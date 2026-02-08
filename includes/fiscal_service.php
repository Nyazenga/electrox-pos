<?php
/**
 * Fiscal Service
 * Main service class for handling fiscalization operations
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';
require_once APP_PATH . '/includes/zimra_logger.php';

/**
 * Map currency code for ZIMRA API
 * ZIMRA expects current ISO 4217 currency codes
 * ZWL (old Zimbabwean Dollar) was replaced by ZWG (Zimbabwe Gold/ZiG) effective June 25, 2024
 * 
 * @param string $currencyCode Original currency code from database
 * @return string Currency code for ZIMRA API
 */
if (!function_exists('mapCurrencyCodeForZimra')) {
    function mapCurrencyCodeForZimra($currencyCode) {
        $code = strtoupper(trim($currencyCode));
        
        // Map old currency codes to current ISO 4217 codes for ZIMRA
        $currencyMap = [
            'ZWL' => 'ZWG', // ZWL (Zimbabwean Dollar) -> ZWG (Zimbabwe Gold/ZiG) - replaced June 25, 2024
            // Add other mappings here if needed in the future
        ];
        
        return $currencyMap[$code] ?? $code;
    }
}

class FiscalService {
    private $db;
    private $branchId;
    private $deviceId;
    private $api;
    private $device;
    private $certificateFingerprint = null; // Store certificate fingerprint for consistency checking
    
    public function __construct($branchId) {
        // Use primary database for fiscal devices (they're branch-specific)
        $this->db = Database::getPrimaryInstance();
        $this->branchId = $branchId;
        
        // Load fiscal device for this branch
        $this->device = $this->db->getRow(
            "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
            [':branch_id' => $branchId]
        );
        
        if (!$this->device) {
            throw new Exception('No fiscal device configured for this branch');
        }
        
        $this->deviceId = $this->device['device_id'];
        
        // Initialize API client
        $this->api = new ZimraApi(
            $this->device['device_model_name'],
            $this->device['device_model_version'],
            true // Use test environment
        );
        
        // CRITICAL: Always load certificate using CertificateStorage (single source of truth)
        // This ensures consistency with receipt submission and fiscal day closing
        require_once APP_PATH . '/includes/certificate_storage.php';
        $certData = CertificateStorage::loadCertificate($this->deviceId);
        
        if (!$certData || !$certData['certificate'] || !$certData['privateKey']) {
            error_log("FISCAL SERVICE: ERROR - No certificate found in CertificateStorage for device {$this->deviceId}");
            throw new Exception("Certificate not found for device {$this->deviceId}. Please ensure the device is registered and certificate is stored in CertificateStorage.");
        }
        
        error_log("FISCAL SERVICE: Certificate loaded from CertificateStorage for device {$this->deviceId}");
        $this->api->setCertificate($certData['certificate'], $certData['privateKey']);
        
        // Update device record in memory for backward compatibility (don't save to DB - CertificateStorage is source of truth)
        $this->device['certificate_pem'] = $certData['certificate'];
        $this->device['private_key_pem'] = $certData['privateKey'];
    }
    
    /**
     * Check if fiscalization is enabled for branch
     */
    public static function isFiscalizationEnabled($branchId) {
        $db = Database::getPrimaryInstance();
        $branch = $db->getRow(
            "SELECT fiscalization_enabled FROM branches WHERE id = :id",
            [':id' => $branchId]
        );
        
        return $branch && $branch['fiscalization_enabled'] == 1;
    }
    
    /**
     * Register device with ZIMRA
     */
    public function registerDevice() {
        if ($this->device['is_registered']) {
            throw new Exception('Device is already registered');
        }
        
        // Generate CSR
        $csrData = ZimraCertificate::generateCSR(
            $this->device['device_serial_no'],
            $this->device['device_id']
        );
        
        // Log registration request
        $requestData = [
            'device_id' => $this->device['device_id'],
            'activation_key' => $this->device['activation_key'],
            'csr_length' => strlen($csrData['csr'])
        ];
        
        // Call ZIMRA API
        $response = $this->api->registerDevice(
            $this->device['device_id'],
            $this->device['activation_key'],
            $csrData['csr']
        );
        
        // Log response
        ZimraLogger::log('REGISTER_DEVICE', $requestData, $response, $this->device['device_id']);
        
        // Save certificate and private key using CertificateStorage (NOT encrypted for now)
        require_once APP_PATH . '/includes/certificate_storage.php';
        CertificateStorage::saveCertificate(
            $this->device['device_id'],
            $response['certificate'],
            $csrData['privateKey'],
            null, // validTill will be extracted
            false // NOT encrypting for now
        );
        
        // Update API client with new certificate
        $this->api->setCertificate($response['certificate'], $csrData['privateKey']);
        
        // Sync config
        $this->syncConfig();
        
        return $response;
    }
    
    /**
     * Sync configuration from ZIMRA
     */
    public function syncConfig() {
        // Verify certificate is loaded before making authenticated request
        if (!$this->api->hasCertificate()) {
            throw new Exception('Device certificate not loaded. Please register the device first.');
        }
        
        error_log("SYNC CONFIG: Attempting to sync config for device {$this->deviceId}, branch {$this->branchId}");
        
        try {
            $response = $this->api->getConfig($this->deviceId);
        } catch (Exception $e) {
            error_log("SYNC CONFIG ERROR: " . $e->getMessage());
            throw $e; // Re-throw to be caught by frontend
        }
        
        error_log("SYNC CONFIG: Successfully received response from ZIMRA");
        
        // Log raw applicableTaxes to see what ZIMRA actually returns
        error_log("SYNC CONFIG: Raw applicableTaxes from ZIMRA: " . json_encode($response['applicableTaxes'] ?? []));
        error_log("SYNC CONFIG: ========== DETAILED TAX ANALYSIS ==========");
        if (!empty($response['applicableTaxes']) && is_array($response['applicableTaxes'])) {
            foreach ($response['applicableTaxes'] as $index => $tax) {
                error_log("SYNC CONFIG: Tax[$index] - Raw from ZIMRA: " . json_encode($tax));
                error_log("SYNC CONFIG: Tax[$index] - taxID: " . ($tax['taxID'] ?? 'NOT PROVIDED'));
                error_log("SYNC CONFIG: Tax[$index] - taxPercent: " . ($tax['taxPercent'] ?? 'NOT PROVIDED') . " (type: " . (isset($tax['taxPercent']) ? gettype($tax['taxPercent']) : 'N/A') . ")");
                error_log("SYNC CONFIG: Tax[$index] - taxName: " . ($tax['taxName'] ?? 'NOT PROVIDED'));
                if (isset($tax['taxValidFrom'])) {
                    error_log("SYNC CONFIG: Tax[$index] - taxValidFrom: " . $tax['taxValidFrom']);
                }
                if (isset($tax['taxValidTill'])) {
                    error_log("SYNC CONFIG: Tax[$index] - taxValidTill: " . $tax['taxValidTill']);
                }
            }
        }
        error_log("SYNC CONFIG: ========== END TAX ANALYSIS ==========");
        
        // Check if ZIMRA already provides taxID in the response
        $hasTaxID = false;
        if (!empty($response['applicableTaxes']) && is_array($response['applicableTaxes'])) {
            $firstTax = $response['applicableTaxes'][0];
            if (isset($firstTax['taxID'])) {
                $hasTaxID = true;
                error_log("SYNC CONFIG: ZIMRA response already includes taxID! Using ZIMRA's taxID values.");
            }
        }
        
        // Map applicable taxes to include taxID and taxCode
        // ZIMRA returns: {taxPercent, taxName} OR possibly {taxID, taxPercent, taxName}
        // We need: {taxID, taxPercent, taxCode, taxName}
        require_once APP_PATH . '/includes/fiscal_helper.php';
        $mappedApplicableTaxes = mapApplicableTaxes($response['applicableTaxes'] ?? [], $hasTaxID);
        
        // Save config - store both raw and mapped taxes
        $configData = [
            'branch_id' => $this->branchId,
            'device_id' => $this->deviceId,
            'taxpayer_name' => $response['taxPayerName'],
            'taxpayer_tin' => $response['taxPayerTIN'],
            'vat_number' => $response['vatNumber'] ?? null,
            'device_branch_name' => $response['deviceBranchName'],
            'device_branch_address' => json_encode($response['deviceBranchAddress']),
            'device_branch_contacts' => json_encode($response['deviceBranchContacts'] ?? null),
            'device_operating_mode' => $response['deviceOperatingMode'],
            'taxpayer_day_max_hrs' => $response['taxPayerDayMaxHrs'],
            'taxpayer_day_end_notification_hrs' => $response['taxpayerDayEndNotificationHrs'],
            'applicable_taxes' => json_encode($mappedApplicableTaxes), // Store mapped version with taxID and taxCode
            'certificate_valid_till' => $response['certificateValidTill'],
            'qr_url' => $response['qrUrl'],
            'last_synced' => date('Y-m-d H:i:s')
        ];
        
        error_log("SYNC CONFIG: Storing mapped applicable taxes: " . json_encode($mappedApplicableTaxes));
        
        $existing = $this->db->getRow(
            "SELECT id FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
            [':branch_id' => $this->branchId, ':device_id' => $this->deviceId]
        );
        
        if ($existing) {
            $this->db->update('fiscal_config', $configData, ['id' => $existing['id']]);
        } else {
            $this->db->insert('fiscal_config', $configData);
        }
        
        // Update device last sync
        $this->db->update('fiscal_devices', [
            'last_config_sync' => date('Y-m-d H:i:s')
        ], ['id' => $this->device['id']]);
        
        return $response;
    }
    
    /**
     * Sync local fiscal day with ZIMRA status
     * This ensures local database always matches ZIMRA (source of truth)
     * 
     * @param array $zimraStatus ZIMRA status response
     * @return array|null Synced fiscal day record or null
     */
    private function syncFiscalDayWithZimra($zimraStatus) {
        if (!$zimraStatus || !isset($zimraStatus['fiscalDayStatus'])) {
            return null;
        }
        
        $zimraDayStatus = $zimraStatus['fiscalDayStatus'];
        $zimraDayNo = $zimraStatus['lastFiscalDayNo'] ?? null;
        
        // Handle FiscalDayCloseFailed - this means a close attempt failed, but day is still "open" for closing purposes
        // We should allow retrying the close operation
        $isOpenForClosing = ($zimraDayStatus === 'FiscalDayOpened' || $zimraDayStatus === 'FiscalDayCloseFailed');
        
        // If ZIMRA says day is open (or close failed), ensure we have a local record
        if ($isOpenForClosing && $zimraDayNo) {
            // Check if we have a record for this fiscal day number
            $fiscalDay = $this->db->getRow(
                "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND fiscal_day_no = :fiscal_day_no ORDER BY id DESC LIMIT 1",
                [':branch_id' => $this->branchId, ':device_id' => $this->deviceId, ':fiscal_day_no' => $zimraDayNo]
            );
            
            if ($fiscalDay) {
                // Update existing record to match ZIMRA status
                $this->db->update('fiscal_days', [
                    'status' => $zimraDayStatus,
                    'last_receipt_global_no' => $zimraStatus['lastReceiptGlobalNo'] ?? 0,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $fiscalDay['id']]);
                
                return $this->db->getRow(
                    "SELECT * FROM fiscal_days WHERE id = :id",
                    [':id' => $fiscalDay['id']]
                );
            } else {
                // Create new record - ZIMRA has an open day we don't know about
                // Use today's date as fiscal_day_opened (best guess)
                $fiscalDayOpened = date('Y-m-d\TH:i:s');
                $fiscalDayId = $this->db->insert('fiscal_days', [
                    'branch_id' => $this->branchId,
                    'device_id' => $this->deviceId,
                    'fiscal_day_no' => $zimraDayNo,
                    'fiscal_day_opened' => $fiscalDayOpened,
                    'status' => $zimraDayStatus,
                    'last_receipt_global_no' => $zimraStatus['lastReceiptGlobalNo'] ?? 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
                if ($fiscalDayId) {
                    error_log("SYNC: Created local fiscal day record (ID: $fiscalDayId, Day No: $zimraDayNo) to match ZIMRA");
                    return $this->db->getRow(
                        "SELECT * FROM fiscal_days WHERE id = :id",
                        [':id' => $fiscalDayId]
                    );
                }
            }
        } else {
            // ZIMRA says day is closed - update any open local records
            $openDays = $this->db->getRows(
                "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened'",
                [':branch_id' => $this->branchId, ':device_id' => $this->deviceId]
            );
            
            foreach ($openDays as $openDay) {
                $this->db->update('fiscal_days', [
                    'status' => $zimraDayStatus,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $openDay['id']]);
            }
        }
        
        return null;
    }
    
    /**
     * Get current fiscal day status
     */
    public function getFiscalDayStatus() {
        try {
            $response = $this->api->getStatus($this->deviceId);
            
            // Log the response for debugging
            if ($response && isset($response['fiscalDayStatus'])) {
                error_log("FISCAL DAY STATUS: Device {$this->deviceId} - Status: {$response['fiscalDayStatus']}, Day No: " . ($response['lastFiscalDayNo'] ?? 'N/A'));
            }
            
            // Always sync local database with ZIMRA (source of truth)
            $this->syncFiscalDayWithZimra($response);
            
            return $response;
        } catch (Exception $e) {
            // Log detailed error information
            $errorMsg = "Error getting fiscal day status for device {$this->deviceId}: " . $e->getMessage();
            error_log($errorMsg);
            error_log("FISCAL DAY STATUS ERROR: " . get_class($e) . " - " . $e->getTraceAsString());
            
            // Return null to indicate status couldn't be fetched
            // Caller should handle this gracefully and show a warning
            return null;
        }
    }
    
    /**
     * Open fiscal day
     */
    public function openFiscalDay($fiscalDayNo = null) {
        // ALWAYS check ZIMRA first (source of truth) and sync local database
        try {
            $status = $this->api->getStatus($this->deviceId);
            
            // Sync local database with ZIMRA status
            $syncedDay = $this->syncFiscalDayWithZimra($status);
            
            // Check if day is open (FiscalDayOpened) or close failed (FiscalDayCloseFailed - needs closing)
            $isDayOpen = isset($status['fiscalDayStatus']) && 
                        ($status['fiscalDayStatus'] === 'FiscalDayOpened' || $status['fiscalDayStatus'] === 'FiscalDayCloseFailed');
            
            if ($isDayOpen) {
                // Day is already open on ZIMRA (or close failed) - return the synced local record
                if ($syncedDay) {
                    $statusMsg = $status['fiscalDayStatus'] === 'FiscalDayCloseFailed' 
                        ? 'Close failed - needs closing' 
                        : 'already open';
                    error_log("FISCAL DAY: Day is $statusMsg on ZIMRA (Day No: " . ($status['lastFiscalDayNo'] ?? 'Unknown') . ", Status: {$status['fiscalDayStatus']}). Local database synced.");
                    return [
                        'fiscalDayNo' => $syncedDay['fiscal_day_no'],
                        'fiscalDayOpened' => $syncedDay['fiscal_day_opened'],
                        'status' => $status['fiscalDayStatus'],
                        'synced' => true,
                        'message' => $status['fiscalDayStatus'] === 'FiscalDayCloseFailed' 
                            ? 'Fiscal day close previously failed. Please close the current fiscal day first before opening a new one.'
                            : 'Fiscal day is already open on ZIMRA. Local database synced.'
                    ];
                } else {
                    $currentDayNo = $status['lastFiscalDayNo'] ?? 'Unknown';
                    $statusText = $status['fiscalDayStatus'] === 'FiscalDayCloseFailed' ? 'close failed' : 'open';
                    throw new Exception("Fiscal day is $statusText on ZIMRA (Day No: $currentDayNo). Please close the current fiscal day first before opening a new one.");
                }
            }
        } catch (Exception $e) {
            // If error is from ZIMRA API saying day is already open, re-throw it
            if (strpos($e->getMessage(), 'already open') !== false || strpos($e->getMessage(), 'FISC01') !== false) {
                // Try to sync anyway - maybe we can recover
                try {
                    $status = $this->api->getStatus($this->deviceId);
                    $syncedDay = $this->syncFiscalDayWithZimra($status);
                    if ($syncedDay && isset($status['fiscalDayStatus']) && $status['fiscalDayStatus'] === 'FiscalDayOpened') {
                        error_log("FISCAL DAY: Recovered by syncing with ZIMRA (Day No: " . ($status['lastFiscalDayNo'] ?? 'Unknown') . ")");
                        return [
                            'fiscalDayNo' => $syncedDay['fiscal_day_no'],
                            'fiscalDayOpened' => $syncedDay['fiscal_day_opened'],
                            'status' => 'FiscalDayOpened',
                            'synced' => true
                        ];
                    }
                } catch (Exception $syncError) {
                    // If sync fails, throw original error
                }
                throw $e;
            }
            // If getStatus fails for other reasons, log and continue
            error_log("Warning: Could not check fiscal day status: " . $e->getMessage());
        }
        
        // Check local database (should be synced by now, but double-check)
        $openDay = $this->db->getRow(
            "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened'",
            [':branch_id' => $this->branchId, ':device_id' => $this->deviceId]
        );
        
        if ($openDay) {
            // Local database says day is open, but ZIMRA check above didn't find it
            // This shouldn't happen if sync worked, but handle it gracefully
            error_log("WARNING: Local database has open fiscal day but ZIMRA doesn't. This may indicate a sync issue.");
            // Continue to try opening - ZIMRA will reject if there's really an issue
        }
        
        // Use ISO 8601 format for date
        $fiscalDayOpened = date('Y-m-d\TH:i:s');
        
        // CRITICAL: Reload certificate from CertificateStorage to ensure we use the latest certificate
        // This ensures consistency with receipt submission which also uses CertificateStorage
        require_once APP_PATH . '/includes/certificate_storage.php';
        $certData = CertificateStorage::loadCertificate($this->deviceId);
        if ($certData && $certData['certificate'] && $certData['privateKey']) {
            // Validate certificate before using it
            $this->validateCertificateForOperation($certData['certificate'], $certData['privateKey'], 'fiscal_day_open');
            $this->api->setCertificate($certData['certificate'], $certData['privateKey']);
            error_log("OPEN FISCAL DAY: Certificate reloaded and API client updated");
        }
        
        // Call ZIMRA API
        try {
            $requestData = [
                'device_id' => $this->deviceId,
                'fiscal_day_opened' => $fiscalDayOpened,
                'fiscal_day_no' => $fiscalDayNo
            ];
            
            $response = $this->api->openDay($this->deviceId, $fiscalDayOpened, $fiscalDayNo);
            
            // Log operation
            ZimraLogger::log('OPEN_FISCAL_DAY', $requestData, $response, $this->deviceId);
            
            // Save fiscal day
            $fiscalDayData = [
                'branch_id' => $this->branchId,
                'device_id' => $this->deviceId,
                'fiscal_day_no' => $response['fiscalDayNo'],
                'fiscal_day_opened' => $fiscalDayOpened,
                'status' => 'FiscalDayOpened',
                'last_receipt_counter' => 0,
                'last_receipt_global_no' => 0
            ];
            
            $fiscalDayId = $this->db->insert('fiscal_days', $fiscalDayData);
            
            return [
                'fiscalDayId' => $fiscalDayId,
                'fiscalDayNo' => $response['fiscalDayNo'],
                'operationID' => $response['operationID']
            ];
        } catch (Exception $e) {
            // If openDay() returns FISC01 error (day not closed), try to sync and return existing day
            if (strpos($e->getMessage(), 'FISC01') !== false || strpos($e->getMessage(), 'not closed') !== false || strpos($e->getMessage(), 'already open') !== false) {
                error_log("FISCAL DAY: openDay() returned FISC01 - day is already open. Attempting to sync...");
                
                // Try to get the actual status from ZIMRA and sync
                try {
                    $status = $this->api->getStatus($this->deviceId);
                    $syncedDay = $this->syncFiscalDayWithZimra($status);
                    
                    if ($syncedDay && isset($status['fiscalDayStatus']) && 
                        ($status['fiscalDayStatus'] === 'FiscalDayOpened' || $status['fiscalDayStatus'] === 'FiscalDayCloseFailed')) {
                        error_log("FISCAL DAY: Successfully synced existing open day (Day No: " . ($status['lastFiscalDayNo'] ?? 'Unknown') . ")");
                        return [
                            'fiscalDayNo' => $syncedDay['fiscal_day_no'],
                            'fiscalDayOpened' => $syncedDay['fiscal_day_opened'],
                            'status' => $status['fiscalDayStatus'],
                            'synced' => true,
                            'message' => 'Fiscal day is already open on ZIMRA. Local database synced.'
                        ];
                    }
                } catch (Exception $syncError) {
                    error_log("FISCAL DAY: Could not sync after FISC01 error: " . $syncError->getMessage());
                }
                
                // If we can't sync, throw a more helpful error
                throw new Exception("Cannot open fiscal day: ZIMRA reports that the current fiscal day is not closed. Please close the current fiscal day first before opening a new one.");
            }
            
            // Re-throw other errors
            throw $e;
        }
    }
    
    /**
     * Submit receipt to ZIMRA
     * @param int $invoiceId Invoice ID
     * @param array $receiptData Receipt data
     * @param int|null $saleId Sale ID
     * @param string|null $previousReceiptHash Previous receipt hash from ZIMRA (for receiptCounter > 1)
     */
    public function submitReceipt($invoiceId, $receiptData, $saleId = null, $previousReceiptHash = null) {
        // CRITICAL: Always sync with ZIMRA first to get accurate status
        try {
            $zimraStatus = $this->api->getStatus($this->deviceId);
            $this->syncFiscalDayWithZimra($zimraStatus);
        } catch (Exception $e) {
            error_log("SUBMIT RECEIPT: Could not sync with ZIMRA: " . $e->getMessage());
        }
        
        // Get current fiscal day - check for both FiscalDayOpened and FiscalDayCloseFailed
        // FiscalDayCloseFailed means day is still "open" for receipt submission purposes
        $fiscalDay = $this->db->getRow(
            "SELECT * FROM fiscal_days 
             WHERE branch_id = :branch_id 
             AND device_id = :device_id 
             AND (status = 'FiscalDayOpened' OR status = 'FiscalDayCloseFailed')
             ORDER BY id DESC LIMIT 1",
            [':branch_id' => $this->branchId, ':device_id' => $this->deviceId]
        );
        
        if (!$fiscalDay) {
            // Try to get any fiscal day for this device and sync
            $anyFiscalDay = $this->db->getRow(
                "SELECT * FROM fiscal_days 
                 WHERE branch_id = :branch_id 
                 AND device_id = :device_id 
                 ORDER BY id DESC LIMIT 1",
                [':branch_id' => $this->branchId, ':device_id' => $this->deviceId]
            );
            
            if ($anyFiscalDay) {
                // Sync with ZIMRA to update status
                try {
                    $zimraStatus = $this->api->getStatus($this->deviceId);
                    $syncedDay = $this->syncFiscalDayWithZimra($zimraStatus);
                    if ($syncedDay) {
                        $fiscalDay = $syncedDay;
                    }
                } catch (Exception $e) {
                    // Continue with error
                }
            }
            
            if (!$fiscalDay) {
                throw new Exception('No open fiscal day. Please open a fiscal day first.');
            }
        }
        
        // CRITICAL FIX: Get the correct receipt counter (RCPT011 fix)
        // RCPT011: receiptCounter must be equal to 1 for the first receipt in fiscal day
        // and receiptCounter must be greater by one from the previous receipt's receiptCounter value
        $lastReceiptCounter = null;
        $nextReceiptCounter = null;
        $nextReceiptGlobalNo = null;
        
        // Method 1: ALWAYS get from ZIMRA status first (authoritative source for receiptGlobalNo)
        // RCPT012: receiptGlobalNo must be greater by one from the previous receipt's receiptGlobalNo
        // OR may be equal to 1 for the first receipt in fiscal day
        $zimraLastGlobalNo = null;
        try {
            $zimraStatus = $this->api->getStatus($this->deviceId);
            // ZIMRA provides lastReceiptGlobalNo in status - use this as the authoritative source
            if (isset($zimraStatus['lastReceiptGlobalNo'])) {
                $zimraLastGlobalNo = intval($zimraStatus['lastReceiptGlobalNo']);
                error_log("RECEIPT GLOBAL NO: ZIMRA status shows lastReceiptGlobalNo = $zimraLastGlobalNo");
            }
        } catch (Exception $e) {
            error_log("RECEIPT GLOBAL NO: Could not get ZIMRA status: " . $e->getMessage());
        }
        
        // Method 2: Get receipt counter from database - last successful receipt for this fiscal day
        // receiptCounter is per fiscal day (resets at fiscal day boundary)
        $lastReceipt = $this->db->getRow(
            "SELECT receipt_counter, receipt_global_no, receipt_id 
             FROM fiscal_receipts 
             WHERE device_id = :device_id 
             AND fiscal_day_no = :fiscal_day_no 
             AND submission_status = 'Submitted'
             AND receipt_id IS NOT NULL
             ORDER BY receipt_counter DESC, receipt_global_no DESC 
             LIMIT 1",
            [
                ':device_id' => $this->deviceId,
                ':fiscal_day_no' => $fiscalDay['fiscal_day_no']
            ]
        );
        
        if ($lastReceipt && isset($lastReceipt['receipt_counter'])) {
            $lastReceiptCounter = intval($lastReceipt['receipt_counter']);
            $nextReceiptCounter = $lastReceiptCounter + 1;
            error_log("RECEIPT COUNTER: Found last receipt counter from database: $lastReceiptCounter (receipt_id: {$lastReceipt['receipt_id']}, global_no: {$lastReceipt['receipt_global_no']})");
            error_log("RECEIPT COUNTER: Next receipt counter = $nextReceiptCounter");
        } else {
            // No previous receipt in this fiscal day - counter should be 1
            $lastReceiptCounter = 0;
            $nextReceiptCounter = 1;
            error_log("RECEIPT COUNTER: No previous receipt found for fiscal day {$fiscalDay['fiscal_day_no']}. Using counter 1 (first receipt in fiscal day).");
        }
        
        // Override receiptCounter in receiptData to ensure it's sequential
        $receiptData['receiptCounter'] = $nextReceiptCounter;
        
        error_log("RECEIPT COUNTER: Using receiptCounter = $nextReceiptCounter (last was $lastReceiptCounter) for fiscal day {$fiscalDay['fiscal_day_no']}");
        
        // Calculate receiptGlobalNo
        // RCPT012: receiptGlobalNo must be greater by one from the previous receipt's receiptGlobalNo
        // OR may be equal to 1 for the first receipt in fiscal day
        // CRITICAL: ZIMRA is the authoritative source - use ZIMRA's lastReceiptGlobalNo
        
        if ($zimraLastGlobalNo !== null) {
            // ZIMRA has a lastReceiptGlobalNo - next one must be +1 (RCPT012 requirement)
            // Exception: Can use 1 if this is the first receipt in fiscal day (receiptCounter=1)
            // BUT only if ZIMRA's lastReceiptGlobalNo is 0 (truly first receipt ever)
            if ($nextReceiptCounter === 1 && $zimraLastGlobalNo === 0) {
                // This is the first receipt ever and first in fiscal day - can use 1
                $nextReceiptGlobalNo = 1;
                error_log("RECEIPT GLOBAL NO: First receipt ever (ZIMRA lastGlobalNo=0) - using receiptGlobalNo = 1");
            } else {
                // Must be previous receipt's globalNo + 1
                $nextReceiptGlobalNo = $zimraLastGlobalNo + 1;
                error_log("RECEIPT GLOBAL NO: Using receiptGlobalNo = $nextReceiptGlobalNo (ZIMRA lastGlobalNo=$zimraLastGlobalNo + 1)");
            }
        } else {
            // ZIMRA status unavailable - fallback to database (should rarely happen)
            $lastGlobalReceipt = $this->db->getRow(
                "SELECT receipt_global_no 
                 FROM fiscal_receipts 
                 WHERE device_id = :device_id 
                 AND receipt_global_no IS NOT NULL
                 AND submission_status = 'Submitted'
                 ORDER BY receipt_global_no DESC 
                 LIMIT 1",
                [':device_id' => $this->deviceId]
            );
            
            if ($lastGlobalReceipt) {
                $nextReceiptGlobalNo = intval($lastGlobalReceipt['receipt_global_no']) + 1;
                error_log("RECEIPT GLOBAL NO: ZIMRA status unavailable - using receiptGlobalNo = $nextReceiptGlobalNo from database");
            } else {
                // No previous receipt - use 1 if first receipt in fiscal day, otherwise use counter
                $nextReceiptGlobalNo = ($nextReceiptCounter === 1) ? 1 : $nextReceiptCounter;
                error_log("RECEIPT GLOBAL NO: No previous receipt - using receiptGlobalNo = $nextReceiptGlobalNo");
            }
        }
        
        // Ensure receiptGlobalNo is always >= receiptCounter for consistency
        if ($nextReceiptGlobalNo < $nextReceiptCounter) {
            $nextReceiptGlobalNo = $nextReceiptCounter;
            error_log("RECEIPT GLOBAL NO: Adjusted to match counter (cannot be less than counter)");
        }
        
        $receiptData['receiptGlobalNo'] = $nextReceiptGlobalNo;
        error_log("RECEIPT GLOBAL NO: Final - receiptGlobalNo = $nextReceiptGlobalNo, receiptCounter = $nextReceiptCounter, ZIMRA lastGlobalNo = " . ($zimraLastGlobalNo ?? 'N/A'));
        
        // Get previous receipt hash - must be from a successfully fiscalized receipt
        // CRITICAL: Always retrieve previous receipt hash if ANY previous receipt exists
        // The receipt chain requires the hash of the immediately preceding receipt,
        // regardless of whether this is the first receipt in the fiscal day or not
        // (receiptCounter can reset at fiscal day boundaries, but the hash chain must continue)
        
        // Log if parameter was provided
        if ($previousReceiptHash !== null) {
            error_log("PREVIOUS RECEIPT HASH: Using provided parameter (hash from caller): " . substr($previousReceiptHash, 0, 30) . "...");
        }
        
        // CRITICAL FIX: For the FIRST receipt in a fiscal day (receiptCounter === 1), previousReceiptHash MUST be null
        // Documentation Section 13.2.1: "This field is not used in signature when current receipt is first in fiscal day."
        // The receipt chain applies WITHIN a fiscal day, not across fiscal day boundaries
        if ($receiptData['receiptCounter'] === 1) {
            error_log("PREVIOUS RECEIPT HASH: First receipt in fiscal day (receiptCounter=1) - setting previousReceiptHash to NULL as per documentation");
            $previousReceiptHash = null;
        } elseif ($previousReceiptHash === null) {
            // For receiptCounter > 1, get previous receipt hash from database
            error_log("PREVIOUS RECEIPT HASH: Searching for previous receipt - device_id={$this->deviceId}, current_fiscal_day_no={$fiscalDay['fiscal_day_no']}, current_receipt_counter={$receiptData['receiptCounter']}");
            
            // CRITICAL: Get the hash from the receipt that immediately precedes this one
            // For signature validation (RCPT020), we need the hash from the receipt with receiptGlobalNo = (current - 1)
            // This ensures the receipt chain is properly maintained WITHIN the fiscal day
            
            $previousReceipt = null;
            
            // First, try to get the receipt with receiptGlobalNo = (current - 1)
            // This is the immediately preceding receipt in the global sequence
            if (isset($receiptData['receiptGlobalNo']) && $receiptData['receiptGlobalNo'] > 1) {
                $expectedPreviousGlobalNo = $receiptData['receiptGlobalNo'] - 1;
                error_log("PREVIOUS RECEIPT HASH: Looking for receipt with receiptGlobalNo = $expectedPreviousGlobalNo (immediately preceding receipt)");
                
                $previousReceipt = $this->db->getRow(
                    "SELECT fr.receipt_hash, fr.receipt_id, fr.receipt_global_no, fr.receipt_counter, fr.receipt_server_signature, fr.sale_id, fr.fiscal_day_no
                     FROM fiscal_receipts fr
                     WHERE fr.device_id = :device_id 
                     AND fr.receipt_global_no = :expected_global_no
                     AND fr.submission_status = 'Submitted'
                     AND fr.receipt_id IS NOT NULL
                     AND fr.receipt_hash IS NOT NULL
                     ORDER BY fr.id DESC
                     LIMIT 1",
                    [
                        ':device_id' => $this->deviceId,
                        ':expected_global_no' => $expectedPreviousGlobalNo
                    ]
                );
                
                if ($previousReceipt && !empty($previousReceipt['receipt_hash'])) {
                    error_log("PREVIOUS RECEIPT HASH: Found receipt with exact receiptGlobalNo = $expectedPreviousGlobalNo");
                }
            }
            
            // Fallback: If exact match not found, get the most recent receipt (should have globalNo = current - 1)
            // This handles cases where database might be slightly out of sync
            if (!$previousReceipt || empty($previousReceipt['receipt_hash'])) {
                error_log("PREVIOUS RECEIPT HASH: Exact globalNo match not found, getting most recent receipt");
                
                $previousReceipt = $this->db->getRow(
                    "SELECT fr.receipt_hash, fr.receipt_id, fr.receipt_global_no, fr.receipt_counter, fr.receipt_server_signature, fr.sale_id, fr.fiscal_day_no
                     FROM fiscal_receipts fr
                     WHERE fr.device_id = :device_id 
                     AND fr.submission_status = 'Submitted'
                     AND fr.receipt_id IS NOT NULL
                     AND fr.receipt_hash IS NOT NULL
                     ORDER BY fr.receipt_global_no DESC, fr.id DESC
                     LIMIT 1",
                    [
                        ':device_id' => $this->deviceId
                    ]
                );
            }
            
            if ($previousReceipt && !empty($previousReceipt['receipt_hash'])) {
                // Use receipt_hash which now stores OUR generated hash (not ZIMRA's)
                // Test results confirm that using our hash prevents RCPT020 errors
                $previousReceiptHash = $previousReceipt['receipt_hash'];
                error_log("PREVIOUS RECEIPT HASH: ✓ Found hash from fiscal_day_no={$previousReceipt['fiscal_day_no']}, receipt_counter={$previousReceipt['receipt_counter']}, receipt_id={$previousReceipt['receipt_id']}, sale_id={$previousReceipt['sale_id']}, hash=" . substr($previousReceiptHash, 0, 30) . "...");
            } else {
                // Debug: Check what receipts exist for this device
                $allReceipts = $this->db->getRows(
                    "SELECT fr.receipt_counter, fr.receipt_id, fr.receipt_hash, fr.submission_status, fr.sale_id, fr.fiscal_day_no, fr.receipt_global_no
                     FROM fiscal_receipts fr
                     WHERE fr.device_id = :device_id 
                     ORDER BY fr.receipt_global_no DESC, fr.id DESC
                     LIMIT 10",
                    [
                        ':device_id' => $this->deviceId
                    ]
                );
                error_log("PREVIOUS RECEIPT HASH: ✗ WARNING - No previous receipt hash found! This will cause signature mismatch for receiptCounter={$receiptData['receiptCounter']}.");
                error_log("PREVIOUS RECEIPT HASH: Query returned: " . ($previousReceipt ? "receipt_id={$previousReceipt['receipt_id']}, but hash is empty" : "no receipt found"));
                error_log("PREVIOUS RECEIPT HASH: Recent receipts for this device: " . json_encode($allReceipts, JSON_PRETTY_PRINT));
                // Set to null explicitly if no previous receipt found (this is OK for the very first receipt)
                $previousReceiptHash = null;
            }
        }
        
        // Final log
        if ($previousReceiptHash === null) {
            error_log("PREVIOUS RECEIPT HASH: Using NULL (this is the first receipt or no previous receipt found)");
        }
        
        // Log previous receipt hash retrieval for debugging
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] SIGNATURE DEBUG: Previous receipt hash: " . ($previousReceiptHash ? substr($previousReceiptHash, 0, 30) . "..." : "NULL (first receipt)") . "\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        // CRITICAL: Generate signature using EXACT values that will be sent in request
        // ZIMRA calculates signature from the JSON request body, so values must match exactly
        // Log the exact values being used for signature generation
        $writeLog = function($message) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[$timestamp] $message" . PHP_EOL;
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
        };
        
        $writeLog("SIGNATURE PRE-GENERATION: receiptData values for signature:");
        $writeLog("  deviceID: " . ($receiptData['deviceID'] ?? 'MISSING'));
        $writeLog("  receiptType: " . ($receiptData['receiptType'] ?? 'MISSING'));
        $writeLog("  receiptCurrency: " . ($receiptData['receiptCurrency'] ?? 'MISSING'));
        $writeLog("  receiptGlobalNo: " . ($receiptData['receiptGlobalNo'] ?? 'MISSING') . " (type: " . gettype($receiptData['receiptGlobalNo'] ?? null) . ")");
        $writeLog("  receiptDate: " . ($receiptData['receiptDate'] ?? 'MISSING'));
        $writeLog("  receiptTotal: " . ($receiptData['receiptTotal'] ?? 'MISSING') . " (type: " . gettype($receiptData['receiptTotal'] ?? null) . ")");
        $writeLog("  receiptTaxes count: " . count($receiptData['receiptTaxes'] ?? []));
        if (!empty($receiptData['receiptTaxes'])) {
            foreach ($receiptData['receiptTaxes'] as $idx => $tax) {
                $writeLog("  receiptTaxes[$idx]: taxCode=" . ($tax['taxCode'] ?? '') . ", taxPercent=" . ($tax['taxPercent'] ?? '') . " (type: " . gettype($tax['taxPercent'] ?? null) . "), taxAmount=" . ($tax['taxAmount'] ?? '') . ", salesAmountWithTax=" . ($tax['salesAmountWithTax'] ?? ''));
            }
        }
        
        // CRITICAL FIXES to match Python library format (MUST BE BEFORE SIGNATURE GENERATION):
        
        // 1. Convert moneyTypeCode from string to integer (0 = Cash, 1 = Card)
        if (!empty($receiptData['receiptPayments'])) {
            foreach ($receiptData['receiptPayments'] as &$payment) {
                if (isset($payment['moneyTypeCode'])) {
                    $moneyType = strtolower($payment['moneyTypeCode']);
                    if ($moneyType === 'cash') {
                        $payment['moneyTypeCode'] = 0;
                    } elseif ($moneyType === 'card') {
                        $payment['moneyTypeCode'] = 1;
                    } else {
                        $payment['moneyTypeCode'] = 0;
                    }
                }
                // Convert paymentAmount to float
                if (isset($payment['paymentAmount'])) {
                    $payment['paymentAmount'] = floatval($payment['paymentAmount']);
                }
            }
            unset($payment);
        }
        
        // 2. Prepare receiptLines (keep taxCode - API spec requires it)
        if (!empty($receiptData['receiptLines'])) {
            foreach ($receiptData['receiptLines'] as &$line) {
                // Convert numeric values to floats
                if (isset($line['receiptLinePrice'])) $line['receiptLinePrice'] = floatval($line['receiptLinePrice']);
                if (isset($line['receiptLineQuantity'])) $line['receiptLineQuantity'] = floatval($line['receiptLineQuantity']);
                if (isset($line['receiptLineTotal'])) $line['receiptLineTotal'] = floatval($line['receiptLineTotal']);
                // CRITICAL: For exempt taxes (taxCode='E'), taxPercent should NOT be included in JSON payload
                // Documentation: "In case of exempt, field will not be provided" (receiptLine)
                // Even if taxPercent is 0, if taxCode='E', we must NOT include it
                if (isset($line['taxCode']) && $line['taxCode'] === 'E') {
                    // Exempt tax - remove taxPercent field entirely
                    unset($line['taxPercent']);
                } elseif (isset($line['taxPercent']) && $line['taxPercent'] !== null) {
                    // Non-exempt tax - convert to float
                    $line['taxPercent'] = floatval($line['taxPercent']);
                } else {
                    // taxPercent is null and not exempt - remove it
                    unset($line['taxPercent']);
                }
                // Keep taxCode - API spec shows it should be included in receiptLines
            }
            unset($line);
        }
        
        // 3. Prepare receiptTaxes for signature generation and payload
        // CRITICAL: For exempt taxes (taxCode='E'), taxPercent should NOT be included in JSON payload
        // Documentation: "In case of exempt, field will not be provided" (receiptTax)
        // Signature format (zimra-public): taxPercent || taxAmount || salesAmountWithTax (NO taxCode in signature)
        // For exempt taxes, taxPercent is removed here, and signature will use empty string
        if (!empty($receiptData['receiptTaxes'])) {
            foreach ($receiptData['receiptTaxes'] as &$tax) {
                // Convert numeric values to floats and round taxAmount to 2 decimal places
                // CRITICAL: Documentation states:
                // - "In case of no VAT sale, 0 value should be used" (for 0% tax, MUST include taxPercent: 0)
                // - "In case of exempt this field should not be provided" (for exempt, MUST remove taxPercent)
                // RCPT025 validation requires taxPercent and taxID combination to match FDMS
                // So: 0% tax MUST have taxPercent: 0 in payload, only exempt should have it removed
                if (isset($tax['taxCode']) && $tax['taxCode'] === 'E') {
                    // Exempt tax - remove taxPercent field entirely (per documentation)
                    unset($tax['taxPercent']);
                } elseif (isset($tax['taxPercent']) && $tax['taxPercent'] !== null) {
                    // Non-exempt tax (including 0%) - convert to float and keep in payload
                    // 0% tax MUST have taxPercent: 0 in payload to pass RCPT025 validation
                    $tax['taxPercent'] = floatval($tax['taxPercent']);
                } else {
                    // taxPercent is null and not exempt - remove it
                    unset($tax['taxPercent']);
                }
                if (isset($tax['taxAmount'])) {
                    $tax['taxAmount'] = round(floatval($tax['taxAmount']), 2); // CRITICAL: Round to 2 decimal places
                }
                if (isset($tax['salesAmountWithTax'])) $tax['salesAmountWithTax'] = round(floatval($tax['salesAmountWithTax']), 2);
            }
            unset($tax);
        }
        
        // 4. Convert receiptTotal to float
        if (isset($receiptData['receiptTotal'])) {
            $receiptData['receiptTotal'] = floatval($receiptData['receiptTotal']);
        }
        
        // CRITICAL: Log receiptData RIGHT BEFORE signature generation (after all transformations)
        $logFile = APP_PATH . '/logs/fiscal_service_receipt_data_log.txt';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "========================================\n";
        $logMessage .= "[$timestamp] FISCAL_SERVICE receiptData - BEFORE signature generation\n";
        $logMessage .= "========================================\n";
        $logMessage .= "Device ID: " . ($receiptData['deviceID'] ?? 'NOT SET') . "\n";
        $logMessage .= "Previous Receipt Hash: " . ($previousReceiptHash ? substr($previousReceiptHash, 0, 30) . "..." : "NULL") . "\n";
        $logMessage .= "COMPLETE receiptData JSON (after all transformations, before signature):\n";
        $logMessage .= json_encode($receiptData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $logMessage .= "\n";
        $logMessage .= "Receipt Taxes (for signature string):\n";
        foreach ($receiptData['receiptTaxes'] ?? [] as $idx => $tax) {
            $logMessage .= "  Tax[$idx]: taxPercent=" . ($tax['taxPercent'] ?? 'N/A') . ", taxAmount=" . ($tax['taxAmount'] ?? 'N/A') . ", salesAmountWithTax=" . ($tax['salesAmountWithTax'] ?? 'N/A') . "\n";
        }
        $logMessage .= "========================================\n\n";
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
        error_log("FISCAL_SERVICE: Logged receiptData to $logFile before signature generation");
        
        // CRITICAL: Always reload certificate from CertificateStorage (single source of truth)
        // This ensures we're using the same certificate that was used for fiscal day opening/closing
        require_once APP_PATH . '/includes/certificate_storage.php';
        $certDataForReceipt = CertificateStorage::loadCertificate($this->deviceId);
        
        if (!$certDataForReceipt || !$certDataForReceipt['certificate'] || !$certDataForReceipt['privateKey']) {
            error_log("SUBMIT RECEIPT: ERROR - Could not load certificate from CertificateStorage");
            throw new Exception("Certificate not found for device {$this->deviceId}. Please ensure the device is registered.");
        }
        
        $privateKeyForReceipt = $certDataForReceipt['privateKey'];
        $certificateForReceipt = $certDataForReceipt['certificate'];
        
        // CRITICAL: Validate certificate before using it for receipt submission
        $this->validateCertificateForOperation($certificateForReceipt, $privateKeyForReceipt, 'receipt_submission');
        
        // Update API client to use the fresh certificate
        $this->api->setCertificate($certificateForReceipt, $privateKeyForReceipt);
        
        $writeLog = function($message) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[$timestamp] SUBMIT RECEIPT: $message" . PHP_EOL;
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
            error_log("SUBMIT RECEIPT: $message");
        };
        
        // Log certificate details for comparison
        $certFingerprintReceipt = null;
        $certSubjectReceipt = null;
        if ($certificateForReceipt) {
            $cert = openssl_x509_read($certificateForReceipt);
            if ($cert) {
                $certDetails = openssl_x509_parse($cert);
                $certFingerprintReceipt = openssl_x509_fingerprint($cert, 'sha256', false);
                $certSubjectReceipt = $certDetails['subject']['CN'] ?? 'N/A';
            }
        }
        
        $keyFingerprintReceipt = null;
        $key = openssl_pkey_get_private($privateKeyForReceipt);
        if ($key) {
            $keyDetails = openssl_pkey_get_details($key);
            if (isset($keyDetails['key'])) {
                $keyFingerprintReceipt = hash('sha256', $keyDetails['key']);
            }
        }
        
        $writeLog("========== CERTIFICATE LOADED FOR RECEIPT SUBMISSION ==========");
        $writeLog("Certificate Subject: " . ($certSubjectReceipt ?? 'N/A'));
        $writeLog("Certificate Fingerprint (SHA256): " . ($certFingerprintReceipt ?? 'N/A'));
        $writeLog("Private Key Fingerprint (SHA256 of public key): " . ($keyFingerprintReceipt ?? 'N/A'));
        $writeLog("Certificate Length: " . strlen($certificateForReceipt) . " bytes");
        $writeLog("Private Key Length: " . strlen($privateKeyForReceipt) . " bytes");
        $writeLog("Certificate First 50 chars: " . substr($certificateForReceipt, 0, 50));
        $writeLog("Certificate Last 50 chars: " . substr($certificateForReceipt, -50));
        $writeLog("Private Key First 50 chars: " . substr($privateKeyForReceipt, 0, 50));
        $writeLog("Private Key Last 50 chars: " . substr($privateKeyForReceipt, -50));
        $writeLog("API client certificate updated");
        $writeLog("================================================");
        
        // Generate receipt signature AFTER all fixes are applied
        // Signature must be generated on the FINAL payload structure that will be sent to ZIMRA
        $deviceSignature = ZimraSignature::generateReceiptDeviceSignature(
            $receiptData,
            $previousReceiptHash,
            $privateKeyForReceipt
        );
        
        // Add signature to receipt data
        $receiptData['receiptDeviceSignature'] = $deviceSignature;
        
        // Reorder receiptTaxes fields to match API spec order: taxCode, taxPercent, taxID, taxAmount, salesAmountWithTax
        // CRITICAL: For exempt taxes (taxCode='E'), taxPercent must NOT be included in JSON payload
        // Documentation: "In case of exempt, field will not be provided" (receiptTax)
        if (!empty($receiptData['receiptTaxes'])) {
            foreach ($receiptData['receiptTaxes'] as &$tax) {
                // Reorder fields to match API spec order
                $reorderedTax = [];
                if (isset($tax['taxCode'])) $reorderedTax['taxCode'] = $tax['taxCode'];
                // Only include taxPercent if it's not exempt (taxCode='E' means exempt - must not include taxPercent)
                // Even if taxPercent is 0, if taxCode='E', we must NOT include it
                if (isset($tax['taxCode']) && $tax['taxCode'] === 'E') {
                    // Exempt tax - do NOT include taxPercent field
                } elseif (isset($tax['taxPercent']) && $tax['taxPercent'] !== null) {
                    // Non-exempt tax - include taxPercent
                    $reorderedTax['taxPercent'] = $tax['taxPercent'];
                }
                if (isset($tax['taxID'])) $reorderedTax['taxID'] = $tax['taxID'];
                if (isset($tax['taxAmount'])) $reorderedTax['taxAmount'] = $tax['taxAmount'];
                if (isset($tax['salesAmountWithTax'])) $reorderedTax['salesAmountWithTax'] = $tax['salesAmountWithTax'];
                $tax = $reorderedTax;
            }
            unset($tax);
        }
        
        // Remove deviceID from receipt data before sending to API
        // deviceID is needed for signature generation but is not part of the receipt object structure
        // It's only used in the URL path: /Device/v1/{deviceID}/SubmitReceipt
        $deviceID = $receiptData['deviceID'] ?? null;
        unset($receiptData['deviceID']);
        
        // Helper function to write logs directly to file
        $writeLog = function($message) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[$timestamp] $message" . PHP_EOL;
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
            error_log($message);
        };
        
        // Log device signature hash for comparison with ZIMRA response
        $writeLog("ZIMRA SUBMIT RECEIPT: Device signature hash being sent: " . substr($deviceSignature['hash'], 0, 30) . "...");
        
        // Log complete receipt data being sent to ZIMRA (for debugging RCPT025/RCPT026)
        $writeLog("ZIMRA SUBMIT RECEIPT: ========== COMPLETE RECEIPT DATA ==========");
        $writeLog("ZIMRA SUBMIT RECEIPT: receiptLinesTaxInclusive = " . ($receiptData['receiptLinesTaxInclusive'] ?? 'not set'));
        $writeLog("ZIMRA SUBMIT RECEIPT: receiptTotal = " . ($receiptData['receiptTotal'] ?? 'not set'));
        $writeLog("ZIMRA SUBMIT RECEIPT: receiptLines count = " . count($receiptData['receiptLines'] ?? []));
        $writeLog("ZIMRA SUBMIT RECEIPT: receiptTaxes count = " . count($receiptData['receiptTaxes'] ?? []));
        $writeLog("ZIMRA SUBMIT RECEIPT: All receiptLines: " . json_encode($receiptData['receiptLines'] ?? [], JSON_PRETTY_PRINT));
        $writeLog("ZIMRA SUBMIT RECEIPT: All receiptTaxes: " . json_encode($receiptData['receiptTaxes'] ?? [], JSON_PRETTY_PRINT));
        
        // Calculate and log expected vs actual tax amounts for validation
        if (!empty($receiptData['receiptTaxes']) && !empty($receiptData['receiptLines'])) {
            $writeLog("ZIMRA SUBMIT RECEIPT: ========== TAX VALIDATION CHECK ==========");
            foreach ($receiptData['receiptTaxes'] as $taxIndex => $tax) {
                // Sum all receiptLineTotal for lines with matching taxPercent and taxID
                // NOTE: receiptLines don't have taxCode (removed before sending), so match by taxPercent and taxID
                $sumLineTotal = 0;
                foreach ($receiptData['receiptLines'] as $line) {
                    $taxPercentMatch = abs(floatval($line['taxPercent'] ?? 0) - floatval($tax['taxPercent'])) < 0.01;
                    $taxIDMatch = (isset($line['taxID']) && isset($tax['taxID']) && intval($line['taxID']) === intval($tax['taxID']));
                    if ($taxPercentMatch && $taxIDMatch) {
                        $sumLineTotal += floatval($line['receiptLineTotal']);
                    }
                }
                
                $isTaxInclusive = $receiptData['receiptLinesTaxInclusive'] ?? true;
                $taxPercentValue = floatval($tax['taxPercent']);
                
                // Calculate expected tax amount per RCPT026
                $expectedTaxAmount = 0;
                if ($isTaxInclusive) {
                    if ($taxPercentValue > 1) {
                        $taxPercentDecimal = $taxPercentValue / 100;
                        $expectedTaxAmount = $sumLineTotal * ($taxPercentDecimal / (1 + $taxPercentDecimal));
                    } else {
                        $expectedTaxAmount = $sumLineTotal * ($taxPercentValue / (1 + $taxPercentValue));
                    }
                } else {
                    if ($taxPercentValue > 1) {
                        $taxPercentDecimal = $taxPercentValue / 100;
                        $expectedTaxAmount = $sumLineTotal * $taxPercentDecimal;
                    } else {
                        $expectedTaxAmount = $sumLineTotal * $taxPercentValue;
                    }
                }
                $expectedTaxAmount = round($expectedTaxAmount, 2);
                
                $writeLog("ZIMRA SUBMIT RECEIPT: Tax[$taxIndex] - taxID={$tax['taxID']}, taxPercent={$tax['taxPercent']}, taxCode={$tax['taxCode']}");
                $writeLog("ZIMRA SUBMIT RECEIPT: Tax[$taxIndex] - SUM(receiptLineTotal) for matching lines = $sumLineTotal");
                $writeLog("ZIMRA SUBMIT RECEIPT: Tax[$taxIndex] - Expected taxAmount (RCPT026) = $expectedTaxAmount");
                $writeLog("ZIMRA SUBMIT RECEIPT: Tax[$taxIndex] - Actual taxAmount being sent = {$tax['taxAmount']}");
                $writeLog("ZIMRA SUBMIT RECEIPT: Tax[$taxIndex] - salesAmountWithTax = {$tax['salesAmountWithTax']}");
                $writeLog("ZIMRA SUBMIT RECEIPT: Tax[$taxIndex] - Match: " . (abs($expectedTaxAmount - $tax['taxAmount']) < 0.01 ? 'YES ✓' : 'NO ✗ (DIFFERENCE: ' . abs($expectedTaxAmount - $tax['taxAmount']) . ')'));
            }
            $writeLog("ZIMRA SUBMIT RECEIPT: ========== END TAX VALIDATION ==========");
        }
        
        $writeLog("ZIMRA SUBMIT RECEIPT: ========== END RECEIPT DATA ==========");
        
        // Log the exact JSON being sent to ZIMRA (before deviceID removal)
        $receiptDataForLogging = $receiptData;
        $receiptDataForLogging['deviceID'] = $deviceID; // Add back for logging
        $writeLog("ZIMRA SUBMIT RECEIPT: Complete receipt data JSON being sent: " . json_encode(['receipt' => $receiptData], JSON_PRETTY_PRINT));
        
        // Submit to ZIMRA
        try {
            $response = $this->api->submitReceipt($this->deviceId, $receiptData);
            
            // Log the RAW ZIMRA response (exactly as received) BEFORE we modify it
            $writeLog("ZIMRA SUBMIT RECEIPT: ========== RAW ZIMRA RESPONSE (EXACTLY AS RECEIVED) ==========");
            $writeLog("ZIMRA RAW response (JSON): " . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $writeLog("ZIMRA SUBMIT RECEIPT: ========== END RAW ZIMRA RESPONSE ==========");
        } catch (Exception $e) {
            // Log error
            ZimraLogger::logReceipt($this->deviceId, $receiptData, ['error' => $e->getMessage()], $deviceSignature['hash'] ?? null);
            // Re-throw ZIMRA API errors with full details
            throw $e;
        }
        
        // Log receipt submission using comprehensive logger
        ZimraLogger::logReceipt($this->deviceId, $receiptData, $response, $deviceSignature['hash'] ?? null);
        
        // Log the full response for debugging
        $writeLog("ZIMRA SUBMIT RECEIPT: ========== ZIMRA RESPONSE ==========");
        $writeLog("ZIMRA submitReceipt response: " . json_encode($response, JSON_PRETTY_PRINT));
        
        // Compare our hash with ZIMRA's hash if available
        if (isset($response['receiptServerSignature']['hash'])) {
            $zimraHash = $response['receiptServerSignature']['hash'];
            $ourHash = $deviceSignature['hash'];
            $writeLog("ZIMRA SUBMIT RECEIPT: Hash comparison - Our hash: " . substr($ourHash, 0, 30) . "...");
            $writeLog("ZIMRA SUBMIT RECEIPT: Hash comparison - ZIMRA hash: " . substr($zimraHash, 0, 30) . "...");
            $writeLog("ZIMRA SUBMIT RECEIPT: Hash comparison - Match: " . ($ourHash === $zimraHash ? "YES" : "NO - SIGNATURE STRING FORMAT IS WRONG"));
        }
        
        // Check for validation errors - log them but don't throw exception yet
        // CRITICAL: ZIMRA still accepts receipts with validation errors (returns receiptID and hash)
        // We need to save the receipt even with errors so we can retrieve the hash for the next receipt
        $hasValidationErrors = false;
        $validationErrors = [];
        if (!empty($response['validationErrors']) && is_array($response['validationErrors'])) {
            $hasValidationErrors = true;
            $writeLog("ZIMRA SUBMIT RECEIPT: ========== VALIDATION ERRORS DETECTED ==========");
            foreach ($response['validationErrors'] as $validationError) {
                $errorCode = $validationError['validationErrorCode'] ?? 'UNKNOWN';
                $errorColor = $validationError['validationErrorColor'] ?? 'Unknown';
                $validationErrors[] = "Code: $errorCode (Color: $errorColor)";
                $writeLog("ZIMRA SUBMIT RECEIPT: Validation Error - Code: $errorCode, Color: $errorColor");
            }
            
            // Log the receipt data that has validation errors for debugging
            $writeLog("ZIMRA SUBMIT RECEIPT: Receipt data with validation errors:");
            $writeLog("ZIMRA SUBMIT RECEIPT: receiptTaxes: " . json_encode($receiptData['receiptTaxes'] ?? [], JSON_PRETTY_PRINT));
            $writeLog("ZIMRA SUBMIT RECEIPT: receiptLines: " . json_encode($receiptData['receiptLines'] ?? [], JSON_PRETTY_PRINT));
            $writeLog("ZIMRA SUBMIT RECEIPT: Full ZIMRA response: " . json_encode($response, JSON_PRETTY_PRINT));
            $writeLog("ZIMRA SUBMIT RECEIPT: ========== END VALIDATION ERRORS ==========");
            $writeLog("ZIMRA SUBMIT RECEIPT: NOTE - Receipt will still be saved so hash can be retrieved for next receipt");
        }
        
        // Validate ZIMRA response contains required fields
        // Note: receiptGlobalNo might not be in the response if validation failed
        $missingFields = [];
        if (empty($response['receiptID'])) {
            $missingFields[] = 'receiptID';
        }
        if (empty($response['serverDate'])) {
            $missingFields[] = 'serverDate';
        }
        // receiptServerSignature might not be present in first receipt response
        // Only check if it's missing for subsequent receipts (when we expect it)
        if (empty($response['receiptServerSignature']) && $receiptData['receiptCounter'] > 1) {
            $missingFields[] = 'receiptServerSignature';
        }
        
        // receiptGlobalNo might not always be in the response
        // If it's missing, we'll use receiptID as fallback or get it from the request
        $receiptGlobalNo = $response['receiptGlobalNo'] ?? null;
        if (empty($receiptGlobalNo)) {
            // Try to use the receiptGlobalNo from the request data
            $receiptGlobalNo = $receiptData['receiptGlobalNo'] ?? null;
            if (empty($receiptGlobalNo)) {
                $missingFields[] = 'receiptGlobalNo (not in response or request)';
            }
        }
        
        if (!empty($missingFields)) {
            $responseJson = json_encode($response, JSON_PRETTY_PRINT);
            $errorMsg = 'Invalid ZIMRA response: Missing required fields (' . implode(', ', $missingFields) . '). ';
            $errorMsg .= 'Full response: ' . substr($responseJson, 0, 2000);
            throw new Exception($errorMsg);
        }
        
        // Get QR URL from config (from getConfig response)
        $config = $this->db->getRow(
            "SELECT * FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
            [':branch_id' => $this->branchId, ':device_id' => $this->deviceId]
        );
        
        if (empty($config['qr_url'])) {
            throw new Exception('QR URL not found in fiscal config. Please sync configuration from ZIMRA first.');
        }
        
        // Generate receiptQrData from ReceiptDeviceSignature (first 16 chars of MD5 hash from hexadecimal format)
        // Method matches Python library: base64 decode -> hex -> hex2bin -> MD5 -> first 16 chars
        // This is the ONLY local generation allowed per ZIMRA spec section 11
        $qrData = ZimraQRCode::generateReceiptQrData($deviceSignature);
        
        // Generate QR code according to ZIMRA spec section 11
        // qrUrl: from config (from getConfig response)
        // deviceID: from device config (10 digits with leading zeros)
        // receiptDate: from receiptDate field value (format: ddMMyyyy) - NOT serverDate!
        // receiptGlobalNo: from ZIMRA response (10 digits with leading zeros) or from request if not in response - ZIMRA requires 10 digits
        // receiptQrData: from device signature MD5 hash (16 chars)
        $qrUrl = $config['qr_url'];
        // CRITICAL: Use receiptDate from the receipt data we sent, NOT serverDate from ZIMRA response
        // Documentation: "Invoice date (receiptDate field value) represented in 8 digits (format: ddMMyyyy)"
        $receiptDateFromReceipt = $receiptData['receiptDate']; // This is the receiptDate we sent to ZIMRA
        $zimraReceiptGlobalNo = $receiptGlobalNo; // Use receiptGlobalNo from response or request
        
        $qrCodeResult = ZimraQRCode::generateQRCode($qrUrl, $this->deviceId, $receiptDateFromReceipt, $zimraReceiptGlobalNo, $qrData);
        $verificationCode = $qrCodeResult['verificationCode'];
        $qrCodeString = $qrCodeResult['qrCode'];
        
        // Generate QR code image using TCPDF2DBarcode (required for display)
        $qrImageBase64 = null;
        if (class_exists('TCPDF2DBarcode')) {
            try {
                require_once APP_PATH . '/vendor/autoload.php';
                $qr = new TCPDF2DBarcode($qrCodeString, 'QRCODE,L');
                $qrImageData = $qr->getBarcodePngData(4, 4, array(0, 0, 0));
                if ($qrImageData && strlen($qrImageData) > 0) {
                    $qrImageBase64 = base64_encode($qrImageData);
                } else {
                    throw new Exception('Failed to generate QR code image: TCPDF2DBarcode returned empty data');
                }
            } catch (Exception $e) {
                throw new Exception('Failed to generate QR code image: ' . $e->getMessage());
            }
        } else {
            throw new Exception('TCPDF2DBarcode class not available. Cannot generate QR code image.');
        }
        
        $qrCode = [
            'qrCode' => $qrCodeString,
            'verificationCode' => $verificationCode,
            'qrImage' => $qrImageBase64
        ];
        
        // CRITICAL FIX: Use OUR generated hash for receipt chaining (tested and confirmed working)
        // Based on testing, ZIMRA expects us to use OUR generated hash (the one we signed with) for the next receipt's previousReceiptHash.
        // Even though ZIMRA returns a different hash in receiptServerSignature, we MUST use our hash for chaining to avoid RCPT020 errors.
        // 
        // Test results show: When using our hash for chaining, all receipts are accepted without RCPT020 errors.
        // When using ZIMRA's hash for chaining, subsequent receipts get RCPT020 errors.
        $ourHash = $deviceSignature['hash'];
        $zimraHash = null;
        
        if (isset($response['receiptServerSignature']['hash']) && !empty($response['receiptServerSignature']['hash'])) {
            $zimraHash = $response['receiptServerSignature']['hash'];
            $writeLog("HASH EXTRACTION: Using OUR generated hash for receipt chaining");
            $writeLog("HASH EXTRACTION: Our hash (used for chaining): " . $ourHash);
            $writeLog("HASH EXTRACTION: ZIMRA's hash (from response): " . $zimraHash);
            $writeLog("HASH EXTRACTION: Hashes match: " . ($ourHash === $zimraHash ? "YES ✓" : "NO ✗ - Using our hash for chaining per test results"));
        } else {
            // ZIMRA should return receiptServerSignature, but if not, use our hash (which is what we should use anyway)
            $writeLog("HASH EXTRACTION: receiptServerSignature not in response, using our generated hash");
        }
        
        // Use our hash for chaining (this is what gets saved to receipt_hash and used for next receipt)
        $hashForChaining = $ourHash;
        
        // Save fiscal receipt
        $fiscalReceiptData = [
            'invoice_id' => $invoiceId > 0 ? $invoiceId : null,
            'sale_id' => $saleId,
            'branch_id' => $this->branchId,
            'device_id' => $this->deviceId,
            'fiscal_day_no' => $fiscalDay['fiscal_day_no'],
            'receipt_type' => $receiptData['receiptType'],
            'receipt_currency' => $receiptData['receiptCurrency'],
            'receipt_counter' => $receiptData['receiptCounter'],
            'receipt_global_no' => $receiptGlobalNo, // Use calculated value (from response or request)
            'invoice_no' => $receiptData['invoiceNo'],
            'receipt_date' => $receiptData['receiptDate'], // CRITICAL: Store receiptDate from receipt data (not serverDate) - needed for QR code generation per ZIMRA spec section 11
            'receipt_total' => $receiptData['receiptTotal'],
            'receipt_hash' => $hashForChaining, // CRITICAL: Use OUR generated hash for receipt chaining (tested and confirmed working)
            // Test results show that using our hash (not ZIMRA's) prevents RCPT020 errors on subsequent receipts
            'receipt_device_signature' => json_encode($deviceSignature),
            'receipt_server_signature' => json_encode($response['receiptServerSignature']),
            'receipt_id' => $response['receiptID'],
            'receipt_qr_code' => $qrCode['qrImage'], // Store base64 encoded QR image
            'receipt_qr_data' => $qrData,
            'receipt_verification_code' => $qrCode['verificationCode'],
            'submission_status' => 'Submitted',
            'submitted_at' => date('Y-m-d H:i:s')
        ];
        
        // Save fiscal receipt to database
        // Note: Even if this fails, we still return the QR code since ZIMRA already accepted the receipt
        $fiscalReceiptId = null;
        try {
            $fiscalReceiptId = $this->db->insert('fiscal_receipts', $fiscalReceiptData);
            
            // Save receipt lines
            foreach ($receiptData['receiptLines'] as $line) {
                $this->db->insert('fiscal_receipt_lines', [
                    'fiscal_receipt_id' => $fiscalReceiptId,
                    'receipt_line_no' => $line['receiptLineNo'],
                    'receipt_line_type' => $line['receiptLineType'],
                    'receipt_line_name' => $line['receiptLineName'],
                    'receipt_line_hs_code' => $line['receiptLineHSCode'] ?? null,
                    'receipt_line_price' => $line['receiptLinePrice'] ?? null,
                    'receipt_line_quantity' => $line['receiptLineQuantity'],
                    'receipt_line_total' => $line['receiptLineTotal'],
                    'tax_code' => $line['taxCode'] ?? null,
                    'tax_percent' => $line['taxPercent'] ?? null,
                    'tax_id' => $line['taxID']
                ]);
            }
            
            // Save receipt taxes
            foreach ($receiptData['receiptTaxes'] as $tax) {
                $this->db->insert('fiscal_receipt_taxes', [
                    'fiscal_receipt_id' => $fiscalReceiptId,
                    'tax_code' => $tax['taxCode'] ?? null,
                    'tax_percent' => $tax['taxPercent'] ?? null,
                    'tax_id' => $tax['taxID'],
                    'tax_amount' => $tax['taxAmount'],
                    'sales_amount_with_tax' => $tax['salesAmountWithTax']
                ]);
            }
            
            // Save receipt payments
            foreach ($receiptData['receiptPayments'] as $payment) {
                $this->db->insert('fiscal_receipt_payments', [
                    'fiscal_receipt_id' => $fiscalReceiptId,
                    'money_type_code' => $payment['moneyTypeCode'],
                    'payment_amount' => $payment['paymentAmount']
                ]);
            }
            
            $writeLog("ZIMRA SUBMIT RECEIPT: Successfully saved fiscal receipt to database (ID: $fiscalReceiptId)");
        } catch (Exception $dbError) {
            // Log the error but don't fail - ZIMRA already accepted the receipt
            $writeLog("ZIMRA SUBMIT RECEIPT: WARNING - Failed to save fiscal receipt to database: " . $dbError->getMessage());
            $writeLog("ZIMRA SUBMIT RECEIPT: Receipt was accepted by ZIMRA (receiptID: {$response['receiptID']}), but database save failed. QR code will still be returned.");
            error_log("FISCAL RECEIPT DB ERROR: " . $dbError->getMessage());
        }
        
        // Update fiscal day counters
        $this->updateFiscalDayCounters($fiscalDay['id'], $receiptData);
        
        // Update invoice
        $this->db->update('invoices', [
            'fiscalized' => 1,
            'fiscalized_at' => date('Y-m-d H:i:s'),
            'fiscal_details' => json_encode([
                'receipt_id' => $response['receiptID'],
                'receipt_global_no' => $receiptGlobalNo, // Use calculated value
                'receipt_counter' => $receiptData['receiptCounter'],
                'device_id' => $this->deviceId,
                'fiscal_day_no' => $fiscalDay['fiscal_day_no'],
                'qr_code' => $qrCode['qrCode'],
                'verification_code' => $qrCode['verificationCode']
            ])
        ], ['id' => $invoiceId]);
        
        // CRITICAL: Return ALL fields from ZIMRA response, including receiptServerSignature
        // The response must include receiptServerSignature.hash for next receipt's previousReceiptHash
        $returnData = [
            'fiscalReceiptId' => $fiscalReceiptId,
            'receiptID' => $response['receiptID'],
            'receiptGlobalNo' => $receiptGlobalNo, // Use calculated value (from response or request)
            'qrCode' => $qrCode['qrCode'],
            'verificationCode' => $qrCode['verificationCode'],
            'qrCodeImage' => $qrCode['qrImage'] // Base64 encoded QR image for immediate use
        ];
        
        // CRITICAL: Include ALL ZIMRA response fields, especially receiptServerSignature
        if (isset($response['serverDate'])) {
            $returnData['serverDate'] = $response['serverDate'];
        }
        if (isset($response['operationID'])) {
            $returnData['operationID'] = $response['operationID'];
        }
        if (isset($response['receiptServerSignature'])) {
            $returnData['receiptServerSignature'] = $response['receiptServerSignature'];
        }
        if (isset($response['validationErrors'])) {
            $returnData['validationErrors'] = $response['validationErrors'];
        }
        
        // Log what we're returning
        $writeLog("ZIMRA SUBMIT RECEIPT: Return data includes receiptServerSignature: " . (isset($returnData['receiptServerSignature']) ? "YES" : "NO"));
        if (isset($returnData['receiptServerSignature']['hash'])) {
            $writeLog("ZIMRA SUBMIT RECEIPT: Return data hash: " . substr($returnData['receiptServerSignature']['hash'], 0, 30) . "...");
        }
        
        return $returnData;
    }
    
    /**
     * Update fiscal day counters
     */
    private function updateFiscalDayCounters($fiscalDayId, $receiptData) {
        // This is a simplified version - full implementation would track all counter types
        // For now, we'll update counters when closing the day
    }
    
    /**
     * Close fiscal day
     */
    public function closeFiscalDay() {
        // ALWAYS check ZIMRA first (source of truth) and sync local database
        $zimraStatus = null;
        try {
            $zimraStatus = $this->api->getStatus($this->deviceId);
            
            // Sync local database with ZIMRA status
            $syncedDay = $this->syncFiscalDayWithZimra($zimraStatus);
            
            // If ZIMRA says day is not open (or close failed), we can't close it
            // FiscalDayCloseFailed means a previous close attempt failed - we should allow retrying
            $zimraDayStatus = $zimraStatus['fiscalDayStatus'] ?? null;
            $canClose = ($zimraDayStatus === 'FiscalDayOpened' || $zimraDayStatus === 'FiscalDayCloseFailed');
            
            if (!$canClose) {
                throw new Exception('No open fiscal day to close. ZIMRA reports status: ' . ($zimraDayStatus ?? 'Unknown'));
            }
            
            // If sync created/updated a record, use it
            if ($syncedDay) {
                $fiscalDay = $syncedDay;
            } else {
                // Sync might not have created a record if lastFiscalDayNo is missing
                // Try to get from local database anyway
                $fiscalDay = $this->db->getRow(
                    "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status IN ('FiscalDayOpened', 'FiscalDayCloseFailed', 'FiscalDayCloseInitiated') ORDER BY id DESC LIMIT 1",
                    [':branch_id' => $this->branchId, ':device_id' => $this->deviceId]
                );
                
                // If still no record and ZIMRA has FiscalDayCloseFailed, try to create one
                if (!$fiscalDay && $zimraDayStatus === 'FiscalDayCloseFailed') {
                    $zimraDayNo = $zimraStatus['lastFiscalDayNo'] ?? null;
                    if ($zimraDayNo) {
                        // Create record for the failed close attempt
                        $fiscalDayOpened = date('Y-m-d\TH:i:s');
                        $fiscalDayId = $this->db->insert('fiscal_days', [
                            'branch_id' => $this->branchId,
                            'device_id' => $this->deviceId,
                            'fiscal_day_no' => $zimraDayNo,
                            'fiscal_day_opened' => $fiscalDayOpened,
                            'status' => 'FiscalDayCloseFailed',
                            'last_receipt_global_no' => $zimraStatus['lastReceiptGlobalNo'] ?? 0,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        if ($fiscalDayId) {
                            error_log("CLOSE FISCAL DAY: Created local record for FiscalDayCloseFailed (ID: $fiscalDayId, Day No: $zimraDayNo)");
                            $fiscalDay = $this->db->getRow(
                                "SELECT * FROM fiscal_days WHERE id = :id",
                                [':id' => $fiscalDayId]
                            );
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // If error message indicates no open day, re-throw it
            if (strpos($e->getMessage(), 'No open fiscal day') !== false) {
                throw $e;
            }
            // If we can't get ZIMRA status, try local database as fallback
            error_log("Warning: Could not get ZIMRA status: " . $e->getMessage());
            $fiscalDay = $this->db->getRow(
                "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status IN ('FiscalDayOpened', 'FiscalDayCloseFailed', 'FiscalDayCloseInitiated') ORDER BY id DESC LIMIT 1",
                [':branch_id' => $this->branchId, ':device_id' => $this->deviceId]
            );
        }
        
        if (!$fiscalDay) {
            // Provide more helpful error message
            $statusMsg = isset($zimraStatus) ? 'ZIMRA reports status: ' . ($zimraStatus['fiscalDayStatus'] ?? 'Unknown') : 'Could not check ZIMRA status';
            throw new Exception('No open fiscal day to close. ' . $statusMsg . '. Please check ZIMRA status first or contact support.');
        }
        
        // Define logging function
        $writeLog = function($message) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[$timestamp] CLOSE FISCAL DAY: $message" . PHP_EOL;
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
            error_log("CLOSE FISCAL DAY: $message");
        };
        
        // Calculate fiscal day counters
        $counters = $this->calculateFiscalDayCounters($fiscalDay['id']);
        
        // Log original counters BEFORE sorting
        $writeLog("========== ORIGINAL COUNTERS (BEFORE SORTING) ==========");
        $writeLog("Total counters: " . count($counters));
        foreach ($counters as $idx => $counter) {
            $writeLog("[$idx] " . json_encode($counter));
        }
        $writeLog("================================================");
        
        // CRITICAL FIX: Sort counters BEFORE signature generation AND API call
        // This ensures the counters sent to ZIMRA are in the EXACT same order as used in the signature
        // ZIMRA sorts counters by: type → currency → taxID → moneyType before verifying signature
        // We MUST match their sorting order EXACTLY (using numeric type priorities like Python)
        require_once APP_PATH . '/includes/zimra_signature.php';
        $counters = ZimraSignature::sortCountersForZimra($counters);
        
        // Log sorted counters AFTER sorting
        $writeLog("========== SORTED COUNTERS (AFTER SORTING - MATCHING PYTHON) ==========");
        $writeLog("Total counters: " . count($counters));
        foreach ($counters as $idx => $counter) {
            $writeLog("[$idx] " . json_encode($counter));
        }
        $writeLog("================================================");
        
        // Generate fiscal day signature
        $fiscalDayData = [
            'deviceID' => $this->deviceId,
            'fiscalDayNo' => $fiscalDay['fiscal_day_no'],
            'fiscalDayOpened' => $fiscalDay['fiscal_day_opened'],
            'fiscalDayCounters' => $counters
        ];
        
        // CRITICAL: Always reload certificate from CertificateStorage to ensure we have the latest
        // This ensures we're using the same certificate that was used for receipt submission
        require_once APP_PATH . '/includes/certificate_storage.php';
        $certData = CertificateStorage::loadCertificate($this->deviceId);
        
        if (!$certData || !$certData['certificate'] || !$certData['privateKey']) {
            error_log("CLOSE FISCAL DAY: ERROR - Could not load certificate from CertificateStorage");
            throw new Exception("Certificate not found for device {$this->deviceId}. Please ensure the device is registered.");
        }
        
        $certificatePem = $certData['certificate'];
        $privateKeyPem = $certData['privateKey'];
        
        // CRITICAL: Validate certificate before using it
        $this->validateCertificateForOperation($certificatePem, $privateKeyPem, 'fiscal_day_close');
        
        // Log certificate details for debugging
        $certFingerprint = null;
        $certSubject = null;
        if ($certificatePem) {
            $cert = openssl_x509_read($certificatePem);
            if ($cert) {
                $certDetails = openssl_x509_parse($cert);
                $certFingerprint = openssl_x509_fingerprint($cert, 'sha256', false);
                $certSubject = $certDetails['subject']['CN'] ?? 'N/A';
            }
        }
        
        $keyFingerprint = null;
        if ($privateKeyPem) {
            $key = openssl_pkey_get_private($privateKeyPem);
            if ($key) {
                $keyDetails = openssl_pkey_get_details($key);
                if (isset($keyDetails['key'])) {
                    $keyFingerprint = hash('sha256', $keyDetails['key']);
                }
            }
        }
        
        $writeLog("========== CERTIFICATE LOADED FOR FISCAL DAY CLOSE ==========");
        $writeLog("Certificate Subject: " . ($certSubject ?? 'N/A'));
        $writeLog("Certificate Fingerprint (SHA256): " . ($certFingerprint ?? 'N/A'));
        $writeLog("Private Key Fingerprint (SHA256 of public key): " . ($keyFingerprint ?? 'N/A'));
        $writeLog("Certificate Length: " . strlen($certificatePem) . " bytes");
        $writeLog("Private Key Length: " . strlen($privateKeyPem) . " bytes");
        $writeLog("Certificate First 50 chars: " . substr($certificatePem, 0, 50));
        $writeLog("Certificate Last 50 chars: " . substr($certificatePem, -50));
        $writeLog("Private Key First 50 chars: " . substr($privateKeyPem, 0, 50));
        $writeLog("Private Key Last 50 chars: " . substr($privateKeyPem, -50));
        $writeLog("================================================");
        
        // CRITICAL: Update the API client's certificate to ensure it uses the fresh certificate
        // This is important because the certificate might have been updated after the constructor ran
        $this->api->setCertificate($certificatePem, $privateKeyPem);
        $writeLog("API client certificate updated");
        
        // Update device record in memory for consistency (don't save to DB - CertificateStorage is source of truth)
        $this->device['certificate_pem'] = $certificatePem;
        $this->device['private_key_pem'] = $privateKeyPem;
        
        // Verify private key is valid before using it
        $testKey = openssl_pkey_get_private($privateKeyPem);
        if (!$testKey) {
            $error = openssl_error_string();
            error_log("CLOSE FISCAL DAY: ERROR - Private key is invalid: $error");
            throw new Exception("Invalid private key for device {$this->deviceId}. Please verify the certificate and private key are correct. Error: $error");
        }
        
        // Verify certificate matches private key (use the reloaded certificate)
        $certPem = $certificatePem;
        if ($certPem) {
            $cert = openssl_x509_read($certPem);
            if ($cert) {
                $certPubKey = openssl_pkey_get_public($certPem);
                $keyDetails = openssl_pkey_get_details($testKey);
                $certDetails = openssl_pkey_get_details($certPubKey);
                
                // Compare public keys (they should match)
                if ($keyDetails && $certDetails && isset($keyDetails['key']) && isset($certDetails['key'])) {
                    if ($keyDetails['key'] !== $certDetails['key']) {
                        error_log("CLOSE FISCAL DAY: WARNING - Certificate public key doesn't match private key!");
                        throw new Exception("Certificate and private key do not match for device {$this->deviceId}. Please re-register the device.");
                    } else {
                        error_log("CLOSE FISCAL DAY: Certificate and private key verified - they match");
                    }
                }
            }
        }
        
        // Log signature generation details
        $writeLog("========== GENERATING FISCAL DAY SIGNATURE ==========");
        $writeLog("Fiscal Day Data: " . json_encode($fiscalDayData, JSON_PRETTY_PRINT));
        $writeLog("Using Private Key Fingerprint: " . ($keyFingerprint ?? 'N/A'));
        
        $deviceSignature = ZimraSignature::generateFiscalDayDeviceSignature(
            $fiscalDayData,
            $privateKeyPem
        );
        
        $writeLog("Generated Signature Hash: " . ($deviceSignature['hash'] ?? 'N/A'));
        $writeLog("Generated Signature (first 100 chars): " . substr($deviceSignature['signature'] ?? '', 0, 100));
        $writeLog("================================================");
        
        // Get last receipt counter
        $lastReceipt = $this->db->getRow(
            "SELECT receipt_counter FROM fiscal_receipts WHERE device_id = :device_id AND fiscal_day_no = :fiscal_day_no ORDER BY receipt_counter DESC LIMIT 1",
            [':device_id' => $this->deviceId, ':fiscal_day_no' => $fiscalDay['fiscal_day_no']]
        );
        
        $receiptCounter = $lastReceipt ? $lastReceipt['receipt_counter'] : 0;
        
        // Log the EXACT payload that will be sent to ZIMRA
        $writeLog("========== PAYLOAD TO BE SENT TO ZIMRA ==========");
        $payloadToLog = [
            'deviceID' => $this->deviceId,
            'fiscalDayNo' => $fiscalDay['fiscal_day_no'],
            'fiscalDayCounters' => $counters,
            'fiscalDayDeviceSignature' => $deviceSignature,
            'receiptCounter' => $receiptCounter
        ];
        $writeLog(json_encode($payloadToLog, JSON_PRETTY_PRINT));
        $writeLog("================================================");
        
        // Prepare request data for logging
        $requestData = [
            'device_id' => $this->deviceId,
            'fiscal_day_no' => $fiscalDay['fiscal_day_no'],
            'counters' => $counters,
            'receipt_counter' => $receiptCounter,
            'signature_hash' => $deviceSignature['hash'] ?? null
        ];
        
        // Call ZIMRA API
        $response = $this->api->closeDay(
            $this->deviceId,
            $fiscalDay['fiscal_day_no'],
            $counters,
            $deviceSignature,
            $receiptCounter
        );
        
        // Log operation
        ZimraLogger::log('CLOSE_FISCAL_DAY', $requestData, $response, $this->deviceId);
        
        // Update fiscal day
        $this->db->update('fiscal_days', [
            'status' => 'FiscalDayCloseInitiated',
            'fiscal_day_device_signature' => json_encode($deviceSignature),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $fiscalDay['id']]);
        
        // Save counters
        foreach ($counters as $counter) {
            $this->db->insert('fiscal_counters', [
                'fiscal_day_id' => $fiscalDay['id'],
                'fiscal_counter_type' => $counter['fiscalCounterType'],
                'fiscal_counter_currency' => $counter['fiscalCounterCurrency'],
                'fiscal_counter_tax_id' => $counter['fiscalCounterTaxID'] ?? null,
                'fiscal_counter_tax_percent' => $counter['fiscalCounterTaxPercent'] ?? null,
                'fiscal_counter_money_type' => $counter['fiscalCounterMoneyType'] ?? null,
                'fiscal_counter_value' => $counter['fiscalCounterValue']
            ]);
        }
        
        return $response;
    }
    
    /**
     * Calculate fiscal day counters
     */
    private function calculateFiscalDayCounters($fiscalDayId) {
        // Get fiscal day record to get fiscal_day_no
        $fiscalDay = $this->db->getRow(
            "SELECT * FROM fiscal_days WHERE id = :id",
            [':id' => $fiscalDayId]
        );
        
        if (!$fiscalDay) {
            error_log("ERROR: Could not find fiscal day with ID: $fiscalDayId");
            return [];
        }
        
        $fiscalDayNo = $fiscalDay['fiscal_day_no'];
        
        // CRITICAL FIX: Get receipts WITHOUT JOIN to avoid duplicate counting
        // We'll process each receipt once and get its taxes separately
        $receipts = $this->db->getRows(
            "SELECT fr.* FROM fiscal_receipts fr 
             WHERE fr.device_id = :device_id AND fr.fiscal_day_no = :fiscal_day_no
             AND fr.submission_status = 'Submitted'
             ORDER BY fr.receipt_counter ASC",
            [':device_id' => $this->deviceId, ':fiscal_day_no' => $fiscalDayNo]
        );
        
        if (empty($receipts)) {
            error_log("WARNING: No receipts found for fiscal day $fiscalDayNo. Returning empty counters.");
            return [];
        }
        
        $counters = [];
        $salesByTax = []; // [currency][taxID][taxPercent] => total (FiscalInvoice only)
        $salesTaxByTax = []; // [currency][taxID][taxPercent] => tax amount (FiscalInvoice only)
        $creditNoteByTax = []; // [currency][taxID][taxPercent] => total (CreditNote only, negative)
        $creditNoteTaxByTax = []; // [currency][taxID][taxPercent] => tax amount (CreditNote only, negative)
        $debitNoteByTax = []; // [currency][taxID][taxPercent] => total (DebitNote only)
        $debitNoteTaxByTax = []; // [currency][taxID][taxPercent] => tax amount (DebitNote only)
        $balanceByMoneyType = []; // [currency][moneyType] => total (all receipt types)
        
        foreach ($receipts as $receipt) {
            $receiptType = $receipt['receipt_type'] ?? 'FiscalInvoice';
            $currency = strtoupper($receipt['receipt_currency'] ?? 'ZWL');
            $receiptTotal = floatval($receipt['receipt_total'] ?? 0);
            
            // Get payment methods for this receipt (a receipt can have multiple payments)
            $payments = $this->db->getRows(
                "SELECT money_type_code, payment_amount FROM fiscal_receipt_payments 
                 WHERE fiscal_receipt_id = :receipt_id",
                [':receipt_id' => $receipt['id']]
            );
            
            // If no payments found, default to Cash with full receipt total
            if (empty($payments)) {
                $payments = [['money_type_code' => 'Cash', 'payment_amount' => $receiptTotal]];
            }
            
            // Process each payment for BalanceByMoneyType
            foreach ($payments as $payment) {
                // Map integer moneyTypeCode to enum string
                $moneyTypeCodeInt = intval($payment['money_type_code'] ?? 0);
                $moneyTypeMap = [
                    0 => 'Cash',
                    1 => 'Card',
                    2 => 'MobileWallet',
                    3 => 'Coupon',
                    4 => 'Credit',
                    5 => 'BankTransfer',
                    6 => 'Other'
                ];
                $moneyType = $moneyTypeMap[$moneyTypeCodeInt] ?? 'Cash';
                
                $paymentAmount = floatval($payment['payment_amount'] ?? 0);
                
                // CRITICAL: Credit notes have negative payment amounts
                // Documentation: "* - for credit note ... paymentAmount counters will be used with negative numbers"
                if ($receiptType === 'CreditNote') {
                    $paymentAmount = -abs($paymentAmount); // Ensure negative
                }
                
                if (!isset($balanceByMoneyType[$currency])) {
                    $balanceByMoneyType[$currency] = [];
                }
                if (!isset($balanceByMoneyType[$currency][$moneyType])) {
                    $balanceByMoneyType[$currency][$moneyType] = 0;
                }
                $balanceByMoneyType[$currency][$moneyType] += $paymentAmount;
            }
            
            // Get tax lines for this receipt (a receipt can have multiple tax lines)
            $taxLines = $this->db->getRows(
                "SELECT * FROM fiscal_receipt_taxes 
                 WHERE fiscal_receipt_id = :receipt_id
                 ORDER BY tax_id ASC, tax_percent ASC",
                [':receipt_id' => $receipt['id']]
            );
            
            // Process each tax line
            foreach ($taxLines as $taxLine) {
                $taxID = intval($taxLine['tax_id'] ?? 0);
                $taxPercentValue = isset($taxLine['tax_percent']) && $taxLine['tax_percent'] !== null ? floatval($taxLine['tax_percent']) : null;
                $taxAmount = floatval($taxLine['tax_amount'] ?? 0);
                $salesAmountWithTax = floatval($taxLine['sales_amount_with_tax'] ?? 0);
                
                // Convert taxPercent to string for use as array key
                $taxPercentKey = $taxPercentValue !== null ? (string)$taxPercentValue : 'exempt';
                
                // CRITICAL: According to documentation section 6:
                // - SaleByTax: Does NOT include credit notes and debit notes
                // - CreditNoteByTax: Only for credit notes, with NEGATIVE values
                // - DebitNoteByTax: Only for debit notes
                // - Credit notes decrease counters (negative values)
                
                if ($receiptType === 'FiscalInvoice') {
                    // Fiscal Invoice: Add to SaleByTax and SaleTaxByTax
                    if (!isset($salesByTax[$currency])) {
                        $salesByTax[$currency] = [];
                    }
                    if (!isset($salesByTax[$currency][$taxID])) {
                        $salesByTax[$currency][$taxID] = [];
                    }
                    if (!isset($salesByTax[$currency][$taxID][$taxPercentKey])) {
                        $salesByTax[$currency][$taxID][$taxPercentKey] = 0.0;
                    }
                    $salesByTax[$currency][$taxID][$taxPercentKey] += $salesAmountWithTax;
                    
                    if (!isset($salesTaxByTax[$currency])) {
                        $salesTaxByTax[$currency] = [];
                    }
                    if (!isset($salesTaxByTax[$currency][$taxID])) {
                        $salesTaxByTax[$currency][$taxID] = [];
                    }
                    if (!isset($salesTaxByTax[$currency][$taxID][$taxPercentKey])) {
                        $salesTaxByTax[$currency][$taxID][$taxPercentKey] = 0.0;
                    }
                    $salesTaxByTax[$currency][$taxID][$taxPercentKey] += $taxAmount;
                    
                } elseif ($receiptType === 'CreditNote') {
                    // Credit Note: Add to CreditNoteByTax and CreditNoteTaxByTax with NEGATIVE values
                    // Documentation: "* - for credit note salesAmountWithTax, taxAmount, paymentAmount counters will be used with negative numbers"
                    if (!isset($creditNoteByTax[$currency])) {
                        $creditNoteByTax[$currency] = [];
                    }
                    if (!isset($creditNoteByTax[$currency][$taxID])) {
                        $creditNoteByTax[$currency][$taxID] = [];
                    }
                    if (!isset($creditNoteByTax[$currency][$taxID][$taxPercentKey])) {
                        $creditNoteByTax[$currency][$taxID][$taxPercentKey] = 0.0;
                    }
                    // Use negative value for credit notes
                    $creditNoteByTax[$currency][$taxID][$taxPercentKey] += -abs($salesAmountWithTax);
                    
                    if (!isset($creditNoteTaxByTax[$currency])) {
                        $creditNoteTaxByTax[$currency] = [];
                    }
                    if (!isset($creditNoteTaxByTax[$currency][$taxID])) {
                        $creditNoteTaxByTax[$currency][$taxID] = [];
                    }
                    if (!isset($creditNoteTaxByTax[$currency][$taxID][$taxPercentKey])) {
                        $creditNoteTaxByTax[$currency][$taxID][$taxPercentKey] = 0.0;
                    }
                    // Use negative value for credit notes
                    $creditNoteTaxByTax[$currency][$taxID][$taxPercentKey] += -abs($taxAmount);
                    
                } elseif ($receiptType === 'DebitNote') {
                    // Debit Note: Add to DebitNoteByTax and DebitNoteTaxByTax
                    if (!isset($debitNoteByTax[$currency])) {
                        $debitNoteByTax[$currency] = [];
                    }
                    if (!isset($debitNoteByTax[$currency][$taxID])) {
                        $debitNoteByTax[$currency][$taxID] = [];
                    }
                    if (!isset($debitNoteByTax[$currency][$taxID][$taxPercentKey])) {
                        $debitNoteByTax[$currency][$taxID][$taxPercentKey] = 0.0;
                    }
                    $debitNoteByTax[$currency][$taxID][$taxPercentKey] += $salesAmountWithTax;
                    
                    if (!isset($debitNoteTaxByTax[$currency])) {
                        $debitNoteTaxByTax[$currency] = [];
                    }
                    if (!isset($debitNoteTaxByTax[$currency][$taxID])) {
                        $debitNoteTaxByTax[$currency][$taxID] = [];
                    }
                    if (!isset($debitNoteTaxByTax[$currency][$taxID][$taxPercentKey])) {
                        $debitNoteTaxByTax[$currency][$taxID][$taxPercentKey] = 0.0;
                    }
                    $debitNoteTaxByTax[$currency][$taxID][$taxPercentKey] += $taxAmount;
                }
            }
            
            // Handle exempt receipts (no tax lines)
            if (empty($taxLines)) {
                // Exempt receipt - no tax information
                $taxID = 0;
                $taxPercentKey = 'exempt';
                
                if ($receiptType === 'FiscalInvoice') {
                    if (!isset($salesByTax[$currency])) {
                        $salesByTax[$currency] = [];
                    }
                    if (!isset($salesByTax[$currency][$taxID])) {
                        $salesByTax[$currency][$taxID] = [];
                    }
                    if (!isset($salesByTax[$currency][$taxID][$taxPercentKey])) {
                        $salesByTax[$currency][$taxID][$taxPercentKey] = 0;
                    }
                    $salesByTax[$currency][$taxID][$taxPercentKey] += $receiptTotal;
                } elseif ($receiptType === 'CreditNote') {
                    if (!isset($creditNoteByTax[$currency])) {
                        $creditNoteByTax[$currency] = [];
                    }
                    if (!isset($creditNoteByTax[$currency][$taxID])) {
                        $creditNoteByTax[$currency][$taxID] = [];
                    }
                    if (!isset($creditNoteByTax[$currency][$taxID][$taxPercentKey])) {
                        $creditNoteByTax[$currency][$taxID][$taxPercentKey] = 0;
                    }
                    $creditNoteByTax[$currency][$taxID][$taxPercentKey] += -abs($receiptTotal);
                } elseif ($receiptType === 'DebitNote') {
                    if (!isset($debitNoteByTax[$currency])) {
                        $debitNoteByTax[$currency] = [];
                    }
                    if (!isset($debitNoteByTax[$currency][$taxID])) {
                        $debitNoteByTax[$currency][$taxID] = [];
                    }
                    if (!isset($debitNoteByTax[$currency][$taxID][$taxPercentKey])) {
                        $debitNoteByTax[$currency][$taxID][$taxPercentKey] = 0;
                    }
                    $debitNoteByTax[$currency][$taxID][$taxPercentKey] += $receiptTotal;
                }
            }
        }
        
        // Helper function to format counter value and add to counters array
        $addCounter = function($counterType, $currency, $taxID, $taxPercent, $value, $moneyType = null) use (&$counters) {
            // Documentation: "Zero value counters must not be submitted to FDMS"
            if (abs($value) < 0.01) {
                return; // Skip zero-value counters
            }
            
            // Format value as decimal string with exactly 2 decimal places (required by ZIMRA decimal(19,2))
            $formattedValue = number_format($value, 2, '.', '');
            
            // Map currency code for ZIMRA (ZWL -> ZWG)
            $currencyForZimra = mapCurrencyCodeForZimra($currency);
            
            $counter = [
                'fiscalCounterType' => $counterType,
                'fiscalCounterCurrency' => $currencyForZimra,
                'fiscalCounterValue' => $formattedValue
            ];
            
            // Add tax-related fields for "byTax" counters
            if (strpos($counterType, 'ByTax') !== false) {
                // CRITICAL: Always set taxID (use 0 for exempt) to ensure stable sorting
                $counter['fiscalCounterTaxID'] = isset($taxID) ? max(0, intval($taxID)) : 0;
                // CRITICAL: For exempt (taxPercentKey === 'exempt'), taxPercent must be null
                // Documentation: "In case of exempt, this field must not be provided"
                if ($taxPercent !== null && $taxPercent !== 'exempt') {
                    $counter['fiscalCounterTaxPercent'] = floatval($taxPercent);
                }
            }
            
            // Add money type for BalanceByMoneyType
            if ($counterType === 'BalanceByMoneyType' && $moneyType) {
                $counter['fiscalCounterMoneyType'] = $moneyType;
            }
            
            $counters[] = $counter;
        };
        
        // SaleByTax counters (FiscalInvoice only, does NOT include credit/debit notes)
        foreach ($salesByTax as $currency => $taxIDs) {
            foreach ($taxIDs as $taxID => $taxPercents) {
                foreach ($taxPercents as $taxPercentKey => $value) {
                    $taxPercent = ($taxPercentKey === 'exempt') ? null : floatval($taxPercentKey);
                    $addCounter('SaleByTax', $currency, $taxID, $taxPercent, $value);
                }
            }
        }
        
        // SaleTaxByTax counters (FiscalInvoice only)
        foreach ($salesTaxByTax as $currency => $taxIDs) {
            foreach ($taxIDs as $taxID => $taxPercents) {
                foreach ($taxPercents as $taxPercentKey => $value) {
                    $taxPercent = ($taxPercentKey === 'exempt') ? null : floatval($taxPercentKey);
                    $addCounter('SaleTaxByTax', $currency, $taxID, $taxPercent, $value);
                }
            }
        }
        
        // CreditNoteByTax counters (CreditNote only, with negative values)
        foreach ($creditNoteByTax as $currency => $taxIDs) {
            foreach ($taxIDs as $taxID => $taxPercents) {
                foreach ($taxPercents as $taxPercentKey => $value) {
                    // Value is already negative from processing above
                    $taxPercent = ($taxPercentKey === 'exempt') ? null : floatval($taxPercentKey);
                    $addCounter('CreditNoteByTax', $currency, $taxID, $taxPercent, $value);
                }
            }
        }
        
        // CreditNoteTaxByTax counters (CreditNote only, with negative values)
        foreach ($creditNoteTaxByTax as $currency => $taxIDs) {
            foreach ($taxIDs as $taxID => $taxPercents) {
                foreach ($taxPercents as $taxPercentKey => $value) {
                    // Value is already negative from processing above
                    $taxPercent = ($taxPercentKey === 'exempt') ? null : floatval($taxPercentKey);
                    $addCounter('CreditNoteTaxByTax', $currency, $taxID, $taxPercent, $value);
                }
            }
        }
        
        // DebitNoteByTax counters (DebitNote only)
        foreach ($debitNoteByTax as $currency => $taxIDs) {
            foreach ($taxIDs as $taxID => $taxPercents) {
                foreach ($taxPercents as $taxPercentKey => $value) {
                    $taxPercent = ($taxPercentKey === 'exempt') ? null : floatval($taxPercentKey);
                    $addCounter('DebitNoteByTax', $currency, $taxID, $taxPercent, $value);
                }
            }
        }
        
        // DebitNoteTaxByTax counters (DebitNote only)
        foreach ($debitNoteTaxByTax as $currency => $taxIDs) {
            foreach ($taxIDs as $taxID => $taxPercents) {
                foreach ($taxPercents as $taxPercentKey => $value) {
                    $taxPercent = ($taxPercentKey === 'exempt') ? null : floatval($taxPercentKey);
                    $addCounter('DebitNoteTaxByTax', $currency, $taxID, $taxPercent, $value);
                }
            }
        }
        
        // BalanceByMoneyType counters (all receipt types, credit notes have negative values)
        foreach ($balanceByMoneyType as $currency => $moneyTypes) {
            foreach ($moneyTypes as $moneyType => $value) {
                // Documentation: "Zero value counters must not be submitted to FDMS"
                // But for BalanceByMoneyType, we might have negative values (credit notes)
                // So we check for non-zero (not just > 0)
                if (abs($value) >= 0.01) {
                    $formattedValue = number_format($value, 2, '.', '');
                    $currencyForZimra = mapCurrencyCodeForZimra($currency);
                    
                    $counters[] = [
                        'fiscalCounterType' => 'BalanceByMoneyType',
                        'fiscalCounterCurrency' => $currencyForZimra,
                        'fiscalCounterMoneyType' => $moneyType,
                        'fiscalCounterValue' => $formattedValue
                    ];
                }
            }
        }
        
        error_log("CALCULATED COUNTERS: " . count($counters) . " counters for fiscal day $fiscalDayNo");
        
        return $counters;
    }
    
    /**
     * Validate certificate for fiscal operation
     * Ensures certificate and private key are valid and match
     * 
     * @param string $certificatePem Certificate in PEM format
     * @param string $privateKeyPem Private key in PEM format
     * @param string $operation Operation name for logging (e.g., 'receipt_submission', 'fiscal_day_close')
     * @throws Exception If certificate validation fails
     */
    private function validateCertificateForOperation($certificatePem, $privateKeyPem, $operation) {
        $logPrefix = strtoupper($operation);
        
        // Get certificate fingerprint for consistency checking
        $certFingerprint = null;
        $certSubject = null;
        if ($certificatePem) {
            $cert = openssl_x509_read($certificatePem);
            if ($cert) {
                $certDetails = openssl_x509_parse($cert);
                $certFingerprint = openssl_x509_fingerprint($cert, 'sha256', false);
                $certSubject = $certDetails['subject']['CN'] ?? 'N/A';
            }
        }
        
        // Verify private key is valid
        $testKey = openssl_pkey_get_private($privateKeyPem);
        if (!$testKey) {
            $error = openssl_error_string();
            error_log("$logPrefix: ERROR - Private key is invalid: $error");
            throw new Exception("Invalid private key for device {$this->deviceId} during $operation. Please re-register the device.");
        }
        
        // Verify certificate matches private key
        if ($certificatePem) {
            $cert = openssl_x509_read($certificatePem);
            if ($cert) {
                $certPubKey = openssl_pkey_get_public($certificatePem);
                $keyDetails = openssl_pkey_get_details($testKey);
                $certDetails = openssl_pkey_get_details($certPubKey);
                
                // Compare public keys (they should match)
                if ($keyDetails && $certDetails && isset($keyDetails['key']) && isset($certDetails['key'])) {
                    if ($keyDetails['key'] !== $certDetails['key']) {
                        error_log("$logPrefix: ERROR - Certificate public key doesn't match private key!");
                        error_log("$logPrefix: Certificate Subject: $certSubject");
                        error_log("$logPrefix: Certificate Fingerprint: $certFingerprint");
                        throw new Exception("Certificate and private key do not match for device {$this->deviceId} during $operation. Please re-register the device.");
                    }
                }
            }
        }
        
        // Check certificate expiration
        if ($certificatePem) {
            $cert = openssl_x509_read($certificatePem);
            if ($cert) {
                $certDetails = openssl_x509_parse($cert);
                if (isset($certDetails['validTo_time_t'])) {
                    $expiryTime = $certDetails['validTo_time_t'];
                    $now = time();
                    $daysUntilExpiry = floor(($expiryTime - $now) / 86400);
                    
                    if ($expiryTime < $now) {
                        error_log("$logPrefix: ERROR - Certificate has expired!");
                        throw new Exception("Certificate for device {$this->deviceId} has expired. Please re-register the device.");
                    } elseif ($daysUntilExpiry < 30) {
                        error_log("$logPrefix: WARNING - Certificate expires in $daysUntilExpiry days. Consider renewing soon.");
                    }
                }
            }
        }
        
        // Log successful validation
        error_log("$logPrefix: Certificate validated successfully");
        error_log("$logPrefix: Certificate Subject: $certSubject");
        error_log("$logPrefix: Certificate Fingerprint: $certFingerprint");
        
        // Store fingerprint in class for consistency checking
        if (!isset($this->certificateFingerprint)) {
            $this->certificateFingerprint = $certFingerprint;
        } elseif ($this->certificateFingerprint !== $certFingerprint) {
            error_log("$logPrefix: WARNING - Certificate fingerprint changed! Previous: {$this->certificateFingerprint}, Current: $certFingerprint");
            error_log("$logPrefix: This may indicate certificate was updated. Updating stored fingerprint.");
            $this->certificateFingerprint = $certFingerprint;
        }
    }
    
    /**
     * Extract certificate expiry date
     */
    private function extractCertificateExpiry($certificatePem) {
        $cert = openssl_x509_read($certificatePem);
        if (!$cert) {
            return null;
        }
        
        $details = openssl_x509_parse($cert);
        if (isset($details['validTo_time_t'])) {
            return date('Y-m-d H:i:s', $details['validTo_time_t']);
        }
        
        return null;
    }
}


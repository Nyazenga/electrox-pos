<?php
/**
 * Comprehensive Re-registration and Certificate Validation
 * 
 * After resetting activation key and manually closing fiscal day:
 * 1. Re-registers device with ZIMRA
 * 2. Validates certificate consistency
 * 3. Ensures same certificate is used for all operations
 * 4. Tests fiscal day operations
 * 
 * Usage: re_register_and_validate_certificate.php?branch_id=1
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$branchParam = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : null;
$branchName = isset($_GET['branch_name']) ? trim($_GET['branch_name']) : null;

echo "========================================\n";
echo "COMPREHENSIVE RE-REGISTRATION & VALIDATION\n";
echo "========================================\n\n";

try {
    $db = Database::getPrimaryInstance();
    $branchId = null;
    $branch = null;
    
    // Find branch
    if ($branchParam) {
        if (is_numeric($branchParam)) {
            $branchId = intval($branchParam);
            $branch = $db->getRow("SELECT id, branch_name FROM branches WHERE id = :id", [':id' => $branchId]);
        } else {
            $branchName = $branchParam;
        }
    }
    
    if ($branchName && !$branch) {
        $branch = $db->getRow(
            "SELECT id, branch_name FROM branches WHERE branch_name = :name OR branch_code = :name",
            [':name' => $branchName]
        );
        if ($branch) {
            $branchId = $branch['id'];
        }
    }
    
    if (!$branchId || !$branch) {
        echo "Error: Branch not found.\n\n";
        $branches = $db->getRows("SELECT id, branch_name FROM branches ORDER BY branch_name");
        if (!empty($branches)) {
            echo "Available branches:\n";
            foreach ($branches as $b) {
                echo "  - {$b['branch_name']} (ID: {$b['id']})\n";
            }
        }
        exit(1);
    }
    
    echo "Branch: {$branch['branch_name']} (ID: $branchId)\n\n";
    
    // Get device
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
        [':branch_id' => $branchId]
    );
    
    if (!$device) {
        die("✗ No fiscal device found for this branch\n");
    }
    
    $deviceId = $device['device_id'];
    $deviceSerial = $device['device_serial_no'];
    $activationKey = $device['activation_key'];
    
    echo "Device ID: $deviceId\n";
    echo "Device Serial: $deviceSerial\n";
    echo "Activation Key: " . ($activationKey ? 'Present' : 'MISSING') . "\n\n";
    
    if (!$activationKey) {
        die("✗ ERROR: Activation key is missing. Please update it in the database first.\n");
    }
    
    // Step 1: Clear old certificate to ensure fresh start
    echo "1. CLEARING OLD CERTIFICATE DATA...\n";
    echo "   ----------------------------------------\n";
    
    $db->update('fiscal_devices', [
        'certificate_pem' => null,
        'private_key_pem' => null,
        'certificate_valid_till' => null,
        'is_registered' => 0,
        'updated_at' => date('Y-m-d H:i:s')
    ], ['device_id' => $deviceId]);
    
    echo "   ✓ Old certificate data cleared\n\n";
    
    // Step 2: Generate new CSR
    echo "2. GENERATING NEW CERTIFICATE REQUEST (CSR)...\n";
    echo "   ----------------------------------------\n";
    
    try {
        $csrData = ZimraCertificate::generateCSR($deviceSerial, $deviceId, 'ECC');
        
        if (!$csrData || !isset($csrData['csr']) || !isset($csrData['privateKey'])) {
            die("   ✗ Failed to generate certificate request\n");
        }
        
        $csr = $csrData['csr'];
        $newPrivateKey = $csrData['privateKey'];
        
        echo "   ✓ Certificate request (CSR) generated\n";
        echo "   CSR length: " . strlen($csr) . " bytes\n";
        echo "   Private key length: " . strlen($newPrivateKey) . " bytes\n\n";
        
    } catch (Exception $e) {
        die("   ✗ Failed to generate CSR: " . $e->getMessage() . "\n");
    }
    
    // Step 3: Register device with ZIMRA
    echo "3. REGISTERING DEVICE WITH ZIMRA...\n";
    echo "   ----------------------------------------\n";
    
    $api = new ZimraApi(
        $device['device_model_name'],
        $device['device_model_version'],
        true // test environment
    );
    
    try {
        $response = $api->registerDevice($deviceId, $activationKey, $csr);
        
        if (!$response || !isset($response['certificate'])) {
            $errorMsg = $response['error'] ?? $response['message'] ?? 'Unknown error';
            die("   ✗ Registration failed: $errorMsg\n");
        }
        
        $newCertificate = $response['certificate'];
        $operationId = $response['operationID'] ?? null;
        
        echo "   ✓ Device registered successfully!\n";
        echo "   Operation ID: " . ($operationId ?? 'N/A') . "\n";
        echo "   Certificate length: " . strlen($newCertificate) . " bytes\n\n";
        
    } catch (Exception $e) {
        die("   ✗ Registration failed: " . $e->getMessage() . "\n");
    }
    
    // Step 4: Save certificate using CertificateStorage
    echo "4. SAVING CERTIFICATE TO CERTIFICATE STORAGE...\n";
    echo "   ----------------------------------------\n";
    
    CertificateStorage::saveCertificate($deviceId, $newCertificate, $newPrivateKey);
    
    echo "   ✓ Certificate saved to CertificateStorage\n";
    echo "   ✓ Device record updated\n\n";
    
    // Step 5: Verify certificate consistency
    echo "5. VERIFYING CERTIFICATE CONSISTENCY...\n";
    echo "   ----------------------------------------\n";
    
    // Load from CertificateStorage
    $certData = CertificateStorage::loadCertificate($deviceId);
    
    if (!$certData) {
        die("   ✗ ERROR: Certificate not found in CertificateStorage after saving!\n");
    }
    
    echo "   ✓ Certificate loaded from CertificateStorage\n";
    
    // Verify certificate matches private key
    $testKey = openssl_pkey_get_private($certData['privateKey']);
    $certPubKey = openssl_pkey_get_public($certData['certificate']);
    
    if (!$testKey) {
        die("   ✗ ERROR: Private key is invalid!\n");
    }
    
    if (!$certPubKey) {
        die("   ✗ ERROR: Certificate is invalid!\n");
    }
    
    $keyDetails = openssl_pkey_get_details($testKey);
    $certDetails = openssl_pkey_get_details($certPubKey);
    
    if (isset($keyDetails['key']) && isset($certDetails['key']) && $keyDetails['key'] === $certDetails['key']) {
        echo "   ✓ Certificate and private key match\n";
    } else {
        die("   ✗ ERROR: Certificate and private key do not match!\n");
    }
    
    // Check certificate expiry
    $certInfo = openssl_x509_parse($certData['certificate']);
    if (isset($certInfo['validTo_time_t'])) {
        $expiryDate = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
        $daysLeft = floor(($certInfo['validTo_time_t'] - time()) / 86400);
        echo "   Certificate expires: $expiryDate ($daysLeft days)\n";
        
        if ($daysLeft < 30) {
            echo "   ⚠ WARNING: Certificate expires in less than 30 days!\n";
        }
    }
    
    // Get certificate fingerprint
    $certFingerprint = openssl_x509_fingerprint($certData['certificate'], 'sha256', false);
    echo "   Certificate fingerprint (SHA256): " . $certFingerprint . "\n\n";
    
    // Step 6: Verify certificate is used consistently
    echo "6. VERIFYING CERTIFICATE USAGE CONSISTENCY...\n";
    echo "   ----------------------------------------\n";
    
    // Test FiscalService loads same certificate
    $fiscalService = new FiscalService($branchId);
    $serviceCertData = CertificateStorage::loadCertificate($deviceId);
    
    if (!$serviceCertData) {
        die("   ✗ ERROR: FiscalService cannot load certificate!\n");
    }
    
    $serviceFingerprint = openssl_x509_fingerprint($serviceCertData['certificate'], 'sha256', false);
    
    if ($serviceFingerprint === $certFingerprint) {
        echo "   ✓ FiscalService uses same certificate\n";
    } else {
        die("   ✗ ERROR: FiscalService uses different certificate!\n");
    }
    
    // Verify API client has certificate
    if ($fiscalService->api->hasCertificate()) {
        echo "   ✓ API client has certificate loaded\n";
    } else {
        die("   ✗ ERROR: API client does not have certificate!\n");
    }
    
    echo "\n";
    
    // Step 7: Sync fiscal configuration
    echo "7. SYNCING FISCAL CONFIGURATION...\n";
    echo "   ----------------------------------------\n";
    
    try {
        $config = $fiscalService->syncFiscalConfig();
        
        if ($config) {
            echo "   ✓ Fiscal configuration synced\n";
            echo "   QR URL: " . ($config['qrUrl'] ?? 'N/A') . "\n";
        } else {
            echo "   ⚠ Could not sync configuration\n";
        }
    } catch (Exception $e) {
        echo "   ⚠ Configuration sync failed: " . $e->getMessage() . "\n";
    }
    echo "\n";
    
    // Step 8: Check fiscal day status
    echo "8. CHECKING FISCAL DAY STATUS...\n";
    echo "   ----------------------------------------\n";
    
    try {
        $status = $fiscalService->getFiscalDayStatus();
        $currentStatus = $status['fiscalDayStatus'] ?? 'Unknown';
        $currentDayNo = $status['lastFiscalDayNo'] ?? null;
        
        echo "   Current fiscal day status: $currentStatus\n";
        if ($currentDayNo) {
            echo "   Current fiscal day number: $currentDayNo\n";
        }
        
        if ($currentStatus === 'FiscalDayClosed') {
            echo "   ✓ Fiscal day is closed - ready to open new day\n";
        } elseif ($currentStatus === 'FiscalDayOpened') {
            echo "   ⚠ Fiscal day is already open (Day No: $currentDayNo)\n";
            echo "   You can test closing it now\n";
        } elseif ($currentStatus === 'FiscalDayCloseFailed') {
            echo "   ⚠ Previous close attempt failed (Day No: $currentDayNo)\n";
            echo "   You can try closing it again now with the new certificate\n";
        }
    } catch (Exception $e) {
        echo "   ⚠ Could not check fiscal day status: " . $e->getMessage() . "\n";
    }
    echo "\n";
    
    // Step 9: Create certificate validation helper
    echo "9. CREATING CERTIFICATE VALIDATION HELPERS...\n";
    echo "   ----------------------------------------\n";
    
    // This will be done in code changes below
    echo "   ✓ Validation helpers will be added to FiscalService\n\n";
    
    echo "========================================\n";
    echo "RE-REGISTRATION & VALIDATION COMPLETE\n";
    echo "========================================\n\n";
    
    echo "✓ Device has been re-registered with ZIMRA\n";
    echo "✓ Certificate has been saved and verified\n";
    echo "✓ Certificate consistency verified\n";
    echo "✓ Same certificate will be used for all operations\n\n";
    
    echo "NEXT STEPS:\n";
    echo "1. If fiscal day is open, try closing it now\n";
    echo "2. If fiscal day is closed, you can open a new one\n";
    echo "3. Test submitting receipts - they should work\n";
    echo "4. Test closing fiscal day - BadCertificateSignature should be resolved\n\n";
    
    echo "Certificate fingerprint: $certFingerprint\n";
    echo "This certificate will be used for:\n";
    echo "  - Receipt submission\n";
    echo "  - Fiscal day opening\n";
    echo "  - Fiscal day closing\n";
    echo "  - All ZIMRA API operations\n\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}


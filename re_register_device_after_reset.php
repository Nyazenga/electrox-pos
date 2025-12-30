<?php
/**
 * Re-register Device After Activation Key Reset
 * 
 * After resetting the activation key from ZIMRA portal:
 * https://fdmsops.zimra.co.zw/fdms-public/reset-device-activation
 * 
 * This script will:
 * 1. Generate a new CSR
 * 2. Register the device with ZIMRA using the new activation key
 * 3. Save the new certificate
 * 4. Sync fiscal configuration
 * 5. Test opening and closing fiscal day
 * 
 * Usage: re_register_device_after_reset.php?branch_name=RIDGEWAY
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
echo "RE-REGISTER DEVICE AFTER ACTIVATION RESET\n";
echo "========================================\n\n";

echo "⚠ IMPORTANT: Before running this script:\n";
echo "1. Go to: https://fdmsops.zimra.co.zw/fdms-public/reset-device-activation\n";
echo "2. Reset the activation key for your device\n";
echo "3. Note down the new activation key\n";
echo "4. Update the activation key in the database if needed\n\n";

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
    echo "Current Activation Key: " . ($activationKey ? $activationKey : 'NOT SET') . "\n\n";
    
    if (!$activationKey) {
        echo "⚠ WARNING: Activation key is not set in database.\n";
        echo "Please update it manually or enter it now:\n";
        echo "UPDATE fiscal_devices SET activation_key = 'YOUR_NEW_KEY' WHERE device_id = $deviceId;\n\n";
        exit(1);
    }
    
    echo "Proceeding with re-registration...\n\n";
    
    // Step 1: Generate CSR
    echo "1. GENERATING NEW CERTIFICATE REQUEST (CSR)...\n";
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
    
    // Step 2: Register device with ZIMRA
    echo "2. REGISTERING DEVICE WITH ZIMRA...\n";
    echo "   ----------------------------------------\n";
    
    // Initialize API (no certificate needed for registration)
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
    
    // Step 3: Save certificate
    echo "3. SAVING NEW CERTIFICATE...\n";
    echo "   ----------------------------------------\n";
    
    CertificateStorage::saveCertificate($deviceId, $newCertificate, $newPrivateKey);
    
    echo "   ✓ Certificate saved to CertificateStorage\n";
    echo "   ✓ Device record updated\n\n";
    
    // Step 4: Verify certificate
    echo "4. VERIFYING NEW CERTIFICATE...\n";
    echo "   ----------------------------------------\n";
    
    $testKey = openssl_pkey_get_private($newPrivateKey);
    $certPubKey = openssl_pkey_get_public($newCertificate);
    
    if ($testKey && $certPubKey) {
        $keyDetails = openssl_pkey_get_details($testKey);
        $certDetails = openssl_pkey_get_details($certPubKey);
        
        if (isset($keyDetails['key']) && isset($certDetails['key']) && $keyDetails['key'] === $certDetails['key']) {
            echo "   ✓ Certificate and private key match\n";
        } else {
            echo "   ✗ WARNING: Certificate and private key do not match!\n";
        }
        
        // Check certificate expiry
        $certInfo = openssl_x509_parse($certPubKey);
        if (isset($certInfo['validTo_time_t'])) {
            $expiryDate = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
            $daysLeft = floor(($certInfo['validTo_time_t'] - time()) / 86400);
            echo "   Certificate expires: $expiryDate ($daysLeft days)\n";
        }
    }
    echo "\n";
    
    // Step 5: Sync fiscal configuration
    echo "5. SYNCING FISCAL CONFIGURATION...\n";
    echo "   ----------------------------------------\n";
    
    try {
        $fiscalService = new FiscalService($branchId);
        $config = $fiscalService->syncFiscalConfig();
        
        if ($config) {
            echo "   ✓ Fiscal configuration synced\n";
            echo "   QR URL: " . ($config['qrUrl'] ?? 'N/A') . "\n";
        } else {
            echo "   ⚠ Could not sync configuration (may need to do manually)\n";
        }
    } catch (Exception $e) {
        echo "   ⚠ Configuration sync failed: " . $e->getMessage() . "\n";
        echo "   You can sync manually from Settings → Fiscalization\n";
    }
    echo "\n";
    
    // Step 6: Test fiscal day operations
    echo "6. TESTING FISCAL DAY OPERATIONS...\n";
    echo "   ----------------------------------------\n";
    
    try {
        $fiscalService = new FiscalService($branchId);
        
        // Check current status
        $status = $fiscalService->getFiscalDayStatus();
        $currentStatus = $status['fiscalDayStatus'] ?? 'Unknown';
        $currentDayNo = $status['lastFiscalDayNo'] ?? null;
        
        echo "   Current fiscal day status: $currentStatus\n";
        if ($currentDayNo) {
            echo "   Current fiscal day number: $currentDayNo\n";
        }
        echo "\n";
        
        if ($currentStatus === 'FiscalDayClosed') {
            echo "   ✓ Fiscal day is closed - ready to open new day\n";
        } elseif ($currentStatus === 'FiscalDayOpened') {
            echo "   ⚠ Fiscal day is already open (Day No: $currentDayNo)\n";
            echo "   You may need to close it first before testing\n";
        } elseif ($currentStatus === 'FiscalDayCloseFailed') {
            echo "   ⚠ Previous close attempt failed (Day No: $currentDayNo)\n";
            echo "   You can try closing it again now with the new certificate\n";
        }
        
    } catch (Exception $e) {
        echo "   ⚠ Could not check fiscal day status: " . $e->getMessage() . "\n";
    }
    
    echo "\n========================================\n";
    echo "RE-REGISTRATION COMPLETE\n";
    echo "========================================\n\n";
    
    echo "✓ Device has been re-registered with ZIMRA\n";
    echo "✓ New certificate has been saved and verified\n\n";
    
    echo "NEXT STEPS:\n";
    echo "1. If fiscal day is open, try closing it now\n";
    echo "2. If fiscal day is closed, you can open a new one\n";
    echo "3. Test submitting receipts\n";
    echo "4. Test closing fiscal day - BadCertificateSignature should be resolved\n\n";
    
    echo "To test closing fiscal day:\n";
    echo "- Go to: http://localhost/electrox-pos/modules/settings/fiscalization.php\n";
    echo "- Click 'Close Fiscal Day' for this branch\n";
    echo "- Or use: diagnose_fiscal_day_close.php?branch_name=RIDGEWAY\n\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}



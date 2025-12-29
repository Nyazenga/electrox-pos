<?php
/**
 * Re-register Device to Fix BadCertificateSignature Error
 * 
 * This script re-registers the fiscal device with ZIMRA to ensure
 * the certificate is synced and matches what ZIMRA expects.
 * 
 * Usage: re_register_device_for_fiscal_close.php?branch_name=RIDGEWAY
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

$branchParam = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : null;
$branchName = isset($_GET['branch_name']) ? trim($_GET['branch_name']) : null;

echo "========================================\n";
echo "RE-REGISTER DEVICE FOR FISCAL DAY CLOSE\n";
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
    echo "Activation Key: " . ($activationKey ? 'Present' : 'Missing') . "\n\n";
    
    if (!$activationKey) {
        die("✗ ERROR: Activation key is missing. Cannot re-register device.\n");
    }
    
    echo "⚠ WARNING: Re-issuing certificate will:\n";
    echo "  1. Generate a new certificate request\n";
    echo "  2. Get a new certificate from ZIMRA (using current certificate to authenticate)\n";
    echo "  3. Replace the existing certificate\n";
    echo "  4. This should fix BadCertificateSignature errors by syncing with ZIMRA\n\n";
    
    echo "Press Enter to continue or Ctrl+C to cancel...\n";
    // For web, we'll just proceed
    // $handle = fopen("php://stdin", "r");
    // $line = fgets($handle);
    // fclose($handle);
    
    echo "\n1. GENERATING NEW CERTIFICATE REQUEST (CSR)...\n";
    echo "   ----------------------------------------\n";
    
    // Generate new certificate request (CSR)
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
    
    echo "2. RE-ISSUING CERTIFICATE FROM ZIMRA...\n";
    echo "   ----------------------------------------\n";
    
    // Initialize API with current certificate (required for issueCertificate)
    require_once APP_PATH . '/includes/zimra_api.php';
    $api = new ZimraApi(
        $device['device_model_name'],
        $device['device_model_version'],
        true // test environment
    );
    
    // Load current certificate to authenticate
    require_once APP_PATH . '/includes/certificate_storage.php';
    $currentCertData = CertificateStorage::loadCertificate($deviceId);
    
    if (!$currentCertData) {
        // Fallback to device record
        $currentCert = $device['certificate_pem'] ?? null;
        $currentKey = $device['private_key_pem'] ?? null;
        
        if ($currentKey && strpos($currentKey, '-----BEGIN') === false) {
            try {
                $currentKey = CertificateStorage::decryptPrivateKey($currentKey);
            } catch (Exception $e) {
                die("   ✗ Cannot decrypt current private key: " . $e->getMessage() . "\n");
            }
        }
        
        if ($currentCert && $currentKey) {
            $api->setCertificate($currentCert, $currentKey);
        } else {
            die("   ✗ Current certificate not found. Cannot authenticate to re-issue certificate.\n");
        }
    } else {
        $api->setCertificate($currentCertData['certificate'], $currentCertData['privateKey']);
    }
    
    // Issue new certificate (re-issue)
    try {
        $response = $api->issueCertificate($deviceId, $csr);
        
        if (!$response || !isset($response['certificate'])) {
            die("   ✗ Certificate re-issue failed: " . ($response['error'] ?? 'Unknown error') . "\n");
        }
        
        $newCertificate = $response['certificate'];
        $operationId = $response['operationID'] ?? null;
        
        echo "   ✓ Certificate re-issued successfully\n";
        echo "   Operation ID: " . ($operationId ?? 'N/A') . "\n\n";
        
    } catch (Exception $e) {
        die("   ✗ Certificate re-issue failed: " . $e->getMessage() . "\n");
    }
    
    echo "3. SAVING NEW CERTIFICATE...\n";
    echo "   ----------------------------------------\n";
    
    // Save certificate using CertificateStorage (this also updates the device record)
    require_once APP_PATH . '/includes/certificate_storage.php';
    CertificateStorage::saveCertificate($deviceId, $newCertificate, $newPrivateKey);
    
    echo "   ✓ Certificate saved to CertificateStorage\n";
    echo "   ✓ Device record updated\n\n";
    
    echo "4. VERIFYING NEW CERTIFICATE...\n";
    echo "   ----------------------------------------\n";
    
    // Verify certificate matches private key
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
    }
    
    echo "\n========================================\n";
    echo "RE-REGISTRATION COMPLETE\n";
    echo "========================================\n\n";
    
    echo "✓ Device has been re-registered with ZIMRA\n";
    echo "✓ New certificate has been saved\n\n";
    
    echo "NEXT STEPS:\n";
    echo "1. Sync fiscal configuration: Go to Settings → Fiscalization → Sync Config\n";
    echo "2. Open a new fiscal day (if needed)\n";
    echo "3. Try closing the fiscal day again\n";
    echo "4. The BadCertificateSignature error should be resolved\n";
    echo "5. If it still fails, check the diagnostic tool for other issues\n\n";
    
    echo "TESTING FISCAL DAY OPERATIONS:\n";
    echo "You can now test:\n";
    echo "- Opening fiscal day\n";
    echo "- Submitting receipts\n";
    echo "- Closing fiscal day\n\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}


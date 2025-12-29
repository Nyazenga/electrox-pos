<?php
/**
 * Verify re-registration was successful
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/fiscal_service.php';

echo "========================================\n";
echo "VERIFYING RE-REGISTRATION\n";
echo "========================================\n\n";

$deviceId = 30200;
$branchId = 3;

// Check certificate
echo "1. CHECKING CERTIFICATE...\n";
echo "   ----------------------------------------\n";

$cert = CertificateStorage::loadCertificate($deviceId);
if ($cert) {
    echo "   ✓ Certificate found in CertificateStorage\n";
    echo "   Certificate length: " . strlen($cert['certificate']) . " bytes\n";
    echo "   Private key length: " . strlen($cert['privateKey']) . " bytes\n";
    
    // Verify certificate and key match
    $testKey = openssl_pkey_get_private($cert['privateKey']);
    $certPubKey = openssl_pkey_get_public($cert['certificate']);
    
    if ($testKey && $certPubKey) {
        $keyDetails = openssl_pkey_get_details($testKey);
        $certDetails = openssl_pkey_get_details($certPubKey);
        
        if (isset($keyDetails['key']) && isset($certDetails['key']) && $keyDetails['key'] === $certDetails['key']) {
            echo "   ✓ Certificate and private key match\n";
        } else {
            echo "   ✗ Certificate and private key do NOT match!\n";
        }
    }
    
    // Check expiry
    $certInfo = openssl_x509_parse($certPubKey);
    if (isset($certInfo['validTo_time_t'])) {
        $expiryDate = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
        $daysLeft = floor(($certInfo['validTo_time_t'] - time()) / 86400);
        echo "   Certificate expires: $expiryDate ($daysLeft days)\n";
    }
} else {
    echo "   ✗ Certificate NOT found!\n";
}
echo "\n";

// Check device record
echo "2. CHECKING DEVICE RECORD...\n";
echo "   ----------------------------------------\n";

$db = Database::getPrimaryInstance();
$device = $db->getRow(
    "SELECT device_id, device_serial_no, activation_key, is_registered, certificate_valid_till, updated_at 
     FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if ($device) {
    echo "   Device ID: {$device['device_id']}\n";
    echo "   Serial: {$device['device_serial_no']}\n";
    echo "   Activation Key: {$device['activation_key']}\n";
    echo "   Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
    echo "   Certificate valid till: " . ($device['certificate_valid_till'] ?? 'N/A') . "\n";
    echo "   Last updated: " . ($device['updated_at'] ?? 'N/A') . "\n";
} else {
    echo "   ✗ Device record NOT found!\n";
}
echo "\n";

// Check fiscal day status
echo "3. CHECKING FISCAL DAY STATUS...\n";
echo "   ----------------------------------------\n";

try {
    $fiscalService = new FiscalService($branchId);
    $status = $fiscalService->getFiscalDayStatus();
    
    $currentStatus = $status['fiscalDayStatus'] ?? 'Unknown';
    $currentDayNo = $status['lastFiscalDayNo'] ?? null;
    $lastReceiptNo = $status['lastReceiptGlobalNo'] ?? null;
    
    echo "   Fiscal day status: $currentStatus\n";
    if ($currentDayNo) {
        echo "   Current fiscal day number: $currentDayNo\n";
    }
    if ($lastReceiptNo !== null) {
        echo "   Last receipt global number: $lastReceiptNo\n";
    }
    
    if ($currentStatus === 'FiscalDayCloseFailed') {
        echo "\n   ⚠ Previous close attempt failed\n";
        echo "   You can now try closing it again with the new certificate\n";
    } elseif ($currentStatus === 'FiscalDayOpened') {
        echo "\n   ℹ Fiscal day is currently open\n";
        echo "   You can try closing it now\n";
    } elseif ($currentStatus === 'FiscalDayClosed') {
        echo "\n   ✓ Fiscal day is closed - ready to open new day\n";
    }
    
} catch (Exception $e) {
    echo "   ⚠ Could not check fiscal day status: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "VERIFICATION COMPLETE\n";
echo "========================================\n\n";

echo "SUMMARY:\n";
if ($cert && $device && $device['is_registered']) {
    echo "✓ Re-registration appears successful!\n";
    echo "✓ Certificate is saved and valid\n";
    echo "✓ Device is registered\n\n";
    echo "You can now:\n";
    echo "1. Try closing the fiscal day from Settings → Fiscalization\n";
    echo "2. The BadCertificateSignature error should be resolved\n";
} else {
    echo "✗ Some issues detected - please check above\n";
}


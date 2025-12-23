<?php
/**
 * Try to register device 30199
 * This will work if device is not yet registered, or if ZIMRA allows re-registration
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . DIRECTORY_SEPARATOR . 'config.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'zimra_api.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'zimra_certificate.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'certificate_storage.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db.php';

$deviceId = 30199;
$activationKey = '00544726'; // Try exact format
$deviceSerialNo = 'electrox-1';

echo "=== Attempting Device Registration ===\n\n";
echo "Device ID: $deviceId\n";
echo "Activation Key: $activationKey\n";
echo "Serial No: $deviceSerialNo\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $api = new ZimraApi('Server', 'v1', true);
    
    // Generate CSR
    echo "Step 1: Generating CSR...\n";
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    echo "  [OK] CSR generated\n";
    echo "  CSR length: " . strlen($csrData['csr']) . " bytes\n";
    echo "  Private key length: " . strlen($csrData['privateKey']) . " bytes\n\n";
    
    // Try registerDevice
    echo "Step 2: Calling registerDevice endpoint...\n";
    echo "  Endpoint: POST /Public/v1/$deviceId/RegisterDevice\n";
    echo "  This endpoint does NOT require certificate authentication\n";
    echo "  If device is already registered, this may fail\n\n";
    
    $response = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    
    echo "Step 3: Response received!\n";
    echo "  [SUCCESS] Device registered or certificate issued!\n\n";
    
    echo "=== ZIMRA RESPONSE ===\n";
    echo json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($response['certificate'])) {
        echo "Step 4: Saving certificate...\n";
        CertificateStorage::saveCertificate($deviceId, $response['certificate'], $csrData['privateKey']);
        echo "  [OK] Certificate saved to database\n\n";
        
        echo "=== Registration Complete ===\n";
        echo "You can now use this device for API calls.\n";
    }
    
} catch (Exception $e) {
    echo "\n[ERROR]: " . $e->getMessage() . "\n\n";
    
    // Parse error details
    $errorMsg = $e->getMessage();
    
    if (strpos($errorMsg, 'DEV02') !== false) {
        echo "ANALYSIS:\n";
        echo "---------\n";
        echo "Error Code: DEV02 - Activation key is incorrect\n\n";
        echo "POSSIBLE CAUSES:\n";
        echo "1. The activation key '$activationKey' is incorrect\n";
        echo "2. The device is already registered and activation key cannot be reused\n";
        echo "3. The activation key format is wrong (should be 8 characters, case-insensitive)\n\n";
        echo "SOLUTIONS:\n";
        echo "---------\n";
        echo "If device is already registered but certificate is lost:\n";
        echo "- Contact ZIMRA support to reset device registration\n";
        echo "- Or request a new activation key from ZIMRA\n";
        echo "- Or if you have access to ZIMRA portal, you may be able to reset it there\n\n";
        echo "If activation key is wrong:\n";
        echo "- Verify the activation key from ZIMRA portal or documentation\n";
        echo "- Ensure it's exactly 8 characters (case-insensitive)\n";
    } elseif (strpos($errorMsg, 'DEV03') !== false) {
        echo "Error Code: DEV03 - Certificate request is invalid\n";
        echo "Check the CSR format and requirements.\n";
    } else {
        echo "Full error response:\n";
        echo $errorMsg . "\n";
    }
    
    exit(1);
}



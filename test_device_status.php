<?php
/**
 * Test if device is already registered by checking status
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Check Device Registration Status ===\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

// Check if we have a certificate stored in database
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if ($device && $device['certificate_pem'] && $device['private_key_pem']) {
    echo "✓ Found stored certificate in database\n";
    echo "  Device ID: " . $device['device_id'] . "\n";
    echo "  Serial No: " . $device['device_serial_no'] . "\n";
    echo "  Is Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n\n";
    
    // Try to use the stored certificate
    $api = new ZimraApi('Server', 'v1', true);
    $api->setCertificate($device['certificate_pem'], $device['private_key_pem']);
    
    echo "Testing getStatus with stored certificate...\n";
    try {
        $status = $api->getStatus($deviceId);
        echo "✓ SUCCESS! Device is registered and working\n";
        echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
        echo "  Last Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
        echo "  Operation ID: " . ($status['operationID'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "✗ FAILED: " . $e->getMessage() . "\n";
        echo "  Certificate may be expired or invalid\n";
    }
} else {
    echo "✗ No certificate found in database\n";
    echo "  Device needs to be registered\n\n";
    
    echo "Attempting registration...\n";
    $api = new ZimraApi('Server', 'v1', true);
    
    try {
        $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
        echo "✓ CSR generated\n";
        
        $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
        
        if (isset($result['certificate'])) {
            echo "✓ SUCCESS! Device registered\n";
            echo "  Certificate received\n";
            
            // Save to database
            if ($device) {
                $primaryDb->update('fiscal_devices', [
                    'certificate_pem' => $result['certificate'],
                    'private_key_pem' => $csrData['privateKey'],
                    'is_registered' => 1
                ], ['id' => $device['id']]);
                echo "  ✓ Certificate saved to database\n";
            }
        }
    } catch (Exception $e) {
        echo "✗ Registration failed: " . $e->getMessage() . "\n";
        
        // Check error code
        if (strpos($e->getMessage(), 'DEV03') !== false) {
            echo "\n  Error DEV03: CSR not in PEM structure\n";
            echo "  Possible reasons:\n";
            echo "    - Device may already be registered\n";
            echo "    - CSR format issue\n";
            echo "    - Try using issueCertificate instead\n";
        } elseif (strpos($e->getMessage(), 'DEV01') !== false) {
            echo "\n  Error DEV01: Device not found or not active\n";
        }
    }
}

echo "\n=== Test Complete ===\n";


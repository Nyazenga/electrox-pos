<?php
/**
 * Register Device 30200 and Save Certificate EXACTLY as Received
 * No modifications, no trimming, save as-is
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "REGISTER DEVICE 30200 - SAVE CERTIFICATE AS-IS\n";
echo "========================================\n\n";

$deviceId = 30200;
$activationKey = '00294543';
$deviceSerialNo = 'electrox-2';

$primaryDb = Database::getPrimaryInstance();

// Check if device already exists
echo "Step 1: Checking device record...\n";
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    echo "Creating device record...\n";
    $branch = $primaryDb->getRow("SELECT id FROM branches WHERE branch_code = 'HS' OR branch_name LIKE '%Hillside%' LIMIT 1");
    if (!$branch) {
        $branch = $primaryDb->getRow("SELECT id FROM branches LIMIT 1");
    }
    
    if (!$branch) {
        die("✗ No branches found\n");
    }
    
    $deviceId_db = $primaryDb->insert('fiscal_devices', [
        'branch_id' => $branch['id'],
        'device_id' => $deviceId,
        'device_serial_no' => $deviceSerialNo,
        'activation_key' => $activationKey,
        'device_model_name' => 'Server',
        'device_model_version' => 'v1',
        'is_active' => 1
    ]);
    
    if ($deviceId_db) {
        echo "✓ Device record created (ID: $deviceId_db)\n";
    } else {
        die("✗ Failed to create device record\n");
    }
} else {
    echo "✓ Device record exists (ID: " . $device['id'] . ")\n";
    if ($device['is_registered']) {
        echo "⚠ Device is already registered\n";
    }
}
echo "\n";

// Step 2: Generate CSR
echo "Step 2: Generating CSR...\n";
$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
echo "✓ CSR generated\n";
echo "  CSR Subject CN: ZIMRA-$deviceSerialNo-" . str_pad($deviceId, 10, '0', STR_PAD_LEFT) . "\n";
echo "  CSR length: " . strlen($csrData['csr']) . " bytes\n";
echo "  Private key length: " . strlen($csrData['privateKey']) . " bytes\n\n";

// Step 3: Register Device
echo "Step 3: Registering device with ZIMRA...\n";
$api = new ZimraApi('Server', 'v1', true);

try {
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    
    if (!isset($result['certificate'])) {
        die("✗ No certificate in response\n");
    }
    
    echo "✓✓✓ DEVICE REGISTERED SUCCESSFULLY! ✓✓✓\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    
    // Get certificate EXACTLY as received (no modifications)
    $certificateReceived = $result['certificate'];
    $privateKeyReceived = $csrData['privateKey'];
    
    echo "\nCertificate received:\n";
    echo "  Length: " . strlen($certificateReceived) . " bytes\n";
    echo "  First 50 chars: " . substr($certificateReceived, 0, 50) . "...\n";
    echo "  Last 50 chars: ..." . substr($certificateReceived, -50) . "\n";
    echo "  Has BEGIN: " . (strpos($certificateReceived, '-----BEGIN CERTIFICATE-----') !== false ? 'Yes' : 'No') . "\n";
    echo "  Has END: " . (strpos($certificateReceived, '-----END CERTIFICATE-----') !== false ? 'Yes' : 'No') . "\n";
    echo "\n";
    
    // Step 4: Save Certificate to File EXACTLY as received
    echo "Step 4: Saving certificate to file (EXACTLY as received)...\n";
    $certFile = "certificate_$deviceId.pem";
    $keyFile = "private_key_$deviceId.pem";
    
    // Save EXACTLY as received - no trimming, no modifications
    $bytesWritten = file_put_contents($certFile, $certificateReceived);
    if ($bytesWritten === false) {
        die("✗ Failed to write certificate to file\n");
    }
    echo "✓ Certificate saved to file: $certFile\n";
    echo "  Bytes written: $bytesWritten\n";
    echo "  File size: " . filesize($certFile) . " bytes\n";
    
    // Verify file content matches
    $fileContent = file_get_contents($certFile);
    if ($fileContent === $certificateReceived) {
        echo "  ✓ File content matches received certificate (EXACT)\n";
    } else {
        echo "  ✗ File content does NOT match!\n";
        echo "  File length: " . strlen($fileContent) . " bytes\n";
        echo "  Received length: " . strlen($certificateReceived) . " bytes\n";
    }
    
    // Save private key
    $keyBytesWritten = file_put_contents($keyFile, $privateKeyReceived);
    if ($keyBytesWritten === false) {
        die("✗ Failed to write private key to file\n");
    }
    echo "✓ Private key saved to file: $keyFile\n";
    echo "  Bytes written: $keyBytesWritten\n";
    echo "  File size: " . filesize($keyFile) . " bytes\n";
    echo "\n";
    
    // Step 5: Save Certificate to Database EXACTLY as received
    echo "Step 5: Saving certificate to database (EXACTLY as received)...\n";
    
    // Use CertificateStorage which will encrypt private key but keep certificate as-is
    CertificateStorage::saveCertificate($deviceId, $certificateReceived, $privateKeyReceived);
    echo "✓ Certificate saved to database using CertificateStorage\n";
    
    // Verify database content
    $dbDevice = $primaryDb->getRow(
        "SELECT certificate_pem, private_key_pem FROM fiscal_devices WHERE device_id = :device_id",
        [':device_id' => $deviceId]
    );
    
    if ($dbDevice && $dbDevice['certificate_pem']) {
        $dbCert = $dbDevice['certificate_pem'];
        echo "  Database cert length: " . strlen($dbCert) . " bytes\n";
        
        // Compare - certificates should match exactly
        if ($dbCert === $certificateReceived) {
            echo "  ✓✓✓ Database certificate matches received certificate (EXACT) ✓✓✓\n";
        } else {
            // Check if only whitespace difference
            $dbCertClean = preg_replace('/\s+/', '', $dbCert);
            $receivedClean = preg_replace('/\s+/', '', $certificateReceived);
            if ($dbCertClean === $receivedClean) {
                echo "  ⚠ Only whitespace differences (certificates are same, just formatting)\n";
            } else {
                echo "  ✗ Database certificate does NOT match received certificate!\n";
                echo "  First 100 chars match: " . (substr($dbCert, 0, 100) === substr($certificateReceived, 0, 100) ? 'Yes' : 'No') . "\n";
            }
        }
        
        // Private key is encrypted, so we can't compare directly
        echo "  Private key: Encrypted in database (as expected)\n";
    } else {
        echo "  ✗ Certificate not found in database after save!\n";
    }
    echo "\n";
    
    // Step 6: Test Authentication with Saved Certificate
    echo "Step 6: Testing authentication with saved certificate...\n";
    echo str_repeat("-", 50) . "\n";
    
    // Test with file certificate
    echo "Test A: Using certificate from file...\n";
    $certFromFile = file_get_contents($certFile);
    $keyFromFile = file_get_contents($keyFile);
    
    $api2 = new ZimraApi('Server', 'v1', true);
    $api2->setCertificate($certFromFile, $keyFromFile);
    
    try {
        $status = $api2->getStatus($deviceId);
        echo "  ✓✓✓ getStatus SUCCESS! ✓✓✓\n";
        echo "    Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
        echo "    Last Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "  ✗ getStatus FAILED: " . $e->getMessage() . "\n";
    }
    
    // Test with database certificate
    echo "\nTest B: Using certificate from database...\n";
    $certData = CertificateStorage::loadCertificate($deviceId);
    if ($certData) {
        $api3 = new ZimraApi('Server', 'v1', true);
        $api3->setCertificate($certData['certificate'], $certData['privateKey']);
        
        try {
            $status2 = $api3->getStatus($deviceId);
            echo "  ✓✓✓ getStatus SUCCESS! ✓✓✓\n";
            echo "    Fiscal Day Status: " . ($status2['fiscalDayStatus'] ?? 'N/A') . "\n";
        } catch (Exception $e) {
            echo "  ✗ getStatus FAILED: " . $e->getMessage() . "\n";
        }
        
        try {
            $config = $api3->getConfig($deviceId);
            echo "  ✓✓✓ getConfig SUCCESS! ✓✓✓\n";
            echo "    Operating Mode: " . ($config['deviceOperatingMode'] ?? 'N/A') . "\n";
        } catch (Exception $e) {
            echo "  ✗ getConfig FAILED: " . $e->getMessage() . "\n";
        }
        
        try {
            $ping = $api3->ping($deviceId);
            echo "  ✓✓✓ ping SUCCESS! ✓✓✓\n";
            echo "    Reporting Frequency: " . ($ping['reportingFrequency'] ?? 'N/A') . " minutes\n";
        } catch (Exception $e) {
            echo "  ✗ ping FAILED: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  ✗ Failed to load certificate from database\n";
    }
    
    echo "\n========================================\n";
    echo "REGISTRATION COMPLETE\n";
    echo "========================================\n";
    echo "\nCertificate saved to:\n";
    echo "  File: $certFile\n";
    echo "  Database: fiscal_devices table (device_id = $deviceId)\n";
    echo "\nPrivate key saved to:\n";
    echo "  File: $keyFile\n";
    echo "  Database: fiscal_devices table (encrypted)\n";
    
} catch (Exception $e) {
    echo "✗ Registration failed: " . $e->getMessage() . "\n";
    exit(1);
}


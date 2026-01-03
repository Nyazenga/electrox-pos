#!/bin/bash
# Script to create and run reregister_device_30199.php on the server

cat > /var/www/electro-pos/reregister_device_30199.php << 'ENDOFFILE'
<?php
/**
 * Re-register Device 30199 and Store Certificate
 * Run on both localhost and live server
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(60);

echo "========================================\n";
echo "RE-REGISTER DEVICE 30199\n";
echo "========================================\n\n";

echo "Loading config...\n";
require_once __DIR__ . '/config.php';
echo "Config loaded\n";

// Detect if running on localhost (Windows XAMPP)
$isLocalhost = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1');
echo "Detected localhost: " . ($isLocalhost ? 'Yes' : 'No') . "\n";
echo "DB_HOST: " . DB_HOST . "\n\n";

// Use localhost credentials for local
if ($isLocalhost) {
    $dsn = "mysql:host=localhost;dbname=electrox_primary;charset=utf8mb4";
    $pdo = new PDO($dsn, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    class SimpleDb {
        private $pdo;
        public function __construct($pdo) { $this->pdo = $pdo; }
        public function getRow($sql, $params = []) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        public function update($table, $data, $where) {
            $set = [];
            $whereClause = [];
            $params = [];
            foreach ($data as $key => $value) {
                $set[] = "$key = :set_$key";
                $params[":set_$key"] = $value;
            }
            foreach ($where as $key => $value) {
                $whereClause[] = "$key = :where_$key";
                $params[":where_$key"] = $value;
            }
            $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $whereClause);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        }
    }
    $db = new SimpleDb($pdo);
} else {
    require_once APP_PATH . '/includes/db.php';
    $db = Database::getPrimaryInstance();
}

require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

echo "Step 1: Checking device in database...\n";
$device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id", [':device_id' => $deviceId]);
if (!$device) {
    die("✗ Device 30199 not found in database!\n");
}

echo "✓ Device found\n";
echo "  Branch ID: " . $device['branch_id'] . "\n";
echo "  Current Registration Status: " . ($device['is_registered'] ? 'Registered' : 'Not Registered') . "\n\n";

echo "Step 2: Generating Certificate Signing Request (CSR)...\n";
try {
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId);
    echo "✓ CSR generated\n";
    echo "  CSR length: " . strlen($csrData['csr']) . " bytes\n";
    echo "  Private key length: " . strlen($csrData['privateKey']) . " bytes\n\n";
} catch (Exception $e) {
    die("✗ CSR generation failed: " . $e->getMessage() . "\n");
}

echo "Step 3: Registering device with ZIMRA...\n";
try {
    $api = new ZimraApi('Server', 'v1', true);
    
    echo "  Calling registerDevice API...\n";
    $response = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    
    if (!isset($response['certificate'])) {
        die("✗ Registration failed: No certificate in response\n");
    }
    
    echo "✓ Device registered successfully!\n";
    echo "  Certificate received: " . strlen($response['certificate']) . " bytes\n\n";
} catch (Exception $e) {
    die("✗ Registration failed: " . $e->getMessage() . "\n");
}

echo "Step 4: Storing certificate...\n";
try {
    CertificateStorage::saveCertificate(
        $deviceId,
        $response['certificate'],
        $csrData['privateKey'],
        null, // validTill will be extracted
        false // Not encrypting for now
    );
    echo "✓ Certificate stored in database\n\n";
} catch (Exception $e) {
    die("✗ Certificate storage failed: " . $e->getMessage() . "\n");
}

echo "Step 5: Updating device registration status...\n";
try {
    $db->update('fiscal_devices', [
        'is_registered' => 1,
        'activation_key' => $activationKey,
        'device_serial_no' => $deviceSerialNo,
        'updated_at' => date('Y-m-d H:i:s')
    ], ['device_id' => $deviceId]);
    echo "✓ Device status updated\n\n";
} catch (Exception $e) {
    echo "⚠ Device status update failed: " . $e->getMessage() . "\n";
    echo "  (Certificate is stored, but device record not updated)\n\n";
}

echo "Step 6: Testing API call with new certificate...\n";
try {
    $api->setCertificate($response['certificate'], $csrData['privateKey']);
    
    echo "  Calling getStatus()...\n";
    $startTime = microtime(true);
    $status = $api->getStatus($deviceId);
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "✓✓✓ SUCCESS in {$duration}ms ✓✓✓\n";
    echo "Response:\n";
    echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
    
    if (isset($status['fiscalDayStatus'])) {
        echo "\n✓ Fiscal Day Status: " . $status['fiscalDayStatus'] . "\n";
        if (isset($status['lastFiscalDayNo'])) {
            echo "✓ Last Fiscal Day No: " . $status['lastFiscalDayNo'] . "\n";
        }
    }
    
    echo "\n✓✓✓ DEVICE 30199 IS NOW WORKING! ✓✓✓\n";
} catch (Exception $e) {
    echo "✗✗✗ TEST FAILED ✗✗✗\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Type: " . get_class($e) . "\n";
}

echo "\n========================================\n";
echo "RE-REGISTRATION COMPLETE\n";
echo "========================================\n";
ENDOFFILE

cd /var/www/electro-pos && php reregister_device_30199.php


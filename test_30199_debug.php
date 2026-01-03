<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(15);

echo "Step 1: Loading config...\n";
require_once __DIR__ . '/config.php';
echo "✓ Config loaded\n";

echo "Step 2: Loading database...\n";
try {
    require_once APP_PATH . '/includes/db.php';
    echo "✓ Database class loaded\n";
} catch (Exception $e) {
    die("✗ Database class failed: " . $e->getMessage() . "\n");
}

echo "Step 3: Getting database instance...\n";
try {
    // Use direct PDO connection for localhost
    $dsn = "mysql:host=localhost;dbname=electrox_primary;charset=utf8mb4";
    $pdo = new PDO($dsn, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Create a simple wrapper for getRow
    class SimpleDb {
        private $pdo;
        public function __construct($pdo) { $this->pdo = $pdo; }
        public function getRow($sql, $params = []) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    $db = new SimpleDb($pdo);
    echo "✓ Database instance created\n";
} catch (Exception $e) {
    die("✗ Database instance failed: " . $e->getMessage() . "\n");
}

echo "Step 4: Querying device...\n";
try {
    $device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id", [':device_id' => 30199]);
    if (!$device) {
        die("✗ Device 30199 not found in database\n");
    }
    echo "✓ Device found: " . $device['device_id'] . ", Registered: " . $device['is_registered'] . "\n";
} catch (Exception $e) {
    die("✗ Database query failed: " . $e->getMessage() . "\n");
}

echo "Step 5: Loading certificate...\n";
try {
    // Load certificate directly from database using localhost connection
    $certQuery = "SELECT certificate_pem, private_key_pem, certificate_valid_till FROM fiscal_devices WHERE device_id = 30199 AND is_registered = 1";
    $certRow = $pdo->query($certQuery)->fetch(PDO::FETCH_ASSOC);
    
    if ($certRow && $certRow['certificate_pem'] && $certRow['private_key_pem']) {
        $certData = [
            'certificate' => $certRow['certificate_pem'],
            'privateKey' => $certRow['private_key_pem'],
            'validTill' => $certRow['certificate_valid_till']
        ];
        echo "✓ Certificate loaded from database: " . strlen($certData['certificate']) . " bytes\n";
    } elseif ($device['certificate_pem'] && $device['private_key_pem']) {
        echo "  → Using fallback from device record\n";
        $certData = ['certificate' => $device['certificate_pem'], 'privateKey' => $device['private_key_pem']];
        echo "✓ Certificate loaded from device record: " . strlen($certData['certificate']) . " bytes\n";
    } else {
        die("✗ No certificate found\n");
    }
} catch (Exception $e) {
    die("✗ Certificate loading failed: " . $e->getMessage() . "\n");
}

echo "Step 6: Initializing API...\n";
try {
    require_once APP_PATH . '/includes/zimra_api.php';
    $api = new ZimraApi($device['device_model_name'] ?? 'Server', $device['device_model_version'] ?? 'v1', true);
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    echo "✓ API client initialized\n";
} catch (Exception $e) {
    die("✗ API initialization failed: " . $e->getMessage() . "\n");
}

echo "Step 7: Setting timeout to 5 seconds...\n";
try {
    $reflection = new ReflectionClass($api);
    $timeoutProperty = $reflection->getProperty('timeout');
    $timeoutProperty->setAccessible(true);
    $timeoutProperty->setValue($api, 5);
    echo "✓ Timeout set to 5 seconds\n";
} catch (Exception $e) {
    echo "⚠ Could not set timeout: " . $e->getMessage() . "\n";
}

echo "\nStep 8: Calling getStatus() API...\n";
echo "========================================\n";
$startTime = microtime(true);

try {
    $status = $api->getStatus(30199);
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "\n✓✓✓ SUCCESS in {$duration}ms ✓✓✓\n";
    echo "Response:\n";
    echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
    
    if (isset($status['fiscalDayStatus'])) {
        echo "\n✓ Fiscal Day Status: " . $status['fiscalDayStatus'] . "\n";
    } else {
        echo "\n⚠ Response missing 'fiscalDayStatus' field\n";
    }
} catch (Exception $e) {
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    echo "\n✗✗✗ ERROR after {$duration}ms ✗✗✗\n";
    echo "Error Message: " . $e->getMessage() . "\n";
    echo "Error Type: " . get_class($e) . "\n";
    echo "Error Code: " . ($e->getCode() ?: 'N/A') . "\n";
    
    // Analyze error
    $errorMsg = $e->getMessage();
    echo "\n=== ERROR ANALYSIS ===\n";
    if (strpos($errorMsg, '401') !== false || strpos($errorMsg, 'Unauthorized') !== false) {
        echo "DIAGNOSIS: Certificate Authentication Failed (401)\n";
    } elseif (strpos($errorMsg, '404') !== false) {
        echo "DIAGNOSIS: Device Not Found (404)\n";
    } elseif (strpos($errorMsg, 'timeout') !== false || strpos($errorMsg, 'Failed to connect') !== false) {
        echo "DIAGNOSIS: Connection Timeout or Network Issue\n";
    } else {
        echo "DIAGNOSIS: " . $errorMsg . "\n";
    }
}

echo "\n========================================\n";
echo "Test complete\n";


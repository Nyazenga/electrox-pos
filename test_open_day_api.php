<?php
/**
 * Test ZIMRA OpenDay API endpoint
 * This script makes a direct API call to test the OpenDay endpoint
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bootstrap
define('APP_PATH', __DIR__);

// Check if config.php exists
if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'config.php')) {
    die("ERROR: config.php not found in " . __DIR__ . "\n");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

// Check if includes directory exists
if (!is_dir(APP_PATH . DIRECTORY_SEPARATOR . 'includes')) {
    die("ERROR: includes directory not found in " . APP_PATH . "\n");
}

require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'certificate_storage.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'zimra_api.php';

// Get device ID from command line or use default
$deviceId = isset($argv[1]) ? intval($argv[1]) : 30200;
$fiscalDayNo = isset($argv[2]) ? intval($argv[2]) : null;

echo "=== Testing ZIMRA OpenDay API ===\n\n";
echo "Device ID: $deviceId\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Load certificate from database
    echo "Step 1: Loading certificate from database...\n";
    $certData = CertificateStorage::loadCertificate($deviceId);
    
    if (!$certData) {
        // Try fallback to device record
        $primaryDb = Database::getPrimaryInstance();
        $device = $primaryDb->getRow(
            "SELECT certificate_pem, private_key_pem FROM fiscal_devices WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
        
        if ($device && $device['certificate_pem'] && $device['private_key_pem']) {
            // Try to decrypt private key
            $privateKey = $device['private_key_pem'];
            if (strpos($privateKey, '-----BEGIN') === false) {
                $privateKey = CertificateStorage::decryptPrivateKey($privateKey);
            }
            $certData = [
                'certificate' => $device['certificate_pem'],
                'privateKey' => $privateKey
            ];
            echo "  ✓ Certificate loaded from device record (fallback)\n";
        } else {
            throw new Exception("No certificate found for device $deviceId");
        }
    } else {
        echo "  ✓ Certificate loaded from CertificateStorage\n";
    }
    
    echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n\n";
    
    // Initialize ZIMRA API
    echo "Step 2: Initializing ZIMRA API...\n";
    $api = new ZimraApi('Server', 'v1', true); // Use test environment
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    echo "  ✓ API initialized\n";
    echo "  Base URL: https://fdmsapitest.zimra.co.zw\n";
    echo "  Device Model: Server\n";
    echo "  Device Version: v1\n\n";
    
    // Prepare request data
    echo "Step 3: Preparing request data...\n";
    $fiscalDayOpened = date('Y-m-d\TH:i:s');
    
    $requestData = [
        'fiscalDayOpened' => $fiscalDayOpened
    ];
    
    if ($fiscalDayNo !== null) {
        $requestData['fiscalDayNo'] = $fiscalDayNo;
    }
    
    echo "  Request body:\n";
    echo "  " . json_encode($requestData, JSON_PRETTY_PRINT) . "\n\n";
    
    // Make the API call
    echo "Step 4: Making API call to OpenDay endpoint...\n";
    echo "  Endpoint: /Device/v1/$deviceId/OpenDay\n";
    echo "  Method: POST\n";
    echo "  Headers:\n";
    echo "    - Content-Type: application/json\n";
    echo "    - Accept: application/json\n";
    echo "    - DeviceModelName: Server\n";
    echo "    - DeviceModelVersion: v1\n";
    echo "  Certificate: ✓ Loaded\n\n";
    
    $response = $api->openDay($deviceId, $fiscalDayOpened, $fiscalDayNo);
    
    echo "Step 5: Response received!\n";
    echo "  ✓ SUCCESS!\n\n";
    echo "Response Data:\n";
    echo json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($response['operationID'])) {
        echo "Operation ID: " . $response['operationID'] . "\n";
    }
    if (isset($response['fiscalDayNo'])) {
        echo "Fiscal Day No: " . $response['fiscalDayNo'] . "\n";
    }
    
    echo "\n=== Test Complete ===\n";
    echo "✓ API call successful!\n";
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    
    // Try to parse error details
    $errorMsg = $e->getMessage();
    if (strpos($errorMsg, '401') !== false) {
        echo "\n  This is a 401 Unauthorized error.\n";
        echo "  Possible causes:\n";
        echo "    - Certificate is expired\n";
        echo "    - Certificate is not valid for this device\n";
        echo "    - Certificate authentication failed\n";
    } elseif (strpos($errorMsg, '422') !== false || strpos($errorMsg, 'FISC01') !== false) {
        echo "\n  This is a 422 Unprocessable Entity error.\n";
        echo "  Possible causes:\n";
        echo "    - Fiscal day is already open\n";
        echo "    - Previous fiscal day is not closed\n";
    } elseif (strpos($errorMsg, '400') !== false) {
        echo "\n  This is a 400 Bad Request error.\n";
        echo "  Possible causes:\n";
        echo "    - Invalid request data format\n";
        echo "    - Missing required fields\n";
    }
    
    echo "\nFull error:\n";
    echo $errorMsg . "\n";
    
    if (strpos($errorMsg, 'Full response:') !== false) {
        // Try to extract JSON from error message
        $jsonStart = strpos($errorMsg, '{');
        if ($jsonStart !== false) {
            $jsonPart = substr($errorMsg, $jsonStart);
            $jsonEnd = strrpos($jsonPart, '}');
            if ($jsonEnd !== false) {
                $jsonStr = substr($jsonPart, 0, $jsonEnd + 1);
                $errorData = json_decode($jsonStr, true);
                if ($errorData) {
                    echo "\nParsed error response:\n";
                    echo json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
                }
            }
        }
    }
    
    exit(1);
}


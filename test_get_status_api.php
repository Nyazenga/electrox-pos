<?php
/**
 * Test ZIMRA GetStatus API endpoint
 * This script makes a direct API call to test the GetStatus endpoint
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

echo "=== Testing ZIMRA GetStatus API ===\n\n";
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
            echo "  [OK] Certificate loaded from device record (fallback)\n";
        } else {
            throw new Exception("No certificate found for device $deviceId");
        }
    } else {
        echo "  [OK] Certificate loaded from CertificateStorage\n";
    }
    
    echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n\n";
    
    // Initialize ZIMRA API
    echo "Step 2: Initializing ZIMRA API...\n";
    $api = new ZimraApi('Server', 'v1', true); // Use test environment
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    echo "  [OK] API initialized\n";
    echo "  Base URL: https://fdmsapitest.zimra.co.zw\n";
    echo "  Device Model: Server\n";
    echo "  Device Version: v1\n\n";
    
    // Get branch ID for device
    $primaryDb = Database::getPrimaryInstance();
    $device = $primaryDb->getRow(
        "SELECT branch_id FROM fiscal_devices WHERE device_id = :device_id AND is_active = 1",
        [':device_id' => $deviceId]
    );
    
    if (!$device) {
        throw new Exception("Device $deviceId not found or not active");
    }
    
    $branchId = $device['branch_id'];
    echo "  Branch ID: $branchId\n\n";
    
    // Use FiscalService which has better error handling
    require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'fiscal_service.php';
    $fiscalService = new FiscalService($branchId);
    
    // Make the API call
    echo "Step 3: Making API call to GetStatus endpoint...\n";
    echo "  Endpoint: GET /Device/v1/$deviceId/GetStatus\n";
    echo "  Full URL: https://fdmsapitest.zimra.co.zw/Device/v1/$deviceId/GetStatus\n";
    echo "  Method: GET\n";
    echo "  Headers:\n";
    echo "    - Accept: application/json\n";
    echo "    - DeviceModelName: Server\n";
    echo "    - DeviceModelVersion: v1\n";
    echo "  Certificate: [OK] Loaded\n";
    echo "  Timeout: 30 seconds\n\n";
    
    // Use direct API call
    try {
        $response = $api->getStatus($deviceId);
    } catch (Exception $apiError) {
        // Check if it's a connection timeout
        if (strpos($apiError->getMessage(), 'Failed to connect') !== false || strpos($apiError->getMessage(), 'timeout') !== false) {
            echo "  [WARNING] Connection timeout or network issue\n";
            echo "  This might be a temporary network problem.\n";
            echo "  The endpoint format appears correct: /Device/v1/{deviceID}/GetStatus\n\n";
            throw $apiError;
        }
        throw $apiError;
    }
    
    echo "Step 4: Response received!\n";
    echo "  [SUCCESS] Status retrieved successfully!\n\n";
    
    echo "========================================\n";
    echo "ZIMRA STATUS RESPONSE\n";
    echo "========================================\n\n";
    
    // Display formatted response
    if ($response === null) {
        echo "[WARNING] Response is null - API may have returned empty response\n\n";
    } else {
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
    
    echo "========================================\n";
    echo "STATUS DETAILS\n";
    echo "========================================\n\n";
    
    if (isset($response['operationID'])) {
        echo "Operation ID: " . $response['operationID'] . "\n";
    }
    
    if (isset($response['fiscalDayStatus'])) {
        echo "Fiscal Day Status: " . $response['fiscalDayStatus'] . "\n";
        
        // Status interpretation
        switch ($response['fiscalDayStatus']) {
            case 'FiscalDayOpened':
                echo "  → Fiscal day is currently OPEN\n";
                break;
            case 'FiscalDayClosed':
                echo "  → Fiscal day is CLOSED\n";
                break;
            case 'FiscalDayCloseFailed':
                echo "  → Previous close attempt FAILED - can retry\n";
                break;
            case 'FiscalDayCloseInitiated':
                echo "  → Close is IN PROGRESS (asynchronous)\n";
                break;
            default:
                echo "  → Unknown status\n";
        }
    }
    
    if (isset($response['fiscalDayReconciliationMode'])) {
        echo "Reconciliation Mode: " . $response['fiscalDayReconciliationMode'] . "\n";
    }
    
    if (isset($response['lastFiscalDayNo'])) {
        echo "Last Fiscal Day No: " . $response['lastFiscalDayNo'] . "\n";
    }
    
    if (isset($response['lastReceiptGlobalNo'])) {
        echo "Last Receipt Global No: " . $response['lastReceiptGlobalNo'] . "\n";
    }
    
    if (isset($response['fiscalDayOpened'])) {
        echo "Fiscal Day Opened: " . $response['fiscalDayOpened'] . "\n";
    }
    
    if (isset($response['fiscalDayClosed'])) {
        echo "Fiscal Day Closed: " . $response['fiscalDayClosed'] . "\n";
    }
    
    if (isset($response['fiscalDayServerSignature'])) {
        echo "\nServer Signature:\n";
        $sig = $response['fiscalDayServerSignature'];
        if (isset($sig['certificateThumbprint'])) {
            echo "  Certificate Thumbprint: " . $sig['certificateThumbprint'] . "\n";
        }
        if (isset($sig['hash'])) {
            echo "  Hash: " . $sig['hash'] . "\n";
        }
        if (isset($sig['signature'])) {
            echo "  Signature: " . substr($sig['signature'], 0, 50) . "... (truncated)\n";
        }
    }
    
    echo "\n=== Test Complete ===\n";
    echo "[SUCCESS] Status retrieved successfully!\n";
    
} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    
    // Try to parse error details
    $errorMsg = $e->getMessage();
    if (strpos($errorMsg, '401') !== false) {
        echo "\n  This is a 401 Unauthorized error.\n";
        echo "  Possible causes:\n";
        echo "    - Certificate is expired\n";
        echo "    - Certificate is not valid for this device\n";
        echo "    - Certificate authentication failed\n";
    } elseif (strpos($errorMsg, '422') !== false) {
        echo "\n  This is a 422 Unprocessable Entity error.\n";
        echo "  Possible causes:\n";
        echo "    - Invalid device state\n";
        echo "    - Device not properly registered\n";
    } elseif (strpos($errorMsg, '400') !== false) {
        echo "\n  This is a 400 Bad Request error.\n";
        echo "  Possible causes:\n";
        echo "    - Invalid request format\n";
        echo "    - Missing required headers\n";
    } elseif (strpos($errorMsg, '500') !== false) {
        echo "\n  This is a 500 Server Error.\n";
        echo "  ZIMRA server encountered temporary issues.\n";
        echo "  Try again in a few moments.\n";
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
                    echo json_encode($errorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                }
            }
        }
    }
    
    exit(1);
}

<?php
/**
 * Test ZIMRA CloseDay API endpoint
 * This script makes a direct API call to test the CloseDay endpoint
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
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'zimra_signature.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'fiscal_service.php';

// Get device ID from command line or use default
$deviceId = isset($argv[1]) ? intval($argv[1]) : 30200;

echo "=== Testing ZIMRA CloseDay API ===\n\n";
echo "Device ID: $deviceId\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Use FiscalService to handle the close operation (it does all the work)
    echo "Step 1: Initializing FiscalService...\n";
    
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
    echo "  Branch ID: $branchId\n";
    echo "  Device ID: $deviceId\n\n";
    
    // Create FiscalService instance
    $fiscalService = new FiscalService($branchId);
    
    // Step 2: Check current status
    echo "Step 2: Checking current fiscal day status...\n";
    $status = $fiscalService->getFiscalDayStatus();
    
    if ($status && isset($status['fiscalDayStatus'])) {
        echo "  Current status: " . $status['fiscalDayStatus'] . "\n";
        if (isset($status['lastFiscalDayNo'])) {
            echo "  Last fiscal day no: " . $status['lastFiscalDayNo'] . "\n";
        }
        
        if ($status['fiscalDayStatus'] !== 'FiscalDayOpened' && $status['fiscalDayStatus'] !== 'FiscalDayCloseFailed') {
            throw new Exception("Fiscal day status is '" . $status['fiscalDayStatus'] . "'. Cannot close. Status must be 'FiscalDayOpened' or 'FiscalDayCloseFailed'.");
        }
    } else {
        throw new Exception("Could not retrieve fiscal day status from ZIMRA");
    }
    echo "\n";
    
    // Step 3: Close the fiscal day
    echo "Step 3: Closing fiscal day...\n";
    echo "  This will:\n";
    echo "    - Calculate fiscal day counters\n";
    echo "    - Generate fiscal day signature\n";
    echo "    - Submit close request to ZIMRA\n\n";
    
    $result = $fiscalService->closeFiscalDay();
    
    echo "Step 4: Response received!\n";
    echo "  [SUCCESS] Fiscal day close initiated!\n\n";
    
    echo "Response Data:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($result['operationID'])) {
        echo "Operation ID: " . $result['operationID'] . "\n";
        echo "\n";
        echo "IMPORTANT NOTES:\n";
        echo "  - Closing is asynchronous - ZIMRA processes the request in the background\n";
        echo "  - The fiscal day status will change to 'FiscalDayCloseInitiated' locally\n";
        echo "  - Wait a few minutes, then check status again to see if it's fully closed\n";
        echo "  - Once closed, you can open a new fiscal day\n";
    }
    
    echo "\n=== Test Complete ===\n";
    echo "[SUCCESS] Fiscal day close request submitted successfully!\n";
    
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
    } elseif (strpos($errorMsg, '422') !== false || strpos($errorMsg, 'FISC01') !== false) {
        echo "\n  This is a 422 Unprocessable Entity error.\n";
        echo "  Possible causes:\n";
        echo "    - Fiscal day is not open (cannot close)\n";
        echo "    - Fiscal day has Grey or Red receipts (validation errors)\n";
        echo "    - Fiscal day validation failed\n";
    } elseif (strpos($errorMsg, '400') !== false) {
        echo "\n  This is a 400 Bad Request error.\n";
        echo "  Possible causes:\n";
        echo "    - Invalid request data format\n";
        echo "    - Missing required fields\n";
        echo "    - Invalid counter values\n";
    } elseif (strpos($errorMsg, 'No open fiscal day') !== false) {
        echo "\n  Fiscal day is not open, so it cannot be closed.\n";
        echo "  Current status may be 'FiscalDayClosed' or no fiscal day exists.\n";
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



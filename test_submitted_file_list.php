<?php
/**
 * Test ZIMRA SubmittedFileList endpoint
 * GET /Device/v1/{deviceID}/SubmittedFileList
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

$deviceId = 30199;
$branchId = 1;

try {
    $db = Database::getPrimaryInstance();
    
    // Get device
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id",
        [':device_id' => $deviceId, ':branch_id' => $branchId]
    );
    
    if (!$device || empty($device['certificate_pem']) || empty($device['private_key_pem'])) {
        echo "ERROR: Device not found or missing certificate/key\n";
        exit(1);
    }
    
    // Initialize fiscal service to get API client
    $fiscalService = new FiscalService($branchId);
    
    // Use reflection to get the API client (it's private)
    $reflection = new ReflectionClass($fiscalService);
    $apiProperty = $reflection->getProperty('api');
    $apiProperty->setAccessible(true);
    $api = $apiProperty->getValue($fiscalService);
    
    // Try multiple date ranges - today, this week, this month
    $todayStart = date('Y-m-d\T00:00:00');
    $todayEnd = date('Y-m-d\T23:59:59');
    
    // Also try broader range (last 7 days)
    $weekStart = date('Y-m-d\T00:00:00', strtotime('-7 days'));
    $weekEnd = date('Y-m-d\T23:59:59');
    
    echo "=== Testing ZIMRA SubmittedFileList Endpoint ===\n\n";
    echo "Device ID: $deviceId\n\n";
    
    // First try today
    echo "=== ATTEMPT 1: Today's Range ===\n";
    echo "Date Range: $todayStart to $todayEnd\n\n";
    
    // Call the endpoint using the API client's makeRequest method
    $endpoint = '/Device/v1/' . intval($deviceId) . '/SubmittedFileList';
    $queryParams = [
        'FileUploadedFrom' => $todayStart,
        'FileUploadedTill' => $todayEnd,
        'Offset' => 0,
        'Limit' => 100,
        'Sort' => 'FileUploadDate',
        'Order' => 'desc'
    ];
    
    $endpointWithParams = $endpoint . '?' . http_build_query($queryParams);
    
    echo "Endpoint: $endpointWithParams\n\n";
    
    // Use reflection to call makeRequest
    $reflectionApi = new ReflectionClass($api);
    $makeRequestMethod = $reflectionApi->getMethod('makeRequest');
    $makeRequestMethod->setAccessible(true);
    
    try {
        $response = $makeRequestMethod->invoke($api, $endpointWithParams, 'GET', null, true);
        
        echo "=== RAW ZIMRA RESPONSE (as-is) ===\n";
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
        
        echo "=== SUMMARY ===\n";
        if (isset($response['total'])) {
            echo "Total files: {$response['total']}\n";
        }
        if (isset($response['rows']) && is_array($response['rows'])) {
            echo "Files returned: " . count($response['rows']) . "\n\n";
            
            if (count($response['rows']) > 0) {
                foreach ($response['rows'] as $index => $file) {
                    echo "File #" . ($index + 1) . ":\n";
                    echo "  File Name: " . ($file['fileName'] ?? 'N/A') . "\n";
                    echo "  Upload Date: " . ($file['fileUploadDate'] ?? 'N/A') . "\n";
                    echo "  Device ID: " . ($file['deviceId'] ?? 'N/A') . "\n";
                    echo "  Day No: " . ($file['dayNo'] ?? 'N/A') . "\n";
                    echo "  Fiscal Day Opened At: " . ($file['fiscalDayOpenedAt'] ?? 'N/A') . "\n";
                    echo "  Processing Status: " . ($file['fileProcessingStatus'] ?? 'N/A') . "\n";
                    if (isset($file['invoiceWithValidationErrors']) && !empty($file['invoiceWithValidationErrors'])) {
                        echo "  Receipts with Validation Errors: " . count($file['invoiceWithValidationErrors']) . "\n";
                    }
                    echo "\n";
                }
            } else {
                echo "No files found for today. Trying last 7 days...\n\n";
            }
        }
        
        // If no files found for today, try last 7 days
        if (isset($response['total']) && $response['total'] == 0) {
            echo "=== ATTEMPT 2: Last 7 Days Range ===\n";
            echo "Date Range: $weekStart to $weekEnd\n\n";
            
            $queryParamsWeek = [
                'FileUploadedFrom' => $weekStart,
                'FileUploadedTill' => $weekEnd,
                'Offset' => 0,
                'Limit' => 100,
                'Sort' => 'FileUploadDate',
                'Order' => 'desc'
            ];
            
            $endpointWithParamsWeek = $endpoint . '?' . http_build_query($queryParamsWeek);
            echo "Endpoint: $endpointWithParamsWeek\n\n";
            
            $responseWeek = $makeRequestMethod->invoke($api, $endpointWithParamsWeek, 'GET', null, true);
            
            echo "=== RAW ZIMRA RESPONSE (Last 7 Days) ===\n";
            echo json_encode($responseWeek, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
            
            if (isset($responseWeek['total'])) {
                echo "Total files (last 7 days): {$responseWeek['total']}\n";
            }
            if (isset($responseWeek['rows']) && is_array($responseWeek['rows']) && count($responseWeek['rows']) > 0) {
                echo "Files returned: " . count($responseWeek['rows']) . "\n\n";
                
                foreach ($responseWeek['rows'] as $index => $file) {
                    echo "File #" . ($index + 1) . ":\n";
                    echo "  File Name: " . ($file['fileName'] ?? 'N/A') . "\n";
                    echo "  Upload Date: " . ($file['fileUploadDate'] ?? 'N/A') . "\n";
                    echo "  Device ID: " . ($file['deviceId'] ?? 'N/A') . "\n";
                    echo "  Day No: " . ($file['dayNo'] ?? 'N/A') . "\n";
                    echo "  Fiscal Day Opened At: " . ($file['fiscalDayOpenedAt'] ?? 'N/A') . "\n";
                    echo "  Processing Status: " . ($file['fileProcessingStatus'] ?? 'N/A') . "\n";
                    if (isset($file['invoiceWithValidationErrors']) && !empty($file['invoiceWithValidationErrors'])) {
                        echo "  Receipts with Validation Errors: " . count($file['invoiceWithValidationErrors']) . "\n";
                        foreach ($file['invoiceWithValidationErrors'] as $receipt) {
                            echo "    - Receipt Global No: " . ($receipt['receiptGlobalNo'] ?? 'N/A') . "\n";
                            echo "      Receipt Counter: " . ($receipt['receiptCounter'] ?? 'N/A') . "\n";
                            if (isset($receipt['validationErrors'])) {
                                foreach ($receipt['validationErrors'] as $error) {
                                    echo "      Error: " . ($error['validationErrorCode'] ?? 'N/A') . " (" . ($error['validationErrorColor'] ?? 'N/A') . ")\n";
                                }
                            }
                        }
                    }
                    echo "\n";
                }
            }
        }
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

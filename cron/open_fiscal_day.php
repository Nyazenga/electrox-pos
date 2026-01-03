<?php
/**
 * Cron Job: Open Fiscal Day
 * Runs daily at 04:00 (4 AM)
 * Opens fiscal day for all branches and devices
 */

// Set script execution time limit
set_time_limit(300); // 5 minutes

// Define APP_PATH before requiring config
define('APP_PATH', dirname(dirname(__FILE__)));

require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/mailer.php';

// Email recipient
$emailRecipient = 'nyazengamd@gmail.com';

// Results array
$results = [];

// Get all branches with fiscalization enabled
$primaryDb = Database::getPrimaryInstance();
$branches = $primaryDb->getRows(
    "SELECT b.*, fd.device_id, fd.device_serial_no, fd.device_model_name, fd.device_model_version
     FROM branches b
     INNER JOIN fiscal_devices fd ON b.id = fd.branch_id
     WHERE b.fiscalization_enabled = 1 
     AND fd.is_active = 1
     ORDER BY b.id, fd.device_id"
);

if ($branches === false || empty($branches)) {
    $results[] = [
        'branch' => 'N/A',
        'device_id' => 'N/A',
        'status' => 'No branches with fiscalization enabled found',
        'success' => false,
        'error' => 'No branches with fiscalization enabled'
    ];
    
    // Send email
    sendFiscalDayEmail($emailRecipient, 'Fiscal Day Open - No Branches', $results);
    exit(0);
}

// Process each branch/device combination
foreach ($branches as $branchDevice) {
    $branchId = $branchDevice['id'];
    $branchName = $branchDevice['branch_name'];
    $deviceId = $branchDevice['device_id'];
    $deviceSerialNo = $branchDevice['device_serial_no'];
    
    $result = [
        'branch' => $branchName,
        'branch_id' => $branchId,
        'device_id' => $deviceId,
        'device_serial_no' => $deviceSerialNo,
        'operation' => 'Open Fiscal Day',
        'timestamp' => date('Y-m-d H:i:s'),
        'status_before' => null,
        'status_after' => null,
        'success' => false,
        'error' => null,
        'zimra_response' => null,
        'retries' => 0
    ];
    
    try {
        // Initialize FiscalService
        $fiscalService = new FiscalService($branchId);
        
        // Get status BEFORE operation
        try {
            $statusBefore = $fiscalService->getFiscalDayStatus();
            $result['status_before'] = $statusBefore ? json_encode($statusBefore, JSON_PRETTY_PRINT) : 'Could not retrieve status';
        } catch (Exception $e) {
            $result['status_before'] = 'Error: ' . $e->getMessage();
        }
        
        // Attempt to open fiscal day with retries
        $maxRetries = 3;
        $opened = false;
        $lastError = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $result['retries'] = $attempt - 1;
            
            try {
                $openResult = $fiscalService->openFiscalDay();
                $result['zimra_response'] = json_encode($openResult, JSON_PRETTY_PRINT);
                $result['success'] = true;
                $opened = true;
                break;
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                $result['error'] = $lastError;
                
                // Wait before retry (exponential backoff)
                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt)); // 2, 4, 8 seconds
                }
            }
        }
        
        if (!$opened) {
            $result['error'] = "Failed after {$maxRetries} attempts. Last error: {$lastError}";
        }
        
        // Wait a moment for ZIMRA to process
        sleep(2);
        
        // Get status AFTER operation
        try {
            $statusAfter = $fiscalService->getFiscalDayStatus();
            $result['status_after'] = $statusAfter ? json_encode($statusAfter, JSON_PRETTY_PRINT) : 'Could not retrieve status';
        } catch (Exception $e) {
            $result['status_after'] = 'Error: ' . $e->getMessage();
        }
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        $result['success'] = false;
    }
    
    $results[] = $result;
}

// Send email with results
sendFiscalDayEmail($emailRecipient, 'Fiscal Day Open - Daily Report', $results);

/**
 * Send email with fiscal day operation results
 */
function sendFiscalDayEmail($to, $subject, $results) {
    try {
        $mailer = new Mailer();
        
        // Build email body
        $body = "<html><body>";
        $body .= "<h2>Fiscal Day Open Report</h2>";
        $body .= "<p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>";
        $body .= "<p><strong>Total Branches/Devices Processed:</strong> " . count($results) . "</p>";
        
        $successCount = 0;
        $failCount = 0;
        
        foreach ($results as $result) {
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }
        
        $body .= "<p><strong>Successful:</strong> {$successCount}</p>";
        $body .= "<p><strong>Failed:</strong> {$failCount}</p>";
        
        $body .= "<hr>";
        $body .= "<h3>Detailed Results</h3>";
        
        foreach ($results as $result) {
            $statusColor = $result['success'] ? 'green' : 'red';
            $statusText = $result['success'] ? 'SUCCESS' : 'FAILED';
            
            $body .= "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
            $body .= "<h4 style='color: {$statusColor};'>Branch: {$result['branch']} | Device: {$result['device_id']} ({$result['device_serial_no']}) | Status: {$statusText}</h4>";
            $body .= "<p><strong>Operation:</strong> {$result['operation']}</p>";
            $body .= "<p><strong>Timestamp:</strong> {$result['timestamp']}</p>";
            $body .= "<p><strong>Retries:</strong> {$result['retries']}</p>";
            
            if ($result['status_before']) {
                $body .= "<p><strong>Status Before:</strong></p>";
                $body .= "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>" . htmlspecialchars($result['status_before']) . "</pre>";
            }
            
            if ($result['status_after']) {
                $body .= "<p><strong>Status After:</strong></p>";
                $body .= "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>" . htmlspecialchars($result['status_after']) . "</pre>";
            }
            
            if ($result['zimra_response']) {
                $body .= "<p><strong>ZIMRA Response:</strong></p>";
                $body .= "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>" . htmlspecialchars($result['zimra_response']) . "</pre>";
            }
            
            if ($result['error']) {
                $body .= "<p><strong style='color: red;'>Error:</strong> " . htmlspecialchars($result['error']) . "</p>";
            }
            
            $body .= "</div>";
        }
        
        $body .= "</body></html>";
        
        $mailer->send($to, $subject, $body, true);
        
        error_log("FISCAL DAY OPEN CRON: Email sent to {$to}");
    } catch (Exception $e) {
        error_log("FISCAL DAY OPEN CRON: Failed to send email: " . $e->getMessage());
    }
}

exit(0);


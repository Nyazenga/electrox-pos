<?php
/**
 * Cron Job: Ping ZIMRA Server
 * Runs periodically to report devices are online to FDMS
 * Frequency should be based on reportingFrequency received from ZIMRA (default: every 5 minutes)
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

// Get all branches with fiscalization enabled - simplified, just ping active devices
$primaryDb = Database::getPrimaryInstance();
$branches = $primaryDb->getRows(
    "SELECT b.*, fd.device_id, fd.device_serial_no, fd.device_model_name, fd.device_model_version,
            fd.last_ping, fd.reporting_frequency
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
        'status' => 'No registered branches with fiscalization enabled found',
        'success' => false,
        'error' => 'No registered branches with fiscalization enabled'
    ];
    
    // Send email
    sendPingEmail($emailRecipient, 'Device Ping - No Devices', $results);
    exit(0);
}

// Log start of ping process
error_log("PING CRON: Starting ping process at " . date('Y-m-d H:i:s') . " for " . count($branches) . " device(s)");

// Process each branch/device combination
foreach ($branches as $branchDevice) {
    $branchId = $branchDevice['id'];
    $branchName = $branchDevice['branch_name'];
    $deviceId = $branchDevice['device_id'];
    $deviceSerialNo = $branchDevice['device_serial_no'];
    $lastPing = $branchDevice['last_ping'];
    $reportingFrequency = $branchDevice['reporting_frequency'] ?? 5; // Default to 5 minutes if not set
    
    error_log("PING CRON: Processing device {$deviceId} ({$branchName})");
    
    $result = [
        'branch' => $branchName,
        'branch_id' => $branchId,
        'device_id' => $deviceId,
        'device_serial_no' => $deviceSerialNo,
        'operation' => 'Ping ZIMRA',
        'timestamp' => date('Y-m-d H:i:s'),
        'last_ping_before' => $lastPing,
        'success' => false,
        'error' => null,
        'zimra_response' => null,
        'reporting_frequency' => null,
        'retries' => 0
    ];
    
    try {
        // Initialize FiscalService
        $fiscalService = new FiscalService($branchId);
        
        // Attempt to ping with retries
        $maxRetries = 3;
        $pinged = false;
        $lastError = null;
        $pingResult = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $result['retries'] = $attempt - 1;
            
            try {
                $pingResult = $fiscalService->ping();
                $result['zimra_response'] = json_encode($pingResult, JSON_PRETTY_PRINT);
                $result['reporting_frequency'] = $pingResult['reportingFrequency'] ?? null;
                $pinged = true; // API call succeeded
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
        
        if (!$pinged) {
            $result['error'] = "Failed after {$maxRetries} attempts. Last error: {$lastError}";
            $result['success'] = false;
        } else {
            // Verify response contains required fields
            if (isset($pingResult['operationID']) && isset($pingResult['reportingFrequency'])) {
                $result['success'] = true;
                error_log("PING CRON: Device {$deviceId} pinged successfully - OperationID: {$pingResult['operationID']}, Frequency: {$pingResult['reportingFrequency']} min");
                
                // Update reporting frequency if it changed
                if (isset($pingResult['reportingFrequency'])) {
                    $newFrequency = intval($pingResult['reportingFrequency']);
                    if ($newFrequency != $reportingFrequency) {
                        try {
                            $primaryDb->update('fiscal_devices', [
                                'reporting_frequency' => $newFrequency
                            ], ['device_id' => $deviceId]);
                            error_log("PING CRON: Updated reporting frequency for device {$deviceId} from {$reportingFrequency} to {$newFrequency} minutes");
                        } catch (Exception $e) {
                            // Column might not exist, that's okay
                            error_log("PING CRON: Could not update reporting_frequency: " . $e->getMessage());
                        }
                    }
                }
            } else {
                $result['success'] = false;
                $result['error'] = "Ping response missing required fields (operationID or reportingFrequency)";
                error_log("PING CRON: Device {$deviceId} ping failed - Missing required fields in response");
            }
        }
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        $result['success'] = false;
        error_log("PING CRON: Device {$deviceId} error - " . $e->getMessage());
    }
    
    $results[] = $result;
}

// Log completion
$successCount = 0;
$failCount = 0;
foreach ($results as $result) {
    if ($result['success']) {
        $successCount++;
    } else {
        $failCount++;
    }
}
error_log("PING CRON: Completed at " . date('Y-m-d H:i:s') . " - Success: {$successCount}, Failed: {$failCount}");

// Send email with results (only if there are failures, or send summary)
$hasFailures = false;
foreach ($results as $result) {
    if (!$result['success']) {
        $hasFailures = true;
        break;
    }
}

// Send email if there are failures, or send daily summary (once per day at a specific time)
$currentHour = (int)date('H');
$sendEmail = $hasFailures || ($currentHour == 8); // Send on failures or daily at 8 AM

if ($sendEmail) {
    $subject = $hasFailures ? 'Device Ping - Failures Detected' : 'Device Ping - Daily Summary';
    sendPingEmail($emailRecipient, $subject, $results);
}

/**
 * Send email with ping operation results
 */
function sendPingEmail($to, $subject, $results) {
    try {
        $mailer = new Mailer();
        
        // Build email body
        $body = "<html><body>";
        $body .= "<h2>Device Ping Report</h2>";
        $body .= "<p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>";
        $body .= "<p><strong>Total Devices Processed:</strong> " . count($results) . "</p>";
        
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
            
            if ($result['last_ping_before']) {
                $body .= "<p><strong>Last Ping Before:</strong> {$result['last_ping_before']}</p>";
            }
            
            if ($result['reporting_frequency']) {
                $body .= "<p><strong>Reporting Frequency:</strong> {$result['reporting_frequency']} minutes</p>";
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
        
        error_log("PING CRON: Email sent to {$to}");
    } catch (Exception $e) {
        error_log("PING CRON: Failed to send email: " . $e->getMessage());
    }
}

exit(0);

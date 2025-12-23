<?php
/**
 * Monitor Fiscal Day Close Status
 * Checks status every 3 minutes until completion (FiscalDayClosed or FiscalDayCloseFailed)
 * Sends email notification when status is final
 * 
 * Usage: php monitor_fiscal_day_close.php [device_id] [branch_id]
 * Example: php monitor_fiscal_day_close.php 30200 1
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bootstrap
define('APP_PATH', __DIR__);
require_once APP_PATH . DIRECTORY_SEPARATOR . 'config.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'fiscal_service.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'mailer.php';

// Get parameters from command line
$deviceId = isset($argv[1]) ? intval($argv[1]) : 30200;
$branchId = isset($argv[2]) ? intval($argv[2]) : 1;
$emailTo = 'nyazengamd@gmail.com';
$checkInterval = 180; // 3 minutes in seconds
$maxChecks = 20; // Maximum 20 checks (60 minutes total)

echo "========================================\n";
echo "Fiscal Day Close Status Monitor\n";
echo "========================================\n";
echo "Device ID: $deviceId\n";
echo "Branch ID: $branchId\n";
echo "Email: $emailTo\n";
echo "Check Interval: 3 minutes\n";
echo "Max Checks: $maxChecks (60 minutes total)\n";
echo "========================================\n\n";

try {
    // Initialize FiscalService
    $fiscalService = new FiscalService($branchId, $deviceId);
    
    $checkCount = 0;
    $startTime = time();
    
    while ($checkCount < $maxChecks) {
        $checkCount++;
        $currentTime = date('Y-m-d H:i:s');
        $elapsedMinutes = round((time() - $startTime) / 60, 1);
        
        echo "[$currentTime] Check #$checkCount (Elapsed: {$elapsedMinutes} minutes)\n";
        echo "  Fetching fiscal day status from ZIMRA...\n";
        
        // Get status from ZIMRA
        $status = $fiscalService->getFiscalDayStatus();
        
        if (!$status || !isset($status['fiscalDayStatus'])) {
            echo "  ⚠ Warning: Could not retrieve status from ZIMRA\n";
            echo "  Waiting 3 minutes before next check...\n\n";
            sleep($checkInterval);
            continue;
        }
        
        $fiscalDayStatus = $status['fiscalDayStatus'];
        $lastFiscalDayNo = $status['lastFiscalDayNo'] ?? 'N/A';
        $lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? 'N/A';
        $operationID = $status['operationID'] ?? 'N/A';
        
        echo "  Status: $fiscalDayStatus\n";
        echo "  Fiscal Day No: $lastFiscalDayNo\n";
        echo "  Last Receipt Global No: $lastReceiptGlobalNo\n";
        echo "  Operation ID: $operationID\n";
        
        // Check if status is final (Closed or CloseFailed)
        if ($fiscalDayStatus === 'FiscalDayClosed') {
            echo "\n";
            echo "========================================\n";
            echo "✓ SUCCESS: Fiscal Day Closed!\n";
            echo "========================================\n";
            
            // Prepare email content
            $subject = "Fiscal Day Closed Successfully - Device $deviceId";
            $body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #28a745; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-top: none; }
                    .info-row { margin: 10px 0; }
                    .label { font-weight: bold; color: #495057; }
                    .value { color: #212529; }
                    .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2 style='margin: 0;'>✓ Fiscal Day Closed Successfully</h2>
                    </div>
                    <div class='content'>
                        <p>The fiscal day has been successfully closed by ZIMRA.</p>
                        
                        <div class='info-row'>
                            <span class='label'>Device ID:</span>
                            <span class='value'>$deviceId</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Branch ID:</span>
                            <span class='value'>$branchId</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Fiscal Day No:</span>
                            <span class='value'>$lastFiscalDayNo</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Last Receipt Global No:</span>
                            <span class='value'>$lastReceiptGlobalNo</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Operation ID:</span>
                            <span class='value'>$operationID</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Status:</span>
                            <span class='value'><strong style='color: #28a745;'>FiscalDayClosed</strong></span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Closed At:</span>
                            <span class='value'>$currentTime</span>
                        </div>
                        
                        " . (isset($status['fiscalDayClosed']) ? "
                        <div class='info-row'>
                            <span class='label'>Fiscal Day Closed (ZIMRA):</span>
                            <span class='value'>" . $status['fiscalDayClosed'] . "</span>
                        </div>
                        " : "") . "
                        
                        <div class='footer'>
                            <p>This is an automated notification from ELECTROX POS System.</p>
                            <p>You can now open a new fiscal day if needed.</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            // Send email
            echo "  Sending success email to $emailTo...\n";
            $mailer = new Mailer();
            $emailSent = $mailer->send($emailTo, $subject, $body, true);
            
            if ($emailSent) {
                echo "  ✓ Email sent successfully!\n";
            } else {
                echo "  ✗ Failed to send email. Check mailer configuration.\n";
            }
            
            echo "\n========================================\n";
            echo "Monitoring complete. Exiting.\n";
            echo "========================================\n";
            exit(0);
            
        } elseif ($fiscalDayStatus === 'FiscalDayCloseFailed') {
            echo "\n";
            echo "========================================\n";
            echo "✗ FAILURE: Fiscal Day Close Failed!\n";
            echo "========================================\n";
            
            $errorCode = $status['fiscalDayClosingErrorCode'] ?? 'Unknown';
            
            echo "  Error Code: $errorCode\n";
            
            // Prepare email content
            $subject = "Fiscal Day Close Failed - Device $deviceId";
            $body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #dc3545; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-top: none; }
                    .error-box { background-color: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; margin: 15px 0; }
                    .info-row { margin: 10px 0; }
                    .label { font-weight: bold; color: #495057; }
                    .value { color: #212529; }
                    .error-code { font-size: 18px; font-weight: bold; color: #dc3545; }
                    .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2 style='margin: 0;'>✗ Fiscal Day Close Failed</h2>
                    </div>
                    <div class='content'>
                        <p>The fiscal day close operation has failed. Please review the error details below.</p>
                        
                        <div class='error-box'>
                            <div class='error-code'>Error Code: $errorCode</div>
                            <p style='margin-top: 10px;'>" . getErrorCodeDescription($errorCode) . "</p>
                        </div>
                        
                        <div class='info-row'>
                            <span class='label'>Device ID:</span>
                            <span class='value'>$deviceId</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Branch ID:</span>
                            <span class='value'>$branchId</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Fiscal Day No:</span>
                            <span class='value'>$lastFiscalDayNo</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Last Receipt Global No:</span>
                            <span class='value'>$lastReceiptGlobalNo</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Operation ID:</span>
                            <span class='value'>$operationID</span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Status:</span>
                            <span class='value'><strong style='color: #dc3545;'>FiscalDayCloseFailed</strong></span>
                        </div>
                        <div class='info-row'>
                            <span class='label'>Failed At:</span>
                            <span class='value'>$currentTime</span>
                        </div>
                        
                        <div class='footer'>
                            <p><strong>Action Required:</strong> Please review the error and retry closing the fiscal day after fixing the issue.</p>
                            <p>This is an automated notification from ELECTROX POS System.</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            // Send email
            echo "  Sending failure email to $emailTo...\n";
            $mailer = new Mailer();
            $emailSent = $mailer->send($emailTo, $subject, $body, true);
            
            if ($emailSent) {
                echo "  ✓ Email sent successfully!\n";
            } else {
                echo "  ✗ Failed to send email. Check mailer configuration.\n";
            }
            
            echo "\n========================================\n";
            echo "Monitoring complete. Exiting.\n";
            echo "========================================\n";
            exit(1);
            
        } else {
            // Status is still FiscalDayCloseInitiated or other intermediate status
            echo "  Status: $fiscalDayStatus (still processing...)\n";
            echo "  Waiting 3 minutes before next check...\n\n";
            
            // Sleep for 3 minutes (180 seconds)
            sleep($checkInterval);
        }
    }
    
    // If we reach here, max checks exceeded
    echo "\n";
    echo "========================================\n";
    echo "⚠ Maximum checks reached ($maxChecks checks)\n";
    echo "========================================\n";
    echo "The fiscal day close is taking longer than expected.\n";
    echo "Last status: " . ($status['fiscalDayStatus'] ?? 'Unknown') . "\n";
    echo "Please check manually or run this script again.\n";
    echo "========================================\n";
    exit(2);
    
} catch (Exception $e) {
    echo "\n";
    echo "========================================\n";
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "========================================\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

/**
 * Get error code description
 */
function getErrorCodeDescription($errorCode) {
    $descriptions = [
        'BadCertificateSignature' => 'Bad certificate signature is used. The fiscal day signature validation failed.',
        'MissingReceipts' => 'There are missing receipts in fiscal day (Grey validation error).',
        'ReceiptsWithValidationErrors' => 'There are receipts with validation errors in fiscal day (Red validation error).',
        'CountersMismatch' => 'There are mismatches between counters. The calculated counters do not match the submitted counters.',
    ];
    
    return $descriptions[$errorCode] ?? "Unknown error code: $errorCode";
}


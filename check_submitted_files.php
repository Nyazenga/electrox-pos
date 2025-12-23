<?php
/**
 * Check Submitted Files from ZIMRA and Compare with Database
 * Fetches submitted files from ZIMRA API and compares with our database records
 * 
 * Usage: php check_submitted_files.php [device_id] [fiscal_day_no]
 * Example: php check_submitted_files.php 30200 1
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bootstrap
define('APP_PATH', __DIR__);
require_once APP_PATH . DIRECTORY_SEPARATOR . 'config.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'fiscal_service.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'certificate_storage.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'zimra_api.php';

// Get parameters from command line
$deviceId = isset($argv[1]) ? intval($argv[1]) : 30200;
$fiscalDayNo = isset($argv[2]) ? intval($argv[2]) : 1;
$branchId = isset($argv[3]) ? intval($argv[3]) : 1; // Default to HEAD OFFICE

echo "=== Check Submitted Files from ZIMRA ===\n\n";
echo "Device ID: $deviceId\n";
echo "Fiscal Day No: $fiscalDayNo\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Step 1: Load certificate
    echo "Step 1: Loading certificate...\n";
    $certData = CertificateStorage::loadCertificate($deviceId);
    if (!$certData) {
        $primaryDb = Database::getPrimaryInstance();
        $device = $primaryDb->getRow(
            "SELECT certificate_pem, private_key_pem FROM fiscal_devices WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
        if ($device && $device['certificate_pem'] && $device['private_key_pem']) {
            $privateKey = $device['private_key_pem'];
            if (strpos($privateKey, '-----BEGIN') === false) {
                $privateKey = CertificateStorage::decryptPrivateKey($privateKey);
            }
            $certData = [
                'certificate' => $device['certificate_pem'],
                'privateKey' => $privateKey
            ];
            echo "  [OK] Certificate loaded from device record\n";
        } else {
            throw new Exception("No certificate found for device $deviceId");
        }
    } else {
        echo "  [OK] Certificate loaded\n";
    }
    echo "\n";

    // Step 2: Initialize ZIMRA API
    echo "Step 2: Initializing ZIMRA API...\n";
    $api = new ZimraApi('Server', 'v1', true);
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    echo "  [OK] API initialized\n";
    echo "\n";

    // Step 3: Get status from ZIMRA first
    echo "Step 3: Getting fiscal day status from ZIMRA...\n";
    $fiscalService = new FiscalService($branchId, $deviceId);
    $zimraStatus = $fiscalService->getFiscalDayStatus();
    
    if ($zimraStatus && isset($zimraStatus['lastFiscalDayNo'])) {
        $zimraFiscalDayNo = $zimraStatus['lastFiscalDayNo'];
        echo "  ZIMRA reports fiscal day no: $zimraFiscalDayNo\n";
        if ($fiscalDayNo != $zimraFiscalDayNo) {
            echo "  WARNING: Requested fiscal day no ($fiscalDayNo) differs from ZIMRA ($zimraFiscalDayNo)\n";
            echo "  Using ZIMRA's fiscal day no: $zimraFiscalDayNo\n";
            $fiscalDayNo = $zimraFiscalDayNo;
        }
    }
    echo "\n";

    // Step 4: Get fiscal day info from database
    echo "Step 4: Getting fiscal day info from database...\n";
    $db = Database::getInstance();
    $fiscalDay = $db->getRow(
        "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND fiscal_day_no = :fiscal_day_no",
        [':branch_id' => $branchId, ':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
    );
    
    if ($fiscalDay) {
        $fiscalDayOpened = $fiscalDay['fiscal_day_opened'];
        $fiscalDayDate = date('Y-m-d', strtotime($fiscalDayOpened));
        echo "  Fiscal Day Found in Database\n";
        echo "  Fiscal Day Opened: $fiscalDayOpened\n";
        echo "  Fiscal Day Date: $fiscalDayDate\n";
        echo "  Status: " . ($fiscalDay['status'] ?? 'N/A') . "\n";
    } else {
        echo "  WARNING: Fiscal day $fiscalDayNo not found in database for device $deviceId\n";
        echo "  Will use date range from ZIMRA status or current date\n";
        // Use a date range - from 30 days ago to today
        $fiscalDayDate = date('Y-m-d', strtotime('-30 days'));
        $fiscalDayOpened = $fiscalDayDate . 'T00:00:00';
    }
    echo "\n";

    // Step 5: Get submitted files from ZIMRA
    echo "Step 5: Fetching submitted files from ZIMRA...\n";
    echo "  Endpoint: GET /Device/v1/$deviceId/SubmittedFileList\n";
    
    // Set date range: from fiscal day opened date to today
    $dateFrom = date('Y-m-d\TH:i:s', strtotime($fiscalDayOpened));
    $dateTo = date('Y-m-d\TH:i:s');
    
    $filters = [
        'FileUploadedFrom' => $dateFrom,
        'FileUploadedTill' => $dateTo,
        'Offset' => 0,
        'Limit' => 100,
        'Sort' => 'FileUploadDate',
        'Order' => 'asc'
    ];
    
    echo "  Filters:\n";
    echo "    FileUploadedFrom: $dateFrom\n";
    echo "    FileUploadedTill: $dateTo\n";
    echo "    Limit: 100\n";
    echo "\n";
    
    $submittedFiles = $api->getSubmittedFileList($deviceId, $filters);
    
    echo "  [OK] Response received\n";
    echo "\n";

    // Step 5: Display submitted files
    echo "========================================\n";
    echo "ZIMRA SUBMITTED FILES\n";
    echo "========================================\n\n";
    
    if (isset($submittedFiles['total'])) {
        echo "Total files: " . $submittedFiles['total'] . "\n";
    }
    
    if (isset($submittedFiles['rows']) && is_array($submittedFiles['rows'])) {
        echo "Files found: " . count($submittedFiles['rows']) . "\n\n";
        
        foreach ($submittedFiles['rows'] as $index => $file) {
            echo "--- File " . ($index + 1) . " ---\n";
            echo "File Name: " . ($file['fileName'] ?? 'N/A') . "\n";
            echo "Operation ID: " . ($file['operationId'] ?? 'N/A') . "\n";
            echo "Device ID: " . ($file['deviceId'] ?? 'N/A') . "\n";
            echo "Day No: " . ($file['dayNo'] ?? 'N/A') . "\n";
            echo "Fiscal Day Opened At: " . ($file['fiscalDayOpenedAt'] ?? 'N/A') . "\n";
            echo "File Upload Date: " . ($file['fileUploadDate'] ?? 'N/A') . "\n";
            echo "File Processing Date: " . ($file['fileProcessingDate'] ?? 'N/A') . "\n";
            echo "File Processing Status: " . ($file['fileProcessingStatus'] ?? 'N/A') . "\n";
            if (isset($file['fileProcessingError'])) {
                echo "File Processing Error: " . $file['fileProcessingError'] . "\n";
            }
            $hasFooter = $file['hasFooter'] ?? null;
            echo "Has Footer: " . ($hasFooter !== null ? ($hasFooter ? 'Yes' : 'No') : 'N/A') . "\n";
            echo "File Sequence: " . ($file['fileSequence'] ?? 'N/A') . "\n";
            
            $invoiceErrors = $file['invoiceWithValidationErrors'] ?? null;
            if ($invoiceErrors !== null && is_array($invoiceErrors)) {
                echo "Invoices with Validation Errors: " . count($invoiceErrors) . "\n";
                foreach ($invoiceErrors as $errorInvoice) {
                    echo "  - Receipt Counter: " . ($errorInvoice['receiptCounter'] ?? 'N/A') . "\n";
                    echo "    Receipt Global No: " . ($errorInvoice['receiptGlobalNo'] ?? 'N/A') . "\n";
                    $validationErrors = $errorInvoice['validationErrors'] ?? null;
                    if ($validationErrors !== null && is_array($validationErrors)) {
                        foreach ($validationErrors as $error) {
                            echo "    Error: " . ($error['validationErrorCode'] ?? 'N/A') . " (" . ($error['validationErrorColor'] ?? 'N/A') . ")\n";
                        }
                    }
                }
            }
            echo "\n";
        }
    } else {
        echo "No files found or invalid response format.\n";
        echo "Response: " . json_encode($submittedFiles, JSON_PRETTY_PRINT) . "\n";
    }
    
    echo "\n";

    // Step 6: Get receipts from our database
    echo "Step 6: Getting receipts from our database...\n";
    $dbReceipts = $db->getRows(
        "SELECT fr.id, fr.receipt_global_no, fr.receipt_counter, fr.receipt_id, 
                fr.submission_status, fr.receipt_date, fr.total_amount,
                fr.fiscal_day_no, fr.device_id, fr.branch_id,
                (SELECT COUNT(*) FROM fiscal_receipt_taxes frt WHERE frt.fiscal_receipt_id = fr.id) as tax_count
         FROM fiscal_receipts fr
         WHERE fr.branch_id = :branch_id AND fr.device_id = :device_id AND fr.fiscal_day_no = :fiscal_day_no
         ORDER BY fr.receipt_global_no ASC",
        [':branch_id' => $branchId, ':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
    );
    
    echo "  Receipts in database: " . count($dbReceipts) . "\n";
    
    // Count receipts by status
    $submittedCount = 0;
    $pendingCount = 0;
    $failedCount = 0;
    $withReceiptId = 0;
    $withoutReceiptId = 0;
    
    foreach ($dbReceipts as $receipt) {
        if ($receipt['submission_status'] === 'Submitted') {
            $submittedCount++;
        } elseif ($receipt['submission_status'] === 'Pending') {
            $pendingCount++;
        } elseif ($receipt['submission_status'] === 'Failed') {
            $failedCount++;
        }
        
        if (!empty($receipt['receipt_id'])) {
            $withReceiptId++;
        } else {
            $withoutReceiptId++;
        }
    }
    
    echo "  - Submitted: $submittedCount\n";
    echo "  - Pending: $pendingCount\n";
    echo "  - Failed: $failedCount\n";
    echo "  - With ZIMRA Receipt ID: $withReceiptId\n";
    echo "  - Without ZIMRA Receipt ID: $withoutReceiptId\n";
    echo "\n";

    // Step 7: Compare and analyze
    echo "========================================\n";
    echo "COMPARISON SUMMARY\n";
    echo "========================================\n\n";
    
    echo "Database Receipts (for fiscal day $fiscalDayNo):\n";
    foreach ($dbReceipts as $receipt) {
        $status = $receipt['submission_status'];
        $receiptId = $receipt['receipt_id'] ?? 'NULL';
        $taxCount = $receipt['tax_count'] ?? 0;
        
        $statusIcon = '✓';
        $statusColor = '';
        if ($status === 'Submitted' && !empty($receiptId)) {
            $statusIcon = '✓';
        } elseif ($status === 'Failed') {
            $statusIcon = '✗';
        } elseif ($status === 'Pending') {
            $statusIcon = '⏳';
        } else {
            $statusIcon = '⚠';
        }
        
        echo "  $statusIcon Receipt Global No: " . $receipt['receipt_global_no'] . 
             ", Counter: " . $receipt['receipt_counter'] . 
             ", Status: " . $status . 
             ", ZIMRA Receipt ID: " . $receiptId . 
             ", Tax Records: " . $taxCount . "\n";
    }
    
    echo "\n";
    echo "ANALYSIS:\n";
    echo "---------\n";
    if ($withoutReceiptId > 0) {
        echo "⚠ WARNING: $withoutReceiptId receipt(s) do not have a ZIMRA receipt_id.\n";
        echo "  These receipts may not have been successfully submitted to ZIMRA.\n";
        echo "  They will NOT be included in ZIMRA's fiscal day counters.\n";
        echo "  This could cause a signature mismatch!\n\n";
    }
    
    if ($submittedCount > 0 && $withReceiptId === $submittedCount) {
        echo "✓ All submitted receipts have ZIMRA receipt_id.\n";
    }
    
    echo "\n";
    echo "NOTE: SubmittedFileList endpoint is for OFFLINE mode (file submissions).\n";
    echo "For ONLINE mode (which you're using), receipts are submitted individually.\n";
    echo "The receipt_id field in our database indicates if ZIMRA accepted the receipt.\n";
    echo "\n";
    echo "If receipts are missing receipt_id, they were not accepted by ZIMRA and\n";
    echo "will cause a signature mismatch when closing the fiscal day.\n";
    
    echo "\n=== Check Complete ===\n";

} catch (Exception $e) {
    echo "\n[ERROR]: " . $e->getMessage() . "\n";
    $trace = $e->getTraceAsString();
    if (!empty($trace)) {
        echo "\nStack trace:\n" . $trace . "\n";
    }
    exit(1);
}


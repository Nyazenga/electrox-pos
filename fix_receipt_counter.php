<?php
/**
 * Fix receipt counter by checking ZIMRA status and trying different counters
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$deviceId = 30199;
$branchId = 1;

echo "========================================\n";
echo "FIXING RECEIPT COUNTER ISSUE\n";
echo "========================================\n\n";

try {
    $db = Database::getPrimaryInstance();
    $fiscalService = new FiscalService($branchId);
    
    // Get current fiscal day
    $fiscalDay = $db->getRow(
        "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened'",
        [':branch_id' => $branchId, ':device_id' => $deviceId]
    );
    
    if (!$fiscalDay) {
        echo "No open fiscal day. Opening one...\n";
        $fiscalService->openFiscalDay();
        $fiscalDay = $db->getRow(
            "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened'",
            [':branch_id' => $branchId, ':device_id' => $deviceId]
        );
    }
    
    echo "Fiscal Day No: " . ($fiscalDay['fiscal_day_no'] ?? 'N/A') . "\n\n";
    
    // Get ZIMRA status
    echo "Getting ZIMRA status...\n";
    $status = $fiscalService->getFiscalDayStatus();
    echo "ZIMRA Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n\n";
    
    // Get all receipts from database for this fiscal day
    echo "Checking database for receipts in fiscal day {$fiscalDay['fiscal_day_no']}...\n";
    $receipts = $db->getRows(
        "SELECT receipt_counter, receipt_global_no, receipt_id, invoice_no, submission_status, submitted_at 
         FROM fiscal_receipts 
         WHERE device_id = :device_id AND fiscal_day_no = :fiscal_day_no 
         ORDER BY receipt_counter DESC, receipt_global_no DESC",
        [':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDay['fiscal_day_no']]
    );
    
    echo "Found " . count($receipts) . " receipts in database:\n";
    foreach ($receipts as $r) {
        echo "  Counter: " . ($r['receipt_counter'] ?? 'NULL') . 
             ", Global: " . ($r['receipt_global_no'] ?? 'NULL') . 
             ", ReceiptID: " . ($r['receipt_id'] ?? 'NULL') . 
             ", Status: " . ($r['submission_status'] ?? 'NULL') . 
             ", Invoice: " . ($r['invoice_no'] ?? 'NULL') . "\n";
    }
    
    // Calculate next counter
    $lastCounter = 0;
    if (!empty($receipts)) {
        // Get the highest counter that has a receipt_id (successfully submitted)
        foreach ($receipts as $r) {
            if (!empty($r['receipt_id']) && isset($r['receipt_counter'])) {
                $lastCounter = max($lastCounter, intval($r['receipt_counter']));
            }
        }
    }
    
    $nextCounter = $lastCounter + 1;
    echo "\nLast successful counter: $lastCounter\n";
    echo "Next counter to use: $nextCounter\n\n";
    
    // Try submitting with the calculated counter
    echo "Attempting to submit receipt with counter = $nextCounter...\n";
    
    $receiptData = [
        'deviceID' => $deviceId,
        'receiptType' => 'FISCALINVOICE',
        'receiptCurrency' => 'USD',
        'receiptCounter' => $nextCounter, // Use calculated counter
        'receiptGlobalNo' => ($status['lastReceiptGlobalNo'] ?? 0) + 1,
        'invoiceNo' => 'FIX-COUNTER-' . date('YmdHis'),
        'receiptDate' => date('Y-m-d\TH:i:s'),
        'receiptLinesTaxInclusive' => true,
        'receiptLines' => [
            [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => 1,
                'receiptLineHSCode' => '04021099',
                'receiptLineName' => 'Test Item',
                'receiptLinePrice' => 10.00,
                'receiptLineQuantity' => 1,
                'receiptLineTotal' => 10.00,
                'taxCode' => 'C',
                'taxPercent' => 0,
                'taxID' => 2
            ]
        ],
        'receiptTaxes' => [
            [
                'taxID' => 2,
                'taxCode' => 'C',
                'taxPercent' => 0,
                'taxAmount' => 0,
                'salesAmountWithTax' => 10.00
            ]
        ],
        'receiptTotal' => 10.00,
        'receiptPayments' => [
            [
                'moneyTypeCode' => 'Cash',
                'paymentAmount' => 10.00
            ]
        ]
    ];
    
    $result = $fiscalService->submitReceipt(0, $receiptData);
    
    echo "\n========================================\n";
    echo "RESULT:\n";
    echo "========================================\n";
    echo "Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
    
    if (!empty($result['validationErrors'])) {
        echo "\nValidation Errors:\n";
        foreach ($result['validationErrors'] as $error) {
            $code = $error['validationErrorCode'] ?? 'N/A';
            $color = $error['validationErrorColor'] ?? 'N/A';
            echo "  - $code ($color)\n";
            
            if ($code === 'RCPT011') {
                echo "\n⚠ RCPT011 still occurring. Options:\n";
                echo "  1. Close fiscal day and open a new one\n";
                echo "  2. Try incrementing counter manually\n";
                echo "  3. Reset device (if needed)\n";
            }
        }
    } else {
        echo "\n✓ NO VALIDATION ERRORS - RECEIPT ACCEPTED!\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    
    if (strpos($e->getMessage(), 'RCPT011') !== false) {
        echo "\n⚠ RCPT011 error. Let's try closing the fiscal day and opening a new one...\n";
        try {
            echo "Closing fiscal day...\n";
            $fiscalService->closeFiscalDay();
            echo "✓ Fiscal day closed\n";
            
            echo "Opening new fiscal day...\n";
            $fiscalService->openFiscalDay();
            echo "✓ New fiscal day opened\n";
            
            echo "\nNow try submitting again with counter = 1\n";
        } catch (Exception $closeError) {
            echo "✗ Could not close/open fiscal day: " . $closeError->getMessage() . "\n";
            echo "\n⚠ May need to reset devices if fiscal day is stuck\n";
        }
    }
}

echo "\n========================================\n";


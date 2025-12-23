<?php
/**
 * Simple test script to submit a receipt - matches interface flow exactly
 * This will help us compare what works vs what doesn't
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$deviceId = 30199; // Use device 30199
$branchId = 1; // Adjust if needed

try {
    echo "========================================\n";
    echo "SIMPLE RECEIPT TEST - Device $deviceId\n";
    echo "========================================\n\n";
    
    // Initialize fiscal service (same as interface)
    $fiscalService = new FiscalService($branchId);
    
    // Get fiscal day status
    $status = $fiscalService->getFiscalDayStatus();
    echo "Fiscal Day Status: " . ($status['status'] ?? 'Unknown') . "\n";
    echo "Fiscal Day No: " . ($status['fiscalDayNo'] ?? 'Unknown') . "\n\n";
    
    // Open fiscal day if needed
    if (($status['status'] ?? '') !== 'FiscalDayOpened') {
        echo "Opening fiscal day...\n";
        $openResult = $fiscalService->openFiscalDay();
        echo "Fiscal Day Opened: " . ($openResult['fiscalDayNo'] ?? 'Failed') . "\n\n";
    }
    
    // Get current receipt counter
    $db = Database::getPrimaryInstance();
    $lastReceipt = $db->getRow(
        "SELECT receipt_counter, receipt_global_no FROM fiscal_receipts 
         WHERE device_id = :device_id
         ORDER BY receipt_global_no DESC LIMIT 1",
        [':device_id' => $deviceId]
    );
    
    $receiptCounter = $lastReceipt ? ($lastReceipt['receipt_counter'] + 1) : 1;
    $receiptGlobalNo = $lastReceipt ? ($lastReceipt['receipt_global_no'] + 1) : 1;
    
    echo "Receipt Counter: $receiptCounter\n";
    echo "Receipt Global No: $receiptGlobalNo\n\n";
    
    // Build simple receipt data (matching interface format)
    $receiptData = [
        'deviceID' => $deviceId,
        'receiptType' => 'FiscalInvoice',
        'receiptCurrency' => 'USD',
        'receiptCounter' => $receiptCounter,
        'receiptGlobalNo' => $receiptGlobalNo,
        'receiptDate' => date('Y-m-d\TH:i:s'),
        'invoiceNo' => 'TEST-' . date('YmdHis'),
        'receiptTotal' => 10.0,
        'receiptLinesTaxInclusive' => true,
        'receiptLines' => [
            [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => 1,
                'receiptLineHSCode' => '00000000',
                'receiptLineName' => 'Test Item',
                'receiptLinePrice' => 10.0,
                'receiptLineQuantity' => 1.0,
                'receiptLineTotal' => 10.0,
                'taxID' => 517,
                'taxPercent' => 15.5
            ]
        ],
        'receiptTaxes' => [
            [
                'taxPercent' => 15.5,
                'taxID' => 517,
                'taxAmount' => 1.34,
                'salesAmountWithTax' => 10.0
            ]
        ],
        'receiptPayments' => [
            [
                'moneyTypeCode' => 0, // Integer: 0 = Cash
                'paymentAmount' => 10.0
            ]
        ],
        'receiptPrintForm' => 'InvoiceA4'
    ];
    
    // Log receipt data before submission
    $logFile = APP_PATH . '/logs/test_script_receipt_data_log.txt';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "========================================\n";
    $logMessage .= "[$timestamp] TEST SCRIPT receiptData - BEFORE submitReceipt() CALL\n";
    $logMessage .= "========================================\n";
    $logMessage .= "COMPLETE receiptData JSON:\n";
    $logMessage .= json_encode($receiptData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $logMessage .= "========================================\n\n";
    @file_put_contents($logFile, $logMessage, FILE_APPEND);
    echo "Logged receiptData to: $logFile\n\n";
    
    // Submit receipt
    echo "Submitting receipt...\n";
    $result = $fiscalService->submitReceipt(0, $receiptData);
    
    echo "\n========================================\n";
    echo "RESULT:\n";
    echo "========================================\n";
    echo "Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
    echo "Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
    echo "Verification Code: " . ($result['verificationCode'] ?? 'N/A') . "\n";
    echo "Success: " . (isset($result['receiptID']) ? 'YES ✓' : 'NO ✗') . "\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "\n========================================\n";
    echo "ERROR:\n";
    echo "========================================\n";
    echo $e->getMessage() . "\n";
    echo "========================================\n";
}


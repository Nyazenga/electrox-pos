<?php
/**
 * Quick test of receipt submission with moneyTypeCode fix
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
echo "TESTING RECEIPT WITH moneyTypeCode FIX\n";
echo "========================================\n\n";

try {
    $fiscalService = new FiscalService($branchId);
    
    // Prepare receipt data
    $receiptData = [
        'deviceID' => $deviceId,
        'receiptType' => 'FiscalInvoice',
        'receiptCurrency' => 'USD',
        'receiptCounter' => 1,
        'receiptGlobalNo' => 1,
        'invoiceNo' => 'TEST-FIX-' . date('YmdHis'),
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
                'moneyTypeCode' => 'Cash', // Will be converted to 0
                'paymentAmount' => 10.00
            ]
        ]
    ];
    
    echo "Submitting receipt...\n";
    echo "moneyTypeCode before: " . $receiptData['receiptPayments'][0]['moneyTypeCode'] . "\n";
    
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
            
            if ($code === 'RCPT020') {
                echo "    ⚠ RCPT020 (Invalid Signature) - signature issue persists!\n";
            } elseif ($code === 'RCPT011') {
                echo "    ℹ RCPT011 (Counter issue) - expected for first receipt\n";
            }
        }
    } else {
        echo "\n✓ NO VALIDATION ERRORS - RECEIPT ACCEPTED!\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    
    if (strpos($e->getMessage(), 'RCPT020') !== false) {
        echo "\n⚠ RCPT020 error detected in exception message\n";
    }
}

echo "\n========================================\n";


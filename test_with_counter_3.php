<?php
/**
 * Test with receiptCounter = 3 (since ZIMRA shows lastReceiptGlobalNo = 2)
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
echo "TESTING WITH RECEIPT COUNTER = 3\n";
echo "========================================\n\n";

try {
    $fiscalService = new FiscalService($branchId);
    
    // Try to open fiscal day if not open
    echo "Checking/opening fiscal day...\n";
    try {
        $fiscalService->openFiscalDay();
        echo "✓ Fiscal day is open\n";
    } catch (Exception $e) {
        echo "⚠ " . $e->getMessage() . "\n";
    }
    
    // Get status to see last receipt
    $status = $fiscalService->getFiscalDayStatus();
    echo "\nZIMRA Status:\n";
    echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "  Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "  Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n\n";
    
    // Try different counters
    $countersToTry = [1, 2, 3, 4, 5];
    
    foreach ($countersToTry as $tryCounter) {
        echo "========================================\n";
        echo "Trying receiptCounter = $tryCounter\n";
        echo "========================================\n";
    
        $receiptData = [
            'deviceID' => $deviceId,
            'receiptType' => 'FISCALINVOICE',
            'receiptCurrency' => 'USD',
            'receiptCounter' => $tryCounter,
            'receiptGlobalNo' => ($status['lastReceiptGlobalNo'] ?? 0) + 1,
            'invoiceNo' => 'TEST-COUNTER-' . $tryCounter . '-' . date('YmdHis'),
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
    
        echo "Submitting receipt...\n";
        try {
            $result = $fiscalService->submitReceipt(0, $receiptData);
            
            echo "\nResult:\n";
            echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
            
            if (!empty($result['validationErrors'])) {
                $hasRCPT011 = false;
                foreach ($result['validationErrors'] as $error) {
                    $code = $error['validationErrorCode'] ?? 'N/A';
                    $color = $error['validationErrorColor'] ?? 'N/A';
                    echo "  Validation Error: $code ($color)\n";
                    if ($code === 'RCPT011') {
                        $hasRCPT011 = true;
                    }
                }
                
                if (!$hasRCPT011) {
                    echo "\n✓ SUCCESS! Counter $tryCounter worked - NO RCPT011!\n";
                    echo "   (May have other errors, but counter is correct)\n";
                    break;
                } else {
                    echo "  ✗ Counter $tryCounter: RCPT011 (wrong counter)\n\n";
                }
            } else {
                echo "\n✓✓✓ PERFECT! Counter $tryCounter worked - NO VALIDATION ERRORS!\n";
                break;
            }
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'RCPT011') !== false) {
                echo "  ✗ Counter $tryCounter: RCPT011 error\n\n";
            } else {
                echo "  ✗ Counter $tryCounter: " . $e->getMessage() . "\n\n";
            }
        }
        
        // Small delay
        usleep(500000);
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";


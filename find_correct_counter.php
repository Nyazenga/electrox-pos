<?php
/**
 * Find the correct receipt counter by trying different values
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
echo "FINDING CORRECT RECEIPT COUNTER\n";
echo "========================================\n\n";

try {
    $fiscalService = new FiscalService($branchId);
    
    // Get current fiscal day
    $fiscalDay = $fiscalService->db->getRow(
        "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened'",
        [':branch_id' => $branchId, ':device_id' => $deviceId]
    );
    
    if (!$fiscalDay) {
        throw new Exception("No open fiscal day");
    }
    
    echo "Fiscal Day No: " . ($fiscalDay['fiscal_day_no'] ?? 'N/A') . "\n";
    
    // Get ZIMRA status
    $status = $fiscalService->getFiscalDayStatus();
    echo "ZIMRA Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n\n";
    
    // Try counters from 1 to 10
    $foundCounter = null;
    
    for ($tryCounter = 1; $tryCounter <= 10; $tryCounter++) {
        echo "Trying receiptCounter = $tryCounter...\n";
        
        $receiptData = [
            'deviceID' => $deviceId,
            'receiptType' => 'FISCALINVOICE',
            'receiptCurrency' => 'USD',
            'receiptCounter' => $tryCounter,
            'receiptGlobalNo' => ($status['lastReceiptGlobalNo'] ?? 0) + 1,
            'invoiceNo' => 'TRY-COUNTER-' . $tryCounter . '-' . date('YmdHis'),
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
        
        try {
            $result = $fiscalService->submitReceipt(0, $receiptData);
            
            // Check for validation errors
            if (empty($result['validationErrors'])) {
                echo "\n✓ SUCCESS! Counter $tryCounter worked - NO VALIDATION ERRORS!\n";
                echo "Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
                $foundCounter = $tryCounter;
                break;
            } else {
                $hasRCPT011 = false;
                foreach ($result['validationErrors'] as $error) {
                    if (($error['validationErrorCode'] ?? '') === 'RCPT011') {
                        $hasRCPT011 = true;
                        break;
                    }
                }
                
                if (!$hasRCPT011) {
                    echo "  ⚠ Counter $tryCounter: No RCPT011, but has other errors: ";
                    foreach ($result['validationErrors'] as $error) {
                        echo ($error['validationErrorCode'] ?? 'N/A') . " ";
                    }
                    echo "\n";
                    // This might be the right counter but with other issues
                } else {
                    echo "  ✗ Counter $tryCounter: RCPT011 (wrong counter)\n";
                }
            }
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'RCPT011') !== false) {
                echo "  ✗ Counter $tryCounter: RCPT011 error\n";
            } else {
                echo "  ✗ Counter $tryCounter: " . $e->getMessage() . "\n";
            }
        }
        
        // Small delay between attempts
        usleep(500000); // 0.5 seconds
    }
    
    if ($foundCounter) {
        echo "\n========================================\n";
        echo "FOUND CORRECT COUNTER: $foundCounter\n";
        echo "========================================\n";
        echo "Use receiptCounter = $foundCounter for the next receipt\n";
    } else {
        echo "\n========================================\n";
        echo "COULD NOT FIND CORRECT COUNTER\n";
        echo "========================================\n";
        echo "Options:\n";
        echo "1. Close fiscal day and open a new one\n";
        echo "2. Reset the device (contact ZIMRA)\n";
        echo "3. Check ZIMRA portal for actual receipt count\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";


<?php
/**
 * Test and log EXACT payload being sent to ZIMRA
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$deviceId = 30199;
$branchId = 1;

echo "================================================================================\n";
echo "PHP - EXACT PAYLOAD LOGGING\n";
echo "================================================================================\n\n";

try {
    $fiscalService = new FiscalService($branchId);
    
    // Create test receipt data - EXACTLY matching what Python sends
    $receiptData = [
        'deviceID' => $deviceId,
        'receiptType' => 'FISCALINVOICE',
        'receiptCurrency' => 'USD',
        'receiptCounter' => 1,
        'receiptGlobalNo' => 1,
        'invoiceNo' => 'PHP-LOG-' . date('YmdHis'),
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
    
    echo "1. INPUT RECEIPT DATA:\n";
    echo json_encode($receiptData, JSON_PRETTY_PRINT) . "\n\n";
    
    // Patch the API class to log exact payload
    // We need to intercept what's sent to ZIMRA
    // Let's modify the submitReceipt to log before sending
    
    // Get the API instance
    $reflection = new ReflectionClass($fiscalService);
    $apiProperty = $reflection->getProperty('api');
    $apiProperty->setAccessible(true);
    $api = $apiProperty->getValue($fiscalService);
    
    // Create a wrapper class to intercept the request
    class LoggingZimraApi extends ZimraApi {
        public function submitReceipt($deviceID, $receipt) {
            // Wrap receipt in 'Receipt' field (capital R) to match Python library format
            $requestBody = [
                'Receipt' => $receipt
            ];
            
            echo "\n================================================================================\n";
            echo "5. EXACT JSON PAYLOAD BEING SENT TO ZIMRA:\n";
            echo "================================================================================\n";
            echo json_encode($requestBody, JSON_PRETTY_PRINT) . "\n";
            echo "================================================================================\n\n";
            
            // Also show as compact JSON (what actually gets sent)
            $compactJson = json_encode($requestBody, JSON_UNESCAPED_SLASHES);
            echo "6. COMPACT JSON (actual bytes sent):\n";
            echo $compactJson . "\n";
            echo "\n   Length: " . strlen($compactJson) . " bytes\n\n";
            
            // Call parent method
            return parent::submitReceipt($deviceID, $receipt);
        }
    }
    
    // Replace the API instance with our logging version
    $loggingApi = new LoggingZimraApi('Server', 'v1', true);
    
    // Copy certificate from original API
    $originalReflection = new ReflectionClass($api);
    $certMethod = $originalReflection->getMethod('hasCertificate');
    if ($certMethod->invoke($api)) {
        // Get certificate and key
        $device = $fiscalService->db->getRow(
            "SELECT certificate_pem, private_key_pem FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id",
            [':device_id' => $deviceId, ':branch_id' => $branchId]
        );
        if ($device && $device['certificate_pem'] && $device['private_key_pem']) {
            $loggingApi->setCertificate($device['certificate_pem'], $device['private_key_pem']);
        }
    }
    
    // Replace API instance
    $apiProperty->setValue($fiscalService, $loggingApi);
    
    echo "2. PREPARING RECEIPT (calculating taxes, signing)...\n";
    echo "   (This happens inside submitReceipt)\n\n";
    
    echo "7. SUBMITTING RECEIPT...\n";
    $result = $fiscalService->submitReceipt(0, $receiptData);
    
    echo "\n================================================================================\n";
    echo "8. ZIMRA RESPONSE:\n";
    echo "================================================================================\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    echo "================================================================================\n\n";
    
    if (!empty($result['validationErrors'])) {
        echo "⚠ VALIDATION ERRORS:\n";
        foreach ($result['validationErrors'] as $error) {
            echo "  - " . ($error['validationErrorCode'] ?? 'N/A') . " (" . ($error['validationErrorColor'] ?? 'N/A') . ")\n";
        }
    } else {
        echo "✓ NO VALIDATION ERRORS!\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    if (strpos($e->getMessage(), 'RCPT020') !== false) {
        echo "\n⚠ RCPT020 (Invalid Signature) error detected!\n";
    }
}


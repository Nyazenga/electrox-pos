<?php
/**
 * Re-register device and test with moneyTypeCode fix
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/zimra_logger.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$deviceId = 30199;
$branchId = 1;

echo "========================================\n";
echo "RE-REGISTERING DEVICE $deviceId AND TESTING\n";
echo "========================================\n\n";

try {
    // Initialize logger
    ZimraLogger::init();
    
    // Get device from database
    $db = Database::getPrimaryInstance();
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id",
        [':device_id' => $deviceId, ':branch_id' => $branchId]
    );
    
    if (!$device) {
        throw new Exception("Device $deviceId not found in database");
    }
    
    echo "Step 1: Re-registering device $deviceId...\n";
    
    // Delete existing certificate from database to force re-registration
    try {
        $db->executeQuery(
            "DELETE FROM fiscal_certificates WHERE device_id = :device_id AND branch_id = :branch_id",
            [':device_id' => $deviceId, ':branch_id' => $branchId]
        );
        echo "  ✓ Deleted existing certificate from database\n";
    } catch (Exception $e) {
        echo "  ⚠ Could not delete certificate: " . $e->getMessage() . "\n";
    }
    
    // Also update device to mark as not registered
    try {
        $db->executeQuery(
            "UPDATE fiscal_devices SET is_registered = 0, certificate_pem = NULL, private_key_pem = NULL WHERE device_id = :device_id AND branch_id = :branch_id",
            [':device_id' => $deviceId, ':branch_id' => $branchId]
        );
        echo "  ✓ Marked device as not registered\n";
    } catch (Exception $e) {
        echo "  ⚠ Could not update device: " . $e->getMessage() . "\n";
    }
    
    // Register device
    $fiscalService = new FiscalService($branchId);
    $registerResponse = $fiscalService->registerDevice();
    
    if (isset($registerResponse['certificate'])) {
        echo "  ✓ Device registered successfully!\n";
        echo "    Certificate length: " . strlen($registerResponse['certificate']) . " bytes\n";
        echo "    Operation ID: " . ($registerResponse['operationID'] ?? 'N/A') . "\n";
    } else {
        throw new Exception("Registration failed: " . json_encode($registerResponse));
    }
    
    // Wait a moment for certificate to be processed
    sleep(2);
    
    echo "\nStep 2: Getting configuration...\n";
    try {
        $config = $fiscalService->syncConfiguration();
        echo "  ✓ Configuration synced\n";
    } catch (Exception $e) {
        echo "  ⚠ Configuration sync failed: " . $e->getMessage() . "\n";
    }
    
    echo "\nStep 3: Getting fiscal day status...\n";
    try {
        $status = $fiscalService->getFiscalDayStatus();
        echo "  Status: " . ($status['status'] ?? 'N/A') . "\n";
        echo "  Fiscal Day No: " . ($status['fiscalDayNo'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "  ⚠ Status check failed: " . $e->getMessage() . "\n";
    }
    
    echo "\nStep 4: Opening fiscal day...\n";
    try {
        $openResult = $fiscalService->openFiscalDay();
        echo "  ✓ Fiscal day opened\n";
        echo "    Fiscal Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "  ⚠ Open fiscal day failed: " . $e->getMessage() . "\n";
        // Continue anyway - day might already be open
    }
    
    echo "\nStep 5: Submitting test receipt with moneyTypeCode fix...\n";
    
    // Prepare receipt data
    $receiptTotal = 10.00;
    $receiptData = [
        'deviceID' => $deviceId,
        'receiptType' => 'FiscalInvoice',
        'receiptCurrency' => 'USD',
        'receiptCounter' => 1,
        'receiptGlobalNo' => 1,
        'invoiceNo' => 'TEST-' . $deviceId . '-' . date('YmdHis'),
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
        'receiptTotal' => $receiptTotal,
        'receiptPayments' => [
            [
                'moneyTypeCode' => 'Cash', // Will be converted to 0 in submitReceipt
                'paymentAmount' => $receiptTotal
            ]
        ]
    ];
    
    try {
        $submitResult = $fiscalService->submitReceipt(0, $receiptData);
        
        echo "\n========================================\n";
        echo "RECEIPT SUBMISSION RESULT:\n";
        echo "========================================\n";
        echo "Receipt ID: " . ($submitResult['receiptID'] ?? 'N/A') . "\n";
        echo "Server Date: " . ($submitResult['serverDate'] ?? 'N/A') . "\n";
        
        if (!empty($submitResult['validationErrors'])) {
            echo "\n⚠ VALIDATION ERRORS:\n";
            foreach ($submitResult['validationErrors'] as $error) {
                echo "  - Code: " . ($error['validationErrorCode'] ?? 'N/A') . ", Color: " . ($error['validationErrorColor'] ?? 'N/A') . "\n";
            }
        } else {
            echo "\n✓ NO VALIDATION ERRORS - RECEIPT ACCEPTED!\n";
        }
        
        if (isset($submitResult['receiptServerSignature']['hash'])) {
            echo "\nHash Comparison:\n";
            echo "  ZIMRA Hash: " . substr($submitResult['receiptServerSignature']['hash'], 0, 30) . "...\n";
        }
        
        echo "\n========================================\n";
        
    } catch (Exception $e) {
        echo "  ✗ Receipt submission failed: " . $e->getMessage() . "\n";
        
        // Check if it's a validation error
        if (strpos($e->getMessage(), 'RCPT020') !== false) {
            echo "\n⚠ RCPT020 (Invalid Signature) error still occurring!\n";
        } elseif (strpos($e->getMessage(), 'RCPT011') !== false) {
            echo "\n⚠ RCPT011 (Counter issue) - this is expected for first receipt\n";
        }
    }
    
    echo "\n========================================\n";
    echo "TEST COMPLETE\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}


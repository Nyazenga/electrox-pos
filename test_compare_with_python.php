<?php
/**
 * Test to compare our signature calculation with what Python would generate
 * for the exact same receipt data
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_signature.php';

echo "========================================\n";
echo "COMPARING SIGNATURE WITH PYTHON\n";
echo "========================================\n\n";

// Exact receipt data from interface
$receiptData = [
    'deviceID' => 30199,
    'receiptType' => 'FiscalInvoice',
    'receiptCurrency' => 'USD',
    'receiptCounter' => 3,
    'receiptGlobalNo' => 3,
    'receiptDate' => '2025-12-21T16:12:14',
    'invoiceNo' => '1-251221-0010',
    'receiptTotal' => 3.0,
    'receiptLinesTaxInclusive' => true,
    'receiptLines' => [
        [
            'receiptLineType' => 'Sale',
            'receiptLineNo' => 1,
            'receiptLineHSCode' => '00000000',
            'receiptLineName' => 'Baked Beans 410g',
            'receiptLinePrice' => 3.0,
            'receiptLineQuantity' => 1.0,
            'receiptLineTotal' => 3.0,
            'taxID' => 517,
            'taxPercent' => 15.5
        ]
    ],
    'receiptTaxes' => [
        [
            'taxPercent' => 15.5,
            'taxID' => 517,
            'taxAmount' => 0.4,
            'salesAmountWithTax' => 3.0
        ]
    ],
    'receiptPayments' => [
        [
            'moneyTypeCode' => 0,
            'paymentAmount' => 3.0
        ]
    ],
    'receiptPrintForm' => 'InvoiceA4'
];

$previousReceiptHash = 'e1iNWMdN7DZFjcavfapDen0I7ubFfF5rQzpxutGSWHU=';

echo "Receipt Data:\n";
echo "  Counter: {$receiptData['receiptCounter']}\n";
echo "  Global No: {$receiptData['receiptGlobalNo']}\n";
echo "  Total: {$receiptData['receiptTotal']}\n";
echo "  Previous Hash: " . substr($previousReceiptHash, 0, 30) . "...\n\n";

// Generate signature
$deviceSignature = ZimraSignature::generateReceiptDeviceSignature($receiptData, $previousReceiptHash);

echo "PHP Signature String: " . $deviceSignature['signatureString'] . "\n";
echo "PHP Hash: " . $deviceSignature['hash'] . "\n";
echo "Python Hash (expected): tRfMPGi30+q6lMv1z1Z4+dtAwNLBDtyjsKAU/qicwOk=\n";
echo "ZIMRA Hash (actual): alEf0IYMEy1e/ZrjFaiWInknQcyeNDVy4bEwKp8HL2A=\n\n";

// Check if our hash matches Python
if ($deviceSignature['hash'] === 'tRfMPGi30+q6lMv1z1Z4+dtAwNLBDtyjsKAU/qicwOk=') {
    echo "✓ Our hash matches Python's hash\n";
} else {
    echo "✗ Our hash does NOT match Python's hash\n";
}

// Check if our hash matches ZIMRA
if ($deviceSignature['hash'] === 'alEf0IYMEy1e/ZrjFaiWInknQcyeNDVy4bEwKp8HL2A=') {
    echo "✓ Our hash matches ZIMRA's hash\n";
} else {
    echo "✗ Our hash does NOT match ZIMRA's hash\n";
    echo "\nThis means ZIMRA is calculating the signature from different values.\n";
    echo "Possible causes:\n";
    echo "1. ZIMRA is using different values from the JSON payload\n";
    echo "2. ZIMRA is using a different previousReceiptHash\n";
    echo "3. ZIMRA is using a different signature string format\n";
}

echo "\n========================================\n";
echo "DONE\n";
echo "========================================\n";


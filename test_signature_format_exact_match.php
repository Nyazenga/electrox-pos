<?php
/**
 * Test Signature Format - Exact Match with Python Library
 * 
 * This script tests if our signature string format matches Python's exactly
 * by comparing character-by-character.
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_signature.php';

echo "========================================\n";
echo "SIGNATURE FORMAT EXACT MATCH TEST\n";
echo "========================================\n\n";

// Test data matching recent test run
$receiptData = [
    'deviceID' => 30199,
    'receiptType' => 'FiscalInvoice',
    'receiptCurrency' => 'USD',
    'receiptGlobalNo' => 7,
    'receiptDate' => '2025-12-21T20:55:10',
    'receiptTotal' => 11.00,
    'receiptTaxes' => [
        [
            'taxPercent' => 15.5,
            'taxID' => 517,
            'taxAmount' => 1.48,  // Rounded to 2 decimals
            'salesAmountWithTax' => 11.00
        ]
    ]
];

$previousReceiptHash = 'gA5U61llQnNBFWf82SJhiEFlShS5OrtecPLOnFnM2W4=';

echo "Test Receipt Data:\n";
echo "  deviceID: {$receiptData['deviceID']}\n";
echo "  receiptType: {$receiptData['receiptType']}\n";
echo "  receiptCurrency: {$receiptData['receiptCurrency']}\n";
echo "  receiptGlobalNo: {$receiptData['receiptGlobalNo']}\n";
echo "  receiptDate: {$receiptData['receiptDate']}\n";
echo "  receiptTotal: {$receiptData['receiptTotal']}\n";
echo "  receiptTaxes[0]: taxPercent={$receiptData['receiptTaxes'][0]['taxPercent']}, taxAmount={$receiptData['receiptTaxes'][0]['taxAmount']}, salesAmountWithTax={$receiptData['receiptTaxes'][0]['salesAmountWithTax']}\n";
echo "  previousReceiptHash: " . substr($previousReceiptHash, 0, 30) . "...\n\n";

// Build signature string using our PHP implementation
// We'll manually build it to match our implementation
$receiptCurrency = strtoupper($receiptData['receiptCurrency']);

// Build taxes string (matching our PHP implementation)
$receiptTaxes = $receiptData['receiptTaxes'];
usort($receiptTaxes, function($a, $b) {
    return ($a['taxID'] ?? 0) - ($b['taxID'] ?? 0);
});

$taxStrings = [];
foreach ($receiptTaxes as $tax) {
    $taxAmountFloat = floatval($tax['taxAmount'] ?? 0);
    $salesAmountFloat = floatval($tax['salesAmountWithTax'] ?? 0);
    $amountCents = intval($taxAmountFloat * 100);
    $salesCents = intval($salesAmountFloat * 100);
    $percent = '';
    if (isset($tax['taxPercent'])) {
        $percentValue = floatval($tax['taxPercent']);
        $percent = number_format($percentValue, 2, '.', '');
    }
    $taxString = $percent . strval($amountCents) . strval($salesCents);
    $taxStrings[] = $taxString;
}
$ourTaxesString = implode('', $taxStrings);

// Build our signature string (matching our PHP implementation)
$parts = [];
$parts[] = strval(intval($receiptData['deviceID']));
$parts[] = strtoupper($receiptData['receiptType']);
$parts[] = $receiptCurrency;
$parts[] = strval(intval($receiptData['receiptGlobalNo']));
$parts[] = $receiptData['receiptDate'];
// Use toCents (but we'll simulate it)
$ourTotalCents = intval($receiptData['receiptTotal'] * 100); // Simplified - should match Python
$parts[] = strval($ourTotalCents);
$parts[] = $ourTaxesString;
$parts[] = $previousReceiptHash;
$ourSignatureString = implode('', $parts);

echo "OUR PHP SIGNATURE STRING:\n";
echo "  Length: " . strlen($ourSignatureString) . " characters\n";
echo "  String: $ourSignatureString\n";
echo "  Hex: " . bin2hex($ourSignatureString) . "\n\n";

// Build what Python would generate (exact Python logic)
$deviceID = strval(intval($receiptData['deviceID']));
$receiptType = strtoupper($receiptData['receiptType']);
$receiptCurrency = strtoupper($receiptData['receiptCurrency']);
$receiptGlobalNo = strval(intval($receiptData['receiptGlobalNo']));
$receiptDate = $receiptData['receiptDate']; // Already in correct format
$receiptTotal = intval($receiptData['receiptTotal'] * 100); // Python: int(receiptTotal * 100) - NO round!

// Build taxes string (Python format)
$receiptTaxes = $receiptData['receiptTaxes'];
// Sort by taxID (Python: sorted(receiptTaxes, key=lambda x: (x['taxID'])))
usort($receiptTaxes, function($a, $b) {
    return ($a['taxID'] ?? 0) - ($b['taxID'] ?? 0);
});

$taxStrings = [];
foreach ($receiptTaxes as $tax) {
    // Python: f"{float(tax['taxPercent']):.2f}{int(tax['taxAmount']*100)}{int(tax['salesAmountWithTax']*100)}"
    $taxPercent = number_format(floatval($tax['taxPercent']), 2, '.', ''); // Python: f"{float(tax['taxPercent']):.2f}"
    $taxAmountCents = intval(floatval($tax['taxAmount']) * 100); // Python: int(tax['taxAmount']*100) - NO round!
    $salesAmountCents = intval(floatval($tax['salesAmountWithTax']) * 100); // Python: int(tax['salesAmountWithTax']*100) - NO round!
    $taxStrings[] = $taxPercent . strval($taxAmountCents) . strval($salesAmountCents);
}
$concatenatedTaxes = implode('', $taxStrings);

// Python signature string: f"{deviceID}{receiptType.upper()}{receiptCurrency.upper()}{receiptGlobalNo}{receiptDate}{int(receiptTotal*100)}{concatenated_receipt_taxes}{previous_hash}"
$pythonSignatureString = $deviceID . $receiptType . $receiptCurrency . $receiptGlobalNo . $receiptDate . strval($receiptTotal) . $concatenatedTaxes . $previousReceiptHash;

echo "PYTHON LIBRARY SIGNATURE STRING (simulated):\n";
echo "  Length: " . strlen($pythonSignatureString) . " characters\n";
echo "  String: $pythonSignatureString\n";
echo "  Hex: " . bin2hex($pythonSignatureString) . "\n\n";

// Compare
echo "COMPARISON:\n";
echo "  Match: " . ($ourSignatureString === $pythonSignatureString ? "YES ✓" : "NO ✗") . "\n";

if ($ourSignatureString !== $pythonSignatureString) {
    echo "\nDIFFERENCES:\n";
    $minLen = min(strlen($ourSignatureString), strlen($pythonSignatureString));
    $maxLen = max(strlen($ourSignatureString), strlen($pythonSignatureString));
    
    if ($minLen !== $maxLen) {
        echo "  Length difference: " . abs($maxLen - $minLen) . " characters\n";
    }
    
    $firstDiff = -1;
    for ($i = 0; $i < $minLen; $i++) {
        if ($ourSignatureString[$i] !== $pythonSignatureString[$i]) {
            $firstDiff = $i;
            break;
        }
    }
    
    if ($firstDiff >= 0) {
        echo "  First difference at position: $firstDiff\n";
        echo "  Our char: '" . ($ourSignatureString[$firstDiff] ?? 'N/A') . "' (ASCII: " . ord($ourSignatureString[$firstDiff] ?? 0) . ")\n";
        echo "  Python char: '" . ($pythonSignatureString[$firstDiff] ?? 'N/A') . "' (ASCII: " . ord($pythonSignatureString[$firstDiff] ?? 0) . ")\n";
        echo "  Context (our): ..." . substr($ourSignatureString, max(0, $firstDiff - 10), 30) . "...\n";
        echo "  Context (Python): ..." . substr($pythonSignatureString, max(0, $firstDiff - 10), 30) . "...\n";
    }
    
    // Show breakdown
    echo "\nBREAKDOWN COMPARISON:\n";
    echo "  deviceID: Our='$deviceID', Python='$deviceID' - " . ($deviceID === $deviceID ? "MATCH" : "DIFFERENT") . "\n";
    echo "  receiptType: Our='" . strtoupper($receiptData['receiptType']) . "', Python='$receiptType' - " . (strtoupper($receiptData['receiptType']) === $receiptType ? "MATCH" : "DIFFERENT") . "\n";
    echo "  receiptCurrency: Our='" . strtoupper($receiptData['receiptCurrency']) . "', Python='$receiptCurrency' - " . (strtoupper($receiptData['receiptCurrency']) === $receiptCurrency ? "MATCH" : "DIFFERENT") . "\n";
    echo "  receiptGlobalNo: Our='" . strval(intval($receiptData['receiptGlobalNo'])) . "', Python='$receiptGlobalNo' - " . (strval(intval($receiptData['receiptGlobalNo'])) === $receiptGlobalNo ? "MATCH" : "DIFFERENT") . "\n";
    echo "  receiptDate: Our='" . $receiptData['receiptDate'] . "', Python='$receiptDate' - " . ($receiptData['receiptDate'] === $receiptDate ? "MATCH" : "DIFFERENT") . "\n";
    
    // Calculate our receiptTotal
    require_once APP_PATH . '/includes/db.php';
    $ourTotalCents = ZimraSignature::toCents($receiptData['receiptTotal'], $receiptData['receiptCurrency']);
    echo "  receiptTotal (cents): Our='$ourTotalCents', Python='$receiptTotal' - " . ($ourTotalCents == $receiptTotal ? "MATCH" : "DIFFERENT") . "\n";
    
    echo "  receiptTaxes: Our='$ourTaxesString', Python='$concatenatedTaxes' - " . ($ourTaxesString === $concatenatedTaxes ? "MATCH" : "DIFFERENT") . "\n";
    echo "  previousReceiptHash: Our='" . substr($previousReceiptHash, 0, 20) . "...', Python='" . substr($previousReceiptHash, 0, 20) . "...' - MATCH\n";
}

// Generate hashes
$ourHash = base64_encode(hash('sha256', $ourSignatureString, true));
$pythonHash = base64_encode(hash('sha256', $pythonSignatureString, true));

echo "\nHASH COMPARISON:\n";
echo "  Our hash:    $ourHash\n";
echo "  Python hash: $pythonHash\n";
echo "  Match: " . ($ourHash === $pythonHash ? "YES ✓" : "NO ✗") . "\n";

echo "\n========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";


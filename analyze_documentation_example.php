<?php
/**
 * Analyze ZIMRA Documentation Example to verify signature string format
 * 
 * Documentation Example:
 * Result: A0250000B0.000350000C15.0015000115000D15.0030000230000
 * Result used for hash generation: 
 * 321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000B0.000350000C15.0015000115000D15.0030000230000hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=
 * Generated receipt hash: zDxEalWUpwX2BcsYxRUAEfY/13OaCrTwDt01So3a6uU=
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "========================================\n";
echo "ZIMRA DOCUMENTATION EXAMPLE ANALYSIS\n";
echo "========================================\n\n";

// Documentation example data
$example = [
    'deviceID' => 321,
    'receiptType' => 'FISCALINVOICE',
    'receiptCurrency' => 'ZWL',
    'receiptGlobalNo' => 432,
    'receiptDate' => '2019-09-19T15:43:12',
    'receiptTotal' => 9450.00, // In ZWL
    'receiptTaxes' => [
        [
            'taxID' => 1,
            'taxCode' => 'A',
            'taxPercent' => null, // Empty (exempt)
            'taxAmount' => 0.00,
            'salesAmountWithTax' => 2500.00
        ],
        [
            'taxID' => 2,
            'taxCode' => 'B',
            'taxPercent' => 0,
            'taxAmount' => 0.00,
            'salesAmountWithTax' => 3500.00
        ],
        [
            'taxID' => 3,
            'taxCode' => 'C',
            'taxPercent' => 15,
            'taxAmount' => 150.00,
            'salesAmountWithTax' => 1150.00
        ],
        [
            'taxID' => 3,
            'taxCode' => 'D',
            'taxPercent' => 15,
            'taxAmount' => 300.00,
            'salesAmountWithTax' => 2300.00
        ]
    ],
    'previousReceiptHash' => 'hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k='
];

echo "Documentation Example Data:\n";
echo "  deviceID: {$example['deviceID']}\n";
echo "  receiptType: {$example['receiptType']}\n";
echo "  receiptCurrency: {$example['receiptCurrency']}\n";
echo "  receiptGlobalNo: {$example['receiptGlobalNo']}\n";
echo "  receiptDate: {$example['receiptDate']}\n";
echo "  receiptTotal: {$example['receiptTotal']} ZWL\n";
echo "  previousReceiptHash: {$example['previousReceiptHash']}\n\n";

// Build receiptTaxes string according to documentation
// Format: taxCode || taxPercent || taxAmount || salesAmountWithTax
// Sort by taxID ascending, then taxCode alphabetical (empty before A)

echo "Building receiptTaxes string...\n";
$taxStrings = [];

// Sort by taxID, then by taxCode
usort($example['receiptTaxes'], function($a, $b) {
    $taxIdA = intval($a['taxID']);
    $taxIdB = intval($b['taxID']);
    if ($taxIdA !== $taxIdB) {
        return $taxIdA - $taxIdB;
    }
    // If taxID same, sort by taxCode (empty comes before A)
    $taxCodeA = $a['taxCode'] ?? '';
    $taxCodeB = $b['taxCode'] ?? '';
    if ($taxCodeA === '' && $taxCodeB !== '') return -1;
    if ($taxCodeA !== '' && $taxCodeB === '') return 1;
    return strcmp($taxCodeA, $taxCodeB);
});

foreach ($example['receiptTaxes'] as $idx => $tax) {
    // 1. taxCode
    $taxCode = $tax['taxCode'] ?? '';
    
    // 2. taxPercent - format with 2 decimal places
    $taxPercent = '';
    if (isset($tax['taxPercent'])) {
        $percentValue = floatval($tax['taxPercent']);
        $taxPercent = number_format($percentValue, 2, '.', ''); // e.g., "15.00", "0.00"
    }
    // If taxPercent is null/empty (exempt), use empty string
    
    // 3. taxAmount in cents
    $taxAmountCents = intval(floatval($tax['taxAmount']) * 100);
    
    // 4. salesAmountWithTax in cents
    $salesAmountCents = intval(floatval($tax['salesAmountWithTax']) * 100);
    
    // Format: taxCode || taxPercent || taxAmount || salesAmountWithTax
    $taxString = $taxCode . $taxPercent . strval($taxAmountCents) . strval($salesAmountCents);
    $taxStrings[] = $taxString;
    
    echo "  Tax[$idx]: taxID={$tax['taxID']}, taxCode='$taxCode', taxPercent='$taxPercent', taxAmount={$tax['taxAmount']} (cents: $taxAmountCents), salesAmountWithTax={$tax['salesAmountWithTax']} (cents: $salesAmountCents)\n";
    echo "    Tax string: $taxString\n";
}

$receiptTaxesString = implode('', $taxStrings);
echo "\nReceiptTaxes concatenated: $receiptTaxesString\n";
echo "Expected from documentation: A0250000B0.000350000C15.0015000115000D15.0030000230000\n";

if ($receiptTaxesString === 'A0250000B0.000350000C15.0015000115000D15.0030000230000') {
    echo "✓ ReceiptTaxes string matches documentation!\n\n";
} else {
    echo "✗ ReceiptTaxes string does NOT match documentation!\n";
    echo "  Length: " . strlen($receiptTaxesString) . " vs " . strlen('A0250000B0.000350000C15.0015000115000D15.0030000230000') . "\n\n";
}

// Build complete signature string
// Format: deviceID || receiptType || receiptCurrency || receiptGlobalNo || receiptDate || receiptTotal || receiptTaxes || previousReceiptHash

$parts = [];
$parts[] = strval($example['deviceID']); // 321
$parts[] = $example['receiptType']; // FISCALINVOICE
$parts[] = $example['receiptCurrency']; // ZWL
$parts[] = strval($example['receiptGlobalNo']); // 432
$parts[] = $example['receiptDate']; // 2019-09-19T15:43:12
$parts[] = strval(intval($example['receiptTotal'] * 100)); // 945000 (9450.00 ZWL in cents)
$parts[] = $receiptTaxesString; // A0250000B0.000350000C15.0015000115000D15.0030000230000
$parts[] = $example['previousReceiptHash']; // hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=

$signatureString = implode('', $parts); // NO SPACES - concatenated directly

echo "Complete Signature String:\n";
echo "$signatureString\n\n";

echo "Expected from documentation:\n";
echo "321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000B0.000350000C15.0015000115000D15.0030000230000hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=\n\n";

if ($signatureString === '321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000B0.000350000C15.0015000115000D15.0030000230000hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=') {
    echo "✓✓✓ SIGNATURE STRING MATCHES DOCUMENTATION EXACTLY!\n\n";
} else {
    echo "✗✗✗ SIGNATURE STRING DOES NOT MATCH!\n\n";
    
    // Character-by-character comparison
    $expected = '321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000B0.000350000C15.0015000115000D15.0030000230000hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=';
    $len = max(strlen($signatureString), strlen($expected));
    
    echo "Character-by-character comparison:\n";
    for ($i = 0; $i < $len; $i++) {
        $our = $i < strlen($signatureString) ? $signatureString[$i] : 'MISSING';
        $exp = $i < strlen($expected) ? $expected[$i] : 'MISSING';
        if ($our !== $exp) {
            echo "  Position $i: Our='$our' (ASCII: " . ord($our) . "), Expected='$exp' (ASCII: " . ord($exp) . ")\n";
        }
    }
}

// Calculate hash
$hash = base64_encode(hash('sha256', $signatureString, true));
echo "\nCalculated Hash: $hash\n";
echo "Expected Hash: zDxEalWUpwX2BcsYxRUAEfY/13OaCrTwDt01So3a6uU=\n";

if ($hash === 'zDxEalWUpwX2BcsYxRUAEfY/13OaCrTwDt01So3a6uU=') {
    echo "✓✓✓ HASH MATCHES DOCUMENTATION EXACTLY!\n";
} else {
    echo "✗✗✗ HASH DOES NOT MATCH!\n";
}

echo "\n========================================\n";
echo "ANALYSIS COMPLETE\n";
echo "========================================\n";


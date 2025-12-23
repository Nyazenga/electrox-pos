<?php
/**
 * Verify ZIMRA Documentation Hash Calculation
 * 
 * Documentation Example:
 * Signature String: 321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000B0.000350000C15.0015000115000D15.0030000230000hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=
 * Expected Hash: zDxEalWUpwX2BcsYxRUAEfY/13OaCrTwDt01So3a6uU=
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "========================================\n";
echo "ZIMRA DOCUMENTATION HASH VERIFICATION\n";
echo "========================================\n\n";

// Documentation example signature string (exactly as shown)
$signatureString = '321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000B0.000350000C15.0015000115000D15.0030000230000hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=';

// Expected hash from documentation
$expectedHash = 'zDxEalWUpwX2BcsYxRUAEfY/13OaCrTwDt01So3a6uU=';

echo "Signature String (from documentation):\n";
echo "$signatureString\n\n";

echo "String Length: " . strlen($signatureString) . " bytes\n\n";

// Calculate SHA256 hash
$hashBinary = hash('sha256', $signatureString, true); // true = raw binary output
$hashBase64 = base64_encode($hashBinary);

echo "Calculated Hash (PHP):\n";
echo "$hashBase64\n\n";

echo "Expected Hash (Documentation):\n";
echo "$expectedHash\n\n";

if ($hashBase64 === $expectedHash) {
    echo "✓✓✓ HASH MATCHES DOCUMENTATION EXACTLY!\n";
} else {
    echo "✗✗✗ HASH DOES NOT MATCH!\n\n";
    
    // Character-by-character comparison
    echo "Hash Comparison:\n";
    $len = max(strlen($hashBase64), strlen($expectedHash));
    $mismatches = 0;
    for ($i = 0; $i < $len; $i++) {
        $our = $i < strlen($hashBase64) ? $hashBase64[$i] : 'MISSING';
        $exp = $i < strlen($expectedHash) ? $expectedHash[$i] : 'MISSING';
        if ($our !== $exp) {
            echo "  Position $i: Our='$our', Expected='$exp'\n";
            $mismatches++;
        }
    }
    
    if ($mismatches === 0) {
        echo "  Hashes are identical!\n";
    } else {
        echo "  Total mismatches: $mismatches\n";
    }
    
    // Also show hex representation for debugging
    echo "\nHash (Hex): " . bin2hex($hashBinary) . "\n";
    echo "Expected (Decoded from Base64): " . bin2hex(base64_decode($expectedHash)) . "\n";
}

// Verify the signature string byte-by-byte to check for hidden characters
echo "\n========================================\n";
echo "SIGNATURE STRING BYTE ANALYSIS\n";
echo "========================================\n";
echo "Checking for any hidden characters or encoding issues...\n\n";

// Check each character
for ($i = 0; $i < strlen($signatureString); $i++) {
    $char = $signatureString[$i];
    $ascii = ord($char);
    
    // Check for non-printable or suspicious characters
    if ($ascii < 32 || $ascii > 126) {
        echo "Position $i: Non-printable character (ASCII: $ascii, Hex: " . dechex($ascii) . ")\n";
    }
}

echo "\nAll characters are printable ASCII.\n";

// Try different hash methods to see if any match
echo "\n========================================\n";
echo "ALTERNATIVE HASH CALCULATIONS\n";
echo "========================================\n";

// Method 1: Standard SHA256
echo "Method 1 (Standard SHA256):\n";
$hash1 = base64_encode(hash('sha256', $signatureString, true));
echo "$hash1\n";
echo "Match: " . ($hash1 === $expectedHash ? "YES" : "NO") . "\n\n";

// Method 2: With UTF-8 encoding
echo "Method 2 (UTF-8 encoded):\n";
$hash2 = base64_encode(hash('sha256', utf8_encode($signatureString), true));
echo "$hash2\n";
echo "Match: " . ($hash2 === $expectedHash ? "YES" : "NO") . "\n\n";

// Method 3: Verify our signature string generation matches
echo "Method 3 (Verifying signature string generation):\n";
require_once __DIR__ . '/includes/zimra_signature.php';

$exampleReceiptData = [
    'deviceID' => 321,
    'receiptType' => 'FISCALINVOICE',
    'receiptCurrency' => 'ZWL',
    'receiptGlobalNo' => 432,
    'receiptDate' => '2019-09-19T15:43:12',
    'receiptTotal' => 9450.00,
    'receiptTaxes' => [
        [
            'taxID' => 1,
            'taxCode' => 'A',
            'taxPercent' => null,
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
    ]
];

$previousHash = 'hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=';

// Use reflection to access private method
$reflection = new ReflectionClass('ZimraSignature');
$method = $reflection->getMethod('buildReceiptSignatureString');
$method->setAccessible(true);

$generatedString = $method->invoke(null, $exampleReceiptData, $previousHash);
echo "Generated signature string:\n$generatedString\n";
echo "Documentation string:\n$signatureString\n";
echo "Match: " . ($generatedString === $signatureString ? "YES" : "NO") . "\n";

if ($generatedString === $signatureString) {
    $generatedHash = base64_encode(hash('sha256', $generatedString, true));
    echo "Generated hash: $generatedHash\n";
    echo "Expected hash: $expectedHash\n";
    echo "Hash match: " . ($generatedHash === $expectedHash ? "YES" : "NO") . "\n";
}

echo "\n========================================\n";
echo "VERIFICATION COMPLETE\n";
echo "========================================\n";


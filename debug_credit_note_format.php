<?php
/**
 * Debug credit note format - Panier shows: -5810-581
 * Let's figure out exactly what this means
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "========================================\n";
echo "CREDIT NOTE FORMAT ANALYSIS\n";
echo "========================================\n\n";

echo "Panier Credit Note Signature:\n";
echo "24455CREDITNOTEUSD82025-05-06T12:54:46-5810-581oDCrjJ5wjYdt+DjjqEJ6Z9zEtjttpb5OYLlYh9Avsic=\n\n";

echo "Breaking it down:\n";
echo "deviceID: 24455\n";
echo "receiptType: CREDITNOTE\n";
echo "receiptCurrency: USD\n";
echo "receiptGlobalNo: 8\n";
echo "receiptDate: 2025-05-06T12:54:46\n";
echo "receiptTotal: -581 (cents)\n";
echo "receiptTaxes: 0-581\n";
echo "  - This appears to be: taxCode('') + taxPercent('') + taxAmount(-581) + salesAmountWithTax(-581)\n";
echo "  - But why does it show as '0-581' and not '-581-581'?\n\n";

echo "Let's try different interpretations:\n\n";

// Try 1: taxCode='', taxPercent='', taxAmount=0, salesAmountWithTax=-581
$try1 = '' . '' . '0' . '-581';
echo "Try 1 (taxAmount=0, salesAmountWithTax=-581): '$try1'\n";
$sig1 = '24455CREDITNOTEUSD82025-05-06T12:54:46-581' . $try1 . 'oDCrjJ5wjYdt+DjjqEJ6Z9zEtjttpb5OYLlYh9Avsic=';
$hash1 = base64_encode(hash('sha256', $sig1, true));
echo "  Signature: $sig1\n";
echo "  Hash: $hash1\n";
echo "  Match: " . ($hash1 === 'F7ZV6q3zDkgKK2/d2970TkxtrwyyQwg9AUXeu6b1Pr8=' ? "YES ✓" : "NO ✗") . "\n\n";

// Try 2: taxCode='', taxPercent='0', taxAmount=-581, salesAmountWithTax=-581
$try2 = '' . '0' . '-581' . '-581';
echo "Try 2 (taxPercent='0', taxAmount=-581, salesAmountWithTax=-581): '$try2'\n";
$sig2 = '24455CREDITNOTEUSD82025-05-06T12:54:46-581' . $try2 . 'oDCrjJ5wjYdt+DjjqEJ6Z9zEtjttpb5OYLlYh9Avsic=';
$hash2 = base64_encode(hash('sha256', $sig2, true));
echo "  Signature: $sig2\n";
echo "  Hash: $hash2\n";
echo "  Match: " . ($hash2 === 'F7ZV6q3zDkgKK2/d2970TkxtrwyyQwg9AUXeu6b1Pr8=' ? "YES ✓" : "NO ✗") . "\n\n";

// Try 3: Look at the actual Panier signature more carefully
// receiptTotal: -581
// receiptTaxes: 0-581
// The '0' might be part of receiptTotal and receiptTaxes combined?

// Actually, let me check if it's: receiptTotal + receiptTaxes = -5810-581
// No wait, receiptTotal is -581 (4 chars), receiptTaxes starts after it

// Let me parse character by character after receiptDate
$panierSig = '24455CREDITNOTEUSD82025-05-06T12:54:46-5810-581oDCrjJ5wjYdt+DjjqEJ6Z9zEtjttpb5OYLlYh9Avsic=';
$afterDate = substr($panierSig, strpos($panierSig, '2025-05-06T12:54:46') + strlen('2025-05-06T12:54:46'));
echo "Characters after receiptDate: $afterDate\n";
echo "  First part (receiptTotal): -581\n";
echo "  Second part (receiptTaxes): 0-581\n";
echo "  Third part (previousReceiptHash): oDCrjJ5wjYdt+DjjqEJ6Z9zEtjttpb5OYLlYh9Avsic=\n\n";

// So receiptTaxes is definitely "0-581"
// For exempt tax: taxCode='', taxPercent=''
// So we have: '' + '' + taxAmount + salesAmountWithTax = '0-581'

// This means one of:
// 1. taxAmount=0, salesAmountWithTax=-581
// 2. taxAmount=-581 but formatted differently?

// Actually, wait - if taxAmount is 0 (zero), and salesAmountWithTax is -581,
// then: '' + '' + '0' + '-581' = '0-581' ✓

// But the Panier receipt data shows:
// "taxAmount": 0,
// "salesAmountWithTax": -5.81

// So in cents: taxAmount=0, salesAmountWithTax=-581
// taxCode='' + taxPercent='' + taxAmount='0' + salesAmountWithTax='-581' = '0-581' ✓

echo "SOLUTION: For credit notes with exempt tax:\n";
echo "  - taxCode: '' (empty)\n";
echo "  - taxPercent: '' (empty, exempt)\n";
echo "  - taxAmount: 0 (always 0 for exempt, even in credit notes?)\n";
echo "  - salesAmountWithTax: -581 (negative, in cents)\n";
echo "  - Result: '' + '' + '0' + '-581' = '0-581'\n\n";

echo "But wait - the Panier receipt data shows:\n";
echo '  "taxAmount": 0,\n';
echo '  "salesAmountWithTax": -5.81\n';
echo "\nSo taxAmount is 0 (not negative), even though it's a credit note!\n";
echo "This means for credit notes with exempt tax, taxAmount stays 0.\n";


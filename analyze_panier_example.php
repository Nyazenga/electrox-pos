<?php
/**
 * Analyze Panier API Example to verify signature format
 * 
 * Panier Example (First Receipt):
 * result_used_to_hash: "24455FISCALINVOICEUSD72025-05-06T12:40:345810581"
 * 
 * Breaking down:
 * - deviceID: 24455
 * - receiptType: FISCALINVOICE
 * - receiptCurrency: USD
 * - receiptGlobalNo: 7
 * - receiptDate: 2025-05-06T12:40:34
 * - receiptTotal: 581 (cents: 5.81 * 100)
 * - receiptTaxes: 0581 (taxCode="" + taxPercent="" + taxAmount=0 + salesAmountWithTax=581)
 * - previousReceiptHash: NOT INCLUDED (first receipt)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "========================================\n";
echo "PANIER API EXAMPLE ANALYSIS\n";
echo "========================================\n\n";

// Panier Example - First Receipt (Exempt Tax)
echo "PANIER EXAMPLE - First Receipt (Exempt Tax):\n";
echo "--------------------------------------------\n";

$panierSignature = "24455FISCALINVOICEUSD72025-05-06T12:40:345810581";
$panierHash = "oDCrjJ5wjYdt+DjjqEJ6Z9zEtjttpb5OYLlYh9Avsic=";

echo "Signature String: $panierSignature\n";
echo "Expected Hash: $panierHash\n\n";

// Break down the signature string
echo "Breaking down signature string:\n";
echo "  deviceID: 24455\n";
echo "  receiptType: FISCALINVOICE\n";
echo "  receiptCurrency: USD\n";
echo "  receiptGlobalNo: 7\n";
echo "  receiptDate: 2025-05-06T12:40:34\n";
echo "  receiptTotal: 581 (cents: 5.81 USD)\n";
echo "  receiptTaxes: 0581\n";
echo "    - taxCode: '' (empty, exempt)\n";
echo "    - taxPercent: '' (empty, exempt)\n";
echo "    - taxAmount: 0 (cents)\n";
echo "    - salesAmountWithTax: 581 (cents)\n";
echo "  previousReceiptHash: NOT INCLUDED (first receipt)\n\n";

// Verify hash
$calculatedHash = base64_encode(hash('sha256', $panierSignature, true));
echo "Calculated Hash: $calculatedHash\n";
echo "Panier Hash:     $panierHash\n";
echo "Match: " . ($calculatedHash === $panierHash ? "YES ✓" : "NO ✗") . "\n\n";

// Panier Example - Second Receipt (Credit Note with previous hash)
echo "========================================\n";
echo "PANIER EXAMPLE - Credit Note (with previousHash):\n";
echo "--------------------------------------------\n";

$panierCreditSignature = "24455CREDITNOTEUSD82025-05-06T12:54:46-5810-581oDCrjJ5wjYdt+DjjqEJ6Z9zEtjttpb5OYLlYh9Avsic=";
$panierCreditHash = "F7ZV6q3zDkgKK2/d2970TkxtrwyyQwg9AUXeu6b1Pr8=";

echo "Signature String: $panierCreditSignature\n";
echo "Expected Hash: $panierCreditHash\n\n";

// Break down
echo "Breaking down signature string:\n";
echo "  deviceID: 24455\n";
echo "  receiptType: CREDITNOTE\n";
echo "  receiptCurrency: USD\n";
echo "  receiptGlobalNo: 8\n";
echo "  receiptDate: 2025-05-06T12:54:46\n";
echo "  receiptTotal: -581 (cents: -5.81 USD, negative for credit note)\n";
echo "  receiptTaxes: 0-581\n";
echo "    - taxCode: '' (empty, exempt)\n";
echo "    - taxPercent: '' (empty, exempt)\n";
echo "    - taxAmount: -581 (cents, negative)\n";
echo "    - salesAmountWithTax: -581 (cents, negative)\n";
echo "  previousReceiptHash: oDCrjJ5wjYdt+DjjqEJ6Z9zEtjttpb5OYLlYh9Avsic=\n\n";

// Verify hash
$calculatedCreditHash = base64_encode(hash('sha256', $panierCreditSignature, true));
echo "Calculated Hash: $calculatedCreditHash\n";
echo "Panier Hash:     $panierCreditHash\n";
echo "Match: " . ($calculatedCreditHash === $panierCreditHash ? "YES ✓" : "NO ✗") . "\n\n";

echo "========================================\n";
echo "KEY FINDINGS FROM PANIER EXAMPLES\n";
echo "========================================\n\n";

echo "1. Signature format matches ZIMRA documentation:\n";
echo "   deviceID || receiptType || receiptCurrency || receiptGlobalNo || receiptDate || receiptTotal || receiptTaxes || previousReceiptHash\n\n";

echo "2. receiptTaxes format: taxCode || taxPercent || taxAmount || salesAmountWithTax\n";
echo "   - For exempt tax: taxCode='', taxPercent='', taxAmount=0, salesAmountWithTax=581\n";
echo "   - Result: '0581' (empty + empty + 0 + 581)\n\n";

echo "3. For negative amounts (credit notes):\n";
echo "   - receiptTotal: -581 (negative integer in cents)\n";
echo "   - taxAmount: -581 (negative integer in cents)\n";
echo "   - salesAmountWithTax: -581 (negative integer in cents)\n";
echo "   - Result in signature: '-5810-581' (taxPercent='' + taxAmount=-581 + salesAmountWithTax=-581)\n\n";

echo "4. First receipt: previousReceiptHash is NOT included\n";
echo "   - Signature ends after receiptTaxes\n\n";

echo "5. Subsequent receipts: previousReceiptHash IS included\n";
echo "   - Signature includes previousReceiptHash at the end\n\n";


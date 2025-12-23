<?php
/**
 * Compare Python Receipt #2 signature string with PHP Receipt #2
 */

echo "========================================\n";
echo "PYTHON vs PHP - RECEIPT #2 COMPARISON\n";
echo "========================================\n\n";

// Python Receipt #2 signature string (from logs)
$python_string = "30199FISCALINVOICEUSD22025-12-21T18:49:08120015.501611200cFXk+7IfHyINiGv0svinyTktj5XsslfncnGKKUGJsKk=";

// PHP Receipt #2 signature string (from error.log)
$php_string = "30199FISCALINVOICEUSD22025-12-21T18:14:45120015.501611200o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=";

echo "PYTHON RECEIPT #2:\n";
echo $python_string . "\n\n";

echo "PHP RECEIPT #2:\n";
echo $php_string . "\n\n";

echo "BREAKDOWN:\n";
echo "========================================\n";
echo "PYTHON:\n";
echo "  deviceID: 30199\n";
echo "  receiptType: FISCALINVOICE\n";
echo "  receiptCurrency: USD\n";
echo "  receiptGlobalNo: 2\n";
echo "  receiptDate: 2025-12-21T18:49:08\n";
echo "  receiptTotal: 1200 (12.00 USD in cents)\n";
echo "  receiptTaxes: 15.501611200 (taxPercent=15.50, taxAmount=161 cents, salesAmountWithTax=1200 cents)\n";
echo "  previousReceiptHash: cFXk+7IfHyINiGv0svinyTktj5XsslfncnGKKUGJsKk=\n\n";

echo "PHP:\n";
echo "  deviceID: 30199\n";
echo "  receiptType: FISCALINVOICE\n";
echo "  receiptCurrency: USD\n";
echo "  receiptGlobalNo: 2\n";
echo "  receiptDate: 2025-12-21T18:14:45\n";
echo "  receiptTotal: 1200 (12.00 USD in cents)\n";
echo "  receiptTaxes: 15.501611200 (taxPercent=15.50, taxAmount=161 cents, salesAmountWithTax=1200 cents)\n";
echo "  previousReceiptHash: o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=\n\n";

echo "DIFFERENCES:\n";
echo "========================================\n";
echo "1. Receipt Date: Different timestamps (expected - different submission times)\n";
echo "2. Previous Receipt Hash: DIFFERENT!\n";
echo "   - Python: cFXk+7IfHyINiGv0svinyTktj5XsslfncnGKKUGJsKk=\n";
echo "   - PHP: o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=\n\n";

echo "KEY FINDING:\n";
echo "========================================\n";
echo "Both Python and PHP are using DIFFERENT previousReceiptHash values!\n";
echo "This means they're using different hashes from Receipt #1.\n";
echo "The previousReceiptHash MUST be ZIMRA's hash from Receipt #1's response.\n";
echo "Both are getting RCPT020, which suggests the hash chain is broken.\n\n";

echo "NEXT STEPS:\n";
echo "========================================\n";
echo "1. Verify Receipt #1's hash from ZIMRA response matches what we're using\n";
echo "2. Check if Python and PHP are both using ZIMRA's hash (not our generated hash)\n";
echo "3. Compare the exact hash values from ZIMRA responses\n";


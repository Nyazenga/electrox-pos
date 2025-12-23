<?php
/**
 * Final comparison of Python and PHP Receipt #2 signature strings
 */

echo "========================================\n";
echo "FINAL PYTHON vs PHP - RECEIPT #2 COMPARISON\n";
echo "========================================\n\n";

// Python Receipt #2 signature string (from latest test run)
$python_string = "30199FISCALINVOICEUSD22025-12-21T18:54:57120015.501611200SSuQOwLUbxNVF62zjxPe1fF/V5pw2KwcXwewQk8re0g=";

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
echo "  receiptDate: 2025-12-21T18:54:57\n";
echo "  receiptTotal: 1200 (12.00 USD in cents)\n";
echo "  receiptTaxes: 15.501611200\n";
echo "    - taxPercent: 15.50\n";
echo "    - taxAmount: 161 cents (1.61 USD)\n";
echo "    - salesAmountWithTax: 1200 cents (12.00 USD)\n";
echo "  previousReceiptHash: SSuQOwLUbxNVF62zjxPe1fF/V5pw2KwcXwewQk8re0g=\n\n";

echo "PHP:\n";
echo "  deviceID: 30199\n";
echo "  receiptType: FISCALINVOICE\n";
echo "  receiptCurrency: USD\n";
echo "  receiptGlobalNo: 2\n";
echo "  receiptDate: 2025-12-21T18:14:45\n";
echo "  receiptTotal: 1200 (12.00 USD in cents)\n";
echo "  receiptTaxes: 15.501611200\n";
echo "    - taxPercent: 15.50\n";
echo "    - taxAmount: 161 cents (1.61 USD)\n";
echo "    - salesAmountWithTax: 1200 cents (12.00 USD)\n";
echo "  previousReceiptHash: o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=\n\n";

echo "DIFFERENCES:\n";
echo "========================================\n";
echo "1. Receipt Date: Different timestamps (expected - different submission times)\n";
echo "2. Previous Receipt Hash: DIFFERENT!\n";
echo "   - Python: SSuQOwLUbxNVF62zjxPe1fF/V5pw2KwcXwewQk8re0g=\n";
echo "   - PHP: o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=\n\n";

echo "KEY FINDING:\n";
echo "========================================\n";
echo "Both Python and PHP are using DIFFERENT previousReceiptHash values!\n";
echo "This means they're using different hashes from Receipt #1.\n";
echo "The previousReceiptHash MUST be ZIMRA's hash from Receipt #1's response.\n\n";

echo "ROOT CAUSE:\n";
echo "========================================\n";
echo "Python and PHP are submitting Receipt #1 at different times,\n";
echo "so they get different ZIMRA hashes. Each implementation must use\n";
echo "its OWN Receipt #1's ZIMRA hash for Receipt #2.\n\n";

echo "SOLUTION:\n";
echo "========================================\n";
echo "Both implementations are correct - they're just using different\n";
echo "hashes because they submitted Receipt #1 separately.\n";
echo "The RCPT020 error suggests there's still a signature mismatch.\n";
echo "We need to verify the signature generation matches exactly.\n";


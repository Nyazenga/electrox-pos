<?php
/**
 * Compare Python and PHP signature strings to identify differences
 */

echo "========================================\n";
echo "PYTHON vs PHP SIGNATURE STRING COMPARISON\n";
echo "========================================\n\n";

// Python signature strings (from logs)
$python_strings = [
    1 => "30199FISCALINVOICEUSD12025-12-21T18:31:57110015.001431100",
    2 => "30199FISCALINVOICEUSD22025-12-21T18:32:03120015.001571200",
    3 => "30199FISCALINVOICEUSD32025-12-21T18:32:10130015.001701300"
];

// PHP signature strings (from error.log - Receipt #2 that failed)
$php_string = "30199FISCALINVOICEUSD22025-12-21T18:14:45120015.501611200o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=";

echo "PYTHON RECEIPT #2 SIGNATURE STRING:\n";
echo $python_strings[2] . "\n\n";

echo "PHP RECEIPT #2 SIGNATURE STRING:\n";
echo $php_string . "\n\n";

echo "DIFFERENCES:\n";
echo "1. Tax Percent: Python uses '15.00', PHP uses '15.50'\n";
echo "2. Tax Amount: Python uses '157' cents, PHP uses '161' cents\n";
echo "3. Previous Receipt Hash: Python MISSING, PHP includes hash\n";
echo "4. Receipt Date: Different timestamps (expected)\n\n";

echo "KEY FINDINGS:\n";
echo "========================================\n";
echo "1. TAX PRECISION ISSUE:\n";
echo "   - Python: taxPercent=15.0, taxAmount=1.57 (157 cents)\n";
echo "   - PHP: taxPercent=15.5, taxAmount=1.61 (161 cents)\n";
echo "   - PHP uses 15.5% tax, Python uses 15.0% tax\n";
echo "   - This causes different tax amounts in signature!\n\n";

echo "2. PREVIOUS RECEIPT HASH:\n";
echo "   - Python Receipt #2: NO previousReceiptHash (should have it!)\n";
echo "   - PHP Receipt #2: HAS previousReceiptHash\n";
echo "   - Python library is NOT chaining receipts correctly!\n\n";

echo "3. TAX AMOUNT CALCULATION:\n";
echo "   Python (15%): 12.00 * (15/115) = 1.5652... = 157 cents (truncated)\n";
echo "   PHP (15.5%): 12.00 * (15.5/115.5) = 1.6104... = 161 cents (truncated)\n";
echo "   Both use truncation (int()), but different tax rates!\n\n";

echo "RECOMMENDATIONS:\n";
echo "========================================\n";
echo "1. Use taxPercent=15.5 in PHP (matches our tax config)\n";
echo "2. Ensure previousReceiptHash is included for receiptCounter > 1\n";
echo "3. Verify tax amount calculation matches Python's truncation logic\n";
echo "4. Check if Python library needs to be updated to support 15.5% tax\n";


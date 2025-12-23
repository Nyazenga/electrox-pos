<?php
/**
 * Analyze differences between Python and PHP Receipt #2
 */

echo "========================================\n";
echo "PYTHON vs PHP - RECEIPT #2 ANALYSIS\n";
echo "========================================\n\n";

// Python Receipt #2 signature string (from Python logs)
$python_string = "30199FISCALINVOICEUSD22025-12-21T18:49:08120015.501611200cFXk+7IfHyINiGv0svinyTktj5XsslfncnGKKUGJsKk=";

// PHP Receipt #2 signature string (from PHP error.log)
$php_string = "30199FISCALINVOICEUSD22025-12-21T18:14:45120015.501611200o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=";

// Python Receipt #1 ZIMRA hash (from Python response)
$python_r1_hash = "KfdgqWpbozoQbxwOG6qi4Bx2cmwagN/lIMHXWdNfFnY=";

// PHP Receipt #1 ZIMRA hash (from PHP database)
$php_r1_hash = "o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=";

echo "SIGNATURE STRINGS:\n";
echo "========================================\n";
echo "Python Receipt #2:\n";
echo $python_string . "\n\n";
echo "PHP Receipt #2:\n";
echo $php_string . "\n\n";

echo "RECEIPT #1 HASHES (used as previousReceiptHash):\n";
echo "========================================\n";
echo "Python Receipt #1 ZIMRA Hash: " . $python_r1_hash . "\n";
echo "PHP Receipt #1 ZIMRA Hash: " . $php_r1_hash . "\n\n";

echo "ANALYSIS:\n";
echo "========================================\n";
echo "1. Both Python and PHP Receipt #2 use DIFFERENT previousReceiptHash values\n";
echo "   - Python uses: cFXk+7IfHyINiGv0svinyTktj5XsslfncnGKKUGJsKk=\n";
echo "   - PHP uses: o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=\n\n";

echo "2. Python Receipt #1 ZIMRA hash: " . $python_r1_hash . "\n";
echo "   But Python Receipt #2 uses: cFXk+7IfHyINiGv0svinyTktj5XsslfncnGKKUGJsKk=\n";
echo "   These DON'T MATCH! Python is using the wrong hash!\n\n";

echo "3. PHP Receipt #1 ZIMRA hash: " . $php_r1_hash . "\n";
echo "   PHP Receipt #2 uses: o7Z4KT0uM2zVYYBaXMjzI9j1IcCZmhAbMCuoP9W2BEc=\n";
echo "   These MATCH! PHP is using the correct hash.\n\n";

echo "KEY FINDING:\n";
echo "========================================\n";
echo "Python is NOT using ZIMRA's hash from Receipt #1's response!\n";
echo "Python is using a different hash (possibly its own generated hash).\n";
echo "This is why Python Receipt #2 gets RCPT020.\n\n";

echo "ROOT CAUSE:\n";
echo "========================================\n";
echo "Python's test script is not correctly extracting the hash from\n";
echo "ZIMRA's response for Receipt #1, or it's using the wrong hash.\n";
echo "The previousReceiptHash MUST be ZIMRA's hash from receiptServerSignature.hash\n";


<?php
/**
 * Compare our signature generation with Python library format
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test data matching what we sent
$deviceID = 30200;
$receiptType = 'FiscalInvoice';
$receiptCurrency = 'USD';
$receiptGlobalNo = 1;
$receiptDate = '2025-12-21T14:58:45';
$receiptTotal = 10.00; // USD
$taxPercent = 0;
$taxAmount = 0.00;
$salesAmountWithTax = 10.00;
$previousReceiptHash = null; // First receipt

echo "========================================\n";
echo "SIGNATURE STRING COMPARISON\n";
echo "========================================\n\n";

// Our PHP format
echo "OUR PHP FORMAT:\n";
echo "----------------\n";
$totalCents = intval($receiptTotal * 100); // 10.00 USD = 1000 cents
$taxPercentStr = number_format($taxPercent, 2, '.', ''); // "0.00"
$taxAmountCents = intval($taxAmount * 100); // 0 cents
$salesAmountCents = intval($salesAmountWithTax * 100); // 1000 cents

$taxString = $taxPercentStr . $taxAmountCents . $salesAmountCents; // "0.0001000"

$parts = [
    strval(intval($deviceID)),           // "30200"
    strtoupper($receiptType),            // "FISCALINVOICE"
    strtoupper($receiptCurrency),        // "USD"
    strval(intval($receiptGlobalNo)),    // "1"
    $receiptDate,                        // "2025-12-21T14:58:45"
    strval($totalCents),                 // "1000"
    $taxString                           // "0.0001000"
];
// No previousReceiptHash for first receipt

$ourSignatureString = implode('', $parts);
echo "Signature String: $ourSignatureString\n";
echo "Length: " . strlen($ourSignatureString) . " characters\n";
echo "Breakdown:\n";
echo "  deviceID: " . $parts[0] . "\n";
echo "  receiptType: " . $parts[1] . "\n";
echo "  receiptCurrency: " . $parts[2] . "\n";
echo "  receiptGlobalNo: " . $parts[3] . "\n";
echo "  receiptDate: " . $parts[4] . "\n";
echo "  receiptTotal (cents): " . $parts[5] . "\n";
echo "  receiptTaxes: " . $parts[6] . "\n";
echo "    (taxPercent: $taxPercentStr, taxAmount: $taxAmountCents cents, salesAmountWithTax: $salesAmountCents cents)\n";

// Python library format (simulated)
echo "\nPYTHON LIBRARY FORMAT (from __init__.py lines 526-530):\n";
echo "----------------\n";
$pythonDeviceID = $deviceID;
$pythonReceiptType = strtoupper($receiptType);
$pythonReceiptCurrency = strtoupper($receiptCurrency);
$pythonReceiptGlobalNo = $receiptGlobalNo;
$pythonReceiptDate = $receiptDate;
$pythonReceiptTotal = intval($receiptTotal * 100); // int(receiptData["receiptTotal"]*100)
$pythonTaxPercent = number_format($taxPercent, 2, '.', ''); // f"{float(tax['taxPercent']):.2f}"
$pythonTaxAmount = intval($taxAmount * 100); // int(tax['taxAmount']*100)
$pythonSalesAmount = intval($salesAmountWithTax * 100); // int(tax['salesAmountWithTax']*100)
$pythonConcatenatedTaxes = $pythonTaxPercent . $pythonTaxAmount . $pythonSalesAmount;

$pythonSignatureString = $pythonDeviceID . $pythonReceiptType . $pythonReceiptCurrency . $pythonReceiptGlobalNo . $pythonReceiptDate . $pythonReceiptTotal . $pythonConcatenatedTaxes;
// No previous_hash for first receipt

echo "Signature String: $pythonSignatureString\n";
echo "Length: " . strlen($pythonSignatureString) . " characters\n";
echo "Breakdown:\n";
echo "  deviceID: $pythonDeviceID\n";
echo "  receiptType: $pythonReceiptType\n";
echo "  receiptCurrency: $pythonReceiptCurrency\n";
echo "  receiptGlobalNo: $pythonReceiptGlobalNo\n";
echo "  receiptDate: $pythonReceiptDate\n";
echo "  receiptTotal (cents): $pythonReceiptTotal\n";
echo "  concatenated_receipt_taxes: $pythonConcatenatedTaxes\n";
echo "    (taxPercent: $pythonTaxPercent, taxAmount: $pythonTaxAmount cents, salesAmountWithTax: $pythonSalesAmount cents)\n";

// Compare
echo "\nCOMPARISON:\n";
echo "----------------\n";
if ($ourSignatureString === $pythonSignatureString) {
    echo "✓ MATCH! Both formats are identical.\n";
} else {
    echo "✗ MISMATCH!\n";
    echo "  Our string:      $ourSignatureString\n";
    echo "  Python string:   $pythonSignatureString\n";
    echo "  Difference at position: ";
    for ($i = 0; $i < min(strlen($ourSignatureString), strlen($pythonSignatureString)); $i++) {
        if ($ourSignatureString[$i] !== $pythonSignatureString[$i]) {
            echo "$i (our: '{$ourSignatureString[$i]}' vs Python: '{$pythonSignatureString[$i]}')\n";
            break;
        }
    }
    if (strlen($ourSignatureString) !== strlen($pythonSignatureString)) {
        echo "Length difference: " . abs(strlen($ourSignatureString) - strlen($pythonSignatureString)) . " characters\n";
    }
}

// Generate hash for both
echo "\nHASH COMPARISON:\n";
echo "----------------\n";
$ourHash = base64_encode(hash('sha256', $ourSignatureString, true));
$pythonHash = base64_encode(hash('sha256', $pythonSignatureString, true));
echo "Our hash:    $ourHash\n";
echo "Python hash: $pythonHash\n";
if ($ourHash === $pythonHash) {
    echo "✓ Hashes match!\n";
} else {
    echo "✗ Hashes don't match!\n";
}

echo "\n========================================\n";
echo "COMPARISON COMPLETE\n";
echo "========================================\n";


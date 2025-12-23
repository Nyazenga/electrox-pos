<?php
/**
 * Compare payloads from interface vs successful test
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);

echo "========================================\n";
echo "PAYLOAD COMPARISON TOOL\n";
echo "========================================\n\n";

// Read interface payload log
$interfaceLog = APP_PATH . '/logs/interface_payload_log.txt';
if (file_exists($interfaceLog)) {
    echo "1. INTERFACE PAYLOAD:\n";
    $interfaceContent = file_get_contents($interfaceLog);
    
    // Extract JSON from log
    $jsonStart = strpos($interfaceContent, '{"Receipt":');
    if ($jsonStart !== false) {
        $interfaceJson = substr($interfaceContent, $jsonStart);
        // Find the end (before the closing =========)
        $jsonEnd = strpos($interfaceJson, '}');
        if ($jsonEnd !== false) {
            // Find the last closing brace
            $lastBrace = strrpos($interfaceJson, '}');
            $interfaceJson = substr($interfaceJson, 0, $lastBrace + 1);
        }
        
        echo "   Extracted JSON:\n";
        echo "   " . substr($interfaceJson, 0, 200) . "...\n\n";
        
        $interfaceData = json_decode($interfaceJson, true);
        if ($interfaceData && isset($interfaceData['Receipt'])) {
            $interfaceReceipt = $interfaceData['Receipt'];
            echo "   ✓ Parsed successfully\n";
        } else {
            echo "   ✗ Could not parse JSON\n";
            exit(1);
        }
    } else {
        echo "   ✗ Could not find JSON in log\n";
        exit(1);
    }
} else {
    echo "1. INTERFACE PAYLOAD: Log file not found\n";
    exit(1);
}

// Get successful test payload from error.log
$testLog = APP_PATH . '/logs/error.log';
if (file_exists($testLog)) {
    echo "\n2. SUCCESSFUL TEST PAYLOAD:\n";
    $testContent = file_get_contents($testLog);
    
    // Find the last successful test (look for "Request:" with no validation errors after)
    $testLines = explode("\n", $testContent);
    $testJson = null;
    $foundSuccess = false;
    
    // Look backwards for a Request line followed by a successful response
    for ($i = count($testLines) - 1; $i >= 0; $i--) {
        $line = $testLines[$i];
        if (strpos($line, 'Request:') !== false && strpos($line, 'receiptType') !== false) {
            // Extract JSON
            $jsonStart = strpos($line, '{');
            if ($jsonStart !== false) {
                $testJson = substr($line, $jsonStart);
                // Check if this was followed by success (no validation errors)
                for ($j = $i + 1; $j < min($i + 50, count($testLines)); $j++) {
                    if (strpos($testLines[$j], 'validationErrors') === false && 
                        strpos($testLines[$j], 'RCPT020') === false &&
                        strpos($testLines[$j], 'receiptID') !== false) {
                        $foundSuccess = true;
                        break;
                    }
                }
                if ($foundSuccess) break;
            }
        }
    }
    
    if ($testJson) {
        echo "   Extracted JSON:\n";
        echo "   " . substr($testJson, 0, 200) . "...\n\n";
        $testData = json_decode($testJson, true);
        if ($testData) {
            $testReceipt = $testData;
            echo "   ✓ Parsed successfully\n";
        } else {
            echo "   ✗ Could not parse JSON\n";
            exit(1);
        }
    } else {
        echo "   Using test_device_30199_reset.php structure as reference\n";
        // Use the structure from the test script
        $testReceipt = [
            'receiptType' => 'FISCALINVOICE',
            'receiptCurrency' => 'USD',
            'receiptCounter' => 1,
            'receiptGlobalNo' => 1,
            'receiptDate' => date('Y-m-d\TH:i:s'),
            'invoiceNo' => 'TEST',
            'receiptTotal' => 10.0,
            'receiptLinesTaxInclusive' => true,
            'receiptLines' => [
                [
                    'receiptLineType' => 'Sale',
                    'receiptLineNo' => 1,
                    'receiptLineHSCode' => '04021099',
                    'receiptLineName' => 'Test Item',
                    'receiptLinePrice' => 10.0,
                    'receiptLineQuantity' => 1.0,
                    'receiptLineTotal' => 10.0,
                    'taxID' => 2,
                    'taxPercent' => 0.0
                ]
            ],
            'receiptTaxes' => [
                [
                    'taxPercent' => 0.0,
                    'taxID' => 2,
                    'taxAmount' => 0.0,
                    'salesAmountWithTax' => 10.0
                ]
            ],
            'receiptPayments' => [
                [
                    'moneyTypeCode' => 0,
                    'paymentAmount' => 10.0
                ]
            ]
        ];
    }
} else {
    echo "2. TEST PAYLOAD: Log file not found\n";
    exit(1);
}

// Compare
echo "\n3. COMPARISON:\n";
echo "========================================\n\n";

$differences = [];

// Compare receiptType
if (isset($interfaceReceipt['receiptType']) && isset($testReceipt['receiptType'])) {
    if ($interfaceReceipt['receiptType'] !== $testReceipt['receiptType']) {
        $differences[] = "receiptType: Interface='{$interfaceReceipt['receiptType']}', Test='{$testReceipt['receiptType']}'";
    }
}

// Compare receiptLines
if (isset($interfaceReceipt['receiptLines']) && isset($testReceipt['receiptLines'])) {
    foreach ($interfaceReceipt['receiptLines'] as $i => $interfaceLine) {
        if (!isset($testReceipt['receiptLines'][$i])) continue;
        
        $testLine = $testReceipt['receiptLines'][$i];
        
        // Check for taxCode
        if (isset($interfaceLine['taxCode'])) {
            $differences[] = "receiptLines[$i]: Interface HAS taxCode='{$interfaceLine['taxCode']}', Test does not";
        }
        
        // Check numeric types
        foreach (['receiptLinePrice', 'receiptLineQuantity', 'receiptLineTotal', 'taxPercent'] as $field) {
            if (isset($interfaceLine[$field]) && isset($testLine[$field])) {
                $interfaceType = gettype($interfaceLine[$field]);
                $testType = gettype($testLine[$field]);
                if ($interfaceType !== $testType) {
                    $differences[] = "receiptLines[$i][$field]: Interface type=$interfaceType ({$interfaceLine[$field]}), Test type=$testType ({$testLine[$field]})";
                }
            }
        }
    }
}

// Compare receiptTaxes
if (isset($interfaceReceipt['receiptTaxes']) && isset($testReceipt['receiptTaxes'])) {
    foreach ($interfaceReceipt['receiptTaxes'] as $i => $interfaceTax) {
        if (!isset($testReceipt['receiptTaxes'][$i])) continue;
        
        $testTax = $testReceipt['receiptTaxes'][$i];
        
        // Check for taxCode
        if (isset($interfaceTax['taxCode'])) {
            $differences[] = "receiptTaxes[$i]: Interface HAS taxCode='{$interfaceTax['taxCode']}', Test does not";
        }
        
        // Check field order
        $interfaceKeys = array_keys($interfaceTax);
        $testKeys = array_keys($testTax);
        if ($interfaceKeys !== $testKeys) {
            $differences[] = "receiptTaxes[$i]: Field order - Interface: " . implode(', ', $interfaceKeys) . ", Test: " . implode(', ', $testKeys);
        }
    }
}

// Compare receiptPayments
if (isset($interfaceReceipt['receiptPayments']) && isset($testReceipt['receiptPayments'])) {
    foreach ($interfaceReceipt['receiptPayments'] as $i => $interfacePayment) {
        if (!isset($testReceipt['receiptPayments'][$i])) continue;
        
        $testPayment = $testReceipt['receiptPayments'][$i];
        
        // Check moneyTypeCode type
        if (isset($interfacePayment['moneyTypeCode']) && isset($testPayment['moneyTypeCode'])) {
            $interfaceType = gettype($interfacePayment['moneyTypeCode']);
            $testType = gettype($testPayment['moneyTypeCode']);
            if ($interfaceType !== $testType) {
                $differences[] = "receiptPayments[$i][moneyTypeCode]: Interface type=$interfaceType ({$interfacePayment['moneyTypeCode']}), Test type=$testType ({$testPayment['moneyTypeCode']})";
            }
        }
    }
}

// Output differences
echo "DIFFERENCES FOUND:\n";
echo "========================================\n";
if (empty($differences)) {
    echo "✅ NO DIFFERENCES FOUND!\n";
    echo "   Payloads match. The issue might be in signature generation.\n";
} else {
    foreach ($differences as $diff) {
        echo "❌ $diff\n";
    }
}

echo "\n========================================\n";
echo "FULL INTERFACE PAYLOAD:\n";
echo json_encode($interfaceReceipt, JSON_PRETTY_PRINT) . "\n";


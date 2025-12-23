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
    echo "1. INTERFACE PAYLOAD (from interface_payload_log.txt):\n";
    $interfaceContent = file_get_contents($interfaceLog);
    $lines = explode("\n", $interfaceContent);
    
    // Find the last JSON payload
    $inJson = false;
    $jsonLines = [];
    foreach (array_reverse($lines) as $line) {
        if (strpos($line, 'JSON Payload:') !== false) {
            $inJson = true;
            continue;
        }
        if ($inJson && trim($line) !== '' && strpos($line, '===') === false) {
            array_unshift($jsonLines, $line);
        }
        if ($inJson && strpos($line, '===') !== false && count($jsonLines) > 0) {
            break;
        }
    }
    
    $interfaceJson = implode("\n", $jsonLines);
    echo $interfaceJson . "\n\n";
    
    // Parse JSON
    $interfaceData = json_decode($interfaceJson, true);
    if ($interfaceData) {
        echo "   Parsed successfully\n";
        if (isset($interfaceData['Receipt'])) {
            echo "   Has 'Receipt' wrapper: YES\n";
            $interfaceReceipt = $interfaceData['Receipt'];
        } else {
            echo "   Has 'Receipt' wrapper: NO\n";
            $interfaceReceipt = $interfaceData;
        }
    } else {
        echo "   ERROR: Could not parse JSON\n";
        exit(1);
    }
} else {
    echo "1. INTERFACE PAYLOAD: Log file not found: $interfaceLog\n";
    echo "   Make a sale from the interface first!\n\n";
    exit(1);
}

// Read successful test payload
$testLog = APP_PATH . '/logs/error.log';
if (file_exists($testLog)) {
    echo "2. SUCCESSFUL TEST PAYLOAD (from error.log):\n";
    $testContent = file_get_contents($testLog);
    
    // Find the last successful payload (look for "Request:" with successful receipt)
    $testLines = explode("\n", $testContent);
    $testJson = null;
    foreach (array_reverse($testLines) as $line) {
        if (strpos($line, 'Request:') !== false && strpos($line, 'receiptType') !== false) {
            // Extract JSON from line
            $jsonStart = strpos($line, '{');
            if ($jsonStart !== false) {
                $testJson = substr($line, $jsonStart);
                break;
            }
        }
    }
    
    if ($testJson) {
        echo $testJson . "\n\n";
        $testData = json_decode($testJson, true);
        if ($testData) {
            echo "   Parsed successfully\n";
            $testReceipt = $testData;
        } else {
            echo "   ERROR: Could not parse JSON\n";
            exit(1);
        }
    } else {
        echo "   Could not find test payload in logs\n";
        echo "   Run a successful test first!\n\n";
        exit(1);
    }
} else {
    echo "2. TEST PAYLOAD: Log file not found\n\n";
    exit(1);
}

// Compare
echo "\n3. COMPARISON:\n";
echo "========================================\n\n";

$differences = [];

// Compare receiptLines
if (isset($interfaceReceipt['receiptLines']) && isset($testReceipt['receiptLines'])) {
    echo "receiptLines:\n";
    $interfaceLines = $interfaceReceipt['receiptLines'];
    $testLines = $testReceipt['receiptLines'];
    
    if (count($interfaceLines) !== count($testLines)) {
        $differences[] = "receiptLines count: Interface=" . count($interfaceLines) . ", Test=" . count($testLines);
    }
    
    foreach ($interfaceLines as $i => $interfaceLine) {
        if (!isset($testLines[$i])) {
            $differences[] = "receiptLines[$i]: Missing in test";
            continue;
        }
        
        $testLine = $testLines[$i];
        
        // Check for taxCode
        if (isset($interfaceLine['taxCode']) && !isset($testLine['taxCode'])) {
            $differences[] = "receiptLines[$i]: Interface has taxCode='{$interfaceLine['taxCode']}', Test does not";
        }
        
        // Check field types
        foreach (['receiptLinePrice', 'receiptLineQuantity', 'receiptLineTotal', 'taxPercent'] as $field) {
            if (isset($interfaceLine[$field]) && isset($testLine[$field])) {
                $interfaceVal = $interfaceLine[$field];
                $testVal = $testLine[$field];
                if (gettype($interfaceVal) !== gettype($testVal)) {
                    $differences[] = "receiptLines[$i][$field]: Interface type=" . gettype($interfaceVal) . " ($interfaceVal), Test type=" . gettype($testVal) . " ($testVal)";
                }
            }
        }
    }
} else {
    $differences[] = "receiptLines: Missing in one or both";
}

// Compare receiptTaxes
if (isset($interfaceReceipt['receiptTaxes']) && isset($testReceipt['receiptTaxes'])) {
    echo "\nreceiptTaxes:\n";
    $interfaceTaxes = $interfaceReceipt['receiptTaxes'];
    $testTaxes = $testReceipt['receiptTaxes'];
    
    if (count($interfaceTaxes) !== count($testTaxes)) {
        $differences[] = "receiptTaxes count: Interface=" . count($interfaceTaxes) . ", Test=" . count($testTaxes);
    }
    
    foreach ($interfaceTaxes as $i => $interfaceTax) {
        if (!isset($testTaxes[$i])) {
            $differences[] = "receiptTaxes[$i]: Missing in test";
            continue;
        }
        
        $testTax = $testTaxes[$i];
        
        // Check for taxCode
        if (isset($interfaceTax['taxCode']) && !isset($testTax['taxCode'])) {
            $differences[] = "receiptTaxes[$i]: Interface has taxCode='{$interfaceTax['taxCode']}', Test does not";
        }
        
        // Check field order
        $interfaceKeys = array_keys($interfaceTax);
        $testKeys = array_keys($testTax);
        if ($interfaceKeys !== $testKeys) {
            $differences[] = "receiptTaxes[$i]: Field order differs - Interface: " . implode(', ', $interfaceKeys) . ", Test: " . implode(', ', $testKeys);
        }
    }
} else {
    $differences[] = "receiptTaxes: Missing in one or both";
}

// Compare receiptPayments
if (isset($interfaceReceipt['receiptPayments']) && isset($testReceipt['receiptPayments'])) {
    echo "\nreceiptPayments:\n";
    $interfacePayments = $interfaceReceipt['receiptPayments'];
    $testPayments = $testReceipt['receiptPayments'];
    
    foreach ($interfacePayments as $i => $interfacePayment) {
        if (!isset($testPayments[$i])) {
            continue;
        }
        
        $testPayment = $testPayments[$i];
        
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
echo "\n4. DIFFERENCES FOUND:\n";
echo "========================================\n";
if (empty($differences)) {
    echo "✅ NO DIFFERENCES FOUND!\n";
    echo "   Payloads match. The issue might be elsewhere.\n";
} else {
    foreach ($differences as $diff) {
        echo "❌ $diff\n";
    }
}

echo "\n========================================\n";


<?php
/**
 * Comprehensive Exempt Tax Combination Tester
 * Tests 100+ combinations of receipt lines with exempt, zero-rated, 5% non-VAT, and 15.5% VAT taxes
 * Runs against actual ZIMRA API and shows which combinations work
 */

// Set execution time limit
set_time_limit(600); // 10 minutes

// Define APP_PATH if not defined
if (!defined('APP_PATH')) {
    define('APP_PATH', __DIR__);
}

// Include required files
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/database.php';
require_once APP_PATH . '/includes/fiscal_helper.php';
require_once APP_PATH . '/includes/fiscal_service.php';

// Start output buffering for real-time display
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Exempt Tax Combination Tester</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #dcdcaa; }
        .info { color: #569cd6; }
        .test-case { margin: 10px 0; padding: 10px; border-left: 3px solid #569cd6; background: #252526; }
        .test-case.success { border-left-color: #4ec9b0; }
        .test-case.error { border-left-color: #f48771; }
        .summary { margin-top: 30px; padding: 20px; background: #252526; border-radius: 5px; }
        h1 { color: #4ec9b0; }
        h2 { color: #569cd6; }
        pre { background: #1e1e1e; padding: 10px; overflow-x: auto; }
        .progress { margin: 20px 0; }
        .progress-bar { width: 100%; height: 30px; background: #3c3c3c; border-radius: 5px; overflow: hidden; }
        .progress-fill { height: 100%; background: #4ec9b0; transition: width 0.3s; }
    </style>
</head>
<body>
    <h1>🧪 Comprehensive Exempt Tax Combination Tester</h1>
    <p class="info">Testing 100+ combinations of receipt lines with different tax types...</p>
    <div class="progress">
        <div class="progress-bar">
            <div class="progress-fill" id="progress" style="width: 0%"></div>
        </div>
        <div id="progress-text">Starting...</div>
    </div>
    <div id="results"></div>
<?php
flush();
ob_flush();

// Get database connection
$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get branch and device info
$branchId = 1; // Default branch
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE branch_id = ? AND is_active = 1 LIMIT 1",
    [$branchId]
);

if (!$device) {
    die("<div class='error'>❌ No active fiscal device found for branch $branchId</div></body></html>");
}

echo "<div class='info'>✓ Using device: {$device['device_serial_no']} (ID: {$device['device_id']})</div>\n";
flush();
ob_flush();

// Get applicable taxes from ZIMRA config (from database, not API)
$primaryDb = Database::getPrimaryInstance();
$config = $primaryDb->getRow(
    "SELECT * FROM fiscal_config WHERE branch_id = ? AND device_id = ?",
    [$branchId, $device['device_id']]
);

if (!$config || empty($config['applicable_taxes'])) {
    die("<div class='error'>❌ No fiscal configuration found. Please sync configuration from ZIMRA first.</div></body></html>");
}

$applicableTaxes = json_decode($config['applicable_taxes'], true);
if (empty($applicableTaxes)) {
    die("<div class='error'>❌ No applicable taxes in configuration. Please sync taxes from ZIMRA first.</div></body></html>");
}

// Find tax IDs
$exemptTax = null;
$zeroRatedTax = null;
$fivePercentTax = null;
$fifteenPointFiveTax = null;

foreach ($applicableTaxes as $tax) {
    $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : null;
    $taxCode = $tax['taxCode'] ?? '';
    $taxId = isset($tax['taxID']) ? intval($tax['taxID']) : null;
    
    // Match exempt tax (taxCode='E' or taxPercent is null with taxCode='E')
    if ($taxCode === 'E' || ($taxPercent === null && $taxCode === 'E')) {
        if (!$exemptTax) $exemptTax = $tax;
    }
    // Match zero-rated (taxPercent=0 and taxCode='C')
    if ($taxPercent == 0 && $taxCode === 'C') {
        if (!$zeroRatedTax) $zeroRatedTax = $tax;
    }
    // Match 5% non-VAT withholding (taxPercent=5, taxID=514 or taxCode='B' or empty)
    if ($taxPercent == 5 && ($taxId == 514 || $taxCode === 'B' || $taxCode === '')) {
        if (!$fivePercentTax) $fivePercentTax = $tax;
    }
    // Match 15.5% VAT (taxPercent=15.5 and taxCode='A')
    if (abs($taxPercent - 15.5) < 0.01 && $taxCode === 'A') {
        if (!$fifteenPointFiveTax) $fifteenPointFiveTax = $tax;
    }
}

echo "<div class='info'>✓ Found taxes: ";
if ($exemptTax) echo "Exempt (ID: {$exemptTax['taxID']}), ";
if ($zeroRatedTax) echo "Zero-rated (ID: {$zeroRatedTax['taxID']}), ";
if ($fivePercentTax) echo "5% (ID: {$fivePercentTax['taxID']}), ";
if ($fifteenPointFiveTax) echo "15.5% (ID: {$fifteenPointFiveTax['taxID']})";
echo "</div>\n";
flush();
ob_flush();

// Test combinations
$testCases = [];
$testNumber = 0;

// Helper function to create test case
function createTestCase($name, $lines, $description = '') {
    global $testNumber;
    $testNumber++;
    return [
        'id' => $testNumber,
        'name' => $name,
        'description' => $description,
        'lines' => $lines
    ];
}

// CATEGORY 1: Single exempt tax (various amounts)
for ($i = 1; $i <= 10; $i++) {
    $amount = $i * 100;
    $testCases[] = createTestCase(
        "Single Exempt - Amount $amount",
        [['tax' => 'exempt', 'amount' => $amount, 'qty' => 1]],
        "Single exempt item with amount $amount"
    );
}

// CATEGORY 2: Exempt + Zero-rated combinations
for ($exemptAmt = 100; $exemptAmt <= 500; $exemptAmt += 100) {
    for ($zeroAmt = 100; $zeroAmt <= 500; $zeroAmt += 100) {
        $testCases[] = createTestCase(
            "Exempt ($exemptAmt) + Zero-rated ($zeroAmt)",
            [
                ['tax' => 'exempt', 'amount' => $exemptAmt, 'qty' => 1],
                ['tax' => 'zero', 'amount' => $zeroAmt, 'qty' => 1]
            ]
        );
    }
}

// CATEGORY 3: Exempt + 5% combinations
for ($exemptAmt = 100; $exemptAmt <= 500; $exemptAmt += 100) {
    for ($fiveAmt = 100; $fiveAmt <= 500; $fiveAmt += 100) {
        $testCases[] = createTestCase(
            "Exempt ($exemptAmt) + 5% ($fiveAmt)",
            [
                ['tax' => 'exempt', 'amount' => $exemptAmt, 'qty' => 1],
                ['tax' => 'five', 'amount' => $fiveAmt, 'qty' => 1]
            ]
        );
    }
}

// CATEGORY 4: Exempt + 15.5% combinations
for ($exemptAmt = 100; $exemptAmt <= 500; $exemptAmt += 100) {
    for ($fifteenAmt = 100; $fifteenAmt <= 500; $fifteenAmt += 100) {
        $testCases[] = createTestCase(
            "Exempt ($exemptAmt) + 15.5% ($fifteenAmt)",
            [
                ['tax' => 'exempt', 'amount' => $exemptAmt, 'qty' => 1],
                ['tax' => 'fifteen', 'amount' => $fifteenAmt, 'qty' => 1]
            ]
        );
    }
}

// CATEGORY 5: Exempt + Zero-rated + 5% combinations
for ($exemptAmt = 200; $exemptAmt <= 400; $exemptAmt += 100) {
    for ($zeroAmt = 200; $zeroAmt <= 400; $zeroAmt += 100) {
        for ($fiveAmt = 200; $fiveAmt <= 400; $fiveAmt += 100) {
            $testCases[] = createTestCase(
                "Exempt ($exemptAmt) + Zero ($zeroAmt) + 5% ($fiveAmt)",
                [
                    ['tax' => 'exempt', 'amount' => $exemptAmt, 'qty' => 1],
                    ['tax' => 'zero', 'amount' => $zeroAmt, 'qty' => 1],
                    ['tax' => 'five', 'amount' => $fiveAmt, 'qty' => 1]
                ]
            );
        }
    }
}

// CATEGORY 6: Exempt + Zero-rated + 15.5% combinations
for ($exemptAmt = 200; $exemptAmt <= 400; $exemptAmt += 100) {
    for ($zeroAmt = 200; $zeroAmt <= 400; $zeroAmt += 100) {
        for ($fifteenAmt = 200; $fifteenAmt <= 400; $fifteenAmt += 100) {
            $testCases[] = createTestCase(
                "Exempt ($exemptAmt) + Zero ($zeroAmt) + 15.5% ($fifteenAmt)",
                [
                    ['tax' => 'exempt', 'amount' => $exemptAmt, 'qty' => 1],
                    ['tax' => 'zero', 'amount' => $zeroAmt, 'qty' => 1],
                    ['tax' => 'fifteen', 'amount' => $fifteenAmt, 'qty' => 1]
                ]
            );
        }
    }
}

// CATEGORY 7: Exempt + 5% + 15.5% combinations
for ($exemptAmt = 200; $exemptAmt <= 400; $exemptAmt += 100) {
    for ($fiveAmt = 200; $fiveAmt <= 400; $fiveAmt += 100) {
        for ($fifteenAmt = 200; $fifteenAmt <= 400; $fifteenAmt += 100) {
            $testCases[] = createTestCase(
                "Exempt ($exemptAmt) + 5% ($fiveAmt) + 15.5% ($fifteenAmt)",
                [
                    ['tax' => 'exempt', 'amount' => $exemptAmt, 'qty' => 1],
                    ['tax' => 'five', 'amount' => $fiveAmt, 'qty' => 1],
                    ['tax' => 'fifteen', 'amount' => $fifteenAmt, 'qty' => 1]
                ]
            );
        }
    }
}

// CATEGORY 8: All four tax types
for ($exemptAmt = 200; $exemptAmt <= 300; $exemptAmt += 100) {
    for ($zeroAmt = 200; $zeroAmt <= 300; $zeroAmt += 100) {
        for ($fiveAmt = 200; $fiveAmt <= 300; $fiveAmt += 100) {
            for ($fifteenAmt = 200; $fifteenAmt <= 300; $fifteenAmt += 100) {
                $testCases[] = createTestCase(
                    "All 4: Exempt ($exemptAmt) + Zero ($zeroAmt) + 5% ($fiveAmt) + 15.5% ($fifteenAmt)",
                    [
                        ['tax' => 'exempt', 'amount' => $exemptAmt, 'qty' => 1],
                        ['tax' => 'zero', 'amount' => $zeroAmt, 'qty' => 1],
                        ['tax' => 'five', 'amount' => $fiveAmt, 'qty' => 1],
                        ['tax' => 'fifteen', 'amount' => $fifteenAmt, 'qty' => 1]
                    ]
                );
            }
        }
    }
}

// CATEGORY 9: Multiple exempt items
for ($i = 2; $i <= 5; $i++) {
    $testCases[] = createTestCase(
        "$i Exempt Items",
        array_fill(0, $i, ['tax' => 'exempt', 'amount' => 200, 'qty' => 1])
    );
}

// CATEGORY 10: Known working combinations (no exempt)
$testCases[] = createTestCase("3x 15.5% + 1x Zero-rated", [
    ['tax' => 'fifteen', 'amount' => 450, 'qty' => 1],
    ['tax' => 'fifteen', 'amount' => 450, 'qty' => 1],
    ['tax' => 'fifteen', 'amount' => 450, 'qty' => 1],
    ['tax' => 'zero', 'amount' => 450, 'qty' => 1]
]);

$testCases[] = createTestCase("2x 5% + 2x Zero-rated", [
    ['tax' => 'five', 'amount' => 450, 'qty' => 1],
    ['tax' => 'five', 'amount' => 450, 'qty' => 1],
    ['tax' => 'zero', 'amount' => 450, 'qty' => 1],
    ['tax' => 'zero', 'amount' => 450, 'qty' => 1]
]);

$totalTests = count($testCases);
echo "<div class='info'>✓ Generated $totalTests test cases</div>\n";
flush();
ob_flush();

// Results storage
$results = [
    'success' => [],
    'failed' => [],
    'errors' => []
];

// Run tests
$currentTest = 0;
foreach ($testCases as $testCase) {
    $currentTest++;
    $progress = ($currentTest / $totalTests) * 100;
    
    echo "<script>document.getElementById('progress').style.width = '$progress%'; document.getElementById('progress-text').innerHTML = 'Test $currentTest/$totalTests: {$testCase['name']}';</script>\n";
    flush();
    ob_flush();
    
    echo "<div class='test-case' id='test-{$testCase['id']}'>\n";
    echo "<strong>Test #{$testCase['id']}: {$testCase['name']}</strong><br>\n";
    
    try {
        // Create a test sale
        $saleData = [
            'branch_id' => $branchId,
            'user_id' => 1, // System user
            'customer_id' => null,
            'sale_date' => date('Y-m-d H:i:s'),
            'subtotal' => 0,
            'discount_amount' => 0,
            'delivery_cost' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'payment_status' => 'paid',
            'receipt_number' => 'TEST-' . date('YmdHis') . '-' . $testCase['id']
        ];
        
        // Calculate totals
        $subtotal = 0;
        foreach ($testCase['lines'] as $line) {
            $subtotal += $line['amount'] * $line['qty'];
        }
        $saleData['subtotal'] = $subtotal;
        $saleData['total_amount'] = $subtotal; // Will be adjusted by fiscalization
        
        // Create sale
        $saleId = $db->insert('sales', $saleData);
        
        if (!$saleId) {
            throw new Exception("Failed to create sale: " . $db->getLastError());
        }
        
        // Create sale items
        $lineNo = 1;
        foreach ($testCase['lines'] as $line) {
            $tax = null;
            $taxId = null;
            
            switch ($line['tax']) {
                case 'exempt':
                    $tax = $exemptTax;
                    break;
                case 'zero':
                    $tax = $zeroRatedTax;
                    break;
                case 'five':
                    $tax = $fivePercentTax;
                    break;
                case 'fifteen':
                    $tax = $fifteenPointFiveTax;
                    break;
            }
            
            if (!$tax) {
                throw new Exception("Tax type '{$line['tax']}' not found in applicable taxes");
            }
            
            $taxId = $tax['taxID'];
            $unitPrice = $line['amount'] / $line['qty'];
            
            // Get or create a product with the correct tax_id for this line
            // Use different product IDs for different tax types to avoid conflicts
            $productId = $lineNo + 1000; // Unique product ID per line
            $product = $db->getRow("SELECT * FROM products WHERE id = ?", [$productId]);
            if (!$product) {
                // Create a dummy product if it doesn't exist
                $db->insert('products', [
                    'id' => $productId,
                    'name' => "Test Product {$lineNo}",
                    'tax_id' => $taxId,
                    'category_id' => 1,
                    'is_active' => 1,
                    'sku' => 'TEST-' . $productId
                ]);
            } else {
                // Update product tax_id to match this line's tax
                $db->update('products', ['tax_id' => $taxId], ['id' => $productId]);
            }
            
            $itemData = [
                'sale_id' => $saleId,
                'product_id' => $productId,
                'product_name' => "Test Item {$lineNo} ({$line['tax']})",
                'quantity' => $line['qty'],
                'unit_price' => $unitPrice,
                'total_price' => $line['amount']
            ];
            
            $itemId = $db->insert('sale_items', $itemData);
            
            $lineNo++;
        }
        
        // Get or create currency
        $currency = $db->getRow("SELECT * FROM currencies WHERE code = 'USD' LIMIT 1");
        if (!$currency) {
            $currencyId = $db->insert('currencies', [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'decimal_places' => 2,
                'is_active' => 1
            ]);
            $currency = ['id' => $currencyId];
        }
        
        // Create payment
        $paymentData = [
            'sale_id' => $saleId,
            'payment_method' => 'Cash',
            'amount' => $saleData['total_amount'],
            'currency_id' => $currency['id']
        ];
        $db->insert('sale_payments', $paymentData);
        
        // Try to fiscalize
        $fiscalResult = fiscalizeSale($saleId, $branchId, $db);
        
        // Check result
        if ($fiscalResult === false) {
            throw new Exception("Fiscalization returned false");
        }
        
        if (is_array($fiscalResult) && isset($fiscalResult['receiptID'])) {
            // Check for validation errors
            $hasErrors = false;
            $errorCodes = [];
            
            if (isset($fiscalResult['validationErrors']) && !empty($fiscalResult['validationErrors'])) {
                $hasErrors = true;
                foreach ($fiscalResult['validationErrors'] as $error) {
                    $errorCodes[] = $error['validationErrorCode'] ?? 'UNKNOWN';
                }
            }
            
            if (!$hasErrors) {
                $results['success'][] = [
                    'test' => $testCase,
                    'receiptID' => $fiscalResult['receiptID'],
                    'hash' => $fiscalResult['receiptServerSignature']['hash'] ?? null,
                    'receiptGlobalNo' => $fiscalResult['receiptGlobalNo'] ?? null
                ];
                echo "<span class='success'>✓ SUCCESS - Receipt ID: {$fiscalResult['receiptID']}, Global No: " . ($fiscalResult['receiptGlobalNo'] ?? 'N/A') . "</span><br>\n";
            } else {
                $results['failed'][] = [
                    'test' => $testCase,
                    'receiptID' => $fiscalResult['receiptID'] ?? null,
                    'errors' => $errorCodes,
                    'receiptGlobalNo' => $fiscalResult['receiptGlobalNo'] ?? null
                ];
                echo "<span class='error'>✗ FAILED - Errors: " . implode(', ', $errorCodes) . "</span><br>\n";
                if (isset($fiscalResult['receiptID'])) {
                    echo "<span class='warning'>  Receipt ID: {$fiscalResult['receiptID']} (accepted but has validation errors)</span><br>\n";
                }
            }
        } elseif ($fiscalResult === true) {
            // Fiscalization succeeded but no detailed result - check database
            $fiscalReceipt = $primaryDb->getRow(
                "SELECT * FROM fiscal_receipts WHERE sale_id = ? ORDER BY id DESC LIMIT 1",
                [$saleId]
            );
            if ($fiscalReceipt) {
                $results['success'][] = [
                    'test' => $testCase,
                    'receiptID' => $fiscalReceipt['receipt_id'] ?? 'N/A',
                    'hash' => null,
                    'receiptGlobalNo' => $fiscalReceipt['receipt_global_no'] ?? null
                ];
                echo "<span class='success'>✓ SUCCESS - Fiscalized (Receipt ID: " . ($fiscalReceipt['receipt_id'] ?? 'N/A') . ")</span><br>\n";
            } else {
                $results['success'][] = [
                    'test' => $testCase,
                    'receiptID' => 'N/A',
                    'hash' => null
                ];
                echo "<span class='success'>✓ SUCCESS - Fiscalized (no detailed result)</span><br>\n";
            }
        } else {
            throw new Exception("Fiscalization failed: " . (is_string($fiscalResult) ? $fiscalResult : 'Unknown error'));
        }
        
        // Clean up test sale (optional - comment out to keep for debugging)
        // $db->delete('sale_payments', ['sale_id' => $saleId]);
        // $db->delete('sale_items', ['sale_id' => $saleId]);
        // $db->delete('sales', ['id' => $saleId]);
        
    } catch (Exception $e) {
        $results['errors'][] = [
            'test' => $testCase,
            'error' => $e->getMessage()
        ];
        echo "<span class='error'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
    }
    
    echo "</div>\n";
    flush();
    ob_flush();
    
    // Small delay to avoid overwhelming the API
    usleep(100000); // 0.1 second
}

// Summary
echo "<div class='summary'>\n";
echo "<h2>📊 Test Summary</h2>\n";
echo "<div class='success'>✓ Successful: " . count($results['success']) . "</div>\n";
echo "<div class='error'>✗ Failed: " . count($results['failed']) . "</div>\n";
echo "<div class='warning'>⚠ Errors: " . count($results['errors']) . "</div>\n";

if (!empty($results['success'])) {
    echo "<h3>✅ Successful Combinations:</h3>\n";
    echo "<pre>";
    foreach ($results['success'] as $result) {
        echo "Test #{$result['test']['id']}: {$result['test']['name']}\n";
        echo "  Receipt ID: {$result['receiptID']}\n";
        echo "  Hash: " . substr($result['hash'], 0, 30) . "...\n\n";
    }
    echo "</pre>\n";
}

if (!empty($results['failed'])) {
    echo "<h3>❌ Failed Combinations (" . count($results['failed']) . ") with validation errors:</h3>\n";
    echo "<pre>";
    foreach ($results['failed'] as $result) {
        echo "Test #{$result['test']['id']}: {$result['test']['name']}\n";
        echo "  Errors: " . implode(', ', $result['errors']) . "\n";
        if ($result['receiptID']) {
            echo "  Receipt ID: {$result['receiptID']}\n";
        }
        if (isset($result['receiptGlobalNo'])) {
            echo "  Receipt Global No: {$result['receiptGlobalNo']}\n";
        }
        echo "  Lines: " . count($result['test']['lines']) . "\n";
        foreach ($result['test']['lines'] as $line) {
            echo "    - {$line['tax']}: {$line['amount']} x {$line['qty']}\n";
        }
        echo "\n";
    }
    echo "</pre>\n";
}

if (!empty($results['errors'])) {
    echo "<h3>⚠️ Errors (exceptions):</h3>\n";
    echo "<pre>";
    foreach ($results['errors'] as $result) {
        echo "Test #{$result['test']['id']}: {$result['test']['name']}\n";
        echo "  Error: {$result['error']}\n\n";
    }
    echo "</pre>\n";
}

echo "</div>\n";
?>
    <script>
        document.getElementById('progress').style.width = '100%';
        document.getElementById('progress-text').innerHTML = 'Complete!';
    </script>
</body>
</html>
<?php
ob_end_flush();
?>

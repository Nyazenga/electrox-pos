<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';

echo "=== Testing Verify Taxpayer Information ===\n\n";

// Test combinations
$tests = [
    [
        'name' => 'Device 30199 (Head Office)',
        'device_id' => 30199,
        'activation_key' => '00544726',
        'serial_no' => 'electrox-1'
    ],
    [
        'name' => 'Device 30200 (Hillside)',
        'device_id' => 30200,
        'activation_key' => '00294543',
        'serial_no' => 'electrox-2'
    ]
];

$api = new ZimraApi('Server', 'v1', true);

foreach ($tests as $test) {
    echo "Testing: {$test['name']}\n";
    echo "  Device ID: {$test['device_id']}\n";
    echo "  Activation Key: {$test['activation_key']}\n";
    echo "  Serial No: {$test['serial_no']}\n";
    
    try {
        $result = $api->verifyTaxpayerInformation(
            $test['device_id'],
            $test['activation_key'],
            $test['serial_no']
        );
        
        echo "  ✓ SUCCESS!\n";
        echo "    Taxpayer: {$result['taxPayerName']}\n";
        echo "    TIN: {$result['taxPayerTIN']}\n";
        if (isset($result['vatNumber'])) {
            echo "    VAT: {$result['vatNumber']}\n";
        }
        echo "    Branch: {$result['deviceBranchName']}\n";
        echo "\n";
        
    } catch (Exception $e) {
        echo "  ✗ FAILED: " . $e->getMessage() . "\n";
        echo "\n";
    }
}


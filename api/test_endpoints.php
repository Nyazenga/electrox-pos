<?php
/**
 * Test all API endpoints
 * Run: php api/test_endpoints.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

echo "=== ELECTROX-POS API ENDPOINT TESTING ===\n\n";

// Test 1: Authentication
echo "1. Testing Authentication...\n";
$auth = Auth::getInstance();
$result = $auth->login('admin@electrox.co.zw', 'Admin@123', 'primary');
if ($result['success']) {
    echo "   ✓ Login successful\n";
    echo "   User ID: " . $_SESSION['user_id'] . "\n";
    $token = base64_encode(json_encode([
        'user_id' => $_SESSION['user_id'],
        'email' => $_SESSION['email'],
        'expires' => time() + (24 * 60 * 60)
    ]));
    echo "   Token generated: " . substr($token, 0, 20) . "...\n\n";
} else {
    echo "   ✗ Login failed: " . $result['message'] . "\n\n";
    exit(1);
}

// Test 2: Check all endpoint files exist
echo "2. Checking endpoint files...\n";
$endpoints = [
    'auth.php',
    'products.php',
    'categories.php',
    'sales.php',
    'invoices.php',
    'customers.php',
    'suppliers.php',
    'tradeins.php',
    'branches.php',
    'users.php',
    'inventory.php',
    'refunds.php',
    'shifts.php',
    'reports.php'
];

$missing = [];
foreach ($endpoints as $endpoint) {
    $file = __DIR__ . '/api/v1/' . $endpoint;
    if (file_exists($file)) {
        echo "   ✓ $endpoint exists\n";
    } else {
        echo "   ✗ $endpoint MISSING\n";
        $missing[] = $endpoint;
    }
}

if (!empty($missing)) {
    echo "\n   Missing endpoints: " . implode(', ', $missing) . "\n";
} else {
    echo "\n   All endpoint files exist!\n";
}

echo "\n3. Testing endpoint accessibility...\n";

// Test API endpoints via HTTP
$baseUrl = 'http://localhost/electrox-pos/api/v1';
$tests = [
    ['GET', '/auth', false, 'Should fail without credentials'],
    ['GET', '/products', true, 'Get products'],
    ['GET', '/categories', true, 'Get categories'],
    ['GET', '/sales', true, 'Get sales'],
    ['GET', '/invoices', true, 'Get invoices'],
    ['GET', '/customers', true, 'Get customers'],
    ['GET', '/suppliers', true, 'Get suppliers'],
    ['GET', '/tradeins', true, 'Get trade-ins'],
    ['GET', '/branches', true, 'Get branches'],
    ['GET', '/users', true, 'Get users'],
    ['GET', '/inventory', true, 'Get inventory'],
    ['GET', '/refunds', true, 'Get refunds'],
    ['GET', '/shifts', true, 'Get shifts'],
    ['GET', '/reports/sales-summary', true, 'Get sales summary'],
];

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    list($method, $path, $needsAuth, $description) = $test;
    
    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200 || $httpCode == 201) {
        echo "   ✓ $description (HTTP $httpCode)\n";
        $passed++;
    } elseif ($httpCode == 401 && !$needsAuth) {
        echo "   ✓ $description (Expected 401)\n";
        $passed++;
    } else {
        echo "   ✗ $description (HTTP $httpCode)\n";
        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data['message'])) {
                echo "      Error: " . $data['message'] . "\n";
            }
        }
        $failed++;
    }
}

echo "\n=== TEST SUMMARY ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n";

if ($failed > 0) {
    exit(1);
}


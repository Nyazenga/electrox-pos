<?php
/**
 * Comprehensive API Endpoint Testing Script
 * Run: php api/test_all_endpoints.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

echo "=== ELECTROX-POS API COMPREHENSIVE TESTING ===\n\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'errors' => []
];

// Test 1: Authentication
echo "1. Testing Authentication...\n";
$auth = Auth::getInstance();
$result = $auth->login('admin@electrox.co.zw', 'Admin@123', 'primary');
if ($result['success']) {
    echo "   ✓ Login successful\n";
    $token = base64_encode(json_encode([
        'user_id' => $_SESSION['user_id'],
        'email' => $_SESSION['email'],
        'expires' => time() + (24 * 60 * 60)
    ]));
    echo "   Token: " . substr($token, 0, 30) . "...\n\n";
    $results['passed']++;
} else {
    echo "   ✗ Login failed: " . $result['message'] . "\n\n";
    $results['failed']++;
    $results['errors'][] = "Authentication failed";
    exit(1);
}

// Test all endpoints
$baseUrl = 'http://localhost/electrox-pos/api/v1';
$endpoints = [
    ['GET', '/products', 'Get products'],
    ['GET', '/categories', 'Get categories'],
    ['GET', '/sales', 'Get sales'],
    ['GET', '/invoices', 'Get invoices'],
    ['GET', '/customers', 'Get customers'],
    ['GET', '/suppliers', 'Get suppliers'],
    ['GET', '/tradeins', 'Get trade-ins'],
    ['GET', '/branches', 'Get branches'],
    ['GET', '/users', 'Get users'],
    ['GET', '/inventory', 'Get inventory'],
    ['GET', '/inventory/grn', 'Get GRNs'],
    ['GET', '/refunds', 'Get refunds'],
    ['GET', '/shifts', 'Get shifts'],
    ['GET', '/roles', 'Get roles'],
    ['GET', '/currencies', 'Get currencies'],
    ['GET', '/transfers', 'Get transfers'],
    ['GET', '/reports/sales-summary', 'Get sales summary'],
];

echo "2. Testing GET endpoints...\n";
foreach ($endpoints as $test) {
    list($method, $path, $description) = $test;
    
    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode == 200 || $httpCode == 201) {
        $data = json_decode($response, true);
        if ($data && isset($data['success']) && $data['success']) {
            echo "   ✓ $description (HTTP $httpCode)\n";
            $results['passed']++;
        } else {
            echo "   ✗ $description - Response indicates failure\n";
            if ($data && isset($data['message'])) {
                echo "      Error: " . $data['message'] . "\n";
            }
            $results['failed']++;
            $results['errors'][] = "$description: Invalid response";
        }
    } else {
        echo "   ✗ $description (HTTP $httpCode)\n";
        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data['message'])) {
                echo "      Error: " . $data['message'] . "\n";
            }
        }
        if ($error) {
            echo "      Curl Error: $error\n";
        }
        $results['failed']++;
        $results['errors'][] = "$description: HTTP $httpCode";
    }
}

echo "\n3. Checking endpoint files...\n";
$requiredFiles = [
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
    'reports.php',
    'roles.php',
    'currencies.php',
    'transfers.php'
];

$missing = [];
foreach ($requiredFiles as $file) {
    $filePath = __DIR__ . '/v1/' . $file;
    if (file_exists($filePath)) {
        echo "   ✓ $file exists\n";
    } else {
        echo "   ✗ $file MISSING\n";
        $missing[] = $file;
        $results['failed']++;
    }
}

if (!empty($missing)) {
    $results['errors'][] = "Missing files: " . implode(', ', $missing);
}

echo "\n=== TEST SUMMARY ===\n";
echo "Passed: " . $results['passed'] . "\n";
echo "Failed: " . $results['failed'] . "\n";
echo "Total Tests: " . ($results['passed'] + $results['failed']) . "\n";

if (!empty($results['errors'])) {
    echo "\nErrors:\n";
    foreach ($results['errors'] as $error) {
        echo "  - $error\n";
    }
}

if ($results['failed'] > 0) {
    exit(1);
} else {
    echo "\n✓ All tests passed!\n";
}



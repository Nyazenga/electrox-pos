<?php
// Test expiry notifications
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/mailer.php';

$emailRecipient = 'nyazengamd@gmail.com';

echo "Testing expiry notifications...\n";
flush();

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Check all products with expiry dates
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE status = 'Active' AND expiry_date IS NOT NULL");
    $result = $stmt->fetch();
    echo "Products with expiry dates: " . $result['count'] . "\n";
    flush();
    
    // Check products expiring within 3 months
    $threeMonthsFromNow = date('Y-m-d', strtotime('+3 months'));
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE status = 'Active' AND expiry_date IS NOT NULL AND expiry_date >= :today AND expiry_date <= :three_months");
    $stmt->execute([':today' => $today, ':three_months' => $threeMonthsFromNow]);
    $result = $stmt->fetch();
    echo "Products expiring within 3 months: " . $result['count'] . "\n";
    flush();
    
    if ($result['count'] == 0) {
        echo "No products expiring - creating a test product with expiry date...\n";
        flush();
        
        // Create a test product that expires in 2 months for testing
        $testExpiryDate = date('Y-m-d', strtotime('+2 months'));
        $stmt = $pdo->prepare("INSERT INTO products (product_code, product_name, category_id, branch_id, status, quantity_in_stock, reorder_level, expiry_date, created_at) VALUES ('TEST-EXPIRY-001', 'Test Expiry Product', 1, 1, 'Active', 10, 5, :expiry_date, NOW())");
        $stmt->execute([':expiry_date' => $testExpiryDate]);
        echo "Test product created with expiry date: $testExpiryDate\n";
        flush();
        
        // Run expiry cron
        echo "Running expiry notifications cron...\n";
        flush();
        include APP_PATH . '/cron/expiry_notifications.php';
        echo "Expiry cron executed\n";
        flush();
        
        // Clean up test product
        $pdo->exec("DELETE FROM products WHERE product_code = 'TEST-EXPIRY-001'");
        echo "Test product cleaned up\n";
    } else {
        echo "Products found - running expiry cron...\n";
        flush();
        include APP_PATH . '/cron/expiry_notifications.php';
        echo "Expiry cron executed\n";
        flush();
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    flush();
}

echo "Done\n";


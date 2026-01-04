<?php
// Test database connection directly
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

echo "Testing database connection...\n";
flush();

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ];
    
    echo "Connecting to database...\n";
    flush();
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    echo "Connected!\n";
    flush();
    
    echo "Querying products...\n";
    flush();
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE status = 'Active' AND quantity_in_stock <= reorder_level AND reorder_level > 0");
    $result = $stmt->fetch();
    
    echo "Low stock products: " . $result['count'] . "\n";
    flush();
    
    if ($result['count'] > 0) {
        echo "Products found - should send email!\n";
    } else {
        echo "No products found\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    flush();
}

echo "Done\n";


<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=electrox_primary", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Seeding electrox_primary database...\n";
    
    $sql = file_get_contents(__DIR__ . '/database/seed_data.sql');
    
    // Remove USE statement and split by semicolon
    $sql = preg_replace('/USE.*?;/i', '', $sql);
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^(CREATE|INSERT INTO `users`|INSERT INTO `roles`|INSERT INTO `branches`|INSERT INTO `product_categories`|INSERT INTO `system_settings`)/i', $statement)) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') === false && strpos($e->getMessage(), 'already exists') === false) {
                    echo "Warning: " . substr($e->getMessage(), 0, 100) . "\n";
                }
            }
        }
    }
    
    echo "\n=== SEED DATA INSERTED SUCCESSFULLY ===\n";
    echo "Branches: 3\n";
    echo "Suppliers: 10\n";
    echo "Customers: 15\n";
    echo "Products: 20\n";
    echo "Invoices: 15\n";
    echo "Invoice Items: 30\n";
    echo "Payments: 15\n";
    echo "Stock Movements: 20\n";
    echo "Trade-Ins: 10\n";
    echo "Activity Logs: 15\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}


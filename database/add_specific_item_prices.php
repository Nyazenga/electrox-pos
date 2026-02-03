<?php
/**
 * Migration: Add price columns to product_specific_list table
 * This allows each specific item (e.g., 128GB iPhone vs 256GB iPhone) to have different prices
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Connect directly to the tenant database
$host = 'localhost';
$dbname = 'electrox_primary';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Adding Price Columns to product_specific_list ===\n\n";
    
    // Check if columns already exist
    $stmt = $pdo->query("SHOW COLUMNS FROM product_specific_list WHERE Field = 'cost_price'");
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($result)) {
        $sql = "ALTER TABLE product_specific_list 
                ADD COLUMN cost_price DECIMAL(10,2) DEFAULT NULL AFTER warranty_months,
                ADD COLUMN selling_price DECIMAL(10,2) DEFAULT NULL AFTER cost_price,
                ADD COLUMN wholesale_price DECIMAL(10,2) DEFAULT NULL AFTER selling_price";
        $pdo->exec($sql);
        echo "✓ Added price columns to product_specific_list\n";
    } else {
        echo "✓ Price columns already exist\n";
    }
    
    echo "\nMigration complete!\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
} catch (PDOException $e) {
    echo "✗ PDO Error: " . $e->getMessage() . "\n";
    exit(1);
}

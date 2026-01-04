<?php
/**
 * Export products table structure and data from localhost
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

// Connect to localhost database
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'electrox_primary';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    echo "Connected to localhost $dbname\n\n";
    
    // Export structure
    echo "Exporting table structure...\n";
    $stmt = $pdo->query("SHOW CREATE TABLE products");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $createTable = $result['Create Table'];
    
    $structureFile = __DIR__ . '/products_structure_only.sql';
    file_put_contents($structureFile, "-- Table structure for products\n");
    file_put_contents($structureFile, "DROP TABLE IF EXISTS `products`;\n", FILE_APPEND);
    file_put_contents($structureFile, $createTable . ";\n", FILE_APPEND);
    echo "✓ Structure exported to: $structureFile\n\n";
    
    // Export data
    echo "Exporting product data...\n";
    $stmt = $pdo->query("SELECT * FROM products ORDER BY id");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($products)) {
        echo "⚠️  No products found in localhost database\n";
        exit(1);
    }
    
    // Get column names
    $columns = array_keys($products[0]);
    $columnList = '`' . implode('`, `', $columns) . '`';
    
    $dataFile = __DIR__ . '/products_data_only.sql';
    file_put_contents($dataFile, "-- Product data\n");
    file_put_contents($dataFile, "INSERT INTO `products` ($columnList) VALUES\n", FILE_APPEND);
    
    $rows = [];
    foreach ($products as $product) {
        $values = [];
        foreach ($columns as $col) {
            $value = $product[$col];
            if ($value === null) {
                $values[] = 'NULL';
            } elseif (is_numeric($value)) {
                $values[] = $value;
            } else {
                $values[] = $pdo->quote($value);
            }
        }
        $rows[] = '(' . implode(', ', $values) . ')';
    }
    
    file_put_contents($dataFile, implode(",\n", $rows) . ";\n", FILE_APPEND);
    echo "✓ Data exported: " . count($products) . " products to: $dataFile\n\n";
    
    echo "✅ Export complete!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}


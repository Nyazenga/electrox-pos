<?php
/**
 * Export products table from localhost database
 */

// Connect to localhost database
$host = 'localhost';
$user = 'root';
$pass = ''; // XAMPP default
$dbname = 'electrox_primary';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    echo "Connected to localhost $dbname\n";
    
    // Get table structure
    $stmt = $pdo->query("SHOW CREATE TABLE products");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $createTable = $result['Create Table'];
    
    // Get all products
    $stmt = $pdo->query("SELECT * FROM products ORDER BY id");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($products) . " products\n";
    
    if (empty($products)) {
        die("No products found!\n");
    }
    
    // Build SQL file
    $sql = "-- Products table export\n";
    $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $sql .= "START TRANSACTION;\n\n";
    $sql .= "DROP TABLE IF EXISTS `products`;\n\n";
    $sql .= $createTable . ";\n\n";
    $sql .= "-- Dumping data for table `products`\n\n";
    
    // Get column names
    $columns = array_keys($products[0]);
    $columnList = '`' . implode('`, `', $columns) . '`';
    
    $sql .= "INSERT INTO `products` ($columnList) VALUES\n";
    
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
                // Escape single quotes
                $value = str_replace("'", "''", $value);
                $values[] = "'" . $value . "'";
            }
        }
        $rows[] = '(' . implode(', ', $values) . ')';
    }
    
    $sql .= implode(",\n", $rows) . ";\n\n";
    $sql .= "COMMIT;\n";
    
    // Write to file
    $outputFile = __DIR__ . '/products_clean.sql';
    file_put_contents($outputFile, $sql);
    
    echo "✓ Exported to: $outputFile\n";
    echo "File size: " . filesize($outputFile) . " bytes\n";
    echo "✅ Export complete!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}


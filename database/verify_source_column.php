<?php
/**
 * Verify source column exists and is configured correctly
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

echo "Verifying source column...\n\n";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Check column
    $stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
    $column = $stmt->fetch();
    
    if ($column) {
        echo "✓ Column 'source' exists\n";
        echo "  Type: {$column['Type']}\n";
        echo "  Default: {$column['Default']}\n";
        echo "  Null: {$column['Null']}\n";
        
        // Check if enum values are correct
        if (strpos($column['Type'], "enum('manual','bulk_upload')") !== false) {
            echo "  ✓ Enum values are correct\n";
        } else {
            echo "  ⚠ Enum values may be incorrect: {$column['Type']}\n";
        }
    } else {
        echo "❌ Column 'source' does NOT exist\n";
        exit(1);
    }
    
    // Check index
    $stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_source'");
    $index = $stmt->fetch();
    
    if ($index) {
        echo "\n✓ Index 'idx_source' exists\n";
    } else {
        echo "\n⚠ Index 'idx_source' does NOT exist\n";
    }
    
    // Check data distribution
    echo "\nData distribution:\n";
    $stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
    $results = $stmt->fetchAll();
    foreach ($results as $row) {
        $source = $row['source'] ?? 'NULL';
        echo "  - {$source}: {$row['count']} product(s)\n";
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total = $stmt->fetch()['total'];
    echo "  - Total: $total product(s)\n";
    
    echo "\n✅ Verification complete!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}


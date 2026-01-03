<?php
/**
 * Script to restore products from localhost backup to live server
 * This reads the products_backup.sql file and inserts products
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

// Read the backup file
$backupFile = dirname(__FILE__) . '/products_backup.sql';
if (!file_exists($backupFile)) {
    die("Backup file not found: $backupFile\n");
}

echo "Reading backup file...\n";
$sql = file_get_contents($backupFile);

// Extract INSERT statements
preg_match_all('/INSERT INTO `products` VALUES\s*\((.*?)\);/s', $sql, $matches);

if (empty($matches[1])) {
    die("No INSERT statements found in backup file.\n");
}

echo "Found " . count($matches[1]) . " product records.\n";
echo "Starting restore...\n\n";

$db->beginTransaction();

try {
    $inserted = 0;
    $skipped = 0;
    
    foreach ($matches[1] as $values) {
        // Parse the values - handle NULL, strings, numbers
        $values = trim($values);
        
        // Build INSERT statement
        $insertSql = "INSERT INTO `products` VALUES ($values)";
        
        try {
            $db->query($insertSql);
            $inserted++;
            if ($inserted % 10 == 0) {
                echo "Inserted $inserted products...\n";
            }
        } catch (Exception $e) {
            // Check if it's a duplicate key error
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $skipped++;
            } else {
                echo "Error inserting product: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }
    
    // Update source values
    $db->query("UPDATE products SET source = 'manual' WHERE source IS NULL OR source = ''");
    
    $db->commitTransaction();
    
    echo "\n✅ Restore completed!\n";
    echo "Inserted: $inserted products\n";
    echo "Skipped (duplicates): $skipped products\n";
    
    // Verify
    $count = $db->getRow("SELECT COUNT(*) as total FROM products");
    echo "Total products in database: " . $count['total'] . "\n";
    
} catch (Exception $e) {
    $db->rollbackTransaction();
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}


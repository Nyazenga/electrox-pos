<?php
/**
 * Script to add source column to products table in ALL branch databases
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

// Get all branches
$branches = $primaryDb->getRows("SELECT * FROM branches WHERE status = 'Active'");

if (empty($branches)) {
    echo "No active branches found.\n";
    exit(1);
}

echo "Found " . count($branches) . " active branches.\n\n";

foreach ($branches as $branch) {
    $branchName = strtolower($branch['branch_name']);
    $dbName = 'electrox_' . $branchName;
    
    echo "Processing branch: {$branch['branch_name']} (database: $dbName)...\n";
    
    try {
        // Connect to branch database
        $branchDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=$dbName;charset=" . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        
        // Check if column exists
        $stmt = $branchDb->query("SHOW COLUMNS FROM products LIKE 'source'");
        $columnExists = $stmt->rowCount() > 0;
        
        if (!$columnExists) {
            // Add column
            $branchDb->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
            echo "  ✓ Column 'source' added.\n";
            
            // Add index
            $branchDb->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
            echo "  ✓ Index 'idx_source' added.\n";
            
            // Update existing products
            $branchDb->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
            echo "  ✓ Updated existing products.\n";
        } else {
            echo "  ✓ Column 'source' already exists.\n";
        }
        
        echo "  ✅ Branch '{$branch['branch_name']}' completed.\n\n";
        
    } catch (PDOException $e) {
        echo "  ❌ ERROR for branch '{$branch['branch_name']}': " . $e->getMessage() . "\n\n";
    }
}

echo "✅ Migration completed for all branches!\n";


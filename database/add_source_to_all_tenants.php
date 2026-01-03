<?php
/**
 * Script to add source column to products table in ALL tenant databases
 * This ensures the column exists regardless of which tenant is active
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

// Get all active branches (tenants)
$primaryDb = Database::getPrimaryInstance();
$branches = $primaryDb->getRows("SELECT * FROM branches WHERE status = 'Active'");

if (empty($branches)) {
    echo "No active branches found.\n";
    exit(1);
}

echo "Found " . count($branches) . " active branch(es).\n\n";

$successCount = 0;
$errorCount = 0;

foreach ($branches as $branch) {
    $branchName = strtolower($branch['branch_name']);
    $dbName = 'electrox_' . $branchName;
    
    echo "Processing branch: {$branch['branch_name']} (database: $dbName)...\n";
    
    try {
        // Connect directly to the tenant database
        $dsn = "mysql:host=" . DB_HOST . ";dbname=$dbName;charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        // Check if column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
        $columnExists = $stmt->fetch();
        
        if (!$columnExists) {
            // Add column
            $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
            echo "  ✓ Column 'source' added.\n";
        } else {
            echo "  ✓ Column 'source' already exists.\n";
        }
        
        // Check if index exists
        $stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_source'");
        $indexExists = $stmt->fetch();
        
        if (!$indexExists) {
            // Add index
            $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
            echo "  ✓ Index 'idx_source' added.\n";
        } else {
            echo "  ✓ Index 'idx_source' already exists.\n";
        }
        
        // Update existing products
        $updated = $pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
        echo "  ✓ Updated $updated product(s) to have source = 'manual'.\n";
        
        $successCount++;
        echo "  ✅ Branch '{$branch['branch_name']}' completed successfully.\n\n";
        
    } catch (PDOException $e) {
        echo "  ❌ Error for branch '{$branch['branch_name']}': " . $e->getMessage() . "\n\n";
        $errorCount++;
    }
}

echo "\n========================================\n";
echo "Summary:\n";
echo "  ✅ Successful: $successCount branch(es)\n";
echo "  ❌ Errors: $errorCount branch(es)\n";
echo "========================================\n";

if ($errorCount > 0) {
    exit(1);
}


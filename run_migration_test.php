<?php
/**
 * Test script to run the product_specific_list migration
 * This connects directly to electrox_primary using root/empty password
 * DO NOT RUN ON PRODUCTION - This is for testing only
 */

require_once __DIR__ . '/config.php';

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = PRIMARY_DB_NAME;

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    echo "Connected to database: $dbName\n";
    echo "===========================================\n\n";
    
    // Read migration file
    $migrationFile = __DIR__ . '/database/migrate_product_specific_list.sql';
    if (!file_exists($migrationFile)) {
        die("Migration file not found: $migrationFile\n");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Split by semicolons and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^--/', $stmt) && 
                   !preg_match('/^\/\*/', $stmt);
        }
    );
    
    echo "Executing migration statements...\n\n";
    
    foreach ($statements as $index => $statement) {
        // Skip comments and empty statements
        if (empty(trim($statement)) || preg_match('/^--/', $statement)) {
            continue;
        }
        
        // Handle multi-line comments
        $statement = preg_replace('/\/\*.*?\*\//s', '', $statement);
        $statement = trim($statement);
        
        if (empty($statement)) {
            continue;
        }
        
        try {
            // Handle IF NOT EXISTS for ALTER TABLE (MySQL doesn't support it natively)
            if (preg_match('/ALTER TABLE.*ADD COLUMN IF NOT EXISTS/i', $statement)) {
                $statement = preg_replace('/IF NOT EXISTS/i', '', $statement);
                // Check if column exists first
                preg_match('/ALTER TABLE `?(\w+)`?.*ADD COLUMN `?(\w+)`?/i', $statement, $matches);
                if (count($matches) >= 3) {
                    $table = $matches[1];
                    $column = $matches[2];
                    $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                    if ($check->rowCount() > 0) {
                        echo "  [SKIP] Column $column already exists in $table\n";
                        continue;
                    }
                }
            }
            
            // Handle ADD INDEX IF NOT EXISTS
            if (preg_match('/ADD INDEX IF NOT EXISTS/i', $statement)) {
                $statement = preg_replace('/IF NOT EXISTS/i', '', $statement);
                preg_match('/ADD INDEX `?(\w+)`?/i', $statement, $matches);
                if (count($matches) >= 2) {
                    $indexName = $matches[1];
                    preg_match('/ALTER TABLE `?(\w+)`?/i', $statement, $tableMatches);
                    if (count($tableMatches) >= 2) {
                        $table = $tableMatches[1];
                        $check = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
                        if ($check->rowCount() > 0) {
                            echo "  [SKIP] Index $indexName already exists in $table\n";
                            continue;
                        }
                    }
                }
            }
            
            $pdo->exec($statement);
            echo "  [OK] Statement " . ($index + 1) . " executed\n";
        } catch (PDOException $e) {
            // Some errors are expected (like column already exists)
            if (strpos($e->getMessage(), 'Duplicate column') !== false || 
                strpos($e->getMessage(), 'Duplicate key') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "  [SKIP] " . $e->getMessage() . "\n";
            } else {
                echo "  [ERROR] " . $e->getMessage() . "\n";
                echo "  Statement: " . substr($statement, 0, 100) . "...\n";
            }
        }
    }
    
    echo "\n===========================================\n";
    echo "Migration completed!\n\n";
    
    // Verify migration
    echo "Verifying migration...\n";
    $tableExists = $pdo->query("SHOW TABLES LIKE 'product_specific_list'")->rowCount() > 0;
    if ($tableExists) {
        echo "  ✓ product_specific_list table exists\n";
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM product_specific_list")->fetch()['cnt'];
        echo "  ✓ Found $count entries in product_specific_list\n";
    } else {
        echo "  ✗ product_specific_list table not found\n";
    }
    
    $columnExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'requires_specific_list'")->rowCount() > 0;
    if ($columnExists) {
        echo "  ✓ requires_specific_list column exists\n";
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM products WHERE requires_specific_list = 1")->fetch()['cnt'];
        echo "  ✓ Found $count products requiring specific list\n";
    } else {
        echo "  ✗ requires_specific_list column not found\n";
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
}

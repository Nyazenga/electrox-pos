<?php
/**
 * Script to restore products directly from localhost database to live server
 * This connects to localhost, exports products, and inserts them into live database
 */

// For live server - connect to localhost MySQL and export, then import to live
// This script should be run on the live server, but we'll read from a SQL file

require_once dirname(dirname(__FILE__)) . '/config.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$dbname = 'electrox_primary';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    echo "Connected to $dbname\n";
    
    // Read the SQL file from the root directory
    $sqlFile = dirname(dirname(__FILE__)) . '/products_backup.sql';
    if (!file_exists($sqlFile)) {
        die("Backup file not found: $sqlFile\n");
    }
    
    echo "Reading SQL file...\n";
    $sql = file_get_contents($sqlFile);
    
    // Split by semicolons but preserve within quotes
    $statements = [];
    $current = '';
    $inQuotes = false;
    $quoteChar = null;
    
    for ($i = 0; $i < strlen($sql); $i++) {
        $char = $sql[$i];
        
        if (!$inQuotes && ($char === '"' || $char === "'" || $char === '`')) {
            $inQuotes = true;
            $quoteChar = $char;
            $current .= $char;
        } elseif ($inQuotes && $char === $quoteChar && ($i == 0 || $sql[$i-1] !== '\\')) {
            $inQuotes = false;
            $quoteChar = null;
            $current .= $char;
        } elseif (!$inQuotes && $char === ';') {
            $stmt = trim($current);
            if (!empty($stmt) && stripos($stmt, 'INSERT INTO') !== false) {
                $statements[] = $stmt;
            }
            $current = '';
        } else {
            $current .= $char;
        }
    }
    
    if (!empty(trim($current)) && stripos($current, 'INSERT INTO') !== false) {
        $statements[] = trim($current);
    }
    
    echo "Found " . count($statements) . " INSERT statements\n";
    
    if (empty($statements)) {
        die("No INSERT statements found in backup file.\n");
    }
    
    echo "Starting restore...\n\n";
    
    $pdo->beginTransaction();
    
    try {
        $inserted = 0;
        $skipped = 0;
        
        foreach ($statements as $stmt) {
            try {
                // Replace the VALUES part to handle the source column
                // If the statement doesn't have enough columns, we need to add source
                $pdo->exec($stmt);
                $inserted++;
                
                if ($inserted % 10 == 0) {
                    echo "Inserted $inserted products...\n";
                }
            } catch (PDOException $e) {
                // Check if it's a duplicate key error
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $skipped++;
                } else {
                    echo "Error: " . $e->getMessage() . "\n";
                    // Continue anyway
                }
            }
        }
        
        // Update source values for all products
        echo "\nUpdating source values...\n";
        $updated = $pdo->exec("UPDATE products SET source = 'manual' WHERE source IS NULL OR source = ''");
        echo "Updated $updated products to have source = 'manual'\n";
        
        $pdo->commit();
        
        echo "\n✅ Restore completed!\n";
        echo "Inserted: $inserted products\n";
        echo "Skipped (duplicates): $skipped products\n";
        
        // Verify
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
        $count = $stmt->fetch();
        echo "Total products in database: " . $count['total'] . "\n";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}


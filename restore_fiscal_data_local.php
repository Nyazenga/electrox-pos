<?php
/**
 * Restore Fiscalization Data to Localhost
 * Imports fiscalization data from backup file
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Local database credentials
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'electrox_primary';

// Backup file path (adjust as needed)
$backupFile = __DIR__ . '/fiscal_data_backup.sql';

if (!file_exists($backupFile)) {
    echo "✗ Backup file not found: $backupFile\n";
    echo "\nPlease download the backup file from the server first:\n";
    echo "  pscp.exe -pw \"GRCAdmin123/\" root@31.97.199.82:/tmp/fiscal_data_backup_*.sql .\n";
    echo "\nThen rename it to: fiscal_data_backup.sql\n";
    exit(1);
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "Connected to local database: $dbName\n";
    echo "========================================\n\n";
    
    echo "Reading backup file: $backupFile\n";
    $sql = file_get_contents($backupFile);
    
    if (empty($sql)) {
        throw new Exception("Backup file is empty");
    }
    
    echo "File size: " . number_format(strlen($sql) / 1024, 2) . " KB\n";
    echo "\n";
    echo "Restoring fiscalization data...\n";
    echo "This will replace existing fiscalization data in local database!\n";
    echo "\n";
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^--/', $stmt) && 
                   !preg_match('/^SET/', $stmt) &&
                   !preg_match('/^LOCK/', $stmt) &&
                   !preg_match('/^UNLOCK/', $stmt);
        }
    );
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    
    $executed = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $pdo->exec($statement);
            $executed++;
            
            // Show progress for INSERT statements
            if (preg_match('/^INSERT INTO `(\w+)`/', $statement, $matches)) {
                echo "  ✓ Inserted into {$matches[1]}\n";
            } elseif (preg_match('/^DROP TABLE/', $statement)) {
                echo "  ✓ Dropped table\n";
            } elseif (preg_match('/^CREATE TABLE/', $statement)) {
                if (preg_match('/^CREATE TABLE `(\w+)`/', $statement, $matches)) {
                    echo "  ✓ Created table {$matches[1]}\n";
                }
            }
        } catch (PDOException $e) {
            $errors++;
            echo "  ✗ Error: " . $e->getMessage() . "\n";
            // Continue with next statement
        }
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    
    echo "\n";
    echo "========================================\n";
    echo "✓ Restore completed!\n";
    echo "========================================\n";
    echo "Statements executed: $executed\n";
    if ($errors > 0) {
        echo "Errors: $errors\n";
    }
    echo "\n";
    echo "Verifying data...\n";
    
    // Verify tables
    $tables = ['fiscal_devices', 'fiscal_config', 'fiscal_days', 'fiscal_receipts'];
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "  $table: $count rows\n";
    }
    
    echo "\n✓ Fiscalization data restored to localhost!\n";
    echo "You can now test fiscalization locally.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

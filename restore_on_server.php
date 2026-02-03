<?php
/**
 * Restore Database Script (Run on Server)
 */
$dbHost = 'localhost';
$dbUser = 'grcadmin';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';
$backupFile = '/tmp/electrox_primary_clean.sql';

echo "========================================\n";
echo "Restoring Database\n";
echo "========================================\n\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "Step 1: Reading backup file...\n";
    if (!file_exists($backupFile)) {
        throw new Exception("Backup file not found: $backupFile");
    }
    
    $sql = file_get_contents($backupFile);
    
    echo "Step 2: Disabling foreign key checks...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    
    echo "Step 3: Executing SQL statements...\n";
    $statements = array_filter(array_map('trim', explode(';', $sql)), function($stmt) {
        return !empty($stmt) && !preg_match('/^--/', $stmt) && !preg_match('/^\/\*/', $stmt);
    });
    
    $executed = 0;
    $errors = 0;
    
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $executed++;
            if ($executed % 100 == 0) {
                echo "  Processed $executed statements...\r";
                flush();
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') === false && 
                strpos($e->getMessage(), 'already exists') === false) {
                $errors++;
                if ($errors <= 10) {
                    echo "  ⚠ " . substr($e->getMessage(), 0, 100) . "\n";
                }
            }
        }
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    
    echo "\n\nStep 4: Verifying restore...\n";
    $tables = ['users', 'roles', 'branches', 'product_categories', 'currencies'];
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "  $table: $count rows\n";
    }
    
    echo "\n========================================\n";
    echo "✓ Restore completed!\n";
    echo "========================================\n";
    echo "Executed: $executed statements\n";
    if ($errors > 0) {
        echo "Errors (non-critical): $errors\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
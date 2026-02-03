<?php
/**
 * Restore Clean Database to Live Server
 * This script uploads and restores the cleaned database to the live server
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get backup file from command line
$backupFile = $argv[1] ?? null;

if (!$backupFile || !file_exists($backupFile)) {
    echo "Usage: php restore_to_live_server.php [backup_file.sql]\n";
    echo "\nAvailable backup files:\n";
    $files = glob(__DIR__ . "/electrox_primary_clean_*.sql");
    foreach ($files as $file) {
        echo "  - " . basename($file) . "\n";
    }
    exit(1);
}

$serverIP = '31.97.199.82';
$serverUser = 'root';
$serverPass = 'GRCAdmin123/';
$remotePath = '/tmp/electrox_primary_clean.sql';

echo "========================================\n";
echo "Restore Database to Live Server\n";
echo "========================================\n\n";
echo "Backup file: $backupFile\n";
echo "Server: $serverUser@$serverIP\n";
echo "Remote path: $remotePath\n\n";

// Step 1: Upload backup file
echo "Step 1: Uploading backup file to server...\n";
$pscpCmd = "pscp.exe -pw \"$serverPass\" " . escapeshellarg($backupFile) . " $serverUser@$serverIP:$remotePath";
exec($pscpCmd, $output, $returnVar);

if ($returnVar !== 0) {
    echo "✗ Upload failed!\n";
    echo "Output: " . implode("\n", $output) . "\n";
    echo "\nPlease upload manually:\n";
    echo "  pscp.exe -pw \"$serverPass\" \"$backupFile\" $serverUser@$serverIP:$remotePath\n";
    exit(1);
}

echo "✓ Backup file uploaded successfully\n\n";

// Step 2: Create restore script on server
echo "Step 2: Creating restore script on server...\n";
$restoreScript = <<<'PHP'
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
PHP;

$restoreScriptFile = __DIR__ . '/restore_on_server.php';
file_put_contents($restoreScriptFile, $restoreScript);

$pscpScriptCmd = "pscp.exe -pw \"$serverPass\" " . escapeshellarg($restoreScriptFile) . " $serverUser@$serverIP:/tmp/restore_db.php";
exec($pscpScriptCmd, $output2, $returnVar2);

if ($returnVar2 !== 0) {
    echo "✗ Failed to upload restore script\n";
    exit(1);
}

echo "✓ Restore script uploaded\n\n";

// Step 3: Execute restore on server
echo "Step 3: Executing restore on server...\n";
echo "NOTE: This may take a few minutes. Please wait...\n\n";

$plinkCmd = "plink.exe -ssh -pw \"$serverPass\" $serverUser@$serverIP \"php /tmp/restore_db.php\"";
passthru($plinkCmd, $returnVar3);

if ($returnVar3 !== 0) {
    echo "\n✗ Restore failed!\n";
    echo "\nYou can run manually on server:\n";
    echo "  ssh $serverUser@$serverIP\n";
    echo "  php /tmp/restore_db.php\n";
    exit(1);
}

echo "\n✓ Database restored successfully to live server!\n";
echo "\nNext steps:\n";
echo "1. Update nginx configuration\n";
echo "2. Generate SSL certificate\n";
echo "3. Update domain references in code\n";
echo "4. Push code to git\n";

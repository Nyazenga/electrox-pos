<?php
/**
 * Restore Fiscalization Data to Localhost
 * Simple version using mysql command or direct SQL import
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Local database credentials
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'electrox_primary';

// Get backup file from command line or use default
$backupFile = $argv[1] ?? __DIR__ . '/fiscal_data_backup.sql';

if (!file_exists($backupFile)) {
    echo "✗ Backup file not found: $backupFile\n";
    echo "\nUsage: php restore_fiscal_simple.php [backup_file.sql]\n";
    echo "\nOr download from server first:\n";
    echo "  pscp.exe -pw \"GRCAdmin123/\" root@31.97.199.82:/tmp/fiscal_data_backup_*.sql .\n";
    exit(1);
}

echo "Restoring fiscalization data to localhost...\n";
echo "============================================\n\n";
echo "Backup file: $backupFile\n";
echo "Target database: $dbName\n\n";

// Try using mysql command line first (faster and more reliable)
$mysqlCmd = "mysql -u $dbUser " . ($dbPass ? "-p'$dbPass' " : "") . "$dbName < " . escapeshellarg($backupFile);
echo "Executing: mysql import...\n";
flush();

$output = [];
$returnVar = 0;
exec($mysqlCmd . " 2>&1", $output, $returnVar);

if ($returnVar === 0) {
    echo "✓ Data imported successfully using mysql command!\n\n";
    
    // Verify
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        echo "Verifying imported data:\n";
        $tables = ['fiscal_devices', 'fiscal_config', 'fiscal_days', 'fiscal_receipts'];
        foreach ($tables as $table) {
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "  $table: $count rows\n";
        }
        
        // Show device info
        $devices = $pdo->query("SELECT id, branch_id, device_id, device_serial_no, is_registered, is_active FROM fiscal_devices")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($devices)) {
            echo "\nFiscal Devices:\n";
            foreach ($devices as $device) {
                echo "  - Device ID: {$device['device_id']}, Branch: {$device['branch_id']}, Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . ", Active: " . ($device['is_active'] ? 'Yes' : 'No') . "\n";
            }
        }
        
        // Show fiscal days status
        $fiscalDays = $pdo->query("SELECT branch_id, device_id, fiscal_day_no, status FROM fiscal_days ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($fiscalDays)) {
            echo "\nRecent Fiscal Days:\n";
            foreach ($fiscalDays as $day) {
                echo "  - Branch: {$day['branch_id']}, Device: {$day['device_id']}, Day #: {$day['fiscal_day_no']}, Status: {$day['status']}\n";
            }
        }
        
        echo "\n✓ Fiscalization data successfully restored to localhost!\n";
        echo "You can now test fiscalization locally.\n";
        
    } catch (Exception $e) {
        echo "⚠ Import completed but verification failed: " . $e->getMessage() . "\n";
    }
    
} else {
    echo "✗ MySQL command import failed. Trying PHP import...\n";
    echo "Error output:\n";
    foreach ($output as $line) {
        echo "  $line\n";
    }
    
    // Fallback to PHP import
    echo "\nTrying PHP-based import...\n";
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        $sql = file_get_contents($backupFile);
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Split and execute
        $statements = explode(';', $sql);
        $executed = 0;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (empty($stmt) || preg_match('/^--/', $stmt)) continue;
            
            try {
                $pdo->exec($stmt);
                $executed++;
            } catch (PDOException $e) {
                // Ignore some errors (like table already exists)
                if (strpos($e->getMessage(), 'already exists') === false) {
                    echo "  ⚠ " . $e->getMessage() . "\n";
                }
            }
        }
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        echo "✓ Imported $executed statements\n";
        
    } catch (Exception $e) {
        echo "✗ PHP import also failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

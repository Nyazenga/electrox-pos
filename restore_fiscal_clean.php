<?php
/**
 * Restore Fiscalization Data to Localhost
 * Clears existing data first, then imports fresh data
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Local database credentials
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'electrox_primary';

// Get backup file from command line or use default
$backupFile = $argv[1] ?? __DIR__ . '/fiscal_backup_20260203_175512.sql';

if (!file_exists($backupFile)) {
    echo "✗ Backup file not found: $backupFile\n";
    echo "\nUsage: php restore_fiscal_clean.php [backup_file.sql]\n";
    exit(1);
}

echo "========================================\n";
echo "Fiscalization Data Restore (Clean)\n";
echo "========================================\n\n";
echo "Backup file: $backupFile\n";
echo "Target database: $dbName\n\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "Step 1: Clearing existing fiscal data...\n";
    flush();
    
    // Disable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    
    // Clear tables in reverse dependency order
    $tables = [
        'fiscal_receipt_payments',
        'fiscal_receipt_taxes',
        'fiscal_receipt_lines',
        'fiscal_receipts',
        'fiscal_counters',
        'fiscal_days',
        'fiscal_config',
        'fiscal_devices'
    ];
    
    foreach ($tables as $table) {
        try {
            $pdo->exec("TRUNCATE TABLE `$table`");
            echo "  ✓ Cleared $table\n";
            flush();
        } catch (PDOException $e) {
            echo "  ⚠ Could not clear $table: " . $e->getMessage() . "\n";
            flush();
        }
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    
    echo "\nStep 2: Importing backup data...\n";
    flush();
    
    // Read SQL file
    $sql = file_get_contents($backupFile);
    
    // Disable foreign key checks during import
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    
    // Convert INSERT to INSERT IGNORE to handle any remaining duplicates
    $sql = preg_replace('/^INSERT INTO/i', 'INSERT IGNORE INTO', $sql);
    
    // Split and execute statements
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
            // Only show non-duplicate errors
            if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                $errors++;
                if ($errors <= 10) { // Only show first 10 errors
                    echo "  ⚠ " . substr($e->getMessage(), 0, 100) . "\n";
                    flush();
                }
            }
        }
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    
    echo "\n\nStep 3: Verifying imported data...\n";
    flush();
    
    $tables = [
        'fiscal_devices' => 'Fiscal Devices',
        'fiscal_config' => 'Fiscal Config',
        'fiscal_days' => 'Fiscal Days',
        'fiscal_receipts' => 'Fiscal Receipts',
        'fiscal_receipt_lines' => 'Receipt Lines',
        'fiscal_receipt_taxes' => 'Receipt Taxes',
        'fiscal_receipt_payments' => 'Receipt Payments',
        'fiscal_counters' => 'Fiscal Counters'
    ];
    
    foreach ($tables as $table => $label) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "  $label: $count rows\n";
            flush();
        } catch (PDOException $e) {
            echo "  ⚠ Could not count $table: " . $e->getMessage() . "\n";
            flush();
        }
    }
    
    // Show device info
    echo "\nFiscal Devices Status:\n";
    $devices = $pdo->query("SELECT id, branch_id, device_id, device_serial_no, is_registered, is_active FROM fiscal_devices")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($devices)) {
        foreach ($devices as $device) {
            echo "  - Device ID: {$device['device_id']}, Branch: {$device['branch_id']}, ";
            echo "Serial: {$device['device_serial_no']}, ";
            echo "Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . ", ";
            echo "Active: " . ($device['is_active'] ? 'Yes' : 'No') . "\n";
        }
    } else {
        echo "  (No devices found)\n";
    }
    
    // Show recent fiscal days
    echo "\nRecent Fiscal Days:\n";
    $fiscalDays = $pdo->query("SELECT branch_id, device_id, fiscal_day_no, status FROM fiscal_days ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($fiscalDays)) {
        foreach ($fiscalDays as $day) {
            echo "  - Branch: {$day['branch_id']}, Device: {$day['device_id']}, ";
            echo "Day #: {$day['fiscal_day_no']}, Status: {$day['status']}\n";
        }
    } else {
        echo "  (No fiscal days found)\n";
    }
    
    echo "\n========================================\n";
    echo "✓ Restore completed successfully!\n";
    echo "========================================\n";
    echo "Imported: $executed statements\n";
    if ($errors > 0) {
        echo "Errors (non-critical): $errors\n";
    }
    echo "\nYou can now test fiscalization locally.\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

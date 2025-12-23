<?php
/**
 * Setup Fiscal Tables
 * Run this script to create fiscalization tables
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "Setting up fiscalization tables...\n";

try {
    $db = Database::getInstance();
    
    // Read and execute SQL file
    $sqlFile = APP_PATH . '/database/fiscal_schema.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Use primary database for fiscal tables (they're branch-specific, not tenant-specific)
    $primaryDb = Database::getPrimaryInstance();
    
    // Get PDO instance for direct execution
    $pdo = $primaryDb->getPdo();
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        // Skip ALTER TABLE IF NOT EXISTS - handle separately
        if (stripos($statement, 'ALTER TABLE') !== false) {
            continue;
        }
        
        // Skip comments
        if (preg_match('/^\/\*/', $statement) || preg_match('/^\*\//', $statement)) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            echo "✓ Executed statement\n";
        } catch (PDOException $e) {
            // Ignore "table already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Duplicate column') === false &&
                strpos($e->getMessage(), 'Duplicate key') === false) {
                echo "⚠ Warning: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Handle ALTER TABLE separately - check if column exists first
    try {
        $columnExists = $pdo->query("SHOW COLUMNS FROM `branches` LIKE 'fiscalization_enabled'")->fetch();
        if (!$columnExists) {
            $pdo->exec("ALTER TABLE `branches` ADD COLUMN `fiscalization_enabled` tinyint(1) DEFAULT 0 AFTER `status`");
            echo "✓ Added fiscalization_enabled column to branches\n";
        } else {
            echo "✓ fiscalization_enabled column already exists\n";
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            echo "⚠ Warning: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✓ Fiscal tables setup completed!\n";
    
    // Insert default device configurations for Head Office and Hillside
    echo "\nSetting up default device configurations...\n";
    
    // Use primary database for branches
    $primaryDb = Database::getPrimaryInstance();
    
    // Get branch IDs
    $headOffice = $primaryDb->getRow("SELECT id FROM branches WHERE branch_code = 'HO' OR branch_name LIKE '%Head Office%' LIMIT 1");
    $hillside = $primaryDb->getRow("SELECT id FROM branches WHERE branch_code = 'HS' OR branch_name LIKE '%Hillside%' LIMIT 1");
    
    if ($headOffice) {
        $existing = $primaryDb->getRow(
            "SELECT id FROM fiscal_devices WHERE branch_id = :branch_id",
            [':branch_id' => $headOffice['id']]
        );
        
        if (!$existing) {
            $primaryDb->insert('fiscal_devices', [
                'branch_id' => $headOffice['id'],
                'device_id' => 30200,
                'device_serial_no' => 'electrox-2',
                'activation_key' => '00544726',
                'device_model_name' => 'Server',
                'device_model_version' => 'v1',
                'is_active' => 1
            ]);
            echo "✓ Head Office device configured (Device ID: 30200)\n";
        }
    }
    
    if ($hillside) {
        $existing = $primaryDb->getRow(
            "SELECT id FROM fiscal_devices WHERE branch_id = :branch_id",
            [':branch_id' => $hillside['id']]
        );
        
        if (!$existing) {
            $primaryDb->insert('fiscal_devices', [
                'branch_id' => $hillside['id'],
                'device_id' => 30200,
                'device_serial_no' => 'electrox-2',
                'activation_key' => '00294543',
                'device_model_name' => 'Server',
                'device_model_version' => 'v1',
                'is_active' => 1
            ]);
            echo "✓ Hillside device configured (Device ID: 30200)\n";
        }
    }
    
    echo "\n✓ Setup completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}


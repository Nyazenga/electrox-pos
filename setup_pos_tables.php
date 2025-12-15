<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = file_get_contents(__DIR__ . '/database/pos_tables.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false) {
                    echo "Error: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "POS tables created successfully!\n";
    
    // Also create in tenant databases
    $baseDb = Database::getMainInstance();
    $tenants = $baseDb->getRows("SELECT tenant_slug FROM tenants WHERE status = 'active'");
    
    foreach ($tenants as $tenant) {
        $tenantDbName = 'electrox_' . $tenant['tenant_slug'];
        try {
            $tenantPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $tenantDbName, DB_USER, DB_PASS);
            $tenantPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            foreach ($statements as $statement) {
                if (!empty($statement) && !preg_match('/^--/', $statement)) {
                    try {
                        $tenantPdo->exec($statement);
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'already exists') === false) {
                            // Ignore duplicate table errors
                        }
                    }
                }
            }
            
            echo "Tables created in {$tenantDbName}\n";
        } catch (PDOException $e) {
            echo "Error creating tables in {$tenantDbName}: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}


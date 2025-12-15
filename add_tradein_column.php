<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM trade_ins LIKE 'new_product_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE trade_ins ADD COLUMN new_product_id INT(11) DEFAULT NULL AFTER final_valuation");
        echo "Column added successfully to primary database\n";
    } else {
        echo "Column already exists in primary database\n";
    }
    
    // Also add to tenant databases
    $baseDb = Database::getMainInstance();
    $tenants = $baseDb->getRows("SELECT tenant_slug FROM tenants WHERE status = 'active'");
    
    foreach ($tenants as $tenant) {
        $tenantDbName = 'electrox_' . $tenant['tenant_slug'];
        try {
            $tenantPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $tenantDbName, DB_USER, DB_PASS);
            $tenantPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $tenantPdo->query("SHOW COLUMNS FROM trade_ins LIKE 'new_product_id'");
            if ($stmt->rowCount() == 0) {
                $tenantPdo->exec("ALTER TABLE trade_ins ADD COLUMN new_product_id INT(11) DEFAULT NULL AFTER final_valuation");
                echo "Column added to {$tenantDbName}\n";
            } else {
                echo "Column already exists in {$tenantDbName}\n";
            }
        } catch (PDOException $e) {
            echo "Error with {$tenantDbName}: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}


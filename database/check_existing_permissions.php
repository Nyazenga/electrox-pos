<?php
/**
 * Check Existing Permissions in Database
 * This script checks what permissions already exist and reports what's missing
 */

require_once dirname(__DIR__) . '/config.php';

// Connect to primary database
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Checking Existing Permissions in Database ===\n\n";
    
    // Get all existing permissions
    $stmt = $pdo->query("SELECT permission_key, permission_name, module FROM permissions ORDER BY module, permission_key");
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($existing) . " existing permissions:\n\n";
    
    // Group by module
    $byModule = [];
    foreach ($existing as $perm) {
        $module = $perm['module'] ?? 'Other';
        if (!isset($byModule[$module])) {
            $byModule[$module] = [];
        }
        $byModule[$module][] = $perm['permission_key'];
    }
    
    foreach ($byModule as $module => $keys) {
        echo "  $module (" . count($keys) . "):\n";
        foreach ($keys as $key) {
            echo "    - $key\n";
        }
        echo "\n";
    }
    
    // List of all required permissions (from comprehensive_permissions.sql)
    $requiredPermissions = [
        'dashboard.view',
        'products.view', 'products.create', 'products.edit', 'products.delete', 'products.categories',
        'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.delete', 'inventory.view_other_branches',
        'grn.view', 'grn.create', 'grn.edit', 'grn.delete', 'grn.change_status',
        'transfers.view', 'transfers.create', 'transfers.edit', 'transfers.delete', 'transfers.change_status',
        'pos.view', 'pos.create', 'pos.create_sale', 'pos.manage_sales', 'pos.edit', 'pos.delete', 'pos.refund',
        'pos.customize', 'pos.cash_management', 'pos.cash', 'pos.access',
        'drawer.view', 'drawer.transaction', 'drawer.report',
        'receipts.view', 'receipts.print', 'receipts.email', 'receipts.refund', 'receipts.delete',
        'sales.view', 'sales.create', 'sales.edit', 'sales.delete', 'sales.refund',
        'invoicing.view', 'invoicing.create', 'invoicing.edit', 'invoicing.delete', 'invoicing.print',
        'invoicing.change_status', 'invoicing.customize',
        'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.delete', 'invoices.print',
        'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
        'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
        'tradeins.view', 'tradeins.create', 'tradeins.edit', 'tradeins.delete', 'tradeins.process',
        'reports.view', 'reports.sales', 'reports.inventory', 'reports.financial',
        'branches.view', 'branches.create', 'branches.edit', 'branches.delete', 'branches.switch',
        'users.view', 'users.create', 'users.edit', 'users.delete',
        'roles.view', 'roles.create', 'roles.edit', 'roles.delete', 'roles.permissions',
        'currencies.view', 'currencies.create', 'currencies.edit', 'currencies.delete',
        'settings.view', 'settings.edit',
        'fiscalization.view_status', 'fiscalization.view_all', 'fiscalization.verify_taxpayer',
        'fiscalization.register_device', 'fiscalization.sync_config'
    ];
    
    $existingKeys = array_column($existing, 'permission_key');
    $missing = array_diff($requiredPermissions, $existingKeys);
    
    echo "\n=== Missing Permissions ===\n";
    if (empty($missing)) {
        echo "  ✓ All required permissions exist!\n";
    } else {
        echo "  Found " . count($missing) . " missing permissions:\n\n";
        foreach ($missing as $key) {
            echo "    - $key\n";
        }
        echo "\n  Run add_missing_permissions.sql to add these.\n";
    }
    
    // Check for extra permissions (not in our required list)
    $extra = array_diff($existingKeys, $requiredPermissions);
    if (!empty($extra)) {
        echo "\n=== Extra Permissions (not in required list) ===\n";
        foreach ($extra as $key) {
            echo "    - $key\n";
        }
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}


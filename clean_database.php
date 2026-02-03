<?php
/**
 * Clean Database - Remove Business Data, Keep System Essentials
 * 
 * Removes:
 * - Products, product_specific_list
 * - Sales, sale_items, sale_payments
 * - Invoices, invoice_items, invoice_payments
 * - Customers, suppliers
 * - Laybyes, laybye_items, laybye_payments, laybye_payment_schedule
 * - Refunds, cancelled_sales
 * - Trade-ins, stock_transfers, stock_movements
 * - All fiscalization data (fiscal_devices, fiscal_days, fiscal_receipts, etc.)
 * 
 * Keeps:
 * - Users, roles, permissions, role_permissions
 * - Branches
 * - Product categories
 * - Currencies
 * - Settings
 * - POS configurations
 * - Fiscal settings (config only, not data)
 * - Proforma terms
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database credentials
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'electrox_primary';

echo "========================================\n";
echo "Database Cleanup Script\n";
echo "========================================\n\n";
echo "Target database: $dbName\n\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "Step 1: Disabling foreign key checks...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    flush();
    
    // Tables to clear (business data)
    $tablesToClear = [
        // Products
        'product_specific_list',
        'products',
        
        // Sales
        'sale_payments',
        'sale_items',
        'sales',
        
        // Invoices
        'invoice_payments',
        'invoice_items',
        'invoices',
        
        // Customers & Suppliers
        'customers',
        'suppliers',
        
        // Laybyes
        'laybye_payment_schedule',
        'laybye_payments',
        'laybye_items',
        'laybyes',
        
        // Credit Sales
        'credit_sale_payments',
        'credit_sale_items',
        'credit_sales',
        
        // Refunds & Cancellations
        'refunds',
        'refund_items',
        'cancelled_sales',
        'cancelled_sale_items',
        
        // Trade-ins
        'trade_ins',
        'trade_in_items',
        
        // Stock Management
        'stock_transfers',
        'stock_transfer_items',
        'stock_movements',
        'stock_adjustments',
        'grn_items',
        'grns',
        'stock_takes',
        'stock_take_items',
        
        // Fiscalization Data (all data, keep config structure)
        'fiscal_receipt_payments',
        'fiscal_receipt_taxes',
        'fiscal_receipt_lines',
        'fiscal_receipts',
        'fiscal_counters',
        'fiscal_days',
        'fiscal_devices',
        
        // Reports & Analytics
        'sales_reports',
        'inventory_reports',
        
        // Other business data
        'notifications',
        'activity_logs',
    ];
    
    echo "\nStep 2: Clearing business data tables...\n";
    $cleared = 0;
    $errors = 0;
    
    foreach ($tablesToClear as $table) {
        try {
            // Check if table exists
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("TRUNCATE TABLE `$table`");
                echo "  ✓ Cleared $table\n";
                $cleared++;
                flush();
            } else {
                echo "  - Table $table does not exist (skipped)\n";
                flush();
            }
        } catch (PDOException $e) {
            echo "  ⚠ Error clearing $table: " . $e->getMessage() . "\n";
            $errors++;
            flush();
        }
    }
    
    echo "\nStep 3: Resetting auto-increment counters...\n";
    foreach ($tablesToClear as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
            }
        } catch (PDOException $e) {
            // Ignore errors for tables without auto-increment
        }
    }
    
    echo "\nStep 4: Re-enabling foreign key checks...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    
    echo "\nStep 5: Verifying system tables are intact...\n";
    $systemTables = [
        'users' => 'Users',
        'roles' => 'Roles',
        'permissions' => 'Permissions',
        'role_permissions' => 'Role Permissions',
        'branches' => 'Branches',
        'product_categories' => 'Product Categories',
        'currencies' => 'Currencies',
        'settings' => 'Settings',
        'pos_config' => 'POS Config',
        'fiscal_config' => 'Fiscal Config',
        'proforma_terms' => 'Proforma Terms',
    ];
    
    foreach ($systemTables as $table => $label) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                echo "  ✓ $label: $count rows (preserved)\n";
            } else {
                echo "  ⚠ $label: Table not found\n";
            }
            flush();
        } catch (PDOException $e) {
            echo "  ⚠ Error checking $table: " . $e->getMessage() . "\n";
            flush();
        }
    }
    
    echo "\n========================================\n";
    echo "✓ Database cleanup completed!\n";
    echo "========================================\n";
    echo "Cleared: $cleared tables\n";
    if ($errors > 0) {
        echo "Errors: $errors\n";
    }
    echo "\nSystem essentials preserved.\n";
    echo "Database is now clean and ready for backup.\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

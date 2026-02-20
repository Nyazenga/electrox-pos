<?php
/**
 * Clear All Transactional Data Script
 * 
 * This script clears all transactional data from the database while preserving:
 * - System settings (system_settings, pos_settings)
 * - Fiscalization configuration (fiscal_devices, fiscal_config, zimra_certificates)
 * - Master data (branches, currencies, users, roles, permissions)
 * - Configuration (payment_terms, proforma_terms, tenants)
 * 
 * WARNING: This script will permanently delete all sales, invoices, refunds, 
 * credit notes, debit notes, stock movements, products, customers, suppliers,
 * and related transactional data.
 * 
 * Usage:
 *   php scripts/clear_all_transactional_data.php [--confirm] [--tenant=TENANT_NAME]
 * 
 * Options:
 *   --confirm    : Required flag to confirm deletion
 *   --tenant     : Optional tenant name (defaults to all tenants)
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Load configuration
define('APP_PATH', dirname(__DIR__));
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';

// Production mode detection - use production credentials when not on localhost
$isLocalhost = (
    (isset($_SERVER['HTTP_HOST']) && ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0)) ||
    (isset($_SERVER['SERVER_NAME']) && ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1')) ||
    (php_uname('n') === 'localhost' || php_uname('n') === '127.0.0.1')
);

// Parse command line arguments
$confirm = false;
$tenantName = null;
$clearAllTenants = true;

foreach ($argv as $arg) {
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (strpos($arg, '--tenant=') === 0) {
        $tenantName = substr($arg, 9);
        $clearAllTenants = false;
    }
}

if (!$confirm) {
    echo "⚠️  WARNING: This script will permanently delete ALL transactional data!\n";
    echo "This includes:\n";
    echo "  - All sales, invoices, refunds, credit notes, debit notes\n";
    echo "  - All stock movements, transfers, stock takes\n";
    echo "  - All fiscal receipts and fiscal days\n";
    echo "  - All shifts, payments, and activity logs\n";
    echo "  - All laybyes and trade-ins\n";
    echo "  - All products, product categories, and product data\n";
    echo "  - All customers and suppliers\n\n";
    echo "To proceed, run with --confirm flag:\n";
    echo "  php scripts/clear_all_transactional_data.php --confirm\n";
    if ($tenantName) {
        echo "  php scripts/clear_all_transactional_data.php --confirm --tenant=$tenantName\n";
    }
    exit(1);
}

// Function to get all tenant databases
function getAllTenantDatabases($pdo) {
    $tenants = [];
    try {
        // Try to get tenant_slug from tenants table (preferred method)
        $stmt = $pdo->query("SELECT tenant_slug, database_name FROM tenants WHERE status = 'active'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Use tenant_slug if available, otherwise extract from database_name
            $tenantName = $row['tenant_slug'] ?? str_replace('electrox_', '', $row['database_name']);
            if (!in_array($tenantName, $tenants)) {
                $tenants[] = $tenantName;
            }
        }
    } catch (PDOException $e) {
        // Fallback: try to get tenant databases from information_schema
        try {
            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'electrox_%' AND SCHEMA_NAME != 'electrox_primary' AND SCHEMA_NAME != 'electrox_base'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dbName = $row['SCHEMA_NAME'];
                $tenantName = str_replace('electrox_', '', $dbName);
                if (!in_array($tenantName, $tenants)) {
                    $tenants[] = $tenantName;
                }
            }
        } catch (PDOException $e2) {
            echo "⚠️  Warning: Could not fetch tenant databases: " . $e2->getMessage() . "\n";
        }
    }
    return $tenants;
}

// Function to clear transactional data for a specific database
function clearTransactionalData($dbName, $pdo) {
    echo "\n📊 Clearing transactional data from: $dbName\n";
    echo str_repeat("=", 60) . "\n";
    
    $deletedCounts = [];
    $errors = [];
    
    try {
        // Switch to the target database
        $pdo->exec("USE `$dbName`");
        
        // Disable foreign key checks temporarily
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Define tables to clear in order (child tables first)
        $tablesToClear = [
            // Child tables (must be deleted before parent tables)
            'sale_payments',
            'sale_items',
            'refund_payments',
            'refund_items',
            'credit_note_items',
            'debit_note_items',
            'invoice_items',
            'laybye_payments',
            'laybye_payment_schedule',
            'laybye_items',
            'transfer_items',
            'stock_take_items',
            'stock_take_reports',
            'grn_items',
            'fiscal_receipt_lines',
            'fiscal_receipt_payments',
            'fiscal_receipt_taxes',
            
            // Parent tables
            'sales',
            'refunds',
            'credit_notes',
            'debit_notes',
            'invoices',
            'laybyes',
            'stock_transfers',
            'stock_takes',
            'goods_received_notes',
            'fiscal_receipts',
            
            // Other transactional tables
            'shifts',
            'drawer_transactions',
            'payments',
            'account_payments',
            'stock_movements',
            'price_change_history',
            'fiscal_days',
            'fiscal_counters',
            'activity_logs',
            'audit_logs',
            'user_sessions',
            'zimra_operation_logs',
            'zimra_receipt_logs',
            'trade_ins',
            'currency_exchange_rates',
            
            // Product data
            'product_favorites',
            'product_specific_list',
            'products',
            // Note: product_categories is PRESERVED (not deleted)
            
            // Business data
            'customers',
            'suppliers',
        ];
        
        // Clear each table
        foreach ($tablesToClear as $table) {
            try {
                // Check if table exists
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() === 0) {
                    echo "  ⏭️  Table '$table' does not exist, skipping...\n";
                    continue;
                }
                
                // Get count before deletion
                $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM `$table`");
                $count = $countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];
                
                if ($count > 0) {
                    // Delete all records
                    $pdo->exec("DELETE FROM `$table`");
                    $deletedCounts[$table] = $count;
                    echo "  ✅ Deleted $count records from '$table'\n";
                } else {
                    echo "  ℹ️  Table '$table' is already empty\n";
                }
            } catch (PDOException $e) {
                $errorMsg = "Error clearing '$table': " . $e->getMessage();
                $errors[] = $errorMsg;
                echo "  ❌ $errorMsg\n";
            }
        }
        
        // Reset auto-increment counters for cleared tables
        echo "\n🔄 Resetting auto-increment counters...\n";
        $autoIncrementTables = [
            'sales', 'refunds', 'credit_notes', 'debit_notes', 'invoices',
            'laybyes', 'stock_transfers', 'stock_takes', 'goods_received_notes',
            'fiscal_receipts', 'shifts', 'payments', 'fiscal_days',
            'products', 'customers', 'suppliers'
        ];
        
        foreach ($autoIncrementTables as $table) {
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
                    echo "  ✅ Reset auto-increment for '$table'\n";
                }
            } catch (PDOException $e) {
                // Ignore errors for tables that don't have auto-increment
            }
        }
        
        // Re-enable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        // Summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "✅ Completed clearing transactional data from: $dbName\n";
        
        $totalDeleted = array_sum($deletedCounts);
        if ($totalDeleted > 0) {
            echo "📊 Total records deleted: $totalDeleted\n";
            echo "📋 Tables cleared: " . count($deletedCounts) . "\n";
        } else {
            echo "ℹ️  No transactional data found to clear.\n";
        }
        
        if (!empty($errors)) {
            echo "⚠️  Errors encountered: " . count($errors) . "\n";
            foreach ($errors as $error) {
                echo "   - $error\n";
            }
        }
        
        return ['success' => true, 'deleted' => $totalDeleted, 'errors' => count($errors)];
        
    } catch (PDOException $e) {
        echo "❌ Fatal error clearing $dbName: " . $e->getMessage() . "\n";
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); // Re-enable in case of error
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Main execution
echo "🗑️  CLEAR ALL TRANSACTIONAL DATA SCRIPT\n";
echo str_repeat("=", 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Use appropriate credentials based on environment
    // For localhost: use root with no password
    // For production: use DB_USER and DB_PASS from config
    $dbHost = DB_HOST;
    $dbUser = $isLocalhost ? 'root' : DB_USER;
    $dbPass = $isLocalhost ? '' : DB_PASS;
    
    echo "🔌 Database Connection:\n";
    echo "   Host: $dbHost\n";
    echo "   Database: " . PRIMARY_DB_NAME . "\n";
    echo "   User: $dbUser\n";
    echo "   Environment: " . ($isLocalhost ? 'LOCAL' : 'PRODUCTION') . "\n\n";
    
    // Connect to primary database
    $primaryPdo = new PDO(
        "mysql:host=$dbHost;dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET,
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // Clear primary database
    echo "📍 Processing PRIMARY database: " . PRIMARY_DB_NAME . "\n";
    $primaryResult = clearTransactionalData(PRIMARY_DB_NAME, $primaryPdo);
    
    // Clear tenant databases if requested
    if ($clearAllTenants) {
        $tenants = getAllTenantDatabases($primaryPdo);
        if (!empty($tenants)) {
            echo "\n📍 Processing TENANT databases:\n";
            foreach ($tenants as $tenant) {
                $tenantDbName = 'electrox_' . $tenant;
                try {
                    // Check if database exists
                    $stmt = $primaryPdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$tenantDbName'");
                    if ($stmt->rowCount() > 0) {
                        clearTransactionalData($tenantDbName, $primaryPdo);
                    } else {
                        echo "  ⏭️  Database '$tenantDbName' does not exist, skipping...\n";
                    }
                } catch (PDOException $e) {
                    echo "  ❌ Error processing '$tenantDbName': " . $e->getMessage() . "\n";
                }
            }
        }
    } elseif ($tenantName) {
        // Clear specific tenant
        $tenantDbName = 'electrox_' . $tenantName;
        echo "\n📍 Processing TENANT database: $tenantDbName\n";
        try {
            $stmt = $primaryPdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$tenantDbName'");
            if ($stmt->rowCount() > 0) {
                clearTransactionalData($tenantDbName, $primaryPdo);
            } else {
                echo "  ❌ Database '$tenantDbName' does not exist!\n";
                exit(1);
            }
        } catch (PDOException $e) {
            echo "  ❌ Error processing '$tenantDbName': " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    // Final summary
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✅ SCRIPT COMPLETED SUCCESSFULLY\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "\n📋 PRESERVED DATA:\n";
    echo "  ✅ System settings (system_settings, pos_settings)\n";
    echo "  ✅ Fiscalization configuration (fiscal_devices, fiscal_config, zimra_certificates)\n";
    echo "  ✅ Master data (branches, currencies, users, roles, permissions)\n";
    echo "  ✅ Product categories (product_categories)\n";
    echo "  ✅ Configuration (payment_terms, proforma_terms, tenants)\n";
    echo "\n";
    
} catch (PDOException $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

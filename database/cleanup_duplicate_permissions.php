<?php
/**
 * Cleanup Duplicate Permissions Script
 * Removes old/duplicate permissions and keeps only the correct ones
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

// Connect directly to electrox_primary database (NOT electrox_base)
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Simple database wrapper
    class SimpleDB {
        private $pdo;
        public function __construct($pdo) { $this->pdo = $pdo; }
        public function getRow($sql, $params = []) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        public function getRows($sql, $params = []) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        public function executeQuery($sql, $params = []) {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }
    
    $db = new SimpleDB($pdo);
} catch (Exception $e) {
    die("Failed to connect to " . PRIMARY_DB_NAME . ": " . $e->getMessage() . "\n");
}

echo "Cleaning up duplicate permissions...\n\n";

// List of permissions to keep (the correct ones)
$keepPermissions = [
    // Dashboard
    'dashboard.view',
    
    // Products
    'products.view', 'products.create', 'products.edit', 'products.delete', 'products.categories',
    
    // Inventory
    'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.delete', 'inventory.view_other_branches',
    
    // GRN
    'grn.view', 'grn.create', 'grn.edit', 'grn.delete', 'grn.change_status',
    
    // Transfers
    'transfers.view', 'transfers.create', 'transfers.edit', 'transfers.delete', 'transfers.change_status',
    
    // Trade-Ins
    'tradeins.view', 'tradeins.create', 'tradeins.edit', 'tradeins.delete', 'tradeins.process',
    
    // POS
    'pos.view', 'pos.create_sale', 'pos.edit_sale', 'pos.delete_sale', 'pos.manage_sales', 
    'pos.cash_management', 'pos.customize',
    
    // Receipts
    'receipts.view', 'receipts.delete', 'receipts.refund',
    
    // Sales
    'sales.view', 'sales.create', 'sales.edit', 'sales.delete',
    
    // Invoicing (use invoicing.* not invoices.*)
    'invoicing.view', 'invoicing.create', 'invoicing.edit', 'invoicing.delete', 
    'invoicing.change_status', 'invoicing.customize',
    
    // Customers
    'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
    
    // Suppliers
    'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
    
    // Reports
    'reports.view', 'reports.sales', 'reports.inventory', 'reports.financial',
    
    // Drawer
    'drawer.view', 'drawer.transaction', 'drawer.report',
    
    // Branches
    'branches.view', 'branches.create', 'branches.edit', 'branches.delete', 'branches.switch',
    
    // Users
    'users.view', 'users.create', 'users.edit', 'users.delete',
    
    // Roles
    'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
    
    // Settings
    'settings.view', 'settings.edit',
    
    // Currencies
    'currencies.view', 'currencies.create', 'currencies.edit', 'currencies.delete',
    
    // Fiscalization
    'fiscalization.view', 'fiscalization.manage', 'fiscalization.verify_taxpayer', 
    'fiscalization.register_device', 'fiscalization.sync_config', 'fiscalization.view_status', 
    'fiscalization.view_all',
];

// Get all permissions
$allPermissions = $db->getRows("SELECT id, permission_key FROM permissions");
if ($allPermissions === false) $allPermissions = [];

$toDelete = [];
$toKeep = [];

foreach ($allPermissions as $perm) {
    if (in_array($perm['permission_key'], $keepPermissions)) {
        $toKeep[] = $perm['id'];
    } else {
        $toDelete[] = $perm;
    }
}

echo "Permissions to keep: " . count($toKeep) . "\n";
echo "Permissions to delete: " . count($toDelete) . "\n\n";

if (count($toDelete) > 0) {
    echo "Deleting duplicate/old permissions:\n";
    foreach ($toDelete as $perm) {
        echo "  - {$perm['permission_key']} (ID: {$perm['id']})\n";
        
        // First remove from role_permissions
        $db->executeQuery("DELETE FROM role_permissions WHERE permission_id = :id", [':id' => $perm['id']]);
        
        // Then delete the permission
        $db->executeQuery("DELETE FROM permissions WHERE id = :id", [':id' => $perm['id']]);
    }
    echo "\n";
}

echo "Cleanup complete!\n";
echo "Total permissions remaining: " . count($toKeep) . "\n";


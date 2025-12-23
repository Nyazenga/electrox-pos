<?php
/**
 * Comprehensive Permissions Seeding Script
 * This script seeds all permissions required for the ELECTROX POS system
 */

require_once dirname(__DIR__) . '/config.php';

// Connect directly to electrox_primary database (NOT electrox_base)
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create a simple database wrapper for this script
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
        public function getCount($sql, $params = []) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result ? (int)array_values($result)[0] : 0;
        }
        public function insert($table, $data) {
            $fields = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
            $stmt = $this->pdo->prepare($sql);
            foreach ($data as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            return $stmt->execute();
        }
        public function update($table, $data, $where) {
            $set = [];
            foreach ($data as $key => $value) {
                $set[] = "$key = :set_$key";
            }
            $whereClause = [];
            foreach ($where as $key => $value) {
                $whereClause[] = "$key = :where_$key";
            }
            $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $whereClause);
            $stmt = $this->pdo->prepare($sql);
            foreach ($data as $key => $value) {
                $stmt->bindValue(":set_$key", $value);
            }
            foreach ($where as $key => $value) {
                $stmt->bindValue(":where_$key", $value);
            }
            return $stmt->execute();
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

// Define all permissions organized by module
$permissions = [
    // Dashboard
    ['permission_key' => 'dashboard.view', 'permission_name' => 'View Dashboard', 'module' => 'Dashboard', 'description' => 'Access to view the main dashboard'],
    
    // Products Module
    ['permission_key' => 'products.view', 'permission_name' => 'View Products', 'module' => 'Products', 'description' => 'View product list'],
    ['permission_key' => 'products.create', 'permission_name' => 'Create Products', 'module' => 'Products', 'description' => 'Create new products'],
    ['permission_key' => 'products.edit', 'permission_name' => 'Edit Products', 'module' => 'Products', 'description' => 'Edit existing products'],
    ['permission_key' => 'products.delete', 'permission_name' => 'Delete Products', 'module' => 'Products', 'description' => 'Delete products'],
    ['permission_key' => 'products.categories', 'permission_name' => 'Manage Categories', 'module' => 'Products', 'description' => 'Manage product categories'],
    
    // Inventory Module
    ['permission_key' => 'inventory.view', 'permission_name' => 'View Inventory', 'module' => 'Inventory', 'description' => 'View stock levels'],
    ['permission_key' => 'inventory.create', 'permission_name' => 'Create Inventory', 'module' => 'Inventory', 'description' => 'Create GRN and transfers'],
    ['permission_key' => 'inventory.edit', 'permission_name' => 'Edit Inventory', 'module' => 'Inventory', 'description' => 'Edit inventory records'],
    ['permission_key' => 'inventory.delete', 'permission_name' => 'Delete Inventory', 'module' => 'Inventory', 'description' => 'Delete inventory records'],
    ['permission_key' => 'inventory.view_other_branches', 'permission_name' => 'View Stock in Other Branches', 'module' => 'Inventory', 'description' => 'View product stock available in other branches'],
    
    // GRN (Goods Received Note)
    ['permission_key' => 'grn.view', 'permission_name' => 'View GRN', 'module' => 'GRN', 'description' => 'View goods received notes'],
    ['permission_key' => 'grn.create', 'permission_name' => 'Create GRN', 'module' => 'GRN', 'description' => 'Create new goods received notes'],
    ['permission_key' => 'grn.edit', 'permission_name' => 'Edit GRN', 'module' => 'GRN', 'description' => 'Edit goods received notes'],
    ['permission_key' => 'grn.delete', 'permission_name' => 'Delete GRN', 'module' => 'GRN', 'description' => 'Delete goods received notes'],
    ['permission_key' => 'grn.change_status', 'permission_name' => 'Change GRN Status', 'module' => 'GRN', 'description' => 'Change status of goods received notes'],
    
    // Transfers
    ['permission_key' => 'transfers.view', 'permission_name' => 'View Transfers', 'module' => 'Transfers', 'description' => 'View stock transfers'],
    ['permission_key' => 'transfers.create', 'permission_name' => 'Create Transfers', 'module' => 'Transfers', 'description' => 'Create new stock transfers'],
    ['permission_key' => 'transfers.edit', 'permission_name' => 'Edit Transfers', 'module' => 'Transfers', 'description' => 'Edit stock transfers'],
    ['permission_key' => 'transfers.delete', 'permission_name' => 'Delete Transfers', 'module' => 'Transfers', 'description' => 'Delete stock transfers'],
    ['permission_key' => 'transfers.change_status', 'permission_name' => 'Change Transfer Status', 'module' => 'Transfers', 'description' => 'Change status of stock transfers'],
    
    // Trade-Ins
    ['permission_key' => 'tradeins.view', 'permission_name' => 'View Trade-Ins', 'module' => 'Trade-Ins', 'description' => 'View trade-in transactions'],
    ['permission_key' => 'tradeins.create', 'permission_name' => 'Create Trade-Ins', 'module' => 'Trade-Ins', 'description' => 'Create new trade-in transactions'],
    ['permission_key' => 'tradeins.edit', 'permission_name' => 'Edit Trade-Ins', 'module' => 'Trade-Ins', 'description' => 'Edit trade-in transactions'],
    ['permission_key' => 'tradeins.delete', 'permission_name' => 'Delete Trade-Ins', 'module' => 'Trade-Ins', 'description' => 'Delete trade-in transactions'],
    
    // POS Module
    ['permission_key' => 'pos.view', 'permission_name' => 'View POS', 'module' => 'POS', 'description' => 'Access POS system'],
    ['permission_key' => 'pos.create_sale', 'permission_name' => 'Create Sale', 'module' => 'POS', 'description' => 'Create new sales transactions'],
    ['permission_key' => 'pos.edit_sale', 'permission_name' => 'Edit Sale', 'module' => 'POS', 'description' => 'Edit sales transactions'],
    ['permission_key' => 'pos.delete_sale', 'permission_name' => 'Delete Sale', 'module' => 'POS', 'description' => 'Delete sales transactions'],
    ['permission_key' => 'pos.manage_sales', 'permission_name' => 'Manage Sales', 'module' => 'POS', 'description' => 'Manage existing sales'],
    ['permission_key' => 'pos.cash_management', 'permission_name' => 'Cash Management', 'module' => 'POS', 'description' => 'Access cash management'],
    ['permission_key' => 'pos.customize', 'permission_name' => 'POS Customization', 'module' => 'POS', 'description' => 'Customize POS settings'],
    
    // Receipts
    ['permission_key' => 'receipts.view', 'permission_name' => 'View Receipts', 'module' => 'Receipts', 'description' => 'View sales receipts'],
    ['permission_key' => 'receipts.delete', 'permission_name' => 'Delete Receipts', 'module' => 'Receipts', 'description' => 'Delete sales receipts'],
    ['permission_key' => 'receipts.refund', 'permission_name' => 'Process Refunds', 'module' => 'Receipts', 'description' => 'Process refunds for receipts'],
    
    // Sales Module
    ['permission_key' => 'sales.view', 'permission_name' => 'View Sales', 'module' => 'Sales', 'description' => 'View sales records'],
    ['permission_key' => 'sales.create', 'permission_name' => 'Create Sales', 'module' => 'Sales', 'description' => 'Create new sales'],
    ['permission_key' => 'sales.edit', 'permission_name' => 'Edit Sales', 'module' => 'Sales', 'description' => 'Edit sales records'],
    ['permission_key' => 'sales.delete', 'permission_name' => 'Delete Sales', 'module' => 'Sales', 'description' => 'Delete sales records'],
    
    // Customers Module
    ['permission_key' => 'customers.view', 'permission_name' => 'View Customers', 'module' => 'Customers', 'description' => 'View customer list'],
    ['permission_key' => 'customers.create', 'permission_name' => 'Create Customers', 'module' => 'Customers', 'description' => 'Create new customers'],
    ['permission_key' => 'customers.edit', 'permission_name' => 'Edit Customers', 'module' => 'Customers', 'description' => 'Edit customer information'],
    ['permission_key' => 'customers.delete', 'permission_name' => 'Delete Customers', 'module' => 'Customers', 'description' => 'Delete customers'],
    
    // Suppliers Module
    ['permission_key' => 'suppliers.view', 'permission_name' => 'View Suppliers', 'module' => 'Suppliers', 'description' => 'View supplier list'],
    ['permission_key' => 'suppliers.create', 'permission_name' => 'Create Suppliers', 'module' => 'Suppliers', 'description' => 'Create new suppliers'],
    ['permission_key' => 'suppliers.edit', 'permission_name' => 'Edit Suppliers', 'module' => 'Suppliers', 'description' => 'Edit supplier information'],
    ['permission_key' => 'suppliers.delete', 'permission_name' => 'Delete Suppliers', 'module' => 'Suppliers', 'description' => 'Delete suppliers'],
    
    // Reports Module
    ['permission_key' => 'reports.view', 'permission_name' => 'View Reports', 'module' => 'Reports', 'description' => 'Access reports module'],
    ['permission_key' => 'reports.sales', 'permission_name' => 'Sales Reports', 'module' => 'Reports', 'description' => 'View sales reports'],
    ['permission_key' => 'reports.inventory', 'permission_name' => 'Inventory Reports', 'module' => 'Reports', 'description' => 'View inventory reports'],
    ['permission_key' => 'reports.financial', 'permission_name' => 'Financial Reports', 'module' => 'Reports', 'description' => 'View financial reports'],
    
    // Drawer Management
    ['permission_key' => 'drawer.view', 'permission_name' => 'View Drawer', 'module' => 'Drawer', 'description' => 'View drawer information'],
    ['permission_key' => 'drawer.transaction', 'permission_name' => 'Drawer Transactions', 'module' => 'Drawer', 'description' => 'Perform drawer transactions (cash in/out)'],
    ['permission_key' => 'drawer.report', 'permission_name' => 'View Drawer Report', 'module' => 'Drawer', 'description' => 'View drawer reports'],
    
    // Branch Management
    ['permission_key' => 'branches.view', 'permission_name' => 'View Branches', 'module' => 'Branches', 'description' => 'View branch list'],
    ['permission_key' => 'branches.create', 'permission_name' => 'Create Branches', 'module' => 'Branches', 'description' => 'Create new branches'],
    ['permission_key' => 'branches.edit', 'permission_name' => 'Edit Branches', 'module' => 'Branches', 'description' => 'Edit branch information'],
    ['permission_key' => 'branches.delete', 'permission_name' => 'Delete Branches', 'module' => 'Branches', 'description' => 'Delete branches'],
    ['permission_key' => 'branches.switch', 'permission_name' => 'Switch Between Branches', 'module' => 'Branches', 'description' => 'Change between branches in system (top bar and filters)'],
    
    // Users Module
    ['permission_key' => 'users.view', 'permission_name' => 'View Users', 'module' => 'Users', 'description' => 'View user list'],
    ['permission_key' => 'users.create', 'permission_name' => 'Create Users', 'module' => 'Users', 'description' => 'Create new users'],
    ['permission_key' => 'users.edit', 'permission_name' => 'Edit Users', 'module' => 'Users', 'description' => 'Edit user information'],
    ['permission_key' => 'users.delete', 'permission_name' => 'Delete Users', 'module' => 'Users', 'description' => 'Delete users'],
    
    // Roles & Permissions Module
    ['permission_key' => 'roles.view', 'permission_name' => 'View Roles', 'module' => 'Roles', 'description' => 'View roles and permissions'],
    ['permission_key' => 'roles.create', 'permission_name' => 'Create Roles', 'module' => 'Roles', 'description' => 'Create new roles'],
    ['permission_key' => 'roles.edit', 'permission_name' => 'Edit Roles', 'module' => 'Roles', 'description' => 'Edit roles and permissions'],
    ['permission_key' => 'roles.delete', 'permission_name' => 'Delete Roles', 'module' => 'Roles', 'description' => 'Delete roles'],
    
    // Settings Module
    ['permission_key' => 'settings.view', 'permission_name' => 'View Settings', 'module' => 'Settings', 'description' => 'Access settings'],
    ['permission_key' => 'settings.edit', 'permission_name' => 'Edit Settings', 'module' => 'Settings', 'description' => 'Edit system settings'],
    
    // Currencies Module
    ['permission_key' => 'currencies.view', 'permission_name' => 'View Currencies', 'module' => 'Currencies', 'description' => 'View currency list'],
    ['permission_key' => 'currencies.create', 'permission_name' => 'Create Currencies', 'module' => 'Currencies', 'description' => 'Create new currencies'],
    ['permission_key' => 'currencies.edit', 'permission_name' => 'Edit Currencies', 'module' => 'Currencies', 'description' => 'Edit currency information'],
    ['permission_key' => 'currencies.delete', 'permission_name' => 'Delete Currencies', 'module' => 'Currencies', 'description' => 'Delete currencies'],
    
    // Fiscalization / ZIMRA
    ['permission_key' => 'fiscalization.view', 'permission_name' => 'View Fiscalization', 'module' => 'Fiscalization', 'description' => 'View fiscalization settings'],
    ['permission_key' => 'fiscalization.manage', 'permission_name' => 'Fiscal Day Management', 'module' => 'Fiscalization', 'description' => 'Open and close fiscal days'],
    ['permission_key' => 'fiscalization.verify_taxpayer', 'permission_name' => 'Verify Taxpayer Information', 'module' => 'Fiscalization', 'description' => 'Verify taxpayer information with ZIMRA'],
    ['permission_key' => 'fiscalization.register_device', 'permission_name' => 'Register Device with ZIMRA', 'module' => 'Fiscalization', 'description' => 'Register fiscal device with ZIMRA'],
    ['permission_key' => 'fiscalization.sync_config', 'permission_name' => 'Sync Configuration', 'module' => 'Fiscalization', 'description' => 'Sync device configuration with ZIMRA'],
    ['permission_key' => 'fiscalization.view_status', 'permission_name' => 'View Branch Device Status', 'module' => 'Fiscalization', 'description' => 'View fiscal device status for branches'],
    ['permission_key' => 'fiscalization.view_all', 'permission_name' => 'View All Fiscalizations', 'module' => 'Fiscalization', 'description' => 'View all fiscalization records'],
    
    // Invoicing Module
    ['permission_key' => 'invoicing.view', 'permission_name' => 'View Invoices', 'module' => 'Invoicing', 'description' => 'View invoice list'],
    ['permission_key' => 'invoicing.create', 'permission_name' => 'Create Invoices', 'module' => 'Invoicing', 'description' => 'Create new invoices (proforma, tax, quote, credit note)'],
    ['permission_key' => 'invoicing.edit', 'permission_name' => 'Edit Invoices', 'module' => 'Invoicing', 'description' => 'Edit existing invoices'],
    ['permission_key' => 'invoicing.delete', 'permission_name' => 'Delete Invoices', 'module' => 'Invoicing', 'description' => 'Delete invoices'],
    ['permission_key' => 'invoicing.change_status', 'permission_name' => 'Change Invoice Status', 'module' => 'Invoicing', 'description' => 'Change status of invoices'],
    ['permission_key' => 'invoicing.customize', 'permission_name' => 'Customize Invoices', 'module' => 'Invoicing', 'description' => 'Customize invoice templates'],
];

echo "Starting permissions seeding...\n";

$inserted = 0;
$skipped = 0;

foreach ($permissions as $permission) {
    // Check if permission already exists
    $existing = $db->getRow(
        "SELECT id FROM permissions WHERE permission_key = :key",
        [':key' => $permission['permission_key']]
    );
    
    if ($existing) {
        // Update existing permission
        $db->update('permissions', [
            'permission_name' => $permission['permission_name'],
            'module' => $permission['module'],
            'description' => $permission['description']
        ], ['id' => $existing['id']]);
        echo "Updated: {$permission['permission_key']}\n";
        $skipped++;
    } else {
        // Insert new permission
        $db->insert('permissions', $permission);
        echo "Inserted: {$permission['permission_key']}\n";
        $inserted++;
    }
}

echo "\nSeeding complete!\n";
echo "Inserted: $inserted\n";
echo "Updated: $skipped\n";

// Now ensure System Administrator role has all permissions
echo "\nAssigning all permissions to System Administrator role...\n";

$adminRole = $db->getRow("SELECT id FROM roles WHERE name = 'System Administrator' OR name = 'Administrator' LIMIT 1");

if ($adminRole) {
    $allPermissions = $db->getRows("SELECT id FROM permissions");
    if ($allPermissions !== false) {
        foreach ($allPermissions as $perm) {
            // Check if already assigned
            $existing = $db->getRow(
                "SELECT id FROM role_permissions WHERE role_id = :role_id AND permission_id = :perm_id",
                [
                    ':role_id' => $adminRole['id'],
                    ':perm_id' => $perm['id']
                ]
            );
            
            if (!$existing) {
                $db->insert('role_permissions', [
                    'role_id' => $adminRole['id'],
                    'permission_id' => $perm['id']
                ]);
            }
        }
        echo "All permissions assigned to System Administrator role.\n";
    }
} else {
    echo "Warning: System Administrator role not found. Please create it manually.\n";
}

echo "\nDone!\n";


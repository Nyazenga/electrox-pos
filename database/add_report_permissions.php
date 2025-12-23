<?php
/**
 * Add Additional Report Permissions
 * Adds granular permissions for all the different report types
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
    }
    
    $db = new SimpleDB($pdo);
} catch (Exception $e) {
    die("Failed to connect to " . PRIMARY_DB_NAME . ": " . $e->getMessage() . "\n");
}

// Additional report permissions
$reportPermissions = [
    // Sales Reports
    ['permission_key' => 'reports.sales_summary', 'permission_name' => 'Sales Summary Report', 'module' => 'Reports', 'description' => 'View sales summary report'],
    ['permission_key' => 'reports.sales_by_product', 'permission_name' => 'Sales by Product Report', 'module' => 'Reports', 'description' => 'View sales by product report'],
    ['permission_key' => 'reports.sales_by_category', 'permission_name' => 'Sales by Category Report', 'module' => 'Reports', 'description' => 'View sales by category report'],
    ['permission_key' => 'reports.sales_by_staff', 'permission_name' => 'Sales by Staff Report', 'module' => 'Reports', 'description' => 'View sales by staff report'],
    ['permission_key' => 'reports.sales_by_payment', 'permission_name' => 'Sales by Payment Type Report', 'module' => 'Reports', 'description' => 'View sales by payment type report'],
    ['permission_key' => 'reports.sales_by_trend', 'permission_name' => 'Sales Trend Report', 'module' => 'Reports', 'description' => 'View sales trend report'],
    ['permission_key' => 'reports.sales_by_order', 'permission_name' => 'Sales by Order Report', 'module' => 'Reports', 'description' => 'View sales by order report'],
    ['permission_key' => 'reports.sales_by_discount', 'permission_name' => 'Sales by Discount Report', 'module' => 'Reports', 'description' => 'View sales by discount report'],
    ['permission_key' => 'reports.sales_by_modifier', 'permission_name' => 'Sales by Modifier Report', 'module' => 'Reports', 'description' => 'View sales by modifier report'],
    
    // Product Reports
    ['permission_key' => 'reports.product_wise_receipt', 'permission_name' => 'Product Wise Receipt Report', 'module' => 'Reports', 'description' => 'View product wise receipt report'],
    ['permission_key' => 'reports.product_wise_tax', 'permission_name' => 'Product Wise Tax Report', 'module' => 'Reports', 'description' => 'View product wise tax report'],
    ['permission_key' => 'reports.product_wise_order', 'permission_name' => 'Product Wise Order Report', 'module' => 'Reports', 'description' => 'View product wise order report'],
    ['permission_key' => 'reports.product_wise_deleted', 'permission_name' => 'Product Wise Deleted Receipts Report', 'module' => 'Reports', 'description' => 'View product wise deleted receipts report'],
    ['permission_key' => 'reports.product_sales_by_staff', 'permission_name' => 'Product Sales by Staff Report', 'module' => 'Reports', 'description' => 'View product sales by staff report'],
    ['permission_key' => 'reports.products_consumed_by_staff', 'permission_name' => 'Products Consumed by Staff Report', 'module' => 'Reports', 'description' => 'View products consumed by staff report'],
    
    // Receipt Reports
    ['permission_key' => 'reports.receipts', 'permission_name' => 'Receipts Report', 'module' => 'Reports', 'description' => 'View receipts report'],
    ['permission_key' => 'reports.deleted_receipts', 'permission_name' => 'Deleted Receipts Report', 'module' => 'Reports', 'description' => 'View deleted receipts report'],
    ['permission_key' => 'reports.manual_receipts', 'permission_name' => 'Manual Receipts Report', 'module' => 'Reports', 'description' => 'View manual receipts report'],
    
    // Refund Reports
    ['permission_key' => 'reports.refunds', 'permission_name' => 'Refunds Report', 'module' => 'Reports', 'description' => 'View refunds report'],
    ['permission_key' => 'reports.refunds_credit_notes', 'permission_name' => 'Refunds and Credit Notes Report', 'module' => 'Reports', 'description' => 'View refunds and credit notes report'],
    
    // Other Reports
    ['permission_key' => 'reports.taxes', 'permission_name' => 'Taxes Report', 'module' => 'Reports', 'description' => 'View taxes report'],
    ['permission_key' => 'reports.shifts', 'permission_name' => 'Shifts Report', 'module' => 'Reports', 'description' => 'View shifts report'],
    ['permission_key' => 'reports.customers', 'permission_name' => 'Customers Report', 'module' => 'Reports', 'description' => 'View customers report'],
    ['permission_key' => 'reports.currency', 'permission_name' => 'Currency Report', 'module' => 'Reports', 'description' => 'View currency report'],
    ['permission_key' => 'reports.ecommerce_sales', 'permission_name' => 'E-commerce Sales Report', 'module' => 'Reports', 'description' => 'View e-commerce sales report'],
    ['permission_key' => 'reports.order_type_wise', 'permission_name' => 'Order Type Wise Sales Report', 'module' => 'Reports', 'description' => 'View order type wise sales report'],
    ['permission_key' => 'reports.deleted_products_open_orders', 'permission_name' => 'Deleted Products Open Orders Report', 'module' => 'Reports', 'description' => 'View deleted products open orders report'],
];

echo "Adding report permissions...\n\n";

$inserted = 0;
$skipped = 0;

foreach ($reportPermissions as $permission) {
    // Check if permission already exists
    $existing = $db->getRow(
        "SELECT id FROM permissions WHERE permission_key = :key",
        [':key' => $permission['permission_key']]
    );
    
    if ($existing) {
        echo "Skipped (exists): {$permission['permission_key']}\n";
        $skipped++;
    } else {
        $db->insert('permissions', $permission);
        echo "Inserted: {$permission['permission_key']}\n";
        $inserted++;
    }
}

echo "\nDone!\n";
echo "Inserted: $inserted\n";
echo "Skipped: $skipped\n";

// Assign all new permissions to System Administrator
echo "\nAssigning new permissions to System Administrator...\n";
$adminRole = $db->getRow("SELECT id FROM roles WHERE name = 'System Administrator' OR name = 'Administrator' LIMIT 1");

if ($adminRole) {
    foreach ($reportPermissions as $permission) {
        $perm = $db->getRow("SELECT id FROM permissions WHERE permission_key = :key", [':key' => $permission['permission_key']]);
        if ($perm) {
            $existing = $db->getRow(
                "SELECT id FROM role_permissions WHERE role_id = :role_id AND permission_id = :perm_id",
                [':role_id' => $adminRole['id'], ':perm_id' => $perm['id']]
            );
            
            if (!$existing) {
                $db->insert('role_permissions', [
                    'role_id' => $adminRole['id'],
                    'permission_id' => $perm['id']
                ]);
            }
        }
    }
    echo "All new permissions assigned to System Administrator.\n";
} else {
    echo "Warning: System Administrator role not found.\n";
}


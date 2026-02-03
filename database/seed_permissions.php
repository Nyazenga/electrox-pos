<?php
/**
 * Seed Permissions for All New Features
 * Run this script to add all new permissions to the database
 */

require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

echo "Seeding permissions for new features...\n\n";

// Define all new permissions
$permissions = [
    // Refund/Credit Note permissions
    ['sales.refund', 'Process refunds and generate credit notes'],
    ['receipts.refund', 'Refund receipts'],
    ['reports.refunds', 'View refund reports'],
    ['sales.view_credit_note', 'View credit notes'],
    ['sales.print_credit_note', 'Print credit notes'],
    
    // Credit Sales permissions
    ['sales.credit_sales', 'View and manage credit sales'],
    ['sales.settle_account', 'Settle credit sale accounts'],
    
    // Laybyes permissions
    ['sales.laybyes', 'View and manage laybyes'],
    ['sales.laybyes.create', 'Create new laybyes'],
    ['sales.laybyes.edit', 'Edit laybyes'],
    ['sales.laybyes.complete', 'Complete laybyes'],
    ['sales.laybyes.cancel', 'Cancel laybyes'],
    ['sales.laybyes.add_payment', 'Add payments to laybyes'],
    ['reports.laybyes', 'View laybye reports'],
    
    // Wholesale/Dealer Sales permissions
    ['sales.wholesale', 'Process wholesale/dealer sales'],
    ['reports.wholesale', 'View wholesale sales reports'],
    
    // Price Change Tracking
    ['reports.price_change_history', 'View price change history'],
    
    // VAT Returns
    ['reports.vat_returns', 'View VAT returns report'],
    
    // Stock Take
    ['products.stock_take', 'Perform stock take'],
    ['products.stock_take_reports', 'View stock take reports'],
    
    // Barcode Management
    ['products.barcodes', 'Manage product barcodes'],
    ['products.generate_barcodes', 'Generate product barcodes'],
    ['products.print_barcodes', 'Print product barcodes'],
    
    // Function Keys
    // Payment Terms
    ['settings.payment_terms', 'Manage payment terms'],
    
    // Other Reports permissions (25+ reports)
    ['reports.discounts_period', 'View discounts given for period'],
    ['reports.product_discounts_period', 'View product discounts for period'],
    ['reports.customer_purchases_period', 'View customer purchases for period'],
    ['reports.current_customers_branch', 'View current customers list for branch'],
    ['reports.customers_by_sales_branch', 'View customers list by sales for branch'],
    ['reports.highest_movers_qty', 'View highest movers by quantity for branch'],
    ['reports.highest_movers_gross_profit', 'View highest movers by gross profit contribution'],
    ['reports.highest_movers_sales_contribution', 'View highest movers by sales contribution'],
    ['reports.highest_movers_sales_supplier', 'View highest movers by sales for supplier'],
    ['reports.stock_balances_branch', 'View stock balances for branch'],
    ['reports.stock_balances_branch_category', 'View stock balances for branch by category'],
    ['reports.stock_balances_company', 'View stock balances for company'],
    ['reports.stock_adjustments_period', 'View stock adjustments for period for branch'],
    ['reports.view_stock_purchases', 'View stock purchases'],
    ['reports.view_stock_purchases_product', 'View stock purchases for product'],
    ['reports.purchases_by_supplier', 'View purchases by supplier'],
    ['reports.individual_product_movement', 'View individual product movement for company'],
    ['reports.view_stock_transfers', 'View stock transfers'],
    ['reports.view_stock_transfers_period', 'View stock transfers for period'],
    ['reports.stock_expiry_report', 'View stock expiry report'],
    ['reports.consignment_stock_report', 'View consignment stock report'],
    ['reports.price_list', 'View price list'],
    ['reports.fifo_stock_ageing', 'View FIFO stock ageing report'],
    ['reports.products_converted', 'View products converted report'],
    ['reports.performance_report', 'View performance report'],
    
    // Sales Reports permissions (11 additional reports)
    ['reports.sales_day_by_user', 'View sales for day by user'],
    ['reports.sales_period_by_user', 'View sales for period by user'],
    ['reports.sales_period_all_users', 'View sales for period all users'],
    ['reports.sales_for_product', 'View sales for product'],
    ['reports.sales_vs_stock_balances', 'View sales vs stock balances for period'],
    ['reports.sales_by_category_user', 'View sales by category for user'],
    ['reports.sales_by_category_branch', 'View sales by category for branch'],
    ['reports.revenue_report_period', 'View revenue report for period for branch'],
    ['reports.sales_graph_period', 'View sales graph for period for branch'],
    ['reports.end_of_day_printout', 'View end of day print out slip'],
    ['reports.product_sales_per_day', 'View all product sales per day with amounts by category'],
];

try {
    $primaryDb->beginTransaction();

$inserted = 0;
$skipped = 0;

    foreach ($permissions as $perm) {
        $key = $perm[0];
        $description = $perm[1] ?? '';
        
    // Check if permission already exists
        $existing = $primaryDb->getRow(
        "SELECT id FROM permissions WHERE permission_key = :key",
            [':key' => $key]
    );
    
    if ($existing) {
            echo "  ⚠ Permission '$key' already exists, skipping...\n";
        $skipped++;
            continue;
        }
        
        // Insert permission
        $primaryDb->insert('permissions', [
            'permission_key' => $key,
            'description' => $description,
            'module' => getModuleFromKey($key),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        echo "  ✓ Added permission: $key\n";
        $inserted++;
    }
    
    // Assign all permissions to Administrator role
    $adminRole = $primaryDb->getRow("SELECT id FROM roles WHERE name LIKE '%Administrator%' OR name LIKE '%Admin%' LIMIT 1");

if ($adminRole) {
        $adminRoleId = $adminRole['id'];
        $assigned = 0;
        
        foreach ($permissions as $perm) {
            $key = $perm[0];
            
            // Get permission ID
            $permission = $primaryDb->getRow(
                "SELECT id FROM permissions WHERE permission_key = :key",
                [':key' => $key]
            );
            
            if ($permission) {
            // Check if already assigned
                $existing = $primaryDb->getRow(
                    "SELECT id FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id",
                [
                        ':role_id' => $adminRoleId,
                        ':permission_id' => $permission['id']
                ]
            );
            
            if (!$existing) {
                    $primaryDb->insert('role_permissions', [
                        'role_id' => $adminRoleId,
                        'permission_id' => $permission['id']
                    ]);
                    $assigned++;
                }
            }
        }
        
        echo "\n  ✓ Assigned $assigned permissions to Administrator role\n";
    }
    
    $primaryDb->commit();
    
    echo "\n✓ Permissions seeding completed!\n";
    echo "  - Inserted: $inserted\n";
    echo "  - Skipped: $skipped\n";
    
} catch (Exception $e) {
    $primaryDb->rollBack();
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

function getModuleFromKey($key) {
    $parts = explode('.', $key);
    return $parts[0] ?? 'general';
}

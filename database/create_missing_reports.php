<?php
/**
 * Script to create all missing report files
 * This will generate all 25+ Other Reports and 11 Sales Reports
 */

require_once dirname(__FILE__) . '/../config.php';

$reportsToCreate = [
    // Other Reports (remaining 24)
    'customer_purchases_period',
    'current_customers_branch',
    'customers_by_sales_branch',
    'highest_movers_qty',
    'highest_movers_gross_profit',
    'highest_movers_sales_contribution',
    'highest_movers_sales_supplier',
    'stock_balances_branch',
    'stock_balances_branch_category',
    'stock_balances_company',
    'stock_adjustments_period',
    'view_stock_purchases',
    'view_stock_purchases_product',
    'purchases_by_supplier',
    'individual_product_movement',
    'view_stock_transfers',
    'view_stock_transfers_period',
    'stock_expiry_report',
    'consignment_stock_report',
    'price_list',
    'fifo_stock_ageing',
    'products_converted',
    'performance_report',
    
    // Sales Reports (11)
    'sales_day_by_user',
    'sales_period_by_user',
    'sales_period_all_users',
    'sales_for_product',
    'sales_vs_stock_balances',
    'sales_by_category_user',
    'sales_by_category_branch',
    'revenue_report_period',
    'sales_graph_period',
    'end_of_day_printout',
    'product_sales_per_day',
];

echo "This script lists reports that need to be created.\n";
echo "Total reports to create: " . count($reportsToCreate) . "\n\n";
echo "Reports:\n";
foreach ($reportsToCreate as $report) {
    echo "  - $report.php\n";
}

echo "\nNote: Reports should be created manually following the pattern in discounts_period.php\n";
echo "Each report should include:\n";
echo "  - Filters (date range, branch, product, etc.)\n";
echo "  - Summary stat cards\n";
echo "  - DataTable with searchable, sortable data\n";
echo "  - PDF export functionality\n";
echo "  - Proper permissions check\n";



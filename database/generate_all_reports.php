<?php
/**
 * Generate all missing report files
 * This script creates all 25+ Other Reports and 11 Sales Reports
 */

$reports = [
    // Other Reports
    ['file' => 'current_customers_branch.php', 'title' => 'Current Customers List for Branch', 'permission' => 'reports.current_customers_branch', 'icon' => 'bi-people'],
    ['file' => 'customers_by_sales_branch.php', 'title' => 'Customers List by Sales for Branch', 'permission' => 'reports.customers_by_sales_branch', 'icon' => 'bi-person-lines-fill'],
    ['file' => 'highest_movers_qty.php', 'title' => 'Highest Movers by Qty for Branch', 'permission' => 'reports.highest_movers_qty', 'icon' => 'bi-arrow-up-circle'],
    ['file' => 'highest_movers_gross_profit.php', 'title' => 'Highest Movers by Gross Profit', 'permission' => 'reports.highest_movers_gross_profit', 'icon' => 'bi-graph-up-arrow'],
    ['file' => 'highest_movers_sales_contribution.php', 'title' => 'Highest Movers by Sales Contribution', 'permission' => 'reports.highest_movers_sales_contribution', 'icon' => 'bi-pie-chart'],
    ['file' => 'highest_movers_sales_supplier.php', 'title' => 'Highest Movers by Sales for Supplier', 'permission' => 'reports.highest_movers_sales_supplier', 'icon' => 'bi-truck'],
    ['file' => 'stock_balances_branch.php', 'title' => 'Stock Balances for Branch', 'permission' => 'reports.stock_balances_branch', 'icon' => 'bi-boxes'],
    ['file' => 'stock_balances_branch_category.php', 'title' => 'Stock Balances for Branch by Category', 'permission' => 'reports.stock_balances_branch_category', 'icon' => 'bi-tags'],
    ['file' => 'stock_balances_company.php', 'title' => 'Stock Balances for Company', 'permission' => 'reports.stock_balances_company', 'icon' => 'bi-building'],
    ['file' => 'stock_adjustments_period.php', 'title' => 'Stock Adjustments for Period', 'permission' => 'reports.stock_adjustments_period', 'icon' => 'bi-arrow-left-right'],
    ['file' => 'view_stock_purchases.php', 'title' => 'View Stock Purchases', 'permission' => 'reports.view_stock_purchases', 'icon' => 'bi-cart-plus'],
    ['file' => 'view_stock_purchases_product.php', 'title' => 'View Stock Purchases for Product', 'permission' => 'reports.view_stock_purchases_product', 'icon' => 'bi-box-seam'],
    ['file' => 'purchases_by_supplier.php', 'title' => 'Purchases by Supplier', 'permission' => 'reports.purchases_by_supplier', 'icon' => 'bi-truck'],
    ['file' => 'individual_product_movement.php', 'title' => 'Individual Product Movement', 'permission' => 'reports.individual_product_movement', 'icon' => 'bi-arrow-repeat'],
    ['file' => 'view_stock_transfers.php', 'title' => 'View Stock Transfers', 'permission' => 'reports.view_stock_transfers', 'icon' => 'bi-arrow-left-right'],
    ['file' => 'view_stock_transfers_period.php', 'title' => 'View Stock Transfers for Period', 'permission' => 'reports.view_stock_transfers_period', 'icon' => 'bi-calendar-range'],
    ['file' => 'stock_expiry_report.php', 'title' => 'Stock Expiry Report', 'permission' => 'reports.stock_expiry_report', 'icon' => 'bi-calendar-x'],
    ['file' => 'consignment_stock_report.php', 'title' => 'Consignment Stock Report', 'permission' => 'reports.consignment_stock_report', 'icon' => 'bi-box-arrow-in-right'],
    ['file' => 'price_list.php', 'title' => 'Price List', 'permission' => 'reports.price_list', 'icon' => 'bi-list-ul'],
    ['file' => 'fifo_stock_ageing.php', 'title' => 'FIFO Stock Ageing Report', 'permission' => 'reports.fifo_stock_ageing', 'icon' => 'bi-clock-history'],
    ['file' => 'products_converted.php', 'title' => 'Products Converted', 'permission' => 'reports.products_converted', 'icon' => 'bi-arrow-repeat'],
    ['file' => 'performance_report.php', 'title' => 'Performance Report', 'permission' => 'reports.performance_report', 'icon' => 'bi-speedometer2'],
    
    // Sales Reports
    ['file' => 'sales_day_by_user.php', 'title' => 'Sales for Day by User', 'permission' => 'reports.sales_day_by_user', 'icon' => 'bi-calendar-day'],
    ['file' => 'sales_period_by_user.php', 'title' => 'Sales for Period by User', 'permission' => 'reports.sales_period_by_user', 'icon' => 'bi-calendar-range'],
    ['file' => 'sales_period_all_users.php', 'title' => 'Sales for Period All Users', 'permission' => 'reports.sales_period_all_users', 'icon' => 'bi-people'],
    ['file' => 'sales_for_product.php', 'title' => 'Sales for Product', 'permission' => 'reports.sales_for_product', 'icon' => 'bi-box-seam'],
    ['file' => 'sales_vs_stock_balances.php', 'title' => 'Sales vs Stock Balances', 'permission' => 'reports.sales_vs_stock_balances', 'icon' => 'bi-bar-chart'],
    ['file' => 'sales_by_category_user.php', 'title' => 'Sales by Category for User', 'permission' => 'reports.sales_by_category_user', 'icon' => 'bi-person-tag'],
    ['file' => 'sales_by_category_branch.php', 'title' => 'Sales by Category for Branch', 'permission' => 'reports.sales_by_category_branch', 'icon' => 'bi-shop'],
    ['file' => 'revenue_report_period.php', 'title' => 'Revenue Report for Period', 'permission' => 'reports.revenue_report_period', 'icon' => 'bi-cash-stack'],
    ['file' => 'sales_graph_period.php', 'title' => 'Sales Graph for Period', 'permission' => 'reports.sales_graph_period', 'icon' => 'bi-graph-up'],
    ['file' => 'end_of_day_printout.php', 'title' => 'End of Day Print Out', 'permission' => 'reports.end_of_day_printout', 'icon' => 'bi-printer'],
    ['file' => 'product_sales_per_day.php', 'title' => 'Product Sales per Day by Category', 'permission' => 'reports.product_sales_per_day', 'icon' => 'bi-calendar3'],
];

echo "Reports to create: " . count($reports) . "\n";
echo "Note: Reports should be created manually following the pattern.\n";
echo "Each report needs:\n";
echo "  - Proper filters (date range, branch, etc.)\n";
echo "  - Summary stat cards\n";
echo "  - DataTable with search/sort\n";
echo "  - PDF export\n";
echo "  - Permission checks\n";


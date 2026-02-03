<?php
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/includes/auth.php';
$auth = Auth::getInstance();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapeHtml($pageTitle) ?> - <?= SYSTEM_NAME ?></title>
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_URL ?>images/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="<?= ASSETS_URL ?>css/style.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --secondary-blue: #3b82f6;
            --light-blue: #dbeafe;
            --accent-blue: #60a5fa;
            --dark-navy: #1e40af;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <img src="<?= ASSETS_URL ?>images/logo.png" alt="ELECTROX" class="logo-img">
                    <span class="logo-text">ELECTROX</span>
                </div>
                <div class="sidebar-controls">
                    <button class="sidebar-close-btn" id="sidebarCloseBtn" title="Close Sidebar">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse Sidebar">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button class="sidebar-expand-btn" id="sidebarExpandBtn" title="Expand Sidebar">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>
            <ul class="sidebar-menu">
                <?php
                $auth = Auth::getInstance();
                $currentUri = $_SERVER['REQUEST_URI'] ?? '';
                $isDashboard = (strpos($currentUri, '/modules/dashboard/') !== false || (strpos($currentUri, '/dashboard') !== false && strpos($currentUri, '/modules/') === false));
                $isProducts = strpos($currentUri, '/modules/products/') !== false || strpos($currentUri, '/modules/inventory/') !== false || strpos($currentUri, '/modules/tradeins/') !== false;
                $isPOS = strpos($currentUri, '/modules/pos/') !== false;
                $isSales = strpos($currentUri, '/modules/sales/') !== false;
                $isInvoicing = strpos($currentUri, '/modules/invoicing/') !== false;
                $isCustomers = strpos($currentUri, '/modules/customers/') !== false;
                $isReports = strpos($currentUri, '/modules/reports/') !== false;
                $isAdministration = strpos($currentUri, '/modules/branches/') !== false || strpos($currentUri, '/modules/users/') !== false || strpos($currentUri, '/modules/settings/') !== false || strpos($currentUri, '/modules/roles/') !== false || strpos($currentUri, '/modules/currencies/') !== false || strpos($currentUri, '/view_all_fiscalizations') !== false || strpos($currentUri, '/check_fiscalization_status') !== false;
                $isSuppliers = strpos($currentUri, '/modules/suppliers/') !== false;
                ?>
                <?php if ($auth->hasPermission('dashboard.view')): ?>
                <li><a href="<?= BASE_URL ?>modules/dashboard/index.php" class="<?= $isDashboard ? 'active' : '' ?>" data-tooltip="Dashboard"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a></li>
                <?php endif; ?>
                <?php if ($auth->hasAnyModulePermission('products') || $auth->hasAnyModulePermission('inventory') || $auth->hasAnyModulePermission('grn') || $auth->hasAnyModulePermission('transfers') || $auth->hasAnyModulePermission('tradeins')): ?>
                <li class="has-submenu">
                    <a href="#" class="<?= $isProducts ? 'active' : '' ?>" data-tooltip="Products">
                        <i class="bi bi-box-seam"></i> <span>Products</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <?php if ($auth->hasPermission('products.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/products/index.php" class="<?= strpos($currentUri, '/modules/products/index.php') !== false || strpos($currentUri, '/modules/products/add.php') !== false || strpos($currentUri, '/modules/products/edit.php') !== false || strpos($currentUri, '/modules/products/view.php') !== false ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>Manage Products</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('products.categories')): ?>
                        <li><a href="<?= BASE_URL ?>modules/products/categories.php" class="<?= strpos($currentUri, '/modules/products/categories') !== false ? 'active' : '' ?>"><i class="bi bi-tags"></i> <span>Manage Categories</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('products.barcodes')): ?>
                        <li><a href="<?= BASE_URL ?>modules/products/barcodes.php" class="<?= strpos($currentUri, '/modules/products/barcodes') !== false ? 'active' : '' ?>"><i class="bi bi-upc-scan"></i> <span>Product Barcodes</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('products.stock_take') && getSetting('allow_stock_take', '1') == '1'): ?>
                        <li><a href="<?= BASE_URL ?>modules/products/stock_take.php" class="<?= strpos($currentUri, '/modules/products/stock_take.php') !== false ? 'active' : '' ?>"><i class="bi bi-clipboard-check"></i> <span>Stock Take</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('products.stock_take_reports') && getSetting('allow_stock_take', '1') == '1'): ?>
                        <li><a href="<?= BASE_URL ?>modules/products/stock_take_reports.php" class="<?= strpos($currentUri, '/modules/products/stock_take_reports') !== false ? 'active' : '' ?>"><i class="bi bi-file-earmark-text"></i> <span>Stock Take Reports</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('inventory.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/inventory/index.php" class="<?= strpos($currentUri, '/modules/inventory/index') !== false || (strpos($currentUri, '/modules/inventory/') !== false && strpos($currentUri, '/modules/inventory/grn') === false && strpos($currentUri, '/modules/inventory/transfers') === false) ? 'active' : '' ?>"><i class="bi bi-archive"></i> <span>Stock Levels</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('grn.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/inventory/grn.php" class="<?= strpos($currentUri, '/modules/inventory/grn') !== false ? 'active' : '' ?>"><i class="bi bi-box-arrow-in-down"></i> <span>Goods Received</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('transfers.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/inventory/transfers.php" class="<?= strpos($currentUri, '/modules/inventory/transfers') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-left-right"></i> <span>Transfers</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('tradeins.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/tradeins/index.php" class="<?= strpos($currentUri, '/modules/tradeins/') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-repeat"></i> <span>Trade-Ins</span></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($auth->hasAnyModulePermission('pos') || $auth->hasAnyModulePermission('drawer') || $auth->hasAnyModulePermission('receipts')): ?>
                <li class="has-submenu">
                    <a href="#" class="<?= $isPOS ? 'active' : '' ?>" data-tooltip="POS">
                        <i class="bi bi-cash-coin"></i> <span>POS</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <?php if ($auth->hasPermission('pos.view') || $auth->hasPermission('pos.create_sale')): ?>
                        <li><a href="<?= BASE_URL ?>modules/pos/index.php" class="<?= strpos($currentUri, '/modules/pos/index.php') !== false || (strpos($currentUri, '/modules/pos/') !== false && strpos($currentUri, '/modules/pos/manage') === false && strpos($currentUri, '/modules/pos/cash') === false && strpos($currentUri, '/modules/pos/customize') === false) ? 'active' : '' ?>"><i class="bi bi-cart-plus"></i> <span>New Sales</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('pos.manage_sales')): ?>
                        <li><a href="<?= BASE_URL ?>modules/pos/manage.php" class="<?= strpos($currentUri, '/modules/pos/manage') !== false ? 'active' : '' ?>"><i class="bi bi-receipt-cutoff"></i> <span>Manage Sales</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('pos.cash_management') || $auth->hasPermission('drawer.transaction') || $auth->hasPermission('drawer.report')): ?>
                        <li><a href="<?= BASE_URL ?>modules/pos/cash.php" class="<?= strpos($currentUri, '/modules/pos/cash') !== false ? 'active' : '' ?>"><i class="bi bi-cash-stack"></i> <span>Cash Management</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('pos.customize')): ?>
                        <li><a href="<?= BASE_URL ?>modules/pos/customize.php" class="<?= strpos($currentUri, '/modules/pos/customize') !== false ? 'active' : '' ?>"><i class="bi bi-sliders"></i> <span>POS Customization</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('pos.shift_management')): ?>
                        <li><a href="<?= BASE_URL ?>modules/pos/shift_management.php" class="<?= strpos($currentUri, '/modules/pos/shift_management') !== false ? 'active' : '' ?>"><i class="bi bi-clock-history"></i> <span>Shift Management</span></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($auth->hasAnyModulePermission('sales')): ?>
                <li class="has-submenu">
                    <a href="#" class="<?= $isSales ? 'active' : '' ?>" data-tooltip="Sales">
                        <i class="bi bi-cart-check"></i> <span>Sales</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <?php if ($auth->hasPermission('sales.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/sales/dashboard.php" class="<?= strpos($currentUri, '/modules/sales/dashboard') !== false ? 'active' : '' ?>"><i class="bi bi-graph-up"></i> <span>Sales Dashboard</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/sales/index.php" class="<?= strpos($currentUri, '/modules/sales/index') !== false || (strpos($currentUri, '/modules/sales/') !== false && strpos($currentUri, '/modules/sales/dashboard') === false && strpos($currentUri, '/modules/sales/cancelled') === false && strpos($currentUri, '/modules/sales/credit') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>Sales</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('sales.view') && getSetting('allow_credit_sales', '0') == '1'): ?>
                        <li><a href="<?= BASE_URL ?>modules/sales/credit_sales.php" class="<?= strpos($currentUri, '/modules/sales/credit') !== false ? 'active' : '' ?>"><i class="bi bi-credit-card"></i> <span>Credit Sales</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('sales.laybyes')): ?>
                        <li><a href="<?= BASE_URL ?>modules/sales/laybyes.php" class="<?= strpos($currentUri, '/modules/sales/laybyes') !== false ? 'active' : '' ?>"><i class="bi bi-bag-check"></i> <span>Laybyes</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasAnyPermission('sales.refund', 'receipts.refund', 'reports.refunds')): ?>
                        <li><a href="<?= BASE_URL ?>modules/sales/cancelled_sales.php" class="<?= strpos($currentUri, '/modules/sales/cancelled') !== false ? 'active' : '' ?>"><i class="bi bi-x-circle"></i> <span>Cancelled Sales</span></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($auth->hasAnyModulePermission('invoicing')): ?>
                <li class="has-submenu">
                    <a href="#" class="<?= $isInvoicing ? 'active' : '' ?>" data-tooltip="Invoicing">
                        <i class="bi bi-receipt"></i> <span>Invoicing</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <?php if ($auth->hasPermission('invoicing.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/index.php" class="<?= strpos($currentUri, '/modules/invoicing/index') !== false || (strpos($currentUri, '/modules/invoicing/') !== false && strpos($currentUri, '/modules/invoicing/create') === false && strpos($currentUri, '/modules/invoicing/customize') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>All Invoices</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('invoicing.create')): ?>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/create.php?type=proforma" class="<?= strpos($currentUri, '/modules/invoicing/create') !== false ? 'active' : '' ?>"><i class="bi bi-file-earmark-text"></i> <span>Proforma Invoice</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('invoicing.customize')): ?>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/customize.php" class="<?= strpos($currentUri, '/modules/invoicing/customize') !== false ? 'active' : '' ?>"><i class="bi bi-sliders"></i> <span>Customize</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('invoicing.customize')): ?>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/terms/index.php" class="<?= strpos($currentUri, '/modules/invoicing/terms') !== false ? 'active' : '' ?>"><i class="bi bi-file-text"></i> <span>Terms & Conditions</span></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($auth->hasAnyModulePermission('customers')): ?>
                <li class="has-submenu">
                    <a href="#" class="<?= $isCustomers ? 'active' : '' ?>" data-tooltip="Customers">
                        <i class="bi bi-people"></i> <span>Customers</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <?php if ($auth->hasPermission('customers.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/customers/index.php" class="<?= strpos($currentUri, '/modules/customers/index') !== false || (strpos($currentUri, '/modules/customers/') !== false && strpos($currentUri, '/modules/customers/add') === false && strpos($currentUri, '/modules/customers/view') === false && strpos($currentUri, '/modules/customers/edit') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>All Customers</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('customers.create')): ?>
                        <li><a href="<?= BASE_URL ?>modules/customers/add.php" class="<?= strpos($currentUri, '/modules/customers/add') !== false ? 'active' : '' ?>"><i class="bi bi-plus-circle"></i> <span>Add Customer</span></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($auth->hasAnyModulePermission('suppliers')): ?>
                <li class="has-submenu">
                    <a href="#" class="<?= $isSuppliers ? 'active' : '' ?>" data-tooltip="Suppliers">
                        <i class="bi bi-truck"></i> <span>Suppliers</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <?php if ($auth->hasPermission('suppliers.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/suppliers/index.php" class="<?= strpos($currentUri, '/modules/suppliers/index') !== false || (strpos($currentUri, '/modules/suppliers/') !== false && strpos($currentUri, '/modules/suppliers/add') === false && strpos($currentUri, '/modules/suppliers/view') === false && strpos($currentUri, '/modules/suppliers/edit') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>All Suppliers</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('suppliers.create')): ?>
                        <li><a href="<?= BASE_URL ?>modules/suppliers/add.php" class="<?= strpos($currentUri, '/modules/suppliers/add') !== false ? 'active' : '' ?>"><i class="bi bi-plus-circle"></i> <span>Add Supplier</span></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($auth->hasAnyModulePermission('reports')): ?>
                <li class="has-submenu">
                    <a href="#" class="<?= $isReports ? 'active' : '' ?>" data-tooltip="Reports">
                        <i class="bi bi-graph-up"></i> <span>Reports</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <?php if ($auth->hasPermission('reports.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/index.php" class="<?= strpos($currentUri, '/modules/reports/index') !== false ? 'active' : '' ?>"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a></li>
                        <?php endif; ?>
                        <?php 
                        // Helper function to check if user has access to general reports
                        $hasGeneralReports = $auth->hasPermission('reports.general') || 
                                             $auth->hasPermission('reports.view') || 
                                             $auth->hasPermission('reports.sales_summary') || 
                                             $auth->hasPermission('reports.receipts') || 
                                             $auth->hasPermission('reports.refunds') || 
                                             $auth->hasPermission('reports.sales_by_product') || 
                                             $auth->hasPermission('reports.sales_by_category') || 
                                             $auth->hasPermission('reports.sales_by_discount') || 
                                             $auth->hasPermission('reports.sales_by_payment') || 
                                             $auth->hasPermission('reports.taxes') || 
                                             $auth->hasPermission('reports.shifts');
                        if ($hasGeneralReports): ?>
                        <li class="submenu-header">General Reports</li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.sales_summary')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_summary.php" class="<?= strpos($currentUri, '/modules/reports/sales_summary') !== false ? 'active' : '' ?>"><i class="bi bi-bar-chart"></i> <span>Sales Summary</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.receipts')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/receipts.php" class="<?= strpos($currentUri, '/modules/reports/receipts') !== false ? 'active' : '' ?>"><i class="bi bi-receipt-cutoff"></i> <span>Receipts</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.refunds') || $auth->hasPermission('receipts.refund')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/refunds.php" class="<?= strpos($currentUri, '/modules/reports/refunds') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-counterclockwise"></i> <span>Refunds</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.sales_by_product')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_products.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_products') !== false ? 'active' : '' ?>"><i class="bi bi-box-seam"></i> <span>Sales by Products</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.sales_by_category')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_category.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_category') !== false ? 'active' : '' ?>"><i class="bi bi-tags"></i> <span>Sales by Category</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.sales_by_discount')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_discounts.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_discounts') !== false ? 'active' : '' ?>"><i class="bi bi-percent"></i> <span>Sales by Discounts</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.sales_by_payment')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_payment_types.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_payment_types') !== false ? 'active' : '' ?>"><i class="bi bi-credit-card"></i> <span>Sales by Payment Types</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.taxes')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/taxes.php" class="<?= strpos($currentUri, '/modules/reports/taxes') !== false ? 'active' : '' ?>"><i class="bi bi-receipt"></i> <span>Taxes</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasGeneralReports || $auth->hasPermission('reports.shifts')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/shifts.php" class="<?= strpos($currentUri, '/modules/reports/shifts') !== false ? 'active' : '' ?>"><i class="bi bi-clock-history"></i> <span>Shifts</span></a></li>
                        <?php endif; ?>
                        <?php 
                        // Helper function to check if user has access to advanced sales reports
                        $hasAdvancedSales = $auth->hasPermission('reports.advanced_sales') || 
                                            $auth->hasPermission('reports.view') || 
                                            $auth->hasPermission('reports.product_wise_receipt') || 
                                            $auth->hasPermission('reports.sales_by_trend') || 
                                            $auth->hasPermission('reports.deleted_receipts') || 
                                            $auth->hasPermission('reports.order_type_wise') || 
                                            $auth->hasPermission('reports.product_wise_tax') || 
                                            $auth->hasPermission('reports.manual_receipts') || 
                                            $auth->hasPermission('reports.sales_by_order') || 
                                            $auth->hasPermission('reports.product_wise_order') || 
                                            $auth->hasPermission('reports.sales_by_modifier') || 
                                            $auth->hasPermission('reports.product_sales_by_staff') || 
                                            $auth->hasPermission('reports.sales_by_staff') || 
                                            $auth->hasPermission('reports.products_consumed_by_staff') || 
                                            $auth->hasPermission('reports.ecommerce_sales');
                        if ($hasAdvancedSales): ?>
                        <li class="submenu-header">Advanced Sales Reports</li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.product_wise_receipt')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/product_wise_receipt.php" class="<?= strpos($currentUri, '/modules/reports/product_wise_receipt') !== false ? 'active' : '' ?>"><i class="bi bi-file-earmark-text"></i> <span>Product Wise Receipt</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.sales_by_trend')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_trend.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_trend') !== false ? 'active' : '' ?>"><i class="bi bi-graph-up-arrow"></i> <span>Sales by Trend</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.deleted_receipts') || $auth->hasPermission('receipts.delete')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/deleted_receipts.php" class="<?= strpos($currentUri, '/modules/reports/deleted_receipts') !== false ? 'active' : '' ?>"><i class="bi bi-trash"></i> <span>Deleted Receipts</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.order_type_wise')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/order_type_wise_sales.php" class="<?= strpos($currentUri, '/modules/reports/order_type_wise_sales') !== false ? 'active' : '' ?>"><i class="bi bi-list-check"></i> <span>Order Type Wise Sales</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.product_wise_tax')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/product_wise_tax_charge.php" class="<?= strpos($currentUri, '/modules/reports/product_wise_tax_charge') !== false ? 'active' : '' ?>"><i class="bi bi-calculator"></i> <span>Product Wise Tax/Charge</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.manual_receipts')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/manual_receipts.php" class="<?= strpos($currentUri, '/modules/reports/manual_receipts') !== false ? 'active' : '' ?>"><i class="bi bi-pencil-square"></i> <span>Manual Receipts</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.sales_by_order')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_orders.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_orders') !== false ? 'active' : '' ?>"><i class="bi bi-cart-check"></i> <span>Sales by Orders</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.product_wise_order')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/product_wise_orders.php" class="<?= strpos($currentUri, '/modules/reports/product_wise_orders') !== false ? 'active' : '' ?>"><i class="bi bi-box-arrow-in-right"></i> <span>Product Wise Orders</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.sales_by_modifier')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_modifiers.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_modifiers') !== false ? 'active' : '' ?>"><i class="bi bi-sliders"></i> <span>Sales by Modifiers</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.product_sales_by_staff')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/product_sales_by_staff.php" class="<?= strpos($currentUri, '/modules/reports/product_sales_by_staff') !== false ? 'active' : '' ?>"><i class="bi bi-person-badge"></i> <span>Product Sales by Staff</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.sales_by_staff')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_staff.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_staff') !== false ? 'active' : '' ?>"><i class="bi bi-people"></i> <span>Sales by Staff</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.products_consumed_by_staff')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/products_consumed_by_staff.php" class="<?= strpos($currentUri, '/modules/reports/products_consumed_by_staff') !== false ? 'active' : '' ?>"><i class="bi bi-person-dash"></i> <span>Products Consumed by Staff</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasAdvancedSales || $auth->hasPermission('reports.ecommerce_sales')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/ecommerce_sales.php" class="<?= strpos($currentUri, '/modules/reports/ecommerce_sales') !== false ? 'active' : '' ?>"><i class="bi bi-globe"></i> <span>Ecommerce Sales</span></a></li>
                        <?php endif; ?>
                        <?php 
                        // Helper function to check if user has access to suspicious reports
                        $hasSuspiciousReports = $auth->hasPermission('reports.suspicious') || 
                                                $auth->hasPermission('reports.view') || 
                                                $auth->hasPermission('reports.product_wise_deleted') || 
                                                $auth->hasPermission('reports.refunds_credit_notes') || 
                                                $auth->hasPermission('reports.deleted_products_open_orders');
                        if ($hasSuspiciousReports): ?>
                        <li class="submenu-header">Suspicious Reports</li>
                        <?php endif; ?>
                        <?php if ($hasSuspiciousReports || $auth->hasPermission('reports.product_wise_deleted')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/product_wise_deleted_receipts.php" class="<?= strpos($currentUri, '/modules/reports/product_wise_deleted_receipts') !== false ? 'active' : '' ?>"><i class="bi bi-exclamation-triangle"></i> <span>Product Wise Deleted Receipts</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSuspiciousReports || $auth->hasPermission('reports.refunds_credit_notes')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/refunds_credit_notes.php" class="<?= strpos($currentUri, '/modules/reports/refunds_credit_notes') !== false ? 'active' : '' ?>"><i class="bi bi-file-earmark-minus"></i> <span>Refunds & Credit Notes</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSuspiciousReports || $auth->hasPermission('reports.deleted_products_open_orders')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/deleted_products_open_orders.php" class="<?= strpos($currentUri, '/modules/reports/deleted_products_open_orders') !== false ? 'active' : '' ?>"><i class="bi bi-exclamation-circle"></i> <span>Deleted Products in Open Orders</span></a></li>
                        <?php endif; ?>
                        <?php 
                        // Other Reports section
                        $hasOtherReports = $auth->hasPermission('reports.discounts_period') || 
                                          $auth->hasPermission('reports.product_discounts_period') || 
                                          $auth->hasPermission('reports.customer_purchases_period') || 
                                          $auth->hasPermission('reports.current_customers_branch') || 
                                          $auth->hasPermission('reports.customers_by_sales_branch') || 
                                          $auth->hasPermission('reports.highest_movers_qty') || 
                                          $auth->hasPermission('reports.highest_movers_gross_profit') || 
                                          $auth->hasPermission('reports.highest_movers_sales_contribution') || 
                                          $auth->hasPermission('reports.highest_movers_sales_supplier') || 
                                          $auth->hasPermission('reports.stock_balances_branch') || 
                                          $auth->hasPermission('reports.stock_balances_branch_category') || 
                                          $auth->hasPermission('reports.stock_balances_company') || 
                                          $auth->hasPermission('reports.stock_adjustments_period') || 
                                          $auth->hasPermission('reports.view_stock_purchases') || 
                                          $auth->hasPermission('reports.view_stock_purchases_product') || 
                                          $auth->hasPermission('reports.purchases_by_supplier') || 
                                          $auth->hasPermission('reports.individual_product_movement') || 
                                          $auth->hasPermission('reports.view_stock_transfers') || 
                                          $auth->hasPermission('reports.view_stock_transfers_period') || 
                                          $auth->hasPermission('reports.stock_expiry_report') || 
                                          $auth->hasPermission('reports.consignment_stock_report') || 
                                          $auth->hasPermission('reports.price_list') || 
                                          $auth->hasPermission('reports.fifo_stock_ageing') || 
                                          $auth->hasPermission('reports.products_converted') || 
                                          $auth->hasPermission('reports.performance_report') ||
                                          $auth->hasPermission('reports.view');
                        if ($hasOtherReports): ?>
                        <li class="submenu-header">Other Reports</li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.discounts_period')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/discounts_period.php" class="<?= strpos($currentUri, '/modules/reports/discounts_period') !== false ? 'active' : '' ?>"><i class="bi bi-percent"></i> <span>Discounts Given for Period</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.product_discounts_period')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/product_discounts_period.php" class="<?= strpos($currentUri, '/modules/reports/product_discounts_period') !== false ? 'active' : '' ?>"><i class="bi bi-tag"></i> <span>Product Discounts for Period</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.customer_purchases_period')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/customer_purchases_period.php" class="<?= strpos($currentUri, '/modules/reports/customer_purchases_period') !== false ? 'active' : '' ?>"><i class="bi bi-person-check"></i> <span>Customer Purchases for Period</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.current_customers_branch')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/current_customers_branch.php" class="<?= strpos($currentUri, '/modules/reports/current_customers_branch') !== false ? 'active' : '' ?>"><i class="bi bi-people"></i> <span>Current Customers List for Branch</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.customers_by_sales_branch')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/customers_by_sales_branch.php" class="<?= strpos($currentUri, '/modules/reports/customers_by_sales_branch') !== false ? 'active' : '' ?>"><i class="bi bi-person-lines-fill"></i> <span>Customers List by Sales for Branch</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.highest_movers_qty')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/highest_movers_qty.php" class="<?= strpos($currentUri, '/modules/reports/highest_movers_qty') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-up-circle"></i> <span>Highest Movers by Qty for Branch</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.highest_movers_gross_profit')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/highest_movers_gross_profit.php" class="<?= strpos($currentUri, '/modules/reports/highest_movers_gross_profit') !== false ? 'active' : '' ?>"><i class="bi bi-graph-up-arrow"></i> <span>Highest Movers by Gross Profit</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.highest_movers_sales_contribution')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/highest_movers_sales_contribution.php" class="<?= strpos($currentUri, '/modules/reports/highest_movers_sales_contribution') !== false ? 'active' : '' ?>"><i class="bi bi-pie-chart"></i> <span>Highest Movers by Sales Contribution</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.highest_movers_sales_supplier')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/highest_movers_sales_supplier.php" class="<?= strpos($currentUri, '/modules/reports/highest_movers_sales_supplier') !== false ? 'active' : '' ?>"><i class="bi bi-truck"></i> <span>Highest Movers by Sales for Supplier</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.stock_balances_branch')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/stock_balances_branch.php" class="<?= strpos($currentUri, '/modules/reports/stock_balances_branch') !== false ? 'active' : '' ?>"><i class="bi bi-boxes"></i> <span>Stock Balances for Branch</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.stock_balances_branch_category')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/stock_balances_branch_category.php" class="<?= strpos($currentUri, '/modules/reports/stock_balances_branch_category') !== false ? 'active' : '' ?>"><i class="bi bi-tags"></i> <span>Stock Balances for Branch by Category</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.stock_balances_company')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/stock_balances_company.php" class="<?= strpos($currentUri, '/modules/reports/stock_balances_company') !== false ? 'active' : '' ?>"><i class="bi bi-building"></i> <span>Stock Balances for Company</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.stock_adjustments_period')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/stock_adjustments_period.php" class="<?= strpos($currentUri, '/modules/reports/stock_adjustments_period') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-left-right"></i> <span>Stock Adjustments for Period</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.view_stock_purchases')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/view_stock_purchases.php" class="<?= strpos($currentUri, '/modules/reports/view_stock_purchases') !== false ? 'active' : '' ?>"><i class="bi bi-cart-plus"></i> <span>View Stock Purchases</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.view_stock_purchases_product')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/view_stock_purchases_product.php" class="<?= strpos($currentUri, '/modules/reports/view_stock_purchases_product') !== false ? 'active' : '' ?>"><i class="bi bi-box-seam"></i> <span>View Stock Purchases for Product</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.purchases_by_supplier')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/purchases_by_supplier.php" class="<?= strpos($currentUri, '/modules/reports/purchases_by_supplier') !== false ? 'active' : '' ?>"><i class="bi bi-truck"></i> <span>Purchases by Supplier</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.individual_product_movement')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/individual_product_movement.php" class="<?= strpos($currentUri, '/modules/reports/individual_product_movement') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-repeat"></i> <span>Individual Product Movement</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.view_stock_transfers')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/view_stock_transfers.php" class="<?= strpos($currentUri, '/modules/reports/view_stock_transfers') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-left-right"></i> <span>View Stock Transfers</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.view_stock_transfers_period')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/view_stock_transfers_period.php" class="<?= strpos($currentUri, '/modules/reports/view_stock_transfers_period') !== false ? 'active' : '' ?>"><i class="bi bi-calendar-range"></i> <span>View Stock Transfers for Period</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.stock_expiry_report')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/stock_expiry_report.php" class="<?= strpos($currentUri, '/modules/reports/stock_expiry_report') !== false ? 'active' : '' ?>"><i class="bi bi-calendar-x"></i> <span>Stock Expiry Report</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.consignment_stock_report')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/consignment_stock_report.php" class="<?= strpos($currentUri, '/modules/reports/consignment_stock_report') !== false ? 'active' : '' ?>"><i class="bi bi-box-arrow-in-right"></i> <span>Consignment Stock Report</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.price_list')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/price_list.php" class="<?= strpos($currentUri, '/modules/reports/price_list') !== false ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>Price List</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.fifo_stock_ageing')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/fifo_stock_ageing.php" class="<?= strpos($currentUri, '/modules/reports/fifo_stock_ageing') !== false ? 'active' : '' ?>"><i class="bi bi-clock-history"></i> <span>FIFO Stock Ageing Report</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.products_converted')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/products_converted.php" class="<?= strpos($currentUri, '/modules/reports/products_converted') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-repeat"></i> <span>Products Converted</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasOtherReports || $auth->hasPermission('reports.performance_report')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/performance_report.php" class="<?= strpos($currentUri, '/modules/reports/performance_report') !== false ? 'active' : '' ?>"><i class="bi bi-speedometer2"></i> <span>Performance Report</span></a></li>
                        <?php endif; ?>
                        <?php 
                        // Sales Reports section
                        $hasSalesReports = $auth->hasPermission('reports.sales_day_by_user') || 
                                          $auth->hasPermission('reports.sales_period_by_user') || 
                                          $auth->hasPermission('reports.sales_period_all_users') || 
                                          $auth->hasPermission('reports.sales_for_product') || 
                                          $auth->hasPermission('reports.sales_vs_stock_balances') || 
                                          $auth->hasPermission('reports.sales_by_category_user') || 
                                          $auth->hasPermission('reports.sales_by_category_branch') || 
                                          $auth->hasPermission('reports.revenue_report_period') || 
                                          $auth->hasPermission('reports.sales_graph_period') || 
                                          $auth->hasPermission('reports.end_of_day_printout') || 
                                          $auth->hasPermission('reports.product_sales_per_day') ||
                                          $auth->hasPermission('reports.view');
                        if ($hasSalesReports): ?>
                        <li class="submenu-header">Sales Reports</li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.sales_day_by_user')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_day_by_user.php" class="<?= strpos($currentUri, '/modules/reports/sales_day_by_user') !== false ? 'active' : '' ?>"><i class="bi bi-calendar-day"></i> <span>Sales for Day by User</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.sales_period_by_user')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_period_by_user.php" class="<?= strpos($currentUri, '/modules/reports/sales_period_by_user') !== false ? 'active' : '' ?>"><i class="bi bi-calendar-range"></i> <span>Sales for Period by User</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.sales_period_all_users')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_period_all_users.php" class="<?= strpos($currentUri, '/modules/reports/sales_period_all_users') !== false ? 'active' : '' ?>"><i class="bi bi-people"></i> <span>Sales for Period All Users</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.sales_for_product')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_for_product.php" class="<?= strpos($currentUri, '/modules/reports/sales_for_product') !== false ? 'active' : '' ?>"><i class="bi bi-box-seam"></i> <span>Sales for Product</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.sales_vs_stock_balances')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_vs_stock_balances.php" class="<?= strpos($currentUri, '/modules/reports/sales_vs_stock_balances') !== false ? 'active' : '' ?>"><i class="bi bi-bar-chart"></i> <span>Sales vs Stock Balances</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.sales_by_category_user')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_category_user.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_category_user') !== false ? 'active' : '' ?>"><i class="bi bi-person-tag"></i> <span>Sales by Category for User</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.sales_by_category_branch')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_by_category_branch.php" class="<?= strpos($currentUri, '/modules/reports/sales_by_category_branch') !== false ? 'active' : '' ?>"><i class="bi bi-shop"></i> <span>Sales by Category for Branch</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.revenue_report_period')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/revenue_report_period.php" class="<?= strpos($currentUri, '/modules/reports/revenue_report_period') !== false ? 'active' : '' ?>"><i class="bi bi-cash-stack"></i> <span>Revenue Report for Period</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.sales_graph_period')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/sales_graph_period.php" class="<?= strpos($currentUri, '/modules/reports/sales_graph_period') !== false ? 'active' : '' ?>"><i class="bi bi-graph-up"></i> <span>Sales Graph for Period</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.end_of_day_printout')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/end_of_day_printout.php" class="<?= strpos($currentUri, '/modules/reports/end_of_day_printout') !== false ? 'active' : '' ?>"><i class="bi bi-printer"></i> <span>End of Day Print Out</span></a></li>
                        <?php endif; ?>
                        <?php if ($hasSalesReports || $auth->hasPermission('reports.product_sales_per_day')): ?>
                        <li><a href="<?= BASE_URL ?>modules/reports/product_sales_per_day.php" class="<?= strpos($currentUri, '/modules/reports/product_sales_per_day') !== false ? 'active' : '' ?>"><i class="bi bi-calendar3"></i> <span>Product Sales per Day by Category</span></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($auth->hasAnyModulePermission('branches') || $auth->hasAnyModulePermission('users') || $auth->hasAnyModulePermission('roles') || $auth->hasAnyModulePermission('currencies') || $auth->hasAnyModulePermission('settings') || $auth->hasAnyModulePermission('fiscalization')): ?>
                <li class="has-submenu">
                    <a href="#" class="<?= $isAdministration ? 'active' : '' ?>" data-tooltip="Administration">
                        <i class="bi bi-gear"></i> <span>Administration</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <?php if ($auth->hasPermission('branches.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/branches/index.php" class="<?= strpos($currentUri, '/modules/branches/') !== false ? 'active' : '' ?>"><i class="bi bi-shop"></i> <span>Branches</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('users.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/users/index.php" class="<?= strpos($currentUri, '/modules/users/') !== false ? 'active' : '' ?>"><i class="bi bi-person-gear"></i> <span>Users</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('roles.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/roles/index.php" class="<?= strpos($currentUri, '/modules/roles/') !== false ? 'active' : '' ?>"><i class="bi bi-shield-check"></i> <span>Roles & Permissions</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('currencies.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/currencies/index.php" class="<?= strpos($currentUri, '/modules/currencies/') !== false ? 'active' : '' ?>"><i class="bi bi-currency-exchange"></i> <span>Currencies</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('settings.view')): ?>
                        <li><a href="<?= BASE_URL ?>modules/settings/index.php" class="<?= strpos($currentUri, '/modules/settings/') !== false ? 'active' : '' ?>"><i class="bi bi-sliders"></i> <span>Settings</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('fiscalization.view_status')): ?>
                        <li><a href="<?= BASE_URL ?>check_fiscalization_status.php" class="<?= strpos($currentUri, '/check_fiscalization_status') !== false ? 'active' : '' ?>"><i class="bi bi-check-circle"></i> <span>Fiscalization Status</span></a></li>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('fiscalization.view_all')): ?>
                        <li><a href="<?= BASE_URL ?>view_all_fiscalizations.php" class="<?= strpos($currentUri, '/view_all_fiscalizations') !== false ? 'active' : '' ?>"><i class="bi bi-receipt-cutoff"></i> <span>All Fiscalizations</span></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
        <div class="main-content">
            <nav class="topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="bi bi-list"></i>
                    </button>
                </div>
                <div class="topbar-right">
                    <?php
                    // Get all active branches for selector
                    $db = Database::getInstance();
                    $allBranches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
                    if ($allBranches === false) {
                        $allBranches = [];
                    }
                    $currentBranchId = $_SESSION['branch_id'] ?? null;
                    $currentBranchName = $_SESSION['branch_name'] ?? 'No Branch Selected';
                    ?>
                    <!-- Branch Selector -->
                    <?php if ($auth->hasPermission('branches.switch')): ?>
                    <div class="dropdown me-3">
                        <button class="btn btn-outline-primary dropdown-toggle branch-selector" type="button" id="branchDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-shop me-2"></i>
                            <span id="currentBranchName"><?= escapeHtml($currentBranchName) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="branchDropdown" style="max-height: 300px; overflow-y: auto;">
                            <?php if (empty($allBranches)): ?>
                                <li><span class="dropdown-item-text text-muted">No branches available</span></li>
                            <?php else: ?>
                                <?php foreach ($allBranches as $branch): ?>
                                    <li>
                                        <a class="dropdown-item branch-option <?= $currentBranchId == $branch['id'] ? 'active' : '' ?>" 
                                           href="#" 
                                           data-branch-id="<?= $branch['id'] ?>" 
                                           data-branch-name="<?= escapeHtml($branch['branch_name']) ?>">
                                            <i class="bi bi-check-circle-fill text-success me-2 <?= $currentBranchId == $branch['id'] ? '' : 'd-none' ?>"></i>
                                            <?= escapeHtml($branch['branch_name']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php else: ?>
                    <!-- Branch Display (No Switch Permission) -->
                    <div class="me-3">
                        <span class="badge bg-secondary">
                            <i class="bi bi-shop me-2"></i>
                            <?= escapeHtml($currentBranchName) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-link dropdown-toggle user-dropdown" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-info-box">
                                <div class="user-info-content">
                                    <span class="user-name"><?= escapeHtml(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) ?></span>
                                    <span class="user-role"><?= escapeHtml($_SESSION['role_name'] ?? 'User') ?></span>
                                </div>
                                <i class="bi bi-chevron-down ms-2"></i>
                            </div>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/users/profile.php"><i class="bi bi-person"></i> My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
            <div class="content-area">


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
            </div>
            <ul class="sidebar-menu">
                <?php
                $currentUri = $_SERVER['REQUEST_URI'] ?? '';
                $isDashboard = (strpos($currentUri, '/modules/dashboard/') !== false || (strpos($currentUri, '/dashboard') !== false && strpos($currentUri, '/modules/') === false));
                $isProducts = strpos($currentUri, '/modules/products/') !== false || strpos($currentUri, '/modules/inventory/') !== false || strpos($currentUri, '/modules/tradeins/') !== false;
                $isPOS = strpos($currentUri, '/modules/pos/') !== false;
                $isSales = strpos($currentUri, '/modules/sales/') !== false;
                $isInvoicing = strpos($currentUri, '/modules/invoicing/') !== false;
                $isCustomers = strpos($currentUri, '/modules/customers/') !== false;
                $isReports = strpos($currentUri, '/modules/reports/') !== false;
                $isAdministration = strpos($currentUri, '/modules/branches/') !== false || strpos($currentUri, '/modules/users/') !== false || strpos($currentUri, '/modules/settings/') !== false || strpos($currentUri, '/modules/roles/') !== false || strpos($currentUri, '/modules/currencies/') !== false;
                $isSuppliers = strpos($currentUri, '/modules/suppliers/') !== false;
                ?>
                <li><a href="<?= BASE_URL ?>modules/dashboard/index.php" class="<?= $isDashboard ? 'active' : '' ?>"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a></li>
                <li class="has-submenu">
                    <a href="#" class="<?= $isProducts ? 'active' : '' ?>">
                        <i class="bi bi-box-seam"></i> <span>Products</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <li><a href="<?= BASE_URL ?>modules/products/index.php" class="<?= strpos($currentUri, '/modules/products/index.php') !== false || (strpos($currentUri, '/modules/products/') !== false && strpos($currentUri, '/modules/products/categories') === false && strpos($currentUri, '/modules/inventory/') === false && strpos($currentUri, '/modules/tradeins/') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>Manage Products</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/products/categories.php" class="<?= strpos($currentUri, '/modules/products/categories') !== false ? 'active' : '' ?>"><i class="bi bi-tags"></i> <span>Manage Categories</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/inventory/index.php" class="<?= strpos($currentUri, '/modules/inventory/index') !== false || (strpos($currentUri, '/modules/inventory/') !== false && strpos($currentUri, '/modules/inventory/grn') === false && strpos($currentUri, '/modules/inventory/transfers') === false) ? 'active' : '' ?>"><i class="bi bi-archive"></i> <span>Stock Levels</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/inventory/grn.php" class="<?= strpos($currentUri, '/modules/inventory/grn') !== false ? 'active' : '' ?>"><i class="bi bi-box-arrow-in-down"></i> <span>Goods Received</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/inventory/transfers.php" class="<?= strpos($currentUri, '/modules/inventory/transfers') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-left-right"></i> <span>Transfers</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/tradeins/index.php" class="<?= strpos($currentUri, '/modules/tradeins/') !== false ? 'active' : '' ?>"><i class="bi bi-arrow-repeat"></i> <span>Trade-Ins</span></a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" class="<?= $isPOS ? 'active' : '' ?>">
                        <i class="bi bi-cash-coin"></i> <span>POS</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <li><a href="<?= BASE_URL ?>modules/pos/index.php" class="<?= strpos($currentUri, '/modules/pos/index.php') !== false || (strpos($currentUri, '/modules/pos/') !== false && strpos($currentUri, '/modules/pos/manage') === false && strpos($currentUri, '/modules/pos/cash') === false && strpos($currentUri, '/modules/pos/customize') === false) ? 'active' : '' ?>"><i class="bi bi-cart-plus"></i> <span>New Sales</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/pos/manage.php" class="<?= strpos($currentUri, '/modules/pos/manage') !== false ? 'active' : '' ?>"><i class="bi bi-receipt-cutoff"></i> <span>Manage Sales</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/pos/cash.php" class="<?= strpos($currentUri, '/modules/pos/cash') !== false ? 'active' : '' ?>"><i class="bi bi-cash-stack"></i> <span>Cash Management</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/pos/customize.php" class="<?= strpos($currentUri, '/modules/pos/customize') !== false ? 'active' : '' ?>"><i class="bi bi-sliders"></i> <span>POS Customization</span></a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" class="<?= $isSales ? 'active' : '' ?>">
                        <i class="bi bi-cart-check"></i> <span>Sales</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <li><a href="<?= BASE_URL ?>modules/sales/dashboard.php" class="<?= strpos($currentUri, '/modules/sales/dashboard') !== false ? 'active' : '' ?>"><i class="bi bi-graph-up"></i> <span>Sales Dashboard</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/sales/index.php" class="<?= strpos($currentUri, '/modules/sales/index') !== false || (strpos($currentUri, '/modules/sales/') !== false && strpos($currentUri, '/modules/sales/dashboard') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>Sales</span></a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" class="<?= $isInvoicing ? 'active' : '' ?>">
                        <i class="bi bi-receipt"></i> <span>Invoicing</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <li><a href="<?= BASE_URL ?>modules/invoicing/index.php" class="<?= strpos($currentUri, '/modules/invoicing/index') !== false || (strpos($currentUri, '/modules/invoicing/') !== false && strpos($currentUri, '/modules/invoicing/create') === false && strpos($currentUri, '/modules/invoicing/customize') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>All Invoices</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/create.php?type=proforma" class="<?= strpos($currentUri, '/modules/invoicing/create') !== false && isset($_GET['type']) && $_GET['type'] === 'proforma' ? 'active' : '' ?>"><i class="bi bi-file-earmark-text"></i> <span>Proforma Invoice</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/create.php?type=tax" class="<?= strpos($currentUri, '/modules/invoicing/create') !== false && isset($_GET['type']) && $_GET['type'] === 'tax' ? 'active' : '' ?>"><i class="bi bi-receipt-cutoff"></i> <span>Tax Invoice</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/create.php?type=quote" class="<?= strpos($currentUri, '/modules/invoicing/create') !== false && isset($_GET['type']) && $_GET['type'] === 'quote' ? 'active' : '' ?>"><i class="bi bi-file-earmark-check"></i> <span>Quote</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/create.php?type=credit" class="<?= strpos($currentUri, '/modules/invoicing/create') !== false && isset($_GET['type']) && $_GET['type'] === 'credit' ? 'active' : '' ?>"><i class="bi bi-arrow-counterclockwise"></i> <span>Credit Note</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/invoicing/customize.php" class="<?= strpos($currentUri, '/modules/invoicing/customize') !== false ? 'active' : '' ?>"><i class="bi bi-sliders"></i> <span>Customize</span></a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" class="<?= $isCustomers ? 'active' : '' ?>">
                        <i class="bi bi-people"></i> <span>Customers</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <li><a href="<?= BASE_URL ?>modules/customers/index.php" class="<?= strpos($currentUri, '/modules/customers/index') !== false || (strpos($currentUri, '/modules/customers/') !== false && strpos($currentUri, '/modules/customers/add') === false && strpos($currentUri, '/modules/customers/view') === false && strpos($currentUri, '/modules/customers/edit') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>All Customers</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/customers/add.php" class="<?= strpos($currentUri, '/modules/customers/add') !== false ? 'active' : '' ?>"><i class="bi bi-plus-circle"></i> <span>Add Customer</span></a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" class="<?= $isSuppliers ? 'active' : '' ?>">
                        <i class="bi bi-truck"></i> <span>Suppliers</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <li><a href="<?= BASE_URL ?>modules/suppliers/index.php" class="<?= strpos($currentUri, '/modules/suppliers/index') !== false || (strpos($currentUri, '/modules/suppliers/') !== false && strpos($currentUri, '/modules/suppliers/add') === false && strpos($currentUri, '/modules/suppliers/view') === false && strpos($currentUri, '/modules/suppliers/edit') === false) ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> <span>All Suppliers</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/suppliers/add.php" class="<?= strpos($currentUri, '/modules/suppliers/add') !== false ? 'active' : '' ?>"><i class="bi bi-plus-circle"></i> <span>Add Supplier</span></a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" class="<?= $isReports ? 'active' : '' ?>">
                        <i class="bi bi-graph-up"></i> <span>Reports</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <li><a href="<?= BASE_URL ?>modules/reports/sales.php" class="<?= strpos($currentUri, '/modules/reports/sales') !== false ? 'active' : '' ?>"><i class="bi bi-cash-stack"></i> <span>Sales Reports</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/reports/inventory.php" class="<?= strpos($currentUri, '/modules/reports/inventory') !== false ? 'active' : '' ?>"><i class="bi bi-archive"></i> <span>Inventory Reports</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/reports/financial.php" class="<?= strpos($currentUri, '/modules/reports/financial') !== false ? 'active' : '' ?>"><i class="bi bi-wallet2"></i> <span>Financial Reports</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/reports/customers.php" class="<?= strpos($currentUri, '/modules/reports/customers') !== false ? 'active' : '' ?>"><i class="bi bi-people"></i> <span>Customer Reports</span></a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" class="<?= $isAdministration ? 'active' : '' ?>">
                        <i class="bi bi-gear"></i> <span>Administration</span>
                        <i class="bi bi-chevron-down submenu-arrow"></i>
                    </a>
                    <ul class="submenu">
                        <li><a href="<?= BASE_URL ?>modules/branches/index.php" class="<?= strpos($currentUri, '/modules/branches/') !== false ? 'active' : '' ?>"><i class="bi bi-shop"></i> <span>Branches</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/users/index.php" class="<?= strpos($currentUri, '/modules/users/') !== false ? 'active' : '' ?>"><i class="bi bi-person-gear"></i> <span>Users</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/roles/index.php" class="<?= strpos($currentUri, '/modules/roles/') !== false ? 'active' : '' ?>"><i class="bi bi-shield-check"></i> <span>Roles & Permissions</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/currencies/index.php" class="<?= strpos($currentUri, '/modules/currencies/') !== false ? 'active' : '' ?>"><i class="bi bi-currency-exchange"></i> <span>Currencies</span></a></li>
                        <li><a href="<?= BASE_URL ?>modules/settings/index.php" class="<?= strpos($currentUri, '/modules/settings/') !== false ? 'active' : '' ?>"><i class="bi bi-sliders"></i> <span>Settings</span></a></li>
                    </ul>
                </li>
            </ul>
            <div class="sidebar-footer">
                <div class="clock-container">
                    <canvas id="analogClock" width="120" height="120"></canvas>
                </div>
                <div class="sidebar-date" id="sidebarDate"></div>
            </div>
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


<?php
/**
 * Analyze Permissions and Sidebar Links
 * Maps sidebar navigation to required permissions
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

// Get all existing permissions
$permissions = $primaryDb->getRows("SELECT permission_key, permission_name, module FROM permissions ORDER BY module, permission_key");

echo "=== EXISTING PERMISSIONS IN DATABASE ===\n\n";
foreach ($permissions as $perm) {
    echo sprintf("%-30s | %-30s | %s\n", $perm['module'], $perm['permission_key'], $perm['permission_name']);
}

echo "\n\n=== SIDEBAR NAVIGATION ANALYSIS ===\n\n";

// Define sidebar structure and required permissions
$sidebarStructure = [
    'Dashboard' => [
        'link' => 'modules/dashboard/index.php',
        'permission' => 'dashboard.view',
        'page_check' => 'modules/dashboard/index.php'
    ],
    'Products' => [
        'submenu' => [
            'Manage Products' => [
                'link' => 'modules/products/index.php',
                'permission' => 'products.view',
                'page_check' => 'modules/products/index.php'
            ],
            'Manage Categories' => [
                'link' => 'modules/products/categories.php',
                'permission' => 'products.categories',
                'page_check' => 'modules/products/categories.php'
            ],
            'Stock Levels' => [
                'link' => 'modules/inventory/index.php',
                'permission' => 'inventory.view',
                'page_check' => 'modules/inventory/index.php'
            ],
            'Goods Received' => [
                'link' => 'modules/inventory/grn.php',
                'permission' => 'grn.view',
                'page_check' => 'modules/inventory/grn.php'
            ],
            'Transfers' => [
                'link' => 'modules/inventory/transfers.php',
                'permission' => 'transfers.view',
                'page_check' => 'modules/inventory/transfers.php'
            ],
            'Trade-Ins' => [
                'link' => 'modules/tradeins/index.php',
                'permission' => 'tradeins.view',
                'page_check' => 'modules/tradeins/index.php'
            ]
        ]
    ],
    'POS' => [
        'submenu' => [
            'New Sales' => [
                'link' => 'modules/pos/index.php',
                'permission' => 'pos.create_sale',
                'page_check' => 'modules/pos/index.php'
            ],
            'Manage Sales' => [
                'link' => 'modules/pos/manage.php',
                'permission' => 'pos.manage_sales',
                'page_check' => 'modules/pos/manage.php'
            ],
            'Cash Management' => [
                'link' => 'modules/pos/cash.php',
                'permission' => 'pos.cash_management',
                'page_check' => 'modules/pos/cash.php'
            ],
            'POS Customization' => [
                'link' => 'modules/pos/customize.php',
                'permission' => 'pos.customize',
                'page_check' => 'modules/pos/customize.php'
            ]
        ]
    ],
    'Sales' => [
        'submenu' => [
            'Sales Dashboard' => [
                'link' => 'modules/sales/dashboard.php',
                'permission' => 'sales.view',
                'page_check' => 'modules/sales/dashboard.php'
            ],
            'Sales' => [
                'link' => 'modules/sales/index.php',
                'permission' => 'sales.view',
                'page_check' => 'modules/sales/index.php'
            ]
        ]
    ],
    'Invoicing' => [
        'submenu' => [
            'All Invoices' => [
                'link' => 'modules/invoicing/index.php',
                'permission' => 'invoicing.view',
                'page_check' => 'modules/invoicing/index.php'
            ],
            'Proforma Invoice' => [
                'link' => 'modules/invoicing/create.php?type=proforma',
                'permission' => 'invoicing.create',
                'page_check' => 'modules/invoicing/create.php'
            ],
            'Tax Invoice' => [
                'link' => 'modules/invoicing/create.php?type=tax',
                'permission' => 'invoicing.create',
                'page_check' => 'modules/invoicing/create.php'
            ],
            'Quote' => [
                'link' => 'modules/invoicing/create.php?type=quote',
                'permission' => 'invoicing.create',
                'page_check' => 'modules/invoicing/create.php'
            ],
            'Credit Note' => [
                'link' => 'modules/invoicing/create.php?type=credit',
                'permission' => 'invoicing.create',
                'page_check' => 'modules/invoicing/create.php'
            ],
            'Customize' => [
                'link' => 'modules/invoicing/customize.php',
                'permission' => 'invoicing.customize',
                'page_check' => 'modules/invoicing/customize.php'
            ]
        ]
    ],
    'Customers' => [
        'submenu' => [
            'All Customers' => [
                'link' => 'modules/customers/index.php',
                'permission' => 'customers.view',
                'page_check' => 'modules/customers/index.php'
            ],
            'Add Customer' => [
                'link' => 'modules/customers/add.php',
                'permission' => 'customers.create',
                'page_check' => 'modules/customers/add.php'
            ]
        ]
    ],
    'Suppliers' => [
        'submenu' => [
            'All Suppliers' => [
                'link' => 'modules/suppliers/index.php',
                'permission' => 'suppliers.view',
                'page_check' => 'modules/suppliers/index.php'
            ],
            'Add Supplier' => [
                'link' => 'modules/suppliers/add.php',
                'permission' => 'suppliers.create',
                'page_check' => 'modules/suppliers/add.php'
            ]
        ]
    ],
    'Reports' => [
        'submenu' => [
            'Dashboard' => [
                'link' => 'modules/reports/index.php',
                'permission' => 'reports.view',
                'page_check' => 'modules/reports/index.php'
            ],
            'General Reports' => [
                'category' => true,
                'permission' => 'reports.general',
                'reports' => [
                    'Sales Summary' => ['permission' => 'reports.sales_summary', 'page' => 'modules/reports/sales_summary.php'],
                    'Receipts' => ['permission' => 'reports.receipts', 'page' => 'modules/reports/receipts.php'],
                    'Refunds' => ['permission' => 'reports.refunds', 'page' => 'modules/reports/refunds.php'],
                    'Sales by Products' => ['permission' => 'reports.sales_by_product', 'page' => 'modules/reports/sales_by_products.php'],
                    'Sales by Category' => ['permission' => 'reports.sales_by_category', 'page' => 'modules/reports/sales_by_category.php'],
                    'Sales by Discounts' => ['permission' => 'reports.sales_by_discount', 'page' => 'modules/reports/sales_by_discounts.php'],
                    'Sales by Payment Types' => ['permission' => 'reports.sales_by_payment', 'page' => 'modules/reports/sales_by_payment_types.php'],
                    'Taxes' => ['permission' => 'reports.taxes', 'page' => 'modules/reports/taxes.php'],
                    'Shifts' => ['permission' => 'reports.shifts', 'page' => 'modules/reports/shifts.php']
                ]
            ],
            'Advanced Sales Reports' => [
                'category' => true,
                'permission' => 'reports.advanced_sales',
                'reports' => [
                    'Product Wise Receipt' => ['permission' => 'reports.product_wise_receipt', 'page' => 'modules/reports/product_wise_receipt.php'],
                    'Sales by Trend' => ['permission' => 'reports.sales_by_trend', 'page' => 'modules/reports/sales_by_trend.php'],
                    'Deleted Receipts' => ['permission' => 'reports.deleted_receipts', 'page' => 'modules/reports/deleted_receipts.php'],
                    'Order Type Wise Sales' => ['permission' => 'reports.order_type_wise', 'page' => 'modules/reports/order_type_wise_sales.php'],
                    'Product Wise Tax/Charge' => ['permission' => 'reports.product_wise_tax', 'page' => 'modules/reports/product_wise_tax_charge.php'],
                    'Manual Receipts' => ['permission' => 'reports.manual_receipts', 'page' => 'modules/reports/manual_receipts.php'],
                    'Sales by Orders' => ['permission' => 'reports.sales_by_order', 'page' => 'modules/reports/sales_by_orders.php'],
                    'Product Wise Orders' => ['permission' => 'reports.product_wise_order', 'page' => 'modules/reports/product_wise_orders.php'],
                    'Sales by Modifiers' => ['permission' => 'reports.sales_by_modifier', 'page' => 'modules/reports/sales_by_modifiers.php'],
                    'Product Sales by Staff' => ['permission' => 'reports.product_sales_by_staff', 'page' => 'modules/reports/product_sales_by_staff.php'],
                    'Sales by Staff' => ['permission' => 'reports.sales_by_staff', 'page' => 'modules/reports/sales_by_staff.php'],
                    'Products Consumed by Staff' => ['permission' => 'reports.products_consumed_by_staff', 'page' => 'modules/reports/products_consumed_by_staff.php'],
                    'Ecommerce Sales' => ['permission' => 'reports.ecommerce_sales', 'page' => 'modules/reports/ecommerce_sales.php']
                ]
            ],
            'Suspicious Reports' => [
                'category' => true,
                'permission' => 'reports.suspicious',
                'reports' => [
                    'Product Wise Deleted Receipts' => ['permission' => 'reports.product_wise_deleted', 'page' => 'modules/reports/product_wise_deleted_receipts.php'],
                    'Refunds & Credit Notes' => ['permission' => 'reports.refunds_credit_notes', 'page' => 'modules/reports/refunds_credit_notes.php'],
                    'Deleted Products in Open Orders' => ['permission' => 'reports.deleted_products_open_orders', 'page' => 'modules/reports/deleted_products_open_orders.php']
                ]
            ]
        ]
    ],
    'Administration' => [
        'submenu' => [
            'Branches' => [
                'link' => 'modules/branches/index.php',
                'permission' => 'branches.view',
                'page_check' => 'modules/branches/index.php'
            ],
            'Users' => [
                'link' => 'modules/users/index.php',
                'permission' => 'users.view',
                'page_check' => 'modules/users/index.php'
            ],
            'Roles & Permissions' => [
                'link' => 'modules/roles/index.php',
                'permission' => 'roles.view',
                'page_check' => 'modules/roles/index.php'
            ],
            'Currencies' => [
                'link' => 'modules/currencies/index.php',
                'permission' => 'currencies.view',
                'page_check' => 'modules/currencies/index.php'
            ],
            'Settings' => [
                'link' => 'modules/settings/index.php',
                'permission' => 'settings.view',
                'page_check' => 'modules/settings/index.php'
            ],
            'Fiscalization Status' => [
                'link' => 'check_fiscalization_status.php',
                'permission' => 'fiscalization.view_status',
                'page_check' => 'check_fiscalization_status.php'
            ],
            'All Fiscalizations' => [
                'link' => 'view_all_fiscalizations.php',
                'permission' => 'fiscalization.view_all',
                'page_check' => 'view_all_fiscalizations.php'
            ]
        ]
    ]
];

// Check existing permissions
$existingPerms = [];
foreach ($permissions as $perm) {
    $existingPerms[$perm['permission_key']] = $perm;
}

echo "SIDEBAR ELEMENT | REQUIRED PERMISSION | EXISTS | PAGE FILE\n";
echo str_repeat("-", 100) . "\n";

function checkSidebarItem($name, $item, $indent = '') {
    global $existingPerms;
    
    if (isset($item['permission'])) {
        $exists = isset($existingPerms[$item['permission']]) ? 'YES' : 'NO';
        $page = $item['page_check'] ?? $item['link'] ?? 'N/A';
        echo sprintf("%s%-40s | %-30s | %-6s | %s\n", $indent, $name, $item['permission'], $exists, $page);
    }
    
    if (isset($item['submenu'])) {
        foreach ($item['submenu'] as $subName => $subItem) {
            checkSidebarItem($subName, $subItem, $indent . '  ');
        }
    }
    
    if (isset($item['reports'])) {
        foreach ($item['reports'] as $reportName => $reportData) {
            $exists = isset($existingPerms[$reportData['permission']]) ? 'YES' : 'NO';
            echo sprintf("%s%-40s | %-30s | %-6s | %s\n", $indent . '  ', $reportName, $reportData['permission'], $exists, $reportData['page']);
        }
    }
}

foreach ($sidebarStructure as $mainName => $mainItem) {
    checkSidebarItem($mainName, $mainItem);
}

echo "\n\n=== MISSING PERMISSIONS ===\n\n";
$missing = [];
foreach ($sidebarStructure as $mainName => $mainItem) {
    if (isset($mainItem['permission']) && !isset($existingPerms[$mainItem['permission']])) {
        $missing[] = $mainItem['permission'];
    }
    if (isset($mainItem['submenu'])) {
        foreach ($mainItem['submenu'] as $subName => $subItem) {
            if (isset($subItem['permission']) && !isset($existingPerms[$subItem['permission']])) {
                $missing[] = $subItem['permission'];
            }
            if (isset($subItem['reports'])) {
                foreach ($subItem['reports'] as $reportData) {
                    if (!isset($existingPerms[$reportData['permission']])) {
                        $missing[] = $reportData['permission'];
                    }
                }
            }
        }
    }
}

if (empty($missing)) {
    echo "No missing permissions found!\n";
} else {
    foreach (array_unique($missing) as $perm) {
        echo "- $perm\n";
    }
}


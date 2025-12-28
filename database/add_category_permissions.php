<?php
/**
 * Add Report Category Permissions
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

$permissions = [
    [
        'permission_key' => 'reports.general',
        'permission_name' => 'View All General Reports',
        'module' => 'Reports',
        'description' => 'View all general reports (Sales Summary, Receipts, Refunds, Sales by Products/Category/Discounts/Payment Types, Taxes, Shifts)'
    ],
    [
        'permission_key' => 'reports.advanced_sales',
        'permission_name' => 'View All Advanced Sales Reports',
        'module' => 'Reports',
        'description' => 'View all advanced sales reports (Product Wise Receipt, Sales by Trend, Deleted Receipts, Order Type Wise, Product Wise Tax/Charge, Manual Receipts, Sales by Orders, Product Wise Orders, Sales by Modifiers, Product Sales by Staff, Sales by Staff, Products Consumed by Staff, Ecommerce Sales)'
    ],
    [
        'permission_key' => 'reports.suspicious',
        'permission_name' => 'View All Suspicious Reports',
        'module' => 'Reports',
        'description' => 'View all suspicious reports (Product Wise Deleted Receipts, Refunds & Credit Notes, Deleted Products in Open Orders)'
    ]
];

$added = 0;
$skipped = 0;

foreach ($permissions as $perm) {
    // Check if permission already exists
    $existing = $primaryDb->getRow(
        "SELECT id FROM permissions WHERE permission_key = :key",
        [':key' => $perm['permission_key']]
    );
    
    if ($existing) {
        echo "Skipping {$perm['permission_key']} - already exists\n";
        $skipped++;
    } else {
        $primaryDb->insert('permissions', $perm);
        echo "Added {$perm['permission_key']} - {$perm['permission_name']}\n";
        $added++;
    }
}

echo "\n=== Summary ===\n";
echo "Added: $added\n";
echo "Skipped: $skipped\n";
echo "Total: " . ($added + $skipped) . "\n";


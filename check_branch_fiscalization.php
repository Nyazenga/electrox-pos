<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

// Find HEAD OFFICE branch
$branch = $primaryDb->getRow("SELECT id, branch_name, fiscalization_enabled FROM branches WHERE branch_name LIKE '%HEAD%' OR branch_name LIKE '%OFFICE%' OR id = 1");

if (!$branch) {
    die("HEAD OFFICE branch not found\n");
}

echo "=== HEAD OFFICE Branch Status ===\n\n";
echo "Branch ID: {$branch['id']}\n";
echo "Branch Name: {$branch['branch_name']}\n";
echo "Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'YES' : 'NO') . "\n\n";

// Check device
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
    [':branch_id' => $branch['id']]
);

if ($device) {
    echo "Device Found:\n";
    echo "  Device ID: {$device['device_id']}\n";
    echo "  Serial: {$device['device_serial_no']}\n";
    echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
    echo "  Has Certificate: " . (!empty($device['certificate_pem']) ? 'Yes' : 'No') . "\n";
} else {
    echo "NO DEVICE FOUND FOR THIS BRANCH!\n";
}

// Check config
$config = $primaryDb->getRow(
    "SELECT * FROM fiscal_config WHERE branch_id = :branch_id",
    [':branch_id' => $branch['id']]
);

if ($config) {
    echo "\nFiscal Config Found:\n";
    echo "  Device ID: {$config['device_id']}\n";
} else {
    echo "\nNO FISCAL CONFIG FOUND!\n";
}

// Check recent sales
$db = Database::getInstance();
$sales = $db->getRows("SELECT id, branch_id, total, fiscalized, created_at FROM sales ORDER BY id DESC LIMIT 5");

echo "\n=== Recent Sales ===\n";
foreach ($sales as $sale) {
    echo "Sale ID: {$sale['id']}, Branch: {$sale['branch_id']}, Total: {$sale['total']}, Fiscalized: {$sale['fiscalized']}, Date: {$sale['created_at']}\n";
}

// Check fiscal receipts
$fiscalReceipts = $primaryDb->getRows("SELECT * FROM fiscal_receipts ORDER BY id DESC LIMIT 5");
echo "\n=== Fiscal Receipts ===\n";
if (empty($fiscalReceipts)) {
    echo "NO FISCAL RECEIPTS FOUND!\n";
} else {
    foreach ($fiscalReceipts as $fr) {
        echo "Fiscal Receipt ID: {$fr['id']}, Sale ID: {$fr['sale_id']}, Receipt Global No: {$fr['receipt_global_no']}\n";
    }
}


<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

echo "=== Device and Activation Key Configuration ===\n\n";

$devices = $primaryDb->getRows("SELECT * FROM fiscal_devices ORDER BY branch_id");

foreach ($devices as $device) {
    $branch = $primaryDb->getRow("SELECT branch_name FROM branches WHERE id = :id", [':id' => $device['branch_id']]);
    echo "Branch: " . ($branch['branch_name'] ?? 'Unknown') . "\n";
    echo "  Device ID: {$device['device_id']}\n";
    echo "  Serial No: {$device['device_serial_no']}\n";
    echo "  Activation Key: {$device['activation_key']}\n";
    echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
    echo "\n";
}

echo "\n=== Correct Combinations (from original setup) ===\n";
echo "Device 30199 (Head Office):\n";
echo "  Activation Key: 00544726\n";
echo "  Serial No: electrox-1\n\n";
echo "Device 30200 (Hillside):\n";
echo "  Activation Key: 00294543\n";
echo "  Serial No: electrox-2\n\n";

echo "⚠️  If you're trying to verify Device 30200, use activation key 00294543\n";
echo "⚠️  If you're trying to verify Device 30199, use activation key 00544726\n";


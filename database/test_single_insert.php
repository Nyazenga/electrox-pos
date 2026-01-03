<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

$sql = "INSERT INTO `products` (`id`, `product_code`, `product_name`, `category_id`, `brand`, `model`, `color`, `storage`, `sim_configuration`, `serial_number`, `imei`, `battery_health`, `sku`, `barcode`, `description`, `specifications`, `cost_price`, `selling_price`, `minimum_price`, `profit_margin`, `reorder_level`, `reorder_quantity`, `warranty_months`, `warranty_terms`, `condition`, `status`, `trade_in_eligible`, `is_trade_in`, `tags`, `images`, `branch_id`, `tax_id`, `quantity_in_stock`, `created_at`, `updated_at`, `created_by`, `source`, `updated_by`, `expiry_date`, `weight`, `unit_of_measure`, `manufacturer`, `batch_number`) VALUES (1, 'PROD00001', NULL, 1, 'Apple', 'iPhone 15 Pro Max', 'Natural Titanium', '256GB', NULL, NULL, NULL, NULL, NULL, '8667540720717', NULL, NULL, 1200.00, 1500.00, 0.00, 0.00, 5, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 0, '2025-11-12 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL)";

echo "Testing single INSERT...\n";
$result = $db->query($sql);
if ($result === false) {
    echo "❌ Error: " . $db->getLastError() . "\n";
} else {
    echo "✅ Success!\n";
}


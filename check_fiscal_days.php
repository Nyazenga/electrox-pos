<?php
define('APP_PATH', __DIR__);
require_once 'config.php';
require_once 'includes/db.php';

$db = Database::getInstance();
$days = $db->getRows(
    "SELECT * FROM fiscal_days WHERE device_id = 30200 ORDER BY fiscal_day_no DESC LIMIT 5"
);

echo "Fiscal Days for Device 30200:\n";
echo json_encode($days, JSON_PRETTY_PRINT);



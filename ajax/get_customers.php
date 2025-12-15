<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

header('Content-Type: application/json');

$db = Database::getInstance();
$search = $_GET['search'] ?? '';

$sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name, email, phone FROM customers WHERE status = 'Active'";
$params = [];

if ($search) {
    $sql .= " AND (first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR phone LIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY first_name, last_name LIMIT 50";

$customers = $db->getRows($sql, $params);

echo json_encode($customers);


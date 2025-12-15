<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

if (!isset($_SESSION['admin_user_id'])) {
    redirectTo('login.php');
}

$id = $_GET['id'] ?? 0;

if ($id) {
    $result = approveTenant($id);
    if ($result['success']) {
        header('Location: index.php?success=Tenant approved successfully');
    } else {
        header('Location: index.php?error=' . urlencode($result['message']));
    }
} else {
    redirectTo('index.php');
}


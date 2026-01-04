<?php
// Redirect to POS Customize page - all POS receipt settings are now configured there
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/auth.php';

$auth = Auth::getInstance();
$auth->requireLogin();

header('Location: ' . BASE_URL . 'modules/pos/customize.php');
exit;


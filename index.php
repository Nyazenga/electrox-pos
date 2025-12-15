<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/session.php';

initSession();

$auth = Auth::getInstance();

if ($auth->isLoggedIn()) {
    redirectTo('modules/dashboard/index.php');
} else {
    redirectTo('login.php');
}


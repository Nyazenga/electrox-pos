<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$auth = Auth::getInstance();
$auth->logout();

redirectTo('login.php');


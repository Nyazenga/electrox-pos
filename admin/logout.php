<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';

initSession();
session_unset();
session_destroy();

redirectTo('login.php');


<?php
// Prevent direct access
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/config.php';

function initSession() {
    if (session_status() == PHP_SESSION_NONE) {
        session_set_cookie_params(
            SESSION_LIFETIME,
            '/',
            '',
            APP_MODE === 'production',
            true
        );
        
        session_name(SESSION_NAME);
        session_start();
    }
}

function checkSessionActivity() {
    if (isset($_SESSION['last_activity'])) {
        $inactiveTime = time() - $_SESSION['last_activity'];
        
        if ($inactiveTime > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            redirectTo('login.php');
        }
        
        if ($inactiveTime > 1800) {
            session_regenerate_id(true);
        }
    }
    
    $_SESSION['last_activity'] = time();
}

function redirectTo($url) {
    if (strpos($url, 'http') !== 0) {
        $url = BASE_URL . $url;
    }
    header("Location: " . $url);
    exit;
}

function sanitizeInput($input) {
    if (is_array($input)) {
        foreach ($input as $key => $val) {
            $input[$key] = sanitizeInput($val);
        }
        return $input;
    }
    
    $input = trim($input);
    $input = strip_tags($input);
    return $input;
}

function generateCsrfToken() {
    initSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    initSession();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function logError($message, $context = []) {
    if (LOG_ENABLED && defined('ERROR_LOG_FILE')) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logMessage = "[$timestamp] [ERROR] $message$contextStr" . PHP_EOL;
        $logDir = dirname(ERROR_LOG_FILE);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents(ERROR_LOG_FILE, $logMessage, FILE_APPEND);
    }
}

function logActivity($userId, $action, $details = null) {
    require_once APP_PATH . '/includes/db.php';
    $db = Database::getInstance();
    
    $activityData = [
        'user_id' => $userId,
        'action' => $action,
        'details' => $details ? json_encode($details) : null,
        'ip_address' => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $db->insert('activity_logs', $activityData);
    } catch (Exception $e) {
        logError("Failed to log activity: " . $e->getMessage());
    }
}

function logAudit($userId, $action, $entityType, $entityId, $oldValues = null, $newValues = null) {
    require_once APP_PATH . '/includes/db.php';
    $db = Database::getInstance();
    
    $auditData = [
        'user_id' => $userId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'old_values' => $oldValues ? json_encode($oldValues) : null,
        'new_values' => $newValues ? json_encode($newValues) : null,
        'ip_address' => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $db->insert('audit_logs', $auditData);
    } catch (Exception $e) {
        logError("Failed to log audit: " . $e->getMessage());
    }
}

function getClientIp() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function formatCurrency($amount, $currency = 'USD') {
    if ($amount === null || $amount === '') {
        return '$0.00';
    }
    $amount = floatval($amount);
    $symbols = [
        'USD' => '$',
        'ZWG' => 'ZW$',
        'RTGS' => 'RTGS$'
    ];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

function formatDate($date, $format = 'Y-m-d') {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'Y-m-d H:i:s') {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '';
    }
    return date($format, strtotime($datetime));
}

function escapeHtml($string) {
    if ($string === null || $string === '') {
        return '';
    }
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

function generateRandomString($length = 10) {
    return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
}

function generateProductCode() {
    return 'PROD-' . strtoupper(generateRandomString(5));
}

function generateInvoiceNumber() {
    return 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateGRNNumber() {
    return 'GRN-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateTransferNumber() {
    return 'TRF-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateTradeInNumber() {
    return 'TI-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateCustomerCode() {
    return 'CUST-' . strtoupper(generateRandomString(5));
}

function generateBranchCode() {
    return 'BRN-' . strtoupper(generateRandomString(3));
}


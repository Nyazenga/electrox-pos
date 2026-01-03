<?php
// Define application path if not already defined
if (!defined('APP_PATH')) {
    define('APP_PATH', __DIR__);
}

// Prevent direct access
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

// Environment configuration - Auto-detect localhost
$isLocalhost = false;
if (isset($_SERVER['HTTP_HOST'])) {
    $host = $_SERVER['HTTP_HOST'];
    $isLocalhost = (
        $host === 'localhost' ||
        $host === '127.0.0.1' ||
        strpos($host, 'localhost:') === 0 ||
        strpos($host, '127.0.0.1:') === 0
    );
}
if (!$isLocalhost && isset($_SERVER['SERVER_NAME'])) {
    $serverName = $_SERVER['SERVER_NAME'];
    $isLocalhost = ($serverName === 'localhost' || $serverName === '127.0.0.1');
}
define('APP_MODE', $isLocalhost ? 'development' : 'production');

// Error handling based on environment
if (APP_MODE === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/logs/error.log');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Base URLs - Auto-detect from current request
if (APP_MODE === 'development') {
    define('BASE_URL', 'http://localhost/electrox-pos/');
    define('ASSETS_URL', BASE_URL . 'assets/');
} else {
    // Auto-detect base URL from current request
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'nedcom.co.zw';
    $baseUrl = $protocol . '://' . $host . '/';
    define('BASE_URL', $baseUrl);
    define('ASSETS_URL', BASE_URL . 'assets/');
}

// Database configuration
// ⚠️ SECURITY WARNING: Change these credentials in production!
// For better security, use config.local.php (see README)
define('DB_HOST', getenv('ELECTROX_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('ELECTROX_DB_USER') ?: (APP_MODE === 'production' ? 'grcadmin' : 'root'));
define('DB_PASS', getenv('ELECTROX_DB_PASS') ?: (APP_MODE === 'production' ? 'GRCAdmin123/' : ''));
define('DB_CHARSET', 'utf8mb4');

// SaaS Mode Configuration
define('SAAS_MODE', true);
define('PRIMARY_DB_NAME', 'electrox_primary');
define('BASE_DB_NAME', 'electrox_base');

// Function to get current tenant database name
function getCurrentTenantDbName() {
    // Ensure session is started
    if (session_status() == PHP_SESSION_NONE) {
        if (function_exists('initSession')) {
            initSession();
        } else {
            session_start();
        }
    }
    return $_SESSION['tenant_name'] ?? null;
}

// Function to get tenant database connection
function getTenantDbConnection($tenantName = null) {
    if (!$tenantName) {
        $tenantName = getCurrentTenantDbName();
    }
    if (!$tenantName) {
        throw new Exception('No tenant selected');
    }
    
    $tenantDbName = 'electrox_' . $tenantName;
    $dsn = "mysql:host=" . DB_HOST . ";dbname=$tenantDbName;charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}

// Default time zone
date_default_timezone_set('Africa/Harare');

// Session configuration
define('SESSION_NAME', 'ELECTROX_SESSION');
define('SESSION_LIFETIME', 0); // 0 = No timeout - sessions persist until explicit logout

// Encryption settings
define('HASH_COST', 10);

// Email settings - Gmail SMTP
define('SMTP_HOST', getenv('ELECTROX_SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', getenv('ELECTROX_SMTP_PORT') ?: 587);
define('SMTP_USERNAME', getenv('ELECTROX_SMTP_USER') ?: 'nyazengamd@gmail.com');
define('SMTP_PASSWORD', getenv('ELECTROX_SMTP_PASS') ?: 'vuamghodglqyvuxp');
define('SMTP_FROM_EMAIL', getenv('ELECTROX_FROM_EMAIL') ?: 'nyazengamd@gmail.com');
define('SMTP_FROM_NAME', getenv('ELECTROX_FROM_NAME') ?: 'ELECTROX-POS');

// AI settings - Gemini
define('AI_ENABLED', true);
define('GEMINI_API_KEY', 'AIzaSyAWhjH-3TQfFagB5xilDfpnCThS3h6ZVsE');

// File upload settings
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_UPLOAD_TYPES', ['pdf', 'docx', 'xlsx', 'pptx', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp']);
define('UPLOAD_PATH', __DIR__ . '/assets/uploads/');

// System info
define('SYSTEM_NAME', 'ELECTROX-POS');
define('SYSTEM_VERSION', '1.0.0');

// Logging configuration
define('LOG_ENABLED', true);
define('ERROR_LOG_FILE', __DIR__ . '/logs/error.log');
define('ACTIVITY_LOG_FILE', __DIR__ . '/logs/activity.log');
define('AUDIT_LOG_FILE', __DIR__ . '/logs/audit.log');

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0755, true);
}

// Load local configuration if exists
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}


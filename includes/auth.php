<?php
// Prevent direct access
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/session.php';

class Auth {
    private static $instance = null;
    
    private function __construct() {}
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function login($email, $password, $tenantName = null) {
        initSession();
        
        if ($tenantName) {
            $_SESSION['tenant_name'] = $tenantName;
        }
        
        $tenantName = getCurrentTenantDbName();
        if (!$tenantName) {
            return ['success' => false, 'message' => 'No tenant selected'];
        }
        
        try {
            $db = Database::getInstance(true, $tenantName);
            
            $user = $db->getRow(
                "SELECT * FROM users WHERE email = :email AND status = 'active'",
                [':email' => $email]
            );
            
            if (!$user) {
                $this->logFailedAttempt($email);
                return ['success' => false, 'message' => 'Invalid email or password'];
            }
            
            if (!password_verify($password, $user['password'])) {
                $this->logFailedAttempt($email, $user['id']);
                $this->checkAccountLockout($user['id']);
                return ['success' => false, 'message' => 'Invalid email or password'];
            }
            
            if ($user['login_attempts'] >= 5) {
                return ['success' => false, 'message' => 'Account locked due to too many failed login attempts. Please contact administrator.'];
            }
            
            $role = $db->getRow("SELECT * FROM roles WHERE id = :id", [':id' => $user['role_id']]);
            
            $branchId = $user['branch_id'] ?? null;
            $branch = null;
            if ($branchId) {
                $branch = $db->getRow("SELECT * FROM branches WHERE id = :id", [':id' => $branchId]);
            }
            
            // Load permissions from role_permissions table
            $permissions = [];
            if ($user['role_id']) {
                $permissionRows = $db->getRows(
                    "SELECT p.permission_key 
                     FROM permissions p 
                     INNER JOIN role_permissions rp ON p.id = rp.permission_id 
                     WHERE rp.role_id = :role_id",
                    [':role_id' => $user['role_id']]
                );
                if ($permissionRows !== false && is_array($permissionRows)) {
                    $permissions = array_column($permissionRows, 'permission_key');
                    // Filter out any null/empty values and re-index
                    $permissions = array_values(array_filter($permissions, function($p) { return !empty($p); }));
                }
            }
            
            // Log permissions for debugging (only in development)
            if (APP_MODE === 'development' && !empty($permissions)) {
                error_log("User {$user['email']} (Role ID: {$user['role_id']}) loaded " . count($permissions) . " permissions: " . implode(', ', $permissions));
            }
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $role['name'] ?? 'User';
            $_SESSION['branch_id'] = $branchId;
            $_SESSION['branch_name'] = $branch['branch_name'] ?? null;
            $_SESSION['permissions'] = $permissions;
            $_SESSION['last_activity'] = time();
            
            $db->update('users', [
                'last_login' => date('Y-m-d H:i:s'),
                'login_attempts' => 0
            ], ['id' => $user['id']]);
            
            logActivity($user['id'], 'login', ['ip' => getClientIp()]);
            
            return ['success' => true, 'message' => 'Login successful', 'user' => $user];
            
        } catch (Exception $e) {
            logError("Login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred. Please try again.'];
        }
    }
    
    public function logout() {
        initSession();
        if (isset($_SESSION['user_id'])) {
            logActivity($_SESSION['user_id'], 'logout');
        }
        session_unset();
        session_destroy();
    }
    
    public function isLoggedIn() {
        initSession();
        checkSessionActivity();
        return isset($_SESSION['user_id']);
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            redirectTo('login.php');
        }
    }
    
    public function hasPermission($permission) {
        initSession();
        if (!isset($_SESSION['permissions'])) {
            // Try to reload permissions from database if session doesn't have them
            $this->reloadPermissions();
        }
        
        if (!isset($_SESSION['permissions'])) {
            return false;
        }
        
        $permissions = $_SESSION['permissions'];
        
        // Check for exact permission match
        if (in_array($permission, $permissions)) {
            return true;
        }
        
        // Check for wildcard permission
        if (in_array('*', $permissions)) {
            return true;
        }
        
        // Check for System Administrator role (should have all permissions)
        if (isset($_SESSION['role_name']) && 
            (strtolower($_SESSION['role_name']) === 'system administrator' || 
             strtolower($_SESSION['role_name']) === 'administrator')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if user has any permission for a module (e.g., 'pos', 'products', 'sales')
     * Returns true if user has ANY permission that starts with the module prefix
     */
    public function hasAnyModulePermission($modulePrefix) {
        initSession();
        if (!isset($_SESSION['permissions'])) {
            $this->reloadPermissions();
        }
        
        if (!isset($_SESSION['permissions'])) {
            return false;
        }
        
        $permissions = $_SESSION['permissions'];
        $prefix = $modulePrefix . '.';
        
        // Check for System Administrator role (should have all permissions)
        if (isset($_SESSION['role_name']) && 
            (strtolower($_SESSION['role_name']) === 'system administrator' || 
             strtolower($_SESSION['role_name']) === 'administrator')) {
            return true;
        }
        
        // Check if any permission starts with the module prefix
        foreach ($permissions as $perm) {
            if ($perm === '*' || strpos($perm, $prefix) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Reload permissions from database
     */
    public function reloadPermissions() {
        if (!isset($_SESSION['role_id']) || !$_SESSION['role_id']) {
            return;
        }
        
        try {
            $db = Database::getInstance();
            $permissionRows = $db->getRows(
                "SELECT p.permission_key 
                 FROM permissions p 
                 INNER JOIN role_permissions rp ON p.id = rp.permission_id 
                 WHERE rp.role_id = :role_id",
                [':role_id' => $_SESSION['role_id']]
            );
            
            if ($permissionRows !== false && is_array($permissionRows)) {
                $permissions = array_column($permissionRows, 'permission_key');
                // Filter out any null/empty values
                $permissions = array_filter($permissions, function($p) { return !empty($p); });
                $_SESSION['permissions'] = array_values($permissions); // Re-index array
            } else {
                $_SESSION['permissions'] = [];
            }
            
            // Log for debugging (only in development)
            if (APP_MODE === 'development' && !empty($_SESSION['permissions'])) {
                error_log("Reloaded " . count($_SESSION['permissions']) . " permissions for role ID: {$_SESSION['role_id']}");
            }
        } catch (Exception $e) {
            logError("Failed to reload permissions: " . $e->getMessage());
            $_SESSION['permissions'] = [];
        }
    }
    
    public function requirePermission($permission) {
        if (!$this->hasPermission($permission)) {
            header('HTTP/1.0 403 Forbidden');
            die('Access denied. You do not have permission to access this resource.');
        }
    }
    
    public function getCurrentUser() {
        initSession();
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        try {
            $db = Database::getInstance();
            return $db->getRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function logFailedAttempt($email, $userId = null) {
        try {
            $db = Database::getInstance();
            if ($userId) {
                $user = $db->getRow("SELECT login_attempts FROM users WHERE id = :id", [':id' => $userId]);
                $attempts = ($user['login_attempts'] ?? 0) + 1;
                $db->update('users', ['login_attempts' => $attempts], ['id' => $userId]);
            }
        } catch (Exception $e) {
            logError("Failed to log failed attempt: " . $e->getMessage());
        }
    }
    
    private function checkAccountLockout($userId) {
        try {
            $db = Database::getInstance();
            $user = $db->getRow("SELECT login_attempts FROM users WHERE id = :id", [':id' => $userId]);
            if (($user['login_attempts'] ?? 0) >= 5) {
                $db->update('users', ['status' => 'locked'], ['id' => $userId]);
            }
        } catch (Exception $e) {
            logError("Failed to check account lockout: " . $e->getMessage());
        }
    }
}


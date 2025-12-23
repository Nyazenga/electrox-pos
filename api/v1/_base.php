<?php
/**
 * Base API Helper Functions
 */

// Prevent direct access
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

// Initialize session to ensure tenant is available
initSession();

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Get authenticated user
 */
function getApiUser() {
    initSession();
    $auth = Auth::getInstance();
    
    // Check for Bearer token in Authorization header
    $headers = getallheaders();
    $token = null;
    
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    // If token provided, validate it and set session
    if ($token) {
        try {
            $decoded = json_decode(base64_decode($token), true);
            if ($decoded && isset($decoded['user_id']) && isset($decoded['expires'])) {
                // Check if token is expired
                if ($decoded['expires'] < time()) {
                    return null;
                }
                
                // Get user from database and set session
                $db = Database::getInstance();
                $user = $db->getRow("SELECT * FROM users WHERE id = :id AND status = 'active'", [':id' => $decoded['user_id']]);
                
                if ($user) {
                    // Set session for compatibility with existing code
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['branch_id'] = $user['branch_id'] ?? null;
                    
                    // Get role and permissions
                    $role = $db->getRow("SELECT * FROM roles WHERE id = :id", [':id' => $user['role_id']]);
                    if ($role) {
                        $_SESSION['role_name'] = $role['name'];
                        $_SESSION['permissions'] = json_decode($role['permissions'] ?? '[]', true);
                    }
                    
                    return [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'role_id' => $user['role_id'],
                        'branch_id' => $user['branch_id'] ?? null
                    ];
                }
            }
        } catch (Exception $e) {
            // Token invalid, fall through to session check
        }
    }
    
    // Fallback to session-based auth (for web interface compatibility)
    if ($auth->isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'email' => $_SESSION['email'] ?? null,
            'role_id' => $_SESSION['role_id'] ?? null,
            'branch_id' => $_SESSION['branch_id'] ?? null
        ];
    }
    
    return null;
}

/**
 * Require authentication
 */
function requireAuth() {
    $user = getApiUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required'
        ]);
        exit;
    }
    return $user;
}

/**
 * Require permission
 */
function requirePermission($permission) {
    $user = requireAuth();
    $auth = Auth::getInstance();
    
    try {
        $auth->requirePermission($permission);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Permission denied: ' . $e->getMessage()
        ]);
        exit;
    }
    
    return $user;
}

/**
 * Get request body as JSON
 */
function getRequestBody() {
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 */
function sendError($message, $statusCode = 400, $errors = []) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'errors' => $errors
    ], JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send success response
 */
function sendSuccess($data = [], $message = 'Success') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], JSON_PRETTY_PRINT);
    exit;
}

/**
 * Get pagination parameters
 */
function getPaginationParams() {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 25;
    $offset = ($page - 1) * $limit;
    
    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Format paginated response
 */
function formatPaginatedResponse($data, $total, $page, $limit) {
    return [
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ];
}

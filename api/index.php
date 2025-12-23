<?php
/**
 * @OA\Info(
 *     title="ELECTROX-POS API",
 *     version="1.0.0",
 *     description="Comprehensive REST API for ELECTROX-POS System - Stock Management, Invoicing & POS",
 *     @OA\Contact(
 *         email="support@electrox.co.zw"
 *     ),
 *     @OA\License(
 *         name="Proprietary",
 *         url="https://electrox.co.zw"
 *     )
 * )
 * @OA\Server(
 *     url="http://localhost/electrox-pos/api",
 *     description="Local Development Server"
 * )
 * @OA\Server(
 *     url="https://app.electrox-pos.com/api",
 *     description="Production Server"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="API Token Authentication"
 * )
 * @OA\Tag(
 *     name="Authentication",
 *     description="User authentication and authorization"
 * )
 * @OA\Tag(
 *     name="Products",
 *     description="Product management operations"
 * )
 * @OA\Tag(
 *     name="Sales",
 *     description="Point of Sale and sales operations"
 * )
 * @OA\Tag(
 *     name="Invoices",
 *     description="Invoice management operations"
 * )
 * @OA\Tag(
 *     name="Customers",
 *     description="Customer management operations"
 * )
 * @OA\Tag(
 *     name="Inventory",
 *     description="Inventory, GRN, and stock management"
 * )
 * @OA\Tag(
 *     name="Reports",
 *     description="Sales and inventory reports"
 * )
 * @OA\Tag(
 *     name="Shifts",
 *     description="Shift management operations"
 * )
 */

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// API Router
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Handle Swagger endpoints
if (strpos($requestUri, '/swagger.json') !== false || strpos($requestUri, '/swagger.php') !== false) {
    require_once __DIR__ . '/swagger.php';
    exit;
}

if (strpos($requestUri, '/swagger-ui.php') !== false) {
    require_once __DIR__ . '/swagger-ui.php';
    exit;
}

// Remove base path and query string
$path = parse_url($requestUri, PHP_URL_PATH);
$basePath = '/electrox-pos/api';
$path = str_replace($basePath, '', $path);
$path = trim($path, '/');

// Handle /v1/ prefix
if (strpos($path, 'v1/') === 0) {
    $path = substr($path, 3); // Remove 'v1/'
}

$segments = explode('/', $path);

// Route to appropriate endpoint
if (empty($segments[0]) || $segments[0] === 'index.php' || $segments[0] === '') {
    // API Documentation/Info
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'ELECTROX-POS API v1.0.0',
        'documentation' => BASE_URL . 'api/swagger-ui.php',
        'swagger_json' => BASE_URL . 'api/swagger.json',
        'endpoints' => [
            'POST /api/v1/auth' => 'User authentication',
            'GET /api/v1/products' => 'Get all products',
            'GET /api/v1/products/{id}' => 'Get product by ID',
            'POST /api/v1/products' => 'Create product',
            'PUT /api/v1/products/{id}' => 'Update product',
            'DELETE /api/v1/products/{id}' => 'Delete product',
            'GET /api/v1/sales' => 'Get all sales',
            'GET /api/v1/sales/{id}' => 'Get sale by ID',
            'POST /api/v1/sales' => 'Create sale (POS transaction)',
            'GET /api/v1/invoices' => 'Get all invoices',
            'GET /api/v1/invoices/{id}' => 'Get invoice by ID',
            'POST /api/v1/invoices' => 'Create invoice',
            'PUT /api/v1/invoices/{id}/status' => 'Update invoice status',
            'GET /api/v1/customers' => 'Get all customers',
            'GET /api/v1/customers/{id}' => 'Get customer by ID',
            'POST /api/v1/customers' => 'Create customer',
            'PUT /api/v1/customers/{id}' => 'Update customer',
            'GET /api/v1/inventory' => 'Get inventory/stock levels',
            'GET /api/v1/inventory/grn' => 'Get GRNs',
            'POST /api/v1/inventory/grn' => 'Create GRN',
            'GET /api/v1/shifts' => 'Get shifts',
            'POST /api/v1/shifts/start' => 'Start shift',
            'POST /api/v1/shifts/{id}/end' => 'End shift',
            'GET /api/v1/reports/sales-summary' => 'Get sales summary report'
        ]
    ]);
    exit;
} else {
    $endpoint = $segments[0];
    $file = __DIR__ . '/v1/' . $endpoint . '.php';
    
    if (file_exists($file)) {
        // Set REQUEST_URI for endpoint to parse
        $_SERVER['REQUEST_URI'] = $requestUri;
        require_once $file;
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Endpoint not found',
            'available_endpoints' => [
                'auth',
                'products',
                'sales',
                'invoices',
                'customers',
                'inventory',
                'reports',
                'shifts',
                'suppliers',
                'tradeins',
                'branches',
                'users',
                'categories',
                'refunds',
                'roles',
                'currencies',
                'transfers'
            ]
        ]);
    }
}


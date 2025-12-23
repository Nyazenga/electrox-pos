<?php
/**
 * @OA\Get(
 *     path="/customers",
 *     tags={"Customers"},
 *     summary="Get all customers",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=200, description="List of customers")
 * )
 * @OA\Post(
 *     path="/customers",
 *     tags={"Customers"},
 *     summary="Create new customer",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=201, description="Customer created")
 * )
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();
$pagination = getPaginationParams();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$id = null;
if (isset($pathParts[count($pathParts) - 1]) && is_numeric($pathParts[count($pathParts) - 1])) {
    $id = intval($pathParts[count($pathParts) - 1]);
}

if ($method === 'GET') {
    requirePermission('customers.view');
    
    if ($id) {
        $customer = $db->getRow("SELECT * FROM customers WHERE id = :id", [':id' => $id]);
        if (!$customer) {
            sendError('Customer not found', 404);
        }
        sendSuccess($customer);
    } else {
        $search = $_GET['search'] ?? '';
        $whereConditions = ["1=1"];
        $params = [];
        
        if ($search) {
            $whereConditions[] = "(first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR phone LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM customers WHERE $whereClause", $params);
        $totalCount = $total ? intval($total['count']) : 0;
        
        $customers = $db->getRows("SELECT * FROM customers WHERE $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
                                  array_merge($params, [
                                      ':limit' => $pagination['limit'],
                                      ':offset' => $pagination['offset']
                                  ]));
        
        if ($customers === false) {
            $customers = [];
        }
        
        $response = formatPaginatedResponse($customers, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('customers.create');
    
    $input = getRequestBody();
    
    $customerData = [
        'first_name' => $input['first_name'] ?? '',
        'last_name' => $input['last_name'] ?? '',
        'email' => $input['email'] ?? null,
        'phone' => $input['phone'] ?? null,
        'address' => $input['address'] ?? null,
        'city' => $input['city'] ?? null,
        'country' => $input['country'] ?? null,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $customerId = $db->insert('customers', $customerData);
    
    if (!$customerId) {
        sendError('Failed to create customer: ' . $db->getLastError(), 500);
    }
    
    $customer = $db->getRow("SELECT * FROM customers WHERE id = :id", [':id' => $customerId]);
    sendSuccess($customer, 'Customer created successfully', 201);
    
} elseif ($method === 'PUT') {
    requirePermission('customers.edit');
    
    if (!$id) {
        sendError('Customer ID is required', 400);
    }
    
    $input = getRequestBody();
    
    $updateData = [];
    $allowedFields = ['first_name', 'last_name', 'email', 'phone', 'address', 'city', 'country'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateData[$field] = $input[$field];
        }
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $result = $db->update('customers', $updateData, ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to update customer', 500);
    }
    
    $customer = $db->getRow("SELECT * FROM customers WHERE id = :id", [':id' => $id]);
    sendSuccess($customer, 'Customer updated successfully');
    
} else {
    sendError('Method not allowed', 405);
}



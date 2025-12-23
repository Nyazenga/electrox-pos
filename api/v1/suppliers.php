<?php
/**
 * Suppliers API Endpoint
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();
$pagination = getPaginationParams();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$id = null;
foreach ($pathParts as $part) {
    if (is_numeric($part)) {
        $id = intval($part);
        break;
    }
}

if ($method === 'GET') {
    requirePermission('suppliers.view');
    
    if ($id) {
        $supplier = $db->getRow("SELECT * FROM suppliers WHERE id = :id", [':id' => $id]);
        if (!$supplier) {
            sendError('Supplier not found', 404);
        }
        sendSuccess($supplier);
    } else {
        $search = $_GET['search'] ?? '';
        $whereConditions = ["1=1"];
        $params = [];
        
        if ($search) {
            $whereConditions[] = "(name LIKE :search OR supplier_code LIKE :search OR email LIKE :search OR phone LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM suppliers WHERE $whereClause", $params);
        $totalCount = $total ? intval($total['count']) : 0;
        
        $suppliers = $db->getRows("SELECT * FROM suppliers WHERE $whereClause ORDER BY name LIMIT :limit OFFSET :offset",
                                  array_merge($params, [
                                      ':limit' => $pagination['limit'],
                                      ':offset' => $pagination['offset']
                                  ]));
        
        if ($suppliers === false) {
            $suppliers = [];
        }
        
        $response = formatPaginatedResponse($suppliers, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('suppliers.create');
    
    $input = getRequestBody();
    
    $supplierData = [
        'supplier_code' => $input['supplier_code'] ?? 'SUP-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8)),
        'name' => $input['name'] ?? '',
        'contact_person' => $input['contact_person'] ?? null,
        'email' => $input['email'] ?? null,
        'phone' => $input['phone'] ?? null,
        'address' => $input['address'] ?? null,
        'city' => $input['city'] ?? null,
        'country' => $input['country'] ?? null,
        'status' => $input['status'] ?? 'Active',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $supplierId = $db->insert('suppliers', $supplierData);
    
    if (!$supplierId) {
        sendError('Failed to create supplier: ' . $db->getLastError(), 500);
    }
    
    $supplier = $db->getRow("SELECT * FROM suppliers WHERE id = :id", [':id' => $supplierId]);
    sendSuccess($supplier, 'Supplier created successfully', 201);
    
} elseif ($method === 'PUT') {
    requirePermission('suppliers.edit');
    
    if (!$id) {
        sendError('Supplier ID is required', 400);
    }
    
    $input = getRequestBody();
    
    $updateData = [];
    $allowedFields = ['name', 'contact_person', 'email', 'phone', 'address', 'city', 'country', 'status'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateData[$field] = $input[$field];
        }
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $result = $db->update('suppliers', $updateData, ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to update supplier', 500);
    }
    
    $supplier = $db->getRow("SELECT * FROM suppliers WHERE id = :id", [':id' => $id]);
    sendSuccess($supplier, 'Supplier updated successfully');
    
} elseif ($method === 'DELETE') {
    requirePermission('suppliers.delete');
    
    if (!$id) {
        sendError('Supplier ID is required', 400);
    }
    
    $supplier = $db->getRow("SELECT * FROM suppliers WHERE id = :id", [':id' => $id]);
    if (!$supplier) {
        sendError('Supplier not found', 404);
    }
    
    $result = $db->update('suppliers', ['status' => 'Inactive'], ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to delete supplier', 500);
    }
    
    sendSuccess([], 'Supplier deleted successfully');
} else {
    sendError('Method not allowed', 405);
}


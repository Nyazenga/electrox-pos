<?php
/**
 * @OA\Get(
 *     path="/products",
 *     tags={"Products"},
 *     summary="Get all products",
 *     security={{"bearerAuth": {}}},
 *     @OA\Parameter(name="page", in="query", description="Page number", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="limit", in="query", description="Items per page", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="category_id", in="query", description="Filter by category", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="status", in="query", description="Filter by status", @OA\Schema(type="string", enum={"Active", "Inactive"})),
 *     @OA\Parameter(name="search", in="query", description="Search term", @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="List of products")
 * )
 * @OA\Get(
 *     path="/products/{id}",
 *     tags={"Products"},
 *     summary="Get product by ID",
 *     security={{"bearerAuth": {}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Product details")
 * )
 * @OA\Post(
 *     path="/products",
 *     tags={"Products"},
 *     summary="Create new product",
 *     security={{"bearerAuth": {}}},
 *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/Product")),
 *     @OA\Response(response=201, description="Product created")
 * )
 * @OA\Put(
 *     path="/products/{id}",
 *     tags={"Products"},
 *     summary="Update product",
 *     security={{"bearerAuth": {}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/Product")),
 *     @OA\Response(response=200, description="Product updated")
 * )
 * @OA\Delete(
 *     path="/products/{id}",
 *     tags={"Products"},
 *     summary="Delete product",
 *     security={{"bearerAuth": {}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Product deleted")
 * )
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();
$pagination = getPaginationParams();

// Get ID from URL if present
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$id = null;
if (isset($pathParts[count($pathParts) - 1]) && is_numeric($pathParts[count($pathParts) - 1])) {
    $id = intval($pathParts[count($pathParts) - 1]);
}

if ($method === 'GET') {
    if ($id) {
        // Get single product
        requirePermission('products.view');
        
        $product = $db->getRow("SELECT p.*, pc.name as category_name, b.branch_name 
                                FROM products p 
                                LEFT JOIN product_categories pc ON p.category_id = pc.id 
                                LEFT JOIN branches b ON p.branch_id = b.id 
                                WHERE p.id = :id", [':id' => $id]);
        
        if (!$product) {
            sendError('Product not found', 404);
        }
        
        // Get product images
        if (!empty($product['images'])) {
            $product['images'] = json_decode($product['images'], true);
        }
        
        sendSuccess($product);
    } else {
        // Get all products
        requirePermission('products.view');
        
        $whereConditions = ["1=1"];
        $params = [];
        
        if (isset($_GET['category_id']) && $_GET['category_id'] !== 'all') {
            $whereConditions[] = "p.category_id = :category_id";
            $params[':category_id'] = intval($_GET['category_id']);
        }
        
        if (isset($_GET['status']) && $_GET['status'] !== 'all') {
            $whereConditions[] = "p.status = :status";
            $params[':status'] = $_GET['status'];
        }
        
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $search = "%" . $_GET['search'] . "%";
            $whereConditions[] = "(p.brand LIKE :search OR p.model LIKE :search OR p.product_name LIKE :search OR p.product_code LIKE :search)";
            $params[':search'] = $search;
        }
        
        // Branch filter
        if ($user['branch_id']) {
            $whereConditions[] = "p.branch_id = :branch_id";
            $params[':branch_id'] = $user['branch_id'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Get total count
        $total = $db->getRow("SELECT COUNT(*) as count FROM products p WHERE $whereClause", $params);
        $totalCount = $total ? intval($total['count']) : 0;
        
        // Get products
        $products = $db->getRows("SELECT p.*, pc.name as category_name, b.branch_name 
                                  FROM products p 
                                  LEFT JOIN product_categories pc ON p.category_id = pc.id 
                                  LEFT JOIN branches b ON p.branch_id = b.id 
                                  WHERE $whereClause
                                  ORDER BY p.created_at DESC
                                  LIMIT :limit OFFSET :offset", 
                                  array_merge($params, [
                                      ':limit' => $pagination['limit'],
                                      ':offset' => $pagination['offset']
                                  ]));
        
        if ($products === false) {
            $products = [];
        }
        
        // Process images
        foreach ($products as &$product) {
            if (!empty($product['images'])) {
                $product['images'] = json_decode($product['images'], true);
            }
        }
        
        $response = formatPaginatedResponse($products, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    // Create product
    requirePermission('products.create');
    
    $input = getRequestBody();
    
    // Validate required fields
    $required = ['product_code'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            sendError("Field '$field' is required", 400);
        }
    }
    
    // Prepare product data
    $productData = [
        'product_code' => $input['product_code'],
        'category_id' => $input['category_id'] ?? null,
        'branch_id' => $user['branch_id'] ?? $input['branch_id'] ?? null,
        'tax_id' => isset($input['tax_id']) && $input['tax_id'] !== '' ? intval($input['tax_id']) : null,
        'brand' => $input['brand'] ?? null,
        'model' => $input['model'] ?? null,
        'product_name' => $input['product_name'] ?? null,
        'description' => $input['description'] ?? null,
        'cost_price' => floatval($input['cost_price'] ?? 0),
        'selling_price' => floatval($input['selling_price'] ?? 0),
        'quantity_in_stock' => intval($input['quantity_in_stock'] ?? 0),
        'reorder_level' => intval($input['reorder_level'] ?? 0),
        'status' => $input['status'] ?? 'Active',
        'color' => $input['color'] ?? null,
        'barcode' => $input['barcode'] ?? null,
        'serial_number' => $input['serial_number'] ?? null,
        'imei' => $input['imei'] ?? null,
        'sim_config' => $input['sim_config'] ?? null,
        'battery_health' => $input['battery_health'] ?? null,
        'images' => isset($input['images']) ? json_encode($input['images']) : null,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $productId = $db->insert('products', $productData);
    
    if (!$productId) {
        sendError('Failed to create product: ' . $db->getLastError(), 500);
    }
    
    $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $productId]);
    sendSuccess($product, 'Product created successfully', 201);
    
} elseif ($method === 'PUT') {
    // Update product
    requirePermission('products.edit');
    
    if (!$id) {
        sendError('Product ID is required', 400);
    }
    
    $input = getRequestBody();
    
    // Check if product exists
    $existing = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $id]);
    if (!$existing) {
        sendError('Product not found', 404);
    }
    
    // Prepare update data
    $updateData = [];
    $allowedFields = [
        'category_id', 'brand', 'model', 'product_name', 'description',
        'cost_price', 'selling_price', 'quantity_in_stock', 'reorder_level',
        'status', 'color', 'barcode', 'serial_number', 'imei', 'sim_config',
        'battery_health', 'images', 'tax_id'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            if ($field === 'images' && is_array($input[$field])) {
                $updateData[$field] = json_encode($input[$field]);
            } elseif ($field === 'tax_id') {
                // Handle tax_id: allow null or integer
                $updateData[$field] = ($input[$field] === '' || $input[$field] === null) ? null : intval($input[$field]);
            } else {
                $updateData[$field] = $input[$field];
            }
        }
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $result = $db->update('products', $updateData, ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to update product: ' . $db->getLastError(), 500);
    }
    
    $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $id]);
    sendSuccess($product, 'Product updated successfully');
    
} elseif ($method === 'DELETE') {
    // Delete product
    requirePermission('products.delete');
    
    if (!$id) {
        sendError('Product ID is required', 400);
    }
    
    $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $id]);
    if (!$product) {
        sendError('Product not found', 404);
    }
    
    // Soft delete by setting status to Inactive
    $result = $db->update('products', ['status' => 'Inactive'], ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to delete product', 500);
    }
    
    sendSuccess([], 'Product deleted successfully');
} else {
    sendError('Method not allowed', 405);
}



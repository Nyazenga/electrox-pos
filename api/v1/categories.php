<?php
/**
 * Product Categories API Endpoint
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
    requirePermission('products.view');
    
    if ($id) {
        $category = $db->getRow("SELECT pc.*, COUNT(p.id) as product_count FROM product_categories pc LEFT JOIN products p ON pc.id = p.category_id WHERE pc.id = :id GROUP BY pc.id", [':id' => $id]);
        if (!$category) {
            sendError('Category not found', 404);
        }
        sendSuccess($category);
    } else {
        $categories = $db->getRows("SELECT pc.*, COUNT(p.id) as product_count FROM product_categories pc LEFT JOIN products p ON pc.id = p.category_id GROUP BY pc.id ORDER BY pc.name LIMIT :limit OFFSET :offset",
                                    [
                                        ':limit' => $pagination['limit'],
                                        ':offset' => $pagination['offset']
                                    ]);
        
        if ($categories === false) {
            $categories = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM product_categories");
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($categories, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('products.create');
    
    $input = getRequestBody();
    
    if (!isset($input['name']) || empty($input['name'])) {
        sendError('Category name is required', 400);
    }
    
    $categoryData = [
        'name' => $input['name'],
        'description' => $input['description'] ?? null,
        'tax_id' => isset($input['tax_id']) && $input['tax_id'] !== '' ? intval($input['tax_id']) : null,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $categoryId = $db->insert('product_categories', $categoryData);
    
    if (!$categoryId) {
        sendError('Failed to create category: ' . $db->getLastError(), 500);
    }
    
    $category = $db->getRow("SELECT * FROM product_categories WHERE id = :id", [':id' => $categoryId]);
    sendSuccess($category, 'Category created successfully', 201);
    
} elseif ($method === 'PUT') {
    requirePermission('products.edit');
    
    if (!$id) {
        sendError('Category ID is required', 400);
    }
    
    $input = getRequestBody();
    
    $updateData = [];
    if (isset($input['name'])) {
        $updateData['name'] = $input['name'];
    }
    if (isset($input['description'])) {
        $updateData['description'] = $input['description'];
    }
    if (isset($input['tax_id'])) {
        $updateData['tax_id'] = ($input['tax_id'] === '' || $input['tax_id'] === null) ? null : intval($input['tax_id']);
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $result = $db->update('product_categories', $updateData, ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to update category', 500);
    }
    
    $category = $db->getRow("SELECT * FROM product_categories WHERE id = :id", [':id' => $id]);
    sendSuccess($category, 'Category updated successfully');
    
} elseif ($method === 'DELETE') {
    requirePermission('products.delete');
    
    if (!$id) {
        sendError('Category ID is required', 400);
    }
    
    // Check if category has products
    $productCount = $db->getRow("SELECT COUNT(*) as count FROM products WHERE category_id = :id", [':id' => $id]);
    if ($productCount && intval($productCount['count']) > 0) {
        sendError('Cannot delete category with existing products', 400);
    }
    
    $result = $db->delete('product_categories', ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to delete category', 500);
    }
    
    sendSuccess([], 'Category deleted successfully');
} else {
    sendError('Method not allowed', 405);
}



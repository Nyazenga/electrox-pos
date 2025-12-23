<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.create');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['name'])) {
    echo json_encode(['success' => false, 'message' => 'Category name is required']);
    exit;
}

try {
    $db = Database::getInstance();
    
    $data = [
        'name' => $input['name'],
        'description' => $input['description'] ?? null,
        'tax_id' => isset($input['tax_id']) && $input['tax_id'] !== '' ? intval($input['tax_id']) : null,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if (!empty($input['id'])) {
        // Update
        $db->update('product_categories', $data, ['id' => intval($input['id'])]);
    } else {
        // Insert
        $data['created_at'] = date('Y-m-d H:i:s');
        $db->insert('product_categories', $data);
    }
    
    echo json_encode(['success' => true, 'message' => 'Category saved successfully']);
    
} catch (Exception $e) {
    logError("Save category error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save category: ' . $e->getMessage()]);
}


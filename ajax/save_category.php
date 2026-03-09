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
    
    $isSpecific = isset($input['is_specific']) ? intval($input['is_specific']) : 0;
    
    $data = [
        'name' => $input['name'],
        'description' => $input['description'] ?? null,
        'tax_id' => isset($input['tax_id']) && $input['tax_id'] !== '' ? intval($input['tax_id']) : null,
        'is_specific' => $isSpecific,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if (!empty($input['id'])) {
        // Update existing category
        $categoryId = intval($input['id']);
        $db->update('product_categories', $data, ['id' => $categoryId]);
        
        // Save characteristic assignments if category is specific
        if ($isSpecific && isset($input['characteristics'])) {
            // Remove existing assignments
            $db->delete('category_characteristic_assignments', ['category_id' => $categoryId]);
            
            // Insert new assignments
            $characteristics = $input['characteristics'];
            foreach ($characteristics as $index => $char) {
                $charId = intval($char['id'] ?? 0);
                if ($charId <= 0) continue;
                
                $db->insert('category_characteristic_assignments', [
                    'category_id' => $categoryId,
                    'characteristic_id' => $charId,
                    'is_required' => !empty($char['is_required']) ? 1 : 0,
                    'sort_order' => $index + 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        } elseif (!$isSpecific) {
            // If no longer specific, remove all characteristic assignments
            $db->delete('category_characteristic_assignments', ['category_id' => $categoryId]);
        }
        
        // Update requires_specific_list flag on products in this category
        $db->getPdo()->prepare(
            "UPDATE products SET requires_specific_list = :is_specific WHERE category_id = :category_id"
        )->execute([':is_specific' => $isSpecific, ':category_id' => $categoryId]);
        
    } else {
        // Create new category
        $data['created_at'] = date('Y-m-d H:i:s');
        $categoryId = $db->insert('product_categories', $data);
        
        if (!$categoryId) {
            echo json_encode(['success' => false, 'message' => 'Failed to create category']);
            exit;
        }
        
        // Save characteristic assignments if category is specific
        if ($isSpecific && isset($input['characteristics'])) {
            foreach ($input['characteristics'] as $index => $char) {
                $charId = intval($char['id'] ?? 0);
                if ($charId <= 0) continue;
                
                $db->insert('category_characteristic_assignments', [
                    'category_id' => $categoryId,
                    'characteristic_id' => $charId,
                    'is_required' => !empty($char['is_required']) ? 1 : 0,
                    'sort_order' => $index + 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'Category saved successfully', 'id' => $categoryId]);
    
} catch (Exception $e) {
    logError("Save category error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save category: ' . $e->getMessage()]);
}

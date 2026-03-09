<?php
/**
 * AJAX handler for Category Characteristics CRUD
 * Manages the master list of characteristics that can be assigned to categories
 */
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Determine action from POST or GET
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

try {
    $db = Database::getInstance();
    
    switch ($action) {
        
        // ─── LIST ALL CHARACTERISTICS ───
        case 'list':
            $characteristics = $db->getRows(
                "SELECT * FROM category_characteristics WHERE is_active = 1 ORDER BY sort_order, label"
            );
            echo json_encode(['success' => true, 'characteristics' => $characteristics ?: []]);
            break;
        
        // ─── GET SINGLE CHARACTERISTIC ───
        case 'get':
            $id = intval($_GET['id'] ?? ($input['id'] ?? 0));
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid characteristic ID']);
                exit;
            }
            $char = $db->getRow("SELECT * FROM category_characteristics WHERE id = :id", [':id' => $id]);
            if (!$char) {
                echo json_encode(['success' => false, 'message' => 'Characteristic not found']);
                exit;
            }
            echo json_encode(['success' => true, 'characteristic' => $char]);
            break;
        
        // ─── CREATE CHARACTERISTIC ───
        case 'create':
            if (!$auth->hasPermission('products.create')) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $input = $input ?? json_decode(file_get_contents('php://input'), true);
            $name = sanitizeInput($input['name'] ?? '');
            $label = sanitizeInput($input['label'] ?? '');
            $fieldType = $input['field_type'] ?? 'text';
            $options = $input['options'] ?? null;
            $description = sanitizeInput($input['description'] ?? '');
            
            if (empty($name) || empty($label)) {
                echo json_encode(['success' => false, 'message' => 'Name and Label are required']);
                exit;
            }
            
            // Validate field_type
            $validTypes = ['text', 'number', 'select', 'color', 'boolean', 'textarea', 'date'];
            if (!in_array($fieldType, $validTypes)) {
                echo json_encode(['success' => false, 'message' => 'Invalid field type']);
                exit;
            }
            
            // Sanitize name: lowercase, underscores only
            $name = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
            
            // Check for duplicate name
            $existing = $db->getRow("SELECT id FROM category_characteristics WHERE name = :name", [':name' => $name]);
            if ($existing) {
                echo json_encode(['success' => false, 'message' => 'A characteristic with this name already exists']);
                exit;
            }
            
            // Get max sort_order
            $maxOrder = $db->getRow("SELECT MAX(sort_order) as max_order FROM category_characteristics");
            $sortOrder = ($maxOrder['max_order'] ?? 0) + 1;
            
            // Handle options for select type
            $optionsJson = null;
            if ($fieldType === 'select' && !empty($options)) {
                if (is_string($options)) {
                    // Try to parse as JSON first
                    $parsed = json_decode($options, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $optionsJson = json_encode($parsed);
                    } else {
                        // Treat as comma-separated
                        $optionsArr = array_map('trim', explode(',', $options));
                        $optionsJson = json_encode(array_filter($optionsArr));
                    }
                } elseif (is_array($options)) {
                    $optionsJson = json_encode($options);
                }
            }
            
            $data = [
                'name' => $name,
                'label' => $label,
                'field_type' => $fieldType,
                'options' => $optionsJson,
                'is_system' => 0,
                'system_column' => null,
                'description' => $description,
                'sort_order' => $sortOrder,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $id = $db->insert('category_characteristics', $data);
            if ($id) {
                echo json_encode(['success' => true, 'message' => 'Characteristic created successfully', 'id' => $id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create characteristic']);
            }
            break;
        
        // ─── UPDATE CHARACTERISTIC ───
        case 'update':
            if (!$auth->hasPermission('products.edit')) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $input = $input ?? json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid characteristic ID']);
                exit;
            }
            
            $existing = $db->getRow("SELECT * FROM category_characteristics WHERE id = :id", [':id' => $id]);
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Characteristic not found']);
                exit;
            }
            
            $label = sanitizeInput($input['label'] ?? $existing['label']);
            $fieldType = $input['field_type'] ?? $existing['field_type'];
            $options = $input['options'] ?? $existing['options'];
            $description = sanitizeInput($input['description'] ?? $existing['description']);
            
            // Handle options for select type
            $optionsJson = null;
            if ($fieldType === 'select' && !empty($options)) {
                if (is_string($options)) {
                    $parsed = json_decode($options, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $optionsJson = json_encode($parsed);
                    } else {
                        $optionsArr = array_map('trim', explode(',', $options));
                        $optionsJson = json_encode(array_filter($optionsArr));
                    }
                } elseif (is_array($options)) {
                    $optionsJson = json_encode($options);
                }
            }
            
            $data = [
                'label' => $label,
                'field_type' => $fieldType,
                'options' => $optionsJson,
                'description' => $description,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Don't allow changing name or system fields for system characteristics
            if (!$existing['is_system']) {
                if (!empty($input['name'])) {
                    $data['name'] = preg_replace('/[^a-z0-9_]/', '_', strtolower(sanitizeInput($input['name'])));
                }
            }
            
            $result = $db->update('category_characteristics', $data, ['id' => $id]);
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Characteristic updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update characteristic']);
            }
            break;
        
        // ─── DELETE CHARACTERISTIC ───
        case 'delete':
            if (!$auth->hasPermission('products.delete')) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $input = $input ?? json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid characteristic ID']);
                exit;
            }
            
            $existing = $db->getRow("SELECT * FROM category_characteristics WHERE id = :id", [':id' => $id]);
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Characteristic not found']);
                exit;
            }
            
            // Don't allow deleting system characteristics
            if ($existing['is_system']) {
                echo json_encode(['success' => false, 'message' => 'System characteristics cannot be deleted. You can deactivate them instead.']);
                exit;
            }
            
            // Check if characteristic is in use
            $usageCount = $db->getCount(
                "SELECT COUNT(*) FROM category_characteristic_assignments WHERE characteristic_id = :id",
                [':id' => $id]
            );
            
            if ($usageCount > 0) {
                // Soft delete - deactivate instead
                $db->update('category_characteristics', ['is_active' => 0], ['id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Characteristic deactivated (it is assigned to categories)']);
            } else {
                $db->delete('category_characteristics', ['id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Characteristic deleted successfully']);
            }
            break;
        
        // ─── TOGGLE ACTIVE STATUS ───
        case 'toggle_active':
            if (!$auth->hasPermission('products.edit')) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $input = $input ?? json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid characteristic ID']);
                exit;
            }
            
            $existing = $db->getRow("SELECT * FROM category_characteristics WHERE id = :id", [':id' => $id]);
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Characteristic not found']);
                exit;
            }
            
            $newStatus = $existing['is_active'] ? 0 : 1;
            $db->update('category_characteristics', ['is_active' => $newStatus], ['id' => $id]);
            echo json_encode([
                'success' => true, 
                'message' => $newStatus ? 'Characteristic activated' : 'Characteristic deactivated',
                'is_active' => $newStatus
            ]);
            break;
        
        // ─── GET CHARACTERISTICS FOR A CATEGORY ───
        case 'get_for_category':
            $categoryId = intval($_GET['category_id'] ?? ($input['category_id'] ?? 0));
            if (!$categoryId) {
                echo json_encode(['success' => false, 'message' => 'Invalid category ID']);
                exit;
            }
            
            $assigned = $db->getRows(
                "SELECT cc.*, cca.is_required, cca.sort_order as assignment_order
                 FROM category_characteristics cc
                 INNER JOIN category_characteristic_assignments cca ON cc.id = cca.characteristic_id
                 WHERE cca.category_id = :category_id AND cc.is_active = 1
                 ORDER BY cca.sort_order, cc.sort_order",
                [':category_id' => $categoryId]
            );
            
            echo json_encode(['success' => true, 'characteristics' => $assigned ?: []]);
            break;
        
        // ─── SAVE CATEGORY CHARACTERISTIC ASSIGNMENTS ───
        case 'save_assignments':
            if (!$auth->hasPermission('products.edit')) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $input = $input ?? json_decode(file_get_contents('php://input'), true);
            $categoryId = intval($input['category_id'] ?? 0);
            $assignments = $input['assignments'] ?? [];
            
            if (!$categoryId) {
                echo json_encode(['success' => false, 'message' => 'Invalid category ID']);
                exit;
            }
            
            $db->beginTransaction();
            try {
                // Remove existing assignments
                $db->delete('category_characteristic_assignments', ['category_id' => $categoryId]);
                
                // Insert new assignments
                foreach ($assignments as $index => $assignment) {
                    $charId = intval($assignment['characteristic_id'] ?? 0);
                    if ($charId <= 0) continue;
                    
                    $db->insert('category_characteristic_assignments', [
                        'category_id' => $categoryId,
                        'characteristic_id' => $charId,
                        'is_required' => !empty($assignment['is_required']) ? 1 : 0,
                        'sort_order' => $index + 1,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
                
                $db->commitTransaction();
                echo json_encode(['success' => true, 'message' => 'Characteristic assignments saved']);
            } catch (Exception $e) {
                $db->rollbackTransaction();
                echo json_encode(['success' => false, 'message' => 'Failed to save assignments: ' . $e->getMessage()]);
            }
            break;
        
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
            break;
    }
    
} catch (Exception $e) {
    logError("Characteristics error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

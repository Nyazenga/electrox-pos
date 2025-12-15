<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$productId = intval($input['product_id'] ?? 0);

if (!$productId) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

try {
    $db = Database::getInstance();
    $userId = $_SESSION['user_id'];
    
    // Check if already favorited
    $existing = $db->getRow("SELECT id FROM product_favorites WHERE product_id = :product_id AND user_id = :user_id", [
        ':product_id' => $productId,
        ':user_id' => $userId
    ]);
    
    if ($existing) {
        // Remove from favorites
        $db->delete('product_favorites', ['id' => $existing['id']]);
        echo json_encode(['success' => true, 'favorited' => false]);
    } else {
        // Add to favorites
        $db->insert('product_favorites', [
            'product_id' => $productId,
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo json_encode(['success' => true, 'favorited' => true]);
    }
} catch (Exception $e) {
    logError("Toggle favorite error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


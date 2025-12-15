<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.edit');

$input = json_decode(file_get_contents('php://input'), true);
$productId = $input['product_id'] ?? 0;
$imageUrl = $input['image_url'] ?? '';

if (!$productId || !$imageUrl) {
    echo json_encode(['success' => false, 'message' => 'Product ID and image URL required']);
    exit;
}

$db = Database::getInstance();
$product = $db->getRow("SELECT * FROM products WHERE id = ?", [$productId]);
if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

$existingImages = !empty($product['images']) ? json_decode($product['images'], true) : [];
$existingImages = array_filter($existingImages, function($img) use ($imageUrl) {
    return $img !== $imageUrl;
});

$db->update('products', ['images' => json_encode(array_values($existingImages))], ['id' => $productId]);

echo json_encode(['success' => true]);


<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.edit');

$db = Database::getInstance();
$productId = $_POST['product_id'] ?? 0;

if (!$productId) {
    echo json_encode(['success' => false, 'message' => 'Product ID required']);
    exit;
}

$product = $db->getRow("SELECT * FROM products WHERE id = ?", [$productId]);
if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No image uploaded']);
    exit;
}

$uploadDir = APP_PATH . '/uploads/products/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$fileName = uniqid() . '_' . basename($_FILES['image']['name']);
$targetPath = $uploadDir . $fileName;

if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
    $imageUrl = BASE_URL . 'uploads/products/' . $fileName;
    
    $existingImages = !empty($product['images']) ? json_decode($product['images'], true) : [];
    $existingImages[] = $imageUrl;
    
    $db->update('products', ['images' => json_encode($existingImages)], ['id' => $productId]);
    
    echo json_encode(['success' => true, 'image_url' => $imageUrl]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
}


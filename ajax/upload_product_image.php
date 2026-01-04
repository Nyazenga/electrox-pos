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

if (!isset($_FILES['image'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$fileError = $_FILES['image']['error'];
if ($fileError !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
        UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];
    $errorMessage = $errorMessages[$fileError] ?? 'Upload error code: ' . $fileError;
    error_log("Image upload error: $errorMessage (code: $fileError)");
    echo json_encode(['success' => false, 'message' => $errorMessage]);
    exit;
}

// Validate file type
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$fileType = $_FILES['image']['type'];
if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.']);
    exit;
}

// Validate file size (max 5MB)
$maxFileSize = 5 * 1024 * 1024; // 5MB in bytes
if ($_FILES['image']['size'] > $maxFileSize) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
    exit;
}

$uploadDir = APP_PATH . '/uploads/products/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        error_log("Failed to create upload directory: $uploadDir");
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
        exit;
    }
}

// Check if directory is writable
if (!is_writable($uploadDir)) {
    error_log("Upload directory is not writable: $uploadDir");
    echo json_encode(['success' => false, 'message' => 'Upload directory is not writable']);
    exit;
}

$fileName = uniqid() . '_' . basename($_FILES['image']['name']);
$targetPath = $uploadDir . $fileName;

if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
    // Verify file was actually written
    if (!file_exists($targetPath)) {
        error_log("File was not created after move_uploaded_file: $targetPath");
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
        exit;
    }
    
    $imageUrl = BASE_URL . 'uploads/products/' . $fileName;
    
    $existingImages = !empty($product['images']) ? json_decode($product['images'], true) : [];
    if (!is_array($existingImages)) {
        $existingImages = [];
    }
    $existingImages[] = $imageUrl;
    
    $result = $db->update('products', ['images' => json_encode($existingImages)], ['id' => $productId]);
    if ($result === false) {
        error_log("Failed to update product images in database. Error: " . $db->getLastError());
        // Don't fail the upload if DB update fails - file is already uploaded
    }
    
    echo json_encode(['success' => true, 'image_url' => $imageUrl]);
} else {
    $lastError = error_get_last();
    $errorMsg = $lastError ? $lastError['message'] : 'Unknown error';
    error_log("move_uploaded_file failed: $errorMsg. Source: " . $_FILES['image']['tmp_name'] . ", Target: $targetPath");
    echo json_encode(['success' => false, 'message' => 'Failed to upload image. Please check directory permissions.']);
}


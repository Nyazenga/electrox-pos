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

// Create upload directory structure
$uploadDir = APP_PATH . '/uploads/products/';
$uploadsBaseDir = APP_PATH . '/uploads/';

// Try to create base uploads directory first if it doesn't exist
if (!is_dir($uploadsBaseDir)) {
    if (!mkdir($uploadsBaseDir, 0755, true)) {
        error_log("Failed to create base uploads directory: $uploadsBaseDir");
        echo json_encode(['success' => false, 'message' => 'Failed to create uploads directory. Please contact administrator to create the uploads directory with proper permissions.']);
        exit;
    }
}

// Now create products subdirectory if it doesn't exist
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        error_log("Failed to create products upload directory: $uploadDir. Base directory exists: " . (is_dir($uploadsBaseDir) ? 'yes' : 'no') . ", writable: " . (is_writable($uploadsBaseDir) ? 'yes' : 'no'));
        echo json_encode(['success' => false, 'message' => 'Failed to create products upload directory. Please contact administrator to create the uploads/products directory with proper permissions (755).']);
        exit;
    }
}

// Check if directory is writable
if (!is_writable($uploadDir)) {
    error_log("Upload directory is not writable: $uploadDir. Permissions: " . substr(sprintf('%o', fileperms($uploadDir)), -4));
    echo json_encode(['success' => false, 'message' => 'Upload directory is not writable. Please contact administrator to set write permissions (755) on the uploads/products directory.']);
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


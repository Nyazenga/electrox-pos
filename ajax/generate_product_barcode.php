<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

initSession();

header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $auth->requirePermission('products.barcodes');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['product_id'])) {
    echo json_encode(['success' => false, 'message' => 'Product ID required']);
    exit;
}

$db = Database::getInstance();
$productId = intval($input['product_id']);

// Get product
$product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $productId]);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

// Generate unique barcode (13 digits EAN-13 format)
function generateUniqueBarcode($db) {
    do {
        // Generate 12 digits
        $barcode = '';
        for ($i = 0; $i < 12; $i++) {
            $barcode .= rand(0, 9);
        }
        
        // Calculate check digit (EAN-13)
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = intval($barcode[$i]);
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        $barcode .= $checkDigit;
        
        // Check if barcode already exists
        $exists = $db->getRow("SELECT id FROM products WHERE barcode = :barcode", [':barcode' => $barcode]);
    } while ($exists);
    
    return $barcode;
}

$barcode = generateUniqueBarcode($db);

// Update product
if ($db->update('products', ['barcode' => $barcode], ['id' => $productId])) {
    echo json_encode([
        'success' => true,
        'message' => 'Barcode generated successfully',
        'barcode' => $barcode
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save barcode']);
}


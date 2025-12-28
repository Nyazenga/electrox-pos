<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/vendor/autoload.php';

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
$replaceExisting = isset($input['replace_existing']) && $input['replace_existing'];
$productIds = isset($input['product_ids']) && is_array($input['product_ids']) ? array_map('intval', $input['product_ids']) : null;

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get products
$branchId = $_SESSION['branch_id'] ?? null;
$whereClause = "status = 'Active'";
$params = [];

// If specific product IDs are provided, use only those
if ($productIds !== null && !empty($productIds)) {
    $placeholders = [];
    foreach ($productIds as $index => $id) {
        $key = ':product_id_' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $whereClause .= " AND id IN (" . implode(',', $placeholders) . ")";
    
    // If replace_existing is false, only generate for products without barcodes
    if (!$replaceExisting) {
        $whereClause .= " AND (barcode IS NULL OR barcode = '')";
    }
} else {
    // Otherwise, use branch filter if applicable
    if ($branchId) {
        $whereClause .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branchId;
    }
    
    if (!$replaceExisting) {
        $whereClause .= " AND (barcode IS NULL OR barcode = '')";
    }
}

$sql = "SELECT * FROM products WHERE $whereClause ORDER BY product_code";
$products = $db->getRows($sql, $params);

if ($products === false) {
    error_log("Barcode generation query failed. SQL: $sql, Params: " . json_encode($params));
    echo json_encode(['success' => false, 'message' => 'Database query failed. Please check error logs.']);
    exit;
}

if (empty($products)) {
    $message = 'No products found to generate barcodes for';
    if (!$replaceExisting && $productIds === null) {
        $message .= '. All products already have barcodes. Check "Replace existing barcodes" to regenerate them.';
    } elseif (!$replaceExisting && $productIds !== null) {
        $message .= '. Selected products already have barcodes. Check "Replace existing barcodes" to regenerate them.';
    }
    error_log("No products found. SQL: $sql, Params: " . json_encode($params) . ", Replace existing: " . ($replaceExisting ? 'true' : 'false'));
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Generate unique barcode function
function generateUniqueBarcode($db) {
    do {
        $barcode = '';
        for ($i = 0; $i < 12; $i++) {
            $barcode .= rand(0, 9);
        }
        
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = intval($barcode[$i]);
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        $barcode .= $checkDigit;
        
        $exists = $db->getRow("SELECT id FROM products WHERE barcode = :barcode", [':barcode' => $barcode]);
    } while ($exists);
    
    return $barcode;
}

// Generate barcodes for all products
$generated = 0;
$barcodeMap = [];

foreach ($products as $product) {
    $barcode = generateUniqueBarcode($db);
    if ($db->update('products', ['barcode' => $barcode], ['id' => $product['id']])) {
        $generated++;
        $barcodeMap[$product['id']] = [
            'barcode' => $barcode,
            'product' => $product
        ];
    }
}

if ($generated === 0) {
    echo json_encode(['success' => false, 'message' => 'Failed to generate any barcodes']);
    exit;
}

// Generate PDF with barcodes
try {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('ELECTROX-POS');
    $pdf->SetAuthor('ELECTROX-POS');
    $pdf->SetTitle('Product Barcodes');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 10);
    
    // Barcode style
    $style = array(
        'position' => '',
        'align' => 'C',
        'stretch' => false,
        'fitwidth' => true,
        'cellfitalign' => '',
        'border' => true,
        'hpadding' => 'auto',
        'vpadding' => 'auto',
        'fgcolor' => array(0,0,0),
        'bgcolor' => false,
        'text' => true,
        'font' => 'helvetica',
        'fontsize' => 8,
        'stretchtext' => 4
    );
    
    $itemsPerPage = 20; // 4 columns x 5 rows
    $itemsPerRow = 4;
    $currentItem = 0;
    
    // Add first page
    $pdf->AddPage();
    $y = 15;
    
    foreach ($barcodeMap as $productId => $data) {
        if ($currentItem > 0 && $currentItem % $itemsPerPage === 0) {
            $pdf->AddPage();
            $y = 15;
        }
        
        $row = floor(($currentItem % $itemsPerPage) / $itemsPerRow);
        $col = ($currentItem % $itemsPerPage) % $itemsPerRow;
        
        $x = 10 + ($col * 48);
        $y = 15 + ($row * 50);
        
        $product = $data['product'];
        $barcode = $data['barcode'];
        $displayName = !empty($product['product_name']) ? $product['product_name'] : trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
        
        // Product name (truncate if too long)
        $pdf->SetXY($x, $y);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(45, 5, substr($displayName, 0, 30), 0, 1, 'C');
        
        // Barcode
        $pdf->SetXY($x, $y + 5);
        $pdf->write1DBarcode($barcode, 'EAN13', $x, $y + 5, 45, 15, 0.4, $style, 'N');
        
        // Product code
        $pdf->SetXY($x, $y + 22);
        $pdf->SetFont('helvetica', '', 6);
        $pdf->Cell(45, 4, 'Code: ' . ($product['product_code'] ?? 'N/A'), 0, 1, 'C');
        
        // Barcode number
        $pdf->SetXY($x, $y + 26);
        $pdf->Cell(45, 4, $barcode, 0, 1, 'C');
        
        $currentItem++;
    }
    
    // Save PDF
    $filename = 'barcodes_' . date('YmdHis') . '.pdf';
    $filepath = APP_PATH . '/uploads/barcodes/' . $filename;
    
    if (!is_dir(dirname($filepath))) {
        mkdir(dirname($filepath), 0755, true);
    }
    
    $pdf->Output($filepath, 'F');
    
    echo json_encode([
        'success' => true,
        'message' => "Generated $generated barcode(s) successfully",
        'pdf_url' => BASE_URL . 'uploads/barcodes/' . $filename
    ]);
    
} catch (Exception $e) {
    error_log("Barcode PDF generation error: " . $e->getMessage());
    echo json_encode([
        'success' => true,
        'message' => "Generated $generated barcode(s), but PDF generation failed: " . $e->getMessage()
    ]);
}


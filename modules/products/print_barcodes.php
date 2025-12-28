<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.barcodes');

$productIds = $_GET['ids'] ?? '';
if (empty($productIds)) {
    die('No products selected');
}

$ids = array_filter(array_map('intval', explode(',', $productIds)));
if (empty($ids)) {
    die('Invalid product IDs');
}

$db = Database::getInstance();
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$products = $db->getRows("SELECT p.*, 
                         COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
                         pc.name as category_name,
                         b.branch_name
                         FROM products p
                         LEFT JOIN product_categories pc ON p.category_id = pc.id
                         LEFT JOIN branches b ON p.branch_id = b.id
                         WHERE p.id IN ($placeholders) AND p.barcode IS NOT NULL AND p.barcode != ''
                         ORDER BY p.product_code", $ids);

if (empty($products)) {
    die('No products with barcodes found');
}

require_once APP_PATH . '/vendor/autoload.php';

// Create PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('ELECTROX-POS');
$pdf->SetAuthor('ELECTROX-POS');
$pdf->SetTitle('Product Barcodes');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);

// Barcode style - matching generate_all_barcodes.php
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

foreach ($products as $product) {
    if ($currentItem > 0 && $currentItem % $itemsPerPage === 0) {
        $pdf->AddPage();
        $y = 15;
    }
    
    $row = floor(($currentItem % $itemsPerPage) / $itemsPerRow);
    $col = ($currentItem % $itemsPerPage) % $itemsPerRow;
    
    $x = 10 + ($col * 48);
    $y = 15 + ($row * 50);
    
    $barcode = $product['barcode'];
    $displayName = $product['display_name'];
    
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

// Output PDF
$pdf->Output('Product_Barcodes_' . date('Ymd') . '.pdf', 'I');
exit;


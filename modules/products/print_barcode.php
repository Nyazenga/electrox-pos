<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.barcodes');

$productId = intval($_GET['id'] ?? 0);
if (!$productId) {
    die('Invalid product ID');
}

$db = Database::getInstance();
$product = $db->getRow("SELECT p.*, 
                       COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
                       pc.name as category_name,
                       b.branch_name
                       FROM products p
                       LEFT JOIN product_categories pc ON p.category_id = pc.id
                       LEFT JOIN branches b ON p.branch_id = b.id
                       WHERE p.id = :id", [':id' => $productId]);

if (!$product) {
    die('Product not found');
}

if (empty($product['barcode'])) {
    die('Product does not have a barcode. Please generate one first.');
}

require_once APP_PATH . '/vendor/autoload.php';

// Create PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('ELECTROX-POS');
$pdf->SetAuthor('ELECTROX-POS');
$pdf->SetTitle('Barcode - ' . $product['display_name']);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);

// Barcode style - matching print_barcodes.php and generate_all_barcodes.php
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

// Add first page
$pdf->AddPage();
$y = 15;

// Position in top-left corner (first position in the grid)
$x = 10; // First column
$y = 15; // First row

$barcode = $product['barcode'];
$displayName = $product['display_name'];

// Product name (truncate if too long) - matching print_barcodes.php
$pdf->SetXY($x, $y);
$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(45, 5, substr($displayName, 0, 30), 0, 1, 'C');

// Barcode - using EAN13 like print_barcodes.php
$pdf->SetXY($x, $y + 5);
$pdf->write1DBarcode($barcode, 'EAN13', $x, $y + 5, 45, 15, 0.4, $style, 'N');

// Product code - matching print_barcodes.php format
$pdf->SetXY($x, $y + 22);
$pdf->SetFont('helvetica', '', 6);
$pdf->Cell(45, 4, 'Code: ' . ($product['product_code'] ?? 'N/A'), 0, 1, 'C');

// Barcode number - matching print_barcodes.php
$pdf->SetXY($x, $y + 26);
$pdf->Cell(45, 4, $barcode, 0, 1, 'C');

// Output PDF
$pdf->Output('Barcode_' . $product['product_code'] . '.pdf', 'I');
exit;


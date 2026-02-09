<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/settings_functions.php';

// Get prices_include_tax setting for receipt display
$pricesIncludeTax = getSetting('prices_include_tax', '1') == '1';

$auth = Auth::getInstance();
$auth->requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die('Invalid receipt ID');
}

// Check if we should use TCPDF (for PDF export)
$usePDF = isset($_GET['pdf']) && $_GET['pdf'] == '1';

$db = Database::getInstance();
$sale = $db->getRow("SELECT s.*, c.first_name, c.last_name, c.email, c.phone, c.address, c.company_name, c.tin, c.vat_number, c.city, b.branch_name, b.address as branch_address, b.phone as branch_phone, u.first_name as cashier_first, u.last_name as cashier_last,
                      pt.name as payment_term_name, pt.days as payment_term_days
                      FROM sales s 
                      LEFT JOIN customers c ON s.customer_id = c.id 
                      LEFT JOIN branches b ON s.branch_id = b.id 
                      LEFT JOIN users u ON s.user_id = u.id 
                      LEFT JOIN payment_terms pt ON s.payment_term_id = pt.id
                      WHERE s.id = :id", [':id' => $id]);

// Initialize fiscal_details if not set
if (!isset($sale['fiscal_details'])) {
    $sale['fiscal_details'] = null;
}
if (!isset($sale['fiscalized'])) {
    $sale['fiscalized'] = 0;
}

// Check if sale has fiscal_details, if not check invoice
if (!$sale['fiscal_details'] && !empty($sale['invoice_id'])) {
    $invoice = $db->getRow("SELECT fiscalized, fiscal_details FROM invoices WHERE id = :id", [':id' => $sale['invoice_id']]);
    if ($invoice && $invoice['fiscalized']) {
        $sale['fiscalized'] = $invoice['fiscalized'];
        $sale['fiscal_details'] = $invoice['fiscal_details'];
    }
}

if (!$sale) {
    die('Receipt not found');
}

$items = $db->getRows("SELECT si.*, p.tax_id as product_tax_id, pc.tax_id as category_tax_id 
                       FROM sale_items si 
                       LEFT JOIN products p ON si.product_id = p.id 
                       LEFT JOIN product_categories pc ON p.category_id = pc.id 
                       WHERE si.sale_id = :id", [':id' => $id]);
if ($items === false) {
    $items = [];
}

// Get product_specific_list entries for each sale item
// CRITICAL: Entries are DELETED when sold, but if delete fails they're marked as 'sold' with sale_item_id set
foreach ($items as &$item) {
    // Query for entries linked by sale_item_id (includes entries that weren't successfully deleted)
    $specificEntries = $db->getRows(
        "SELECT * FROM product_specific_list 
         WHERE sale_item_id = :sale_item_id 
         ORDER BY id",
        [':sale_item_id' => $item['id']]
    );
    
    $item['specific_list_entries'] = $specificEntries !== false ? $specificEntries : [];
}
unset($item);

// Get tax information for each item from fiscal config
$primaryDb = Database::getPrimaryInstance();
$branchId = $sale['branch_id'] ?? null;
$applicableTaxes = [];
if ($branchId) {
    $fiscalConfig = $primaryDb->getRow(
        "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id LIMIT 1",
        [':branch_id' => $branchId]
    );
    if ($fiscalConfig && !empty($fiscalConfig['applicable_taxes'])) {
        $applicableTaxes = json_decode($fiscalConfig['applicable_taxes'], true);
        if (!is_array($applicableTaxes)) {
            $applicableTaxes = [];
        }
    }
}

// Create tax map
$taxMap = [];
foreach ($applicableTaxes as $tax) {
    if (isset($tax['taxID'])) {
        $taxId = intval($tax['taxID']);
        $taxPercent = isset($tax['taxPercent']) && $tax['taxPercent'] !== null ? floatval($tax['taxPercent']) : null;
        $taxCode = $tax['taxCode'] ?? '';
        $taxMap[$taxId] = ['percent' => $taxPercent, 'code' => $taxCode];
    }
}

// Add tax percent to each item
foreach ($items as &$item) {
    $productTaxId = $item['product_tax_id'] ?? null;
    $categoryTaxId = $item['category_tax_id'] ?? null;
    $finalTaxId = $productTaxId ?: $categoryTaxId;
    
    $item['tax_percent'] = null;
    $item['tax_code'] = null;
    
    if ($finalTaxId && isset($taxMap[intval($finalTaxId)])) {
        $item['tax_percent'] = $taxMap[intval($finalTaxId)]['percent'];
        $item['tax_code'] = $taxMap[intval($finalTaxId)]['code'];
    }
}
unset($item);

// Get payments - ALWAYS fetch directly from sale_payments first to ensure we get them
$payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $id]);
if ($payments === false) {
    $payments = [];
}

// Enrich payments with currency information from tenant database
if (!empty($payments)) {
    foreach ($payments as &$payment) {
        if (!empty($payment['currency_id'])) {
            $currency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $payment['currency_id']]);
            if ($currency) {
                $payment['currency_code'] = $currency['code'];
                $payment['currency_symbol'] = $currency['symbol'];
                $payment['currency_symbol_position'] = $currency['symbol_position'];
            }
        }
    }
    unset($payment);
}

// Get base currency for display
$baseCurrency = getBaseCurrency($db);

// Determine payment currency from payments (for display conversion)
$paymentCurrency = null;
$paymentCurrencyId = null;
$exchangeRate = 1.0;
if (!empty($payments)) {
    // Get currency from first payment
    $firstPayment = $payments[0];
    if (!empty($firstPayment['currency_id'])) {
        $paymentCurrencyId = $firstPayment['currency_id'];
        $paymentCurrency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $paymentCurrencyId]);
        if ($paymentCurrency && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
            // Get exchange rate from BASE currency to PAYMENT currency (for converting base amounts to payment currency)
            // If base is USD (rate=1.0) and payment is ZWL (rate=2.0), then 1 USD = 2 ZWL, so rate = 2.0
            $exchangeRate = getExchangeRate($baseCurrency['id'], $paymentCurrencyId, $db);
        }
    }
}

$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');

// Get receipt logo - check pos_receipt_logo first, then fallback to invoice_logo, then company_logo
$receiptLogoPath = getSetting('pos_receipt_logo', '');
// If no receipt logo setting found, try invoice_logo, then company_logo
if (empty($receiptLogoPath)) {
    $receiptLogoPath = getSetting('invoice_logo', getSetting('company_logo', ''));
}
// If still no logo setting found, try to find the most recent receipt_logo or invoice_logo file
if (empty($receiptLogoPath)) {
    $logoDir = APP_PATH . '/assets/images/';
    $logoFiles = array_merge(
        glob($logoDir . 'receipt_logo_*.png'),
        glob($logoDir . 'receipt_logo_*.jpg'),
        glob($logoDir . 'receipt_logo_*.jpeg'),
        glob($logoDir . 'invoice_logo_*.png'),
        glob($logoDir . 'invoice_logo_*.jpg'),
        glob($logoDir . 'invoice_logo_*.jpeg')
    );
    if (!empty($logoFiles)) {
        // Sort by modification time, most recent first
        usort($logoFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $mostRecent = $logoFiles[0];
        $receiptLogoPath = 'assets/images/' . basename($mostRecent);
        // If we found a file but no database setting, save it to the database for persistence
        if (!empty($receiptLogoPath)) {
            setSetting('pos_receipt_logo', $receiptLogoPath);
        }
    }
}
// Normalize logo path - ensure it's relative to APP_PATH
if ($receiptLogoPath && !empty($receiptLogoPath)) {
    $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
    // If file doesn't exist at the stored path, try without leading slash
    if (!file_exists($logoFullPath) && strpos($receiptLogoPath, '/') !== 0) {
        $logoFullPath = APP_PATH . '/' . $receiptLogoPath;
    }
    // Only use logo if file actually exists
    if (!file_exists($logoFullPath)) {
        $receiptLogoPath = '';
    }
}
$receiptLogoUrl = '';
if ($receiptLogoPath) {
    $receiptLogoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
}

// Check if we should use TCPDF (for PDF export)
if ($usePDF) {
    // Use TCPDF for PDF generation
    require_once APP_PATH . '/vendor/autoload.php';
    
    // Create PDF (Portrait, mm, A4)
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('ELECTROX-POS');
    $pdf->SetAuthor($companyName);
    $pdf->SetTitle('Receipt ' . $sale['receipt_number']);
    $pdf->SetSubject('Receipt');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 20);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Get logo path for TCPDF
    $logoPath = '';
    $logoHeight = 0;
    if ($receiptLogoPath) {
        $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
        if (file_exists($logoFullPath)) {
            $logoPath = realpath($logoFullPath);
            $logoHeight = 25;
        }
    }
    
    // Start Y position
    $startY = 15;
    $pdf->SetY($startY);
    
    // Logo on right (if exists)
    $logoBottomY = $startY;
    if ($logoPath) {
        $logoWidth = 45;
        $logoX = 195 - 5 - $logoWidth;
        $logoY = $startY;
        try {
            $pdf->Image($logoPath, $logoX, $logoY, $logoWidth, $logoHeight, '', '', '', false, 300, '', false, false, 0);
            $logoBottomY = $logoY + $logoHeight;
        } catch (Exception $e) {
            error_log("Failed to add logo to PDF: " . $e->getMessage());
            $logoPath = '';
        }
    }
    
    // Company name on left
    $pdf->SetXY(15, $startY);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(95, 8, htmlspecialchars($companyName), 0, 1, 'L');
    
    // Company address on left
    $pdf->SetFont('helvetica', '', 9);
    $pdf->MultiCell(95, 5, htmlspecialchars($companyAddress), 0, 'L', false, 0);
    
    // Contact info
    $contactStartY = max($pdf->GetY(), $logoBottomY + 3);
    $pdf->SetXY(15, $contactStartY);
    if ($companyPhone) {
        $pdf->Cell(95, 5, 'Contact Number: ' . htmlspecialchars($companyPhone), 0, 1, 'L');
    }
    if ($companyEmail) {
        $pdf->Cell(95, 5, 'Email: ' . htmlspecialchars($companyEmail), 0, 1, 'L');
    }
    $leftSectionEndY = $pdf->GetY();
    
    // Receipt title on right
    $rightStartY = $logoBottomY + 3;
    $pdf->SetXY(110, $rightStartY);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 6, 'RECEIPT', 0, 1, 'R');
    $rightSectionEndY = $pdf->GetY();
    
    // Move to the lower of left or right section
    $nextY = max($leftSectionEndY, $rightSectionEndY);
    $pdf->SetY($nextY);
    
    // Horizontal line
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(8);
    
    // Receipt Meta Section
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(95, 5, '', 0, 0, 'L');
    $pdf->Cell(0, 5, 'Date: ' . date('d/m/Y H:i', strtotime($sale['sale_date'])), 0, 1, 'R');
    $pdf->Cell(95, 5, '', 0, 0, 'L');
    $pdf->Cell(0, 5, 'Receipt #: ' . htmlspecialchars($sale['receipt_number']), 0, 1, 'R');
    $cashierName = trim(($sale['cashier_first'] ?? '') . ' ' . ($sale['cashier_last'] ?? ''));
    if ($cashierName) {
        $pdf->Cell(95, 5, '', 0, 0, 'L');
        $pdf->Cell(0, 5, 'Cashier: ' . htmlspecialchars($cashierName), 0, 1, 'R');
    }
    
    // Customer Details Section (if available)
    $customerName = trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? ''));
    if ($customerName || !empty($sale['company_name']) || !empty($sale['phone']) || !empty($sale['address'])) {
        $pdf->Ln(5);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);
        
        $displayName = $customerName ?: ($sale['company_name'] ?? 'Walk-in');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'Customer Details:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Name: ' . htmlspecialchars($displayName), 0, 1, 'L');
        
        if (!empty($sale['phone'])) {
            $pdf->Cell(0, 5, 'Phone: ' . htmlspecialchars($sale['phone']), 0, 1, 'L');
        }
        if (!empty($sale['address'])) {
            $pdf->Cell(0, 5, 'Address: ' . htmlspecialchars($sale['address']), 0, 1, 'L');
        }
        
        $pdf->Ln(3);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);
    } else {
        $pdf->Ln(8);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(10);
    }
    
    // Items Table Header
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(120, 10, 'Description', 1, 0, 'L', true);
    $pdf->Cell(20, 10, 'Qty', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Price', 1, 0, 'R', true);
    $pdf->Cell(25, 10, 'Total', 1, 1, 'R', true);
    
    // Items Table Rows
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);
    
    foreach ($items as $item) {
        $description = htmlspecialchars($item['product_name']);
        $quantity = $item['quantity'];
        $unitPrice = floatval($item['unit_price']);
        $totalPrice = floatval($item['total_price']);
        
        // Add serial numbers and IMEI inline in description (not separate lines)
        if (!empty($item['specific_list_entries'])) {
            $details = [];
            foreach ($item['specific_list_entries'] as $entry) {
                $entryDetails = [];
                // Always check for serial_number and imei - they might be in the database
                if (isset($entry['serial_number']) && $entry['serial_number'] !== null && trim($entry['serial_number']) !== '') {
                    $entryDetails[] = "S/N: " . htmlspecialchars(trim($entry['serial_number']));
                }
                if (isset($entry['imei']) && $entry['imei'] !== null && trim($entry['imei']) !== '') {
                    $entryDetails[] = "IMEI: " . htmlspecialchars(trim($entry['imei']));
                }
                // Only add color and storage if serial/IMEI are present, or if they're the only details
                if (!empty($entryDetails)) {
                    // Add color and storage if available
                    if (isset($entry['color']) && $entry['color'] !== null && trim($entry['color']) !== '') {
                        $colorValue = trim($entry['color']);
                        if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $colorValue)) {
                                            $entryDetails[] = "Color: " . htmlspecialchars($colorValue);
                        }
                    }
                    if (isset($entry['storage']) && $entry['storage'] !== null && trim($entry['storage']) !== '') {
                        $entryDetails[] = "Storage: " . htmlspecialchars(trim($entry['storage']));
                    }
                    $details[] = implode(", ", $entryDetails);
                } elseif ((isset($entry['color']) && $entry['color'] !== null && trim($entry['color']) !== '') || 
                          (isset($entry['storage']) && $entry['storage'] !== null && trim($entry['storage']) !== '')) {
                    // If no serial/IMEI but has color/storage, show those
                    $entryDetails = [];
                    if (isset($entry['color']) && $entry['color'] !== null && trim($entry['color']) !== '') {
                        $colorValue = trim($entry['color']);
                        if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $colorValue)) {
                            $entryDetails[] = "Color: " . htmlspecialchars($colorValue);
                        }
                    }
                    if (isset($entry['storage']) && $entry['storage'] !== null && trim($entry['storage']) !== '') {
                        $entryDetails[] = "Storage: " . htmlspecialchars(trim($entry['storage']));
                    }
                    if (!empty($entryDetails)) {
                        $details[] = implode(", ", $entryDetails);
                    }
                }
            }
            if (!empty($details)) {
                $description .= " (" . implode("; ", $details) . ")";
            }
        }
        
        // Convert to payment currency if needed (for PDF display)
        if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
            $unitPrice = $unitPrice * $exchangeRate;
            $totalPrice = $totalPrice * $exchangeRate;
        }
        
        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        
        $lineHeight = 5;
        $minHeight = 8;
        
        $pdf->SetFont('helvetica', '', 9);
        
        // Calculate height needed for description (wrap text if needed)
        $testY = $pdf->GetY();
        $pdf->MultiCell(120, $lineHeight, $description, 0, 'L', false, 0);
        $measuredHeight = $pdf->GetY() - $testY;
        $actualRowHeight = max($minHeight, $measuredHeight);
        
        $pdf->SetXY($startX, $startY);
        // Use MultiCell for description to handle wrapping properly
        $pdf->MultiCell(120, $lineHeight, $description, 1, 'L', false, 0);
        
        // Get the end Y position after MultiCell
        $descEndY = $pdf->GetY();
        $actualRowHeight = max($minHeight, $descEndY - $startY);
        
        // Draw the other cells aligned to the same row
        $pdf->SetXY($startX + 120, $startY);
        $pdf->Cell(20, $actualRowHeight, $quantity, 1, 0, 'C');
        $pdf->Cell(30, $actualRowHeight, number_format($unitPrice, 2), 1, 0, 'R');
        $pdf->Cell(25, $actualRowHeight, number_format($totalPrice, 2), 1, 1, 'R');
        
        // Make sure we're at the right Y position
        if ($pdf->GetY() < $startY + $actualRowHeight) {
            $pdf->SetY($startY + $actualRowHeight);
        }
    }
    
    $pdf->Ln(10);
    
    // Get fiscal receipt and taxes BEFORE summary section (for tax breakdown display)
    $primaryDb = Database::getPrimaryInstance();
    $fiscalReceipt = null;
    $fiscalReceiptTaxes = [];
    
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
         FROM fiscal_receipts fr
         LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
         LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
         WHERE fr.sale_id = :sale_id
         LIMIT 1",
        [':sale_id' => $id]
    );
    
    if ($fiscalReceipt) {
        $fiscalReceiptTaxes = $primaryDb->getRows(
            "SELECT tax_code, tax_percent, tax_id, tax_amount, sales_amount_with_tax 
             FROM fiscal_receipt_taxes 
             WHERE fiscal_receipt_id = :fiscal_receipt_id 
             ORDER BY tax_percent ASC, tax_code ASC",
            [':fiscal_receipt_id' => $fiscalReceipt['id']]
        );
        
        // Convert fiscal receipt taxes from payment currency to base currency if needed
        // Fiscal receipt taxes are stored in payment currency, but sale amounts are in base currency
        if (!empty($fiscalReceiptTaxes) && $paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
            // Convert FROM payment currency TO base currency (reverse of exchangeRate)
            $paymentToBaseRate = getExchangeRate($paymentCurrencyId, $baseCurrency['id'], $db);
            foreach ($fiscalReceiptTaxes as &$tax) {
                $tax['tax_amount'] = floatval($tax['tax_amount']) * $paymentToBaseRate;
                $tax['sales_amount_with_tax'] = floatval($tax['sales_amount_with_tax']) * $paymentToBaseRate;
            }
            unset($tax);
        }
    }
    
    // Summary Section - Convert amounts to payment currency if needed
    // Calculate tax amount from fiscal receipt taxes (if available) or use sale tax_amount
    $pdfTaxAmount = 0;
    if (!empty($fiscalReceiptTaxes)) {
        foreach ($fiscalReceiptTaxes as $tax) {
            $pdfTaxAmount += floatval($tax['tax_amount'] ?? 0);
        }
    } else {
        $pdfTaxAmount = floatval($sale['tax_amount'] ?? 0);
    }
    
    $pdfDiscountAmount = floatval($sale['discount_amount'] ?? 0);
    $pdfDeliveryCost = floatval($sale['delivery_cost'] ?? 0);
    $pdfTotalAmount = floatval($sale['total_amount']);
    $pdfDiscountAmount = floatval($sale['discount_amount'] ?? 0);
    
    // CRITICAL FIX: Use sales.total_amount as source of truth, not sum of sale_items
    // Total (Excl. tax) = total_amount - tax_amount - delivery_cost
    $pdfSubtotal = $pdfTotalAmount - $pdfTaxAmount - $pdfDeliveryCost;
    
    if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
        $pdfSubtotal = $pdfSubtotal * $exchangeRate;
        $pdfDiscountAmount = $pdfDiscountAmount * $exchangeRate;
        $pdfDeliveryCost = $pdfDeliveryCost * $exchangeRate;
        $pdfTaxAmount = $pdfTaxAmount * $exchangeRate;
        $pdfTotalAmount = $pdfTotalAmount * $exchangeRate;
    }
    
    if ($pdfDiscountAmount > 0) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(120, 0, '', 0, 0);
        $pdf->Cell(20, 0, '', 0, 0);
        $pdf->Cell(55, 8, 'Discount:', 1, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(25, 8, '-' . number_format($pdfDiscountAmount, 2), 1, 1, 'R');
    }
    
    if ($pdfDeliveryCost > 0) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(120, 0, '', 0, 0);
        $pdf->Cell(20, 0, '', 0, 0);
        $pdf->Cell(55, 8, 'Delivery Cost:', 1, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(25, 8, number_format($pdfDeliveryCost, 2), 1, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(120, 0, '', 0, 0);
    $pdf->Cell(20, 0, '', 0, 0);
    $pdf->Cell(55, 8, 'Total(Excl. tax):', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(25, 8, number_format($pdfSubtotal, 2), 1, 1, 'R');
    
    // Tax Breakdown (if fiscalized)
    if ($fiscalReceipt && !empty($fiscalReceiptTaxes)) {
        // Group taxes by taxPercent and taxCode for display
        $taxGroups = [];
        foreach ($fiscalReceiptTaxes as $tax) {
            $taxPercent = isset($tax['tax_percent']) && $tax['tax_percent'] !== null ? floatval($tax['tax_percent']) : null;
            $taxCode = $tax['tax_code'] ?? '';
            $taxAmount = floatval($tax['tax_amount'] ?? 0);
            
            // Create key for grouping: exempt by code, others by percent
            if ($taxCode === 'E') {
                $key = 'exempt';
            } elseif ($taxPercent === 0.0 || $taxPercent === 0) {
                $key = '0';
            } else {
                $key = strval($taxPercent);
            }
            
            if (!isset($taxGroups[$key])) {
                $taxGroups[$key] = [
                    'taxPercent' => $taxPercent,
                    'taxCode' => $taxCode,
                    'totalAmount' => 0
                ];
            }
            $taxGroups[$key]['totalAmount'] += $taxAmount;
        }
        
        // Sort: exempt first, then 0%, then by percent ascending
        uksort($taxGroups, function($a, $b) {
            if ($a === 'exempt') return -1;
            if ($b === 'exempt') return 1;
            if ($a === '0') return -1;
            if ($b === '0') return 1;
            return floatval($a) <=> floatval($b);
        });
        
        // Display tax breakdowns
        foreach ($taxGroups as $group) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(120, 0, '', 0, 0);
            $pdf->Cell(20, 0, '', 0, 0);
            
            // Format label based on tax type
            if ($group['taxCode'] === 'E') {
                $label = 'Total: Exempt from VAT';
            } elseif ($group['taxPercent'] === 0.0 || $group['taxPercent'] === 0 || $group['taxPercent'] === null) {
                $label = 'Total 0% VAT';
            } else {
                $label = 'Total ' . number_format($group['taxPercent'], 1) . '% VAT';
            }
            
            $pdf->Cell(55, 8, $label . ':', 1, 0, 'L');
            $pdf->SetFont('helvetica', 'B', 9);
            
            // Convert tax amount to payment currency if needed
            $pdfTaxAmount = floatval($group['totalAmount']);
            if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                $pdfTaxAmount = $pdfTaxAmount * $exchangeRate;
            }
            
            $pdf->Cell(25, 8, number_format($pdfTaxAmount, 2), 1, 1, 'R');
        }
    }
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(120, 0, '', 0, 0);
    $pdf->Cell(20, 0, '', 0, 0);
    $pdf->Cell(55, 10, 'Total(Incl. tax):', 1, 0, 'L');
    $pdf->Cell(25, 10, number_format($pdfTotalAmount, 2), 1, 1, 'R');
    
    $pdf->Ln(12);
    
    // Check if this is a credit sale
    $isCreditSale = isset($sale['is_credit_sale']) && $sale['is_credit_sale'] == 1;
    $accountBalance = floatval($sale['account_balance'] ?? 0);
    
    if ($isCreditSale) {
        // Convert account balance to payment currency if needed
        if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
            $accountBalance = $accountBalance * $exchangeRate;
        }
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'Put on Account Billing:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        
        if (!empty($sale['payment_term_name'])) {
            $paymentTermText = $sale['payment_term_name'];
            if (!empty($sale['payment_term_days'])) {
                $paymentTermText .= ' (' . intval($sale['payment_term_days']) . ' days)';
            }
            $pdf->Cell(0, 5, 'Payment Terms: ' . htmlspecialchars($paymentTermText), 0, 1, 'L');
        }
        
        if ($accountBalance > 0) {
            $pdf->Cell(0, 5, 'Account Balance: ' . number_format($accountBalance, 2), 0, 1, 'L');
        } else {
            $pdf->Cell(0, 5, 'Account Balance: ' . number_format($accountBalance, 2) . ' (Fully Paid)', 0, 1, 'L');
        }
    } else {
        // Payment Information
        if (!empty($payments)) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Payment:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 9);
            
            $totalPaid = 0;
            foreach ($payments as $payment) {
                // Use original_amount (payment currency) if available, otherwise convert base_amount
                $amount = isset($payment['original_amount']) ? floatval($payment['original_amount']) : floatval($payment['amount']);
                if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                    // If amount is in base currency, convert to payment currency
                    if (!isset($payment['original_amount'])) {
                        $amount = $amount * $exchangeRate;
                    }
                }
                $totalPaid += $amount;
                
                $currencySymbol = $payment['currency_symbol'] ?? ($paymentCurrency ? $paymentCurrency['symbol'] : '$');
                $currencyCode = $payment['currency_code'] ?? ($paymentCurrency ? $paymentCurrency['code'] : 'USD');
                $symbolPosition = $payment['currency_symbol_position'] ?? ($paymentCurrency ? $paymentCurrency['symbol_position'] : 'before');
                
                $paymentMethod = ucfirst($payment['payment_method']);
                if ($symbolPosition === 'before') {
                    $amountStr = $currencySymbol . ' ' . number_format($amount, 2);
                } else {
                    $amountStr = number_format($amount, 2) . ' ' . $currencySymbol;
                }
                
                $pdf->Cell(0, 5, $paymentMethod . ': ' . $amountStr, 0, 1, 'L');
            }
            
            // Calculate change (use converted total amount)
            $change = $totalPaid - $pdfTotalAmount;
            if ($change > 0) {
                $pdf->Ln(3);
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell(0, 5, 'Change: ' . number_format($change, 2), 0, 1, 'L');
            }
        }
    }
    
    $pdf->Ln(12);
    
    // Fiscal Information Section (if fiscalized)
    // Note: $fiscalReceipt was already fetched above for tax breakdown, reuse it here
    $fiscalDetails = null;
    
    if ($fiscalReceipt) {
        // Build fiscal details from fiscal receipt
        $fiscalDetails = [
            'receipt_global_no' => $fiscalReceipt['receipt_global_no'],
            'device_id' => $fiscalReceipt['device_id'],
            'verification_code' => $fiscalReceipt['receipt_verification_code'],
            'qr_code' => $fiscalReceipt['receipt_qr_data']
        ];
        $sale['fiscalized'] = 1;
    } elseif (!empty($sale['fiscal_details'])) {
        // Fallback: use fiscal_details from sale record
        $fiscalDetails = json_decode($sale['fiscal_details'], true);
        
        if ($fiscalDetails && isset($fiscalDetails['receipt_global_no'])) {
            $fiscalReceipt = $primaryDb->getRow(
                "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
                 FROM fiscal_receipts fr
                 LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
                 LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
                 WHERE fr.receipt_global_no = :receipt_global_no AND fr.device_id = :device_id
                 LIMIT 1",
                [
                    ':receipt_global_no' => $fiscalDetails['receipt_global_no'],
                    ':device_id' => $fiscalDetails['device_id'] ?? null
                ]
            );
        }
    }
    
    if ($fiscalDetails && $fiscalReceipt) {
        $pdf->Ln(10);
        
        // QR Code (CENTERED, BIGGER - according to documentation for A4)
        $qrCodeDisplayed = false;
        
        // First, try to use stored QR code image if available
        if (isset($fiscalReceipt['receipt_qr_code']) && !empty($fiscalReceipt['receipt_qr_code']) && strlen($fiscalReceipt['receipt_qr_code']) > 0) {
            try {
                // receipt_qr_code is stored as base64 encoded PNG image
                $qrImageData = base64_decode($fiscalReceipt['receipt_qr_code']);
                
                if ($qrImageData !== false && strlen($qrImageData) > 0) {
                    // It's a base64 encoded PNG, write to temp file and use it
                    $tempQrFile = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
                    file_put_contents($tempQrFile, $qrImageData);
                    
                    // Center and make bigger (30mm instead of 20mm)
                    // Page width: 210mm, left margin: 15mm, right margin: 15mm
                    // Content width: 210 - 15 - 15 = 180mm
                    // Center of content: 15 + (180 / 2) = 105mm
                    // QR left position: 105 - (30 / 2) = 90mm
                    $qrSize = 30; // mm
                    $qrX = 15 + ((210 - 15 - 15) / 2) - ($qrSize / 2); // Properly centered: 90mm
                    $qrY = $pdf->GetY();
                    $pdf->Image($tempQrFile, $qrX, $qrY, $qrSize, $qrSize, 'PNG', '', '', false, 300, '', false, false, 0);
                    $pdf->SetY($qrY + $qrSize + 5);
                    @unlink($tempQrFile);
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code image error: " . $e->getMessage());
            }
        }
        
        // Fallback: Generate QR code on-the-fly from receipt_qr_data
        if (!$qrCodeDisplayed && isset($fiscalReceipt['receipt_qr_data']) && !empty($fiscalReceipt['receipt_qr_data'])) {
            try {
                // Build full QR URL from qr_data
                $qrUrl = $fiscalReceipt['qr_url'] ?? 'https://fdmstest.zimra.co.zw';
                $deviceId = $fiscalReceipt['device_id'] ?? '';
                $receiptDate = $fiscalReceipt['receipt_date'] ?? '';
                $receiptGlobalNo = $fiscalReceipt['receipt_global_no'] ?? '';
                
                if ($deviceId && $receiptDate && $receiptGlobalNo) {
                    $deviceIdFormatted = str_pad($deviceId, 10, '0', STR_PAD_LEFT);
                    $date = new DateTime($receiptDate);
                    $receiptDateFormatted = $date->format('dmy');
                    $receiptGlobalNoFormatted = str_pad($receiptGlobalNo, 10, '0', STR_PAD_LEFT);
                    $qrDataFormatted = substr($fiscalReceipt['receipt_qr_data'], 0, 16);
                    $qrCodeString = rtrim($qrUrl, '/') . '/' . $deviceIdFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $qrDataFormatted;
                    
                    // Use TCPDF's built-in QR code support - CENTERED and BIGGER
                    $style = array(
                        'border' => false,
                        'padding' => 0,
                        'fgcolor' => array(0,0,0),
                        'bgcolor' => false,
                        'module_width' => 1,
                        'module_height' => 1
                    );
                    $qrSize = 30; // mm - bigger
                    // Page width: 210mm, left margin: 15mm, right margin: 15mm
                    // Content width: 210 - 15 - 15 = 180mm
                    // Center of content: 15 + (180 / 2) = 105mm
                    // QR left position: 105 - (30 / 2) = 90mm
                    $qrX = 15 + ((210 - 15 - 15) / 2) - ($qrSize / 2); // Properly centered: 90mm
                    $qrY = $pdf->GetY();
                    $pdf->write2DBarcode($qrCodeString, 'QRCODE,L', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');
                    $pdf->SetY($qrY + $qrSize + 5);
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code generation error: " . $e->getMessage());
            }
        }
        
        // Verification Code (BELOW QR CODE - according to documentation)
        if (isset($fiscalDetails['verification_code'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Verification code: ' . $fiscalDetails['verification_code'], 0, 1, 'C');
        }
        
        // Verification URL
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 4, 'You can verify this receipt manually at', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'U', 8);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell(0, 4, 'https://receipt.zimra.org/', 0, 1, 'C', false, 'https://receipt.zimra.org/');
        $pdf->SetTextColor(0, 0, 0);
        
        $pdf->Ln(5);
    }
    
    // Footer
    $receiptFooterText = getSetting('pos_receipt_footer_text', 'Thank you for your business!');
    if ($receiptFooterText) {
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(8);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, htmlspecialchars($receiptFooterText), 0, 1, 'C');
    }
    
    // Output PDF
    $pdf->Output('Receipt_' . $sale['receipt_number'] . '.pdf', 'D');
    exit;
}

$pageTitle = 'Receipt #' . escapeHtml($sale['receipt_number']);
require_once APP_PATH . '/includes/header.php';
?>

<style>
/* Receipt container styles (similar to receipt.php) */
.receipt-container {
    max-width: 400px;
    margin: 0 auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

/* Override main CSS to allow scrolling */
body, html {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    height: auto !important;
}

.main-content {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    height: 100vh !important;
}

.content-area {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    padding: 30px !important;
    padding-top: 10px !important;
    justify-content: flex-start !important;
    min-height: auto !important;
    position: relative !important;
    overflow: visible !important;
    overflow-x: hidden !important;
    overflow-y: visible !important;
    flex: none !important;
    height: auto !important;
}

.receipt-container {
    max-width: 400px;
    margin: 0 auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: visible !important;
    position: relative;
    width: 100%;
}

/* Action buttons - normal flow, no sticky */
.no-print {
    text-align: center;
    padding: 20px 0;
    margin-bottom: 20px;
}

.receipt-header {
    text-align: center;
    border-bottom: 2px solid #1e3a8a;
    padding-bottom: 15px;
    margin-bottom: 15px;
}

.receipt-header img {
    display: block;
    margin: 0 auto 15px;
    max-width: 200px;
    max-height: 80px;
    object-fit: contain;
}

.receipt-header h2 {
    margin: 0 0 8px 0;
    color: #1e3a8a;
    font-size: 20px;
}

.receipt-header .company-info {
    font-size: 10px;
    line-height: 1.4;
}

.receipt-info {
    margin: 12px 0;
    font-size: 11px;
    line-height: 1.6;
}

.receipt-info div {
    margin-bottom: 4px;
}

.receipt-info strong {
    display: inline-block;
    min-width: 80px;
}

.receipt-container table {
    width: 100%;
    border-collapse: collapse;
    margin: 12px 0;
    font-size: 11px;
    table-layout: auto;
}

.receipt-container table th, 
.receipt-container table td {
    padding: 6px 8px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.receipt-container table th {
    background: #f3f4f6;
    font-weight: bold;
}

/* Responsive table columns */
.receipt-container table colgroup col:nth-child(1) {
    min-width: 120px;
}

.receipt-container table colgroup col:nth-child(2) {
    width: 60px;
    min-width: 50px;
}

.receipt-container table colgroup col:nth-child(3),
.receipt-container table colgroup col:nth-child(4) {
    width: 90px;
    min-width: 70px;
}

/* Ensure item names wrap properly */
.receipt-container table tbody td:first-child {
    word-wrap: break-word;
    word-break: break-word;
    white-space: normal;
    line-height: 1.4;
}

/* Prevent Qty, Price, Total from wrapping */
.receipt-container table tbody td:nth-child(2),
.receipt-container table tbody td:nth-child(3),
.receipt-container table tbody td:nth-child(4) {
    white-space: nowrap;
}

/* Responsive adjustments for smaller screens */
@media (max-width: 480px) {
    .receipt-container {
        padding: 20px 15px;
    }
    
    .receipt-container table {
        font-size: 10px;
    }
    
    .receipt-container table th, 
    .receipt-container table td {
        padding: 5px 6px;
    }
    
    .receipt-container table colgroup col:nth-child(1) {
        min-width: 100px;
    }
    
    .receipt-container table colgroup col:nth-child(2) {
        width: 50px;
        min-width: 45px;
    }
    
    .receipt-container table colgroup col:nth-child(3),
    .receipt-container table colgroup col:nth-child(4) {
        width: 75px;
        min-width: 65px;
    }
}

.receipt-container .total-row {
    font-weight: bold;
    font-size: 14px;
    border-top: 2px solid #1e3a8a;
}

.receipt-footer {
    text-align: center;
    margin-top: 15px;
    padding-top: 12px;
    border-top: 2px solid #1e3a8a;
    font-size: 11px;
}

/* ========== PRINT STYLES ========== */
@media print {
    @page {
        size: 80mm auto;
        margin: 0;
    }
    
    .sidebar,
    .topbar,
    .no-print,
    .no-print * {
        display: none !important;
    }
    
    body {
        margin: 0;
        padding: 0;
        font-size: 12px;
        background: white !important;
    }
    
    .content-area {
        display: block !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    
    .receipt-container {
        max-width: 80mm !important;
        width: 80mm !important;
        margin: 0 auto !important;
        padding: 10mm 5mm !important;
        box-shadow: none !important;
        border: none !important;
        border-radius: 0 !important;
        display: block !important;
    }
    
    .receipt-container * {
        visibility: visible !important;
    }
    
    .receipt-header {
        border-bottom: 1px solid #000 !important;
        padding-bottom: 8px !important;
        margin-bottom: 8px !important;
        display: block !important;
    }
    
    .receipt-header img {
        max-width: 150px !important;
        max-height: 60px !important;
        margin-bottom: 8px !important;
    }
    
    .receipt-header h2 {
        color: #000 !important;
        font-size: 16px !important;
        display: block !important;
    }
    
    .receipt-header .company-info {
        font-size: 9px !important;
        display: block !important;
    }
    
    .receipt-info {
        margin: 8px 0 !important;
        font-size: 9px !important;
        display: block !important;
    }
    
    .receipt-info div {
        display: block !important;
    }
    
    .receipt-container table {
        margin: 8px 0 !important;
        font-size: 9px !important;
        display: table !important;
        width: 100% !important;
    }
    
    .receipt-container table thead,
    .receipt-container table tbody,
    .receipt-container table tfoot {
        display: table-row-group !important;
    }
    
    .receipt-container table tr {
        display: table-row !important;
    }
    
    .receipt-container table th, 
    .receipt-container table td {
        padding: 3px 2px !important;
        border-bottom: 1px dashed #ccc !important;
        display: table-cell !important;
    }
    
    .receipt-container table th {
        background: transparent !important;
        border-bottom: 1px solid #000 !important;
    }
    
    .receipt-container .total-row {
        font-size: 11px !important;
        border-top: 1px solid #000 !important;
    }
    
    .receipt-footer {
        margin-top: 10px !important;
        padding-top: 8px !important;
        border-top: 1px solid #000 !important;
        font-size: 9px !important;
        display: block !important;
    }
    
    .content-area {
        padding: 0 !important;
        margin: 0 !important;
    }
}
</style>

<div class="content-area">
    <!-- Action Buttons - Top -->
    <div class="no-print mb-4" style="text-align: center; padding: 20px 0; margin-bottom: 20px;">
        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="<?= BASE_URL ?>modules/pos/index.php?payment_success=1&receipt_id=<?= $id ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to POS
            </a>
            <button class="btn btn-primary" onclick="showEmailModal()">
                <i class="bi bi-envelope"></i> Send via Email
            </button>
            <button class="btn btn-success" onclick="showWhatsAppModal()">
                <i class="bi bi-whatsapp"></i> Send via WhatsApp
            </button>
            <button class="btn btn-info" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
            <a href="receipt.php?id=<?= $id ?>&pdf=1" class="btn btn-secondary">
                <i class="bi bi-file-earmark-pdf"></i> Export A4 PDF
            </a>
            <?php if ($sale['payment_status'] !== 'refunded'): ?>
                <button class="btn btn-warning" onclick="refundSale(<?= $id ?>)">
                    <i class="bi bi-arrow-counterclockwise"></i> Refund
                </button>
                <button class="btn btn-danger" onclick="deleteReceipt(<?= $id ?>)">
                    <i class="bi bi-trash"></i> Delete
                </button>
            <?php else: ?>
                <span class="badge bg-danger">Refunded</span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Receipt Container - Below Buttons -->
    <div class="receipt-container">
    
    <div class="receipt-header">
        <?php if ($receiptLogoPath && !empty($receiptLogoPath)): 
            $logoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
            $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
            if (file_exists($logoFullPath)): ?>
                <div style="text-align: center; margin-bottom: 15px;">
                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" style="max-width: 200px; max-height: 80px; object-fit: contain;" onerror="this.style.display='none';">
                </div>
        <?php endif; endif; ?>
        <h2><?= escapeHtml($companyName) ?></h2>
        <div class="company-info">
            <?php if ($companyAddress): ?>
                <?= escapeHtml($companyAddress) ?><br>
            <?php endif; ?>
            <?php if ($companyPhone): ?>
                Phone: <?= escapeHtml($companyPhone) ?><br>
            <?php endif; ?>
            <?php if ($companyEmail): ?>
                <?= escapeHtml($companyEmail) ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="receipt-info">
        <div><strong>Receipt #:</strong> <?= escapeHtml($sale['receipt_number']) ?></div>
        <div><strong>Date:</strong> <?= formatDateTime($sale['sale_date']) ?></div>
        <div><strong>Cashier:</strong> <?= escapeHtml(($sale['cashier_first'] ?? '') . ' ' . ($sale['cashier_last'] ?? '')) ?></div>
    </div>
    
    <?php if ($sale['first_name'] || $sale['last_name'] || $sale['company_name'] || !empty($sale['phone']) || !empty($sale['address']) || !empty($sale['email']) || !empty($sale['tin']) || !empty($sale['vat_number'])): ?>
    <div class="customer-details-section" style="margin: 12px 0; padding: 0; font-size: 11px; line-height: 1.6;">
        <div style="font-weight: bold; margin-bottom: 4px;">Customer Details:</div>
        <?php 
        $customerName = trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? ''));
        $displayName = $customerName ?: ($sale['company_name'] ?? 'Walk-in');
        ?>
        <div style="margin-bottom: 4px;"><strong>Name:</strong> <?= escapeHtml($displayName) ?></div>
        <?php if (!empty($sale['phone'])): ?>
            <div style="margin-bottom: 4px;"><strong>Phone:</strong> <?= escapeHtml($sale['phone']) ?></div>
        <?php endif; ?>
        <?php if (!empty($sale['email'])): ?>
            <div style="margin-bottom: 4px;"><strong>Email:</strong> <?= escapeHtml($sale['email']) ?></div>
        <?php endif; ?>
        <?php if (!empty($sale['address'])): ?>
            <div style="margin-bottom: 4px;"><strong>Address:</strong> <?= escapeHtml($sale['address']) ?><?= !empty($sale['city']) ? ', ' . escapeHtml($sale['city']) : '' ?></div>
        <?php endif; ?>
        <?php if (!empty($sale['tin']) || !empty($sale['vat_number'])): ?>
            <div style="margin-top: 6px; padding-top: 6px; border-top: 1px solid #ddd;">
                <div style="font-weight: bold; margin-bottom: 4px;">Tax Details:</div>
                <?php if (!empty($sale['tin'])): ?>
                    <div style="margin-bottom: 4px;"><strong>TIN:</strong> <?= escapeHtml($sale['tin']) ?></div>
                <?php endif; ?>
                <?php if (!empty($sale['vat_number'])): ?>
                    <div style="margin-bottom: 4px;"><strong>VAT Number:</strong> <?= escapeHtml($sale['vat_number']) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php
    // Get fiscal receipt data BEFORE the table so taxes can be displayed in the tfoot
    $primaryDb = Database::getPrimaryInstance();
    $fiscalReceipt = null;
    $fiscalReceiptTaxes = [];
    
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
         FROM fiscal_receipts fr
         LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
         LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
         WHERE fr.sale_id = :sale_id
         LIMIT 1",
        [':sale_id' => $id]
    );
    
    if ($fiscalReceipt) {
        $fiscalReceiptTaxes = $primaryDb->getRows(
            "SELECT tax_code, tax_percent, tax_id, tax_amount, sales_amount_with_tax 
             FROM fiscal_receipt_taxes 
             WHERE fiscal_receipt_id = :fiscal_receipt_id 
             ORDER BY tax_percent ASC, tax_code ASC",
            [':fiscal_receipt_id' => $fiscalReceipt['id']]
        );
        
        // Convert fiscal receipt taxes from payment currency to base currency if needed
        // Fiscal receipt taxes are stored in payment currency, but sale amounts are in base currency
        if (!empty($fiscalReceiptTaxes) && $paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
            // Convert FROM payment currency TO base currency (reverse of exchangeRate)
            $paymentToBaseRate = getExchangeRate($paymentCurrencyId, $baseCurrency['id'], $db);
            foreach ($fiscalReceiptTaxes as &$tax) {
                $tax['tax_amount'] = floatval($tax['tax_amount']) * $paymentToBaseRate;
                $tax['sales_amount_with_tax'] = floatval($tax['sales_amount_with_tax']) * $paymentToBaseRate;
            }
            unset($tax);
        }
    }
    ?>
    
    <table style="width: 100%; border-collapse: collapse; table-layout: auto;">
        <colgroup>
            <col style="min-width: 150px;">
            <col style="width: 60px; min-width: 50px;">
            <col style="width: 90px; min-width: 70px;">
            <col style="width: 90px; min-width: 70px;">
        </colgroup>
        <thead>
            <tr>
                <th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; white-space: normal;">Description</th>
                <th style="text-align: center; padding: 6px 8px; border-bottom: 1px solid #ddd; white-space: nowrap;">Qty</th>
                <th style="text-align: right; padding: 6px 8px; border-bottom: 1px solid #ddd; white-space: nowrap;">Price</th>
                <th style="text-align: right; padding: 6px 8px; border-bottom: 1px solid #ddd; white-space: nowrap;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            // Convert items to payment currency if different from base
            foreach ($items as $item): 
                $unitPrice = floatval($item['unit_price']);
                $totalPrice = floatval($item['total_price']);
                
                // Convert to payment currency if needed (base currency -> payment currency)
                if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                    // Convert from base to payment currency (multiply by exchange rate)
                    $unitPrice = $unitPrice * $exchangeRate;
                    $totalPrice = $totalPrice * $exchangeRate;
                }
                
                // Format with payment currency
                $unitPriceFormatted = $paymentCurrency ? formatCurrencyAmount($unitPrice, $paymentCurrencyId, $db) : formatCurrency($unitPrice);
                $totalPriceFormatted = $paymentCurrency ? formatCurrencyAmount($totalPrice, $paymentCurrencyId, $db) : formatCurrency($totalPrice);
            ?>
                <tr>
                    <td style="text-align: left; padding: 6px 8px; word-wrap: break-word; word-break: break-word; border-bottom: 1px solid #ddd; white-space: normal; line-height: 1.4;">
                        <?php
                        $description = escapeHtml($item['product_name']);
                        if (!empty($item['specific_list_entries'])) {
                            $details = [];
                            foreach ($item['specific_list_entries'] as $entry) {
                                $entryDetails = [];
                                // Always check for serial_number and imei - they might be in the database
                                if (isset($entry['serial_number']) && $entry['serial_number'] !== null && trim($entry['serial_number']) !== '') {
                                    $entryDetails[] = "S/N: " . escapeHtml(trim($entry['serial_number']));
                                }
                                if (isset($entry['imei']) && $entry['imei'] !== null && trim($entry['imei']) !== '') {
                                    $entryDetails[] = "IMEI: " . escapeHtml(trim($entry['imei']));
                                }
                                // Only add color and storage if serial/IMEI are present, or if they're the only details
                                if (!empty($entryDetails)) {
                                    // Add color and storage if available
                                    if (isset($entry['color']) && $entry['color'] !== null && trim($entry['color']) !== '') {
                                        $colorValue = trim($entry['color']);
                                        if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $colorValue)) {
                                            $entryDetails[] = "Color: " . escapeHtml($colorValue);
                                        }
                                    }
                                    if (isset($entry['storage']) && $entry['storage'] !== null && trim($entry['storage']) !== '') {
                                        $entryDetails[] = "Storage: " . escapeHtml(trim($entry['storage']));
                                    }
                                    $details[] = implode(", ", $entryDetails);
                                } elseif ((isset($entry['color']) && $entry['color'] !== null && trim($entry['color']) !== '') || 
                                          (isset($entry['storage']) && $entry['storage'] !== null && trim($entry['storage']) !== '')) {
                                    // If no serial/IMEI but has color/storage, show those
                                    $entryDetails = [];
                                    if (isset($entry['color']) && $entry['color'] !== null && trim($entry['color']) !== '') {
                                        $colorValue = trim($entry['color']);
                                        if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $colorValue)) {
                                            $entryDetails[] = "Color: " . escapeHtml($colorValue);
                                        }
                                    }
                                    if (isset($entry['storage']) && $entry['storage'] !== null && trim($entry['storage']) !== '') {
                                        $entryDetails[] = "Storage: " . escapeHtml(trim($entry['storage']));
                                    }
                                    if (!empty($entryDetails)) {
                                        $details[] = implode(", ", $entryDetails);
                                    }
                                }
                            }
                            if (!empty($details)) {
                                $description .= " (" . implode("; ", $details) . ")";
                            }
                        }
                        echo $description;
                        ?>
                    </td>
                    <td style="text-align: center; padding: 6px 8px; border-bottom: 1px solid #ddd; white-space: nowrap;"><?= $item['quantity'] ?></td>
                    <td style="text-align: right; padding: 6px 8px; border-bottom: 1px solid #ddd; white-space: nowrap;"><?= $unitPriceFormatted ?></td>
                    <td style="text-align: right; padding: 6px 8px; border-bottom: 1px solid #ddd; white-space: nowrap;"><?= $totalPriceFormatted ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php
            // Calculate tax amount from fiscal receipt taxes (if available) or use sale tax_amount
            $taxAmount = 0;
            if (!empty($fiscalReceiptTaxes)) {
                foreach ($fiscalReceiptTaxes as $tax) {
                    $taxAmount += floatval($tax['tax_amount'] ?? 0);
                }
            } else {
                $taxAmount = floatval($sale['tax_amount'] ?? 0);
            }
            
            $discountAmount = floatval($sale['discount_amount'] ?? 0);
            $deliveryCost = floatval($sale['delivery_cost'] ?? 0);
            $totalAmount = floatval($sale['total_amount']);
            
            // CRITICAL FIX: Use sales.total_amount as source of truth, not sum of sale_items
            // This ensures totals match what was actually charged, especially when product_specific_items have different prices
            // Total (Excl. tax) = total_amount - tax_amount - delivery_cost
            $subtotal = $totalAmount - $taxAmount - $deliveryCost;
            
            // Convert amounts to payment currency if needed
            if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                $subtotal = $subtotal * $exchangeRate;
                $discountAmount = $discountAmount * $exchangeRate;
                $deliveryCost = $deliveryCost * $exchangeRate;
                $taxAmount = $taxAmount * $exchangeRate;
                $totalAmount = $totalAmount * $exchangeRate;
            }
            
            $subtotalFormatted = $paymentCurrency ? formatCurrencyAmount($subtotal, $paymentCurrencyId, $db) : formatCurrency($subtotal);
            $discountFormatted = $paymentCurrency ? formatCurrencyAmount($discountAmount, $paymentCurrencyId, $db) : formatCurrency($discountAmount);
            $deliveryCostFormatted = $paymentCurrency ? formatCurrencyAmount($deliveryCost, $paymentCurrencyId, $db) : formatCurrency($deliveryCost);
            $totalFormatted = $paymentCurrency ? formatCurrencyAmount($totalAmount, $paymentCurrencyId, $db) : formatCurrency($totalAmount);
            ?>
            <?php if ($discountAmount > 0): ?>
                <tr>
                    <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>Discount:</strong></td>
                    <td style="text-align: right; padding: 6px 4px;"><strong>-<?= $discountFormatted ?></strong></td>
                </tr>
            <?php endif; ?>
            <?php if ($deliveryCost > 0): ?>
                <tr>
                    <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>Delivery Cost:</strong></td>
                    <td style="text-align: right; padding: 6px 4px;"><strong><?= $deliveryCostFormatted ?></strong></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>Total(Excl. tax):</strong></td>
                <td style="text-align: right; padding: 6px 4px;"><strong><?= $subtotalFormatted ?></strong></td>
            </tr>
            <?php
            // Tax Breakdown (if fiscalized) - get from fiscal receipt taxes
            if (!empty($fiscalReceiptTaxes)) {
                // Group taxes by taxPercent and taxCode for display
                $taxGroups = [];
                foreach ($fiscalReceiptTaxes as $tax) {
                    $taxPercent = isset($tax['tax_percent']) && $tax['tax_percent'] !== null ? floatval($tax['tax_percent']) : null;
                    $taxCode = $tax['tax_code'] ?? '';
                    $taxAmount = floatval($tax['tax_amount'] ?? 0);
                    
                    // Create key for grouping: exempt by code, others by percent
                    if ($taxCode === 'E') {
                        $key = 'exempt';
                    } elseif ($taxPercent === 0.0 || $taxPercent === 0) {
                        $key = '0';
                    } else {
                        $key = strval($taxPercent);
                    }
                    
                    if (!isset($taxGroups[$key])) {
                        $taxGroups[$key] = [
                            'taxPercent' => $taxPercent,
                            'taxCode' => $taxCode,
                            'totalAmount' => 0
                        ];
                    }
                    $taxGroups[$key]['totalAmount'] += $taxAmount;
                }
                
                // Sort: exempt first, then 0%, then by percent ascending
                uksort($taxGroups, function($a, $b) {
                    if ($a === 'exempt') return -1;
                    if ($b === 'exempt') return 1;
                    if ($a === '0') return -1;
                    if ($b === '0') return 1;
                    return floatval($a) <=> floatval($b);
                });
                
                    // Display tax breakdowns
                    foreach ($taxGroups as $group):
                        // Format label based on tax type
                        if ($group['taxCode'] === 'E') {
                            $label = 'Total: Exempt from VAT';
                        } elseif ($group['taxPercent'] === 0.0 || $group['taxPercent'] === 0 || $group['taxPercent'] === null) {
                            $label = 'Total 0% VAT';
                        } else {
                            $label = 'Total ' . number_format($group['taxPercent'], 1) . '% VAT';
                        }
                        
                        // Convert tax amount to payment currency if needed
                        $groupTaxAmount = $group['totalAmount'];
                        if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                            $groupTaxAmount = $groupTaxAmount * $exchangeRate;
                        }
                        $groupTaxFormatted = $paymentCurrency ? formatCurrencyAmount($groupTaxAmount, $paymentCurrencyId, $db) : formatCurrency($groupTaxAmount);
                        ?>
                            <tr>
                                <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong><?= escapeHtml($label) ?>:</strong></td>
                                <td style="text-align: right; padding: 6px 4px;"><strong><?= $groupTaxFormatted ?></strong></td>
                            </tr>
                        <?php
                endforeach;
            }
            ?>
            <tr class="total-row">
                <td colspan="3" style="text-align: right; padding: 6px 4px; border-top: 2px solid #333;"><strong>Total(Incl. tax):</strong></td>
                <td style="text-align: right; padding: 6px 4px; border-top: 2px solid #333;"><strong><?= $totalFormatted ?></strong></td>
            </tr>
            <?php 
            // Check if this is a credit sale
            $isCreditSale = isset($sale['is_credit_sale']) && $sale['is_credit_sale'] == 1;
            $accountBalance = floatval($sale['account_balance'] ?? 0);
            
            if ($isCreditSale):
                // Convert account balance to payment currency if needed
                if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                    $accountBalance = $accountBalance * $exchangeRate;
                }
                $accountBalanceFormatted = $paymentCurrency ? formatCurrencyAmount($accountBalance, $paymentCurrencyId, $db) : formatCurrency($accountBalance);
            ?>
                <tr>
                    <td colspan="4" style="padding: 6px 4px; padding-top: 8px;">
                        <strong>Put on Account Billing:</strong><br>
                        <div style="margin-left: 10px;">
                            <?php if (!empty($sale['payment_term_name'])): ?>
                                <div><strong>Payment Terms:</strong> <?= escapeHtml($sale['payment_term_name']) ?>
                                <?php if (!empty($sale['payment_term_days'])): ?>
                                    (<?= intval($sale['payment_term_days']) ?> days)
                                <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($accountBalance > 0): ?>
                                <div><strong>Account Balance:</strong> <?= $accountBalanceFormatted ?></div>
                            <?php else: ?>
                                <div><strong>Account Balance:</strong> <?= $accountBalanceFormatted ?> (Fully Paid)</div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="padding: 6px 4px; padding-top: 8px;">
                        <strong>Payment:</strong><br>
                        <?php 
                        $totalPaid = 0;
                        if (empty($payments)): 
                        ?>
                            <div style="margin-left: 10px; color: #999; font-style: italic;">No payment information available</div>
                        <?php else: 
                            foreach ($payments as $payment): 
                                // Use base_amount if available, otherwise use amount
                                $paymentAmount = isset($payment['base_amount']) ? floatval($payment['base_amount']) : floatval($payment['amount']);
                                $totalPaid += $paymentAmount;
                                
                                // Display original amount and currency if different from base
                                $displayAmount = isset($payment['original_amount']) ? floatval($payment['original_amount']) : floatval($payment['amount']);
                                $currencyCode = $payment['currency_code'] ?? ($baseCurrency ? $baseCurrency['code'] : 'USD');
                                $currencySymbol = $payment['currency_symbol'] ?? ($baseCurrency ? $baseCurrency['symbol'] : '$');
                                $symbolPosition = $payment['currency_symbol_position'] ?? ($baseCurrency ? $baseCurrency['symbol_position'] : 'before');
                                
                                if ($symbolPosition === 'before') {
                                    $formattedAmount = $currencySymbol . number_format($displayAmount, 2);
                                } else {
                                    $formattedAmount = number_format($displayAmount, 2) . ' ' . $currencySymbol;
                                }
                        ?>
                            <div style="margin-left: 10px;">
                                <?= escapeHtml(ucfirst($payment['payment_method'])) ?>: <?= $formattedAmount ?>
                                <?php if ($currencyCode && $currencyCode !== ($baseCurrency ? $baseCurrency['code'] : 'USD')): ?>
                                    <span style="font-size: 0.9em; color: #666;">(<?= escapeHtml($currencyCode) ?>)</span>
                                <?php endif; ?>
                            </div>
                        <?php 
                            endforeach; 
                        endif; 
                        ?>
                    </td>
                </tr>
                <?php 
                // Calculate change if amount paid exceeds total (convert to payment currency if needed)
                $change = $totalPaid - $totalAmount; // Use converted totalAmount
                if ($change > 0): 
                    $changeFormatted = $paymentCurrency ? formatCurrencyAmount($change, $paymentCurrencyId, $db) : formatCurrency($change);
                ?>
                    <tr>
                        <td colspan="3" style="text-align: right; padding: 6px 4px; padding-top: 8px;"><strong>Change:</strong></td>
                        <td style="text-align: right; padding: 6px 4px; padding-top: 8px;"><strong><?= $changeFormatted ?></strong></td>
                    </tr>
                <?php endif; ?>
            <?php endif; ?>
        </tfoot>
    </table>
    
    <?php
    // Fiscal Information Section (for HTML view)
    if (!$usePDF) {
        // Get fiscal receipt data (same logic as PDF view)
        $primaryDb = Database::getPrimaryInstance();
        $fiscalDetails = null;
        $fiscalReceipt = null;
        
        // First, try to get fiscal receipt by sale_id
        $fiscalReceipt = $primaryDb->getRow(
            "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
             FROM fiscal_receipts fr
             LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
             LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
             WHERE fr.sale_id = :sale_id
             LIMIT 1",
            [':sale_id' => $id]
        );
        
        if ($fiscalReceipt) {
            // Build fiscal details from fiscal receipt
            $fiscalDetails = [
                'receipt_global_no' => $fiscalReceipt['receipt_global_no'],
                'device_id' => $fiscalReceipt['device_id'],
                'verification_code' => $fiscalReceipt['receipt_verification_code'],
                'qr_code' => $fiscalReceipt['receipt_qr_data']
            ];
        } elseif (!empty($sale['fiscal_details'])) {
            // Fallback: use fiscal_details from sale record
            $fiscalDetails = json_decode($sale['fiscal_details'], true);
            
            if ($fiscalDetails && isset($fiscalDetails['receipt_global_no'])) {
                $fiscalReceipt = $primaryDb->getRow(
                    "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
                     FROM fiscal_receipts fr
                     LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
                     LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
                     WHERE fr.receipt_global_no = :receipt_global_no AND fr.device_id = :device_id
                     LIMIT 1",
                    [
                        ':receipt_global_no' => $fiscalDetails['receipt_global_no'],
                        ':device_id' => $fiscalDetails['device_id'] ?? null
                    ]
                );
            }
        }
        
        // Display fiscal information if available
        if ($fiscalDetails && $fiscalReceipt):
    ?>
    <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd;">
        <?php
        // QR Code Display (FIRST - according to documentation)
        $qrCodeDisplayed = false;
        
        // First, try to use stored QR code image if available
        if (isset($fiscalReceipt['receipt_qr_code']) && !empty($fiscalReceipt['receipt_qr_code']) && strlen($fiscalReceipt['receipt_qr_code']) > 0) {
            try {
                $qrImageData = base64_decode($fiscalReceipt['receipt_qr_code']);
                if ($qrImageData !== false && strlen($qrImageData) > 0) {
                    $qrImageBase64 = base64_encode($qrImageData);
                    echo '<div style="text-align: center; margin: 10px 0;">';
                    echo '<img src="data:image/png;base64,' . htmlspecialchars($qrImageBase64) . '" alt="QR Code" style="max-width: 120px; height: auto; border: 1px solid #ddd;">';
                    echo '</div>';
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code image error: " . $e->getMessage());
            }
        }
        
        // Fallback: Generate QR code URL for display
        if (!$qrCodeDisplayed && isset($fiscalReceipt['receipt_qr_data']) && !empty($fiscalReceipt['receipt_qr_data'])) {
            $qrUrl = $fiscalReceipt['qr_url'] ?? 'https://fdmstest.zimra.co.zw';
            $deviceId = $fiscalReceipt['device_id'] ?? '';
            $receiptDate = $fiscalReceipt['receipt_date'] ?? '';
            $receiptGlobalNo = $fiscalReceipt['receipt_global_no'] ?? '';
            
            if ($deviceId && $receiptDate && $receiptGlobalNo) {
                $deviceIdFormatted = str_pad($deviceId, 10, '0', STR_PAD_LEFT);
                $date = new DateTime($receiptDate);
                $receiptDateFormatted = $date->format('dmy');
                $receiptGlobalNoFormatted = str_pad($receiptGlobalNo, 10, '0', STR_PAD_LEFT);
                $qrDataFormatted = substr($fiscalReceipt['receipt_qr_data'], 0, 16);
                $qrCodeString = rtrim($qrUrl, '/') . '/' . $deviceIdFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $qrDataFormatted;
                
                // Use a QR code API service to generate the image
                $qrCodeApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($qrCodeString);
                echo '<div style="text-align: center; margin: 10px 0;">';
                echo '<img src="' . htmlspecialchars($qrCodeApiUrl) . '" alt="QR Code" style="max-width: 120px; height: auto; border: 1px solid #ddd;">';
                echo '</div>';
            }
        }
        
        // Verification Code (BELOW QR CODE - according to documentation)
        if (isset($fiscalDetails['verification_code'])): ?>
            <div style="text-align: center; margin: 8px 0; font-weight: bold; font-size: 10px;">
                Verification code: <?= escapeHtml($fiscalDetails['verification_code']) ?>
            </div>
        <?php endif; ?>
        
        <!-- Verification URL -->
        <div style="text-align: center; margin: 5px 0; font-size: 9px; color: #666;">
            You can verify this receipt manually at<br>
            <a href="https://receipt.zimra.org/" target="_blank" style="color: #1e3a8a; text-decoration: underline;">https://receipt.zimra.org/</a>
        </div>
    </div>
    <?php
        endif;
    }
    ?>
    
    <div class="receipt-footer">
        <div style="margin-bottom: 5px;">Thank you for your business!</div>
        <div>
            <?= SYSTEM_NAME ?> - <?= SYSTEM_VERSION ?? '1.0.0' ?>
        </div>
    </div>
    </div>
</div>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailModalLabel">Send Receipt via Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="emailForm">
                    <input type="hidden" name="receipt_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label for="emailAddress" class="form-label">Email Address *</label>
                        <input type="email" class="form-control" id="emailAddress" name="email" 
                               value="<?= escapeHtml($sale['email'] ?? '') ?>" required>
                        <small class="text-muted">Enter the email address to send the receipt to</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="sendReceiptEmail()">
                    <i class="bi bi-envelope"></i> Send Email
                </button>
            </div>
        </div>
    </div>
</div>

<!-- WhatsApp Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1" aria-labelledby="whatsappModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="whatsappModalLabel">Send Receipt via WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="whatsappForm">
                    <input type="hidden" name="receipt_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label for="whatsappNumber" class="form-label">WhatsApp Number *</label>
                        <input type="text" class="form-control" id="whatsappNumber" name="phone" 
                               placeholder="e.g., +263771234567" 
                               value="<?= escapeHtml($sale['phone'] ?? '') ?>" required>
                        <small class="text-muted">Enter the WhatsApp number with country code (e.g., +263771234567)</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="sendReceiptWhatsApp()">
                    <i class="bi bi-whatsapp"></i> Send WhatsApp
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Refund Modal -->
<div class="modal fade" id="refundModal" tabindex="-1" aria-labelledby="refundModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="refundModalLabel">
                    <i class="bi bi-arrow-counterclockwise"></i> Process Refund / Generate Credit Note
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="max-height: 80vh; overflow-y: auto;">
                <input type="hidden" id="refundSaleId" value="">
                
                <!-- Step 1: Sale Information -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Sale Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Receipt Number:</strong><br>
                                <span id="refundReceiptNumber" class="text-primary"></span>
                            </div>
                            <div class="col-md-3">
                                <strong>Sale Date:</strong><br>
                                <span id="refundDate"></span>
                            </div>
                            <div class="col-md-3">
                                <strong>Original Amount:</strong><br>
                                <span id="refundOriginalAmount" class="text-primary fw-bold"></span>
                            </div>
                            <div class="col-md-3">
                                <strong>Sold By:</strong><br>
                                <span id="refundCashierName"></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Details -->
                <div class="card mb-3" id="customerDetailsCard" style="display: none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-person"></i> Customer Details</h6>
                    </div>
                    <div class="card-body" id="customerDetailsBody">
                        <!-- Customer details will be populated here -->
                    </div>
                </div>
                
                <!-- ZIMRA Limitation Notice -->
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle"></i> <strong>Important:</strong> ZIMRA only supports full refunds. To process a partial refund, you must:
                    <ol class="mb-0 mt-2">
                        <li>Process a full refund for the original sale</li>
                        <li>Create a new sale for the items the customer wants to keep</li>
                    </ol>
                </div>
                
                <!-- Step 2: Product Selection (Full Refund Only) -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-box-seam"></i> Products to Refund (Full Refund Only)</h6>
                    </div>
                    <div class="card-body">
                        <div id="refundItemsList" style="max-height: 300px; overflow-y: auto;">
                            <!-- Items will be populated here -->
                        </div>
                        <div class="mt-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Remaining Invoice Amount:</strong>
                                    <span id="remainingInvoiceAmount" class="text-success fw-bold"></span>
                                </div>
                                <div class="col-md-6 text-end">
                                    <strong>Refund Total:</strong>
                                    <span id="refundTotalAmount" class="text-danger fw-bold fs-5">$0.00</span>
                                </div>
                            </div>
                            <div class="row mt-2" id="deliveryCostCheckboxRow" style="display: none;">
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="includeDeliveryCost" onchange="updateRefundTotal()">
                                        <label class="form-check-label" for="includeDeliveryCost">
                                            <strong>Include Delivery Cost</strong> (<span id="deliveryCostAmount"></span>)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Step 4: Payment Method Selection -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-credit-card"></i> Refund Payment Method</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label"><strong>Payment Method</strong></label>
                            <select class="form-select" id="refundPaymentMethod" onchange="updateRefundPaymentMethod()">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="ecocash">EcoCash</option>
                                <option value="onemoney">OneMoney</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="split">Split Payment</option>
                            </select>
                        </div>
                        <div id="refundCurrencySelection" style="display: block;">
                            <label class="form-label"><strong>Currency</strong></label>
                            <select class="form-select" id="refundCurrency">
                                <!-- Currencies will be populated here -->
                            </select>
                        </div>
                        <div id="splitRefundPayments" style="display: none;">
                            <label class="form-label"><strong>Split Refund Payments</strong></label>
                            <div id="splitRefundPaymentsList">
                                <!-- Split payments will be populated here -->
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addSplitRefundPayment()">
                                <i class="bi bi-plus"></i> Add Payment
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 5: Reason and Notes -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-chat-left-text"></i> Refund Reason & Notes</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label"><strong>Refund Reason <span class="text-danger">*</span></strong></label>
                            <select class="form-select" id="refundReason" required>
                                <option value="">Select a reason...</option>
                                <option value="Customer Request">Customer Request</option>
                                <option value="Defective Product">Defective Product</option>
                                <option value="Wrong Item">Wrong Item</option>
                                <option value="Duplicate Sale">Duplicate Sale</option>
                                <option value="Price Error">Price Error</option>
                                <option value="Cancelled Order">Cancelled Order</option>
                                <option value="Returned Goods">Returned Goods</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><strong>Additional Notes / Comments</strong></label>
                            <textarea class="form-control" id="refundNotes" rows="3" placeholder="Enter any additional notes or comments about this refund..."></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Summary -->
                <div class="alert alert-warning">
                    <strong><i class="bi bi-exclamation-triangle"></i> Important:</strong>
                    <ul class="mb-0 mt-2">
                        <li>This refund will restore stock for returned items</li>
                        <li>A credit note will be generated and fiscalized to ZIMRA (if original sale was fiscalized)</li>
                        <li>Cash drawer will be adjusted for cash refunds</li>
                        <li>This action cannot be undone</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="processRefundBtn" onclick="processRefund()" disabled style="opacity: 0.5;">
                    <i class="bi bi-check-circle"></i> Process Refund & Generate Credit Note
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function showEmailModal() {
    const modal = new bootstrap.Modal(document.getElementById('emailModal'));
    modal.show();
}

function showWhatsAppModal() {
    const modal = new bootstrap.Modal(document.getElementById('whatsappModal'));
    modal.show();
}

function sendReceiptEmail() {
    const email = document.getElementById('emailAddress').value.trim();
    const receiptId = <?= $id ?>;
    
    if (!email) {
        Swal.fire('Error', 'Please enter an email address', 'error');
        return;
    }
    
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        Swal.fire('Error', 'Please enter a valid email address', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Sending...',
        text: 'Please wait while we send the receipt',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/send_receipt_email.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `receipt_id=${receiptId}&email=${encodeURIComponent(email)}`
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                }
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            Swal.fire('Success!', data.message, 'success').then(() => {
                bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide();
            });
        } else {
            const errorMsg = data.message || (data.debug ? JSON.stringify(data.debug) : 'Failed to send email');
            Swal.fire('Error', errorMsg, 'error');
            if (data.debug) {
                console.error('Email send error details:', data.debug);
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An unexpected error occurred: ' + error.message, 'error');
    });
}

function sendReceiptWhatsApp() {
    const phone = document.getElementById('whatsappNumber').value.trim();
    const receiptId = <?= $id ?>;
    
    if (!phone) {
        Swal.fire('Error', 'Please enter a WhatsApp number', 'error');
        return;
    }
    
    // Basic phone validation
    const phoneRegex = /^\+?[1-9]\d{1,14}$/;
    if (!phoneRegex.test(phone.replace(/\s/g, ''))) {
        Swal.fire('Error', 'Please enter a valid WhatsApp number with country code', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Sending...',
        text: 'Please wait while we send the receipt',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/send_receipt_whatsapp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `receipt_id=${receiptId}&phone=${encodeURIComponent(phone)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Success!',
                text: 'Opening WhatsApp...',
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: 'Open WhatsApp',
                cancelButtonText: 'Close'
            }).then((result) => {
                if (result.isConfirmed && data.whatsapp_link) {
                    window.open(data.whatsapp_link, '_blank');
                }
                bootstrap.Modal.getInstance(document.getElementById('whatsappModal')).hide();
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to send WhatsApp message', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An unexpected error occurred', 'error');
    });
}

// Refund and Delete functions
function refundSale(saleId) {
    // Load sale data and show refund modal
    fetch('<?= BASE_URL ?>ajax/get_sale_for_refund.php?id=' + saleId, {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showRefundModal(data.sale);
        } else {
            Swal.fire('Error', data.message || 'Failed to load sale data', 'error');
        }
    })
    .catch(error => {
        console.error('Error loading sale:', error);
        Swal.fire('Error', 'Failed to load sale data', 'error');
    });
}

let currentRefundSale = null;
let refundPaymentMethods = [];

function showRefundModal(sale) {
    currentRefundSale = sale;
    
    // Populate sale information
    document.getElementById('refundSaleId').value = sale.id;
    document.getElementById('refundReceiptNumber').textContent = sale.receipt_number || 'N/A';
    document.getElementById('refundOriginalAmount').textContent = formatCurrency(sale.total_amount);
    document.getElementById('refundDate').textContent = new Date(sale.sale_date).toLocaleString();
    
    // Populate cashier name
    const cashierName = (sale.cashier_first || '') + ' ' + (sale.cashier_last || '');
    document.getElementById('refundCashierName').textContent = cashierName.trim() || 'N/A';
    
    // Populate customer details
    if (sale.customer_id && (sale.first_name || sale.last_name || sale.company_name)) {
        const customerDetailsBody = document.getElementById('customerDetailsBody');
        customerDetailsBody.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <strong>Name:</strong> ${escapeHtml((sale.first_name || '') + ' ' + (sale.last_name || '') || sale.company_name || 'Walk-in')}
                </div>
                ${sale.email ? `<div class="col-md-6"><strong>Email:</strong> ${escapeHtml(sale.email)}</div>` : ''}
                ${sale.phone ? `<div class="col-md-6"><strong>Phone:</strong> ${escapeHtml(sale.phone)}</div>` : ''}
                ${sale.address ? `<div class="col-md-12 mt-2"><strong>Address:</strong> ${escapeHtml(sale.address)}</div>` : ''}
            </div>
        `;
        document.getElementById('customerDetailsCard').style.display = 'block';
    } else {
        document.getElementById('customerDetailsCard').style.display = 'none';
    }
    
    // Populate items
    const itemsContainer = document.getElementById('refundItemsList');
    itemsContainer.innerHTML = '';
    
    // CRITICAL: Use EXACT fiscalized prices if available (from fiscal_receipt_lines)
    // This ensures refund modal shows the exact prices that were sent to ZIMRA
    // If not fiscalized, convert stored prices to include tax using product's actual tax rate
    const pricesIncludeTax = sale.prices_include_tax === true || sale.prices_include_tax === 1;
    const defaultTaxRate = parseFloat(sale.default_tax_rate) || 0;
    
    sale.items.forEach((item, index) => {
        // For DISPLAY: Use the actual prices the customer paid (display_unit_price/display_total_price)
        // These are calculated from sale_items prices converted to include tax using product's actual tax rate
        // For FISCALIZATION: Use fiscal_unit_price/fiscal_total_price (already handled in fiscal_helper.php)
        let unitPriceWithTax = null;
        let totalPriceWithTax = null;
        
        if (item.display_unit_price !== undefined && item.display_total_price !== undefined) {
            // Use display prices (what customer actually paid)
            unitPriceWithTax = parseFloat(item.display_unit_price) || 0;
            totalPriceWithTax = parseFloat(item.display_total_price) || 0;
        } else if (pricesIncludeTax && defaultTaxRate > 0) {
            // Fallback: Convert from price WITHOUT tax to price WITH tax using default tax rate
            // (This should only happen if display prices weren't calculated)
            const taxDecimal = defaultTaxRate / 100;
            unitPriceWithTax = parseFloat(item.unit_price) * (1 + taxDecimal);
            totalPriceWithTax = parseFloat(item.total_price) * (1 + taxDecimal);
        } else {
            // Prices already include tax or tax is 0
            unitPriceWithTax = parseFloat(item.unit_price) || 0;
            totalPriceWithTax = parseFloat(item.total_price) || 0;
        }
        
        // Round to 2 decimal places
        unitPriceWithTax = Math.round(unitPriceWithTax * 100) / 100;
        totalPriceWithTax = Math.round(totalPriceWithTax * 100) / 100;
        
        const itemDiv = document.createElement('div');
        itemDiv.className = 'refund-item mb-3 p-3 border rounded';
        itemDiv.setAttribute('data-product-name', item.product_name.toLowerCase());
        itemDiv.innerHTML = `
            <div class="d-flex align-items-center mb-2">
                <input type="checkbox" class="form-check-input me-2 refund-item-checkbox" 
                       id="refundItem_${item.id}" 
                       data-item-id="${item.id}"
                       data-product-id="${item.product_id}"
                       data-unit-price="${unitPriceWithTax}"
                       data-total-price="${totalPriceWithTax}"
                       data-quantity="${item.quantity}"
                       data-refund-quantity="${item.quantity}"
                       checked
                       disabled
                       title="Full refund only - all items must be refunded">
                <label class="form-check-label flex-grow-1" for="refundItem_${item.id}">
                    <strong>${escapeHtml(item.product_name)}</strong>
                    ${item.barcode ? `<div class="text-muted small">Barcode: ${escapeHtml(item.barcode)}</div>` : ''}
                    <div class="text-muted small">
                        Qty: ${item.quantity} × ${formatCurrency(unitPriceWithTax)} = ${formatCurrency(totalPriceWithTax)}
                    </div>
                </label>
                <div class="refund-item-amount fw-bold text-primary">
                    ${formatCurrency(totalPriceWithTax)}
                </div>
            </div>
            <!-- Quantity inputs removed - full refund only (all items refunded) -->
        `;
        itemsContainer.appendChild(itemDiv);
    });
    
    // Populate currencies for refund payment
    const currencySelect = document.getElementById('refundCurrency');
    currencySelect.innerHTML = '';
    if (sale.currencies && sale.currencies.length > 0) {
        sale.currencies.forEach(currency => {
            const option = document.createElement('option');
            option.value = currency.id;
            option.textContent = currency.code + ' - ' + currency.name;
            if (currency.is_base) {
                option.selected = true;
            }
            currencySelect.appendChild(option);
        });
    }
    
    // Initialize payment methods from original sale
    refundPaymentMethods = [];
    if (sale.payments && sale.payments.length > 0) {
        sale.payments.forEach(payment => {
            refundPaymentMethods.push({
                method: payment.payment_method,
                amount: parseFloat(payment.amount),
                currency_id: payment.currency_id || (sale.base_currency ? sale.base_currency.id : null)
            });
        });
    }
    
    // Set default refund payment method
    if (sale.payments && sale.payments.length > 0) {
        document.getElementById('refundPaymentMethod').value = sale.payments[0].payment_method || 'cash';
    }
    
    // Full refund only - no refund type selection needed
    document.getElementById('refundReason').value = '';
    document.getElementById('refundNotes').value = '';
    
    // Initialize delivery cost checkbox
    const includeDeliveryCheckbox = document.getElementById('includeDeliveryCost');
    const deliveryCostRow = document.getElementById('deliveryCostCheckboxRow');
    const deliveryCostAmount = document.getElementById('deliveryCostAmount');
    
    if (sale.delivery_cost && parseFloat(sale.delivery_cost) > 0) {
        if (deliveryCostRow) deliveryCostRow.style.display = 'block';
        if (deliveryCostAmount) deliveryCostAmount.textContent = formatCurrency(sale.delivery_cost);
        
        // For full refunds, auto-check delivery cost if it exists
        const allCheckboxes = document.querySelectorAll('.refund-item-checkbox');
        const allChecked = allCheckboxes.length > 0 && Array.from(allCheckboxes).every(cb => cb.checked);
        if (allChecked && includeDeliveryCheckbox) {
            includeDeliveryCheckbox.checked = true;
        } else if (includeDeliveryCheckbox) {
            includeDeliveryCheckbox.checked = false;
        }
    } else {
        if (deliveryCostRow) deliveryCostRow.style.display = 'none';
        if (includeDeliveryCheckbox) includeDeliveryCheckbox.checked = false;
    }
    
    // Initialize payment method display
    updateRefundPaymentMethod();
    
    // Update totals
    updateRefundTotal();
    
    // Show modal
    new bootstrap.Modal(document.getElementById('refundModal')).show();
}

function searchRefundProducts() {
    const search = document.getElementById('productSearchRefund').value.toLowerCase();
    document.querySelectorAll('.refund-item').forEach(item => {
        const productName = item.getAttribute('data-product-name') || '';
        item.style.display = productName.includes(search) ? 'block' : 'none';
    });
}

function updateRefundPaymentMethod() {
    const method = document.getElementById('refundPaymentMethod').value;
    const currencySelection = document.getElementById('refundCurrencySelection');
    const splitPayments = document.getElementById('splitRefundPayments');
    
    if (method === 'split') {
        if (currencySelection) currencySelection.style.display = 'none';
        if (splitPayments) {
            splitPayments.style.display = 'block';
            initializeSplitRefundPayments();
        }
    } else {
        if (currencySelection) currencySelection.style.display = 'block';
        if (splitPayments) splitPayments.style.display = 'none';
    }
}

function initializeSplitRefundPayments() {
    const container = document.getElementById('splitRefundPaymentsList');
    container.innerHTML = '';
    
    const totalRefund = parseFloat(document.getElementById('refundTotalAmount').textContent.replace(/[^0-9.-]/g, '')) || 0;
    
    if (totalRefund > 0 && currentRefundSale && currentRefundSale.payments) {
        // Initialize with original payment methods
        currentRefundSale.payments.forEach((payment, index) => {
            addSplitRefundPaymentRow(payment.payment_method, payment.amount, payment.currency_id);
        });
    } else {
        // Default to 2 payments
        addSplitRefundPaymentRow('cash', 0, null);
        addSplitRefundPaymentRow('card', 0, null);
    }
    
    validateSplitRefundPayments();
}

function addSplitRefundPayment() {
    addSplitRefundPaymentRow('cash', 0, null);
    validateSplitRefundPayments();
}

function addSplitRefundPaymentRow(method = 'cash', amount = 0, currencyId = null) {
    const container = document.getElementById('splitRefundPaymentsList');
    const index = container.children.length;
    
    const row = document.createElement('div');
    row.className = 'split-payment-item mb-2 p-2 border rounded';
    row.innerHTML = `
        <div class="row g-2">
            <div class="col-md-4">
                <select class="form-select form-select-sm split-payment-method" onchange="validateSplitRefundPayments()">
                    <option value="cash" ${method === 'cash' ? 'selected' : ''}>Cash</option>
                    <option value="card" ${method === 'card' ? 'selected' : ''}>Card</option>
                    <option value="ecocash" ${method === 'ecocash' ? 'selected' : ''}>EcoCash</option>
                    <option value="onemoney" ${method === 'onemoney' ? 'selected' : ''}>OneMoney</option>
                    <option value="bank" ${method === 'bank' ? 'selected' : ''}>Bank Transfer</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm split-payment-currency">
                    ${currentRefundSale && currentRefundSale.currencies ? currentRefundSale.currencies.map(c => 
                        `<option value="${c.id}" ${(currencyId == c.id || (!currencyId && c.is_base)) ? 'selected' : ''}>${escapeHtml(c.code)}</option>`
                    ).join('') : '<option value="">USD</option>'}
                </select>
            </div>
            <div class="col-md-4">
                <input type="number" class="form-control form-control-sm split-payment-amount" 
                       step="0.01" min="0" value="${amount.toFixed(2)}" 
                       placeholder="Amount" onchange="validateSplitRefundPayments()">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-sm btn-danger" onclick="removeSplitRefundPayment(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.appendChild(row);
}

function removeSplitRefundPayment(btn) {
    btn.closest('.split-payment-item').remove();
    validateSplitRefundPayments();
}

function validateSplitRefundPayments() {
    const totalRefund = parseFloat(document.getElementById('refundTotalAmount').textContent.replace(/[^0-9.-]/g, '')) || 0;
    let totalPaid = 0;
    
    document.querySelectorAll('.split-payment-amount').forEach(input => {
        totalPaid += parseFloat(input.value) || 0;
    });
    
    const difference = Math.abs(totalRefund - totalPaid);
    const isValid = difference < 0.01; // Allow 1 cent tolerance
    
    // Show validation message
    let validationMsg = document.getElementById('splitRefundValidation');
    if (!validationMsg) {
        validationMsg = document.createElement('div');
        validationMsg.id = 'splitRefundValidation';
        validationMsg.className = 'mt-2';
        document.getElementById('splitRefundPayments').appendChild(validationMsg);
    }
    
    if (isValid) {
        validationMsg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Payment amounts match refund total</span>';
    } else {
        validationMsg.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-circle"></i> Total paid (${formatCurrency(totalPaid)}) does not match refund total (${formatCurrency(totalRefund)}). Difference: ${formatCurrency(difference)}</span>`;
    }
    
    return isValid;
}

// updateRefundItemAmount() removed - full refund only, no quantity adjustments needed
function updateRefundItemAmount(itemId, unitPrice, maxQuantity) {
    // Function disabled - full refund only
    
    if (!checkbox.checked) return;
    
    let qty = parseInt(qtyInput.value) || 0;
    if (qty > maxQuantity) {
        qty = maxQuantity;
        qtyInput.value = qty;
    }
    if (qty < 1) {
        qty = 1;
        qtyInput.value = qty;
    }
    
    const amount = qty * parseFloat(unitPrice);
    amountDiv.textContent = formatCurrency(amount);
    
    // Update data attribute
    checkbox.setAttribute('data-refund-quantity', qty);
    checkbox.setAttribute('data-refund-amount', amount.toFixed(2));
    
    updateRefundTotal();
}

function updateRefundTotal() {
    const checkboxes = document.querySelectorAll('.refund-item-checkbox:checked');
    let refundSubtotal = 0;
    
    // Calculate refund subtotal (sum of selected items)
    checkboxes.forEach(checkbox => {
        const refundQty = parseInt(checkbox.getAttribute('data-refund-quantity')) || parseInt(checkbox.getAttribute('data-quantity'));
        const unitPrice = parseFloat(checkbox.getAttribute('data-unit-price'));
        const amount = refundQty * unitPrice;
        refundSubtotal += amount;
    });
    
    // Round refund subtotal to 2 decimal places to avoid floating point errors
    refundSubtotal = Math.round(refundSubtotal * 100) / 100;
    
    // Calculate proportional discount and delivery cost
    let refundDiscount = 0;
    let refundDeliveryCost = 0;
    let refundTotal = refundSubtotal;
    
    if (currentRefundSale) {
        const originalSubtotal = parseFloat(currentRefundSale.subtotal) || 0;
        const originalDiscount = parseFloat(currentRefundSale.discount_amount) || 0;
        const originalDeliveryCost = parseFloat(currentRefundSale.delivery_cost) || 0;
        const originalTotal = parseFloat(currentRefundSale.total_amount) || 0;
        
        // Check if all items are selected (full refund)
        const allCheckboxes = document.querySelectorAll('.refund-item-checkbox');
        const allChecked = allCheckboxes.length > 0 && Array.from(allCheckboxes).every(cb => cb.checked);
        const allFullQuantity = Array.from(allCheckboxes).every(cb => {
            const refundQty = parseInt(cb.getAttribute('data-refund-quantity')) || parseInt(cb.getAttribute('data-quantity'));
            const originalQty = parseInt(cb.getAttribute('data-quantity'));
            return refundQty === originalQty;
        });
        
        // If all visible items are checked with full quantities, it's a full refund
        // (regardless of whether subtotals match, as some items may have been previously refunded)
        const isFullRefund = allChecked && allFullQuantity && allCheckboxes.length > 0;
        
        if (originalDiscount > 0) {
            if (isFullRefund) {
                // Full refund: refund the entire discount
                refundDiscount = originalDiscount;
            }
            // Full refund only: refund the entire discount
        }
        
        // Include delivery cost based on checkbox state
        const includeDeliveryCheckbox = document.getElementById('includeDeliveryCost');
        
        // Respect user's checkbox choice - only include delivery cost if checkbox is checked
        if (includeDeliveryCheckbox && includeDeliveryCheckbox.checked && originalDeliveryCost > 0) {
            refundDeliveryCost = originalDeliveryCost;
        } else {
            refundDeliveryCost = 0;
        }
        
        // Calculate refund total (subtotal - discount + delivery cost)
        // Round to 2 decimal places to avoid floating point errors
        refundTotal = Math.round((refundSubtotal - refundDiscount + refundDeliveryCost) * 100) / 100;
        
        // Calculate remaining invoice amount
        // For full refunds, remaining should always be 0 (tax is handled separately in credit notes)
        let remaining = 0;
        
        // Full refund only: remaining is always 0
        remaining = 0;
        
        document.getElementById('remainingInvoiceAmount').textContent = formatCurrency(remaining);
    }
    
    // Display refund total (which includes discount and delivery cost)
    document.getElementById('refundTotalAmount').textContent = formatCurrency(refundTotal);
    
    // Update split payments if active
    if (document.getElementById('refundPaymentMethod').value === 'split') {
        validateSplitRefundPayments();
    }
    
    // Enable/disable process button
    const processBtn = document.getElementById('processRefundBtn');
    if (refundTotal > 0) {
        processBtn.disabled = false;
        processBtn.style.opacity = '1';
    } else {
        processBtn.disabled = true;
        processBtn.style.opacity = '0.5';
    }
}

function formatCurrency(amount) {
    if (typeof amount !== 'number') {
        amount = parseFloat(amount) || 0;
    }
    return '$' + amount.toFixed(2);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// toggleRefundType() removed - only full refunds are supported

function processRefund() {
    const saleId = document.getElementById('refundSaleId').value;
    const refundType = 'full'; // Only full refunds are supported (ZIMRA limitation)
    const reason = document.getElementById('refundReason').value;
    const notes = document.getElementById('refundNotes').value;
    
    // Validate reason
    if (!reason || reason.trim() === '') {
        Swal.fire('Error', 'Please select a refund reason', 'error');
        return;
    }
    
    // Get selected items
    const selectedItems = [];
    document.querySelectorAll('.refund-item-checkbox:checked').forEach(checkbox => {
        const itemId = parseInt(checkbox.getAttribute('data-item-id'));
        const productId = parseInt(checkbox.getAttribute('data-product-id'));
        const refundQty = parseInt(checkbox.getAttribute('data-refund-quantity')) || parseInt(checkbox.getAttribute('data-quantity'));
        const unitPrice = parseFloat(checkbox.getAttribute('data-unit-price'));
        const totalPrice = refundQty * unitPrice;
        
        selectedItems.push({
            sale_item_id: itemId,
            product_id: productId,
            quantity: refundQty,
            unit_price: unitPrice,
            total_price: totalPrice
        });
    });
    
    if (selectedItems.length === 0) {
        Swal.fire('Error', 'Please select at least one item to refund', 'error');
        return;
    }
    
    // Get the refund total (with discount) from the displayed amount
    const refundTotalElement = document.getElementById('refundTotalAmount');
    const totalRefund = parseFloat(refundTotalElement.textContent.replace(/[^0-9.-]/g, '')) || 0;
    
    // Check if delivery cost should be included
    const includeDeliveryCheckbox = document.getElementById('includeDeliveryCost');
    const includeDeliveryCost = includeDeliveryCheckbox ? includeDeliveryCheckbox.checked : false;
    
    // Get payment methods
    const paymentMethod = document.getElementById('refundPaymentMethod').value;
    let paymentMethods = [];
    
    if (paymentMethod === 'split') {
        // Validate split payments
        if (!validateSplitRefundPayments()) {
            Swal.fire('Error', 'Split payment amounts must equal the refund total', 'error');
            return;
        }
        
        document.querySelectorAll('.split-payment-item').forEach(row => {
            const method = row.querySelector('.split-payment-method').value;
            const amount = parseFloat(row.querySelector('.split-payment-amount').value) || 0;
            const currencyId = parseInt(row.querySelector('.split-payment-currency').value) || null;
            
            if (amount > 0) {
                paymentMethods.push({
                    method: method,
                    amount: amount,
                    currency_id: currencyId,
                    reference: null
                });
            }
        });
    } else {
        const currencyId = parseInt(document.getElementById('refundCurrency').value) || null;
        paymentMethods.push({
            method: paymentMethod,
            amount: totalRefund,
            currency_id: currencyId,
            reference: null
        });
    }
    
    // Show confirmation
    Swal.fire({
        title: 'Confirm Refund',
        html: `
            <p>Refund Type: <strong>${refundType === 'full' ? 'Full' : 'Partial'}</strong></p>
            <p>Items: <strong>${selectedItems.length}</strong></p>
            <p>Total Refund Amount: <strong>${formatCurrency(totalRefund)}</strong></p>
            ${includeDeliveryCost && currentRefundSale && currentRefundSale.delivery_cost > 0 ? `<p>Includes Delivery Cost: <strong>${formatCurrency(currentRefundSale.delivery_cost)}</strong></p>` : ''}
            <p>Payment Method: <strong>${paymentMethod === 'split' ? 'Split Payment' : paymentMethod.toUpperCase()}</strong></p>
            <p>Reason: <strong>${escapeHtml(reason)}</strong></p>
            <p class="text-muted small mt-3">A credit note will be generated and fiscalized to ZIMRA if the original sale was fiscalized.</p>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Process Refund',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc3545'
    }).then((result) => {
        if (result.isConfirmed) {
            // Process refund
            Swal.fire({
                title: 'Processing Refund...',
                html: 'Generating credit note and processing refund...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('<?= BASE_URL ?>ajax/process_refund.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify({
                    sale_id: parseInt(saleId),
                    refund_type: refundType,
                    items: selectedItems,
                    payment_methods: paymentMethods,
                    reason: reason,
                    notes: notes,
                    include_delivery_cost: includeDeliveryCost
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let message = 'Refund processed successfully';
                    if (data.fiscalized) {
                        message += ' and credit note fiscalized to ZIMRA';
                    }
                    if (data.credit_note_number) {
                        message += '<br><br>Credit Note #: <strong>' + data.credit_note_number + '</strong>';
                    }
                    
                    Swal.fire({
                        title: 'Success!',
                        html: message,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Close modal
                        bootstrap.Modal.getInstance(document.getElementById('refundModal')).hide();
                        // Redirect to credit note view (view_credit_note.php expects refund_id, not credit_note_id)
                        if (data.refund_id) {
                            window.location.href = '<?= BASE_URL ?>modules/sales/view_credit_note.php?id=' + data.refund_id;
                        } else {
                            // Fallback: reload page if refund ID not available
                            window.location.reload();
                        }
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to process refund', 'error');
                }
            })
            .catch(error => {
                console.error('Refund error:', error);
                Swal.fire('Error', 'Failed to process refund: ' + error.message, 'error');
            });
        }
    });
}

function deleteReceipt(saleId) {
    Swal.fire({
        title: 'Delete Receipt?',
        html: `
            <p>Are you sure you want to delete this receipt?</p>
            <p class="text-danger"><strong>This action will:</strong></p>
            <ul class="text-start text-danger">
                <li>Restore stock for all items</li>
                <li>Reverse shift cash adjustments</li>
                <li>Mark the receipt as deleted</li>
            </ul>
            <p class="text-muted">This action cannot be undone.</p>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc3545'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting Receipt...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const formData = new FormData();
            formData.append('sale_id', saleId);
            
            fetch('<?= BASE_URL ?>ajax/delete_receipt.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: data.message || 'Receipt deleted successfully',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Redirect to manage sales
                        window.location.href = '<?= BASE_URL ?>modules/pos/manage.php';
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete receipt', 'error');
                }
            })
            .catch(error => {
                console.error('Delete receipt error:', error);
                Swal.fire('Error', 'Failed to delete receipt: ' + error.message, 'error');
            });
        }
    });
}

// Auto-print on page load if print parameter is set
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const shouldPrint = urlParams.get('print');
    
    if (shouldPrint === '1') {
        setTimeout(() => {
            window.print();
        }, 500);
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


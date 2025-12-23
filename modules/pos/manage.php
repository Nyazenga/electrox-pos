<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
// This page matches sidebar "Manage Sales" menu item
$auth->requirePermission('pos.manage_sales');

$pageTitle = 'Manage Sales';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Check if deleted_at column exists
$hasDeletedAtColumn = false;
try {
    $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'sales' 
                            AND COLUMN_NAME = 'deleted_at'");
    $hasDeletedAtColumn = ($colCheck && $colCheck['count'] > 0);
} catch (Exception $e) {
    // Column doesn't exist, continue without it
    $hasDeletedAtColumn = false;
}

// Build query with proper null handling for branch_id
if ($branchId !== null) {
    $sql = "SELECT s.*, c.first_name, c.last_name, u.first_name as cashier_first, u.last_name as cashier_last 
            FROM sales s 
            LEFT JOIN customers c ON s.customer_id = c.id 
            LEFT JOIN users u ON s.user_id = u.id 
            WHERE s.branch_id = :branch_id 
            AND DATE(s.sale_date) BETWEEN :start_date AND :end_date";
    if ($hasDeletedAtColumn) {
        $sql .= " AND (s.deleted_at IS NULL)";
    }
    $params = [':branch_id' => $branchId, ':start_date' => $startDate, ':end_date' => $endDate];
} else {
    $sql = "SELECT s.*, c.first_name, c.last_name, u.first_name as cashier_first, u.last_name as cashier_last 
            FROM sales s 
            LEFT JOIN customers c ON s.customer_id = c.id 
            LEFT JOIN users u ON s.user_id = u.id 
            WHERE (s.branch_id IS NULL OR s.branch_id = 0)
            AND DATE(s.sale_date) BETWEEN :start_date AND :end_date";
    if ($hasDeletedAtColumn) {
        $sql .= " AND (s.deleted_at IS NULL)";
    }
    $params = [':start_date' => $startDate, ':end_date' => $endDate];
}

if ($search) {
    $sql .= " AND (s.receipt_number LIKE :search OR c.first_name LIKE :search OR c.last_name LIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY s.sale_date DESC LIMIT 100";

$sales = $db->getRows($sql, $params);
// Ensure $sales is always an array
if ($sales === false) {
    $sales = [];
}

// Enrich sales with fiscal data
if (!empty($sales)) {
    $primaryDb = Database::getPrimaryInstance();
    $saleIds = array_column($sales, 'id');
    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    
    $fiscalReceipts = $primaryDb->getRows(
        "SELECT sale_id, receipt_global_no, receipt_verification_code, fiscal_day_no, device_id, receipt_date 
         FROM fiscal_receipts 
         WHERE sale_id IN ($placeholders)",
        $saleIds
    );
    
    // Create a map of sale_id => fiscal receipt
    $fiscalMap = [];
    foreach ($fiscalReceipts as $fr) {
        $fiscalMap[$fr['sale_id']] = $fr;
    }
    
    // Add fiscal data to each sale
    foreach ($sales as &$sale) {
        if (isset($fiscalMap[$sale['id']])) {
            $sale['fiscal_receipt'] = $fiscalMap[$sale['id']];
            $sale['fiscalized'] = 1;
        } else {
            $sale['fiscal_receipt'] = null;
            $sale['fiscalized'] = $sale['fiscalized'] ?? 0;
        }
    }
    unset($sale);
}

$selectedSale = null;
if (isset($_GET['id'])) {
    $selectedSale = $db->getRow("SELECT s.*, c.first_name, c.last_name, c.email, c.phone, u.first_name as cashier_first, u.last_name as cashier_last, b.branch_name 
                                  FROM sales s 
                                  LEFT JOIN customers c ON s.customer_id = c.id 
                                  LEFT JOIN users u ON s.user_id = u.id 
                                  LEFT JOIN branches b ON s.branch_id = b.id 
                                  WHERE s.id = :id", [':id' => intval($_GET['id'])]);
    
    if ($selectedSale) {
        $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $selectedSale['id']]);
        if ($items === false) {
            $items = [];
        }
        $selectedSale['items'] = $items;
        
        // Load fiscal receipt data for the selected sale
        $primaryDb = Database::getPrimaryInstance();
        $fiscalReceipt = $primaryDb->getRow(
            "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
             FROM fiscal_receipts fr
             LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
             LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
             WHERE fr.sale_id = :sale_id
             LIMIT 1",
            [':sale_id' => $selectedSale['id']]
        );
        $selectedSale['fiscal_receipt'] = $fiscalReceipt;
        
        // Get fiscal receipt taxes for tax breakdown display
        $selectedSale['fiscal_receipt_taxes'] = [];
        if ($fiscalReceipt) {
            $selectedSale['fiscal_receipt_taxes'] = $primaryDb->getRows(
                "SELECT tax_code, tax_percent, tax_id, tax_amount, sales_amount_with_tax 
                 FROM fiscal_receipt_taxes 
                 WHERE fiscal_receipt_id = :fiscal_receipt_id 
                 ORDER BY tax_percent ASC, tax_code ASC",
                [':fiscal_receipt_id' => $fiscalReceipt['id']]
            );
        }
        
        // Get payments - ALWAYS fetch directly from sale_payments first to ensure we get them
        $payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $selectedSale['id']]);
        if ($payments === false) {
            $payments = [];
        }
        
        // Enrich payments with currency information from tenant database (currencies table is in tenant database)
        if (!empty($payments)) {
            require_once APP_PATH . '/includes/currency_functions.php';
            foreach ($payments as &$payment) {
                // Get currency info from tenant database (where currencies table exists)
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
        
        $selectedSale['payments'] = $payments;
        
        // Get base currency for display
        if (!function_exists('getBaseCurrency')) {
            require_once APP_PATH . '/includes/currency_functions.php';
        }
        $baseCurrency = getBaseCurrency($db);
        
        // Determine payment currency from payments (for display conversion)
        $paymentCurrency = null;
        $paymentCurrencyId = null;
        $exchangeRate = 1.0;
        if (!empty($selectedSale['payments'])) {
            // Get currency from first payment
            $firstPayment = $selectedSale['payments'][0];
            if (!empty($firstPayment['currency_id'])) {
                $paymentCurrencyId = $firstPayment['currency_id'];
                // Get currency from tenant database (currencies table is in tenant database)
                $paymentCurrency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $paymentCurrencyId]);
                if ($paymentCurrency && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                    // Get exchange rate from BASE currency to PAYMENT currency (for converting base amounts to payment currency)
                    // If base is USD (rate=1.0) and payment is ZWL (rate=2.0), then 1 USD = 2 ZWL, so rate = 2.0
                    if (!function_exists('getExchangeRate')) {
                        require_once APP_PATH . '/includes/currency_functions.php';
                    }
                    $exchangeRate = getExchangeRate($baseCurrency['id'], $paymentCurrencyId, $db);
                }
            }
        }
        
        // Helper function to convert and format currency for display
        if (!function_exists('formatCurrencyAmount')) {
            require_once APP_PATH . '/includes/currency_functions.php';
        }
        function formatAndConvertCurrency($amount, $paymentCurrencyId, $baseCurrencyId, $exchangeRate, $db) {
            if ($paymentCurrencyId && $paymentCurrencyId != $baseCurrencyId) {
                // Convert amount from base to payment currency for display
                $convertedAmount = $amount * $exchangeRate;
                return formatCurrencyAmount($convertedAmount, $paymentCurrencyId, $db);
            }
            return formatCurrencyAmount($amount, $baseCurrencyId, $db);
        }
    } else {
        // Ensure baseCurrency is defined even if selectedSale is null
        if (!function_exists('getBaseCurrency')) {
            require_once APP_PATH . '/includes/currency_functions.php';
        }
        $baseCurrency = getBaseCurrency($db);
    }
    
    // Get company settings for receipt display
    $companyName = getSetting('company_name', SYSTEM_NAME);
    $companyAddress = getSetting('company_address', '');
    $companyPhone = getSetting('company_phone', '');
    $companyEmail = getSetting('company_email', '');
    
    // Get receipt logo
    $receiptLogoPath = getSetting('pos_receipt_logo', '');
    $receiptLogoUrl = '';
    if ($receiptLogoPath) {
        $receiptLogoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<style>
.receipts-container {
    display: flex;
    height: calc(100vh - 80px);
    gap: 20px;
}

.receipts-list {
    width: 400px;
    background: white;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.receipts-list-header {
    margin-bottom: 20px;
}

.receipts-list-header h5 {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.receipt-item {
    padding: 15px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
}

.receipt-item:hover {
    border-color: var(--primary-blue);
    background: var(--light-blue);
}

.receipt-item.active {
    border-color: var(--primary-blue);
    background: var(--light-blue);
}

.receipt-item .badge {
    font-size: 11px;
    padding: 4px 8px;
    font-weight: 600;
}

.receipt-item.refunded-item,
.receipt-item[data-refunded="true"] {
    border-color: #dc3545;
    background: #fff5f5;
    opacity: 0.85;
}

.receipt-item.refunded-item:hover,
.receipt-item[data-refunded="true"]:hover {
    border-color: #dc3545;
    background: #ffe5e5;
    opacity: 1;
}

.receipt-item.refunded-item.active {
    border-color: #dc3545;
    background: #ffe5e5;
}

.receipt-details {
    flex: 1;
    background: white;
    border-radius: 12px;
    padding: 30px;
    overflow-y: auto;
}

.receipt-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
}

.total-display {
    text-align: center;
    margin: 30px 0;
    padding: 30px;
    background: var(--light-blue);
    border-radius: 12px;
}

.total-amount {
    font-size: 32px;
    font-weight: 700;
    color: var(--primary-blue);
}

/* Receipt container styles (similar to receipt.php) */
.receipt-container {
    max-width: 400px;
    margin: 0 auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
}

.receipt-container table th, 
.receipt-container table td {
    padding: 6px 4px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.receipt-container table th {
    background: #f3f4f6;
    font-weight: bold;
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
    .receipts-list,
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
    
    .receipts-container {
        display: block !important;
        height: auto !important;
        flex-direction: column !important;
        gap: 0 !important;
    }
    
    .receipt-details {
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        box-shadow: none !important;
        display: block !important;
        overflow: visible !important;
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

/* ========== MOBILE RESPONSIVE STYLES ========== */

/* Tablet and below (max-width: 1024px) */
@media (max-width: 1024px) {
    .receipts-container {
        flex-direction: column;
        height: auto;
        min-height: calc(100vh - 80px);
    }
    
    .receipts-list {
        width: 100%;
        max-height: 40vh;
        order: 2;
    }
    
    .receipt-details {
        order: 1;
        min-height: 60vh;
    }
}

/* Mobile (max-width: 768px) */
@media (max-width: 768px) {
    .receipts-container {
        flex-direction: column;
        height: auto;
        min-height: calc(100vh - 60px);
        gap: 0;
        padding: 0;
    }
    
    .receipts-list {
        width: 100%;
        border-radius: 0;
        padding: 15px;
        max-height: 50vh;
        order: 2;
        position: relative;
    }
    
    .receipts-list-header {
        margin-bottom: 15px;
    }
    
    .receipts-list-header h5 {
        font-size: 18px;
        flex-wrap: wrap;
    }
    
    .receipt-item {
        padding: 12px;
        margin-bottom: 8px;
    }
    
    .receipt-details {
        order: 1;
        width: 100%;
        border-radius: 0;
        padding: 20px 15px;
        min-height: 50vh;
    }
    
    .receipt-header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
        margin-bottom: 20px;
    }
    
    .receipt-header .btn {
        width: 100%;
        margin-bottom: 10px;
    }
    
    .total-display {
        margin: 20px 0;
        padding: 20px;
    }
    
    .total-amount {
        font-size: 24px;
    }
    
    .table {
        font-size: 14px;
    }
    
    .table th,
    .table td {
        padding: 8px;
    }
}

/* Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .receipts-list {
        padding: 10px;
        max-height: 45vh;
    }
    
    .receipt-item {
        padding: 10px;
        font-size: 14px;
    }
    
    .receipt-details {
        padding: 15px 10px;
    }
    
    .total-amount {
        font-size: 32px;
    }
    
    .table {
        font-size: 12px;
    }
    
    .table th,
    .table td {
        padding: 6px 4px;
    }
    
    .receipt-header h4 {
        font-size: 18px;
    }
}
</style>

<div class="receipts-container">
    <div class="receipts-list">
        <div class="receipts-list-header">
            <h5>
                Past receipts
                <i class="bi bi-chevron-down"></i>
            </h5>
            <div class="input-group mb-3">
                <input type="text" class="form-control" id="receiptSearch" placeholder="Search" value="<?= escapeHtml($search) ?>">
                <button class="btn btn-outline-secondary" type="button"><i class="bi bi-calendar3"></i></button>
            </div>
            <form method="GET" class="row g-2 mb-3">
                <div class="col-6">
                    <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control form-control-sm">
                </div>
                <div class="col-6">
                    <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control form-control-sm">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                </div>
            </form>
        </div>
        
        <div style="flex: 1; overflow-y: auto;">
            <?php if (empty($sales)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 48px;"></i>
                    <p class="mt-2">No sales found for the selected date range</p>
                </div>
            <?php else: ?>
                <?php foreach ($sales as $sale): ?>
                <div class="receipt-item <?= $selectedSale && $selectedSale['id'] == $sale['id'] ? 'active' : '' ?> <?= $sale['payment_status'] === 'refunded' ? 'refunded-item' : '' ?>" 
                     data-refunded="<?= $sale['payment_status'] === 'refunded' ? 'true' : 'false' ?>"
                     onclick="window.location.href='?id=<?= $sale['id'] ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>'">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="flex-grow-1">
                            <strong>Receipt #<?= escapeHtml($sale['receipt_number']) ?></strong>
                            <?php if ($sale['payment_status'] === 'refunded'): ?>
                                <span class="badge bg-danger ms-2">
                                    <i class="bi bi-arrow-counterclockwise"></i> Refunded
                                </span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted"><?= date('h:i A', strtotime($sale['sale_date'])) ?></small>
                    </div>
                    <div class="text-muted small mb-1">
                        <?= date('l, F j, Y', strtotime($sale['sale_date'])) ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="fw-bold <?= $sale['payment_status'] === 'refunded' ? 'text-danger' : 'text-primary' ?>">
                            <?= formatCurrency($sale['total_amount']) ?>
                            <?php if ($sale['payment_status'] === 'refunded'): ?>
                                <small class="text-muted d-block" style="font-size: 11px; font-weight: normal;">(Refunded)</small>
                            <?php endif; ?>
                        </div>
                        <?php if ($sale['payment_status'] === 'refunded'): ?>
                            <i class="bi bi-arrow-counterclockwise text-danger" style="font-size: 20px;"></i>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($sale['fiscal_receipt'])): ?>
                        <div class="mt-2 pt-2 border-top" style="font-size: 10px; color: #666;">
                            <div><strong>Fiscal:</strong> Day <?= escapeHtml($sale['fiscal_receipt']['fiscal_day_no']) ?>, Global #<?= escapeHtml($sale['fiscal_receipt']['receipt_global_no']) ?></div>
                            <div><strong>Verification:</strong> <?= escapeHtml($sale['fiscal_receipt']['receipt_verification_code']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="receipt-details">
        <?php if ($selectedSale): ?>
            <!-- Action Buttons - Top Row -->
            <div class="no-print mb-4" style="text-align: center; padding: 20px 0;">
                <div class="d-flex justify-content-center gap-2 flex-wrap">
                    <button onclick="window.print()" class="btn btn-info">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="receipt.php?id=<?= $selectedSale['id'] ?>&pdf=1" class="btn btn-secondary">
                        <i class="bi bi-file-earmark-pdf"></i> Export A4 PDF
                    </a>
                    <button class="btn btn-primary" onclick="showEmailModal(<?= $selectedSale['id'] ?>, '<?= escapeHtml($selectedSale['email'] ?? '') ?>')">
                        <i class="bi bi-envelope"></i> Send Email
                    </button>
                    <button class="btn btn-success" onclick="showWhatsAppModal(<?= $selectedSale['id'] ?>, '<?= escapeHtml($selectedSale['phone'] ?? '') ?>')">
                        <i class="bi bi-whatsapp"></i> Send WhatsApp
                    </button>
                    <?php if ($selectedSale['payment_status'] !== 'refunded'): ?>
                        <?php if ($auth->hasPermission('receipts.refund')): ?>
                        <button class="btn btn-warning" onclick="refundSale(<?= $selectedSale['id'] ?>)">
                            <i class="bi bi-arrow-counterclockwise"></i> Refund
                        </button>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('receipts.delete')): ?>
                        <button class="btn btn-danger" onclick="deleteReceipt(<?= $selectedSale['id'] ?>)">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-danger">Refunded</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Receipt Container - Below Buttons -->
            <div class="receipt-container">
                <div class="receipt-header">
                    <?php if ($receiptLogoUrl): ?>
                        <div style="text-align: center; margin-bottom: 15px;">
                            <img src="<?= htmlspecialchars($receiptLogoUrl) ?>" alt="Logo" style="max-width: 200px; max-height: 80px; object-fit: contain;" onerror="this.style.display='none';">
                        </div>
                    <?php endif; ?>
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
                    <div><strong>Receipt #:</strong> <?= escapeHtml($selectedSale['receipt_number']) ?></div>
                    <div><strong>Date:</strong> <?= formatDateTime($selectedSale['sale_date']) ?></div>
                    <div><strong>Cashier:</strong> <?= escapeHtml(($selectedSale['cashier_first'] ?? '') . ' ' . ($selectedSale['cashier_last'] ?? '')) ?></div>
                    <?php if ($selectedSale['first_name']): ?>
                        <div><strong>Customer:</strong> <?= escapeHtml($selectedSale['first_name'] . ' ' . $selectedSale['last_name']) ?></div>
                    <?php endif; ?>
                </div>
                
                <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                    <colgroup>
                        <col style="width: auto;">
                        <col style="width: 50px;">
                        <col style="width: 80px;">
                        <col style="width: 80px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="text-align: left; padding: 6px 4px; border-bottom: 1px solid #ddd;">Item</th>
                            <th style="text-align: center; padding: 6px 4px; border-bottom: 1px solid #ddd;">Qty</th>
                            <th style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;">Price</th>
                            <th style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Convert items to payment currency if different from base
                        foreach ($selectedSale['items'] as $item): 
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
                                <td style="text-align: left; padding: 6px 4px; word-wrap: break-word; border-bottom: 1px solid #ddd;"><?= escapeHtml($item['product_name']) ?></td>
                                <td style="text-align: center; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= $item['quantity'] ?></td>
                                <td style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= $unitPriceFormatted ?></td>
                                <td style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= $totalPriceFormatted ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <?php
                        // Convert amounts to payment currency if needed
                        $subtotal = floatval($selectedSale['subtotal']);
                        $discountAmount = floatval($selectedSale['discount_amount'] ?? 0);
                        $totalAmount = floatval($selectedSale['total_amount']);
                        
                        if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                            $subtotal = $subtotal * $exchangeRate;
                            $discountAmount = $discountAmount * $exchangeRate;
                            $totalAmount = $totalAmount * $exchangeRate;
                        }
                        
                        $subtotalFormatted = $paymentCurrency ? formatCurrencyAmount($subtotal, $paymentCurrencyId, $db) : formatCurrency($subtotal);
                        $discountFormatted = $paymentCurrency ? formatCurrencyAmount($discountAmount, $paymentCurrencyId, $db) : formatCurrency($discountAmount);
                        $totalFormatted = $paymentCurrency ? formatCurrencyAmount($totalAmount, $paymentCurrencyId, $db) : formatCurrency($totalAmount);
                        ?>
                        <tr>
                            <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>Subtotal:</strong></td>
                            <td style="text-align: right; padding: 6px 4px;"><strong><?= $subtotalFormatted ?></strong></td>
                        </tr>
                        <?php if ($discountAmount > 0): ?>
                            <tr>
                                <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>Discount:</strong></td>
                                <td style="text-align: right; padding: 6px 4px;"><strong>-<?= $discountFormatted ?></strong></td>
                            </tr>
                        <?php endif; ?>
                        <?php
                        // Tax Breakdown (if fiscalized)
                        if (!empty($selectedSale['fiscal_receipt_taxes'])) {
                            // Group taxes by taxPercent and taxCode for display
                            $taxGroups = [];
                            foreach ($selectedSale['fiscal_receipt_taxes'] as $tax) {
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
                                $taxAmount = floatval($group['totalAmount']);
                                if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                                    $taxAmount = $taxAmount * $exchangeRate;
                                }
                                $taxAmountFormatted = $paymentCurrency ? formatCurrencyAmount($taxAmount, $paymentCurrencyId, $db) : formatCurrency($taxAmount);
                        ?>
                            <tr>
                                <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong><?= escapeHtml($label) ?>:</strong></td>
                                <td style="text-align: right; padding: 6px 4px;"><strong><?= $taxAmountFormatted ?></strong></td>
                            </tr>
                        <?php
                            endforeach;
                        }
                        ?>
                        <tr class="total-row">
                            <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>TOTAL:</strong></td>
                            <td style="text-align: right; padding: 6px 4px;"><strong><?= $totalFormatted ?></strong></td>
                        </tr>
                        <tr>
                            <td colspan="4" style="padding: 6px 4px; padding-top: 8px;">
                                <strong>Payment:</strong><br>
                                <?php 
                                // Ensure payments are loaded (fallback check)
                                if (!isset($selectedSale['payments']) || empty($selectedSale['payments'])) {
                                    // Try to fetch payments again as fallback
                                    $fallbackPayments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $selectedSale['id']]);
                                    if ($fallbackPayments !== false && !empty($fallbackPayments)) {
                                        $selectedSale['payments'] = $fallbackPayments;
                                        // Enrich with currency info from tenant database
                                        if (!function_exists('getBaseCurrency')) {
                                            require_once APP_PATH . '/includes/currency_functions.php';
                                        }
                                        foreach ($selectedSale['payments'] as &$payment) {
                                            if (!empty($payment['currency_id'])) {
                                                // Get currency from tenant database (where currencies table exists)
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
                                }
                                
                                $totalPaid = 0;
                                if (empty($selectedSale['payments'])): 
                                ?>
                                    <div style="margin-left: 10px; color: #999; font-style: italic;">No payment information available</div>
                                <?php else: 
                                    foreach ($selectedSale['payments'] as $payment): 
                                        // Use original_amount (in payment currency) if available, otherwise convert base_amount
                                        $paymentAmount = null;
                                        if (isset($payment['original_amount']) && $payment['original_amount'] !== null) {
                                            $paymentAmount = floatval($payment['original_amount']);
                                        } elseif (isset($payment['base_amount']) && $payment['base_amount'] !== null) {
                                            $baseAmount = floatval($payment['base_amount']);
                                            // Convert to payment currency if needed
                                            if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                                                $paymentAmount = $baseAmount * $exchangeRate;
                                            } else {
                                                $paymentAmount = $baseAmount;
                                            }
                                        } else {
                                            $paymentAmount = floatval($payment['amount']);
                                            // Convert to payment currency if needed
                                            if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                                                $paymentAmount = $paymentAmount * $exchangeRate;
                                            }
                                        }
                                        $totalPaid += $paymentAmount;
                                        
                                        // Display original amount and currency (already in payment currency)
                                        $displayAmount = isset($payment['original_amount']) ? floatval($payment['original_amount']) : $paymentAmount;
                                        $currencyCode = $payment['currency_code'] ?? ($paymentCurrency ? $paymentCurrency['code'] : ($baseCurrency ? $baseCurrency['code'] : 'USD'));
                                        $currencySymbol = $payment['currency_symbol'] ?? ($paymentCurrency ? $paymentCurrency['symbol'] : ($baseCurrency ? $baseCurrency['symbol'] : '$'));
                                        $symbolPosition = $payment['currency_symbol_position'] ?? ($paymentCurrency ? $paymentCurrency['symbol_position'] : ($baseCurrency ? $baseCurrency['symbol_position'] : 'before'));
                                        
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
                    </tfoot>
                </table>
                
                <?php if (!empty($selectedSale['fiscal_receipt'])): 
                    $fiscalReceipt = $selectedSale['fiscal_receipt'];
                    // Build fiscal details for display
                    $fiscalDetails = [
                        'receipt_global_no' => $fiscalReceipt['receipt_global_no'] ?? '',
                        'device_id' => $fiscalReceipt['device_id'] ?? '',
                        'verification_code' => $fiscalReceipt['receipt_verification_code'] ?? '',
                        'qr_code' => $fiscalReceipt['receipt_qr_data'] ?? ''
                    ];
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
                        if (isset($fiscalDetails['verification_code']) && !empty($fiscalDetails['verification_code'])): ?>
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
                <?php endif; ?>
                
                <div class="receipt-footer">
                    <div style="margin-bottom: 5px;">Thank you for your business!</div>
                    <div>
                        <?= SYSTEM_NAME ?> - <?= SYSTEM_VERSION ?? '1.0.0' ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-receipt-cutoff" style="font-size: 64px; color: #d1d5db;"></i>
                <h5 class="mt-3 text-muted">Select a receipt to view details</h5>
            </div>
        <?php endif; ?>
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
                    <input type="hidden" name="receipt_id" id="emailReceiptId">
                    <div class="mb-3">
                        <label for="emailAddress" class="form-label">Email Address *</label>
                        <input type="email" class="form-control" id="emailAddress" name="email" required>
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
                    <input type="hidden" name="receipt_id" id="whatsappReceiptId">
                    <div class="mb-3">
                        <label for="whatsappNumber" class="form-label">WhatsApp Number *</label>
                        <input type="text" class="form-control" id="whatsappNumber" name="phone" 
                               placeholder="e.g., +263771234567" required>
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

<script>
function showEmailModal(receiptId, email = '') {
    document.getElementById('emailReceiptId').value = receiptId;
    document.getElementById('emailAddress').value = email;
    const modal = new bootstrap.Modal(document.getElementById('emailModal'));
    modal.show();
}

function showWhatsAppModal(receiptId, phone = '') {
    document.getElementById('whatsappReceiptId').value = receiptId;
    document.getElementById('whatsappNumber').value = phone;
    const modal = new bootstrap.Modal(document.getElementById('whatsappModal'));
    modal.show();
}

function sendReceiptEmail() {
    const email = document.getElementById('emailAddress').value.trim();
    const receiptId = document.getElementById('emailReceiptId').value;
    
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
    const receiptId = document.getElementById('whatsappReceiptId').value;
    
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
</script>

<!-- Refund Modal -->
<div class="modal fade" id="refundModal" tabindex="-1" aria-labelledby="refundModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="refundModalLabel">
                    <i class="bi bi-arrow-counterclockwise"></i> Process Refund
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="refundSaleId" value="">
                
                <div class="mb-4">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Receipt Number:</strong> <span id="refundReceiptNumber"></span>
                        </div>
                        <div class="col-md-6">
                            <strong>Original Amount:</strong> <span id="refundOriginalAmount" class="text-primary"></span>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Sale Date:</strong> <span id="refundDate"></span>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><strong>Refund Type</strong></label>
                    <select class="form-select" id="refundType" onchange="toggleRefundType()">
                        <option value="full">Full Refund</option>
                        <option value="partial">Partial Refund</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><strong>Select Items to Refund</strong></label>
                    <div id="refundItemsList" style="max-height: 300px; overflow-y: auto;">
                        <!-- Items will be populated here -->
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <strong>Refund Total: <span id="refundTotalAmount" class="text-primary">$0.00</span></strong>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><strong>Refund Reason</strong> <span class="text-muted">(Optional)</span></label>
                    <select class="form-select" id="refundReason">
                        <option value="">Select a reason...</option>
                        <option value="Customer Request">Customer Request</option>
                        <option value="Defective Product">Defective Product</option>
                        <option value="Wrong Item">Wrong Item</option>
                        <option value="Duplicate Sale">Duplicate Sale</option>
                        <option value="Price Error">Price Error</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><strong>Notes</strong> <span class="text-muted">(Optional)</span></label>
                    <textarea class="form-control" id="refundNotes" rows="3" placeholder="Additional notes about this refund..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="processRefundBtn" onclick="processRefund()" disabled style="opacity: 0.5;">
                    <i class="bi bi-check-circle"></i> Process Refund
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('receiptSearch').addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    document.querySelectorAll('.receipt-item').forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
});

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

function showRefundModal(sale) {
    // Populate refund modal with sale data
    document.getElementById('refundSaleId').value = sale.id;
    document.getElementById('refundReceiptNumber').textContent = sale.receipt_number;
    document.getElementById('refundOriginalAmount').textContent = '$' + parseFloat(sale.total_amount).toFixed(2);
    document.getElementById('refundDate').textContent = new Date(sale.sale_date).toLocaleString();
    
    // Populate items
    const itemsContainer = document.getElementById('refundItemsList');
    itemsContainer.innerHTML = '';
    
    let totalRefundAmount = 0;
    
    sale.items.forEach((item, index) => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'refund-item mb-3 p-3 border rounded';
        itemDiv.innerHTML = `
            <div class="d-flex align-items-center mb-2">
                <input type="checkbox" class="form-check-input me-2 refund-item-checkbox" 
                       id="refundItem_${item.id}" 
                       data-item-id="${item.id}"
                       data-product-id="${item.product_id}"
                       data-unit-price="${item.unit_price}"
                       data-total-price="${item.total_price}"
                       data-quantity="${item.quantity}"
                       onchange="updateRefundTotal()"
                       checked>
                <label class="form-check-label flex-grow-1" for="refundItem_${item.id}">
                    <strong>${escapeHtml(item.product_name)}</strong>
                    <div class="text-muted small">
                        Qty: ${item.quantity} × $${parseFloat(item.unit_price).toFixed(2)} = $${parseFloat(item.total_price).toFixed(2)}
                    </div>
                </label>
                <div class="refund-item-amount fw-bold text-primary">
                    $${parseFloat(item.total_price).toFixed(2)}
                </div>
            </div>
            <div class="refund-item-quantity" style="display: none;">
                <label class="form-label small">Refund Quantity:</label>
                <input type="number" class="form-control form-control-sm" 
                       id="refundQty_${item.id}"
                       min="1" 
                       max="${item.quantity}" 
                       value="${item.quantity}"
                       onchange="updateRefundItemAmount(${item.id}, ${item.unit_price}, ${item.quantity})">
            </div>
        `;
        itemsContainer.appendChild(itemDiv);
        totalRefundAmount += parseFloat(item.total_price);
    });
    
    // Set refund type
    document.getElementById('refundType').value = 'full';
    document.getElementById('refundReason').value = '';
    document.getElementById('refundNotes').value = '';
    
    // Update total
    updateRefundTotal();
    
    // Show modal
    new bootstrap.Modal(document.getElementById('refundModal')).show();
}

function updateRefundItemAmount(itemId, unitPrice, maxQuantity) {
    const qtyInput = document.getElementById('refundQty_' + itemId);
    const checkbox = document.getElementById('refundItem_' + itemId);
    const amountDiv = checkbox.closest('.refund-item').querySelector('.refund-item-amount');
    
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
    amountDiv.textContent = '$' + amount.toFixed(2);
    
    // Update data attribute
    checkbox.setAttribute('data-refund-quantity', qty);
    checkbox.setAttribute('data-refund-amount', amount.toFixed(2));
    
    updateRefundTotal();
}

function updateRefundTotal() {
    const checkboxes = document.querySelectorAll('.refund-item-checkbox:checked');
    let total = 0;
    
    checkboxes.forEach(checkbox => {
        const refundQty = parseInt(checkbox.getAttribute('data-refund-quantity')) || parseInt(checkbox.getAttribute('data-quantity'));
        const unitPrice = parseFloat(checkbox.getAttribute('data-unit-price'));
        const amount = refundQty * unitPrice;
        total += amount;
    });
    
    document.getElementById('refundTotalAmount').textContent = '$' + total.toFixed(2);
    
    // Enable/disable process button
    const processBtn = document.getElementById('processRefundBtn');
    if (total > 0) {
        processBtn.disabled = false;
        processBtn.style.opacity = '1';
    } else {
        processBtn.disabled = true;
        processBtn.style.opacity = '0.5';
    }
}

function toggleRefundType() {
    const refundType = document.getElementById('refundType').value;
    const quantityInputs = document.querySelectorAll('.refund-item-quantity');
    
    if (refundType === 'partial') {
        quantityInputs.forEach(div => div.style.display = 'block');
        // Uncheck all items first
        document.querySelectorAll('.refund-item-checkbox').forEach(cb => {
            cb.checked = false;
            updateRefundTotal();
        });
    } else {
        quantityInputs.forEach(div => div.style.display = 'none');
        // Check all items with full quantity
        document.querySelectorAll('.refund-item-checkbox').forEach(cb => {
            cb.checked = true;
            const qty = parseInt(cb.getAttribute('data-quantity'));
            cb.setAttribute('data-refund-quantity', qty);
            updateRefundTotal();
        });
    }
    updateRefundTotal();
}

function processRefund() {
    const saleId = document.getElementById('refundSaleId').value;
    const refundType = document.getElementById('refundType').value;
    const reason = document.getElementById('refundReason').value;
    const notes = document.getElementById('refundNotes').value;
    
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
    
    // Calculate total refund amount
    const totalRefund = selectedItems.reduce((sum, item) => sum + item.total_price, 0);
    
    // Show confirmation
    Swal.fire({
        title: 'Confirm Refund',
        html: `
            <p>Refund Type: <strong>${refundType === 'full' ? 'Full' : 'Partial'}</strong></p>
            <p>Items: <strong>${selectedItems.length}</strong></p>
            <p>Total Refund Amount: <strong>$${totalRefund.toFixed(2)}</strong></p>
            ${reason ? `<p>Reason: ${escapeHtml(reason)}</p>` : ''}
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
                    reason: reason,
                    notes: notes
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: 'Refund processed successfully',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Close modal
                        bootstrap.Modal.getInstance(document.getElementById('refundModal')).hide();
                        // Reload page to show updated status
                        window.location.reload();
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
                        // Redirect to manage sales without the deleted receipt
                        window.location.href = '<?= BASE_URL ?>modules/pos/manage.php?start_date=<?= $startDate ?>&end_date=<?= $endDate ?>';
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

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


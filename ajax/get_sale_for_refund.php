<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$auth->requirePermission('pos.access');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
    exit;
}

try {
    $db = Database::getInstance();
    require_once APP_PATH . '/includes/currency_functions.php';
    require_once APP_PATH . '/includes/settings_functions.php';
    
    // Get sale with all details including customer and cashier info
    $sale = $db->getRow("SELECT s.*, 
                          c.first_name, c.last_name, c.email, c.phone, c.address, c.company_name,
                          u.first_name as cashier_first, u.last_name as cashier_last, u.username as cashier_username,
                          b.branch_name, s.branch_id
                          FROM sales s 
                          LEFT JOIN customers c ON s.customer_id = c.id 
                          LEFT JOIN users u ON s.user_id = u.id 
                          LEFT JOIN branches b ON s.branch_id = b.id
                          WHERE s.id = :id", [':id' => $id]);
    
    // Ensure delivery_cost is included (default to 0 if null)
    $sale['delivery_cost'] = floatval($sale['delivery_cost'] ?? 0);
    
    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'Sale not found']);
        exit;
    }
    
    // Extract branch_id for tax rate lookup
    $branchId = isset($sale['branch_id']) ? intval($sale['branch_id']) : null;
    
    // Check if already refunded
    if ($sale['payment_status'] === 'refunded') {
        echo json_encode(['success' => false, 'message' => 'This sale has already been refunded']);
        exit;
    }
    
    // Get tax settings for refund calculation
    $pricesIncludeTax = getSetting('prices_include_tax', '1') == '1';
    $defaultTaxRate = getDefaultTaxRate();
    
    // Check if sale was fiscalized (must be done before getting fiscal receipt lines)
    $primaryDb = Database::getPrimaryInstance();
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id LIMIT 1",
        [':sale_id' => $id]
    );
    
    // Get sale items with product tax information
    $items = $db->getRows("SELECT si.*, p.barcode, p.sku, p.tax_id as product_tax_id,
                           pc.tax_id as category_tax_id
                          FROM sale_items si
                          LEFT JOIN products p ON si.product_id = p.id
                          LEFT JOIN product_categories pc ON p.category_id = pc.id
                          WHERE si.sale_id = :id", [':id' => $id]);
    if ($items === false) {
        $items = [];
    }
    
    // CRITICAL: Get EXACT fiscalized prices from fiscal_receipt_lines for FISCALIZATION use only
    // These are kept separate from display prices - fiscalization will use these
    $fiscalReceiptLines = [];
    if ($fiscalReceipt) {
        $fiscalReceiptLines = $primaryDb->getRows(
            "SELECT frl.*, frl.receipt_line_price as fiscal_unit_price, frl.receipt_line_total as fiscal_total_price,
                    frl.tax_percent as fiscal_tax_percent, frl.tax_id as fiscal_tax_id, frl.sale_item_id
             FROM fiscal_receipt_lines frl
             WHERE frl.fiscal_receipt_id = :fiscal_receipt_id 
             AND frl.receipt_line_type = 'Sale'
             ORDER BY frl.receipt_line_no",
            [':fiscal_receipt_id' => $fiscalReceipt['id']]
        );
        if ($fiscalReceiptLines === false) {
            $fiscalReceiptLines = [];
        }
    }
    
    // Map fiscalized prices to sale items by sale_item_id (for fiscalization use only)
    $fiscalPriceMap = [];
    foreach ($fiscalReceiptLines as $fiscalLine) {
        if (!empty($fiscalLine['sale_item_id'])) {
            $fiscalPriceMap[$fiscalLine['sale_item_id']] = [
                'unit_price' => floatval($fiscalLine['fiscal_unit_price']),
                'total_price' => floatval($fiscalLine['fiscal_total_price']),
                'tax_percent' => isset($fiscalLine['fiscal_tax_percent']) ? floatval($fiscalLine['fiscal_tax_percent']) : null,
                'tax_id' => intval($fiscalLine['fiscal_tax_id'])
            ];
        }
    }
    
    // Get applicable taxes to determine product tax rates
    $productTaxRates = [];
    if ($branchId) {
        try {
            $device = $primaryDb->getRow(
                "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1 LIMIT 1",
                [':branch_id' => $branchId]
            );
            
            if ($device) {
                $config = $primaryDb->getRow(
                    "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id LIMIT 1",
                    [':branch_id' => $branchId, ':device_id' => $device['device_id']]
                );
                
                if ($config && !empty($config['applicable_taxes'])) {
                    $applicableTaxes = json_decode($config['applicable_taxes'], true);
                    if (is_array($applicableTaxes)) {
                        foreach ($applicableTaxes as $tax) {
                            if (isset($tax['taxID']) && isset($tax['taxPercent']) && $tax['taxPercent'] !== null) {
                                $productTaxRates[intval($tax['taxID'])] = floatval($tax['taxPercent']);
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Fallback to default tax rate if fiscal config not available
            error_log("GET SALE FOR REFUND: Could not get fiscal config: " . $e->getMessage());
        }
    }
    
    // Attach fiscalized prices AND calculate display prices for each item
    foreach ($items as &$item) {
        // Attach fiscalized prices (for fiscalization use only)
        if (isset($fiscalPriceMap[$item['id']])) {
            $item['fiscal_unit_price'] = $fiscalPriceMap[$item['id']]['unit_price'];
            $item['fiscal_total_price'] = $fiscalPriceMap[$item['id']]['total_price'];
            $item['fiscal_tax_percent'] = $fiscalPriceMap[$item['id']]['tax_percent'];
            $item['fiscal_tax_id'] = $fiscalPriceMap[$item['id']]['tax_id'];
        }
        
        // Calculate DISPLAY prices (what customer actually paid)
        // If prices_include_tax is enabled, stored prices are WITHOUT tax, so add tax back
        $displayUnitPrice = floatval($item['unit_price']);
        $displayTotalPrice = floatval($item['total_price']);
        
        if ($pricesIncludeTax) {
            // Get the product's actual tax rate
            $productTaxRate = null;
            
            // Priority 1: Use fiscalized tax rate if available (most accurate)
            if (isset($item['fiscal_tax_percent']) && $item['fiscal_tax_percent'] !== null) {
                $productTaxRate = floatval($item['fiscal_tax_percent']);
            }
            // Priority 2: Product's own tax_id
            elseif (!empty($item['product_tax_id']) && isset($productTaxRates[intval($item['product_tax_id'])])) {
                $productTaxRate = $productTaxRates[intval($item['product_tax_id'])];
            }
            // Priority 3: Category's tax_id
            elseif (!empty($item['category_tax_id']) && isset($productTaxRates[intval($item['category_tax_id'])])) {
                $productTaxRate = $productTaxRates[intval($item['category_tax_id'])];
            }
            // Fallback: Use default tax rate
            elseif ($defaultTaxRate > 0) {
                $productTaxRate = $defaultTaxRate;
            }
            
            // Convert stored price (without tax) back to price with tax
            if ($productTaxRate > 0) {
                $taxDecimal = $productTaxRate / 100;
                $displayUnitPrice = $displayUnitPrice * (1 + $taxDecimal);
                $displayTotalPrice = $displayTotalPrice * (1 + $taxDecimal);
            }
        }
        // If prices_include_tax is false, display prices = stored prices (no conversion needed)
        
        // Round to 2 decimal places
        $item['display_unit_price'] = round($displayUnitPrice, 2);
        $item['display_total_price'] = round($displayTotalPrice, 2);
    }
    unset($item);
    
    // Get payments with currency info
    $payments = $db->getRows("SELECT sp.*, c.code as currency_code, c.name as currency_name, c.symbol as currency_symbol
                              FROM sale_payments sp
                              LEFT JOIN currencies c ON sp.currency_id = c.id
                              WHERE sp.sale_id = :id", [':id' => $id]);
    if ($payments === false) {
        $payments = [];
    }
    
    // Get currencies for refund payment method selection
    $currencies = getActiveCurrencies(null);
    $baseCurrency = getBaseCurrency(null);
    
    $sale['items'] = $items;
    $sale['payments'] = $payments;
    $sale['currencies'] = $currencies;
    $sale['base_currency'] = $baseCurrency;
    $sale['fiscalized'] = $fiscalReceipt ? true : false;
    $sale['fiscal_receipt'] = $fiscalReceipt;
    $sale['prices_include_tax'] = $pricesIncludeTax;
    $sale['default_tax_rate'] = $defaultTaxRate;
    
    echo json_encode(['success' => true, 'sale' => $sale]);
    
} catch (Exception $e) {
    logError("Error loading sale for refund: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load sale: ' . $e->getMessage()]);
}


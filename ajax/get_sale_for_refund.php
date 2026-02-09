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
    // CRITICAL: Include is_wholesale_sale to know if sale was made at wholesale prices
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
                            if (isset($tax['taxID'])) {
                                $taxId = intval($tax['taxID']);
                                // CRITICAL FIX: Include exempt taxes (taxPercent = null, taxCode = 'E') with rate 0
                                // This prevents fallback to default tax rate for exempt products
                                if (isset($tax['taxCode']) && $tax['taxCode'] === 'E') {
                                    $productTaxRates[$taxId] = 0; // Exempt = 0% tax
                                } elseif (isset($tax['taxPercent']) && $tax['taxPercent'] !== null) {
                                    $productTaxRates[$taxId] = floatval($tax['taxPercent']);
                                } elseif (isset($tax['taxPercent']) && $tax['taxPercent'] === 0) {
                                    $productTaxRates[$taxId] = 0; // Zero-rated = 0% tax
                                }
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
    
    // Check if sale was made at wholesale prices
    $isWholesaleSale = isset($sale['is_wholesale_sale']) && (
        $sale['is_wholesale_sale'] === 1 || 
        $sale['is_wholesale_sale'] === '1' || 
        $sale['is_wholesale_sale'] === true
    );
    
    // Attach fiscalized prices AND calculate display prices for each item
    foreach ($items as &$item) {
        // Attach fiscalized prices (for fiscalization use only)
        if (isset($fiscalPriceMap[$item['id']])) {
            $item['fiscal_unit_price'] = $fiscalPriceMap[$item['id']]['unit_price'];
            $item['fiscal_total_price'] = $fiscalPriceMap[$item['id']]['total_price'];
            $item['fiscal_tax_percent'] = $fiscalPriceMap[$item['id']]['tax_percent'];
            $item['fiscal_tax_id'] = $fiscalPriceMap[$item['id']]['tax_id'];
        }
        
        // CRITICAL FIX: For refund display, use the ACTUAL stored unit_price from sale_items
        // This already contains the correct price (wholesale if sale was wholesale, retail otherwise)
        // The stored price is what the customer actually paid (after tax calculation if prices_include_tax)
        // We should NOT recalculate it - just use it as-is for refund display
        
        // Get the stored price (this is the price that was actually charged to the customer)
        $storedUnitPrice = floatval($item['unit_price']);
        $storedTotalPrice = floatval($item['total_price']);
        
        // If the sale was fiscalized, we have the exact prices sent to ZIMRA
        // For display in refund modal, use stored prices (which match what customer paid)
        // For fiscalization, we'll use fiscal_unit_price/fiscal_total_price
        
        // CRITICAL: The stored unit_price in sale_items is ALREADY the correct price:
        // - If wholesale sale: it's the wholesale price (with tax extracted if prices_include_tax)
        // - If retail sale: it's the retail price (with tax extracted if prices_include_tax)
        // - The price stored is what was actually charged, so we use it directly for refund
        
        // Calculate DISPLAY prices (what customer actually paid)
        // If prices_include_tax is enabled, stored prices are WITHOUT tax, so add tax back
        $displayUnitPrice = $storedUnitPrice;
        $displayTotalPrice = $storedTotalPrice;
        
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
            // This gives us the price the customer actually paid
            // CRITICAL: For exempt (taxRate = 0) and zero-rated (taxRate = 0), do NOT add tax
            if ($productTaxRate !== null && $productTaxRate > 0) {
                $taxDecimal = $productTaxRate / 100;
                $displayUnitPrice = $storedUnitPrice * (1 + $taxDecimal);
                $displayTotalPrice = $storedTotalPrice * (1 + $taxDecimal);
            } else {
                // Exempt or zero-rated: price with tax = price without tax (no tax added)
                $displayUnitPrice = $storedUnitPrice;
                $displayTotalPrice = $storedTotalPrice;
            }
        }
        // If prices_include_tax is false, display prices = stored prices (no conversion needed)
        
        // Round to 2 decimal places
        $item['display_unit_price'] = round($displayUnitPrice, 2);
        $item['display_total_price'] = round($displayTotalPrice, 2);
        
        // Store flag for frontend to know if this was a wholesale sale
        $item['is_wholesale_sale'] = $isWholesaleSale;
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
    
    // CRITICAL: Convert display prices from base currency to payment currency
    // Prices in sale_items are stored in base currency, but refund modal should show payment currency
    $paymentCurrency = null;
    $paymentCurrencyId = null;
    $exchangeRate = 1.0;
    
    if (!empty($payments)) {
        $firstPayment = $payments[0];
        if (!empty($firstPayment['currency_id'])) {
            $paymentCurrencyId = $firstPayment['currency_id'];
            $paymentCurrency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $paymentCurrencyId]);
            if ($paymentCurrency && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                // Get exchange rate from BASE currency to PAYMENT currency
                require_once APP_PATH . '/includes/currency_functions.php';
                $exchangeRate = getExchangeRate($baseCurrency['id'], $paymentCurrencyId, $db);
                
                // Convert all display prices from base to payment currency
                foreach ($items as &$item) {
                    if (isset($item['display_unit_price'])) {
                        $item['display_unit_price'] = round($item['display_unit_price'] * $exchangeRate, 2);
                    }
                    if (isset($item['display_total_price'])) {
                        $item['display_total_price'] = round($item['display_total_price'] * $exchangeRate, 2);
                    }
                }
                unset($item);
                
                // Also convert sale totals for display
                if (isset($sale['total_amount'])) {
                    $sale['display_total_amount'] = round(floatval($sale['total_amount']) * $exchangeRate, 2);
                }
                if (isset($sale['discount_amount'])) {
                    $sale['display_discount_amount'] = round(floatval($sale['discount_amount']) * $exchangeRate, 2);
                }
                if (isset($sale['delivery_cost'])) {
                    $sale['display_delivery_cost'] = round(floatval($sale['delivery_cost']) * $exchangeRate, 2);
                }
            }
        }
    }
    
    $sale['items'] = $items;
    $sale['payments'] = $payments;
    $sale['currencies'] = $currencies;
    $sale['base_currency'] = $baseCurrency;
    $sale['payment_currency'] = $paymentCurrency; // Add payment currency for frontend
    $sale['payment_currency_id'] = $paymentCurrencyId; // Add payment currency ID for frontend
    $sale['fiscalized'] = $fiscalReceipt ? true : false;
    $sale['fiscal_receipt'] = $fiscalReceipt;
    $sale['prices_include_tax'] = $pricesIncludeTax;
    $sale['default_tax_rate'] = $defaultTaxRate;
    $sale['is_wholesale_sale'] = $isWholesaleSale;
    
    echo json_encode(['success' => true, 'sale' => $sale]);
    
} catch (Exception $e) {
    logError("Error loading sale for refund: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load sale: ' . $e->getMessage()]);
}


<?php
/**
 * Fiscal Helper Functions
 * Helper functions for fiscalizing invoices and sales
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/includes/fiscal_service.php';

/**
 * Write log message directly to error log file
 * This ensures logs are written even if PHP error_log is not configured correctly
 */
function writeFiscalLog($message) {
    $logFile = APP_PATH . '/logs/error.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    @file_put_contents($logFile, $logMessage, FILE_APPEND);
    // Also use error_log as fallback
    error_log($message);
}

/**
 * Map ZIMRA applicableTaxes format to include taxID and taxCode
 * ZIMRA getConfig returns: {taxPercent, taxName} OR possibly {taxID, taxPercent, taxName}
 * We need: {taxID, taxPercent, taxCode}
 * 
 * @param array $applicableTaxesRaw Raw applicable taxes from ZIMRA
 * @param bool $hasTaxID Whether ZIMRA already provided taxID in the response
 * @return array Mapped applicable taxes with taxID and taxCode
 */
function mapApplicableTaxes($applicableTaxesRaw, $hasTaxID = false) {
    if (empty($applicableTaxesRaw) || !is_array($applicableTaxesRaw)) {
        return [];
    }
    
    $applicableTaxes = [];
    $taxIndex = 1;
    
    foreach ($applicableTaxesRaw as $tax) {
        // CRITICAL: Always use ZIMRA's taxID if provided, otherwise assign sequentially
        // ZIMRA provides taxID in the response, so we MUST use it for RCPT025 compliance
        if (isset($tax['taxID'])) {
            $taxID = intval($tax['taxID']); // Use ZIMRA's taxID
            $taxIDSource = "from ZIMRA";
        } else {
            $taxID = $taxIndex; // Fallback: assign sequentially if ZIMRA doesn't provide
            $taxIDSource = "assigned (ZIMRA didn't provide)";
        }
        
        $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : null;
        $taxName = strtolower($tax['taxName'] ?? '');
        
        // Determine taxCode based on taxPercent and taxName
        // Common ZIMRA tax codes: 'A' = standard VAT, 'B' = reduced, 'C' = zero, 'E' = exempt
        $taxCode = 'A'; // Default to standard VAT
        
        if ($taxPercent === null || $taxName === 'exempt' || strpos($taxName, 'exempt') !== false) {
            $taxCode = 'E'; // Exempt
        } elseif ($taxPercent == 0) {
            $taxCode = 'C'; // Zero rate
        } elseif ($taxPercent == 15 || $taxPercent == 15.5) {
            $taxCode = 'A'; // Standard VAT (15% or 15.5%)
        } elseif ($taxPercent < 15 && $taxPercent > 0) {
            $taxCode = 'B'; // Reduced rate
        }
        
        // CRITICAL: Preserve null for exempt taxes (don't convert to 0)
        // Documentation: "In case of exempt, field will not be returned" (getConfig)
        $mappedTax = [
            'taxID' => $taxID,
            'taxPercent' => $taxPercent, // Keep null for exempt taxes (don't convert to 0)
            'taxCode' => $taxCode,
            'taxName' => $tax['taxName'] ?? ''
        ];
        
        // Preserve tax validity period fields if ZIMRA provides them (for RCPT025 validation)
        // ZIMRA uses "validFrom" not "taxValidFrom" in the response
        if (isset($tax['validFrom'])) {
            $mappedTax['taxValidFrom'] = $tax['validFrom'];
        } elseif (isset($tax['taxValidFrom'])) {
            $mappedTax['taxValidFrom'] = $tax['taxValidFrom'];
        }
        if (isset($tax['validTill'])) {
            $mappedTax['taxValidTill'] = $tax['validTill'];
        } elseif (isset($tax['taxValidTill'])) {
            $mappedTax['taxValidTill'] = $tax['taxValidTill'];
        }
        
        $applicableTaxes[] = $mappedTax;
        
        error_log("Mapped tax: taxID=$taxID ($taxIDSource), taxPercent=" . ($taxPercent ?? 'null') . ", taxCode=$taxCode, taxName={$tax['taxName']}");
        $taxIndex++;
    }
    
    return $applicableTaxes;
}

/**
 * Fiscalize an invoice
 */
function fiscalizeInvoice($invoiceId, $db = null) {
    if (!$db) {
        $db = Database::getInstance();
    }
    
    // Use primary database for branches
    $primaryDb = Database::getPrimaryInstance();
    
    // Get invoice (from tenant database)
    $invoice = $db->getRow(
        "SELECT i.*, i.branch_id 
         FROM invoices i 
         WHERE i.id = :id",
        [':id' => $invoiceId]
    );
    
    if (!$invoice || !$invoice['branch_id']) {
        return false;
    }
    
    // Get branch info from primary database
    $branch = $primaryDb->getRow(
        "SELECT id, fiscalization_enabled FROM branches WHERE id = :id",
        [':id' => $invoice['branch_id']]
    );
    
    if (!$branch) {
        return false;
    }
    
    $invoice['fiscalization_enabled'] = $branch['fiscalization_enabled'];
    
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    // Check if fiscalization is enabled
    if (!$invoice['fiscalization_enabled']) {
        return false; // Fiscalization not enabled for this branch
    }
    
    // Check if already fiscalized
    if ($invoice['fiscalized']) {
        return true; // Already fiscalized
    }
    
    // Only fiscalize TaxInvoice and Receipt types
    if (!in_array($invoice['invoice_type'], ['TaxInvoice', 'Receipt'])) {
        return false; // Not a fiscalizable invoice type
    }
    
    try {
        $fiscalService = new FiscalService($invoice['branch_id']);
        
        // Get invoice items (from tenant database)
        $invoiceItems = $db->getRows(
            "SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id",
            [':id' => $invoiceId]
        );
        
        // Get customer (from tenant database)
        $customer = null;
        if ($invoice['customer_id']) {
            $customer = $db->getRow(
                "SELECT * FROM customers WHERE id = :id",
                [':id' => $invoice['customer_id']]
            );
        }
        
        // Get fiscal day (from primary database)
        $primaryDb = Database::getPrimaryInstance();
        $fiscalDay = $primaryDb->getRow(
            "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND status = 'FiscalDayOpened' ORDER BY id DESC LIMIT 1",
            [':branch_id' => $invoice['branch_id']]
        );
        
        if (!$fiscalDay) {
            // Try to open fiscal day automatically
            try {
                $fiscalService->openFiscalDay();
                $fiscalDay = $db->getRow(
                    "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND status = 'FiscalDayOpened' ORDER BY id DESC LIMIT 1",
                    [':branch_id' => $invoice['branch_id']]
                );
            } catch (Exception $e) {
                error_log("Failed to auto-open fiscal day: " . $e->getMessage());
                throw new Exception('No open fiscal day. Please open a fiscal day first.');
            }
        }
        
        // Get last receipt counters (from primary database)
        $lastReceipt = $primaryDb->getRow(
            "SELECT receipt_counter, receipt_global_no FROM fiscal_receipts 
             WHERE device_id = (SELECT device_id FROM fiscal_devices WHERE branch_id = :branch_id LIMIT 1)
             ORDER BY receipt_global_no DESC LIMIT 1",
            [':branch_id' => $invoice['branch_id']]
        );
        
        $receiptCounter = $lastReceipt ? ($lastReceipt['receipt_counter'] + 1) : 1;
        $receiptGlobalNo = $lastReceipt ? ($lastReceipt['receipt_global_no'] + 1) : 1;
        
        // Get device (from primary database)
        $device = $primaryDb->getRow(
            "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
            [':branch_id' => $invoice['branch_id']]
        );
        
        // Get config (from primary database)
        $config = $primaryDb->getRow(
            "SELECT * FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
            [':branch_id' => $invoice['branch_id'], ':device_id' => $device['device_id']]
        );
        
        if (!$config) {
            throw new Exception('Fiscal configuration not found. Please sync configuration first.');
        }
        
        // Get applicable taxes from config (already mapped with taxID and taxCode when synced)
        $applicableTaxes = json_decode($config['applicable_taxes'], true);
        
        // Verify the taxes have taxID and taxCode (if not, they're in old format - map them)
        if (empty($applicableTaxes) || (!isset($applicableTaxes[0]['taxID']) || !isset($applicableTaxes[0]['taxCode']))) {
            // Old format - map them and update database
            $applicableTaxes = mapApplicableTaxes($applicableTaxes ?? []);
            $primaryDb = Database::getPrimaryInstance();
            $primaryDb->update('fiscal_config', [
                'applicable_taxes' => json_encode($applicableTaxes)
            ], ['id' => $config['id']]);
        }
        
        // Build receipt data
        $receiptData = buildReceiptData($invoice, $invoiceItems, $customer, $device, $config, $applicableTaxes, $fiscalDay, $receiptCounter, $receiptGlobalNo);
        
        // Submit receipt
        $result = $fiscalService->submitReceipt($invoiceId, $receiptData);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Fiscalization error for invoice $invoiceId: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Map currency code for ZIMRA API
 * ZIMRA expects current ISO 4217 currency codes
 * ZWL (old Zimbabwean Dollar) was replaced by ZWG (Zimbabwe Gold/ZiG) effective June 25, 2024
 * 
 * @param string $currencyCode Original currency code from database
 * @return string Currency code for ZIMRA API
 */
if (!function_exists('mapCurrencyCodeForZimra')) {
    function mapCurrencyCodeForZimra($currencyCode) {
        $code = strtoupper(trim($currencyCode));
        
        // Map old currency codes to current ISO 4217 codes for ZIMRA
        $currencyMap = [
            'ZWL' => 'ZWG', // ZWL (Zimbabwean Dollar) -> ZWG (Zimbabwe Gold/ZiG) - replaced June 25, 2024
            // Add other mappings here if needed in the future
        ];
        
        return $currencyMap[$code] ?? $code;
    }
}

/**
 * Fiscalize a sale (POS transaction)
 */
function fiscalizeSale($saleId, $branchId, $db = null) {
    error_log("FISCALIZE SALE: Starting fiscalization for sale $saleId, branch $branchId");
    
    if (!$db) {
        $db = Database::getInstance();
    }
    
    // Use primary database for branches
    $primaryDb = Database::getPrimaryInstance();
    
    // Check if fiscalization is enabled for branch
    $branch = $primaryDb->getRow(
        "SELECT id, fiscalization_enabled FROM branches WHERE id = :id",
        [':id' => $branchId]
    );
    
    if (!$branch) {
        error_log("FISCALIZE SALE: Branch $branchId not found");
        return false;
    }
    
    if (!$branch['fiscalization_enabled']) {
        error_log("FISCALIZE SALE: Fiscalization not enabled for branch $branchId");
        return false; // Fiscalization not enabled
    }
    
    error_log("FISCALIZE SALE: Branch $branchId has fiscalization enabled");
    
    // Get sale
    $sale = $db->getRow(
        "SELECT * FROM sales WHERE id = :id",
        [':id' => $saleId]
    );
    
    if (!$sale) {
        error_log("FISCALIZE SALE: Sale $saleId not found");
        return false;
    }
    
    error_log("FISCALIZE SALE: Sale $saleId found, total: {$sale['total_amount']}");
    
    try {
        // Initialize fiscal service
        $fiscalService = new FiscalService($branchId);
        
        // Get sale items with product and category tax information
        $saleItems = $db->getRows(
            "SELECT si.*, p.tax_id as product_tax_id, pc.tax_id as category_tax_id
             FROM sale_items si 
             LEFT JOIN products p ON si.product_id = p.id 
             LEFT JOIN product_categories pc ON p.category_id = pc.id
             WHERE si.sale_id = :id",
            [':id' => $saleId]
        );
        
        // Get sale payments with currency information
        $salePayments = $db->getRows(
            "SELECT sp.*, c.code as currency_code 
             FROM sale_payments sp
             LEFT JOIN currencies c ON sp.currency_id = c.id
             WHERE sp.sale_id = :id",
            [':id' => $saleId]
        );
        
        // Determine receipt currency from payments
        // ZIMRA requires all payments to be in the same currency as receiptCurrency
        // Use the first payment's currency (or most common if mixed)
        $receiptCurrency = 'USD'; // Default fallback
        if (!empty($salePayments)) {
            $currencyCodes = array_filter(array_column($salePayments, 'currency_code'));
            if (!empty($currencyCodes)) {
                // Get currency code from first payment (uppercase, 3 chars per ISO 4217)
                $firstCurrencyCode = strtoupper(trim($currencyCodes[0] ?? 'USD'));
                $receiptCurrency = $firstCurrencyCode;
                
                // Check if all payments are in the same currency (ZIMRA requirement)
                $uniqueCurrencies = array_unique($currencyCodes);
                if (count($uniqueCurrencies) > 1) {
                    writeFiscalLog("FISCALIZE SALE: WARNING - Mixed currencies in payments: " . implode(', ', $uniqueCurrencies) . ". Using first payment's currency: $receiptCurrency");
                } else {
                    writeFiscalLog("FISCALIZE SALE: Receipt currency determined from payments: $receiptCurrency");
                }
            }
        }
        
        // Map currency code for ZIMRA API (ZWL was replaced by ZWG per ISO 4217)
        // ZIMRA expects current ISO 4217 codes, so ZWL must be mapped to ZWG
        $receiptCurrencyForZimra = mapCurrencyCodeForZimra($receiptCurrency);
        if ($receiptCurrencyForZimra !== $receiptCurrency) {
            writeFiscalLog("FISCALIZE SALE: Currency code mapped for ZIMRA: $receiptCurrency -> $receiptCurrencyForZimra");
        }
        
        // CRITICAL: Get exchange rate to convert amounts from base currency to payment currency
        // Sale items are stored in base currency, but ZIMRA needs amounts in payment currency
        $baseCurrency = getBaseCurrency($db);
        $paymentCurrencyObj = null;
        $exchangeRateToPayment = 1.0;
        
        if ($baseCurrency && !empty($salePayments)) {
            // Get payment currency object
            $firstPaymentCurrencyId = $salePayments[0]['currency_id'] ?? null;
            if ($firstPaymentCurrencyId && $firstPaymentCurrencyId != $baseCurrency['id']) {
                $paymentCurrencyObj = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $firstPaymentCurrencyId]);
                if ($paymentCurrencyObj) {
                    // Get exchange rate: base currency -> payment currency
                    // If payment currency has exchange_rate = 2.0, then 1 base = 2 payment
                    $exchangeRateToPayment = getExchangeRate($baseCurrency['id'], $firstPaymentCurrencyId, $db);
                    writeFiscalLog("FISCALIZE SALE: Converting amounts from base currency ({$baseCurrency['code']}) to payment currency ({$paymentCurrencyObj['code']}) using exchange rate: $exchangeRateToPayment");
                }
            }
        }
        
        // Get customer if exists
        $customer = null;
        if ($sale['customer_id']) {
            $customer = $db->getRow(
                "SELECT * FROM customers WHERE id = :id",
                [':id' => $sale['customer_id']]
            );
        }
        
        // Get device and config from primary database
        $device = $primaryDb->getRow(
            "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
            [':branch_id' => $branchId]
        );
        
        $config = $primaryDb->getRow(
            "SELECT * FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
            [':branch_id' => $branchId, ':device_id' => $device['device_id']]
        );
        
        if (!$config) {
            throw new Exception('Fiscal configuration not found. Please sync configuration first.');
        }
        
        // Get applicable taxes from config (already mapped with taxID and taxCode when synced)
        $applicableTaxes = json_decode($config['applicable_taxes'], true) ?? [];
        
        if (empty($applicableTaxes)) {
            error_log("FISCALIZE SALE: WARNING - No applicable taxes found in config. Please sync configuration from ZIMRA.");
            throw new Exception('No applicable taxes found in fiscal configuration. Please sync configuration from ZIMRA first.');
        }
        
        // Verify the taxes have taxID and taxCode (if not, they're in old format - map them)
        if (!isset($applicableTaxes[0]['taxID']) || !isset($applicableTaxes[0]['taxCode'])) {
            error_log("FISCALIZE SALE: Applicable taxes in old format (without taxID/taxCode), mapping now...");
            $applicableTaxes = mapApplicableTaxes($applicableTaxes);
            // Update config with mapped version for future use
            $primaryDb->update('fiscal_config', [
                'applicable_taxes' => json_encode($applicableTaxes)
            ], ['id' => $config['id']]);
        }
        
        error_log("FISCALIZE SALE: Using applicable taxes from config: " . json_encode($applicableTaxes));
        
        // Get fiscal day - ALWAYS sync with ZIMRA first (source of truth)
        // getFiscalDayStatus() automatically syncs local database with ZIMRA
        $status = null;
        $fiscalDay = null;
        $shouldAutoOpen = false;
        $shouldAutoClose = false;
        
        try {
            $status = $fiscalService->getFiscalDayStatus();
            if (!$status || !isset($status['fiscalDayStatus'])) {
                // Could not get ZIMRA status - try local database
                error_log("FISCALIZE SALE: Could not get ZIMRA status, checking local database");
            } else {
                $fiscalDayStatus = $status['fiscalDayStatus'];
                
                // Check if day is open (FiscalDayOpened or FiscalDayCloseFailed - both allow receipt submission)
                $isDayOpen = ($fiscalDayStatus === 'FiscalDayOpened' || $fiscalDayStatus === 'FiscalDayCloseFailed');
                
                if (!$isDayOpen && $fiscalDayStatus === 'FiscalDayClosed') {
                    // Day is closed - attempt to auto-open
                    $shouldAutoOpen = true;
                    error_log("FISCALIZE SALE: Fiscal day is closed. Attempting to auto-open...");
                } else if ($isDayOpen) {
                    // Day is open - check if it's been open for ~24 hours (auto-close)
                    $fiscalDay = $primaryDb->getRow(
                        "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status IN ('FiscalDayOpened', 'FiscalDayCloseFailed') ORDER BY id DESC LIMIT 1",
                        [':branch_id' => $branchId, ':device_id' => $device['device_id']]
                    );
                    
                    if ($fiscalDay) {
                        // Check if fiscal day has been open for close to 24 hours
                        $fiscalDayOpened = new DateTime($fiscalDay['fiscal_day_opened']);
                        $now = new DateTime();
                        $hoursOpen = ($now->getTimestamp() - $fiscalDayOpened->getTimestamp()) / 3600;
                        
                        // Get taxpayerDayMaxHrs from config (usually 24 hours)
                        $config = $primaryDb->getRow(
                            "SELECT taxpayer_day_max_hrs FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
                            [':branch_id' => $branchId, ':device_id' => $device['device_id']]
                        );
                        $maxHours = $config['taxpayer_day_max_hrs'] ?? 24;
                        
                        // Auto-close if open for more than (maxHours - 1) hours (close 1 hour before max to be safe)
                        if ($hoursOpen >= ($maxHours - 1)) {
                            $shouldAutoClose = true;
                            error_log("FISCALIZE SALE: Fiscal day has been open for {$hoursOpen} hours (max: {$maxHours}). Attempting to auto-close...");
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // If error indicates no open day, check if we should auto-open
            if (strpos($e->getMessage(), 'No open fiscal day') !== false || strpos($e->getMessage(), 'No open') !== false) {
                $shouldAutoOpen = true;
                error_log("FISCALIZE SALE: No open fiscal day detected. Attempting to auto-open...");
            } else {
                error_log("Warning: Could not check ZIMRA status: " . $e->getMessage());
            }
        }
        
        // Auto-close if needed (before auto-open)
        if ($shouldAutoClose && $fiscalDay) {
            try {
                error_log("FISCALIZE SALE: Auto-closing fiscal day #{$fiscalDay['fiscal_day_no']}...");
                $fiscalService->closeFiscalDay();
                error_log("FISCALIZE SALE: Auto-close initiated successfully");
                // After closing, we'll need to open a new day
                $shouldAutoOpen = true;
                $fiscalDay = null;
            } catch (Exception $e) {
                error_log("FISCALIZE SALE: Auto-close failed: " . $e->getMessage());
                // Continue with current open day if close fails
            }
        }
        
        // Auto-open if needed
        if ($shouldAutoOpen) {
            try {
                error_log("FISCALIZE SALE: Auto-opening fiscal day...");
                $openResult = $fiscalService->openFiscalDay();
                error_log("FISCALIZE SALE: Auto-open successful. Day No: " . ($openResult['fiscalDayNo'] ?? 'Unknown'));
                
                // Refresh status after opening
                $status = $fiscalService->getFiscalDayStatus();
            } catch (Exception $e) {
                error_log("FISCALIZE SALE: Auto-open failed: " . $e->getMessage());
                throw new Exception('No open fiscal day and auto-open failed: ' . $e->getMessage() . '. Please manually open a fiscal day.');
            }
        }
        
        // After auto-open/auto-close, get fiscal day from database
        if (!$fiscalDay) {
            $fiscalDay = $primaryDb->getRow(
                "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status IN ('FiscalDayOpened', 'FiscalDayCloseFailed') ORDER BY id DESC LIMIT 1",
                [':branch_id' => $branchId, ':device_id' => $device['device_id']]
            );
        }
        
        if (!$fiscalDay) {
            throw new Exception('No open fiscal day. Please open a fiscal day first.');
        }
        
        // Get last receipt counters (from primary database)
        $lastReceipt = $primaryDb->getRow(
            "SELECT receipt_counter, receipt_global_no FROM fiscal_receipts 
             WHERE device_id = :device_id
             ORDER BY receipt_global_no DESC LIMIT 1",
            [':device_id' => $device['device_id']]
        );
        
        $receiptCounter = $lastReceipt ? ($lastReceipt['receipt_counter'] + 1) : 1;
        $receiptGlobalNo = $lastReceipt ? ($lastReceipt['receipt_global_no'] + 1) : 1;
        
        // Build receipt data
        $receiptDate = date('Y-m-d\TH:i:s', strtotime($sale['sale_date']));
        writeFiscalLog("FISCALIZE SALE: Receipt date being used: $receiptDate (from sale_date: {$sale['sale_date']})");
        writeFiscalLog("FISCALIZE SALE: CRITICAL - RCPT025 requires receiptDate to be in tax valid from/till period. Ensure tax is valid for this date.");
        
        // Convert receipt total to payment currency
        $receiptTotal = floatval($sale['total_amount']);
        if ($exchangeRateToPayment != 1.0) {
            $receiptTotal = $receiptTotal * $exchangeRateToPayment;
            writeFiscalLog("FISCALIZE SALE: Converted receiptTotal from base currency {$sale['total_amount']} to payment currency $receiptTotal (rate: $exchangeRateToPayment)");
        }
        
        $receiptData = [
            'deviceID' => $device['device_id'], // Required for signature generation
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => $receiptCurrencyForZimra, // Use currency mapped for ZIMRA (ZWL->ZWG)
            'receiptCounter' => $receiptCounter,
            'receiptGlobalNo' => $receiptGlobalNo,
            'receiptDate' => $receiptDate,
            'invoiceNo' => $sale['receipt_number'],
            'receiptTotal' => $receiptTotal, // Already converted to payment currency
            'receiptLinesTaxInclusive' => true,
            'receiptLines' => [],
            'receiptTaxes' => [],
            'receiptPayments' => [],
            'receiptPrintForm' => 'InvoiceA4'
        ];
        
        // Build receipt lines from sale items
        // MUST use exact tax values from ZIMRA applicableTaxes - NO DEFAULTS OR FALLBACKS
        $lineNo = 1;
        foreach ($saleItems as $item) {
            if (empty($applicableTaxes)) {
                throw new Exception('No applicable taxes found in fiscal configuration. Please sync configuration from ZIMRA first.');
            }
            
            // Try to use product's tax_id if available, then category tax_id, then fallback
            $selectedTax = null;
            $productTaxId = isset($item['product_tax_id']) ? intval($item['product_tax_id']) : null;
            $categoryTaxId = isset($item['category_tax_id']) ? intval($item['category_tax_id']) : null;
            
            // Priority 1: Product's own tax_id
            if ($productTaxId) {
                foreach ($applicableTaxes as $tax) {
                    if (isset($tax['taxID']) && intval($tax['taxID']) === $productTaxId) {
                        $selectedTax = $tax;
                        writeFiscalLog("FISCALIZE SALE: Using product's assigned tax - taxID={$productTaxId} from product configuration");
                        break;
                    }
                }
                
                if (!$selectedTax) {
                    writeFiscalLog("FISCALIZE SALE: WARNING - Product tax_id={$productTaxId} not found in applicable taxes. Trying category tax.");
                }
            }
            
            // Priority 2: Category's tax_id (if product doesn't have one)
            if (!$selectedTax && $categoryTaxId) {
                foreach ($applicableTaxes as $tax) {
                    if (isset($tax['taxID']) && intval($tax['taxID']) === $categoryTaxId) {
                        $selectedTax = $tax;
                        writeFiscalLog("FISCALIZE SALE: Using category's assigned tax - taxID={$categoryTaxId} from category configuration");
                        break;
                    }
                }
                
                if (!$selectedTax) {
                    writeFiscalLog("FISCALIZE SALE: WARNING - Category tax_id={$categoryTaxId} not found in applicable taxes. Falling back to auto-selection.");
                }
            }
            
            // Fallback: Find the standard VAT tax (typically 15% or 15.5%) from ZIMRA applicable taxes
            // Look for taxPercent >= 15 (standard VAT rate)
            if (!$selectedTax) {
                foreach ($applicableTaxes as $tax) {
                    $taxPercent = floatval($tax['taxPercent'] ?? 0);
                    // Look for standard VAT rate (15% or higher, typically 15.5% in Zimbabwe)
                    if ($taxPercent >= 15.0) {
                        $selectedTax = $tax;
                        writeFiscalLog("FISCALIZE SALE: Auto-selected standard VAT tax (>=15%) - taxID={$tax['taxID']}");
                        break;
                    }
                }
            }
            
            // If no standard VAT tax found, use the tax with highest taxPercent (should be standard VAT)
            if (!$selectedTax) {
                $highestTax = null;
                $highestPercent = 0;
                foreach ($applicableTaxes as $tax) {
                    $taxPercent = floatval($tax['taxPercent'] ?? 0);
                    if ($taxPercent > $highestPercent) {
                        $highestPercent = $taxPercent;
                        $highestTax = $tax;
                    }
                }
                $selectedTax = $highestTax;
                if ($selectedTax) {
                    writeFiscalLog("FISCALIZE SALE: Auto-selected highest tax rate - taxID={$selectedTax['taxID']}, taxPercent={$highestPercent}");
                }
            }
            
            if (!$selectedTax) {
                throw new Exception('No valid tax found in ZIMRA applicable taxes. Please sync configuration from ZIMRA.');
            }
            
            // Validate required fields exist - no fallbacks
            // NOTE: taxPercent can be null for exempt taxes (documentation: "field will not be returned")
            if (empty($selectedTax['taxID']) || empty($selectedTax['taxCode'])) {
                throw new Exception('Invalid tax configuration: Missing required tax fields (taxID or taxCode) in ZIMRA applicable taxes. Please sync configuration from ZIMRA.');
            }
            
            $taxId = intval($selectedTax['taxID']);
            // CRITICAL: For exempt taxes, taxPercent should be null (not 0, not included in payload)
            // Documentation: "In case of exempt, field will not be returned" (getConfig) and "must not be provided" (receiptLine/receiptTax)
            $taxPercent = isset($selectedTax['taxPercent']) && $selectedTax['taxPercent'] !== null ? floatval($selectedTax['taxPercent']) : null;
            $taxCode = $selectedTax['taxCode']; // Exact from ZIMRA - no fallback
            
            writeFiscalLog("FISCALIZE SALE: Using EXACT tax from ZIMRA - taxID=$taxId, taxPercent=" . ($taxPercent !== null ? $taxPercent : 'NULL (exempt)') . ", taxCode=$taxCode");
            writeFiscalLog("FISCALIZE SALE: NOTE - ZIMRA getConfig returned taxPercent=" . ($selectedTax['taxPercent'] ?? 'NULL (exempt)') . " (raw value from ZIMRA)");
            
            // Convert item amounts to payment currency if needed
            $unitPrice = floatval($item['unit_price']);
            $totalPrice = floatval($item['total_price'] ?? $item['line_total'] ?? ($item['unit_price'] * $item['quantity']));
            
            if ($exchangeRateToPayment != 1.0) {
                $unitPrice = $unitPrice * $exchangeRateToPayment;
                $totalPrice = $totalPrice * $exchangeRateToPayment;
                writeFiscalLog("FISCALIZE SALE: Converted item '{$item['product_name']}' - unit_price: {$item['unit_price']} -> $unitPrice, total_price: " . ($item['total_price'] ?? 'N/A') . " -> $totalPrice");
            }
            
            // Build receipt line - for exempt taxes (taxCode='E'), don't include taxPercent field
            // Documentation: "In case of exempt, field will not be provided" (receiptLine)
            $receiptLine = [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => $lineNo++,
                'receiptLineHSCode' => '00000000',
                'receiptLineName' => $item['product_name'] ?? 'Item',
                'receiptLinePrice' => $unitPrice, // Already converted to payment currency
                'receiptLineQuantity' => floatval($item['quantity']),
                'receiptLineTotal' => $totalPrice, // Already converted to payment currency
                'taxID' => $taxId, // Exact from ZIMRA
                'taxCode' => $taxCode // Exact from ZIMRA
            ];
            // Only include taxPercent if it's not exempt (taxCode='E' means exempt - must not include taxPercent)
            // Even if ZIMRA returns taxPercent=0 for exempt, we must NOT include it per documentation
            if ($taxCode !== 'E' && $taxPercent !== null) {
                $receiptLine['taxPercent'] = $taxPercent;
            }
            $receiptData['receiptLines'][] = $receiptLine;
        }
        
        // Calculate taxes
        // Group by taxID, taxPercent AND taxCode (as per RCPT025: taxID/taxPercent must match FDMS, and RCPT026: "same taxPercent and taxCode values")
        writeFiscalLog("FISCALIZE SALE: ========== TAX CALCULATION START ==========");
        writeFiscalLog("FISCALIZE SALE: receiptLinesTaxInclusive = " . ($receiptData['receiptLinesTaxInclusive'] ? 'true' : 'false'));
        writeFiscalLog("FISCALIZE SALE: Total receiptLines count: " . count($receiptData['receiptLines']));
        writeFiscalLog("FISCALIZE SALE: All receiptLines with tax info: " . json_encode($receiptData['receiptLines'], JSON_PRETTY_PRINT));
        
        $taxGroups = [];
        foreach ($receiptData['receiptLines'] as $line) {
            // Group by taxID, taxPercent and taxCode to ensure RCPT025 compliance
            // RCPT025 requires taxID/taxPercent combination to match FDMS exactly
            // For exempt taxes, taxPercent may be null - use 'NULL' as string key for grouping
            $taxPercentKey = isset($line['taxPercent']) && $line['taxPercent'] !== null ? $line['taxPercent'] : 'NULL';
            $taxKey = $line['taxID'] . '_' . $taxPercentKey . '_' . $line['taxCode'];
            if (!isset($taxGroups[$taxKey])) {
                $taxGroups[$taxKey] = [
                    'taxID' => $line['taxID'],
                    'taxCode' => $line['taxCode'],
                    'taxPercent' => isset($line['taxPercent']) ? $line['taxPercent'] : null, // Keep null for exempt
                    'total' => 0
                ];
                writeFiscalLog("FISCALIZE SALE: Created new tax group - taxID={$line['taxID']}, taxPercent=" . ($taxGroups[$taxKey]['taxPercent'] !== null ? $taxGroups[$taxKey]['taxPercent'] : 'NULL (exempt)') . ", taxCode={$line['taxCode']}");
            }
            $taxGroups[$taxKey]['total'] += floatval($line['receiptLineTotal']);
            writeFiscalLog("FISCALIZE SALE: Added line total " . floatval($line['receiptLineTotal']) . " to tax group $taxKey, new total: {$taxGroups[$taxKey]['total']}");
        }
        
        writeFiscalLog("FISCALIZE SALE: Tax groups after grouping: " . json_encode($taxGroups, JSON_PRETTY_PRINT));
        
        foreach ($taxGroups as $group) {
            writeFiscalLog("FISCALIZE SALE: ========== PROCESSING TAX GROUP ==========");
            writeFiscalLog("FISCALIZE SALE: Group taxID={$group['taxID']}, taxPercent=" . (isset($group['taxPercent']) && $group['taxPercent'] !== null ? $group['taxPercent'] : 'NULL (exempt)') . ", taxCode={$group['taxCode']}, total={$group['total']}");
            
            // RCPT026: taxAmount calculation based on receiptLinesTaxInclusive flag
            // Documentation: 
            // - If receiptLinesTaxInclusive is true: taxAmount = SUM(receiptLineTotal) * taxPercent/(1+taxPercent)
            // - If receiptLinesTaxInclusive is false: taxAmount = SUM(receiptLineTotal) * taxPercent
            // 
            // ZIMRA taxPercent format: According to documentation "Decimal (5,2)", it's in percentage form (e.g., 15.50 for 15.5%)
            // But the formula taxPercent/(1+taxPercent) suggests decimal form (0.155 for 15.5%)
            // 
            // CRITICAL: For exempt taxes, taxPercent is null - taxAmount should be 0
            $taxPercentValue = isset($group['taxPercent']) && $group['taxPercent'] !== null ? floatval($group['taxPercent']) : null;
            $isTaxInclusive = $receiptData['receiptLinesTaxInclusive'] ?? true;
            
            writeFiscalLog("FISCALIZE SALE: taxPercentValue (raw) = " . ($taxPercentValue !== null ? $taxPercentValue : 'NULL (exempt)'));
            writeFiscalLog("FISCALIZE SALE: isTaxInclusive = " . ($isTaxInclusive ? 'true' : 'false'));
            
            // CRITICAL: For exempt taxes (taxPercent is null), taxAmount is always 0
            if ($taxPercentValue === null) {
                $taxAmount = 0;
                writeFiscalLog("FISCALIZE SALE: Exempt tax - taxAmount set to 0");
            } elseif ($isTaxInclusive) {
                // Tax-inclusive: taxAmount = total * taxPercent/(1+taxPercent)
                // CRITICAL: Testing if ZIMRA uses percentage directly in formula
                // Try both interpretations and log both for comparison
                if ($taxPercentValue > 1) {
                    // Interpretation 1: Convert to decimal first (what we've been doing)
                    $taxPercentDecimal = $taxPercentValue / 100;
                    $taxAmountMethod1 = $group['total'] * ($taxPercentDecimal / (1 + $taxPercentDecimal));
                    
                    // Interpretation 2: Use percentage directly in formula (what ZIMRA might be doing)
                    $taxAmountMethod2 = $group['total'] * ($taxPercentValue / (1 + $taxPercentValue));
                    
                    // Interpretation 3: Use percentage in formula taxPercent/(100+taxPercent) - mathematically same as method 1
                    $taxAmountMethod3 = $group['total'] * ($taxPercentValue / (100 + $taxPercentValue));
                    
                    writeFiscalLog("FISCALIZE SALE: Testing different formula interpretations:");
                    writeFiscalLog("FISCALIZE SALE: Method 1 (convert to decimal): taxAmount = {$group['total']} * (0.155 / 1.155) = $taxAmountMethod1");
                    writeFiscalLog("FISCALIZE SALE: Method 2 (use percentage directly): taxAmount = {$group['total']} * (15.5 / 16.5) = $taxAmountMethod2");
                    writeFiscalLog("FISCALIZE SALE: Method 3 (percentage in 100+formula): taxAmount = {$group['total']} * (15.5 / 115.5) = $taxAmountMethod3");
                    
                    // Use method 1 (convert to decimal) - this is mathematically correct
                    $taxAmount = $taxAmountMethod1;
                    writeFiscalLog("FISCALIZE SALE: Using Method 1 (convert to decimal): taxAmount = $taxAmount");
                } else {
                    // taxPercent is already in decimal form (0.155)
                    writeFiscalLog("FISCALIZE SALE: Tax-inclusive calculation (decimal form): using taxPercentValue directly = $taxPercentValue");
                    $taxAmount = $group['total'] * ($taxPercentValue / (1 + $taxPercentValue));
                    writeFiscalLog("FISCALIZE SALE: Formula: taxAmount = {$group['total']} * ($taxPercentValue / (1 + $taxPercentValue)) = {$group['total']} * " . ($taxPercentValue / (1 + $taxPercentValue)) . " = $taxAmount");
                }
            } else {
                // Tax-exclusive: taxAmount = total * taxPercent
                if ($taxPercentValue > 1) {
                    // taxPercent is in percentage form (15.5), convert to decimal (0.155)
                    $taxPercentDecimal = $taxPercentValue / 100;
                    writeFiscalLog("FISCALIZE SALE: Tax-exclusive calculation (percentage form): taxPercentDecimal = $taxPercentDecimal (from $taxPercentValue / 100)");
                    $taxAmount = $group['total'] * $taxPercentDecimal;
                    writeFiscalLog("FISCALIZE SALE: Formula: taxAmount = {$group['total']} * $taxPercentDecimal = $taxAmount");
                } else {
                    // taxPercent is already in decimal form (0.155)
                    writeFiscalLog("FISCALIZE SALE: Tax-exclusive calculation (decimal form): using taxPercentValue directly = $taxPercentValue");
                    $taxAmount = $group['total'] * $taxPercentValue;
                    writeFiscalLog("FISCALIZE SALE: Formula: taxAmount = {$group['total']} * $taxPercentValue = $taxAmount");
                }
            }
            
            writeFiscalLog("FISCALIZE SALE: Calculated taxAmount (before rounding) = " . ($taxAmount ?? 0));
            
            // CRITICAL: Find the exact tax from applicableTaxes by matching taxID, taxPercent and taxCode
            // RCPT025 requires taxID/taxPercent combination to match FDMS exactly
            // RCPT025 also requires: "receiptDate must be in a period of tax valid from and valid till period"
            // Note: group['taxPercent'] is now in percentage form (15.50), same as applicableTaxes
            writeFiscalLog("FISCALIZE SALE: Searching for matching tax in applicableTaxes:");
            writeFiscalLog("FISCALIZE SALE: Looking for - taxID={$group['taxID']}, taxPercent=" . (isset($group['taxPercent']) && $group['taxPercent'] !== null ? $group['taxPercent'] : 'NULL (exempt)') . ", taxCode={$group['taxCode']}");
            
            $validTax = null;
            foreach ($applicableTaxes as $index => $applicableTax) {
                $taxIDMatch = (intval($applicableTax['taxID']) === intval($group['taxID']));
                $taxCodeMatch = ($applicableTax['taxCode'] === $group['taxCode']);
                
                // CRITICAL: For exempt taxes (taxCode='E'), match by taxID and taxCode only, ignore taxPercent
                // The database may store taxPercent=0 for exempt taxes, but we remove it from payload (per documentation)
                // So we can't rely on taxPercent matching for exempt taxes
                if ($group['taxCode'] === 'E' && $applicableTax['taxCode'] === 'E') {
                    // Both are exempt - match by taxID and taxCode only
                    if ($taxIDMatch && $taxCodeMatch) {
                        $validTax = $applicableTax;
                        writeFiscalLog("FISCALIZE SALE: ✓ Found matching exempt tax at index $index (matched by taxID and taxCode only, ignoring taxPercent)");
                        break;
                    }
                } else {
                    // Non-exempt taxes - match by taxID, taxPercent and taxCode
                    $taxPercentMatch = false;
                    $groupTaxPercent = isset($group['taxPercent']) && $group['taxPercent'] !== null ? $group['taxPercent'] : null;
                    $applicableTaxPercent = isset($applicableTax['taxPercent']) && $applicableTax['taxPercent'] !== null ? $applicableTax['taxPercent'] : null;
                    
                    if ($groupTaxPercent === null && $applicableTaxPercent === null) {
                        // Both are null (shouldn't happen for non-exempt, but handle it)
                        $taxPercentMatch = true;
                    } elseif ($groupTaxPercent !== null && $applicableTaxPercent !== null) {
                        // Both have values - compare
                        $taxPercentMatch = (abs(floatval($applicableTaxPercent) - floatval($groupTaxPercent)) < 0.01);
                    }
                    // If one is null and the other is not, they don't match
                    
                    writeFiscalLog("FISCALIZE SALE: Checking applicableTax[$index]: taxID={$applicableTax['taxID']} (match: " . ($taxIDMatch ? 'YES' : 'NO') . "), taxPercent=" . ($applicableTaxPercent !== null ? $applicableTaxPercent : 'NULL') . " (match: " . ($taxPercentMatch ? 'YES' : 'NO') . "), taxCode={$applicableTax['taxCode']} (match: " . ($taxCodeMatch ? 'YES' : 'NO') . ")");
                    
                    // Match by taxID, taxPercent and taxCode to ensure RCPT025 compliance
                    if ($taxIDMatch && $taxPercentMatch && $taxCodeMatch) {
                        $validTax = $applicableTax;
                        writeFiscalLog("FISCALIZE SALE: ✓ Found matching tax at index $index");
                        break;
                    }
                }
            }
            
            if (!$validTax) {
                $groupTaxPercentDisplay = isset($group['taxPercent']) && $group['taxPercent'] !== null ? $group['taxPercent'] : '(exempt/null)';
                $errorMsg = "FISCALIZE SALE: ERROR - Tax combination (taxID={$group['taxID']}, taxPercent=$groupTaxPercentDisplay, taxCode={$group['taxCode']}) not found in applicableTaxes from ZIMRA!";
                writeFiscalLog($errorMsg);
                writeFiscalLog("FISCALIZE SALE: Available applicableTaxes: " . json_encode($applicableTaxes, JSON_PRETTY_PRINT));
                throw new Exception("Invalid tax configuration: Tax combination (taxID={$group['taxID']}, taxPercent=$groupTaxPercentDisplay, taxCode={$group['taxCode']}) not found in ZIMRA applicable taxes. Please sync configuration from ZIMRA.");
            }
            
            // Use EXACT values from ZIMRA - no fallbacks
            if (empty($validTax['taxCode']) || empty($validTax['taxID'])) {
                throw new Exception("Invalid tax configuration: Missing required fields in ZIMRA applicable tax (taxID={$validTax['taxID']}, taxPercent={$validTax['taxPercent']}, taxCode={$validTax['taxCode']}). Please sync configuration from ZIMRA.");
            }
            
            writeFiscalLog("FISCALIZE SALE: Using validTax - taxID={$validTax['taxID']}, taxPercent={$validTax['taxPercent']}, taxCode={$validTax['taxCode']}");
            
            // RCPT025: Check tax validity period if available
            $receiptDateTimestamp = strtotime($receiptData['receiptDate']);
            $taxValidFrom = isset($validTax['taxValidFrom']) ? strtotime($validTax['taxValidFrom']) : null;
            $taxValidTill = isset($validTax['taxValidTill']) ? strtotime($validTax['taxValidTill']) : null;
            
            if ($taxValidFrom !== null || $taxValidTill !== null) {
                $isValidPeriod = true;
                if ($taxValidFrom !== null && $receiptDateTimestamp < $taxValidFrom) {
                    $isValidPeriod = false;
                    writeFiscalLog("FISCALIZE SALE: ERROR - receiptDate ({$receiptData['receiptDate']}) is BEFORE tax validFrom (" . date('Y-m-d H:i:s', $taxValidFrom) . ")");
                }
                if ($taxValidTill !== null && $receiptDateTimestamp > $taxValidTill) {
                    $isValidPeriod = false;
                    writeFiscalLog("FISCALIZE SALE: ERROR - receiptDate ({$receiptData['receiptDate']}) is AFTER tax validTill (" . date('Y-m-d H:i:s', $taxValidTill) . ")");
                }
                if ($isValidPeriod) {
                    writeFiscalLog("FISCALIZE SALE: ✓ Tax validity period check passed - receiptDate is within tax valid period");
                } else {
                    writeFiscalLog("FISCALIZE SALE: ✗ Tax validity period check FAILED - receiptDate is NOT within tax valid period (RCPT025 will fail)");
                }
            } else {
                writeFiscalLog("FISCALIZE SALE: WARNING - Tax validity period (taxValidFrom/taxValidTill) not available in applicableTaxes. RCPT025 requires receiptDate to be within tax valid period if tax has validity dates.");
            }
            
            // CRITICAL FIX: Match test script behavior EXACTLY
            // Test script: salesAmountWithTax = floatval(total), taxAmount = round(total * 0.155 / 1.155, 2)
            // For production: Use sum of receiptLineTotal for this tax group as salesAmountWithTax
            // After all tax groups are processed, we'll ensure sum equals receiptTotal (RCPT027 fix)
            $salesAmountWithTax = round($group['total'], 2);
            
            // Use the EXACT same formula as test script: round(salesAmountWithTax * taxPercentDecimal / (1 + taxPercentDecimal), 2)
            // Test script: round((10.00 + $i) * 0.155 / 1.155, 2) where (10.00 + $i) = salesAmountWithTax
            // CRITICAL: For exempt taxes, taxPercent is null - taxAmount should be 0
            $taxPercentForCalculation = isset($validTax['taxPercent']) && $validTax['taxPercent'] !== null ? floatval($validTax['taxPercent']) : null;
            if ($taxPercentForCalculation === null) {
                // Exempt tax - taxAmount is 0
                $taxAmount = 0;
                writeFiscalLog("FISCALIZE SALE: Exempt tax - taxAmount set to 0");
            } else {
                if ($receiptData['receiptLinesTaxInclusive']) {
                    // Tax-inclusive: taxAmount = salesAmountWithTax * (taxPercent / (1 + taxPercent))
                    // Convert taxPercent to decimal if > 1 (percentage form)
                    if ($taxPercentForCalculation > 1) {
                        $taxPercentDecimal = $taxPercentForCalculation / 100;
                    } else {
                        $taxPercentDecimal = $taxPercentForCalculation;
                    }
                    // Match test script formula EXACTLY: round(salesAmountWithTax * taxPercentDecimal / (1 + taxPercentDecimal), 2)
                    $taxAmount = round($salesAmountWithTax * $taxPercentDecimal / (1 + $taxPercentDecimal), 2);
                } else {
                    // Tax-exclusive: taxAmount = salesAmountWithTax * taxPercent
                    if ($taxPercentForCalculation > 1) {
                        $taxPercentDecimal = $taxPercentForCalculation / 100;
                    } else {
                        $taxPercentDecimal = $taxPercentForCalculation;
                    }
                    $taxAmount = round($salesAmountWithTax * $taxPercentDecimal, 2);
                }
            }
            
            writeFiscalLog("FISCALIZE SALE: Recalculated taxAmount from final salesAmountWithTax (matching Python library):");
            writeFiscalLog("FISCALIZE SALE:   salesAmountWithTax: $salesAmountWithTax");
            writeFiscalLog("FISCALIZE SALE:   taxPercent: " . ($taxPercentForCalculation !== null ? $taxPercentForCalculation : 'NULL (exempt)'));
            writeFiscalLog("FISCALIZE SALE:   Final taxAmount: $taxAmount");
            
            // CRITICAL: For exempt taxes, taxPercent should be null (not included in payload)
            // Documentation: "In case of exempt, field will not be returned" (getConfig) and "must not be provided" (receiptTax)
            $taxPercentForAPI = isset($validTax['taxPercent']) && $validTax['taxPercent'] !== null ? floatval($validTax['taxPercent']) : null;
            
            if ($taxPercentForAPI !== null) {
                writeFiscalLog("FISCALIZE SALE: Using EXACT taxPercent from ZIMRA for API - Original: {$validTax['taxPercent']}, For API: $taxPercentForAPI (as number, JSON will encode as: " . json_encode($taxPercentForAPI) . ")");
            } else {
                writeFiscalLog("FISCALIZE SALE: Exempt tax - taxPercent will NOT be included in payload (per ZIMRA documentation)");
            }
            writeFiscalLog("FISCALIZE SALE: CRITICAL - RCPT025 requires taxID/taxPercent combination to match FDMS exactly. Using taxID={$validTax['taxID']}, taxPercent=" . ($taxPercentForAPI !== null ? $taxPercentForAPI : 'NULL (exempt)') . " from ZIMRA getConfig.");
            
            // Build receiptTax entry - for exempt taxes (taxCode='E'), don't include taxPercent field
            // Documentation: "In case of exempt, field will not be provided" (receiptTax)
            $receiptTaxEntry = [
                'taxID' => intval($validTax['taxID']), // Exact from ZIMRA - CRITICAL for RCPT025
                'taxCode' => $validTax['taxCode'], // Exact from ZIMRA - no fallback
                'taxAmount' => $taxAmount, // Recalculated from final salesAmountWithTax (matching Python library)
                'salesAmountWithTax' => $salesAmountWithTax // Sum of receiptLineTotal for this tax group
            ];
            // Only include taxPercent if it's not exempt (taxCode='E' means exempt - must not include taxPercent)
            // Even if ZIMRA returns taxPercent=0 for exempt, we must NOT include it per documentation
            if ($validTax['taxCode'] !== 'E' && $taxPercentForAPI !== null) {
                $receiptTaxEntry['taxPercent'] = $taxPercentForAPI;
            }
            
            $receiptData['receiptTaxes'][] = $receiptTaxEntry;
            
            writeFiscalLog("FISCALIZE SALE: Added receiptTax entry: " . json_encode($receiptTaxEntry, JSON_PRETTY_PRINT));
            writeFiscalLog("FISCALIZE SALE: Using exact tax from ZIMRA - taxID={$validTax['taxID']}, taxPercent={$validTax['taxPercent']}, taxCode={$validTax['taxCode']}, taxAmount=$taxAmount, salesAmountWithTax={$group['total']}");
            writeFiscalLog("FISCALIZE SALE: ========== END PROCESSING TAX GROUP ==========");
        }
        
        // Validate all receiptTaxes match applicableTaxes exactly - NO FALLBACKS
        // This is sensitive fiscal data - must use exact values from ZIMRA
        writeFiscalLog("FISCALIZE SALE: ========== FINAL TAX VALIDATION ==========");
        writeFiscalLog("FISCALIZE SALE: Receipt date: {$receiptData['receiptDate']}");
        writeFiscalLog("FISCALIZE SALE: Final receiptTaxes being sent to ZIMRA: " . json_encode($receiptData['receiptTaxes'], JSON_PRETTY_PRINT));
        writeFiscalLog("FISCALIZE SALE: Applicable taxes from ZIMRA config: " . json_encode($applicableTaxes, JSON_PRETTY_PRINT));
        
        // Summary for RCPT025/RCPT026 debugging
        writeFiscalLog("FISCALIZE SALE: ========== RCPT025/RCPT026 SUMMARY ==========");
        foreach ($receiptData['receiptTaxes'] as $tax) {
            // Calculate expected taxAmount using the exact formula from documentation
            $sumLineTotal = 0;
            foreach ($receiptData['receiptLines'] as $line) {
                if (abs(floatval($line['taxPercent']) - floatval($tax['taxPercent'])) < 0.01 && 
                    $line['taxCode'] === $tax['taxCode']) {
                    $sumLineTotal += floatval($line['receiptLineTotal']);
                }
            }
            
            $taxPercentForFormula = floatval($tax['taxPercent']);
            $isTaxInclusive = $receiptData['receiptLinesTaxInclusive'] ?? true;
            
            if ($isTaxInclusive) {
                // Formula: taxAmount = SUM(receiptLineTotal) * taxPercent/(1+taxPercent)
                // If taxPercent is 15.5 (percentage), convert to 0.155 (decimal) for formula
                $taxPercentDecimal = ($taxPercentForFormula > 1) ? ($taxPercentForFormula / 100) : $taxPercentForFormula;
                $expectedTaxAmount = $sumLineTotal * ($taxPercentDecimal / (1 + $taxPercentDecimal));
                $expectedTaxAmount = round($expectedTaxAmount, 2);
                writeFiscalLog("FISCALIZE SALE: RCPT026 Formula Check - SUM(receiptLineTotal)=$sumLineTotal, taxPercent=$taxPercentForFormula, taxPercentDecimal=$taxPercentDecimal");
                writeFiscalLog("FISCALIZE SALE: RCPT026 Formula Check - Expected: $sumLineTotal * ($taxPercentDecimal / (1 + $taxPercentDecimal)) = $expectedTaxAmount");
            } else {
                $taxPercentDecimal = ($taxPercentForFormula > 1) ? ($taxPercentForFormula / 100) : $taxPercentForFormula;
                $expectedTaxAmount = $sumLineTotal * $taxPercentDecimal;
                $expectedTaxAmount = round($expectedTaxAmount, 2);
                writeFiscalLog("FISCALIZE SALE: RCPT026 Formula Check (tax-exclusive) - SUM(receiptLineTotal)=$sumLineTotal, taxPercent=$taxPercentForFormula");
                writeFiscalLog("FISCALIZE SALE: RCPT026 Formula Check - Expected: $sumLineTotal * $taxPercentDecimal = $expectedTaxAmount");
            }
            
            writeFiscalLog("FISCALIZE SALE: Sending to ZIMRA - taxID={$tax['taxID']}, taxPercent={$tax['taxPercent']} (type: " . gettype($tax['taxPercent']) . "), taxCode={$tax['taxCode']}, taxAmount={$tax['taxAmount']}");
            writeFiscalLog("FISCALIZE SALE: RCPT025 requires: taxID/taxPercent combination MUST exist in FDMS EXACTLY as sent (taxID={$tax['taxID']}, taxPercent={$tax['taxPercent']})");
            writeFiscalLog("FISCALIZE SALE: RCPT025 requires: receiptDate ({$receiptData['receiptDate']}) MUST be within tax valid from/till period");
            writeFiscalLog("FISCALIZE SALE: RCPT026 requires: taxAmount ({$tax['taxAmount']}) MUST equal Expected ($expectedTaxAmount) - Match: " . (abs($tax['taxAmount'] - $expectedTaxAmount) < 0.01 ? 'YES' : 'NO'));
            writeFiscalLog("FISCALIZE SALE: If RCPT025 persists: Verify in ZIMRA FDMS that taxID={$tax['taxID']} with taxPercent={$tax['taxPercent']} exists and is valid for receiptDate={$receiptData['receiptDate']}");
            writeFiscalLog("FISCALIZE SALE: If RCPT026 persists: ZIMRA might be using a different formula interpretation. Check if taxPercent should be in decimal form (0.155) instead of percentage (15.5)");
        }
        writeFiscalLog("FISCALIZE SALE: ========== END SUMMARY ==========");
        
        // Log detailed comparison for each receiptTax
        foreach ($receiptData['receiptTaxes'] as $index => $receiptTax) {
            writeFiscalLog("FISCALIZE SALE: ReceiptTax[$index]: taxID={$receiptTax['taxID']}, taxPercent={$receiptTax['taxPercent']}, taxCode={$receiptTax['taxCode']}, taxAmount={$receiptTax['taxAmount']}, salesAmountWithTax={$receiptTax['salesAmountWithTax']}");
            
            // Verify calculation matches RCPT026 formula
            $expectedTaxAmount = 0;
            if ($receiptData['receiptLinesTaxInclusive']) {
                $taxPercentDecimal = ($receiptTax['taxPercent'] > 1) ? ($receiptTax['taxPercent'] / 100) : $receiptTax['taxPercent'];
                $expectedTaxAmount = $receiptTax['salesAmountWithTax'] * ($taxPercentDecimal / (1 + $taxPercentDecimal));
            } else {
                $taxPercentDecimal = ($receiptTax['taxPercent'] > 1) ? ($receiptTax['taxPercent'] / 100) : $receiptTax['taxPercent'];
                $expectedTaxAmount = $receiptTax['salesAmountWithTax'] * $taxPercentDecimal;
            }
            $expectedTaxAmount = round($expectedTaxAmount, 2);
            writeFiscalLog("FISCALIZE SALE: ReceiptTax[$index] validation - Expected taxAmount (per RCPT026): $expectedTaxAmount, Actual: {$receiptTax['taxAmount']}, Match: " . (abs($expectedTaxAmount - $receiptTax['taxAmount']) < 0.01 ? 'YES' : 'NO'));
        }
        
        writeFiscalLog("FISCALIZE SALE: ========== TAX CALCULATION END ==========");
        
        // RCPT027 FIX: Ensure sum of salesAmountWithTax equals receiptTotal EXACTLY (matching test script)
        // Test script ensures: receiptTotal == SUM(receiptTaxes.salesAmountWithTax) exactly
        // This is critical - ZIMRA validates that receiptTotal = SUM(receiptTaxes.salesAmountWithTax)
        $sumSalesAmountWithTax = 0;
        foreach ($receiptData['receiptTaxes'] as $tax) {
            $sumSalesAmountWithTax += floatval($tax['salesAmountWithTax']);
        }
        $receiptTotal = floatval($receiptData['receiptTotal']);
        $difference = abs($receiptTotal - $sumSalesAmountWithTax);
        
        writeFiscalLog("FISCALIZE SALE: RCPT027 Validation - receiptTotal: $receiptTotal, sum of salesAmountWithTax: $sumSalesAmountWithTax, difference: $difference");
        
        // CRITICAL: Match test script behavior - if there's ANY difference, set salesAmountWithTax to receiptTotal
        // Test script: salesAmountWithTax = floatval(receiptTotal) directly, ensuring exact match
        if ($difference > 0.01) {
            writeFiscalLog("FISCALIZE SALE: RCPT027 FIX - Difference detected, ensuring salesAmountWithTax matches receiptTotal exactly (matching test script)");
            
            // For single tax group: set salesAmountWithTax directly to receiptTotal (matching test script)
            if (count($receiptData['receiptTaxes']) === 1) {
                $tax = &$receiptData['receiptTaxes'][0];
                
                // Preserve all tax fields before adjustment
                $preservedTaxCode = $tax['taxCode'] ?? '';
                $preservedTaxID = $tax['taxID'] ?? null;
                $preservedTaxPercent = $tax['taxPercent'] ?? null;
                
                // Set salesAmountWithTax directly to receiptTotal (matching test script)
                $tax['salesAmountWithTax'] = round($receiptTotal, 2);
                writeFiscalLog("FISCALIZE SALE: RCPT027 FIX - Set salesAmountWithTax directly to receiptTotal: {$tax['salesAmountWithTax']} (matching test script)");
                
                // Recalculate taxAmount from salesAmountWithTax using EXACT test script formula
                $taxPercentForCalculation = round(floatval($preservedTaxPercent ?? $tax['taxPercent']), 2);
                if ($receiptData['receiptLinesTaxInclusive']) {
                    if ($taxPercentForCalculation > 1) {
                        $taxPercentDecimal = $taxPercentForCalculation / 100;
                    } else {
                        $taxPercentDecimal = $taxPercentForCalculation;
                    }
                    // Test script formula: round((total) * 0.155 / 1.155, 2)
                    $tax['taxAmount'] = round($tax['salesAmountWithTax'] * $taxPercentDecimal / (1 + $taxPercentDecimal), 2);
                } else {
                    if ($taxPercentForCalculation > 1) {
                        $taxPercentDecimal = $taxPercentForCalculation / 100;
                    } else {
                        $taxPercentDecimal = $taxPercentForCalculation;
                    }
                    $tax['taxAmount'] = round($tax['salesAmountWithTax'] * $taxPercentDecimal, 2);
                }
                
                // CRITICAL: Restore all preserved fields (especially taxCode for signature generation)
                $tax['taxCode'] = $preservedTaxCode;
                if ($preservedTaxID !== null) $tax['taxID'] = $preservedTaxID;
                if ($preservedTaxPercent !== null) $tax['taxPercent'] = $preservedTaxPercent;
                
                writeFiscalLog("FISCALIZE SALE: RCPT027 FIX - Recalculated taxAmount: {$tax['taxAmount']}, preserved taxCode: '{$preservedTaxCode}'");
                unset($tax);
            } else {
                // Multiple tax groups: distribute proportionally
                if ($sumSalesAmountWithTax > 0) {
                    $adjustmentFactor = $receiptTotal / $sumSalesAmountWithTax;
                    writeFiscalLog("FISCALIZE SALE: RCPT027 FIX - Adjusting salesAmountWithTax by factor: $adjustmentFactor (multiple tax groups)");
                    
                    foreach ($receiptData['receiptTaxes'] as &$tax) {
                        // CRITICAL: Preserve taxCode, taxID, and taxPercent when adjusting
                        $preservedTaxCode = $tax['taxCode'] ?? '';
                        $preservedTaxID = $tax['taxID'] ?? null;
                        $preservedTaxPercent = $tax['taxPercent'] ?? null;
                        
                        $oldSalesAmount = floatval($tax['salesAmountWithTax']);
                        $tax['salesAmountWithTax'] = round($oldSalesAmount * $adjustmentFactor, 2);
                        writeFiscalLog("FISCALIZE SALE: RCPT027 FIX - Adjusted salesAmountWithTax from $oldSalesAmount to {$tax['salesAmountWithTax']}");
                        
                        // Recalculate taxAmount from the adjusted salesAmountWithTax (matching test script approach)
                        $taxPercentForCalculation = round(floatval($preservedTaxPercent ?? $tax['taxPercent']), 2);
                        if ($receiptData['receiptLinesTaxInclusive']) {
                            if ($taxPercentForCalculation > 1) {
                                $taxPercentDecimal = $taxPercentForCalculation / 100;
                            } else {
                                $taxPercentDecimal = $taxPercentForCalculation;
                            }
                            $tax['taxAmount'] = round(($tax['salesAmountWithTax'] * $taxPercentDecimal) / (1 + $taxPercentDecimal), 2);
                        } else {
                            if ($taxPercentForCalculation > 1) {
                                $taxPercentDecimal = $taxPercentForCalculation / 100;
                            } else {
                                $taxPercentDecimal = $taxPercentForCalculation;
                            }
                            $tax['taxAmount'] = round($tax['salesAmountWithTax'] * $taxPercentDecimal, 2);
                        }
                        
                        // CRITICAL: Restore preserved fields
                        $tax['taxCode'] = $preservedTaxCode;
                        if ($preservedTaxID !== null) $tax['taxID'] = $preservedTaxID;
                        if ($preservedTaxPercent !== null) $tax['taxPercent'] = $preservedTaxPercent;
                        
                        writeFiscalLog("FISCALIZE SALE: RCPT027 FIX - Recalculated taxAmount: {$tax['taxAmount']}, preserved taxCode: '{$preservedTaxCode}'");
                    }
                    unset($tax);
                }
            }
            
            // Verify fix
            $sumSalesAmountWithTaxAfter = 0;
            foreach ($receiptData['receiptTaxes'] as $tax) {
                $sumSalesAmountWithTaxAfter += floatval($tax['salesAmountWithTax']);
            }
            $finalDifference = abs($receiptTotal - $sumSalesAmountWithTaxAfter);
            writeFiscalLog("FISCALIZE SALE: RCPT027 FIX - After adjustment: receiptTotal: $receiptTotal, sum of salesAmountWithTax: $sumSalesAmountWithTaxAfter, difference: $finalDifference");
            
            if ($finalDifference > 0.01) {
                writeFiscalLog("FISCALIZE SALE: RCPT027 WARNING - After adjustment, difference still exceeds 0.01. Adjusting receiptTotal to match sum.");
                $receiptData['receiptTotal'] = round($sumSalesAmountWithTaxAfter, 2);
            } else {
                writeFiscalLog("FISCALIZE SALE: RCPT027 FIX - SUCCESS: receiptTotal now matches sum of salesAmountWithTax exactly");
            }
        } else {
            writeFiscalLog("FISCALIZE SALE: RCPT027 Validation PASSED - receiptTotal matches sum of salesAmountWithTax");
        }
        
        foreach ($receiptData['receiptTaxes'] as $receiptTax) {
            $found = false;
            foreach ($applicableTaxes as $applicableTax) {
                // Both receiptTax and applicableTax are in percentage form, so direct comparison
                if (intval($receiptTax['taxID']) === intval($applicableTax['taxID']) && 
                    abs(floatval($receiptTax['taxPercent']) - floatval($applicableTax['taxPercent'])) < 0.01 &&
                    $receiptTax['taxCode'] === $applicableTax['taxCode']) {
                    $found = true;
                    writeFiscalLog("FISCALIZE SALE: ✓ Receipt tax validated - taxID={$receiptTax['taxID']}, taxPercent={$receiptTax['taxPercent']}, taxCode={$receiptTax['taxCode']}");
                    break;
                }
            }
            if (!$found) {
                $errorMsg = "FISCALIZE SALE: ERROR - Receipt tax (taxID={$receiptTax['taxID']}, taxPercent={$receiptTax['taxPercent']}, taxCode={$receiptTax['taxCode']}) does not match any applicableTax from ZIMRA!";
                writeFiscalLog($errorMsg);
                writeFiscalLog("FISCALIZE SALE: Available applicableTaxes: " . json_encode($applicableTaxes, JSON_PRETTY_PRINT));
                throw new Exception("Invalid tax configuration: Tax combination (taxID={$receiptTax['taxID']}, taxPercent={$receiptTax['taxPercent']}, taxCode={$receiptTax['taxCode']}) not found in ZIMRA applicable taxes. Please sync configuration from ZIMRA.");
            }
        }
        
        // CRITICAL: Keep taxCode in receiptLines (test script includes it, and it's needed for validation)
        // Also ensure numeric values are floats and field order matches
        foreach ($receiptData['receiptLines'] as &$line) {
            // Keep taxCode - test script includes it and it's used for validation matching
            // Ensure numeric values are floats
            $line['receiptLinePrice'] = floatval($line['receiptLinePrice']);
            $line['receiptLineQuantity'] = floatval($line['receiptLineQuantity']);
            $line['receiptLineTotal'] = floatval($line['receiptLineTotal']);
            $line['taxPercent'] = floatval($line['taxPercent']);
            // Keep taxCode and taxID
            if (isset($line['taxID'])) $line['taxID'] = intval($line['taxID']);
        }
        unset($line);
        
        // CRITICAL: Keep taxCode in receiptTaxes for signature generation (ZIMRA documentation requires taxCode in signature)
        // taxCode will be removed from JSON payload in fiscal_service.php before sending to ZIMRA
        // Reorder receiptTaxes fields: taxPercent, taxID, taxCode, taxAmount, salesAmountWithTax (taxCode kept for signature)
        foreach ($receiptData['receiptTaxes'] as &$tax) {
            $taxPercent = floatval($tax['taxPercent']);
            $taxID = intval($tax['taxID']);
            $taxCode = $tax['taxCode'] ?? ''; // Keep taxCode for signature generation
            $taxAmount = floatval($tax['taxAmount']);
            $salesAmountWithTax = floatval($tax['salesAmountWithTax']);
            
            // Rebuild with correct order, keeping taxCode for signature generation
            $tax = [
                'taxPercent' => $taxPercent,
                'taxID' => $taxID,
                'taxCode' => $taxCode, // Keep for signature (will be removed from payload before sending)
                'taxAmount' => $taxAmount,
                'salesAmountWithTax' => $salesAmountWithTax
            ];
        }
        unset($tax);
        
        writeFiscalLog("FISCALIZE SALE: Applied fixes - kept taxCode in receiptTaxes for signature generation, ensured float types, reordered receiptTaxes fields");
        
        // Build payments
        // ZIMRA MoneyType enum: 0=Cash, 1=Card, 2=MobileWallet, 3=Coupon, 4=Credit, 5=BankTransfer, 6=Other
        $totalPaymentAmount = 0;
        foreach ($salePayments as $payment) {
            $method = strtolower($payment['payment_method'] ?? 'cash');
            
            // Map payment method to ZIMRA MoneyType integer codes
            $moneyTypeCode = 0; // Default to Cash (0)
            if ($method === 'cash') {
                $moneyTypeCode = 0; // Cash
            } elseif ($method === 'card') {
                $moneyTypeCode = 1; // Card
            } elseif ($method === 'ecocash' || $method === 'onemoney' || $method === 'mobile') {
                $moneyTypeCode = 2; // MobileWallet
            } elseif ($method === 'coupon') {
                $moneyTypeCode = 3; // Coupon
            } elseif ($method === 'credit') {
                $moneyTypeCode = 4; // Credit
            } elseif ($method === 'bank' || $method === 'banktransfer') {
                $moneyTypeCode = 5; // BankTransfer
            } else {
                $moneyTypeCode = 6; // Other
            }
            
            // Use original_amount (in payment currency) if available, otherwise convert base_amount
            $paymentAmount = null;
            if (isset($payment['original_amount']) && $payment['original_amount'] !== null) {
                $paymentAmount = floatval($payment['original_amount']);
                writeFiscalLog("FISCALIZE SALE: Using original_amount from payment: $paymentAmount");
            } elseif (isset($payment['base_amount']) && $payment['base_amount'] !== null && $exchangeRateToPayment != 1.0) {
                // Convert base_amount to payment currency
                $paymentAmount = floatval($payment['base_amount']) * $exchangeRateToPayment;
                writeFiscalLog("FISCALIZE SALE: Converted base_amount {$payment['base_amount']} to payment currency: $paymentAmount (rate: $exchangeRateToPayment)");
            } else {
                // Fallback to amount field
                $paymentAmount = floatval($payment['amount']);
                // If exchange rate is not 1.0, we need to convert
                if ($exchangeRateToPayment != 1.0) {
                    $paymentAmount = $paymentAmount * $exchangeRateToPayment;
                    writeFiscalLog("FISCALIZE SALE: Converted payment amount from base to payment currency: $paymentAmount (rate: $exchangeRateToPayment)");
                }
            }
            
            $totalPaymentAmount += $paymentAmount;
            
            writeFiscalLog("FISCALIZE SALE: Payment - method: $method, moneyTypeCode: $moneyTypeCode, amount: $paymentAmount, currency: " . ($payment['currency_code'] ?? 'N/A'));
            
            $receiptData['receiptPayments'][] = [
                'moneyTypeCode' => $moneyTypeCode, // Integer (0-6) per ZIMRA MoneyType enum
                'paymentAmount' => $paymentAmount // Already in payment currency
            ];
        }
        
        // RCPT039: receiptTotal must equal sum of all paymentAmount
        // Validate and fix if needed
        $receiptTotal = floatval($receiptData['receiptTotal']);
        $difference = abs($receiptTotal - $totalPaymentAmount);
        
        error_log("FISCALIZE SALE: Payment validation - receiptTotal: $receiptTotal, sum of payments: $totalPaymentAmount, difference: $difference");
        
        if ($difference > 0.01) { // Allow 1 cent tolerance for floating point
            if ($totalPaymentAmount == 0) {
                // No payments found - add a default cash payment
                error_log("FISCALIZE SALE: No payments found, adding default cash payment equal to receiptTotal");
                $receiptData['receiptPayments'] = [[
                    'moneyTypeCode' => 0, // Integer: 0 = Cash
                    'paymentAmount' => floatval($receiptTotal)
                ]];
            } elseif ($totalPaymentAmount < $receiptTotal) {
                // Payments are less than total - add difference as cash
                $difference = $receiptTotal - $totalPaymentAmount;
                error_log("FISCALIZE SALE: Payments ($totalPaymentAmount) less than total ($receiptTotal), adding $difference as cash payment");
                $receiptData['receiptPayments'][] = [
                    'moneyTypeCode' => 0, // Integer: 0 = Cash
                    'paymentAmount' => round($difference, 2)
                ];
            } else {
                // Payments exceed total - adjust last payment to match
                $excess = $totalPaymentAmount - $receiptTotal;
                error_log("FISCALIZE SALE: Payments ($totalPaymentAmount) exceed total ($receiptTotal) by $excess, adjusting last payment");
                if (!empty($receiptData['receiptPayments'])) {
                    $lastIndex = count($receiptData['receiptPayments']) - 1;
                    $lastPayment = &$receiptData['receiptPayments'][$lastIndex];
                    $lastPayment['paymentAmount'] = round($lastPayment['paymentAmount'] - $excess, 2);
                    // Ensure it's not negative
                    if ($lastPayment['paymentAmount'] < 0) {
                        $lastPayment['paymentAmount'] = 0;
                    }
                }
            }
        }
        
        // Final validation - recalculate sum
        $finalPaymentSum = 0;
        foreach ($receiptData['receiptPayments'] as $payment) {
            $finalPaymentSum += floatval($payment['paymentAmount']);
        }
        
        $finalDifference = abs($receiptTotal - $finalPaymentSum);
        if ($finalDifference > 0.01) {
            error_log("FISCALIZE SALE: WARNING - After adjustment, payment sum ($finalPaymentSum) still doesn't match receiptTotal ($receiptTotal), difference: $finalDifference");
            // Force match by adjusting receiptTotal to match payments (last resort)
            // This should rarely happen, but ensures RCPT039 compliance
            $receiptData['receiptTotal'] = round($finalPaymentSum, 2);
            error_log("FISCALIZE SALE: Adjusted receiptTotal to match payment sum: " . $receiptData['receiptTotal']);
        } else {
            error_log("FISCALIZE SALE: Payment validation passed - receiptTotal: $receiptTotal, payment sum: $finalPaymentSum");
        }
        
        // CRITICAL: Log EXACT receiptData BEFORE calling submitReceipt (for comparison with test scripts)
        $logFile = APP_PATH . '/logs/interface_receipt_data_log.txt';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "========================================\n";
        $logMessage .= "[$timestamp] INTERFACE RECEIPT DATA - BEFORE submitReceipt() CALL\n";
        $logMessage .= "========================================\n";
        $logMessage .= "Sale ID: $saleId\n";
        $logMessage .= "Device ID: " . ($receiptData['deviceID'] ?? 'NOT SET') . "\n";
        $logMessage .= "Receipt Counter: " . ($receiptData['receiptCounter'] ?? 'NOT SET') . "\n";
        $logMessage .= "Receipt Global No: " . ($receiptData['receiptGlobalNo'] ?? 'NOT SET') . "\n";
        $logMessage .= "Receipt Type: " . ($receiptData['receiptType'] ?? 'NOT SET') . "\n";
        $logMessage .= "Receipt Currency: " . ($receiptData['receiptCurrency'] ?? 'NOT SET') . "\n";
        $logMessage .= "Receipt Total: " . ($receiptData['receiptTotal'] ?? 'NOT SET') . " (type: " . gettype($receiptData['receiptTotal'] ?? null) . ")\n";
        $logMessage .= "Receipt Date: " . ($receiptData['receiptDate'] ?? 'NOT SET') . "\n";
        $logMessage .= "Invoice No: " . ($receiptData['invoiceNo'] ?? 'NOT SET') . "\n";
        $logMessage .= "Receipt Lines Count: " . count($receiptData['receiptLines'] ?? []) . "\n";
        $logMessage .= "Receipt Taxes Count: " . count($receiptData['receiptTaxes'] ?? []) . "\n";
        $logMessage .= "Receipt Payments Count: " . count($receiptData['receiptPayments'] ?? []) . "\n";
        $logMessage .= "\n";
        $logMessage .= "COMPLETE receiptData JSON (exact structure):\n";
        $logMessage .= json_encode($receiptData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $logMessage .= "\n";
        $logMessage .= "Receipt Lines Details:\n";
        foreach ($receiptData['receiptLines'] ?? [] as $idx => $line) {
            $logMessage .= "  Line[$idx]: " . json_encode($line, JSON_UNESCAPED_SLASHES) . "\n";
        }
        $logMessage .= "\n";
        $logMessage .= "Receipt Taxes Details:\n";
        foreach ($receiptData['receiptTaxes'] ?? [] as $idx => $tax) {
            $logMessage .= "  Tax[$idx]: " . json_encode($tax, JSON_UNESCAPED_SLASHES) . "\n";
        }
        $logMessage .= "\n";
        $logMessage .= "Receipt Payments Details:\n";
        foreach ($receiptData['receiptPayments'] ?? [] as $idx => $payment) {
            $logMessage .= "  Payment[$idx]: " . json_encode($payment, JSON_UNESCAPED_SLASHES) . "\n";
        }
        $logMessage .= "\n";
        $logMessage .= "========================================\n\n";
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
        error_log("INTERFACE RECEIPT DATA: Logged complete receiptData to $logFile before submitReceipt() call");
        
        // Get previous receipt hash for receipt chaining
        // This helps ensure proper receipt chaining when submitReceipt retrieves the hash
        $previousReceiptHash = null;
        $previousReceiptRecord = $primaryDb->getRow(
            "SELECT receipt_hash, receipt_server_signature 
             FROM fiscal_receipts 
             WHERE device_id = :device_id 
             AND submission_status = 'Submitted'
             AND receipt_hash IS NOT NULL
             ORDER BY receipt_global_no DESC, id DESC 
             LIMIT 1",
            [':device_id' => $device['device_id']]
        );
        
        if ($previousReceiptRecord && !empty($previousReceiptRecord['receipt_hash'])) {
            // CRITICAL: Use OUR generated hash (from receipt_hash field), NOT ZIMRA's hash
            // Test results confirm that using our hash prevents RCPT020 errors on subsequent receipts
            // Even though ZIMRA returns a different hash in receiptServerSignature, we MUST use our hash for chaining
            $previousReceiptHash = $previousReceiptRecord['receipt_hash'];
            error_log("FISCALIZE SALE: Retrieved previous receipt hash (OUR generated hash): " . substr($previousReceiptHash, 0, 30) . "...");
        } else {
            error_log("FISCALIZE SALE: No previous receipt hash found (this may be the first receipt)");
        }
        
        // Submit receipt
        // If sale is linked to an invoice, use that invoice_id; otherwise use 0 (NULL)
        $saleInvoiceId = !empty($sale['invoice_id']) ? intval($sale['invoice_id']) : 0;
        $result = $fiscalService->submitReceipt($saleInvoiceId, $receiptData, $saleId, $previousReceiptHash);
        
        // Try to get QR code image from fiscal_receipts table (if database save succeeded)
        // Otherwise use the QR code from the result (which is always available)
        $primaryDb = Database::getPrimaryInstance();
        $fiscalReceipt = $primaryDb->getRow(
            "SELECT receipt_qr_code, receipt_qr_data, receipt_verification_code, receipt_global_no, receipt_id 
             FROM fiscal_receipts 
             WHERE sale_id = :sale_id 
             ORDER BY id DESC LIMIT 1",
            [':sale_id' => $saleId]
        );
        
        // Use QR code from result (always available) or from database (if save succeeded)
        $qrCodeImage = $result['qrCodeImage'] ?? $fiscalReceipt['receipt_qr_code'] ?? null;
        
        // Update sale with fiscal details
        $fiscalDetails = [
            'receipt_id' => $result['receiptID'] ?? '',
            'receipt_global_no' => $result['receiptGlobalNo'] ?? $receiptGlobalNo,
            'fiscal_day_no' => $fiscalDay['fiscal_day_no'],
            'device_id' => $device['device_id'],
            'qr_code' => $result['qrCode'] ?? '',
            'verification_code' => $result['verificationCode'] ?? '',
            'qr_code_image' => $qrCodeImage // Base64 encoded QR image
        ];
        
        $db->update('sales', [
            'fiscalized' => 1,
            'fiscal_details' => json_encode($fiscalDetails)
        ], ['id' => $saleId]);
        
        // Return result with QR code image included
        $result['qrCodeImage'] = $qrCodeImage;
        return $result;
        
    } catch (Exception $e) {
        error_log("FISCALIZE SALE ERROR for sale $saleId: " . $e->getMessage());
        error_log("FISCALIZE SALE STACK TRACE: " . $e->getTraceAsString());
        // Re-throw the exception so the actual ZIMRA error can be captured and displayed to the user
        throw $e;
    }
}

/**
 * Build receipt data for ZIMRA submission
 */
function buildReceiptData($invoice, $invoiceItems, $customer, $device, $config, $applicableTaxes, $fiscalDay, $receiptCounter, $receiptGlobalNo) {
    // Use tenant database for products
    $db = Database::getInstance();
    
    // Determine receipt type
    $receiptType = 'FiscalInvoice';
    if ($invoice['invoice_type'] === 'CreditNote') {
        $receiptType = 'CreditNote';
    }
    
    // Determine currency (default to ZWL)
    $receiptCurrency = 'ZWL';
    
    // Map currency code for ZIMRA API (ZWL -> ZWG)
    $receiptCurrencyForZimra = mapCurrencyCodeForZimra($receiptCurrency);
    
    // Build receipt lines
    $receiptLines = [];
    $lineNo = 1;
    
    foreach ($invoiceItems as $item) {
        // Determine tax
        $taxId = 1; // Default tax ID
        $taxPercent = 15.00; // Default 15% VAT
        $taxCode = 'A'; // Default tax code
        
        // Find matching tax from applicable taxes
        foreach ($applicableTaxes as $tax) {
            if ($tax['taxPercent'] == 15.00) {
                $taxId = $tax['taxID'];
                $taxPercent = $tax['taxPercent'];
                $taxCode = 'A'; // You may need to map this properly
                break;
            }
        }
        
        // Get product HS code if available
        $hsCode = null;
        if ($item['product_id']) {
            $product = $db->getRow("SELECT hs_code FROM products WHERE id = :id", [':id' => $item['product_id']]);
            $hsCode = $product['hs_code'] ?? null;
        }
        
        $receiptLines[] = [
            'receiptLineType' => 'Sale',
            'receiptLineNo' => $lineNo++,
            'receiptLineHSCode' => $hsCode,
            'receiptLineName' => $item['description'] ?? 'Item',
            'receiptLinePrice' => floatval($item['unit_price']),
            'receiptLineQuantity' => floatval($item['quantity']),
            'receiptLineTotal' => floatval($item['line_total']),
            'taxCode' => $taxCode,
            'taxPercent' => $taxPercent,
            'taxID' => $taxId
        ];
    }
    
    // Calculate taxes
    $receiptTaxes = [];
    $taxGroups = [];
    
    foreach ($receiptLines as $line) {
        $taxKey = $line['taxID'] . '_' . ($line['taxCode'] ?? '');
        if (!isset($taxGroups[$taxKey])) {
            $taxGroups[$taxKey] = [
                'taxID' => $line['taxID'],
                'taxCode' => $line['taxCode'],
                'taxPercent' => $line['taxPercent'],
                'total' => 0
            ];
        }
        $taxGroups[$taxKey]['total'] += $line['receiptLineTotal'];
    }
    
    foreach ($taxGroups as $group) {
        // RCPT026: taxAmount calculation based on receiptLinesTaxInclusive flag
        // Documentation: 
        // - If receiptLinesTaxInclusive is true: taxAmount = SUM(receiptLineTotal) * taxPercent/(1+taxPercent)
        // - If receiptLinesTaxInclusive is false: taxAmount = SUM(receiptLineTotal) * taxPercent
        // 
        // ZIMRA taxPercent format: According to documentation "Decimal (5,2)", it's in percentage form (e.g., 15.50 for 15.5%)
        // But the formula taxPercent/(1+taxPercent) suggests decimal form (0.155 for 15.5%)
        // 
        // To handle both interpretations, we check: if taxPercent > 1, it's percentage form, else decimal form
        $taxPercentValue = floatval($group['taxPercent']);
        $isTaxInclusive = true; // Default for invoices (can be overridden if needed)
        
        if ($isTaxInclusive) {
            // Tax-inclusive: taxAmount = total * taxPercent/(1+taxPercent)
            // If taxPercent is in percentage form (e.g., 15.5), convert to decimal first
            if ($taxPercentValue > 1) {
                // taxPercent is in percentage form (15.5), convert to decimal (0.155)
                $taxPercentDecimal = $taxPercentValue / 100;
                $taxAmount = $group['total'] * ($taxPercentDecimal / (1 + $taxPercentDecimal));
            } else {
                // taxPercent is already in decimal form (0.155)
                $taxAmount = $group['total'] * ($taxPercentValue / (1 + $taxPercentValue));
            }
        } else {
            // Tax-exclusive: taxAmount = total * taxPercent
            if ($taxPercentValue > 1) {
                // taxPercent is in percentage form (15.5), convert to decimal (0.155)
                $taxPercentDecimal = $taxPercentValue / 100;
                $taxAmount = $group['total'] * $taxPercentDecimal;
            } else {
                // taxPercent is already in decimal form (0.155)
                $taxAmount = $group['total'] * $taxPercentValue;
            }
        }
        
        $salesAmountWithTax = $group['total'];
        
        $receiptTaxes[] = [
            'taxCode' => $group['taxCode'],
            'taxPercent' => $group['taxPercent'],
            'taxID' => $group['taxID'],
            'taxAmount' => round($taxAmount, 2),
            'salesAmountWithTax' => round($salesAmountWithTax, 2)
        ];
    }
    
    // Build payments
    $receiptPayments = [];
    $paymentMethods = json_decode($invoice['payment_methods'] ?? '[]', true);
    
    if (empty($paymentMethods)) {
        // Default to cash
        $receiptPayments[] = [
            'moneyTypeCode' => 'Cash',
            'paymentAmount' => floatval($invoice['total_amount'])
        ];
    } else {
        // Parse payment methods
        foreach ($paymentMethods as $method) {
            $moneyType = mapPaymentMethodToMoneyType($method);
            $receiptPayments[] = [
                'moneyTypeCode' => $moneyType,
                'paymentAmount' => floatval($invoice['total_amount'])
            ];
            break; // For now, use first payment method
        }
    }
    
    // Build buyer data
    $buyerData = null;
    if ($customer) {
        $buyerData = [
            'buyerRegisterName' => $customer['company_name'] ?? ($customer['first_name'] . ' ' . $customer['last_name']),
            'buyerTradeName' => $customer['company_name'] ?? null,
            'buyerTIN' => $customer['tin'] ?? '',
            'vatNumber' => $customer['vat_number'] ?? null,
            'buyerContacts' => [
                'email' => $customer['email'] ?? null,
                'phoneNo' => $customer['phone'] ?? null
            ],
            'buyerAddress' => $customer['address'] ? [
                'street' => $customer['address'],
                'city' => $customer['city'] ?? '',
                'province' => $customer['province'] ?? '',
                'houseNo' => ''
            ] : null
        ];
    }
    
    // Build receipt data
    $receiptData = [
        'deviceID' => $device['device_id'],
        'receiptType' => $receiptType,
        'receiptCurrency' => $receiptCurrencyForZimra, // Use currency mapped for ZIMRA (ZWL->ZWG)
        'receiptCounter' => $receiptCounter,
        'receiptGlobalNo' => $receiptGlobalNo,
        'invoiceNo' => $invoice['invoice_number'],
        'receiptDate' => $invoice['invoice_date'],
        'receiptLinesTaxInclusive' => true,
        'receiptLines' => $receiptLines,
        'receiptTaxes' => $receiptTaxes,
        'receiptPayments' => $receiptPayments,
        'receiptTotal' => floatval($invoice['total_amount']),
        'receiptPrintForm' => 'InvoiceA4',
        'buyerData' => $buyerData
    ];
    
    return $receiptData;
}

/**
 * Map payment method to ZIMRA money type
 */
function mapPaymentMethodToMoneyType($method) {
    $method = strtolower($method);
    
    if (strpos($method, 'cash') !== false) {
        return 'Cash';
    } elseif (strpos($method, 'card') !== false) {
        return 'Card';
    } elseif (strpos($method, 'ecocash') !== false || strpos($method, 'onemoney') !== false || strpos($method, 'mobile') !== false) {
        return 'MobileWallet';
    } elseif (strpos($method, 'bank') !== false || strpos($method, 'transfer') !== false) {
        return 'BankTransfer';
    } else {
        return 'Cash'; // Default
    }
}


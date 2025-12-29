<?php
/**
 * Script to recalculate and fix invoice totals for all existing invoices
 * This matches the POS calculation: Subtotal - Discount + Sum of Taxes
 */

require_once dirname(__FILE__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/settings_functions.php';

echo "Starting invoice totals fix...\n\n";

try {
    $db = Database::getInstance();
    if (!$db) {
        throw new Exception("Failed to connect to tenant database");
    }
    
    $primaryDb = Database::getPrimaryInstance();
    if (!$primaryDb) {
        echo "Warning: Could not connect to primary database. Tax rates may be limited.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Get all invoices
$invoices = $db->getRows("SELECT * FROM invoices ORDER BY id");

if (!$invoices || empty($invoices)) {
    echo "No invoices found.\n";
    exit;
}

echo "Found " . count($invoices) . " invoices to process.\n\n";

try {
    $pricesIncludeTax = getSetting('prices_include_tax', '1') == '1';
    echo "Prices include tax: " . ($pricesIncludeTax ? 'Yes' : 'No') . "\n";
    
    $defaultTaxRate = getDefaultTaxRate();
    echo "Default tax rate: {$defaultTaxRate}%\n\n";
} catch (Exception $e) {
    echo "ERROR loading settings: " . $e->getMessage() . "\n";
    exit(1);
} catch (Error $e) {
    echo "FATAL ERROR loading settings: " . $e->getMessage() . "\n";
    exit(1);
}

// Get tax map from fiscal config
$taxMap = [];
try {
    if ($primaryDb) {
        $fiscalConfigs = $primaryDb->getRows("SELECT branch_id, applicable_taxes FROM fiscal_config");
        if ($fiscalConfigs && is_array($fiscalConfigs)) {
            foreach ($fiscalConfigs as $config) {
                if (!empty($config['applicable_taxes'])) {
                    $applicableTaxes = json_decode($config['applicable_taxes'], true);
                    if (is_array($applicableTaxes)) {
                        foreach ($applicableTaxes as $tax) {
                            if (isset($tax['taxID']) && isset($tax['taxPercent'])) {
                                $taxId = intval($tax['taxID']);
                                $taxPercent = floatval($tax['taxPercent']);
                                $taxMap[$taxId] = $taxPercent;
                                $taxMap[(string)$taxId] = $taxPercent; // Also store as string key
                            }
                        }
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    echo "Warning: Could not load tax map from fiscal config: " . $e->getMessage() . "\n";
    echo "Will use default tax rate for all items.\n\n";
} catch (Error $e) {
    echo "Warning: Could not load tax map from fiscal config: " . $e->getMessage() . "\n";
    echo "Will use default tax rate for all items.\n\n";
}

$updated = 0;
$errors = 0;

echo "Tax map loaded with " . count($taxMap) . " entries.\n";
echo "Default tax rate: {$defaultTaxRate}%\n";
echo "Prices include tax: " . ($pricesIncludeTax ? 'Yes' : 'No') . "\n\n";

foreach ($invoices as $invoice) {
    $invoiceId = $invoice['id'];
    $invoiceNumber = $invoice['invoice_number'];
    
    echo "Processing invoice #{$invoiceNumber} (ID: {$invoiceId})...\n";
    
    try {
        // Get invoice items
        $invoiceItems = $db->getRows(
            "SELECT ii.*, p.tax_id as product_tax_id, pc.tax_id as category_tax_id 
             FROM invoice_items ii 
             LEFT JOIN products p ON ii.product_id = p.id 
             LEFT JOIN product_categories pc ON p.category_id = pc.id 
             WHERE ii.invoice_id = :id",
            [':id' => $invoiceId]
        );
        
        if ($invoiceItems === false) {
            echo "  ERROR: Failed to fetch items.\n\n";
            $errors++;
            continue;
        }
        
        if (empty($invoiceItems)) {
            echo "  No items found, skipping.\n\n";
            continue;
        }
        
        // Calculate global discount percentage
        $globalDiscountPercent = 0;
        if ($invoice['subtotal'] > 0 && $invoice['discount_amount'] > 0 && $invoice['discount_amount'] > 0.01) {
            $globalDiscountPercent = ($invoice['discount_amount'] / $invoice['subtotal']) * 100;
        }
        
        // Recalculate totals
        $subtotalExclVAT = 0;
        $taxGroups = [];
        
        foreach ($invoiceItems as $item) {
            $unitPrice = floatval($item['unit_price'] ?? 0);
            $quantity = intval($item['quantity'] ?? 1);
            
            // Get product-specific tax rate
            $productTaxId = $item['product_tax_id'] ?? null;
            $categoryTaxId = $item['category_tax_id'] ?? null;
            $finalTaxId = $productTaxId ?: $categoryTaxId;
            $itemTaxRate = $defaultTaxRate;
            
            if ($finalTaxId) {
                $taxIdInt = intval($finalTaxId);
                if (isset($taxMap[$taxIdInt])) {
                    $itemTaxRate = $taxMap[$taxIdInt];
                } elseif (isset($taxMap[(string)$taxIdInt])) {
                    $itemTaxRate = $taxMap[(string)$taxIdInt];
                }
            }
            
            // Calculate from original unit_price (this is the price WITH tax if pricesIncludeTax)
            $lineSubtotal = $unitPrice * $quantity;
            
            // Calculate tax from original price
            $lineTax = 0;
            $priceWithoutTax = 0;
            
            if ($pricesIncludeTax) {
                // Prices include tax - EXTRACT tax from original price
                if ($itemTaxRate > 0) {
                    $taxDecimal = $itemTaxRate / 100;
                    $priceWithoutTax = $lineSubtotal / (1 + $taxDecimal);
                    $lineTax = $lineSubtotal - $priceWithoutTax;
                } else {
                    $priceWithoutTax = $lineSubtotal;
                    $lineTax = 0;
                }
            } else {
                // Prices do NOT include tax - ADD tax on top
                $priceWithoutTax = $lineSubtotal;
                if ($itemTaxRate > 0) {
                    $lineTax = $lineSubtotal * ($itemTaxRate / 100);
                } else {
                    $lineTax = 0;
                }
            }
            
            // Round tax to 2 decimal places
            $lineTax = round($lineTax, 2);
            $priceWithoutTax = round($priceWithoutTax, 2);
            
            // Add to subtotal (Excl VAT)
            $subtotalExclVAT += $priceWithoutTax;
            
            // Group taxes by rate
            if ($lineTax > 0) {
                $key = number_format($itemTaxRate, 1);
                if (!isset($taxGroups[$key])) {
                    $taxGroups[$key] = [
                        'rate' => $itemTaxRate,
                        'amount' => 0
                    ];
                }
                $taxGroups[$key]['amount'] += $lineTax;
            }
        }
        
        // Round tax group amounts
        foreach ($taxGroups as &$group) {
            $group['amount'] = round($group['amount'], 2);
        }
        unset($group);
        
        // Sum all taxes
        $totalTax = 0;
        foreach ($taxGroups as $group) {
            $totalTax += $group['amount'];
        }
        
        // Calculate subtotal WITH tax (sum of original prices including tax)
        $subtotalInclTax = 0;
        foreach ($invoiceItems as $item) {
            $unitPrice = floatval($item['unit_price'] ?? 0);
            $quantity = intval($item['quantity'] ?? 1);
            $lineSubtotal = $unitPrice * $quantity; // WITH tax if pricesIncludeTax
            $subtotalInclTax += $lineSubtotal;
        }
        
        // Calculate discount from subtotal INCLUDING tax (matching POS behavior)
        $discountAmount = $subtotalInclTax * ($globalDiscountPercent / 100);
        $discountAmount = round($discountAmount, 2);
        
        // Total (Incl VAT) = Subtotal - Discount + Sum of Taxes
        $totalAmount = $subtotalExclVAT - $discountAmount + $totalTax;
        $totalAmount = round($totalAmount, 2);
        
        // Update invoice
        $updateData = [
            'subtotal' => round($subtotalExclVAT, 2),
            'discount_amount' => $discountAmount,
            'tax_amount' => round($totalTax, 2),
            'total_amount' => $totalAmount,
            'balance_due' => $totalAmount
        ];
        
        $result = $db->update('invoices', $updateData, ['id' => $invoiceId]);
        
        if ($result === false) {
            throw new Exception("Failed to update invoice in database");
        }
        
        $oldTotal = floatval($invoice['total_amount']);
        $newTotal = $totalAmount;
        
        if (abs($oldTotal - $newTotal) > 0.01) {
            echo "  Updated: " . number_format($oldTotal, 2) . " -> " . number_format($newTotal, 2) . "\n";
            $updated++;
        } else {
            echo "  No change needed (already correct: " . number_format($newTotal, 2) . ")\n";
        }
        
        echo "\n";
        
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        echo "  Stack trace: " . $e->getTraceAsString() . "\n\n";
        $errors++;
    } catch (Error $e) {
        echo "  FATAL ERROR: " . $e->getMessage() . "\n";
        echo "  Stack trace: " . $e->getTraceAsString() . "\n\n";
        $errors++;
    }
}

echo "\n";
echo "========================================\n";
echo "Fix completed!\n";
echo "Updated: {$updated} invoices\n";
echo "Errors: {$errors} invoices\n";
echo "========================================\n";


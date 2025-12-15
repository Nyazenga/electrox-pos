<?php
/**
 * Backfill Script: Create sales records for paid invoices that don't have corresponding sales
 * 
 * This script processes all invoices with status "Paid" that don't have a linked sale record
 * and creates the appropriate sales, sale items, and payment records.
 * 
 * Usage: php backfill_invoice_sales.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

echo "=== Invoice Sales Backfill Script ===\n\n";

try {
    $db = Database::getInstance();
    
    // Ensure POS tables exist
    @ensurePOSTables($db);
    
    // Add invoice_id column to sales table if it doesn't exist
    try {
        $columnCheck = $db->getRow("SHOW COLUMNS FROM sales WHERE Field = 'invoice_id'");
        if (!$columnCheck) {
            $db->executeQuery("ALTER TABLE sales ADD COLUMN invoice_id int(11) DEFAULT NULL AFTER customer_id, ADD KEY idx_invoice_id (invoice_id)");
            echo "✓ Added invoice_id column to sales table\n";
        }
    } catch (Exception $e) {
        echo "⚠ Column check: " . $e->getMessage() . "\n";
    }
    
    // Find all paid invoices without sales
    // Use LEFT JOIN to find invoices that don't have a corresponding sale
    $paidInvoices = $db->getRows("SELECT i.* 
        FROM invoices i 
        LEFT JOIN sales s ON s.invoice_id = i.id 
        WHERE i.status = 'Paid' AND (s.id IS NULL OR s.invoice_id IS NULL)
        ORDER BY i.id ASC");
    
    if ($paidInvoices === false || empty($paidInvoices)) {
        echo "✓ No paid invoices found without sales records. All invoices are synced!\n";
        exit(0);
    }
    
    echo "Found " . count($paidInvoices) . " paid invoice(s) without sales records.\n\n";
    
    $successCount = 0;
    $errorCount = 0;
    $skippedCount = 0;
    
    foreach ($paidInvoices as $invoice) {
        echo "Processing Invoice: {$invoice['invoice_number']} (ID: {$invoice['id']})...\n";
        
        try {
            $db->beginTransaction();
            
            $branchId = $invoice['branch_id'] ?? null;
            $userId = $invoice['user_id'] ?? 1; // Default to user 1 if not set
            
            // Get invoice items with product_id (only create sale for items with products)
            $invoiceItems = $db->getRows("SELECT ii.*, p.brand, p.model, p.quantity_in_stock
                FROM invoice_items ii 
                LEFT JOIN products p ON ii.product_id = p.id 
                WHERE ii.invoice_id = :id AND ii.product_id IS NOT NULL AND ii.product_id > 0", 
                [':id' => $invoice['id']]);
            
            if (empty($invoiceItems)) {
                echo "  ⚠ Skipping: No products in invoice items (or all items are manual/non-product items)\n";
                $db->rollbackTransaction();
                $skippedCount++;
                continue;
            }
            
            // Verify stock availability (but don't block backfill - just log warning)
            $stockWarnings = [];
            foreach ($invoiceItems as $item) {
                $productId = intval($item['product_id']);
                $quantity = intval($item['quantity'] ?? 1);
                
                if ($productId > 0 && $quantity > 0) {
                    $branchId = $invoice['branch_id'] ?? null;
                    if ($branchId !== null) {
                        $product = $db->getRow("SELECT quantity_in_stock, brand, model FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $branchId]);
                    } else {
                        $product = $db->getRow("SELECT SUM(quantity_in_stock) as quantity_in_stock, brand, model FROM products WHERE id = :id GROUP BY id", 
                            [':id' => $productId]);
                    }
                    
                    $availableStock = $product ? intval($product['quantity_in_stock'] ?? 0) : 0;
                    if ($availableStock < $quantity) {
                        $productName = trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? '')) ?: "Product #{$productId}";
                        $stockWarnings[] = "{$productName} (Available: {$availableStock}, Required: {$quantity})";
                    }
                }
            }
            
            if (!empty($stockWarnings)) {
                echo "  ⚠ Warning: Some products have insufficient stock: " . implode(', ', $stockWarnings) . "\n";
                echo "  ⚠ Continuing anyway (historical backfill)...\n";
            }
            
            // Get or create shift
            $shift = null;
            if ($branchId !== null) {
                $shift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
                    ':branch_id' => $branchId,
                    ':user_id' => $userId
                ]);
            } else {
                $shift = $db->getRow("SELECT * FROM shifts WHERE (branch_id IS NULL OR branch_id = 0) AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
                    ':user_id' => $userId
                ]);
            }
            
            // If no open shift, find the most recent closed shift or create a new one
            if (!$shift) {
                if ($branchId !== null) {
                    $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE branch_id = :branch_id ORDER BY id DESC LIMIT 1", [':branch_id' => $branchId]);
                } else {
                    $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE (branch_id IS NULL OR branch_id = 0) ORDER BY id DESC LIMIT 1");
                }
                $shiftNumber = ($lastShift ? intval($lastShift['shift_number']) : 0) + 1;
                
                $shiftData = [
                    'shift_number' => $shiftNumber,
                    'branch_id' => $branchId ?? 0,
                    'user_id' => $userId,
                    'opened_at' => $invoice['invoice_date'] ?? date('Y-m-d H:i:s'),
                    'opened_by' => $userId,
                    'starting_cash' => 0.00,
                    'expected_cash' => 0.00,
                    'status' => 'closed', // Mark as closed since it's historical
                    'closed_at' => $invoice['invoice_date'] ?? date('Y-m-d H:i:s'),
                    'closed_by' => $userId
                ];
                
                $shiftId = $db->insert('shifts', $shiftData);
                if ($shiftId) {
                    $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $shiftId]);
                }
            }
            
            if (!$shift) {
                throw new Exception('Failed to get or create shift');
            }
            
            // Generate receipt number (format: BRANCH-DATE-SEQ)
            $datePart = date('ymd', strtotime($invoice['invoice_date'] ?? 'now'));
            $branchPrefix = $branchId ?? 0;
            $maxRetries = 20;
            $receiptNumber = null;
            
            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $pattern = $branchPrefix . '-' . $datePart . '-%';
                $maxReceipt = $db->getRow("SELECT receipt_number FROM sales WHERE receipt_number LIKE :pattern ORDER BY receipt_number DESC LIMIT 1", 
                    [':pattern' => $pattern]);
                
                $seq = 1;
                if ($maxReceipt && isset($maxReceipt['receipt_number'])) {
                    $receiptNum = $maxReceipt['receipt_number'];
                    $prefix = $branchPrefix . '-' . $datePart . '-';
                    
                    if (strpos($receiptNum, $prefix) === 0) {
                        $seqPart = substr($receiptNum, strlen($prefix));
                        if (preg_match('/^(\d+)/', $seqPart, $matches)) {
                            $seq = intval($matches[1]) + 1;
                        }
                    }
                }
                
                $seq += $retry;
                $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
                $receiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded;
                
                // Check if receipt number already exists
                $existing = $db->getRow("SELECT id FROM sales WHERE receipt_number = :receipt_number", [
                    ':receipt_number' => $receiptNumber
                ]);
                
                if (!$existing) {
                    break;
                }
                
                if ($retry < $maxRetries - 1) {
                    usleep(rand(10000, 50000));
                }
            }
            
            if (!$receiptNumber || $retry >= $maxRetries) {
                // Last resort: use random suffix
                $randomSuffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 2));
                $seq = ($seq ?? 9999);
                $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
                $receiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded . '-' . $randomSuffix;
            }
            
            // Calculate totals from invoice
            $subtotal = floatval($invoice['subtotal'] ?? 0);
            $discountAmount = floatval($invoice['discount_amount'] ?? 0);
            $taxAmount = floatval($invoice['tax_amount'] ?? 0);
            $total = floatval($invoice['total_amount'] ?? 0);
            
            // Determine discount type
            $discountType = null;
            if ($discountAmount > 0) {
                $percentageCheck = ($subtotal * ($discountAmount / $subtotal * 100)) / 100;
                if (abs($percentageCheck - $discountAmount) < 0.01 && $subtotal > 0) {
                    $discountType = 'percentage';
                } else {
                    $discountType = 'value';
                }
            }
            
            // Create sale record
            $saleData = [
                'receipt_number' => $receiptNumber,
                'shift_id' => $shift['id'],
                'branch_id' => $branchId,
                'user_id' => $userId,
                'customer_id' => $invoice['customer_id'] ?? null,
                'invoice_id' => $invoice['id'],
                'sale_date' => $invoice['invoice_date'] ?? date('Y-m-d H:i:s'),
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $total,
                'payment_status' => 'paid',
                'notes' => 'Backfilled from invoice: ' . $invoice['invoice_number'],
                'created_at' => $invoice['invoice_date'] ?? date('Y-m-d H:i:s')
            ];
            
            $saleId = $db->insert('sales', $saleData);
            
            if (!$saleId) {
                throw new Exception('Failed to create sale record: ' . $db->getLastError());
            }
            
            echo "  ✓ Created sale #{$saleId} with receipt {$receiptNumber}\n";
            
            // Create sale items from invoice items
            foreach ($invoiceItems as $item) {
                $productId = intval($item['product_id']);
                $quantity = intval($item['quantity'] ?? 1);
                $unitPrice = floatval($item['unit_price'] ?? 0);
                $lineTotal = floatval($item['line_total'] ?? ($unitPrice * $quantity));
                
                // Get product name
                $productName = '';
                if (!empty($item['brand']) || !empty($item['model'])) {
                    $productName = trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''));
                }
                if (empty($productName)) {
                    $product = $db->getRow("SELECT brand, model FROM products WHERE id = :id", [':id' => $productId]);
                    if ($product) {
                        $productName = trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
                    }
                }
                if (empty($productName)) {
                    $productName = 'Product #' . $productId;
                }
                
                $itemData = [
                    'sale_id' => $saleId,
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                    'created_at' => $invoice['invoice_date'] ?? date('Y-m-d H:i:s')
                ];
                
                $itemId = $db->insert('sale_items', $itemData);
                if (!$itemId) {
                    throw new Exception('Failed to create sale item: ' . $db->getLastError());
                }
            }
            
            echo "  ✓ Created " . count($invoiceItems) . " sale item(s)\n";
            
            // Get base currency
            $baseCurrency = getBaseCurrency($db);
            $baseCurrencyId = $baseCurrency ? $baseCurrency['id'] : null;
            
            // Create payment records from invoice payments table
            $invoicePayments = $db->getRows("SELECT * FROM payments WHERE invoice_id = :invoice_id AND status = 'Completed'", 
                [':invoice_id' => $invoice['id']]);
            
            if ($invoicePayments !== false && !empty($invoicePayments)) {
                foreach ($invoicePayments as $invPayment) {
                    $paymentMethod = $invPayment['payment_method'] ?? 'cash';
                    $originalAmount = floatval($invPayment['amount'] ?? 0);
                    $currencyCode = $invPayment['currency'] ?? 'USD';
                    $exchangeRate = floatval($invPayment['exchange_rate'] ?? 1.0);
                    
                    // Get currency ID
                    $currencyId = null;
                    if ($currencyCode && $currencyCode !== 'USD') {
                        $mainDb = Database::getMainInstance();
                        $currency = $mainDb->getRow("SELECT id FROM currencies WHERE code = :code", [':code' => $currencyCode]);
                        if ($currency) {
                            $currencyId = $currency['id'];
                        }
                    }
                    if (!$currencyId) {
                        $currencyId = $baseCurrencyId;
                    }
                    
                    // Calculate base amount
                    $baseAmount = $originalAmount * $exchangeRate;
                    
                    $paymentData = [
                        'sale_id' => $saleId,
                        'payment_method' => strtolower($paymentMethod),
                        'currency_id' => $currencyId,
                        'exchange_rate' => $exchangeRate,
                        'original_amount' => $originalAmount,
                        'base_amount' => $baseAmount,
                        'amount' => $baseAmount,
                        'reference' => $invPayment['reference_number'] ?? $invPayment['transaction_id'] ?? null,
                        'created_at' => $invoice['invoice_date'] ?? date('Y-m-d H:i:s')
                    ];
                    
                    $paymentId = $db->insert('sale_payments', $paymentData);
                    if (!$paymentId) {
                        throw new Exception('Failed to create payment record: ' . $db->getLastError());
                    }
                }
                echo "  ✓ Created " . count($invoicePayments) . " payment record(s)\n";
            } else {
                // No payments in payments table, create default payment
                $paymentMethod = 'cash';
                if (!empty($invoice['payment_methods'])) {
                    $paymentMethods = json_decode($invoice['payment_methods'], true);
                    if (is_array($paymentMethods) && !empty($paymentMethods)) {
                        $firstMethod = $paymentMethods[0];
                        if (is_string($firstMethod)) {
                            $parts = explode(' ', $firstMethod);
                            $paymentMethod = strtolower(end($parts));
                        }
                    }
                }
                
                $paymentData = [
                    'sale_id' => $saleId,
                    'payment_method' => $paymentMethod,
                    'currency_id' => $baseCurrencyId,
                    'exchange_rate' => 1.0,
                    'original_amount' => $total,
                    'base_amount' => $total,
                    'amount' => $total,
                    'created_at' => $invoice['invoice_date'] ?? date('Y-m-d H:i:s')
                ];
                
                $paymentId = $db->insert('sale_payments', $paymentData);
                if (!$paymentId) {
                    throw new Exception('Failed to create payment record: ' . $db->getLastError());
                }
                echo "  ✓ Created default payment record\n";
            }
            
            // Update sales table with base currency
            if ($baseCurrencyId) {
                $db->update('sales', ['base_currency_id' => $baseCurrencyId], ['id' => $saleId]);
            }
            
            // Update shift expected cash for cash payments (only if shift is open)
            if ($shift['status'] === 'open') {
                $cashPayments = $db->getRows("SELECT base_amount, amount FROM sale_payments WHERE sale_id = :sale_id AND LOWER(payment_method) = 'cash'", 
                    [':sale_id' => $saleId]);
                
                $totalCashPaid = 0;
                if ($cashPayments !== false) {
                    foreach ($cashPayments as $cp) {
                        $totalCashPaid += floatval($cp['base_amount'] ?? $cp['amount'] ?? 0);
                    }
                }
                
                if ($totalCashPaid > 0) {
                    $db->update('shifts', [
                        'expected_cash' => $shift['expected_cash'] + $totalCashPaid
                    ], ['id' => $shift['id']]);
                }
            }
            
            $db->commitTransaction();
            $successCount++;
            echo "  ✓ Successfully processed invoice {$invoice['invoice_number']}\n\n";
            
        } catch (Exception $e) {
            $db->rollbackTransaction();
            $errorCount++;
            echo "  ✗ Error processing invoice {$invoice['invoice_number']}: " . $e->getMessage() . "\n\n";
        }
    }
    
    echo "\n=== Backfill Complete ===\n";
    echo "Successfully processed: {$successCount}\n";
    echo "Errors: {$errorCount}\n";
    echo "Skipped: {$skippedCount}\n";
    
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}


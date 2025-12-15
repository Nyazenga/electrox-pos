<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die('Invalid shift ID');
}

$db = Database::getInstance();
$shift = $db->getRow("SELECT s.*, b.branch_name, u1.first_name as opened_first, u1.last_name as opened_last, u2.first_name as closed_first, u2.last_name as closed_last 
                       FROM shifts s 
                       LEFT JOIN branches b ON s.branch_id = b.id 
                       LEFT JOIN users u1 ON s.opened_by = u1.id 
                       LEFT JOIN users u2 ON s.closed_by = u2.id 
                       WHERE s.id = :id", [':id' => $id]);

if (!$shift) {
    die('Shift not found');
}

// Calculate statistics
$cashSales = $db->getRow("SELECT COALESCE(SUM(sp.amount), 0) as total FROM sale_payments sp 
                          INNER JOIN sales s ON sp.sale_id = s.id 
                          WHERE s.shift_id = :shift_id AND LOWER(sp.payment_method) = 'cash'", 
                          [':shift_id' => $id]);
if ($cashSales === false) {
    $cashSales = ['total' => 0];
}

$totalSales = $db->getRow("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total, 
                           COALESCE(SUM(discount_amount), 0) as discount, COALESCE(SUM(tax_amount), 0) as tax 
                           FROM sales WHERE shift_id = :shift_id", 
                           [':shift_id' => $id]);
if ($totalSales === false) {
    $totalSales = ['count' => 0, 'total' => 0, 'discount' => 0, 'tax' => 0];
}

$payIns = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM drawer_transactions 
                       WHERE shift_id = :shift_id AND transaction_type = 'pay_in'", 
                       [':shift_id' => $id]);
if ($payIns === false) {
    $payIns = ['total' => 0];
}

$payOuts = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM drawer_transactions 
                        WHERE shift_id = :shift_id AND transaction_type = 'pay_out'", 
                        [':shift_id' => $id]);
if ($payOuts === false) {
    $payOuts = ['total' => 0];
}

// Get payment types with currency breakdown
$paymentTypes = $db->getRows("SELECT sp.payment_method, 
                                      COALESCE(sp.currency_id, :base_currency_id) as currency_id,
                                      COALESCE(SUM(sp.base_amount), SUM(sp.amount), 0) as total_base,
                                      COALESCE(SUM(sp.original_amount), SUM(sp.amount), 0) as total_original
                               FROM sale_payments sp 
                               INNER JOIN sales s ON sp.sale_id = s.id 
                               WHERE s.shift_id = :shift_id 
                               GROUP BY sp.payment_method, sp.currency_id", 
                               [':shift_id' => $id, ':base_currency_id' => getBaseCurrency($db)['id'] ?? 1]);
if ($paymentTypes === false) {
    $paymentTypes = [];
}

// Get currency-wise breakdown
$currencies = getActiveCurrencies($db);
$currencyBreakdown = [];
foreach ($currencies as $currency) {
    $currencySales = $db->getRow("SELECT 
                                      COALESCE(SUM(sp.base_amount), SUM(sp.amount), 0) as total_base,
                                      COALESCE(SUM(sp.original_amount), SUM(sp.amount), 0) as total_original,
                                      COUNT(DISTINCT sp.sale_id) as transaction_count
                                   FROM sale_payments sp 
                                   INNER JOIN sales s ON sp.sale_id = s.id 
                                   WHERE s.shift_id = :shift_id 
                                     AND COALESCE(sp.currency_id, :base_currency_id) = :currency_id", 
                                   [':shift_id' => $id, 
                                    ':currency_id' => $currency['id'],
                                    ':base_currency_id' => getBaseCurrency($db)['id'] ?? 1]);
    if ($currencySales && ($currencySales['total_base'] > 0 || $currencySales['total_original'] > 0)) {
        $currencyBreakdown[$currency['id']] = [
            'currency' => $currency,
            'total_base' => floatval($currencySales['total_base']),
            'total_original' => floatval($currencySales['total_original']),
            'transaction_count' => intval($currencySales['transaction_count'])
        ];
    }
}

// Get payment method and currency combination breakdown
$paymentMethodCurrencyBreakdown = $db->getRows("SELECT 
                                                    sp.payment_method,
                                                    COALESCE(sp.currency_id, :base_currency_id) as currency_id,
                                                    COALESCE(SUM(sp.base_amount), SUM(sp.amount), 0) as total_base,
                                                    COALESCE(SUM(sp.original_amount), SUM(sp.amount), 0) as total_original
                                                 FROM sale_payments sp 
                                                 INNER JOIN sales s ON sp.sale_id = s.id 
                                                 WHERE s.shift_id = :shift_id 
                                                 GROUP BY sp.payment_method, sp.currency_id
                                                 ORDER BY sp.payment_method, sp.currency_id", 
                                                 [':shift_id' => $id, ':base_currency_id' => getBaseCurrency($db)['id'] ?? 1]);
if ($paymentMethodCurrencyBreakdown === false) {
    $paymentMethodCurrencyBreakdown = [];
}

$companyName = getSetting('company_name', SYSTEM_NAME);

$pageTitle = 'Shift Status Report';
require_once APP_PATH . '/includes/header.php';
?>
<style>
        @media print {
            .no-print,
            .sidebar,
            .topbar,
            header,
            .navbar {
                display: none !important;
            }
            body { 
                margin: 0;
                padding: 0;
            }
            .content-area { 
                padding: 0 !important;
                margin: 0 !important;
            }
        }
        
        .shift-report-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .report-header {
            text-align: center;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .report-section {
            margin: 20px 0;
        }
        .report-section h3 {
            color: #1e3a8a;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        table th, table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background: #f3f4f6;
            font-weight: 600;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                max-width: 100%;
                margin: 10px;
                padding: 15px;
            }
            
            .report-header h1 {
                font-size: 24px;
            }
            
            .report-section h3 {
                font-size: 18px;
            }
            
            table {
                font-size: 12px;
            }
            
            table th, table td {
                padding: 8px 4px;
            }
            
            .no-print button {
                width: 100%;
                margin-bottom: 10px;
                margin-left: 0 !important;
            }
        }
        
        @media (max-width: 480px) {
            body {
                margin: 5px;
                padding: 10px;
            }
            
            .report-header h1 {
                font-size: 20px;
            }
            
            .report-section h3 {
                font-size: 16px;
            }
            
            table {
                font-size: 11px;
            }
            
            table th, table td {
                padding: 6px 2px;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }
    </style>

<div class="shift-report-container">
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <h2>Shift Status Report</h2>
        <div>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print
            </button>
            <a href="cash.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
    
    <div class="report-header">
        <div style="text-align: left; font-size: 12px; margin-bottom: 10px;">
            <?= date('m/d/y, g:i A') ?>
        </div>
        <h1 class="mb-3">Shift Status Report</h1>
        <div style="text-align: left; margin-top: 15px;">
            <div><strong>Shop name:</strong> <?= escapeHtml($shift['branch_name'] ?? 'N/A') ?></div>
            <div><strong>Terminal name:</strong> POS 01</div>
            <div class="text-muted" style="font-size: 12px; margin-top: 5px;">
                <?= date('m/d/y, g:i A') ?>
            </div>
        </div>
    </div>
    
    <div class="report-section">
        <div><strong>Shift opened by:</strong> <?= escapeHtml(($shift['opened_first'] ?? '') . ' ' . ($shift['opened_last'] ?? '')) ?> at <?= formatDateTime($shift['opened_at']) ?></div>
        <?php if ($shift['closed_at']): ?>
            <div><strong>Shift closed by:</strong> <?= escapeHtml(($shift['closed_first'] ?? '') . ' ' . ($shift['closed_last'] ?? '')) ?> at <?= formatDateTime($shift['closed_at']) ?></div>
        <?php endif; ?>
    </div>
    
    <div class="report-section">
        <h3>Cash Drawer</h3>
        <table>
            <tr>
                <td>Starting cash</td>
                <td><?= formatCurrency($shift['starting_cash']) ?></td>
            </tr>
            <tr>
                <td>Cash sale</td>
                <td><?= formatCurrency($cashSales['total']) ?></td>
            </tr>
            <tr>
                <td>Advance payment</td>
                <td><?= formatCurrency(0) ?></td>
            </tr>
            <tr>
                <td>Cash credit settlements:</td>
                <td><?= formatCurrency(0) ?></td>
            </tr>
            <tr>
                <td>Cash refund</td>
                <td><?= formatCurrency(0) ?></td>
            </tr>
            <tr>
                <td>Paid Out</td>
                <td><?= formatCurrency($payOuts['total']) ?></td>
            </tr>
            <tr style="font-weight: bold;">
                <td>Expected cash amount :</td>
                <td><?= formatCurrency($shift['expected_cash']) ?></td>
            </tr>
            <tr>
                <td>Gross sales</td>
                <td><?= formatCurrency($totalSales['total']) ?></td>
            </tr>
            <tr>
                <td>Refunds</td>
                <td><?= formatCurrency(0) ?></td>
            </tr>
            <tr>
                <td>Discounts</td>
                <td><?= formatCurrency($totalSales['discount']) ?></td>
            </tr>
            <tr>
                <td>Net sales</td>
                <td><?= formatCurrency($totalSales['total'] - $totalSales['discount']) ?></td>
            </tr>
            <tr>
                <td>Taxes</td>
                <td><?= formatCurrency($totalSales['tax']) ?></td>
            </tr>
            <tr>
                <td>Total tendered</td>
                <td><?= formatCurrency($totalSales['total']) ?></td>
            </tr>
        </table>
    </div>
    
    <div class="report-section">
        <h3>Payment Type Wise Sale</h3>
        <table>
            <?php 
            // Group by payment method (using base amounts)
            $paymentTotals = [];
            foreach ($paymentTypes as $payment) {
                $method = $payment['payment_method'];
                if (!isset($paymentTotals[$method])) {
                    $paymentTotals[$method] = 0;
                }
                $paymentTotals[$method] += floatval($payment['total_base'] ?? $payment['total']);
            }
            foreach ($paymentTotals as $method => $total): ?>
                <tr>
                    <td><?= escapeHtml(ucfirst($method)) ?></td>
                    <td><?= formatCurrency($total) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    
    <?php if (!empty($currencyBreakdown)): ?>
    <div class="report-section">
        <h3>Currency Breakdown</h3>
        <table>
            <tr>
                <th>Currency</th>
                <th>Transactions</th>
                <th>Amount (Original)</th>
                <th>Amount (Base)</th>
            </tr>
            <?php foreach ($currencyBreakdown as $breakdown): 
                $currency = $breakdown['currency'];
            ?>
                <tr>
                    <td><strong><?= escapeHtml($currency['code']) ?> - <?= escapeHtml($currency['name']) ?></strong></td>
                    <td><?= $breakdown['transaction_count'] ?></td>
                    <td><?= formatCurrencyAmount($breakdown['total_original'], $currency['id'], $db) ?></td>
                    <td><?= formatCurrencyAmount($breakdown['total_base'], getBaseCurrency($db)['id'], $db) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($paymentMethodCurrencyBreakdown)): ?>
    <div class="report-section">
        <h3>Payment Method & Currency Split</h3>
        <table>
            <tr>
                <th>Payment Method</th>
                <th>Currency</th>
                <th>Amount (Original)</th>
                <th>Amount (Base)</th>
            </tr>
            <?php foreach ($paymentMethodCurrencyBreakdown as $breakdown): 
                $currency = getCurrency($breakdown['currency_id'], $db);
            ?>
                <tr>
                    <td><?= escapeHtml(ucfirst($breakdown['payment_method'])) ?></td>
                    <td><?= escapeHtml($currency ? $currency['code'] : 'N/A') ?></td>
                    <td><?= formatCurrencyAmount($breakdown['total_original'], $breakdown['currency_id'], $db) ?></td>
                    <td><?= formatCurrencyAmount($breakdown['total_base'], getBaseCurrency($db)['id'], $db) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


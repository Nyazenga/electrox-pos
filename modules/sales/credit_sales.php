<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('sales.view');

$pageTitle = 'Credit Sales';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$balanceFilter = $_GET['balance_filter'] ?? 'all'; // 'all', 'non_zero', 'zero'
$receiptNumberFilter = trim($_GET['receipt_number'] ?? '');

// Get branches
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions
$whereConditions = ["s.is_credit_sale = 1"];
$params = [];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($balanceFilter === 'non_zero') {
    $whereConditions[] = "s.account_balance > 0";
} elseif ($balanceFilter === 'zero') {
    $whereConditions[] = "s.account_balance = 0";
}

if (!empty($receiptNumberFilter)) {
    $whereConditions[] = "s.receipt_number LIKE :receipt_number";
    $params[':receipt_number'] = '%' . $receiptNumberFilter . '%';
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(*) as total_credit_sales,
    COUNT(CASE WHEN s.account_balance > 0 THEN 1 END) as outstanding_balances,
    COUNT(CASE WHEN s.account_balance = 0 THEN 1 END) as settled_sales,
    COALESCE(SUM(s.total_amount), 0) as total_credit_amount,
    COALESCE(SUM(s.account_balance), 0) as total_outstanding
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_credit_sales' => 0,
        'outstanding_balances' => 0,
        'settled_sales' => 0,
        'total_credit_amount' => 0,
        'total_outstanding' => 0
    ];
}

// Get credit sales
$creditSales = $db->getRows("SELECT 
    s.*,
    CONCAT(c.first_name, ' ', c.last_name) as customer_name,
    c.phone as customer_phone,
    c.email as customer_email,
    b.branch_name,
    pt.name as payment_term_name,
    pt.days as payment_term_days,
    CONCAT(u.first_name, ' ', u.last_name) as salesperson_name
FROM sales s
LEFT JOIN customers c ON s.customer_id = c.id
LEFT JOIN branches b ON s.branch_id = b.id
LEFT JOIN payment_terms pt ON s.payment_term_id = pt.id
LEFT JOIN users u ON s.user_id = u.id
WHERE $whereClause
ORDER BY s.sale_date DESC
LIMIT 1000", $params);

if ($creditSales === false) {
    $creditSales = [];
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-credit-card"></i> Credit Sales</h2>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-filter"></i> Balance Filter</label>
                <select name="balance_filter" class="form-select">
                    <option value="all" <?= $balanceFilter === 'all' ? 'selected' : '' ?>>All Balances</option>
                    <option value="non_zero" <?= $balanceFilter === 'non_zero' ? 'selected' : '' ?>>Non-Zero Balances</option>
                    <option value="zero" <?= $balanceFilter === 'zero' ? 'selected' : '' ?>>Settled (Zero Balance)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-receipt"></i> Receipt Number</label>
                <input type="text" name="receipt_number" class="form-control" placeholder="Search by receipt number..." value="<?= escapeHtml($receiptNumberFilter) ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search"></i> Filter</button>
                <a href="credit_sales.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Credit Sales</h6>
                <h3 class="mb-0"><?= number_format($summary['total_credit_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Outstanding Balances</h6>
                <h3 class="mb-0 text-warning"><?= number_format($summary['outstanding_balances']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Credit Amount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_credit_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Outstanding</h6>
                <h3 class="mb-0 text-danger"><?= formatCurrency($summary['total_outstanding']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Credit Sales List</h5>
    </div>
    <div class="card-body">
        <?php if (empty($creditSales)): ?>
            <p class="text-muted mb-0">No credit sales found for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="creditSalesTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Receipt Number</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th>Salesperson</th>
                            <th>Total Amount</th>
                            <th>Payment Terms</th>
                            <th class="text-end">Account Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($creditSales as $sale): 
                            $balance = floatval($sale['account_balance'] ?? 0);
                            $isSettled = $balance <= 0;
                        ?>
                            <tr>
                                <td><?= date('Y-m-d H:i', strtotime($sale['sale_date'])) ?></td>
                                <td><strong><?= escapeHtml($sale['receipt_number']) ?></strong></td>
                                <td>
                                    <?= escapeHtml($sale['customer_name'] ?? 'N/A') ?>
                                    <?php if ($sale['customer_phone']): ?>
                                        <br><small class="text-muted"><?= escapeHtml($sale['customer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= escapeHtml($sale['branch_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($sale['salesperson_name'] ?? 'N/A') ?></td>
                                <td><?= formatCurrency($sale['total_amount']) ?></td>
                                <td>
                                    <?= escapeHtml($sale['payment_term_name'] ?? 'N/A') ?>
                                    <?php if ($sale['payment_term_days']): ?>
                                        <br><small class="text-muted"><?= $sale['payment_term_days'] ?> days</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <strong class="<?= $isSettled ? 'text-success' : 'text-danger' ?>">
                                        <?= formatCurrency($balance) ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $isSettled ? 'success' : 'warning' ?>">
                                        <?= $isSettled ? 'Settled' : 'Outstanding' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if (!$isSettled && $auth->hasPermission('sales.settle_account')): ?>
                                            <button type="button" class="btn btn-primary" onclick="settleAccount(<?= $sale['id'] ?>, <?= htmlspecialchars(json_encode([
                                                'receipt_number' => $sale['receipt_number'],
                                                'customer_name' => $sale['customer_name'] ?? 'N/A',
                                                'sale_date' => $sale['sale_date'],
                                                'branch_name' => $sale['branch_name'] ?? 'N/A',
                                                'balance' => $balance,
                                                'total_amount' => $sale['total_amount'],
                                                'payment_term_name' => $sale['payment_term_name'] ?? 'N/A'
                                            ]), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="bi bi-cash-coin"></i> Settle
                                            </button>
                                        <?php endif; ?>
                                        <a href="../pos/receipt.php?id=<?= $sale['id'] ?>" class="btn btn-info" target="_blank" title="View Receipt">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Settle Account Modal -->
<div class="modal fade" id="settleAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Settle Account Balance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="settleAccountForm">
                <div class="modal-body">
                    <input type="hidden" id="settleSaleId" name="sale_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Receipt Number</strong></label>
                            <p class="form-control-plaintext" id="settleReceiptNumber"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Sale Date</strong></label>
                            <p class="form-control-plaintext" id="settleSaleDate"></p>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Customer</strong></label>
                            <p class="form-control-plaintext" id="settleCustomerName"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Branch</strong></label>
                            <p class="form-control-plaintext" id="settleBranchName"></p>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Total Amount</strong></label>
                            <p class="form-control-plaintext" id="settleTotalAmount"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Payment Terms</strong></label>
                            <p class="form-control-plaintext" id="settlePaymentTerms"></p>
                        </div>
                    </div>
                    
                    <div class="mb-3 p-3 bg-info bg-opacity-10 border border-info rounded">
                        <strong>Current Balance:</strong> <span id="settleCurrentBalance" class="fw-bold"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method *</label>
                        <select class="form-select" id="settlePaymentMethod" name="payment_method" required>
                            <option value="">Select Payment Method</option>
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="ecocash">EcoCash</option>
                            <option value="onemoney">OneMoney</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Currency *</label>
                        <select class="form-select" id="settleCurrencyId" name="currency_id" required>
                            <?php 
                            $currencies = getActiveCurrencies($db);
                            $baseCurrency = getBaseCurrency($db);
                            foreach ($currencies as $currency): 
                            ?>
                                <option value="<?= $currency['id'] ?>" 
                                        data-exchange-rate="<?= floatval($currency['exchange_rate'] ?? 1.0) ?>"
                                        data-is-base="<?= $currency['is_base'] ? '1' : '0' ?>"
                                        data-symbol="<?= escapeHtml($currency['symbol'] ?? '') ?>"
                                        data-code="<?= escapeHtml($currency['code'] ?? '') ?>"
                                        <?= $currency['is_base'] ? 'selected' : '' ?>>
                                    <?= escapeHtml($currency['code']) ?> - <?= escapeHtml($currency['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="settleExchangeRateInfo"></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Amount *</label>
                        <div class="mb-2 p-2 bg-light border rounded" id="settleExpectedPaymentAmount" style="display: none;">
                            <small class="text-muted d-block mb-1"><strong>Expected Full Payment:</strong></small>
                            <span id="settleExpectedAmountValue" class="fw-bold"></span>
                        </div>
                        <input type="number" step="0.01" class="form-control" id="settlePaymentAmount" name="payment_amount" required min="0.01">
                        <small class="text-muted">Enter the amount to pay in the selected currency (can be partial or full payment)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Comment/Notes</label>
                        <textarea class="form-control" id="settleComment" name="comment" rows="3" placeholder="Optional notes about this payment"></textarea>
                    </div>
                    
                    <div class="mb-3 p-3 bg-warning bg-opacity-10 border border-warning rounded" id="settleRemainingBalance" style="display: none;">
                        <strong>Remaining Balance After Payment:</strong> <span id="settleRemainingAmount" class="fw-bold"></span>
                    </div>
                    <div class="mb-3 p-3 bg-danger bg-opacity-10 border border-danger rounded" id="settleOverpaymentWarning" style="display: none;">
                        <strong><i class="bi bi-exclamation-triangle"></i> Overpayment Warning:</strong> 
                        <span id="settleOverpaymentMessage"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Process Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Store current sale data and base balance for currency conversion
let currentSettleSaleData = null;
let baseCurrencyBalance = 0;
let baseCurrency = <?= json_encode($baseCurrency) ?>;
let baseCurrencySymbol = <?= json_encode($baseCurrency['symbol'] ?? '$') ?>;

function settleAccount(saleId, saleData) {
    currentSettleSaleData = saleData;
    baseCurrencyBalance = parseFloat(saleData.balance) || 0;
    
    document.getElementById('settleSaleId').value = saleId;
    document.getElementById('settleReceiptNumber').textContent = saleData.receipt_number;
    document.getElementById('settleSaleDate').textContent = new Date(saleData.sale_date).toLocaleString();
    document.getElementById('settleCustomerName').textContent = saleData.customer_name;
    document.getElementById('settleBranchName').textContent = saleData.branch_name;
    document.getElementById('settleTotalAmount').textContent = formatCurrency(saleData.total_amount);
    document.getElementById('settlePaymentTerms').textContent = saleData.payment_term_name;
    
    // Reset form fields
    document.getElementById('settlePaymentAmount').value = '';
    document.getElementById('settlePaymentMethod').value = '';
    document.getElementById('settleComment').value = '';
    document.getElementById('settleRemainingBalance').style.display = 'none';
    
    // Set default currency to base currency
    const currencySelect = document.getElementById('settleCurrencyId');
    const baseCurrencyOption = Array.from(currencySelect.options).find(opt => opt.getAttribute('data-is-base') === '1');
    if (baseCurrencyOption) {
        currencySelect.value = baseCurrencyOption.value;
    }
    
    // Update balance display and payment amount max based on selected currency
    updateSettleBalanceDisplay();
    updateSettlePaymentAmountMax();
    updateSettleExpectedPaymentAmount();
    
    const modal = new bootstrap.Modal(document.getElementById('settleAccountModal'));
    modal.show();
}

function updateSettleBalanceDisplay() {
    const currencySelect = document.getElementById('settleCurrencyId');
    const selectedOption = currencySelect.options[currencySelect.selectedIndex];
    
    if (!selectedOption || !currentSettleSaleData) return;
    
    const exchangeRate = parseFloat(selectedOption.getAttribute('data-exchange-rate')) || 1.0;
    const isBase = selectedOption.getAttribute('data-is-base') === '1';
    const symbol = selectedOption.getAttribute('data-symbol') || '';
    const currencyCode = selectedOption.getAttribute('data-code') || '';
    
    // Convert base currency balance to selected currency
    let balanceInSelectedCurrency = baseCurrencyBalance;
    if (!isBase && exchangeRate > 0) {
        balanceInSelectedCurrency = baseCurrencyBalance * exchangeRate;
    }
    
    // Update current balance display
    const balanceElement = document.getElementById('settleCurrentBalance');
    if (isBase) {
        balanceElement.textContent = formatCurrencyAmount(balanceInSelectedCurrency, symbol);
    } else {
        balanceElement.textContent = formatCurrencyAmount(balanceInSelectedCurrency, symbol) + 
            ' (' + formatCurrencyAmount(baseCurrencyBalance, baseCurrencySymbol) + ')';
    }
    
    // Update exchange rate info
    const exchangeRateInfo = document.getElementById('settleExchangeRateInfo');
    if (isBase) {
        exchangeRateInfo.textContent = 'Base Currency (Rate: 1.00)';
        exchangeRateInfo.style.display = 'block';
    } else {
        exchangeRateInfo.textContent = 'Exchange Rate: 1 ' + baseCurrencySymbol + ' = ' + exchangeRate.toFixed(4) + ' ' + currencyCode;
        exchangeRateInfo.style.display = 'block';
    }
    
    // Update expected payment amount display
    updateSettleExpectedPaymentAmount();
    
    // Update remaining balance if payment amount is entered
    updateSettleRemainingBalance();
}

function updateSettleExpectedPaymentAmount() {
    const currencySelect = document.getElementById('settleCurrencyId');
    const selectedOption = currencySelect.options[currencySelect.selectedIndex];
    const expectedPaymentDiv = document.getElementById('settleExpectedPaymentAmount');
    const expectedAmountValue = document.getElementById('settleExpectedAmountValue');
    
    if (!selectedOption || !currentSettleSaleData) {
        expectedPaymentDiv.style.display = 'none';
        return;
    }
    
    const exchangeRate = parseFloat(selectedOption.getAttribute('data-exchange-rate')) || 1.0;
    const isBase = selectedOption.getAttribute('data-is-base') === '1';
    const symbol = selectedOption.getAttribute('data-symbol') || '';
    
    // Convert base currency balance to selected currency
    let expectedAmount = baseCurrencyBalance;
    if (!isBase && exchangeRate > 0) {
        expectedAmount = baseCurrencyBalance * exchangeRate;
    }
    
    // Display expected payment amount
    if (isBase) {
        expectedAmountValue.textContent = formatCurrencyAmount(expectedAmount, symbol);
    } else {
        expectedAmountValue.textContent = formatCurrencyAmount(expectedAmount, symbol) + 
            ' (' + formatCurrencyAmount(baseCurrencyBalance, baseCurrencySymbol) + ')';
    }
    
    expectedPaymentDiv.style.display = 'block';
}

function updateSettlePaymentAmountMax() {
    const currencySelect = document.getElementById('settleCurrencyId');
    const selectedOption = currencySelect.options[currencySelect.selectedIndex];
    const paymentAmountInput = document.getElementById('settlePaymentAmount');
    
    if (!selectedOption || !currentSettleSaleData) return;
    
    const exchangeRate = parseFloat(selectedOption.getAttribute('data-exchange-rate')) || 1.0;
    const isBase = selectedOption.getAttribute('data-is-base') === '1';
    
    // Convert base currency balance to selected currency for max value
    let maxAmount = baseCurrencyBalance;
    if (!isBase && exchangeRate > 0) {
        maxAmount = baseCurrencyBalance * exchangeRate;
    }
    
    paymentAmountInput.max = maxAmount;
}

function updateSettleRemainingBalance() {
    const paymentAmountInput = document.getElementById('settlePaymentAmount');
    const currencySelect = document.getElementById('settleCurrencyId');
    const selectedOption = currencySelect.options[currencySelect.selectedIndex];
    
    if (!selectedOption || !currentSettleSaleData) return;
    
    const paymentAmount = parseFloat(paymentAmountInput.value) || 0;
    const exchangeRate = parseFloat(selectedOption.getAttribute('data-exchange-rate')) || 1.0;
    const isBase = selectedOption.getAttribute('data-is-base') === '1';
    const symbol = selectedOption.getAttribute('data-symbol') || '';
    const currencyCode = selectedOption.getAttribute('data-code') || '';
    
    // Hide warnings initially
    document.getElementById('settleRemainingBalance').style.display = 'none';
    document.getElementById('settleOverpaymentWarning').style.display = 'none';
    
    if (paymentAmount <= 0) {
        return;
    }
    
    // Convert payment amount from selected currency to base currency
    let paymentAmountInBase = paymentAmount;
    if (!isBase && exchangeRate > 0) {
        paymentAmountInBase = paymentAmount / exchangeRate;
    }
    
    // Check for overpayment
    if (paymentAmountInBase > baseCurrencyBalance) {
        // Show overpayment warning
        const overpaymentWarning = document.getElementById('settleOverpaymentWarning');
        const overpaymentMessage = document.getElementById('settleOverpaymentMessage');
        const overpaymentAmount = paymentAmountInBase - baseCurrencyBalance;
        const overpaymentInSelected = isBase ? overpaymentAmount : overpaymentAmount * exchangeRate;
        
        overpaymentMessage.textContent = 'Payment amount exceeds balance by ' + 
            formatCurrencyAmount(overpaymentInSelected, symbol) + 
            ' (' + formatCurrencyAmount(overpaymentAmount, baseCurrencySymbol) + ')';
        overpaymentWarning.style.display = 'block';
        
        // Disable submit button
        const submitBtn = document.querySelector('#settleAccountForm button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
        }
        
        // Set max value and adjust input
        paymentAmountInput.setCustomValidity('Payment amount cannot exceed the balance');
        return;
    } else {
        // Clear overpayment warning and enable submit
        paymentAmountInput.setCustomValidity('');
        const submitBtn = document.querySelector('#settleAccountForm button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        }
    }
    
    // Calculate remaining balance in base currency
    const remainingInBase = baseCurrencyBalance - paymentAmountInBase;
    
    // Convert remaining balance back to selected currency for display
    let remainingInSelectedCurrency = remainingInBase;
    if (!isBase && exchangeRate > 0) {
        remainingInSelectedCurrency = remainingInBase * exchangeRate;
    }
    
    const remainingDiv = document.getElementById('settleRemainingBalance');
    const remainingAmount = document.getElementById('settleRemainingAmount');
    
    if (paymentAmount > 0 && paymentAmountInBase <= baseCurrencyBalance) {
        remainingDiv.style.display = 'block';
        
        // Display in selected currency and base currency (in brackets)
        if (isBase) {
            remainingAmount.textContent = formatCurrencyAmount(Math.max(0, remainingInSelectedCurrency), symbol);
        } else {
            remainingAmount.textContent = formatCurrencyAmount(Math.max(0, remainingInSelectedCurrency), symbol) + 
                ' (' + formatCurrencyAmount(Math.max(0, remainingInBase), baseCurrencySymbol) + ')';
        }
        
        remainingAmount.className = remainingInBase > 0 ? 'fw-bold text-warning' : 'fw-bold text-success';
    }
}

function formatCurrencyAmount(amount, symbol) {
    const formatted = parseFloat(amount).toFixed(2);
    return (symbol || '$') + formatted;
}

// Event listeners for currency and payment amount changes
document.addEventListener('DOMContentLoaded', function() {
    const currencySelect = document.getElementById('settleCurrencyId');
    const paymentAmountInput = document.getElementById('settlePaymentAmount');
    
    if (currencySelect) {
        currencySelect.addEventListener('change', function() {
            updateSettleBalanceDisplay();
            updateSettlePaymentAmountMax();
            updateSettleExpectedPaymentAmount();
            // Clear payment amount when currency changes
            paymentAmountInput.value = '';
            updateSettleRemainingBalance();
        });
    }
    
    if (paymentAmountInput) {
        paymentAmountInput.addEventListener('input', function() {
            updateSettleRemainingBalance();
        });
    }
});

document.getElementById('settleAccountForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const paymentAmountInput = document.getElementById('settlePaymentAmount');
    const currencySelect = document.getElementById('settleCurrencyId');
    const selectedOption = currencySelect.options[currencySelect.selectedIndex];
    const paymentAmount = parseFloat(paymentAmountInput.value) || 0;
    
    // Validate payment amount
    if (paymentAmount <= 0) {
        Swal.fire('Error', 'Payment amount must be greater than 0', 'error');
        return;
    }
    
    // Check for overpayment
    if (selectedOption && currentSettleSaleData) {
        const exchangeRate = parseFloat(selectedOption.getAttribute('data-exchange-rate')) || 1.0;
        const isBase = selectedOption.getAttribute('data-is-base') === '1';
        
        // Convert payment amount from selected currency to base currency
        let paymentAmountInBase = paymentAmount;
        if (!isBase && exchangeRate > 0) {
            paymentAmountInBase = paymentAmount / exchangeRate;
        }
        
        if (paymentAmountInBase > baseCurrencyBalance) {
            Swal.fire('Error', 'Payment amount cannot exceed the current balance', 'error');
            return;
        }
    }
    
    const formData = {
        sale_id: document.getElementById('settleSaleId').value,
        payment_amount: paymentAmount,
        payment_method: document.getElementById('settlePaymentMethod').value,
        currency_id: parseInt(document.getElementById('settleCurrencyId').value),
        comment: document.getElementById('settleComment').value
    };
    
    Swal.fire({
        title: 'Processing Payment...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/settle_account.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(formData)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Account balance settled successfully',
                confirmButtonColor: '#1e3a8a'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message || 'Failed to settle account balance',
                confirmButtonColor: '#d33'
            });
        }
    })
    .catch(error => {
        console.error('Settlement error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'An error occurred while processing the payment',
            confirmButtonColor: '#d33'
        });
    });
});

function formatCurrency(amount) {
    return '<?= getBaseCurrency($db) ? getBaseCurrency($db)['symbol'] : '$' ?>' + parseFloat(amount).toFixed(2);
}

$(document).ready(function() {
    if ($.fn.DataTable && $('#creditSalesTable').length) {
        var receiptNumberFilter = <?= !empty($receiptNumberFilter) ? json_encode($receiptNumberFilter) : '""' ?>;
        $('#creditSalesTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            // Pre-fill search box if receipt number filter is applied
            search: {
                search: receiptNumberFilter
            }
        });
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

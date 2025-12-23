<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
// This page matches sidebar "Cash Management" menu item
if (!$auth->hasPermission('pos.cash_management') && !$auth->hasPermission('drawer.transaction') && !$auth->hasPermission('drawer.report')) {
    $auth->requirePermission('pos.cash_management'); // This will show access denied
}

$pageTitle = 'Cash Management';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'];

// Get current shift
$currentShift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
    ':branch_id' => $branchId,
    ':user_id' => $userId
]);

if (!$currentShift) {
    redirectTo('modules/pos/index.php');
}

// Get base currency
$baseCurrency = getBaseCurrency($db);
$baseCurrencyId = $baseCurrency ? $baseCurrency['id'] : null;

// Calculate shift statistics (using base amounts for cash)
$cashSales = $db->getRow("SELECT COALESCE(SUM(COALESCE(sp.base_amount, sp.amount)), 0) as total 
                          FROM sale_payments sp 
                          INNER JOIN sales s ON sp.sale_id = s.id 
                          WHERE s.shift_id = :shift_id AND LOWER(sp.payment_method) = 'cash'", 
                          [':shift_id' => $currentShift['id']]);
if ($cashSales === false) {
    $cashSales = ['total' => 0];
}

// Get currency-wise cash breakdown
$currencies = getActiveCurrencies($db);
$currencyCashBreakdown = [];
foreach ($currencies as $currency) {
    $currencyCash = $db->getRow("SELECT 
                                      COALESCE(SUM(sp.base_amount), SUM(sp.amount), 0) as total_base,
                                      COALESCE(SUM(sp.original_amount), SUM(sp.amount), 0) as total_original
                                   FROM sale_payments sp 
                                   INNER JOIN sales s ON sp.sale_id = s.id 
                                   WHERE s.shift_id = :shift_id 
                                     AND LOWER(sp.payment_method) = 'cash'
                                     AND COALESCE(sp.currency_id, :base_currency_id) = :currency_id", 
                                   [':shift_id' => $currentShift['id'],
                                    ':currency_id' => $currency['id'],
                                    ':base_currency_id' => $baseCurrencyId]);
    if ($currencyCash && ($currencyCash['total_base'] > 0 || $currencyCash['total_original'] > 0)) {
        $currencyCashBreakdown[$currency['id']] = [
            'currency' => $currency,
            'total_base' => floatval($currencyCash['total_base']),
            'total_original' => floatval($currencyCash['total_original'])
        ];
    }
}

// Calculate cash refunds from refund_payments table
$cashRefunds = $db->getRow("SELECT COALESCE(SUM(rp.amount), 0) as total 
                            FROM refund_payments rp
                            INNER JOIN refunds r ON rp.refund_id = r.id
                            WHERE r.shift_id = :shift_id AND LOWER(rp.payment_method) = 'cash'", 
                            [':shift_id' => $currentShift['id']]);
if ($cashRefunds === false) {
    $cashRefunds = ['total' => 0];
}

$payIns = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM drawer_transactions 
                       WHERE shift_id = :shift_id AND transaction_type = 'pay_in'", 
                       [':shift_id' => $currentShift['id']]);
if ($payIns === false) {
    $payIns = ['total' => 0];
}

$payOuts = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM drawer_transactions 
                        WHERE shift_id = :shift_id AND transaction_type = 'pay_out'", 
                        [':shift_id' => $currentShift['id']]);
if ($payOuts === false) {
    $payOuts = ['total' => 0];
}

// Calculate expected cash: starting + cash sales + pay ins - pay outs - cash refunds
$expectedCash = $currentShift['starting_cash'] + $cashSales['total'] + $payIns['total'] - $payOuts['total'] - $cashRefunds['total'];

// Get drawer transactions
$transactions = $db->getRows("SELECT dt.*, u.first_name, u.last_name FROM drawer_transactions dt 
                              LEFT JOIN users u ON dt.user_id = u.id 
                              WHERE dt.shift_id = :shift_id 
                              ORDER BY dt.created_at DESC", 
                              [':shift_id' => $currentShift['id']]);

require_once APP_PATH . '/includes/header.php';
?>

<style>
/* ========== MOBILE RESPONSIVE STYLES ========== */

/* Tablet and below (max-width: 1024px) */
@media (max-width: 1024px) {
    .row {
        margin-left: -10px;
        margin-right: -10px;
    }
    
    .row > [class*="col-"] {
        padding-left: 10px;
        padding-right: 10px;
    }
}

/* Mobile (max-width: 768px) */
@media (max-width: 768px) {
    .d-flex.justify-content-between {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start !important;
    }
    
    .d-flex.justify-content-between > div {
        width: 100%;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .d-flex.justify-content-between .btn {
        width: 100%;
        justify-content: center;
    }
    
    .row {
        margin-left: 0;
        margin-right: 0;
    }
    
    .row > [class*="col-"] {
        padding-left: 0;
        padding-right: 0;
        margin-bottom: 15px;
    }
    
    .card {
        border-radius: 0;
        margin-bottom: 15px;
    }
    
    .table {
        font-size: 14px;
    }
    
    .table th,
    .table td {
        padding: 8px 4px;
    }
    
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    h2 {
        font-size: 24px;
    }
    
    h5 {
        font-size: 18px;
    }
}

/* Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .d-flex.justify-content-between {
        margin-bottom: 20px;
    }
    
    h2 {
        font-size: 20px;
    }
    
    .table {
        font-size: 12px;
    }
    
    .table th,
    .table td {
        padding: 6px 2px;
    }
    
    .card-body {
        padding: 15px;
    }
    
    .card-header {
        padding: 12px 15px;
    }
    
    .btn {
        font-size: 14px;
        padding: 10px 15px;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Cash Management</h2>
    <div>
        <?php if ($auth->hasPermission('drawer.transaction')): ?>
        <button class="btn btn-primary" onclick="showDrawerTransaction()">
            <i class="bi bi-cash-coin"></i> Drawer Transaction
        </button>
        <?php endif; ?>
        <?php if ($auth->hasPermission('drawer.view')): ?>
        <button class="btn btn-info" onclick="showDrawerReport()">
            <i class="bi bi-file-earmark-text"></i> Drawer Report
        </button>
        <?php endif; ?>
        <button class="btn btn-danger" onclick="showShiftEnd()">
            <i class="bi bi-stop-circle"></i> End Shift
        </button>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Shift Details</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="50%">Shift number:</th>
                        <td><?= $currentShift['shift_number'] ?></td>
                    </tr>
                    <tr>
                        <th>Shift opened by:</th>
                        <td><?= escapeHtml($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Opened at:</th>
                        <td><?= formatDateTime($currentShift['opened_at']) ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="badge bg-success">Open</span></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Cash Drawer</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="50%">Starting cash amount:</th>
                        <td><?= formatCurrency($currentShift['starting_cash']) ?></td>
                    </tr>
                    <tr>
                        <th>Cash sale:</th>
                        <td><?= formatCurrency($cashSales['total']) ?></td>
                    </tr>
                    <tr>
                        <th>Cash refund:</th>
                        <td><?= formatCurrency($cashRefunds['total']) ?></td>
                    </tr>
                    <tr>
                        <th>Paid in:</th>
                        <td><?= formatCurrency($payIns['total']) ?></td>
                    </tr>
                    <tr>
                        <th>Paid out:</th>
                        <td><?= formatCurrency($payOuts['total']) ?></td>
                    </tr>
                    <tr class="border-top">
                        <th><strong>Expected cash amount:</strong></th>
                        <td><strong><?= formatCurrency($expectedCash) ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Drawer Transactions</h5>
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Reason</th>
                    <th>User</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No transactions found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $txn): ?>
                        <tr>
                            <td><?= formatDateTime($txn['created_at']) ?></td>
                            <td>
                                <span class="badge bg-<?= $txn['transaction_type'] == 'pay_in' ? 'success' : 'warning' ?>">
                                    <?= strtoupper(str_replace('_', ' ', $txn['transaction_type'])) ?>
                                </span>
                            </td>
                            <td><?= formatCurrency($txn['amount']) ?></td>
                            <td><?= escapeHtml($txn['reason'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml(($txn['first_name'] ?? '') . ' ' . ($txn['last_name'] ?? '')) ?></td>
                            <td><?= escapeHtml($txn['notes'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Drawer Transaction Modal -->
<div class="modal fade" id="drawerTransactionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Drawer Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label>Available cash amount:</label>
                    <div class="fs-4 fw-bold text-primary"><?= formatCurrency($expectedCash) ?></div>
                </div>
                <div class="mb-3">
                    <label>Transaction Type *</label>
                    <select class="form-select form-select-lg" id="transactionType" onchange="updateTransactionFields()">
                        <option value="">Select transaction type...</option>
                        <option value="pay_in">Pay In</option>
                        <option value="pay_out">Pay Out</option>
                    </select>
                </div>
                <div class="mb-3" id="amountField">
                    <label>Amount *</label>
                    <input type="text" class="form-control form-control-lg" id="transactionAmount" placeholder="Amount" value="0.00" oninput="validateTransactionForm()">
                </div>
                <div class="mb-3" id="reasonField" style="display: none;">
                    <label>Reason *</label>
                    <select class="form-select" id="transactionReason" onchange="validateTransactionForm()">
                        <option value="">Select a reason...</option>
                    </select>
                </div>
                <div class="mb-3" id="notesField" style="display: none;">
                    <label>Notes</label>
                    <textarea class="form-control" id="transactionNotes" rows="3" placeholder="Notes"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-info" onclick="showDrawerReport()">
                    <i class="bi bi-file-text"></i> View Report
                </button>
                <button type="button" class="btn btn-primary" id="processTransactionBtn" onclick="processDrawerTransaction()" disabled>
                    <i class="bi bi-check-circle"></i> Process Transaction
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Drawer Report Modal -->
<div class="modal fade" id="drawerReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Drawer Transaction Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Balance</th>
                            </tr>
                        </thead>
                        <tbody id="drawerReportBody">
                            <tr>
                                <td colspan="4" class="text-center text-muted">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printDrawerReport()">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Shift End Modal -->
<div class="modal fade" id="shiftEndModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Shift End</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label>Shift end module</label>
                    <input type="text" class="form-control" value="Standard" readonly>
                </div>
                <div class="mb-3">
                    <label>Expected cash amount</label>
                    <input type="text" class="form-control form-control-lg" id="expectedCash" value="<?= number_format($expectedCash, 2) ?>" readonly>
                </div>
                <div class="mb-3">
                    <label>Actual cash amount</label>
                    <input type="text" class="form-control form-control-lg" id="actualCash" placeholder="Amount" value="0.00">
                </div>
                <div class="mb-3">
                    <label>Difference</label>
                    <input type="text" class="form-control form-control-lg" id="cashDifference" value="0.00" readonly>
                </div>
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="printReport" checked>
                        <label class="form-check-label" for="printReport">Print Report</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary w-100" onclick="processShiftEnd()">
                    RUN SHIFT END PROCESS <?= date('Y-m-d') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Make functions available globally immediately
(function() {
    'use strict';
    
    // Define functions immediately
    window.showDrawerTransaction = function() {
        const modal = document.getElementById('drawerTransactionModal');
        if (modal) {
            // Reset form when opening
            document.getElementById('transactionType').value = '';
            document.getElementById('transactionAmount').value = '0.00';
            document.getElementById('transactionReason').innerHTML = '<option value="">Select a reason...</option>';
            document.getElementById('transactionNotes').value = '';
            document.getElementById('reasonField').style.display = 'none';
            document.getElementById('notesField').style.display = 'none';
            document.getElementById('processTransactionBtn').disabled = true;
            new bootstrap.Modal(modal).show();
        } else {
            console.error('drawerTransactionModal not found');
        }
    };
    
    window.showShiftEnd = function() {
        const modal = document.getElementById('shiftEndModal');
        if (modal) {
            new bootstrap.Modal(modal).show();
        } else {
            console.error('shiftEndModal not found');
        }
    };
})();

function showDrawerReport() {
    // Load drawer transactions
    fetch('<?= BASE_URL ?>ajax/get_drawer_report.php?shift_id=<?= isset($currentShift['id']) ? intval($currentShift['id']) : 0 ?>', {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const tbody = document.getElementById('drawerReportBody');
            tbody.innerHTML = '';
            
            // Add opening balance
            tbody.innerHTML += `
                <tr>
                    <td><?= date('H:i', strtotime($currentShift['opened_at'])) ?></td>
                    <td>Open Balance</td>
                    <td class="text-success">+<?= number_format($currentShift['starting_cash'], 2) ?></td>
                    <td><?= number_format($currentShift['starting_cash'], 2) ?></td>
                </tr>
            `;
            
            let runningBalance = parseFloat(<?= isset($currentShift['starting_cash']) ? floatval($currentShift['starting_cash']) : 0 ?>);
            
            data.transactions.forEach(txn => {
                const amount = parseFloat(txn.amount);
                if (txn.transaction_type === 'pay_in') {
                    runningBalance += amount;
                    tbody.innerHTML += `
                        <tr>
                            <td>${new Date(txn.created_at).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}</td>
                            <td>${txn.reason || 'Pay In'}</td>
                            <td class="text-success">+${parseFloat(amount).toFixed(2)}</td>
                            <td>${runningBalance.toFixed(2)}</td>
                        </tr>
                    `;
                } else {
                    runningBalance -= amount;
                    tbody.innerHTML += `
                        <tr>
                            <td>${new Date(txn.created_at).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}</td>
                            <td>${txn.reason || 'Pay Out'}</td>
                            <td class="text-danger">-${parseFloat(amount).toFixed(2)}</td>
                            <td>${runningBalance.toFixed(2)}</td>
                        </tr>
                    `;
                }
            });
            
            new bootstrap.Modal(document.getElementById('drawerReportModal')).show();
        } else {
            Swal.fire('Error', data.message || 'Failed to load drawer report', 'error');
        }
    })
    .catch(error => {
        console.error('Drawer report error:', error);
        Swal.fire('Error', 'Failed to load drawer report: ' + error.message, 'error');
    });
}

function printDrawerReport() {
    window.print();
}

function updateTransactionFields() {
    const transactionType = document.getElementById('transactionType').value;
    const reasonField = document.getElementById('reasonField');
    const notesField = document.getElementById('notesField');
    const reasonSelect = document.getElementById('transactionReason');
    const processBtn = document.getElementById('processTransactionBtn');
    
    if (!transactionType) {
        reasonField.style.display = 'none';
        notesField.style.display = 'none';
        processBtn.disabled = true;
        return;
    }
    
    // Show fields
    reasonField.style.display = 'block';
    notesField.style.display = 'block';
    
    // Clear and populate reason options based on type
    reasonSelect.innerHTML = '<option value="">Select a reason...</option>';
    
    if (transactionType === 'pay_in') {
        // Pay In reasons
        const payInReasons = [
            'Bank Deposit',
            'Petty Cash',
            'Loan Repayment',
            'Other Income',
            'Other'
        ];
        payInReasons.forEach(reason => {
            const option = document.createElement('option');
            option.value = reason;
            option.textContent = reason;
            reasonSelect.appendChild(option);
        });
    } else if (transactionType === 'pay_out') {
        // Pay Out reasons
        const payOutReasons = [
            'Expense',
            'Bank Withdrawal',
            'Loan',
            'Petty Cash',
            'Other'
        ];
        payOutReasons.forEach(reason => {
            const option = document.createElement('option');
            option.value = reason;
            option.textContent = reason;
            reasonSelect.appendChild(option);
        });
    }
    
    // Validate form
    validateTransactionForm();
}

function validateTransactionForm() {
    const transactionType = document.getElementById('transactionType').value;
    const amount = parseFloat(document.getElementById('transactionAmount').value) || 0;
    const reason = document.getElementById('transactionReason').value;
    const processBtn = document.getElementById('processTransactionBtn');
    
    if (transactionType && amount > 0 && reason) {
        processBtn.disabled = false;
    } else {
        processBtn.disabled = true;
    }
}

function processDrawerTransaction() {
    const transactionType = document.getElementById('transactionType').value;
    const amount = parseFloat(document.getElementById('transactionAmount').value) || 0;
    const reason = document.getElementById('transactionReason').value;
    const notes = document.getElementById('transactionNotes').value;
    
    if (!transactionType) {
        Swal.fire('Error', 'Please select a transaction type', 'error');
        return;
    }
    
    if (amount <= 0) {
        Swal.fire('Error', 'Please enter a valid amount', 'error');
        return;
    }
    
    if (!reason) {
        Swal.fire('Error', 'Please select a reason', 'error');
        return;
    }
    
    const shiftId = <?= isset($currentShift['id']) ? intval($currentShift['id']) : 0 ?>;
    
    Swal.fire({
        title: 'Processing...',
        text: 'Please wait',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/drawer_transaction.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
            shift_id: shiftId,
            transaction_type: transactionType,
            amount: amount,
            reason: reason,
            notes: notes
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Transaction processed successfully', 'success').then(() => {
                bootstrap.Modal.getInstance(document.getElementById('drawerTransactionModal')).hide();
                window.location.reload();
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to process transaction', 'error');
        }
    })
    .catch(error => {
        console.error('Drawer transaction error:', error);
        Swal.fire('Error', 'Failed to record transaction: ' + error.message, 'error');
    });
}

document.getElementById('actualCash').addEventListener('input', function() {
    const expected = parseFloat(document.getElementById('expectedCash').value.replace(/,/g, '')) || 0;
    const actual = parseFloat(this.value) || 0;
    const difference = actual - expected;
    document.getElementById('cashDifference').value = difference.toFixed(2);
});

function processShiftEnd() {
    const actualCash = parseFloat(document.getElementById('actualCash').value) || 0;
    const printReport = document.getElementById('printReport').checked;
    
    Swal.fire({
        title: 'End Shift?',
        text: 'This will close the current shift',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, End Shift',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const shiftId = <?= isset($currentShift['id']) ? intval($currentShift['id']) : 0 ?>;
            
            fetch('<?= BASE_URL ?>ajax/end_shift.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    shift_id: shiftId,
                    actual_cash: actualCash,
                    print_report: printReport
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (printReport && data.report_url) {
                        // Redirect to shift report page instead of opening in new window
                        window.location.href = data.report_url;
                    } else {
                        Swal.fire({
                            title: 'Success',
                            text: 'Shift ended successfully',
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            window.location.href = '<?= BASE_URL ?>modules/pos/start_shift.php';
                        });
                    }
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('End shift error:', error);
                Swal.fire('Error', 'Failed to end shift: ' + error.message, 'error');
            });
        }
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


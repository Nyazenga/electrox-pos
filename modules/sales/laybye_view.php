<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('sales.laybyes');

$laybyeId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = $_GET['action'] ?? 'view';

if (!$laybyeId) {
    redirectTo('laybyes.php');
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

// Get laybye
$laybye = $primaryDb->getRow("SELECT l.*, 
    CONCAT(c.first_name, ' ', c.last_name) as customer_name,
    c.phone as customer_phone,
    c.email as customer_email,
    b.branch_name,
    CONCAT(u.first_name, ' ', u.last_name) as created_by_name
    FROM laybyes l
    LEFT JOIN customers c ON l.customer_id = c.id
    LEFT JOIN branches b ON l.branch_id = b.id
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.id = :id", [':id' => $laybyeId]);

if (!$laybye) {
    redirectTo('laybyes.php');
}

// Get laybye items
$laybyeItems = $primaryDb->getRows("SELECT * FROM laybye_items WHERE laybye_id = :id", [':id' => $laybyeId]);
if ($laybyeItems === false) $laybyeItems = [];

// Get laybye payments
$laybyePayments = $primaryDb->getRows("SELECT lp.*, 
    CONCAT(u.first_name, ' ', u.last_name) as user_name
    FROM laybye_payments lp
    LEFT JOIN users u ON lp.user_id = u.id
    WHERE lp.laybye_id = :id
    ORDER BY lp.payment_date DESC", [':id' => $laybyeId]);
if ($laybyePayments === false) $laybyePayments = [];

// Get payment schedule
$paymentSchedule = [];
$monthlyAmount = null;
if ($laybye['payment_schedule_type'] === 'monthly') {
    $paymentSchedule = $primaryDb->getRows("SELECT * FROM laybye_payment_schedule 
        WHERE laybye_id = :id 
        ORDER BY scheduled_date ASC", [':id' => $laybyeId]);
    if ($paymentSchedule === false) $paymentSchedule = [];
    
    // Get monthly amount from schedule data
    $scheduleData = json_decode($laybye['payment_schedule_data'], true);
    if ($scheduleData && $scheduleData['type'] === 'monthly' && isset($scheduleData['monthly_amount'])) {
        $monthlyAmount = floatval($scheduleData['monthly_amount']);
    }
    
    // If not in schedule data, calculate from first unpaid schedule entry
    if ($monthlyAmount === null && !empty($paymentSchedule)) {
        foreach ($paymentSchedule as $entry) {
            if (!$entry['is_paid']) {
                $monthlyAmount = floatval($entry['scheduled_amount']);
                break;
            }
        }
    }
}

// Get base currency
$baseCurrency = getBaseCurrency($db);
$currencies = getActiveCurrencies($db);

$pageTitle = 'Laybye: ' . $laybye['laybye_number'];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-bag-check"></i> Laybye: <?= escapeHtml($laybye['laybye_number']) ?></h2>
    <a href="laybyes.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back to Laybyes
    </a>
</div>

<div class="row">
    <div class="col-md-8">
        <!-- Laybye Details -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Laybye Details</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Customer:</strong> <?= escapeHtml($laybye['customer_name'] ?? 'N/A') ?><br>
                        <strong>Phone:</strong> <?= escapeHtml($laybye['customer_phone'] ?? 'N/A') ?><br>
                        <strong>Email:</strong> <?= escapeHtml($laybye['customer_email'] ?? 'N/A') ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Branch:</strong> <?= escapeHtml($laybye['branch_name'] ?? 'N/A') ?><br>
                        <strong>Created By:</strong> <?= escapeHtml($laybye['created_by_name'] ?? 'N/A') ?><br>
                        <strong>Created:</strong> <?= date('M d, Y H:i', strtotime($laybye['created_at'])) ?>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-2">Total Amount</h6>
                                <h4><?= formatCurrency($laybye['total_amount']) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h6 class="mb-2">Amount Paid</h6>
                                <h4><?= formatCurrency($laybye['amount_paid']) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-danger text-white">
                            <div class="card-body text-center">
                                <h6 class="mb-2">Amount Remaining</h6>
                                <h4><?= formatCurrency($laybye['amount_remaining']) ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <strong>Status:</strong> 
                    <span class="badge bg-<?= $laybye['status'] === 'completed' ? 'success' : ($laybye['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                        <?= ucfirst(str_replace('_', ' ', $laybye['status'])) ?>
                    </span>
                </div>
                
                <?php if ($laybye['notes']): ?>
                    <div class="mb-3">
                        <strong>Notes:</strong><br>
                        <?= nl2br(escapeHtml($laybye['notes'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Items</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($laybyeItems as $item): ?>
                                <tr>
                                    <td><?= escapeHtml($item['product_name']) ?></td>
                                    <td><?= $item['quantity'] ?></td>
                                    <td><?= formatCurrency($item['unit_price']) ?></td>
                                    <td><?= formatCurrency($item['total_price']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Payment Schedule -->
        <?php if (!empty($paymentSchedule)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Payment Schedule</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Due Date</th>
                                <th>Scheduled Amount</th>
                                <th>Paid Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentSchedule as $schedule): ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($schedule['scheduled_date'])) ?></td>
                                    <td><?= formatCurrency($schedule['scheduled_amount']) ?></td>
                                    <td><?= formatCurrency($schedule['paid_amount']) ?></td>
                                    <td>
                                        <?php if ($schedule['is_paid']): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Payment History</h5>
            </div>
            <div class="card-body">
                <?php if (empty($laybyePayments)): ?>
                    <p class="text-muted">No payments recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Processed By</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($laybyePayments as $payment): ?>
                                    <tr>
                                        <td><?= date('M d, Y H:i', strtotime($payment['payment_date'])) ?></td>
                                        <td><?= formatCurrency($payment['payment_amount']) ?></td>
                                        <td><?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?></td>
                                        <td><?= escapeHtml($payment['user_name'] ?? 'N/A') ?></td>
                                        <td><?= escapeHtml($payment['notes'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <?php if ($laybye['status'] !== 'completed' && $laybye['status'] !== 'cancelled'): ?>
                    <?php if ($auth->hasPermission('sales.laybyes.add_payment')): ?>
                        <button type="button" class="btn btn-success w-100 mb-2" onclick="showAddPaymentModal()">
                            <i class="bi bi-cash-coin"></i> Add Payment
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($laybye['amount_remaining'] <= 0.01 && $auth->hasPermission('sales.laybyes.complete')): ?>
                        <button type="button" class="btn btn-primary w-100 mb-2" onclick="completeLaybye()">
                            <i class="bi bi-check-circle"></i> Complete Laybye
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($auth->hasPermission('sales.laybyes.cancel')): ?>
                        <button type="button" class="btn btn-danger w-100" onclick="cancelLaybye()">
                            <i class="bi bi-x-circle"></i> Cancel Laybye
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">This laybye is <?= $laybye['status'] ?>.</p>
                    <?php if ($laybye['status'] === 'completed' && $laybye['sale_id']): ?>
                        <a href="<?= BASE_URL ?>modules/pos/receipt.php?id=<?= $laybye['sale_id'] ?>" class="btn btn-info w-100" target="_blank">
                            <i class="bi bi-receipt"></i> View Receipt
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Schedule Info -->
        <?php if ($laybye['next_payment_date']): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Next Payment</h5>
            </div>
            <div class="card-body">
                <p><strong>Due Date:</strong><br><?= date('M d, Y', strtotime($laybye['next_payment_date'])) ?></p>
                <?php
                $scheduleData = json_decode($laybye['payment_schedule_data'], true);
                if ($scheduleData && $scheduleData['type'] === 'monthly' && isset($scheduleData['monthly_amount'])): ?>
                    <p><strong>Amount:</strong><br><?= formatCurrency($scheduleData['monthly_amount']) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Payment Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addPaymentForm">
                <div class="modal-body">
                    <input type="hidden" id="laybye_id" value="<?= $laybyeId ?>">
                    <input type="hidden" id="payment_type" value="custom">
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Type *</label>
                        <div class="btn-group w-100" role="group">
                            <?php if ($monthlyAmount !== null): ?>
                                <input type="radio" class="btn-check" name="payment_type_radio" id="payment_type_monthly" value="monthly" autocomplete="off" checked>
                                <label class="btn btn-outline-primary" for="payment_type_monthly">
                                    Pay Monthly Amount<br>
                                    <small><?= formatCurrency($monthlyAmount) ?></small>
                                </label>
                            <?php endif; ?>
                            
                            <input type="radio" class="btn-check" name="payment_type_radio" id="payment_type_full" value="full" autocomplete="off" <?= $monthlyAmount === null ? 'checked' : '' ?>>
                            <label class="btn btn-outline-success" for="payment_type_full">
                                Pay Full Remaining<br>
                                <small><?= formatCurrency($laybye['amount_remaining']) ?></small>
                            </label>
                            
                            <input type="radio" class="btn-check" name="payment_type_radio" id="payment_type_custom" value="custom" autocomplete="off" <?= $monthlyAmount === null ? '' : '' ?>>
                            <label class="btn btn-outline-info" for="payment_type_custom">
                                Pay Custom Amount
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="custom_amount_container" style="display: <?= $monthlyAmount === null ? 'block' : 'none' ?>;">
                        <label class="form-label">Payment Amount *</label>
                        <input type="number" step="0.01" class="form-control" id="payment_amount" min="0.01" max="<?= $laybye['amount_remaining'] ?>" value="<?= $monthlyAmount !== null ? $monthlyAmount : $laybye['amount_remaining'] ?>">
                        <small class="text-muted">Remaining: <?= formatCurrency($laybye['amount_remaining']) ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method *</label>
                        <select class="form-select" id="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Currency</label>
                        <select class="form-select" id="payment_currency">
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?= $currency['id'] ?>" <?= $currency['is_base'] ? 'selected' : '' ?>>
                                    <?= escapeHtml($currency['code']) ?> (<?= escapeHtml($currency['symbol']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="payment_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const monthlyAmount = <?= $monthlyAmount !== null ? $monthlyAmount : 'null' ?>;
const fullRemainingAmount = <?= $laybye['amount_remaining'] ?>;

function showAddPaymentModal() {
    const modal = new bootstrap.Modal(document.getElementById('addPaymentModal'));
    modal.show();
    
    // Reset form when modal opens
    document.getElementById('payment_type').value = monthlyAmount !== null ? 'monthly' : 'full';
    if (monthlyAmount !== null) {
        document.getElementById('payment_type_monthly').checked = true;
    } else {
        document.getElementById('payment_type_full').checked = true;
    }
    updatePaymentAmount();
}

function updatePaymentAmount() {
    const paymentType = document.querySelector('input[name="payment_type_radio"]:checked')?.value || 'custom';
    const customContainer = document.getElementById('custom_amount_container');
    const amountInput = document.getElementById('payment_amount');
    
    document.getElementById('payment_type').value = paymentType;
    
    if (paymentType === 'monthly' && monthlyAmount !== null) {
        amountInput.value = monthlyAmount;
        customContainer.style.display = 'none';
        amountInput.removeAttribute('required');
    } else if (paymentType === 'full') {
        amountInput.value = fullRemainingAmount;
        customContainer.style.display = 'none';
        amountInput.removeAttribute('required');
    } else {
        customContainer.style.display = 'block';
        amountInput.setAttribute('required', 'required');
        if (!amountInput.value || amountInput.value <= 0) {
            amountInput.value = monthlyAmount !== null ? monthlyAmount : fullRemainingAmount;
        }
    }
}

// Add event listeners to payment type radio buttons
document.querySelectorAll('input[name="payment_type_radio"]').forEach(radio => {
    radio.addEventListener('change', updatePaymentAmount);
});

document.getElementById('addPaymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const paymentType = document.getElementById('payment_type').value;
    let paymentAmount;
    
    if (paymentType === 'monthly' && monthlyAmount !== null) {
        paymentAmount = monthlyAmount;
    } else if (paymentType === 'full') {
        paymentAmount = fullRemainingAmount;
    } else {
        paymentAmount = parseFloat(document.getElementById('payment_amount').value);
        if (!paymentAmount || paymentAmount <= 0) {
            Swal.fire('Error', 'Please enter a valid payment amount', 'error');
            return;
        }
        if (paymentAmount > fullRemainingAmount) {
            Swal.fire('Error', 'Payment amount cannot exceed remaining balance', 'error');
            return;
        }
    }
    
    const formData = {
        laybye_id: <?= $laybyeId ?>,
        payment_amount: paymentAmount,
        payment_method: document.getElementById('payment_method').value,
        currency_id: parseInt(document.getElementById('payment_currency').value),
        notes: document.getElementById('payment_notes').value
    };
    
    Swal.fire({
        title: 'Processing Payment...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/add_laybye_payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(formData)
    })
    .then(async response => {
        const text = await response.text();
        console.log('Raw response:', text);
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Response text:', text);
            throw new Error('Invalid JSON response: ' + text.substring(0, 200));
        }
    })
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Success!',
                text: 'Payment added successfully',
                icon: 'success',
                confirmButtonText: 'OK'
            }).then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire({
                title: 'Error',
                text: data.message || 'Failed to add payment',
                icon: 'error',
                width: '600px'
            });
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        Swal.fire({
            title: 'Error',
            text: 'An error occurred while adding payment: ' + error.message,
            icon: 'error',
            width: '600px'
        });
    });
});

function completeLaybye() {
    Swal.fire({
        title: 'Complete Laybye?',
        text: 'This will create a sale and allow you to select specific product items. Continue?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Complete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Preparing Laybye...',
                text: 'Redirecting to POS...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('<?= BASE_URL ?>ajax/complete_laybye.php?id=<?= $laybyeId ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Redirect to POS payment page to select specific items and process sale
                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                        } else {
                            window.location.href = '<?= BASE_URL ?>modules/pos/payment.php?from_laybye=1&laybye_id=<?= $laybyeId ?>';
                        }
                    } else {
                        Swal.fire('Error', data.message || 'Failed to complete laybye', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'An error occurred while completing laybye', 'error');
                });
        }
    });
}

function cancelLaybye() {
    Swal.fire({
        title: 'Cancel Laybye?',
        text: 'This action cannot be undone. Please provide a reason:',
        input: 'text',
        inputPlaceholder: 'Reason for cancellation',
        showCancelButton: true,
        confirmButtonText: 'Cancel Laybye',
        cancelButtonText: 'Keep Laybye',
        inputValidator: (value) => {
            if (!value) {
                return 'Please provide a reason for cancellation';
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Implement cancel functionality
            Swal.fire('Info', 'Cancel functionality to be implemented', 'info');
        }
    });
}

function formatCurrency(amount) {
    return '<?= $baseCurrency['symbol'] ?? '$' ?>' + parseFloat(amount).toFixed(2);
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

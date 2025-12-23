<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
// Allow if user can access POS (pos.view or pos.create_sale)
if (!$auth->hasPermission('pos.view') && !$auth->hasPermission('pos.create_sale')) {
    $auth->requirePermission('pos.view'); // This will show access denied
}

initSession();

$cart = $_SESSION['pos_cart'] ?? [];
$customer = $_SESSION['pos_customer'] ?? null;
$discount = $_SESSION['pos_discount'] ?? ['type' => null, 'amount' => 0];

if (empty($cart)) {
    redirectTo('modules/pos/index.php');
}

$pageTitle = 'Payment';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Load currency functions
require_once APP_PATH . '/includes/currency_functions.php';

// Get currencies from tenant database (each tenant has their own currencies)
$currencies = getActiveCurrencies(null); // Uses tenant database
$baseCurrency = getBaseCurrency(null); // Uses tenant database

// Calculate totals
$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

$discountAmount = 0;
if ($discount['type'] === 'value') {
    $discountAmount = $discount['amount'];
} else if ($discount['type'] === 'percentage') {
    $discountAmount = ($subtotal * $discount['amount']) / 100;
}

$total = $subtotal - $discountAmount;

require_once APP_PATH . '/includes/header.php';
?>

<style>
.payment-container {
    display: flex;
    height: calc(100vh - 80px);
    gap: 20px;
}

.payment-left {
    width: 400px;
    background: #f9fafb;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
}

.payment-right {
    flex: 1;
    background: white;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
}

.payment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.total-display {
    text-align: center;
    margin: 24px 0;
}

.total-amount {
    font-size: 48px;
    font-weight: 700;
    color: var(--primary-blue);
    line-height: 1;
}

.total-label {
    font-size: 14px;
    color: var(--text-muted);
    margin-top: 8px;
}

.payment-methods {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.payment-method-btn {
    padding: 14px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    background: white;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 14px;
    font-weight: 600;
    text-align: center;
}

.payment-method-btn:hover {
    border-color: var(--primary-blue);
    background: var(--light-blue);
}

.payment-method-btn.active {
    border-color: var(--primary-blue);
    background: var(--primary-blue);
    color: white;
}

.amount-due {
    margin-bottom: 20px;
}

.amount-due-label {
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 8px;
}

.amount-due-value {
    font-size: 24px;
    font-weight: 600;
    color: var(--text-dark);
}

.keypad {
    margin-bottom: 20px;
}

.keypad-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 8px;
}

.keypad-btn {
    padding: 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.keypad-btn:hover {
    background: var(--light-blue);
    border-color: var(--primary-blue);
}

.keypad-btn.clear {
    background: #fee2e2;
    color: #dc2626;
}

.charge-btn {
    width: 100%;
    padding: 16px;
    background: var(--primary-blue);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.charge-btn:hover:not(:disabled) {
    background: var(--dark-navy);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(30, 58, 138, 0.3);
}

.charge-btn:disabled {
    background: #9ca3af;
    cursor: not-allowed;
    opacity: 0.5;
}

.change-display {
    background: #f0fdf4;
    border: 2px solid #22c55e;
    border-radius: 8px;
    padding: 12px;
    text-align: center;
    margin-bottom: 20px;
}

.split-payment-section {
    margin-top: 30px;
    padding-top: 30px;
    border-top: 2px solid #e5e7eb;
}

.split-controls {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}

.split-count {
    font-size: 14px;
    font-weight: 600;
    min-width: 80px;
    text-align: center;
}

.split-btn {
    width: 40px;
    height: 40px;
    border: 2px solid var(--primary-blue);
    background: white;
    color: var(--primary-blue);
    border-radius: 8px;
    font-size: 18px;
    cursor: pointer;
}

.split-payments {
    max-height: 400px;
    overflow-y: auto;
}

.split-payment-item {
    background: #f9fafb;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.split-payment-item select,
.split-payment-item input {
    flex: 1;
    padding: 10px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
}

.split-payment-item .btn {
    padding: 12px 20px;
}

/* ========== MOBILE RESPONSIVE STYLES ========== */

/* Small Laptop Screens (1024px - 1366px) */
@media (max-width: 1366px) and (min-width: 1025px) {
    .total-amount {
        font-size: 42px;
    }
    
    .amount-due-value {
        font-size: 22px;
    }
    
    .payment-method-btn {
        padding: 12px;
        font-size: 13px;
    }
    
    .keypad-btn {
        padding: 14px;
        font-size: 16px;
    }
    
    .charge-btn {
        padding: 14px;
        font-size: 15px;
    }
}

/* Tablet and below (max-width: 1024px) */
@media (max-width: 1024px) {
    .payment-container {
        flex-direction: column;
        height: auto;
        min-height: calc(100vh - 80px);
        gap: 12px;
    }
    
    .payment-left {
        width: 100%;
        order: 2;
        max-height: 50vh;
        overflow-y: auto;
        padding: 16px;
    }
    
    .payment-right {
        order: 1;
        padding: 16px;
    }
    
    .total-amount {
        font-size: 36px;
    }
    
    .total-label {
        font-size: 12px;
    }
    
    .payment-methods {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-bottom: 16px;
    }
    
    .payment-method-btn {
        padding: 12px;
        font-size: 13px;
    }
    
    .amount-due-value {
        font-size: 20px;
    }
    
    .keypad-btn {
        padding: 12px;
        font-size: 16px;
    }
    
    .charge-btn {
        padding: 14px;
        font-size: 15px;
    }
}

/* Mobile (max-width: 768px) */
@media (max-width: 768px) {
    .payment-container {
        flex-direction: column;
        height: auto;
        min-height: calc(100vh - 60px);
        gap: 0;
        padding: 0;
    }
    
    .payment-left {
        width: 100%;
        order: 2;
        border-radius: 0;
        padding: 12px;
        max-height: none;
        position: relative;
    }
    
    .payment-right {
        order: 1;
        width: 100%;
        border-radius: 0;
        padding: 16px 12px;
        min-height: auto;
    }
    
    .payment-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 16px;
    }
    
    .payment-header .btn {
        width: 100%;
        justify-content: center;
        font-size: 13px;
        padding: 8px 12px;
    }
    
    .total-display {
        margin: 16px 0;
    }
    
    .total-amount {
        font-size: 32px;
    }
    
    .total-label {
        font-size: 11px;
    }
    
    .payment-methods {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        margin-bottom: 16px;
    }
    
    .payment-method-btn {
        padding: 12px 10px;
        font-size: 12px;
    }
    
    .amount-due {
        margin-bottom: 16px;
    }
    
    .amount-due-label {
        font-size: 11px;
    }
    
    .amount-due-value {
        font-size: 20px;
    }
    
    .keypad {
        margin-bottom: 16px;
    }
    
    .keypad-row {
        grid-template-columns: repeat(4, 1fr);
        gap: 6px;
        margin-bottom: 6px;
    }
    
    .keypad-btn {
        padding: 14px 10px;
        font-size: 16px;
    }
    
    .charge-btn {
        padding: 14px;
        font-size: 14px;
    }
    
    .change-display {
        padding: 10px;
        margin-bottom: 16px;
    }
    
    .change-display .amount-due-value {
        font-size: 18px;
    }
    
    .split-controls {
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .split-btn {
        width: 36px;
        height: 36px;
        font-size: 16px;
    }
    
    .split-count {
        font-size: 12px;
        min-width: 70px;
    }
    
    .split-payment-item {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        padding: 12px;
    }
    
    .split-payment-item select,
    .split-payment-item input {
        width: 100%;
        font-size: 13px;
        padding: 10px;
    }
}

/* Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .payment-left {
        padding: 10px;
    }
    
    .payment-right {
        padding: 12px 10px;
    }
    
    .payment-header {
        margin-bottom: 12px;
        gap: 10px;
    }
    
    .total-display {
        margin: 12px 0;
    }
    
    .total-amount {
        font-size: 28px;
    }
    
    .total-label {
        font-size: 10px;
        margin-top: 6px;
    }
    
    .payment-methods {
        grid-template-columns: repeat(2, 1fr);
        gap: 6px;
        margin-bottom: 14px;
    }
    
    .payment-method-btn {
        padding: 10px 8px;
        font-size: 11px;
    }
    
    .amount-due {
        margin-bottom: 14px;
    }
    
    .amount-due-label {
        font-size: 10px;
        margin-bottom: 6px;
    }
    
    .amount-due-value {
        font-size: 18px;
    }
    
    .keypad {
        margin-bottom: 14px;
    }
    
    .keypad-row {
        gap: 5px;
        margin-bottom: 5px;
    }
    
    .keypad-btn {
        padding: 12px 8px;
        font-size: 15px;
    }
    
    .charge-btn {
        padding: 12px;
        font-size: 13px;
    }
    
    .change-display {
        padding: 8px;
        margin-bottom: 14px;
    }
    
    .change-display .amount-due-label {
        font-size: 10px;
    }
    
    .change-display .amount-due-value {
        font-size: 16px;
    }
    
    .cart-item {
        font-size: 12px;
        margin-bottom: 10px;
    }
    
    .cart-item-name {
        font-size: 12px;
    }
    
    .cart-item-price {
        font-size: 11px;
    }
    
    .split-payment-item {
        padding: 10px;
    }
    
    .split-btn {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
    
    .split-count {
        font-size: 11px;
        min-width: 60px;
    }
}

/* Extra Small Mobile (max-width: 360px) */
@media (max-width: 360px) {
    .payment-left {
        padding: 8px;
    }
    
    .payment-right {
        padding: 10px 8px;
    }
    
    .total-amount {
        font-size: 24px;
    }
    
    .payment-method-btn {
        padding: 8px 6px;
        font-size: 10px;
    }
    
    .amount-due-value {
        font-size: 16px;
    }
    
    .keypad-btn {
        padding: 10px 6px;
        font-size: 14px;
    }
    
    .charge-btn {
        padding: 10px;
        font-size: 12px;
    }
    
    .split-payment-item select,
    .split-payment-item input {
        font-size: 12px;
        padding: 8px;
    }
}

/* Form element scaling */
@media (max-width: 768px) {
    .form-select-lg {
        font-size: 14px !important;
        padding: 10px 12px !important;
    }
    
    .form-label.fw-bold {
        font-size: 13px !important;
    }
}

@media (max-width: 480px) {
    .form-select-lg {
        font-size: 13px !important;
        padding: 8px 10px !important;
    }
    
    .form-label.fw-bold {
        font-size: 12px !important;
    }
    
    .form-control-lg {
        font-size: 13px !important;
        padding: 8px 10px !important;
    }
}

/* Landscape Mobile */
@media (max-width: 768px) and (orientation: landscape) {
    .payment-container {
        flex-direction: row;
        height: calc(100vh - 60px);
    }
    
    .payment-left {
        width: 40%;
        max-height: 100%;
        order: 1;
    }
    
    .payment-right {
        width: 60%;
        order: 2;
    }
}
</style>

<div class="payment-container">
    <div class="payment-left">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5>Order Summary</h5>
            <button class="btn btn-sm btn-secondary" onclick="window.location.href='index.php'">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
        
        <div class="cart-items" style="flex: 1; overflow-y: auto; margin-bottom: 20px;" id="cartItemsContainer">
            <?php foreach ($cart as $index => $item): ?>
                <div class="cart-item mb-3" data-item-index="<?= $index ?>">
                    <div class="cart-item-info">
                        <div class="cart-item-name"><?= escapeHtml($item['name']) ?></div>
                        <div class="cart-item-price" data-base-price="<?= $item['price'] ?>" data-quantity="<?= $item['quantity'] ?>">
                            <?= formatCurrency($item['price']) ?> × <?= $item['quantity'] ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <strong class="cart-item-total" data-base-total="<?= $item['price'] * $item['quantity'] ?>">
                            <?= formatCurrency($item['price'] * $item['quantity']) ?>
                        </strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="border-top pt-3">
            <div class="d-flex justify-content-between mb-2">
                <span>Subtotal:</span>
                <strong id="orderSubtotal" data-base-amount="<?= $subtotal ?>"><?= formatCurrency($subtotal) ?></strong>
            </div>
            <?php if ($discountAmount > 0): ?>
                <div class="d-flex justify-content-between mb-2 text-danger">
                    <span>Discount:</span>
                    <strong id="orderDiscount" data-base-amount="<?= $discountAmount ?>">-<?= formatCurrency($discountAmount) ?></strong>
                </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between pt-2 border-top">
                <span class="fs-5 fw-bold">Total Charge:</span>
                <span class="fs-5 fw-bold text-primary" id="orderTotal" data-base-amount="<?= $total ?>"><?= formatCurrency($total) ?></span>
            </div>
        </div>
    </div>
    
    <div class="payment-right">
        <div class="payment-header">
            <button class="btn btn-link" onclick="window.location.href='index.php'">
                <i class="bi bi-arrow-left"></i> Payment
            </button>
            <button class="btn btn-primary" id="splitBtn" onclick="toggleSplitPayment()">SPLIT</button>
        </div>
        
        <div class="total-display">
            <div class="total-amount" id="totalAmountDisplay"><?= number_format($total, 2) ?></div>
            <div class="total-label">Total Amount</div>
        </div>
        
        <div class="payment-methods">
            <div class="payment-method-btn active" data-method="cash" onclick="selectPaymentMethod('cash')">
                Cash
            </div>
            <div class="payment-method-btn" data-method="card" onclick="selectPaymentMethod('card')">
                Card
            </div>
            <div class="payment-method-btn" data-method="ecocash" onclick="selectPaymentMethod('ecocash')">
                EcoCash
            </div>
            <div class="payment-method-btn" data-method="onemoney" onclick="selectPaymentMethod('onemoney')">
                OneMoney
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label fw-bold">Currency</label>
            <select class="form-select form-select-lg" id="paymentCurrency" onchange="updateCurrencyDisplay()">
                <?php foreach ($currencies as $currency): ?>
                    <option value="<?= $currency['id'] ?>" <?= $currency['is_base'] ? 'selected' : '' ?>>
                        <?= escapeHtml($currency['code']) ?> - <?= escapeHtml($currency['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted" id="currencyRateInfo"></small>
        </div>
        
        <div class="amount-due mb-3">
            <div class="amount-due-label">Amount Paid</div>
            <div class="amount-due-value" id="amountPaidDisplay">$0.00</div>
        </div>
        
        <div class="change-display mb-3" id="changeDisplay" style="display: none;">
            <div class="amount-due-label text-success">Change to Give</div>
            <div class="amount-due-value text-success fw-bold" id="changeAmount">$0.00</div>
        </div>
        
        <div class="keypad">
            <div class="keypad-row">
                <button class="keypad-btn touch-friendly" onclick="keypadInput('1')">1</button>
                <button class="keypad-btn touch-friendly" onclick="keypadInput('2')">2</button>
                <button class="keypad-btn touch-friendly" onclick="keypadInput('3')">3</button>
                <button class="keypad-btn touch-friendly" onclick="keypadBackspace()"><i class="bi bi-backspace"></i></button>
            </div>
            <div class="keypad-row">
                <button class="keypad-btn touch-friendly" onclick="keypadInput('4')">4</button>
                <button class="keypad-btn touch-friendly" onclick="keypadInput('5')">5</button>
                <button class="keypad-btn touch-friendly" onclick="keypadInput('6')">6</button>
                <button class="keypad-btn touch-friendly"></button>
            </div>
            <div class="keypad-row">
                <button class="keypad-btn touch-friendly" onclick="keypadInput('7')">7</button>
                <button class="keypad-btn touch-friendly" onclick="keypadInput('8')">8</button>
                <button class="keypad-btn touch-friendly" onclick="keypadInput('9')">9</button>
                <button class="keypad-btn touch-friendly"></button>
            </div>
            <div class="keypad-row">
                <button class="keypad-btn touch-friendly" onclick="keypadInput('.')">.</button>
                <button class="keypad-btn touch-friendly" onclick="keypadInput('0')">0</button>
                <button class="keypad-btn touch-friendly clear" onclick="keypadClear()">CLEAR</button>
                <button class="keypad-btn touch-friendly"></button>
            </div>
        </div>
        
        <button class="charge-btn touch-friendly" onclick="processCharge()" id="chargeBtn" disabled style="opacity: 0.5; cursor: not-allowed;">
            Process Payment
        </button>
    </div>
</div>

<!-- Split Payment Modal -->
<div class="modal fade" id="splitPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Split Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6>Total Amount: <strong class="text-primary" id="splitTotalDisplay"><?= $baseCurrency ? formatCurrencyAmount($total, $baseCurrency['id'], $db) : '$' . number_format($total, 2) ?></strong></h6>
                        <div class="d-flex align-items-center gap-3">
                            <button class="btn btn-outline-secondary" onclick="changeSplitCount(-1)">
                                <i class="bi bi-dash-lg"></i>
                            </button>
                            <span class="fw-bold"><span id="splitCount">2</span> Payments</span>
                            <button class="btn btn-outline-secondary" onclick="changeSplitCount(1)">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </div>
                    <div class="alert alert-info mb-2">
                        <strong>Remaining (Base Currency):</strong> <span id="splitRemaining" class="fw-bold"><?= $baseCurrency ? formatCurrencyAmount($total, $baseCurrency['id'], $db) : '$' . number_format($total, 2) ?></span>
                    </div>
                    <div class="alert alert-success" id="splitChangeDisplay" style="display: none;">
                        <strong>Change to Give (Base Currency):</strong> <span id="splitChangeAmount" class="fw-bold text-success"></span>
                    </div>
                </div>
                
                <div class="split-payments" id="splitPayments" style="max-height: 400px; overflow-y: auto;">
                    <!-- Split payment items will be generated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-secondary" id="processSplitBtn" onclick="processSplitPayment()" disabled style="opacity: 0.5; cursor: not-allowed;">Process All Payments</button>
            </div>
        </div>
    </div>
</div>

<script>
let totalAmount = <?= $total ?>;
let amountPaid = '0.00';
let selectedMethod = 'cash';
let selectedCurrencyId = <?= $baseCurrency ? $baseCurrency['id'] : 'null' ?>;
let baseCurrencyId = <?= $baseCurrency ? $baseCurrency['id'] : 'null' ?>;
let currencies = <?= json_encode($currencies) ?>;
let splitPayments = [];
let splitCount = 2;
let cartData = <?= json_encode($cart) ?>;
let orderSubtotal = <?= $subtotal ?>;
let orderDiscount = <?= $discountAmount ?>;
let orderTotal = <?= $total ?>;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCurrencyDisplay();
    updateAmountDue();
});

// Currency conversion functions
function getCurrencyById(id) {
    return currencies.find(c => c.id == id) || null;
}

function convertToBase(amount, fromCurrencyId) {
    if (fromCurrencyId == baseCurrencyId) return parseFloat(amount);
    const currency = getCurrencyById(fromCurrencyId);
    if (!currency) return parseFloat(amount);
    return parseFloat(amount) / parseFloat(currency.exchange_rate);
}

function convertFromBase(amount, toCurrencyId) {
    if (toCurrencyId == baseCurrencyId) return parseFloat(amount);
    const currency = getCurrencyById(toCurrencyId);
    if (!currency) return parseFloat(amount);
    return parseFloat(amount) * parseFloat(currency.exchange_rate);
}

function formatCurrencyAmount(amount, currencyId) {
    const currency = getCurrencyById(currencyId);
    if (!currency) return '$' + parseFloat(amount).toFixed(2);
    const formatted = parseFloat(amount).toFixed(currency.decimal_places);
    if (currency.symbol_position === 'before') {
        return currency.symbol + formatted;
    } else {
        return formatted + ' ' + currency.symbol;
    }
}

function updateCurrencyDisplay() {
    const currencySelect = document.getElementById('paymentCurrency');
    if (!currencySelect) return;
    
    selectedCurrencyId = parseInt(currencySelect.value);
    const currency = getCurrencyById(selectedCurrencyId);
    if (!currency) return;
    
    // Update rate info
    const rateInfo = document.getElementById('currencyRateInfo');
    if (rateInfo && currency.id != baseCurrencyId) {
        rateInfo.textContent = `1 ${getCurrencyById(baseCurrencyId)?.code || 'USD'} = ${parseFloat(currency.exchange_rate).toFixed(6)} ${currency.code}`;
    } else if (rateInfo) {
        rateInfo.textContent = 'Base currency';
    }
    
    // Update order summary with selected currency
    updateOrderSummary();
    
    // Update amount display
    updateAmountDue();
}

function updateOrderSummary() {
    const currency = getCurrencyById(selectedCurrencyId || baseCurrencyId);
    if (!currency) return;
    
    // Update cart items
    document.querySelectorAll('.cart-item').forEach(itemEl => {
        const priceEl = itemEl.querySelector('.cart-item-price');
        const totalEl = itemEl.querySelector('.cart-item-total');
        
        if (priceEl && totalEl) {
            const basePrice = parseFloat(priceEl.getAttribute('data-base-price')) || 0;
            const quantity = parseFloat(priceEl.getAttribute('data-quantity')) || 1;
            const baseTotal = parseFloat(totalEl.getAttribute('data-base-total')) || 0;
            
            // Convert to selected currency
            const convertedPrice = convertFromBase(basePrice, selectedCurrencyId || baseCurrencyId);
            const convertedTotal = convertFromBase(baseTotal, selectedCurrencyId || baseCurrencyId);
            
            // Update display
            priceEl.textContent = formatCurrencyAmount(convertedPrice, selectedCurrencyId || baseCurrencyId) + ' × ' + quantity;
            totalEl.textContent = formatCurrencyAmount(convertedTotal, selectedCurrencyId || baseCurrencyId);
        }
    });
    
    // Update subtotal
    const subtotalEl = document.getElementById('orderSubtotal');
    if (subtotalEl) {
        const baseSubtotal = parseFloat(subtotalEl.getAttribute('data-base-amount')) || 0;
        const convertedSubtotal = convertFromBase(baseSubtotal, selectedCurrencyId || baseCurrencyId);
        subtotalEl.textContent = formatCurrencyAmount(convertedSubtotal, selectedCurrencyId || baseCurrencyId);
    }
    
    // Update discount
    const discountEl = document.getElementById('orderDiscount');
    if (discountEl) {
        const baseDiscount = parseFloat(discountEl.getAttribute('data-base-amount')) || 0;
        const convertedDiscount = convertFromBase(baseDiscount, selectedCurrencyId || baseCurrencyId);
        discountEl.textContent = '-' + formatCurrencyAmount(convertedDiscount, selectedCurrencyId || baseCurrencyId);
    }
    
    // Update total
    const totalEl = document.getElementById('orderTotal');
    if (totalEl) {
        const baseTotal = parseFloat(totalEl.getAttribute('data-base-amount')) || 0;
        const convertedTotal = convertFromBase(baseTotal, selectedCurrencyId || baseCurrencyId);
        totalEl.textContent = formatCurrencyAmount(convertedTotal, selectedCurrencyId || baseCurrencyId);
    }
    
    // Update total amount display (the big number in payment section)
    const totalAmountDisplay = document.getElementById('totalAmountDisplay');
    if (totalAmountDisplay) {
        const convertedTotal = convertFromBase(totalAmount, selectedCurrencyId || baseCurrencyId);
        totalAmountDisplay.textContent = parseFloat(convertedTotal).toFixed(currency.decimal_places || 2);
    }
}

function selectPaymentMethod(method) {
    selectedMethod = method;
    document.querySelectorAll('.payment-method-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    const btn = document.querySelector(`[data-method="${method}"]`);
    if (btn) {
        btn.classList.add('active');
    }
}

function toggleSplitPayment() {
    // Open split payment modal
    splitCount = 2; // Reset to 2 payments
    initializeSplitPayments();
    const modal = new bootstrap.Modal(document.getElementById('splitPaymentModal'));
    modal.show();
}

function changeSplitCount(delta) {
    splitCount = Math.max(1, splitCount + delta);
    document.getElementById('splitCount').textContent = splitCount;
    initializeSplitPayments();
    validateSplitPayment(); // Revalidate after count change
}

function initializeSplitPayments() {
    const container = document.getElementById('splitPayments');
    splitPayments = [];
    
    // Get base currency ID
    const baseCurrencyId = <?= $baseCurrency ? $baseCurrency['id'] : 'null' ?>;
    
    // Initialize with different payment methods to avoid duplicates
    const methods = ['cash', 'card', 'ecocash', 'onemoney'];
    for (let i = 0; i < splitCount; i++) {
        splitPayments.push({
            method: methods[i % methods.length], // Cycle through methods
            currency_id: baseCurrencyId, // Default to base currency
            amount: '0.00',
            base_amount: '0.00' // Will be calculated
        });
    }
    
    renderSplitPayments();
    validateSplitPayment(); // Initial validation
}

function renderSplitPayments() {
    const container = document.getElementById('splitPayments');
    let totalPaid = 0;
    
    container.innerHTML = splitPayments.map((payment, index) => {
        const amount = parseFloat(payment.amount) || 0;
        totalPaid += amount;
        return `
            <div class="split-payment-item mb-3 p-3 border rounded">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <strong class="text-muted">Payment ${index + 1}</strong>
                    ${splitPayments.length > 1 ? `<button class="btn btn-sm btn-danger ms-auto" onclick="removeSplitPayment(${index})" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>` : ''}
                </div>
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small">Payment Method</label>
                        <select class="form-select" onchange="updateSplitMethod(${index}, this.value)">
                            <option value="cash" ${payment.method === 'cash' ? 'selected' : ''}>Cash</option>
                            <option value="card" ${payment.method === 'card' ? 'selected' : ''}>Card</option>
                            <option value="ecocash" ${payment.method === 'ecocash' ? 'selected' : ''}>EcoCash</option>
                            <option value="onemoney" ${payment.method === 'onemoney' ? 'selected' : ''}>OneMoney</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Currency</label>
                        <select class="form-select split-currency-select" data-index="${index}" onchange="updateSplitCurrency(${index}, this.value)">
                            ${currencies.map(currency => `
                                <option value="${currency.id}" 
                                        data-code="${currency.code}"
                                        data-symbol="${currency.symbol}"
                                        data-position="${currency.symbol_position}"
                                        data-rate="${currency.exchange_rate}"
                                        ${payment.currency_id == currency.id ? 'selected' : ''}>
                                    ${currency.code}
                                </option>
                            `).join('')}
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Amount <small class="text-muted" id="splitCurrencyInfo${index}"></small></label>
                        <div class="input-group">
                            <span class="input-group-text split-currency-symbol" id="splitSymbol${index}"><?= $baseCurrency ? escapeHtml($baseCurrency['symbol']) : '$' ?></span>
                            <input type="text" class="form-control form-control-lg text-end split-amount-input" 
                                   data-index="${index}"
                                   value="${amount > 0 ? payment.amount : ''}" 
                                   onchange="updateSplitAmount(${index}, this.value)" 
                                   oninput="this.value = this.value.replace(/[^0-9.]/g, ''); updateSplitAmount(${index}, this.value)"
                                   placeholder="0.00">
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    remainingAmount = totalAmount - totalPaid;
    updateSplitRemaining();
    // Validate after rendering - safe now since validateSplitPayment no longer calls itself
    validateSplitPayment();
}

function updateSplitRemaining() {
    const remainingEl = document.getElementById('splitRemaining');
    const changeDisplay = document.getElementById('splitChangeDisplay');
    const changeAmountEl = document.getElementById('splitChangeAmount');
    
    if (remainingEl) {
        const baseCurrency = getCurrencyById(baseCurrencyId);
        const formatted = formatCurrencyAmount(Math.abs(remainingAmount), baseCurrencyId);
        
        // Remove all classes first
        remainingEl.classList.remove('text-danger', 'text-success', 'text-warning');
        
        if (remainingAmount < 0) {
            // Show change (exceeds total)
            remainingEl.classList.add('text-danger');
            remainingEl.textContent = formatted + ' (Exceeds Total!)';
            
            // Show change display
            if (changeDisplay) changeDisplay.style.display = 'block';
            if (changeAmountEl) {
                changeAmountEl.textContent = formatCurrencyAmount(Math.abs(remainingAmount), baseCurrencyId);
            }
        } else if (Math.abs(remainingAmount) < 0.01) {
            remainingEl.classList.add('text-success');
            remainingEl.textContent = formatted + ' (Exact!)';
            
            // Hide change display
            if (changeDisplay) changeDisplay.style.display = 'none';
        } else if (remainingAmount > 0) {
            remainingEl.classList.add('text-warning');
            remainingEl.textContent = formatted + ' remaining';
            
            // Hide change display
            if (changeDisplay) changeDisplay.style.display = 'none';
        } else {
            remainingEl.textContent = formatted;
            
            // Hide change display
            if (changeDisplay) changeDisplay.style.display = 'none';
        }
    }
}

// Real-time validation for split payments
function validateSplitPayment() {
    let totalPaidBase = 0;
    let hasErrors = false;
    
    // Calculate total in base currency and check for errors
    for (let i = 0; i < splitPayments.length; i++) {
        const amount = parseFloat(splitPayments[i].amount) || 0;
        const currencyId = splitPayments[i].currency_id || baseCurrencyId;
        
        if (amount <= 0) {
            hasErrors = true;
        }
        
        // Convert to base currency
        const baseAmount = convertToBase(amount, currencyId);
        totalPaidBase += baseAmount;
        splitPayments[i].base_amount = baseAmount.toFixed(2);
    }
    
    // Get the process button
    const processBtn = document.getElementById('processSplitBtn');
    
    // Validate: total must be >= cart total (in base currency), all amounts > 0
    const isValid = !hasErrors && totalPaidBase >= totalAmount;
    
    if (processBtn) {
        if (isValid) {
            processBtn.disabled = false;
            processBtn.style.opacity = '1';
            processBtn.style.cursor = 'pointer';
            processBtn.classList.remove('btn-secondary');
            processBtn.classList.add('btn-primary');
        } else {
            processBtn.disabled = true;
            processBtn.style.opacity = '0.5';
            processBtn.style.cursor = 'not-allowed';
            processBtn.classList.remove('btn-primary');
            processBtn.classList.add('btn-secondary');
        }
    }
    
    // Update remaining display (in base currency)
    remainingAmount = totalAmount - totalPaidBase;
    updateSplitRemaining();
}

function updateSplitMethod(index, method) {
    // Check if this method is already used by another payment WITH THE SAME CURRENCY
    const currentCurrencyId = splitPayments[index].currency_id || baseCurrencyId;
    const existingIndex = splitPayments.findIndex((p, i) => 
        i !== index && 
        p.method === method && 
        (p.currency_id || baseCurrencyId) === currentCurrencyId
    );
    if (existingIndex !== -1) {
        Swal.fire({
            title: 'Duplicate Payment Method',
            text: `${method.charAt(0).toUpperCase() + method.slice(1)} with the same currency is already used in Payment ${existingIndex + 1}. Please choose a different payment method or currency.`,
            icon: 'warning',
            confirmButtonText: 'OK'
        });
        // Revert to previous method
        renderSplitPayments();
        return;
    }
    splitPayments[index].method = method;
    validateSplitPayment();
}

function updateSplitCurrency(index, currencyId) {
    const currency = getCurrencyById(parseInt(currencyId));
    if (!currency) return;
    
    splitPayments[index].currency_id = parseInt(currencyId);
    
    // Update symbol display
    const symbolEl = document.getElementById(`splitSymbol${index}`);
    if (symbolEl) {
        symbolEl.textContent = currency.symbol;
    }
    
    // Update currency info
    const infoEl = document.getElementById(`splitCurrencyInfo${index}`);
    if (infoEl) {
        if (currency.id != baseCurrencyId) {
            infoEl.textContent = `(1 ${getCurrencyById(baseCurrencyId)?.code || 'USD'} = ${parseFloat(currency.exchange_rate).toFixed(6)} ${currency.code})`;
        } else {
            infoEl.textContent = '';
        }
    }
    
    // Recalculate base amount if amount exists
    if (splitPayments[index].amount && parseFloat(splitPayments[index].amount) > 0) {
        const baseAmount = convertToBase(parseFloat(splitPayments[index].amount), parseInt(currencyId));
        splitPayments[index].base_amount = baseAmount.toFixed(2);
    } else {
        // Reset base amount if no amount
        splitPayments[index].base_amount = '0.00';
    }
    
    // Force validation to update immediately
    validateSplitPayment();
    
    // Show validation feedback if needed
    setTimeout(() => {
        let totalPaidBase = 0;
        for (let i = 0; i < splitPayments.length; i++) {
            const amount = parseFloat(splitPayments[i].amount) || 0;
            const currId = splitPayments[i].currency_id || baseCurrencyId;
            totalPaidBase += convertToBase(amount, currId);
        }
        
        if (totalPaidBase > 0 && totalPaidBase < totalAmount) {
            const formattedPaid = formatCurrencyAmount(totalPaidBase, baseCurrencyId);
            const formattedTotal = formatCurrencyAmount(totalAmount, baseCurrencyId);
            const remaining = totalAmount - totalPaidBase;
            const formattedRemaining = formatCurrencyAmount(remaining, baseCurrencyId);
            
            // Show toast/notification (non-blocking)
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Insufficient Payment',
                    html: `Total payments (${formattedPaid}) is less than cart total (${formattedTotal}).<br>Remaining: <strong>${formattedRemaining}</strong>`,
                    icon: 'warning',
                    timer: 3000,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            }
        }
    }, 100);
}

function updateSplitAmount(index, amount) {
    const numAmount = parseFloat(amount) || 0;
    const currencyId = splitPayments[index].currency_id || baseCurrencyId;
    const currency = getCurrencyById(currencyId);
    
    // Store amount with proper decimal places
    const decimalPlaces = currency ? currency.decimal_places : 2;
    splitPayments[index].amount = numAmount > 0 ? numAmount.toFixed(decimalPlaces) : '0.00';
    
    // Calculate and store base amount
    const baseAmount = convertToBase(numAmount, currencyId);
    splitPayments[index].base_amount = baseAmount.toFixed(2);
    
    // Real-time validation
    validateSplitPayment();
}

function removeSplitPayment(index) {
    if (splitPayments.length <= 1) {
        Swal.fire('Error', 'You must have at least one payment', 'error');
        return;
    }
    splitPayments.splice(index, 1);
    splitCount = splitPayments.length;
    document.getElementById('splitCount').textContent = splitCount;
    renderSplitPayments();
    validateSplitPayment(); // Revalidate after removal
}

function processSplitPayment() {
    // Final validation (button should already be disabled if invalid, but double-check)
    let totalPaidBase = 0;
    const usedMethods = [];
    
    // Prepare payments with currency info
    const paymentsToProcess = [];
    
    for (let i = 0; i < splitPayments.length; i++) {
        const amount = parseFloat(splitPayments[i].amount) || 0;
        const currencyId = splitPayments[i].currency_id || baseCurrencyId;
        const currency = getCurrencyById(currencyId);
        
        if (amount <= 0) {
            Swal.fire('Error', `Payment ${i + 1} amount must be greater than 0`, 'error');
            return;
        }
        
        // Check for duplicate payment methods WITH THE SAME CURRENCY
        // Allow same payment method if currencies are different
        const methodCurrencyKey = `${splitPayments[i].method}_${currencyId}`;
        if (usedMethods.includes(methodCurrencyKey)) {
            Swal.fire({
                title: 'Duplicate Payment Method',
                text: `${splitPayments[i].method.charAt(0).toUpperCase() + splitPayments[i].method.slice(1)} with the same currency is used more than once. Please use different payment methods or currencies.`,
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }
        usedMethods.push(methodCurrencyKey);
        
        // Calculate base amount
        const baseAmount = convertToBase(amount, currencyId);
        totalPaidBase += baseAmount;
        
        // Get exchange rate
        const exchangeRate = currency && currency.id != baseCurrencyId ? parseFloat(currency.exchange_rate) : 1.0;
        
        paymentsToProcess.push({
            method: splitPayments[i].method,
            amount: amount,
            currency_id: currencyId,
            exchange_rate: exchangeRate,
            original_amount: amount,
            base_amount: baseAmount.toFixed(2)
        });
    }
    
    // Validate that total split amount (in base currency) is at least equal to cart total
    if (totalPaidBase < totalAmount) {
        const formattedPaid = formatCurrencyAmount(totalPaidBase, baseCurrencyId);
        const formattedTotal = formatCurrencyAmount(totalAmount, baseCurrencyId);
        Swal.fire({
            title: 'Insufficient Payment',
            text: `Total payments (${formattedPaid}) is less than cart total (${formattedTotal}). Please adjust the amounts.`,
            icon: 'error',
            confirmButtonText: 'OK'
        });
        return;
    }
    
    // If we get here, validation passed - process payment
    // Close modal first
    const modal = bootstrap.Modal.getInstance(document.getElementById('splitPaymentModal'));
    if (modal) modal.hide();
    // Process all split payments
    processPayment(paymentsToProcess);
}

let keypadInputValue = '0.00';
function keypadInput(char) {
    if (char === '.') {
        if (!keypadInputValue.includes('.')) {
            keypadInputValue += '.';
        }
    } else {
        if (keypadInputValue === '0.00' || keypadInputValue === '0') {
            keypadInputValue = char;
        } else {
            keypadInputValue += char;
        }
    }
    amountPaid = keypadInputValue;
    updateAmountDue();
}

function keypadBackspace() {
    keypadInputValue = keypadInputValue.slice(0, -1);
    if (!keypadInputValue) keypadInputValue = '0.00';
    amountPaid = keypadInputValue;
    updateAmountDue();
}

function keypadClear() {
    keypadInputValue = '0.00';
    amountPaid = '0.00';
    updateAmountDue();
}

function updateAmountDue() {
    const paid = parseFloat(amountPaid) || 0;
    const amountPaidEl = document.getElementById('amountPaidDisplay');
    const changeDisplay = document.getElementById('changeDisplay');
    const changeAmountEl = document.getElementById('changeAmount');
    const chargeBtn = document.getElementById('chargeBtn');
    
    // Get currency for display
    const currency = getCurrencyById(selectedCurrencyId || baseCurrencyId);
    
    // Convert paid amount to base currency for validation
    const paidBase = convertToBase(paid, selectedCurrencyId || baseCurrencyId);
    
    // Update amount paid display with currency symbol
    if (amountPaidEl) {
        amountPaidEl.textContent = formatCurrencyAmount(paid, selectedCurrencyId || baseCurrencyId);
    }
    
    // Calculate and show change if paid > total
    // Convert total to payment currency first, then calculate change in payment currency
    const totalInPaymentCurrency = convertFromBase(totalAmount, selectedCurrencyId || baseCurrencyId);
    const paidInPaymentCurrency = parseFloat(amountPaid) || 0;
    
    if (paidInPaymentCurrency > totalInPaymentCurrency) {
        const change = paidInPaymentCurrency - totalInPaymentCurrency;
        if (changeDisplay) {
            changeDisplay.style.display = 'block';
        }
        if (changeAmountEl) {
            // Display change in the same currency as payment
            changeAmountEl.textContent = formatCurrencyAmount(change, selectedCurrencyId || baseCurrencyId);
        }
    } else {
        if (changeDisplay) {
            changeDisplay.style.display = 'none';
        }
    }
    
    // Enable/disable charge button based on validation (using base currency)
    if (chargeBtn) {
        if (paidBase >= totalAmount && paid > 0) {
            chargeBtn.disabled = false;
            chargeBtn.style.opacity = '1';
            chargeBtn.style.cursor = 'pointer';
        } else {
            chargeBtn.disabled = true;
            chargeBtn.style.opacity = '0.5';
            chargeBtn.style.cursor = 'not-allowed';
        }
    }
}

function processCharge() {
    // Process single payment
    const paid = parseFloat(amountPaid) || 0;
    const currencyId = selectedCurrencyId || baseCurrencyId;
    const currency = getCurrencyById(currencyId);
    
    // Convert to base currency for validation
    const paidBase = convertToBase(paid, currencyId);
    
    // Validation
    if (paid <= 0) {
        Swal.fire('Error', 'Please enter an amount to pay', 'error');
        return;
    }
    
    if (paidBase < totalAmount) {
        const formattedPaid = formatCurrencyAmount(paid, currencyId);
        const formattedTotal = formatCurrencyAmount(totalAmount, baseCurrencyId);
        Swal.fire('Error', `Amount paid (${formattedPaid}) is less than total (${formattedTotal})`, 'error');
        return;
    }
    
    // Get exchange rate
    const exchangeRate = currency && currency.id != baseCurrencyId ? parseFloat(currency.exchange_rate) : 1.0;
    
    // Process payment
    processPayment([{
        method: selectedMethod,
        amount: paid,
        currency_id: currencyId,
        exchange_rate: exchangeRate,
        original_amount: paid,
        base_amount: paidBase.toFixed(2)
    }]);
}

// Helper function to format ZIMRA error messages for display
function formatZimraError(errorMessage) {
    if (!errorMessage) return 'Unknown error';
    
    // If it's a ZIMRA API Error, format it nicely
    if (errorMessage.includes('ZIMRA API Error')) {
        // Extract error code and message
        const match = errorMessage.match(/ZIMRA API Error \(([^)]+)\): ([^|]+)/);
        if (match) {
            const errorCode = escapeHtml(match[1]);
            const errorMsg = escapeHtml(match[2].trim());
            let formatted = '<strong>Error Code:</strong> ' + errorCode + '<br><strong>Message:</strong> ' + errorMsg;
            
            // Extract validation errors if present
            const validationMatch = errorMessage.match(/Validation errors: (.+?)(?:\s*\|\s*Full response:|$)/);
            if (validationMatch) {
                try {
                    const validationErrors = JSON.parse(validationMatch[1]);
                    formatted += '<br><br><strong>Validation Errors:</strong><br>';
                    formatted += '<pre style="margin: 5px 0; white-space: pre-wrap;">' + escapeHtml(JSON.stringify(validationErrors, null, 2)) + '</pre>';
                } catch (e) {
                    formatted += '<br><br><strong>Validation Errors:</strong> ' + escapeHtml(validationMatch[1]);
                }
            }
            
            // Extract full response if present (truncated)
            const fullResponseMatch = errorMessage.match(/Full response: (.+)$/);
            if (fullResponseMatch) {
                let fullResponse = fullResponseMatch[1];
                if (fullResponse.length > 500) {
                    fullResponse = fullResponse.substring(0, 500) + '... (truncated)';
                }
                formatted += '<br><br><strong>Full Response:</strong><br>';
                formatted += '<pre style="margin: 5px 0; white-space: pre-wrap; word-break: break-all;">' + escapeHtml(fullResponse) + '</pre>';
            }
            
            return formatted;
        }
    }
    
    // Return escaped if not a ZIMRA error
    return escapeHtml(errorMessage);
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function processPayment(payments) {
    Swal.fire({
        title: 'Processing Payment...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/process_sale.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
            cart: <?= json_encode($cart) ?>,
            customer: <?= json_encode($customer) ?>,
            discount: <?= json_encode($discount) ?>,
            payments: payments
        })
    })
    .then(r => {
        if (!r.ok) {
            throw new Error('Network response was not ok');
        }
        return r.json();
    })
    .then(data => {
        if (data.success) {
            // Check for fiscalization error first
            if (data.fiscalization_error) {
                // Sale succeeded but fiscalization failed - show warning
                const errorMsg = formatZimraError(data.fiscalization_error);
                Swal.fire({
                    title: 'Sale Completed - Fiscalization Warning',
                    html: '<div style="text-align: left;">' +
                          '<p><strong>The sale was processed successfully, but fiscalization with ZIMRA failed:</strong></p>' +
                          '<div style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto;">' +
                          errorMsg +
                          '</div>' +
                          '<p style="margin-top: 15px; color: #856404;"><strong>⚠️ Important:</strong> The receipt was created but may not be valid for tax purposes. Please contact support or try to fiscalize manually.</p>' +
                          '</div>',
                    icon: 'warning',
                    confirmButtonText: 'OK',
                    width: '600px',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then(() => {
                    // Clear session
                    fetch('<?= BASE_URL ?>ajax/clear_pos_cart.php');
                    
                    // Print receipt automatically
                    if (data.receipt_id) {
                        // Open receipt in new window for printing with print parameter
                        const receiptWindow = window.open('<?= BASE_URL ?>modules/pos/receipt.php?id=' + data.receipt_id + '&print=1', '_blank');
                        
                        // Redirect to index.php
                        window.location.href = 'index.php';
                    } else {
                        window.location.href = 'index.php';
                    }
                });
            } else {
                // No fiscalization error - proceed normally
                // Clear session
                fetch('<?= BASE_URL ?>ajax/clear_pos_cart.php');
                
                // Print receipt automatically
                if (data.receipt_id) {
                    // Show success message first (especially if fiscalization was successful)
                    const fiscalizedMsg = data.fiscal_details && data.fiscal_details.fiscalized 
                        ? '<p style="color: #28a745;"><strong>✓ Receipt fiscalized successfully with ZIMRA</strong></p>' 
                        : '';
                    
                    // Open receipt window immediately (before SweetAlert to avoid popup blocker)
                    const receiptWindow = window.open('<?= BASE_URL ?>modules/pos/receipt.php?id=' + data.receipt_id + '&print=1', '_blank');
                    
                    // Check if window was blocked
                    if (!receiptWindow || receiptWindow.closed || typeof receiptWindow.closed == 'undefined') {
                        // Popup was blocked - show message and redirect to receipt page
                        Swal.fire({
                            title: 'Success!',
                            html: (data.fiscal_details && data.fiscal_details.fiscalized 
                                ? '<p style="color: #28a745;"><strong>✓ Receipt fiscalized successfully with ZIMRA</strong></p>' 
                                : '') + '<p>Payment processed successfully. Please allow popups to view receipt automatically, or click OK to view receipt.</p>',
                            icon: 'success',
                            confirmButtonText: 'View Receipt',
                            showCancelButton: true,
                            cancelButtonText: 'Continue'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = '<?= BASE_URL ?>modules/pos/receipt.php?id=' + data.receipt_id + '&print=1';
                            } else {
                                window.location.href = 'index.php';
                            }
                        });
                    } else {
                        // Window opened successfully
                        Swal.fire({
                            title: 'Success!',
                            html: (data.fiscal_details && data.fiscal_details.fiscalized 
                                ? '<p style="color: #28a745;"><strong>✓ Receipt fiscalized successfully with ZIMRA</strong></p>' 
                                : '') + '<p>Payment processed successfully. Receipt is opening...</p>',
                            icon: 'success',
                            timer: 2000,
                            timerProgressBar: true,
                            showConfirmButton: false,
                            allowOutsideClick: true
                        }).then(() => {
                            // Redirect to index.php
                            window.location.href = 'index.php';
                        });
                    }
                } else {
                    Swal.fire('Success', 'Payment processed successfully', 'success').then(() => {
                        window.location.href = 'index.php';
                    });
                }
            }
        } else {
            Swal.fire({
                title: 'Error',
                text: data.message || 'Failed to process payment. Please try again.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    })
    .catch(error => {
        console.error('Payment processing error:', error);
        Swal.fire({
            title: 'Error',
            text: 'An error occurred while processing payment: ' + error.message,
            icon: 'error',
            confirmButtonText: 'OK'
        });
    });
}

function showReceiptOptions(receiptId) {
    Swal.fire({
        title: 'Send Receipt',
        html: `
            <div class="mb-3">
                <label>Email</label>
                <input type="email" id="receiptEmail" class="form-control" placeholder="customer@example.com">
            </div>
            <div class="mb-3">
                <label>WhatsApp</label>
                <input type="tel" id="receiptWhatsApp" class="form-control" placeholder="+263771234567">
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Send',
        preConfirm: () => {
            const email = document.getElementById('receiptEmail').value;
            const whatsapp = document.getElementById('receiptWhatsApp').value;
            
            if (!email && !whatsapp) {
                Swal.showValidationMessage('Please provide email or WhatsApp number');
                return false;
            }
            
            return {email, whatsapp};
        }
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>ajax/send_receipt.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    receipt_id: receiptId,
                    email: result.value.email,
                    whatsapp: result.value.whatsapp
                })
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    Swal.fire('Success', 'Receipt sent successfully', 'success').then(() => {
                        window.location.href = 'index.php';
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

// Ensure functions are available globally immediately
window.selectPaymentMethod = selectPaymentMethod;
window.toggleSplitPayment = toggleSplitPayment;
window.keypadInput = keypadInput;
window.keypadBackspace = keypadBackspace;
window.keypadClear = keypadClear;
window.processCharge = processCharge;
window.changeSplitCount = changeSplitCount;
window.updateSplitMethod = updateSplitMethod;
window.updateSplitAmount = updateSplitAmount;
window.removeSplitPayment = removeSplitPayment;
window.processSplitPayment = processSplitPayment;
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


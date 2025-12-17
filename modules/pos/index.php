<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('pos.access');

$pageTitle = 'New Sales';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    redirectTo('login.php');
}

// Clear discount if cart is empty (new sale)
$sessionCart = $_SESSION['pos_cart'] ?? [];
if (empty($sessionCart)) {
    unset($_SESSION['pos_discount']);
    unset($_SESSION['pos_customer']);
}

// Get POS settings
$homeLayout = getSetting('pos_home_layout', 'grid');
$cartLayout = getSetting('pos_cart_layout', 'increase_qty');

// Check if shifts table exists, create if not
$tableExists = $db->getRow("SHOW TABLES LIKE 'shifts'");
if (!$tableExists) {
    // Create shifts table if it doesn't exist
    $createTableSql = "CREATE TABLE IF NOT EXISTS `shifts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `shift_number` int(11) NOT NULL,
        `branch_id` int(11) DEFAULT 0,
        `user_id` int(11) NOT NULL,
        `opened_at` datetime NOT NULL,
        `closed_at` datetime DEFAULT NULL,
        `opened_by` int(11) NOT NULL,
        `closed_by` int(11) DEFAULT NULL,
        `starting_cash` decimal(10,2) DEFAULT 0.00,
        `expected_cash` decimal(10,2) DEFAULT 0.00,
        `actual_cash` decimal(10,2) DEFAULT NULL,
        `difference` decimal(10,2) DEFAULT 0.00,
        `status` enum('open','closed') DEFAULT 'open',
        `notes` text DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_branch_id` (`branch_id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->executeQuery($createTableSql);
}

// Get current shift - handle null branch_id
if ($branchId) {
    $currentShift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
        ':branch_id' => $branchId,
        ':user_id' => $userId
    ]);
} else {
    // If no branch_id, check for any open shift for this user (branch_id = 0 or NULL)
    $currentShift = $db->getRow("SELECT * FROM shifts WHERE (branch_id = 0 OR branch_id IS NULL) AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
        ':user_id' => $userId
    ]);
}

// If no shift, redirect to start shift page
if (!$currentShift) {
    redirectTo('modules/pos/start_shift.php');
}

// Ensure is_trade_in column exists and update existing trade-in products
try {
    $columns = $db->getRows("SHOW COLUMNS FROM products WHERE Field = 'is_trade_in'");
    if (empty($columns)) {
        // Column doesn't exist, add it
        $pdo = $db->getPdo();
        try {
            $pdo->exec("ALTER TABLE `products` ADD COLUMN `is_trade_in` tinyint(1) DEFAULT 0");
            $pdo->exec("ALTER TABLE `products` ADD KEY `idx_is_trade_in` (`is_trade_in`)");
        } catch (Exception $e) {
            // Column might already exist, ignore
        }
    }
    
    // Update existing trade-in products (run once, but safe to run multiple times)
    // Products from stock_movements with Trade-In type
    $db->executeQuery("
        UPDATE products p
        INNER JOIN stock_movements sm ON p.id = sm.product_id
        SET p.is_trade_in = 1
        WHERE sm.movement_type = 'Trade-In' 
        AND (p.is_trade_in = 0 OR p.is_trade_in IS NULL)
        LIMIT 100
    ");
    
    // Products with trade-in descriptions
    $db->executeQuery("
        UPDATE products
        SET is_trade_in = 1
        WHERE (description LIKE 'Trade-in device:%' 
           OR description LIKE 'Trade-In Device%'
           OR description LIKE 'Trade-In:%')
        AND (is_trade_in = 0 OR is_trade_in IS NULL)
        LIMIT 100
    ");
    
    // Products from sale_items with Trade-In prefix
    $db->executeQuery("
        UPDATE products p
        INNER JOIN sale_items si ON p.id = si.product_id
        SET p.is_trade_in = 1
        WHERE si.product_name LIKE 'Trade-In:%'
        AND (p.is_trade_in = 0 OR p.is_trade_in IS NULL)
        LIMIT 100
    ");
} catch (Exception $e) {
    // Silently fail - don't break the page if update fails
    error_log("Trade-in product update error: " . $e->getMessage());
}

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
$favorites = $db->getRows("SELECT p.* FROM products p INNER JOIN product_favorites pf ON p.id = pf.product_id WHERE pf.user_id = :user_id AND p.status = 'Active'", [':user_id' => $_SESSION['user_id']]) ?: [];

// Get customers and products for trade-in modal
$customers = $db->getRows("SELECT * FROM customers WHERE status = 'Active' ORDER BY first_name, last_name");
$products = $db->getRows("SELECT * FROM products WHERE status = 'Active' ORDER BY brand, model");

require_once APP_PATH . '/includes/header.php';
?>

<style>
/* Searchable dropdown styles */
#tradeInCustomerDropdown,
#posDeviceCategoryDropdown,
#tradeInProductDropdown {
    max-height: 300px;
    overflow-y: auto;
    z-index: 1050;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

#tradeInCustomerDropdown .dropdown-item,
#posDeviceCategoryDropdown .dropdown-item,
#tradeInProductDropdown .dropdown-item {
    padding: 10px 15px;
    cursor: pointer;
    white-space: normal;
    word-wrap: break-word;
}

#tradeInCustomerDropdown .dropdown-item:hover,
#posDeviceCategoryDropdown .dropdown-item:hover,
#tradeInProductDropdown .dropdown-item:hover {
    background-color: #f8f9fa;
}

#tradeInCustomerDropdown .dropdown-item:active,
#posDeviceCategoryDropdown .dropdown-item:active,
#tradeInProductDropdown .dropdown-item:active {
    background-color: var(--primary-blue);
    color: white;
}

.pos-container {
    display: flex;
    height: calc(100vh - 80px);
    gap: 20px;
    position: relative;
}

.pos-left {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: white;
    border-radius: 12px;
    padding: 20px;
    overflow: hidden;
    min-width: 0; /* Important for flexbox */
}

.pos-right {
    width: 400px;
    background: white;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex-shrink: 0;
}

/* ========== MOBILE RESPONSIVE STYLES ========== */

/* Tablet and below (max-width: 1024px) */
@media (max-width: 1024px) {
    .pos-container {
        gap: 15px;
    }
    
    .pos-right {
        width: 380px;
    }
    
    .product-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    }
}

/* Mobile (max-width: 768px) */
@media (max-width: 768px) {
    .pos-container {
        flex-direction: column;
        height: auto;
        min-height: calc(100vh - 60px);
        gap: 0;
        padding: 0;
    }
    
    .pos-left {
        flex: 1;
        min-height: 0;
        padding: 15px;
        border-radius: 0;
        order: 1;
    }
    
    .pos-right {
        width: 100%;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 75vh;
        max-height: 75vh;
        border-radius: 20px 20px 0 0;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
        z-index: 1000;
        transform: translateY(calc(100% - 60px));
        transition: transform 0.3s ease;
        overflow-y: auto;
        padding: 20px;
        order: 2;
    }
    
    .pos-right.cart-open {
        transform: translateY(0);
    }
    
    .cart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .cart-header h5 {
        margin: 0;
        flex: 1;
        font-size: 18px;
    }
    
    .cart-header > div {
        display: flex;
        gap: 5px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .cart-header .btn {
        padding: 8px 12px;
        font-size: 14px;
    }
    
    .cart-toggle-btn {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: var(--primary-blue);
        color: white;
        border: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 1001;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        cursor: pointer;
    }
    
    .cart-toggle-btn .badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #ef4444;
        color: white;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: bold;
    }
    
    .category-tabs {
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 15px;
    }
    
    .category-tab {
        font-size: 12px;
        padding: 8px 12px;
    }
    
    .product-grid {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 10px;
    }
    
    .product-card {
        padding: 10px;
    }
    
    .product-card img {
        height: 80px;
    }
    
    .product-name {
        font-size: 13px;
    }
    
    .product-price {
        font-size: 14px;
    }
    
    .search-bar input {
        padding: 12px 45px 12px 15px;
        font-size: 14px;
    }
    
    .cart-item {
        font-size: 14px;
        padding: 10px;
    }
    
    .cart-item-name {
        font-size: 14px;
    }
    
    .cart-item-price {
        font-size: 12px;
    }
    
    .btn-action {
        padding: 12px;
        font-size: 14px;
    }
}

/* Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .pos-left {
        padding: 10px;
    }
    
    .pos-right {
        padding: 15px;
        height: 80vh;
        max-height: 80vh;
    }
    
    .cart-header h5 {
        font-size: 16px;
    }
    
    .category-tab {
        font-size: 11px;
        padding: 6px 10px;
    }
    
    .product-grid {
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 8px;
    }
    
    .product-card {
        padding: 8px;
    }
    
    .product-card img {
        height: 60px;
    }
    
    .product-name {
        font-size: 11px;
        margin-top: 5px;
    }
    
    .product-price {
        font-size: 12px;
        margin-top: 3px;
    }
    
    .search-bar input {
        padding: 10px 40px 10px 12px;
        font-size: 13px;
    }
    
    .cart-item {
        font-size: 12px;
        padding: 8px;
    }
    
    .cart-item-name {
        font-size: 12px;
    }
    
    .cart-item-price {
        font-size: 11px;
    }
    
    .btn-action {
        padding: 10px;
        font-size: 12px;
    }
    
    .cart-toggle-btn {
        width: 55px;
        height: 55px;
        bottom: 15px;
        right: 15px;
        font-size: 20px;
    }
}

.search-bar {
    margin-bottom: 20px;
    position: relative;
}

.search-bar input {
    width: 100%;
    padding: 15px 50px 15px 20px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 16px;
}

.search-bar i {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
}

.category-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.category-tab {
    padding: 10px 20px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    transition: all 0.3s;
    font-weight: 500;
}

.category-tab.active {
    background: var(--primary-blue);
    color: white;
    border-color: var(--primary-blue);
}

.product-grid {
    flex: 1;
    overflow-y: auto;
    display: grid;
    gap: 15px;
    padding-right: 10px;
}

/* Apply layout based on setting */
<?php if ($homeLayout == 'grid'): ?>
.product-grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
}
<?php elseif ($homeLayout == 'simple-grid'): ?>
.product-grid {
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
}
.product-card {
    padding: 12px;
    height: 200px;
    max-height: 200px;
}
.product-card .product-image {
    height: 100px;
    margin-bottom: 8px;
}
.product-card .product-name {
    font-size: 13px;
    margin-bottom: 6px;
}
.product-card .product-price {
    font-size: 14px;
    background: var(--primary-blue);
    color: white;
}
<?php elseif ($homeLayout == 'list'): ?>
.product-grid {
    grid-template-columns: 1fr;
}
.product-card {
    flex-direction: row;
    height: auto;
    max-height: none;
    text-align: left;
    padding: 15px;
}
.product-card .product-image {
    width: 120px;
    height: 120px;
    flex-shrink: 0;
    margin-right: 15px;
    margin-bottom: 0;
}
.product-card .product-name {
    flex: 1;
    margin: 0;
    max-height: none;
}
.product-card .product-price {
    margin-top: 0;
    margin-left: auto;
    background: var(--primary-blue);
    color: white;
}
<?php elseif ($homeLayout == 'retail'): ?>
.product-grid {
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 20px;
}
.product-card {
    padding: 20px;
    height: 280px;
    max-height: 280px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border: 2px solid #e5e7eb;
}
.product-card:hover {
    box-shadow: 0 8px 12px rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}
.product-card .product-image {
    height: 140px;
    margin-bottom: 12px;
    border-radius: 8px;
}
.product-card .product-name {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 8px;
    line-height: 1.4;
}
.product-card .product-price {
    font-size: 18px;
    font-weight: 700;
    background: var(--primary-blue);
    color: white;
    padding: 10px 15px;
}
.product-card .product-stock {
    font-size: 12px;
    margin-top: 6px;
}
<?php endif; ?>

@media (max-width: 768px) {
    <?php if ($homeLayout == 'retail'): ?>
    .product-grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 15px;
    }
    .product-card {
        height: 240px;
        max-height: 240px;
        padding: 15px;
    }
    .product-card .product-image {
        height: 120px;
    }
    <?php else: ?>
    .product-grid {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 10px;
    }
    <?php endif; ?>
}

@media (max-width: 576px) {
    <?php if ($homeLayout == 'retail'): ?>
    .product-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .product-card {
        height: 220px;
        max-height: 220px;
        padding: 12px;
    }
    .product-card .product-image {
        height: 100px;
    }
    <?php else: ?>
    .product-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
    <?php endif; ?>
}

.product-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 15px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
    height: 220px;
    max-height: 220px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

@media (max-width: 768px) {
    .product-card {
        height: 200px;
        max-height: 200px;
        padding: 12px;
    }
}

@media (max-width: 576px) {
    .product-card {
        height: 180px;
        max-height: 180px;
        padding: 10px;
    }
}

.product-card:hover {
    border-color: var(--primary-blue);
    transform: translateY(-5px);
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
}

.product-image {
    width: 100%;
    height: 100px;
    background: #f3f4f6;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    font-size: 40px;
    overflow: hidden;
    position: relative;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-image .placeholder-icon {
    color: #9ca3af;
}

.product-price {
    background: var(--primary-blue);
    color: white;
    padding: 8px 12px;
    border-radius: 8px;
    font-weight: 600;
    margin-top: auto;
    font-size: 14px;
    transition: all 0.3s;
}

.product-card:hover .product-price {
    background: var(--dark-navy);
    transform: scale(1.05);
}

.product-name {
    font-size: 13px;
    margin: 5px 0;
    color: var(--text-dark);
    word-wrap: break-word;
    overflow-wrap: break-word;
    hyphens: auto;
    line-height: 1.3;
    flex: 1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    min-height: 2.6em;
    max-height: 2.6em;
    align-items: center;
    justify-content: center;
}

@media (max-width: 576px) {
    .product-name {
        font-size: 11px;
        min-height: 2.4em;
    }
}

.product-info {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(0,0,0,0.6);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    cursor: pointer;
    z-index: 10;
    transition: all 0.3s;
}

.product-info:hover {
    background: var(--primary-blue);
    transform: scale(1.1);
}

.product-favorite {
    position: absolute;
    top: 10px;
    left: 10px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(0,0,0,0.6);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    cursor: pointer;
    z-index: 10;
    transition: all 0.3s;
}

.product-favorite:hover {
    background: #ef4444;
    transform: scale(1.1);
}

.product-favorite.active {
    background: #ef4444;
    color: #fef2f2;
}

.product-trade-in-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
    z-index: 10;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    white-space: nowrap;
}

.product-trade-in-badge i {
    font-size: 10px;
}

.cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e5e7eb;
}

.cart-items {
    flex: 1;
    overflow-y: auto;
    margin-bottom: 20px;
}

.cart-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #f9fafb;
    border-radius: 8px;
    margin-bottom: 10px;
}

.cart-item-info {
    flex: 1;
}

.cart-item-name {
    font-weight: 600;
    margin-bottom: 5px;
}

.cart-item-price {
    color: var(--text-muted);
    font-size: 14px;
}

.cart-item-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.qty-control {
    display: flex;
    align-items: center;
    gap: 8px;
    background: white;
    padding: 5px 8px;
    border-radius: 6px;
}

.qty-input {
    width: 60px;
    height: 30px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    text-align: center;
    font-size: 14px;
    font-weight: 600;
    padding: 0 5px;
}

.qty-input:focus {
    outline: none;
    border-color: var(--primary-blue);
    box-shadow: 0 0 0 2px rgba(30, 58, 138, 0.1);
}

.qty-btn {
    width: 30px;
    height: 30px;
    border: none;
    background: var(--primary-blue);
    color: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.cart-summary {
    border-top: 2px solid #e5e7eb;
    padding-top: 20px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    font-size: 16px;
}

.summary-row.total {
    font-size: 24px;
    font-weight: 700;
    color: var(--primary-blue);
    padding-top: 10px;
    border-top: 2px solid #e5e7eb;
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.btn-action {
    flex: 1;
    padding: 15px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-discount {
    background: #f59e0b;
    color: white;
}

.btn-discount:hover {
    background: #d97706;
}

.btn-charge {
    background: var(--primary-blue);
    color: white;
    font-size: 18px;
}

.btn-charge:hover {
    background: var(--dark-navy);
}

.btn-customer {
    background: #10b981;
    color: white;
}

.btn-customer:hover {
    background: #059669;
}

.touch-friendly {
    min-height: 50px;
    min-width: 50px;
}

/* Trade-in product search dropdown */
#tradeInProductDropdown {
    max-height: 300px;
    overflow-y: auto;
    z-index: 1050;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

#tradeInProductDropdown .dropdown-item {
    padding: 10px 15px;
    cursor: pointer;
    white-space: normal;
    word-wrap: break-word;
}

#tradeInProductDropdown .dropdown-item:hover {
    background-color: #f8f9fa;
}

#tradeInProductDropdown .dropdown-item:active {
    background-color: var(--primary-blue);
    color: white;
}
</style>

<?php if (!$currentShift): ?>
<div class="alert alert-warning text-center">
    <h4>No Active Shift</h4>
    <p>Please start a shift to begin processing sales.</p>
</div>
<?php endif; ?>

<div class="pos-container" <?= !$currentShift ? 'style="pointer-events: none; opacity: 0.5;"' : '' ?>>
    <!-- Mobile Cart Toggle Button -->
    <button class="cart-toggle-btn" id="cartToggleBtn" onclick="toggleCart()" style="display: none;">
        <i class="bi bi-cart"></i>
        <span class="badge" id="cartToggleBadge" style="display: none;">0</span>
    </button>
    
    <div class="pos-left">
        <?php if ($currentShift): ?>
            <div class="mb-3">
                <button class="btn btn-danger btn-sm" onclick="showEndShiftModal()">
                    <i class="bi bi-stop-circle"></i> End Shift
                </button>
            </div>
        <?php endif; ?>
        <div class="search-bar">
            <input type="text" id="productSearch" placeholder="Search All or Scan Barcode" autofocus>
            <i class="bi bi-search"></i>
            <i class="bi bi-upc-scan" style="margin-left: 10px; cursor: pointer;" title="Barcode Scanner" onclick="focusBarcodeInput()"></i>
        </div>
        <div class="barcode-input-container" style="margin-bottom: 10px; display: none;" id="barcodeContainer">
            <input type="text" id="barcodeInput" class="form-control" placeholder="Scan or enter barcode..." autocomplete="off">
        </div>
        
        <div class="category-tabs">
            <div class="category-tab active" data-category="all">All</div>
            <div class="category-tab" data-category="favorite">Favorite</div>
            <div class="category-tab" data-category="trade-in">Trade-In</div>
            <?php foreach ($categories as $category): ?>
                <div class="category-tab" data-category="<?= $category['id'] ?>"><?= escapeHtml($category['name']) ?></div>
            <?php endforeach; ?>
            <div class="category-tab" data-category="no-category">No category</div>
        </div>
        
        <div class="product-grid" id="productGrid">
            <?php 
            $products = $db->getRows("SELECT p.*, pc.name as category_name, COALESCE(p.is_trade_in, 0) as is_trade_in FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.status = 'Active' AND p.quantity_in_stock > 0 ORDER BY COALESCE(p.product_name, p.brand), p.model");
            $favoriteIds = array_column($favorites, 'id');
            foreach ($products as $product): 
                $isFavorite = in_array($product['id'], $favoriteIds);
                $productImage = !empty($product['images']) ? json_decode($product['images'], true)[0] ?? null : null;
                $productDisplayName = !empty($product['product_name']) ? $product['product_name'] : ($product['brand'] . ' ' . $product['model']);
            ?>
                <div class="product-card" 
                     data-product-id="<?= $product['id'] ?>" 
                     data-category-id="<?= $product['category_id'] ?? 'no-category' ?>" 
                     data-product-name="<?= escapeHtml($productDisplayName) ?>"
                     data-product-price="<?= $product['selling_price'] ?>"
                     data-product-stock="<?= $product['quantity_in_stock'] ?>"
                     data-product-barcode="<?= escapeHtml($product['barcode'] ?? '') ?>"
                     data-is-trade-in="<?= ($product['is_trade_in'] ?? 0) ? '1' : '0' ?>"
                     onclick="addToCartFromCard(this)">
                    <div class="product-info" onclick="event.stopPropagation(); showProductInfo(<?= $product['id'] ?>)">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="product-favorite <?= $isFavorite ? 'active' : '' ?>" onclick="event.stopPropagation(); toggleFavorite(<?= $product['id'] ?>, this)">
                        <i class="bi bi-<?= $isFavorite ? 'heart-fill' : 'heart' ?>"></i>
                    </div>
                    <?php if ($product['is_trade_in'] ?? 0): ?>
                    <div class="product-trade-in-badge">
                        <i class="bi bi-arrow-repeat"></i> Trade-In
                    </div>
                    <?php endif; ?>
                    <div class="product-image">
                        <?php if ($productImage): ?>
                            <img src="<?= escapeHtml($productImage) ?>" alt="<?= escapeHtml($productDisplayName) ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="placeholder-icon" style="display: none;">
                                <?php if (!empty($product['color']) && $product['color'] !== '#ffffff' && $product['color'] !== 'white'): ?>
                                    <div style="width: 100%; height: 100%; background-color: <?= escapeHtml($product['color']) ?>; display: flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-box-seam" style="font-size: 48px; color: rgba(0,0,0,0.3);"></i>
                                    </div>
                                <?php else: ?>
                                    <i class="bi bi-box-seam"></i>
                                <?php endif; ?>
                            </div>
                        <?php elseif (!empty($product['color']) && $product['color'] !== '#ffffff' && $product['color'] !== 'white'): ?>
                            <div class="placeholder-icon" style="width: 100%; height: 100%; background-color: <?= escapeHtml($product['color']) ?>; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-box-seam" style="font-size: 48px; color: rgba(0,0,0,0.3);"></i>
                            </div>
                        <?php else: ?>
                            <div class="placeholder-icon"><i class="bi bi-box-seam"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="product-name"><?= escapeHtml($productDisplayName) ?></div>
                    <div class="product-price"><?= formatCurrency($product['selling_price']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Mobile Cart Toggle Button -->
    <button class="cart-toggle-btn" id="cartToggleBtn" onclick="toggleCart()" style="display: none;">
        <i class="bi bi-cart"></i>
        <span class="badge" id="cartToggleBadge" style="display: none;">0</span>
    </button>
    
    <div class="pos-right" id="posRight">
        <div class="cart-header">
            <h5>Order</h5>
            <button class="btn btn-sm btn-close d-md-none" onclick="toggleCart()" style="display: none; margin-left: auto; background: none; border: none; font-size: 20px; color: var(--text-dark); padding: 0; width: 30px; height: 30px;">
                <i class="bi bi-x-lg"></i>
            </button>
            <div>
                <button class="btn btn-sm btn-customer touch-friendly" onclick="selectCustomer()" title="Select Customer">
                    <i class="bi bi-person-plus"></i>
                </button>
                <button class="btn btn-sm touch-friendly" onclick="applyDiscount()" title="Discount" style="background: #f59e0b; color: white; border: none;">
                    <i class="bi bi-percent"></i>
                </button>
                <button class="btn btn-sm touch-friendly" onclick="resetSale()" title="Reset Sale" style="background: #6b7280; color: white;">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button class="btn btn-sm btn-warning touch-friendly" onclick="showTradeInModal()" title="Trade-In">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
                <?php if ($currentShift): ?>
                <button class="btn btn-sm btn-danger touch-friendly" onclick="showEndShiftModal()" title="End Shift">
                    <i class="bi bi-stop-circle"></i>
                </button>
                <?php endif; ?>
                <span class="badge bg-primary" id="cartCount">0</span>
            </div>
        </div>
        
        <div class="cart-items" id="cartItems">
            <div class="text-center text-muted py-5">Cart is empty</div>
        </div>
        
        <div class="cart-summary">
            <div class="summary-row">
                <span>Subtotal:</span>
                <span id="subtotal"><?= formatCurrency(0) ?></span>
            </div>
            <div class="summary-row" id="discountRow" style="display: none;">
                <span>Discount:</span>
                <span id="discountAmountDisplay" class="text-danger">-<?= formatCurrency(0) ?></span>
                <button class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="removeDiscount()" title="Remove Discount" style="font-size: 12px;">
                    <i class="bi bi-x-circle"></i>
                </button>
            </div>
            <div class="summary-row total">
                <span>Total:</span>
                <span id="total"><?= formatCurrency(0) ?></span>
            </div>
            
            <div class="action-buttons">
                <button class="btn-action btn-discount touch-friendly" onclick="applyDiscount()">
                    <i class="bi bi-percent"></i> Discount
                </button>
                <button class="btn-action btn-charge touch-friendly" onclick="processPayment()">
                    <i class="bi bi-cash-coin"></i> Charge
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Customer Selection Modal -->
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" class="form-control" id="customerSearch" placeholder="Search">
                </div>
                <button class="btn btn-primary w-100 mb-3" onclick="createCustomer()">
                    <i class="bi bi-person-plus"></i> Create Customer
                </button>
                <div id="customerList" style="max-height: 400px; overflow-y: auto;">
                    <!-- Customer list will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Trade-In Modal -->
<div class="modal fade" id="tradeInModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Trade-In</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <form id="tradeInForm">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="mb-3">Device Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Customer</label>
                                    <div class="position-relative">
                                        <input type="text" 
                                               class="form-control" 
                                               id="tradeInCustomerSearch" 
                                               placeholder="Type to search customers or leave empty for Walk-in..."
                                               autocomplete="off"
                                               value="">
                                        <input type="hidden" name="customer_id" id="tradeInCustomer" value="">
                                        <div class="dropdown-menu position-absolute w-100" id="tradeInCustomerDropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                                            <a class="dropdown-item trade-in-customer-item" 
                                               href="#" 
                                               data-id=""
                                               data-text="Walk-in Customer">
                                                Walk-in Customer
                                            </a>
                                            <?php foreach ($customers as $customer): ?>
                                                <a class="dropdown-item trade-in-customer-item" 
                                                   href="#" 
                                                   data-id="<?= $customer['id'] ?>"
                                                   data-text="<?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?>">
                                                    <?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Device Category *</label>
                                    <div class="position-relative">
                                        <input type="text" 
                                               class="form-control" 
                                               id="posDeviceCategorySearch" 
                                               placeholder="Type to search categories..."
                                               autocomplete="off"
                                               required>
                                        <input type="hidden" name="device_category" id="posDeviceCategory" required>
                                        <div class="dropdown-menu position-absolute w-100" id="posDeviceCategoryDropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                                            <?php 
                                            $categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
                                            foreach ($categories as $category): 
                                            ?>
                                                <a class="dropdown-item trade-in-category-item" 
                                                   href="#" 
                                                   data-value="<?= escapeHtml($category['name']) ?>"
                                                   data-text="<?= escapeHtml($category['name']) ?>">
                                                    <?= escapeHtml($category['name']) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Device Brand *</label>
                                    <input type="text" class="form-control" name="device_brand" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Device Model *</label>
                                    <input type="text" class="form-control" name="device_model" required>
                                </div>
                                <div class="col-md-6 mb-3" id="posColorField" style="display: none;">
                                    <label class="form-label">Color</label>
                                    <input type="text" class="form-control" name="device_color">
                                </div>
                                <div class="col-md-6 mb-3" id="posStorageField" style="display: none;">
                                    <label class="form-label">Storage</label>
                                    <input type="text" class="form-control" name="device_storage">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        Condition *
                                        <i class="bi bi-info-circle text-primary ms-1" style="cursor: pointer;" onclick="showConditionInfoModal()" title="Click for condition descriptions"></i>
                                    </label>
                                    <select class="form-select" name="device_condition" required>
                                        <option value="A+">A+ (Excellent)</option>
                                        <option value="A">A (Very Good)</option>
                                        <option value="B" selected>B (Good)</option>
                                        <option value="C">C (Fair)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3" id="posBatteryHealthField" style="display: none;">
                                    <label class="form-label">Battery Health (%)</label>
                                    <input type="number" class="form-control" name="battery_health" min="0" max="100">
                                </div>
                                <div class="col-md-6 mb-3" id="posSerialNumberField" style="display: none;">
                                    <label class="form-label">Serial Number</label>
                                    <input type="text" class="form-control" name="serial_number">
                                </div>
                                <div class="col-md-6 mb-3" id="posImeiField" style="display: none;">
                                    <label class="form-label">IMEI</label>
                                    <input type="text" class="form-control" name="imei" maxlength="15">
                                </div>
                                <div class="col-md-6 mb-3" id="posSimConfigField" style="display: none;">
                                    <label class="form-label">SIM Configuration</label>
                                    <select class="form-select" name="sim_configuration">
                                        <option value="">Select</option>
                                        <option value="Single SIM">Single SIM</option>
                                        <option value="Dual SIM">Dual SIM</option>
                                        <option value="eSIM">eSIM</option>
                                        <option value="Dual SIM + eSIM">Dual SIM + eSIM</option>
                                    </select>
                                </div>
                            </div>
                            
                            <h6 class="mb-3 mt-4">Product Details (For Stock Entry)</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Cost Price *</label>
                                    <input type="number" class="form-control" name="cost_price" step="0.01" min="0" value="0" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Selling Price *</label>
                                    <input type="number" class="form-control" name="selling_price" step="0.01" min="0" value="0" required>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="description" rows="2" placeholder="Product description..."></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Specifications</label>
                                    <textarea class="form-control" name="specifications" rows="2" placeholder="Technical specifications..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="mb-3">Assessment & Valuation</h6>
                            <div class="mb-3">
                                <label class="form-label">Cosmetic Issues</label>
                                <textarea class="form-control" name="cosmetic_issues" rows="2"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Functional Issues</label>
                                <textarea class="form-control" name="functional_issues" rows="2"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Accessories Included</label>
                                <textarea class="form-control" name="accessories_included" rows="2"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Date of First Use</label>
                                <input type="date" class="form-control" name="date_of_first_use" id="posDateOfFirstUse" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Manual Valuation</label>
                                <input type="number" class="form-control" name="manual_valuation" step="0.01" min="0" value="0">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Final Valuation *</label>
                                <input type="number" class="form-control" name="final_valuation" id="posFinalValuation" step="0.01" min="0" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Valuation Notes</label>
                                <textarea class="form-control" name="valuation_notes" rows="2"></textarea>
                            </div>
                            
                            <h6 class="mb-3 mt-4">New Product Selection</h6>
                            <div class="mb-3">
                                <label class="form-label">Product They're Getting</label>
                                <div class="position-relative">
                                    <input type="text" 
                                           class="form-control" 
                                           id="tradeInProductSearch" 
                                           placeholder="Type to search products..."
                                           autocomplete="off">
                                    <input type="hidden" name="new_product_id" id="tradeInProduct">
                                    <div class="dropdown-menu position-absolute w-100" id="tradeInProductDropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                                        <?php foreach ($products as $product): ?>
                                            <a class="dropdown-item trade-in-product-item" 
                                               href="#" 
                                               data-id="<?= $product['id'] ?>"
                                               data-price="<?= $product['selling_price'] ?>"
                                               data-text="<?= escapeHtml($product['brand'] . ' ' . $product['model'] . ' - ' . formatCurrency($product['selling_price'])) ?>">
                                                <?= escapeHtml($product['brand'] . ' ' . $product['model'] . ' - ' . formatCurrency($product['selling_price'])) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div id="posProductDetails" style="display: none;">
                                <div class="alert alert-info">
                                    <strong>Product Price:</strong> <span id="posProductPrice">$0.00</span><br>
                                    <strong>Trade-In Value:</strong> <span id="posTradeInValue">$0.00</span><br>
                                    <strong>Balance to Pay:</strong> <span id="posBalanceToPay" class="fw-bold">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="processTradeInFromPOS()">Process Trade-In</button>
            </div>
        </div>
    </div>
</div>

<!-- Condition Info Modal -->
<div class="modal fade" id="conditionInfoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Device Condition Grades</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <h6 class="text-primary"><strong>A+ (Excellent)</strong></h6>
                    <ul class="mb-3">
                        <li>No scratches or visible wear</li>
                        <li>Good as new condition</li>
                        <li>Battery health almost 100% (95%+)</li>
                        <li>All functions working perfectly</li>
                        <li>Original packaging and accessories included</li>
                        <li>No cosmetic or functional issues</li>
                    </ul>
                </div>
                <div class="mb-3">
                    <h6 class="text-info"><strong>A (Very Good)</strong></h6>
                    <ul class="mb-3">
                        <li>Minor scratches or light wear</li>
                        <li>Very good overall condition</li>
                        <li>Battery health 85-94%</li>
                        <li>All functions working properly</li>
                        <li>May have minor cosmetic imperfections</li>
                        <li>Most accessories included</li>
                    </ul>
                </div>
                <div class="mb-3">
                    <h6 class="text-warning"><strong>B (Good)</strong></h6>
                    <ul class="mb-3">
                        <li>Noticeable scratches or wear</li>
                        <li>Good working condition</li>
                        <li>Battery health 70-84%</li>
                        <li>All essential functions working</li>
                        <li>Some cosmetic issues present</li>
                        <li>May be missing some accessories</li>
                    </ul>
                </div>
                <div class="mb-3">
                    <h6 class="text-danger"><strong>C (Fair)</strong></h6>
                    <ul class="mb-3">
                        <li>Significant scratches or wear</li>
                        <li>Fair condition with visible damage</li>
                        <li>Battery health below 70%</li>
                        <li>Some functions may have issues</li>
                        <li>Noticeable cosmetic and/or functional problems</li>
                        <li>Limited or no accessories</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Shift End Modal -->
<?php if ($currentShift): 
    // Calculate expected cash
    $cashSales = $db->getRow("SELECT COALESCE(SUM(sp.amount), 0) as total FROM sale_payments sp 
                               INNER JOIN sales s ON sp.sale_id = s.id 
                               WHERE s.shift_id = :shift_id AND LOWER(sp.payment_method) = 'cash'", 
                               [':shift_id' => $currentShift['id']]);
    $cashSalesTotal = $cashSales ? floatval($cashSales['total']) : 0;
    
    $payIns = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM drawer_transactions 
                            WHERE shift_id = :shift_id AND transaction_type = 'pay_in'", 
                            [':shift_id' => $currentShift['id']]);
    $payInsTotal = $payIns ? floatval($payIns['total']) : 0;
    
    $payOuts = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM drawer_transactions 
                             WHERE shift_id = :shift_id AND transaction_type = 'pay_out'", 
                             [':shift_id' => $currentShift['id']]);
    $payOutsTotal = $payOuts ? floatval($payOuts['total']) : 0;
    
    $expectedCash = $currentShift['starting_cash'] + $cashSalesTotal + $payInsTotal - $payOutsTotal;
?>
<div class="modal fade" id="shiftEndModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">End Shift</h5>
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
<?php endif; ?>

<!-- Discount Modal -->
<div class="modal fade" id="discountModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Total Discount</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="discountType" id="discountValue" value="value" checked>
                        <label class="form-check-label" for="discountValue">Value</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="discountType" id="discountPercentage" value="percentage">
                        <label class="form-check-label" for="discountPercentage">Percentage</label>
                    </div>
                </div>
                <div class="mb-3">
                    <input type="text" class="form-control form-control-lg text-end" id="discountAmount" value="0.00" readonly>
                </div>
                <div class="keypad">
                    <div class="row g-2">
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('1')">1</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('2')">2</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('3')">3</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadBackspace()"><i class="bi bi-backspace"></i></button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('4')">4</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('5')">5</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('6')">6</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly"></button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('7')">7</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('8')">8</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('9')">9</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly"></button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('.')">.</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadInput('0')">0</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly" onclick="discountKeypadClear()">CLEAR</button></div>
                        <div class="col-3"><button class="btn btn-light w-100 touch-friendly"></button></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary w-100" onclick="applyDiscountAmount()">ADD</button>
            </div>
        </div>
    </div>
</div>

<script>
let cart = [];
let selectedCustomer = null;
let discount = { type: null, amount: 0 };
// Only load discount if there's an active cart, otherwise reset it
let sessionCart = <?= json_encode($_SESSION['pos_cart'] ?? []) ?>;
let sessionDiscount = <?= json_encode($_SESSION['pos_discount'] ?? ['type' => null, 'amount' => 0]) ?>;

// If cart is empty, don't load discount (it should be cleared on server side)
if (sessionCart.length === 0) {
    discount = { type: null, amount: 0 };
} else {
    // Load discount only if cart exists
    discount = sessionDiscount;
    // Load cart and customer from session
    cart = sessionCart;
    selectedCustomer = <?= json_encode($_SESSION['pos_customer'] ?? null) ?>;
}

let currentCategory = 'all';
let allCustomers = []; // Store all loaded customers for filtering

// Initialize display on page load
document.addEventListener('DOMContentLoaded', function() {
    if (cart.length > 0) {
        updateCart(); // Update cart display if items exist
    } else {
        // Ensure discount is cleared if no cart
        discount = { type: null, amount: 0 };
        updateCart();
    }
});

// Category filtering
document.querySelectorAll('.category-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        currentCategory = this.dataset.category;
        filterProducts();
    });
});

function filterProducts() {
    const products = document.querySelectorAll('.product-card');
    const favoriteIds = <?= json_encode(!empty($favorites) ? array_column($favorites, 'id') : []) ?>;
    
    products.forEach(card => {
        const categoryId = card.dataset.categoryId || 'no-category';
        const productId = parseInt(card.dataset.productId);
        const isTradeIn = card.dataset.isTradeIn === '1';
        
        if (currentCategory === 'all') {
            card.style.display = 'block';
        } else if (currentCategory === 'favorite') {
            card.style.display = favoriteIds.includes(productId) ? 'block' : 'none';
        } else if (currentCategory === 'trade-in') {
            card.style.display = isTradeIn ? 'block' : 'none';
        } else if (currentCategory === 'no-category') {
            card.style.display = categoryId === 'no-category' ? 'block' : 'none';
        } else {
            card.style.display = categoryId == currentCategory ? 'block' : 'none';
        }
    });
}

// Barcode scanning functionality
let barcodeTimeout;
const barcodeInput = document.getElementById('barcodeInput');
const barcodeContainer = document.getElementById('barcodeContainer');

function focusBarcodeInput() {
    if (barcodeContainer) {
        barcodeContainer.style.display = barcodeContainer.style.display === 'none' ? 'block' : 'none';
        if (barcodeContainer.style.display === 'block' && barcodeInput) {
            barcodeInput.focus();
        }
    }
}

if (barcodeInput) {
    barcodeInput.addEventListener('input', function(e) {
        const barcode = this.value.trim();
        
        // Clear previous timeout
        clearTimeout(barcodeTimeout);
        
        // Wait for user to finish typing (barcode scanners typically send data quickly)
        barcodeTimeout = setTimeout(() => {
            if (barcode.length >= 3) { // Minimum barcode length
                // Find product by barcode
                const productCard = document.querySelector(`.product-card[data-product-barcode="${barcode}"]`);
                if (productCard) {
                    // Add to cart
                    addToCartFromCard(productCard);
                    // Clear barcode input
                    this.value = '';
                    // Show success feedback
                    const productName = productCard.dataset.productName;
                    Swal.fire({
                        icon: 'success',
                        title: 'Product Found',
                        text: productName + ' added to cart',
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Product Not Found',
                        text: 'No product found with barcode: ' + barcode,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    this.value = '';
                }
            }
        }, 500); // Wait 500ms after last keystroke
    });
    
    // Handle Enter key
    barcodeInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const barcode = this.value.trim();
            if (barcode.length >= 3) {
                clearTimeout(barcodeTimeout);
                const productCard = document.querySelector(`.product-card[data-product-barcode="${barcode}"]`);
                if (productCard) {
                    addToCartFromCard(productCard);
                    this.value = '';
                    const productName = productCard.dataset.productName;
                    Swal.fire({
                        icon: 'success',
                        title: 'Product Found',
                        text: productName + ' added to cart',
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Product Not Found',
                        text: 'No product found with barcode: ' + barcode,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    this.value = '';
                }
            }
        }
    });
}

// Search functionality
document.getElementById('productSearch').addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    const favoriteIds = <?= json_encode(!empty($favorites) ? array_column($favorites, 'id') : []) ?>;
    
    document.querySelectorAll('.product-card').forEach(card => {
        const text = card.textContent.toLowerCase();
        const categoryId = card.dataset.categoryId || 'no-category';
        const productId = parseInt(card.dataset.productId);
        const isTradeIn = card.dataset.isTradeIn === '1';
        
        // Check if matches search
        const matchesSearch = text.includes(search);
        
        // Check if matches current filter
        let matchesFilter = true;
        if (currentCategory === 'favorite') {
            matchesFilter = favoriteIds.includes(productId);
        } else if (currentCategory === 'trade-in') {
            matchesFilter = isTradeIn;
        } else if (currentCategory === 'no-category') {
            matchesFilter = categoryId === 'no-category';
        } else if (currentCategory !== 'all') {
            matchesFilter = categoryId == currentCategory;
        }
        
        card.style.display = (matchesSearch && matchesFilter) ? 'block' : 'none';
    });
});

// Cart functions
window.addToCartFromCard = function(cardElement) {
    const id = parseInt(cardElement.dataset.productId);
    const name = cardElement.dataset.productName;
    const price = parseFloat(cardElement.dataset.productPrice);
    const stock = parseInt(cardElement.dataset.productStock);
    addToCart(id, name, price, stock);
};

function addToCart(id, name, price, stock) {
    const existing = cart.find(item => item.id === id);
    const cartLayout = '<?= $cartLayout ?? "increase_qty" ?>';
    
    if (existing && cartLayout === 'increase_qty') {
        if (existing.quantity < stock) {
            existing.quantity++;
        } else {
            Swal.fire('Error', 'Insufficient stock', 'error');
            return;
        }
    } else {
        if (existing && cartLayout === 'multiple_line') {
            cart.push({id, name, price, quantity: 1, stock});
        } else if (!existing) {
            cart.push({id, name, price, quantity: 1, stock});
        } else {
            Swal.fire('Error', 'Insufficient stock', 'error');
            return;
        }
    }
    updateCart();
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCart();
}

function updateQuantity(index, change) {
    const item = cart[index];
    const newQty = item.quantity + change;
    if (newQty > 0 && newQty <= item.stock) {
        item.quantity = newQty;
        updateCart();
    } else if (newQty > item.stock) {
        Swal.fire('Error', 'Insufficient stock. Available: ' + item.stock, 'error');
    }
}

function setQuantity(index, value) {
    const item = cart[index];
    const newQty = parseInt(value) || 1;
    
    if (newQty < 1) {
        item.quantity = 1;
        updateCart();
        Swal.fire('Warning', 'Quantity must be at least 1', 'warning');
    } else if (newQty > item.stock) {
        item.quantity = item.stock;
        updateCart();
        Swal.fire('Error', 'Insufficient stock. Available: ' + item.stock, 'error');
    } else {
        item.quantity = newQty;
        updateCart();
    }
}

function validateQuantity(index, input) {
    const item = cart[index];
    const value = parseInt(input.value) || 1;
    
    if (value < 1) {
        input.value = 1;
        item.quantity = 1;
        updateCart();
    } else if (value > item.stock) {
        input.value = item.stock;
        item.quantity = item.stock;
        updateCart();
        Swal.fire('Error', 'Insufficient stock. Available: ' + item.stock, 'error');
    } else {
        item.quantity = value;
        updateCart();
    }
}

function updateCart() {
    const cartItems = document.getElementById('cartItems');
    const cartCount = document.getElementById('cartCount');
    const cartToggleBadge = document.getElementById('cartToggleBadge');
    cartCount.textContent = cart.length;
    
    // Update mobile cart toggle badge
    if (cartToggleBadge) {
        if (cart.length > 0) {
            cartToggleBadge.textContent = cart.length;
            cartToggleBadge.style.display = 'flex';
        } else {
            cartToggleBadge.style.display = 'none';
        }
    }
    
    if (cart.length === 0) {
        cartItems.innerHTML = '<div class="text-center text-muted py-5">Cart is empty</div>';
        document.getElementById('subtotal').textContent = '$0.00';
        document.getElementById('total').textContent = '$0.00';
        return;
    }
    
    let html = '';
    let subtotal = 0;
    
    cart.forEach((item, index) => {
        const lineTotal = item.price * item.quantity;
        subtotal += lineTotal;
        
        html += `
            <div class="cart-item">
                <div class="cart-item-info">
                    <div class="cart-item-name">${item.name}</div>
                    <div class="cart-item-price">${item.price.toFixed(2)} × ${item.quantity}</div>
                </div>
                <div class="cart-item-actions">
                    <div class="qty-control">
                        <button class="qty-btn touch-friendly" onclick="updateQuantity(${index}, -1)" title="Decrease">-</button>
                        <input type="number" class="qty-input" value="${item.quantity}" min="1" max="${item.stock}" 
                               onchange="setQuantity(${index}, this.value)" 
                               onblur="validateQuantity(${index}, this)"
                               data-index="${index}">
                        <button class="qty-btn touch-friendly" onclick="updateQuantity(${index}, 1)" title="Increase">+</button>
                    </div>
                    <button class="btn btn-sm btn-danger touch-friendly" onclick="removeFromCart(${index})" title="Remove">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        `;
    });
    
    cartItems.innerHTML = html;
    
    // Calculate totals
    let discountAmount = 0;
    if (discount.type === 'value') {
        discountAmount = discount.amount;
    } else if (discount.type === 'percentage') {
        discountAmount = (subtotal * discount.amount) / 100;
    }
    
    const total = subtotal - discountAmount;
    
    const currencySymbol = '$';
    document.getElementById('subtotal').textContent = currencySymbol + subtotal.toFixed(2);
    
    if (discountAmount > 0) {
        document.getElementById('discountRow').style.display = 'flex';
        const discountDisplay = document.getElementById('discountAmountDisplay');
        if (discountDisplay) {
            discountDisplay.textContent = '-' + currencySymbol + discountAmount.toFixed(2);
        }
    } else {
        document.getElementById('discountRow').style.display = 'none';
    }
    
    document.getElementById('total').textContent = currencySymbol + total.toFixed(2);
}

// Customer selection
function selectCustomer() {
    loadCustomers();
    const modal = new bootstrap.Modal(document.getElementById('customerModal'));
    modal.show();
    
    // Clear search when modal opens
    document.getElementById('customerSearch').value = '';
    
    // Add event listener for search input (only once)
    const searchInput = document.getElementById('customerSearch');
    // Remove existing listeners by cloning and replacing
    const newSearchInput = searchInput.cloneNode(true);
    searchInput.parentNode.replaceChild(newSearchInput, searchInput);
    
    // Add search event listener
    newSearchInput.addEventListener('input', filterCustomerList);
    newSearchInput.addEventListener('keyup', filterCustomerList);
}

function loadCustomers() {
    fetch('<?= BASE_URL ?>ajax/get_customers.php')
        .then(r => r.json())
        .then(data => {
            allCustomers = data; // Store all customers
            filterCustomerList(); // Display all initially
        });
}

function filterCustomerList() {
    const searchTerm = document.getElementById('customerSearch').value.toLowerCase().trim();
    const list = document.getElementById('customerList');
    
    // Filter customers based on search term
    const filtered = allCustomers.filter(c => {
        const name = (c.name || '').toLowerCase();
        const email = (c.email || '').toLowerCase();
        const phone = (c.phone || '').toLowerCase();
        return name.includes(searchTerm) || email.includes(searchTerm) || phone.includes(searchTerm);
    });
    
    if (filtered.length === 0) {
        list.innerHTML = '<div class="text-center text-muted py-3">No customers found</div>';
    } else {
        list.innerHTML = filtered.map(c => {
            // Escape HTML to prevent XSS
            const name = escapeHtml(c.name || '');
            const email = escapeHtml(c.email || '');
            return `
                <div class="list-group-item list-group-item-action" onclick="setCustomer(${c.id}, '${escapeJs(c.name)}')">
                    <div class="fw-bold">${name}</div>
                    <small class="text-muted">${email}</small>
                </div>
            `;
        }).join('');
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to escape JavaScript strings
function escapeJs(str) {
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}

function setCustomer(id, name) {
    selectedCustomer = {id, name};
    bootstrap.Modal.getInstance(document.getElementById('customerModal')).hide();
    
    // Update customer display in POS
    const customerBtn = document.querySelector('[onclick*="selectCustomer"]');
    if (customerBtn) {
        customerBtn.innerHTML = `<i class="bi bi-person"></i> ${escapeHtml(name)}`;
        customerBtn.classList.add('btn-success');
        customerBtn.classList.remove('btn-outline-primary');
    }
    
    // Don't show alert, just update silently
}

function createCustomer() {
    window.location.href = '<?= BASE_URL ?>modules/customers/add.php?pos=1';
}

// Discount
function applyDiscount() {
    // Load current discount if exists
    const modal = new bootstrap.Modal(document.getElementById('discountModal'));
    
    // Set current discount values
    if (discount && discount.type && discount.amount > 0) {
        // Set the discount type radio button
        document.getElementById(discount.type === 'percentage' ? 'discountPercentage' : 'discountValue').checked = true;
        
        // Set the discount amount input
        discountInput = discount.amount.toString();
        document.getElementById('discountAmount').value = discountInput;
    } else {
        // Reset to defaults
        document.getElementById('discountValue').checked = true;
        discountInput = '0.00';
        document.getElementById('discountAmount').value = '0.00';
    }
    
    modal.show();
}

// Tag function - Remove tag button or implement actual functionality

let discountInput = '0.00';
function discountKeypadInput(char) {
    if (char === '.') {
        if (!discountInput.includes('.')) {
            discountInput += '.';
        }
    } else {
        if (discountInput === '0.00') {
            discountInput = char;
        } else {
            discountInput += char;
        }
    }
    document.getElementById('discountAmount').value = discountInput;
}

function discountKeypadBackspace() {
    discountInput = discountInput.slice(0, -1);
    if (!discountInput) discountInput = '0.00';
    document.getElementById('discountAmount').value = discountInput;
}

function discountKeypadClear() {
    discountInput = '0.00';
    document.getElementById('discountAmount').value = discountInput;
}

function applyDiscountAmount() {
    const type = document.querySelector('input[name="discountType"]:checked').value;
    const amount = parseFloat(discountInput) || 0;
    
    // Validation
    if (amount <= 0) {
        Swal.fire('Error', 'Discount amount must be greater than 0', 'error');
        return;
    }
    
    if (type === 'percentage' && amount > 100) {
        Swal.fire('Error', 'Percentage cannot exceed 100%', 'error');
        return;
    }
    
    // Calculate subtotal for percentage validation
    let subtotal = 0;
    cart.forEach(item => {
        subtotal += item.price * item.quantity;
    });
    
    if (type === 'percentage') {
        const discountValue = (subtotal * amount) / 100;
        if (discountValue > subtotal) {
            Swal.fire('Error', 'Discount cannot exceed subtotal amount', 'error');
            return;
        }
    } else if (type === 'value' && amount > subtotal) {
        Swal.fire('Error', 'Discount amount cannot exceed subtotal', 'error');
        return;
    }
    
    // Apply discount
    discount = { type, amount };
    
    // Save to session
    saveCartToSession();
    
    // Update cart display
    updateCart();
    
    // Show success message
    const currencySymbol = '$';
    const discountText = type === 'percentage' 
        ? `${amount}% discount applied` 
        : `${currencySymbol}${amount.toFixed(2)} discount applied`;
    
    Swal.fire({
        icon: 'success',
        title: 'Discount Applied',
        text: discountText,
        timer: 1500,
        showConfirmButton: false
    });
    
    // Close modal
    bootstrap.Modal.getInstance(document.getElementById('discountModal')).hide();
}

// Remove discount
function removeDiscount() {
    discount = { type: null, amount: 0 };
    saveCartToSession();
    updateCart();
    Swal.fire({
        icon: 'success',
        title: 'Discount Removed',
        timer: 1500,
        showConfirmButton: false
    });
}

// Reset sale - clear cart, customer, and discount
function resetSale() {
    Swal.fire({
        title: 'Reset Sale?',
        text: 'This will clear the cart, customer, and discount. Start a new sale?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Reset',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626'
    }).then((result) => {
        if (result.isConfirmed) {
            // Clear everything
            cart = [];
            selectedCustomer = null;
            discount = { type: null, amount: 0 };
            
            // Clear from session - ensure discount is cleared
            fetch('<?= BASE_URL ?>ajax/clear_pos_cart.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    cart: [],
                    customer: null,
                    discount: { type: null, amount: 0 }
                })
            })
            .then(() => {
                // Also explicitly clear discount from session
                fetch('<?= BASE_URL ?>ajax/clear_pos_cart.php')
                    .then(() => {
                        // Update display
                        updateCart();
                        
                        // Clear customer button
                        const customerBtn = document.querySelector('[onclick*="selectCustomer"]');
                        if (customerBtn) {
                            customerBtn.innerHTML = '<i class="bi bi-person-plus"></i>';
                            customerBtn.classList.remove('btn-success');
                            customerBtn.classList.add('btn-outline-primary');
                        }
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Sale Reset',
                            text: 'Ready for a new sale',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    });
            });
        }
    });
}

// Save cart to session
function saveCartToSession() {
    fetch('<?= BASE_URL ?>ajax/save_pos_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            cart: cart,
            customer: selectedCustomer,
            discount: discount
        })
    }).catch(err => {
        console.error('Error saving cart:', err);
    });
}

// Payment
function processPayment() {
    if (cart.length === 0) {
        Swal.fire('Error', 'Cart is empty', 'error');
        return;
    }
    
    // Save cart to session before navigating
    saveCartToSession();
    
    // Small delay to ensure session is saved
    setTimeout(() => {
        window.location.href = '<?= BASE_URL ?>modules/pos/payment.php';
    }, 100);
}

function escapeJs(str) {
    return str.replace(/'/g, "\\'").replace(/"/g, '\\"');
}

function showTradeInModal() {
    new bootstrap.Modal(document.getElementById('tradeInModal')).show();
}

function showConditionInfoModal() {
    new bootstrap.Modal(document.getElementById('conditionInfoModal')).show();
}

function showEndShiftModal() {
    new bootstrap.Modal(document.getElementById('shiftEndModal')).show();
}

<?php if ($currentShift): ?>
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
<?php endif; ?>

// POS dynamic fields update based on device category
function updatePOSDynamicFields() {
    const categoryInput = document.getElementById('posDeviceCategory');
    if (!categoryInput) return;
    
    const categoryName = categoryInput.value.toLowerCase();
    
    // Get all dynamic fields
    const colorField = document.getElementById('posColorField');
    const storageField = document.getElementById('posStorageField');
    const batteryHealthField = document.getElementById('posBatteryHealthField');
    const serialNumberField = document.getElementById('posSerialNumberField');
    const imeiField = document.getElementById('posImeiField');
    const simConfigField = document.getElementById('posSimConfigField');
    
    // Hide all fields first
    if (colorField) colorField.style.display = 'none';
    if (storageField) storageField.style.display = 'none';
    if (batteryHealthField) batteryHealthField.style.display = 'none';
    if (serialNumberField) serialNumberField.style.display = 'none';
    if (imeiField) imeiField.style.display = 'none';
    if (simConfigField) simConfigField.style.display = 'none';
    
    // Show fields based on category
    if (categoryName.includes('smartphone') || categoryName.includes('phone')) {
        // Smartphones: Show all relevant fields
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (batteryHealthField) batteryHealthField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
        if (imeiField) imeiField.style.display = 'block';
        if (simConfigField) simConfigField.style.display = 'block';
    } else if (categoryName.includes('laptop')) {
        // Laptops: Color, Storage, Serial Number
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
    } else if (categoryName.includes('tablet')) {
        // Tablets: Color, Storage, Battery Health, Serial Number
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (batteryHealthField) batteryHealthField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
    } else if (categoryName.includes('audio') || categoryName.includes('wearable')) {
        // Audio Devices & Wearables: Color, Battery Health (for wearables)
        if (colorField) colorField.style.display = 'block';
        if (categoryName.includes('wearable') && batteryHealthField) {
            batteryHealthField.style.display = 'block';
        }
    } else if (categoryName.includes('charging') || categoryName.includes('adapter') || 
               categoryName.includes('gaming') || categoryName.includes('networking') || 
               categoryName.includes('accessor')) {
        // Charging Adapters, Gaming, Networking, Accessories: Minimal fields (just brand/model)
        // No additional fields shown
    }
}

function processTradeInFromPOS() {
    const form = document.getElementById('tradeInForm');
    if (!form) {
        Swal.fire('Error', 'Trade-in form not found', 'error');
        return;
    }
    
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    
    // Convert empty strings to null for optional fields
    if (data.new_product_id === '') data.new_product_id = null;
    if (data.customer_id === '') data.customer_id = null;
    
    // Convert numeric strings to numbers
    if (data.cost_price) data.cost_price = parseFloat(data.cost_price) || 0;
    if (data.selling_price) data.selling_price = parseFloat(data.selling_price) || 0;
    if (data.final_valuation) data.final_valuation = parseFloat(data.final_valuation) || 0;
    if (data.manual_valuation) data.manual_valuation = parseFloat(data.manual_valuation) || 0;
    if (data.battery_health) data.battery_health = parseInt(data.battery_health) || null;
    
    if (!data.device_brand || !data.device_model || !data.final_valuation || !data.device_category) {
        Swal.fire('Error', 'Please fill in all required fields (Category, Brand, Model, Final Valuation)', 'error');
        return;
    }
    
    if (!data.cost_price || !data.selling_price) {
        Swal.fire('Error', 'Please enter Cost Price and Selling Price for stock entry', 'error');
        return;
    }
    
    console.log('Submitting trade-in data:', data);
    
    Swal.fire({
        title: 'Processing Trade-In...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/create_tradein.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => {
        if (!r.ok) {
            throw new Error('Network response was not ok');
        }
        return r.json();
    })
    .then(result => {
        console.log('Create trade-in response:', result);
        if (result.success && result.trade_in_id) {
            console.log('Trade-in created with ID:', result.trade_in_id);
            // Process the trade-in immediately
            return fetch('<?= BASE_URL ?>ajax/process_tradein.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({trade_in_id: result.trade_in_id})
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok');
                }
                return r.json();
            })
            .then(processResult => {
                console.log('Process trade-in response:', processResult);
                if (processResult && processResult.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: 'Trade-in processed successfully. Device added to stock and product deducted.',
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('tradeInModal')).hide();
                        window.location.reload();
                    });
                } else {
                    console.error('Process trade-in error:', processResult);
                    const errorMsg = (processResult && processResult.message) ? processResult.message : 'Failed to process trade-in. Please check the console for details.';
                    Swal.fire('Error', errorMsg, 'error');
                }
            })
            .catch(error => {
                console.error('Process trade-in fetch error:', error);
                Swal.fire('Error', 'Failed to process trade-in: ' + error.message, 'error');
            });
        } else {
            console.error('Create trade-in error:', result);
            Swal.fire('Error', result.message || 'Failed to create trade-in. Trade-in ID: ' + (result.trade_in_id || 'not provided'), 'error');
        }
    })
    .catch(error => {
        console.error('Trade-in error:', error);
        Swal.fire('Error', 'Failed to process trade-in: ' + error.message, 'error');
    });
}

function showProductInfo(productId) {
    fetch('<?= BASE_URL ?>ajax/get_product_info.php?id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const p = data.product;
                const imageHtml = p.images ? `<img src="${JSON.parse(p.images)[0]}" style="max-width: 100px; max-height: 100px; margin-bottom: 15px;">` : '';
                
                Swal.fire({
                    title: p.brand + ' ' + p.model,
                    html: `
                        <div class="text-start">
                            ${imageHtml}
                            <p><strong>In stock:</strong> ${p.quantity_in_stock}</p>
                            <p><strong>Product Code:</strong> ${p.product_code || '-'}</p>
                            <p><strong>Product Price:</strong> ${p.selling_price}</p>
                            <p><strong>Product Cost:</strong> ${p.cost_price || '0.00'}</p>
                            <p><strong>Category:</strong> ${p.category_name || 'No category'}</p>
                            <p><strong>Barcode:</strong> ${p.barcode || '-'}</p>
                            <p><strong>Tax / Charges:</strong> ${p.tax || '-'}</p>
                        </div>
                    `,
                    icon: 'info',
                    width: '500px',
                    showCloseButton: true
                });
            }
        });
}

function toggleFavorite(productId, element) {
    const isActive = element.classList.contains('active');
    
    fetch('<?= BASE_URL ?>ajax/toggle_favorite.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({product_id: productId})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (data.favorited) {
                element.classList.add('active');
                element.querySelector('i').className = 'bi bi-heart-fill';
            } else {
                element.classList.remove('active');
                element.querySelector('i').className = 'bi bi-heart';
            }
        }
    });
}

function showTradeInModal() {
    new bootstrap.Modal(document.getElementById('tradeInModal')).show();
}

function showConditionInfoModal() {
    new bootstrap.Modal(document.getElementById('conditionInfoModal')).show();
}

// Mobile cart toggle
function toggleCart() {
    const posRight = document.getElementById('posRight');
    if (posRight) {
        posRight.classList.toggle('cart-open');
    }
}

// Show/hide cart toggle button on mobile
function checkMobileView() {
    const cartToggleBtn = document.getElementById('cartToggleBtn');
    const closeBtn = document.querySelector('.cart-header .btn-close');
    const posRight = document.getElementById('posRight');
    const posLeft = document.getElementById('posLeft');
    
    if (window.innerWidth <= 768) {
        // Mobile view
        if (cartToggleBtn) cartToggleBtn.style.display = 'flex';
        if (closeBtn) closeBtn.style.display = 'block';
        // Ensure cart starts closed on mobile
        if (posRight && !posRight.classList.contains('cart-open')) {
            // Cart is hidden, products should be visible
            if (posLeft) posLeft.style.marginBottom = '0';
        }
    } else {
        // Desktop view
        if (cartToggleBtn) cartToggleBtn.style.display = 'none';
        if (closeBtn) closeBtn.style.display = 'none';
        if (posRight) posRight.classList.remove('cart-open');
        if (posLeft) posLeft.style.marginBottom = '0';
    }
}

window.addEventListener('resize', checkMobileView);
window.addEventListener('load', function() {
    checkMobileView();
    // Ensure products are visible on initial load
    setTimeout(checkMobileView, 100);
});
checkMobileView();

// Trade-in product search functionality
function filterTradeInProducts(searchTerm) {
    const dropdown = document.getElementById('tradeInProductDropdown');
    if (!dropdown) return;
    
    const items = dropdown.querySelectorAll('.trade-in-product-item');
    const term = searchTerm.toLowerCase().trim();
    
    let hasMatches = false;
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(term)) {
            item.style.display = 'block';
            hasMatches = true;
        } else {
            item.style.display = 'none';
        }
    });
    
    if (hasMatches || term === '') {
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
    }
}

function showTradeInProductDropdown() {
    const dropdown = document.getElementById('tradeInProductDropdown');
    const searchInput = document.getElementById('tradeInProductSearch');
    if (!dropdown || !searchInput) return;
    
    if (searchInput.value.trim() === '') {
        // Show all products when focused and empty
        dropdown.querySelectorAll('.trade-in-product-item').forEach(item => {
            item.style.display = 'block';
        });
    }
    dropdown.style.display = 'block';
}

function hideTradeInProductDropdown() {
    const dropdown = document.getElementById('tradeInProductDropdown');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
}

// Trade-in customer dropdown functions
function filterTradeInCustomers(searchTerm) {
    const dropdown = document.getElementById('tradeInCustomerDropdown');
    if (!dropdown) return;
    
    const items = dropdown.querySelectorAll('.trade-in-customer-item');
    const term = searchTerm.toLowerCase().trim();
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(term)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function showTradeInCustomerDropdown() {
    const dropdown = document.getElementById('tradeInCustomerDropdown');
    const searchInput = document.getElementById('tradeInCustomerSearch');
    if (!dropdown || !searchInput) return;
    
    if (searchInput.value.trim() === '') {
        dropdown.querySelectorAll('.trade-in-customer-item').forEach(item => {
            item.style.display = 'block';
        });
    }
    dropdown.style.display = 'block';
}

function hideTradeInCustomerDropdown() {
    const dropdown = document.getElementById('tradeInCustomerDropdown');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
}

// Trade-in category dropdown functions
function filterTradeInCategories(searchTerm) {
    const dropdown = document.getElementById('posDeviceCategoryDropdown');
    if (!dropdown) return;
    
    const items = dropdown.querySelectorAll('.trade-in-category-item');
    const term = searchTerm.toLowerCase().trim();
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(term)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function showTradeInCategoryDropdown() {
    const dropdown = document.getElementById('posDeviceCategoryDropdown');
    const searchInput = document.getElementById('posDeviceCategorySearch');
    if (!dropdown || !searchInput) return;
    
    if (searchInput.value.trim() === '') {
        dropdown.querySelectorAll('.trade-in-category-item').forEach(item => {
            item.style.display = 'block';
        });
    }
    dropdown.style.display = 'block';
}

function hideTradeInCategoryDropdown() {
    const dropdown = document.getElementById('posDeviceCategoryDropdown');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
}

// Make function globally accessible
window.selectTradeInProduct = function(productId, productText, productPrice) {
    const searchInput = document.getElementById('tradeInProductSearch');
    const hiddenInput = document.getElementById('tradeInProduct');
    
    if (!searchInput || !hiddenInput) {
        console.error('Trade-in product input fields not found');
        return false;
    }
    
    // Set the values
    searchInput.value = productText;
    hiddenInput.value = productId;
    
    // Force update the input to ensure it's registered
    searchInput.dispatchEvent(new Event('input', { bubbles: true }));
    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
    
    // Hide dropdown
    hideTradeInProductDropdown();
    
    // Update balance calculation in POS modal
    const posFinalValuation = document.getElementById('posFinalValuation');
    const posProductDetails = document.getElementById('posProductDetails');
    if (posFinalValuation && posProductDetails) {
        const tradeInValue = parseFloat(posFinalValuation.value) || 0;
        posProductDetails.style.display = 'block';
        const posProductPrice = document.getElementById('posProductPrice');
        const posTradeInValue = document.getElementById('posTradeInValue');
        const posBalanceToPay = document.getElementById('posBalanceToPay');
        
        if (posProductPrice) posProductPrice.textContent = '$' + productPrice.toFixed(2);
        if (posTradeInValue) posTradeInValue.textContent = '$' + tradeInValue.toFixed(2);
        if (posBalanceToPay) posBalanceToPay.textContent = '$' + Math.max(0, productPrice - tradeInValue).toFixed(2);
    }
    
    console.log('Product selected:', { productId, productText, productPrice });
    return false;
};

// Initialize trade-in product search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('tradeInProductSearch');
    const dropdown = document.getElementById('tradeInProductDropdown');
    let blurTimeout;
    
    if (searchInput && dropdown) {
        // Handle input filtering
        searchInput.addEventListener('input', function() {
            filterTradeInProducts(this.value);
        });
        
        // Handle focus - show dropdown
        searchInput.addEventListener('focus', function() {
            clearTimeout(blurTimeout);
            showTradeInProductDropdown();
        });
        
        // Handle blur - hide dropdown after delay
        searchInput.addEventListener('blur', function() {
            blurTimeout = setTimeout(() => {
                hideTradeInProductDropdown();
            }, 300);
        });
        
        // Handle clicks on dropdown items using event delegation
        dropdown.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const item = e.target.closest('.trade-in-product-item');
            if (item) {
                clearTimeout(blurTimeout);
                
                const productId = item.dataset.id;
                const productText = item.dataset.text;
                const productPrice = parseFloat(item.dataset.price) || 0;
                
                // Set values
                searchInput.value = productText;
                const hiddenInput = document.getElementById('tradeInProduct');
                if (hiddenInput) {
                    hiddenInput.value = productId;
                }
                
                // Hide dropdown
                hideTradeInProductDropdown();
                
                // Update balance calculation in POS modal
                const posFinalValuation = document.getElementById('posFinalValuation');
                const posProductDetails = document.getElementById('posProductDetails');
                if (posFinalValuation && posProductDetails) {
                    const tradeInValue = parseFloat(posFinalValuation.value) || 0;
                    posProductDetails.style.display = 'block';
                    const posProductPrice = document.getElementById('posProductPrice');
                    const posTradeInValue = document.getElementById('posTradeInValue');
                    const posBalanceToPay = document.getElementById('posBalanceToPay');
                    
                    if (posProductPrice) posProductPrice.textContent = '$' + productPrice.toFixed(2);
                    if (posTradeInValue) posTradeInValue.textContent = '$' + tradeInValue.toFixed(2);
                    if (posBalanceToPay) posBalanceToPay.textContent = '$' + Math.max(0, productPrice - tradeInValue).toFixed(2);
                }
                
                console.log('Product selected:', { productId, productText, productPrice });
            }
        });
        
        // Prevent dropdown from closing when clicking inside it
        dropdown.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });
    }
    
    // Trade-in customer search functionality
    const customerSearch = document.getElementById('tradeInCustomerSearch');
    const customerDropdown = document.getElementById('tradeInCustomerDropdown');
    const customerHidden = document.getElementById('tradeInCustomer');
    let customerBlurTimeout;
    
    if (customerSearch && customerDropdown) {
        customerSearch.addEventListener('input', function() {
            filterTradeInCustomers(this.value);
        });
        
        customerSearch.addEventListener('focus', function() {
            clearTimeout(customerBlurTimeout);
            showTradeInCustomerDropdown();
        });
        
        customerSearch.addEventListener('blur', function() {
            customerBlurTimeout = setTimeout(() => {
                hideTradeInCustomerDropdown();
            }, 300);
        });
        
        customerDropdown.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const item = e.target.closest('.trade-in-customer-item');
            if (item) {
                clearTimeout(customerBlurTimeout);
                
                const customerId = item.dataset.id;
                const customerText = item.dataset.text;
                
                customerSearch.value = customerText;
                if (customerHidden) {
                    customerHidden.value = customerId;
                }
                
                hideTradeInCustomerDropdown();
            }
        });
        
        customerDropdown.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });
    }
    
    // Trade-in category search functionality
    const categorySearch = document.getElementById('posDeviceCategorySearch');
    const categoryDropdown = document.getElementById('posDeviceCategoryDropdown');
    const categoryHidden = document.getElementById('posDeviceCategory');
    let categoryBlurTimeout;
    
    if (categorySearch && categoryDropdown) {
        categorySearch.addEventListener('input', function() {
            filterTradeInCategories(this.value);
        });
        
        categorySearch.addEventListener('focus', function() {
            clearTimeout(categoryBlurTimeout);
            showTradeInCategoryDropdown();
        });
        
        categorySearch.addEventListener('blur', function() {
            categoryBlurTimeout = setTimeout(() => {
                hideTradeInCategoryDropdown();
            }, 300);
        });
        
        categoryDropdown.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const item = e.target.closest('.trade-in-category-item');
            if (item) {
                clearTimeout(categoryBlurTimeout);
                
                const categoryValue = item.dataset.value;
                const categoryText = item.dataset.text;
                
                categorySearch.value = categoryText;
                if (categoryHidden) {
                    categoryHidden.value = categoryValue;
                    // Trigger dynamic fields update
                    updatePOSDynamicFields();
                }
                
                hideTradeInCategoryDropdown();
            }
        });
        
        categoryDropdown.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });
    }
    
    // Update product details when final valuation changes in POS modal
    const posFinalValuation = document.getElementById('posFinalValuation');
    if (posFinalValuation) {
        posFinalValuation.addEventListener('input', function() {
            const hiddenInput = document.getElementById('tradeInProduct');
            if (hiddenInput && hiddenInput.value) {
                const selectedProduct = Array.from(document.querySelectorAll('.trade-in-product-item')).find(item => 
                    item.dataset.id === hiddenInput.value
                );
                if (selectedProduct) {
                    const productPrice = parseFloat(selectedProduct.dataset.price) || 0;
                    const tradeInValue = parseFloat(this.value) || 0;
                    const posTradeInValue = document.getElementById('posTradeInValue');
                    const posBalanceToPay = document.getElementById('posBalanceToPay');
                    
                    if (posTradeInValue) posTradeInValue.textContent = '$' + tradeInValue.toFixed(2);
                    if (posBalanceToPay) posBalanceToPay.textContent = '$' + Math.max(0, productPrice - tradeInValue).toFixed(2);
                }
            }
        });
    }
});

// Make quantity functions globally accessible
window.updateQuantity = updateQuantity;
window.setQuantity = setQuantity;
window.validateQuantity = validateQuantity;

// Check for payment success notification on page load
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const paymentSuccess = urlParams.get('payment_success');
    const receiptId = urlParams.get('receipt_id');
    
    if (paymentSuccess === '1' && receiptId) {
        // Show success notification immediately (only when coming from receipt page)
        Swal.fire({
            title: 'Success!',
            text: 'Payment processed successfully. Receipt will be printed.',
            icon: 'success',
            confirmButtonText: 'OK',
            timer: 3000,
            timerProgressBar: true,
            allowOutsideClick: true,
            allowEscapeKey: true
        }).then(() => {
            // Clean URL
            window.history.replaceState({}, document.title, window.location.pathname);
        });
    } else {
        // Clean URL if parameters exist but don't match (remove them silently)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('payment_success') || urlParams.has('receipt_id')) {
            const newUrl = window.location.pathname;
            window.history.replaceState({}, document.title, newUrl);
        }
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

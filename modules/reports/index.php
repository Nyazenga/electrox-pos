<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Reports Dashboard';

require_once APP_PATH . '/includes/header.php';
?>

<style>
.report-card {
    transition: transform 0.2s, box-shadow 0.2s;
    cursor: pointer;
    height: 100%;
}
.report-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.submenu-header {
    padding: 8px 15px;
    font-weight: 600;
    color: #6c757d;
    font-size: 12px;
    text-transform: uppercase;
    margin-top: 10px;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-graph-up"></i> Reports Dashboard</h2>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">General Reports</h5>
                <div class="row g-3">
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-primary" onclick="window.location.href='sales_summary.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-bar-chart" style="font-size: 48px; color: #1e3a8a;"></i>
                                <h6 class="mt-3">Sales Summary</h6>
                                <p class="text-muted small mb-0">Overview of sales performance</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-info" onclick="window.location.href='receipts.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-receipt-cutoff" style="font-size: 48px; color: #0dcaf0;"></i>
                                <h6 class="mt-3">Receipts</h6>
                                <p class="text-muted small mb-0">All transaction receipts</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-warning" onclick="window.location.href='refunds.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-arrow-counterclockwise" style="font-size: 48px; color: #ffc107;"></i>
                                <h6 class="mt-3">Refunds</h6>
                                <p class="text-muted small mb-0">Refund transactions</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-success" onclick="window.location.href='sales_by_products.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-box-seam" style="font-size: 48px; color: #198754;"></i>
                                <h6 class="mt-3">Sales by Products</h6>
                                <p class="text-muted small mb-0">Product performance analysis</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-primary" onclick="window.location.href='sales_by_category.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-tags" style="font-size: 48px; color: #1e3a8a;"></i>
                                <h6 class="mt-3">Sales by Category</h6>
                                <p class="text-muted small mb-0">Category-wise sales breakdown</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-info" onclick="window.location.href='sales_by_discounts.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-percent" style="font-size: 48px; color: #0dcaf0;"></i>
                                <h6 class="mt-3">Sales by Discounts</h6>
                                <p class="text-muted small mb-0">Discount analysis</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-warning" onclick="window.location.href='sales_by_payment_types.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-credit-card" style="font-size: 48px; color: #ffc107;"></i>
                                <h6 class="mt-3">Sales by Payment Types</h6>
                                <p class="text-muted small mb-0">Payment method analysis</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-danger" onclick="window.location.href='taxes.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-receipt" style="font-size: 48px; color: #dc3545;"></i>
                                <h6 class="mt-3">Taxes</h6>
                                <p class="text-muted small mb-0">Tax summary and breakdown</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-secondary" onclick="window.location.href='charges.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-cash-coin" style="font-size: 48px; color: #6c757d;"></i>
                                <h6 class="mt-3">Charges</h6>
                                <p class="text-muted small mb-0">Additional charges report</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card border-primary" onclick="window.location.href='shifts.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-clock-history" style="font-size: 48px; color: #1e3a8a;"></i>
                                <h6 class="mt-3">Shifts</h6>
                                <p class="text-muted small mb-0">Shift reports and analysis</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">Advanced Sales Reports</h5>
                <div class="row g-3">
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='product_wise_receipt.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-file-earmark-text" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Product Wise Receipt</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='sales_by_trend.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-graph-up-arrow" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Sales by Trend</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='deleted_receipts.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-trash" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Deleted Receipts</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='order_type_wise_sales.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-list-check" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Order Type Wise Sales</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='product_wise_tax_charge.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-calculator" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Product Wise Tax/Charge</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='manual_receipts.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-pencil-square" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Manual Receipts</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='sales_by_orders.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-cart-check" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Sales by Orders</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='product_wise_orders.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-box-arrow-in-right" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Product Wise Orders</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='sales_by_modifiers.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-sliders" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Sales by Modifiers</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='product_sales_by_staff.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-person-badge" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Product Sales by Staff</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='sales_by_staff.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-people" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Sales by Staff</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='products_consumed_by_staff.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-person-dash" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Products Consumed by Staff</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="card report-card" onclick="window.location.href='ecommerce_sales.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-globe" style="font-size: 36px; color: #1e3a8a;"></i>
                                <h6 class="mt-2" style="font-size: 14px;">Ecommerce Sales</h6>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3 text-danger">Suspicious Reports</h5>
                <div class="row g-3">
                    <div class="col-md-4 col-sm-6">
                        <div class="card report-card border-danger" onclick="window.location.href='product_wise_deleted_receipts.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-exclamation-triangle" style="font-size: 48px; color: #dc3545;"></i>
                                <h6 class="mt-3">Product Wise Deleted Receipts</h6>
                                <p class="text-muted small mb-0">Track deleted receipts by product</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="card report-card border-warning" onclick="window.location.href='refunds_credit_notes.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-file-earmark-minus" style="font-size: 48px; color: #ffc107;"></i>
                                <h6 class="mt-3">Refunds & Credit Notes</h6>
                                <p class="text-muted small mb-0">Comprehensive refund analysis</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="card report-card border-danger" onclick="window.location.href='deleted_products_open_orders.php'">
                            <div class="card-body text-center">
                                <i class="bi bi-exclamation-circle" style="font-size: 48px; color: #dc3545;"></i>
                                <h6 class="mt-3">Deleted Products in Open Orders</h6>
                                <p class="text-muted small mb-0">Identify problematic orders</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

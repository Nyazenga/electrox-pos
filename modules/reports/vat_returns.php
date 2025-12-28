<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.vat_returns');

$pageTitle = 'VAT Returns Report';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');

// Get branches
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions
$whereConditions = ["DATE(fr.receipt_date) BETWEEN :start_date AND :end_date", "fr.submission_status = 'Submitted'"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "fr.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "fr.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

$whereClause = implode(' AND ', $whereConditions);

// Get VAT Output (from Fiscal Invoices and Debit Notes)
$vatOutput = $primaryDb->getRows("SELECT 
    frt.tax_code,
    frt.tax_percent,
    frt.tax_id,
    SUM(CASE WHEN fr.receipt_type = 'FiscalInvoice' THEN frt.tax_amount ELSE 0 END) as invoice_tax,
    SUM(CASE WHEN fr.receipt_type = 'FiscalInvoice' THEN frt.sales_amount_with_tax ELSE 0 END) as invoice_sales_with_tax,
    SUM(CASE WHEN fr.receipt_type = 'DebitNote' THEN frt.tax_amount ELSE 0 END) as debit_note_tax,
    SUM(CASE WHEN fr.receipt_type = 'DebitNote' THEN frt.sales_amount_with_tax ELSE 0 END) as debit_note_sales_with_tax,
    SUM(CASE WHEN fr.receipt_type = 'FiscalInvoice' THEN frt.tax_amount ELSE 0 END) + 
    SUM(CASE WHEN fr.receipt_type = 'DebitNote' THEN frt.tax_amount ELSE 0 END) as total_tax_output,
    SUM(CASE WHEN fr.receipt_type = 'FiscalInvoice' THEN frt.sales_amount_with_tax ELSE 0 END) + 
    SUM(CASE WHEN fr.receipt_type = 'DebitNote' THEN frt.sales_amount_with_tax ELSE 0 END) as total_sales_output
FROM fiscal_receipt_taxes frt
INNER JOIN fiscal_receipts fr ON frt.fiscal_receipt_id = fr.id
WHERE $whereClause
GROUP BY frt.tax_code, frt.tax_percent, frt.tax_id
ORDER BY frt.tax_percent DESC", $params);

if ($vatOutput === false) {
    $vatOutput = [];
}

// Get VAT Input (from Credit Notes - negative amounts)
$vatInput = $primaryDb->getRows("SELECT 
    frt.tax_code,
    frt.tax_percent,
    frt.tax_id,
    SUM(ABS(frt.tax_amount)) as credit_note_tax,
    SUM(ABS(frt.sales_amount_with_tax)) as credit_note_sales_with_tax
FROM fiscal_receipt_taxes frt
INNER JOIN fiscal_receipts fr ON frt.fiscal_receipt_id = fr.id
WHERE $whereClause AND fr.receipt_type = 'CreditNote'
GROUP BY frt.tax_code, frt.tax_percent, frt.tax_id
ORDER BY frt.tax_percent DESC", $params);

if ($vatInput === false) {
    $vatInput = [];
}

// Calculate totals
$totalVatOutput = 0;
$totalVatInput = 0;
$totalSalesOutput = 0;
$totalSalesInput = 0;

foreach ($vatOutput as $output) {
    $totalVatOutput += floatval($output['total_tax_output']);
    $totalSalesOutput += floatval($output['total_sales_output']);
}

foreach ($vatInput as $input) {
    $totalVatInput += floatval($input['credit_note_tax']);
    $totalSalesInput += floatval($input['credit_note_sales_with_tax']);
}

$vatDue = $totalVatOutput - $totalVatInput;

// Get summary stats
$summary = $primaryDb->getRow("SELECT 
    COUNT(CASE WHEN fr.receipt_type = 'FiscalInvoice' THEN 1 END) as total_invoices,
    COUNT(CASE WHEN fr.receipt_type = 'CreditNote' THEN 1 END) as total_credit_notes,
    COUNT(CASE WHEN fr.receipt_type = 'DebitNote' THEN 1 END) as total_debit_notes,
    SUM(CASE WHEN fr.receipt_type = 'FiscalInvoice' THEN fr.receipt_total ELSE 0 END) as invoice_total,
    SUM(CASE WHEN fr.receipt_type = 'CreditNote' THEN ABS(fr.receipt_total) ELSE 0 END) as credit_note_total,
    SUM(CASE WHEN fr.receipt_type = 'DebitNote' THEN fr.receipt_total ELSE 0 END) as debit_note_total
FROM fiscal_receipts fr
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_invoices' => 0,
        'total_credit_notes' => 0,
        'total_debit_notes' => 0,
        'invoice_total' => 0,
        'credit_note_total' => 0,
        'debit_note_total' => 0
    ];
}

// Get detailed breakdown by tax
$taxBreakdown = [];
foreach ($vatOutput as $output) {
    $taxKey = $output['tax_code'] . '_' . $output['tax_percent'];
    $taxBreakdown[$taxKey] = [
        'tax_code' => $output['tax_code'],
        'tax_percent' => $output['tax_percent'],
        'tax_id' => $output['tax_id'],
        'vat_output' => floatval($output['total_tax_output']),
        'sales_output' => floatval($output['total_sales_output']),
        'vat_input' => 0,
        'sales_input' => 0
    ];
}

foreach ($vatInput as $input) {
    $taxKey = $input['tax_code'] . '_' . $input['tax_percent'];
    if (!isset($taxBreakdown[$taxKey])) {
        $taxBreakdown[$taxKey] = [
            'tax_code' => $input['tax_code'],
            'tax_percent' => $input['tax_percent'],
            'tax_id' => $input['tax_id'],
            'vat_output' => 0,
            'sales_output' => 0,
            'vat_input' => 0,
            'sales_input' => 0
        ];
    }
    $taxBreakdown[$taxKey]['vat_input'] = floatval($input['credit_note_tax']);
    $taxBreakdown[$taxKey]['sales_input'] = floatval($input['credit_note_sales_with_tax']);
    $taxBreakdown[$taxKey]['vat_due'] = $taxBreakdown[$taxKey]['vat_output'] - $taxBreakdown[$taxKey]['vat_input'];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">VAT Returns Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Summary</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Invoices</td><td style="text-align: right;">' . number_format($summary['total_invoices']) . '</td></tr>';
    $html .= '<tr><td>Total Credit Notes</td><td style="text-align: right;">' . number_format($summary['total_credit_notes']) . '</td></tr>';
    $html .= '<tr><td>Total Debit Notes</td><td style="text-align: right;">' . number_format($summary['total_debit_notes']) . '</td></tr>';
    $html .= '<tr><td><strong>Total VAT Output</strong></td><td style="text-align: right;"><strong>' . formatCurrency($totalVatOutput) . '</strong></td></tr>';
    $html .= '<tr><td><strong>Total VAT Input</strong></td><td style="text-align: right;"><strong>' . formatCurrency($totalVatInput) . '</strong></td></tr>';
    $html .= '<tr><td><strong>VAT Due</strong></td><td style="text-align: right;"><strong>' . formatCurrency($vatDue) . '</strong></td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">VAT Breakdown by Tax Type</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Tax Code</th><th>Tax %</th><th style="text-align: right;">VAT Output</th><th style="text-align: right;">VAT Input</th><th style="text-align: right;">VAT Due</th></tr>';
    foreach ($taxBreakdown as $tax) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($tax['tax_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . ($tax['tax_percent'] !== null ? number_format($tax['tax_percent'], 2) . '%' : 'Exempt') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($tax['vat_output']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($tax['vat_input']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($tax['vat_due'] ?? ($tax['vat_output'] - $tax['vat_input'])) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('VAT Returns Report', $html, 'VAT_Returns_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-receipt-cutoff"></i> VAT Returns Report</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar"></i> Start Date</label>
                <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar"></i> End Date</label>
                <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="vat_returns.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Invoices</h6>
                <h3 class="mb-0"><?= number_format($summary['total_invoices']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Credit Notes</h6>
                <h3 class="mb-0"><?= number_format($summary['total_credit_notes']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">VAT Output</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($totalVatOutput) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">VAT Input</h6>
                <h3 class="mb-0 text-danger"><?= formatCurrency($totalVatInput) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">VAT Due (Output - Input)</h6>
                <h2 class="mb-0 text-primary"><?= formatCurrency($vatDue) ?></h2>
                <small class="text-muted"><?= $vatDue >= 0 ? 'Amount payable to ZIMRA' : 'Amount refundable from ZIMRA' ?></small>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-list-ul"></i> VAT Breakdown by Tax Type</h5>
    </div>
    <div class="card-body">
        <?php if (empty($taxBreakdown)): ?>
            <p class="text-muted mb-0">No VAT data found for the selected period.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="vatBreakdownTable">
                    <thead>
                        <tr>
                            <th>Tax Code</th>
                            <th>Tax Rate</th>
                            <th class="text-end">VAT Output</th>
                            <th class="text-end">VAT Input</th>
                            <th class="text-end">VAT Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($taxBreakdown as $tax): 
                            $vatDue = ($tax['vat_due'] ?? ($tax['vat_output'] - $tax['vat_input']));
                            $dueClass = $vatDue >= 0 ? 'text-success' : 'text-danger';
                        ?>
                            <tr>
                                <td><?= escapeHtml($tax['tax_code'] ?? 'N/A') ?></td>
                                <td><?= $tax['tax_percent'] !== null ? number_format($tax['tax_percent'], 2) . '%' : '<span class="badge bg-secondary">Exempt</span>' ?></td>
                                <td class="text-end"><?= formatCurrency($tax['vat_output']) ?></td>
                                <td class="text-end"><?= formatCurrency($tax['vat_input']) ?></td>
                                <td class="text-end <?= $dueClass ?>"><strong><?= formatCurrency($vatDue) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-primary">
                            <th colspan="2">Total</th>
                            <th class="text-end"><?= formatCurrency($totalVatOutput) ?></th>
                            <th class="text-end"><?= formatCurrency($totalVatInput) ?></th>
                            <th class="text-end"><?= formatCurrency($vatDue) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    if ($.fn.DataTable && $('#vatBreakdownTable').length) {
        $('#vatBreakdownTable').DataTable({
            order: [[4, 'desc']],
            pageLength: 25,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            footerCallback: function (row, data, start, end, display) {
                // Footer is already calculated in PHP, no need to recalculate
            }
        });
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


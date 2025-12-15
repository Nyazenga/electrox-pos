<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('suppliers.create');

$pageTitle = 'Add Supplier';

$db = Database::getInstance();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierCode = trim($_POST['supplier_code'] ?? '');
    
    // Auto-generate supplier code if empty
    if (empty($supplierCode)) {
        $dateStr = date('Ymd');
        $maxAttempts = 10;
        $attempt = 0;
        $generated = false;
        
        while ($attempt < $maxAttempts && !$generated) {
            $sequence = str_pad($attempt + 1, 2, '0', STR_PAD_LEFT);
            $supplierCode = 'SUP-' . $dateStr . '-' . $sequence;
            
            // Check if code exists
            $existing = $db->getRow("SELECT id FROM suppliers WHERE supplier_code = :code", [':code' => $supplierCode]);
            if (!$existing) {
                $generated = true;
            } else {
                $attempt++;
            }
        }
        
        // Fallback with timestamp if all attempts fail
        if (!$generated) {
            $timestamp = substr(time(), -6);
            $supplierCode = 'SUP-' . $dateStr . '-' . $timestamp;
        }
    }
    
    $data = [
        'supplier_code' => $supplierCode,
        'name' => trim($_POST['name'] ?? ''),
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'tin' => trim($_POST['tin'] ?? ''),
        'payment_terms' => trim($_POST['payment_terms'] ?? ''),
        'credit_limit' => floatval($_POST['credit_limit'] ?? 0),
        'status' => $_POST['status'] ?? 'Active'
    ];
    
    if (empty($data['name'])) {
        $error = 'Supplier name is required';
    } else {
        $result = $db->insert('suppliers', $data);
        if ($result) {
            logActivity($_SESSION['user_id'], 'supplier_created', ['supplier_id' => $result]);
            redirectTo('modules/suppliers/index.php');
        } else {
            $error = 'Failed to create supplier: ' . $db->getLastError();
        }
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Add Supplier</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Supplier Code</label>
                    <input type="text" class="form-control" id="supplier_code" name="supplier_code" placeholder="Auto-generated" readonly>
                    <small class="text-muted">Auto-generated</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Supplier Name *</label>
                    <input type="text" class="form-control" name="name" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Contact Person</label>
                    <input type="text" class="form-control" name="contact_person">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email">
                </div>
                <div class="col-md-6">
                    <label class="form-label">TIN Number</label>
                    <input type="text" class="form-control" name="tin">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="3"></textarea>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Payment Terms</label>
                    <input type="text" class="form-control" name="payment_terms" placeholder="e.g., Net 30">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Credit Limit</label>
                    <input type="number" class="form-control" name="credit_limit" step="0.01" value="0">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="Active" selected>Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Supplier</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-generate supplier code on page load
document.addEventListener('DOMContentLoaded', function() {
    generateSupplierCode();
});

function generateSupplierCode() {
    const date = new Date();
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const dateStr = year + month + day;
    
    // Generate with retry logic
    let attempt = 0;
    const maxAttempts = 10;
    
    function tryGenerate() {
        const sequence = String(attempt + 1).padStart(2, '0');
        const supplierCode = 'SUP-' + dateStr + '-' + sequence;
        
        // Check if exists
        fetch('<?= BASE_URL ?>ajax/check_supplier_code.php?code=' + encodeURIComponent(supplierCode))
            .then(response => response.json())
            .then(data => {
                if (!data.exists) {
                    document.getElementById('supplier_code').value = supplierCode;
                } else {
                    attempt++;
                    if (attempt < maxAttempts) {
                        tryGenerate();
                    } else {
                        // Fallback with timestamp
                        const timestamp = Date.now().toString().slice(-6);
                        document.getElementById('supplier_code').value = 'SUP-' + dateStr + '-' + timestamp;
                    }
                }
            })
            .catch(() => {
                // Fallback on error
                const timestamp = Date.now().toString().slice(-6);
                document.getElementById('supplier_code').value = 'SUP-' + dateStr + '-' + timestamp;
            });
    }
    
    tryGenerate();
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>



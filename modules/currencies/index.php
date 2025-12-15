<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.view');

$pageTitle = 'Currency Management';

// Always use base database for currencies (shared across all tenants)
$db = Database::getMainInstance();
$currencies = $db->getRows("SELECT * FROM currencies ORDER BY is_base DESC, code ASC");
if ($currencies === false || !is_array($currencies)) {
    $currencies = [];
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Currency Management</h2>
    <a href="add.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Add Currency
    </a>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="currenciesTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Symbol</th>
                        <th>Exchange Rate</th>
                        <th>Base Currency</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($currencies as $currency): ?>
                        <tr>
                            <td><strong><?= escapeHtml($currency['code']) ?></strong></td>
                            <td><?= escapeHtml($currency['name']) ?></td>
                            <td><?= escapeHtml($currency['symbol']) ?></td>
                            <td>
                                <?php if ($currency['is_base']): ?>
                                    <span class="badge bg-secondary">Base (1.000000)</span>
                                <?php else: ?>
                                    <?= number_format($currency['exchange_rate'], 6) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($currency['is_base']): ?>
                                    <span class="badge bg-primary">Yes</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($currency['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="edit.php?id=<?= $currency['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="exchange_rates.php?currency_id=<?= $currency['id'] ?>" class="btn btn-outline-info" title="Exchange Rates">
                                        <i class="bi bi-arrow-left-right"></i>
                                    </a>
                                    <?php if (!$currency['is_base']): ?>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteCurrency(<?= $currency['id'] ?>, <?= json_encode($currency['code']) ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function deleteCurrency(id, code) {
    Swal.fire({
        title: 'Delete Currency?',
        text: `Are you sure you want to delete ${code}? This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>ajax/delete_currency.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ currency_id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error!', 'An error occurred while deleting the currency.', 'error');
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const table = $('#currenciesTable').DataTable({
        order: [[4, 'desc'], [0, 'asc']],
        pageLength: 25,
        language: {
            emptyTable: 'No currencies found. <a href="add.php">Add your first currency</a>'
        }
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


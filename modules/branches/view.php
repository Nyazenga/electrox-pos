<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('branches.view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/branches/index.php');
}

$pageTitle = 'View Branch';

$db = Database::getInstance();
$branch = $db->getRow("SELECT * FROM branches WHERE id = :id", [':id' => $id]);

if (!$branch) {
    redirectTo('modules/branches/index.php');
}

$users = $db->getRows("SELECT * FROM users WHERE branch_id = :id", [':id' => $id]);
$products = $db->getCount("SELECT COUNT(*) FROM products WHERE branch_id = :id", [':id' => $id]);
$invoices = $db->getCount("SELECT COUNT(*) FROM invoices WHERE branch_id = :id", [':id' => $id]);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Branch Details</h2>
    <div>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        <?php if ($auth->hasPermission('branches.edit')): ?>
            <a href="edit.php?id=<?= $branch['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Branch Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Branch Code:</th>
                        <td><?= escapeHtml($branch['branch_code']) ?></td>
                    </tr>
                    <tr>
                        <th>Branch Name:</th>
                        <td><?= escapeHtml($branch['branch_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Address:</th>
                        <td><?= escapeHtml($branch['address'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?= escapeHtml($branch['phone'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?= escapeHtml($branch['email'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Manager:</th>
                        <td><?= escapeHtml($branch['manager_name'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="badge bg-<?= $branch['status'] == 'Active' ? 'success' : 'secondary' ?>"><?= escapeHtml($branch['status']) ?></span></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Statistics</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6>Total Users</h6>
                    <h3 class="text-primary"><?= count($users) ?></h3>
                </div>
                <div class="mb-3">
                    <h6>Total Products</h6>
                    <h3 class="text-info"><?= $products ?></h3>
                </div>
                <div class="mb-3">
                    <h6>Total Invoices</h6>
                    <h3 class="text-success"><?= $invoices ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Branch Users</h5>
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="4" class="text-center">No users assigned to this branch</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= escapeHtml($user['first_name'] . ' ' . $user['last_name']) ?></td>
                            <td><?= escapeHtml($user['email']) ?></td>
                            <td><?= escapeHtml($user['role_id']) ?></td>
                            <td><span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'secondary' ?>"><?= escapeHtml(ucfirst($user['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('branches.view');

$pageTitle = 'Branches';

$db = Database::getInstance();
$branches = $db->getRows("SELECT b.*, u.first_name, u.last_name FROM branches b LEFT JOIN users u ON b.manager_id = u.id ORDER BY b.created_at DESC");

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Branches</h2>
    <?php if ($auth->hasPermission('branches.create')): ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Branch</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Branch Code</th>
                    <th>Branch Name</th>
                    <th>Address</th>
                    <th>Manager</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branches as $branch): ?>
                    <tr>
                        <td><?= escapeHtml($branch['branch_code']) ?></td>
                        <td><?= escapeHtml($branch['branch_name']) ?></td>
                        <td><?= escapeHtml($branch['address']) ?></td>
                        <td><?= escapeHtml(($branch['first_name'] ?? '') . ' ' . ($branch['last_name'] ?? 'N/A')) ?></td>
                        <td><span class="badge bg-<?= $branch['status'] == 'Active' ? 'success' : 'secondary' ?>"><?= escapeHtml($branch['status']) ?></span></td>
                        <td>
                            <a href="view.php?id=<?= $branch['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                            <?php if ($auth->hasPermission('branches.edit')): ?>
                                <a href="edit.php?id=<?= $branch['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


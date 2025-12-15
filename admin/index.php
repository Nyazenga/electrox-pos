<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/session.php';

initSession();

if (!isset($_SESSION['admin_user_id'])) {
    redirectTo('login.php');
}

$db = Database::getMainInstance();
$registrations = $db->getRows("SELECT * FROM tenant_registrations WHERE status = 'pending' ORDER BY created_at DESC");
$tenants = $db->getRows("SELECT * FROM tenants ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?= SYSTEM_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: 'Poppins', sans-serif; }
        .header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <h3><i class="bi bi-shield-lock"></i> Admin Panel</h3>
                <a href="logout.php" class="btn btn-light">Logout</a>
            </div>
        </div>
    </div>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5>Pending Registrations (<?= count($registrations) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($registrations)): ?>
                            <p class="text-muted">No pending registrations</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>Tenant</th>
                                        <th>Email</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registrations as $reg): ?>
                                        <tr>
                                            <td><?= escapeHtml($reg['company_name']) ?></td>
                                            <td><?= escapeHtml($reg['tenant_name']) ?></td>
                                            <td><?= escapeHtml($reg['contact_email']) ?></td>
                                            <td><?= formatDate($reg['created_at']) ?></td>
                                            <td>
                                                <a href="approve.php?id=<?= $reg['id'] ?>" class="btn btn-sm btn-success">Approve</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success">
                        <h5>Active Tenants (<?= count($tenants) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($tenants)): ?>
                            <p class="text-muted">No active tenants</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>Tenant</th>
                                        <th>Status</th>
                                        <th>Plan</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tenants as $tenant): ?>
                                        <tr>
                                            <td><?= escapeHtml($tenant['company_name']) ?></td>
                                            <td><?= escapeHtml($tenant['tenant_slug']) ?></td>
                                            <td><span class="badge bg-<?= $tenant['status'] == 'active' ? 'success' : 'secondary' ?>"><?= escapeHtml($tenant['status']) ?></span></td>
                                            <td><?= escapeHtml($tenant['subscription_plan']) ?></td>
                                            <td><?= formatDate($tenant['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


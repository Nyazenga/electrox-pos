<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.view');

$pageTitle = 'Settings';

$currentPage = $_GET['page'] ?? 'company';
$validPages = ['company', 'pos', 'inventory', 'financial'];
if (!in_array($currentPage, $validPages)) {
    $currentPage = 'company';
}

require_once APP_PATH . '/includes/header.php';
?>

<style>
.settings-container {
    display: flex;
    gap: 20px;
    min-height: calc(100vh - 200px);
}

.settings-sidebar {
    width: 250px;
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    height: fit-content;
    position: sticky;
    top: 20px;
}

.settings-sidebar .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    margin-bottom: 8px;
    border-radius: 8px;
    color: #495057;
    text-decoration: none;
    transition: all 0.2s;
}

.settings-sidebar .nav-link:hover {
    background: #f8f9fa;
    color: var(--primary-blue);
}

.settings-sidebar .nav-link.active {
    background: var(--primary-blue);
    color: white;
}

.settings-sidebar .nav-link i {
    font-size: 18px;
    width: 24px;
}

.settings-content {
    flex: 1;
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

@media (max-width: 768px) {
    .settings-container {
        flex-direction: column;
    }
    
    .settings-sidebar {
        width: 100%;
        position: relative;
        top: 0;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Settings</h2>
</div>

<div class="settings-container">
    <div class="settings-sidebar">
        <nav class="nav flex-column">
            <a class="nav-link <?= $currentPage == 'company' ? 'active' : '' ?>" href="index.php?page=company">
                <i class="bi bi-building"></i>
                <span>Company Settings</span>
            </a>
            <a class="nav-link <?= $currentPage == 'pos' ? 'active' : '' ?>" href="index.php?page=pos">
                <i class="bi bi-cart"></i>
                <span>POS Configuration</span>
            </a>
            <a class="nav-link <?= $currentPage == 'inventory' ? 'active' : '' ?>" href="index.php?page=inventory">
                <i class="bi bi-archive"></i>
                <span>Inventory Settings</span>
            </a>
            <a class="nav-link <?= $currentPage == 'financial' ? 'active' : '' ?>" href="index.php?page=financial">
                <i class="bi bi-cash-coin"></i>
                <span>Financial Settings</span>
            </a>
        </nav>
    </div>
    
    <div class="settings-content">
        <?php
        $pageFile = $currentPage . '.php';
        if (file_exists(__DIR__ . '/' . $pageFile)) {
            include $pageFile;
        } else {
            echo '<div class="alert alert-warning">Settings page not found.</div>';
        }
        ?>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

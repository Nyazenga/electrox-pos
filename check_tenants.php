<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=== CHECKING TENANT DATABASES AND REGISTRATIONS ===\n\n";

try {
    // Check databases
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SHOW DATABASES LIKE 'electrox_%'");
    $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "ELECTROX Databases found:\n";
    foreach ($databases as $db) {
        echo "  - $db\n";
    }
    echo "\n";
    
    // Check base database tenants
    $baseDb = Database::getMainInstance();
    $tenants = $baseDb->getRows("SELECT tenant_slug, database_name, status, company_name FROM tenants ORDER BY tenant_slug");
    
    echo "Tenants registered in electrox_base:\n";
    if (empty($tenants)) {
        echo "  - No tenants found\n";
    } else {
        foreach ($tenants as $tenant) {
            echo "  - Tenant Slug: {$tenant['tenant_slug']}\n";
            echo "    Database Name: {$tenant['database_name']}\n";
            echo "    Status: {$tenant['status']}\n";
            echo "    Company: {$tenant['company_name']}\n";
            echo "\n";
        }
    }
    
    // Check registrations
    $registrations = $baseDb->getRows("SELECT id, tenant_name, company_name, contact_email, status FROM tenant_registrations ORDER BY id DESC");
    
    echo "Pending Registrations:\n";
    if (empty($registrations)) {
        echo "  - No registrations found\n";
    } else {
        foreach ($registrations as $reg) {
            echo "  - ID: {$reg['id']}\n";
            echo "    Tenant Name: {$reg['tenant_name']}\n";
            echo "    Company: {$reg['company_name']}\n";
            echo "    Email: {$reg['contact_email']}\n";
            echo "    Status: {$reg['status']}\n";
            echo "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}


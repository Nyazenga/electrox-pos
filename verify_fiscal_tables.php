<?php
/**
 * Verify fiscal tables exist
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Verifying Fiscal Tables ===\n\n";

$primaryDb = Database::getPrimaryInstance();
$pdo = $primaryDb->getPdo();

echo "Database: " . PRIMARY_DB_NAME . "\n";
echo "Connection: " . ($pdo ? 'OK' : 'FAILED') . "\n\n";

// Check if table exists
$tables = ['fiscal_devices', 'fiscal_days', 'fiscal_receipts', 'fiscal_config'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        echo ($exists ? '✓' : '✗') . " $table: " . ($exists ? 'EXISTS' : 'NOT FOUND') . "\n";
        
        if ($exists) {
            // Get row count
            $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM $table");
            $count = $countStmt->fetch()['cnt'];
            echo "  Rows: $count\n";
        }
    } catch (Exception $e) {
        echo "✗ $table: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n=== Verification Complete ===\n";


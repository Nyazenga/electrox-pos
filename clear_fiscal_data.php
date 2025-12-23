<?php
/**
 * Clear fiscal receipts and fiscal days data
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

echo "========================================\n";
echo "CLEARING FISCAL DATA\n";
echo "========================================\n\n";

try {
    $receiptsDeleted = $db->executeQuery("DELETE FROM fiscal_receipts");
    $daysDeleted = $db->executeQuery("DELETE FROM fiscal_days");
    
    echo "✓ Cleared fiscal_receipts table\n";
    echo "✓ Cleared fiscal_days table\n";
    echo "\nDatabase cleared successfully!\n";
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";


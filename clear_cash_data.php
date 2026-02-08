<?php
/**
 * Clear Cash Management Data
 * Clears shifts and drawer_transactions tables
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

echo "==========================================\n";
echo "Clearing Cash Management Data\n";
echo "==========================================\n\n";

try {
    $pdo = $db->getPdo();
    
    // Disable foreign key checks temporarily
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Clear drawer_transactions
    echo "Step 1: Clearing drawer_transactions...\n";
    $pdo->exec("TRUNCATE TABLE drawer_transactions");
    echo "✓ drawer_transactions cleared\n";
    
    // Clear shifts
    echo "Step 2: Clearing shifts...\n";
    $pdo->exec("TRUNCATE TABLE shifts");
    echo "✓ shifts cleared\n";
    
    // Reset auto-increment
    echo "Step 3: Resetting auto-increment...\n";
    $pdo->exec("ALTER TABLE drawer_transactions AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE shifts AUTO_INCREMENT = 1");
    echo "✓ Auto-increment reset\n";
    
    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Verify
    $drawerCount = $db->getValue("SELECT COUNT(*) FROM drawer_transactions");
    $shiftCount = $db->getValue("SELECT COUNT(*) FROM shifts");
    
    echo "\n==========================================\n";
    echo "✓ Cash Management Data Cleared\n";
    echo "==========================================\n\n";
    echo "Verification:\n";
    echo "  - drawer_transactions: {$drawerCount} rows\n";
    echo "  - shifts: {$shiftCount} rows\n";
    echo "\n✓ All cash management data has been cleared!\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

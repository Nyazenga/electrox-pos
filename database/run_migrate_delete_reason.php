<?php
/**
 * Run delete_reason column migration across all tenant databases
 */
$isLocal = (php_uname('s') === 'Windows NT' || strpos(php_uname('n'), 'DESKTOP') !== false || file_exists('C:/xampp'));
$dbUser = $isLocal ? 'root' : 'grcadmin';
$dbPass = $isLocal ? '' : 'Adm1n@GRC2024!';
$dbHost = 'localhost';

try {
    $primaryDb = new PDO("mysql:host=$dbHost;dbname=electrox_primary", $dbUser, $dbPass);
    $primaryDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $tenants = $primaryDb->query("SELECT tenant_slug FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tenants as $slug) {
        $dbName = 'electrox_' . $slug;
        echo "Processing $dbName...\n";
        try {
            $conn = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Check if delete_reason column exists
            $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'delete_reason'");
            $stmt->execute([$dbName]);
            
            if ($stmt->fetchColumn() == 0) {
                $conn->exec("ALTER TABLE sales ADD COLUMN delete_reason TEXT NULL AFTER deleted_by");
                echo "  ✅ Added delete_reason column\n";
            } else {
                echo "  ✅ delete_reason column already exists\n";
            }
        } catch (Exception $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n";
        }
    }
    echo "\nDone!\n";
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

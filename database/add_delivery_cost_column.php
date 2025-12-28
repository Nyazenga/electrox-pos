<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Get current database name
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    
    // Check if column exists
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                        WHERE TABLE_SCHEMA = ? 
                        AND TABLE_NAME = 'refunds' 
                        AND COLUMN_NAME = 'delivery_cost'");
    $stmt->execute([$dbName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['count'] > 0) {
        echo "Column 'delivery_cost' already exists in 'refunds' table.\n";
    } else {
        echo "Adding 'delivery_cost' column to 'refunds' table...\n";
        
        // Add delivery_cost column
        $pdo->exec("ALTER TABLE refunds ADD COLUMN delivery_cost DECIMAL(10,2) DEFAULT 0.00 AFTER discount_amount");
        
        echo "Successfully added 'delivery_cost' column to 'refunds' table.\n";
    }
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), '1060') !== false) {
        echo "Column 'delivery_cost' already exists (caught by exception).\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}


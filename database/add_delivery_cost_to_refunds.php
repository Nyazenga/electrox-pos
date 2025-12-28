<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

try {
    $db = Database::getInstance();
    
    // Check if column already exists
    $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'refunds' 
                            AND COLUMN_NAME = 'delivery_cost'");
    
    if ($colCheck && $colCheck['count'] > 0) {
        echo "Column 'delivery_cost' already exists in 'refunds' table.\n";
        exit(0);
    }
    
    // Add delivery_cost column
    $result = $db->query("ALTER TABLE refunds ADD COLUMN delivery_cost DECIMAL(10,2) DEFAULT 0.00 AFTER discount_amount");
    
    if ($result !== false) {
        echo "Successfully added 'delivery_cost' column to 'refunds' table.\n";
    } else {
        $error = $db->getLastError();
        if (strpos($error, 'Duplicate column') !== false) {
            echo "Column 'delivery_cost' already exists in 'refunds' table.\n";
        } else {
            throw new Exception("Failed to add column: " . $error);
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}


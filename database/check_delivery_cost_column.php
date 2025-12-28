<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

try {
    $db = Database::getInstance();
    
    // Check if column exists
    $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'refunds' 
                            AND COLUMN_NAME = 'delivery_cost'");
    
    if ($colCheck && $colCheck['count'] > 0) {
        echo "Column 'delivery_cost' EXISTS in 'refunds' table.\n";
    } else {
        echo "Column 'delivery_cost' DOES NOT EXIST in 'refunds' table.\n";
        echo "Attempting to add it...\n";
        
        // Add delivery_cost column
        $result = $db->query("ALTER TABLE refunds ADD COLUMN delivery_cost DECIMAL(10,2) DEFAULT 0.00 AFTER discount_amount");
        
        if ($result !== false) {
            echo "Successfully added 'delivery_cost' column to 'refunds' table.\n";
        } else {
            $error = $db->getLastError();
            echo "Error: " . $error . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}


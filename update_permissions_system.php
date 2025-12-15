<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

// Read and execute the permissions system SQL
$sqlFile = APP_PATH . '/database/add_permissions_system.sql';
if (file_exists($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            $db->getPdo()->exec($statement);
        } catch (PDOException $e) {
            // Ignore "already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Duplicate') === false) {
                echo "Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "Permissions system tables created successfully!\n";
} else {
    echo "SQL file not found: $sqlFile\n";
}



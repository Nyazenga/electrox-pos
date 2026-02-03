<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 10);

echo "Checking MySQL connection...\n";
flush();

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = 'GRCAdmin123/';

// First, try to connect without specifying database
try {
    echo "Attempting connection to MySQL server...\n";
    flush();
    
    $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
    
    echo "✓ Connected to MySQL server!\n";
    flush();
    
    // List databases
    echo "\nAvailable databases:\n";
    $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($databases as $db) {
        echo "  - $db\n";
    }
    
    // Check if electrox_primary exists
    if (in_array('electrox_primary', $databases)) {
        echo "\n✓ Database 'electrox_primary' exists!\n";
        
        // Try to use it
        $pdo->exec("USE electrox_primary");
        echo "✓ Switched to electrox_primary database\n";
        
        // Check users table
        $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
        if (!empty($tables)) {
            echo "✓ Users table exists\n";
            
            // Count users
            $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            echo "  Total users: $count\n";
            
            // List first few users
            $users = $pdo->query("SELECT id, email, username FROM users LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            echo "\nFirst few users:\n";
            foreach ($users as $u) {
                echo "  - ID: {$u['id']}, Email: {$u['email']}, Username: {$u['username']}\n";
            }
        } else {
            echo "✗ Users table does not exist\n";
        }
    } else {
        echo "\n✗ Database 'electrox_primary' does NOT exist!\n";
    }
    
} catch (PDOException $e) {
    echo "✗ Connection Error: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

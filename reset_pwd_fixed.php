<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(10);

echo "Starting password reset...\n";
flush();

// Try different connection methods
$dbHost = '127.0.0.1'; // Try IP instead of localhost
$dbUser = 'root';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';
$email = 'nyazengamd@gmail.com';
$newPassword = 'Admin123/';

define('HASH_COST', 10);

// Test connection first
echo "Testing MySQL connection...\n";
flush();

try {
    // Try with shorter timeout
    $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
    echo "✓ MySQL connection successful\n";
    flush();
    
    // Check if database exists
    $dbExists = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$dbName'")->fetch();
    if (!$dbExists) {
        echo "✗ Database '$dbName' does not exist!\n";
        echo "Available databases:\n";
        $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dbs as $db) {
            echo "  - $db\n";
        }
        exit(1);
    }
    
    echo "✓ Database '$dbName' exists\n";
    flush();
    
    // Connect to specific database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
    
    echo "✓ Connected to database\n";
    flush();
    
    // Find user
    echo "Searching for user: $email\n";
    flush();
    
    $stmt = $pdo->prepare("SELECT id, email, username, first_name, last_name, status FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "✗ User not found!\n";
        echo "Available users:\n";
        $allUsers = $pdo->query("SELECT id, email, username FROM users LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allUsers as $u) {
            echo "  - {$u['email']} (ID: {$u['id']})\n";
        }
        exit(1);
    }
    
    echo "✓ User found: ID={$user['id']}, Email={$user['email']}\n";
    flush();
    
    // Generate hash
    echo "Generating password hash...\n";
    flush();
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
    
    // Update password
    echo "Updating password...\n";
    flush();
    $updateStmt = $pdo->prepare("UPDATE users SET password = :password, login_attempts = 0, status = 'active' WHERE id = :id");
    $result = $updateStmt->execute([
        ':password' => $hashedPassword,
        ':id' => $user['id']
    ]);
    
    if ($result && $updateStmt->rowCount() > 0) {
        echo "\n";
        echo "========================================\n";
        echo "✓ SUCCESS: Password updated!\n";
        echo "========================================\n";
        echo "Tenant: primary\n";
        echo "Email: $email\n";
        echo "Password: $newPassword\n";
        echo "URL: https://electrox.bulconsultancy.com/login.php\n";
        echo "========================================\n";
    } else {
        echo "✗ Failed to update (no rows affected)\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    
    // Try localhost if 127.0.0.1 failed
    if ($dbHost === '127.0.0.1') {
        echo "\nTrying with 'localhost' instead...\n";
        flush();
        // Could add retry logic here
    }
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(10);

echo "Starting password reset...\n";
flush();

// Use production credentials (grcadmin user)
$dbHost = 'localhost';
$dbUser = 'grcadmin';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';
$email = 'nyazengamd@gmail.com';
$newPassword = 'Admin123/';

define('HASH_COST', 10);

echo "Connecting to database as $dbUser...\n";
flush();

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    echo "✓ Connected to database: $dbName\n";
    flush();
    
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
            echo "  - {$u['email']} (ID: {$u['id']}, Username: {$u['username']})\n";
        }
        exit(1);
    }
    
    echo "✓ User found:\n";
    echo "  ID: {$user['id']}\n";
    echo "  Email: {$user['email']}\n";
    echo "  Username: {$user['username']}\n";
    echo "  Name: {$user['first_name']} {$user['last_name']}\n";
    echo "  Status: {$user['status']}\n";
    flush();
    
    echo "Generating password hash...\n";
    flush();
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
    
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
        echo "URL: https://electrox-pos.com/login.php\n";
        echo "========================================\n";
    } else {
        echo "✗ Failed to update (no rows affected)\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

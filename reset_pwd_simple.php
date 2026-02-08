<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 30);

echo "Starting password reset script...\n";
flush();

define('HASH_COST', 10);
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';
$email = 'nyazengamd@gmail.com';
$newPassword = 'Admin123/';

echo "Connecting to database: $dbName\n";
flush();

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    echo "Connected successfully!\n";
    flush();
    
    echo "Searching for user: $email\n";
    flush();
    
    $stmt = $pdo->prepare("SELECT id, email, username, first_name, last_name, status FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "ERROR: User not found!\n";
        echo "Listing available users:\n";
        $allUsers = $pdo->query("SELECT id, email, username FROM users LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allUsers as $u) {
            echo "  - {$u['email']} (ID: {$u['id']}, Username: {$u['username']})\n";
        }
        exit(1);
    }
    
    echo "User found:\n";
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
    
    if ($result) {
        echo "\n";
        echo "========================================\n";
        echo "SUCCESS: Password updated!\n";
        echo "========================================\n";
        echo "New login credentials:\n";
        echo "  Tenant: primary\n";
        echo "  Email: $email\n";
        echo "  Password: $newPassword\n";
        echo "  Login URL: https://electrox-pos.com/login.php\n";
        echo "========================================\n";
    } else {
        echo "FAILED: Could not update password\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

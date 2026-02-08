<?php
define('HASH_COST', 10);
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';
$email = 'nyazengamd@gmail.com';
$newPassword = 'Admin123/';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected to database: $dbName\n";
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "ERROR: User not found!\n";
        $allUsers = $pdo->query("SELECT id, email, username FROM users LIMIT 10")->fetchAll();
        echo "Available users:\n";
        foreach ($allUsers as $u) {
            echo "  - {$u['email']} (ID: {$u['id']})\n";
        }
        exit(1);
    }
    
    echo "User found: ID={$user['id']}, Email={$user['email']}\n";
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
    $updateStmt = $pdo->prepare("UPDATE users SET password = :password, login_attempts = 0, status = 'active' WHERE id = :id");
    $result = $updateStmt->execute([':password' => $hashedPassword, ':id' => $user['id']]);
    
    if ($result) {
        echo "SUCCESS: Password updated!\n";
        echo "New password: $newPassword\n";
        echo "Login at: https://electrox-pos.com/login.php\n";
    } else {
        echo "FAILED: Could not update password\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

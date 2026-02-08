#!/bin/bash
# Password Reset Script for Remote Server
# Run this on the server: bash reset_password_remote.sh

cd /var/www/html/electrox-pos

php << 'ENDPHP'
<?php
// Database credentials for electrox_primary
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';

$email = 'nyazengamd@gmail.com';
$newPassword = 'Admin123/';

// Define HASH_COST
define('HASH_COST', 10);

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    echo "Connected to database: $dbName\n";
    echo "===========================================\n\n";
    
    // Find the user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "ERROR: User with email '$email' not found!\n";
        echo "Searching for similar emails...\n";
        
        // Try case-insensitive search
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email)");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // List all users
            $allUsers = $pdo->query("SELECT id, email, username, first_name, last_name FROM users LIMIT 20")->fetchAll();
            echo "\nAvailable users in database:\n";
            foreach ($allUsers as $u) {
                echo "  - ID: {$u['id']}, Email: {$u['email']}, Username: {$u['username']}, Name: {$u['first_name']} {$u['last_name']}\n";
            }
            exit(1);
        }
    }
    
    echo "User found:\n";
    echo "  ID: " . $user['id'] . "\n";
    echo "  Email: " . $user['email'] . "\n";
    echo "  Username: " . ($user['username'] ?? 'N/A') . "\n";
    echo "  Name: " . ($user['first_name'] ?? '') . " " . ($user['last_name'] ?? '') . "\n";
    echo "  Status: " . ($user['status'] ?? 'N/A') . "\n";
    echo "\n";
    
    // Hash the new password using the same method as the system
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
    
    echo "New password hash generated:\n";
    echo "  $hashedPassword\n";
    echo "\n";
    
    // Update the password in the database
    $updateStmt = $pdo->prepare("UPDATE users SET password = :password, login_attempts = 0, status = 'active' WHERE id = :id");
    $result = $updateStmt->execute([
        ':password' => $hashedPassword,
        ':id' => $user['id']
    ]);
    
    if ($result) {
        echo "✓ Password successfully updated!\n";
        echo "✓ Login attempts reset to 0\n";
        echo "✓ Account status set to 'active'\n";
        echo "\n";
        echo "New login credentials:\n";
        echo "  Tenant: primary\n";
        echo "  Email: $email\n";
        echo "  Password: $newPassword\n";
        echo "\n";
        echo "You can now login at: https://electrox-pos.com/login.php\n";
    } else {
        echo "✗ Failed to update password\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
ENDPHP

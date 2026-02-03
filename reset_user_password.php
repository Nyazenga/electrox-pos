<?php
require_once __DIR__ . '/config.php';

// Database credentials - using root with empty password for localhost
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = PRIMARY_DB_NAME;

$email = 'nyazengamd@gmail.com';
$newPassword = 'Admin123/';

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=" . DB_CHARSET;
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
        exit(1);
    }
    
    echo "User found:\n";
    echo "  ID: " . $user['id'] . "\n";
    echo "  Email: " . $user['email'] . "\n";
    echo "  Username: " . ($user['username'] ?? 'N/A') . "\n";
    echo "  Name: " . ($user['first_name'] ?? '') . " " . ($user['last_name'] ?? '') . "\n";
    echo "\n";
    
    // Hash the new password
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
    
    echo "New password hash generated:\n";
    echo "  $hashedPassword\n";
    echo "\n";
    
    // Update the password in the database
    $updateStmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
    $result = $updateStmt->execute([
        ':password' => $hashedPassword,
        ':id' => $user['id']
    ]);
    
    if ($result) {
        echo "✓ Password successfully updated!\n";
        echo "\n";
        echo "New login credentials:\n";
        echo "  Email: $email\n";
        echo "  Password: $newPassword\n";
        echo "\n";
        
        // Verify the password hash works
        $verifyStmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
        $verifyStmt->execute([':id' => $user['id']]);
        $updatedUser = $verifyStmt->fetch();
        
        if (password_verify($newPassword, $updatedUser['password'])) {
            echo "✓ Password verification successful!\n";
        } else {
            echo "✗ WARNING: Password verification failed!\n";
        }
    } else {
        echo "✗ ERROR: Failed to update password!\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
}

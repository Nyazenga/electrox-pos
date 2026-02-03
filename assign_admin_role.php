<?php
require_once __DIR__ . '/config.php';

// Database credentials - using root with empty password for localhost
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = PRIMARY_DB_NAME;

$email = 'nyazengamd@gmail.com';

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
    
    // First, check available roles
    echo "Available roles:\n";
    $rolesStmt = $pdo->query("SELECT * FROM roles ORDER BY id");
    $roles = $rolesStmt->fetchAll();
    
    foreach ($roles as $role) {
        echo "  ID: {$role['id']} - {$role['name']} (Status: {$role['status']})\n";
    }
    echo "\n";
    
    // Find administrator role (case-insensitive search)
    $adminRoleStmt = $pdo->prepare("
        SELECT * FROM roles 
        WHERE LOWER(name) LIKE '%administrator%' OR LOWER(name) LIKE '%admin%'
        ORDER BY id LIMIT 1
    ");
    $adminRoleStmt->execute();
    $adminRole = $adminRoleStmt->fetch();
    
    if (!$adminRole) {
        echo "ERROR: Administrator role not found!\n";
        echo "Please check the roles table and specify the correct role ID.\n";
        exit(1);
    }
    
    echo "Administrator role found:\n";
    echo "  ID: {$adminRole['id']}\n";
    echo "  Name: {$adminRole['name']}\n";
    echo "  Status: {$adminRole['status']}\n";
    echo "\n";
    
    // Find the user
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $userStmt->execute([':email' => $email]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        echo "ERROR: User with email '$email' not found!\n";
        exit(1);
    }
    
    echo "User found:\n";
    echo "  ID: " . $user['id'] . "\n";
    echo "  Email: " . $user['email'] . "\n";
    echo "  Username: " . ($user['username'] ?? 'N/A') . "\n";
    echo "  Current Role ID: " . ($user['role_id'] ?? 'NULL') . "\n";
    
    if ($user['role_id']) {
        $currentRoleStmt = $pdo->prepare("SELECT * FROM roles WHERE id = :id");
        $currentRoleStmt->execute([':id' => $user['role_id']]);
        $currentRole = $currentRoleStmt->fetch();
        if ($currentRole) {
            echo "  Current Role Name: " . $currentRole['name'] . "\n";
        }
    }
    echo "\n";
    
    // Update the user's role
    $updateStmt = $pdo->prepare("UPDATE users SET role_id = :role_id WHERE id = :id");
    $result = $updateStmt->execute([
        ':role_id' => $adminRole['id'],
        ':id' => $user['id']
    ]);
    
    if ($result) {
        echo "✓ Role successfully updated!\n";
        echo "\n";
        echo "User now has Administrator role:\n";
        echo "  Email: $email\n";
        echo "  Role: {$adminRole['name']} (ID: {$adminRole['id']})\n";
        echo "\n";
        
        // Verify the update
        $verifyStmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = :id");
        $verifyStmt->execute([':id' => $user['id']]);
        $updatedUser = $verifyStmt->fetch();
        
        if ($updatedUser && $updatedUser['role_id'] == $adminRole['id']) {
            echo "✓ Role verification successful!\n";
            echo "  Confirmed Role: {$updatedUser['role_name']}\n";
        } else {
            echo "✗ WARNING: Role verification failed!\n";
        }
    } else {
        echo "✗ ERROR: Failed to update role!\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
$host = 'localhost';
$user = 'root';
$pass = '';

$adminPass = '$2y$10$3xkndv4Den7JbXkyUOfm2urr7JNex7EWTd7a0sXn9W0CgJIa8L116';
$cashierPass = '$2y$10$3xkndv4Den7JbXkyUOfm2urr7JNex7EWTd7a0sXn9W0CgJIa8L116';

try {
    $pdo = new PDO("mysql:host=$host;dbname=electrox_primary", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("UPDATE users SET password = '$adminPass' WHERE username = 'admin'");
    $pdo->exec("UPDATE users SET password = '$cashierPass' WHERE username = 'cashier'");
    
    $pdo = new PDO("mysql:host=$host;dbname=electrox_base", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("UPDATE admin_users SET password = '$adminPass' WHERE username = 'admin'");
    
    echo "Passwords updated successfully!\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}


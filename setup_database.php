<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Creating base database...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `electrox_base` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `electrox_base`");
    
    $baseSql = file_get_contents(__DIR__ . '/database/base_schema.sql');
    $baseSql = preg_replace('/CREATE DATABASE.*?;/i', '', $baseSql);
    $baseSql = preg_replace('/USE.*?;/i', '', $baseSql);
    
    $statements = explode(';', $baseSql);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "Base database created successfully!\n";
    
    echo "Creating primary database...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `electrox_primary` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `electrox_primary`");
    
    $primarySql = file_get_contents(__DIR__ . '/database/primary_schema.sql');
    $primarySql = preg_replace('/CREATE DATABASE.*?;/i', '', $primarySql);
    $primarySql = preg_replace('/USE.*?;/i', '', $primarySql);
    
    $statements = explode(';', $primarySql);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') === false) {
                    echo "Warning: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "Primary database created successfully!\n";
    echo "\n=== DATABASE SETUP COMPLETE ===\n";
    echo "Base Database: electrox_base\n";
    echo "Primary Database: electrox_primary\n";
    echo "\nYou can now login with:\n";
    echo "URL: http://localhost/electrox-pos/login.php\n";
    echo "Tenant: (register a new tenant first)\n";
    echo "Email: admin@electrox.co.zw\n";
    echo "Password: Admin@123\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}


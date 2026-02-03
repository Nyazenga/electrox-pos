<?php
require_once __DIR__ . '/config.php';

// Connect to electrox_primary database
// Override credentials to use root with empty password for localhost
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = PRIMARY_DB_NAME;

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    echo "Connected to database: " . PRIMARY_DB_NAME . "\n";
    echo "===========================================\n\n";
    
    // Search for user with email nyazengamd@gmail.com
    $email = 'nyazengamd@gmail.com';
    
    // First, check if there's a users table in primary database
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Available tables: " . implode(', ', $tables) . "\n\n";
    
    // Check if users table exists in primary database
    if (in_array('users', $tables)) {
        echo "Found 'users' table in primary database.\n";
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo "\n=== USER FOUND IN PRIMARY DATABASE ===\n";
            echo "ID: " . $user['id'] . "\n";
            echo "Email: " . $user['email'] . "\n";
            echo "Username: " . ($user['username'] ?? 'N/A') . "\n";
            echo "First Name: " . ($user['first_name'] ?? 'N/A') . "\n";
            echo "Last Name: " . ($user['last_name'] ?? 'N/A') . "\n";
            echo "Status: " . ($user['status'] ?? 'N/A') . "\n";
            echo "\nHASHED PASSWORD:\n";
            echo $user['password'] . "\n";
            echo "\nPassword Hash Info:\n";
            echo "- Algorithm: " . (password_get_info($user['password'])['algoName'] ?? 'Unknown') . "\n";
            echo "- Options: " . print_r(password_get_info($user['password'])['options'] ?? [], true) . "\n";
        } else {
            echo "\nUser with email '$email' NOT found in primary database users table.\n";
        }
    } else {
        echo "No 'users' table found in primary database.\n";
    }
    
    // Also check tenants table - users might be in tenant databases
    if (in_array('tenants', $tables)) {
        echo "\n=== CHECKING TENANT DATABASES ===\n";
        $stmt = $pdo->query("SELECT * FROM tenants WHERE status = 'active'");
        $tenants = $stmt->fetchAll();
        
        foreach ($tenants as $tenant) {
            $tenantName = $tenant['name'];
            $tenantDbName = 'electrox_' . $tenantName;
            
            echo "\nChecking tenant: $tenantName (database: $tenantDbName)\n";
            
            try {
                $tenantDsn = "mysql:host=$dbHost;dbname=$tenantDbName;charset=" . DB_CHARSET;
                $tenantPdo = new PDO($tenantDsn, $dbUser, $dbPass, $options);
                
                $stmt = $tenantPdo->prepare("SELECT * FROM users WHERE email = :email");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    echo "\n*** USER FOUND IN TENANT: $tenantName ***\n";
                    echo "ID: " . $user['id'] . "\n";
                    echo "Email: " . $user['email'] . "\n";
                    echo "Username: " . ($user['username'] ?? 'N/A') . "\n";
                    echo "First Name: " . ($user['first_name'] ?? 'N/A') . "\n";
                    echo "Last Name: " . ($user['last_name'] ?? 'N/A') . "\n";
                    echo "Status: " . ($user['status'] ?? 'N/A') . "\n";
                    echo "\nHASHED PASSWORD:\n";
                    echo $user['password'] . "\n";
                    echo "\nPassword Hash Info:\n";
                    $hashInfo = password_get_info($user['password']);
                    echo "- Algorithm: " . ($hashInfo['algoName'] ?? 'Unknown') . "\n";
                    echo "- Options: " . print_r($hashInfo['options'] ?? [], true) . "\n";
                    echo "\n===========================================\n";
                }
            } catch (PDOException $e) {
                echo "  Could not connect to tenant database: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n\n=== IMPORTANT NOTE ===\n";
    echo "BCRYPT passwords CANNOT be 'unhashed' - they are one-way hashes.\n";
    echo "The password shown above is the hashed version stored in the database.\n";
    echo "To reset the password, you would need to:\n";
    echo "1. Generate a new password hash using password_hash()\n";
    echo "2. Update the database with the new hash\n";
    echo "Or use password_verify() to test if a plain text password matches the hash.\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
}

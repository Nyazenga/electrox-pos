<?php
/**
 * Debug the exact database and query used by products/index.php
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/session.php';

initSession();

echo "=== DEBUGGING PRODUCTS PAGE ===\n\n";

// Check session
echo "Session variables:\n";
echo "  tenant_name: " . ($_SESSION['tenant_name'] ?? 'NOT SET') . "\n";
echo "  branch_id: " . ($_SESSION['branch_id'] ?? 'NOT SET') . "\n";
echo "  user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n\n";

// Get database instance (same as products/index.php)
$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Check which database we're connected to
echo "Database connections:\n";
try {
    $result = $db->getRow("SELECT DATABASE() as db_name");
    echo "  \$db (getInstance): " . ($result['db_name'] ?? 'UNKNOWN') . "\n";
} catch (Exception $e) {
    echo "  \$db (getInstance): ERROR - " . $e->getMessage() . "\n";
}

try {
    $result = $primaryDb->getRow("SELECT DATABASE() as db_name");
    echo "  \$primaryDb (getPrimaryInstance): " . ($result['db_name'] ?? 'UNKNOWN') . "\n";
} catch (Exception $e) {
    echo "  \$primaryDb (getPrimaryInstance): ERROR - " . $e->getMessage() . "\n";
}

echo "\n";

// Check products in each database
$databases = ['electrox_primary', 'electrox_belgravia', 'electrox_ridgeway'];

foreach ($databases as $dbName) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=$dbName;charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
        $count = $stmt->fetch()['count'];
        
        echo "Products in $dbName: $count\n";
        
        if ($count > 0) {
            $stmt = $pdo->query("SELECT branch_id, COUNT(*) as count FROM products GROUP BY branch_id");
            $branches = $stmt->fetchAll();
            foreach ($branches as $b) {
                echo "  - Branch ID {$b['branch_id']}: {$b['count']} products\n";
            }
        }
    } catch (Exception $e) {
        echo "Products in $dbName: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Now test the exact query from products/index.php
$branchId = $_SESSION['branch_id'] ?? null;
$selectedBranch = 'all';
$whereConditions = ["1=1"];
$params = [];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

$whereClause = implode(' AND ', $whereConditions);

$query = "SELECT p.*, pc.name as category_name, pc.tax_id as category_tax_id, b.branch_name 
          FROM products p 
          LEFT JOIN product_categories pc ON p.category_id = pc.id 
          LEFT JOIN branches b ON p.branch_id = b.id 
          WHERE $whereClause
          ORDER BY p.created_at DESC";

echo "Query being executed:\n";
echo $query . "\n\n";
echo "Parameters: ";
print_r($params);
echo "\n";

try {
    $products = $db->getRows($query, $params);
    if ($products === false) {
        $products = [];
    }
    echo "Results: " . count($products) . " product(s) found\n";
    
    if (count($products) > 0) {
        echo "\nFirst 3 products:\n";
        foreach (array_slice($products, 0, 3) as $p) {
            echo "  - {$p['product_code']}: " . ($p['product_name'] ?? ($p['brand'] . ' ' . $p['model'])) . " (Branch: {$p['branch_id']})\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR executing query: " . $e->getMessage() . "\n";
}


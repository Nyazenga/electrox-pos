<?php
/**
 * Test the exact query used by products/index.php
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/session.php';

// Simulate a session with branch_id
initSession();
$_SESSION['branch_id'] = 1; // BELGRAVIA

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;

echo "Testing products query...\n\n";
echo "Branch ID from session: " . ($branchId ?? 'NULL') . "\n\n";

// Get filters (matching products/index.php logic)
$selectedBranch = 'all'; // Default to 'all' if not specified
$categoryId = 'all';
$status = 'all';
$stockLevel = 'all';
$source = 'all';
$search = '';

// Build query exactly as in products/index.php
$whereConditions = ["1=1"];
$params = [];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($categoryId !== 'all' && $categoryId) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $categoryId;
}

if ($status !== 'all') {
    $whereConditions[] = "p.status = :status";
    $params[':status'] = $status;
}

if ($stockLevel !== 'all') {
    if ($stockLevel === 'out') {
        $whereConditions[] = "p.quantity_in_stock = 0";
    } elseif ($stockLevel === 'low') {
        $whereConditions[] = "p.quantity_in_stock > 0 AND p.quantity_in_stock <= p.reorder_level";
    } elseif ($stockLevel === 'below_reorder') {
        $whereConditions[] = "p.quantity_in_stock <= p.reorder_level";
    } elseif ($stockLevel === 'in_stock') {
        $whereConditions[] = "p.quantity_in_stock > p.reorder_level";
    }
}

if ($source !== 'all') {
    $whereConditions[] = "p.source = :source";
    $params[':source'] = $source;
}

if ($search) {
    $whereConditions[] = "(p.brand LIKE :search1 
                          OR p.model LIKE :search2 
                          OR p.product_name LIKE :search5 
                          OR p.product_code LIKE :search3 
                          OR p.description LIKE :search4
                          OR CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, '')) LIKE :search6
                          OR CONCAT(COALESCE(p.product_name, ''), ' ', COALESCE(p.brand, ''), ' ', COALESCE(p.model, '')) LIKE :search7)";
    $searchTerm = "%$search%";
    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
    $params[':search4'] = $searchTerm;
    $params[':search5'] = $searchTerm;
    $params[':search6'] = $searchTerm;
    $params[':search7'] = $searchTerm;
}

$whereClause = implode(' AND ', $whereConditions);

$query = "SELECT p.*, pc.name as category_name, pc.tax_id as category_tax_id, b.branch_name 
          FROM products p 
          LEFT JOIN product_categories pc ON p.category_id = pc.id 
          LEFT JOIN branches b ON p.branch_id = b.id 
          WHERE $whereClause
          ORDER BY p.created_at DESC";

echo "Query:\n";
echo $query . "\n\n";
echo "Parameters:\n";
print_r($params);
echo "\n";

try {
    $products = $db->getRows($query, $params);
    
    if ($products === false) {
        $products = [];
    }
    
    echo "Results: " . count($products) . " product(s) found\n\n";
    
    if (count($products) > 0) {
        echo "First 5 products:\n";
        foreach (array_slice($products, 0, 5) as $product) {
            echo "  - ID: {$product['id']}, Code: {$product['product_code']}, Branch: {$product['branch_id']} ({$product['branch_name']}), Name: " . ($product['product_name'] ?? ($product['brand'] . ' ' . $product['model'])) . "\n";
        }
    } else {
        echo "No products found!\n\n";
        
        // Debug: Check what's in the database
        echo "Debugging:\n";
        $allProducts = $db->getRows("SELECT id, product_code, branch_id, status, source FROM products LIMIT 10");
        echo "Total products in database: " . count($allProducts) . "\n";
        if (count($allProducts) > 0) {
            echo "Sample products:\n";
            foreach ($allProducts as $p) {
                echo "  - ID: {$p['id']}, Code: {$p['product_code']}, Branch: {$p['branch_id']}, Status: {$p['status']}, Source: {$p['source']}\n";
            }
        }
        
        // Check branches
        $branches = $db->getRows("SELECT id, branch_name FROM branches");
        echo "\nBranches:\n";
        foreach ($branches as $b) {
            echo "  - ID: {$b['id']}, Name: {$b['branch_name']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}


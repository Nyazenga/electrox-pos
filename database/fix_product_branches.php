<?php
/**
 * Fix product branch assignments - update invalid branch_ids to valid ones
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

echo "Fixing product branch assignments...\n\n";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Get valid branch IDs
    $stmt = $pdo->query("SELECT id FROM branches WHERE status = 'Active' ORDER BY id");
    $validBranches = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($validBranches)) {
        echo "❌ No active branches found!\n";
        exit(1);
    }
    
    echo "Valid branch IDs: " . implode(', ', $validBranches) . "\n\n";
    
    // Check current distribution
    echo "Current product distribution:\n";
    $stmt = $pdo->query("SELECT branch_id, COUNT(*) as count FROM products GROUP BY branch_id ORDER BY branch_id");
    $distribution = $stmt->fetchAll();
    foreach ($distribution as $row) {
        $branchId = $row['branch_id'] ?? 'NULL';
        $isValid = in_array($row['branch_id'], $validBranches);
        $status = $isValid ? '✓' : '✗ INVALID';
        echo "  Branch ID $branchId: {$row['count']} products $status\n";
    }
    
    // Find products with invalid branch_ids
    $placeholders = implode(',', array_fill(0, count($validBranches), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE branch_id IS NULL OR branch_id NOT IN ($placeholders)");
    $stmt->execute($validBranches);
    $invalidCount = $stmt->fetch()['count'];
    
    if ($invalidCount > 0) {
        echo "\nFound $invalidCount product(s) with invalid branch_id\n";
        echo "Updating to branch_id = {$validBranches[0]} (first valid branch)...\n";
        
        $stmt = $pdo->prepare("UPDATE products SET branch_id = ? WHERE branch_id IS NULL OR branch_id NOT IN ($placeholders)");
        $params = array_merge([$validBranches[0]], $validBranches);
        $stmt->execute($params);
        $updated = $stmt->rowCount();
        
        echo "✓ Updated $updated product(s)\n";
    } else {
        echo "\n✓ All products have valid branch_ids\n";
    }
    
    // Verify final distribution
    echo "\nFinal product distribution:\n";
    $stmt = $pdo->query("SELECT b.id, b.branch_name, COUNT(p.id) as count 
                         FROM branches b 
                         LEFT JOIN products p ON b.id = p.branch_id 
                         WHERE b.status = 'Active'
                         GROUP BY b.id, b.branch_name 
                         ORDER BY b.id");
    $final = $stmt->fetchAll();
    foreach ($final as $row) {
        echo "  {$row['branch_name']} (ID {$row['id']}): {$row['count']} products\n";
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total = $stmt->fetch()['total'];
    echo "\n✅ Total products: $total\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}


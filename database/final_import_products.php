<?php
/**
 * Final import of products from products.sql
 * Uses the row data directly from the SQL file
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Connected to " . PRIMARY_DB_NAME . "\n";

$sqlFile = __DIR__ . '/products.sql';
if (!file_exists($sqlFile)) {
    die("❌ SQL file not found: $sqlFile\n");
}

echo "Reading SQL file...\n";
$lines = file($sqlFile);

// Get INSERT line to extract column list
$insertLine = trim($lines[78]);
if (!preg_match('/INSERT INTO `products` \((.+?)\) VALUES/i', $insertLine, $matches)) {
    die("❌ Could not extract column list from INSERT statement\n");
}
$columnList = $matches[1];
echo "Column list extracted: " . substr($columnList, 0, 100) . "...\n\n";

// Truncate table
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$count = $stmt->fetchColumn();
echo "Current products count: $count\n";

if ($count > 0) {
    echo "⚠️  Truncating table...\n";
    $pdo->exec("TRUNCATE TABLE products");
    echo "✓ Table truncated\n\n";
}

// Import rows (lines 80-132, indices 79-131)
$inserted = 0;
$errors = 0;

$pdo->beginTransaction();

try {
    for ($i = 79; $i <= 131; $i++) {
        $line = trim($lines[$i]);
        if (empty($line) || !preg_match('/^\(/', $line)) {
            continue;
        }
        
        // Remove trailing comma or semicolon
        $rowData = rtrim($line, ',;');
        
        // Build INSERT statement
        $sql = "INSERT INTO `products` ($columnList) VALUES $rowData";
        
        try {
            $pdo->exec($sql);
            $inserted++;
            if ($inserted % 10 == 0) {
                echo "  Inserted $inserted rows...\n";
            }
        } catch (PDOException $e) {
            $errors++;
            $rowNum = $i - 78;
            echo "❌ Error on row $rowNum: " . $e->getMessage() . "\n";
            throw $e; // Re-throw to trigger rollback
        }
    }
    
    $pdo->commit();
    
    echo "\n✅ Import complete!\n";
    echo "  Inserted: $inserted\n";
    echo "  Errors: $errors\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ Import failed! Rolling back...\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$newCount = $stmt->fetchColumn();
echo "\nTotal products in database: $newCount\n";

// Show source distribution
echo "\nSource distribution:\n";
$stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
$sourceCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($sourceCounts as $row) {
    echo "  - " . ($row['source'] ?: 'NULL/Empty') . ": " . $row['count'] . "\n";
}


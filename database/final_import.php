<?php
/**
 * Final product import - reads backup and imports with source column
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$dbname = 'electrox_primary';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    echo "✅ Connected to $dbname\n\n";
    
    // Get column list (excluding source, we'll add it separately)
    $stmt = $pdo->query("SHOW COLUMNS FROM products");
    $allColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $sourceIndex = array_search('source', $allColumns);
    
    // Columns without source
    $columnsWithoutSource = array_filter($allColumns, function($col) {
        return $col !== 'source';
    });
    $columnList = '`' . implode('`, `', $columnsWithoutSource) . '`, `source`';
    
    echo "Table has " . count($allColumns) . " columns\n";
    echo "Source column index: " . ($sourceIndex !== false ? $sourceIndex : 'NOT FOUND') . "\n\n";
    
    // Read backup file
    $sqlFile = dirname(dirname(__FILE__)) . '/products_backup.sql';
    if (!file_exists($sqlFile)) {
        die("❌ Backup file not found: $sqlFile\n");
    }
    
    echo "Reading file...\n";
    $content = file_get_contents($sqlFile);
    // Remove BOM if present (UTF-8 BOM: EF BB BF)
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
        echo "Removed UTF-8 BOM\n";
    }
    // Also try removing any other BOM variants
    $content = ltrim($content, "\xFE\xFF\xFF\xFE\x00\x00\xFE\xFF");
    echo "File size: " . strlen($content) . " bytes\n";
    
    // Convert to UTF-8 if needed
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        echo "Converted encoding to UTF-8\n";
    }
    
    // Try to find INSERT - use multiple methods
    $insertLine = null;
    
    // Method 1: Line by line - handle both Unix and Windows line endings
    $lines = preg_split('/\r?\n/', $content);
    foreach ($lines as $i => $line) {
        $line = trim($line);
        if (stripos($line, 'INSERT INTO') !== false && 
            stripos($line, 'products') !== false && 
            stripos($line, 'VALUES') !== false) {
            $insertLine = $line;
            echo "✅ Found INSERT on line " . ($i + 1) . " (method: line-by-line)\n";
            echo "Line preview: " . substr($line, 0, 150) . "...\n";
            break;
        }
    }
    
    // Method 2: Regex on full content
    if (!$insertLine && preg_match('/INSERT INTO\s+`?products`?\s+VALUES\s+[^;]+/i', $content, $matches)) {
        $insertLine = $matches[0];
        echo "✅ Found INSERT using regex\n";
    }
    
    if (!$insertLine) {
        die("❌ INSERT not found. File contains: " . substr($content, 0, 200) . "...\n");
    }
    
    // Remove comment
    $insertLine = preg_replace('/\/\*!40000.*/', '', $insertLine);
    $insertLine = trim($insertLine);
    
    // Extract VALUES part
    if (!preg_match('/VALUES\s*(.+)/', $insertLine, $m)) {
        die("❌ Could not extract VALUES\n");
    }
    
    $valuesStr = trim($m[1]);
    $valuesStr = rtrim($valuesStr, ');');
    $valuesStr = rtrim($valuesStr, ')');
    
    // Split rows
    $rows = preg_split('/\)\s*,\s*\(/', $valuesStr);
    $rows[0] = ltrim($rows[0], '(');
    $rows[count($rows)-1] = rtrim($rows[count($rows)-1], ')');
    
    echo "Found " . count($rows) . " rows\n";
    echo "Starting import...\n\n";
    
    $pdo->beginTransaction();
    $inserted = 0;
    $skipped = 0;
    
    foreach ($rows as $idx => $row) {
        // Parse values (simple CSV parsing)
        $values = [];
        $current = '';
        $inQuotes = false;
        $quote = null;
        
        for ($i = 0; $i < strlen($row); $i++) {
            $c = $row[$i];
            $prev = $i > 0 ? $row[$i-1] : null;
            
            if (!$inQuotes && ($c === '"' || $c === "'")) {
                $inQuotes = true;
                $quote = $c;
                $current .= $c;
            } elseif ($inQuotes && $c === $quote && $prev !== '\\') {
                $inQuotes = false;
                $quote = null;
                $current .= $c;
            } elseif (!$inQuotes && $c === ',') {
                $values[] = trim($current);
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if ($current) $values[] = trim($current);
        
        // Add 'manual' for source column
        if (count($values) < count($columnsWithoutSource)) {
            // Pad with NULL
            while (count($values) < count($columnsWithoutSource)) {
                $values[] = 'NULL';
            }
        }
        $values[] = "'manual'"; // Add source
        
        $sql = "INSERT INTO `products` ($columnList) VALUES (" . implode(',', $values) . ")";
        
        try {
            $pdo->exec($sql);
            $inserted++;
            if ($inserted % 10 == 0) echo "Inserted $inserted...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $skipped++;
            }
        }
    }
    
    $pdo->exec("UPDATE products SET source = 'manual' WHERE source IS NULL OR source = ''");
    $pdo->commit();
    
    echo "\n✅ Done! Inserted: $inserted, Skipped: $skipped\n";
    $stmt = $pdo->query("SELECT COUNT(*) as c FROM products");
    echo "Total products: " . $stmt->fetch()['c'] . "\n";
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}


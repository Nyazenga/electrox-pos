<?php
/**
 * Generate a clean INSERT file from products.sql
 */

$sqlFile = __DIR__ . '/products.sql';
$outputFile = __DIR__ . '/products_insert_clean.sql';

$lines = file($sqlFile);

// Get INSERT line
$insertLine = trim($lines[78]);
preg_match_all('/`([^`]+)`/', $insertLine, $colMatches);
$columns = array_slice($colMatches[1], 1);
$columnList = '`' . implode('`, `', $columns) . '`';

// Build clean INSERT statement
$output = "INSERT INTO `products` ($columnList) VALUES\n";

// Add rows
for ($i = 79; $i <= 131; $i++) {
    $line = trim($lines[$i]);
    if (empty($line) || !preg_match('/^\(/', $line)) {
        continue;
    }
    
    // Remove trailing comma or semicolon
    $line = rtrim($line, ',;');
    
    // Add comma if not last row
    if ($i < 131) {
        $line .= ',';
    } else {
        $line .= ';';
    }
    
    $output .= $line . "\n";
}

file_put_contents($outputFile, $output);
echo "✅ Generated clean INSERT file: $outputFile\n";
echo "Size: " . filesize($outputFile) . " bytes\n";


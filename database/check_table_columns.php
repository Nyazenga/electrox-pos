<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();
$cols = $db->getRows("SHOW COLUMNS FROM products");

echo "Table has " . count($cols) . " columns:\n";
foreach ($cols as $col) {
    echo "  - " . $col['Field'] . "\n";
}

// Read INSERT statement
$sqlFile = __DIR__ . '/products.sql';
$lines = file($sqlFile);
$insertLine = trim($lines[78]);
preg_match_all('/`([^`]+)`/', $insertLine, $colMatches);
$insertCols = array_slice($colMatches[1], 1); // Skip 'products' table name

echo "\nINSERT has " . count($insertCols) . " columns:\n";
foreach ($insertCols as $col) {
    echo "  - " . $col . "\n";
}

// Find missing columns
$tableColNames = array_column($cols, 'Field');
$missingInInsert = array_diff($tableColNames, $insertCols);
$extraInInsert = array_diff($insertCols, $tableColNames);

if (!empty($missingInInsert)) {
    echo "\n❌ Missing in INSERT: " . implode(', ', $missingInInsert) . "\n";
}
if (!empty($extraInInsert)) {
    echo "\n❌ Extra in INSERT: " . implode(', ', $extraInInsert) . "\n";
}
if (empty($missingInInsert) && empty($extraInInsert)) {
    echo "\n✅ Column lists match!\n";
}


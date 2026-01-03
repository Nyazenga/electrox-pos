<?php
$file = __DIR__ . '/products.sql';
$lines = file($file);

// Get INSERT line
$insertLine = trim($lines[78]);
preg_match_all('/`([^`]+)`/', $insertLine, $colMatches);
$columns = array_slice($colMatches[1], 1);
$colCount = count($columns);

echo "INSERT has $colCount columns\n";

// Get first row
$firstRow = trim($lines[79]);
echo "First row: " . substr($firstRow, 0, 200) . "...\n\n";

// Count values (careful with commas in strings)
$inQuotes = false;
$escapeNext = false;
$commaCount = 0;
for ($i = 0; $i < strlen($firstRow); $i++) {
    $char = $firstRow[$i];
    if ($escapeNext) {
        $escapeNext = false;
        continue;
    }
    if ($char === '\\') {
        $escapeNext = true;
        continue;
    }
    if ($char === "'" && !$escapeNext) {
        $inQuotes = !$inQuotes;
    } elseif ($char === ',' && !$inQuotes) {
        $commaCount++;
    }
}
$valueCount = $commaCount + 1;

echo "First row has $valueCount values\n";
echo "Match: " . ($colCount === $valueCount ? "YES" : "NO") . "\n";

if ($colCount !== $valueCount) {
    echo "\nMISMATCH! Expected $colCount, got $valueCount\n";
    exit(1);
}


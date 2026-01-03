<?php
$file = __DIR__ . '/products.sql';
$lines = file($file);

// Get INSERT line (line 79, index 78)
$insertLine = trim($lines[78]);
preg_match_all('/`([^`]+)`/', $insertLine, $colMatches);
$columnCount = count($colMatches[1]);

echo "INSERT statement columns: $columnCount\n";
echo "Columns: " . implode(', ', $colMatches[1]) . "\n\n";

// Get first data row (line 80, index 79)
$firstRow = trim($lines[79]);
// Count commas (but be careful with commas inside strings)
// Simple approach: count commas outside quotes
$inQuotes = false;
$commaCount = 0;
for ($i = 0; $i < strlen($firstRow); $i++) {
    $char = $firstRow[$i];
    if ($char === "'" && ($i === 0 || $firstRow[$i-1] !== '\\')) {
        $inQuotes = !$inQuotes;
    } elseif ($char === ',' && !$inQuotes) {
        $commaCount++;
    }
}
$valueCount = $commaCount + 1; // Values = commas + 1

echo "First row values: $valueCount\n";
echo "Match: " . ($columnCount === $valueCount ? "YES" : "NO") . "\n";

if ($columnCount !== $valueCount) {
    echo "\nMISMATCH! Column count: $columnCount, Value count: $valueCount\n";
    exit(1);
}


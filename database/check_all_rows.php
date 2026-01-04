<?php
$file = __DIR__ . '/products.sql';
$lines = file($file);

// Get INSERT line to count columns
$insertLine = trim($lines[78]);
preg_match_all('/`([^`]+)`/', $insertLine, $colMatches);
$expectedCols = $colMatches[1];
// Remove 'products' from the count (it's the table name, not a column)
$expectedCount = count($expectedCols) - 1; // Subtract 1 for 'products'

echo "Expected columns: $expectedCount\n\n";

// Check rows 80-132 (indices 79-131)
$errors = [];
for ($i = 79; $i <= 131; $i++) {
    $line = trim($lines[$i]);
    if (empty($line) || !preg_match('/^\(/', $line)) {
        continue;
    }
    
    // Count commas (but be careful with commas inside strings)
    $inQuotes = false;
    $escapeNext = false;
    $commaCount = 0;
    for ($j = 0; $j < strlen($line); $j++) {
        $char = $line[$j];
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
    $valueCount = $commaCount + 1; // Values = commas + 1
    
    if ($valueCount !== $expectedCount) {
        $rowNum = $i - 78;
        $errors[] = "Row $rowNum: Expected $expectedCount values, found $valueCount";
        echo "Row $rowNum: Expected $expectedCount values, found $valueCount\n";
        echo "  " . substr($line, 0, 150) . "...\n\n";
    }
}

if (empty($errors)) {
    echo "✅ All rows have correct value count!\n";
} else {
    echo "\n❌ Found " . count($errors) . " rows with mismatched values\n";
    exit(1);
}


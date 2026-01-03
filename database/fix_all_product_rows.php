<?php
/**
 * Fix all product rows to have correct column order
 * source should be after created_by
 */

$file = __DIR__ . '/products.sql';
$lines = file($file);

// Get INSERT line to count columns
$insertLine = trim($lines[78]);
preg_match_all('/`([^`]+)`/', $insertLine, $colMatches);
$expectedCols = $colMatches[1];
$expectedCount = count($expectedCols);

echo "Expected columns: $expectedCount\n";
echo "Columns: " . implode(', ', array_slice($expectedCols, 35, 8)) . "\n\n";

// Fix rows 80-132 (indices 79-131)
$fixed = 0;
for ($i = 79; $i <= 131; $i++) {
    $line = $lines[$i];
    
    // Pattern: ...created_at, updated_at, created_by, [source should be here], updated_by, expiry_date, weight, unit_of_measure, manufacturer, batch_number
    // Current wrong pattern: ..., NULL, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL),
    // Should be: ..., NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
    
    // Fix pattern: created_by (NULL or number), then 5 NULLs, then 'manual', then 5 NULLs
    // Should be: created_by (NULL or number), then 'manual', then updated_by (NULL or number), then 5 NULLs
    
    // Pattern 1: created_by is NULL, then 5 NULLs, then 'manual', then 5 NULLs
    if (preg_match("/(, NULL, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL\),?)$/", $line)) {
        $newLine = preg_replace(
            "/(, NULL, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL\),?)$/",
            ", NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL$1",
            $line
        );
        if ($newLine !== $line) {
            $lines[$i] = $newLine;
            $fixed++;
            echo "Fixed row " . ($i - 78) . "\n";
        }
    }
    // Pattern 2: created_by is a number, then NULL, NULL, NULL, NULL, 'manual', then 5 NULLs
    elseif (preg_match("/(, \d+, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL\),?)$/", $line)) {
        $newLine = preg_replace(
            "/(, )(\d+)(, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL\),?)$/",
            "$1$2, 'manual', NULL, NULL, NULL, NULL, NULL, NULL$3",
            $line
        );
        if ($newLine !== $line) {
            $lines[$i] = $newLine;
            $fixed++;
            echo "Fixed row " . ($i - 78) . " (created_by is number)\n";
        }
    }
    // Pattern 3: created_by is NULL, then number, then NULL, NULL, NULL, 'manual', then 5 NULLs
    elseif (preg_match("/(, NULL, \d+, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL\),?)$/", $line)) {
        $newLine = preg_replace(
            "/(, NULL, )(\d+)(, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL\),?)$/",
            "$1'manual', $2, NULL, NULL, NULL, NULL, NULL$3",
            $line
        );
        if ($newLine !== $line) {
            $lines[$i] = $newLine;
            $fixed++;
            echo "Fixed row " . ($i - 78) . " (updated_by is number)\n";
        }
    }
}

if ($fixed > 0) {
    file_put_contents($file, implode('', $lines));
    echo "\n✅ Fixed $fixed rows\n";
} else {
    echo "\n✓ No rows needed fixing\n";
}


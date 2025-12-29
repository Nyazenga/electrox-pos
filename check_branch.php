<?php
require_once 'config.php';
require_once 'includes/db.php';

$db = Database::getPrimaryInstance();

// Get all branches
$branches = $db->getRows("SELECT id, branch_name, branch_code FROM branches ORDER BY branch_name");

echo "All branches in database:\n";
foreach ($branches as $b) {
    echo "ID: {$b['id']}, Name: '{$b['branch_name']}', Code: '" . ($b['branch_code'] ?? 'NULL') . "'\n";
    echo "  Length: " . strlen($b['branch_name']) . " chars\n";
    echo "  Hex: " . bin2hex($b['branch_name']) . "\n\n";
}

// Try exact match
echo "\nTrying exact match for 'RIDGEWAY':\n";
$exact = $db->getRow("SELECT id, branch_name FROM branches WHERE branch_name = :name", [':name' => 'RIDGEWAY']);
if ($exact) {
    echo "Found: {$exact['branch_name']} (ID: {$exact['id']})\n";
} else {
    echo "Not found with exact match\n";
}

// Try case-insensitive
echo "\nTrying case-insensitive match:\n";
$ci = $db->getRow("SELECT id, branch_name FROM branches WHERE UPPER(TRIM(branch_name)) = UPPER(TRIM(:name))", [':name' => 'RIDGEWAY']);
if ($ci) {
    echo "Found: {$ci['branch_name']} (ID: {$ci['id']})\n";
} else {
    echo "Not found with case-insensitive match\n";
}


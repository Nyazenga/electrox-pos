<?php
/**
 * Verify database migrations were applied correctly
 */
define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

$dbHost = DB_HOST;
$dbUser = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') ? 'root' : DB_USER;
$dbPass = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') ? '' : DB_PASS;

echo "🔍 MIGRATION VERIFICATION\n";
echo str_repeat("=", 60) . "\n\n";

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // 1. Check category_characteristics table
    $tables = $pdo->query("SHOW TABLES LIKE 'category%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "📋 Category tables:\n";
    foreach ($tables as $t) echo "   - $t\n";

    // 2. Check condition column type
    $col = $pdo->query("SHOW COLUMNS FROM product_specific_list WHERE Field = 'condition'")->fetch();
    echo "\n📋 Condition column type: " . ($col ? $col['Type'] : 'NOT FOUND') . "\n";

    // 3. Check is_specific column on product_categories
    $col2 = $pdo->query("SHOW COLUMNS FROM product_categories WHERE Field = 'is_specific'")->fetch();
    echo "📋 is_specific column: " . ($col2 ? $col2['Type'] : 'NOT FOUND') . "\n";

    // 4. Counts
    $charCount = $pdo->query("SELECT COUNT(*) FROM category_characteristics")->fetchColumn();
    echo "\n📊 category_characteristics: $charCount records\n";

    $assignCount = $pdo->query("SELECT COUNT(*) FROM category_characteristic_assignments")->fetchColumn();
    echo "📊 category_characteristic_assignments: $assignCount records\n";

    // 5. List characteristics
    echo "\n📋 Characteristics:\n";
    $chars = $pdo->query("SELECT name, label, field_type, is_system FROM category_characteristics ORDER BY sort_order")->fetchAll();
    foreach ($chars as $c) {
        $sys = $c['is_system'] ? ' [SYSTEM]' : '';
        echo "   - {$c['name']}: {$c['label']} ({$c['field_type']})$sys\n";
    }

    // 6. List categories with is_specific
    echo "\n📋 Categories (is_specific):\n";
    $cats = $pdo->query("SELECT name, is_specific FROM product_categories ORDER BY name")->fetchAll();
    foreach ($cats as $cat) {
        $flag = $cat['is_specific'] ? '✅ SPECIFIC' : '  generic';
        echo "   - {$cat['name']}: $flag\n";
    }

    echo "\n✅ VERIFICATION COMPLETE\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

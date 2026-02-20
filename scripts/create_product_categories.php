<?php
/**
 * Create Product Categories
 */

define('APP_PATH', dirname(__DIR__));

// Local database credentials
$localDbHost = 'localhost';
$localDbUser = 'root';
$localDbPass = '';
$localDbName = 'electrox_primary';

echo "📦 CREATING PRODUCT CATEGORIES\n";
echo str_repeat("=", 60) . "\n\n";

try {
    // Connect to local database
    echo "💾 Connecting to database...\n";
    $pdo = new PDO(
        "mysql:host=$localDbHost;dbname=$localDbName;charset=utf8mb4",
        $localDbUser,
        $localDbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "✅ Connected to database\n\n";
    
    // Clear existing categories
    echo "🗑️  Clearing existing categories...\n";
    $pdo->exec("DELETE FROM product_categories");
    $pdo->exec("ALTER TABLE product_categories AUTO_INCREMENT = 1");
    echo "✅ Cleared existing categories\n\n";
    
    // Product categories to create
    $categories = [
        ['name' => 'Wearables', 'description' => 'Smartwatches, fitness trackers', 'tax_id' => null],
        ['name' => 'Tablets', 'description' => 'Tablet devices', 'tax_id' => null],
        ['name' => 'Smartphones', 'description' => 'Mobile phones and smartphones', 'tax_id' => null],
        ['name' => 'Networking', 'description' => 'Network equipment', 'tax_id' => null],
        ['name' => 'Laptops', 'description' => 'Laptop computers', 'tax_id' => null],
        ['name' => 'General', 'description' => 'General products including groceries, consumables, and miscellaneous items', 'tax_id' => null],
        ['name' => 'Gaming', 'description' => 'Gaming devices and accessories', 'tax_id' => null],
        ['name' => 'Charging Adapters', 'description' => 'Chargers and adapters', 'tax_id' => null],
        ['name' => 'Audio Devices', 'description' => 'Headphones, speakers, etc.', 'tax_id' => null],
        ['name' => 'Accessories', 'description' => 'General accessories', 'tax_id' => null],
    ];
    
    // Insert categories
    echo "📤 Creating product categories...\n";
    $inserted = 0;
    
    foreach ($categories as $index => $category) {
        try {
            // Check which columns exist
            $columns = $pdo->query("SHOW COLUMNS FROM product_categories")->fetchAll(PDO::FETCH_COLUMN);
            $hasStatus = in_array('status', $columns);
            $hasCreatedAt = in_array('created_at', $columns);
            $hasUpdatedAt = in_array('updated_at', $columns);
            
            // Build SQL based on available columns
            $fields = ['name', 'description'];
            $values = [':name', ':description'];
            $params = [
                ':name' => $category['name'],
                ':description' => $category['description']
            ];
            
            if (in_array('tax_id', $columns)) {
                $fields[] = 'tax_id';
                $values[] = ':tax_id';
                $params[':tax_id'] = $category['tax_id'];
            }
            
            if ($hasStatus) {
                $fields[] = 'status';
                $values[] = ':status';
                $params[':status'] = 'Active';
            }
            
            if ($hasCreatedAt) {
                $fields[] = 'created_at';
                $values[] = ':created_at';
                $createdAt = '2025-12-12 00:00:00';
                if ($category['name'] === 'General') {
                    $createdAt = '2025-12-15 00:00:00';
                }
                $params[':created_at'] = $createdAt;
            }
            
            if ($hasUpdatedAt) {
                $fields[] = 'updated_at';
                $values[] = ':updated_at';
                $params[':updated_at'] = date('Y-m-d H:i:s');
            }
            
            $sql = "INSERT INTO product_categories (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $values) . ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $inserted++;
            echo "  ✅ Created: {$category['name']}\n";
            
        } catch (PDOException $e) {
            echo "  ❌ Error creating {$category['name']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✅ COMPLETE\n";
    echo "   Created: $inserted categories\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

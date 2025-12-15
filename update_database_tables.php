<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once APP_PATH . '/includes/db.php';

echo "Starting database update...\n\n";

try {
    $db = Database::getInstance();
    echo "Database connection successful\n";
    
    $pdo = $db->getPdo();
    
    // Update stock_movements enum to include 'Trade-In'
    echo "\n1. Updating stock_movements table...\n";
    try {
        $pdo->exec("ALTER TABLE `stock_movements` MODIFY COLUMN `movement_type` enum('Purchase','Sale','Transfer','Adjustment','Damage','Return','Trade-In') DEFAULT 'Adjustment'");
        echo "   ✓ Stock movements enum updated successfully\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') === false) {
            echo "   ⚠ Could not update enum: " . $e->getMessage() . "\n";
            echo "   (This is OK if it's already updated)\n";
        } else {
            echo "   ✓ Stock movements enum already updated\n";
        }
    }
    
    // Add new_product_id column to trade_ins if it doesn't exist
    echo "\n2. Checking trade_ins table...\n";
    try {
        $columns = $db->getRows("SHOW COLUMNS FROM trade_ins WHERE Field = 'new_product_id'");
        if (empty($columns)) {
            echo "   Adding new_product_id column...\n";
            $pdo->exec("ALTER TABLE `trade_ins` ADD COLUMN `new_product_id` int(11) DEFAULT NULL AFTER `final_valuation`");
            echo "   ✓ Added new_product_id column\n";
            
            // Add index
            try {
                echo "   Adding index...\n";
                $pdo->exec("ALTER TABLE `trade_ins` ADD KEY `idx_new_product_id` (`new_product_id`)");
                echo "   ✓ Added index\n";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate key') === false && strpos($e->getMessage(), 'already exists') === false) {
                    echo "   ⚠ Index error: " . $e->getMessage() . "\n";
                } else {
                    echo "   ✓ Index already exists\n";
                }
            }
        } else {
            echo "   ✓ new_product_id column already exists\n";
        }
    } catch (Exception $e) {
        echo "   ⚠ Error: " . $e->getMessage() . "\n";
    }
    
    // Add is_trade_in column to products if it doesn't exist
    echo "\n3. Checking products table for is_trade_in column...\n";
    try {
        $columns = $db->getRows("SHOW COLUMNS FROM products WHERE Field = 'is_trade_in'");
        if (empty($columns)) {
            echo "   Adding is_trade_in column...\n";
            $pdo = $db->getPdo();
            $pdo->exec("ALTER TABLE `products` ADD COLUMN `is_trade_in` tinyint(1) DEFAULT 0");
            echo "   ✓ Added is_trade_in column\n";
            
            // Add index
            try {
                echo "   Adding index...\n";
                $pdo->exec("ALTER TABLE `products` ADD KEY `idx_is_trade_in` (`is_trade_in`)");
                echo "   ✓ Added index\n";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate key') === false && strpos($e->getMessage(), 'already exists') === false) {
                    echo "   ⚠ Index error: " . $e->getMessage() . "\n";
                } else {
                    echo "   ✓ Index already exists\n";
                }
            }
        } else {
            echo "   ✓ is_trade_in column already exists\n";
        }
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "   ✓ Column already exists\n";
        } else {
            echo "   ⚠ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // Update existing trade-in products
    echo "\n4. Updating existing trade-in products...\n";
    try {
        // Find products from stock_movements with Trade-In type
        $tradeInProducts = $db->getRows("
            SELECT DISTINCT p.id 
            FROM products p
            INNER JOIN stock_movements sm ON p.id = sm.product_id
            WHERE sm.movement_type = 'Trade-In' 
            AND (p.is_trade_in = 0 OR p.is_trade_in IS NULL)
        ");
        
        if ($tradeInProducts && count($tradeInProducts) > 0) {
            echo "   Found " . count($tradeInProducts) . " products from stock movements\n";
            foreach ($tradeInProducts as $product) {
                $db->update('products', ['is_trade_in' => 1], ['id' => $product['id']]);
            }
            echo "   ✓ Updated " . count($tradeInProducts) . " products\n";
        } else {
            echo "   No products found from stock movements\n";
        }
        
        // Find products by description pattern
        $descProducts = $db->getRows("
            SELECT id 
            FROM products 
            WHERE (description LIKE 'Trade-in device:%' OR description LIKE 'Trade-In Device%' OR description LIKE 'Trade-In:%')
            AND (is_trade_in = 0 OR is_trade_in IS NULL)
        ");
        
        if ($descProducts && count($descProducts) > 0) {
            echo "   Found " . count($descProducts) . " products by description\n";
            foreach ($descProducts as $product) {
                $db->update('products', ['is_trade_in' => 1], ['id' => $product['id']]);
            }
            echo "   ✓ Updated " . count($descProducts) . " products\n";
        } else {
            echo "   No products found by description\n";
        }
        
        // Find products from sale_items with Trade-In prefix
        $saleProducts = $db->getRows("
            SELECT DISTINCT si.product_id as id
            FROM sale_items si
            WHERE si.product_name LIKE 'Trade-In:%'
            AND si.product_id IS NOT NULL
            AND EXISTS (SELECT 1 FROM products p WHERE p.id = si.product_id AND (p.is_trade_in = 0 OR p.is_trade_in IS NULL))
        ");
        
        if ($saleProducts && count($saleProducts) > 0) {
            echo "   Found " . count($saleProducts) . " products from sale items\n";
            foreach ($saleProducts as $product) {
                $db->update('products', ['is_trade_in' => 1], ['id' => $product['id']]);
            }
            echo "   ✓ Updated " . count($saleProducts) . " products\n";
        } else {
            echo "   No products found from sale items\n";
        }
        
        // Summary
        $totalTradeIn = $db->getRow("SELECT COUNT(*) as total FROM products WHERE is_trade_in = 1");
        $activeTradeIn = $db->getRow("SELECT COUNT(*) as total FROM products WHERE is_trade_in = 1 AND status = 'Active' AND quantity_in_stock > 0");
        echo "   Total trade-in products: " . ($totalTradeIn['total'] ?? 0) . "\n";
        echo "   Active trade-in products in stock: " . ($activeTradeIn['total'] ?? 0) . "\n";
        
    } catch (Exception $e) {
        echo "   ⚠ Error updating products: " . $e->getMessage() . "\n";
    }
    
    echo "\n✓ Database tables update completed!\n";
    
} catch (Exception $e) {
    echo "\n✗ Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nDone!\n";

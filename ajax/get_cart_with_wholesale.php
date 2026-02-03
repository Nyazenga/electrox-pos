<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$useWholesale = isset($input['use_wholesale']) && $input['use_wholesale'] === true;

try {
    $db = Database::getInstance();
    $cart = $_SESSION['pos_cart'] ?? [];
    
    if (empty($cart)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty']);
        exit;
    }
    
    // Update cart with wholesale prices if requested
    if ($useWholesale) {
        foreach ($cart as $index => &$item) {
            // Check if item has specific list entries with their own prices
            if (!empty($item['specific_list_entries']) && is_array($item['specific_list_entries']) && count($item['specific_list_entries']) > 0) {
                // Use specific item wholesale price if available
                $specificEntry = $item['specific_list_entries'][0];
                if (!empty($specificEntry['id'])) {
                    $specificItem = $db->getRow(
                        "SELECT wholesale_price, selling_price FROM product_specific_list WHERE id = :id",
                        [':id' => $specificEntry['id']]
                    );
                    if ($specificItem && !empty($specificItem['wholesale_price']) && floatval($specificItem['wholesale_price']) > 0) {
                        $item['price'] = floatval($specificItem['wholesale_price']);
                        $item['is_wholesale'] = true;
                        continue;
                    } elseif ($specificItem && !empty($specificItem['selling_price']) && floatval($specificItem['selling_price']) > 0) {
                        // No wholesale price for specific item, keep selling price
                        $item['price'] = floatval($specificItem['selling_price']);
                        unset($item['is_wholesale']);
                        continue;
                    }
                }
            }
            
            // Fallback to product-level wholesale price
            $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $item['id']]);
            if ($product && !empty($product['wholesale_price']) && floatval($product['wholesale_price']) > 0) {
                $item['price'] = floatval($product['wholesale_price']);
                $item['is_wholesale'] = true;
            }
        }
        unset($item);
    } else {
        // Reset to retail prices
        foreach ($cart as $index => &$item) {
            // Check if item has specific list entries with their own prices
            if (!empty($item['specific_list_entries']) && is_array($item['specific_list_entries']) && count($item['specific_list_entries']) > 0) {
                // Use specific item selling price
                $specificEntry = $item['specific_list_entries'][0];
                if (!empty($specificEntry['id'])) {
                    $specificItem = $db->getRow(
                        "SELECT selling_price FROM product_specific_list WHERE id = :id",
                        [':id' => $specificEntry['id']]
                    );
                    if ($specificItem && !empty($specificItem['selling_price']) && floatval($specificItem['selling_price']) > 0) {
                        $item['price'] = floatval($specificItem['selling_price']);
                        unset($item['is_wholesale']);
                        continue;
                    }
                }
            }
            
            // Fallback to product-level selling price
            $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $item['id']]);
            if ($product) {
                $item['price'] = floatval($product['selling_price']);
                unset($item['is_wholesale']);
            }
        }
        unset($item);
    }
    
    $_SESSION['pos_cart'] = $cart;
    $_SESSION['is_wholesale_sale'] = $useWholesale;
    
    echo json_encode(['success' => true, 'cart' => $cart]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

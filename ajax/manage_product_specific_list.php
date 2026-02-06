<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $db = Database::getInstance();
    $primaryDb = Database::getPrimaryInstance();
    
    switch ($action) {
        case 'add':
            // Add one or more product_specific_list entries
            $productId = intval($_POST['product_id'] ?? 0);
            $branchId = intval($_POST['branch_id'] ?? $_SESSION['branch_id'] ?? 0);
            $entries = json_decode($_POST['entries'] ?? '[]', true);
            
            if ($productId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
                exit;
            }
            
            if (empty($entries) || !is_array($entries)) {
                echo json_encode(['success' => false, 'message' => 'No entries provided']);
                exit;
            }
            
            // Verify product requires specific list
            $product = $db->getRow("SELECT p.*, pc.name as category_name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.id = :id", [':id' => $productId]);
            if (!$product) {
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                exit;
            }
            
            if (!productRequiresSpecificList($product, $db)) {
                echo json_encode(['success' => false, 'message' => 'This product does not require specific list entries']);
                exit;
            }
            
            // Determine if IMEI should be used based on category
            $categoryName = strtolower($product['category_name'] ?? '');
            $useIMEI = (strpos($categoryName, 'smartphone') !== false || 
                       strpos($categoryName, 'phone') !== false || 
                       strpos($categoryName, 'tablet') !== false);
            
            $db->beginTransaction();
            $added = 0;
            $errors = [];
            
            foreach ($entries as $entryIndex => $entry) {
                $entryNum = $entryIndex + 1;
                
                // Validate required fields
                $serialNumber = !empty($entry['serial_number']) ? trim($entry['serial_number']) : '';
                $imei = !empty($entry['imei']) ? trim($entry['imei']) : '';
                
                if (empty($serialNumber) && empty($imei)) {
                    $errors[] = "Entry $entryNum: Must have either serial number or IMEI";
                    continue;
                }
                
                // Validate serial number length
                if (!empty($serialNumber)) {
                    if (strlen($serialNumber) > 100) {
                        $errors[] = "Entry $entryNum: Serial number cannot exceed 100 characters";
                        continue;
                    }
                    
                    // Check for duplicate serial numbers
                    $existing = $db->getRow(
                        "SELECT id FROM product_specific_list WHERE serial_number = :serial AND branch_id = :branch_id AND product_id != :product_id",
                        [':serial' => $serialNumber, ':branch_id' => $branchId, ':product_id' => $productId]
                    );
                    if ($existing) {
                        $errors[] = "Entry $entryNum: Serial number '{$serialNumber}' already exists";
                        continue;
                    }
                }
                
                // Validate IMEI format and check for duplicates
                if (!empty($imei)) {
                    // IMEI must be exactly 15 digits
                    if (!preg_match('/^\d{15}$/', $imei)) {
                        $errors[] = "Entry $entryNum: IMEI must be exactly 15 digits";
                        continue;
                    }
                    
                    // Check for duplicate IMEI
                    $existing = $db->getRow(
                        "SELECT id FROM product_specific_list WHERE imei = :imei AND branch_id = :branch_id AND product_id != :product_id",
                        [':imei' => $imei, ':branch_id' => $branchId, ':product_id' => $productId]
                    );
                    if ($existing) {
                        $errors[] = "Entry $entryNum: IMEI '{$imei}' already exists";
                        continue;
                    }
                }
                
                // Validate battery health (0-100)
                if (!empty($entry['battery_health']) && $entry['battery_health'] !== '') {
                    $batteryHealth = intval($entry['battery_health']);
                    if ($batteryHealth < 0 || $batteryHealth > 100) {
                        $errors[] = "Entry $entryNum: Battery health must be between 0 and 100%";
                        continue;
                    }
                }
                
                // Validate warranty months (0-999)
                if (!empty($entry['warranty_months']) && $entry['warranty_months'] !== '') {
                    $warrantyMonths = intval($entry['warranty_months']);
                    if ($warrantyMonths < 0 || $warrantyMonths > 999) {
                        $errors[] = "Entry $entryNum: Warranty months must be between 0 and 999";
                        continue;
                    }
                }
                
                // Validate field lengths
                if (!empty($entry['color']) && strlen($entry['color']) > 50) {
                    $errors[] = "Entry $entryNum: Color cannot exceed 50 characters";
                    continue;
                }
                if (!empty($entry['storage']) && strlen($entry['storage']) > 50) {
                    $errors[] = "Entry $entryNum: Storage cannot exceed 50 characters";
                    continue;
                }
                if (!empty($entry['manufacturer']) && strlen($entry['manufacturer']) > 100) {
                    $errors[] = "Entry $entryNum: Manufacturer cannot exceed 100 characters";
                    continue;
                }
                if (!empty($entry['sim_configuration']) && strlen($entry['sim_configuration']) > 50) {
                    $errors[] = "Entry $entryNum: SIM configuration cannot exceed 50 characters";
                    continue;
                }
                
                // Sanitize and prepare data
                $batteryHealth = null;
                if (!empty($entry['battery_health']) && $entry['battery_health'] !== '') {
                    $batteryHealth = intval($entry['battery_health']);
                    if ($batteryHealth < 0) $batteryHealth = 0;
                    if ($batteryHealth > 100) $batteryHealth = 100;
                }
                
                $warrantyMonths = 0;
                if (!empty($entry['warranty_months']) && $entry['warranty_months'] !== '') {
                    $warrantyMonths = intval($entry['warranty_months']);
                    if ($warrantyMonths < 0) $warrantyMonths = 0;
                    if ($warrantyMonths > 999) $warrantyMonths = 999;
                }
                
                // Handle prices (tax-inclusive)
                $costPrice = !empty($entry['cost_price']) ? floatval($entry['cost_price']) : null;
                $sellingPrice = !empty($entry['selling_price']) ? floatval($entry['selling_price']) : null;
                $wholesalePrice = !empty($entry['wholesale_price']) ? floatval($entry['wholesale_price']) : null;
                
                if ($sellingPrice === null || $sellingPrice <= 0) {
                    $errors[] = "Entry $entryNum: Selling Price is required and must be greater than 0";
                    continue;
                }
                
                $data = [
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'color' => !empty($entry['color']) ? substr(sanitizeInput(trim($entry['color'])), 0, 50) : null,
                    'storage' => !empty($entry['storage']) ? substr(sanitizeInput(trim($entry['storage'])), 0, 50) : null,
                    'sim_configuration' => !empty($entry['sim_configuration']) ? substr(sanitizeInput(trim($entry['sim_configuration'])), 0, 50) : null,
                    'serial_number' => !empty($serialNumber) ? substr(sanitizeInput($serialNumber), 0, 100) : null,
                    'imei' => ($useIMEI && !empty($imei)) ? sanitizeInput($imei) : null, // Null out IMEI for non-smartphone/tablet
                    'battery_health' => $batteryHealth,
                    'manufacturer' => !empty($entry['manufacturer']) ? substr(sanitizeInput(trim($entry['manufacturer'])), 0, 100) : null,
                    'warranty_months' => $warrantyMonths,
                    'warranty_terms' => !empty($entry['warranty_terms']) ? sanitizeInput(trim($entry['warranty_terms'])) : null,
                    'condition' => !empty($entry['condition']) && in_array($entry['condition'], ['New', 'Refurbished', 'Used']) ? sanitizeInput($entry['condition']) : 'New',
                    'trade_in_eligible' => !empty($entry['trade_in_eligible']) ? 1 : 0,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'wholesale_price' => $wholesalePrice,
                    'status' => 'available',
                    'created_by' => $_SESSION['user_id'] ?? null
                ];
                
                $id = $db->insert('product_specific_list', $data);
                if ($id) {
                    $added++;
                } else {
                    $dbError = $db->getLastError();
                    // Parse error message to provide specific feedback
                    $errorMessage = '';
                    
                    // Check for duplicate entry errors
                    if (strpos($dbError, 'Duplicate entry') !== false || 
                        strpos($dbError, '1062') !== false || 
                        strpos($dbError, '23000') !== false) {
                        
                        // Try to extract which field is duplicated
                        if (strpos($dbError, 'serial_number') !== false || strpos($dbError, 'unique_serial_number') !== false) {
                            $errorMessage = "Entry $entryNum: Serial number '{$serialNumber}' already exists in this branch";
                        } elseif (strpos($dbError, 'imei') !== false || strpos($dbError, 'unique_imei') !== false) {
                            $errorMessage = "Entry $entryNum: IMEI '{$imei}' already exists in this branch";
                        } elseif (!empty($serialNumber)) {
                            $errorMessage = "Entry $entryNum: Serial number '{$serialNumber}' already exists in this branch";
                        } elseif (!empty($imei)) {
                            $errorMessage = "Entry $entryNum: IMEI '{$imei}' already exists in this branch";
                        } else {
                            $errorMessage = "Entry $entryNum: Duplicate entry detected - this combination already exists";
                        }
                    } else {
                        // Other database errors
                        $errorMessage = "Entry $entryNum: Failed to add - " . ($dbError ?: 'Database error');
                    }
                    
                    if ($errorMessage) {
                        $errors[] = $errorMessage;
                    }
                }
            }
            
            if ($added > 0) {
                // Update product quantity to match count of available entries
                $count = getProductSpecificListCount($productId, $branchId, 'available', $db);
                $db->update('products', ['quantity_in_stock' => $count], ['id' => $productId]);
                
                $db->commitTransaction();
                echo json_encode([
                    'success' => true,
                    'message' => "Added $added entry/entries successfully",
                    'added' => $added,
                    'errors' => $errors,
                    'new_quantity' => $count
                ]);
            } else {
                $db->rollbackTransaction();
                $errorMessage = 'Failed to add entries';
                if (!empty($errors)) {
                    $errorMessage .= ': ' . implode('; ', $errors);
                }
                echo json_encode([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => $errors
                ]);
            }
            break;
            
        case 'get':
            // Get product_specific_list entries for a product
            $productId = intval($_GET['product_id'] ?? 0);
            $branchId = !empty($_GET['branch_id']) ? intval($_GET['branch_id']) : null;
            $status = !empty($_GET['status']) ? sanitizeInput($_GET['status']) : 'available';
            
            if ($productId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
                exit;
            }
            
            $entries = getAvailableProductSpecificList($productId, $branchId, null, $db);
            
            echo json_encode([
                'success' => true,
                'entries' => $entries,
                'count' => count($entries)
            ]);
            break;
            
        case 'update':
            // Update one or more product_specific_list entries
            $entries = json_decode($_POST['entries'] ?? '[]', true);
            
            if (empty($entries) || !is_array($entries)) {
                echo json_encode(['success' => false, 'message' => 'No entries provided']);
                exit;
            }
            
            $db->beginTransaction();
            $updated = 0;
            $errors = [];
            
            foreach ($entries as $entryIndex => $entry) {
                $id = intval($entry['id'] ?? 0);
                if ($id <= 0) {
                    $errors[] = "Entry " . ($entryIndex + 1) . ": Invalid ID";
                    continue;
                }
                
                // Verify entry exists and get product category
                $existing = $db->getRow("SELECT psl.*, p.category_id, pc.name as category_name 
                                        FROM product_specific_list psl 
                                        JOIN products p ON psl.product_id = p.id 
                                        LEFT JOIN product_categories pc ON p.category_id = pc.id 
                                        WHERE psl.id = :id", [':id' => $id]);
                if (!$existing) {
                    $errors[] = "Entry " . ($entryIndex + 1) . ": Entry not found";
                    continue;
                }
                
                // Determine if IMEI should be used based on category
                $categoryName = strtolower($existing['category_name'] ?? '');
                $useIMEI = (strpos($categoryName, 'smartphone') !== false || 
                           strpos($categoryName, 'phone') !== false || 
                           strpos($categoryName, 'tablet') !== false);
                $useSIMConfig = $useIMEI; // Only for phones/tablets
                $useBatteryHealth = $useIMEI; // Only for phones/tablets
                
                // Validate required fields
                $serialNumber = !empty($entry['serial_number']) ? trim($entry['serial_number']) : '';
                $imei = !empty($entry['imei']) ? trim($entry['imei']) : '';
                
                if (empty($serialNumber) && empty($imei)) {
                    $errors[] = "Entry " . ($entryIndex + 1) . ": Must have either serial number or IMEI";
                    continue;
                }
                
                // Validate IMEI format if provided
                if (!empty($imei)) {
                    if (!preg_match('/^\d{15}$/', $imei)) {
                        $errors[] = "Entry " . ($entryIndex + 1) . ": IMEI must be exactly 15 digits";
                        continue;
                    }
                    
                    // Check for duplicate IMEI (excluding current entry)
                    $duplicate = $db->getRow(
                        "SELECT id FROM product_specific_list WHERE imei = :imei AND branch_id = :branch_id AND id != :id",
                        [':imei' => $imei, ':branch_id' => $existing['branch_id'], ':id' => $id]
                    );
                    if ($duplicate) {
                        $errors[] = "Entry " . ($entryIndex + 1) . ": IMEI '{$imei}' already exists";
                        continue;
                    }
                }
                
                // Validate serial number length and duplicates
                if (!empty($serialNumber)) {
                    if (strlen($serialNumber) > 100) {
                        $errors[] = "Entry " . ($entryIndex + 1) . ": Serial number cannot exceed 100 characters";
                        continue;
                    }
                    
                    $duplicate = $db->getRow(
                        "SELECT id FROM product_specific_list WHERE serial_number = :serial AND branch_id = :branch_id AND id != :id",
                        [':serial' => $serialNumber, ':branch_id' => $existing['branch_id'], ':id' => $id]
                    );
                    if ($duplicate) {
                        $errors[] = "Entry " . ($entryIndex + 1) . ": Serial number '{$serialNumber}' already exists";
                        continue;
                    }
                }
                
                // Validate battery health
                $batteryHealth = null;
                if (!empty($entry['battery_health']) && $entry['battery_health'] !== '') {
                    $batteryHealth = intval($entry['battery_health']);
                    if ($batteryHealth < 0 || $batteryHealth > 100) {
                        $errors[] = "Entry " . ($entryIndex + 1) . ": Battery health must be between 0 and 100%";
                        continue;
                    }
                }
                
                // Validate warranty months
                $warrantyMonths = 0;
                if (!empty($entry['warranty_months']) && $entry['warranty_months'] !== '') {
                    $warrantyMonths = intval($entry['warranty_months']);
                    if ($warrantyMonths < 0 || $warrantyMonths > 999) {
                        $errors[] = "Entry " . ($entryIndex + 1) . ": Warranty months must be between 0 and 999";
                        continue;
                    }
                }
                
                // Handle prices (tax-inclusive)
                $costPrice = !empty($entry['cost_price']) ? floatval($entry['cost_price']) : null;
                $sellingPrice = !empty($entry['selling_price']) ? floatval($entry['selling_price']) : null;
                $wholesalePrice = !empty($entry['wholesale_price']) ? floatval($entry['wholesale_price']) : null;
                
                if ($sellingPrice === null || $sellingPrice <= 0) {
                    $errors[] = "Entry " . ($entryIndex + 1) . ": Selling Price is required and must be greater than 0";
                    continue;
                }
                
                // Prepare update data
                // For laptops/audio devices, explicitly null out SIM config and battery health
                $simConfigValue = null;
                if ($useSIMConfig && !empty($entry['sim_configuration'])) {
                    $simConfigValue = substr(sanitizeInput(trim($entry['sim_configuration'])), 0, 50);
                }
                
                $batteryHealthValue = null;
                if ($useBatteryHealth && $batteryHealth !== null) {
                    $batteryHealthValue = $batteryHealth;
                }
                
                $updateData = [
                    'color' => !empty($entry['color']) ? substr(sanitizeInput(trim($entry['color'])), 0, 50) : null,
                    'storage' => !empty($entry['storage']) ? substr(sanitizeInput(trim($entry['storage'])), 0, 50) : null,
                    'sim_configuration' => $simConfigValue, // Null for laptops/audio devices
                    'serial_number' => !empty($serialNumber) ? substr(sanitizeInput($serialNumber), 0, 100) : null,
                    'imei' => ($useIMEI && !empty($imei)) ? sanitizeInput($imei) : null, // Null out IMEI for non-smartphone/tablet
                    'battery_health' => $batteryHealthValue, // Null for laptops/audio devices
                    'manufacturer' => !empty($entry['manufacturer']) ? substr(sanitizeInput(trim($entry['manufacturer'])), 0, 100) : null,
                    'warranty_months' => $warrantyMonths,
                    'warranty_terms' => !empty($entry['warranty_terms']) ? sanitizeInput(trim($entry['warranty_terms'])) : null,
                    'condition' => !empty($entry['condition']) && in_array($entry['condition'], ['New', 'Refurbished', 'Used']) ? sanitizeInput($entry['condition']) : 'New',
                    'trade_in_eligible' => !empty($entry['trade_in_eligible']) ? 1 : 0,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'wholesale_price' => $wholesalePrice
                ];
                
                try {
                    $result = $db->update('product_specific_list', $updateData, ['id' => $id]);
                    if ($result) {
                        $updated++;
                    } else {
                        $dbError = $db->getLastError();
                        // Parse error message to provide specific feedback
                        $errorMessage = '';
                        
                        // Check for duplicate entry errors
                        if (strpos($dbError, 'Duplicate entry') !== false || 
                            strpos($dbError, '1062') !== false || 
                            strpos($dbError, '23000') !== false) {
                            
                            // Try to extract which field is duplicated
                            if (strpos($dbError, 'serial_number') !== false || strpos($dbError, 'unique_serial_number') !== false) {
                                $errorMessage = "Entry " . ($entryIndex + 1) . ": Serial number '{$serialNumber}' already exists in this branch";
                            } elseif (strpos($dbError, 'imei') !== false || strpos($dbError, 'unique_imei') !== false) {
                                $errorMessage = "Entry " . ($entryIndex + 1) . ": IMEI '{$imei}' already exists in this branch";
                            } elseif (!empty($serialNumber)) {
                                $errorMessage = "Entry " . ($entryIndex + 1) . ": Serial number '{$serialNumber}' already exists in this branch";
                            } elseif (!empty($imei)) {
                                $errorMessage = "Entry " . ($entryIndex + 1) . ": IMEI '{$imei}' already exists in this branch";
                            } else {
                                $errorMessage = "Entry " . ($entryIndex + 1) . ": Duplicate entry detected - this combination already exists";
                            }
                        } else {
                            // Other database errors - provide more detailed error message
                            $errorDetails = $dbError ?: 'Unknown database error';
                            // Log the actual error for debugging
                            error_log("Product specific list update error for entry ID {$id}: " . $errorDetails);
                            $errorMessage = "Entry " . ($entryIndex + 1) . ": Failed to update - " . $errorDetails;
                        }
                        
                        if ($errorMessage) {
                            $errors[] = $errorMessage;
                        }
                    }
                } catch (Exception $e) {
                    error_log("Exception updating product specific list entry ID {$id}: " . $e->getMessage());
                    $errors[] = "Entry " . ($entryIndex + 1) . ": Error updating - " . $e->getMessage();
                }
            }
            
            if ($updated > 0) {
                // Update product quantity
                if (!empty($entries)) {
                    $firstEntry = $db->getRow("SELECT product_id, branch_id FROM product_specific_list WHERE id = :id", [':id' => $entries[0]['id']]);
                    if ($firstEntry) {
                        $count = getProductSpecificListCount($firstEntry['product_id'], $firstEntry['branch_id'], 'available', $db);
                        $db->update('products', ['quantity_in_stock' => $count], ['id' => $firstEntry['product_id']]);
                    }
                }
                
                $db->commitTransaction();
                $message = "Updated $updated entry/entries successfully";
                if (!empty($errors)) {
                    $message .= ': ' . implode('; ', $errors);
                }
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'updated' => $updated,
                    'errors' => $errors
                ]);
            } else {
                $db->rollbackTransaction();
                $errorMessage = 'Failed to update entries';
                if (!empty($errors)) {
                    $errorMessage .= ': ' . implode('; ', $errors);
                }
                echo json_encode([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => $errors
                ]);
            }
            break;
            
        case 'update_status':
            // Update status of specific list entry (e.g., when sold)
            $id = intval($_POST['id'] ?? 0);
            $status = sanitizeInput($_POST['status'] ?? '');
            $saleItemId = !empty($_POST['sale_item_id']) ? intval($_POST['sale_item_id']) : null;
            $invoiceItemId = !empty($_POST['invoice_item_id']) ? intval($_POST['invoice_item_id']) : null;
            
            if ($id <= 0 || empty($status)) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            $updateData = ['status' => $status];
            if ($saleItemId !== null) {
                $updateData['sale_item_id'] = $saleItemId;
            }
            if ($invoiceItemId !== null) {
                $updateData['invoice_item_id'] = $invoiceItemId;
            }
            
            $result = $db->update('product_specific_list', $updateData, ['id' => $id]);
            
            if ($result) {
                // Update product quantity
                $entry = $db->getRow("SELECT product_id, branch_id FROM product_specific_list WHERE id = :id", [':id' => $id]);
                if ($entry) {
                    $count = getProductSpecificListCount($entry['product_id'], $entry['branch_id'], 'available', $db);
                    $db->update('products', ['quantity_in_stock' => $count], ['id' => $entry['product_id']]);
                }
                
                echo json_encode(['success' => true, 'message' => 'Status updated']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update status']);
            }
            break;
            
        case 'delete':
            // Delete a specific list entry
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            $entry = $db->getRow("SELECT product_id, branch_id, status FROM product_specific_list WHERE id = :id", [':id' => $id]);
            if (!$entry) {
                echo json_encode(['success' => false, 'message' => 'Entry not found']);
                exit;
            }
            
            if ($entry['status'] === 'sold') {
                echo json_encode(['success' => false, 'message' => 'Cannot delete sold items']);
                exit;
            }
            
            $result = $db->delete('product_specific_list', 'id = :id', [':id' => $id]);
            
            if ($result) {
                // Update product quantity
                $count = getProductSpecificListCount($entry['product_id'], $entry['branch_id'], 'available', $db);
                $db->update('products', ['quantity_in_stock' => $count], ['id' => $entry['product_id']]);
                
                echo json_encode(['success' => true, 'message' => 'Entry deleted']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete entry']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->getPdo()->inTransaction()) {
        $db->rollbackTransaction();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

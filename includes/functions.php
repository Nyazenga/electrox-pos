<?php
// Prevent direct access
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/session.php';

function checkTenantExists($tenant_name) {
    /**
     * Check if a tenant exists by tenant_slug (which matches database suffix)
     * Pattern: tenant_slug = "primary" means database = "electrox_primary"
     * The tenant_name parameter is treated as tenant_slug (lowercased)
     */
    try {
        $db = Database::getMainInstance();
        $tenantSlug = strtolower(trim($tenant_name));
        $dbName = 'electrox_' . $tenantSlug;
        
        // First check if database exists (tenant_slug = database suffix)
        $stmt = $db->getPdo()->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :dbname");
        $stmt->execute([':dbname' => $dbName]);
        if ($stmt->fetch()) {
            return true; // Database exists, tenant exists
        }
        
        // Check if tenant_slug exists in tenants table (tenant_slug is the key, not tenant_name)
        $tenant = $db->getRow(
            "SELECT * FROM tenants WHERE tenant_slug = :slug",
            [':slug' => $tenantSlug]
        );
        
        if ($tenant) {
            return true; // Tenant record exists
        }
        
        // Check if there's a pending registration with this tenant_name (which becomes tenant_slug on approval)
        $registration = $db->getRow(
            "SELECT * FROM tenant_registrations WHERE tenant_name = :name AND status = 'pending'",
            [':name' => $tenantSlug]
        );
        
        return $registration !== false;
        
    } catch (Exception $e) {
        logError("Error checking tenant existence: " . $e->getMessage());
        return false;
    }
}

function generateTenantSuggestions($base_name) {
    $suggestions = [];
    for ($i = 1; $i <= 5; $i++) {
        $suggestion = $base_name . $i;
        if (!checkTenantExists($suggestion)) {
            $suggestions[] = $suggestion;
        }
    }
    return $suggestions;
}

function createTenantAndUser($data) {
    try {
        $db = Database::getMainInstance();
        
        $registrationData = [
            'company_name' => $data['company_name'],
            'tenant_name' => $data['tenant_name'],
            'business_type' => $data['business_type'] ?? 'Electronics Retail',
            'contact_email' => $data['email'],
            'contact_person' => $data['first_name'] . ' ' . $data['last_name'],
            'country' => $data['country'] ?? 'Zimbabwe',
            'currency' => $data['currency'] ?? 'USD',
            'additional_info' => json_encode([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => HASH_COST])
            ]),
            'status' => 'pending',
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $db->insert('tenant_registrations', $registrationData);
        
        return [
            'success' => true,
            'message' => 'Registration submitted successfully. Your account will be activated after admin approval. You will receive an email with login details once approved.'
        ];
        
    } catch (Exception $e) {
        logError("Error creating tenant registration: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Registration failed. Please try again or contact support.'
        ];
    }
}

function setCurrentTenant($tenant_name) {
    initSession();
    $_SESSION['tenant_name'] = $tenant_name;
}

function getCurrentTenant() {
    initSession();
    return $_SESSION['tenant_name'] ?? null;
}

function clonePrimaryDatabase($tenantSlug) {
    try {
        $db = Database::getMainInstance();
        $primaryDb = Database::getPrimaryInstance();
        
        $databaseName = 'electrox_' . $tenantSlug;
        
        $db->getPdo()->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $tables = $primaryDb->getRows("SHOW TABLES");
        $tableKey = 'Tables_in_' . PRIMARY_DB_NAME;
        
        foreach ($tables as $table) {
            $tableName = $table[$tableKey];
            
            $db->getPdo()->exec("CREATE TABLE `{$databaseName}`.`{$tableName}` LIKE `" . PRIMARY_DB_NAME . "`.`{$tableName}`");
            $db->getPdo()->exec("INSERT INTO `{$databaseName}`.`{$tableName}` SELECT * FROM `" . PRIMARY_DB_NAME . "`.`{$tableName}`");
        }
        
        return true;
        
    } catch (Exception $e) {
        logError("Error cloning primary database: " . $e->getMessage());
        return false;
    }
}

function approveTenant($registrationId) {
    try {
        $db = Database::getMainInstance();
        
        $registration = $db->getRow("SELECT * FROM tenant_registrations WHERE id = :id", [':id' => $registrationId]);
        if (!$registration) {
            return ['success' => false, 'message' => 'Registration not found'];
        }
        
        $tenantSlug = strtolower($registration['tenant_name']);
        $additionalInfo = json_decode($registration['additional_info'], true);
        
        if (!clonePrimaryDatabase($tenantSlug)) {
            return ['success' => false, 'message' => 'Failed to create tenant database'];
        }
        
        $tenantData = [
            'tenant_name' => $registration['company_name'],
            'tenant_slug' => $tenantSlug,
            'database_name' => 'electrox_' . $tenantSlug,
            'company_name' => $registration['company_name'],
            'business_type' => $registration['business_type'],
            'contact_email' => $registration['contact_email'],
            'contact_person' => $registration['contact_person'],
            'country' => $registration['country'],
            'currency' => $registration['currency'],
            'subscription_plan' => 'Free',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $_SESSION['user_id'] ?? null
        ];
        
        $tenantId = $db->insert('tenants', $tenantData);
        
        $tenantDb = Database::getInstance(true, $tenantSlug);
        
        $adminUser = [
            'username' => $additionalInfo['email'],
            'email' => $additionalInfo['email'],
            'password' => $additionalInfo['password'],
            'first_name' => $additionalInfo['first_name'],
            'last_name' => $additionalInfo['last_name'],
            'role_id' => 1,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $tenantDb->update('users', $adminUser, ['id' => 1]);
        
        $db->update('tenant_registrations', ['status' => 'approved'], ['id' => $registrationId]);
        
        return ['success' => true, 'message' => 'Tenant approved successfully', 'tenant_id' => $tenantId];
        
    } catch (Exception $e) {
        logError("Error approving tenant: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to approve tenant: ' . $e->getMessage()];
    }
}

function getTenantSubscriptionInfo($tenantSlug) {
    try {
        $db = Database::getMainInstance();
        $tenant = $db->getRow("SELECT * FROM tenants WHERE tenant_slug = :slug", [':slug' => $tenantSlug]);
        return $tenant;
    } catch (Exception $e) {
        return null;
    }
}

function isTenantActive($tenantSlug) {
    try {
        $db = Database::getMainInstance();
        $tenant = $db->getRow(
            "SELECT status FROM tenants WHERE tenant_slug = :slug",
            [':slug' => $tenantSlug]
        );
        return $tenant && in_array($tenant['status'], ['active', 'trial']);
    } catch (Exception $e) {
        return false;
    }
}

function sendEmail($to, $subject, $body, $isHtml = true) {
    require_once APP_PATH . '/includes/mailer.php';
    $mailer = new Mailer();
    return $mailer->send($to, $subject, $body, $isHtml);
}

function getSetting($key, $default = null) {
    try {
        $db = Database::getInstance();
        $setting = $db->getRow("SELECT setting_value FROM system_settings WHERE setting_key = :key", [':key' => $key]);
        return $setting ? $setting['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setSetting($key, $value) {
    try {
        $db = Database::getInstance();
        
        // Ensure value is a string (convert null to empty string)
        $valueToSave = $value === null ? '' : (string)$value;
        
        $existing = $db->getRow("SELECT id FROM system_settings WHERE setting_key = :key", [':key' => $key]);
        
        if ($existing) {
            // Update existing setting
            $updateData = [
                'setting_value' => $valueToSave,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Only add updated_by if user is logged in
            if (isset($_SESSION['user_id'])) {
                $updateData['updated_by'] = $_SESSION['user_id'];
            }
            
            $result = $db->update('system_settings', $updateData, ['id' => $existing['id']]);
            
            // Return true even if rowCount is 0 (value unchanged) or if update succeeded
            // rowCount can be 0 if value didn't change, but that's still success
            if ($result === false) {
                $error = $db->getLastError();
                logError("Failed to update setting {$key}: " . ($error ?: 'Unknown error'));
                return false;
            }
            return true;
        } else {
            // Insert new setting
            $insertData = [
                'setting_key' => $key,
                'setting_value' => $valueToSave,
                'setting_type' => 'string',
                'category' => 'General'
            ];
            
            $result = $db->insert('system_settings', $insertData);
            
            if ($result === false) {
                $error = $db->getLastError();
                logError("Failed to insert setting {$key}: " . ($error ?: 'Unknown error'));
                return false;
            }
            return true;
        }
    } catch (Exception $e) {
        logError("Error setting setting {$key}: " . $e->getMessage());
        return false;
    }
}

function checkStockLevel($productId, $branchId = null) {
    try {
        $db = Database::getInstance();
        $branchId = $branchId ?? $_SESSION['branch_id'] ?? null;
        
        if ($branchId) {
            $stock = $db->getRow(
                "SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id",
                [':id' => $productId, ':branch_id' => $branchId]
            );
        } else {
            $stock = $db->getRow(
                "SELECT SUM(quantity_in_stock) as quantity_in_stock FROM products WHERE id = :id",
                [':id' => $productId]
            );
        }
        
        return (int)($stock['quantity_in_stock'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

function updateStock($productId, $quantity, $branchId = null, $movementType = 'Adjustment', $useTransaction = false) {
    try {
        // If useTransaction is false, get a new instance to avoid transaction conflicts
        // If true, use the passed database instance (for transaction support)
        if ($useTransaction && isset($GLOBALS['current_transaction_db'])) {
            $db = $GLOBALS['current_transaction_db'];
        } else {
            $db = Database::getInstance();
        }
        
        $branchId = $branchId ?? $_SESSION['branch_id'] ?? null;
        
        $product = $db->getRow(
            "SELECT quantity_in_stock FROM products WHERE id = :id" . ($branchId !== null ? " AND branch_id = :branch_id" : ""),
            $branchId !== null ? [':id' => $productId, ':branch_id' => $branchId] : [':id' => $productId]
        );
        
        if ($product === false) {
            $product = ['quantity_in_stock' => 0];
        }
        
        $previousQuantity = (int)($product['quantity_in_stock'] ?? 0);
        $newQuantity = $previousQuantity + $quantity;
        
        $updateWhere = ['id' => $productId];
        if ($branchId !== null) {
            $updateWhere['branch_id'] = $branchId;
        }
        
        $db->update('products', [
            'quantity_in_stock' => $newQuantity
        ], $updateWhere);
        
        $db->insert('stock_movements', [
            'product_id' => $productId,
            'branch_id' => $branchId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'previous_quantity' => $previousQuantity,
            'new_quantity' => $newQuantity,
            'user_id' => $_SESSION['user_id'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return true;
    } catch (Exception $e) {
        logError("Error updating stock: " . $e->getMessage());
        return false;
    }
}

function calculateProfit($sellingPrice, $costPrice) {
    if ($costPrice == 0) {
        return 0;
    }
    return (($sellingPrice - $costPrice) / $costPrice) * 100;
}

function getLowStockItems($branchId = null) {
    try {
        $db = Database::getInstance();
        $branchId = $branchId ?? $_SESSION['branch_id'] ?? null;
        
        $sql = "SELECT p.*, pc.name as category_name 
                FROM products p 
                LEFT JOIN product_categories pc ON p.category_id = pc.id 
                WHERE p.quantity_in_stock <= p.reorder_level 
                AND p.status = 'Active'";
        
        if ($branchId) {
            $sql .= " AND p.branch_id = :branch_id";
            return $db->getRows($sql, [':branch_id' => $branchId]);
        }
        
        return $db->getRows($sql);
    } catch (Exception $e) {
        return [];
    }
}

function ensurePOSTables($db) {
    try {
        $sqlFile = APP_PATH . '/database/pos_tables.sql';
        if (!file_exists($sqlFile)) {
            logError("POS tables SQL file not found: " . $sqlFile);
            return false;
        }
        
        $sql = file_get_contents($sqlFile);
        
        // Remove comments
        $sql = preg_replace('/--.*$/m', '', $sql); // Remove single-line comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Remove multi-line comments
        
        // Split SQL into individual statements
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) {
                $stmt = trim($stmt);
                return !empty($stmt) && strlen($stmt) > 10; // Minimum length check
            }
        );
        
        $pdo = $db->getPdo();
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // Ignore "table already exists" errors (error code 1050)
                    if ($e->getCode() != 1050 && strpos($e->getMessage(), 'already exists') === false) {
                        logError("Error creating POS table: " . $e->getMessage() . " SQL: " . substr($statement, 0, 100));
                    }
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        logError("Error ensuring POS tables: " . $e->getMessage());
        return false;
    }
}


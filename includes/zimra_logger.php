<?php
/**
 * Comprehensive ZIMRA Operation Logger
 * Logs all operations to: txt file, log file, and database
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

class ZimraLogger {
    private static $logDir = null;
    private static $txtLogFile = null;
    private static $db = null; // PDO instance
    
    /**
     * Initialize logger
     */
    public static function init() {
        if (defined('APP_PATH')) {
            self::$logDir = APP_PATH . '/logs/zimra';
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0755, true);
            }
            self::$txtLogFile = self::$logDir . '/zimra_operations_' . date('Y-m-d') . '.txt';
        }
        
        // Connect directly to electrox_primary database
        if (!self::$db) {
            try {
                $dsn = "mysql:host=localhost;dbname=electrox_primary;charset=utf8mb4";
                $pdo = new PDO($dsn, 'root', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
                self::$db = $pdo;
                self::createLoggingTables();
            } catch (Exception $e) {
                error_log("ZIMRA Logger: Failed to connect to database: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Create logging tables if they don't exist
     */
    private static function createLoggingTables() {
        if (!self::$db) return;
        
        try {
            // Create zimra_operation_logs table
            self::$db->exec("
                CREATE TABLE IF NOT EXISTS `zimra_operation_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `timestamp` datetime NOT NULL,
                    `operation` varchar(100) NOT NULL,
                    `device_id` int(11) DEFAULT NULL,
                    `request_data` text,
                    `response_data` text,
                    `status` varchar(50) DEFAULT NULL,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_timestamp` (`timestamp`),
                    KEY `idx_operation` (`operation`),
                    KEY `idx_device_id` (`device_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Create zimra_certificates table
            self::$db->exec("
                CREATE TABLE IF NOT EXISTS `zimra_certificates` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `device_id` int(11) NOT NULL,
                    `certificate_type` enum('certificate','private_key') NOT NULL,
                    `certificate_data` text NOT NULL,
                    `file_path` varchar(500) DEFAULT NULL,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_device_type` (`device_id`, `certificate_type`),
                    KEY `idx_device_id` (`device_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Create zimra_receipt_logs table
            self::$db->exec("
                CREATE TABLE IF NOT EXISTS `zimra_receipt_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `timestamp` datetime NOT NULL,
                    `device_id` int(11) NOT NULL,
                    `receipt_global_no` int(11) DEFAULT NULL,
                    `receipt_counter` int(11) DEFAULT NULL,
                    `receipt_currency` varchar(3) DEFAULT NULL,
                    `receipt_total` decimal(21,2) DEFAULT NULL,
                    `receipt_hash` varchar(255) DEFAULT NULL,
                    `request_data` text,
                    `response_data` text,
                    `status` varchar(50) DEFAULT NULL,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_device_id` (`device_id`),
                    KEY `idx_receipt_global_no` (`receipt_global_no`),
                    KEY `idx_timestamp` (`timestamp`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log("ZIMRA Logger: Failed to create tables: " . $e->getMessage());
        }
    }
    
    /**
     * Log operation to all destinations
     */
    public static function log($operation, $data, $response = null, $deviceId = null) {
        self::init();
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = [
            'timestamp' => $timestamp,
            'operation' => $operation,
            'device_id' => $deviceId,
            'request_data' => $data,
            'response' => $response,
            'status' => isset($response['status_code']) ? $response['status_code'] : (isset($response['error']) ? 'ERROR' : 'SUCCESS')
        ];
        
        // Log to TXT file
        self::logToTxt($logEntry);
        
        // Log to error.log
        self::logToErrorLog($logEntry);
        
        // Log to database
        self::logToDatabase($logEntry);
    }
    
    /**
     * Log to TXT file (human-readable)
     */
    private static function logToTxt($entry) {
        if (!self::$txtLogFile) return;
        
        $txt = "========================================\n";
        $txt .= "TIMESTAMP: {$entry['timestamp']}\n";
        $txt .= "OPERATION: {$entry['operation']}\n";
        if ($entry['device_id']) {
            $txt .= "DEVICE ID: {$entry['device_id']}\n";
        }
        $txt .= "STATUS: {$entry['status']}\n";
        $txt .= "----------------------------------------\n";
        $txt .= "REQUEST DATA:\n";
        $txt .= json_encode($entry['request_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $txt .= "----------------------------------------\n";
        if ($entry['response']) {
            $txt .= "RESPONSE:\n";
            $txt .= json_encode($entry['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
        $txt .= "========================================\n\n";
        
        @file_put_contents(self::$txtLogFile, $txt, FILE_APPEND);
    }
    
    /**
     * Log to error.log
     */
    private static function logToErrorLog($entry) {
        if (!defined('APP_PATH')) return;
        
        $logFile = APP_PATH . '/logs/error.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logMessage = "[{$entry['timestamp']}] ZIMRA OPERATION: {$entry['operation']}\n";
        if ($entry['device_id']) {
            $logMessage .= "[{$entry['timestamp']}] Device ID: {$entry['device_id']}\n";
        }
        $logMessage .= "[{$entry['timestamp']}] Status: {$entry['status']}\n";
        $logMessage .= "[{$entry['timestamp']}] Request: " . json_encode($entry['request_data'], JSON_UNESCAPED_SLASHES) . "\n";
        if ($entry['response']) {
            $logMessage .= "[{$entry['timestamp']}] Response: " . json_encode($entry['response'], JSON_UNESCAPED_SLASHES) . "\n";
        }
        $logMessage .= "[{$entry['timestamp']}] ========== END ZIMRA OPERATION ==========\n";
        
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    /**
     * Log to database
     */
    private static function logToDatabase($entry) {
        if (!self::$db) return;
        
        try {
            $stmt = self::$db->prepare("
                INSERT INTO zimra_operation_logs (timestamp, operation, device_id, request_data, response_data, status)
                VALUES (:timestamp, :operation, :device_id, :request_data, :response_data, :status)
            ");
            $stmt->execute([
                ':timestamp' => $entry['timestamp'],
                ':operation' => $entry['operation'],
                ':device_id' => $entry['device_id'],
                ':request_data' => json_encode($entry['request_data'], JSON_UNESCAPED_SLASHES),
                ':response_data' => $entry['response'] ? json_encode($entry['response'], JSON_UNESCAPED_SLASHES) : null,
                ':status' => $entry['status']
            ]);
        } catch (Exception $e) {
            // Silently fail database logging (don't break the operation)
            error_log("ZIMRA Logger: Failed to log to database: " . $e->getMessage());
        }
    }
    
    /**
     * Log certificate storage
     */
    public static function logCertificate($deviceId, $certificateType, $data, $filePath = null) {
        self::init();
        
        $timestamp = date('Y-m-d H:i:s');
        $entry = [
            'timestamp' => $timestamp,
            'operation' => 'CERTIFICATE_STORAGE',
            'device_id' => $deviceId,
            'certificate_type' => $certificateType, // 'certificate' or 'private_key'
            'file_path' => $filePath,
            'data_length' => strlen($data),
            'data_preview' => substr($data, 0, 100) . '...'
        ];
        
        // Log to TXT
        if (self::$txtLogFile) {
            $txt = "========================================\n";
            $txt .= "TIMESTAMP: {$timestamp}\n";
            $txt .= "OPERATION: CERTIFICATE STORAGE\n";
            $txt .= "DEVICE ID: {$deviceId}\n";
            $txt .= "TYPE: {$certificateType}\n";
            if ($filePath) {
                $txt .= "FILE PATH: {$filePath}\n";
            }
            $txt .= "DATA LENGTH: " . strlen($data) . " bytes\n";
            $txt .= "DATA PREVIEW: " . substr($data, 0, 200) . "...\n";
            $txt .= "FULL DATA:\n{$data}\n";
            $txt .= "========================================\n\n";
            @file_put_contents(self::$txtLogFile, $txt, FILE_APPEND);
        }
        
        // Log to error.log
        self::logToErrorLog($entry);
        
        // Log to database
        if (self::$db) {
            try {
                // Check if exists first
                $stmt = self::$db->prepare("SELECT id FROM zimra_certificates WHERE device_id = :device_id AND certificate_type = :type");
                $stmt->execute([':device_id' => $deviceId, ':type' => $certificateType]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    $stmt = self::$db->prepare("
                        UPDATE zimra_certificates 
                        SET certificate_data = :data, file_path = :file_path, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':data' => $data,
                        ':file_path' => $filePath,
                        ':id' => $existing['id']
                    ]);
                } else {
                    $stmt = self::$db->prepare("
                        INSERT INTO zimra_certificates (device_id, certificate_type, certificate_data, file_path)
                        VALUES (:device_id, :type, :data, :file_path)
                    ");
                    $stmt->execute([
                        ':device_id' => $deviceId,
                        ':type' => $certificateType,
                        ':data' => $data,
                        ':file_path' => $filePath
                    ]);
                }
            } catch (Exception $e) {
                error_log("ZIMRA Logger: Failed to log certificate: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Log receipt submission
     */
    public static function logReceipt($deviceId, $receiptData, $response, $receiptHash = null) {
        self::init();
        
        $timestamp = date('Y-m-d H:i:s');
        $entry = [
            'timestamp' => $timestamp,
            'operation' => 'SUBMIT_RECEIPT',
            'device_id' => $deviceId,
            'receipt_global_no' => $receiptData['receiptGlobalNo'] ?? null,
            'receipt_counter' => $receiptData['receiptCounter'] ?? null,
            'receipt_currency' => $receiptData['receiptCurrency'] ?? null,
            'receipt_total' => $receiptData['receiptTotal'] ?? null,
            'receipt_hash' => $receiptHash,
            'request_data' => $receiptData,
            'response' => $response,
            'status' => isset($response['validationErrors']) ? 'VALIDATION_ERROR' : (isset($response['receiptID']) ? 'SUCCESS' : 'ERROR')
        ];
        
        // Log to all destinations
        self::logToTxt($entry);
        self::logToErrorLog($entry);
        
        // Log to database
        if (self::$db) {
            try {
                $stmt = self::$db->prepare("
                    INSERT INTO zimra_receipt_logs (timestamp, device_id, receipt_global_no, receipt_counter, receipt_currency, receipt_total, receipt_hash, request_data, response_data, status)
                    VALUES (:timestamp, :device_id, :receipt_global_no, :receipt_counter, :receipt_currency, :receipt_total, :receipt_hash, :request_data, :response_data, :status)
                ");
                $stmt->execute([
                    ':timestamp' => $timestamp,
                    ':device_id' => $deviceId,
                    ':receipt_global_no' => $entry['receipt_global_no'],
                    ':receipt_counter' => $entry['receipt_counter'],
                    ':receipt_currency' => $entry['receipt_currency'],
                    ':receipt_total' => $entry['receipt_total'],
                    ':receipt_hash' => $entry['receipt_hash'],
                    ':request_data' => json_encode($receiptData, JSON_UNESCAPED_SLASHES),
                    ':response_data' => $response ? json_encode($response, JSON_UNESCAPED_SLASHES) : null,
                    ':status' => $entry['status']
                ]);
            } catch (Exception $e) {
                error_log("ZIMRA Logger: Failed to log receipt: " . $e->getMessage());
            }
        }
    }
}


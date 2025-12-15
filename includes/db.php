<?php
// Prevent direct access
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/config.php';

class Database {
    private $pdo;
    private $lastError;
    private static $instance = null;
    private $isTenantConnection = false;
    private $currentDbName = null;
    
    private function __construct($useTenantConnection = true, $tenantName = null) {
        try {
            $currentTenant = $tenantName ?: getCurrentTenantDbName();
            
            if ($useTenantConnection && $currentTenant) {
                $dbName = 'electrox_' . $currentTenant;
                $this->isTenantConnection = true;
                $this->currentDbName = $dbName;
            } else {
                $dbName = BASE_DB_NAME;
                $this->isTenantConnection = false;
                $this->currentDbName = $dbName;
            }
            
            $dsn = "mysql:host=" . DB_HOST . ";dbname=$dbName;charset=" . DB_CHARSET;
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
        } catch (PDOException $e) {
            $this->logError($e->getMessage());
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance($useTenantConnection = true, $tenantName = null) {
        $currentTenant = $tenantName ?: getCurrentTenantDbName();
        $expectedDbName = $currentTenant && $useTenantConnection ? 'electrox_' . $currentTenant : BASE_DB_NAME;
        
        if (self::$instance === null || 
            !self::$instance->isConnectedToDatabase($expectedDbName)) {
            self::$instance = new self($useTenantConnection, $tenantName);
        }
        
        return self::$instance;
    }
    
    private function isConnectedToDatabase($expectedDbName) {
        try {
            return $this->currentDbName === $expectedDbName;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public static function forceReconnect($useTenantConnection = true, $tenantName = null) {
        self::$instance = null;
        return self::getInstance($useTenantConnection, $tenantName);
    }
    
    public static function getMainInstance() {
        return new self(false);
    }
    
    public static function getPrimaryInstance() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $db = new self(false);
        $db->pdo = $pdo;
        $db->currentDbName = PRIMARY_DB_NAME;
        return $db;
    }
    
    public function executeQuery($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError($e->getMessage() . ' SQL: ' . $sql);
            return false;
        }
    }
    
    public function insert($table, $data) {
        // Escape column names with backticks to handle reserved keywords
        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($data);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                $this->logError("Insert failed: " . $errorInfo[2] . ' SQL: ' . $sql);
                $this->lastError = $errorInfo[2];
                return false;
            }
            
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->logError($e->getMessage() . ' SQL: ' . $sql);
            $this->lastError = $e->getMessage();
            return false;
        }
    }
    
    public function update($table, $data, $where, $whereParams = []) {
        $setClauses = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            if (is_array($value)) {
                continue;
            }
            // Escape column names with backticks to handle reserved keywords
            $setClauses[] = "`$column` = :$column";
            $params[":$column"] = $value;
        }
        
        if (is_array($where)) {
            $whereClauses = [];
            foreach ($where as $column => $value) {
                // Escape column names with backticks
                $whereClauses[] = "`$column` = :where_$column";
                $params[":where_$column"] = $value;
            }
            $whereClause = implode(' AND ', $whereClauses);
        } else {
            $whereClause = $where;
            if (!empty($whereParams)) {
                foreach ($whereParams as $key => $value) {
                    $params[$key] = $value;
                }
            }
        }
        
        $sql = "UPDATE `$table` SET " . implode(', ', $setClauses) . " WHERE $whereClause";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                $this->logError("Update failed: " . $errorInfo[2] . ' SQL: ' . $sql);
                $this->lastError = $errorInfo[2];
            }
            
            return $result ? $stmt->rowCount() : false;
        } catch (PDOException $e) {
            $this->logError($e->getMessage() . ' SQL: ' . $sql);
            $this->lastError = $e->getMessage();
            return false;
        }
    }
    
    public function delete($table, $where, $params = []) {
        if (is_array($where)) {
            $whereClauses = [];
            $whereParams = [];
            foreach ($where as $column => $value) {
                $whereClauses[] = "$column = :where_$column";
                $whereParams[":where_$column"] = $value;
            }
            $whereClause = implode(' AND ', $whereClauses);
            $params = array_merge($params, $whereParams);
        } else {
            $whereClause = $where;
        }
        
        $sql = "DELETE FROM $table WHERE $whereClause";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            return $result ? $stmt->rowCount() : false;
        } catch (PDOException $e) {
            $this->logError($e->getMessage() . ' SQL: ' . $sql);
            return false;
        }
    }
    
    public function getRow($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            $this->logError($e->getMessage() . ' SQL: ' . $sql);
            return false;
        }
    }
    
    public function getRows($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logError($e->getMessage() . ' SQL: ' . $sql);
            return false;
        }
    }
    
    public function getCount($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result ? (int)array_values($result)[0] : 0;
        } catch (PDOException $e) {
            $this->logError($e->getMessage() . ' SQL: ' . $sql);
            return false;
        }
    }
    
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    public function commitTransaction() {
        return $this->pdo->commit();
    }
    
    public function rollbackTransaction() {
        try {
            // Only rollback if there's an active transaction
            if ($this->pdo->inTransaction()) {
                return $this->pdo->rollBack();
            }
            return true;
        } catch (PDOException $e) {
            // If rollback fails, log it but don't throw
            $this->logError("Rollback error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getLastError() {
        return $this->lastError ?? 'Unknown database error';
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    private function logError($message) {
        $logEnabled = defined('LOG_ENABLED') ? LOG_ENABLED : true;
        $errorLogFile = defined('ERROR_LOG_FILE') ? ERROR_LOG_FILE : (__DIR__ . '/../logs/error.log');
        
        if ($logEnabled) {
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[$timestamp] [Database Error] $message" . PHP_EOL;
            $logDir = dirname($errorLogFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            @file_put_contents($errorLogFile, $logMessage, FILE_APPEND);
        }
    }
}


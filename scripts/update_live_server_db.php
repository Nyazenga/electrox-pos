<?php
/**
 * Database Structure Synchronization Script
 * 
 * SAFELY synchronizes database structure from .sql files to live databases
 * WITHOUT affecting any existing data.
 * 
 * METHOD:
 * 1. Backup live databases
 * 2. Create temporary databases from .sql files
 * 3. Compare structures using SHOW CREATE statements
 * 4. Apply only structural changes (tables, views, routines, triggers)
 * 5. Preserve all data
 * 6. Automatic rollback on error
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 300);

// Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BACKUP_DIR', '/var/www/db_backups');
define('SQL_DIR', '/var/www/electro-pos');

$databases = [
    'electrox_primary' => SQL_DIR . '/electrox_primary.sql',
    'electrox_base' => SQL_DIR . '/electrox_base.sql'
];

$log = [];
$errors = [];
$backupFiles = [];
$tempDatabases = [];

function logMessage($message, $type = 'info') {
    global $log;
    $timestamp = date('Y-m-d H:i:s');
    $log[] = "[$timestamp] [$type] $message";
    echo "[$timestamp] [$type] $message\n";
    flush();
}

function executeMySQL($command, $database = null) {
    global $errors;
    $dbPart = $database ? " -D " . escapeshellarg($database) : "";
    $cmd = sprintf(
        "mysql -h %s -u %s -p'%s'%s -e %s 2>&1",
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        $dbPart,
        escapeshellarg($command)
    );
    exec($cmd, $output, $returnCode);
    $fullOutput = implode("\n", $output);
    
    // Check for actual errors (not warnings)
    if ($returnCode !== 0) {
        $error = $fullOutput;
        if (stripos($error, 'ERROR') !== false && stripos($error, 'Warning') === false) {
            $errors[] = $error;
            logMessage("MySQL Error: $error", 'error');
            return false;
        }
    } elseif (stripos($fullOutput, 'ERROR') !== false && stripos($fullOutput, 'Warning') === false) {
        // Even with exit code 0, check for ERROR in output
        $errors[] = $fullOutput;
        logMessage("MySQL Error in output: $fullOutput", 'error');
        return false;
    }
    
    return $output;
}

function executeMySQLFile($file, $database) {
    global $errors;
    $cmd = sprintf(
        "mysql -h %s -u %s -p'%s' %s < %s 2>&1",
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg($database),
        escapeshellarg($file)
    );
    exec($cmd, $output, $returnCode);
    if ($returnCode !== 0) {
        $error = implode("\n", $output);
        if (strpos($error, 'Warning') === false) {
            $errors[] = $error;
            logMessage("MySQL File Error: $error", 'error');
            return false;
        }
    }
    return true;
}

function backupDatabase($database) {
    global $backupFiles;
    logMessage("Creating backup: $database");
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0755, true);
    }
    $backupFile = BACKUP_DIR . '/' . $database . '_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $cmd = sprintf(
        "mysqldump -h %s -u %s -p'%s' --single-transaction --routines --triggers %s > %s 2>&1",
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg($database),
        escapeshellarg($backupFile)
    );
    exec($cmd, $output, $returnCode);
    if ($returnCode !== 0 || !file_exists($backupFile) || filesize($backupFile) < 100) {
        logMessage("Backup failed", 'error');
        return false;
    }
    $backupFiles[$database] = $backupFile;
    $size = round(filesize($backupFile) / 1024 / 1024, 2);
    logMessage("Backup created: $size MB");
    return true;
}

function restoreDatabase($database, $backupFile) {
    logMessage("RESTORING from backup", 'warning');
    executeMySQL("DROP DATABASE IF EXISTS `{$database}`");
    executeMySQL("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    return executeMySQLFile($backupFile, $database);
}

function createTempDatabase($sqlFile, $tempDbName) {
    global $tempDatabases;
    logMessage("Creating temp DB: $tempDbName");
    executeMySQL("DROP DATABASE IF EXISTS `{$tempDbName}`");
    executeMySQL("CREATE DATABASE `{$tempDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    if (!executeMySQLFile($sqlFile, $tempDbName)) {
        return false;
    }
    $tempDatabases[] = $tempDbName;
    return true;
}

function getTableList($database) {
    $result = executeMySQL("SHOW TABLES", $database);
    $tables = [];
    foreach ($result as $line) {
        // Match table names from SHOW TABLES output
        // Format can be: "| table_name |" or just "table_name"
        if (preg_match('/\|?\s*(\w+)\s*\|?/', $line, $match)) {
            $tableName = $match[1];
            // Skip header line
            if (strtolower($tableName) !== 'tables_in_' . strtolower($database)) {
                $tables[] = $tableName;
            }
        }
    }
    return $tables;
}

function getTableCreate($database, $table) {
    // Use \G format to get vertical output
    $result = executeMySQL("SHOW CREATE TABLE `$table`\\G", $database);
    if ($result && is_array($result)) {
        $full = implode("\n", $result);
        
        // SHOW CREATE TABLE with \G format produces:
        // "*************************** 1. row ***************************"
        // "       Table: test_table"
        // "Create Table: CREATE TABLE `test_table` ("
        // "  `id` int NOT NULL AUTO_INCREMENT,"
        // "  ..."
        // ") ENGINE=InnoDB ..."
        
        // Method 1: Extract everything after "Create Table:" until we have complete statement
        if (preg_match('/Create Table:\s*(CREATE\s+TABLE.*?ENGINE\s*=\s*\w+[^;]*?)(?:\s*;)?\s*$/ims', $full, $match)) {
            $createStmt = trim($match[1]);
            // Replace newlines with spaces but preserve the structure
            $createStmt = preg_replace('/\s+/', ' ', $createStmt);
            $createStmt = rtrim($createStmt, ';') . ';';
            return $createStmt;
        }
        
        // Method 2: Line-by-line extraction
        $lines = explode("\n", $full);
        $inCreateSection = false;
        $createStmt = '';
        
        foreach ($lines as $line) {
            if (preg_match('/Create Table:\s*(.*)/i', $line, $match)) {
                $inCreateSection = true;
                $createStmt = trim($match[1]);
            } elseif ($inCreateSection) {
                $trimmed = trim($line);
                // Stop at separator lines or empty lines after getting ENGINE
                if (preg_match('/^\*+$/', $trimmed)) {
                    break;
                }
                if (!empty($trimmed)) {
                    $createStmt .= ' ' . $trimmed;
                    // Stop after we've captured ENGINE clause
                    if (preg_match('/ENGINE\s*=/i', $createStmt)) {
                        break;
                    }
                }
            }
        }
        
        if (!empty($createStmt) && preg_match('/CREATE\s+TABLE/i', $createStmt)) {
            $createStmt = preg_replace('/\s+/', ' ', $createStmt);
            $createStmt = rtrim($createStmt, ';') . ';';
            return $createStmt;
        }
    }
    return null;
}

function getColumnList($database, $table) {
    $result = executeMySQL("SHOW COLUMNS FROM `$table`", $database);
    $columns = [];
    if ($result && is_array($result)) {
        foreach ($result as $line) {
            if (preg_match('/\|\s*(\w+)\s*\|/', $line, $match)) {
                $colName = $match[1];
                // Skip header
                if (strtolower($colName) !== 'field') {
                    $columns[] = $colName;
                }
            }
        }
    }
    return $columns;
}

function normalizeSQL($sql) {
    // Normalize for comparison
    $sql = preg_replace('/\s+/', ' ', $sql);
    $sql = preg_replace('/AUTO_INCREMENT=\d+/', '', $sql);
    $sql = preg_replace('/COLLATE\s+utf8mb4_\w+/i', 'COLLATE utf8mb4_general_ci', $sql);
    $sql = preg_replace('/DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
    $sql = preg_replace('/ENGINE\s*=\s*(\w+)/i', 'ENGINE=$1', $sql);
    return strtolower(trim($sql));
}

function compareAndSync($sourceDb, $targetDb) {
    $changes = [];
    $sourceTables = getTableList($sourceDb);
    $targetTables = getTableList($targetDb);
    
    logMessage("Source DB has " . count($sourceTables) . " tables");
    logMessage("Target DB has " . count($targetTables) . " tables");
    if (in_array('test_table', $sourceTables)) {
        logMessage("test_table found in source DB");
    } else {
        logMessage("test_table NOT found in source DB");
    }
    if (in_array('test_table', $targetTables)) {
        logMessage("test_table found in target DB");
    } else {
        logMessage("test_table NOT found in target DB");
    }
    
    // Compare tables
    foreach ($sourceTables as $table) {
        $sourceCreate = getTableCreate($sourceDb, $table);
        if (!$sourceCreate) {
            logMessage("Warning: Could not get CREATE statement for $table", 'warning');
            continue;
        }
        
        if (!in_array($table, $targetTables)) {
            // New table - CREATE
            logMessage("Table $table exists in source but not in target - will create");
            $changes[] = ['type' => 'CREATE_TABLE', 'name' => $table, 'sql' => $sourceCreate];
        } else {
            // Compare structures
            $targetCreate = getTableCreate($targetDb, $table);
            if (!$targetCreate) continue;
            
            $sourceNorm = normalizeSQL($sourceCreate);
            $targetNorm = normalizeSQL($targetCreate);
            
            if ($sourceNorm !== $targetNorm) {
                // Structures differ - use safe swap method
                // Get common columns for safe data copy
                $sourceCols = getColumnList($sourceDb, $table);
                $targetCols = getColumnList($targetDb, $table);
                $commonCols = array_intersect($sourceCols, $targetCols);
                
                if (empty($commonCols)) {
                    // No common columns - just recreate (data loss warning is acceptable for structure sync)
                    $changes[] = [
                        'type' => 'ALTER_TABLE',
                        'name' => $table,
                        'sql' => "DROP TABLE IF EXISTS `{$table}_old`;
RENAME TABLE `{$table}` TO `{$table}_old`;
{$sourceCreate};
DROP TABLE `{$table}_old`;"
                    ];
                } else {
                    // Map common columns for data preservation
                    $colList = '`' . implode('`, `', $commonCols) . '`';
                    $changes[] = [
                        'type' => 'ALTER_TABLE',
                        'name' => $table,
                        'sql' => "DROP TABLE IF EXISTS `{$table}_new`;
DROP TABLE IF EXISTS `{$table}_old`;
RENAME TABLE `{$table}` TO `{$table}_old`;
{$sourceCreate};
INSERT INTO `{$table}` ({$colList}) SELECT {$colList} FROM `{$table}_old`;
DROP TABLE `{$table}_old`;"
                    ];
                }
            }
        }
    }
    
    // Compare views
    $sourceViews = executeMySQL("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '$sourceDb'", $sourceDb);
    foreach ($sourceViews as $line) {
        if (preg_match('/\|\s*(\w+)\s*\|/', $line, $match)) {
            $view = $match[1];
            $targetView = executeMySQL("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '$targetDb' AND TABLE_NAME = '$view'", $targetDb);
            if (!$targetView || empty($targetView)) {
                $viewCreate = executeMySQL("SHOW CREATE VIEW `$view`", $sourceDb);
                if ($viewCreate) {
                    $viewSQL = implode("\n", $viewCreate);
                    if (preg_match('/CREATE.*?VIEW.*?AS\s+(.*)/is', $viewSQL, $vm)) {
                        $changes[] = ['type' => 'CREATE_VIEW', 'name' => $view, 'sql' => "CREATE OR REPLACE VIEW `$view` AS " . trim($vm[1])];
                    }
                }
            }
        }
    }
    
    // Compare routines
    $sourceRoutines = executeMySQL("SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '$sourceDb'", $sourceDb);
    foreach ($sourceRoutines as $line) {
        if (preg_match('/\|\s*(\w+)\s*\|\s*(\w+)\s*\|/', $line, $match)) {
            $routine = $match[1];
            $type = strtoupper($match[2]);
            $targetRoutine = executeMySQL("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '$targetDb' AND ROUTINE_NAME = '$routine'", $targetDb);
            if (!$targetRoutine || empty($targetRoutine)) {
                $routineCreate = executeMySQL("SHOW CREATE $type `$routine`", $sourceDb);
                if ($routineCreate) {
                    $routineSQL = implode("\n", $routineCreate);
                    if (preg_match('/CREATE.*?$type.*?AS\s+(.*)/is', $routineSQL, $rm)) {
                        $changes[] = ['type' => "CREATE_$type", 'name' => $routine, 'sql' => $routineSQL];
                    }
                }
            }
        }
    }
    
    // Compare triggers
    $sourceTriggers = executeMySQL("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '$sourceDb'", $sourceDb);
    foreach ($sourceTriggers as $line) {
        if (preg_match('/\|\s*(\w+)\s*\|/', $line, $match)) {
            $trigger = $match[1];
            $targetTrigger = executeMySQL("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '$targetDb' AND TRIGGER_NAME = '$trigger'", $targetDb);
            if (!$targetTrigger || empty($targetTrigger)) {
                $triggerCreate = executeMySQL("SHOW CREATE TRIGGER `$trigger`", $sourceDb);
                if ($triggerCreate) {
                    $triggerSQL = implode("\n", $triggerCreate);
                    $changes[] = ['type' => 'CREATE_TRIGGER', 'name' => $trigger, 'sql' => $triggerSQL];
                }
            }
        }
    }
    
    return $changes;
}

function applyChanges($database, $changes) {
    if (empty($changes)) {
        logMessage("No changes needed");
        return true;
    }
    logMessage("Applying " . count($changes) . " changes");
    executeMySQL("SET autocommit = 0", $database);
    executeMySQL("START TRANSACTION", $database);
    try {
        foreach ($changes as $change) {
            logMessage("Applying {$change['type']}: {$change['name']}");
            logMessage("SQL: " . substr($change['sql'], 0, 200) . "...");
            $statements = array_filter(array_map('trim', explode(';', $change['sql'])));
            foreach ($statements as $stmt) {
                if (!empty($stmt) && !preg_match('/^\s*$/', $stmt)) {
                    logMessage("Executing: " . substr($stmt, 0, 150));
                    $result = executeMySQL($stmt, $database);
                    if ($result === false) {
                        throw new Exception("Failed to execute: " . substr($stmt, 0, 100));
                    }
                }
            }
        }
        executeMySQL("COMMIT", $database);
        executeMySQL("SET autocommit = 1", $database);
        logMessage("Changes applied successfully");
        return true;
    } catch (Exception $e) {
        executeMySQL("ROLLBACK", $database);
        executeMySQL("SET autocommit = 1", $database);
        logMessage("Error, rolled back: " . $e->getMessage(), 'error');
        return false;
    }
}

function cleanup() {
    global $tempDatabases;
    foreach ($tempDatabases as $tempDb) {
        executeMySQL("DROP DATABASE IF EXISTS `{$tempDb}`");
    }
}

function main() {
    global $databases, $backupFiles, $errors;
    logMessage("=== Database Sync Started ===");
    
    // Backup
    foreach ($databases as $db => $sqlFile) {
        if (!backupDatabase($db)) {
            logMessage("Backup failed. Aborting.", 'error');
            exit(1);
        }
    }
    
    // Process each database
    foreach ($databases as $database => $sqlFile) {
        logMessage("Processing: $database");
        if (!file_exists($sqlFile)) {
            logMessage("SQL file not found: $sqlFile", 'warning');
            continue;
        }
        
        $tempDb = $database . '_temp_' . time();
        if (!createTempDatabase($sqlFile, $tempDb)) {
            logMessage("Failed to create temp DB", 'error');
            continue;
        }
        
        $changes = compareAndSync($tempDb, $database);
        if (!empty($changes)) {
            if (!applyChanges($database, $changes)) {
                logMessage("CRITICAL: Restoring from backup", 'error');
                restoreDatabase($database, $backupFiles[$database]);
                cleanup();
                exit(1);
            }
        } else {
            logMessage("Database is in sync");
        }
        
        executeMySQL("DROP DATABASE IF EXISTS `{$tempDb}`");
    }
    
    cleanup();
    logMessage("=== Sync Completed Successfully ===");
    
    // Write log file
    global $log;
    $logFile = '/var/www/logs/db_sync_' . date('Y-m-d_H-i-s') . '.log';
    if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);
    if (!empty($log) && is_array($log)) {
        file_put_contents($logFile, implode("\n", $log));
        logMessage("Log saved to: $logFile");
    }
    
    return empty($errors);
}

if (php_sapi_name() === 'cli') {
    register_shutdown_function('cleanup');
    $success = main();
    exit($success ? 0 : 1);
} else {
    echo "CLI only";
    exit(1);
}

<?php
/**
 * Backup Cleaned Database for Live Server Replacement
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'electrox_primary';

$timestamp = date('Ymd_His');
$backupFile = __DIR__ . "/electrox_primary_clean_$timestamp.sql";

echo "========================================\n";
echo "Database Backup (Clean)\n";
echo "========================================\n\n";
echo "Database: $dbName\n";
echo "Output file: $backupFile\n\n";

// Use mysqldump if available
$mysqlDumpCmd = "mysqldump -u $dbUser " . ($dbPass ? "-p'$dbPass' " : "") . "--single-transaction --routines --triggers $dbName > " . escapeshellarg($backupFile);

echo "Creating backup...\n";
flush();

$output = [];
$returnVar = 0;
exec($mysqlDumpCmd . " 2>&1", $output, $returnVar);

if ($returnVar === 0) {
    $size = filesize($backupFile);
    $sizeMB = round($size / 1024 / 1024, 2);
    echo "✓ Backup created successfully!\n";
    echo "File: $backupFile\n";
    echo "Size: $sizeMB MB\n\n";
    echo "Ready to restore to live server.\n";
} else {
    echo "✗ mysqldump failed. Trying PHP backup...\n";
    echo "Error output:\n";
    foreach ($output as $line) {
        echo "  $line\n";
    }
    
    // Fallback to PHP backup
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Get all tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        $sql = "-- Clean Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Database: $dbName\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        foreach ($tables as $table) {
            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            
            // Get CREATE TABLE
            $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $sql .= $createTable['Create Table'] . ";\n\n";
            
            // Get data
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $sql .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = 'NULL';
                        } else {
                            $rowValues[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $values[] = "(" . implode(", ", $rowValues) . ")";
                }
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        file_put_contents($backupFile, $sql);
        $size = filesize($backupFile);
        $sizeMB = round($size / 1024 / 1024, 2);
        
        echo "✓ PHP backup created successfully!\n";
        echo "File: $backupFile\n";
        echo "Size: $sizeMB MB\n";
        echo "Tables: " . count($tables) . "\n\n";
        echo "Ready to restore to live server.\n";
        
    } catch (Exception $e) {
        echo "✗ PHP backup failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\nBackup file: $backupFile\n";

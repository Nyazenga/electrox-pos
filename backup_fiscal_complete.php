<?php
/**
 * Complete Fiscalization Backup Script
 * Run this directly on the server: php backup_fiscal_complete.php
 */

set_time_limit(60);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dbHost = 'localhost';
$dbUser = 'grcadmin';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';
$outputFile = '/tmp/fiscal_backup_' . date('Ymd_His') . '.sql';

echo "========================================\n";
echo "Fiscalization Data Backup\n";
echo "========================================\n\n";

try {
    echo "Connecting to database...\n";
    flush();
    
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10
    ]);
    
    echo "✓ Connected to $dbName\n\n";
    flush();
    
    $tables = [
        'fiscal_devices',
        'fiscal_config', 
        'fiscal_days',
        'fiscal_receipts',
        'fiscal_receipt_lines',
        'fiscal_receipt_taxes',
        'fiscal_receipt_payments',
        'fiscal_counters'
    ];
    
    $fp = fopen($outputFile, 'w');
    if (!$fp) {
        throw new Exception("Cannot create file: $outputFile");
    }
    
    fwrite($fp, "-- Fiscalization Data Backup\n");
    fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- Source: Live Server ($dbName)\n\n");
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    $totalRows = 0;
    
    foreach ($tables as $table) {
        echo "Processing: $table\n";
        flush();
        
        // Check if table exists
        $exists = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        if (!$exists) {
            echo "  ⚠ Table does not exist, skipping\n";
            continue;
        }
        
        // Get table structure
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        fwrite($fp, "-- Table: $table\n");
        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fp, $create['Create Table'] . ";\n\n");
        
        // Get data
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = count($rows);
        $totalRows += $rowCount;
        
        echo "  Found $rowCount rows\n";
        flush();
        
        if ($rowCount > 0) {
            fwrite($fp, "-- Data for $table ($rowCount rows)\n");
            fwrite($fp, "LOCK TABLES `$table` WRITE;\n");
            
            $columns = array_keys($rows[0]);
            $colList = '`' . implode('`, `', $columns) . '`';
            
            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = $pdo->quote($value);
                    }
                }
                fwrite($fp, "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $values) . ");\n");
            }
            
            fwrite($fp, "UNLOCK TABLES;\n\n");
        }
    }
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    
    $fileSize = filesize($outputFile);
    
    echo "\n";
    echo "========================================\n";
    echo "✓ Backup completed successfully!\n";
    echo "========================================\n";
    echo "File: $outputFile\n";
    echo "Size: " . number_format($fileSize / 1024, 2) . " KB\n";
    echo "Total rows: $totalRows\n";
    echo "\n";
    echo "To download to local machine:\n";
    echo "  pscp.exe -pw \"GRCAdmin123/\" root@31.97.199.82:$outputFile .\n";
    echo "\n";
    
} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

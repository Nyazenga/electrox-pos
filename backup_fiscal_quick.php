<?php
set_time_limit(30);
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 30);

$dbHost = 'localhost';
$dbUser = 'grcadmin';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';
$outputFile = '/tmp/fiscal_backup_' . date('Ymd_His') . '.sql';

echo "Starting backup...\n";
flush();

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    echo "Connected\n";
    flush();
    
    $tables = ['fiscal_devices', 'fiscal_config', 'fiscal_days', 'fiscal_receipts', 'fiscal_receipt_lines', 'fiscal_receipt_taxes', 'fiscal_receipt_payments', 'fiscal_counters'];
    $fp = fopen($outputFile, 'w');
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    foreach ($tables as $table) {
        echo "Backing up $table...\n";
        flush();
        
        $exists = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        if (!$exists) {
            echo "  Table not found, skipping\n";
            continue;
        }
        
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fp, $create['Create Table'] . ";\n\n");
        
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        echo "  Found " . count($rows) . " rows\n";
        flush();
        
        if (count($rows) > 0) {
            fwrite($fp, "LOCK TABLES `$table` WRITE;\n");
            $cols = array_keys($rows[0]);
            $colList = '`' . implode('`, `', $cols) . '`';
            
            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $pdo->quote($v);
                }
                fwrite($fp, "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $vals) . ");\n");
            }
            fwrite($fp, "UNLOCK TABLES;\n\n");
        }
    }
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    
    $size = filesize($outputFile);
    echo "\nDone! File: $outputFile\n";
    echo "Size: " . round($size/1024, 2) . " KB\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

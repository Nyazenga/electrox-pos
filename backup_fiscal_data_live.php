<?php
/**
 * Backup Fiscalization Data from Live Server
 * Exports all fiscalization-related tables to SQL file
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Live server credentials
$dbHost = 'localhost';
$dbUser = 'grcadmin';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';

// Tables to backup
$fiscalTables = [
    'fiscal_devices',
    'fiscal_config',
    'fiscal_days',
    'fiscal_receipts',
    'fiscal_receipt_lines',
    'fiscal_receipt_taxes',
    'fiscal_receipt_payments',
    'fiscal_counters'
];

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "Connected to live database: $dbName\n";
    echo "========================================\n\n";
    
    $outputFile = '/tmp/fiscal_data_backup_' . date('Y-m-d_His') . '.sql';
    $fp = fopen($outputFile, 'w');
    
    if (!$fp) {
        throw new Exception("Cannot create output file: $outputFile");
    }
    
    fwrite($fp, "-- Fiscalization Data Backup\n");
    fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- Source: Live Server (electrox_primary)\n\n");
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    foreach ($fiscalTables as $table) {
        echo "Backing up table: $table\n";
        flush();
        
        // Check if table exists
        $tableExists = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        if (!$tableExists) {
            echo "  ⚠ Table '$table' does not exist, skipping...\n";
            continue;
        }
        
        // Get table structure
        $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        fwrite($fp, "-- Table structure for table `$table`\n");
        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fp, $createTable['Create Table'] . ";\n\n");
        
        // Get table data
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = count($rows);
        
        if ($rowCount > 0) {
            echo "  Found $rowCount rows\n";
            flush();
            
            fwrite($fp, "-- Data for table `$table`\n");
            fwrite($fp, "LOCK TABLES `$table` WRITE;\n");
            
            // Get column names
            $columns = array_keys($rows[0]);
            $columnList = '`' . implode('`, `', $columns) . '`';
            
            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        // Escape special characters
                        $escaped = $pdo->quote($value);
                        $values[] = $escaped;
                    }
                }
                $valuesList = implode(', ', $values);
                fwrite($fp, "INSERT INTO `$table` ($columnList) VALUES ($valuesList);\n");
            }
            
            fwrite($fp, "UNLOCK TABLES;\n\n");
        } else {
            echo "  No data found\n";
            fwrite($fp, "-- No data for table `$table`\n\n");
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
    echo "\n";
    echo "To download, run on your local machine:\n";
    echo "  pscp.exe -pw \"GRCAdmin123/\" root@31.97.199.82:$outputFile .\n";
    echo "\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

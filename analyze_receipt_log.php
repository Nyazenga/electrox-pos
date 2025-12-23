<?php
/**
 * Analyze Fiscal Service Receipt Data Log
 * Identifies patterns, issues, and statistics from the receipt log file
 */

$logFile = __DIR__ . '/logs/fiscal_service_receipt_data_log.txt';

if (!file_exists($logFile)) {
    die("Log file not found: $logFile\n");
}

$content = file_get_contents($logFile);

// Statistics
$stats = [
    'total_entries' => 0,
    'by_device' => [],
    'previous_hash_null' => 0,
    'previous_hash_set' => 0,
    'receipt_counter_issues' => [],
    'receipt_global_no_sequence' => [],
    'tax_precision_issues' => [],
    'receipt_counter_resets' => 0,
];

// Parse entries - split by entry separators
$entryBlocks = preg_split('/={40,}/', $content);
$entries = [];

foreach ($entryBlocks as $blockIdx => $block) {
    if (empty(trim($block))) continue;
    
    $currentEntry = [
        'timestamp' => null,
        'device_id' => null,
        'previous_hash' => null,
        'receipt_counter' => null,
        'receipt_global_no' => null,
        'receipt_total' => null,
        'tax_amount' => null,
        'invoice_no' => null,
    ];
    
    $lines = explode("\n", $block);
    foreach ($lines as $line) {
        // Extract timestamp
        if (preg_match('/\[([\d\-\s:]+)\]/', $line, $matches)) {
            $currentEntry['timestamp'] = $matches[1];
        }
        
        // Extract device ID
        if (preg_match('/Device ID:\s*(\d+)/', $line, $matches)) {
            $currentEntry['device_id'] = (int)$matches[1];
            if (!isset($stats['by_device'][$currentEntry['device_id']])) {
                $stats['by_device'][$currentEntry['device_id']] = 0;
            }
            $stats['by_device'][$currentEntry['device_id']]++;
        }
        
        // Extract previous receipt hash
        if (preg_match('/Previous Receipt Hash:\s*(NULL|[\w\+\/\.=]+)/', $line, $matches)) {
            $currentEntry['previous_hash'] = $matches[1];
            if ($matches[1] === 'NULL') {
                $stats['previous_hash_null']++;
            } else {
                $stats['previous_hash_set']++;
            }
        }
        
        // Extract receipt counter
        if (preg_match('/"receiptCounter":\s*(\d+)/', $line, $matches)) {
            $currentEntry['receipt_counter'] = (int)$matches[1];
        }
        
        // Extract receipt global number
        if (preg_match('/"receiptGlobalNo":\s*(\d+)/', $line, $matches)) {
            $currentEntry['receipt_global_no'] = (int)$matches[1];
        }
        
        // Extract receipt total
        if (preg_match('/"receiptTotal":\s*([\d\.]+)/', $line, $matches)) {
            $currentEntry['receipt_total'] = (float)$matches[1];
        }
        
        // Extract tax amount
        if (preg_match('/"taxAmount":\s*([\d\.]+)/', $line, $matches)) {
            $currentEntry['tax_amount'] = $matches[1];
            // Check for precision issues (more than 2 decimal places)
            if (strpos($matches[1], '.') !== false) {
                $decimals = strlen(substr(strrchr($matches[1], "."), 1));
                if ($decimals > 2) {
                    $stats['tax_precision_issues'][] = [
                        'entry' => count($entries) + 1,
                        'tax_amount' => $matches[1],
                        'decimals' => $decimals,
                    ];
                }
            }
        }
        
        // Extract invoice number
        if (preg_match('/"invoiceNo":\s*"([^"]+)"/', $line, $matches)) {
            $currentEntry['invoice_no'] = $matches[1];
        }
    }
    
    // Only add if we have at least a device ID
    if ($currentEntry['device_id'] !== null) {
        $entries[] = $currentEntry;
        $stats['total_entries']++;
    }
}

// Analyze receipt counter sequences per device
$deviceCounters = [];
foreach ($entries as $idx => $entry) {
    if (!$entry['device_id']) continue;
    
    $deviceId = $entry['device_id'];
    if (!isset($deviceCounters[$deviceId])) {
        $deviceCounters[$deviceId] = [
            'last_counter' => null,
            'last_global_no' => null,
            'resets' => 0,
            'counter_issues' => [],
        ];
    }
    
    // Track receipt global number sequence
    if ($entry['receipt_global_no'] !== null) {
        if (!isset($stats['receipt_global_no_sequence'][$deviceId])) {
            $stats['receipt_global_no_sequence'][$deviceId] = [];
        }
        $stats['receipt_global_no_sequence'][$deviceId][] = $entry['receipt_global_no'];
    }
    
    // Check for counter resets or inconsistencies
    if ($deviceCounters[$deviceId]['last_counter'] !== null && 
        $entry['receipt_counter'] !== null) {
        
        $expectedCounter = $deviceCounters[$deviceId]['last_counter'] + 1;
        if ($entry['receipt_counter'] < $expectedCounter) {
            if ($entry['receipt_counter'] === 1) {
                $deviceCounters[$deviceId]['resets']++;
                $stats['receipt_counter_resets']++;
            } else {
                $deviceCounters[$deviceId]['counter_issues'][] = [
                    'entry_idx' => $idx + 1,
                    'expected' => $expectedCounter,
                    'actual' => $entry['receipt_counter'],
                    'global_no' => $entry['receipt_global_no'],
                ];
            }
        }
    }
    
    if ($entry['receipt_counter'] !== null) {
        $deviceCounters[$deviceId]['last_counter'] = $entry['receipt_counter'];
    }
    if ($entry['receipt_global_no'] !== null) {
        $deviceCounters[$deviceId]['last_global_no'] = $entry['receipt_global_no'];
    }
}

// Output results
echo "========================================\n";
echo "FISCAL SERVICE RECEIPT LOG ANALYSIS\n";
echo "========================================\n\n";

echo "OVERALL STATISTICS:\n";
echo "-------------------\n";
echo "Total entries: " . $stats['total_entries'] . "\n";
echo "Entries by device:\n";
foreach ($stats['by_device'] as $deviceId => $count) {
    echo "  Device $deviceId: $count entries\n";
}
echo "\n";

echo "PREVIOUS RECEIPT HASH ANALYSIS:\n";
echo "-------------------------------\n";
if ($stats['total_entries'] > 0) {
    echo "NULL (missing chain): " . $stats['previous_hash_null'] . " (" . 
         round(100 * $stats['previous_hash_null'] / $stats['total_entries'], 1) . "%)\n";
    echo "Set (properly chained): " . $stats['previous_hash_set'] . " (" . 
         round(100 * $stats['previous_hash_set'] / $stats['total_entries'], 1) . "%)\n";
} else {
    echo "NULL (missing chain): " . $stats['previous_hash_null'] . "\n";
    echo "Set (properly chained): " . $stats['previous_hash_set'] . "\n";
}
echo "\n";

echo "RECEIPT COUNTER ANALYSIS:\n";
echo "-------------------------\n";
echo "Total counter resets to 1: " . $stats['receipt_counter_resets'] . "\n";
foreach ($deviceCounters as $deviceId => $deviceStats) {
    echo "\nDevice $deviceId:\n";
    echo "  Counter resets: " . $deviceStats['resets'] . "\n";
    echo "  Last counter: " . ($deviceStats['last_counter'] ?? 'N/A') . "\n";
    echo "  Last global no: " . ($deviceStats['last_global_no'] ?? 'N/A') . "\n";
    if (!empty($deviceStats['counter_issues'])) {
        echo "  Counter issues (non-sequential): " . count($deviceStats['counter_issues']) . "\n";
        if (count($deviceStats['counter_issues']) <= 5) {
            foreach ($deviceStats['counter_issues'] as $issue) {
                echo "    Entry #{$issue['entry_idx']}: Expected {$issue['expected']}, got {$issue['actual']} (Global No: {$issue['global_no']})\n";
            }
        }
    }
}
echo "\n";

echo "RECEIPT GLOBAL NUMBER SEQUENCE:\n";
echo "------------------------------\n";
foreach ($stats['receipt_global_no_sequence'] as $deviceId => $sequence) {
    echo "Device $deviceId: ";
    $unique = array_unique($sequence);
    if (count($unique) === count($sequence)) {
        echo "Sequential (no duplicates)\n";
    } else {
        $duplicates = array_count_values($sequence);
        $dupCount = 0;
        foreach ($duplicates as $num => $count) {
            if ($count > 1) {
                $dupCount += $count - 1;
            }
        }
        echo "Has " . $dupCount . " duplicate global numbers\n";
    }
    echo "  Range: " . min($sequence) . " to " . max($sequence) . "\n";
    echo "  Count: " . count($sequence) . " entries\n";
}
echo "\n";

echo "TAX PRECISION ANALYSIS:\n";
echo "-----------------------\n";
echo "Entries with > 2 decimal places: " . count($stats['tax_precision_issues']) . "\n";
if (!empty($stats['tax_precision_issues'])) {
    $uniqueAmounts = [];
    foreach ($stats['tax_precision_issues'] as $issue) {
        $uniqueAmounts[$issue['tax_amount']] = true;
    }
    echo "Unique problematic amounts: " . count($uniqueAmounts) . "\n";
    if (count($uniqueAmounts) <= 10) {
        foreach (array_keys($uniqueAmounts) as $amount) {
            echo "  $amount\n";
        }
    }
}
echo "\n";

// Find entries with mismatched counter vs global number
echo "COUNTER vs GLOBAL NUMBER MISMATCHES:\n";
echo "------------------------------------\n";
$mismatches = 0;
foreach ($entries as $idx => $entry) {
    if ($entry['receipt_counter'] !== null && 
        $entry['receipt_global_no'] !== null &&
        $entry['receipt_counter'] !== $entry['receipt_global_no']) {
        $mismatches++;
        if ($mismatches <= 10) {
            echo "Entry #" . ($idx + 1) . " (Device {$entry['device_id']}): Counter={$entry['receipt_counter']}, GlobalNo={$entry['receipt_global_no']}\n";
        }
    }
}
if ($mismatches > 10) {
    echo "... and " . ($mismatches - 10) . " more\n";
}
echo "Total mismatches: $mismatches\n";
echo "\n";

// Summary of key issues
echo "========================================\n";
echo "KEY ISSUES SUMMARY:\n";
echo "========================================\n";
$issues = [];

if ($stats['previous_hash_null'] > $stats['total_entries'] * 0.5) {
    $issues[] = "CRITICAL: Over 50% of receipts have NULL previous hash (chain broken)";
}

if ($stats['receipt_counter_resets'] > 0) {
    $issues[] = "WARNING: Receipt counter resets detected ($stats[receipt_counter_resets] times)";
}

if (count($stats['tax_precision_issues']) > 0) {
    $issues[] = "WARNING: Tax amounts with excessive decimal precision found";
}

if ($mismatches > 0) {
    $issues[] = "WARNING: Receipt counter and global number mismatches found ($mismatches instances)";
}

if (empty($issues)) {
    echo "No major issues detected.\n";
} else {
    foreach ($issues as $issue) {
        echo "• $issue\n";
    }
}
echo "\n";


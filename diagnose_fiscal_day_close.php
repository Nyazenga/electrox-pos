<?php
/**
 * Diagnostic tool to identify why fiscal day close is failing
 * This will check:
 * 1. Error code from ZIMRA
 * 2. Receipt submission status
 * 3. Counter calculations
 * 4. Receipt chain integrity
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$branchParam = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : null;
$branchName = isset($_GET['branch_name']) ? trim($_GET['branch_name']) : null;

// Debug: Show what we received
// echo "DEBUG: branchParam = " . var_export($branchParam, true) . "\n";
// echo "DEBUG: branchName = " . var_export($branchName, true) . "\n";

echo "========================================\n";
echo "FISCAL DAY CLOSE DIAGNOSTIC TOOL\n";
echo "========================================\n\n";

try {
    $db = Database::getPrimaryInstance();
    $branchId = null;
    
    // Try to find branch by ID or name
    // If branch_name is explicitly provided, use it; otherwise check branchParam
    if ($branchName) {
        // branch_name was explicitly provided, use it
    } elseif ($branchParam) {
        if (is_numeric($branchParam)) {
            $branchId = intval($branchParam);
        } else {
            $branchName = $branchParam;
        }
    }
    
    // If branch_id is provided and valid, use it first
    if ($branchId && is_numeric($branchId)) {
        $branch = $db->getRow("SELECT id, branch_name FROM branches WHERE id = :id", [':id' => $branchId]);
        if ($branch) {
            echo "Using branch: {$branch['branch_name']} (ID: $branchId)\n\n";
        } else {
            $branchId = null; // Reset if not found
        }
    }
    
    // If no branch found by ID, try by name
    if (!$branchId && $branchName) {
        $searchName = trim($branchName);
        // Try exact match by branch_name first
        $branch = $db->getRow(
            "SELECT id, branch_name FROM branches WHERE branch_name = :name",
            [':name' => $searchName]
        );
        // If not found, try branch_code
        if (!$branch) {
            $branch = $db->getRow(
                "SELECT id, branch_name FROM branches WHERE branch_code = :name",
                [':name' => $searchName]
            );
        }
        // If still not found, try case-insensitive
        if (!$branch) {
            $branch = $db->getRow(
                "SELECT id, branch_name FROM branches WHERE LOWER(branch_name) = LOWER(:name)",
                [':name' => $searchName]
            );
        }
        if (!$branch) {
            $branch = $db->getRow(
                "SELECT id, branch_name FROM branches WHERE LOWER(branch_code) = LOWER(:name)",
                [':name' => $searchName]
            );
        }
        if ($branch) {
            $branchId = $branch['id'];
            echo "Using branch: {$branch['branch_name']} (ID: $branchId)\n\n";
        }
    }
    
    // If still no branch found, show error
    if (!$branchId) {
        $searchTerm = $branchName ?: ($branchParam ?: 'specified');
        echo "✗ ERROR: Branch '$searchTerm' not found\n\n";
        // Show available branches
        $branches = $db->getRows("SELECT id, branch_name FROM branches ORDER BY branch_name");
        if (!empty($branches)) {
            echo "Available branches:\n";
            foreach ($branches as $b) {
                echo "  - {$b['branch_name']} (ID: {$b['id']})\n";
            }
            echo "\nUsage: diagnose_fiscal_day_close.php?branch_id={id} OR ?branch_name={name}\n";
        }
        exit(1);
    }
    
    // Get branch details for later use (should already be set, but ensure it is)
    if (!isset($branch)) {
        $branch = $db->getRow("SELECT id, branch_name FROM branches WHERE id = :id", [':id' => $branchId]);
    }
    
    // First, check if device exists
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
        [':branch_id' => $branchId]
    );
    
    if (!$device) {
        echo "✗ ERROR: No fiscal device configured for branch ID $branchId ({$branch['branch_name']})\n\n";
        
        // Check if there are any devices for this branch (even inactive)
        $anyDevice = $db->getRow(
            "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id",
            [':branch_id' => $branchId]
        );
        if ($anyDevice) {
            echo "⚠ Found device but it's not active:\n";
            echo "   Device ID: {$anyDevice['device_id']}\n";
            echo "   Active: " . ($anyDevice['is_active'] ? 'Yes' : 'No') . "\n";
            echo "   Registered: " . ($anyDevice['is_registered'] ? 'Yes' : 'No') . "\n\n";
        }
        
        // Show all configured devices
        $allDevices = $db->getRows("
            SELECT fd.*, b.branch_name 
            FROM fiscal_devices fd 
            LEFT JOIN branches b ON fd.branch_id = b.id 
            ORDER BY b.branch_name, fd.device_id
        ");
        if (!empty($allDevices)) {
            echo "Configured fiscal devices:\n";
            foreach ($allDevices as $d) {
                $status = $d['is_active'] ? 'Active' : 'Inactive';
                echo "  - {$d['branch_name']} (Branch ID: {$d['branch_id']}): Device {$d['device_id']} - $status\n";
            }
            echo "\n";
        }
        
        echo "SOLUTION:\n";
        echo "1. Go to Settings → Fiscalization (ZIMRA)\n";
        echo "2. Configure a fiscal device for this branch\n";
        echo "3. Register the device with ZIMRA\n";
        echo "4. Then run this diagnostic tool again\n\n";
        exit(1);
    }
    
    if (!$device['is_registered'] || empty($device['certificate_pem'])) {
        echo "⚠ WARNING: Device is not registered or certificate is missing\n";
        echo "   Device ID: " . ($device['device_id'] ?? 'N/A') . "\n";
        echo "   Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
        echo "   Certificate: " . (empty($device['certificate_pem']) ? 'Missing' : 'Present') . "\n\n";
        echo "SOLUTION:\n";
        echo "1. Go to Settings → Fiscalization (ZIMRA)\n";
        echo "2. Register the device with ZIMRA\n";
        echo "3. Then run this diagnostic tool again\n\n";
        exit(1);
    }
    
    $fiscalService = new FiscalService($branchId);
    
    // 1. Get ZIMRA status and error code
    echo "1. CHECKING ZIMRA STATUS...\n";
    echo "   ----------------------------------------\n";
    $status = $fiscalService->getFiscalDayStatus();
    
    if (!$status) {
        die("   ✗ Could not retrieve status from ZIMRA\n");
    }
    
    $fiscalDayStatus = $status['fiscalDayStatus'] ?? 'Unknown';
    $fiscalDayNo = $status['lastFiscalDayNo'] ?? null;
    $lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? null;
    $errorCode = $status['fiscalDayClosingErrorCode'] ?? null;
    
    echo "   Status: $fiscalDayStatus\n";
    echo "   Fiscal Day No: " . ($fiscalDayNo ?? 'N/A') . "\n";
    echo "   Last Receipt Global No: " . ($lastReceiptGlobalNo ?? 'N/A') . "\n";
    
    if ($errorCode) {
        echo "   ✗ ERROR CODE: $errorCode\n";
        $errorDescriptions = [
            'BadCertificateSignature' => 'Bad certificate signature is used',
            'MissingReceipts' => 'There are missing receipts in fiscal day (Grey validation error)',
            'ReceiptsWithValidationErrors' => 'There are receipts with validation errors (Red validation error)',
            'CountersMismatch' => 'There are mismatches between counters'
        ];
        $errorDesc = $errorDescriptions[$errorCode] ?? 'Unknown error';
        echo "   Description: $errorDesc\n";
    } else {
        echo "   ✓ No error code (day may not be in failed state)\n";
    }
    echo "\n";
    
    if ($fiscalDayStatus !== 'FiscalDayCloseFailed' && $fiscalDayStatus !== 'FiscalDayOpened') {
        echo "   ⚠ Fiscal day is not in a state that can be closed.\n";
        echo "   Current status: $fiscalDayStatus\n";
        echo "   Expected: FiscalDayOpened or FiscalDayCloseFailed\n\n";
        exit(0);
    }
    
    // 2. Get device info (already retrieved above)
    $deviceId = $device['device_id'];
    echo "2. DEVICE INFORMATION...\n";
    echo "   ----------------------------------------\n";
    echo "   Device ID: $deviceId\n";
    echo "   Branch ID: $branchId\n";
    echo "   Branch Name: " . ($db->getRow("SELECT branch_name FROM branches WHERE id = :id", [':id' => $branchId])['branch_name'] ?? 'N/A') . "\n";
    echo "   Device Serial: " . ($device['device_serial_no'] ?? 'N/A') . "\n";
    echo "   Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n\n";
    
    // 3. Check receipts for this fiscal day
    echo "3. CHECKING RECEIPTS FOR FISCAL DAY $fiscalDayNo...\n";
    echo "   ----------------------------------------\n";
    
    // Use tenant database for receipts (fiscal_receipts is in tenant DB)
    $tenantDb = Database::getInstance();
    $receipts = $tenantDb->getRows(
        "SELECT fr.*, 
         COUNT(DISTINCT frt.id) as tax_line_count,
         GROUP_CONCAT(DISTINCT frt.tax_id) as tax_ids,
         GROUP_CONCAT(DISTINCT frt.tax_percent) as tax_percents
         FROM fiscal_receipts fr 
         LEFT JOIN fiscal_receipt_taxes frt ON fr.id = frt.fiscal_receipt_id 
         WHERE fr.device_id = :device_id AND fr.fiscal_day_no = :fiscal_day_no
         GROUP BY fr.id
         ORDER BY fr.receipt_counter ASC",
        [':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
    );
    
    if (empty($receipts)) {
        echo "   ⚠ No receipts found for fiscal day $fiscalDayNo\n";
        echo "   This might be the issue - ZIMRA expects at least one receipt\n\n";
    } else {
        echo "   Total Receipts Found: " . count($receipts) . "\n\n";
        
        // Check submission status
        $submittedCount = 0;
        $failedCount = 0;
        $pendingCount = 0;
        
        foreach ($receipts as $receipt) {
            $submissionStatus = $receipt['submission_status'] ?? 'Unknown';
            if ($submissionStatus === 'Submitted') {
                $submittedCount++;
            } elseif ($submissionStatus === 'Failed') {
                $failedCount++;
            } else {
                $pendingCount++;
            }
        }
        
        echo "   Submission Status Breakdown:\n";
        echo "   - Submitted: $submittedCount\n";
        echo "   - Failed: $failedCount\n";
        echo "   - Pending/Other: $pendingCount\n\n";
        
        if ($failedCount > 0 || $pendingCount > 0) {
            echo "   ⚠ WARNING: Some receipts are not submitted!\n";
            echo "   Only 'Submitted' receipts are included in counter calculations.\n";
            echo "   This could cause a counters mismatch.\n\n";
        }
        
        // Check receipt chain integrity
        echo "   Receipt Chain Check:\n";
        $expectedCounter = 1;
        $chainBroken = false;
        $missingReceipts = [];
        
        foreach ($receipts as $receipt) {
            $actualCounter = intval($receipt['receipt_counter']);
            if ($actualCounter !== $expectedCounter) {
                echo "   ✗ Chain broken at counter $expectedCounter (found $actualCounter)\n";
                $chainBroken = true;
                // Find missing counters
                for ($i = $expectedCounter; $i < $actualCounter; $i++) {
                    $missingReceipts[] = $i;
                }
            }
            $expectedCounter = $actualCounter + 1;
        }
        
        if (!$chainBroken) {
            echo "   ✓ Receipt chain is intact (counters are sequential)\n";
        } else {
            echo "   ✗ Receipt chain is broken! Missing counters: " . implode(', ', $missingReceipts) . "\n";
            echo "   This will cause 'MissingReceipts' error (Grey receipts)\n";
        }
        echo "\n";
        
        // Check for validation errors
        echo "   Validation Errors Check:\n";
        $hasValidationErrors = false;
        foreach ($receipts as $receipt) {
            $validationErrors = $receipt['validation_errors'] ?? null;
            if ($validationErrors) {
                $errors = json_decode($validationErrors, true);
                if (!empty($errors)) {
                    $hasValidationErrors = true;
                    echo "   ✗ Receipt #{$receipt['receipt_counter']} has validation errors:\n";
                    foreach ($errors as $error) {
                        $code = $error['validationErrorCode'] ?? 'Unknown';
                        $color = $error['validationErrorColor'] ?? 'Unknown';
                        echo "      - $code ($color)\n";
                    }
                }
            }
        }
        
        if (!$hasValidationErrors) {
            echo "   ✓ No validation errors found in submitted receipts\n";
        } else {
            echo "   ✗ Some receipts have validation errors (Red receipts)\n";
            echo "   This will prevent fiscal day from closing\n";
        }
        echo "\n";
    }
    
    // 4. Calculate and display counters
    echo "4. CALCULATING COUNTERS...\n";
    echo "   ----------------------------------------\n";
    
    // fiscal_days is in tenant database
    $fiscalDay = $tenantDb->getRow(
        "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND fiscal_day_no = :fiscal_day_no",
        [':branch_id' => $branchId, ':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
    );
    
    if (!$fiscalDay) {
        echo "   ⚠ Fiscal day record not found in local database\n";
        echo "   Creating temporary record for counter calculation...\n";
        $fiscalDayId = $tenantDb->insert('fiscal_days', [
            'branch_id' => $branchId,
            'device_id' => $deviceId,
            'fiscal_day_no' => $fiscalDayNo,
            'fiscal_day_opened' => date('Y-m-d\TH:i:s'),
            'status' => $fiscalDayStatus,
            'last_receipt_global_no' => $lastReceiptGlobalNo ?? 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        $fiscalDay = $tenantDb->getRow("SELECT * FROM fiscal_days WHERE id = :id", [':id' => $fiscalDayId]);
    }
    
    // Use reflection to access private method
    $reflection = new ReflectionClass($fiscalService);
    $method = $reflection->getMethod('calculateFiscalDayCounters');
    $method->setAccessible(true);
    $counters = $method->invoke($fiscalService, $fiscalDay['id']);
    
    if (empty($counters)) {
        echo "   ⚠ No counters calculated (no receipts or all receipts failed)\n";
    } else {
        echo "   Total Counters: " . count($counters) . "\n\n";
        
        // Group counters by type
        $byType = [];
        foreach ($counters as $counter) {
            $type = $counter['fiscalCounterType'];
            if (!isset($byType[$type])) {
                $byType[$type] = [];
            }
            $byType[$type][] = $counter;
        }
        
        foreach ($byType as $type => $typeCounters) {
            echo "   $type: " . count($typeCounters) . " counter(s)\n";
            foreach ($typeCounters as $counter) {
                $currency = $counter['fiscalCounterCurrency'];
                $value = $counter['fiscalCounterValue'];
                if ($type === 'BalanceByMoneyType') {
                    $moneyType = $counter['fiscalCounterMoneyType'];
                    echo "      - $currency $moneyType: $value\n";
                } else {
                    $taxID = $counter['fiscalCounterTaxID'] ?? 'N/A';
                    $taxPercent = $counter['fiscalCounterTaxPercent'] ?? 'Exempt';
                    echo "      - $currency TaxID:$taxID Tax%:$taxPercent: $value\n";
                }
            }
        }
    }
    echo "\n";
    
    // 5. Recommendations
    echo "5. RECOMMENDATIONS...\n";
    echo "   ----------------------------------------\n";
    
    if ($errorCode) {
        switch ($errorCode) {
            case 'MissingReceipts':
                echo "   ✗ ISSUE: Missing receipts (Grey validation error)\n";
                echo "   SOLUTION:\n";
                echo "   1. Check if all receipts were submitted successfully\n";
                echo "   2. Verify receipt counter sequence is complete (1, 2, 3, ...)\n";
                echo "   3. If receipts are missing, you may need to resubmit them\n";
                echo "   4. Or use ZIMRA portal to close manually: https://fdmsops.zimra.co.zw/fdms-public/close-fiscal-day\n";
                break;
                
            case 'ReceiptsWithValidationErrors':
                echo "   ✗ ISSUE: Receipts with validation errors (Red validation error)\n";
                echo "   SOLUTION:\n";
                echo "   1. Check the validation errors for each receipt above\n";
                echo "   2. Fix the validation errors (usually RCPT025, RCPT026, etc.)\n";
                echo "   3. Resubmit corrected receipts\n";
                echo "   4. Or use ZIMRA portal to close manually\n";
                break;
                
            case 'CountersMismatch':
                echo "   ✗ ISSUE: Counter mismatch\n";
                echo "   SOLUTION:\n";
                echo "   1. Verify counter calculations match ZIMRA's expectations\n";
                echo "   2. Check if all receipts are included in counter calculation\n";
                echo "   3. Verify credit notes are handled correctly (should decrease counters)\n";
                echo "   4. Check if payment methods match exactly\n";
                echo "   5. Or use ZIMRA portal to close manually\n";
                break;
                
            case 'BadCertificateSignature':
                echo "   ✗ ISSUE: Bad certificate signature\n";
                echo "   SOLUTION:\n";
                echo "   1. Verify device certificate is valid and not expired\n";
                echo "   2. Check if private key matches the certificate\n";
                echo "   3. Try reissuing the certificate if needed\n";
                break;
                
            default:
                echo "   ✗ ISSUE: Unknown error code ($errorCode)\n";
                echo "   SOLUTION: Contact ZIMRA support or use their portal to close manually\n";
        }
    } else {
        echo "   ℹ No specific error code found.\n";
        echo "   If close is failing, try:\n";
        echo "   1. Check ZIMRA portal for detailed error: https://fdmsops.zimra.co.zw/fdms-public/close-fiscal-day\n";
        echo "   2. Verify all receipts are submitted\n";
        echo "   3. Check counter calculations match ZIMRA's expectations\n";
    }
    
    echo "\n";
    echo "========================================\n";
    echo "DIAGNOSTIC COMPLETE\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}


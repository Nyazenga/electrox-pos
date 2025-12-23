<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_helper.php';
require_once APP_PATH . '/includes/fiscal_service.php';

echo "=== COMPREHENSIVE FISCALIZATION TEST ===\n\n";

// Step 1: Check branch
$primaryDb = Database::getPrimaryInstance();
$branch = $primaryDb->getRow("SELECT id, branch_name, fiscalization_enabled FROM branches WHERE branch_name LIKE '%HEAD%' OR id = 1 LIMIT 1");

if (!$branch) {
    die("Branch not found\n");
}

$branchId = $branch['id'];
echo "Step 1: Branch Check\n";
echo "  Branch: {$branch['branch_name']} (ID: $branchId)\n";
echo "  Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'YES' : 'NO') . "\n\n";

if (!$branch['fiscalization_enabled']) {
    die("Fiscalization is not enabled for this branch!\n");
}

// Step 2: Check device
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
    [':branch_id' => $branchId]
);

if (!$device) {
    die("No device found for branch $branchId\n");
}

echo "Step 2: Device Check\n";
echo "  Device ID: {$device['device_id']}\n";
echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "  Has Certificate: " . (!empty($device['certificate_pem']) ? 'Yes' : 'No') . "\n\n";

// Step 3: Test FiscalService
echo "Step 3: Testing FiscalService\n";
try {
    $fiscalService = new FiscalService($branchId);
    echo "  ✓ FiscalService initialized\n";
    
    $status = $fiscalService->getFiscalDayStatus();
    echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    
    if ($status['fiscalDayStatus'] !== 'FiscalDayOpened') {
        echo "  Opening fiscal day...\n";
        $result = $fiscalService->openFiscalDay();
        echo "  ✓ Fiscal day opened (Day #{$result['fiscalDayNo']})\n";
    }
} catch (Exception $e) {
    die("  ✗ FiscalService failed: " . $e->getMessage() . "\n");
}

echo "\n";

// Step 4: Check for sales
$db = Database::getInstance();
$sales = $db->getRows("SELECT id, branch_id, total_amount, payment_status, created_at FROM sales WHERE branch_id = :branch_id ORDER BY id DESC LIMIT 5", [':branch_id' => $branchId]);

echo "Step 4: Checking Sales\n";
if (empty($sales)) {
    echo "  ⚠ No sales found for branch $branchId\n";
    echo "  Please make a sale in the POS system first\n";
    exit;
}

echo "  Found " . count($sales) . " sales\n";
foreach ($sales as $sale) {
    echo "    Sale ID: {$sale['id']}, Total: {$sale['total_amount']}, Status: {$sale['payment_status']}\n";
}

// Step 5: Test fiscalization on most recent sale
$sale = $sales[0];
echo "\nStep 5: Testing Fiscalization on Sale {$sale['id']}\n";

// Check if already fiscalized
$fiscalReceipt = $primaryDb->getRow(
    "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
    [':sale_id' => $sale['id']]
);

if ($fiscalReceipt) {
    echo "  ⚠ Sale is already fiscalized\n";
    echo "    Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
    echo "    Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
} else {
    echo "  Attempting to fiscalize...\n";
    try {
        $result = fiscalizeSale($sale['id'], $branchId, $db);
        
        if ($result) {
            echo "  ✓ Fiscalization successful!\n";
            
            // Check fiscal receipt
            $fiscalReceipt = $primaryDb->getRow(
                "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
                [':sale_id' => $sale['id']]
            );
            
            if ($fiscalReceipt) {
                echo "  ✓ Fiscal receipt created:\n";
                echo "    Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
                echo "    Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
                echo "    Has QR Code: " . (!empty($fiscalReceipt['receipt_qr_code']) ? 'Yes' : 'No') . "\n";
            }
        } else {
            echo "  ✗ Fiscalization returned false\n";
        }
    } catch (Exception $e) {
        echo "  ✗ Fiscalization failed: " . $e->getMessage() . "\n";
        echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}

// Step 6: Check SubmittedFileList
echo "\nStep 6: Checking SubmittedFileList from ZIMRA\n";
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$certData = CertificateStorage::loadCertificate($device['device_id']);
if ($certData) {
    $api = new ZimraApi('Server', 'v1', true);
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    
    try {
        $fileList = $api->getSubmittedFileList($device['device_id']);
        echo "  ✓ SubmittedFileList retrieved\n";
        echo "  Total: " . ($fileList['total'] ?? 0) . "\n";
        if (isset($fileList['rows']) && is_array($fileList['rows']) && !empty($fileList['rows'])) {
            echo "  Files found:\n";
            foreach ($fileList['rows'] as $file) {
                echo "    - " . print_r($file, true) . "\n";
            }
        } else {
            echo "  No files submitted to ZIMRA\n";
        }
    } catch (Exception $e) {
        echo "  ✗ SubmittedFileList failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "  ✗ Certificate not found\n";
}

echo "\n=== Test Complete ===\n";


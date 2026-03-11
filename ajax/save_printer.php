<?php
/**
 * Save printer configuration to database
 */

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();

$db = Database::getInstance();
$branchId = intval($_POST['branch_id'] ?? 0);

if (!$branchId) {
    $branchId = $auth->getUser()['branch_id'] ?? 0;
}

if (!$branchId) {
    echo json_encode([
        'success' => false,
        'message' => 'Branch ID is required'
    ]);
    exit;
}

$printerId = intval($_POST['printer_id'] ?? 0);
$printerName = trim($_POST['printer_name'] ?? '');
$connectionMode = $_POST['connection_mode'] ?? 'USB';
$deviceId = trim($_POST['device_id'] ?? '');
$paperSize = $_POST['paper_size'] ?? '80mm';
$printReceipts = intval($_POST['print_receipts'] ?? 1);
$printBills = intval($_POST['print_bills'] ?? 0);
$cashDrawerConnected = intval($_POST['cash_drawer_connected'] ?? 0);
$status = $_POST['status'] ?? 'active';

if (empty($printerName)) {
    echo json_encode([
        'success' => false,
        'message' => 'Printer name is required'
    ]);
    exit;
}

if (empty($deviceId)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please select a device from the device list'
    ]);
    exit;
}

try {
    // Validate connection mode
    if (!in_array($connectionMode, ['USB', 'Network', 'Bluetooth'])) {
        $connectionMode = 'USB';
    }
    
    // Validate paper size
    if (!in_array($paperSize, ['58mm', '80mm'])) {
        $paperSize = '80mm';
    }
    
    // Validate status
    if (!in_array($status, ['active', 'inactive'])) {
        $status = 'active';
    }
    
    // Check if updating existing printer
    if ($printerId > 0) {
        // Verify printer belongs to user's branch
        $existingPrinter = $db->getRow(
            "SELECT * FROM printers WHERE id = :id AND branch_id = :branch_id",
            [':id' => $printerId, ':branch_id' => $branchId]
        );
        
        if (!$existingPrinter) {
            echo json_encode([
                'success' => false,
                'message' => 'Printer not found or you do not have permission to update it'
            ]);
            exit;
        }
        
        // Update printer
        $result = $db->executeQuery(
            "UPDATE printers SET 
                printer_name = :printer_name,
                connection_mode = :connection_mode,
                device_id = :device_id,
                paper_size = :paper_size,
                print_receipts = :print_receipts,
                print_bills = :print_bills,
                cash_drawer_connected = :cash_drawer_connected,
                status = :status
             WHERE id = :id AND branch_id = :branch_id",
            [
                ':id' => $printerId,
                ':branch_id' => $branchId,
                ':printer_name' => $printerName,
                ':connection_mode' => $connectionMode,
                ':device_id' => $deviceId,
                ':paper_size' => $paperSize,
                ':print_receipts' => $printReceipts,
                ':print_bills' => $printBills,
                ':cash_drawer_connected' => $cashDrawerConnected,
                ':status' => $status
            ]
        );
        
        $message = 'Printer updated successfully';
    } else {
        // Insert new printer
        $result = $db->executeQuery(
            "INSERT INTO printers (branch_id, printer_name, connection_mode, device_id, paper_size, print_receipts, print_bills, cash_drawer_connected, status) 
             VALUES (:branch_id, :printer_name, :connection_mode, :device_id, :paper_size, :print_receipts, :print_bills, :cash_drawer_connected, :status)",
            [
                ':branch_id' => $branchId,
                ':printer_name' => $printerName,
                ':connection_mode' => $connectionMode,
                ':device_id' => $deviceId,
                ':paper_size' => $paperSize,
                ':print_receipts' => $printReceipts,
                ':print_bills' => $printBills,
                ':cash_drawer_connected' => $cashDrawerConnected,
                ':status' => $status
            ]
        );
        
        $message = 'Printer saved successfully';
    }
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save printer'
        ]);
    }
} catch (Exception $e) {
    error_log("Error saving printer: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error saving printer: ' . $e->getMessage()
    ]);
}

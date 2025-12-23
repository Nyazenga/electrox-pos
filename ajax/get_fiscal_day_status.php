<?php
/**
 * AJAX endpoint to get fiscal day status for all branches
 * Used for real-time status updates
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/fiscal_service.php';

initSession();
header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $db = Database::getPrimaryInstance();
    
    // Get all branches
    $branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
    
    $statuses = [];
    
    foreach ($branches as $branch) {
        $device = $db->getRow(
            "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
            [':branch_id' => $branch['id']]
        );
        
        $branchStatus = [
            'branch_id' => $branch['id'],
            'branch_name' => $branch['branch_name'],
            'fiscalization_enabled' => (bool)($branch['fiscalization_enabled'] ?? false),
            'has_device' => $device !== null,
            'device_registered' => false,
            'fiscal_day_status' => null,
            'fiscal_day_no' => null,
            'can_open' => false,
            'can_close' => false,
            'error' => null
        ];
        
        if ($device) {
            $branchStatus['device_id'] = $device['device_id'];
            $branchStatus['device_registered'] = (bool)($device['is_registered'] ?? false);
            
            // Get ZIMRA status if device is registered
            if ($device['is_registered'] && !empty($device['certificate_pem'])) {
                try {
                    $fiscalService = new FiscalService($branch['id']);
                    $zimraStatus = $fiscalService->getFiscalDayStatus();
                    
                    if ($zimraStatus && isset($zimraStatus['fiscalDayStatus'])) {
                        $branchStatus['fiscal_day_status'] = $zimraStatus['fiscalDayStatus'];
                        $branchStatus['fiscal_day_no'] = $zimraStatus['lastFiscalDayNo'] ?? null;
                        
                        // Determine if we can open or close
                        $isOpen = ($zimraStatus['fiscalDayStatus'] === 'FiscalDayOpened' || 
                                  $zimraStatus['fiscalDayStatus'] === 'FiscalDayCloseFailed');
                        $isClosed = ($zimraStatus['fiscalDayStatus'] === 'FiscalDayClosed');
                        
                        $branchStatus['can_open'] = $isClosed;
                        $branchStatus['can_close'] = $isOpen;
                    }
                } catch (Exception $e) {
                    $branchStatus['error'] = $e->getMessage();
                }
            }
        }
        
        $statuses[] = $branchStatus;
    }
    
    echo json_encode([
        'success' => true,
        'statuses' => $statuses,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}


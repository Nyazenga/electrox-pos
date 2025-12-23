<?php
/**
 * @OA\Get(
 *     path="/shifts",
 *     tags={"Shifts"},
 *     summary="Get shifts",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=200, description="List of shifts")
 * )
 * @OA\Post(
 *     path="/shifts/start",
 *     tags={"Shifts"},
 *     summary="Start a new shift",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=201, description="Shift started")
 * )
 * @OA\Post(
 *     path="/shifts/{id}/end",
 *     tags={"Shifts"},
 *     summary="End a shift",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=200, description="Shift ended")
 * )
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

if ($method === 'GET') {
    requirePermission('pos.access');
    
    $whereConditions = ["1=1"];
    $params = [];
    
    if ($user['branch_id']) {
        $whereConditions[] = "s.branch_id = :branch_id";
        $params[':branch_id'] = $user['branch_id'];
    } else {
        $whereConditions[] = "(s.branch_id = 0 OR s.branch_id IS NULL)";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    $shifts = $db->getRows("SELECT s.*, b.branch_name, u.first_name, u.last_name
                           FROM shifts s
                           LEFT JOIN branches b ON s.branch_id = b.id
                           LEFT JOIN users u ON s.user_id = u.id
                           WHERE $whereClause
                           ORDER BY s.opened_at DESC
                           LIMIT 50",
                           $params);
    
    if ($shifts === false) {
        $shifts = [];
    }
    
    sendSuccess($shifts);
    
} elseif ($method === 'POST') {
    if (isset($pathParts[count($pathParts) - 1]) && $pathParts[count($pathParts) - 1] === 'start') {
        requirePermission('pos.access');
        
        $input = getRequestBody();
        $startingCash = floatval($input['starting_cash'] ?? 0);
        
        @ensurePOSTables($db);
        
        $db->beginTransaction();
        
        try {
            $branchId = $user['branch_id'] ?? null;
            $userId = $user['id'];
            
            // Check if there's already an open shift
            if ($branchId) {
                $existingShift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
                    ':branch_id' => $branchId,
                    ':user_id' => $userId
                ]);
            } else {
                $existingShift = $db->getRow("SELECT * FROM shifts WHERE (branch_id = 0 OR branch_id IS NULL) AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
                    ':user_id' => $userId
                ]);
            }
            
            if ($existingShift) {
                throw new Exception('There is already an open shift');
            }
            
            // Get last shift number
            if ($branchId) {
                $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE branch_id = :branch_id ORDER BY id DESC LIMIT 1", [':branch_id' => $branchId]);
            } else {
                $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE (branch_id = 0 OR branch_id IS NULL) ORDER BY id DESC LIMIT 1");
            }
            
            $shiftNumber = ($lastShift ? intval($lastShift['shift_number']) : 0) + 1;
            
            $shiftData = [
                'shift_number' => $shiftNumber,
                'branch_id' => $branchId ?? 0,
                'user_id' => $userId,
                'opened_at' => date('Y-m-d H:i:s'),
                'opened_by' => $userId,
                'starting_cash' => $startingCash,
                'expected_cash' => $startingCash,
                'status' => 'open'
            ];
            
            $shiftId = $db->insert('shifts', $shiftData);
            
            if (!$shiftId) {
                throw new Exception('Failed to create shift: ' . $db->getLastError());
            }
            
            $db->commitTransaction();
            
            $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $shiftId]);
            sendSuccess($shift, 'Shift started successfully', 201);
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollbackTransaction();
            }
            sendError('Failed to start shift: ' . $e->getMessage(), 500);
        }
        
    } elseif (isset($pathParts[count($pathParts) - 2]) && $pathParts[count($pathParts) - 2] === 'end') {
        requirePermission('pos.access');
        
        $shiftId = intval($pathParts[count($pathParts) - 1]);
        
        if (!$shiftId) {
            sendError('Shift ID is required', 400);
        }
        
        $input = getRequestBody();
        $actualCash = floatval($input['actual_cash'] ?? 0);
        
        $db->beginTransaction();
        
        try {
            $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id AND status = 'open'", [':id' => $shiftId]);
            
            if (!$shift) {
                throw new Exception('Shift not found or already closed');
            }
            
            $difference = $actualCash - floatval($shift['expected_cash']);
            
            $db->update('shifts', [
                'closed_at' => date('Y-m-d H:i:s'),
                'closed_by' => $user['id'],
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'status' => 'closed',
                'notes' => $input['notes'] ?? null
            ], ['id' => $shiftId]);
            
            $db->commitTransaction();
            
            $closedShift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $shiftId]);
            sendSuccess($closedShift, 'Shift ended successfully');
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollbackTransaction();
            }
            sendError('Failed to end shift: ' . $e->getMessage(), 500);
        }
        
    } else {
        sendError('Invalid endpoint', 400);
    }
} else {
    sendError('Method not allowed', 405);
}

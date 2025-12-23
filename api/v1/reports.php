<?php
/**
 * @OA\Get(
 *     path="/reports/sales-summary",
 *     tags={"Reports"},
 *     summary="Get sales summary report",
 *     security={{"bearerAuth": {}}},
 *     @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
 *     @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
 *     @OA\Response(response=200, description="Sales summary data")
 * )
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();

if ($method === 'GET') {
    requirePermission('reports.view');
    
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    $reportType = $pathParts[count($pathParts) - 1] ?? 'sales-summary';
    
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $branchId = $user['branch_id'];
    
    $whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
    $params = [':start_date' => $startDate, ':end_date' => $endDate];
    
    if ($branchId) {
        $whereConditions[] = "s.branch_id = :branch_id";
        $params[':branch_id'] = $branchId;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    if ($reportType === 'sales-summary') {
        $summary = $db->getRow("SELECT 
            COUNT(DISTINCT s.id) as total_receipts,
            COALESCE(SUM(s.total_amount), 0) as gross_sales,
            COALESCE(SUM(s.discount_amount), 0) as total_discount,
            COALESCE(SUM(s.tax_amount), 0) as total_tax,
            COALESCE(SUM(CASE WHEN s.payment_status = 'refunded' THEN s.total_amount ELSE 0 END), 0) as total_refunds
            FROM sales s
            WHERE $whereClause", $params);
        
        if ($summary === false) {
            $summary = [
                'total_receipts' => 0,
                'gross_sales' => 0,
                'total_discount' => 0,
                'total_tax' => 0,
                'total_refunds' => 0
            ];
        }
        
        $netSales = $summary['gross_sales'] - $summary['total_refunds'] - $summary['total_discount'];
        $summary['net_sales'] = $netSales;
        
        sendSuccess($summary);
    } else {
        sendError('Report type not found', 404);
    }
} else {
    sendError('Method not allowed', 405);
}



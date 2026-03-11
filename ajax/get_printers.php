<?php
/**
 * Get available printers from localhost printer service
 * This endpoint tries to connect to the ESC/POS Thermal Printer Partner service
 * running on localhost to detect available printers
 */

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();

$printers = [];

// Try to connect to localhost printer service
// The ESC/POS Thermal Printer Partner typically runs on port 8080, 3000, 5000, or 9000
$servicePorts = [8080, 3000, 5000, 9000];
$serviceFound = false;

foreach ($servicePorts as $port) {
    $url = "http://localhost:{$port}/api/printers";
    
    // Use cURL to connect to localhost service
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2 second timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode == 200 && !empty($response)) {
        $data = json_decode($response, true);
        if ($data && isset($data['printers']) && is_array($data['printers'])) {
            $printers = $data['printers'];
            $serviceFound = true;
            break;
        }
    }
}

// If service not found, return empty list with helpful message
if (!$serviceFound) {
    echo json_encode([
        'success' => false,
        'message' => 'Printer service not found. Please make sure the Thermal printer setup is running on your desktop.',
        'printers' => [],
        'hint' => 'The ESC/POS Thermal Printer Partner service should be running. Check your desktop for the "Thermal printer setup" shortcut.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'printers' => $printers
]);

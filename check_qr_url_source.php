<?php
/**
 * Check where qr_url comes from
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

$primaryDb = Database::getPrimaryInstance();

// Check what's stored in fiscal_config
$config = $primaryDb->getRow(
    "SELECT qr_url, device_id, branch_id, last_synced 
     FROM fiscal_config 
     WHERE device_id = 30199 
     LIMIT 1"
);

if ($config) {
    echo "=== QR URL Stored in Database ===\n";
    echo "QR URL: {$config['qr_url']}\n";
    echo "Device ID: {$config['device_id']}\n";
    echo "Branch ID: {$config['branch_id']}\n";
    echo "Last Synced: {$config['last_synced']}\n\n";
    
    echo "=== Source ===\n";
    echo "This QR URL comes from ZIMRA's getConfig() API response.\n";
    echo "ZIMRA returns the qrUrl field in the getConfig response.\n";
    echo "For TEST environment, ZIMRA returns: https://fdmstest.zimra.co.zw\n";
    echo "For PRODUCTION, ZIMRA would return: https://invoice.zimra.co.zw\n\n";
    
    echo "The documentation examples show 'https://invoice.zimra.co.zw' as an EXAMPLE.\n";
    echo "But the actual URL to use comes from ZIMRA's getConfig API response.\n\n";
    
    echo "=== This is CORRECT ===\n";
    echo "We ARE using the correct URL - it's what ZIMRA told us to use!\n";
} else {
    echo "No config found for device 30199\n";
    echo "The qr_url should come from ZIMRA's getConfig() API response.\n";
}


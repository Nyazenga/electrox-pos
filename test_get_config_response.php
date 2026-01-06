<?php
/**
 * Test script to call ZIMRA getConfig and display the raw response
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';

header('Content-Type: text/plain; charset=utf-8');

echo "========================================\n";
echo "ZIMRA getConfig - Raw Response\n";
echo "========================================\n\n";

// Get device info - use first active device
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE is_active = 1 LIMIT 1"
);

if (!$device) {
    die("ERROR: No active fiscal device found.\n");
}

$deviceId = $device['device_id'];
$branchId = $device['branch_id'];

echo "Device ID: $deviceId\n";
echo "Branch ID: $branchId\n";
echo "Model: {$device['device_model_name']} v{$device['device_model_version']}\n\n";

// Load certificate
$certData = CertificateStorage::loadCertificate($deviceId);

if (!$certData || !$certData['certificate'] || !$certData['privateKey']) {
    die("ERROR: Certificate not found for device $deviceId\n");
}

echo "Certificate loaded from CertificateStorage\n\n";

// Initialize API
$api = new ZimraApi(
    $device['device_model_name'],
    $device['device_model_version'],
    true // Use test environment
);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

echo "Calling ZIMRA getConfig endpoint...\n\n";

try {
    $response = $api->getConfig($deviceId);
    
    echo "========================================\n";
    echo "SUCCESS - Raw Response from ZIMRA:\n";
    echo "========================================\n\n";
    
    // Pretty print JSON
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    echo "\n\n========================================\n";
    echo "APPLICABLE TAXES DETAIL:\n";
    echo "========================================\n\n";
    
    if (!empty($response['applicableTaxes']) && is_array($response['applicableTaxes'])) {
        foreach ($response['applicableTaxes'] as $index => $tax) {
            echo "Tax #" . ($index + 1) . ":\n";
            echo "  taxID: " . (isset($tax['taxID']) ? $tax['taxID'] : 'NOT PROVIDED') . "\n";
            echo "  taxPercent: " . (isset($tax['taxPercent']) ? $tax['taxPercent'] : 'NOT PROVIDED (exempt)') . "\n";
            echo "  taxName: " . ($tax['taxName'] ?? 'NOT PROVIDED') . "\n";
            if (isset($tax['taxValidFrom'])) {
                echo "  taxValidFrom: " . $tax['taxValidFrom'] . "\n";
            }
            if (isset($tax['taxValidTill'])) {
                echo "  taxValidTill: " . $tax['taxValidTill'] . "\n";
            }
            echo "  Full JSON: " . json_encode($tax, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            echo "\n";
        }
    } else {
        echo "No applicable taxes in response\n";
    }
    
} catch (Exception $e) {
    echo "========================================\n";
    echo "ERROR:\n";
    echo "========================================\n\n";
    echo $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}


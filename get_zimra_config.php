<?php
/**
 * Get ZIMRA Configuration Script
 * 
 * This script calls ZIMRA's getConfig endpoint and saves the raw response to a file.
 * 
 * Usage:
 *   php get_zimra_config.php [branch_id]
 * 
 * If branch_id is not provided, it will use the first active branch with fiscalization enabled.
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "ZIMRA getConfig - Raw Response Export\n";
echo "========================================\n\n";

// Get branch ID from command line or find first active branch
$branchId = null;
if (isset($argv[1])) {
    $branchId = intval($argv[1]);
    echo "Using branch ID from command line: $branchId\n";
} else {
    echo "No branch ID provided. Finding first active branch with fiscalization enabled...\n";
    $primaryDb = Database::getPrimaryInstance();
    $branch = $primaryDb->getRow(
        "SELECT id, name FROM branches WHERE fiscalization_enabled = 1 ORDER BY id LIMIT 1"
    );
    
    if (!$branch) {
        die("✗ ERROR: No branch with fiscalization enabled found.\n");
    }
    
    $branchId = $branch['id'];
    echo "✓ Found branch: {$branch['name']} (ID: $branchId)\n";
}

echo "\n";

// Get device info and call getConfig directly
echo "Getting device information...\n";
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
    [':branch_id' => $branchId]
);

if (!$device) {
    die("✗ ERROR: No active fiscal device found for branch $branchId\n");
}

$deviceId = $device['device_id'];
echo "✓ Device ID: $deviceId\n";
echo "  Device Model: {$device['device_model_name']} v{$device['device_model_version']}\n\n";

// Load certificate
echo "Loading certificate...\n";
require_once APP_PATH . '/includes/certificate_storage.php';
$certData = CertificateStorage::loadCertificate($deviceId);

if (!$certData) {
    die("✗ ERROR: No certificate found for device $deviceId. Please register device first.\n");
}
echo "✓ Certificate loaded\n\n";

// Initialize API and call getConfig
echo "Calling ZIMRA getConfig endpoint...\n";
require_once APP_PATH . '/includes/zimra_api.php';
$api = new ZimraApi(
    $device['device_model_name'],
    $device['device_model_version'],
    true // Use test environment
);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

try {
    $rawResponse = $api->getConfig($deviceId);
    echo "✓ getConfig call successful\n\n";
} catch (Exception $e) {
    die("✗ ERROR: Failed to call getConfig: " . $e->getMessage() . "\n");
}

// Save to file
$outputFile = APP_PATH . '/zimra_config_response_' . date('Y-m-d_H-i-s') . '.json';
$jsonOutput = json_encode($rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

file_put_contents($outputFile, $jsonOutput);

echo "========================================\n";
echo "RESPONSE SAVED\n";
echo "========================================\n";
echo "File: $outputFile\n";
echo "File size: " . number_format(filesize($outputFile)) . " bytes\n\n";

// Display summary
echo "========================================\n";
echo "RESPONSE SUMMARY\n";
echo "========================================\n";
echo "Taxpayer Name: " . ($rawResponse['taxPayerName'] ?? 'N/A') . "\n";
echo "Taxpayer TIN: " . ($rawResponse['taxPayerTIN'] ?? 'N/A') . "\n";
echo "VAT Number: " . ($rawResponse['vatNumber'] ?? 'N/A') . "\n";
echo "Device Operating Mode: " . ($rawResponse['deviceOperatingMode'] ?? 'N/A') . "\n";
echo "QR URL: " . ($rawResponse['qrUrl'] ?? 'N/A') . "\n";
echo "Certificate Valid Till: " . ($rawResponse['certificateValidTill'] ?? 'N/A') . "\n";
echo "\n";

// Display applicable taxes in detail
echo "========================================\n";
echo "APPLICABLE TAXES (RAW FROM ZIMRA)\n";
echo "========================================\n";
if (!empty($rawResponse['applicableTaxes']) && is_array($rawResponse['applicableTaxes'])) {
    echo "Total taxes: " . count($rawResponse['applicableTaxes']) . "\n\n";
    
    foreach ($rawResponse['applicableTaxes'] as $index => $tax) {
        echo "Tax #" . ($index + 1) . ":\n";
        echo "  Raw JSON: " . json_encode($tax, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        echo "  taxID: " . (isset($tax['taxID']) ? $tax['taxID'] : 'NOT PROVIDED') . "\n";
        echo "  taxPercent: " . (isset($tax['taxPercent']) ? $tax['taxPercent'] : 'NOT PROVIDED') . " (type: " . (isset($tax['taxPercent']) ? gettype($tax['taxPercent']) : 'N/A') . ")\n";
        echo "  taxName: " . (isset($tax['taxName']) ? $tax['taxName'] : 'NOT PROVIDED') . "\n";
        if (isset($tax['taxValidFrom'])) {
            echo "  taxValidFrom: " . $tax['taxValidFrom'] . "\n";
        }
        if (isset($tax['taxValidTill'])) {
            echo "  taxValidTill: " . $tax['taxValidTill'] . "\n";
        }
        echo "\n";
    }
} else {
    echo "No applicable taxes found in response.\n\n";
}

// Display full response structure
echo "========================================\n";
echo "FULL RESPONSE STRUCTURE\n";
echo "========================================\n";
echo "Keys in response: " . implode(', ', array_keys($rawResponse)) . "\n\n";

echo "========================================\n";
echo "COMPLETE JSON RESPONSE\n";
echo "========================================\n";
echo $jsonOutput . "\n\n";

echo "========================================\n";
echo "DONE\n";
echo "========================================\n";
echo "Full response saved to: $outputFile\n";
echo "You can open this file to see the complete ZIMRA response.\n";


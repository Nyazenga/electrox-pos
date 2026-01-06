<?php
/**
 * Display the latest ZIMRA getConfig response
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "========================================\n";
echo "ZIMRA getConfig - Raw Response\n";
echo "========================================\n\n";

// Find the most recent config response file
$files = glob(__DIR__ . '/zimra_config_response_*.json');
if (empty($files)) {
    die("No config response file found. Please run get_zimra_config.php first.\n");
}

// Sort by modification time, get the most recent
usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$latestFile = $files[0];
$response = json_decode(file_get_contents($latestFile), true);

echo "Response file: " . basename($latestFile) . "\n";
echo "Generated: " . date('Y-m-d H:i:s', filemtime($latestFile)) . "\n\n";

echo "========================================\n";
echo "COMPLETE RESPONSE:\n";
echo "========================================\n\n";
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "\n\n========================================\n";
echo "APPLICABLE TAXES DETAIL:\n";
echo "========================================\n\n";

if (!empty($response['applicableTaxes']) && is_array($response['applicableTaxes'])) {
    echo "Total taxes: " . count($response['applicableTaxes']) . "\n\n";
    
    foreach ($response['applicableTaxes'] as $index => $tax) {
        echo "Tax #" . ($index + 1) . ":\n";
        echo "  taxID: " . (isset($tax['taxID']) ? $tax['taxID'] : 'NOT PROVIDED') . "\n";
        echo "  taxPercent: " . (isset($tax['taxPercent']) ? $tax['taxPercent'] : 'NOT PROVIDED (exempt)') . "\n";
        echo "  taxName: " . ($tax['taxName'] ?? 'NOT PROVIDED') . "\n";
        echo "  taxCode: " . (isset($tax['taxCode']) ? $tax['taxCode'] : 'NOT PROVIDED') . "\n";
        if (isset($tax['validFrom'])) {
            echo "  validFrom: " . $tax['validFrom'] . "\n";
        }
        if (isset($tax['validTill'])) {
            echo "  validTill: " . $tax['validTill'] . "\n";
        }
        echo "  Raw JSON: " . json_encode($tax, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        echo "\n";
    }
} else {
    echo "No applicable taxes in response\n";
}


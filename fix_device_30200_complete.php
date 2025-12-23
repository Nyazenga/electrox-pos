<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$primaryDb = Database::getPrimaryInstance();

echo "=== Fixing Device 30200 Setup ===\n\n";

// Step 1: Ensure device record exists
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = 30200"
);

if (!$device) {
    echo "Creating device record for 30200...\n";
    $branch = $primaryDb->getRow("SELECT id FROM branches LIMIT 1");
    if (!$branch) {
        die("✗ No branches found\n");
    }
    
    $deviceId = $primaryDb->insert('fiscal_devices', [
        'branch_id' => $branch['id'],
        'device_id' => 30200,
        'device_serial_no' => 'electrox-2',
        'activation_key' => '00294543',
        'device_model_name' => 'Server',
        'device_model_version' => 'v1',
        'is_active' => 1,
        'is_registered' => 1
    ]);
    
    if (!$deviceId) {
        die("✗ Failed to create device record: " . $primaryDb->getLastError() . "\n");
    }
    echo "✓ Device record created (ID: $deviceId)\n\n";
} else {
    echo "✓ Device record exists (ID: {$device['id']})\n";
    // Update it
    $primaryDb->update('fiscal_devices', [
        'device_id' => 30200,
        'device_serial_no' => 'electrox-2',
        'activation_key' => '00294543',
        'is_registered' => 1
    ], ['id' => $device['id']]);
    echo "✓ Device record updated\n\n";
}

// Step 2: Restore certificate from file
echo "Restoring certificate from file...\n";
$certFile = __DIR__ . "/certificate_30200.pem";
$keyFile = __DIR__ . "/private_key_30200.pem";

if (!file_exists($certFile) || !file_exists($keyFile)) {
    die("✗ Certificate files not found\n");
}

$certificate = file_get_contents($certFile);
$privateKey = file_get_contents($keyFile);

echo "  Certificate: " . strlen($certificate) . " bytes\n";
echo "  Private Key: " . strlen($privateKey) . " bytes\n";

// Verify
$cert = openssl_x509_read($certificate);
if (!$cert) {
    die("✗ Invalid certificate\n");
}
$certInfo = openssl_x509_parse($cert);
$cn = $certInfo['subject']['CN'] ?? '';
echo "  Certificate CN: $cn\n";

if (strpos($cn, '30200') === false) {
    die("✗ Certificate is not for device 30200\n");
}

$key = openssl_pkey_get_private($privateKey);
if (!$key) {
    die("✗ Invalid private key\n");
}
openssl_free_key($key);
openssl_x509_free($cert);

echo "✓ Certificate and key are valid\n\n";

// Step 3: Save certificate
echo "Saving certificate to database...\n";
try {
    CertificateStorage::saveCertificate(30200, $certificate, $privateKey);
    echo "✓ Certificate saved\n\n";
} catch (Exception $e) {
    die("✗ Failed to save: " . $e->getMessage() . "\n");
}

// Step 4: Verify
$savedCert = CertificateStorage::loadCertificate(30200);
if (!$savedCert) {
    die("✗ Failed to load saved certificate\n");
}

echo "✓ Certificate verified in database\n";
echo "  Certificate: " . strlen($savedCert['certificate']) . " bytes\n";
echo "  Private Key: " . strlen($savedCert['privateKey']) . " bytes\n\n";

// Step 5: Update all branches to use device 30200
echo "Updating all branches to use device 30200...\n";
$devices = $primaryDb->getRows("SELECT * FROM fiscal_devices");
foreach ($devices as $dev) {
    $primaryDb->update('fiscal_devices', [
        'device_id' => 30200,
        'device_serial_no' => 'electrox-2',
        'activation_key' => '00294543',
        'is_registered' => 1
    ], ['id' => $dev['id']]);
}

$configs = $primaryDb->getRows("SELECT * FROM fiscal_config");
foreach ($configs as $config) {
    $primaryDb->update('fiscal_config', [
        'device_id' => 30200
    ], ['id' => $config['id']]);
}
echo "✓ All branches and configs updated\n\n";

// Step 6: Test authentication
echo "Testing authentication...\n";
require_once APP_PATH . '/includes/zimra_api.php';

$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($savedCert['certificate'], $savedCert['privateKey']);

try {
    $status = $api->getStatus(30200);
    echo "✓ Authentication successful!\n";
    echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    
    if (isset($status['lastFiscalDayNo'])) {
        echo "  Last Fiscal Day No: " . $status['lastFiscalDayNo'] . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ Authentication failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Device 30200 is Ready! ===\n";


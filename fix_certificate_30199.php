<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

// Find device record with certificate (might be under 30200 or 30199)
$deviceWithCert = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE certificate_pem IS NOT NULL AND certificate_pem != '' LIMIT 1"
);

if (!$deviceWithCert) {
    echo "✗ No device with certificate found\n";
    exit(1);
}

echo "Found certificate in device record ID {$deviceWithCert['id']} (device_id: {$deviceWithCert['device_id']})\n";
echo "Certificate length: " . strlen($deviceWithCert['certificate_pem']) . " bytes\n";
echo "Private key encrypted: " . (!empty($deviceWithCert['private_key_encrypted']) ? 'Yes' : 'No') . "\n\n";

// Now update device 30199 record with this certificate
$device30199 = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = 30199 LIMIT 1"
);

if (!$device30199) {
    echo "✗ Device 30199 record not found\n";
    exit(1);
}

echo "Updating device 30199 record (ID: {$device30199['id']})...\n";

$updateData = [
    'certificate_pem' => $deviceWithCert['certificate_pem']
];

// Copy private key if available
if (!empty($deviceWithCert['private_key_encrypted'])) {
    $updateData['private_key_encrypted'] = $deviceWithCert['private_key_encrypted'];
} elseif (!empty($deviceWithCert['private_key_pem'])) {
    $updateData['private_key_pem'] = $deviceWithCert['private_key_pem'];
}

$primaryDb->update('fiscal_devices', $updateData, ['id' => $device30199['id']]);
echo "✓ Certificate copied to device 30199\n";

// Verify
$verify = $primaryDb->getRow(
    "SELECT certificate_pem FROM fiscal_devices WHERE device_id = 30199"
);
if ($verify && !empty($verify['certificate_pem'])) {
    echo "✓ Verified: Device 30199 now has certificate (" . strlen($verify['certificate_pem']) . " bytes)\n";
} else {
    echo "✗ Verification failed\n";
}


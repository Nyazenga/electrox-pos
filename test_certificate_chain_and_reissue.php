<?php
/**
 * Test Certificate Chain and Attempt Re-issue
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "CERTIFICATE CHAIN VERIFICATION & RE-ISSUE TEST\n";
echo "========================================\n\n";

$deviceId = 30199;
$deviceSerialNo = 'electrox-1';
$activationKey = '00544726';

$primaryDb = Database::getPrimaryInstance();

// STEP 1: Get Server Certificate
echo "STEP 1: Getting ZIMRA Server Certificate\n";
echo str_repeat("-", 50) . "\n";
$api = new ZimraApi('Server', 'v1', true);

try {
    $serverCert = $api->getServerCertificate();
    echo "✓ Server certificate retrieved\n";
    if (isset($serverCert['certificate']) && is_array($serverCert['certificate'])) {
        echo "  Certificate chain length: " . count($serverCert['certificate']) . "\n";
        foreach ($serverCert['certificate'] as $idx => $cert) {
            $certObj = openssl_x509_read($cert);
            if ($certObj) {
                $details = openssl_x509_parse($certObj);
                echo "  Certificate " . ($idx + 1) . ": " . ($details['subject']['CN'] ?? 'N/A') . "\n";
            }
        }
    }
    echo "  Valid Till: " . ($serverCert['certificateValidTill'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}
echo "\n";

// STEP 2: Load Current Certificate
echo "STEP 2: Loading Current Device Certificate\n";
echo str_repeat("-", 50) . "\n";
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    $certFile = "certificate_$deviceId.pem";
    $keyFile = "private_key_$deviceId.pem";
    
    if (file_exists($certFile) && file_exists($keyFile)) {
        $certData = [
            'certificate' => file_get_contents($certFile),
            'privateKey' => file_get_contents($keyFile)
        ];
        CertificateStorage::saveCertificate($deviceId, $certData['certificate'], $certData['privateKey']);
        echo "✓ Certificate loaded from file\n";
    } else {
        die("✗ No certificate found\n");
    }
} else {
    echo "✓ Certificate loaded from database\n";
}

$cert = openssl_x509_read($certData['certificate']);
$details = openssl_x509_parse($cert);
echo "  Subject: " . ($details['subject']['CN'] ?? 'N/A') . "\n";
echo "  Issuer: " . ($details['issuer']['CN'] ?? 'N/A') . "\n";
echo "\n";

// STEP 3: Verify Certificate Chain
echo "STEP 3: Verifying Certificate Chain\n";
echo str_repeat("-", 50) . "\n";
// Check if our certificate's issuer matches server certificate
$ourIssuer = $details['issuer'];
echo "  Our Certificate Issuer:\n";
foreach ($ourIssuer as $key => $value) {
    echo "    $key: $value\n";
}

if (isset($serverCert['certificate']) && is_array($serverCert['certificate'])) {
    $serverCertObj = openssl_x509_read($serverCert['certificate'][0]);
    if ($serverCertObj) {
        $serverDetails = openssl_x509_parse($serverCertObj);
        echo "\n  Server Certificate Subject:\n";
        foreach ($serverDetails['subject'] as $key => $value) {
            echo "    $key: $value\n";
        }
        
        // Check if issuer matches
        $issuerMatches = true;
        foreach ($ourIssuer as $key => $value) {
            if (!isset($serverDetails['subject'][$key]) || $serverDetails['subject'][$key] !== $value) {
                $issuerMatches = false;
                break;
            }
        }
        
        if ($issuerMatches) {
            echo "\n  ✓ Certificate issuer appears to match server certificate\n";
        } else {
            echo "\n  ⚠ Certificate issuer may not match server certificate\n";
        }
    }
}
echo "\n";

// STEP 4: Try to Re-register Device (May Fail)
echo "STEP 4: Attempting Device Re-registration\n";
echo str_repeat("-", 50) . "\n";
echo "⚠ This will likely fail if device is already registered\n";
echo "Generating new CSR...\n";

$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
echo "✓ CSR generated\n";
echo "  CSR Subject CN: ZIMRA-$deviceSerialNo-" . str_pad($deviceId, 10, '0', STR_PAD_LEFT) . "\n";

try {
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    if (isset($result['certificate'])) {
        echo "✓✓✓ NEW CERTIFICATE RECEIVED! ✓✓✓\n";
        echo "  Certificate length: " . strlen($result['certificate']) . " bytes\n";
        
        // Save new certificate
        CertificateStorage::saveCertificate($deviceId, $result['certificate'], $csrData['privateKey']);
        echo "✓ New certificate saved to database\n";
        
        // Test immediately
        echo "\nTesting new certificate...\n";
        $api2 = new ZimraApi('Server', 'v1', true);
        $api2->setCertificate($result['certificate'], $csrData['privateKey']);
        
        try {
            $status = $api2->getStatus($deviceId);
            echo "✓✓✓ AUTHENTICATION SUCCESSFUL WITH NEW CERTIFICATE! ✓✓✓\n";
            echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
        } catch (Exception $e) {
            echo "✗ Still failing: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    echo "✗ Registration failed: $errorMsg\n";
    
    if (strpos($errorMsg, 'DEV02') !== false) {
        echo "\n⚠ Device already registered. Cannot re-register.\n";
        echo "⚠ Current certificate may be revoked or invalid.\n";
        echo "\nRECOMMENDATION: Contact ZIMRA to:\n";
        echo "  1. Verify certificate status for device ID $deviceId\n";
        echo "  2. Re-issue certificate if needed\n";
        echo "  3. Or reset device registration\n";
    }
}
echo "\n";

// STEP 5: Try issueCertificate (Requires Current Certificate)
echo "STEP 5: Attempting Certificate Re-issue\n";
echo str_repeat("-", 50) . "\n";
echo "⚠ This requires current certificate to be valid\n";

$api3 = new ZimraApi('Server', 'v1', true);
$api3->setCertificate($certData['certificate'], $certData['privateKey']);

// Generate new CSR for re-issue
$csrData2 = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
echo "✓ New CSR generated for re-issue\n";

try {
    $result = $api3->issueCertificate($deviceId, $csrData2['csr']);
    if (isset($result['certificate'])) {
        echo "✓✓✓ NEW CERTIFICATE ISSUED! ✓✓✓\n";
        echo "  Certificate length: " . strlen($result['certificate']) . " bytes\n";
        
        // Save new certificate
        CertificateStorage::saveCertificate($deviceId, $result['certificate'], $csrData2['privateKey']);
        echo "✓ New certificate saved to database\n";
        
        // Test immediately
        echo "\nTesting newly issued certificate...\n";
        $api4 = new ZimraApi('Server', 'v1', true);
        $api4->setCertificate($result['certificate'], $csrData2['privateKey']);
        
        try {
            $status = $api4->getStatus($deviceId);
            echo "✓✓✓ AUTHENTICATION SUCCESSFUL WITH RE-ISSUED CERTIFICATE! ✓✓✓\n";
            echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
        } catch (Exception $e) {
            echo "✗ Still failing: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "✗ Re-issue failed: " . $e->getMessage() . "\n";
    echo "⚠ Current certificate is not valid for authentication\n";
    echo "⚠ Cannot re-issue without valid certificate\n";
}

echo "\n========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";


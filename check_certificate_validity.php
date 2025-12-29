<?php
/**
 * Check Certificate Validity for Fiscal Device
 * Diagnoses certificate issues that cause BadCertificateSignature errors
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$branchParam = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : null;
$branchName = isset($_GET['branch_name']) ? trim($_GET['branch_name']) : null;

echo "========================================\n";
echo "CERTIFICATE VALIDITY CHECKER\n";
echo "========================================\n\n";

try {
    $db = Database::getPrimaryInstance();
    $branchId = null;
    $branch = null;
    
    // Find branch - allow branch_id to be either numeric ID or branch name
    if ($branchParam) {
        if (is_numeric($branchParam)) {
            $branchId = intval($branchParam);
            $branch = $db->getRow("SELECT id, branch_name FROM branches WHERE id = :id", [':id' => $branchId]);
        } else {
            $branchName = $branchParam;
        }
    }
    
    if ($branchName && !$branch) {
        // Look up branch by name
        $branch = $db->getRow(
            "SELECT id, branch_name FROM branches WHERE branch_name = :name OR branch_code = :name",
            [':name' => $branchName]
        );
        if ($branch) {
            $branchId = $branch['id'];
        }
    }
    
    if (!$branchId || !$branch) {
        echo "Error: Branch not found.\n\n";
        // Show available branches
        $branches = $db->getRows("SELECT id, branch_name FROM branches ORDER BY branch_name");
        if (!empty($branches)) {
            echo "Available branches:\n";
            foreach ($branches as $b) {
                echo "  - {$b['branch_name']} (ID: {$b['id']})\n";
            }
            echo "\nUsage: check_certificate_validity.php?branch_id={id} OR ?branch_name={name}\n";
        }
        exit(1);
    }
    
    echo "Branch: {$branch['branch_name']} (ID: $branchId)\n\n";
    
    // Get device
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
        [':branch_id' => $branchId]
    );
    
    if (!$device) {
        die("✗ No fiscal device found for this branch\n");
    }
    
    $deviceId = $device['device_id'];
    echo "Device ID: $deviceId\n";
    echo "Device Serial: " . ($device['device_serial_no'] ?? 'N/A') . "\n";
    echo "Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n\n";
    
    // Check certificate from CertificateStorage
    echo "1. CHECKING CERTIFICATE FROM CERTIFICATE STORAGE...\n";
    echo "   ----------------------------------------\n";
    $certData = CertificateStorage::loadCertificate($deviceId);
    
    if ($certData) {
        echo "   ✓ Certificate found in CertificateStorage\n";
        $certPem = $certData['certificate'];
        $privateKeyPem = $certData['privateKey'];
    } else {
        echo "   ✗ Certificate not found in CertificateStorage\n";
        echo "   Trying fallback from device record...\n";
        $certPem = $device['certificate_pem'] ?? null;
        $privateKeyPem = $device['private_key_pem'] ?? null;
        
        if ($privateKeyPem && strpos($privateKeyPem, '-----BEGIN') === false) {
            echo "   Attempting to decrypt private key...\n";
            try {
                $privateKeyPem = CertificateStorage::decryptPrivateKey($privateKeyPem);
                echo "   ✓ Private key decrypted\n";
            } catch (Exception $e) {
                echo "   ✗ Failed to decrypt private key: " . $e->getMessage() . "\n";
            }
        }
    }
    
    if (!$certPem || !$privateKeyPem) {
        die("\n✗ ERROR: Certificate or private key is missing!\n");
    }
    
    // 2. Validate certificate
    echo "\n2. VALIDATING CERTIFICATE...\n";
    echo "   ----------------------------------------\n";
    
    $cert = openssl_x509_read($certPem);
    if (!$cert) {
        die("   ✗ ERROR: Invalid certificate format: " . openssl_error_string() . "\n");
    }
    
    $certInfo = openssl_x509_parse($cert);
    $validFrom = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
    $validTo = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
    $now = time();
    $isExpired = $now > $certInfo['validTo_time_t'];
    $isNotYetValid = $now < $certInfo['validFrom_time_t'];
    
    echo "   Subject: " . ($certInfo['name'] ?? 'N/A') . "\n";
    echo "   Issuer: " . ($certInfo['issuer']['CN'] ?? 'N/A') . "\n";
    echo "   Valid From: $validFrom\n";
    echo "   Valid To: $validTo\n";
    
    if ($isExpired) {
        echo "   ✗ CERTIFICATE IS EXPIRED!\n";
        echo "   SOLUTION: Re-issue certificate using Settings → Fiscalization\n";
    } elseif ($isNotYetValid) {
        echo "   ✗ CERTIFICATE IS NOT YET VALID!\n";
    } else {
        $daysLeft = floor(($certInfo['validTo_time_t'] - $now) / 86400);
        echo "   ✓ Certificate is valid (expires in $daysLeft days)\n";
    }
    
    // 3. Validate private key
    echo "\n3. VALIDATING PRIVATE KEY...\n";
    echo "   ----------------------------------------\n";
    
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if (!$privateKey) {
        die("   ✗ ERROR: Invalid private key: " . openssl_error_string() . "\n");
    }
    
    $keyDetails = openssl_pkey_get_details($privateKey);
    $keyType = $keyDetails['type'] === OPENSSL_KEYTYPE_RSA ? 'RSA' : ($keyDetails['type'] === OPENSSL_KEYTYPE_EC ? 'ECC' : 'UNKNOWN');
    echo "   ✓ Private key is valid\n";
    echo "   Key Type: $keyType";
    if ($keyType === 'RSA') {
        echo " (" . ($keyDetails['bits'] ?? 'unknown') . " bits)";
    } elseif ($keyType === 'ECC') {
        echo " (curve: " . ($keyDetails['ec']['curve_name'] ?? 'unknown') . ")";
    }
    echo "\n";
    
    // 4. Verify certificate matches private key
    echo "\n4. VERIFYING CERTIFICATE/PRIVATE KEY MATCH...\n";
    echo "   ----------------------------------------\n";
    
    $certPubKey = openssl_pkey_get_public($certPem);
    if (!$certPubKey) {
        die("   ✗ ERROR: Could not extract public key from certificate: " . openssl_error_string() . "\n");
    }
    
    $certDetails = openssl_pkey_get_details($certPubKey);
    
    if (isset($keyDetails['key']) && isset($certDetails['key'])) {
        if ($keyDetails['key'] === $certDetails['key']) {
            echo "   ✓ Certificate and private key MATCH\n";
        } else {
            echo "   ✗ ERROR: Certificate and private key DO NOT MATCH!\n";
            echo "   This is the likely cause of BadCertificateSignature error.\n";
            echo "   SOLUTION: Re-register the device with ZIMRA\n";
        }
    } else {
        echo "   ⚠ Could not compare keys (different key types or formats)\n";
    }
    
    // 5. Test signature generation
    echo "\n5. TESTING SIGNATURE GENERATION...\n";
    echo "   ----------------------------------------\n";
    
    $testString = "TEST_SIGNATURE_STRING_" . time();
    $testHash = hash('sha256', $testString, true);
    $testSignature = '';
    $signSuccess = openssl_sign($testString, $testSignature, $privateKey, OPENSSL_ALGO_SHA256);
    
    if ($signSuccess) {
        echo "   ✓ Signature generation works\n";
        
        // Verify signature
        $verifyResult = openssl_verify($testString, $testSignature, $certPubKey, OPENSSL_ALGO_SHA256);
        if ($verifyResult === 1) {
            echo "   ✓ Signature verification works (signature can be verified with certificate)\n";
        } elseif ($verifyResult === 0) {
            echo "   ✗ Signature verification FAILED (signature cannot be verified with certificate)\n";
            echo "   This confirms certificate and private key do not match!\n";
        } else {
            echo "   ⚠ Signature verification error: " . openssl_error_string() . "\n";
        }
    } else {
        echo "   ✗ Signature generation FAILED: " . openssl_error_string() . "\n";
    }
    
    // 6. Recommendations
    echo "\n6. RECOMMENDATIONS...\n";
    echo "   ----------------------------------------\n";
    
    if ($isExpired) {
        echo "   ✗ ISSUE: Certificate is expired\n";
        echo "   ACTION: Re-issue certificate:\n";
        echo "   1. Go to Settings → Fiscalization (ZIMRA)\n";
        echo "   2. Click 'Re-issue Certificate'\n";
        echo "   3. Save the new certificate\n";
    } elseif (isset($keyDetails['key']) && isset($certDetails['key']) && $keyDetails['key'] !== $certDetails['key']) {
        echo "   ✗ ISSUE: Certificate and private key do not match\n";
        echo "   ACTION: Re-register device:\n";
        echo "   1. Go to Settings → Fiscalization (ZIMRA)\n";
        echo "   2. Click 'Register Device' (this will re-register with ZIMRA)\n";
        echo "   3. This will get a new certificate that matches your private key\n";
    } else {
        echo "   ℹ Certificate and private key appear to be valid\n";
        echo "   If you're still getting BadCertificateSignature:\n";
        echo "   1. The certificate on ZIMRA's side might be different\n";
        echo "   2. Try re-registering the device to sync certificates\n";
        echo "   3. Check ZIMRA portal to see which certificate they have\n";
    }
    
    echo "\n========================================\n";
    echo "CHECK COMPLETE\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}


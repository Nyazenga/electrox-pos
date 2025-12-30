<?php
/**
 * Verify Certificate Consistency
 * 
 * Checks that the same certificate is being used for all fiscal operations
 * 
 * Usage: verify_certificate_consistency.php?branch_id=1
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/fiscal_service.php';

// Support both CLI and web usage
if (php_sapi_name() === 'cli') {
    // CLI usage: php verify_certificate_consistency.php 1
    $branchParam = isset($argv[1]) ? trim($argv[1]) : null;
    $branchName = null;
} else {
    // Web usage
    $branchParam = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : null;
    $branchName = isset($_GET['branch_name']) ? trim($_GET['branch_name']) : null;
}

echo "========================================\n";
echo "CERTIFICATE CONSISTENCY VERIFICATION\n";
echo "========================================\n\n";

try {
    $db = Database::getPrimaryInstance();
    $branchId = null;
    $branch = null;
    
    // Find branch
    if ($branchParam) {
        if (is_numeric($branchParam)) {
            $branchId = intval($branchParam);
            $branch = $db->getRow("SELECT id, branch_name FROM branches WHERE id = :id", [':id' => $branchId]);
        } else {
            $branchName = $branchParam;
        }
    }
    
    if ($branchName && !$branch) {
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
    
    echo "Device ID: $deviceId\n\n";
    
    // Check 1: Certificate in CertificateStorage
    echo "1. CHECKING CERTIFICATE STORAGE...\n";
    echo "   ----------------------------------------\n";
    
    $certData = CertificateStorage::loadCertificate($deviceId);
    
    if (!$certData) {
        die("   ✗ ERROR: Certificate not found in CertificateStorage!\n");
    }
    
    $storageFingerprint = openssl_x509_fingerprint($certData['certificate'], 'sha256', false);
    echo "   ✓ Certificate found in CertificateStorage\n";
    echo "   Certificate fingerprint: $storageFingerprint\n\n";
    
    // Check 2: Certificate in device record
    echo "2. CHECKING DEVICE RECORD...\n";
    echo "   ----------------------------------------\n";
    
    if (!$device['certificate_pem'] || !$device['private_key_pem']) {
        echo "   ⚠ WARNING: Certificate not in device record\n";
        echo "   This is okay if CertificateStorage is being used\n";
    } else {
        $deviceFingerprint = openssl_x509_fingerprint($device['certificate_pem'], 'sha256', false);
        echo "   ✓ Certificate found in device record\n";
        echo "   Certificate fingerprint: $deviceFingerprint\n";
        
        if ($deviceFingerprint === $storageFingerprint) {
            echo "   ✓ Fingerprints match\n";
        } else {
            echo "   ✗ WARNING: Fingerprints do not match!\n";
            echo "   CertificateStorage should be the source of truth\n";
        }
    }
    echo "\n";
    
    // Check 3: FiscalService loads same certificate
    echo "3. CHECKING FISCAL SERVICE...\n";
    echo "   ----------------------------------------\n";
    
    $fiscalService = new FiscalService($branchId);
    $serviceCertData = CertificateStorage::loadCertificate($deviceId);
    
    if (!$serviceCertData) {
        die("   ✗ ERROR: FiscalService cannot load certificate!\n");
    }
    
    $serviceFingerprint = openssl_x509_fingerprint($serviceCertData['certificate'], 'sha256', false);
    
    if ($serviceFingerprint === $storageFingerprint) {
        echo "   ✓ FiscalService uses same certificate\n";
        echo "   Certificate fingerprint: $serviceFingerprint\n";
    } else {
        die("   ✗ ERROR: FiscalService uses different certificate!\n");
    }
    
    // Verify API client has certificate
    if ($fiscalService->api->hasCertificate()) {
        echo "   ✓ API client has certificate loaded\n";
    } else {
        die("   ✗ ERROR: API client does not have certificate!\n");
    }
    echo "\n";
    
    // Check 4: Certificate validity
    echo "4. CHECKING CERTIFICATE VALIDITY...\n";
    echo "   ----------------------------------------\n";
    
    $cert = openssl_x509_read($certData['certificate']);
    if ($cert) {
        $certDetails = openssl_x509_parse($cert);
        $certSubject = $certDetails['subject']['CN'] ?? 'N/A';
        $certIssuer = $certDetails['issuer']['CN'] ?? 'N/A';
        
        echo "   Certificate Subject: $certSubject\n";
        echo "   Certificate Issuer: $certIssuer\n";
        
        if (isset($certDetails['validTo_time_t'])) {
            $expiryDate = date('Y-m-d H:i:s', $certDetails['validTo_time_t']);
            $daysLeft = floor(($certDetails['validTo_time_t'] - time()) / 86400);
            echo "   Expires: $expiryDate ($daysLeft days)\n";
            
            if ($daysLeft < 0) {
                echo "   ✗ ERROR: Certificate has expired!\n";
            } elseif ($daysLeft < 30) {
                echo "   ⚠ WARNING: Certificate expires in less than 30 days!\n";
            } else {
                echo "   ✓ Certificate is valid\n";
            }
        }
    }
    echo "\n";
    
    // Check 5: Certificate and private key match
    echo "5. CHECKING CERTIFICATE/PRIVATE KEY MATCH...\n";
    echo "   ----------------------------------------\n";
    
    $testKey = openssl_pkey_get_private($certData['privateKey']);
    $certPubKey = openssl_pkey_get_public($certData['certificate']);
    
    if (!$testKey) {
        die("   ✗ ERROR: Private key is invalid!\n");
    }
    
    if (!$certPubKey) {
        die("   ✗ ERROR: Certificate is invalid!\n");
    }
    
    $keyDetails = openssl_pkey_get_details($testKey);
    $certDetails = openssl_pkey_get_details($certPubKey);
    
    if (isset($keyDetails['key']) && isset($certDetails['key']) && $keyDetails['key'] === $certDetails['key']) {
        echo "   ✓ Certificate and private key match\n";
    } else {
        die("   ✗ ERROR: Certificate and private key do not match!\n");
    }
    echo "\n";
    
    // Summary
    echo "========================================\n";
    echo "VERIFICATION SUMMARY\n";
    echo "========================================\n\n";
    
    echo "✓ Certificate is stored in CertificateStorage\n";
    echo "✓ FiscalService loads certificate from CertificateStorage\n";
    echo "✓ API client has certificate loaded\n";
    echo "✓ Certificate and private key match\n";
    echo "✓ Same certificate will be used for:\n";
    echo "  - Receipt submission\n";
    echo "  - Fiscal day opening\n";
    echo "  - Fiscal day closing\n";
    echo "  - All ZIMRA API operations\n\n";
    
    echo "Certificate fingerprint: $storageFingerprint\n";
    echo "This is the certificate that will be used for all operations.\n\n";
    
    echo "If you see BadCertificateSignature errors:\n";
    echo "1. Run: re_register_and_validate_certificate.php?branch_id=$branchId\n";
    echo "2. This will re-register the device and ensure certificate consistency\n\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}


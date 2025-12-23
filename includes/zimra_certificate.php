<?php
/**
 * ZIMRA Certificate Management
 * Handles CSR generation and certificate management
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

class ZimraCertificate {
    /**
     * Generate Certificate Signing Request (CSR)
     * Supports both ECC and RSA algorithms
     * 
     * @param string $deviceSerialNo Device serial number
     * @param int $deviceID Device ID
     * @param string $algorithm 'ECC' or 'RSA' (default: ECC)
     * @return array ['csr' => string, 'privateKey' => string]
     */
    public static function generateCSR($deviceSerialNo, $deviceID, $algorithm = 'ECC') {
        // Format device name as per ZIMRA spec: ZIMRA-<SerialNo>-<zero_padded_10_digit_deviceId>
        // Example: "ZIMRA-SN: 001-0000000187" or "ZIMRA-electrox-1-00000030199"
        $deviceName = 'ZIMRA-' . $deviceSerialNo . '-' . str_pad($deviceID, 10, '0', STR_PAD_LEFT);
        
        // CSR Subject fields as per ZIMRA documentation section 4.2
        // C = ZW, O = Zimbabwe Revenue Authority, S = Zimbabwe (mandatory if provided)
        // Note: Use 'ST' instead of 'S' as OpenSSL uses 'ST' for State
        $dn = [
            'CN' => $deviceName,
            'C' => 'ZW',
            'O' => 'Zimbabwe Revenue Authority',
            'ST' => 'Zimbabwe'  // Use 'ST' not 'S' - OpenSSL uses ST for State
        ];
        
        $opensslConfig = self::getOpenSSLConfig();
        
        // CSR config - ensure we don't use default values
        $csrConfig = array_merge([
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_req',
            'req_extensions' => 'v3_req'
        ], $opensslConfig);
        
        if ($algorithm === 'ECC') {
            // ECC ECDSA on SECG secp256r1 (prime256v1, NIST P-256)
            $config = array_merge([
                'private_key_bits' => 256,
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1'
            ], $opensslConfig);
            
            $privateKey = @openssl_pkey_new($config);
            if (!$privateKey) {
                $error = openssl_error_string();
                // Try RSA if ECC fails
                $config = array_merge([
                    'private_key_bits' => 2048,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA
                ], $opensslConfig);
                $privateKey = @openssl_pkey_new($config);
                if (!$privateKey) {
                    throw new Exception('Failed to generate private key. OpenSSL error: ' . ($error ?: 'Unknown error'));
                }
                $algorithm = 'RSA'; // Fallback to RSA
            }
            
            $csr = @openssl_csr_new($dn, $privateKey, $csrConfig);
            
        } else {
            // RSA 2048
            $config = array_merge([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA
            ], $opensslConfig);
            
            $privateKey = @openssl_pkey_new($config);
            if (!$privateKey) {
                $error = openssl_error_string();
                throw new Exception('Failed to generate RSA private key. OpenSSL error: ' . ($error ?: 'Unknown error'));
            }
            
            $csr = @openssl_csr_new($dn, $privateKey, $csrConfig);
        }
        
        if (!$csr) {
            throw new Exception('Failed to generate CSR: ' . openssl_error_string());
        }
        
        // Export CSR to PEM format
        openssl_csr_export($csr, $csrPem);
        
        // Export private key to PEM format
        $exportConfig = self::getOpenSSLConfig();
        openssl_pkey_export($privateKey, $privateKeyPem, null, $exportConfig);
        
        return [
            'csr' => $csrPem,
            'privateKey' => $privateKeyPem
        ];
    }
    
    /**
     * Get OpenSSL configuration
     */
    private static function getOpenSSLConfig() {
        // On Windows, we might need to specify config file
        // Try to find openssl.cnf
        $possiblePaths = [
            'C:/xampp/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/ssl/openssl.cnf',
            'C:/OpenSSL-Win64/bin/openssl.cfg',
            'C:/Program Files/OpenSSL-Win64/bin/openssl.cfg',
            php_ini_loaded_file() ? dirname(php_ini_loaded_file()) . '/extras/openssl/openssl.cnf' : null
        ];
        
        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path)) {
                return ['config' => $path];
            }
        }
        
        // Return empty array - let OpenSSL use default
        return [];
    }
    
    /**
     * Verify certificate validity
     */
    public static function verifyCertificate($certificatePem) {
        $cert = openssl_x509_read($certificatePem);
        if (!$cert) {
            return false;
        }
        
        $valid = openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_CLIENT);
        $details = openssl_x509_parse($cert);
        
        return [
            'valid' => $valid !== false,
            'subject' => $details['subject'] ?? null,
            'issuer' => $details['issuer'] ?? null,
            'validFrom' => isset($details['validFrom_time_t']) ? date('Y-m-d H:i:s', $details['validFrom_time_t']) : null,
            'validTo' => isset($details['validTo_time_t']) ? date('Y-m-d H:i:s', $details['validTo_time_t']) : null,
            'serialNumber' => $details['serialNumber'] ?? null
        ];
    }
    
    /**
     * Check if certificate is expiring soon (within 30 days)
     */
    public static function isCertificateExpiringSoon($certificatePem, $days = 30) {
        $cert = openssl_x509_read($certificatePem);
        if (!$cert) {
            return true; // Consider expired if can't read
        }
        
        $details = openssl_x509_parse($cert);
        if (!isset($details['validTo_time_t'])) {
            return true;
        }
        
        $expiryTime = $details['validTo_time_t'];
        $daysUntilExpiry = ($expiryTime - time()) / 86400;
        
        return $daysUntilExpiry <= $days;
    }
}


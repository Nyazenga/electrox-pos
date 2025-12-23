<?php
/**
 * Certificate Storage Helper
 * Handles secure storage and retrieval of certificates and private keys
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

class CertificateStorage {
    /**
     * Get encryption key for private key encryption
     * Uses application secret or generates one
     */
    private static function getEncryptionKey() {
        // Use application secret from config if available
        // Otherwise, use a default key (should be set in production)
        $key = defined('CERTIFICATE_ENCRYPTION_KEY') ? CERTIFICATE_ENCRYPTION_KEY : 'default-key-change-in-production';
        
        // Ensure key is 32 bytes for AES-256
        return substr(hash('sha256', $key), 0, 32);
    }
    
    /**
     * Encrypt private key before storage
     * 
     * @param string $privateKeyPem Private key in PEM format
     * @return string Encrypted private key (base64 encoded)
     */
    public static function encryptPrivateKey($privateKeyPem) {
        $key = self::getEncryptionKey();
        $iv = openssl_random_pseudo_bytes(16);
        
        $encrypted = openssl_encrypt($privateKeyPem, 'AES-256-CBC', $key, 0, $iv);
        
        if ($encrypted === false) {
            throw new Exception('Failed to encrypt private key: ' . openssl_error_string());
        }
        
        // Prepend IV to encrypted data and base64 encode
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt private key after retrieval
     * 
     * @param string $encryptedPrivateKey Encrypted private key (base64 encoded)
     * @return string Private key in PEM format
     */
    public static function decryptPrivateKey($encryptedPrivateKey) {
        $key = self::getEncryptionKey();
        
        $data = base64_decode($encryptedPrivateKey);
        if ($data === false) {
            // May be unencrypted (backward compatibility)
            return $encryptedPrivateKey;
        }
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        
        if ($decrypted === false) {
            // Try as plain text (backward compatibility)
            return $encryptedPrivateKey;
        }
        
        return $decrypted;
    }
    
    /**
     * Save certificate and private key to database
     * 
     * @param int $deviceId Device ID
     * @param string $certificatePem Certificate in PEM format
     * @param string $privateKeyPem Private key in PEM format
     * @param DateTime|null $validTill Certificate expiration date
     * @param bool $encryptPrivateKey Whether to encrypt private key (default: false for now to avoid confusion)
     */
    public static function saveCertificate($deviceId, $certificatePem, $privateKeyPem, $validTill = null, $encryptPrivateKey = false) {
        $db = Database::getPrimaryInstance();
        
        // Store private key (encrypted or plain text based on flag)
        // NOTE: For now, we're NOT encrypting to avoid confusion during testing
        $storedPrivateKey = $encryptPrivateKey ? self::encryptPrivateKey($privateKeyPem) : $privateKeyPem;
        
        // Extract expiration date if not provided
        if ($validTill === null) {
            $validTill = self::extractCertificateExpiry($certificatePem);
        }
        
        // Log certificate storage using ZimraLogger
        if (class_exists('ZimraLogger')) {
            $certDir = defined('APP_PATH') ? APP_PATH . '/certificates' : null;
            if ($certDir && !is_dir($certDir)) {
                @mkdir($certDir, 0755, true);
            }
            $certFile = $certDir ? $certDir . "/device_{$deviceId}_certificate.pem" : null;
            $keyFile = $certDir ? $certDir . "/device_{$deviceId}_private_key.pem" : null;
            
            // Save to files for backup
            if ($certFile) {
                @file_put_contents($certFile, $certificatePem);
            }
            if ($keyFile) {
                @file_put_contents($keyFile, $privateKeyPem);
            }
            
            ZimraLogger::logCertificate($deviceId, 'certificate', $certificatePem, $certFile);
            ZimraLogger::logCertificate($deviceId, 'private_key', $privateKeyPem, $keyFile);
        }
        
        // Update or insert device record
        $device = $db->getRow(
            "SELECT id FROM fiscal_devices WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
        
        $data = [
            'certificate_pem' => $certificatePem,
            'private_key_pem' => $storedPrivateKey, // Store plain text for now (or encrypted if flag is true)
            'certificate_valid_till' => $validTill,
            'is_registered' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if ($device) {
            $db->update('fiscal_devices', $data, ['id' => $device['id']]);
        } else {
            // Device record doesn't exist - try to find by device_id to get branch_id
            $existingDevice = $db->getRow(
                "SELECT branch_id FROM fiscal_devices WHERE device_id = :device_id",
                [':device_id' => $deviceId]
            );
            
            if ($existingDevice) {
                // Update by device_id directly
                $db->update('fiscal_devices', $data, ['device_id' => $deviceId]);
            } else {
                throw new Exception("Device record not found for device_id: $deviceId. Please create device record first.");
            }
        }
    }
    
    /**
     * Load certificate and private key from database
     * 
     * @param int $deviceId Device ID
     * @return array ['certificate' => string, 'privateKey' => string, 'validTill' => DateTime|null] or null if not found
     */
    public static function loadCertificate($deviceId) {
        $db = Database::getPrimaryInstance();
        
        $device = $db->getRow(
            "SELECT certificate_pem, private_key_pem, certificate_valid_till FROM fiscal_devices WHERE device_id = :device_id AND is_registered = 1",
            [':device_id' => $deviceId]
        );
        
        if (!$device || !$device['certificate_pem'] || !$device['private_key_pem']) {
            return null;
        }
        
        // Try to decrypt private key (handles both encrypted and plain text for backward compatibility)
        $privateKey = self::decryptPrivateKey($device['private_key_pem']);
        
        return [
            'certificate' => $device['certificate_pem'],
            'privateKey' => $privateKey,
            'validTill' => $device['certificate_valid_till']
        ];
    }
    
    /**
     * Extract certificate expiration date
     * 
     * @param string $certificatePem Certificate in PEM format
     * @return DateTime|null Expiration date or null if extraction fails
     */
    public static function extractCertificateExpiry($certificatePem) {
        $cert = openssl_x509_read($certificatePem);
        if (!$cert) {
            return null;
        }
        
        $details = openssl_x509_parse($cert);
        if (!isset($details['validTo_time_t'])) {
            return null;
        }
        
        return date('Y-m-d H:i:s', $details['validTo_time_t']);
    }
    
    /**
     * Check if certificate is expired or expiring soon
     * 
     * @param int $deviceId Device ID
     * @param int $daysBeforeExpiry Days before expiry to consider "expiring soon" (default: 30)
     * @return array ['expired' => bool, 'expiringSoon' => bool, 'validTill' => DateTime|null]
     */
    public static function checkCertificateStatus($deviceId, $daysBeforeExpiry = 30) {
        $certData = self::loadCertificate($deviceId);
        
        if (!$certData || !$certData['validTill']) {
            return [
                'expired' => true,
                'expiringSoon' => true,
                'validTill' => null
            ];
        }
        
        $validTill = new DateTime($certData['validTill']);
        $now = new DateTime();
        $expiryThreshold = clone $now;
        $expiryThreshold->modify("+$daysBeforeExpiry days");
        
        return [
            'expired' => $validTill < $now,
            'expiringSoon' => $validTill < $expiryThreshold,
            'validTill' => $validTill
        ];
    }
}


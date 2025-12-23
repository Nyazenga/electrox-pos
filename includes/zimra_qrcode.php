<?php
/**
 * ZIMRA QR Code Generation
 * Implements QR code generation according to ZIMRA spec section 11
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/vendor/autoload.php';

class ZimraQRCode {
    /**
     * Generate QR code data and URL
     * Section 11: Receipt QR code rules
     * 
     * @param string $qrUrl Base URL for QR validation
     * @param int $deviceID Device ID (10 digits with leading zeros)
     * @param string $receiptDate Receipt date (format: YYYY-MM-DD HH:mm:ss)
     * @param int $receiptGlobalNo Receipt global number (10 digits with leading zeros)
     * @param string $receiptQrData Receipt QR data (first 16 chars of MD5 hash)
     * @return array ['qrCode' => string, 'verificationCode' => string, 'qrImage' => string (base64)]
     */
    /**
     * Generate QR code according to ZIMRA spec section 11
     * - qrUrl: from getConfig response (stored in fiscal_config)
     * - deviceID: device ID (10 digits with leading zeros)
     * - receiptDate: from receiptDate field value (format: ddMMyyyy) - NOT serverDate!
     * - receiptGlobalNo: from ZIMRA response (10 digits with leading zeros)
     * - receiptQrData: from ReceiptDeviceSignature MD5 hash (16 chars)
     * 
     * @param string $qrUrl Base URL for QR validation (from getConfig response)
     * @param int $deviceID Device ID (10 digits with leading zeros)
     * @param string $receiptDate Receipt date from receiptDate field value (format: YYYY-MM-DDTHH:mm:ss or ISO 8601)
     * @param int $receiptGlobalNo Receipt global number from ZIMRA response (10 digits with leading zeros)
     * @param string $receiptQrData Receipt QR data (first 16 chars of MD5 hash from ReceiptDeviceSignature hexadecimal format)
     * @return array ['qrCode' => string, 'verificationCode' => string, 'qrImage' => string (base64)]
     */
    public static function generateQRCode($qrUrl, $deviceID, $receiptDate, $receiptGlobalNo, $receiptQrData) {
        // Format deviceID (10 digits with leading zeros)
        $deviceIDFormatted = str_pad(intval($deviceID), 10, '0', STR_PAD_LEFT);
        
        // Format receiptDate from receiptDate field value (8 digits: ddMMyyyy)
        // receiptDate format: YYYY-MM-DDTHH:mm:ss or ISO 8601
        // Documentation: "Invoice date (receiptDate field value) represented in 8 digits (format: ddMMyyyy)"
        try {
            $date = new DateTime($receiptDate);
            $receiptDateFormatted = $date->format('dmY'); // ddMMyyyy format (e.g., 23122025 for Dec 23, 2025) - Y gives 4-digit year
        } catch (Exception $e) {
            throw new Exception('Invalid receiptDate format: ' . $receiptDate);
        }
        
        // Format receiptGlobalNo from ZIMRA response (10 digits with leading zeros) - ZIMRA requires 10 digits, not 9
        $receiptGlobalNoFormatted = str_pad(intval($receiptGlobalNo), 10, '0', STR_PAD_LEFT);
        
        // Ensure receiptQrData is exactly 16 characters (from MD5 hash of ReceiptDeviceSignature)
        $receiptQrDataFormatted = strtoupper(substr($receiptQrData, 0, 16));
        if (strlen($receiptQrDataFormatted) < 16) {
            $receiptQrDataFormatted = str_pad($receiptQrDataFormatted, 16, '0', STR_PAD_RIGHT);
        }
        
        // Build QR code URL according to ZIMRA spec section 11
        // POS needs to add / at the end of URL before adding other fields
        $qrUrl = rtrim($qrUrl, '/') . '/';
        $qrCode = $qrUrl . $deviceIDFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $receiptQrDataFormatted;
        
        // Format verification code (4 character groups separated by dash)
        $verificationCode = self::formatVerificationCode($receiptQrDataFormatted);
        
        // Generate QR code image using TCPDF2DBarcode
        $qrImage = self::generateQRImage($qrCode);
        
        return [
            'qrCode' => $qrCode,
            'verificationCode' => $verificationCode,
            'qrImage' => $qrImage
        ];
    }
    
    /**
     * Format verification code as 4 character groups
     * Example: 4C8BE27663330417 -> 4C8B-E276-6333-0417
     */
    public static function formatVerificationCode($qrData) {
        $groups = str_split($qrData, 4);
        return implode('-', $groups);
    }
    
    /**
     * Generate QR code image using TCPDF2DBarcode
     * Returns base64 encoded PNG image (without data URI prefix)
     * REQUIRED - no fallback, must generate QR code image
     */
    private static function generateQRImage($qrCode) {
        if (!class_exists('TCPDF2DBarcode')) {
            throw new Exception('TCPDF2DBarcode class not available. Cannot generate QR code image.');
        }
        
        try {
            require_once APP_PATH . '/vendor/autoload.php';
            $qr = new TCPDF2DBarcode($qrCode, 'QRCODE,L');
            $qrImage = $qr->getBarcodePngData(4, 4, array(0, 0, 0));
            if ($qrImage && strlen($qrImage) > 0) {
                // Return base64 encoded image (without data URI prefix for database storage)
                return base64_encode($qrImage);
            } else {
                throw new Exception('TCPDF2DBarcode returned empty QR code image data');
            }
        } catch (Exception $e) {
            throw new Exception('Failed to generate QR code image: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate receipt QR data from device signature
     * First 16 characters of MD5 hash from ReceiptDeviceSignature hexadecimal format
     * 
     * Matches Python library method:
     * 1. Base64 decode signature to bytes
     * 2. Convert bytes to hex string
     * 3. Convert hex string back to bytes (bytes.fromhex() equivalent)
     * 4. MD5 hash the bytes
     * 5. Take first 16 characters
     */
    public static function generateReceiptQrData($receiptDeviceSignature) {
        // Decode Base64 string to bytes
        $byteArray = base64_decode($receiptDeviceSignature['signature']);
        
        // Convert bytes to hexadecimal string
        $hexStr = bin2hex($byteArray);
        
        // Convert hex string back to bytes (equivalent to Python's bytes.fromhex())
        $hexBytes = hex2bin($hexStr);
        
        // Compute MD5 hash of the bytes
        $md5Hash = md5($hexBytes);
        
        // Return first 16 characters (uppercase for QR code)
        return strtoupper(substr($md5Hash, 0, 16));
    }
}


<?php
/**
 * ZIMRA Receipt and Fiscal Day Signature Generation
 * Implements signature generation according to ZIMRA spec section 13
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

class ZimraSignature {
    /**
     * Generate receipt device signature
     * Section 13.2.1
     * 
     * @param array $receiptData Receipt data
     * @param string $previousReceiptHash Previous receipt hash (null for first receipt)
     * @param string $privateKeyPem Private key in PEM format
     * @return array ['hash' => string (base64), 'signature' => string (base64)]
     */
    public static function generateReceiptDeviceSignature($receiptData, $previousReceiptHash, $privateKeyPem) {
        // Build signature string according to spec
        $signatureString = self::buildReceiptSignatureString($receiptData, $previousReceiptHash);
        
        // Generate SHA-256 hash according to Section 13.1: Hash = SHA-256(x1|| x2||…||xn)
        $hash = hash('sha256', $signatureString, true);
        $hashBase64 = base64_encode($hash);
        
        // Log hash generation as per Section 13.1
        // Also log to test log file if it exists
        $logFiles = [APP_PATH . '/logs/error.log'];
        if (defined('ZIMRA_TEST_LOG_FILE') && file_exists(ZIMRA_TEST_LOG_FILE)) {
            $logFiles[] = ZIMRA_TEST_LOG_FILE;
        }
        
        foreach ($logFiles as $logFile) {
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = str_repeat('=', 80) . "\n";
                $logMessage .= "HASH GENERATION (ZIMRA Section 13.1)\n";
                $logMessage .= str_repeat('=', 80) . "\n";
                $logMessage .= "Date/Time: $timestamp\n";
                $logMessage .= "\n";
                
                $logMessage .= "ZIMRA INSTRUCTION:\n";
                $logMessage .= "  Formula: Hash = SHA-256(x1|| x2||…||xn)\n";
                $logMessage .= "  Where x1||x2||...||xn is the signature string (concatenated fields)\n";
                $logMessage .= "\n";
                
                $logMessage .= str_repeat('-', 80) . "\n";
                $logMessage .= "HASH GENERATION PROCESS:\n";
                $logMessage .= str_repeat('-', 80) . "\n";
                $logMessage .= "\n";
                
                $logMessage .= "1. Input (Signature String):\n";
                $logMessage .= "   " . $signatureString . "\n";
                $logMessage .= "   Length: " . strlen($signatureString) . " characters\n";
                $logMessage .= "\n";
                
                $logMessage .= "2. Hash Algorithm: SHA-256\n";
                $logMessage .= "   Method: hash('sha256', signatureString, true)\n";
                $logMessage .= "   (true parameter returns raw binary output)\n";
                $logMessage .= "\n";
                
                $logMessage .= "3. Generated Hash (raw binary):\n";
                $logMessage .= "   Length: " . strlen($hash) . " bytes (32 bytes for SHA-256)\n";
                $logMessage .= "   Hexadecimal: " . bin2hex($hash) . "\n";
                $logMessage .= "\n";
                
                $logMessage .= "4. Hash Encoding: Base64\n";
                $logMessage .= "   Method: base64_encode(hash)\n";
                $logMessage .= "   Generated receipt hash in base64 representation:\n";
                $logMessage .= "   " . $hashBase64 . "\n";
                $logMessage .= "\n";
                
                $logMessage .= str_repeat('-', 80) . "\n";
                $logMessage .= "FINAL HASH (for receiptDeviceSignature.hash):\n";
                $logMessage .= str_repeat('-', 80) . "\n";
                $logMessage .= $hashBase64 . "\n";
                $logMessage .= "\n";
                
                $logMessage .= str_repeat('=', 80) . "\n";
                $logMessage .= "END HASH GENERATION\n";
                $logMessage .= str_repeat('=', 80) . "\n\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        // Sign with private key according to Section 13.1:
        // - RSA: Signature = RSA(x1|| x2||…||xn,d,n) - signs the concatenated string directly
        // - ECC: Signature = ECC(Hash,CURVE,g,n) - signs the hash
        // Note: openssl_sign with OPENSSL_ALGO_SHA256 hashes first, then signs
        // This is the standard and secure approach for both RSA (PKCS#1 v1.5) and ECC (ECDSA)
        
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            throw new Exception('Failed to load private key: ' . openssl_error_string());
        }
        
        // Get key type for logging (before signing)
        $keyDetails = openssl_pkey_get_details($privateKey);
        $keyType = $keyDetails['type'] === OPENSSL_KEYTYPE_RSA ? 'RSA' : ($keyDetails['type'] === OPENSSL_KEYTYPE_EC ? 'ECC' : 'UNKNOWN');
        
        // Log key type for debugging
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] ========== SIGNATURE GENERATION (Section 13.1) ==========\n";
                $logMessage .= "[$timestamp] Key type: $keyType";
                if ($keyType === 'RSA') {
                    $logMessage .= " (" . ($keyDetails['bits'] ?? 'unknown') . " bits)\n";
                    $logMessage .= "[$timestamp] Formula: Signature = RSA(x1|| x2||…||xn,d,n)\n";
                } elseif ($keyType === 'ECC') {
                    $logMessage .= " (curve: " . ($keyDetails['ec']['curve_name'] ?? 'unknown') . ")\n";
                    $logMessage .= "[$timestamp] Formula: Signature = ECC(Hash,CURVE,g,n)\n";
                } else {
                    $logMessage .= "\n";
                }
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        $signature = '';
        // Use OPENSSL_ALGO_SHA256 which:
        // - For RSA: Hashes with SHA-256, then signs with PKCS#1 v1.5 padding
        // - For ECC: Hashes with SHA-256, then signs with ECDSA
        // This matches the ZIMRA documentation requirements
        $success = openssl_sign($signatureString, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        
        if (!$success) {
            throw new Exception('Failed to sign receipt: ' . openssl_error_string());
        }
        
        // Get public key details for verification (before freeing private key)
        $publicKeyDetails = openssl_pkey_get_details($privateKey);
        $publicKeyPem = $publicKeyDetails['key'] ?? null;
        
        $signatureBase64 = base64_encode($signature);
        
        // Verify signature can be verified with public key (for debugging)
        if ($publicKeyPem) {
            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if ($publicKey) {
                $verifyResult = openssl_verify($signatureString, $signature, $publicKey, OPENSSL_ALGO_SHA256);
                
                if (defined('APP_PATH')) {
                    $logFile = APP_PATH . '/logs/error.log';
                    $logDir = dirname($logFile);
                    if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                        $timestamp = date('Y-m-d H:i:s');
                        $logMessage = "[$timestamp] Signature verification (cryptographic check): " . ($verifyResult === 1 ? "VALID ✓" : ($verifyResult === 0 ? "INVALID ✗" : "ERROR")) . "\n";
                        @file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                }
            }
        }
        
        // Log final signature for debugging
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] Final device signature (base64): " . $signatureBase64 . "\n";
                $logMessage .= "[$timestamp] ========== END SIGNATURE GENERATION ==========\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        return [
            'hash' => $hashBase64,
            'signature' => $signatureBase64
        ];
    }
    
    /**
     * Build receipt signature string according to ZIMRA Documentation Section 13.2.1
     * 
     * Format: deviceID || receiptType || receiptCurrency || receiptGlobalNo || receiptDate || receiptTotal || receiptTaxes || previousReceiptHash
     * 
     * ALL currencies (USD and ZWL) use the SAME format with all 8 fields.
     * receiptTaxes format: taxCode || taxPercent (2 decimals) || taxAmount (cents) || salesAmountWithTax (cents)
     * 
     * Documentation: "Fields must be concatenated without any concatenation character"
     * This means NO SPACES between fields - direct concatenation.
     * 
     * Documentation example: 321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000B0.000350000C15.0015000115000D15.0030000230000hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=
     */
    private static function buildReceiptSignatureString($receiptData, $previousReceiptHash) {
        $receiptCurrency = strtoupper($receiptData['receiptCurrency']);
        $parts = [];
        
        // Build receiptTaxes string (ZIMRA documentation format: taxCode || taxPercent || taxAmount || salesAmountWithTax)
        $taxesString = self::buildTaxesString($receiptData['receiptTaxes'], $receiptCurrency);
        
        // ALL currencies use the SAME format: all 8 fields in order
        
        // 1. deviceID - format as integer (no zero padding for signature)
        $parts[] = strval(intval($receiptData['deviceID']));
        
        // 2. receiptType (uppercase)
        $parts[] = strtoupper($receiptData['receiptType']);
        
        // 3. receiptCurrency (uppercase)
        $parts[] = $receiptCurrency;
        
        // 4. receiptGlobalNo - format as integer (no zero padding for signature)
        $parts[] = strval(intval($receiptData['receiptGlobalNo']));
        
        // 5. receiptDate (ISO 8601 format: YYYY-MM-DDTHH:mm:ss)
        // Handle both with and without timezone/milliseconds
        $dateStr = $receiptData['receiptDate'];
        if (strpos($dateStr, 'T') !== false) {
            // Remove timezone and milliseconds if present
            $dateStr = preg_replace('/\.\d+Z?$/', '', $dateStr); // Remove milliseconds and Z
            $dateStr = preg_replace('/[+-]\d{2}:\d{2}$/', '', $dateStr); // Remove timezone
        }
        $date = new DateTime($dateStr);
        $parts[] = $date->format('Y-m-d\TH:i:s');
        
        // 6. receiptTotal (in cents) - currency-specific conversion
        $totalCents = self::toCents($receiptData['receiptTotal'], $receiptCurrency);
        $parts[] = strval($totalCents);
        
        // 7. receiptTaxes (concatenated - Python format: taxPercent || taxAmount || salesAmountWithTax)
        $parts[] = $taxesString;
        
        // 8. previousReceiptHash (if not first receipt)
        if ($previousReceiptHash !== null) {
            $parts[] = $previousReceiptHash;
        }
        
        $signatureString = implode('', $parts);
        
        // Log signature string EXACTLY as per ZIMRA documentation format for manual verification
        // Also log to test log file if it exists (for test_with_detailed_zimra_logs.php)
        $logFiles = [APP_PATH . '/logs/error.log'];
        if (defined('ZIMRA_TEST_LOG_FILE') && file_exists(ZIMRA_TEST_LOG_FILE)) {
            $logFiles[] = ZIMRA_TEST_LOG_FILE;
        }
        
        foreach ($logFiles as $logFile) {
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = str_repeat('=', 80) . "\n";
                $logMessage .= "RECEIPT SIGNATURE STRING GENERATION (ZIMRA Section 13.2.1)\n";
                $logMessage .= str_repeat('=', 80) . "\n";
                $logMessage .= "Date/Time: $timestamp\n";
                $logMessage .= "Receipt Counter: " . ($receiptData['receiptCounter'] ?? 'N/A') . "\n";
                $logMessage .= "Receipt Global No: " . ($receiptData['receiptGlobalNo'] ?? 'N/A') . "\n";
                $logMessage .= "\n";
                
                $logMessage .= "ZIMRA INSTRUCTION:\n";
                $logMessage .= "  The concatenated string must have the following parameters in correct order:\n";
                $logMessage .= "  deviceID || receiptType || receiptCurrency || receiptGlobalNo || receiptDate || receiptTotal || receiptTaxes || previousReceiptHash\n";
                $logMessage .= "  receiptTaxes format: taxCode || taxPercent || taxAmount || salesAmountWithTax\n";
                $logMessage .= "  NB: If it's the first receipt of the day, previousReceiptHash must NOT be included\n";
                $logMessage .= "\n";
                
                $logMessage .= str_repeat('-', 80) . "\n";
                $logMessage .= "SIGNATURE STRING CONSTRUCTION (STEP BY STEP):\n";
                $logMessage .= str_repeat('-', 80) . "\n";
                $logMessage .= "\n";
                
                // Log each component clearly
                $logMessage .= "1. deviceID = " . $parts[0] . "\n";
                $logMessage .= "   → " . $parts[0] . "\n";
                $logMessage .= "\n";
                
                $logMessage .= "2. receiptType (uppercase) = " . strtoupper($receiptData['receiptType']) . "\n";
                $logMessage .= "   → " . $parts[1] . "\n";
                $logMessage .= "\n";
                
                $logMessage .= "3. receiptCurrency (uppercase) = " . strtoupper($receiptData['receiptCurrency']) . "\n";
                $logMessage .= "   → " . $parts[2] . "\n";
                $logMessage .= "\n";
                
                $logMessage .= "4. receiptGlobalNo = " . intval($receiptData['receiptGlobalNo']) . "\n";
                $logMessage .= "   → " . $parts[3] . "\n";
                $logMessage .= "\n";
                
                $logMessage .= "5. receiptDate = " . ($receiptData['receiptDate'] ?? 'N/A') . "\n";
                $logMessage .= "   → " . $parts[4] . " (ISO 8601: YYYY-MM-DDTHH:mm:ss)\n";
                $logMessage .= "\n";
                
                $logMessage .= "6. receiptTotal = " . floatval($receiptData['receiptTotal'] ?? 0) . " (original value)\n";
                $logMessage .= "   → " . $parts[5] . " (in cents)\n";
                $logMessage .= "   Calculation: " . floatval($receiptData['receiptTotal'] ?? 0) . " * 100 = " . $parts[5] . " cents\n";
                $logMessage .= "\n";
                
                $logMessage .= "7. receiptTaxes (taxCode || taxPercent || taxAmount || salesAmountWithTax):\n";
                // Log breakdown of receiptTaxes
                if (!empty($receiptData['receiptTaxes'])) {
                    foreach ($receiptData['receiptTaxes'] as $idx => $tax) {
                        $taxCode = $tax['taxCode'] ?? '';
                        $taxPercent = isset($tax['taxPercent']) ? number_format(floatval($tax['taxPercent']), 2, '.', '') : '';
                        $taxAmount = isset($tax['taxAmount']) ? intval(floatval($tax['taxAmount']) * 100) : 0;
                        $salesAmount = isset($tax['salesAmountWithTax']) ? intval(floatval($tax['salesAmountWithTax']) * 100) : 0;
                        $logMessage .= "   Tax Entry #" . ($idx + 1) . ":\n";
                        $logMessage .= "     - taxCode = '" . $taxCode . "'\n";
                        $logMessage .= "     - taxPercent = " . ($tax['taxPercent'] ?? 'N/A') . " → " . $taxPercent . " (2 decimals)\n";
                        $logMessage .= "     - taxAmount = " . ($tax['taxAmount'] ?? 'N/A') . " → " . $taxAmount . " cents\n";
                        $logMessage .= "     - salesAmountWithTax = " . ($tax['salesAmountWithTax'] ?? 'N/A') . " → " . $salesAmount . " cents\n";
                        $logMessage .= "     → Concatenated: " . $taxCode . $taxPercent . $taxAmount . $salesAmount . "\n";
                    }
                }
                $logMessage .= "   → Complete receiptTaxes string: " . $parts[6] . "\n";
                $logMessage .= "\n";
                
                if (isset($parts[7])) {
                    $logMessage .= "8. previousReceiptHash = " . $parts[7] . "\n";
                } else {
                    $logMessage .= "8. previousReceiptHash = (NOT INCLUDED - first receipt in fiscal day per ZIMRA instruction)\n";
                }
                $logMessage .= "\n";
                
                $logMessage .= str_repeat('-', 80) . "\n";
                $logMessage .= "COMPLETE SIGNATURE STRING (concatenated without any separator):\n";
                $logMessage .= str_repeat('-', 80) . "\n";
                $logMessage .= $signatureString . "\n";
                $logMessage .= "\n";
                $logMessage .= "String length: " . strlen($signatureString) . " characters\n";
                $logMessage .= "\n";
                $logMessage .= str_repeat('=', 80) . "\n";
                $logMessage .= "END SIGNATURE STRING GENERATION\n";
                $logMessage .= str_repeat('=', 80) . "\n\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        return $signatureString;
    }
    
    /**
     * Build taxes string for signature
     * 
     * CRITICAL: ZIMRA Documentation Section 13.2.1 specifies:
     * Format: taxCode || taxPercent || taxAmount || salesAmountWithTax
     * 
     * Documentation explicitly states: "taxCode || taxPercent || taxAmount || salesAmountWithTax"
     * Sorting: "Taxes are ordered by taxID in ascending order and taxCode in alphabetical order
     * (if taxCode is empty it is ordered before A letter)."
     * 
     * Note: Python library doesn't include taxCode, but ZIMRA documentation requires it.
     * We'll follow the official ZIMRA documentation (which matches the example).
     */
    private static function buildTaxesString($receiptTaxes, $currency = 'ZWL') {
        // Sort taxes by taxID ascending, then by taxCode alphabetically (empty comes before A)
        // Documentation: "Taxes are ordered by taxID in ascending order and taxCode in alphabetical 
        // order (if taxCode is empty it is ordered before A letter)."
        usort($receiptTaxes, function($a, $b) {
            $taxIdA = intval($a['taxID'] ?? 0);
            $taxIdB = intval($b['taxID'] ?? 0);
            if ($taxIdA !== $taxIdB) {
                return $taxIdA - $taxIdB;
            }
            // If taxID is same, sort by taxCode (empty comes before any letter)
            $taxCodeA = $a['taxCode'] ?? '';
            $taxCodeB = $b['taxCode'] ?? '';
            if ($taxCodeA === '' && $taxCodeB !== '') {
                return -1; // Empty comes first
            }
            if ($taxCodeA !== '' && $taxCodeB === '') {
                return 1; // Empty comes first
            }
            return strcmp($taxCodeA, $taxCodeB);
        });
        
        // Log tax sorting order
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] TAX SORTING (ZIMRA Documentation Format): Taxes ordered by taxID (ascending), then taxCode (alphabetical, empty first):\n";
                foreach ($receiptTaxes as $idx => $tax) {
                    $logMessage .= "[$timestamp]   Tax[$idx]: taxID=" . ($tax['taxID'] ?? 'N/A') . ", taxCode='" . ($tax['taxCode'] ?? '') . "', taxPercent=" . ($tax['taxPercent'] ?? 'N/A') . "\n";
                }
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        $taxStrings = [];
        foreach ($receiptTaxes as $tax) {
            // ZIMRA Documentation format: taxCode || taxPercent || taxAmount || salesAmountWithTax
            // Documentation Section 13.2.1: "taxCode || taxPercent || taxAmount || salesAmountWithTax"
            
            // 1. taxCode (empty string if not present)
            $taxCode = $tax['taxCode'] ?? '';
            
            // 2. taxPercent - format with exactly 2 decimal places
            // Documentation: "In case taxPercent is not an integer there should be dot between the integer and fractional part."
            // "In case taxPercent is an integer there should be value of tax percent, dot and two zeros sent."
            // Examples: 15 -> 15.00, 14.5 -> 14.50, 15.5 -> 15.50
            $percent = '';
            if (isset($tax['taxPercent'])) {
                $percentValue = floatval($tax['taxPercent']);
                $percent = number_format($percentValue, 2, '.', ''); // Always 2 decimal places, e.g., "15.50"
            }
            // If taxPercent is not sent (exempt), use empty value
            
            // 3. taxAmount - in cents (use toCents for currency-specific conversion)
            // Documentation: "Amounts are represented in cents"
            $taxAmountFloat = floatval($tax['taxAmount'] ?? 0);
            $amountCents = self::toCents($taxAmountFloat, $currency);
            
            // 4. salesAmountWithTax - in cents (use toCents for currency-specific conversion)
            // Documentation: "Amounts are represented in cents"
            $salesAmountFloat = floatval($tax['salesAmountWithTax'] ?? 0);
            $salesCents = self::toCents($salesAmountFloat, $currency);
            
            // ZIMRA Documentation format: taxCode || taxPercent || taxAmount || salesAmountWithTax
            $taxString = $taxCode . $percent . strval($amountCents) . strval($salesCents);
            $taxStrings[] = $taxString;
            
            // Log tax string construction for debugging (matching ZIMRA documentation format)
            if (defined('APP_PATH')) {
                $logFile = APP_PATH . '/logs/error.log';
                $logDir = dirname($logFile);
                if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                    $timestamp = date('Y-m-d H:i:s');
                    $logMessage = "[$timestamp] TAX STRING CONSTRUCTION (ZIMRA Documentation Format):\n";
                    $logMessage .= "[$timestamp]   Format: taxCode || taxPercent (2 decimals) || taxAmount (cents) || salesAmountWithTax (cents)\n";
                    $logMessage .= "[$timestamp]   taxCode: '$taxCode' (from " . ($tax['taxCode'] ?? 'N/A') . ")\n";
                    $logMessage .= "[$timestamp]   taxPercent: '$percent' (from " . ($tax['taxPercent'] ?? 'N/A') . ", formatted with 2 decimal places)\n";
                    $logMessage .= "[$timestamp]   taxAmount: '$amountCents' (from " . ($tax['taxAmount'] ?? 'N/A') . " " . $currency . ", intval(value * 100))\n";
                    $logMessage .= "[$timestamp]   salesAmountWithTax: '$salesCents' (from " . ($tax['salesAmountWithTax'] ?? 'N/A') . " " . $currency . ", intval(value * 100))\n";
                    $logMessage .= "[$timestamp]   Final tax string: $taxString\n";
                    @file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
            }
        }
        
        return implode('', $taxStrings);
    }
    
    /**
     * Convert amount to cents
     * According to ZIMRA documentation, amounts are always represented in cents
     * For currencies with 2 decimal places (USD, ZWL, etc.), multiply by 100
     * For currencies with different decimal places, adjust accordingly
     */
    private static function toCents($amount, $currencyCode) {
        // Get currency decimal places from database - NO FALLBACK
        if (!defined('APP_PATH') || !class_exists('Database')) {
            throw new Exception("Cannot convert amount to cents: Database class not available for currency: $currencyCode");
        }
        
        $db = Database::getInstance();
        if (!$db || !method_exists($db, 'getRow')) {
            throw new Exception("Cannot convert amount to cents: Database instance not available for currency: $currencyCode");
        }
        
        // Map currency code back to original for database lookup
        // ZWG (ZIMRA code) should map back to ZWL (database code) for decimal_places lookup
        $currencyCodeForDb = self::mapCurrencyCodeForDb($currencyCode);
        
        $currency = $db->getRow("SELECT decimal_places FROM currencies WHERE code = ? AND is_active = 1 LIMIT 1", [$currencyCodeForDb]);
        if (!$currency || !isset($currency['decimal_places'])) {
            throw new Exception("Cannot convert amount to cents: Currency '$currencyCodeForDb' (mapped from '$currencyCode') not found in database or decimal_places not set");
        }
        
        $decimalPlaces = intval($currency['decimal_places']);
        
        // Convert to smallest currency unit (cents for 2 decimal places)
        // For 2 decimal places: multiply by 100 (e.g., 45.00 USD = 4500 cents)
        // For 3 decimal places: multiply by 1000, etc.
        // CRITICAL: Match Python's int() behavior - truncate, don't round!
        // Python: int(receiptTotal * 100) truncates (floors for positive numbers)
        // PHP: intval() also truncates, but we should NOT use round() first
        $multiplier = pow(10, $decimalPlaces);
        return intval($amount * $multiplier);
    }
    
    /**
     * Map ZIMRA currency code back to database currency code
     * For database lookups (decimal_places), we need the original currency code
     * 
     * @param string $zimraCurrencyCode Currency code used in ZIMRA API (e.g., ZWG)
     * @return string Original currency code from database (e.g., ZWL)
     */
    private static function mapCurrencyCodeForDb($zimraCurrencyCode) {
        $code = strtoupper(trim($zimraCurrencyCode));
        
        // Reverse mapping: ZIMRA code -> Database code
        // ZWG (ZIMRA) -> ZWL (database)
        $reverseMap = [
            'ZWG' => 'ZWL', // ZWG (Zimbabwe Gold/ZiG) maps back to ZWL in database
            // Add other reverse mappings here if needed
        ];
        
        return $reverseMap[$code] ?? $code;
    }
    
    /**
     * Generate fiscal day device signature
     * Section 13.3.1
     */
    public static function generateFiscalDayDeviceSignature($fiscalDayData, $privateKeyPem) {
        // Build signature string according to Section 13.3.1
        $signatureString = self::buildFiscalDaySignatureString($fiscalDayData);
        
        // Log signature string for debugging
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] ========== FISCAL DAY DEVICE SIGNATURE GENERATION (Section 13.3.1) ==========\n";
                $logMessage .= "[$timestamp] Order 1 - deviceID: " . ($fiscalDayData['deviceID'] ?? 'N/A') . "\n";
                $logMessage .= "[$timestamp] Order 2 - fiscalDayNo: " . ($fiscalDayData['fiscalDayNo'] ?? 'N/A') . "\n";
                $logMessage .= "[$timestamp] Order 3 - fiscalDayDate (YYYY-MM-DD): " . (isset($fiscalDayData['fiscalDayOpened']) ? (new DateTime($fiscalDayData['fiscalDayOpened']))->format('Y-m-d') : 'N/A') . "\n";
                $logMessage .= "[$timestamp] Order 4 - fiscalDayCounters: (see counters string below)\n";
                $logMessage .= "[$timestamp] \n";
                $logMessage .= "[$timestamp] COMPLETE SIGNATURE STRING (no concatenation character between fields):\n";
                $logMessage .= "[$timestamp] " . $signatureString . "\n";
                $logMessage .= "[$timestamp] Signature string length: " . strlen($signatureString) . " characters\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        // Generate SHA-256 hash according to Section 13.1: Hash = SHA-256(x1|| x2||…||xn)
        $hash = hash('sha256', $signatureString, true);
        $hashBase64 = base64_encode($hash);
        
        // Log hash generation
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] Generated hash (base64): " . $hashBase64 . "\n";
                $logMessage .= "[$timestamp] Hash length: " . strlen($hash) . " bytes (32 bytes for SHA-256)\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            throw new Exception('Failed to load private key: ' . openssl_error_string());
        }
        
        // Get key type for logging
        $keyDetails = openssl_pkey_get_details($privateKey);
        $keyType = $keyDetails['type'] === OPENSSL_KEYTYPE_RSA ? 'RSA' : ($keyDetails['type'] === OPENSSL_KEYTYPE_EC ? 'ECC' : 'UNKNOWN');
        
        // Log key type
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] Key type: $keyType";
                if ($keyType === 'RSA') {
                    $logMessage .= " (" . ($keyDetails['bits'] ?? 'unknown') . " bits)\n";
                } elseif ($keyType === 'ECC') {
                    $logMessage .= " (curve: " . ($keyDetails['ec']['curve_name'] ?? 'unknown') . ")\n";
                } else {
                    $logMessage .= "\n";
                }
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        $signature = '';
        // Use openssl_sign with OPENSSL_ALGO_SHA256 (same as receipts which work)
        // This hashes first, then signs - standard and secure approach
        $success = openssl_sign($signatureString, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        
        if (!$success) {
            throw new Exception('Failed to sign fiscal day: ' . openssl_error_string());
        }
        
        $signatureBase64 = base64_encode($signature);
        
        // Log final signature
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] Final device signature (base64): " . $signatureBase64 . "\n";
                $logMessage .= "[$timestamp] ========== END FISCAL DAY DEVICE SIGNATURE GENERATION ==========\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        return [
            'hash' => $hashBase64,
            'signature' => $signatureBase64
        ];
    }
    
    /**
     * Build fiscal day signature string
     */
    private static function buildFiscalDaySignatureString($fiscalDayData) {
        $parts = [];
        
        // 1. deviceID
        $parts[] = strval($fiscalDayData['deviceID']);
        
        // 2. fiscalDayNo
        $parts[] = strval($fiscalDayData['fiscalDayNo']);
        
        // 3. fiscalDayDate (YYYY-MM-DD)
        $date = new DateTime($fiscalDayData['fiscalDayOpened']);
        $parts[] = $date->format('Y-m-d');
        
        // 4. fiscalDayCounters (concatenated)
        $countersString = self::buildCountersString($fiscalDayData['fiscalDayCounters']);
        $parts[] = $countersString;
        
        return implode('', $parts);
    }
    
    /**
     * Build counters string for signature
     * According to ZIMRA documentation Section 13.3.1:
     * - Sort by: fiscalCounterType (asc), currency (asc), fiscalCounterTaxID (asc) / fiscalCounterMoneyType (asc)
     * - Format: fiscalCounterType || fiscalCounterCurrency || fiscalCounterTaxPercent/fiscalCounterMoneyType || fiscalCounterValue
     * - All text in UPPER CASE
     * - Amounts in cents
     * - taxPercent: empty if exempt, "0.00" if 0, "15.00" if 15, "14.50" if 14.5
     */
    private static function buildCountersString($counters) {
        // Sort counters according to documentation:
        // 1. fiscalCounterType (ascending order)
        // 2. fiscalCounterCurrency (alphabetical ascending order)
        // 3. fiscalCounterTaxID (ascending order) for tax counters OR fiscalCounterMoneyType (ascending order) for money type counters
        usort($counters, function($a, $b) {
            // 1. Sort by fiscalCounterType (ascending)
            $typeA = strtoupper($a['fiscalCounterType'] ?? '');
            $typeB = strtoupper($b['fiscalCounterType'] ?? '');
            if ($typeA !== $typeB) {
                return strcmp($typeA, $typeB);
            }
            
            // 2. Sort by currency (alphabetical ascending)
            $currA = strtoupper($a['fiscalCounterCurrency'] ?? '');
            $currB = strtoupper($b['fiscalCounterCurrency'] ?? '');
            if ($currA !== $currB) {
                return strcmp($currA, $currB);
            }
            
            // 3. Sort by taxID (numeric ascending) for tax counters, or moneyType (ascending) for money type counters
            if (isset($a['fiscalCounterTaxID']) || isset($b['fiscalCounterTaxID'])) {
                // Tax counter - sort by taxID numerically
                $taxIDA = intval($a['fiscalCounterTaxID'] ?? 0);
                $taxIDB = intval($b['fiscalCounterTaxID'] ?? 0);
                if ($taxIDA !== $taxIDB) {
                    return $taxIDA - $taxIDB;
                }
                // If same taxID, sort by taxPercent (for cases where same taxID has different percents)
                // CRITICAL: Handle null (exempt) vs 0 (zero-rated) vs > 0 (tax)
                // Exempt (null) should come first, then 0, then positive values
                $percentA = $a['fiscalCounterTaxPercent'] ?? null;
                $percentB = $b['fiscalCounterTaxPercent'] ?? null;
                
                if ($percentA === null && $percentB === null) {
                    // Both exempt - equal
                } elseif ($percentA === null) {
                    return -1; // Exempt comes before any value
                } elseif ($percentB === null) {
                    return 1; // Exempt comes before any value
                } else {
                    // Both have values - compare numerically
                    $percentAFloat = floatval($percentA);
                    $percentBFloat = floatval($percentB);
                    if (abs($percentAFloat - $percentBFloat) > 0.001) {
                        return $percentAFloat < $percentBFloat ? -1 : 1;
                    }
                }
            } else {
                // Money type counter - sort by moneyType alphabetically
                $moneyA = strtoupper($a['fiscalCounterMoneyType'] ?? '');
                $moneyB = strtoupper($b['fiscalCounterMoneyType'] ?? '');
                return strcmp($moneyA, $moneyB);
            }
            
            return 0;
        });
        
        $counterStrings = [];
        foreach ($counters as $counter) {
            $type = strtoupper($counter['fiscalCounterType']);
            $currency = strtoupper($counter['fiscalCounterCurrency']);
            
            // Format taxPercent or moneyType according to documentation Section 13.3.1:
            // - If exempt (taxPercent is null/not set): use empty string
            // - If taxPercent is 0 (zero-rated): use "0.00"
            // - If taxPercent is non-zero: use number_format with exactly 2 decimal places (e.g., "15.00", "14.50")
            // - If moneyType: use uppercase moneyType
            // CRITICAL: We distinguish between null (exempt) and 0 (zero-rated)
            $percentOrMoneyType = '';
            if (isset($counter['fiscalCounterTaxPercent']) && $counter['fiscalCounterTaxPercent'] !== null) {
                // taxPercent is explicitly set (including 0 for zero-rated)
                $taxPercent = floatval($counter['fiscalCounterTaxPercent']);
                // Always format with exactly 2 decimal places: 0 -> "0.00", 15 -> "15.00", 14.5 -> "14.50"
                $percentOrMoneyType = number_format($taxPercent, 2, '.', '');
            } elseif (isset($counter['fiscalCounterMoneyType'])) {
                // Money type counter
                $percentOrMoneyType = strtoupper($counter['fiscalCounterMoneyType']);
            }
            // If neither is set (exempt), $percentOrMoneyType remains empty string
            
            // Convert value to cents
            // CRITICAL: fiscalCounterValue should be in currency units (e.g., 4031.00)
            // We need to convert it to cents (integer) for the signature string
            // According to documentation: "Amounts are represented in cents"
            // Example: 23000,00 becomes 2300000 (23000 * 100 = 2300000 cents)
            $valueFloat = floatval($counter['fiscalCounterValue']);
            $valueCents = self::toCents($valueFloat, $currency);
            
            // Build counter string: type || currency || percentOrMoneyType || valueCents
            // valueCents is already an integer from toCents(), convert to string
            $counterString = $type . $currency . $percentOrMoneyType . strval($valueCents);
            $counterStrings[] = $counterString;
            
            // Log for debugging
            if (defined('APP_PATH')) {
                $logFile = APP_PATH . '/logs/error.log';
                $logDir = dirname($logFile);
                if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                    $timestamp = date('Y-m-d H:i:s');
                    $logMessage = "[$timestamp] FISCAL DAY COUNTER STRING (Section 13.3.1):\n";
                    $logMessage .= "[$timestamp]   Type: $type\n";
                    $logMessage .= "[$timestamp]   Currency: $currency\n";
                    $logMessage .= "[$timestamp]   TaxPercent/MoneyType: '$percentOrMoneyType'\n";
                    $logMessage .= "[$timestamp]   Value: $valueFloat -> $valueCents cents\n";
                    $logMessage .= "[$timestamp]   Counter String: $counterString\n";
                    @file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
            }
        }
        
        $result = implode('', $counterStrings);
        
        // Log complete counters string
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] COMPLETE FISCAL DAY COUNTERS STRING (Section 13.3.1):\n";
                $logMessage .= "[$timestamp] " . $result . "\n";
                $logMessage .= "[$timestamp] Length: " . strlen($result) . " characters\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        return $result;
    }
}



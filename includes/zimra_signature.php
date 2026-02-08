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
     * receiptTaxes format: taxPercent (2 decimals, empty if exempt) || taxAmount (cents) || salesAmountWithTax (cents)
     * NOTE: taxCode is NOT included in signature string (matches zimra-public reference library)
     * 
     * Documentation: "Fields must be concatenated without any concatenation character"
     * This means NO SPACES between fields - direct concatenation.
     * 
     * Documentation example: 321FISCALINVOICEZWL4322019-09-19T15:43:12945000A0250000B0.000350000C15.0015000115000D15.0030000230000hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k=
     */
    private static function buildReceiptSignatureString($receiptData, $previousReceiptHash) {
        $receiptCurrency = strtoupper($receiptData['receiptCurrency']);
        $parts = [];
        
        // Build receiptTaxes string (zimra-public format: taxPercent || taxAmount || salesAmountWithTax, NO taxCode)
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
                $logMessage .= "  receiptTaxes format: taxPercent (2 decimals, empty if exempt) || taxAmount (cents) || salesAmountWithTax (cents)\n";
                $logMessage .= "  NOTE: taxCode is NOT included in signature string (matches zimra-public reference library)\n";
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
                
                $logMessage .= "7. receiptTaxes (taxPercent || taxAmount || salesAmountWithTax, NO taxCode):\n";
                // Log breakdown of receiptTaxes
                if (!empty($receiptData['receiptTaxes'])) {
                    foreach ($receiptData['receiptTaxes'] as $idx => $tax) {
                        $taxCode = $tax['taxCode'] ?? ''; // For logging only, not in signature
                        $taxPercent = isset($tax['taxPercent']) ? number_format(floatval($tax['taxPercent']), 2, '.', '') : '';
                        $taxAmount = isset($tax['taxAmount']) ? self::toCents(floatval($tax['taxAmount']), $receiptCurrency) : 0;
                        $salesAmount = isset($tax['salesAmountWithTax']) ? self::toCents(floatval($tax['salesAmountWithTax']), $receiptCurrency) : 0;
                        $logMessage .= "   Tax Entry #" . ($idx + 1) . ":\n";
                        $logMessage .= "     - taxCode = '" . $taxCode . "' (in payload only, NOT in signature)\n";
                        $logMessage .= "     - taxPercent = " . ($tax['taxPercent'] ?? 'N/A (exempt)') . " → '" . $taxPercent . "' (2 decimals or empty)\n";
                        $logMessage .= "     - taxAmount = " . ($tax['taxAmount'] ?? 'N/A') . " → " . $taxAmount . " cents\n";
                        $logMessage .= "     - salesAmountWithTax = " . ($tax['salesAmountWithTax'] ?? 'N/A') . " → " . $salesAmount . " cents\n";
                        $logMessage .= "     → Signature string (NO taxCode): " . $taxPercent . $taxAmount . $salesAmount . "\n";
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
                
                // ADDITIONAL LOGGING: Check if 5% tax is present and log it separately for ZIMRA support
                if (!empty($receiptData['receiptTaxes'])) {
                    $has5PercentTax = false;
                    foreach ($receiptData['receiptTaxes'] as $tax) {
                        $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : null;
                        if ($taxPercent == 5) {
                            $has5PercentTax = true;
                            $logMessage .= str_repeat('-', 80) . "\n";
                            $logMessage .= "*** 5% NON-VAT WITHHOLDING TAX DETECTED ***\n";
                            $logMessage .= str_repeat('-', 80) . "\n";
                            $logMessage .= "Tax ID: " . ($tax['taxID'] ?? 'N/A') . "\n";
                            $logMessage .= "Tax Code: " . ($tax['taxCode'] ?? 'N/A') . "\n";
                            $logMessage .= "Tax Percent: " . ($taxPercent ?? 'N/A') . "\n";
                            $logMessage .= "Tax Amount: " . ($tax['taxAmount'] ?? 'N/A') . "\n";
                            $logMessage .= "Sales Amount With Tax: " . ($tax['salesAmountWithTax'] ?? 'N/A') . "\n";
                            $logMessage .= "\n";
                            // Log how this tax appears in the concatenated string (NO taxCode in signature)
                            $taxCode = $tax['taxCode'] ?? ''; // For reference only, not in signature
                            $taxPercentStr = isset($tax['taxPercent']) ? number_format(floatval($tax['taxPercent']), 2, '.', '') : '';
                            $taxAmountStr = self::toCents(floatval($tax['taxAmount'] ?? 0), $receiptCurrency);
                            $salesAmountStr = self::toCents(floatval($tax['salesAmountWithTax'] ?? 0), $receiptCurrency);
                            $taxSegment = $taxPercentStr . $taxAmountStr . $salesAmountStr; // NO taxCode
                            $logMessage .= "5% Tax segment in signature string: " . $taxSegment . "\n";
                            $logMessage .= "  Format: taxPercent('$taxPercentStr') || taxAmount($taxAmountStr cents) || salesAmountWithTax($salesAmountStr cents) [taxCode='$taxCode' in payload only]\n";
                            $logMessage .= str_repeat('-', 80) . "\n";
                            break;
                        }
                    }
                    if ($has5PercentTax) {
                        $logMessage .= "\n*** THIS CONCATENATED STRING CONTAINS 5% NON-VAT WITHHOLDING TAX FOR ZIMRA TESTING ***\n\n";
                    }
                }
                
                $logMessage .= str_repeat('=', 80) . "\n";
                $logMessage .= "END SIGNATURE STRING GENERATION\n";
                $logMessage .= str_repeat('=', 80) . "\n\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                // ALSO log to error_log for visibility in nginx logs (for production debugging)
                // This ensures the concatenated string is visible even if file logging fails
                error_log("ZIMRA SIGNATURE: Receipt Global No: " . ($receiptData['receiptGlobalNo'] ?? 'N/A') . " | Counter: " . ($receiptData['receiptCounter'] ?? 'N/A'));
                error_log("ZIMRA SIGNATURE: COMPLETE CONCATENATED STRING: " . $signatureString);
                error_log("ZIMRA SIGNATURE: String length: " . strlen($signatureString) . " characters");
                if (!empty($receiptData['receiptTaxes'])) {
                    foreach ($receiptData['receiptTaxes'] as $idx => $tax) {
                        $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : null;
                        if ($taxPercent == 5) {
                            error_log("ZIMRA SIGNATURE: *** 5% NON-VAT WITHHOLDING TAX PRESENT - TaxID: " . ($tax['taxID'] ?? 'N/A') . ", TaxCode: '" . ($tax['taxCode'] ?? '') . "'");
                            $taxCode = $tax['taxCode'] ?? ''; // For reference only
                            $taxPercentStr = isset($tax['taxPercent']) ? number_format(floatval($tax['taxPercent']), 2, '.', '') : '';
                            $taxAmountStr = self::toCents(floatval($tax['taxAmount'] ?? 0), $receiptCurrency);
                            $salesAmountStr = self::toCents(floatval($tax['salesAmountWithTax'] ?? 0), $receiptCurrency);
                            $taxSegment = $taxPercentStr . $taxAmountStr . $salesAmountStr; // NO taxCode in signature
                            error_log("ZIMRA SIGNATURE: 5% Tax segment: " . $taxSegment . " (taxPercent='$taxPercentStr' || taxAmount=$taxAmountStr || salesAmount=$salesAmountStr) [taxCode='$taxCode' in payload only]");
                        }
                    }
                }
            }
        }
        
        return $signatureString;
    }
    
    /**
     * Build taxes string for signature
     * 
     * CRITICAL: Matches zimra-public reference library format (working implementation)
     * Format: taxPercent || taxAmount || salesAmountWithTax (NO taxCode in signature)
     * 
     * Sorting: Taxes are ordered by taxID in ascending order ONLY
     * 
     * Note: taxCode is included in JSON payload but NOT in signature string
     * For exempt taxes, taxPercent is empty string in signature
     * We'll follow the official ZIMRA documentation (which matches the example).
     */
    private static function buildTaxesString($receiptTaxes, $currency = 'ZWL') {
        // CRITICAL FIX: Match zimra-public reference library format
        // Sort taxes by taxID ascending ONLY (no taxCode sorting)
        // Signature format: taxPercent || taxAmount || salesAmountWithTax (NO taxCode in signature)
        usort($receiptTaxes, function($a, $b) {
            $taxIdA = intval($a['taxID'] ?? 0);
            $taxIdB = intval($b['taxID'] ?? 0);
            return $taxIdA - $taxIdB;
        });
        
        // Log tax sorting order
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] TAX SORTING (zimra-public format): Taxes ordered by taxID (ascending) ONLY:\n";
                foreach ($receiptTaxes as $idx => $tax) {
                    $logMessage .= "[$timestamp]   Tax[$idx]: taxID=" . ($tax['taxID'] ?? 'N/A') . ", taxCode='" . ($tax['taxCode'] ?? '') . "', taxPercent=" . ($tax['taxPercent'] ?? 'N/A (exempt)') . "\n";
                }
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        $taxStrings = [];
        foreach ($receiptTaxes as $tax) {
            // CRITICAL: zimra-public format - NO taxCode in signature string
            // Format: taxPercent || taxAmount || salesAmountWithTax
            // Reference: zimra-public/zimra/__init__.py lines 392-405
            
            // 1. taxPercent - format with exactly 2 decimal places, or empty string if exempt OR zero
            // CRITICAL FIX: For exempt taxes AND zero-percent taxes, use empty string in signature
            // This matches ZIMRA's expectation: 0% should be treated like exempt in signature string
            // For exempt taxes, taxPercent field is not present in payload, so use empty string
            // For zero-percent taxes, taxPercent is 0 in payload, but should be empty in signature
            $percent = '';
            if (isset($tax['taxPercent']) && $tax['taxPercent'] !== null) {
                $percentValue = floatval($tax['taxPercent']);
                // Only include taxPercent in signature if it's greater than 0
                // Zero-percent taxes should use empty string (like exempt)
                if ($percentValue > 0) {
                    $percent = number_format($percentValue, 2, '.', ''); // Always 2 decimal places, e.g., "15.50"
                }
                // If taxPercent is 0, $percent remains empty string (treated like exempt)
            }
            // If taxPercent is not present (exempt tax), $percent remains empty string
            
            // 2. taxAmount - in cents (use toCents for currency-specific conversion)
            $taxAmountFloat = floatval($tax['taxAmount'] ?? 0);
            $amountCents = self::toCents($taxAmountFloat, $currency);
            
            // 3. salesAmountWithTax - in cents (use toCents for currency-specific conversion)
            $salesAmountFloat = floatval($tax['salesAmountWithTax'] ?? 0);
            $salesCents = self::toCents($salesAmountFloat, $currency);
            
            // Format: taxPercent || taxAmount || salesAmountWithTax (NO taxCode)
            // This matches zimra-public concatenate_receipt_taxes function exactly
            $taxString = $percent . strval($amountCents) . strval($salesCents);
            $taxStrings[] = $taxString;
            
            // Log tax string construction for debugging
            if (defined('APP_PATH')) {
                $logFile = APP_PATH . '/logs/error.log';
                $logDir = dirname($logFile);
                if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                    $timestamp = date('Y-m-d H:i:s');
                    $logMessage = "[$timestamp] TAX STRING CONSTRUCTION (zimra-public format):\n";
                    $logMessage .= "[$timestamp]   Format: taxPercent (2 decimals, empty if exempt) || taxAmount (cents) || salesAmountWithTax (cents)\n";
                    $logMessage .= "[$timestamp]   taxPercent: '$percent' (from " . ($tax['taxPercent'] ?? 'N/A (exempt)') . ", formatted with 2 decimal places or empty)\n";
                    $logMessage .= "[$timestamp]   taxAmount: '$amountCents' (from " . ($tax['taxAmount'] ?? 'N/A') . " " . $currency . ", converted to cents)\n";
                    $logMessage .= "[$timestamp]   salesAmountWithTax: '$salesCents' (from " . ($tax['salesAmountWithTax'] ?? 'N/A') . " " . $currency . ", converted to cents)\n";
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
            // Also log to nginx error log
            error_log("FISCAL DAY SIGNATURE: deviceID=" . ($fiscalDayData['deviceID'] ?? 'N/A') . ", fiscalDayNo=" . ($fiscalDayData['fiscalDayNo'] ?? 'N/A'));
            error_log("FISCAL DAY SIGNATURE: COMPLETE SIGNATURE STRING: " . $signatureString);
            error_log("FISCAL DAY SIGNATURE: Signature string length: " . strlen($signatureString) . " characters");
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
            // Also log to nginx error log
            error_log("FISCAL DAY SIGNATURE: Generated hash (base64): " . $hashBase64);
            error_log("FISCAL DAY SIGNATURE: Hash length: " . strlen($hash) . " bytes");
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
            // Also log to nginx error log
            $keyTypeMsg = "Key type: $keyType";
            if ($keyType === 'RSA') {
                $keyTypeMsg .= " (" . ($keyDetails['bits'] ?? 'unknown') . " bits)";
            } elseif ($keyType === 'ECC') {
                $keyTypeMsg .= " (curve: " . ($keyDetails['ec']['curve_name'] ?? 'unknown') . ")";
            }
            error_log("FISCAL DAY SIGNATURE: $keyTypeMsg");
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
            // Also log to nginx error log
            error_log("FISCAL DAY SIGNATURE: Final device signature (base64): " . $signatureBase64);
            error_log("FISCAL DAY SIGNATURE: ========== END FISCAL DAY DEVICE SIGNATURE GENERATION ==========");
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
     * Sort counters according to ZIMRA specification
     * This MUST be called before sending counters to ZIMRA to ensure the JSON and signature match
     * PUBLIC method for use in fiscal_service.php
     */
    public static function sortCountersForZimra($counters) {
        // Make a copy to avoid modifying the original (PHP arrays are copied by value)
        $sortedCounters = $counters;
        self::sortCountersInternal($sortedCounters);
        
        // Log sorting for debugging
        if (defined('APP_PATH')) {
            $logFile = APP_PATH . '/logs/error.log';
            $logDir = dirname($logFile);
            if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[$timestamp] ========== COUNTERS SORTING (MATCHING PYTHON) ==========\n";
                $logMessage .= "[$timestamp] Total counters: " . count($sortedCounters) . "\n";
                foreach ($sortedCounters as $idx => $counter) {
                    $type = $counter['fiscalCounterType'] ?? 'N/A';
                    $priority = self::getCounterTypePriority($type);
                    $currency = $counter['fiscalCounterCurrency'] ?? 'N/A';
                    $taxID = isset($counter['fiscalCounterTaxID']) ? $counter['fiscalCounterTaxID'] : 'N/A';
                    $moneyType = $counter['fiscalCounterMoneyType'] ?? 'N/A';
                    $value = $counter['fiscalCounterValue'] ?? 'N/A';
                    $logMessage .= "[$timestamp] [$idx] Type: $type (priority: $priority) | Currency: $currency | TaxID: $taxID | MoneyType: $moneyType | Value: $value\n";
                }
                $logMessage .= "[$timestamp] ====================================================\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
            // Also log to nginx error log
            error_log("COUNTERS SORTING: Total counters: " . count($sortedCounters));
            foreach ($sortedCounters as $idx => $counter) {
                $type = $counter['fiscalCounterType'] ?? 'N/A';
                $priority = self::getCounterTypePriority($type);
                $currency = $counter['fiscalCounterCurrency'] ?? 'N/A';
                $taxID = isset($counter['fiscalCounterTaxID']) ? $counter['fiscalCounterTaxID'] : 'N/A';
                $moneyType = $counter['fiscalCounterMoneyType'] ?? 'N/A';
                $value = $counter['fiscalCounterValue'] ?? 'N/A';
                error_log("COUNTERS SORTING: [$idx] Type: $type (priority: $priority) | Currency: $currency | TaxID: $taxID | MoneyType: $moneyType | Value: $value");
            }
        }
        
        return $sortedCounters;
    }
    
    /**
     * Internal sorting function - extracted to be reusable
     * CRITICAL: This MUST match Python implementation exactly:
     * - Type priority: 1=SaleByTax, 2=SaleTaxByTax, 3=CreditNoteByTax, 4=CreditNoteTaxByTax,
     *   5=DebitNoteByTax, 6=DebitNoteTaxByTax, 7=BalanceByMoneyType
     * - Then: currency (alphabetical), taxID/moneyType
     */
    private static function sortCountersInternal(&$counters) {
        usort($counters, function($a, $b) {
            // 1. Sort by fiscalCounterType using NUMERIC PRIORITY (like Python, NOT alphabetical)
            $typeA = $a['fiscalCounterType'] ?? '';
            $typeB = $b['fiscalCounterType'] ?? '';
            
            $priorityA = self::getCounterTypePriority($typeA);
            $priorityB = self::getCounterTypePriority($typeB);
            
            if ($priorityA !== $priorityB) {
                return $priorityA - $priorityB;
            }
            
            // 2. Sort by currency (alphabetical ascending)
            $currA = strtoupper($a['fiscalCounterCurrency'] ?? '');
            $currB = strtoupper($b['fiscalCounterCurrency'] ?? '');
            if ($currA !== $currB) {
                return strcmp($currA, $currB);
            }
            
            // 3. Determine if these are tax counters or money type counters
            // CRITICAL: Python implementation only sorts by taxID, NOT by taxPercent
            // See Python line 770: x.get('fiscalCounterTaxID', '')
            $hasTaxIDA = isset($a['fiscalCounterTaxID']) && $a['fiscalCounterTaxID'] !== null;
            $hasTaxIDB = isset($b['fiscalCounterTaxID']) && $b['fiscalCounterTaxID'] !== null;
            
            if ($hasTaxIDA || $hasTaxIDB) {
                // Tax counter - sort by taxID numerically only (like Python, NOT by taxPercent)
                $taxIDA = $hasTaxIDA ? intval($a['fiscalCounterTaxID']) : 0;
                $taxIDB = $hasTaxIDB ? intval($b['fiscalCounterTaxID']) : 0;
                if ($taxIDA !== $taxIDB) {
                    return $taxIDA - $taxIDB;
                }
                // If same taxID, use value as tiebreaker for stability (Python doesn't sort by taxPercent)
                $valueA = floatval($a['fiscalCounterValue'] ?? 0);
                $valueB = floatval($b['fiscalCounterValue'] ?? 0);
                if (abs($valueA - $valueB) > 0.001) {
                    return $valueA < $valueB ? -1 : 1;
                }
                return 0;
            } else {
                // Money type counter - sort by moneyType alphabetically
                $moneyA = strtoupper($a['fiscalCounterMoneyType'] ?? '');
                $moneyB = strtoupper($b['fiscalCounterMoneyType'] ?? '');
                $moneyCmp = strcmp($moneyA, $moneyB);
                if ($moneyCmp !== 0) {
                    return $moneyCmp;
                }
                // If same money type, use value as tiebreaker for stability
                $valueA = floatval($a['fiscalCounterValue'] ?? 0);
                $valueB = floatval($b['fiscalCounterValue'] ?? 0);
                if (abs($valueA - $valueB) > 0.001) {
                    return $valueA < $valueB ? -1 : 1;
                }
                return 0;
            }
        });
    }
    
    /**
     * Get numeric priority for counter type (matches Python implementation)
     */
    private static function getCounterTypePriority($type) {
        $typeUpper = strtoupper($type ?? '');
        switch ($typeUpper) {
            case 'SALEBYTAX':
                return 1;
            case 'SALETAXBYTAX':
                return 2;
            case 'CREDITNOTEBYTAX':
                return 3;
            case 'CREDITNOTETAXBYTAX':
                return 4;
            case 'DEBITNOTEBYTAX':
                return 5;
            case 'DEBITNOTETAXBYTAX':
                return 6;
            case 'BALANCEBYMONEYTYPE':
                return 7;
            default:
                return 99; // Unknown types go last
        }
    }
    
    /**
     * Build counters string for signature
     * According to ZIMRA documentation Section 13.3.1:
     * - Counters MUST be pre-sorted by caller (use sortCountersForZimra() first)
     * - Format: fiscalCounterType || fiscalCounterCurrency || fiscalCounterTaxPercent/fiscalCounterMoneyType || fiscalCounterValue
     * - All text in UPPER CASE
     * - Amounts in cents
     * - taxPercent: empty if exempt, "0.00" if 0, "15.00" if 15, "14.50" if 14.5
     */
    private static function buildCountersString($counters) {
        // CRITICAL: Counters should already be sorted by caller (fiscal_service.php calls sortCountersForZimra first)
        // DO NOT re-sort here - it could change the order and break signature validation
        // The counters passed here should be in the EXACT same order as sent to ZIMRA API
        
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
                // Also log to nginx error log
                error_log("FISCAL DAY COUNTER STRING: Type=$type, Currency=$currency, TaxPercent/MoneyType='$percentOrMoneyType', Value=$valueFloat -> $valueCents cents, Counter String: $counterString");
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
            // Also log to nginx error log
            error_log("FISCAL DAY COUNTERS STRING: COMPLETE COUNTERS STRING: " . $result);
            error_log("FISCAL DAY COUNTERS STRING: Length: " . strlen($result) . " characters");
        }
        
        return $result;
    }
}



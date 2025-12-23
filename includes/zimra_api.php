<?php
/**
 * ZIMRA Fiscal Device Gateway API Client
 * Implements the Fiscal Device Gateway API v7.2 specification
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/vendor/autoload.php';

class ZimraApi {
    private $baseUrl;
    private $deviceModelName;
    private $deviceModelVersion;
    private $certificate;
    private $privateKey;
    private $timeout = 30;
    
    // Test environment URL
    const TEST_URL = 'https://fdmsapitest.zimra.co.zw';
    const PRODUCTION_URL = 'https://fdmsapi.zimra.co.zw';
    
    public function __construct($deviceModelName = 'Server', $deviceModelVersion = 'v1', $useTest = true) {
        $this->baseUrl = $useTest ? self::TEST_URL : self::PRODUCTION_URL;
        $this->deviceModelName = $deviceModelName;
        $this->deviceModelVersion = $deviceModelVersion;
    }
    
    /**
     * Set client certificate and private key for authenticated requests
     */
    public function setCertificate($certificatePem, $privateKeyPem) {
        $this->certificate = $certificatePem;
        $this->privateKey = $privateKeyPem;
        
        error_log("ZIMRA API: Certificate set - Cert length: " . strlen($certificatePem) . ", Key length: " . strlen($privateKeyPem));
    }
    
    /**
     * Check if certificate is loaded
     */
    public function hasCertificate() {
        return !empty($this->certificate) && !empty($this->privateKey);
    }
    
    /**
     * Make HTTP request to ZIMRA API
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null, $requiresAuth = false, $includeDeviceHeaders = false) {
        // Endpoint should already include full path (e.g., /Public/v1/{deviceID}/VerifyTaxpayerInformation)
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        
        $ch = curl_init($url);
        
        // Base headers - all endpoints need these
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        // Device headers - required for Device endpoints and registerDevice (Public endpoint)
        // According to Swagger: Device endpoints need DeviceModelName and DeviceModelVersion headers
        // Note: registerDevice is a Public endpoint but still requires these headers (per Swagger)
        // Note: Swagger shows "DeviceModelVersion" not "DeviceModelVersionNo"
        if ($includeDeviceHeaders || strpos($endpoint, '/Device/') !== false || strpos($endpoint, '/User/') !== false || strpos($endpoint, '/ProductsStock/') !== false) {
            $headers[] = 'DeviceModelName: ' . $this->deviceModelName;
            $headers[] = 'DeviceModelVersion: ' . $this->deviceModelVersion;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_VERBOSE => false, // Set to true for debugging
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        
        // Add client certificate if required
        if ($requiresAuth && $this->certificate && $this->privateKey) {
            // Create temporary files for certificate and key
            $certFile = tempnam(sys_get_temp_dir(), 'zimra_cert_');
            $keyFile = tempnam(sys_get_temp_dir(), 'zimra_key_');
            
            file_put_contents($certFile, $this->certificate);
            file_put_contents($keyFile, $this->privateKey);
            
            curl_setopt($ch, CURLOPT_SSLCERT, $certFile);
            curl_setopt($ch, CURLOPT_SSLKEY, $keyFile);
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
        }
        
        if ($data !== null) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            
            // CRITICAL: Log exact JSON being sent for comparison
            if (strpos($endpoint, 'SubmitReceipt') !== false) {
                $logFile = APP_PATH . '/logs/interface_payload_log.txt';
                $logDir = dirname($logFile);
                if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
                    $timestamp = date('Y-m-d H:i:s');
                    $logEntry = "\n========================================\n";
                    $logEntry .= "[$timestamp] INTERFACE PAYLOAD - EXACT JSON SENT TO ZIMRA\n";
                    $logEntry .= "========================================\n";
                    $logEntry .= "Endpoint: $endpoint\n";
                    $logEntry .= "Full URL: $url\n";
                    $logEntry .= "JSON Payload:\n";
                    $logEntry .= $jsonData . "\n";
                    $logEntry .= "========================================\n\n";
                    @file_put_contents($logFile, $logEntry, FILE_APPEND);
                }
                error_log("ZIMRA API PAYLOAD: Exact JSON sent to ZIMRA: " . $jsonData);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        
        // Log certificate usage for debugging
        if ($requiresAuth) {
            if ($this->certificate && $this->privateKey) {
                error_log("ZIMRA API: Using certificate for $endpoint - Cert length: " . strlen($this->certificate) . ", Key length: " . strlen($this->privateKey));
            } else {
                error_log("ZIMRA API: WARNING - Certificate required for $endpoint but not set! Cert: " . ($this->certificate ? 'present' : 'missing') . ", Key: " . ($this->privateKey ? 'present' : 'missing'));
            }
        }
        
        // Clean up temp files
        if (isset($certFile)) {
            @unlink($certFile);
            @unlink($keyFile);
        }
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL Error: " . $error . " (URL: " . $url . ")");
        }
        
        // If httpCode is 0, it means connection failed
        if ($httpCode == 0) {
            throw new Exception("Failed to connect to ZIMRA API. URL: " . $url . " Error: " . ($error ?: "Connection timeout or SSL error"));
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = $responseData['title'] ?? ($responseData['detail'] ?? ($responseData['message'] ?? 'Unknown error'));
            $errorCode = $responseData['errorCode'] ?? 'UNKNOWN';
            $fullError = "ZIMRA API Error ($errorCode): $errorMessage";
            
            // Include validation errors if present
            if (isset($responseData['errors']) && is_array($responseData['errors'])) {
                $fullError .= " | Validation errors: " . json_encode($responseData['errors']);
            }
            
            // Include full response for debugging (truncated)
            if ($responseData) {
                $responseJson = json_encode($responseData);
                $fullError .= " | Full response: " . substr($responseJson, 0, 1000);
            } elseif ($response) {
                $fullError .= " | Raw response: " . substr($response, 0, 1000);
            }
            
            throw new Exception($fullError, $httpCode);
        }
        
        if (!$responseData && $response) {
            // Response might not be JSON
            throw new Exception("Invalid JSON response from ZIMRA API. HTTP Code: $httpCode. Response: " . substr($response, 0, 500));
        }
        
        return $responseData;
    }
    
    /**
     * 4.1. verifyTaxpayerInformation
     * Correct endpoint: POST /Public/v1/{deviceID}/VerifyTaxpayerInformation
     */
    public function verifyTaxpayerInformation($deviceID, $activationKey, $deviceSerialNo) {
        $endpoint = '/Public/v1/' . intval($deviceID) . '/VerifyTaxpayerInformation';
        
        return $this->makeRequest($endpoint, 'POST', [
            'activationKey' => $activationKey,
            'deviceSerialNo' => $deviceSerialNo
        ], false);
    }
    
    /**
     * 4.2. registerDevice
     * Public endpoint: POST /Public/v1/{deviceID}/RegisterDevice
     * Note: This endpoint requires DeviceModelName and DeviceModelVersion headers
     */
    public function registerDevice($deviceID, $activationKey, $certificateRequest) {
        $endpoint = '/Public/v1/' . intval($deviceID) . '/RegisterDevice';
        
        // Based on testing: Method 1 (direct CSR, let json_encode handle newlines) works better
        // json_encode will automatically convert actual newlines to \n in JSON
        // This matches what ZIMRA expects based on the error message format
        // Clean the CSR first - ensure proper PEM format
        $csr = trim($certificateRequest);
        
        return $this->makeRequest($endpoint, 'POST', [
            'activationKey' => $activationKey,
            'certificateRequest' => $csr
        ], false, true); // Pass true to include device headers for this Public endpoint
    }
    
    /**
     * 4.3. issueCertificate
     * Device endpoint: POST /Device/v1/{deviceID}/IssueCertificate
     */
    public function issueCertificate($deviceID, $certificateRequest) {
        $endpoint = '/Device/v1/' . intval($deviceID) . '/IssueCertificate';
        
        return $this->makeRequest($endpoint, 'POST', [
            'certificateRequest' => $certificateRequest
        ], true);
    }
    
    /**
     * 4.4. getConfig
     * Device endpoint: GET /Device/v1/{deviceID}/GetConfig
     */
    public function getConfig($deviceID) {
        $endpoint = '/Device/v1/' . intval($deviceID) . '/GetConfig';
        
        return $this->makeRequest($endpoint, 'GET', null, true);
    }
    
    /**
     * 4.5. getStatus
     * Device endpoint: GET /Device/v1/{deviceID}/GetStatus
     */
    public function getStatus($deviceID) {
        $endpoint = '/Device/v1/' . intval($deviceID) . '/GetStatus';
        
        return $this->makeRequest($endpoint, 'GET', null, true);
    }
    
    /**
     * 4.6. openDay
     * Device endpoint: POST /Device/v1/{deviceID}/OpenDay
     */
    public function openDay($deviceID, $fiscalDayOpened, $fiscalDayNo = null) {
        $endpoint = '/Device/v1/' . intval($deviceID) . '/OpenDay';
        
        // Convert date to ISO 8601 format if it's not already
        if (is_string($fiscalDayOpened) && strpos($fiscalDayOpened, 'T') === false) {
            $date = new DateTime($fiscalDayOpened);
            $fiscalDayOpened = $date->format('Y-m-d\TH:i:s');
        }
        
        // Based on Swagger, the request body should have fiscalDayOpened directly
        // but the error suggests it needs openDayRequest wrapper
        // Try flat structure first
        $data = [
            'fiscalDayOpened' => $fiscalDayOpened
        ];
        
        if ($fiscalDayNo !== null) {
            $data['fiscalDayNo'] = intval($fiscalDayNo);
        }
        
        return $this->makeRequest($endpoint, 'POST', $data, true);
    }
    
    /**
     * 4.7. submitReceipt
     * Device endpoint: POST /Device/v1/{deviceID}/SubmitReceipt
     * Note: Request body should have 'receipt' wrapper according to Swagger
     */
    public function submitReceipt($deviceID, $receipt) {
        $endpoint = '/Device/v1/' . intval($deviceID) . '/SubmitReceipt';
        
        // Wrap receipt in 'receipt' field (lowercase) to match ZIMRA API specification
        // API spec shows: {"receipt": {...}}
        $requestBody = [
            'receipt' => $receipt
        ];
        
        return $this->makeRequest($endpoint, 'POST', $requestBody, true);
    }
    
    /**
     * 4.10. closeDay
     * Device endpoint: POST /Device/v1/{deviceID}/CloseDay
     */
    public function closeDay($deviceID, $fiscalDayNo, $fiscalDayCounters, $fiscalDayDeviceSignature, $receiptCounter) {
        $endpoint = '/Device/v1/' . intval($deviceID) . '/CloseDay';
        
        return $this->makeRequest($endpoint, 'POST', [
            'fiscalDayNo' => intval($fiscalDayNo),
            'fiscalDayCounters' => $fiscalDayCounters,
            'fiscalDayDeviceSignature' => $fiscalDayDeviceSignature,
            'receiptCounter' => intval($receiptCounter)
        ], true);
    }
    
    /**
     * 4.11. getServerCertificate
     * Public endpoint: GET /Public/v1/GetServerCertificate
     */
    public function getServerCertificate($thumbprint = null) {
        $endpoint = '/Public/v1/GetServerCertificate';
        
        // GET request with query parameter if thumbprint provided
        if ($thumbprint !== null) {
            $endpoint .= '?thumbprint=' . urlencode($thumbprint);
        }
        
        return $this->makeRequest($endpoint, 'GET', null, false);
    }
    
    /**
     * 4.13. ping
     * Device endpoint: POST /Device/v1/{deviceID}/Ping
     */
    public function ping($deviceID) {
        $endpoint = '/Device/v1/' . intval($deviceID) . '/Ping';
        
        // Ping has empty body
        return $this->makeRequest($endpoint, 'POST', null, true);
    }
    
    /**
     * 4.9. submitFile (for offline mode)
     * Device endpoint: POST /Device/v1/{deviceID}/SubmitFile
     */
    public function submitFile($deviceID, $fileContent) {
        // This endpoint uses multipart/form-data
        // fileContent should be the JSON file content (not base64 encoded)
        $endpoint = '/Device/v1/' . intval($deviceID) . '/SubmitFile';
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        
        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'zimra_file_');
        file_put_contents($tempFile, $fileContent);
        
        $ch = curl_init($url);
        
        $headers = [
            'DeviceModelName: ' . $this->deviceModelName,
            'DeviceModelVersion: ' . $this->deviceModelVersion, // Note: Swagger shows "DeviceModelVersion" not "DeviceModelVersionNo"
        ];
        
        // deviceID is in path, not in form data
        $postFields = [
            'file' => new CURLFile($tempFile, 'application/json', 'fiscal_data.json')
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        if ($this->certificate && $this->privateKey) {
            $certFile = tempnam(sys_get_temp_dir(), 'zimra_cert_');
            $keyFile = tempnam(sys_get_temp_dir(), 'zimra_key_');
            
            file_put_contents($certFile, $this->certificate);
            file_put_contents($keyFile, $this->privateKey);
            
            curl_setopt($ch, CURLOPT_SSLCERT, $certFile);
            curl_setopt($ch, CURLOPT_SSLKEY, $keyFile);
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Clean up temp files
        @unlink($tempFile);
        if (isset($certFile)) {
            @unlink($certFile);
            @unlink($keyFile);
        }
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL Error: " . $error);
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = $responseData['title'] ?? 'Unknown error';
            $errorCode = $responseData['errorCode'] ?? 'UNKNOWN';
            throw new Exception("ZIMRA API Error ($errorCode): $errorMessage", $httpCode);
        }
        
        return $responseData;
    }
    
    /**
     * 4.10. getFileStatus
     */
    public function getFileStatus($deviceID, $fileUploadedFrom, $fileUploadedTill, $operationID = null) {
        $data = [
            'deviceID' => intval($deviceID),
            'fileUploadedFrom' => $fileUploadedFrom,
            'fileUploadedTill' => $fileUploadedTill
        ];
        
        if ($operationID !== null) {
            $data['operationID'] = $operationID;
        }
        
        return $this->makeRequest('/api/getFileStatus', 'POST', $data, true);
    }
    
    /**
     * Get Submitted File List
     * Device endpoint: GET /Device/v1/{deviceID}/SubmittedFileList
     * According to Swagger, this accepts query parameters for filtering
     */
    public function getSubmittedFileList($deviceID, $filters = []) {
        $endpoint = '/Device/v1/' . intval($deviceID) . '/SubmittedFileList';
        
        // Add query parameters if filters provided
        if (!empty($filters)) {
            $queryParams = [];
            foreach ($filters as $key => $value) {
                $queryParams[] = urlencode($key) . '=' . urlencode($value);
            }
            if (!empty($queryParams)) {
                $endpoint .= '?' . implode('&', $queryParams);
            }
        }
        
        return $this->makeRequest($endpoint, 'GET', null, true);
    }
}


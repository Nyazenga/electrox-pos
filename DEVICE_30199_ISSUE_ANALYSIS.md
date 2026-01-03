# Device 30199 Fiscal Day Opening Issue - Root Cause Analysis

## Error Message
```
Error opening fiscal day: Could not retrieve fiscal day status from ZIMRA. Please try again.
```

## Device Configuration Status
- ✅ Device ID: 30199
- ✅ Branch ID: 1
- ✅ Registered: Yes (is_registered = 1)
- ✅ Active: Yes (is_active = 1)
- ✅ Certificate: Present (1467 bytes)
- ✅ Private Key: Present (1704 bytes)
- ✅ Certificate Valid Until: 2026-12-29 (360 days remaining)

## Code Flow Analysis

### 1. Error Location
The error is thrown in `modules/settings/fiscalization.php` line 129:
```php
$status = $fiscalService->getFiscalDayStatus();
if (!$status || !isset($status['fiscalDayStatus'])) {
    throw new Exception('Could not retrieve fiscal day status from ZIMRA. Please try again.');
}
```

### 2. getFiscalDayStatus() Method
Located in `includes/fiscal_service.php` lines 347-370:
```php
public function getFiscalDayStatus() {
    try {
        $response = $this->api->getStatus($this->deviceId);
        // ... sync logic ...
        return $response;
    } catch (Exception $e) {
        error_log("FISCAL DAY STATUS ERROR: " . get_class($e) . " - " . $e->getTraceAsString());
        return null;  // ← This causes the error message
    }
}
```

### 3. Possible Root Causes

#### A. Certificate Loading Issue
**Location**: `includes/fiscal_service.php` lines 71-103

**Issue**: `CertificateStorage::loadCertificate()` may return null even though certificate exists.

**Check**:
```sql
SELECT device_id, is_registered, 
       certificate_pem IS NOT NULL as has_cert,
       private_key_pem IS NOT NULL as has_key
FROM fiscal_devices 
WHERE device_id = 30199 AND is_registered = 1;
```

**If CertificateStorage returns null but certificate exists**:
- The fallback code (lines 81-100) should handle this
- But if decryption fails silently, certificate won't be set

#### B. API Call Failure
**Location**: `includes/zimra_api.php` line 246

**Possible failures**:
1. **401 Unauthorized**: Certificate authentication failed
   - Certificate expired (but we verified it's valid until 2026-12-29)
   - Certificate revoked by ZIMRA
   - Certificate CN doesn't match device ID
   - Certificate format invalid

2. **404 Not Found**: Device not registered with ZIMRA
   - Device exists in local DB but not in ZIMRA system
   - Device ID mismatch

3. **Network Error**: Cannot reach ZIMRA API
   - Connection timeout
   - DNS resolution failure
   - Firewall blocking
   - SSL handshake failure

4. **Unexpected Response**: API returns success but missing `fiscalDayStatus` field
   - API response format changed
   - API version mismatch

## Diagnostic Steps

### Step 1: Check Error Logs
```bash
# On server
grep -i "FISCAL DAY STATUS ERROR" /var/log/php8.3-fpm.log | tail -20
grep -i "30199" /var/log/php8.3-fpm.log | tail -20
```

### Step 2: Test Certificate Loading
```php
$certData = CertificateStorage::loadCertificate(30199);
if (!$certData) {
    // Check why it failed
    $device = $db->getRow(
        "SELECT certificate_pem, private_key_pem, is_registered 
         FROM fiscal_devices 
         WHERE device_id = 30199 AND is_registered = 1"
    );
    // Debug...
}
```

### Step 3: Test Direct API Call
```php
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($cert, $key);
try {
    $status = $api->getStatus(30199);
    // Check response...
} catch (Exception $e) {
    // This is the actual error!
    echo $e->getMessage();
}
```

### Step 4: Verify Certificate Validity
```bash
# Extract certificate from database and check
openssl x509 -in certificate.pem -text -noout
# Check:
# - Subject CN matches device ID format
# - Not expired
# - Valid signature
```

## Most Likely Causes (Based on Evidence)

### 1. Certificate Authentication Failure (401)
**Probability: HIGH**

**Evidence**:
- Certificate exists and is valid
- Device is registered
- Error occurs during API call

**Solution**:
- Verify certificate CN matches device ID
- Check if certificate was revoked
- Re-issue certificate if needed

### 2. CertificateStorage Loading Issue
**Probability: MEDIUM**

**Evidence**:
- Certificate exists in database
- `CertificateStorage::loadCertificate()` may fail silently
- Fallback code may not work correctly

**Solution**:
- Check if `is_registered = 1` in query
- Verify private key decryption works
- Test fallback path

### 3. Network/API Connectivity
**Probability: LOW**

**Evidence**:
- Other devices may work
- Certificate is valid

**Solution**:
- Test network connectivity to ZIMRA API
- Check firewall rules
- Verify DNS resolution

## Immediate Action Items

1. **Check PHP Error Logs** for actual exception message
2. **Run diagnostic script** to identify exact failure point
3. **Test direct API call** bypassing FiscalService
4. **Verify certificate CN** matches device ID format
5. **Check ZIMRA API status** (if available)

## Files to Check

1. `/var/log/php8.3-fpm.log` - PHP error logs
2. `/var/log/nginx/error.log` - Web server errors
3. `includes/fiscal_service.php` - FiscalService class
4. `includes/certificate_storage.php` - Certificate loading
5. `includes/zimra_api.php` - API client

## Next Steps

1. Run `diagnose_device_30199.php` on server to get exact error
2. Check error logs for "FISCAL DAY STATUS ERROR" entries
3. Test certificate loading manually
4. Test direct API call to identify failure point
5. Fix the identified issue


# Fiscal Day Open Error Diagnosis

## Error Message
```
Error opening fiscal day: Could not retrieve fiscal day status from ZIMRA. Please try again.
```

## Root Cause
This error occurs when `getFiscalDayStatus()` fails to retrieve the fiscal day status from ZIMRA API. The method returns `null` when an exception is caught, or the response doesn't contain the `fiscalDayStatus` field.

## Possible Causes & Solutions

### 1. **Certificate Not Loaded or Missing** ⚠️ MOST COMMON

**Symptoms:**
- Error log shows: `"FISCAL SERVICE: WARNING - No certificate found for device {deviceId}"`
- Error log shows: `"ZIMRA API: WARNING - Certificate required for /Device/v1/{deviceID}/GetStatus but not set!"`

**How to Check:**
1. Check PHP error logs for certificate-related warnings
2. Verify certificate exists in database:
   ```sql
   SELECT device_id, certificate_pem IS NOT NULL as has_cert, 
          private_key_pem IS NOT NULL as has_key, is_registered
   FROM fiscal_devices 
   WHERE branch_id = [YOUR_BRANCH_ID];
   ```

**Solution:**
- If device is not registered: Register the device first (this will generate and save the certificate)
- If certificate is missing: Re-register the device or restore from backup
- Check `certificate_storage` table for encrypted certificates

---

### 2. **Certificate Expired or Invalid**

**Symptoms:**
- API returns 401 Unauthorized
- Error log shows: `"ZIMRA API Error (AUTH01): Unauthorized"` or similar

**How to Check:**
1. Check certificate expiration:
   ```sql
   SELECT device_id, certificate_valid_till 
   FROM certificate_storage 
   WHERE device_id = [YOUR_DEVICE_ID];
   ```
2. Or check device record:
   ```sql
   SELECT device_id, certificate_valid_till 
   FROM fiscal_devices 
   WHERE device_id = [YOUR_DEVICE_ID];
   ```

**Solution:**
- If expired: Re-issue certificate using `issueCertificate()` API endpoint
- If invalid: Re-register the device

---

### 3. **Network/Connection Issues**

**Symptoms:**
- Error log shows: `"Failed to connect to ZIMRA API"` or `"CURL Error: Connection timeout"`
- Error log shows: `"HTTP Code: 0"`

**How to Check:**
1. Test connectivity from server:
   ```bash
   curl -v https://fdmsapitest.zimra.co.zw/Public/v1/GetServerCertificate
   ```
2. Check firewall rules
3. Check DNS resolution

**Solution:**
- Verify server can reach `fdmsapitest.zimra.co.zw` (or production URL)
- Check firewall allows outbound HTTPS connections
- Verify DNS resolution works
- Check if server time is synchronized (NTP)

---

### 4. **Device Not Registered or Invalid Device ID**

**Symptoms:**
- API returns 404 Not Found
- Error log shows: `"ZIMRA API Error (DEV01): Device not found"`

**How to Check:**
1. Verify device exists and is registered:
   ```sql
   SELECT device_id, is_registered, activation_key, device_serial_no
   FROM fiscal_devices 
   WHERE branch_id = [YOUR_BRANCH_ID] AND is_active = 1;
   ```

**Solution:**
- If `is_registered = 0`: Register the device first
- If device ID is wrong: Update `device_id` in `fiscal_devices` table
- Verify device ID matches ZIMRA records

---

### 5. **Unexpected API Response Format**

**Symptoms:**
- API call succeeds but response doesn't have `fiscalDayStatus` field
- Error log shows successful API call but no status field

**How to Check:**
1. Check error logs for the actual API response:
   ```bash
   grep "FISCAL DAY STATUS" /var/log/php/error.log
   ```

**Solution:**
- Check ZIMRA API documentation for response format changes
- Verify API version matches expected format
- Contact ZIMRA support if response format changed

---

### 6. **SSL/Certificate Authentication Failure**

**Symptoms:**
- Error log shows: `"ZIMRA API Error (AUTH01): Unauthorized"`
- HTTP 401 response

**How to Check:**
1. Verify certificate is properly formatted:
   ```php
   // Certificate should start with:
   // -----BEGIN CERTIFICATE-----
   // And end with:
   // -----END CERTIFICATE-----
   ```
2. Check certificate matches device ID:
   ```sql
   SELECT device_id, 
          SUBSTRING(certificate_pem, 1, 50) as cert_start
   FROM fiscal_devices 
   WHERE device_id = [YOUR_DEVICE_ID];
   ```

**Solution:**
- Verify certificate CN (Common Name) matches device ID format
- Re-issue certificate if CN doesn't match
- Check certificate chain is valid

---

### 7. **Database Connection Issues**

**Symptoms:**
- Error log shows database connection errors
- `FiscalService` constructor fails

**How to Check:**
1. Verify database connection works
2. Check `fiscal_devices` table exists and is accessible

**Solution:**
- Fix database connection issues
- Verify `fiscal_devices` table structure is correct

---

## Diagnostic Steps

### Step 1: Check Error Logs
```bash
# On server, check PHP error logs
tail -n 100 /var/log/php/error.log | grep -i "fiscal\|zimra"

# Look for:
# - "FISCAL SERVICE: WARNING"
# - "ZIMRA API: WARNING"
# - "FISCAL DAY STATUS ERROR"
# - "CURL Error"
```

### Step 2: Verify Device Configuration
```sql
SELECT 
    fd.device_id,
    fd.branch_id,
    fd.is_registered,
    fd.is_active,
    fd.device_serial_no,
    CASE 
        WHEN fd.certificate_pem IS NULL THEN 'Missing'
        WHEN cs.certificate IS NULL THEN 'Not in storage'
        ELSE 'Present'
    END as certificate_status,
    cs.valid_till as cert_expiry
FROM fiscal_devices fd
LEFT JOIN certificate_storage cs ON fd.device_id = cs.device_id
WHERE fd.branch_id = [YOUR_BRANCH_ID];
```

### Step 3: Test API Connection Manually
Create a test script to verify API connectivity:
```php
<?php
require_once 'includes/zimra_api.php';
require_once 'includes/certificate_storage.php';

$deviceId = [YOUR_DEVICE_ID];
$api = new ZimraApi('Server', 'v1', true);

// Load certificate
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    echo "✓ Certificate loaded\n";
} else {
    echo "✗ Certificate not found\n";
    exit;
}

// Test getStatus
try {
    $status = $api->getStatus($deviceId);
    echo "✓ API call successful\n";
    echo "Response: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "✗ API call failed: " . $e->getMessage() . "\n";
}
```

### Step 4: Check Network Connectivity
```bash
# Test DNS resolution
nslookup fdmsapitest.zimra.co.zw

# Test HTTPS connectivity
curl -v https://fdmsapitest.zimra.co.zw/Public/v1/GetServerCertificate

# Check server time (important for SSL)
date
```

---

## Quick Fix Checklist

- [ ] Verify device is registered (`is_registered = 1`)
- [ ] Check certificate exists in database
- [ ] Verify certificate is not expired
- [ ] Test network connectivity to ZIMRA API
- [ ] Check PHP error logs for detailed error messages
- [ ] Verify device ID is correct
- [ ] Check server time is synchronized
- [ ] Verify firewall allows outbound HTTPS

---

## Common Error Messages & Meanings

| Error Message | Meaning | Solution |
|--------------|---------|----------|
| `"No certificate found"` | Certificate not loaded | Register device or restore certificate |
| `"Failed to connect"` | Network issue | Check connectivity, firewall, DNS |
| `"401 Unauthorized"` | Certificate invalid/expired | Re-issue or re-register device |
| `"404 Not Found"` | Device not registered | Register the device |
| `"Connection timeout"` | Network timeout | Check network, increase timeout |
| `"SSL error"` | Certificate/SSL issue | Verify certificate format and validity |

---

## Prevention

1. **Regular Certificate Monitoring**: Set up alerts for certificate expiration
2. **Health Checks**: Implement periodic API connectivity tests
3. **Error Logging**: Ensure comprehensive error logging is enabled
4. **Backup Certificates**: Regularly backup certificates securely
5. **Network Monitoring**: Monitor network connectivity to ZIMRA API

---

## Need More Help?

If the issue persists after checking all above:
1. Check ZIMRA API status page (if available)
2. Contact ZIMRA support with:
   - Device ID
   - Error logs
   - Certificate status
   - Network connectivity test results
3. Review ZIMRA API documentation for any recent changes


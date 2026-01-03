# Device 30199 API Test Script - Usage Guide

## Script Created
**File**: `test_device_30199_api_direct.php`

## Purpose
This script directly tests the ZIMRA API call for device 30199 and captures the **exact error message** to diagnose why fiscal day opening fails.

## What It Does

1. **Checks Device Configuration**
   - Verifies device exists in database
   - Checks registration status
   - Validates device is active

2. **Loads Certificate**
   - Tries `CertificateStorage::loadCertificate()` first
   - Falls back to device record if needed
   - Attempts decryption if private key is encrypted
   - Validates certificate format and expiration

3. **Initializes API Client**
   - Creates ZimraApi instance
   - Sets certificate for authentication
   - Verifies certificate is properly loaded

4. **Makes Direct API Call**
   - Calls `getStatus()` directly (bypassing FiscalService)
   - Captures **exact error message** if it fails
   - Shows full response if successful

5. **Tests via FiscalService**
   - Tests the same call through FiscalService
   - Compares results with direct API call
   - Identifies if issue is in FiscalService or API itself

6. **Provides Detailed Analysis**
   - Analyzes error type (401, 404, network, etc.)
   - Provides specific recommendations
   - Shows full error trace

## How to Run

### On Server (Recommended)
```bash
cd /var/www/electro-pos
php test_device_30199_api_direct.php
```

### On Localhost
```bash
cd C:\xampp\htdocs\electrox-pos
php test_device_30199_api_direct.php
```

## Expected Output

### If API Call Succeeds:
```
✓✓✓ DIRECT API CALL SUCCESS! ✓✓✓
Response time: XXXms

Response Data:
{
    "fiscalDayStatus": "FiscalDayClosed",
    "lastFiscalDayNo": 123,
    ...
}

✓ Fiscal Day Status: FiscalDayClosed
```

### If API Call Fails:
```
✗✗✗ DIRECT API CALL FAILED! ✗✗✗

EXACT ERROR MESSAGE:
--------------------------------------------------
ZIMRA API Error (AUTH01): Unauthorized
--------------------------------------------------

Error Type: Exception
Error Code: 401

=== ERROR ANALYSIS ===
DIAGNOSIS: Certificate Authentication Failed (401 Unauthorized)

Possible causes:
  1. Certificate expired
  2. Certificate revoked by ZIMRA
  3. Certificate CN doesn't match device ID
  ...

=== FULL ERROR TRACE ===
[Full stack trace]
```

## What to Look For

1. **If Direct API Fails:**
   - The exact error message will tell you the root cause
   - 401 = Certificate authentication issue
   - 404 = Device not found/not registered
   - Connection errors = Network issue

2. **If Direct API Succeeds but FiscalService Fails:**
   - Issue is in FiscalService certificate loading
   - Check CertificateStorage::loadCertificate()
   - Check FiscalService constructor

3. **If Both Fail:**
   - Issue is with the API call itself
   - Check certificate validity
   - Check network connectivity
   - Check ZIMRA API status

## Next Steps After Running

1. **Copy the exact error message** from the output
2. **Check the error analysis section** for diagnosis
3. **Follow the recommended actions** provided
4. **If 401 error**: Contact ZIMRA or re-issue certificate
5. **If 404 error**: Verify device registration
6. **If network error**: Check connectivity and firewall

## Troubleshooting

### Script doesn't run:
- Check PHP is installed: `php -v`
- Check file permissions: `chmod +x test_device_30199_api_direct.php`
- Check config.php exists and is correct

### No output:
- Check PHP error reporting is enabled
- Check error logs: `/var/log/php8.3-fpm.log`
- Run with: `php -d display_errors=1 test_device_30199_api_direct.php`

### Certificate not found:
- Verify device 30199 exists in database
- Check `is_registered = 1` in fiscal_devices table
- Verify certificate_pem and private_key_pem are not NULL

## Files Created

1. `test_device_30199_api_direct.php` - Main test script
2. `DEVICE_30199_ISSUE_ANALYSIS.md` - Detailed analysis document
3. `FISCAL_DAY_OPEN_ERROR_DIAGNOSIS.md` - General diagnosis guide

## Support

After running the script, share:
1. The exact error message
2. The error analysis section
3. Whether direct API or FiscalService failed
4. Any additional error logs

This will help identify the exact root cause and solution.


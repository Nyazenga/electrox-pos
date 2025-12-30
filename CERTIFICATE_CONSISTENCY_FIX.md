# Certificate Consistency Fix - BadCertificateSignature Prevention

## Problem
The `BadCertificateSignature` error occurs when the certificate used to sign the fiscal day close request doesn't match what ZIMRA expects. This can happen when:
- Different certificates are used for receipts vs closing
- Certificate was renewed/re-registered but old certificate is still cached
- Certificate storage is inconsistent

## Solution Implemented

### 1. Certificate Storage Consistency
- **All operations now use `CertificateStorage::loadCertificate()`** to ensure the same certificate is used
- Certificate is loaded fresh from storage before each operation (no caching)
- Certificate fingerprint is logged and verified for consistency

### 2. Certificate Validation
Added `validateCertificateForOperation()` method that:
- Verifies private key is valid
- Verifies certificate matches private key
- Checks certificate expiration
- Logs certificate fingerprint for debugging
- Throws clear errors if validation fails

### 3. Operations Using Certificate Validation
All fiscal operations now validate certificate before use:
- ✅ **Fiscal Day Opening** - Validates before opening
- ✅ **Receipt Submission** - Validates before submitting
- ✅ **Fiscal Day Closing** - Validates before closing

### 4. Re-registration Script
Created `re_register_and_validate_certificate.php` that:
- Clears old certificate data
- Generates new CSR
- Registers device with ZIMRA
- Saves certificate to CertificateStorage
- Verifies certificate consistency
- Tests fiscal day operations

## How to Use

### After Resetting Activation Key:

1. **Re-register the device:**
   ```
   https://nedcom.co.zw/re_register_and_validate_certificate.php?branch_id=1
   ```

2. **Verify certificate consistency:**
   ```
   https://nedcom.co.zw/verify_certificate_consistency.php?branch_id=1
   ```

3. **Test fiscal day operations:**
   - Open fiscal day
   - Submit receipts
   - Close fiscal day

## What Was Changed

### Files Modified:
1. **`includes/fiscal_service.php`**
   - Added `validateCertificateForOperation()` method
   - Added certificate validation before all fiscal operations
   - Added certificate fingerprint tracking for consistency

### Files Created:
1. **`re_register_and_validate_certificate.php`**
   - Comprehensive re-registration script
   - Validates certificate after registration
   - Ensures consistency

2. **`verify_certificate_consistency.php`**
   - Verifies certificate is used consistently
   - Checks all storage locations
   - Validates certificate/private key match

## Prevention Measures

### Automatic Checks:
1. **Certificate loaded fresh** from CertificateStorage before each operation
2. **Certificate validated** before use (matches private key, not expired)
3. **Fingerprint logged** for debugging and consistency tracking
4. **Clear error messages** if certificate issues are detected

### Manual Checks:
1. **Run verification script** after re-registration
2. **Check logs** for certificate fingerprints
3. **Monitor certificate expiration** (warnings at 30 days)

## Certificate Flow

```
1. Device Registration
   └─> Certificate saved to CertificateStorage
   
2. Fiscal Day Open
   └─> Load from CertificateStorage
   └─> Validate certificate
   └─> Use for API calls
   
3. Receipt Submission
   └─> Load from CertificateStorage (fresh)
   └─> Validate certificate
   └─> Use for API calls
   
4. Fiscal Day Close
   └─> Load from CertificateStorage (fresh)
   └─> Validate certificate
   └─> Use for API calls
```

**All operations use the SAME certificate from CertificateStorage**

## Troubleshooting

### If BadCertificateSignature still occurs:

1. **Verify certificate consistency:**
   ```
   verify_certificate_consistency.php?branch_id=1
   ```

2. **Re-register device:**
   ```
   re_register_and_validate_certificate.php?branch_id=1
   ```

3. **Check logs** for certificate fingerprints:
   - All operations should show the same fingerprint
   - If different, certificate storage is inconsistent

4. **Check certificate expiration:**
   - Certificate must not be expired
   - Renew if expiring soon (< 30 days)

## Important Notes

- **CertificateStorage is the single source of truth** for certificates
- **All operations reload certificate** from CertificateStorage (no caching)
- **Certificate is validated** before each operation
- **Fingerprint is logged** for debugging
- **Same certificate** is used for receipts and closing

This ensures that BadCertificateSignature errors should not occur again.


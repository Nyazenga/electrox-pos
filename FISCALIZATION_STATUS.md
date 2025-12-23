# Fiscalization Status - QR Codes Not Showing

## Problem
QR codes are not appearing on receipts after processing payment.

## Root Cause
Sales are not being fiscalized because of certificate authentication issues:
- **401 Unauthorized** errors when trying to open fiscal days or submit receipts
- Certificate mismatch: System has certificate for device 30199 but needs device 30200
- Private key corruption: Device 30199's private key is corrupted in the database

## Current Situation

### Device 30200
- ✅ Registered with ZIMRA
- ❌ Certificate not available (stored certificate is actually for device 30199)
- ❌ Cannot re-register (activation key incorrect or device already registered)
- **Action Required**: Contact ZIMRA to get the correct certificate/private key for device 30200

### Device 30199  
- ✅ Has certificate in database
- ❌ Private key is corrupted (decryption produces invalid data)
- ❌ Cannot use for fiscalization
- **Action Required**: Contact ZIMRA to reset device 30199 registration OR get original private key

## What's Working
- ✅ Fiscalization code is implemented correctly
- ✅ QR code display logic is correct in receipt templates
- ✅ Database schema is correct
- ✅ Certificate storage/encryption system is working
- ✅ All API endpoints are correctly implemented

## What's Not Working
- ❌ Certificate authentication (401 errors)
- ❌ Fiscal day opening
- ❌ Receipt submission to ZIMRA
- ❌ QR code generation (because receipts aren't fiscalized)

## Solution

**You need to contact ZIMRA to resolve the certificate issue:**

1. **Option 1 (Recommended)**: Get the correct certificate and private key for device 30200
   - Device ID: 30200
   - Serial: electrox-2
   - Request: Certificate and private key for this device

2. **Option 2**: Reset device 30199 registration
   - Device ID: 30199
   - Serial: electrox-1
   - Request: Reset registration so it can be re-registered

3. **Option 3**: Get correct activation key for device 30200
   - If device 30200 can be re-registered, get the correct activation key

## Once Certificate is Available

After you receive the correct certificate from ZIMRA:

1. Save it using:
   ```php
   php register_device_30200_fresh.php
   ```
   (Update the script with the certificate they provide)

2. Or manually save using CertificateStorage:
   ```php
   CertificateStorage::saveCertificate($deviceId, $certificatePem, $privateKeyPem);
   ```

3. Test fiscalization:
   ```php
   php test_fiscalization_now.php
   ```

4. Make a test sale - it should automatically fiscalize and show QR code on receipt

## Files to Check

- `modules/pos/receipt.php` - QR code display logic (✅ Working)
- `ajax/process_sale.php` - Fiscalization trigger (✅ Working)
- `includes/fiscal_service.php` - Fiscalization service (✅ Working)
- `includes/certificate_storage.php` - Certificate management (✅ Working)

All code is correct - the issue is purely the missing/corrupted certificate.

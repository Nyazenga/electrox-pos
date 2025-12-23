# Certificate Resolution Plan

## Current Situation

✅ **Credentials Confirmed:**
- Device 30199: Serial `electrox-1`, Activation Key `00544726`
- Device 30200: Serial `electrox-2`, Activation Key `00294543`

❌ **Problem:**
- Both devices are already registered with ZIMRA
- We don't have valid certificates for either device
- Cannot re-register (devices already exist in ZIMRA system)
- Cannot issue new certificates (requires current certificate to authenticate)

## Why QR Codes Aren't Showing

1. Sales are not being fiscalized (401 Unauthorized errors)
2. Fiscalization fails because we don't have valid certificates
3. No fiscalization = No QR codes on receipts

## Solution Options

### Option 1: Request Certificates from ZIMRA (Recommended)

**Contact ZIMRA Support and request:**

"Hello, I have two fiscal devices registered in your system:
- Device ID: 30199, Serial: electrox-1, Activation Key: 00544726
- Device ID: 30200, Serial: electrox-2, Activation Key: 00294543

These devices are registered but I don't have the X.509 certificates and private keys. Can you provide:
1. The device certificates (PEM format)
2. The corresponding private keys (PEM format)

I need these to enable fiscalization in my POS system."

### Option 2: Reset Device Registration

**Contact ZIMRA Support and request:**

"Hello, I need to reset the registration for my fiscal devices so I can complete the registration process and obtain certificates:
- Device ID: 30199, Serial: electrox-1, Activation Key: 00544726
- Device ID: 30200, Serial: electrox-2, Activation Key: 00294543

Can you reset these devices so I can register them again?"

### Option 3: Check ZIMRA Portal

If ZIMRA has a web portal, check if you can:
- Download existing certificates
- View device certificates
- Export certificate/private key pairs

## Once You Have Certificates

### Step 1: Save Certificates

Create a file `save_certificates.php`:

```php
<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/certificate_storage.php';

// Device 30199
$deviceId = 30199;
$certificatePem = "-----BEGIN CERTIFICATE-----\n[PASTE CERTIFICATE FROM ZIMRA]\n-----END CERTIFICATE-----";
$privateKeyPem = "-----BEGIN PRIVATE KEY-----\n[PASTE PRIVATE KEY FROM ZIMRA]\n-----END PRIVATE KEY-----";

CertificateStorage::saveCertificate($deviceId, $certificatePem, $privateKeyPem);
echo "✓ Certificate saved for device $deviceId\n";

// Device 30200
$deviceId = 30200;
$certificatePem = "-----BEGIN CERTIFICATE-----\n[PASTE CERTIFICATE FROM ZIMRA]\n-----END CERTIFICATE-----";
$privateKeyPem = "-----BEGIN PRIVATE KEY-----\n[PASTE PRIVATE KEY FROM ZIMRA]\n-----END PRIVATE KEY-----";

CertificateStorage::saveCertificate($deviceId, $certificatePem, $privateKeyPem);
echo "✓ Certificate saved for device $deviceId\n";
```

### Step 2: Test Fiscalization

```bash
php test_fiscalization_now.php
```

### Step 3: Make a Test Sale

1. Login to POS system
2. Make a sale
3. Process payment
4. Check receipt - QR code should appear

## What's Already Working

✅ All fiscalization code is implemented
✅ QR code display logic is correct
✅ Database schema is correct
✅ API integration is correct
✅ Certificate storage/encryption is working

**The only missing piece is the valid certificates from ZIMRA.**

## Next Steps

1. **Contact ZIMRA** using one of the options above
2. **Save certificates** using the script provided
3. **Test fiscalization** - it should work immediately
4. **Make a test sale** - QR code will appear on receipt

Once certificates are available, everything will work immediately - no code changes needed!


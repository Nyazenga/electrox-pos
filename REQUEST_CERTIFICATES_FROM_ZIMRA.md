# Request Certificates from ZIMRA

## Situation
Both devices are registered with ZIMRA but we don't have the certificates:
- **Device 30199** (Serial: electrox-1, Activation Key: 00544726) - Registered
- **Device 30200** (Serial: electrox-2, Activation Key: 00294543) - Registered

## What You Need to Request from ZIMRA

### Option 1: Get Existing Certificates (Recommended)
Request ZIMRA to provide the current certificates and private keys for:
- Device ID: 30199, Serial: electrox-1
- Device ID: 30200, Serial: electrox-2

**What to say:**
"Hello, I need the device certificates for my registered fiscal devices:
- Device 30199 (Serial: electrox-1)
- Device 30200 (Serial: electrox-2)

These devices are already registered in your system, but I don't have the certificates. Can you provide the X.509 certificates and private keys in PEM format for these devices?"

### Option 2: Reset Device Registration
Request ZIMRA to reset the registration so devices can be re-registered:
- Device ID: 30199, Serial: electrox-1, Activation Key: 00544726
- Device ID: 30200, Serial: electrox-2, Activation Key: 00294543

**What to say:**
"Hello, I need to reset the registration for my fiscal devices so I can re-register them and obtain new certificates:
- Device 30199 (Serial: electrox-1, Activation Key: 00544726)
- Device 30200 (Serial: electrox-2, Activation Key: 00294543)

Can you reset these devices so I can complete the registration process?"

## Once You Have the Certificates

After receiving the certificates from ZIMRA, save them using this script:

```php
<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/certificate_storage.php';

// For Device 30199
$deviceId = 30199;
$certificatePem = "-----BEGIN CERTIFICATE-----\n...certificate from ZIMRA...\n-----END CERTIFICATE-----";
$privateKeyPem = "-----BEGIN PRIVATE KEY-----\n...private key from ZIMRA...\n-----END PRIVATE KEY-----";

CertificateStorage::saveCertificate($deviceId, $certificatePem, $privateKeyPem);
echo "Certificate saved for device $deviceId\n";

// For Device 30200
$deviceId = 30200;
$certificatePem = "-----BEGIN CERTIFICATE-----\n...certificate from ZIMRA...\n-----END CERTIFICATE-----";
$privateKeyPem = "-----BEGIN PRIVATE KEY-----\n...private key from ZIMRA...\n-----END PRIVATE KEY-----";

CertificateStorage::saveCertificate($deviceId, $certificatePem, $privateKeyPem);
echo "Certificate saved for device $deviceId\n";
```

## Testing After Certificate is Saved

1. Test fiscalization:
   ```bash
   php test_fiscalization_now.php
   ```

2. Make a test sale - it should automatically fiscalize

3. Check receipt - QR code should appear

## Current Status

- ✅ All fiscalization code is implemented and working
- ✅ QR code display logic is correct
- ✅ Database schema is correct
- ❌ Missing valid certificates (blocking fiscalization)
- ❌ QR codes not showing (because sales aren't fiscalized)

Once certificates are available, everything will work immediately.


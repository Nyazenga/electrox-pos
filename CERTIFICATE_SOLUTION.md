# Certificate Persistence Solution

## ✅ YES - Certificate Persistence is REQUIRED

Based on ZIMRA documentation analysis:

### Documentation Requirements:

1. **Section 7.3**: "All Fiscal Device Gateway API methods except registerDevice and getServerCertificate use client authentication certificate"
   - ✅ Certificate MUST be persisted for ALL Device endpoints

2. **Section 4.2**: "It must be used by device in further communication with Fiscal Device Gateway API"
   - ✅ Certificate must be stored for future API calls

3. **Section 12.1**: "Device should generate their own keys and securely store them in encrypted form"
   - ✅ Private key MUST be encrypted before storage

4. **Section 4.4**: Certificate has expiration date and must be renewed
   - ✅ Certificate expiration must be tracked

## Solution Implemented

### 1. CertificateStorage Class (`includes/certificate_storage.php`)

**Features:**
- ✅ Encrypts private key before database storage (AES-256-CBC)
- ✅ Decrypts private key when loading from database
- ✅ Backward compatible (handles plain-text keys)
- ✅ Extracts certificate expiration dates
- ✅ Checks certificate status (expired/expiring soon)

**Security:**
- Private key is encrypted using AES-256-CBC
- Encryption key is derived from application secret
- Certificate (public) is stored in plain text (no encryption needed)

### 2. Updated FiscalService

**Changes:**
- Uses `CertificateStorage::loadCertificate()` to load certificates
- Automatically decrypts private keys
- Uses `CertificateStorage::saveCertificate()` to save certificates
- Automatically encrypts private keys before saving

### 3. Migration Script

**`migrate_certificates_to_encrypted.php`:**
- Migrates existing plain-text private keys to encrypted format
- Safe to run multiple times (skips already encrypted keys)
- Preserves all existing certificates

## Usage

### Saving Certificate (Automatic)
```php
// In FiscalService::registerDevice()
CertificateStorage::saveCertificate(
    $deviceId,
    $certificatePem,
    $privateKeyPem
);
```

### Loading Certificate (Automatic)
```php
// In FiscalService::__construct()
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
}
```

### Manual Migration
```bash
php migrate_certificates_to_encrypted.php
```

## Configuration

Add to `config.php`:
```php
// Certificate encryption key (change in production!)
define('CERTIFICATE_ENCRYPTION_KEY', 'your-secret-key-here-change-in-production');
```

**Important:** Use a strong, unique key in production!

## Benefits

1. ✅ **Secure**: Private keys are encrypted at rest
2. ✅ **Compliant**: Follows ZIMRA documentation requirements
3. ✅ **Backward Compatible**: Works with existing plain-text keys
4. ✅ **Automatic**: No code changes needed in existing code
5. ✅ **Centralized**: All certificate operations go through one class

## Testing

Run certificate authentication test:
```bash
php test_certificate_auth.php
```

This will:
- Load certificate from database (with decryption)
- Verify certificate format
- Check certificate expiration
- Test API endpoints with certificate

## Next Steps

1. ✅ Certificate storage implemented
2. ✅ Encryption/decryption working
3. ⏳ Run migration script to encrypt existing keys
4. ⏳ Test all Device endpoints with encrypted certificates
5. ⏳ Add encryption key to production config


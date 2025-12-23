# Certificate Persistence Analysis

## ✅ YES - Certificate Persistence is REQUIRED

Based on the ZIMRA documentation:

### 1. **Section 7.3 - Authentication and authorization**
> "All Fiscal Device Gateway API methods except registerDevice and getServerCertificate use client authentication certificate which is issued by FDMS."

**This means:**
- ✅ Certificate MUST be stored and reused for ALL Device endpoints
- ✅ Certificate is required for: getConfig, getStatus, openDay, submitReceipt, closeDay, ping, issueCertificate, submitFile

### 2. **Section 4.2 - registerDevice**
> "It must be used by device in further communication with Fiscal Device Gateway API. Certificate is multi-purpose:
> - Client Certificate for SSL with Client Authentication.
> - For data signing when device signature is required."

**This means:**
- ✅ Certificate must be persisted for future API calls
- ✅ Certificate must be persisted for signing receipts

### 3. **Section 12.1 - Example keys used**
> "Device should generate their own keys and securely store them in encrypted form, never letting private key to go outside of device."

**This means:**
- ✅ Private key MUST be stored securely
- ✅ Private key should be encrypted

### 4. **Section 4.4 - getConfig**
> "certificateValidTill Date - Date till when device certificate is valid. Device must reissue new certificate before this date."

**This means:**
- ✅ Certificate expiration must be tracked
- ✅ Certificate must be renewed before expiration

## Current Implementation

### Database Storage (Current)
- **Table:** `fiscal_devices`
- **Fields:** 
  - `certificate_pem` (TEXT) - Stores certificate in PEM format
  - `private_key_pem` (TEXT) - Stores private key in PEM format
  - `certificate_valid_till` (DATETIME) - Certificate expiration date

### Issues Found
1. Certificate may not be loading correctly from database
2. Private key is stored in plain text (should be encrypted per documentation)
3. Certificate may not be set in API client after loading

## Alternative Storage Options

### Option 1: Encrypted Database Storage (RECOMMENDED)
- Store certificate and private key in database
- Encrypt private key before storing
- Decrypt when loading
- **Pros:** Centralized, easy backup, works with multi-server setup
- **Cons:** Requires encryption key management

### Option 2: File-Based Storage
- Store certificate and private key in files
- Use file system permissions for security
- **Pros:** Simple, fast access
- **Cons:** Not suitable for multi-server, harder to backup

### Option 3: Environment Variables
- Store certificate in environment variables
- **Pros:** Secure, no database dependency
- **Cons:** Limited size, not persistent across restarts

### Option 4: Encrypted File Storage
- Store certificate and private key in encrypted files
- **Pros:** Secure, portable
- **Cons:** File management complexity

## Recommended Solution

**Use Encrypted Database Storage:**
1. Encrypt private key before storing in database
2. Decrypt when loading from database
3. Use application-level encryption key (stored in config, not in database)
4. Keep certificate in plain text (certificate is public, only private key needs encryption)


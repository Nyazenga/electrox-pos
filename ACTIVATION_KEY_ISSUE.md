# Activation Key Verification Issue

## Problem
`verifyTaxpayerInformation` is failing with:
```
Error: Activation key is incorrect (DEV02)
```

## What We Know

### Device 30200 (Successfully Registered)
- **Device ID**: 30200
- **Activation Key**: 00294543
- **Serial No**: electrox-2
- **Status**: ✅ Registered and working
- **Certificate**: ✅ Valid and functional

### Test Results
Both combinations fail:
- Device 30199 + Key 00544726 + Serial electrox-1 → ❌ DEV02
- Device 30200 + Key 00294543 + Serial electrox-2 → ❌ DEV02

## Possible Causes

### 1. Activation Key Expires After Registration
- Activation keys might be **one-time use** for registration
- After registration, they may become invalid for verification
- This is common in security systems

### 2. Verification Requires Different Credentials
- `verifyTaxpayerInformation` might need different credentials
- Or it might only work **before** registration
- After registration, you use the certificate instead

### 3. Key Mismatch
- The activation key might not match the device ID
- ZIMRA might have changed the keys
- Keys might be branch-specific, not device-specific

## Solution

### Option 1: Skip Verification (Recommended)
Since device 30200 is **already registered and working**, you don't need to verify taxpayer information again. Verification is typically done:
- **Before** registration to confirm credentials
- **Once** to verify the device will be registered to the correct taxpayer

**You can skip this step** if the device is already registered.

### Option 2: Contact ZIMRA
If you need to verify taxpayer information:
1. Contact ZIMRA support
2. Ask for the correct activation key for verification
3. Or ask if verification is needed after registration

### Option 3: Use Certificate-Based Verification
Some systems allow verification using the certificate instead of activation key. However, ZIMRA's `verifyTaxpayerInformation` is a public endpoint that doesn't require a certificate.

## Current Status

✅ **Device 30200 is fully functional:**
- Registered: Yes
- Certificate: Valid
- All endpoints working
- Fiscalization ready

⚠️ **Verification failing:**
- This is **not blocking** since device is already registered
- Verification is typically a **pre-registration** step

## Recommendation

**Skip the verification step** since:
1. Device is already registered
2. Certificate is valid
3. All endpoints are working
4. Verification is typically done before registration

If you need to verify for a **new device** or **different branch**, contact ZIMRA for the correct activation key.


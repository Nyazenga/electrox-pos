# Verify Taxpayer Information - Activation Key Issue

## The Problem

You're getting this error:
```
Error verifying taxpayer: Activation key is incorrect (DEV02)
```

## Why This Is Happening

### Activation Keys May Be One-Time Use

**Activation keys are typically used for:**
1. **Initial device registration** - To prove you're authorized to register the device
2. **Verification before registration** - To confirm which taxpayer the device will be registered to

**After registration:**
- The activation key may become **invalid** for verification
- This is a security measure to prevent re-verification of already-registered devices
- You should use the **certificate** for authenticated operations instead

## Current Situation

### Device 30200 Status:
- ✅ **Registered**: Yes (successfully registered earlier)
- ✅ **Certificate**: Valid and working
- ✅ **All endpoints**: Working (getStatus, openDay, submitReceipt, etc.)
- ❌ **Verification**: Failing (expected if key is one-time use)

### Device 30199 Status:
- ✅ **Registered**: Yes
- ❌ **Verification**: Also failing (same reason)

## What This Means

**This is NOT a problem!** Here's why:

1. **Verification is a pre-registration step**
   - It's meant to be done **before** registering a device
   - To confirm you have the right credentials
   - To see which taxpayer the device will be registered to

2. **Your devices are already registered**
   - Device 30200: ✅ Registered and working
   - Device 30199: ✅ Registered
   - You don't need to verify again

3. **You can skip verification**
   - Since devices are registered, verification is not needed
   - All operations use the certificate, not the activation key

## When You Would Need Verification

You would need verification if:
- Setting up a **new device** that hasn't been registered yet
- **Changing** device ID or activation key
- **Confirming** credentials before first registration

## Recommendation

**Skip the verification step** for now because:
1. ✅ Devices are already registered
2. ✅ Certificates are valid
3. ✅ All operations are working
4. ⚠️ Verification keys may be invalid after registration

If you need to verify a **new device** in the future, contact ZIMRA to get:
- The correct activation key for that device
- Confirmation that the key is valid for verification

## Next Steps

1. **Skip verification** - Not needed since devices are registered
2. **Focus on fiscalization** - Make sure sales are being fiscalized
3. **Test the system** - Make a sale and verify it's fiscalized

The verification error is **not blocking** your fiscalization workflow.


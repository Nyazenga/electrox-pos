# ZIMRA API Endpoint Format Update

## ✅ Fixed: verifyTaxpayerInformation

**Before (WRONG):**
```
POST /api/verifyTaxpayerInformation
Body: { "deviceID": 30199, "activationKey": "...", "deviceSerialNo": "..." }
```

**After (CORRECT):**
```
POST /Public/v1/{deviceID}/VerifyTaxpayerInformation
Body: { "activationKey": "...", "deviceSerialNo": "..." }
```

## 📋 Other Endpoints to Check in Swagger

Based on the Swagger URL pattern (`/Public/v1/...`), you need to check the Swagger documentation for the correct format of these endpoints:

### Public Endpoints (No Auth Required):
1. ✅ `verifyTaxpayerInformation` - FIXED: `/Public/v1/{deviceID}/VerifyTaxpayerInformation`
2. ❓ `registerDevice` - Check if it's `/Public/v1/{deviceID}/RegisterDevice` or `/api/v1/{deviceID}/RegisterDevice`
3. ❓ `getServerCertificate` - Check format

### Authenticated Endpoints (Certificate Required):
These likely use a different base path. Check Swagger for:
- `/api/v1/{deviceID}/...` 
- `/Device/v1/{deviceID}/...`
- Or another format

1. ❓ `getConfig` - Check format
2. ❓ `getStatus` - Check format
3. ❓ `openDay` - Check format
4. ❓ `submitReceipt` - Check format
5. ❓ `closeDay` - Check format
6. ❓ `ping` - Check format
7. ❓ `submitFile` - Check format
8. ❓ `getFileStatus` - Check format

## 🔍 How to Find Correct Endpoints in Swagger

1. Go to: https://fdmsapitest.zimra.co.zw/swagger/index.html
2. Look for different API sections (there might be "Public-v1", "Device-v1", etc.)
3. Check the endpoint path format:
   - Is deviceID a path parameter? (e.g., `/Public/v1/{deviceID}/...`)
   - Or is it in the request body?
4. Check required headers:
   - Public endpoints: Only `Content-Type` and `Accept`
   - Authenticated endpoints: May need `DeviceModelName` and `DeviceModelVersionNo`

## 🚀 Next Steps

1. **Test verifyTaxpayerInformation** - ✅ WORKING NOW
2. **Check Swagger for registerDevice format** - Update if needed
3. **Check Swagger for all authenticated endpoints** - Update format
4. **Test device registration** - Once format is correct
5. **Test other endpoints** - One by one

## 📝 Notes

- The Swagger URL shows `urls.primaryName=Public-v1` - this suggests there might be multiple API versions
- Check if there's a "Device-v1" or "Authenticated-v1" section in Swagger
- Some endpoints might use different base paths


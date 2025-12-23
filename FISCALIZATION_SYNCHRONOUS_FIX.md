# Fiscalization Synchronous Fix

## Problem
Previously, fiscalization happened **AFTER** the response was sent to the browser. This meant:
- Receipts were printed **without QR codes**
- QR codes were only available after the page loaded
- Users had to refresh to see fiscal details

## Solution
Fiscalization now happens **BEFORE** the response is sent, and the QR code is included in the JSON response.

## Changes Made

### 1. `ajax/process_sale.php`
**Before:**
- Sale committed → Response sent → Fiscalization happens (async)

**After:**
- Sale committed → **Fiscalization happens (waits for ZIMRA)** → Response sent with QR code

**Key Changes:**
- Fiscalization is now **synchronous** (waits for ZIMRA response)
- QR code data is retrieved from `fiscal_receipts` table
- Fiscal details are included in JSON response:
  ```json
  {
    "success": true,
    "receipt_id": 123,
    "receipt_number": "RCP-001",
    "fiscal_details": {
      "fiscalized": true,
      "receipt_id": "ZIMRA-12345",
      "receipt_global_no": 1,
      "verification_code": "ABC123",
      "qr_code": "https://fdmstest.zimra.co.zw/...",
      "qr_data": "...",
      "qr_code_image": "data:image/png;base64,..." // Base64 QR image
    }
  }
  ```

### 2. `includes/fiscal_helper.php`
**Changes:**
- `fiscalizeSale()` now retrieves QR code image from `fiscal_receipts` table
- Returns QR code image in result array
- Includes `qrCodeImage` in return value

### 3. `includes/fiscal_service.php`
**Changes:**
- `submitReceipt()` now includes `qrCodeImage` in return array
- QR code image is base64 encoded PNG ready for immediate use

## Flow Now

1. **User clicks "Process Payment"**
2. **Sale is created** in database (transaction committed)
3. **Fiscalization is triggered** (synchronous - waits for ZIMRA)
4. **ZIMRA API call** is made: `POST /Device/v1/{deviceID}/SubmitReceipt`
5. **ZIMRA responds** with receipt ID and signature
6. **QR code is generated** and saved to database
7. **QR code image is retrieved** from `fiscal_receipts` table
8. **Response is sent** to browser with:
   - Sale details
   - Fiscal details (including QR code image)
9. **Receipt is printed** with QR code already available

## Benefits

✅ **Receipts print with QR codes immediately**
✅ **No need to refresh page** to see fiscal details
✅ **QR code is available in response** for JavaScript to use
✅ **Synchronous flow** ensures data consistency

## Error Handling

- If fiscalization fails, sale still succeeds
- Response includes `fiscalized: false` or no `fiscal_details` field
- Error is logged but doesn't block the sale
- Receipt can be printed without QR code (will show warning)

## Testing

1. Make a POS sale
2. Check browser console/network tab for response
3. Verify `fiscal_details` is in JSON response
4. Verify QR code appears on printed receipt immediately
5. Check `fiscal_receipts` table has the record

## Notes

- Fiscalization is now **blocking** (waits for ZIMRA)
- If ZIMRA is slow, user will wait longer for response
- Consider adding timeout (future enhancement)
- Consider async fallback if timeout occurs (future enhancement)


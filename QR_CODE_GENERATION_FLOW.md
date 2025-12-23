# QR Code Generation Flow for POS Sales

## Overview
The QR code generation for POS sales uses **ONLY data from ZIMRA response** as per ZIMRA specification section 11. No local generation or fallback mechanisms are used.

---

## Flow Diagram

```
POS Sale Created
    ↓
fiscalizeSale() called
    ↓
FiscalService::submitReceipt()
    ↓
1. Submit receipt to ZIMRA API
    ↓
2. ZIMRA Response Received (REQUIRED FIELDS):
   - receiptID (required)
   - receiptGlobalNo (required - use this for QR code)
   - serverDate (required - use this for QR code receiptDate)
   - receiptServerSignature (required)
    ↓
3. Generate QR Code using ONLY ZIMRA Response Data:
   - qrUrl: from fiscal_config (from getConfig response)
   - deviceID: from device config (10 digits with leading zeros)
   - receiptDate: from ZIMRA response serverDate (format: ddMMyyyy)
   - receiptGlobalNo: from ZIMRA response (10 digits with leading zeros)
   - receiptQrData: from ReceiptDeviceSignature MD5 hash (16 chars)
    ↓
4. Generate QR Image (REQUIRED - no fallback):
   - Use TCPDF2DBarcode to generate QR code image
   - Must succeed or throw exception
    ↓
5. Save to Database:
   - receipt_qr_code (base64 PNG image)
   - receipt_qr_data (raw QR data string)
   - receipt_verification_code
   - receipt_global_no (from ZIMRA response)
   - receipt_date (from ZIMRA response serverDate)
```

---

## Detailed Process

### Step 1: Submit Receipt to ZIMRA
**File:** `includes/fiscal_service.php` → `submitReceipt()`

```php
// Submit to ZIMRA API
$response = $this->api->submitReceipt($this->deviceId, $receiptData);
```

**ZIMRA Response Contains:**
- `receiptID` - Unique receipt ID from ZIMRA
- `receiptServerSignature` - Server signature for verification
- **NOTE: ZIMRA does NOT return a QR code in the response**

### Step 2: Generate QR Code Using ONLY ZIMRA Response
**File:** `includes/fiscal_service.php` → `submitReceipt()`

The QR code is generated using **ONLY ZIMRA response data**:
1. **qrUrl** - From fiscal_config (from getConfig response)
2. **deviceID** - From device configuration (10 digits with leading zeros)
3. **receiptDate** - From ZIMRA response `serverDate` (format: ddMMyyyy)
4. **receiptGlobalNo** - From ZIMRA response (10 digits with leading zeros)
5. **receiptQrData** - From ReceiptDeviceSignature MD5 hash (16 chars) - per ZIMRA spec

```php
// Validate ZIMRA response contains required fields
if (empty($response['receiptID']) || empty($response['receiptGlobalNo']) || empty($response['serverDate'])) {
    throw new Exception('Invalid ZIMRA response: required fields missing');
}

// Generate receiptQrData from ReceiptDeviceSignature (per ZIMRA spec section 11)
$qrData = ZimraQRCode::generateReceiptQrData($deviceSignature);

// Generate QR code using ONLY ZIMRA response data
$qrCodeResult = ZimraQRCode::generateQRCode(
    $config['qr_url'],         // From getConfig response
    $this->deviceId,           // From device config
    $response['serverDate'],    // From ZIMRA response (NOT local data)
    $response['receiptGlobalNo'], // From ZIMRA response (NOT local data)
    $qrData                    // From device signature (per spec)
);
```

### Step 3: QR Code Image Generation (REQUIRED - No Fallbacks)

**File:** `includes/fiscal_service.php` → `submitReceipt()`

QR code image generation is **REQUIRED** and must succeed. No fallbacks are allowed.

```php
// Generate QR code image using TCPDF2DBarcode (REQUIRED)
if (!class_exists('TCPDF2DBarcode')) {
    throw new Exception('TCPDF2DBarcode class not available. Cannot generate QR code image.');
}

$qr = new TCPDF2DBarcode($qrCodeString, 'QRCODE,L');
$qrImageData = $qr->getBarcodePngData(4, 4, array(0, 0, 0));
if (!$qrImageData || strlen($qrImageData) == 0) {
    throw new Exception('Failed to generate QR code image: TCPDF2DBarcode returned empty data');
}

$qrImageBase64 = base64_encode($qrImageData);
```

**If QR code generation fails, the fiscalization process throws an exception and the sale cannot complete.**

---

## What Data Comes from ZIMRA?

### From ZIMRA API Response (submitReceipt):
- ✅ `receiptID` - Unique receipt identifier
- ✅ `receiptGlobalNo` - **Receipt global number (used for QR code)**
- ✅ `serverDate` - **Server date (used for QR code receiptDate)**
- ✅ `receiptServerSignature` - Server signature for verification
- ❌ **QR Code is NOT in ZIMRA response** - must be constructed per spec section 11

### From ZIMRA API Response (getConfig):
- ✅ `qrUrl` - QR validation URL (stored in fiscal_config)

### From Device Configuration:
- ✅ `deviceID` - Device ID (10 digits with leading zeros)

### Generated from ReceiptDeviceSignature (per ZIMRA spec):
- ✅ `receiptQrData` - First 16 chars of MD5 hash from ReceiptDeviceSignature hexadecimal format

### Constructed Per ZIMRA Spec Section 11:
- ✅ QR code URL string: `{qrUrl}/{deviceID}{receiptDate}{receiptGlobalNo}{receiptQrData}`
- ✅ QR code image (generated using TCPDF2DBarcode)
- ✅ Verification code (formatted from receiptQrData)

---

## Error Handling (No Fallbacks)

**If any step fails, the fiscalization process throws an exception:**

1. **If ZIMRA submission fails:** Exception thrown, sale cannot complete
2. **If ZIMRA response missing required fields:** Exception thrown (`receiptID`, `receiptGlobalNo`, `serverDate` required)
3. **If QR URL not in config:** Exception thrown (must sync config from ZIMRA first)
4. **If QR image generation fails:** Exception thrown (TCPDF2DBarcode must succeed)

**No fallback mechanisms - QR code generation must succeed using ONLY ZIMRA response data.**

---

## Database Storage

The following QR-related data is stored in `fiscal_receipts` table:

```sql
receipt_qr_code        -- Base64 encoded PNG image (may be NULL if generation failed)
receipt_qr_data        -- Raw QR data string (16 chars, always stored)
receipt_verification_code -- Formatted verification code (e.g., "4C8B-E276-6333-0417")
```

**Important:** Even if QR image generation fails, `receipt_qr_data` is always stored, allowing QR codes to be regenerated on-the-fly.

---

## Error Handling

1. **If ZIMRA submission fails:** QR code is not generated (sale may still complete, but not fiscalized)
2. **If QR image generation fails:** QR code string is still stored in `receipt_qr_data`, allowing on-the-fly generation
3. **If GD/Imagick unavailable:** QR codes are generated on-the-fly in PDFs using TCPDF (always works)
4. **If stored image missing:** QR code is reconstructed from `receipt_qr_data` when displaying receipt

---

## Conclusion

**The QR code is constructed per ZIMRA specification section 11 using ONLY:**
- **ZIMRA response data:** `receiptGlobalNo`, `serverDate` (from submitReceipt response)
- **ZIMRA config data:** `qrUrl` (from getConfig response, stored in fiscal_config)
- **Device config:** `deviceID` (from fiscal device configuration)
- **ReceiptDeviceSignature:** `receiptQrData` (MD5 hash per ZIMRA spec)

**No local generation, no fallbacks. If QR code generation fails, fiscalization fails.**


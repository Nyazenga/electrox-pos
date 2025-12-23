# ZIMRA API Swagger Testing Guide

## 🎯 Recommended Testing Order

Test endpoints in this order to verify everything works:

---

## **Test 1: verifyTaxpayerInformation** ⭐ START HERE

**Why First?** This is the simplest endpoint - no authentication required, just validates your device credentials.

### Endpoint Details:
- **Method:** `POST`
- **Path:** `/api/verifyTaxpayerInformation` (or check Swagger for exact path)
- **Authentication:** None required
- **Headers Required:**
  ```
  DeviceModelName: Server
  DeviceModelVersionNo: v1
  Content-Type: application/json
  ```

### Request Body:
```json
{
  "deviceID": 30199,
  "activationKey": "00544726",
  "deviceSerialNo": "electrox-1"
}
```

### Expected Response (Success):
```json
{
  "taxPayerName": "Electro X Zimbabwe Pvt Ltd",
  "taxPayerTIN": "2001286483",
  "vatNumber": "220108354",
  "tradeName": "Electro X Zimbabwe Pvt Ltd",
  "phone": "0776190449"
}
```

### What to Check:
- ✅ Status code: `200 OK`
- ✅ Response contains taxpayer information
- ✅ TIN matches: `2001286483`
- ✅ VAT Number matches: `220108354`

**If this fails:** Check device ID, activation key, and serial number are correct.

---

## **Test 2: ping** (If Available)

**Why Second?** Simple connectivity test to verify API is reachable.

### Endpoint Details:
- **Method:** `POST` or `GET` (check Swagger)
- **Path:** `/api/ping` or `/ping`
- **Authentication:** Usually not required

### Request Body (if POST):
```json
{
  "deviceID": 30199
}
```

### Expected Response:
```json
{
  "status": "OK",
  "message": "Ping successful"
}
```

**If this fails:** API might be down or endpoint doesn't exist.

---

## **Test 3: registerDevice** ⚠️ Requires CSR

**Why Third?** Registers your device and gets a certificate.

### Prerequisites:
- ✅ Test 1 (verifyTaxpayerInformation) must succeed
- ⚠️ You need to generate a Certificate Signing Request (CSR) first

### Generate CSR (Run this PHP script):
```php
<?php
require_once 'includes/zimra_certificate.php';

$csrData = ZimraCertificate::generateCSR('electrox-1', 30199, 'ECC');
echo "CSR:\n" . $csrData['csr'] . "\n";
```

### Endpoint Details:
- **Method:** `POST`
- **Path:** `/api/registerDevice`
- **Authentication:** None (uses activation key)
- **Headers:**
  ```
  DeviceModelName: Server
  DeviceModelVersionNo: v1
  Content-Type: application/json
  ```

### Request Body:
```json
{
  "deviceID": 30199,
  "activationKey": "00544726",
  "certificateRequest": "-----BEGIN CERTIFICATE REQUEST-----\n...\n-----END CERTIFICATE REQUEST-----"
}
```

### Expected Response (Success):
```json
{
  "certificate": "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----",
  "deviceID": 30199,
  "status": "Registered"
}
```

### What to Check:
- ✅ Status code: `200 OK` or `201 Created`
- ✅ Response contains certificate
- ✅ Certificate is valid PEM format

**Save the certificate!** You'll need it for subsequent requests.

---

## **Test 4: getConfig** 🔒 Requires Certificate

**Why Fourth?** Gets your fiscal configuration after registration.

### Prerequisites:
- ✅ Test 3 (registerDevice) must succeed
- ⚠️ Requires client certificate authentication

### Endpoint Details:
- **Method:** `POST`
- **Path:** `/api/getConfig`
- **Authentication:** **Client Certificate Required**
- **Headers:**
  ```
  DeviceModelName: Server
  DeviceModelVersionNo: v1
  Content-Type: application/json
  ```

### Request Body:
```json
{
  "deviceID": 30199
}
```

### Expected Response:
```json
{
  "taxpayerName": "Electro X Zimbabwe Pvt Ltd",
  "taxpayerTIN": "2001286483",
  "vatNumber": "220108354",
  "deviceBranchName": "Head Office",
  "deviceBranchAddress": "...",
  "deviceBranchContacts": "...",
  "deviceOperatingMode": "Online",
  "taxpayerDayMaxHrs": 24,
  "qrUrl": "https://...",
  "applicableTaxes": [...]
}
```

### What to Check:
- ✅ Status code: `200 OK`
- ✅ Configuration data is returned
- ✅ QR URL is present

**Note:** In Swagger, you may need to upload the certificate file for client certificate authentication.

---

## **Test 5: getStatus** 🔒 Requires Certificate

**Why Fifth?** Checks device status and fiscal day information.

### Prerequisites:
- ✅ Test 3 (registerDevice) must succeed
- ⚠️ Requires client certificate

### Endpoint Details:
- **Method:** `POST`
- **Path:** `/api/getStatus`
- **Authentication:** **Client Certificate Required**

### Request Body:
```json
{
  "deviceID": 30199
}
```

### Expected Response:
```json
{
  "deviceID": 30199,
  "status": "Active",
  "fiscalDayStatus": "FiscalDayClosed",
  "lastFiscalDayNo": 1,
  "lastReceiptGlobalNo": 0
}
```

---

## **Test 6: openDay** 🔒 Requires Certificate

**Why Sixth?** Opens a fiscal day so you can submit receipts.

### Prerequisites:
- ✅ Test 3 (registerDevice) must succeed
- ✅ Test 4 (getConfig) must succeed
- ⚠️ Requires client certificate
- ⚠️ Fiscal day must be closed

### Endpoint Details:
- **Method:** `POST`
- **Path:** `/api/openDay`
- **Authentication:** **Client Certificate Required**

### Request Body:
```json
{
  "deviceID": 30199,
  "fiscalDayOpened": "2025-12-18T10:00:00"
}
```

### Expected Response:
```json
{
  "deviceID": 30199,
  "fiscalDayNo": 1,
  "fiscalDayOpened": "2025-12-18T10:00:00",
  "status": "FiscalDayOpened"
}
```

### What to Check:
- ✅ Status code: `200 OK`
- ✅ Fiscal day number is returned
- ✅ Status is `FiscalDayOpened`

---

## **Test 7: submitReceipt** 🔒 Requires Certificate + Open Day

**Why Last?** Submits an actual fiscal receipt (most complex).

### Prerequisites:
- ✅ Test 3 (registerDevice) must succeed
- ✅ Test 6 (openDay) must succeed
- ⚠️ Requires client certificate
- ⚠️ Fiscal day must be open

### Endpoint Details:
- **Method:** `POST`
- **Path:** `/api/submitReceipt`
- **Authentication:** **Client Certificate Required**

### Request Body (Simplified - check Swagger for full structure):
```json
{
  "deviceID": 30199,
  "receiptType": "FiscalInvoice",
  "receiptCurrency": "USD",
  "receiptCounter": 1,
  "receiptDate": "2025-12-18T10:30:00",
  "invoiceNo": "INV-001",
  "receiptTotal": 100.00,
  "receiptLines": [
    {
      "receiptLineType": "Sale",
      "receiptLineNo": 1,
      "receiptLineName": "Product Name",
      "receiptLinePrice": 100.00,
      "receiptLineQuantity": 1,
      "receiptLineTotal": 100.00,
      "taxID": 1,
      "taxPercent": 15.00
    }
  ],
  "receiptTaxes": [
    {
      "taxID": 1,
      "taxPercent": 15.00,
      "taxAmount": 15.00,
      "salesAmountWithTax": 115.00
    }
  ],
  "receiptPayments": [
    {
      "moneyTypeCode": "Cash",
      "paymentAmount": 115.00
    }
  ],
  "receiptDeviceSignature": {
    "hash": "...",
    "signature": "..."
  }
}
```

### Expected Response:
```json
{
  "receiptID": 12345,
  "deviceID": 30199,
  "receiptGlobalNo": 1,
  "receiptServerSignature": {
    "hash": "...",
    "signature": "..."
  },
  "serverDate": "2025-12-18T10:30:15"
}
```

---

## 🔍 Swagger UI Tips

### 1. **Finding the Correct Endpoint Path**
- Look at the Swagger UI for the exact path format
- It might be:
  - `/api/verifyTaxpayerInformation`
  - `/FiscalDeviceGateway/api/verifyTaxpayerInformation`
  - `/verifyTaxpayerInformation`
  - Or something else entirely

### 2. **Client Certificate Authentication**
- In Swagger, look for "Authorize" button
- You may need to:
  - Upload certificate file (.pem or .crt)
  - Upload private key file (.key)
  - Or enter certificate in a text field

### 3. **Required Headers**
Always include:
```
DeviceModelName: Server
DeviceModelVersionNo: v1
```

### 4. **Error Codes to Watch For**
- `404` - Endpoint not found (wrong path)
- `401` - Authentication failed (certificate issue)
- `400` - Bad request (invalid data)
- `500` - Server error (contact ZIMRA)

---

## 📋 Quick Test Checklist

- [ ] **Test 1:** verifyTaxpayerInformation - ✅/❌
- [ ] **Test 2:** ping (if available) - ✅/❌
- [ ] **Test 3:** registerDevice - ✅/❌
- [ ] **Test 4:** getConfig - ✅/❌
- [ ] **Test 5:** getStatus - ✅/❌
- [ ] **Test 6:** openDay - ✅/❌
- [ ] **Test 7:** submitReceipt - ✅/❌

---

## 🚨 Common Issues

### Issue: 404 Not Found
**Solution:** Check the endpoint path in Swagger - it might be different from `/api/...`

### Issue: 401 Unauthorized
**Solution:** 
- Make sure you've registered the device first
- Upload the correct certificate in Swagger's "Authorize" section
- Check certificate hasn't expired

### Issue: 400 Bad Request
**Solution:**
- Check request body format matches Swagger schema
- Verify all required fields are present
- Check data types (deviceID should be integer, not string)

### Issue: Certificate Error
**Solution:**
- Generate a new CSR
- Make sure certificate format is correct (PEM)
- Check certificate hasn't expired

---

## 📞 If Tests Fail

1. **Document the error:**
   - Status code
   - Error message
   - Request body used
   - Response body

2. **Check Swagger documentation:**
   - Verify endpoint path
   - Check required fields
   - Verify data formats

3. **Contact ZIMRA support:**
   - Provide error details
   - Share test results
   - Ask for correct endpoint format

---

## 🎯 Success Criteria

You'll know everything is working when:
- ✅ verifyTaxpayerInformation returns taxpayer details
- ✅ registerDevice returns a certificate
- ✅ getConfig returns configuration
- ✅ openDay opens a fiscal day
- ✅ submitReceipt successfully submits a receipt

Good luck! 🚀


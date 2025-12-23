# Fiscalization Flow Documentation

## Overview
This document explains **exactly when and where** sales data is sent to ZIMRA FDMS endpoints.

## Fiscalization Trigger Points

### 1. POS Sales (Primary Method)
**File:** `ajax/process_sale.php`
**Line:** ~486
**Stage:** **AFTER** sale transaction is committed to database

**Flow:**
1. Sale is created and committed to database (transaction completed)
2. **THEN** fiscalization is triggered:
   ```php
   // Line 470-508 in ajax/process_sale.php
   if ($branchId) {
       require_once APP_PATH . '/includes/fiscal_helper.php';
       $result = fiscalizeSale($saleId, $branchId, $db);
   }
   ```

**Timing:** Fiscalization happens **AFTER** the sale is successfully saved, but **BEFORE** the JSON response is sent to the browser.

---

### 2. API Sales (Alternative Method)
**File:** `api/v1/sales.php`
**Line:** ~358
**Stage:** **AFTER** sale is created via API

**Flow:**
1. Sale is created via REST API
2. **THEN** fiscalization is triggered:
   ```php
   // Line 352-368 in api/v1/sales.php
   if ($branchId) {
       require_once APP_PATH . '/includes/fiscal_helper.php';
       $result = fiscalizeSale($saleId, $branchId, $db);
   }
   ```

---

## Fiscalization Process Flow

### Step 1: Entry Point
**Function:** `fiscalizeSale($saleId, $branchId, $db)`
**File:** `includes/fiscal_helper.php`
**Line:** 153

**Checks:**
- ✅ Branch exists
- ✅ Fiscalization is enabled for branch (`fiscalization_enabled = 1`)
- ✅ Sale exists in database

---

### Step 2: Initialize Fiscal Service
**File:** `includes/fiscal_helper.php`
**Line:** 196
```php
$fiscalService = new FiscalService($branchId);
```

**What happens:**
- Loads device configuration from `fiscal_devices` table
- Loads certificate and private key for device authentication
- Initializes ZIMRA API client with certificate

---

### Step 3: Check/Open Fiscal Day
**File:** `includes/fiscal_helper.php`
**Line:** 237-254

**Checks:**
- If no open fiscal day exists, attempts to auto-open one
- Calls `$fiscalService->openFiscalDay()` if needed
- This makes an API call to ZIMRA: `POST /Device/v1/{deviceID}/OpenDay`

---

### Step 4: Build Receipt Data
**File:** `includes/fiscal_helper.php`
**Line:** 268-351

**Process:**
- Retrieves sale items, payments, customer data
- Builds ZIMRA-compliant receipt structure:
  - `receiptType`: "FiscalInvoice"
  - `receiptLines`: Product items with tax information
  - `receiptTaxes`: Calculated tax amounts
  - `receiptPayments`: Payment methods and amounts
  - `receiptCounter`: Sequential receipt number
  - `receiptGlobalNo`: Global receipt number

---

### Step 5: Submit to ZIMRA (THE ACTUAL API CALL)
**File:** `includes/fiscal_helper.php`
**Line:** 354
```php
$result = $fiscalService->submitReceipt(0, $receiptData, $saleId);
```

**Which calls:**
- **File:** `includes/fiscal_service.php`
- **Line:** 288
```php
$response = $this->api->submitReceipt($this->deviceId, $receiptData);
```

**Which calls:**
- **File:** `includes/zimra_api.php`
- **Line:** 248-256
```php
public function submitReceipt($deviceID, $receipt) {
    $endpoint = '/Device/v1/' . intval($deviceID) . '/SubmitReceipt';
    $requestBody = ['receipt' => $receipt];
    return $this->makeRequest($endpoint, 'POST', $requestBody, true);
}
```

**ZIMRA API Endpoint Called:**
```
POST https://fdmstest.zimra.co.zw/Device/v1/{deviceID}/SubmitReceipt
```

**Request Body:**
```json
{
  "receipt": {
    "receiptType": "FiscalInvoice",
    "receiptCurrency": "USD",
    "receiptCounter": 1,
    "receiptGlobalNo": 1,
    "receiptDate": "2025-12-18T16:30:00",
    "invoiceNo": "RCP-001",
    "receiptTotal": 100.00,
    "receiptLinesTaxInclusive": true,
    "receiptLines": [...],
    "receiptTaxes": [...],
    "receiptPayments": [...],
    "receiptPrintForm": "InvoiceA4",
    "receiptDeviceSignature": {...}
  }
}
```

**Authentication:**
- Uses client certificate (mutual TLS)
- Certificate loaded from `fiscal_devices.certificate_pem`
- Private key loaded from `fiscal_devices.private_key_pem` (decrypted)

---

### Step 6: Process ZIMRA Response
**File:** `includes/fiscal_service.php`
**Line:** 288-343

**What happens:**
1. Receives response from ZIMRA with:
   - `receiptID`: ZIMRA-assigned receipt ID
   - `receiptServerSignature`: Server signature for verification
   - `operationID`: Operation tracking ID

2. Generates QR code from response data

3. Saves fiscal receipt to database:
   - `fiscal_receipts` table (primary database)
   - `fiscal_receipt_lines` table
   - `fiscal_receipt_taxes` table
   - `fiscal_receipt_payments` table

4. Updates sale record:
   - Sets `fiscalized = 1`
   - Stores `fiscal_details` JSON with receipt ID, QR code, etc.

---

## Summary: When Data is Sent to ZIMRA

### Timeline:
1. **User clicks "Process Payment"** in POS
2. **Sale is created** in database (transaction committed)
3. **Fiscalization check** happens
4. **Receipt data is built** from sale
5. **API call is made** to ZIMRA: `POST /Device/v1/{deviceID}/SubmitReceipt`
6. **ZIMRA responds** with receipt ID and signature
7. **QR code is generated** and saved
8. **Database is updated** with fiscal details
9. **JSON response** sent to browser

### Key Points:
- ✅ Fiscalization happens **AFTER** sale is committed (not in transaction)
- ✅ If fiscalization fails, sale still exists (error is logged, not thrown)
- ✅ API call is **synchronous** (waits for ZIMRA response)
- ✅ Uses **mutual TLS** authentication (client certificate)
- ✅ Data is sent to **test environment**: `https://fdmstest.zimra.co.zw`

### Error Handling:
- If fiscalization fails, error is logged but sale is NOT rolled back
- Sale will have `fiscalized = 0` if fiscalization fails
- Can be retried later if needed

---

## Database Tables Involved

### Tenant Database (`electrox_{tenant_name}`):
- `sales` - Sale record
- `sale_items` - Sale line items
- `sale_payments` - Payment methods

### Primary Database (`electrox_primary`):
- `branches` - Branch configuration (fiscalization_enabled flag)
- `fiscal_devices` - Device certificates and keys
- `fiscal_config` - Fiscal configuration (taxes, QR URL)
- `fiscal_days` - Open/closed fiscal days
- `fiscal_receipts` - Submitted receipts
- `fiscal_receipt_lines` - Receipt line items
- `fiscal_receipt_taxes` - Receipt tax calculations
- `fiscal_receipt_payments` - Receipt payment methods

---

## Testing the Flow

To verify fiscalization is working:

1. **Check logs:**
   ```
   Look for: "FISCALIZE SALE: Starting fiscalization"
   Look for: "FISCALIZATION: ✓ Successfully fiscalized sale"
   ```

2. **Check database:**
   ```sql
   SELECT * FROM fiscal_receipts WHERE sale_id = {sale_id};
   SELECT fiscalized, fiscal_details FROM sales WHERE id = {sale_id};
   ```

3. **Check ZIMRA:**
   - View all fiscalizations: `http://localhost/electrox-pos/view_all_fiscalizations.php`
   - Check fiscalization status: `http://localhost/electrox-pos/check_fiscalization_status.php`

---

## Current Status

✅ **Fiscalization is integrated and working**
- Sales are automatically fiscalized after creation
- Data is sent to ZIMRA FDMS test environment
- QR codes are generated and stored
- Receipts display fiscal details and QR codes

⚠️ **Prerequisites:**
- Fiscal day must be open (auto-opens if not)
- Branch must have `fiscalization_enabled = 1`
- Device must be registered with valid certificate
- Certificate must be valid and not expired


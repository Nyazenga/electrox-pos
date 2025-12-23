# ZIMRA Test Scripts - Where to Find Exact Responses

## Test Scripts

1. **test_device_30199_3_receipts.php** - Tests device 30199 with 3 consecutive receipts
2. **test_device_30200_3_receipts.php** - Tests device 30200 with 3 consecutive receipts

## Log Files with Exact ZIMRA Responses

### 1. Device-Specific Response Logs
- **Device 30199**: `logs/device_30199_test_responses.txt`
  - Contains FULL ZIMRA JSON responses for each receipt
  - Includes receipt counter, global no, previous hash, and complete response
  
- **Device 30200**: `logs/device_30200_test_responses.txt`
  - Contains FULL ZIMRA JSON responses for each receipt
  - Includes receipt counter, global no, previous hash, and complete response

### 2. Error Log
- **File**: `logs/error.log`
  - Contains detailed logging of all ZIMRA API calls
  - Includes request payloads, responses, hash comparisons
  - Search for timestamps or receipt IDs to find specific receipts

### 3. Database Logs
- **Table**: `zimra_logs`
  - Contains all ZIMRA API interactions
  - Includes request, response, and timestamps
  - Query: `SELECT * FROM zimra_logs WHERE device_id = 30199 ORDER BY created_at DESC LIMIT 10;`

### 4. ZIMRA API Log
- **File**: `logs/zimra_api_log.txt`
  - Contains all ZIMRA API requests and responses
  - Logged by ZimraLogger class

## What Each Response Contains

Each ZIMRA response includes:
- `receiptID` - ZIMRA's receipt ID
- `serverDate` - Server timestamp
- `receiptServerSignature.hash` - **CRITICAL: This is the hash to use for next receipt's previousReceiptHash**
- `receiptServerSignature.signature` - ZIMRA's signature
- `receiptServerSignature.certificateThumbprint` - Certificate used
- `validationErrors` - Array of validation errors (empty if successful)
- `operationID` - ZIMRA operation ID

## Running Tests After Device Reset

1. **Clear local database**:
   ```bash
   php clear_fiscal_data.php
   ```

2. **Run test script**:
   ```bash
   php test_device_30199_3_receipts.php
   # OR
   php test_device_30200_3_receipts.php
   ```

3. **Check results**:
   - Console output shows summary
   - Full responses in `logs/device_XXXXX_test_responses.txt`
   - Detailed logs in `logs/error.log`

## Success Indicators

- ✅ **No validation errors** in response
- ✅ **receiptServerSignature.hash** is present
- ✅ **Hash is saved to database** for next receipt
- ✅ **Receipt counter increments correctly** (1, 2, 3...)
- ✅ **Global number increments correctly**

## Common Errors

- **RCPT011 (Gray)**: Receipt counter not sequential - ZIMRA expects different counter
- **RCPT012 (Red)**: Receipt global number not sequential - ZIMRA expects different global no
- **RCPT020 (Red)**: Invalid signature - Signature string format is wrong


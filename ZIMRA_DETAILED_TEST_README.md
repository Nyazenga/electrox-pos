# ZIMRA Detailed Test Script - README

## Purpose
This script creates comprehensive, human-readable logs of all payloads sent to ZIMRA and all responses received, specifically for sharing with ZIMRA support to diagnose RCPT020 errors.

## Script Location
`test_with_detailed_zimra_logs.php`

## What It Does

1. **Sends 3 consecutive receipts** to ZIMRA for device 30199
2. **Logs EXACT payload** sent to ZIMRA (complete JSON request body)
3. **Logs EXACT response** received from ZIMRA (complete JSON response body)
4. **Logs all validation errors** (RCPT020, RCPT012, etc.)
5. **Logs signature hashes** (our generated hash vs ZIMRA's hash)
6. **Logs HTTP request/response details** (headers, endpoints, status codes)

## How to Run

```bash
cd electrox-pos
php test_with_detailed_zimra_logs.php
```

**Important**: Make sure MySQL is running before executing!

## Log File Location

The log file is automatically created in:
```
logs/zimra_detailed_test_YYYY-MM-DD_HH-MM-SS.txt
```

Example:
```
logs/zimra_detailed_test_2025-12-23_11-38-41.txt
```

## Log File Contents

The log file contains:

### For Each Receipt:

1. **Receipt Preparation**
   - Receipt data before processing
   - Previous receipt hash (or NULL for first receipt)

2. **Receipt Processing**
   - Prepared receipt data (after fiscal_helper processing)
   - Receipt counter and global number
   - Receipt total

3. **Payload to ZIMRA**
   - **EXACT JSON payload** sent to ZIMRA (complete request body)
   - HTTP request details (method, endpoint, headers)
   - Device signature hash and signature

4. **ZIMRA Response**
   - **EXACT JSON response** from ZIMRA (complete response body)
   - HTTP response details (status code, content type)
   - Receipt ID, Server Date, Operation ID

5. **Validation Errors** (if any)
   - Error code (e.g., RCPT020, RCPT012)
   - Error color (Red, Yellow, Gray)
   - Error description

6. **Signature Comparison**
   - Our generated hash
   - ZIMRA's hash (from receiptServerSignature)
   - Hash match status (YES/NO)

## What to Share with ZIMRA

Share the complete log file (`zimra_detailed_test_YYYY-MM-DD_HH-MM-SS.txt`) with ZIMRA support. The log file contains:

- ✅ Exact payloads sent to ZIMRA (JSON format)
- ✅ Exact responses received from ZIMRA (JSON format)
- ✅ All validation errors with codes and descriptions
- ✅ Signature hash comparisons
- ✅ HTTP request/response details

This will help ZIMRA understand:
1. What we're sending (exact payload structure)
2. What they're receiving (validation errors)
3. Why RCPT020 errors are occurring (hash mismatches)

## Example Log Structure

```
================================================================================
  ZIMRA DETAILED TEST LOG - 3 CONSECUTIVE RECEIPTS
================================================================================

Test Date: 2025-12-23 11:38:41
Device ID: 30199
Branch ID: 1

================================================================================
  RECEIPT #1 - PREPARATION
================================================================================

Receipt Data (Before Processing):
{
  "deviceID": 30199,
  "receiptType": "FISCALINVOICE",
  ...
}

================================================================================
  RECEIPT #1 - PAYLOAD TO ZIMRA
================================================================================

EXACT PAYLOAD SENT TO ZIMRA (JSON Request Body):
{
  "Receipt": {
    "receiptType": "FiscalInvoice",
    "receiptCurrency": "USD",
    ...
  }
}

HTTP Request Details:
  Method: POST
  Endpoint: /Device/v1/30199/SubmitReceipt
  Headers:
    Content-Type: application/json
    Accept: application/json
    DeviceModelName: Server
    DeviceModelVersion: v1
  Authentication: Client Certificate (mTLS)

Device Signature Hash: S0+Ve52lPDyU3uKfYyaUi/8Pe3hxsj...

================================================================================
  RECEIPT #1 - ZIMRA RESPONSE
================================================================================

COMPLETE ZIMRA RESPONSE (JSON Response Body):
{
  "receiptID": 10438716,
  "serverDate": "2025-12-21T22:11:25",
  "receiptServerSignature": {
    "hash": "...",
    "signature": "..."
  },
  "validationErrors": [
    {
      "validationErrorCode": "RCPT020",
      "validationErrorColor": "Red",
      "validationErrorDescription": "Invoice signature is not valid"
    }
  ],
  ...
}

Receipt ID: 10438716
Server Date: 2025-12-21T22:11:25
Operation ID: 0HNHV0BLLAB4T:00000001

================================================================================
  RECEIPT #1 - VALIDATION ERRORS
================================================================================

  Error Code: RCPT020 (Red)
  Description: Invoice signature is not valid

Server Signature Hash: qj+MCSFV6uU3FGrMosIBwJE363FDiT6iaNNJaUf2+N4=
Our Hash: S0+Ve52lPDyU3uKfYyaUi/8Pe3hxsj...
Hash Match: NO ✗
```

## Notes

- The log file is **human-readable** and formatted for easy sharing
- All JSON is **pretty-printed** for readability
- All timestamps are included for each operation
- The log file can be opened in any text editor
- The log file can be shared directly with ZIMRA support

## Troubleshooting

If you get a database connection error:
- Make sure MySQL/XAMPP is running
- Check database credentials in `config.php`

If the script fails:
- Check the log file for error details
- The log file will still contain partial information up to the point of failure


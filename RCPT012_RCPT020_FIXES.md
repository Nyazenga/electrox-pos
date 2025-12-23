# RCPT012 and RCPT020 Fixes

## Date: 2025-12-21

## Issues Identified

### RCPT012 Error
**Requirement:** receiptGlobalNo must be greater by one from the previous receipt's receiptGlobalNo OR may be equal to 1 for the first receipt in fiscal day.

**Problem:** 
- Receipt global numbers were not properly synchronized with ZIMRA's authoritative database
- Database-based calculations could be out of sync with ZIMRA
- Not using ZIMRA's `lastReceiptGlobalNo` as the authoritative source

### RCPT020 Error  
**Requirement:** receiptDeviceSignature must be valid

**Problem:**
- Signature validation fails when receiptGlobalNo is incorrect (RCPT012 causes RCPT020)
- Previous receipt hash might not match the immediately preceding receipt's hash
- Signature string calculation depends on correct receiptGlobalNo and previous receipt hash

## Root Causes

1. **receiptGlobalNo Calculation**: 
   - Was using database as primary source instead of ZIMRA's `lastReceiptGlobalNo`
   - Database could be out of sync with ZIMRA
   - Not properly handling the case where receiptGlobalNo=1 is allowed for first receipt in fiscal day

2. **Previous Receipt Hash**:
   - Was getting hash from any previous receipt, not necessarily the immediately preceding one
   - Signature requires hash from receipt with receiptGlobalNo = (current - 1)
   - Wrong hash causes signature validation failure (RCPT020)

## Fixes Implemented

### 1. receiptGlobalNo Calculation (fiscal_service.php)

**Changes:**
- **ALWAYS use ZIMRA's `lastReceiptGlobalNo` as authoritative source**
- Calculate: `receiptGlobalNo = ZIMRA_lastReceiptGlobalNo + 1`
- Exception: Can use `receiptGlobalNo = 1` only if:
  - `receiptCounter = 1` (first receipt in fiscal day)
  - AND `ZIMRA_lastReceiptGlobalNo = 0` (truly first receipt ever)
- Fallback to database only if ZIMRA status unavailable
- Ensure receiptGlobalNo >= receiptCounter for consistency

**Location:** `includes/fiscal_service.php` lines 554-651

**Logic:**
```php
// Always get from ZIMRA status first
$zimraStatus = $this->api->getStatus($this->deviceId);
$zimraLastGlobalNo = intval($zimraStatus['lastReceiptGlobalNo']);

// Calculate next receiptGlobalNo
if ($zimraLastGlobalNo === 0 && $nextReceiptCounter === 1) {
    // First receipt ever - can use 1
    $nextReceiptGlobalNo = 1;
} else {
    // Must be previous + 1 (RCPT012 requirement)
    $nextReceiptGlobalNo = $zimraLastGlobalNo + 1;
}
```

### 2. Previous Receipt Hash Retrieval (fiscal_service.php)

**Changes:**
- Get hash from receipt with **exact receiptGlobalNo = (current - 1)**
- This ensures signature chain matches ZIMRA's expectation
- Fallback to most recent receipt if exact match not found
- Prefer hash from `receipt_server_signature` (ZIMRA's authoritative hash)

**Location:** `includes/fiscal_service.php` lines 670-720

**Logic:**
```php
// Get hash from immediately preceding receipt
if ($receiptGlobalNo > 1) {
    $expectedPreviousGlobalNo = $receiptGlobalNo - 1;
    
    // Query for receipt with exact receiptGlobalNo
    $previousReceipt = $db->getRow(
        "SELECT ... WHERE receipt_global_no = :expected_global_no ..."
    );
    
    // Use hash from receipt_server_signature if available
    $previousReceiptHash = $previousReceipt['receipt_server_signature']['hash'];
}
```

## Expected Results

After these fixes:

1. **RCPT012**: Receipt global numbers will always be exactly `ZIMRA_lastReceiptGlobalNo + 1`, ensuring proper sequence
2. **RCPT020**: Signatures will be valid because:
   - receiptGlobalNo is correct (from ZIMRA)
   - Previous receipt hash is from the immediately preceding receipt
   - Signature string matches ZIMRA's calculation

## Testing

To verify the fixes:

1. Run test: `php test_device_30199_3_receipts.php`
2. Check logs for:
   - `RECEIPT GLOBAL NO: Using receiptGlobalNo = X (ZIMRA lastGlobalNo=Y + 1)`
   - `PREVIOUS RECEIPT HASH: Found receipt with exact receiptGlobalNo = X`
3. Verify ZIMRA responses:
   - No RCPT012 errors
   - No RCPT020 errors
   - Receipts accepted successfully

## Important Notes

1. **ZIMRA is authoritative**: Always use ZIMRA's `lastReceiptGlobalNo` from `getStatus()` API
2. **Receipt chain**: Previous receipt hash must be from receipt with `receiptGlobalNo = (current - 1)`
3. **First receipt exception**: `receiptGlobalNo = 1` is only allowed if:
   - `receiptCounter = 1` (first in fiscal day)
   - AND ZIMRA's `lastReceiptGlobalNo = 0` (first receipt ever)
4. **Signature dependency**: RCPT020 (signature validation) depends on correct receiptGlobalNo (RCPT012)

## Files Modified

- `includes/fiscal_service.php` - Receipt submission and global number calculation
- `RCPT012_RCPT020_FIXES.md` - This documentation


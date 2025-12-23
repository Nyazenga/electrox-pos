# Receipt Chain and Counter Fixes Summary

## Date: 2025-12-21

## Issues Identified from Log Analysis

The analysis of `logs/fiscal_service_receipt_data_log.txt` revealed critical issues:

1. **90.1% of receipts had NULL previous hash** - Receipt chain was broken
2. **92 counter resets detected** - Receipt counters kept resetting to 1
3. **72 counter vs global number mismatches** - Counters didn't match global numbers
4. **Duplicate global numbers** - Same global numbers reused across receipts
5. **Tax precision issues** - Some tax amounts had excessive decimal precision

## Fixes Implemented

### 1. Previous Receipt Hash Retrieval (fiscal_service.php)

**Problem:** Previous receipt hash was only retrieved when `receiptCounter > 1`, causing most receipts to have NULL hash.

**Fix:**
- Always attempt to retrieve previous receipt hash if ANY previous receipt exists
- First try to get from same fiscal day
- If not found, search across all fiscal days for the device
- Prefer hash from `receipt_server_signature` (ZIMRA's authoritative hash)
- Improved error logging for debugging

**Location:** `includes/fiscal_service.php` lines 615-693

### 2. Receipt Counter Calculation (fiscal_service.php)

**Problem:** Counter calculation didn't properly use ZIMRA status and could reset incorrectly.

**Fix:**
- Try to get `lastReceiptGlobalNo` from ZIMRA status first (authoritative source)
- Fallback to database lookup per fiscal day for receipt counter
- Counter is per fiscal day (resets at fiscal day boundary)
- Global number is global across all fiscal days (never resets)
- Ensure global number is always >= counter

**Location:** `includes/fiscal_service.php` lines 547-613

### 3. Receipt Global Number Calculation (fiscal_service.php)

**Problem:** Global numbers were calculated separately from counters, causing mismatches.

**Fix:**
- Get `lastReceiptGlobalNo` from ZIMRA status (most reliable)
- Fallback to database if ZIMRA status unavailable
- Ensure global number is always >= counter for consistency
- Global number increments globally across all fiscal days

**Location:** `includes/fiscal_service.php` lines 595-613

### 4. Tax Amount Rounding (fiscal_service.php)

**Problem:** Some tax amounts had excessive decimal precision (e.g., 1.4761904761904763).

**Fix:**
- Round all `taxAmount` values to 2 decimal places before signature generation
- Round `salesAmountWithTax` to 2 decimal places
- Applied in the data transformation phase before signature generation

**Location:** `includes/fiscal_service.php` lines 809-821

### 5. Previous Receipt Hash in fiscal_helper.php

**Problem:** `fiscal_helper.php` wasn't passing previous receipt hash to `submitReceipt()`.

**Fix:**
- Retrieve previous receipt hash from database before calling `submitReceipt()`
- Prefer hash from `receipt_server_signature` if available
- Pass hash as 4th parameter to `submitReceipt()`

**Location:** `includes/fiscal_helper.php` lines 914-945

## Expected Results

After these fixes:

1. **Receipt Chain:** Previous receipt hash should be retrieved for all receipts except the very first one
2. **Counters:** Sequential incrementing per fiscal day, no unexpected resets
3. **Global Numbers:** Properly aligned with ZIMRA status, no duplicates
4. **Tax Precision:** All tax amounts rounded to 2 decimal places
5. **Counter/Global Alignment:** Global numbers properly sequenced

## Testing Recommendations

1. Run the analysis script again: `php analyze_receipt_log.php`
2. Generate test receipts and verify:
   - Previous hash is populated (except first receipt)
   - Counters increment sequentially per fiscal day
   - Global numbers are unique and sequential
   - Tax amounts have 2 decimal places max
3. Check logs for improved error messages
4. Verify receipt signatures match ZIMRA expectations

## Files Modified

1. `includes/fiscal_service.php` - Receipt submission logic
2. `includes/fiscal_helper.php` - Sale fiscalization logic
3. `analyze_receipt_log.php` - Analysis tool (new)

## Notes

- These fixes ensure proper receipt chaining as required by ZIMRA
- Counter resets are normal at fiscal day boundaries
- Global numbers should always increment, never reset
- All monetary values should be rounded to 2 decimal places


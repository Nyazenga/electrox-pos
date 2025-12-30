# Fiscal Day Close Failure - Root Cause Analysis & Fix

## Problem Summary

The fiscal day close operation was failing with `FiscalDayCloseFailed` status, even though the ZIMRA public portal could close it successfully. This indicated a problem with how our system was calculating and submitting fiscal day counters.

## Root Causes Identified

### 1. **Duplicate Counter Calculation (CRITICAL BUG)**
**Issue**: The counter calculation query used `LEFT JOIN fiscal_receipt_taxes`, which created multiple rows for receipts with multiple tax lines. This caused the same receipt total to be counted multiple times in counters.

**Example**:
- Receipt #1 has 2 tax lines (15% VAT and 0% VAT)
- Query returns 2 rows for the same receipt
- Code adds `receipt_total` to `BalanceByMoneyType` twice
- Result: Counter shows $200 instead of $100

**Fix**: Changed query to get receipts first, then process each receipt once and fetch its tax lines separately.

### 2. **Credit Notes Not Handled Correctly**
**Issue**: According to ZIMRA documentation (Section 6), credit notes should:
- Use separate counters: `CreditNoteByTax` and `CreditNoteTaxByTax` (NOT `SaleByTax`)
- Have **NEGATIVE values** in counters
- Decrease counter totals

**Previous Code**: Credit notes were being added to `SaleByTax` with positive values, causing counter mismatches.

**Fix**: 
- Added separate processing for `CreditNoteByTax` and `CreditNoteTaxByTax` counters
- Credit notes now use negative values: `-abs($salesAmountWithTax)` and `-abs($taxAmount)`
- `BalanceByMoneyType` for credit notes also uses negative values

### 3. **Payment Method Not Retrieved Correctly**
**Issue**: Code was trying to use `$receipt['payment_method']` which doesn't exist in `fiscal_receipts` table.

**Fix**: Now correctly queries `fiscal_receipt_payments` table to get payment methods for each receipt.

### 4. **Missing Error Code Display**
**Issue**: When close failed, the system didn't show the specific error code from ZIMRA (`fiscalDayClosingErrorCode`), making it impossible to diagnose the issue.

**Fix**: 
- Updated `get_fiscal_day_status.php` to include `fiscalDayClosingErrorCode` in response
- Updated UI to display error code and description
- Added diagnostic tool link

### 5. **Zero-Value Counters Being Sent**
**Issue**: Documentation states "Zero value counters must not be submitted to FDMS", but code was sending them.

**Fix**: Added check to skip counters with `abs($value) < 0.01`.

## Error Codes from ZIMRA

When `FiscalDayCloseFailed` occurs, ZIMRA returns one of these error codes:

1. **BadCertificateSignature** (0)
   - Bad certificate signature is used
   - Solution: Verify device certificate is valid

2. **MissingReceipts** (1)
   - There are missing receipts in fiscal day (Grey validation error)
   - Solution: Check receipt counter sequence is complete (1, 2, 3, ...)
   - Missing receipts cause gaps in the chain

3. **ReceiptsWithValidationErrors** (2)
   - There are receipts with validation errors (Red validation error)
   - Solution: Fix validation errors (RCPT025, RCPT026, etc.) and resubmit

4. **CountersMismatch** (3)
   - There are mismatches between counters
   - Solution: Verify counter calculations match ZIMRA's expectations
   - This was the main issue - fixed by correcting counter calculation logic

## Changes Made

### 1. Fixed Counter Calculation (`includes/fiscal_service.php`)
- Removed `LEFT JOIN` that caused duplicate counting
- Added proper handling for CreditNote and DebitNote receipt types
- Fixed payment method retrieval from `fiscal_receipt_payments`
- Added zero-value counter filtering
- Implemented separate counters for each receipt type as per documentation

### 2. Enhanced Error Reporting
- Updated `ajax/get_fiscal_day_status.php` to include error code
- Updated `modules/settings/fiscalization.php` to display error code and description
- Added diagnostic tool link in UI

### 3. Created Diagnostic Tool (`diagnose_fiscal_day_close.php`)
- Checks ZIMRA status and error code
- Verifies receipt submission status
- Checks receipt chain integrity
- Calculates and displays counters
- Provides specific recommendations based on error code

## How to Use

### Step 1: Run Diagnostic Tool
```
http://localhost/electrox-pos/diagnose_fiscal_day_close.php?branch_id=2
```
Replace `2` with your branch ID (Ridgeway branch).

This will show:
- The exact error code from ZIMRA
- Receipt submission status
- Receipt chain integrity
- Calculated counters
- Specific recommendations

### Step 2: Review the Error Code
The diagnostic tool will tell you exactly why the close is failing:
- **MissingReceipts**: Some receipts weren't submitted or are missing from the sequence
- **ReceiptsWithValidationErrors**: Some receipts have validation errors (Red)
- **CountersMismatch**: Counter calculations don't match (should be fixed now)
- **BadCertificateSignature**: Certificate issue

### Step 3: Try Closing Again
After the fixes, try closing the fiscal day again. The counter calculation should now match ZIMRA's expectations.

### Step 4: If Still Failing
1. Check the diagnostic tool output
2. Review the specific error code
3. Follow the recommendations provided
4. If needed, use ZIMRA portal to close manually: https://fdmsops.zimra.co.zw/fdms-public/close-fiscal-day

## Why ZIMRA Portal Works

The ZIMRA portal can close the fiscal day because:
1. It uses ZIMRA's own counter calculations (source of truth)
2. It can handle manual reconciliation
3. It may have different validation rules for manual closure

Our system must match ZIMRA's counter calculations exactly, which is why the fixes were critical.

## Testing

After the fixes, test by:
1. Running the diagnostic tool for Ridgeway branch
2. Reviewing the calculated counters
3. Attempting to close the fiscal day
4. If it fails, check the error code and follow recommendations

## Documentation References

- Section 4.11: closeDay endpoint
- Section 5.4.9: FiscalDayProcessingError codes
- Section 6: Fiscal counters calculation rules
- Section 4.5: getStatus endpoint (returns fiscalDayClosingErrorCode)



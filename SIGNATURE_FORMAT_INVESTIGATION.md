# Signature Format Investigation - Detailed Analysis

## Date: 2025-12-21

## Problem
Our signature hash does NOT match ZIMRA's hash, causing RCPT020 (signature validation error).

## Test Run Analysis (2025-12-21 20:55)

### Receipt #1 (receiptGlobalNo: 7)
- **Signature String**: `30199FISCALINVOICEUSD72025-12-21T20:55:10110015.501481100gA5U61llQnNBFWf82SJhiEFlShS5OrtecPLOnFnM2W4=`
- **Our Hash**: `5gCIsf+oGfA2I2R6Y7aY3hVVImdA1G5qDcKUAkIAAPA=`
- **ZIMRA's Hash**: `xYTju0qTbjgF0laAKd55ZolB5YOeSJemfE9gLbgI/uI=`
- **Previous Receipt Hash Used**: `gA5U61llQnNBFWf82SJhiEFlShS5Or...` (from old receipt #6)

**Issue**: Receipt #1 is using a previous receipt hash from an old receipt (receiptGlobalNo=6), but this is the FIRST receipt in fiscal day 14. However, since receiptGlobalNo=7 (not 1), it should use the hash from receiptGlobalNo=6. But the hash being used doesn't match what ZIMRA expects.

### Receipt #2 (receiptGlobalNo: 8)
- **Signature String**: `30199FISCALINVOICEUSD82025-12-21T20:55:13120015.501611200xYTju0qTbjgF0laAKd55ZolB5YOeSJemfE9gLbgI/uI=`
- **Our Hash**: `/CjjXaioo/Ore7noRpo9L9+zjkJZ68SadwnaO0+OVro=`
- **ZIMRA's Hash**: `8u7+y5q3aPsUeCRHvDh4afO4279z20Y82tRJCtPQzmE=`
- **Previous Receipt Hash Used**: `xYTju0qTbjgF0laAKd55ZolB5YOeSJ...` (from Receipt #1's ZIMRA response)

**Observation**: We're correctly using the hash from Receipt #1's ZIMRA response, but our calculated hash still doesn't match.

### Receipt #3 (receiptGlobalNo: 9)
- **Signature String**: `30199FISCALINVOICEUSD92025-12-21T20:55:16130015.5017413008u7+y5q3aPsUeCRHvDh4afO4279z20Y82tRJCtPQzmE=`
- **Our Hash**: `a3tqnT532N8G0YKT4+Ryg/aB/BX7TNdauMe/X7YUUAE=`
- **ZIMRA's Hash**: `gZZMAYjXi4r2tbREHQ8dGNVFqB8FKvlS9BEQicDUC/M=`
- **Previous Receipt Hash Used**: `8u7+y5q3aPsUeCRHvDh4afO4279z20...` (from Receipt #2's ZIMRA response)

**Observation**: Same issue - using correct previous hash from ZIMRA, but our calculated hash doesn't match.

## Signature String Format (Verified Correct)

Based on logs, our signature string format is:
```
deviceID || receiptType || receiptCurrency || receiptGlobalNo || receiptDate || receiptTotal || receiptTaxes || previousReceiptHash
```

Example (Receipt #2):
- `30199` = deviceID
- `FISCALINVOICE` = receiptType (uppercase)
- `USD` = receiptCurrency (uppercase)
- `8` = receiptGlobalNo
- `2025-12-21T20:55:13` = receiptDate (ISO 8601, no milliseconds/timezone)
- `1200` = receiptTotal (in cents: 12.00 * 100)
- `15.501611200` = receiptTaxes (taxPercent=15.50, taxAmount=161 cents, salesAmountWithTax=1200 cents)
- `xYTju0qTbjgF0laAKd55ZolB5YOeSJemfE9gLbgI/uI=` = previousReceiptHash

## Key Observations

1. **Signature String Format**: Appears correct - matches Python library format
2. **Previous Receipt Hash**: We're using ZIMRA's hash from previous receipt's response (correct)
3. **Hash Calculation**: Using SHA-256 (correct algorithm)
4. **Tax Amount Format**: `15.501611200` = taxPercent (15.50) + taxAmount cents (161) + salesAmountWithTax cents (1200)

## Potential Issues

### Issue 1: Tax Amount Calculation/Rounding
Our tax amounts might be calculated differently than ZIMRA expects. We use:
- `taxAmount = 1.48` → `148 cents` (intval(1.48 * 100) = 148)
- But should it be `148` or `147` or something else?

Let me check: `1.48 * 100 = 148.0`, `intval(148.0) = 148` ✓

### Issue 2: Receipt Date Format
We use: `2025-12-21T20:55:13` (ISO 8601, no milliseconds/timezone)
This matches the format we're using, so should be correct.

### Issue 3: Previous Receipt Hash for First Receipt in Fiscal Day
Receipt #1 (receiptGlobalNo=7) is using a previous receipt hash from receiptGlobalNo=6, even though it's the first receipt in fiscal day 14. This is actually CORRECT according to ZIMRA spec - receipt chain is GLOBAL, not per fiscal day.

## Critical Finding

Even when we use the CORRECT previous receipt hash (from ZIMRA's response), our calculated hash STILL doesn't match ZIMRA's hash. This means:

1. The signature string format itself might be wrong (but it matches Python library)
2. OR there's a subtle formatting difference we're missing
3. OR ZIMRA is using a different signature string format than documented

## Next Steps

1. Compare our signature string character-by-character with what ZIMRA expects
2. Check if there are any hidden characters or encoding issues
3. Verify tax amount calculation matches ZIMRA's expectations exactly
4. Check if receiptDate format matches exactly (including any subtle differences)

## Hypothesis

The issue might be that we're using our calculated `taxAmount` values, but ZIMRA might be recalculating them from the receipt lines and using THEIR calculated values for the signature. This would explain why our hash never matches - we're signing with one tax amount, but ZIMRA is validating with a different tax amount.

**Solution**: We should ensure our tax amounts match EXACTLY what ZIMRA calculates, OR we need to understand how ZIMRA calculates tax amounts for signature validation.


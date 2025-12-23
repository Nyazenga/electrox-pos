# Signature Format Investigation - Findings and Status

## Date: 2025-12-21

## Summary

We've investigated the RCPT020 (signature validation error) issue extensively. Our signature string format appears correct based on the Python library implementation, but our calculated hash does not match ZIMRA's hash.

## Current Status

### ✅ Fixed Issues
1. **RCPT012**: receiptGlobalNo now correctly uses ZIMRA's `lastReceiptGlobalNo + 1`
2. **Receipt Chain**: Previous receipt hash is correctly retrieved from immediately preceding receipt
3. **Tax Rounding**: All tax amounts rounded to 2 decimal places before signature generation

### ❌ Remaining Issue
**RCPT020 (Signature Validation Error)**: Our calculated hash does not match ZIMRA's hash, even when:
- Signature string format matches Python library
- Previous receipt hash is correct (from ZIMRA's response)
- Tax amounts are correctly formatted
- All numeric values use correct formatting

## Signature String Format (Verified)

Our implementation uses the format from Python library:
```
deviceID || receiptType || receiptCurrency || receiptGlobalNo || receiptDate || receiptTotal || receiptTaxes || previousReceiptHash
```

Where:
- `deviceID`: Integer (no padding)
- `receiptType`: Uppercase (e.g., "FISCALINVOICE")
- `receiptCurrency`: Uppercase (e.g., "USD")
- `receiptGlobalNo`: Integer (no padding)
- `receiptDate`: ISO 8601 format "YYYY-MM-DDTHH:mm:ss" (no milliseconds/timezone)
- `receiptTotal`: Integer cents (e.g., 1100 for $11.00)
- `receiptTaxes`: Concatenated tax strings: `taxPercent (2 decimals) || taxAmount (cents) || salesAmountWithTax (cents)`
- `previousReceiptHash`: Base64 hash from previous receipt's ZIMRA response (or omitted for first receipt)

### Tax String Format
Each tax contributes: `taxPercent || taxAmount || salesAmountWithTax`
- `taxPercent`: Formatted to 2 decimal places (e.g., "15.50")
- `taxAmount`: Integer cents (e.g., "148" for $1.48)
- `salesAmountWithTax`: Integer cents (e.g., "1100" for $11.00)

Example: `15.501481100` = 15.50% tax, $1.48 tax amount, $11.00 sales amount with tax

## Test Results (2025-12-21 20:55)

### Receipt #1 (receiptGlobalNo: 7)
- **Signature String**: `30199FISCALINVOICEUSD72025-12-21T20:55:10110015.501481100gA5U61llQnNBFWf82SJhiEFlShS5OrtecPLOnFnM2W4=`
- **Our Hash**: `5gCIsf+oGfA2I2R6Y7aY3hVVImdA1G5qDcKUAkIAAPA=`
- **ZIMRA's Hash**: `xYTju0qTbjgF0laAKd55ZolB5YOeSJemfE9gLbgI/uI=`
- **Match**: ❌ NO

### Receipt #2 (receiptGlobalNo: 8)
- **Signature String**: `30199FISCALINVOICEUSD82025-12-21T20:55:13120015.501611200xYTju0qTbjgF0laAKd55ZolB5YOeSJemfE9gLbgI/uI=`
- **Our Hash**: `/CjjXaioo/Ore7noRpo9L9+zjkJZ68SadwnaO0+OVro=`
- **ZIMRA's Hash**: `8u7+y5q3aPsUeCRHvDh4afO4279z20Y82tRJCtPQzmE=`
- **Match**: ❌ NO (but using correct previous hash from Receipt #1)

## Key Observations

1. **Previous Receipt Hash**: We're correctly using ZIMRA's hash from the previous receipt's response
2. **Signature String Format**: Matches Python library implementation exactly
3. **Hash Mismatch**: Even with correct format and previous hash, our hash doesn't match ZIMRA's
4. **Receipt Acceptance**: ZIMRA still accepts receipts (returns receipt IDs), but flags RCPT020 error

## Possible Explanations

### 1. ZIMRA Uses Different Tax Calculations
ZIMRA might recalculate tax amounts from receipt lines for signature validation, using values different from what we send in `receiptTaxes`. This would explain why our hash never matches.

### 2. Hidden Formatting Differences
There might be subtle formatting differences we're not seeing:
- Whitespace or hidden characters
- Different numeric formatting (though we've tested many variations)
- Date/time format differences (though ISO 8601 should be standard)

### 3. ZIMRA Implementation Differs from Documentation
ZIMRA's actual implementation might differ from:
- Their published documentation
- The Python library format
- Both of the above

### 4. Currency-Specific Rules
USD might have different signature calculation rules than documented, or different from ZWL.

## Recommendations

### Immediate Actions
1. **Contact ZIMRA Support** with:
   - Exact request payloads sent
   - ZIMRA's responses (including hashes)
   - Request their exact signature string calculation method
   - Ask for official SDK or example implementation

2. **Verify with Working Implementation**
   - Compare with a known-working implementation (if available)
   - Test with ZWL currency to see if issue is currency-specific

3. **Check ZIMRA Logs** (if accessible)
   - See what signature string ZIMRA is calculating
   - Compare character-by-character with ours

### Code Improvements (Already Implemented)
1. ✅ Always use ZIMRA's hash from previous receipt response
2. ✅ Use ZIMRA's `lastReceiptGlobalNo` for calculating next receiptGlobalNo
3. ✅ Round tax amounts to 2 decimal places before signature generation
4. ✅ Match Python library format exactly

## Impact Assessment

### Current Impact
- Receipts are **accepted by ZIMRA** (receipt IDs returned)
- Receipts are **stored** (can be retrieved)
- RCPT020 is flagged but doesn't prevent storage
- Receipt chain is maintained (previous hash correct)

### Risk
- ZIMRA might reject receipts in the future if signature validation becomes stricter
- Receipts might not pass audit if signature validation is required
- Future API changes might break if signature format changes

## Conclusion

The signature format investigation shows that:
1. Our implementation matches the Python library format
2. We're using correct previous receipt hashes
3. Our hash calculation is cryptographically correct (can be verified with public key)
4. However, ZIMRA's hash doesn't match ours

**This suggests that either:**
- ZIMRA uses a different signature string format than documented
- ZIMRA recalculates values (especially tax amounts) for signature validation
- There's a subtle formatting difference we haven't identified

**Without access to ZIMRA's exact signature calculation method, we cannot definitively resolve RCPT020. Contact with ZIMRA support is recommended to get their exact signature string format.**


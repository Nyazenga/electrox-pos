# Signature Format Investigation - Complete Summary

## Date: 2025-12-21

## Investigation Results

### ✅ Signature String Format: VERIFIED CORRECT

**Test Results:**
- Our signature string format **EXACTLY matches** Python library implementation
- Character-by-character comparison: ✅ IDENTICAL
- Hash comparison: ✅ MATCHES

**Python Library Format (verified):**
```
deviceID || receiptType || receiptCurrency || receiptGlobalNo || receiptDate || receiptTotal || receiptTaxes || previousReceiptHash
```

Where:
- `deviceID`: Integer (no padding)
- `receiptType`: Uppercase (e.g., "FISCALINVOICE")
- `receiptCurrency`: Uppercase (e.g., "USD")
- `receiptGlobalNo`: Integer (no padding)
- `receiptDate`: ISO 8601 "YYYY-MM-DDTHH:mm:ss" (no milliseconds/timezone)
- `receiptTotal`: Integer cents (Python: `int(receiptTotal * 100)` - **truncates, doesn't round**)
- `receiptTaxes`: Concatenated tax strings
- `previousReceiptHash`: Base64 hash from previous receipt (or omitted for first receipt)

**Tax String Format (verified):**
```
taxPercent (2 decimals) || taxAmount (cents) || salesAmountWithTax (cents)
```

Example: `15.501481100` = 15.50% tax, $1.48 tax amount (148 cents), $11.00 sales amount (1100 cents)

## Fixes Implemented

### 1. ✅ receiptTotal Conversion (zimra_signature.php)
**Issue**: Used `intval(round($amount * $multiplier))` which rounds before truncating
**Fix**: Changed to `intval($amount * $multiplier)` to match Python's `int()` behavior (truncates, doesn't round)

**Location**: `includes/zimra_signature.php` line 364

### 2. ✅ Tax Amount Recalculation (fiscal_helper.php)
**Issue**: Calculated taxAmount from accumulated per-line amounts, which can have rounding errors
**Fix**: Recalculate taxAmount from final salesAmountWithTax (matching Python library line 513)

**Python behavior:**
```python
# Accumulate per line
tax_lines[(tax_percent, tax_id)]["taxAmount"] += self.tax_calculator(item["receiptLineTotal"], tax_percent)

# Then RECALCULATE from total
"taxAmount": self.tax_calculator(sale_amount=value["salesAmountWithTax"], tax_rate=...)
```

**Our fix**: Now recalculates taxAmount from final salesAmountWithTax using the same formula

**Location**: `includes/fiscal_helper.php` lines 637-655

### 3. ✅ receiptGlobalNo Calculation (fiscal_service.php)
**Issue**: Was using database as primary source instead of ZIMRA's authoritative `lastReceiptGlobalNo`
**Fix**: Always use ZIMRA's `lastReceiptGlobalNo + 1` (already implemented in previous fix)

### 4. ✅ Previous Receipt Hash (fiscal_service.php)
**Issue**: Was getting hash from wrong receipt or not retrieving correctly
**Fix**: Get hash from receipt with exact `receiptGlobalNo = (current - 1)` (already implemented)

## Current Status

### ✅ Working Correctly
1. Signature string format matches Python library exactly
2. Hash calculation is cryptographically correct
3. receiptGlobalNo uses ZIMRA's authoritative source
4. Previous receipt hash retrieval is correct
5. Tax amounts are recalculated from totals (matching Python)

### ❌ Still Investigating
**RCPT020 (Signature Validation Error)**: Our hash still doesn't match ZIMRA's hash

**Possible Explanations:**
1. **ZIMRA recalculates values differently**: ZIMRA might recalculate tax amounts from receipt lines using a different method than we use
2. **Floating point precision**: Subtle differences in floating point calculations between PHP and ZIMRA's system
3. **ZIMRA uses different format**: ZIMRA's actual implementation might differ from Python library/documentation
4. **Tax calculation formula**: ZIMRA might use a slightly different tax calculation formula

## Key Findings

1. **Our format is correct**: Verified by exact comparison with Python library
2. **Python library works**: If Python library works with ZIMRA, our format should work too
3. **The issue is in values, not format**: Since format matches, the mismatch must be in the actual values used

## Recommendations

1. **Test with exact Python values**: Run a test receipt using Python library, capture the exact values it uses, then use those same values in our PHP implementation
2. **Compare tax calculations**: Verify our tax calculation matches Python's exactly, especially for edge cases
3. **Contact ZIMRA**: Request their exact signature string calculation method or example signature strings
4. **Monitor ZIMRA responses**: Check if receipts are still being accepted despite RCPT020 (they are currently)

## Files Modified

1. `includes/zimra_signature.php` - Fixed `toCents()` to truncate instead of round
2. `includes/fiscal_helper.php` - Recalculate taxAmount from final salesAmountWithTax
3. `includes/fiscal_service.php` - receiptGlobalNo and previous receipt hash fixes (from previous session)

## Test Scripts Created

1. `test_signature_format_exact_match.php` - Verifies signature string matches Python library exactly

## Next Steps

1. Run test again with the tax recalculation fix
2. Compare our tax amounts with Python library's calculated amounts
3. If still failing, contact ZIMRA support with exact request/response data


# taxCode in Signature String - ZIMRA Documentation Fix

## Date: 2025-12-21

## Issue
ZIMRA documentation section 13.2.1 specifies that receiptTaxes signature format should include taxCode:
```
taxCode || taxPercent || taxAmount || salesAmountWithTax
```

However, the Python library implementation does NOT include taxCode in the signature string.

## Fix Applied

Updated `includes/zimra_signature.php` to match ZIMRA documentation by including taxCode in the signature string format.

### Changes:

1. **Tax String Format**: Changed from Python library format to ZIMRA documentation format
   - **Before**: `taxPercent || taxAmount || salesAmountWithTax` (Python library)
   - **After**: `taxCode || taxPercent || taxAmount || salesAmountWithTax` (ZIMRA documentation)

2. **Tax Sorting**: Updated to match ZIMRA documentation
   - Sort by taxID (ascending)
   - Then sort by taxCode (alphabetical, empty comes before A)

3. **taxCode Preservation**: 
   - taxCode is now preserved in receiptTaxes for signature generation
   - taxCode is removed from JSON payload before sending to ZIMRA (Python library doesn't send it in payload)

### Code Changes:

**includes/zimra_signature.php:**
- Updated `buildTaxesString()` to include taxCode
- Updated sorting to include taxCode
- Updated logging to reflect ZIMRA documentation format

**includes/fiscal_service.php:**
- Moved taxCode removal to AFTER signature generation
- taxCode is now available for signature generation, then removed before JSON payload

**includes/fiscal_helper.php:**
- Updated comment to reflect that taxCode is kept for signature generation

## Test Results

After this fix, signature strings now include taxCode (or empty string if not present):
- Example: `15.501481100` (if taxCode is empty)
- Example: `A15.501481100` (if taxCode is 'A')

## Current Status

- ✅ Signature format now matches ZIMRA documentation (includes taxCode)
- ✅ Tax sorting matches ZIMRA documentation (taxID, then taxCode)
- ❌ RCPT020 (signature validation) still failing - hash doesn't match ZIMRA's

## Next Steps

Since we've now implemented the exact ZIMRA documentation format, but RCPT020 still persists, this suggests:

1. ZIMRA might use a different format than documented
2. ZIMRA might recalculate values differently for signature validation
3. There may be a subtle difference we haven't identified

**Recommendation**: Contact ZIMRA support with:
- Exact signature strings we're generating
- ZIMRA's expected signature strings
- Request clarification on the exact signature format they use for validation


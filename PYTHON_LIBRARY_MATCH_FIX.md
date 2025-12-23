# Python Library Signature Format Match Fix

## Date: 2025-12-21

## Issue

Our PHP implementation was including `taxCode` in the signature string based on ZIMRA documentation, but the Python library (which works correctly) does NOT include `taxCode` in the signature string.

## Python Library Format (Lines 202-211, 526-532)

**Tax Concatenation** (line 207):
```python
f"{float(tax['taxPercent']):.2f}{int(tax['taxAmount']*100)}{int(tax['salesAmountWithTax']*100)}"
```

**Signature String** (line 528):
```python
f"{deviceID}{receiptType.upper()}{receiptCurrency.upper()}{receiptGlobalNo}{receiptDate}{int(receiptTotal*100)}{concatenated_receipt_taxes}{previous_hash}"
```

**Key Points**:
- NO `taxCode` in the signature string
- Format: `taxPercent (2 decimals) || taxAmount (cents) || salesAmountWithTax (cents)`
- Sorting: By `taxID` only (line 204: `sorted(receiptTaxes, key=lambda x: (x['taxID']))`)

## Fix Applied

Updated `includes/zimra_signature.php` to match Python library exactly:

### Changes:

1. **Removed taxCode from signature string**
   - **Before**: `taxCode || taxPercent || taxAmount || salesAmountWithTax`
   - **After**: `taxPercent || taxAmount || salesAmountWithTax` (matching Python)

2. **Updated tax sorting**
   - **Before**: Sort by `taxID`, then by `taxCode` (alphabetical, empty first)
   - **After**: Sort by `taxID` only (matching Python library line 204)

3. **Updated comments and logging**
   - Changed all references from "ZIMRA Documentation Format" to "Python Library Format"
   - Updated log messages to reflect Python library format

### Code Changes:

**includes/zimra_signature.php**:
- `buildTaxesString()`: Removed taxCode concatenation
- `buildTaxesString()`: Simplified sorting to taxID only
- Updated all comments and log messages to reference Python library format

## Verification

The PHP implementation now matches the Python library exactly:

| Aspect | Python Library | PHP Implementation | Match |
|--------|---------------|-------------------|-------|
| Tax Format | `taxPercent || taxAmount || salesAmountWithTax` | `taxPercent || taxAmount || salesAmountWithTax` | ✅ |
| taxCode in signature | NO | NO | ✅ |
| Tax Sorting | By taxID only | By taxID only | ✅ |
| Signature String | All 8 fields for all currencies | All 8 fields for all currencies | ✅ |

## Note on ZIMRA Documentation

The ZIMRA documentation (section 13.2.1) mentions `taxCode` in the format, but the working Python library implementation does NOT include it. We're following the Python library format since it's proven to work correctly.

## Testing

After this fix, the signature string format should exactly match the Python library's format, which should help resolve RCPT020 signature validation errors.


# Signature Format Verification - Python Library Comparison

## Date: 2025-12-21

## Test Results

✅ **SIGNATURE STRING FORMAT: MATCHES PYTHON LIBRARY EXACTLY**

Test performed with actual receipt data from test run:
- deviceID: 30199
- receiptType: FiscalInvoice
- receiptCurrency: USD
- receiptGlobalNo: 7
- receiptDate: 2025-12-21T20:55:10
- receiptTotal: 11.00
- receiptTaxes: taxPercent=15.5, taxAmount=1.48, salesAmountWithTax=11.00
- previousReceiptHash: gA5U61llQnNBFWf82SJhiEFlShS5OrtecPLOnFnM2W4=

### Results
- **Our Signature String**: `30199FISCALINVOICEUSD72025-12-21T20:55:10110015.501481100gA5U61llQnNBFWf82SJhiEFlShS5OrtecPLOnFnM2W4=`
- **Python Signature String**: `30199FISCALINVOICEUSD72025-12-21T20:55:10110015.501481100gA5U61llQnNBFWf82SJhiEFlShS5OrtecPLOnFnM2W4=`
- **Match**: ✅ YES (character-by-character identical)
- **Our Hash**: `5gCIsf+oGfA2I2R6Y7aY3hVVImdA1G5qDcKUAkIAAPA=`
- **Python Hash**: `5gCIsf+oGfA2I2R6Y7aY3hVVImdA1G5qDcKUAkIAAPA=`
- **Hash Match**: ✅ YES

## Conclusion

Our signature string format **EXACTLY matches** the Python library implementation. This confirms:

1. ✅ Field order is correct
2. ✅ Field formatting is correct
3. ✅ Tax string format is correct
4. ✅ Numeric conversions (cents) are correct
5. ✅ Hash calculation is correct

## Why ZIMRA's Hash Doesn't Match

Since our format matches Python's exactly, but ZIMRA's hash still doesn't match, this indicates:

**ZIMRA is recalculating values (especially tax amounts) from receipt lines for signature validation.**

This means:
- We send tax amounts we calculated
- ZIMRA recalculates tax amounts from receipt lines
- If our calculated amounts don't match ZIMRA's recalculated amounts, the signature will be wrong

## Python Library Implementation Reference

### Signature String Format (line 528)
```python
string_to_sign = f"{self.deviceID}{receiptData["receiptType"].upper()}{receiptData["receiptCurrency"].upper()}{receiptData["receiptGlobalNo"]}{receiptData["receiptDate"]}{int(receiptData["receiptTotal"]*100)}{concatenated_receipt_taxes}{previous_hash}"
```

### Tax Concatenation (line 207)
```python
f"{float(tax['taxPercent']):.2f}{int(tax['taxAmount']*100)}{int(tax['salesAmountWithTax']*100)}"
```

### Tax Calculation (line 159)
```python
tax_amount = round(((sale_amount * tax_rate) / (1 + tax_rate)), 2)
```

## Our Implementation Status

✅ **Matches Python library exactly:**
- Signature string format: ✅
- Tax string format: ✅
- Numeric conversions: ✅ (fixed `toCents()` to use `intval()` without `round()`)
- Hash calculation: ✅

## Next Steps

Since our format matches Python's exactly, the RCPT020 error is likely due to:
1. ZIMRA recalculating tax amounts differently than we calculate them
2. ZIMRA using a different signature format than documented/Python library
3. ZIMRA using different values from the request payload

**Recommendation**: Contact ZIMRA support to:
- Request their exact signature string calculation method
- Ask if they recalculate tax amounts for signature validation
- Request example signature strings for test receipts


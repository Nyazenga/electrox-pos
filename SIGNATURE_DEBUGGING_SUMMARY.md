# ZIMRA Signature Debugging Summary

## Problem
Receipts are being rejected with `RCPT020` (Invalid Signature) error. Our calculated hash does not match ZIMRA's expected hash.

## Our Signature String Format
Based on ZIMRA documentation section 13.2.1, we're using:
```
deviceID || receiptType || receiptCurrency || receiptGlobalNo || receiptDate || receiptTotal || receiptTaxes || previousReceiptHash
```

**Example from our logs:**
```
30200FISCALINVOICEUSD122025-12-19T00:00:07300A15.5040300
```

Breaking down:
- `30200` = deviceID
- `FISCALINVOICE` = receiptType (uppercase)
- `USD` = receiptCurrency (uppercase)
- `12` = receiptGlobalNo
- `2025-12-19T00:00:07` = receiptDate (ISO 8601, no milliseconds/timezone)
- `300` = receiptTotal in cents (3 * 100)
- `A15.5040300` = receiptTaxes string
  - `A` = taxCode
  - `15.50` = taxPercent (formatted to 2 decimal places)
  - `40` = taxAmount in cents (0.4 * 100)
  - `300` = salesAmountWithTax in cents (3 * 100)
- (no previousReceiptHash for first receipt)

## Hash Comparison
- **Our hash:** `GwIq3b160mD03D4NR75HKZSOukHHxWtxZMvBLihmCfI=`
- **ZIMRA's hash:** `Z/ptktsSO1G1B+m/k6S1owKmOJZC/qxs8Op0oZZpO7Q=`
- **Match:** NO

## Variations Tested (70+)
We tested the following variations without success, including both Example 1 and Example 2 formats from ZIMRA documentation:

### Numeric Formatting
1. taxPercent as integer (15 instead of 15.50)
2. taxPercent with one decimal (15.5 instead of 15.50)
3. taxPercent with 3 decimal places (15.500)
4. taxPercent as decimal (0.155)
5. taxPercent with leading zero (015.50)
6. taxPercent as integer * 100 (1550)

### Amount Formatting
7. Zero-padded amounts (0040, 0300)
8. Zero-padded amounts 6 digits (000040, 000300)
9. Zero-padded amounts 8 digits
10. Zero-padded receiptTotal (0300)
11. receiptTotal as decimal (3.00)
12. taxAmount and salesAmount as decimals (0.40, 3.00)
13. All amounts as decimals
14. receiptTotal without cents conversion (3)
15. All amounts without cents conversion
16. receiptTotal as float string (300.00)
17. All amounts as float strings

### Field Formatting
18. Zero-padded receiptGlobalNo (0012)
19. Zero-padded deviceID (030200)
20. Date without seconds
21. Date with Z suffix
22. Date with milliseconds
23. Date with timezone
24. Lowercase currency (usd)
25. Lowercase receiptType

### Field Order
26. Taxes before receiptTotal
27. Date before receiptGlobalNo
28. Tax string: taxPercent before taxCode
29. Tax string: salesAmount before taxAmount

### Additional Fields
30. Include taxID in tax string
31. Include receiptCounter
32. Include invoiceNo
33. Include receiptLinesTaxInclusive
34. Include receiptPayments
35. Include receiptPrintForm
36. Include username fields
37. Include receiptNotes
38. Include receipt lines
39. Use serverDate instead of receiptDate
40. Include empty previousReceiptHash

### Separators
41. Tax string with separators
42. All fields with separators

### JSON Values
43. Use exact JSON numeric format (15.5 not 15.50)
44. All numbers as JSON format

### ZIMRA Documentation Examples
45. Example 1 format (with receiptType and receiptCurrency) - NO MATCH
46. Example 2 format (without receiptType and receiptCurrency) - NO MATCH
47. Example 2 format without taxCode - NO MATCH
48. Using serverDate instead of receiptDate - NO MATCH
49. Including receiptID from ZIMRA response - NO MATCH
50. Different field orders based on examples - NO MATCH

**CRITICAL FINDING:** When we verified Example 2 from the ZIMRA documentation itself, the calculated hash did NOT match the documented hash. This suggests:
- The documentation may have errors
- There may be undocumented formatting requirements
- ZIMRA's actual implementation may differ from the documentation

## Signature Verification
Our signature is cryptographically valid - it can be verified with our public key. This confirms:
- The private key is correct
- The signature algorithm is correct
- The issue is with the signature string format, not the signing process

## Possible Explanations
1. **Documentation incomplete/incorrect** - The ZIMRA documentation may not accurately reflect the actual implementation
2. **ZIMRA bug** - There may be a bug in ZIMRA's signature validation
3. **Currency-specific rules** - USD may have different formatting rules than ZWL
4. **Additional fields** - ZIMRA may include fields not mentioned in the documentation
5. **Different field order** - ZIMRA may use a different field order than documented
6. **Certificate mismatch** - The certificate/private key may not match what ZIMRA expects

## Recommendations
1. **Contact ZIMRA Support** with:
   - The exact request body sent
   - The exact response received
   - The signature string format you're using
   - Request their exact signature string format or SDK

2. **Check for ZIMRA SDK** - There may be an official SDK that shows the correct format

3. **Verify Certificate** - Ensure the certificate/private key matches what ZIMRA expects

4. **Test with ZWL currency** - See if the issue is specific to USD

5. **Check ZIMRA logs** - If available, check ZIMRA's logs to see what signature string they're expecting

## Current Status
- ✅ Tax calculations are correct (RCPT025/RCPT026 resolved)
- ✅ Signature generation is cryptographically correct
- ❌ Signature string format doesn't match ZIMRA's expectations
- ❌ Receipts are being rejected with RCPT020

## Next Steps
1. Contact ZIMRA support for clarification
2. Test with a different currency (ZWL) if possible
3. Verify certificate/private key matches ZIMRA's records
4. Check for ZIMRA SDK or example implementations


# PAYLOAD COMPARISON: Python vs PHP

## KEY DIFFERENCES FOUND:

### 1. **Data Types:**
- **Python**: Uses floats (`10.0`, `0.0`)
- **PHP**: Uses integers (`10`, `0`)

### 2. **taxCode Field:**
- **Python**: Does NOT include `taxCode` in `receiptLines` or `receiptTaxes`
- **PHP**: Includes `taxCode: "C"` in both `receiptLines` and `receiptTaxes`

### 3. **Field Order:**
- **Python**: `taxPercent` comes before `taxID` in `receiptTaxes`
- **PHP**: `taxID` comes before `taxPercent` in `receiptTaxes`

## PYTHON PAYLOAD (WORKING):
```json
{
  "Receipt": {
    "receiptType": "FISCALINVOICE",
    "receiptCurrency": "USD",
    "receiptCounter": 1,
    "receiptGlobalNo": 1,
    "invoiceNo": "PYTHON-LOG-20251221151528",
    "receiptDate": "2025-12-21T15:15:28",
    "receiptLinesTaxInclusive": true,
    "receiptLines": [{
      "receiptLineType": "Sale",
      "receiptLineNo": 1,
      "receiptLineHSCode": "04021099",
      "receiptLineName": "Test Item",
      "receiptLinePrice": 10.0,
      "receiptLineQuantity": 1,
      "receiptLineTotal": 10.0,
      "taxPercent": 0.0,
      "taxID": 2
    }],
    "receiptTaxes": [{
      "taxPercent": 0.0,
      "taxID": 2,
      "taxAmount": 0.0,
      "salesAmountWithTax": 10.0
    }],
    "receiptPayments": [{
      "moneyTypeCode": 0,
      "paymentAmount": 10.0
    }],
    "receiptTotal": 10.0,
    "receiptDeviceSignature": {...}
  }
}
```

## PHP PAYLOAD (NOT WORKING):
```json
{
  "Receipt": {
    "receiptType": "FISCALINVOICE",
    "receiptCurrency": "USD",
    "receiptCounter": 1,
    "receiptGlobalNo": 1,
    "invoiceNo": "PHP-LOG-20251221151530",
    "receiptDate": "2025-12-21T15:15:30",
    "receiptLinesTaxInclusive": true,
    "receiptLines": [{
      "receiptLineType": "Sale",
      "receiptLineNo": 1,
      "receiptLineHSCode": "04021099",
      "receiptLineName": "Test Item",
      "receiptLinePrice": 10,        // INTEGER, not float
      "receiptLineQuantity": 1,
      "receiptLineTotal": 10,        // INTEGER, not float
      "taxCode": "C",                // EXTRA FIELD - NOT IN PYTHON
      "taxPercent": 0,               // INTEGER, not float
      "taxID": 2
    }],
    "receiptTaxes": [{
      "taxID": 2,                    // DIFFERENT ORDER
      "taxCode": "C",                // EXTRA FIELD - NOT IN PYTHON
      "taxPercent": 0,               // INTEGER, not float
      "taxAmount": 0,                // INTEGER, not float
      "salesAmountWithTax": 10       // INTEGER, not float
    }],
    "receiptPayments": [{
      "moneyTypeCode": 0,            // OK - converted to integer
      "paymentAmount": 10            // INTEGER, not float
    }],
    "receiptTotal": 10,              // INTEGER, not float
    "receiptDeviceSignature": {...}
  }
}
```

## FIXES NEEDED:
1. Remove `taxCode` from `receiptLines` and `receiptTaxes`
2. Convert all numeric values to floats (10.0 instead of 10)
3. Reorder `receiptTaxes` fields: `taxPercent` before `taxID`


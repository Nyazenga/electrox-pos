# Log Analysis: Bad Certificate Signature Error

## Problem Identified from Logs

The logs show that counters are being sorted **ALPHABETICALLY** instead of by **NUMERIC PRIORITY**:

### Current (WRONG) Order in Signature:
```
BALANCEBYMONEYTYPE...CREDITNOTEBYTAX...CREDITNOTETAXBYTAX...SALEBYTAX...SALETAXBYTAX
```

### Correct Order (Python/ZIMRA expects):
```
SALEBYTAX...SALETAXBYTAX...CREDITNOTEBYTAX...CREDITNOTETAXBYTAX...BALANCEBYMONEYTYPE
```

## Root Cause

The old code was using **alphabetical sorting** (`strcmp`) for counter types, but ZIMRA expects **numeric priority sorting**:
- SaleByTax = Priority 1
- SaleTaxByTax = Priority 2
- CreditNoteByTax = Priority 3
- CreditNoteTaxByTax = Priority 4
- DebitNoteByTax = Priority 5
- DebitNoteTaxByTax = Priority 6
- BalanceByMoneyType = Priority 7

## Fix Applied

1. **Changed sorting to use numeric priorities** (matching Python implementation exactly)
2. **Added comprehensive logging** to show:
   - Original counters (before sorting)
   - Sorted counters (after sorting, with priorities)
   - Complete signature string
   - Full payload sent to ZIMRA

## What the Logs Show

From Device 30200, Day 13:
- Signature string: `30200132026-01-04BALANCEBYMONEYTYPEUSDCASH786253CREDITNOTEBYTAX...`
- This shows BALANCEBYMONEYTYPE first (alphabetically), but it should be LAST (priority 7)
- The counters are in alphabetical order, not priority order

## Next Steps

1. **Wait for GitHub workflow to deploy** the new code to live server
2. **Test again** with 3 consecutive sales and close fiscal day
3. **Check logs** for the new "COUNTERS SORTING (MATCHING PYTHON)" section
4. **Verify** the signature string shows counters in priority order (1,2,3,4,7)

## Expected Log Output After Fix

You should see:
```
[timestamp] ========== COUNTERS SORTING (MATCHING PYTHON) ==========
[timestamp] [0] Type: SaleByTax (priority: 1) | Currency: USD | TaxID: 1 | ...
[timestamp] [1] Type: SaleByTax (priority: 1) | Currency: USD | TaxID: 2 | ...
[timestamp] [2] Type: SaleTaxByTax (priority: 2) | Currency: USD | TaxID: 514 | ...
[timestamp] [3] Type: CreditNoteByTax (priority: 3) | Currency: USD | TaxID: 2 | ...
[timestamp] [4] Type: BalanceByMoneyType (priority: 7) | Currency: USD | MoneyType: Cash | ...
```

And the signature string should start with:
```
30200132026-01-04SALEBYTAXUSD...SALETAXBYTAXUSD...CREDITNOTEBYTAXUSD...BALANCEBYMONEYTYPEUSD...
```

NOT:
```
30200132026-01-04BALANCEBYMONEYTYPEUSD...CREDITNOTEBYTAXUSD...
```


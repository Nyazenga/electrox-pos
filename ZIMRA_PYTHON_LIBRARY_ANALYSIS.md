# ZIMRA Python Library Analysis - Critical Findings

## Date: 2025-12-19
## Analysis of: `zimra-public/zimra/__init__.py`

---

## CRITICAL FINDINGS - Answers to ZIMRA Questions

### 1. Receipt Signature Format (USD vs ZWL)

**Question:** Are there currency-specific signature requirements?

**Answer from Python Library (Lines 526-530):**
```python
if previous_hash:
    string_to_sign = f"{deviceID}{receiptType.upper()}{receiptCurrency.upper()}{receiptGlobalNo}{receiptDate}{int(receiptTotal*100)}{concatenated_receipt_taxes}{previous_hash}"
else:
    string_to_sign = f"{deviceID}{receiptType.upper()}{receiptCurrency.upper()}{receiptGlobalNo}{receiptDate}{int(receiptTotal*100)}{concatenated_receipt_taxes}"
```

**CRITICAL:** ALL currencies (USD and ZWL) use the **SAME format**: All 8 fields in order:
1. deviceID
2. receiptType (uppercase)
3. receiptCurrency (uppercase)
4. receiptGlobalNo
5. receiptDate
6. receiptTotal (in cents)
7. receiptTaxes (concatenated)
8. previousReceiptHash (if not first receipt)

**The documentation Example 2 (USD) is misleading/incomplete** - it shows only `receiptTaxes + previousReceiptHash`, but the actual implementation uses all 8 fields for ALL currencies.

---

### 2. Tax Concatenation Format

**Question:** What is the exact format for concatenating receiptTaxes?

**Answer from Python Library (Lines 202-211):**
```python
def concatenate_receipt_taxes(self, receiptTaxes):
    receiptTaxes_sorted = sorted(receiptTaxes, key=lambda x: (x['taxID']))
    concatenated_string = ''.join(
        f"{float(tax['taxPercent']):.2f}{int(tax['taxAmount']*100)}{int(tax['salesAmountWithTax']*100)}" 
        for tax in receiptTaxes_sorted
    )
    return concatenated_string
```

**CRITICAL:** The format is:
- **taxPercent** (formatted to 2 decimals, e.g., "15.00", "0.00", "14.50")
- **taxAmount** (in cents, integer)
- **salesAmountWithTax** (in cents, integer)

**NO taxCode, NO taxID in the concatenation!**

Sorting: By taxID only (ascending order).

---

### 3. Fiscal Day Signature Format (The "L" Character Issue)

**Question:** Is the "L" between "USD" and "CASH" (USDLCASH) intentional?

**Answer from Python Library (Lines 773-780):**
```python
concatenated_counters = ''.join(
    f"{counter['fiscalCounterType'].upper()}"
    f"{counter['fiscalCounterCurrency'].upper()}"
    f"{'{:.2f}'.format(counter['fiscalCounterTaxPercent']) if counter.get('fiscalCounterTaxPercent') is not None else ''}"
    f"{money_type_mapping.get(counter.get('fiscalCounterMoneyType'), '')}"  # Map money type to string
    f"{int(float(counter['fiscalCounterValue']) * 100)}"  # Convert to cents
    for counter in sorted_counters
    if float(counter['fiscalCounterValue']) != float(0)
)
```

**CRITICAL:** There is **NO "L" character**! The format is:
- fiscalCounterType (uppercase)
- fiscalCounterCurrency (uppercase)
- fiscalCounterTaxPercent (2 decimals) OR fiscalCounterMoneyType (directly, e.g., "CASH", "CARD")
- fiscalCounterValue (in cents)

**The documentation example showing "USDLCASH" is a TYPO** - it should be "USDCASH".

---

### 4. First Receipt in Fiscal Day

**Question:** How to handle first receipt when previousReceiptHash is null?

**Answer from Python Library (Lines 526-530):**
```python
if previous_hash:
    string_to_sign = f"{deviceID}...{previous_hash}"
else:
    string_to_sign = f"{deviceID}...{concatenated_receipt_taxes}"  # No previous_hash
```

**Answer:** Simply omit the `previousReceiptHash` field from the signature string. The format is the same (all 8 fields), but field 8 is omitted.

---

## IMPLEMENTATION FIXES APPLIED

### 1. Receipt Signature Generation (`zimra_signature.php`)
- ✅ **FIXED:** Removed currency-specific format logic
- ✅ **FIXED:** Now uses same format for ALL currencies (all 8 fields)
- ✅ **FIXED:** Tax concatenation now uses: `taxPercent || taxAmount || salesAmountWithTax` (NO taxCode, NO taxID)

### 2. Fiscal Day Signature Generation (`zimra_signature.php`)
- ✅ **VERIFIED:** No "L" character is added (implementation was already correct)
- ✅ **CONFIRMED:** Format is: `type || currency || percentOrMoneyType || valueCents`

### 3. Comprehensive Logging System (`zimra_logger.php`)
- ✅ **CREATED:** Logs to TXT file (human-readable)
- ✅ **CREATED:** Logs to error.log
- ✅ **CREATED:** Logs to database (zimra_operation_logs, zimra_receipt_logs, zimra_certificates)
- ✅ **CREATED:** Certificate storage (NOT encrypted for now)

---

## TEST DEVICES

### Device 30199
- Serial: electrox-1
- Activation Key: 00544726
- Status: Reset by ZIMRA (fresh start)

### Device 30200
- Serial: electrox-2
- Activation Key: 00294543
- Status: Reset by ZIMRA (fresh start)

---

## NEXT STEPS

1. ✅ Register both devices
2. ✅ Store certificates (plain text, not encrypted)
3. ✅ Log all operations (txt, log, database)
4. ⏳ Test receipt submission with corrected signature format
5. ⏳ Test fiscal day operations (open, close)
6. ⏳ Verify all signatures match ZIMRA's expectations

---

## FILES MODIFIED

1. `electrox-pos/includes/zimra_signature.php`
   - Fixed receipt signature format (all currencies use same format)
   - Fixed tax concatenation (removed taxCode and taxID)

2. `electrox-pos/includes/zimra_logger.php` (NEW)
   - Comprehensive logging system
   - Certificate storage (not encrypted)

3. `electrox-pos/includes/fiscal_service.php`
   - Fixed counter calculation (uses fiscal_day_no instead of fiscal_day_id)

---

## DOCUMENTATION DISCREPANCIES

The ZIMRA documentation v7.2 has the following issues:

1. **Example 2 (USD)** - Shows only `receiptTaxes + previousReceiptHash`, but actual implementation uses all 8 fields
2. **Fiscal Day Example** - Shows "USDLCASH" but should be "USDCASH" (no "L" character)
3. **Tax Concatenation** - Documentation shows taxCode in examples, but Python library doesn't include it

**Recommendation:** Use the Python library implementation as the source of truth, as it's a working implementation that has been tested with ZIMRA's API.


# Fiscal Day Close Decimal Format Fix

## Problem

When closing a fiscal day, two issues occurred:

1. **Deprecated Warnings**: PHP 8.1+ deprecation warnings about implicit float-to-int conversion:
   ```
   Deprecated: Implicit conversion from float 15.5 to int loses precision
   ```
   Occurred on lines 1085, 1086, 1088, 1096, 1097, 1099 in `fiscal_service.php`

2. **ZIMRA API Validation Error**: 
   ```
   Error closing fiscal day: ZIMRA API Error (UNKNOWN): Bad Request | 
   Validation errors: {"fiscalDayCounters[2].FiscalCounterValue":["provided value FiscalCounterValue do not satisfy decimal(21,2)."]}
   ```

## Root Causes

### 1. Float-to-Int Conversion Warnings

**Issue**: Using float values (like `15.5`) as array keys causes PHP 8.1+ to issue deprecation warnings because floats are implicitly converted to integers (15.5 becomes 15).

**Location**: In `calculateFiscalDayCounters()`, the code was using `$taxPercent` (a float) directly as an array key:
```php
$salesByTax[$currency][$taxID][$taxPercent] += $salesAmountWithTax;
```

### 2. Decimal Format Validation Error

**Issue**: ZIMRA API requires `fiscalCounterValue` to satisfy `decimal(21,2)` format, which means:
- Maximum 21 digits before the decimal point
- **Exactly 2 digits after the decimal point**

When sending a float like `15.5` in JSON, it's encoded as `15.5` (without trailing zero). ZIMRA's strict validation rejects this because it doesn't have exactly 2 decimal places.

**Location**: In `calculateFiscalDayCounters()`, counter values were being set directly from float calculations:
```php
'fiscalCounterValue' => $value  // $value is a float like 15.5
```

## Solutions Applied

### 1. Fixed Float-to-Int Conversion Warnings

**Solution**: Convert `$taxPercent` to a string when using it as an array key:

```php
// Convert taxPercent to string for use as array key (prevents float-to-int deprecation warning)
$taxPercentKey = (string)$taxPercent;

// Use string key in arrays
$salesByTax[$currency][$taxID][$taxPercentKey] += $salesAmountWithTax;
$salesTaxByTax[$currency][$taxID][$taxPercentKey] += $taxAmount;
```

**Result**: No more deprecation warnings, and array keys are properly preserved.

### 2. Fixed Decimal Format Validation

**Solution**: Format all `fiscalCounterValue` values as strings with exactly 2 decimal places using `number_format()`:

```php
// Format value as decimal string with exactly 2 decimal places (required by ZIMRA decimal(21,2))
// ZIMRA API validation requires decimal(21,2) format - must have exactly 2 decimal places
// Using string format ensures trailing zeros are preserved in JSON
$formattedValue = number_format($value, 2, '.', '');

$counters[] = [
    'fiscalCounterType' => 'SaleByTax',
    'fiscalCounterCurrency' => $currency,
    'fiscalCounterTaxID' => $taxID > 0 ? $taxID : null,
    'fiscalCounterTaxPercent' => $taxPercent > 0 ? $taxPercent : null,
    'fiscalCounterValue' => $formattedValue // String with exactly 2 decimal places
];
```

**Applied to**:
- `SaleByTax` counters
- `SaleTaxByTax` counters  
- `BalanceByMoneyType` counters

**Result**: All counter values are now formatted as strings with exactly 2 decimal places (e.g., "15.50" instead of 15.5), satisfying ZIMRA's `decimal(21,2)` validation.

## Technical Details

### Why String Format?

1. **JSON Encoding**: When JSON encodes a number `15.5`, it becomes `15.5` (no trailing zero). When it encodes a string `"15.50"`, it becomes `"15.50"` (trailing zero preserved).

2. **ZIMRA Validation**: ZIMRA's API validation checks the string representation and requires exactly 2 decimal places.

3. **Signature Compatibility**: The signature generation code (`toCents()`) handles both strings and numbers correctly, as PHP automatically converts strings to floats during arithmetic operations.

### Example

**Before**:
```json
{
  "fiscalCounterValue": 15.5
}
```
❌ ZIMRA rejects: "provided value FiscalCounterValue do not satisfy decimal(21,2)"

**After**:
```json
{
  "fiscalCounterValue": "15.50"
}
```
✅ ZIMRA accepts: Value satisfies decimal(21,2) format

## Testing

To verify the fix:

1. **Close a fiscal day** with receipts that have tax percentages like 15.5%
2. **Check error logs** - should see no deprecation warnings
3. **Verify ZIMRA response** - should accept the close request without validation errors
4. **Check counter values** - should be formatted with exactly 2 decimal places

## Files Modified

- `includes/fiscal_service.php`:
  - Fixed float-to-int conversion warnings (lines ~1079-1099)
  - Fixed decimal format for all counter types (lines ~1115-1162)

## Related Issues

- PHP 8.1+ deprecation warnings for float array keys
- ZIMRA API decimal(21,2) validation requirements
- JSON encoding of decimal values


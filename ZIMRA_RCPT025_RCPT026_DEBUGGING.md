# ZIMRA RCPT025 and RCPT026 Error Debugging Guide

## Current Status

Despite correct calculations and matching tax values from `getConfig`, ZIMRA is still returning:
- **RCPT025**: Invalid tax is used
- **RCPT026**: Incorrectly calculated tax amount

## What We've Verified

✅ **Tax Calculation (RCPT026)**: Our calculation matches the expected formula
- Formula: `taxAmount = SUM(receiptLineTotal) * taxPercent/(1+taxPercent)`
- For tax-inclusive: We convert percentage (15.5) to decimal (0.155) for the formula
- Result: `325 * (0.155 / 1.155) = 43.61` ✓

✅ **Tax Matching (RCPT025)**: We're using exact values from ZIMRA `getConfig`
- taxID: 4 (from ZIMRA)
- taxPercent: 15.5 (from ZIMRA)
- taxCode: A (mapped from ZIMRA)

## Possible Root Causes

### 1. FDMS Tax Registration Mismatch
**Issue**: The taxID/taxPercent combination in FDMS might differ from what `getConfig` returns.

**Solution**: Contact ZIMRA support to verify:
- What taxID is registered in FDMS for 15.5% VAT?
- What exact taxPercent value is registered (15.5, 15.50, or 0.155)?
- Is the tax valid for the receipt date being used?

### 2. Tax Validity Period Issue
**Issue**: RCPT025 requires `receiptDate` to be within the tax's `taxValidFrom` and `taxValidTill` period.

**Current Status**: ZIMRA `getConfig` is not returning `taxValidFrom`/`taxValidTill` fields, so we cannot verify this.

**Solution**: 
- Check with ZIMRA if the tax has validity dates
- Verify the receipt date is within the valid period
- Request ZIMRA to include validity dates in `getConfig` response

### 3. TaxPercent Format Issue
**Issue**: ZIMRA documentation says `Decimal (5,2)` but the formula `taxPercent/(1+taxPercent)` suggests decimal form (0.155).

**What We've Tried**:
- ✅ Sending as number: `15.5`
- ✅ Sending as string: `"15.50"`
- ✅ Using exact value from `getConfig`

**Solution**: Verify with ZIMRA:
- Should `taxPercent` be sent as percentage (15.5) or decimal (0.155)?
- How should `decimal(5,2)` be interpreted in the API?

### 4. TaxID Assignment Issue
**Issue**: If ZIMRA doesn't provide `taxID` in `getConfig`, we assign it sequentially (1, 2, 3, 4...), which might not match FDMS.

**Solution**: Verify with ZIMRA:
- Does `getConfig` return `taxID`? (Check logs for "ZIMRA response already includes taxID")
- If not, what is the correct taxID for 15.5% VAT in FDMS?

## Information to Provide ZIMRA Support

When contacting ZIMRA support, provide:

1. **Device Information**:
   - Device ID: [Your device ID]
   - Branch: [Your branch]

2. **Tax Configuration from getConfig**:
   ```
   taxID: 4
   taxPercent: 15.5
   taxCode: A
   taxName: Standard rated 15.5%
   ```

3. **Receipt Data Being Sent**:
   ```
   receiptDate: 2025-12-18T23:02:51
   receiptLines: 2 items, total 325
   receiptTaxes: taxID=4, taxPercent=15.5, taxCode=A, taxAmount=43.61
   ```

4. **Error Details**:
   - Error Codes: RCPT025, RCPT026
   - Receipt ID: 10395177
   - Operation ID: 0HNHM698NBAIU:00000001

5. **Questions to Ask**:
   - What is the exact taxID/taxPercent combination registered in FDMS for this device?
   - Is the tax valid for receiptDate 2025-12-18T23:02:51?
   - Should taxPercent be sent as 15.5 (percentage) or 0.155 (decimal)?
   - What is the expected taxAmount calculation for a tax-inclusive receipt with total 325 and taxPercent 15.5%?

## Testing Steps

### Step 1: Re-sync Configuration
1. Go to fiscal configuration page
2. Click "Sync Configuration from ZIMRA"
3. Check logs for raw `applicableTaxes` response
4. Verify taxID, taxPercent, and any validity dates

### Step 2: Check Logs
After making a sale, check `logs/error.log` for:
- Exact taxPercent value from ZIMRA
- Tax calculation details
- RCPT025/RCPT026 summary

### Step 3: Manual API Test (if needed)
If you have access to ZIMRA API testing tools, try submitting with:
- Different taxPercent formats (15.5, 15.50, 0.155)
- Different taxID values
- Different receipt dates

## Next Steps

1. **Immediate**: Re-sync ZIMRA configuration and check logs for raw tax data
2. **Contact ZIMRA**: Provide the information above and ask for FDMS tax configuration verification
3. **Test**: Once ZIMRA confirms the correct values, update the code accordingly

## Code Location

- Tax calculation: `electrox-pos/includes/fiscal_helper.php` (function `fiscalizeSale`)
- Tax configuration sync: `electrox-pos/includes/fiscal_service.php` (function `syncConfig`)
- Logs: `electrox-pos/logs/error.log`


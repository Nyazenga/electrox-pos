# Fiscalization Not Being Called - Diagnosis

## Problem
Sale 87 was created but **NO fiscalization was attempted**. No logs show fiscalization code was executed.

## Findings

### Sale 87 Details
- ✅ Exists in database (`electrox_primary`)
- ✅ Branch ID: 1 (HEAD OFFICE)
- ✅ Fiscalization enabled for branch
- ❌ No fiscal receipt
- ❌ No fiscalization logs

### No Logs Found
- ❌ No "PROCESS SALE: Script started" logs
- ❌ No "FISCALIZATION" logs
- ❌ No ZIMRA API calls

## Possible Causes

### 1. Wrong Endpoint Called
The sale might have been created through `api/v1/sales.php` instead of `ajax/process_sale.php`. Check:
- Browser network tab to see which endpoint was called
- If API endpoint, verify it has fiscalization code

### 2. Error Before Fiscalization
The script might be failing before reaching fiscalization code, but the sale transaction already committed.

### 3. Logging Disabled
`error_log()` might not be working, but this is unlikely since other logs appear.

## Solution

**Check which endpoint created the sale:**
1. Open browser DevTools (F12)
2. Go to Network tab
3. Make a new sale
4. Look for the request that creates the sale
5. Check the URL - is it `ajax/process_sale.php` or `api/v1/sales.php`?

**If it's `api/v1/sales.php`:**
- Verify it has fiscalization code (it does, at line 358)
- Check if it's being called correctly

**If it's `ajax/process_sale.php`:**
- The logs should appear but don't
- This suggests an error or the script isn't being called

## Immediate Action

Make a **new sale** and:
1. Check browser network tab immediately
2. Check error logs immediately after
3. Report which endpoint was called


# Fiscal Day Status Display Fix

## Problem

The fiscal day status display was showing "Closed" even though ZIMRA API was reporting that a fiscal day was open, causing errors when trying to open a new fiscal day:

```
Error opening fiscal day: ZIMRA API Error (FISC01): Unprocessable Entity | 
Full response: {"type":"https://httpstatuses.io/422","title":"Unprocessable Entity","status":422,"detail":"Open new fiscal day is not possible while current fiscal day is not closed.","errorCode":"FISC01","operationID":"0HNHM698NJVMT:00000001"}
```

## Root Causes

1. **Incomplete Status Checking**: The display logic only checked for `FiscalDayOpened` status, but didn't account for `FiscalDayCloseFailed` status, which also means the day is "open" and needs to be closed.

2. **Silent Error Handling**: When `getFiscalDayStatus()` failed to fetch status from ZIMRA (due to exceptions), it returned `null` silently. The display then fell back to local database, which might show "Closed" even though ZIMRA actually had an open day.

3. **Missing Error Recovery**: When `openDay()` API call returned FISC01 error (day not closed), the code didn't attempt to sync with ZIMRA to get the actual status and return the existing open day.

4. **No User Warning**: Users weren't warned when ZIMRA status couldn't be fetched, leading to confusion about the actual state.

## Solutions Applied

### 1. Enhanced Status Display Logic (`fiscalization.php`)

**Before:**
- Only checked for `FiscalDayOpened` status
- No warning when ZIMRA status couldn't be fetched
- Simple fallback to local database

**After:**
- Checks for both `FiscalDayOpened` AND `FiscalDayCloseFailed` statuses (both mean day is "open")
- Shows warning (⚠) when ZIMRA status couldn't be fetched
- Displays "Close Failed - Retry Close" message when status is `FiscalDayCloseFailed`
- Tracks status source (ZIMRA, local, or assumed) for debugging

### 2. Improved Error Logging (`fiscal_service.php` - `getFiscalDayStatus()`)

**Before:**
```php
catch (Exception $e) {
    error_log("Error getting fiscal day status: " . $e->getMessage());
    return null;
}
```

**After:**
- Logs detailed error information including device ID, exception class, and stack trace
- Helps diagnose why ZIMRA status can't be fetched
- Logs successful status fetches for debugging

### 3. Enhanced `openFiscalDay()` Error Handling

**Before:**
- Only checked status before calling `openDay()`
- If `openDay()` returned FISC01 error, it was thrown as-is
- Didn't attempt to sync and return existing day

**After:**
- Checks for both `FiscalDayOpened` and `FiscalDayCloseFailed` statuses
- Catches FISC01 errors from `openDay()` API call
- Attempts to sync with ZIMRA when FISC01 error occurs
- Returns existing open day if sync succeeds
- Provides helpful error messages

### 4. Better Status Synchronization

The code now properly handles all "open" statuses:
- `FiscalDayOpened`: Day is open and active
- `FiscalDayCloseFailed`: Previous close attempt failed, day still needs closing

Both statuses prevent opening a new fiscal day and require closing the current day first.

## How It Works Now

### Status Display Flow

1. **Page Loads**: For each branch with registered device:
   - Attempts to fetch ZIMRA status via `getFiscalDayStatus()`
   - If successful: Uses ZIMRA status (source of truth)
   - If fails: Falls back to local database, shows warning
   - Checks for all "open" statuses: `FiscalDayOpened` or `FiscalDayCloseFailed`

2. **Status Display**:
   - **"Open"** badge: Day is `FiscalDayOpened` or `FiscalDayCloseFailed`
   - **"Closed"** badge: Day is `FiscalDayClosed` or no status available
   - **Warning**: Shows ⚠ if ZIMRA status couldn't be fetched
   - **Close Failed**: Shows "Close Failed - Retry Close" message

### Opening Fiscal Day Flow

1. **Pre-check**: Calls `getStatus()` to check current status
2. **Status Check**: If day is `FiscalDayOpened` or `FiscalDayCloseFailed`:
   - Syncs local database
   - Returns existing day (doesn't throw error)
3. **Open Attempt**: Calls `openDay()` API
4. **Error Handling**: If `openDay()` returns FISC01:
   - Attempts to sync with ZIMRA
   - Returns existing open day if sync succeeds
   - Throws helpful error if sync fails

## User Experience Improvements

1. **Clear Status Display**: Users can now see the actual fiscal day status from ZIMRA
2. **Warning Indicators**: Users are warned when ZIMRA status couldn't be fetched
3. **Better Error Messages**: Error messages explain what needs to be done (close current day first)
4. **Automatic Recovery**: System attempts to sync and recover when errors occur

## Testing Recommendations

1. **Test Status Display**:
   - Verify "Open" shows when ZIMRA has `FiscalDayOpened` status
   - Verify "Open" shows when ZIMRA has `FiscalDayCloseFailed` status
   - Verify warning shows when ZIMRA status can't be fetched
   - Verify "Closed" shows when ZIMRA has `FiscalDayClosed` status

2. **Test Opening Fiscal Day**:
   - Try opening when day is already open (should return existing day)
   - Try opening when day close failed (should return existing day with message)
   - Try opening when day is closed (should succeed)
   - Verify error messages are helpful

3. **Test Error Scenarios**:
   - Network errors when fetching status
   - ZIMRA API errors
   - Certificate/authentication errors

## Files Modified

1. `modules/settings/fiscalization.php` - Enhanced status display logic
2. `includes/fiscal_service.php` - Improved error handling and status checking

## Related Documentation

- `FISCAL_DAY_STATUS_EXPLANATION.md` - Explains where status comes from
- `FISCAL_DAY_SYNC_FIX.md` - Explains sync mechanism
- `FISCAL_DAY_CLOSE_FIX.md` - Explains closing fiscal days


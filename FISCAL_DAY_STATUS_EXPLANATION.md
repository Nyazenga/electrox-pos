# Fiscal Day Status Table - Data Source Explanation

## Where the Status Comes From

The "Branch Device Status" table gets its data from **TWO sources**:

### 1. Local Database (Primary Database)
- **Device Info**: Device ID, Serial Number, Activation Key, Registration status
- **Certificate Info**: Whether certificate exists, expiry date
- **Fiscalization Enabled**: From `branches.fiscalization_enabled`
- **Local Fiscal Day**: From `fiscal_days` table (may be out of sync)

### 2. ZIMRA API (Real-Time)
- **Fiscal Day Status**: **Fetched directly from ZIMRA** when page loads
- **Fiscal Day Number**: Current or last fiscal day number
- **Source of Truth**: ZIMRA's status is authoritative

## How It Works

1. **Page Loads**: For each branch with a registered device:
   - Queries local database for device/config info
   - **Calls ZIMRA API** to get actual fiscal day status
   - Combines both sources for display

2. **Status Display Logic**:
   ```
   IF ZIMRA status available:
       Use ZIMRA status (source of truth)
   ELSE IF local database has open day:
       Use local status (fallback)
   ELSE:
       Show "Closed"
   ```

3. **Fiscal Day Column**:
   - **"Open"**: ZIMRA reports `FiscalDayStatus = 'FiscalDayOpened'`
   - **"Closed"**: ZIMRA reports `FiscalDayStatus = 'FiscalDayClosed'` OR no status available
   - Shows fiscal day number from ZIMRA if available

## Why This Matters

### Before Fix:
- Table only checked local database
- Could show "Closed" even if ZIMRA had an open day
- Led to errors when trying to open a new day

### After Fix:
- Table checks ZIMRA's actual status
- Shows real-time status from source of truth
- Prevents confusion and errors

## Current Situation

If the table shows "Closed" but you get an error saying a day is open:
1. **Refresh the page** - Status is fetched on each page load
2. **Check ZIMRA directly** - Use "Get Fiscal Day Status" button
3. **Close the day** - If ZIMRA says it's open, close it first

## Performance

- Status is fetched from ZIMRA for each branch when page loads
- If ZIMRA is slow/unavailable, falls back to local status
- Errors are logged but don't break the page display


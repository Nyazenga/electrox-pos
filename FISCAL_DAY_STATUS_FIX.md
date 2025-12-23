# Fiscal Day Status Display Fix

## Problem
The "Branch Device Status" table was showing "Closed" for fiscal days, but ZIMRA was reporting that a fiscal day was already open. This caused confusion because:
- Local database might not have the correct status
- ZIMRA's actual status wasn't being checked
- Table was only reading from local `fiscal_days` table

## Root Cause
The table was only checking the local database:
```php
$fiscalDay = $db->getRow(
    "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened'"
);
```

This doesn't reflect ZIMRA's actual status, which is the source of truth.

## Solution

### 1. Query ZIMRA Status Directly
- Now checks ZIMRA's actual status via `getFiscalDayStatus()` API call
- Falls back to local database if ZIMRA query fails
- Shows the real-time status from ZIMRA

### 2. Display Logic
- **If ZIMRA status available**: Shows ZIMRA's status (source of truth)
- **If ZIMRA unavailable**: Falls back to local database status
- **Shows fiscal day number**: From ZIMRA if available, otherwise from local DB

### 3. Status Indicators
- **Open**: Green badge with fiscal day number
- **Closed**: Yellow badge (may show last fiscal day number)
- **Error**: If can't connect to ZIMRA, shows local status with warning

## What You'll See Now

The table will now show:
- **Fiscal Day**: "Open" or "Closed" based on ZIMRA's actual status
- **Day Number**: Current or last fiscal day number from ZIMRA
- **Real-time**: Status is fetched from ZIMRA when page loads

## Why This Matters

- **ZIMRA is source of truth**: Local database can be out of sync
- **Prevents errors**: You'll see if a day is actually open before trying to open another
- **Accurate status**: Table reflects reality, not just local records

## Performance Note

- Status is fetched from ZIMRA for each branch when page loads
- If ZIMRA is slow/unavailable, it falls back to local status
- Errors are logged but don't break the page


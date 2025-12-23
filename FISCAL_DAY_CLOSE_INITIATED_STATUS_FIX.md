# Fiscal Day Close Initiated Status Display Fix

## Problem

After successfully initiating a fiscal day close (receiving operation ID), refreshing the page still showed the fiscal day status as "Open" even though the close request was submitted to ZIMRA.

**User Experience:**
1. User clicks "Close Fiscal Day"
2. Gets success message: "Fiscal day close initiated successfully! Operation ID: 0HNHM698NK3S7:00000001"
3. Refreshes the page
4. Status still shows "Open" (confusing!)

## Root Cause

**ZIMRA's Close Operation is Asynchronous:**
- When you submit a close request, ZIMRA returns an operation ID immediately
- ZIMRA processes the close request asynchronously (takes time)
- The local database is updated to `FiscalDayCloseInitiated` status
- But ZIMRA's `getStatus()` API still returns `FiscalDayOpened` until processing completes
- The display logic only checked ZIMRA status, which still showed "Open"

**Missing Status Check:**
- The code only queried for `FiscalDayOpened` status in local database
- It didn't check for `FiscalDayCloseInitiated` status
- So it couldn't detect when a close was in progress

## Solution

### 1. Added `FiscalDayCloseInitiated` Status Check

**Before:**
```php
$fiscalDay = $db->getRow(
    "SELECT * FROM fiscal_days WHERE ... AND status = 'FiscalDayOpened' ..."
);
```

**After:**
```php
$fiscalDay = $db->getRow(
    "SELECT * FROM fiscal_days WHERE ... AND status = 'FiscalDayOpened' ..."
);

// Also check for days that are in the process of closing (asynchronous operation)
$fiscalDayCloseInitiated = $db->getRow(
    "SELECT * FROM fiscal_days WHERE ... AND status = 'FiscalDayCloseInitiated' ..."
);
```

### 2. Enhanced Status Display Logic

**Added "Closing..." Status:**
- Detects when local database has `FiscalDayCloseInitiated` but ZIMRA still shows `FiscalDayOpened`
- Shows a blue "Closing..." badge instead of green "Open" badge
- Displays helpful message: "Close initiated, waiting for ZIMRA"
- Shows instruction: "⏳ Please wait, then refresh to check status"

**Status Priority:**
1. **Closing...** (blue badge): Close initiated, ZIMRA processing
2. **Open** (green badge): Day is open and active
3. **Closed** (yellow badge): Day is closed

### 3. User-Friendly Messages

When close is in progress:
- **Badge**: Blue "Closing..." badge (distinct from "Open")
- **Message**: "Close initiated, waiting for ZIMRA"
- **Instruction**: "⏳ Please wait, then refresh to check status"
- **Day Number**: Still shows the fiscal day number

## How It Works Now

### Status Flow

1. **User Initiates Close**:
   - Local database updated to `FiscalDayCloseInitiated`
   - ZIMRA receives close request (returns operation ID)
   - ZIMRA starts processing (asynchronous)

2. **Page Refresh (Before ZIMRA Completes)**:
   - Local database: `FiscalDayCloseInitiated` ✓
   - ZIMRA status: `FiscalDayOpened` (still processing)
   - **Display**: Shows "Closing..." badge with helpful message

3. **Page Refresh (After ZIMRA Completes)**:
   - Local database: Synced to `FiscalDayClosed` (via sync mechanism)
   - ZIMRA status: `FiscalDayClosed`
   - **Display**: Shows "Closed" badge

### Status Detection Logic

```php
if ($zimraStatus && isset($zimraStatus['fiscalDayStatus'])) {
    $isDayOpen = ($actualFiscalDayStatus === 'FiscalDayOpened' || ...);
    
    // Check if close was initiated but ZIMRA hasn't processed it yet
    if ($isDayOpen && $fiscalDayCloseInitiated && $actualFiscalDayStatus === 'FiscalDayOpened') {
        $isClosing = true; // Close is in progress
    }
} elseif ($fiscalDayCloseInitiated) {
    // Local database shows close was initiated
    $isClosing = true; // Close is in progress
}
```

## User Experience Improvements

### Before Fix:
- ❌ Confusing: Shows "Open" even after initiating close
- ❌ No indication that close is in progress
- ❌ User doesn't know to wait and refresh

### After Fix:
- ✅ Clear: Shows "Closing..." when close is in progress
- ✅ Informative: Explains that ZIMRA is processing
- ✅ Helpful: Instructs user to wait and refresh
- ✅ Visual distinction: Blue badge vs green "Open" badge

## Status Badges

| Status | Badge Color | When Shown |
|--------|------------|------------|
| **Closing...** | Blue (info) | Close initiated, ZIMRA processing |
| **Open** | Green (success) | Day is open and active |
| **Close Failed** | Red (danger) | Previous close attempt failed |
| **Closed** | Yellow (warning) | Day is closed |

## Testing

To verify the fix:

1. **Open a fiscal day**
2. **Initiate close** - Should get success message with operation ID
3. **Refresh page immediately** - Should see "Closing..." badge
4. **Wait a few minutes** (ZIMRA processes asynchronously)
5. **Refresh page again** - Should see "Closed" badge

## Important Notes

- **Asynchronous Processing**: ZIMRA processes close requests asynchronously
- **Wait Time**: May take a few minutes for ZIMRA to complete processing
- **Refresh Required**: User needs to refresh page to see updated status
- **Status Sync**: Local database syncs with ZIMRA when status is checked
- **Operation ID**: Save the operation ID for tracking if needed

## Files Modified

- `modules/settings/fiscalization.php`:
  - Added query for `FiscalDayCloseInitiated` status
  - Enhanced status display logic to detect closing state
  - Added "Closing..." badge and helpful messages

## Related Documentation

- `FISCAL_DAY_CLOSE_FIX.md` - Explains closing fiscal days
- `FISCAL_DAY_STATUS_DISPLAY_FIX.md` - Explains status display logic
- `FISCAL_DAY_SYNC_FIX.md` - Explains sync mechanism


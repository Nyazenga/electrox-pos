# Fiscal Day Close Fix

## Problem
Error when trying to open a fiscal day:
```
Error opening fiscal day: ZIMRA API Error (FISC01): Unprocessable Entity | 
Open new fiscal day is not possible while current fiscal day is not closed.
```

## Root Cause
A fiscal day is already open on ZIMRA's side, and you cannot open a new one until the current one is closed.

## Solution

### 1. Added "Close Fiscal Day" Action
- Added a new button in the Device Actions section
- Allows you to close the current fiscal day before opening a new one

### 2. Improved Error Handling
- `openFiscalDay()` now checks ZIMRA status first
- If a day is already open, it throws a clear error message telling you to close it first
- Shows the current fiscal day number in the error

### 3. How to Fix Your Current Situation

**Step 1: Close the Current Fiscal Day**
1. Go to Settings → Fiscalization (ZIMRA)
2. Scroll to "Device Actions" section
3. Find "Close Fiscal Day"
4. Select your branch (HEAD OFFICE)
5. Click "Close Day"
6. Wait for success message

**Step 2: Open a New Fiscal Day**
1. After closing, use "Open Fiscal Day" action
2. Select your branch
3. Click "Open Day"
4. Should work now!

## What Happens When You Close a Fiscal Day

1. **Calculates Counters**: Sums up all receipts for the day
2. **Generates Signature**: Creates a cryptographic signature for the day
3. **Sends to ZIMRA**: Submits close request to ZIMRA
4. **Updates Status**: Marks day as "FiscalDayCloseInitiated" in database
5. **Saves Counters**: Stores fiscal day counters for reporting

## Important Notes

- **Closing is asynchronous**: ZIMRA processes the close request asynchronously
- **Status updates**: Check status later to confirm day is fully closed
- **Receipts after close**: You cannot submit receipts after closing a day
- **Daily workflow**: Typically close at end of day, open at start of next day

## Typical Daily Workflow

**Morning:**
1. Check fiscal day status
2. If closed, open a new fiscal day
3. Start making sales (they'll be automatically fiscalized)

**Evening:**
1. Close fiscal day (optional - can be automated)
2. System is ready for next day


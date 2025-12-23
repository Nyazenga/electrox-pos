# Shifts vs Fiscal Days - ZIMRA Integration

## Overview

The Electro X POS system has **two separate but related concepts**:

1. **Shifts** - Business operations (cashier shifts, cash tracking)
2. **Fiscal Days** - ZIMRA regulatory requirement (mandatory for fiscalization)

## According to ZIMRA Documentation

### Fiscal Days (Section 2.3, 4.6, 4.10)

- **Purpose**: Regulatory requirement for submitting receipts to ZIMRA
- **Opening**: Must be opened before submitting any receipts (`openDay` API)
- **Closing**: Must be closed at end of day (`closeDay` API)
- **Frequency**: Typically **once per day** (not per shift)
- **Status**: Can be `FiscalDayOpened`, `FiscalDayCloseInitiated`, `FiscalDayCloseFailed`, or `FiscalDayClosed`

### Key Requirements from Documentation:

1. **"Fiscal device must open a new fiscal day before issuing receipts and invoices"** (Section 9.1)
2. **"Fiscal day opening message must be sent immediately after opening a fiscal day"** (Section 9.10)
3. **"Receipt must be sent to Fiscal Device Gateway API only after successfully opening a fiscal day"** (Section 9.11)
4. **"When work is finished with device, it must close fiscal day"** (Section 2.3)

## How They Work Together

### Typical Daily Workflow:

```
Morning:
1. First cashier opens shift → Fiscal day auto-opens (if enabled)
2. Multiple cashiers can work during the day (multiple shifts)
3. All receipts are submitted under the same fiscal day

Evening:
1. Last cashier closes shift → Reminder logged (fiscal day still open)
2. Manager manually closes fiscal day via Settings → Fiscalization
3. Next day: Process repeats
```

### Relationship:

- **One fiscal day** can span **multiple shifts** (e.g., morning shift + evening shift)
- **One shift** typically uses **one fiscal day** (the day's fiscal day)
- Fiscal day is **per branch**, shifts are **per cashier**

## Current Implementation

### Automatic Integration (NEW):

✅ **When Opening a Shift:**
- System checks if fiscalization is enabled for the branch
- Checks if a fiscal day is already open for today
- If not, **automatically opens a fiscal day** (first shift of the day only)
- Logs the action for audit trail

✅ **When Closing a Shift:**
- System checks if this is the last open shift of the day
- If yes, logs a reminder about closing the fiscal day
- **Does NOT auto-close** (closing requires manual verification and counter calculation)

### Manual Management:

You can still manually manage fiscal days via:
- **Settings → Fiscalization (ZIMRA) → Device Actions**
- Open/Close fiscal day buttons
- Status checking

## Best Practices

### Recommended Approach:

1. **Let the system auto-open** fiscal days when the first shift starts
2. **Manually close** fiscal days at end of day via Settings page
3. **Verify status** before closing (check all receipts are submitted)
4. **Close before midnight** to ensure clean day boundaries

### Why Not Auto-Close?

Fiscal day closing requires:
- Calculating all fiscal counters (by tax, currency, payment method)
- Generating cryptographic signatures
- Verifying all receipts are submitted
- Handling errors if validation fails

This is better done manually with verification.

## Configuration

### Enable/Disable Fiscalization:

1. Go to **Settings → Fiscalization (ZIMRA)**
2. Select branch
3. Toggle "Fiscalization Enabled"
4. Configure device details (Device ID, Serial No, Activation Key)

### Check Status:

- **Settings → Fiscalization (ZIMRA)** - Shows fiscal day status per branch
- **Administration → Fiscalization Status** - Overview of all branches

## Troubleshooting

### "Fiscal day is already open" Error:

- A fiscal day is already open for today
- Check status in Settings → Fiscalization
- Close the existing day before opening a new one

### "No open fiscal day" When Making Sales:

- Fiscal day was not opened (or was closed)
- Check if fiscalization is enabled for the branch
- Open a fiscal day via Settings → Fiscalization

### Receipts Not Fiscalizing:

1. Check fiscalization is enabled for branch
2. Check fiscal day is open
3. Check device is registered and has valid certificate
4. Check error logs for API errors

## Summary

- **Shifts** = Business operations (cash tracking, cashier management)
- **Fiscal Days** = ZIMRA regulatory requirement (receipt submission)
- **Integration**: Fiscal day auto-opens with first shift, manual close recommended
- **Frequency**: One fiscal day per day (not per shift)
- **Management**: Auto-open on shift start, manual close via Settings


# Fiscalization Settings Page Fix

## Changes Made

### 1. Database Connection
- **Changed**: From `Database::getInstance()` (tenant DB) to `Database::getPrimaryInstance()` (primary DB)
- **Reason**: Fiscal tables (`fiscal_devices`, `fiscal_config`, `fiscal_days`, `branches`) are stored in the PRIMARY database, not tenant-specific databases

### 2. Form Auto-Population
- **Added**: JavaScript to auto-populate form fields when a branch is selected
- **Fields populated**: Device ID, Serial Number, Activation Key, Model Name, Model Version
- **Benefit**: Users can see existing device configuration and edit it easily

### 3. Enhanced Status Table
- **Added columns**:
  - Activation Key (to verify correct key is stored)
  - Has Certificate (to see if device is registered)
  - Fiscal Day status (to see if fiscal day is open)
- **Enhanced display**:
  - Certificate expiry date shown if available
  - Fiscal day number shown if day is open
  - Better status badges and colors

### 4. Data Loading
- **Added**: Fiscal day status loading for each branch
- **Shows**: Whether fiscal day is open and which day number

## What You Should See Now

When you visit `http://localhost/electrox-pos/modules/settings/index.php?page=fiscalization`:

1. **Branch Device Status Table** should show:
   - All branches with their device configurations
   - Device ID: 30200 (for both branches)
   - Serial Number: electrox-2
   - Activation Key: 00294543
   - Registered: Yes
   - Has Certificate: Yes
   - Fiscalization Enabled: Enabled (for HEAD OFFICE)
   - Fiscal Day: Open (if fiscal day is open)
   - Status: Active

2. **Device Configuration Form**:
   - When you select a branch, the form should auto-populate with existing device data
   - You can edit and save changes

3. **All data is now read from PRIMARY database** (`electrox_primary`)

## Verification

The page should now correctly display:
- ✅ Device ID: 30200
- ✅ Serial Number: electrox-2
- ✅ Activation Key: 00294543
- ✅ Certificate status
- ✅ Fiscal day status
- ✅ Fiscalization enabled status


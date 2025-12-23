# Fiscalization Settings Page - Section Explanations

## Overview
This page allows you to configure and manage ZIMRA fiscalization for each branch. Each branch needs its own fiscal device registered with ZIMRA.

---

## Section 1: Device Configuration

### Purpose
**Configure the fiscal device details for each branch** before registering with ZIMRA.

### Fields Explained:

#### **Branch ***
- **Purpose**: Select which branch you're configuring
- **Why needed**: Each branch has its own fiscal device and must be configured separately
- **Example**: HEAD OFFICE or HILLSIDE

#### **Enable Fiscalization**
- **Purpose**: Turn fiscalization ON or OFF for the selected branch
- **Why needed**: You may want to disable fiscalization temporarily (maintenance, testing, etc.)
- **Behavior**: When disabled, sales/invoices for this branch will NOT be sent to ZIMRA

#### **Device ID ***
- **Purpose**: The unique device ID assigned by ZIMRA for this branch
- **Why needed**: ZIMRA uses this to identify which device is submitting receipts
- **Example**: 
  - HEAD OFFICE: 30199 (original) or 30200 (test)
  - HILLSIDE: 30200 (test - both branches using same for testing)

#### **Device Serial Number ***
- **Purpose**: A unique identifier for your physical/logical device
- **Why needed**: Used during device registration to link the device ID with your system
- **Example**: 
  - `electrox-1` (HEAD OFFICE)
  - `electrox-2` (HILLSIDE or test device)

#### **Activation Key ***
- **Purpose**: 8-character key provided by ZIMRA to activate the device
- **Why needed**: Required for device registration - proves you're authorized to use this device ID
- **Example**: 
  - HEAD OFFICE: `00544726`
  - HILLSIDE: `00294543`

#### **Device Model Name**
- **Purpose**: Name of your device model (as registered with ZIMRA)
- **Why needed**: ZIMRA requires this for device registration
- **Default**: `Server` (since this is a server-based POS system, not a physical fiscal printer)

#### **Device Model Version**
- **Purpose**: Version of your device model
- **Why needed**: ZIMRA requires this for device registration
- **Default**: `v1`

### When to Use This Section:
- **Initial Setup**: When setting up fiscalization for a new branch
- **Changing Device**: If you need to switch to a different device ID
- **Updating Credentials**: If activation key or serial number changes
- **Enabling/Disabling**: To turn fiscalization on or off for a branch

---

## Section 2: Device Actions

### Purpose
**Perform operations with ZIMRA** after device is configured.

### Actions Explained:

#### **1. Verify Taxpayer Information**
- **Purpose**: Check if the device ID, activation key, and serial number are correct BEFORE registering
- **Why needed**: 
  - Validates that you have the right credentials
  - Shows which taxpayer/business the device will be registered to
  - Prevents registration errors
- **When to use**: 
  - Before first registration
  - When troubleshooting registration issues
  - To verify credentials are correct
- **What it shows**:
  - Taxpayer Name
  - TIN (Tax Identification Number)
  - VAT Number
  - Branch Name and Address

#### **2. Register Device**
- **Purpose**: Register the device with ZIMRA and obtain a certificate
- **Why needed**: 
  - Device MUST be registered before it can submit receipts
  - Registration generates a certificate used for authentication
  - Without registration, fiscalization will fail
- **When to use**: 
  - First time setup
  - After changing device ID
  - If certificate was lost/revoked
- **What happens**:
  1. Generates a Certificate Signing Request (CSR)
  2. Sends CSR to ZIMRA with device details
  3. ZIMRA returns a certificate
  4. Certificate is saved in database (encrypted)
  5. Device is marked as "Registered"

#### **3. Sync Configuration**
- **Purpose**: Download configuration from ZIMRA (tax rates, QR code URL, etc.)
- **Why needed**: 
  - Gets the latest tax configuration
  - Gets the correct QR code URL for receipts
  - Ensures your system matches ZIMRA's settings
- **When to use**: 
  - After registration
  - When tax rates change
  - Periodically to stay in sync
- **What it downloads**:
  - Applicable tax rates (VAT, etc.)
  - QR code base URL
  - Other fiscal configuration

#### **4. Get Fiscal Day Status**
- **Purpose**: Check if a fiscal day is currently open
- **Why needed**: 
  - Receipts can only be submitted when fiscal day is OPEN
  - Shows current fiscal day number
  - Shows last receipt number
- **When to use**: 
  - Before making sales (to ensure day is open)
  - To check current status
  - Troubleshooting why receipts aren't being submitted
- **What it shows**:
  - Fiscal Day Status: `FiscalDayOpened` or `FiscalDayClosed`
  - Fiscal Day Number
  - Last Receipt Global Number

#### **5. Open Fiscal Day**
- **Purpose**: Open a new fiscal day (required before submitting receipts)
- **Why needed**: 
  - ZIMRA requires a fiscal day to be open before accepting receipts
  - Each day has a unique number
  - Receipts are numbered sequentially per day
- **When to use**: 
  - At the start of each business day
  - If fiscal day was closed
  - After system restart
- **What happens**:
  1. Opens a new fiscal day with ZIMRA
  2. Gets a fiscal day number
  - Receipts can now be submitted

---

## Section 3: Branch Device Status Table

### Purpose
**Overview of all branches and their fiscalization status** at a glance.

### What It Shows:
- **Branch**: Branch name
- **Device ID**: Configured device ID
- **Serial No**: Device serial number
- **Activation Key**: Activation key (for verification)
- **Registered**: Whether device is registered with ZIMRA
- **Has Certificate**: Whether device has a valid certificate
- **Fiscalization Enabled**: Whether fiscalization is turned on for this branch
- **Fiscal Day**: Whether fiscal day is currently open
- **Last Sync**: When configuration was last synced
- **Status**: Overall device status (Active, Expired, Not Configured, etc.)

### When to Use:
- **Quick Check**: See status of all branches at once
- **Troubleshooting**: Identify which branches have issues
- **Monitoring**: Check if certificates are expiring soon

---

## Typical Workflow

### First Time Setup:
1. **Configure Device** (Section 1):
   - Select branch
   - Enter Device ID, Serial Number, Activation Key
   - Enable fiscalization
   - Click "Save Device Settings"

2. **Verify Taxpayer** (Section 2):
   - Enter device details
   - Click "Verify"
   - Confirm taxpayer information is correct

3. **Register Device** (Section 2):
   - Select branch
   - Click "Register Device"
   - Wait for success message

4. **Sync Configuration** (Section 2):
   - Select branch
   - Click "Sync Config"
   - Tax rates and QR URL are downloaded

5. **Open Fiscal Day** (Section 2):
   - Select branch
   - Click "Open Day"
   - Fiscal day is now open

6. **Check Status** (Section 3):
   - Verify all statuses show green/active
   - System is ready for fiscalization!

### Daily Operations:
- **Morning**: Open Fiscal Day (if not already open)
- **During Day**: System automatically fiscalizes sales/invoices
- **Evening**: Close Fiscal Day (optional - can be done automatically)

### Troubleshooting:
- **No receipts being fiscalized**: Check if fiscal day is open
- **Registration errors**: Verify taxpayer information first
- **Certificate expired**: Re-register device
- **Wrong taxpayer**: Check device ID and activation key

---

## Important Notes

1. **Each branch needs its own device ID** (unless testing with shared device)
2. **Device must be registered** before fiscalization will work
3. **Fiscal day must be open** before submitting receipts
4. **Certificate is required** for all authenticated API calls
5. **Configuration should be synced** after registration and periodically


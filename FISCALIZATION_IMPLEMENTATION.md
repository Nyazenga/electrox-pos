# ZIMRA Fiscalization Implementation

## Overview
This document describes the complete implementation of ZIMRA (Zimbabwe Revenue Authority) fiscalization for the ELECTROX-POS system. The implementation follows the Fiscal Device Gateway API v7.2 specification.

## Implementation Status

### ✅ Completed Components

1. **Database Schema** (`database/fiscal_schema.sql`)
   - `fiscal_devices` - Stores device registration information per branch
   - `fiscal_days` - Tracks fiscal day status and information
   - `fiscal_receipts` - Stores fiscal receipt submissions
   - `fiscal_receipt_lines` - Stores receipt line items
   - `fiscal_receipt_taxes` - Stores tax information
   - `fiscal_receipt_payments` - Stores payment information
   - `fiscal_counters` - Stores fiscal day counters
   - `fiscal_config` - Stores configuration synced from ZIMRA

2. **ZIMRA API Client** (`includes/zimra_api.php`)
   - Complete implementation of all ZIMRA API endpoints
   - Supports both test and production environments
   - Handles client certificate authentication
   - Implements all required methods:
     - verifyTaxpayerInformation
     - registerDevice
     - issueCertificate
     - getConfig
     - getStatus
     - openDay
     - submitReceipt
     - closeDay
     - ping
     - submitFile (for offline mode)
     - getFileStatus

3. **Certificate Management** (`includes/zimra_certificate.php`)
   - CSR generation (ECC and RSA)
   - Certificate validation
   - Expiry checking

4. **Signature Generation** (`includes/zimra_signature.php`)
   - Receipt device signature generation
   - Fiscal day device signature generation
   - Follows ZIMRA specification section 13

5. **QR Code Generation** (`includes/zimra_qrcode.php`)
   - QR code URL generation
   - Verification code formatting
   - QR data generation from device signature

6. **Fiscal Service** (`includes/fiscal_service.php`)
   - Main service class for fiscalization operations
   - Device registration
   - Configuration syncing
   - Fiscal day management
   - Receipt submission

7. **Fiscal Helper** (`includes/fiscal_helper.php`)
   - Helper functions for fiscalizing invoices
   - Receipt data building
   - Payment method mapping

8. **Settings Page** (`modules/settings/fiscalization.php`)
   - Complete UI for managing fiscalization
   - Device configuration
   - Device registration
   - Configuration syncing
   - Fiscal day management
   - Status checking

9. **Integration**
   - Integrated into invoice status update flow
   - Automatic fiscalization when invoice is marked as Paid

## Device Configuration

### Head Office
- Device ID: 30199
- Activation Key: 00544726
- Serial Number: electrox-1

### Hillside
- Device ID: 30200
- Activation Key: 00294543
- Serial Number: electrox-2

## Setup Instructions

### 1. Run Database Migration
```bash
php setup_fiscal_tables.php
```

This will:
- Create all fiscal tables in the primary database
- Add `fiscalization_enabled` column to branches table
- Set up default device configurations

### 2. Configure Devices in Settings
1. Navigate to Settings > Fiscalization (ZIMRA)
2. Select a branch
3. Enter device details:
   - Device ID
   - Device Serial Number
   - Activation Key
4. Enable fiscalization for the branch
5. Click "Save Device Settings"

### 3. Verify Taxpayer Information
1. In the "Device Actions" section
2. Enter device details
3. Click "Verify" to verify taxpayer information with ZIMRA

### 4. Register Device
1. Select the branch
2. Click "Register Device"
3. This will:
   - Generate CSR
   - Register device with ZIMRA
   - Receive and store certificate
   - Sync configuration

### 5. Sync Configuration
1. Select the branch
2. Click "Sync Config"
3. This retrieves latest configuration from ZIMRA

### 6. Open Fiscal Day
1. Select the branch
2. Click "Open Fiscal Day"
3. This opens a new fiscal day for receipt submission

## Usage

### Automatic Fiscalization
When an invoice is marked as "Paid":
1. System checks if fiscalization is enabled for the branch
2. Checks if invoice type is fiscalizable (TaxInvoice or Receipt)
3. Opens fiscal day if not already open
4. Submits receipt to ZIMRA
5. Generates QR code
6. Updates invoice with fiscal details

### Manual Fiscal Day Management
- Open fiscal day: Settings > Fiscalization > Open Fiscal Day
- Close fiscal day: (To be implemented in UI)
- Check status: Settings > Fiscalization > Get Status

## Testing

### Test Connection
```bash
php test_zimra_connection.php
```

This script tests:
- Taxpayer information verification
- Certificate generation
- API connectivity (if device is registered)

## API Endpoints Used

### Test Environment
- Base URL: `https://fdmsapitest.zimra.co.zw`
- Swagger: `https://fdmsapitest.zimra.co.zw/swagger/index.html`

### Production Environment
- Base URL: `https://fdmsapi.zimra.co.zw`

## Important Notes

1. **Certificate Management**
   - Certificates are stored securely in the database
   - Certificates should be renewed before expiry
   - System checks for expiring certificates

2. **Fiscal Day Requirements**
   - Fiscal day must be open before submitting receipts
   - Receipts must be submitted in order (receiptGlobalNo)
   - Fiscal day must be closed at end of day

3. **Receipt Requirements**
   - All receipts must have valid tax information
   - HS codes required for VAT payers
   - Receipts must be signed before submission

4. **Error Handling**
   - Fiscalization errors are logged but don't block invoice creation
   - Check logs for fiscalization issues
   - Retry failed submissions manually

## Next Steps

1. **Add Fiscal Details to PDF Receipts**
   - Update receipt templates to include:
     - QR code
     - Verification code
     - Fiscal device information
     - Fiscal day number

2. **Implement Fiscal Day Closing UI**
   - Add button to close fiscal day
   - Display fiscal day report
   - Show counters and totals

3. **Add Retry Mechanism**
   - Queue failed submissions
   - Automatic retry for failed receipts
   - Manual retry option

4. **Add Reporting**
   - Fiscal day reports
   - Receipt submission status
   - Error reports

## Troubleshooting

### Device Registration Fails
- Verify activation key is correct
- Check device serial number format
- Ensure internet connectivity
- Check ZIMRA test environment status

### Receipt Submission Fails
- Verify fiscal day is open
- Check receipt counters are sequential
- Verify tax information is correct
- Check certificate is valid and not expired

### Certificate Issues
- Check certificate expiry date
- Renew certificate if expiring soon
- Verify certificate is properly stored

## Support

For issues with ZIMRA API:
- Check ZIMRA documentation
- Contact ZIMRA support team
- Verify test environment status

## Files Created/Modified

### New Files
- `database/fiscal_schema.sql`
- `includes/zimra_api.php`
- `includes/zimra_certificate.php`
- `includes/zimra_signature.php`
- `includes/zimra_qrcode.php`
- `includes/fiscal_service.php`
- `includes/fiscal_helper.php`
- `modules/settings/fiscalization.php`
- `setup_fiscal_tables.php`
- `test_zimra_connection.php`

### Modified Files
- `modules/settings/index.php` - Added fiscalization menu item
- `ajax/update_invoice_status.php` - Added fiscalization call
- `database/primary_schema.sql` - (Note: fiscal tables are in separate file)

## Testing Checklist

- [ ] Run database migration
- [ ] Configure devices for both branches
- [ ] Verify taxpayer information
- [ ] Register devices
- [ ] Sync configuration
- [ ] Open fiscal day
- [ ] Create and pay an invoice
- [ ] Verify receipt is fiscalized
- [ ] Check QR code generation
- [ ] Verify fiscal details in invoice
- [ ] Test connection script


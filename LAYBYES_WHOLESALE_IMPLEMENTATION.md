# Laybyes and Wholesale Sales Implementation Summary

## Overview
This document summarizes the comprehensive implementation of Laybyes (Layaways) and Wholesale/Dealer Sales features for the ELECTROX POS system.

## Database Changes

### Migration Script
Run the migration script to create all necessary database tables and columns:
```bash
php database/migrate_laybyes_wholesale.php
```

### New Tables Created
1. **laybyes** - Main laybye records
2. **laybye_items** - Items in each laybye
3. **laybye_payments** - Payment history for laybyes
4. **laybye_payment_schedule** - Scheduled payment dates and reminders

### Modified Tables
1. **products** - Added `wholesale_price` column
2. **sales** - Added `is_wholesale_sale` and `is_pending_payment` columns

## Features Implemented

### 1. Laybyes System
- **Create Laybyes**: Customers can reserve products without taking them immediately
- **Payment Tracking**: Track total amount, amount paid, and amount remaining
- **Payment Schedule**: Monthly or custom payment schedules
- **Payment Reminders**: Automated email reminders via cron job
- **Completion**: When fully paid, creates actual sale and assigns product_specific_list items

**Key Files:**
- `modules/sales/laybyes.php` - Main laybyes listing page
- `ajax/create_laybye.php` - Create new laybye
- `ajax/add_laybye_payment.php` - Add payment to laybye
- `ajax/complete_laybye.php` - Complete laybye and create sale

### 2. Wholesale/Dealer Sales
- **Wholesale Pricing**: Products can have separate wholesale prices
- **Admin-Only Access**: Only administrators can process wholesale sales
- **Pending Payment**: Wholesale sales can be marked as pending payment
- **Price Selection**: Automatically uses wholesale price when checkbox is checked

**Key Files:**
- `modules/pos/payment.php` - Added wholesale checkbox (admin only)
- `ajax/process_sale.php` - Handles wholesale prices and pending payments
- `ajax/get_cart_with_wholesale.php` - Updates cart with wholesale prices

### 3. Permissions
All permissions have been added to `database/seed_permissions.php`:
- `sales.laybyes` - View and manage laybyes
- `sales.laybyes.create` - Create new laybyes
- `sales.laybyes.edit` - Edit laybyes
- `sales.laybyes.complete` - Complete laybyes
- `sales.laybyes.cancel` - Cancel laybyes
- `sales.laybyes.add_payment` - Add payments to laybyes
- `reports.laybyes` - View laybye reports
- `sales.wholesale` - Process wholesale/dealer sales
- `reports.wholesale` - View wholesale sales reports

**To seed permissions:**
```bash
php database/seed_permissions.php
```

### 4. Cron Job
- **File**: `cron/laybye_payment_reminders.php`
- **Schedule**: Monthly on the 1st at 9:00 AM (configured in `cron/setup_crontab.sh`)
- **Function**: Sends email reminders to customers with upcoming or overdue payments

**To set up cron job:**
```bash
bash cron/setup_crontab.sh
```

## Workflow

### Laybye Workflow
1. Customer selects products and creates laybye (no product_specific_list items captured yet)
2. Customer makes payments over time
3. System tracks payments and updates amount remaining
4. When fully paid, admin completes laybye
5. System creates actual sale and assigns product_specific_list items at completion
6. Stock is deducted and receipt is generated

### Wholesale Sale Workflow
1. Admin selects products in POS
2. Admin checks "Wholesale/Dealer Sale" checkbox (only visible to admins)
3. Cart prices update to wholesale prices (if available)
4. Admin processes payment (can be $0 for pending payment)
5. Sale is marked as `is_wholesale_sale = 1` and `is_pending_payment = 1` if no payment
6. When dealer pays later, admin can update the sale

## Remaining Tasks

### High Priority
1. **Laybye Add Page** (`modules/sales/laybye_add.php`) - Create laybye from POS or sales page
2. **Laybye View Page** (`modules/sales/laybye_view.php`) - View details, add payments, complete laybye
3. **Reports Pages** - Laybye reports and wholesale sales reports

### Medium Priority
1. **Product Edit Page** - Add wholesale_price field to product add/edit forms
2. **Bulk Upload** - Support wholesale_price in bulk upload template
3. **Sales Reports** - Filter by wholesale sales

## Testing Checklist

- [ ] Run database migration
- [ ] Seed permissions
- [ ] Test creating laybye
- [ ] Test adding payments to laybye
- [ ] Test completing laybye
- [ ] Test wholesale sale checkbox (admin only)
- [ ] Test wholesale price selection
- [ ] Test pending payment flag
- [ ] Test cron job (manually run to verify)
- [ ] Test permissions

## Notes

1. **Product Specific List**: For products requiring specific list items, these are NOT captured when laybye is created. They are only captured when the laybye is completed (when fully paid).

2. **Wholesale Price**: If a product doesn't have a wholesale_price set, the system will use the regular selling_price even if wholesale sale is checked.

3. **Pending Payment**: Wholesale sales with pending payment can be updated later when the dealer brings payment.

4. **Fiscalization**: Wholesale sales follow the same fiscalization rules as regular sales (can be skipped if needed).

## Support

For issues or questions, refer to:
- Database migration: `database/migrate_laybyes_wholesale.php`
- Permissions: `database/seed_permissions.php`
- Cron setup: `cron/setup_crontab.sh`

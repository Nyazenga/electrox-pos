# Clear All Transactional Data Script

## Overview
This script permanently deletes all transactional data from the database while preserving system configuration, master data, and fiscalization settings.

## What Gets Deleted
- All sales, invoices, refunds, credit notes, debit notes
- All stock movements, transfers, stock takes
- All fiscal receipts and fiscal days
- All shifts, payments, and activity logs
- All laybyes and trade-ins
- All currency exchange rates
- **All products, product categories, and product data**
- **All customers and suppliers**

## What Gets Preserved
- ✅ System settings (`system_settings`, `pos_settings`)
- ✅ Fiscalization configuration (`fiscal_devices`, `fiscal_config`, `zimra_certificates`)
- ✅ Master data (`branches`, `currencies`, `users`, `roles`, `permissions`)
- ✅ Configuration (`payment_terms`, `proforma_terms`, `tenants`)

## Usage

### Clear All Databases (Primary + All Tenants)
```bash
php scripts/clear_all_transactional_data.php --confirm
```

### Clear Only Primary Database
```bash
php scripts/clear_all_transactional_data.php --confirm --tenant=primary
```

### Clear Specific Tenant Database
```bash
php scripts/clear_all_transactional_data.php --confirm --tenant=belgravia
```

### Preview (Without Confirmation)
```bash
php scripts/clear_all_transactional_data.php
```
This will show a warning and exit without making any changes.

## Safety Features
1. **Requires `--confirm` flag** - Script will not run without explicit confirmation
2. **CLI-only** - Cannot be run via web browser
3. **Transaction-safe** - Uses foreign key checks disabled during deletion
4. **Error handling** - Continues processing even if individual tables fail
5. **Detailed logging** - Shows exactly what was deleted

## Output
The script provides:
- Real-time progress for each table
- Count of records deleted per table
- Summary of total records deleted
- List of any errors encountered
- Confirmation of preserved data

## Example Output
```
🗑️  CLEAR ALL TRANSACTIONAL DATA SCRIPT
============================================================
Started at: 2026-02-20 12:00:00

📍 Processing PRIMARY database: electrox_primary
============================================================
  ✅ Deleted 271 records from 'sales'
  ✅ Deleted 283 records from 'fiscal_receipts'
  ✅ Deleted 45 records from 'refunds'
  ...

✅ Completed clearing transactional data from: electrox_primary
📊 Total records deleted: 1,234
📋 Tables cleared: 42

✅ SCRIPT COMPLETED SUCCESSFULLY
```

## Important Notes
- ⚠️ **This action is irreversible** - Make sure you have a backup before running
- The script automatically resets auto-increment counters
- Foreign key constraints are temporarily disabled during deletion
- Works on both local development and production servers
- Automatically detects and processes all active tenant databases

## Database Compatibility
- Works with MySQL/MariaDB
- Compatible with multi-tenant architecture
- Handles missing tables gracefully
- Supports both primary and tenant databases

## Troubleshooting
If you encounter errors:
1. Check database connection credentials in `config.php`
2. Ensure you have sufficient database permissions
3. Verify the database exists and is accessible
4. Check for any locked tables (may need to wait or restart MySQL)

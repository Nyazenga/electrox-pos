# Production Usage: Clear All Transactional Data Script

## ✅ Script is Production-Ready

The `clear_all_transactional_data.php` script has been tested and is ready for use on the live server.

## 🚀 How to Run on Live Server

### Step 1: SSH into the Live Server
```bash
ssh your-username@your-server-ip
```

### Step 2: Navigate to the Project Directory
```bash
cd /path/to/electrox-pos
```

### Step 3: Pull Latest Changes (if needed)
```bash
git pull origin main
```

### Step 4: Preview What Will Be Deleted (Optional)
```bash
php scripts/clear_all_transactional_data.php
```
This will show a warning and exit without making changes.

### Step 5: Run the Script with Confirmation
```bash
php scripts/clear_all_transactional_data.php --confirm
```

## 📋 What Gets Deleted

- ✅ All sales, invoices, refunds, credit notes, debit notes
- ✅ All stock movements, transfers, stock takes
- ✅ All fiscal receipts and fiscal days (historical data)
- ✅ All shifts, payments, and activity logs
- ✅ All products, product favorites, product specific lists
- ✅ All customers and suppliers
- ✅ All ZIMRA operation logs and receipt logs

## 🔒 What Gets Preserved

- ✅ **Fiscalization devices and certificates** (Device IDs 37542, 37543, activation keys)
- ✅ **System settings** (system_settings, pos_settings)
- ✅ **Fiscalization configuration** (fiscal_config)
- ✅ **Users, roles, and permissions**
- ✅ **Branch information**
- ✅ **Product categories** (preserved for future products)
- ✅ **Configuration** (payment_terms, proforma_terms, tenants)
- ✅ **Currencies**

## ⚠️ Important Notes

1. **This action is IRREVERSIBLE** - Make sure you have a backup before running
2. **The script auto-detects environment** - Uses production credentials automatically
3. **All tables are preserved** - Only data is deleted, not table structures
4. **Auto-increment counters are reset** - New records will start from ID 1

## 📊 Expected Output

The script will show:
- Real-time progress for each table
- Count of records deleted per table
- Summary of total records deleted
- Confirmation of preserved data

Example output:
```
🗑️  CLEAR ALL TRANSACTIONAL DATA SCRIPT
============================================================
Started at: 2026-02-20 12:00:00

🔌 Database Connection:
   Host: localhost
   Database: electrox_primary
   User: grcadmin
   Environment: PRODUCTION

📍 Processing PRIMARY database: electrox_primary
============================================================
  ✅ Deleted 271 records from 'sales'
  ✅ Deleted 734 records from 'sale_items'
  ...

✅ SCRIPT COMPLETED SUCCESSFULLY
```

## 🔄 After Running the Script

After clearing transactional data:
1. Product categories are preserved - you can add new products
2. Fiscalization devices remain configured - ready for new fiscal days
3. System settings remain intact - no reconfiguration needed
4. Users and permissions remain - no need to recreate accounts

## 🆘 Troubleshooting

If you encounter errors:
1. Check database credentials in `config.php`
2. Ensure you have sufficient database permissions
3. Verify the database exists and is accessible
4. Check for any locked tables

## 📞 Support

If you need help, check:
- `scripts/README_CLEAR_TRANSACTIONAL_DATA.md` - Full documentation
- `scripts/PRESERVATION_CONFIRMATION.md` - What gets preserved

# Database Connection and Error Fixes

## ✅ Fixed Issues

### 1. Tenant Database Connection
- **Problem**: Database class wasn't ensuring session was started before getting tenant name
- **Fix**: Added session initialization in `Database::getInstance()` and `Database::__construct()`
- **Files Modified**:
  - `includes/db.php` - Added session initialization
  - `config.php` - Updated `getCurrentTenantDbName()` to use `initSession()`

### 2. Currency Database Errors
- **Problem**: Currency functions were querying `electrox_base` or `electrox_primary` instead of tenant database
- **Fix**: Changed all currency functions to use `Database::getInstance()` (tenant database)
- **Files Modified**:
  - `includes/currency_functions.php` - All functions now use tenant database
  - `api/v1/currencies.php` - Changed from `getMainInstance()` to `getInstance()`
  - `modules/pos/receipt.php` - Changed from `getMainInstance()` to tenant `$db`

### 3. Customers Table Query Error
- **Problem**: Query was trying to filter by `branch_id` but `customers` table doesn't have that column
- **Fix**: Removed `branch_id` filter from customers count query
- **Files Modified**:
  - `modules/dashboard/index.php` - Removed branch_id filter

### 4. API Session Initialization
- **Problem**: API endpoints weren't initializing session, so tenant wasn't available
- **Fix**: Added `initSession()` call in `api/v1/_base.php`
- **Files Modified**:
  - `api/v1/_base.php` - Added session initialization

## 🔧 How It Works Now

1. **Session Initialization**:
   - `initSession()` is called early in request lifecycle
   - Session stores `tenant_name` (e.g., "primary")
   - Database class reads from session to determine which database to connect to

2. **Database Connection**:
   - `Database::getInstance()` automatically connects to `electrox_{tenant_name}`
   - If no tenant in session, falls back to `electrox_base`
   - Session is automatically started if needed

3. **Currency Queries**:
   - All currency queries now use tenant database
   - Currencies are stored per-tenant, not globally

## 📋 Remaining Issues (if any)

- Check if `customers` table should have `branch_id` column (currently removed from query)
- Verify all fiscal tables exist in `electrox_primary` database
- Ensure all scripts that need tenant connection call `initSession()` or include session.php

## ✅ Testing

After these fixes:
1. Currency queries should work (no more "Table 'electrox_primary.currencies' doesn't exist")
2. Database connections should automatically use logged-in tenant
3. Customers count query should work (no more "Column 'branch_id' not found")


# Tenant Database Connection Fixes

## ✅ All Fixes Applied

### 1. Database Class Session Initialization
- **File**: `includes/db.php`
- **Changes**:
  - Added session initialization in `getInstance()` method
  - Added session initialization in `__construct()` method
  - Improved `isConnectedToDatabase()` to verify active connection
  - Database now automatically reconnects when tenant changes

### 2. Config Function Update
- **File**: `config.php`
- **Changes**:
  - Updated `getCurrentTenantDbName()` to use `initSession()` instead of direct `session_start()`
  - Ensures proper session initialization

### 3. Currency Functions
- **File**: `includes/currency_functions.php`
- **Changes**:
  - Changed all functions from `Database::getMainInstance()` to `Database::getInstance()`
  - Currencies now queried from tenant database, not base/primary

### 4. Currency API
- **File**: `api/v1/currencies.php`
- **Changes**:
  - Changed from `Database::getMainInstance()` to `Database::getInstance()`
  - Currencies API now uses tenant database

### 5. Receipt Currency Query
- **File**: `modules/pos/receipt.php`
- **Changes**:
  - Changed from `Database::getMainInstance()` to tenant `$db` instance
  - Currency queries now use tenant database

### 6. API Base Session
- **File**: `api/v1/_base.php`
- **Changes**:
  - Added `require_once` for `session.php`
  - Added `initSession()` call at the start
  - Ensures session is available for all API endpoints

### 7. Dashboard Customers Query
- **File**: `modules/dashboard/index.php`
- **Changes**:
  - Removed `branch_id` filter from customers count query
  - Customers table doesn't have `branch_id` column

## 🔧 How It Works Now

1. **Session Initialization**:
   - `initSession()` is called early in request lifecycle
   - Session stores `tenant_name` (e.g., "primary")
   - All database connections read from session

2. **Automatic Tenant Connection**:
   - `Database::getInstance()` automatically:
     - Starts session if needed
     - Reads tenant from session
     - Connects to `electrox_{tenant_name}`
     - Reconnects if tenant changes

3. **Database Selection**:
   - **Tenant Database** (`electrox_primary`, `electrox_acme`, etc.):
     - Sales, invoices, products, customers, currencies
     - All operational data
   - **Primary Database** (`electrox_primary`):
     - Branches, fiscal devices, fiscal receipts
     - System-wide configuration
   - **Base Database** (`electrox_base`):
     - Tenant registry, admin users
     - System administration

## ✅ Errors Fixed

1. ✅ "Table 'electrox_primary.currencies' doesn't exist" - Fixed by using tenant database
2. ✅ "Column 'branch_id' not found in customers" - Fixed by removing branch_id filter
3. ✅ Database connection not using logged-in tenant - Fixed by session initialization

## 📋 Testing

After these fixes, you should see:
- ✅ No more currency database errors
- ✅ No more customers branch_id errors
- ✅ Database automatically connects to logged-in tenant
- ✅ All queries use correct database


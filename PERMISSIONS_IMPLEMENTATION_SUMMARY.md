# Permissions & Authorization Implementation Summary

## Overview
This document summarizes the comprehensive permissions and authorization system implemented for the ELECTROX POS system. All modules, pages, AJAX endpoints, and sidebar elements now have proper permission checks to ensure users can only access and perform actions they are authorized to do.

## Database Structure

### Tables
1. **permissions** - Stores all available permissions
   - `id` - Primary key
   - `permission_key` - Unique permission identifier (e.g., 'products.view')
   - `permission_name` - Human-readable name
   - `module` - Module category
   - `description` - Permission description

2. **role_permissions** - Junction table linking roles to permissions
   - `id` - Primary key
   - `role_id` - Foreign key to roles table
   - `permission_id` - Foreign key to permissions table

3. **roles** - User roles
   - `id` - Primary key
   - `name` - Role name
   - `description` - Role description
   - `is_system_role` - Flag for system roles

## Comprehensive Permissions List

All permissions are defined in `database/comprehensive_permissions.sql`. The system includes:

### Dashboard
- `dashboard.view` - View Dashboard

### Products Module
- `products.view` - View Products
- `products.create` - Create Products
- `products.edit` - Edit Products
- `products.delete` - Delete Products
- `products.categories` - Manage Categories

### Inventory Module
- `inventory.view` - View Inventory
- `inventory.create` - Create Inventory (GRN and transfers)
- `inventory.edit` - Edit Inventory
- `inventory.delete` - Delete Inventory
- `inventory.view_other_branches` - View Other Branches Inventory

### GRN (Goods Received Notes)
- `grn.view` - View GRN
- `grn.create` - Create GRN
- `grn.edit` - Edit GRN
- `grn.delete` - Delete GRN
- `grn.change_status` - Change GRN Status

### Stock Transfers
- `transfers.view` - View Transfers
- `transfers.create` - Create Transfers
- `transfers.edit` - Edit Transfers
- `transfers.delete` - Delete Transfers
- `transfers.change_status` - Change Transfer Status

### POS Module
- `pos.view` - View POS
- `pos.create_sale` - Create Sale
- `pos.manage_sales` - Manage Sales
- `pos.edit` - Edit Sales
- `pos.delete` - Delete Sales
- `pos.refund` - Process Refunds
- `pos.customize` - Customize POS
- `pos.cash_management` - Cash Management
- `pos.access` - General POS access

### Drawer Management
- `drawer.view` - View Drawer
- `drawer.transaction` - Drawer Transactions
- `drawer.report` - Drawer Reports

### Receipts
- `receipts.view` - View Receipts
- `receipts.print` - Print Receipts
- `receipts.email` - Email Receipts
- `receipts.refund` - Refund Receipts
- `receipts.delete` - Delete Receipts

### Sales Module
- `sales.view` - View Sales
- `sales.create` - Create Sales
- `sales.edit` - Edit Sales
- `sales.delete` - Delete Sales
- `sales.refund` - Refund Sales

### Invoicing Module
- `invoicing.view` - View Invoices
- `invoicing.create` - Create Invoices
- `invoicing.edit` - Edit Invoices
- `invoicing.delete` - Delete Invoices
- `invoicing.print` - Print Invoices
- `invoicing.change_status` - Change Invoice Status
- `invoicing.customize` - Customize Invoices

### Customers Module
- `customers.view` - View Customers
- `customers.create` - Create Customers
- `customers.edit` - Edit Customers
- `customers.delete` - Delete Customers

### Suppliers Module
- `suppliers.view` - View Suppliers
- `suppliers.create` - Create Suppliers
- `suppliers.edit` - Edit Suppliers
- `suppliers.delete` - Delete Suppliers

### Trade-Ins Module
- `tradeins.view` - View Trade-Ins
- `tradeins.create` - Create Trade-Ins
- `tradeins.edit` - Edit Trade-Ins
- `tradeins.delete` - Delete Trade-Ins
- `tradeins.process` - Process Trade-Ins

### Reports Module
- `reports.view` - View Reports (required for all report pages)
- `reports.sales` - Sales Reports
- `reports.inventory` - Inventory Reports
- `reports.financial` - Financial Reports

### Administration - Branches
- `branches.view` - View Branches
- `branches.create` - Create Branches
- `branches.edit` - Edit Branches
- `branches.delete` - Delete Branches
- `branches.switch` - Switch Branches

### Administration - Users
- `users.view` - View Users
- `users.create` - Create Users
- `users.edit` - Edit Users
- `users.delete` - Delete Users

### Administration - Roles
- `roles.view` - View Roles
- `roles.create` - Create Roles
- `roles.edit` - Edit Roles
- `roles.delete` - Delete Roles
- `roles.permissions` - Manage Permissions

### Administration - Currencies
- `currencies.view` - View Currencies
- `currencies.create` - Create Currencies
- `currencies.edit` - Edit Currencies
- `currencies.delete` - Delete Currencies

### Administration - Settings
- `settings.view` - View Settings
- `settings.edit` - Edit Settings

### Fiscalization Module
- `fiscalization.view_status` - View Fiscalization Status
- `fiscalization.view_all` - View All Fiscalizations
- `fiscalization.verify_taxpayer` - Verify Taxpayer
- `fiscalization.register_device` - Register Device
- `fiscalization.sync_config` - Sync Fiscal Config

## Implementation Details

### Page-Level Authorization
All module pages now have permission checks at the top:
```php
$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('module.action');
```

### AJAX Endpoint Authorization
All AJAX endpoints have been secured with permission checks:
- Authentication check: `$auth->requireLogin()`
- Permission check: `$auth->requirePermission('permission.key')` or conditional checks

### Sidebar Authorization
The sidebar menu items are conditionally displayed based on permissions:
- Main menu items: `$auth->hasAnyModulePermission('module')`
- Submenu items: `$auth->hasPermission('specific.permission')`

### Auth Class Methods
- `hasPermission($permission)` - Check if user has specific permission
- `hasAnyModulePermission($modulePrefix)` - Check if user has any permission for a module
- `requirePermission($permission)` - Require permission or show 403 error
- `requireLogin()` - Require user to be logged in

## Files Modified

### Database
- `database/comprehensive_permissions.sql` - Complete permissions list (NEW)

### Module Pages
- `modules/dashboard/index.php` - Added `dashboard.view` check
- All other module pages already had permission checks

### AJAX Endpoints
- `ajax/change_branch.php` - Added `branches.switch` check
- `ajax/send_receipt.php` - Added `receipts.email` check
- `ajax/send_receipt_email.php` - Added `receipts.email` check
- `ajax/save_pos_cart.php` - Added `pos.access` check
- `ajax/clear_pos_cart.php` - Added `pos.access` check
- `ajax/toggle_favorite.php` - Added permission check
- `ajax/get_customers.php` - Added permission check
- `ajax/get_category.php` - Added permission check
- `ajax/check_grn_number.php` - Added permission check
- `ajax/check_transfer_number.php` - Added permission check
- `ajax/check_supplier_code.php` - Added permission check

### Sidebar
- `includes/header.php` - Enhanced permission checks for:
  - Sales submenu items
  - Customers submenu items
  - Suppliers submenu items
  - Reports submenu items

## Security Features

1. **Defense in Depth**: Multiple layers of permission checks
   - Page-level checks prevent direct URL access
   - AJAX endpoint checks prevent API abuse
   - Sidebar checks provide UI-level security

2. **Wildcard Support**: Administrator role with `*` permission has access to everything

3. **Module-Level Checks**: `hasAnyModulePermission()` allows flexible module access control

4. **Session-Based**: Permissions are loaded into session on login and can be reloaded

## Next Steps

1. **Run the SQL file**: Execute `database/comprehensive_permissions.sql` to ensure all permissions exist in the database

2. **Assign Permissions to Roles**: Use the Roles & Permissions module to assign appropriate permissions to each role

3. **Test Authorization**: Test with different user roles to ensure proper access control

4. **API Endpoints**: Consider adding permission checks to API endpoints in `api/v1/` directory (currently pending)

## Notes

- The system supports backward compatibility with legacy permission keys (e.g., `invoices.*` alongside `invoicing.*`)
- Users can always view their own profile (`modules/users/profile.php`) without special permissions
- Permission checks are logged for debugging in development mode
- The Auth class automatically handles Administrator role with full access


-- Comprehensive Permissions System
-- This file contains ALL permissions needed for the ELECTROX POS system
-- 
-- IMPORTANT: This uses INSERT IGNORE, so it will safely skip permissions that already exist
-- If you want to check what's missing first, run: php database/check_existing_permissions.php
-- 
-- This includes:
-- 1. All permissions from add_permissions_system.sql (basic set)
-- 2. Additional permissions needed for full functionality
--
-- Run this to ensure all permissions are available in the database

-- Insert/Update all permissions (INSERT IGNORE skips existing ones)
INSERT IGNORE INTO `permissions` (`permission_key`, `permission_name`, `module`, `description`) VALUES
-- Dashboard
('dashboard.view', 'View Dashboard', 'Dashboard', 'View the main dashboard'),

-- Products Module
('products.view', 'View Products', 'Products', 'View product list'),
('products.create', 'Create Products', 'Products', 'Create new products'),
('products.edit', 'Edit Products', 'Products', 'Edit existing products'),
('products.delete', 'Delete Products', 'Products', 'Delete products'),
('products.categories', 'Manage Categories', 'Products', 'Manage product categories'),

-- Inventory Module
('inventory.view', 'View Inventory', 'Inventory', 'View stock levels'),
('inventory.create', 'Create Inventory', 'Inventory', 'Create GRN and transfers'),
('inventory.edit', 'Edit Inventory', 'Inventory', 'Edit inventory records'),
('inventory.delete', 'Delete Inventory', 'Inventory', 'Delete inventory records'),
('inventory.view_other_branches', 'View Other Branches Inventory', 'Inventory', 'View inventory from other branches'),

-- GRN (Goods Received Notes)
('grn.view', 'View GRN', 'Inventory', 'View goods received notes'),
('grn.create', 'Create GRN', 'Inventory', 'Create new goods received notes'),
('grn.edit', 'Edit GRN', 'Inventory', 'Edit goods received notes'),
('grn.delete', 'Delete GRN', 'Inventory', 'Delete goods received notes'),
('grn.change_status', 'Change GRN Status', 'Inventory', 'Approve or reject GRN'),

-- Stock Transfers
('transfers.view', 'View Transfers', 'Inventory', 'View stock transfers'),
('transfers.create', 'Create Transfers', 'Inventory', 'Create stock transfers'),
('transfers.edit', 'Edit Transfers', 'Inventory', 'Edit stock transfers'),
('transfers.delete', 'Delete Transfers', 'Inventory', 'Delete stock transfers'),
('transfers.change_status', 'Change Transfer Status', 'Inventory', 'Approve, receive or reject transfers'),

-- POS Module
('pos.view', 'View POS', 'POS', 'Access POS system'),
('pos.create_sale', 'Create Sale', 'POS', 'Create new sales in POS'),
('pos.manage_sales', 'Manage Sales', 'POS', 'View and manage existing sales'),
('pos.edit', 'Edit Sales', 'POS', 'Edit sales records'),
('pos.delete', 'Delete Sales', 'POS', 'Delete sales records'),
('pos.refund', 'Process Refunds', 'POS', 'Process refunds'),
('pos.customize', 'Customize POS', 'POS', 'Customize POS settings and layout'),
('pos.cash_management', 'Cash Management', 'POS', 'Manage cash drawer and shifts'),
('pos.access', 'Access POS', 'POS', 'General POS access permission'),

-- Drawer Management
('drawer.view', 'View Drawer', 'POS', 'View cash drawer'),
('drawer.transaction', 'Drawer Transactions', 'POS', 'Perform drawer transactions (pay in/out)'),
('drawer.report', 'Drawer Reports', 'POS', 'View drawer reports'),

-- Receipts
('receipts.view', 'View Receipts', 'POS', 'View receipts'),
('receipts.print', 'Print Receipts', 'POS', 'Print receipts'),
('receipts.email', 'Email Receipts', 'POS', 'Email receipts to customers'),
('receipts.refund', 'Refund Receipts', 'POS', 'Process receipt refunds'),
('receipts.delete', 'Delete Receipts', 'POS', 'Delete receipts'),

-- Sales Module
('sales.view', 'View Sales', 'Sales', 'View sales list'),
('sales.create', 'Create Sales', 'Sales', 'Create new sales'),
('sales.edit', 'Edit Sales', 'Sales', 'Edit sales records'),
('sales.delete', 'Delete Sales', 'Sales', 'Delete sales records'),
('sales.refund', 'Refund Sales', 'Sales', 'Process sales refunds'),

-- Invoicing Module
('invoicing.view', 'View Invoices', 'Invoicing', 'View invoice list'),
('invoicing.create', 'Create Invoices', 'Invoicing', 'Create new invoices'),
('invoicing.edit', 'Edit Invoices', 'Invoicing', 'Edit existing invoices'),
('invoicing.delete', 'Delete Invoices', 'Invoicing', 'Delete invoices'),
('invoicing.print', 'Print Invoices', 'Invoicing', 'Print invoices'),
('invoicing.change_status', 'Change Invoice Status', 'Invoicing', 'Change invoice status'),
('invoicing.customize', 'Customize Invoices', 'Invoicing', 'Customize invoice templates'),

-- Legacy invoice permissions (for backward compatibility)
('invoices.view', 'View Invoices', 'Invoicing', 'View invoice list'),
('invoices.create', 'Create Invoices', 'Invoicing', 'Create new invoices'),
('invoices.edit', 'Edit Invoices', 'Invoicing', 'Edit existing invoices'),
('invoices.delete', 'Delete Invoices', 'Invoicing', 'Delete invoices'),
('invoices.print', 'Print Invoices', 'Invoicing', 'Print invoices'),

-- Customers Module
('customers.view', 'View Customers', 'Customers', 'View customer list'),
('customers.create', 'Create Customers', 'Customers', 'Create new customers'),
('customers.edit', 'Edit Customers', 'Customers', 'Edit existing customers'),
('customers.delete', 'Delete Customers', 'Customers', 'Delete customers'),

-- Suppliers Module
('suppliers.view', 'View Suppliers', 'Suppliers', 'View supplier list'),
('suppliers.create', 'Create Suppliers', 'Suppliers', 'Create new suppliers'),
('suppliers.edit', 'Edit Suppliers', 'Suppliers', 'Edit existing suppliers'),
('suppliers.delete', 'Delete Suppliers', 'Suppliers', 'Delete suppliers'),

-- Trade-Ins Module
('tradeins.view', 'View Trade-Ins', 'Trade-Ins', 'View trade-in list'),
('tradeins.create', 'Create Trade-Ins', 'Trade-Ins', 'Create new trade-ins'),
('tradeins.edit', 'Edit Trade-Ins', 'Trade-Ins', 'Edit existing trade-ins'),
('tradeins.delete', 'Delete Trade-Ins', 'Trade-Ins', 'Delete trade-ins'),
('tradeins.process', 'Process Trade-Ins', 'Trade-Ins', 'Process trade-in transactions'),

-- Reports Module
('reports.view', 'View Reports', 'Reports', 'View all reports'),
('reports.sales', 'Sales Reports', 'Reports', 'View sales reports'),
('reports.inventory', 'Inventory Reports', 'Reports', 'View inventory reports'),
('reports.financial', 'Financial Reports', 'Reports', 'View financial reports'),

-- Administration - Branches
('branches.view', 'View Branches', 'Administration', 'View branch list'),
('branches.create', 'Create Branches', 'Administration', 'Create new branches'),
('branches.edit', 'Edit Branches', 'Administration', 'Edit existing branches'),
('branches.delete', 'Delete Branches', 'Administration', 'Delete branches'),
('branches.switch', 'Switch Branches', 'Administration', 'Switch between branches'),

-- Administration - Users
('users.view', 'View Users', 'Administration', 'View user list'),
('users.create', 'Create Users', 'Administration', 'Create new users'),
('users.edit', 'Edit Users', 'Administration', 'Edit existing users'),
('users.delete', 'Delete Users', 'Administration', 'Delete users'),

-- Administration - Roles
('roles.view', 'View Roles', 'Administration', 'View role list'),
('roles.create', 'Create Roles', 'Administration', 'Create new roles'),
('roles.edit', 'Edit Roles', 'Administration', 'Edit existing roles'),
('roles.delete', 'Delete Roles', 'Administration', 'Delete roles'),
('roles.permissions', 'Manage Permissions', 'Administration', 'Assign permissions to roles'),

-- Administration - Currencies
('currencies.view', 'View Currencies', 'Administration', 'View currency list'),
('currencies.create', 'Create Currencies', 'Administration', 'Create new currencies'),
('currencies.edit', 'Edit Currencies', 'Administration', 'Edit existing currencies'),
('currencies.delete', 'Delete Currencies', 'Administration', 'Delete currencies'),

-- Administration - Settings
('settings.view', 'View Settings', 'Administration', 'View system settings'),
('settings.edit', 'Edit Settings', 'Administration', 'Edit system settings'),

-- Fiscalization Module
('fiscalization.view_status', 'View Fiscalization Status', 'Fiscalization', 'View fiscalization status'),
('fiscalization.view_all', 'View All Fiscalizations', 'Fiscalization', 'View all fiscalization records'),
('fiscalization.verify_taxpayer', 'Verify Taxpayer', 'Fiscalization', 'Verify taxpayer information'),
('fiscalization.register_device', 'Register Device', 'Fiscalization', 'Register fiscal devices'),
('fiscalization.sync_config', 'Sync Fiscal Config', 'Fiscalization', 'Synchronize fiscal configuration');


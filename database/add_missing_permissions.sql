-- Add Missing Permissions Only
-- This script adds ONLY the 16 permissions that are missing from the database
-- Based on check_existing_permissions.php output
-- It uses INSERT IGNORE to safely skip existing permissions

-- Missing permissions identified on database check:
INSERT IGNORE INTO `permissions` (`permission_key`, `permission_name`, `module`, `description`) VALUES

-- POS Module - Missing legacy permissions (for backward compatibility)
('pos.create', 'Create Sales', 'POS', 'Create new sales'),
('pos.edit', 'Edit Sales', 'POS', 'Edit sales records'),
('pos.delete', 'Delete Sales', 'POS', 'Delete sales records'),
('pos.refund', 'Process Refunds', 'POS', 'Process refunds'),
('pos.cash', 'Cash Management', 'POS', 'Manage cash drawer and shifts'),
('pos.access', 'Access POS', 'POS', 'General POS access permission'),

-- Receipts - Missing
('receipts.print', 'Print Receipts', 'POS', 'Print receipts'),
('receipts.email', 'Email Receipts', 'POS', 'Email receipts to customers'),

-- Sales Module - Missing
('sales.refund', 'Refund Sales', 'Sales', 'Process sales refunds'),

-- Invoicing Module - Missing
('invoicing.print', 'Print Invoices', 'Invoicing', 'Print invoices'),

-- Legacy invoice permissions (for backward compatibility with old code)
('invoices.view', 'View Invoices', 'Invoicing', 'View invoice list'),
('invoices.create', 'Create Invoices', 'Invoicing', 'Create new invoices'),
('invoices.edit', 'Edit Invoices', 'Invoicing', 'Edit existing invoices'),
('invoices.delete', 'Delete Invoices', 'Invoicing', 'Delete invoices'),
('invoices.print', 'Print Invoices', 'Invoicing', 'Print invoices'),

-- Administration - Roles - Missing
('roles.permissions', 'Manage Permissions', 'Administration', 'Assign permissions to roles');


-- Add Permissions System Tables

-- Permissions table
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(100) NOT NULL UNIQUE,
  `permission_name` varchar(255) NOT NULL,
  `module` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_permission_key` (`permission_key`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Role Permissions junction table
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_permission` (`role_id`, `permission_id`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_permission_id` (`permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default permissions
INSERT IGNORE INTO `permissions` (`permission_key`, `permission_name`, `module`, `description`) VALUES
-- Dashboard
('dashboard.view', 'View Dashboard', 'Dashboard', 'View the main dashboard'),

-- Products
('products.view', 'View Products', 'Products', 'View product list'),
('products.create', 'Create Products', 'Products', 'Create new products'),
('products.edit', 'Edit Products', 'Products', 'Edit existing products'),
('products.delete', 'Delete Products', 'Products', 'Delete products'),
('products.categories', 'Manage Categories', 'Products', 'Manage product categories'),

-- Inventory
('inventory.view', 'View Inventory', 'Inventory', 'View stock levels'),
('inventory.create', 'Create Inventory', 'Inventory', 'Create GRN and transfers'),
('inventory.edit', 'Edit Inventory', 'Inventory', 'Edit inventory records'),
('inventory.delete', 'Delete Inventory', 'Inventory', 'Delete inventory records'),

-- POS
('pos.view', 'View POS', 'POS', 'Access POS system'),
('pos.create', 'Create Sales', 'POS', 'Create new sales'),
('pos.edit', 'Edit Sales', 'POS', 'Edit sales records'),
('pos.delete', 'Delete Sales', 'POS', 'Delete sales records'),
('pos.refund', 'Process Refunds', 'POS', 'Process refunds'),
('pos.cash', 'Cash Management', 'POS', 'Manage cash drawer and shifts'),

-- Sales
('sales.view', 'View Sales', 'Sales', 'View sales list'),
('sales.create', 'Create Sales', 'Sales', 'Create new sales'),
('sales.edit', 'Edit Sales', 'Sales', 'Edit sales records'),
('sales.delete', 'Delete Sales', 'Sales', 'Delete sales records'),

-- Invoicing
('invoices.view', 'View Invoices', 'Invoicing', 'View invoice list'),
('invoices.create', 'Create Invoices', 'Invoicing', 'Create new invoices'),
('invoices.edit', 'Edit Invoices', 'Invoicing', 'Edit existing invoices'),
('invoices.delete', 'Delete Invoices', 'Invoicing', 'Delete invoices'),
('invoices.print', 'Print Invoices', 'Invoicing', 'Print invoices'),

-- Customers
('customers.view', 'View Customers', 'Customers', 'View customer list'),
('customers.create', 'Create Customers', 'Customers', 'Create new customers'),
('customers.edit', 'Edit Customers', 'Customers', 'Edit existing customers'),
('customers.delete', 'Delete Customers', 'Customers', 'Delete customers'),

-- Suppliers
('suppliers.view', 'View Suppliers', 'Suppliers', 'View supplier list'),
('suppliers.create', 'Create Suppliers', 'Suppliers', 'Create new suppliers'),
('suppliers.edit', 'Edit Suppliers', 'Suppliers', 'Edit existing suppliers'),
('suppliers.delete', 'Delete Suppliers', 'Suppliers', 'Delete suppliers'),

-- Trade-Ins
('tradeins.view', 'View Trade-Ins', 'Trade-Ins', 'View trade-in list'),
('tradeins.create', 'Create Trade-Ins', 'Trade-Ins', 'Create new trade-ins'),
('tradeins.edit', 'Edit Trade-Ins', 'Trade-Ins', 'Edit existing trade-ins'),
('tradeins.delete', 'Delete Trade-Ins', 'Trade-Ins', 'Delete trade-ins'),

-- Reports
('reports.view', 'View Reports', 'Reports', 'View all reports'),
('reports.sales', 'Sales Reports', 'Reports', 'View sales reports'),
('reports.inventory', 'Inventory Reports', 'Reports', 'View inventory reports'),
('reports.financial', 'Financial Reports', 'Reports', 'View financial reports'),

-- Administration
('branches.view', 'View Branches', 'Administration', 'View branch list'),
('branches.create', 'Create Branches', 'Administration', 'Create new branches'),
('branches.edit', 'Edit Branches', 'Administration', 'Edit existing branches'),
('branches.delete', 'Delete Branches', 'Administration', 'Delete branches'),

('users.view', 'View Users', 'Administration', 'View user list'),
('users.create', 'Create Users', 'Administration', 'Create new users'),
('users.edit', 'Edit Users', 'Administration', 'Edit existing users'),
('users.delete', 'Delete Users', 'Administration', 'Delete users'),

('roles.view', 'View Roles', 'Administration', 'View role list'),
('roles.create', 'Create Roles', 'Administration', 'Create new roles'),
('roles.edit', 'Edit Roles', 'Administration', 'Edit existing roles'),
('roles.delete', 'Delete Roles', 'Administration', 'Delete roles'),
('roles.permissions', 'Manage Permissions', 'Administration', 'Assign permissions to roles'),

('settings.view', 'View Settings', 'Administration', 'View system settings'),
('settings.edit', 'Edit Settings', 'Administration', 'Edit system settings');



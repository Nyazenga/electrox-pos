-- Add Missing View Permissions and Report Category Permissions
-- This ensures every sidebar link has a corresponding view permission
-- Report categories auto-select all sub-permissions

INSERT IGNORE INTO `permissions` (`permission_key`, `permission_name`, `module`, `description`) VALUES

-- Report Category Permissions (these auto-select all reports in that category)
('reports.general', 'View All General Reports', 'Reports', 'View all general reports (Sales Summary, Receipts, Refunds, Sales by Products/Category/Discounts/Payment Types, Taxes, Shifts)'),
('reports.advanced_sales', 'View All Advanced Sales Reports', 'Reports', 'View all advanced sales reports (Product Wise Receipt, Sales by Trend, Deleted Receipts, Order Type Wise, Product Wise Tax/Charge, Manual Receipts, Sales by Orders, Product Wise Orders, Sales by Modifiers, Product Sales by Staff, Sales by Staff, Products Consumed by Staff, Ecommerce Sales)'),
('reports.suspicious', 'View All Suspicious Reports', 'Reports', 'View all suspicious reports (Product Wise Deleted Receipts, Refunds & Credit Notes, Deleted Products in Open Orders)');


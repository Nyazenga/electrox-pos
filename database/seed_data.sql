USE `electrox_primary`;

-- Seed Branches (at least 3)
INSERT INTO `branches` (`branch_code`, `branch_name`, `address`, `city`, `phone`, `email`, `status`, `opening_date`) VALUES
('HO', 'Head Office', '123 Electronics Street', 'Harare', '+263 242 700000', 'info@electrox.co.zw', 'Active', CURDATE()),
('BEL', 'Belgravia Branch', '45 Samora Machel Avenue', 'Harare', '+263 242 700001', 'belgravia@electrox.co.zw', 'Active', DATE_SUB(CURDATE(), INTERVAL 6 MONTH)),
('AVD', 'Avondale Branch', '78 Avondale Shopping Centre', 'Harare', '+263 242 700002', 'avondale@electrox.co.zw', 'Active', DATE_SUB(CURDATE(), INTERVAL 3 MONTH));

-- Seed Suppliers (at least 10)
INSERT INTO `suppliers` (`supplier_code`, `name`, `contact_person`, `phone`, `email`, `address`, `status`, `rating`) VALUES
('SUP001', 'Tech Distributors Zimbabwe', 'John Moyo', '+263 772 123456', 'info@techdist.co.zw', '123 Industrial Road, Harare', 'Active', 5),
('SUP002', 'Mobile World Suppliers', 'Sarah Chidza', '+263 773 234567', 'sales@mobileworld.co.zw', '456 Enterprise Street, Harare', 'Active', 4),
('SUP003', 'Gadget Importers Ltd', 'David Mupfumi', '+263 774 345678', 'contact@gadgetimports.co.zw', '789 Import Avenue, Harare', 'Active', 5),
('SUP004', 'Electronics Hub', 'Linda Nkomo', '+263 775 456789', 'info@electronicshub.co.zw', '321 Tech Park, Harare', 'Active', 4),
('SUP005', 'Smart Device Solutions', 'Peter Dube', '+263 776 567890', 'sales@smartdevices.co.zw', '654 Innovation Drive, Harare', 'Active', 5),
('SUP006', 'Global Tech Suppliers', 'Mary Sibanda', '+263 777 678901', 'contact@globaltech.co.zw', '987 Global Plaza, Harare', 'Active', 4),
('SUP007', 'Premium Electronics', 'James Ndlovu', '+263 778 789012', 'info@premiumelec.co.zw', '147 Premium Road, Harare', 'Active', 5),
('SUP008', 'Digital Solutions Co', 'Grace Moyo', '+263 779 890123', 'sales@digitalsolutions.co.zw', '258 Digital Street, Harare', 'Active', 4),
('SUP009', 'Tech Wholesale Ltd', 'Michael Chidza', '+263 771 901234', 'contact@techwholesale.co.zw', '369 Wholesale Avenue, Harare', 'Active', 5),
('SUP010', 'Modern Electronics', 'Patience Mupfumi', '+263 772 012345', 'info@modernelec.co.zw', '741 Modern Road, Harare', 'Active', 4);

-- Seed Customers (at least 15)
INSERT INTO `customers` (`customer_code`, `customer_type`, `first_name`, `last_name`, `phone`, `email`, `address`, `city`, `loyalty_points`, `status`, `customer_since`) VALUES
('CUST001', 'Individual', 'Tendai', 'Mukamuri', '+263 772 111111', 'tendai@email.com', '123 Main Street', 'Harare', 150, 'Active', DATE_SUB(CURDATE(), INTERVAL 12 MONTH)),
('CUST002', 'Individual', 'Blessing', 'Chidza', '+263 773 222222', 'blessing@email.com', '456 High Road', 'Harare', 200, 'Active', DATE_SUB(CURDATE(), INTERVAL 8 MONTH)),
('CUST003', 'Corporate', 'John', 'Doe', '+263 774 333333', 'john@company.com', '789 Business Park', 'Harare', 500, 'Active', DATE_SUB(CURDATE(), INTERVAL 6 MONTH)),
('CUST004', 'Individual', 'Sarah', 'Moyo', '+263 775 444444', 'sarah@email.com', '321 Residential Area', 'Harare', 75, 'Active', DATE_SUB(CURDATE(), INTERVAL 4 MONTH)),
('CUST005', 'Individual', 'David', 'Nkomo', '+263 776 555555', 'david@email.com', '654 Suburb Street', 'Harare', 300, 'Active', DATE_SUB(CURDATE(), INTERVAL 10 MONTH)),
('CUST006', 'Corporate', 'Tech', 'Solutions', '+263 777 666666', 'info@techsolutions.co.zw', '987 Corporate Avenue', 'Harare', 1000, 'Active', DATE_SUB(CURDATE(), INTERVAL 15 MONTH)),
('CUST007', 'Individual', 'Linda', 'Sibanda', '+263 778 777777', 'linda@email.com', '147 Home Street', 'Harare', 50, 'Active', DATE_SUB(CURDATE(), INTERVAL 2 MONTH)),
('CUST008', 'Individual', 'Peter', 'Dube', '+263 779 888888', 'peter@email.com', '258 Living Road', 'Harare', 125, 'Active', DATE_SUB(CURDATE(), INTERVAL 5 MONTH)),
('CUST009', 'Corporate', 'Digital', 'Enterprises', '+263 771 999999', 'contact@digitalent.co.zw', '369 Enterprise Park', 'Harare', 750, 'Active', DATE_SUB(CURDATE(), INTERVAL 9 MONTH)),
('CUST010', 'Individual', 'Mary', 'Ndlovu', '+263 772 000000', 'mary@email.com', '741 Personal Avenue', 'Harare', 100, 'Active', DATE_SUB(CURDATE(), INTERVAL 3 MONTH)),
('CUST011', 'Individual', 'James', 'Mupfumi', '+263 773 111111', 'james@email.com', '852 Family Road', 'Harare', 175, 'Active', DATE_SUB(CURDATE(), INTERVAL 7 MONTH)),
('CUST012', 'Individual', 'Grace', 'Chidza', '+263 774 222222', 'grace@email.com', '963 Community Street', 'Harare', 225, 'Active', DATE_SUB(CURDATE(), INTERVAL 11 MONTH)),
('CUST013', 'Corporate', 'Smart', 'Business', '+263 775 333333', 'info@smartbiz.co.zw', '159 Business Centre', 'Harare', 600, 'Active', DATE_SUB(CURDATE(), INTERVAL 13 MONTH)),
('CUST014', 'Individual', 'Michael', 'Moyo', '+263 776 444444', 'michael@email.com', '357 Residential Road', 'Harare', 80, 'Active', DATE_SUB(CURDATE(), INTERVAL 1 MONTH)),
('CUST015', 'Individual', 'Patience', 'Nkomo', '+263 777 555555', 'patience@email.com', '468 Home Avenue', 'Harare', 250, 'Active', DATE_SUB(CURDATE(), INTERVAL 14 MONTH));

-- Seed Products (at least 20)
INSERT INTO `products` (`product_code`, `category_id`, `brand`, `model`, `color`, `storage`, `cost_price`, `selling_price`, `reorder_level`, `branch_id`, `quantity_in_stock`, `status`, `created_at`) VALUES
('PROD00001', 1, 'Apple', 'iPhone 15 Pro Max', 'Natural Titanium', '256GB', 1200.00, 1500.00, 5, 1, 15, 'Active', DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('PROD00002', 1, 'Samsung', 'Galaxy S24 Ultra', 'Titanium Black', '512GB', 1100.00, 1400.00, 5, 1, 12, 'Active', DATE_SUB(CURDATE(), INTERVAL 25 DAY)),
('PROD00003', 1, 'Apple', 'iPhone 15', 'Blue', '128GB', 800.00, 1000.00, 10, 1, 20, 'Active', DATE_SUB(CURDATE(), INTERVAL 20 DAY)),
('PROD00004', 1, 'Samsung', 'Galaxy S24', 'Marble Gray', '256GB', 900.00, 1150.00, 8, 1, 18, 'Active', DATE_SUB(CURDATE(), INTERVAL 18 DAY)),
('PROD00005', 1, 'Huawei', 'P50 Pro', 'Golden Black', '256GB', 700.00, 900.00, 10, 2, 25, 'Active', DATE_SUB(CURDATE(), INTERVAL 15 DAY)),
('PROD00006', 2, 'Dell', 'XPS 15', 'Platinum Silver', '1TB', 1800.00, 2200.00, 3, 1, 8, 'Active', DATE_SUB(CURDATE(), INTERVAL 28 DAY)),
('PROD00007', 2, 'Apple', 'MacBook Pro M3', 'Space Gray', '512GB', 2000.00, 2500.00, 3, 1, 6, 'Active', DATE_SUB(CURDATE(), INTERVAL 22 DAY)),
('PROD00008', 2, 'HP', 'Spectre x360', 'Nightfall Black', '512GB', 1500.00, 1900.00, 5, 2, 10, 'Active', DATE_SUB(CURDATE(), INTERVAL 17 DAY)),
('PROD00009', 2, 'Lenovo', 'ThinkPad X1 Carbon', 'Black', '1TB', 1600.00, 2000.00, 4, 1, 9, 'Active', DATE_SUB(CURDATE(), INTERVAL 12 DAY)),
('PROD00010', 3, 'Apple', 'iPad Pro 12.9"', 'Space Gray', '256GB', 1000.00, 1300.00, 5, 1, 12, 'Active', DATE_SUB(CURDATE(), INTERVAL 10 DAY)),
('PROD00011', 3, 'Samsung', 'Galaxy Tab S9', 'Graphite', '256GB', 900.00, 1150.00, 6, 2, 14, 'Active', DATE_SUB(CURDATE(), INTERVAL 8 DAY)),
('PROD00012', 4, 'Apple', '20W USB-C Power Adapter', 'White', NULL, 15.00, 25.00, 20, 1, 50, 'Active', DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
('PROD00013', 4, 'Samsung', '25W Super Fast Charger', 'Black', NULL, 12.00, 20.00, 25, 1, 60, 'Active', DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
('PROD00014', 5, 'Apple', 'AirPods Pro', 'White', NULL, 200.00, 280.00, 10, 1, 30, 'Active', DATE_SUB(CURDATE(), INTERVAL 7 DAY)),
('PROD00015', 5, 'Sony', 'WH-1000XM5', 'Black', NULL, 300.00, 400.00, 8, 2, 20, 'Active', DATE_SUB(CURDATE(), INTERVAL 6 DAY)),
('PROD00016', 6, 'Apple', 'Watch Series 9', 'Midnight', '45mm', 350.00, 450.00, 10, 1, 25, 'Active', DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
('PROD00017', 6, 'Samsung', 'Galaxy Watch 6', 'Graphite', '44mm', 280.00, 380.00, 12, 2, 22, 'Active', DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
('PROD00018', 7, 'Sony', 'PlayStation 5', 'White', '825GB', 500.00, 650.00, 5, 1, 15, 'Active', DATE_SUB(CURDATE(), INTERVAL 9 DAY)),
('PROD00019', 8, 'TP-Link', 'Archer AX50', 'Black', NULL, 80.00, 120.00, 15, 1, 35, 'Active', DATE_SUB(CURDATE(), INTERVAL 11 DAY)),
('PROD00020', 9, 'Apple', 'MagSafe Charger', 'White', NULL, 30.00, 45.00, 20, 1, 40, 'Active', DATE_SUB(CURDATE(), INTERVAL 1 DAY));

-- Seed Invoices (at least 15)
INSERT INTO `invoices` (`invoice_number`, `invoice_type`, `customer_id`, `branch_id`, `user_id`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `amount_paid`, `balance_due`, `payment_methods`, `invoice_date`, `status`, `created_at`) VALUES
('INV-20241212-0001', 'Receipt', 1, 1, 1, 1500.00, 0.00, 0.00, 1500.00, 1500.00, 0.00, '["USD Cash"]', DATE_SUB(NOW(), INTERVAL 1 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 1 DAY)),
('INV-20241212-0002', 'TaxInvoice', 3, 1, 1, 2200.00, 0.00, 319.00, 2519.00, 2519.00, 0.00, '["Bank Transfer"]', DATE_SUB(NOW(), INTERVAL 2 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 2 DAY)),
('INV-20241212-0003', 'Receipt', 2, 2, 2, 1150.00, 50.00, 0.00, 1100.00, 1100.00, 0.00, '["EcoCash"]', DATE_SUB(NOW(), INTERVAL 3 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 3 DAY)),
('INV-20241212-0004', 'Receipt', 4, 1, 1, 900.00, 0.00, 0.00, 900.00, 900.00, 0.00, '["USD Cash"]', DATE_SUB(NOW(), INTERVAL 4 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 4 DAY)),
('INV-20241212-0005', 'TaxInvoice', 6, 1, 1, 5000.00, 200.00, 696.00, 5496.00, 3000.00, 2496.00, '["Bank Transfer"]', DATE_SUB(NOW(), INTERVAL 5 DAY), 'Partially Paid', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('INV-20241212-0006', 'Receipt', 5, 2, 2, 1300.00, 0.00, 0.00, 1300.00, 1300.00, 0.00, '["OneMoney"]', DATE_SUB(NOW(), INTERVAL 6 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 6 DAY)),
('INV-20241212-0007', 'Receipt', 7, 1, 1, 280.00, 0.00, 0.00, 280.00, 280.00, 0.00, '["USD Cash"]', DATE_SUB(NOW(), INTERVAL 7 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 7 DAY)),
('INV-20241212-0008', 'Receipt', 8, 1, 1, 450.00, 0.00, 0.00, 450.00, 450.00, 0.00, '["Card"]', DATE_SUB(NOW(), INTERVAL 8 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 8 DAY)),
('INV-20241212-0009', 'TaxInvoice', 9, 2, 2, 3800.00, 0.00, 551.00, 4351.00, 4351.00, 0.00, '["Bank Transfer"]', DATE_SUB(NOW(), INTERVAL 9 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 9 DAY)),
('INV-20241212-0010', 'Receipt', 10, 1, 1, 1000.00, 100.00, 0.00, 900.00, 900.00, 0.00, '["USD Cash"]', DATE_SUB(NOW(), INTERVAL 10 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 10 DAY)),
('INV-20241212-0011', 'Receipt', 11, 2, 2, 650.00, 0.00, 0.00, 650.00, 650.00, 0.00, '["EcoCash"]', DATE_SUB(NOW(), INTERVAL 11 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 11 DAY)),
('INV-20241212-0012', 'Receipt', 12, 1, 1, 120.00, 0.00, 0.00, 120.00, 120.00, 0.00, '["USD Cash"]', DATE_SUB(NOW(), INTERVAL 12 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 12 DAY)),
('INV-20241212-0013', 'TaxInvoice', 13, 1, 1, 2500.00, 0.00, 362.50, 2862.50, 1500.00, 1362.50, '["Bank Transfer"]', DATE_SUB(NOW(), INTERVAL 13 DAY), 'Partially Paid', DATE_SUB(NOW(), INTERVAL 13 DAY)),
('INV-20241212-0014', 'Receipt', 14, 2, 2, 400.00, 0.00, 0.00, 400.00, 400.00, 0.00, '["OneMoney"]', DATE_SUB(NOW(), INTERVAL 14 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 14 DAY)),
('INV-20241212-0015', 'Receipt', 15, 1, 1, 1900.00, 50.00, 0.00, 1850.00, 1850.00, 0.00, '["Card"]', DATE_SUB(NOW(), INTERVAL 15 DAY), 'Paid', DATE_SUB(NOW(), INTERVAL 15 DAY));

-- Seed Invoice Items (at least 30)
INSERT INTO `invoice_items` (`invoice_id`, `product_id`, `quantity`, `unit_price`, `line_total`, `cost_price`, `created_at`) VALUES
(1, 1, 1, 1500.00, 1500.00, 1200.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 6, 1, 2200.00, 2200.00, 1800.00, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 4, 1, 1150.00, 1150.00, 900.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, 5, 1, 900.00, 900.00, 700.00, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(5, 7, 2, 2500.00, 5000.00, 2000.00, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(6, 10, 1, 1300.00, 1300.00, 1000.00, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(7, 14, 1, 280.00, 280.00, 200.00, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(8, 16, 1, 450.00, 450.00, 350.00, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(9, 8, 2, 1900.00, 3800.00, 1500.00, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(10, 3, 1, 1000.00, 1000.00, 800.00, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(11, 18, 1, 650.00, 650.00, 500.00, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(12, 19, 1, 120.00, 120.00, 80.00, DATE_SUB(NOW(), INTERVAL 12 DAY)),
(13, 7, 1, 2500.00, 2500.00, 2000.00, DATE_SUB(NOW(), INTERVAL 13 DAY)),
(14, 15, 1, 400.00, 400.00, 300.00, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(15, 8, 1, 1900.00, 1900.00, 1500.00, DATE_SUB(NOW(), INTERVAL 15 DAY)),
(1, 12, 2, 25.00, 50.00, 15.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 20, 1, 45.00, 45.00, 30.00, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 13, 1, 20.00, 20.00, 12.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, 14, 1, 280.00, 280.00, 200.00, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(5, 16, 1, 450.00, 450.00, 350.00, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(6, 17, 1, 380.00, 380.00, 280.00, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(7, 12, 1, 25.00, 25.00, 15.00, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(8, 13, 1, 20.00, 20.00, 12.00, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(9, 19, 2, 120.00, 240.00, 80.00, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(10, 20, 1, 45.00, 45.00, 30.00, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(11, 12, 1, 25.00, 25.00, 15.00, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(12, 13, 1, 20.00, 20.00, 12.00, DATE_SUB(NOW(), INTERVAL 12 DAY)),
(13, 14, 1, 280.00, 280.00, 200.00, DATE_SUB(NOW(), INTERVAL 13 DAY)),
(14, 15, 1, 400.00, 400.00, 300.00, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(15, 16, 1, 450.00, 450.00, 350.00, DATE_SUB(NOW(), INTERVAL 15 DAY));

-- Seed Payments (at least 15)
INSERT INTO `payments` (`invoice_id`, `payment_method`, `amount`, `currency`, `payment_date`, `received_by`, `status`, `created_at`) VALUES
(1, 'USD Cash', 1500.00, 'USD', DATE_SUB(NOW(), INTERVAL 1 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 'Bank Transfer', 2519.00, 'USD', DATE_SUB(NOW(), INTERVAL 2 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 'EcoCash', 1100.00, 'USD', DATE_SUB(NOW(), INTERVAL 3 DAY), 2, 'Completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, 'USD Cash', 900.00, 'USD', DATE_SUB(NOW(), INTERVAL 4 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(5, 'Bank Transfer', 3000.00, 'USD', DATE_SUB(NOW(), INTERVAL 5 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(6, 'OneMoney', 1300.00, 'USD', DATE_SUB(NOW(), INTERVAL 6 DAY), 2, 'Completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(7, 'USD Cash', 280.00, 'USD', DATE_SUB(NOW(), INTERVAL 7 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(8, 'Card', 450.00, 'USD', DATE_SUB(NOW(), INTERVAL 8 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(9, 'Bank Transfer', 4351.00, 'USD', DATE_SUB(NOW(), INTERVAL 9 DAY), 2, 'Completed', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(10, 'USD Cash', 900.00, 'USD', DATE_SUB(NOW(), INTERVAL 10 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(11, 'EcoCash', 650.00, 'USD', DATE_SUB(NOW(), INTERVAL 11 DAY), 2, 'Completed', DATE_SUB(NOW(), INTERVAL 11 DAY)),
(12, 'USD Cash', 120.00, 'USD', DATE_SUB(NOW(), INTERVAL 12 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(13, 'Bank Transfer', 1500.00, 'USD', DATE_SUB(NOW(), INTERVAL 13 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 13 DAY)),
(14, 'OneMoney', 400.00, 'USD', DATE_SUB(NOW(), INTERVAL 14 DAY), 2, 'Completed', DATE_SUB(NOW(), INTERVAL 14 DAY)),
(15, 'Card', 1850.00, 'USD', DATE_SUB(NOW(), INTERVAL 15 DAY), 1, 'Completed', DATE_SUB(NOW(), INTERVAL 15 DAY));

-- Seed Stock Movements (at least 20)
INSERT INTO `stock_movements` (`product_id`, `branch_id`, `movement_type`, `quantity`, `previous_quantity`, `new_quantity`, `user_id`, `created_at`) VALUES
(1, 1, 'Purchase', 20, 0, 20, 1, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2, 1, 'Purchase', 15, 0, 15, 1, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(3, 1, 'Purchase', 25, 0, 25, 1, DATE_SUB(NOW(), INTERVAL 25 DAY)),
(4, 1, 'Purchase', 20, 0, 20, 1, DATE_SUB(NOW(), INTERVAL 22 DAY)),
(5, 2, 'Purchase', 30, 0, 30, 2, DATE_SUB(NOW(), INTERVAL 20 DAY)),
(6, 1, 'Purchase', 10, 0, 10, 1, DATE_SUB(NOW(), INTERVAL 18 DAY)),
(7, 1, 'Purchase', 8, 0, 8, 1, DATE_SUB(NOW(), INTERVAL 15 DAY)),
(8, 2, 'Purchase', 12, 0, 12, 2, DATE_SUB(NOW(), INTERVAL 12 DAY)),
(9, 1, 'Purchase', 10, 0, 10, 1, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(10, 1, 'Purchase', 15, 0, 15, 1, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(1, 1, 'Sale', -5, 20, 15, 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 1, 'Sale', -3, 15, 12, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 1, 'Sale', -5, 25, 20, 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4, 1, 'Sale', -2, 20, 18, 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(5, 2, 'Sale', -5, 30, 25, 2, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(6, 1, 'Sale', -2, 10, 8, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(7, 1, 'Sale', -2, 8, 6, 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(8, 2, 'Sale', -2, 12, 10, 2, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(9, 1, 'Sale', -1, 10, 9, 1, DATE_SUB(NOW(), INTERVAL 12 DAY)),
(10, 1, 'Sale', -3, 15, 12, 1, DATE_SUB(NOW(), INTERVAL 6 DAY));

-- Seed Trade-Ins (at least 10)
INSERT INTO `trade_ins` (`trade_in_number`, `customer_id`, `branch_id`, `assessed_by`, `device_category`, `device_brand`, `device_model`, `device_color`, `device_storage`, `device_condition`, `battery_health`, `manual_valuation`, `final_valuation`, `status`, `created_at`) VALUES
('TI-20241212-0001', 1, 1, 1, 'Smartphones', 'Apple', 'iPhone 12', 'Blue', '128GB', 'A', 85, 400.00, 400.00, 'Accepted', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('TI-20241212-0002', 2, 1, 1, 'Smartphones', 'Samsung', 'Galaxy S21', 'Phantom Black', '256GB', 'A+', 92, 350.00, 350.00, 'Accepted', DATE_SUB(NOW(), INTERVAL 4 DAY)),
('TI-20241212-0003', 3, 2, 2, 'Laptops', 'Dell', 'XPS 13', 'Silver', '512GB', 'B', 78, 600.00, 600.00, 'Assessed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
('TI-20241212-0004', 4, 1, 1, 'Smartphones', 'Apple', 'iPhone 11', 'Black', '64GB', 'B', 75, 250.00, 250.00, 'Accepted', DATE_SUB(NOW(), INTERVAL 2 DAY)),
('TI-20241212-0005', 5, 1, 1, 'Tablets', 'Apple', 'iPad Air', 'Space Gray', '256GB', 'A', 88, 450.00, 450.00, 'Processed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
('TI-20241212-0006', 6, 2, 2, 'Smartphones', 'Huawei', 'P40 Pro', 'Silver Frost', '256GB', 'A', 80, 300.00, 300.00, 'Accepted', DATE_SUB(NOW(), INTERVAL 7 DAY)),
('TI-20241212-0007', 7, 1, 1, 'Smartphones', 'Samsung', 'Galaxy Note 20', 'Mystic Bronze', '256GB', 'B', 70, 280.00, 280.00, 'Assessed', DATE_SUB(NOW(), INTERVAL 1 DAY)),
('TI-20241212-0008', 8, 1, 1, 'Laptops', 'HP', 'Pavilion 15', 'Black', '1TB', 'C', 65, 400.00, 400.00, 'Rejected', DATE_SUB(NOW(), INTERVAL 8 DAY)),
('TI-20241212-0009', 9, 2, 2, 'Smartphones', 'Apple', 'iPhone X', 'Space Gray', '256GB', 'B', 72, 200.00, 200.00, 'Accepted', DATE_SUB(NOW(), INTERVAL 9 DAY)),
('TI-20241212-0010', 10, 1, 1, 'Tablets', 'Samsung', 'Galaxy Tab S7', 'Mystic Black', '128GB', 'A', 85, 350.00, 350.00, 'Processed', DATE_SUB(NOW(), INTERVAL 10 DAY));

-- Seed Activity Logs (at least 15)
INSERT INTO `activity_logs` (`user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 'login', '{"ip": "192.168.1.100"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(1, 'product_created', '{"product_id": 1, "product_code": "PROD00001"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(2, 'login', '{"ip": "192.168.1.101"}', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(1, 'invoice_created', '{"invoice_id": 1, "invoice_number": "INV-20241212-0001"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 'sale_processed', '{"invoice_id": 3, "amount": 1100.00}', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'customer_created', '{"customer_id": 1, "customer_code": "CUST001"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 'stock_adjusted', '{"product_id": 1, "quantity": 20}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2, 'tradein_assessed', '{"trade_in_id": 1, "valuation": 400.00}', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 'report_generated', '{"report_type": "sales", "period": "monthly"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'user_created', '{"user_id": 2, "username": "cashier"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(2, 'logout', '{"ip": "192.168.1.101"}', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(1, 'settings_updated', '{"setting_key": "company_name"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(1, 'branch_created', '{"branch_id": 2, "branch_code": "BEL"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 6 MONTH)),
(2, 'product_viewed', '{"product_id": 5}', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(1, 'dashboard_accessed', '{}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 30 MINUTE));


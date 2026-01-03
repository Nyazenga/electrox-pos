-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 03, 2026 at 07:18 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `electrox_primary`
--

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product_code` varchar(50) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL,
  `storage` varchar(50) DEFAULT NULL,
  `sim_configuration` varchar(50) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `imei` varchar(50) DEFAULT NULL,
  `battery_health` int(11) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `specifications` text DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `selling_price` decimal(10,2) DEFAULT 0.00,
  `minimum_price` decimal(10,2) DEFAULT 0.00,
  `profit_margin` decimal(5,2) DEFAULT 0.00,
  `reorder_level` int(11) DEFAULT 10,
  `reorder_quantity` int(11) DEFAULT 10,
  `warranty_months` int(11) DEFAULT 0,
  `warranty_terms` text DEFAULT NULL,
  `condition` enum('New','Refurbished','Used') DEFAULT 'New',
  `status` enum('Active','Inactive','Discontinued') DEFAULT 'Active',
  `trade_in_eligible` tinyint(1) DEFAULT 0,
  `is_trade_in` tinyint(1) DEFAULT 0,
  `tags` varchar(255) DEFAULT NULL,
  `images` text DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `tax_id` int(11) DEFAULT NULL,
  `quantity_in_stock` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `weight` decimal(10,3) DEFAULT NULL,
  `unit_of_measure` varchar(20) DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product_code`, `product_name`, `category_id`, `brand`, `model`, `color`, `storage`, `sim_configuration`, `serial_number`, `imei`, `battery_health`, `sku`, `barcode`, `description`, `specifications`, `cost_price`, `selling_price`, `minimum_price`, `profit_margin`, `reorder_level`, `reorder_quantity`, `warranty_months`, `warranty_terms`, `condition`, `status`, `trade_in_eligible`, `is_trade_in`, `tags`, `images`, `branch_id`, `tax_id`, `quantity_in_stock`, `created_at`, `updated_at`, `created_by`, `source`, `updated_by`, `expiry_date`, `weight`, `unit_of_measure`, `manufacturer`, `batch_number`) VALUES
(1, 'PROD00001', NULL, 1, 'Apple', 'iPhone 15 Pro Max', 'Natural Titanium', '256GB', NULL, NULL, NULL, NULL, NULL, '8667540720717', NULL, NULL, 1200.00, 1500.00, 0.00, 0.00, 5, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 0, '2025-11-12 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'PROD00002', NULL, 1, 'Samsung', 'Galaxy S24 Ultra', 'Titanium Black', '512GB', NULL, NULL, NULL, NULL, NULL, '8239061786352', NULL, NULL, 1100.00, 1400.00, 0.00, 0.00, 5, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 8, '2025-11-17 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 'PROD00003', NULL, 1, 'Apple', 'iPhone 15', 'Blue', '128GB', NULL, NULL, NULL, NULL, NULL, '1492554521480', NULL, NULL, 800.00, 1000.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 0, '2025-11-22 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 'PROD00004', NULL, 1, 'Samsung', 'Galaxy S24', 'Marble Gray', '256GB', NULL, NULL, NULL, NULL, NULL, '2661815980413', NULL, NULL, 900.00, 1150.00, 0.00, 0.00, 8, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 13, '2025-11-24 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'PROD00005', NULL, 1, 'Huawei', 'P50 Pro', 'Golden Black', '256GB', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 700.00, 900.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 2, NULL, 25, '2025-11-27 00:00:00', '2025-12-12 18:08:06', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 'PROD00006', NULL, 2, 'Dell', 'XPS 15', 'Platinum Silver', '1TB', NULL, NULL, NULL, NULL, NULL, '9094329617170', NULL, NULL, 1800.00, 2200.00, 0.00, 0.00, 3, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 5, '2025-11-14 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 'PROD00007', NULL, 2, 'Apple', 'MacBook Pro M3', 'Space Gray', '512GB', NULL, NULL, NULL, NULL, NULL, '5813346210454', NULL, NULL, 2000.00, 2500.00, 0.00, 0.00, 3, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 0, '2025-11-20 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'PROD00008', NULL, 2, 'HP', 'Spectre x360', 'Nightfall Black', '512GB', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1500.00, 1900.00, 0.00, 0.00, 5, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 2, NULL, 10, '2025-11-25 00:00:00', '2025-12-12 18:08:06', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'PROD00009', NULL, 2, 'Lenovo', 'ThinkPad X1 Carbon', 'Black', '1TB', NULL, NULL, NULL, NULL, NULL, '4627956175734', NULL, NULL, 1600.00, 2000.00, 0.00, 0.00, 4, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 9, '2025-11-30 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'PROD00010', NULL, 3, 'Apple', 'iPad Pro 12.9\"', 'Space Gray', '256GB', NULL, NULL, NULL, NULL, NULL, '2894455581201', NULL, NULL, 1000.00, 1300.00, 0.00, 0.00, 5, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 0, '2025-12-02 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'PROD00011', NULL, 3, 'Samsung', 'Galaxy Tab S9', 'Graphite', '256GB', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 900.00, 1150.00, 0.00, 0.00, 6, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 2, NULL, 14, '2025-12-04 00:00:00', '2025-12-12 18:08:06', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'PROD00012', NULL, 4, 'Apple', '20W USB-C Power Adapter', '#34495E', NULL, NULL, NULL, NULL, NULL, NULL, '1691390208689', NULL, NULL, 15.00, 25.00, 0.00, 0.00, 20, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 367, '2025-12-07 00:00:00', '2025-12-31 12:07:33', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 'PROD00013', NULL, 4, 'Samsung', '25W Super Fast Charger', 'Black', NULL, NULL, NULL, NULL, NULL, NULL, '0642750963268', NULL, NULL, 12.00, 20.00, 0.00, 0.00, 25, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693e0c94b5f07_logo-icon.png\"]', 1, NULL, 57, '2025-12-09 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(14, 'PROD00014', NULL, 5, 'Apple', 'AirPods Pro', '#45B7D1', NULL, NULL, NULL, NULL, NULL, NULL, '2910697163897', NULL, NULL, 200.00, 280.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 0, '2025-12-05 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(15, 'PROD00015', NULL, 5, 'Sony', 'WH-1000XM5', 'Black', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 300.00, 400.00, 0.00, 0.00, 8, 10, 0, NULL, 'New', 'Inactive', 0, 0, NULL, NULL, 1, NULL, 20, '2025-12-06 00:00:00', '2025-12-14 12:04:14', NULL, 1, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL),
(16, 'PROD00016', NULL, 6, 'Apple', 'Watch Series 9', 'Midnight', '45mm', NULL, NULL, NULL, NULL, NULL, '4154737436819', NULL, NULL, 350.00, 450.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 0, '2025-12-08 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'PROD00017', NULL, 6, 'Samsung', 'Galaxy Watch 6', 'Graphite', '44mm', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 280.00, 380.00, 0.00, 0.00, 12, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693d2f6b12769_logo.png\"]', 2, NULL, 22, '2025-12-10 00:00:00', '2025-12-13 11:18:35', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'PROD00018', NULL, 7, 'Sony', 'PlayStation 5', '#F8B739', '825GB', NULL, NULL, NULL, NULL, NULL, '5854050620047', NULL, NULL, 500.00, 650.00, 0.00, 0.00, 5, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 14, '2025-12-03 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'PROD00019', NULL, 8, 'TP-Link', 'Archer AX50', 'Black', NULL, NULL, NULL, NULL, NULL, NULL, '4242803045345', NULL, NULL, 80.00, 120.00, 0.00, 0.00, 15, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 35, '2025-12-01 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'PROD00020', NULL, 9, 'Apple', 'MagSafe Charger', '#F8B739', NULL, NULL, NULL, NULL, NULL, NULL, '5247002812223', NULL, NULL, 30.00, 45.00, 0.00, 0.00, 20, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693c5abe5a31c_favicon.ico\"]', 1, NULL, 70, '2025-12-11 00:00:00', '2025-12-31 11:53:39', NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL),
(21, 'PROD-OISEL', NULL, 1, 'Tecno', 'Spark', 'Blue', '120', 'Dual SIM', '12345', '12345', NULL, NULL, '5427088768403', 'Tecno', 'Spark', 600.00, 630.00, 0.00, 0.00, 10, 10, 0, NULL, 'Used', 'Active', 0, 1, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693f8e42880d6_pngtree-a-packet-of-rice-png-image_19449986.png\"]', 1, NULL, 1101, '2025-12-14 13:58:06', '2026-01-02 11:51:21', 1, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL),
(22, 'PROD-XHWSA', NULL, 7, 'Sony', 'PS5', '#F7DC6F', '', '', '', '', NULL, NULL, '3949264896462', 'PS5', '1TB', 500.00, 550.00, 0.00, 0.00, 10, 10, 0, NULL, 'Used', 'Active', 0, 1, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693f8e3a68048_Great-Value-Long-Grain-Rice-90-Second-Pouch-8-8-oz_c157578f-27db-4d21-9d5e-8735034d3db4.06f6c06aed47f896b6093b840aa4939c.avif\"]', 1, NULL, 0, '2025-12-14 14:14:53', '2025-12-31 11:53:39', 1, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL),
(23, 'PROD-ESMVG', NULL, 7, 'Sony', 'PS5', '#E74C3C', '', '', '', '', NULL, NULL, '1497914650220', 'Play station', '2TB', 340.00, 400.00, 0.00, 0.00, 10, 10, 0, NULL, 'Used', 'Active', 0, 1, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693f8e3267833_SUGAR PACKET SPIKE 12X1Kg-500x500.jpeg\"]', 1, NULL, 0, '2025-12-14 14:19:05', '2025-12-31 11:53:39', 1, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL),
(24, 'PROD-80AFM', NULL, 5, 'Tecno', 'Spark', 'Grey', '', '', '', '', NULL, NULL, '8215041466391', '', '', 450.00, 500.00, 0.00, 0.00, 10, 10, 0, NULL, 'Used', 'Active', 0, 1, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693f3bff231d7_images.jfif\"]', 1, NULL, 789, '2025-12-14 15:10:54', '2025-12-31 11:53:39', 1, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL),
(26, 'PROD-MHEKG', 'Coca-Cola 2L Bottle', 10, NULL, NULL, '#F7DC6F', NULL, NULL, NULL, NULL, NULL, NULL, '4009333211318', 'Coca-Cola 2 Liter Bottle', NULL, 2.50, 3.50, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 38, '2025-12-15 17:12:12', '2025-12-31 11:53:39', 1, NULL, NULL, 2.000, 'bottle', NULL, 'manual'),
(27, 'PROD-LLFQC', 'Bread White Loaf 500g', 10, NULL, NULL, '#BB8FCE', NULL, NULL, NULL, NULL, NULL, NULL, '3491011409208', 'Fresh White Bread Loaf', NULL, 1.20, 2.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 27, '2025-12-15 17:12:12', '2025-12-31 11:53:39', 1, NULL, NULL, 0.500, 'piece', NULL, 'manual'),
(28, 'PROD-CDXUZ', 'Milk Full Cream 1L', 10, NULL, NULL, '#FFA07A', NULL, NULL, NULL, NULL, NULL, NULL, '9935658322345', 'Fresh Full Cream Milk', NULL, 2.80, 3.50, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 789, '2025-12-15 17:12:12', '2025-12-31 11:53:39', 1, NULL, NULL, 1.000, 'L', NULL, 'manual'),
(29, 'PROD-1TGCK', 'Rice Long Grain 5kg', 10, NULL, NULL, '#E74C3C', NULL, NULL, NULL, NULL, NULL, NULL, '3100921603674', 'Premium Long Grain Rice', NULL, 8.50, 12.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 90, '2025-12-15 17:12:12', '2025-12-31 12:04:07', 1, NULL, NULL, 5.000, 'bag', NULL, 'manual'),
(30, 'PROD-QEZS3', 'Sugar White 2kg', 10, NULL, NULL, '#4ECDC4', NULL, NULL, NULL, NULL, NULL, NULL, '7707329008528', 'White Granulated Sugar', NULL, 3.20, 4.50, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/6940fb4f4a5fc_SUGAR PACKET SPIKE 12X1Kg-500x500.jpeg\"]', 1, NULL, 35, '2025-12-15 17:12:12', '2025-12-31 11:53:39', 1, NULL, NULL, 2.000, 'bag', NULL, 'manual'),
(31, 'PROD-ZRSXP', 'Cooking Oil Sunflower 2L', 10, NULL, NULL, '#FFA07A', NULL, NULL, NULL, NULL, NULL, NULL, '5692227379608', 'Sunflower Cooking Oil', NULL, 4.50, 6.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 19, '2025-12-15 17:12:12', '2025-12-31 11:53:39', 1, NULL, NULL, 2.000, 'bottle', NULL, 'manual'),
(32, 'PROD-3CUZT', 'Eggs Large Grade A - Dozen', 10, NULL, NULL, '#E74C3C', NULL, NULL, NULL, NULL, NULL, NULL, '4203908123598', 'Fresh Large Grade A Eggs', NULL, 3.50, 5.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 1000, '2025-12-15 17:12:12', '2025-12-31 12:04:07', 1, NULL, NULL, 0.700, 'box', NULL, 'manual'),
(33, 'PROD-RVDHT', 'Tomatoes Fresh 1kg', 10, NULL, NULL, '#BB8FCE', NULL, NULL, NULL, NULL, NULL, NULL, '0115359210182', 'Fresh Red Tomatoes', NULL, 2.00, 3.50, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 10, '2025-12-15 17:12:12', '2025-12-31 11:53:39', 1, NULL, NULL, 1.000, 'kg', NULL, 'manual'),
(34, 'PROD-MVQBH', 'Sugar 2kg', 10, NULL, NULL, '#85C1E2', '', '', '', '', NULL, NULL, '3166722841905', 'SUGAR', 'SUGAR', 12.00, 15.00, 0.00, 0.00, 5, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 10, '2025-12-16 08:24:27', '2025-12-31 11:53:39', 1, NULL, NULL, 2.000, 'kg', '', ''),
(35, 'PROD-JD5M1', 'Cooking Salt 1kg', 10, NULL, NULL, '#1abc9c', '', NULL, NULL, NULL, NULL, NULL, '8691088577915', 'Fine Cooking Salt', NULL, 1.50, 2.50, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, 517, 380, '2025-12-17 10:59:40', '2025-12-31 11:53:39', 1, 1, NULL, 1.000, 'bag', NULL, 'manual'),
(36, 'PROD-DRBFC', 'Black Pepper 250g', 10, NULL, NULL, '#000000', '', NULL, NULL, NULL, NULL, NULL, '0557269504034', 'Ground Black Pepper', NULL, 3.00, 5.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, 514, 15, '2025-12-17 10:59:40', '2025-12-31 11:53:39', 1, 1, NULL, 0.250, 'pack', NULL, 'manual'),
(37, 'PROD-DFMEO', 'Tomato Sauce 500g', 10, NULL, NULL, '#FF6347', NULL, NULL, NULL, NULL, NULL, NULL, '2843558746598', 'Tomato Ketchup', NULL, 2.20, 3.50, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 30, '2025-12-17 10:59:40', '2025-12-31 11:53:39', 1, NULL, NULL, 0.500, 'bottle', NULL, 'manual'),
(38, 'PROD-YVOLE', 'Cooking Oil 5L', 10, NULL, NULL, '#FFD700', NULL, NULL, NULL, NULL, NULL, NULL, '6804822045539', 'Sunflower Cooking Oil', NULL, 10.00, 15.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 7, '2025-12-17 10:59:40', '2025-12-31 11:53:39', 1, NULL, NULL, 5.000, 'bottle', NULL, 'manual'),
(39, 'PROD-4FTJT', 'Baked Beans 410g', 10, NULL, NULL, '#8B4513', NULL, NULL, NULL, NULL, NULL, NULL, '7881699167372', 'Canned Baked Beans', NULL, 1.80, 3.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 899, '2025-12-17 10:59:40', '2025-12-31 11:53:39', 1, NULL, NULL, 0.410, 'can', NULL, 'manual'),
(40, 'PROD-14VBV', 'Cornflakes 500g', 10, NULL, NULL, '#FFD700', NULL, NULL, NULL, NULL, NULL, NULL, '2939173901099', 'Breakfast Cereal', NULL, 3.50, 5.50, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 90, '2025-12-17 10:59:40', '2025-12-31 12:04:07', 1, NULL, NULL, 0.500, 'box', NULL, 'manual'),
(41, 'PROD-P49VU', 'Tea Bags 100s', 10, NULL, NULL, '#8B4513', NULL, NULL, NULL, NULL, NULL, NULL, '7738598740154', 'Black Tea Bags', NULL, 4.00, 6.50, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 35, '2025-12-17 10:59:40', '2025-12-31 11:53:39', 1, NULL, NULL, 0.200, 'box', NULL, 'manual'),
(42, 'PROD-WQSO5', 'Instant Coffee 200g', 10, NULL, NULL, '#8B4513', NULL, NULL, NULL, NULL, NULL, NULL, '9848993356381', 'Instant Coffee Powder', NULL, 5.50, 9.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, NULL, 16, '2025-12-17 10:59:40', '2025-12-31 11:53:39', 1, NULL, NULL, 0.200, 'jar', NULL, 'manual'),
(43, 'PROD-M2KNC', 'Sugar 2kg White', 10, NULL, NULL, '#cc0505', '', '', '', '', NULL, NULL, '3639607093374', 'none', 'none', 12.00, 15.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/694273fa6c3f3_SUGAR PACKET SPIKE 12X1Kg-500x500.jpeg\"]', 1, NULL, 100, '2025-12-17 11:12:26', '2026-01-02 11:51:21', 1, NULL, '2027-12-12', 2.000, 'kg', 'hullets', '1'),
(44, 'PROD-E7UMO', 'Rice 2kg White', 10, NULL, NULL, '#ffffff', '', '', '', '', NULL, NULL, '3844811709076', '', '', 12.00, 15.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/69427a167b19d_Great-Value-Long-Grain-Rice-90-Second-Pouch-8-8-oz_c157578f-27db-4d21-9d5e-8735034d3db4.06f6c06aed47f896b6093b840aa4939c.avif\"]', 1, NULL, 0, '2025-12-17 11:38:30', '2026-01-02 11:51:21', 1, NULL, '2027-12-12', 2.000, '', 'hullets', '1'),
(45, 'PROD-FMXNH', 'Chibuku', 10, NULL, NULL, '#261cba', '', '', '', '', NULL, NULL, '1713656205369', 'chibuku', 'chibuku', 1.75, 2.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, 514, 290, '2025-12-23 20:12:19', '2026-01-02 11:51:21', 1, NULL, '2027-01-01', 2.000, 'L', 'delta', '001'),
(46, 'PROD-UTPDZ', 'Nyati', 10, NULL, NULL, '#de0d0d', '', '', '', '', NULL, NULL, '5634666206770', 'nyati', 'nyati', 1.75, 2.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, 517, 285, '2025-12-23 20:16:05', '2026-01-02 11:51:21', 1, NULL, '2026-01-01', 2.000, 'L', 'inscor', '001'),
(47, 'PROD-GKBID', 'Lacto', 10, NULL, NULL, '#bcc819', '', '', '', '', NULL, NULL, '2503252737918', '', '', 1.75, 2.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, 2, 0, '2025-12-23 21:10:08', '2026-01-02 11:51:21', 1, NULL, '2026-01-01', 2.000, '', 'inscor', '001'),
(48, 'PROD-NMAWH', 'Mauyu', 10, NULL, NULL, '#bcc819', '', '', '', '', NULL, NULL, '6818109762019', '', '', 1.75, 2.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, 2, 896, '2025-12-23 21:12:15', '2026-01-02 11:51:21', 1, NULL, '2026-01-01', 2.000, '', 'inscor', '001'),
(56, 'PROD-J9UGQ', NULL, 1, 'Samsung', 'F13', '#e30d0d', '128', '', NULL, NULL, 100, NULL, '98409090325342', 'GGH', 'GHGH', 100.00, 120.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, NULL, 1, 1, 3, '2026-01-02 13:23:28', '2026-01-02 13:23:28', 1, NULL, NULL, NULL, '', '', 'manual'),
(59, 'PROD-RKVIS', NULL, 2, 'DELL', 'LATITUDE 8928', '#0a0a0a', '256', '', NULL, NULL, NULL, NULL, '9840909032544333', '', '', 120.00, 220.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Inactive', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/6957eebad99e4_614z+tE-O6L.jpg\"]', 1, 517, 1, '2026-01-02 18:13:46', '2026-01-02 19:05:05', 1, 1, NULL, NULL, '', '', 'manual'),
(60, 'PROD-DHTKE', NULL, 2, 'HP', 'PROBOOK 90', '#d6d6d6', '256', '', NULL, NULL, NULL, NULL, '9840909032544333', '', '', 600.00, 800.00, 0.00, 0.00, 1, 10, 0, NULL, 'New', 'Inactive', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/6957fb5673a10_Copy-of-Copy-of-Untitled-Design51.jpg\"]', 1, 514, 1, '2026-01-02 19:07:34', '2026-01-02 19:34:57', 1, NULL, NULL, NULL, '', '', 'manual'),
(61, 'PROD-DHTKE-TRF-3', NULL, 2, 'HP', 'PROBOOK 90', '#d6d6d6', '256', '', NULL, NULL, NULL, NULL, '9840909032544333', '', '', 600.00, 800.00, 0.00, 0.00, 1, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/6957fb5673a10_Copy-of-Copy-of-Untitled-Design51.jpg\"]', 3, 514, 1, '2026-01-02 19:16:05', '2026-01-02 19:16:05', 1, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL),
(62, 'PROD-TXL0L', NULL, 2, 'HP', 'PROBOOK 90', '#d6d6d6', '256', '', NULL, NULL, NULL, NULL, '9840909032544333', '', '', 600.00, 800.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/695801df6a197_Copy-of-Copy-of-Untitled-Design51.jpg\"]', 1, 517, 1, '2026-01-02 19:35:27', '2026-01-02 19:36:35', 1, 1, NULL, NULL, '', '', 'manual'),
(63, 'PROD-TXL0L-TRF-4', NULL, 2, 'HP', 'PROBOOK 90', '#d6d6d6', '256', '', NULL, NULL, NULL, NULL, '9840909032544333', '', '', 600.00, 800.00, 0.00, 0.00, 10, 10, 0, NULL, 'New', 'Active', 0, 0, NULL, '[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/695801df6a197_Copy-of-Copy-of-Untitled-Design51.jpg\"]', 3, 517, 1, '2026-01-02 19:36:35', '2026-01-02 19:36:35', 1, NULL, NULL, NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_code` (`product_code`),
  ADD KEY `idx_product_code` (`product_code`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_branch_id` (`branch_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_trade_in` (`is_trade_in`),
  ADD KEY `idx_tax_id` (`tax_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

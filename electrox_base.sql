-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: electrox_base
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `electrox_base`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `electrox_base` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `electrox_base`;

--
-- Table structure for table `admin_users`
--

DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_users`
--

LOCK TABLES `admin_users` WRITE;
/*!40000 ALTER TABLE `admin_users` DISABLE KEYS */;
INSERT INTO `admin_users` VALUES (1,'admin','admin@electrox.co.zw','$2y$10$3xkndv4Den7JbXkyUOfm2urr7JNex7EWTd7a0sXn9W0CgJIa8L116','System','Administrator','active','2025-12-12 16:22:30');
/*!40000 ALTER TABLE `admin_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `currencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(3) NOT NULL,
  `name` varchar(100) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `symbol_position` enum('before','after') DEFAULT 'before',
  `decimal_places` int(11) DEFAULT 2,
  `is_base` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `exchange_rate` decimal(10,6) DEFAULT 1.000000,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_code` (`code`),
  KEY `idx_is_base` (`is_base`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
INSERT INTO `currencies` VALUES (1,'USD','US Dollar','$','before',2,1,1,1.000000,'2025-12-14 22:22:32','2025-12-14 22:22:32',NULL,NULL),(2,'ZAR','South African Rand','R','before',2,0,1,18.500000,'2025-12-14 22:22:32','2025-12-14 22:22:32',NULL,NULL),(3,'EUR','Euro','€','before',2,0,1,0.920000,'2025-12-14 22:22:32','2025-12-14 22:22:32',NULL,NULL),(4,'GBP','British Pound','£','before',2,0,1,0.790000,'2025-12-14 22:22:32','2025-12-14 22:22:32',NULL,NULL),(5,'ZWL','Zimbabwean Dollar','ZWL','before',2,0,1,35.000000,'2025-12-14 22:36:30','2025-12-14 22:36:30',NULL,NULL);
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `drawer_transactions`
--

DROP TABLE IF EXISTS `drawer_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `drawer_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_id` int(11) NOT NULL,
  `transaction_type` enum('pay_in','pay_out') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,6) DEFAULT 1.000000,
  `original_amount` decimal(10,2) DEFAULT NULL,
  `base_amount` decimal(10,2) DEFAULT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `drawer_transactions`
--

LOCK TABLES `drawer_transactions` WRITE;
/*!40000 ALTER TABLE `drawer_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `drawer_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(100) NOT NULL,
  `permission_name` varchar(255) NOT NULL,
  `module` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_key` (`permission_key`),
  KEY `idx_permission_key` (`permission_key`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'dashboard.view','View Dashboard','Dashboard','View the main dashboard','2025-12-14 23:12:38'),(2,'products.view','View Products','Products','View product list','2025-12-14 23:12:38'),(3,'products.create','Create Products','Products','Create new products','2025-12-14 23:12:38'),(4,'products.edit','Edit Products','Products','Edit existing products','2025-12-14 23:12:38'),(5,'products.delete','Delete Products','Products','Delete products','2025-12-14 23:12:38'),(6,'products.categories','Manage Categories','Products','Manage product categories','2025-12-14 23:12:38'),(7,'inventory.view','View Inventory','Inventory','View stock levels','2025-12-14 23:12:38'),(8,'inventory.create','Create Inventory','Inventory','Create GRN and transfers','2025-12-14 23:12:38'),(9,'inventory.edit','Edit Inventory','Inventory','Edit inventory records','2025-12-14 23:12:38'),(10,'inventory.delete','Delete Inventory','Inventory','Delete inventory records','2025-12-14 23:12:38'),(11,'pos.view','View POS','POS','Access POS system','2025-12-14 23:12:38'),(12,'pos.create','Create Sales','POS','Create new sales','2025-12-14 23:12:38'),(13,'pos.edit','Edit Sales','POS','Edit sales records','2025-12-14 23:12:38'),(14,'pos.delete','Delete Sales','POS','Delete sales records','2025-12-14 23:12:38'),(15,'pos.refund','Process Refunds','POS','Process refunds','2025-12-14 23:12:38'),(16,'pos.cash','Cash Management','POS','Manage cash drawer and shifts','2025-12-14 23:12:38'),(17,'sales.view','View Sales','Sales','View sales list','2025-12-14 23:12:38'),(18,'sales.create','Create Sales','Sales','Create new sales','2025-12-14 23:12:38'),(19,'sales.edit','Edit Sales','Sales','Edit sales records','2025-12-14 23:12:38'),(20,'sales.delete','Delete Sales','Sales','Delete sales records','2025-12-14 23:12:38'),(21,'invoices.view','View Invoices','Invoicing','View invoice list','2025-12-14 23:12:38'),(22,'invoices.create','Create Invoices','Invoicing','Create new invoices','2025-12-14 23:12:38'),(23,'invoices.edit','Edit Invoices','Invoicing','Edit existing invoices','2025-12-14 23:12:38'),(24,'invoices.delete','Delete Invoices','Invoicing','Delete invoices','2025-12-14 23:12:38'),(25,'invoices.print','Print Invoices','Invoicing','Print invoices','2025-12-14 23:12:38'),(26,'customers.view','View Customers','Customers','View customer list','2025-12-14 23:12:38'),(27,'customers.create','Create Customers','Customers','Create new customers','2025-12-14 23:12:38'),(28,'customers.edit','Edit Customers','Customers','Edit existing customers','2025-12-14 23:12:38'),(29,'customers.delete','Delete Customers','Customers','Delete customers','2025-12-14 23:12:38'),(30,'suppliers.view','View Suppliers','Suppliers','View supplier list','2025-12-14 23:12:38'),(31,'suppliers.create','Create Suppliers','Suppliers','Create new suppliers','2025-12-14 23:12:38'),(32,'suppliers.edit','Edit Suppliers','Suppliers','Edit existing suppliers','2025-12-14 23:12:38'),(33,'suppliers.delete','Delete Suppliers','Suppliers','Delete suppliers','2025-12-14 23:12:38'),(34,'tradeins.view','View Trade-Ins','Trade-Ins','View trade-in list','2025-12-14 23:12:38'),(35,'tradeins.create','Create Trade-Ins','Trade-Ins','Create new trade-ins','2025-12-14 23:12:38'),(36,'tradeins.edit','Edit Trade-Ins','Trade-Ins','Edit existing trade-ins','2025-12-14 23:12:38'),(37,'tradeins.delete','Delete Trade-Ins','Trade-Ins','Delete trade-ins','2025-12-14 23:12:38'),(38,'reports.view','View Reports','Reports','View all reports','2025-12-14 23:12:38'),(39,'reports.sales','Sales Reports','Reports','View sales reports','2025-12-14 23:12:38'),(40,'reports.inventory','Inventory Reports','Reports','View inventory reports','2025-12-14 23:12:38'),(41,'reports.financial','Financial Reports','Reports','View financial reports','2025-12-14 23:12:38'),(42,'branches.view','View Branches','Administration','View branch list','2025-12-14 23:12:38'),(43,'branches.create','Create Branches','Administration','Create new branches','2025-12-14 23:12:38'),(44,'branches.edit','Edit Branches','Administration','Edit existing branches','2025-12-14 23:12:38'),(45,'branches.delete','Delete Branches','Administration','Delete branches','2025-12-14 23:12:38'),(46,'users.view','View Users','Administration','View user list','2025-12-14 23:12:38'),(47,'users.create','Create Users','Administration','Create new users','2025-12-14 23:12:38'),(48,'users.edit','Edit Users','Administration','Edit existing users','2025-12-14 23:12:38'),(49,'users.delete','Delete Users','Administration','Delete users','2025-12-14 23:12:38'),(50,'roles.view','View Roles','Administration','View role list','2025-12-14 23:12:38'),(51,'roles.create','Create Roles','Administration','Create new roles','2025-12-14 23:12:38'),(52,'roles.edit','Edit Roles','Administration','Edit existing roles','2025-12-14 23:12:38'),(53,'roles.delete','Delete Roles','Administration','Delete roles','2025-12-14 23:12:38'),(54,'roles.permissions','Manage Permissions','Administration','Assign permissions to roles','2025-12-14 23:12:38'),(55,'settings.view','View Settings','Administration','View system settings','2025-12-14 23:12:38'),(56,'settings.edit','Edit Settings','Administration','Edit system settings','2025-12-14 23:12:38');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_settings`
--

DROP TABLE IF EXISTS `pos_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(50) DEFAULT 'string',
  `category` varchar(50) DEFAULT 'general',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_setting_key` (`setting_key`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_settings`
--

LOCK TABLES `pos_settings` WRITE;
/*!40000 ALTER TABLE `pos_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_favorites`
--

DROP TABLE IF EXISTS `product_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_product` (`user_id`,`product_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_favorites`
--

LOCK TABLES `product_favorites` WRITE;
/*!40000 ALTER TABLE `product_favorites` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_favorites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=145 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,1,42,'2025-12-14 23:12:38'),(2,1,43,'2025-12-14 23:12:38'),(3,1,44,'2025-12-14 23:12:38'),(4,1,45,'2025-12-14 23:12:38'),(5,1,46,'2025-12-14 23:12:38'),(6,1,47,'2025-12-14 23:12:38'),(7,1,48,'2025-12-14 23:12:38'),(8,1,49,'2025-12-14 23:12:38'),(9,1,50,'2025-12-14 23:12:38'),(10,1,51,'2025-12-14 23:12:38'),(11,1,52,'2025-12-14 23:12:38'),(12,1,53,'2025-12-14 23:12:38'),(13,1,54,'2025-12-14 23:12:38'),(14,1,55,'2025-12-14 23:12:38'),(15,1,56,'2025-12-14 23:12:38'),(16,1,26,'2025-12-14 23:12:38'),(17,1,27,'2025-12-14 23:12:38'),(18,1,28,'2025-12-14 23:12:38'),(19,1,29,'2025-12-14 23:12:38'),(20,1,1,'2025-12-14 23:12:38'),(21,1,7,'2025-12-14 23:12:38'),(22,1,8,'2025-12-14 23:12:38'),(23,1,9,'2025-12-14 23:12:38'),(24,1,10,'2025-12-14 23:12:38'),(25,1,21,'2025-12-14 23:12:38'),(26,1,22,'2025-12-14 23:12:38'),(27,1,23,'2025-12-14 23:12:38'),(28,1,24,'2025-12-14 23:12:38'),(29,1,25,'2025-12-14 23:12:38'),(30,1,11,'2025-12-14 23:12:38'),(31,1,12,'2025-12-14 23:12:38'),(32,1,13,'2025-12-14 23:12:38'),(33,1,14,'2025-12-14 23:12:38'),(34,1,15,'2025-12-14 23:12:38'),(35,1,16,'2025-12-14 23:12:38'),(36,1,2,'2025-12-14 23:12:38'),(37,1,3,'2025-12-14 23:12:38'),(38,1,4,'2025-12-14 23:12:38'),(39,1,5,'2025-12-14 23:12:38'),(40,1,6,'2025-12-14 23:12:38'),(41,1,38,'2025-12-14 23:12:38'),(42,1,39,'2025-12-14 23:12:38'),(43,1,40,'2025-12-14 23:12:38'),(44,1,41,'2025-12-14 23:12:38'),(45,1,17,'2025-12-14 23:12:38'),(46,1,18,'2025-12-14 23:12:38'),(47,1,19,'2025-12-14 23:12:38'),(48,1,20,'2025-12-14 23:12:38'),(49,1,30,'2025-12-14 23:12:38'),(50,1,31,'2025-12-14 23:12:38'),(51,1,32,'2025-12-14 23:12:38'),(52,1,33,'2025-12-14 23:12:38'),(53,1,34,'2025-12-14 23:12:38'),(54,1,35,'2025-12-14 23:12:38'),(55,1,36,'2025-12-14 23:12:38'),(56,1,37,'2025-12-14 23:12:38'),(57,2,43,'2025-12-14 23:12:38'),(58,2,45,'2025-12-14 23:12:38'),(59,2,44,'2025-12-14 23:12:38'),(60,2,42,'2025-12-14 23:12:38'),(61,2,27,'2025-12-14 23:12:38'),(62,2,29,'2025-12-14 23:12:38'),(63,2,28,'2025-12-14 23:12:38'),(64,2,26,'2025-12-14 23:12:38'),(65,2,1,'2025-12-14 23:12:38'),(66,2,8,'2025-12-14 23:12:38'),(67,2,10,'2025-12-14 23:12:38'),(68,2,9,'2025-12-14 23:12:38'),(69,2,7,'2025-12-14 23:12:38'),(70,2,22,'2025-12-14 23:12:39'),(71,2,24,'2025-12-14 23:12:39'),(72,2,23,'2025-12-14 23:12:39'),(73,2,25,'2025-12-14 23:12:39'),(74,2,21,'2025-12-14 23:12:39'),(75,2,16,'2025-12-14 23:12:39'),(76,2,12,'2025-12-14 23:12:39'),(77,2,14,'2025-12-14 23:12:39'),(78,2,13,'2025-12-14 23:12:39'),(79,2,15,'2025-12-14 23:12:39'),(80,2,11,'2025-12-14 23:12:39'),(81,2,6,'2025-12-14 23:12:39'),(82,2,3,'2025-12-14 23:12:39'),(83,2,5,'2025-12-14 23:12:39'),(84,2,4,'2025-12-14 23:12:39'),(85,2,2,'2025-12-14 23:12:39'),(86,2,41,'2025-12-14 23:12:39'),(87,2,40,'2025-12-14 23:12:39'),(88,2,39,'2025-12-14 23:12:39'),(89,2,38,'2025-12-14 23:12:39'),(90,2,18,'2025-12-14 23:12:39'),(91,2,20,'2025-12-14 23:12:39'),(92,2,19,'2025-12-14 23:12:39'),(93,2,17,'2025-12-14 23:12:39'),(94,2,55,'2025-12-14 23:12:39'),(95,2,31,'2025-12-14 23:12:39'),(96,2,33,'2025-12-14 23:12:39'),(97,2,32,'2025-12-14 23:12:39'),(98,2,30,'2025-12-14 23:12:39'),(99,2,35,'2025-12-14 23:12:39'),(100,2,37,'2025-12-14 23:12:39'),(101,2,36,'2025-12-14 23:12:39'),(102,2,34,'2025-12-14 23:12:39'),(103,2,47,'2025-12-14 23:12:39'),(104,2,48,'2025-12-14 23:12:39'),(105,2,46,'2025-12-14 23:12:39'),(106,3,27,'2025-12-14 23:12:39'),(107,3,29,'2025-12-14 23:12:39'),(108,3,28,'2025-12-14 23:12:39'),(109,3,26,'2025-12-14 23:12:39'),(110,3,1,'2025-12-14 23:12:39'),(111,3,16,'2025-12-14 23:12:39'),(112,3,12,'2025-12-14 23:12:39'),(113,3,14,'2025-12-14 23:12:39'),(114,3,13,'2025-12-14 23:12:39'),(115,3,15,'2025-12-14 23:12:39'),(116,3,11,'2025-12-14 23:12:39'),(117,3,18,'2025-12-14 23:12:39'),(118,3,20,'2025-12-14 23:12:39'),(119,3,19,'2025-12-14 23:12:39'),(120,3,17,'2025-12-14 23:12:39'),(121,4,27,'2025-12-14 23:12:39'),(122,4,29,'2025-12-14 23:12:39'),(123,4,28,'2025-12-14 23:12:39'),(124,4,26,'2025-12-14 23:12:39'),(125,4,1,'2025-12-14 23:12:39'),(126,4,22,'2025-12-14 23:12:39'),(127,4,24,'2025-12-14 23:12:39'),(128,4,23,'2025-12-14 23:12:39'),(129,4,25,'2025-12-14 23:12:39'),(130,4,21,'2025-12-14 23:12:39'),(131,4,18,'2025-12-14 23:12:39'),(132,4,20,'2025-12-14 23:12:39'),(133,4,19,'2025-12-14 23:12:39'),(134,4,17,'2025-12-14 23:12:39'),(135,5,1,'2025-12-14 23:12:39'),(136,5,8,'2025-12-14 23:12:39'),(137,5,10,'2025-12-14 23:12:39'),(138,5,9,'2025-12-14 23:12:39'),(139,5,7,'2025-12-14 23:12:39'),(140,5,6,'2025-12-14 23:12:39'),(141,5,3,'2025-12-14 23:12:39'),(142,5,5,'2025-12-14 23:12:39'),(143,5,4,'2025-12-14 23:12:39'),(144,5,2,'2025-12-14 23:12:39');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system_role` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`name`),
  KEY `idx_is_system_role` (`is_system_role`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Administrator','Full system administrator with all permissions',1,'2025-12-14 23:12:38','2025-12-14 23:12:38',NULL,NULL),(2,'Manager','Manager with most permissions except system administration',1,'2025-12-14 23:12:38','2025-12-14 23:12:38',NULL,NULL),(3,'Cashier','Cashier with POS and sales permissions',1,'2025-12-14 23:12:38','2025-12-14 23:12:38',NULL,NULL),(4,'Sales Representative','Sales rep with sales and customer permissions',1,'2025-12-14 23:12:38','2025-12-14 23:12:38',NULL,NULL),(5,'Inventory Manager','Inventory manager with product and inventory permissions',1,'2025-12-14 23:12:38','2025-12-14 23:12:38',NULL,NULL);
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sale_id` (`sale_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_items`
--

LOCK TABLES `sale_items` WRITE;
/*!40000 ALTER TABLE `sale_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_payments`
--

DROP TABLE IF EXISTS `sale_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,6) DEFAULT 1.000000,
  `original_amount` decimal(10,2) DEFAULT NULL,
  `base_amount` decimal(10,2) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sale_id` (`sale_id`),
  KEY `idx_currency_id` (`currency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_payments`
--

LOCK TABLES `sale_payments` WRITE;
/*!40000 ALTER TABLE `sale_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(50) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `sale_date` datetime NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_type` enum('value','percentage') DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `base_currency_id` int(11) DEFAULT NULL,
  `base_exchange_rate` decimal(10,6) DEFAULT NULL,
  `original_currency_id` int(11) DEFAULT NULL,
  `original_total_amount` decimal(10,2) DEFAULT NULL,
  `payment_status` enum('paid','pending','refunded') DEFAULT 'paid',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  KEY `idx_receipt_number` (`receipt_number`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_sale_date` (`sale_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shifts`
--

DROP TABLE IF EXISTS `shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_number` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `opened_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `opened_by` int(11) NOT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `starting_cash` decimal(10,2) DEFAULT 0.00,
  `expected_cash` decimal(10,2) DEFAULT 0.00,
  `actual_cash` decimal(10,2) DEFAULT NULL,
  `difference` decimal(10,2) DEFAULT 0.00,
  `base_currency_id` int(11) DEFAULT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shifts`
--

LOCK TABLES `shifts` WRITE;
/*!40000 ALTER TABLE `shifts` DISABLE KEYS */;
/*!40000 ALTER TABLE `shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','integer','boolean','json') DEFAULT 'string',
  `category` varchar(50) DEFAULT 'General',
  `description` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenant_registrations`
--

DROP TABLE IF EXISTS `tenant_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenant_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `tenant_name` varchar(50) NOT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `contact_email` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Zimbabwe',
  `currency` varchar(10) DEFAULT 'USD',
  `additional_info` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_name` (`tenant_name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenant_registrations`
--

LOCK TABLES `tenant_registrations` WRITE;
/*!40000 ALTER TABLE `tenant_registrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `tenant_registrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenants`
--

DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_name` varchar(255) NOT NULL,
  `tenant_slug` varchar(50) NOT NULL,
  `database_name` varchar(100) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `subscription_plan` varchar(50) DEFAULT 'Free',
  `max_users` int(11) DEFAULT 1,
  `max_branches` int(11) DEFAULT 1,
  `max_products` int(11) DEFAULT 100,
  `storage_limit_gb` int(11) DEFAULT 1,
  `status` enum('pending','active','suspended','trial','expired') DEFAULT 'pending',
  `country` varchar(100) DEFAULT 'Zimbabwe',
  `currency` varchar(10) DEFAULT 'USD',
  `timezone` varchar(50) DEFAULT 'Africa/Harare',
  `created_at` datetime DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `trial_ends_at` datetime DEFAULT NULL,
  `subscription_ends_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_slug` (`tenant_slug`),
  KEY `idx_tenant_slug` (`tenant_slug`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenants`
--

LOCK TABLES `tenants` WRITE;
/*!40000 ALTER TABLE `tenants` DISABLE KEYS */;
INSERT INTO `tenants` VALUES (1,'ELECTROX Primary','primary','electrox_primary','ELECTROX Electronics','Electronics Retail','admin@electrox.co.zw','System Administrator','Professional',50,10,10000,100,'active','Zimbabwe','USD','Africa/Harare','2025-12-12 18:08:09','2025-12-12 18:08:09',1,NULL,NULL),(2,'ELECTROX Primary','demo','electrox_demo','ELECTROX Electronics','Electronics Retail','admin@electrox.co.zw','System Administrator','Professional',50,10,10000,100,'active','Zimbabwe','USD','Africa/Harare','2025-12-12 18:08:24','2025-12-12 18:08:24',1,NULL,NULL);
/*!40000 ALTER TABLE `tenants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'electrox_base'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-12-15  7:18:10

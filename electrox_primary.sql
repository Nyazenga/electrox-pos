-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: electrox_primary
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
-- Current Database: `electrox_primary`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `electrox_primary` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `electrox_primary`;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=150 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,1,'login','{\"ip\": \"192.168.1.100\"}','192.168.1.100',NULL,'2025-12-12 17:08:06'),(2,1,'product_created','{\"product_id\": 1, \"product_code\": \"PROD00001\"}','192.168.1.100',NULL,'2025-12-12 16:08:06'),(3,2,'login','{\"ip\": \"192.168.1.101\"}','192.168.1.101',NULL,'2025-12-12 15:08:06'),(4,1,'invoice_created','{\"invoice_id\": 1, \"invoice_number\": \"INV-20241212-0001\"}','192.168.1.100',NULL,'2025-12-11 18:08:06'),(5,2,'sale_processed','{\"invoice_id\": 3, \"amount\": 1100.00}','192.168.1.101',NULL,'2025-12-09 18:08:06'),(6,1,'customer_created','{\"customer_id\": 1, \"customer_code\": \"CUST001\"}','192.168.1.100',NULL,'2025-12-07 18:08:06'),(7,1,'stock_adjusted','{\"product_id\": 1, \"quantity\": 20}','192.168.1.100',NULL,'2025-11-12 18:08:06'),(8,2,'tradein_assessed','{\"trade_in_id\": 1, \"valuation\": 400.00}','192.168.1.101',NULL,'2025-12-07 18:08:06'),(9,1,'report_generated','{\"report_type\": \"sales\", \"period\": \"monthly\"}','192.168.1.100',NULL,'2025-12-10 18:08:06'),(10,1,'user_created','{\"user_id\": 2, \"username\": \"cashier\"}','192.168.1.100',NULL,'2025-12-02 18:08:06'),(11,2,'logout','{\"ip\": \"192.168.1.101\"}','192.168.1.101',NULL,'2025-12-12 14:08:06'),(12,1,'settings_updated','{\"setting_key\": \"company_name\"}','192.168.1.100',NULL,'2025-12-05 18:08:06'),(13,1,'branch_created','{\"branch_id\": 2, \"branch_code\": \"BEL\"}','192.168.1.100',NULL,'2025-06-12 18:08:06'),(14,2,'product_viewed','{\"product_id\": 5}','192.168.1.101',NULL,'2025-12-12 17:08:06'),(15,1,'dashboard_accessed','{}','192.168.1.100',NULL,'2025-12-12 17:38:06'),(16,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-12 18:10:44'),(17,1,'logout',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-12 18:13:56'),(18,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-12 18:20:59'),(19,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-12 18:52:03'),(20,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-12 19:24:24'),(21,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-12 19:58:36'),(22,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 09:39:30'),(23,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 10:30:34'),(24,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 11:10:18'),(25,1,'tradein_created','{\"trade_in_id\":\"11\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 11:31:02'),(26,1,'tradein_processed','{\"trade_in_id\":11,\"sale_id\":false}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 11:31:02'),(27,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 11:42:04'),(28,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 12:12:53'),(29,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 19:32:49'),(30,1,'logout',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 19:38:03'),(31,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 19:38:24'),(32,1,'shift_start','{\"shift_id\":false,\"shift_number\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 19:38:47'),(33,1,'shift_start','{\"shift_id\":false,\"shift_number\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 19:38:53'),(34,1,'shift_start','{\"shift_id\":false,\"shift_number\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 19:39:02'),(35,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 20:03:32'),(36,1,'shift_start','{\"shift_id\":false,\"shift_number\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 20:04:44'),(37,1,'shift_start','{\"shift_id\":false,\"shift_number\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-13 20:04:49'),(38,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 02:58:10'),(39,1,'shift_start','{\"shift_id\":\"1\",\"shift_number\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 02:58:25'),(40,1,'pos_sale','{\"sale_id\":false,\"receipt_number\":\"1-2512141\",\"amount\":1501}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 02:59:18'),(41,1,'tradein_created','{\"trade_in_id\":\"12\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 03:01:49'),(42,1,'tradein_processed','{\"trade_in_id\":12,\"sale_id\":false}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 03:01:49'),(43,1,'pos_sale','{\"sale_id\":false,\"receipt_number\":\"1-2512141\",\"amount\":1300}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 03:02:55'),(44,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:17:06'),(45,1,'pos_sale','{\"sale_id\":false,\"receipt_number\":\"1-2512141\",\"amount\":2550}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:20:56'),(46,1,'shift_end','{\"shift_id\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:37:31'),(47,1,'shift_start','{\"shift_id\":\"2\",\"shift_number\":2}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:42:26'),(48,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:49:00'),(49,1,'logout',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:53:13'),(50,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:53:34'),(51,1,'logout',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:55:24'),(52,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:56:39'),(53,1,'pos_sale','{\"sale_id\":\"7\",\"receipt_number\":\"1-2512147\",\"amount\":2300}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 10:56:49'),(54,1,'pos_sale','{\"sale_id\":\"8\",\"receipt_number\":\"1-2512148\",\"amount\":3600}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:01:15'),(55,1,'pos_sale','{\"sale_id\":\"9\",\"receipt_number\":\"1-2512149\",\"amount\":1300}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:03:31'),(56,1,'pos_sale','{\"sale_id\":\"10\",\"receipt_number\":\"1-25121410\",\"amount\":2550}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:08:17'),(57,1,'pos_sale','{\"sale_id\":\"14\",\"receipt_number\":\"1-25121411\",\"amount\":1585}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:15:23'),(58,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:20:05'),(59,1,'pos_refund','{\"refund_id\":\"1\",\"sale_id\":14,\"refund_number\":\"REF-1-2512141\",\"amount\":1585}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:20:29'),(60,1,'pos_sale','{\"sale_id\":\"15\",\"receipt_number\":\"1-25121412\",\"amount\":2780}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:24:46'),(61,1,'pos_refund','{\"refund_id\":\"4\",\"sale_id\":15,\"refund_number\":\"REF-1-2512142\",\"amount\":2780}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:27:29'),(62,1,'pos_sale','{\"sale_id\":\"16\",\"receipt_number\":\"1-25121413\",\"amount\":2500}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:29:53'),(63,1,'pos_sale','{\"sale_id\":\"17\",\"receipt_number\":\"1-25121414\",\"amount\":2780}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:31:14'),(64,1,'pos_sale','{\"sale_id\":\"18\",\"receipt_number\":\"1-25121415\",\"amount\":1800}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:47:24'),(65,1,'pos_sale','{\"sale_id\":\"19\",\"receipt_number\":\"1-25121416\",\"amount\":25}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:48:14'),(66,1,'shift_end','{\"shift_id\":2}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:49:13'),(67,1,'shift_start','{\"shift_id\":\"3\",\"shift_number\":3}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:49:48'),(68,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 11:59:42'),(69,1,'drawer_transaction','{\"shift_id\":3,\"transaction_type\":\"pay_out\",\"amount\":10,\"reason\":\"Petty Cash\",\"notes\":\"LUNCH\",\"user_id\":1,\"created_at\":\"2025-12-14 12:00:12\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 12:00:12'),(70,1,'pos_refund','{\"refund_id\":\"5\",\"sale_id\":19,\"refund_number\":\"REF-1-2512143\",\"amount\":25}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 12:00:37'),(71,1,'pos_sale','{\"sale_id\":\"20\",\"receipt_number\":\"1-25121417\",\"amount\":1216}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 12:01:07'),(72,1,'tradein_created','{\"trade_in_id\":\"13\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 12:06:20'),(73,1,'tradein_processed','{\"trade_in_id\":13,\"sale_id\":false}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 12:06:20'),(74,1,'pos_refund','{\"refund_id\":\"6\",\"sale_id\":20,\"refund_number\":\"REF-1-2512144\",\"amount\":1216}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 12:08:42'),(75,1,'pos_sale','{\"sale_id\":\"22\",\"receipt_number\":\"1-25121418\",\"amount\":3700}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 12:09:25'),(76,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 12:29:57'),(77,1,'tradein_created','{\"trade_in_id\":\"14\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 12:48:53'),(78,1,'tradein_created','{\"trade_in_id\":\"15\"}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 12:49:12'),(79,1,'tradein_created','{\"trade_in_id\":false}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 12:49:20'),(80,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 13:00:12'),(81,1,'tradein_created','{\"trade_in_id\":false}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 13:02:18'),(82,1,'tradein_created','{\"trade_in_id\":false}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:05:14'),(83,1,'tradein_created','{\"trade_in_id\":false}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:06:59'),(84,1,'tradein_created','{\"trade_in_id\":\"21\"}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:18:52'),(85,1,'tradein_created','{\"trade_in_id\":\"22\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 13:22:42'),(86,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:33:41'),(87,1,'tradein_created','{\"trade_in_id\":\"23\"}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:35:13'),(88,1,'tradein_created','{\"trade_in_id\":\"24\"}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:47:34'),(89,1,'tradein_created','{\"trade_in_id\":\"25\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 13:51:26'),(90,1,'tradein_created','{\"trade_in_id\":\"26\"}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:54:11'),(91,1,'tradein_created','{\"trade_in_id\":\"27\"}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:58:06'),(92,1,'tradein_processed','{\"trade_in_id\":27,\"sale_id\":\"31\"}','::1','Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Mobile Safari/537.36 Edg/143.0.0.0','2025-12-14 13:58:06'),(93,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:05:31'),(94,1,'tradein_created','{\"trade_in_id\":\"28\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:07:43'),(95,1,'tradein_created','{\"trade_in_id\":\"29\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:14:52'),(96,1,'tradein_processed','{\"trade_in_id\":29,\"sale_id\":\"33\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:14:53'),(97,1,'tradein_created','{\"trade_in_id\":\"30\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:19:04'),(98,1,'tradein_processed','{\"trade_in_id\":30,\"sale_id\":\"35\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:19:05'),(99,1,'tradein_updated','{\"trade_in_id\":28}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:32:24'),(100,1,'pos_sale','{\"sale_id\":\"37\",\"receipt_number\":\"1-25121425\",\"amount\":1305}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:34:46'),(101,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 14:35:48'),(102,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 15:09:28'),(103,1,'tradein_created','{\"trade_in_id\":\"31\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 15:10:53'),(104,1,'tradein_processed','{\"trade_in_id\":31,\"sale_id\":\"38\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 15:10:54'),(105,1,'pos_sale','{\"sale_id\":\"40\",\"receipt_number\":\"1-25121428\",\"amount\":500}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 15:11:29'),(106,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 19:33:27'),(107,1,'invoice_created','\"Invoice created: PROF-20251214-9030\"','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 19:52:26'),(108,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 20:04:42'),(109,1,'invoice_created','\"Invoice created: PROF-20251214-2199\"','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 20:05:18'),(110,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 20:34:31'),(111,1,'grn_created','{\"grn_id\":\"1\",\"grn_number\":\"GRN-20251214-874\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 20:58:14'),(112,1,'transfer_created','{\"transfer_id\":\"1\",\"transfer_number\":\"TRF-20251214-363\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 21:02:03'),(113,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 21:07:59'),(114,0,'Invoice PROF-20251214-9030 status changed to Paid','{\"invoice_id\":18,\"old_status\":\"Unknown\",\"new_status\":\"Paid\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 21:30:07'),(115,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 21:38:29'),(116,1,'grn_status_updated','{\"grn_id\":1,\"status\":\"Approved\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 21:49:22'),(117,1,'transfer_status_updated','{\"transfer_id\":1,\"status\":\"Approved\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 21:49:34'),(118,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 22:19:45'),(119,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 22:50:12'),(120,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 23:32:07'),(121,1,'pos_sale','{\"sale_id\":\"43\",\"receipt_number\":\"1-251214814567\",\"amount\":2780}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-14 23:34:41'),(122,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 00:11:45'),(123,1,'shift_start','{\"shift_id\":\"4\",\"shift_number\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 00:21:33'),(124,1,'pos_sale','{\"sale_id\":\"44\",\"receipt_number\":\"3-2512151\",\"amount\":2500}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 00:22:04'),(125,1,'pos_sale','{\"sale_id\":\"45\",\"receipt_number\":\"3-2512152\",\"amount\":2900}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 00:22:30'),(126,1,'pos_sale','{\"sale_id\":\"46\",\"receipt_number\":\"3-2512153\",\"amount\":1280}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 00:24:35'),(127,1,'supplier_created','{\"supplier_id\":\"11\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 00:26:07'),(128,1,'pos_sale','{\"sale_id\":\"47\",\"receipt_number\":\"3-2512154\",\"amount\":1280}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 00:28:18'),(129,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 01:11:03'),(130,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:07:55'),(131,1,'pos_sale','{\"sale_id\":\"48\",\"receipt_number\":\"1-2512151\",\"amount\":1280}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:08:19'),(132,1,'pos_sale','{\"sale_id\":\"49\",\"receipt_number\":\"1-2512152\",\"amount\":1280}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:09:38'),(133,1,'invoice_created','\"Invoice created: TAX-20251215-7488\"','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:17:42'),(134,1,'pos_sale','{\"sale_id\":\"50\",\"receipt_number\":\"1-2512153\",\"amount\":305}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:20:32'),(135,1,'receipt_sent_email','{\"receipt_id\":50,\"receipt_number\":\"1-2512153\",\"email\":\"nyazengamd@gmail.com\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:22:18'),(136,1,'pos_sale','{\"sale_id\":\"51\",\"receipt_number\":\"3-2512155\",\"amount\":305}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:29:18'),(137,1,'receipt_sent_whatsapp','{\"receipt_id\":51,\"receipt_number\":\"3-2512155\",\"phone\":\"+263782794721\",\"whatsapp_link\":\"https:\\/\\/wa.me\\/263782794721?text=%F0%9F%93%A7+%2ARECEIPT+FROM+ELECTROX%2A%0A%0AReceipt+%23%3A+3-2512155%0ADate%3A+2025-12-15+06%3A29%3A18%0ACashier%3A+System+Administrator%0A%0A%2AITEMS%3A%2A%0A%E2%80%A2+Apple+20W+USB-C+Power+Adapter+x1+%3D+%2425.00%0A%E2%80%A2+Apple+AirPods+Pro+x1+%3D+%24280.00%0A%0A%2ASUMMARY%3A%2A%0ASubtotal%3A+%24305.00%0A%2ATOTAL%3A+%24305.00%2A%0A%0A%2APAYMENT%3A%2A%0A%0AThank+you+for+your+business%21%0AELECTROX\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:34:13'),(138,1,'pos_refund','{\"refund_id\":\"7\",\"sale_id\":51,\"refund_number\":\"REF-3-2512151\",\"amount\":305}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:36:12'),(139,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:39:45'),(140,1,'receipt_sent_email','{\"receipt_id\":50,\"receipt_number\":\"1-2512153\",\"email\":\"nyazengamd@gmail.com\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:45:29'),(141,1,'pos_sale','{\"sale_id\":\"52\",\"receipt_number\":\"1-2512154\",\"amount\":305}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:45:41'),(142,1,'receipt_sent_email','{\"receipt_id\":47,\"receipt_number\":\"3-2512154\",\"email\":\"nyazengamd@gmail.com\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 06:59:44'),(143,1,'drawer_transaction','{\"shift_id\":4,\"transaction_type\":\"pay_in\",\"amount\":23,\"reason\":\"Bank Deposit\",\"notes\":\"\",\"user_id\":1,\"created_at\":\"2025-12-15 07:00:19\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 07:00:19'),(144,1,'drawer_transaction','{\"shift_id\":4,\"transaction_type\":\"pay_out\",\"amount\":45,\"reason\":\"Petty Cash\",\"notes\":\"HI\",\"user_id\":1,\"created_at\":\"2025-12-15 07:00:32\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 07:00:32'),(145,1,'pos_sale','{\"sale_id\":\"53\",\"receipt_number\":\"3-2512156\",\"amount\":2500}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 07:02:46'),(146,1,'pos_sale','{\"sale_id\":\"54\",\"receipt_number\":\"3-2512157\",\"amount\":1280}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 07:05:43'),(147,1,'receipt_sent_email','{\"receipt_id\":54,\"receipt_number\":\"3-2512157\",\"email\":\"nyazengamd@gmail.com\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 07:06:19'),(148,1,'login','{\"ip\":\"::1\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 07:09:04'),(149,1,'pos_sale','{\"sale_id\":\"55\",\"receipt_number\":\"1-2512155\",\"amount\":1280}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2025-12-15 07:15:46');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_code` varchar(20) NOT NULL,
  `branch_name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `opening_date` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_code` (`branch_code`),
  KEY `idx_branch_code` (`branch_code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` VALUES (1,'HO','Head Office','123 Electronics Street','Harare','+263 242 700000','info@electrox.co.zw',NULL,'Active','2025-12-12','2025-12-12 16:22:31','2025-12-12 16:22:31'),(3,'BRHIL20251214','HILLSIDE','123 HILLSIDE BRANCH','HARARE','0782794721','nyazengamd@gmail.com',1,'Active','2025-12-14','2025-12-14 20:27:46','2025-12-14 20:27:46');
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(50) NOT NULL,
  `customer_type` enum('Individual','Corporate') DEFAULT 'Individual',
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `vat_number` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `loyalty_points` int(11) DEFAULT 0,
  `credit_limit` decimal(10,2) DEFAULT 0.00,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `customer_since` date DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `tags` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_code` (`customer_code`),
  KEY `idx_customer_code` (`customer_code`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'CUST001','Individual','Tendai','Mukamuri',NULL,NULL,NULL,'+263 772 111111','tendai@email.com','123 Main Street','Harare',150,0.00,0.00,'2024-12-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(2,'CUST002','Individual','Blessing','Chidza',NULL,NULL,NULL,'+263 773 222222','blessing@email.com','456 High Road','Harare',200,0.00,0.00,'2025-04-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(3,'CUST003','Corporate','John','Doe',NULL,NULL,NULL,'+263 774 333333','john@company.com','789 Business Park','Harare',500,0.00,0.00,'2025-06-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(4,'CUST004','Individual','Sarah','Moyo',NULL,NULL,NULL,'+263 775 444444','sarah@email.com','321 Residential Area','Harare',75,0.00,0.00,'2025-08-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(5,'CUST005','Individual','David','Nkomo',NULL,NULL,NULL,'+263 776 555555','david@email.com','654 Suburb Street','Harare',300,0.00,0.00,'2025-02-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(6,'CUST006','Corporate','Tech','Solutions',NULL,NULL,NULL,'+263 777 666666','info@techsolutions.co.zw','987 Corporate Avenue','Harare',1000,0.00,0.00,'2024-09-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(7,'CUST007','Individual','Linda','Sibanda',NULL,NULL,NULL,'+263 778 777777','linda@email.com','147 Home Street','Harare',50,0.00,0.00,'2025-10-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(8,'CUST008','Individual','Peter','Dube',NULL,NULL,NULL,'+263 779 888888','peter@email.com','258 Living Road','Harare',125,0.00,0.00,'2025-07-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(9,'CUST009','Corporate','Digital','Enterprises',NULL,NULL,NULL,'+263 771 999999','contact@digitalent.co.zw','369 Enterprise Park','Harare',750,0.00,0.00,'2025-03-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(10,'CUST010','Individual','Mary','Ndlovu',NULL,NULL,NULL,'+263 772 000000','mary@email.com','741 Personal Avenue','Harare',100,0.00,0.00,'2025-09-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(11,'CUST011','Individual','James','Mupfumi',NULL,NULL,NULL,'+263 773 111111','james@email.com','852 Family Road','Harare',175,0.00,0.00,'2025-05-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(12,'CUST012','Individual','Grace','Chidza',NULL,NULL,NULL,'+263 774 222222','grace@email.com','963 Community Street','Harare',225,0.00,0.00,'2025-01-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(13,'CUST013','Corporate','Smart','Business',NULL,NULL,NULL,'+263 775 333333','info@smartbiz.co.zw','159 Business Centre','Harare',600,0.00,0.00,'2024-11-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(14,'CUST014','Individual','Michael','Moyo',NULL,NULL,NULL,'+263 776 444444','michael@email.com','357 Residential Road','Harare',80,0.00,0.00,'2025-11-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL),(15,'CUST015','Individual','Patience','Nkomo',NULL,NULL,NULL,'+263 777 555555','patience@email.com','468 Home Avenue','Harare',250,0.00,0.00,'2024-10-12','Active',NULL,NULL,'2025-12-12 18:08:06','2025-12-12 18:08:06',NULL);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `drawer_transactions`
--

LOCK TABLES `drawer_transactions` WRITE;
/*!40000 ALTER TABLE `drawer_transactions` DISABLE KEYS */;
INSERT INTO `drawer_transactions` VALUES (1,3,'pay_out',10.00,NULL,1.000000,NULL,NULL,'Petty Cash','LUNCH',1,'2025-12-14 12:00:12'),(2,4,'pay_in',23.00,NULL,1.000000,NULL,NULL,'Bank Deposit','',1,'2025-12-15 07:00:19'),(3,4,'pay_out',45.00,NULL,1.000000,NULL,NULL,'Petty Cash','HI',1,'2025-12-15 07:00:32'),(4,4,'pay_out',20.00,NULL,1.000000,NULL,NULL,'Change Given','Change for receipt 3-2512157',1,'2025-12-15 07:05:43'),(5,3,'pay_out',20.00,NULL,1.000000,NULL,NULL,'Change Given','Change for receipt 1-2512155',1,'2025-12-15 07:15:46');
/*!40000 ALTER TABLE `drawer_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `goods_received_notes`
--

DROP TABLE IF EXISTS `goods_received_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `goods_received_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grn_number` varchar(50) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `received_date` date DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `total_value` decimal(10,2) DEFAULT 0.00,
  `status` enum('Draft','Approved','Rejected') DEFAULT 'Draft',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grn_number` (`grn_number`),
  KEY `idx_grn_number` (`grn_number`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `goods_received_notes`
--

LOCK TABLES `goods_received_notes` WRITE;
/*!40000 ALTER TABLE `goods_received_notes` DISABLE KEYS */;
INSERT INTO `goods_received_notes` VALUES (1,'GRN-20251214-874',8,1,'2025-12-14',1,1680.00,'Approved','NONE','2025-12-14 20:58:14','2025-12-14 21:49:22',1,'2025-12-14 21:49:22');
/*!40000 ALTER TABLE `goods_received_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grn_items`
--

DROP TABLE IF EXISTS `grn_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grn_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grn_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `serial_numbers` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `selling_price` decimal(10,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_grn_id` (`grn_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grn_items`
--

LOCK TABLES `grn_items` WRITE;
/*!40000 ALTER TABLE `grn_items` DISABLE KEYS */;
INSERT INTO `grn_items` VALUES (1,1,20,NULL,56,30.00,45.00,'2025-12-14 20:58:14');
/*!40000 ALTER TABLE `grn_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice_items`
--

DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `line_total` decimal(10,2) DEFAULT 0.00,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `profit_margin` decimal(5,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice_items`
--

LOCK TABLES `invoice_items` WRITE;
/*!40000 ALTER TABLE `invoice_items` DISABLE KEYS */;
INSERT INTO `invoice_items` VALUES (1,1,1,NULL,1,1500.00,0.00,0.00,1500.00,1200.00,0.00,'2025-12-11 18:08:06'),(2,2,6,NULL,1,2200.00,0.00,0.00,2200.00,1800.00,0.00,'2025-12-10 18:08:06'),(3,3,4,NULL,1,1150.00,0.00,0.00,1150.00,900.00,0.00,'2025-12-09 18:08:06'),(4,4,5,NULL,1,900.00,0.00,0.00,900.00,700.00,0.00,'2025-12-08 18:08:06'),(5,5,7,NULL,2,2500.00,0.00,0.00,5000.00,2000.00,0.00,'2025-12-07 18:08:06'),(6,6,10,NULL,1,1300.00,0.00,0.00,1300.00,1000.00,0.00,'2025-12-06 18:08:06'),(7,7,14,NULL,1,280.00,0.00,0.00,280.00,200.00,0.00,'2025-12-05 18:08:06'),(8,8,16,NULL,1,450.00,0.00,0.00,450.00,350.00,0.00,'2025-12-04 18:08:06'),(9,9,8,NULL,2,1900.00,0.00,0.00,3800.00,1500.00,0.00,'2025-12-03 18:08:06'),(10,10,3,NULL,1,1000.00,0.00,0.00,1000.00,800.00,0.00,'2025-12-02 18:08:06'),(11,11,18,NULL,1,650.00,0.00,0.00,650.00,500.00,0.00,'2025-12-01 18:08:06'),(12,12,19,NULL,1,120.00,0.00,0.00,120.00,80.00,0.00,'2025-11-30 18:08:06'),(13,13,7,NULL,1,2500.00,0.00,0.00,2500.00,2000.00,0.00,'2025-11-29 18:08:06'),(14,14,15,NULL,1,400.00,0.00,0.00,400.00,300.00,0.00,'2025-11-28 18:08:06'),(15,15,8,NULL,1,1900.00,0.00,0.00,1900.00,1500.00,0.00,'2025-11-27 18:08:06'),(16,1,12,NULL,2,25.00,0.00,0.00,50.00,15.00,0.00,'2025-12-11 18:08:06'),(17,2,20,NULL,1,45.00,0.00,0.00,45.00,30.00,0.00,'2025-12-10 18:08:06'),(18,3,13,NULL,1,20.00,0.00,0.00,20.00,12.00,0.00,'2025-12-09 18:08:06'),(19,4,14,NULL,1,280.00,0.00,0.00,280.00,200.00,0.00,'2025-12-08 18:08:06'),(20,5,16,NULL,1,450.00,0.00,0.00,450.00,350.00,0.00,'2025-12-07 18:08:06'),(21,6,17,NULL,1,380.00,0.00,0.00,380.00,280.00,0.00,'2025-12-06 18:08:06'),(22,7,12,NULL,1,25.00,0.00,0.00,25.00,15.00,0.00,'2025-12-05 18:08:06'),(23,8,13,NULL,1,20.00,0.00,0.00,20.00,12.00,0.00,'2025-12-04 18:08:06'),(24,9,19,NULL,2,120.00,0.00,0.00,240.00,80.00,0.00,'2025-12-03 18:08:06'),(25,10,20,NULL,1,45.00,0.00,0.00,45.00,30.00,0.00,'2025-12-02 18:08:06'),(26,11,12,NULL,1,25.00,0.00,0.00,25.00,15.00,0.00,'2025-12-01 18:08:06'),(27,12,13,NULL,1,20.00,0.00,0.00,20.00,12.00,0.00,'2025-11-30 18:08:06'),(28,13,14,NULL,1,280.00,0.00,0.00,280.00,200.00,0.00,'2025-11-29 18:08:06'),(29,14,15,NULL,1,400.00,0.00,0.00,400.00,300.00,0.00,'2025-11-28 18:08:06'),(30,15,16,NULL,1,450.00,0.00,0.00,450.00,350.00,0.00,'2025-11-27 18:08:06'),(33,18,12,NULL,1,25.00,0.00,0.00,25.00,15.00,40.00,'2025-12-14 19:52:26'),(34,19,12,NULL,1,25.00,0.00,0.00,25.00,15.00,40.00,'2025-12-14 20:05:18'),(35,20,10,NULL,1,1300.00,0.00,0.00,1300.00,1000.00,23.08,'2025-12-15 06:17:42');
/*!40000 ALTER TABLE `invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_type` enum('Receipt','TaxInvoice','Proforma','Quote','CreditNote') DEFAULT 'Receipt',
  `customer_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `balance_due` decimal(10,2) DEFAULT 0.00,
  `payment_methods` text DEFAULT NULL,
  `invoice_date` datetime DEFAULT current_timestamp(),
  `due_date` date DEFAULT NULL,
  `status` enum('Draft','Sent','Viewed','Paid','Overdue','Void') DEFAULT 'Draft',
  `fiscalized` tinyint(1) DEFAULT 0,
  `fiscal_details` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fiscalized_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `idx_invoice_number` (`invoice_number`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_invoice_date` (`invoice_date`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
INSERT INTO `invoices` VALUES (1,'INV-20241212-0001','Receipt',1,1,1,1500.00,0.00,0.00,1500.00,1500.00,0.00,'[\"USD Cash\"]','2025-12-11 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-11 18:08:06','2025-12-12 18:08:06',NULL),(2,'INV-20241212-0002','TaxInvoice',3,1,1,2200.00,0.00,319.00,2519.00,2519.00,0.00,'[\"Bank Transfer\"]','2025-12-10 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-10 18:08:06','2025-12-12 18:08:06',NULL),(3,'INV-20241212-0003','Receipt',2,2,2,1150.00,50.00,0.00,1100.00,1100.00,0.00,'[\"EcoCash\"]','2025-12-09 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-09 18:08:06','2025-12-12 18:08:06',NULL),(4,'INV-20241212-0004','Receipt',4,1,1,900.00,0.00,0.00,900.00,900.00,0.00,'[\"USD Cash\"]','2025-12-08 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-08 18:08:06','2025-12-12 18:08:06',NULL),(5,'INV-20241212-0005','TaxInvoice',6,1,1,5000.00,200.00,696.00,5496.00,3000.00,2496.00,'[\"Bank Transfer\"]','2025-12-07 18:08:06',NULL,'',0,NULL,NULL,NULL,'2025-12-07 18:08:06','2025-12-12 18:08:06',NULL),(6,'INV-20241212-0006','Receipt',5,2,2,1300.00,0.00,0.00,1300.00,1300.00,0.00,'[\"OneMoney\"]','2025-12-06 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-06 18:08:06','2025-12-12 18:08:06',NULL),(7,'INV-20241212-0007','Receipt',7,1,1,280.00,0.00,0.00,280.00,280.00,0.00,'[\"USD Cash\"]','2025-12-05 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-05 18:08:06','2025-12-12 18:08:06',NULL),(8,'INV-20241212-0008','Receipt',8,1,1,450.00,0.00,0.00,450.00,450.00,0.00,'[\"Card\"]','2025-12-04 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-04 18:08:06','2025-12-12 18:08:06',NULL),(9,'INV-20241212-0009','TaxInvoice',9,2,2,3800.00,0.00,551.00,4351.00,4351.00,0.00,'[\"Bank Transfer\"]','2025-12-03 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-03 18:08:06','2025-12-12 18:08:06',NULL),(10,'INV-20241212-0010','Receipt',10,1,1,1000.00,100.00,0.00,900.00,900.00,0.00,'[\"USD Cash\"]','2025-12-02 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-02 18:08:06','2025-12-12 18:08:06',NULL),(11,'INV-20241212-0011','Receipt',11,2,2,650.00,0.00,0.00,650.00,650.00,0.00,'[\"EcoCash\"]','2025-12-01 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-12-01 18:08:06','2025-12-12 18:08:06',NULL),(12,'INV-20241212-0012','Receipt',12,1,1,120.00,0.00,0.00,120.00,120.00,0.00,'[\"USD Cash\"]','2025-11-30 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-11-30 18:08:06','2025-12-12 18:08:06',NULL),(13,'INV-20241212-0013','TaxInvoice',13,1,1,2500.00,0.00,362.50,2862.50,1500.00,1362.50,'[\"Bank Transfer\"]','2025-11-29 18:08:06',NULL,'',0,NULL,NULL,NULL,'2025-11-29 18:08:06','2025-12-12 18:08:06',NULL),(14,'INV-20241212-0014','Receipt',14,2,2,400.00,0.00,0.00,400.00,400.00,0.00,'[\"OneMoney\"]','2025-11-28 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-11-28 18:08:06','2025-12-12 18:08:06',NULL),(15,'INV-20241212-0015','Receipt',15,1,1,1900.00,50.00,0.00,1850.00,1850.00,0.00,'[\"Card\"]','2025-11-27 18:08:06',NULL,'Paid',0,NULL,NULL,NULL,'2025-11-27 18:08:06','2025-12-12 18:08:06',NULL),(18,'PROF-20251214-9030','Proforma',2,1,1,25.00,0.00,3.75,28.75,0.00,28.75,NULL,'2025-12-14 00:00:00','2026-01-13','Paid',0,NULL,NULL,NULL,'2025-12-14 19:52:26','2025-12-14 21:30:07',NULL),(19,'PROF-20251214-2199','Proforma',2,1,1,25.00,0.00,3.75,28.75,0.00,28.75,NULL,'2025-12-14 00:00:00','2026-01-13','Draft',0,NULL,NULL,NULL,'2025-12-14 20:05:18','2025-12-14 20:05:18',NULL),(20,'TAX-20251215-7488','TaxInvoice',2,1,1,1300.00,0.00,195.00,1495.00,0.00,1495.00,NULL,'2025-12-15 00:00:00','2026-01-14','Draft',0,NULL,NULL,NULL,'2025-12-15 06:17:42','2025-12-15 06:17:42',NULL);
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'USD',
  `exchange_rate` decimal(10,4) DEFAULT 1.0000,
  `reference_number` varchar(100) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `payment_date` datetime DEFAULT current_timestamp(),
  `received_by` int(11) DEFAULT NULL,
  `status` enum('Completed','Pending','Failed') DEFAULT 'Completed',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,1,'USD Cash',1500.00,'USD',1.0000,NULL,NULL,'2025-12-11 18:08:06',1,'Completed',NULL,'2025-12-11 18:08:06'),(2,2,'Bank Transfer',2519.00,'USD',1.0000,NULL,NULL,'2025-12-10 18:08:06',1,'Completed',NULL,'2025-12-10 18:08:06'),(3,3,'EcoCash',1100.00,'USD',1.0000,NULL,NULL,'2025-12-09 18:08:06',2,'Completed',NULL,'2025-12-09 18:08:06'),(4,4,'USD Cash',900.00,'USD',1.0000,NULL,NULL,'2025-12-08 18:08:06',1,'Completed',NULL,'2025-12-08 18:08:06'),(5,5,'Bank Transfer',3000.00,'USD',1.0000,NULL,NULL,'2025-12-07 18:08:06',1,'Completed',NULL,'2025-12-07 18:08:06'),(6,6,'OneMoney',1300.00,'USD',1.0000,NULL,NULL,'2025-12-06 18:08:06',2,'Completed',NULL,'2025-12-06 18:08:06'),(7,7,'USD Cash',280.00,'USD',1.0000,NULL,NULL,'2025-12-05 18:08:06',1,'Completed',NULL,'2025-12-05 18:08:06'),(8,8,'Card',450.00,'USD',1.0000,NULL,NULL,'2025-12-04 18:08:06',1,'Completed',NULL,'2025-12-04 18:08:06'),(9,9,'Bank Transfer',4351.00,'USD',1.0000,NULL,NULL,'2025-12-03 18:08:06',2,'Completed',NULL,'2025-12-03 18:08:06'),(10,10,'USD Cash',900.00,'USD',1.0000,NULL,NULL,'2025-12-02 18:08:06',1,'Completed',NULL,'2025-12-02 18:08:06'),(11,11,'EcoCash',650.00,'USD',1.0000,NULL,NULL,'2025-12-01 18:08:06',2,'Completed',NULL,'2025-12-01 18:08:06'),(12,12,'USD Cash',120.00,'USD',1.0000,NULL,NULL,'2025-11-30 18:08:06',1,'Completed',NULL,'2025-11-30 18:08:06'),(13,13,'Bank Transfer',1500.00,'USD',1.0000,NULL,NULL,'2025-11-29 18:08:06',1,'Completed',NULL,'2025-11-29 18:08:06'),(14,14,'OneMoney',400.00,'USD',1.0000,NULL,NULL,'2025-11-28 18:08:06',2,'Completed',NULL,'2025-11-28 18:08:06'),(15,15,'Card',1850.00,'USD',1.0000,NULL,NULL,'2025-11-27 18:08:06',1,'Completed',NULL,'2025-11-27 18:08:06');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
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
INSERT INTO `permissions` VALUES (1,'dashboard.view','View Dashboard','Dashboard','View the main dashboard','2025-12-14 23:12:39'),(2,'products.view','View Products','Products','View product list','2025-12-14 23:12:39'),(3,'products.create','Create Products','Products','Create new products','2025-12-14 23:12:39'),(4,'products.edit','Edit Products','Products','Edit existing products','2025-12-14 23:12:39'),(5,'products.delete','Delete Products','Products','Delete products','2025-12-14 23:12:39'),(6,'products.categories','Manage Categories','Products','Manage product categories','2025-12-14 23:12:39'),(7,'inventory.view','View Inventory','Inventory','View stock levels','2025-12-14 23:12:40'),(8,'inventory.create','Create Inventory','Inventory','Create GRN and transfers','2025-12-14 23:12:40'),(9,'inventory.edit','Edit Inventory','Inventory','Edit inventory records','2025-12-14 23:12:40'),(10,'inventory.delete','Delete Inventory','Inventory','Delete inventory records','2025-12-14 23:12:40'),(11,'pos.view','View POS','POS','Access POS system','2025-12-14 23:12:40'),(12,'pos.create','Create Sales','POS','Create new sales','2025-12-14 23:12:40'),(13,'pos.edit','Edit Sales','POS','Edit sales records','2025-12-14 23:12:40'),(14,'pos.delete','Delete Sales','POS','Delete sales records','2025-12-14 23:12:40'),(15,'pos.refund','Process Refunds','POS','Process refunds','2025-12-14 23:12:40'),(16,'pos.cash','Cash Management','POS','Manage cash drawer and shifts','2025-12-14 23:12:40'),(17,'sales.view','View Sales','Sales','View sales list','2025-12-14 23:12:40'),(18,'sales.create','Create Sales','Sales','Create new sales','2025-12-14 23:12:40'),(19,'sales.edit','Edit Sales','Sales','Edit sales records','2025-12-14 23:12:40'),(20,'sales.delete','Delete Sales','Sales','Delete sales records','2025-12-14 23:12:40'),(21,'invoices.view','View Invoices','Invoicing','View invoice list','2025-12-14 23:12:40'),(22,'invoices.create','Create Invoices','Invoicing','Create new invoices','2025-12-14 23:12:40'),(23,'invoices.edit','Edit Invoices','Invoicing','Edit existing invoices','2025-12-14 23:12:40'),(24,'invoices.delete','Delete Invoices','Invoicing','Delete invoices','2025-12-14 23:12:40'),(25,'invoices.print','Print Invoices','Invoicing','Print invoices','2025-12-14 23:12:40'),(26,'customers.view','View Customers','Customers','View customer list','2025-12-14 23:12:40'),(27,'customers.create','Create Customers','Customers','Create new customers','2025-12-14 23:12:40'),(28,'customers.edit','Edit Customers','Customers','Edit existing customers','2025-12-14 23:12:40'),(29,'customers.delete','Delete Customers','Customers','Delete customers','2025-12-14 23:12:40'),(30,'suppliers.view','View Suppliers','Suppliers','View supplier list','2025-12-14 23:12:40'),(31,'suppliers.create','Create Suppliers','Suppliers','Create new suppliers','2025-12-14 23:12:40'),(32,'suppliers.edit','Edit Suppliers','Suppliers','Edit existing suppliers','2025-12-14 23:12:40'),(33,'suppliers.delete','Delete Suppliers','Suppliers','Delete suppliers','2025-12-14 23:12:40'),(34,'tradeins.view','View Trade-Ins','Trade-Ins','View trade-in list','2025-12-14 23:12:40'),(35,'tradeins.create','Create Trade-Ins','Trade-Ins','Create new trade-ins','2025-12-14 23:12:40'),(36,'tradeins.edit','Edit Trade-Ins','Trade-Ins','Edit existing trade-ins','2025-12-14 23:12:40'),(37,'tradeins.delete','Delete Trade-Ins','Trade-Ins','Delete trade-ins','2025-12-14 23:12:40'),(38,'reports.view','View Reports','Reports','View all reports','2025-12-14 23:12:40'),(39,'reports.sales','Sales Reports','Reports','View sales reports','2025-12-14 23:12:40'),(40,'reports.inventory','Inventory Reports','Reports','View inventory reports','2025-12-14 23:12:40'),(41,'reports.financial','Financial Reports','Reports','View financial reports','2025-12-14 23:12:40'),(42,'branches.view','View Branches','Administration','View branch list','2025-12-14 23:12:40'),(43,'branches.create','Create Branches','Administration','Create new branches','2025-12-14 23:12:40'),(44,'branches.edit','Edit Branches','Administration','Edit existing branches','2025-12-14 23:12:40'),(45,'branches.delete','Delete Branches','Administration','Delete branches','2025-12-14 23:12:40'),(46,'users.view','View Users','Administration','View user list','2025-12-14 23:12:40'),(47,'users.create','Create Users','Administration','Create new users','2025-12-14 23:12:40'),(48,'users.edit','Edit Users','Administration','Edit existing users','2025-12-14 23:12:40'),(49,'users.delete','Delete Users','Administration','Delete users','2025-12-14 23:12:40'),(50,'roles.view','View Roles','Administration','View role list','2025-12-14 23:12:40'),(51,'roles.create','Create Roles','Administration','Create new roles','2025-12-14 23:12:40'),(52,'roles.edit','Edit Roles','Administration','Edit existing roles','2025-12-14 23:12:40'),(53,'roles.delete','Delete Roles','Administration','Delete roles','2025-12-14 23:12:40'),(54,'roles.permissions','Manage Permissions','Administration','Assign permissions to roles','2025-12-14 23:12:40'),(55,'settings.view','View Settings','Administration','View system settings','2025-12-14 23:12:40'),(56,'settings.edit','Edit Settings','Administration','Edit system settings','2025-12-14 23:12:40');
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
-- Table structure for table `product_categories`
--

DROP TABLE IF EXISTS `product_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `required_fields` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_categories`
--

LOCK TABLES `product_categories` WRITE;
/*!40000 ALTER TABLE `product_categories` DISABLE KEYS */;
INSERT INTO `product_categories` VALUES (1,NULL,'Smartphones','Mobile phones and smartphones',NULL,'[\"brand\",\"model\",\"color\",\"storage\",\"sim\",\"serial\"]','2025-12-12 16:22:31','2025-12-12 16:22:31'),(2,NULL,'Laptops','Laptop computers',NULL,'[\"brand\",\"model\",\"color\",\"storage\",\"serial\"]','2025-12-12 16:22:31','2025-12-12 16:22:31'),(3,NULL,'Tablets','Tablet devices',NULL,'[\"brand\",\"model\",\"color\",\"storage\",\"serial\"]','2025-12-12 16:22:31','2025-12-12 16:22:31'),(4,NULL,'Charging Adapters','Chargers and adapters',NULL,'[\"brand\",\"model\"]','2025-12-12 16:22:31','2025-12-12 16:22:31'),(5,NULL,'Audio Devices','Headphones, speakers, etc.',NULL,'[\"brand\",\"model\",\"color\"]','2025-12-12 16:22:31','2025-12-12 16:22:31'),(6,NULL,'Wearables','Smartwatches, fitness trackers',NULL,'[\"brand\",\"model\",\"color\"]','2025-12-12 16:22:31','2025-12-12 16:22:31'),(7,NULL,'Gaming','Gaming devices and accessories',NULL,'[\"brand\",\"model\"]','2025-12-12 16:22:31','2025-12-12 16:22:31'),(8,NULL,'Networking','Network equipment',NULL,'[\"brand\",\"model\"]','2025-12-12 16:22:31','2025-12-12 16:22:31'),(9,NULL,'Accessories','General accessories',NULL,'[\"brand\",\"model\"]','2025-12-12 16:22:31','2025-12-12 16:22:31');
/*!40000 ALTER TABLE `product_categories` ENABLE KEYS */;
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
  UNIQUE KEY `unique_favorite` (`product_id`,`user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_favorites`
--

LOCK TABLES `product_favorites` WRITE;
/*!40000 ALTER TABLE `product_favorites` DISABLE KEYS */;
INSERT INTO `product_favorites` VALUES (1,14,1,'2025-12-13 11:26:02'),(2,10,1,'2025-12-13 11:26:04'),(3,3,1,'2025-12-14 03:00:14'),(4,20,1,'2025-12-14 03:03:27'),(5,1,1,'2025-12-14 11:31:44');
/*!40000 ALTER TABLE `product_favorites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_code` varchar(50) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `storage` varchar(50) DEFAULT NULL,
  `sim_configuration` varchar(50) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `imei` varchar(50) DEFAULT NULL,
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
  `quantity_in_stock` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_code` (`product_code`),
  KEY `idx_product_code` (`product_code`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_status` (`status`),
  KEY `idx_is_trade_in` (`is_trade_in`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'PROD00001',1,'Apple','iPhone 15 Pro Max','Natural Titanium','256GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1200.00,1500.00,0.00,0.00,5,10,0,NULL,'New','Active',0,0,NULL,NULL,1,10,'2025-11-12 00:00:00','2025-12-14 23:34:41',NULL,NULL),(2,'PROD00002',1,'Samsung','Galaxy S24 Ultra','Titanium Black','512GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1100.00,1400.00,0.00,0.00,5,10,0,NULL,'New','Active',0,0,NULL,NULL,1,9,'2025-11-17 00:00:00','2025-12-14 12:09:25',NULL,NULL),(3,'PROD00003',1,'Apple','iPhone 15','Blue','128GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,800.00,1000.00,0.00,0.00,10,10,0,NULL,'New','Active',0,0,NULL,NULL,1,6,'2025-11-22 00:00:00','2025-12-15 07:15:46',NULL,NULL),(4,'PROD00004',1,'Samsung','Galaxy S24','Marble Gray','256GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,900.00,1150.00,0.00,0.00,8,10,0,NULL,'New','Active',0,0,NULL,NULL,1,15,'2025-11-24 00:00:00','2025-12-14 15:10:54',NULL,NULL),(5,'PROD00005',1,'Huawei','P50 Pro','Golden Black','256GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,700.00,900.00,0.00,0.00,10,10,0,NULL,'New','Active',0,0,NULL,NULL,2,25,'2025-11-27 00:00:00','2025-12-12 18:08:06',NULL,NULL),(6,'PROD00006',2,'Dell','XPS 15','Platinum Silver','1TB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1800.00,2200.00,0.00,0.00,3,10,0,NULL,'New','Active',0,0,NULL,NULL,1,8,'2025-11-14 00:00:00','2025-12-12 18:08:06',NULL,NULL),(7,'PROD00007',2,'Apple','MacBook Pro M3','Space Gray','512GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,2000.00,2500.00,0.00,0.00,3,10,0,NULL,'New','Active',0,0,NULL,NULL,1,6,'2025-11-20 00:00:00','2025-12-12 18:08:06',NULL,NULL),(8,'PROD00008',2,'HP','Spectre x360','Nightfall Black','512GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1500.00,1900.00,0.00,0.00,5,10,0,NULL,'New','Active',0,0,NULL,NULL,2,10,'2025-11-25 00:00:00','2025-12-12 18:08:06',NULL,NULL),(9,'PROD00009',2,'Lenovo','ThinkPad X1 Carbon','Black','1TB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1600.00,2000.00,0.00,0.00,4,10,0,NULL,'New','Active',0,0,NULL,NULL,1,9,'2025-11-30 00:00:00','2025-12-12 18:08:06',NULL,NULL),(10,'PROD00010',3,'Apple','iPad Pro 12.9\"','Space Gray','256GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.00,1300.00,0.00,0.00,5,10,0,NULL,'New','Active',0,0,NULL,NULL,1,0,'2025-12-02 00:00:00','2025-12-14 11:03:30',NULL,NULL),(11,'PROD00011',3,'Samsung','Galaxy Tab S9','Graphite','256GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,900.00,1150.00,0.00,0.00,6,10,0,NULL,'New','Active',0,0,NULL,NULL,2,14,'2025-12-04 00:00:00','2025-12-12 18:08:06',NULL,NULL),(12,'PROD00012',4,'Apple','20W USB-C Power Adapter','White',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,15.00,25.00,0.00,0.00,20,10,0,NULL,'New','Active',0,0,NULL,NULL,1,45,'2025-12-07 00:00:00','2025-12-15 06:45:41',NULL,NULL),(13,'PROD00013',4,'Samsung','25W Super Fast Charger','Black',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,12.00,20.00,0.00,0.00,25,10,0,NULL,'New','Active',0,0,NULL,'[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693e0c94b5f07_logo-icon.png\"]',1,58,'2025-12-09 00:00:00','2025-12-14 21:49:34',NULL,NULL),(14,'PROD00014',5,'Apple','AirPods Pro','White',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,200.00,280.00,0.00,0.00,10,10,0,NULL,'New','Active',0,0,NULL,NULL,1,16,'2025-12-05 00:00:00','2025-12-15 07:15:46',NULL,NULL),(15,'PROD00015',5,'Sony','WH-1000XM5','Black','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,300.00,400.00,0.00,0.00,8,10,0,NULL,'New','Inactive',0,0,NULL,NULL,1,20,'2025-12-06 00:00:00','2025-12-14 12:04:14',NULL,1),(16,'PROD00016',6,'Apple','Watch Series 9','Midnight','45mm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,350.00,450.00,0.00,0.00,10,10,0,NULL,'New','Active',0,0,NULL,NULL,1,25,'2025-12-08 00:00:00','2025-12-12 18:08:06',NULL,NULL),(17,'PROD00017',6,'Samsung','Galaxy Watch 6','Graphite','44mm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,280.00,380.00,0.00,0.00,12,10,0,NULL,'New','Active',0,0,NULL,'[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693d2f6b12769_logo.png\"]',2,22,'2025-12-10 00:00:00','2025-12-13 11:18:35',NULL,NULL),(18,'PROD00018',7,'Sony','PlayStation 5','White','825GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,500.00,650.00,0.00,0.00,5,10,0,NULL,'New','Active',0,0,NULL,NULL,1,14,'2025-12-03 00:00:00','2025-12-14 14:14:53',NULL,NULL),(19,'PROD00019',8,'TP-Link','Archer AX50','Black',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,80.00,120.00,0.00,0.00,15,10,0,NULL,'New','Active',0,0,NULL,NULL,1,35,'2025-12-01 00:00:00','2025-12-12 18:08:06',NULL,NULL),(20,'PROD00020',9,'Apple','MagSafe Charger','White',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,30.00,45.00,0.00,0.00,20,10,0,NULL,'New','Active',0,0,NULL,'[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693c5abe5a31c_favicon.ico\"]',1,112,'2025-12-11 00:00:00','2025-12-14 21:49:22',NULL,NULL),(21,'PROD-OISEL',1,'Tecno','Spark','Blue','120','Dual SIM','12345','12345',NULL,NULL,'Tecno','Spark',600.00,630.00,0.00,0.00,10,10,0,NULL,'Used','Active',0,1,NULL,'[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693f8e42880d6_pngtree-a-packet-of-rice-png-image_19449986.png\"]',1,1,'2025-12-14 13:58:06','2025-12-15 06:27:46',1,NULL),(22,'PROD-XHWSA',7,'Sony','PS5','','','','','',NULL,NULL,'PS5','1TB',500.00,550.00,0.00,0.00,10,10,0,NULL,'Used','Active',0,1,NULL,'[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693f8e3a68048_Great-Value-Long-Grain-Rice-90-Second-Pouch-8-8-oz_c157578f-27db-4d21-9d5e-8735034d3db4.06f6c06aed47f896b6093b840aa4939c.avif\"]',1,0,'2025-12-14 14:14:53','2025-12-15 06:27:38',1,NULL),(23,'PROD-ESMVG',7,'Sony','PS5','','','','','',NULL,NULL,'Play station','2TB',340.00,400.00,0.00,0.00,10,10,0,NULL,'Used','Active',0,1,NULL,'[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693f8e3267833_SUGAR PACKET SPIKE 12X1Kg-500x500.jpeg\"]',1,1,'2025-12-14 14:19:05','2025-12-15 06:27:30',1,NULL),(24,'PROD-80AFM',5,'Tecno','Spark','Grey','','','','',NULL,NULL,'','',450.00,500.00,0.00,0.00,10,10,0,NULL,'Used','Active',0,1,NULL,'[\"http:\\/\\/localhost\\/electrox-pos\\/uploads\\/products\\/693f3bff231d7_images.jfif\"]',1,0,'2025-12-14 15:10:54','2025-12-15 00:36:47',1,NULL);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refund_items`
--

DROP TABLE IF EXISTS `refund_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refund_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `refund_id` int(11) NOT NULL,
  `sale_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_refund_id` (`refund_id`),
  KEY `idx_sale_item_id` (`sale_item_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refund_items`
--

LOCK TABLES `refund_items` WRITE;
/*!40000 ALTER TABLE `refund_items` DISABLE KEYS */;
INSERT INTO `refund_items` VALUES (1,1,22,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 11:20:29'),(2,1,23,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-14 11:20:29'),(3,1,24,14,'Apple AirPods Pro',2,280.00,560.00,'2025-12-14 11:20:29'),(4,4,25,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 11:27:29'),(5,4,26,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 11:27:29'),(6,4,27,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-14 11:27:29'),(7,5,34,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-14 12:00:37'),(8,6,35,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 12:08:42'),(9,6,36,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 12:08:42'),(10,7,74,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-15 06:36:12'),(11,7,75,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 06:36:12');
/*!40000 ALTER TABLE `refund_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refund_payments`
--

DROP TABLE IF EXISTS `refund_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refund_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `refund_id` int(11) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,6) DEFAULT 1.000000,
  `original_amount` decimal(10,2) DEFAULT NULL,
  `base_amount` decimal(10,2) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_refund_id` (`refund_id`),
  KEY `idx_currency_id` (`currency_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refund_payments`
--

LOCK TABLES `refund_payments` WRITE;
/*!40000 ALTER TABLE `refund_payments` DISABLE KEYS */;
INSERT INTO `refund_payments` VALUES (1,1,'cash',NULL,1.000000,NULL,NULL,1585.00,NULL,'2025-12-14 11:20:29'),(2,4,'cash',NULL,1.000000,NULL,NULL,2780.00,NULL,'2025-12-14 11:27:29'),(3,5,'cash',NULL,1.000000,NULL,NULL,25.00,NULL,'2025-12-14 12:00:37'),(4,6,'cash',NULL,1.000000,NULL,NULL,1216.00,NULL,'2025-12-14 12:08:42'),(5,7,'cash',NULL,1.000000,NULL,NULL,305.00,NULL,'2025-12-15 06:36:12');
/*!40000 ALTER TABLE `refund_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refunds`
--

DROP TABLE IF EXISTS `refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refunds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `refund_number` varchar(50) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `refund_date` datetime NOT NULL,
  `refund_type` enum('full','partial') DEFAULT 'full',
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `base_currency_id` int(11) DEFAULT NULL,
  `base_exchange_rate` decimal(10,6) DEFAULT NULL,
  `original_currency_id` int(11) DEFAULT NULL,
  `original_total_amount` decimal(10,2) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','completed','cancelled') DEFAULT 'completed',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `refund_number` (`refund_number`),
  KEY `idx_refund_number` (`refund_number`),
  KEY `idx_sale_id` (`sale_id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_refund_date` (`refund_date`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refunds`
--

LOCK TABLES `refunds` WRITE;
/*!40000 ALTER TABLE `refunds` DISABLE KEYS */;
INSERT INTO `refunds` VALUES (1,'REF-1-2512141',14,2,1,1,NULL,'2025-12-14 11:20:29','full',1585.00,0.00,0.00,1585.00,NULL,NULL,NULL,NULL,'Customer Request','','completed','2025-12-14 11:20:29','2025-12-14 11:20:29'),(4,'REF-1-2512142',15,2,1,1,NULL,'2025-12-14 11:27:28','full',2780.00,0.00,0.00,2780.00,NULL,NULL,NULL,NULL,'Customer Request','','completed','2025-12-14 11:27:29','2025-12-14 11:27:29'),(5,'REF-1-2512143',19,2,1,1,NULL,'2025-12-14 12:00:37','full',25.00,0.00,0.00,25.00,NULL,NULL,NULL,NULL,'Customer Request','','completed','2025-12-14 12:00:37','2025-12-14 12:00:37'),(6,'REF-1-2512144',20,3,1,1,2,'2025-12-14 12:08:42','full',1280.00,64.00,0.00,1216.00,NULL,NULL,NULL,NULL,'Customer Request','','completed','2025-12-14 12:08:42','2025-12-14 12:08:42'),(7,'REF-3-2512151',51,4,3,1,NULL,'2025-12-15 06:36:12','full',305.00,0.00,0.00,305.00,NULL,NULL,NULL,NULL,'','','completed','2025-12-15 06:36:12','2025-12-15 06:36:12');
/*!40000 ALTER TABLE `refunds` ENABLE KEYS */;
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
INSERT INTO `role_permissions` VALUES (1,1,42,'2025-12-14 23:12:40'),(2,1,43,'2025-12-14 23:12:40'),(3,1,44,'2025-12-14 23:12:40'),(4,1,45,'2025-12-14 23:12:40'),(5,1,46,'2025-12-14 23:12:40'),(6,1,47,'2025-12-14 23:12:40'),(7,1,48,'2025-12-14 23:12:40'),(8,1,49,'2025-12-14 23:12:40'),(9,1,50,'2025-12-14 23:12:40'),(10,1,51,'2025-12-14 23:12:40'),(11,1,52,'2025-12-14 23:12:40'),(12,1,53,'2025-12-14 23:12:40'),(13,1,54,'2025-12-14 23:12:40'),(14,1,55,'2025-12-14 23:12:40'),(15,1,56,'2025-12-14 23:12:40'),(16,1,26,'2025-12-14 23:12:40'),(17,1,27,'2025-12-14 23:12:40'),(18,1,28,'2025-12-14 23:12:40'),(19,1,29,'2025-12-14 23:12:40'),(20,1,1,'2025-12-14 23:12:40'),(21,1,7,'2025-12-14 23:12:40'),(22,1,8,'2025-12-14 23:12:40'),(23,1,9,'2025-12-14 23:12:40'),(24,1,10,'2025-12-14 23:12:40'),(25,1,21,'2025-12-14 23:12:40'),(26,1,22,'2025-12-14 23:12:40'),(27,1,23,'2025-12-14 23:12:40'),(28,1,24,'2025-12-14 23:12:40'),(29,1,25,'2025-12-14 23:12:40'),(30,1,11,'2025-12-14 23:12:40'),(31,1,12,'2025-12-14 23:12:40'),(32,1,13,'2025-12-14 23:12:40'),(33,1,14,'2025-12-14 23:12:40'),(34,1,15,'2025-12-14 23:12:40'),(35,1,16,'2025-12-14 23:12:40'),(36,1,2,'2025-12-14 23:12:40'),(37,1,3,'2025-12-14 23:12:40'),(38,1,4,'2025-12-14 23:12:40'),(39,1,5,'2025-12-14 23:12:40'),(40,1,6,'2025-12-14 23:12:40'),(41,1,38,'2025-12-14 23:12:40'),(42,1,39,'2025-12-14 23:12:40'),(43,1,40,'2025-12-14 23:12:40'),(44,1,41,'2025-12-14 23:12:40'),(45,1,17,'2025-12-14 23:12:40'),(46,1,18,'2025-12-14 23:12:40'),(47,1,19,'2025-12-14 23:12:40'),(48,1,20,'2025-12-14 23:12:40'),(49,1,30,'2025-12-14 23:12:40'),(50,1,31,'2025-12-14 23:12:40'),(51,1,32,'2025-12-14 23:12:40'),(52,1,33,'2025-12-14 23:12:40'),(53,1,34,'2025-12-14 23:12:40'),(54,1,35,'2025-12-14 23:12:40'),(55,1,36,'2025-12-14 23:12:40'),(56,1,37,'2025-12-14 23:12:40'),(57,2,43,'2025-12-14 23:12:40'),(58,2,45,'2025-12-14 23:12:40'),(59,2,44,'2025-12-14 23:12:40'),(60,2,42,'2025-12-14 23:12:40'),(61,2,27,'2025-12-14 23:12:40'),(62,2,29,'2025-12-14 23:12:40'),(63,2,28,'2025-12-14 23:12:40'),(64,2,26,'2025-12-14 23:12:40'),(65,2,1,'2025-12-14 23:12:40'),(66,2,8,'2025-12-14 23:12:40'),(67,2,10,'2025-12-14 23:12:40'),(68,2,9,'2025-12-14 23:12:40'),(69,2,7,'2025-12-14 23:12:40'),(70,2,22,'2025-12-14 23:12:40'),(71,2,24,'2025-12-14 23:12:40'),(72,2,23,'2025-12-14 23:12:40'),(73,2,25,'2025-12-14 23:12:40'),(74,2,21,'2025-12-14 23:12:40'),(75,2,16,'2025-12-14 23:12:40'),(76,2,12,'2025-12-14 23:12:40'),(77,2,14,'2025-12-14 23:12:40'),(78,2,13,'2025-12-14 23:12:40'),(79,2,15,'2025-12-14 23:12:40'),(80,2,11,'2025-12-14 23:12:40'),(81,2,6,'2025-12-14 23:12:40'),(82,2,3,'2025-12-14 23:12:40'),(83,2,5,'2025-12-14 23:12:40'),(84,2,4,'2025-12-14 23:12:40'),(85,2,2,'2025-12-14 23:12:40'),(86,2,41,'2025-12-14 23:12:40'),(87,2,40,'2025-12-14 23:12:40'),(88,2,39,'2025-12-14 23:12:40'),(89,2,38,'2025-12-14 23:12:40'),(90,2,18,'2025-12-14 23:12:40'),(91,2,20,'2025-12-14 23:12:40'),(92,2,19,'2025-12-14 23:12:40'),(93,2,17,'2025-12-14 23:12:40'),(94,2,55,'2025-12-14 23:12:40'),(95,2,31,'2025-12-14 23:12:40'),(96,2,33,'2025-12-14 23:12:40'),(97,2,32,'2025-12-14 23:12:40'),(98,2,30,'2025-12-14 23:12:40'),(99,2,35,'2025-12-14 23:12:40'),(100,2,37,'2025-12-14 23:12:40'),(101,2,36,'2025-12-14 23:12:40'),(102,2,34,'2025-12-14 23:12:40'),(103,2,47,'2025-12-14 23:12:40'),(104,2,48,'2025-12-14 23:12:40'),(105,2,46,'2025-12-14 23:12:40'),(106,3,27,'2025-12-14 23:12:40'),(107,3,29,'2025-12-14 23:12:40'),(108,3,28,'2025-12-14 23:12:40'),(109,3,26,'2025-12-14 23:12:40'),(110,3,1,'2025-12-14 23:12:40'),(111,3,16,'2025-12-14 23:12:40'),(112,3,12,'2025-12-14 23:12:40'),(113,3,14,'2025-12-14 23:12:40'),(114,3,13,'2025-12-14 23:12:40'),(115,3,15,'2025-12-14 23:12:40'),(116,3,11,'2025-12-14 23:12:40'),(117,3,18,'2025-12-14 23:12:40'),(118,3,20,'2025-12-14 23:12:40'),(119,3,19,'2025-12-14 23:12:40'),(120,3,17,'2025-12-14 23:12:40'),(121,4,27,'2025-12-14 23:12:40'),(122,4,29,'2025-12-14 23:12:40'),(123,4,28,'2025-12-14 23:12:40'),(124,4,26,'2025-12-14 23:12:40'),(125,4,1,'2025-12-14 23:12:40'),(126,4,22,'2025-12-14 23:12:40'),(127,4,24,'2025-12-14 23:12:40'),(128,4,23,'2025-12-14 23:12:40'),(129,4,25,'2025-12-14 23:12:40'),(130,4,21,'2025-12-14 23:12:40'),(131,4,18,'2025-12-14 23:12:40'),(132,4,20,'2025-12-14 23:12:40'),(133,4,19,'2025-12-14 23:12:40'),(134,4,17,'2025-12-14 23:12:40'),(135,5,1,'2025-12-14 23:12:40'),(136,5,8,'2025-12-14 23:12:40'),(137,5,10,'2025-12-14 23:12:40'),(138,5,9,'2025-12-14 23:12:40'),(139,5,7,'2025-12-14 23:12:40'),(140,5,6,'2025-12-14 23:12:40'),(141,5,3,'2025-12-14 23:12:40'),(142,5,5,'2025-12-14 23:12:40'),(143,5,4,'2025-12-14 23:12:40'),(144,5,2,'2025-12-14 23:12:40');
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
  `permissions` text DEFAULT NULL,
  `is_system_role` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Administrator','Full system access','[\"*\"]',1,'2025-12-12 16:22:31','2025-12-12 16:22:31'),(2,'Branch Manager','Branch management access','[\"dashboard.view\",\"products.view\",\"products.create\",\"products.edit\",\"inventory.view\",\"inventory.create\",\"pos.access\",\"invoices.view\",\"invoices.create\",\"customers.view\",\"customers.create\",\"reports.view\"]',1,'2025-12-12 16:22:31','2025-12-12 16:22:31'),(3,'Cashier','POS operations','[\"pos.access\",\"customers.view\",\"invoices.view\"]',1,'2025-12-12 16:22:31','2025-12-12 16:22:31'),(4,'Stock Clerk','Inventory management','[\"inventory.view\",\"inventory.create\",\"products.view\"]',1,'2025-12-12 16:22:31','2025-12-12 16:22:31'),(5,'Accountant','Financial reports','[\"invoices.view\",\"invoices.create\",\"reports.view\",\"reports.financial\"]',1,'2025-12-12 16:22:31','2025-12-12 16:22:31');
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
) ENGINE=InnoDB AUTO_INCREMENT=84 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_items`
--

LOCK TABLES `sale_items` WRITE;
/*!40000 ALTER TABLE `sale_items` DISABLE KEYS */;
INSERT INTO `sale_items` VALUES (1,1,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-14 10:42:43'),(2,1,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 10:42:43'),(3,1,10,'Apple iPad Pro 12.9\"',1,1300.00,1300.00,'2025-12-14 10:42:43'),(4,2,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-14 10:45:02'),(5,2,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 10:45:02'),(6,2,10,'Apple iPad Pro 12.9\"',1,1300.00,1300.00,'2025-12-14 10:45:02'),(7,3,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 10:49:25'),(8,3,10,'Apple iPad Pro 12.9\"',1,1300.00,1300.00,'2025-12-14 10:49:25'),(9,4,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 10:51:08'),(10,4,10,'Apple iPad Pro 12.9\"',1,1300.00,1300.00,'2025-12-14 10:51:08'),(11,5,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 10:51:11'),(12,5,10,'Apple iPad Pro 12.9\"',1,1300.00,1300.00,'2025-12-14 10:51:11'),(13,6,10,'Apple iPad Pro 12.9\"',1,1300.00,1300.00,'2025-12-14 10:53:48'),(14,6,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 10:53:48'),(15,7,10,'Apple iPad Pro 12.9\"',1,1300.00,1300.00,'2025-12-14 10:56:49'),(16,7,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 10:56:49'),(17,8,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 11:01:14'),(18,8,10,'Apple iPad Pro 12.9\"',2,1300.00,2600.00,'2025-12-14 11:01:15'),(19,9,10,'Apple iPad Pro 12.9\"',1,1300.00,1300.00,'2025-12-14 11:03:30'),(20,10,2,'Samsung Galaxy S24 Ultra',1,1400.00,1400.00,'2025-12-14 11:08:17'),(21,10,11,'Samsung Galaxy Tab S9',1,1150.00,1150.00,'2025-12-14 11:08:17'),(22,14,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 11:15:23'),(23,14,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-14 11:15:23'),(24,14,14,'Apple AirPods Pro',2,280.00,560.00,'2025-12-14 11:15:23'),(25,15,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 11:24:46'),(26,15,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 11:24:46'),(27,15,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-14 11:24:46'),(28,16,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 11:29:53'),(29,16,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-14 11:29:53'),(30,17,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 11:31:14'),(31,17,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-14 11:31:14'),(32,17,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 11:31:14'),(33,18,20,'Apple MagSafe Charger',40,45.00,1800.00,'2025-12-14 11:47:24'),(34,19,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-14 11:48:14'),(35,20,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 12:01:07'),(36,20,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 12:01:07'),(37,22,11,'Samsung Galaxy Tab S9',2,1150.00,2300.00,'2025-12-14 12:09:25'),(38,22,2,'Samsung Galaxy S24 Ultra',1,1400.00,1400.00,'2025-12-14 12:09:25'),(39,31,21,'Trade-In: Tecno Spark',1,600.00,600.00,'2025-12-14 13:58:06'),(40,32,4,'Samsung Galaxy S24',1,1150.00,1150.00,'2025-12-14 13:58:06'),(41,33,22,'Trade-In: Sony PS5',1,550.00,550.00,'2025-12-14 14:14:53'),(42,34,18,'Sony PlayStation 5',1,650.00,650.00,'2025-12-14 14:14:53'),(43,35,23,'Trade-In: Sony PS5',1,340.00,340.00,'2025-12-14 14:19:05'),(44,36,22,'Sony PS5',1,550.00,550.00,'2025-12-14 14:19:05'),(45,37,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-14 14:34:46'),(46,37,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 14:34:46'),(47,37,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 14:34:46'),(48,38,24,'Trade-In: Tecno Spark',1,450.00,450.00,'2025-12-14 15:10:54'),(49,39,4,'Samsung Galaxy S24',1,1150.00,1150.00,'2025-12-14 15:10:54'),(50,40,24,'Tecno Spark',1,500.00,500.00,'2025-12-14 15:11:29'),(51,41,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 22:51:53'),(52,41,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-14 22:51:53'),(53,41,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 22:51:53'),(54,42,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 23:03:02'),(55,42,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-14 23:03:02'),(56,42,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 23:03:02'),(57,43,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-14 23:34:41'),(58,43,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-14 23:34:41'),(59,43,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-14 23:34:41'),(60,44,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-15 00:22:04'),(61,44,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-15 00:22:04'),(62,45,9,'Lenovo ThinkPad X1 Carbon',1,2000.00,2000.00,'2025-12-15 00:22:30'),(63,45,5,'Huawei P50 Pro',1,900.00,900.00,'2025-12-15 00:22:30'),(64,46,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 00:24:35'),(65,46,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-15 00:24:35'),(66,47,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 00:28:18'),(67,47,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-15 00:28:18'),(68,48,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 06:08:19'),(69,48,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-15 06:08:19'),(70,49,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-15 06:09:38'),(71,49,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 06:09:38'),(72,50,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 06:20:32'),(73,50,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-15 06:20:32'),(74,51,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-15 06:29:18'),(75,51,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 06:29:18'),(76,52,12,'Apple 20W USB-C Power Adapter',1,25.00,25.00,'2025-12-15 06:45:41'),(77,52,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 06:45:41'),(78,53,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-15 07:02:46'),(79,53,1,'Apple iPhone 15 Pro Max',1,1500.00,1500.00,'2025-12-15 07:02:46'),(80,54,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 07:05:43'),(81,54,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-15 07:05:43'),(82,55,14,'Apple AirPods Pro',1,280.00,280.00,'2025-12-15 07:15:46'),(83,55,3,'Apple iPhone 15',1,1000.00,1000.00,'2025-12-15 07:15:46');
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
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_payments`
--

LOCK TABLES `sale_payments` WRITE;
/*!40000 ALTER TABLE `sale_payments` DISABLE KEYS */;
INSERT INTO `sale_payments` VALUES (1,1,'cash',NULL,1.000000,NULL,NULL,2000.00,NULL,'2025-12-14 10:42:43'),(2,2,'cash',NULL,1.000000,NULL,NULL,2000.00,NULL,'2025-12-14 10:45:02'),(3,3,'cash',NULL,1.000000,NULL,NULL,2500.00,NULL,'2025-12-14 10:49:25'),(4,4,'cash',NULL,1.000000,NULL,NULL,2500.00,NULL,'2025-12-14 10:51:08'),(5,5,'cash',NULL,1.000000,NULL,NULL,2500.00,NULL,'2025-12-14 10:51:11'),(6,6,'cash',NULL,1.000000,NULL,NULL,1600.00,NULL,'2025-12-14 10:53:48'),(7,7,'cash',NULL,1.000000,NULL,NULL,2500.00,NULL,'2025-12-14 10:56:49'),(8,8,'cash',NULL,1.000000,NULL,NULL,4000.00,NULL,'2025-12-14 11:01:15'),(9,9,'cash',NULL,1.000000,NULL,NULL,1500.00,NULL,'2025-12-14 11:03:30'),(10,10,'cash',NULL,1.000000,NULL,NULL,2000.00,NULL,'2025-12-14 11:08:17'),(11,10,'cash',NULL,1.000000,NULL,NULL,600.00,NULL,'2025-12-14 11:08:17'),(12,14,'cash',NULL,1.000000,NULL,NULL,1600.00,NULL,'2025-12-14 11:15:23'),(13,15,'cash',NULL,1.000000,NULL,NULL,3000.00,NULL,'2025-12-14 11:24:46'),(14,16,'cash',NULL,1.000000,NULL,NULL,3000.00,NULL,'2025-12-14 11:29:53'),(15,17,'cash',NULL,1.000000,NULL,NULL,3000.00,NULL,'2025-12-14 11:31:14'),(16,18,'cash',NULL,1.000000,NULL,NULL,7899.00,NULL,'2025-12-14 11:47:24'),(17,18,'card',NULL,1.000000,NULL,NULL,890.00,NULL,'2025-12-14 11:47:24'),(18,19,'cash',NULL,1.000000,NULL,NULL,50.00,NULL,'2025-12-14 11:48:14'),(19,20,'cash',NULL,1.000000,NULL,NULL,1300.00,NULL,'2025-12-14 12:01:07'),(20,0,'trade_in',NULL,1.000000,NULL,NULL,450.00,NULL,'2025-12-14 12:06:20'),(21,22,'cash',NULL,1.000000,NULL,NULL,9000.00,NULL,'2025-12-14 12:09:25'),(22,31,'trade_in',NULL,1.000000,NULL,NULL,600.00,NULL,'2025-12-14 13:58:06'),(23,32,'cash',NULL,1.000000,NULL,NULL,550.00,NULL,'2025-12-14 13:58:06'),(24,33,'trade_in',NULL,1.000000,NULL,NULL,550.00,NULL,'2025-12-14 14:14:53'),(25,34,'cash',NULL,1.000000,NULL,NULL,100.00,NULL,'2025-12-14 14:14:53'),(26,35,'trade_in',NULL,1.000000,NULL,NULL,340.00,NULL,'2025-12-14 14:19:05'),(27,36,'cash',NULL,1.000000,NULL,NULL,210.00,NULL,'2025-12-14 14:19:05'),(28,37,'cash',NULL,1.000000,NULL,NULL,1310.00,NULL,'2025-12-14 14:34:46'),(29,38,'trade_in',NULL,1.000000,NULL,NULL,450.00,NULL,'2025-12-14 15:10:54'),(30,39,'cash',NULL,1.000000,NULL,NULL,700.00,NULL,'2025-12-14 15:10:54'),(31,40,'cash',NULL,1.000000,NULL,NULL,500.00,NULL,'2025-12-14 15:11:29'),(32,43,'cash',1,1.000000,123.00,123.00,123.00,NULL,'2025-12-14 23:34:41'),(33,43,'card',2,18.500000,50000.00,2702.70,2702.70,NULL,'2025-12-14 23:34:41'),(34,44,'cash',1,1.000000,123.00,123.00,123.00,NULL,'2025-12-15 00:22:04'),(35,44,'card',5,35.000000,95654.00,2732.97,2732.97,NULL,'2025-12-15 00:22:04'),(36,45,'cash',1,1.000000,3000.00,3000.00,3000.00,NULL,'2025-12-15 00:22:30'),(37,46,'cash',1,1.000000,1300.00,1300.00,1300.00,NULL,'2025-12-15 00:24:35'),(38,47,'cash',1,1.000000,1300.00,1300.00,1300.00,NULL,'2025-12-15 00:28:18'),(39,48,'cash',1,1.000000,1300.00,1300.00,1300.00,NULL,'2025-12-15 06:08:19'),(40,49,'ecocash',1,1.000000,1300.00,1300.00,1300.00,NULL,'2025-12-15 06:09:38'),(41,50,'cash',1,1.000000,500.00,500.00,500.00,NULL,'2025-12-15 06:20:32'),(42,51,'cash',1,1.000000,400.00,400.00,400.00,NULL,'2025-12-15 06:29:18'),(43,52,'cash',1,1.000000,500.00,500.00,500.00,NULL,'2025-12-15 06:45:41'),(44,53,'cash',1,1.000000,2500.00,2500.00,2500.00,NULL,'2025-12-15 07:02:46'),(45,54,'cash',1,1.000000,1300.00,1300.00,1300.00,NULL,'2025-12-15 07:05:43'),(46,55,'cash',1,1.000000,1300.00,1300.00,1300.00,NULL,'2025-12-15 07:15:46');
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
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
INSERT INTO `sales` VALUES (1,'1-2512141',2,1,1,NULL,'2025-12-14 10:42:43',1605.00,NULL,0.00,0.00,1605.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 10:42:43','2025-12-14 10:42:43'),(2,'1-2512142',2,1,1,NULL,'2025-12-14 10:45:02',1605.00,NULL,0.00,0.00,1605.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 10:45:02','2025-12-14 10:45:02'),(3,'1-2512143',2,1,1,NULL,'2025-12-14 10:49:25',2300.00,NULL,0.00,0.00,2300.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 10:49:25','2025-12-14 10:49:25'),(4,'1-2512144',2,1,1,NULL,'2025-12-14 10:51:08',2300.00,NULL,0.00,0.00,2300.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 10:51:08','2025-12-14 10:51:08'),(5,'1-2512145',2,1,1,NULL,'2025-12-14 10:51:11',2300.00,NULL,0.00,0.00,2300.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 10:51:11','2025-12-14 10:51:11'),(6,'1-2512146',2,1,1,NULL,'2025-12-14 10:53:48',1580.00,NULL,0.00,0.00,1580.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 10:53:48','2025-12-14 10:53:48'),(7,'1-2512147',2,1,1,NULL,'2025-12-14 10:56:49',2300.00,NULL,0.00,0.00,2300.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 10:56:49','2025-12-14 10:56:49'),(8,'1-2512148',2,1,1,NULL,'2025-12-14 11:01:14',3600.00,NULL,0.00,0.00,3600.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 11:01:14','2025-12-14 11:01:14'),(9,'1-2512149',2,1,1,NULL,'2025-12-14 11:03:30',1300.00,NULL,0.00,0.00,1300.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 11:03:30','2025-12-14 11:03:30'),(10,'1-25121410',2,1,1,NULL,'2025-12-14 11:08:17',2550.00,NULL,0.00,0.00,2550.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 11:08:17','2025-12-14 11:08:17'),(14,'1-25121411',2,1,1,NULL,'2025-12-14 11:15:23',1585.00,NULL,0.00,0.00,1585.00,NULL,NULL,NULL,NULL,'refunded',NULL,'2025-12-14 11:15:23','2025-12-14 11:20:29'),(15,'1-25121412',2,1,1,NULL,'2025-12-14 11:24:46',2780.00,NULL,0.00,0.00,2780.00,NULL,NULL,NULL,NULL,'refunded',NULL,'2025-12-14 11:24:46','2025-12-14 11:27:29'),(16,'1-25121413',2,1,1,NULL,'2025-12-14 11:29:53',2500.00,NULL,0.00,0.00,2500.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 11:29:53','2025-12-14 11:29:53'),(17,'1-25121414',2,1,1,NULL,'2025-12-14 11:31:14',2780.00,NULL,0.00,0.00,2780.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 11:31:14','2025-12-14 11:31:14'),(18,'1-25121415',2,1,1,NULL,'2025-12-14 11:47:24',1800.00,NULL,0.00,0.00,1800.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 11:47:24','2025-12-14 11:47:24'),(19,'1-25121416',2,1,1,NULL,'2025-12-14 11:48:14',25.00,NULL,0.00,0.00,25.00,NULL,NULL,NULL,NULL,'refunded',NULL,'2025-12-14 11:48:14','2025-12-14 12:00:37'),(20,'1-25121417',3,1,1,2,'2025-12-14 12:01:06',1280.00,'percentage',64.00,0.00,1216.00,NULL,NULL,NULL,NULL,'refunded',NULL,'2025-12-14 12:01:06','2025-12-14 12:08:42'),(22,'1-25121418',3,1,1,NULL,'2025-12-14 12:09:25',3700.00,NULL,0.00,0.00,3700.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 12:09:25','2025-12-14 12:09:25'),(31,'1-25121419',3,1,1,NULL,'2025-12-14 13:58:06',600.00,NULL,0.00,0.00,600.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 13:58:06','2025-12-14 13:58:06'),(32,'1-25121420',3,1,1,NULL,'2025-12-14 13:58:06',1150.00,'value',600.00,0.00,550.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 13:58:06','2025-12-14 13:58:06'),(33,'1-25121421',3,1,1,9,'2025-12-14 14:14:53',550.00,NULL,0.00,0.00,550.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 14:14:53','2025-12-14 14:14:53'),(34,'1-25121422',3,1,1,9,'2025-12-14 14:14:53',650.00,'value',550.00,0.00,100.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 14:14:53','2025-12-14 14:14:53'),(35,'1-25121423',3,1,1,11,'2025-12-14 14:19:05',340.00,NULL,0.00,0.00,340.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 14:19:05','2025-12-14 14:19:05'),(36,'1-25121424',3,1,1,11,'2025-12-14 14:19:05',550.00,'value',340.00,0.00,210.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 14:19:05','2025-12-14 14:19:05'),(37,'1-25121425',3,1,1,NULL,'2025-12-14 14:34:45',1305.00,NULL,0.00,0.00,1305.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 14:34:45','2025-12-14 14:34:45'),(38,'1-25121426',3,1,1,2,'2025-12-14 15:10:54',450.00,NULL,0.00,0.00,450.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 15:10:54','2025-12-14 15:10:54'),(39,'1-25121427',3,1,1,2,'2025-12-14 15:10:54',1150.00,'value',450.00,0.00,700.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 15:10:54','2025-12-14 15:10:54'),(40,'1-25121428',3,1,1,NULL,'2025-12-14 15:11:29',500.00,NULL,0.00,0.00,500.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 15:11:29','2025-12-14 15:11:29'),(41,'1-25121429',3,1,1,NULL,'2025-12-14 22:51:53',2780.00,NULL,0.00,0.00,2780.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 22:51:53','2025-12-14 22:51:53'),(42,'1-251214822363',3,1,1,NULL,'2025-12-14 23:03:02',2780.00,NULL,0.00,0.00,2780.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 23:03:02','2025-12-14 23:03:02'),(43,'1-251214814567',3,1,1,NULL,'2025-12-14 23:34:41',2780.00,NULL,0.00,0.00,2780.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-14 23:34:41','2025-12-14 23:34:41'),(44,'3-2512151',4,3,1,NULL,'2025-12-15 00:22:04',2500.00,NULL,0.00,0.00,2500.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 00:22:04','2025-12-15 00:22:04'),(45,'3-2512152',4,3,1,NULL,'2025-12-15 00:22:30',2900.00,NULL,0.00,0.00,2900.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 00:22:30','2025-12-15 00:22:30'),(46,'3-2512153',4,3,1,NULL,'2025-12-15 00:24:35',1280.00,NULL,0.00,0.00,1280.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 00:24:35','2025-12-15 00:24:35'),(47,'3-2512154',4,3,1,NULL,'2025-12-15 00:28:18',1280.00,NULL,0.00,0.00,1280.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 00:28:18','2025-12-15 00:28:18'),(48,'1-2512151',3,1,1,NULL,'2025-12-15 06:08:18',1280.00,NULL,0.00,0.00,1280.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 06:08:18','2025-12-15 06:08:18'),(49,'1-2512152',3,1,1,NULL,'2025-12-15 06:09:38',1280.00,NULL,0.00,0.00,1280.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 06:09:38','2025-12-15 06:09:38'),(50,'1-2512153',3,1,1,NULL,'2025-12-15 06:20:32',305.00,NULL,0.00,0.00,305.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 06:20:32','2025-12-15 06:20:32'),(51,'3-2512155',4,3,1,NULL,'2025-12-15 06:29:18',305.00,NULL,0.00,0.00,305.00,NULL,NULL,NULL,NULL,'refunded',NULL,'2025-12-15 06:29:18','2025-12-15 06:36:12'),(52,'1-2512154',3,1,1,NULL,'2025-12-15 06:45:41',305.00,NULL,0.00,0.00,305.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 06:45:41','2025-12-15 06:45:41'),(53,'3-2512156',4,3,1,NULL,'2025-12-15 07:02:46',2500.00,NULL,0.00,0.00,2500.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 07:02:46','2025-12-15 07:02:46'),(54,'3-2512157',4,3,1,NULL,'2025-12-15 07:05:43',1280.00,NULL,0.00,0.00,1280.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 07:05:43','2025-12-15 07:05:43'),(55,'1-2512155',3,1,1,NULL,'2025-12-15 07:15:46',1280.00,NULL,0.00,0.00,1280.00,NULL,NULL,NULL,NULL,'paid',NULL,'2025-12-15 07:15:46','2025-12-15 07:15:46');
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
  `branch_id` int(11) DEFAULT 0,
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shifts`
--

LOCK TABLES `shifts` WRITE;
/*!40000 ALTER TABLE `shifts` DISABLE KEYS */;
INSERT INTO `shifts` VALUES (1,1,1,1,'2025-12-14 02:58:25','2025-12-14 10:37:31',1,1,45.00,21767.00,45.00,-21722.00,NULL,'closed',NULL,'2025-12-14 02:58:25','2025-12-14 10:37:31'),(2,2,1,1,'2025-12-14 10:42:26','2025-12-14 11:49:13',1,1,34.00,37893.00,42283.00,4365.00,NULL,'closed',NULL,'2025-12-14 10:42:26','2025-12-14 12:00:37'),(3,3,1,1,'2025-12-14 11:49:48',NULL,1,NULL,12.00,13299.00,NULL,0.00,NULL,'open',NULL,'2025-12-14 11:49:48','2025-12-15 07:15:46'),(4,1,3,1,'2025-12-15 00:21:33',NULL,1,NULL,12.00,8288.00,NULL,0.00,NULL,'open',NULL,'2025-12-15 00:21:33','2025-12-15 07:05:43');
/*!40000 ALTER TABLE `shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `movement_type` enum('Purchase','Sale','Transfer','Adjustment','Damage','Return','Trade-In') DEFAULT 'Adjustment',
  `quantity` int(11) DEFAULT 0,
  `previous_quantity` int(11) DEFAULT 0,
  `new_quantity` int(11) DEFAULT 0,
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=125 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
INSERT INTO `stock_movements` VALUES (1,1,NULL,1,'Purchase',20,0,20,NULL,NULL,1,NULL,'2025-11-12 18:08:06'),(2,2,NULL,1,'Purchase',15,0,15,NULL,NULL,1,NULL,'2025-11-14 18:08:06'),(3,3,NULL,1,'Purchase',25,0,25,NULL,NULL,1,NULL,'2025-11-17 18:08:06'),(4,4,NULL,1,'Purchase',20,0,20,NULL,NULL,1,NULL,'2025-11-20 18:08:06'),(5,5,NULL,2,'Purchase',30,0,30,NULL,NULL,2,NULL,'2025-11-22 18:08:06'),(6,6,NULL,1,'Purchase',10,0,10,NULL,NULL,1,NULL,'2025-11-24 18:08:06'),(7,7,NULL,1,'Purchase',8,0,8,NULL,NULL,1,NULL,'2025-11-27 18:08:06'),(8,8,NULL,2,'Purchase',12,0,12,NULL,NULL,2,NULL,'2025-11-30 18:08:06'),(9,9,NULL,1,'Purchase',10,0,10,NULL,NULL,1,NULL,'2025-12-02 18:08:06'),(10,10,NULL,1,'Purchase',15,0,15,NULL,NULL,1,NULL,'2025-12-04 18:08:06'),(11,1,NULL,1,'Sale',-5,20,15,NULL,NULL,1,NULL,'2025-12-11 18:08:06'),(12,2,NULL,1,'Sale',-3,15,12,NULL,NULL,1,NULL,'2025-12-10 18:08:06'),(13,3,NULL,1,'Sale',-5,25,20,NULL,NULL,1,NULL,'2025-12-08 18:08:06'),(14,4,NULL,1,'Sale',-2,20,18,NULL,NULL,1,NULL,'2025-12-09 18:08:06'),(15,5,NULL,2,'Sale',-5,30,25,NULL,NULL,2,NULL,'2025-12-08 18:08:06'),(16,6,NULL,1,'Sale',-2,10,8,NULL,NULL,1,NULL,'2025-12-10 18:08:06'),(17,7,NULL,1,'Sale',-2,8,6,NULL,NULL,1,NULL,'2025-12-07 18:08:06'),(18,8,NULL,2,'Sale',-2,12,10,NULL,NULL,2,NULL,'2025-12-03 18:08:06'),(19,9,NULL,1,'Sale',-1,10,9,NULL,NULL,1,NULL,'2025-11-30 18:08:06'),(20,10,NULL,1,'Sale',-3,15,12,NULL,NULL,1,NULL,'2025-12-06 18:08:06'),(21,14,NULL,1,'Sale',-1,30,29,NULL,NULL,1,NULL,'2025-12-14 02:59:18'),(22,10,NULL,1,'Sale',-1,12,11,NULL,NULL,1,NULL,'2025-12-14 02:59:18'),(23,8,NULL,1,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-14 03:01:49'),(24,10,NULL,1,'Sale',-1,11,10,NULL,NULL,1,NULL,'2025-12-14 03:02:55'),(25,2,NULL,1,'Sale',-1,12,11,NULL,NULL,1,NULL,'2025-12-14 10:20:56'),(26,4,NULL,1,'Sale',-1,18,17,NULL,NULL,1,NULL,'2025-12-14 10:20:56'),(27,12,NULL,1,'Sale',-1,50,49,NULL,NULL,1,NULL,'2025-12-14 10:42:43'),(28,14,NULL,1,'Sale',-1,29,28,NULL,NULL,1,NULL,'2025-12-14 10:42:43'),(29,10,NULL,1,'Sale',-1,10,9,NULL,NULL,1,NULL,'2025-12-14 10:42:43'),(30,12,NULL,1,'Sale',-1,49,48,NULL,NULL,1,NULL,'2025-12-14 10:45:02'),(31,14,NULL,1,'Sale',-1,28,27,NULL,NULL,1,NULL,'2025-12-14 10:45:02'),(32,10,NULL,1,'Sale',-1,9,8,NULL,NULL,1,NULL,'2025-12-14 10:45:02'),(33,3,NULL,1,'Sale',-1,20,19,NULL,NULL,1,NULL,'2025-12-14 10:49:25'),(34,10,NULL,1,'Sale',-1,8,7,NULL,NULL,1,NULL,'2025-12-14 10:49:25'),(35,3,NULL,1,'Sale',-1,19,18,NULL,NULL,1,NULL,'2025-12-14 10:51:08'),(36,10,NULL,1,'Sale',-1,7,6,NULL,NULL,1,NULL,'2025-12-14 10:51:08'),(37,3,NULL,1,'Sale',-1,18,17,NULL,NULL,1,NULL,'2025-12-14 10:51:11'),(38,10,NULL,1,'Sale',-1,6,5,NULL,NULL,1,NULL,'2025-12-14 10:51:11'),(39,10,NULL,1,'Sale',-1,5,4,NULL,NULL,1,NULL,'2025-12-14 10:53:48'),(40,14,NULL,1,'Sale',-1,27,26,NULL,NULL,1,NULL,'2025-12-14 10:53:48'),(41,10,NULL,1,'Sale',-1,4,3,NULL,NULL,1,NULL,'2025-12-14 10:56:49'),(42,3,NULL,1,'Sale',-1,17,16,NULL,NULL,1,NULL,'2025-12-14 10:56:49'),(43,3,NULL,1,'Sale',-1,16,15,NULL,NULL,1,NULL,'2025-12-14 11:01:15'),(44,10,NULL,1,'Sale',-2,3,1,NULL,NULL,1,NULL,'2025-12-14 11:01:15'),(45,10,NULL,1,'Sale',-1,1,0,NULL,NULL,1,NULL,'2025-12-14 11:03:30'),(46,2,NULL,1,'Sale',-1,11,10,NULL,NULL,1,NULL,'2025-12-14 11:08:17'),(47,11,NULL,1,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-14 11:08:17'),(48,3,NULL,1,'Sale',-1,15,14,NULL,NULL,1,NULL,'2025-12-14 11:15:23'),(49,12,NULL,1,'Sale',-1,48,47,NULL,NULL,1,NULL,'2025-12-14 11:15:23'),(50,14,NULL,1,'Sale',-2,26,24,NULL,NULL,1,NULL,'2025-12-14 11:15:23'),(51,3,NULL,1,'Return',1,14,15,NULL,NULL,1,NULL,'2025-12-14 11:20:29'),(52,12,NULL,1,'Return',1,47,48,NULL,NULL,1,NULL,'2025-12-14 11:20:29'),(53,14,NULL,1,'Return',2,24,26,NULL,NULL,1,NULL,'2025-12-14 11:20:29'),(54,14,NULL,1,'Sale',-1,26,25,NULL,NULL,1,NULL,'2025-12-14 11:24:46'),(55,3,NULL,1,'Sale',-1,15,14,NULL,NULL,1,NULL,'2025-12-14 11:24:46'),(56,1,NULL,1,'Sale',-1,15,14,NULL,NULL,1,NULL,'2025-12-14 11:24:46'),(57,14,NULL,1,'Return',1,25,26,NULL,NULL,1,NULL,'2025-12-14 11:27:29'),(58,3,NULL,1,'Return',1,14,15,NULL,NULL,1,NULL,'2025-12-14 11:27:29'),(59,1,NULL,1,'Return',1,14,15,NULL,NULL,1,NULL,'2025-12-14 11:27:29'),(60,3,NULL,1,'Sale',-1,15,14,NULL,NULL,1,NULL,'2025-12-14 11:29:53'),(61,1,NULL,1,'Sale',-1,15,14,NULL,NULL,1,NULL,'2025-12-14 11:29:53'),(62,14,NULL,1,'Sale',-1,26,25,NULL,NULL,1,NULL,'2025-12-14 11:31:14'),(63,1,NULL,1,'Sale',-1,14,13,NULL,NULL,1,NULL,'2025-12-14 11:31:14'),(64,3,NULL,1,'Sale',-1,14,13,NULL,NULL,1,NULL,'2025-12-14 11:31:14'),(65,20,NULL,1,'Sale',-40,40,0,NULL,NULL,1,NULL,'2025-12-14 11:47:24'),(66,12,NULL,1,'Sale',-1,48,47,NULL,NULL,1,NULL,'2025-12-14 11:48:14'),(67,12,NULL,1,'Return',1,47,48,NULL,NULL,1,NULL,'2025-12-14 12:00:37'),(68,14,NULL,1,'Sale',-1,25,24,NULL,NULL,1,NULL,'2025-12-14 12:01:07'),(69,3,NULL,1,'Sale',-1,13,12,NULL,NULL,1,NULL,'2025-12-14 12:01:07'),(70,14,NULL,1,'Return',1,24,25,NULL,NULL,1,NULL,'2025-12-14 12:08:42'),(71,3,NULL,1,'Return',1,12,13,NULL,NULL,1,NULL,'2025-12-14 12:08:42'),(72,11,NULL,1,'Sale',-2,0,-2,NULL,NULL,1,NULL,'2025-12-14 12:09:25'),(73,2,NULL,1,'Sale',-1,10,9,NULL,NULL,1,NULL,'2025-12-14 12:09:25'),(74,21,NULL,1,'Trade-In',1,0,1,NULL,NULL,1,'Trade-In Device','2025-12-14 13:58:06'),(75,4,NULL,1,'Sale',-1,17,16,NULL,NULL,1,'Trade-In Sale','2025-12-14 13:58:06'),(76,22,NULL,1,'Trade-In',1,0,1,NULL,NULL,1,'Trade-In Device','2025-12-14 14:14:53'),(77,18,NULL,1,'Sale',-1,15,14,NULL,NULL,1,'Trade-In Sale','2025-12-14 14:14:53'),(78,23,NULL,1,'Trade-In',1,0,1,NULL,NULL,1,'Trade-In Device','2025-12-14 14:19:05'),(79,22,NULL,1,'Sale',-1,1,0,NULL,NULL,1,'Trade-In Sale','2025-12-14 14:19:05'),(80,12,NULL,1,'Sale',-1,48,47,NULL,NULL,1,NULL,'2025-12-14 14:34:46'),(81,14,NULL,1,'Sale',-1,25,24,NULL,NULL,1,NULL,'2025-12-14 14:34:46'),(82,3,NULL,1,'Sale',-1,13,12,NULL,NULL,1,NULL,'2025-12-14 14:34:46'),(83,24,NULL,1,'Trade-In',1,0,1,NULL,NULL,1,'Trade-In Device','2025-12-14 15:10:54'),(84,4,NULL,1,'Sale',-1,16,15,NULL,NULL,1,'Trade-In Sale','2025-12-14 15:10:54'),(85,24,NULL,1,'Sale',-1,1,0,NULL,NULL,1,NULL,'2025-12-14 15:11:29'),(86,20,NULL,1,'Purchase',56,0,56,NULL,NULL,1,NULL,'2025-12-14 20:58:14'),(87,13,NULL,1,'Transfer',-1,60,59,1,'Transfer',1,'Transfer Out: TRF-20251214-363','2025-12-14 21:02:03'),(88,20,NULL,1,'Purchase',56,56,112,NULL,NULL,1,NULL,'2025-12-14 21:49:22'),(89,13,NULL,1,'Transfer',-1,59,58,1,'Transfer',1,'Transfer Out: TRF-20251214-363','2025-12-14 21:49:34'),(90,3,NULL,1,'Sale',-1,12,11,NULL,NULL,1,NULL,'2025-12-14 22:51:53'),(91,1,NULL,1,'Sale',-1,13,12,NULL,NULL,1,NULL,'2025-12-14 22:51:53'),(92,14,NULL,1,'Sale',-1,24,23,NULL,NULL,1,NULL,'2025-12-14 22:51:53'),(93,3,NULL,1,'Sale',-1,11,10,NULL,NULL,1,NULL,'2025-12-14 23:03:02'),(94,1,NULL,1,'Sale',-1,12,11,NULL,NULL,1,NULL,'2025-12-14 23:03:02'),(95,14,NULL,1,'Sale',-1,23,22,NULL,NULL,1,NULL,'2025-12-14 23:03:02'),(96,3,NULL,1,'Sale',-1,10,9,NULL,NULL,1,NULL,'2025-12-14 23:34:41'),(97,1,NULL,1,'Sale',-1,11,10,NULL,NULL,1,NULL,'2025-12-14 23:34:41'),(98,14,NULL,1,'Sale',-1,22,21,NULL,NULL,1,NULL,'2025-12-14 23:34:41'),(99,3,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 00:22:04'),(100,1,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 00:22:04'),(101,9,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 00:22:30'),(102,5,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 00:22:30'),(103,14,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 00:24:35'),(104,3,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 00:24:35'),(105,14,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 00:28:18'),(106,3,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 00:28:18'),(107,14,NULL,1,'Sale',-1,21,20,NULL,NULL,1,NULL,'2025-12-15 06:08:19'),(108,3,NULL,1,'Sale',-1,9,8,NULL,NULL,1,NULL,'2025-12-15 06:08:19'),(109,3,NULL,1,'Sale',-1,8,7,NULL,NULL,1,NULL,'2025-12-15 06:09:38'),(110,14,NULL,1,'Sale',-1,20,19,NULL,NULL,1,NULL,'2025-12-15 06:09:38'),(111,14,NULL,1,'Sale',-1,19,18,NULL,NULL,1,NULL,'2025-12-15 06:20:32'),(112,12,NULL,1,'Sale',-1,47,46,NULL,NULL,1,NULL,'2025-12-15 06:20:32'),(113,12,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 06:29:18'),(114,14,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 06:29:18'),(115,12,NULL,3,'Return',1,0,1,NULL,NULL,1,NULL,'2025-12-15 06:36:12'),(116,14,NULL,3,'Return',1,0,1,NULL,NULL,1,NULL,'2025-12-15 06:36:12'),(117,12,NULL,1,'Sale',-1,46,45,NULL,NULL,1,NULL,'2025-12-15 06:45:41'),(118,14,NULL,1,'Sale',-1,18,17,NULL,NULL,1,NULL,'2025-12-15 06:45:41'),(119,3,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 07:02:46'),(120,1,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 07:02:46'),(121,14,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 07:05:43'),(122,3,NULL,3,'Sale',-1,0,-1,NULL,NULL,1,NULL,'2025-12-15 07:05:43'),(123,14,NULL,1,'Sale',-1,17,16,NULL,NULL,1,NULL,'2025-12-15 07:15:46'),(124,3,NULL,1,'Sale',-1,7,6,NULL,NULL,1,NULL,'2025-12-15 07:15:46');
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_transfers`
--

DROP TABLE IF EXISTS `stock_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_number` varchar(50) NOT NULL,
  `from_branch_id` int(11) NOT NULL,
  `to_branch_id` int(11) NOT NULL,
  `transfer_date` date DEFAULT NULL,
  `initiated_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `status` enum('Pending','Approved','InTransit','Received','Rejected') DEFAULT 'Pending',
  `total_items` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_number` (`transfer_number`),
  KEY `idx_transfer_number` (`transfer_number`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_transfers`
--

LOCK TABLES `stock_transfers` WRITE;
/*!40000 ALTER TABLE `stock_transfers` DISABLE KEYS */;
INSERT INTO `stock_transfers` VALUES (1,'TRF-20251214-363',1,3,'2025-12-14',1,1,NULL,'Approved',1,'','2025-12-14 21:02:03','2025-12-14 21:49:34');
/*!40000 ALTER TABLE `stock_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `payment_terms` varchar(100) DEFAULT NULL,
  `credit_limit` decimal(10,2) DEFAULT 0.00,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `rating` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'SUP001','Tech Distributors Zimbabwe','John Moyo','+263 772 123456','info@techdist.co.zw','123 Industrial Road, Harare',NULL,NULL,0.00,'Active',5,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(2,'SUP002','Mobile World Suppliers','Sarah Chidza','+263 773 234567','sales@mobileworld.co.zw','456 Enterprise Street, Harare',NULL,NULL,0.00,'Active',4,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(3,'SUP003','Gadget Importers Ltd','David Mupfumi','+263 774 345678','contact@gadgetimports.co.zw','789 Import Avenue, Harare',NULL,NULL,0.00,'Active',5,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(4,'SUP004','Electronics Hub','Linda Nkomo','+263 775 456789','info@electronicshub.co.zw','321 Tech Park, Harare',NULL,NULL,0.00,'Active',4,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(5,'SUP005','Smart Device Solutions','Peter Dube','+263 776 567890','sales@smartdevices.co.zw','654 Innovation Drive, Harare',NULL,NULL,0.00,'Active',5,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(6,'SUP006','Global Tech Suppliers','Mary Sibanda','+263 777 678901','contact@globaltech.co.zw','987 Global Plaza, Harare',NULL,NULL,0.00,'Active',4,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(7,'SUP007','Premium Electronics','James Ndlovu','+263 778 789012','info@premiumelec.co.zw','147 Premium Road, Harare',NULL,NULL,0.00,'Active',5,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(8,'SUP008','Digital Solutions Co','Grace Moyo','+263 779 890123','sales@digitalsolutions.co.zw','258 Digital Street, Harare',NULL,NULL,0.00,'Active',4,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(9,'SUP009','Tech Wholesale Ltd','Michael Chidza','+263 771 901234','contact@techwholesale.co.zw','369 Wholesale Avenue, Harare',NULL,NULL,0.00,'Active',5,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(10,'SUP010','Modern Electronics','Patience Mupfumi','+263 772 012345','info@modernelec.co.zw','741 Modern Road, Harare',NULL,NULL,0.00,'Active',4,'2025-12-12 18:08:06','2025-12-12 18:08:06'),(11,'','hi','hi','0782794721','nyazengamd@gmail.com','35','345','435',12.00,'Active',0,'2025-12-15 00:26:07','2025-12-15 00:26:07');
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
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
  `created_at` datetime DEFAULT current_timestamp(),
  `description` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'company_name','ELECTROX','string','General','2025-12-14 23:15:57',NULL,'2025-12-14 20:44:58',1),(2,'company_address','17 Phillips Avenue, Belgravia, Harare, Zimbabwe','string','General','2025-12-14 23:15:57',NULL,'2025-12-14 20:44:58',1),(3,'company_phone','+263 789 728 642','string','General','2025-12-14 23:15:57',NULL,'2025-12-14 20:44:58',1),(4,'company_email','info@electrox.co.zw','string','General','2025-12-14 23:15:57',NULL,'2025-12-14 20:44:58',1),(5,'default_currency','USD','string','Financial','2025-12-14 23:15:57',NULL,'2025-12-12 16:22:31',NULL),(6,'vat_rate','14.5','string','Financial','2025-12-14 23:15:57',NULL,'2025-12-12 16:22:31',NULL),(7,'session_timeout','1800','integer','Security','2025-12-14 23:15:57',NULL,'2025-12-12 16:22:31',NULL),(8,'pos_home_layout','grid','string','POS','2025-12-14 23:15:57',NULL,'2025-12-15 06:58:29',1),(9,'pos_cart_layout','increase_qty','string','POS','2025-12-14 23:15:57',NULL,'2025-12-13 11:24:30',NULL),(10,'pos_language','english','string','POS','2025-12-14 23:15:57',NULL,'2025-12-13 11:24:30',NULL),(11,'pos_transaction_days','30','string','POS','2025-12-14 23:15:57',NULL,'2025-12-13 11:24:30',NULL),(12,'pos_receipt_summary','0','string','POS','2025-12-14 23:15:57',NULL,'2025-12-15 06:58:29',1),(13,'pos_printer_setup','','string','POS','2025-12-14 23:15:57',NULL,'2025-12-13 11:24:30',NULL),(14,'pos_dual_display','0','string','POS','2025-12-14 23:15:57',NULL,'2025-12-15 06:58:29',1),(15,'invoice_template','modern','string','General','2025-12-14 23:16:17',NULL,'2025-12-14 23:16:17',NULL),(16,'invoice_primary_color','#1e3a8a','string','General','2025-12-14 23:16:17',NULL,'2025-12-14 23:16:17',NULL),(17,'invoice_show_logo','1','string','General','2025-12-14 23:16:17',NULL,'2025-12-14 23:16:17',NULL),(18,'invoice_header_text','','string','General','2025-12-14 23:16:17',NULL,'2025-12-14 23:16:17',NULL),(19,'invoice_footer_text','Thank you for your business!','string','General','2025-12-14 23:16:17',NULL,'2025-12-14 23:16:17',NULL),(20,'invoice_show_tax_id','1','string','General','2025-12-14 23:16:17',NULL,'2025-12-14 23:16:17',NULL),(21,'invoice_default_terms','','string','General','2025-12-14 23:16:17',NULL,'2025-12-14 23:16:17',NULL),(22,'invoice_logo','assets/images/invoice_logo_1765746977.png','string','General','2025-12-14 23:16:17',NULL,'2025-12-14 23:16:17',NULL),(23,'pos_auto_print','0','string','General','2025-12-15 00:23:26',NULL,'2025-12-15 06:58:29',1),(24,'pos_receipt_logo','/assets/uploads/receipts/receipt_logo_1765751058.png','string','General','2025-12-15 00:24:18',NULL,'2025-12-15 00:24:18',NULL),(25,'pos_receipt_header','Thank you for shopping with us!','string','General','2025-12-15 00:24:18',NULL,'2025-12-15 00:24:18',NULL),(26,'pos_receipt_footer','Visit us again!','string','General','2025-12-15 00:24:18',NULL,'2025-12-15 00:24:18',NULL),(27,'pos_default_tax_rate','15','string','General','2025-12-15 00:24:18',NULL,'2025-12-15 00:24:18',NULL);
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trade_ins`
--

DROP TABLE IF EXISTS `trade_ins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trade_ins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trade_in_number` varchar(50) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `assessed_by` int(11) DEFAULT NULL,
  `device_category` varchar(100) DEFAULT NULL,
  `device_brand` varchar(100) DEFAULT NULL,
  `device_model` varchar(100) DEFAULT NULL,
  `device_color` varchar(50) DEFAULT NULL,
  `device_storage` varchar(50) DEFAULT NULL,
  `device_condition` enum('A+','A','B','C') DEFAULT 'B',
  `battery_health` int(11) DEFAULT NULL,
  `cosmetic_issues` text DEFAULT NULL,
  `functional_issues` text DEFAULT NULL,
  `accessories_included` text DEFAULT NULL,
  `date_of_first_use` date DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `photos` text DEFAULT NULL,
  `ai_valuation` decimal(10,2) DEFAULT NULL,
  `ai_confidence_score` decimal(5,2) DEFAULT NULL,
  `manual_valuation` decimal(10,2) DEFAULT NULL,
  `final_valuation` decimal(10,2) DEFAULT NULL,
  `new_product_id` int(11) DEFAULT NULL,
  `valuation_notes` text DEFAULT NULL,
  `status` enum('Assessed','Accepted','Rejected','Processed') DEFAULT 'Assessed',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `trade_in_number` (`trade_in_number`),
  KEY `idx_trade_in_number` (`trade_in_number`),
  KEY `idx_new_product_id` (`new_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trade_ins`
--

LOCK TABLES `trade_ins` WRITE;
/*!40000 ALTER TABLE `trade_ins` DISABLE KEYS */;
INSERT INTO `trade_ins` VALUES (1,'TI-20241212-0001',1,1,1,'Smartphones','Apple','iPhone 12','Blue','128GB','A',85,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,400.00,400.00,12,NULL,'Accepted','2025-12-07 18:08:06','2025-12-13 10:03:11'),(2,'TI-20241212-0002',2,1,1,'Smartphones','Samsung','Galaxy S21','Phantom Black','256GB','A+',92,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,350.00,350.00,13,NULL,'Accepted','2025-12-08 18:08:06','2025-12-13 10:03:11'),(3,'TI-20241212-0003',3,2,2,'Laptops','Dell','XPS 13','Silver','512GB','B',78,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,600.00,600.00,6,NULL,'Assessed','2025-12-09 18:08:06','2025-12-13 10:03:11'),(4,'TI-20241212-0004',4,1,1,'Smartphones','Apple','iPhone 11','Black','64GB','B',75,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,250.00,250.00,12,NULL,'Accepted','2025-12-10 18:08:06','2025-12-13 10:03:11'),(5,'TI-20241212-0005',5,1,1,'Tablets','Apple','iPad Air','Space Gray','256GB','A',88,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,450.00,450.00,12,NULL,'Processed','2025-12-06 18:08:06','2025-12-13 10:03:11'),(6,'TI-20241212-0006',6,2,2,'Smartphones','Huawei','P40 Pro','Silver Frost','256GB','A',80,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,300.00,300.00,5,NULL,'Accepted','2025-12-05 18:08:06','2025-12-13 10:03:11'),(7,'TI-20241212-0007',7,1,1,'Smartphones','Samsung','Galaxy Note 20','Mystic Bronze','256GB','B',70,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,280.00,280.00,13,NULL,'Assessed','2025-12-11 18:08:06','2025-12-13 10:03:11'),(8,'TI-20241212-0008',8,1,1,'Laptops','HP','Pavilion 15','Black','1TB','C',65,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,400.00,400.00,8,NULL,'Rejected','2025-12-04 18:08:06','2025-12-13 10:03:11'),(9,'TI-20241212-0009',9,2,2,'Smartphones','Apple','iPhone X','Space Gray','256GB','B',72,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,200.00,200.00,12,NULL,'Accepted','2025-12-03 18:08:06','2025-12-13 10:03:11'),(10,'TI-20241212-0010',10,1,1,'Tablets','Samsung','Galaxy Tab S7','Mystic Black','128GB','A',85,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,350.00,350.00,13,NULL,'Processed','2025-12-02 18:08:06','2025-12-13 10:03:11'),(11,'TI-20251213-11',NULL,1,1,NULL,'SAMSUNG','452',NULL,NULL,'B',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,234.00,12,NULL,'Processed','2025-12-13 11:31:02','2025-12-13 11:31:02'),(12,'TI-20251214--10',NULL,1,1,NULL,'Samsung ','Galaxy A23',NULL,NULL,'B',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,800.00,8,NULL,'Processed','2025-12-14 03:01:49','2025-12-14 03:01:49'),(13,'TI-20251214--9',NULL,1,1,NULL,'Samsung ','Galaxy A23',NULL,NULL,'A+',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,450.00,0,NULL,'Processed','2025-12-14 12:06:20','2025-12-14 12:06:20'),(14,'TI-20251214-1',NULL,1,1,'Smartphones','Tecno','Spark','Grey','120','B',89,'clean','clean','charger','2024-01-02',NULL,NULL,NULL,NULL,200.00,200.00,3,'clean device\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Grey\",\"device_storage\":\"120\",\"serial_number\":\"12345678\",\"imei\":\"12345678\",\"sim_configuration\":\"Dual SIM\",\"cost_price\":\"200\",\"selling_price\":\"230\",\"description\":\"Tecno Spark 15\",\"specifications\":\"120Gb\",\"battery_health\":\"89\",\"cosmetic_issues\":\"clean\",\"functional_issues\":\"clean\",\"accessories_included\":\"charger\",\"date_of_first_use\":\"2024-01-02\"}','Accepted','2025-12-14 12:48:52','2025-12-14 12:48:52'),(15,'TI-20251214-5',NULL,1,1,'Smartphones','Tecno','Spark','Grey','120','B',89,'clean','clean','charger','2024-01-02',NULL,NULL,NULL,NULL,200.00,200.00,3,'clean device\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Grey\",\"device_storage\":\"120\",\"serial_number\":\"12345678\",\"imei\":\"12345678\",\"sim_configuration\":\"Dual SIM\",\"cost_price\":\"200\",\"selling_price\":\"230\",\"description\":\"Tecno Spark 15\",\"specifications\":\"120Gb\",\"battery_health\":\"89\",\"cosmetic_issues\":\"clean\",\"functional_issues\":\"clean\",\"accessories_included\":\"charger\",\"date_of_first_use\":\"2024-01-02\"}','Accepted','2025-12-14 12:49:12','2025-12-14 12:49:12'),(21,'TI-20251214-006',NULL,1,1,'Smartphones','Tecno','Spark','Grey','120','A+',89,'clean','none','clean','2024-01-02',NULL,NULL,NULL,NULL,350.00,350.00,4,'clean\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Grey\",\"device_storage\":\"120\",\"serial_number\":\"1234567\",\"imei\":\"1234567\",\"sim_configuration\":\"Dual SIM + eSIM\",\"cost_price\":350,\"selling_price\":400,\"description\":\"Tecno\",\"specifications\":\"128gb\",\"battery_health\":89,\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"clean\",\"date_of_first_use\":\"2024-01-02\"}','Accepted','2025-12-14 13:18:52','2025-12-14 13:18:52'),(22,'TI-20251214-007',NULL,1,1,'Smartphones','Tecno','Spark','Blue','120','B',89,'clean','none','charger','2024-01-02',NULL,NULL,NULL,NULL,150.00,150.00,1,'clean\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Blue\",\"device_storage\":\"120\",\"serial_number\":\"1234567\",\"imei\":\"1234567\",\"sim_configuration\":\"Dual SIM + eSIM\",\"cost_price\":150,\"selling_price\":160,\"description\":\"Tecno\",\"specifications\":\"Spark\",\"battery_health\":89,\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"charger\",\"date_of_first_use\":\"2024-01-02\"}','Accepted','2025-12-14 13:22:42','2025-12-14 13:22:42'),(23,'TI-20251214-008',NULL,1,1,'Smartphones','Tecno','Spark','Blue','120','B',89,'clean','none','charger','2024-01-02',NULL,NULL,NULL,NULL,50.00,50.00,3,'clean\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Blue\",\"device_storage\":\"120\",\"serial_number\":\"1234567\",\"imei\":\"1234567\",\"sim_configuration\":\"eSIM\",\"cost_price\":50,\"selling_price\":60,\"description\":\"Tecno\",\"specifications\":\"Spark\",\"battery_health\":89,\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"charger\",\"date_of_first_use\":\"2024-01-02\"}','Accepted','2025-12-14 13:35:13','2025-12-14 13:35:13'),(24,'TI-20251214-009',NULL,1,1,'Smartphones','Tecno','Spark','Blue','120','C',89,'clean','none','charger','2024-01-02',NULL,NULL,NULL,NULL,600.00,600.00,1,'clean\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Blue\",\"device_storage\":\"120\",\"serial_number\":\"123456\",\"imei\":\"123456\",\"sim_configuration\":\"Dual SIM + eSIM\",\"cost_price\":600,\"selling_price\":620,\"description\":\"Tecno\",\"specifications\":\"Spark\",\"battery_health\":89,\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"charger\",\"date_of_first_use\":\"2024-01-02\"}','Accepted','2025-12-14 13:47:34','2025-12-14 13:47:34'),(25,'TI-20251214-010',NULL,1,1,'Smartphones','Tecno','Spark','Blue','120','B',89,'clean','none','charger','2024-01-02',NULL,NULL,NULL,NULL,600.00,600.00,4,'clean\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Blue\",\"device_storage\":\"120\",\"serial_number\":\"12345\",\"imei\":\"12345\",\"sim_configuration\":\"Dual SIM\",\"cost_price\":600,\"selling_price\":630,\"description\":\"Tecno\",\"specifications\":\"Spark\",\"battery_health\":89,\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"charger\",\"date_of_first_use\":\"2024-01-02\"}','Accepted','2025-12-14 13:51:26','2025-12-14 13:51:26'),(26,'TI-20251214-011',NULL,1,1,'Smartphones','Tecno','Spark','Blue','120','B',89,'clean','none','charger','2024-01-02',NULL,NULL,NULL,NULL,600.00,600.00,4,'clean\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Blue\",\"device_storage\":\"120\",\"serial_number\":\"12345\",\"imei\":\"12345\",\"sim_configuration\":\"Dual SIM\",\"cost_price\":600,\"selling_price\":630,\"description\":\"Tecno\",\"specifications\":\"Spark\",\"battery_health\":89,\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"charger\",\"date_of_first_use\":\"2024-01-02\"}','Accepted','2025-12-14 13:54:11','2025-12-14 13:54:11'),(27,'TI-20251214-012',NULL,1,1,'Smartphones','Tecno','Spark','Blue','120','B',89,'clean','none','charger','2024-01-02',NULL,NULL,NULL,NULL,600.00,600.00,4,'clean\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Smartphones\",\"device_color\":\"Blue\",\"device_storage\":\"120\",\"serial_number\":\"12345\",\"imei\":\"12345\",\"sim_configuration\":\"Dual SIM\",\"cost_price\":600,\"selling_price\":630,\"description\":\"Tecno\",\"specifications\":\"Spark\",\"battery_health\":89,\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"charger\",\"date_of_first_use\":\"2024-01-02\"}','Processed','2025-12-14 13:58:06','2025-12-14 13:58:06'),(28,'TI-20251214-13',12,1,1,'Gaming','Sony','PS5','','','B',NULL,'clean','none','2 game pads','2025-12-01',NULL,NULL,NULL,NULL,250.00,250.00,18,'clean\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Gaming\",\"device_color\":\"\",\"device_storage\":\"\",\"serial_number\":\"\",\"imei\":\"\",\"sim_configuration\":\"\",\"cost_price\":250,\"selling_price\":270,\"description\":\"PS5\",\"specifications\":\"1TB\",\"battery_health\":null,\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"2 game pads\",\"date_of_first_use\":\"2025-12-01\"}','Assessed','2025-12-14 14:07:43','2025-12-14 14:32:24'),(29,'TI-20251214-014',9,1,1,'Gaming','Sony','PS5','','','A+',0,'clean','none','game pads','2025-12-01',NULL,NULL,NULL,NULL,500.00,550.00,18,'neat\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Gaming\",\"device_color\":\"\",\"device_storage\":\"\",\"serial_number\":\"\",\"imei\":\"\",\"sim_configuration\":\"\",\"cost_price\":500,\"selling_price\":550,\"description\":\"PS5\",\"specifications\":\"1TB\",\"battery_health\":\"\",\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"game pads\",\"date_of_first_use\":\"2025-12-01\"}','Processed','2025-12-14 14:14:52','2025-12-14 14:14:53'),(30,'TI-20251214-015',11,1,1,'Gaming','Sony','PS5','','','B',0,'clean','none','1 game pad','2025-12-01',NULL,NULL,NULL,NULL,340.00,340.00,22,'good\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Gaming\",\"device_color\":\"\",\"device_storage\":\"\",\"serial_number\":\"\",\"imei\":\"\",\"sim_configuration\":\"\",\"cost_price\":340,\"selling_price\":400,\"description\":\"Play station\",\"specifications\":\"2TB\",\"battery_health\":\"\",\"cosmetic_issues\":\"clean\",\"functional_issues\":\"none\",\"accessories_included\":\"1 game pad\",\"date_of_first_use\":\"2025-12-01\"}','Processed','2025-12-14 14:19:04','2025-12-14 14:19:05'),(31,'TI-20251214-016',2,1,1,'Audio Devices','Tecno','Spark','Grey','','A+',0,'','','','2025-12-03',NULL,NULL,NULL,NULL,450.00,450.00,4,'none\n\nPRODUCT_DETAILS_JSON:{\"device_category\":\"Audio Devices\",\"device_color\":\"Grey\",\"device_storage\":\"\",\"serial_number\":\"\",\"imei\":\"\",\"sim_configuration\":\"\",\"cost_price\":450,\"selling_price\":500,\"description\":\"\",\"specifications\":\"\",\"battery_health\":\"\",\"cosmetic_issues\":\"\",\"functional_issues\":\"\",\"accessories_included\":\"\",\"date_of_first_use\":\"2025-12-03\"}','Processed','2025-12-14 15:10:53','2025-12-14 15:10:54');
/*!40000 ALTER TABLE `trade_ins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transfer_items`
--

DROP TABLE IF EXISTS `transfer_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transfer_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `serial_numbers` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transfer_id` (`transfer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transfer_items`
--

LOCK TABLES `transfer_items` WRITE;
/*!40000 ALTER TABLE `transfer_items` DISABLE KEYS */;
INSERT INTO `transfer_items` VALUES (1,1,13,NULL,1,'2025-12-14 21:02:03');
/*!40000 ALTER TABLE `transfer_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive','locked') DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_branch_id` (`branch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','admin@electrox.co.zw','$2y$10$3xkndv4Den7JbXkyUOfm2urr7JNex7EWTd7a0sXn9W0CgJIa8L116','System','Administrator',NULL,NULL,1,1,'active','2025-12-15 07:09:04',0,'2025-12-12 16:22:31','2025-12-15 07:09:04',NULL,NULL,NULL),(2,'cashier','cashier@electrox.co.zw','$2y$10$3xkndv4Den7JbXkyUOfm2urr7JNex7EWTd7a0sXn9W0CgJIa8L116','Test','Cashier',NULL,NULL,1,3,'active',NULL,0,'2025-12-12 16:22:31','2025-12-12 17:36:07',NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'electrox_primary'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-12-15  7:18:09

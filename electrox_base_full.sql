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
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
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
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
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
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
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

-- Dump completed on 2025-12-30 18:41:23

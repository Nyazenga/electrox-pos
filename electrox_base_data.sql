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
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `admin_users`
--

LOCK TABLES `admin_users` WRITE;
/*!40000 ALTER TABLE `admin_users` DISABLE KEYS */;
INSERT INTO `admin_users` VALUES (1,'admin','admin@electrox.co.zw','$2y$10$3xkndv4Den7JbXkyUOfm2urr7JNex7EWTd7a0sXn9W0CgJIa8L116','System','Administrator','active','2025-12-12 16:22:30');
/*!40000 ALTER TABLE `admin_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `tenant_registrations`
--

LOCK TABLES `tenant_registrations` WRITE;
/*!40000 ALTER TABLE `tenant_registrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `tenant_registrations` ENABLE KEYS */;
UNLOCK TABLES;

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
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-12-30 18:37:46

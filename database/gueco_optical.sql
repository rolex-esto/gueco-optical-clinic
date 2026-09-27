-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: gueco_optical
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
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_type` enum('staff','patient') DEFAULT 'staff',
  `action` varchar(200) NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,1,'staff','Login','Auth','::1','2026-08-05 14:40:13'),(2,1,'staff','Logout','Auth','::1','2026-08-05 14:44:58'),(3,2,'staff','Login','Auth','::1','2026-08-05 14:45:16'),(4,2,'staff','Logout','Auth','::1','2026-08-05 14:49:55'),(5,3,'staff','Login','Auth','::1','2026-08-05 14:50:25'),(6,3,'staff','Logout','Auth','::1','2026-08-05 14:53:00'),(7,1,'patient','Patient Logout','Auth','::1','2026-08-05 14:54:19'),(8,1,'staff','Login','Auth','::1','2026-08-05 14:54:41'),(9,1,'staff','Logout','Auth','::1','2026-08-05 14:58:06'),(10,2,'staff','Login','Auth','::1','2026-08-06 04:10:51'),(11,1,'patient','Patient Logout','Auth','::1','2026-08-06 07:56:23'),(12,2,'staff','Login','Auth','::1','2026-08-06 08:00:38'),(13,2,'staff','Logout','Auth','::1','2026-08-06 08:03:08'),(14,1,'staff','Login','Auth','::1','2026-08-06 08:03:36'),(15,1,'staff','Logout','Auth','::1','2026-08-06 08:06:29'),(16,3,'staff','Login','Auth','::1','2026-08-06 08:06:47'),(17,3,'staff','Logout','Auth','::1','2026-08-06 08:08:18'),(18,1,'patient','Patient Logout','Auth','::1','2026-08-06 08:16:27'),(19,1,'patient','Patient Logout','Auth','::1','2026-08-06 15:35:02'),(20,2,'staff','Login','Auth','::1','2026-08-06 15:58:06'),(21,1,'patient','Patient Logout','Auth','::1','2026-08-06 16:06:16'),(22,1,'staff','Login','Auth','::1','2026-08-06 16:12:43'),(23,1,'staff','Logout','Auth','::1','2026-08-06 16:26:06'),(24,2,'staff','Login','Auth','::1','2026-08-06 16:26:21'),(25,2,'staff','Logout','Auth','::1','2026-08-06 16:27:27'),(26,1,'patient','Patient Logout','Auth','::1','2026-08-06 16:52:18'),(27,2,'staff','Login','Auth','::1','2026-08-07 04:38:05'),(28,2,'staff','Logout','Auth','::1','2026-08-07 05:09:48'),(29,2,'staff','Login','Auth','::1','2026-08-07 05:14:51'),(30,2,'patient','Patient Logout','Auth','::1','2026-08-07 05:20:31'),(31,2,'patient','Patient Logout','Auth','::1','2026-08-07 05:23:50'),(32,1,'staff','Login','Auth','::1','2026-08-07 10:55:17'),(33,2,'patient','Patient Logout','Auth','::1','2026-08-10 09:44:49'),(34,3,'staff','Login','Auth','::1','2026-08-10 09:46:25'),(35,2,'staff','Login','Auth','::1','2026-08-14 05:48:09'),(36,1,'staff','Login','Auth','::1','2026-08-20 04:36:56'),(37,1,'patient','Patient Logout','Auth','::1','2026-08-20 11:23:15'),(38,1,'staff','Login','Auth','::1','2026-08-20 11:24:01'),(39,1,'staff','Logout','Auth','::1','2026-08-20 12:02:05'),(40,7,'staff','Login','Auth','::1','2026-08-20 12:02:25'),(41,7,'staff','Logout','Auth','::1','2026-08-20 12:02:54'),(42,1,'staff','Login','Auth','::1','2026-08-20 12:03:01'),(43,1,'staff','Login','Auth','::1','2026-08-24 11:08:17'),(44,1,'staff','Logout','Auth','::1','2026-08-26 16:26:41'),(45,1,'staff','Login','Auth','::1','2026-08-26 16:27:11'),(46,1,'staff','Logout','Auth','::1','2026-08-27 03:43:03'),(47,3,'staff','Login','Auth','::1','2026-08-27 03:43:54'),(48,3,'staff','Logout','Auth','::1','2026-08-27 03:44:28'),(49,1,'staff','Login','Auth','::1','2026-08-27 03:44:35'),(50,1,'staff','Login','Auth','::1','2026-08-27 17:01:25'),(51,1,'staff','Logout','Auth','::1','2026-08-27 17:16:35'),(52,3,'staff','Login','Auth','::1','2026-08-27 17:16:43'),(53,3,'staff','Logout','Auth','::1','2026-08-27 17:18:15'),(54,1,'staff','Login','Auth','::1','2026-08-27 17:18:26'),(55,1,'staff','Logout','Auth','::1','2026-08-27 17:41:14'),(56,1,'staff','Login','Auth','::1','2026-08-27 17:41:50'),(57,1,'staff','Logout','Auth','::1','2026-08-29 05:25:27'),(58,3,'staff','Login','Auth','::1','2026-08-29 05:25:33'),(59,3,'staff','Logout','Auth','::1','2026-08-29 05:57:22'),(60,1,'staff','Login','Auth','::1','2026-08-29 05:58:09'),(61,1,'staff','Login','Auth','::1','2026-09-05 13:06:13'),(62,1,'staff','Logout','Auth','::1','2026-09-05 13:08:20'),(63,1,'staff','Login','Auth','::1','2026-09-05 13:20:24'),(64,1,'staff','Login','Auth','::1','2026-09-05 20:17:08'),(65,1,'staff','Login','Auth','::1','2026-09-06 09:04:21'),(66,1,'staff','Login','Auth','::1','2026-09-10 08:55:14'),(67,1,'patient','Patient Logout','Auth','::1','2026-09-10 09:17:15'),(68,2,'staff','Login','Auth','::1','2026-09-10 09:18:16'),(69,2,'staff','Logout','Auth','::1','2026-09-10 09:18:30'),(70,3,'staff','Login','Auth','::1','2026-09-10 09:18:37'),(71,3,'staff','Logout','Auth','::1','2026-09-10 09:19:06'),(72,1,'staff','Login','Auth','::1','2026-09-10 09:19:13'),(74,1,'staff','Logout','Auth','::1','2026-09-10 11:46:21'),(75,2,'staff','Login','Auth','::1','2026-09-10 11:46:32'),(76,2,'staff','Logout','Auth','::1','2026-09-10 11:46:57'),(77,3,'staff','Login','Auth','::1','2026-09-10 11:47:05'),(78,3,'staff','Completed sale GOC-20260910-0003 for Walk-in Customer (Total: ₱2,700.00, Payment: CASH)','Sales / POS','::1','2026-09-10 11:48:13'),(79,3,'staff','Logout','Auth','::1','2026-09-10 11:48:46'),(80,1,'staff','Login','Auth','::1','2026-09-10 11:48:54'),(81,1,'staff','Updated staff account for \"Mike Bryan Tumulak\" (admin@gueco.com, Role: Admin, Status: ACTIVE)','Staff Management','::1','2026-09-10 12:01:28'),(82,1,'staff','Updated staff account for \"Jonnel Olarte\" (admin@gueco.com, Role: Admin, Status: ACTIVE)','Staff Management','::1','2026-09-10 12:32:07'),(83,1,'staff','Updated staff account for \"Jonnel Olarte Nepumocino\" (admin@gueco.com, Role: Admin, Status: ACTIVE)','Staff Management','::1','2026-09-10 12:32:36'),(84,1,'staff','Updated staff account for \"mike bryan\" (admin@gueco.com, Role: Admin, Status: ACTIVE)','Staff Management','::1','2026-09-10 12:36:17'),(85,1,'staff','Updated staff account for \"Mike Bryan\" (admin@gueco.com, Role: Admin, Status: ACTIVE)','Staff Management','::1','2026-09-10 12:36:29'),(86,1,'staff','Updated staff account for \"Mike Bryan Tumulak\" (admin@gueco.com, Role: Admin, Status: ACTIVE)','Staff Management','::1','2026-09-10 12:37:44'),(87,1,'staff','Updated staff account for \"Mike Bryan Tumulak\" (admin@gueco.com, Role: Admin, Status: ACTIVE)','Staff Management','::1','2026-09-10 12:53:15'),(88,1,'staff','Updated supplier \"LensCraft Distributors\" (Status: ACTIVE)','Suppliers','::1','2026-09-10 17:05:55'),(89,1,'staff','Updated product \"Acuvue Oasys Monthly\" (GOCAOM, Price: ₱600.00, Status: ACTIVE)','Inventory','::1','2026-09-10 17:16:40'),(90,1,'staff','Updated product \"Acuvue Oasys Monthly\" (AOMGO, Price: ₱600.00, Status: ACTIVE)','Inventory','::1','2026-09-10 17:17:00'),(91,1,'staff','Logout','Auth','::1','2026-09-10 17:27:33'),(92,3,'staff','Login','Auth','::1','2026-09-10 17:27:42'),(93,3,'staff','Completed sale GOC-20260910-0004 for Juan Dela Cruz (Total: ₱6,700.00, Payment: CASH)','Sales / POS','::1','2026-09-10 17:28:59'),(94,3,'staff','Completed sale GOC-20260910-0005 for Juan Dela Cruz (Total: ₱9,975.00, Payment: CASH)','Sales / POS','::1','2026-09-10 17:32:16'),(95,3,'staff','Logout','Auth','::1','2026-09-10 17:33:15'),(96,1,'staff','Login','Auth','::1','2026-09-10 17:33:26'),(97,1,'staff','Login','Auth','::1','2026-09-14 09:03:50'),(98,1,'patient','Patient Logout','Auth','::1','2026-09-14 10:37:19'),(99,1,'staff','Login','Auth','::1','2026-09-14 11:14:19'),(100,1,'staff','Stock Out: -40 for \"Hard Shell Eyeglass Case\" (Reason: Expired, Stock: 50 → 10)','Inventory','::1','2026-09-14 11:34:59'),(101,1,'staff','Login','Auth','::1','2026-09-18 12:51:36'),(102,1,'staff','Updated staff account for \"Mike Bryan Tumulak\" (admin@gueco.com, Role: Admin, Status: INACTIVE)','Staff Management','::1','2026-09-19 16:12:27'),(103,1,'staff','Updated staff account for \"Mike Bryan Tumulak\" (admin@gueco.com, Role: Admin, Status: ACTIVE)','Staff Management','::1','2026-09-19 16:12:36'),(104,1,'staff','Stock In: +30 for \"Hard Shell Eyeglass Case\" (Reason: Supplier Delivered, Stock: 10 → 40)','Inventory','::1','2026-09-19 16:30:35'),(105,1,'staff','Logout','Auth','::1','2026-09-19 17:00:53'),(106,3,'staff','Login','Auth','::1','2026-09-19 17:01:08'),(107,3,'staff','Logout','Auth','::1','2026-09-19 17:01:19'),(108,1,'staff','Login','Auth','::1','2026-09-19 17:01:29'),(109,1,'staff','Logout','Auth','::1','2026-09-19 17:22:58'),(110,1,'staff','Login','Auth','::1','2026-09-19 19:00:53'),(111,1,'patient','Patient Logout','Auth','::1','2026-09-20 06:42:36'),(112,3,'patient','Patient Logout','Auth','::1','2026-09-20 07:21:14'),(113,3,'patient','Patient Logout','Auth','::1','2026-09-20 07:29:19'),(114,1,'staff','Login','Auth','::1','2026-09-20 07:38:10'),(116,3,'patient','Patient Logout','Auth','::1','2026-09-20 07:56:02'),(118,1,'staff','Login','Auth','::1','2026-09-20 08:09:51'),(120,1,'staff','Login','Auth','::1','2026-09-20 13:29:04'),(123,1,'staff','Login','Auth','::1','2026-09-21 03:00:56'),(124,1,'staff','Confirmed appointment #17 for patient Lebron James','Appointments','::1','2026-09-21 05:22:49');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `appointment_type` varchar(20) NOT NULL DEFAULT 'SCHEDULED',
  `purpose` enum('consultation','eyeglass_claim','follow_up','contact_lens_fitting','other') NOT NULL DEFAULT 'consultation',
  `status` enum('pending','confirmed','in_progress','completed','cancelled','no_show') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` (`id`, `patient_id`, `appointment_date`, `appointment_time`, `purpose`, `status`, `notes`, `verified_by`, `created_at`, `updated_at`) VALUES (1,1,'2026-08-13','10:30:00','consultation','cancelled','',NULL,'2026-08-05 14:54:08','2026-08-06 03:41:34'),(2,1,'2026-08-07','10:00:00','consultation','completed','Testing lang',NULL,'2026-08-06 03:42:25','2026-08-06 04:12:39'),(3,1,'2026-08-07','11:00:00','follow_up','cancelled','',NULL,'2026-08-06 04:03:36','2026-08-06 07:58:39'),(4,1,'2026-08-10','14:30:00','consultation','cancelled','',NULL,'2026-08-06 08:13:45','2026-08-06 08:17:12'),(5,1,'2026-08-14','15:30:00','eyeglass_claim','cancelled','Hello po',NULL,'2026-08-06 08:17:31','2026-08-06 15:35:59'),(6,1,'2026-08-13','10:30:00','consultation','completed','hahahaha',1,'2026-08-06 16:25:43','2026-08-20 05:12:01'),(7,2,'2026-08-12','14:30:00','','completed','try lang',1,'2026-08-08 13:04:40','2026-08-08 13:08:04'),(8,2,'2026-08-10','10:00:00','','cancelled','halo po',NULL,'2026-08-08 13:13:15','2026-08-08 13:17:17'),(9,2,'2026-08-10','10:30:00','contact_lens_fitting','cancelled','',NULL,'2026-08-08 13:15:49','2026-08-08 13:17:14'),(10,2,'2026-08-10','09:00:00','follow_up','cancelled','',NULL,'2026-08-08 13:18:11','2026-08-08 13:25:15'),(11,2,'2026-08-10','14:30:00','follow_up','no_show','',NULL,'2026-08-08 13:26:02','2026-08-20 05:16:52'),(12,1,'2026-08-21','11:30:00','consultation','cancelled','try lang po',1,'2026-08-20 05:18:43','2026-08-20 05:33:25'),(13,1,'2026-08-25','15:00:00','contact_lens_fitting','completed','heheheh',1,'2026-08-20 11:23:42','2026-08-27 02:30:17'),(14,1,'2026-09-24','14:30:00','contact_lens_fitting','pending','Service: Post-Consultation Prescription Check',NULL,'2026-09-19 19:00:36','2026-09-20 06:38:11'),(15,1,'2026-09-22','11:00:00','eyeglass_claim','pending','Service: Eyeglass Frame Selection &amp; Styling',NULL,'2026-09-20 03:57:08','2026-09-20 03:57:08'),(16,1,'2026-09-22','13:30:00','follow_up','pending','Service: Post-Consultation Prescription Check',NULL,'2026-09-20 03:57:58','2026-09-20 04:44:57'),(17,4,'2026-09-24','16:30:00','eyeglass_claim','cancelled','Service: Senior Vision &amp; Cataract Screening',1,'2026-09-21 05:22:12','2026-09-21 05:23:24');
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_category_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Frames','Eyeglass frames of various styles and brands','active','2026-08-05 14:36:44'),(2,'Lenses','Prescription and non-prescription lenses','active','2026-08-05 14:36:44'),(3,'Contact Lenses','Soft and hard contact lenses','active','2026-08-05 14:36:44'),(4,'Eye Care Solutions','Contact lens solutions and eye drops','active','2026-08-05 14:36:44'),(5,'Accessories','Cases, cleaning cloths, and other accessories','active','2026-08-05 14:36:44'),(12,'Mirrors and Filters','Reflect or select specific light wavelengths','active','2026-08-28 05:18:46');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_logs`
--

DROP TABLE IF EXISTS `inventory_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `type` enum('stock_in','stock_out','adjustment') NOT NULL,
  `quantity` int(11) NOT NULL,
  `previous_stock` int(11) NOT NULL,
  `new_stock` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `inventory_logs_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_logs`
--

LOCK TABLES `inventory_logs` WRITE;
/*!40000 ALTER TABLE `inventory_logs` DISABLE KEYS */;
INSERT INTO `inventory_logs` VALUES (1,12,'stock_out',75,100,25,'expiration date',NULL,1,'2026-08-27 03:38:00'),(2,12,'stock_in',50,25,75,'Supplier Delivered',NULL,1,'2026-08-28 05:46:35'),(3,12,'stock_out',2,75,73,'Sale: GOC-20260829-0001',1,3,'2026-08-29 05:53:25'),(4,12,'stock_out',2,73,71,'Sale: GOC-20260829-0001',1,3,'2026-08-29 05:53:25'),(5,4,'stock_out',2,50,48,'Sale: GOC-20260829-0002',2,3,'2026-08-29 05:56:32'),(6,4,'stock_out',2,48,46,'Sale: GOC-20260829-0002',2,3,'2026-08-29 05:56:32'),(7,1,'stock_out',2,20,18,'Sale: GOC-20260910-0003',3,3,'2026-09-10 11:48:13'),(8,3,'stock_out',2,10,8,'Sale: GOC-20260910-0004',4,3,'2026-09-10 17:28:59'),(9,9,'stock_out',2,30,28,'Sale: GOC-20260910-0004',4,3,'2026-09-10 17:28:59'),(10,12,'stock_out',5,71,66,'Sale: GOC-20260910-0005',5,3,'2026-09-10 17:32:16'),(11,2,'stock_out',2,15,13,'Sale: GOC-20260910-0005',5,3,'2026-09-10 17:32:16'),(12,9,'stock_out',3,28,25,'Sale: GOC-20260910-0005',5,3,'2026-09-10 17:32:16'),(22,11,'stock_out',40,50,10,'Expired',NULL,1,'2026-09-14 11:34:59'),(29,11,'stock_in',30,10,40,'Supplier Delivered',NULL,1,'2026-09-19 16:30:35');
/*!40000 ALTER TABLE `inventory_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patient_records`
--

DROP TABLE IF EXISTS `patient_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patient_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `visit_date` date NOT NULL,
  `chief_complaint` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `doctor_id` (`doctor_id`),
  KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `patient_records_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patient_records_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `patient_records_ibfk_3` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patient_records`
--

LOCK TABLES `patient_records` WRITE;
/*!40000 ALTER TABLE `patient_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patients`
--

DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `avatar` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `auth_provider` varchar(20) DEFAULT 'email',
  `email_verified` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_otp_hash` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `login_count` int(11) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `google_id` (`google_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patients`
--

LOCK TABLES `patients` WRITE;
/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
INSERT INTO `patients` VALUES (1,'Juan','Dela','Cruz','Juan Dela Cruz','patient@gueco.com',NULL,'$2y$10$KhzP0BfOYVWtAsm5HgGiB.Cu1VoUI.0q3POkpIPINaMB0FRZJEVDq','09201234567','Quezon City','1995-05-15','male',NULL,'active','2026-08-05 14:36:45','2026-09-20 17:01:37',NULL,NULL),(2,'Mike Bryan','P.','Tumulak','Mike Bryan P. Tumulak','mikebryan@gmail.com',NULL,'$2y$10$ec5bJp/MadFeQuA1oe1fl.o5AbjBV2LG2kN5gpjr7rDrbZhspBTYi','09936518901','Quezon City','2003-07-11','male',NULL,'active','2026-08-07 05:14:18','2026-09-20 17:01:37',NULL,NULL),(3,'Xin','','Bryan','Xin Bryan','bryanxin221@gmail.com','117413819814554404970','$2y$10$xEfG2op17Fytp.rZUyI6E.AlLtlgc9gNDhnowtgExyp7kvMySTDee',NULL,NULL,NULL,NULL,'https://lh3.googleusercontent.com/a/ACg8ocIDlv4veV75vZCiAsJ_YK9oDENbdv4kvw1TLqpTEdDDO04SN_I=s96-c','active','2026-09-20 07:16:41','2026-09-20 17:01:37',NULL,NULL),(4,'Lebron',NULL,'James','Lebron James','brya7789@gmail.com','110469196614655598564','$2y$10$DAzvXu21Dk9K.TZSZUX7ZetVDB9PptFaaGr60xq3nHrG6tb3KIaLq','09368424324','Akron, Ohio','2003-04-12','male','https://lh3.googleusercontent.com/a/ACg8ocLFuM3UujOpjy-9ywZYvhkyc3Oor_QmUj6Owr_5R3VG55fP4Q=s96-c','active','2026-09-20 07:35:50','2026-09-21 05:19:49',NULL,NULL),(5,'Mike','Bryan','Tumulak','Mike Bryan Tumulak','tumulakmikebryan@gmail.com','110028845833045961972','$2y$10$teeshVRXhAxkSTfPKZX5d.GsLlaOxr7cqqjRzn4yYsaHHOgej.X0W','09947895181','Jicilito Zabarte, Caloocan','2004-07-09','male','https://lh3.googleusercontent.com/a/ACg8ocJN_B0BMwFpGZjFPqOXUwaKHSBQw4rUgsXCYfcsocA7BLSc7BQ=s96-c','active','2026-09-20 07:58:51','2026-09-20 17:01:37',NULL,NULL),(6,'Andrea','Mae','Magoncia','Andrea Mae Magoncia','andrea.mae10082004@gmail.com','100546455919179829175','$2y$10$xZgNlqUyLeO4Ar4d2/Sele5LK24iYVkKa.Pf936/CCvvkBnU5etYG','09765674756','Rodriguez  Rizal','2004-08-13','female','https://lh3.googleusercontent.com/a/ACg8ocIqSRHwQ9KbKZ-XEF0XaDxGtNA6BN_P4O-zAU2wkstUPZdG-A=s96-c','active','2026-09-20 13:21:10','2026-09-20 17:01:37',NULL,NULL),(8,'Jonnel',NULL,'Olarte','Jonnel Olarte','parkjonnel@gmail.com',NULL,'$2y$10$SWV7pSiDWbAdfysPBcCg4.ky4WzGPEyKA5.fHFz5J5VEuGj70WNaK','09171234567','Cilito, North Caloocan','2004-08-25','male',NULL,'active','2026-09-20 16:21:40','2026-09-20 17:33:15','$2y$10$vwnVjblrNpRwuEDvWE4AdeC/DfYBBKrY/m4neFG6zVbEG7JZJJQHC','2026-09-21 01:48:15');
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prescriptions`
--

DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prescriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `od_sphere` decimal(5,2) DEFAULT NULL,
  `od_cylinder` decimal(5,2) DEFAULT NULL,
  `od_axis` int(11) DEFAULT NULL,
  `od_add` decimal(5,2) DEFAULT NULL,
  `od_va` varchar(20) DEFAULT NULL,
  `os_sphere` decimal(5,2) DEFAULT NULL,
  `os_cylinder` decimal(5,2) DEFAULT NULL,
  `os_axis` int(11) DEFAULT NULL,
  `os_add` decimal(5,2) DEFAULT NULL,
  `os_va` varchar(20) DEFAULT NULL,
  `pd` decimal(5,2) DEFAULT NULL,
  `pd_right` decimal(5,2) DEFAULT NULL,
  `pd_left` decimal(5,2) DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `lens_type` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `doctor_id` (`doctor_id`),
  KEY `record_id` (`record_id`),
  CONSTRAINT `prescriptions_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prescriptions_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `prescriptions_ibfk_3` FOREIGN KEY (`record_id`) REFERENCES `patient_records` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prescriptions`
--

LOCK TABLES `prescriptions` WRITE;
/*!40000 ALTER TABLE `prescriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `prescriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `tier` enum('budget','mid','high') NOT NULL DEFAULT 'budget',
  `supplier_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `product_code` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `low_stock_alert` int(11) NOT NULL DEFAULT 5,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product_name` (`name`),
  KEY `category_id` (`category_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `idx_products_tier` (`tier`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,1,'mid',1,'Ray-Ban Classic Frame',NULL,'Full-rim acetate frame, black',NULL,1500.00,18,5,'active','2026-08-05 14:36:44','2026-09-18 14:57:04'),(2,1,'high',1,'Titan Titanium Frame',NULL,'Lightweight titanium half-rim frame',NULL,4500.00,13,5,'active','2026-08-05 14:36:44','2026-09-18 14:57:04'),(3,1,'high',2,'Oakley Sport Frame',NULL,'Wraparound sport frame',NULL,3000.00,8,3,'active','2026-08-05 14:36:44','2026-09-18 14:57:04'),(4,2,'budget',2,'Single Vision Lens',NULL,'Standard single vision prescription lens',NULL,800.00,46,10,'active','2026-08-05 14:36:44','2026-08-29 05:56:32'),(5,2,'mid',2,'Progressive Lens',NULL,'No-line multifocal lens',NULL,2000.00,30,8,'active','2026-08-05 14:36:44','2026-09-18 14:57:04'),(6,2,'mid',3,'Anti-Radiation Lens',NULL,'Blue light blocking lens',NULL,1200.00,40,10,'active','2026-08-05 14:36:44','2026-09-18 14:57:04'),(7,3,'budget',2,'Acuvue Oasys Monthly','AOMGO','Monthly disposable contact lenses (pair)',NULL,600.00,25,5,'active','2026-08-05 14:36:44','2026-09-10 17:17:00'),(8,3,'mid',2,'Air Optix Daily',NULL,'Daily disposable contact lenses (30-pack)',NULL,1200.00,20,5,'active','2026-08-05 14:36:44','2026-09-18 14:57:04'),(9,4,'budget',3,'ReNu Multi-Purpose Solution',NULL,'360ml contact lens solution',NULL,350.00,25,8,'active','2026-08-05 14:36:44','2026-09-14 09:12:31'),(10,4,'budget',3,'Opti-Free Replenish',NULL,'300ml contact lens solution',NULL,400.00,25,8,'active','2026-08-05 14:36:44','2026-08-27 03:32:57'),(11,5,'budget',1,'Hard Shell Eyeglass Case','HSECGO','Protective hard case with logo','prod_6a90706849212.jpg',150.00,40,20,'active','2026-08-05 14:36:44','2026-09-19 16:30:35'),(12,5,'budget',1,'Microfiber Cleaning Cloth','MCCGO','Soft lens cleaning cloth','prod_6a9072d55ceae.jpg',85.00,66,25,'active','2026-08-05 14:36:44','2026-09-10 17:32:16');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
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
  `product_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `item_type` enum('product','consultation_fee','service') DEFAULT 'product',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_items`
--

LOCK TABLES `sale_items` WRITE;
/*!40000 ALTER TABLE `sale_items` DISABLE KEYS */;
INSERT INTO `sale_items` VALUES (5,1,3,'Oakley Sport Frame','product',1,3000.00,3000.00,NULL),(6,1,11,'Hard Shell Eyeglass Case','product',1,150.00,150.00,NULL),(7,1,12,'Microfiber Cleaning Cloth','product',2,85.00,170.00,NULL),(8,2,2,'Titan Titanium Frame','product',1,4500.00,4500.00,NULL),(9,2,5,'Progressive Lens','product',2,2000.00,4000.00,NULL),(10,2,7,'Acuvue Oasys Monthly','product',1,600.00,600.00,NULL),(11,2,9,'ReNu Multi-Purpose Solution','product',2,350.00,700.00,NULL),(12,2,12,'Microfiber Cleaning Cloth','product',1,20.00,20.00,NULL),(13,3,1,'Ray-Ban Classic Frame','product',2,1500.00,3000.00,NULL),(14,4,3,'Oakley Sport Frame','product',2,3000.00,6000.00,NULL),(15,4,9,'ReNu Multi-Purpose Solution','product',2,350.00,700.00,NULL),(16,5,12,'Microfiber Cleaning Cloth','product',5,85.00,425.00,NULL),(17,5,2,'Titan Titanium Frame','product',2,4500.00,9000.00,NULL),(18,5,9,'ReNu Multi-Purpose Solution','product',3,350.00,1050.00,NULL);
/*!40000 ALTER TABLE `sale_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(50) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `cashier_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `prescription_id` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','gcash','other') DEFAULT 'cash',
  `payment_type` enum('full','downpayment') NOT NULL DEFAULT 'full',
  `deposit_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(10,2) NOT NULL DEFAULT 0.00,
  `target_pickup_date` date DEFAULT NULL,
  `job_order_no` varchar(50) DEFAULT NULL,
  `order_status` enum('completed','in_progress','ready_for_pickup','claimed') NOT NULL DEFAULT 'completed',
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `change_amount` decimal(10,2) DEFAULT 0.00,
  `status` enum('completed','refunded','voided') DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_no` (`invoice_no`),
  KEY `patient_id` (`patient_id`),
  KEY `cashier_id` (`cashier_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `prescription_id` (`prescription_id`),
  CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`),
  CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `examination_certificates`
--

DROP TABLE IF EXISTS `examination_certificates`;
CREATE TABLE `examination_certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_no` varchar(50) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `certificate_date` date NOT NULL,
  `patient_name` varchar(255) NOT NULL,
  `patient_age` int(11) DEFAULT NULL,
  `patient_address` varchar(255) DEFAULT NULL,
  `branch` varchar(255) NOT NULL DEFAULT 'Poblacion, Capas, Tarlac | Cel No.: 0923-425-7857',
  `reason_for_exam` text NOT NULL,
  `requested_by` varchar(255) NOT NULL,
  `purpose` varchar(255) NOT NULL,
  `doctor_name` varchar(150) NOT NULL DEFAULT 'MARIA LUZ S. GUECO, O.D.',
  `doctor_title` varchar(100) NOT NULL DEFAULT 'OPTOMETRIST',
  `doctor_license_no` varchar(100) NOT NULL DEFAULT 'LIC. NO. 4385',
  `include_signature` tinyint(1) NOT NULL DEFAULT 1,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `certificate_no` (`certificate_no`),
  KEY `idx_cert_patient` (`patient_id`),
  KEY `idx_cert_doctor` (`doctor_id`),
  KEY `idx_cert_appointment` (`appointment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
INSERT INTO `sales` VALUES (1,'GOC-20260829-0001',2,3,NULL,3320.00,0.00,3320.00,'cash',5000.00,1680.00,'completed','2026-08-29 05:53:25'),(2,'GOC-20260829-0002',1,3,NULL,9820.00,0.00,9820.00,'cash',10000.00,180.00,'completed','2026-08-29 05:56:32'),(3,'GOC-20260910-0003',NULL,3,NULL,3000.00,300.00,2700.00,'cash',3000.00,300.00,'completed','2026-09-10 11:48:13'),(4,'GOC-20260910-0004',1,3,NULL,6700.00,0.00,6700.00,'cash',7000.00,300.00,'completed','2026-09-10 17:28:59'),(5,'GOC-20260910-0005',1,3,NULL,10475.00,500.00,9975.00,'cash',11000.00,1025.00,'completed','2026-09-10 17:32:16');
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_company_name` (`company_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'VisionPro Supply Co.','Juan Dela Cruz','09171234567','visionpro@email.com','Manila, Philippines','active','2026-08-05 14:36:44','2026-08-05 14:36:44'),(2,'OpticsWorld Philippines','Maria Santos','09281234567','opticsworld@email.com','Quezon City, Philippines','active','2026-08-05 14:36:44','2026-08-05 14:36:44'),(3,'LensCraft Distributors','Pedro Reyes','09391234567','lenscraft@email.com','Tarlac City, Philippines','active','2026-08-05 14:36:44','2026-08-28 05:52:24');
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'clinic_name','Gueco Optical Clinic','2026-08-05 14:36:44'),(2,'clinic_address','Capas, Tarlac','2026-08-05 14:36:44'),(3,'clinic_phone','09XX-XXX-XXXX','2026-08-05 14:36:44'),(4,'clinic_hours','9:00 AM - 5:00 PM','2026-08-05 14:36:44'),(5,'consultation_fee','300.00','2026-08-05 14:36:44'),(6,'invoice_prefix','GO-','2026-08-05 14:36:44'),(7,'appointment_slots','09:00,09:30,10:00,10:30,11:00,11:30,13:00,13:30,14:00,14:30,15:00,15:30,16:00,16:30','2026-08-05 14:36:44');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','doctor','saleslady') NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `profile_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Mike Bryan Tumulak','admin@gueco.com','$2y$10$0355owRXx/UVO9Jzd0b2aeSyKx2huGtHTVTmHKwVWvTaxzJ85XyUG','admin','09171234567','active',NULL,'2026-08-05 14:36:45','2026-09-19 16:12:36'),(2,'Dr. Gueco','doctor@gueco.com','$2y$10$slt8cXxoOnaD2GTlm4HccOt3N5w7UfjHSshKo7rJd9wpBEuTOlx7y','doctor','09181234567','active',NULL,'2026-08-05 14:36:45','2026-08-05 14:36:45'),(3,'Maria Santos','saleslady@gueco.com','$2y$10$rU5CPvc04vNM9EalVidzYOZdkXP5UPmqtlXGF9CnYzmXD7atuvANe','saleslady','09191234567','active',NULL,'2026-08-05 14:36:45','2026-08-05 14:36:45'),(7,'Mike Bryan P. Tumulak','mikebryan@gmail.com','$2y$10$FvgyD1ehzEkh8v.TWyaC4OS0WOP3XW5joQzDLikpJyWYflIwNelWi','saleslady','09201234567','active',NULL,'2026-08-20 12:01:41','2026-08-27 17:50:23');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-22 22:40:29

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: profitpocket_db
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

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `activity_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `business_id` bigint(20) unsigned DEFAULT NULL,
  `action_code` varchar(80) NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `before_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_data`)),
  `after_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_data`)),
  `details` varchar(255) DEFAULT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  KEY `idx_activity_user_date` (`actor_user_id`,`occurred_at`),
  KEY `idx_activity_business_date` (`business_id`,`occurred_at`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `activity_logs_ibfk_2` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`business_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_action_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_action_logs` (
  `admin_action_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) unsigned NOT NULL,
  `target_user_id` bigint(20) unsigned DEFAULT NULL,
  `action_code` varchar(80) NOT NULL,
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_value`)),
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_value`)),
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`admin_action_id`),
  KEY `idx_admin_action_date` (`admin_user_id`,`created_at`),
  KEY `target_user_id` (`target_user_id`),
  CONSTRAINT `admin_action_logs_ibfk_1` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `admin_action_logs_ibfk_2` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_admin_action_guard BEFORE INSERT ON admin_action_logs
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id
    WHERE u.user_id=NEW.admin_user_id AND r.role_type='admin'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin action requires administrator account';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `announcements`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `announcement_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `author_admin_id` bigint(20) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `announcement_status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `author_admin_id` (`author_admin_id`),
  KEY `idx_announcements_published` (`announcement_status`,`published_at`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`author_admin_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_announcement_guard BEFORE INSERT ON announcements
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id
    WHERE u.user_id=NEW.author_admin_id AND r.role_type='admin'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Announcements require an administrator';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `auth_sessions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_sessions` (
  `session_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `uq_session_token` (`token_hash`),
  KEY `idx_session_user_expiry` (`user_id`,`expires_at`),
  CONSTRAINT `auth_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `chk_session_expiry` CHECK (`expires_at` > `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `backup_jobs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_jobs` (
  `backup_job_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `initiated_by` bigint(20) unsigned DEFAULT NULL,
  `backup_status` enum('queued','running','success','failed') NOT NULL DEFAULT 'queued',
  `storage_reference` varchar(255) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `error_summary` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`backup_job_id`),
  KEY `initiated_by` (`initiated_by`),
  CONSTRAINT `backup_jobs_ibfk_1` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_backup_admin_guard BEFORE INSERT ON backup_jobs
FOR EACH ROW
BEGIN
  IF NEW.initiated_by IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id
    WHERE u.user_id=NEW.initiated_by AND r.role_type='admin'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Backup job initiation requires administrator';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `business_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `business_categories` (
  `business_category_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `category_name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`business_category_id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `businesses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `businesses` (
  `business_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_user_id` bigint(20) unsigned NOT NULL,
  `business_category_id` smallint(5) unsigned NOT NULL,
  `business_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`business_id`),
  UNIQUE KEY `uq_one_business_per_owner` (`owner_user_id`),
  UNIQUE KEY `uq_business_owner_pair` (`business_id`,`owner_user_id`),
  KEY `business_category_id` (`business_category_id`),
  CONSTRAINT `businesses_ibfk_1` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `businesses_ibfk_2` FOREIGN KEY (`business_category_id`) REFERENCES `business_categories` (`business_category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_business_owner_insert BEFORE INSERT ON businesses
FOR EACH ROW
BEGIN
  DECLARE v_type VARCHAR(10);
  SELECT role_type INTO v_type FROM roles WHERE role_id =
    (SELECT role_id FROM users WHERE user_id = NEW.owner_user_id);
  IF v_type IS NULL OR v_type <> 'owner' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business must belong to a business owner';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_business_owner_update BEFORE UPDATE ON businesses
FOR EACH ROW
BEGIN
  IF NEW.owner_user_id <> OLD.owner_user_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business ownership transfer is not enabled';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `customer_credits`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_credits` (
  `sale_id` bigint(20) unsigned NOT NULL,
  `due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reminder_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sale_id`),
  CONSTRAINT `customer_credits_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_credit_insert BEFORE INSERT ON customer_credits
FOR EACH ROW
BEGIN
  DECLARE v_type VARCHAR(12);
  DECLARE v_customer BIGINT UNSIGNED;
  SELECT sale_type, customer_id INTO v_type, v_customer
    FROM sales WHERE sale_id = NEW.sale_id;
  IF v_type IS NULL OR v_type <> 'credit' OR v_customer IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Credit requires a customer-linked credit sale';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_credit_update BEFORE UPDATE ON customer_credits
FOR EACH ROW
BEGIN
  IF NEW.sale_id <> OLD.sale_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot reassign credit to another sale';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_credit_delete BEFORE DELETE ON customer_credits
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Credit histories cannot be deleted';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `customers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `customer_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `uq_customer_business` (`customer_id`,`business_id`),
  KEY `idx_customer_search` (`business_id`,`customer_name`),
  CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`business_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_categories` (
  `expense_category_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`expense_category_id`),
  UNIQUE KEY `uq_exp_cat_business` (`expense_category_id`,`business_id`),
  UNIQUE KEY `uq_exp_cat_name` (`business_id`,`category_name`),
  CONSTRAINT `expense_categories_ibfk_1` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`business_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_receipts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_receipts` (
  `receipt_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `expense_id` bigint(20) unsigned NOT NULL,
  `business_id` bigint(20) unsigned NOT NULL,
  `uploaded_by` bigint(20) unsigned NOT NULL,
  `file_storage_key` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size_bytes` bigint(20) unsigned NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`receipt_id`),
  KEY `expense_id` (`expense_id`,`business_id`),
  KEY `business_id` (`business_id`,`uploaded_by`),
  CONSTRAINT `expense_receipts_ibfk_1` FOREIGN KEY (`expense_id`, `business_id`) REFERENCES `expenses` (`expense_id`, `business_id`),
  CONSTRAINT `expense_receipts_ibfk_2` FOREIGN KEY (`business_id`, `uploaded_by`) REFERENCES `businesses` (`business_id`, `owner_user_id`),
  CONSTRAINT `chk_receipt_size` CHECK (`file_size_bytes` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expenses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses` (
  `expense_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `expense_category_id` bigint(20) unsigned NOT NULL,
  `payment_method_id` smallint(5) unsigned NOT NULL,
  `recurring_expense_id` bigint(20) unsigned DEFAULT NULL,
  `recurrence_due_on` date DEFAULT NULL,
  `entered_by` bigint(20) unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(13,2) NOT NULL,
  `expense_date` date NOT NULL,
  `expense_treatment` enum('operating','inventory_purchase','other_cash_outflow') NOT NULL DEFAULT 'operating',
  `expense_status` enum('posted','void') NOT NULL DEFAULT 'posted',
  `notes` text DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`expense_id`),
  UNIQUE KEY `uq_expense_business` (`expense_id`,`business_id`),
  UNIQUE KEY `uq_recurring_occurrence` (`recurring_expense_id`,`recurrence_due_on`),
  KEY `idx_expense_summary` (`business_id`,`expense_status`,`expense_date`,`expense_treatment`),
  KEY `expense_category_id` (`expense_category_id`,`business_id`),
  KEY `recurring_expense_id` (`recurring_expense_id`,`business_id`),
  KEY `payment_method_id` (`payment_method_id`),
  KEY `business_id` (`business_id`,`entered_by`),
  KEY `business_id_2` (`business_id`,`voided_by`),
  CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`business_id`),
  CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`expense_category_id`, `business_id`) REFERENCES `expense_categories` (`expense_category_id`, `business_id`),
  CONSTRAINT `expenses_ibfk_3` FOREIGN KEY (`recurring_expense_id`, `business_id`) REFERENCES `recurring_expenses` (`recurring_expense_id`, `business_id`),
  CONSTRAINT `expenses_ibfk_4` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`payment_method_id`),
  CONSTRAINT `expenses_ibfk_5` FOREIGN KEY (`business_id`, `entered_by`) REFERENCES `businesses` (`business_id`, `owner_user_id`),
  CONSTRAINT `expenses_ibfk_6` FOREIGN KEY (`business_id`, `voided_by`) REFERENCES `businesses` (`business_id`, `owner_user_id`),
  CONSTRAINT `chk_expense_amount` CHECK (`amount` > 0),
  CONSTRAINT `chk_recurring_due_pair` CHECK (`recurring_expense_id` is null and `recurrence_due_on` is null or `recurring_expense_id` is not null and `recurrence_due_on` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_expense_created_audit AFTER INSERT ON expenses
FOR EACH ROW
BEGIN
  INSERT INTO activity_logs(actor_user_id,business_id,action_code,entity_type,entity_id,after_data)
  VALUES(NEW.entered_by,NEW.business_id,'EXPENSE_CREATED','expense',NEW.expense_id,
    JSON_OBJECT('amount',NEW.amount,'treatment',NEW.expense_treatment));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_expense_update BEFORE UPDATE ON expenses
FOR EACH ROW
BEGIN
  IF OLD.expense_status='void' AND NEW.expense_status <> 'void' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Voided expenses cannot be reopened';
  END IF;
  IF NOT (
      NEW.business_id <=> OLD.business_id AND
      NEW.expense_category_id <=> OLD.expense_category_id AND
      NEW.payment_method_id <=> OLD.payment_method_id AND
      NEW.amount <=> OLD.amount AND
      NEW.expense_date <=> OLD.expense_date AND
      NEW.expense_treatment <=> OLD.expense_treatment AND
      NEW.entered_by <=> OLD.entered_by AND
      NEW.recurring_expense_id <=> OLD.recurring_expense_id AND
      NEW.recurrence_due_on <=> OLD.recurrence_due_on
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Void and recreate an expense to correct financial fields';
  END IF;
  IF OLD.expense_status='posted' AND NEW.expense_status='void' AND
      (NEW.void_reason IS NULL OR NEW.voided_by IS NULL OR NEW.voided_at IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Expense void requires reason, actor and timestamp';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_expense_audit AFTER UPDATE ON expenses
FOR EACH ROW
BEGIN
  IF NEW.expense_status <> OLD.expense_status THEN
    INSERT INTO activity_logs (actor_user_id,business_id,action_code,entity_type,entity_id,before_data,after_data)
    VALUES (NEW.voided_by,NEW.business_id,'EXPENSE_VOIDED','expense',NEW.expense_id,
      JSON_OBJECT('status',OLD.expense_status),JSON_OBJECT('status',NEW.expense_status));
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_expense_delete BEFORE DELETE ON expenses
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Expense records must be preserved';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `password_reset_tokens`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `reset_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `uq_reset_token` (`token_hash`),
  KEY `idx_reset_user` (`user_id`),
  CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `chk_reset_expiry` CHECK (`expires_at` > `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_methods`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_methods` (
  `payment_method_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `method_code` varchar(40) NOT NULL,
  `method_name` varchar(80) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`payment_method_id`),
  UNIQUE KEY `method_code` (`method_code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `permission_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `permission_code` varchar(60) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `permission_code` (`permission_code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `platform_settings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` bigint(20) unsigned NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `platform_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_setting_guard BEFORE INSERT ON platform_settings
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id
    WHERE u.user_id=NEW.updated_by AND r.role_type='admin'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform settings require an administrator';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_setting_update_guard BEFORE UPDATE ON platform_settings
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id
    WHERE u.user_id=NEW.updated_by AND r.role_type='admin'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform settings require an administrator';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `products`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `sku` varchar(60) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `default_selling_price` decimal(13,2) NOT NULL DEFAULT 0.00,
  `default_unit_cost` decimal(13,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `uq_product_business` (`product_id`,`business_id`),
  UNIQUE KEY `uq_product_sku_per_business` (`business_id`,`sku`),
  KEY `idx_product_search` (`business_id`,`product_name`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`business_id`),
  CONSTRAINT `chk_product_sell` CHECK (`default_selling_price` >= 0),
  CONSTRAINT `chk_product_cost` CHECK (`default_unit_cost` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_expenses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recurring_expenses` (
  `recurring_expense_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `expense_category_id` bigint(20) unsigned NOT NULL,
  `payment_method_id` smallint(5) unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(13,2) NOT NULL,
  `expense_treatment` enum('operating','inventory_purchase','other_cash_outflow') NOT NULL DEFAULT 'operating',
  `frequency` enum('daily','weekly','monthly','yearly') NOT NULL,
  `repeat_every` smallint(5) unsigned NOT NULL DEFAULT 1,
  `start_date` date NOT NULL,
  `next_due_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`recurring_expense_id`),
  UNIQUE KEY `uq_recurring_business` (`recurring_expense_id`,`business_id`),
  KEY `idx_recurring_date` (`business_id`,`is_active`,`next_due_date`),
  KEY `expense_category_id` (`expense_category_id`,`business_id`),
  KEY `payment_method_id` (`payment_method_id`),
  CONSTRAINT `recurring_expenses_ibfk_1` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`business_id`),
  CONSTRAINT `recurring_expenses_ibfk_2` FOREIGN KEY (`expense_category_id`, `business_id`) REFERENCES `expense_categories` (`expense_category_id`, `business_id`),
  CONSTRAINT `recurring_expenses_ibfk_3` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`payment_method_id`),
  CONSTRAINT `chk_recurring_amount` CHECK (`amount` > 0),
  CONSTRAINT `chk_recurring_interval` CHECK (`repeat_every` >= 1),
  CONSTRAINT `chk_recurring_end` CHECK (`end_date` is null or `end_date` >= `start_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_permissions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` smallint(5) unsigned NOT NULL,
  `permission_id` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `role_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `role_code` varchar(40) NOT NULL,
  `role_name` varchar(80) NOT NULL,
  `role_type` enum('owner','admin') NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_code` (`role_code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_items` (
  `sale_item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint(20) unsigned NOT NULL,
  `business_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `product_name_snapshot` varchar(150) NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price` decimal(13,2) NOT NULL,
  `unit_cost` decimal(13,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sale_item_id`),
  KEY `idx_sale_item_sale` (`sale_id`,`business_id`),
  KEY `idx_sale_item_product` (`product_id`,`business_id`),
  CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`, `business_id`) REFERENCES `sales` (`sale_id`, `business_id`),
  CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`, `business_id`) REFERENCES `products` (`product_id`, `business_id`),
  CONSTRAINT `chk_sale_item_quantity` CHECK (`quantity` > 0),
  CONSTRAINT `chk_sale_item_price` CHECK (`unit_price` >= 0),
  CONSTRAINT `chk_sale_item_cost` CHECK (`unit_cost` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sale_item_insert BEFORE INSERT ON sale_items
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(12);
  SELECT sale_status INTO v_status FROM sales WHERE sale_id = NEW.sale_id;
  IF v_status <> 'draft' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only draft sales may receive items';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sale_item_update BEFORE UPDATE ON sale_items
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(12);
  SELECT sale_status INTO v_status FROM sales WHERE sale_id = OLD.sale_id;
  IF v_status <> 'draft' OR NEW.sale_id <> OLD.sale_id OR NEW.business_id <> OLD.business_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot alter finalized items or move a sale item';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sale_item_delete BEFORE DELETE ON sale_items
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(12);
  SELECT sale_status INTO v_status FROM sales WHERE sale_id = OLD.sale_id;
  IF v_status <> 'draft' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete finalized sale items';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `sale_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_payments` (
  `payment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint(20) unsigned NOT NULL,
  `business_id` bigint(20) unsigned NOT NULL,
  `payment_method_id` smallint(5) unsigned NOT NULL,
  `provider_name` varchar(80) DEFAULT NULL,
  `entered_by` bigint(20) unsigned NOT NULL,
  `payment_kind` enum('collection','refund') NOT NULL DEFAULT 'collection',
  `amount` decimal(13,2) NOT NULL,
  `reverses_payment_id` bigint(20) unsigned DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reference_number` varchar(100) DEFAULT NULL,
  `idempotency_key` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `uq_payment_same_sale` (`payment_id`,`sale_id`,`business_id`),
  UNIQUE KEY `uq_payment_idempotency` (`business_id`,`idempotency_key`),
  KEY `idx_payment_sale_date` (`sale_id`,`business_id`,`paid_at`),
  KEY `idx_payment_reverses` (`reverses_payment_id`,`sale_id`,`business_id`),
  KEY `payment_method_id` (`payment_method_id`),
  KEY `business_id` (`business_id`,`entered_by`),
  CONSTRAINT `sale_payments_ibfk_1` FOREIGN KEY (`sale_id`, `business_id`) REFERENCES `sales` (`sale_id`, `business_id`),
  CONSTRAINT `sale_payments_ibfk_2` FOREIGN KEY (`reverses_payment_id`, `sale_id`, `business_id`) REFERENCES `sale_payments` (`payment_id`, `sale_id`, `business_id`),
  CONSTRAINT `sale_payments_ibfk_3` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`payment_method_id`),
  CONSTRAINT `sale_payments_ibfk_4` FOREIGN KEY (`business_id`, `entered_by`) REFERENCES `businesses` (`business_id`, `owner_user_id`),
  CONSTRAINT `chk_payment_amount` CHECK (`amount` > 0),
  CONSTRAINT `chk_payment_reversal` CHECK (`payment_kind` = 'collection' and `reverses_payment_id` is null or `payment_kind` = 'refund' and `reverses_payment_id` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_payment_insert BEFORE INSERT ON sale_payments
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(12);
  DECLARE v_discount_type VARCHAR(12);
  DECLARE v_discount DECIMAL(13,2);
  DECLARE v_subtotal DECIMAL(16,2) DEFAULT 0;
  DECLARE v_total DECIMAL(16,2);
  DECLARE v_paid DECIMAL(16,2);
  DECLARE v_original DECIMAL(13,2);
  DECLARE v_original_kind VARCHAR(12);
  DECLARE v_prev_refunds DECIMAL(16,2) DEFAULT 0;

  -- Parent row lock serializes concurrent collections against same sale.
  SELECT sale_status, discount_type, discount_value
    INTO v_status, v_discount_type, v_discount
    FROM sales WHERE sale_id=NEW.sale_id FOR UPDATE;
  IF v_status IS NULL OR v_status='void' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot pay a missing or voided sale';
  END IF;
  SELECT COALESCE(SUM(ROUND(quantity*unit_price,2)),0)
    INTO v_subtotal FROM sale_items WHERE sale_id=NEW.sale_id;
  SET v_total = ROUND(CASE v_discount_type
    WHEN 'percent' THEN v_subtotal*(1-v_discount/100)
    WHEN 'fixed' THEN v_subtotal-v_discount
    ELSE v_subtotal END, 2);
  IF v_total <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sale needs positive total before taking payment';
  END IF;
  SELECT COALESCE(SUM(CASE payment_kind WHEN 'collection' THEN amount ELSE -amount END),0)
    INTO v_paid FROM sale_payments WHERE sale_id=NEW.sale_id;

  IF NEW.payment_kind='collection' THEN
    IF v_paid+NEW.amount > v_total THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds remaining sale balance';
    END IF;
  ELSE
    SELECT amount,payment_kind INTO v_original,v_original_kind
      FROM sale_payments WHERE payment_id=NEW.reverses_payment_id
        AND sale_id=NEW.sale_id AND business_id=NEW.business_id;
    IF v_original IS NULL OR v_original_kind<>'collection' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund must refer to same-sale collection';
    END IF;
    SELECT COALESCE(SUM(amount),0) INTO v_prev_refunds
      FROM sale_payments WHERE reverses_payment_id=NEW.reverses_payment_id
        AND payment_kind='refund';
    IF v_prev_refunds+NEW.amount > v_original OR NEW.amount > v_paid THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund exceeds collected amount';
    END IF;
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_payment_audit AFTER INSERT ON sale_payments
FOR EACH ROW
BEGIN
  INSERT INTO activity_logs (actor_user_id,business_id,action_code,entity_type,entity_id,after_data)
  VALUES (NEW.entered_by,NEW.business_id,'SALE_PAYMENT_RECORDED','sale_payment',NEW.payment_id,
    JSON_OBJECT('sale_id',NEW.sale_id,'kind',NEW.payment_kind,'amount',NEW.amount));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_payment_update BEFORE UPDATE ON sale_payments
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment records are immutable; issue a refund';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_payment_delete BEFORE DELETE ON sale_payments
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment records must be preserved';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `sales`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `sale_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `sale_number` varchar(60) NOT NULL,
  `sale_type` enum('immediate','credit') NOT NULL DEFAULT 'immediate',
  `sale_status` enum('draft','posted','void') NOT NULL DEFAULT 'draft',
  `sold_at` datetime NOT NULL DEFAULT current_timestamp(),
  `discount_type` enum('none','fixed','percent') NOT NULL DEFAULT 'none',
  `discount_value` decimal(13,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sale_id`),
  UNIQUE KEY `uq_sale_number_per_business` (`business_id`,`sale_number`),
  UNIQUE KEY `uq_sale_business` (`sale_id`,`business_id`),
  KEY `idx_sale_customer_business` (`customer_id`,`business_id`),
  KEY `idx_sales_date` (`business_id`,`sale_status`,`sold_at`),
  KEY `business_id` (`business_id`,`created_by`),
  KEY `business_id_2` (`business_id`,`voided_by`),
  CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`business_id`),
  CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`customer_id`, `business_id`) REFERENCES `customers` (`customer_id`, `business_id`),
  CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`business_id`, `created_by`) REFERENCES `businesses` (`business_id`, `owner_user_id`),
  CONSTRAINT `sales_ibfk_4` FOREIGN KEY (`business_id`, `voided_by`) REFERENCES `businesses` (`business_id`, `owner_user_id`),
  CONSTRAINT `chk_sales_discount` CHECK (`discount_value` >= 0 and (`discount_type` = 'none' and `discount_value` = 0 or `discount_type` = 'fixed' or `discount_type` = 'percent' and `discount_value` <= 100)),
  CONSTRAINT `chk_credit_has_customer` CHECK (`sale_type` <> 'credit' or `customer_id` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sale_created_audit AFTER INSERT ON sales
FOR EACH ROW
BEGIN
  INSERT INTO activity_logs(actor_user_id,business_id,action_code,entity_type,entity_id)
  VALUES(NEW.created_by,NEW.business_id,'SALE_CREATED','sale',NEW.sale_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sale_before_update BEFORE UPDATE ON sales
FOR EACH ROW
BEGIN
  DECLARE v_subtotal DECIMAL(16,2) DEFAULT 0;
  DECLARE v_total DECIMAL(16,2) DEFAULT 0;
  DECLARE v_paid DECIMAL(16,2) DEFAULT 0;
  DECLARE v_count BIGINT DEFAULT 0;

  IF OLD.sale_status <> 'draft' AND NOT (
      NEW.business_id <=> OLD.business_id AND
      NEW.customer_id <=> OLD.customer_id AND
      NEW.sale_number <=> OLD.sale_number AND
      NEW.sale_type <=> OLD.sale_type AND
      NEW.sold_at <=> OLD.sold_at AND
      NEW.discount_type <=> OLD.discount_type AND
      NEW.discount_value <=> OLD.discount_value AND
      NEW.created_by <=> OLD.created_by
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finalized sale financial fields are immutable';
  END IF;
  IF OLD.sale_status='void' AND NOT (
      NEW.void_reason <=> OLD.void_reason AND
      NEW.voided_by <=> OLD.voided_by AND
      NEW.voided_at <=> OLD.voided_at
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Void metadata is immutable';
  END IF;
  IF OLD.sale_status='void' AND NEW.sale_status <> 'void' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Voided sales cannot be reopened';
  END IF;
  IF OLD.sale_status='posted' AND NEW.sale_status='draft' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted sales cannot return to draft';
  END IF;
  IF OLD.sale_status='draft' AND NEW.sale_status <> 'draft' THEN
    SELECT COUNT(*), COALESCE(SUM(ROUND(quantity*unit_price,2)),0)
      INTO v_count, v_subtotal FROM sale_items WHERE sale_id = OLD.sale_id;
    IF NEW.sale_status='posted' AND (v_count=0 OR v_subtotal <= 0) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted sale requires priced items';
    END IF;
  END IF;
  IF NEW.sale_type <> 'credit' AND EXISTS
    (SELECT 1 FROM customer_credits WHERE sale_id=OLD.sale_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot convert a credit record to an immediate sale';
  END IF;
  IF OLD.sale_status='draft' AND NEW.sale_status='posted' THEN
    SET v_total = ROUND(CASE NEW.discount_type
      WHEN 'percent' THEN v_subtotal*(1-NEW.discount_value/100)
      WHEN 'fixed' THEN v_subtotal-NEW.discount_value
      ELSE v_subtotal END, 2);
    IF v_total <= 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted sale amount must be positive';
    END IF;
    SELECT COALESCE(SUM(CASE payment_kind WHEN 'collection' THEN amount ELSE -amount END),0)
      INTO v_paid FROM sale_payments WHERE sale_id=OLD.sale_id;
    IF v_paid < 0 OR v_paid > v_total THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment total does not match sale amount';
    END IF;
    IF NEW.sale_type='immediate' AND v_paid <> v_total THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immediate sale must be fully paid before posting';
    END IF;
    IF NEW.sale_type='credit' AND NOT EXISTS
      (SELECT 1 FROM customer_credits WHERE sale_id=OLD.sale_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Credit sale requires credit details';
    END IF;
  END IF;
  IF NEW.sale_status='void' AND OLD.sale_status <> 'void' THEN
    SELECT COALESCE(SUM(CASE payment_kind WHEN 'collection' THEN amount ELSE -amount END),0)
      INTO v_paid FROM sale_payments WHERE sale_id=OLD.sale_id;
    IF v_paid <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund payments before voiding a paid sale';
    END IF;
    IF NEW.void_reason IS NULL OR NEW.voided_by IS NULL OR NEW.voided_at IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Void requires reason, actor and timestamp';
    END IF;
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sale_status_audit AFTER UPDATE ON sales
FOR EACH ROW
BEGIN
  IF NEW.sale_status <> OLD.sale_status THEN
    INSERT INTO activity_logs (actor_user_id,business_id,action_code,entity_type,entity_id,before_data,after_data)
    VALUES (IF(NEW.sale_status='void', NEW.voided_by, NEW.created_by),
      NEW.business_id,'SALE_STATUS_CHANGED','sale',NEW.sale_id,
      JSON_OBJECT('status',OLD.sale_status), JSON_OBJECT('status',NEW.sale_status));
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `support_tickets`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_tickets` (
  `ticket_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submitted_by` bigint(20) unsigned NOT NULL,
  `assigned_admin_id` bigint(20) unsigned DEFAULT NULL,
  `subject` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('low','normal','high') NOT NULL DEFAULT 'normal',
  `ticket_status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  KEY `submitted_by` (`submitted_by`),
  KEY `assigned_admin_id` (`assigned_admin_id`),
  KEY `idx_ticket_status` (`ticket_status`,`created_at`),
  CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `support_tickets_ibfk_2` FOREIGN KEY (`assigned_admin_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ticket_assignment_guard BEFORE INSERT ON support_tickets
FOR EACH ROW
BEGIN
  IF NEW.assigned_admin_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id
    WHERE u.user_id=NEW.assigned_admin_id AND r.role_type='admin'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket assignee must be an administrator';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ticket_assignment_update_guard BEFORE UPDATE ON support_tickets
FOR EACH ROW
BEGIN
  IF NEW.assigned_admin_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id
    WHERE u.user_id=NEW.assigned_admin_id AND r.role_type='admin'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket assignee must be an administrator';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `ticket_messages`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_messages` (
  `message_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `sender_user_id` bigint(20) unsigned NOT NULL,
  `message_body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`),
  KEY `idx_ticket_messages` (`ticket_id`,`created_at`),
  KEY `sender_user_id` (`sender_user_id`),
  CONSTRAINT `ticket_messages_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`ticket_id`),
  CONSTRAINT `ticket_messages_ibfk_2` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_status_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_status_history` (
  `history_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `changed_by` bigint(20) unsigned NOT NULL,
  `old_status` enum('open','in_progress','resolved','closed') NOT NULL,
  `new_status` enum('open','in_progress','resolved','closed') NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `ticket_status_history_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`ticket_id`),
  CONSTRAINT `ticket_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_feedback`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_feedback` (
  `feedback_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `category` enum('bug','suggestion','compliment','other') NOT NULL DEFAULT 'suggestion',
  `message` text NOT NULL,
  `status` enum('new','reviewed','archived') NOT NULL DEFAULT 'new',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`feedback_id`),
  KEY `submitted_by` (`submitted_by`),
  CONSTRAINT `user_feedback_ibfk_1` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` smallint(5) unsigned NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `account_status` enum('active','inactive','suspended','deactivated') NOT NULL DEFAULT 'active',
  `email_verified_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_email` (`email`),
  KEY `idx_users_role_status` (`role_id`,`account_status`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_user_role_guard BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
  DECLARE v_type VARCHAR(10);
  IF NEW.role_id <> OLD.role_id AND EXISTS
    (SELECT 1 FROM businesses WHERE owner_user_id=OLD.user_id) THEN
    SELECT role_type INTO v_type FROM roles WHERE role_id=NEW.role_id;
    IF v_type <> 'owner' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business owner account cannot become an administrator';
    END IF;
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Temporary table structure for view `v_business_credit_totals`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_business_credit_totals` AS SELECT
 1 AS `business_id`,
  1 AS `outstanding_utang` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_credit_balances`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_credit_balances` AS SELECT
 1 AS `sale_id`,
  1 AS `business_id`,
  1 AS `customer_id`,
  1 AS `sale_number`,
  1 AS `credit_created_at`,
  1 AS `due_date`,
  1 AS `notes`,
  1 AS `reminder_notes`,
  1 AS `original_sale_amount`,
  1 AS `total_paid`,
  1 AS `balance_due`,
  1 AS `credit_status` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_daily_financial_events`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_daily_financial_events` AS SELECT
 1 AS `business_id`,
  1 AS `activity_date`,
  1 AS `net_sales`,
  1 AS `cogs`,
  1 AS `operating_expenses`,
  1 AS `cash_in`,
  1 AS `cash_out` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_daily_financials`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_daily_financials` AS SELECT
 1 AS `business_id`,
  1 AS `activity_date`,
  1 AS `net_sales`,
  1 AS `cost_of_goods_sold`,
  1 AS `gross_profit`,
  1 AS `operating_expenses`,
  1 AS `net_profit`,
  1 AS `cash_received`,
  1 AS `cash_spent`,
  1 AS `net_cash_flow` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_dashboard_overview`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_dashboard_overview` AS SELECT
 1 AS `business_id`,
  1 AS `business_name`,
  1 AS `total_net_sales`,
  1 AS `total_cogs`,
  1 AS `total_operating_expenses`,
  1 AS `estimated_net_profit`,
  1 AS `total_cash_received`,
  1 AS `total_cash_spent`,
  1 AS `net_cash_flow`,
  1 AS `outstanding_utang` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_payment_totals`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_payment_totals` AS SELECT
 1 AS `sale_id`,
  1 AS `net_paid` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_sale_financials`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_sale_financials` AS SELECT
 1 AS `sale_id`,
  1 AS `business_id`,
  1 AS `customer_id`,
  1 AS `sale_number`,
  1 AS `sale_type`,
  1 AS `sale_status`,
  1 AS `sold_at`,
  1 AS `discount_type`,
  1 AS `discount_value`,
  1 AS `subtotal`,
  1 AS `cost_of_goods_sold`,
  1 AS `net_sale`,
  1 AS `total_paid`,
  1 AS `balance_due` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_sale_item_totals`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_sale_item_totals` AS SELECT
 1 AS `sale_id`,
  1 AS `subtotal`,
  1 AS `cost_of_goods_sold`,
  1 AS `item_rows` */;
SET character_set_client = @saved_cs_client;

--
-- Dumping routines for database 'profitpocket_db'
--

--
-- Final view structure for view `v_business_credit_totals`
--

/*!50001 DROP VIEW IF EXISTS `v_business_credit_totals`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_business_credit_totals` AS select `v_credit_balances`.`business_id` AS `business_id`,round(sum(`v_credit_balances`.`balance_due`),2) AS `outstanding_utang` from `v_credit_balances` where `v_credit_balances`.`credit_status` not in ('void','fully_paid') group by `v_credit_balances`.`business_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_credit_balances`
--

/*!50001 DROP VIEW IF EXISTS `v_credit_balances`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_credit_balances` AS select `f`.`sale_id` AS `sale_id`,`f`.`business_id` AS `business_id`,`f`.`customer_id` AS `customer_id`,`f`.`sale_number` AS `sale_number`,`c`.`created_at` AS `credit_created_at`,`c`.`due_date` AS `due_date`,`c`.`notes` AS `notes`,`c`.`reminder_notes` AS `reminder_notes`,`f`.`net_sale` AS `original_sale_amount`,`f`.`total_paid` AS `total_paid`,`f`.`balance_due` AS `balance_due`,case when `f`.`sale_status` = 'void' then 'void' when `f`.`balance_due` = 0 then 'fully_paid' when `c`.`due_date` is not null and `c`.`due_date` < curdate() then 'overdue' when `f`.`total_paid` > 0 then 'partially_paid' else 'unpaid' end AS `credit_status` from (`customer_credits` `c` join `v_sale_financials` `f` on(`f`.`sale_id` = `c`.`sale_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_daily_financial_events`
--

/*!50001 DROP VIEW IF EXISTS `v_daily_financial_events`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_daily_financial_events` AS select `v_sale_financials`.`business_id` AS `business_id`,cast(`v_sale_financials`.`sold_at` as date) AS `activity_date`,`v_sale_financials`.`net_sale` AS `net_sales`,`v_sale_financials`.`cost_of_goods_sold` AS `cogs`,0.00 AS `operating_expenses`,0.00 AS `cash_in`,0.00 AS `cash_out` from `v_sale_financials` where `v_sale_financials`.`sale_status` = 'posted' union all select `expenses`.`business_id` AS `business_id`,`expenses`.`expense_date` AS `expense_date`,0.00 AS `0.00`,0.00 AS `0.00`,case when `expenses`.`expense_treatment` = 'operating' then `expenses`.`amount` else 0.00 end AS `Name_exp_5`,0.00 AS `0.00`,`expenses`.`amount` AS `amount` from `expenses` where `expenses`.`expense_status` = 'posted' union all select `p`.`business_id` AS `business_id`,cast(`p`.`paid_at` as date) AS `DATE(p.paid_at)`,0.00 AS `0.00`,0.00 AS `0.00`,0.00 AS `0.00`,case when `p`.`payment_kind` = 'collection' then `p`.`amount` else -`p`.`amount` end AS `Name_exp_6`,0.00 AS `0.00` from (`sale_payments` `p` join `sales` `s` on(`s`.`sale_id` = `p`.`sale_id`)) where `s`.`sale_status` in ('posted','void') */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_daily_financials`
--

/*!50001 DROP VIEW IF EXISTS `v_daily_financials`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_daily_financials` AS select `v_daily_financial_events`.`business_id` AS `business_id`,`v_daily_financial_events`.`activity_date` AS `activity_date`,round(sum(`v_daily_financial_events`.`net_sales`),2) AS `net_sales`,round(sum(`v_daily_financial_events`.`cogs`),2) AS `cost_of_goods_sold`,round(sum(`v_daily_financial_events`.`net_sales`) - sum(`v_daily_financial_events`.`cogs`),2) AS `gross_profit`,round(sum(`v_daily_financial_events`.`operating_expenses`),2) AS `operating_expenses`,round(sum(`v_daily_financial_events`.`net_sales`) - sum(`v_daily_financial_events`.`cogs`) - sum(`v_daily_financial_events`.`operating_expenses`),2) AS `net_profit`,round(sum(`v_daily_financial_events`.`cash_in`),2) AS `cash_received`,round(sum(`v_daily_financial_events`.`cash_out`),2) AS `cash_spent`,round(sum(`v_daily_financial_events`.`cash_in`) - sum(`v_daily_financial_events`.`cash_out`),2) AS `net_cash_flow` from `v_daily_financial_events` group by `v_daily_financial_events`.`business_id`,`v_daily_financial_events`.`activity_date` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_dashboard_overview`
--

/*!50001 DROP VIEW IF EXISTS `v_dashboard_overview`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_dashboard_overview` AS select `b`.`business_id` AS `business_id`,`b`.`business_name` AS `business_name`,coalesce(sum(`d`.`net_sales`),0.00) AS `total_net_sales`,coalesce(sum(`d`.`cost_of_goods_sold`),0.00) AS `total_cogs`,coalesce(sum(`d`.`operating_expenses`),0.00) AS `total_operating_expenses`,coalesce(sum(`d`.`net_profit`),0.00) AS `estimated_net_profit`,coalesce(sum(`d`.`cash_received`),0.00) AS `total_cash_received`,coalesce(sum(`d`.`cash_spent`),0.00) AS `total_cash_spent`,coalesce(sum(`d`.`net_cash_flow`),0.00) AS `net_cash_flow`,coalesce(`c`.`outstanding_utang`,0.00) AS `outstanding_utang` from ((`businesses` `b` left join `v_daily_financials` `d` on(`d`.`business_id` = `b`.`business_id`)) left join `v_business_credit_totals` `c` on(`c`.`business_id` = `b`.`business_id`)) group by `b`.`business_id`,`b`.`business_name`,`c`.`outstanding_utang` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_payment_totals`
--

/*!50001 DROP VIEW IF EXISTS `v_payment_totals`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_payment_totals` AS select `sale_payments`.`sale_id` AS `sale_id`,round(sum(case `sale_payments`.`payment_kind` when 'collection' then `sale_payments`.`amount` else -`sale_payments`.`amount` end),2) AS `net_paid` from `sale_payments` group by `sale_payments`.`sale_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_sale_financials`
--

/*!50001 DROP VIEW IF EXISTS `v_sale_financials`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_sale_financials` AS select `s`.`sale_id` AS `sale_id`,`s`.`business_id` AS `business_id`,`s`.`customer_id` AS `customer_id`,`s`.`sale_number` AS `sale_number`,`s`.`sale_type` AS `sale_type`,`s`.`sale_status` AS `sale_status`,`s`.`sold_at` AS `sold_at`,`s`.`discount_type` AS `discount_type`,`s`.`discount_value` AS `discount_value`,coalesce(`i`.`subtotal`,0.00) AS `subtotal`,coalesce(`i`.`cost_of_goods_sold`,0.00) AS `cost_of_goods_sold`,round(case `s`.`discount_type` when 'percent' then coalesce(`i`.`subtotal`,0) * (1 - `s`.`discount_value` / 100) when 'fixed' then coalesce(`i`.`subtotal`,0) - `s`.`discount_value` else coalesce(`i`.`subtotal`,0) end,2) AS `net_sale`,coalesce(`p`.`net_paid`,0.00) AS `total_paid`,greatest(0,round(case `s`.`discount_type` when 'percent' then coalesce(`i`.`subtotal`,0) * (1 - `s`.`discount_value` / 100) when 'fixed' then coalesce(`i`.`subtotal`,0) - `s`.`discount_value` else coalesce(`i`.`subtotal`,0) end - coalesce(`p`.`net_paid`,0),2)) AS `balance_due` from ((`sales` `s` left join `v_sale_item_totals` `i` on(`i`.`sale_id` = `s`.`sale_id`)) left join `v_payment_totals` `p` on(`p`.`sale_id` = `s`.`sale_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_sale_item_totals`
--

/*!50001 DROP VIEW IF EXISTS `v_sale_item_totals`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_sale_item_totals` AS select `sale_items`.`sale_id` AS `sale_id`,round(sum(round(`sale_items`.`quantity` * `sale_items`.`unit_price`,2)),2) AS `subtotal`,round(sum(round(`sale_items`.`quantity` * `sale_items`.`unit_cost`,2)),2) AS `cost_of_goods_sold`,count(0) AS `item_rows` from `sale_items` group by `sale_items`.`sale_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-09 14:29:43

/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.6-MariaDB, for debian-linux-gnu (aarch64)
--
-- Host: localhost    Database: crypto_bot
-- ------------------------------------------------------
-- Server version	11.8.6-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `bot_settings`
--

DROP TABLE IF EXISTS `bot_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bot_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'string',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bot_settings_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bot_settings`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `bot_settings` WRITE;
/*!40000 ALTER TABLE `bot_settings` DISABLE KEYS */;
INSERT INTO `bot_settings` VALUES
(1,'starting_balance','100','float','2026-05-01 06:46:41','2026-05-01 06:46:41'),
(2,'leverage','10','int','2026-05-01 07:02:01','2026-05-01 07:02:01'),
(3,'strategy.short_scalp.trailing_tp_enabled','1','bool','2026-05-02 14:10:16','2026-05-02 14:10:16'),
(4,'strategy.short_scalp.trailing_tp_arm_pct','1','float','2026-05-02 14:10:16','2026-05-02 14:10:16'),
(5,'strategy.short_scalp.trailing_tp_trail_pct','0.5','float','2026-05-02 14:10:16','2026-05-02 14:10:16'),
(6,'strategy.short_scalp.partial_tp_trigger_pct','0','float','2026-05-02 14:10:16','2026-05-02 14:10:16'),
(7,'strategy.short_scalp.strict_downtrend_enabled','','bool','2026-05-02 14:10:16','2026-05-02 14:10:16'),
(8,'strategy.short_scalp.atr_sl_enabled','','bool','2026-05-02 14:10:16','2026-05-02 14:10:16'),
(9,'strategy.short_scalp.stop_loss_pct','2.5','float','2026-05-02 14:10:16','2026-05-02 14:10:16'),
(10,'strategy.short_scalp.max_hold_minutes','480','int','2026-05-02 14:10:16','2026-05-02 14:10:16'),
(12,'strategy.long_continuation.enabled','1','bool','2026-05-06 04:37:31','2026-05-06 04:37:31');
/*!40000 ALTER TABLE `bot_settings` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `positions`
--

DROP TABLE IF EXISTS `positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `positions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `symbol` varchar(255) NOT NULL,
  `side` varchar(255) NOT NULL DEFAULT 'SHORT',
  `strategy_key` varchar(64) DEFAULT NULL,
  `entry_price` decimal(20,8) NOT NULL,
  `quantity` decimal(20,8) NOT NULL,
  `position_size_usdt` decimal(20,4) NOT NULL,
  `stop_loss_price` decimal(20,8) DEFAULT NULL,
  `take_profit_price` decimal(20,8) DEFAULT NULL,
  `current_price` decimal(20,8) DEFAULT NULL,
  `unrealized_pnl` decimal(20,4) DEFAULT NULL,
  `leverage` int(11) NOT NULL DEFAULT 5,
  `status` varchar(255) NOT NULL DEFAULT 'open',
  `error_message` text DEFAULT NULL,
  `exchange_order_id` varchar(255) DEFAULT NULL,
  `sl_order_id` varchar(255) DEFAULT NULL,
  `tp_order_id` varchar(255) DEFAULT NULL,
  `partial_tp_taken` tinyint(1) NOT NULL DEFAULT 0,
  `trailing_tp_armed` tinyint(1) NOT NULL DEFAULT 0,
  `trailing_extreme_price` decimal(25,12) DEFAULT NULL,
  `total_entry_fee` decimal(20,8) DEFAULT NULL,
  `funding_fee` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `last_funding_at` timestamp NULL DEFAULT NULL,
  `is_dry_run` tinyint(1) NOT NULL DEFAULT 1,
  `opened_at` timestamp NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `positions_symbol_status_index` (`symbol`,`status`),
  KEY `positions_status_index` (`status`),
  KEY `positions_strategy_status_idx` (`strategy_key`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `positions`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `positions` WRITE;
/*!40000 ALTER TABLE `positions` DISABLE KEYS */;
INSERT INTO `positions` VALUES
(1,'DUSDT','SHORT','short_scalp',0.01069807,9348.00000000,100.0000,0.01096552,0.00000000,0.01065300,0.4213,10,'open',NULL,'dry_lim_69fad91455e9b9.24686827','dry_sl_69fad91456cad7.59057318','dry_trail_69fad91456d371.93696904',0,0,NULL,0.02500139,-0.02663048,'2026-05-06 00:00:00',1,'2026-05-06 06:00:52','2026-05-06 14:00:52','2026-05-06 06:00:52','2026-05-06 06:04:00'),
(2,'BRUSDT','SHORT','short_scalp',0.17185718,581.00000000,100.0000,0.17615361,0.00000000,0.17123000,0.3644,10,'open',NULL,'dry_lim_69fad914e4cc69.11550511','dry_sl_69fad914e5fd40.70061920','dry_trail_69fad914e606d8.65644625',0,0,NULL,0.02496226,0.00499245,'2026-05-06 00:00:00',1,'2026-05-06 06:00:52','2026-05-06 14:00:52','2026-05-06 06:00:52','2026-05-06 06:04:00'),
(3,'AIGENSYNUSDT','SHORT','short_scalp',0.03202320,3123.00000000,100.0000,0.03282378,0.00000000,0.03226000,-0.7395,10,'open',NULL,'dry_lim_69fad915d6ccf0.40739927','dry_sl_69fad915d805f8.38918989','dry_trail_69fad915d811e4.16134222',0,0,NULL,0.02500211,0.01065590,'2026-05-06 00:00:00',1,'2026-05-06 06:00:53','2026-05-06 14:00:53','2026-05-06 06:00:53','2026-05-06 06:04:00');
/*!40000 ALTER TABLE `positions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `trades`
--

DROP TABLE IF EXISTS `trades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `position_id` bigint(20) unsigned NOT NULL,
  `symbol` varchar(255) NOT NULL,
  `side` varchar(255) NOT NULL,
  `strategy_key` varchar(64) DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'close',
  `entry_price` decimal(20,8) NOT NULL,
  `exit_price` decimal(20,8) NOT NULL,
  `quantity` decimal(20,8) NOT NULL,
  `pnl` decimal(20,4) NOT NULL,
  `pnl_pct` decimal(10,4) NOT NULL,
  `fees` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `funding_fee` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `close_reason` varchar(255) NOT NULL,
  `exchange_order_id` varchar(255) DEFAULT NULL,
  `is_dry_run` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `trades_position_id_foreign` (`position_id`),
  KEY `trades_symbol_index` (`symbol`),
  KEY `trades_close_reason_index` (`close_reason`),
  KEY `trades_strategy_created_idx` (`strategy_key`,`created_at`),
  CONSTRAINT `trades_position_id_foreign` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trades`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `trades` WRITE;
/*!40000 ALTER TABLE `trades` DISABLE KEYS */;
/*!40000 ALTER TABLE `trades` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `balance_snapshots`
--

DROP TABLE IF EXISTS `balance_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `balance_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `wallet_balance` decimal(20,8) NOT NULL,
  `available_balance` decimal(20,8) NOT NULL,
  `unrealized_profit` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `margin_balance` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `position_margin` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `maint_margin` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `open_positions` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_dry_run` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `balance_snapshots_is_dry_run_created_at_index` (`is_dry_run`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `balance_snapshots`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `balance_snapshots` WRITE;
/*!40000 ALTER TABLE `balance_snapshots` DISABLE KEYS */;
INSERT INTO `balance_snapshots` VALUES
(1,100.00000000,100.00000000,0.00000000,100.00000000,0.00000000,0.00000000,0,1,'2026-05-06 06:00:50');
/*!40000 ALTER TABLE `balance_snapshots` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-05-06  6:04:22

/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(255) NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `related_type` varchar(255) DEFAULT NULL,
  `related_id` bigint(20) unsigned DEFAULT NULL,
  `actor_type` varchar(255) DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `process` varchar(255) NOT NULL,
  `severity` varchar(255) NOT NULL DEFAULT 'info',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `capability_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `capability_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `capability` varchar(255) NOT NULL,
  `integration` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `capability_assignments_capability_unique` (`capability`),
  KEY `capability_assignments_integration_index` (`integration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `connection_test_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `connection_test_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `integration` varchar(255) NOT NULL,
  `success` tinyint(1) NOT NULL,
  `message` text DEFAULT NULL,
  `request_method` varchar(255) DEFAULT NULL,
  `request_url` text DEFAULT NULL,
  `response_status` int(11) DEFAULT NULL,
  `response_data` text DEFAULT NULL,
  `response_time_ms` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `connection_test_logs_integration_index` (`integration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `content_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `content_blocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `grid_col` int(11) NOT NULL DEFAULT 1,
  `grid_row` int(11) NOT NULL DEFAULT 1,
  `col_span` int(11) NOT NULL DEFAULT 1,
  `row_span` int(11) NOT NULL DEFAULT 1,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dhcp_leases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dhcp_leases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `integration` varchar(255) DEFAULT NULL,
  `ip_address_id` bigint(20) unsigned NOT NULL,
  `mac_address_id` bigint(20) unsigned DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dhcp_leases_ip_address_id_mac_address_id_unique` (`ip_address_id`,`mac_address_id`),
  KEY `dhcp_leases_ip_address_id_index` (`ip_address_id`),
  KEY `dhcp_leases_mac_address_id_foreign` (`mac_address_id`),
  CONSTRAINT `dhcp_leases_ip_address_id_foreign` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dhcp_leases_mac_address_id_foreign` FOREIGN KEY (`mac_address_id`) REFERENCES `mac_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dhcp_pool_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dhcp_pool_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `integration` varchar(255) NOT NULL,
  `address_family` varchar(255) NOT NULL,
  `total` decimal(39,0) NOT NULL DEFAULT 0,
  `used` decimal(39,0) NOT NULL DEFAULT 0,
  `available` decimal(39,0) NOT NULL DEFAULT 0,
  `utilisation` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dhcp_pool_statuses_integration_address_family_unique` (`integration`,`address_family`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dhcp_range_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dhcp_range_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `integration` varchar(255) DEFAULT NULL,
  `interface` varchar(255) NOT NULL DEFAULT '',
  `type` varchar(255) NOT NULL,
  `subnet` varchar(255) NOT NULL,
  `range_from` varchar(255) NOT NULL,
  `range_to` varchar(255) NOT NULL,
  `prefix` varchar(255) DEFAULT NULL,
  `gateway` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `total_addresses` varchar(64) DEFAULT NULL,
  `used_addresses` decimal(39,0) DEFAULT NULL,
  `utilisation` decimal(5,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dhcp_range_records_interface_unique` (`integration`,`type`,`interface`,`subnet`,`range_from`,`range_to`) USING HASH,
  KEY `dhcp_range_records_integration_index` (`integration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dhcp_snooping_observations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dhcp_snooping_observations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `switch_config_id` bigint(20) unsigned NOT NULL,
  `vlan` int(10) unsigned NOT NULL DEFAULT 0,
  `ip` varchar(255) NOT NULL,
  `mac` varchar(255) NOT NULL,
  `interface` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `observed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dhcp_snooping_unique` (`switch_config_id`,`vlan`,`ip`,`mac`),
  CONSTRAINT `dhcp_snooping_observations_switch_config_id_foreign` FOREIGN KEY (`switch_config_id`) REFERENCES `switch_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dhcp_sync_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dhcp_sync_states` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `integration` varchar(255) NOT NULL,
  `address_family` varchar(255) NOT NULL,
  `dataset` varchar(255) NOT NULL,
  `empty_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `last_success_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dhcp_sync_states_integration_address_family_dataset_unique` (`integration`,`address_family`,`dataset`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `integration_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `integration` varchar(255) NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` longtext DEFAULT NULL,
  `encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration_configs_integration_key_unique` (`integration`,`key`),
  KEY `integration_configs_integration_index` (`integration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ip_address_mac_address`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_address_mac_address` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address_id` bigint(20) unsigned NOT NULL,
  `mac_address_id` bigint(20) unsigned NOT NULL,
  `source` varchar(255) NOT NULL,
  `last_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address_mac_address_ip_address_id_mac_address_id_unique` (`ip_address_id`,`mac_address_id`),
  KEY `ip_address_mac_address_mac_address_id_foreign` (`mac_address_id`),
  CONSTRAINT `ip_address_mac_address_ip_address_id_foreign` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ip_address_mac_address_mac_address_id_foreign` FOREIGN KEY (`mac_address_id`) REFERENCES `mac_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ip_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `address` varchar(255) NOT NULL,
  `internet_enabled` tinyint(1) DEFAULT NULL,
  `rate_limit_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `dns_filtering_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `comment` varchar(255) DEFAULT NULL,
  `last_seen_at` timestamp NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_addresses_address_unique` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mac_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mac_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mac_address` varchar(17) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mac_addresses_mac_address_unique` (`mac_address`),
  KEY `mac_addresses_user_id_foreign` (`user_id`),
  CONSTRAINT `mac_addresses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pages_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `role_user_user_id_foreign` (`user_id`),
  KEY `role_user_role_id_foreign` (`role_id`),
  CONSTRAINT `role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'String',
  `value` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `switch_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `switch_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `hostname` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'cisco',
  `username` varchar(255) NOT NULL,
  `password` longtext NOT NULL,
  `enable_password` longtext DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `port` int(11) NOT NULL DEFAULT 22,
  `timeout` int(11) NOT NULL DEFAULT 5,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `switch_configs_hostname_unique` (`hostname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `switch_port_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `switch_port_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `switch_port_id` bigint(20) unsigned NOT NULL,
  `config_text` text NOT NULL,
  `config_hash` varchar(255) NOT NULL,
  `interface_output` text DEFAULT NULL,
  `last_fetched_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `switch_port_configs_switch_port_id_foreign` (`switch_port_id`),
  CONSTRAINT `switch_port_configs_switch_port_id_foreign` FOREIGN KEY (`switch_port_id`) REFERENCES `switch_ports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `switch_port_macs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `switch_port_macs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `switch_port_id` bigint(20) unsigned NOT NULL,
  `mac_address` varchar(255) NOT NULL,
  `mac_address_id` bigint(20) unsigned DEFAULT NULL,
  `vlan` smallint(5) unsigned DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `switch_port_macs_switch_port_id_mac_address_vlan_unique` (`switch_port_id`,`mac_address`,`vlan`),
  KEY `switch_port_macs_mac_address_index` (`mac_address`),
  KEY `switch_port_macs_mac_address_id_foreign` (`mac_address_id`),
  CONSTRAINT `switch_port_macs_mac_address_id_foreign` FOREIGN KEY (`mac_address_id`) REFERENCES `mac_addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `switch_port_macs_switch_port_id_foreign` FOREIGN KEY (`switch_port_id`) REFERENCES `switch_ports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `switch_ports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `switch_ports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `switch_config_id` bigint(20) unsigned NOT NULL,
  `port_name` varchar(255) NOT NULL,
  `port_number` varchar(255) NOT NULL,
  `switch_description` varchar(255) DEFAULT NULL,
  `admin_notes` varchar(255) DEFAULT NULL,
  `access_vlan` smallint(5) unsigned DEFAULT NULL,
  `switchport_mode` varchar(255) DEFAULT NULL,
  `speed` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'down',
  `admin_status` varchar(255) NOT NULL DEFAULT 'up',
  `duplex` varchar(255) DEFAULT NULL,
  `poe_status` varchar(255) DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `switch_ports_switch_config_id_port_name_unique` (`switch_config_id`,`port_name`),
  KEY `switch_ports_status_index` (`status`),
  CONSTRAINT `switch_ports_switch_config_id_foreign` FOREIGN KEY (`switch_config_id`) REFERENCES `switch_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `switch_sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `switch_sync_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `switch_config_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `error` text DEFAULT NULL,
  `ports_created` int(10) unsigned NOT NULL DEFAULT 0,
  `ports_updated` int(10) unsigned NOT NULL DEFAULT 0,
  `macs_created` int(10) unsigned NOT NULL DEFAULT 0,
  `macs_updated` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `switch_sync_runs_switch_config_id_status_index` (`switch_config_id`,`status`),
  CONSTRAINT `switch_sync_runs_switch_config_id_foreign` FOREIGN KEY (`switch_config_id`) REFERENCES `switch_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_ip_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_ip_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `ip_address_id` bigint(20) unsigned NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_ip_addresses_user_id_foreign` (`user_id`),
  KEY `user_ip_addresses_ip_address_id_foreign` (`ip_address_id`),
  CONSTRAINT `user_ip_addresses_ip_address_id_foreign` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_ip_addresses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_parameters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_parameters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_parameters_user_id_key_unique` (`user_id`,`key`),
  CONSTRAINT `user_parameters_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nickname` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `internet_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `internet_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `rate_limit_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `dns_filtering_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `weekly_bandwidth` bigint(20) unsigned NOT NULL DEFAULT 0,
  `weekly_received` bigint(20) unsigned NOT NULL DEFAULT 0,
  `weekly_sent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `external_id` varchar(255) DEFAULT NULL,
  `access_token` longtext DEFAULT NULL,
  `refresh_token` longtext DEFAULT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_external_id_unique` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webauthn_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webauthn_credentials` (
  `id` varchar(510) NOT NULL,
  `authenticatable_type` varchar(255) NOT NULL,
  `authenticatable_id` bigint(20) unsigned NOT NULL,
  `user_id` char(36) NOT NULL,
  `alias` varchar(255) DEFAULT NULL,
  `counter` bigint(20) unsigned DEFAULT NULL,
  `rp_id` varchar(255) NOT NULL,
  `origin` varchar(255) NOT NULL,
  `transports` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`transports`)),
  `aaguid` char(36) DEFAULT NULL,
  `public_key` text NOT NULL,
  `attestation_format` varchar(255) NOT NULL DEFAULT 'none',
  `certificates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`certificates`)),
  `disabled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `webauthn_user_index` (`authenticatable_type`,`authenticatable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

/*M!999999\- enable the sandbox mode */ 
SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0000_00_00_000000_create_webauthn_credentials',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0000_00_00_000000_create_websockets_statistics_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2014_10_12_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2019_08_19_000000_create_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2023_09_17_212938_create_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2023_09_17_214801_create_ip_addresses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2023_09_18_190344_create_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2023_09_18_193507_create_role_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2023_09_19_131754_create_auth_providers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2023_09_19_134826_create_user_authentications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2023_09_19_172201_create_user_ip_addresses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2023_10_13_102857_add_recieved_to_ip_addresses',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_04_10_112048_add_theme_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_04_10_115420_create_content_blocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_04_12_125112_add_expires_at_to_ip_addresses',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_04_12_182759_create_mac_addresses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_04_12_182804_add_mac_address_id_to_ip_addresses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_04_13_000001_consolidate_auth_schema',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_04_13_004812_create_integration_configs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_04_13_004956_create_switch_configs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_04_16_082330_create_capability_assignments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_04_16_082330_create_connection_test_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_04_16_094808_add_password_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_04_16_123329_add_response_data_to_connection_test_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_04_16_140000_add_request_response_detail_to_connection_test_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_04_16_200220_create_switch_ports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_04_16_200220_create_switch_sync_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_04_16_200221_create_switch_port_configs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_04_16_200221_create_switch_port_macs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_04_19_000001_add_interface_output_to_switch_port_configs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_04_21_211206_add_grid_columns_to_content_blocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_04_21_211711_create_user_parameters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_04_21_230545_delete_removed_block_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_04_22_151423_rename_policy_columns_and_add_new_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_04_22_215645_create_pages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_04_23_300000_create_network_device_tracking_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_04_25_215416_add_weekly_bandwidth_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_04_25_222233_add_weekly_received_sent_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_04_26_013134_rationalise_capabilities_and_drop_ip_bandwidth_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_04_28_222245_remove_default_librenms_capability_assignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_05_09_000001_make_audit_log_subject_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_06_08_000001_create_dhcp_range_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_06_08_000002_add_integration_to_dhcp_leases',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_06_08_000003_create_dhcp_pool_statuses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_06_08_000004_create_dhcp_sync_states_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_06_08_000005_create_dhcp_snooping_observations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_06_08_000006_make_dhcp_leases_mac_address_id_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_06_09_000001_add_interface_to_dhcp_range_records_unique',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_06_10_000001_normalize_ipv6_addresses_lowercase',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_06_10_100000_dedupe_ip_addresses_and_add_unique_index',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_06_11_000001_change_dhcp_range_records_total_addresses_to_string',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_06_12_000001_add_severity_to_audit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_06_12_000002_drop_system_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_06_18_120000_make_ip_internet_enabled_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_09_28_000001_key_dhcp_leases_on_ip_and_mac',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_09_28_000002_drop_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_09_28_000003_drop_websockets_statistics_entries_table',1);
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

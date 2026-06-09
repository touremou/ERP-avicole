-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : dim. 24 mai 2026 à 01:20
-- Version du serveur : 8.4.7
-- Version de PHP : 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `avismart_dbb`
--
CREATE DATABASE IF NOT EXISTS `avismart_dbb` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `avismart_dbb`;

-- --------------------------------------------------------

--
-- Structure de la table `batches`
--

DROP TABLE IF EXISTS `batches`;
CREATE TABLE IF NOT EXISTS `batches` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_batch_id` bigint UNSIGNED DEFAULT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date DEFAULT NULL,
  `transfer_date` date DEFAULT NULL,
  `building_id` bigint UNSIGNED NOT NULL,
  `allocated_surface` decimal(10,2) DEFAULT NULL,
  `provider_id` bigint UNSIGNED NOT NULL,
  `employee_id` bigint UNSIGNED NOT NULL,
  `code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsible` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('poussiniere','chair','ponte','reproducteur') COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT 'Non spécifié',
  `age_at_arrival` int UNSIGNED NOT NULL DEFAULT '1',
  `avg_weight_start` decimal(8,2) NOT NULL DEFAULT '0.00',
  `qty_alive` int NOT NULL DEFAULT '0',
  `qty_males` int NOT NULL DEFAULT '0',
  `qty_females` int NOT NULL DEFAULT '0',
  `mating_ratio` decimal(8,2) NOT NULL DEFAULT '0.00',
  `qty_dead` int NOT NULL DEFAULT '0',
  `initial_quantity` int UNSIGNED NOT NULL,
  `current_quantity` int UNSIGNED NOT NULL,
  `chick_state` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `behavior` text COLLATE utf8mb4_unicode_ci,
  `vaccination_received` tinyint(1) NOT NULL DEFAULT '0',
  `vaccination_details` text COLLATE utf8mb4_unicode_ci,
  `arrival_date` date DEFAULT NULL,
  `expected_end_date` date NOT NULL,
  `buy_price_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_acquisition_cost` decimal(12,2) UNSIGNED NOT NULL,
  `planned_density` decimal(8,2) NOT NULL DEFAULT '0.00',
  `arrival_mortality_rate` decimal(5,2) UNSIGNED NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Actif',
  `observations` text COLLATE utf8mb4_unicode_ci,
  `photo_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `actual_sell_price_per_unit` decimal(15,2) DEFAULT NULL,
  `additional_costs` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_revenue` decimal(15,2) DEFAULT NULL,
  `margin` decimal(15,2) DEFAULT NULL,
  `closing_date` date DEFAULT NULL,
  `protocol_id` bigint UNSIGNED DEFAULT NULL,
  `production_phase` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'demarrage',
  `current_protocol_id` bigint UNSIGNED DEFAULT NULL,
  `transfer_history` json DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_synced` tinyint(1) NOT NULL DEFAULT '1',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batches_code_unique` (`code`),
  UNIQUE KEY `batches_uuid_unique` (`uuid`),
  KEY `batches_provider_id_foreign` (`provider_id`),
  KEY `batches_employee_id_foreign` (`employee_id`),
  KEY `batches_parent_batch_id_foreign` (`parent_batch_id`),
  KEY `idx_batches_status_type` (`status`,`type`),
  KEY `idx_batches_arrival_date` (`arrival_date`),
  KEY `idx_batches_building_status` (`building_id`,`status`),
  KEY `batches_protocol_id_foreign` (`protocol_id`),
  KEY `batches_current_protocol_id_foreign` (`current_protocol_id`)
) ENGINE=MyISAM AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `batches`
--

INSERT INTO `batches` (`id`, `parent_batch_id`, `uuid`, `start_date`, `transfer_date`, `building_id`, `allocated_surface`, `provider_id`, `employee_id`, `code`, `responsible`, `type`, `model_name`, `age_at_arrival`, `avg_weight_start`, `qty_alive`, `qty_males`, `qty_females`, `mating_ratio`, `qty_dead`, `initial_quantity`, `current_quantity`, `chick_state`, `behavior`, `vaccination_received`, `vaccination_details`, `arrival_date`, `expected_end_date`, `buy_price_per_unit`, `total_acquisition_cost`, `planned_density`, `arrival_mortality_rate`, `status`, `observations`, `photo_path`, `created_at`, `updated_at`, `actual_sell_price_per_unit`, `additional_costs`, `total_revenue`, `margin`, `closing_date`, `protocol_id`, `production_phase`, `current_protocol_id`, `transfer_history`, `deleted_at`, `is_synced`, `last_sync_at`) VALUES
(1, NULL, 'c136d6a8-52da-11f1-826d-025041000001', '2026-03-23', '2026-03-31', 1, NULL, 1, 1, 'LOT-20260331-131337', NULL, 'ponte', 'Isa Brown', 1, 0.04, 2100, 0, 0, 0.00, 100, 2100, 2100, 'Normal', NULL, 0, NULL, '2026-03-23', '2027-09-14', 19000.00, 39900000.00, 0.00, 4.55, 'Actif', NULL, NULL, '2026-03-31 11:14:24', '2026-04-07 09:00:40', 0.00, 0.00, 0.00, NULL, NULL, 2, 'ponte', 2, '[{\"date\": \"2026-03-31\", \"user\": \"Admin Manuel\", \"notes\": \"RAS\", \"new_phase\": \"ponte\", \"old_phase\": \"demarrage\", \"to_building\": \"Batiment-P-2500\", \"from_building\": \"Bâtiment-P-2500\", \"protocol_applied\": \"PONTE STANDARD AFRIQUE (ISA/LOHMANN)\"}, {\"date\": \"2026-03-31\", \"user\": \"Admin Manuel\", \"notes\": \"Transfert standard\", \"new_phase\": \"ponte\", \"old_phase\": \"ponte\", \"to_building\": \"Bâtiment-P-2500\", \"from_building\": \"Batiment-P-2500\", \"protocol_applied\": \"PONTE STANDARD AFRIQUE (ISA/LOHMANN)\"}]', NULL, 1, NULL),
(2, NULL, 'c136dfcd-52da-11f1-826d-025041000001', '2026-04-01', '2026-04-08', 2, NULL, 1, 1, 'LOT-20260401-143137', NULL, 'reproducteur', 'Goliath', 1, 0.04, 1108, 110, 1000, 11.00, 0, 1110, 1108, 'Normal', NULL, 0, NULL, '2026-04-01', '2027-09-23', 18999.00, 21088890.00, 0.00, 0.00, 'Actif', NULL, NULL, '2026-04-01 12:31:59', '2026-04-11 08:47:35', NULL, 0.00, NULL, NULL, NULL, 4, 'reproducteur', 4, '[{\"date\": \"2026-04-08\", \"uuid\": \"d6b4554a-cc0d-4da3-921e-d8d4fa2c7756\", \"notes\": \"Transfert standard via ERP\", \"new_phase\": \"reproducteur\", \"old_phase\": \"demarrage\", \"to_building\": \"Batiment-M-2000\", \"performed_by\": \"Admin Manuel\", \"from_building\": \"Batiment-P-2000\", \"protocol_applied\": \"REPRO/FERMIER RUSTIQUE (SASSO/KABIR)\"}, {\"date\": \"2026-04-08\", \"uuid\": \"c3074603-0a84-46f5-9609-c77a8171096a\", \"notes\": \"Mutation avec mise en vide sanitaire du bâtiment précédent\", \"new_phase\": \"reproducteur\", \"old_phase\": \"reproducteur\", \"to_building\": \"Batiment-P-2000\", \"performed_by\": \"Admin Manuel\", \"from_building\": \"Batiment-M-2000\", \"protocol_applied\": \"REPRO/FERMIER RUSTIQUE (SASSO/KABIR)\"}]', NULL, 1, NULL),
(3, NULL, 'c136e0e3-52da-11f1-826d-025041000001', '2026-03-30', NULL, 3, NULL, 1, 2, 'LOT-20260405-111726', NULL, 'chair', 'Cobb 500', 1, 0.04, 1000, 0, 0, 0.00, 0, 1000, 995, 'Normal', NULL, 0, NULL, '2026-03-30', '2026-05-14', 18000.00, 18000000.00, 0.00, 0.00, 'Actif', NULL, NULL, '2026-04-05 09:18:06', '2026-05-19 13:16:23', 0.00, 0.00, 0.00, NULL, NULL, 3, 'demarrage', 3, NULL, NULL, 1, NULL),
(4, NULL, 'c136e1e2-52da-11f1-826d-025041000001', '2026-04-09', NULL, 2, NULL, 2, 1, 'LOT-20260409-131317', NULL, 'ponte', 'Isa Brown', 1, 0.04, 498, 0, 0, 0.00, 0, 500, 496, 'Normal', NULL, 0, NULL, '2026-04-09', '2027-10-01', 19000.00, 9500000.00, 0.00, 0.00, 'Actif', NULL, NULL, '2026-04-09 11:13:55', '2026-05-19 13:09:16', NULL, 0.00, NULL, NULL, NULL, 1, 'demarrage', 1, NULL, NULL, 1, NULL),
(5, NULL, 'c136e2c4-52da-11f1-826d-025041000001', '2026-04-06', NULL, 2, NULL, 1, 1, 'LOT-20260409-134306', NULL, 'reproducteur', 'Goliath', 1, 0.04, 220, 0, 0, 0.00, 0, 220, 220, 'Normal', NULL, 0, NULL, '2026-04-06', '2027-06-30', 20000.00, 4400000.00, 0.00, 0.00, 'Actif', NULL, NULL, '2026-04-09 11:44:28', '2026-04-09 11:44:28', NULL, 0.00, NULL, NULL, NULL, NULL, 'demarrage', 4, NULL, NULL, 1, NULL),
(6, NULL, 'c136e3ac-52da-11f1-826d-025041000001', '2026-04-09', NULL, 2, NULL, 1, 1, 'LOT-20260409-144030', NULL, 'chair', 'Cobb 500', 1, 0.04, 100, 0, 0, 0.00, 0, 100, 100, 'Normal', NULL, 0, NULL, '2026-04-09', '2026-05-24', 19000.00, 1900000.00, 0.00, 0.00, 'Actif', NULL, NULL, '2026-04-09 12:41:20', '2026-04-09 12:41:20', NULL, 0.00, NULL, NULL, NULL, NULL, 'demarrage', 3, NULL, NULL, 1, NULL),
(7, NULL, 'c136e4c3-52da-11f1-826d-025041000001', '2026-04-09', NULL, 1, NULL, 1, 1, 'LOT-20260409-144553', NULL, 'ponte', 'Isa Brown', 1, 0.04, 0, 0, 0, 0.00, 0, 100, 0, 'Normal', NULL, 0, NULL, '2026-04-09', '2027-10-01', 19000.00, 1900000.00, 0.00, 0.00, 'Terminé', NULL, NULL, '2026-04-09 12:46:18', '2026-04-09 12:55:12', 20000.00, 0.00, 2000000.00, 100000.00, '2026-04-09', NULL, 'demarrage', 1, NULL, NULL, 1, NULL),
(8, NULL, 'c136e5aa-52da-11f1-826d-025041000001', '2026-04-09', NULL, 3, NULL, 1, 1, 'LOT-20260409-145601', NULL, 'chair', 'Cobb 500', 1, 0.04, 500, 0, 0, 0.00, 0, 500, 498, 'Normal', NULL, 0, NULL, '2026-04-09', '2026-05-24', 18000.00, 9000000.00, 0.00, 0.00, 'Actif', NULL, NULL, '2026-04-09 12:56:43', '2026-05-19 12:28:19', NULL, 0.00, NULL, NULL, NULL, 3, 'demarrage', 3, NULL, NULL, 1, NULL),
(9, NULL, 'c136e697-52da-11f1-826d-025041000001', '2026-04-01', NULL, 4, NULL, 1, 1, 'LOT-20260409-154815', NULL, 'chair', 'Cobb 500', 1, 0.04, 0, 0, 0, 0.00, 0, 700, 0, 'Normal', NULL, 0, NULL, '2026-04-01', '2026-05-16', 18000.00, 12600000.00, 0.00, 0.00, 'Terminé', NULL, NULL, '2026-04-09 13:49:01', '2026-05-18 11:08:52', 35000.00, 0.00, 24500000.00, 11900000.00, '2026-05-18', 3, 'demarrage', 3, NULL, NULL, 1, NULL),
(10, NULL, 'c136e779-52da-11f1-826d-025041000001', '2026-04-09', NULL, 4, 100.00, 1, 1, 'LOT-20260409-160842', NULL, 'ponte', 'Isa Brown', 1, 0.04, 299, 0, 0, 0.00, 0, 300, 299, 'Normal', NULL, 0, NULL, '2026-04-09', '2027-10-01', 19000.00, 5700000.00, 0.00, 0.00, 'Actif', NULL, NULL, '2026-04-09 14:09:09', '2026-04-20 16:05:30', NULL, 0.00, NULL, NULL, NULL, 1, 'demarrage', 1, NULL, NULL, 1, NULL),
(11, NULL, '35298450-f82b-4323-bdc4-4544e8d71a58', NULL, NULL, 1, 50.00, 1, 1, 'TEST-082058', 'Moussa', 'chair', 'Cobb 500', 1, 0.00, 0, 0, 0, 0.00, 5, 100, 100, 'Normal', NULL, 0, NULL, '2026-05-19', '2026-07-03', 3500.00, 350000.00, 0.00, 4.76, 'Actif', NULL, NULL, '2026-05-19 06:20:58', '2026-05-19 07:10:05', NULL, 0.00, NULL, NULL, NULL, 1, 'demarrage', 1, NULL, NULL, 1, NULL),
(12, NULL, '09ab8a4e-bb2e-49ee-b7cc-4a86429112c4', NULL, '2026-05-21', 1, 40.00, 1, 1, 'LOT-20260519-093526', 'Moussa', 'ponte', 'Isa Brown', 1, 0.00, 0, 0, 0, 0.00, 2, 200, 197, 'Normal', NULL, 0, NULL, '2026-05-18', '2027-11-09', 19000.00, 3800000.00, 5.00, 0.99, 'Actif', NULL, NULL, '2026-05-19 07:36:14', '2026-05-22 15:21:38', NULL, 0.00, NULL, NULL, NULL, 1, 'ponte', 1, '[{\"date\": \"2026-05-21\", \"uuid\": \"b550b125-9b9e-46d0-9969-9da235f1777e\", \"notes\": null, \"new_phase\": \"ponte\", \"old_phase\": \"demarrage\", \"to_building\": \"Bâtiment-P-2500\", \"performed_by\": \"Moussa Touré\", \"from_building\": \"Batiment-M-2000\", \"to_building_id\": 1, \"from_building_id\": 4, \"protocol_applied\": \"CYCLE ÉLEVAGE ISA BROWN GUINÉE\", \"quantity_at_transfer\": 197}]', NULL, 1, NULL),
(13, NULL, '3ec9567b-2e2f-4904-a36e-3c549b963a95', NULL, NULL, 5, NULL, 3, 1, 'EXT-AVICO', NULL, 'reproducteur', 'Non spécifié', 1, 0.00, 0, 0, 0, 0.00, 0, 0, 0, 'Normal', NULL, 0, NULL, '2026-05-23', '2027-08-16', 0.00, 0.00, 0.00, 0.00, 'Actif', NULL, NULL, '2026-05-23 09:44:50', '2026-05-23 09:44:50', NULL, 0.00, NULL, NULL, NULL, NULL, 'Attente/Incubation', NULL, NULL, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `batch_tasks`
--

DROP TABLE IF EXISTS `batch_tasks`;
CREATE TABLE IF NOT EXISTS `batch_tasks` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` bigint UNSIGNED NOT NULL,
  `action_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Eau de boisson',
  `day_number` int NOT NULL,
  `planned_date` date NOT NULL,
  `is_completed` tinyint(1) NOT NULL DEFAULT '0',
  `is_system_generated` tinyint(1) NOT NULL DEFAULT '0',
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_signature` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_batch_tasks_planned` (`batch_id`,`planned_date`,`is_completed`)
) ENGINE=MyISAM AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `batch_tasks`
--

INSERT INTO `batch_tasks` (`id`, `batch_id`, `action_name`, `type`, `method`, `day_number`, `planned_date`, `is_completed`, `is_system_generated`, `completed_at`, `cancelled_at`, `cancellation_reason`, `operator_signature`, `created_at`, `updated_at`) VALUES
(18, 1, 'Déparasitage (Lévamisole)', 'Traitement', 'Eau de boisson', 42, '2026-05-12', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(17, 1, 'Variole Aviaire (Transfixion)', 'Vaccin', 'Injection', 35, '2026-05-05', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(16, 1, 'Anti-Coccidien (Amprolium)', 'Traitement', 'Eau de boisson', 28, '2026-04-28', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(15, 1, 'Newcastle Lasota (Rappel)', 'Vaccin', 'Eau de boisson', 21, '2026-04-21', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(14, 1, 'Gumboro (Rappel obligatoire)', 'Vaccin', 'Eau de boisson', 14, '2026-04-14', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(13, 1, 'Gumboro (Premier passage)', 'Vaccin', 'Eau de boisson', 7, '2026-04-07', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(12, 1, 'Newcastle + Bronchite HB1', 'Vaccin', 'Oculaire', 3, '2026-04-03', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(11, 1, 'Réception + Anti-stress (Vitamines)', 'Vitamine', 'Eau de boisson', 1, '2026-04-01', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(19, 1, 'Vaccin Coryza Infectieux', 'Vaccin', 'Injection', 90, '2026-06-29', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(20, 1, 'Newcastle Inactivé (Oil)', 'Vaccin', 'Injection', 112, '2026-07-21', 0, 0, NULL, NULL, NULL, NULL, '2026-03-31 11:20:45', '2026-03-31 11:20:45'),
(31, 2, 'Variole Aviaire', 'Vaccin', 'Injection', 45, '2026-05-23', 0, 0, NULL, NULL, NULL, NULL, '2026-04-08 19:50:28', '2026-04-08 19:50:28'),
(30, 2, 'Rappel Newcastle Lasota', 'Vaccin', 'Eau de boisson', 21, '2026-04-29', 0, 0, NULL, NULL, NULL, NULL, '2026-04-08 19:50:28', '2026-04-08 19:50:28'),
(29, 2, 'Gumboro', 'Vaccin', 'Eau de boisson', 14, '2026-04-22', 0, 0, NULL, NULL, NULL, NULL, '2026-04-08 19:50:28', '2026-04-08 19:50:28'),
(28, 2, 'Newcastle HB1', 'Vaccin', 'Oculaire', 7, '2026-04-15', 0, 0, NULL, NULL, NULL, NULL, '2026-04-08 19:50:28', '2026-04-08 19:50:28'),
(27, 2, 'Démarrage Vitamines', 'Vitamine', 'Eau de boisson', 1, '2026-04-09', 0, 0, NULL, NULL, NULL, NULL, '2026-04-08 19:50:28', '2026-04-08 19:50:28'),
(32, 2, 'Déparasitage global', 'Traitement', 'Eau de boisson', 60, '2026-06-07', 0, 0, NULL, NULL, NULL, NULL, '2026-04-08 19:50:28', '2026-04-08 19:50:28'),
(33, 12, 'Réception + Vitamines Hydratation', 'Vitamine', 'Eau de boisson', 1, '2026-05-20', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(34, 12, 'Vaccin Newcastle + Bronchite (HB1)', 'Vaccin', 'Oculaire', 3, '2026-05-22', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(35, 12, 'Vaccin Gumboro (Intermédiaire)', 'Vaccin', 'Eau de boisson', 7, '2026-05-26', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(36, 12, 'Vitamines Anti-stress', 'Vitamine', 'Eau de boisson', 10, '2026-05-29', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(37, 12, 'Rappel Gumboro', 'Vaccin', 'Eau de boisson', 14, '2026-06-02', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(38, 12, 'Rappel Newcastle + Bronchite (Lasota)', 'Vaccin', 'Eau de boisson', 21, '2026-06-09', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(39, 12, 'Traitement Coccidiose (Préventif)', 'Traitement', 'Eau de boisson', 28, '2026-06-16', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(40, 12, 'Vaccin Variole Aviaire', 'Vaccin', 'Injection', 35, '2026-06-23', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(41, 12, 'Déparasitage interne', 'Traitement', 'Eau de boisson', 42, '2026-06-30', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(42, 12, 'Vaccin Coryza Contagieux', 'Vaccin', 'Injection', 70, '2026-07-28', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(43, 12, 'Rappel Newcastle Inactivé (Avant Ponte)', 'Vaccin', 'Injection', 112, '2026-09-08', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(44, 12, 'Transfert vers bâtiment de ponte + Calcium', 'Vitamine', 'Aliment', 126, '2026-09-22', 0, 0, NULL, NULL, NULL, NULL, '2026-05-19 07:36:14', '2026-05-19 07:36:14'),
(45, 12, 'Réception + Vitamines Hydratation', 'Vitamine', 'Eau de boisson', 1, '2026-05-22', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(46, 12, 'Vaccin Newcastle + Bronchite (HB1)', 'Vaccin', 'Oculaire', 3, '2026-05-24', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(47, 12, 'Vaccin Gumboro (Intermédiaire)', 'Vaccin', 'Eau de boisson', 7, '2026-05-28', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(48, 12, 'Vitamines Anti-stress', 'Vitamine', 'Eau de boisson', 10, '2026-05-31', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(49, 12, 'Rappel Gumboro', 'Vaccin', 'Eau de boisson', 14, '2026-06-04', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(50, 12, 'Rappel Newcastle + Bronchite (Lasota)', 'Vaccin', 'Eau de boisson', 21, '2026-06-11', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(51, 12, 'Traitement Coccidiose (Préventif)', 'Traitement', 'Eau de boisson', 28, '2026-06-18', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(52, 12, 'Vaccin Variole Aviaire', 'Vaccin', 'Injection', 35, '2026-06-25', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(53, 12, 'Déparasitage interne', 'Traitement', 'Eau de boisson', 42, '2026-07-02', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(54, 12, 'Vaccin Coryza Contagieux', 'Vaccin', 'Injection', 70, '2026-07-30', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(55, 12, 'Rappel Newcastle Inactivé (Avant Ponte)', 'Vaccin', 'Injection', 112, '2026-09-10', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59'),
(56, 12, 'Transfert vers bâtiment de ponte + Calcium', 'Vitamine', 'Aliment', 126, '2026-09-24', 0, 1, NULL, NULL, NULL, NULL, '2026-05-21 10:43:59', '2026-05-21 10:43:59');

-- --------------------------------------------------------

--
-- Structure de la table `buildings`
--

DROP TABLE IF EXISTS `buildings`;
CREATE TABLE IF NOT EXISTS `buildings` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Vide',
  `capacity` int NOT NULL,
  `surface` decimal(8,2) NOT NULL DEFAULT '0.00',
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `disinfection_started_at` timestamp NULL DEFAULT NULL,
  `min_sanitary_days` int NOT NULL DEFAULT '14',
  `max_sanitary_days` int NOT NULL DEFAULT '21',
  PRIMARY KEY (`id`),
  KEY `idx_buildings_status` (`status`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `buildings`
--

INSERT INTO `buildings` (`id`, `name`, `type`, `status`, `capacity`, `surface`, `description`, `created_at`, `updated_at`, `deleted_at`, `disinfection_started_at`, `min_sanitary_days`, `max_sanitary_days`) VALUES
(1, 'Bâtiment-P-2500', 'ponte', 'Occupé', 2500, 250.00, NULL, '2026-03-31 09:34:36', '2026-04-05 09:38:41', NULL, NULL, 14, 21),
(2, 'Batiment-P-2000', 'mixte', 'Occupé', 2000, 250.00, NULL, '2026-03-31 09:35:50', '2026-04-08 19:50:28', NULL, NULL, 14, 21),
(3, 'Batiment-C-2000', 'chair', 'Occupé', 2000, 200.00, NULL, '2026-04-05 08:43:09', '2026-04-05 09:37:55', NULL, NULL, 14, 21),
(4, 'Batiment-M-2000', 'mixte', 'Occupé', 2000, 200.00, NULL, '2026-04-08 16:27:50', '2026-04-09 13:49:01', NULL, NULL, 14, 21),
(5, 'Zone Fournisseurs Externes', 'reproducteur', 'Vide', 999999, 1.00, 'Bâtiment virtuel de transit pour le traçage des achats externes.', '2026-05-23 08:48:32', '2026-05-23 08:48:32', NULL, NULL, 14, 21);

-- --------------------------------------------------------

--
-- Structure de la table `cache`
--

DROP TABLE IF EXISTS `cache`;
CREATE TABLE IF NOT EXISTS `cache` (
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
CREATE TABLE IF NOT EXISTS `cache_locks` (
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `daily_checks`
--

DROP TABLE IF EXISTS `daily_checks`;
CREATE TABLE IF NOT EXISTS `daily_checks` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_id` bigint UNSIGNED NOT NULL,
  `check_date` date NOT NULL,
  `mortality` int UNSIGNED NOT NULL DEFAULT '0',
  `feed_consumed` decimal(8,2) UNSIGNED NOT NULL DEFAULT '0.00',
  `feed_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `water_consumed` decimal(8,2) UNSIGNED DEFAULT NULL,
  `avg_weight` decimal(8,3) UNSIGNED DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `observations` text COLLATE utf8mb4_unicode_ci,
  `treatment_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `treatment_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `temp_min` decimal(5,2) DEFAULT NULL,
  `temp_max` decimal(5,2) DEFAULT NULL,
  `humidity` decimal(5,2) DEFAULT NULL,
  `qty_quarantine_in` int NOT NULL DEFAULT '0',
  `qty_quarantine_out` int NOT NULL DEFAULT '0',
  `qty_sorted_out` int NOT NULL DEFAULT '0',
  `litter_changed` tinyint(1) NOT NULL DEFAULT '0',
  `health_status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Normal',
  `is_synced` tinyint(1) NOT NULL DEFAULT '1',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `daily_checks_batch_id_check_date_unique` (`batch_id`,`check_date`),
  UNIQUE KEY `unique_daily_check_per_batch_day` (`batch_id`,`check_date`),
  KEY `daily_checks_uuid_index` (`uuid`),
  KEY `daily_checks_is_synced_index` (`is_synced`),
  KEY `idx_daily_checks_check_date` (`check_date`),
  KEY `idx_daily_checks_batch_date` (`batch_id`,`check_date`)
) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `daily_checks`
--

INSERT INTO `daily_checks` (`id`, `uuid`, `batch_id`, `check_date`, `mortality`, `feed_consumed`, `feed_type`, `water_consumed`, `avg_weight`, `temperature`, `observations`, `treatment_type`, `treatment_name`, `created_at`, `updated_at`, `temp_min`, `temp_max`, `humidity`, `qty_quarantine_in`, `qty_quarantine_out`, `qty_sorted_out`, `litter_changed`, `health_status`, `is_synced`, `last_sync_at`, `deleted_at`) VALUES
(1, 'ac14d1ee-80a2-4f35-b3e0-f429d077cbe0', 1, '2026-03-31', 1, 20.00, 'Démarrage', 100.00, 0.030, NULL, 'RAS', 'Antibiotique', 'Amintotal', '2026-03-31 14:53:51', '2026-03-31 14:53:51', 20.00, 30.00, 10.00, 1, 2, 0, 1, 'Normal', 1, '2026-04-12 15:08:19', NULL),
(2, 'a11a9b8a-0375-4e3c-bae3-86e8d55e4354', 1, '2026-04-03', 0, 5.00, 'Ponte 1 (Pic de ponte)', 0.00, NULL, NULL, NULL, NULL, NULL, '2026-04-03 09:02:01', '2026-04-03 12:55:47', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, '2026-04-12 15:08:19', NULL),
(3, '84bf57f3-f302-4e89-9bbf-c3a075744086', 2, '2026-04-03', 1, 50.00, 'Ponte Croissance (Poulette)', 150.00, 0.070, NULL, 'RAS', 'Antibiotique', 'Oxy500', '2026-04-03 13:06:53', '2026-04-03 20:23:32', 20.00, 30.00, 10.00, 2, 1, 0, 1, 'Normal', 1, '2026-04-12 15:08:19', NULL),
(5, NULL, 4, '2026-04-12', 2, 0.00, 'Ponte Démarrage (Poussin)', 0.00, NULL, NULL, NULL, NULL, NULL, '2026-04-12 15:27:51', '2026-04-12 15:27:51', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, NULL, NULL),
(6, NULL, 10, '2026-04-20', 1, 0.00, 'Ponte Démarrage (Poussin)', 0.00, NULL, NULL, NULL, NULL, NULL, '2026-04-20 16:05:30', '2026-04-20 16:05:30', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, NULL, NULL),
(7, '9c017867-04cc-4e5f-8097-0946c269aff9', 11, '2026-05-19', 7, 0.00, 'Chair Démarrage', 0.00, NULL, NULL, NULL, NULL, NULL, '2026-05-19 07:08:31', '2026-05-19 07:27:20', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, NULL, NULL),
(8, '595689bf-7da3-43cf-a647-b48f34386b3c', 12, '2026-05-19', 2, 0.00, 'Ponte Démarrage (Poussin)', 0.00, NULL, NULL, NULL, NULL, NULL, '2026-05-19 07:36:50', '2026-05-19 07:36:50', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, NULL, NULL),
(9, NULL, 3, '2026-05-19', 5, 10.00, 'Test', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-19 11:37:07', '2026-05-19 11:37:07', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, NULL, NULL),
(10, NULL, 8, '2026-05-19', 2, 0.00, 'Chair Finition', 0.00, NULL, NULL, NULL, NULL, NULL, '2026-05-19 12:28:19', '2026-05-19 12:28:19', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, NULL, NULL),
(11, NULL, 4, '2026-05-19', 3, 55.00, 'Ponte Croissance (Poulette)', 200.00, 0.050, NULL, 'RAS', 'Vaccin', 'Gomboro', '2026-05-19 12:28:50', '2026-05-19 13:09:16', 20.00, 30.00, 10.00, 1, 2, 0, 0, 'Normal', 1, NULL, NULL),
(12, NULL, 3, '2026-01-15', 8, 10.00, 'Test', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-19 13:16:22', '2026-05-19 13:16:23', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, NULL, '2026-05-19 13:16:23'),
(13, NULL, 12, '2026-05-20', 2, 25.00, 'Ponte Démarrage (Poussin)', 200.00, 0.058, NULL, 'RAS', 'Antibiotique', 'Gomboro', '2026-05-20 20:47:13', '2026-05-20 20:47:13', 10.00, 20.00, 10.00, 1, 2, 0, 0, 'Normal', 1, NULL, NULL),
(14, NULL, 12, '2026-05-21', 2, 15.00, 'Ponte 1 (Pic de ponte)', 150.00, NULL, NULL, NULL, NULL, NULL, '2026-05-21 10:04:15', '2026-05-21 10:04:15', NULL, NULL, NULL, 0, 0, 0, 0, 'Normal', 1, NULL, NULL),
(15, NULL, 12, '2026-05-22', 0, 40.00, 'Ponte Démarrage (Poussin)', 100.00, 0.060, NULL, 'RAS', NULL, NULL, '2026-05-22 07:40:09', '2026-05-22 15:22:42', NULL, NULL, NULL, 0, 0, 0, 1, 'Normal', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `egg_movements`
--

DROP TABLE IF EXISTS `egg_movements`;
CREATE TABLE IF NOT EXISTS `egg_movements` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` enum('vente','don','ajustement','casse_magasin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `grade` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `observations` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `egg_productions`
--

DROP TABLE IF EXISTS `egg_productions`;
CREATE TABLE IF NOT EXISTS `egg_productions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_id` bigint UNSIGNED NOT NULL,
  `production_date` date NOT NULL,
  `total_eggs_collected` int NOT NULL DEFAULT '0',
  `broken_eggs` int NOT NULL DEFAULT '0',
  `small_eggs` int NOT NULL DEFAULT '0',
  `incubable_eggs` int NOT NULL DEFAULT '0',
  `grade_xl` decimal(10,2) NOT NULL DEFAULT '0.00',
  `grade_l` decimal(10,2) NOT NULL DEFAULT '0.00',
  `grade_m` decimal(10,2) NOT NULL DEFAULT '0.00',
  `grade_s` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_graded` tinyint(1) NOT NULL DEFAULT '0',
  `laying_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `observations` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_synced` tinyint(1) NOT NULL DEFAULT '1',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `egg_productions_batch_id_production_date_index` (`batch_id`,`production_date`),
  KEY `egg_productions_uuid_index` (`uuid`),
  KEY `egg_productions_is_synced_index` (`is_synced`),
  KEY `idx_egg_productions_batch_date` (`batch_id`,`production_date`),
  KEY `idx_egg_productions_graded` (`is_graded`)
) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `egg_productions`
--

INSERT INTO `egg_productions` (`id`, `uuid`, `batch_id`, `production_date`, `total_eggs_collected`, `broken_eggs`, `small_eggs`, `incubable_eggs`, `grade_xl`, `grade_l`, `grade_m`, `grade_s`, `is_graded`, `laying_rate`, `observations`, `created_at`, `updated_at`, `is_synced`, `last_sync_at`, `deleted_at`) VALUES
(1, '9dcaed7d-81f0-4a09-b21c-9cc900ae0e49', 1, '2026-03-31', 103, 2, 5, 0, 0.00, 0.00, 0.00, 3.20, 1, 4.90, NULL, '2026-03-31 14:58:12', '2026-03-31 15:05:32', 1, '2026-04-12 15:08:19', NULL),
(2, '2b9b4d83-e151-4905-9540-f10f633447b1', 1, '2026-04-01', 300, 2, 3, 0, 1.00, 5.00, 1.83, 2.00, 1, 14.29, NULL, '2026-04-01 07:12:06', '2026-04-01 07:13:29', 1, '2026-04-12 15:08:19', NULL),
(3, '6bbda730-bbdd-4fc9-8251-b05118ed0e25', 2, '2026-04-01', 162, 15, 20, 0, 0.23, 2.00, 1.00, 1.00, 1, 14.73, NULL, '2026-04-01 17:06:49', '2026-04-01 17:44:51', 1, '2026-04-12 15:08:19', NULL),
(4, '7ca5f8df-0e17-4821-a973-220a0e865e78', 1, '2026-04-01', 601, 7, 8, 0, 0.53, 11.00, 6.00, 2.00, 1, 28.62, NULL, '2026-04-01 17:26:36', '2026-04-01 17:28:29', 1, '2026-04-12 15:08:19', NULL),
(5, '8be3a67f-e37f-40bd-92c1-b2b0aab79cb1', 2, '2026-04-01', 60, 21, 8, 0, 1.00, 0.00, 0.00, 0.03, 1, 0.00, NULL, '2026-04-01 18:06:51', '2026-04-01 18:08:33', 1, '2026-04-12 15:08:19', NULL),
(6, '23eb8ad2-c677-4cf9-bb33-9853d970815f', 1, '2026-04-01', 312, 12, 16, 0, 3.00, 3.00, 1.47, 2.00, 1, 14.86, NULL, '2026-04-01 18:56:10', '2026-04-01 18:58:34', 1, '2026-04-12 15:08:19', NULL),
(7, 'caff483b-93b4-4fe6-9c49-2fa0a93632d4', 2, '2026-04-01', 305, 12, 11, 0, 2.00, 2.00, 3.00, 2.40, 1, 0.00, NULL, '2026-04-01 19:04:34', '2026-04-01 19:05:19', 1, '2026-04-12 15:08:19', NULL),
(8, '41211bd9-af63-400c-93e6-1f0e58304ea1', 1, '2026-04-06', 300, 1, 2, 0, 0.00, 2.00, 5.00, 2.90, 1, 15.01, NULL, '2026-04-06 08:14:12', '2026-04-06 08:15:03', 1, '2026-04-12 15:08:19', NULL),
(9, '6d704562-060b-4cd3-884f-2959986d59e6', 1, '2026-04-08', 90, 1, 1, 0, 0.03, 1.00, 1.00, 0.90, 1, 4.29, NULL, '2026-04-08 14:20:33', '2026-04-08 14:21:47', 1, '2026-04-12 15:08:19', NULL),
(10, '0a73cd4d-c5a6-49aa-bcd2-232eea8a63f0', 1, '2026-04-11', 90, 11, 9, 0, 0.00, 2.00, 0.33, 0.00, 1, 4.29, NULL, '2026-04-11 13:14:31', '2026-05-20 07:57:45', 1, '2026-04-12 15:08:19', NULL),
(11, NULL, 12, '2026-05-21', 208, 10, 8, 0, 2.00, 0.33, 2.00, 2.00, 1, 105.58, NULL, '2026-05-21 10:45:23', '2026-05-21 12:30:42', 1, NULL, NULL),
(12, NULL, 1, '2026-05-21', 139, 2, 3, 0, 0.10, 2.03, 0.33, 2.00, 1, 6.62, NULL, '2026-05-21 12:31:25', '2026-05-21 14:59:12', 1, NULL, NULL),
(14, NULL, 4, '2026-05-21', 30, 0, 0, 0, 0.00, 1.00, 0.00, 0.00, 1, 6.05, NULL, '2026-05-21 15:14:22', '2026-05-21 15:25:16', 1, NULL, NULL),
(15, NULL, 1, '2026-05-23', 102, 8, 4, 0, 0.00, 1.00, 1.00, 1.00, 1, 4.86, '[Nouveau passage]', '2026-05-23 11:24:17', '2026-05-23 11:25:18', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `employees`
--

DROP TABLE IF EXISTS `employees`;
CREATE TABLE IF NOT EXISTS `employees` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gender` enum('M','F') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'M',
  `birth_date` date DEFAULT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contract_type` enum('CDI','CDD','Journalier') COLLATE utf8mb4_unicode_ci NOT NULL,
  `hire_date` date NOT NULL,
  `salary` decimal(12,2) DEFAULT '0.00',
  `emergency_contact_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_phone` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cv_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Actif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employees_employee_id_unique` (`employee_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `employees`
--

INSERT INTO `employees` (`id`, `employee_id`, `last_name`, `first_name`, `gender`, `birth_date`, `phone`, `email`, `job_title`, `department`, `contract_type`, `hire_date`, `salary`, `emergency_contact_name`, `emergency_contact_phone`, `photo_path`, `cv_path`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'EMP-2026-001', 'Touré', 'Moussa', 'M', NULL, '+3366600001', NULL, 'Technicien', 'Elevage', 'CDI', '2026-03-31', 1000000.00, NULL, NULL, 'employees/photos/Yl7bsyOZ2t1PFw7gbvgyrcfftNSGSnbsdpSqcAwK.jpg', 'employees/cvs/QiXUSGzsAVuri07hGq7Jp019anBiEpKJA6GSbM3z.pdf', 'Actif', '2026-03-31 11:11:01', '2026-04-11 12:47:21', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `feed_purchases`
--

DROP TABLE IF EXISTS `feed_purchases`;
CREATE TABLE IF NOT EXISTS `feed_purchases` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` bigint UNSIGNED NOT NULL,
  `purchase_date` date NOT NULL,
  `feed_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KG',
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `supplier` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_feed_purchases_batch` (`batch_id`,`purchase_date`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `feed_purchases`
--

INSERT INTO `feed_purchases` (`id`, `batch_id`, `purchase_date`, `feed_type`, `quantity`, `unit`, `unit_price`, `total_price`, `supplier`, `created_at`, `updated_at`) VALUES
(1, 1, '2026-03-31', 'Croissance', 300.00, 'KG', 120.00, 36000.00, 'BioCrest', '2026-03-31 13:45:27', '2026-03-31 14:52:41'),
(2, 1, '2026-03-31', 'Croissance', 100.00, 'KG', 120.00, 12000.00, 'BioCrest', '2026-03-31 13:59:24', '2026-03-31 13:59:24'),
(3, 1, '2026-03-31', 'Croissance', 2.00, 'Sac', 200000.00, 200000.00, 'BioCrest', '2026-03-31 14:23:57', '2026-03-31 14:23:57'),
(4, 1, '2026-03-31', 'Démarrage', 100.00, 'KG', 200000.00, 200000.00, 'BioCrest', '2026-03-31 14:24:31', '2026-03-31 14:24:31'),
(5, 1, '2026-03-31', 'Ponte', 10.00, 'Sac', 250000.00, 250000.00, 'BioCrest', '2026-03-31 14:24:51', '2026-03-31 14:24:51'),
(8, 2, '2026-04-03', 'Vaccin Gomboro', 1.00, 'Boîte', 200000.00, 200000.00, 'BioCrest', '2026-04-03 08:39:13', '2026-04-03 08:39:13'),
(7, 2, '2026-04-03', 'Ponte 1 (Pic de ponte)', 20.00, 'Sac', 5000000.00, 5000000.00, 'BioCrest', '2026-04-03 08:13:52', '2026-04-03 08:13:52');

-- --------------------------------------------------------

--
-- Structure de la table `food_norms`
--

DROP TABLE IF EXISTS `food_norms`;
CREATE TABLE IF NOT EXISTS `food_norms` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `animal_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phase` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_em` decimal(8,2) NOT NULL COMMENT 'Énergie Métabolisable kcal/kg',
  `target_pb` decimal(5,2) NOT NULL COMMENT 'Protéine Brute %',
  `target_lys` decimal(5,2) NOT NULL COMMENT 'Lysine %',
  `target_meth` decimal(5,2) NOT NULL COMMENT 'Méthionine %',
  `target_ca` decimal(5,2) NOT NULL COMMENT 'Calcium %',
  `target_p` decimal(5,2) NOT NULL COMMENT 'Phosphore %',
  `target_price_kg` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `food_norms`
--

INSERT INTO `food_norms` (`id`, `name`, `animal_type`, `phase`, `target_em`, `target_pb`, `target_lys`, `target_meth`, `target_ca`, `target_p`, `target_price_kg`, `is_active`, `created_at`, `updated_at`) VALUES
(14, 'CHAIR DEMARRAGE', 'Broiler', 'Démarrage', 3000.00, 22.00, 1.20, 0.50, 1.00, 0.45, 6500.00, 1, '2026-04-03 14:12:37', '2026-04-03 14:12:37'),
(15, 'CHAIR CROISSANCE', 'Broiler', 'Croissance', 3100.00, 20.00, 1.10, 0.45, 0.90, 0.40, 6000.00, 1, '2026-04-03 14:12:37', '2026-04-03 14:12:37'),
(16, 'CHAIR FINITION', 'Broiler', 'Finition', 3200.00, 18.50, 1.00, 0.40, 0.85, 0.35, 5800.00, 1, '2026-04-03 14:12:37', '2026-04-03 14:12:37'),
(17, 'PONTE POUSSIN', 'Layer', 'Poussin', 2850.00, 20.00, 1.05, 0.42, 1.05, 0.48, 6200.00, 1, '2026-04-03 14:12:37', '2026-04-03 14:12:37'),
(18, 'PONTE POULETTE', 'Layer', 'Poulette', 2750.00, 16.00, 0.75, 0.35, 1.10, 0.40, 5500.00, 1, '2026-04-03 14:12:37', '2026-04-03 14:12:37'),
(19, 'PONTE PIC', 'Layer', 'Ponte 1', 2800.00, 17.50, 0.85, 0.40, 3.80, 0.42, 5900.00, 1, '2026-04-03 14:12:37', '2026-04-03 14:12:37'),
(20, 'PONTE ENTRETIEN', 'Layer', 'Ponte 2', 2750.00, 16.50, 0.78, 0.38, 4.20, 0.38, 5700.00, 1, '2026-04-03 14:12:37', '2026-04-03 14:12:37'),
(21, 'CHAIR DEMARRAGE', 'Broiler', 'Démarrage', 3000.00, 22.00, 1.20, 0.50, 1.00, 0.45, 6500.00, 1, '2026-04-03 14:37:30', '2026-04-03 14:37:30'),
(22, 'CHAIR CROISSANCE', 'Broiler', 'Croissance', 3100.00, 20.00, 1.10, 0.45, 0.90, 0.40, 6000.00, 1, '2026-04-03 14:37:30', '2026-04-03 14:37:30'),
(23, 'CHAIR FINITION', 'Broiler', 'Finition', 3200.00, 18.50, 1.00, 0.40, 0.85, 0.35, 5800.00, 1, '2026-04-03 14:37:30', '2026-04-03 14:37:30'),
(24, 'PONTE POUSSIN', 'Layer', 'Poussin', 2850.00, 20.00, 1.05, 0.42, 1.05, 0.48, 6200.00, 1, '2026-04-03 14:37:30', '2026-04-03 14:37:30'),
(25, 'PONTE POULETTE', 'Layer', 'Poulette', 2750.00, 16.00, 0.75, 0.35, 1.10, 0.40, 5500.00, 1, '2026-04-03 14:37:30', '2026-04-03 14:37:30'),
(26, 'PONTE PIC', 'Layer', 'Ponte 1', 2800.00, 17.50, 0.85, 0.40, 3.80, 0.42, 5900.00, 1, '2026-04-03 14:37:30', '2026-04-03 14:37:30'),
(27, 'PONTE ENTRETIEN', 'Layer', 'Ponte 2', 2750.00, 16.50, 0.78, 0.38, 4.20, 0.38, 5700.00, 1, '2026-04-03 14:37:30', '2026-04-03 14:37:30');

-- --------------------------------------------------------

--
-- Structure de la table `formulas`
--

DROP TABLE IF EXISTS `formulas`;
CREATE TABLE IF NOT EXISTS `formulas` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `poultry_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Chair',
  `code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `total_batch_weight` decimal(10,2) NOT NULL DEFAULT '1000.00',
  `instructions` text COLLATE utf8mb4_unicode_ci,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `formulas_code_unique` (`code`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `formulas`
--

INSERT INTO `formulas` (`id`, `name`, `poultry_type`, `code`, `target_type`, `is_active`, `total_batch_weight`, `instructions`, `is_locked`, `created_at`, `updated_at`) VALUES
(1, 'CHAIR DÉMARRAGE', 'Chair', 'CH-DEM', 'Broiler Starter', 1, 1000.00, NULL, 0, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(2, 'CHAIR CROISSANCE', 'Chair', 'CH-CRO', 'Broiler Grower', 1, 1000.00, NULL, 0, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(3, 'CHAIR FINITION', 'Chair', 'CH-FIN', 'Broiler Finisher', 1, 1000.00, NULL, 0, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(4, 'PONTE DÉMARRAGE (POUSSIN)', 'Ponte', 'PO-DEM', 'Layer Chick', 1, 1000.00, NULL, 0, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(5, 'PONTE CROISSANCE (POULETTE)', 'Ponte', 'PO-CRO', 'Layer Pullet', 1, 1000.00, NULL, 0, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(6, 'PONTE 1 (PIC DE PONTE)', 'Ponte', 'PO-PIC', 'Layer Peak', 1, 1000.00, NULL, 0, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(7, 'PONTE 2 (ENTRETIEN)', 'Ponte', 'PO-ENT', 'Layer Maintain', 1, 1000.00, NULL, 0, '2026-04-03 16:00:29', '2026-04-03 16:00:29');

-- --------------------------------------------------------

--
-- Structure de la table `formula_items`
--

DROP TABLE IF EXISTS `formula_items`;
CREATE TABLE IF NOT EXISTS `formula_items` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `formula_id` bigint UNSIGNED NOT NULL,
  `raw_material_id` bigint UNSIGNED NOT NULL,
  `quantity_kg` decimal(10,3) DEFAULT NULL,
  `percentage` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `formula_items_formula_id_foreign` (`formula_id`),
  KEY `formula_items_raw_material_id_foreign` (`raw_material_id`)
) ENGINE=MyISAM AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `formula_items`
--

INSERT INTO `formula_items` (`id`, `formula_id`, `raw_material_id`, `quantity_kg`, `percentage`, `created_at`, `updated_at`) VALUES
(73, 1, 1, 550.000, 55.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(74, 1, 2, 330.000, 33.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(75, 1, 4, 50.000, 5.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(76, 1, 6, 50.000, 5.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(77, 1, 8, 20.000, 2.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(78, 2, 1, 600.000, 60.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(79, 2, 2, 270.000, 27.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(80, 2, 4, 50.000, 5.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(81, 2, 3, 30.000, 3.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(82, 2, 6, 50.000, 5.00, '2026-04-03 16:00:29', '2026-04-03 16:00:29'),
(83, 6, 1, 520.000, 52.00, '2026-04-03 16:00:30', '2026-04-03 16:00:30'),
(84, 6, 2, 210.000, 21.00, '2026-04-03 16:00:30', '2026-04-03 16:00:30'),
(85, 6, 4, 100.000, 10.00, '2026-04-03 16:00:30', '2026-04-03 16:00:30'),
(86, 6, 5, 90.000, 9.00, '2026-04-03 16:00:30', '2026-04-03 16:00:30'),
(87, 6, 3, 40.000, 4.00, '2026-04-03 16:00:30', '2026-04-03 16:00:30'),
(88, 6, 7, 40.000, 4.00, '2026-04-03 16:00:30', '2026-04-03 16:00:30'),
(89, 3, 1, 650.000, 65.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(90, 3, 2, 220.000, 22.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(91, 3, 3, 60.000, 6.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(92, 3, 4, 30.000, 3.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(93, 3, 6, 40.000, 4.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(94, 4, 1, 500.000, 50.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(95, 4, 2, 280.000, 28.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(96, 4, 4, 120.000, 12.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(97, 4, 5, 20.000, 2.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(98, 4, 3, 30.000, 3.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(99, 4, 7, 50.000, 5.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(100, 5, 1, 450.000, 45.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(101, 5, 2, 180.000, 18.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(102, 5, 4, 250.000, 25.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(103, 5, 5, 30.000, 3.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(104, 5, 3, 50.000, 5.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(105, 5, 7, 40.000, 4.00, '2026-04-03 16:30:00', '2026-04-03 16:30:00'),
(106, 7, 1, 500.000, 50.00, '2026-04-03 16:30:01', '2026-04-03 16:30:01'),
(107, 7, 2, 180.000, 18.00, '2026-04-03 16:30:01', '2026-04-03 16:30:01'),
(108, 7, 5, 100.000, 10.00, '2026-04-03 16:30:01', '2026-04-03 16:30:01'),
(109, 7, 4, 150.000, 15.00, '2026-04-03 16:30:01', '2026-04-03 16:30:01'),
(110, 7, 3, 30.000, 3.00, '2026-04-03 16:30:01', '2026-04-03 16:30:01'),
(111, 7, 7, 40.000, 4.00, '2026-04-03 16:30:01', '2026-04-03 16:30:01');

-- --------------------------------------------------------

--
-- Structure de la table `health_checks`
--

DROP TABLE IF EXISTS `health_checks`;
CREATE TABLE IF NOT EXISTS `health_checks` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_id` bigint UNSIGNED NOT NULL,
  `intervention_date` date NOT NULL,
  `type` enum('Vaccin','Traitement','Vitamine','Désinfection') COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_number` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `dosage` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode_administration` enum('Eau de boisson','Injection','Nébulisation','Aliment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `observations` text COLLATE utf8mb4_unicode_ci,
  `cost` decimal(10,2) DEFAULT NULL,
  `veterinary_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_synced` tinyint(1) NOT NULL DEFAULT '1',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `health_checks_uuid_index` (`uuid`),
  KEY `health_checks_is_synced_index` (`is_synced`),
  KEY `idx_health_checks_batch_date` (`batch_id`,`intervention_date`),
  KEY `idx_health_checks_type` (`type`)
) ENGINE=MyISAM AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `health_checks`
--

INSERT INTO `health_checks` (`id`, `uuid`, `batch_id`, `intervention_date`, `type`, `product_name`, `batch_number`, `expiry_date`, `dosage`, `mode_administration`, `observations`, `cost`, `veterinary_name`, `created_at`, `updated_at`, `is_synced`, `last_sync_at`, `deleted_at`) VALUES
(4, '840d3438-3b7a-4bcd-8510-6f01bfe4bcdc', 10, '2026-04-11', 'Vitamine', 'Réception + Vitamines Hydratation', NULL, NULL, NULL, 'Eau de boisson', 'RAS', 100000.00, NULL, '2026-04-11 11:35:47', '2026-04-11 11:35:47', 1, '2026-04-12 15:08:19', NULL),
(2, 'b82707d4-1645-470f-9a9a-2c9eef3aeaea', 1, '2026-04-01', 'Vaccin', 'Gomboro', NULL, NULL, NULL, 'Eau de boisson', NULL, 199988.00, NULL, '2026-04-01 07:10:55', '2026-04-01 07:11:19', 1, '2026-04-12 15:08:19', NULL),
(3, '57fe4752-d184-4cdb-bd7b-4a68959d5461', 2, '2026-04-05', 'Vaccin', 'Gomboro', '00v01', '2026-04-06', NULL, 'Eau de boisson', NULL, 100000.00, 'Bah', '2026-04-06 08:12:41', '2026-04-06 08:13:03', 1, '2026-04-12 15:08:19', NULL),
(5, NULL, 1, '2026-04-20', 'Vitamine', 'Réception + Anti-stress (Vitamines)', '00v01', '2026-04-19', NULL, 'Eau de boisson', 'RAS', 200000.00, 'Bah', '2026-04-20 09:20:42', '2026-04-20 09:20:42', 1, NULL, NULL),
(6, NULL, 1, '2026-05-19', 'Vaccin', 'Newcastle + Bronchite HB1', NULL, NULL, NULL, 'Eau de boisson', NULL, 0.00, NULL, '2026-05-19 13:58:17', '2026-05-19 13:58:17', 1, NULL, NULL),
(7, NULL, 12, '2026-05-19', 'Vitamine', 'Réception + Vitamines Hydratation', NULL, NULL, NULL, 'Eau de boisson', NULL, 0.00, NULL, '2026-05-19 13:58:32', '2026-05-19 13:58:32', 1, NULL, NULL),
(8, NULL, 10, '2026-05-19', 'Vaccin', 'Vaccin Variole Aviaire', NULL, NULL, NULL, 'Eau de boisson', NULL, 0.00, NULL, '2026-05-19 13:59:52', '2026-05-19 13:59:52', 1, NULL, NULL),
(9, NULL, 1, '2026-05-19', 'Vaccin', 'Gumboro (Premier passage)', NULL, NULL, NULL, 'Eau de boisson', NULL, 0.00, NULL, '2026-05-19 14:09:39', '2026-05-19 14:09:39', 1, NULL, NULL),
(10, NULL, 11, '2026-05-20', 'Vitamine', 'Réception + Vitamines Hydratation', NULL, NULL, NULL, 'Eau de boisson', NULL, 0.00, NULL, '2026-05-20 14:34:01', '2026-05-20 14:34:01', 1, NULL, NULL),
(11, NULL, 4, '2026-05-23', 'Vaccin', 'Réception + Vitamines Hydratation', NULL, NULL, NULL, 'Eau de boisson', NULL, 0.00, NULL, '2026-05-23 11:51:53', '2026-05-23 11:51:53', 1, NULL, NULL),
(12, NULL, 3, '2026-05-23', 'Vitamine', 'Réception + Glucose/Vitamines', NULL, NULL, NULL, 'Eau de boisson', NULL, 200000.00, NULL, '2026-05-23 11:52:09', '2026-05-23 11:52:09', 1, NULL, NULL),
(13, NULL, 2, '2026-05-23', 'Vitamine', 'Démarrage Vitamines', NULL, NULL, NULL, 'Eau de boisson', NULL, 100000.00, NULL, '2026-05-23 11:52:31', '2026-05-23 11:52:31', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `incubations`
--

DROP TABLE IF EXISTS `incubations`;
CREATE TABLE IF NOT EXISTS `incubations` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_id` bigint UNSIGNED NOT NULL,
  `incubator_id` bigint UNSIGNED DEFAULT NULL,
  `code_incubation` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `incubation_duration` int NOT NULL DEFAULT '21',
  `hatch_date_expected` date NOT NULL,
  `eggs_count` int NOT NULL,
  `fertile_eggs` int DEFAULT NULL,
  `hatched_chicks` int DEFAULT NULL,
  `fertility_rate` decimal(5,2) DEFAULT NULL,
  `hatchability_rate` decimal(5,2) DEFAULT NULL,
  `status` enum('incubation','mirage_fait','clos','echec') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'incubation',
  `finished_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_synced` tinyint(1) NOT NULL DEFAULT '1',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incubations_code_incubation_unique` (`code_incubation`),
  KEY `incubations_batch_id_foreign` (`batch_id`),
  KEY `incubations_incubator_id_foreign` (`incubator_id`),
  KEY `incubations_uuid_index` (`uuid`),
  KEY `incubations_is_synced_index` (`is_synced`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `incubations`
--

INSERT INTO `incubations` (`id`, `uuid`, `batch_id`, `incubator_id`, `code_incubation`, `start_date`, `incubation_duration`, `hatch_date_expected`, `eggs_count`, `fertile_eggs`, `hatched_chicks`, `fertility_rate`, `hatchability_rate`, `status`, `finished_at`, `created_at`, `updated_at`, `is_synced`, `last_sync_at`, `deleted_at`) VALUES
(1, 'add2b67c-4c86-4b3e-8ec0-820864f90b4f', 2, 1, 'INC-260401-EA7D', '2026-04-01', 21, '2026-04-22', 500, 470, 440, 0.00, 0.00, 'clos', NULL, '2026-04-01 12:32:48', '2026-04-01 16:47:37', 1, '2026-04-12 15:08:19', NULL),
(2, '4e2455d6-364d-4631-ad00-a9d4cc6eaf00', 1, 1, 'INC-260409-WJWP', '2026-04-09', 21, '2026-04-30', 50, 48, 47, 0.00, 0.00, 'clos', NULL, '2026-04-09 14:42:26', '2026-04-15 10:05:07', 1, '2026-04-12 15:08:19', NULL),
(3, '3d69036d-c25f-4ad1-adbc-a42e9c19f156', 1, 3, 'INC-260519-BPYI', '2026-05-19', 21, '2026-06-09', 100, 95, 90, 0.00, 0.00, 'clos', NULL, '2026-05-19 13:22:26', '2026-05-22 16:38:35', 1, NULL, NULL),
(4, 'dc229b16-5a15-4c39-bd7f-7853f61d8402', 2, 1, 'INC-260522-6PTQ', '2026-05-22', 21, '2026-06-12', 200, 185, 180, NULL, NULL, 'clos', NULL, '2026-05-22 16:39:07', '2026-05-22 16:39:45', 1, NULL, NULL),
(5, 'aed37218-53dd-4c76-a6a8-9375809193b8', 13, 2, 'INC-260523-BDYU', '2026-05-23', 21, '2026-06-13', 100, 87, 84, NULL, NULL, 'clos', NULL, '2026-05-23 09:44:50', '2026-05-23 09:46:29', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `incubators`
--

DROP TABLE IF EXISTS `incubators`;
CREATE TABLE IF NOT EXISTS `incubators` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacity` int NOT NULL DEFAULT '0',
  `status` enum('Disponible','Occupé','Maintenance') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Disponible',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `incubators`
--

INSERT INTO `incubators` (`id`, `name`, `capacity`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Couveuse B-03', 1000, 'Disponible', '2026-04-01 12:14:02', '2026-05-23 09:48:10', NULL),
(2, 'Couveuse B-02', 800, 'Disponible', '2026-04-01 12:22:20', '2026-05-23 09:47:50', NULL),
(3, 'Couveuse B-01', 400, 'Disponible', '2026-05-19 13:21:56', '2026-05-23 09:47:13', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `incubator_maintenances`
--

DROP TABLE IF EXISTS `incubator_maintenances`;
CREATE TABLE IF NOT EXISTS `incubator_maintenances` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `incubator_id` bigint UNSIGNED NOT NULL,
  `maintenance_date` date NOT NULL,
  `type` enum('Entretien','Réparation','Désinfection','Étalonnage') COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `performed_by` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incubator_maintenances_incubator_id_foreign` (`incubator_id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `incubator_maintenances`
--

INSERT INTO `incubator_maintenances` (`id`, `incubator_id`, `maintenance_date`, `type`, `description`, `performed_by`, `created_at`, `updated_at`) VALUES
(1, 1, '2026-04-09', 'Désinfection', 'vidange', 'Admin Manuel', '2026-04-09 14:41:15', '2026-04-09 14:41:15'),
(2, 1, '2026-05-19', 'Désinfection', 'Vidange', 'Admin Manuel', '2026-05-19 13:23:17', '2026-05-19 13:23:17'),
(3, 2, '2026-05-23', 'Désinfection', 'nettoyage', 'Moussa Touré', '2026-05-23 09:47:03', '2026-05-23 09:47:03'),
(4, 3, '2026-05-23', 'Désinfection', 'nettoyage', 'Moussa Touré', '2026-05-23 09:47:13', '2026-05-23 09:47:13'),
(5, 1, '2026-05-23', 'Désinfection', 'nettoyage', 'Moussa Touré', '2026-05-23 09:47:18', '2026-05-23 09:47:18');

-- --------------------------------------------------------

--
-- Structure de la table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED DEFAULT NULL,
  `available_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
CREATE TABLE IF NOT EXISTS `job_batches` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `maintenance_logs`
--

DROP TABLE IF EXISTS `maintenance_logs`;
CREATE TABLE IF NOT EXISTS `maintenance_logs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `mill_machine_id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `hours_at_maintenance` double NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_logs_mill_machine_id_foreign` (`mill_machine_id`),
  KEY `maintenance_logs_user_id_foreign` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `maintenance_logs`
--

INSERT INTO `maintenance_logs` (`id`, `mill_machine_id`, `user_id`, `hours_at_maintenance`, `description`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 0.38, 'Vidange', '2026-04-06 14:16:33', '2026-04-06 14:16:33'),
(2, 1, 1, 0.38, 'Vidange', '2026-04-06 14:18:45', '2026-04-06 14:18:45'),
(3, 1, 1, 0, 'Vidange', '2026-04-06 14:24:34', '2026-04-06 14:24:34'),
(4, 1, 1, 0, 'Vidange', '2026-04-06 14:25:01', '2026-04-06 14:25:01'),
(5, 1, 1, 0.35, 'Filtres', '2026-04-07 07:40:06', '2026-04-07 07:40:06');

-- --------------------------------------------------------

--
-- Structure de la table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=95 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_03_18_121556_create_buildings_table', 1),
(5, '2026_03_18_130517_create_settings_table', 1),
(6, '2026_03_18_130649_create_batches_table', 1),
(7, '2026_03_18_141051_add_surface_to_buildings_table', 1),
(8, '2026_03_18_141052_create_employees_table', 1),
(9, '2026_03_18_141105_create_providers_table', 1),
(10, '2026_03_18_151121_create_daily_checks_table', 1),
(11, '2026_03_18_175101_make_responsible_nullable_in_batches_table', 1),
(12, '2026_03_19_002930_modify_status_in_employees_table', 1),
(13, '2026_03_19_010345_add_status_to_buildings_table', 1),
(14, '2026_03_19_152555_add_closing_fields_to_batches_table', 1),
(15, '2026_03_19_160806_add_role_to_users_table', 1),
(16, '2026_03_19_231954_add_health_status_to_daily_checks_table', 1),
(17, '2026_03_20_000110_add_litter_changed_to_daily_checks_table', 1),
(18, '2026_03_20_133909_rename_breeding_type_to_type_in_batches_table', 1),
(19, '2026_03_21_094350_fix_type_column_in_batches_table', 1),
(20, '2026_03_21_122106_add_soft_deletes_to_tables', 1),
(21, '2026_03_21_211634_create_health_checks_table', 1),
(22, '2026_03_21_212251_create_protocols_table', 1),
(23, '2026_03_21_212305_create_protocol_steps_table', 1),
(24, '2026_03_21_233030_add_name_to_protocols_table', 1),
(25, '2026_03_22_002535_add_protocol_id_to_batches_table', 1),
(26, '2026_03_22_002747_make_cost_nullable_in_health_checks', 1),
(27, '2026_03_22_004229_add_type_to_protocols_table', 1),
(28, '2026_03_22_153257_add_sanitary_fields_to_buildings_table', 1),
(29, '2026_03_23_142509_add_details_to_health_checks_table', 1),
(30, '2026_03_23_161253_create_feed_purchases_table', 1),
(31, '2026_03_23_173923_add_feed_type_to_daily_checks_table', 1),
(32, '2026_03_23_194726_create_egg_productions_table', 1),
(33, '2026_03_24_140145_create_egg_movements_table', 1),
(34, '2026_03_24_145919_change_avg_weight_start_to_nullable_in_batches_table', 1),
(35, '2026_03_24_150314_fix_default_values_on_batches_table', 1),
(36, '2026_03_24_150702_fix_all_missing_defaults_on_batches_table', 1),
(37, '2026_03_24_174337_create_reproducers_table', 1),
(38, '2026_03_24_211358_add_repro_fields_to_batches_table', 1),
(39, '2026_03_25_100645_create_incubators_table', 1),
(40, '2026_03_25_102118_create_incubator_maintenances_table', 1),
(41, '2026_03_25_171121_create_production_norms_table', 1),
(42, '2026_03_26_103241_add_model_name_to_production_norms_table', 1),
(43, '2026_03_26_105228_add_model_name_to_production_norms_table', 1),
(44, '2026_03_26_112421_add_model_name_to_batches_table', 1),
(45, '2026_03_26_155949_add_strain_to_protocols_table', 1),
(46, '2026_03_26_174030_prepare_batches_for_erp_transfer', 1),
(47, '2026_03_26_174112_create_batch_tasks_table', 1),
(48, '2026_03_27_115003_create_stock_movements_table', 1),
(49, '2026_03_27_115126_create_stocks_table', 1),
(50, '2026_03_27_130320_add_soft_deletes_to_stocks_table', 1),
(51, '2026_03_27_152542_update_stocks_table_for_erp_integration', 1),
(52, '2026_03_31_162208_add_unit_to_feed_purchases_table', 2),
(53, '2026_04_02_094954_create_raw_materials_table', 3),
(54, '2026_04_02_094955_create_formulas_table', 3),
(55, '2026_04_02_094956_create_formula_items_table', 3),
(56, '2026_04_02_094957_create_mill_machines_table', 3),
(57, '2026_04_02_094958_create_mill_productions_table', 3),
(58, '2026_04_02_112111_add_is_active_to_formulas_table', 4),
(59, '2026_04_02_115155_create_food_norms_table', 5),
(60, '2026_04_02_115226_add_nutrition_to_raw_materials', 5),
(63, '2026_04_02_165752_add_maintenance_fields_to_mill_machines_table', 6),
(64, '2026_04_02_171214_make_real_cost_nullable_in_mill_productions', 7),
(65, '2026_04_02_173223_align_provenderie_and_stocks_structure', 8),
(66, '2026_04_02_173934_link_raw_materials_to_existing_stocks', 9),
(67, '2026_04_03_083419_add_poultry_type_to_formulas_table', 10),
(68, '2026_04_03_131955_adjust_formula_items_for_flexibility', 10),
(69, '2026_04_03_170329_create_mill_production_machine_table', 11),
(70, '2026_04_03_171912_create_maintenance_logs_table', 12),
(71, '2026_04_07_142029_add_supervisor_id_to_mill_productions', 13),
(72, '2026_04_07_150059_create_acl_tables', 14),
(73, '2026_04_07_150316_add_role_id_to_users_table', 14),
(74, '2026_04_09_114947_add_timestamps_to_permission_role_table', 15),
(75, '2026_04_09_155924_add_allocated_surface_to_batches_table', 16),
(76, '2026_04_11_144507_add_soft_deletes_to_batches_table', 17),
(77, '2026_04_12_164153_prepare_tables_for_offline_sync', 18),
(78, '2026_04_14_153334_create_notifications_table', 19),
(79, '2026_04_14_add_alert_thresholds', 20),
(80, '2026_05_18_130649_consolidate_batches_table', 21),
(81, '2026_05_18_142200_add_unique_constraint_to_daily_checks', 21),
(82, '2026_05_18_000001_consolidate_batches_table', 22),
(84, '2026_05_18_000002_add_unique_constraint_to_daily_checks', 23),
(85, '2026_05_18_000003_improve_module_lots_annexes', 23),
(86, '2026_05_18_000004_deprecate_legacy_columns', 23),
(87, '2026_05_19_151020_add_soft_deletes_to_hatchery_tables', 24),
(88, '2026_05_21_102940_add_is_system_generated_to_tasks_table', 25),
(89, '2026_05_21_112153_add_feed_type_to_stocks_table', 26),
(90, '2026_05_22_092226_add_unit_price_to_stocks_table', 27),
(91, '2026_05_22_174610_add_snapshot_to_mill_production_machine', 28),
(92, '2026_05_22_175243_make_machine_id_nullable_in_mill_productions', 29),
(93, '2026_05_22_183442_upgrade_incubations_table_for_erp', 30),
(94, '2026_05_23_113312_optimize_batches_table_defaults', 31);

-- --------------------------------------------------------

--
-- Structure de la table `mill_machines`
--

DROP TABLE IF EXISTS `mill_machines`;
CREATE TABLE IF NOT EXISTS `mill_machines` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacity_per_hour` decimal(10,2) NOT NULL,
  `total_hours_run` decimal(12,2) NOT NULL DEFAULT '0.00',
  `maintenance_interval_hours` int NOT NULL DEFAULT '500',
  `last_maintenance` date DEFAULT NULL,
  `status` enum('Opérationnel','Maintenance','En Panne','Désactivé') COLLATE utf8mb4_unicode_ci DEFAULT 'Opérationnel',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `mill_machines`
--

INSERT INTO `mill_machines` (`id`, `name`, `type`, `capacity_per_hour`, `total_hours_run`, `maintenance_interval_hours`, `last_maintenance`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Broyeur multifonction', 'Broyeuse', 3500.00, 0.14, 500, '2026-04-20', 'Opérationnel', '2026-04-02 15:03:50', '2026-05-22 15:19:22'),
(5, 'Machine à granulés', 'Granuleur', 1000.00, 0.60, 500, '2026-04-07', 'Opérationnel', '2026-04-07 09:11:36', '2026-05-19 11:20:34'),
(3, 'Mélangeur électrique', 'Mixeur', 300.00, 2.16, 500, NULL, 'Opérationnel', '2026-04-03 15:41:30', '2026-05-19 11:20:34'),
(4, 'Convoyeur', 'Convoyeur', 1000.00, 0.65, 500, NULL, 'Opérationnel', '2026-04-03 15:42:20', '2026-05-19 11:20:34');

-- --------------------------------------------------------

--
-- Structure de la table `mill_productions`
--

DROP TABLE IF EXISTS `mill_productions`;
CREATE TABLE IF NOT EXISTS `mill_productions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_number` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `formula_id` bigint UNSIGNED NOT NULL,
  `machine_id` bigint UNSIGNED DEFAULT NULL,
  `quantity_produced` decimal(12,2) NOT NULL,
  `real_cost_per_kg` decimal(12,2) DEFAULT NULL,
  `operator_id` bigint UNSIGNED NOT NULL,
  `supervisor_id` bigint UNSIGNED DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `status` enum('Planifié','En cours','Terminé','Échec') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Planifié',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mill_productions_batch_number_unique` (`batch_number`),
  KEY `mill_productions_formula_id_foreign` (`formula_id`),
  KEY `mill_productions_machine_id_foreign` (`machine_id`),
  KEY `mill_productions_operator_id_foreign` (`operator_id`),
  KEY `mill_productions_supervisor_id_foreign` (`supervisor_id`)
) ENGINE=MyISAM AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `mill_productions`
--

INSERT INTO `mill_productions` (`id`, `batch_number`, `formula_id`, `machine_id`, `quantity_produced`, `real_cost_per_kg`, `operator_id`, `supervisor_id`, `started_at`, `finished_at`, `status`, `created_at`, `updated_at`) VALUES
(1, 'OP-20260402-YQI0', 1, 1, 500.00, NULL, 1, NULL, NULL, '2026-04-02 15:13:55', 'Terminé', '2026-04-02 15:13:05', '2026-04-02 15:13:55'),
(2, 'OP-20260402-ZQ8S', 1, 1, 250.00, NULL, 1, NULL, NULL, '2026-04-02 15:44:21', 'Terminé', '2026-04-02 15:43:00', '2026-04-02 15:44:21'),
(3, 'OP-20260403-FA53', 2, 1, 150.00, 4253.22, 1, NULL, NULL, '2026-04-03 12:20:43', 'Terminé', '2026-04-03 11:30:34', '2026-04-03 12:20:43'),
(4, 'OP-20260403-J6H3', 2, 1, 50.00, 4253.22, 1, NULL, NULL, '2026-04-03 12:23:51', 'Terminé', '2026-04-03 12:23:46', '2026-04-03 12:23:51'),
(5, 'OP-20260403-3L1R', 2, 1, 50.00, 4471.40, 1, NULL, NULL, '2026-04-03 12:29:10', 'Terminé', '2026-04-03 12:29:06', '2026-04-03 12:29:10'),
(6, 'OP-20260403-2213-TW3E', 7, 1, 100.00, 5295.71, 1, NULL, NULL, '2026-04-03 20:20:14', 'Terminé', '2026-04-03 20:13:56', '2026-04-03 20:20:14'),
(7, 'OP-20260403-2221-VEWX', 3, 1, 250.00, 5979.43, 1, NULL, NULL, '2026-04-03 20:22:07', 'Terminé', '2026-04-03 20:21:53', '2026-04-03 20:22:07'),
(8, 'OP-20260406-1018-NMW5', 5, 1, 500.00, 5322.71, 1, NULL, NULL, '2026-04-06 10:54:45', 'Terminé', '2026-04-06 08:18:45', '2026-04-06 10:54:45'),
(9, 'OP-20260406-1543-SEWD', 2, 1, 500.00, 6364.57, 1, NULL, NULL, '2026-04-06 13:48:53', 'Terminé', '2026-04-06 13:43:56', '2026-04-06 13:48:53'),
(10, 'OP-20260406-1625-WH64', 6, 1, 200.00, 5551.00, 1, NULL, NULL, '2026-04-06 14:53:30', 'Terminé', '2026-04-06 14:25:32', '2026-04-06 14:53:30'),
(11, 'OP-20260406-1700-W5ZM', 5, 1, 1000.00, 5322.71, 1, NULL, NULL, '2026-04-06 15:11:29', 'Terminé', '2026-04-06 15:00:03', '2026-04-06 15:11:29'),
(12, 'OP-20260407-0940-QJWM', 2, 1, 100.00, 6364.57, 1, NULL, NULL, '2026-04-07 07:44:58', 'Terminé', '2026-04-07 07:40:43', '2026-04-07 07:44:58'),
(13, 'OP-20260407-1000-MHAL', 6, 1, 100.00, 5551.00, 1, NULL, NULL, '2026-04-07 08:04:58', 'Terminé', '2026-04-07 08:00:29', '2026-04-07 08:04:58'),
(14, 'OP-20260407-1020-NMBK', 3, 1, 150.00, 5979.43, 1, NULL, NULL, '2026-04-07 08:59:39', 'Terminé', '2026-04-07 08:20:08', '2026-04-07 08:59:39'),
(15, 'OP-20260407-1348-0UWG', 1, 1, 100.00, 6909.14, 1, NULL, NULL, '2026-04-07 11:53:01', 'Terminé', '2026-04-07 11:48:24', '2026-04-07 11:53:01'),
(16, 'OP-20260407-1420-IJ1M', 1, 1, 150.00, 6909.14, 1, 1, NULL, '2026-04-07 12:24:25', 'Terminé', '2026-04-07 12:20:58', '2026-04-07 12:24:25'),
(17, 'OP-20260407-1424-AEWC', 2, 1, 100.00, 6364.57, 1, 1, NULL, '2026-04-07 14:52:17', 'Terminé', '2026-04-07 12:24:42', '2026-04-07 14:52:17'),
(18, 'OP-20260420-1710-54AF', 6, 1, 250.00, 5076.53, 1, 1, NULL, '2026-05-19 11:20:34', 'Terminé', '2026-04-20 15:10:10', '2026-05-19 11:20:34'),
(19, 'OP-20260522-1717-KNNJ', 6, 1, 100.00, 5076.53, 1, 1, NULL, '2026-05-22 15:50:18', 'Terminé', '2026-05-22 15:17:39', '2026-05-22 15:50:18'),
(20, 'OP-20260522-1718-V8AK', 3, 1, 250.00, 5386.34, 1, 1, NULL, '2026-05-22 15:19:22', 'Terminé', '2026-05-22 15:18:15', '2026-05-22 15:19:22'),
(21, 'OP-20260522-1753-3W6C', 7, NULL, 1000.00, NULL, 1, 1, NULL, NULL, 'Planifié', '2026-05-22 15:53:18', '2026-05-22 15:53:18');

-- --------------------------------------------------------

--
-- Structure de la table `mill_production_machine`
--

DROP TABLE IF EXISTS `mill_production_machine`;
CREATE TABLE IF NOT EXISTS `mill_production_machine` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `mill_production_id` bigint UNSIGNED NOT NULL,
  `mill_machine_id` bigint UNSIGNED NOT NULL,
  `snapshot_capacity_per_hour` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mill_production_machine_mill_production_id_foreign` (`mill_production_id`),
  KEY `mill_production_machine_mill_machine_id_foreign` (`mill_machine_id`)
) ENGINE=MyISAM AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `mill_production_machine`
--

INSERT INTO `mill_production_machine` (`id`, `mill_production_id`, `mill_machine_id`, `snapshot_capacity_per_hour`, `created_at`, `updated_at`) VALUES
(1, 14, 1, 0.00, NULL, NULL),
(2, 14, 3, 0.00, NULL, NULL),
(3, 14, 4, 0.00, NULL, NULL),
(4, 15, 1, 0.00, NULL, NULL),
(5, 15, 5, 0.00, NULL, NULL),
(6, 15, 3, 0.00, NULL, NULL),
(7, 15, 4, 0.00, NULL, NULL),
(8, 16, 1, 0.00, NULL, NULL),
(9, 16, 5, 0.00, NULL, NULL),
(10, 16, 3, 0.00, NULL, NULL),
(11, 16, 4, 0.00, NULL, NULL),
(12, 17, 1, 0.00, NULL, NULL),
(13, 17, 5, 0.00, NULL, NULL),
(14, 18, 1, 0.00, '2026-04-20 15:10:10', '2026-04-20 15:10:10'),
(15, 18, 5, 0.00, '2026-04-20 15:10:10', '2026-04-20 15:10:10'),
(16, 18, 3, 0.00, '2026-04-20 15:10:10', '2026-04-20 15:10:10'),
(17, 18, 4, 0.00, '2026-04-20 15:10:10', '2026-04-20 15:10:10'),
(18, 19, 1, 0.00, '2026-05-22 15:17:39', '2026-05-22 15:17:39'),
(19, 19, 5, 0.00, '2026-05-22 15:17:39', '2026-05-22 15:17:39'),
(20, 19, 3, 0.00, '2026-05-22 15:17:39', '2026-05-22 15:17:39'),
(21, 19, 4, 0.00, '2026-05-22 15:17:39', '2026-05-22 15:17:39'),
(22, 20, 1, 0.00, '2026-05-22 15:18:15', '2026-05-22 15:18:15'),
(23, 21, 1, 3500.00, '2026-05-22 15:53:18', '2026-05-22 15:53:18'),
(24, 21, 5, 1000.00, '2026-05-22 15:53:18', '2026-05-22 15:53:18'),
(25, 21, 3, 300.00, '2026-05-22 15:53:18', '2026-05-22 15:53:18'),
(26, 21, 4, 1000.00, '2026-05-22 15:53:18', '2026-05-22 15:53:18');

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint UNSIGNED NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `notifications`
--

INSERT INTO `notifications` (`id`, `type`, `notifiable_type`, `notifiable_id`, `data`, `read_at`, `created_at`, `updated_at`) VALUES
('8caf82e0-e61d-437d-a323-22de6574376d', 'App\\Notifications\\IndustrialAlert', 'App\\Models\\User', 1, '{\"type\":\"high_mortality\",\"title\":\"Alerte de Surmortalit\\u00e9\",\"message\":\"Mortalit\\u00e9 critique sur le lot LOT-20260409-154815 : 100% atteint.\",\"id_reference\":\"\"}', NULL, '2026-05-18 11:08:54', '2026-05-18 11:08:54');

-- --------------------------------------------------------

--
-- Structure de la table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_unique` (`name`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'L', 'Lecture seule', '2026-04-07 16:13:16', '2026-04-07 16:13:16'),
(2, 'C', 'Création / Ajout', '2026-04-07 16:13:16', '2026-04-07 16:13:16'),
(3, 'M', 'Modification / Edition', '2026-04-07 16:13:16', '2026-04-07 16:13:16'),
(4, 'S', 'Suppression définitive', '2026-04-07 16:13:16', '2026-04-07 16:13:16');

-- --------------------------------------------------------

--
-- Structure de la table `permission_role`
--

DROP TABLE IF EXISTS `permission_role`;
CREATE TABLE IF NOT EXISTS `permission_role` (
  `role_id` bigint UNSIGNED NOT NULL,
  `permission_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_role_permission_id_foreign` (`permission_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `permission_role`
--

INSERT INTO `permission_role` (`role_id`, `permission_id`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, NULL),
(1, 2, NULL, NULL),
(1, 3, NULL, NULL),
(1, 4, NULL, NULL),
(3, 1, NULL, NULL),
(3, 3, NULL, NULL),
(2, 1, NULL, NULL),
(3, 2, '2026-04-11 12:51:01', '2026-04-11 12:51:01');

-- --------------------------------------------------------

--
-- Structure de la table `production_norms`
--

DROP TABLE IF EXISTS `production_norms`;
CREATE TABLE IF NOT EXISTS `production_norms` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `week_number` int NOT NULL,
  `target_weight` decimal(8,2) DEFAULT NULL,
  `target_feed_daily` decimal(8,2) DEFAULT NULL,
  `target_water_daily` decimal(8,2) DEFAULT NULL,
  `target_laying_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `phase_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_norm_index` (`batch_type`,`week_number`,`model_name`)
) ENGINE=MyISAM AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `production_norms`
--

INSERT INTO `production_norms` (`id`, `batch_type`, `week_number`, `target_weight`, `target_feed_daily`, `target_water_daily`, `target_laying_rate`, `phase_name`, `model_name`, `created_at`, `updated_at`) VALUES
(1, 'ponte', 1, 70.00, 12.00, 25.00, 0.00, 'Démarrage', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(2, 'ponte', 2, 120.00, 18.00, 35.00, 0.00, 'Démarrage', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(3, 'ponte', 3, 190.00, 24.00, 45.00, 0.00, 'Démarrage', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(4, 'ponte', 4, 280.00, 30.00, 60.00, 0.00, 'Démarrage', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(5, 'ponte', 5, 380.00, 35.00, 70.00, 0.00, 'Croissance', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(6, 'ponte', 10, 900.00, 60.00, 120.00, 0.00, 'Croissance', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(7, 'ponte', 15, 1300.00, 75.00, 150.00, 0.00, 'Croissance', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(8, 'ponte', 20, 1750.00, 100.00, 200.00, 45.00, 'Ponte', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(9, 'ponte', 25, 1920.00, 115.00, 230.00, 94.00, 'Ponte', 'Isa Brown', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(10, 'chair', 1, 190.00, 22.00, 45.00, 0.00, 'Démarrage', 'Cobb 500', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(11, 'chair', 2, 480.00, 50.00, 95.00, 0.00, 'Démarrage', 'Cobb 500', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(12, 'chair', 3, 960.00, 85.00, 160.00, 0.00, 'Croissance', 'Cobb 500', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(13, 'chair', 4, 1550.00, 120.00, 230.00, 0.00, 'Croissance', 'Cobb 500', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(14, 'chair', 5, 2200.00, 155.00, 300.00, 0.00, 'Finition', 'Cobb 500', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(15, 'chair', 6, 2800.00, 180.00, 350.00, 0.00, 'Finition', 'Cobb 500', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(16, 'reproducteur', 1, 150.00, 18.00, 35.00, 0.00, 'Démarrage', 'Goliath', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(17, 'reproducteur', 2, 350.00, 35.00, 65.00, 0.00, 'Démarrage', 'Goliath', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(18, 'reproducteur', 4, 850.00, 65.00, 130.00, 0.00, 'Croissance', 'Goliath', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(19, 'reproducteur', 8, 2100.00, 110.00, 220.00, 0.00, 'Croissance', 'Goliath', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(20, 'reproducteur', 12, 3500.00, 150.00, 300.00, 0.00, 'Finition', 'Goliath', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(21, 'chair', 1, 170.00, 20.00, 40.00, 0.00, 'Démarrage', 'Standard', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(22, 'chair', 2, 400.00, 40.00, 80.00, 0.00, 'Démarrage', 'Standard', '2026-03-31 09:37:34', '2026-03-31 09:37:34'),
(23, 'chair', 5, 1800.00, 130.00, 250.00, 0.00, 'Croissance', 'Standard', '2026-03-31 09:37:34', '2026-03-31 09:37:34');

-- --------------------------------------------------------

--
-- Structure de la table `protocols`
--

DROP TABLE IF EXISTS `protocols`;
CREATE TABLE IF NOT EXISTS `protocols` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `strain` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `protocols`
--

INSERT INTO `protocols` (`id`, `name`, `type`, `strain`, `description`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 'CYCLE ÉLEVAGE ISA BROWN GUINÉE', 'ponte', 'Isa Brown', 'Protocole complet de prophylaxie et démarrage pour pondeuses en climat tropical (Guinée). Phase 0-18 semaines.', NULL, '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(2, 'PONTE STANDARD AFRIQUE (ISA/LOHMANN)', 'ponte', 'Isa Brown / Lohmann', 'Programme complet de 0 à 72 semaines adapté au climat tropical. Focus Gumboro et Newcastle.', NULL, '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(3, 'CHAIR RAPIDE COBB 500 (45J)', 'chair', 'Cobb 500', 'Cycle court optimisé pour croissance rapide et protection respiratoire intense.', NULL, '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(4, 'REPRO/FERMIER RUSTIQUE (SASSO/KABIR)', 'reproducteur', 'Sasso / Kabir', 'Protocole pour souches rustiques à croissance lente et haute résistance.', NULL, '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(5, 'CYCLE ÉLEVAGE ISA BROWN GUINÉE (COPIE)', 'ponte', 'Isa Brown', 'Protocole complet de prophylaxie et démarrage pour pondeuses en climat tropical (Guinée). Phase 0-18 semaines.', '2026-04-11 11:32:36', '2026-04-11 11:15:18', '2026-04-11 11:32:36');

-- --------------------------------------------------------

--
-- Structure de la table `protocol_steps`
--

DROP TABLE IF EXISTS `protocol_steps`;
CREATE TABLE IF NOT EXISTS `protocol_steps` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `protocol_id` bigint UNSIGNED NOT NULL,
  `day_number` int NOT NULL,
  `action_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Vaccin','Traitement','Vitamine','Désinfection') COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_suggested` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `method` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `protocol_steps_protocol_id_foreign` (`protocol_id`)
) ENGINE=MyISAM AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `protocol_steps`
--

INSERT INTO `protocol_steps` (`id`, `protocol_id`, `day_number`, `action_name`, `type`, `product_suggested`, `method`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Réception + Vitamines Hydratation', 'Vitamine', NULL, 'Eau de boisson', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(2, 1, 3, 'Vaccin Newcastle + Bronchite (HB1)', 'Vaccin', NULL, 'Oculaire', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(3, 1, 7, 'Vaccin Gumboro (Intermédiaire)', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(4, 1, 10, 'Vitamines Anti-stress', 'Vitamine', NULL, 'Eau de boisson', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(5, 1, 14, 'Rappel Gumboro', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(6, 1, 21, 'Rappel Newcastle + Bronchite (Lasota)', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(7, 1, 28, 'Traitement Coccidiose (Préventif)', 'Traitement', NULL, 'Eau de boisson', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(8, 1, 35, 'Vaccin Variole Aviaire', 'Vaccin', NULL, 'Injection', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(9, 1, 42, 'Déparasitage interne', 'Traitement', NULL, 'Eau de boisson', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(10, 1, 70, 'Vaccin Coryza Contagieux', 'Vaccin', NULL, 'Injection', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(11, 1, 112, 'Rappel Newcastle Inactivé (Avant Ponte)', 'Vaccin', NULL, 'Injection', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(12, 1, 126, 'Transfert vers bâtiment de ponte + Calcium', 'Vitamine', NULL, 'Aliment', '2026-03-31 10:52:51', '2026-03-31 10:52:51'),
(13, 2, 1, 'Réception + Anti-stress (Vitamines)', 'Vitamine', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(14, 2, 3, 'Newcastle + Bronchite HB1', 'Vaccin', NULL, 'Oculaire', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(15, 2, 7, 'Gumboro (Premier passage)', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(16, 2, 14, 'Gumboro (Rappel obligatoire)', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(17, 2, 21, 'Newcastle Lasota (Rappel)', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(18, 2, 28, 'Anti-Coccidien (Amprolium)', 'Traitement', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(19, 2, 35, 'Variole Aviaire (Transfixion)', 'Vaccin', NULL, 'Injection', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(20, 2, 42, 'Déparasitage (Lévamisole)', 'Traitement', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(21, 2, 90, 'Vaccin Coryza Infectieux', 'Vaccin', NULL, 'Injection', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(22, 2, 112, 'Newcastle Inactivé (Oil)', 'Vaccin', NULL, 'Injection', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(23, 3, 1, 'Réception + Glucose/Vitamines', 'Vitamine', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(24, 3, 4, 'Newcastle + Bronchite (HB1)', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(25, 3, 10, 'Gumboro Intermédiaire', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(26, 3, 18, 'Rappel Newcastle Lasota', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(27, 3, 21, 'Traitement Coccidiose (3 jours)', 'Traitement', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(28, 3, 28, 'Vitamines Croissance / Anti-stress', 'Vitamine', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(29, 4, 1, 'Démarrage Vitamines', 'Vitamine', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(30, 4, 7, 'Newcastle HB1', 'Vaccin', NULL, 'Oculaire', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(31, 4, 14, 'Gumboro', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(32, 4, 21, 'Rappel Newcastle Lasota', 'Vaccin', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(33, 4, 45, 'Variole Aviaire', 'Vaccin', NULL, 'Injection', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(34, 4, 60, 'Déparasitage global', 'Traitement', NULL, 'Eau de boisson', '2026-03-31 11:08:49', '2026-03-31 11:08:49'),
(35, 5, 1, 'Réception + Vitamines Hydratation', 'Vitamine', NULL, 'Eau de boisson', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(36, 5, 3, 'Vaccin Newcastle + Bronchite (HB1)', 'Vaccin', NULL, 'Oculaire', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(37, 5, 7, 'Vaccin Gumboro (Intermédiaire)', 'Vaccin', NULL, 'Eau de boisson', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(38, 5, 10, 'Vitamines Anti-stress', 'Vitamine', NULL, 'Eau de boisson', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(39, 5, 14, 'Rappel Gumboro', 'Vaccin', NULL, 'Eau de boisson', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(40, 5, 21, 'Rappel Newcastle + Bronchite (Lasota)', 'Vaccin', NULL, 'Eau de boisson', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(41, 5, 28, 'Traitement Coccidiose (Préventif)', 'Traitement', NULL, 'Eau de boisson', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(42, 5, 35, 'Vaccin Variole Aviaire', 'Vaccin', NULL, 'Injection', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(43, 5, 42, 'Déparasitage interne', 'Traitement', NULL, 'Eau de boisson', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(44, 5, 70, 'Vaccin Coryza Contagieux', 'Vaccin', NULL, 'Injection', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(45, 5, 112, 'Rappel Newcastle Inactivé (Avant Ponte)', 'Vaccin', NULL, 'Injection', '2026-04-11 11:15:18', '2026-04-11 11:15:18'),
(46, 5, 126, 'Transfert vers bâtiment de ponte + Calcium', 'Vitamine', NULL, 'Aliment', '2026-04-11 11:15:18', '2026-04-11 11:15:18');

-- --------------------------------------------------------

--
-- Structure de la table `providers`
--

DROP TABLE IF EXISTS `providers`;
CREATE TABLE IF NOT EXISTS `providers` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rccm` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nif` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_terms` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reliability` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Moyen',
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Actif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `providers_provider_id_unique` (`provider_id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `providers`
--

INSERT INTO `providers` (`id`, `provider_id`, `name`, `type`, `domain`, `phone`, `email`, `address`, `rccm`, `nif`, `payment_terms`, `reliability`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'PROV-2026-001', 'BioCrest', 'Fournisseur', 'Aviculture', '+337000001', 'contact@biocrest.fr', 'Kindia', 'AGP-001', '00 10 0011', '100% livraison', 'Bon', 'Actif', '2026-03-31 11:11:53', '2026-04-08 09:22:57', NULL),
(2, 'PROV-2026-002', 'BioCrest ferme', 'Fournisseur', 'Aviculture', '+2246200000', 'contact@biocrest.fr', NULL, NULL, NULL, NULL, 'Moyen', 'Actif', '2026-04-08 09:23:36', '2026-04-08 09:25:54', NULL),
(3, 'PROV-2026-003', 'Avico', 'Poussins', NULL, '+22400000123', NULL, NULL, NULL, NULL, NULL, 'Moyen', 'Actif', '2026-05-23 09:11:48', '2026-05-23 09:11:48', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `raw_materials`
--

DROP TABLE IF EXISTS `raw_materials`;
CREATE TABLE IF NOT EXISTS `raw_materials` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kg',
  `stock_qty` decimal(15,3) NOT NULL DEFAULT '0.000',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT '0.00',
  `energy_kcal` decimal(8,2) NOT NULL DEFAULT '0.00',
  `alert_threshold` int NOT NULL DEFAULT '100',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `protein_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `lysine_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `calcium_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `raw_materials_name_unique` (`name`),
  KEY `raw_materials_stock_id_foreign` (`stock_id`)
) ENGINE=MyISAM AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `raw_materials`
--

INSERT INTO `raw_materials` (`id`, `stock_id`, `name`, `unit`, `stock_qty`, `unit_cost`, `energy_kcal`, `alert_threshold`, `is_active`, `created_at`, `updated_at`, `protein_rate`, `lysine_rate`, `calcium_rate`) VALUES
(1, NULL, 'Maïs Jaune', 'kg', 1956.000, 3487.55, 3300.00, 1000, 1, '2026-04-03 15:51:04', '2026-05-22 15:50:18', 8.50, 0.24, 0.02),
(2, NULL, 'Tourteau de Soja', 'kg', 911.000, 9542.86, 2400.00, 500, 1, '2026-04-03 15:51:04', '2026-05-22 15:50:18', 46.00, 2.90, 0.25),
(3, NULL, 'Tourteau de Palmiste', 'kg', 586.000, 3600.00, 1800.00, 500, 1, '2026-04-03 15:51:04', '2026-05-22 15:50:18', 16.00, 0.60, 0.30),
(4, NULL, 'Son de Riz', 'kg', 4478.000, 2800.00, 2100.00, 800, 1, '2026-04-03 15:51:04', '2026-05-22 15:50:18', 12.00, 0.50, 0.10),
(5, NULL, 'Coquillages (Calcaire)', 'kg', 886.500, 1500.00, 0.00, 300, 1, '2026-04-03 15:51:04', '2026-05-22 15:50:18', 0.00, 0.00, 38.00),
(6, NULL, 'Concentré Chair 5%', 'kg', 926.500, 18000.00, 2200.00, 100, 1, '2026-04-03 15:51:04', '2026-05-22 15:19:22', 35.00, 4.50, 6.00),
(7, NULL, 'Concentré Ponte 5%', 'kg', 910.000, 17500.00, 1900.00, 100, 1, '2026-04-03 15:51:04', '2026-05-22 15:50:18', 30.00, 3.80, 8.00),
(8, NULL, 'Huile Végétale', 'kg', 495.000, 15000.00, 8800.00, 50, 1, '2026-04-03 15:51:04', '2026-04-07 12:24:25', 0.00, 0.00, 0.00),
(9, NULL, 'Farine de Poisson', 'kg', 1000.000, 12000.00, 2800.00, 100, 1, '2026-04-03 15:51:04', '2026-04-03 20:04:31', 60.00, 4.50, 5.00),
(10, NULL, 'Sel de mer', 'kg', 500.000, 1000.00, 0.00, 50, 1, '2026-04-03 15:51:04', '2026-04-03 20:04:55', 0.00, 0.00, 0.00),
(12, NULL, 'Concentré Ponte 20%', 'kg', 480.000, 400.00, 0.00, 200, 1, '2026-05-19 11:43:36', '2026-05-19 11:44:38', 0.00, 0.00, 0.00);

-- --------------------------------------------------------

--
-- Structure de la table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `roles`
--

INSERT INTO `roles` (`id`, `name`, `display_name`, `icon`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'Administrateur', '', '2026-04-07 13:19:14', '2026-04-07 13:19:14'),
(2, 'worker', 'Ouvrier', '', '2026-04-07 13:19:15', '2026-04-07 13:19:15'),
(3, 'manager', 'Manager', '👤', '2026-04-07 13:29:43', '2026-04-07 13:29:43'),
(4, 'contributeur', 'Contributeur', '👤', '2026-04-11 12:50:26', '2026-04-11 12:50:26');

-- --------------------------------------------------------

--
-- Structure de la table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('wnDDnOMcoPLvQBzE79Nexf1uYcj2yDCep3tZcKWo', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiJSSFQ4bFZOOEpXM0V5dnhNZVZEd05XSDBuVTZoOFFBUXRSTUxLQmFqIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9sb2dpbiIsInJvdXRlIjoibG9naW4ifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==', 1776098344),
('r8TYk5KbexgtGj4iMrb4f1ApYyBlFVnD4duFxUk6', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiJRUGRVWEgyQ2xXUTlPVW9nd05XdFdCdHlRR2doWEd0aTBvdmIxZlFzIiwidXJsIjp7ImludGVuZGVkIjoiaHR0cDpcL1wvMTI3LjAuMC4xOjgwMDBcL2VnZy1wcm9kdWN0aW9uIn0sIl9wcmV2aW91cyI6eyJ1cmwiOiJodHRwOlwvXC8xMjcuMC4wLjE6ODAwMFwvbG9naW4iLCJyb3V0ZSI6ImxvZ2luIn0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=', 1776084076),
('Yi3OaiBZZTYc67C8E4gkdBWhEdN6FsZMYAQC9cxo', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiI3cUhiUXp5SUZLd3lnWFBjRGNYZzdtcm5nUUlNbURCblJxcllFOWYyIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9iYXRjaGVzIiwicm91dGUiOiJiYXRjaGVzLmluZGV4In0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfSwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjF9', 1776184423),
('C2rTX2B7fvIWl1UvWRuVH7d8vmd9aFPBqL3e0bGE', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiJIdW1rRXVZUXFmTGVCZ25XUTRqWFM0cnZLcGNkN2NZVzdtbkpCNEd3IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9kYXNoYm9hcmQiLCJyb3V0ZSI6ImRhc2hib2FyZCJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX0sImxvZ2luX3dlYl81OWJhMzZhZGRjMmIyZjk0MDE1ODBmMDE0YzdmNThlYTRlMzA5ODlkIjoxfQ==', 1776199689);

-- --------------------------------------------------------

--
-- Structure de la table `settings`
--

DROP TABLE IF EXISTS `settings`;
CREATE TABLE IF NOT EXISTS `settings` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `group` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `stocks`
--

DROP TABLE IF EXISTS `stocks`;
CREATE TABLE IF NOT EXISTS `stocks` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `feed_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Clé stricte pour la liaison avec les consommations (ex: Croissance, Ponte 1)',
  `unit` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unité',
  `current_quantity` decimal(15,3) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `alert_threshold` decimal(15,3) NOT NULL,
  `last_unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stocks_category_name` (`category`(50),`item_name`(100))
) ENGINE=MyISAM AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `stocks`
--

INSERT INTO `stocks` (`id`, `category`, `item_name`, `feed_type`, `unit`, `current_quantity`, `unit_price`, `alert_threshold`, `last_unit_price`, `metadata`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'conso', 'Chair Démarrage', 'Chair Démarrage', 'KG', 1080.000, 0.00, 0.000, 0.00, '{\"supplier\": \"BioCrest\", \"conso_type\": \"Aliment\", \"poultry_type\": \"Chair\"}', '2026-03-31 13:36:57', '2026-04-03 07:30:45', '2026-04-03 07:30:45'),
(2, 'conso', 'Chair Croissance', 'Chair Croissance', 'KG', 449.500, 0.00, 2.000, 0.00, '{\"supplier\": \"BioCrest\", \"conso_type\": \"Aliment\", \"poultry_type\": \"Chair\"}', '2026-03-31 14:17:25', '2026-04-03 07:30:28', '2026-04-03 07:30:28'),
(3, 'conso', 'Ponte 1 (Pic de ponte)', 'Ponte 1 (Pic de ponte)', 'KG', 0.000, 0.00, 5.000, 0.00, '{\"conso_type\": \"Aliment\", \"poultry_type\": \"Ponte\"}', '2026-03-31 14:32:45', '2026-04-03 07:30:52', '2026-04-03 07:30:52'),
(4, 'conso', 'Chair Finition', 'Chair Finition', 'KG', 0.000, 0.00, 5.000, 0.00, '{\"conso_type\": \"Aliment\", \"poultry_type\": \"Chair\"}', '2026-03-31 14:32:47', '2026-04-03 07:30:59', '2026-04-03 07:30:59'),
(5, 'oeufs', 'S', 'S', 'Alvéole', 21.430, 32000.00, 1.000, 0.00, '{\"supplier\": null, \"conso_type\": \"Aliment\"}', '2026-03-31 15:05:00', '2026-05-23 11:25:18', NULL),
(6, 'oeufs', 'M', 'M', 'Alvéole', 21.963, 35000.00, 10.000, 0.00, '[]', '2026-03-31 15:06:20', '2026-05-23 11:25:18', NULL),
(7, 'oeufs', 'L', 'L', 'Alvéole', 30.366, 38000.00, 10.000, 0.00, '[]', '2026-03-31 15:06:20', '2026-05-23 11:25:18', NULL),
(8, 'oeufs', 'XL', 'XL', 'Alvéole', 9.890, 40000.00, 10.000, 0.00, '[]', '2026-03-31 15:06:20', '2026-05-22 07:32:10', NULL),
(9, 'oeufs', 'Cassé', 'Cassé', 'Alvéole', 3.467, 20000.00, 10.000, 0.00, '[]', '2026-03-31 15:06:20', '2026-05-23 11:25:18', NULL),
(10, 'oeufs', 'Anomalie', 'Anomalie', 'Alvéole', 3.267, 0.00, 10.000, 0.00, NULL, '2026-03-31 15:06:20', '2026-05-23 11:25:18', NULL),
(11, 'litieres', 'Litieres lot 1', 'Litieres lot 1', 'Sac', 20.000, 0.00, 10.000, 0.00, '{\"supplier\": \"BioCrest\", \"conso_type\": \"Aliment\"}', '2026-04-01 07:15:17', '2026-05-21 09:49:28', NULL),
(12, 'materiels', 'Mangeoires', 'Mangeoires', 'Pcs', 9.999, 0.00, 5.000, 0.00, '[]', '2026-04-02 15:40:06', '2026-05-21 09:49:28', NULL),
(13, 'materiels', 'Abreuvoirs', 'Abreuvoirs', 'Pcs', 8.000, 0.00, 5.000, 0.00, '[]', '2026-04-02 15:40:06', '2026-05-21 12:29:18', NULL),
(14, 'materiels', 'Radiant', 'Radiant', 'Pcs', 3.999, 0.00, 2.000, 0.00, '[]', '2026-04-02 15:40:06', '2026-05-21 09:49:28', NULL),
(15, 'conso', 'Ponte Démarrage (Poussin)', 'Ponte Démarrage (Poussin)', 'KG', 870.000, 4800.00, 2.000, 0.00, '{\"supplier\": \"BioCrest\", \"conso_type\": \"Aliment\", \"poultry_type\": \"Ponte\"}', '2026-04-03 07:16:10', '2026-05-22 15:22:42', NULL),
(16, 'conso', 'Ponte Croissance (Poulette)', 'Ponte Croissance (Poulette)', 'KG', 1675.000, 4900.00, 2.000, 0.00, '{\"supplier\": \"BioCrest\", \"conso_type\": \"Aliment\", \"poultry_type\": \"Ponte\"}', '2026-04-03 07:52:14', '2026-05-22 07:38:07', NULL),
(17, 'conso', 'Chair Démarrage', 'Chair Démarrage', 'KG', 259.998, 4700.00, 50.000, 0.00, '{\"conso_type\": \"Aliment\", \"poultry_type\": \"Chair\"}', '2026-04-03 07:54:40', '2026-05-22 07:36:39', NULL),
(18, 'conso', 'Chair Croissance', 'Chair Croissance', 'KG', 710.000, 5200.00, 50.000, 0.00, '{\"conso_type\": \"Aliment\", \"poultry_type\": \"Chair\"}', '2026-04-03 07:54:40', '2026-05-22 07:35:30', NULL),
(19, 'conso', 'Chair Finition', 'Chair Finition', 'KG', 550.000, 5000.00, 50.000, 0.00, '{\"conso_type\": \"Aliment\", \"poultry_type\": \"Chair\"}', '2026-04-03 07:54:40', '2026-05-22 15:20:28', NULL),
(20, 'conso', 'Ponte 1 (Pic de ponte)', 'Ponte 1 (Pic de ponte)', 'KG', 934.998, 4900.00, 50.000, 0.00, '{\"conso_type\": \"Aliment\", \"poultry_type\": \"Ponte\"}', '2026-04-03 07:54:40', '2026-05-22 15:50:18', NULL),
(21, 'conso', 'Ponte 2 (Entretien)', 'Ponte 2 (Entretien)', 'KG', 100.000, 5000.00, 50.000, 0.00, '{\"conso_type\": \"Aliment\", \"poultry_type\": \"Ponte\"}', '2026-04-03 07:54:40', '2026-05-22 07:35:03', NULL),
(22, 'conso', 'Vaccin Gomboro', 'Vaccin Gomboro', 'Boîte', 1.000, 0.00, 0.000, 0.00, '{\"supplier\": \"BioCrest\", \"conso_type\": \"Santé\", \"poultry_type\": \"Chair\"}', '2026-04-03 08:39:13', '2026-05-21 09:49:28', NULL),
(23, 'conso', 'Amintotal', 'Amintotal', 'Flacon', 2.000, 0.00, 1.000, 0.00, '{\"supplier\": \"BioCrest\", \"conso_type\": \"Santé\", \"poultry_type\": \"Chair\"}', '2026-04-07 10:01:17', '2026-05-21 09:49:28', NULL),
(24, 'conso', 'Désincfectant', 'Désincfectant', 'Litre', 5.000, 0.00, 1.000, 0.00, '{\"supplier\": \"BioCrest ferme\", \"conso_type\": \"Hygiène\", \"poultry_type\": \"Chair\"}', '2026-04-11 08:42:19', '2026-05-21 09:49:28', NULL),
(25, 'materiels', 'Bourouette', NULL, 'Pcs', 3.000, 0.00, 1.000, 0.00, '{\"supplier\": \"BioCrest\", \"conso_type\": \"Aliment\", \"poultry_type\": \"Chair\"}', '2026-05-21 12:26:46', '2026-05-21 12:27:44', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_id` bigint UNSIGNED NOT NULL,
  `type` enum('in','out','adjustment','transfer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `reference_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_destination` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_movements_user_id_foreign` (`user_id`),
  KEY `idx_stock_movements_stock_created` (`stock_id`,`created_at`)
) ENGINE=MyISAM AUTO_INCREMENT=220 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `stock_movements`
--

INSERT INTO `stock_movements` (`id`, `stock_id`, `type`, `quantity`, `reference_type`, `source_destination`, `user_id`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 'in', 1000.000, NULL, NULL, 1, 'Stock initial', '2026-03-31 13:36:57', '2026-03-31 13:36:57'),
(2, 2, 'in', 249.500, NULL, NULL, 1, 'Stock initial', '2026-03-31 14:17:25', '2026-03-31 14:17:25'),
(3, 2, 'in', 100.000, NULL, NULL, 1, 'Achat Sac - Lot LOT-20260331-131337 | Entrée: 2 Sac -> Stock: 2 Sac', '2026-03-31 14:23:57', '2026-03-31 14:23:57'),
(4, 1, 'in', 100.000, NULL, NULL, 1, 'Achat KG - Lot LOT-20260331-131337 | Entrée: 100 KG -> Stock: 2 Sac', '2026-03-31 14:24:31', '2026-03-31 14:24:31'),
(5, 2, 'out', 50.000, NULL, NULL, 1, 'Ajustement quantité achat #1 | Entrée: 50 KG -> Stock: 1 Sac', '2026-03-31 14:52:11', '2026-03-31 14:52:11'),
(6, 2, 'in', 150.000, NULL, NULL, 1, 'Ajustement quantité achat #1 | Entrée: 150 KG -> Stock: 3 Sac', '2026-03-31 14:52:41', '2026-03-31 14:52:41'),
(7, 1, 'out', 20.000, NULL, NULL, 1, 'Consommation journalière - Lot LOT-20260331-131337 | Entrée: 20.0 KG -> Stock: 0.4 Sac', '2026-03-31 14:53:51', '2026-03-31 14:53:51'),
(8, 5, 'in', 2.000, NULL, NULL, 1, 'Stock initial', '2026-03-31 15:05:00', '2026-03-31 15:05:00'),
(9, 5, 'in', 2.200, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 2.2 Alvéole)', '2026-03-31 15:05:32', '2026-03-31 15:05:32'),
(10, 8, 'in', 1.830, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 1.8333333333333 Alvéole)', '2026-04-01 07:12:47', '2026-04-01 07:12:47'),
(11, 7, 'in', 4.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 4 Alvéole)', '2026-04-01 07:12:47', '2026-04-01 07:12:47'),
(12, 6, 'in', 2.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 2 Alvéole)', '2026-04-01 07:12:47', '2026-04-01 07:12:47'),
(13, 5, 'in', 2.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 2 Alvéole)', '2026-04-01 07:12:47', '2026-04-01 07:12:47'),
(14, 8, 'out', 0.830, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 0.83 Alvéole)', '2026-04-01 07:13:29', '2026-04-01 07:13:29'),
(15, 7, 'in', 1.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 1 Alvéole)', '2026-04-01 07:13:29', '2026-04-01 07:13:29'),
(16, 6, 'out', 0.170, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 0.16666666666667 Alvéole)', '2026-04-01 07:13:29', '2026-04-01 07:13:29'),
(17, 8, 'adjustment', 2.000, NULL, NULL, 1, 'Ajustement manuel de la fiche (Ancien: 1.00 -> Nouveau: 2)', '2026-04-01 07:14:02', '2026-04-01 07:14:02'),
(18, 11, 'in', 20.000, NULL, NULL, 1, 'Stock initial', '2026-04-01 07:15:17', '2026-04-01 07:15:17'),
(19, 8, 'in', 3.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 3 Alvéole)', '2026-04-01 17:07:50', '2026-04-01 17:07:50'),
(20, 7, 'in', 2.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 2 Alvéole)', '2026-04-01 17:07:50', '2026-04-01 17:07:50'),
(21, 5, 'in', 0.100, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 0.1 Alvéole)', '2026-04-01 17:07:50', '2026-04-01 17:07:50'),
(22, 9, 'out', 0.030, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260401-143137 (Saisie: 0.033333333333333 Alvéole)', '2026-04-01 17:07:50', '2026-04-01 17:07:50'),
(23, 10, 'out', 0.030, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260401-143137 (Saisie: 0.033333333333333 Alvéole)', '2026-04-01 17:07:50', '2026-04-01 17:07:50'),
(24, 8, 'in', 2.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 2 Alvéole)', '2026-04-01 17:27:41', '2026-04-01 17:27:41'),
(25, 7, 'in', 10.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 10 Alvéole)', '2026-04-01 17:27:41', '2026-04-01 17:27:41'),
(26, 6, 'in', 3.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 3 Alvéole)', '2026-04-01 17:27:41', '2026-04-01 17:27:41'),
(27, 5, 'in', 4.630, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 4.6333333333333 Alvéole)', '2026-04-01 17:27:41', '2026-04-01 17:27:41'),
(28, 8, 'out', 1.470, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 1.4666666666667 Alvéole)', '2026-04-01 17:28:29', '2026-04-01 17:28:29'),
(29, 7, 'in', 1.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 1 Alvéole)', '2026-04-01 17:28:29', '2026-04-01 17:28:29'),
(30, 6, 'in', 3.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 3 Alvéole)', '2026-04-01 17:28:29', '2026-04-01 17:28:29'),
(31, 5, 'out', 2.630, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 2.63 Alvéole)', '2026-04-01 17:28:29', '2026-04-01 17:28:29'),
(32, 9, 'in', 0.070, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260331-131337 (Saisie: 0.066666666666667 Alvéole)', '2026-04-01 17:28:29', '2026-04-01 17:28:29'),
(33, 10, 'in', 0.030, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260331-131337 (Saisie: 0.033333333333333 Alvéole)', '2026-04-01 17:28:29', '2026-04-01 17:28:29'),
(34, 8, 'out', 2.900, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 2.9 Alvéole)', '2026-04-01 17:43:29', '2026-04-01 17:43:29'),
(35, 7, 'in', 3.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 3 Alvéole)', '2026-04-01 17:43:29', '2026-04-01 17:43:29'),
(36, 5, 'out', 0.100, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 0.1 Alvéole)', '2026-04-01 17:43:29', '2026-04-01 17:43:29'),
(37, 8, 'in', 0.130, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 0.13333333333333 Alvéole)', '2026-04-01 17:44:51', '2026-04-01 17:44:51'),
(38, 7, 'out', 3.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 3 Alvéole)', '2026-04-01 17:44:51', '2026-04-01 17:44:51'),
(39, 6, 'in', 1.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 1 Alvéole)', '2026-04-01 17:44:51', '2026-04-01 17:44:51'),
(40, 5, 'in', 1.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 1 Alvéole)', '2026-04-01 17:44:51', '2026-04-01 17:44:51'),
(41, 9, 'in', 0.330, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260401-143137 (Saisie: 0.33333333333333 Alvéole)', '2026-04-01 17:44:51', '2026-04-01 17:44:51'),
(42, 10, 'in', 0.530, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260401-143137 (Saisie: 0.53333333333333 Alvéole)', '2026-04-01 17:44:51', '2026-04-01 17:44:51'),
(43, 8, 'in', 1.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 1 Alvéole)', '2026-04-01 18:07:15', '2026-04-01 18:07:15'),
(44, 5, 'in', 0.670, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 0.66666666666667 Alvéole)', '2026-04-01 18:07:15', '2026-04-01 18:07:15'),
(45, 5, 'out', 0.640, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 0.63666666666667 Alvéole)', '2026-04-01 18:08:33', '2026-04-01 18:08:33'),
(46, 9, 'in', 0.530, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260401-143137 (Saisie: 0.53333333333333 Alvéole)', '2026-04-01 18:08:33', '2026-04-01 18:08:33'),
(47, 10, 'in', 0.100, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260401-143137 (Saisie: 0.1 Alvéole)', '2026-04-01 18:08:33', '2026-04-01 18:08:33'),
(48, 8, 'in', 5.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 5 Alvéole)', '2026-04-01 18:56:55', '2026-04-01 18:56:55'),
(49, 7, 'in', 1.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 1 Alvéole)', '2026-04-01 18:56:55', '2026-04-01 18:56:55'),
(50, 6, 'in', 1.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 1 Alvéole)', '2026-04-01 18:56:55', '2026-04-01 18:56:55'),
(51, 5, 'in', 2.400, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 2.4 Alvéole)', '2026-04-01 18:56:55', '2026-04-01 18:56:55'),
(52, 10, 'out', 0.070, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260331-131337 (Saisie: 0.066666666666667 Alvéole)', '2026-04-01 18:56:55', '2026-04-01 18:56:55'),
(53, 8, 'out', 2.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 2 Alvéole)', '2026-04-01 18:58:34', '2026-04-01 18:58:34'),
(54, 7, 'in', 2.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 2 Alvéole)', '2026-04-01 18:58:34', '2026-04-01 18:58:34'),
(55, 6, 'in', 0.470, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 0.46666666666667 Alvéole)', '2026-04-01 18:58:34', '2026-04-01 18:58:34'),
(56, 5, 'out', 0.400, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260331-131337 (Saisie: 0.4 Alvéole)', '2026-04-01 18:58:34', '2026-04-01 18:58:34'),
(57, 9, 'out', 0.100, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260331-131337 (Saisie: 0.1 Alvéole)', '2026-04-01 18:58:34', '2026-04-01 18:58:34'),
(58, 10, 'in', 0.030, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260331-131337 (Saisie: 0.033333333333333 Alvéole)', '2026-04-01 18:58:34', '2026-04-01 18:58:34'),
(59, 8, 'in', 2.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 2 Alvéole)', '2026-04-01 19:05:19', '2026-04-01 19:05:19'),
(60, 7, 'in', 2.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 2 Alvéole)', '2026-04-01 19:05:19', '2026-04-01 19:05:19'),
(61, 6, 'in', 3.000, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 3 Alvéole)', '2026-04-01 19:05:19', '2026-04-01 19:05:19'),
(62, 5, 'in', 2.400, NULL, NULL, 1, 'Ajustement Tri Lot LOT-20260401-143137 (Saisie: 2.4 Alvéole)', '2026-04-01 19:05:19', '2026-04-01 19:05:19'),
(63, 9, 'in', 0.400, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260401-143137 (Saisie: 0.4 Alvéole)', '2026-04-01 19:05:19', '2026-04-01 19:05:19'),
(64, 10, 'in', 0.370, NULL, NULL, 1, 'Ajustement Pertes Lot LOT-20260401-143137 (Saisie: 0.36666666666667 Alvéole)', '2026-04-01 19:05:19', '2026-04-01 19:05:19'),
(65, 15, 'in', 1000.000, NULL, NULL, 1, 'Stock initial - Enregistrement article', '2026-04-03 07:16:10', '2026-04-03 07:16:10'),
(66, 16, 'in', 500.000, NULL, NULL, 1, 'Stock initial - Enregistrement article', '2026-04-03 07:52:14', '2026-04-03 07:52:14'),
(67, 18, 'in', 10.000, NULL, NULL, 1, NULL, '2026-04-03 08:12:50', '2026-04-03 08:12:50'),
(68, 20, 'in', 50000.000, NULL, NULL, 1, 'Achat Sac - Lot LOT-20260401-143137 | Entrée: 20 Sac -> Stock: 1000 KG', '2026-04-03 08:13:52', '2026-04-03 08:13:52'),
(69, 17, 'out', 100.000, NULL, NULL, 1, 'ANNULATION ACHAT #6 - Lot LOT-20260401-143137 | Entrée: 2.00 KG -> Stock: 2 KG', '2026-04-03 08:14:05', '2026-04-03 08:14:05'),
(70, 20, 'adjustment', 55000.000, NULL, NULL, 1, 'Ajustement manuel (Ancien: 1000.000 -> Nouveau: 1100)', '2026-04-03 08:15:06', '2026-04-03 08:15:06'),
(71, 17, 'adjustment', 499.900, NULL, NULL, 1, 'Ajustement manuel (Ancien: -2.000 -> Nouveau: 9.998)', '2026-04-03 08:15:28', '2026-04-03 08:15:28'),
(72, 15, 'in', 50.000, NULL, NULL, 1, NULL, '2026-04-03 08:29:08', '2026-04-03 08:29:08'),
(73, 22, 'in', 1.000, NULL, NULL, 1, 'Achat Boîte - Lot LOT-20260401-143137 (Santé) | Entrée: 1 Boîte -> Stock: 1 Boîte', '2026-04-03 08:39:13', '2026-04-03 08:39:13'),
(74, 15, 'out', 15.000, NULL, NULL, 1, 'Consommation journalière - Lot LOT-20260331-131337 | Entrée: 15.0 KG -> Stock: 15 KG', '2026-04-03 09:02:01', '2026-04-03 09:02:01'),
(75, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:44:57', '2026-04-03 11:44:57'),
(76, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:44:57', '2026-04-03 11:44:57'),
(77, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:44:57', '2026-04-03 11:44:57'),
(78, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:45:46', '2026-04-03 11:45:46'),
(79, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:45:46', '2026-04-03 11:45:46'),
(80, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:45:46', '2026-04-03 11:45:46'),
(81, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:49:58', '2026-04-03 11:49:58'),
(82, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:49:58', '2026-04-03 11:49:58'),
(83, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:49:58', '2026-04-03 11:49:58'),
(84, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:50:04', '2026-04-03 11:50:04'),
(85, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:50:04', '2026-04-03 11:50:04'),
(86, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:50:04', '2026-04-03 11:50:04'),
(87, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:51:41', '2026-04-03 11:51:41'),
(88, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:51:41', '2026-04-03 11:51:41'),
(89, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 11:51:41', '2026-04-03 11:51:41'),
(90, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:02:06', '2026-04-03 12:02:06'),
(91, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:02:06', '2026-04-03 12:02:06'),
(92, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:02:06', '2026-04-03 12:02:06'),
(93, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:05:18', '2026-04-03 12:05:18'),
(94, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:05:18', '2026-04-03 12:05:18'),
(95, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:05:18', '2026-04-03 12:05:18'),
(96, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:07:41', '2026-04-03 12:07:41'),
(97, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:07:41', '2026-04-03 12:07:41'),
(98, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:07:41', '2026-04-03 12:07:41'),
(99, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:11:08', '2026-04-03 12:11:08'),
(100, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:11:08', '2026-04-03 12:11:08'),
(101, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:11:08', '2026-04-03 12:11:08'),
(102, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:13:01', '2026-04-03 12:13:01'),
(103, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:13:01', '2026-04-03 12:13:01'),
(104, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:13:01', '2026-04-03 12:13:01'),
(105, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:17:17', '2026-04-03 12:17:17'),
(106, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:17:17', '2026-04-03 12:17:17'),
(107, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:17:17', '2026-04-03 12:17:17'),
(108, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:19:20', '2026-04-03 12:19:20'),
(109, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:19:20', '2026-04-03 12:19:20'),
(110, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:19:20', '2026-04-03 12:19:20'),
(111, 12, 'out', 105.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:20:43', '2026-04-03 12:20:43'),
(112, 13, 'out', 30.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:20:43', '2026-04-03 12:20:43'),
(113, 14, 'out', 15.000, 'App\\Models\\MillProduction', '3', 1, 'Consommation pour lot OP-20260403-FA53', '2026-04-03 12:20:43', '2026-04-03 12:20:43'),
(114, 15, 'in', 7500.000, NULL, NULL, 1, NULL, '2026-04-03 12:20:43', '2026-04-03 12:20:43'),
(115, 12, 'out', 35.000, 'App\\Models\\MillProduction', '4', 1, 'Consommation pour lot OP-20260403-J6H3', '2026-04-03 12:23:51', '2026-04-03 12:23:51'),
(116, 13, 'out', 10.000, 'App\\Models\\MillProduction', '4', 1, 'Consommation pour lot OP-20260403-J6H3', '2026-04-03 12:23:51', '2026-04-03 12:23:51'),
(117, 14, 'out', 5.000, 'App\\Models\\MillProduction', '4', 1, 'Consommation pour lot OP-20260403-J6H3', '2026-04-03 12:23:51', '2026-04-03 12:23:51'),
(118, 15, 'in', 2500.000, NULL, NULL, 1, NULL, '2026-04-03 12:23:51', '2026-04-03 12:23:51'),
(119, 12, 'out', 35.000, 'App\\Models\\MillProduction', '5', 1, 'Consommation pour lot OP-20260403-3L1R', '2026-04-03 12:29:10', '2026-04-03 12:29:10'),
(120, 13, 'out', 10.000, 'App\\Models\\MillProduction', '5', 1, 'Consommation pour lot OP-20260403-3L1R', '2026-04-03 12:29:10', '2026-04-03 12:29:10'),
(121, 14, 'out', 5.000, 'App\\Models\\MillProduction', '5', 1, 'Consommation pour lot OP-20260403-3L1R', '2026-04-03 12:29:10', '2026-04-03 12:29:10'),
(122, 15, 'in', 50.000, NULL, NULL, 1, NULL, '2026-04-03 12:29:10', '2026-04-03 12:29:10'),
(123, 15, 'adjustment', 185.000, NULL, NULL, 1, 'Ajustement manuel (Nouvelle valeur en KG : 185)', '2026-04-03 12:29:42', '2026-04-03 12:29:42'),
(124, 20, 'adjustment', 100.000, NULL, NULL, 1, 'Ajustement manuel (Nouvelle valeur en KG : 100)', '2026-04-03 12:33:49', '2026-04-03 12:33:49'),
(125, 15, 'in', 750.000, NULL, NULL, 1, NULL, '2026-04-03 12:53:19', '2026-04-03 12:53:19'),
(126, 16, 'out', 500.000, NULL, NULL, 1, NULL, '2026-04-03 12:53:19', '2026-04-03 12:53:19'),
(127, 16, 'in', 500.000, NULL, NULL, 1, NULL, '2026-04-03 12:55:47', '2026-04-03 12:55:47'),
(128, 20, 'out', 250.000, NULL, NULL, 1, NULL, '2026-04-03 12:55:47', '2026-04-03 12:55:47'),
(129, 20, 'adjustment', 299.998, NULL, NULL, 1, 'Ajustement manuel (Nouvelle valeur en KG : 299.998)', '2026-04-03 13:06:15', '2026-04-03 13:06:15'),
(130, 15, 'out', 35.000, NULL, NULL, 1, NULL, '2026-04-03 13:06:53', '2026-04-03 13:06:53'),
(131, 21, 'in', 100.000, NULL, NULL, 1, NULL, '2026-04-03 20:20:14', '2026-04-03 20:20:14'),
(132, 19, 'in', 250.000, NULL, NULL, 1, NULL, '2026-04-03 20:22:06', '2026-04-03 20:22:06'),
(133, 15, 'in', 35.000, NULL, NULL, 1, NULL, '2026-04-03 20:23:32', '2026-04-03 20:23:32'),
(134, 16, 'out', 50.000, NULL, NULL, 1, NULL, '2026-04-03 20:23:32', '2026-04-03 20:23:32'),
(135, 17, 'out', 5.000, NULL, NULL, 1, NULL, '2026-04-05 09:37:17', '2026-04-05 09:37:17'),
(136, 7, 'in', 2.000, NULL, NULL, 1, NULL, '2026-04-06 08:15:03', '2026-04-06 08:15:03'),
(137, 6, 'in', 5.000, NULL, NULL, 1, NULL, '2026-04-06 08:15:03', '2026-04-06 08:15:03'),
(138, 5, 'in', 2.900, NULL, NULL, 1, NULL, '2026-04-06 08:15:03', '2026-04-06 08:15:03'),
(139, 9, 'in', 0.033, NULL, NULL, 1, NULL, '2026-04-06 08:15:03', '2026-04-06 08:15:03'),
(140, 10, 'in', 0.067, NULL, NULL, 1, NULL, '2026-04-06 08:15:03', '2026-04-06 08:15:03'),
(141, 16, 'in', 500.000, NULL, NULL, 1, NULL, '2026-04-06 10:54:45', '2026-04-06 10:54:45'),
(142, 18, 'in', 500.000, NULL, NULL, 1, NULL, '2026-04-06 13:48:53', '2026-04-06 13:48:53'),
(143, 20, 'in', 200.000, NULL, NULL, 1, NULL, '2026-04-06 14:53:30', '2026-04-06 14:53:30'),
(144, 16, 'in', 1000.000, NULL, NULL, 1, NULL, '2026-04-06 15:11:29', '2026-04-06 15:11:29'),
(145, 17, 'in', 5.000, NULL, NULL, 1, NULL, '2026-04-07 07:18:44', '2026-04-07 07:18:44'),
(146, 18, 'in', 100.000, NULL, NULL, 1, NULL, '2026-04-07 07:44:58', '2026-04-07 07:44:58'),
(147, 20, 'in', 100.000, NULL, NULL, 1, NULL, '2026-04-07 08:04:58', '2026-04-07 08:04:58'),
(148, 19, 'in', 150.000, NULL, NULL, 1, NULL, '2026-04-07 08:59:39', '2026-04-07 08:59:39'),
(149, 11, 'out', 1.000, NULL, NULL, 1, NULL, '2026-04-07 09:00:56', '2026-04-07 09:00:56'),
(150, 5, 'in', 1.000, NULL, NULL, 1, NULL, '2026-04-07 09:01:27', '2026-04-07 09:01:27'),
(151, 5, 'in', 50.000, NULL, NULL, 1, NULL, '2026-04-07 09:01:40', '2026-04-07 09:01:40'),
(152, 5, 'out', 1.000, NULL, NULL, 1, NULL, '2026-04-07 09:18:43', '2026-04-07 09:18:43'),
(153, 13, 'adjustment', 0.000, NULL, NULL, 1, 'Ajustement manuel (Nouvelle valeur : 0 Pcs)', '2026-04-07 09:57:20', '2026-04-07 09:57:20'),
(154, 12, 'adjustment', 9.999, NULL, NULL, 1, 'Ajustement manuel (Nouvelle valeur : 9.999 Pcs)', '2026-04-07 09:57:40', '2026-04-07 09:57:40'),
(155, 14, 'adjustment', 3.999, NULL, NULL, 1, 'Ajustement manuel (Nouvelle valeur : 3.999 Pcs)', '2026-04-07 09:58:01', '2026-04-07 09:58:01'),
(156, 11, 'in', 1.000, NULL, NULL, 1, 'Flux express via interface de gestion', '2026-04-07 10:00:24', '2026-04-07 10:00:24'),
(157, 23, 'in', 2.000, NULL, NULL, 1, 'Stock initial (Saisie originale : 2 Flacon)', '2026-04-07 10:01:17', '2026-04-07 10:01:17'),
(158, 17, 'in', 100.000, NULL, NULL, 1, NULL, '2026-04-07 11:53:01', '2026-04-07 11:53:01'),
(159, 17, 'in', 150.000, NULL, NULL, 1, NULL, '2026-04-07 12:24:25', '2026-04-07 12:24:25'),
(160, 18, 'in', 100.000, NULL, NULL, 2, NULL, '2026-04-07 14:52:17', '2026-04-07 14:52:17'),
(161, 8, 'in', 0.033, NULL, NULL, 1, NULL, '2026-04-08 14:21:47', '2026-04-08 14:21:47'),
(162, 7, 'in', 1.000, NULL, NULL, 1, NULL, '2026-04-08 14:21:47', '2026-04-08 14:21:47'),
(163, 6, 'in', 1.000, NULL, NULL, 1, NULL, '2026-04-08 14:21:47', '2026-04-08 14:21:47'),
(164, 5, 'in', 0.900, NULL, NULL, 1, NULL, '2026-04-08 14:21:47', '2026-04-08 14:21:47'),
(165, 9, 'in', 0.033, NULL, NULL, 1, NULL, '2026-04-08 14:21:47', '2026-04-08 14:21:47'),
(166, 10, 'in', 0.033, NULL, NULL, 1, NULL, '2026-04-08 14:21:47', '2026-04-08 14:21:47'),
(167, 24, 'in', 5.000, NULL, NULL, 1, 'Initialisation (Valeur d\'entrée : 5 Litre)', '2026-04-11 08:42:19', '2026-04-11 08:42:19'),
(168, 13, 'in', 2.000, NULL, NULL, 1, 'Mouvement de stock manuel', '2026-04-11 08:47:03', '2026-04-11 08:47:03'),
(169, 15, 'out', 0.000, NULL, NULL, 1, NULL, '2026-04-12 15:27:51', '2026-04-12 15:27:51'),
(170, 15, 'out', 0.000, NULL, NULL, 1, NULL, '2026-04-20 16:05:30', '2026-04-20 16:05:30'),
(171, 20, 'in', 250.000, NULL, NULL, 1, NULL, '2026-05-19 11:20:34', '2026-05-19 11:20:34'),
(172, 16, 'out', 55.000, NULL, NULL, 1, NULL, '2026-05-19 12:30:28', '2026-05-19 12:30:28'),
(173, 16, 'out', 55.000, NULL, NULL, 1, NULL, '2026-05-19 12:59:40', '2026-05-19 12:59:40'),
(174, 16, 'out', 55.000, NULL, NULL, 1, NULL, '2026-05-19 13:00:00', '2026-05-19 13:00:00'),
(175, 16, 'out', 55.000, NULL, NULL, 1, NULL, '2026-05-19 13:03:20', '2026-05-19 13:03:20'),
(176, 16, 'out', 55.000, NULL, NULL, 1, NULL, '2026-05-19 13:09:16', '2026-05-19 13:09:16'),
(177, 7, 'in', 2.000, NULL, NULL, 1, NULL, '2026-05-20 07:57:45', '2026-05-20 07:57:45'),
(178, 6, 'in', 0.333, NULL, NULL, 1, NULL, '2026-05-20 07:57:45', '2026-05-20 07:57:45'),
(179, 9, 'in', 0.367, NULL, NULL, 1, NULL, '2026-05-20 07:57:45', '2026-05-20 07:57:45'),
(180, 10, 'in', 0.300, NULL, NULL, 1, NULL, '2026-05-20 07:57:45', '2026-05-20 07:57:45'),
(181, 15, 'out', 25.000, NULL, NULL, 1, NULL, '2026-05-20 20:47:13', '2026-05-20 20:47:13'),
(182, 20, 'out', 15.000, NULL, NULL, 1, '[SYNC] Consommation journalière lot LOT-20260519-093526 | Saisie: 15 KG → Appliqué: 15 KG', '2026-05-21 10:04:15', '2026-05-21 10:04:15'),
(183, 13, 'in', 1.000, NULL, NULL, 1, 'Mouvement de stock manuel', '2026-05-21 12:25:18', '2026-05-21 12:25:18'),
(184, 25, 'in', 4.000, NULL, NULL, 1, 'Initialisation (Valeur d\'entrée : 4 Pcs)', '2026-05-21 12:26:46', '2026-05-21 12:26:46'),
(185, 25, 'adjustment', 1.000, NULL, NULL, 1, 'Ajustement fiche (Précédent: 4 -> Nouveau: 3 Pcs)', '2026-05-21 12:27:44', '2026-05-21 12:27:44'),
(186, 13, 'in', 5.000, NULL, NULL, 1, 'Mouvement de stock manuel', '2026-05-21 12:29:04', '2026-05-21 12:29:04'),
(187, 8, 'in', 2.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260519-093526 — calibre XL | Saisie: 2 Alvéole → Appliqué: 2 Alvéole', '2026-05-21 12:30:41', '2026-05-21 12:30:41'),
(188, 7, 'in', 0.333, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260519-093526 — calibre L | Saisie: 0.3333 Alvéole → Appliqué: 0.33 Alvéole', '2026-05-21 12:30:42', '2026-05-21 12:30:42'),
(189, 6, 'in', 2.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260519-093526 — calibre M | Saisie: 2 Alvéole → Appliqué: 2 Alvéole', '2026-05-21 12:30:42', '2026-05-21 12:30:42'),
(190, 5, 'in', 2.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260519-093526 — calibre S | Saisie: 2 Alvéole → Appliqué: 2 Alvéole', '2026-05-21 12:30:42', '2026-05-21 12:30:42'),
(191, 9, 'in', 0.333, NULL, NULL, 1, '[SYNC] Ajustement pertes lot LOT-20260519-093526 | Saisie: 0.3333 Alvéole → Appliqué: 0.33 Alvéole', '2026-05-21 12:30:42', '2026-05-21 12:30:42'),
(192, 10, 'in', 0.267, NULL, NULL, 1, '[SYNC] Ajustement pertes lot LOT-20260519-093526 | Saisie: 0.2667 Alvéole → Appliqué: 0.27 Alvéole', '2026-05-21 12:30:42', '2026-05-21 12:30:42'),
(193, 8, 'in', 0.100, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre XL | Saisie: 0.1 Alvéole → Appliqué: 0.1 Alvéole', '2026-05-21 12:32:21', '2026-05-21 12:32:21'),
(194, 7, 'in', 2.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre L | Saisie: 2 Alvéole → Appliqué: 2 Alvéole', '2026-05-21 12:32:21', '2026-05-21 12:32:21'),
(195, 6, 'in', 0.367, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre M | Saisie: 0.3667 Alvéole → Appliqué: 0.37 Alvéole', '2026-05-21 12:32:21', '2026-05-21 12:32:21'),
(196, 5, 'in', 2.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre S | Saisie: 2 Alvéole → Appliqué: 2 Alvéole', '2026-05-21 12:32:21', '2026-05-21 12:32:21'),
(197, 9, 'in', 0.067, NULL, NULL, 1, '[SYNC] Ajustement pertes lot LOT-20260331-131337 | Saisie: 0.0667 Alvéole → Appliqué: 0.07 Alvéole', '2026-05-21 12:32:21', '2026-05-21 12:32:21'),
(198, 10, 'in', 0.100, NULL, NULL, 1, '[SYNC] Ajustement pertes lot LOT-20260331-131337 | Saisie: 0.1 Alvéole → Appliqué: 0.1 Alvéole', '2026-05-21 12:32:21', '2026-05-21 12:32:21'),
(199, 7, 'in', 0.033, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre L | Saisie: 0.0333 Alvéole → Appliqué: 0.03 Alvéole', '2026-05-21 14:59:12', '2026-05-21 14:59:12'),
(200, 6, 'out', 0.037, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre M | Saisie: 0.0367 Alvéole → Appliqué: 0.04 Alvéole', '2026-05-21 14:59:12', '2026-05-21 14:59:12'),
(201, 7, 'out', 1.000, NULL, NULL, 1, 'Mouvement de stock manuel', '2026-05-21 15:13:37', '2026-05-21 15:13:37'),
(202, 6, 'out', 1.000, NULL, NULL, 1, 'Mouvement de stock manuel', '2026-05-21 15:24:15', '2026-05-21 15:24:15'),
(203, 7, 'in', 1.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260409-131317 — calibre L | Saisie: 1 Alvéole → Appliqué: 1 Alvéole', '2026-05-21 15:25:16', '2026-05-21 15:25:16'),
(204, 15, 'out', 20.000, NULL, NULL, 1, '[SYNC] Consommation journalière lot LOT-20260519-093526 | Saisie: 20 KG → Appliqué: 20 KG', '2026-05-22 07:40:09', '2026-05-22 07:40:09'),
(205, 7, 'out', 1.000, NULL, NULL, 1, 'Mouvement de stock manuel', '2026-05-22 08:07:56', '2026-05-22 08:07:56'),
(206, 19, 'in', 250.000, NULL, NULL, 1, '[SYNC] Production OP #OP-20260522-1718-V8AK | Saisie: 250 KG → Appliqué: 250 KG', '2026-05-22 15:19:22', '2026-05-22 15:19:22'),
(207, 19, 'out', 100.000, NULL, NULL, 1, 'Mouvement de stock manuel', '2026-05-22 15:20:28', '2026-05-22 15:20:28'),
(208, 15, 'in', 20.000, NULL, NULL, 1, '[SYNC] Correction pointage lot LOT-20260519-093526 (annulation ancienne conso) | Saisie: 20 KG → Appliqué: 20 KG', '2026-05-22 15:21:38', '2026-05-22 15:21:38'),
(209, 15, 'out', 10.000, NULL, NULL, 1, '[SYNC] Consommation journalière lot LOT-20260519-093526 | Saisie: 10 KG → Appliqué: 10 KG', '2026-05-22 15:21:38', '2026-05-22 15:21:38'),
(210, 15, 'in', 10.000, NULL, NULL, 1, '[SYNC] Rectification pointage #15 (annulation) | Saisie: 10 KG → Appliqué: 10 KG', '2026-05-22 15:22:20', '2026-05-22 15:22:20'),
(211, 15, 'out', 40.000, NULL, NULL, 1, '[SYNC] Rectification pointage #15 (nouvelle conso) | Saisie: 40 KG → Appliqué: 40 KG', '2026-05-22 15:22:20', '2026-05-22 15:22:20'),
(212, 15, 'in', 40.000, NULL, NULL, 1, '[SYNC] Rectification pointage #15 (annulation) | Saisie: 40 KG → Appliqué: 40 KG', '2026-05-22 15:22:42', '2026-05-22 15:22:42'),
(213, 15, 'out', 40.000, NULL, NULL, 1, '[SYNC] Rectification pointage #15 (nouvelle conso) | Saisie: 40 KG → Appliqué: 40 KG', '2026-05-22 15:22:42', '2026-05-22 15:22:42'),
(214, 20, 'in', 100.000, NULL, NULL, 1, '[SYNC] Production OP #OP-20260522-1717-KNNJ | Saisie: 100 KG → Appliqué: 100 KG', '2026-05-22 15:50:18', '2026-05-22 15:50:18'),
(215, 7, 'in', 1.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre L | Saisie: 1 Alvéole → Appliqué: 1 Alvéole', '2026-05-23 11:25:18', '2026-05-23 11:25:18'),
(216, 6, 'in', 1.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre M | Saisie: 1 Alvéole → Appliqué: 1 Alvéole', '2026-05-23 11:25:18', '2026-05-23 11:25:18'),
(217, 5, 'in', 1.000, NULL, NULL, 1, '[SYNC] Tri lot LOT-20260331-131337 — calibre S | Saisie: 1 Alvéole → Appliqué: 1 Alvéole', '2026-05-23 11:25:18', '2026-05-23 11:25:18'),
(218, 9, 'in', 0.267, NULL, NULL, 1, '[SYNC] Ajustement pertes lot LOT-20260331-131337 | Saisie: 0.2667 Alvéole → Appliqué: 0.27 Alvéole', '2026-05-23 11:25:18', '2026-05-23 11:25:18'),
(219, 10, 'in', 0.133, NULL, NULL, 1, '[SYNC] Ajustement pertes lot LOT-20260331-131337 | Saisie: 0.1333 Alvéole → Appliqué: 0.13 Alvéole', '2026-05-23 11:25:18', '2026-05-23 11:25:18');

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `role` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'worker' COMMENT 'DEPRECATED 2026-05 : Utiliser role_id → roles → permissions. Voir BatchObserver et DashboardController pour les usages legacy.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_role_id_foreign` (`role_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `role_id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`, `created_at`, `updated_at`, `role`) VALUES
(1, 1, 'Moussa Touré', 'admin@test.com', NULL, '$2y$12$3.ZSYthsODn2GM6U5KYKPujPGwag1I25TcErjr3G1zz0i3f6j.zJi', NULL, '2026-03-31 09:32:35', '2026-05-21 10:41:39', 'admin'),
(2, 3, 'Manager', 'manager@test.com', NULL, '$2y$12$NsE.RlBgQYZSEM0G6Sen1.UhCkCVNXNpAUW8KQ64yVoKI8YiBT4N2', NULL, '2026-04-07 13:33:57', '2026-05-20 17:31:26', 'worker');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

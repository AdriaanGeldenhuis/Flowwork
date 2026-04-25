-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: dedi321.cpt1.host-h.net
-- Generation Time: Mar 02, 2026 at 11:21 AM
-- Server version: 10.11.16-MariaDB-deb11
-- PHP Version: 8.2.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `flowwwqmnt_db1`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_actions`
--

CREATE TABLE `ai_actions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) NOT NULL,
  `query_id` bigint(20) UNSIGNED NOT NULL,
  `candidate_id` bigint(20) UNSIGNED NOT NULL,
  `action` enum('call','whatsapp','email','add_to_crm','rfq','view') NOT NULL,
  `details_json` longtext DEFAULT NULL,
  `user_id` int(10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ai_actions`
--

INSERT INTO `ai_actions` (`id`, `company_id`, `query_id`, `candidate_id`, `action`, `details_json`, `user_id`, `created_at`) VALUES
(1, 1, 12, 0, 'whatsapp', '{\"timestamp\":1759405750}', 1, '2025-10-02 13:49:10');

-- --------------------------------------------------------

--
-- Table structure for table `ai_candidates`
--

CREATE TABLE `ai_candidates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) NOT NULL,
  `query_id` bigint(20) UNSIGNED NOT NULL,
  `rank` int(11) NOT NULL,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK to crm_accounts',
  `name` varchar(255) NOT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `categories_json` longtext DEFAULT NULL,
  `distance_km` decimal(6,2) DEFAULT NULL,
  `compliance_state` enum('valid','expiring','expired','missing') DEFAULT 'missing',
  `performance_json` longtext DEFAULT NULL COMMENT 'JSON: on_time_pct, defect_rate, response_hours',
  `score_final` decimal(5,2) NOT NULL DEFAULT 0.00,
  `explanation` text DEFAULT NULL COMMENT 'AI-generated "why this supplier"',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ai_candidates`
--

INSERT INTO `ai_candidates` (`id`, `company_id`, `query_id`, `rank`, `account_id`, `name`, `phone`, `email`, `website`, `address`, `lat`, `lng`, `categories_json`, `distance_km`, `compliance_state`, `performance_json`, `score_final`, `explanation`, `created_at`) VALUES
(1, 1, 4, 1, 1, 'Adriaan', NULL, NULL, NULL, NULL, NULL, NULL, '[]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null,\"defect_rate\":null}', 0.80, 'Top result because: existing supplier in your CRM; all compliance docs valid.', '2025-10-02 12:26:15'),
(2, 1, 8, 1, 6, 'Quick Plumbers ZA', '0837654321', 'quotes@quickplumbers.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'missing', '{\"on_time_pct\":null,\"response_hours\":null,\"defect_rate\":null}', 1.00, 'Top result: existing supplier in your CRM; marked as preferred; matches category: plumber.', '2025-10-02 13:28:04'),
(3, 1, 11, 1, 6, 'Quick Plumbers ZA', '0837654321', 'quotes@quickplumbers.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.85, 'Found via crm', '2025-10-02 13:39:35'),
(4, 1, 11, 2, 1, 'Adriaan', NULL, NULL, NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:39:35'),
(5, 1, 11, 3, 3, 'Joe Plumbing', '0821234567', 'joe@plumbing.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:39:35'),
(6, 1, 11, 4, 4, 'ABC Electrical', '0837654321', 'info@abcelectrical.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:39:35'),
(7, 1, 11, 5, 5, 'Vanderbijlpark Plumbing Services', '0821234567', 'info@vbplumbing.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:39:35'),
(8, 1, 11, 6, 7, 'Elite Plumbing & Geysers', '0612345678', 'contact@eliteplumbing.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:39:35'),
(9, 1, 11, 7, 8, 'Joe Electrical Services', '0829876543', 'joe@electrical.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:39:35'),
(10, 1, 11, 8, 9, 'ABC General Contractors', '0813456789', 'info@abccontractors.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:39:35'),
(11, 1, 12, 1, 6, 'Quick Plumbers ZA', '0837654321', 'quotes@quickplumbers.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.85, 'Found via crm', '2025-10-02 13:45:48'),
(12, 1, 12, 2, 1, 'Adriaan', NULL, NULL, NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:45:48'),
(13, 1, 12, 3, 3, 'Joe Plumbing', '0821234567', 'joe@plumbing.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:45:48'),
(14, 1, 12, 4, 4, 'ABC Electrical', '0837654321', 'info@abcelectrical.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:45:48'),
(15, 1, 12, 5, 5, 'Vanderbijlpark Plumbing Services', '0821234567', 'info@vbplumbing.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:45:48'),
(16, 1, 12, 6, 7, 'Elite Plumbing & Geysers', '0612345678', 'contact@eliteplumbing.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:45:48'),
(17, 1, 12, 7, 8, 'Joe Electrical Services', '0829876543', 'joe@electrical.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:45:48'),
(18, 1, 12, 8, 9, 'ABC General Contractors', '0813456789', 'info@abccontractors.co.za', NULL, NULL, NULL, NULL, '[\"plumber\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:45:48'),
(19, 1, 13, 1, 1, 'Adriaan', NULL, NULL, NULL, NULL, NULL, NULL, '[\"electrician\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:46:50'),
(20, 1, 13, 2, 3, 'Joe Plumbing', '0821234567', 'joe@plumbing.co.za', NULL, NULL, NULL, NULL, '[\"electrician\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:46:50'),
(21, 1, 13, 3, 4, 'ABC Electrical', '0837654321', 'info@abcelectrical.co.za', NULL, NULL, NULL, NULL, '[\"electrician\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:46:50'),
(22, 1, 13, 4, 5, 'Vanderbijlpark Plumbing Services', '0821234567', 'info@vbplumbing.co.za', NULL, NULL, NULL, NULL, '[\"electrician\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:46:50'),
(23, 1, 13, 5, 6, 'Quick Plumbers ZA', '0837654321', 'quotes@quickplumbers.co.za', NULL, NULL, NULL, NULL, '[\"electrician\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:46:50'),
(24, 1, 13, 6, 7, 'Elite Plumbing & Geysers', '0612345678', 'contact@eliteplumbing.co.za', NULL, NULL, NULL, NULL, '[\"electrician\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:46:50'),
(25, 1, 13, 7, 8, 'Joe Electrical Services', '0829876543', 'joe@electrical.co.za', NULL, NULL, NULL, NULL, '[\"electrician\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:46:50'),
(26, 1, 13, 8, 9, 'ABC General Contractors', '0813456789', 'info@abccontractors.co.za', NULL, NULL, NULL, NULL, '[\"electrician\"]', NULL, 'valid', '{\"on_time_pct\":null,\"response_hours\":null}', 0.70, 'Found via crm', '2025-10-02 13:46:50'),
(27, 1, 14, 1, NULL, 'Plumb-It', '0112345678', 'info@plumbit.co.za', 'https://plumbit.co.za', '45 Main Road, Vanderbijlpark, Gauteng, South Africa', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.85, 'Professional plumbing services for residential and commercial properties.', '2025-10-02 13:50:57'),
(28, 1, 14, 2, NULL, 'Mr. Fix It Plumbing', '0829876543', 'contact@mrfixit.co.za', 'https://mrfixit.co.za', '12 Industrial Street, Vanderbijlpark, Gauteng, South Africa', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.85, 'Expert plumbing solutions with a focus on customer satisfaction.', '2025-10-02 13:50:57'),
(29, 1, 14, 3, NULL, 'The Plumber', '0118765432', 'info@theplumber.co.za', 'https://theplumber.co.za', '78 Water Street, Vanderbijlpark, Gauteng, South Africa', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.85, 'Reliable plumbing services for all your needs.', '2025-10-02 13:50:57'),
(30, 1, 14, 4, NULL, 'Plumbing 24/7', '0123456789', 'support@plumbing247.co.za', 'https://plumbing247.co.za', '34 Service Road, Vanderbijlpark, Gauteng, South Africa', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.85, 'Emergency plumbing services available around the clock.', '2025-10-02 13:50:57'),
(31, 1, 14, 5, NULL, 'Vanderbijlpark Plumbing', '0832345678', 'info@vanderbijlparkplumbing.co.za', 'https://vanderbijlparkplumbing.co.za', '9 Riverside Drive, Vanderbijlpark, Gauteng, South Africa', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.85, 'Local plumbing experts offering a wide range of services.', '2025-10-02 13:50:57'),
(32, 1, 15, 1, NULL, 'Voltex', '0123456789', 'info@voltex.co.za', 'https://www.voltex.co.za', '1234 Pretorius Street, Pretoria, Gauteng, South Africa', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.85, 'Leading supplier of electrical and lighting products.', '2025-10-02 13:52:48'),
(33, 1, 15, 2, NULL, 'Stuttaford Van Lines', '0112345678', 'info@svl.co.za', 'https://www.stuttafordvanlines.co.za', '5678 Van Der Walt Street, Pretoria, Gauteng, South Africa', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.85, 'Provides electrical solutions and installations.', '2025-10-02 13:52:48'),
(34, 1, 15, 3, NULL, 'Electro-Tech', '0829876543', 'info@electro-tech.co.za', 'https://www.electro-tech.co.za', '9101 Jacob Mare Street, Pretoria, Gauteng, South Africa', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.85, 'Expert electricians for residential and commercial needs.', '2025-10-02 13:52:48'),
(35, 1, 15, 4, NULL, 'Kusile Electrical', '0118765432', 'info@kusileelectrical.co.za', 'https://www.kusileelectrical.co.za', '4321 Church Street, Pretoria, Gauteng, South Africa', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.85, 'Specialized in renewable energy and electrical installations.', '2025-10-02 13:52:48'),
(36, 1, 15, 5, NULL, 'Murray & Roberts', '0124567890', 'info@murrayandroberts.co.za', 'https://www.murrob.com', '6789 Pretorius Street, Pretoria, Gauteng, South Africa', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.85, 'Provides comprehensive electrical and engineering solutions.', '2025-10-02 13:52:48'),
(37, 1, 15, 6, NULL, 'Eskom', '0860037566', 'info@eskom.co.za', 'https://www.eskom.co.za', '1 Maxwell Drive, Pretoria, Gauteng, South Africa', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.85, 'South Africa\'s main electricity supplier.', '2025-10-02 13:52:48'),
(38, 1, 16, 1, NULL, 'PlumbLink', '0111234567', 'info@plumblink.co.za', 'https://plumblink.co.za', 'Cnr. Voortrekker Ave & Frikkie Meyer Blvd, Vanderbijlpark', NULL, NULL, '[\"plumbers\"]', NULL, 'missing', '[]', 0.85, 'PlumbLink is a national plumbing supply chain providing a wide range of plumbing products and services.', '2025-10-02 13:57:14'),
(39, 1, 16, 2, NULL, 'City Plumbing', '0119876543', 'info@cityplumbing.co.za', 'https://cityplumbing.co.za', 'Shop 5, Riverside Mall, Vanderbijlpark', NULL, NULL, '[\"plumbers\"]', NULL, 'missing', '[]', 0.85, 'City Plumbing offers plumbing services and supplies, catering to both residential and commercial needs.', '2025-10-02 13:57:14'),
(40, 1, 16, 3, NULL, 'Builders Warehouse', '0115551234', 'info@builders.co.za', 'https://builders.co.za', 'Cnr. De Villiers & Frikkie Meyer Blvd, Vanderbijlpark', NULL, NULL, '[\"plumbers\"]', NULL, 'missing', '[]', 0.85, 'Builders Warehouse is a leading retailer of building materials and home improvement products, including plumbing supplies.', '2025-10-02 13:57:14'),
(41, 1, 17, 1, NULL, 'City Plumbing', '0211234567', 'info@cityplumbing.co.za', 'https://cityplumbing.co.za', '123 Main Road, Cape Town, 8000', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-02 14:01:17'),
(42, 1, 17, 2, NULL, 'PlumbLink', '0112345678', 'contact@plumblink.co.za', 'https://plumblink.co.za', '456 Pretoria Street, Johannesburg, 2000', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-02 14:01:17'),
(43, 1, 17, 3, NULL, 'Italtile Plumbing', '0313456789', 'sales@italtile.co.za', 'https://italtile.co.za', '789 Durban Avenue, Durban, 4000', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-02 14:01:17'),
(44, 1, 18, 1, NULL, 'City Plumbing', '0211234567', 'info@cityplumbing.co.za', 'https://cityplumbing.co.za', '123 Main Road, Cape Town, 8000', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-02 17:51:50'),
(45, 1, 18, 2, NULL, 'PlumbLink', '0112345678', 'contact@plumblink.co.za', 'https://plumblink.co.za', '456 Johannesburg Street, Johannesburg, 2000', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-02 17:51:50'),
(46, 1, 18, 3, NULL, 'Italtile Plumbing', '0313456789', 'sales@italtile.co.za', 'https://italtile.co.za', '789 Durban Avenue, Durban, 4000', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-02 17:51:50'),
(47, 1, 19, 1, NULL, 'Builders Warehouse', '0111234567', 'info@builders.co.za', 'https://www.builders.co.za', 'Cnr. North Rand & Trichardts Road, Boksburg, Gauteng, 1459', NULL, NULL, '[]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-08 01:20:32'),
(48, 1, 19, 2, NULL, 'Voltex', '0118792000', 'info@voltex.co.za', 'https://www.voltex.co.za', '3 River Road, Bedfordview, Gauteng, 2007', NULL, NULL, '[]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-08 01:20:32'),
(49, 1, 19, 3, NULL, 'Murray & Roberts', '0114566200', 'info@murrob.com', 'https://www.murrob.com', 'Douglas Roberts Centre, 22 Skeen Boulevard, Bedfordview, 2007', NULL, NULL, '[]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-08 01:20:32'),
(50, 1, 20, 1, NULL, 'Voltex', '0118792000', 'info@voltex.co.za', 'https://www.voltex.co.za', 'Cnr. Barlow Road & Cavaleros Drive, Jupiter, Johannesburg, 2094', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-09 20:28:48'),
(51, 1, 20, 2, NULL, 'CBI Electric', '0119282000', 'info@cbi-electric.com', 'https://www.cbi-electric.com', '1 Second Avenue, Industria, Johannesburg, 2093', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-09 20:28:48'),
(52, 1, 20, 3, NULL, 'Electro Sales', '0215114000', 'info@electrosales.co.za', 'https://www.electrosales.co.za', 'Unit 3, 4th Street, Montague Gardens, Cape Town, 7441', NULL, NULL, '[\"electrician\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-09 20:28:48'),
(53, 1, 21, 1, NULL, 'City Plumbing', '0211234567', 'info@cityplumbing.co.za', 'https://cityplumbing.co.za', '123 Main Road, Cape Town, 8000', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-21 08:34:27'),
(54, 1, 21, 2, NULL, 'PlumbLink', '0312345678', 'contact@plumblink.co.za', 'https://plumblink.co.za', '456 Durban Road, Durban, 4001', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-21 08:34:27'),
(55, 1, 21, 3, NULL, 'Italtile Plumbing', '0113456789', 'support@italtile.co.za', 'https://italtile.co.za', '789 Sandton Drive, Sandton, 2196', NULL, NULL, '[\"plumber\"]', NULL, 'missing', '[]', 0.90, 'Verified company via AI search', '2025-10-21 08:34:27');

-- --------------------------------------------------------

--
-- Table structure for table `ai_hints`
--

CREATE TABLE `ai_hints` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) NOT NULL,
  `hint_type` enum('vendor','gl','project') NOT NULL,
  `hint_key` varchar(255) NOT NULL,
  `hint_value` varchar(255) NOT NULL,
  `hits` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_queries`
--

CREATE TABLE `ai_queries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `q_text` text NOT NULL COMMENT 'Search query or shopping list items',
  `parsed_json` longtext DEFAULT NULL COMMENT 'JSON: category, location, budget, date',
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `radius_km` decimal(6,2) DEFAULT 25.00,
  `took_ms` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ai_queries`
--

INSERT INTO `ai_queries` (`id`, `company_id`, `user_id`, `q_text`, `parsed_json`, `lat`, `lng`, `radius_km`, `took_ms`, `created_at`) VALUES
(1, 1, 1, 'glass suppliers in vanderbijlpark', '{\"keywords\":[\"glass\",\"suppliers\",\"in\",\"vanderbijlpark\"],\"location\":\"vanderbijlpark\",\"budget\":null,\"date\":null,\"quantity\":null,\"categories\":[]}', NULL, NULL, 25.00, 3, '2025-10-02 12:19:45'),
(2, 1, 1, 'glass vanderbijlpark', '{\"keywords\":[\"glass\",\"vanderbijlpark\"],\"location\":null,\"budget\":null,\"date\":null,\"quantity\":null,\"categories\":[]}', NULL, NULL, 25.00, 3, '2025-10-02 12:23:08'),
(3, 1, 1, 'plumber vanderbijlpark', '{\"keywords\":[\"plumber\",\"vanderbijlpark\"],\"location\":null,\"budget\":null,\"date\":null,\"quantity\":null,\"categories\":[]}', NULL, NULL, 25.00, 3, '2025-10-02 12:23:18'),
(4, 1, 1, 'plumber', '{\"keywords\":[\"plumber\"],\"location\":null,\"budget\":null,\"date\":null,\"quantity\":null,\"categories\":[]}', NULL, NULL, 25.00, 6, '2025-10-02 12:26:15'),
(5, 1, 1, 'plumbers in vanderbijlpark', '{\"keywords\":[\"plumbers\",\"in\",\"vanderbijlpark\"],\"location\":\"vanderbijlpark\",\"budget_max\":null,\"quantity\":null,\"categories\":[],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 6, '2025-10-02 13:08:54'),
(6, 1, 1, 'plumbers in vanderbijlpark', '{\"keywords\":[\"plumbers\",\"in\",\"vanderbijlpark\"],\"location\":\"vanderbijlpark\",\"budget_max\":null,\"quantity\":null,\"categories\":[],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 4, '2025-10-02 13:11:11'),
(7, 1, 1, 'plumbers in vanderbijlpark', '{\"keywords\":[\"plumbers\",\"vanderbijlpark\"],\"location\":\"vanderbijlpark\",\"budget_max\":null,\"quantity\":null,\"categories\":[\"plumber\"],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 6, '2025-10-02 13:19:41'),
(8, 1, 1, 'plumbers', '{\"keywords\":[\"plumbers\"],\"location\":null,\"budget_max\":null,\"quantity\":null,\"categories\":[\"plumber\"],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 50.00, 7, '2025-10-02 13:28:04'),
(9, 1, 1, 'plumbers in vanderbijlpark', '{\"keywords\":[\"plumbers\",\"vanderbijlpark\"],\"location\":\"vanderbijlpark\",\"budget_max\":null,\"quantity\":null,\"categories\":[\"plumber\"],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 5, '2025-10-02 13:30:26'),
(10, 1, 1, 'plumbers', '{\"keywords\":[\"plumbers\"],\"location\":null,\"budget_max\":null,\"quantity\":null,\"categories\":[\"plumber\"],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 6, '2025-10-02 13:35:07'),
(11, 1, 1, 'plumbers in vanderbijlpark', '{\"keywords\":[\"plumbers\",\"vanderbijlpark\"],\"location\":\"vanderbijlpark\",\"budget_max\":null,\"quantity\":null,\"categories\":[\"plumber\"],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 12, '2025-10-02 13:39:35'),
(12, 1, 1, 'plumbers vanderbijlpark', '{\"keywords\":[\"plumbers\",\"vanderbijlpark\"],\"location\":null,\"budget_max\":null,\"quantity\":null,\"categories\":[\"plumber\"],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 10, '2025-10-02 13:45:48'),
(13, 1, 1, 'electricians in pretoria', '{\"keywords\":[\"electricians\",\"pretoria\"],\"location\":\"pretoria\",\"budget_max\":null,\"quantity\":null,\"categories\":[\"electrician\"],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 50.00, 14, '2025-10-02 13:46:50'),
(14, 1, 1, 'plumbers in vanderbijlpark', '{\"categories\":[\"plumber\"],\"location\":\"Vanderbijlpark\",\"keywords\":[],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 8762, '2025-10-02 13:50:57'),
(15, 1, 1, 'electricians in pretoria', '{\"categories\":[\"electrician\"],\"location\":\"Pretoria\",\"keywords\":[],\"urgency\":\"normal\",\"requirements\":[]}', NULL, NULL, 25.00, 12046, '2025-10-02 13:52:48'),
(16, 1, 1, 'plumbers vanderbijlpark', '{\"categories\":[\"plumbers\"],\"location\":\"vanderbijlpark\"}', NULL, NULL, 25.00, 6771, '2025-10-02 13:57:14'),
(17, 1, 1, 'plumbers vanderbijlpark', '{\"categories\":[\"plumber\"],\"location\":null}', NULL, NULL, 25.00, 3835, '2025-10-02 14:01:17'),
(18, 1, 1, 'Plumber vandebijlpark', '{\"categories\":[\"plumber\"],\"location\":null}', NULL, NULL, 25.00, 4862, '2025-10-02 17:51:50'),
(19, 1, 1, 'Milk', '{\"categories\":[],\"location\":null}', NULL, NULL, 25.00, 5054, '2025-10-08 01:20:32'),
(20, 1, 1, 'Electrician vanderbijlpark', '{\"categories\":[\"electrician\"],\"location\":null}', NULL, NULL, 25.00, 6833, '2025-10-09 20:28:48'),
(21, 1, 1, 'plumber vanderbijlpark', '{\"categories\":[\"plumber\"],\"location\":null}', NULL, NULL, 25.00, 2354, '2025-10-21 08:34:27');

-- --------------------------------------------------------

--
-- Table structure for table `ai_results`
--

CREATE TABLE `ai_results` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) NOT NULL,
  `query_id` bigint(20) UNSIGNED NOT NULL,
  `source` enum('crm','places','bing','registry','history') NOT NULL,
  `source_ref` varchar(190) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `category` varchar(120) DEFAULT NULL,
  `score_raw` decimal(5,2) DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_settings`
--

CREATE TABLE `ai_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) NOT NULL,
  `rules_json` longtext DEFAULT NULL COMMENT 'Synonyms, categories, allow/deny lists',
  `default_radius_km` decimal(6,2) DEFAULT 25.00,
  `min_score_threshold` decimal(5,2) DEFAULT 0.55,
  `require_compliance` tinyint(1) DEFAULT 1,
  `block_expired_compliance` tinyint(1) DEFAULT 1,
  `rfq_max_recipients` int(11) DEFAULT 8,
  `api_keys_json` longtext DEFAULT NULL COMMENT 'External connectors',
  `daily_search_cap` int(11) DEFAULT 100,
  `per_hour_cap` int(11) DEFAULT 20,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ai_settings`
--

INSERT INTO `ai_settings` (`id`, `company_id`, `rules_json`, `default_radius_km`, `min_score_threshold`, `require_compliance`, `block_expired_compliance`, `rfq_max_recipients`, `api_keys_json`, `daily_search_cap`, `per_hour_cap`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, 25.00, 0.55, 1, 1, 8, NULL, 100, 20, '2025-10-02 12:07:28', '2025-10-02 12:07:28');

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scopes`)),
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ap_bills`
--

CREATE TABLE `ap_bills` (
  `id` int(10) NOT NULL,
  `company_id` int(10) NOT NULL,
  `supplier_id` bigint(20) NOT NULL,
  `vendor_invoice_number` varchar(190) DEFAULT NULL,
  `vendor_vat` varchar(64) DEFAULT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `currency` char(3) DEFAULT 'ZAR',
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `tax` decimal(15,2) DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','review','approved','posted','paid','blocked') NOT NULL DEFAULT 'draft',
  `ocr_id` bigint(20) DEFAULT NULL,
  `file_id` bigint(20) DEFAULT NULL,
  `hash_fingerprint` varchar(64) DEFAULT NULL,
  `journal_id` bigint(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ap_bill_lines`
--

CREATE TABLE `ap_bill_lines` (
  `id` int(10) NOT NULL,
  `bill_id` int(10) NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 1.000,
  `unit` varchar(50) DEFAULT 'ea',
  `unit_price` decimal(15,2) NOT NULL,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 15.00,
  `line_total` decimal(15,2) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `gl_account_id` bigint(20) DEFAULT NULL,
  `inventory_item_id` bigint(20) DEFAULT NULL,
  `project_board_id` int(11) DEFAULT NULL,
  `project_item_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ap_match_links`
--

CREATE TABLE `ap_match_links` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `po_line_id` bigint(20) DEFAULT NULL,
  `grn_line_id` bigint(20) DEFAULT NULL,
  `bill_line_id` int(11) DEFAULT NULL,
  `qty_matched` decimal(15,3) NOT NULL DEFAULT 0.000,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ap_payments`
--

CREATE TABLE `ap_payments` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `supplier_id` bigint(20) NOT NULL,
  `bank_account_id` bigint(20) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_date` date NOT NULL,
  `method` enum('eft','cash','cheque','card','other') NOT NULL DEFAULT 'eft',
  `reference` varchar(190) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `journal_id` bigint(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ap_payment_allocations`
--

CREATE TABLE `ap_payment_allocations` (
  `id` int(11) NOT NULL,
  `ap_payment_id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `cost_cents` bigint(20) NOT NULL DEFAULT 0,
  `salvage_cents` bigint(20) NOT NULL DEFAULT 0,
  `useful_life_months` int(10) UNSIGNED NOT NULL DEFAULT 60,
  `depreciation_method` enum('straight_line') NOT NULL DEFAULT 'straight_line',
  `accum_dep_cents` bigint(20) NOT NULL DEFAULT 0,
  `status` enum('active','disposed') NOT NULL DEFAULT 'active',
  `disposal_date` date DEFAULT NULL,
  `journal_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `company_id`, `user_id`, `board_id`, `item_id`, `action`, `entity_type`, `entity_id`, `details`, `ip`, `timestamp`, `created_at`) VALUES
(1, 1, 1, NULL, NULL, 'system_init', NULL, NULL, 'Admin area initialized', NULL, '2025-09-30 11:54:26', '2025-10-04 13:09:31'),
(2, 1, 1, NULL, NULL, 'company_updated', NULL, NULL, 'Updated company profile', NULL, '2025-09-30 11:54:26', '2025-10-04 13:09:31'),
(3, 1, 1, NULL, 1, 'item_status_changed', NULL, NULL, 'Changed status to: To Do', '102.22.121.194', '2025-09-30 13:57:21', '2025-10-04 13:09:31'),
(4, 1, 1, 6, 2, 'item_created', NULL, NULL, 'Created item: asxa', '102.22.121.194', '2025-09-30 15:23:53', '2025-10-04 13:09:31'),
(5, 1, 1, NULL, 2, 'item_updated', NULL, NULL, 'Updated status_label to: In Progress', '102.22.121.194', '2025-09-30 15:23:58', '2025-10-04 13:09:31'),
(6, 1, 1, NULL, 2, 'item_updated', NULL, NULL, 'Updated due_date to: null', '102.22.121.194', '2025-09-30 15:25:46', '2025-10-04 13:09:31'),
(7, 1, 1, NULL, 2, 'item_updated', NULL, NULL, 'Updated assigned_to to: 1', '102.22.121.194', '2025-09-30 15:25:54', '2025-10-04 13:09:31'),
(8, 1, 1, 6, 3, 'item_created', NULL, NULL, 'Created item: dsf', '102.22.121.194', '2025-09-30 15:27:26', '2025-10-04 13:09:31'),
(9, 1, 1, NULL, 3, 'item_updated', NULL, NULL, 'Updated due_date to: null', '102.22.121.194', '2025-09-30 15:27:50', '2025-10-04 13:09:31'),
(10, 1, 1, NULL, NULL, 'crm_account_create', NULL, NULL, '{\"id\":\"1\",\"name\":\"Adriaan\",\"type\":\"supplier\"}', NULL, '2025-10-01 14:52:48', '2025-10-04 13:09:31'),
(11, 1, 1, NULL, NULL, 'crm_account_create', NULL, NULL, '{\"id\":\"2\",\"name\":\"adriaan\",\"type\":\"customer\"}', NULL, '2025-10-01 17:34:16', '2025-10-04 13:09:31'),
(12, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"glass suppliers in vanderbijlpark\",\"results\":0}', '102.22.121.194', '2025-10-02 10:19:45', '2025-10-04 13:09:31'),
(13, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"glass vanderbijlpark\",\"results\":0}', '102.22.121.194', '2025-10-02 10:23:08', '2025-10-04 13:09:31'),
(14, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumber vanderbijlpark\",\"results\":0}', '102.22.121.194', '2025-10-02 10:23:18', '2025-10-04 13:09:31'),
(15, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumber\",\"results\":1}', '102.22.121.194', '2025-10-02 10:26:15', '2025-10-04 13:09:31'),
(16, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumbers in vanderbijlpark\",\"results\":0}', '102.22.121.194', '2025-10-02 11:08:54', '2025-10-04 13:09:31'),
(17, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumbers in vanderbijlpark\",\"results\":0}', '102.22.121.194', '2025-10-02 11:11:11', '2025-10-04 13:09:31'),
(18, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumbers in vanderbijlpark\",\"results\":0}', '102.22.121.194', '2025-10-02 11:19:41', '2025-10-04 13:09:31'),
(19, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumbers\",\"results\":1}', '102.22.121.194', '2025-10-02 11:28:04', '2025-10-04 13:09:31'),
(20, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumbers in vanderbijlpark\",\"results\":0}', '102.22.121.194', '2025-10-02 11:30:26', '2025-10-04 13:09:31'),
(21, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumbers\",\"results\":0}', '102.22.121.194', '2025-10-02 11:35:07', '2025-10-04 13:09:31'),
(22, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumbers in vanderbijlpark\",\"results\":8}', '102.22.121.194', '2025-10-02 11:39:35', '2025-10-04 13:09:31'),
(23, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"plumbers vanderbijlpark\",\"results\":8}', '102.22.121.194', '2025-10-02 11:45:48', '2025-10-04 13:09:31'),
(24, 1, 1, NULL, NULL, 'ai_search', NULL, NULL, '{\"query\":\"electricians in pretoria\",\"results\":8}', '102.22.121.194', '2025-10-02 11:46:50', '2025-10-04 13:09:31'),
(25, 1, 1, NULL, NULL, 'ai_search_web', NULL, NULL, '{\"query\":\"plumbers in vanderbijlpark\",\"results\":5}', '102.22.121.194', '2025-10-02 11:50:57', '2025-10-04 13:09:31'),
(26, 1, 1, NULL, NULL, 'ai_search_web', NULL, NULL, '{\"query\":\"electricians in pretoria\",\"results\":6}', '102.22.121.194', '2025-10-02 11:52:48', '2025-10-04 13:09:31'),
(27, 1, 1, NULL, NULL, 'mail_account_saved', NULL, NULL, '{\"account_id\":\"1\",\"email\":\"adriaan@concreto.co.za\"}', NULL, '2025-10-02 13:31:52', '2025-10-04 13:09:31'),
(28, 1, 1, NULL, NULL, 'calendar_created', NULL, NULL, '{\"calendar_id\":\"1\",\"name\":\"Concreto\",\"type\":\"personal\"}', '102.22.121.194', '2025-10-02 14:35:56', '2025-10-04 13:09:31'),
(29, 1, 1, NULL, NULL, 'event_created', NULL, NULL, '{\"event_id\":\"1\",\"title\":\"Meeting with John\"}', '102.22.121.194', '2025-10-02 14:36:09', '2025-10-04 13:09:31'),
(30, 1, 1, NULL, NULL, 'event_created', NULL, NULL, '{\"event_id\":\"2\",\"title\":\"mmeetig with anita\"}', '102.22.121.194', '2025-10-02 17:31:56', '2025-10-04 13:09:31'),
(31, 1, 1, NULL, NULL, 'plan_changed', NULL, NULL, 'Changed plan to: Pro', NULL, '2025-10-02 18:06:53', '2025-10-04 13:09:31'),
(32, 1, 1, NULL, NULL, 'user_created', NULL, NULL, 'Created user: anita@concreto.co.za', NULL, '2025-10-02 18:07:17', '2025-10-04 13:09:31'),
(33, 1, 1, NULL, NULL, 'update', 'crm_contact', 3, '{\"account_id\":4}', NULL, '2025-10-04 11:12:48', '2025-10-04 13:12:48'),
(34, 1, 1, NULL, NULL, 'update', 'crm_address', 1, '{\"account_id\":4}', NULL, '2025-10-04 11:13:22', '2025-10-04 13:13:22'),
(35, 1, 1, NULL, NULL, 'create', 'crm_interaction', 1, '{\"account_id\":4,\"type\":\"email\"}', NULL, '2025-10-04 11:17:15', '2025-10-04 13:17:15'),
(37, 1, 1, NULL, NULL, 'crm_account_create', NULL, NULL, '{\"id\":\"10\",\"name\":\"sdfsdfsd\",\"type\":\"customer\"}', NULL, '2025-10-04 11:22:29', '2025-10-04 13:22:29'),
(38, 1, 1, NULL, NULL, 'update', 'crm_contact', 4, '{\"account_id\":10}', NULL, '2025-10-04 11:22:45', '2025-10-04 13:22:45'),
(39, 1, 1, NULL, NULL, 'update', 'crm_address', 2, '{\"account_id\":10}', NULL, '2025-10-04 11:23:02', '2025-10-04 13:23:02'),
(42, 1, 1, NULL, NULL, 'crm_compliance_upload', NULL, NULL, '{\"account_id\":4,\"type_id\":3,\"doc_id\":\"2\"}', NULL, '2025-10-04 11:34:39', '2025-10-04 13:34:39'),
(43, 1, 1, NULL, NULL, 'crm_compliance_delete', NULL, NULL, '{\"account_id\":4,\"doc_id\":2}', NULL, '2025-10-04 11:36:26', '2025-10-04 13:36:26'),
(44, 1, 1, NULL, NULL, 'crm_compliance_upload', NULL, NULL, '{\"account_id\":4,\"type_id\":6,\"doc_id\":\"3\"}', NULL, '2025-10-04 11:42:18', '2025-10-04 13:42:18'),
(45, 1, 1, NULL, NULL, 'crm_compliance_delete', NULL, NULL, '{\"account_id\":4,\"doc_id\":3,\"file_path\":null}', NULL, '2025-10-04 11:46:04', '2025-10-04 13:46:04'),
(47, 1, 1, NULL, NULL, 'crm_compliance_upload', NULL, NULL, '{\"account_id\":4,\"type_id\":3,\"doc_id\":\"4\",\"file_path\":\"\\/uploads\\/company\\/1\\/compliance\\/doc_4_3_1759580708.jpeg\"}', NULL, '2025-10-04 12:25:08', '2025-10-04 14:25:08'),
(48, 1, 1, NULL, NULL, 'crm_compliance_delete', NULL, NULL, '{\"account_id\":4,\"doc_id\":4,\"file_path\":\"\\/uploads\\/company\\/1\\/compliance\\/doc_4_3_1759580708.jpeg\"}', NULL, '2025-10-04 12:25:32', '2025-10-04 14:25:32'),
(49, 1, 1, NULL, NULL, 'crm_compliance_upload', NULL, NULL, '{\"account_id\":4,\"type_id\":3,\"doc_id\":\"5\",\"file_path\":\"\\/uploads\\/company\\/1\\/compliance\\/doc_4_3_1759580796.jpeg\"}', NULL, '2025-10-04 12:26:36', '2025-10-04 14:26:36'),
(59, 1, 1, NULL, NULL, 'quote_created', NULL, NULL, '{\"quote_id\":\"1\",\"quote_number\":\"Q2025-0000\"}', '102.22.121.194', '2025-10-04 12:56:57', '2025-10-04 14:56:57'),
(60, 1, 1, NULL, NULL, 'quote_created', NULL, NULL, '{\"quote_id\":\"2\",\"quote_number\":\"Q2025-0001\"}', '102.22.121.194', '2025-10-04 12:56:57', '2025-10-04 14:56:57'),
(61, 1, 1, NULL, NULL, 'invoice_created', NULL, NULL, '{\"invoice_id\":\"1\",\"invoice_number\":\"INV2025-0000\"}', '102.22.121.194', '2025-10-04 12:57:32', '2025-10-04 14:57:32'),
(62, 1, 1, NULL, NULL, 'quote_created', NULL, NULL, '{\"quote_id\":\"3\",\"quote_number\":\"Q2025-0002\"}', '102.22.121.194', '2025-10-04 18:19:21', '2025-10-04 20:19:21'),
(63, 1, 1, NULL, NULL, 'quote_created', NULL, NULL, '{\"quote_id\":\"4\",\"quote_number\":\"Q2025-0003\"}', '102.22.121.194', '2025-10-04 18:19:21', '2025-10-04 20:19:21'),
(67, 1, 1, NULL, NULL, 'crm_account_create', NULL, NULL, '{\"id\":\"12\",\"name\":\"wqdqwdwq\",\"type\":\"supplier\"}', NULL, '2025-10-04 19:33:11', '2025-10-04 21:33:11'),
(68, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":12,\"name\":\"wqdqwd\",\"type\":\"supplier\"}', NULL, '2025-10-04 19:38:42', '2025-10-04 21:38:42'),
(69, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":2,\"name\":\"adriaan\",\"type\":\"customer\"}', NULL, '2025-10-04 19:39:12', '2025-10-04 21:39:12'),
(70, 1, 1, NULL, NULL, 'employee_created', NULL, NULL, '{\"employee_id\":\"1\",\"name\":\"Adriaan Geldenhuis\"}', '102.22.121.194', '2025-10-04 21:10:58', '2025-10-04 23:10:58'),
(71, 1, 1, NULL, NULL, 'receipt_uploaded', NULL, NULL, '{\"file_id\":\"1\",\"filename\":\"large-GRAN0047 BI INV364410 B.jpeg\"}', '102.22.121.194', '2025-10-04 22:04:32', '2025-10-05 00:04:32'),
(72, 1, 1, NULL, NULL, 'finance_settings_updated', NULL, NULL, '{\"finance_fiscal_year_start\":\"January\",\"finance_ar_account_id\":\"\",\"finance_ap_account_id\":\"13\",\"finance_vat_output_account_id\":\"\",\"finance_vat_input_account_id\":\"\",\"finance_sales_account_id\":\"\",\"finance_cogs_account_id\":\"\",\"finance_inventory_account_id\":\"\"}', '102.22.121.194', '2025-10-05 00:45:10', '2025-10-05 02:45:10'),
(73, 1, 1, NULL, NULL, 'payrun_created', NULL, NULL, '{\"run_id\":\"1\",\"name\":\"asdf\",\"period\":\"2025-10-01 to 2025-10-31\"}', '102.22.121.194', '2025-10-05 01:47:54', '2025-10-05 03:47:54'),
(74, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":2,\"name\":\"Build it Mosselbay\",\"type\":\"customer\"}', NULL, '2025-10-05 08:43:39', '2025-10-05 10:43:39'),
(75, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":2,\"name\":\"Build it Mosselbay\",\"type\":\"customer\"}', NULL, '2025-10-05 09:05:57', '2025-10-05 11:05:57'),
(76, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":4,\"name\":\"ABC Electrical\",\"type\":\"supplier\"}', NULL, '2025-10-05 09:35:02', '2025-10-05 11:35:02'),
(77, 1, 1, NULL, NULL, 'crm_compliance_upload', NULL, NULL, '{\"account_id\":4,\"type_id\":1,\"doc_id\":\"6\",\"file_path\":\"\\/uploads\\/company\\/1\\/compliance\\/doc_4_1_1759657641.jpg\"}', NULL, '2025-10-05 09:47:21', '2025-10-05 11:47:21'),
(78, 1, 1, NULL, NULL, 'update', 'crm_contact', 5, '{\"account_id\":4}', NULL, '2025-10-05 10:13:24', '2025-10-05 12:13:24'),
(79, 1, 1, NULL, NULL, 'update', 'crm_contact', 5, '{\"action\":\"set_primary\",\"account_id\":4}', NULL, '2025-10-05 10:13:33', '2025-10-05 12:13:33'),
(80, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":4,\"name\":\"ABC Electrical\",\"type\":\"supplier\"}', NULL, '2025-10-05 10:16:15', '2025-10-05 12:16:15'),
(81, 1, 1, NULL, NULL, 'update', 'crm_contact', 6, '{\"account_id\":2}', NULL, '2025-10-05 10:19:14', '2025-10-05 12:19:14'),
(82, 1, 1, NULL, NULL, 'create', 'crm_interaction', 2, '{\"account_id\":2,\"type\":\"email\"}', NULL, '2025-10-05 10:22:16', '2025-10-05 12:22:16'),
(83, 1, 1, NULL, NULL, 'create', 'crm_address', 3, '{\"account_id\":4}', NULL, '2025-10-05 10:30:46', '2025-10-05 12:30:46'),
(84, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":4,\"name\":\"ABC Electrical\",\"type\":\"supplier\"}', NULL, '2025-10-05 11:10:53', '2025-10-05 13:10:53'),
(85, 1, 1, NULL, NULL, 'finance_settings_updated', NULL, NULL, '{\"finance_fiscal_year_start\":\"February\",\"finance_ar_account_id\":\"5\",\"finance_ap_account_id\":\"13\",\"finance_vat_output_account_id\":\"14\",\"finance_vat_input_account_id\":\"15\",\"finance_sales_account_id\":\"26\",\"finance_cogs_account_id\":\"29\",\"finance_inventory_account_id\":\"6\"}', '102.22.121.194', '2025-10-06 20:51:35', '2025-10-06 22:51:35'),
(86, 1, 1, NULL, NULL, 'finance_settings_saved', NULL, NULL, '{\"updated\":[\"fiscal_year_start\",\"ar_account_id\",\"ap_account_id\",\"vat_output_account_id\",\"vat_input_account_id\",\"sales_account_id\",\"cogs_account_id\",\"inventory_account_id\"]}', '102.22.121.194', '2025-10-08 12:37:53', '2025-10-08 14:37:53'),
(87, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":4,\"name\":\"ABC Electrical\",\"type\":\"supplier\"}', NULL, '2025-10-08 17:22:35', '2025-10-08 19:22:35'),
(88, 1, 1, NULL, NULL, 'ar_invoice_synced', NULL, NULL, '{\"invoice_id\":\"1\",\"journal_id\":1}', '102.22.121.194', '2025-10-09 18:13:40', '2025-10-09 20:13:40'),
(89, 1, 1, NULL, NULL, 'user_created', NULL, NULL, 'Created user: catherine@geldenhuis.co.za', NULL, '2025-10-14 10:55:28', '2025-10-14 12:55:28'),
(90, 1, 1, NULL, NULL, 'crm_compliance_upload', NULL, NULL, '{\"account_id\":9,\"type_id\":3,\"doc_id\":\"7\",\"file_path\":\"\\/uploads\\/company\\/1\\/compliance\\/doc_9_3_1760611588.jpg\"}', NULL, '2025-10-16 10:46:28', '2025-10-16 12:46:28'),
(91, 1, 1, NULL, NULL, 'update', 'crm_contact', 7, '{\"account_id\":9}', NULL, '2025-10-16 10:47:15', '2025-10-16 12:47:15'),
(92, 1, 1, NULL, NULL, 'update', 'crm_contact', 8, '{\"account_id\":9}', NULL, '2025-10-16 10:47:25', '2025-10-16 12:47:25'),
(93, 1, 1, NULL, NULL, 'create', 'crm_interaction', 3, '{\"account_id\":9,\"type\":\"email\"}', NULL, '2025-10-16 10:51:54', '2025-10-16 12:51:54'),
(94, 1, 1, NULL, NULL, 'create', 'crm_address', 7, '{\"account_id\":9}', NULL, '2025-10-16 10:58:30', '2025-10-16 12:58:30'),
(95, 1, 1, NULL, NULL, 'crm_account_update', NULL, NULL, '{\"id\":4,\"name\":\"ABCD Electrical\",\"type\":\"supplier\"}', NULL, '2025-10-16 11:21:51', '2025-10-16 13:21:51'),
(96, 1, 1, NULL, NULL, 'update', 'crm_contact', 9, '{\"account_id\":2}', NULL, '2025-10-16 14:38:06', '2025-10-16 16:38:06'),
(97, 1, 1, NULL, NULL, 'user_created', NULL, NULL, 'Created user: anita@concreto.co.za', NULL, '2025-10-18 11:41:35', '2025-10-18 13:41:35'),
(98, 1, 3, NULL, NULL, 'crm_account_create', NULL, NULL, '{\"id\":\"13\",\"name\":\"Hallmart\",\"type\":\"customer\"}', NULL, '2025-10-20 13:42:57', '2025-10-20 15:42:57'),
(99, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-21 22:39:37', '2025-11-22 00:39:37'),
(100, 1, 1, NULL, NULL, 'user_logout', NULL, NULL, '{\"method\":\"manual\"}', '102.22.121.194', '2025-11-21 22:39:50', '2025-11-22 00:39:50'),
(101, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-21 22:42:50', '2025-11-22 00:42:50'),
(102, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '105.245.32.113', '2025-11-22 13:21:42', '2025-11-22 15:21:42'),
(103, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.4.77', '2025-11-24 06:31:16', '2025-11-24 08:31:16'),
(104, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '41.13.4.77', '2025-11-24 07:40:00', '2025-11-24 09:40:00'),
(105, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.22.60', '2025-11-24 10:24:08', '2025-11-24 12:24:08'),
(106, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.194', '2025-11-25 12:10:36', '2025-11-25 14:10:36'),
(107, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '41.13.17.48', '2025-11-25 13:40:19', '2025-11-25 15:40:19'),
(108, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '41.13.17.48', '2025-11-25 13:40:19', '2025-11-25 15:40:19'),
(109, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-25 13:42:53', '2025-11-25 15:42:53'),
(110, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '105.245.20.117', '2025-11-27 09:32:53', '2025-11-27 11:32:53'),
(111, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.194', '2025-11-27 15:05:26', '2025-11-27 17:05:26'),
(112, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-27 15:48:52', '2025-11-27 17:48:52'),
(113, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-27 18:05:28', '2025-11-27 20:05:28'),
(114, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 07:34:49', '2025-11-28 09:34:49'),
(115, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.194', '2025-11-28 08:18:38', '2025-11-28 10:18:38'),
(116, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 14:28:58', '2025-11-28 16:28:58'),
(117, 1, 1, NULL, NULL, 'user_logout', NULL, NULL, '{\"method\":\"manual\"}', '102.22.121.194', '2025-11-28 14:38:35', '2025-11-28 16:38:35'),
(118, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 15:00:44', '2025-11-28 17:00:44'),
(119, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 15:01:53', '2025-11-28 17:01:53'),
(120, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 17:43:09', '2025-11-28 19:43:09'),
(121, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 18:57:54', '2025-11-28 20:57:54'),
(122, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 19:10:04', '2025-11-28 21:10:04'),
(123, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 19:13:04', '2025-11-28 21:13:04'),
(124, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 19:30:55', '2025-11-28 21:30:55'),
(125, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-28 20:38:08', '2025-11-28 22:38:08'),
(126, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-11-29 04:46:37', '2025-11-29 06:46:37'),
(127, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-01 05:21:33', '2025-12-01 07:21:33'),
(128, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '105.245.32.230', '2025-12-01 09:14:06', '2025-12-01 11:14:06'),
(129, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.194', '2025-12-01 13:49:33', '2025-12-01 15:49:33'),
(130, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-01 19:01:29', '2025-12-01 21:01:29'),
(131, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '105.245.32.230', '2025-12-01 20:54:58', '2025-12-01 22:54:58'),
(132, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.194', '2025-12-02 10:33:05', '2025-12-02 12:33:05'),
(133, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-02 10:55:24', '2025-12-02 12:55:24'),
(134, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.18.177', '2025-12-02 13:30:16', '2025-12-02 15:30:16'),
(135, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-03 03:35:33', '2025-12-03 05:35:33'),
(136, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-04 05:32:28', '2025-12-04 07:32:28'),
(137, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.194', '2025-12-04 06:06:02', '2025-12-04 08:06:02'),
(138, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-04 09:31:18', '2025-12-04 11:31:18'),
(139, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-04 10:40:13', '2025-12-04 12:40:13'),
(140, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-04 11:51:51', '2025-12-04 13:51:51'),
(141, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.64.39.170', '2025-12-04 13:07:15', '2025-12-04 15:07:15'),
(142, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-04 13:47:37', '2025-12-04 15:47:37'),
(143, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.129.62.9', '2025-12-05 06:46:41', '2025-12-05 08:46:41'),
(144, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-05 10:54:19', '2025-12-05 12:54:19'),
(145, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-06 07:06:41', '2025-12-06 09:06:41'),
(146, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.194', '2025-12-07 13:05:15', '2025-12-07 15:05:15'),
(147, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '105.245.36.71', '2025-12-08 04:06:55', '2025-12-08 06:06:55'),
(148, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-09 05:57:00', '2025-12-09 07:57:00'),
(149, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-09 07:29:04', '2025-12-09 09:29:04'),
(150, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-09 10:57:06', '2025-12-09 12:57:06'),
(151, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-09 13:16:21', '2025-12-09 15:16:21'),
(152, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-10 13:56:32', '2025-12-10 15:56:32'),
(153, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '105.245.4.150', '2025-12-11 07:29:06', '2025-12-11 09:29:06'),
(154, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-11 08:17:44', '2025-12-11 10:17:44'),
(155, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-11 10:37:24', '2025-12-11 12:37:24'),
(156, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-11 11:24:40', '2025-12-11 13:24:40'),
(157, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-11 16:12:36', '2025-12-11 18:12:36'),
(158, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-11 18:31:00', '2025-12-11 20:31:00'),
(159, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-12 05:36:10', '2025-12-12 07:36:10'),
(160, 1, 3, NULL, NULL, 'password_reset_requested', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-12 05:37:35', '2025-12-12 07:37:35'),
(161, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-12 09:02:18', '2025-12-12 11:02:18'),
(162, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-13 06:16:37', '2025-12-13 08:16:37'),
(163, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-13 13:12:09', '2025-12-13 15:12:09'),
(164, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-14 08:31:59', '2025-12-14 10:31:59'),
(165, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-14 10:01:30', '2025-12-14 12:01:30'),
(166, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-14 10:04:30', '2025-12-14 12:04:30'),
(167, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-14 16:36:36', '2025-12-14 18:36:36'),
(168, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-15 06:07:23', '2025-12-15 08:07:23'),
(169, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-15 06:07:43', '2025-12-15 08:07:43'),
(170, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-15 07:11:25', '2025-12-15 09:11:25'),
(171, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-15 09:59:00', '2025-12-15 11:59:00'),
(172, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-15 15:40:47', '2025-12-15 17:40:47'),
(173, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-16 14:00:57', '2025-12-16 16:00:57'),
(174, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-16 14:01:15', '2025-12-16 16:01:15'),
(175, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-17 08:41:13', '2025-12-17 10:41:13'),
(176, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-17 08:52:12', '2025-12-17 10:52:12'),
(177, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-17 12:22:45', '2025-12-17 14:22:45'),
(178, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-17 18:27:00', '2025-12-17 20:27:00'),
(179, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-18 06:30:23', '2025-12-18 08:30:23'),
(180, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-18 09:07:29', '2025-12-18 11:07:29'),
(181, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-18 17:32:10', '2025-12-18 19:32:10'),
(182, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-19 04:04:01', '2025-12-19 06:04:01'),
(183, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-19 07:16:44', '2025-12-19 09:16:44'),
(184, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-19 14:23:19', '2025-12-19 16:23:19'),
(185, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2025-12-19 14:42:36', '2025-12-19 16:42:36'),
(186, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-19 17:18:44', '2025-12-19 19:18:44'),
(187, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-20 05:05:49', '2025-12-20 07:05:49'),
(188, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-20 18:13:09', '2025-12-20 20:13:09'),
(189, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-21 14:31:45', '2025-12-21 16:31:45'),
(190, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2025-12-29 08:01:11', '2025-12-29 10:01:11'),
(191, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-03 09:07:58', '2026-01-03 11:07:58'),
(192, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-05 06:42:16', '2026-01-05 08:42:16'),
(193, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-05 09:57:47', '2026-01-05 11:57:47'),
(194, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-06 09:20:56', '2026-01-06 11:20:56'),
(195, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-06 11:26:14', '2026-01-06 13:26:14'),
(196, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-07 09:26:02', '2026-01-07 11:26:02'),
(197, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-09 18:45:33', '2026-01-09 20:45:33'),
(198, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-12 11:28:46', '2026-01-12 13:28:46'),
(199, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-13 07:12:59', '2026-01-13 09:12:59'),
(200, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-13 07:46:16', '2026-01-13 09:46:16'),
(201, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-13 18:26:31', '2026-01-13 20:26:31'),
(202, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-13 18:45:00', '2026-01-13 20:45:00'),
(203, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.16.49', '2026-01-14 07:52:09', '2026-01-14 09:52:09'),
(204, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-14 17:51:44', '2026-01-14 19:51:44'),
(205, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-15 06:46:32', '2026-01-15 08:46:32'),
(206, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-15 11:44:08', '2026-01-15 13:44:08'),
(207, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-15 12:10:05', '2026-01-15 14:10:05'),
(208, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '105.245.24.182', '2026-01-15 14:11:37', '2026-01-15 16:11:37'),
(209, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '105.245.24.182', '2026-01-16 11:51:21', '2026-01-16 13:51:21'),
(210, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-16 13:24:27', '2026-01-16 15:24:27'),
(211, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-16 14:21:19', '2026-01-16 16:21:19'),
(212, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-16 14:34:04', '2026-01-16 16:34:04'),
(213, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-17 09:15:49', '2026-01-17 11:15:49'),
(214, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-18 20:25:33', '2026-01-18 22:25:33'),
(215, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-19 05:10:37', '2026-01-19 07:10:37'),
(216, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-19 05:17:04', '2026-01-19 07:17:04'),
(217, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-19 08:58:55', '2026-01-19 10:58:55'),
(218, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-20 06:58:28', '2026-01-20 08:58:28'),
(219, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-20 07:07:56', '2026-01-20 09:07:56'),
(220, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-20 11:46:28', '2026-01-20 13:46:28'),
(221, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.18.170', '2026-01-21 12:26:54', '2026-01-21 14:26:54'),
(222, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-22 08:00:51', '2026-01-22 10:00:51'),
(223, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-22 11:28:38', '2026-01-22 13:28:38'),
(224, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-22 14:31:40', '2026-01-22 16:31:40'),
(225, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-24 14:09:37', '2026-01-24 16:09:37'),
(226, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-24 18:42:23', '2026-01-24 20:42:23'),
(227, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-25 09:35:46', '2026-01-25 11:35:46'),
(228, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-25 14:00:50', '2026-01-25 16:00:50'),
(229, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-26 05:57:49', '2026-01-26 07:57:49'),
(230, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.78.140', '2026-01-26 10:21:25', '2026-01-26 12:21:25'),
(231, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-26 17:10:55', '2026-01-26 19:10:55'),
(232, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-27 04:05:05', '2026-01-27 06:05:05'),
(233, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-27 14:47:22', '2026-01-27 16:47:22'),
(234, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-27 14:48:23', '2026-01-27 16:48:23'),
(235, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-27 18:58:14', '2026-01-27 20:58:14'),
(236, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-28 05:11:27', '2026-01-28 07:11:27'),
(237, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-28 09:34:30', '2026-01-28 11:34:30'),
(238, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-29 07:04:46', '2026-01-29 09:04:46'),
(239, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-29 11:42:11', '2026-01-29 13:42:11'),
(240, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-29 14:19:55', '2026-01-29 16:19:55'),
(241, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-01-30 06:23:55', '2026-01-30 08:23:55'),
(242, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.11.59', '2026-01-30 09:45:44', '2026-01-30 11:45:44'),
(243, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-30 11:52:28', '2026-01-30 13:52:28'),
(244, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-30 12:42:26', '2026-01-30 14:42:26'),
(245, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-01-30 15:51:29', '2026-01-30 17:51:29'),
(246, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-02 06:41:22', '2026-02-02 08:41:22'),
(247, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-02 14:01:59', '2026-02-02 16:01:59'),
(248, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-03 09:01:45', '2026-02-03 11:01:45'),
(249, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-04 11:55:13', '2026-02-04 13:55:13'),
(250, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-04 15:05:13', '2026-02-04 17:05:13'),
(251, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-04 19:22:35', '2026-02-04 21:22:35'),
(252, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-05 08:55:42', '2026-02-05 10:55:42'),
(253, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.2.49', '2026-02-05 17:30:51', '2026-02-05 19:30:51'),
(254, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-06 16:36:17', '2026-02-06 18:36:17'),
(255, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-07 10:19:35', '2026-02-07 12:19:35'),
(256, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-09 05:08:35', '2026-02-09 07:08:35'),
(257, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-10 11:53:58', '2026-02-10 13:53:58'),
(258, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.134.154.191', '2026-02-10 13:32:22', '2026-02-10 15:32:22'),
(259, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-11 05:26:08', '2026-02-11 07:26:08'),
(260, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-11 10:41:48', '2026-02-11 12:41:48'),
(261, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-11 10:47:45', '2026-02-11 12:47:45'),
(262, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-11 10:50:16', '2026-02-11 12:50:16'),
(263, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-13 09:42:20', '2026-02-13 11:42:20'),
(264, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-13 14:18:07', '2026-02-13 16:18:07'),
(265, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-13 17:05:36', '2026-02-13 19:05:36'),
(266, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-13 17:06:00', '2026-02-13 19:06:00'),
(267, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-15 18:25:44', '2026-02-15 20:25:44'),
(268, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-16 17:22:55', '2026-02-16 19:22:55'),
(269, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-17 07:06:46', '2026-02-17 09:06:46'),
(270, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-17 09:38:31', '2026-02-17 11:38:31'),
(271, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-18 13:47:09', '2026-02-18 15:47:09'),
(272, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-18 15:07:33', '2026-02-18 17:07:33'),
(273, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-18 15:09:25', '2026-02-18 17:09:25'),
(274, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-18 15:36:55', '2026-02-18 17:36:55'),
(275, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-18 16:34:05', '2026-02-18 18:34:05'),
(276, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-18 17:10:36', '2026-02-18 19:10:36'),
(277, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-19 10:27:16', '2026-02-19 12:27:16'),
(278, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '41.13.21.177', '2026-02-20 17:31:48', '2026-02-20 19:31:48'),
(279, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-21 04:39:49', '2026-02-21 06:39:49'),
(280, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-21 05:11:45', '2026-02-21 07:11:45'),
(281, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-22 13:23:28', '2026-02-22 15:23:28'),
(282, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-22 13:26:39', '2026-02-22 15:26:39'),
(283, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '105.245.24.223', '2026-02-23 08:42:16', '2026-02-23 10:42:16'),
(284, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-23 13:32:39', '2026-02-23 15:32:39'),
(285, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-24 13:08:17', '2026-02-24 15:08:17'),
(286, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-24 14:19:27', '2026-02-24 16:19:27'),
(287, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-24 15:20:25', '2026-02-24 17:20:25'),
(288, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-25 07:16:35', '2026-02-25 09:16:35'),
(289, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-26 09:38:13', '2026-02-26 11:38:13'),
(290, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-26 09:38:44', '2026-02-26 11:38:44'),
(291, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-26 09:39:13', '2026-02-26 11:39:13'),
(292, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-26 09:39:26', '2026-02-26 11:39:26'),
(293, 1, 3, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"catherine@concreto.co.za\"}', '102.22.121.190', '2026-02-26 09:43:40', '2026-02-26 11:43:40'),
(294, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-26 19:40:11', '2026-02-26 21:40:11'),
(295, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-26 19:58:25', '2026-02-26 21:58:25'),
(296, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-02-28 05:40:49', '2026-02-28 07:40:49'),
(297, 1, 1, NULL, NULL, 'user_login', NULL, NULL, '{\"email\":\"adriaan@concreto.co.za\"}', '102.22.121.190', '2026-03-01 17:12:54', '2026-03-01 19:12:54');

-- --------------------------------------------------------

--
-- Table structure for table `auth_sessions`
--

CREATE TABLE `auth_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `app_key` enum('flowwork','flowdrive') NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `session_token` char(64) NOT NULL,
  `refresh_token` char(64) NOT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_active` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_file_exports`
--

CREATE TABLE `bank_file_exports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `format` varchar(64) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `total_amount_cents` bigint(20) NOT NULL DEFAULT 0,
  `employee_count` int(11) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_import_batches`
--

CREATE TABLE `bank_import_batches` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `bank_account_id` bigint(20) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `imported_at` datetime DEFAULT current_timestamp(),
  `imported_by` int(11) NOT NULL,
  `summary_json` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_reconciliations`
--

CREATE TABLE `bank_reconciliations` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `bank_account_id` int(10) UNSIGNED NOT NULL,
  `statement_start` date NOT NULL,
  `statement_end` date NOT NULL,
  `opening_balance_cents` bigint(20) NOT NULL DEFAULT 0,
  `closing_balance_cents` bigint(20) NOT NULL DEFAULT 0,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `closed_by` int(10) UNSIGNED DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_reconciliation_lines`
--

CREATE TABLE `bank_reconciliation_lines` (
  `id` int(10) UNSIGNED NOT NULL,
  `reconciliation_id` int(10) UNSIGNED NOT NULL,
  `bank_transaction_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('matched','excluded','manual') NOT NULL DEFAULT 'matched',
  `difference_cents` bigint(20) NOT NULL DEFAULT 0,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_activity`
--

CREATE TABLE `board_activity` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_audit_log`
--

CREATE TABLE `board_audit_log` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `board_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `board_audit_log`
--

INSERT INTO `board_audit_log` (`id`, `company_id`, `board_id`, `item_id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 2, NULL, 1, 'group_added', '{\"group_id\":\"11\",\"name\":\"vfdv\"}', '102.22.121.194', '2025-10-01 10:17:13'),
(2, 1, 2, NULL, 1, 'item_created', '{\"title\":\"New Item\"}', '102.22.121.194', '2025-10-01 10:17:23'),
(3, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"8\",\"name\":\"Status\",\"type\":\"status\"}', '102.22.121.194', '2025-10-01 10:18:24'),
(4, 1, 2, 3, 1, 'status_changed', '{\"new_status\":\"Done\"}', '102.22.121.194', '2025-10-01 10:18:45'),
(5, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"9\",\"name\":\"People\",\"type\":\"people\"}', '102.22.121.194', '2025-10-01 10:19:09'),
(6, 1, 2, 10, 1, 'item_created', '{\"title\":\"New Item\"}', '102.22.121.194', '2025-10-01 10:19:21'),
(7, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"10\",\"name\":\"Date\",\"type\":\"date\"}', '102.22.121.194', '2025-10-01 10:19:52'),
(8, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"11\",\"name\":\"Timeline\",\"type\":\"timeline\"}', '102.22.121.194', '2025-10-01 10:20:21'),
(9, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"12\",\"name\":\"Total\",\"type\":\"number\"}', '102.22.121.194', '2025-10-01 10:20:53'),
(10, 1, 2, NULL, 1, 'item_created', '{\"title\":\"New Item\"}', '102.22.121.194', '2025-10-01 10:22:03'),
(11, 1, 2, 4, 1, 'status_changed', '{\"new_status\":\"Stuck\"}', '102.22.121.194', '2025-10-01 10:25:18'),
(12, 1, 2, 10, 1, 'status_changed', '{\"new_status\":\"Waiting\"}', '102.22.121.194', '2025-10-01 10:25:20'),
(13, 1, 2, 10, 1, 'status_changed', '{\"new_status\":\"Working on it\"}', '102.22.121.194', '2025-10-01 10:25:33'),
(14, 1, 2, 10, 1, 'status_changed', '{\"new_status\":\"Working on it\"}', '102.22.121.194', '2025-10-01 10:25:40'),
(15, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"13\",\"name\":\"Dropdown\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-01 10:31:33'),
(16, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"14\",\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.194', '2025-10-01 10:31:46'),
(17, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"15\",\"name\":\"Formula\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-01 10:32:00'),
(18, 1, 2, NULL, 1, 'column_added', '{\"column_id\":\"16\",\"name\":\"Checkbox\",\"type\":\"checkbox\"}', '102.22.121.194', '2025-10-01 10:32:09'),
(19, 1, 2, 12, 1, 'item_created', '{\"title\":\"New Item\"}', '102.22.121.194', '2025-10-01 10:33:13'),
(20, 1, 2, NULL, 1, 'group_deleted', '{\"group_id\":3}', '102.22.121.194', '2025-10-01 10:38:37'),
(21, 1, 1, 6, 1, 'status_changed', '{\"new_status\":\"Stuck\"}', '102.22.121.194', '2025-10-01 12:36:50'),
(22, 1, 1, 8, 1, 'status_changed', '{\"new_status\":\"Done\"}', '102.22.121.194', '2025-10-01 12:36:51'),
(23, 1, 1, 13, 1, 'item_created', '{\"title\":\"New Item\"}', '102.22.121.194', '2025-10-01 17:29:41'),
(24, 1, 1, NULL, 1, 'column_added', '{\"column_id\":\"17\",\"name\":\"Who Paid\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-01 17:30:37'),
(25, 1, 1, 13, 1, 'status_changed', '{\"new_status\":\"Done\"}', '102.22.121.194', '2025-10-01 17:31:30'),
(26, 1, 1, 13, 1, 'status_changed', '{\"new_status\":\"Waiting\"}', '102.22.121.194', '2025-10-02 17:25:02'),
(27, 1, 1, NULL, 1, 'column_added', '{\"column_id\":\"24\",\"name\":\"Timeline\",\"type\":\"timeline\"}', '102.22.121.194', '2025-10-02 17:25:58'),
(28, 1, 1, NULL, 1, 'created item', NULL, NULL, '2025-10-03 10:04:06'),
(29, 1, 1, NULL, 1, 'created item', NULL, NULL, '2025-10-03 10:05:07'),
(30, 1, 1, NULL, 1, 'column_added', '{\"column_id\":\"32\",\"name\":\"Formula\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-03 11:45:09'),
(31, 1, 1, 16, 1, 'item_created', '{\"title\":\"New Item\"}', '102.22.121.194', '2025-10-03 11:45:18'),
(34, 1, 1, 17, 1, 'item_created', '{\"title\":\"New Item\"}', '102.22.121.194', '2025-10-03 11:48:08'),
(36, 1, 6, NULL, 1, 'created item', NULL, '102.22.121.194', '2025-10-03 14:12:14'),
(37, 1, 6, NULL, 1, 'created item', NULL, '102.22.121.194', '2025-10-03 14:13:46'),
(38, 1, 6, NULL, 1, 'created item', NULL, '102.22.121.194', '2025-10-03 16:12:59'),
(39, 1, 6, NULL, 1, 'created column', '{\"column_id\":\"37\",\"name\":\"Total\\/unit\",\"type\":\"number\"}', '102.22.121.194', '2025-10-03 16:59:42'),
(40, 1, 6, NULL, 1, 'created column', '{\"column_id\":\"38\",\"name\":\"Description\",\"type\":\"text\"}', '102.22.121.194', '2025-10-03 18:03:22'),
(41, 1, 6, NULL, 1, 'item_created', '{\"title\":\"saxa\"}', '102.22.121.194', '2025-10-07 12:18:45'),
(42, 1, 6, 23, 1, 'item_created', '{\"title\":\"sadad\"}', '102.22.121.194', '2025-10-07 12:30:42'),
(43, 1, 6, NULL, 1, 'item_created', '{\"title\":\"sdfsdfsd\"}', '102.22.121.194', '2025-10-07 13:00:26'),
(44, 1, 6, NULL, 1, 'column_added', '{\"column_id\":39,\"name\":\"New Column\",\"type\":\"status\"}', '102.22.121.194', '2025-10-07 13:58:51'),
(45, 1, 6, NULL, 1, 'item_created', '{\"title\":\"dfsgsdfgsdf\"}', '102.22.121.194', '2025-10-07 13:59:02'),
(46, 1, 6, NULL, 1, 'column_added', '{\"column_id\":40,\"name\":\"New Column\",\"type\":\"date\"}', '102.22.121.194', '2025-10-07 14:20:04'),
(47, 1, 6, NULL, 1, 'item_created', '{\"title\":\"dd\"}', '102.22.121.194', '2025-10-07 14:20:19'),
(48, 1, 6, 27, 1, 'item_created', '{\"title\":\"sdfsf\"}', '102.22.121.194', '2025-10-07 14:35:49'),
(49, 1, 6, NULL, 1, 'column_added', '{\"column_id\":41,\"name\":\"New Column\",\"type\":\"number\"}', '102.22.121.194', '2025-10-07 15:23:30'),
(50, 1, 6, NULL, 1, 'item_created', '{\"title\":\"attie\"}', '102.22.121.194', '2025-10-07 15:49:41'),
(51, 1, 6, 29, 1, 'item_created', '{\"title\":\"fdvd\"}', '102.22.121.194', '2025-10-07 16:51:21'),
(52, 1, 6, 30, 1, 'item_created', '{\"title\":\"dfsg\"}', '102.22.121.194', '2025-10-07 18:02:53'),
(53, 1, 6, NULL, 1, 'item_created', '{\"title\":\"s\"}', '102.22.121.194', '2025-10-07 19:25:12'),
(54, 1, 6, 32, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-07 19:32:47'),
(55, 1, 6, NULL, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-07 19:34:53'),
(56, 1, 6, NULL, 1, 'item_created', '{\"title\":\"s\"}', '102.22.121.194', '2025-10-07 20:04:21'),
(57, 1, 6, 35, 1, 'item_created', '{\"title\":\"sasad\"}', '102.22.121.194', '2025-10-07 22:00:17'),
(58, 1, 6, NULL, 1, 'column_added', '{\"column_id\":42,\"name\":\"New Column\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-07 22:01:34'),
(59, 1, 6, NULL, 1, 'column_added', '{\"column_id\":43,\"name\":\"New Column\",\"type\":\"number\"}', '102.22.121.194', '2025-10-07 22:02:03'),
(60, 1, 6, NULL, 1, 'group_added', '{\"group_id\":20,\"name\":\"dweds\"}', '102.22.121.194', '2025-10-07 22:53:16'),
(61, 1, 6, NULL, 1, 'group_added', '{\"group_id\":21,\"name\":\"sadasdasdas\"}', '102.22.121.194', '2025-10-07 22:55:19'),
(62, 1, 6, NULL, 1, 'group_added', '{\"group_id\":22,\"name\":\"C\"}', '102.22.121.194', '2025-10-07 22:56:01'),
(63, 1, 6, NULL, 1, 'item_created', '{\"title\":\"aaa\"}', '102.22.121.194', '2025-10-07 23:04:23'),
(64, 1, 6, NULL, 1, 'group_added', '{\"group_id\":23,\"name\":\"C\"}', '102.22.121.194', '2025-10-07 23:04:32'),
(65, 1, 6, NULL, 1, 'group_added', '{\"group_id\":24,\"name\":\"C\"}', '102.22.121.194', '2025-10-07 23:16:58'),
(66, 1, 6, NULL, 1, 'group_added', '{\"group_id\":25,\"name\":\"V\"}', '102.22.121.194', '2025-10-08 06:39:39'),
(67, 1, 6, NULL, 1, 'item_created', '{\"title\":\"111\"}', '102.22.121.194', '2025-10-08 06:43:17'),
(68, 1, 6, NULL, 1, 'group_added', '{\"group_id\":26,\"name\":\"C\"}', '102.22.121.194', '2025-10-08 07:31:11'),
(69, 1, 6, NULL, 1, 'group_added', '{\"group_id\":27,\"name\":\"C\"}', '102.22.121.194', '2025-10-08 07:36:34'),
(70, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":29,\"name\":\"A\"}', '102.22.121.194', '2025-10-08 07:37:24'),
(71, 1, 6, 38, 1, 'item_created', '{\"title\":\"aassaa\"}', '102.22.121.194', '2025-10-08 07:38:00'),
(72, 1, 6, NULL, 1, 'group_added', '{\"group_id\":30,\"name\":\"r\"}', '102.22.121.194', '2025-10-08 07:55:21'),
(73, 1, 6, NULL, 1, 'group_added', '{\"group_id\":31,\"name\":\"c\"}', '102.22.121.194', '2025-10-08 08:11:36'),
(74, 1, 6, NULL, 1, 'group_added', '{\"group_id\":32,\"name\":\"CCCC\"}', '102.22.121.194', '2025-10-08 08:18:11'),
(75, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":33,\"name\":\"a\"}', '102.22.121.194', '2025-10-08 08:56:07'),
(76, 1, 6, 39, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-08 09:01:44'),
(77, 1, 6, NULL, 1, 'group_added', '{\"group_id\":34,\"name\":\"asasasas\"}', '102.22.121.194', '2025-10-08 09:16:13'),
(78, 1, 6, NULL, 1, 'group_added', '{\"group_id\":44,\"name\":\"a\"}', '102.22.121.194', '2025-10-08 09:18:56'),
(79, 1, 6, NULL, 1, 'group_added', '{\"group_id\":45,\"name\":\"ek wys 3 de\"}', '102.22.121.194', '2025-10-08 09:19:08'),
(80, 1, 6, 40, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-08 09:42:18'),
(81, 1, 6, NULL, 1, 'group_added', '{\"group_id\":46,\"name\":\"1212\"}', '102.22.121.194', '2025-10-08 09:42:36'),
(82, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":47,\"name\":\"Skepsel 1\"}', '102.22.121.194', '2025-10-08 09:50:24'),
(83, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":48,\"name\":\"Skepsel 2\"}', '102.22.121.194', '2025-10-08 09:52:23'),
(84, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-08 09:52:37'),
(85, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-08 09:52:41'),
(86, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":59,\"name\":\"New Column\",\"type\":\"number\"}', '102.22.121.194', '2025-10-08 09:53:16'),
(87, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":60,\"name\":\"New Column\",\"type\":\"number\"}', '102.22.121.194', '2025-10-08 09:53:34'),
(88, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":61,\"name\":\"New Column\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-08 09:53:44'),
(89, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":62,\"name\":\"New Column\",\"type\":\"people\"}', '102.22.121.194', '2025-10-08 09:55:23'),
(90, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":63,\"name\":\"New Column\",\"type\":\"text\"}', '102.22.121.194', '2025-10-08 09:55:50'),
(91, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"s\"}', '102.22.121.194', '2025-10-08 09:58:32'),
(92, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-08 09:58:39'),
(93, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":49,\"name\":\"Section 3\"}', '102.22.121.194', '2025-10-08 10:28:43'),
(94, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"Setiis\"}', '102.22.121.194', '2025-10-08 10:45:46'),
(95, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"6\"}', '102.22.121.194', '2025-10-08 11:24:15'),
(96, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"8\"}', '102.22.121.194', '2025-10-08 11:42:21'),
(97, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":50,\"name\":\"12\"}', '102.22.121.194', '2025-10-08 11:52:11'),
(98, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"13\"}', '102.22.121.194', '2025-10-08 11:52:20'),
(99, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-08 12:17:27'),
(100, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"15\"}', '102.22.121.194', '2025-10-08 12:18:27'),
(101, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"dddd\"}', '102.22.121.194', '2025-10-08 18:33:05'),
(102, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"111\"}', '102.22.121.194', '2025-10-08 18:59:17'),
(103, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"asdf\"}', '102.22.121.194', '2025-10-08 19:17:07'),
(104, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"aaaa\"}', '102.22.121.194', '2025-10-08 19:24:11'),
(105, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":64,\"name\":\"aaa\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-08 20:04:42'),
(106, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"12121\"}', '102.22.121.194', '2025-10-08 20:05:06'),
(107, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"see\"}', '102.22.121.194', '2025-10-08 20:35:03'),
(108, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"aaaadddd\"}', '102.22.121.194', '2025-10-08 20:40:24'),
(109, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":51,\"name\":\"asdf\"}', '102.22.121.194', '2025-10-08 20:50:26'),
(110, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"aaaaa3333\"}', '102.22.121.194', '2025-10-08 20:52:28'),
(111, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":52,\"name\":\"222aaa222\"}', '102.22.121.194', '2025-10-08 21:25:16'),
(112, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"aaa1111\"}', '102.22.121.194', '2025-10-08 21:25:24'),
(113, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":53,\"name\":\"ttt\"}', '102.22.121.194', '2025-10-09 08:50:56'),
(114, 1, 6, NULL, 1, 'item_created', '{\"title\":\"aaa\"}', '102.22.121.194', '2025-10-09 09:59:01'),
(115, 1, 6, NULL, 1, 'column_added', '{\"column_id\":65,\"name\":\"Timeline\",\"type\":\"timeline\"}', '102.22.121.194', '2025-10-09 10:31:59'),
(116, 1, 6, NULL, 1, 'column_added', '{\"column_id\":66,\"name\":\"Dropdown\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-09 10:34:25'),
(117, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":67,\"name\":\"Total\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-09 11:24:26'),
(118, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":68,\"name\":\"Status\",\"type\":\"status\"}', '102.22.121.194', '2025-10-09 11:28:28'),
(119, 1, 6, NULL, 1, 'column_added', '{\"column_id\":69,\"name\":\"Assign\",\"type\":\"people\"}', '102.22.121.194', '2025-10-09 11:47:30'),
(120, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":70,\"name\":\"Drop\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-09 11:48:57'),
(121, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"asass\"}', '102.22.121.194', '2025-10-09 11:52:15'),
(122, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":71,\"name\":\"xx\",\"type\":\"date\"}', '102.22.121.194', '2025-10-09 11:55:17'),
(123, 1, 6, NULL, 1, 'group_added', '{\"group_id\":54,\"name\":\"444\"}', '102.22.121.194', '2025-10-09 12:25:34'),
(124, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":80,\"name\":\"www\",\"type\":\"timeline\"}', '102.22.121.194', '2025-10-09 12:32:20'),
(125, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":81,\"name\":\"ppp\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-09 12:33:26'),
(126, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":82,\"name\":\"Status\",\"type\":\"status\"}', '102.22.121.194', '2025-10-09 13:10:28'),
(127, 1, 6, NULL, 1, 'column_added', '{\"column_id\":83,\"name\":\"date\",\"type\":\"date\"}', '102.22.121.194', '2025-10-09 15:54:18'),
(128, 1, 6, NULL, 1, 'column_added', '{\"column_id\":84,\"name\":\"j\",\"type\":\"people\"}', '102.22.121.194', '2025-10-09 15:55:33'),
(129, 1, 6, NULL, 1, 'column_added', '{\"column_id\":85,\"name\":\"544\",\"type\":\"text\"}', '102.22.121.194', '2025-10-10 04:05:32'),
(130, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":97,\"name\":\"text\",\"type\":\"text\"}', '102.22.121.194', '2025-10-10 04:54:16'),
(131, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":64,\"name\":\"a\"}', '102.22.121.194', '2025-10-10 04:59:26'),
(132, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":98,\"name\":\"1234\",\"type\":\"text\"}', '102.22.121.194', '2025-10-10 05:56:09'),
(133, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"1 st Item\"}', '102.22.121.194', '2025-10-10 05:56:21'),
(134, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-10 06:12:51'),
(135, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"b\"}', '102.22.121.194', '2025-10-10 06:15:28'),
(136, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":65,\"name\":\"Phase 4\"}', '102.22.121.194', '2025-10-10 08:00:23'),
(137, 1, NULL, NULL, 1, 'item_created', '{\"title\":\"a\"}', '102.22.121.194', '2025-10-10 13:49:58'),
(138, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":75,\"name\":\"111\"}', '102.22.121.194', '2025-10-10 17:48:09'),
(139, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":76,\"name\":\"aaaaa\"}', '102.22.121.194', '2025-10-10 21:42:45'),
(140, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":110,\"name\":\"Status A1\",\"type\":\"status\"}', '102.22.121.194', '2025-10-10 21:45:10'),
(141, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":111,\"name\":\"ASDF\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-11 08:40:32'),
(142, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":77,\"name\":\"vvv\"}', '102.22.121.194', '2025-10-11 08:43:31'),
(143, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":78,\"name\":\"vv\"}', '102.22.121.194', '2025-10-11 09:16:15'),
(144, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":112,\"name\":\"ddd\",\"type\":\"date\"}', '102.22.121.194', '2025-10-11 10:09:13'),
(145, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":113,\"name\":\"ddd\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-11 10:09:31'),
(146, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":114,\"name\":\"AAA\",\"type\":\"text\"}', '102.22.121.194', '2025-10-11 11:40:14'),
(147, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":79,\"name\":\"TESTING ALL\"}', '102.22.121.194', '2025-10-11 12:15:42'),
(148, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":115,\"name\":\"111\",\"type\":\"number\"}', '102.22.121.194', '2025-10-11 12:58:20'),
(149, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":116,\"name\":\"aaa\",\"type\":\"text\"}', '102.22.121.194', '2025-10-11 12:58:28'),
(150, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":117,\"name\":\"Status\",\"type\":\"status\"}', '102.22.121.194', '2025-10-11 12:58:35'),
(151, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":118,\"name\":\"People\",\"type\":\"people\"}', '102.22.121.194', '2025-10-11 12:58:44'),
(152, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":119,\"name\":\"Date\",\"type\":\"date\"}', '102.22.121.194', '2025-10-11 12:58:52'),
(153, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":120,\"name\":\"Dropdown\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-11 12:59:27'),
(154, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":121,\"name\":\"Formula\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-11 12:59:43'),
(155, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":122,\"name\":\"Dropdown\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-11 13:05:50'),
(156, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":80,\"name\":\"TEST\"}', '102.22.121.194', '2025-10-11 13:10:49'),
(157, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":81,\"name\":\"aas\"}', '102.22.121.194', '2025-10-11 13:20:50'),
(158, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":123,\"name\":\"text\",\"type\":\"text\"}', '102.22.121.194', '2025-10-11 14:05:25'),
(159, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":124,\"name\":\"Number\",\"type\":\"number\"}', '102.22.121.194', '2025-10-11 14:05:44'),
(160, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":125,\"name\":\"Status\",\"type\":\"status\"}', '102.22.121.194', '2025-10-11 14:05:51'),
(161, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":126,\"name\":\"People\",\"type\":\"people\"}', '102.22.121.194', '2025-10-11 14:05:59'),
(162, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":127,\"name\":\"Date\",\"type\":\"date\"}', '102.22.121.194', '2025-10-11 14:06:04'),
(163, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":128,\"name\":\"Dropdown\",\"type\":\"dropdown\"}', '102.22.121.194', '2025-10-11 14:06:27'),
(164, 1, NULL, NULL, 1, 'column_added', '{\"column_id\":129,\"name\":\"Formula\",\"type\":\"formula\"}', '102.22.121.194', '2025-10-11 14:06:38'),
(165, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":82,\"name\":\"Phase 4\"}', '102.22.121.194', '2025-10-11 15:44:49'),
(166, 1, NULL, NULL, 1, 'group_added', '{\"group_id\":83,\"name\":\"1234\"}', '102.22.121.194', '2025-10-11 17:10:08'),
(167, 1, 6, NULL, 1, 'group_added', '{\"group_id\":93,\"name\":\"4\"}', '102.22.121.194', '2025-10-12 10:58:16'),
(168, 1, 6, NULL, 1, 'group_added', '{\"group_id\":94,\"name\":\"a\"}', '102.22.121.194', '2025-10-12 12:10:59'),
(169, 1, 6, NULL, 1, 'group_added', '{\"group_id\":95,\"name\":\"ss\"}', '102.22.121.194', '2025-10-12 16:14:10'),
(170, 1, 24, NULL, 3, 'group_added', '{\"group_id\":114,\"name\":\"Ground Floor Masonry\\/Brickwork\"}', '102.22.121.194', '2025-10-17 11:45:01'),
(171, 1, 24, NULL, 3, 'group_added', '{\"group_id\":115,\"name\":\"Slab\"}', '102.22.121.194', '2025-10-17 13:02:45'),
(172, 1, 17, NULL, 4, 'group_added', '{\"group_id\":116,\"name\":\"New Double story corner area\"}', '102.22.121.194', '2025-10-18 12:29:18'),
(173, 1, 17, NULL, 4, 'group_added', '{\"group_id\":117,\"name\":\"Brickwork\"}', '102.22.121.194', '2025-10-18 12:41:54'),
(174, 1, 17, NULL, 4, 'group_added', '{\"group_id\":118,\"name\":\"Paintwork\"}', '102.22.121.194', '2025-10-18 13:48:25'),
(175, 1, 24, NULL, 3, 'group_added', '{\"group_id\":128,\"name\":\"First Floor Masonry\\/ Brickforce\"}', '102.22.121.194', '2025-10-18 15:39:41'),
(176, 1, 24, NULL, 3, 'group_added', '{\"group_id\":129,\"name\":\"Roofing\"}', '102.22.121.194', '2025-10-18 16:44:19'),
(177, 1, 24, NULL, 3, 'group_added', '{\"group_id\":130,\"name\":\"Carpentry & Iornmongery\"}', '102.22.121.194', '2025-10-18 16:48:12'),
(178, 1, 24, NULL, 3, 'group_added', '{\"group_id\":131,\"name\":\"Ceilings & Cornice\"}', '102.22.121.194', '2025-10-18 16:50:09'),
(179, 1, 24, NULL, 3, 'group_added', '{\"group_id\":132,\"name\":\"Joinery\"}', '102.22.121.194', '2025-10-18 16:53:23'),
(180, 1, 24, NULL, 3, 'group_added', '{\"group_id\":133,\"name\":\"Iornmongery\"}', '102.22.121.194', '2025-10-18 16:53:39'),
(181, 1, 24, NULL, 3, 'group_added', '{\"group_id\":134,\"name\":\"Metalwork (structual columns)\"}', '102.22.121.194', '2025-10-18 16:54:38'),
(182, 1, 24, NULL, 3, 'group_added', '{\"group_id\":135,\"name\":\"Plastering & Screeds\"}', '102.22.121.194', '2025-10-18 17:04:34'),
(183, 1, 24, NULL, 3, 'group_added', '{\"group_id\":136,\"name\":\"Plumbing\"}', '102.22.121.194', '2025-10-18 17:31:55'),
(184, 1, 24, NULL, 3, 'group_added', '{\"group_id\":137,\"name\":\"Electrical installation\"}', '102.22.121.194', '2025-10-18 17:44:37'),
(185, 1, 24, NULL, 3, 'group_added', '{\"group_id\":138,\"name\":\"Gutters\"}', '102.22.121.194', '2025-10-18 17:49:22'),
(186, 1, 24, NULL, 3, 'group_added', '{\"group_id\":139,\"name\":\"|Aluminium\\/ doors\\/ windows\\/ Balustrades\"}', '102.22.121.194', '2025-10-18 17:52:39'),
(187, 1, 24, NULL, 3, 'group_added', '{\"group_id\":140,\"name\":\"Finishes (Floors & walls Multi-skim)\"}', '102.22.121.194', '2025-10-18 18:05:48'),
(188, 1, 24, NULL, 3, 'group_added', '{\"group_id\":141,\"name\":\"Painting\"}', '102.22.121.194', '2025-10-18 18:08:47'),
(189, 1, 24, NULL, 3, 'group_added', '{\"group_id\":142,\"name\":\"Gas Reticulation\"}', '102.22.121.194', '2025-10-18 18:13:03'),
(190, 1, 24, NULL, 3, 'group_added', '{\"group_id\":143,\"name\":\"External Surfaces\"}', '102.22.121.194', '2025-10-18 18:13:24'),
(191, 1, 24, NULL, 3, 'group_added', '{\"group_id\":144,\"name\":\"Swimming Pool\"}', '102.22.121.194', '2025-10-18 18:13:37'),
(192, 1, 24, NULL, 3, 'group_added', '{\"group_id\":145,\"name\":\"Provisional sums (Sanitaries)\"}', '102.22.121.194', '2025-10-18 18:14:06'),
(193, 1, 24, NULL, 3, 'group_added', '{\"group_id\":146,\"name\":\"Cleaning\"}', '102.22.121.194', '2025-10-18 18:16:07'),
(194, 1, 24, NULL, 3, 'group_added', '{\"group_id\":147,\"name\":\"Storm water\"}', '102.22.121.194', '2025-10-18 18:16:26'),
(195, 1, 24, NULL, 3, 'group_added', '{\"group_id\":148,\"name\":\"Labour\"}', '102.22.121.194', '2025-10-19 10:03:39'),
(196, 1, 24, NULL, 3, 'group_added', '{\"group_id\":149,\"name\":\"Adriaan Salary\"}', '102.22.121.194', '2025-10-19 12:05:32'),
(197, 1, 6, NULL, 3, 'group_added', '{\"group_id\":150,\"name\":\"50 x 5 Flat Bar x 60m\\/ 16 Round Bar x 6.0 M\\/ ect\"}', '102.22.121.194', '2025-10-20 14:01:02'),
(198, 1, 24, NULL, 1, 'group_added', '{\"group_id\":160,\"name\":\"Property Cost\"}', '102.22.121.194', '2025-10-22 13:07:32'),
(199, 1, 18, NULL, 3, 'group_added', '{\"group_id\":161,\"name\":\"Floats\"}', '102.22.121.194', '2025-11-07 07:27:39'),
(200, 1, 18, NULL, 3, 'group_added', '{\"group_id\":162,\"name\":\"Winston\"}', '102.22.121.194', '2025-11-10 15:44:45'),
(201, 1, 18, NULL, 3, 'group_added', '{\"group_id\":163,\"name\":\"Rivonia + Water Edge\"}', '102.22.121.194', '2025-11-12 12:46:09'),
(202, 1, 27, NULL, 3, 'group_added', '{\"group_id\":164,\"name\":\"Winston\"}', '102.22.121.194', '2025-11-18 08:36:09'),
(203, 1, 18, NULL, 3, 'group_added', '{\"group_id\":165,\"name\":\"Stanly\"}', '41.13.11.93', '2025-11-21 04:34:42'),
(204, 1, 33, NULL, 1, 'group_added', '{\"group_id\":175,\"name\":\"EXTRAS\"}', '102.22.121.194', '2025-12-04 09:45:32'),
(205, 1, 18, NULL, 3, 'group_added', '{\"group_id\":176,\"name\":\"Extras\"}', '102.22.121.190', '2025-12-09 10:59:41'),
(206, 1, 38, NULL, 1, 'group_added', '{\"group_id\":195,\"name\":\"Painting\"}', '102.22.121.190', '2025-12-15 15:46:19'),
(207, 1, 38, NULL, 1, 'group_added', '{\"group_id\":196,\"name\":\"Skirtings and loabour on doors\"}', '102.22.121.190', '2025-12-15 15:47:00'),
(208, 1, 27, NULL, 3, 'group_added', '{\"group_id\":197,\"name\":\"Wages\"}', '102.22.121.190', '2026-01-13 07:45:33'),
(209, 1, 45, NULL, 1, 'column_added', '{\"column_id\":354,\"name\":\"Formula\",\"type\":\"formula\"}', '102.22.121.190', '2026-01-15 11:47:33'),
(210, 1, 45, NULL, 1, 'column_added', '{\"column_id\":355,\"name\":\"THU\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:48:10'),
(211, 1, 45, NULL, 1, 'column_added', '{\"column_id\":356,\"name\":\"FRI\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:49:07'),
(212, 1, 45, NULL, 1, 'column_added', '{\"column_id\":357,\"name\":\"SAT\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:51:54'),
(213, 1, 45, NULL, 1, 'column_added', '{\"column_id\":358,\"name\":\"SUN\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:52:11'),
(214, 1, 45, NULL, 1, 'column_added', '{\"column_id\":359,\"name\":\"SAT\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:53:51'),
(215, 1, 45, NULL, 1, 'column_added', '{\"column_id\":360,\"name\":\"SUN\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:55:00'),
(216, 1, 45, NULL, 1, 'column_added', '{\"column_id\":361,\"name\":\"MON\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:55:59'),
(217, 1, 45, NULL, 1, 'column_added', '{\"column_id\":362,\"name\":\"THE\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:56:11'),
(218, 1, 45, NULL, 1, 'column_added', '{\"column_id\":363,\"name\":\"WED\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:56:24'),
(219, 1, 45, NULL, 1, 'column_added', '{\"column_id\":364,\"name\":\"THU\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:56:40'),
(220, 1, 45, NULL, 1, 'column_added', '{\"column_id\":365,\"name\":\"FRI\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 11:56:56'),
(221, 1, 45, NULL, 3, 'group_added', '{\"group_id\":216,\"name\":\"PLOT\"}', '102.22.121.190', '2026-01-15 12:56:31'),
(222, 1, 45, NULL, 3, 'group_added', '{\"group_id\":217,\"name\":\"Grandiceps\"}', '102.22.121.190', '2026-01-15 13:06:29'),
(223, 1, 45, NULL, 3, 'group_added', '{\"group_id\":218,\"name\":\"Monthly Salaries\"}', '102.22.121.190', '2026-01-15 13:11:41'),
(224, 1, 45, NULL, 1, 'column_added', '{\"column_id\":366,\"name\":\"SUN 4\",\"type\":\"number\"}', '102.22.121.190', '2026-01-15 13:13:42'),
(225, 1, 24, NULL, 3, 'group_added', '{\"group_id\":219,\"name\":\"Wages\"}', '102.22.121.190', '2026-01-16 14:23:19'),
(226, 1, 33, NULL, 1, 'group_added', '{\"group_id\":220,\"name\":\"Inkomste\"}', '102.22.121.190', '2026-01-17 09:19:05'),
(227, 1, 33, NULL, 1, 'group_added', '{\"group_id\":221,\"name\":\"Na die tyd Uitgawes\"}', '102.22.121.190', '2026-01-17 09:24:08'),
(228, 1, 48, NULL, 3, 'column_added', '{\"column_id\":378,\"name\":\"Sat 17\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:16:45'),
(229, 1, 48, NULL, 3, 'column_added', '{\"column_id\":379,\"name\":\"Sun 18\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:17:00'),
(230, 1, 48, NULL, 3, 'column_added', '{\"column_id\":380,\"name\":\"Mon 19\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:17:14'),
(231, 1, 48, NULL, 3, 'column_added', '{\"column_id\":381,\"name\":\"Tue 20\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:17:37'),
(232, 1, 48, NULL, 3, 'column_added', '{\"column_id\":382,\"name\":\"WED 21\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:17:59'),
(233, 1, 48, NULL, 3, 'column_added', '{\"column_id\":383,\"name\":\"THU 22\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:18:14'),
(234, 1, 48, NULL, 3, 'column_added', '{\"column_id\":384,\"name\":\"FRI 23\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:18:34'),
(235, 1, 48, NULL, 3, 'column_added', '{\"column_id\":385,\"name\":\"SAT 24\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:18:46'),
(236, 1, 48, NULL, 3, 'column_added', '{\"column_id\":386,\"name\":\"SUN 25\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:18:58'),
(237, 1, 48, NULL, 3, 'column_added', '{\"column_id\":387,\"name\":\"MON 26\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:19:10'),
(238, 1, 48, NULL, 3, 'column_added', '{\"column_id\":388,\"name\":\"TUE 26\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:20:01'),
(239, 1, 48, NULL, 3, 'column_added', '{\"column_id\":389,\"name\":\"WED 28\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:20:15'),
(240, 1, 48, NULL, 3, 'column_added', '{\"column_id\":390,\"name\":\"THU 29\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:20:49'),
(241, 1, 48, NULL, 3, 'column_added', '{\"column_id\":391,\"name\":\"FRI 30\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:21:02'),
(242, 1, 48, NULL, 3, 'column_added', '{\"column_id\":392,\"name\":\"Total Days\",\"type\":\"formula\"}', '102.22.121.190', '2026-01-19 05:22:56'),
(243, 1, 48, NULL, 3, 'column_added', '{\"column_id\":393,\"name\":\"Rate\",\"type\":\"number\"}', '102.22.121.190', '2026-01-19 05:23:22'),
(244, 1, 48, NULL, 3, 'column_added', '{\"column_id\":394,\"name\":\"Salary\",\"type\":\"formula\"}', '102.22.121.190', '2026-01-19 05:24:18'),
(245, 1, 48, NULL, 3, 'column_added', '{\"column_id\":395,\"name\":\"JOB DESCRIPTION\",\"type\":\"text\"}', '102.22.121.190', '2026-01-19 05:30:06'),
(246, 1, 48, NULL, 3, 'column_added', '{\"column_id\":396,\"name\":\"Total days\",\"type\":\"formula\"}', '102.22.121.190', '2026-01-27 14:53:58'),
(247, 1, 48, NULL, 3, 'column_added', '{\"column_id\":397,\"name\":\"Salary\",\"type\":\"formula\"}', '102.22.121.190', '2026-01-27 14:55:01'),
(248, 1, 51, NULL, 3, 'column_added', '{\"column_id\":409,\"name\":\"Sat 31\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:29:54'),
(249, 1, 51, NULL, 3, 'column_added', '{\"column_id\":410,\"name\":\"Son 1\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:30:08'),
(250, 1, 51, NULL, 3, 'column_added', '{\"column_id\":411,\"name\":\"Maan 2\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:30:22'),
(251, 1, 51, NULL, 3, 'column_added', '{\"column_id\":412,\"name\":\"Dins 3\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:30:34'),
(252, 1, 51, NULL, 3, 'column_added', '{\"column_id\":413,\"name\":\"woen 4\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:30:45'),
(253, 1, 51, NULL, 3, 'column_added', '{\"column_id\":414,\"name\":\"dond 5\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:30:55'),
(254, 1, 51, NULL, 3, 'column_added', '{\"column_id\":415,\"name\":\"vry 6\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:31:08'),
(255, 1, 51, NULL, 3, 'column_added', '{\"column_id\":416,\"name\":\"sat 7\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:31:23'),
(256, 1, 51, NULL, 3, 'column_added', '{\"column_id\":417,\"name\":\"son 8\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:31:34'),
(257, 1, 51, NULL, 3, 'column_added', '{\"column_id\":418,\"name\":\"maan 9\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:31:48'),
(258, 1, 51, NULL, 3, 'column_added', '{\"column_id\":419,\"name\":\"dins 10\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:32:02'),
(259, 1, 51, NULL, 3, 'column_added', '{\"column_id\":420,\"name\":\"woen 11\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:32:18'),
(260, 1, 51, NULL, 3, 'column_added', '{\"column_id\":421,\"name\":\"dond 12\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:32:31'),
(261, 1, 51, NULL, 3, 'column_added', '{\"column_id\":422,\"name\":\"vry 13\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:32:44'),
(262, 1, 51, NULL, 3, 'column_added', '{\"column_id\":423,\"name\":\"Total Days\",\"type\":\"formula\"}', '102.22.121.190', '2026-02-05 09:35:29'),
(263, 1, 51, NULL, 3, 'column_added', '{\"column_id\":424,\"name\":\"Job description\",\"type\":\"text\"}', '102.22.121.190', '2026-02-05 09:35:52'),
(264, 1, 51, NULL, 3, 'group_added', '{\"group_id\":241,\"name\":\"Rivonia\"}', '102.22.121.190', '2026-02-05 09:37:33'),
(265, 1, 51, NULL, 3, 'column_added', '{\"column_id\":425,\"name\":\"Rate\",\"type\":\"number\"}', '102.22.121.190', '2026-02-05 09:38:52'),
(266, 1, 51, NULL, 3, 'column_added', '{\"column_id\":426,\"name\":\"Salary\",\"type\":\"formula\"}', '102.22.121.190', '2026-02-05 09:39:38'),
(267, 1, 51, NULL, 3, 'group_added', '{\"group_id\":242,\"name\":\"Waters Edge\"}', '102.22.121.190', '2026-02-05 10:00:50'),
(268, 1, 51, NULL, 3, 'group_added', '{\"group_id\":243,\"name\":\"Plot\"}', '102.22.121.190', '2026-02-05 10:12:44'),
(269, 1, 27, NULL, 3, 'group_added', '{\"group_id\":244,\"name\":\"Glass\"}', '102.22.121.190', '2026-02-11 06:55:33'),
(270, 1, 53, NULL, 1, 'column_added', '{\"column_id\":438,\"name\":\"Quoted\",\"type\":\"number\"}', '102.22.121.190', '2026-02-13 10:06:15'),
(271, 1, 53, NULL, 1, 'column_added', '{\"column_id\":439,\"name\":\"Projected Cost\",\"type\":\"number\"}', '102.22.121.190', '2026-02-13 10:06:32'),
(272, 1, 53, NULL, 1, 'group_added', '{\"group_id\":254,\"name\":\"Palasades\"}', '102.22.121.190', '2026-02-13 10:12:00'),
(273, 1, 56, NULL, 1, 'column_added', '{\"column_id\":459,\"name\":\"Quoted\",\"type\":\"number\"}', '102.22.121.190', '2026-02-13 10:34:25'),
(274, 1, 54, NULL, 1, 'column_added', '{\"column_id\":460,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:15:27'),
(275, 1, 54, NULL, 1, 'column_added', '{\"column_id\":461,\"name\":\"Number\",\"type\":\"number\"}', '102.22.121.190', '2026-02-18 15:15:42'),
(276, 1, 54, NULL, 1, 'column_added', '{\"column_id\":462,\"name\":\"Date\",\"type\":\"date\"}', '102.22.121.190', '2026-02-18 15:15:51'),
(277, 1, 54, NULL, 1, 'column_added', '{\"column_id\":463,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:16:07'),
(278, 1, 54, NULL, 1, 'column_added', '{\"column_id\":464,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:16:13'),
(279, 1, 54, NULL, 1, 'column_added', '{\"column_id\":465,\"name\":\"Checkbox\",\"type\":\"checkbox\"}', '102.22.121.190', '2026-02-18 15:18:43'),
(280, 1, 54, NULL, 1, 'column_added', '{\"column_id\":466,\"name\":\"Dropdown\",\"type\":\"dropdown\"}', '102.22.121.190', '2026-02-18 15:29:04'),
(281, 1, 54, NULL, 1, 'column_added', '{\"column_id\":467,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:34:18'),
(282, 1, 54, NULL, 1, 'column_added', '{\"column_id\":468,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:34:23'),
(283, 1, 54, NULL, 1, 'column_added', '{\"column_id\":469,\"name\":\"Number\",\"type\":\"number\"}', '102.22.121.190', '2026-02-18 15:34:27'),
(284, 1, 54, NULL, 1, 'column_added', '{\"column_id\":470,\"name\":\"Date\",\"type\":\"date\"}', '102.22.121.190', '2026-02-18 15:34:33'),
(285, 1, 54, NULL, 1, 'column_added', '{\"column_id\":471,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:34:39'),
(286, 1, 54, NULL, 1, 'column_added', '{\"column_id\":472,\"name\":\"Payment Status\",\"type\":\"dropdown\"}', '102.22.121.190', '2026-02-18 15:35:39'),
(287, 1, 57, NULL, 1, 'column_added', '{\"column_id\":473,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:37:57'),
(288, 1, 57, NULL, 1, 'column_added', '{\"column_id\":474,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:38:01'),
(289, 1, 57, NULL, 1, 'column_added', '{\"column_id\":475,\"name\":\"Checkbox\",\"type\":\"checkbox\"}', '102.22.121.190', '2026-02-18 15:38:07'),
(290, 1, 57, NULL, 1, 'column_added', '{\"column_id\":476,\"name\":\"Number\",\"type\":\"number\"}', '102.22.121.190', '2026-02-18 15:38:14'),
(291, 1, 57, NULL, 1, 'column_added', '{\"column_id\":477,\"name\":\"Date\",\"type\":\"date\"}', '102.22.121.190', '2026-02-18 15:38:42'),
(292, 1, 57, NULL, 1, 'column_added', '{\"column_id\":478,\"name\":\"Text\",\"type\":\"text\"}', '102.22.121.190', '2026-02-18 15:38:48'),
(293, 1, 57, NULL, 1, 'column_added', '{\"column_id\":479,\"name\":\"Payment Status\",\"type\":\"dropdown\"}', '102.22.121.190', '2026-02-18 15:39:15'),
(294, 1, 60, NULL, 3, 'column_added', '{\"column_id\":491,\"name\":\"Job Description\",\"type\":\"text\"}', '102.22.121.190', '2026-02-21 04:42:27'),
(295, 1, 60, NULL, 3, 'column_added', '{\"column_id\":492,\"name\":\"S 14\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:43:02'),
(296, 1, 60, NULL, 3, 'column_added', '{\"column_id\":493,\"name\":\"S 15\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:43:23'),
(297, 1, 60, NULL, 3, 'column_added', '{\"column_id\":494,\"name\":\"M 16\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:43:37'),
(298, 1, 60, NULL, 3, 'column_added', '{\"column_id\":495,\"name\":\"D 17\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:43:49'),
(299, 1, 60, NULL, 3, 'column_added', '{\"column_id\":496,\"name\":\"W 18\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:44:05'),
(300, 1, 60, NULL, 3, 'column_added', '{\"column_id\":497,\"name\":\"D 19\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:44:23'),
(301, 1, 60, NULL, 3, 'column_added', '{\"column_id\":498,\"name\":\"V 20\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:44:37'),
(302, 1, 60, NULL, 3, 'column_added', '{\"column_id\":499,\"name\":\"S 22\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:44:51'),
(303, 1, 60, NULL, 3, 'column_added', '{\"column_id\":500,\"name\":\"S 22\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:45:22'),
(304, 1, 60, NULL, 3, 'column_added', '{\"column_id\":501,\"name\":\"M 23\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:45:34'),
(305, 1, 60, NULL, 3, 'column_added', '{\"column_id\":502,\"name\":\"D 24\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:45:46'),
(306, 1, 60, NULL, 3, 'column_added', '{\"column_id\":503,\"name\":\"W 25\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:45:57'),
(307, 1, 60, NULL, 3, 'column_added', '{\"column_id\":504,\"name\":\"V25\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:49:11'),
(308, 1, 60, NULL, 3, 'column_added', '{\"column_id\":505,\"name\":\"S26\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:49:27'),
(309, 1, 60, NULL, 3, 'column_added', '{\"column_id\":506,\"name\":\"S27\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:49:39'),
(310, 1, 60, NULL, 3, 'column_added', '{\"column_id\":507,\"name\":\"TOTAL DAYS\",\"type\":\"formula\"}', '102.22.121.190', '2026-02-21 04:50:47'),
(311, 1, 60, NULL, 3, 'column_added', '{\"column_id\":508,\"name\":\"RATE\",\"type\":\"number\"}', '102.22.121.190', '2026-02-21 04:51:16'),
(312, 1, 60, NULL, 3, 'column_added', '{\"column_id\":509,\"name\":\"SALARY\",\"type\":\"formula\"}', '102.22.121.190', '2026-02-21 04:51:44'),
(313, 1, 60, NULL, 3, 'column_added', '{\"column_id\":510,\"name\":\"SA 14 Extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:32:42'),
(314, 1, 60, NULL, 3, 'column_added', '{\"column_id\":511,\"name\":\"SO 15 Extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:32:57'),
(315, 1, 60, NULL, 3, 'column_added', '{\"column_id\":512,\"name\":\"ma 16 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:33:12'),
(316, 1, 60, NULL, 3, 'column_added', '{\"column_id\":513,\"name\":\"di 17 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:33:26'),
(317, 1, 60, NULL, 3, 'column_added', '{\"column_id\":514,\"name\":\"wo 18 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:33:39'),
(318, 1, 60, NULL, 3, 'column_added', '{\"column_id\":515,\"name\":\"do 19 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:33:53'),
(319, 1, 60, NULL, 3, 'column_added', '{\"column_id\":516,\"name\":\"vr 20 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:34:16'),
(320, 1, 60, NULL, 3, 'column_added', '{\"column_id\":517,\"name\":\"sa 21 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:34:29'),
(321, 1, 60, NULL, 3, 'column_added', '{\"column_id\":518,\"name\":\"so 22 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:34:47'),
(322, 1, 60, NULL, 3, 'column_added', '{\"column_id\":519,\"name\":\"ma 23 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:35:01'),
(323, 1, 60, NULL, 3, 'column_added', '{\"column_id\":520,\"name\":\"di 24 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:35:13'),
(324, 1, 60, NULL, 3, 'column_added', '{\"column_id\":521,\"name\":\"wo 25 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:36:04'),
(325, 1, 60, NULL, 3, 'column_added', '{\"column_id\":522,\"name\":\"do 26 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:36:16'),
(326, 1, 60, NULL, 3, 'column_added', '{\"column_id\":523,\"name\":\"vr 27 extra\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:36:28'),
(327, 1, 60, NULL, 3, 'column_added', '{\"column_id\":524,\"name\":\"rate\",\"type\":\"number\"}', '102.22.121.190', '2026-02-22 13:37:39'),
(328, 1, 60, NULL, 3, 'column_added', '{\"column_id\":525,\"name\":\"total days\",\"type\":\"formula\"}', '102.22.121.190', '2026-02-22 13:38:49'),
(329, 1, 60, NULL, 3, 'column_added', '{\"column_id\":526,\"name\":\"Salary\",\"type\":\"formula\"}', '102.22.121.190', '2026-02-22 13:39:24');

-- --------------------------------------------------------

--
-- Table structure for table `board_automations`
--

CREATE TABLE `board_automations` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `trigger_type` varchar(50) NOT NULL,
  `trigger_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`trigger_config`)),
  `action_type` varchar(50) NOT NULL,
  `action_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`action_config`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_automation_logs`
--

CREATE TABLE `board_automation_logs` (
  `id` int(11) NOT NULL,
  `automation_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `executed_at` datetime DEFAULT current_timestamp(),
  `success` tinyint(1) DEFAULT NULL,
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_columns`
--

CREATE TABLE `board_columns` (
  `column_id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('status','people','date','timeline','text','longtext','number','dropdown','checkbox','tags','link','email','phone','formula','progress','priority','supplier','files') NOT NULL DEFAULT 'text',
  `config` text DEFAULT NULL,
  `supplier_id` int(10) DEFAULT NULL,
  `position` int(11) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `visible` tinyint(1) DEFAULT 1,
  `width` int(11) DEFAULT 150,
  `color` varchar(7) DEFAULT NULL,
  `required` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `board_columns`
--

INSERT INTO `board_columns` (`column_id`, `board_id`, `company_id`, `name`, `type`, `config`, `supplier_id`, `position`, `sort_order`, `visible`, `width`, `color`, `required`, `created_at`) VALUES
(1, 1, 1, 'Status', 'status', '{\"labels\":{\"Working on it\":\"#fdab3d\",\"Done\":\"#00c875\",\"Stuck\":\"#e2445c\"}}', NULL, 0, 0, 1, 150, NULL, 0, '2025-10-01 08:31:35'),
(2, 1, 1, 'Person', 'people', '{}', NULL, 1, 0, 1, 150, NULL, 0, '2025-10-01 08:31:35'),
(3, 1, 1, 'Due Date', 'date', '{}', NULL, 2, 0, 1, 150, NULL, 0, '2025-10-01 08:31:35'),
(4, 1, 1, 'Timeline', 'timeline', '{}', NULL, 3, 0, 1, 150, NULL, 0, '2025-10-01 08:31:35'),
(5, 1, 1, 'People', 'people', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2025-10-01 08:31:35'),
(6, 1, 1, 'Formula', 'formula', NULL, NULL, 5, 0, 1, 150, NULL, 0, '2025-10-01 08:31:35'),
(7, 1, 1, 'Total', 'number', NULL, NULL, 6, 0, 1, 150, NULL, 0, '2025-10-01 08:31:35'),
(8, 2, 1, 'Status', 'status', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-01 10:18:24'),
(9, 2, 1, 'People', 'people', NULL, NULL, 2, 0, 1, 150, NULL, 0, '2025-10-01 10:19:09'),
(10, 2, 1, 'Date', 'date', NULL, NULL, 3, 0, 1, 150, NULL, 0, '2025-10-01 10:19:52'),
(11, 2, 1, 'Timeline', 'timeline', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2025-10-01 10:20:21'),
(12, 2, 1, 'Total', 'number', NULL, NULL, 5, 0, 1, 150, NULL, 0, '2025-10-01 10:20:53'),
(13, 2, 1, 'Dropdown', 'dropdown', NULL, NULL, 6, 0, 1, 150, NULL, 0, '2025-10-01 10:31:33'),
(14, 2, 1, 'Text', 'text', NULL, NULL, 7, 0, 1, 150, NULL, 0, '2025-10-01 10:31:46'),
(15, 2, 1, 'Formula', 'formula', NULL, NULL, 8, 0, 1, 150, NULL, 0, '2025-10-01 10:32:00'),
(16, 2, 1, 'Checkbox', 'checkbox', NULL, NULL, 9, 0, 1, 150, NULL, 0, '2025-10-01 10:32:09'),
(17, 1, 1, 'Who Paid', 'dropdown', NULL, NULL, 7, 0, 1, 150, NULL, 0, '2025-10-01 17:30:37'),
(24, 1, 1, 'Timeline', 'timeline', NULL, NULL, 8, 0, 1, 150, NULL, 0, '2025-10-02 17:25:58'),
(25, 5, 1, 'Status', 'status', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2025-10-03 08:14:55'),
(26, 5, 1, 'Person', 'people', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-03 08:14:55'),
(27, 5, 1, 'Due Date', 'date', NULL, NULL, 2, 0, 1, 150, NULL, 0, '2025-10-03 08:14:55'),
(28, 5, 1, 'Priority', 'dropdown', NULL, NULL, 3, 0, 1, 150, NULL, 0, '2025-10-03 08:14:55'),
(29, 1, 1, 'People', 'people', NULL, NULL, 9, 0, 1, 150, NULL, 0, '2025-10-03 10:04:11'),
(30, 1, 1, 'Date', 'date', NULL, NULL, 10, 0, 1, 150, NULL, 0, '2025-10-03 10:04:14'),
(31, 1, 1, 'Status', 'status', NULL, NULL, 11, 0, 1, 150, NULL, 0, '2025-10-03 10:04:18'),
(32, 1, 1, 'Formula', 'formula', NULL, NULL, 12, 0, 1, 150, NULL, 0, '2025-10-03 11:45:09'),
(37, 6, 1, 'Total/unit', 'number', NULL, NULL, 6, 0, 0, 150, NULL, 0, '2025-10-03 16:59:42'),
(43, 6, 1, 'Actual Cost', 'number', '{\"format\":\"currency\",\"precision\":2,\"agg\":\"sum\"}', NULL, 3, 0, 1, 150, NULL, 0, '2025-10-07 22:02:03'),
(48, 8, 0, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-08 09:17:06'),
(49, 8, 0, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2025-10-08 09:17:06'),
(50, 8, 0, 'Status', 'status', '{}', NULL, 2, 0, 1, 120, NULL, 0, '2025-10-08 09:17:06'),
(51, 9, 0, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-08 09:17:06'),
(52, 9, 0, 'Qty', 'number', '{\"precision\": 2, \"agg\": \"sum\"}', NULL, 1, 0, 1, 100, NULL, 0, '2025-10-08 09:17:06'),
(53, 9, 0, 'Rate', 'number', '{\"precision\": 2, \"agg\": \"sum\"}', NULL, 2, 0, 1, 100, NULL, 0, '2025-10-08 09:17:06'),
(54, 9, 0, 'Total', 'formula', '{\"agg\": \"sum\", \"precision\": 2, \"formula\": \"[Qty]*[Rate]\"}', NULL, 3, 0, 1, 120, NULL, 0, '2025-10-08 09:17:07'),
(55, 10, 0, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-08 09:17:07'),
(56, 10, 0, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-08 09:17:07'),
(57, 10, 0, 'Qty', 'number', '{\"precision\": 0, \"agg\": \"sum\"}', NULL, 2, 0, 1, 100, NULL, 0, '2025-10-08 09:17:07'),
(58, 10, 0, 'Status', 'status', '{}', NULL, 3, 0, 1, 120, NULL, 0, '2025-10-08 09:17:07'),
(65, 6, 1, 'Timeline', 'timeline', NULL, NULL, 7, 0, 0, 150, NULL, 0, '2025-10-09 10:31:59'),
(83, 6, 1, 'date', 'date', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2025-10-09 15:54:18'),
(85, 6, 1, 'Supplier', 'text', NULL, NULL, 2, 0, 1, 150, NULL, 0, '2025-10-10 04:05:32'),
(145, 17, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-11 17:31:57'),
(146, 17, 1, 'Timeline', 'timeline', NULL, NULL, 3, 0, 1, 180, NULL, 0, '2025-10-11 17:31:57'),
(147, 17, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2025-10-11 17:31:57'),
(148, 18, 1, 'Order number', 'text', NULL, NULL, 1, 0, 1, 200, NULL, 0, '2025-10-11 17:31:57'),
(151, 18, 1, 'Total', 'formula', '{\"formula\":\"{Qty}  *  {Rate}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 7, 0, 1, 120, NULL, 0, '2025-10-11 17:31:57'),
(152, 19, 1, 'Order Number', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-11 17:31:57'),
(153, 19, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-11 17:31:57'),
(154, 19, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-10-11 17:31:57'),
(155, 19, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2025-10-11 17:31:57'),
(156, 19, 1, 'Supplier', 'supplier', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2025-10-11 17:33:27'),
(158, 18, 1, 'Progress', 'progress', NULL, NULL, 10, 0, 1, 150, NULL, 0, '2025-10-11 17:37:27'),
(159, 18, 1, 'Status', 'status', '{\"labels\":{\"To Do\":\"#64748b\",\"Working on it\":\"#fbbf24\",\"Done\":\"#22c55e\",\"Stuck\":\"#ef4444\"}}', NULL, 6, 0, 1, 150, NULL, 0, '2025-10-11 17:37:42'),
(160, 18, 1, 'Timeline', 'timeline', NULL, NULL, 11, 0, 1, 150, NULL, 0, '2025-10-11 17:37:57'),
(161, 18, 1, 'NOTAS', 'text', NULL, NULL, 12, 0, 1, 150, NULL, 0, '2025-10-11 17:38:21'),
(211, 6, 1, 'Invoice Number', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-14 12:33:21'),
(212, 6, 1, 'POP', 'files', NULL, NULL, 8, 0, 1, 150, NULL, 0, '2025-10-14 12:34:09'),
(214, 6, 1, 'Paid', 'status', '{\"labels\":{\"To Do\":\"#64748b\",\"Working on it\":\"#fbbf24\",\"Done\":\"#22c55e\",\"Stuck\":\"#ef4444\"}}', NULL, 4, 0, 1, 150, NULL, 0, '2025-10-14 13:23:40'),
(217, 23, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-16 09:34:08'),
(218, 23, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2025-10-16 09:34:08'),
(219, 23, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2025-10-16 09:34:08'),
(221, 24, 1, 'Actual cost', 'number', NULL, NULL, 1, 0, 1, 100, NULL, 0, '2025-10-16 09:34:08'),
(224, 25, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-16 09:34:08'),
(225, 25, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-16 09:34:08'),
(226, 25, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-10-16 09:34:08'),
(227, 25, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2025-10-16 09:34:08'),
(228, 24, 1, 'Status', 'status', '{\"labels\":{\"To Do\":\"#64748b\",\"Working on it\":\"#fbbf24\",\"Done\":\"#22c55e\",\"Stuck\":\"#ef4444\"}}', NULL, 5, 0, 1, 150, NULL, 0, '2025-10-16 09:36:03'),
(230, 24, 1, 'Supplier', 'text', NULL, NULL, 6, 0, 1, 150, NULL, 0, '2025-10-16 09:47:35'),
(231, 24, 1, 'Invoice Nr', 'text', NULL, NULL, 2, 0, 1, 150, NULL, 0, '2025-10-16 09:52:55'),
(232, 24, 1, '\'Order Nr', 'text', NULL, NULL, 3, 0, 1, 150, NULL, 0, '2025-10-16 09:53:16'),
(233, 24, 1, 'Date', 'date', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2025-10-16 09:53:42'),
(235, 24, 1, 'Payment Status', 'dropdown', '{\"options\":[\"Paid\",\"Not Paid\",\"Deposit paid\"]}', NULL, 7, 0, 1, 150, NULL, 0, '2025-10-16 09:57:17'),
(236, 17, 1, 'UNIT PRICE', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 4, 0, 1, 150, NULL, 0, '2025-10-18 11:59:42'),
(237, 17, 1, 'Formula', 'formula', '{\"formula\":\"{UNIT PRICE}  *  {1QUANTITY}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 6, 0, 1, 150, NULL, 0, '2025-10-18 12:01:49'),
(238, 17, 1, '1QUANTITY', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 5, 0, 1, 150, NULL, 0, '2025-10-18 12:02:28'),
(239, 26, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-18 14:28:32'),
(240, 26, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2025-10-18 14:28:32'),
(241, 26, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2025-10-18 14:28:32'),
(246, 28, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-18 14:28:32'),
(247, 28, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-18 14:28:32'),
(248, 28, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-10-18 14:28:32'),
(249, 28, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2025-10-18 14:28:32'),
(250, 26, 1, 'Unit price', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 5, 0, 1, 150, NULL, 0, '2025-10-18 15:03:04'),
(251, 26, 1, 'quantity', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 4, 0, 1, 150, NULL, 0, '2025-10-18 15:03:25'),
(253, 26, 1, 'Formula', 'formula', '{\"formula\":\"{Unit price}  *  {quantity}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 6, 0, 1, 150, NULL, 0, '2025-10-18 15:09:40'),
(254, 29, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-21 14:41:34'),
(255, 29, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2025-10-21 14:41:34'),
(256, 29, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2025-10-21 14:41:34'),
(261, 31, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-10-21 14:41:34'),
(262, 31, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-21 14:41:34'),
(263, 31, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-10-21 14:41:34'),
(264, 31, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2025-10-21 14:41:34'),
(265, 30, 1, 'Suppliers', 'text', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2025-10-21 14:45:22'),
(266, 30, 1, 'Actual Cost', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 0, 0, 1, 150, NULL, 0, '2025-10-21 14:45:52'),
(267, 30, 1, 'INvoice Number', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-21 14:46:13'),
(268, 30, 1, 'Order Number', 'text', NULL, NULL, 2, 0, 1, 150, NULL, 0, '2025-10-21 14:46:34'),
(270, 30, 1, 'Paid', 'dropdown', '{\"options\":[\"Paid\",\"Not Paid\",\"Deposite Paid\"]}', NULL, 5, 0, 1, 150, NULL, 0, '2025-10-21 14:47:44'),
(271, 30, 1, 'Date', 'date', NULL, NULL, 3, 0, 1, 150, NULL, 0, '2025-10-21 14:48:03'),
(272, 27, 1, '/Actual Cost', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 2, 0, 1, 150, NULL, 0, '2025-10-21 14:50:30'),
(273, 27, 1, 'invoice number', 'text', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2025-10-21 14:50:57'),
(274, 27, 1, 'order number', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-21 14:51:10'),
(275, 27, 1, 'Date', 'date', NULL, NULL, 3, 0, 1, 150, NULL, 0, '2025-10-21 14:51:35'),
(276, 27, 1, 'Supplier', 'text', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2025-10-21 14:51:50'),
(277, 27, 1, 'Paid', 'dropdown', '{\"options\":[\"Paid\",\"Not Paid\",\"Deposite Paid\"]}', NULL, 5, 0, 1, 150, NULL, 0, '2025-10-21 14:52:19'),
(278, 17, 1, 'Progress', 'progress', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-10-24 10:01:32'),
(280, 18, 1, 'Supplier', 'text', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2025-10-28 09:56:39'),
(281, 18, 1, 'Invoice Number', 'text', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2025-10-28 09:57:04'),
(282, 18, 1, 'Date', 'date', NULL, NULL, 3, 0, 1, 150, NULL, 0, '2025-10-28 09:57:51'),
(283, 18, 1, 'Paid', 'dropdown', '{\"options\":[\"Paid\",\"Not Paid\",\"Deposite Paid\"]}', NULL, 5, 0, 1, 150, NULL, 0, '2025-10-28 09:59:11'),
(284, 18, 1, 'Actual Cost', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 2, 0, 1, 150, NULL, 0, '2025-10-28 13:54:42'),
(286, 26, 1, 'Progress', 'progress', NULL, NULL, 2, 0, 1, 150, NULL, 0, '2025-10-29 07:06:22'),
(287, 32, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-12-04 09:33:42'),
(288, 32, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2025-12-04 09:33:43'),
(289, 32, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2025-12-04 09:33:43'),
(291, 33, 1, 'Qty', 'number', NULL, NULL, 1, 0, 1, 100, NULL, 0, '2025-12-04 09:33:43'),
(292, 33, 1, 'Rate', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-12-04 09:33:43'),
(293, 33, 1, 'Total', 'formula', '{\"formula\":\"{Qty}  *  {Rate}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 3, 0, 1, 120, NULL, 0, '2025-12-04 09:33:43'),
(294, 34, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-12-04 09:33:43'),
(295, 34, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-12-04 09:33:43'),
(296, 34, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-12-04 09:33:43'),
(297, 34, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2025-12-04 09:33:43'),
(298, 35, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-12-09 11:06:57'),
(299, 35, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2025-12-09 11:06:57'),
(300, 35, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2025-12-09 11:06:57'),
(302, 36, 1, 'Qty', 'number', NULL, NULL, 9, 0, 1, 100, NULL, 0, '2025-12-09 11:06:57'),
(303, 36, 1, 'Rate', 'number', NULL, NULL, 8, 0, 1, 100, NULL, 0, '2025-12-09 11:06:57'),
(304, 36, 1, 'Total', 'formula', '{\"agg\":\"sum\",\"precision\":2,\"formula\":\"[Qty]*[Rate]\"}', NULL, 7, 0, 1, 120, NULL, 0, '2025-12-09 11:06:57'),
(305, 37, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-12-09 11:06:57'),
(306, 37, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-12-09 11:06:57'),
(307, 37, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-12-09 11:06:57'),
(308, 37, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2025-12-09 11:06:57'),
(309, 36, 1, 'Invoice Number', 'text', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2025-12-15 06:10:33'),
(310, 36, 1, 'Actual Cost', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 2, 0, 1, 150, NULL, 0, '2025-12-15 06:11:10'),
(311, 36, 1, 'supplier', 'text', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2025-12-15 06:11:36'),
(313, 36, 1, 'Date', 'date', NULL, NULL, 3, 0, 1, 150, NULL, 0, '2025-12-15 06:12:03'),
(314, 36, 1, 'Paid', 'dropdown', '{\"options\":[\"Paid\",\"Deposite Paid\",\"Not paid\"]}', NULL, 5, 0, 1, 150, NULL, 0, '2025-12-15 06:12:37'),
(315, 36, 1, 'Payment Status', 'status', '{\"labels\":{\"To Do\":\"#64748b\",\"Working on it\":\"#fbbf24\",\"Done\":\"#22c55e\",\"Stuck\":\"#ef4444\"}}', NULL, 6, 0, 1, 150, NULL, 0, '2025-12-15 06:15:53'),
(316, 38, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-12-15 15:41:29'),
(318, 38, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2025-12-15 15:41:29'),
(319, 39, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-12-15 15:41:29'),
(320, 39, 1, 'Qty', 'number', NULL, NULL, 1, 0, 1, 100, NULL, 0, '2025-12-15 15:41:29'),
(321, 39, 1, 'Rate', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-12-15 15:41:29'),
(322, 39, 1, 'Total', 'formula', '{\"agg\":\"sum\",\"precision\":2,\"formula\":\"[Qty]*[Rate]\"}', NULL, 3, 0, 1, 120, NULL, 0, '2025-12-15 15:41:29'),
(323, 40, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2025-12-15 15:41:29'),
(324, 40, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-12-15 15:41:29'),
(325, 40, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2025-12-15 15:41:29'),
(326, 40, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2025-12-15 15:41:29'),
(327, 38, 1, 'Cost', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 3, 0, 1, 150, NULL, 0, '2025-12-15 15:42:29'),
(328, 36, 1, 'order number', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2025-12-16 14:23:37'),
(329, 41, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-01-14 17:53:15'),
(330, 41, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2026-01-14 17:53:15'),
(331, 41, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2026-01-14 17:53:15'),
(332, 42, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-01-14 17:53:15'),
(333, 42, 1, 'Qty', 'number', NULL, NULL, 1, 0, 1, 100, NULL, 0, '2026-01-14 17:53:15'),
(334, 42, 1, 'Rate', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2026-01-14 17:53:15'),
(335, 42, 1, 'Total', 'formula', '{\"formula\":\"{Qty}  *  {Rate}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 3, 0, 1, 120, NULL, 0, '2026-01-14 17:53:15'),
(336, 43, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-01-14 17:53:15'),
(337, 43, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2026-01-14 17:53:15'),
(338, 43, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2026-01-14 17:53:15'),
(339, 43, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2026-01-14 17:53:15'),
(340, 44, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-01-15 08:07:07'),
(341, 44, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2026-01-15 08:07:07'),
(342, 44, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2026-01-15 08:07:07'),
(343, 45, 1, 'Job Description', 'text', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2026-01-15 08:07:07'),
(344, 45, 1, 'MON 5', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 3, 0, 1, 30, NULL, 0, '2026-01-15 08:07:07'),
(345, 45, 1, 'Rate', 'number', NULL, NULL, 16, 0, 1, 100, NULL, 0, '2026-01-15 08:07:07'),
(346, 45, 1, 'TotAL DAYS', 'formula', '{\"formula\":\"{SAT 3}  +  {SUN 4}  +  {MON 5}  +  {THUE 6}  +  {WED 7}  +  {THUR 8}  +  {FRI 9}  +  {sat 10}  +  {sun 11}  +  {mon 12}  +  {thUE 13}  +  {wed 14}  +  {thuR 15}  +  {fri 16}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 15, 0, 1, 120, NULL, 0, '2026-01-15 08:07:07'),
(347, 46, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-01-15 08:07:07'),
(348, 46, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2026-01-15 08:07:07'),
(349, 46, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2026-01-15 08:07:07'),
(350, 46, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2026-01-15 08:07:07'),
(351, 45, 1, 'THUE 6', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 4, 0, 1, 30, NULL, 0, '2026-01-15 08:12:21'),
(352, 45, 1, 'WED 7', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 5, 0, 1, 30, NULL, 0, '2026-01-15 08:12:43'),
(354, 45, 1, 'Total', 'formula', '{\"formula\":\"{TotAL DAYS}  *  {Rate}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 17, 0, 1, 150, NULL, 0, '2026-01-15 11:47:33'),
(355, 45, 1, 'THUR 8', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 6, 0, 1, 150, NULL, 0, '2026-01-15 11:48:10'),
(356, 45, 1, 'FRI 9', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 7, 0, 1, 150, NULL, 0, '2026-01-15 11:49:07'),
(357, 45, 1, 'SAT 3', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 1, 0, 1, 150, NULL, 0, '2026-01-15 11:51:54'),
(359, 45, 1, 'sat 10', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 8, 0, 1, 150, NULL, 0, '2026-01-15 11:53:51'),
(360, 45, 1, 'sun 11', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 9, 0, 1, 150, NULL, 0, '2026-01-15 11:55:00'),
(361, 45, 1, 'mon 12', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 10, 0, 1, 150, NULL, 0, '2026-01-15 11:55:59'),
(362, 45, 1, 'thUE 13', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 11, 0, 1, 150, NULL, 0, '2026-01-15 11:56:11'),
(363, 45, 1, 'wed 14', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 12, 0, 1, 150, NULL, 0, '2026-01-15 11:56:24'),
(364, 45, 1, 'thuR 15', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 13, 0, 1, 150, NULL, 0, '2026-01-15 11:56:40'),
(365, 45, 1, 'fri 16', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 14, 0, 1, 150, NULL, 0, '2026-01-15 11:56:56'),
(366, 45, 1, 'SUN 4', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 2, 0, 1, 150, NULL, 0, '2026-01-15 13:13:42'),
(367, 47, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-01-19 05:14:20'),
(368, 47, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2026-01-19 05:14:20'),
(369, 47, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2026-01-19 05:14:20'),
(374, 49, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-01-19 05:14:20'),
(375, 49, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2026-01-19 05:14:20'),
(376, 49, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2026-01-19 05:14:20'),
(377, 49, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2026-01-19 05:14:20'),
(378, 48, 1, 'Sat 17', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 1, 0, 1, 150, NULL, 0, '2026-01-19 05:16:45'),
(379, 48, 1, 'Sun 18', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 2, 0, 1, 150, NULL, 0, '2026-01-19 05:17:00'),
(380, 48, 1, 'Mon 19', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 3, 0, 1, 150, NULL, 0, '2026-01-19 05:17:14'),
(381, 48, 1, 'Tue 20', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 4, 0, 1, 150, NULL, 0, '2026-01-19 05:17:37'),
(382, 48, 1, 'WEd 21', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 5, 0, 1, 150, NULL, 0, '2026-01-19 05:17:59'),
(383, 48, 1, 'THU 22', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 6, 0, 1, 150, NULL, 0, '2026-01-19 05:18:14'),
(384, 48, 1, 'FRI 23', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 7, 0, 1, 150, NULL, 0, '2026-01-19 05:18:34'),
(385, 48, 1, 'SAT 24', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 8, 0, 1, 150, NULL, 0, '2026-01-19 05:18:46'),
(386, 48, 1, 'SUN 25', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 9, 0, 1, 150, NULL, 0, '2026-01-19 05:18:58'),
(387, 48, 1, 'MON 26', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 10, 0, 1, 150, NULL, 0, '2026-01-19 05:19:10'),
(388, 48, 1, 'TUE 27', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 11, 0, 1, 150, NULL, 0, '2026-01-19 05:20:01'),
(389, 48, 1, 'WED 28', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 12, 0, 1, 150, NULL, 0, '2026-01-19 05:20:15'),
(390, 48, 1, 'THU 29', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 13, 0, 1, 150, NULL, 0, '2026-01-19 05:20:49'),
(391, 48, 1, 'FRI 30', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 14, 0, 1, 150, NULL, 0, '2026-01-19 05:21:02'),
(393, 48, 1, 'Rate', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 16, 0, 1, 150, NULL, 0, '2026-01-19 05:23:22'),
(395, 48, 1, 'JOB DESCRIPTION', 'text', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2026-01-19 05:30:06'),
(396, 48, 1, 'Total days', 'formula', '{\"formula\":\"{Sat 17}  +  {Sun 18}  +  {Mon 19}  +  {Tue 20}  +  {WEd 21}  +  {THU 22}  +  {FRI 23}  +  {SAT 24}  +  {SUN 25}  +  {MON 26}  +  {TUE 27}  +  {WED 28}  +  {THU 29}  +  {FRI 30}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 15, 0, 1, 150, NULL, 0, '2026-01-27 14:53:58'),
(397, 48, 1, 'Salary', 'formula', '{\"formula\":\"{Total days}  *  {Rate}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 17, 0, 1, 150, NULL, 0, '2026-01-27 14:55:01'),
(398, 50, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-02-02 06:43:44'),
(399, 50, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2026-02-02 06:43:44'),
(400, 50, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2026-02-02 06:43:44'),
(405, 52, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-02-02 06:43:44'),
(406, 52, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2026-02-02 06:43:44'),
(407, 52, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2026-02-02 06:43:44'),
(408, 52, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2026-02-02 06:43:44'),
(409, 51, 1, 'SAT 31', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 1, 0, 1, 150, NULL, 0, '2026-02-05 09:29:54'),
(410, 51, 1, 'Son 1', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 2, 0, 1, 150, NULL, 0, '2026-02-05 09:30:08'),
(411, 51, 1, 'Maan 2', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 3, 0, 1, 150, NULL, 0, '2026-02-05 09:30:22'),
(412, 51, 1, 'Dins 3', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 4, 0, 1, 150, NULL, 0, '2026-02-05 09:30:34'),
(413, 51, 1, 'woen 4', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 5, 0, 1, 150, NULL, 0, '2026-02-05 09:30:45'),
(414, 51, 1, 'dond 5', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 6, 0, 1, 150, NULL, 0, '2026-02-05 09:30:55'),
(415, 51, 1, 'vry 6', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 7, 0, 1, 150, NULL, 0, '2026-02-05 09:31:08'),
(416, 51, 1, 'sat 7', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 8, 0, 1, 150, NULL, 0, '2026-02-05 09:31:23'),
(417, 51, 1, 'son 8', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 9, 0, 1, 150, NULL, 0, '2026-02-05 09:31:34'),
(418, 51, 1, 'maan 9', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 10, 0, 1, 150, NULL, 0, '2026-02-05 09:31:48'),
(419, 51, 1, 'dins 10', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 11, 0, 1, 150, NULL, 0, '2026-02-05 09:32:02'),
(420, 51, 1, 'woen 11', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 12, 0, 1, 150, NULL, 0, '2026-02-05 09:32:18'),
(421, 51, 1, 'dond 12', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 13, 0, 1, 150, NULL, 0, '2026-02-05 09:32:31'),
(422, 51, 1, '13', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 14, 0, 1, 150, NULL, 0, '2026-02-05 09:32:44'),
(423, 51, 1, 'Total Days', 'formula', '{\"formula\":\"{Sat 31}  +  {Son 1}  +  {Maan 2}  +  {Dins 3}  +  {woen 4}  +  {dond 5}  +  {vry 6}  +  {sat 7}  +  {son 8}  +  {maan 9}  +  {dins 10}  +  {woen 11}  +  {dond 12}  +  {vry 13}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 15, 0, 1, 150, NULL, 0, '2026-02-05 09:35:29'),
(424, 51, 1, 'Job description', 'text', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2026-02-05 09:35:52'),
(425, 51, 1, 'Rate', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 16, 0, 1, 150, NULL, 0, '2026-02-05 09:38:52'),
(426, 51, 1, 'Salary', 'formula', '{\"formula\":\"{Total Days}  *  {Rate}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 17, 0, 1, 150, NULL, 0, '2026-02-05 09:39:38'),
(429, 53, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2026-02-13 10:04:50'),
(434, 55, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-02-13 10:04:50'),
(435, 55, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2026-02-13 10:04:50'),
(436, 55, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2026-02-13 10:04:50'),
(437, 55, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2026-02-13 10:04:50'),
(438, 53, 1, 'Quoted', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 3, 0, 1, 150, NULL, 0, '2026-02-13 10:06:15'),
(439, 53, 1, 'Projected Cost', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 4, 0, 1, 150, NULL, 0, '2026-02-13 10:06:32'),
(447, 58, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-02-13 10:32:00'),
(448, 58, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2026-02-13 10:32:00'),
(449, 58, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2026-02-13 10:32:00'),
(450, 58, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2026-02-13 10:32:00'),
(451, 56, 1, 'Status', 'status', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2026-02-13 10:33:12'),
(455, 56, 1, 'Quantity', 'number', '{\"agg\":\"sum\",\"precision\":0}', NULL, 1, 0, 1, 120, NULL, 0, '2026-02-13 10:33:12'),
(456, 56, 1, 'Price/Unit', 'number', '{\"agg\":\"avg\",\"precision\":2}', NULL, 2, 0, 1, 120, NULL, 0, '2026-02-13 10:33:12'),
(457, 56, 1, 'Total', 'formula', '{\"formula\":\"{Quantity} * {Price\\/Unit}\",\"agg\":\"sum\",\"precision\":2}', NULL, 3, 0, 1, 120, NULL, 0, '2026-02-13 10:33:12'),
(458, 56, 1, 'Notes', 'text', NULL, NULL, 5, 0, 1, 200, NULL, 0, '2026-02-13 10:33:12'),
(459, 56, 1, 'Quoted', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 4, 0, 1, 150, NULL, 0, '2026-02-13 10:34:25'),
(460, 54, 1, 'Invoice Number', 'text', NULL, NULL, 0, 0, 1, 150, NULL, 0, '2026-02-18 15:15:27'),
(461, 54, 1, 'total cost', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 2, 0, 1, 150, NULL, 0, '2026-02-18 15:15:42'),
(462, 54, 1, 'Date', 'date', NULL, NULL, 3, 0, 1, 150, NULL, 0, '2026-02-18 15:15:51'),
(463, 54, 1, 'order number', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2026-02-18 15:16:07'),
(464, 54, 1, 'supplier', 'text', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2026-02-18 15:16:13'),
(466, 54, 1, 'Payment Status', 'dropdown', '{\"options\":[\"Paid\",\"Deposit Paid\",\"Not Paid\"]}', NULL, 5, 0, 1, 150, NULL, 0, '2026-02-18 15:29:04'),
(473, 57, 1, 'Invoice Number', 'text', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2026-02-18 15:37:57'),
(474, 57, 1, 'order number', 'text', NULL, NULL, 5, 0, 1, 150, NULL, 0, '2026-02-18 15:38:01'),
(476, 57, 1, 'total cost', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 7, 0, 1, 150, NULL, 0, '2026-02-18 15:38:14'),
(477, 57, 1, 'Date', 'date', NULL, NULL, 8, 0, 1, 150, NULL, 0, '2026-02-18 15:38:42'),
(478, 57, 1, 'Text', 'text', NULL, NULL, 9, 0, 1, 150, NULL, 0, '2026-02-18 15:38:48'),
(479, 57, 1, 'Payment Status', 'dropdown', '{\"options\":[\"Paid\",\"Deposit Paid\",\"Not Paid\"]}', NULL, 10, 0, 1, 150, NULL, 0, '2026-02-18 15:39:15'),
(480, 59, 1, 'Task', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-02-21 04:40:39'),
(481, 59, 1, 'Timeline', 'timeline', NULL, NULL, 1, 0, 1, 180, NULL, 0, '2026-02-21 04:40:39'),
(482, 59, 1, 'Status', 'status', NULL, NULL, 2, 0, 1, 120, NULL, 0, '2026-02-21 04:40:39'),
(487, 61, 1, 'Item', 'text', NULL, NULL, 0, 0, 1, 200, NULL, 0, '2026-02-21 04:40:39'),
(488, 61, 1, 'Supplier', 'text', NULL, NULL, 1, 0, 1, 150, NULL, 0, '2026-02-21 04:40:39'),
(489, 61, 1, 'Qty', 'number', NULL, NULL, 2, 0, 1, 100, NULL, 0, '2026-02-21 04:40:39'),
(490, 61, 1, 'Status', 'status', NULL, NULL, 3, 0, 1, 120, NULL, 0, '2026-02-21 04:40:39'),
(491, 60, 1, 'Job Description', 'text', NULL, NULL, 4, 0, 1, 150, NULL, 0, '2026-02-21 04:42:26'),
(510, 60, 1, 'SA 14 Extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 5, 0, 1, 150, NULL, 0, '2026-02-22 13:32:42'),
(511, 60, 1, 'SO 15 Extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 6, 0, 1, 150, NULL, 0, '2026-02-22 13:32:57'),
(512, 60, 1, 'ma 16 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 7, 0, 1, 150, NULL, 0, '2026-02-22 13:33:12'),
(513, 60, 1, 'di 17 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 8, 0, 1, 150, NULL, 0, '2026-02-22 13:33:26'),
(514, 60, 1, 'wo 18 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 9, 0, 1, 150, NULL, 0, '2026-02-22 13:33:39'),
(515, 60, 1, 'do 19 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 10, 0, 1, 150, NULL, 0, '2026-02-22 13:33:53'),
(516, 60, 1, 'vr 20 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 11, 0, 1, 150, NULL, 0, '2026-02-22 13:34:16'),
(517, 60, 1, 'sa 21 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 12, 0, 1, 150, NULL, 0, '2026-02-22 13:34:29'),
(518, 60, 1, 'so 22 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 13, 0, 1, 150, NULL, 0, '2026-02-22 13:34:47'),
(519, 60, 1, 'ma 23 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 14, 0, 1, 150, NULL, 0, '2026-02-22 13:35:01'),
(520, 60, 1, 'di 24 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 15, 0, 1, 150, NULL, 0, '2026-02-22 13:35:13'),
(521, 60, 1, 'wo 25 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 16, 0, 1, 150, NULL, 0, '2026-02-22 13:36:04'),
(522, 60, 1, 'do 26 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 17, 0, 1, 150, NULL, 0, '2026-02-22 13:36:16'),
(523, 60, 1, 'vr 27 extra', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 18, 0, 1, 150, NULL, 0, '2026-02-22 13:36:28'),
(524, 60, 1, 'rate', 'number', '{\"format\":\"decimal\",\"precision\":2,\"agg\":\"sum\"}', NULL, 19, 0, 1, 150, NULL, 0, '2026-02-22 13:37:39'),
(525, 60, 1, 'total days', 'formula', '{\"formula\":\"{SA 14 Extra}  +  {SO 15 Extra}  +  {ma 16 extra}  +  {di 17 extra}  +  {wo 18 extra}  +  {do 19 extra}  +  {vr 20 extra}  +  {sa 21 extra}  +  {so 22 extra}  +  {ma 23 extra}  +  {di 24 extra}  +  {wo 25 extra}  +  {do 26 extra}  +  {vr 27 extra}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 20, 0, 1, 150, NULL, 0, '2026-02-22 13:38:49'),
(526, 60, 1, 'Salary', 'formula', '{\"formula\":\"{rate}  *  {total days}\",\"precision\":2,\"agg\":\"sum\"}', NULL, 21, 0, 1, 150, NULL, 0, '2026-02-22 13:39:24');

-- --------------------------------------------------------

--
-- Table structure for table `board_column_settings`
--

CREATE TABLE `board_column_settings` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `hidden_columns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hidden_columns`)),
  `column_order` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`column_order`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_dependencies`
--

CREATE TABLE `board_dependencies` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `predecessor_item` int(11) NOT NULL,
  `successor_item` int(11) NOT NULL,
  `dependency_type` enum('FS','SS','FF','SF') DEFAULT 'FS',
  `lag_days` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_groups`
--

CREATE TABLE `board_groups` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT 'New Group',
  `position` int(11) DEFAULT 0,
  `is_locked` tinyint(1) DEFAULT 0,
  `collapsed` tinyint(1) DEFAULT 0,
  `color` varchar(7) DEFAULT '#8b5cf6',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `board_groups`
--

INSERT INTO `board_groups` (`id`, `board_id`, `name`, `position`, `is_locked`, `collapsed`, `color`, `created_at`) VALUES
(18, 6, 'Expenses', 0, 0, 0, '#ec4899', '2025-10-03 16:13:06'),
(84, 17, 'Make site Safe', 0, 0, 0, '#8b5cf6', '2025-10-11 17:31:57'),
(85, 17, 'Old Showroom', 3, 0, 0, '#10b981', '2025-10-11 17:31:57'),
(86, 17, 'Miscellaneous', 4, 0, 0, '#ec4899', '2025-10-11 17:31:57'),
(87, 18, 'Prelininaries', 0, 0, 0, '#8b5cf6', '2025-10-11 17:31:57'),
(88, 18, 'Vechile Expenses', 1, 0, 1, '#3b82f6', '2025-10-11 17:31:57'),
(89, 18, 'Wages', 2, 0, 1, '#10b981', '2025-10-11 17:31:57'),
(90, 19, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-10-11 17:31:57'),
(91, 19, 'Phase 2', 1, 0, 0, '#10b981', '2025-10-11 17:31:57'),
(92, 19, 'Phase 3', 2, 0, 0, '#ef4444', '2025-10-11 17:31:57'),
(105, 23, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-10-16 09:34:08'),
(106, 23, 'Phase 2', 1, 0, 0, '#10b981', '2025-10-16 09:34:08'),
(107, 23, 'Phase 3', 2, 0, 0, '#ef4444', '2025-10-16 09:34:08'),
(108, 24, 'Prelimenaries', 0, 0, 1, '#8b5cf6', '2025-10-16 09:34:08'),
(109, 24, 'Earthworks & Excavations', 1, 0, 1, '#10b981', '2025-10-16 09:34:08'),
(110, 24, 'Foundation & Slab', 2, 0, 1, '#ef4444', '2025-10-16 09:34:08'),
(111, 25, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-10-16 09:34:08'),
(112, 25, 'Phase 2', 1, 0, 0, '#10b981', '2025-10-16 09:34:08'),
(113, 25, 'Phase 3', 2, 0, 0, '#ef4444', '2025-10-16 09:34:08'),
(114, 24, 'Ground Floor Masonry/Brickwork', 3, 0, 1, '#8b5cf6', '2025-10-17 11:45:01'),
(115, 24, 'Slab', 4, 0, 1, '#10b981', '2025-10-17 13:02:45'),
(116, 17, 'New Double story corner area', 1, 0, 0, '#10b981', '2025-10-18 12:29:18'),
(117, 17, 'New Showroom (Workshop Area)', 2, 0, 0, '#f59e0b', '2025-10-18 12:41:54'),
(118, 17, 'Paintwork', 5, 0, 0, '#8b5cf6', '2025-10-18 13:48:25'),
(119, 26, 'Quote: CON01912', 0, 0, 0, '#3b82f6', '2025-10-18 14:28:32'),
(120, 26, 'Quote: CON01914', 1, 0, 0, '#ec4899', '2025-10-18 14:28:32'),
(122, 27, 'Preliminaries', 0, 0, 1, '#8b5cf6', '2025-10-18 14:28:32'),
(123, 27, 'Vechile Expenses', 1, 0, 1, '#3b82f6', '2025-10-18 14:28:32'),
(124, 27, 'Rivonia + Water Edge', 2, 0, 1, '#10b981', '2025-10-18 14:28:32'),
(125, 28, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-10-18 14:28:32'),
(126, 28, 'Phase 2', 1, 0, 0, '#10b981', '2025-10-18 14:28:32'),
(127, 28, 'Phase 3', 2, 0, 0, '#ef4444', '2025-10-18 14:28:32'),
(128, 24, 'First Floor Masonry/ Brickforce', 5, 0, 1, '#f97316', '2025-10-18 15:39:41'),
(129, 24, 'Roofing', 6, 0, 1, '#ec4899', '2025-10-18 16:44:19'),
(130, 24, 'Carpentry & Iornmongery', 7, 0, 1, '#3b82f6', '2025-10-18 16:48:12'),
(131, 24, 'Ceilings & Cornice', 8, 0, 1, '#6b7280', '2025-10-18 16:50:09'),
(132, 24, 'Joinery', 9, 0, 1, '#6366f1', '2025-10-18 16:53:23'),
(133, 24, 'Iornmongery', 10, 0, 1, '#10b981', '2025-10-18 16:53:39'),
(134, 24, 'Metalwork (structual columns)', 11, 0, 1, '#ef4444', '2025-10-18 16:54:38'),
(135, 24, 'Plastering & Screeds', 12, 0, 1, '#8b5cf6', '2025-10-18 17:04:34'),
(136, 24, 'Plumbing', 13, 0, 1, '#ec4899', '2025-10-18 17:31:55'),
(137, 24, 'Electrical installation', 14, 0, 1, '#3b82f6', '2025-10-18 17:44:37'),
(138, 24, 'Gutters', 15, 0, 1, '#10b981', '2025-10-18 17:49:22'),
(139, 24, '|Aluminium/ doors/ windows/ Balustrades', 16, 0, 1, '#6366f1', '2025-10-18 17:52:39'),
(140, 24, 'Finishes (Floors & walls Multi-skim)', 17, 0, 1, '#ef4444', '2025-10-18 18:05:48'),
(141, 24, 'Painting', 18, 0, 1, '#f59e0b', '2025-10-18 18:08:47'),
(142, 24, 'Gas Reticulation', 19, 0, 1, '#6b7280', '2025-10-18 18:13:03'),
(143, 24, 'External Surfaces', 20, 0, 1, '#3b82f6', '2025-10-18 18:13:24'),
(144, 24, 'Swimming Pool', 21, 0, 1, '#ec4899', '2025-10-18 18:13:37'),
(145, 24, 'Provisional sums (Sanitaries)', 22, 0, 1, '#8b5cf6', '2025-10-18 18:14:06'),
(146, 24, 'Cleaning', 23, 0, 1, '#14b8a6', '2025-10-18 18:16:07'),
(147, 24, 'Storm water', 24, 0, 1, '#6b7280', '2025-10-18 18:16:26'),
(148, 24, 'Labour', 25, 0, 1, '#ef4444', '2025-10-19 10:03:39'),
(149, 24, 'Adriaan Salary', 26, 0, 1, '#10b981', '2025-10-19 12:05:32'),
(150, 6, '50 x 5 Flat Bar x 60m/ 16 Round Bar x 6.0 M/ ect', 1, 0, 0, '#8b5cf6', '2025-10-20 14:01:02'),
(151, 29, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-10-21 14:41:34'),
(152, 29, 'Phase 2', 1, 0, 0, '#10b981', '2025-10-21 14:41:34'),
(153, 29, 'Phase 3', 2, 0, 0, '#ef4444', '2025-10-21 14:41:34'),
(154, 30, 'Prelimenaries', 0, 0, 1, '#ec4899', '2025-10-21 14:41:34'),
(155, 30, 'Phase 2', 1, 0, 0, '#3b82f6', '2025-10-21 14:41:34'),
(156, 30, 'Phase 3', 2, 0, 0, '#ef4444', '2025-10-21 14:41:34'),
(157, 31, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-10-21 14:41:34'),
(158, 31, 'Phase 2', 1, 0, 0, '#10b981', '2025-10-21 14:41:34'),
(159, 31, 'Phase 3', 2, 0, 0, '#ef4444', '2025-10-21 14:41:34'),
(160, 24, 'Property Cost', 27, 0, 1, '#f97316', '2025-10-22 13:07:32'),
(161, 18, 'Floats', 3, 0, 1, '#f59e0b', '2025-11-07 07:27:39'),
(162, 18, 'Winston', 4, 0, 1, '#f97316', '2025-11-10 15:44:45'),
(163, 18, 'Rivonia + Water Edge', 5, 0, 1, '#ef4444', '2025-11-12 12:46:09'),
(164, 27, 'Winston', 3, 0, 1, '#f59e0b', '2025-11-18 08:36:09'),
(165, 18, 'Stanley', 6, 0, 1, '#ec4899', '2025-11-21 04:34:42'),
(166, 32, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-12-04 09:33:43'),
(167, 32, 'Phase 2', 1, 0, 0, '#10b981', '2025-12-04 09:33:43'),
(168, 32, 'Phase 3', 2, 0, 0, '#ef4444', '2025-12-04 09:33:43'),
(169, 33, 'Lone Einde Jan', 0, 0, 0, '#8b5cf6', '2025-12-04 09:33:43'),
(170, 33, 'Aankope nog die jaar Rivonia', 1, 0, 0, '#10b981', '2025-12-04 09:33:43'),
(171, 33, 'Aankope nog die jaar Water Edge', 2, 0, 0, '#ef4444', '2025-12-04 09:33:43'),
(172, 34, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-12-04 09:33:43'),
(173, 34, 'Phase 2', 1, 0, 0, '#10b981', '2025-12-04 09:33:43'),
(174, 34, 'Phase 3', 2, 0, 0, '#ef4444', '2025-12-04 09:33:43'),
(175, 33, 'EXTRAS', 3, 0, 0, '#8b5cf6', '2025-12-04 09:45:32'),
(176, 18, 'Extras', 7, 0, 1, '#8b5cf6', '2025-12-09 10:59:41'),
(177, 35, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-12-09 11:06:57'),
(178, 35, 'Phase 2', 1, 0, 0, '#10b981', '2025-12-09 11:06:57'),
(179, 35, 'Phase 3', 2, 0, 0, '#ef4444', '2025-12-09 11:06:57'),
(180, 36, 'Prelimenaries', 0, 0, 1, '#3b82f6', '2025-12-09 11:06:57'),
(181, 36, 'Wages', 1, 0, 1, '#3b82f6', '2025-12-09 11:06:57'),
(182, 36, 'Phase 3', 2, 0, 1, '#3b82f6', '2025-12-09 11:06:57'),
(183, 37, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-12-09 11:06:57'),
(184, 37, 'Phase 2', 1, 0, 0, '#10b981', '2025-12-09 11:06:57'),
(185, 37, 'Phase 3', 2, 0, 0, '#ef4444', '2025-12-09 11:06:57'),
(186, 38, 'Electrical and Sanitries', 0, 0, 0, '#8b5cf6', '2025-12-15 15:41:29'),
(187, 38, 'Building and Plaster', 1, 0, 0, '#10b981', '2025-12-15 15:41:29'),
(188, 38, 'Cupboards', 2, 0, 0, '#ef4444', '2025-12-15 15:41:29'),
(189, 39, 'Unit 1 3 Bedroom', 0, 0, 0, '#8b5cf6', '2025-12-15 15:41:29'),
(190, 39, 'Unit 2 2 bedroom', 1, 0, 0, '#10b981', '2025-12-15 15:41:29'),
(191, 39, 'Unit 3 3 bedroom', 2, 0, 0, '#ef4444', '2025-12-15 15:41:29'),
(192, 40, 'Phase 1', 0, 0, 0, '#8b5cf6', '2025-12-15 15:41:29'),
(193, 40, 'Phase 2', 1, 0, 0, '#10b981', '2025-12-15 15:41:29'),
(194, 40, 'Phase 3', 2, 0, 0, '#ef4444', '2025-12-15 15:41:29'),
(195, 38, 'Painting', 3, 0, 0, '#8b5cf6', '2025-12-15 15:46:19'),
(196, 38, 'Skirtings and loabour on doors', 4, 0, 0, '#8b5cf6', '2025-12-15 15:47:00'),
(197, 27, 'Wages', 4, 0, 1, '#ef4444', '2026-01-13 07:45:33'),
(198, 41, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-01-14 17:53:15'),
(199, 41, 'Phase 2', 1, 0, 0, '#10b981', '2026-01-14 17:53:15'),
(200, 41, 'Phase 3', 2, 0, 0, '#ef4444', '2026-01-14 17:53:15'),
(201, 42, 'AMAROK', 0, 0, 0, '#8b5cf6', '2026-01-14 17:53:15'),
(202, 42, 'Phase 2', 1, 0, 0, '#10b981', '2026-01-14 17:53:15'),
(203, 42, 'Phase 3', 2, 0, 0, '#ef4444', '2026-01-14 17:53:15'),
(204, 43, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-01-14 17:53:15'),
(205, 43, 'Phase 2', 1, 0, 0, '#10b981', '2026-01-14 17:53:15'),
(206, 43, 'Phase 3', 2, 0, 0, '#ef4444', '2026-01-14 17:53:15'),
(207, 44, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-01-15 08:07:07'),
(208, 44, 'Phase 2', 1, 0, 0, '#10b981', '2026-01-15 08:07:07'),
(209, 44, 'Phase 3', 2, 0, 0, '#ef4444', '2026-01-15 08:07:07'),
(210, 45, 'Rivonia (1) 01/05 - 01/16', 0, 0, 0, '#8b5cf6', '2026-01-15 08:07:07'),
(211, 45, 'Waters Edge 01/05 - 01/16', 1, 0, 0, '#3b82f6', '2026-01-15 08:07:07'),
(213, 46, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-01-15 08:07:07'),
(214, 46, 'Phase 2', 1, 0, 0, '#10b981', '2026-01-15 08:07:07'),
(215, 46, 'Phase 3', 2, 0, 0, '#ef4444', '2026-01-15 08:07:07'),
(216, 45, 'plot 180  01/05 - 01/16', 2, 0, 0, '#10b981', '2026-01-15 12:56:31'),
(217, 45, 'Grandiceps', 3, 0, 1, '#f59e0b', '2026-01-15 13:06:29'),
(219, 24, 'Wages', 28, 0, 1, '#8b5cf6', '2026-01-16 14:23:19'),
(220, 33, 'Inkomste', 4, 0, 0, '#8b5cf6', '2026-01-17 09:19:05'),
(221, 33, 'Na die tyd Uitgawes', 5, 0, 0, '#8b5cf6', '2026-01-17 09:24:08'),
(222, 47, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-01-19 05:14:20'),
(223, 47, 'Phase 2', 1, 0, 0, '#10b981', '2026-01-19 05:14:20'),
(224, 47, 'Phase 3', 2, 0, 0, '#ef4444', '2026-01-19 05:14:20'),
(225, 48, 'Rivonia', 0, 0, 0, '#8b5cf6', '2026-01-19 05:14:20'),
(226, 48, 'Water Edge', 1, 0, 0, '#3b82f6', '2026-01-19 05:14:20'),
(227, 48, 'Plot 180', 2, 0, 0, '#10b981', '2026-01-19 05:14:20'),
(228, 49, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-01-19 05:14:20'),
(229, 49, 'Phase 2', 1, 0, 0, '#10b981', '2026-01-19 05:14:20'),
(230, 49, 'Phase 3', 2, 0, 0, '#ef4444', '2026-01-19 05:14:20'),
(231, 50, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-02-02 06:43:44'),
(232, 50, 'Phase 2', 1, 0, 0, '#10b981', '2026-02-02 06:43:44'),
(233, 50, 'Phase 3', 2, 0, 0, '#ef4444', '2026-02-02 06:43:44'),
(237, 52, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-02-02 06:43:44'),
(238, 52, 'Phase 2', 1, 0, 0, '#10b981', '2026-02-02 06:43:44'),
(239, 52, 'Phase 3', 2, 0, 0, '#ef4444', '2026-02-02 06:43:44'),
(241, 51, 'Rivonia', 1, 0, 0, '#8b5cf6', '2026-02-05 09:37:33'),
(242, 51, 'Waters Edge', 2, 0, 0, '#8b5cf6', '2026-02-05 10:00:50'),
(243, 51, 'Plot', 3, 0, 0, '#8b5cf6', '2026-02-05 10:12:44'),
(244, 27, 'Glass', 5, 0, 1, '#ec4899', '2026-02-11 06:55:33'),
(245, 53, 'Rehbilitate, level area and remove all rubble', 0, 0, 0, '#8b5cf6', '2026-02-13 10:04:50'),
(246, 53, 'Paving Leveling and Laying', 1, 0, 0, '#10b981', '2026-02-13 10:04:50'),
(247, 53, 'Crusher 13mm 1900m 50mm thick', 2, 0, 0, '#ef4444', '2026-02-13 10:04:50'),
(248, 54, 'Prelimenaries', 0, 0, 1, '#8b5cf6', '2026-02-13 10:04:50'),
(249, 54, 'Vechile Expenses', 1, 0, 1, '#3b82f6', '2026-02-13 10:04:50'),
(250, 54, 'Wages', 2, 0, 1, '#10b981', '2026-02-13 10:04:50'),
(251, 55, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-02-13 10:04:50'),
(252, 55, 'Phase 2', 1, 0, 0, '#10b981', '2026-02-13 10:04:50'),
(253, 55, 'Phase 3', 2, 0, 0, '#ef4444', '2026-02-13 10:04:50'),
(254, 53, 'Palasades', 3, 0, 0, '#8b5cf6', '2026-02-13 10:12:00'),
(255, 56, 'Install isoboard ceilings 30mm', 0, 0, 0, '#8b5cf6', '2026-02-13 10:32:00'),
(256, 56, 'Paint Ceilings', 1, 0, 0, '#10b981', '2026-02-13 10:32:00'),
(258, 57, 'Prelimenaries', 0, 0, 1, '#8b5cf6', '2026-02-13 10:32:00'),
(259, 57, 'Vechile Expenses', 1, 0, 1, '#3b82f6', '2026-02-13 10:32:00'),
(260, 57, 'Wages', 2, 0, 1, '#10b981', '2026-02-13 10:32:00'),
(261, 58, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-02-13 10:32:00'),
(262, 58, 'Phase 2', 1, 0, 0, '#10b981', '2026-02-13 10:32:00'),
(263, 58, 'Phase 3', 2, 0, 0, '#ef4444', '2026-02-13 10:32:00'),
(264, 59, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-02-21 04:40:39'),
(265, 59, 'Phase 2', 1, 0, 0, '#10b981', '2026-02-21 04:40:39'),
(266, 59, 'Phase 3', 2, 0, 0, '#ef4444', '2026-02-21 04:40:39'),
(267, 60, 'Rivonia', 0, 0, 0, '#8b5cf6', '2026-02-21 04:40:39'),
(268, 60, 'Waters Edge', 1, 0, 0, '#3b82f6', '2026-02-21 04:40:39'),
(269, 60, 'Plot', 2, 0, 1, '#10b981', '2026-02-21 04:40:39'),
(270, 61, 'Phase 1', 0, 0, 0, '#8b5cf6', '2026-02-21 04:40:39'),
(271, 61, 'Phase 2', 1, 0, 0, '#10b981', '2026-02-21 04:40:39'),
(272, 61, 'Phase 3', 2, 0, 0, '#ef4444', '2026-02-21 04:40:39');

-- --------------------------------------------------------

--
-- Table structure for table `board_guests`
--

CREATE TABLE `board_guests` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `password_set_at` datetime DEFAULT NULL,
  `status` enum('pending','active','expired') DEFAULT 'pending',
  `invited_by` int(11) NOT NULL,
  `invited_at` datetime NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `last_access_at` datetime DEFAULT NULL,
  `access_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `board_guests`
--

INSERT INTO `board_guests` (`id`, `board_id`, `company_id`, `email`, `token`, `password_hash`, `password_set_at`, `status`, `invited_by`, `invited_at`, `confirmed_at`, `expires_at`, `last_access_at`, `access_count`) VALUES
(4, 26, 1, 'jyahyamcd@yahoo.com', 'e1e15d3309e24ab3bc47a5b422008b85ecfd0031b28fc141adfcc62b532566b8', '$2y$12$5ouX/5yP0ZV1JGOjO0H17uh7yHi7z5jnCHELTZm8jSQ7KRLDDbqPm', '2025-11-22 01:06:39', 'active', 1, '2025-11-21 23:26:45', '2025-11-22 01:06:39', '2025-12-21 23:26:45', '2025-12-19 09:12:23', 9),
(5, 26, 1, 'rbarends03@gmail.com', '7f76d824b53273ff8fa0abc9fa79bfc900a0a8258f0c08d649f3d09cb875fa67', NULL, NULL, 'pending', 1, '2025-11-03 13:46:11', NULL, '2025-12-03 13:46:11', NULL, 0),
(6, 26, 1, 'john@ppma.co.za', 'b7ada2eb3c730fcde0d3a940131678187c120b81becbe4bcdd2cc090df805cbc', '$2y$10$CEaLWrQCnxKQhyV7NLUpHetmlY0aIYnQXj8reL/4SaFrUhC4.wmqe', '2025-11-04 08:41:55', 'active', 1, '2025-11-03 16:56:30', '2025-11-04 08:41:55', '2025-12-03 16:56:30', '2025-11-04 08:41:56', 1),
(7, 57, 1, 'kitchingrm@gmail.com', 'bc87d4aec183f9f1ae11e4a16ad5721e77e3ac6caafc9c5efc80f459fded7a98', NULL, NULL, 'pending', 1, '2026-02-18 18:03:00', NULL, '2026-03-20 18:03:00', NULL, 0),
(8, 56, 1, 'kitchingrm@gmail.com', 'a533135730e743617b85a9116eece4acbd7bfd7d93473da535929267bd430528', NULL, NULL, 'pending', 1, '2026-02-18 18:03:27', NULL, '2026-03-20 18:03:27', NULL, 0),
(9, 53, 1, 'kitchingrm@gmail.com', '16b0f1724c64ff5f1088812470fa5849a63e2c4f781085d54be5b579a0dd6b6e', NULL, NULL, 'pending', 1, '2026-02-18 18:03:52', NULL, '2026-03-20 18:03:52', NULL, 0),
(10, 54, 1, 'kitchingrm@gmail.com', '7b748aa1ddb73b5ae45ebc974b15b6a286f397e5ba088e7023740754bb80f404', NULL, NULL, 'pending', 1, '2026-02-18 18:04:07', NULL, '2026-03-20 18:04:07', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `board_guest_activity`
--

CREATE TABLE `board_guest_activity` (
  `id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `action` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `timestamp` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_items`
--

CREATE TABLE `board_items` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL DEFAULT 'New Item',
  `description` text DEFAULT NULL,
  `position` int(11) DEFAULT 0,
  `status_label` varchar(100) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `progress` int(11) DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `archived` tinyint(1) DEFAULT 0,
  `subitem_count` int(11) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `board_items`
--

INSERT INTO `board_items` (`id`, `board_id`, `company_id`, `group_id`, `title`, `description`, `position`, `status_label`, `assigned_to`, `priority`, `progress`, `due_date`, `start_date`, `end_date`, `tags`, `archived`, `subitem_count`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 1, 'New Item', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-09-30 18:55:27', '2025-10-01 08:13:35'),
(2, 2, 1, 1, 'New Item', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-09-30 18:55:37', '2025-09-30 18:56:10'),
(3, 2, 1, 2, 'New Itewedfdedfm', NULL, 3, 'Done', NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-09-30 18:58:53', '2025-10-01 10:18:45'),
(4, 2, 1, 2, 'New Itemwedwedw', NULL, 1, 'Stuck', 1, 'medium', 0, '2025-10-02', '2025-10-02', '2025-10-31', NULL, 0, 0, 1, '2025-09-30 19:20:04', '2025-10-02 19:03:20'),
(5, 1, 1, 5, 'Newqwdqdqwd Item', NULL, 5, 'wqdqw', 1, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-09-30 19:23:04', '2025-10-01 08:13:35'),
(6, 1, 1, 5, 'New Item', NULL, 1, 'Stuck', 1, 'medium', 0, '2025-10-17', '2025-10-10', '2025-10-24', NULL, 0, 0, 1, '2025-09-30 19:24:11', '2025-10-03 12:15:49'),
(7, 1, 1, 5, 'New Item', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-01 07:50:32', '2025-10-01 07:50:32'),
(8, 1, 1, 5, 'New Item', NULL, 3, 'Done', NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-01 07:50:34', '2025-10-01 12:36:51'),
(10, 2, 1, 1, 'New Item', NULL, 2, 'Working on it', 1, 'medium', 0, '2025-10-23', '2025-10-09', '2025-10-24', NULL, 0, 0, 1, '2025-10-01 10:19:21', '2025-10-01 10:36:43'),
(12, 2, 1, 2, 'Hello', NULL, 2, 'To Do', NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-01 10:33:13', '2025-10-03 08:30:35'),
(13, 1, 1, 6, 'concrete', NULL, 1, 'Waiting', NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-01 17:29:41', '2025-10-02 17:25:02'),
(16, 1, 1, 9, 'New Item', NULL, 1, 'Working on it', NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-03 11:45:18', '2025-10-03 11:45:18'),
(17, 1, 1, 5, 'ORDER 19920192Item', NULL, 6, 'Working on it', NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-03 11:48:08', '2025-10-03 11:48:44'),
(18, 1, 1, 8, 'New Item', NULL, 1, 'Working on it', NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 2, '2025-10-03 12:43:47', '2025-10-03 12:43:57'),
(23, 6, 1, 18, 'sadad', NULL, 3, 'todo', 1, 'medium', 0, '2025-10-08', NULL, NULL, NULL, 1, 0, 1, '2025-10-07 12:30:42', '2025-10-14 12:29:27'),
(27, 6, 1, 18, 'sdfsf', NULL, 1, 'todo', NULL, 'medium', 0, '2025-10-23', NULL, NULL, NULL, 1, 0, 1, '2025-10-07 14:35:49', '2025-10-14 12:29:27'),
(29, 6, 1, 18, 'fdvd', NULL, 2, NULL, 1, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-07 16:51:21', '2025-10-14 12:29:27'),
(30, 6, 1, 18, 'dfsg', NULL, 4, 'done', NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-07 18:02:53', '2025-10-12 10:57:04'),
(32, 6, 1, 18, 'a', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-07 19:32:47', '2025-10-12 10:57:04'),
(35, 6, 1, 18, 'sadasasad', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-07 22:00:17', '2025-10-12 10:57:04'),
(38, 6, 1, 18, 'aassaa', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-08 07:38:00', '2025-10-14 12:29:27'),
(39, 6, 1, 18, 'a', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-08 09:01:44', '2025-10-12 10:55:20'),
(40, 6, 1, 18, 'a', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-08 09:42:18', '2025-10-12 10:55:20'),
(89, 19, 1, 90, 'Cement', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-11 17:32:54', '2025-10-11 17:34:09'),
(90, 18, 1, 87, 'Cement', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-11 17:35:25', '2025-10-30 12:20:48'),
(91, 17, 1, 84, 'aaa', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-11 17:57:58', '2025-10-18 11:56:56'),
(92, 19, 1, 90, 'Cement (Copy)', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-11 19:07:13', '2025-10-11 19:07:13'),
(93, 6, 1, 18, 'aaaa (Copy)', NULL, 10, 'stuck', 1, 'medium', 0, '2025-10-30', NULL, NULL, NULL, 1, 0, 1, '2025-10-11 21:14:48', '2025-10-12 10:57:04'),
(94, 6, 1, 18, 'ssasd', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-12 10:42:40', '2025-10-12 10:57:04'),
(95, 6, 1, 18, 'sdfsf (Copy)', NULL, 12, 'stuck', NULL, 'medium', 0, '2025-10-23', NULL, NULL, NULL, 1, 0, 1, '2025-10-12 10:56:51', '2025-10-12 10:57:04'),
(105, 6, 1, 18, 'courier', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-14 12:30:01', '2025-11-11 14:05:26'),
(106, 6, 1, 18, 'Courier', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:22:48', '2025-11-11 14:05:27'),
(107, 6, 1, 18, 'Courier', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:28:59', '2025-11-11 14:05:29'),
(108, 6, 1, 18, 'Tools', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:30:26', '2025-10-14 13:31:20'),
(109, 6, 1, 18, 'Glass', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:35:14', '2025-11-11 14:05:38'),
(110, 6, 1, 18, 'Compressor', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:38:18', '2025-10-14 13:41:46'),
(111, 6, 1, 18, 'Accomodation', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:42:49', '2025-10-14 14:51:10'),
(112, 6, 1, 18, 'Steal', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:45:50', '2025-10-14 13:48:01'),
(113, 6, 1, 18, 'Glass', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:48:18', '2025-10-14 14:57:36'),
(114, 6, 1, 18, 'Glass', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:49:57', '2025-10-14 15:00:19'),
(115, 6, 1, 18, 'Inverter', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:50:59', '2025-10-14 15:00:56'),
(116, 6, 1, 18, 'Signage', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:53:10', '2025-10-14 13:54:30'),
(117, 6, 1, 18, 'Aluminium', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 13:54:51', '2025-10-15 16:06:02'),
(118, 6, 1, 18, 'Inverter/Battery', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:08:46', '2025-10-14 14:10:58'),
(119, 6, 1, 18, 'Frames/Boards/Long Diagonal Braces...', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:13:17', '2025-10-14 14:17:11'),
(120, 6, 1, 18, 'Tiles', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:17:45', '2025-10-15 13:19:28'),
(121, 6, 1, 18, 'Electrical Equipment', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:22:35', '2025-10-14 15:28:46'),
(122, 6, 1, 18, 'Vechile Rental', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:25:59', '2025-10-14 14:33:22'),
(123, 6, 1, 18, 'Vechile Rental', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:33:52', '2025-10-14 14:34:54'),
(124, 6, 1, 18, 'Tiles Equipment', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:36:48', '2025-10-14 15:32:50'),
(125, 6, 1, 18, 'Tile Equipment', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:39:07', '2025-10-14 14:40:48'),
(126, 6, 1, 18, 'Aluminium', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-14 14:41:33', '2025-10-14 14:44:11'),
(128, 6, 1, 18, 'courier (Copy)', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-14 16:36:29', '2025-10-14 16:36:37'),
(129, 6, 1, 18, 'aaa', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-15 08:11:27', '2025-10-15 08:11:37'),
(130, 6, 1, 18, 'Tiles', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-15 13:18:24', '2025-10-15 13:19:14'),
(131, 6, 1, 18, 'Divern paid to Mervin', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-15 13:21:57', '2025-10-15 13:23:58'),
(132, 6, 1, 18, 'hhhh', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-15 13:25:35', '2025-10-15 13:27:22'),
(133, 6, 1, 18, 'Divern paid to Mervin', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 13:27:18', '2025-10-15 13:28:17'),
(134, 6, 1, 18, 'Circular saw', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 13:29:04', '2025-10-15 13:32:17'),
(135, 6, 1, 18, 'Electrical Equipment', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 13:32:30', '2025-10-15 13:36:02'),
(136, 6, 1, 18, 'Scaffolding Hire', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 13:36:43', '2025-10-15 13:37:51'),
(137, 6, 1, 18, 'Electrical Equipment', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 13:38:34', '2025-10-15 13:40:19'),
(138, 6, 1, 18, 'Patric Alarms', NULL, 28, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:14:35', '2025-10-15 15:44:27'),
(139, 6, 1, 18, '20K Float JHB team/ INV for 15K collected/ 5K Alex to cash up', NULL, 29, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:19:59', '2025-10-15 15:20:58'),
(140, 6, 1, 18, 'Kieth & Mervin', NULL, 30, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:22:18', '2025-10-15 15:24:04'),
(141, 6, 1, 18, 'Accomodation', NULL, 31, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:24:16', '2025-11-11 14:33:50'),
(142, 6, 1, 18, 'Kishor Kitchen', NULL, 32, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:28:58', '2025-10-15 15:31:06'),
(143, 6, 1, 18, 'Accomodation (for 45 days)', NULL, 33, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:31:29', '2025-10-16 10:22:28'),
(144, 6, 1, 18, 'Glass', NULL, 34, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:33:17', '2025-10-15 15:34:01'),
(145, 6, 1, 18, 'Food & entertainment', NULL, 35, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:35:02', '2025-10-15 15:35:37'),
(146, 6, 1, 18, '15K Lana', NULL, 36, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:35:50', '2025-10-15 15:36:26'),
(147, 6, 1, 18, 'Furniture', NULL, 37, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:37:27', '2025-10-15 15:39:06'),
(148, 6, 1, 18, 'Evey accomodation', NULL, 38, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:39:27', '2025-10-15 15:40:11'),
(149, 6, 1, 18, 'Evelyn (float/ witness vinyl/ sink ect)', NULL, 39, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:40:59', '2025-10-15 15:41:35'),
(150, 6, 1, 18, 'Only POP received', NULL, 40, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:45:42', '2025-10-15 15:48:13'),
(151, 6, 1, 18, 'Only POP received', NULL, 41, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:51:33', '2025-10-15 15:52:36'),
(152, 6, 1, 18, 'Signage', NULL, 42, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:55:46', '2025-10-15 15:56:30'),
(153, 6, 1, 18, 'Only POP received', NULL, 43, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 15:58:56', '2025-10-15 16:00:11'),
(154, 6, 1, 18, 'Only POP received', NULL, 44, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 16:01:45', '2025-10-15 16:02:42'),
(155, 6, 1, 18, 'Only POP received', NULL, 45, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 16:03:44', '2025-10-15 16:04:26'),
(156, 6, 1, 18, 'Aluminium', NULL, 46, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 16:06:36', '2025-10-15 16:11:17'),
(157, 6, 1, 18, 'Only POP Received', NULL, 47, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-15 16:14:04', '2025-10-15 16:14:48'),
(158, 24, 1, 108, 'a', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-10-16 09:34:41', '2025-10-16 09:41:44'),
(159, 24, 1, 108, 'July', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-16 09:46:41', '2025-10-16 09:51:05'),
(160, 24, 1, 108, 'August', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-16 09:48:10', '2025-10-16 09:51:05'),
(161, 24, 1, 108, 'September', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-16 09:48:42', '2025-10-16 09:51:05'),
(162, 24, 1, 108, 'Adriaan RPVDM', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-16 09:49:26', '2025-10-16 09:51:05'),
(163, 24, 1, 108, 'kkk', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-16 09:54:44', '2025-10-16 09:55:09'),
(164, 24, 1, 108, 'kk', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-16 09:57:27', '2025-10-16 09:58:07'),
(165, 24, 1, 108, 'Wheelbarrow/ shovel/ ect', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 09:58:48', '2025-10-16 10:00:44'),
(166, 24, 1, 108, 'Dynamic Cone Penetration', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:01:33', '2025-10-16 10:02:44'),
(167, 24, 1, 108, 'Netting and wood', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:02:56', '2025-10-16 10:04:57'),
(168, 24, 1, 108, 'washer/ Decking ect', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:05:32', '2025-10-16 10:06:33'),
(169, 24, 1, 108, 'Petrol For Wacker', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:06:49', '2025-10-16 10:07:47'),
(170, 24, 1, 108, 'Hosepipe Fitting ect', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:08:04', '2025-10-16 10:09:08'),
(171, 24, 1, 108, 'Site Foreman', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:09:23', '2025-10-16 10:10:31'),
(172, 24, 1, 108, 'Site Foreman', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:10:45', '2025-10-16 10:11:47'),
(173, 24, 1, 108, 'Site Foreman', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:12:00', '2025-10-16 10:13:17'),
(174, 24, 1, 108, 'SUV Kia', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:13:28', '2025-10-16 10:18:02'),
(175, 24, 1, 108, 'SUV kia', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:18:20', '2025-10-16 10:19:34'),
(176, 24, 1, 108, 'SUV Kia', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:19:14', '2025-10-16 10:27:18'),
(177, 24, 1, 108, 'Monthly Management Fee', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:27:32', '2025-10-16 10:28:15'),
(178, 24, 1, 108, 'Site Plans Print', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:28:33', '2025-10-16 10:29:27'),
(179, 24, 1, 108, 'Storage Container', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:29:44', '2025-10-16 10:42:34'),
(180, 24, 1, 108, 'Petrol wacker', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:30:55', '2025-10-16 10:31:34'),
(181, 24, 1, 108, 'Brickforce', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:31:44', '2025-10-16 10:32:30'),
(182, 24, 1, 108, 'Toilet', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:32:40', '2025-10-16 10:33:49'),
(183, 24, 1, 108, 'guttter sweeper', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:33:59', '2025-10-16 10:35:07'),
(184, 24, 1, 108, 'Brickforce', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:40:41', '2025-10-16 10:41:56'),
(185, 24, 1, 108, 'Storage Container', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:42:25', '2025-10-16 10:43:41'),
(186, 24, 1, 108, 'Hire of Concrete Mixers/ Delivery ect', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:47:22', '2025-10-16 10:49:48'),
(187, 24, 1, 108, 'Hire of rammers/wackers/ delivery ect', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:50:20', '2025-10-16 10:51:57'),
(188, 24, 1, 108, 'Petrol for wackers', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:52:12', '2025-10-16 10:53:11'),
(189, 24, 1, 108, 'Pencil and Gloves', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:53:50', '2025-10-16 10:54:59'),
(190, 24, 1, 108, 'Petrol For Wacker', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:55:16', '2025-10-16 10:56:37'),
(191, 24, 1, 108, 'Hire Of Concrete Mixer/ Delivery ect', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:57:12', '2025-10-16 10:58:11'),
(192, 24, 1, 108, 'Generater Service', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 10:58:29', '2025-10-16 11:00:04'),
(193, 24, 1, 108, 'Site Foreman', NULL, 28, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:00:16', '2025-10-16 11:01:58'),
(194, 24, 1, 108, 'SUV Kia', NULL, 29, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:01:29', '2025-10-16 11:02:25'),
(195, 24, 1, 108, 'Monthly Management Overheads', NULL, 30, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:07:34', '2025-10-16 11:08:25'),
(196, 24, 1, 108, 'Mixer Petrol', NULL, 31, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:08:39', '2025-10-16 11:09:49'),
(197, 24, 1, 108, 'Hire of Concrete mixer/ Delivery ect', NULL, 32, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:10:22', '2025-10-16 11:12:19'),
(198, 24, 1, 108, 'Storage Container', NULL, 33, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:12:36', '2025-10-16 11:13:44'),
(199, 24, 1, 108, 'Toilet', NULL, 34, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:13:58', '2025-10-16 11:19:12'),
(200, 24, 1, 108, 'safety Management file', NULL, 35, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:19:36', '2025-10-16 11:20:39'),
(201, 24, 1, 108, 'Wacker petrol', NULL, 36, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:20:55', '2025-10-16 11:21:49'),
(202, 24, 1, 108, 'Gloves/ Key-Blank', NULL, 37, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:22:10', '2025-10-16 11:23:27'),
(203, 24, 1, 108, 'Petrol Wakkers', NULL, 38, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:23:40', '2025-10-16 11:24:32'),
(204, 24, 1, 108, 'Starter For Generator', NULL, 39, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:25:34', '2025-10-16 11:26:31'),
(205, 24, 1, 108, 'Gloves', NULL, 40, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:26:45', '2025-10-16 11:28:20'),
(206, 24, 1, 108, 'Site Foreman', NULL, 41, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:28:33', '2025-10-16 11:33:28'),
(207, 24, 1, 108, 'SUV kia', NULL, 42, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:33:52', '2025-10-16 11:35:38'),
(208, 24, 1, 108, 'Monthly Management Overheads', NULL, 43, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:35:17', '2025-10-16 11:36:15'),
(209, 24, 1, 108, 'Hire of Rammer/ wacker ect', NULL, 44, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:36:56', '2025-10-16 11:38:03'),
(210, 24, 1, 108, 'Toilet', NULL, 45, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:38:33', '2025-10-16 11:39:59'),
(211, 24, 1, 108, 'Skip Hire', NULL, 46, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 11:40:15', '2025-10-16 12:04:12'),
(212, 24, 1, 108, 'Storage Container', NULL, 47, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:04:26', '2025-10-16 12:08:39'),
(213, 24, 1, 108, 'Toilet', NULL, 48, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:08:54', '2025-10-16 12:09:50'),
(214, 24, 1, 108, 'Storage Container', NULL, 49, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:10:28', '2025-10-16 12:11:25'),
(215, 24, 1, 108, 'Storage Container', NULL, 50, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:11:40', '2025-10-16 12:12:54'),
(216, 24, 1, 108, 'Storage Container', NULL, 51, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:13:14', '2025-10-16 12:15:51'),
(217, 24, 1, 108, 'storage Container', NULL, 52, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:14:45', '2025-10-16 12:16:08'),
(218, 24, 1, 108, 'Storage Container', NULL, 53, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:16:26', '2025-10-16 12:17:08'),
(219, 24, 1, 108, 'Storage Container', NULL, 54, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:17:31', '2025-10-16 12:18:26'),
(220, 24, 1, 108, 'Toilet', NULL, 55, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:18:40', '2025-10-16 12:19:46'),
(221, 24, 1, 108, 'Toilet', NULL, 56, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:20:49', '2025-10-16 12:22:06'),
(222, 24, 1, 108, 'Toilet', NULL, 57, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:24:21', '2025-10-16 12:28:43'),
(223, 24, 1, 108, 'Toilet', NULL, 58, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:26:03', '2025-10-16 12:28:45'),
(224, 24, 1, 108, 'Toilet', NULL, 59, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:30:44', '2025-10-16 12:32:04'),
(225, 24, 1, 108, 'NHBRC', NULL, 60, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:32:56', '2025-10-16 12:33:08'),
(226, 24, 1, 108, 'Tek screws ect', NULL, 61, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-16 12:33:24', '2025-10-16 12:33:54'),
(227, 24, 1, 108, 'Screws/ plugs ect', NULL, 61, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:34:11', '2025-10-16 12:35:35'),
(228, 24, 1, 108, 'Sundries', NULL, 62, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 12:35:54', '2025-10-16 12:37:24'),
(229, 24, 1, 108, 'Nails/ Gloves ect', NULL, 63, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 13:44:49', '2025-10-16 13:46:10'),
(230, 24, 1, 108, 'Gloves Crayfish-GCray', NULL, 64, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 13:46:47', '2025-10-16 13:47:55'),
(231, 24, 1, 108, 'Nut Setter Magnetic', NULL, 65, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 13:48:39', '2025-10-16 13:49:41'),
(232, 24, 1, 108, 'Nails Round Wire', NULL, 66, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 13:51:35', '2025-10-16 13:53:56'),
(233, 24, 1, 108, 'Nails/ Cut-Scr/ ect', NULL, 67, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 13:54:28', '2025-10-16 13:55:49'),
(234, 24, 1, 108, 'Nails', NULL, 68, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 13:56:03', '2025-10-16 13:57:20'),
(235, 24, 1, 108, 'Drill C/L IMP /PP Magnetic ect', NULL, 69, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 13:58:11', '2025-10-16 13:59:30'),
(236, 24, 1, 108, 'Toilet', NULL, 70, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:00:02', '2025-10-16 14:02:17'),
(237, 24, 1, 108, 'Toilet', NULL, 71, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:02:30', '2025-10-16 14:04:16'),
(238, 24, 1, 108, 'Storage Container', NULL, 72, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:04:30', '2025-10-16 14:05:44'),
(239, 24, 1, 108, 'Poker', NULL, 73, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-16 14:07:44', '2025-10-18 17:30:09'),
(240, 24, 1, 108, 'Poker', NULL, 74, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:07:55', '2025-10-16 14:09:21'),
(241, 24, 1, 108, 'Brickforce/ Broom', NULL, 75, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:09:48', '2025-10-16 14:10:48'),
(242, 24, 1, 108, 'Jointex/ Hardboard std', NULL, 76, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:11:32', '2025-10-16 14:13:05'),
(243, 24, 1, 108, 'Nails/ Tek/ Screws ect', NULL, 77, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:13:59', '2025-10-16 14:14:59'),
(244, 24, 1, 108, 'Sheeting', NULL, 78, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:15:09', '2025-10-16 14:16:28'),
(245, 24, 1, 108, 'Nails Masonary fluted/ Brickforce ect', NULL, 79, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:16:59', '2025-10-16 14:18:20'),
(246, 24, 1, 108, 'Wheelbarrow/ spade ect', NULL, 80, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:18:46', '2025-10-16 14:20:48'),
(247, 24, 1, 108, 'Toilet', NULL, 81, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:20:57', '2025-10-16 14:21:52'),
(248, 24, 1, 108, 'Skip', NULL, 82, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:22:07', '2025-10-16 14:23:11'),
(249, 24, 1, 108, 'Skip', NULL, 83, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:23:29', '2025-10-16 14:25:15'),
(250, 24, 1, 108, 'Poker', NULL, 84, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:25:34', '2025-10-16 14:26:43'),
(251, 24, 1, 108, 'Gum Boots/ Knee pads', NULL, 85, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:28:30', '2025-10-16 14:29:38'),
(252, 24, 1, 108, 'Threaded Rod/ Fible Sheet Resin', NULL, 86, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:31:07', '2025-10-16 14:32:06'),
(253, 24, 1, 108, 'Hose', NULL, 87, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 14:32:41', '2025-10-16 14:33:56'),
(254, 24, 1, 108, 'storage container', NULL, 88, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 15:12:20', '2025-10-16 15:13:23'),
(255, 24, 1, 108, 'Nails', NULL, 89, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 15:13:32', '2025-10-16 15:15:34'),
(256, 24, 1, 108, 'Toilet', NULL, 90, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-16 15:15:49', '2025-10-16 15:16:39'),
(257, 24, 1, 109, 'Beacon Verifications ect', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 09:30:26', '2025-10-17 09:33:53'),
(258, 24, 1, 109, 'TLB Hire (18 Hours)', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 09:34:18', '2025-10-17 09:37:27'),
(259, 24, 1, 109, 'TLB Hire (6 Hours)', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 09:36:28', '2025-10-17 09:37:37'),
(260, 24, 1, 109, 'TLB Hire (26 Hours) / Tipper 58 Hire', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 09:38:34', '2025-10-17 09:39:45'),
(261, 24, 1, 109, 'Hire of Rammer/ Delivery ect', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 09:40:13', '2025-10-17 09:41:20'),
(262, 24, 1, 109, 'Land survey', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 09:41:35', '2025-10-17 09:42:51'),
(263, 24, 1, 109, 'Rammer', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 09:43:06', '2025-10-17 10:20:58'),
(264, 24, 1, 109, 'Rammer', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:15:06', '2025-10-17 10:20:52'),
(265, 24, 1, 109, 'Rammer', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:16:22', '2025-10-17 10:20:46'),
(266, 24, 1, 109, 'Nails/ Plugs Ect', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:17:38', '2025-10-17 10:19:24'),
(267, 24, 1, 109, 'Rammer', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:19:32', '2025-10-17 10:21:00'),
(268, 24, 1, 110, '25Mpa 19/13 mm 30x concrete/ pump ect', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:22:17', '2025-10-17 10:23:45'),
(269, 24, 1, 110, 'Gloves', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:27:39', '2025-10-17 10:38:20'),
(270, 24, 1, 110, 'Building sand', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:39:00', '2025-10-17 10:40:06'),
(271, 24, 1, 110, 'Cement x30', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:40:25', '2025-10-17 10:41:58'),
(272, 24, 1, 110, 'Cement Afrisam All Pourpose', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:42:24', '2025-10-17 10:43:58'),
(273, 24, 1, 110, 'Binding Wire & Steel', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 10:44:15', '2025-10-17 11:26:54'),
(274, 24, 1, 110, 'Binding Wire & Steel', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:27:18', '2025-10-17 11:28:53'),
(275, 24, 1, 110, 'Kerb Mix', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:29:06', '2025-10-17 11:30:14'),
(276, 24, 1, 110, 'Building sand', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:30:58', '2025-10-17 11:33:15'),
(277, 24, 1, 110, 'DPC sheeting 30m x 3m', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:33:41', '2025-10-17 11:35:00'),
(278, 24, 1, 110, 'REF193 Mech - Internal Materiaal Transfer', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:37:28', '2025-10-17 11:38:38'),
(279, 24, 1, 110, 'Internal Brick Charge from 305 Valley to 11 Grandiceps x1000', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:39:24', '2025-10-17 11:40:28'),
(280, 24, 1, 110, 'Sheeting', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:40:41', '2025-10-17 11:48:41'),
(281, 24, 1, 114, '22 000 x Bricks', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:45:46', '2025-10-17 11:48:00'),
(282, 24, 1, 114, 'Cement/ Brickforce ect', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:49:37', '2025-10-17 11:52:08'),
(283, 24, 1, 114, 'Cement', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:52:30', '2025-10-17 11:53:48'),
(284, 24, 1, 114, 'Butterfly Ties', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:54:19', '2025-10-17 11:55:26'),
(285, 24, 1, 114, 'Cement & Nails', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:55:50', '2025-10-17 11:57:40'),
(286, 24, 1, 114, 'Cement & del Bakkie', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:58:00', '2025-10-17 11:58:52'),
(287, 24, 1, 114, 'Kerb Mix', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 11:58:59', '2025-10-17 12:00:11'),
(288, 24, 1, 114, 'Cement', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:00:30', '2025-10-17 12:01:52'),
(289, 24, 1, 114, 'Cement', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:02:05', '2025-10-17 12:03:54'),
(290, 24, 1, 114, 'Cement All purpose', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:04:23', '2025-10-17 12:05:19'),
(291, 24, 1, 114, 'Chain/ Cup Hook', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:05:39', '2025-10-17 12:06:35'),
(292, 24, 1, 114, 'Cement', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:06:46', '2025-10-17 12:07:50'),
(293, 24, 1, 114, 'Lintels/ Brickforce/ Butterfly\'s ect', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:08:41', '2025-10-17 12:16:50'),
(294, 24, 1, 114, 'Cement Del Bakkie', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:17:06', '2025-10-17 12:22:04'),
(295, 24, 1, 114, 'Building Sand', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:22:23', '2025-10-17 12:24:29'),
(296, 24, 1, 114, 'Cement/ Plastering Plastic/ Trowel Pastering ect', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:25:34', '2025-10-17 12:30:24'),
(297, 24, 1, 114, 'Elastorcyl - Charcoal 201', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:31:00', '2025-10-17 12:32:02'),
(298, 24, 1, 114, 'Ref 193 Mesh', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:32:26', '2025-10-17 12:33:32'),
(299, 24, 1, 114, 'Lintels', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:33:49', '2025-10-17 12:34:41'),
(300, 24, 1, 114, '25Mpa 19/13mm / concrete/ Pump Establishment', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:35:35', '2025-10-17 12:39:57'),
(301, 24, 1, 114, 'sheeting/ Roll', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:38:42', '2025-10-17 12:40:01'),
(302, 24, 1, 114, 'Brick Clay/ Cement ect', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:40:30', '2025-10-17 12:41:38'),
(303, 24, 1, 114, 'Building sand', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:41:48', '2025-10-17 12:42:41'),
(304, 24, 1, 114, 'Cement / Del bakkie', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:42:52', '2025-10-17 12:43:52'),
(305, 24, 1, 114, 'Steel', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:44:02', '2025-10-17 12:45:24'),
(306, 24, 1, 114, 'Kerb Mix', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:45:38', '2025-10-17 12:46:51'),
(307, 24, 1, 114, 'Cement Afrisam All purpose 42. 5N/ Brickforce', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:47:34', '2025-10-17 12:50:22'),
(308, 24, 1, 114, 'Cement', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:50:35', '2025-10-17 12:51:40'),
(309, 24, 1, 114, 'Cement', NULL, 28, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-17 12:52:04', '2025-10-17 12:53:21'),
(310, 24, 1, 115, 'Payments Per Slab', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 11:16:25', '2025-10-18 11:18:10'),
(311, 24, 1, 115, 'Rebar For Slab', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 11:18:33', '2025-10-18 11:19:44'),
(312, 24, 1, 115, 'Mesh & DPC', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 11:20:48', '2025-10-18 11:21:36'),
(313, 24, 1, 115, 'Timber', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 11:22:17', '2025-10-18 11:25:01'),
(314, 24, 1, 115, 'Jointex/ hardwood ect', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 11:25:29', '2025-10-18 11:29:51'),
(315, 24, 1, 115, 'Ready Mix', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 11:30:09', '2025-10-21 05:53:51'),
(316, 24, 1, 115, 'Ready Mix', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 11:31:48', '2025-10-18 11:33:17'),
(317, 24, 1, 115, 'Timber/ Nails ect', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 11:33:36', '2025-10-18 11:34:35'),
(318, 17, 1, 84, 'Clean area place netting', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 11:58:10', '2025-11-03 11:48:38'),
(319, 17, 1, 84, 'Sundries', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:04:18', '2025-11-03 11:48:40'),
(320, 17, 1, 84, 'Labour', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:07:16', '2025-11-03 11:48:41'),
(321, 17, 1, 85, 'Demolish: Ramp to Workshop', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:12:57', '2025-12-01 05:24:31'),
(322, 17, 1, 85, 'Demolish: Tiles', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:13:21', '2025-12-13 07:21:16'),
(323, 17, 1, 85, 'Demolish: 2 New front entrances', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:17:36', '2025-11-27 18:07:49'),
(324, 17, 1, 85, 'Tile floor 175m2: Tiles', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:20:48', '2026-01-16 14:35:21'),
(325, 17, 1, 85, 'Tile floor 175m2: Cement and sundries', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:22:05', '2026-01-16 14:35:11'),
(326, 17, 1, 85, 'Tile floor 175m2: Labour', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:23:28', '2026-01-28 14:04:52'),
(327, 17, 1, 85, 'New Showroom Aluminium and Glass: Install Lintels', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:25:19', '2025-11-27 18:08:28'),
(328, 17, 1, 85, 'New Showroom Aluminium and Glass: Supply Aluminium & Glass', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:27:03', '2025-12-13 07:21:25'),
(329, 17, 1, 85, 'New Showroom Aluminium and Glass: Round off shopfront', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:27:06', '2025-12-13 07:21:32'),
(330, 17, 1, 116, 'Foundation: Excavation', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:31:31', '2025-11-03 11:48:31'),
(331, 17, 1, 116, 'Foundation: Steel', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:33:44', '2025-11-03 11:48:18'),
(332, 17, 1, 116, 'Foundation: Concrete', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:34:33', '2025-11-03 11:48:27'),
(333, 17, 1, 116, 'Foundation: Underpinning under old building', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:35:35', '2025-11-03 11:48:52'),
(334, 17, 1, 116, 'Foundation: Building of foundation walls', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:36:29', '2025-11-06 15:11:56'),
(335, 17, 1, 116, 'Foundation: Compacting and filling', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:37:40', '2025-11-13 13:31:38'),
(336, 17, 1, 116, 'Foundation: Ref 193 And DPC', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:38:23', '2025-11-13 13:31:39'),
(337, 17, 1, 116, 'Foundation: Slab concrete', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:39:19', '2025-11-13 13:31:40'),
(338, 17, 1, 116, 'Foundation: Labour', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:40:34', '2025-11-13 13:31:42'),
(339, 17, 1, 116, 'Brickwork: Brickwork with casted column', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:42:26', '2025-11-06 15:28:39'),
(340, 17, 1, 116, 'Brickwork: Ibeams', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:43:14', '2025-11-13 13:31:54'),
(341, 17, 1, 116, 'Brickwork: Above Ibeam Brickwork', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 12:44:10', '2025-11-25 02:35:47'),
(342, 17, 1, 116, 'Roofing: Structure for Sheeting', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:01:40', '2025-11-27 18:06:01'),
(343, 17, 1, 116, 'Roofing: Sundries', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:02:36', '2025-11-27 18:06:08'),
(344, 17, 1, 116, 'Roofing: IBR Cromadek', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:03:12', '2025-11-27 18:06:13'),
(345, 17, 1, 116, 'Roofing: Install cromadec', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:03:49', '2025-11-27 18:06:21'),
(346, 17, 1, 116, 'Plaster: Supply material and Sundries', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:04:52', '2025-12-01 05:22:41'),
(347, 17, 1, 116, 'Plaster: Labour', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:05:26', '2025-12-13 07:20:09'),
(348, 17, 1, 116, 'Aluminium and Glass: Supply Aluminium and Glass', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:23:25', '2025-12-13 07:20:16'),
(349, 17, 1, 116, 'Aluminium and Glass: Install', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:28:16', '2025-12-13 07:20:26'),
(350, 17, 1, 116, 'Ceiling: Supply and install', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:28:59', '2025-12-19 04:22:55'),
(351, 17, 1, 86, 'Ceiling Repairs:', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:31:10', '2025-11-27 18:08:39'),
(352, 17, 1, 86, 'Make all electrical Safe', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:32:09', '2025-11-27 18:08:45'),
(353, 17, 1, 86, 'Instalation of new lights in extention area: Supply and install new led lights', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:32:53', '2026-01-28 14:05:30'),
(354, 17, 1, 117, 'Drywall 3m High 24m long: Supply and install drywalingl', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:34:35', '2025-11-13 13:32:01'),
(355, 17, 1, 117, 'Drywall 3m High 24m long: Roof ontop of cubacle', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:35:27', '2025-11-25 02:37:00'),
(356, 17, 1, 117, 'Drywall 3m High 24m long: lifted floor', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:36:08', '2025-11-25 02:37:02'),
(357, 17, 1, 117, 'Floor: Diamond Grind Floor', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:37:49', '2026-01-28 14:04:02'),
(358, 17, 1, 117, 'Floor: Epoxy floor 2 coats', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:38:38', '2026-01-28 14:03:57'),
(359, 17, 1, 117, 'Remove Workshop doors: Labour', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:39:26', '2025-11-25 02:37:24'),
(360, 17, 1, 117, 'Insert 2 new entrances on the left side of the middle entrance: Insert 2 new entrances', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:41:00', '2025-11-25 02:37:20'),
(361, 17, 1, 117, 'Insert 2 lintels and plaster: Insert 2 lintels and plaster', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:42:27', '2025-12-01 05:23:30'),
(362, 17, 1, 117, 'Paint front area of the new Showroom celing Black: Supply Paint', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:43:06', '2025-12-01 05:24:05'),
(363, 17, 1, 117, 'Paint front area of the new Showroom celing Black: Labour', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:43:44', '2025-12-13 07:20:46'),
(364, 17, 1, 117, 'Remove the carport and move to the back: Remove carports', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:44:50', '2026-01-30 06:24:24'),
(365, 17, 1, 117, 'Remove the carport and move to the back: Installing at back', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:45:40', '2026-01-28 14:04:36'),
(366, 17, 1, 117, 'Supply and install new clearview fence: Supply and install Clearview', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:46:22', '2026-01-16 14:34:48'),
(367, 17, 1, 117, 'Electrical for new offices: Plugs', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:47:04', '2026-01-30 06:24:37'),
(368, 17, 1, 117, 'Electrical for new offices: Lights', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:47:50', '2025-10-18 13:48:07'),
(369, 17, 1, 118, 'Paint outside: Supply and paint plaster primer', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:56:29', '2026-01-05 10:11:40'),
(370, 17, 1, 118, 'Paint outside: Rapair outside wall and prepair', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:56:40', '2025-10-30 15:11:35'),
(371, 17, 1, 118, 'Paint outside: Paint outside wall', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:56:42', '2025-10-30 15:11:50'),
(372, 17, 1, 118, 'Paint outside: Supply outside paint', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 13:56:44', '2026-01-05 10:11:37'),
(373, 17, 1, 116, 'Drywall with recycle door', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 14:03:36', '2025-11-25 02:36:56'),
(374, 26, 1, 119, 'Repair all walls and balconies with Duragrout', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:02:21', '2025-12-19 04:04:35'),
(375, 26, 1, 119, 'Prepare wall for repainting', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:10:25', '2025-12-11 11:25:07'),
(376, 26, 1, 119, 'Paint wall 2 coats matching', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:11:28', '2025-12-19 04:04:44'),
(377, 26, 1, 119, 'Prepare balcony wall + waterproof joints', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:12:33', '2025-12-11 11:25:34'),
(378, 26, 1, 119, 'Paint balcony wall 2 coats matching', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:13:32', '2025-12-11 11:25:43'),
(379, 26, 1, 119, 'Prepare balcony fence', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:14:21', '2025-12-11 11:25:51'),
(380, 26, 1, 119, 'Paint balcony fence 2 coats enamel', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:15:09', '2026-01-21 12:30:41'),
(381, 26, 1, 119, 'Prepare balcony ceiling, replace damaged board', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:16:19', '2025-11-27 18:18:26'),
(382, 26, 1, 119, 'Paint balcony ceiling 2 coats', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:16:55', '2025-12-11 11:26:19'),
(383, 26, 1, 119, 'Prepare Northside windows, repaint & seal joints', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:17:33', '2026-01-21 12:30:53'),
(384, 26, 1, 119, 'Paint Northside windows 2 coats', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:18:10', '2026-01-21 12:31:03'),
(385, 26, 1, 119, 'Prepare lake-side windows, remove damaged putty', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:18:50', '2026-01-21 12:31:17'),
(386, 26, 1, 119, 'Paint lake-side windows & wall 2 coats', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:19:37', '2025-12-11 12:24:01'),
(387, 26, 1, 119, 'Prepare basement pillars & walls', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:20:09', '2025-12-02 10:58:39'),
(388, 26, 1, 119, 'Paint basement pillars & walls 2 coats', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:20:47', '2025-12-11 12:26:22'),
(389, 26, 1, 119, 'Prepare lake-side balcony burglar fence', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:21:22', '2025-11-27 15:51:24'),
(390, 26, 1, 119, 'Paint lake-side burglar fence 2 coats', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:21:56', '2026-01-21 12:31:36'),
(391, 26, 1, 119, 'Prepare precast wall for repainting', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:22:44', '2026-01-21 12:31:43'),
(392, 26, 1, 119, 'Prepare west-side windows, remove damaged putty', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:23:41', '2025-12-11 12:27:06'),
(393, 26, 1, 119, 'Paint west-side windows 2 coats matching', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-18 15:24:19', '2026-01-21 12:31:58'),
(394, 24, 1, 128, 'Bricks & Sundries', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:40:44', '2025-10-18 15:47:44'),
(395, 24, 1, 128, 'Cement/ Sand ect', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:42:35', '2025-10-18 15:47:45'),
(396, 24, 1, 128, 'Brickforce ect', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:44:01', '2025-10-18 15:47:46'),
(397, 24, 1, 128, 'Bricks ect', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:46:13', '2025-10-18 15:47:48'),
(398, 24, 1, 128, 'Brickforce ect', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:48:07', '2025-10-18 15:48:54');
INSERT INTO `board_items` (`id`, `board_id`, `company_id`, `group_id`, `title`, `description`, `position`, `status_label`, `assigned_to`, `priority`, `progress`, `due_date`, `start_date`, `end_date`, `tags`, `archived`, `subitem_count`, `created_by`, `created_at`, `updated_at`) VALUES
(399, 24, 1, 128, 'Steel', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:49:06', '2025-10-18 15:50:17'),
(400, 24, 1, 128, 'Brick Clayg/ Bousand M3 ect', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:50:57', '2025-10-18 15:51:56'),
(401, 24, 1, 128, 'Lintels', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:52:12', '2025-10-18 15:53:12'),
(402, 24, 1, 128, 'Bracing Strap/ nails Masonry/ Gecko Mesh', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:53:46', '2025-10-18 15:54:43'),
(403, 24, 1, 128, 'Brick Clay', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:54:56', '2025-10-18 15:55:46'),
(404, 24, 1, 128, 'Nails/ Grout', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:56:41', '2025-10-18 15:57:33'),
(405, 24, 1, 128, 'Duragrout 20kg/ Sand rivier/ Nails Brickforce ect', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:58:46', '2025-10-18 16:01:11'),
(406, 24, 1, 128, 'Poker', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 15:59:57', '2025-10-18 16:01:10'),
(407, 24, 1, 128, 'Sand', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:01:17', '2025-10-18 16:02:32'),
(408, 24, 1, 128, 'Sand', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:02:50', '2025-10-18 16:39:02'),
(409, 24, 1, 128, 'Clay Brick', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:39:21', '2025-10-18 16:40:27'),
(410, 24, 1, 128, 'Brickforce', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:40:42', '2025-10-18 16:41:30'),
(411, 24, 1, 128, 'Bricks', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:41:40', '2025-10-18 16:42:36'),
(412, 24, 1, 128, 'Bricks', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:42:42', '2025-10-18 16:43:47'),
(413, 24, 1, 129, 'Roof', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:44:27', '2025-10-18 16:45:47'),
(414, 24, 1, 129, 'Roof kraai', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:45:57', '2025-10-18 16:46:58'),
(415, 24, 1, 131, 'Cornice adhesive/ Duram Wall + Ceiling White 5L', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:51:20', '2025-10-18 17:28:49'),
(416, 24, 1, 134, 'Binding wire/ Rebar: Supply, cut, bend ect', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:55:30', '2025-10-18 16:56:58'),
(417, 24, 1, 134, 'Binding wire/ Rebar: Supply, cut, bend ect', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 16:58:50', '2025-10-18 17:00:36'),
(418, 24, 1, 134, 'Binding Wire', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:00:50', '2025-10-18 17:01:57'),
(419, 24, 1, 134, 'Duram Heat Black Paint/ Brush ect', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:02:46', '2025-10-18 17:03:59'),
(420, 24, 1, 135, 'Plaster', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:04:49', '2025-10-18 17:06:10'),
(421, 24, 1, 135, 'Sand', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:06:24', '2025-10-18 17:07:33'),
(422, 24, 1, 135, 'Cement', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:07:48', '2025-10-18 17:09:04'),
(423, 24, 1, 135, 'Pleister Sand', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:09:34', '2025-10-18 17:10:35'),
(424, 24, 1, 135, 'Cement/ Ogies wire/ Grippon', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:11:01', '2025-10-18 17:27:05'),
(425, 24, 1, 135, 'Plaster', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:27:15', '2025-10-18 17:28:22'),
(426, 24, 1, 135, 'Plumbing', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-18 17:30:42', '2025-10-18 17:31:09'),
(427, 24, 1, 136, 'Plumbsteel PPW Paid Direct', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:32:25', '2025-10-18 17:37:03'),
(428, 24, 1, 136, 'Plumb Steel', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:34:17', '2025-10-18 17:36:18'),
(429, 24, 1, 136, 'Drain Easy Channel & Grate BI Black/ gully', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:38:08', '2025-10-18 17:39:52'),
(430, 24, 1, 137, 'ILJ Electrical', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:44:55', '2025-10-18 17:46:06'),
(431, 24, 1, 137, 'ILJ Electrical', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:46:18', '2025-10-18 17:48:19'),
(432, 24, 1, 139, 'Duram Woodseal clear5', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:53:15', '2025-10-18 17:54:34'),
(433, 24, 1, 139, 'lintels', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:54:46', '2025-10-18 17:55:45'),
(434, 24, 1, 139, 'Lintels', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 17:55:53', '2025-10-18 17:57:29'),
(435, 24, 1, 140, 'Flexibe W/sealant / Duram ect', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 18:06:22', '2025-10-18 18:07:18'),
(436, 24, 1, 140, 'Sealant/ masking tape', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 18:07:37', '2025-10-18 18:08:26'),
(437, 24, 1, 141, 'Brushes', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 18:09:15', '2025-10-18 18:12:36'),
(438, 24, 1, 145, 'Toilet Paper', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-18 18:15:05', '2025-10-18 18:15:55'),
(439, 24, 1, 148, 'Labour', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 10:04:11', '2025-10-19 10:05:40'),
(440, 24, 1, 148, 'Labour', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 10:05:45', '2025-10-19 10:06:32'),
(441, 24, 1, 148, 'Labour', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 10:06:40', '2025-10-19 10:08:29'),
(442, 24, 1, 148, 'Labour', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 10:07:06', '2025-10-19 10:11:40'),
(443, 24, 1, 148, 'Labour', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 10:09:29', '2025-10-19 10:11:49'),
(444, 24, 1, 148, 'Labour', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:15:14', '2025-10-19 11:16:44'),
(445, 24, 1, 148, 'Labour', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:17:30', '2025-10-19 11:20:07'),
(446, 24, 1, 148, 'Labour', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:20:12', '2025-10-19 11:21:24'),
(447, 24, 1, 148, 'Labour', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:21:34', '2025-10-19 11:24:42'),
(448, 24, 1, 148, 'Labour', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:24:51', '2025-10-19 11:25:37'),
(449, 24, 1, 148, 'Wages', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:25:47', '2025-10-19 11:26:38'),
(450, 24, 1, 148, 'Wages', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:26:54', '2025-10-19 11:27:53'),
(451, 24, 1, 148, 'Wages', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:28:05', '2025-10-19 11:28:51'),
(452, 24, 1, 148, 'Wages', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:29:02', '2025-10-19 11:30:35'),
(453, 24, 1, 148, 'Wages', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:31:12', '2025-10-19 11:32:53'),
(454, 24, 1, 148, 'wages', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:33:28', '2025-10-19 11:34:52'),
(455, 24, 1, 148, 'Wages', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:34:34', '2025-10-19 11:35:32'),
(456, 24, 1, 148, 'Wages', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:35:43', '2025-10-19 11:36:28'),
(457, 24, 1, 148, 'Wages', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:36:39', '2025-10-19 11:37:31'),
(458, 24, 1, 148, 'Wages', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:37:39', '2025-10-19 11:38:27'),
(459, 24, 1, 148, 'Wages', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:38:36', '2025-10-19 11:40:02'),
(460, 24, 1, 148, 'Wages', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:40:10', '2025-10-19 11:40:56'),
(461, 24, 1, 148, 'Wages', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:41:04', '2025-10-19 11:41:48'),
(462, 24, 1, 148, 'Wages', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 11:41:56', '2025-10-19 11:42:44'),
(463, 24, 1, 148, 'Wages', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 12:02:05', '2025-10-19 12:03:23'),
(464, 24, 1, 149, 'July', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 12:06:10', '2025-10-19 12:09:27'),
(465, 24, 1, 149, 'August', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 12:06:58', '2025-10-19 12:09:32'),
(466, 24, 1, 149, 'September', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 12:07:48', '2025-10-19 12:08:25'),
(467, 24, 1, 149, 'Adriaan RPVDM', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-19 12:08:43', '2025-10-19 12:09:21'),
(468, 24, 1, 135, 'Cement Afrisan All Purpose 42.  5N', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-20 06:11:38', '2025-10-20 06:12:54'),
(469, 24, 1, 136, 'Copper Pipe Glass/ Pipe PVC 50mm ect', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-20 06:18:55', '2025-10-20 06:20:22'),
(470, 26, 1, 119, 'Prepare east wall floor for repainting', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:37:44', '2025-12-01 05:28:31'),
(471, 26, 1, 119, 'Paint east wall floor 2 coats enamel', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:45:40', '2025-10-29 07:29:14'),
(472, 26, 1, 119, 'Remove & replace balcony ceiling boards, prep paint', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 4, '2025-10-20 08:46:12', '2025-10-29 06:54:40'),
(473, 26, 1, 119, 'Paint balcony ceiling 2 coats', NULL, 29, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:46:46', '2025-12-11 12:28:19'),
(474, 26, 1, 119, 'Prepare balcony edges', NULL, 30, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:47:36', '2025-12-19 04:06:23'),
(475, 26, 1, 119, 'Paint balcony edges 2 coats', NULL, 31, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:48:54', '2025-12-11 12:28:33'),
(476, 26, 1, 119, 'Install 21 steel service doors, locks, keys, paint', NULL, 32, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 4, '2025-10-20 08:49:36', '2025-10-29 06:56:10'),
(477, 26, 1, 119, 'Install 1 ground floor burglar service door & paint', NULL, 33, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 4, '2025-10-20 08:50:49', '2025-10-29 06:56:10'),
(478, 26, 1, 119, 'Prepare 63 door frames & burglar bars repainting', NULL, 34, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:51:25', '2025-12-01 05:29:04'),
(479, 26, 1, 119, 'Paint 63 door frames & burglar bars matching', NULL, 35, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:52:02', '2026-01-21 12:32:09'),
(480, 26, 1, 119, 'Prepare balcony fence & burglar basement', NULL, 36, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:52:32', '2025-11-27 15:52:56'),
(481, 26, 1, 119, 'Paint balcony fence & burglar basement 2 coats enamel', NULL, 37, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:55:28', '2026-01-21 12:32:22'),
(482, 26, 1, 119, 'Remove damaged window putty, reinstall new putty', NULL, 39, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:57:39', '2025-11-27 15:53:07'),
(483, 26, 1, 119, 'Paint windows 2 coats', NULL, 40, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:58:53', '2026-01-21 12:32:39'),
(484, 26, 1, 119, 'Prepare steel staircase for repainting', NULL, 41, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:59:28', '2025-11-27 15:53:18'),
(485, 26, 1, 119, 'Paint steel staircase 2 coats enamel matching', NULL, 42, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 08:59:57', '2026-01-21 12:32:48'),
(486, 26, 1, 119, 'Scaffolding Hire (4-story, 6 weeks rental including setup and dismantle)', NULL, 43, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 09:00:35', '2025-12-11 12:30:22'),
(487, 26, 1, 119, 'Presure wash and prepare all facebricks', NULL, 44, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 4, '2025-10-20 09:01:15', '2025-10-29 06:56:10'),
(488, 26, 1, 119, 'Paint all facebricks with Armorseal 301', NULL, 45, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 4, '2025-10-20 09:01:55', '2025-10-29 06:56:10'),
(489, 26, 1, 119, 'Open and Waterproof Bottom wall outside steps', NULL, 46, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 09:02:36', '2026-01-21 12:33:00'),
(490, 26, 1, 119, 'Prepare Palasides all around Building', NULL, 47, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 09:03:13', '2025-12-19 04:11:50'),
(491, 26, 1, 119, 'Paint Palasides all around building', NULL, 48, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 09:04:09', '2026-01-21 12:33:33'),
(492, 26, 1, 119, 'Paint precast wall 2 coats Dulux', NULL, 55, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 4, '2025-10-20 09:09:00', '2025-10-29 06:56:10'),
(493, 26, 1, 120, 'Repair precast slab wall behind the palisides (Parking Area)', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 09:18:45', '2026-01-21 12:34:06'),
(494, 26, 1, 120, 'ducting replace botom area', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 09:19:33', '2025-10-20 09:20:04'),
(495, 26, 1, 120, 'Carport new extention', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 4, '2025-10-20 09:20:27', '2025-10-20 09:20:51'),
(496, 6, 1, 18, '50 x 5 Flat Bar x 6M/  16 Round bar x 6M/  ect', NULL, 48, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-20 14:01:53', '2025-10-27 16:52:29'),
(497, 24, 1, 128, 'Building sand del', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 05:54:45', '2025-10-21 05:56:33'),
(498, 6, 1, 18, 'Float Evelyn', NULL, 49, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 13:12:54', '2025-10-21 13:14:54'),
(499, 6, 1, 18, '42-210NM 1/2\'DR Torque/ Wrench/ ect', NULL, 50, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 13:22:27', '2025-10-21 13:24:39'),
(500, 30, 1, 154, 'Cement/ Bousand/ River Gravel', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 14:44:32', '2025-11-28 20:21:24'),
(501, 30, 1, 154, 'Cement/ Brick Stocks Cement/ Bricforce NHBRC', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 14:55:26', '2025-10-21 14:56:24'),
(502, 30, 1, 154, 'Brick Stocks Cement', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 14:56:45', '2025-10-21 14:58:07'),
(503, 30, 1, 154, 'Brick Stocks Cement', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 14:59:12', '2025-10-21 15:00:30'),
(504, 30, 1, 154, 'Cement', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:01:12', '2025-10-21 15:02:30'),
(505, 30, 1, 154, 'Cement', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:02:41', '2025-10-21 15:03:40'),
(506, 30, 1, 154, 'Cement / Bousand', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:03:58', '2025-10-21 15:04:54'),
(507, 30, 1, 154, 'Sheeting Black', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:05:16', '2025-10-21 15:06:00'),
(508, 30, 1, 154, 'Silicone Paintable acrylic/ Masking tape ect', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:06:41', '2025-10-21 15:07:29'),
(509, 30, 1, 154, 'Sand Concrete Mix / cement', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:08:02', '2025-10-21 15:08:46'),
(510, 30, 1, 154, 'Brick Stocks Clay / Brickforce ect', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:09:15', '2025-10-21 15:10:00'),
(511, 30, 1, 154, 'Plaster Sand', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:10:16', '2025-10-21 15:11:04'),
(512, 30, 1, 154, 'Grippon / Riversand', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:11:25', '2025-10-21 15:13:36'),
(513, 30, 1, 154, 'Brick Force Clay', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:13:51', '2025-10-21 15:14:33'),
(514, 30, 1, 154, 'Brick Stocks Cement', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:14:55', '2025-10-21 15:15:39'),
(515, 30, 1, 154, 'Del Bakkie Cement', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:15:54', '2025-10-21 15:16:52'),
(516, 30, 1, 154, 'Lintels / Duram Cement Primer / Bracing Strap', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:17:31', '2025-10-21 15:18:21'),
(517, 30, 1, 154, 'Brick Stocks Clay/ Cement', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:18:45', '2025-10-21 15:19:32'),
(518, 30, 1, 154, 'Plaster Sand', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:19:46', '2025-10-21 15:20:39'),
(519, 30, 1, 154, 'Tile Mosiac Fix / Sponge / Tile Edge Straight ect', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:21:17', '2025-10-21 15:21:59'),
(520, 30, 1, 154, 'Dust Mask / Blade diamond', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:22:41', '2025-10-21 15:23:21'),
(521, 30, 1, 154, 'Rammer', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:23:28', '2025-10-21 15:24:49'),
(522, 30, 1, 154, 'Topping Sand', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:25:00', '2025-10-21 15:26:54'),
(523, 30, 1, 154, 'Dowlstick / Tek Screw Wood ect', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:27:22', '2025-10-21 15:28:38'),
(524, 30, 1, 154, 'Rammer', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:29:08', '2025-10-21 15:29:53'),
(525, 30, 1, 154, 'Grippon / Bracing Strap / Lintels ect', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-21 15:30:22', '2025-10-21 15:32:51'),
(526, 24, 1, 160, 'Purchase of Property', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-22 13:07:58', '2025-10-22 13:08:10'),
(527, 24, 1, 160, 'Bond Costs', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-22 13:08:25', '2026-02-16 17:33:58'),
(528, 6, 1, 18, 'Patrick Alarms', NULL, 51, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:38:03', '2025-10-27 16:39:34'),
(529, 6, 1, 18, 'Total Paid to Patrick 22k', NULL, 52, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:39:48', '2025-10-27 16:40:10'),
(530, 6, 1, 18, 'Security alarms', NULL, 53, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:40:47', '2025-11-11 14:05:56'),
(531, 6, 1, 18, 'Security Alarms', NULL, 54, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:42:56', '2025-10-27 16:47:25'),
(532, 6, 1, 18, 'security alarms', NULL, 55, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:48:08', '2025-10-27 16:49:24'),
(533, 6, 1, 18, 'Evelyn Float', NULL, 56, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:49:40', '2025-10-27 16:50:12'),
(534, 6, 1, 18, 'Clifton Float', NULL, 57, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:50:21', '2025-10-27 16:52:05'),
(535, 6, 1, 18, 'POP only', NULL, 58, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:54:11', '2025-10-27 16:54:56'),
(536, 6, 1, 18, '6.38mm clear laminated jumbo, ect', NULL, 59, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-27 16:57:14', '2025-10-27 17:00:21'),
(537, 18, 1, 87, 'Trailer Rent', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-28 09:54:03', '2025-10-30 12:19:20'),
(538, 18, 1, 87, 'Diesel', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-28 13:58:43', '2025-10-30 14:26:35'),
(539, 18, 1, 87, 'Drill Bit 5x8 .0x100x160 MM SDS Plus ect', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-28 14:04:25', '2025-10-28 14:05:40'),
(540, 18, 1, 87, '|Builders Warehouse', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-28 14:07:44', '2025-12-11 10:40:15'),
(541, 26, 1, 119, 'Paint Precast wall 2 coats', NULL, 57, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-29 06:48:07', '2026-01-21 12:33:54'),
(542, 26, 1, 119, 'Remove & replace balcony ceiling boards, prep paint', NULL, 60, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-10-29 06:55:40', '2026-01-21 12:33:48'),
(543, 18, 1, 87, 'Chalk Line, Tek Screw', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 12:44:22', '2025-10-30 12:47:07'),
(544, 18, 1, 87, 'Diesel', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-10-30 12:49:21', '2025-10-30 14:26:35'),
(545, 18, 1, 87, 'Trailer Rent', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 12:54:42', '2025-10-30 12:55:39'),
(546, 18, 1, 87, 'Ski Rope ect', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 12:59:02', '2025-10-30 13:00:31'),
(547, 18, 1, 88, 'Diesel', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:26:55', '2025-10-30 14:27:46'),
(548, 18, 1, 88, 'Toll gates', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:28:45', '2025-11-10 08:59:30'),
(549, 18, 1, 88, 'Toll Gates', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:32:58', '2025-10-30 14:54:33'),
(550, 18, 1, 88, 'Toll gates', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:34:01', '2025-10-30 14:54:41'),
(551, 18, 1, 88, 'Diesel', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:34:39', '2025-10-30 14:35:34'),
(552, 18, 1, 87, 'Slasher hacksaw blade, ect', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:43:24', '2025-10-30 14:48:45'),
(553, 18, 1, 87, 'Slip Only', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:49:43', '2026-02-11 06:05:14'),
(554, 18, 1, 88, 'Diesel', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:51:57', '2025-10-30 14:53:53'),
(555, 18, 1, 87, 'Builders Mix, Cement ITE, ect', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 14:56:12', '2025-10-30 15:00:23'),
(556, 18, 1, 87, 'Trailer Rent', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 15:02:34', '2025-10-30 15:03:31'),
(557, 18, 1, 87, 'Cement, service fee - T06 Tipper, ect', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 15:06:23', '2025-10-31 06:26:39'),
(558, 18, 1, 87, 'Steal', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 15:08:12', '2025-11-04 09:30:48'),
(559, 18, 1, 87, 'Drywalling', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 15:10:54', '2025-10-31 06:29:55'),
(560, 18, 1, 87, 'Shade Cloth, Step Ladder, ect', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-30 15:12:17', '2025-10-30 15:17:30'),
(561, 30, 1, 154, 'Dusk Masks / Duram Roofcote', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 06:54:38', '2025-10-31 13:20:06'),
(562, 30, 1, 154, 'Contex Elbow / PVC Sockets / ect', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 06:56:54', '2025-10-31 13:20:10'),
(563, 30, 1, 154, 'Plumbing supplies', NULL, 28, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 06:58:27', '2025-10-31 13:20:14'),
(564, 30, 1, 154, 'Plumbing Supplies', NULL, 29, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:18:31', '2025-10-31 13:19:58'),
(565, 30, 1, 154, 'Duram Roofcote', NULL, 30, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:20:39', '2025-10-31 13:22:04'),
(566, 30, 1, 154, 'Sheeting / Building line', NULL, 31, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:22:24', '2025-10-31 13:26:03'),
(567, 30, 1, 154, 'Blade Saw / Washer / Tek Screws / Conduit ect', NULL, 32, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:24:43', '2025-10-31 13:26:18'),
(568, 30, 1, 154, 'Duram Roofcote / Mandrel / Hole Saw', NULL, 33, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:26:54', '2025-10-31 13:29:17'),
(569, 30, 1, 154, 'Cement', NULL, 34, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:28:05', '2025-10-31 13:29:27'),
(570, 30, 1, 154, 'cement', NULL, 35, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:29:43', '2025-10-31 13:31:36'),
(571, 30, 1, 154, 'Gully Trap / Rodding Eye / Straight Edge ect', NULL, 36, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:32:19', '2025-10-31 13:33:26'),
(572, 30, 1, 154, 'Silicone / Mortar / Cement', NULL, 37, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:33:55', '2025-10-31 13:43:28'),
(573, 30, 1, 154, 'Stop Tap', NULL, 38, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:43:53', '2025-10-31 13:47:15'),
(574, 30, 1, 154, 'Compacter', NULL, 39, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:47:28', '2025-10-31 13:48:24'),
(575, 30, 1, 154, 'Compacter', NULL, 40, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-10-31 13:48:40', '2025-10-31 13:49:31'),
(576, 18, 1, 89, 'Vusi Leen Geld R300', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:05:39', '2025-11-03 12:07:20'),
(577, 18, 1, 87, 'S5 Timber Sans, Service Fee', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:08:53', '2025-11-03 12:10:14'),
(578, 18, 1, 87, 'Soudal professional Sealant Gun, ect', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:11:24', '2025-11-03 12:13:28'),
(579, 18, 1, 87, 'Plumblink', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:13:46', '2025-11-03 12:19:37'),
(580, 18, 1, 87, 'Lasher spade 660MM, Lasher Garden Rake, ect', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:14:34', '2025-11-03 12:15:58'),
(581, 18, 1, 87, 'Kos Vir mense', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:16:22', '2025-11-03 12:17:27'),
(582, 18, 1, 87, 'Plaister sand (extra bestelling op RIV015)', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:21:55', '2025-11-03 12:24:42'),
(583, 18, 1, 87, 'Prepsol Engine Cleaner, ect', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:56:46', '2025-11-03 13:46:54'),
(584, 18, 1, 87, 'D-Tek Diamond Blade', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 12:59:38', '2025-11-03 13:01:35'),
(585, 30, 1, 154, 'Gully Head + Grate, Rodding Eye 110MM X 45\', ect', NULL, 41, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 13:21:52', '2025-11-03 13:23:28'),
(586, 30, 1, 154, 'Silicone 260ML Clear, Mortar Off 5Lt, ect', NULL, 42, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 13:24:21', '2025-11-03 13:25:26'),
(587, 30, 1, 154, 'Stop Tap 15MM CXC', NULL, 43, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 13:37:07', '2025-11-03 13:38:00'),
(588, 18, 1, 87, 'Cotton Waste Rags 5KG', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-03 13:48:28', '2025-11-03 13:49:31'),
(589, 18, 1, 87, '2 Brickforce 230MM (20M)', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:25:04', '2026-02-11 09:41:58'),
(590, 18, 1, 88, 'Diesel', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:27:14', '2025-11-04 09:28:00'),
(591, 18, 1, 87, 'Bosch Drill, Gloves, ect', NULL, 28, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:29:58', '2025-11-05 12:59:47'),
(592, 30, 1, 154, 'MIRA0031', NULL, 44, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:45:56', '2025-11-04 09:45:56'),
(593, 30, 1, 154, 'MIRA0032', NULL, 45, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:46:05', '2025-11-04 09:46:05'),
(594, 30, 1, 154, 'Plastersand', NULL, 46, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:46:53', '2025-11-04 09:48:32'),
(595, 30, 1, 154, 'Flexi Connector SABSFXF, Conex Coup Male, ect', NULL, 47, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:49:54', '2025-11-04 09:51:20'),
(596, 30, 1, 154, 'PVC Bend Plain, PVC Socket Reducer, ect', NULL, 48, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:52:13', '2025-11-04 09:53:08'),
(597, 30, 1, 154, 'Pipe PVC 50MM, Holderbat PVC', NULL, 49, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:56:45', '2025-11-04 09:57:35'),
(598, 30, 1, 154, 'MIRA0037', NULL, 50, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:58:07', '2025-11-04 09:58:07'),
(599, 30, 1, 154, 'MIRA0038', NULL, 51, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:58:13', '2025-11-04 09:58:13'),
(600, 30, 1, 154, 'Topping sand', NULL, 52, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-04 09:58:23', '2025-11-04 09:59:57'),
(601, 18, 1, 87, 'Brickforce NHBRC, Builders carpenters Pencil', NULL, 29, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-05 12:58:43', '2025-11-05 12:59:45'),
(602, 18, 1, 87, 'Square MTS combination', NULL, 30, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-05 13:26:49', '2025-11-05 13:28:03'),
(603, 18, 1, 87, 'Ruwag Drill Bit Metal X2', NULL, 31, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-05 13:28:46', '2025-11-05 13:29:32'),
(604, 27, 1, 122, 'WAT001', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-05 13:30:52', '2025-11-05 13:34:25'),
(605, 27, 1, 122, 'Lasher Hawk Plaster, Trowel MTS Corner', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-05 13:31:24', '2025-11-05 13:38:00'),
(606, 27, 1, 123, 'Diesel', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-05 13:39:51', '2025-11-05 13:40:22'),
(607, 27, 1, 122, 'Wheelbarrow, Shutterply, Grip Level, ect', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-05 13:41:31', '2025-11-05 13:42:34'),
(608, 27, 1, 122, 'Hexagonal Wire', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-05 13:43:29', '2025-11-10 15:26:46'),
(609, 27, 1, 122, 'Once Off Site Insurance', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:06:23', '2025-11-07 07:11:17'),
(610, 18, 1, 87, 'Once Off Site Insurance', NULL, 32, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:09:56', '2025-11-07 07:10:59'),
(611, 18, 1, 87, 'Expanded Mesh, Base Plate, ect', NULL, 33, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:14:05', '2025-11-07 07:19:47'),
(612, 18, 1, 87, 'Glove crayfish, Prestige Grout, ect', NULL, 34, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:14:22', '2025-11-07 07:16:28'),
(613, 18, 1, 87, 'AlccolinPolyester Chemical Anchor, ect', NULL, 35, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:21:16', '2025-11-07 07:22:44'),
(614, 18, 1, 87, 'Bracing Strap 30M', NULL, 36, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:23:34', '2025-11-07 07:24:25'),
(615, 18, 1, 87, 'Float Stanley', NULL, 37, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-07 07:26:48', '2025-11-07 07:27:27'),
(616, 18, 1, 161, 'Float Stanley', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:27:47', '2025-12-01 14:56:04'),
(617, 18, 1, 87, 'Dust mask', NULL, 37, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:29:31', '2025-11-07 07:30:08'),
(618, 18, 1, 87, 'Flat angle Bracket', NULL, 38, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:30:45', '2025-11-07 07:32:01'),
(619, 18, 1, 87, '38.1X1.9mm Round Tube 6M', NULL, 39, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:36:00', '2025-11-07 07:37:38'),
(620, 27, 1, 122, '38.1 X 1.9mm Round Tube 6m', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:38:40', '2025-11-07 07:39:55'),
(621, 18, 1, 88, 'Diesel', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:41:18', '2025-11-07 07:42:53'),
(622, 18, 1, 88, 'Toll Gates', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:43:24', '2025-11-07 07:44:32'),
(623, 18, 1, 87, 'USB, Reinforce Mesh', NULL, 40, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-07 07:47:46', '2025-11-07 07:49:56'),
(624, 18, 1, 87, 'Padlock, chair angles, ect', NULL, 41, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 08:44:41', '2025-11-10 15:13:25'),
(625, 18, 1, 87, 'Rammer RIV042', NULL, 42, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 08:44:57', '2025-11-12 11:39:49'),
(626, 18, 1, 87, 'Drill Bit, PVC Soldid Blend, 20mm Sans Conduit', NULL, 43, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 08:47:24', '2025-11-10 08:48:27'),
(627, 18, 1, 87, 'Midas Battery For Hyundai', NULL, 44, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 08:52:36', '2025-11-17 11:18:36'),
(628, 18, 1, 88, 'Diesel', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 08:58:07', '2025-11-10 09:03:51'),
(629, 18, 1, 87, 'Rammer Petrol', NULL, 45, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 15:00:56', '2025-11-10 15:01:59'),
(630, 18, 1, 88, 'Diesel', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 15:02:41', '2025-11-10 15:03:36'),
(631, 18, 1, 87, 'Arcal Speed 50L Cylinder 17.5KG', NULL, 46, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-10 15:05:41', '2025-11-12 12:45:43'),
(632, 27, 1, 122, 'Wire Brush, Rags A Grade 5kg, ect', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-10 15:24:26', '2025-11-10 15:28:43'),
(633, 27, 1, 123, 'Diesel', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 15:27:39', '2025-11-10 15:28:23'),
(634, 27, 1, 122, 'Midas Battery For Hyundai', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 15:29:27', '2025-11-10 15:30:30'),
(635, 27, 1, 122, 'Arcal Speed 50L Cylinder 17.5 KG', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-10 15:32:10', '2025-11-12 12:22:35'),
(636, 27, 1, 122, 'Wire Brush, Rags A Grade 5kg, ect', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 15:34:00', '2025-11-10 15:35:16'),
(637, 27, 1, 122, '10kg polyfiller, 10x masks, ect', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 15:36:15', '2025-11-12 11:06:18'),
(638, 27, 1, 122, 'Angles, ect', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-10 15:40:56', '2025-11-12 12:22:35'),
(639, 18, 1, 87, '\'Angles, ect', NULL, 47, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-10 15:43:26', '2025-11-12 12:45:43'),
(640, 18, 1, 162, 'Beacon TV Bar, Coke', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-10 15:45:18', '2025-11-18 08:40:35'),
(641, 18, 1, 87, 'Voertuig hyundai', NULL, 48, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-10 15:51:47', '2025-11-12 12:45:43'),
(642, 27, 1, 122, 'Voertuig huyndai', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-10 15:55:18', '2025-11-12 12:22:35'),
(643, 6, 1, 18, 'Deeranya POP', NULL, 60, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:12:20', '2025-11-11 14:14:45'),
(644, 6, 1, 18, 'Tyre Shine & Degreaser', NULL, 61, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:14:19', '2025-11-11 14:15:29'),
(645, 6, 1, 18, '4000 paid to P Gengan', NULL, 62, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:16:03', '2025-11-11 14:16:44'),
(646, 6, 1, 18, 'Patrick Alarms R9000 paid eft R1000 cash', NULL, 63, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:17:25', '2025-11-11 14:26:15'),
(647, 6, 1, 18, 'Clifton Float', NULL, 64, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:18:15', '2025-11-11 14:18:58'),
(648, 6, 1, 18, 'N 30mpa 19mm (x4)', NULL, 65, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:20:06', '2025-11-11 14:21:49'),
(649, 6, 1, 18, 'Roofing Steel', NULL, 66, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:22:06', '2025-11-11 14:24:49'),
(650, 6, 1, 18, 'Theunis Refund R7000 Float', NULL, 67, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:25:10', '2025-11-11 14:27:41'),
(651, 6, 1, 18, 'Patrick Alarms R1000 cash', NULL, 68, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:26:42', '2025-11-11 14:27:08'),
(652, 6, 1, 18, 'R3000 paid to Victor Aluminum', NULL, 69, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:28:09', '2025-11-11 14:29:27'),
(653, 6, 1, 18, 'PVC Ceilings For Service Centre', NULL, 70, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:29:21', '2025-11-11 14:30:49'),
(654, 6, 1, 18, 'Theunis Float R15 000', NULL, 71, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:31:08', '2025-11-11 14:31:37'),
(655, 6, 1, 18, 'Clifton Float R10 000', NULL, 72, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:31:54', '2025-11-11 14:32:23'),
(656, 6, 1, 18, 'Theunis Float R5000', NULL, 73, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:32:43', '2025-11-11 14:33:07'),
(657, 6, 1, 18, 'Topdog Toolshop Fyrefly POP only', NULL, 74, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:36:05', '2025-11-11 14:37:34'),
(658, 6, 1, 18, 'MatAir Oil Less silent Compressor', NULL, 75, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:38:19', '2025-11-11 14:39:45'),
(659, 6, 1, 18, 'coffee Machine', NULL, 76, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:40:07', '2025-11-11 14:41:21'),
(660, 6, 1, 18, 'Midea bottom Loading water Dispenser', NULL, 77, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:41:59', '2025-11-11 14:43:37'),
(661, 6, 1, 18, 'aluminum & glass', NULL, 78, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:51:24', '2025-11-11 14:53:03'),
(662, 6, 1, 18, 'POP only', NULL, 79, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:55:47', '2025-11-11 14:57:55'),
(663, 6, 1, 18, 'Midea Bottom Loading Water Dispenser', NULL, 80, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 14:57:49', '2025-11-11 14:58:45'),
(664, 6, 1, 18, 'Paid Divern R24758', NULL, 81, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 15:00:43', '2025-11-11 15:01:28'),
(665, 6, 1, 18, 'Casablanca, Twisted, Classic', NULL, 82, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 15:02:30', '2025-11-11 15:03:27'),
(666, 6, 1, 18, 'TV License, TVs', NULL, 83, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 15:04:22', '2025-11-11 15:05:20'),
(667, 6, 1, 18, 'Christmas Decor', NULL, 84, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 15:06:33', '2025-11-11 15:11:57'),
(668, 6, 1, 18, 'Shelvs', NULL, 85, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 15:13:22', '2025-11-12 13:04:47'),
(669, 6, 1, 18, 'Theunis Float', NULL, 86, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 15:15:45', '2025-11-11 15:17:13'),
(670, 6, 1, 18, 'Theunis Float', NULL, 87, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 15:17:10', '2025-11-11 15:17:44'),
(671, 6, 1, 18, 'theunis Float', NULL, 88, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-11 15:17:56', '2025-11-11 15:18:31'),
(672, 27, 1, 123, 'Toll Gate', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:06:38', '2025-11-18 08:54:35'),
(673, 27, 1, 123, 'Sanding paper, Gloves', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-12 11:08:05', '2025-11-12 11:08:09'),
(674, 27, 1, 122, 'Sandding paper, Gloves', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:08:27', '2025-11-12 11:09:41'),
(675, 27, 1, 122, 'Ruus-Con , Paint Brush', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:10:18', '2025-11-12 11:11:16'),
(676, 27, 1, 123, 'Olie vir Bakkie', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:12:05', '2025-11-12 11:13:38'),
(677, 27, 1, 123, 'Diesel', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:14:14', '2025-11-12 11:15:02'),
(678, 27, 1, 123, 'Toll gates', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:15:31', '2025-11-12 11:16:42'),
(679, 27, 1, 122, 'Brush, Hammer Steel, Concrete Nails', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:18:47', '2025-11-12 11:19:40'),
(680, 18, 1, 87, 'Rammer refund of deposit paid RIV042', NULL, 49, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:35:38', '2025-11-12 11:39:30'),
(681, 18, 1, 88, 'toll Gates', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:40:43', '2025-11-12 11:41:34'),
(682, 18, 1, 88, 'Toll Gates', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:44:28', '2025-11-12 11:45:22'),
(683, 18, 1, 87, 'Cement, Timber Sans, ect', NULL, 50, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:46:42', '2025-11-12 12:00:43'),
(684, 18, 1, 87, 'Packing Tape Clear tape', NULL, 51, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:49:20', '2025-11-12 11:52:26'),
(685, 18, 1, 87, 'Chissel Flat', NULL, 52, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 11:53:31', '2025-11-12 12:08:30'),
(686, 18, 1, 88, 'Diesel', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:01:20', '2025-11-12 12:02:18'),
(687, 18, 1, 88, 'Toll Gates', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:02:28', '2025-11-12 12:03:03'),
(688, 18, 1, 88, 'Toll Gates', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:03:19', '2025-11-12 12:04:06'),
(689, 18, 1, 87, '12mm Staal Boor', NULL, 53, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-12 12:07:19', '2025-11-12 12:45:43'),
(690, 18, 1, 87, '12mm Staal Boor', NULL, 54, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-12 12:10:35', '2025-11-12 12:45:43'),
(691, 18, 1, 87, 'Drill Chuck Key, M10x30mm boute, M10 Nuts', NULL, 55, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-12 12:12:28', '2025-11-12 12:45:43'),
(692, 27, 1, 122, '12mm Staal Boor', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-12 12:13:32', '2025-11-12 12:22:35'),
(693, 27, 1, 122, '12mm Staal Boor', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-12 12:14:30', '2025-11-12 12:22:35'),
(694, 27, 1, 122, 'Drill Chucky Key, M10x30mm Boute, M10 Nuts', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-12 12:15:48', '2025-11-12 12:22:35'),
(695, 27, 1, 124, 'Arcal Spees 50L Cylinder 17.5 KG', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:18:59', '2025-11-12 12:19:44'),
(696, 27, 1, 124, 'Angles, ect', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:19:58', '2025-11-12 12:21:00'),
(697, 27, 1, 124, 'Voertuig Hyundai', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:21:21', '2025-11-12 12:21:59'),
(698, 27, 1, 124, '12mm Staal Boor', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:23:28', '2025-11-12 12:24:27'),
(699, 27, 1, 124, '12mm Staal Boor', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:24:41', '2025-11-12 12:25:24'),
(700, 27, 1, 124, 'Drill Chucky Key, M10X30mm Boute, M10 Nuts', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:26:02', '2025-11-12 12:26:36'),
(701, 18, 1, 163, 'Arcal Speed 50L Cylinder 17.5 KG', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:46:59', '2025-11-12 12:47:46'),
(702, 18, 1, 163, 'Angles, ect', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:48:08', '2025-11-12 12:49:26'),
(703, 18, 1, 163, 'Voertuig Hyundai', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:49:42', '2025-11-12 12:50:19'),
(704, 18, 1, 163, '12mm Staal Boor', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:50:49', '2025-11-12 12:51:49'),
(705, 18, 1, 163, '12mm Staal boor', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:51:59', '2025-11-12 12:52:30'),
(706, 18, 1, 163, 'Drill Chuck Key, M10x30mm Boute, M10 Nuts', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:53:08', '2025-11-12 12:53:45'),
(707, 18, 1, 87, 'POP Carpenters', NULL, 53, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 12:54:57', '2025-11-12 12:56:47'),
(708, 6, 1, 18, 'KRHN Agency Water POP', NULL, 89, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-12 13:00:29', '2025-11-12 13:02:35'),
(709, 18, 1, 87, 'Shutterply, Gypsum Board, Drywalling', NULL, 54, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 13:21:37', '2025-11-17 10:11:08'),
(710, 18, 1, 87, 'RIV050', NULL, 55, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-13 13:21:43', '2025-11-13 13:22:39'),
(711, 18, 1, 87, 'Hammer Drill, Dust Mask, ect', NULL, 56, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 13:22:34', '2025-11-13 13:31:07'),
(712, 18, 1, 88, 'Toll Gates', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 13:36:39', '2025-11-17 11:05:59'),
(713, 18, 1, 87, 'Ceiling bord(17), Corner Streeos(10)', NULL, 57, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 13:37:49', '2025-11-17 10:03:51'),
(714, 27, 1, 123, 'Diesel', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 13:38:49', '2025-11-13 13:40:32'),
(715, 27, 1, 122, 'Sandpaper, Sponge, Dust Mask', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 13:40:59', '2025-11-13 13:41:49'),
(716, 18, 1, 88, 'Diesel', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 14:02:45', '2025-11-13 14:03:26'),
(717, 18, 1, 162, 'BD Pie Creamy Chicken', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 14:04:56', '2025-11-13 14:06:54'),
(718, 18, 1, 162, 'Food', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 14:10:45', '2025-11-13 14:11:52'),
(719, 18, 1, 162, 'W R10 000', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 14:15:56', '2025-11-25 12:12:11');
INSERT INTO `board_items` (`id`, `board_id`, `company_id`, `group_id`, `title`, `description`, `position`, `status_label`, `assigned_to`, `priority`, `progress`, `due_date`, `start_date`, `end_date`, `tags`, `archived`, `subitem_count`, `created_by`, `created_at`, `updated_at`) VALUES
(720, 18, 1, 162, 'W R3000', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 14:16:04', '2025-11-25 12:12:24'),
(721, 18, 1, 162, 'Food', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 14:16:20', '2025-11-18 08:38:46'),
(722, 18, 1, 162, 'Food', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 14:17:37', '2025-11-18 08:38:52'),
(723, 18, 1, 88, 'Toll Gates', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 16:02:36', '2025-11-13 16:03:15'),
(724, 18, 1, 87, 'Rammer', NULL, 58, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-13 16:05:52', '2025-11-17 10:02:59'),
(725, 27, 1, 123, 'Toll gates', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-13 16:11:36', '2025-11-13 16:12:12'),
(726, 18, 1, 88, 'Diesel', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-14 06:42:32', '2025-11-14 06:47:33'),
(727, 18, 1, 88, 'Diesel', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-17 10:04:26', '2025-11-17 10:05:38'),
(728, 18, 1, 87, 'Timber SAP', NULL, 58, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-17 10:06:08', '2025-11-17 10:08:47'),
(729, 18, 1, 88, 'Diesel', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-17 10:09:29', '2025-11-17 10:10:13'),
(730, 18, 1, 87, 'Timber SAB', NULL, 59, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-17 10:17:15', '2025-11-18 08:49:17'),
(731, 27, 1, 123, 'Diesel', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-17 10:19:28', '2025-11-17 10:21:23'),
(732, 18, 1, 163, 'Petrol', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-17 11:36:08', '2025-11-17 11:38:03'),
(733, 27, 1, 124, 'Petrol', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-17 11:38:35', '2025-11-17 11:39:07'),
(734, 18, 1, 162, 'Food', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-18 08:38:54', '2025-11-18 08:39:40'),
(735, 18, 1, 87, 'Safety management File', NULL, 60, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-18 08:51:04', '2025-11-18 08:53:24'),
(736, 27, 1, 123, 'Diesel', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-18 08:54:38', '2025-11-18 08:55:39'),
(737, 27, 1, 122, 'Sandpaper, 5kg Putty, Scraper', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-18 08:56:13', '2025-11-18 08:59:53'),
(738, 27, 1, 122, 'safety Management File', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-18 08:57:27', '2025-11-18 08:58:42'),
(739, 27, 1, 122, 'Sandpaper, 5kg Putty, Scraper', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-18 08:59:01', '2025-11-18 08:59:47'),
(740, 27, 1, 122, 'Safety goggle, Safety Eyeglass wear', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:30:50', '2025-11-21 04:31:49'),
(741, 27, 1, 123, 'Diesel', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:32:21', '2025-11-21 04:33:26'),
(742, 18, 1, 165, 'Stanly Float', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:35:07', '2025-11-21 04:35:50'),
(743, 18, 1, 88, 'Diesel', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:36:33', '2025-11-21 04:37:18'),
(744, 18, 1, 88, 'Rhinoglide 10kg', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-21 04:38:12', '2025-11-21 04:38:20'),
(745, 18, 1, 87, 'Rhinoglidge', NULL, 61, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:38:32', '2025-11-21 04:39:46'),
(746, 18, 1, 88, 'Diesel', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:40:09', '2025-11-21 04:41:05'),
(747, 18, 1, 87, 'Sandpaper', NULL, 62, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:41:31', '2025-11-21 04:42:53'),
(748, 18, 1, 87, 'Dust Mask', NULL, 63, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:44:12', '2025-11-21 04:44:55'),
(749, 18, 1, 87, 'Rollers, paint brush, scraper, ect', NULL, 64, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:45:33', '2025-11-21 04:46:28'),
(750, 18, 1, 87, 'Aluminim', NULL, 65, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:49:01', '2025-11-21 05:03:18'),
(751, 18, 1, 87, 'Carpenters Payment', NULL, 66, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:51:16', '2025-11-24 08:05:20'),
(752, 18, 1, 163, 'Paint', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:54:02', '2025-11-21 04:56:04'),
(753, 18, 1, 163, 'Mihindra', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:56:55', '2025-11-21 04:58:34'),
(754, 27, 1, 124, 'Paint', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 04:59:28', '2025-11-21 05:00:45'),
(755, 27, 1, 124, 'Mahindra', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-21 05:01:09', '2025-11-21 05:02:19'),
(756, 18, 1, 87, 'Rhinoglide 20kg', NULL, 67, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-24 07:43:46', '2025-11-24 07:50:48'),
(757, 18, 1, 87, 'Building supplies', NULL, 68, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-24 07:43:57', '2025-11-28 08:33:27'),
(758, 18, 1, 165, 'Stanly Float', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-24 07:44:26', '2025-11-24 07:45:12'),
(759, 18, 1, 87, 'Carpenters Payment', NULL, 69, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-24 07:46:57', '2025-11-24 07:48:21'),
(760, 18, 1, 87, 'Building supplies', NULL, 70, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-24 07:51:29', '2025-11-24 07:53:52'),
(761, 18, 1, 88, 'Diesel', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-24 07:54:28', '2025-11-24 08:01:10'),
(762, 18, 1, 88, 'Toll Gates', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-24 08:01:29', '2025-11-24 08:02:32'),
(763, 18, 1, 88, 'Toll Gates', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-24 08:02:51', '2025-11-24 08:03:40'),
(764, 27, 1, 164, 'Medical Expenses', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:13:50', '2025-11-25 12:17:19'),
(765, 27, 1, 164, 'medical Expenses', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:16:39', '2025-11-25 12:17:57'),
(766, 27, 1, 164, 'Medical Expenses', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:18:36', '2025-11-25 12:19:29'),
(767, 27, 1, 164, 'Medical Expenses', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:19:52', '2025-11-25 12:20:42'),
(768, 18, 1, 87, '1 Liter Oil. RIV065', NULL, 71, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-11-25 12:29:38', '2026-02-24 06:23:27'),
(769, 18, 1, 88, 'Diesel', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:30:24', '2025-11-25 12:31:46'),
(770, 18, 1, 88, 'Delo Ultra Gold (Diesel)', NULL, 28, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:32:52', '2025-11-25 12:33:39'),
(771, 18, 1, 165, 'Vetkoeke, tea', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:33:58', '2025-11-25 12:34:59'),
(772, 18, 1, 88, 'Toll Gates', NULL, 29, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:35:26', '2025-11-25 12:35:57'),
(773, 18, 1, 88, 'Toll Gates', NULL, 30, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:36:16', '2025-11-25 12:36:45'),
(774, 18, 1, 88, 'Toll Gates', NULL, 31, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:37:02', '2025-12-02 10:36:43'),
(775, 18, 1, 88, 'RIV068', NULL, 32, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:38:17', '2025-12-02 10:36:48'),
(776, 18, 1, 87, 'Lintel, timber sans, ect', NULL, 72, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:39:44', '2026-02-11 06:30:41'),
(777, 27, 1, 122, 'Putty, Sanding Belt', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:42:44', '2025-11-25 12:44:54'),
(778, 27, 1, 122, 'Polyfilla, Window Putty, ect', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:46:34', '2025-12-02 11:26:24'),
(779, 27, 1, 164, 'R2000 voorskot op Salaris', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:49:08', '2025-11-25 12:49:52'),
(780, 27, 1, 164, 'Energie drink', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:52:36', '2025-11-25 12:53:21'),
(781, 27, 1, 164, 'little egypt', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 12:58:57', '2025-11-25 13:00:47'),
(782, 27, 1, 122, 'Ski rope', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-25 13:02:35', '2025-11-25 13:03:26'),
(783, 27, 1, 122, 'Crack Filler, putty, ect', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:21:40', '2025-11-28 08:22:31'),
(784, 27, 1, 122, 'Paint', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:23:37', '2025-11-28 08:24:23'),
(785, 27, 1, 122, 'Uber', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:26:32', '2025-11-28 08:27:13'),
(786, 27, 1, 122, 'Uber', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:27:17', '2025-11-28 08:27:41'),
(787, 27, 1, 122, 'Builders Supplies + Uber', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:28:17', '2025-11-28 09:04:07'),
(788, 18, 1, 87, 'Shaft Packaging Pallet Wrap, Lintel, ect', NULL, 73, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:32:14', '2025-11-28 08:33:37'),
(789, 18, 1, 88, 'Diesel', NULL, 33, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:36:55', '2025-11-28 08:37:45'),
(790, 18, 1, 88, 'Toll Gates', NULL, 34, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:37:56', '2025-11-28 08:38:30'),
(791, 18, 1, 87, 'Plasterbond & Key, Dust Musk, ect', NULL, 74, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:39:02', '2025-11-28 08:40:47'),
(792, 18, 1, 87, 'Ecolight, Lock set, ect', NULL, 75, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:41:46', '2025-11-28 08:42:30'),
(793, 18, 1, 87, 'Chain Hoist (Deposit paid)', NULL, 76, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:43:47', '2025-11-28 08:48:49'),
(794, 18, 1, 87, 'Chain Block- 3M', NULL, 77, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:47:26', '2025-11-28 08:49:55'),
(795, 18, 1, 87, 'Cleaning Thinners, silicone, ect', NULL, 78, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-11-28 08:59:18', '2025-11-28 09:00:41'),
(796, 30, 1, 154, 'Cement/ Bousand/ River Gravel (Copy)', NULL, 53, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-11-28 20:21:51', '2025-11-28 20:22:10'),
(797, 18, 1, 88, 'Diesel', NULL, 35, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:36:10', '2025-12-02 10:39:44'),
(798, 18, 1, 87, 'Plaster Sand', NULL, 79, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:37:11', '2025-12-02 10:38:25'),
(799, 18, 1, 87, 'Econo Straight Edge', NULL, 80, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:40:15', '2025-12-02 10:43:21'),
(800, 18, 1, 87, 'Glasss Float, Builders Socket, ect', NULL, 81, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:41:43', '2025-12-02 10:43:17'),
(801, 18, 1, 87, '20 Bags of Cement 1 Dec', NULL, 82, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:44:09', '2025-12-09 07:59:10'),
(802, 18, 1, 87, 'Paint', NULL, 83, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:44:39', '2025-12-02 10:45:23'),
(803, 18, 1, 88, 'Diesel', NULL, 36, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:46:05', '2025-12-02 11:12:27'),
(804, 18, 1, 88, 'Toll gates', NULL, 37, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:47:28', '2025-12-02 11:12:29'),
(805, 18, 1, 88, 'Toll gates', NULL, 38, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:48:09', '2025-12-02 11:12:43'),
(806, 18, 1, 88, 'Toll Gates', NULL, 39, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:49:07', '2025-12-02 11:12:44'),
(807, 18, 1, 88, 'Toll Gates', NULL, 40, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:50:24', '2025-12-02 11:13:07'),
(808, 18, 1, 88, 'Toll Gates', NULL, 41, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:50:39', '2025-12-02 11:13:15'),
(809, 18, 1, 88, 'Toll gates', NULL, 42, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 10:51:00', '2025-12-02 11:13:24'),
(810, 18, 1, 88, 'Toll Gates', NULL, 43, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 11:09:04', '2025-12-02 11:13:32'),
(811, 18, 1, 88, 'Toll Gates', NULL, 44, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 11:09:23', '2025-12-02 11:13:41'),
(812, 18, 1, 88, 'Toll Gates', NULL, 45, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 11:09:40', '2025-12-02 11:13:49'),
(813, 18, 1, 88, 'Toll Gates', NULL, 46, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 11:09:54', '2025-12-02 11:14:16'),
(814, 18, 1, 88, 'Toll Gates', NULL, 47, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 11:10:07', '2025-12-02 11:14:04'),
(815, 18, 1, 88, 'Petrol', NULL, 48, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 11:14:37', '2025-12-02 11:15:10'),
(816, 27, 1, 122, '225mm Eezypile set, extension rod, ect', NULL, 28, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 11:19:22', '2025-12-02 11:20:29'),
(817, 27, 1, 164, 'Meat', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-02 11:37:14', '2025-12-02 11:40:25'),
(818, 33, 1, 169, 'Lone 05/12/25', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:34:45', '2026-03-01 17:13:09'),
(819, 33, 1, 169, 'Boneses', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:35:42', '2025-12-19 17:18:59'),
(820, 33, 1, 170, 'Glas', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-12-04 09:36:13', '2025-12-04 16:59:21'),
(821, 33, 1, 170, 'Verf', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:36:47', '2026-02-28 05:42:37'),
(822, 33, 1, 170, 'masjiene huur', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:37:45', '2026-02-28 05:43:48'),
(823, 33, 1, 170, 'carpenter', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:38:36', '2025-12-17 12:29:00'),
(824, 33, 1, 170, 'Glas man', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:38:51', '2026-02-05 17:34:05'),
(825, 33, 1, 170, 'P&G', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:39:41', '2026-03-01 17:13:20'),
(826, 33, 1, 170, 'teels en tile', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:40:14', '2026-02-05 17:34:20'),
(827, 33, 1, 171, 'Verf', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:41:32', '2026-02-21 05:14:20'),
(828, 33, 1, 171, 'P&G\'s', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:41:51', '2026-03-01 17:13:45'),
(829, 33, 1, 171, 'Verwers', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:42:06', '2026-01-26 06:11:45'),
(830, 33, 1, 175, 'Winston salaris', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:45:41', '2026-02-09 21:59:43'),
(831, 33, 1, 175, 'Adriaan Salaris', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:45:58', '2026-02-21 05:15:03'),
(832, 33, 1, 175, 'Catherine en Adriaan Salaris', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:46:44', '2026-03-01 17:13:55'),
(833, 33, 1, 175, 'Hennie', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-12-04 09:47:10', '2026-01-17 10:27:38'),
(834, 33, 1, 175, 'Bakkies reg maak', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:47:26', '2026-02-13 17:10:34'),
(835, 33, 1, 175, 'Amarok', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-12-04 09:47:40', '2026-01-17 10:27:38'),
(836, 33, 1, 175, 'kar', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-04 09:49:37', '2026-02-10 05:55:11'),
(837, 18, 1, 88, 'Toll Gates', NULL, 49, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-05 06:51:05', '2025-12-05 06:51:50'),
(838, 18, 1, 88, 'Toll Gates', NULL, 50, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-05 06:52:16', '2025-12-05 06:53:22'),
(839, 18, 1, 88, 'Plaster Sand, IBR 0.47mm 6m', NULL, 51, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-05 06:56:07', '2025-12-05 06:56:12'),
(840, 18, 1, 87, 'Plaster Sand, IBR 0.47mm 6m', NULL, 84, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-05 06:56:54', '2025-12-05 06:58:46'),
(841, 18, 1, 88, 'Diesel', NULL, 51, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-05 06:59:15', '2025-12-05 07:00:34'),
(842, 18, 1, 88, 'Toll Gates', NULL, 51, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-05 07:00:40', '2025-12-05 07:01:12'),
(843, 18, 1, 88, 'Diesel', NULL, 52, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-05 07:01:19', '2025-12-05 07:02:07'),
(844, 33, 1, 175, 'Hyundai', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-12-07 13:05:37', '2026-01-17 10:27:38'),
(845, 27, 1, 122, '4m x Plaster Sand (R1800-900)', NULL, 29, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-09 07:30:50', '2025-12-09 07:32:26'),
(846, 27, 1, 122, 'Putty packet, dust mask, silicone gun, ect', NULL, 30, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-09 07:32:04', '2025-12-14 09:07:50'),
(847, 27, 1, 122, 'Plaster Sand, Royal white wash, ect', NULL, 31, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-09 07:34:51', '2025-12-14 09:09:18'),
(848, 27, 1, 123, 'Toll Gates', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:36:27', '2025-12-09 07:37:06'),
(849, 27, 1, 122, 'Foam', NULL, 32, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-09 07:37:39', '2025-12-14 09:10:48'),
(850, 27, 1, 123, 'Toll Gates', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:40:07', '2025-12-09 07:41:29'),
(851, 27, 1, 123, 'Toll Gates', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:41:00', '2025-12-09 07:41:39'),
(852, 27, 1, 122, 'RIV027', NULL, 33, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:42:16', '2025-12-14 09:06:14'),
(853, 27, 1, 122, 'RIV028', NULL, 34, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:42:22', '2025-12-14 09:06:21'),
(854, 27, 1, 122, 'WAT029 plaster primer', NULL, 35, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:43:09', '2025-12-14 09:06:26'),
(855, 27, 1, 122, 'Putty Bag, Sander Mouse Pad, ect', NULL, 36, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:43:36', '2025-12-09 07:44:45'),
(856, 27, 1, 123, 'Toll Gates', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:45:06', '2025-12-09 07:45:31'),
(857, 27, 1, 123, 'Diesel', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:45:51', '2025-12-09 07:46:30'),
(858, 27, 1, 123, 'Toll Gates', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:46:43', '2025-12-09 07:47:08'),
(859, 27, 1, 122, 'Paint brushes, rollers, ect', NULL, 37, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:47:39', '2025-12-09 07:48:28'),
(860, 27, 1, 122, 'Stepladder', NULL, 38, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 07:48:43', '2025-12-09 08:30:42'),
(861, 27, 1, 122, 'Pollyfilla paint brushes, ect', NULL, 39, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-09 07:49:14', '2025-12-09 08:24:36'),
(862, 18, 1, 87, 'Klenz ATF DEXIII 500ml, cable ties', NULL, 85, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:02:05', '2025-12-09 08:07:30'),
(863, 18, 1, 87, 'Toolhire deposits', NULL, 86, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:02:42', '2025-12-09 08:10:58'),
(864, 18, 1, 87, 'Roofcoat', NULL, 87, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:03:04', '2026-02-11 06:32:04'),
(865, 18, 1, 87, 'Lacquer Thinners', NULL, 88, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:04:29', '2026-02-11 06:33:28'),
(866, 18, 1, 87, 'Plaster Sand', NULL, 89, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:07:47', '2026-02-11 06:32:19'),
(867, 18, 1, 88, 'Toll Gates', NULL, 53, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:11:25', '2025-12-09 08:12:06'),
(868, 27, 1, 123, 'Petrol', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:12:45', '2025-12-09 08:13:20'),
(869, 18, 1, 88, 'Diesel', NULL, 54, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:14:50', '2025-12-09 08:15:31'),
(870, 18, 1, 87, 'Timber SAP', NULL, 90, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:17:38', '2025-12-09 08:19:07'),
(871, 18, 1, 87, 'Eureka Cut SCRW, Eureka D/Wall', NULL, 91, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:19:57', '2025-12-09 08:20:59'),
(872, 27, 1, 122, 'Painter Payments (1)', NULL, 40, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-09 08:22:39', '2026-01-16 13:54:13'),
(873, 27, 1, 122, 'Pollyfilla Paint Brushes, ect', NULL, 41, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:23:54', '2025-12-09 08:24:33'),
(874, 27, 1, 122, 'Painter Payments (2)', NULL, 42, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-09 08:25:02', '2026-01-16 13:54:02'),
(875, 27, 1, 164, 'Voorskot', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-09 08:26:31', '2025-12-09 08:26:54'),
(876, 27, 1, 164, 'Volgende', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:26:50', '2025-12-09 08:26:50'),
(877, 27, 1, 164, 'Voorskot', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 08:27:01', '2025-12-09 08:28:46'),
(878, 27, 1, 164, 'Bakkie Parts', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 10:57:43', '2025-12-19 14:46:41'),
(879, 18, 1, 176, 'Mahindra Parts', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 11:00:39', '2025-12-09 11:01:48'),
(880, 18, 1, 87, 'USB, Masonry nails, River Sand', NULL, 92, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 12:34:02', '2025-12-09 12:35:23'),
(881, 18, 1, 88, 'Diesel Rammer', NULL, 55, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-09 12:36:37', '2025-12-09 12:37:18'),
(882, 18, 1, 87, 'Plaster Sand', NULL, 93, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-11 08:20:06', '2025-12-11 08:21:04'),
(883, 18, 1, 87, 'PPC Surecement', NULL, 94, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-11 08:21:55', '2025-12-11 08:22:44'),
(884, 27, 1, 124, 'Electrician for Mahindra', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-11 10:37:40', '2025-12-11 10:43:47'),
(885, 27, 1, 124, 'Filters for Mahindra', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-11 10:38:05', '2025-12-11 10:40:04'),
(886, 18, 1, 163, 'Electrician for Mahindra', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-11 10:40:22', '2025-12-11 10:42:57'),
(887, 18, 1, 163, 'Filters for Mahindra', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-11 10:40:36', '2025-12-11 10:41:30'),
(888, 27, 1, 124, 'Office Furniture', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-13 13:13:20', '2025-12-13 13:14:31'),
(889, 18, 1, 163, 'Office Furniture', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-13 13:15:00', '2025-12-13 13:16:35'),
(890, 18, 1, 163, 'Office Furniture', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-13 13:17:09', '2025-12-13 13:18:13'),
(891, 27, 1, 124, 'Office Furniture', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-13 13:18:32', '2025-12-13 13:19:07'),
(892, 18, 1, 87, 'Aluminium & Glass', NULL, 95, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-14 08:42:29', '2025-12-14 08:44:50'),
(893, 18, 1, 87, 'River Sand', NULL, 95, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:45:23', '2025-12-14 08:46:49'),
(894, 18, 1, 87, 'Polycell Polyfilla, floor rolls', NULL, 96, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:47:15', '2025-12-14 08:48:09'),
(895, 18, 1, 88, 'Petrol', NULL, 56, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:48:39', '2025-12-14 08:49:21'),
(896, 18, 1, 88, 'Diesel', NULL, 57, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:49:41', '2025-12-14 08:50:15'),
(897, 18, 1, 88, '5lt Diesel Engine Oil', NULL, 58, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:50:49', '2025-12-14 08:51:19'),
(898, 18, 1, 88, 'Diesel', NULL, 59, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:51:33', '2025-12-14 08:52:13'),
(899, 18, 1, 87, 'Polycell Polyfilla, tape, masonry nails.', NULL, 97, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:53:12', '2025-12-14 08:54:13'),
(900, 18, 1, 87, 'Gloves', NULL, 98, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:54:31', '2025-12-14 08:55:10'),
(901, 18, 1, 87, 'Plaster Sand', NULL, 99, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:55:27', '2025-12-14 08:56:19'),
(902, 18, 1, 87, 'Aluminum & Glass', NULL, 100, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:56:56', '2025-12-14 08:58:24'),
(903, 18, 1, 87, 'Boom Lift (updated invoice)', NULL, 101, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 08:59:50', '2025-12-14 09:03:23'),
(904, 27, 1, 122, 'Putty packet, Dust Mask, Silicone gun, ect', NULL, 43, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:07:02', '2025-12-14 09:07:43'),
(905, 27, 1, 122, 'Plaster sand,Royal White wash, ect', NULL, 44, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:08:19', '2025-12-14 09:09:14'),
(906, 27, 1, 122, 'Foam', NULL, 45, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:10:03', '2025-12-14 09:10:44'),
(907, 27, 1, 122, 'Uber Total', NULL, 46, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:11:17', '2025-12-14 09:12:51'),
(908, 27, 1, 123, 'Diesel', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:12:03', '2025-12-14 09:12:42'),
(909, 27, 1, 123, 'Paint (@paints) WAT040', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-14 09:13:18', '2025-12-14 09:13:36'),
(910, 27, 1, 122, 'Paint', NULL, 47, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:13:57', '2025-12-14 09:32:23'),
(911, 27, 1, 122, 'Putty Packet white 500G x2, Paint Brush x2, ect', NULL, 48, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:15:06', '2025-12-14 09:15:51'),
(912, 27, 1, 122, '50x228x6m , Roller', NULL, 49, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:16:56', '2025-12-14 09:17:38'),
(913, 27, 1, 122, 'Boom Lift', NULL, 50, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:21:01', '2025-12-14 09:22:54'),
(914, 27, 1, 122, 'Aluminim Welder (Subcontractor)', NULL, 51, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:25:24', '2025-12-16 14:19:40'),
(915, 27, 1, 122, 'Aluminim Welder (Subcontractor)', NULL, 52, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:26:45', '2025-12-14 09:27:37'),
(916, 27, 1, 122, 'Aluminim Welder (Subcontractor)', NULL, 53, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:28:03', '2025-12-14 09:28:51'),
(917, 18, 1, 88, 'Mahindra Service', NULL, 60, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 09:37:41', '2025-12-14 09:39:30'),
(918, 18, 1, 87, 'Glass', NULL, 102, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-14 10:02:32', '2025-12-14 10:03:33'),
(919, 36, 1, 180, 'Cutting Wheel Steel', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-15 06:10:14', '2025-12-16 14:35:16'),
(920, 38, 1, 186, 'takealot order', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-15 15:41:56', '2026-01-25 15:24:35'),
(921, 38, 1, 187, 'Bricks cement and sand', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-15 15:43:28', '2026-01-25 14:02:19'),
(922, 38, 1, 187, 'labour', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-15 15:43:41', '2025-12-15 15:43:45'),
(923, 38, 1, 188, 'Budget', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-15 15:44:17', '2026-01-25 14:01:55'),
(924, 38, 1, 195, 'Painting and painter', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-15 15:46:28', '2026-01-25 14:02:09'),
(925, 38, 1, 196, 'Skirtings', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-15 15:47:12', '2025-12-15 15:47:17'),
(926, 38, 1, 196, 'Labour on skirtings and door hang', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-15 15:47:30', '2025-12-15 15:47:35'),
(927, 38, 1, 186, 'Plumbing and electrical piping', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2025-12-15 15:47:59', '2025-12-15 15:48:04'),
(928, 18, 1, 88, 'diesel', NULL, 61, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:04:47', '2025-12-16 14:05:23'),
(929, 18, 1, 87, 'Tiles', NULL, 103, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:06:02', '2025-12-16 14:09:49'),
(930, 18, 1, 87, 'Fluted Nails, Sandpaper, ect', NULL, 104, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:09:12', '2025-12-16 14:10:17'),
(931, 18, 1, 87, 'floor Grinding Machines', NULL, 105, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:10:53', '2025-12-16 14:12:53'),
(932, 18, 1, 88, 'Diesel', NULL, 62, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:15:07', '2025-12-16 14:15:47'),
(933, 18, 1, 87, 'Dust Masks.', NULL, 106, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:16:50', '2025-12-16 14:18:36'),
(934, 27, 1, 122, 'Paint Brushes', NULL, 54, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:20:09', '2025-12-16 14:21:21'),
(935, 27, 1, 123, 'Diesel', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:21:45', '2025-12-16 14:22:48'),
(936, 36, 1, 180, 'Cutting Wheel Steel', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:23:25', '2025-12-16 14:52:04'),
(937, 36, 1, 180, 'Cement x5', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:27:45', '2025-12-16 14:28:36'),
(938, 36, 1, 180, 'Cement', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:29:17', '2025-12-16 14:31:58'),
(939, 36, 1, 180, 'Levels', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-16 14:32:29', '2025-12-16 14:33:41'),
(940, 36, 1, 180, 'Cutting Wheel Steel', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2025-12-16 14:34:10', '2025-12-16 14:52:10'),
(941, 18, 1, 87, 'Carpenters Payment', NULL, 107, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-17 08:52:57', '2025-12-17 08:53:26'),
(942, 18, 1, 89, 'Local Laborers', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-17 08:53:51', '2025-12-17 08:54:24'),
(943, 27, 1, 164, 'Bakkie Kostes', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-17 08:55:06', '2025-12-17 08:55:52'),
(944, 27, 1, 122, 'Cement, Plastering, Trowel, ect', NULL, 55, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-17 08:57:13', '2025-12-17 08:57:54'),
(945, 18, 1, 87, 'Paint Brushes & Rollers', NULL, 108, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-17 08:59:02', '2025-12-17 08:59:53'),
(946, 27, 1, 164, 'Bakkie Parts', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-19 14:43:13', '2025-12-19 14:48:38'),
(947, 27, 1, 164, 'Bakkie Kostes', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2025-12-19 15:29:46', '2025-12-19 15:30:22'),
(948, 18, 1, 87, 'Duck Tape', NULL, 109, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-06 09:22:05', '2026-01-06 09:23:10'),
(949, 18, 1, 87, 'Trailer Hire (Renting)', NULL, 110, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-06 09:23:36', '2026-01-06 09:24:32'),
(950, 18, 1, 88, 'diesel', NULL, 63, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-06 09:25:06', '2026-01-06 09:26:01'),
(951, 18, 1, 87, 'Cleaning Thinners', NULL, 111, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-06 09:26:27', '2026-01-06 09:27:49'),
(952, 18, 1, 89, 'Labourers', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-06 09:34:17', '2026-01-06 09:34:53'),
(953, 18, 1, 89, 'painter', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-06 09:34:59', '2026-01-06 09:35:27'),
(954, 18, 1, 89, 'JMPD', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-06 09:35:34', '2026-01-06 09:36:05'),
(955, 18, 1, 89, '2 Laborers & 1 Painter', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-06 09:36:33', '2026-01-13 09:24:26'),
(956, 18, 1, 88, 'Diesel', NULL, 64, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:31:21', '2026-01-12 11:31:56'),
(957, 18, 1, 88, 'Diesel', NULL, 65, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:32:10', '2026-01-12 11:32:55'),
(958, 18, 1, 88, 'Toll gates', NULL, 66, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:33:11', '2026-01-12 11:38:49'),
(959, 18, 1, 87, '5 bags cement, Building sand, sand paper, ect', NULL, 112, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:34:16', '2026-01-12 11:35:29'),
(960, 18, 1, 87, 'Coastal Hire Deposit for machine', NULL, 113, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:36:37', '2026-01-13 07:49:48'),
(961, 18, 1, 88, 'Diesel', NULL, 67, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:36:53', '2026-01-12 11:37:23'),
(962, 18, 1, 88, 'Toll Gates', NULL, 68, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:37:45', '2026-01-12 11:39:27'),
(963, 18, 1, 88, 'Toll gates', NULL, 69, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:37:50', '2026-01-12 11:39:29'),
(964, 18, 1, 87, 'Spray Foam', NULL, 114, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:39:56', '2026-01-12 11:40:58'),
(965, 18, 1, 88, 'toll gates', NULL, 70, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:41:29', '2026-01-12 11:42:57'),
(966, 18, 1, 88, 'Toll Gates', NULL, 71, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:41:34', '2026-01-12 11:43:34'),
(967, 18, 1, 88, 'Toll gates', NULL, 72, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:41:38', '2026-01-12 11:43:00'),
(968, 18, 1, 88, 'Toll Gates', NULL, 73, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:43:14', '2026-01-12 11:43:42'),
(969, 18, 1, 88, 'Diesel', NULL, 74, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:43:56', '2026-01-12 11:44:31'),
(970, 18, 1, 88, 'Toll gates', NULL, 75, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:44:49', '2026-01-12 11:46:49'),
(971, 18, 1, 88, 'Toll Gates', NULL, 76, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:44:52', '2026-01-12 11:46:50'),
(972, 18, 1, 88, 'Toll Gates', NULL, 77, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:44:57', '2026-01-12 11:46:52'),
(973, 18, 1, 88, 'Toll Gates', NULL, 78, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:45:14', '2026-01-12 11:46:53'),
(974, 18, 1, 87, 'Cement Bags, Paintersmate', NULL, 115, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:47:20', '2026-01-12 11:49:08'),
(975, 18, 1, 88, 'diesel', NULL, 79, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:49:55', '2026-01-12 11:50:32'),
(976, 18, 1, 88, 'Toll gates', NULL, 80, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:51:11', '2026-01-12 11:52:06'),
(977, 18, 1, 88, 'Toll Gates', NULL, 81, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-12 11:51:31', '2026-01-12 11:52:03'),
(978, 27, 1, 122, 'Cement & 3x sponge rollers', NULL, 56, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:26:09', '2026-01-13 07:29:52'),
(979, 27, 1, 122, 'Paint', NULL, 57, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:28:19', '2026-01-13 07:29:10'),
(980, 27, 1, 122, 'Padlock', NULL, 58, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:30:06', '2026-01-13 07:31:25'),
(981, 27, 1, 123, 'Toll Gates', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:31:48', '2026-01-13 07:32:36'),
(982, 27, 1, 123, 'Toll Gates', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:31:53', '2026-01-13 07:32:38'),
(983, 27, 1, 122, '750ml thinners', NULL, 59, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:32:55', '2026-01-13 07:33:32'),
(984, 27, 1, 122, '4x Ecopile Rollers, black bags', NULL, 60, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:36:02', '2026-01-13 07:36:37'),
(985, 27, 1, 122, 'Thinners, sanding belt, 5kg cleaning rags, ect', NULL, 61, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:37:13', '2026-01-13 07:38:00'),
(986, 18, 1, 88, 'Toll Gates', NULL, 82, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:39:33', '2026-01-13 07:40:28'),
(987, 18, 1, 88, 'Toll Gates', NULL, 83, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:39:44', '2026-01-13 07:40:29'),
(988, 18, 1, 88, 'Diesel', NULL, 84, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:41:47', '2026-01-27 04:15:49'),
(989, 27, 1, 197, 'Simon Painter', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-01-13 07:47:05', '2026-01-16 13:53:47'),
(990, 27, 1, 164, 'R500 loan', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:50:57', '2026-01-13 07:51:39'),
(991, 27, 1, 164, 'R160 loan', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 07:51:48', '2026-01-13 07:52:15'),
(992, 27, 1, 197, 'Simon Painter', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-01-13 07:52:53', '2026-01-16 13:53:47'),
(993, 27, 1, 197, 'Simon Painter', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-01-13 07:53:34', '2026-01-16 13:53:47'),
(994, 36, 1, 181, 'Total Wages', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 09:22:38', '2026-01-13 09:22:59'),
(995, 18, 1, 89, 'Wages (F22) (25 Okt - 6 Nov)', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 09:24:31', '2026-01-13 09:26:05'),
(996, 18, 1, 89, 'Wages (F23) (8 Nov - 21 Nov)', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 09:26:28', '2026-01-13 09:27:32'),
(997, 18, 1, 89, 'Wages (F24) (22 Nov - 5 Dec)', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 09:27:56', '2026-01-13 09:28:34'),
(998, 18, 1, 89, 'Wages (F25) (6 Dec - 19 Dec)', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-13 09:29:01', '2026-01-13 09:31:42'),
(999, 33, 1, 175, 'Theo', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-13 18:29:36', '2026-02-21 05:12:46'),
(1000, 33, 1, 170, 'Trappe', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-13 18:35:17', '2026-03-01 17:13:26'),
(1001, 33, 1, 175, 'Tirag', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-13 18:46:12', '2026-01-17 10:27:38'),
(1002, 42, 1, 201, 'Enijn spares', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-14 17:54:59', '2026-01-14 17:56:35'),
(1003, 42, 1, 201, 'Panelbeating', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-14 17:55:23', '2026-01-14 17:56:35'),
(1004, 42, 1, 201, 'SEATS', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-14 17:55:36', '2026-01-14 17:56:35'),
(1005, 42, 1, 201, 'Macanical', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-14 17:56:02', '2026-01-14 17:56:35'),
(1006, 42, 1, 201, 'Purchase Price', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-14 17:56:23', '2026-01-14 17:56:35'),
(1007, 42, 1, 201, 'Tyres', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-14 17:58:21', '2026-01-14 18:07:24'),
(1008, 33, 1, 171, 'scaffolding', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-15 07:26:10', '2026-01-17 09:19:55'),
(1009, 33, 1, 171, 'Glas aankope', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-15 07:28:02', '2026-02-10 05:52:27'),
(1010, 45, 1, 210, 'Stanley', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-15 08:11:18', '2026-01-15 13:15:34'),
(1011, 45, 1, 210, 'Sydney', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-15 11:50:51', '2026-01-16 12:29:33'),
(1012, 45, 1, 210, 'Nelson', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:27:28', '2026-01-16 12:29:40'),
(1013, 45, 1, 210, 'Elias', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:27:34', '2026-01-16 12:29:44'),
(1014, 45, 1, 210, 'Daniel', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:27:40', '2026-01-16 12:29:50'),
(1015, 45, 1, 210, 'Christopher', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:27:48', '2026-01-16 12:29:56'),
(1016, 45, 1, 210, 'Dixon', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:31:38', '2026-01-16 12:30:00'),
(1017, 45, 1, 211, 'Jordan', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:46:13', '2026-01-15 13:27:26'),
(1018, 45, 1, 211, 'Christopher', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:46:25', '2026-01-15 13:27:24'),
(1019, 45, 1, 211, 'Dixon', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:46:30', '2026-01-15 13:27:16'),
(1020, 45, 1, 211, 'Bongi', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:46:55', '2026-01-15 13:27:18'),
(1021, 45, 1, 211, 'Bryan', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:47:01', '2026-01-15 13:27:14'),
(1022, 45, 1, 211, 'Vusi', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:47:06', '2026-01-15 13:27:12'),
(1023, 45, 1, 216, 'Christopher', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:57:29', '2026-01-15 13:26:37'),
(1024, 45, 1, 216, 'Dixon', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:57:50', '2026-01-16 12:32:18'),
(1025, 45, 1, 216, 'Esther', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 12:57:54', '2026-01-15 13:26:42'),
(1026, 45, 1, 217, 'Sifundise', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-15 13:09:00', '2026-01-15 13:16:20'),
(1027, 27, 1, 197, 'Wages (F22) (5 Nov - 6 Nov)', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:34:52', '2026-01-16 14:29:18'),
(1028, 33, 1, 175, 'Mervin', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-16 13:35:32', '2026-01-17 10:27:38'),
(1029, 27, 1, 197, 'Wages (F23) (8 Nov - 21 Nov)', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:36:04', '2026-01-16 14:29:14'),
(1030, 27, 1, 197, 'Wages (F24) (22 Nov - 5 Dec)', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:37:04', '2026-01-16 14:29:27'),
(1031, 27, 1, 197, 'Wages (F25) (5 Dec - 19 Dec)', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:42:40', '2026-01-16 14:29:48'),
(1032, 27, 1, 197, 'Simon Painter', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:47:36', '2026-01-16 13:52:20'),
(1033, 27, 1, 197, 'Simon Painter', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:48:27', '2026-01-16 13:52:25'),
(1034, 27, 1, 197, 'Simon Painter', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:49:30', '2026-01-16 13:57:33'),
(1035, 27, 1, 197, 'Simon Painter', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:49:38', '2026-01-16 13:57:35'),
(1036, 27, 1, 197, 'Simon Painter', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:49:55', '2026-01-16 13:57:36'),
(1037, 27, 1, 197, 'Simon Painter', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:50:00', '2026-01-16 13:57:37'),
(1038, 27, 1, 197, 'Simon Painter', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:50:25', '2026-01-16 13:57:38'),
(1039, 27, 1, 122, 'Paint', NULL, 62, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 13:58:16', '2026-01-16 13:59:59'),
(1040, 27, 1, 122, 'Paint', NULL, 63, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:00:27', '2026-01-16 14:00:56'),
(1041, 27, 1, 122, 'Hire All WAT055', NULL, 64, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:02:09', '2026-01-16 14:02:15'),
(1042, 27, 1, 122, 'Paint', NULL, 65, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:02:25', '2026-01-16 14:02:58'),
(1043, 27, 1, 122, 'Sanding belt, Cables ties, ect', NULL, 66, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:05:45', '2026-01-16 14:06:49'),
(1044, 24, 1, 219, 'F26 ( 5 Jan - 16 Jan)', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:23:45', '2026-01-16 14:24:16'),
(1045, 27, 1, 197, 'F26 Lone ( 5 Jan - 16 Dec)', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:24:53', '2026-01-16 14:25:26'),
(1046, 27, 1, 197, 'Simon painter Brother', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:26:00', '2026-01-16 14:26:34'),
(1047, 18, 1, 89, 'Wages (F26) (5 Jan - 16 Dec)', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:27:27', '2026-01-16 14:30:54'),
(1048, 18, 1, 89, 'Tiler BN Moyo', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:27:46', '2026-01-16 14:28:27'),
(1049, 18, 1, 87, 'Plaster Sand, PPC surecement', NULL, 116, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:34:02', '2026-01-16 14:35:08'),
(1050, 18, 1, 88, 'Diesel', NULL, 85, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:35:33', '2026-01-27 04:15:58'),
(1051, 18, 1, 87, 'Polyfilla, Dust Mask, ect', NULL, 117, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:42:44', '2026-01-16 14:43:58'),
(1052, 18, 1, 87, 'Weber-tyld tile', NULL, 118, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-16 14:44:14', '2026-01-16 14:48:11'),
(1053, 33, 1, 220, 'Revonia', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-17 09:21:17', '2026-03-01 17:14:19');
INSERT INTO `board_items` (`id`, `board_id`, `company_id`, `group_id`, `title`, `description`, `position`, `status_label`, `assigned_to`, `priority`, `progress`, `due_date`, `start_date`, `end_date`, `tags`, `archived`, `subitem_count`, `created_by`, `created_at`, `updated_at`) VALUES
(1054, 33, 1, 220, 'Waters Edge', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-17 09:21:39', '2026-01-26 06:02:06'),
(1055, 33, 1, 221, 'Kar', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-17 09:24:15', '2026-01-26 06:09:24'),
(1056, 33, 1, 221, 'Server', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-17 09:24:35', '2026-01-26 06:03:02'),
(1057, 33, 1, 221, 'Advertising Relatives', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-17 09:26:31', '2026-02-17 07:07:25'),
(1058, 33, 1, 221, 'Rekenaar Parte', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-17 09:27:24', '2026-02-10 05:55:18'),
(1059, 33, 1, 175, 'aftrek orders', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-17 10:27:32', '2026-03-01 17:14:03'),
(1060, 48, 1, 225, 'Stanley', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:15:01', '2026-01-29 07:07:08'),
(1061, 48, 1, 225, 'Nelson (Albert)', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:15:29', '2026-01-29 07:07:06'),
(1062, 48, 1, 225, 'Elias', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:15:35', '2026-01-29 07:07:04'),
(1063, 48, 1, 225, 'Daniel', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:15:40', '2026-01-29 07:06:58'),
(1064, 48, 1, 225, 'Sydney', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:15:45', '2026-01-29 07:06:54'),
(1065, 48, 1, 225, 'Christopher', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:15:53', '2026-01-29 07:06:50'),
(1066, 48, 1, 225, 'Dixon', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:15:55', '2026-01-29 07:06:48'),
(1067, 48, 1, 226, 'Bongi', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:28:17', '2026-01-29 11:43:08'),
(1068, 48, 1, 226, 'Bryan', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:28:36', '2026-01-29 11:43:14'),
(1069, 48, 1, 226, 'Vusi', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:28:48', '2026-01-29 11:43:16'),
(1070, 48, 1, 226, 'Christopher', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:29:10', '2026-01-29 11:43:21'),
(1071, 48, 1, 226, 'Dixon', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:29:21', '2026-01-29 11:43:23'),
(1072, 48, 1, 227, 'Esther', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:29:36', '2026-01-29 11:42:26'),
(1073, 48, 1, 227, 'Christopher', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:30:51', '2026-01-29 11:42:29'),
(1074, 48, 1, 227, 'Dixon', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 05:30:54', '2026-01-29 11:42:31'),
(1075, 27, 1, 164, 'Bakkie parts pistons, rings, ect', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 09:00:16', '2026-01-19 09:01:09'),
(1076, 27, 1, 164, 'Bakkie parts, 2 stroke, ect', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 09:01:46', '2026-01-19 09:02:33'),
(1077, 27, 1, 164, 'Bakkie parts, Deposit on spares', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-19 09:03:24', '2026-01-19 09:04:00'),
(1078, 48, 1, 226, 'Tiekie', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-22 08:02:11', '2026-01-29 11:43:27'),
(1079, 48, 1, 226, 'Levi', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-22 08:02:24', '2026-01-29 11:43:36'),
(1080, 48, 1, 226, 'Lebohang', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-22 08:02:36', '2026-01-29 11:43:39'),
(1081, 27, 1, 197, 'Tiekie Painter leen R400', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-25 09:36:28', '2026-01-29 11:49:30'),
(1082, 38, 1, 186, 'Toilet', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-26 05:59:12', '2026-01-26 05:59:18'),
(1083, 33, 1, 220, 'Bank', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-01-26 06:02:20', '2026-02-26 19:46:00'),
(1084, 18, 1, 88, 'Diesel', NULL, 86, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:15:10', '2026-01-27 04:16:47'),
(1085, 18, 1, 87, '20kg Tile Adhesive', NULL, 119, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:17:32', '2026-01-27 04:18:33'),
(1086, 18, 1, 87, 'Tile Adhesive, 1kg Tal Flex', NULL, 120, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:19:10', '2026-01-27 04:20:06'),
(1087, 18, 1, 87, 'Plastersand', NULL, 121, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:20:25', '2026-01-27 04:21:23'),
(1088, 18, 1, 88, 'Diesel', NULL, 87, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:21:43', '2026-01-27 04:22:16'),
(1089, 18, 1, 87, 'Sandpaper', NULL, 122, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:22:40', '2026-01-27 04:24:29'),
(1090, 18, 1, 88, 'Diesel', NULL, 88, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:26:06', '2026-01-27 04:26:58'),
(1091, 18, 1, 87, 'PPC Surecement', NULL, 123, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:27:45', '2026-01-27 04:28:38'),
(1092, 18, 1, 87, 'Tiles', NULL, 124, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:29:14', '2026-01-27 04:36:25'),
(1093, 18, 1, 88, 'Diesel', NULL, 89, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:32:26', '2026-01-27 04:33:12'),
(1094, 18, 1, 88, 'Diesel', NULL, 90, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:33:27', '2026-01-27 04:33:59'),
(1095, 18, 1, 87, 'Paint Brush, Paint rollers, plaster sand, ect', NULL, 125, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:34:38', '2026-01-27 04:35:50'),
(1096, 18, 1, 163, 'Fines', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 04:37:20', '2026-01-28 05:17:13'),
(1097, 48, 1, 227, 'Gift', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 14:47:44', '2026-01-30 12:42:46'),
(1098, 48, 1, 227, 'Happy', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-27 14:49:44', '2026-01-29 11:42:36'),
(1099, 18, 1, 88, 'Toll gates', NULL, 91, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:13:31', '2026-01-28 05:14:50'),
(1100, 18, 1, 88, 'Toll Gates', NULL, 92, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:14:59', '2026-01-28 05:16:00'),
(1101, 27, 1, 124, 'Fines', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:17:41', '2026-01-28 05:18:39'),
(1102, 18, 1, 88, 'Toll gates', NULL, 93, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:19:23', '2026-01-28 05:20:13'),
(1103, 18, 1, 88, 'Diesel', NULL, 94, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:20:27', '2026-01-28 05:21:26'),
(1104, 18, 1, 87, 'Clearview fence, poles, 2x baseplates, ect', NULL, 126, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-01-28 05:23:59', '2026-02-11 09:42:42'),
(1105, 18, 1, 87, 'Anti slip additive', NULL, 127, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:26:35', '2026-01-28 05:27:53'),
(1106, 18, 1, 88, 'Diesel', NULL, 95, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:28:19', '2026-01-28 05:29:10'),
(1107, 27, 1, 122, 'WAT058', NULL, 67, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-01-28 05:37:42', '2026-01-28 05:40:08'),
(1108, 27, 1, 122, 'Thinners', NULL, 68, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:37:49', '2026-01-28 05:40:15'),
(1109, 27, 1, 122, 'Plastic bags, cement, ect', NULL, 69, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:39:31', '2026-02-11 06:48:17'),
(1110, 48, 1, 226, 'Nelson (Albert)', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:43:42', '2026-01-29 11:46:28'),
(1111, 48, 1, 226, 'Daniel', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-28 05:44:28', '2026-01-29 11:46:40'),
(1112, 48, 1, 225, 'Levi', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-29 07:05:13', '2026-01-29 11:58:40'),
(1113, 48, 1, 225, 'Fikile', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-29 07:05:32', '2026-01-29 11:58:51'),
(1114, 18, 1, 87, 'Plastic bags, polycap pipe, ect', NULL, 128, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-01-29 14:23:03', '2026-01-29 14:24:14'),
(1115, 18, 1, 87, 'Tiles', NULL, 129, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:06:56', '2026-02-03 09:08:55'),
(1116, 18, 1, 89, 'Tiler BM Moyo', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:09:44', '2026-02-03 09:10:22'),
(1117, 18, 1, 87, '2 Ruff cutrollers, 10 pipe clamps, 30mm screws, ect', NULL, 130, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:11:39', '2026-02-03 09:14:14'),
(1118, 18, 1, 87, '6m Aluminim T track', NULL, 131, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:15:42', '2026-02-03 09:17:24'),
(1119, 18, 1, 87, '3 double plugs, 1 switch plug, 2 wall boxes, ect', NULL, 132, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:18:06', '2026-02-03 09:23:21'),
(1120, 18, 1, 88, 'Diesel', NULL, 96, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:19:52', '2026-02-03 09:20:40'),
(1121, 18, 1, 89, 'Alfred Electrician loan R1000', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:21:58', '2026-02-03 09:22:37'),
(1122, 18, 1, 87, 'Dust Mask, PVC tube connector, ect', NULL, 133, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:23:48', '2026-02-03 09:24:53'),
(1123, 18, 1, 87, 'Wall plugs, 50x50 angle bar, ect', NULL, 134, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:25:42', '2026-02-03 09:27:08'),
(1124, 18, 1, 87, '6 LED Altra round lights, wall plugs, 500g polyfila, ect', NULL, 135, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:28:11', '2026-02-03 09:32:19'),
(1125, 18, 1, 87, '10 boxes of Tiles', NULL, 136, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:31:55', '2026-02-03 09:34:38'),
(1126, 18, 1, 88, 'Diesel', NULL, 97, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:33:06', '2026-02-03 09:33:52'),
(1127, 18, 1, 87, '2x grey grout, 10x tile cement, 4x cement, ect', NULL, 137, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:35:11', '2026-02-03 09:36:18'),
(1128, 18, 1, 87, 'LED panel light, cables, ect', NULL, 138, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:37:11', '2026-02-03 09:38:42'),
(1129, 18, 1, 87, 'Tek screws, washers, cutting disk, ect', NULL, 139, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:39:44', '2026-02-03 09:41:00'),
(1130, 18, 1, 87, 'RIV Trailer rent paid', NULL, 140, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:41:18', '2026-02-03 09:45:17'),
(1131, 18, 1, 87, 'Vision angle, E track, 3.0m brush welt, ect', NULL, 141, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:41:27', '2026-02-03 09:46:17'),
(1132, 18, 1, 87, 'Silicone', NULL, 142, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:44:03', '2026-02-03 09:45:30'),
(1133, 18, 1, 87, 'Poly nails, PVC Trunking 3m', NULL, 143, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:46:56', '2026-02-03 09:47:49'),
(1134, 18, 1, 87, 'KFC, Aluminime, pap, drinks & bread, ect', NULL, 144, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:52:44', '2026-02-03 09:53:41'),
(1135, 18, 1, 89, 'Tiler', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:54:36', '2026-02-03 09:55:23'),
(1136, 18, 1, 89, 'Thabang Bricklayer', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:55:44', '2026-02-03 09:56:31'),
(1137, 18, 1, 89, 'Payment to Stanly', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:57:40', '2026-02-03 09:58:18'),
(1138, 18, 1, 89, '5x tile cement, 2x grout, Tileflex.', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-03 09:58:46', '2026-02-03 09:59:01'),
(1139, 18, 1, 87, '5x tile cement, 2x grout, tileflex', NULL, 145, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 09:59:25', '2026-02-03 10:00:26'),
(1140, 18, 1, 88, 'Diesel', NULL, 98, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 10:01:03', '2026-02-03 10:01:31'),
(1141, 18, 1, 87, 'Wall plugs, river sand, plaster sand, 3x cement', NULL, 146, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 10:05:25', '2026-02-03 10:06:32'),
(1142, 18, 1, 87, 'River sand, 6 round nose strips, 6m timber', NULL, 147, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 10:07:44', '2026-02-03 10:08:45'),
(1143, 18, 1, 89, 'Tiler Jim Maluleke', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 10:09:29', '2026-02-03 10:10:10'),
(1144, 18, 1, 89, 'Thabo', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 10:10:57', '2026-02-03 10:11:24'),
(1145, 18, 1, 88, 'Diesel', NULL, 99, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 10:13:39', '2026-02-03 10:14:03'),
(1146, 18, 1, 89, 'Wages (F27) (17 Jan - 30 Jan)', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 10:19:50', '2026-02-03 10:21:04'),
(1147, 27, 1, 197, 'Wages (F27) (17 Jan - 30 Jan)', NULL, 17, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-03 10:22:06', '2026-02-03 10:22:44'),
(1148, 33, 1, 220, 'mahindra', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-04 19:23:04', '2026-02-13 10:01:19'),
(1149, 33, 1, 220, 'Afdakke Rivonia', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-04 19:23:20', '2026-02-24 15:16:11'),
(1151, 51, 1, 241, 'Tester', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-05 09:38:20', '2026-02-05 09:49:21'),
(1152, 51, 1, 241, 'Sranley', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:46:32', '2026-02-13 07:34:33'),
(1153, 51, 1, 241, 'Sydney', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:46:58', '2026-02-13 07:34:55'),
(1154, 51, 1, 241, 'Elias', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:47:14', '2026-02-13 07:34:37'),
(1155, 51, 1, 241, 'Christopher', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:47:30', '2026-02-13 07:39:27'),
(1156, 51, 1, 241, 'Levi', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:47:47', '2026-02-13 07:43:35'),
(1157, 51, 1, 241, 'Bongi', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:48:00', '2026-02-18 16:58:00'),
(1158, 51, 1, 241, 'Vusi', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:48:14', '2026-02-13 07:43:47'),
(1159, 51, 1, 241, 'Lebohang', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:48:31', '2026-02-13 07:34:50'),
(1160, 51, 1, 241, 'Nelson (Albert)', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:48:45', '2026-02-13 07:34:58'),
(1161, 51, 1, 241, 'Daniel', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 09:49:04', '2026-02-13 07:34:59'),
(1162, 51, 1, 242, 'Bongi', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:02:25', '2026-02-13 14:22:35'),
(1163, 51, 1, 242, 'Lebohang', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:02:59', '2026-02-13 07:28:44'),
(1164, 51, 1, 242, 'Nelson (albert)', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:03:34', '2026-02-13 07:31:16'),
(1165, 51, 1, 242, 'Christopher', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:03:56', '2026-02-13 07:39:33'),
(1166, 51, 1, 242, 'Ndzukisomde', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:05:41', '2026-02-13 07:43:04'),
(1167, 51, 1, 242, 'Anthony', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:05:55', '2026-02-13 07:43:05'),
(1168, 51, 1, 242, 'Vusi', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:09:30', '2026-02-13 07:43:07'),
(1169, 51, 1, 243, 'Esther', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:12:51', '2026-02-13 07:32:35'),
(1170, 51, 1, 242, 'Daniel', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-05 10:22:31', '2026-02-13 07:31:21'),
(1171, 27, 1, 164, 'R400 loan', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-06 16:38:59', '2026-02-06 16:39:35'),
(1172, 27, 1, 164, 'R400 loan', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-06 16:39:46', '2026-02-06 16:40:23'),
(1173, 27, 1, 164, 'R5000 van loan klaar betaal', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-06 16:40:44', '2026-02-06 16:41:13'),
(1174, 51, 1, 242, 'Tiekie', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 05:35:11', '2026-02-13 07:43:10'),
(1175, 51, 1, 242, 'Fikile', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 05:35:31', '2026-02-13 07:43:11'),
(1176, 18, 1, 87, 'Sandpaper, paint rollers, nutsetter, ect', NULL, 148, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 05:48:37', '2026-02-09 05:51:26'),
(1177, 18, 1, 87, 'Aluminim cover strip', NULL, 149, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 05:50:47', '2026-02-09 05:52:09'),
(1178, 18, 1, 87, '10 Wall plugs', NULL, 150, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 05:52:34', '2026-02-09 05:53:39'),
(1179, 18, 1, 87, 'Sqyare tub and wall plugs', NULL, 151, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 05:54:37', '2026-02-09 05:56:56'),
(1180, 18, 1, 87, '25A Contractor 240V 11KW 3P', NULL, 152, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 05:58:25', '2026-02-09 06:00:15'),
(1181, 18, 1, 89, 'Electrician R1200', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:00:53', '2026-02-09 06:48:09'),
(1182, 18, 1, 89, 'Paving Guy R400', NULL, 21, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:01:46', '2026-02-09 06:45:51'),
(1183, 18, 1, 88, 'Diesel', NULL, 100, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:02:35', '2026-02-09 06:03:07'),
(1184, 18, 1, 87, '22mm nail clip X10, wall plugs, ect', NULL, 153, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:03:39', '2026-02-09 06:05:06'),
(1185, 18, 1, 88, 'Diesel', NULL, 101, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:06:10', '2026-02-09 06:06:51'),
(1186, 18, 1, 87, 'Tiles, grey epoxy, led lights, ect', NULL, 154, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-09 06:09:47', '2026-02-22 14:38:15'),
(1187, 18, 1, 87, 'Waterproofing, armourseal, ect', NULL, 155, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-09 06:12:41', '2026-02-22 14:38:15'),
(1188, 18, 1, 87, 'paint', NULL, 156, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:19:07', '2026-02-09 06:20:18'),
(1189, 18, 1, 87, 'Floor strips', NULL, 157, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:21:21', '2026-02-09 06:22:41'),
(1190, 18, 1, 87, 'Steel', NULL, 158, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:24:18', '2026-02-09 06:25:25'),
(1191, 18, 1, 87, '16 LED lights', NULL, 159, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:26:04', '2026-02-09 06:27:53'),
(1192, 18, 1, 87, 'Periodic customer Statement', NULL, 160, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:29:09', '2026-02-09 06:30:36'),
(1193, 18, 1, 87, 'LED office pendent light', NULL, 161, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:32:27', '2026-02-09 06:35:42'),
(1194, 18, 1, 87, 'Paint brushes, rollers, epoxy rollers, ceiling bords, ect', NULL, 162, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:36:46', '2026-02-09 06:43:26'),
(1195, 18, 1, 87, '2 paintersmate, ceiling strips, ect', NULL, 163, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:40:50', '2026-02-09 06:43:43'),
(1196, 18, 1, 88, 'Diesel', NULL, 102, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:44:08', '2026-02-09 06:44:47'),
(1197, 18, 1, 89, 'Electriatian R2750', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:45:13', '2026-02-09 06:48:16'),
(1198, 18, 1, 87, 'Staff refreshments', NULL, 164, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:46:52', '2026-02-11 05:57:26'),
(1199, 18, 1, 87, 'Paint', NULL, 165, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:49:04', '2026-02-09 06:50:39'),
(1200, 18, 1, 87, 'Tiles', NULL, 166, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:51:03', '2026-02-09 06:52:36'),
(1201, 18, 1, 87, 'Color Tint 50ml', NULL, 167, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:53:00', '2026-02-11 05:56:13'),
(1202, 18, 1, 87, 'Fiberglass, ect', NULL, 168, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 06:54:00', '2026-02-09 06:58:47'),
(1203, 18, 1, 87, 'Carport roof, Palace door, ect', NULL, 169, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 08:14:51', '2026-02-09 08:15:49'),
(1204, 18, 1, 89, 'Tiler R1200', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-09 09:41:27', '2026-02-09 09:42:03'),
(1205, 51, 1, 241, 'Godfrey', NULL, 11, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-10 11:55:11', '2026-02-13 07:43:58'),
(1206, 51, 1, 241, 'Tiekie', NULL, 12, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-10 11:55:29', '2026-02-13 07:44:01'),
(1207, 51, 1, 241, 'Filike', NULL, 13, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-10 11:55:40', '2026-02-13 07:44:04'),
(1208, 51, 1, 241, 'Papi', NULL, 14, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-10 11:55:52', '2026-02-13 07:35:16'),
(1209, 51, 1, 241, 'Lefu', NULL, 15, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-10 11:56:02', '2026-02-13 07:35:17'),
(1210, 51, 1, 241, 'Minentle (Rasta)', NULL, 16, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-10 11:59:58', '2026-02-13 07:35:19'),
(1211, 18, 1, 87, '2 Boxes', NULL, 170, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 05:46:01', '2026-02-11 05:47:03'),
(1212, 18, 1, 87, 'Plaster Sand, Surecement', NULL, 171, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 05:48:50', '2026-02-11 05:49:55'),
(1213, 18, 1, 87, 'Rollers, aluminim, ect', NULL, 172, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 05:50:25', '2026-02-11 05:51:18'),
(1214, 18, 1, 88, 'Diesel', NULL, 103, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 05:51:38', '2026-02-11 05:52:06'),
(1215, 18, 1, 88, 'Color tint, 50ml, ect', NULL, 104, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-11 05:53:10', '2026-02-11 05:53:14'),
(1216, 18, 1, 87, 'Color tint 50ml', NULL, 173, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-11 05:53:38', '2026-02-11 05:56:18'),
(1217, 27, 1, 122, '1m Plaster sand', NULL, 70, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:47:39', '2026-02-11 06:48:39'),
(1218, 27, 1, 122, '5L Thinners', NULL, 71, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:49:08', '2026-02-11 06:49:51'),
(1219, 27, 1, 123, 'Toll Gates', NULL, 22, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:50:14', '2026-02-11 06:52:16'),
(1220, 27, 1, 123, 'Toll Gates', NULL, 23, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:50:19', '2026-02-11 06:52:17'),
(1221, 27, 1, 123, 'Toll Gates', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:50:22', '2026-02-11 06:52:18'),
(1222, 27, 1, 123, 'Toll gates', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:50:26', '2026-02-11 06:52:20'),
(1223, 27, 1, 122, 'Rope, 4x brushes, 4x rollers', NULL, 72, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:52:56', '2026-02-11 06:53:41'),
(1224, 27, 1, 122, 'Rope, Tape measuerer', NULL, 73, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:53:58', '2026-02-11 06:54:47'),
(1225, 27, 1, 244, 'Supply 4mm Clear Glass and OBS Glazing', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 06:56:26', '2026-02-11 11:19:39'),
(1226, 27, 1, 244, 'Supply and Install 4mm Clear Glass and OBS', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-11 06:58:28', '2026-02-11 11:19:25'),
(1227, 27, 1, 244, 'Supply and Install 4mm Clear Glass and OBS', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 07:00:21', '2026-02-11 09:26:31'),
(1228, 27, 1, 244, 'Frank Glass Trading POP only', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 07:02:18', '2026-02-11 07:04:42'),
(1229, 27, 1, 122, 'Scrape, Wood chisel, ect', NULL, 74, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 07:05:14', '2026-02-11 07:05:59'),
(1230, 27, 1, 123, 'Petrol', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 09:22:43', '2026-02-11 09:23:31'),
(1231, 27, 1, 122, 'Cement, clout nail 32mm 1kg', NULL, 75, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 09:33:16', '2026-02-11 09:34:09'),
(1232, 27, 1, 122, 'Rollers, brushes, ect', NULL, 76, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 09:34:32', '2026-02-11 09:35:15'),
(1233, 27, 1, 123, 'Petrol', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 09:35:43', '2026-02-11 09:36:26'),
(1234, 27, 1, 123, 'Toll gates withdrawl', NULL, 28, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 09:35:52', '2026-02-11 09:36:58'),
(1235, 27, 1, 244, 'Supply and Install 4mm clear glass and OBS', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 09:38:02', '2026-02-11 09:39:27'),
(1236, 27, 1, 123, 'Toll Gates R500', NULL, 29, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 09:39:59', '2026-02-11 09:40:33'),
(1237, 27, 1, 197, 'Kotse Salaris November', NULL, 18, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 11:15:37', '2026-02-11 11:17:38'),
(1238, 27, 1, 197, 'Kotse Salaris December', NULL, 19, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 11:16:35', '2026-02-11 11:17:55'),
(1239, 27, 1, 197, 'Kotse Salaris januarie', NULL, 20, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-11 11:16:45', '2026-02-11 11:17:59'),
(1240, 51, 1, 243, 'Nelson (Albert)', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-13 07:31:31', '2026-02-13 07:32:31'),
(1241, 51, 1, 243, 'Daniel', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-13 07:31:51', '2026-02-13 07:32:29'),
(1242, 51, 1, 243, 'Sidney', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-13 07:32:11', '2026-02-13 07:50:09'),
(1243, 33, 1, 220, 'Vereeniging Haval', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 09:58:50', '2026-02-19 10:28:00'),
(1244, 33, 1, 220, 'Sizuki Vaalpark', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 09:59:10', '2026-02-21 05:25:15'),
(1245, 53, 1, 245, 'TLB', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:05:46', '2026-02-13 10:11:29'),
(1246, 53, 1, 246, 'Paving rate', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:07:56', '2026-02-13 10:09:03'),
(1247, 53, 1, 247, 'Crucher dust 30m3', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:10:20', '2026-02-13 10:11:21'),
(1248, 53, 1, 254, 'Buy and install', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:12:17', '2026-02-13 13:38:50'),
(1249, 53, 1, 254, 'SUNDRIES AND EXTRAS', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:13:45', '2026-02-13 10:14:00'),
(1250, 56, 1, 255, 'Buy Isoboard', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:33:45', '2026-02-13 10:34:58'),
(1251, 56, 1, 255, 'Install Isoboard', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:35:09', '2026-02-13 10:35:18'),
(1252, 56, 1, 255, 'Sundries', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:35:38', '2026-02-13 10:36:40'),
(1253, 56, 1, 256, 'Charcoal Gun Metal', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:37:11', '2026-02-13 10:37:40'),
(1254, 56, 1, 256, 'Labour', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 1, '2026-02-13 10:37:48', '2026-02-13 10:37:56'),
(1255, 54, 1, 248, 'TLB Hire', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-18 15:13:43', '2026-02-24 06:03:22'),
(1256, 54, 1, 249, 'Diesel', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-18 15:23:15', '2026-02-18 15:30:08'),
(1257, 60, 1, 267, 'Suzie', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-22 13:39:37', '2026-02-22 13:44:40'),
(1258, 60, 1, 267, 'danny', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-22 13:39:48', '2026-02-22 13:44:40'),
(1259, 60, 1, 267, 'Stanley', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:50:49', '2026-02-26 19:55:31'),
(1260, 60, 1, 267, 'Sydney', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:50:53', '2026-02-26 19:55:35'),
(1261, 60, 1, 267, 'Elias', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:50:58', '2026-02-27 10:17:18'),
(1262, 60, 1, 267, 'Daniel', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:51:14', '2026-02-26 19:55:41'),
(1263, 60, 1, 267, 'Levi', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:51:22', '2026-02-27 09:33:53'),
(1264, 60, 1, 267, 'Godfrey', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:51:33', '2026-02-26 19:55:54'),
(1265, 60, 1, 267, 'Papi', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:51:37', '2026-02-26 19:55:57'),
(1266, 60, 1, 267, 'Tikie', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:51:40', '2026-02-26 19:56:00'),
(1267, 60, 1, 267, 'Fikile', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:51:50', '2026-02-26 19:56:02'),
(1268, 60, 1, 267, 'Lefu', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:51:56', '2026-02-27 09:33:49'),
(1269, 60, 1, 267, 'Albert (Nelson)', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 13:52:28', '2026-02-26 19:56:08'),
(1270, 60, 1, 268, 'Bongi', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:03:02', '2026-02-26 19:56:13'),
(1271, 60, 1, 268, 'Tikie', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:03:21', '2026-02-26 19:56:16'),
(1272, 60, 1, 268, 'Vusi', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:03:51', '2026-02-27 09:34:10'),
(1273, 60, 1, 268, 'Anthony', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:04:09', '2026-02-26 19:56:20'),
(1274, 60, 1, 268, 'Ndzukisomde', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:04:38', '2026-02-26 19:56:22'),
(1275, 60, 1, 268, 'Albert (Nelson)', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:05:11', '2026-02-26 19:56:25'),
(1276, 60, 1, 268, 'Daniel', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:05:28', '2026-02-27 09:34:22'),
(1277, 60, 1, 268, 'Christopher', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:05:47', '2026-02-26 19:56:31'),
(1278, 60, 1, 269, 'Esther', NULL, 0, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:06:49', '2026-02-26 19:49:38'),
(1279, 60, 1, 269, 'Albert (Nelson)', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:13:09', '2026-02-26 19:49:40'),
(1280, 60, 1, 269, 'Daniel', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:13:32', '2026-02-26 19:49:42'),
(1281, 60, 1, 268, 'Fikile', NULL, 8, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:24:18', '2026-02-26 19:56:34'),
(1282, 18, 1, 87, 'Sikaflex Purform', NULL, 173, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:41:03', '2026-02-22 14:41:58'),
(1283, 18, 1, 87, 'Trim Tech Saw, 12 timber brackets, ect', NULL, 174, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:43:08', '2026-02-22 14:44:25'),
(1284, 18, 1, 88, 'Diesel', NULL, 104, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:44:39', '2026-02-22 14:45:16'),
(1285, 18, 1, 88, 'Diesel Mechine', NULL, 105, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:49:14', '2026-02-22 14:50:11'),
(1286, 18, 1, 88, 'Rollers', NULL, 106, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-22 14:55:47', '2026-02-22 14:55:58'),
(1287, 18, 1, 87, 'Rollers', NULL, 175, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:56:14', '2026-02-22 14:59:22'),
(1288, 18, 1, 87, 'chain', NULL, 176, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 14:58:50', '2026-02-22 14:59:50'),
(1289, 18, 1, 89, 'Victor Aluminium', NULL, 24, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:11:37', '2026-02-22 15:12:50'),
(1290, 18, 1, 88, 'Diesel', NULL, 106, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:13:14', '2026-02-22 15:14:14'),
(1291, 18, 1, 87, 'Grand PVC, ect', NULL, 177, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:16:15', '2026-02-22 15:17:29'),
(1292, 18, 1, 87, 'Plugtops', NULL, 178, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:17:39', '2026-02-22 15:18:28'),
(1293, 18, 1, 87, 'Sealant', NULL, 179, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:19:15', '2026-02-22 15:20:04'),
(1294, 18, 1, 87, 'Black spray paint', NULL, 180, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:21:11', '2026-02-22 15:22:13'),
(1295, 18, 1, 89, 'Tiler Jim Maluleke', NULL, 25, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:22:42', '2026-02-22 15:23:16'),
(1296, 18, 1, 89, 'Samson Carpenter', NULL, 26, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:25:58', '2026-02-24 06:16:52'),
(1297, 18, 1, 88, 'Diesel', NULL, 107, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:27:09', '2026-02-22 15:27:43'),
(1298, 18, 1, 87, 'Grey and Black Epoxy', NULL, 181, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:29:09', '2026-02-22 15:38:37'),
(1299, 18, 1, 87, 'E Round Surfix, TII-32 3P DIN, ect', NULL, 182, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:31:08', '2026-02-22 15:38:39'),
(1300, 18, 1, 87, 'PVC Trunking, Cement Primer, ect', NULL, 183, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:35:46', '2026-02-22 15:38:28'),
(1301, 18, 1, 87, '9mm Supertec Shell white Vinyl', NULL, 184, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:39:32', '2026-02-22 15:52:20'),
(1302, 18, 1, 87, 'Roofcoat, ect', NULL, 185, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:53:25', '2026-02-22 15:54:54'),
(1303, 18, 1, 87, 'Pine Lamstock wood', NULL, 186, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:56:20', '2026-02-22 15:58:55'),
(1304, 18, 1, 87, 'Tile Adhesive', NULL, 187, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 15:58:02', '2026-02-22 15:59:53'),
(1305, 18, 1, 87, 'Steal for Carports', NULL, 188, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 16:01:52', '2026-02-22 16:02:55'),
(1306, 18, 1, 87, 'Steel', NULL, 189, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 16:06:56', '2026-02-22 16:15:01'),
(1307, 18, 1, 87, 'Steel, sander, ect', NULL, 190, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-22 16:14:55', '2026-02-22 16:16:11'),
(1308, 27, 1, 122, 'Hose Mender, extension cord, ect', NULL, 77, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 05:46:11', '2026-02-24 05:47:03'),
(1309, 27, 1, 122, 'Harness X2', NULL, 78, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 05:47:27', '2026-02-24 05:48:18'),
(1310, 27, 1, 122, 'Turps 5L', NULL, 79, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 05:48:43', '2026-02-24 05:50:38'),
(1311, 27, 1, 122, 'Rood and wall deep well 5L', NULL, 80, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 05:49:54', '2026-02-24 05:51:06'),
(1312, 27, 1, 122, 'Extension cord 5MT', NULL, 81, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 05:51:41', '2026-02-24 05:52:35'),
(1313, 54, 1, 248, 'Palisade Fence, Post, Caps, Installation, ect', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 05:54:26', '2026-02-24 05:59:57'),
(1314, 54, 1, 249, 'Diesel', NULL, 1, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 05:58:56', '2026-02-24 05:59:28'),
(1315, 54, 1, 249, 'Diesel', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 06:01:20', '2026-02-24 06:01:49'),
(1316, 54, 1, 248, 'TLB Hire (20, 21, 22 Feb)', NULL, 2, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 06:02:25', '2026-02-24 06:03:07'),
(1317, 18, 1, 89, 'Welder Rivonia', NULL, 27, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 06:16:48', '2026-02-24 06:17:24'),
(1318, 27, 1, 244, 'Rectangle Tubing, 40 lipchannels, 43 agrade sheets', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-24 06:20:17', '2026-02-24 06:20:42'),
(1319, 18, 1, 87, '36 rectangle tubing, 40 lipchannels, 43 agrade sheets', NULL, 191, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-24 06:21:43', '2026-02-24 06:22:19'),
(1320, 60, 1, 268, 'Levi', NULL, 9, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 06:54:24', '2026-02-27 09:33:40'),
(1321, 60, 1, 269, 'Moses', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 13:08:48', '2026-02-26 19:49:44'),
(1322, 60, 1, 269, 'Cristoff', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-24 13:09:10', '2026-02-26 19:49:46'),
(1323, 18, 1, 88, 'Diesel', NULL, 108, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:17:24', '2026-02-25 07:17:59'),
(1324, 18, 1, 87, 'Poly  nails, rollers, Drill bit, Connector strip, ect', NULL, 191, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:19:00', '2026-02-25 07:20:22'),
(1325, 18, 1, 87, 'Superflex DIY flap disc, Alcolin woodfiller, ect', NULL, 192, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:21:03', '2026-02-25 07:21:58'),
(1326, 18, 1, 87, 'Blacksmith metal disc, Alcolin woodfiller', NULL, 193, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:22:38', '2026-02-25 07:23:35'),
(1327, 18, 1, 87, 'Supperflex cutting disc', NULL, 194, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:24:27', '2026-02-25 07:25:22'),
(1328, 18, 1, 88, 'Diesel', NULL, 109, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:25:40', '2026-02-25 07:27:01'),
(1329, 18, 1, 87, 'ANG 15 x 15 CHA 6m', NULL, 195, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:29:09', '2026-02-25 07:30:03'),
(1330, 18, 1, 87, 'Flat bar 130x8mm, Paint marker pen', NULL, 196, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:30:44', '2026-02-25 07:32:07'),
(1331, 18, 1, 87, 'Tile adhesive 20kg', NULL, 197, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:33:15', '2026-02-25 07:36:14'),
(1332, 18, 1, 87, 'Paintable sealant, Sikaflex purform, ect', NULL, 198, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:43:57', '2026-02-25 07:45:24'),
(1333, 18, 1, 88, 'Diesel', NULL, 110, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 07:45:47', '2026-02-25 07:46:29'),
(1334, 18, 1, 87, 'Panel light 12W', NULL, 199, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 08:16:53', '2026-02-25 08:18:45'),
(1335, 18, 1, 87, 'Topline wire brush, Glove PVC heavy duty', NULL, 200, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-25 08:19:54', '2026-02-25 08:23:06'),
(1336, 60, 1, 268, 'Lefu', NULL, 10, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-26 19:52:42', '2026-02-27 09:33:38'),
(1337, 18, 1, 87, 'Drill Bit 8.0mm, drill bitt 8.5mm', NULL, 201, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 09:47:52', '2026-02-27 09:48:48'),
(1338, 18, 1, 87, 'Alicoin woodfiller, Drill bitt', NULL, 202, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 09:49:38', '2026-02-27 09:50:33'),
(1339, 18, 1, 88, 'Diesel', NULL, 111, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-27 09:51:39', '2026-02-27 09:52:18'),
(1340, 18, 1, 88, 'Diesel', NULL, 111, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 09:52:53', '2026-02-27 09:53:20'),
(1341, 18, 1, 87, 'Flat bar, steel', NULL, 203, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 09:54:35', '2026-02-27 09:55:59'),
(1342, 18, 1, 87, 'Cement', NULL, 204, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 09:56:20', '2026-02-27 10:01:56'),
(1343, 18, 1, 87, 'masonary nails, plastic bat holder, ect', NULL, 205, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:03:14', '2026-02-27 10:04:06'),
(1344, 18, 1, 87, '50kg cement', NULL, 206, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:04:25', '2026-02-27 10:05:26'),
(1345, 18, 1, 88, 'Diesel', NULL, 112, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:05:42', '2026-02-27 10:06:34'),
(1346, 18, 1, 87, 'Cleaning supplies, thinners, sandpaper', NULL, 207, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:07:21', '2026-02-27 10:09:11'),
(1347, 18, 1, 87, '20lt gun metal, grey epoxy, 20lt white paint, ect', NULL, 208, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:10:28', '2026-02-27 10:10:42'),
(1348, 18, 1, 87, 'Pine Lamstock', NULL, 209, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:11:16', '2026-02-27 10:12:40'),
(1349, 18, 1, 87, 'Plaster Bond, Super bonding, roofcoat, ect', NULL, 210, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:13:16', '2026-02-27 10:14:13'),
(1350, 54, 1, 249, 'Diesel fir TLB', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:51:41', '2026-02-27 10:52:45'),
(1351, 54, 1, 249, 'Diesel', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:53:07', '2026-02-27 10:53:41'),
(1352, 54, 1, 248, 'Boomsloping vir Betaling', NULL, 3, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:54:33', '2026-02-27 10:55:39'),
(1353, 54, 1, 248, 'Cement X3', NULL, 4, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:56:06', '2026-02-27 10:57:11'),
(1354, 54, 1, 248, '13mm stone, course river sand, yard mix, cement X3', NULL, 5, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 1, 0, 3, '2026-02-27 10:58:01', '2026-02-27 11:03:31'),
(1355, 54, 1, 248, 'Truck Hire to move stones', NULL, 6, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 10:59:53', '2026-02-27 11:01:14'),
(1356, 54, 1, 248, '13mm stone, course river sand, yard mix, cement X3', NULL, 7, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 11:02:42', '2026-02-27 11:03:28'),
(1357, 27, 1, 122, 'Brushes', NULL, 82, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 11:05:05', '2026-02-27 11:06:05'),
(1358, 27, 1, 122, 'Gun metal roofcoat, steelcoat', NULL, 83, NULL, NULL, 'medium', 0, NULL, NULL, NULL, NULL, 0, 0, 3, '2026-02-27 11:06:31', '2026-02-27 11:07:25');

-- --------------------------------------------------------

--
-- Table structure for table `board_item_attachments`
--

CREATE TABLE `board_item_attachments` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_item_comments`
--

CREATE TABLE `board_item_comments` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_item_values`
--

CREATE TABLE `board_item_values` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `column_id` int(11) NOT NULL,
  `value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `board_item_values`
--

INSERT INTO `board_item_values` (`id`, `item_id`, `column_id`, `value`) VALUES
(1, 10, 12, '100'),
(2, 10, 14, 'dsafdfsdf'),
(3, 10, 16, '0'),
(5, 13, 7, '500'),
(6, 6, 7, '0'),
(425, 89, 156, '9'),
(426, 89, 155, 'todo'),
(427, 89, 154, '199'),
(428, 90, 148, 'RIV001'),
(431, 90, 151, '5880.00'),
(433, 90, 158, '45'),
(434, 90, 159, 'working'),
(435, 90, 160, '{\"start\":\"2025-10-17\",\"end\":\"2025-10-30\"}'),
(436, 90, 161, 'WEENS WEER LAAT'),
(437, 92, 156, '9'),
(438, 92, 155, 'todo'),
(439, 92, 154, '199'),
(445, 93, 43, '222.00'),
(448, 93, 85, '1111'),
(542, 27, 43, '22'),
(556, 105, 83, '2025-10-13'),
(557, 105, 211, 'INV226112024'),
(558, 105, 85, 'Takealot'),
(559, 105, 43, '3073.00'),
(560, 105, 214, 'done'),
(561, 106, 214, 'done'),
(562, 106, 83, '2025-10-13'),
(563, 106, 211, 'INV226112026'),
(564, 106, 85, 'Takealot'),
(565, 106, 43, '3799.00'),
(566, 107, 83, '2025-10-13'),
(567, 107, 211, 'INV226112027'),
(568, 107, 85, 'Takealot'),
(569, 107, 43, '159.00'),
(570, 107, 214, 'done'),
(571, 108, 83, '2025-10-14'),
(572, 108, 211, 'INV45113'),
(573, 108, 85, 'Topdog Toolshop'),
(574, 108, 43, '6480.00'),
(575, 108, 214, 'todo'),
(576, 109, 83, '2025-10-14'),
(577, 109, 211, 'INV578404'),
(578, 109, 85, 'AGW'),
(579, 109, 43, '2100.00'),
(580, 109, 214, 'done'),
(581, 110, 83, '2025-10-14'),
(582, 110, 211, 'QUO1774'),
(583, 110, 85, 'SAL Equipment'),
(584, 110, 43, '14720.00'),
(585, 110, 214, 'todo'),
(586, 111, 83, '2025-10-11'),
(587, 111, 211, 'INV DK125'),
(588, 111, 85, 'DK Didgital'),
(589, 111, 43, '52500.00'),
(590, 111, 214, 'done'),
(591, 112, 83, '2025-10-06'),
(592, 112, 211, 'QUO DBNCQ0032466'),
(593, 112, 85, 'Stewarts & Lloyds'),
(594, 112, 43, '12329.99'),
(595, 112, 214, 'todo'),
(596, 113, 83, '2025-10-03'),
(597, 113, 211, 'QU595888'),
(598, 113, 85, 'AGW'),
(599, 113, 43, '18300.00'),
(600, 113, 214, 'done'),
(601, 114, 83, '2025-10-01'),
(602, 114, 211, 'QU595742'),
(603, 114, 85, 'AGW'),
(604, 114, 43, '26000.00'),
(605, 114, 214, 'done'),
(606, 115, 83, '2025-10-01'),
(607, 115, 211, 'INV8843230'),
(608, 115, 85, 'World Of Sun And Wind Power'),
(609, 115, 43, '29780.00'),
(610, 115, 214, 'done'),
(611, 116, 83, '2025-09-29'),
(612, 116, 211, 'QUT SOQ018326'),
(613, 116, 85, 'Redletter'),
(614, 116, 43, '151980.97'),
(615, 116, 214, 'todo'),
(616, 117, 83, '2025-09-30'),
(617, 117, 211, 'Pro forma INV DST0327533'),
(618, 117, 85, 'Conways aluminum'),
(619, 117, 43, '21764.00'),
(620, 117, 214, 'done'),
(621, 118, 83, '2025-09-30'),
(622, 118, 211, 'QUT8843179'),
(623, 118, 85, 'World of Sun & Wind Power'),
(624, 118, 43, '14890.00'),
(625, 118, 214, 'todo'),
(626, 119, 83, '2025-09-30'),
(627, 119, 211, 'QUT Nr Not Given '),
(628, 119, 85, 'Hire it'),
(629, 119, 43, '3000.00'),
(630, 119, 214, 'todo'),
(631, 120, 83, '2025-09-25'),
(632, 120, 211, 'QUT744062'),
(633, 120, 85, 'Malls Tiles'),
(634, 120, 43, '1381.54'),
(635, 120, 214, 'done'),
(636, 121, 83, '2025-09-19'),
(637, 121, 211, 'QUT Q0111663'),
(638, 121, 85, 'IBW Management (Northside Electrical)'),
(639, 121, 43, '4134.63'),
(640, 121, 214, 'done'),
(641, 122, 83, '2025-09-02'),
(642, 122, 211, 'INV402030'),
(643, 122, 85, 'Kenings'),
(644, 122, 43, '978.36'),
(645, 122, 214, 'todo'),
(646, 123, 83, '2025-09-02'),
(647, 123, 211, 'INV402491'),
(648, 123, 85, 'Kenings'),
(649, 123, 43, '987.85'),
(650, 123, 214, 'todo'),
(651, 124, 83, '2025-09-08'),
(652, 124, 211, 'QUT740325'),
(653, 124, 85, 'Malls Tiles'),
(654, 124, 43, '129241.87'),
(655, 124, 214, 'done'),
(656, 125, 83, '2025-08-06'),
(657, 125, 211, 'QUT2559099'),
(658, 125, 85, 'Mannys Hardware supplies'),
(659, 125, 43, '158301.00'),
(660, 125, 214, 'todo'),
(661, 126, 83, '2025-07-30'),
(662, 126, 211, 'QUT00046308'),
(663, 126, 85, 'Unhu Aluminium PTY LTD'),
(664, 126, 214, 'todo'),
(665, 126, 43, '38085.63'),
(666, 128, 83, '2025-10-13'),
(667, 128, 211, 'INV226112024'),
(668, 128, 85, 'Takealot'),
(669, 128, 43, '3073.00'),
(670, 128, 214, 'todo'),
(677, 130, 83, '2025-09-26'),
(678, 130, 43, '1381.54'),
(679, 131, 83, '2025-09-27'),
(680, 131, 211, 'Not Given'),
(681, 133, 83, '2025-09-27'),
(682, 133, 85, 'Divern Govender'),
(683, 133, 211, 'Not Given'),
(684, 133, 43, '700.00'),
(685, 133, 214, 'done'),
(686, 134, 83, '2025-09-27'),
(687, 134, 85, 'Mica Paint & hardware'),
(688, 134, 43, '3495.00'),
(689, 134, 214, 'done'),
(690, 134, 211, 'Not Given'),
(691, 135, 83, '2025-09-29'),
(692, 135, 211, 'Pro Forma (INV not given)'),
(693, 135, 85, 'Watts On'),
(694, 135, 43, '3588.30'),
(695, 135, 214, 'todo'),
(696, 136, 83, '2025-09-30'),
(697, 136, 211, 'Not Given'),
(698, 136, 85, 'Hire It natal'),
(699, 136, 43, '3345.00'),
(700, 136, 214, 'todo'),
(701, 137, 211, 'Pro Forma (Not Given)'),
(702, 137, 85, 'Watts On'),
(703, 137, 43, '4532.00'),
(704, 137, 214, 'todo'),
(705, 137, 83, '2025-10-03'),
(706, 138, 83, '2025-10-10'),
(707, 138, 211, 'Not Given'),
(708, 138, 43, '17000.00'),
(709, 138, 214, 'done'),
(710, 139, 83, '2025-10-10'),
(711, 139, 211, 'Not Given'),
(712, 139, 85, 'SouthGate wheels'),
(713, 139, 43, '20000.00'),
(714, 139, 214, 'done'),
(715, 140, 83, '2025-10-10'),
(716, 140, 211, 'Not Given'),
(717, 140, 43, '10000.00'),
(718, 140, 214, 'done'),
(719, 140, 85, 'Mervin Pillay'),
(720, 141, 83, '2025-10-10'),
(721, 141, 211, 'Not Given'),
(722, 141, 43, '10000.00'),
(723, 141, 214, 'done'),
(724, 142, 83, '2025-10-10'),
(725, 142, 211, 'Not Given'),
(726, 142, 85, 'KKM Somaru'),
(727, 142, 43, '13000.00'),
(728, 142, 214, 'done'),
(729, 143, 83, '2025-10-10'),
(730, 143, 43, '55000.00'),
(731, 143, 211, 'Not Given'),
(732, 143, 214, 'todo'),
(733, 144, 83, '2025-10-13'),
(734, 144, 211, 'Not Given'),
(735, 144, 85, 'AGW'),
(736, 144, 43, '2100.00'),
(737, 144, 214, 'done'),
(738, 145, 83, '2025-10-14'),
(739, 145, 211, 'Not Given'),
(740, 145, 85, 'Not Given'),
(741, 145, 43, '10000.00'),
(742, 145, 214, 'done'),
(743, 146, 83, '2025-10-14'),
(744, 146, 211, 'Not Given'),
(745, 146, 85, 'South Gate Wheels'),
(746, 146, 43, '15000.00'),
(747, 146, 214, 'done'),
(748, 147, 83, '2025-10-14'),
(749, 147, 211, 'Not Given\r\n\r\n'),
(750, 147, 85, 'Furniture warehouse\r\n'),
(751, 147, 43, '6091.16'),
(752, 147, 214, 'done'),
(753, 148, 83, '2025-10-15'),
(754, 148, 211, 'Not Given'),
(755, 148, 85, 'South Gate wheels'),
(756, 148, 43, '1000.00'),
(757, 148, 214, 'done'),
(758, 149, 83, '2025-10-15'),
(759, 149, 211, 'Not Given'),
(760, 149, 85, 'South Gate Wheels'),
(761, 149, 43, '5000.00'),
(762, 149, 214, 'done'),
(763, 138, 85, 'RP&PN Security Syste'),
(764, 150, 83, '2025-10-03'),
(765, 150, 211, 'Not Given'),
(766, 150, 85, 'Watts On'),
(767, 150, 43, '5211.80'),
(768, 150, 214, 'done'),
(769, 151, 83, '2025-10-02'),
(770, 151, 211, 'Not Given'),
(771, 151, 85, 'M Kanniappen'),
(772, 151, 43, '12000.00'),
(773, 151, 214, 'done'),
(774, 152, 83, '2025-10-01'),
(775, 152, 211, 'Not Given'),
(776, 152, 85, 'Redletter'),
(777, 152, 43, '132094.75'),
(778, 152, 214, 'done'),
(779, 153, 83, '2025-09-29'),
(780, 153, 211, 'Not given'),
(781, 153, 85, 'V Misindo'),
(782, 153, 43, '3570.00'),
(783, 153, 214, 'done'),
(784, 154, 83, '2025-09-23'),
(785, 154, 211, 'Not given'),
(786, 154, 85, 'M Kanniappen'),
(787, 154, 43, '5000.00'),
(788, 154, 214, 'done'),
(789, 155, 83, '2025-09-23'),
(790, 155, 211, 'Not Given'),
(791, 155, 85, 'M Kanniappen'),
(792, 155, 43, '25000.00'),
(793, 155, 214, 'done'),
(794, 156, 83, '2025-09-22'),
(795, 156, 211, 'Not Given'),
(796, 156, 85, 'Conways Aluminium \r\n'),
(797, 156, 43, '27234.82'),
(798, 156, 214, 'done'),
(799, 157, 83, '2025-09-09'),
(800, 157, 211, 'Not given'),
(801, 157, 85, 'M Kanniappen'),
(802, 157, 43, '15000.00'),
(803, 157, 214, 'done'),
(805, 159, 221, '35000.00'),
(806, 159, 228, 'done'),
(808, 159, 230, 'Adriaan Geldenhuis'),
(810, 160, 221, '30000.00'),
(811, 160, 228, 'done'),
(812, 160, 230, 'Adriaan Geldenhuis'),
(814, 161, 221, '30000.00'),
(815, 161, 228, 'done'),
(816, 161, 230, 'Adriaan Geldenhuis'),
(818, 162, 221, '2250.00'),
(819, 162, 228, 'done'),
(821, 165, 221, '7774.87'),
(822, 165, 231, 'INV251059'),
(823, 165, 232, 'New Site'),
(824, 165, 233, '2023-10-10'),
(825, 165, 228, 'done'),
(826, 165, 230, 'Build It'),
(827, 165, 235, 'Paid'),
(828, 166, 221, '3164.23'),
(829, 166, 231, 'INV1222'),
(830, 166, 232, 'None'),
(831, 166, 233, '2023-12-06'),
(832, 166, 228, 'done'),
(833, 166, 230, 'Roadlab'),
(834, 166, 235, 'Paid'),
(835, 167, 221, '7450.00'),
(836, 167, 231, 'INV268220'),
(837, 167, 232, 'G110006'),
(838, 167, 233, '2024-01-17'),
(839, 167, 228, 'done'),
(840, 167, 230, 'Build It'),
(841, 167, 235, 'Paid'),
(842, 168, 221, '292.95'),
(843, 168, 231, 'IN268378'),
(844, 168, 232, '11G0007'),
(845, 168, 233, '2024-01-18'),
(846, 168, 228, 'done'),
(847, 168, 230, 'Build It'),
(848, 168, 235, 'Paid'),
(849, 169, 221, '160.00'),
(850, 169, 231, 'INV25119'),
(851, 169, 232, 'None\r\n'),
(852, 169, 233, '2024-01-18'),
(853, 169, 228, 'done'),
(854, 169, 230, 'Shell'),
(855, 169, 235, 'Paid'),
(856, 170, 221, '744.96'),
(857, 170, 231, 'INV268524'),
(858, 170, 232, '11G008'),
(859, 170, 233, '2024-01-19'),
(860, 170, 228, 'done'),
(861, 170, 230, 'Build It'),
(862, 170, 235, 'Paid'),
(863, 171, 221, '379.50'),
(864, 171, 231, 'Not Given'),
(865, 171, 232, 'Not Given'),
(866, 171, 233, '2023-10-31'),
(867, 171, 228, 'done'),
(868, 171, 230, 'Prosite'),
(869, 171, 235, 'Paid'),
(870, 172, 221, '759.00'),
(871, 172, 231, 'Not Given'),
(872, 172, 232, 'Not Given\r\n'),
(873, 172, 233, '2023-11-30'),
(874, 172, 228, 'done'),
(875, 172, 230, 'Prosite'),
(876, 172, 235, 'Paid'),
(877, 173, 221, '7590.00'),
(878, 173, 231, 'Not Given'),
(879, 173, 232, 'Not Given'),
(880, 173, 233, '2024-01-31'),
(881, 173, 228, 'done'),
(882, 173, 230, 'Prosite'),
(883, 173, 235, 'Paid'),
(884, 174, 221, '160.00'),
(885, 174, 231, 'Not Given'),
(886, 174, 232, 'Not Given'),
(887, 174, 233, '2023-10-31'),
(888, 174, 228, 'done'),
(889, 174, 230, 'Prosite'),
(890, 174, 235, 'Paid'),
(891, 175, 221, '320.00'),
(892, 175, 231, 'Not Given'),
(893, 175, 232, 'Not Given'),
(894, 175, 233, '2023-11-30'),
(895, 175, 228, 'done'),
(896, 175, 230, 'Prosite'),
(897, 175, 235, 'Paid'),
(898, 176, 221, '3200.00'),
(899, 176, 231, 'Not Given'),
(900, 176, 232, 'Not Given'),
(901, 176, 233, '2024-01-31'),
(902, 176, 228, 'done'),
(903, 176, 230, 'Prosite'),
(904, 176, 235, 'Paid'),
(905, 141, 85, 'Not Given'),
(906, 143, 85, 'Not Given'),
(907, 177, 221, '21000.00'),
(908, 177, 231, 'Not Given'),
(909, 177, 232, 'Not Given'),
(910, 177, 233, '2024-01-31'),
(911, 177, 228, 'done'),
(912, 177, 230, 'Prosite'),
(913, 177, 235, 'Paid'),
(914, 178, 221, '686.10'),
(915, 178, 231, 'Not Given'),
(916, 178, 232, 'Not Given'),
(917, 178, 233, '2024-01-24'),
(918, 178, 228, 'done'),
(919, 178, 230, 'Post at print'),
(920, 178, 235, 'Paid'),
(921, 179, 221, '2714.00'),
(922, 179, 231, 'INV2944'),
(923, 179, 232, '11G0004'),
(924, 179, 233, '2024-01-26'),
(925, 179, 228, 'done'),
(926, 179, 230, 'Valley Containers'),
(927, 179, 235, 'Paid'),
(928, 180, 221, '140.00'),
(929, 180, 231, 'INV52205'),
(930, 180, 232, 'Not Given'),
(931, 180, 233, '2024-01-26'),
(932, 180, 228, 'done'),
(933, 180, 230, 'Elegant Fuels'),
(934, 180, 235, 'Paid'),
(935, 181, 221, '1899.80'),
(936, 181, 231, 'INV270026'),
(937, 181, 232, 'Not Given'),
(938, 181, 233, '2024-02-29'),
(939, 181, 228, 'done'),
(940, 181, 230, 'Build It'),
(941, 181, 235, 'Paid'),
(942, 182, 221, '325.00'),
(943, 182, 231, 'INV0122262'),
(944, 182, 232, 'Not Given'),
(945, 182, 233, '2024-01-31'),
(946, 182, 228, 'done'),
(947, 182, 230, 'Masshire'),
(948, 182, 235, 'Paid'),
(949, 183, 221, '139.99'),
(950, 183, 231, 'INV270535'),
(951, 183, 232, '11G0012'),
(952, 183, 233, '2024-01-31'),
(953, 183, 228, 'done'),
(954, 183, 235, 'Paid'),
(955, 183, 230, 'Build It'),
(956, 184, 221, '1459.80'),
(957, 184, 231, 'INV270020'),
(958, 184, 232, '11G0012'),
(959, 184, 233, '2024-01-29'),
(960, 184, 228, 'done'),
(961, 184, 235, 'Paid'),
(962, 184, 230, 'Build It'),
(963, 185, 221, '690.00'),
(964, 185, 231, 'INV8632'),
(965, 185, 232, '11G0004'),
(966, 185, 233, '2024-02-01'),
(967, 185, 228, 'done'),
(968, 185, 235, 'Paid'),
(969, 185, 230, 'Valley containers'),
(970, 186, 221, '451.40'),
(971, 186, 231, 'INV01223137'),
(972, 186, 232, '11G0014'),
(973, 186, 233, '2024-02-05'),
(974, 186, 228, 'done'),
(975, 186, 235, 'Paid'),
(976, 186, 230, 'Masshire'),
(977, 187, 221, '1584.90'),
(978, 187, 231, 'INV01223284'),
(979, 187, 232, '11G0014'),
(980, 187, 233, '2024-02-07'),
(981, 187, 228, 'done'),
(982, 187, 230, 'Masshire'),
(983, 187, 235, 'Paid'),
(984, 188, 221, '150.00'),
(985, 188, 231, 'INV314398'),
(986, 188, 232, 'Not Given'),
(987, 188, 233, '2024-02-06'),
(988, 188, 228, 'done'),
(989, 188, 235, 'Paid'),
(990, 188, 230, 'Caltex'),
(991, 189, 221, '282.00'),
(992, 189, 231, 'INV341565'),
(993, 189, 232, 'Not Given'),
(994, 189, 233, '2024-02-12'),
(995, 189, 228, 'done'),
(996, 189, 230, 'Dana Tool'),
(997, 189, 235, 'Paid'),
(998, 190, 221, '120.00'),
(999, 190, 231, 'Not Given'),
(1000, 190, 232, 'Not Given'),
(1001, 190, 233, '2024-02-05'),
(1002, 190, 228, 'done'),
(1003, 190, 235, 'Paid'),
(1004, 190, 230, 'Caltex'),
(1005, 191, 221, '757.85'),
(1006, 191, 231, 'INV01224140'),
(1007, 191, 232, 'Not Given'),
(1008, 191, 233, '2024-02-20'),
(1009, 191, 228, 'done'),
(1010, 191, 235, 'Paid'),
(1011, 191, 230, 'Masshire'),
(1012, 192, 221, '2450.00'),
(1013, 192, 231, 'INV98'),
(1014, 192, 232, 'Not Given'),
(1015, 192, 233, '2024-02-21'),
(1016, 192, 228, 'done'),
(1017, 192, 235, 'Paid'),
(1018, 192, 230, 'South Cape Lawnmowers'),
(1019, 193, 221, '7590.00'),
(1020, 193, 231, 'Not Given'),
(1021, 193, 232, 'Not Given'),
(1022, 193, 233, '2024-02-29'),
(1023, 193, 228, 'done'),
(1024, 193, 235, 'Paid'),
(1025, 193, 230, 'Prosite'),
(1026, 194, 221, '3200.00'),
(1027, 194, 231, 'Not Given'),
(1028, 194, 232, 'Not Given'),
(1029, 194, 233, '2024-02-29'),
(1030, 194, 228, 'done'),
(1031, 194, 230, 'Prosite'),
(1032, 194, 235, 'Paid'),
(1033, 195, 221, '9500.00'),
(1034, 195, 231, 'Not Given'),
(1035, 195, 232, 'Not Given'),
(1036, 195, 233, '2024-02-29'),
(1037, 195, 228, 'done'),
(1038, 195, 230, 'Prosite'),
(1039, 195, 235, 'Paid'),
(1040, 196, 221, '200.00'),
(1041, 196, 231, 'INV7778'),
(1042, 196, 232, 'Not Given'),
(1043, 196, 233, '2024-02-27'),
(1044, 196, 228, 'done'),
(1045, 196, 235, 'Paid'),
(1046, 196, 230, 'Elegant Fuel'),
(1047, 197, 221, '554.90'),
(1048, 197, 231, 'INV01224868'),
(1049, 197, 232, 'LG009'),
(1050, 197, 233, '2024-02-29'),
(1051, 197, 228, 'done'),
(1052, 197, 235, 'Paid'),
(1053, 197, 230, 'Masshire'),
(1054, 198, 221, '690.00'),
(1055, 198, 231, 'INV9010'),
(1056, 198, 232, '11G0004'),
(1057, 198, 233, '2024-03-01'),
(1058, 198, 228, 'done'),
(1059, 198, 230, 'Valley Container'),
(1060, 198, 235, 'Paid'),
(1061, 199, 221, '325.00'),
(1062, 199, 231, 'INV01224902'),
(1063, 199, 232, 'Not Given'),
(1064, 199, 233, '2024-02-29'),
(1065, 199, 228, 'done'),
(1066, 199, 235, 'Paid'),
(1067, 199, 230, 'Masshire'),
(1068, 200, 221, '2950.00'),
(1069, 200, 231, 'INV1083'),
(1070, 200, 232, 'Not Given'),
(1071, 200, 233, '2024-03-03'),
(1072, 200, 228, 'done'),
(1073, 200, 235, 'Paid'),
(1074, 200, 230, 'OHS'),
(1075, 201, 221, '300.00'),
(1076, 201, 233, '2024-03-07'),
(1077, 201, 228, 'done'),
(1078, 201, 232, 'Not Given'),
(1079, 201, 231, 'INV144341'),
(1080, 201, 235, 'Paid'),
(1081, 201, 230, 'Caltex'),
(1082, 202, 221, '19.57'),
(1083, 202, 231, 'INV93779'),
(1084, 202, 232, 'Not Given'),
(1085, 202, 233, '2024-05-06'),
(1086, 202, 228, 'done'),
(1087, 202, 235, 'Paid'),
(1088, 202, 230, 'Dana Tool'),
(1089, 203, 221, '500.00'),
(1090, 203, 231, 'INV143367'),
(1091, 203, 232, 'Not given'),
(1092, 203, 233, '2024-03-04'),
(1093, 203, 228, 'done'),
(1094, 203, 235, 'Paid'),
(1095, 203, 230, 'Caltex'),
(1096, 204, 221, '280.00'),
(1097, 204, 231, 'INV1045'),
(1098, 204, 232, 'Not Given'),
(1099, 204, 233, '2024-03-13'),
(1100, 204, 228, 'done'),
(1101, 204, 230, 'South Cape Lawnmowers'),
(1102, 204, 235, 'Paid'),
(1103, 205, 221, '276.70'),
(1104, 205, 231, 'Not Given'),
(1105, 205, 232, 'Not Given'),
(1106, 205, 233, '2024-03-22'),
(1107, 205, 228, 'done'),
(1108, 205, 235, 'Paid'),
(1109, 205, 230, 'Steyns'),
(1110, 206, 231, 'Not Given'),
(1111, 206, 232, 'Not Given\r\n'),
(1112, 206, 221, '7590.00'),
(1113, 206, 233, '2024-03-29'),
(1114, 206, 228, 'done'),
(1115, 206, 235, 'Paid'),
(1116, 206, 230, 'Prosite'),
(1117, 207, 221, '3200.00'),
(1118, 207, 231, 'Not Given'),
(1119, 207, 232, 'Not Given'),
(1120, 207, 233, '2024-03-29'),
(1121, 207, 228, 'done'),
(1122, 207, 235, 'Paid'),
(1123, 207, 230, 'Prosite'),
(1124, 208, 221, '12000.00'),
(1125, 208, 231, 'Not Given'),
(1126, 208, 232, 'Not Given'),
(1127, 208, 233, '2024-03-29'),
(1128, 208, 228, 'done'),
(1129, 208, 235, 'Paid'),
(1130, 208, 230, 'prosite'),
(1131, 209, 221, '5599.35'),
(1132, 209, 231, 'INV01225945'),
(1133, 209, 232, '11G0033'),
(1134, 209, 233, '2024-03-08'),
(1135, 209, 228, 'done'),
(1136, 209, 235, 'Paid'),
(1137, 209, 230, 'Masshire'),
(1138, 210, 221, '325.00'),
(1139, 210, 231, 'INV01227022'),
(1140, 210, 232, 'Not Given'),
(1141, 210, 233, '2024-03-31'),
(1142, 210, 228, 'done'),
(1143, 210, 230, 'Masshire'),
(1144, 210, 235, 'Paid'),
(1145, 211, 221, '1800.00'),
(1146, 211, 231, 'INV NV-001358'),
(1147, 211, 232, '11G0025'),
(1148, 211, 233, '2024-03-18'),
(1149, 211, 228, 'done'),
(1150, 211, 230, 'SkipGo'),
(1151, 211, 235, 'Paid'),
(1152, 212, 221, '690.00'),
(1153, 212, 231, 'INV9386'),
(1154, 212, 232, '11G0004'),
(1155, 212, 233, '2024-04-01'),
(1156, 212, 228, 'done'),
(1157, 212, 235, 'Paid'),
(1158, 212, 230, 'Valley Containers'),
(1159, 213, 221, '325.00'),
(1160, 213, 231, 'INV01229152'),
(1161, 213, 232, 'Not Given'),
(1162, 213, 233, '2024-04-30'),
(1163, 213, 228, 'done'),
(1164, 213, 230, 'Masshire'),
(1165, 213, 235, 'Paid'),
(1166, 214, 221, '690.00'),
(1167, 214, 231, 'INV9770'),
(1168, 214, 232, 'Not Given'),
(1169, 214, 233, '2024-05-01'),
(1170, 214, 228, 'done'),
(1171, 214, 230, 'valley Containers'),
(1172, 214, 235, 'Paid'),
(1173, 215, 221, '690.00'),
(1174, 215, 231, 'INV10161'),
(1175, 215, 232, 'Not Given'),
(1176, 215, 233, '2024-06-01'),
(1177, 215, 228, 'done'),
(1178, 215, 235, 'Paid'),
(1179, 215, 230, 'Valley Containers'),
(1180, 216, 221, '736.00'),
(1181, 216, 231, 'INV10556'),
(1182, 216, 232, 'Not Given'),
(1183, 216, 233, '2024-07-01'),
(1184, 216, 228, 'done'),
(1185, 216, 235, 'Paid'),
(1186, 216, 230, 'Valley Containers'),
(1187, 217, 221, '736.00'),
(1188, 217, 231, 'INV10943'),
(1189, 217, 232, 'Not Given'),
(1190, 217, 233, '2024-08-01'),
(1191, 217, 228, 'done'),
(1192, 217, 230, 'Valley Containers'),
(1193, 217, 235, 'Paid'),
(1194, 218, 221, '736.00'),
(1195, 218, 231, 'INV11326'),
(1196, 218, 232, 'Not Given'),
(1197, 218, 233, '2024-09-01'),
(1198, 218, 228, 'done'),
(1199, 218, 230, 'Valley containers'),
(1200, 218, 235, 'Paid'),
(1201, 219, 221, '736.00'),
(1202, 219, 231, 'INV11708'),
(1203, 219, 232, 'Not Given'),
(1204, 219, 233, '2024-10-01'),
(1205, 219, 228, 'done'),
(1206, 219, 230, 'valley Containers'),
(1207, 219, 235, 'Paid'),
(1208, 220, 221, '325.00'),
(1209, 220, 231, 'INV01231480'),
(1210, 220, 232, 'Not Given'),
(1211, 220, 233, '2024-05-31'),
(1212, 220, 228, 'done'),
(1213, 220, 230, 'Masshire'),
(1214, 220, 235, 'Paid'),
(1215, 221, 221, '325.00'),
(1216, 221, 231, 'INV01233700'),
(1217, 221, 232, 'Not Given'),
(1218, 221, 233, '2024-06-30'),
(1219, 221, 228, 'done'),
(1220, 221, 235, 'Paid'),
(1221, 221, 230, 'Masshire'),
(1222, 222, 221, '325.00'),
(1223, 222, 232, 'Not Given'),
(1224, 222, 231, 'INV01236081'),
(1225, 222, 233, '2024-07-31'),
(1226, 222, 228, 'done'),
(1227, 222, 230, 'Masshire'),
(1228, 222, 235, 'Paid'),
(1229, 223, 221, '325.00'),
(1230, 223, 231, 'INV01238152'),
(1231, 223, 232, 'Not Given'),
(1232, 223, 233, '2024-08-31'),
(1233, 223, 228, 'done'),
(1234, 223, 230, 'Masshire'),
(1235, 223, 235, 'Paid'),
(1236, 224, 221, '325.00'),
(1237, 224, 231, 'INV01240165'),
(1238, 224, 232, 'Not Given'),
(1239, 224, 233, '2024-09-30'),
(1240, 224, 228, 'done'),
(1241, 224, 230, 'Masshire'),
(1242, 224, 235, 'Paid'),
(1243, 225, 221, '34000.00'),
(1244, 227, 221, '394.97'),
(1245, 227, 231, 'QUT 264154'),
(1246, 227, 232, 'Not Given'),
(1247, 227, 233, '2024-04-23'),
(1248, 227, 228, 'done'),
(1249, 227, 235, 'Paid'),
(1250, 227, 230, 'Build It'),
(1251, 228, 221, '1082.81'),
(1252, 228, 231, 'QUT 266287'),
(1253, 228, 232, 'Not Given'),
(1254, 228, 233, '2024-05-07'),
(1255, 228, 228, 'done'),
(1256, 228, 230, 'Build It'),
(1257, 228, 235, 'Paid'),
(1258, 229, 221, '1014.90'),
(1259, 229, 231, 'INV348518'),
(1260, 229, 232, 'GRAN0002'),
(1261, 229, 233, '2024-05-08'),
(1262, 229, 235, 'Paid'),
(1263, 229, 230, 'Build It'),
(1264, 229, 228, 'done'),
(1265, 230, 221, '207.00'),
(1266, 230, 231, 'INV351927'),
(1267, 230, 232, 'GRAN0014'),
(1268, 230, 233, '2024-05-25'),
(1269, 230, 228, 'done'),
(1270, 230, 230, 'Build It'),
(1271, 230, 235, 'Paid'),
(1272, 231, 221, '213.90'),
(1273, 231, 231, 'INV352778'),
(1274, 231, 232, 'GRAN0016'),
(1275, 231, 233, '2024-06-04'),
(1276, 231, 228, 'done'),
(1277, 231, 230, 'Build It'),
(1278, 231, 235, 'Paid'),
(1279, 232, 221, '167.96'),
(1280, 232, 231, 'INV353744'),
(1281, 232, 232, 'GRAN0016 C'),
(1282, 232, 233, '2024-06-10'),
(1283, 232, 228, 'done'),
(1284, 232, 230, 'Build It'),
(1285, 232, 235, 'Paid'),
(1286, 233, 221, '764.26'),
(1287, 233, 231, 'INV354348'),
(1288, 233, 232, 'GRAN0019'),
(1289, 233, 233, '2024-05-13'),
(1290, 233, 228, 'done'),
(1291, 233, 230, 'Build It'),
(1292, 233, 235, 'Paid'),
(1293, 234, 221, '167.96'),
(1294, 234, 231, 'INV356146'),
(1295, 234, 232, 'GRAN0021'),
(1296, 234, 233, '2024-05-25'),
(1297, 234, 228, 'done'),
(1298, 234, 230, 'Build It'),
(1299, 234, 235, 'Paid'),
(1300, 235, 221, '869.98'),
(1301, 235, 231, 'INV353096'),
(1302, 235, 232, 'GRAN0027'),
(1303, 235, 233, '2024-05-05'),
(1304, 235, 228, 'done'),
(1305, 235, 230, 'Build It'),
(1306, 235, 235, 'Paid'),
(1307, 236, 221, '325.00'),
(1308, 236, 231, 'INV1256206'),
(1309, 236, 232, 'GRAN0029'),
(1310, 236, 233, '2024-05-31'),
(1311, 236, 228, 'done'),
(1312, 236, 230, 'Masshire'),
(1313, 236, 235, 'Paid'),
(1314, 237, 221, '325.00'),
(1315, 237, 231, 'INV1257977'),
(1316, 237, 232, 'GRAN0030'),
(1317, 237, 233, '2024-06-30'),
(1318, 237, 228, 'done'),
(1319, 237, 230, 'Masshire'),
(1320, 237, 235, 'Paid'),
(1321, 238, 221, '759.00'),
(1322, 238, 231, 'INV4875'),
(1323, 238, 232, 'GRAN0031'),
(1324, 238, 233, '2024-07-29'),
(1325, 238, 228, 'done'),
(1326, 238, 230, 'Valley Containers'),
(1327, 238, 235, 'Paid'),
(1328, 240, 221, '375.00'),
(1329, 240, 231, 'INV83185'),
(1330, 240, 232, 'GRAN0032'),
(1331, 240, 233, '2024-04-24'),
(1332, 240, 228, 'done'),
(1333, 240, 230, 'Tool Quip'),
(1334, 240, 235, 'Paid'),
(1335, 241, 221, '954.89'),
(1336, 241, 231, 'INV347571'),
(1337, 241, 233, '2024-05-03'),
(1338, 241, 232, 'GRAN0006'),
(1339, 241, 228, 'done'),
(1340, 241, 230, 'Build It'),
(1341, 241, 235, 'Paid'),
(1342, 242, 221, '1773.91'),
(1343, 242, 231, 'INV345606'),
(1344, 242, 232, 'GRAN0007'),
(1345, 242, 233, '2024-04-21'),
(1346, 242, 228, 'done'),
(1347, 242, 235, 'Paid'),
(1348, 242, 230, 'Build It'),
(1349, 243, 221, '485.92'),
(1350, 243, 231, 'INV345680'),
(1351, 243, 232, 'GRAN0011'),
(1352, 243, 233, '2024-04-22'),
(1353, 243, 228, 'done'),
(1354, 243, 230, 'Build It'),
(1355, 243, 235, 'Paid'),
(1356, 244, 221, '1435.98'),
(1357, 244, 231, 'INV345681'),
(1358, 244, 232, 'GRAN0011 B'),
(1359, 244, 233, '2024-04-22'),
(1360, 244, 228, 'done'),
(1361, 244, 230, 'Build It'),
(1362, 244, 235, 'Paid'),
(1363, 245, 221, '2474.21'),
(1364, 245, 231, 'INV348433'),
(1365, 245, 232, 'GRAN0012'),
(1366, 245, 233, '2024-05-08'),
(1367, 245, 228, 'done'),
(1368, 245, 230, 'Build It'),
(1369, 245, 235, 'Paid'),
(1370, 246, 221, '4508.85'),
(1371, 246, 231, 'INV36397'),
(1372, 246, 232, 'GRAN0013'),
(1373, 246, 233, '2024-08-11'),
(1374, 246, 228, 'done'),
(1375, 246, 230, 'Build It'),
(1376, 246, 235, 'Paid'),
(1377, 247, 221, '325.00'),
(1378, 247, 231, 'INV01259903'),
(1379, 247, 232, 'GRAN0024'),
(1380, 247, 233, '2024-07-31'),
(1381, 247, 228, 'done'),
(1382, 247, 230, 'Masshire'),
(1383, 247, 235, 'Paid'),
(1384, 248, 221, '600.00'),
(1385, 248, 231, 'INV299'),
(1386, 248, 232, 'GRAN0035'),
(1387, 248, 233, '2024-08-08'),
(1388, 248, 228, 'done'),
(1389, 248, 230, 'CBS SKIPS'),
(1390, 248, 235, 'Paid'),
(1391, 249, 221, '600.00'),
(1392, 249, 231, 'INV300'),
(1393, 249, 232, 'GRAN0038'),
(1394, 249, 233, '2024-08-08'),
(1395, 249, 228, 'done'),
(1396, 249, 230, 'CBS SKIPS'),
(1397, 249, 235, 'Paid'),
(1398, 250, 221, '140.00'),
(1399, 250, 231, 'INV64360'),
(1400, 250, 232, 'GRAN0039'),
(1401, 250, 233, '2024-04-24'),
(1402, 250, 228, 'done'),
(1403, 250, 230, 'Tool Quip'),
(1404, 250, 235, 'Paid'),
(1405, 251, 221, '700.00'),
(1406, 251, 231, 'INV396163'),
(1407, 251, 232, 'GRAN0040'),
(1408, 251, 233, '2024-04-23'),
(1409, 251, 228, 'done'),
(1410, 251, 230, 'DBPH'),
(1411, 251, 235, 'Paid'),
(1412, 252, 221, '249.90'),
(1413, 252, 231, 'INV364119'),
(1414, 252, 232, 'GRAN0045'),
(1415, 252, 233, '2024-08-12'),
(1416, 252, 228, 'done'),
(1417, 252, 230, 'Build It'),
(1418, 252, 235, 'Paid'),
(1419, 253, 221, '108.98'),
(1420, 253, 231, 'INV364204'),
(1421, 253, 232, 'GRAN0046'),
(1422, 253, 233, '2024-08-13'),
(1423, 253, 228, 'done'),
(1424, 253, 230, 'Build It\r\n'),
(1425, 253, 235, 'Paid'),
(1426, 254, 221, '759.00'),
(1427, 254, 231, 'INV16257'),
(1428, 254, 232, 'GRAN0048'),
(1429, 254, 233, '2024-08-01'),
(1430, 254, 228, 'done'),
(1431, 254, 230, 'Valley Containers'),
(1432, 254, 235, 'Paid'),
(1433, 255, 221, '174.95'),
(1434, 255, 231, 'INV368573'),
(1435, 255, 232, 'GRAN0051'),
(1436, 255, 233, '2024-09-08'),
(1437, 255, 228, 'done'),
(1438, 255, 230, 'Build It'),
(1439, 255, 235, 'Paid'),
(1440, 256, 221, '325.00'),
(1441, 256, 231, 'INV1261703'),
(1442, 256, 232, 'GRAN0060'),
(1443, 256, 233, '2024-09-25'),
(1444, 256, 228, 'done'),
(1445, 256, 230, 'Masshire'),
(1446, 256, 235, 'Paid'),
(1447, 257, 228, 'done'),
(1448, 257, 235, 'Paid'),
(1449, 257, 221, '7130.00'),
(1450, 257, 231, 'INV ME247.REK'),
(1451, 257, 232, 'Not Given'),
(1452, 257, 233, '2023-10-24'),
(1453, 257, 230, 'TMK Land Surveyors'),
(1454, 258, 221, '11385.00'),
(1455, 258, 231, 'INV01216873'),
(1456, 258, 232, 'Not Given'),
(1457, 258, 233, '2023-11-03'),
(1458, 258, 228, 'done'),
(1459, 258, 235, 'Paid'),
(1460, 258, 230, 'Masshire'),
(1461, 259, 221, '3795.00'),
(1462, 259, 231, 'INV01216321'),
(1463, 259, 232, 'Not Given'),
(1464, 259, 233, '2023-10-31'),
(1465, 259, 228, 'done'),
(1466, 259, 235, 'Paid'),
(1467, 259, 230, 'Masshire'),
(1468, 260, 221, '79915.34'),
(1469, 260, 231, 'INV750'),
(1470, 260, 232, 'Not Given'),
(1471, 260, 233, '2023-11-16'),
(1472, 260, 228, 'done'),
(1473, 260, 235, 'Paid'),
(1474, 260, 230, 'Too Quip'),
(1475, 261, 221, '3791.40'),
(1476, 261, 231, 'INV01222158'),
(1477, 261, 232, 'Not Given'),
(1478, 261, 233, '2024-01-30'),
(1479, 261, 228, 'done'),
(1480, 261, 235, 'Paid'),
(1481, 261, 230, 'Masshire'),
(1482, 262, 221, '7190.00'),
(1483, 262, 231, 'INV ME24.7REK1'),
(1484, 262, 232, 'Not Given'),
(1485, 262, 233, '2023-10-23'),
(1486, 262, 228, 'done'),
(1487, 262, 230, 'TMK Land Surveyors'),
(1488, 262, 235, 'Paid'),
(1489, 263, 221, '375.00'),
(1490, 263, 231, 'INV83185'),
(1491, 263, 232, 'Not Given'),
(1492, 263, 233, '2024-04-24'),
(1493, 263, 228, 'done'),
(1494, 263, 235, 'Paid'),
(1495, 263, 230, 'Tool Quip'),
(1496, 264, 221, '6512.00'),
(1497, 264, 231, 'INV82691'),
(1498, 264, 232, 'Not Given'),
(1499, 264, 233, '2024-03-17'),
(1500, 264, 228, 'done'),
(1501, 264, 230, 'Tool Quip'),
(1502, 264, 235, 'Paid'),
(1503, 265, 221, '2640.00'),
(1504, 265, 231, 'INV82483'),
(1505, 265, 232, 'GRAN0001'),
(1506, 265, 233, '2024-02-28'),
(1507, 265, 228, 'done'),
(1508, 265, 230, 'Tool Quip'),
(1509, 265, 235, 'Paid'),
(1510, 266, 221, '432.96'),
(1511, 266, 231, 'QUT262484'),
(1512, 266, 232, 'Not Given'),
(1513, 266, 233, '2024-04-10'),
(1514, 266, 228, 'done'),
(1515, 266, 230, 'Build It'),
(1516, 266, 235, 'Paid'),
(1517, 267, 221, '6512.00'),
(1518, 267, 231, 'INV82691'),
(1519, 267, 232, 'GRAN0001 B'),
(1520, 267, 233, '2024-03-17'),
(1521, 267, 228, 'done'),
(1522, 267, 230, 'Tool Quip'),
(1523, 267, 235, 'Paid'),
(1524, 268, 221, '63031.50'),
(1525, 268, 231, 'INV000015353'),
(1526, 268, 232, 'Not Given'),
(1527, 268, 233, '2024-01-23'),
(1528, 268, 228, 'done'),
(1529, 268, 235, 'Paid'),
(1530, 268, 230, 'Rockblend'),
(1531, 269, 221, '336.69'),
(1532, 269, 231, 'INV268903'),
(1533, 269, 232, 'Not Given'),
(1534, 269, 233, '2024-01-25'),
(1535, 269, 228, 'done'),
(1536, 269, 230, 'Build It'),
(1537, 269, 235, 'Paid'),
(1538, 270, 221, '2104.50'),
(1539, 270, 231, 'INV21311'),
(1540, 270, 232, '11G0009'),
(1541, 270, 233, '2024-01-24'),
(1542, 270, 228, 'done'),
(1543, 270, 235, 'Paid'),
(1544, 270, 230, 'Mosselbaai Sand'),
(1545, 271, 221, '3211.50'),
(1546, 271, 231, 'INV CAS760209353'),
(1547, 271, 232, 'Not Given'),
(1548, 271, 233, '2024-02-02'),
(1549, 271, 228, 'done'),
(1550, 271, 235, 'Paid'),
(1551, 271, 230, 'Cashbuild'),
(1552, 272, 221, '3749.70'),
(1553, 272, 231, 'INV269408'),
(1554, 272, 232, '11G0010'),
(1555, 272, 233, '2024-01-25'),
(1556, 272, 228, 'done'),
(1557, 272, 230, 'Build It'),
(1558, 272, 235, 'Paid'),
(1559, 273, 221, '36549.00'),
(1560, 273, 231, 'Not Given'),
(1561, 273, 232, 'Not Given'),
(1562, 273, 233, '2024-01-22'),
(1563, 273, 228, 'done'),
(1564, 273, 235, 'Paid'),
(1565, 273, 230, 'SC Reinvorcing'),
(1566, 274, 221, '4930.82'),
(1567, 274, 231, 'INV144491'),
(1568, 274, 232, 'Not Given'),
(1569, 274, 233, '2024-01-23'),
(1570, 274, 228, 'done'),
(1571, 274, 235, 'Paid'),
(1572, 274, 230, 'SC Reinvorcing'),
(1573, 275, 221, '3340.11'),
(1574, 275, 231, 'INV91627'),
(1575, 275, 232, 'Not Given'),
(1576, 275, 233, '2024-02-02'),
(1577, 275, 228, 'done'),
(1578, 275, 230, 'Transsand'),
(1579, 275, 235, 'Paid'),
(1580, 276, 221, '2104.50'),
(1581, 276, 231, 'INV21336'),
(1582, 276, 232, 'Not Given'),
(1583, 276, 233, '2024-02-13'),
(1584, 276, 228, 'done'),
(1585, 276, 230, 'Mosselbaai Sand'),
(1586, 276, 235, 'Paid'),
(1587, 277, 221, '699.90'),
(1588, 277, 231, 'INV CAS376029922'),
(1589, 277, 232, 'Not Given'),
(1590, 277, 233, '2024-02-07'),
(1591, 277, 228, 'done'),
(1592, 277, 235, 'Paid'),
(1593, 277, 230, 'Cashbuild'),
(1594, 278, 221, '669.99'),
(1595, 278, 231, 'Not Given'),
(1596, 278, 232, 'Not Given'),
(1597, 278, 233, '2024-02-09'),
(1598, 278, 228, 'done'),
(1599, 278, 230, 'prosite'),
(1600, 278, 235, 'Paid'),
(1601, 279, 221, '3780.00'),
(1602, 279, 231, 'Not Given'),
(1603, 279, 232, 'Not Given'),
(1604, 279, 233, '2024-03-27'),
(1605, 279, 228, 'done'),
(1606, 279, 235, 'Paid'),
(1607, 279, 230, 'Prosite'),
(1608, 280, 221, '1435.98'),
(1609, 280, 231, 'QUT263979'),
(1610, 280, 232, 'Not Given'),
(1611, 280, 233, '2024-04-22'),
(1612, 280, 228, 'done'),
(1613, 280, 235, 'Not Paid'),
(1614, 280, 230, 'Build It'),
(1615, 281, 221, '74140.00'),
(1616, 281, 231, 'Not Given'),
(1617, 281, 232, 'Not Given'),
(1618, 281, 233, '2024-01-25'),
(1619, 281, 228, 'done'),
(1620, 281, 235, 'Paid'),
(1621, 281, 230, 'Jhonsons Bricks'),
(1622, 282, 221, '5599.53'),
(1623, 282, 231, 'INV272515'),
(1624, 282, 232, 'Not Given'),
(1625, 282, 233, '2024-02-12'),
(1626, 282, 235, 'Paid'),
(1627, 282, 228, 'done'),
(1628, 282, 230, 'Build It'),
(1629, 283, 221, '1149.00'),
(1630, 283, 231, 'INV CAS3760210550'),
(1631, 283, 232, 'Not Given'),
(1632, 283, 233, '2024-02-14'),
(1633, 283, 228, 'done'),
(1634, 283, 230, 'CashBuild'),
(1635, 283, 235, 'Paid'),
(1636, 284, 221, '416.80'),
(1637, 284, 231, 'INV1517322'),
(1638, 284, 232, 'Not Given'),
(1639, 284, 233, '2024-02-15'),
(1640, 284, 228, 'done'),
(1641, 284, 230, 'Builders home'),
(1642, 284, 235, 'Paid'),
(1643, 285, 221, '1489.88'),
(1644, 285, 231, 'INV272946'),
(1645, 285, 232, 'Not Given'),
(1646, 285, 233, '2024-02-15'),
(1647, 285, 228, 'done'),
(1648, 285, 230, 'Build It'),
(1649, 285, 235, 'Paid'),
(1650, 286, 221, '2639.80'),
(1651, 286, 231, 'INV273522'),
(1652, 286, 232, 'Not Given'),
(1653, 286, 233, '2024-02-19'),
(1654, 286, 228, 'done'),
(1655, 286, 230, 'Build it'),
(1656, 286, 235, 'Paid'),
(1657, 287, 221, '3478.73'),
(1658, 287, 231, 'INV92105'),
(1659, 287, 232, '11G0022'),
(1660, 287, 233, '2024-02-19'),
(1661, 287, 228, 'done'),
(1662, 287, 230, 'Transsand'),
(1663, 287, 235, 'Paid'),
(1664, 288, 221, '3211.50'),
(1665, 288, 231, 'INV CAS3760209353'),
(1666, 288, 232, 'Not Given'),
(1667, 288, 233, '2024-02-02'),
(1668, 288, 228, 'done'),
(1669, 288, 235, 'Paid'),
(1670, 288, 230, 'Cashbuild'),
(1671, 289, 221, '2374.00'),
(1672, 289, 231, 'INV CAS3760209684'),
(1673, 289, 232, 'Not Given'),
(1674, 289, 233, '2024-02-04'),
(1675, 289, 228, 'done'),
(1676, 289, 235, 'Paid'),
(1677, 289, 230, 'Cashbuild'),
(1678, 290, 221, '1249.00'),
(1679, 290, 231, 'INV273800'),
(1680, 290, 232, 'Not Given'),
(1681, 290, 233, '2025-02-20'),
(1682, 290, 228, 'done'),
(1683, 290, 235, 'Paid'),
(1684, 290, 230, 'Build It'),
(1685, 291, 221, '96.64'),
(1686, 291, 231, 'INV1493377'),
(1687, 291, 232, 'Not Given'),
(1688, 291, 233, '2024-02-05'),
(1689, 291, 228, 'done'),
(1690, 291, 235, 'Paid'),
(1691, 291, 230, 'Build It'),
(1692, 292, 221, '2499.80'),
(1693, 292, 231, 'INV273922'),
(1694, 292, 232, 'Not Given'),
(1695, 292, 233, '2024-02-21'),
(1696, 292, 228, 'done'),
(1697, 292, 235, 'Paid'),
(1698, 292, 230, 'Build It'),
(1699, 293, 221, '15653.83'),
(1700, 293, 231, 'INV274112'),
(1701, 293, 232, '11G0027'),
(1702, 293, 233, '2024-02-22'),
(1703, 293, 228, 'done'),
(1704, 293, 230, 'Build It'),
(1705, 293, 235, 'Paid'),
(1706, 294, 221, '5217.20'),
(1707, 294, 231, 'INV274967'),
(1708, 294, 232, 'Not Given'),
(1709, 294, 233, '2024-02-27'),
(1710, 294, 228, 'done'),
(1711, 294, 235, 'Paid'),
(1712, 294, 230, 'Build It'),
(1713, 295, 221, '2104.50'),
(1714, 295, 231, 'INV21365'),
(1715, 295, 232, '11G0026'),
(1716, 295, 233, '2024-02-26'),
(1717, 295, 228, 'done'),
(1718, 295, 235, 'Paid'),
(1719, 295, 230, 'Mosselbaai Sand'),
(1720, 296, 221, '2900.95'),
(1721, 296, 231, 'INV275225'),
(1722, 296, 232, '11G0031'),
(1723, 296, 233, '2024-02-28'),
(1724, 296, 228, 'done'),
(1725, 296, 235, 'Paid'),
(1726, 296, 230, 'Build It'),
(1727, 297, 221, '2793.06'),
(1728, 297, 231, 'INV104915'),
(1729, 297, 232, 'Not Given'),
(1730, 297, 233, '2024-02-28'),
(1731, 297, 228, 'done'),
(1732, 297, 235, 'Paid'),
(1733, 297, 230, 'Wantranc'),
(1734, 298, 221, '6641.25'),
(1735, 298, 231, 'INV144834'),
(1736, 298, 232, 'Not Given'),
(1737, 298, 233, '2024-02-12'),
(1738, 298, 228, 'done'),
(1739, 298, 235, 'Paid'),
(1740, 298, 230, 'SC Reinforcing'),
(1741, 299, 221, '1064.89'),
(1742, 299, 231, 'INV275604'),
(1743, 299, 232, 'Not Given'),
(1744, 299, 233, '2024-03-01'),
(1745, 299, 228, 'done'),
(1746, 299, 230, 'Build It'),
(1747, 299, 235, 'Paid'),
(1748, 300, 221, '44525.35'),
(1749, 300, 231, 'Not Given'),
(1750, 300, 232, 'Not Given'),
(1751, 300, 233, '2024-03-11'),
(1752, 300, 228, 'done'),
(1753, 300, 235, 'Paid'),
(1754, 300, 230, 'Rockblend'),
(1755, 301, 221, '699.99'),
(1756, 301, 231, 'INV276548'),
(1757, 301, 232, '11G0034'),
(1758, 301, 233, '2024-03-11'),
(1759, 301, 228, 'done'),
(1760, 301, 230, 'Build It'),
(1761, 301, 235, 'Paid'),
(1762, 302, 221, '19978.60'),
(1763, 302, 231, 'INV278747'),
(1764, 302, 232, '11G0036'),
(1765, 302, 233, '2024-03-20'),
(1766, 302, 228, 'done'),
(1767, 302, 230, 'Build It'),
(1768, 302, 235, 'Paid'),
(1769, 303, 221, '2104.50'),
(1770, 303, 231, 'Not Given'),
(1771, 303, 232, '11G0037'),
(1772, 303, 233, '2024-03-21'),
(1773, 303, 228, 'done'),
(1774, 303, 230, 'Build It'),
(1775, 303, 235, 'Paid'),
(1776, 304, 230, 'Build It'),
(1777, 304, 235, 'Paid'),
(1778, 304, 228, 'done'),
(1779, 304, 231, 'INV279580'),
(1780, 304, 221, '3889.70'),
(1781, 304, 232, '11G0039'),
(1782, 304, 233, '2024-03-26'),
(1783, 305, 221, '20246.25'),
(1784, 305, 231, 'INV SOQ64947'),
(1785, 305, 232, 'Not Given '),
(1786, 305, 233, '2024-03-22'),
(1787, 305, 228, 'done'),
(1788, 305, 230, 'Steel & Pipes'),
(1789, 305, 235, 'Paid'),
(1790, 306, 221, '3333.51'),
(1791, 306, 231, 'INV93323'),
(1792, 306, 232, '11G0040'),
(1793, 306, 233, '2024-03-27'),
(1794, 306, 228, 'done'),
(1795, 306, 230, 'Transsand'),
(1796, 306, 235, 'Paid'),
(1797, 307, 221, '4969.50'),
(1798, 307, 231, 'INV349457'),
(1799, 307, 232, 'GRAN0010'),
(1800, 307, 233, '2024-05-14'),
(1801, 307, 228, 'done'),
(1802, 307, 230, 'Build it'),
(1803, 307, 235, 'Paid'),
(1804, 308, 221, '3924.70'),
(1805, 308, 231, 'INV352574'),
(1806, 308, 232, 'GRAN0015'),
(1807, 308, 233, '2024-06-03'),
(1808, 308, 228, 'done'),
(1809, 308, 230, 'Build It'),
(1810, 308, 235, 'Paid'),
(1811, 309, 221, '3924.70'),
(1812, 309, 231, 'INV353046'),
(1813, 309, 232, 'GRAN0015 B'),
(1814, 309, 233, '2024-06-03'),
(1815, 309, 228, 'done'),
(1816, 309, 230, 'Build It'),
(1817, 309, 235, 'Paid'),
(1818, 310, 221, '149749.88'),
(1819, 310, 230, 'Mervin Card (Clif)'),
(1820, 310, 228, 'done'),
(1821, 310, 235, 'Paid'),
(1822, 310, 232, 'Not Given'),
(1823, 310, 231, 'Not Given'),
(1824, 311, 221, '69305.06'),
(1825, 311, 231, 'Not Given'),
(1826, 311, 232, 'Not Given'),
(1827, 311, 233, '2024-03-25'),
(1828, 311, 228, 'done'),
(1829, 311, 235, 'Paid'),
(1830, 311, 230, 'South Cape Reinforce'),
(1831, 312, 221, '9156.86'),
(1832, 312, 231, 'Not Given'),
(1833, 312, 232, 'Not Given'),
(1834, 312, 230, 'Build It\r\n'),
(1835, 312, 228, 'done'),
(1836, 312, 235, 'Paid'),
(1837, 313, 221, '823.96'),
(1838, 313, 231, 'INV346131'),
(1839, 313, 232, 'Not Given'),
(1840, 313, 233, '2024-04-24'),
(1841, 313, 228, 'done'),
(1842, 313, 230, 'Build It'),
(1843, 313, 235, 'Paid'),
(1844, 314, 221, '1773.91'),
(1845, 314, 230, 'Build It'),
(1846, 314, 235, 'Paid'),
(1847, 314, 228, 'done'),
(1848, 314, 231, 'QUT263930'),
(1849, 314, 232, 'Not Given'),
(1850, 314, 233, '2024-04-21'),
(1851, 315, 221, '146489.50'),
(1852, 315, 231, 'Q36063'),
(1853, 315, 232, 'Not Given'),
(1854, 315, 233, '2024-04-14'),
(1855, 315, 228, 'done'),
(1856, 315, 230, 'Rockblend'),
(1857, 315, 235, 'Paid'),
(1858, 316, 221, '11240.67'),
(1859, 316, 231, 'Q36152'),
(1860, 316, 232, 'Not Given'),
(1861, 316, 233, '2024-04-24'),
(1862, 316, 228, 'done'),
(1863, 316, 230, 'Rockblend'),
(1864, 316, 235, 'Paid'),
(1865, 317, 221, '10839.29'),
(1866, 317, 231, 'QUT260630'),
(1867, 317, 232, 'GRAN0002'),
(1868, 317, 228, 'done'),
(1869, 317, 235, 'Paid'),
(1870, 317, 230, 'Build It'),
(1871, 318, 145, 'Netting and poles\r\n'),
(1872, 318, 236, '3792.00'),
(1873, 318, 237, '15168.00'),
(1875, 318, 238, '4'),
(1876, 319, 145, 'Sundries\r\n'),
(1877, 319, 236, '3150'),
(1878, 319, 238, '1'),
(1879, 320, 145, 'Labour'),
(1880, 320, 236, '2650.00'),
(1881, 320, 238, '2'),
(1883, 319, 237, '3150.00'),
(1884, 320, 237, '5300.00'),
(1888, 321, 145, 'Ramp to Workshop\r\n'),
(1889, 321, 236, '2300.00'),
(1890, 321, 238, '1'),
(1891, 322, 145, 'Tiles\r\n'),
(1892, 322, 236, '35.00'),
(1893, 322, 238, '175'),
(1894, 323, 145, '2 New front entrances\r\n'),
(1895, 323, 236, '4500.00'),
(1896, 323, 238, '2'),
(1897, 324, 145, 'Tiles\r\n'),
(1898, 324, 236, '199.00'),
(1899, 324, 238, '175'),
(1900, 325, 145, 'Cement and sundries\r\n'),
(1901, 325, 236, '65.00'),
(1902, 325, 238, '175'),
(1903, 326, 145, 'Labour\r\n'),
(1904, 326, 236, '95.00'),
(1905, 326, 238, '175'),
(1906, 327, 145, 'Install Lintels\r\n'),
(1907, 327, 236, '950.00'),
(1908, 327, 238, '4'),
(1909, 328, 145, 'Supply Aluminium & Glass\r\n'),
(1910, 328, 236, '2750.00'),
(1911, 328, 238, '67'),
(1912, 329, 145, 'Round off shopfront\r\n'),
(1913, 329, 236, '5500.00'),
(1914, 329, 238, '1'),
(1915, 330, 145, 'Excavation\r\n'),
(1916, 330, 236, '850.00'),
(1917, 330, 238, '5'),
(1918, 331, 145, 'Steel\r\n'),
(1919, 331, 236, '1100.00'),
(1920, 331, 238, '5'),
(1921, 332, 145, 'Concrete\r\n'),
(1922, 332, 236, '2950.00'),
(1923, 332, 238, '4'),
(1924, 333, 145, 'Underpinning under old building\r\n'),
(1925, 333, 236, '9500.00'),
(1926, 333, 238, '1'),
(1927, 334, 145, 'Building of foundation walls\r\n'),
(1928, 334, 236, '9680.00'),
(1929, 334, 238, '1'),
(1930, 335, 145, 'Compacting and filling\r\n'),
(1931, 335, 236, '6500.00'),
(1932, 335, 238, '1'),
(1933, 336, 145, 'Ref 193 And DPC\r\n'),
(1934, 336, 236, '5500.00'),
(1935, 336, 238, '1'),
(1936, 337, 145, 'Slab concrete\r\n'),
(1937, 337, 236, '2950.00'),
(1938, 337, 238, '3'),
(1939, 338, 145, 'Labour\r\n'),
(1940, 338, 236, '2450.00'),
(1941, 338, 238, '6'),
(1942, 339, 145, 'Brickwork with casted column\r\n'),
(1943, 339, 236, '12310.00'),
(1944, 339, 238, '1'),
(1945, 340, 145, 'Ibeams\r\n'),
(1946, 340, 236, '13200.00'),
(1947, 340, 238, '1'),
(1948, 341, 145, 'Above Ibeam Brickwork\r\n'),
(1949, 341, 236, '14520.00'),
(1950, 341, 238, '1'),
(1951, 342, 145, 'Structure for Sheeting\r\n'),
(1952, 342, 236, '250.00'),
(1953, 342, 238, '27'),
(1954, 343, 145, 'Sundries\r\n'),
(1955, 343, 236, '2500.00'),
(1956, 343, 238, '1'),
(1957, 344, 145, 'IBR Cromadek\r\n'),
(1958, 344, 236, '180.00'),
(1959, 344, 238, '27'),
(1960, 345, 145, 'Install cromadec\r\n'),
(1961, 345, 236, '95.00'),
(1962, 345, 238, '27'),
(1963, 346, 145, 'Supply material and Sundries\r\n'),
(1964, 346, 236, '7500.00'),
(1965, 346, 238, '1'),
(1966, 347, 145, 'Labour\r\n'),
(1967, 347, 236, '6500.00'),
(1968, 347, 238, '1'),
(1969, 348, 145, 'Supply Aluminium and Glass\r\n'),
(1970, 348, 236, '2800.00'),
(1971, 348, 238, '66'),
(1972, 349, 145, 'Install\r\n'),
(1973, 349, 236, '250.00'),
(1974, 349, 238, '33'),
(1975, 350, 145, 'Supply and install'),
(1976, 350, 236, '320'),
(1977, 350, 238, '31'),
(1978, 351, 145, 'Ceiling Repairs\r\n'),
(1979, 351, 236, '9500.00'),
(1980, 351, 238, '1'),
(1981, 352, 145, 'Make all electrical Safe\r\n'),
(1982, 352, 236, '4500.00'),
(1983, 352, 238, '1'),
(1984, 353, 145, 'Supply and install new led lights\r\n'),
(1985, 353, 236, '1399.00'),
(1986, 353, 238, '6'),
(1987, 354, 145, 'Supply and install drywalingl\r\n'),
(1988, 354, 236, '690.00'),
(1989, 354, 238, '144'),
(1990, 355, 145, 'Roof ontop of cubacle\r\n'),
(1991, 355, 236, '650.00'),
(1992, 355, 238, '144'),
(1993, 356, 145, 'lifted floor\r\n'),
(1994, 356, 236, '450.00'),
(1995, 356, 238, '144'),
(1996, 357, 145, 'Diamond Grind Floor\r\n'),
(1997, 357, 236, '350.00'),
(1998, 357, 238, '506'),
(1999, 358, 145, 'Epoxy floor 2 coats\r\n'),
(2000, 358, 236, '250.00'),
(2001, 358, 238, '506'),
(2002, 359, 145, 'Labour\r\n'),
(2003, 359, 236, '1500.00'),
(2004, 359, 238, '3'),
(2005, 360, 145, 'Insert 2 new entrances'),
(2006, 360, 236, '5500.00'),
(2007, 360, 238, '2'),
(2008, 361, 145, 'Insert 2 lintels and plaster\r\n'),
(2009, 361, 236, '3500.00'),
(2010, 361, 238, '2'),
(2011, 362, 145, 'Supply Paint\r\n'),
(2012, 362, 236, '1199.00'),
(2013, 362, 238, '6'),
(2014, 363, 145, 'Labour'),
(2015, 363, 236, '65.00'),
(2016, 363, 238, '420'),
(2017, 364, 145, 'Remove carports\r\n'),
(2018, 364, 236, '6500.00'),
(2019, 364, 238, '1'),
(2020, 365, 145, 'Installing at back\r\n'),
(2021, 365, 236, '13200.00'),
(2022, 365, 238, '1'),
(2023, 366, 145, 'Supply and install Clearview\r\n'),
(2024, 366, 236, '900.00'),
(2025, 366, 238, '35'),
(2026, 367, 145, 'Plugs'),
(2027, 367, 236, '850.00'),
(2028, 367, 238, '12'),
(2029, 368, 145, 'Lights\r\n'),
(2030, 368, 236, '650.00'),
(2031, 368, 238, '6'),
(2032, 369, 145, 'Supply and paint plaster primer\r\n'),
(2033, 369, 236, '85.00'),
(2034, 369, 238, '112'),
(2035, 370, 145, 'Rapair outside wall and prepair\r\n'),
(2036, 370, 236, '35.00'),
(2037, 370, 238, '700'),
(2038, 371, 145, 'Paint outside wall\r\n'),
(2039, 371, 236, '65.00'),
(2040, 371, 238, '700'),
(2041, 372, 145, 'Supply outside paint\r\n'),
(2042, 372, 236, '1799.00'),
(2043, 372, 238, '10'),
(2044, 373, 145, 'Supply and install'),
(2045, 373, 236, '650.00'),
(2046, 373, 238, '12'),
(2047, 374, 239, 'Repair all walls and balconies with Duragrout\r\n'),
(2048, 374, 251, '420'),
(2049, 374, 250, '210.00'),
(2050, 374, 253, '88200.00'),
(2051, 375, 239, 'Prepare wall for repainting\r\n\r\n'),
(2052, 375, 251, '115'),
(2053, 375, 250, '65.00'),
(2054, 376, 239, 'Paint wall 2 coats matching Dulux\r\n\r\n'),
(2055, 376, 251, '115'),
(2056, 376, 250, '95.00'),
(2057, 377, 239, 'Prepare balcony wall + waterproof joints\r\n'),
(2058, 377, 251, '50'),
(2059, 377, 250, '95.00'),
(2060, 378, 239, 'Paint balcony wall 2 coats matching Dulux\r\n'),
(2061, 378, 251, '50'),
(2062, 378, 250, '95.00'),
(2063, 379, 239, 'Prepare balcony fence\r\n'),
(2064, 379, 251, '322'),
(2065, 379, 250, '65.00'),
(2066, 380, 239, 'Paint balcony fence 2 coats enamel\r\n'),
(2067, 380, 251, '322'),
(2068, 380, 250, '95.00'),
(2069, 381, 239, 'Prepare balcony ceiling, replace damaged board\r\n'),
(2070, 381, 251, '55'),
(2071, 381, 250, '210.00'),
(2072, 382, 239, 'Paint balcony ceiling 2 coats\r\n'),
(2073, 382, 251, '55'),
(2074, 382, 250, '95.00'),
(2075, 383, 239, 'Prepare Northside windows, repaint & seal joints\r\n'),
(2076, 383, 251, '19'),
(2077, 383, 250, '350.00'),
(2078, 384, 239, 'Paint Northside windows 2 coats Dulux\r\n'),
(2079, 384, 251, '19'),
(2080, 384, 250, '135.00'),
(2081, 385, 239, 'Prepare lake-side windows, remove damaged putty\r\n'),
(2082, 385, 251, '221'),
(2083, 385, 250, '95.00'),
(2084, 386, 239, 'Paint lake-side windows & wall 2 coats Dulux\r\n'),
(2085, 386, 251, '221'),
(2086, 386, 250, '135.00'),
(2087, 387, 239, 'Prepare basement pillars & walls\r\n'),
(2088, 387, 251, '115.4'),
(2089, 387, 250, '65.00'),
(2090, 388, 239, 'Paint basement pillars & walls 2 coats Dulux\r\n'),
(2091, 388, 251, '115.4'),
(2092, 388, 250, '95.00'),
(2093, 389, 239, 'Prepare lake-side balcony burglar fence\r\n'),
(2094, 389, 251, '87'),
(2095, 389, 250, '85.00'),
(2096, 390, 239, 'Paint lake-side burglar fence 2 coats\r\n'),
(2097, 390, 251, '87'),
(2098, 390, 250, '115.00'),
(2099, 391, 239, 'Prepare precast wall for repainting\r\n'),
(2100, 391, 251, '152'),
(2101, 391, 250, '85.00'),
(2102, 392, 239, 'Prepare west-side windows, remove damaged putty\r\n'),
(2103, 392, 251, '82'),
(2104, 392, 250, '85.00'),
(2105, 393, 239, 'Paint west-side windows 2 coats matching\r\n'),
(2106, 393, 251, '82'),
(2107, 393, 250, '135.00'),
(2108, 394, 221, '60469.67'),
(2109, 394, 232, 'Not Given'),
(2110, 394, 228, 'done'),
(2111, 394, 235, 'Paid'),
(2112, 394, 230, 'Build It'),
(2113, 394, 231, 'QUT264354'),
(2114, 394, 233, '2024-04-24'),
(2115, 395, 221, '9936.63'),
(2116, 395, 231, 'QUT264326'),
(2117, 395, 232, 'Not Given'),
(2118, 395, 233, '2024-04-24'),
(2119, 395, 228, 'done'),
(2120, 395, 230, 'Build It'),
(2121, 395, 235, 'Paid'),
(2122, 396, 221, '954.89'),
(2123, 396, 231, 'QUT265719'),
(2124, 396, 232, 'Not Given'),
(2125, 396, 233, '2024-05-03'),
(2126, 396, 228, 'done'),
(2127, 396, 230, 'Build It'),
(2128, 396, 235, 'Paid'),
(2129, 397, 221, '50647.97'),
(2130, 397, 231, 'QUT265843'),
(2131, 397, 232, 'Not Given'),
(2132, 397, 233, '2024-05-05'),
(2133, 397, 228, 'done'),
(2134, 397, 230, 'Build It'),
(2135, 397, 235, 'Paid'),
(2136, 398, 221, '2474.21'),
(2137, 398, 231, 'INV348433'),
(2138, 398, 232, 'Not Given'),
(2139, 398, 233, '2024-05-08'),
(2140, 398, 228, 'done'),
(2141, 398, 235, 'Paid'),
(2142, 398, 230, 'Build It'),
(2143, 399, 221, '14542.85'),
(2144, 399, 231, 'INV SOQ245619'),
(2145, 399, 232, 'GRAN0014'),
(2146, 399, 233, '2024-05-20'),
(2147, 399, 228, 'done'),
(2148, 399, 235, 'Paid'),
(2149, 399, 230, 'ASAP Seel Alberton'),
(2150, 400, 221, '69851.40'),
(2151, 400, 231, 'QUT26807'),
(2152, 400, 232, 'GRAN0009'),
(2153, 400, 233, '2024-05-19'),
(2154, 400, 228, 'done'),
(2155, 400, 235, 'Paid'),
(2156, 400, 230, 'Build It'),
(2157, 401, 221, '8064.60'),
(2158, 401, 231, 'INV349572'),
(2159, 401, 232, 'GRAN0008'),
(2160, 401, 233, '2024-05-15'),
(2161, 401, 228, 'done'),
(2162, 401, 235, 'Paid'),
(2163, 401, 230, 'Build It'),
(2164, 402, 221, '1020.78'),
(2165, 402, 231, 'INV532772'),
(2166, 402, 232, 'GRAN0016 B'),
(2167, 402, 233, '2024-06-04'),
(2168, 402, 228, 'done'),
(2169, 402, 230, 'Build It'),
(2170, 402, 235, 'Paid'),
(2171, 403, 221, '16850.00'),
(2172, 403, 231, 'INV355345'),
(2173, 403, 232, 'GRAN0017'),
(2174, 403, 233, '2024-05-19'),
(2175, 403, 228, 'done'),
(2176, 403, 230, 'Build It'),
(2177, 403, 235, 'Paid'),
(2178, 404, 221, '159.98'),
(2179, 404, 231, 'INV353946'),
(2180, 404, 232, 'GRAN0018'),
(2181, 404, 233, '2024-05-11'),
(2182, 404, 228, 'done'),
(2183, 404, 235, 'Paid'),
(2184, 404, 230, 'Build It'),
(2185, 405, 221, '49307.08'),
(2186, 405, 231, 'INV362004'),
(2187, 405, 232, 'GRAN0036'),
(2188, 405, 233, '2024-07-31'),
(2189, 406, 221, '1415.00'),
(2190, 406, 231, 'INV84710'),
(2191, 406, 232, 'GRAN0034'),
(2192, 406, 233, '2024-08-11'),
(2193, 406, 228, 'done'),
(2194, 405, 228, 'done'),
(2195, 405, 230, 'Build It'),
(2196, 406, 230, 'Tool Quip'),
(2197, 406, 235, 'Paid'),
(2198, 405, 235, 'Paid'),
(2199, 407, 221, '4480.00'),
(2200, 407, 231, 'INV2989'),
(2201, 407, 232, 'GRAN0037'),
(2202, 407, 233, '2024-08-11'),
(2203, 407, 228, 'done'),
(2204, 407, 230, 'Nivancas Sand'),
(2205, 407, 235, 'Paid'),
(2206, 408, 221, '2139.00'),
(2207, 408, 231, 'INV1310'),
(2208, 408, 232, 'GRAN0047'),
(2209, 408, 233, '2024-08-08'),
(2210, 408, 228, 'done'),
(2211, 408, 230, 'Muller Feeds sand'),
(2212, 408, 235, 'Paid'),
(2213, 409, 221, '34072.84'),
(2214, 409, 231, 'QUT285749'),
(2215, 409, 232, 'GRAN0052'),
(2216, 409, 233, '2024-09-10'),
(2217, 409, 228, 'done'),
(2218, 409, 230, 'Build It'),
(2219, 409, 235, 'Paid'),
(2220, 410, 221, '749.90'),
(2221, 410, 231, 'QUT371346'),
(2222, 410, 232, 'GRAN0058'),
(2223, 410, 233, '2024-09-25'),
(2224, 410, 228, 'done'),
(2225, 410, 235, 'Paid'),
(2226, 410, 230, 'Build It'),
(2227, 411, 221, '15632.20'),
(2228, 411, 231, 'QUT288122'),
(2229, 411, 232, 'GRAN0059'),
(2230, 411, 233, '2024-09-25'),
(2231, 411, 228, 'done'),
(2232, 411, 230, 'Build It'),
(2233, 411, 235, 'Paid'),
(2234, 412, 221, '15632.20'),
(2235, 412, 231, 'INV372683'),
(2236, 412, 232, 'GRAN0063'),
(2237, 412, 233, '2024-10-02'),
(2238, 412, 228, 'done'),
(2239, 412, 230, 'Build It'),
(2240, 412, 235, 'Paid'),
(2241, 413, 221, '293322.72'),
(2242, 413, 231, 'INV5225'),
(2243, 413, 232, 'GRAN0049'),
(2244, 413, 233, '2024-08-20'),
(2245, 413, 228, 'done'),
(2246, 413, 235, 'Paid'),
(2247, 413, 230, 'Mosselbay Roofing'),
(2248, 414, 221, '18000.00'),
(2249, 414, 231, 'Not Given'),
(2250, 414, 232, 'GRAN0053'),
(2251, 414, 233, '2024-09-12'),
(2252, 414, 228, 'done'),
(2253, 414, 235, 'Paid'),
(2254, 414, 230, 'Freddy / kraai'),
(2255, 415, 221, '569.95'),
(2256, 415, 231, 'INV356515'),
(2257, 415, 232, 'GRAN0022'),
(2258, 415, 233, '2024-05-26'),
(2259, 415, 230, 'Build It'),
(2260, 415, 235, 'Paid'),
(2261, 416, 221, '57814.43'),
(2262, 416, 231, 'QUT50377'),
(2263, 416, 232, 'GRAN0004'),
(2264, 416, 228, 'done'),
(2265, 416, 235, 'Paid'),
(2266, 416, 230, 'South Cape Reinforcing '),
(2267, 416, 233, '2024-03-23'),
(2268, 417, 221, '11490.63'),
(2269, 417, 231, 'QUT50376'),
(2270, 417, 232, 'GRAN0005'),
(2271, 417, 233, '2024-03-26'),
(2272, 417, 228, 'done'),
(2273, 417, 235, 'Paid'),
(2274, 417, 230, 'South Cape Reinforcing'),
(2275, 418, 221, '14649.24'),
(2276, 418, 231, 'QUT51666'),
(2277, 418, 232, 'GRAN0033'),
(2278, 418, 233, '2024-07-22'),
(2279, 418, 228, 'done'),
(2280, 418, 235, 'Paid'),
(2281, 418, 230, 'South Cape reinforcing '),
(2282, 419, 221, '5596.33'),
(2283, 419, 231, 'INV372502'),
(2284, 419, 232, 'GRAN0061 B'),
(2285, 419, 228, 'done'),
(2286, 419, 235, 'Paid'),
(2287, 419, 230, 'Build It'),
(2288, 419, 233, '2024-10-01'),
(2289, 420, 221, '7808.50'),
(2290, 420, 231, 'INV14'),
(2291, 420, 232, 'GRAN0050'),
(2292, 420, 233, '2024-08-14'),
(2293, 420, 228, 'done'),
(2294, 420, 230, 'Albertus Plasters\r\n'),
(2295, 420, 235, 'Paid'),
(2296, 421, 221, '2139.00'),
(2297, 421, 231, 'INV1393'),
(2298, 421, 232, 'GRAN0054'),
(2299, 421, 233, '2024-09-25'),
(2300, 421, 228, 'done'),
(2301, 421, 235, 'Paid'),
(2302, 421, 230, 'MF Sands'),
(2303, 422, 221, '10864.09'),
(2304, 422, 231, 'QUT287830'),
(2305, 422, 232, 'GRAN0055'),
(2306, 422, 233, '2024-09-25'),
(2307, 422, 228, 'done'),
(2308, 422, 235, 'Paid'),
(2309, 422, 230, 'Build It'),
(2310, 423, 221, '6670.00'),
(2311, 423, 231, 'QUT10243'),
(2312, 423, 232, 'GRAN0056'),
(2313, 423, 233, '2024-09-24'),
(2314, 423, 228, 'done'),
(2315, 423, 235, 'Paid'),
(2316, 423, 230, 'MF Sands'),
(2317, 424, 221, '10864.09'),
(2318, 424, 231, 'QUT287830'),
(2319, 424, 232, 'GRAN0057'),
(2320, 424, 233, '2024-09-23'),
(2321, 424, 228, 'done'),
(2322, 424, 235, 'Paid'),
(2323, 424, 230, 'Build It'),
(2324, 425, 221, '6875.00'),
(2325, 425, 231, 'CON0076'),
(2326, 425, 232, 'Not Given'),
(2327, 425, 233, '2024-09-26'),
(2328, 425, 228, 'done'),
(2329, 425, 235, 'Paid'),
(2330, 425, 230, 'Albertus plastering'),
(2331, 415, 228, 'done'),
(2332, 427, 221, '25203.44'),
(2333, 427, 231, 'Not Given'),
(2334, 427, 232, 'Not Given'),
(2335, 427, 228, 'done'),
(2336, 427, 235, 'Paid'),
(2337, 428, 221, '38071.56'),
(2338, 428, 231, '10001'),
(2339, 428, 232, 'GRAN0003'),
(2340, 428, 233, '2024-04-30'),
(2341, 428, 228, 'done'),
(2342, 428, 235, 'Paid'),
(2343, 428, 230, 'Plumbsteel'),
(2344, 427, 230, 'Plumbsteel'),
(2345, 429, 221, '1425.39'),
(2346, 429, 231, 'INV371984');
INSERT INTO `board_item_values` (`id`, `item_id`, `column_id`, `value`) VALUES
(2347, 429, 232, 'GRAN0061'),
(2348, 429, 233, '2024-09-29'),
(2349, 429, 228, 'done'),
(2350, 429, 230, 'Build It'),
(2351, 429, 235, 'Paid'),
(2352, 430, 221, '30000.00'),
(2353, 430, 231, 'Not Given'),
(2354, 430, 232, 'GRAN0020'),
(2355, 430, 228, 'done'),
(2356, 430, 235, 'Paid'),
(2357, 430, 230, 'ILJ Electrical '),
(2358, 430, 233, '2024-05-24'),
(2359, 431, 221, '21200.00'),
(2360, 431, 231, 'INV175'),
(2361, 431, 232, 'GRAN0020'),
(2362, 431, 233, '2025-05-24'),
(2363, 431, 228, 'done'),
(2364, 431, 230, 'ILJ Electrical '),
(2365, 431, 235, 'Paid'),
(2366, 432, 221, '549.99'),
(2367, 432, 231, 'INV356668'),
(2368, 432, 232, 'GRAN0023'),
(2369, 432, 233, '2024-05-28'),
(2370, 432, 228, 'done'),
(2371, 432, 235, 'Paid'),
(2372, 432, 230, 'Build It\r\n'),
(2373, 433, 221, '719.92'),
(2374, 433, 231, 'INV364027'),
(2375, 433, 232, 'GRAN0044'),
(2376, 433, 233, '2024-08-12'),
(2377, 433, 228, 'done'),
(2378, 433, 235, 'Paid'),
(2379, 433, 230, 'Build It'),
(2380, 434, 221, '1679.00'),
(2381, 434, 231, 'INV364410'),
(2382, 434, 232, 'GRAN0047 B'),
(2383, 434, 233, '2024-08-14'),
(2384, 434, 228, 'done'),
(2385, 434, 235, 'Paid'),
(2386, 434, 230, 'Build it'),
(2387, 435, 221, '851.96'),
(2388, 435, 231, 'INV357478'),
(2389, 435, 232, 'GRAN0025'),
(2390, 435, 233, '2024-07-03'),
(2391, 435, 228, 'done'),
(2392, 435, 230, 'Build it'),
(2393, 435, 235, 'Paid'),
(2394, 436, 221, '255.95'),
(2395, 436, 231, 'INV357713'),
(2396, 436, 232, 'GRAN0026'),
(2397, 436, 233, '2024-07-04'),
(2398, 436, 228, 'done'),
(2399, 436, 230, 'Build It'),
(2400, 436, 235, 'Paid'),
(2401, 437, 221, '351.96'),
(2402, 437, 231, 'INV363934'),
(2403, 437, 232, 'GRAN0043'),
(2404, 437, 233, '2024-08-12'),
(2405, 437, 228, 'done'),
(2406, 437, 230, 'Build It'),
(2407, 437, 235, 'Paid'),
(2408, 438, 221, '57.99'),
(2409, 438, 231, 'INV1186841'),
(2410, 438, 232, 'Not Given'),
(2411, 438, 233, '2024-03-06'),
(2412, 438, 228, 'done'),
(2413, 438, 235, 'Paid'),
(2414, 438, 230, 'OK'),
(2415, 439, 221, '3311.25'),
(2416, 439, 231, 'Not Given'),
(2417, 439, 232, 'Not Given'),
(2418, 439, 233, '2023-11-03'),
(2419, 439, 228, 'done'),
(2420, 439, 235, 'Paid'),
(2421, 439, 230, 'Prosite'),
(2422, 440, 221, '9600.00'),
(2423, 440, 231, 'Not Given'),
(2424, 440, 232, 'Not Given'),
(2425, 440, 233, '2023-11-17'),
(2426, 440, 228, 'done'),
(2427, 440, 235, 'Paid'),
(2428, 440, 230, 'Prosite'),
(2429, 441, 221, '3140.00'),
(2430, 441, 231, 'Not Given '),
(2431, 441, 232, 'Not Given'),
(2432, 442, 231, 'Not Given'),
(2433, 442, 232, 'Not Given'),
(2434, 441, 233, '2023-12-01'),
(2435, 441, 228, 'done'),
(2436, 441, 230, 'Prosite'),
(2437, 441, 235, 'Paid'),
(2438, 442, 235, 'Paid'),
(2439, 442, 228, 'done'),
(2440, 442, 230, 'Prosite'),
(2441, 442, 221, '49010.00'),
(2442, 442, 233, '2024-01-19'),
(2443, 443, 221, '27160.00'),
(2444, 443, 231, 'Not Given'),
(2445, 443, 232, 'Not Given'),
(2446, 443, 233, '2024-02-02'),
(2447, 443, 228, 'done'),
(2448, 443, 230, 'Prosite'),
(2449, 443, 235, 'Paid'),
(2450, 444, 221, '29930.00'),
(2451, 444, 233, '2024-02-16'),
(2452, 444, 231, 'Not Given '),
(2453, 444, 232, 'Not Given'),
(2454, 444, 228, 'done'),
(2455, 444, 235, 'Paid'),
(2456, 444, 230, 'Prosite'),
(2457, 445, 221, '3020.00'),
(2458, 445, 231, 'Not Given'),
(2459, 445, 232, 'Not Given'),
(2460, 445, 233, '2024-02-16'),
(2461, 445, 228, 'done'),
(2462, 445, 230, 'Prosite'),
(2463, 445, 235, 'Paid'),
(2464, 446, 221, '37720.00'),
(2465, 446, 231, 'Not Given'),
(2466, 446, 232, 'Not Given'),
(2467, 446, 233, '2024-03-01'),
(2468, 446, 228, 'done'),
(2469, 446, 230, 'Prosite'),
(2470, 446, 235, 'Paid'),
(2471, 447, 221, '24810.00'),
(2472, 447, 231, 'Not Given'),
(2473, 447, 232, 'Not Given'),
(2474, 447, 233, '2024-03-01'),
(2475, 447, 228, 'done'),
(2476, 447, 230, 'Prosite'),
(2477, 447, 235, 'Paid'),
(2478, 448, 221, '6210.00'),
(2479, 448, 231, 'Not Given'),
(2480, 448, 232, 'Not Given'),
(2481, 448, 228, 'done'),
(2482, 448, 230, 'Prosite'),
(2483, 448, 235, 'Paid'),
(2484, 449, 221, '9040.00'),
(2485, 449, 231, 'Not Given'),
(2486, 449, 232, 'Not Given'),
(2487, 449, 233, '2024-02-28'),
(2488, 449, 228, 'done'),
(2489, 449, 230, 'Concreto'),
(2490, 449, 235, 'Paid'),
(2491, 450, 221, '26130.00'),
(2492, 450, 231, 'QUT  CON0010'),
(2493, 450, 232, 'Not Given'),
(2494, 450, 233, '2024-03-25'),
(2495, 450, 228, 'done'),
(2496, 450, 230, 'Concreto'),
(2497, 450, 235, 'Paid'),
(2498, 451, 221, '24770.00'),
(2499, 451, 231, 'QUT  CON0015'),
(2500, 451, 232, 'Not Given'),
(2501, 451, 233, '2024-04-11'),
(2502, 451, 228, 'done'),
(2503, 451, 230, 'Concreto'),
(2504, 451, 235, 'Paid'),
(2505, 452, 221, '26080.00'),
(2506, 452, 231, 'QUT  CON0019'),
(2507, 452, 232, 'Not Given'),
(2508, 452, 233, '2024-04-25'),
(2509, 452, 228, 'done'),
(2510, 452, 230, 'Concreto'),
(2511, 452, 235, 'Paid'),
(2512, 453, 221, '36540.00'),
(2513, 453, 231, 'QUT  CON0024'),
(2514, 453, 232, 'Not Given'),
(2515, 453, 233, '2024-05-09'),
(2516, 453, 228, 'done'),
(2517, 453, 230, 'Concreto'),
(2518, 453, 235, 'Paid'),
(2519, 454, 221, '42410.00'),
(2520, 454, 231, 'QUT  CON0032'),
(2521, 454, 232, 'Not Given'),
(2522, 454, 233, '2024-05-22'),
(2523, 454, 228, 'done'),
(2524, 454, 230, 'Concreto'),
(2525, 454, 235, 'Paid'),
(2526, 455, 221, '41250.00'),
(2527, 455, 231, 'QUT  CON0036'),
(2528, 455, 232, 'Not Given'),
(2529, 455, 233, '2024-06-06'),
(2530, 455, 228, 'done'),
(2531, 455, 235, 'Paid'),
(2532, 455, 230, 'Concreto'),
(2533, 456, 221, '21350.00'),
(2534, 456, 231, 'QUT  CON0038'),
(2535, 456, 232, 'Not Given'),
(2536, 456, 233, '2024-06-20'),
(2537, 456, 228, 'done'),
(2538, 456, 230, 'Concreto'),
(2539, 456, 235, 'Paid'),
(2540, 457, 221, '12800.00'),
(2541, 457, 231, 'QUT  CON0043'),
(2542, 457, 232, 'Not Given'),
(2543, 457, 233, '2024-07-04'),
(2544, 457, 228, 'done'),
(2545, 457, 230, 'Concreto'),
(2546, 457, 235, 'Paid'),
(2547, 458, 221, '6140.00'),
(2548, 458, 231, 'QUT  CON0044'),
(2549, 458, 232, 'Not Given'),
(2550, 458, 233, '2024-07-18'),
(2551, 458, 228, 'done'),
(2552, 458, 230, 'Concreto'),
(2553, 458, 235, 'Paid'),
(2554, 459, 221, '15050.00'),
(2555, 459, 231, 'QUT  CON0051'),
(2556, 459, 232, 'Not Given'),
(2557, 459, 233, '2024-08-01'),
(2558, 459, 228, 'done'),
(2559, 459, 230, 'Concreto'),
(2560, 459, 235, 'Paid'),
(2561, 460, 221, '455030.00'),
(2562, 460, 231, 'QUT  CON0054'),
(2563, 460, 232, 'Not Given'),
(2564, 460, 233, '2024-08-15'),
(2565, 460, 228, 'done'),
(2566, 460, 230, 'Concreto'),
(2567, 460, 235, 'Paid'),
(2568, 461, 221, '12710.00'),
(2569, 461, 231, 'QUT  CON0064'),
(2570, 461, 232, 'Not Given'),
(2571, 461, 233, '2024-08-29'),
(2572, 461, 228, 'done'),
(2573, 461, 230, 'Concreto'),
(2574, 461, 235, 'Paid'),
(2575, 462, 221, '29330.00'),
(2576, 462, 231, 'QUT  CON0068'),
(2577, 462, 232, 'Not Given'),
(2578, 462, 233, '2024-09-12'),
(2579, 462, 228, 'done'),
(2580, 462, 230, 'Concreto'),
(2581, 462, 235, 'Paid'),
(2582, 463, 221, '40140.00'),
(2583, 463, 231, 'QUT  CON0075'),
(2584, 463, 232, 'Not Given'),
(2585, 463, 233, '2024-09-26'),
(2586, 463, 228, 'done'),
(2587, 463, 230, 'Concreto'),
(2588, 463, 235, 'Paid'),
(2589, 464, 221, '35000.00'),
(2590, 464, 231, 'Not Given'),
(2591, 464, 232, 'Not Given'),
(2592, 464, 233, '2024-07-31'),
(2593, 464, 228, 'done'),
(2594, 464, 235, 'Paid'),
(2595, 464, 230, 'Not Given'),
(2596, 465, 231, 'Not Given'),
(2597, 465, 221, '30000.00'),
(2598, 465, 232, 'Not given'),
(2599, 465, 233, '2024-08-31'),
(2600, 465, 228, 'done'),
(2601, 465, 230, 'Not Given'),
(2602, 465, 235, 'Paid'),
(2603, 466, 221, '30000.00'),
(2604, 466, 231, 'Not Given'),
(2605, 466, 232, 'Not Given'),
(2606, 466, 233, '2024-09-30'),
(2607, 466, 228, 'done'),
(2608, 466, 230, 'Not Given'),
(2609, 466, 235, 'Paid'),
(2610, 467, 221, '2250.00'),
(2611, 467, 233, '2024-09-15'),
(2612, 467, 231, 'Not given'),
(2613, 467, 232, 'Not Given'),
(2614, 467, 228, 'done'),
(2615, 467, 230, 'Not Given'),
(2616, 467, 235, 'Paid'),
(2617, 468, 221, '4984.60'),
(2618, 468, 231, 'INV375701'),
(2619, 468, 233, '2025-10-20'),
(2620, 468, 228, 'done'),
(2621, 468, 235, 'Paid'),
(2622, 468, 230, 'Build It'),
(2623, 468, 232, 'GRAN0068'),
(2624, 469, 221, '13588.13'),
(2625, 469, 231, 'QTE291471'),
(2626, 469, 232, 'GRAN0065'),
(2627, 469, 233, '2025-10-15'),
(2628, 469, 228, 'done'),
(2629, 469, 230, 'Build It'),
(2630, 469, 235, 'Paid'),
(2631, 470, 239, 'Prepare east wall floor for repainting\r\n'),
(2632, 470, 251, '228'),
(2633, 470, 250, '85.00'),
(2634, 471, 239, 'Paint east wall floor 2 coats enamel\r\n'),
(2635, 471, 251, '228'),
(2636, 471, 250, '115.00'),
(2637, 472, 239, 'Remove & replace balcony ceiling boards, prep paint\r\n'),
(2638, 472, 251, '510'),
(2639, 472, 250, '210.00'),
(2640, 473, 239, 'Paint balcony ceiling 2 coats\r\n'),
(2641, 473, 251, '510'),
(2642, 473, 250, '95.00'),
(2643, 474, 239, 'Prepare balcony edges\r\n'),
(2644, 474, 251, '76'),
(2645, 474, 250, '65.00'),
(2646, 475, 239, 'Paint balcony edges 2 coats Dulux\r\n'),
(2647, 475, 251, '76'),
(2648, 475, 250, '95.00'),
(2649, 476, 239, 'Install 21 steel service doors, locks, keys, paint\r\n'),
(2650, 476, 251, '21'),
(2651, 476, 250, '6500.00'),
(2652, 477, 239, 'Install 1 ground floor burglar service door & paint\r\n'),
(2653, 477, 251, '1'),
(2654, 477, 250, '9500.00'),
(2655, 478, 239, 'Prepare 63 door frames & burglar bars repainting\r\n'),
(2656, 478, 251, '63'),
(2657, 478, 250, '950.00'),
(2658, 479, 239, 'Paint 63 door frames & burglar bars matching\r\n'),
(2659, 479, 251, '63'),
(2660, 479, 250, '950.00'),
(2661, 480, 239, 'Prepare balcony fence & burglar basement\r\n'),
(2662, 480, 251, '216'),
(2663, 480, 250, '95.00'),
(2664, 481, 239, 'Paint balcony fence & burglar basement 2 coats enamel\r\n'),
(2665, 481, 251, '216'),
(2666, 481, 250, '135.00'),
(2667, 482, 239, 'Remove damaged window putty, reinstall new putty\r\n'),
(2668, 482, 251, '220'),
(2669, 482, 250, '35.00'),
(2670, 483, 239, 'Paint windows 2 coats Dulux\r\n'),
(2671, 483, 251, '220'),
(2672, 483, 250, '135.00'),
(2673, 484, 239, 'Prepare steel staircase for repainting\r\n'),
(2674, 484, 251, '3'),
(2675, 484, 250, '5500.00'),
(2676, 485, 239, 'Paint steel staircase 2 coats enamel matching\r\n'),
(2677, 485, 251, '3'),
(2678, 485, 250, '19500.00'),
(2679, 486, 239, 'Scaffolding Hire (4-story, 6 weeks rental including setup and dismantle)\r\n'),
(2680, 486, 251, '6'),
(2681, 486, 250, '29500.00'),
(2682, 487, 239, 'Presure wash and prepare all facebricks \r\n'),
(2683, 487, 251, '2900'),
(2684, 487, 250, '35.00'),
(2685, 488, 239, 'Paint all facebricks with Armorseal 301\r\n'),
(2686, 488, 251, '2900'),
(2687, 488, 250, '95.00'),
(2688, 489, 239, 'Open and Waterproof Bottom wall outside steps\r\n'),
(2689, 489, 251, '1'),
(2690, 489, 250, '23500.00'),
(2691, 490, 239, 'Prepare Palasides all around Building\r\n'),
(2692, 490, 251, '165'),
(2693, 490, 250, '65.00'),
(2694, 491, 239, 'Paint Palasides all around building\r\n'),
(2695, 491, 251, '165'),
(2696, 491, 250, '190.00'),
(2697, 492, 239, 'Paint precast wall 2 coats Dulux\r\n'),
(2698, 492, 251, '152'),
(2699, 492, 250, '95.00'),
(2700, 493, 239, 'Repair precast slab wall behind the palisides (Parking Area)'),
(2701, 493, 251, '1'),
(2702, 493, 250, '26200.00'),
(2703, 494, 239, 'ducting replace botom area\r\n'),
(2704, 494, 251, '2.5'),
(2705, 494, 250, '4300.00'),
(2706, 495, 239, 'Carport new extention\r\n'),
(2707, 495, 251, '28'),
(2708, 495, 250, '650.00'),
(2709, 496, 83, '2025-10-06'),
(2710, 496, 211, 'QTE  DBNCQ0032466'),
(2711, 496, 85, 'Stewarts & Lloyds'),
(2712, 496, 43, '12329.99'),
(2713, 496, 214, 'done'),
(2714, 497, 221, '2070.00'),
(2715, 497, 231, 'INV1440'),
(2716, 497, 232, 'GRAN0066'),
(2717, 497, 233, '2025-10-20'),
(2718, 497, 228, 'done'),
(2719, 497, 235, 'Not Paid'),
(2720, 497, 230, 'Muller Feeds (PTY) (LTD) h/a'),
(2721, 498, 83, '2025-10-21'),
(2722, 498, 211, 'Not Given'),
(2723, 498, 85, 'South Gate wheels'),
(2724, 498, 43, '5000.00'),
(2725, 498, 214, 'done'),
(2726, 499, 83, '2025-10-21'),
(2727, 499, 211, 'INV936796'),
(2728, 499, 85, 'ABG Industrial supplies'),
(2729, 499, 43, '1725.00'),
(2730, 499, 214, 'done'),
(2732, 500, 266, '9286.05'),
(2733, 500, 267, 'INV364912'),
(2734, 500, 268, 'MIRA0001'),
(2735, 500, 271, '2025-08-18'),
(2736, 500, 265, 'Build It'),
(2737, 500, 270, 'Paid'),
(2738, 501, 266, '20430.59'),
(2739, 501, 267, 'INV365116'),
(2740, 501, 268, 'MIRA0002'),
(2741, 501, 271, '2025-08-19'),
(2742, 501, 265, 'Build It'),
(2743, 501, 270, 'Paid'),
(2744, 502, 266, '17656.00'),
(2745, 502, 267, 'INV  CRN19201'),
(2746, 502, 268, 'MIRA0002 CRN19201'),
(2747, 502, 271, '2025-08-20'),
(2748, 502, 265, 'Build It'),
(2749, 502, 270, 'Paid'),
(2750, 503, 268, 'MIRA0002 B'),
(2751, 503, 266, '21296.50'),
(2752, 503, 267, 'INV365338'),
(2753, 503, 271, '2025-08-20'),
(2754, 503, 270, 'Paid'),
(2755, 503, 265, 'Build It'),
(2756, 504, 266, '2359.87'),
(2757, 504, 267, 'INV365457'),
(2758, 504, 268, 'MIRA0003'),
(2759, 504, 271, '2025-08-20'),
(2760, 504, 265, 'Build It'),
(2761, 504, 270, 'Paid'),
(2762, 505, 266, '3539.70'),
(2763, 505, 267, 'INV365786'),
(2764, 505, 268, 'MIRA0004'),
(2765, 505, 271, '2025-08-22'),
(2766, 505, 265, 'Build It'),
(2767, 505, 270, 'Paid'),
(2768, 506, 266, '5350.73'),
(2769, 506, 267, 'INV366342'),
(2770, 506, 268, 'MIRA0005'),
(2771, 506, 271, '2025-08-26'),
(2772, 506, 265, 'Build It'),
(2773, 506, 270, 'Paid'),
(2774, 507, 266, '268.33'),
(2775, 507, 267, 'INV366462'),
(2776, 507, 268, 'MIRA0006'),
(2777, 507, 271, '2025-08-27'),
(2778, 507, 265, 'Build It'),
(2779, 507, 270, 'Paid'),
(2780, 508, 266, '341.80'),
(2781, 508, 267, 'INV366463'),
(2782, 508, 268, 'MIRA0007'),
(2783, 508, 271, '2025-08-27'),
(2784, 508, 265, 'Build It'),
(2785, 508, 270, 'Paid'),
(2786, 509, 266, '2514.31'),
(2787, 509, 267, 'INV366710'),
(2788, 509, 268, 'MIRA0008'),
(2789, 509, 271, '2025-08-28'),
(2790, 509, 270, 'Paid'),
(2791, 509, 265, 'Build It'),
(2792, 510, 266, '6966.30'),
(2793, 510, 267, 'INV366811'),
(2794, 510, 268, 'MIRA0009'),
(2795, 510, 271, '2025-08-28'),
(2796, 510, 265, 'Build It'),
(2797, 510, 270, 'Paid'),
(2798, 511, 266, '1104.00'),
(2799, 511, 267, 'INV1357'),
(2800, 511, 268, 'MIRA0010'),
(2801, 511, 271, '2025-09-05'),
(2802, 511, 265, 'Muller Feeds Sand'),
(2803, 511, 270, 'Paid'),
(2804, 512, 266, '440.27'),
(2805, 512, 267, 'INV367494'),
(2806, 512, 268, 'MIRA0011'),
(2807, 512, 271, '2025-09-02'),
(2808, 512, 265, 'Build It'),
(2809, 512, 270, 'Paid'),
(2810, 513, 266, '2129.65'),
(2811, 513, 267, 'INV367718'),
(2812, 513, 268, 'MIRA0012'),
(2813, 513, 271, '2025-09-03'),
(2814, 513, 265, 'Build It'),
(2815, 513, 270, 'Paid'),
(2816, 514, 266, '4259.30'),
(2817, 514, 267, 'INV367806'),
(2818, 514, 268, 'MIRA0012 B'),
(2819, 514, 271, '2025-09-03'),
(2820, 514, 270, 'Paid'),
(2821, 514, 265, 'Build It'),
(2822, 515, 266, '1924.90'),
(2823, 515, 267, 'INV368156'),
(2824, 515, 268, 'MIRA0013'),
(2825, 515, 271, '2025-09-05'),
(2826, 515, 265, 'Build It'),
(2827, 515, 270, 'Paid'),
(2828, 516, 266, '996.08'),
(2829, 516, 267, 'INV368570'),
(2830, 516, 268, 'MIRA0014'),
(2831, 516, 271, '2025-09-08'),
(2832, 516, 265, 'Build It'),
(2833, 516, 270, 'Paid'),
(2834, 517, 266, '5704.20'),
(2835, 517, 267, 'INV368724'),
(2836, 517, 268, 'MIRA0015'),
(2837, 517, 271, '2025-09-09'),
(2838, 517, 265, 'Build It'),
(2839, 517, 270, 'Paid'),
(2840, 518, 266, '1138.50'),
(2841, 518, 267, 'INV  DEL3936'),
(2842, 518, 268, 'MIRA0016'),
(2843, 518, 271, '2025-09-11'),
(2844, 518, 265, 'Muller Feeds Sand'),
(2845, 518, 270, 'Paid'),
(2846, 519, 266, '267.78'),
(2847, 519, 267, 'INV368819'),
(2848, 519, 268, 'MIRA0017'),
(2849, 519, 271, '2025-09-09'),
(2850, 519, 265, 'Build It\r\n'),
(2851, 519, 270, 'Paid'),
(2852, 520, 266, '258.55'),
(2853, 520, 267, 'INV369795'),
(2854, 520, 268, 'MIRA0018'),
(2855, 520, 271, '2025-09-15'),
(2856, 520, 265, 'Build It'),
(2857, 520, 270, 'Paid'),
(2858, 521, 266, '1244.00'),
(2859, 521, 267, 'INV85284'),
(2860, 521, 268, 'MIRA0018 B'),
(2861, 521, 271, '2025-09-19'),
(2862, 521, 270, 'Paid'),
(2863, 521, 265, 'Tool Quip'),
(2864, 522, 266, '2500.00'),
(2865, 522, 267, 'INV3093'),
(2866, 522, 268, 'MIRA0019'),
(2867, 522, 271, '2025-09-19'),
(2868, 522, 265, 'Nivanccas Sand'),
(2869, 522, 270, 'Paid'),
(2870, 523, 266, '672.48'),
(2871, 523, 267, 'INV370569'),
(2872, 523, 268, 'MIRA0019 B'),
(2873, 523, 271, '2025-09-19'),
(2874, 523, 265, 'Build It'),
(2875, 523, 270, 'Paid'),
(2876, 524, 266, '925.00'),
(2877, 524, 267, 'INV85372'),
(2878, 524, 268, 'MIRA0020'),
(2879, 524, 271, '2025-09-22'),
(2880, 524, 265, 'Tool Quip'),
(2881, 524, 270, 'Paid'),
(2882, 525, 266, '4007.04'),
(2883, 525, 267, 'INV371064'),
(2884, 525, 268, 'MIRA0020 B'),
(2885, 525, 271, '2025-09-23'),
(2886, 525, 265, 'Build It'),
(2887, 525, 270, 'Paid'),
(2888, 526, 221, '1500000'),
(2889, 527, 221, '150000'),
(2890, 528, 83, '2025-10-23'),
(2891, 528, 211, 'Not Given'),
(2892, 528, 85, 'Patrick alarms'),
(2893, 528, 43, '5000.00'),
(2894, 528, 214, 'done'),
(2895, 529, 83, '2025-10-23'),
(2896, 529, 211, 'Not Given'),
(2897, 529, 85, 'Patrick'),
(2898, 529, 43, '22000.00'),
(2899, 529, 214, 'done'),
(2900, 530, 83, '2025-10-04'),
(2901, 530, 211, 'QTE  1022/136556-01'),
(2902, 530, 85, 'Regal'),
(2903, 530, 43, '13982.14'),
(2904, 530, 214, 'todo'),
(2905, 531, 83, '2025-10-04'),
(2906, 531, 211, 'QTE  1022/136578-22'),
(2907, 531, 85, 'Regal'),
(2908, 531, 43, '6695.30'),
(2909, 531, 214, 'todo'),
(2910, 532, 83, '2025-10-04'),
(2911, 532, 211, '1022-136580-01'),
(2912, 532, 85, 'Regal'),
(2913, 532, 43, '10397.61'),
(2914, 532, 214, 'todo'),
(2915, 533, 83, '2025-10-23'),
(2916, 533, 211, 'Not Given\r\n'),
(2917, 533, 85, 'Evelyn'),
(2918, 533, 43, '10000.00'),
(2919, 533, 214, 'done'),
(2920, 534, 83, '2025-10-23'),
(2921, 534, 211, 'Not given'),
(2922, 534, 85, 'Clifton'),
(2923, 534, 43, '10000.00'),
(2924, 534, 214, 'done'),
(2925, 535, 83, '2025-10-22'),
(2926, 535, 211, 'Not Given'),
(2927, 535, 85, 'North Coast Board'),
(2928, 535, 43, '25000.00'),
(2929, 535, 214, 'done'),
(2930, 536, 83, '2025-10-22'),
(2931, 536, 211, 'QU597230'),
(2932, 536, 85, 'AGW'),
(2933, 536, 43, '18400.00'),
(2934, 536, 214, 'done'),
(2935, 537, 148, 'RIV003'),
(2936, 90, 281, 'Not Given'),
(2937, 537, 281, 'Not Given'),
(2938, 537, 284, '580.00'),
(2939, 537, 282, '2025-10-27'),
(2940, 537, 280, 'Trailerent PTY Builders'),
(2941, 537, 283, 'Paid'),
(2942, 537, 159, 'done'),
(2944, 538, 148, 'RIV004'),
(2945, 538, 284, '1176.82'),
(2946, 538, 282, '2025-10-27'),
(2947, 538, 283, 'Paid'),
(2948, 538, 159, 'done'),
(2949, 539, 281, 'INV3290817'),
(2950, 539, 148, 'RIV005'),
(2951, 539, 284, '5329.64'),
(2952, 539, 282, '2025-10-27'),
(2953, 539, 280, 'Riverside Mega Mica'),
(2954, 539, 283, 'Paid'),
(2955, 539, 159, 'done'),
(2956, 540, 281, 'Not Given'),
(2957, 540, 148, 'RIV006'),
(2958, 540, 284, '297.00'),
(2959, 540, 282, '2025-10-28'),
(2960, 540, 280, 'Builders Warehouse'),
(2961, 540, 283, 'Paid'),
(2962, 540, 159, 'done'),
(2963, 541, 251, '152'),
(2964, 541, 250, '95'),
(2965, 542, 251, '50'),
(2966, 542, 250, '210'),
(3009, 374, 240, '{\"start\":\"2025-11-04\",\"end\":\"2025-11-18\"}'),
(3010, 375, 240, '{\"start\":\"2025-11-04\",\"end\":\"2025-11-18\"}'),
(3011, 376, 240, '{\"start\":\"2025-11-18\",\"end\":\"2025-12-05\"}'),
(3012, 377, 240, '{\"start\":\"2025-11-04\",\"end\":\"2025-11-21\"}'),
(3013, 378, 240, '{\"start\":\"2025-11-18\",\"end\":\"2025-12-12\"}'),
(3014, 379, 240, '{\"start\":\"2025-11-18\",\"end\":\"2025-12-02\"}'),
(3015, 380, 240, '{\"start\":\"2025-12-02\",\"end\":\"2025-12-19\"}'),
(3016, 381, 240, '{\"start\":\"2025-11-10\",\"end\":\"2025-11-14\"}'),
(3017, 382, 240, '{\"start\":\"2025-12-03\",\"end\":\"2025-12-12\"}'),
(3018, 383, 240, '{\"start\":\"2025-11-25\",\"end\":\"2025-12-05\"}'),
(3019, 384, 240, '{\"start\":\"2025-12-29\",\"end\":\"2026-01-01\"}'),
(3020, 385, 240, '{\"start\":\"2025-11-25\",\"end\":\"2025-12-05\"}'),
(3021, 386, 240, '{\"start\":\"2025-12-05\",\"end\":\"2025-12-14\"}'),
(3022, 387, 240, '{\"start\":\"2025-11-10\",\"end\":\"2025-11-14\"}'),
(3023, 388, 240, '{\"start\":\"2025-12-15\",\"end\":\"2025-12-19\"}'),
(3024, 389, 240, '{\"start\":\"2025-11-24\",\"end\":\"2025-12-05\"}'),
(3025, 390, 240, '{\"start\":\"2025-12-05\",\"end\":\"2025-12-12\"}'),
(3026, 391, 240, '{\"start\":\"2025-12-01\",\"end\":\"2025-12-05\"}'),
(3027, 392, 240, '{\"start\":\"2025-11-25\",\"end\":\"2025-12-05\"}'),
(3028, 393, 240, '{\"start\":\"2025-12-05\",\"end\":\"2025-12-19\"}'),
(3029, 470, 240, '{\"start\":\"2025-12-03\",\"end\":\"2025-12-10\"}'),
(3030, 471, 240, '{\"start\":\"2025-11-24\",\"end\":\"2025-11-28\"}'),
(3031, 473, 240, '{\"start\":\"2025-12-08\",\"end\":\"2025-12-12\"}'),
(3032, 474, 240, '{\"start\":\"2025-11-10\",\"end\":\"2025-11-14\"}'),
(3033, 475, 240, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3034, 478, 240, '{\"start\":\"2025-12-08\",\"end\":\"2025-12-12\"}'),
(3035, 479, 240, '{\"start\":\"2025-12-15\",\"end\":\"2025-12-19\"}'),
(3036, 480, 240, '{\"start\":\"2025-12-08\",\"end\":\"2025-12-12\"}'),
(3037, 481, 240, '{\"start\":\"2025-12-15\",\"end\":\"2025-12-19\"}'),
(3038, 482, 240, '{\"start\":\"2025-11-10\",\"end\":\"2025-11-14\"}'),
(3039, 483, 240, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3040, 484, 240, '{\"start\":\"2025-12-08\",\"end\":\"2025-12-12\"}'),
(3041, 485, 240, '{\"start\":\"2025-12-15\",\"end\":\"2025-12-19\"}'),
(3042, 486, 240, '{\"start\":\"2025-11-04\",\"end\":\"2025-12-19\"}'),
(3043, 489, 240, '{\"start\":\"2025-11-10\",\"end\":\"2025-11-14\"}'),
(3044, 490, 240, '{\"start\":\"2026-01-05\",\"end\":\"2026-01-09\"}'),
(3045, 491, 240, '{\"start\":\"2026-01-12\",\"end\":\"2026-01-16\"}'),
(3046, 541, 240, '{\"start\":\"2026-01-19\",\"end\":\"2026-01-23\"}'),
(3047, 542, 240, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3048, 318, 278, '100'),
(3049, 319, 278, '100'),
(3050, 320, 278, '100'),
(3051, 330, 278, '100'),
(3052, 538, 281, 'Not given'),
(3053, 543, 281, 'INV 25104133'),
(3054, 543, 148, 'INV007'),
(3055, 543, 284, '254.00'),
(3056, 543, 282, '2025-10-28'),
(3057, 543, 280, 'Builders Warehouse'),
(3058, 543, 283, 'Paid'),
(3059, 543, 159, 'done'),
(3060, 544, 281, 'INV 263253'),
(3061, 544, 148, 'RIV008'),
(3062, 544, 284, '500.00'),
(3063, 544, 282, '2025-10-29'),
(3064, 544, 283, 'Paid'),
(3065, 544, 159, 'done'),
(3066, 544, 280, 'Garage'),
(3067, 538, 280, 'Garage'),
(3068, 545, 281, 'Not Given'),
(3069, 545, 148, 'RIV009'),
(3070, 545, 284, '580.00'),
(3071, 545, 282, '2025-10-29'),
(3072, 545, 280, 'Trailerent PTY Builders'),
(3073, 545, 283, 'Paid'),
(3074, 545, 159, 'done'),
(3075, 546, 281, 'Doc Nr B50271025142058'),
(3076, 546, 148, 'RIV002'),
(3077, 546, 284, '116.00'),
(3078, 546, 282, '2025-10-27'),
(3079, 546, 280, 'Builders Warehouse'),
(3080, 546, 283, 'Paid'),
(3081, 546, 159, 'done'),
(3082, 547, 281, 'Not Given'),
(3083, 547, 148, 'RIV004'),
(3084, 547, 284, '1176.82'),
(3085, 547, 282, '2025-10-27'),
(3086, 547, 280, 'Garage'),
(3087, 547, 283, 'Paid'),
(3088, 547, 159, 'done'),
(3089, 548, 281, 'Not Given'),
(3090, 548, 148, 'Not Given'),
(3091, 548, 284, '27.00'),
(3092, 548, 282, '2025-10-27'),
(3093, 548, 283, 'Paid'),
(3094, 548, 159, 'done'),
(3095, 549, 281, 'Not Given'),
(3096, 549, 148, 'Not Given'),
(3097, 549, 284, '27.00'),
(3098, 549, 282, '2025-10-28'),
(3099, 549, 283, 'Paid'),
(3100, 549, 159, 'done'),
(3101, 550, 281, 'Not Given'),
(3102, 550, 148, 'Not Given'),
(3103, 550, 284, '27.00'),
(3104, 550, 282, '2025-10-28'),
(3105, 550, 283, 'Paid'),
(3106, 550, 159, 'done'),
(3107, 551, 281, 'INV 26353'),
(3108, 551, 148, 'RIV008'),
(3109, 551, 284, '500.00'),
(3110, 551, 282, '2025-10-29'),
(3111, 551, 280, 'Garage'),
(3112, 551, 283, 'Paid'),
(3113, 551, 159, 'done'),
(3114, 552, 281, 'Doc Nr B50291025104166'),
(3115, 552, 148, 'RIV010'),
(3116, 552, 284, '145.00'),
(3117, 552, 282, '2025-10-29'),
(3118, 552, 280, 'Builders Warehouse\r\n'),
(3119, 552, 283, 'Paid'),
(3120, 552, 159, 'done'),
(3121, 553, 281, 'Not given'),
(3122, 553, 148, 'RIV011'),
(3123, 553, 284, '20.00'),
(3124, 553, 282, '2025-10-29'),
(3125, 553, 283, 'Paid'),
(3126, 553, 159, 'done'),
(3127, 554, 281, 'Not Given'),
(3128, 554, 148, 'RIV013'),
(3129, 554, 284, '1056.82'),
(3130, 554, 282, '2025-10-30'),
(3131, 554, 280, 'Garage'),
(3132, 554, 283, 'Paid'),
(3133, 554, 159, 'done'),
(3134, 548, 280, 'N1 South Grasmere'),
(3135, 549, 280, 'N1 South Grassmere'),
(3136, 550, 280, 'N1 South Grassmere'),
(3137, 555, 281, 'Order Nr 2711294'),
(3138, 555, 148, 'RIV012'),
(3139, 555, 284, '12265.70'),
(3140, 555, 282, '2025-10-30'),
(3141, 555, 280, 'Mannies Hardware supplies'),
(3142, 555, 283, 'Paid'),
(3143, 555, 159, 'done'),
(3144, 556, 281, 'Not Given'),
(3145, 556, 148, 'RIV014'),
(3146, 556, 284, '580.00'),
(3147, 556, 282, '2025-10-30'),
(3148, 556, 280, 'Trailerent PTY Builders'),
(3149, 556, 283, 'Paid'),
(3150, 556, 159, 'done'),
(3151, 557, 281, 'Order Nr 2713066'),
(3152, 557, 284, '17500.00'),
(3153, 557, 282, '2025-10-31'),
(3154, 557, 280, 'Mannies Hardware Supplies'),
(3155, 557, 283, 'Paid'),
(3156, 557, 159, 'done'),
(3157, 557, 148, 'RIV015'),
(3158, 558, 148, 'RIV016'),
(3159, 558, 282, '2025-10-30'),
(3160, 558, 280, 'Stewarts & Lloyeds'),
(3161, 558, 283, 'Paid'),
(3162, 558, 159, 'done'),
(3163, 559, 148, 'RIV017'),
(3164, 559, 282, '2025-10-30'),
(3165, 559, 283, 'Paid'),
(3166, 559, 159, 'done'),
(3167, 560, 281, 'Doc Nr B50271025144034'),
(3168, 560, 148, 'RIV018'),
(3169, 560, 284, '5632.98'),
(3170, 560, 282, '2025-10-27'),
(3171, 331, 278, '100'),
(3172, 560, 280, 'Builders warehouse'),
(3173, 560, 283, 'Paid'),
(3174, 560, 159, 'done'),
(3175, 559, 281, 'INV  S05IN43915'),
(3176, 559, 284, '20625.90'),
(3177, 559, 280, 'CDS ceiling & drywalling supplies'),
(3178, 561, 266, '1250.06'),
(3179, 561, 267, 'INV371348'),
(3180, 561, 271, '2025-09-25'),
(3181, 561, 265, 'Build It'),
(3182, 561, 270, 'Paid'),
(3183, 561, 268, 'MIRA0021/22'),
(3184, 562, 266, '507.77'),
(3185, 562, 268, 'MIRA0023'),
(3186, 562, 265, 'Build It'),
(3187, 562, 270, 'Paid'),
(3188, 562, 271, '2025-09-25'),
(3189, 562, 267, 'INV371416'),
(3190, 563, 266, '9274.87'),
(3191, 563, 268, 'MIRA0024'),
(3192, 563, 265, 'Build It'),
(3193, 563, 270, 'Paid'),
(3194, 563, 267, 'INV371437'),
(3195, 563, 271, '2025-09-25'),
(3196, 564, 266, '1501.34'),
(3197, 564, 268, 'MIRA0025'),
(3198, 564, 270, 'Paid'),
(3199, 564, 265, 'Build It'),
(3200, 564, 271, '2025-09-26'),
(3201, 564, 267, 'INV371550'),
(3202, 565, 265, 'Build It'),
(3203, 565, 266, '3241.85'),
(3204, 565, 267, 'INV369043'),
(3205, 565, 271, '2025-09-10'),
(3206, 565, 270, 'Paid'),
(3207, 565, 268, '1 JUAN'),
(3208, 566, 266, '884.21'),
(3209, 566, 267, 'INV370693'),
(3210, 566, 268, '2 JUAN'),
(3211, 566, 271, '2025-09-20'),
(3212, 566, 270, 'Paid'),
(3213, 566, 265, 'Build It'),
(3214, 567, 266, '313.10'),
(3215, 567, 267, 'INV370911'),
(3216, 567, 271, '2025-09-22'),
(3217, 567, 270, 'Paid'),
(3218, 567, 265, 'Build It'),
(3219, 567, 268, '3 JUAN'),
(3220, 568, 266, '1291.16'),
(3221, 568, 267, 'INV371145'),
(3222, 568, 271, '2025-09-23'),
(3223, 568, 270, 'Paid'),
(3224, 568, 265, 'Build It'),
(3225, 569, 266, '2468.97'),
(3226, 569, 267, 'INV371985'),
(3227, 569, 271, '2025-09-29'),
(3228, 569, 265, 'Build it'),
(3229, 569, 270, 'Paid'),
(3230, 568, 268, '4 JUAN'),
(3231, 569, 268, 'MIRA0026'),
(3232, 570, 266, '1423.48'),
(3233, 570, 268, 'MIRA0026 CRN'),
(3234, 570, 267, 'CRN100'),
(3235, 570, 271, '2025-09-29'),
(3236, 570, 265, 'Build It'),
(3237, 570, 270, 'Paid'),
(3238, 571, 266, '767.50'),
(3239, 571, 265, 'build It'),
(3240, 571, 268, 'MIRA0028'),
(3241, 571, 267, 'INV372062'),
(3242, 571, 271, '2025-09-29'),
(3243, 571, 270, 'Paid'),
(3244, 572, 266, '1430.52'),
(3245, 572, 268, 'MIRA0029'),
(3246, 572, 265, 'Build It'),
(3247, 572, 267, 'INV372409'),
(3248, 572, 271, '2025-10-01'),
(3249, 572, 270, 'Paid'),
(3250, 573, 266, '221.35'),
(3251, 573, 267, 'INV372499'),
(3252, 573, 268, 'MIRA0030'),
(3253, 573, 271, '2025-10-01'),
(3254, 573, 265, 'Build It'),
(3255, 573, 270, 'Paid'),
(3256, 574, 266, '720.00'),
(3257, 574, 267, 'INV85548'),
(3258, 574, 271, '2025-09-29'),
(3259, 574, 270, 'Paid'),
(3260, 574, 265, 'Tool Quip'),
(3261, 574, 268, 'MIRA0027'),
(3262, 575, 266, '35.00'),
(3263, 575, 268, 'MIRA0027 B'),
(3264, 575, 265, 'Tool Quip'),
(3265, 575, 270, 'Paid'),
(3266, 575, 271, '2025-10-01'),
(3267, 575, 267, 'INV85556'),
(3268, 331, 147, 'done'),
(3269, 330, 147, 'done'),
(3270, 332, 147, 'done'),
(3271, 332, 278, '100'),
(3272, 318, 147, 'done'),
(3273, 319, 147, 'done'),
(3274, 320, 147, 'done'),
(3275, 333, 278, '100'),
(3276, 333, 147, 'done'),
(3277, 334, 278, '100'),
(3278, 334, 147, 'done'),
(3279, 354, 278, '100'),
(3280, 354, 147, 'done'),
(3281, 355, 147, 'done'),
(3282, 355, 278, '100'),
(3283, 334, 146, '{\"start\":\"2025-11-04\",\"end\":\"2025-11-05\"}'),
(3284, 335, 146, '{\"start\":\"2025-11-06\",\"end\":\"2025-11-06\"}'),
(3285, 335, 147, 'done'),
(3286, 336, 146, '{\"start\":\"2025-11-06\",\"end\":\"2025-11-06\"}'),
(3287, 337, 146, '{\"start\":\"2025-11-07\",\"end\":\"2025-11-07\"}'),
(3288, 338, 146, '{\"start\":\"2001-10-29\",\"end\":\"2001-11-06\"}'),
(3289, 339, 146, '{\"start\":\"2025-11-10\",\"end\":\"2025-11-12\"}'),
(3290, 341, 146, '{\"start\":\"2025-11-12\",\"end\":\"2025-11-14\"}'),
(3291, 340, 146, '{\"start\":\"2025-11-12\",\"end\":\"2025-11-14\"}'),
(3292, 342, 146, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3293, 343, 146, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3294, 344, 146, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3295, 345, 146, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3296, 346, 146, '{\"start\":\"2025-11-24\",\"end\":\"2025-11-28\"}'),
(3297, 347, 146, '{\"start\":\"2025-11-10\",\"end\":\"2025-11-21\"}'),
(3298, 348, 146, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3299, 349, 146, '{\"start\":\"2025-11-17\",\"end\":\"2025-11-21\"}'),
(3300, 354, 146, '{\"start\":\"2025-11-03\",\"end\":\"2025-11-07\"}'),
(3301, 355, 146, '{\"start\":\"2025-11-03\",\"end\":\"2025-11-07\"}'),
(3302, 356, 146, '{\"start\":\"2025-11-10\",\"end\":\"2025-11-12\"}'),
(3303, 576, 281, 'Not Given'),
(3304, 576, 148, 'Not Given'),
(3305, 576, 284, '300.00'),
(3306, 576, 282, '2025-10-30'),
(3307, 576, 283, 'Paid'),
(3308, 576, 159, 'done'),
(3309, 576, 280, 'Concreto (Rivonia)'),
(3310, 577, 281, 'Order Nr 2713862'),
(3311, 577, 148, 'RIV019'),
(3312, 577, 284, '8255.74'),
(3313, 577, 282, '2025-10-31'),
(3314, 577, 280, 'Mannys Hardware Supplies'),
(3315, 577, 159, 'done'),
(3316, 577, 283, 'Paid'),
(3317, 578, 281, 'Doc nr B50311025142042'),
(3318, 578, 148, 'RIV020'),
(3319, 578, 284, '1383.00'),
(3320, 578, 282, '2025-10-31'),
(3321, 578, 280, 'Builders warehouse'),
(3322, 578, 283, 'Paid'),
(3323, 578, 159, 'done'),
(3324, 579, 148, 'RIV021'),
(3325, 580, 281, 'Doc Nr B50311025112097'),
(3326, 580, 148, 'RIV022'),
(3327, 580, 284, '463.00'),
(3328, 580, 282, '2025-10-31'),
(3329, 580, 280, 'Builders Warehouse'),
(3330, 580, 283, 'Paid'),
(3331, 580, 159, 'done'),
(3332, 581, 281, 'Not Given'),
(3333, 581, 148, 'RIV023'),
(3334, 581, 284, '355.70'),
(3335, 581, 282, '2025-10-31'),
(3336, 581, 280, 'KFC'),
(3337, 581, 283, 'Paid'),
(3338, 581, 159, 'done'),
(3339, 579, 281, 'Our Ref 00064266'),
(3340, 579, 284, '69.07'),
(3341, 579, 282, '2025-10-31'),
(3342, 579, 280, 'Plumblink'),
(3343, 579, 283, 'Paid'),
(3344, 579, 159, 'done'),
(3345, 582, 281, 'Order Nr 2718464'),
(3346, 582, 148, 'RIV015'),
(3347, 582, 284, '1320.00'),
(3348, 582, 282, '2025-11-03'),
(3349, 582, 280, 'Mannys Hardware Supplies'),
(3350, 582, 283, 'Paid'),
(3351, 582, 159, 'done'),
(3352, 584, 281, 'Not Given'),
(3353, 584, 148, 'RIV025'),
(3354, 584, 284, '479.97'),
(3355, 584, 282, '2025-11-03'),
(3356, 584, 280, 'DIY Depot Rivonia'),
(3357, 584, 283, 'Paid'),
(3358, 584, 159, 'done'),
(3359, 585, 266, '767.50'),
(3360, 585, 267, 'INV372062'),
(3361, 585, 268, 'MIRA0028'),
(3362, 585, 271, '2025-09-29'),
(3363, 585, 265, 'Build It'),
(3364, 585, 270, 'Paid'),
(3365, 586, 266, '1430.52'),
(3366, 586, 267, 'INV372409'),
(3367, 586, 268, 'MIRA0029'),
(3368, 586, 271, '2025-10-01'),
(3369, 586, 265, 'Build It'),
(3370, 586, 270, 'Paid'),
(3371, 587, 266, '221.35'),
(3372, 587, 267, 'INV372499'),
(3373, 587, 268, 'MIRA0030'),
(3374, 587, 271, '2025-10-01'),
(3375, 587, 270, 'Paid'),
(3376, 587, 265, 'Build It'),
(3377, 583, 281, 'Doc Nr B50031125102116'),
(3378, 583, 148, 'RIV024'),
(3379, 583, 284, '502.00'),
(3380, 583, 282, '2025-11-03'),
(3381, 583, 280, 'Builders Warehouse'),
(3382, 583, 283, 'Paid'),
(3383, 583, 159, 'done'),
(3384, 588, 281, 'Not Given'),
(3385, 588, 148, 'RIV026'),
(3386, 588, 284, '189.99'),
(3387, 588, 282, '2025-11-03'),
(3388, 588, 159, 'done'),
(3389, 588, 283, 'Paid'),
(3390, 588, 280, 'DIY Depot Rivonia'),
(3391, 589, 281, 'Not Given'),
(3392, 589, 148, 'RIV027'),
(3393, 589, 282, '2025-11-03'),
(3394, 589, 283, 'Not Paid'),
(3395, 589, 159, 'todo'),
(3396, 590, 281, 'Not Given'),
(3397, 590, 148, 'RIV028'),
(3398, 590, 284, '912.25'),
(3399, 590, 282, '2025-11-03'),
(3400, 590, 280, 'Garage'),
(3401, 590, 283, 'Paid'),
(3402, 590, 159, 'done'),
(3403, 558, 281, 'QTE  BOYLQ0027749'),
(3404, 558, 284, '19126.98'),
(3405, 591, 148, 'RIV029'),
(3406, 591, 281, 'Doc Nr B50041125143021'),
(3407, 591, 284, '664.91'),
(3408, 591, 282, '2025-11-04'),
(3409, 591, 280, 'Builders Warehouse'),
(3410, 591, 283, 'Paid'),
(3411, 594, 266, '1069.50'),
(3412, 594, 267, 'INV1431'),
(3413, 594, 268, 'MIRA0033'),
(3414, 594, 271, '2025-10-14'),
(3415, 594, 265, 'Mosselbaai Sand'),
(3416, 594, 270, 'Paid'),
(3417, 595, 266, '1151.99'),
(3418, 595, 267, 'INV375741'),
(3419, 595, 268, 'MIRA0034'),
(3420, 595, 271, '2025-10-20'),
(3421, 595, 265, 'Build It'),
(3422, 595, 270, 'Paid'),
(3423, 596, 266, '501.52'),
(3424, 596, 267, 'INV375816'),
(3425, 596, 268, 'MIRA0035'),
(3426, 596, 271, '2025-10-20'),
(3427, 596, 265, 'Build It'),
(3428, 596, 270, 'Paid'),
(3429, 597, 266, '132.05'),
(3430, 597, 267, 'INV375935'),
(3431, 597, 268, 'MIRA0036'),
(3432, 597, 271, '2025-10-21'),
(3433, 597, 265, 'Build It'),
(3434, 597, 270, 'Paid'),
(3435, 600, 266, '2500.00'),
(3436, 600, 267, 'INV0003160'),
(3437, 600, 268, 'MIRA0039'),
(3438, 600, 271, '2025-10-22'),
(3439, 600, 265, 'Nivancas Sand Klip'),
(3440, 600, 270, 'Paid'),
(3441, 601, 281, 'Doc Nr  B50041125143131'),
(3442, 601, 148, 'RIV030'),
(3443, 601, 284, '167.00'),
(3444, 601, 282, '2025-11-04'),
(3445, 601, 280, 'Builders Warehouse'),
(3446, 601, 283, 'Paid'),
(3447, 601, 159, 'done'),
(3448, 591, 159, 'done'),
(3449, 602, 281, 'Not Given'),
(3450, 602, 148, 'RIV031'),
(3451, 602, 284, '119.99'),
(3452, 602, 282, '2025-11-05'),
(3453, 602, 280, 'DIY Depot Rivonia'),
(3454, 602, 283, 'Paid'),
(3455, 602, 159, 'done'),
(3456, 603, 281, 'Not Given'),
(3457, 603, 148, 'RIV032'),
(3458, 603, 284, '219.98'),
(3459, 603, 282, '2025-11-05'),
(3460, 603, 280, 'DIY Depot Rivonia'),
(3461, 603, 283, 'Paid'),
(3462, 603, 159, 'done'),
(3463, 604, 272, '5029.00'),
(3464, 604, 273, 'Not Given'),
(3465, 604, 274, 'WAT001'),
(3466, 604, 275, '2025-11-04'),
(3467, 604, 276, 'PKGM Project Hardware'),
(3468, 604, 277, 'Paid'),
(3469, 605, 272, '781.94'),
(3470, 605, 273, 'Not Given'),
(3471, 605, 274, 'WAT002'),
(3472, 605, 275, '2025-11-05'),
(3473, 605, 276, 'DIY Depot Rivonia'),
(3474, 605, 277, 'Paid'),
(3475, 606, 272, '889.10'),
(3476, 606, 273, 'Not Given'),
(3477, 606, 274, 'WAT003'),
(3478, 606, 275, '2025-11-05'),
(3479, 606, 276, 'Garage'),
(3480, 606, 277, 'Paid'),
(3481, 607, 272, '7711.00'),
(3482, 607, 273, 'Doc Nr  B14051125140033'),
(3483, 607, 274, 'WAT004'),
(3484, 607, 275, '2025-11-05'),
(3485, 607, 276, 'Builders Warehouse'),
(3486, 607, 277, 'Paid'),
(3487, 608, 272, '350.00'),
(3488, 608, 273, 'Doc Nr B14051125140036'),
(3489, 608, 274, 'WAT005 A'),
(3490, 608, 275, '2025-11-05'),
(3491, 608, 276, 'Builders Warehouse'),
(3492, 608, 277, 'Paid'),
(3493, 336, 147, 'done'),
(3497, 321, 237, '2300.00'),
(3498, 322, 237, '6125.00'),
(3499, 323, 237, '9000.00'),
(3500, 324, 237, '34825.00'),
(3501, 325, 237, '11375.00'),
(3502, 326, 237, '16625.00'),
(3503, 328, 237, '184250.00'),
(3504, 329, 237, '5500.00'),
(3505, 327, 237, '3800.00'),
(3506, 351, 237, '9500.00'),
(3507, 352, 237, '4500.00'),
(3508, 353, 237, '8394.00'),
(3509, 330, 237, '4250.00'),
(3510, 331, 237, '5500.00'),
(3511, 332, 237, '11800.00'),
(3512, 333, 237, '9500.00'),
(3513, 334, 237, '9680.00'),
(3514, 335, 237, '6500.00'),
(3515, 336, 237, '5500.00'),
(3516, 337, 237, '8850.00'),
(3517, 338, 237, '14700.00'),
(3518, 339, 237, '12310.00'),
(3519, 341, 237, '14520.00'),
(3520, 340, 237, '13200.00'),
(3521, 342, 237, '6750.00'),
(3522, 343, 237, '2500.00'),
(3523, 344, 237, '4860.00'),
(3524, 345, 237, '2565.00'),
(3525, 346, 237, '7500.00'),
(3526, 347, 237, '6500.00'),
(3527, 348, 237, '184800.00'),
(3528, 349, 237, '8250.00'),
(3529, 350, 237, '9920.00'),
(3530, 373, 237, '7800.00'),
(3531, 354, 237, '99360.00'),
(3532, 355, 237, '93600.00'),
(3533, 356, 237, '64800.00'),
(3534, 357, 237, '177100.00'),
(3535, 358, 237, '126500.00'),
(3536, 359, 237, '4500.00'),
(3537, 360, 237, '11000.00'),
(3538, 361, 237, '7000.00'),
(3539, 362, 237, '7194.00'),
(3540, 363, 237, '27300.00'),
(3541, 364, 237, '6500.00'),
(3542, 365, 237, '13200.00'),
(3543, 366, 237, '31500.00'),
(3544, 367, 237, '10200.00'),
(3545, 368, 237, '3900.00'),
(3546, 369, 237, '9520.00'),
(3547, 370, 237, '24500.00'),
(3548, 371, 237, '45500.00'),
(3549, 372, 237, '17990.00'),
(3606, 339, 147, 'done'),
(3607, 339, 278, '100'),
(3608, 340, 147, 'done'),
(3609, 340, 278, '100'),
(3610, 341, 278, '10'),
(3611, 341, 147, 'done'),
(3612, 338, 147, 'done'),
(3613, 338, 278, '100'),
(3614, 337, 147, 'done'),
(3615, 609, 273, 'QTE 2339'),
(3616, 609, 274, 'Not Given'),
(3617, 609, 276, 'Firedart'),
(3618, 609, 277, 'Paid'),
(3619, 609, 275, '2025-11-05'),
(3620, 609, 272, '3056.25'),
(3621, 610, 281, 'QTE 2339'),
(3622, 610, 148, 'Not Given'),
(3623, 610, 284, '3056.25'),
(3624, 610, 282, '2025-11-05'),
(3625, 610, 280, 'Firedart '),
(3626, 610, 283, 'Paid'),
(3627, 610, 159, 'done'),
(3628, 612, 281, 'Not Given'),
(3629, 612, 148, 'RIV034'),
(3630, 612, 284, '959.90'),
(3631, 612, 282, '2025-11-06'),
(3632, 612, 280, 'DIY Depot Rivonia'),
(3633, 612, 283, 'Paid'),
(3634, 612, 159, 'done'),
(3635, 611, 281, 'Doc Nr  B50061125143021'),
(3636, 611, 148, 'RIV033'),
(3637, 611, 284, '2688.00'),
(3638, 611, 282, '2025-11-06'),
(3639, 611, 280, 'Builders Warehouse'),
(3640, 611, 283, 'Paid'),
(3641, 611, 159, 'done'),
(3642, 613, 281, 'Doc Nr  B50061125143057'),
(3643, 613, 148, 'RIV035'),
(3644, 613, 284, '1058.20'),
(3645, 613, 282, '2025-11-06'),
(3646, 613, 159, 'done'),
(3647, 613, 283, 'Paid'),
(3648, 613, 280, 'Builders Warehouse'),
(3649, 614, 281, 'Not Given'),
(3650, 614, 148, 'RIV036'),
(3651, 614, 284, '499.99'),
(3652, 614, 282, '2025-11-06'),
(3653, 614, 159, 'done'),
(3654, 614, 283, 'Paid'),
(3655, 614, 280, 'DIY Depot Rivonia'),
(3656, 615, 281, 'Not Given'),
(3657, 615, 148, 'Not Given'),
(3658, 615, 284, '2000.00'),
(3659, 616, 281, 'Not Given'),
(3660, 616, 148, 'Not Given'),
(3661, 616, 284, '2000.00'),
(3662, 616, 282, '2025-11-05'),
(3663, 616, 280, 'Concreto'),
(3664, 616, 283, 'Paid'),
(3665, 616, 159, 'done'),
(3666, 617, 281, 'Not Given'),
(3667, 617, 148, 'RIV037'),
(3668, 617, 284, '139.99'),
(3669, 617, 282, '2025-11-06'),
(3670, 617, 283, 'Paid'),
(3671, 617, 159, 'done'),
(3672, 617, 280, 'DIY Depot Rivonia'),
(3673, 618, 281, 'Doc Nr  B50061125107147'),
(3674, 618, 148, 'RIV038'),
(3675, 618, 284, '540.00'),
(3676, 618, 282, '2025-11-06'),
(3677, 618, 280, 'Builders Warehouse'),
(3678, 618, 283, 'Paid'),
(3679, 618, 159, 'done'),
(3680, 619, 281, 'INV  SOQ259718'),
(3681, 619, 148, 'Not Given'),
(3682, 619, 284, '936.00'),
(3683, 619, 282, '2025-11-06'),
(3684, 619, 280, 'Asap Steel'),
(3685, 619, 283, 'Paid'),
(3686, 619, 159, 'done'),
(3687, 620, 272, '936.00'),
(3688, 620, 273, 'INV  SOQ250718'),
(3689, 620, 274, 'Not Given'),
(3690, 620, 275, '2025-11-06'),
(3691, 620, 276, 'Asap Steel'),
(3692, 620, 277, 'Paid'),
(3693, 621, 281, 'Not Given'),
(3694, 621, 148, 'RIV039'),
(3695, 621, 284, '1040.10'),
(3696, 621, 282, '2025-11-07'),
(3697, 621, 280, 'Garage'),
(3698, 621, 283, 'Paid'),
(3699, 621, 159, 'done'),
(3700, 622, 281, 'Not Given'),
(3701, 622, 148, 'Not Given'),
(3702, 622, 284, '27.00'),
(3703, 622, 282, '2025-11-07'),
(3704, 622, 280, 'N1 South Grasmere'),
(3705, 622, 283, 'Paid'),
(3706, 622, 159, 'done'),
(3707, 623, 281, 'Doc Nr  B50071125142017'),
(3708, 623, 148, 'RIV040'),
(3709, 623, 284, '1168.00'),
(3710, 623, 282, '2025-11-07'),
(3711, 623, 280, 'Builders Warehouse'),
(3712, 623, 283, 'Paid'),
(3713, 623, 159, 'done'),
(3714, 374, 286, '100'),
(3715, 626, 281, 'Not Given'),
(3716, 626, 148, 'RIV043'),
(3717, 626, 284, '216.84'),
(3718, 626, 282, '2025-11-07'),
(3719, 626, 280, 'DIY Depot rivonia\r\n'),
(3720, 626, 283, 'Paid'),
(3721, 626, 159, 'done'),
(3722, 627, 281, 'Doc Nr 35G07630'),
(3723, 627, 148, 'Not Given'),
(3724, 627, 284, '1337.50'),
(3725, 627, 282, '2025-11-08'),
(3726, 627, 280, 'Midas'),
(3727, 627, 283, 'Paid'),
(3728, 627, 159, 'done'),
(3729, 628, 281, 'Not Given'),
(3730, 628, 148, 'RIV044'),
(3731, 628, 284, '100.00'),
(3732, 628, 282, '2025-11-09'),
(3733, 628, 280, 'Garage'),
(3734, 628, 283, 'Paid'),
(3735, 628, 159, 'done'),
(3736, 335, 278, '100'),
(3737, 336, 278, '100'),
(3738, 337, 278, '100'),
(3739, 629, 281, 'Not Given'),
(3740, 629, 148, 'Not given'),
(3741, 629, 284, '500.00'),
(3742, 629, 282, '2025-11-09'),
(3743, 629, 280, 'Garage'),
(3744, 629, 283, 'Paid'),
(3745, 629, 159, 'done'),
(3746, 630, 281, 'Not given'),
(3747, 630, 148, 'RIV045'),
(3748, 630, 284, '1398.50'),
(3749, 630, 282, '2025-11-10'),
(3750, 630, 159, 'done'),
(3751, 630, 283, 'Paid'),
(3752, 630, 280, 'Garage'),
(3753, 631, 281, 'Not Given'),
(3754, 631, 148, 'RW001'),
(3755, 631, 284, '762.50'),
(3756, 631, 282, '2025-11-10'),
(3757, 631, 159, 'done'),
(3758, 631, 283, 'Paid'),
(3759, 631, 280, 'Intro Weld'),
(3760, 624, 281, 'Doc Nr  B50071125102069'),
(3761, 624, 148, 'RIV041'),
(3762, 624, 284, '1052.00'),
(3763, 624, 282, '2025-11-07'),
(3764, 624, 280, 'Builders Warehouse'),
(3765, 624, 283, 'Paid'),
(3766, 624, 159, 'done'),
(3767, 632, 272, '1544.00'),
(3768, 632, 273, 'Not Given'),
(3769, 632, 274, 'RIV006'),
(3770, 632, 275, '2025-11-10'),
(3771, 632, 276, 'PKGM Projects Hardware'),
(3772, 632, 277, 'Paid'),
(3773, 633, 272, '878.00'),
(3774, 633, 273, 'Not Given '),
(3775, 633, 274, 'WAT005 B'),
(3776, 633, 275, '2025-11-10'),
(3777, 633, 276, 'Garage'),
(3778, 633, 277, 'Paid'),
(3779, 634, 272, '1337.50'),
(3780, 634, 273, 'Doc Nr  35G07630'),
(3781, 634, 274, 'Not Given'),
(3782, 634, 275, '2025-11-08'),
(3783, 634, 277, 'Paid'),
(3784, 634, 276, 'Midas'),
(3785, 635, 272, '762.50'),
(3786, 635, 273, 'Not Given'),
(3787, 635, 274, 'RW001'),
(3788, 635, 275, '2025-11-10'),
(3789, 635, 277, 'Paid'),
(3790, 635, 276, 'Intro Weld'),
(3791, 636, 273, 'Not Given'),
(3792, 636, 274, 'WAT006'),
(3793, 636, 275, '2025-11-10'),
(3794, 636, 272, '1544.00'),
(3795, 636, 276, 'PKGM Projects Hardware'),
(3796, 636, 277, 'Paid'),
(3797, 637, 273, '23386'),
(3798, 637, 274, 'WAT007'),
(3799, 637, 272, '849.00'),
(3800, 637, 275, '2025-11-10'),
(3801, 637, 277, 'Paid'),
(3802, 638, 273, 'INV M0081697'),
(3803, 638, 274, 'RW002'),
(3804, 638, 272, '2020.12'),
(3805, 638, 275, '2025-11-06'),
(3806, 638, 276, 'ASAP Steel'),
(3807, 638, 277, 'Paid'),
(3808, 639, 281, 'INV M00816097'),
(3809, 639, 148, 'RW002'),
(3810, 639, 284, '2020.12'),
(3811, 639, 282, '2025-11-06'),
(3812, 639, 280, 'ASAP Steel'),
(3813, 639, 283, 'Paid'),
(3814, 639, 159, 'done'),
(3815, 640, 281, 'Doc Nr B50071125102070'),
(3816, 640, 148, 'Not given'),
(3817, 640, 284, '38.00'),
(3818, 640, 282, '2025-11-07'),
(3819, 640, 280, 'Builders Warehouse'),
(3820, 640, 283, 'Paid'),
(3821, 640, 159, 'done'),
(3822, 641, 281, 'Not given\r\n'),
(3823, 641, 148, 'RW003'),
(3824, 641, 284, '34125.00'),
(3825, 641, 282, '2025-11-04'),
(3826, 641, 280, 'We Buy Cars'),
(3827, 641, 283, 'Paid'),
(3828, 641, 159, 'done'),
(3829, 642, 273, 'Not Given'),
(3830, 642, 274, 'RW003'),
(3831, 642, 272, '34125.00'),
(3832, 642, 275, '2025-11-04'),
(3833, 642, 276, 'We Buy Cars'),
(3834, 642, 277, 'Paid'),
(3835, 643, 83, '2025-10-23'),
(3836, 643, 211, 'Not Given'),
(3837, 643, 85, 'Divern Govender'),
(3838, 643, 43, '8749.00'),
(3839, 643, 214, 'done'),
(3840, 644, 83, '2025-10-23'),
(3841, 644, 211, 'Not Given'),
(3842, 644, 85, 'Thaslim Deen Mahomef'),
(3843, 644, 43, '140.00'),
(3844, 644, 214, 'done'),
(3845, 645, 83, '2025-10-26'),
(3846, 645, 211, 'Not Given'),
(3847, 645, 85, 'P Gengan'),
(3848, 645, 43, '4000.00'),
(3849, 645, 214, 'done'),
(3850, 646, 83, '2025-10-28'),
(3851, 646, 211, 'Not Given'),
(3852, 646, 85, 'Patrick alarms'),
(3853, 646, 214, 'done'),
(3854, 647, 83, '2025-10-28'),
(3855, 647, 211, 'Not given'),
(3856, 647, 85, 'Clifton'),
(3857, 647, 43, '15000.00'),
(3858, 647, 214, 'done'),
(3859, 648, 83, '2025-10-28'),
(3860, 648, 211, 'Pro Forma W 204492'),
(3861, 648, 85, 'Cretemix'),
(3862, 648, 43, '7580.85'),
(3863, 648, 214, 'done'),
(3864, 649, 83, '2025-10-28'),
(3865, 649, 211, 'QTE  DBNCQ0032743'),
(3866, 649, 85, 'Stewarts & Lloyds'),
(3867, 649, 43, '3954.00'),
(3868, 649, 214, 'done'),
(3869, 646, 43, '9000.00'),
(3870, 651, 83, '2025-10-28'),
(3871, 651, 211, 'Not Given'),
(3872, 651, 85, 'Patrick Alarms'),
(3873, 651, 43, '1000.00'),
(3874, 651, 214, 'done'),
(3875, 650, 83, '2025-10-28'),
(3876, 650, 211, 'Not given'),
(3877, 650, 85, 'Theunis'),
(3878, 650, 43, '7000.00'),
(3879, 650, 214, 'done'),
(3880, 652, 83, '2025-10-29'),
(3881, 652, 211, 'Not Given'),
(3882, 652, 85, 'Victor (Aluminium)'),
(3883, 652, 43, '3000.00'),
(3884, 652, 214, 'done'),
(3885, 653, 83, '2025-10-30'),
(3886, 653, 211, 'INV  QUA36313'),
(3887, 653, 85, 'PVC Ceilings'),
(3888, 653, 43, '2803.70'),
(3889, 653, 214, 'todo'),
(3890, 654, 83, '2025-10-31'),
(3891, 654, 211, 'Not Given'),
(3892, 654, 85, 'Theunis'),
(3893, 654, 43, '15000.00'),
(3894, 654, 214, 'done'),
(3895, 655, 83, '2025-10-31'),
(3896, 655, 211, 'Not given'),
(3897, 655, 85, 'Clifton'),
(3898, 655, 43, '10000.00'),
(3899, 655, 214, 'done'),
(3900, 656, 83, '2025-11-01'),
(3901, 656, 211, 'Not given'),
(3902, 656, 85, 'Theunis'),
(3903, 656, 43, '5000.00'),
(3904, 656, 214, 'done'),
(3905, 657, 211, 'Not given'),
(3906, 657, 85, 'Topdag Toolshop Fyrefly'),
(3907, 657, 43, '7790.00'),
(3908, 657, 214, 'done'),
(3909, 657, 83, '2025-11-04'),
(3910, 658, 211, 'Not Given'),
(3911, 658, 83, '2025-11-06'),
(3912, 658, 85, 'Not given'),
(3913, 658, 43, '6699.00'),
(3914, 658, 214, 'done'),
(3915, 659, 211, 'Not Given'),
(3916, 659, 85, 'HiFiCorp'),
(3917, 659, 43, '2399.00'),
(3918, 659, 214, 'done'),
(3919, 659, 83, '2025-11-06'),
(3920, 660, 211, 'Order Nr  OD435934902178659100'),
(3921, 660, 85, 'Makro'),
(3922, 660, 43, '5998.00'),
(3923, 660, 214, 'done'),
(3924, 660, 83, '2025-11-07'),
(3925, 661, 83, '2025-11-07'),
(3926, 661, 211, 'QU598377'),
(3927, 661, 85, 'AGW'),
(3928, 661, 43, '2055.36'),
(3929, 661, 214, 'todo'),
(3930, 662, 83, '2025-11-07'),
(3931, 662, 211, 'Not Given'),
(3932, 662, 85, 'AGW'),
(3933, 662, 43, '15758.00'),
(3934, 662, 214, 'done'),
(3935, 663, 83, '2025-11-07'),
(3936, 663, 211, 'INV 4E380U7B25'),
(3937, 663, 85, 'Makro'),
(3938, 663, 43, '2999.00'),
(3939, 663, 214, 'todo'),
(3940, 664, 83, '2025-11-07'),
(3941, 664, 211, 'Not Given'),
(3942, 664, 85, 'Divern Govender'),
(3943, 664, 43, '24758.00'),
(3944, 664, 214, 'done'),
(3945, 665, 83, '2025-11-07'),
(3946, 665, 211, 'Not Given'),
(3947, 665, 85, 'Garden Decor'),
(3948, 665, 43, '4060.00'),
(3949, 665, 214, 'done'),
(3950, 666, 211, 'Not Given'),
(3951, 666, 85, 'HiFiCorp'),
(3952, 666, 43, '19834.50'),
(3953, 666, 214, 'done'),
(3954, 666, 83, '2025-11-07'),
(3955, 667, 83, '2025-11-07'),
(3956, 667, 211, 'Not Given'),
(3957, 667, 85, 'Panda Store'),
(3958, 667, 43, '1263.00'),
(3959, 667, 214, 'done'),
(3960, 668, 83, '2025-11-10'),
(3961, 668, 211, 'Pro Forma PR 6762'),
(3962, 668, 85, 'Castor & Ladder'),
(3963, 668, 43, '6727.50'),
(3964, 668, 214, 'done'),
(3965, 669, 83, '2025-10-04'),
(3966, 669, 211, 'Not Given'),
(3967, 669, 85, 'Theunis\r\n'),
(3968, 669, 43, '13000.00'),
(3969, 669, 214, 'done'),
(3970, 670, 83, '2025-11-05'),
(3971, 670, 211, 'Not Given'),
(3972, 670, 85, 'Theunis'),
(3973, 670, 43, '10000.00'),
(3974, 670, 214, 'done'),
(3975, 671, 83, '2025-11-07'),
(3976, 671, 211, 'Not Given'),
(3977, 671, 85, 'Theunis'),
(3978, 671, 43, '9500.00'),
(3979, 671, 214, 'done'),
(3980, 637, 276, 'Multi Hardware'),
(3981, 672, 273, 'Not Given'),
(3982, 672, 274, 'Not Given'),
(3983, 672, 272, '27.00'),
(3984, 672, 275, '2025-11-11'),
(3985, 672, 276, 'N1 South Grasmere'),
(3986, 672, 277, 'Paid'),
(3987, 674, 273, 'Not Given'),
(3988, 674, 274, 'WAT008'),
(3989, 674, 272, '355.00'),
(3990, 674, 275, '2025-11-11'),
(3991, 674, 276, 'PKGM Projects Hardware'),
(3992, 674, 277, 'Paid'),
(3993, 675, 273, 'Not Given'),
(3994, 675, 274, 'WAT009'),
(3995, 675, 272, '100.00'),
(3996, 675, 275, '2025-11-11'),
(3997, 675, 276, 'Multi Hardware'),
(3998, 675, 277, 'Paid'),
(3999, 676, 273, 'Not Given'),
(4000, 676, 274, 'WAT010'),
(4001, 676, 272, '174.00'),
(4002, 676, 275, '2025-11-11'),
(4003, 676, 276, 'BP Floralake'),
(4004, 676, 277, 'Paid'),
(4005, 677, 273, 'INV  A 583511'),
(4006, 677, 274, 'WAT011'),
(4007, 677, 272, '350.00'),
(4008, 677, 275, '2025-11-12'),
(4009, 677, 276, 'Garage'),
(4010, 677, 277, 'Paid'),
(4011, 678, 273, 'Not Given'),
(4012, 678, 274, 'Not Given'),
(4013, 678, 272, '27.00'),
(4014, 678, 275, '2025-11-12'),
(4015, 678, 276, 'N1 South Grasmere'),
(4016, 678, 277, 'Paid'),
(4017, 679, 273, 'Not Given'),
(4018, 679, 274, 'WAT012'),
(4019, 679, 272, '268.00'),
(4020, 679, 275, '2025-11-12'),
(4021, 679, 276, 'PKGM Projects Hardware'),
(4022, 679, 277, 'Paid'),
(4023, 625, 281, 'Inv 204028'),
(4024, 625, 148, 'RIV042'),
(4025, 625, 284, '2589.00'),
(4026, 625, 282, '2025-11-07'),
(4027, 625, 280, 'Toolhire\r\n'),
(4028, 625, 283, 'Paid'),
(4029, 680, 281, 'INV 204028'),
(4030, 680, 148, 'RIV042'),
(4031, 680, 284, '-1470.00'),
(4032, 680, 282, '2025-11-07'),
(4033, 680, 280, 'Toolhire\r\n'),
(4034, 680, 283, 'Paid'),
(4035, 680, 159, 'done'),
(4036, 625, 159, 'done'),
(4037, 681, 281, 'Not given'),
(4038, 681, 148, 'Not Given'),
(4039, 681, 284, '27.00'),
(4040, 681, 282, '2025-11-08'),
(4041, 681, 280, 'N1 South Grasmere'),
(4042, 681, 283, 'Paid'),
(4043, 681, 159, 'done'),
(4044, 682, 281, 'Not Given'),
(4045, 682, 148, 'Not Given'),
(4046, 682, 284, '27.00'),
(4047, 682, 282, '2025-11-11'),
(4048, 682, 280, 'N1 South Grasmere'),
(4049, 682, 283, 'Paid'),
(4050, 682, 159, 'done'),
(4051, 684, 281, 'Not Given'),
(4052, 684, 284, '22.99'),
(4053, 684, 282, '2025-11-11'),
(4054, 684, 280, 'DIY Depot Rivonia'),
(4055, 684, 283, 'Paid'),
(4056, 684, 159, 'done'),
(4057, 684, 148, 'RIV046 B'),
(4058, 685, 281, 'Doc Nr B50111125143019'),
(4059, 685, 284, '159.00'),
(4060, 685, 282, '2025-11-11'),
(4061, 685, 280, 'Builders Warehouse'),
(4062, 685, 283, 'Paid'),
(4063, 685, 159, 'done'),
(4064, 683, 281, 'Order Nr 2732746'),
(4065, 683, 148, 'RIV046 A'),
(4066, 683, 284, '10089.02'),
(4067, 683, 282, '2025-11-11'),
(4068, 683, 280, 'Mannys Hardware Supplies'),
(4069, 683, 283, 'Paid'),
(4070, 683, 159, 'done'),
(4071, 686, 281, 'Not Given'),
(4072, 686, 148, 'RIV047'),
(4073, 686, 284, '1221.00'),
(4074, 686, 282, '2025-11-12'),
(4075, 686, 280, 'Garage'),
(4076, 686, 283, 'Paid'),
(4077, 686, 159, 'done'),
(4078, 687, 281, 'Not Given'),
(4079, 687, 148, 'Not Given'),
(4080, 687, 284, '27.00'),
(4081, 687, 282, '2025-11-12'),
(4082, 687, 280, 'N1 South Grassmere'),
(4083, 687, 283, 'Paid'),
(4084, 687, 159, 'done'),
(4085, 688, 281, 'Not given'),
(4086, 688, 148, 'Not given'),
(4087, 688, 284, '27.00'),
(4088, 688, 282, '2025-11-12'),
(4089, 688, 280, 'N1 South Grasmere'),
(4090, 688, 283, 'Paid'),
(4091, 688, 159, 'done'),
(4092, 689, 281, 'Not given'),
(4093, 685, 148, 'RIV048'),
(4094, 689, 148, 'RW004'),
(4095, 689, 284, '100.00'),
(4096, 689, 282, '2025-11-11'),
(4097, 689, 280, 'Vans Boumateriaal'),
(4098, 689, 283, 'Paid'),
(4099, 689, 159, 'done'),
(4100, 690, 281, 'Not Given'),
(4101, 690, 148, 'RW005'),
(4102, 690, 284, '57.00'),
(4103, 690, 282, '2025-11-11'),
(4104, 690, 280, 'Vans Boumateriaal'),
(4105, 690, 283, 'Paid'),
(4106, 690, 159, 'done'),
(4107, 691, 281, 'Not Given'),
(4108, 691, 148, 'RW006'),
(4109, 691, 284, '80.00'),
(4110, 691, 282, '2025-11-11'),
(4111, 691, 280, 'Vans Boumateriaal'),
(4112, 691, 283, 'Paid'),
(4113, 691, 159, 'done'),
(4114, 692, 273, 'Not Given'),
(4115, 692, 274, 'RW004'),
(4116, 692, 272, '100.00'),
(4117, 692, 275, '2025-11-11'),
(4118, 692, 276, 'Vans Boumateriaal'),
(4119, 692, 277, 'Paid'),
(4120, 693, 273, 'Not Given'),
(4121, 693, 274, 'RW005'),
(4122, 693, 272, '57.00');
INSERT INTO `board_item_values` (`id`, `item_id`, `column_id`, `value`) VALUES
(4123, 693, 275, '2025-11-11'),
(4124, 693, 276, 'Vans Boumateriaal'),
(4125, 693, 277, 'Paid'),
(4126, 694, 273, 'Not Given'),
(4127, 694, 274, 'RW006'),
(4128, 694, 272, '80.00'),
(4129, 694, 275, '2025-11-11'),
(4130, 694, 277, 'Paid'),
(4131, 694, 276, 'Vans Boumateriaal'),
(4132, 695, 273, 'Not Given'),
(4133, 695, 274, 'RW001'),
(4134, 695, 272, '762.50'),
(4135, 695, 275, '2025-11-10'),
(4136, 695, 277, 'Paid'),
(4137, 695, 276, 'Intro Weld'),
(4138, 696, 273, 'INV M0081697'),
(4139, 696, 274, 'RW002'),
(4140, 696, 272, '2020.12'),
(4141, 696, 275, '2025-11-06'),
(4142, 696, 276, 'Asap steel'),
(4143, 696, 277, 'Paid'),
(4144, 697, 273, 'Not given'),
(4145, 697, 274, 'RW003'),
(4146, 697, 272, '34125.00'),
(4147, 697, 275, '2025-11-04'),
(4148, 697, 276, 'We Buy Cars'),
(4149, 697, 277, 'Paid'),
(4150, 698, 273, 'Not Given'),
(4151, 698, 274, 'RW004'),
(4152, 698, 272, '100.00'),
(4153, 698, 275, '2025-11-11'),
(4154, 698, 276, 'Vans Bou Materiaal'),
(4155, 698, 277, 'Paid'),
(4156, 699, 273, 'Not Given'),
(4157, 699, 274, 'RW005'),
(4158, 699, 272, '57.00'),
(4159, 699, 275, '2025-11-11'),
(4160, 699, 276, 'Vans Boumateriaal'),
(4161, 699, 277, 'Paid'),
(4162, 700, 273, 'Not Given'),
(4163, 700, 274, 'RW006'),
(4164, 700, 272, '80.00'),
(4165, 700, 275, '2025-11-11'),
(4166, 700, 277, 'Paid'),
(4167, 700, 276, 'Vans Boumateriaal'),
(4168, 701, 281, 'Not Given'),
(4169, 701, 148, 'RW001'),
(4170, 701, 284, '762.50'),
(4171, 701, 282, '2025-11-10'),
(4172, 701, 280, 'Intro Weld'),
(4173, 701, 283, 'Paid'),
(4174, 701, 159, 'done'),
(4175, 702, 281, 'INV M0081697'),
(4176, 702, 148, 'RW002'),
(4177, 702, 284, '2020.12'),
(4178, 702, 282, '2025-11-06'),
(4179, 702, 280, 'Asap steel'),
(4180, 702, 283, 'Paid'),
(4181, 702, 159, 'done'),
(4182, 703, 281, 'Not Given'),
(4183, 703, 148, 'RW003'),
(4184, 703, 284, '34125.00'),
(4185, 703, 282, '2025-11-04'),
(4186, 703, 280, 'We Buy Cars'),
(4187, 703, 283, 'Paid'),
(4188, 703, 159, 'done'),
(4189, 704, 281, 'Not Given'),
(4190, 704, 148, 'RW004'),
(4191, 704, 284, '200.00'),
(4192, 704, 282, '2025-11-11'),
(4193, 704, 280, 'Vans Boumateriaal'),
(4194, 704, 283, 'Paid'),
(4195, 704, 159, 'done'),
(4196, 705, 281, 'Not Given'),
(4197, 705, 148, 'RW005'),
(4198, 705, 284, '57.00'),
(4199, 705, 282, '2025-11-11'),
(4200, 705, 280, 'Vans Boumateriaal'),
(4201, 705, 283, 'Paid'),
(4202, 705, 159, 'done'),
(4203, 706, 281, 'Not Given'),
(4204, 706, 148, 'RW006'),
(4205, 706, 284, '80.00'),
(4206, 706, 282, '2025-11-11'),
(4207, 706, 159, 'done'),
(4208, 706, 283, 'Paid'),
(4209, 706, 280, 'Vans Boumateriaal'),
(4210, 707, 281, 'Not Given'),
(4211, 707, 148, 'Not Given'),
(4212, 707, 284, '3030.00'),
(4213, 707, 282, '2025-11-12'),
(4214, 707, 283, 'Paid'),
(4215, 707, 159, 'done'),
(4216, 707, 280, 'Carpenters'),
(4217, 708, 83, '2025-11-11'),
(4218, 708, 211, 'Not Given'),
(4219, 708, 85, 'KRHN Agency'),
(4220, 708, 43, '5460.29'),
(4221, 708, 214, 'done'),
(4222, 711, 281, 'Doc Nr B50131125141031'),
(4223, 711, 148, 'RIV050'),
(4224, 711, 284, '2270.00'),
(4225, 711, 282, '2025-11-13'),
(4226, 711, 280, 'Builders Warehouse'),
(4227, 711, 283, 'Paid'),
(4228, 711, 159, 'done'),
(4229, 356, 278, '100'),
(4230, 356, 147, 'done'),
(4231, 360, 147, 'done'),
(4232, 360, 278, '100'),
(4233, 361, 147, 'done'),
(4234, 361, 278, '100'),
(4235, 712, 281, 'Not Given'),
(4236, 712, 148, 'Not Given '),
(4237, 712, 284, '27.00'),
(4238, 712, 282, '2025-11-13'),
(4239, 712, 280, 'N1 South Grasmere'),
(4240, 712, 283, 'Paid'),
(4241, 712, 159, 'done'),
(4242, 367, 147, 'done'),
(4243, 367, 278, '100'),
(4244, 348, 147, 'done'),
(4245, 349, 147, 'done'),
(4246, 714, 273, 'Not given'),
(4247, 714, 274, 'WAT013'),
(4248, 714, 272, '761.74'),
(4249, 362, 147, 'done'),
(4250, 363, 147, 'done'),
(4251, 714, 275, '2025-11-12'),
(4252, 714, 277, 'Paid'),
(4253, 714, 276, 'Garage'),
(4254, 715, 273, '29610'),
(4255, 715, 274, 'WAT014'),
(4256, 715, 272, '320.00'),
(4257, 715, 275, '2025-11-13'),
(4258, 715, 277, 'Paid'),
(4259, 715, 276, 'Multi Hardware'),
(4260, 375, 286, '100'),
(4261, 374, 241, 'done'),
(4262, 375, 241, 'done'),
(4263, 377, 241, 'done'),
(4264, 381, 241, 'done'),
(4265, 381, 286, '100'),
(4266, 383, 241, 'done'),
(4267, 383, 286, '100'),
(4268, 389, 241, 'done'),
(4269, 389, 286, '100'),
(4270, 392, 286, '100'),
(4271, 392, 241, 'done'),
(4272, 474, 286, '100'),
(4273, 474, 241, 'done'),
(4274, 486, 241, 'working'),
(4275, 486, 286, '80'),
(4276, 716, 281, 'not given'),
(4277, 716, 148, 'Not Given'),
(4278, 716, 284, '1137.50'),
(4279, 716, 282, '2025-10-31'),
(4280, 716, 280, 'Garage'),
(4281, 716, 283, 'Paid'),
(4282, 716, 159, 'done'),
(4283, 717, 281, 'Not Given'),
(4284, 717, 148, 'Not given'),
(4285, 717, 284, '58.80'),
(4286, 717, 282, '2025-10-30'),
(4287, 717, 280, 'Winston Kotse'),
(4288, 717, 283, 'Paid'),
(4289, 717, 159, 'done'),
(4290, 718, 281, 'Not Given'),
(4291, 718, 148, 'Not Given'),
(4292, 718, 284, '469.66'),
(4293, 718, 282, '2025-10-28'),
(4294, 718, 159, 'done'),
(4295, 718, 283, 'Paid'),
(4296, 718, 280, 'PicknPay'),
(4297, 721, 280, 'Chicken Licken'),
(4298, 722, 280, 'PicknPay'),
(4299, 722, 284, '59.74'),
(4300, 722, 282, '2025-10-27'),
(4301, 723, 281, 'Not given'),
(4302, 723, 148, 'Not Given'),
(4303, 723, 284, '27.00'),
(4304, 723, 282, '2025-11-06'),
(4305, 723, 280, 'N1 South Grasmere'),
(4306, 723, 283, 'Paid'),
(4307, 723, 159, 'done'),
(4308, 725, 273, 'Not Given'),
(4309, 725, 274, 'Not Given'),
(4310, 725, 272, '27.00'),
(4311, 725, 275, '2025-11-10'),
(4312, 725, 276, 'N1 South Grasmere'),
(4313, 725, 277, 'Paid'),
(4314, 726, 281, 'Not given'),
(4315, 726, 148, 'RIV052'),
(4316, 726, 282, '2025-11-14'),
(4317, 726, 280, 'Garage'),
(4318, 726, 284, '1297.70'),
(4319, 726, 283, 'Paid'),
(4320, 726, 159, 'done'),
(4321, 709, 148, 'RIV049'),
(4322, 709, 281, 'Order Nr 2738371'),
(4323, 709, 284, '16757.45'),
(4324, 709, 282, '2025-11-13'),
(4325, 709, 280, 'Mannys Hardware'),
(4326, 709, 283, 'Paid'),
(4327, 709, 159, 'done'),
(4328, 713, 148, 'RIV051'),
(4329, 727, 281, 'Not Given'),
(4330, 728, 281, 'Doc Nr B50141125143015'),
(4331, 728, 148, 'RIV053'),
(4332, 728, 284, '3386.09'),
(4333, 728, 282, '2025-11-14'),
(4334, 728, 280, 'Builders Warehouse'),
(4335, 728, 283, 'Paid'),
(4336, 728, 159, 'done'),
(4337, 729, 281, 'INV A 586399'),
(4338, 729, 148, 'RIV054'),
(4339, 729, 284, '1339.35'),
(4340, 729, 282, '2025-11-17'),
(4341, 729, 280, 'Garage'),
(4342, 729, 283, 'Paid'),
(4343, 729, 159, 'done'),
(4344, 731, 273, 'Not Given'),
(4345, 731, 274, 'WAT015'),
(4346, 731, 272, '874.00'),
(4347, 731, 275, '2025-11-14'),
(4348, 731, 277, 'Paid'),
(4349, 731, 276, 'Garage\r\n'),
(4350, 732, 281, 'Not given'),
(4351, 732, 148, 'RW007'),
(4352, 732, 284, '439.00'),
(4353, 732, 282, '2025-11-10'),
(4354, 732, 280, 'Garage'),
(4355, 732, 283, 'Paid'),
(4356, 732, 159, 'done'),
(4357, 733, 273, 'Not given'),
(4358, 733, 274, 'RW007'),
(4359, 733, 272, '439.00'),
(4360, 733, 275, '2025-11-10'),
(4361, 733, 276, 'Garage'),
(4362, 733, 277, 'Paid'),
(4363, 722, 148, 'Not Given'),
(4364, 722, 281, 'Not Given'),
(4365, 722, 283, 'Paid'),
(4366, 722, 159, 'done'),
(4367, 721, 159, 'done'),
(4368, 721, 283, 'Paid'),
(4369, 721, 282, '2025-10-27'),
(4370, 721, 284, '351.00'),
(4371, 721, 148, 'Not Given'),
(4372, 721, 281, 'Not Given'),
(4373, 734, 281, 'Not Given'),
(4374, 734, 148, 'Not Given'),
(4375, 734, 284, '58.80'),
(4376, 734, 282, '2025-10-30'),
(4377, 734, 280, 'PicknPay'),
(4378, 734, 283, 'Paid'),
(4379, 734, 159, 'done'),
(4380, 730, 281, 'Doc Nr B50171125147038'),
(4381, 730, 148, 'RIV055'),
(4382, 730, 284, '3222.00'),
(4383, 730, 282, '2025-11-17'),
(4384, 730, 280, 'Builders Warehouse'),
(4385, 730, 283, 'Paid'),
(4386, 730, 159, 'done'),
(4387, 735, 281, 'INV 1237'),
(4388, 735, 148, 'Not Given'),
(4389, 735, 284, '1950.00'),
(4390, 735, 283, 'Paid'),
(4391, 735, 159, 'done'),
(4392, 735, 280, 'OHS. Occasional Health Safety'),
(4393, 735, 282, '2025-11-16'),
(4394, 736, 273, 'INV A 586971'),
(4395, 736, 274, 'WAT016'),
(4396, 736, 272, '963.80'),
(4397, 736, 275, '2025-11-18'),
(4398, 736, 277, 'Paid'),
(4399, 736, 276, 'Garage'),
(4400, 737, 273, '29516'),
(4401, 737, 274, 'WAT017'),
(4402, 737, 272, '699.00'),
(4403, 737, 275, '2025-11-18'),
(4404, 737, 276, 'Multi Hardware'),
(4405, 737, 277, 'Paid'),
(4406, 738, 273, 'INV 1236'),
(4407, 738, 274, 'Not Given'),
(4408, 738, 272, '1950.00'),
(4409, 738, 275, '2025-11-16'),
(4410, 738, 276, 'OHS. Occasional Health Safety '),
(4411, 738, 277, 'Paid'),
(4412, 739, 273, '29516'),
(4413, 739, 274, 'WAT017'),
(4414, 739, 272, '699.00'),
(4415, 739, 275, '2025-11-18'),
(4416, 739, 276, 'Multi Hardware'),
(4417, 739, 277, 'Paid'),
(4418, 377, 286, '100'),
(4419, 379, 286, '100'),
(4420, 385, 286, '100'),
(4421, 478, 286, '100'),
(4422, 482, 286, '95'),
(4423, 484, 286, '100'),
(4424, 740, 273, 'Not Given'),
(4425, 740, 274, 'WAT018'),
(4426, 740, 272, '60.00'),
(4427, 740, 275, '2025-11-18'),
(4428, 740, 277, 'Paid'),
(4429, 740, 276, 'PKGM Projects Hardware '),
(4430, 741, 273, 'INV A 587743'),
(4431, 741, 274, 'WAT019'),
(4432, 741, 272, '900.25'),
(4433, 741, 275, '2025-11-19'),
(4434, 741, 276, 'Garage'),
(4435, 741, 277, 'Paid'),
(4436, 742, 281, 'Not Given'),
(4437, 742, 148, 'Not Given'),
(4438, 742, 284, '2000.00'),
(4439, 742, 282, '2025-11-18'),
(4440, 742, 280, 'Concreto'),
(4441, 742, 283, 'Paid'),
(4442, 742, 159, 'done'),
(4443, 743, 281, 'Not Given'),
(4444, 743, 148, 'Not Given'),
(4445, 743, 284, '1131.25'),
(4446, 743, 282, '2025-11-18'),
(4447, 743, 159, 'done'),
(4448, 743, 283, 'Paid'),
(4449, 743, 280, 'Garage'),
(4450, 745, 281, 'Not Given'),
(4451, 745, 148, 'RIV056'),
(4452, 745, 284, '716.97'),
(4453, 745, 282, '2025-11-19'),
(4454, 745, 159, 'done'),
(4455, 745, 283, 'Paid'),
(4456, 745, 280, 'DIY Depot Rivonia'),
(4457, 746, 281, 'INV A 588062'),
(4458, 746, 148, 'RIV057'),
(4459, 746, 284, '1094.65'),
(4460, 746, 282, '2025-11-19'),
(4461, 746, 280, 'Garage'),
(4462, 746, 283, 'Paid'),
(4463, 746, 159, 'done'),
(4464, 747, 281, 'Doc Nr B50201125105022'),
(4465, 747, 148, 'RIV058'),
(4466, 747, 284, '145.00'),
(4467, 747, 282, '2025-11-20'),
(4468, 747, 280, 'Builders Warehouse'),
(4469, 747, 283, 'Paid'),
(4470, 747, 159, 'done'),
(4471, 748, 281, 'Not Given'),
(4472, 748, 148, 'RIV059'),
(4473, 748, 284, '139.99'),
(4474, 748, 282, '2025-11-20'),
(4475, 748, 280, 'DIY Depot Rivonia'),
(4476, 748, 283, 'Paid'),
(4477, 748, 159, 'done'),
(4478, 749, 281, 'Not Given'),
(4479, 749, 148, 'RIV060'),
(4480, 749, 284, '746.90'),
(4481, 749, 282, '2025-11-20'),
(4482, 749, 280, 'DIY Depot Rivonia'),
(4483, 749, 283, 'Paid'),
(4484, 749, 159, 'done'),
(4485, 750, 281, 'QTE 00014202'),
(4486, 750, 148, 'RIV061'),
(4487, 750, 284, '66103.10'),
(4488, 750, 282, '2025-11-13'),
(4489, 750, 280, 'MixedTrrees Trading PTY LTD'),
(4490, 750, 283, 'Paid'),
(4491, 750, 159, 'done'),
(4492, 751, 281, 'Not Given'),
(4493, 751, 148, 'Not Given'),
(4494, 751, 284, '3000.00'),
(4495, 751, 282, '2025-11-20'),
(4496, 751, 280, 'Carpenters'),
(4497, 751, 283, 'Paid'),
(4498, 751, 159, 'done'),
(4499, 752, 281, 'Doc Nr  021169P'),
(4500, 752, 148, 'RW008'),
(4501, 752, 284, '9232.00'),
(4502, 752, 282, '2025-11-17'),
(4503, 752, 280, 'AT Paints'),
(4504, 752, 283, 'Paid'),
(4505, 752, 159, 'done'),
(4506, 753, 281, 'Doc Nr 10VEIADR0993'),
(4507, 753, 148, 'RW009'),
(4508, 753, 284, '26625.00'),
(4509, 753, 282, '2025-11-20'),
(4510, 753, 280, 'We Buy Cars'),
(4511, 753, 283, 'Paid'),
(4512, 753, 159, 'done'),
(4513, 754, 273, 'Doc Nr 021169P'),
(4514, 754, 274, 'RW008'),
(4515, 754, 272, '9232.00'),
(4516, 754, 275, '2025-11-17'),
(4517, 754, 276, 'AT Paints'),
(4518, 754, 277, 'Paid'),
(4519, 755, 273, 'Doc Nr 10VEIADR0993'),
(4520, 755, 274, 'RW009'),
(4521, 755, 272, '26625.00'),
(4522, 755, 275, '2025-11-20'),
(4523, 755, 276, 'We Buy Cars'),
(4524, 755, 277, 'Paid'),
(4525, 758, 281, 'Not Given'),
(4526, 758, 148, 'Not Given'),
(4527, 758, 284, '1500.00'),
(4528, 758, 282, '2025-11-21'),
(4529, 758, 280, 'Concreto'),
(4530, 758, 159, 'done'),
(4531, 758, 283, 'Paid'),
(4532, 759, 281, 'Not given'),
(4533, 759, 148, 'Not Given'),
(4534, 759, 284, '3000.00'),
(4535, 759, 282, '2025-11-21'),
(4536, 759, 280, 'Carpenters'),
(4537, 759, 283, 'Paid'),
(4538, 759, 159, 'done'),
(4539, 756, 281, 'Doc Nr B50211125144011'),
(4540, 756, 148, 'RIV062'),
(4541, 756, 284, '898.00'),
(4542, 756, 282, '2025-11-21'),
(4543, 756, 280, 'Builders Warehouse'),
(4544, 756, 283, 'Paid'),
(4545, 756, 159, 'done'),
(4546, 760, 281, 'Doc Nr B50221125142013'),
(4547, 760, 148, 'RIV064'),
(4548, 760, 284, '7854.00'),
(4549, 760, 282, '2025-11-22'),
(4550, 760, 280, 'Builders Warehouse'),
(4551, 760, 283, 'Paid'),
(4552, 760, 159, 'done'),
(4553, 761, 281, 'Not given'),
(4554, 761, 148, 'RIV065'),
(4555, 761, 284, '1000.00'),
(4556, 761, 282, '2025-11-22'),
(4557, 761, 280, 'garage'),
(4558, 761, 283, 'Paid'),
(4559, 761, 159, 'done'),
(4560, 762, 281, 'Not Given'),
(4561, 762, 148, 'Not Given'),
(4562, 762, 284, '27.00'),
(4563, 762, 282, '2025-11-22'),
(4564, 762, 280, 'N1 South Grasmere'),
(4565, 762, 283, 'Paid'),
(4566, 762, 159, 'done'),
(4567, 763, 281, 'Not Given'),
(4568, 763, 148, 'Not Given'),
(4569, 763, 284, '27.00'),
(4570, 763, 282, '2025-11-22'),
(4571, 763, 280, 'N1 South Grasmere'),
(4572, 763, 283, 'Paid'),
(4573, 763, 159, 'done'),
(4574, 359, 278, '100'),
(4575, 470, 286, '100'),
(4576, 480, 286, '100'),
(4577, 342, 278, '100'),
(4578, 348, 278, '100'),
(4579, 373, 278, '100'),
(4580, 373, 147, 'done'),
(4581, 359, 147, 'done'),
(4582, 719, 283, 'Paid'),
(4583, 720, 283, 'Paid'),
(4584, 719, 159, 'done'),
(4585, 720, 159, 'done'),
(4586, 719, 280, 'Concreto'),
(4587, 720, 280, 'Concreto'),
(4588, 719, 284, '10000.00'),
(4589, 720, 284, '3000.00'),
(4590, 719, 281, 'Not Given'),
(4591, 719, 148, 'Not Given'),
(4592, 720, 148, 'Not Given'),
(4593, 720, 281, 'Not Given'),
(4594, 764, 273, 'INV30-772'),
(4595, 764, 274, 'Not Given'),
(4596, 764, 272, '751.38'),
(4597, 764, 275, '2025-11-12'),
(4598, 764, 276, 'Diagnostic Radiological services Inc.'),
(4599, 764, 277, 'Paid'),
(4600, 765, 273, 'QTE 225643'),
(4601, 765, 274, 'Not Given'),
(4602, 765, 272, '730.00'),
(4603, 765, 275, '2025-11-12'),
(4604, 765, 276, 'Cormed Private Hospital '),
(4605, 765, 277, 'Paid'),
(4606, 766, 273, 'QTE  225430'),
(4607, 766, 274, 'Not Given'),
(4608, 766, 272, '170.00'),
(4609, 766, 275, '2025-11-12'),
(4610, 766, 276, 'Cormed Private Hospital'),
(4611, 766, 277, 'Paid'),
(4612, 767, 273, 'Not Given'),
(4613, 767, 274, 'Not Given'),
(4614, 767, 272, '196.24'),
(4615, 767, 275, '2025-11-12'),
(4616, 767, 276, 'Cormed Private Hospital '),
(4617, 767, 277, 'Paid'),
(4618, 769, 281, 'INV A 591318'),
(4619, 769, 148, 'RIV066'),
(4620, 769, 284, '1238.60'),
(4621, 769, 282, '2025-11-25'),
(4622, 769, 280, 'Garage'),
(4623, 769, 283, 'Paid'),
(4624, 769, 159, 'done'),
(4625, 770, 281, 'Not Given'),
(4626, 770, 148, 'RIV067'),
(4627, 770, 284, '595.90'),
(4628, 770, 282, '2025-11-25'),
(4629, 770, 159, 'done'),
(4630, 770, 283, 'Paid'),
(4631, 770, 280, 'Garage'),
(4632, 771, 281, 'Not Given'),
(4633, 771, 148, 'RIV067'),
(4634, 771, 284, '17.00'),
(4635, 771, 282, '2025-11-25'),
(4636, 771, 280, 'Garage'),
(4637, 771, 283, 'Paid'),
(4638, 771, 159, 'done'),
(4639, 772, 281, 'Not Given'),
(4640, 772, 148, 'Not Given'),
(4641, 772, 284, '27.00'),
(4642, 772, 282, '2025-11-25'),
(4643, 772, 280, 'N1 South Grasmere'),
(4644, 772, 283, 'Paid'),
(4645, 772, 159, 'done'),
(4646, 773, 281, 'Not Given'),
(4647, 773, 148, 'Not Given'),
(4648, 773, 284, '27.00'),
(4649, 773, 282, '2025-11-24'),
(4650, 773, 280, 'N1 South Grasmere'),
(4651, 773, 283, 'Paid'),
(4652, 773, 159, 'done'),
(4654, 774, 148, 'Not Given'),
(4655, 774, 284, '27.00'),
(4656, 774, 282, '2025-11-23'),
(4657, 774, 283, 'Paid'),
(4658, 774, 280, 'N1 South Grasmere'),
(4659, 774, 159, 'done'),
(4660, 775, 148, 'RIV068'),
(4661, 776, 148, 'RIV069'),
(4662, 777, 274, 'WAT020'),
(4663, 777, 273, 'Not Given'),
(4664, 777, 272, '333.50'),
(4665, 777, 275, '2025-11-24'),
(4666, 777, 276, 'PKGM Project Hardware'),
(4667, 777, 277, 'Paid'),
(4668, 778, 274, 'WAT021'),
(4669, 779, 273, 'Not Given'),
(4670, 779, 274, 'Not Given'),
(4671, 779, 272, '2000.00'),
(4672, 779, 275, '2025-11-22'),
(4673, 779, 276, 'Winston Kotze'),
(4674, 779, 277, 'Paid'),
(4675, 780, 273, 'Not Given'),
(4676, 780, 274, 'Not Given'),
(4677, 780, 272, '13.90'),
(4678, 780, 275, '2025-11-14'),
(4679, 780, 276, 'PicknPay'),
(4680, 780, 277, 'Paid'),
(4681, 781, 273, 'Not Given\r\n'),
(4682, 781, 274, 'Not Given'),
(4683, 781, 272, '624.63'),
(4684, 781, 275, '2025-11-03'),
(4685, 781, 277, 'Paid'),
(4686, 781, 276, 'Little egypt'),
(4687, 782, 273, 'Not given'),
(4688, 782, 274, 'WAT022'),
(4689, 782, 272, '55.00'),
(4690, 782, 275, '2025-11-18'),
(4691, 782, 276, 'PKGM Projects Hardware'),
(4692, 782, 277, 'Paid'),
(4693, 757, 281, 'Doc Nr B50221125142013'),
(4694, 757, 148, 'RIV063'),
(4695, 757, 284, '7854.00'),
(4696, 757, 282, '2025-11-22'),
(4697, 757, 280, 'Builders Warehouse'),
(4698, 757, 283, 'Paid'),
(4699, 387, 286, '100'),
(4700, 376, 286, '90'),
(4701, 473, 286, '100'),
(4702, 473, 241, 'done'),
(4703, 470, 241, 'done'),
(4704, 387, 241, 'done'),
(4705, 385, 241, 'done'),
(4706, 379, 241, 'done'),
(4707, 478, 241, 'done'),
(4708, 480, 241, 'done'),
(4709, 482, 241, 'working'),
(4710, 484, 241, 'done'),
(4711, 542, 241, 'done'),
(4712, 542, 286, '100'),
(4713, 378, 286, '100'),
(4714, 380, 286, '93'),
(4715, 342, 147, 'done'),
(4716, 343, 278, '100'),
(4717, 343, 147, 'done'),
(4718, 344, 278, '100'),
(4719, 344, 147, 'done'),
(4720, 345, 278, '100'),
(4721, 345, 147, 'done'),
(4722, 349, 278, '100'),
(4723, 362, 278, '100'),
(4724, 363, 278, '100'),
(4725, 321, 278, '100'),
(4726, 321, 147, 'done'),
(4727, 323, 278, '100'),
(4728, 323, 147, 'done'),
(4729, 328, 278, '100'),
(4730, 328, 147, 'done'),
(4731, 329, 278, '100'),
(4732, 329, 147, 'done'),
(4733, 327, 278, '100'),
(4734, 327, 147, 'done'),
(4735, 351, 278, '100'),
(4736, 351, 147, 'done'),
(4737, 352, 278, '100'),
(4738, 352, 147, 'done'),
(4739, 783, 273, 'Not Given'),
(4740, 783, 274, 'WAT023'),
(4741, 783, 272, '1525.00'),
(4742, 783, 275, '2025-11-26'),
(4743, 783, 276, 'Vans Boumateriaal'),
(4744, 783, 277, 'Paid'),
(4745, 784, 273, 'Not Given'),
(4746, 784, 274, 'WAT024'),
(4747, 784, 272, '5012.00'),
(4748, 784, 275, '2025-11-27'),
(4749, 784, 276, 'B And Paint Hardware'),
(4750, 784, 277, 'Paid'),
(4751, 785, 273, 'Not Given'),
(4752, 785, 274, 'Not Given'),
(4753, 785, 272, '45.00'),
(4754, 785, 275, '2025-11-27'),
(4755, 785, 276, 'Uber'),
(4756, 785, 277, 'Paid'),
(4757, 786, 273, 'Not Given'),
(4758, 786, 274, 'Not Given'),
(4759, 786, 272, '40.00'),
(4760, 786, 275, '2025-11-28'),
(4761, 786, 276, 'Uber'),
(4762, 786, 277, 'Paid'),
(4763, 768, 148, 'RIV065'),
(4764, 788, 281, 'Doc Nr B50261125142043'),
(4765, 788, 148, 'RIV070'),
(4766, 788, 284, '4016.00'),
(4767, 788, 282, '2025-11-26'),
(4768, 788, 159, 'done'),
(4769, 757, 159, 'done'),
(4770, 788, 283, 'Paid'),
(4771, 788, 280, 'Builders Warehouse'),
(4772, 789, 281, 'Not Given'),
(4773, 789, 148, 'RIV071'),
(4774, 789, 284, '1212.70'),
(4775, 789, 282, '2025-11-27'),
(4776, 789, 280, 'Garage'),
(4777, 789, 283, 'Paid'),
(4778, 789, 159, 'done'),
(4779, 790, 281, 'Not Given'),
(4780, 790, 148, 'Not Given'),
(4781, 790, 284, '27.00'),
(4782, 790, 282, '2025-11-27'),
(4783, 790, 280, 'N1 South Grasmere'),
(4784, 790, 283, 'Paid'),
(4785, 790, 159, 'done'),
(4786, 791, 148, 'RIV072'),
(4787, 791, 281, 'Doc Nr B50271125142016'),
(4788, 791, 284, '561.00'),
(4789, 791, 282, '2025-11-27'),
(4790, 791, 280, 'Builders Warehouse'),
(4791, 791, 159, 'done'),
(4792, 791, 283, 'Paid'),
(4793, 792, 281, 'Not Given'),
(4794, 792, 148, 'RIV073'),
(4795, 792, 284, '855.93'),
(4796, 792, 282, '2025-11-27'),
(4797, 792, 280, 'DIY Depot Rivonia'),
(4798, 792, 283, 'Paid'),
(4799, 792, 159, 'done'),
(4800, 793, 281, 'Given'),
(4802, 793, 284, '600.00'),
(4803, 793, 280, 'All Quip Hire'),
(4804, 793, 282, '2025-11-27'),
(4805, 793, 283, 'Deposite Paid'),
(4806, 793, 159, 'done'),
(4807, 794, 281, 'INV 14B143529'),
(4808, 794, 148, 'RIV074 B'),
(4809, 794, 284, '995.00'),
(4810, 793, 148, 'RIV074 A'),
(4811, 794, 282, '2025-11-27'),
(4812, 794, 280, 'Adendorff Machinery Mart'),
(4813, 794, 283, 'Paid'),
(4814, 794, 159, 'done'),
(4815, 795, 281, 'Doc Nr B50281125144008'),
(4816, 795, 148, 'RIV075'),
(4817, 795, 282, '2025-11-28'),
(4818, 795, 284, '620.20'),
(4819, 795, 280, 'Builders Warehouse'),
(4820, 795, 283, 'Paid'),
(4821, 795, 159, 'done'),
(4822, 787, 274, 'WAT025'),
(4823, 787, 272, '610.00'),
(4824, 787, 275, '2025-11-28'),
(4825, 787, 276, 'Builders Warehouse'),
(4826, 787, 277, 'Paid'),
(4827, 787, 273, 'Doc Nr S63281125104035'),
(4828, 796, 266, '9286.05'),
(4829, 796, 267, 'INV364912'),
(4830, 796, 268, 'MIRA0001'),
(4831, 796, 271, '2025-08-18'),
(4832, 796, 265, 'Build It'),
(4833, 796, 270, 'Paid'),
(4834, 346, 147, 'done'),
(4835, 346, 278, '100'),
(4836, 347, 278, '100'),
(4837, 347, 147, 'done'),
(4838, 382, 286, '100'),
(4839, 388, 286, '100'),
(4840, 388, 241, 'done'),
(4841, 797, 148, 'RIV076'),
(4843, 774, 281, 'Not Given'),
(4844, 798, 281, 'Doc Nr B50291125144006'),
(4845, 798, 148, 'RIV077'),
(4846, 798, 284, '569.00'),
(4847, 798, 282, '2025-11-29'),
(4848, 798, 280, 'Builders Warehouse'),
(4849, 798, 283, 'Paid'),
(4850, 798, 159, 'done'),
(4851, 797, 281, 'Not Given'),
(4852, 797, 284, '1000.00'),
(4853, 797, 282, '2025-11-29'),
(4854, 797, 280, 'Garage'),
(4855, 797, 283, 'Paid'),
(4856, 797, 159, 'done'),
(4857, 799, 281, 'Not Given'),
(4858, 799, 148, 'RIV078'),
(4859, 799, 284, '229.99'),
(4860, 799, 282, '2025-11-30'),
(4861, 799, 280, 'DIY Depot Rivonia'),
(4862, 799, 283, 'Paid'),
(4863, 799, 159, 'done'),
(4864, 800, 281, 'Doc Nr B50291125143016'),
(4865, 800, 148, 'RIV079'),
(4866, 800, 284, '1481.20'),
(4867, 800, 282, '2025-11-30'),
(4868, 800, 283, 'Paid'),
(4869, 800, 159, 'done'),
(4870, 800, 280, 'Builders Warehouse'),
(4871, 801, 148, 'RIV080'),
(4872, 802, 281, 'Not Given'),
(4873, 802, 148, 'RIV081'),
(4874, 802, 284, '2953.00'),
(4875, 802, 282, '2025-12-01'),
(4876, 802, 280, 'At Paint'),
(4877, 802, 283, 'Paid'),
(4878, 802, 159, 'done'),
(4879, 803, 281, 'Diesel'),
(4880, 803, 148, 'RIV082'),
(4881, 803, 284, '1000.15'),
(4882, 803, 282, '2025-12-02'),
(4883, 803, 280, 'Garage'),
(4884, 803, 283, 'Paid'),
(4885, 804, 281, 'Not Given'),
(4886, 804, 148, 'Not Given'),
(4887, 804, 284, '27.00'),
(4888, 804, 282, '2025-11-25'),
(4889, 804, 280, 'N1 South Grasmere'),
(4890, 804, 283, 'Paid'),
(4891, 805, 281, 'Not Given'),
(4892, 805, 148, 'Not Given'),
(4893, 805, 284, '27.00'),
(4894, 805, 282, '2025-11-26'),
(4895, 805, 280, 'N1 South Grasmere'),
(4896, 806, 281, 'Not Given'),
(4897, 806, 148, 'Not Given'),
(4898, 806, 284, '27.00'),
(4899, 806, 282, '2025-11-27'),
(4900, 806, 280, 'N1 South Grasmere'),
(4901, 807, 282, '2025-11-27'),
(4902, 808, 282, '2025-11-28'),
(4903, 809, 282, '2025-11-28'),
(4904, 810, 282, '2025-11-29'),
(4905, 811, 282, '2025-11-29'),
(4906, 812, 282, '2025-12-01'),
(4907, 813, 282, '2025-12-01'),
(4908, 814, 282, '2025-12-02'),
(4909, 807, 281, 'Not Given'),
(4910, 808, 281, 'Not Given'),
(4911, 809, 281, 'Not Given'),
(4912, 810, 281, 'Not Given'),
(4913, 811, 281, 'Not Given'),
(4914, 812, 281, 'Not Given'),
(4915, 813, 281, 'Not Given\r\n'),
(4916, 814, 281, 'Not Given'),
(4917, 807, 148, 'Not Given'),
(4918, 808, 148, 'Not Given'),
(4919, 809, 148, 'Not Given'),
(4920, 810, 148, 'Not Given'),
(4921, 811, 148, 'Not Given'),
(4922, 812, 148, 'Not Given'),
(4923, 813, 148, 'Not Given'),
(4924, 814, 148, 'Not Given'),
(4925, 807, 284, '27.00'),
(4926, 808, 284, '27.00'),
(4927, 809, 284, '27.00'),
(4928, 810, 284, '27.00'),
(4929, 811, 284, '27.00'),
(4930, 812, 284, '27.00'),
(4931, 813, 284, '27.00'),
(4932, 814, 284, '27.00'),
(4933, 803, 159, 'done'),
(4934, 804, 159, 'done'),
(4935, 805, 159, 'done'),
(4936, 806, 159, 'done'),
(4937, 807, 159, 'done'),
(4938, 808, 159, 'done'),
(4939, 809, 159, 'done'),
(4940, 810, 159, 'done'),
(4941, 811, 159, 'done'),
(4942, 812, 159, 'done'),
(4943, 813, 159, 'done'),
(4944, 805, 283, 'Paid'),
(4945, 806, 283, 'Paid'),
(4946, 807, 283, 'Paid'),
(4947, 808, 283, 'Paid'),
(4948, 809, 283, 'Paid'),
(4949, 810, 283, 'Paid'),
(4950, 811, 283, 'Paid'),
(4951, 812, 283, 'Paid'),
(4952, 813, 283, 'Paid'),
(4953, 807, 280, 'N1 South Grasmere'),
(4954, 808, 280, 'N1 South Grasmere'),
(4955, 809, 280, 'N1 South Garsmere'),
(4956, 810, 280, 'N1 South Grasmere'),
(4957, 811, 280, 'N1 South Grasmere'),
(4958, 812, 280, 'N1 South Grasmere'),
(4959, 814, 159, 'done'),
(4960, 814, 283, 'Paid'),
(4961, 814, 280, 'N1 South Grasmere'),
(4962, 813, 280, 'N1 South Grasmere'),
(4963, 815, 281, 'Not Given'),
(4964, 815, 148, 'RIV083'),
(4965, 815, 284, '600.00'),
(4966, 815, 282, '2025-12-02'),
(4967, 815, 280, 'Garage'),
(4968, 815, 283, 'Paid'),
(4969, 815, 159, 'done'),
(4970, 816, 273, 'Not Given'),
(4971, 816, 274, 'WAT026'),
(4972, 816, 272, '308.00'),
(4973, 816, 275, '2025-11-29'),
(4974, 816, 276, 'PKGM Projects Hardware '),
(4975, 816, 277, 'Paid'),
(4976, 817, 273, 'Not Given'),
(4977, 817, 274, 'Not given'),
(4978, 817, 272, '1609.24'),
(4979, 817, 275, '2025-11-08'),
(4980, 817, 276, 'Riverside Meat'),
(4981, 817, 277, 'Paid'),
(4982, 818, 291, '1'),
(4983, 818, 292, '-30000'),
(4984, 818, 293, '75000.00'),
(4985, 819, 291, '1'),
(4986, 819, 292, '0'),
(4987, 820, 291, '1'),
(4988, 820, 292, '40000'),
(4989, 821, 291, '1'),
(4990, 821, 292, '-5000'),
(4991, 822, 291, '1'),
(4992, 822, 292, '-40000'),
(4993, 823, 291, '1'),
(4994, 823, 292, '0'),
(4995, 824, 291, '1'),
(4997, 825, 291, '1'),
(4998, 826, 291, '1'),
(5000, 825, 292, '0'),
(5001, 827, 291, '1'),
(5002, 827, 292, '-10000'),
(5003, 828, 291, '1'),
(5004, 828, 292, '-10000'),
(5005, 829, 291, '1'),
(5007, 830, 291, '1'),
(5008, 830, 292, '0'),
(5009, 831, 291, '1'),
(5010, 831, 292, '-25000'),
(5011, 832, 291, '1'),
(5013, 833, 291, '1'),
(5014, 833, 292, '6000'),
(5015, 834, 291, '1'),
(5016, 834, 292, '-25000'),
(5017, 835, 291, '1'),
(5018, 835, 292, '119000'),
(5019, 836, 291, '1'),
(5020, 836, 292, '0'),
(5021, 837, 281, 'Not Given'),
(5022, 837, 148, 'Not Given'),
(5023, 837, 284, '27.00'),
(5024, 837, 282, '2025-12-02'),
(5025, 837, 280, 'N1 South Grasmere'),
(5026, 837, 283, 'Paid'),
(5027, 837, 159, 'done'),
(5028, 838, 281, 'Not Given'),
(5029, 838, 148, 'Not Given'),
(5030, 838, 284, '27.00'),
(5031, 838, 282, '2025-12-03'),
(5032, 838, 280, 'N1 South Grasmere'),
(5033, 838, 159, 'done'),
(5034, 838, 283, 'Paid'),
(5035, 840, 281, 'Doc Nr B50031225142019'),
(5036, 840, 148, 'RIV084'),
(5037, 840, 284, '1388.00'),
(5038, 840, 282, '2025-12-03'),
(5039, 840, 280, 'Builders Warehouse'),
(5040, 840, 283, 'Paid'),
(5041, 840, 159, 'done'),
(5042, 841, 281, 'INV A 597811'),
(5043, 841, 148, 'RIV085'),
(5044, 841, 284, '1500.00'),
(5045, 841, 282, '2025-12-04'),
(5046, 841, 280, 'Garage'),
(5047, 841, 283, 'Paid'),
(5048, 841, 159, 'done'),
(5049, 842, 281, 'Not Given'),
(5050, 842, 148, 'Not Given'),
(5051, 842, 284, '27.00'),
(5052, 842, 282, '2025-12-03'),
(5053, 842, 280, 'N1 South Grasmere'),
(5054, 842, 283, 'Paid'),
(5055, 842, 159, 'done'),
(5056, 843, 281, 'INV A 597811'),
(5057, 843, 148, 'RIV085'),
(5058, 843, 284, '1500.00'),
(5059, 843, 282, '2025-12-04'),
(5060, 843, 280, 'Garage'),
(5061, 843, 283, 'Paid'),
(5062, 843, 159, 'done'),
(5063, 844, 291, '1'),
(5064, 844, 292, '0'),
(5065, 845, 273, 'Not Given'),
(5066, 845, 274, 'Not Given'),
(5067, 845, 272, '900.00'),
(5068, 845, 275, '2025-12-03'),
(5069, 846, 273, 'Not Given'),
(5070, 846, 272, '900.00'),
(5071, 846, 275, '2025-12-03'),
(5072, 846, 276, 'PKGM Projects Hardware'),
(5073, 846, 277, 'Paid'),
(5074, 846, 274, 'WAT036'),
(5075, 847, 273, 'Not Given'),
(5076, 847, 274, 'WAT037'),
(5077, 847, 272, '5029.00'),
(5078, 847, 275, '2025-11-04'),
(5079, 847, 276, 'PKGM Projects Hardware'),
(5080, 847, 277, 'Paid'),
(5081, 848, 273, 'Not Given'),
(5082, 848, 274, 'Not Given'),
(5083, 848, 272, '27.00'),
(5084, 848, 275, '2025-12-04'),
(5085, 848, 276, 'N1 South Grasmere'),
(5086, 848, 277, 'Paid'),
(5087, 849, 273, 'Not Given'),
(5088, 849, 274, 'WAT038'),
(5089, 849, 272, '747.00'),
(5090, 849, 275, '2025-12-04'),
(5091, 849, 276, 'PKGM Projects Hardware'),
(5092, 849, 277, 'Paid'),
(5093, 850, 273, 'Not Given'),
(5094, 850, 274, 'Not Given'),
(5095, 850, 272, '27.00'),
(5096, 850, 275, '2025-12-04'),
(5097, 850, 277, 'Paid'),
(5098, 850, 276, 'N1 South Grasmere'),
(5099, 851, 273, 'Not Given'),
(5100, 851, 274, 'Not Given'),
(5101, 851, 272, '27.00'),
(5102, 851, 275, '2025-12-05'),
(5103, 851, 276, 'N1 South Grasmere'),
(5104, 851, 277, 'Paid'),
(5105, 855, 273, 'INV 3339388'),
(5106, 855, 274, 'WAT030'),
(5107, 855, 272, '4689.00'),
(5108, 855, 275, '2025-12-06'),
(5109, 855, 276, 'Riverside Mega Mica'),
(5110, 855, 277, 'Paid'),
(5111, 856, 273, 'Not Given'),
(5112, 856, 274, 'Not Given'),
(5113, 856, 272, '27.00'),
(5114, 856, 275, '2025-12-06'),
(5115, 856, 276, 'N1 South Grasmere'),
(5116, 856, 277, 'Paid'),
(5117, 857, 273, 'Not Given'),
(5118, 857, 274, 'WAT031'),
(5119, 857, 272, '1000.00'),
(5120, 857, 275, '2025-12-06'),
(5121, 857, 276, 'Garage'),
(5122, 857, 277, 'Paid'),
(5123, 858, 273, 'Not Given'),
(5124, 858, 274, 'Not Given'),
(5125, 858, 272, '27.00'),
(5126, 858, 275, '2025-12-06'),
(5127, 858, 277, 'Paid'),
(5128, 858, 276, 'N1 South Grasmere'),
(5129, 859, 273, 'Not Given'),
(5130, 859, 274, 'WAT032'),
(5131, 859, 272, '679.00'),
(5132, 859, 275, '2025-12-08'),
(5133, 859, 277, 'Paid'),
(5134, 859, 276, 'PKGM Projects Hardware'),
(5135, 861, 274, 'WAT034'),
(5136, 860, 274, 'WAT033'),
(5137, 861, 272, '1900.00'),
(5138, 861, 273, '30501'),
(5139, 861, 275, '2025-12-09'),
(5140, 861, 276, 'Multi Hardware'),
(5141, 861, 277, 'Paid'),
(5142, 801, 281, 'INV 2768150'),
(5143, 801, 282, '2025-12-01'),
(5144, 801, 280, 'Mannys Hardware Supplies'),
(5145, 801, 283, 'Paid'),
(5146, 801, 159, 'done'),
(5147, 862, 148, 'RIV086 A'),
(5148, 863, 148, 'RIV086 B'),
(5149, 863, 282, '2025-12-05'),
(5150, 864, 148, 'RIV087'),
(5151, 864, 282, '2025-12-05'),
(5152, 862, 281, 'INV POS127476'),
(5153, 862, 284, '110.98'),
(5154, 862, 282, '2025-12-05'),
(5155, 862, 280, 'MoTo & More'),
(5156, 862, 159, 'done'),
(5157, 862, 283, 'Paid'),
(5158, 865, 282, '2025-12-05'),
(5159, 865, 148, 'RIV088'),
(5160, 863, 281, 'Doc Nr B50061225183009'),
(5161, 863, 284, '1260.00'),
(5162, 863, 280, 'Builders Warehouse'),
(5163, 863, 159, 'done'),
(5164, 863, 283, 'Paid'),
(5165, 867, 281, 'Not Given'),
(5166, 867, 148, 'Not Given'),
(5167, 867, 284, '27.00'),
(5168, 867, 282, '2025-12-06'),
(5169, 867, 280, 'N1 South Grasmere'),
(5170, 867, 283, 'Paid'),
(5171, 867, 159, 'done'),
(5172, 868, 273, 'Not Given'),
(5173, 868, 274, 'WAT035'),
(5174, 868, 272, '1028.00'),
(5175, 868, 275, '2025-12-09'),
(5176, 868, 276, 'Garage'),
(5177, 868, 277, 'Paid'),
(5178, 869, 281, 'Not Given'),
(5179, 869, 148, 'RIV090'),
(5180, 869, 284, '1500.00'),
(5181, 869, 282, '2025-12-07'),
(5182, 869, 280, 'Garage'),
(5183, 869, 283, 'Paid'),
(5184, 869, 159, 'done'),
(5185, 866, 281, 'Doc Nr B50071225144021'),
(5186, 866, 148, 'RIV089'),
(5187, 866, 284, '569.00'),
(5188, 866, 282, '2025-12-07'),
(5189, 866, 280, 'Builders Warehouse'),
(5190, 866, 283, 'Paid'),
(5191, 866, 159, 'done'),
(5192, 870, 281, 'Doc Nr B50081225141061'),
(5193, 870, 148, 'RIV091'),
(5194, 870, 284, '828.00'),
(5195, 870, 282, '2025-12-08'),
(5196, 870, 280, 'Builders Warehouse'),
(5197, 870, 283, 'Paid'),
(5198, 870, 159, 'done'),
(5199, 871, 281, 'Doc Nr B50081225133099'),
(5200, 871, 148, 'RIV092'),
(5201, 871, 284, '568.00'),
(5202, 871, 280, 'Builders Warehouse'),
(5203, 871, 283, 'Paid'),
(5204, 871, 159, 'done'),
(5205, 871, 282, '2025-12-08'),
(5206, 872, 273, 'Cash Send'),
(5207, 872, 274, 'Not Given'),
(5208, 872, 272, '3000.00'),
(5209, 872, 275, '2025-12-08'),
(5210, 872, 276, 'Simon Painter'),
(5211, 872, 277, 'Paid'),
(5212, 873, 273, '30501'),
(5213, 873, 274, 'WAT034'),
(5214, 873, 272, '1900.00'),
(5215, 873, 275, '2025-12-09'),
(5216, 873, 276, 'Multi Hardware'),
(5217, 873, 277, 'Paid'),
(5218, 874, 273, 'Cash Send'),
(5219, 874, 274, 'Not Given'),
(5220, 874, 272, '3000.00'),
(5221, 874, 275, '2025-12-09'),
(5222, 874, 276, 'Simon Painter'),
(5223, 874, 277, 'Paid'),
(5224, 875, 273, 'Not Given'),
(5225, 875, 274, 'Not Given'),
(5226, 877, 273, 'Not Given'),
(5227, 877, 274, 'Not Given'),
(5228, 877, 272, '2000.00'),
(5229, 877, 275, '2025-12-08'),
(5230, 877, 276, 'Winston Kotze'),
(5231, 877, 277, 'Paid'),
(5232, 860, 273, 'Not Given'),
(5233, 860, 272, '680.00'),
(5234, 860, 275, '2025-12-08'),
(5235, 860, 276, 'Builders Express'),
(5236, 860, 277, 'Paid'),
(5237, 878, 273, 'INV 656048'),
(5238, 878, 274, 'Not Given'),
(5239, 878, 272, '1500.00'),
(5240, 878, 275, '2025-12-09'),
(5241, 878, 276, 'Kotwals Motor Spares'),
(5242, 878, 277, 'Paid'),
(5243, 879, 281, 'INV 656049'),
(5244, 879, 148, 'RIV094'),
(5245, 879, 284, '5404.99'),
(5246, 879, 282, '2025-12-09'),
(5247, 879, 280, 'Kotwals Motor Spares'),
(5248, 879, 283, 'Paid'),
(5249, 879, 159, 'done'),
(5250, 880, 281, 'Doc Nr B50081225142037'),
(5251, 880, 148, 'RIV093'),
(5252, 880, 284, '1027.00'),
(5253, 880, 282, '2025-12-09'),
(5254, 880, 280, 'Builders Warehouse'),
(5255, 880, 283, 'Paid'),
(5256, 880, 159, 'done'),
(5257, 881, 281, 'Not Given'),
(5258, 881, 148, 'RIV095'),
(5259, 881, 284, '105.27'),
(5260, 881, 282, '2025-12-09'),
(5261, 881, 280, 'Garage'),
(5262, 881, 159, 'done'),
(5263, 881, 283, 'Paid'),
(5264, 882, 281, 'Doc Nr B50101225143102'),
(5265, 882, 148, 'RIV096'),
(5266, 882, 284, '569.00'),
(5267, 882, 282, '2025-12-10'),
(5268, 882, 280, 'Builders Warehouse'),
(5269, 882, 283, 'Paid'),
(5270, 882, 159, 'done'),
(5271, 883, 281, 'Doc Nr B50111225142019'),
(5272, 883, 148, 'RIV097'),
(5273, 883, 284, '860.00'),
(5274, 883, 282, '2025-12-11'),
(5275, 883, 280, 'Builders Warehouse'),
(5276, 883, 283, 'Paid'),
(5277, 883, 159, 'done'),
(5278, 885, 273, 'Original INV  20CEPAAA9610'),
(5279, 885, 274, 'RW011'),
(5280, 885, 272, '280.72'),
(5281, 885, 275, '2025-12-11'),
(5282, 885, 276, 'NGM Rivonia'),
(5283, 885, 277, 'Paid'),
(5284, 887, 281, 'Original INV  20CEPAAA9610'),
(5285, 887, 148, 'RW011'),
(5286, 887, 284, '280.72'),
(5287, 887, 282, '2025-12-11'),
(5288, 887, 280, 'NMG Rivonia'),
(5289, 887, 283, 'Paid'),
(5290, 887, 159, 'done'),
(5291, 886, 281, 'Not Given'),
(5292, 886, 148, 'RW010'),
(5293, 886, 284, '340.00'),
(5294, 886, 282, '2025-12-10'),
(5295, 886, 280, 'Electrician '),
(5296, 886, 283, 'Paid'),
(5297, 886, 159, 'done'),
(5298, 884, 273, 'Not Given'),
(5299, 884, 274, 'RW010'),
(5300, 884, 272, '340.00'),
(5301, 884, 275, '2025-12-10'),
(5302, 884, 277, 'Paid'),
(5303, 884, 276, 'Electrician '),
(5304, 376, 241, 'working'),
(5305, 378, 241, 'done'),
(5306, 380, 241, 'working'),
(5307, 382, 241, 'done'),
(5308, 386, 241, 'working'),
(5309, 386, 286, '60'),
(5310, 475, 286, '20'),
(5311, 475, 241, 'working'),
(5312, 322, 278, '100'),
(5313, 322, 147, 'done'),
(5314, 888, 273, 'INV  6424902 RC'),
(5315, 888, 274, 'RW012'),
(5316, 888, 272, '1121.25'),
(5317, 888, 275, '2025-12-12'),
(5318, 888, 276, 'Rectron'),
(5319, 888, 277, 'Paid'),
(5320, 889, 281, 'INV  6424902 RC'),
(5321, 889, 148, 'RW012'),
(5322, 889, 284, '1121.25'),
(5323, 889, 282, '2025-12-12'),
(5324, 889, 159, 'done'),
(5325, 889, 283, 'Paid'),
(5326, 889, 280, 'Rectron'),
(5327, 890, 281, 'INV  6424905 RC'),
(5328, 890, 148, 'RW013'),
(5329, 890, 284, '13984.00'),
(5330, 890, 282, '2025-12-12'),
(5331, 890, 280, 'Rectron'),
(5332, 890, 283, 'Paid'),
(5333, 890, 159, 'done'),
(5334, 891, 273, 'INV  6424905 RC'),
(5335, 891, 274, 'RW013'),
(5336, 891, 272, '13984.00'),
(5337, 891, 275, '2025-12-12'),
(5338, 891, 276, 'Rectron'),
(5339, 891, 277, 'Paid'),
(5340, 892, 281, 'QTE  00014652'),
(5341, 892, 284, '18745.71'),
(5342, 892, 282, '2025-12-10'),
(5343, 892, 280, 'Mixed Trees Trading'),
(5344, 892, 283, 'Paid'),
(5345, 892, 159, 'done'),
(5346, 893, 281, 'Order Nr 5001228777'),
(5347, 893, 148, 'RIV098 A'),
(5348, 893, 284, '399.00'),
(5349, 893, 282, '2025-12-11'),
(5350, 893, 280, 'Builders Warehouse'),
(5351, 893, 283, 'Paid'),
(5352, 893, 159, 'done'),
(5353, 894, 281, 'Doc Nr  B50111225144040'),
(5354, 894, 148, 'RIV098 B'),
(5355, 894, 284, '655.00'),
(5356, 894, 282, '2025-12-11'),
(5357, 894, 280, 'Builders Warehouse'),
(5358, 894, 159, 'done'),
(5359, 894, 283, 'Paid'),
(5360, 895, 281, 'Not Given'),
(5361, 895, 148, 'RIV099'),
(5362, 895, 284, '1111.30'),
(5363, 895, 282, '2025-12-11'),
(5364, 895, 280, 'Garage'),
(5365, 895, 283, 'Paid'),
(5366, 895, 159, 'done'),
(5367, 896, 281, 'Not Given'),
(5368, 896, 148, 'RIV100'),
(5369, 896, 284, '1329.10'),
(5370, 896, 282, '2025-12-12'),
(5371, 896, 280, 'Garage'),
(5372, 896, 283, 'Paid'),
(5373, 896, 159, 'done'),
(5374, 897, 281, 'Not Given'),
(5375, 897, 148, 'RIV101'),
(5376, 897, 284, '550.00'),
(5377, 897, 282, '2025-12-12'),
(5378, 897, 280, 'Garage'),
(5379, 897, 283, 'Paid'),
(5380, 897, 159, 'done'),
(5381, 898, 281, 'Not Given'),
(5382, 898, 148, 'RIV102'),
(5383, 898, 284, '1545.30'),
(5384, 898, 282, '2025-12-13'),
(5385, 898, 280, 'Garage'),
(5386, 898, 283, 'Paid'),
(5387, 898, 159, 'done'),
(5388, 899, 281, 'Doc Nr B50131225144017'),
(5389, 899, 148, 'RIV103 A'),
(5390, 899, 284, '1165.00'),
(5391, 899, 282, '2025-12-13'),
(5392, 899, 280, 'Builders Warehouse'),
(5393, 899, 283, 'Paid'),
(5394, 899, 159, 'done'),
(5395, 900, 281, 'Not Given'),
(5396, 900, 148, 'RIV103 B'),
(5397, 900, 284, '137.94'),
(5398, 900, 282, '2025-12-13'),
(5399, 900, 280, 'DIY Depot'),
(5400, 900, 283, 'Paid'),
(5401, 900, 159, 'done'),
(5402, 901, 281, 'Doc Nr  B50141225143006'),
(5403, 901, 148, 'RIV104'),
(5404, 901, 284, '569.00'),
(5405, 901, 282, '2025-12-14'),
(5406, 901, 280, 'Builders Warehouse'),
(5407, 901, 283, 'Paid'),
(5408, 901, 159, 'done'),
(5409, 902, 281, 'QTE  00014652'),
(5410, 902, 148, 'RIV105'),
(5411, 902, 284, '18745.71'),
(5412, 902, 282, '2025-12-10'),
(5413, 902, 280, 'Mixed Trees Trading'),
(5414, 902, 159, 'done'),
(5415, 902, 283, 'Paid'),
(5416, 903, 281, 'QTE  HC9211'),
(5417, 903, 148, 'RIV106'),
(5418, 903, 284, '20930.00'),
(5419, 903, 282, '2025-12-10'),
(5420, 903, 280, 'Kwick Access'),
(5421, 903, 159, 'done'),
(5422, 903, 283, 'Paid'),
(5423, 852, 274, 'RIV027'),
(5424, 853, 274, 'RIV028'),
(5425, 854, 274, 'RIV029'),
(5426, 904, 273, 'Not Given'),
(5427, 904, 274, 'WAT036'),
(5428, 904, 272, '900.00'),
(5429, 904, 275, '2025-12-03'),
(5430, 904, 276, 'PKGM Projects Hardware'),
(5431, 904, 277, 'Paid'),
(5432, 905, 273, 'Not Given'),
(5433, 905, 274, 'WAT037'),
(5434, 905, 272, '5029.00'),
(5435, 905, 275, '2025-11-04'),
(5436, 905, 276, 'PKGM Projects Hardware'),
(5437, 905, 277, 'Paid'),
(5438, 906, 273, 'Not Given'),
(5439, 906, 274, 'WAT038'),
(5440, 906, 272, '747.00'),
(5441, 906, 275, '2025-12-04'),
(5442, 906, 276, 'PKGM Projects Hardware'),
(5443, 906, 277, 'Paid'),
(5444, 907, 273, 'Not Given'),
(5445, 907, 274, 'Not Given'),
(5446, 907, 272, '58.00'),
(5447, 907, 275, '2025-12-09'),
(5448, 907, 276, 'Uber'),
(5449, 907, 277, 'Paid'),
(5450, 908, 273, 'Not Given'),
(5451, 908, 274, 'WAT039'),
(5452, 908, 272, '1241.50'),
(5453, 908, 275, '2025-12-09'),
(5454, 908, 276, 'Garage'),
(5455, 908, 277, 'Paid'),
(5456, 909, 274, 'WAT040'),
(5457, 910, 274, 'WAT040'),
(5458, 911, 273, 'Not Given'),
(5459, 911, 274, 'WAT041'),
(5460, 911, 272, '440.00'),
(5461, 911, 275, '2025-12-12'),
(5462, 911, 276, 'PKGM Projects Hardware'),
(5463, 911, 277, 'Paid'),
(5464, 912, 273, 'Not Given'),
(5465, 912, 274, 'WAT042'),
(5466, 912, 272, '4100.00'),
(5467, 912, 275, '2025-12-13'),
(5468, 912, 276, 'PKGM Projects Hardware'),
(5469, 912, 277, 'Paid'),
(5470, 913, 274, 'WAT043'),
(5471, 913, 273, 'QTE  HC9210'),
(5472, 913, 276, 'Kwick Access'),
(5473, 913, 277, 'Paid'),
(5474, 914, 273, 'Not Given'),
(5475, 914, 274, 'Not Given'),
(5476, 914, 272, '2000.00'),
(5477, 914, 275, '2025-11-15'),
(5478, 914, 276, 'Welder Subcontractor'),
(5479, 914, 277, 'Paid'),
(5480, 915, 273, 'Not Given'),
(5481, 915, 274, 'Not Given '),
(5482, 915, 272, '15000.00'),
(5483, 915, 275, '2025-11-27'),
(5484, 915, 276, 'Welder Subcontractor '),
(5485, 915, 277, 'Paid'),
(5486, 916, 273, 'Not Given'),
(5487, 916, 274, 'Not Given'),
(5488, 916, 272, '5000.00'),
(5489, 916, 275, '2025-12-11'),
(5490, 916, 276, 'Welder Subcontractor '),
(5491, 916, 277, 'Paid'),
(5492, 910, 272, '14433.00'),
(5493, 910, 275, '2025-12-12'),
(5494, 910, 276, '@ Paints'),
(5495, 910, 277, 'Paid'),
(5496, 910, 273, 'QTE3990'),
(5497, 917, 281, 'Not Given'),
(5498, 917, 148, 'RIV107'),
(5499, 917, 284, '1200.00'),
(5500, 917, 282, '2025-12-13'),
(5501, 917, 280, 'Garage'),
(5502, 917, 283, 'Paid'),
(5503, 917, 159, 'done'),
(5504, 918, 281, 'Not Given'),
(5505, 918, 148, 'RIV108'),
(5506, 918, 284, '8700.00'),
(5507, 918, 282, '2025-12-14'),
(5508, 918, 280, 'Frank\'s Glass Trading'),
(5509, 918, 283, 'Paid'),
(5510, 918, 159, 'done'),
(5511, 919, 309, 'Doc Nr B201111225106019'),
(5512, 919, 310, '295.00'),
(5513, 919, 313, '2025-12-11'),
(5514, 919, 311, 'Builders Warehouse'),
(5515, 919, 314, 'Paid'),
(5517, 919, 315, 'done'),
(5518, 920, 327, '25000'),
(5519, 921, 327, '5000'),
(5520, 922, 327, '15000'),
(5521, 923, 327, '45000'),
(5522, 924, 327, '3000'),
(5523, 925, 327, '3500'),
(5524, 926, 327, '3500'),
(5525, 927, 327, '3000'),
(5526, 928, 281, 'Not Given'),
(5527, 928, 148, 'RIV109'),
(5528, 928, 284, '1451.60'),
(5529, 928, 282, '2025-12-15'),
(5530, 928, 280, 'Garage'),
(5531, 928, 283, 'Paid'),
(5532, 928, 159, 'done'),
(5533, 929, 281, 'Doc Nr 1186906317'),
(5534, 929, 148, 'RIV110'),
(5535, 929, 284, '24711.40'),
(5536, 929, 282, '2025-12-15'),
(5537, 929, 280, 'Italtile'),
(5538, 929, 283, 'Paid'),
(5539, 929, 159, 'done'),
(5540, 930, 281, 'Doc Nr B50161225143020'),
(5541, 930, 148, 'RIV111'),
(5542, 930, 284, '2021.00'),
(5543, 930, 282, '2025-12-16'),
(5544, 930, 280, 'Builders Warehouse'),
(5545, 930, 283, 'Paid'),
(5546, 930, 159, 'done'),
(5547, 931, 281, '00016584'),
(5548, 931, 148, 'RIV112'),
(5549, 931, 284, '6150.00'),
(5550, 931, 282, '2025-12-16'),
(5551, 931, 280, 'Talisman Hire'),
(5552, 931, 283, 'Paid'),
(5553, 931, 159, 'done'),
(5554, 932, 281, 'Not Given'),
(5555, 932, 148, 'RIV113'),
(5556, 932, 284, '155.80'),
(5557, 932, 282, '2025-12-16'),
(5558, 932, 280, 'Garage'),
(5559, 932, 283, 'Paid'),
(5560, 932, 159, 'done'),
(5561, 933, 281, 'Tax INV   POS147158'),
(5562, 933, 148, 'RIV114'),
(5563, 933, 284, '139.99'),
(5564, 933, 282, '2025-12-16'),
(5565, 933, 280, 'DIY Depot Rivonia '),
(5566, 933, 283, 'Paid'),
(5567, 933, 159, 'done'),
(5568, 934, 273, 'Doc Nr'),
(5569, 934, 274, 'WAT044'),
(5570, 934, 272, '838.20'),
(5571, 934, 275, '2025-12-15'),
(5572, 934, 276, 'Builders Warehouse'),
(5573, 934, 277, 'Paid'),
(5574, 935, 273, 'INV  A 606004'),
(5575, 935, 274, 'WAT045'),
(5576, 935, 272, '1077.15'),
(5577, 935, 275, '2025-12-16'),
(5578, 935, 276, 'Garage'),
(5579, 935, 277, 'Paid'),
(5580, 936, 328, 'DEV001'),
(5581, 937, 328, 'DEV002'),
(5582, 937, 309, 'Not Given'),
(5583, 937, 310, '600.00'),
(5584, 937, 313, '2025-12-16'),
(5585, 937, 311, 'PKGM Projects Hardware'),
(5586, 937, 314, 'Paid'),
(5587, 937, 315, 'done'),
(5588, 938, 309, '9918'),
(5589, 938, 328, 'DEV003'),
(5590, 938, 310, '500.00'),
(5591, 938, 313, '2025-12-16'),
(5592, 938, 314, 'Paid'),
(5593, 938, 315, 'done'),
(5594, 938, 311, 'Brackenwest Hardware'),
(5595, 939, 309, 'Doc Nr 957117'),
(5596, 939, 328, 'DEV004'),
(5597, 939, 310, '409.80'),
(5598, 939, 313, '2025-12-16'),
(5599, 939, 311, 'Jacks Paints & Hardware'),
(5600, 939, 314, 'Paid'),
(5601, 939, 315, 'done'),
(5602, 940, 309, 'Doc Nr B201111225106019'),
(5603, 940, 328, 'DEV005'),
(5604, 940, 310, '295.00'),
(5605, 940, 313, '2025-12-11'),
(5606, 940, 311, 'Builders warehouse'),
(5607, 940, 314, 'Paid'),
(5608, 940, 315, 'done'),
(5609, 936, 309, 'Doc Nr B201111225106019'),
(5610, 936, 310, '295.00'),
(5611, 936, 313, '2025-12-11'),
(5612, 936, 311, 'Builders Warehouse'),
(5613, 936, 314, 'Paid'),
(5614, 936, 315, 'done'),
(5615, 941, 281, 'Not Given'),
(5616, 941, 148, 'Not Given'),
(5617, 941, 284, '5000.00'),
(5618, 941, 282, '2025-12-16'),
(5619, 941, 280, 'carpenters'),
(5620, 941, 283, 'Paid'),
(5621, 941, 159, 'done'),
(5622, 942, 281, 'Not Given'),
(5623, 942, 148, 'Not Given'),
(5624, 942, 284, '4920.00'),
(5625, 942, 282, '2025-12-17'),
(5626, 942, 280, 'Laborers'),
(5627, 942, 283, 'Paid'),
(5628, 942, 159, 'done'),
(5629, 943, 273, 'Not Given'),
(5630, 943, 274, 'Not Given'),
(5631, 943, 272, '3500.00'),
(5632, 943, 275, '2025-12-17'),
(5633, 943, 276, 'Hennie Smit'),
(5634, 943, 277, 'Paid'),
(5635, 944, 273, 'not Given'),
(5636, 944, 274, 'WAT046'),
(5637, 944, 272, '784.00'),
(5638, 944, 275, '2025-12-17'),
(5639, 944, 276, 'PKGM Projects Hardware'),
(5640, 944, 277, 'Paid'),
(5641, 945, 281, 'Doc Nr B50171225142037'),
(5642, 945, 148, 'RIV115'),
(5643, 945, 284, '258.00'),
(5644, 945, 282, '2025-12-17'),
(5645, 945, 280, 'Builders warehouse'),
(5646, 945, 283, 'Paid'),
(5647, 945, 159, 'done'),
(5648, 390, 286, '100'),
(5649, 390, 241, 'done'),
(5650, 391, 286, '100'),
(5651, 391, 241, 'done'),
(5652, 490, 241, 'working'),
(5653, 490, 286, '80'),
(5654, 350, 147, 'done'),
(5655, 350, 278, '100'),
(5656, 357, 147, 'done'),
(5657, 357, 278, '100'),
(5658, 369, 278, '100'),
(5659, 369, 147, 'done'),
(5660, 946, 273, 'Not Given'),
(5661, 946, 274, 'Not Given'),
(5662, 946, 272, '3630.00'),
(5663, 946, 275, '2025-12-19'),
(5664, 946, 276, 'Kotwals motor spares'),
(5665, 946, 277, 'Paid'),
(5666, 947, 273, 'Not Given'),
(5667, 947, 274, 'Not Given '),
(5668, 947, 272, '2500.00'),
(5669, 947, 275, '2025-12-19'),
(5670, 947, 276, 'Hennie Smit'),
(5671, 947, 277, 'Paid'),
(5672, 829, 292, '0'),
(5673, 372, 147, 'done'),
(5674, 372, 278, '100'),
(5675, 948, 281, 'Doc Nr B50171225141080'),
(5676, 948, 148, 'RIV116'),
(5677, 948, 284, '226.00'),
(5678, 948, 282, '2025-12-17'),
(5679, 948, 280, 'builders Warehouse'),
(5680, 948, 283, 'Paid'),
(5681, 948, 159, 'done'),
(5682, 949, 281, 'Doc Nr B50171225113020'),
(5683, 949, 148, 'RIV117'),
(5684, 949, 284, '620.00'),
(5685, 949, 282, '2025-12-17'),
(5686, 949, 280, 'Builders Warehouse'),
(5687, 949, 283, 'Paid'),
(5688, 949, 159, 'done'),
(5689, 950, 281, 'Not Given'),
(5690, 950, 148, 'RIV118'),
(5691, 950, 284, '1362.70'),
(5692, 950, 282, '2025-12-18'),
(5693, 950, 280, 'Garage'),
(5694, 950, 283, 'Paid'),
(5695, 950, 159, 'done'),
(5696, 951, 281, 'Doc Nr B50181225104257'),
(5697, 951, 148, 'RIV119'),
(5698, 951, 284, '235.00'),
(5699, 951, 282, '2025-12-18'),
(5700, 951, 280, 'Builders warehouse'),
(5701, 951, 283, 'Paid'),
(5702, 951, 159, 'done'),
(5703, 952, 281, 'Not Given'),
(5704, 952, 148, 'Not Given'),
(5705, 952, 284, '500.00'),
(5706, 952, 282, '2025-12-18'),
(5707, 952, 280, 'Laborers '),
(5708, 952, 283, 'Paid'),
(5709, 952, 159, 'done'),
(5710, 953, 281, 'Not Given'),
(5711, 953, 148, 'Not given'),
(5712, 953, 284, '300.00'),
(5713, 953, 282, '2025-12-18'),
(5714, 953, 280, 'Painter'),
(5715, 953, 283, 'Paid'),
(5716, 953, 159, 'done'),
(5717, 954, 281, 'Not Given'),
(5718, 954, 148, 'Not Given'),
(5719, 954, 284, '200.00'),
(5720, 954, 282, '2025-12-18'),
(5721, 954, 280, 'JMPD'),
(5722, 954, 283, 'Paid'),
(5723, 954, 159, 'done'),
(5724, 955, 281, 'Not Given'),
(5725, 955, 148, 'Not Given'),
(5726, 955, 284, '800.00'),
(5727, 955, 282, '2025-12-19'),
(5728, 955, 280, 'Laborers & Painter'),
(5729, 955, 283, 'Paid'),
(5730, 955, 159, 'done'),
(5731, 956, 281, 'Not Given'),
(5732, 956, 148, 'Not Given'),
(5733, 956, 284, '1270.65'),
(5734, 956, 282, '2025-12-20'),
(5735, 956, 280, 'Garage'),
(5736, 956, 283, 'Paid'),
(5737, 956, 159, 'done'),
(5738, 957, 281, 'Not Given'),
(5739, 957, 148, 'RIV120'),
(5740, 957, 284, '1214.28'),
(5741, 957, 282, '2026-01-05'),
(5742, 957, 280, 'Garage'),
(5743, 957, 283, 'Paid'),
(5744, 957, 159, 'done'),
(5745, 958, 281, 'Not Given'),
(5746, 958, 148, 'Not Given'),
(5747, 958, 284, '27.00'),
(5748, 958, 282, '2026-01-05'),
(5749, 958, 280, 'N1 South Grasmere'),
(5750, 958, 283, 'Paid'),
(5751, 958, 159, 'done'),
(5752, 959, 281, 'Doc Nr B50060126143033'),
(5753, 959, 148, 'RIV121'),
(5754, 959, 284, '1199.00'),
(5755, 959, 282, '2026-01-06'),
(5756, 959, 280, 'Builders Warehouse'),
(5757, 959, 283, 'Paid'),
(5758, 959, 159, 'done'),
(5759, 961, 281, 'Not Given'),
(5760, 961, 148, 'RIV123'),
(5761, 961, 284, '970.00'),
(5762, 961, 282, '2026-01-07'),
(5763, 961, 280, 'Garage'),
(5764, 961, 283, 'Paid'),
(5765, 961, 159, 'done'),
(5766, 962, 281, 'Not Given'),
(5767, 963, 281, 'Not Given'),
(5768, 962, 148, 'Not Given'),
(5769, 963, 148, 'Not Given'),
(5770, 962, 284, '27.00'),
(5771, 963, 284, '27.00'),
(5772, 962, 282, '2026-01-07'),
(5773, 963, 282, '2026-01-07'),
(5774, 962, 280, 'N1 South Grasmere'),
(5775, 963, 280, 'N1 South Grassmere '),
(5776, 962, 283, 'Paid'),
(5777, 962, 159, 'done'),
(5778, 963, 159, 'done'),
(5779, 963, 283, 'Paid'),
(5780, 964, 281, 'Doc Nr B50070126143072'),
(5781, 964, 148, 'RIV124'),
(5782, 964, 284, '300.00'),
(5783, 964, 282, '2026-01-07'),
(5784, 964, 280, 'Builders Warehouse'),
(5785, 964, 283, 'Paid'),
(5786, 964, 159, 'done'),
(5787, 965, 282, '2026-01-08'),
(5788, 966, 282, '2026-01-08'),
(5789, 967, 282, '2026-01-09'),
(5790, 965, 281, 'Not Given'),
(5791, 965, 148, 'Not Given '),
(5792, 966, 281, 'Not Given '),
(5793, 966, 148, 'Not Given '),
(5794, 967, 148, 'Not Given '),
(5795, 967, 281, 'Not Given '),
(5796, 965, 284, '27.00'),
(5797, 966, 284, '27.00'),
(5798, 967, 284, '27.00'),
(5799, 965, 280, 'N1 South Grasmere '),
(5800, 966, 280, 'N1 South Grasmere '),
(5801, 967, 280, 'N1 South Grasmere '),
(5802, 965, 283, 'Paid'),
(5803, 966, 283, 'Paid'),
(5804, 967, 283, 'Paid'),
(5805, 965, 159, 'done'),
(5806, 966, 159, 'done'),
(5807, 967, 159, 'done'),
(5808, 968, 281, 'Not Given '),
(5809, 968, 148, 'Not given'),
(5810, 968, 284, '27.00'),
(5811, 968, 282, '2026-01-09'),
(5812, 968, 280, 'N1 South Grasmere '),
(5813, 968, 283, 'Paid'),
(5814, 968, 159, 'done'),
(5815, 969, 281, 'Not Given'),
(5816, 969, 148, 'RIV125'),
(5817, 969, 284, '1100.00'),
(5818, 969, 282, '2026-01-09'),
(5819, 969, 280, 'garage'),
(5820, 969, 283, 'Paid'),
(5821, 969, 159, 'done'),
(5822, 970, 281, 'Not Given '),
(5823, 971, 281, 'Not Given '),
(5824, 972, 281, 'Not Given '),
(5825, 973, 281, 'Not Given '),
(5826, 970, 148, 'Not Given '),
(5827, 971, 148, 'Not Given '),
(5828, 972, 148, 'Not Given '),
(5829, 973, 148, 'Not Given '),
(5830, 970, 284, '27.00'),
(5831, 971, 284, '27.00'),
(5832, 972, 284, '27.00'),
(5833, 973, 284, '27.00'),
(5834, 970, 282, '2026-01-10'),
(5835, 971, 282, '2026-01-10'),
(5836, 972, 282, '2026-01-11'),
(5837, 973, 282, '2026-01-11'),
(5838, 970, 280, 'N1 South Grasmere '),
(5839, 971, 280, 'N1 South Grasmere '),
(5840, 972, 280, 'N1 South Grasmere '),
(5841, 973, 280, 'N1 South Grasmere '),
(5842, 970, 283, 'Paid'),
(5843, 971, 283, 'Paid'),
(5844, 972, 283, 'Paid'),
(5845, 973, 283, 'Paid'),
(5846, 970, 159, 'done'),
(5847, 971, 159, 'done'),
(5848, 972, 159, 'done'),
(5849, 973, 159, 'done'),
(5850, 974, 148, 'RIV126'),
(5851, 974, 284, '574.00'),
(5852, 974, 282, '2026-01-10'),
(5853, 974, 281, 'Doc Nr 43046 & 32092'),
(5854, 974, 280, 'Builders Warehouse'),
(5855, 974, 283, 'Paid'),
(5856, 974, 159, 'done'),
(5857, 975, 148, 'RIV127'),
(5858, 975, 281, 'Not Given'),
(5859, 975, 284, '1200.00'),
(5860, 975, 282, '2026-01-11'),
(5861, 975, 280, 'Garage'),
(5862, 975, 283, 'Paid'),
(5863, 975, 159, 'done'),
(5864, 976, 148, 'Not Given '),
(5865, 976, 281, 'Not Given '),
(5866, 976, 284, '27.00'),
(5867, 976, 282, '2026-01-12'),
(5868, 977, 281, 'Not Given '),
(5869, 977, 148, 'Not Given '),
(5870, 977, 284, '27.00'),
(5871, 977, 282, '2026-01-12'),
(5872, 976, 280, 'N1 South Grasmere '),
(5873, 977, 280, 'N1 South Grasmere '),
(5874, 977, 283, 'Paid'),
(5875, 977, 159, 'done'),
(5876, 976, 159, 'done'),
(5877, 976, 283, 'Paid'),
(5878, 978, 274, 'WAT047'),
(5879, 978, 272, '453.00'),
(5880, 978, 275, '2025-12-18'),
(5881, 978, 273, 'Not Given'),
(5882, 978, 277, 'Paid'),
(5883, 978, 276, 'PKGM Projects Hardware'),
(5884, 979, 273, 'Not Given'),
(5885, 979, 274, 'WAT048'),
(5886, 979, 272, '2260.00'),
(5887, 979, 275, '2025-12-18'),
(5888, 979, 276, 'B&A Paints'),
(5889, 979, 277, 'Paid'),
(5890, 980, 273, 'Not Given'),
(5891, 980, 274, 'WAT049'),
(5892, 980, 272, '89.00'),
(5893, 980, 275, '2026-01-05'),
(5894, 980, 276, 'PKGM Projects Hardware'),
(5895, 980, 277, 'Paid'),
(5896, 981, 273, 'Not Given '),
(5897, 981, 274, 'Not Given '),
(5898, 982, 274, 'Not Given '),
(5899, 982, 273, 'Not Given '),
(5900, 981, 276, 'N1 South Grasmere '),
(5901, 982, 276, 'N1 South Grasmere '),
(5902, 981, 275, '2026-01-05'),
(5903, 982, 275, '2026-01-05'),
(5904, 981, 272, '27.00'),
(5905, 982, 272, '27.00'),
(5906, 981, 277, 'Paid'),
(5907, 982, 277, 'Paid'),
(5908, 983, 273, 'Not Given'),
(5909, 983, 274, 'WAT050'),
(5910, 983, 272, '160.00'),
(5911, 983, 275, '2026-01-06'),
(5912, 983, 276, 'PKGM Projects Hardware '),
(5913, 983, 277, 'Paid'),
(5914, 984, 273, 'Not Given');
INSERT INTO `board_item_values` (`id`, `item_id`, `column_id`, `value`) VALUES
(5915, 984, 274, 'WAT051'),
(5916, 984, 275, '2026-01-07'),
(5917, 984, 272, '150.00'),
(5918, 984, 276, 'PKGM Projects Hardware '),
(5919, 984, 277, 'Paid'),
(5920, 985, 273, 'INV3380832'),
(5921, 985, 274, 'WAT052'),
(5922, 985, 272, '2046.18'),
(5923, 985, 275, '2026-01-08'),
(5924, 985, 276, 'Riverside Mega Mica'),
(5925, 985, 277, 'Paid'),
(5926, 986, 282, '2026-01-13'),
(5927, 987, 282, '2026-01-13'),
(5928, 986, 281, 'Not Given '),
(5929, 986, 280, 'N1 South Grasmere '),
(5930, 987, 280, 'N1 South Grasmere '),
(5931, 986, 284, '27.00'),
(5932, 987, 284, '27.00'),
(5933, 986, 148, 'Not Given '),
(5934, 987, 148, 'Not Given '),
(5935, 987, 281, 'Not Given '),
(5936, 986, 159, 'done'),
(5937, 987, 159, 'done'),
(5938, 986, 283, 'Paid'),
(5939, 987, 283, 'Paid'),
(5940, 988, 281, 'Not Given'),
(5941, 988, 148, 'RIV128'),
(5942, 988, 284, '998.60'),
(5943, 988, 282, '2026-01-13'),
(5944, 988, 280, 'Garage '),
(5945, 988, 283, 'Paid'),
(5946, 988, 159, 'done'),
(5947, 989, 273, 'Not Given'),
(5948, 989, 274, 'Not Given '),
(5949, 989, 272, '1000.00'),
(5950, 989, 275, '2026-01-05'),
(5951, 989, 276, 'Concreto'),
(5952, 989, 277, 'Paid'),
(5953, 960, 148, 'RIV122'),
(5954, 960, 284, '3000'),
(5955, 960, 282, '2026-01-06'),
(5956, 960, 280, 'Coastal Hire'),
(5957, 960, 283, 'Deposite Paid'),
(5958, 960, 159, 'done'),
(5959, 960, 281, 'Not Given'),
(5960, 990, 273, 'Not Given '),
(5961, 990, 274, 'Not Given '),
(5962, 990, 272, '500.00'),
(5963, 990, 275, '2026-01-07'),
(5964, 990, 276, 'Winston Kotze '),
(5965, 990, 277, 'Paid'),
(5966, 991, 273, 'Not Given '),
(5967, 991, 274, 'Not Given '),
(5968, 991, 272, '160.00'),
(5969, 991, 275, '2026-01-07'),
(5970, 991, 276, 'Winston Kotze '),
(5971, 991, 277, 'Paid'),
(5972, 992, 273, 'Not Given '),
(5973, 992, 274, 'Not Given'),
(5974, 992, 272, '1000.00'),
(5975, 992, 275, '2026-01-09'),
(5976, 992, 276, 'Concreto '),
(5977, 992, 277, 'Paid'),
(5978, 993, 273, 'Not Given '),
(5979, 993, 274, 'Not Given '),
(5980, 993, 272, '2000.00'),
(5981, 993, 275, '2026-01-12'),
(5982, 993, 276, 'Concreto '),
(5983, 993, 277, 'Paid'),
(5984, 994, 309, 'Not Given '),
(5985, 994, 328, 'Not Given '),
(5986, 994, 310, '7850.00'),
(5987, 994, 313, '2026-01-19'),
(5988, 995, 281, 'Not Given '),
(5989, 995, 148, 'Not Given '),
(5990, 995, 284, '30740.00'),
(5991, 995, 282, '2025-11-06'),
(5992, 995, 280, 'Concreto Workers '),
(5993, 995, 283, 'Paid'),
(5994, 995, 159, 'done'),
(5995, 996, 281, 'Not Given '),
(5996, 996, 148, 'Not Given '),
(5997, 996, 284, '40320.00'),
(5998, 996, 282, '2025-11-21'),
(5999, 996, 280, 'Concreto Workers '),
(6000, 996, 283, 'Paid'),
(6001, 996, 159, 'done'),
(6002, 997, 281, 'Not Given '),
(6003, 997, 148, 'Not Given '),
(6004, 997, 284, '26470.00'),
(6005, 997, 282, '2025-12-05'),
(6006, 997, 280, 'Concreto Workers'),
(6007, 997, 283, 'Paid'),
(6008, 997, 159, 'done'),
(6009, 998, 281, 'Not Given '),
(6010, 998, 148, 'Not Given '),
(6011, 998, 284, '35160.00'),
(6012, 998, 282, '2025-12-19'),
(6013, 998, 280, 'Concreto Workers '),
(6014, 998, 283, 'Paid'),
(6015, 998, 159, 'done'),
(6016, 999, 291, '1'),
(6017, 999, 292, '0'),
(6018, 1000, 291, '1'),
(6020, 1001, 291, '1'),
(6021, 1001, 292, '-38000'),
(6022, 1002, 333, '1'),
(6023, 1002, 334, '25000'),
(6024, 1003, 333, '1'),
(6025, 1003, 334, '15000'),
(6026, 1004, 333, '1'),
(6027, 1004, 334, '6500'),
(6028, 1005, 333, '1'),
(6029, 1005, 334, '10000'),
(6030, 1006, 333, '1'),
(6031, 1006, 334, '95000'),
(6032, 1002, 335, '25000.00'),
(6033, 1003, 335, '15000.00'),
(6034, 1004, 335, '6500.00'),
(6035, 1005, 335, '10000.00'),
(6036, 1006, 335, '95000.00'),
(6037, 1007, 333, '2'),
(6038, 1007, 334, '2500'),
(6044, 1007, 335, '10000.00'),
(6051, 1008, 292, '-12000'),
(6053, 819, 293, '0.00'),
(6054, 821, 293, '5000.00'),
(6055, 822, 293, '8000.00'),
(6056, 823, 293, '0.00'),
(6057, 824, 293, '18000.00'),
(6058, 825, 293, '0.00'),
(6059, 826, 293, '8500.00'),
(6060, 1000, 293, '35000.00'),
(6061, 827, 293, '10000.00'),
(6062, 828, 293, '20000.00'),
(6063, 829, 293, '15000.00'),
(6064, 1008, 293, '0.00'),
(6065, 830, 293, '18000.00'),
(6066, 831, 293, '50000.00'),
(6067, 832, 293, '12000.00'),
(6068, 834, 293, '6000.00'),
(6069, 836, 293, '15000.00'),
(6070, 999, 293, '175000.00'),
(6071, 1001, 293, '38000.00'),
(6092, 1008, 291, '1'),
(6093, 1009, 291, '1'),
(6094, 1009, 292, '0'),
(6096, 1010, 343, 'Foreman'),
(6097, 1010, 344, '1'),
(6098, 1010, 351, '1'),
(6099, 1010, 346, '5.00'),
(6100, 1010, 345, '690'),
(6101, 1010, 354, '2760.00'),
(6104, 1010, 352, '1'),
(6105, 1010, 355, '1'),
(6106, 1010, 356, '1'),
(6107, 1011, 343, 'General Worker'),
(6108, 1011, 345, '250'),
(6110, 1011, 346, '0.00'),
(6111, 1010, 357, '0'),
(6118, 1011, 354, '0.00'),
(6123, 1010, 365, '1'),
(6128, 1011, 357, '0'),
(6130, 1010, 359, '1'),
(6131, 1010, 360, '1'),
(6132, 1010, 361, '1'),
(6133, 1010, 362, '1'),
(6134, 1010, 363, '1'),
(6135, 1010, 364, '0'),
(6136, 1012, 343, 'plasterer'),
(6137, 1013, 343, 'General Worker'),
(6138, 1014, 343, 'General Worker'),
(6139, 1015, 343, 'General Worker'),
(6140, 1012, 357, '0'),
(6141, 1013, 357, '0'),
(6142, 1014, 357, '0'),
(6143, 1015, 357, '0'),
(6148, 1011, 351, '1'),
(6149, 1012, 351, '1'),
(6150, 1013, 351, '1'),
(6151, 1014, 351, '1'),
(6152, 1015, 351, '1'),
(6153, 1011, 344, '1'),
(6154, 1012, 344, '1'),
(6155, 1013, 344, '1'),
(6156, 1014, 344, '1'),
(6157, 1015, 344, '0'),
(6158, 1011, 352, '1'),
(6159, 1012, 352, '1'),
(6160, 1013, 352, '1'),
(6161, 1014, 352, '0'),
(6162, 1015, 352, '1'),
(6163, 1011, 355, '1'),
(6164, 1012, 355, '1'),
(6165, 1013, 355, '1'),
(6166, 1014, 355, '1'),
(6167, 1015, 355, '1'),
(6168, 1011, 356, '1'),
(6169, 1012, 356, '1'),
(6170, 1013, 356, '1'),
(6171, 1014, 356, '1'),
(6172, 1015, 356, '1'),
(6173, 1016, 343, 'General Worker'),
(6174, 1016, 357, '0'),
(6176, 1016, 344, '0'),
(6177, 1016, 351, '0'),
(6178, 1016, 352, '0'),
(6179, 1016, 355, '0'),
(6180, 1016, 356, '0'),
(6181, 1011, 359, '1'),
(6182, 1012, 359, '1'),
(6183, 1013, 359, '1'),
(6184, 1014, 359, '1'),
(6185, 1015, 359, '1'),
(6186, 1016, 359, '1'),
(6187, 1015, 360, '0'),
(6188, 1016, 360, '1'),
(6189, 1011, 360, '1'),
(6190, 1012, 360, '1'),
(6191, 1013, 360, '1'),
(6192, 1014, 360, '1'),
(6193, 1011, 361, '1'),
(6194, 1012, 361, '1'),
(6195, 1013, 361, '1'),
(6196, 1014, 361, '1'),
(6197, 1015, 361, '1'),
(6198, 1016, 361, '1'),
(6199, 1015, 362, '0'),
(6200, 1012, 362, '1'),
(6201, 1011, 362, '1'),
(6202, 1013, 362, '1'),
(6203, 1014, 362, '1'),
(6204, 1016, 362, '1'),
(6205, 1011, 363, '1'),
(6206, 1012, 363, '1'),
(6207, 1013, 363, '1'),
(6208, 1014, 363, '1'),
(6209, 1015, 363, '1'),
(6210, 1016, 363, '1'),
(6211, 1011, 364, '0'),
(6212, 1012, 364, '0'),
(6213, 1013, 364, '0'),
(6214, 1014, 364, '0'),
(6215, 1015, 364, '0'),
(6216, 1016, 364, '0'),
(6217, 1012, 345, '450'),
(6218, 1013, 345, '220'),
(6219, 1014, 345, '220'),
(6220, 1015, 345, '220'),
(6221, 1016, 345, '200'),
(6222, 1017, 343, 'General Workers'),
(6223, 1018, 343, 'General Workers'),
(6224, 1019, 343, 'General Workers'),
(6225, 1020, 343, 'General Workers'),
(6226, 1021, 343, 'General Workers'),
(6227, 1022, 343, 'General Workers'),
(6228, 1017, 357, '0'),
(6229, 1018, 357, '0'),
(6230, 1019, 357, '0'),
(6231, 1020, 357, '0'),
(6232, 1021, 357, '0'),
(6233, 1022, 357, '0'),
(6240, 1017, 344, '1'),
(6241, 1018, 344, '1'),
(6242, 1019, 344, '1'),
(6243, 1020, 344, '0'),
(6244, 1021, 344, '0'),
(6245, 1022, 344, '0'),
(6246, 1017, 351, '0'),
(6247, 1018, 351, '0'),
(6248, 1019, 351, '1'),
(6249, 1020, 351, '1'),
(6250, 1021, 351, '1'),
(6251, 1022, 351, '0'),
(6252, 1017, 352, '0'),
(6253, 1018, 352, '0'),
(6254, 1019, 352, '1'),
(6255, 1020, 352, '1'),
(6256, 1021, 352, '1'),
(6257, 1022, 352, '1'),
(6258, 1022, 355, '1'),
(6259, 1021, 355, '1'),
(6260, 1020, 355, '1'),
(6261, 1019, 355, '0'),
(6262, 1018, 355, '0'),
(6263, 1017, 355, '0'),
(6264, 1022, 356, '1'),
(6265, 1021, 356, '1'),
(6266, 1020, 356, '1'),
(6267, 1019, 356, '0'),
(6268, 1018, 356, '0'),
(6269, 1017, 356, '0'),
(6270, 1022, 359, '1'),
(6271, 1021, 359, '1'),
(6272, 1020, 359, '1'),
(6273, 1019, 359, '0'),
(6274, 1018, 359, '0'),
(6275, 1017, 359, '0'),
(6276, 1017, 360, '0'),
(6277, 1018, 360, '0'),
(6278, 1019, 360, '0'),
(6279, 1020, 360, '0'),
(6280, 1021, 360, '0'),
(6281, 1022, 360, '0'),
(6282, 1022, 361, '1'),
(6283, 1021, 361, '1'),
(6284, 1020, 361, '1'),
(6285, 1019, 361, '0'),
(6286, 1018, 361, '0'),
(6287, 1017, 361, '0'),
(6288, 1022, 362, '1'),
(6289, 1021, 362, '1'),
(6290, 1020, 362, '1'),
(6291, 1019, 362, '0'),
(6292, 1018, 362, '0'),
(6293, 1017, 362, '0'),
(6294, 1022, 363, '1'),
(6295, 1021, 363, '1'),
(6296, 1020, 363, '1'),
(6297, 1019, 363, '0'),
(6298, 1018, 363, '0'),
(6299, 1017, 363, '0'),
(6300, 1017, 364, '0'),
(6301, 1019, 364, '0'),
(6302, 1018, 364, '0'),
(6303, 1020, 364, '0'),
(6304, 1021, 364, '0'),
(6305, 1022, 364, '0'),
(6306, 1019, 345, '200'),
(6307, 1018, 345, '220'),
(6308, 1017, 345, '220'),
(6309, 1020, 345, '250'),
(6310, 1022, 345, '220'),
(6311, 1021, 345, '220'),
(6314, 1024, 355, '1'),
(6315, 1024, 356, '1'),
(6316, 1024, 343, 'General Worker'),
(6317, 1024, 357, '0'),
(6319, 1024, 344, '0'),
(6320, 1024, 351, '0'),
(6321, 1024, 352, '0'),
(6322, 1024, 359, '0'),
(6323, 1024, 360, '0'),
(6324, 1024, 361, '0'),
(6325, 1024, 363, '0'),
(6326, 1024, 362, '0'),
(6327, 1024, 364, '0'),
(6328, 1023, 362, '1'),
(6329, 1025, 343, 'Cleaning Lady'),
(6330, 1023, 343, 'General Worker'),
(6331, 1025, 357, '0'),
(6333, 1025, 344, '1'),
(6334, 1025, 351, '1'),
(6335, 1025, 352, '1'),
(6336, 1025, 355, '1'),
(6337, 1025, 356, '1'),
(6338, 1025, 359, '0'),
(6339, 1025, 360, '0'),
(6340, 1025, 361, '1'),
(6341, 1025, 362, '1'),
(6342, 1025, 363, '1'),
(6343, 1025, 364, '1'),
(6344, 1023, 364, '0'),
(6345, 1023, 363, '0'),
(6346, 1023, 361, '0'),
(6347, 1023, 360, '0'),
(6348, 1023, 359, '0'),
(6349, 1023, 356, '0'),
(6350, 1023, 355, '0'),
(6351, 1023, 352, '0'),
(6352, 1023, 351, '0'),
(6353, 1023, 344, '0'),
(6355, 1023, 357, '0'),
(6356, 1023, 345, '220'),
(6357, 1024, 345, '200'),
(6358, 1025, 345, '200'),
(6359, 1026, 364, '1'),
(6360, 1026, 363, '1'),
(6362, 1026, 345, '220'),
(6363, 1026, 343, 'General worker '),
(6364, 1026, 357, '0'),
(6366, 1026, 344, '0'),
(6367, 1026, 351, '0'),
(6368, 1026, 352, '0'),
(6369, 1026, 355, '0'),
(6370, 1026, 356, '0'),
(6371, 1026, 359, '0'),
(6372, 1026, 360, '0'),
(6373, 1026, 362, '0'),
(6374, 1026, 361, '0'),
(6375, 1010, 366, '0'),
(6376, 1011, 366, '0'),
(6377, 1012, 366, '0'),
(6378, 1013, 366, '0'),
(6379, 1014, 366, '0'),
(6380, 1015, 366, '0'),
(6381, 1016, 366, '0'),
(6382, 1017, 366, '0'),
(6383, 1018, 366, '0'),
(6384, 1019, 366, '0'),
(6385, 1020, 366, '0'),
(6386, 1021, 366, '0'),
(6387, 1022, 366, '0'),
(6388, 1023, 366, '0'),
(6389, 1024, 366, '0'),
(6390, 1025, 366, '0'),
(6391, 1026, 366, '0'),
(6392, 1024, 365, '0'),
(6393, 1023, 365, '0'),
(6394, 1025, 365, '1'),
(6395, 1022, 365, '1'),
(6396, 1021, 365, '1'),
(6397, 1019, 365, '0'),
(6398, 1020, 365, '1'),
(6399, 1018, 365, '0'),
(6400, 1017, 365, '0'),
(6401, 1011, 365, '1'),
(6402, 1012, 365, '1'),
(6403, 1013, 365, '1'),
(6404, 1014, 365, '1'),
(6405, 1015, 365, '1'),
(6406, 1016, 365, '1'),
(6407, 1027, 273, 'Not Given'),
(6408, 1027, 274, 'Not Given'),
(6409, 1027, 272, '1610.00'),
(6410, 1027, 275, '2025-11-05'),
(6411, 1027, 276, 'Concreto'),
(6412, 1027, 277, 'Paid'),
(6413, 1028, 291, '1'),
(6414, 1028, 292, '-50000'),
(6415, 1029, 273, 'Not Given '),
(6416, 1029, 274, 'Not Given '),
(6417, 1029, 272, '15160.00'),
(6418, 1029, 275, '2025-11-08'),
(6419, 1029, 276, 'Concreto '),
(6420, 1029, 277, 'Paid'),
(6421, 1030, 273, 'Not Given '),
(6422, 1030, 274, 'Not Given '),
(6423, 1030, 272, '16100.00'),
(6424, 1030, 275, '2025-11-22'),
(6425, 1030, 276, 'Concreto '),
(6426, 1030, 277, 'Paid'),
(6427, 1031, 273, 'Not Given '),
(6428, 1031, 274, 'Not Given '),
(6429, 1031, 272, '9320.00'),
(6430, 1031, 275, '2025-12-06'),
(6431, 1031, 276, 'Concreto '),
(6432, 1031, 277, 'Paid'),
(6433, 1032, 273, 'Not Given '),
(6434, 1032, 274, 'Not Given '),
(6435, 1032, 272, '3000.00'),
(6436, 1032, 275, '2025-12-08'),
(6437, 1032, 276, 'Simon Painter'),
(6438, 1032, 277, 'Paid'),
(6439, 1033, 273, 'not Given '),
(6440, 1033, 274, 'Not Given '),
(6441, 1033, 272, '3000.00'),
(6442, 1033, 275, '2025-12-09'),
(6443, 1033, 276, 'Simon Painter'),
(6444, 1033, 277, 'Paid'),
(6445, 1034, 273, 'Not Given '),
(6446, 1034, 274, 'Not Given '),
(6447, 1035, 273, 'Not Given '),
(6448, 1036, 273, 'Not Given '),
(6449, 1037, 273, 'Not Given '),
(6450, 1038, 273, 'Not Given '),
(6451, 1035, 274, 'Not Given '),
(6452, 1036, 274, 'Not Given '),
(6453, 1037, 274, 'Not Given '),
(6454, 1038, 274, 'Not Given '),
(6455, 1034, 276, 'Simon Painter'),
(6456, 1035, 276, 'Simon Painter'),
(6457, 1036, 276, 'Simon Painter'),
(6458, 1038, 276, 'Simon painter'),
(6459, 1037, 276, 'Simon Painter'),
(6460, 1034, 272, '15000.00'),
(6461, 1034, 275, '2025-12-11'),
(6462, 1035, 272, '15000.00'),
(6463, 1035, 275, '2025-12-19'),
(6464, 1036, 272, '1000.00'),
(6465, 1036, 275, '2026-01-05'),
(6466, 1037, 272, '1000.00'),
(6467, 1037, 275, '2026-01-09'),
(6468, 1038, 272, '2000.00'),
(6469, 1038, 275, '2026-01-12'),
(6470, 1034, 277, 'Paid'),
(6471, 1035, 277, 'Paid'),
(6472, 1036, 277, 'Paid'),
(6473, 1037, 277, 'Paid'),
(6474, 1038, 277, 'Paid'),
(6475, 1039, 273, 'Not Given'),
(6476, 1039, 274, 'WAT053'),
(6477, 1039, 272, '2550.00'),
(6478, 1039, 275, '2026-01-06'),
(6479, 1039, 276, 'B&A Paints'),
(6480, 1039, 277, 'Paid'),
(6481, 1040, 273, 'Not Given '),
(6482, 1040, 274, 'WAT054'),
(6483, 1040, 272, '1309.00'),
(6484, 1040, 275, '2026-01-06'),
(6485, 1040, 276, '@Paints'),
(6486, 1040, 277, 'Paid'),
(6487, 1041, 274, 'WAT055'),
(6488, 1042, 273, 'Not Given'),
(6489, 1042, 274, 'WAT056'),
(6490, 1042, 272, '2266.00'),
(6491, 1042, 275, '2026-01-16'),
(6492, 1042, 276, 'B&A Paints'),
(6493, 1042, 277, 'Paid'),
(6494, 1043, 273, 'Not Given'),
(6495, 1043, 274, 'WAT057'),
(6496, 1043, 272, '666.00'),
(6497, 1043, 275, '2026-01-08'),
(6498, 1043, 276, 'Vans Boumateriaal '),
(6499, 1043, 277, 'Paid'),
(6500, 1044, 221, '440.00'),
(6501, 1044, 231, 'Not Given '),
(6502, 1044, 232, 'Not Given '),
(6503, 1044, 233, '2026-01-16'),
(6504, 1044, 230, 'Concreto '),
(6505, 1044, 228, 'done'),
(6506, 1044, 235, 'Paid'),
(6507, 1045, 273, 'Not Given '),
(6508, 1045, 274, 'Not Given '),
(6509, 1045, 272, '7030.00'),
(6510, 1045, 275, '2026-01-16'),
(6511, 1045, 276, 'Concreto '),
(6512, 1045, 277, 'Paid'),
(6513, 1046, 273, 'Not Given '),
(6514, 1046, 274, 'Not Given '),
(6515, 1046, 272, '10000.00'),
(6516, 1046, 275, '2026-01-16'),
(6517, 1046, 276, 'Concreto '),
(6518, 1046, 277, 'Paid'),
(6519, 1048, 281, 'Not Given '),
(6520, 1048, 148, 'Not Given '),
(6521, 1048, 284, '2000.00'),
(6522, 1048, 282, '2026-01-16'),
(6523, 1048, 280, 'BN Moyo'),
(6524, 1048, 283, 'Paid'),
(6525, 1048, 159, 'done'),
(6526, 1047, 281, 'Not Given '),
(6527, 1047, 148, 'Not Given '),
(6528, 1047, 284, '22870.00'),
(6529, 1047, 282, '2026-01-16'),
(6530, 1047, 280, 'Concreto Workers'),
(6531, 1047, 283, 'Paid'),
(6532, 1047, 159, 'done'),
(6533, 1049, 281, 'Doc nr B50130126142061'),
(6534, 364, 147, 'done'),
(6535, 365, 147, 'working'),
(6536, 1049, 148, 'RIV129'),
(6537, 366, 147, 'todo'),
(6538, 1049, 284, '542.00'),
(6539, 1049, 282, '2026-01-13'),
(6540, 324, 147, 'done'),
(6541, 324, 278, '100'),
(6542, 1049, 280, 'Builders Warehouse '),
(6543, 1049, 283, 'Paid'),
(6544, 1049, 159, 'done'),
(6545, 325, 278, '100'),
(6546, 325, 147, 'done'),
(6547, 326, 278, '100'),
(6548, 1050, 281, 'Not Given'),
(6549, 1050, 148, 'RIV130'),
(6550, 1050, 284, '1198.60'),
(6551, 1050, 282, '2026-01-16'),
(6552, 1050, 280, 'Garage'),
(6553, 1050, 283, 'Paid'),
(6554, 1050, 159, 'done'),
(6555, 1051, 281, 'Doc Nr B50160126142031'),
(6556, 1051, 148, 'RIV131'),
(6557, 1051, 284, '1030.00'),
(6558, 1051, 282, '2026-01-16'),
(6559, 1051, 280, 'Builders Warehouse'),
(6560, 1051, 283, 'Paid'),
(6561, 1051, 159, 'done'),
(6562, 1052, 281, 'Doc Nr B50160126144059'),
(6563, 1052, 148, 'RIV132'),
(6564, 1052, 284, '1547.83'),
(6565, 1052, 282, '2026-01-16'),
(6566, 1052, 280, 'Builders Warehouse'),
(6567, 1052, 283, 'Paid'),
(6568, 1052, 159, 'done'),
(6569, 1053, 291, '1'),
(6570, 1053, 292, '200000'),
(6571, 1054, 291, '1'),
(6572, 1054, 292, '569000'),
(6573, 1055, 291, '1'),
(6574, 1055, 292, '0'),
(6575, 1056, 291, '1'),
(6576, 1056, 292, '0'),
(6577, 1057, 291, '1'),
(6578, 1057, 292, '0'),
(6579, 1058, 291, '1'),
(6580, 1058, 292, '0'),
(6581, 1059, 291, '1'),
(6582, 1059, 292, '0'),
(6598, 1060, 393, '690'),
(6599, 1061, 393, '450'),
(6600, 1066, 393, '200'),
(6601, 1064, 393, '250'),
(6602, 1062, 393, '220'),
(6603, 1063, 393, '220'),
(6604, 1065, 393, '220'),
(6605, 1067, 393, '300'),
(6606, 1068, 393, '220'),
(6607, 1069, 393, '220'),
(6608, 1070, 393, '220'),
(6609, 1071, 393, '200'),
(6610, 1072, 393, '200'),
(6611, 1073, 393, '220'),
(6612, 1074, 393, '200'),
(6613, 1074, 395, 'General Worker'),
(6614, 1073, 395, 'General Worker'),
(6615, 1072, 395, 'Cleaning Lady'),
(6616, 1067, 395, 'General Worker'),
(6617, 1068, 395, 'General Worker'),
(6618, 1069, 395, 'General Worker'),
(6619, 1070, 395, 'General Worker'),
(6620, 1071, 395, 'General Worker'),
(6621, 1060, 395, 'Foreman'),
(6622, 1061, 395, 'Plasterer'),
(6623, 1062, 395, 'General Worker'),
(6624, 1063, 395, 'General Worker'),
(6625, 1064, 395, 'General Worker'),
(6626, 1065, 395, 'General Worker'),
(6627, 1066, 395, 'General Worker'),
(6628, 1060, 378, '0'),
(6629, 1061, 378, '0'),
(6630, 1062, 378, '0'),
(6631, 1063, 378, '0'),
(6632, 1064, 378, '0'),
(6633, 1065, 378, '0'),
(6634, 1066, 378, '0'),
(6635, 1067, 378, '0'),
(6636, 1068, 378, '0'),
(6637, 1069, 378, '0'),
(6638, 1070, 378, '0'),
(6639, 1071, 378, '0'),
(6640, 1072, 378, '0'),
(6641, 1073, 378, '0'),
(6642, 1074, 378, '0'),
(6643, 1072, 379, '0'),
(6644, 1073, 379, '0'),
(6645, 1074, 379, '0'),
(6646, 1067, 379, '0'),
(6647, 1068, 379, '0'),
(6648, 1069, 379, '0'),
(6649, 1070, 379, '0'),
(6650, 1071, 379, '0'),
(6651, 1066, 379, '0'),
(6652, 1065, 379, '0'),
(6653, 1060, 379, '0'),
(6654, 1061, 379, '1'),
(6655, 1063, 379, '1'),
(6656, 1062, 379, '0'),
(6657, 1064, 379, '0'),
(6658, 1075, 273, 'INV 658021'),
(6659, 1075, 274, 'Not Given '),
(6660, 1075, 272, '2020.00'),
(6661, 1075, 275, '2025-12-19'),
(6662, 1075, 276, 'Kotwals Motor Spares'),
(6663, 1075, 277, 'Paid'),
(6664, 1076, 273, 'INV 658085'),
(6665, 1076, 274, 'Not Given '),
(6666, 1076, 272, '1365.00'),
(6667, 1076, 275, '2025-12-19'),
(6668, 1076, 276, 'Kotwals Motor Spares '),
(6669, 1076, 277, 'Paid'),
(6670, 1077, 273, 'INV 658027'),
(6671, 1077, 274, 'Not Given'),
(6672, 1077, 272, '245.00'),
(6673, 1077, 275, '2026-01-19'),
(6674, 1077, 276, 'Kotwals Motor Spares '),
(6675, 1077, 277, 'Paid'),
(6676, 1067, 380, '1'),
(6677, 1068, 380, '0'),
(6678, 1069, 380, '1'),
(6679, 1070, 380, '0'),
(6680, 1071, 380, '0'),
(6681, 1070, 381, '0'),
(6682, 1071, 381, '0'),
(6683, 1069, 381, '1'),
(6684, 1068, 381, '1'),
(6685, 1067, 381, '1'),
(6686, 1060, 380, '1'),
(6687, 1061, 380, '1'),
(6688, 1062, 380, '1'),
(6689, 1063, 380, '1'),
(6690, 1064, 380, '1'),
(6691, 1065, 380, '1'),
(6692, 1066, 380, '1'),
(6693, 384, 241, 'working'),
(6694, 384, 286, '80'),
(6695, 393, 241, 'working'),
(6696, 393, 286, '62'),
(6697, 479, 241, 'done'),
(6698, 479, 286, '100'),
(6699, 481, 286, '100'),
(6700, 481, 241, 'done'),
(6701, 483, 241, 'done'),
(6702, 483, 286, '100'),
(6703, 485, 241, 'working'),
(6704, 485, 286, '75'),
(6705, 489, 286, '100'),
(6706, 489, 241, 'done'),
(6707, 491, 241, 'done'),
(6708, 491, 286, '100'),
(6709, 541, 286, '59'),
(6710, 541, 241, 'working'),
(6711, 493, 241, 'done'),
(6712, 493, 286, '100'),
(6713, 1072, 380, '1'),
(6714, 1072, 381, '1'),
(6715, 1072, 382, '1'),
(6716, 1072, 383, '1'),
(6717, 1074, 383, '1'),
(6718, 1074, 382, '1'),
(6719, 1074, 381, '0'),
(6720, 1074, 380, '0'),
(6721, 1073, 382, '0'),
(6722, 1073, 383, '0'),
(6723, 1073, 381, '0'),
(6724, 1073, 380, '0'),
(6725, 1078, 395, 'Painter'),
(6726, 1079, 395, 'Painter'),
(6727, 1080, 395, 'Painter'),
(6728, 1079, 381, '1'),
(6729, 1078, 381, '0'),
(6730, 1080, 381, '0'),
(6731, 1078, 380, '0'),
(6732, 1078, 379, '0'),
(6733, 1078, 378, '0'),
(6734, 1079, 378, '0'),
(6735, 1079, 379, '0'),
(6736, 1079, 380, '0'),
(6737, 1080, 380, '0'),
(6738, 1080, 379, '0'),
(6739, 1080, 378, '0'),
(6740, 1067, 382, '1'),
(6741, 1068, 382, '1'),
(6742, 1069, 382, '1'),
(6743, 1070, 382, '0'),
(6744, 1071, 382, '0'),
(6745, 1078, 382, '1'),
(6746, 1079, 382, '1'),
(6747, 1080, 382, '1'),
(6748, 1067, 383, '1'),
(6749, 1068, 383, '1'),
(6750, 1069, 383, '1'),
(6751, 1070, 383, '0'),
(6752, 1071, 383, '0'),
(6753, 1078, 383, '1'),
(6754, 1079, 383, '1'),
(6755, 1080, 383, '1'),
(6756, 1060, 381, '1'),
(6757, 1061, 381, '1'),
(6758, 1062, 381, '1'),
(6759, 1063, 381, '1'),
(6760, 1064, 381, '1'),
(6761, 1065, 381, '1'),
(6762, 1066, 381, '1'),
(6763, 1060, 382, '1'),
(6764, 1061, 382, '1'),
(6765, 1062, 382, '1'),
(6766, 1063, 382, '1'),
(6767, 1064, 382, '1'),
(6768, 1065, 382, '1'),
(6769, 1066, 382, '0'),
(6770, 1080, 393, '300'),
(6771, 1079, 393, '300'),
(6772, 1078, 393, '450'),
(6773, 1081, 273, 'Not Given '),
(6774, 1081, 274, 'Not Given '),
(6775, 1081, 272, '400.00'),
(6776, 1081, 275, '2026-01-25'),
(6777, 1081, 276, 'Tiekie Painter'),
(6778, 1081, 277, 'Paid'),
(6779, 1082, 327, '5000'),
(6780, 1083, 291, '1'),
(6781, 1083, 292, '0'),
(6782, 1060, 383, '1'),
(6783, 1061, 383, '1'),
(6784, 1062, 383, '1'),
(6785, 1063, 383, '1'),
(6786, 1064, 383, '1'),
(6787, 1065, 383, '1'),
(6788, 1066, 383, '0'),
(6789, 1060, 384, '1'),
(6790, 1061, 384, '1'),
(6791, 1062, 384, '1'),
(6792, 1063, 384, '1'),
(6793, 1064, 384, '1'),
(6794, 1065, 384, '1'),
(6795, 1066, 384, '1'),
(6796, 1060, 385, '1'),
(6797, 1066, 385, '0'),
(6798, 1065, 385, '1'),
(6799, 1064, 385, '1'),
(6800, 1063, 385, '1'),
(6801, 1062, 385, '1'),
(6802, 1061, 385, '1'),
(6803, 1060, 386, '1'),
(6804, 1061, 386, '1'),
(6805, 1062, 386, '1'),
(6806, 1063, 386, '1'),
(6807, 1064, 386, '1'),
(6808, 1065, 386, '0'),
(6809, 1066, 386, '0'),
(6810, 1060, 387, '1'),
(6811, 1064, 387, '1'),
(6812, 1062, 387, '1'),
(6813, 1061, 387, '0'),
(6814, 1063, 387, '0'),
(6815, 1065, 387, '0'),
(6816, 1066, 387, '0'),
(6817, 1067, 384, '1'),
(6818, 1068, 384, '1'),
(6819, 1069, 384, '1'),
(6820, 1078, 384, '1'),
(6821, 1079, 384, '1'),
(6822, 1080, 384, '1'),
(6823, 1071, 384, '0'),
(6824, 1070, 384, '0'),
(6825, 1067, 385, '1'),
(6826, 1068, 385, '1'),
(6827, 1069, 385, '1'),
(6828, 1070, 385, '0'),
(6829, 1071, 385, '0'),
(6830, 1078, 385, '1'),
(6831, 1079, 385, '1'),
(6832, 1080, 385, '1'),
(6833, 1067, 386, '0'),
(6834, 1068, 386, '0'),
(6835, 1069, 386, '0'),
(6836, 1070, 386, '0'),
(6837, 1071, 386, '0'),
(6838, 1078, 386, '0'),
(6839, 1079, 386, '0'),
(6840, 1080, 386, '0'),
(6841, 1069, 387, '1'),
(6842, 1067, 387, '1'),
(6843, 1068, 387, '0'),
(6844, 1070, 387, '0'),
(6845, 1071, 387, '0'),
(6846, 1078, 387, '0'),
(6847, 1079, 387, '1'),
(6848, 1080, 387, '1'),
(6849, 1072, 384, '1'),
(6850, 1072, 385, '0'),
(6851, 1072, 386, '0'),
(6852, 1072, 387, '1'),
(6853, 1073, 384, '0'),
(6854, 1073, 385, '0'),
(6855, 1074, 384, '0'),
(6856, 1074, 385, '0'),
(6857, 1074, 386, '0'),
(6858, 1074, 387, '0'),
(6859, 1073, 387, '0'),
(6860, 1073, 386, '0'),
(6861, 1084, 281, 'Not Given'),
(6862, 1084, 148, 'RIV134'),
(6863, 1084, 284, '1000.00'),
(6864, 1084, 282, '2026-01-19'),
(6865, 1084, 280, 'Garage'),
(6866, 1084, 283, 'Paid'),
(6867, 1084, 159, 'done'),
(6868, 1085, 281, 'Doc Nr B50190125143259'),
(6869, 1085, 148, 'RIV133'),
(6870, 1085, 284, '1440.00'),
(6871, 1085, 282, '2026-01-19'),
(6872, 1085, 280, 'Builders Warehouse '),
(6873, 1085, 283, 'Paid'),
(6874, 1085, 159, 'done'),
(6875, 1086, 281, 'Doc Nr B50200126143040'),
(6876, 1086, 148, 'RIV135'),
(6877, 1086, 284, '447.00'),
(6878, 1086, 282, '2026-01-20'),
(6879, 1086, 280, 'Builders Warehouse '),
(6880, 1086, 283, 'Paid'),
(6881, 1086, 159, 'done'),
(6882, 1087, 281, 'Doc Nr B50200126105041'),
(6883, 1087, 148, 'RIV136'),
(6884, 1087, 284, '99.00'),
(6885, 1087, 282, '2026-01-20'),
(6886, 1087, 280, 'Builders Warehouse'),
(6887, 1087, 283, 'Paid'),
(6888, 1087, 159, 'done'),
(6889, 1088, 281, 'Not Given '),
(6890, 1088, 148, 'RIV137'),
(6891, 1088, 284, '800.00'),
(6892, 1088, 282, '2026-01-21'),
(6893, 1088, 280, 'Garage'),
(6894, 1088, 283, 'Paid'),
(6895, 1088, 159, 'done'),
(6896, 1089, 281, 'Doc Nr B50210126902089'),
(6897, 1089, 282, '2026-01-21'),
(6898, 1089, 280, 'Builders Warehouse '),
(6899, 1089, 283, 'Paid'),
(6900, 1089, 159, 'done'),
(6901, 1089, 148, 'RIV138'),
(6902, 1089, 284, '60.00'),
(6903, 1090, 281, 'Not Given '),
(6904, 1090, 148, 'RIV139'),
(6905, 1090, 284, '978.00'),
(6906, 1090, 282, '2026-01-22'),
(6907, 1090, 280, 'Garage'),
(6908, 1090, 283, 'Paid'),
(6909, 1090, 159, 'done'),
(6910, 1091, 281, 'Doc Nr B50220126144010'),
(6911, 1091, 148, 'RIV140'),
(6912, 1091, 284, '258.00'),
(6913, 1091, 282, '2026-01-22'),
(6914, 1091, 280, 'Builders Warehouse '),
(6915, 1091, 283, 'Paid'),
(6916, 1091, 159, 'done'),
(6917, 1092, 281, 'Pro Forma '),
(6918, 1092, 148, 'RIV144'),
(6919, 1092, 284, '1288.80'),
(6920, 1092, 282, '2026-01-22'),
(6921, 1092, 280, 'Italtile'),
(6922, 1092, 283, 'Paid'),
(6923, 1092, 159, 'done'),
(6924, 1093, 281, 'Not Given '),
(6925, 1093, 148, 'RIV142'),
(6926, 1093, 284, '200.00'),
(6927, 1093, 282, '2026-01-23'),
(6928, 1093, 280, 'Garage'),
(6929, 1093, 283, 'Paid'),
(6930, 1093, 159, 'done'),
(6931, 1094, 281, 'Not Given '),
(6932, 1094, 148, 'RIV141'),
(6933, 1094, 284, '1000.00'),
(6934, 1094, 282, '2026-01-24'),
(6935, 1094, 280, 'Garage'),
(6936, 1094, 283, 'Paid'),
(6937, 1094, 159, 'done'),
(6938, 1095, 281, 'Doc Nr B50240126142025'),
(6939, 1095, 148, 'RIV143'),
(6940, 1095, 284, '833.50'),
(6941, 1095, 282, '2026-01-24'),
(6942, 1095, 280, 'Builders Warehouse '),
(6943, 1095, 283, 'Paid'),
(6944, 1095, 159, 'done'),
(6945, 1096, 281, 'Not Given'),
(6946, 1096, 148, 'RW014'),
(6947, 1096, 284, '1000.00'),
(6948, 1096, 282, '2026-01-25'),
(6949, 1097, 395, 'General Worker\r\n'),
(6950, 1097, 393, '200'),
(6952, 1097, 388, '1'),
(6953, 1098, 395, 'General worker'),
(6954, 1098, 388, '1'),
(6955, 1072, 388, '1'),
(6956, 1073, 388, '0'),
(6957, 1074, 388, '0'),
(6958, 1097, 384, '0'),
(6959, 1098, 384, '1'),
(6960, 1097, 378, '0'),
(6961, 1097, 379, '0'),
(6962, 1097, 380, '0'),
(6963, 1097, 381, '0'),
(6964, 1097, 382, '0'),
(6965, 1097, 383, '0'),
(6966, 1097, 385, '0'),
(6967, 1097, 387, '0'),
(6968, 1098, 383, '0'),
(6969, 1098, 378, '0'),
(6970, 1098, 379, '0'),
(6971, 1098, 380, '0'),
(6972, 1098, 381, '0'),
(6973, 1098, 382, '0'),
(6974, 1097, 386, '0'),
(6975, 1098, 386, '0'),
(6976, 1098, 387, '0'),
(6977, 1098, 385, '0'),
(6978, 1098, 393, '200'),
(6979, 1060, 396, '8.00'),
(6980, 1061, 396, '8.00'),
(6981, 1062, 396, '8.00'),
(6982, 1063, 396, '8.00'),
(6983, 1064, 396, '8.00'),
(6984, 1065, 396, '6.00'),
(6985, 1066, 396, '3.00'),
(6986, 1067, 396, '7.00'),
(6987, 1068, 396, '5.00'),
(6988, 1069, 396, '7.00'),
(6989, 1070, 396, '0.00'),
(6990, 1071, 396, '0.00'),
(6991, 1078, 396, '4.00'),
(6992, 1079, 396, '6.00'),
(6993, 1080, 396, '5.00'),
(6994, 1072, 396, '7.00'),
(6995, 1073, 396, '0.00'),
(6996, 1074, 396, '2.00'),
(6997, 1097, 396, '1.00'),
(6998, 1098, 396, '2.00'),
(6999, 1060, 397, '5520.00'),
(7000, 1061, 397, '3600.00'),
(7001, 1062, 397, '1760.00'),
(7002, 1063, 397, '1760.00'),
(7003, 1064, 397, '2000.00'),
(7004, 1065, 397, '1320.00'),
(7005, 1066, 397, '600.00'),
(7006, 1067, 397, '2100.00'),
(7007, 1068, 397, '1100.00'),
(7008, 1069, 397, '1540.00'),
(7009, 1070, 397, '0.00'),
(7010, 1071, 397, '0.00'),
(7011, 1078, 397, '1800.00'),
(7012, 1079, 397, '1800.00'),
(7013, 1080, 397, '1500.00'),
(7014, 1072, 397, '1400.00'),
(7015, 1073, 397, '0.00'),
(7016, 1074, 397, '400.00'),
(7017, 1097, 397, '200.00'),
(7018, 1098, 397, '400.00'),
(7019, 1099, 281, 'Not Given'),
(7020, 1099, 148, 'Not Given'),
(7021, 1099, 284, '27.00'),
(7022, 1099, 282, '2026-01-24'),
(7023, 1099, 280, 'N1 South Grasmere\r\n'),
(7024, 1099, 283, 'Paid'),
(7025, 1099, 159, 'done'),
(7026, 1100, 281, 'Not Given'),
(7027, 1100, 148, 'Not Given'),
(7028, 1100, 282, '2026-01-24'),
(7029, 1100, 284, '27.00'),
(7030, 1100, 283, 'Paid'),
(7031, 1100, 280, 'N1 South Grasmere'),
(7032, 1100, 159, 'done'),
(7033, 1096, 280, 'Traffic Department'),
(7034, 1096, 283, 'Paid'),
(7035, 1096, 159, 'done'),
(7036, 1101, 273, 'Not Given'),
(7037, 1101, 274, 'RW014'),
(7038, 1101, 272, '1000.00'),
(7039, 1101, 275, '2026-01-25'),
(7040, 1101, 276, 'Traffic Department'),
(7041, 1101, 277, 'Paid'),
(7042, 1102, 281, 'Not Given'),
(7043, 1102, 284, '27.00'),
(7044, 1102, 148, 'Not Given'),
(7045, 1102, 282, '2026-01-25'),
(7046, 1102, 280, 'N1 South Grasmere'),
(7047, 1102, 283, 'Paid'),
(7048, 1102, 159, 'done'),
(7049, 1103, 281, 'Not Given'),
(7050, 1103, 148, 'RIV145'),
(7051, 1103, 284, '1138.60'),
(7052, 1103, 282, '2026-01-26'),
(7053, 1103, 280, 'Garage'),
(7054, 1103, 283, 'Paid'),
(7055, 1103, 159, 'done'),
(7056, 1104, 282, '2026-01-26'),
(7057, 1104, 280, 'Stewards & Lloyds'),
(7058, 1104, 148, 'RIV149'),
(7059, 1105, 281, 'Not Given'),
(7060, 1105, 148, 'RIV148'),
(7061, 1105, 284, '959.92'),
(7062, 1105, 282, '2026-01-27'),
(7063, 1105, 280, 'DIY DEPOT'),
(7064, 1105, 283, 'Paid'),
(7065, 1105, 159, 'done'),
(7066, 1106, 281, 'Not Given'),
(7067, 1106, 148, 'RIV147'),
(7068, 1106, 284, '1000.00'),
(7069, 1106, 282, '2026-01-27'),
(7070, 1106, 280, 'Garage'),
(7071, 1106, 283, 'Paid'),
(7072, 1106, 159, 'done'),
(7073, 1108, 273, '014429'),
(7074, 1108, 274, 'WAT059'),
(7075, 1108, 272, '900.00'),
(7076, 1108, 275, '2026-01-27'),
(7077, 1108, 276, 'Multi Hardware'),
(7078, 1108, 277, 'Paid'),
(7079, 1109, 273, '29887'),
(7080, 1109, 274, 'WAT058 A'),
(7081, 1109, 272, '794.00'),
(7082, 1109, 275, '2026-01-26'),
(7083, 1109, 276, 'DIKM Projects Hardware '),
(7084, 1109, 277, 'Paid'),
(7085, 1067, 388, '1'),
(7086, 1068, 388, '1'),
(7087, 1069, 388, '1'),
(7088, 1070, 388, '0'),
(7089, 1071, 388, '0'),
(7090, 1110, 395, 'Plasterer'),
(7091, 1110, 387, '1'),
(7092, 1111, 387, '1'),
(7093, 1111, 388, '1'),
(7094, 1110, 388, '1'),
(7095, 1078, 388, '1'),
(7096, 1079, 388, '1'),
(7097, 1080, 388, '1'),
(7098, 1097, 389, '1'),
(7099, 1098, 389, '1'),
(7100, 1074, 389, '0'),
(7101, 1073, 389, '0'),
(7102, 1072, 389, '1'),
(7103, 1110, 386, '0'),
(7104, 1111, 386, '0'),
(7105, 1110, 385, '0'),
(7106, 1110, 384, '0'),
(7107, 1110, 383, '0'),
(7108, 1110, 382, '0'),
(7109, 1110, 381, '0'),
(7110, 1110, 380, '0'),
(7111, 1110, 379, '0'),
(7112, 1110, 378, '0'),
(7113, 1111, 378, '0'),
(7114, 1111, 379, '0'),
(7115, 1111, 380, '0'),
(7116, 1111, 381, '0'),
(7117, 1111, 382, '0'),
(7118, 1111, 383, '0'),
(7119, 1111, 384, '0'),
(7120, 1111, 385, '0'),
(7121, 1060, 388, '1'),
(7122, 1061, 388, '0'),
(7123, 1062, 388, '1'),
(7124, 1063, 388, '0'),
(7125, 1064, 388, '1'),
(7126, 1065, 388, '1'),
(7127, 1066, 388, '1'),
(7128, 358, 278, '50'),
(7129, 358, 147, 'working'),
(7130, 364, 278, '100'),
(7131, 365, 278, '50'),
(7132, 326, 147, 'done'),
(7133, 353, 147, 'working'),
(7134, 1112, 389, '1'),
(7135, 1113, 389, '1'),
(7136, 1066, 389, '1'),
(7137, 1065, 389, '1'),
(7138, 1064, 389, '1'),
(7139, 1063, 389, '0'),
(7140, 1062, 389, '1'),
(7141, 1061, 389, '0'),
(7142, 1060, 389, '1'),
(7143, 1060, 390, '1'),
(7144, 1061, 390, '0'),
(7145, 1062, 390, '1'),
(7146, 1063, 390, '0'),
(7147, 1064, 390, '1'),
(7148, 1065, 390, '1'),
(7149, 1066, 390, '1'),
(7150, 1112, 390, '1'),
(7151, 1113, 390, '1'),
(7152, 1113, 391, '1'),
(7153, 1112, 391, '1'),
(7154, 1066, 391, '1'),
(7155, 1065, 391, '1'),
(7156, 1064, 391, '1'),
(7157, 1063, 391, '0'),
(7158, 1062, 391, '1'),
(7159, 1061, 391, '0'),
(7160, 1060, 391, '1'),
(7161, 1067, 389, '1'),
(7162, 1068, 389, '1'),
(7163, 1069, 389, '1'),
(7164, 1070, 389, '0'),
(7165, 1071, 389, '0'),
(7166, 1078, 389, '1'),
(7167, 1079, 389, '0'),
(7168, 1080, 389, '1'),
(7169, 1110, 389, '1'),
(7170, 1111, 389, '1'),
(7171, 1068, 390, '1'),
(7172, 1069, 390, '1'),
(7173, 1070, 390, '0'),
(7174, 1071, 390, '0'),
(7175, 1078, 390, '1'),
(7176, 1079, 390, '0'),
(7177, 1080, 390, '1'),
(7178, 1110, 390, '1'),
(7179, 1111, 390, '1'),
(7180, 1067, 390, '1'),
(7181, 1072, 390, '0'),
(7182, 1073, 390, '0'),
(7183, 1074, 390, '0'),
(7184, 1097, 390, '0'),
(7185, 1098, 390, '0'),
(7186, 1067, 391, '1'),
(7187, 1068, 391, '1'),
(7188, 1069, 391, '1'),
(7189, 1070, 391, '0'),
(7190, 1071, 391, '0'),
(7191, 1078, 391, '1'),
(7192, 1079, 391, '0'),
(7193, 1080, 391, '1'),
(7194, 1110, 391, '1'),
(7195, 1111, 391, '1'),
(7196, 1112, 393, '300'),
(7197, 1110, 393, '450'),
(7198, 1111, 393, '220'),
(7199, 1113, 395, 'Painter'),
(7200, 1112, 395, 'Painter'),
(7201, 1113, 393, '300'),
(7202, 1114, 281, 'Doc Nr B50280126143062'),
(7203, 1114, 148, 'RIV150'),
(7204, 1114, 284, '637.19'),
(7205, 1114, 282, '2026-01-28'),
(7206, 1114, 280, 'Builders Warehouse'),
(7207, 1114, 283, 'Paid'),
(7208, 1114, 159, 'done'),
(7209, 1097, 391, '1'),
(7210, 1115, 281, 'Doc Nr 2135575793'),
(7211, 1115, 148, 'RIV151'),
(7212, 1115, 284, '21845.00'),
(7213, 1115, 282, '2026-01-28'),
(7214, 1115, 280, 'CTM'),
(7215, 1115, 283, 'Paid'),
(7216, 1115, 159, 'done'),
(7217, 1116, 281, 'Not Given '),
(7218, 1116, 148, 'Not Given '),
(7219, 1116, 284, '3770.00'),
(7220, 1116, 282, '2026-01-28'),
(7221, 1116, 280, 'BM Moyo'),
(7222, 1116, 283, 'Paid'),
(7223, 1116, 159, 'done'),
(7224, 1117, 281, 'Doc Nr B50290126141024'),
(7225, 1117, 148, 'RIV152'),
(7226, 1117, 284, '2157.00'),
(7227, 1117, 282, '2026-01-29'),
(7228, 1117, 280, 'Builders Warehouse'),
(7229, 1117, 283, 'Paid'),
(7230, 1117, 159, 'done'),
(7231, 1118, 281, 'Not Given'),
(7232, 1118, 148, 'RIV153'),
(7233, 1118, 284, '189.75'),
(7234, 1118, 282, '2026-01-29'),
(7235, 1118, 280, 'AGW KYA Sands'),
(7236, 1118, 283, 'Paid'),
(7237, 1118, 159, 'done'),
(7238, 1119, 281, 'Doc nr B50290126142059'),
(7239, 1119, 148, 'RIV154'),
(7240, 1119, 284, '795.65'),
(7241, 1119, 282, '2026-01-29'),
(7242, 1119, 280, 'Builders Warehouse'),
(7243, 1119, 283, 'Paid'),
(7244, 1119, 159, 'done'),
(7245, 1120, 281, 'Not Given '),
(7246, 1120, 148, 'RIV154 B'),
(7247, 1120, 284, '100.00'),
(7248, 1120, 282, '2026-01-29'),
(7249, 1120, 280, 'Garage'),
(7250, 1120, 283, 'Paid'),
(7251, 1120, 159, 'done'),
(7252, 1121, 281, 'Not Given '),
(7253, 1121, 148, 'Not Given '),
(7254, 1121, 284, '1000.00'),
(7255, 1121, 282, '2026-01-29'),
(7256, 1121, 280, 'Alfred Electrician '),
(7257, 1121, 283, 'Paid'),
(7258, 1121, 159, 'done'),
(7259, 1122, 281, 'Doc Nr B50300126144027'),
(7260, 1122, 148, 'RIV156'),
(7261, 1122, 284, '316.20'),
(7262, 1122, 282, '2026-01-30'),
(7263, 1122, 280, 'Builders Warehouse'),
(7264, 1122, 283, 'Paid'),
(7265, 1122, 159, 'done'),
(7266, 1123, 281, 'Not Given'),
(7267, 1123, 148, 'RIV157'),
(7268, 1123, 284, '1613.86'),
(7269, 1123, 282, '2026-01-30'),
(7270, 1123, 280, 'DIY Depot'),
(7271, 1123, 283, 'Paid'),
(7272, 1123, 159, 'done'),
(7273, 1124, 281, 'Doc nr 285959'),
(7274, 1124, 148, 'RIV158 A'),
(7275, 1124, 284, '1831.25'),
(7276, 1124, 282, '2026-01-30'),
(7277, 1124, 280, 'Mica'),
(7278, 1124, 283, 'Paid'),
(7279, 1124, 159, 'done'),
(7280, 1125, 281, 'Doc Nr 2135598083'),
(7281, 1125, 148, 'RIV158 B'),
(7282, 1125, 284, '2542.40'),
(7283, 1125, 282, '2026-01-30'),
(7284, 1125, 280, 'CTM'),
(7285, 1125, 159, 'done'),
(7286, 1125, 283, 'Paid'),
(7287, 1126, 281, 'Not Given'),
(7288, 1126, 148, 'RIV159'),
(7289, 1126, 284, '1000.00'),
(7290, 1126, 282, '2026-01-31'),
(7291, 1126, 280, 'Garage'),
(7292, 1126, 283, 'Paid'),
(7293, 1126, 159, 'done'),
(7294, 1127, 281, 'Doc Nr B50310126143013'),
(7295, 1127, 148, 'RIV160'),
(7296, 1127, 284, '1536.50'),
(7297, 1127, 282, '2026-01-31'),
(7298, 1127, 280, 'Builders Warehouse'),
(7299, 1127, 283, 'Paid'),
(7300, 1127, 159, 'done'),
(7301, 1128, 281, 'QUO01838727'),
(7302, 1128, 148, 'RIV161'),
(7303, 1128, 284, '7924.17'),
(7304, 1128, 282, '2026-01-31'),
(7305, 1128, 280, 'Thetotumiso Innovations'),
(7306, 1128, 283, 'Paid'),
(7307, 1128, 159, 'done'),
(7308, 1129, 281, 'Doc Nr B50310126142030'),
(7309, 1129, 148, 'RIV162'),
(7310, 1129, 284, '1753.20'),
(7311, 1129, 282, '2026-01-31'),
(7312, 1129, 280, 'Builders Warehouse'),
(7313, 1129, 283, 'Paid'),
(7314, 1129, 159, 'done'),
(7315, 1131, 281, 'QTE 00015185'),
(7316, 1131, 148, 'RIV164 A'),
(7317, 1130, 148, 'RIV163'),
(7318, 1131, 284, '2906.00'),
(7319, 1131, 282, '2026-01-31'),
(7320, 1131, 280, 'Mixed Trees Trading'),
(7321, 1131, 283, 'Paid'),
(7322, 1131, 159, 'done'),
(7323, 1132, 281, 'Doc Nr B50310126101155'),
(7324, 1132, 148, 'RIV164 B'),
(7325, 1132, 284, '617.00'),
(7326, 1132, 282, '2026-01-31'),
(7327, 1130, 282, '2026-01-31'),
(7328, 1132, 280, 'Builders Warehouse'),
(7329, 1132, 283, 'Paid'),
(7330, 1132, 159, 'done'),
(7331, 1133, 281, 'Doc Nr B50310126133169'),
(7332, 1133, 148, 'RIV165'),
(7333, 1133, 284, '228.00'),
(7334, 1133, 282, '2026-01-31'),
(7335, 1133, 280, 'Builders Warehouse'),
(7336, 1133, 283, 'Paid'),
(7337, 1133, 159, 'done'),
(7338, 1134, 281, 'Not Given'),
(7339, 1134, 148, 'Not Given'),
(7340, 1134, 284, '2865.00'),
(7341, 1134, 282, '2026-01-31'),
(7342, 1134, 280, 'Concreto'),
(7343, 1134, 283, 'Paid'),
(7344, 1134, 159, 'done'),
(7345, 1135, 281, 'Not Given '),
(7346, 1135, 148, 'Not Given '),
(7347, 1135, 284, '1000.00'),
(7348, 1135, 282, '2025-12-31'),
(7349, 1135, 280, 'Tiler'),
(7350, 1135, 283, 'Paid'),
(7351, 1135, 159, 'done'),
(7352, 1136, 281, 'Not Given'),
(7353, 1136, 148, 'Not Given'),
(7354, 1136, 284, '800.00'),
(7355, 1136, 282, '2026-01-31'),
(7356, 1136, 280, 'Thabang Bricklayer'),
(7357, 1136, 283, 'Paid'),
(7358, 1136, 159, 'done'),
(7359, 1137, 281, 'not Given '),
(7360, 1137, 148, 'Not Given '),
(7361, 1137, 284, '1270.00'),
(7362, 1137, 282, '2026-01-31'),
(7363, 1137, 280, 'stanly'),
(7364, 1137, 283, 'Paid'),
(7365, 1137, 159, 'done'),
(7366, 1139, 281, 'Doc Nr B50010226143003'),
(7367, 1139, 148, 'RIV166'),
(7368, 1139, 284, '538.00'),
(7369, 1139, 282, '2026-02-01'),
(7370, 1139, 280, 'Builders Warehouse'),
(7371, 1139, 283, 'Paid'),
(7372, 1139, 159, 'done'),
(7373, 1140, 281, 'Not Given '),
(7374, 1140, 148, 'RIV167'),
(7375, 1140, 284, '1000.00'),
(7376, 1140, 282, '2026-02-01'),
(7377, 1140, 283, 'Paid'),
(7378, 1140, 159, 'done'),
(7379, 1140, 280, 'Garage'),
(7380, 1141, 281, 'Doc Nr B50020226141036'),
(7381, 1141, 148, 'RIV168'),
(7382, 1141, 284, '932.00'),
(7383, 1141, 282, '2026-02-02'),
(7384, 1141, 280, 'Builders Warehouse'),
(7385, 1141, 283, 'Paid'),
(7386, 1141, 159, 'done'),
(7387, 1142, 281, 'Doc Nr B50020226142086'),
(7388, 1142, 148, 'RIV169'),
(7389, 1142, 284, '810.50'),
(7390, 1142, 282, '2026-02-02'),
(7391, 1142, 280, 'Builders Warehouse'),
(7392, 1142, 283, 'Paid'),
(7393, 1142, 159, 'done'),
(7394, 1143, 281, 'Not Given '),
(7395, 1143, 148, 'Not Given '),
(7396, 1143, 284, '5000.00'),
(7397, 1143, 282, '2026-02-02'),
(7398, 1143, 280, 'Tiler Jim Maluleke'),
(7399, 1143, 283, 'Paid'),
(7400, 1143, 159, 'done'),
(7401, 1144, 281, 'Not Given'),
(7402, 1144, 148, 'Not Given'),
(7403, 1144, 284, '800.00'),
(7404, 1144, 282, '2026-02-02'),
(7405, 1144, 280, 'Thabo'),
(7406, 1144, 283, 'Paid'),
(7407, 1144, 159, 'done'),
(7408, 1145, 281, 'Not Given '),
(7409, 1145, 148, 'RIV170'),
(7410, 1145, 284, '1000.00'),
(7411, 1145, 282, '2026-02-03'),
(7412, 1145, 280, 'Garage'),
(7413, 1145, 283, 'Paid'),
(7414, 1145, 159, 'done'),
(7415, 1146, 281, 'Not Given '),
(7416, 1146, 148, 'Not Given '),
(7417, 1146, 284, '27080.00'),
(7418, 1146, 282, '2026-01-30'),
(7419, 1146, 280, 'Concreto Workers'),
(7420, 1146, 283, 'Paid'),
(7421, 1146, 159, 'done'),
(7422, 1147, 273, 'Not Given '),
(7423, 1147, 274, 'Not Given '),
(7424, 1147, 272, '16300.00'),
(7425, 1147, 275, '2026-01-30'),
(7426, 1147, 276, 'Concreto'),
(7427, 1147, 277, 'Paid'),
(7428, 1148, 291, '1'),
(7429, 1148, 292, '0'),
(7430, 1149, 291, '1'),
(7431, 1149, 292, '170000'),
(7432, 1151, 410, '1'),
(7433, 1151, 417, '3'),
(7434, 1151, 418, '1'),
(7435, 1151, 425, '100'),
(7436, 1151, 426, '0.00'),
(7437, 1152, 424, 'Foremen'),
(7438, 1153, 424, 'General Worker'),
(7439, 1154, 424, 'General Worker'),
(7440, 1155, 424, 'General Worker'),
(7441, 1156, 424, 'Painter'),
(7442, 1157, 424, 'General Worker'),
(7443, 1158, 424, 'General Worker'),
(7444, 1159, 424, 'General Worker'),
(7445, 1160, 424, 'Plasterer'),
(7446, 1161, 424, 'General Worker'),
(7447, 1152, 425, '690'),
(7448, 1160, 425, '450'),
(7449, 1153, 425, '300'),
(7450, 1154, 425, '220'),
(7451, 1155, 425, '220'),
(7452, 1161, 425, '220'),
(7453, 1156, 425, '300'),
(7454, 1157, 425, '330'),
(7455, 1158, 425, '220'),
(7456, 1159, 425, '300'),
(7457, 1152, 409, '1'),
(7458, 1153, 409, '1'),
(7459, 1154, 409, '1'),
(7460, 1155, 409, '1'),
(7461, 1156, 409, '1'),
(7462, 1157, 409, '1.4'),
(7463, 1158, 409, '1'),
(7464, 1159, 409, '1'),
(7465, 1160, 409, '1'),
(7466, 1161, 409, '1'),
(7467, 1152, 410, '1'),
(7468, 1153, 410, '1'),
(7469, 1154, 410, '0'),
(7470, 1155, 410, '1'),
(7471, 1156, 410, '1'),
(7472, 1157, 410, '1.3'),
(7473, 1158, 410, '1'),
(7474, 1159, 410, '1'),
(7475, 1160, 410, '1'),
(7476, 1161, 410, '1'),
(7477, 1152, 411, '1'),
(7478, 1153, 411, '1'),
(7479, 1154, 411, '1'),
(7480, 1161, 411, '1'),
(7481, 1160, 411, '0'),
(7482, 1159, 411, '0'),
(7483, 1158, 411, '0'),
(7484, 1157, 411, '0'),
(7485, 1156, 411, '0'),
(7486, 1155, 411, '0'),
(7487, 1152, 412, '1'),
(7488, 1153, 412, '1'),
(7489, 1154, 412, '1'),
(7490, 1155, 412, '1'),
(7491, 1156, 412, '1'),
(7492, 1157, 412, '0'),
(7493, 1158, 412, '0'),
(7494, 1159, 412, '0'),
(7495, 1160, 412, '0'),
(7496, 1161, 412, '0'),
(7497, 1152, 413, '1'),
(7498, 1153, 413, '1'),
(7499, 1154, 413, '1'),
(7500, 1155, 413, '1'),
(7501, 1156, 413, '1'),
(7502, 1160, 413, '1'),
(7503, 1161, 413, '1'),
(7504, 1157, 413, '0'),
(7505, 1158, 413, '0'),
(7506, 1159, 413, '0'),
(7507, 1162, 425, '330'),
(7508, 1162, 424, 'General Worker'),
(7509, 1163, 424, 'Painter'),
(7510, 1163, 425, '300'),
(7511, 1164, 424, 'Plasterer'),
(7512, 1164, 425, '450'),
(7513, 1165, 424, 'General Worker'),
(7514, 1165, 425, '220'),
(7515, 1162, 409, '0'),
(7516, 1163, 409, '0'),
(7517, 1164, 409, '0'),
(7518, 1165, 409, '0'),
(7519, 1162, 410, '0'),
(7520, 1163, 410, '0'),
(7521, 1164, 410, '0'),
(7522, 1165, 410, '0'),
(7523, 1162, 411, '1'),
(7524, 1163, 411, '1'),
(7525, 1164, 411, '1'),
(7526, 1165, 411, '1'),
(7527, 1166, 424, 'General Worker'),
(7528, 1167, 424, 'General Worker'),
(7529, 1166, 425, '220'),
(7530, 1167, 425, '220'),
(7531, 1166, 409, '0'),
(7532, 1166, 410, '0'),
(7533, 1167, 410, '0'),
(7534, 1167, 409, '0'),
(7535, 1166, 411, '1'),
(7536, 1167, 411, '1'),
(7537, 1162, 412, '1'),
(7538, 1163, 412, '1'),
(7539, 1164, 412, '1'),
(7540, 1165, 412, '0'),
(7541, 1168, 424, 'General Worker'),
(7542, 1168, 409, '0'),
(7543, 1168, 410, '0'),
(7544, 1168, 411, '0'),
(7545, 1168, 412, '1'),
(7546, 1167, 412, '1'),
(7547, 1166, 412, '1'),
(7548, 1168, 425, '220'),
(7549, 1162, 413, '1'),
(7550, 1163, 413, '1'),
(7551, 1164, 413, '0'),
(7552, 1165, 413, '0'),
(7553, 1166, 413, '1'),
(7554, 1167, 413, '0'),
(7555, 1168, 413, '0'),
(7556, 1169, 424, 'Cleaning Lady'),
(7557, 1169, 425, '200'),
(7558, 1169, 409, '0'),
(7559, 1169, 410, '0'),
(7560, 1169, 411, '1'),
(7561, 1169, 412, '1'),
(7562, 1169, 413, '1'),
(7563, 1169, 414, '1'),
(7564, 1162, 414, '1'),
(7565, 1163, 414, '1'),
(7566, 1164, 414, '1'),
(7567, 1165, 414, '0'),
(7568, 1166, 414, '1'),
(7569, 1167, 414, '1'),
(7570, 1168, 414, '1'),
(7571, 1170, 424, 'General Worker'),
(7572, 1170, 425, '220'),
(7573, 1170, 414, '1'),
(7574, 1170, 413, '0'),
(7575, 1170, 412, '0'),
(7576, 1170, 411, '0'),
(7577, 1170, 410, '0'),
(7578, 1170, 409, '0'),
(7579, 832, 292, '0'),
(7580, 1171, 273, 'Not Given '),
(7581, 1171, 274, 'Not Given '),
(7582, 1171, 272, '400.00'),
(7583, 1171, 275, '2026-02-02'),
(7584, 1171, 276, 'Winston Kotze '),
(7585, 1171, 277, 'Paid'),
(7586, 1172, 273, 'Not Given '),
(7587, 1172, 274, 'Not Given '),
(7588, 1172, 272, '400.00'),
(7589, 1172, 275, '2026-02-02'),
(7590, 1172, 276, 'Winston Kotze '),
(7591, 1172, 277, 'Paid'),
(7592, 1173, 272, '5000.00'),
(7593, 1173, 276, 'Winston Kotze '),
(7594, 1173, 277, 'Paid'),
(7595, 1173, 273, 'Not Given '),
(7596, 1173, 274, 'Not Given '),
(7597, 1173, 275, '2026-01-31'),
(7598, 1152, 414, '1'),
(7599, 1153, 414, '1'),
(7600, 1154, 414, '1'),
(7601, 1155, 414, '1'),
(7602, 1156, 414, '1'),
(7603, 1157, 414, '0'),
(7604, 1158, 414, '0'),
(7605, 1159, 414, '0'),
(7606, 1160, 414, '0'),
(7607, 1161, 414, '0'),
(7608, 1152, 415, '1'),
(7609, 1153, 415, '1'),
(7610, 1154, 415, '1'),
(7611, 1155, 415, '0'),
(7612, 1156, 415, '1'),
(7613, 1158, 415, '0'),
(7614, 1157, 415, '0'),
(7615, 1159, 415, '0'),
(7616, 1160, 415, '0'),
(7617, 1161, 415, '0'),
(7618, 1162, 415, '1'),
(7619, 1174, 424, 'Painter'),
(7620, 1174, 415, '1'),
(7621, 1175, 424, 'Painter'),
(7622, 1175, 415, '1'),
(7623, 1168, 415, '1'),
(7624, 1170, 415, '1'),
(7625, 1164, 415, '1'),
(7626, 1163, 415, '1'),
(7627, 1167, 415, '1'),
(7628, 1166, 415, '1'),
(7629, 1165, 415, '0'),
(7630, 1174, 409, '0'),
(7631, 1174, 410, '0'),
(7632, 1174, 411, '0'),
(7633, 1174, 412, '0'),
(7634, 1174, 413, '0'),
(7635, 1174, 414, '0'),
(7636, 1175, 414, '0'),
(7637, 1175, 413, '0'),
(7638, 1175, 412, '0'),
(7639, 1175, 411, '0'),
(7640, 1175, 410, '0'),
(7641, 1175, 409, '0'),
(7642, 1162, 416, '1'),
(7643, 1175, 416, '1'),
(7644, 1174, 416, '1'),
(7645, 1163, 416, '1'),
(7646, 1168, 416, '1'),
(7647, 1166, 416, '1'),
(7648, 1167, 416, '1'),
(7649, 1164, 416, '0'),
(7650, 1165, 416, '0'),
(7651, 1170, 416, '0'),
(7652, 1169, 415, '1'),
(7653, 1169, 416, '1'),
(7654, 1169, 417, '0'),
(7655, 1175, 425, '300'),
(7656, 1174, 425, '450'),
(7657, 1176, 281, 'Doc Nr B50040226143025'),
(7658, 1176, 148, 'RIV171 A'),
(7659, 1176, 284, '2043.50'),
(7660, 1176, 282, '2026-02-04'),
(7661, 1176, 280, 'Builders Warehouse'),
(7662, 1176, 283, 'Paid'),
(7663, 1176, 159, 'done'),
(7664, 1177, 281, 'Doc Nr B50030226141092'),
(7665, 1177, 148, 'RIV171 B'),
(7666, 1177, 284, '220.00'),
(7667, 1177, 282, '2026-02-03'),
(7668, 1177, 280, 'Builders Warehouse'),
(7669, 1177, 283, 'Paid'),
(7670, 1177, 159, 'done'),
(7671, 1178, 281, 'Doc Nr B50030226133029'),
(7672, 1178, 148, 'RIV171 C'),
(7673, 1178, 284, '110.00'),
(7674, 1178, 282, '2026-02-04'),
(7675, 1178, 280, 'Builders Warehouse'),
(7676, 1178, 283, 'Paid'),
(7677, 1178, 159, 'done'),
(7678, 1179, 281, 'INV 8212768'),
(7679, 1179, 148, 'RIV172'),
(7680, 1179, 284, '692.35'),
(7681, 1179, 282, '2026-02-04'),
(7682, 1179, 280, 'Stewards & Lloyds'),
(7683, 1179, 283, 'Paid'),
(7684, 1179, 159, 'done'),
(7685, 1180, 281, 'QUO01838748'),
(7686, 1180, 148, 'RIV173'),
(7687, 1180, 284, '549.70'),
(7688, 1180, 282, '2026-02-04'),
(7689, 1180, 280, 'Thetotumiso Innovations'),
(7690, 1180, 283, 'Paid'),
(7691, 1180, 159, 'done'),
(7692, 1181, 281, 'Not given'),
(7693, 1181, 148, 'Not Given'),
(7694, 1181, 284, '1200.00'),
(7695, 1181, 282, '2026-02-04'),
(7696, 1181, 280, 'Electritian (Godwin)'),
(7697, 1181, 283, 'Paid'),
(7698, 1181, 159, 'done'),
(7699, 1182, 281, 'Not Given'),
(7700, 1182, 148, 'Not Given'),
(7701, 1182, 284, '400.00'),
(7702, 1182, 282, '2026-02-04'),
(7703, 1182, 280, 'Paving guy'),
(7704, 1182, 283, 'Paid'),
(7705, 1182, 159, 'done'),
(7706, 1183, 281, 'Not Given'),
(7707, 1183, 148, 'RIV174'),
(7708, 1183, 284, '800.00'),
(7709, 1183, 282, '2026-02-04'),
(7710, 1183, 280, 'Garage'),
(7711, 1183, 159, 'done'),
(7712, 1183, 283, 'Paid'),
(7713, 1184, 281, 'Doc Nr B50050226107066'),
(7714, 1184, 148, 'RIV175'),
(7715, 1184, 284, '224.00'),
(7716, 1184, 282, '2026-02-05'),
(7717, 1184, 280, 'Builders Warehouse'),
(7718, 1184, 283, 'Paid'),
(7719, 1184, 159, 'done'),
(7720, 1185, 281, 'Not Given'),
(7721, 1185, 148, 'RIV176'),
(7722, 1185, 284, '1000.00'),
(7723, 1185, 282, '2026-02-05'),
(7724, 1185, 280, 'garage'),
(7725, 1185, 283, 'Paid'),
(7726, 1185, 159, 'done'),
(7727, 1186, 148, 'RIV177'),
(7728, 1187, 148, 'RIV178'),
(7729, 1187, 282, '2026-02-06'),
(7730, 1186, 282, '2026-02-06'),
(7731, 1188, 281, 'QTE 664317'),
(7732, 1188, 148, 'RIV178'),
(7733, 1188, 284, '8933.00'),
(7734, 1188, 282, '2026-02-06'),
(7735, 1188, 280, 'Atchem Holdings'),
(7736, 1188, 283, 'Paid'),
(7737, 1188, 159, 'done'),
(7738, 1189, 281, 'QTE 118705091'),
(7739, 1189, 148, 'RIV180'),
(7740, 1189, 284, '1519.20'),
(7741, 1189, 282, '2026-02-06'),
(7742, 1189, 280, 'CTM'),
(7743, 1189, 283, 'Paid'),
(7744, 1189, 159, 'done'),
(7745, 1190, 281, 'Not Given'),
(7746, 1190, 148, 'RIV181'),
(7747, 1190, 284, '10264.00'),
(7748, 1190, 282, '2026-02-06'),
(7749, 1190, 280, 'Stewards & Lloyds'),
(7750, 1190, 283, 'Paid'),
(7751, 1190, 159, 'done'),
(7752, 1191, 281, 'Not Given'),
(7753, 1191, 148, 'RIV182'),
(7754, 1191, 284, '11224.00'),
(7755, 1191, 282, '2026-02-06'),
(7756, 1191, 280, 'Thetotumiso Innovations'),
(7757, 1191, 283, 'Paid'),
(7758, 1191, 159, 'done'),
(7759, 1192, 280, 'Coastel Hire'),
(7760, 1192, 281, 'Not Given'),
(7761, 1192, 148, 'Not Given'),
(7762, 1192, 284, '6133.99'),
(7763, 1192, 282, '2026-02-07'),
(7764, 1192, 283, 'Paid'),
(7765, 1192, 159, 'done'),
(7766, 1193, 281, 'Not Given'),
(7767, 1193, 148, 'RIV183'),
(7768, 1193, 284, '349.00'),
(7769, 1193, 282, '2026-02-07'),
(7770, 1193, 280, 'Mr Smart Lighting Company'),
(7771, 1193, 283, 'Paid'),
(7772, 1193, 159, 'done'),
(7773, 1194, 281, 'Doc Nr B50070226101105'),
(7774, 1194, 148, 'RIV184'),
(7775, 1194, 282, '2026-02-07'),
(7776, 1194, 284, '14994.50'),
(7777, 1194, 280, 'Builders Warehouse'),
(7778, 1194, 283, 'Paid'),
(7779, 1194, 159, 'done'),
(7780, 1195, 148, 'RIV185'),
(7781, 1195, 281, 'Doc Nr 226144068'),
(7782, 1195, 284, '232.00'),
(7783, 1195, 282, '2026-02-07'),
(7784, 1195, 280, 'Builders Warehouse'),
(7785, 1195, 283, 'Paid'),
(7786, 1195, 159, 'done'),
(7787, 1196, 281, 'Not Given'),
(7788, 1196, 148, 'RIV187'),
(7789, 1196, 284, '1500.00'),
(7790, 1196, 282, '2026-02-07'),
(7791, 1196, 280, 'Garage'),
(7792, 1196, 283, 'Paid'),
(7793, 1196, 159, 'done'),
(7794, 1197, 281, 'Not given'),
(7795, 1197, 148, 'Not Given'),
(7796, 1197, 284, '2750.00'),
(7797, 1197, 282, '2026-02-07'),
(7798, 1197, 280, 'Electritian (Godwin)'),
(7799, 1197, 283, 'Paid'),
(7800, 1197, 159, 'done'),
(7801, 1198, 148, 'RIV188'),
(7802, 1198, 282, '2026-02-07'),
(7803, 1199, 281, 'Doc  Nr B50066226101028'),
(7804, 1199, 148, 'RIV189'),
(7805, 1199, 284, '6743.00'),
(7806, 1199, 282, '2026-02-08'),
(7807, 1199, 280, 'Builders Warehouse'),
(7808, 1199, 283, 'Paid'),
(7809, 1199, 159, 'done'),
(7810, 1200, 281, 'Doc Nr 2135674903'),
(7811, 1200, 148, 'RIV191'),
(7812, 1200, 284, '644.40'),
(7813, 1200, 282, '2026-02-08'),
(7814, 1200, 280, 'Italtile'),
(7815, 1200, 283, 'Paid'),
(7816, 1200, 159, 'done'),
(7817, 1201, 148, 'RIV190'),
(7818, 1202, 281, 'Doc Nr B50080226106010'),
(7819, 1202, 148, 'RIV192'),
(7820, 1202, 284, '1308.50'),
(7821, 1202, 282, '2026-02-08'),
(7822, 1202, 280, 'Builders Warehouse'),
(7823, 1202, 283, 'Paid'),
(7824, 1202, 159, 'done'),
(7825, 1203, 281, 'INV 30'),
(7826, 1203, 148, 'RIV194'),
(7827, 1203, 284, '19400.00'),
(7828, 1203, 159, 'done'),
(7829, 1203, 283, 'Paid'),
(7830, 1203, 280, 'V M Projects'),
(7831, 1203, 282, '2026-02-05'),
(7832, 1204, 284, '1200.00'),
(7833, 1204, 283, 'Paid'),
(7834, 1204, 159, 'done'),
(7835, 1204, 282, '2026-02-05'),
(7836, 1204, 280, 'Tiler'),
(7837, 1204, 148, 'Not given'),
(7838, 1204, 281, 'Not Given'),
(7839, 1152, 416, '1'),
(7840, 1153, 416, '1'),
(7841, 1154, 416, '1'),
(7842, 1155, 416, '1'),
(7843, 1156, 416, '1'),
(7844, 1157, 416, '1.2'),
(7845, 1158, 416, '0'),
(7846, 1159, 416, '0'),
(7847, 1205, 416, '1'),
(7848, 1160, 416, '1'),
(7849, 1161, 416, '1'),
(7850, 1206, 416, '0'),
(7851, 1207, 416, '0'),
(7852, 1208, 416, '1'),
(7853, 1209, 416, '1'),
(7854, 1206, 424, 'Painter'),
(7855, 1207, 424, 'Painter'),
(7856, 1206, 425, '450'),
(7857, 1207, 425, '300'),
(7858, 1152, 417, '1'),
(7859, 1153, 417, '1'),
(7860, 1154, 417, '1'),
(7861, 1155, 417, '1'),
(7862, 1156, 417, '1'),
(7863, 1157, 417, '1.4'),
(7864, 1158, 417, '1'),
(7865, 1159, 417, '1'),
(7866, 1160, 417, '1'),
(7867, 1161, 417, '1'),
(7868, 1205, 417, '1'),
(7869, 1206, 417, '1'),
(7870, 1207, 417, '1'),
(7871, 1208, 417, '1'),
(7872, 1209, 417, '1'),
(7874, 1152, 418, '1'),
(7875, 1153, 418, '1'),
(7876, 1154, 418, '1'),
(7877, 1155, 418, '1'),
(7878, 1156, 418, '1'),
(7879, 1157, 418, '1'),
(7880, 1158, 418, '1'),
(7881, 1159, 418, '0'),
(7882, 1160, 418, '1'),
(7883, 1161, 418, '1'),
(7884, 1205, 418, '1'),
(7885, 1206, 418, '0'),
(7886, 1207, 418, '0'),
(7887, 1208, 418, '0'),
(7888, 1209, 418, '0'),
(7889, 1210, 418, '1'),
(7890, 1210, 417, '0'),
(7891, 1210, 416, '0'),
(7892, 1205, 409, '0'),
(7893, 1206, 409, '0'),
(7894, 1207, 409, '0'),
(7895, 1208, 409, '0'),
(7896, 1210, 409, '0'),
(7897, 1209, 409, '0'),
(7898, 1205, 410, '0'),
(7899, 1205, 411, '0'),
(7900, 1205, 413, '0'),
(7901, 1205, 412, '0'),
(7902, 1205, 414, '0'),
(7903, 1205, 415, '0'),
(7904, 1206, 415, '0'),
(7905, 1206, 414, '0'),
(7906, 1206, 413, '0'),
(7907, 1206, 412, '0'),
(7908, 1206, 411, '0'),
(7909, 1206, 410, '0'),
(7910, 1207, 410, '0'),
(7911, 1208, 410, '0'),
(7912, 1209, 410, '0'),
(7913, 1210, 410, '0'),
(7914, 1207, 411, '0'),
(7915, 1207, 412, '0'),
(7916, 1207, 413, '0'),
(7917, 1207, 414, '0'),
(7918, 1207, 415, '0'),
(7919, 1208, 415, '0'),
(7920, 1209, 415, '0'),
(7921, 1210, 415, '0'),
(7922, 1210, 411, '0');
INSERT INTO `board_item_values` (`id`, `item_id`, `column_id`, `value`) VALUES
(7923, 1209, 411, '0'),
(7924, 1208, 411, '0'),
(7925, 1208, 412, '0'),
(7926, 1208, 413, '0'),
(7927, 1208, 414, '0'),
(7928, 1209, 414, '0'),
(7929, 1210, 414, '0'),
(7930, 1210, 413, '0'),
(7931, 1210, 412, '0'),
(7932, 1209, 413, '0'),
(7933, 1209, 412, '0'),
(7934, 1162, 417, '0'),
(7935, 1163, 417, '0'),
(7936, 1164, 417, '0'),
(7937, 1165, 417, '0'),
(7938, 1166, 417, '0'),
(7939, 1167, 417, '0'),
(7940, 1168, 417, '0'),
(7941, 1170, 417, '0'),
(7942, 1174, 417, '0'),
(7943, 1175, 417, '0'),
(7944, 1211, 281, 'Doc Nr 2135682958'),
(7945, 1211, 148, 'RIV195'),
(7946, 1211, 284, '644.40'),
(7947, 1211, 282, '2026-02-09'),
(7948, 1211, 280, 'Italtile'),
(7949, 1211, 283, 'Paid'),
(7950, 1211, 159, 'done'),
(7951, 1212, 281, 'Doc Nr B50090226142107'),
(7952, 1212, 148, 'RIV196'),
(7953, 1212, 284, '557.50'),
(7954, 1212, 282, '2026-02-09'),
(7955, 1212, 280, 'Builders Warehouse'),
(7956, 1212, 283, 'Paid'),
(7957, 1212, 159, 'done'),
(7958, 1213, 281, 'Doc Nr B50100226142073'),
(7959, 1213, 148, 'RIV197'),
(7960, 1213, 284, '436.20'),
(7961, 1213, 282, '2026-02-10'),
(7962, 1213, 280, 'Builders Warehouse'),
(7963, 1213, 283, 'Paid'),
(7964, 1213, 159, 'done'),
(7965, 1214, 281, 'Not Given'),
(7966, 1214, 148, 'RIV198'),
(7967, 1214, 284, '1300.00'),
(7968, 1214, 282, '2026-02-10'),
(7969, 1214, 280, 'Garage'),
(7970, 1214, 159, 'done'),
(7971, 1214, 283, 'Paid'),
(7972, 1216, 281, 'Not Given'),
(7973, 1216, 148, 'RIV199'),
(7974, 1216, 284, '170.00'),
(7975, 1216, 282, '2026-02-10'),
(7976, 1216, 280, 'Builders Warehouse'),
(7977, 1216, 283, 'Paid'),
(7978, 1216, 159, 'done'),
(7979, 1201, 281, 'Not Given'),
(7980, 1201, 284, '170.00'),
(7981, 1201, 282, '2026-02-10'),
(7982, 1201, 280, 'Builders Warehouse'),
(7983, 1201, 283, 'Paid'),
(7984, 1201, 159, 'done'),
(7985, 1198, 281, 'Not Given'),
(7986, 1198, 284, '106.99'),
(7987, 1198, 280, 'Checkers'),
(7988, 1198, 159, 'done'),
(7989, 1198, 283, 'Paid'),
(7990, 553, 280, 'Not Given'),
(7991, 776, 281, 'Order Nr 2758577'),
(7992, 776, 284, '1131.00'),
(7993, 776, 282, '2025-11-25'),
(7994, 776, 280, 'Mannys Hardware Supplies'),
(7995, 776, 283, 'Paid'),
(7996, 776, 159, 'done'),
(7997, 864, 281, 'Doc Nr 021686'),
(7998, 864, 284, '10662.00'),
(7999, 864, 280, 'Atchem'),
(8000, 864, 283, 'Paid'),
(8001, 864, 159, 'done'),
(8002, 865, 281, 'Doc Nr 021836'),
(8003, 865, 284, '9482.00'),
(8004, 865, 280, 'Atchem'),
(8005, 865, 283, 'Paid'),
(8006, 865, 159, 'done'),
(8007, 1217, 273, 'Not Given'),
(8008, 1217, 274, 'WAT058 B'),
(8009, 1217, 272, '650.00'),
(8010, 1217, 275, '2026-01-27'),
(8011, 1217, 276, 'PKGM Hardware'),
(8012, 1217, 277, 'Paid'),
(8013, 1218, 273, '014429'),
(8014, 1218, 274, 'WAT059'),
(8015, 1218, 272, '900.00'),
(8016, 1218, 275, '2026-01-27'),
(8017, 1218, 276, 'Multi Hardware'),
(8018, 1218, 277, 'Paid'),
(8019, 1219, 273, 'Not Given '),
(8020, 1220, 273, 'Not Given '),
(8021, 1221, 273, 'Not Given '),
(8022, 1222, 273, 'Not Given '),
(8023, 1219, 274, 'Not Given '),
(8024, 1220, 274, 'Not Given '),
(8025, 1221, 274, 'Not Given '),
(8026, 1222, 274, 'Not Given '),
(8027, 1219, 272, '27.00'),
(8028, 1220, 272, '27.00'),
(8029, 1221, 272, '27.00'),
(8030, 1222, 272, '27.00'),
(8031, 1219, 275, '2026-01-26'),
(8032, 1220, 275, '2026-01-26'),
(8033, 1221, 275, '2026-01-27'),
(8034, 1222, 275, '2026-01-27'),
(8035, 1219, 276, 'N1 South Grasmere '),
(8036, 1220, 276, 'N1 South Grasmere '),
(8037, 1221, 276, 'N1 South Grasmere '),
(8038, 1222, 276, 'N1 South Grasmere '),
(8039, 1219, 277, 'Paid'),
(8040, 1220, 277, 'Paid'),
(8041, 1221, 277, 'Paid'),
(8042, 1222, 277, 'Paid'),
(8043, 1223, 273, '31551'),
(8044, 1223, 274, 'WAT060'),
(8045, 1223, 272, '440.00'),
(8046, 1223, 275, '2026-01-28'),
(8047, 1223, 276, 'Multi Hardware'),
(8048, 1223, 277, 'Paid'),
(8049, 1224, 273, 'Not Given'),
(8050, 1224, 274, 'WAT061'),
(8051, 1224, 272, '133.00'),
(8052, 1224, 275, '2026-01-28'),
(8053, 1224, 276, 'PKGM Projects Hardware'),
(8054, 1224, 277, 'Paid'),
(8055, 1225, 273, 'QUT 811899'),
(8056, 1225, 274, 'WAT064'),
(8057, 1225, 272, '26435.00'),
(8058, 1225, 275, '2025-11-24'),
(8059, 1225, 276, 'Fhizo Construction '),
(8060, 1226, 273, 'QUT 811899'),
(8061, 1226, 274, 'WAT064'),
(8062, 1226, 272, '36080.00'),
(8063, 1226, 275, '2026-01-24'),
(8064, 1226, 276, 'Fhizo Construction '),
(8065, 1227, 273, 'QUT 900170'),
(8066, 1227, 274, 'WAT065'),
(8067, 1227, 272, '3680.00'),
(8068, 1227, 275, '2026-01-28'),
(8069, 1227, 276, 'Fhizo Construction '),
(8070, 1228, 273, 'Not Given'),
(8071, 1228, 274, 'Not Given'),
(8072, 1228, 272, '5200.00'),
(8073, 1228, 275, '2026-01-28'),
(8074, 1228, 276, 'Frank Glass trading'),
(8075, 1228, 277, 'Paid'),
(8076, 1227, 277, 'Paid'),
(8077, 1229, 273, 'Not Given'),
(8078, 1229, 274, 'WAT062'),
(8079, 1229, 272, '425.00'),
(8080, 1229, 275, '2026-01-29'),
(8081, 1229, 276, 'PKGM Hardware'),
(8082, 1229, 277, 'Paid'),
(8083, 1230, 273, 'Not Given'),
(8084, 1230, 274, 'WAT063'),
(8085, 1230, 272, '1000.00'),
(8086, 1230, 275, '2026-01-30'),
(8087, 1230, 276, 'Garage'),
(8088, 1230, 277, 'Paid'),
(8089, 1231, 273, 'Not Given'),
(8090, 1231, 274, 'WAT066 A'),
(8091, 1231, 272, '270.00'),
(8092, 1231, 275, '2026-02-06'),
(8093, 1231, 276, 'PKGM Projects Hardware'),
(8094, 1231, 277, 'Paid'),
(8095, 1232, 273, '31461'),
(8096, 1232, 274, 'WAT066 B'),
(8097, 1232, 272, '589.00'),
(8098, 1232, 275, '2026-02-06'),
(8099, 1232, 276, 'Multi Hardware'),
(8100, 1232, 277, 'Paid'),
(8101, 1233, 273, 'Not Given'),
(8102, 1233, 274, 'WAT067'),
(8103, 1233, 272, '1025.85'),
(8104, 1233, 275, '2026-02-06'),
(8105, 1233, 276, 'garage'),
(8106, 1233, 277, 'Paid'),
(8107, 1234, 277, 'Paid'),
(8108, 1234, 273, 'Not given'),
(8109, 1234, 274, 'Not Given'),
(8110, 1234, 272, '500.00'),
(8111, 1234, 275, '2026-02-06'),
(8112, 1234, 276, 'N1 South Grasmere '),
(8113, 1235, 273, 'INV 900223'),
(8114, 1235, 274, 'Not Given'),
(8115, 1235, 272, '12118.00'),
(8116, 1235, 275, '2026-02-09'),
(8117, 1235, 276, 'Fhizo Construction'),
(8118, 1235, 277, 'Paid'),
(8119, 1236, 273, 'Not Given'),
(8120, 1236, 274, 'Not Given'),
(8121, 1236, 272, '500.00'),
(8122, 1236, 275, '2026-02-11'),
(8123, 1236, 276, 'N1 South Grasmere '),
(8124, 1236, 277, 'Paid'),
(8125, 589, 284, '100.00'),
(8126, 589, 280, 'Builders Warehouse'),
(8127, 1162, 419, '1'),
(8128, 1163, 419, '0'),
(8129, 1164, 419, '1'),
(8130, 1165, 419, '1'),
(8131, 1166, 419, '1'),
(8132, 1167, 419, '1'),
(8133, 1168, 419, '1'),
(8134, 1170, 419, '1'),
(8135, 1174, 419, '1'),
(8136, 1175, 419, '1'),
(8137, 1162, 420, '1'),
(8138, 1163, 420, '0'),
(8139, 1164, 420, '1'),
(8140, 1165, 420, '1'),
(8141, 1166, 420, '1'),
(8142, 1167, 420, '1'),
(8143, 1168, 420, '1'),
(8144, 1170, 420, '1'),
(8145, 1174, 420, '1'),
(8146, 1175, 420, '1'),
(8147, 1162, 418, '0'),
(8148, 1163, 418, '0'),
(8149, 1164, 418, '0'),
(8150, 1165, 418, '0'),
(8151, 1166, 418, '0'),
(8152, 1167, 418, '0'),
(8153, 1168, 418, '0'),
(8154, 1170, 418, '0'),
(8155, 1174, 418, '0'),
(8156, 1175, 418, '0'),
(8157, 1205, 424, 'Painters'),
(8158, 1208, 424, 'Painters'),
(8159, 1209, 424, 'Painters'),
(8160, 1210, 424, 'Laborer'),
(8161, 1205, 425, '300'),
(8162, 1208, 425, '300'),
(8163, 1209, 425, '300'),
(8164, 1210, 425, '220'),
(8165, 1152, 419, '1'),
(8166, 1153, 419, '1'),
(8167, 1154, 419, '1'),
(8168, 1155, 419, '0'),
(8169, 1156, 419, '1'),
(8170, 1157, 419, '0'),
(8171, 1210, 419, '1'),
(8172, 1209, 419, '0'),
(8173, 1208, 419, '1'),
(8174, 1207, 419, '0'),
(8175, 1206, 419, '0'),
(8176, 1205, 419, '1'),
(8177, 1161, 419, '0'),
(8178, 1160, 419, '0'),
(8179, 1159, 419, '0'),
(8180, 1158, 419, '0'),
(8181, 1152, 420, '1'),
(8182, 1153, 420, '1'),
(8183, 1154, 420, '1'),
(8184, 1155, 420, '0'),
(8185, 1156, 420, '1'),
(8186, 1157, 420, '0'),
(8187, 1158, 420, '0'),
(8188, 1159, 420, '0'),
(8189, 1160, 420, '0'),
(8190, 1161, 420, '0'),
(8191, 1205, 420, '1'),
(8192, 1206, 420, '0'),
(8193, 1207, 420, '0'),
(8194, 1208, 420, '1'),
(8195, 1209, 420, '1'),
(8196, 1210, 420, '1'),
(8197, 1169, 418, '1'),
(8198, 1169, 419, '1'),
(8199, 1169, 420, '1'),
(8200, 1237, 273, 'Not Given '),
(8201, 1237, 274, 'Not Given '),
(8202, 1239, 272, '14400.00'),
(8203, 1239, 275, '2026-01-30'),
(8204, 1239, 276, 'Winston Kotse'),
(8205, 1238, 276, 'Winston Koste'),
(8206, 1237, 276, 'Winston Kotze '),
(8207, 1237, 277, 'Paid'),
(8208, 1238, 277, 'Paid'),
(8209, 1239, 277, 'Paid'),
(8210, 1238, 275, '2025-12-25'),
(8211, 1237, 275, '2025-11-25'),
(8212, 1238, 274, 'Not Given '),
(8213, 1239, 274, 'Not Given '),
(8214, 1238, 273, 'Not Given '),
(8215, 1239, 273, 'Not Given '),
(8216, 1225, 277, 'Paid'),
(8217, 1210, 421, '1'),
(8218, 1162, 421, '1'),
(8219, 1174, 421, '1'),
(8220, 1175, 421, '1'),
(8221, 1164, 421, '1'),
(8222, 1170, 421, '1'),
(8223, 1165, 421, '1'),
(8224, 1163, 421, '0'),
(8225, 1166, 421, '1'),
(8226, 1167, 421, '1'),
(8227, 1168, 421, '1'),
(8228, 1162, 422, '0'),
(8229, 1163, 422, '0'),
(8230, 1164, 422, '0'),
(8231, 1165, 422, '0'),
(8232, 1166, 422, '0'),
(8233, 1167, 422, '0'),
(8234, 1168, 422, '0'),
(8235, 1170, 422, '0'),
(8236, 1174, 422, '0'),
(8237, 1175, 422, '0'),
(8238, 1240, 424, 'Plasterer\r\n'),
(8239, 1240, 425, '450'),
(8240, 1241, 424, 'Laborer'),
(8241, 1241, 425, '220'),
(8242, 1242, 424, 'Laborer'),
(8243, 1242, 425, '300'),
(8244, 1242, 422, '1'),
(8245, 1241, 422, '1'),
(8246, 1240, 422, '1'),
(8247, 1169, 421, '1'),
(8248, 1169, 422, '1'),
(8249, 1152, 421, '1'),
(8250, 1153, 421, '1'),
(8251, 1154, 421, '1'),
(8252, 1155, 421, '0'),
(8253, 1156, 421, '1'),
(8254, 1157, 421, '0'),
(8255, 1158, 421, '0'),
(8256, 1159, 421, '0'),
(8257, 1160, 421, '0'),
(8258, 1161, 421, '0'),
(8259, 1205, 421, '0'),
(8260, 1206, 421, '0'),
(8261, 1207, 421, '0'),
(8262, 1208, 421, '1'),
(8263, 1209, 421, '1'),
(8264, 1152, 422, '1'),
(8265, 1153, 422, '0'),
(8266, 1154, 422, '1'),
(8267, 1155, 422, '1'),
(8268, 1156, 422, '0'),
(8269, 1157, 422, '0'),
(8270, 1158, 422, '1'),
(8271, 1159, 422, '0'),
(8272, 1160, 422, '0'),
(8273, 1161, 422, '0'),
(8274, 1205, 422, '1'),
(8275, 1206, 422, '1'),
(8276, 1207, 422, '1'),
(8277, 1208, 422, '1'),
(8278, 1209, 422, '1'),
(8279, 1210, 422, '1'),
(8280, 1243, 292, '100000'),
(8281, 1244, 292, '15000'),
(8282, 1245, 439, '12000'),
(8283, 1245, 438, '28980'),
(8284, 1246, 438, '33120'),
(8285, 1246, 439, '18000'),
(8286, 1247, 438, '49680'),
(8287, 1247, 439, '30000'),
(8288, 1248, 439, '50000'),
(8289, 1248, 438, '187680'),
(8290, 1249, 438, '0'),
(8291, 1249, 439, '15000'),
(8292, 1250, 455, '300'),
(8293, 1250, 456, '105'),
(8294, 1250, 459, '67275'),
(8295, 1251, 455, '300'),
(8296, 1251, 456, '45'),
(8297, 1252, 455, '1'),
(8298, 1252, 456, '5000'),
(8299, 1253, 455, '2'),
(8300, 1253, 456, '1150'),
(8301, 1253, 459, '22425'),
(8302, 1254, 455, '2'),
(8303, 1254, 456, '700'),
(8304, 1243, 291, '1'),
(8305, 1244, 291, '1'),
(8306, 1255, 460, 'CON010'),
(8307, 1255, 463, 'HAV001'),
(8308, 1255, 461, '7500.00'),
(8309, 1255, 462, '2026-02-18'),
(8310, 1255, 464, 'Linno Beukes'),
(8312, 1256, 460, 'Not Given'),
(8313, 1256, 463, 'HAV002'),
(8314, 1256, 461, '1000.00'),
(8315, 1256, 462, '2026-02-18'),
(8316, 1256, 464, 'Garage'),
(8317, 1255, 466, 'Paid'),
(8318, 1256, 466, 'Paid'),
(8319, 1257, 491, 'painter'),
(8320, 1257, 510, '1'),
(8321, 1257, 511, '1'),
(8322, 1257, 512, '0'),
(8323, 1257, 513, '1'),
(8324, 1257, 514, '1'),
(8325, 1257, 524, '250'),
(8326, 1257, 515, '1'),
(8327, 1257, 516, '0'),
(8328, 1257, 517, '0'),
(8329, 1257, 518, '0'),
(8330, 1257, 519, '1'),
(8331, 1257, 520, '1'),
(8332, 1257, 521, '1'),
(8333, 1257, 522, '1'),
(8334, 1257, 523, '0'),
(8335, 1259, 491, 'Foreman'),
(8336, 1260, 491, 'Laborer'),
(8337, 1261, 491, 'Laborer'),
(8338, 1262, 491, 'Laborer'),
(8339, 1263, 491, 'Painter'),
(8340, 1264, 491, 'Painter'),
(8341, 1265, 491, 'Painter'),
(8342, 1266, 491, 'Painter'),
(8343, 1267, 491, 'Painter'),
(8344, 1268, 491, 'Painter'),
(8345, 1269, 491, 'Plasterer'),
(8346, 1259, 524, '690'),
(8347, 1260, 524, '300'),
(8348, 1261, 524, '220'),
(8349, 1262, 524, '220'),
(8350, 1263, 524, '300'),
(8351, 1264, 524, '300'),
(8352, 1265, 524, '300'),
(8353, 1266, 524, '450'),
(8354, 1267, 524, '300'),
(8355, 1268, 524, '300'),
(8356, 1269, 524, '450'),
(8357, 1270, 491, 'Foreman'),
(8358, 1270, 524, '330'),
(8359, 1271, 491, 'Painter'),
(8360, 1271, 524, '450'),
(8361, 1272, 491, 'Laborer'),
(8362, 1272, 524, '220'),
(8363, 1273, 491, 'Laborer'),
(8364, 1273, 524, '220'),
(8365, 1274, 491, 'Laborer'),
(8366, 1274, 524, '220'),
(8367, 1275, 491, 'Plasterer'),
(8368, 1275, 524, '450'),
(8369, 1276, 491, 'Laborer'),
(8370, 1276, 524, '220'),
(8371, 1277, 491, 'Laborer'),
(8372, 1277, 524, '220'),
(8373, 1278, 491, 'Cleaning Lady'),
(8374, 1278, 524, '200'),
(8375, 1259, 510, '1'),
(8376, 1260, 510, '1'),
(8377, 1264, 510, '1'),
(8378, 1265, 510, '1'),
(8379, 1266, 510, '1'),
(8380, 1267, 510, '1'),
(8381, 1268, 510, '1'),
(8382, 1279, 524, '450'),
(8383, 1279, 491, 'Plasterer'),
(8384, 1280, 491, 'Laborer'),
(8385, 1280, 524, '220'),
(8386, 1279, 510, '1'),
(8387, 1280, 510, '1'),
(8388, 1259, 512, '1'),
(8389, 1260, 512, '1'),
(8390, 1261, 512, '1'),
(8391, 1262, 512, '1'),
(8392, 1263, 512, '1'),
(8393, 1259, 513, '1'),
(8394, 1260, 513, '1'),
(8395, 1261, 513, '1'),
(8397, 1263, 513, '1'),
(8398, 1264, 513, '1'),
(8399, 1265, 513, '1'),
(8400, 1268, 513, '1'),
(8401, 1259, 514, '1'),
(8402, 1260, 514, '1'),
(8403, 1261, 514, '1'),
(8404, 1262, 514, '1'),
(8405, 1263, 514, '1'),
(8406, 1264, 514, '1'),
(8407, 1265, 514, '1'),
(8408, 1268, 514, '1'),
(8409, 1269, 514, '1'),
(8410, 1259, 515, '1'),
(8411, 1260, 515, '1'),
(8412, 1261, 515, '1'),
(8413, 1263, 515, '1'),
(8414, 1264, 515, '1'),
(8415, 1265, 515, '1'),
(8416, 1268, 515, '1'),
(8418, 1259, 516, '1'),
(8419, 1260, 516, '1'),
(8420, 1263, 516, '1'),
(8421, 1261, 516, '1'),
(8422, 1264, 516, '1'),
(8423, 1268, 516, '1'),
(8424, 1265, 516, '1'),
(8425, 1259, 517, '1'),
(8426, 1260, 517, '1'),
(8427, 1264, 517, '1'),
(8428, 1268, 517, '1'),
(8429, 1265, 517, '1'),
(8430, 1261, 510, '0'),
(8431, 1262, 510, '0'),
(8432, 1263, 510, '0'),
(8433, 1269, 510, '0'),
(8434, 1264, 512, '0'),
(8435, 1265, 512, '0'),
(8436, 1266, 512, '0'),
(8437, 1267, 512, '0'),
(8438, 1268, 512, '0'),
(8439, 1269, 512, '0'),
(8440, 1266, 513, '0'),
(8441, 1262, 513, '0'),
(8442, 1269, 513, '0'),
(8443, 1267, 513, '0'),
(8444, 1266, 514, '0'),
(8445, 1267, 514, '0'),
(8446, 1262, 515, '0'),
(8447, 1266, 515, '0'),
(8448, 1267, 515, '0'),
(8449, 1269, 515, '0'),
(8450, 1262, 516, '0'),
(8451, 1266, 516, '0'),
(8452, 1267, 516, '0'),
(8453, 1269, 516, '0'),
(8454, 1261, 517, '0'),
(8455, 1262, 517, '0'),
(8456, 1263, 517, '0'),
(8457, 1266, 517, '0'),
(8458, 1267, 517, '0'),
(8459, 1269, 517, '0'),
(8460, 1278, 510, '0'),
(8461, 1278, 511, '0'),
(8462, 1278, 512, '1'),
(8463, 1278, 513, '1'),
(8464, 1278, 514, '1'),
(8465, 1278, 515, '1'),
(8466, 1278, 516, '1'),
(8467, 1278, 517, '0'),
(8468, 1278, 518, '0'),
(8469, 1279, 517, '1'),
(8470, 1280, 517, '1'),
(8471, 1279, 518, '0'),
(8472, 1280, 518, '0'),
(8473, 1279, 516, '0'),
(8474, 1280, 516, '0'),
(8475, 1280, 515, '0'),
(8476, 1279, 515, '0'),
(8477, 1279, 514, '0'),
(8478, 1280, 514, '0'),
(8479, 1279, 513, '0'),
(8480, 1280, 513, '0'),
(8481, 1279, 512, '0'),
(8482, 1280, 512, '0'),
(8483, 1279, 511, '0'),
(8484, 1280, 511, '0'),
(8485, 1270, 512, '1'),
(8486, 1273, 512, '1'),
(8487, 1272, 512, '1'),
(8488, 1274, 512, '1'),
(8489, 1271, 512, '0'),
(8490, 1275, 512, '0'),
(8491, 1276, 512, '0'),
(8492, 1277, 512, '0'),
(8493, 1270, 511, '0'),
(8494, 1272, 511, '0'),
(8495, 1271, 511, '0'),
(8496, 1273, 511, '0'),
(8497, 1274, 511, '0'),
(8498, 1275, 511, '0'),
(8499, 1276, 511, '0'),
(8500, 1277, 511, '0'),
(8501, 1270, 510, '0'),
(8502, 1271, 510, '0'),
(8503, 1272, 510, '0'),
(8504, 1273, 510, '0'),
(8505, 1274, 510, '0'),
(8506, 1275, 510, '0'),
(8507, 1276, 510, '0'),
(8508, 1277, 510, '0'),
(8509, 1270, 513, '1'),
(8510, 1271, 513, '1'),
(8511, 1272, 513, '1'),
(8512, 1273, 513, '1'),
(8513, 1274, 513, '1'),
(8514, 1275, 513, '1'),
(8515, 1276, 513, '1'),
(8516, 1281, 491, 'Painter'),
(8517, 1281, 524, '300'),
(8518, 1277, 513, '1'),
(8519, 1281, 513, '1'),
(8520, 1281, 510, '0'),
(8521, 1281, 511, '0'),
(8522, 1281, 512, '0'),
(8523, 1270, 514, '1'),
(8524, 1271, 514, '1'),
(8525, 1281, 514, '1'),
(8526, 1277, 514, '1'),
(8527, 1272, 514, '1'),
(8528, 1274, 514, '1'),
(8529, 1273, 514, '1'),
(8530, 1275, 514, '0'),
(8531, 1276, 514, '0'),
(8532, 1270, 515, '1'),
(8533, 1271, 515, '1'),
(8534, 1281, 515, '1'),
(8535, 1272, 515, '1'),
(8536, 1275, 515, '1'),
(8537, 1276, 515, '1'),
(8538, 1277, 515, '1'),
(8539, 1273, 515, '1'),
(8540, 1274, 515, '1'),
(8541, 1270, 516, '1'),
(8542, 1271, 516, '1'),
(8543, 1281, 516, '1'),
(8544, 1275, 516, '1'),
(8545, 1276, 516, '1'),
(8546, 1277, 516, '1'),
(8547, 1274, 516, '1'),
(8548, 1273, 516, '1'),
(8549, 1272, 516, '1'),
(8550, 1270, 517, '1'),
(8551, 1271, 517, '1'),
(8552, 1272, 517, '0'),
(8553, 1273, 517, '1'),
(8554, 1274, 517, '1'),
(8555, 1275, 517, '0'),
(8556, 1276, 517, '0'),
(8557, 1277, 517, '1'),
(8558, 1281, 517, '1'),
(8559, 1282, 281, 'Doc Nr B50110226105072'),
(8560, 1282, 148, 'RIV199'),
(8561, 1282, 284, '1076.00'),
(8562, 1282, 282, '2026-02-11'),
(8563, 1282, 280, 'Builders Warehouse'),
(8564, 1282, 283, 'Paid'),
(8565, 1282, 159, 'done'),
(8566, 1283, 148, 'RIV200'),
(8567, 1283, 284, '4047.00'),
(8568, 1283, 281, 'Doc Nr B50130226144026'),
(8569, 1283, 282, '2026-02-13'),
(8570, 1283, 280, 'Builders Warehouse'),
(8571, 1283, 283, 'Paid'),
(8572, 1283, 159, 'done'),
(8573, 1284, 281, 'Not Given '),
(8574, 1284, 148, 'RIV201'),
(8575, 1284, 284, '1200.00'),
(8576, 1284, 282, '2026-02-13'),
(8577, 1284, 280, 'Garage'),
(8578, 1284, 283, 'Paid'),
(8579, 1284, 159, 'done'),
(8580, 1285, 281, 'Not Given'),
(8581, 1285, 148, 'RIV202'),
(8582, 1285, 284, '100.00'),
(8583, 1285, 282, '2026-02-14'),
(8584, 1285, 280, 'Garage'),
(8585, 1285, 283, 'Paid'),
(8586, 1285, 159, 'done'),
(8587, 1286, 281, 'Not Given'),
(8588, 1287, 281, 'Not Given'),
(8589, 1287, 148, 'RIV203 A'),
(8590, 1287, 284, '260.00'),
(8591, 1287, 282, '2026-02-14'),
(8592, 1287, 280, 'DIY Depot'),
(8593, 1287, 283, 'Paid'),
(8594, 1287, 159, 'done'),
(8595, 1288, 281, 'Doc Nr B50140226105133'),
(8596, 1288, 148, 'RIV203 B'),
(8597, 1288, 284, '159.00'),
(8598, 1288, 282, '2026-02-14'),
(8599, 1288, 280, 'Builders Warehouse'),
(8600, 1288, 283, 'Paid'),
(8601, 1288, 159, 'done'),
(8602, 1289, 281, 'Not Given '),
(8603, 1289, 148, 'Not Given'),
(8604, 1289, 284, '500.00'),
(8605, 1289, 282, '2026-02-15'),
(8606, 1289, 280, 'Victor Aluminium '),
(8607, 1289, 283, 'Paid'),
(8608, 1289, 159, 'done'),
(8609, 1290, 281, 'Not Given'),
(8610, 1290, 148, 'RIV205'),
(8611, 1290, 284, '850'),
(8612, 1290, 282, '2026-02-17'),
(8613, 1290, 280, 'Garage'),
(8614, 1290, 283, 'Paid'),
(8615, 1290, 159, 'done'),
(8616, 1291, 281, 'Doc nr B50180226105066'),
(8617, 1291, 148, 'RIV205 A'),
(8618, 1291, 284, '491.00'),
(8619, 1291, 282, '2026-02-18'),
(8620, 1291, 280, 'Builders Warehouse'),
(8621, 1291, 283, 'Paid'),
(8622, 1291, 159, 'done'),
(8623, 1292, 281, 'Not Given'),
(8624, 1292, 148, 'RIV205 B'),
(8625, 1292, 284, '200.00'),
(8626, 1292, 280, 'DIY Depot'),
(8627, 1292, 282, '2026-02-18'),
(8628, 1292, 283, 'Paid'),
(8629, 1292, 159, 'done'),
(8630, 1293, 281, 'Doc Nr B50180226104032'),
(8631, 1293, 148, 'RIV205 C'),
(8632, 1293, 284, '72.00'),
(8633, 1293, 282, '2026-02-18'),
(8634, 1293, 280, 'Builders Warehouse '),
(8635, 1293, 283, 'Paid'),
(8636, 1293, 159, 'done'),
(8637, 1294, 281, 'Not Given'),
(8638, 1294, 148, 'Not Given'),
(8639, 1294, 284, '99.99'),
(8640, 1294, 282, '2026-02-18'),
(8641, 1294, 280, 'DIY Depot'),
(8642, 1294, 283, 'Paid'),
(8643, 1294, 159, 'done'),
(8644, 1295, 281, 'Not Given'),
(8645, 1295, 148, 'Not Given'),
(8646, 1295, 284, '800.00'),
(8647, 1295, 282, '2026-02-18'),
(8648, 1295, 280, 'Tiler Jim Maluleke'),
(8649, 1295, 283, 'Paid'),
(8650, 1295, 159, 'done'),
(8651, 1296, 281, 'Not Given'),
(8652, 1296, 282, '2026-02-18'),
(8653, 1296, 280, 'Samson Carpenter'),
(8654, 1296, 283, 'Paid'),
(8655, 1296, 159, 'done'),
(8656, 1296, 284, '3000.00'),
(8657, 1296, 148, 'Not Given'),
(8658, 1297, 281, 'Not Given'),
(8659, 1297, 148, 'RIV207'),
(8660, 1297, 284, '1000.00'),
(8661, 1297, 282, '2026-02-18'),
(8662, 1297, 280, 'Garage'),
(8663, 1297, 283, 'Paid'),
(8664, 1297, 159, 'done'),
(8665, 1298, 281, 'Not Given\r\n'),
(8666, 1298, 148, 'RIV209'),
(8667, 1298, 284, '5804.00'),
(8668, 1298, 282, '2026-02-19'),
(8669, 1298, 280, 'Atchem Holdings'),
(8670, 1299, 281, 'QUO01838958'),
(8671, 1299, 148, 'RIV210 A'),
(8672, 1299, 284, '4978.90'),
(8673, 1299, 282, '2026-02-19'),
(8674, 1299, 280, 'Thetotumiso Innovations '),
(8675, 1300, 281, 'Doc Nr B50190226101106'),
(8676, 1300, 148, 'RIV210 B'),
(8677, 1300, 284, '461.00'),
(8678, 1300, 282, '2026-02-19'),
(8679, 1300, 280, 'Builders Warehouse '),
(8680, 1300, 283, 'Paid'),
(8681, 1300, 159, 'done'),
(8682, 1298, 159, 'done'),
(8683, 1299, 159, 'done'),
(8684, 1298, 283, 'Paid'),
(8685, 1299, 283, 'Paid'),
(8686, 1301, 281, 'INV0627266'),
(8687, 1301, 148, 'RIV211'),
(8688, 1301, 284, '1435.20'),
(8689, 1301, 282, '2026-02-19'),
(8690, 1301, 280, 'Supertec Ceilings & Board'),
(8691, 1301, 283, 'Paid'),
(8692, 1301, 159, 'done'),
(8693, 1302, 281, 'Not Given'),
(8694, 1302, 148, 'RIV212'),
(8695, 1302, 284, '3001.00'),
(8696, 1302, 282, '2026-02-19'),
(8697, 1302, 280, '@Paint'),
(8698, 1302, 283, 'Paid'),
(8699, 1302, 159, 'done'),
(8700, 1303, 281, 'QTXS0000287041'),
(8701, 1303, 148, 'RIV213 A'),
(8702, 1303, 284, '10706.91'),
(8703, 1303, 282, '2026-02-20'),
(8704, 1303, 280, 'Foresta Timber & Wood'),
(8705, 1303, 283, 'Paid'),
(8706, 1303, 159, 'done'),
(8707, 1304, 281, 'Doc Nr B50200226142135'),
(8708, 1304, 148, 'RIV213 B'),
(8709, 1304, 284, '48.00'),
(8710, 1304, 282, '2026-02-20'),
(8711, 1304, 280, 'Builders Warehouse '),
(8712, 1304, 283, 'Paid'),
(8713, 1304, 159, 'done'),
(8714, 1305, 281, 'not Given'),
(8715, 1305, 283, 'Paid'),
(8716, 1305, 159, 'done'),
(8717, 1305, 284, '43510.00'),
(8718, 1305, 148, 'RIV214'),
(8719, 1305, 282, '2026-02-21'),
(8720, 1305, 280, 'Faheem Bahadar'),
(8721, 1306, 281, 'NJRDDJ0029223/000'),
(8722, 1306, 284, '7513.73'),
(8723, 1306, 282, '2026-02-21'),
(8724, 1306, 280, 'njr Steel'),
(8725, 1306, 283, 'Paid'),
(8726, 1306, 159, 'done'),
(8727, 1306, 148, 'RIV215'),
(8728, 1307, 281, 'INV3431177'),
(8729, 1307, 148, 'RIV216'),
(8730, 1307, 284, '3369.94'),
(8731, 1307, 282, '2026-02-22'),
(8732, 1307, 280, 'Riverside Mega Mica'),
(8733, 1307, 283, 'Paid'),
(8734, 1307, 159, 'done'),
(8735, 1308, 273, 'Not Given'),
(8736, 1308, 274, 'WAT070'),
(8737, 1308, 272, '273.00'),
(8738, 1308, 275, '2026-02-19'),
(8739, 1308, 276, 'PKGM Projects Hardware'),
(8740, 1308, 277, 'Paid'),
(8741, 1309, 273, '30691'),
(8742, 1309, 274, 'WAT071'),
(8743, 1309, 272, '1000.00'),
(8744, 1309, 275, '2026-02-20'),
(8745, 1309, 276, 'Multi Hardware'),
(8746, 1309, 277, 'Paid'),
(8747, 1310, 273, '30692'),
(8748, 1310, 274, 'WAT072 A'),
(8749, 1310, 272, '300.00'),
(8750, 1310, 275, '2026-02-20'),
(8751, 1310, 276, 'Multi Hardware'),
(8752, 1310, 277, 'Paid'),
(8753, 1311, 273, 'Doc Nr 023455'),
(8754, 1311, 274, 'WAT072 B'),
(8755, 1311, 272, '425.00'),
(8756, 1311, 275, '2026-02-20'),
(8757, 1311, 276, 'Atchem'),
(8758, 1311, 277, 'Paid'),
(8759, 1312, 273, 'Not Given'),
(8760, 1312, 274, 'WAT073'),
(8761, 1312, 272, '118.00'),
(8762, 1312, 275, '2026-02-21'),
(8763, 1312, 276, 'PKGM Projects Hardware'),
(8764, 1312, 277, 'Paid'),
(8765, 1313, 460, 'QTE 4409'),
(8766, 1313, 463, 'HAV003'),
(8767, 1313, 461, '58935.00'),
(8768, 1313, 462, '2026-02-19'),
(8769, 1313, 464, 'Kolev Steel Trading'),
(8770, 1314, 460, 'Not Given'),
(8771, 1314, 463, 'HAV004'),
(8772, 1314, 461, '1500.00'),
(8773, 1314, 462, '2026-02-19'),
(8774, 1314, 464, 'Garage'),
(8775, 1314, 466, 'Paid'),
(8776, 1313, 466, 'Deposit Paid'),
(8777, 1315, 460, 'Not Given'),
(8778, 1315, 463, 'HAV006'),
(8779, 1315, 461, '1000.00'),
(8780, 1315, 462, '2026-02-21'),
(8781, 1315, 464, 'Garage'),
(8782, 1315, 466, 'Paid'),
(8783, 1316, 460, 'CON011'),
(8784, 1316, 463, 'HAV007'),
(8785, 1316, 461, '10500.00'),
(8786, 1316, 462, '2026-02-21'),
(8787, 1316, 464, 'Linno Beukes'),
(8788, 1316, 466, 'Paid'),
(8789, 1317, 281, 'Not Given '),
(8790, 1317, 148, 'Not Given '),
(8791, 1317, 284, '19400.00'),
(8792, 1317, 282, '2026-02-05'),
(8793, 1317, 280, 'Welder'),
(8794, 1317, 283, 'Paid'),
(8795, 1317, 159, 'done'),
(8796, 1318, 273, 'Not Given'),
(8797, 1319, 281, 'Not given'),
(8798, 1319, 148, 'RIV214'),
(8799, 1270, 518, '0'),
(8800, 1271, 518, '0'),
(8801, 1281, 518, '0'),
(8802, 1272, 518, '0'),
(8803, 1273, 518, '0'),
(8804, 1274, 518, '0'),
(8805, 1275, 518, '0'),
(8806, 1276, 518, '0'),
(8807, 1277, 518, '0'),
(8808, 1320, 491, 'Painter'),
(8809, 1320, 524, '300'),
(8810, 1320, 517, '1'),
(8811, 1320, 518, '0'),
(8812, 1320, 516, '0'),
(8813, 1320, 515, '0'),
(8814, 1320, 514, '0'),
(8815, 1320, 513, '0'),
(8816, 1320, 512, '0'),
(8817, 1320, 511, '0'),
(8818, 1320, 510, '0'),
(8819, 1270, 519, '1'),
(8820, 1271, 519, '0'),
(8821, 1272, 519, '1'),
(8822, 1273, 519, '1'),
(8823, 1274, 519, '1'),
(8824, 1275, 519, '1'),
(8825, 1276, 519, '0'),
(8826, 1277, 519, '1'),
(8827, 1281, 519, '1'),
(8828, 1320, 519, '1'),
(8829, 1279, 519, '0'),
(8830, 1280, 519, '0'),
(8831, 1278, 519, '1'),
(8832, 1259, 518, '1'),
(8833, 1260, 518, '1'),
(8834, 1261, 518, '0'),
(8835, 1262, 518, '1'),
(8836, 1263, 518, '0'),
(8837, 1264, 518, '1'),
(8838, 1265, 518, '1'),
(8839, 1266, 518, '1'),
(8840, 1267, 518, '1'),
(8841, 1268, 518, '1'),
(8842, 1269, 518, '1'),
(8843, 1259, 519, '1'),
(8844, 1260, 519, '1'),
(8845, 1261, 519, '1'),
(8846, 1262, 519, '1'),
(8847, 1263, 519, '1'),
(8848, 1264, 519, '1'),
(8849, 1265, 519, '0'),
(8850, 1266, 519, '0'),
(8851, 1267, 519, '0'),
(8852, 1268, 519, '1'),
(8853, 1269, 519, '0'),
(8854, 1278, 520, '1'),
(8855, 1279, 520, '1'),
(8856, 1280, 520, '1'),
(8857, 1321, 491, 'Laborer'),
(8858, 1321, 524, '220'),
(8859, 1322, 491, 'Laborer'),
(8860, 1322, 524, '220'),
(8861, 1321, 520, '1'),
(8862, 1322, 520, '1'),
(8863, 1321, 519, '0'),
(8864, 1322, 519, '0'),
(8865, 1321, 518, '0'),
(8866, 1322, 518, '0'),
(8867, 1321, 517, '0'),
(8868, 1322, 517, '0'),
(8869, 1321, 516, '0'),
(8870, 1322, 516, '0'),
(8871, 1321, 510, '0'),
(8872, 1321, 512, '0'),
(8873, 1321, 511, '0'),
(8874, 1321, 513, '0'),
(8875, 1321, 515, '0'),
(8876, 1322, 514, '0'),
(8877, 1322, 515, '0'),
(8878, 1321, 514, '0'),
(8879, 1322, 513, '0'),
(8880, 1322, 512, '0'),
(8881, 1322, 511, '0'),
(8882, 1322, 510, '0'),
(8883, 1323, 281, 'Not Given'),
(8884, 1323, 148, 'RIV217'),
(8885, 1323, 284, '1000.00'),
(8886, 1323, 282, '2026-02-23'),
(8887, 1323, 280, 'Garage'),
(8888, 1323, 283, 'Paid'),
(8889, 1323, 159, 'done'),
(8890, 1324, 281, 'B50220226107036'),
(8891, 1324, 148, 'RIV218'),
(8892, 1324, 284, '966.20'),
(8893, 1324, 282, '2026-02-22'),
(8894, 1324, 280, 'Builders Warehouse'),
(8895, 1324, 283, 'Paid'),
(8896, 1324, 159, 'done'),
(8897, 1325, 281, 'B50220226101101'),
(8898, 1325, 148, 'RIV219'),
(8899, 1325, 284, '402.20'),
(8900, 1325, 282, '2026-02-22'),
(8901, 1325, 280, 'Builders Warehouse'),
(8902, 1325, 283, 'Paid'),
(8903, 1325, 159, 'done'),
(8904, 1326, 281, 'B50230226109021'),
(8905, 1326, 148, 'RIV220'),
(8906, 1326, 284, '159.00'),
(8907, 1326, 282, '2026-02-23'),
(8908, 1326, 280, 'Builders Warehouse'),
(8909, 1326, 283, 'Paid'),
(8910, 1326, 159, 'done'),
(8911, 1327, 281, 'POS151754'),
(8912, 1327, 148, 'RIV221'),
(8913, 1327, 284, '32.99'),
(8914, 1327, 282, '2026-02-21'),
(8915, 1327, 280, 'DIY Depot'),
(8916, 1327, 283, 'Paid'),
(8917, 1327, 159, 'done'),
(8918, 1328, 281, 'Not Given'),
(8919, 1328, 148, 'RIV222'),
(8920, 1328, 284, '1000.00'),
(8921, 1328, 282, '2026-02-24'),
(8922, 1328, 280, 'Garage'),
(8923, 1328, 283, 'Paid'),
(8924, 1328, 159, 'done'),
(8925, 1329, 281, 'TI00789'),
(8926, 1329, 148, 'RIV223'),
(8927, 1329, 284, '113.85'),
(8928, 1329, 282, '2026-02-23'),
(8929, 1329, 280, 'AGW KYA Sands'),
(8930, 1329, 283, 'Paid'),
(8931, 1329, 159, 'done'),
(8932, 1330, 281, 'INV 572682'),
(8933, 1330, 148, 'RIV224'),
(8934, 1330, 284, '1076.72'),
(8935, 1330, 282, '2026-02-20'),
(8936, 1330, 280, 'njr Steel'),
(8937, 1330, 283, 'Paid'),
(8938, 1330, 159, 'done'),
(8939, 1331, 281, 'B50200226142135'),
(8940, 1331, 148, 'RIV225'),
(8941, 1331, 284, '48.00'),
(8942, 1331, 282, '2026-02-20'),
(8943, 1331, 280, 'Builders Warehouse'),
(8944, 1331, 283, 'Paid'),
(8945, 1331, 159, 'done'),
(8946, 1332, 281, 'B50200226107107'),
(8947, 1332, 148, 'RIV226'),
(8948, 1332, 284, '1139.00'),
(8949, 1332, 282, '2026-02-20'),
(8950, 1332, 280, 'Builders Warehouse'),
(8951, 1332, 283, 'Paid'),
(8952, 1332, 159, 'done'),
(8953, 1333, 281, 'Not Given'),
(8954, 1333, 148, 'RIV226'),
(8955, 1333, 284, '1000.00'),
(8956, 1333, 282, '2026-02-22'),
(8957, 1333, 280, 'Garage'),
(8958, 1333, 283, 'Paid'),
(8959, 1333, 159, 'done'),
(8960, 1334, 281, '20100202'),
(8961, 1334, 148, 'RIV227'),
(8962, 1334, 284, '79.90'),
(8963, 1334, 282, '2026-02-23'),
(8964, 1334, 280, 'Value (The Glen)'),
(8965, 1334, 283, 'Paid'),
(8966, 1334, 159, 'done'),
(8967, 1335, 281, 'Doc Nr B50220226101207'),
(8968, 1335, 148, 'RIV228'),
(8969, 1335, 284, '354.00'),
(8970, 1335, 282, '2026-02-22'),
(8971, 1335, 280, 'Builders Warehouse'),
(8972, 1335, 283, 'Paid'),
(8973, 1335, 159, 'done'),
(8974, 1259, 520, '1'),
(8975, 1260, 520, '1'),
(8976, 1261, 520, '1'),
(8977, 1262, 520, '0'),
(8978, 1263, 520, '1'),
(8979, 1264, 520, '1'),
(8980, 1265, 520, '0'),
(8981, 1266, 520, '0'),
(8982, 1267, 520, '0'),
(8983, 1268, 520, '1'),
(8984, 1269, 520, '0'),
(8985, 1278, 521, '1'),
(8986, 1279, 521, '1'),
(8987, 1280, 521, '1'),
(8988, 1321, 521, '1'),
(8989, 1322, 521, '1'),
(8990, 1259, 521, '1'),
(8991, 1260, 521, '1'),
(8992, 1261, 521, '1'),
(8993, 1262, 521, '0'),
(8994, 1263, 521, '1'),
(8995, 1264, 521, '1'),
(8996, 1265, 521, '0'),
(8997, 1266, 521, '0'),
(8998, 1267, 521, '0'),
(8999, 1268, 521, '1'),
(9000, 1269, 521, '0'),
(9001, 1259, 522, '1'),
(9002, 1260, 522, '1'),
(9003, 1261, 522, '1'),
(9004, 1265, 522, '1'),
(9005, 1264, 522, '1'),
(9006, 1262, 522, '0'),
(9007, 1263, 522, '0'),
(9008, 1266, 522, '0'),
(9009, 1267, 522, '0'),
(9010, 1268, 522, '0'),
(9011, 1269, 522, '0'),
(9012, 1278, 522, '1'),
(9013, 1279, 522, '1'),
(9014, 1280, 522, '1'),
(9015, 1321, 522, '0'),
(9016, 1322, 522, '0'),
(9017, 1278, 523, '1'),
(9018, 1279, 523, '1'),
(9019, 1280, 523, '1'),
(9020, 1321, 523, '0'),
(9021, 1322, 523, '0'),
(9022, 1270, 520, '1'),
(9023, 1271, 520, '0'),
(9024, 1272, 520, '1'),
(9025, 1273, 520, '1'),
(9026, 1274, 520, '1'),
(9027, 1275, 520, '0'),
(9028, 1276, 520, '0'),
(9029, 1277, 520, '1'),
(9030, 1281, 520, '1'),
(9031, 1320, 520, '1'),
(9032, 1270, 521, '1'),
(9033, 1271, 521, '0'),
(9034, 1272, 521, '1'),
(9035, 1273, 521, '1'),
(9036, 1274, 521, '1'),
(9037, 1275, 521, '0'),
(9038, 1276, 521, '0'),
(9039, 1277, 521, '1'),
(9040, 1281, 521, '1'),
(9041, 1320, 521, '0'),
(9042, 1270, 522, '1'),
(9043, 1271, 522, '1'),
(9044, 1272, 522, '1'),
(9045, 1273, 522, '1'),
(9046, 1274, 522, '1'),
(9047, 1275, 522, '0'),
(9048, 1276, 522, '0'),
(9049, 1277, 522, '1'),
(9050, 1281, 522, '1'),
(9051, 1320, 522, '1'),
(9052, 1336, 491, 'Painter'),
(9053, 1336, 524, '300'),
(9054, 1336, 522, '1'),
(9055, 1336, 521, '0'),
(9056, 1336, 520, '1'),
(9057, 1336, 519, '1'),
(9058, 1336, 518, '0'),
(9059, 1336, 517, '0'),
(9060, 1336, 510, '0'),
(9061, 1336, 511, '0'),
(9062, 1336, 512, '0'),
(9063, 1336, 513, '0'),
(9064, 1336, 514, '0'),
(9065, 1336, 515, '0'),
(9066, 1336, 516, '0'),
(9067, 1259, 523, '1'),
(9068, 1260, 523, '1'),
(9069, 1261, 523, '0'),
(9070, 1262, 523, '0'),
(9071, 1263, 523, '0'),
(9072, 1320, 523, '1'),
(9073, 1264, 523, '1'),
(9074, 1265, 523, '1'),
(9075, 1266, 523, '0'),
(9076, 1267, 523, '0'),
(9077, 1268, 523, '0'),
(9078, 1269, 523, '0'),
(9079, 1336, 523, '1'),
(9080, 1270, 523, '1'),
(9081, 1271, 523, '1'),
(9082, 1272, 523, '0'),
(9083, 1273, 523, '1'),
(9084, 1274, 523, '1'),
(9085, 1275, 523, '0'),
(9086, 1276, 523, '0'),
(9087, 1277, 523, '1'),
(9088, 1281, 523, '1'),
(9089, 1337, 281, 'Doc Nr B50220226160103'),
(9090, 1337, 148, 'RIV229'),
(9091, 1337, 284, '100.00'),
(9092, 1337, 282, '2026-02-22'),
(9093, 1337, 280, 'Builders Warehouse'),
(9094, 1337, 283, 'Paid'),
(9095, 1337, 159, 'done'),
(9096, 1338, 281, 'B50220226103167'),
(9097, 1338, 148, 'RIV230'),
(9098, 1338, 284, '398.00'),
(9099, 1338, 282, '2026-02-22'),
(9100, 1338, 280, 'Builders Warehouse'),
(9101, 1338, 283, 'Paid'),
(9102, 1338, 159, 'done'),
(9103, 1339, 281, 'Not Given '),
(9104, 1339, 148, 'RIV231'),
(9105, 1339, 284, '1000.00'),
(9106, 1339, 282, '2026-02-22'),
(9107, 1340, 281, 'Not Given'),
(9108, 1340, 148, 'RIV231'),
(9109, 1340, 284, '1000.00'),
(9110, 1340, 282, '2026-02-20'),
(9111, 1340, 280, 'Garage '),
(9112, 1340, 283, 'Paid'),
(9113, 1340, 159, 'done'),
(9114, 1341, 281, 'QTE NJRDDJ0029433/001'),
(9115, 1341, 284, '291.30'),
(9116, 1341, 282, '2026-02-24'),
(9117, 1341, 280, 'njr Steel'),
(9118, 1341, 283, 'Paid'),
(9119, 1341, 159, 'done'),
(9120, 1341, 148, 'RIV236'),
(9121, 1342, 281, 'POS151952'),
(9122, 1342, 148, 'RIV237'),
(9123, 1342, 284, '109.99'),
(9124, 1342, 282, '2026-02-24'),
(9125, 1342, 280, 'DIY Depot'),
(9126, 1342, 283, 'Paid'),
(9127, 1342, 159, 'done'),
(9128, 1343, 281, 'POS152012'),
(9129, 1343, 148, 'RIV238'),
(9130, 1343, 284, '329.84'),
(9131, 1343, 282, '2026-02-25'),
(9132, 1343, 280, 'DIY Depot'),
(9133, 1343, 283, 'Paid'),
(9134, 1343, 159, 'done'),
(9135, 1344, 282, '2026-02-25'),
(9136, 1344, 280, 'Builders Warehouse'),
(9137, 1344, 283, 'Paid'),
(9138, 1344, 159, 'done'),
(9139, 1344, 281, 'Doc Nr B50250226143112'),
(9140, 1344, 148, 'RIV239'),
(9141, 1344, 284, '276.00'),
(9142, 1345, 281, 'not Given'),
(9143, 1345, 148, 'RIV240'),
(9144, 1345, 284, '1300.00'),
(9145, 1345, 282, '2026-02-26'),
(9146, 1345, 280, 'Garage'),
(9147, 1345, 283, 'Paid'),
(9148, 1345, 159, 'done'),
(9149, 1346, 281, 'Doc Nr B50260226101067'),
(9150, 1346, 148, 'RIV241'),
(9151, 1346, 284, '1554.00'),
(9152, 1346, 282, '2026-02-26'),
(9153, 1346, 280, 'Builders Warehouse'),
(9154, 1346, 283, 'Paid'),
(9155, 1346, 159, 'done'),
(9156, 1347, 148, 'RIV242'),
(9157, 1347, 282, '2026-02-26'),
(9158, 1348, 281, 'Ord Nr QTX0000287331'),
(9159, 1348, 148, 'RIV243'),
(9160, 1348, 284, '2712.95'),
(9161, 1348, 282, '2026-02-26'),
(9162, 1348, 280, 'Foresta Timber & Board'),
(9163, 1348, 283, 'Paid'),
(9164, 1348, 159, 'done'),
(9165, 1349, 281, 'Dpc Nr 023625'),
(9166, 1349, 148, 'RIV244'),
(9167, 1349, 284, '4282.00'),
(9168, 1349, 282, '2026-02-26'),
(9169, 1349, 280, 'Atchem'),
(9170, 1349, 283, 'Paid'),
(9171, 1349, 159, 'done'),
(9172, 1350, 460, 'Not Given'),
(9173, 1350, 463, 'HAV008'),
(9174, 1350, 461, '800.00'),
(9175, 1350, 462, '2026-02-25'),
(9176, 1350, 464, 'Garage'),
(9177, 1350, 466, 'Paid'),
(9178, 1351, 463, 'HAV009'),
(9179, 1351, 460, 'Not Given'),
(9180, 1351, 461, '200.15'),
(9181, 1351, 462, '2026-02-25'),
(9182, 1351, 464, 'garage'),
(9183, 1351, 466, 'Paid'),
(9184, 1352, 460, 'Not Given '),
(9185, 1352, 463, 'HAV010'),
(9186, 1352, 461, '4000.00'),
(9187, 1352, 462, '2026-02-26'),
(9188, 1352, 464, 'Oupa '),
(9189, 1352, 466, 'Not Paid'),
(9190, 1353, 460, 'INV 1/59/2002'),
(9191, 1353, 463, 'HAV011'),
(9192, 1353, 461, '288.00'),
(9193, 1353, 462, '2026-02-25'),
(9194, 1353, 464, 'Hardware Inc.'),
(9195, 1353, 466, 'Paid'),
(9196, 1354, 460, 'INV 1/59/1975'),
(9197, 1354, 461, '1735.50'),
(9198, 1354, 462, '2026-02-25'),
(9199, 1354, 464, 'Hardware Inc.'),
(9200, 1354, 466, 'Paid'),
(9201, 1355, 460, 'Doc nr QU122956'),
(9202, 1355, 463, 'HAV012'),
(9203, 1355, 461, '14799.99'),
(9204, 1355, 462, '2026-02-27'),
(9205, 1355, 464, 'Ralf Transport'),
(9206, 1355, 466, 'Not Paid'),
(9207, 1356, 460, 'INV 1/59/2002'),
(9208, 1356, 463, 'HAV013'),
(9209, 1356, 461, '1735.50'),
(9210, 1356, 462, '2026-02-25'),
(9211, 1356, 464, 'Hardware Inc.'),
(9212, 1356, 466, 'Paid'),
(9213, 1357, 273, 'Doc nr B50260226201868'),
(9214, 1357, 274, 'WAT074'),
(9215, 1357, 272, '284.70'),
(9216, 1357, 275, '2026-02-26'),
(9217, 1357, 276, 'Builders Warehouse '),
(9218, 1357, 277, 'Paid'),
(9219, 1358, 273, 'Doc Nr 023627'),
(9220, 1358, 274, 'WAT075'),
(9221, 1358, 272, '1457.00'),
(9222, 1358, 275, '2026-02-26'),
(9223, 1358, 276, 'Atchem'),
(9224, 1358, 277, 'Paid');

-- --------------------------------------------------------

--
-- Table structure for table `board_members`
--

CREATE TABLE `board_members` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('owner','editor','viewer') DEFAULT 'editor',
  `added_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `board_members`
--

INSERT INTO `board_members` (`id`, `board_id`, `user_id`, `role`, `added_at`) VALUES
(1, 2, 1, 'owner', '2025-09-30 09:56:25'),
(2, 3, 1, 'owner', '2025-09-30 09:56:25'),
(3, 4, 1, 'owner', '2025-09-30 09:56:25'),
(4, 5, 1, 'owner', '2025-09-30 09:56:25');

-- --------------------------------------------------------

--
-- Table structure for table `board_saved_views`
--

CREATE TABLE `board_saved_views` (
  `id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `view_type` varchar(20) NOT NULL,
  `filters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`filters`)),
  `sorts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sorts`)),
  `visible_columns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`visible_columns`)),
  `is_shared` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_subitems`
--

CREATE TABLE `board_subitems` (
  `id` int(11) NOT NULL,
  `parent_item_id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `status_label` varchar(50) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `priority` varchar(20) DEFAULT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `position` int(11) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_watchers`
--

CREATE TABLE `board_watchers` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budgets`
--

CREATE TABLE `budgets` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `fiscal_year` smallint(5) UNSIGNED NOT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budget_lines`
--

CREATE TABLE `budget_lines` (
  `id` int(10) UNSIGNED NOT NULL,
  `budget_id` int(10) UNSIGNED NOT NULL,
  `gl_account_id` int(10) UNSIGNED NOT NULL,
  `m01_cents` bigint(20) NOT NULL DEFAULT 0,
  `m02_cents` bigint(20) NOT NULL DEFAULT 0,
  `m03_cents` bigint(20) NOT NULL DEFAULT 0,
  `m04_cents` bigint(20) NOT NULL DEFAULT 0,
  `m05_cents` bigint(20) NOT NULL DEFAULT 0,
  `m06_cents` bigint(20) NOT NULL DEFAULT 0,
  `m07_cents` bigint(20) NOT NULL DEFAULT 0,
  `m08_cents` bigint(20) NOT NULL DEFAULT 0,
  `m09_cents` bigint(20) NOT NULL DEFAULT 0,
  `m10_cents` bigint(20) NOT NULL DEFAULT 0,
  `m11_cents` bigint(20) NOT NULL DEFAULT 0,
  `m12_cents` bigint(20) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendars`
--

CREATE TABLE `calendars` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `calendar_type` enum('personal','team','project','resource') NOT NULL DEFAULT 'personal',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) DEFAULT '#06b6d4',
  `owner_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `sharing_json` longtext DEFAULT NULL COMMENT 'JSON array of {user_id, role: view/edit}',
  `is_active` tinyint(1) DEFAULT 1,
  `ics_token` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `calendars`
--

INSERT INTO `calendars` (`id`, `company_id`, `calendar_type`, `name`, `description`, `color`, `owner_id`, `project_id`, `sharing_json`, `is_active`, `ics_token`, `created_at`, `updated_at`) VALUES
(1, 1, 'personal', 'Concreto', '', '#06b6d4', 1, NULL, NULL, 1, 'cc95a8147f1fea781a8653cd948b1951158b3d9ff2cbe32b9955159161ece110', '2025-10-02 16:35:56', '2025-10-02 16:35:56');

-- --------------------------------------------------------

--
-- Table structure for table `calendar_events`
--

CREATE TABLE `calendar_events` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `calendar_id` bigint(20) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` longtext DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `color` varchar(7) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `all_day` tinyint(1) DEFAULT 0,
  `recurrence` varchar(500) DEFAULT NULL COMMENT 'RRULE string',
  `exdates_json` longtext DEFAULT NULL COMMENT 'Exception dates array',
  `visibility` enum('default','busy','private') DEFAULT 'default',
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `calendar_events`
--

INSERT INTO `calendar_events` (`id`, `company_id`, `calendar_id`, `title`, `description`, `location`, `lat`, `lng`, `color`, `start_datetime`, `end_datetime`, `all_day`, `recurrence`, `exdates_json`, `visibility`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Meeting with John', '', '3pm for 1h', NULL, NULL, NULL, '2025-10-03 16:36:09', '2025-10-03 17:36:09', 0, NULL, NULL, 'default', 1, '2025-10-02 16:36:09', '2025-10-02 16:36:09'),
(2, 1, 1, 'mmeetig with anita', '', '', NULL, NULL, NULL, '2025-10-03 13:00:00', '2025-10-03 14:00:00', 0, NULL, NULL, 'default', 1, '2025-10-02 19:31:56', '2025-10-02 19:31:56');

-- --------------------------------------------------------

--
-- Table structure for table `calendar_event_attachments`
--

CREATE TABLE `calendar_event_attachments` (
  `id` bigint(20) NOT NULL,
  `event_id` bigint(20) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_event_links`
--

CREATE TABLE `calendar_event_links` (
  `id` bigint(20) NOT NULL,
  `event_id` bigint(20) NOT NULL,
  `linked_type` enum('project','board','item','quote','invoice','rfq','po','email','crm_account','crm_interaction') NOT NULL,
  `linked_id` bigint(20) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_event_participants`
--

CREATE TABLE `calendar_event_participants` (
  `id` bigint(20) NOT NULL,
  `event_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('organizer','required','optional') DEFAULT 'required',
  `response` enum('pending','accepted','declined','tentative') DEFAULT 'pending',
  `response_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_event_reminders`
--

CREATE TABLE `calendar_event_reminders` (
  `id` bigint(20) NOT NULL,
  `event_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `minutes_before` int(11) NOT NULL,
  `channel` enum('in_app','email') DEFAULT 'in_app',
  `sent_at` datetime DEFAULT NULL,
  `snoozed_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_event_templates`
--

CREATE TABLE `calendar_event_templates` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `template_json` longtext NOT NULL COMMENT 'Event structure',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_notes`
--

CREATE TABLE `calendar_notes` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `calendar_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note_date` date NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` longtext DEFAULT NULL,
  `color` varchar(7) DEFAULT '#fbbf24',
  `pinned` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_settings`
--

CREATE TABLE `calendar_settings` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `timezone` varchar(64) DEFAULT 'Africa/Johannesburg',
  `week_start` tinyint(4) DEFAULT 1 COMMENT '0=Sun,1=Mon',
  `work_hours_start` time DEFAULT '08:00:00',
  `work_hours_end` time DEFAULT '17:00:00',
  `default_reminder_minutes` int(11) DEFAULT 15,
  `default_view` varchar(20) DEFAULT 'week',
  `enable_invoice_due` tinyint(1) NOT NULL DEFAULT 1,
  `enable_project_dates` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_subscriptions`
--

CREATE TABLE `calendar_subscriptions` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `ics_url` varchar(1000) NOT NULL,
  `color` varchar(7) DEFAULT '#64748b',
  `last_sync_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_tasks`
--

CREATE TABLE `calendar_tasks` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `calendar_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL,
  `due_datetime` datetime DEFAULT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `checklist_json` longtext DEFAULT NULL COMMENT 'Array of {text, done}',
  `position` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_working_hours`
--

CREATE TABLE `calendar_working_hours` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `weekday` tinyint(4) NOT NULL COMMENT '0=Sun,1=Mon,...6=Sat',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `vat_number` varchar(50) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `reg_number` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `postal` varchar(20) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `bank_branch_code` varchar(20) DEFAULT NULL,
  `invoice_footer_text` text DEFAULT NULL,
  `quote_footer_text` text DEFAULT NULL,
  `primary_color` varchar(7) DEFAULT '#fbbf24',
  `secondary_color` varchar(7) DEFAULT '#f59e0b',
  `qi_template` enum('modern','classic','minimal','bold') DEFAULT 'modern',
  `qi_show_company_address` tinyint(1) DEFAULT 1,
  `qi_show_company_phone` tinyint(1) DEFAULT 1,
  `qi_show_company_email` tinyint(1) DEFAULT 1,
  `qi_show_company_website` tinyint(1) DEFAULT 1,
  `qi_show_vat_number` tinyint(1) DEFAULT 1,
  `qi_show_tax_number` tinyint(1) DEFAULT 1,
  `qi_show_reg_number` tinyint(1) DEFAULT 1,
  `qi_font_family` enum('system-ui','montserrat','helvetica','georgia','inter') DEFAULT 'system-ui',
  `country` varchar(100) DEFAULT 'South Africa',
  `business_type` enum('construction','postal','hairdresser') NOT NULL,
  `plan_id` int(11) NOT NULL,
  `max_users` int(11) NOT NULL DEFAULT 1,
  `max_companies` int(11) NOT NULL DEFAULT 1,
  `subscription_active` tinyint(1) NOT NULL DEFAULT 0,
  `yoco_mode` enum('test','live') DEFAULT 'test',
  `yoco_pub_key` varchar(255) DEFAULT NULL,
  `yoco_sec_key` varchar(255) DEFAULT NULL,
  `yoco_webhook_secret` varchar(255) DEFAULT NULL,
  `yoco_customer_id` varchar(128) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `name`, `vat_number`, `tax_number`, `reg_number`, `phone`, `email`, `website`, `logo_url`, `address_line1`, `address_line2`, `city`, `region`, `postal`, `bank_name`, `bank_account_number`, `bank_branch_code`, `invoice_footer_text`, `quote_footer_text`, `primary_color`, `secondary_color`, `qi_template`, `qi_show_company_address`, `qi_show_company_phone`, `qi_show_company_email`, `qi_show_company_website`, `qi_show_vat_number`, `qi_show_tax_number`, `qi_show_reg_number`, `qi_font_family`, `country`, `business_type`, `plan_id`, `max_users`, `max_companies`, `subscription_active`, `yoco_mode`, `yoco_pub_key`, `yoco_sec_key`, `yoco_webhook_secret`, `yoco_customer_id`, `created_at`, `updated_at`) VALUES
(1, 'Concreto Civils', '1234124', '12341241423', '123412412', '0169871614', 'anita@concreto.co.za', 'https://www.concreto.co.za', '/uploads/1/logo/logo.webp', '180 Mullersteine', '', 'Vandebijlpark', 'Gauteng', '1911', 'Capitec', '432563456', '250655', 'Thank You for Your Business', 'Thank You for Your Business\r\nAll Quotes 30 days only...', '#5b6067', '#a9a6b9', 'classic', 1, 1, 1, 1, 1, 0, 1, 'system-ui', 'South Africa', 'construction', 2, 3, 2, 1, 'test', NULL, NULL, NULL, NULL, '2025-09-30 09:01:54', '2025-10-20 15:44:45');

-- --------------------------------------------------------

--
-- Table structure for table `company_settings`
--

CREATE TABLE `company_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `company_settings`
--

INSERT INTO `company_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 1, 'crm_block_expired_suppliers', '0', '2025-10-02 21:58:56'),
(2, 1, 'crm_notify_expiring', '1', '2025-10-02 21:58:56'),
(3, 1, 'crm_reminder_days', '30,14,7', '2025-10-02 21:58:56'),
(4, 1, 'finance_fiscal_year_start', 'February', '2025-10-08 14:37:53'),
(5, 1, 'finance_ar_account_id', '5', '2025-10-08 14:37:53'),
(6, 1, 'finance_ap_account_id', '13', '2025-10-08 14:37:53'),
(7, 1, 'finance_vat_output_account_id', '14', '2025-10-08 14:37:53'),
(8, 1, 'finance_vat_input_account_id', '15', '2025-10-08 14:37:53'),
(9, 1, 'finance_sales_account_id', '26', '2025-10-08 14:37:53'),
(10, 1, 'finance_cogs_account_id', '29', '2025-10-08 14:37:53'),
(11, 1, 'finance_inventory_account_id', '6', '2025-10-08 14:37:53'),
(12, 1, 'receipts_ai_map', '', '2025-10-06 13:25:43'),
(56, 1, 'receipts_widgets_layout', '[{\"id\":1,\"widget_key\":\"bank_matches_pending\",\"size\":\"sm\",\"config\":[]},{\"id\":2,\"widget_key\":\"recent_receipts\",\"size\":\"md\",\"config\":[]},{\"id\":3,\"widget_key\":\"top_suppliers_90d\",\"size\":\"sm\",\"config\":[]},{\"id\":4,\"widget_key\":\"top_suppliers_90d\",\"size\":\"md\",\"config\":[]},{\"id\":5,\"widget_key\":\"top_suppliers_90d\",\"size\":\"sm\",\"config\":[]},{\"id\":6,\"widget_key\":\"cost_by_project\",\"size\":\"sm\",\"config\":[]}]', '2025-10-07 00:41:05');

-- --------------------------------------------------------

--
-- Table structure for table `cost_items`
--

CREATE TABLE `cost_items` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `type` enum('material','labour','equipment','other') NOT NULL DEFAULT 'material',
  `description` text NOT NULL,
  `qty` decimal(15,3) NOT NULL DEFAULT 1.000,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_code` varchar(32) NOT NULL DEFAULT 'standard',
  `supplier_id` bigint(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_notes`
--

CREATE TABLE `credit_notes` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `credit_note_number` varchar(50) NOT NULL,
  `invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `issue_date` date NOT NULL,
  `status` enum('draft','sent','approved','applied','cancelled') NOT NULL DEFAULT 'draft',
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `tax` decimal(15,2) DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `journal_id` bigint(20) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_note_allocations`
--

CREATE TABLE `credit_note_allocations` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `credit_note_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_note_lines`
--

CREATE TABLE `credit_note_lines` (
  `id` int(10) UNSIGNED NOT NULL,
  `credit_note_id` int(10) UNSIGNED NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `unit` varchar(50) DEFAULT 'ea',
  `unit_price` decimal(15,2) NOT NULL,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 15.00,
  `line_total` decimal(15,2) NOT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_accounts`
--

CREATE TABLE `crm_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `type` enum('supplier','customer') NOT NULL,
  `name` varchar(255) NOT NULL,
  `legal_name` varchar(255) DEFAULT NULL,
  `reg_no` varchar(64) DEFAULT NULL,
  `vat_no` varchar(64) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `industry_id` int(10) UNSIGNED DEFAULT NULL,
  `region_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive','prospect','banned') NOT NULL DEFAULT 'active',
  `revenue_cents` bigint(20) UNSIGNED DEFAULT 0,
  `ar_overdue_cents` bigint(20) UNSIGNED DEFAULT 0,
  `last_invoice_at` datetime DEFAULT NULL,
  `on_time_percent` decimal(5,2) DEFAULT NULL,
  `avg_response_hours` decimal(7,2) DEFAULT NULL,
  `defect_rate_percent` decimal(5,2) DEFAULT NULL,
  `preferred` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `crm_accounts`
--

INSERT INTO `crm_accounts` (`id`, `company_id`, `type`, `name`, `legal_name`, `reg_no`, `vat_no`, `phone`, `email`, `website`, `industry_id`, `region_id`, `status`, `revenue_cents`, `ar_overdue_cents`, `last_invoice_at`, `on_time_percent`, `avg_response_hours`, `defect_rate_percent`, `preferred`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'supplier', 'Adriaan', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-01 16:52:48', '2025-10-01 16:52:48'),
(2, 1, 'customer', 'Build it Mosselbay', 'Boerewors Beleggers PTY LTD', '2016/123456/09', '4234567891', '+27440100096', 'adriaan@prositeconstruction.co.za', 'https://www.buildit.co.za/', 1, 2, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-01 19:34:16', '2025-10-05 11:05:57'),
(3, 1, 'supplier', 'Joe Plumbing', NULL, NULL, NULL, '0821234567', 'joe@plumbing.co.za', NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-02 12:26:04', '2025-10-02 12:26:04'),
(4, 1, 'supplier', 'ABCD Electrical', 'ABC Electrical PTY LTD', '2015/654321/07', '41234987568', '+27837654321', 'info@abcelectrical.co.za', NULL, 1, 1, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-02 12:26:04', '2025-10-16 13:21:51'),
(5, 1, 'supplier', 'Vanderbijlpark Plumbing Services', NULL, NULL, NULL, '0821234567', 'info@vbplumbing.co.za', NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-02 13:22:51', '2025-10-02 13:22:51'),
(6, 1, 'supplier', 'Quick Plumbers ZA', NULL, NULL, NULL, '0837654321', 'quotes@quickplumbers.co.za', NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 1, NULL, 1, '2025-10-02 13:22:51', '2025-10-02 13:22:51'),
(7, 1, 'supplier', 'Elite Plumbing & Geysers', NULL, NULL, NULL, '0612345678', 'contact@eliteplumbing.co.za', NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-02 13:22:51', '2025-10-02 13:22:51'),
(8, 1, 'supplier', 'Joe Electrical Services', NULL, NULL, NULL, '0829876543', 'joe@electrical.co.za', NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-02 13:22:51', '2025-10-02 13:22:51'),
(9, 1, 'supplier', 'ABC General Contractor', '', '', '', '0813456789', 'info@abccontractors.co.za', '', NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 1, '', 1, '2025-10-02 13:22:51', '2025-10-16 12:27:44'),
(10, 1, 'customer', 'sdfsdfsd', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-04 13:22:29', '2025-10-04 13:22:29'),
(11, 1, 'supplier', 'Test Company 1759581546', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-04 14:39:06', '2025-10-04 14:39:06'),
(12, 1, 'supplier', 'wqdqwd', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 1, '2025-10-04 21:33:11', '2025-10-04 21:38:42'),
(13, 1, 'customer', 'Hallmart', 'Sea Sponge PTY LTD', '2021/984799/07', '4810302168', NULL, NULL, NULL, 13, 1, 'active', 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 3, '2025-10-20 15:42:57', '2025-10-20 15:42:57');

-- --------------------------------------------------------

--
-- Table structure for table `crm_account_tags`
--

CREATE TABLE `crm_account_tags` (
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `tag_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_addresses`
--

CREATE TABLE `crm_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('billing','shipping','site','head_office') NOT NULL DEFAULT 'head_office',
  `line1` varchar(255) NOT NULL,
  `line2` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `region` varchar(120) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` char(2) DEFAULT 'ZA',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `crm_addresses`
--

INSERT INTO `crm_addresses` (`id`, `company_id`, `account_id`, `type`, `line1`, `line2`, `city`, `region`, `postal_code`, `country`, `created_at`, `updated_at`) VALUES
(2, 1, 10, 'head_office', '34', '345345', 'Vandebijlpark', 'Gauteng', '1900', 'ZA', '2025-10-04 13:23:02', '2025-10-04 13:23:02'),
(3, 1, 4, 'shipping', '112', 'Airline Str', 'Boxburg', 'Gauteng', '1231', 'ZA', '2025-10-05 12:30:46', '2025-10-05 12:30:46'),
(4, 1, 9, 'head_office', 'aaa', 'aaa', 'aaa', 'aaa', '1111', 'ZA', '2025-10-16 12:27:20', '2025-10-16 12:27:20'),
(5, 1, 9, 'head_office', 'aaa2', 'aaa', 'aaa', 'aaa', '1111', 'ZA', '2025-10-16 12:42:30', '2025-10-16 12:42:30'),
(6, 1, 9, 'head_office', 'aaa22', 'aaa', 'aaa', 'aaa', '1111', 'ZA', '2025-10-16 12:42:41', '2025-10-16 12:42:41'),
(7, 1, 9, 'head_office', 'aaav', 'aaa', 'aaa', 'aaa', '1111', 'ZA', '2025-10-16 12:58:30', '2025-10-16 12:58:30'),
(8, 1, 13, 'head_office', '47 Westbourne Road', NULL, 'Bryanston, Sandton', NULL, '2191', 'ZA', '2025-10-20 15:42:57', '2025-10-20 15:42:57');

-- --------------------------------------------------------

--
-- Table structure for table `crm_compliance_docs`
--

CREATE TABLE `crm_compliance_docs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `type_id` smallint(5) UNSIGNED NOT NULL,
  `reference_no` varchar(190) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `status` enum('valid','expiring','expired','missing') NOT NULL DEFAULT 'valid',
  `verified_by` int(10) UNSIGNED DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `crm_compliance_docs`
--

INSERT INTO `crm_compliance_docs` (`id`, `company_id`, `account_id`, `type_id`, `reference_no`, `issue_date`, `expiry_date`, `file_path`, `status`, `verified_by`, `verified_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 'COC-2024-001', '2024-01-01', '2025-12-31', NULL, 'valid', NULL, NULL, '2025-10-02 12:26:05', '2025-10-02 12:26:05'),
(5, 1, 4, 3, '23452345', '2025-10-23', '2026-10-23', '/uploads/company/1/compliance/doc_4_3_1759580796.jpeg', 'valid', NULL, NULL, '2025-10-04 14:26:36', '2025-10-04 14:26:36'),
(6, 1, 4, 1, '243563456', '2025-10-17', '2026-10-17', '/uploads/company/1/compliance/doc_4_1_1759657641.jpg', 'valid', NULL, NULL, '2025-10-05 11:47:21', '2025-10-05 11:47:21'),
(7, 1, 9, 3, 'aassdsa', NULL, '2025-10-30', '/uploads/company/1/compliance/doc_9_3_1760611588.jpg', 'expiring', NULL, NULL, '2025-10-16 12:46:28', '2025-10-16 12:46:28');

-- --------------------------------------------------------

--
-- Table structure for table `crm_compliance_types`
--

CREATE TABLE `crm_compliance_types` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `default_expiry_months` smallint(5) UNSIGNED DEFAULT 12,
  `required` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `crm_compliance_types`
--

INSERT INTO `crm_compliance_types` (`id`, `company_id`, `code`, `name`, `default_expiry_months`, `required`) VALUES
(1, 1, 'COC', 'Certificate of Compliance', 12, 1),
(2, 1, 'TAX', 'Tax Clearance Certificate', 12, 1),
(3, 1, 'BEE', 'BEE Certificate', 12, 0),
(4, 1, 'INSURANCE', 'Liability Insurance', 12, 1),
(5, 1, 'CIDB', 'CIDB Registration', 36, 0),
(6, 1, 'NHBRC', 'NHBRC Registration', 12, 0),
(7, 1, 'COIDA', 'Workman Compensation (COIDA)', 12, 1),
(8, 1, 'VAT', 'VAT Registration Certificate', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `crm_contacts`
--

CREATE TABLE `crm_contacts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(120) DEFAULT NULL,
  `role_title` varchar(120) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `crm_contacts`
--

INSERT INTO `crm_contacts` (`id`, `company_id`, `account_id`, `first_name`, `last_name`, `role_title`, `phone`, `email`, `is_primary`, `created_by`, `created_at`, `updated_at`) VALUES
(3, 1, 4, 'Adriaan', 'Geldenhuis', 'Director', '+273246235235', 'a@a.co.za', 0, 1, '2025-10-04 13:12:48', '2025-10-04 13:12:48'),
(4, 1, 10, 'a', 'a', 'sdasds', '+271345134', 'dsadad@saddasd.co.za', 0, 1, '2025-10-04 13:22:45', '2025-10-04 13:22:45'),
(6, 1, 2, 'Christo', 'Wessels', 'Maneging Director', '+27616241190', 'christow@mosselbi.co.za', 0, 1, '2025-10-05 12:19:14', '2025-10-05 12:19:14'),
(7, 1, 9, 'Adriaan', 'Geldenhuis', 'Director', '+2736745676745', 'a@a.co.za', 0, 1, '2025-10-16 12:47:15', '2025-10-16 12:47:25'),
(8, 1, 9, 'Adriaan1', 'Geldenhuis', 'Director', '+2736745676745', 'a@a.co.za', 1, 1, '2025-10-16 12:47:25', '2025-10-16 12:47:25'),
(9, 1, 2, 'Christo', 'Wessel', 'Maneging Director', '+27616241190', 'christow@mosselbi.co.za', 0, 1, '2025-10-16 16:38:06', '2025-10-16 16:38:06'),
(10, 1, 13, 'Devin', NULL, 'Manager', '+27828326604', 'devin@devico.za', 1, 3, '2025-10-20 15:42:57', '2025-10-20 15:42:57');

-- --------------------------------------------------------

--
-- Table structure for table `crm_import_history`
--

CREATE TABLE `crm_import_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `filename` varchar(255) NOT NULL,
  `type` enum('accounts','contacts','addresses') NOT NULL,
  `total_rows` int(10) UNSIGNED NOT NULL,
  `successful_rows` int(10) UNSIGNED DEFAULT 0,
  `failed_rows` int(10) UNSIGNED DEFAULT 0,
  `status` enum('pending','processing','completed','failed','rolled_back') NOT NULL DEFAULT 'pending',
  `error_log` mediumtext DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_industries`
--

CREATE TABLE `crm_industries` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `crm_industries`
--

INSERT INTO `crm_industries` (`id`, `name`) VALUES
(11, 'Aerospace & Defense'),
(7, 'Agriculture'),
(10, 'AgriTech'),
(12, 'Airlines & Aviation'),
(14, 'Auto Parts & Services'),
(13, 'Automotive'),
(33, 'Banking'),
(72, 'Beauty & Personal Care'),
(15, 'Biotech'),
(34, 'Capital Markets & Investment Management'),
(16, 'Chemicals'),
(17, 'Civil Engineering'),
(18, 'Commercial & Residential Construction'),
(1, 'Construction'),
(21, 'Consumer Durables'),
(19, 'Consumer Electronics'),
(20, 'Consumer Goods (Non-Durables)'),
(48, 'Cybersecurity'),
(49, 'Data & Analytics / AI'),
(22, 'Design & Creative Services'),
(24, 'EdTech'),
(23, 'Education'),
(25, 'Energy (Oil & Gas)'),
(26, 'Energy Trading & Services'),
(29, 'Engineering Services'),
(30, 'Entertainment & Media'),
(31, 'Events & Conferences'),
(32, 'Financial Services'),
(35, 'FinTech'),
(36, 'Food & Beverage'),
(37, 'Forestry & Paper'),
(38, 'Government & Public Sector'),
(47, 'Hardware & Semiconductors'),
(39, 'Healthcare Providers'),
(73, 'Home & Garden'),
(4, 'Hospitality'),
(42, 'Hospitality & Lodging'),
(45, 'Information Technology (IT Services)'),
(44, 'Insurance'),
(50, 'Internet & eCommerce'),
(51, 'Legal Services'),
(52, 'Logistics & Supply Chain'),
(2, 'Manufacturing'),
(54, 'Maritime & Shipping'),
(40, 'Medical Devices'),
(55, 'Metals & Materials'),
(6, 'Mining'),
(56, 'NGO / Non-Profit'),
(9, 'Other'),
(57, 'Packaging & Printing'),
(41, 'Pharmaceuticals'),
(58, 'Professional Services & Consulting'),
(59, 'Property Development'),
(60, 'Real Estate (Sales & Rentals)'),
(27, 'Renewable Energy & Solar'),
(75, 'Research & Development'),
(3, 'Retail'),
(61, 'Retail (Brick-and-Mortar)'),
(62, 'Retail (eCommerce)'),
(63, 'Security Services'),
(46, 'Software'),
(64, 'Sports & Recreation'),
(74, 'Staffing & HR Services'),
(8, 'Technology'),
(65, 'Telecommunications'),
(66, 'Textiles & Apparel'),
(5, 'Transport'),
(69, 'Transportation (Air)'),
(68, 'Transportation (Rail)'),
(67, 'Transportation (Road)'),
(70, 'Transportation (Sea)'),
(43, 'Travel & Tourism'),
(28, 'Utilities'),
(53, 'Warehousing & Fulfilment'),
(71, 'Waste Management & Recycling');

-- --------------------------------------------------------

--
-- Table structure for table `crm_interactions`
--

CREATE TABLE `crm_interactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `type` enum('email','call','visit','note','issue') NOT NULL,
  `subject` varchar(190) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `via` enum('mail','manual','import') NOT NULL DEFAULT 'manual',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `next_action_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `crm_interactions`
--

INSERT INTO `crm_interactions` (`id`, `company_id`, `account_id`, `contact_id`, `type`, `subject`, `body`, `via`, `created_by`, `created_at`, `next_action_at`) VALUES
(1, 1, 4, 3, 'email', 'asadfsd', 'g5w4gsegrsdfg', 'manual', 1, '2025-10-04 13:17:15', '2025-10-04 00:00:00'),
(2, 1, 2, 6, 'email', 'Payments', 'sdfcadcas', 'manual', 1, '2025-10-05 12:22:16', '2025-10-05 13:00:00'),
(3, 1, 9, 8, 'email', 'vv', 'vv', 'manual', 1, '2025-10-16 12:51:54', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `crm_merge_candidates`
--

CREATE TABLE `crm_merge_candidates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `left_id` bigint(20) UNSIGNED NOT NULL,
  `right_id` bigint(20) UNSIGNED NOT NULL,
  `match_score` decimal(5,2) NOT NULL,
  `reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reason`)),
  `resolved` tinyint(1) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_opportunities`
--

CREATE TABLE `crm_opportunities` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) NOT NULL,
  `account_id` bigint(20) NOT NULL,
  `title` varchar(190) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `stage` enum('prospect','qualification','proposal','negotiation','won','lost') NOT NULL DEFAULT 'prospect',
  `probability` decimal(5,2) NOT NULL DEFAULT 0.00,
  `close_date` date DEFAULT NULL,
  `owner_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_regions`
--

CREATE TABLE `crm_regions` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `crm_regions`
--

INSERT INTO `crm_regions` (`id`, `name`) VALUES
(4, 'Eastern Cape'),
(5, 'Free State'),
(1, 'Gauteng'),
(3, 'KwaZulu-Natal'),
(6, 'Limpopo'),
(7, 'Mpumalanga'),
(8, 'North West'),
(9, 'Northern Cape'),
(2, 'Western Cape'),
(10, 'Botswana'),
(11, 'Lesotho'),
(12, 'Mozambique'),
(13, 'Namibia'),
(14, 'eSwatini'),
(15, 'Zimbabwe'),
(16, 'Zambia');

-- --------------------------------------------------------

--
-- Table structure for table `crm_tags`
--

CREATE TABLE `crm_tags` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(64) NOT NULL,
  `color` varchar(20) DEFAULT '#8b5cf6'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `depreciation_lines`
--

CREATE TABLE `depreciation_lines` (
  `id` int(10) UNSIGNED NOT NULL,
  `run_id` int(10) UNSIGNED NOT NULL,
  `asset_id` int(10) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `dep_cents` bigint(20) NOT NULL DEFAULT 0,
  `accum_dep_before_cents` bigint(20) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `depreciation_runs`
--

CREATE TABLE `depreciation_runs` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `run_date` date NOT NULL,
  `journal_id` int(10) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doc_sequences`
--

CREATE TABLE `doc_sequences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `period_key` varchar(12) NOT NULL,
  `prefix` varchar(64) NOT NULL,
  `pad` tinyint(3) UNSIGNED NOT NULL DEFAULT 4,
  `last_number` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `drive_files`
-- (See below for the actual view)
--
CREATE TABLE `drive_files` (
`node_id` int(11)
,`storage_path` varchar(512)
,`mime_type` varchar(127)
,`size` bigint(20)
,`original_name` varchar(288)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `drive_nodes`
-- (See below for the actual view)
--
CREATE TABLE `drive_nodes` (
`id` int(11)
,`company_id` int(11)
,`drive_id` int(11)
,`name` varchar(255)
,`is_deleted` int(1)
);

-- --------------------------------------------------------

--
-- Table structure for table `emails`
--

CREATE TABLE `emails` (
  `email_id` bigint(20) NOT NULL,
  `company_id` int(10) NOT NULL,
  `account_id` bigint(20) NOT NULL,
  `thread_id` bigint(20) DEFAULT NULL,
  `imap_uid` bigint(20) DEFAULT NULL,
  `folder` varchar(255) DEFAULT 'INBOX',
  `direction` enum('incoming','outgoing') DEFAULT 'incoming',
  `sender` varchar(500) DEFAULT NULL,
  `to_recipients` text DEFAULT NULL,
  `cc_recipients` text DEFAULT NULL,
  `bcc_recipients` text DEFAULT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `body_text` mediumtext DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_starred` tinyint(1) DEFAULT 0,
  `has_attachments` tinyint(1) DEFAULT 0,
  `message_id` varchar(255) DEFAULT NULL,
  `in_reply_to` varchar(255) DEFAULT NULL,
  `ref_message_ids` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_accounts`
--

CREATE TABLE `email_accounts` (
  `account_id` bigint(20) NOT NULL,
  `company_id` int(10) NOT NULL,
  `user_id` int(10) NOT NULL,
  `account_name` varchar(120) NOT NULL,
  `email_address` varchar(190) NOT NULL,
  `imap_server` varchar(255) DEFAULT NULL,
  `imap_port` int(11) DEFAULT 993,
  `imap_encryption` enum('ssl','tls','none') DEFAULT 'ssl',
  `pop3_server` varchar(255) DEFAULT NULL,
  `pop3_port` int(11) DEFAULT 995,
  `pop3_encryption` enum('ssl','tls','none') DEFAULT 'ssl',
  `smtp_server` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `smtp_encryption` enum('ssl','tls','none') DEFAULT 'tls',
  `username` varchar(190) DEFAULT NULL,
  `password_encrypted` text DEFAULT NULL,
  `oauth_provider` varchar(64) DEFAULT NULL,
  `oauth_token` text DEFAULT NULL,
  `oauth_refresh_token` text DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_accounts`
--

INSERT INTO `email_accounts` (`account_id`, `company_id`, `user_id`, `account_name`, `email_address`, `imap_server`, `imap_port`, `imap_encryption`, `pop3_server`, `pop3_port`, `pop3_encryption`, `smtp_server`, `smtp_port`, `smtp_encryption`, `username`, `password_encrypted`, `oauth_provider`, `oauth_token`, `oauth_refresh_token`, `last_sync_at`, `last_error`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Adriaan Geldenhuis', 'adriaan@concreto.co.za', 'mail.concreto.co.za', 993, 'ssl', NULL, 995, 'ssl', 'ssmtpconcreto.co.za', 587, 'tls', 'adriaan@concreto.co.za', 'QWRyaWFudXMxMjM0IQ==', NULL, NULL, NULL, NULL, NULL, 1, '2025-10-02 15:31:52', '2025-10-02 15:31:52');

-- --------------------------------------------------------

--
-- Table structure for table `email_attachments`
--

CREATE TABLE `email_attachments` (
  `attachment_id` bigint(20) NOT NULL,
  `email_id` bigint(20) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_links`
--

CREATE TABLE `email_links` (
  `link_id` bigint(20) NOT NULL,
  `email_id` bigint(20) NOT NULL,
  `linked_type` enum('project','board','item','quote','invoice','credit_note','rfq','po','supplier','customer','calendar_event') NOT NULL,
  `linked_id` bigint(20) NOT NULL,
  `created_by` int(10) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_links`
--

INSERT INTO `email_links` (`link_id`, `email_id`, `linked_type`, `linked_id`, `created_by`, `created_at`) VALUES
(1, 1, 'invoice', 1, 1, '2025-10-05 15:52:20');

-- --------------------------------------------------------

--
-- Table structure for table `email_rules`
--

CREATE TABLE `email_rules` (
  `rule_id` int(10) NOT NULL,
  `company_id` int(10) NOT NULL,
  `user_id` int(10) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `priority` int(11) DEFAULT 100,
  `conditions_json` longtext DEFAULT NULL,
  `actions_json` longtext DEFAULT NULL,
  `stop_processing` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_rules`
--

INSERT INTO `email_rules` (`rule_id`, `company_id`, `user_id`, `name`, `priority`, `conditions_json`, `actions_json`, `stop_processing`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 0, NULL, 'Auto-tag RFQ', 100, '{\"contains\":[\"rfq\",\"request for quotation\",\"quote needed\"]}', '{\"add_tags\":[\"RFQ\"],\"assign_role\":\"procurement\",\"sla_hours\":8}', 0, 1, '2025-10-06 18:51:39', '2025-10-06 18:51:39'),
(2, 0, NULL, 'Invoice queries', 100, '{\"contains\":[\"invoice\",\"inv\",\"statement\"]}', '{\"add_tags\":[\"Invoice\"],\"assign_role\":\"finance\",\"sla_hours\":12}', 0, 1, '2025-10-06 18:51:39', '2025-10-06 18:51:39'),
(3, 0, NULL, 'Payment proof', 100, '{\"contains\":[\"proof of payment\",\"pop\",\"eft\",\"bank confirmation\"]}', '{\"add_tags\":[\"Payment Proof\"],\"assign_role\":\"finance\",\"sla_hours\":4}', 0, 1, '2025-10-06 18:51:39', '2025-10-06 18:51:39');

-- --------------------------------------------------------

--
-- Table structure for table `email_send_log`
--

CREATE TABLE `email_send_log` (
  `log_id` bigint(20) NOT NULL,
  `email_id` bigint(20) NOT NULL,
  `status` enum('sent','failed','bounced') DEFAULT 'sent',
  `message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_signatures`
--

CREATE TABLE `email_signatures` (
  `signature_id` int(10) NOT NULL,
  `company_id` int(10) NOT NULL,
  `user_id` int(10) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `content_html` text DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_sync_log`
--

CREATE TABLE `email_sync_log` (
  `log_id` bigint(20) NOT NULL,
  `account_id` bigint(20) NOT NULL,
  `folder` varchar(255) DEFAULT NULL,
  `status` enum('success','error') DEFAULT 'success',
  `message` text DEFAULT NULL,
  `messages_synced` int(11) DEFAULT 0,
  `started_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_tags`
--

CREATE TABLE `email_tags` (
  `tag_id` int(10) NOT NULL,
  `company_id` int(10) NOT NULL,
  `name` varchar(64) NOT NULL,
  `color` varchar(20) DEFAULT '#650000',
  `color_hex` varchar(7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_tags`
--

INSERT INTO `email_tags` (`tag_id`, `company_id`, `name`, `color`, `color_hex`) VALUES
(1, 1, 'RFQ', '#f59e0b', NULL),
(2, 1, 'Invoice', '#10b981', NULL),
(3, 1, 'Payment Proof', '#06b6d4', NULL),
(4, 1, 'Urgent', '#ef4444', NULL),
(5, 1, 'Follow-up', '#8b5cf6', NULL),
(8, 0, 'RFQ', '#650000', '#6b5cff'),
(9, 0, 'Invoice', '#650000', '#10b981'),
(10, 0, 'Payment Proof', '#650000', '#ef4444'),
(11, 0, 'Urgent', '#650000', '#f59e0b'),
(12, 0, 'Follow-up', '#650000', '#3b82f6');

-- --------------------------------------------------------

--
-- Table structure for table `email_tag_map`
--

CREATE TABLE `email_tag_map` (
  `email_id` bigint(20) NOT NULL,
  `tag_id` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `template_id` int(10) NOT NULL,
  `company_id` int(10) NOT NULL,
  `name` varchar(120) NOT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `category` varchar(64) DEFAULT NULL,
  `created_by` int(10) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_threads`
--

CREATE TABLE `email_threads` (
  `thread_id` bigint(20) NOT NULL,
  `company_id` int(10) NOT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `status` enum('open','pending','closed') NOT NULL DEFAULT 'open',
  `first_incoming_at` datetime DEFAULT NULL,
  `first_response_at` datetime DEFAULT NULL,
  `last_agent_reply_at` datetime DEFAULT NULL,
  `subject` varchar(500) NOT NULL,
  `subject_norm` varchar(500) NOT NULL,
  `last_message_at` datetime NOT NULL,
  `is_closed` tinyint(1) DEFAULT 0,
  `sla_due_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `employee_no` varchar(32) NOT NULL,
  `first_name` varchar(120) NOT NULL,
  `last_name` varchar(120) NOT NULL,
  `id_number` varchar(32) DEFAULT NULL,
  `tax_number` varchar(32) DEFAULT NULL,
  `uif_included` tinyint(1) NOT NULL DEFAULT 1,
  `sdl_included` tinyint(1) NOT NULL DEFAULT 1,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `hire_date` date NOT NULL,
  `termination_date` date DEFAULT NULL,
  `termination_reason` varchar(190) DEFAULT NULL,
  `employment_type` enum('permanent','contract','casual') NOT NULL DEFAULT 'permanent',
  `pay_frequency` enum('monthly','fortnight','weekly') NOT NULL DEFAULT 'monthly',
  `base_salary_cents` bigint(20) NOT NULL DEFAULT 0,
  `hourly_rate_cents` bigint(20) NOT NULL DEFAULT 0,
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account_no` varchar(64) DEFAULT NULL,
  `branch_code` varchar(32) DEFAULT NULL,
  `default_project_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `company_id`, `employee_no`, `first_name`, `last_name`, `id_number`, `tax_number`, `uif_included`, `sdl_included`, `email`, `phone`, `hire_date`, `termination_date`, `termination_reason`, `employment_type`, `pay_frequency`, `base_salary_cents`, `hourly_rate_cents`, `bank_name`, `bank_account_no`, `branch_code`, `default_project_id`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'con.001', 'Adriaan', 'Geldenhuis', '8407235058087', '', 1, 1, 'adriaangeldenhuis2@gamil.com', '0723574172', '2025-10-01', NULL, NULL, 'permanent', 'monthly', 1500000, 0, 'Capitec', '4177892453', '250422', NULL, 1, '2025-10-04 23:10:58', '2025-10-04 23:10:58');

-- --------------------------------------------------------

--
-- Table structure for table `employee_payitems`
--

CREATE TABLE `employee_payitems` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `payitem_id` int(10) UNSIGNED NOT NULL,
  `amount_cents` bigint(20) NOT NULL DEFAULT 0,
  `unit` enum('fixed','hour','day','percent') NOT NULL DEFAULT 'fixed',
  `rate_cents` bigint(20) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fa_depreciation_lines`
--

CREATE TABLE `fa_depreciation_lines` (
  `id` bigint(20) NOT NULL,
  `run_id` bigint(20) NOT NULL,
  `asset_id` bigint(20) NOT NULL,
  `amount_cents` bigint(20) NOT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fa_depreciation_runs`
--

CREATE TABLE `fa_depreciation_runs` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `run_month` date NOT NULL,
  `status` enum('draft','posted') DEFAULT 'draft',
  `journal_id` bigint(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fa_disposals`
--

CREATE TABLE `fa_disposals` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `asset_id` bigint(20) NOT NULL,
  `disposal_date` date NOT NULL,
  `proceeds_cents` bigint(20) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `journal_id` bigint(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `fa_disposals`
--
DELIMITER $$
CREATE TRIGGER `fa_disposals_bi` BEFORE INSERT ON `fa_disposals` FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM gl_fixed_assets WHERE asset_id = NEW.asset_id) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Asset not found for fa_disposals.asset_id';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `fd_audit`
--

CREATE TABLE `fd_audit` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `drive_id` int(11) DEFAULT NULL,
  `node_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_blobs`
--

CREATE TABLE `fd_blobs` (
  `id` int(11) NOT NULL,
  `sha256` char(64) NOT NULL,
  `size_bytes` bigint(20) NOT NULL,
  `data` longblob DEFAULT NULL,
  `mime_type` varchar(127) NOT NULL,
  `storage_path` varchar(512) NOT NULL COMMENT 'Relative to upload dir',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `fd_blobs`
--

INSERT INTO `fd_blobs` (`id`, `sha256`, `size_bytes`, `data`, `mime_type`, `storage_path`, `created_at`) VALUES
(1, 'a60e46abc9b98029715a5e2a6195be8aaf06ffe126d77ec9f26d30cccc0e90d0', 211608, NULL, 'image/jpeg', '2025/10/17/a60e46abc9b98029715a5e2a6195be8aaf06ffe126d77ec9f26d30cccc0e90d0', '2025-10-17 23:51:25'),
(2, '452d27d6305d0cec60f73fda44e2eb81a38d5e2d2886e9aae2162895e0fa758c', 40920, NULL, 'image/jpeg', '2025/10/18/452d27d6305d0cec60f73fda44e2eb81a38d5e2d2886e9aae2162895e0fa758c', '2025-10-18 00:01:44'),
(3, '7cbd60309a7e0a3dca9d3612b605e5dc0bb645aa7299c8b1023393f77219a02d', 236884, NULL, 'image/jpeg', '2025/10/18/7cbd60309a7e0a3dca9d3612b605e5dc0bb645aa7299c8b1023393f77219a02d', '2025-10-18 00:13:43'),
(4, 'b634d3670fc34132310d7a7937fac6d16a7f0d33b4aed6e42197bcde006bb2f0', 212175, NULL, 'image/jpeg', '2025/10/18/b634d3670fc34132310d7a7937fac6d16a7f0d33b4aed6e42197bcde006bb2f0', '2025-10-18 00:20:12'),
(5, '967fd8565cb2b11455c3624cb7eb3b4a90fa77cdc3dd5387acdbeee6c98af75e', 28, 0x46616b652050444620636f6e74656e7420666f722074657374696e67, '', '', '2025-10-18 17:54:18'),
(7, 'c8ae6a81f1e28f9c342a6c760e169591ee753c5d57a1096b746473346c80b3c5', 11573, NULL, 'text/html', '2025/10/18/c8ae6a81f1e28f9c342a6c760e169591ee753c5d57a1096b746473346c80b3c5', '2025-10-18 19:12:04'),
(9, 'e35f01a6cc9cf9d23e5bafead401586e6e09065a4ba93ce901d3f5709b432de8', 580, NULL, 'text/plain', '2025/10/18/e35f01a6cc9cf9d23e5bafead401586e6e09065a4ba93ce901d3f5709b432de8', '2025-10-18 20:47:52');

-- --------------------------------------------------------

--
-- Table structure for table `fd_drives`
--

CREATE TABLE `fd_drives` (
  `id` int(11) NOT NULL,
  `type` enum('company','my') NOT NULL DEFAULT 'company',
  `subtype` varchar(50) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `owner_user_id` int(11) DEFAULT NULL COMMENT 'For My Drive',
  `name` varchar(255) NOT NULL,
  `disk_path` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `fd_drives`
--

INSERT INTO `fd_drives` (`id`, `type`, `subtype`, `company_id`, `owner_user_id`, `name`, `disk_path`, `created_at`, `updated_at`) VALUES
(2, 'my', NULL, 1, 1, 'My Drive', '/storage/1/users/1/', '2025-10-17 13:23:42', '2025-10-18 17:25:13'),
(3, 'my', NULL, 1, 3, 'My Drive', '/storage/1/users/3/', '2025-10-17 13:23:42', '2025-10-18 17:25:13'),
(5, 'company', 'shared', 1, NULL, 'Company Drive 1', '/storage/1/company/', '2025-10-17 13:24:24', '2025-10-18 17:25:13'),
(6, 'company', 'flowwork', 1, NULL, 'FlowWork Drive', '/storage/1/flowwork/', '2025-10-18 17:32:17', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `fd_nodes`
--

CREATE TABLE `fd_nodes` (
  `id` int(11) NOT NULL,
  `drive_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL COMMENT 'NULL = root',
  `type` enum('folder','file') NOT NULL,
  `name` varchar(255) NOT NULL,
  `disk_path` varchar(500) DEFAULT NULL,
  `ext` varchar(32) NOT NULL DEFAULT '',
  `blob_id` int(11) DEFAULT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_readonly` tinyint(1) NOT NULL DEFAULT 0,
  `visibility` enum('visible','hidden') NOT NULL DEFAULT 'visible',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`))
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `fd_nodes`
--

INSERT INTO `fd_nodes` (`id`, `drive_id`, `parent_id`, `type`, `name`, `disk_path`, `ext`, `blob_id`, `size_bytes`, `is_system`, `is_readonly`, `visibility`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`, `meta_json`) VALUES
(1, 2, NULL, 'folder', '/', NULL, '', NULL, 0, 1, 0, 'visible', 1, NULL, '2025-10-17 13:23:58', '2025-10-18 15:27:10', NULL, NULL),
(2, 3, NULL, 'folder', '/', NULL, '', NULL, 0, 1, 0, 'visible', 3, NULL, '2025-10-17 13:23:58', '2025-10-18 15:27:10', NULL, NULL),
(4, 5, NULL, 'folder', '/', NULL, '', NULL, 0, 1, 0, 'visible', NULL, NULL, '2025-10-17 13:24:36', '2025-10-18 15:27:10', NULL, NULL),
(5, 2, NULL, 'folder', 'AAAA', NULL, '', NULL, 0, 0, 0, 'visible', 1, NULL, '2025-10-17 23:45:43', '2025-10-18 15:27:10', NULL, NULL),
(6, 5, NULL, 'file', '1', NULL, 'jpg', 1, 211608, 0, 0, 'visible', 1, 1, '2025-10-17 23:51:25', '2025-10-17 23:53:50', NULL, NULL),
(7, 5, NULL, 'file', '2', NULL, 'jpg', 2, 40920, 0, 0, 'visible', 1, 1, '2025-10-18 00:01:44', '2025-10-18 00:01:57', NULL, NULL),
(8, 5, NULL, 'file', '3', NULL, 'jpg', 3, 236884, 0, 0, 'visible', 1, 1, '2025-10-18 00:13:43', '2025-10-18 00:13:56', NULL, NULL),
(9, 2, 5, 'file', 'IMG-20251017-WA0008', NULL, 'jpg', 4, 212175, 0, 0, 'visible', 1, NULL, '2025-10-18 00:20:12', NULL, NULL, NULL),
(10, 2, 5, 'file', 'IMG-20251017-WA0008 (2)', NULL, 'jpg', 4, 212175, 0, 0, 'visible', 1, 1, '2025-10-18 00:20:13', '2025-10-18 15:27:33', '2025-10-18 11:14:51', NULL),
(11, 6, NULL, 'folder', '/', '/storage/1/flowwork/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:32:17', NULL, NULL, NULL),
(12, 6, 11, 'folder', 'internal', '/storage/1/flowwork/internal/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:32:17', NULL, NULL, NULL),
(13, 6, 12, 'folder', 'Payslips', '/storage/1/flowwork/internal/Payslips/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:32:17', NULL, NULL, NULL),
(14, 6, 12, 'folder', 'Reports', '/storage/1/flowwork/internal/Reports/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:32:17', NULL, NULL, NULL),
(15, 6, 12, 'folder', 'Timesheets', '/storage/1/flowwork/internal/Timesheets/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:32:17', NULL, NULL, NULL),
(16, 6, 12, 'folder', 'HR_Docs', '/storage/1/flowwork/internal/HR_Docs/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:32:17', NULL, NULL, NULL),
(17, 6, 11, 'folder', 'clients', '/storage/1/flowwork/clients/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:32:17', NULL, NULL, NULL),
(18, 6, 17, 'folder', '2', '/storage/1/flowwork/clients/2/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:39:41', NULL, NULL, NULL),
(19, 6, 18, 'folder', 'Quotes', '/storage/1/flowwork/clients/2/Quotes/', '', NULL, 0, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:39:42', NULL, NULL, NULL),
(20, 6, 13, 'file', 'Test_Payslip_001', '/storage/1/flowwork/internal/Payslips/Test_Payslip_001.pdf', 'pdf', 5, 28, 1, 1, 'visible', NULL, NULL, '2025-10-18 17:54:18', NULL, NULL, NULL),
(22, 5, NULL, 'folder', 'AAAA', NULL, '', NULL, 0, 0, 0, 'visible', 1, NULL, '2025-10-18 18:44:12', NULL, NULL, NULL),
(23, 2, 5, 'file', 'DRAG DROP', NULL, 'html', 7, 11573, 0, 0, 'visible', 1, NULL, '2025-10-18 19:12:04', NULL, NULL, NULL),
(25, 2, 5, 'folder', 'bvbbb', NULL, '', NULL, 0, 0, 0, 'visible', 1, NULL, '2025-10-18 20:47:44', NULL, NULL, NULL),
(26, 2, 25, 'file', 'PHP ENTRIES', NULL, 'txt', 9, 580, 0, 0, 'visible', 1, NULL, '2025-10-18 20:47:52', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `fd_node_versions`
--

CREATE TABLE `fd_node_versions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `node_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `blob_id` int(11) NOT NULL,
  `size_bytes` bigint(20) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `change_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_permissions`
--

CREATE TABLE `fd_permissions` (
  `id` int(11) NOT NULL,
  `node_id` int(11) NOT NULL,
  `subject_type` enum('user','role','company') NOT NULL,
  `subject_id` int(11) NOT NULL COMMENT 'user.id | role key | company.id',
  `can_read` tinyint(1) NOT NULL DEFAULT 1,
  `can_write` tinyint(1) NOT NULL DEFAULT 0,
  `can_share` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `fd_permissions`
--

INSERT INTO `fd_permissions` (`id`, `node_id`, `subject_type`, `subject_id`, `can_read`, `can_write`, `can_share`, `created_at`) VALUES
(1, 1, 'user', 1, 1, 1, 1, '2025-10-17 13:24:12'),
(2, 2, 'user', 3, 1, 1, 1, '2025-10-17 13:24:12'),
(4, 4, 'company', 1, 1, 1, 1, '2025-10-17 13:24:48'),
(5, 11, 'company', 1, 1, 0, 0, '2025-10-18 17:32:17');

-- --------------------------------------------------------

--
-- Table structure for table `fd_shares`
--

CREATE TABLE `fd_shares` (
  `id` int(11) NOT NULL,
  `node_id` int(11) NOT NULL,
  `token` char(32) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `is_download_only` tinyint(1) NOT NULL DEFAULT 1,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_sync_state`
--

CREATE TABLE `fd_sync_state` (
  `table_name` varchar(64) NOT NULL,
  `last_id` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gl_accounts`
--

CREATE TABLE `gl_accounts` (
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(255) NOT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `tax_code_id` int(10) UNSIGNED DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gl_accounts`
--

INSERT INTO `gl_accounts` (`account_id`, `company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `tax_code_id`, `is_system`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, '1000', 'ASSETS', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(2, 1, '1100', 'Current Assets', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(3, 1, '1110', 'Bank Accounts', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(4, 1, '1120', 'Petty Cash', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(5, 1, '1200', 'Accounts Receivable', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(6, 1, '1300', 'Inventory', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(7, 1, '1400', 'Fixed Assets', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(8, 1, '1410', 'Equipment', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(9, 1, '1420', 'Vehicles', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(10, 1, '1490', 'Accumulated Depreciation', 'asset', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(11, 1, '2000', 'LIABILITIES', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(12, 1, '2100', 'Current Liabilities', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(13, 1, '2110', 'Accounts Payable', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(14, 1, '2120', 'VAT Output', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(15, 1, '2130', 'VAT Input', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(16, 1, '2140', 'PAYE Liability', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(17, 1, '2150', 'UIF Liability', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(18, 1, '2160', 'SDL Liability', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(19, 1, '2200', 'Long Term Liabilities', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(20, 1, '2210', 'Loans Payable', 'liability', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(21, 1, '3000', 'EQUITY', 'equity', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(22, 1, '3100', 'Share Capital', 'equity', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(23, 1, '3200', 'Retained Earnings', 'equity', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(24, 1, '3300', 'Current Year Earnings', 'equity', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(25, 1, '4000', 'REVENUE', 'revenue', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(26, 1, '4100', 'Sales Income', 'revenue', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(27, 1, '4200', 'Service Income', 'revenue', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(28, 1, '4900', 'Other Income', 'revenue', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(29, 1, '5000', 'COST OF SALES', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(30, 1, '5100', 'Direct Materials', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(31, 1, '5200', 'Direct Labour', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(32, 1, '6000', 'OPERATING EXPENSES', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(33, 1, '6100', 'Wages & Salaries', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(34, 1, '6200', 'Rent', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(35, 1, '6300', 'Utilities', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(36, 1, '6400', 'Telephone', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(37, 1, '6500', 'Insurance', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(38, 1, '6600', 'Motor Vehicle Expenses', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(39, 1, '6700', 'Bank Charges', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(40, 1, '6800', 'Depreciation', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(41, 1, '6900', 'General Expenses', 'expense', NULL, NULL, 1, 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46');

-- --------------------------------------------------------

--
-- Table structure for table `gl_bank_accounts`
--

CREATE TABLE `gl_bank_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `account_no` varchar(64) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'ZAR',
  `gl_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `opening_balance_cents` bigint(20) NOT NULL DEFAULT 0,
  `current_balance_cents` bigint(20) NOT NULL DEFAULT 0,
  `last_reconciled_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gl_bank_rules`
--

CREATE TABLE `gl_bank_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `rule_name` varchar(120) NOT NULL,
  `match_field` enum('description','reference','amount') NOT NULL DEFAULT 'description',
  `match_operator` enum('contains','starts_with','ends_with','equals','regex') NOT NULL DEFAULT 'contains',
  `match_value` varchar(255) NOT NULL,
  `gl_account_id` bigint(20) UNSIGNED NOT NULL,
  `tax_code_id` int(10) UNSIGNED DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `description_template` varchar(255) DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gl_bank_transactions`
--

CREATE TABLE `gl_bank_transactions` (
  `bank_tx_id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED NOT NULL,
  `tx_date` date NOT NULL,
  `description` varchar(500) NOT NULL,
  `amount_cents` bigint(20) NOT NULL,
  `balance_cents` bigint(20) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `matched` tinyint(1) NOT NULL DEFAULT 0,
  `journal_id` bigint(20) DEFAULT NULL,
  `import_batch_id` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gl_budgets`
--

CREATE TABLE `gl_budgets` (
  `budget_id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `gl_account_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `period_year` int(11) NOT NULL,
  `period_month` int(11) NOT NULL,
  `amount_cents` bigint(20) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gl_fixed_assets`
--

CREATE TABLE `gl_fixed_assets` (
  `asset_id` bigint(20) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `asset_name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `purchase_cost_cents` bigint(20) NOT NULL,
  `salvage_value_cents` bigint(20) NOT NULL DEFAULT 0,
  `useful_life_months` int(11) NOT NULL,
  `depreciation_method` enum('straight_line','declining_balance') NOT NULL DEFAULT 'straight_line',
  `asset_account_id` bigint(20) UNSIGNED NOT NULL,
  `depreciation_expense_account_id` bigint(20) UNSIGNED NOT NULL,
  `accumulated_depreciation_account_id` bigint(20) UNSIGNED NOT NULL,
  `accumulated_depreciation_cents` bigint(20) NOT NULL DEFAULT 0,
  `status` enum('active','disposed','sold','written_off') NOT NULL DEFAULT 'active',
  `disposal_date` date DEFAULT NULL,
  `disposal_proceeds_cents` bigint(20) DEFAULT NULL,
  `disposal_journal_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gl_period_locks`
--

CREATE TABLE `gl_period_locks` (
  `lock_id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `lock_date` date NOT NULL,
  `lock_reason` varchar(255) DEFAULT NULL,
  `locked_by` int(11) NOT NULL,
  `locked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gl_report_map`
--

CREATE TABLE `gl_report_map` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `report` enum('BS','IS','CF') NOT NULL,
  `group_key` varchar(32) NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gl_report_map`
--

INSERT INTO `gl_report_map` (`id`, `company_id`, `report`, `group_key`, `account_id`) VALUES
(1, 1, 'BS', 'ASSETS', 1),
(2, 1, 'BS', 'ASSETS', 2),
(3, 1, 'BS', 'ASSETS', 3),
(4, 1, 'BS', 'ASSETS', 4),
(5, 1, 'BS', 'ASSETS', 5),
(6, 1, 'BS', 'ASSETS', 6),
(7, 1, 'BS', 'ASSETS', 7),
(8, 1, 'BS', 'ASSETS', 8),
(9, 1, 'BS', 'ASSETS', 9),
(10, 1, 'BS', 'ASSETS', 10),
(31, 1, 'BS', 'EQUITY', 21),
(32, 1, 'BS', 'EQUITY', 22),
(33, 1, 'BS', 'EQUITY', 23),
(34, 1, 'BS', 'EQUITY', 24),
(16, 1, 'BS', 'LIABILITIES', 11),
(17, 1, 'BS', 'LIABILITIES', 12),
(18, 1, 'BS', 'LIABILITIES', 13),
(19, 1, 'BS', 'LIABILITIES', 14),
(20, 1, 'BS', 'LIABILITIES', 15),
(21, 1, 'BS', 'LIABILITIES', 16),
(22, 1, 'BS', 'LIABILITIES', 17),
(23, 1, 'BS', 'LIABILITIES', 18),
(24, 1, 'BS', 'LIABILITIES', 19),
(25, 1, 'BS', 'LIABILITIES', 20),
(45, 1, 'IS', 'COST_OF_SALES', 29),
(46, 1, 'IS', 'COST_OF_SALES', 30),
(47, 1, 'IS', 'COST_OF_SALES', 31),
(48, 1, 'IS', 'OPERATING_EXPENSES', 32),
(49, 1, 'IS', 'OPERATING_EXPENSES', 33),
(50, 1, 'IS', 'OPERATING_EXPENSES', 34),
(51, 1, 'IS', 'OPERATING_EXPENSES', 35),
(52, 1, 'IS', 'OPERATING_EXPENSES', 36),
(53, 1, 'IS', 'OPERATING_EXPENSES', 37),
(54, 1, 'IS', 'OPERATING_EXPENSES', 38),
(55, 1, 'IS', 'OPERATING_EXPENSES', 39),
(56, 1, 'IS', 'OPERATING_EXPENSES', 40),
(57, 1, 'IS', 'OPERATING_EXPENSES', 41),
(38, 1, 'IS', 'REVENUE', 25),
(39, 1, 'IS', 'REVENUE', 26),
(40, 1, 'IS', 'REVENUE', 27),
(41, 1, 'IS', 'REVENUE', 28);

-- --------------------------------------------------------

--
-- Table structure for table `gl_tax_codes`
--

CREATE TABLE `gl_tax_codes` (
  `tax_code_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `rate_percent` decimal(6,2) NOT NULL,
  `type` enum('input','output','exempt','zero') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gl_tax_codes`
--

INSERT INTO `gl_tax_codes` (`tax_code_id`, `company_id`, `code`, `description`, `rate_percent`, `type`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'STD', 'Standard Rate (15%)', 15.00, 'output', 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(2, 1, 'ZERO', 'Zero Rated (0%)', 0.00, 'output', 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(3, 1, 'EXEMPT', 'Exempt', 0.00, 'exempt', 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(4, 1, 'INPUT', 'Input VAT (15%)', 15.00, 'input', 1, '2025-10-01 21:24:46', '2025-10-01 21:24:46'),
(5, 1, 'STANDARD', 'Standard VAT 15%', 15.00, 'output', 1, '2025-10-02 11:39:57', '2025-10-02 11:39:57');

-- --------------------------------------------------------

--
-- Table structure for table `gl_vat_periods`
--

CREATE TABLE `gl_vat_periods` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('open','prepared','filed','paid','adjusted') NOT NULL DEFAULT 'open',
  `output_vat_cents` bigint(20) NOT NULL DEFAULT 0,
  `input_vat_cents` bigint(20) NOT NULL DEFAULT 0,
  `net_vat_cents` bigint(20) NOT NULL DEFAULT 0,
  `filed_at` datetime DEFAULT NULL,
  `filed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `goods_received_notes`
--

CREATE TABLE `goods_received_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `grn_number` varchar(50) NOT NULL,
  `po_id` bigint(20) UNSIGNED NOT NULL,
  `total` decimal(15,2) NOT NULL,
  `received_date` date NOT NULL,
  `status` enum('draft','completed','cancelled') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grn_lines`
--

CREATE TABLE `grn_lines` (
  `id` bigint(20) NOT NULL,
  `grn_id` bigint(20) NOT NULL,
  `po_line_id` bigint(20) DEFAULT NULL,
  `qty_received` decimal(15,3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_items`
--

CREATE TABLE `inventory_items` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `sku` varchar(120) NOT NULL,
  `name` varchar(255) NOT NULL,
  `uom` varchar(50) DEFAULT 'ea',
  `is_stocked` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_id` bigint(20) NOT NULL,
  `movement_date` date NOT NULL,
  `qty` decimal(15,3) NOT NULL,
  `unit_cost` decimal(15,4) NOT NULL,
  `ref_type` varchar(50) DEFAULT NULL,
  `ref_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invites`
--

CREATE TABLE `invites` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `role` enum('admin','member','viewer','pos','bookkeeper') NOT NULL DEFAULT 'member',
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `used_by` int(11) DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `quote_id` int(10) UNSIGNED DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED DEFAULT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('draft','sent','viewed','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax` decimal(15,2) DEFAULT 0.00,
  `total` decimal(15,2) DEFAULT 0.00,
  `balance_due` decimal(15,2) DEFAULT 0.00,
  `currency` char(3) DEFAULT 'ZAR',
  `terms` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `yoco_payment_link` varchar(255) DEFAULT NULL,
  `yoco_reference` varchar(190) DEFAULT NULL,
  `journal_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `company_id`, `invoice_number`, `quote_id`, `customer_id`, `project_id`, `contact_id`, `issue_date`, `due_date`, `status`, `subtotal`, `discount`, `tax`, `total`, `balance_due`, `currency`, `terms`, `notes`, `paid_at`, `pdf_path`, `yoco_payment_link`, `yoco_reference`, `journal_id`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'INV2025-0000', NULL, 2, NULL, NULL, '2025-10-04', '2025-11-03', 'sent', 3243.00, 0.00, 486.45, 3729.45, 3729.45, 'ZAR', '', '', NULL, '/storage/qi/1/invoice/INV2025-0000.pdf', NULL, NULL, 1, 1, '2025-10-04 14:57:32', '2025-10-09 20:13:40'),
(2, 1, 'INV2025-0002', NULL, 13, 8, NULL, '2025-10-20', '2025-10-27', 'draft', 190269.00, 0.00, 28540.35, 218809.35, 218809.35, 'ZAR', 'Payment Due', 'Total contract amount R1 458 713', NULL, NULL, NULL, NULL, NULL, 3, '2025-10-20 15:55:35', '2025-10-20 15:55:35');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_lines`
--

CREATE TABLE `invoice_lines` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 1.000,
  `unit` varchar(50) DEFAULT 'ea',
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 15.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) DEFAULT 0,
  `gl_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `inventory_item_id` bigint(20) DEFAULT NULL,
  `project_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_board_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_lines`
--

INSERT INTO `invoice_lines` (`id`, `invoice_id`, `item_description`, `quantity`, `unit`, `unit_price`, `discount`, `tax_rate`, `line_total`, `sort_order`, `gl_account_id`, `inventory_item_id`, `project_item_id`, `project_board_id`) VALUES
(1, 1, 'fgdhfghdf', 1.000, 'unit', 3243.00, 0.00, 15.00, 3729.45, 0, NULL, NULL, NULL, NULL),
(2, 2, '15% Deposit ', 1.000, 'ea', 190269.00, 0.00, 15.00, 190269.00, 0, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `module` varchar(50) DEFAULT NULL,
  `ref_type` varchar(50) DEFAULT NULL,
  `ref_id` bigint(20) DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `source_type` enum('invoice','payment','receipt','manual') DEFAULT NULL,
  `source_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reversed_by_journal_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reverses_journal_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('draft','prepared','approved','posted') NOT NULL DEFAULT 'draft',
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `posted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `journal_entries`
--

INSERT INTO `journal_entries` (`id`, `company_id`, `entry_date`, `reference`, `description`, `module`, `ref_type`, `ref_id`, `is_locked`, `source_type`, `source_id`, `created_by`, `created_at`, `reversed_by_journal_id`, `reverses_journal_id`, `status`, `approved_by`, `approved_at`, `posted_by`, `posted_at`) VALUES
(1, 1, '2025-10-04', 'INV2025-0000', 'Invoice INV2025-0000', 'fin', 'invoice', 1, 0, 'invoice', 1, 1, '2025-10-09 20:13:40', NULL, NULL, 'draft', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `journal_lines`
--

CREATE TABLE `journal_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `journal_id` bigint(20) UNSIGNED NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `debit` decimal(15,2) DEFAULT 0.00,
  `credit` decimal(15,2) DEFAULT 0.00,
  `project_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `item_id` bigint(20) DEFAULT NULL,
  `tax_code_id` int(10) UNSIGNED DEFAULT NULL,
  `customer_id` bigint(20) DEFAULT NULL,
  `supplier_id` bigint(20) DEFAULT NULL,
  `employee_id` bigint(20) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `journal_lines`
--

INSERT INTO `journal_lines` (`id`, `journal_id`, `account_code`, `description`, `debit`, `credit`, `project_id`, `board_id`, `item_id`, `tax_code_id`, `customer_id`, `supplier_id`, `employee_id`, `reference`) VALUES
(1, 1, '1200', 'Accounts Receivable', 3729.45, 0.00, NULL, NULL, NULL, NULL, 2, NULL, NULL, 'INV2025-0000'),
(2, 1, '4100', 'Sales Income', 0.00, 3243.00, NULL, NULL, NULL, NULL, 2, NULL, NULL, 'INV2025-0000'),
(3, 1, '2120', 'VAT Output', 0.00, 486.45, NULL, NULL, NULL, NULL, 2, NULL, NULL, 'INV2025-0000');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token`, `expires_at`, `used`, `created_at`) VALUES
(1, 3, 'fc625d1bd9ac83ff69939a88df758d459204541cce19fea788c5fd87990192c6', '2025-12-13 07:37:35', 0, '2025-12-12 07:37:35');

-- --------------------------------------------------------

--
-- Table structure for table `payitems`
--

CREATE TABLE `payitems` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `type` enum('earning','deduction','contribution','benefit','reimbursement') NOT NULL,
  `taxable` tinyint(1) NOT NULL DEFAULT 1,
  `uif_subject` tinyint(1) NOT NULL DEFAULT 1,
  `sdl_subject` tinyint(1) NOT NULL DEFAULT 1,
  `paye_code` varchar(32) DEFAULT NULL,
  `basis` enum('fixed','hour','day','percent_base','formula') NOT NULL DEFAULT 'fixed',
  `formula` text DEFAULT NULL,
  `gl_account_code` varchar(20) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_date` date NOT NULL,
  `method` enum('card','eft','cash','cheque','yoco','other') NOT NULL DEFAULT 'eft',
  `reference` varchar(190) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `received_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `journal_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_allocations`
--

CREATE TABLE `payment_allocations` (
  `id` int(10) UNSIGNED NOT NULL,
  `payment_id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_settings`
--

CREATE TABLE `payroll_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `monthly_anchor_day` tinyint(4) DEFAULT 25,
  `fortnight_anchor_day` tinyint(4) DEFAULT 15,
  `weekly_anchor_day` tinyint(4) DEFAULT 5 COMMENT '1=Mon, 5=Fri',
  `default_frequency` enum('monthly','fortnight','weekly') NOT NULL DEFAULT 'monthly',
  `bank_file_format` varchar(64) DEFAULT 'standard_bank_csv',
  `rounding_cents` enum('nearest','down','up') DEFAULT 'nearest',
  `overtime_rules_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`overtime_rules_json`)),
  `auto_post_to_finance` tinyint(1) DEFAULT 0,
  `require_approval_for_variance_pct` decimal(5,2) DEFAULT 10.00,
  `default_wage_gl_code` varchar(20) DEFAULT '6000',
  `default_paye_gl_code` varchar(20) DEFAULT '2100',
  `default_uif_gl_code` varchar(20) DEFAULT '2101',
  `default_sdl_gl_code` varchar(20) DEFAULT '2102',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payroll_settings`
--

INSERT INTO `payroll_settings` (`id`, `company_id`, `monthly_anchor_day`, `fortnight_anchor_day`, `weekly_anchor_day`, `default_frequency`, `bank_file_format`, `rounding_cents`, `overtime_rules_json`, `auto_post_to_finance`, `require_approval_for_variance_pct`, `default_wage_gl_code`, `default_paye_gl_code`, `default_uif_gl_code`, `default_sdl_gl_code`, `created_at`, `updated_at`) VALUES
(1, 1, 25, 15, 5, 'monthly', 'standard_bank_csv', 'nearest', NULL, 0, 10.00, '6000', '2100', '2101', '2102', '2025-10-01 20:36:20', '2025-10-01 20:36:20');

-- --------------------------------------------------------

--
-- Table structure for table `pay_runs`
--

CREATE TABLE `pay_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `run_number` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `frequency` enum('monthly','fortnight','weekly') NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `pay_date` date NOT NULL,
  `anchor_ref` varchar(64) DEFAULT NULL,
  `status` enum('draft','calculated','review','approved','locked','posted') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `locked_by` int(10) UNSIGNED DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `posted_by` int(10) UNSIGNED DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pay_runs`
--

INSERT INTO `pay_runs` (`id`, `company_id`, `run_number`, `name`, `frequency`, `period_start`, `period_end`, `pay_date`, `anchor_ref`, `status`, `notes`, `created_by`, `approved_by`, `approved_at`, `locked_by`, `locked_at`, `posted_by`, `posted_at`, `created_at`, `updated_at`) VALUES
(1, 1, '2025-0001', 'asdf', 'monthly', '2025-10-01', '2025-10-31', '2025-11-25', '2025-10 monthly', 'draft', '', 1, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-05 03:47:54', '2025-10-05 03:47:54');

-- --------------------------------------------------------

--
-- Table structure for table `pay_run_employees`
--

CREATE TABLE `pay_run_employees` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `gross_cents` bigint(20) NOT NULL DEFAULT 0,
  `taxable_income_cents` bigint(20) NOT NULL DEFAULT 0,
  `paye_cents` bigint(20) NOT NULL DEFAULT 0,
  `uif_employee_cents` bigint(20) NOT NULL DEFAULT 0,
  `uif_employer_cents` bigint(20) NOT NULL DEFAULT 0,
  `sdl_cents` bigint(20) NOT NULL DEFAULT 0,
  `other_deductions_cents` bigint(20) NOT NULL DEFAULT 0,
  `reimbursements_cents` bigint(20) NOT NULL DEFAULT 0,
  `net_cents` bigint(20) NOT NULL DEFAULT 0,
  `employer_cost_cents` bigint(20) NOT NULL DEFAULT 0,
  `bank_amount_cents` bigint(20) NOT NULL DEFAULT 0,
  `variance_vs_last_cents` bigint(20) DEFAULT NULL,
  `calc_debug_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`calc_debug_json`)),
  `payslip_path` varchar(255) DEFAULT NULL,
  `payslip_generated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pay_run_lines`
--

CREATE TABLE `pay_run_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `payitem_id` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `rate_cents` bigint(20) NOT NULL DEFAULT 0,
  `amount_cents` bigint(20) NOT NULL DEFAULT 0,
  `project_id` int(10) UNSIGNED DEFAULT NULL,
  `board_id` int(10) UNSIGNED DEFAULT NULL,
  `item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_adhoc` tinyint(1) NOT NULL DEFAULT 0,
  `adhoc_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pay_timesheets`
--

CREATE TABLE `pay_timesheets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `ts_date` date NOT NULL,
  `regular_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `ot_hours` decimal(8,2) DEFAULT 0.00,
  `sunday_hours` decimal(8,2) DEFAULT 0.00,
  `public_holiday_hours` decimal(8,2) DEFAULT 0.00,
  `project_id` int(10) UNSIGNED DEFAULT NULL,
  `board_id` int(10) UNSIGNED DEFAULT NULL,
  `item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `period_locks`
--

CREATE TABLE `period_locks` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 1,
  `locked_by` int(10) UNSIGNED DEFAULT NULL,
  `locked_at` datetime DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `id` int(11) NOT NULL,
  `code` enum('solo','pro','business') NOT NULL,
  `name` varchar(50) NOT NULL,
  `max_users` int(11) NOT NULL DEFAULT 1,
  `max_companies` int(11) NOT NULL DEFAULT 1,
  `price_monthly_cents` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `plans`
--

INSERT INTO `plans` (`id`, `code`, `name`, `max_users`, `max_companies`, `price_monthly_cents`, `created_at`, `updated_at`) VALUES
(1, 'solo', 'Solo', 1, 1, 9900, '2025-09-30 07:41:06', '2025-09-30 07:41:06'),
(2, 'pro', 'Pro', 3, 2, 29900, '2025-09-30 07:41:06', '2025-09-30 07:41:06'),
(3, 'business', 'Business', 10, 3, 59900, '2025-09-30 07:41:06', '2025-09-30 07:41:06');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `project_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_number` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `client` varchar(255) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `site_address` text DEFAULT NULL,
  `status` enum('draft','active','on_hold','completed','archived','cancelled') DEFAULT 'draft',
  `archived` tinyint(1) DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT 0.00,
  `actual_cost` decimal(15,2) DEFAULT 0.00,
  `progress_percent` tinyint(3) DEFAULT 0,
  `project_manager_id` int(11) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`project_id`, `company_id`, `project_number`, `name`, `description`, `client`, `client_id`, `site_address`, `status`, `archived`, `start_date`, `end_date`, `budget`, `actual_cost`, `progress_percent`, `project_manager_id`, `owner_id`, `created_by`, `created_at`, `updated_at`) VALUES
(4, 1, NULL, 'West Town', NULL, NULL, NULL, NULL, 'active', 0, '0000-00-00', '0000-00-00', 0.00, 0.00, 0, 0, 1, 1, '2025-10-03 13:34:54', NULL),
(8, 1, NULL, 'Rivonia', NULL, NULL, NULL, NULL, 'active', 0, '2025-10-27', '2025-12-19', 850000.00, 0.00, 0, 1, 1, 1, '2025-10-11 17:31:57', NULL),
(10, 1, NULL, '11 Grandicepts', NULL, NULL, NULL, NULL, 'active', 0, '2025-01-15', '2026-02-27', 5200000.00, 0.00, 0, 1, 1, 1, '2025-10-16 09:34:08', NULL),
(12, 1, NULL, 'Water Edge Body Corp', NULL, NULL, NULL, NULL, 'active', 0, '2025-10-27', '2025-12-19', 750000.00, 0.00, 0, 1, 1, 1, '2025-10-18 14:28:32', NULL),
(13, 1, NULL, '37 Mira St.', NULL, NULL, NULL, NULL, 'active', 0, '2025-08-15', '2025-10-31', 0.00, 0.00, 0, 1, 3, 3, '2025-10-21 14:41:34', NULL),
(15, 1, NULL, 'Concreto', NULL, NULL, NULL, NULL, 'active', 0, '0000-00-00', '0000-00-00', 0.00, 0.00, 0, 0, 1, 1, '2025-12-04 09:33:42', NULL),
(16, 1, NULL, '6 DeBruin St', NULL, NULL, NULL, NULL, 'active', 0, '2025-11-17', '2025-12-12', 40000.00, 0.00, 0, 3, 3, 3, '2025-12-09 11:06:57', NULL),
(17, 1, NULL, '180 Mullersteine', NULL, NULL, NULL, NULL, 'active', 0, '2025-12-15', '2026-01-02', 100000.00, 0.00, 0, 1, 1, 1, '2025-12-15 15:41:29', NULL),
(19, 1, NULL, 'CARS', NULL, NULL, NULL, NULL, 'active', 0, '0000-00-00', '0000-00-00', 0.00, 0.00, 0, 0, 1, 1, '2026-01-14 17:53:15', NULL),
(20, 1, NULL, 'Wages', NULL, NULL, NULL, NULL, 'active', 0, '0000-00-00', '0000-00-00', 0.00, 0.00, 0, 3, 1, 1, '2026-01-15 08:07:07', NULL),
(21, 1, NULL, 'Wages F27', NULL, NULL, NULL, NULL, 'active', 0, '2026-01-17', '2026-01-30', 0.00, 0.00, 0, 3, 3, 3, '2026-01-19 05:14:20', NULL),
(22, 1, NULL, 'Wages, W28', NULL, NULL, NULL, NULL, 'active', 0, '2026-01-31', '2026-02-13', 0.00, 0.00, 0, 3, 3, 3, '2026-02-02 06:43:44', NULL),
(23, 1, NULL, 'Haval Vereeniging', NULL, NULL, NULL, NULL, 'active', 0, '2026-02-16', '2026-03-06', 200000.00, 0.00, 0, 1, 1, 1, '2026-02-13 10:04:50', NULL),
(26, 1, NULL, 'Suzuki Vaalpark', NULL, NULL, NULL, NULL, 'active', 0, '0000-00-00', '0000-00-00', 0.00, 0.00, 0, 1, 1, 1, '2026-02-13 10:32:00', NULL),
(27, 1, NULL, 'Wages W29', NULL, NULL, NULL, NULL, 'active', 0, '2026-02-14', '2026-02-27', 0.00, 0.00, 0, 3, 3, 3, '2026-02-21 04:40:39', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `project_boards`
--

CREATE TABLE `project_boards` (
  `board_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `board_type` enum('schedule','costing','procurement','quality','safety','general') DEFAULT 'general',
  `title` varchar(255) NOT NULL,
  `default_view` varchar(20) DEFAULT 'table',
  `description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `archived` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_boards`
--

INSERT INTO `project_boards` (`board_id`, `company_id`, `project_id`, `board_type`, `title`, `default_view`, `description`, `sort_order`, `archived`, `is_active`, `created_at`) VALUES
(1, 1, 2, 'costing', 'Costing', 'table', NULL, 0, 0, 1, '2025-09-30 18:25:47'),
(2, 1, 1, 'costing', 'Costing', 'table', NULL, 0, 0, 1, '2025-09-30 18:51:12'),
(5, 1, 3, 'general', 'dfsd', 'table', NULL, 1, 0, 1, '2025-10-03 08:14:55'),
(6, 1, 4, 'costing', 'Cost', 'table', NULL, 0, 0, 1, '2025-10-03 14:05:49'),
(8, 0, 123, 'schedule', 'Schedule', 'table', NULL, 0, 0, 1, '2025-10-08 09:17:05'),
(9, 0, 123, 'costing', 'Costing', 'table', NULL, 0, 0, 1, '2025-10-08 09:17:05'),
(10, 0, 123, 'procurement', 'Procurement', 'table', NULL, 0, 0, 1, '2025-10-08 09:17:05'),
(17, 1, 8, '', 'Schedule', 'table', NULL, 0, 0, 1, '2025-10-11 17:31:57'),
(18, 1, 8, '', 'Costing', 'table', NULL, 0, 0, 1, '2025-10-11 17:31:57'),
(19, 1, 8, '', 'Procurement', 'table', NULL, 0, 0, 1, '2025-10-11 17:31:57'),
(23, 1, 10, '', 'Schedule', 'table', NULL, 0, 0, 1, '2025-10-16 09:34:08'),
(24, 1, 10, '', 'Costing', 'table', NULL, 0, 0, 1, '2025-10-16 09:34:08'),
(25, 1, 10, '', 'Procurement', 'table', NULL, 0, 0, 1, '2025-10-16 09:34:08'),
(26, 1, 12, '', 'Schedule', 'table', NULL, 0, 0, 1, '2025-10-18 14:28:32'),
(27, 1, 12, '', 'Costing', 'table', NULL, 0, 0, 1, '2025-10-18 14:28:32'),
(28, 1, 12, '', 'Procurement', 'table', NULL, 0, 0, 1, '2025-10-18 14:28:32'),
(29, 1, 13, '', 'Schedule', 'table', NULL, 0, 0, 1, '2025-10-21 14:41:34'),
(30, 1, 13, '', 'Costing', 'table', NULL, 0, 0, 1, '2025-10-21 14:41:34'),
(31, 1, 13, '', 'Procurement', 'table', NULL, 0, 0, 1, '2025-10-21 14:41:34'),
(32, 1, 15, '', 'Schedule', 'table', NULL, 0, 0, 1, '2025-12-04 09:33:42'),
(33, 1, 15, '', 'Costing', 'table', NULL, 0, 0, 1, '2025-12-04 09:33:43'),
(34, 1, 15, '', 'Procurement', 'table', NULL, 0, 0, 1, '2025-12-04 09:33:43'),
(35, 1, 16, '', 'Schedule', 'table', NULL, 0, 0, 1, '2025-12-09 11:06:57'),
(36, 1, 16, '', 'Costing', 'table', NULL, 0, 0, 1, '2025-12-09 11:06:57'),
(37, 1, 16, '', 'Procurement', 'table', NULL, 0, 0, 1, '2025-12-09 11:06:57'),
(38, 1, 17, '', 'Schedule', 'table', NULL, 0, 0, 1, '2025-12-15 15:41:29'),
(39, 1, 17, '', 'Costing', 'table', NULL, 0, 0, 1, '2025-12-15 15:41:29'),
(40, 1, 17, '', 'Procurement', 'table', NULL, 0, 0, 1, '2025-12-15 15:41:29'),
(41, 1, 19, '', 'Schedule', 'table', NULL, 0, 0, 1, '2026-01-14 17:53:15'),
(42, 1, 19, '', 'Costing', 'table', NULL, 0, 0, 1, '2026-01-14 17:53:15'),
(43, 1, 19, '', 'Procurement', 'table', NULL, 0, 0, 1, '2026-01-14 17:53:15'),
(44, 1, 20, '', 'Schedule', 'table', NULL, 0, 0, 1, '2026-01-15 08:07:07'),
(45, 1, 20, '', 'Costing', 'table', NULL, 0, 0, 1, '2026-01-15 08:07:07'),
(46, 1, 20, '', 'Procurement', 'table', NULL, 0, 0, 1, '2026-01-15 08:07:07'),
(47, 1, 21, '', 'Schedule', 'table', NULL, 0, 0, 1, '2026-01-19 05:14:20'),
(48, 1, 21, '', 'Costing', 'table', NULL, 0, 0, 1, '2026-01-19 05:14:20'),
(49, 1, 21, '', 'Procurement', 'table', NULL, 0, 0, 1, '2026-01-19 05:14:20'),
(50, 1, 22, '', 'Schedule', 'table', NULL, 0, 0, 1, '2026-02-02 06:43:44'),
(51, 1, 22, '', 'Costing', 'table', NULL, 0, 0, 1, '2026-02-02 06:43:44'),
(52, 1, 22, '', 'Procurement', 'table', NULL, 0, 0, 1, '2026-02-02 06:43:44'),
(53, 1, 23, '', 'Schedule', 'table', NULL, 0, 0, 1, '2026-02-13 10:04:50'),
(54, 1, 23, '', 'Costing', 'table', NULL, 0, 0, 1, '2026-02-13 10:04:50'),
(55, 1, 23, '', 'Procurement', 'table', NULL, 0, 0, 1, '2026-02-13 10:04:50'),
(56, 1, 26, '', 'Schedule', 'table', NULL, 0, 0, 1, '2026-02-13 10:32:00'),
(57, 1, 26, '', 'Costing', 'table', NULL, 0, 0, 1, '2026-02-13 10:32:00'),
(58, 1, 26, '', 'Procurement', 'table', NULL, 0, 0, 1, '2026-02-13 10:32:00'),
(59, 1, 27, '', 'Schedule', 'table', NULL, 0, 0, 1, '2026-02-21 04:40:39'),
(60, 1, 27, '', 'Costing', 'table', NULL, 0, 0, 1, '2026-02-21 04:40:39'),
(61, 1, 27, '', 'Procurement', 'table', NULL, 0, 0, 1, '2026-02-21 04:40:39');

-- --------------------------------------------------------

--
-- Table structure for table `project_members`
--

CREATE TABLE `project_members` (
  `member_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('owner','manager','member','viewer') DEFAULT 'member',
  `can_edit` tinyint(1) DEFAULT 1,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_members`
--

INSERT INTO `project_members` (`member_id`, `company_id`, `project_id`, `user_id`, `role`, `can_edit`, `added_at`) VALUES
(1, 1, 4, 1, 'owner', 1, '2025-10-03 13:34:54'),
(5, 1, 8, 1, 'owner', 1, '2025-10-11 17:31:57'),
(7, 1, 4, 3, 'member', 1, '2025-10-14 11:34:10'),
(8, 1, 10, 1, 'owner', 1, '2025-10-16 09:34:08'),
(9, 1, 10, 3, 'member', 1, '2025-10-16 09:35:42'),
(10, 1, 8, 4, 'member', 1, '2025-10-18 11:49:47'),
(11, 1, 8, 3, 'member', 1, '2025-10-18 11:49:53'),
(12, 1, 4, 4, 'member', 1, '2025-10-18 11:50:51'),
(13, 1, 10, 4, 'member', 1, '2025-10-18 11:51:08'),
(14, 1, 11, 4, 'owner', 1, '2025-10-18 14:22:33'),
(15, 1, 12, 1, 'owner', 1, '2025-10-18 14:28:32'),
(16, 1, 12, 4, 'member', 1, '2025-10-18 14:28:48'),
(17, 1, 12, 3, 'member', 1, '2025-10-18 14:28:52'),
(18, 1, 13, 3, 'owner', 1, '2025-10-21 14:41:34'),
(19, 1, 13, 1, 'manager', 1, '2025-10-21 14:41:34'),
(20, 1, 14, 1, 'owner', 1, '2025-12-04 09:31:52'),
(21, 1, 15, 1, 'owner', 1, '2025-12-04 09:33:42'),
(22, 1, 16, 3, 'owner', 1, '2025-12-09 11:06:57'),
(23, 1, 17, 1, 'owner', 1, '2025-12-15 15:41:29'),
(24, 1, 15, 3, 'member', 1, '2025-12-15 18:09:12'),
(25, 1, 15, 4, 'member', 1, '2025-12-15 18:09:23'),
(26, 1, 17, 4, 'member', 1, '2025-12-15 18:09:54'),
(27, 1, 17, 3, 'member', 1, '2025-12-15 18:10:01'),
(29, 1, 19, 1, 'owner', 1, '2026-01-14 17:53:15'),
(30, 1, 20, 1, 'owner', 1, '2026-01-15 08:07:07'),
(32, 1, 20, 3, 'member', 1, '2026-01-15 12:10:22'),
(33, 1, 21, 3, 'owner', 1, '2026-01-19 05:14:20'),
(34, 1, 21, 1, 'member', 1, '2026-01-22 11:30:02'),
(35, 1, 22, 3, 'owner', 1, '2026-02-02 06:43:44'),
(36, 1, 23, 1, 'owner', 1, '2026-02-13 10:04:50'),
(37, 1, 24, 1, 'owner', 1, '2026-02-13 10:25:27'),
(38, 1, 25, 1, 'owner', 1, '2026-02-13 10:26:08'),
(39, 1, 26, 1, 'owner', 1, '2026-02-13 10:32:00'),
(40, 1, 26, 3, 'member', 1, '2026-02-18 15:08:12'),
(41, 1, 26, 4, 'member', 1, '2026-02-18 15:08:17'),
(42, 1, 23, 4, 'member', 1, '2026-02-18 15:09:09'),
(43, 1, 23, 3, 'member', 1, '2026-02-18 15:09:13'),
(44, 1, 27, 3, 'owner', 1, '2026-02-21 04:40:39');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `po_number` varchar(50) NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `total` decimal(15,2) NOT NULL,
  `status` enum('draft','approved','partial','complete','cancelled') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_lines`
--

CREATE TABLE `purchase_order_lines` (
  `id` bigint(20) NOT NULL,
  `po_id` bigint(20) NOT NULL,
  `description` text NOT NULL,
  `qty` decimal(15,3) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `tax_rate` decimal(5,2) DEFAULT 15.00,
  `gl_account_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qi_email_log`
--

CREATE TABLE `qi_email_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `entity_type` enum('quote','invoice','credit_note','reminder') NOT NULL,
  `entity_id` int(10) UNSIGNED NOT NULL,
  `to_email` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_preview` text DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('sent','failed','bounced') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `qi_email_log`
--

INSERT INTO `qi_email_log` (`id`, `company_id`, `entity_type`, `entity_id`, `to_email`, `subject`, `body_preview`, `sent_at`, `status`, `error_message`) VALUES
(1, 1, 'invoice', 1, 'adriaan@prositeconstruction.co.za', 'Invoice INV2025-0000 from Concreto Civils', 'Invoice INV2025-0000 sent to customer.', '2025-10-05 15:52:20', 'sent', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `qi_email_templates`
--

CREATE TABLE `qi_email_templates` (
  `id` int(11) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `template_type` enum('quote','invoice','payment_reminder','thank_you') NOT NULL,
  `subject` varchar(500) NOT NULL,
  `body_html` text NOT NULL,
  `body_plain` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `qi_email_templates`
--

INSERT INTO `qi_email_templates` (`id`, `company_id`, `template_type`, `subject`, `body_html`, `body_plain`, `active`, `created_at`, `updated_at`) VALUES
(1, 1, 'quote', 'Quote {QUOTE_NUMBER} from {COMPANY_NAME}', '<html><body style=\"font-family: Arial, sans-serif; color: #333;\">\r\n<div style=\"max-width: 600px; margin: 0 auto; padding: 20px;\">\r\n  <h2 style=\"color: #fbbf24;\">New Quote from {COMPANY_NAME}</h2>\r\n  <p>Dear {CUSTOMER_NAME},</p>\r\n  <p>Please find attached our quotation <strong>{QUOTE_NUMBER}</strong>.</p>\r\n  <table style=\"width: 100%; border-collapse: collapse; margin: 20px 0;\">\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Quote Number:</strong></td><td style=\"padding: 10px;\">{QUOTE_NUMBER}</td></tr>\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Amount:</strong></td><td style=\"padding: 10px;\">{AMOUNT}</td></tr>\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Valid Until:</strong></td><td style=\"padding: 10px;\">{EXPIRY_DATE}</td></tr>\r\n  </table>\r\n  <p>If you have any questions, please don\'t hesitate to contact us.</p>\r\n  <p>Best regards,<br>{COMPANY_NAME}</p>\r\n  <hr style=\"border: none; border-top: 1px solid #ddd; margin: 20px 0;\">\r\n  <p style=\"font-size: 12px; color: #999;\">{COMPANY_EMAIL} | {COMPANY_PHONE}</p>\r\n</div>\r\n</body></html>', 'Dear {CUSTOMER_NAME},\r\n\r\nPlease find attached our quotation {QUOTE_NUMBER}.\r\n\r\nValid until: {EXPIRY_DATE}\r\nAmount: {AMOUNT}\r\n\r\nBest regards,\r\n{COMPANY_NAME}', 1, '2025-10-04 17:31:53', '2025-10-04 17:31:53'),
(2, 1, 'invoice', 'Invoice {INVOICE_NUMBER} from {COMPANY_NAME}', '<html><body style=\"font-family: Arial, sans-serif; color: #333;\">\r\n<div style=\"max-width: 600px; margin: 0 auto; padding: 20px;\">\r\n  <h2 style=\"color: #fbbf24;\">Invoice from {COMPANY_NAME}</h2>\r\n  <p>Dear {CUSTOMER_NAME},</p>\r\n  <p>Please find attached invoice <strong>{INVOICE_NUMBER}</strong>.</p>\r\n  <table style=\"width: 100%; border-collapse: collapse; margin: 20px 0;\">\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Invoice Number:</strong></td><td style=\"padding: 10px;\">{INVOICE_NUMBER}</td></tr>\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Amount Due:</strong></td><td style=\"padding: 10px; font-size: 18px; font-weight: bold; color: #fbbf24;\">{AMOUNT}</td></tr>\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Due Date:</strong></td><td style=\"padding: 10px;\">{DUE_DATE}</td></tr>\r\n  </table>\r\n  <p>Payment details:<br>\r\n  Bank: {BANK_NAME}<br>\r\n  Account: {BANK_ACCOUNT}<br>\r\n  Branch: {BANK_BRANCH}</p>\r\n  <p>Best regards,<br>{COMPANY_NAME}</p>\r\n  <hr style=\"border: none; border-top: 1px solid #ddd; margin: 20px 0;\">\r\n  <p style=\"font-size: 12px; color: #999;\">{COMPANY_EMAIL} | {COMPANY_PHONE}</p>\r\n</div>\r\n</body></html>', 'Dear {CUSTOMER_NAME},\r\n\r\nPlease find attached invoice {INVOICE_NUMBER}.\r\n\r\nAmount Due: {AMOUNT}\r\nDue Date: {DUE_DATE}\r\n\r\nBank: {BANK_NAME}\r\nAccount: {BANK_ACCOUNT}\r\nBranch: {BANK_BRANCH}\r\n\r\nBest regards,\r\n{COMPANY_NAME}', 1, '2025-10-04 17:31:53', '2025-10-04 17:31:53'),
(3, 1, 'payment_reminder', 'Payment Reminder: Invoice {INVOICE_NUMBER}', '<html><body style=\"font-family: Arial, sans-serif; color: #333;\">\r\n<div style=\"max-width: 600px; margin: 0 auto; padding: 20px;\">\r\n  <h2 style=\"color: #f59e0b;\">Payment Reminder</h2>\r\n  <p>Dear {CUSTOMER_NAME},</p>\r\n  <p>This is a friendly reminder that invoice <strong>{INVOICE_NUMBER}</strong> is due.</p>\r\n  <table style=\"width: 100%; border-collapse: collapse; margin: 20px 0; border: 2px solid #f59e0b;\">\r\n    <tr><td style=\"padding: 10px; background: #fef3c7;\"><strong>Invoice Number:</strong></td><td style=\"padding: 10px; background: #fef3c7;\">{INVOICE_NUMBER}</td></tr>\r\n    <tr><td style=\"padding: 10px; background: #fef3c7;\"><strong>Amount Due:</strong></td><td style=\"padding: 10px; background: #fef3c7; font-size: 18px; font-weight: bold; color: #f59e0b;\">{AMOUNT}</td></tr>\r\n    <tr><td style=\"padding: 10px; background: #fef3c7;\"><strong>Due Date:</strong></td><td style=\"padding: 10px; background: #fef3c7;\">{DUE_DATE}</td></tr>\r\n  </table>\r\n  <p>Payment details:<br>\r\n  Bank: {BANK_NAME}<br>\r\n  Account: {BANK_ACCOUNT}<br>\r\n  Branch: {BANK_BRANCH}</p>\r\n  <p>Please contact us if you have any questions.</p>\r\n  <p>Best regards,<br>{COMPANY_NAME}</p>\r\n</div>\r\n</body></html>', 'Payment Reminder\r\n\r\nDear {CUSTOMER_NAME},\r\n\r\nInvoice {INVOICE_NUMBER} is due.\r\nAmount: {AMOUNT}\r\nDue Date: {DUE_DATE}\r\n\r\nPlease contact us if you have any questions.\r\n\r\nBest regards,\r\n{COMPANY_NAME}', 1, '2025-10-04 17:31:53', '2025-10-04 17:31:53'),
(4, 1, 'thank_you', 'Thank You - Payment Received', '<html><body style=\"font-family: Arial, sans-serif; color: #333;\">\r\n<div style=\"max-width: 600px; margin: 0 auto; padding: 20px;\">\r\n  <h2 style=\"color: #10b981;\">✅ Payment Received - Thank You!</h2>\r\n  <p>Dear {CUSTOMER_NAME},</p>\r\n  <p>We have received your payment for invoice <strong>{INVOICE_NUMBER}</strong>.</p>\r\n  <table style=\"width: 100%; border-collapse: collapse; margin: 20px 0;\">\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Invoice Number:</strong></td><td style=\"padding: 10px;\">{INVOICE_NUMBER}</td></tr>\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Amount Paid:</strong></td><td style=\"padding: 10px; font-size: 18px; font-weight: bold; color: #10b981;\">{AMOUNT}</td></tr>\r\n    <tr><td style=\"padding: 10px; background: #f5f5f5;\"><strong>Payment Date:</strong></td><td style=\"padding: 10px;\">{PAYMENT_DATE}</td></tr>\r\n  </table>\r\n  <p>Thank you for your business!</p>\r\n  <p>Best regards,<br>{COMPANY_NAME}</p>\r\n</div>\r\n</body></html>', 'Payment Received\r\n\r\nDear {CUSTOMER_NAME},\r\n\r\nWe have received your payment for invoice {INVOICE_NUMBER}.\r\n\r\nAmount: {AMOUNT}\r\nPayment Date: {PAYMENT_DATE}\r\n\r\nThank you for your business!\r\n\r\nBest regards,\r\n{COMPANY_NAME}', 1, '2025-10-04 17:31:53', '2025-10-04 17:31:53');

-- --------------------------------------------------------

--
-- Table structure for table `qi_sequences`
--

CREATE TABLE `qi_sequences` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `type` enum('quote','invoice','credit_note') NOT NULL,
  `year` int(11) NOT NULL,
  `next_number` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `qi_sequences`
--

INSERT INTO `qi_sequences` (`id`, `company_id`, `type`, `year`, `next_number`) VALUES
(5, 1, 'quote', 2025, 6),
(7, 1, 'invoice', 2025, 2);

-- --------------------------------------------------------

--
-- Table structure for table `qi_settings`
--

CREATE TABLE `qi_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `default_terms` text DEFAULT NULL,
  `default_payment_terms` int(11) DEFAULT 30,
  `default_tax_rate` decimal(5,2) DEFAULT 15.00,
  `logo_path` varchar(255) DEFAULT NULL,
  `brand_color` varchar(7) DEFAULT '#10b981',
  `yoco_mode` enum('test','live') DEFAULT 'test',
  `yoco_pub_key` varchar(255) DEFAULT NULL,
  `yoco_sec_key` varchar(255) DEFAULT NULL,
  `yoco_webhook_secret` varchar(255) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_holder` varchar(190) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `bank_branch_code` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quotes`
--

CREATE TABLE `quotes` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `quote_number` varchar(50) NOT NULL,
  `public_token` varchar(64) DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` int(10) UNSIGNED DEFAULT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('draft','sent','viewed','accepted','declined','expired','cancelled','converted') NOT NULL DEFAULT 'draft',
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax` decimal(15,2) DEFAULT 0.00,
  `total` decimal(15,2) DEFAULT 0.00,
  `currency` char(3) DEFAULT 'ZAR',
  `terms` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `accepted_by_name` varchar(190) DEFAULT NULL,
  `accepted_by_ip` varchar(64) DEFAULT NULL,
  `declined_at` datetime DEFAULT NULL,
  `declined_ip` varchar(45) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quotes`
--

INSERT INTO `quotes` (`id`, `company_id`, `quote_number`, `public_token`, `customer_id`, `contact_id`, `project_id`, `issue_date`, `expiry_date`, `status`, `subtotal`, `discount`, `tax`, `total`, `currency`, `terms`, `notes`, `accepted_at`, `accepted_by_name`, `accepted_by_ip`, `declined_at`, `declined_ip`, `created_by`, `pdf_path`, `created_at`, `updated_at`) VALUES
(1, 1, 'Q2025-0000', NULL, 2, NULL, 4, '2025-10-04', '2025-10-18', 'sent', 3344.00, 0.00, 501.60, 3845.60, 'ZAR', '', '', NULL, NULL, NULL, NULL, NULL, 1, NULL, '2025-10-04 14:56:57', '2025-10-04 22:20:41'),
(5, 1, 'Q2025-0004', NULL, 10, NULL, NULL, '2025-10-04', '2025-10-18', 'draft', 22500.00, 0.00, 3375.00, 25875.00, 'ZAR', '', '', NULL, NULL, NULL, NULL, NULL, 1, NULL, '2025-10-04 20:55:33', '2025-10-04 20:55:33'),
(6, 1, 'Q2025-0005', NULL, 1, NULL, NULL, '2025-10-04', '2025-10-19', 'draft', 22500.00, 0.00, 3375.00, 25875.00, 'ZAR', '', '', NULL, NULL, NULL, NULL, NULL, 1, NULL, '2025-10-04 20:56:23', '2025-10-07 01:48:40');

-- --------------------------------------------------------

--
-- Table structure for table `quote_lines`
--

CREATE TABLE `quote_lines` (
  `id` int(10) UNSIGNED NOT NULL,
  `quote_id` int(10) UNSIGNED NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 1.000,
  `unit` varchar(50) DEFAULT 'ea',
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 15.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) DEFAULT 0,
  `gl_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_item_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quote_lines`
--

INSERT INTO `quote_lines` (`id`, `quote_id`, `item_description`, `quantity`, `unit`, `unit_price`, `discount`, `tax_rate`, `line_total`, `sort_order`, `gl_account_id`, `project_item_id`) VALUES
(1, 1, 'gsergsdfrg', 1.000, 'unit', 3344.00, 0.00, 15.00, 3845.60, 0, NULL, NULL),
(5, 5, '10 m3 Concrete 30mpa', 10.000, 'ea', 2250.00, 0.00, 15.00, 25875.00, 0, NULL, NULL),
(8, 6, '10 m3 Concrete 30mpa', 10.000, 'ea', 2250.00, 0.00, 15.00, 22500.00, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `receipt_file`
--

CREATE TABLE `receipt_file` (
  `file_id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `bill_id` int(10) DEFAULT NULL,
  `invoice_id` int(10) DEFAULT NULL,
  `path` varchar(255) NOT NULL,
  `mime` varchar(120) DEFAULT NULL,
  `size_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ocr_status` enum('pending','parsed','failed') NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `receipt_file`
--

INSERT INTO `receipt_file` (`file_id`, `company_id`, `bill_id`, `invoice_id`, `path`, `mime`, `size_bytes`, `uploaded_by`, `uploaded_at`, `ocr_status`) VALUES
(1, 1, NULL, NULL, 'uploads/receipts/1/202510/receipt_68e199f09557e4.18009534.jpeg', 'image/jpeg', 252883, 1, '2025-10-05 00:04:32', 'parsed'),
(2, 1, NULL, NULL, 'uploads/receipts/1/202510/receipt_68e3a72479df16.01986373.jpeg', 'image/jpeg', 252883, 1, '2025-10-06 13:25:24', 'parsed');

-- --------------------------------------------------------

--
-- Table structure for table `receipt_match`
--

CREATE TABLE `receipt_match` (
  `match_id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `bill_id` int(10) DEFAULT NULL,
  `invoice_id` int(10) NOT NULL,
  `target_type` enum('po','grn','board_item') NOT NULL,
  `target_id` bigint(20) UNSIGNED NOT NULL,
  `match_strength` decimal(5,2) NOT NULL DEFAULT 0.00,
  `variance_amount` decimal(15,2) DEFAULT NULL,
  `variance_pct` decimal(5,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receipt_ocr`
--

CREATE TABLE `receipt_ocr` (
  `ocr_id` bigint(20) UNSIGNED NOT NULL,
  `file_id` bigint(20) UNSIGNED NOT NULL,
  `raw_json` longtext DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `vendor_vat` varchar(64) DEFAULT NULL,
  `invoice_number` varchar(190) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `currency` char(3) DEFAULT 'ZAR',
  `subtotal` decimal(15,2) DEFAULT NULL,
  `tax` decimal(15,2) DEFAULT NULL,
  `total` decimal(15,2) DEFAULT NULL,
  `line_items_json` longtext DEFAULT NULL,
  `confidence_score` decimal(5,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `receipt_ocr`
--

INSERT INTO `receipt_ocr` (`ocr_id`, `file_id`, `raw_json`, `vendor_name`, `vendor_vat`, `invoice_number`, `invoice_date`, `currency`, `subtotal`, `tax`, `total`, `line_items_json`, `confidence_score`, `created_at`) VALUES
(1, 1, '{\"vendor_name\":\"Unknown Supplier\",\"vendor_vat\":null,\"invoice_number\":\"INV-3406\",\"invoice_date\":\"2025-10-05\",\"currency\":\"ZAR\",\"subtotal\":6300,\"tax\":0,\"total\":0,\"lines\":[{\"description\":\"Item 1\",\"qty\":1,\"unit\":\"ea\",\"unit_price\":130,\"tax_rate\":15}],\"confidence\":0}', 'Unknown Supplier', NULL, 'INV-3406', '2025-10-05', 'ZAR', 6300.00, 0.00, 0.00, '[{\"description\":\"Item 1\",\"qty\":1,\"unit\":\"ea\",\"unit_price\":130,\"tax_rate\":15}]', 0.00, '2025-10-05 00:04:33'),
(2, 2, '{\"vendor_name\":\"Unknown Supplier\",\"vendor_vat\":null,\"invoice_number\":\"INV-4669\",\"invoice_date\":\"2025-10-06\",\"currency\":\"ZAR\",\"subtotal\":1176,\"tax\":0,\"total\":0,\"lines\":[{\"description\":\"Item 1\",\"qty\":1,\"unit\":\"ea\",\"unit_price\":335,\"tax_rate\":15}],\"confidence\":0}', 'Unknown Supplier', NULL, 'INV-4669', '2025-10-06', 'ZAR', 1176.00, 0.00, 0.00, '[{\"description\":\"Item 1\",\"qty\":1,\"unit\":\"ea\",\"unit_price\":335,\"tax_rate\":15}]', 0.00, '2025-10-06 13:25:24');

-- --------------------------------------------------------

--
-- Table structure for table `receipt_policy_log`
--

CREATE TABLE `receipt_policy_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `invoice_id` int(10) DEFAULT NULL,
  `file_id` bigint(20) UNSIGNED DEFAULT NULL,
  `code` varchar(64) NOT NULL,
  `message` varchar(255) NOT NULL,
  `severity` enum('info','warn','error') NOT NULL DEFAULT 'warn',
  `override_by` int(11) DEFAULT NULL,
  `override_reason` varchar(500) DEFAULT NULL,
  `override_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receipt_settings`
--

CREATE TABLE `receipt_settings` (
  `setting_id` int(10) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `require_po_over_amount` decimal(15,2) DEFAULT 5000.00,
  `require_3way_match` tinyint(1) DEFAULT 0,
  `variance_tolerance_pct` decimal(5,2) DEFAULT 5.00,
  `line_variance_tolerance_pct` decimal(5,2) DEFAULT 10.00,
  `require_vat_number` tinyint(1) DEFAULT 1,
  `block_expired_compliance` tinyint(1) DEFAULT 1,
  `default_expense_account` varchar(64) DEFAULT '5000',
  `max_file_size_mb` int(11) DEFAULT 15,
  `ocr_provider` varchar(32) DEFAULT 'tesseract',
  `auto_split_lines` tinyint(1) DEFAULT 1,
  `retention_days` int(11) DEFAULT 2555,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `receipt_settings`
--

INSERT INTO `receipt_settings` (`setting_id`, `company_id`, `require_po_over_amount`, `require_3way_match`, `variance_tolerance_pct`, `line_variance_tolerance_pct`, `require_vat_number`, `block_expired_compliance`, `default_expense_account`, `max_file_size_mb`, `ocr_provider`, `auto_split_lines`, `retention_days`, `created_at`, `updated_at`) VALUES
(1, 1, 5000.00, 0, 5.00, 10.00, 1, 1, '5000', 15, 'tesseract', 1, 2555, '2025-10-06 13:24:47', '2025-10-06 13:24:47');

-- --------------------------------------------------------

--
-- Table structure for table `recurring_invoices`
--

CREATE TABLE `recurring_invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `frequency` enum('weekly','monthly','quarterly','yearly') NOT NULL,
  `interval_count` int(11) NOT NULL DEFAULT 1,
  `next_run_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `last_invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `last_generated_date` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `template_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`template_data`)),
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recurring_invoice_lines`
--

CREATE TABLE `recurring_invoice_lines` (
  `id` int(10) UNSIGNED NOT NULL,
  `recurring_invoice_id` int(10) UNSIGNED NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 1.000,
  `unit` varchar(50) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 15.00,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shopping_actions`
--

CREATE TABLE `shopping_actions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `list_id` bigint(20) UNSIGNED NOT NULL,
  `item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL COMMENT 'add, edit, check, uncheck, remove, buy, rfq, po',
  `payload_json` longtext DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shopping_actions`
--

INSERT INTO `shopping_actions` (`id`, `company_id`, `list_id`, `item_id`, `user_id`, `action`, `payload_json`, `ip`, `created_at`) VALUES
(1, 1, 1, NULL, 1, 'add', '{\"item_count\":1}', '102.22.121.194', '2025-10-02 18:35:52'),
(2, 1, 1, 2, 1, 'add', '{\"name\":\"coke\",\"qty\":1}', '102.22.121.194', '2025-10-02 18:36:25'),
(3, 1, 1, NULL, 1, 'buy', '{\"session_id\":\"1\"}', '102.22.121.194', '2025-10-02 18:37:04'),
(4, 1, 1, 1, 1, 'check', '{\"status\":\"bought\"}', '102.22.121.194', '2025-10-02 18:37:55'),
(5, 1, 1, 1, 1, 'uncheck', '{\"status\":\"pending\"}', '102.22.121.194', '2025-10-02 18:37:57'),
(6, 1, 1, 1, 1, 'remove', '{\"name\":\"PVC Elbow 20mm 6m Conduit Milk\"}', '102.22.121.194', '2025-10-02 18:38:04'),
(7, 1, 1, NULL, 1, 'buy', '{\"session_id\":\"2\"}', '102.22.121.194', '2025-10-02 18:48:30');

-- --------------------------------------------------------

--
-- Table structure for table `shopping_candidates`
--

CREATE TABLE `shopping_candidates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `list_id` bigint(20) UNSIGNED NOT NULL,
  `item_id` bigint(20) UNSIGNED NOT NULL,
  `source` enum('crm','places','bing','catalog','history') NOT NULL,
  `store_id` bigint(20) DEFAULT NULL COMMENT 'Links to crm_accounts or shopping_stores',
  `store_name` varchar(255) NOT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `distance_km` decimal(6,2) DEFAULT NULL,
  `est_price_cents` bigint(20) DEFAULT NULL,
  `stock_confidence` decimal(3,2) DEFAULT 0.50 COMMENT '0-1 scale',
  `aisle` varchar(100) DEFAULT NULL,
  `pickup` enum('yes','no','unknown') DEFAULT 'unknown',
  `delivery_eta_mins` int(11) DEFAULT NULL,
  `score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `explanation` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shopping_candidates`
--

INSERT INTO `shopping_candidates` (`id`, `list_id`, `item_id`, `source`, `store_id`, `store_name`, `phone`, `address`, `lat`, `lng`, `distance_km`, `est_price_cents`, `stock_confidence`, `aisle`, `pickup`, `delivery_eta_mins`, `score`, `explanation`, `created_at`) VALUES
(21, 1, 2, 'crm', 9, 'ABC General Contractor', '0813456789', NULL, NULL, NULL, NULL, NULL, 0.70, NULL, 'unknown', NULL, 0.95, '✅ Found in your CRM (preferred supplier)', '2025-10-21 08:35:00'),
(22, 1, 2, 'crm', 6, 'Quick Plumbers ZA', '0837654321', NULL, NULL, NULL, NULL, NULL, 0.70, NULL, 'unknown', NULL, 0.95, '✅ Found in your CRM (preferred supplier)', '2025-10-21 08:35:00'),
(23, 1, 2, 'crm', 4, 'ABCD Electrical', '+27837654321', NULL, NULL, NULL, NULL, NULL, 0.70, NULL, 'unknown', NULL, 0.75, '✅ Found in your CRM', '2025-10-21 08:35:00'),
(24, 1, 2, 'crm', 1, 'Adriaan', NULL, NULL, NULL, NULL, NULL, NULL, 0.70, NULL, 'unknown', NULL, 0.75, '✅ Found in your CRM', '2025-10-21 08:35:00'),
(25, 1, 2, 'crm', 7, 'Elite Plumbing & Geysers', '0612345678', NULL, NULL, NULL, NULL, NULL, 0.70, NULL, 'unknown', NULL, 0.75, '✅ Found in your CRM', '2025-10-21 08:35:00');

-- --------------------------------------------------------

--
-- Table structure for table `shopping_items`
--

CREATE TABLE `shopping_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `list_id` bigint(20) UNSIGNED NOT NULL,
  `name_raw` varchar(500) NOT NULL,
  `name_norm` varchar(500) NOT NULL,
  `qty` decimal(15,3) NOT NULL DEFAULT 1.000,
  `unit` varchar(50) DEFAULT 'ea',
  `priority` enum('low','med','high') DEFAULT 'med',
  `notes` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `category_id` int(10) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `item_id` bigint(20) DEFAULT NULL COMMENT 'board item',
  `needed_by` datetime DEFAULT NULL,
  `status` enum('pending','found','bought','removed') NOT NULL DEFAULT 'pending',
  `order_index` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shopping_items`
--

INSERT INTO `shopping_items` (`id`, `list_id`, `name_raw`, `name_norm`, `qty`, `unit`, `priority`, `notes`, `image_path`, `category_id`, `project_id`, `board_id`, `item_id`, `needed_by`, `status`, `order_index`, `created_at`, `updated_at`) VALUES
(1, 1, 'PVC Elbow 20mm 6m Conduit Milk', 'pvc elbow 20mm 6m conduit milk', 2.000, 'ea', 'med', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'removed', 0, '2025-10-02 18:35:52', '2025-10-02 18:38:04'),
(2, 1, 'coke', 'coke', 1.000, 'ea', 'med', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', 1, '2025-10-02 18:36:25', '2025-10-02 18:36:25');

-- --------------------------------------------------------

--
-- Table structure for table `shopping_lists`
--

CREATE TABLE `shopping_lists` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `purpose` enum('procurement','groceries','general') NOT NULL DEFAULT 'general',
  `owner_id` int(11) NOT NULL,
  `shared_mode` enum('private','team','token') NOT NULL DEFAULT 'private',
  `budget_cents` bigint(20) DEFAULT 0,
  `preferred_stores_json` longtext DEFAULT NULL COMMENT 'JSON array of store IDs',
  `status` enum('open','buying','done') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shopping_lists`
--

INSERT INTO `shopping_lists` (`id`, `company_id`, `name`, `purpose`, `owner_id`, `shared_mode`, `budget_cents`, `preferred_stores_json`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'Shopping List 10/2/2025', 'general', 1, 'private', 0, NULL, 'open', '2025-10-02 18:35:52', '2025-10-02 18:48:35');

-- --------------------------------------------------------

--
-- Table structure for table `shopping_preferences`
--

CREATE TABLE `shopping_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `default_radius_km` decimal(6,2) DEFAULT 25.00,
  `avoid_stores_json` longtext DEFAULT NULL COMMENT 'JSON array',
  `prefer_stores_json` longtext DEFAULT NULL COMMENT 'JSON array',
  `brand_prefs_json` longtext DEFAULT NULL,
  `unit_prefs_json` longtext DEFAULT NULL,
  `route_mode` enum('drive','walk','bike') DEFAULT 'drive',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shopping_preferences`
--

INSERT INTO `shopping_preferences` (`id`, `company_id`, `user_id`, `default_radius_km`, `avoid_stores_json`, `prefer_stores_json`, `brand_prefs_json`, `unit_prefs_json`, `route_mode`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 25.00, NULL, NULL, NULL, NULL, 'drive', '2025-10-02 21:28:20', '2025-10-02 21:28:20');

-- --------------------------------------------------------

--
-- Table structure for table `shopping_price_history`
--

CREATE TABLE `shopping_price_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_fingerprint` varchar(64) NOT NULL COMMENT 'Hash of name_norm+unit',
  `store_id` bigint(20) DEFAULT NULL,
  `price_cents` bigint(20) NOT NULL,
  `seen_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shopping_sessions`
--

CREATE TABLE `shopping_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `list_id` bigint(20) UNSIGNED NOT NULL,
  `started_by` int(11) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `active_store_id` bigint(20) DEFAULT NULL,
  `eta_route_mins` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shopping_sessions`
--

INSERT INTO `shopping_sessions` (`id`, `list_id`, `started_by`, `started_at`, `ended_at`, `active_store_id`, `eta_route_mins`) VALUES
(1, 1, 1, '2025-10-02 18:37:04', '2025-10-02 18:37:59', NULL, NULL),
(2, 1, 1, '2025-10-02 18:48:30', '2025-10-02 18:48:35', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `shopping_share_tokens`
--

CREATE TABLE `shopping_share_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `list_id` bigint(20) UNSIGNED NOT NULL,
  `token` varchar(64) NOT NULL,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_check` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shopping_stores`
--

CREATE TABLE `shopping_stores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `crm_account_id` bigint(20) DEFAULT NULL COMMENT 'Link to crm_accounts if supplier',
  `name` varchar(255) NOT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `categories_json` longtext DEFAULT NULL COMMENT 'JSON array',
  `preferred` tinyint(1) NOT NULL DEFAULT 0,
  `open_hours_json` longtext DEFAULT NULL,
  `delivery_options_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shopping_store_catalog`
--

CREATE TABLE `shopping_store_catalog` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `store_id` bigint(20) UNSIGNED NOT NULL,
  `sku` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit` varchar(50) DEFAULT 'ea',
  `pack_size` decimal(10,3) DEFAULT 1.000,
  `price_cents` bigint(20) NOT NULL,
  `vat_rate` decimal(5,2) DEFAULT 15.00,
  `category_id` int(10) DEFAULT NULL,
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shopping_templates`
--

CREATE TABLE `shopping_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `purpose` enum('procurement','groceries','general') NOT NULL DEFAULT 'general',
  `items_json` longtext NOT NULL COMMENT 'JSON array of {name,qty,unit}',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `yoco_subscription_id` varchar(128) DEFAULT NULL,
  `status` enum('pending','active','past_due','canceled') NOT NULL DEFAULT 'pending',
  `current_period_end` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `company_id`, `plan_id`, `yoco_subscription_id`, `status`, `current_period_end`, `created_at`, `updated_at`) VALUES
(1, 1, 2, NULL, 'active', '2025-10-30 09:01:54', '2025-09-30 09:01:54', '2025-10-02 20:06:53');

-- --------------------------------------------------------

--
-- Table structure for table `tax_tables_za`
--

CREATE TABLE `tax_tables_za` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED DEFAULT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `bracket_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Array of {threshold_cents, rate_bp, cumulative_cents}' CHECK (json_valid(`bracket_json`)),
  `rebate_primary_cents` bigint(20) NOT NULL DEFAULT 0,
  `rebate_secondary_cents` bigint(20) NOT NULL DEFAULT 0,
  `rebate_tertiary_cents` bigint(20) NOT NULL DEFAULT 0,
  `uif_cap_annual_cents` bigint(20) NOT NULL DEFAULT 17712200,
  `uif_rate_bp` int(11) NOT NULL DEFAULT 10000 COMMENT '1% = 10000 basis points',
  `sdl_rate_bp` int(11) NOT NULL DEFAULT 10000,
  `medical_credit_main_cents` bigint(20) NOT NULL DEFAULT 0,
  `medical_credit_dep_cents` bigint(20) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tax_tables_za`
--

INSERT INTO `tax_tables_za` (`id`, `company_id`, `effective_from`, `effective_to`, `bracket_json`, `rebate_primary_cents`, `rebate_secondary_cents`, `rebate_tertiary_cents`, `uif_cap_annual_cents`, `uif_rate_bp`, `sdl_rate_bp`, `medical_credit_main_cents`, `medical_credit_dep_cents`, `created_at`) VALUES
(1, NULL, '2024-03-01', '2025-02-28', '[\r\n    {\"threshold_cents\": 0, \"rate_bp\": 1800, \"cumulative_cents\": 0},\r\n    {\"threshold_cents\": 23730000, \"rate_bp\": 2600, \"cumulative_cents\": 4271400},\r\n    {\"threshold_cents\": 37000000, \"rate_bp\": 3100, \"cumulative_cents\": 7738500},\r\n    {\"threshold_cents\": 51200000, \"rate_bp\": 3600, \"cumulative_cents\": 12170500},\r\n    {\"threshold_cents\": 67300000, \"rate_bp\": 3900, \"cumulative_cents\": 17967500},\r\n    {\"threshold_cents\": 87000000, \"rate_bp\": 4100, \"cumulative_cents\": 25643500},\r\n    {\"threshold_cents\": 179400000, \"rate_bp\": 4500, \"cumulative_cents\": 63488500}\r\n  ]', 1748400, 965700, 322200, 17712200, 10000, 10000, 34700, 23400, '2025-10-01 20:14:41');

-- --------------------------------------------------------

--
-- Table structure for table `timesheets`
--

CREATE TABLE `timesheets` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `hours` decimal(6,2) NOT NULL,
  `billable` tinyint(1) NOT NULL DEFAULT 1,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timesheet_entries`
--

CREATE TABLE `timesheet_entries` (
  `id` bigint(20) NOT NULL,
  `company_id` int(10) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `ts_date` date NOT NULL,
  `regular_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `ot_hours` decimal(8,2) DEFAULT 0.00,
  `sunday_hours` decimal(8,2) DEFAULT 0.00,
  `public_holiday_hours` decimal(8,2) DEFAULT 0.00,
  `project_id` int(10) DEFAULT NULL,
  `board_id` int(10) DEFAULT NULL,
  `item_id` bigint(20) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'submitted',
  `approved_by` int(10) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `role` enum('admin','member','viewer','pos','bookkeeper') NOT NULL DEFAULT 'member',
  `is_seat` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Bookkeepers have is_seat=0',
  `session_token` varchar(64) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `status` enum('active','suspended','invited') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `company_id`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `avatar_path`, `role`, `is_seat`, `session_token`, `last_login_at`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'adriaan@concreto.co.za', '$2y$10$bFrT3a4cEA4mvwi0qa4FS.HhukZNNUG7i3O8SGLAZjX/GmbiB56vW', 'Adriaan', 'Geldenhuis', NULL, NULL, 'admin', 1, '46c5163a98d954e02b8ff09d6b08f2e0c7c52c5be9f98cc5fa51993f8c260aaa', '2026-03-01 19:12:54', 'active', '2025-09-30 09:01:54', '2026-03-01 19:12:54'),
(3, 1, 'catherine@concreto.co.za', '$2y$10$fOVPndVApY3/25vatSDiOO.GxnfdmO0ZrIs4Hv0mRp0kd0WmGiKPm', 'Catherine', 'Geldenhuis', NULL, NULL, 'member', 1, '913b31549345f16a74ad89be6661591b8a19cfc0689b606cdd9bb57a0c3e4126', '2026-02-26 11:43:40', 'active', '2025-10-14 12:55:28', '2026-02-26 11:43:40'),
(4, 1, 'anita@concreto.co.za', '$2y$10$d75TX5Ks7a4.9cFfd2Dmm.UdQ5pszoeqFPDkgFf6Uv390JPEeYZY6', 'Anita', 'Geldenhuis', NULL, NULL, 'admin', 1, 'e196c812cab304bedd9a0b5f06479806', '2025-10-18 13:43:07', 'active', '2025-10-18 13:41:35', '2025-10-18 13:43:07');

-- --------------------------------------------------------

--
-- Table structure for table `user_companies`
--

CREATE TABLE `user_companies` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `role` enum('admin','member','viewer','pos','bookkeeper') NOT NULL DEFAULT 'member',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_companies`
--

INSERT INTO `user_companies` (`id`, `user_id`, `company_id`, `role`, `created_at`) VALUES
(1, 1, 1, 'admin', '2025-09-30 09:01:54');

-- --------------------------------------------------------

--
-- Table structure for table `user_mail_prefs`
--

CREATE TABLE `user_mail_prefs` (
  `user_id` int(10) NOT NULL,
  `company_id` int(10) NOT NULL,
  `sla_default_hours` int(11) DEFAULT 24,
  `signature_id` int(10) DEFAULT NULL,
  `block_external_images` tinyint(1) DEFAULT 1,
  `notification_enabled` tinyint(1) DEFAULT 1,
  `notify_channels_json` longtext DEFAULT NULL,
  `quiet_hours_json` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_mail_prefs`
--

INSERT INTO `user_mail_prefs` (`user_id`, `company_id`, `sla_default_hours`, `signature_id`, `block_external_images`, `notification_enabled`, `notify_channels_json`, `quiet_hours_json`, `created_at`, `updated_at`) VALUES
(1, 1, 24, NULL, 1, 1, NULL, NULL, '2025-10-02 16:25:41', '2025-10-02 18:52:25');

-- --------------------------------------------------------

--
-- Table structure for table `vat_entries`
--

CREATE TABLE `vat_entries` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `vat_return_id` int(10) UNSIGNED DEFAULT NULL,
  `txn_date` date NOT NULL,
  `source_type` enum('invoice','bill','journal','import') NOT NULL,
  `source_id` int(10) UNSIGNED NOT NULL,
  `tax_code` varchar(32) NOT NULL,
  `net_cents` bigint(20) NOT NULL DEFAULT 0,
  `vat_cents` bigint(20) NOT NULL DEFAULT 0,
  `gl_account_id` int(10) UNSIGNED DEFAULT NULL,
  `journal_line_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vat_returns`
--

CREATE TABLE `vat_returns` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('open','filed','paid','amended') NOT NULL DEFAULT 'open',
  `payable_cents` bigint(20) NOT NULL DEFAULT 0,
  `journal_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_credits`
--

CREATE TABLE `vendor_credits` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `credit_number` varchar(50) NOT NULL,
  `supplier_id` bigint(20) NOT NULL,
  `issue_date` date NOT NULL,
  `status` enum('draft','approved','applied','cancelled') NOT NULL DEFAULT 'draft',
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `tax` decimal(15,2) DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL,
  `journal_id` bigint(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_credit_allocations`
--

CREATE TABLE `vendor_credit_allocations` (
  `id` int(11) NOT NULL,
  `credit_id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_credit_lines`
--

CREATE TABLE `vendor_credit_lines` (
  `id` int(11) NOT NULL,
  `credit_id` int(11) NOT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 1.000,
  `unit` varchar(50) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 15.00,
  `line_total` decimal(15,2) NOT NULL,
  `gl_account_id` bigint(20) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `webhooks`
--

CREATE TABLE `webhooks` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(64) NOT NULL,
  `events_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`events_json`)),
  `active` tinyint(1) DEFAULT 1,
  `last_delivery_at` datetime DEFAULT NULL,
  `last_status` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `yoco_payment_events`
--

CREATE TABLE `yoco_payment_events` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `event_id` varchar(128) DEFAULT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure for view `drive_files`
--
DROP TABLE IF EXISTS `drive_files`;

CREATE ALGORITHM=UNDEFINED DEFINER=`flowwwqmnt_1`@`%` SQL SECURITY DEFINER VIEW `drive_files`  AS SELECT `n`.`id` AS `node_id`, coalesce(nullif(`n`.`disk_path`,''),`b`.`storage_path`) AS `storage_path`, coalesce(`b`.`mime_type`,case lcase(coalesce(`n`.`ext`,'')) when 'pdf' then 'application/pdf' when 'jpg' then 'image/jpeg' when 'jpeg' then 'image/jpeg' when 'png' then 'image/png' when 'gif' then 'image/gif' else 'application/octet-stream' end) AS `mime_type`, coalesce(`n`.`size_bytes`,`b`.`size_bytes`,0) AS `size`, concat(`n`.`name`,case when `n`.`ext` is not null and `n`.`ext` <> '' then concat('.',`n`.`ext`) else '' end) AS `original_name` FROM (`fd_nodes` `n` left join `fd_blobs` `b` on(`b`.`id` = `n`.`blob_id`)) WHERE `n`.`type` = 'file' ;

-- --------------------------------------------------------

--
-- Structure for view `drive_nodes`
--
DROP TABLE IF EXISTS `drive_nodes`;

CREATE ALGORITHM=UNDEFINED DEFINER=`flowwwqmnt_1`@`%` SQL SECURITY DEFINER VIEW `drive_nodes`  AS SELECT `n`.`id` AS `id`, `d`.`company_id` AS `company_id`, `n`.`drive_id` AS `drive_id`, `n`.`name` AS `name`, CASE WHEN `n`.`deleted_at` is null THEN 0 ELSE 1 END AS `is_deleted` FROM (`fd_nodes` `n` join `fd_drives` `d` on(`d`.`id` = `n`.`drive_id`)) WHERE `n`.`type` in ('file','folder') ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_actions`
--
ALTER TABLE `ai_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_query` (`company_id`,`query_id`),
  ADD KEY `idx_company_user` (`company_id`,`user_id`);

--
-- Indexes for table `ai_candidates`
--
ALTER TABLE `ai_candidates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_query_rank` (`company_id`,`query_id`,`rank`),
  ADD KEY `idx_account` (`company_id`,`account_id`);
ALTER TABLE `ai_candidates` ADD FULLTEXT KEY `ft_search2` (`name`,`address`);

--
-- Indexes for table `ai_hints`
--
ALTER TABLE `ai_hints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aihints_company_type_key` (`company_id`,`hint_type`,`hint_key`),
  ADD KEY `idx_aihints_hits` (`hits`);

--
-- Indexes for table `ai_queries`
--
ALTER TABLE `ai_queries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_time` (`company_id`,`created_at`),
  ADD KEY `idx_company_user` (`company_id`,`user_id`);

--
-- Indexes for table `ai_results`
--
ALTER TABLE `ai_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_query` (`company_id`,`query_id`);
ALTER TABLE `ai_results` ADD FULLTEXT KEY `ft_search` (`name`,`address`,`category`);

--
-- Indexes for table `ai_settings`
--
ALTER TABLE `ai_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_id` (`company_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `token_hash` (`token_hash`),
  ADD KEY `idx_company_revoked` (`company_id`,`revoked_at`);

--
-- Indexes for table `ap_bills`
--
ALTER TABLE `ap_bills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ap_bill_company_hash` (`company_id`,`hash_fingerprint`),
  ADD KEY `idx_ap_bills_supplier` (`supplier_id`),
  ADD KEY `idx_ap_bills_ocr` (`ocr_id`),
  ADD KEY `idx_ap_bills_file` (`file_id`),
  ADD KEY `idx_ap_bills_company_status` (`company_id`,`status`),
  ADD KEY `idx_ap_bills_supplier_date` (`supplier_id`,`issue_date`);

--
-- Indexes for table `ap_bill_lines`
--
ALTER TABLE `ap_bill_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lines_bill` (`bill_id`),
  ADD KEY `idx_lines_project` (`project_board_id`,`project_item_id`),
  ADD KEY `idx_ap_bill_lines_item` (`inventory_item_id`),
  ADD KEY `idx_ap_bill_lines_bill` (`bill_id`);

--
-- Indexes for table `ap_match_links`
--
ALTER TABLE `ap_match_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ap_match_po_line_id` (`po_line_id`),
  ADD KEY `idx_ap_match_grn_line_id` (`grn_line_id`),
  ADD KEY `idx_ap_match_bill_line_id` (`bill_line_id`),
  ADD KEY `idx_ap_match_company_id` (`company_id`);

--
-- Indexes for table `ap_payments`
--
ALTER TABLE `ap_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ap_payments_company_date` (`company_id`,`payment_date`);

--
-- Indexes for table `ap_payment_allocations`
--
ALTER TABLE `ap_payment_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ap_alloc_payment` (`ap_payment_id`),
  ADD KEY `idx_ap_alloc_bill` (`bill_id`);

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_company_timestamp` (`company_id`,`timestamp`),
  ADD KEY `idx_user_timestamp` (`user_id`,`timestamp`);

--
-- Indexes for table `auth_sessions`
--
ALTER TABLE `auth_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_app_device` (`user_id`,`app_key`,`device_id`),
  ADD KEY `idx_token` (`session_token`);

--
-- Indexes for table `bank_file_exports`
--
ALTER TABLE `bank_file_exports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_run` (`company_id`,`run_id`);

--
-- Indexes for table `bank_import_batches`
--
ALTER TABLE `bank_import_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bank_reconciliations`
--
ALTER TABLE `bank_reconciliations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bank_period` (`bank_account_id`,`statement_start`,`statement_end`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_bank` (`bank_account_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `bank_reconciliation_lines`
--
ALTER TABLE `bank_reconciliation_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reco` (`reconciliation_id`),
  ADD KEY `idx_tx` (`bank_transaction_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `board_activity`
--
ALTER TABLE `board_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `board_id` (`board_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `board_audit_log`
--
ALTER TABLE `board_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `idx_item` (`item_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `board_automations`
--
ALTER TABLE `board_automations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `board_automation_logs`
--
ALTER TABLE `board_automation_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_automation` (`automation_id`),
  ADD KEY `idx_item` (`item_id`);

--
-- Indexes for table `board_columns`
--
ALTER TABLE `board_columns`
  ADD PRIMARY KEY (`column_id`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `idx_board_position` (`board_id`,`position`),
  ADD KEY `idx_board_columns_board` (`board_id`,`company_id`),
  ADD KEY `idx_columns_board_pos` (`board_id`,`company_id`,`position`),
  ADD KEY `idx_supplier_id` (`supplier_id`);

--
-- Indexes for table `board_column_settings`
--
ALTER TABLE `board_column_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_board` (`board_id`,`user_id`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `board_dependencies`
--
ALTER TABLE `board_dependencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `idx_predecessor` (`predecessor_item`),
  ADD KEY `idx_successor` (`successor_item`);

--
-- Indexes for table `board_groups`
--
ALTER TABLE `board_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `idx_board_position` (`board_id`,`position`),
  ADD KEY `idx_board_groups_board` (`board_id`),
  ADD KEY `idx_groups_board_pos` (`board_id`,`position`);

--
-- Indexes for table `board_guests`
--
ALTER TABLE `board_guests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD UNIQUE KEY `unique_board_email` (`board_id`,`email`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `invited_by` (`invited_by`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `board_guest_activity`
--
ALTER TABLE `board_guest_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_guest` (`guest_id`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `board_items`
--
ALTER TABLE `board_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_group` (`group_id`),
  ADD KEY `idx_company_board_group` (`company_id`,`board_id`,`group_id`),
  ADD KEY `idx_assigned` (`assigned_to`),
  ADD KEY `idx_board_items_board_group` (`board_id`,`group_id`),
  ADD KEY `idx_board_items_status` (`status_label`),
  ADD KEY `idx_board_items_assigned` (`assigned_to`),
  ADD KEY `idx_board_items_dates` (`due_date`,`start_date`,`end_date`),
  ADD KEY `idx_board_items_company` (`company_id`),
  ADD KEY `idx_board_items_group` (`group_id`),
  ADD KEY `idx_items_board_group` (`board_id`,`group_id`,`position`,`company_id`,`archived`);

--
-- Indexes for table `board_item_attachments`
--
ALTER TABLE `board_item_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `board_item_comments`
--
ALTER TABLE `board_item_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `board_item_values`
--
ALTER TABLE `board_item_values`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_column` (`item_id`,`column_id`),
  ADD UNIQUE KEY `unique_item_column` (`item_id`,`column_id`),
  ADD UNIQUE KEY `uniq_item_col` (`item_id`,`column_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `column_id` (`column_id`),
  ADD KEY `idx_item_column` (`item_id`,`column_id`),
  ADD KEY `idx_board_item_values_lookup` (`item_id`,`column_id`),
  ADD KEY `idx_board_item_values_item` (`item_id`),
  ADD KEY `idx_board_item_values_column` (`column_id`);

--
-- Indexes for table `board_members`
--
ALTER TABLE `board_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `board_user` (`board_id`,`user_id`);

--
-- Indexes for table `board_saved_views`
--
ALTER TABLE `board_saved_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_board` (`board_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `board_subitems`
--
ALTER TABLE `board_subitems`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_item_id` (`parent_item_id`),
  ADD KEY `board_id` (`board_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `board_watchers`
--
ALTER TABLE `board_watchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_user` (`item_id`,`user_id`);

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_year_name` (`company_id`,`fiscal_year`,`name`),
  ADD KEY `idx_company_year` (`company_id`,`fiscal_year`);

--
-- Indexes for table `budget_lines`
--
ALTER TABLE `budget_lines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_budget_account` (`budget_id`,`gl_account_id`),
  ADD KEY `idx_budget` (`budget_id`),
  ADD KEY `idx_account` (`gl_account_id`);

--
-- Indexes for table `calendars`
--
ALTER TABLE `calendars`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ics_token` (`ics_token`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_owner` (`owner_id`),
  ADD KEY `idx_project` (`project_id`);

--
-- Indexes for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_calendar` (`calendar_id`),
  ADD KEY `idx_company_time` (`company_id`,`start_datetime`,`end_datetime`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `calendar_event_attachments`
--
ALTER TABLE `calendar_event_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event` (`event_id`);

--
-- Indexes for table `calendar_event_links`
--
ALTER TABLE `calendar_event_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event` (`event_id`),
  ADD KEY `idx_link` (`linked_type`,`linked_id`);

--
-- Indexes for table `calendar_event_participants`
--
ALTER TABLE `calendar_event_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_participant` (`event_id`,`user_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `calendar_event_reminders`
--
ALTER TABLE `calendar_event_reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event` (`event_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `calendar_event_templates`
--
ALTER TABLE `calendar_event_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `calendar_notes`
--
ALTER TABLE `calendar_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_calendar_date` (`calendar_id`,`note_date`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `calendar_settings`
--
ALTER TABLE `calendar_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_settings` (`company_id`,`user_id`);

--
-- Indexes for table `calendar_subscriptions`
--
ALTER TABLE `calendar_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `calendar_tasks`
--
ALTER TABLE `calendar_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_calendar` (`calendar_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_due` (`due_datetime`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `calendar_working_hours`
--
ALTER TABLE `calendar_working_hours`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `idx_yoco_customer` (`yoco_customer_id`),
  ADD KEY `idx_subscription_active` (`subscription_active`);

--
-- Indexes for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_setting` (`company_id`,`setting_key`),
  ADD UNIQUE KEY `uq_company_key` (`company_id`,`setting_key`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `cost_items`
--
ALTER TABLE `cost_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cost_items_project` (`project_id`,`company_id`),
  ADD KEY `idx_cost_items_item` (`item_id`,`company_id`);

--
-- Indexes for table `credit_notes`
--
ALTER TABLE `credit_notes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_credit_num` (`company_id`,`credit_note_number`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_journal` (`journal_id`);

--
-- Indexes for table `credit_note_allocations`
--
ALTER TABLE `credit_note_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cna_company_credit` (`company_id`,`credit_note_id`);

--
-- Indexes for table `credit_note_lines`
--
ALTER TABLE `credit_note_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_credit` (`credit_note_id`);

--
-- Indexes for table `crm_accounts`
--
ALTER TABLE `crm_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_account_name` (`company_id`,`type`,`name`),
  ADD KEY `idx_company_type` (`company_id`,`type`),
  ADD KEY `idx_company_email` (`company_id`,`email`),
  ADD KEY `idx_company_vat` (`company_id`,`vat_no`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_crm_accounts_company_type_name` (`company_id`,`type`,`name`);
ALTER TABLE `crm_accounts` ADD FULLTEXT KEY `ft_search` (`name`,`legal_name`,`notes`);

--
-- Indexes for table `crm_account_tags`
--
ALTER TABLE `crm_account_tags`
  ADD PRIMARY KEY (`account_id`,`tag_id`),
  ADD KEY `idx_tag_accounts` (`tag_id`,`account_id`);

--
-- Indexes for table `crm_addresses`
--
ALTER TABLE `crm_addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_account_type` (`company_id`,`account_id`,`type`),
  ADD KEY `idx_crm_addresses_account` (`company_id`,`account_id`),
  ADD KEY `fk_crm_addresses_account` (`account_id`);

--
-- Indexes for table `crm_compliance_docs`
--
ALTER TABLE `crm_compliance_docs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expiry` (`company_id`,`account_id`,`expiry_date`),
  ADD KEY `idx_status` (`company_id`,`status`),
  ADD KEY `idx_type` (`company_id`,`type_id`),
  ADD KEY `idx_compliance_expiry` (`company_id`,`account_id`,`status`,`expiry_date`),
  ADD KEY `idx_crm_compliance_status` (`company_id`,`account_id`,`status`,`expiry_date`),
  ADD KEY `fk_crm_compdocs_account` (`account_id`);

--
-- Indexes for table `crm_compliance_types`
--
ALTER TABLE `crm_compliance_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_comp_type` (`company_id`,`code`);

--
-- Indexes for table `crm_contacts`
--
ALTER TABLE `crm_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_account` (`company_id`,`account_id`),
  ADD KEY `idx_company_email` (`company_id`,`email`),
  ADD KEY `idx_is_primary` (`account_id`,`is_primary`),
  ADD KEY `idx_crm_contacts_account` (`company_id`,`account_id`);

--
-- Indexes for table `crm_import_history`
--
ALTER TABLE `crm_import_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_user` (`company_id`,`user_id`),
  ADD KEY `idx_status` (`company_id`,`status`);

--
-- Indexes for table `crm_industries`
--
ALTER TABLE `crm_industries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_name` (`name`),
  ADD UNIQUE KEY `uq_crm_industries_name` (`name`);

--
-- Indexes for table `crm_interactions`
--
ALTER TABLE `crm_interactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_account_time` (`company_id`,`account_id`,`created_at` DESC),
  ADD KEY `idx_company_type_time` (`company_id`,`type`,`created_at` DESC),
  ADD KEY `idx_next_action` (`company_id`,`next_action_at`),
  ADD KEY `idx_crm_interactions_account` (`company_id`,`account_id`,`created_at`),
  ADD KEY `fk_crm_interactions_account` (`account_id`);

--
-- Indexes for table `crm_merge_candidates`
--
ALTER TABLE `crm_merge_candidates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_left` (`company_id`,`left_id`),
  ADD KEY `idx_company_right` (`company_id`,`right_id`),
  ADD KEY `idx_resolved` (`company_id`,`resolved`);

--
-- Indexes for table `crm_opportunities`
--
ALTER TABLE `crm_opportunities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_crmop_company_stage` (`company_id`,`stage`),
  ADD KEY `idx_crmop_account` (`account_id`),
  ADD KEY `idx_crmop_owner` (`owner_id`),
  ADD KEY `idx_crmop_close_date` (`close_date`);

--
-- Indexes for table `crm_regions`
--
ALTER TABLE `crm_regions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_name` (`name`);

--
-- Indexes for table `crm_tags`
--
ALTER TABLE `crm_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tag` (`company_id`,`name`);

--
-- Indexes for table `depreciation_lines`
--
ALTER TABLE `depreciation_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_run` (`run_id`),
  ADD KEY `idx_asset` (`asset_id`);

--
-- Indexes for table `depreciation_runs`
--
ALTER TABLE `depreciation_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_run` (`company_id`,`run_date`),
  ADD KEY `idx_company_date` (`company_id`,`run_date`);

--
-- Indexes for table `doc_sequences`
--
ALTER TABLE `doc_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_type_period` (`company_id`,`doc_type`,`period_key`),
  ADD KEY `idx_company_type` (`company_id`,`doc_type`);

--
-- Indexes for table `emails`
--
ALTER TABLE `emails`
  ADD PRIMARY KEY (`email_id`),
  ADD UNIQUE KEY `uq_emails_message_id` (`message_id`),
  ADD UNIQUE KEY `uq_account_folder_uid` (`account_id`,`folder`(191),`imap_uid`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_account_folder` (`account_id`,`folder`),
  ADD KEY `idx_thread` (`thread_id`),
  ADD KEY `idx_message_id` (`message_id`(191)),
  ADD KEY `idx_sent_at` (`sent_at`),
  ADD KEY `idx_emails_account` (`company_id`,`account_id`,`folder`,`sent_at`),
  ADD KEY `idx_emails_company_folder_read` (`company_id`,`folder`,`is_read`,`direction`);
ALTER TABLE `emails` ADD FULLTEXT KEY `idx_search` (`subject`,`body_text`);

--
-- Indexes for table `email_accounts`
--
ALTER TABLE `email_accounts`
  ADD PRIMARY KEY (`account_id`),
  ADD KEY `idx_company_user` (`company_id`,`user_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `email_attachments`
--
ALTER TABLE `email_attachments`
  ADD PRIMARY KEY (`attachment_id`),
  ADD KEY `idx_email` (`email_id`);

--
-- Indexes for table `email_links`
--
ALTER TABLE `email_links`
  ADD PRIMARY KEY (`link_id`),
  ADD KEY `idx_email` (`email_id`),
  ADD KEY `idx_linked` (`linked_type`,`linked_id`),
  ADD KEY `idx_email_links` (`email_id`,`linked_type`,`linked_id`);

--
-- Indexes for table `email_rules`
--
ALTER TABLE `email_rules`
  ADD PRIMARY KEY (`rule_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_priority` (`priority`);

--
-- Indexes for table `email_send_log`
--
ALTER TABLE `email_send_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_email` (`email_id`);

--
-- Indexes for table `email_signatures`
--
ALTER TABLE `email_signatures`
  ADD PRIMARY KEY (`signature_id`),
  ADD KEY `idx_company_user` (`company_id`,`user_id`);

--
-- Indexes for table `email_sync_log`
--
ALTER TABLE `email_sync_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_account` (`account_id`);

--
-- Indexes for table `email_tags`
--
ALTER TABLE `email_tags`
  ADD PRIMARY KEY (`tag_id`),
  ADD UNIQUE KEY `uq_company_name` (`company_id`,`name`);

--
-- Indexes for table `email_tag_map`
--
ALTER TABLE `email_tag_map`
  ADD PRIMARY KEY (`email_id`,`tag_id`),
  ADD KEY `idx_tag` (`tag_id`),
  ADD KEY `idx_email_tag_map` (`email_id`,`tag_id`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`template_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `email_threads`
--
ALTER TABLE `email_threads`
  ADD PRIMARY KEY (`thread_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_last_message` (`last_message_at`),
  ADD KEY `idx_sla` (`sla_due_at`),
  ADD KEY `idx_threads_company_status_sla` (`company_id`,`status`,`sla_due_at`),
  ADD KEY `idx_threads_lastmsg` (`company_id`,`last_message_at`);
ALTER TABLE `email_threads` ADD FULLTEXT KEY `idx_subject` (`subject`,`subject_norm`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_empno` (`company_id`,`employee_no`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_tax` (`company_id`,`tax_number`),
  ADD KEY `idx_hire` (`company_id`,`hire_date`);

--
-- Indexes for table `employee_payitems`
--
ALTER TABLE `employee_payitems`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp` (`company_id`,`employee_id`),
  ADD KEY `idx_active` (`company_id`,`employee_id`,`active`);

--
-- Indexes for table `fa_depreciation_lines`
--
ALTER TABLE `fa_depreciation_lines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fa_depreciation_runs`
--
ALTER TABLE `fa_depreciation_runs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fa_disposals`
--
ALTER TABLE `fa_disposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fa_disposals_company` (`company_id`),
  ADD KEY `idx_fa_disposals_asset` (`asset_id`),
  ADD KEY `idx_fa_disposals_asset_id` (`asset_id`);

--
-- Indexes for table `fd_audit`
--
ALTER TABLE `fd_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_node` (`node_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_audit_user_action_created` (`user_id`,`action`,`created_at`),
  ADD KEY `idx_audit_company_created` (`company_id`,`created_at`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `fd_blobs`
--
ALTER TABLE `fd_blobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sha256` (`sha256`),
  ADD UNIQUE KEY `uq_blobs_sha256` (`sha256`),
  ADD KEY `idx_sha256` (`sha256`),
  ADD KEY `idx_blob_sha_size` (`sha256`,`size_bytes`);

--
-- Indexes for table `fd_drives`
--
ALTER TABLE `fd_drives`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_owner` (`owner_user_id`),
  ADD KEY `idx_subtype` (`subtype`);

--
-- Indexes for table `fd_nodes`
--
ALTER TABLE `fd_nodes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_parent_name` (`drive_id`,`parent_id`,`name`,`ext`),
  ADD KEY `idx_drive_parent` (`drive_id`,`parent_id`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_deleted` (`deleted_at`),
  ADD KEY `fk_nodes_parent` (`parent_id`),
  ADD KEY `fk_nodes_blob` (`blob_id`),
  ADD KEY `fk_nodes_creator` (`created_by`),
  ADD KEY `fk_nodes_updater` (`updated_by`),
  ADD KEY `idx_drive_parent_deleted` (`drive_id`,`parent_id`,`deleted_at`),
  ADD KEY `idx_nodes_parent` (`drive_id`,`parent_id`,`deleted_at`),
  ADD KEY `idx_nodes_drive_deleted` (`drive_id`,`deleted_at`),
  ADD KEY `idx_nodes_name` (`name`),
  ADD KEY `idx_nodes_ext` (`ext`),
  ADD KEY `idx_disk_path` (`disk_path`(255));
ALTER TABLE `fd_nodes` ADD FULLTEXT KEY `idx_fulltext_name` (`name`);

--
-- Indexes for table `fd_node_versions`
--
ALTER TABLE `fd_node_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_node_ver` (`node_id`,`version_no`);

--
-- Indexes for table `fd_permissions`
--
ALTER TABLE `fd_permissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_node` (`node_id`),
  ADD KEY `idx_subject` (`subject_type`,`subject_id`),
  ADD KEY `idx_perms_node` (`node_id`),
  ADD KEY `idx_perms_subject` (`subject_type`,`subject_id`);

--
-- Indexes for table `fd_shares`
--
ALTER TABLE `fd_shares`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD UNIQUE KEY `uq_share_token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_node` (`node_id`),
  ADD KEY `fk_share_creator` (`created_by`),
  ADD KEY `idx_share_token_expires` (`token`,`expires_at`,`revoked_at`);

--
-- Indexes for table `fd_sync_state`
--
ALTER TABLE `fd_sync_state`
  ADD PRIMARY KEY (`table_name`);

--
-- Indexes for table `gl_accounts`
--
ALTER TABLE `gl_accounts`
  ADD PRIMARY KEY (`account_id`),
  ADD UNIQUE KEY `uq_account_code` (`company_id`,`account_code`),
  ADD KEY `idx_company_type` (`company_id`,`account_type`),
  ADD KEY `idx_parent` (`parent_id`),
  ADD KEY `idx_gl_accounts_company_parent` (`company_id`,`parent_id`);

--
-- Indexes for table `gl_bank_accounts`
--
ALTER TABLE `gl_bank_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_gl_bank_accounts_company_active` (`company_id`,`is_active`);

--
-- Indexes for table `gl_bank_rules`
--
ALTER TABLE `gl_bank_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_active` (`company_id`,`is_active`,`priority`);

--
-- Indexes for table `gl_bank_transactions`
--
ALTER TABLE `gl_bank_transactions`
  ADD PRIMARY KEY (`bank_tx_id`),
  ADD KEY `idx_company_account` (`company_id`,`bank_account_id`),
  ADD KEY `idx_date` (`company_id`,`tx_date`),
  ADD KEY `idx_matched` (`company_id`,`matched`),
  ADD KEY `idx_bank_txn_account_date` (`bank_account_id`,`tx_date`),
  ADD KEY `idx_bank_txn_matched` (`matched`),
  ADD KEY `idx_gl_bank_tx_account` (`bank_account_id`);

--
-- Indexes for table `gl_budgets`
--
ALTER TABLE `gl_budgets`
  ADD PRIMARY KEY (`budget_id`),
  ADD UNIQUE KEY `uq_budget` (`company_id`,`gl_account_id`,`project_id`,`period_year`,`period_month`),
  ADD KEY `idx_company_account` (`company_id`,`gl_account_id`);

--
-- Indexes for table `gl_fixed_assets`
--
ALTER TABLE `gl_fixed_assets`
  ADD PRIMARY KEY (`asset_id`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_gl_fixed_assets_asset_id` (`asset_id`);

--
-- Indexes for table `gl_period_locks`
--
ALTER TABLE `gl_period_locks`
  ADD PRIMARY KEY (`lock_id`),
  ADD KEY `idx_company_date` (`company_id`,`lock_date`),
  ADD KEY `idx_active_company` (`company_id`,`is_active`);

--
-- Indexes for table `gl_report_map`
--
ALTER TABLE `gl_report_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_report_group_account` (`company_id`,`report`,`group_key`,`account_id`),
  ADD KEY `idx_company_report` (`company_id`,`report`,`group_key`);

--
-- Indexes for table `gl_tax_codes`
--
ALTER TABLE `gl_tax_codes`
  ADD PRIMARY KEY (`tax_code_id`),
  ADD UNIQUE KEY `uq_tax_code` (`company_id`,`code`),
  ADD KEY `idx_company_type` (`company_id`,`type`);

--
-- Indexes for table `gl_vat_periods`
--
ALTER TABLE `gl_vat_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_period` (`company_id`,`period_start`,`period_end`),
  ADD KEY `idx_status` (`company_id`,`status`);

--
-- Indexes for table `goods_received_notes`
--
ALTER TABLE `goods_received_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_po` (`company_id`,`po_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `grn_lines`
--
ALTER TABLE `grn_lines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_movements_item_date` (`item_id`,`movement_date`);

--
-- Indexes for table `invites`
--
ALTER TABLE `invites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `used_by` (`used_by`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_company_email` (`company_id`,`email`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_num` (`company_id`,`invoice_number`),
  ADD UNIQUE KEY `uq_invoice_supplier_number` (`company_id`,`customer_id`,`invoice_number`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_company_customer` (`company_id`,`customer_id`),
  ADD KEY `idx_due_date` (`company_id`,`due_date`),
  ADD KEY `idx_balance` (`company_id`,`balance_due`),
  ADD KEY `idx_company_status_date` (`company_id`,`status`,`issue_date`),
  ADD KEY `idx_journal` (`journal_id`),
  ADD KEY `idx_invoices_customer` (`company_id`,`customer_id`),
  ADD KEY `idx_invoices_company_status` (`company_id`,`status`);

--
-- Indexes for table `invoice_lines`
--
ALTER TABLE `invoice_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_sort` (`invoice_id`,`sort_order`),
  ADD KEY `idx_project_board` (`project_board_id`),
  ADD KEY `idx_gl_account` (`gl_account_id`),
  ADD KEY `idx_invoice_lines_inventory_item` (`inventory_item_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_date` (`company_id`,`entry_date`),
  ADD KEY `idx_source` (`source_type`,`source_id`),
  ADD KEY `idx_module` (`company_id`,`module`),
  ADD KEY `idx_ref` (`ref_type`,`ref_id`),
  ADD KEY `idx_journal_entries_company_date` (`company_id`,`entry_date`),
  ADD KEY `idx_journal_entries_ref` (`company_id`,`reference`),
  ADD KEY `idx_reversed_by` (`reversed_by_journal_id`),
  ADD KEY `idx_reverses` (`reverses_journal_id`),
  ADD KEY `idx_status_company` (`company_id`,`status`,`posted_at`);

--
-- Indexes for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_journal` (`journal_id`),
  ADD KEY `idx_account` (`account_code`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_tax` (`tax_code_id`),
  ADD KEY `idx_journal_lines_account_code` (`account_code`),
  ADD KEY `idx_jl_employee` (`employee_id`),
  ADD KEY `idx_journal_lines_journal` (`journal_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `payitems`
--
ALTER TABLE `payitems`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_code` (`company_id`,`code`),
  ADD KEY `idx_company_type` (`company_id`,`type`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_date` (`company_id`,`payment_date`),
  ADD KEY `idx_journal` (`journal_id`);

--
-- Indexes for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment` (`payment_id`),
  ADD KEY `idx_invoice` (`invoice_id`);

--
-- Indexes for table `payroll_settings`
--
ALTER TABLE `payroll_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_id` (`company_id`);

--
-- Indexes for table `pay_runs`
--
ALTER TABLE `pay_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_run_number` (`company_id`,`run_number`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_company_paydate` (`company_id`,`pay_date`),
  ADD KEY `idx_period` (`company_id`,`period_start`,`period_end`);

--
-- Indexes for table `pay_run_employees`
--
ALTER TABLE `pay_run_employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_run_emp` (`run_id`,`employee_id`),
  ADD KEY `idx_company_run` (`company_id`,`run_id`);

--
-- Indexes for table `pay_run_lines`
--
ALTER TABLE `pay_run_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_run_emp` (`company_id`,`run_id`,`employee_id`),
  ADD KEY `idx_project` (`company_id`,`project_id`);

--
-- Indexes for table `pay_timesheets`
--
ALTER TABLE `pay_timesheets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_run_emp_date` (`company_id`,`run_id`,`employee_id`,`ts_date`),
  ADD KEY `idx_project` (`company_id`,`project_id`);

--
-- Indexes for table `period_locks`
--
ALTER TABLE `period_locks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_period` (`company_id`,`period_start`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`project_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_client` (`client_id`),
  ADD KEY `idx_manager` (`project_manager_id`),
  ADD KEY `idx_company_archived` (`company_id`,`archived`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_projects_company` (`company_id`);

--
-- Indexes for table `project_boards`
--
ALTER TABLE `project_boards`
  ADD PRIMARY KEY (`board_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_company_archived` (`company_id`,`archived`),
  ADD KEY `idx_project_order` (`project_id`,`sort_order`),
  ADD KEY `idx_company_project` (`company_id`,`project_id`),
  ADD KEY `idx_project_boards_company` (`company_id`);

--
-- Indexes for table `project_members`
--
ALTER TABLE `project_members`
  ADD PRIMARY KEY (`member_id`),
  ADD UNIQUE KEY `uniq_project_user` (`project_id`,`user_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_project_members_project` (`project_id`),
  ADD KEY `idx_project_members_user` (`user_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_supplier` (`company_id`,`supplier_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `purchase_order_lines`
--
ALTER TABLE `purchase_order_lines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qi_email_log`
--
ALTER TABLE `qi_email_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_entity` (`company_id`,`entity_type`,`entity_id`);

--
-- Indexes for table `qi_email_templates`
--
ALTER TABLE `qi_email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_company_template` (`company_id`,`template_type`);

--
-- Indexes for table `qi_sequences`
--
ALTER TABLE `qi_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_type_year` (`company_id`,`type`,`year`),
  ADD UNIQUE KEY `uq_qi_sequences` (`company_id`,`type`,`year`);

--
-- Indexes for table `qi_settings`
--
ALTER TABLE `qi_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_id` (`company_id`);

--
-- Indexes for table `quotes`
--
ALTER TABLE `quotes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_quote_num` (`company_id`,`quote_number`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_company_customer` (`company_id`,`customer_id`),
  ADD KEY `idx_issue_date` (`company_id`,`issue_date`),
  ADD KEY `idx_quotes_customer` (`company_id`,`customer_id`);

--
-- Indexes for table `quote_lines`
--
ALTER TABLE `quote_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_quote` (`quote_id`),
  ADD KEY `idx_sort` (`quote_id`,`sort_order`);

--
-- Indexes for table `receipt_file`
--
ALTER TABLE `receipt_file`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `idx_company_time` (`company_id`,`uploaded_at`),
  ADD KEY `idx_company_status` (`company_id`,`ocr_status`),
  ADD KEY `idx_invoice` (`invoice_id`);

--
-- Indexes for table `receipt_match`
--
ALTER TABLE `receipt_match`
  ADD PRIMARY KEY (`match_id`),
  ADD UNIQUE KEY `uq_invoice_target` (`invoice_id`,`target_type`,`target_id`),
  ADD KEY `idx_company_target` (`company_id`,`target_type`);

--
-- Indexes for table `receipt_ocr`
--
ALTER TABLE `receipt_ocr`
  ADD PRIMARY KEY (`ocr_id`),
  ADD KEY `idx_file` (`file_id`);

--
-- Indexes for table `receipt_policy_log`
--
ALTER TABLE `receipt_policy_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_invoice` (`company_id`,`invoice_id`),
  ADD KEY `idx_company_file` (`company_id`,`file_id`),
  ADD KEY `idx_severity` (`severity`);

--
-- Indexes for table `receipt_settings`
--
ALTER TABLE `receipt_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `company_id` (`company_id`);

--
-- Indexes for table `recurring_invoices`
--
ALTER TABLE `recurring_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_next` (`company_id`,`next_run_date`,`active`);

--
-- Indexes for table `recurring_invoice_lines`
--
ALTER TABLE `recurring_invoice_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recurring` (`recurring_invoice_id`);

--
-- Indexes for table `shopping_actions`
--
ALTER TABLE `shopping_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_list` (`list_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `shopping_candidates`
--
ALTER TABLE `shopping_candidates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_item` (`item_id`),
  ADD KEY `idx_score` (`item_id`,`score` DESC),
  ADD KEY `list_id` (`list_id`);

--
-- Indexes for table `shopping_items`
--
ALTER TABLE `shopping_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_list` (`list_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_order` (`list_id`,`order_index`);

--
-- Indexes for table `shopping_lists`
--
ALTER TABLE `shopping_lists`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_owner` (`company_id`,`owner_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `shopping_preferences`
--
ALTER TABLE `shopping_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user` (`company_id`,`user_id`);

--
-- Indexes for table `shopping_price_history`
--
ALTER TABLE `shopping_price_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fingerprint` (`company_id`,`item_fingerprint`),
  ADD KEY `idx_store` (`store_id`);

--
-- Indexes for table `shopping_sessions`
--
ALTER TABLE `shopping_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_list_active` (`list_id`,`ended_at`);

--
-- Indexes for table `shopping_share_tokens`
--
ALTER TABLE `shopping_share_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `list_id` (`list_id`);

--
-- Indexes for table `shopping_stores`
--
ALTER TABLE `shopping_stores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_preferred` (`company_id`,`preferred`),
  ADD KEY `idx_crm_link` (`crm_account_id`);

--
-- Indexes for table `shopping_store_catalog`
--
ALTER TABLE `shopping_store_catalog`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_store` (`store_id`),
  ADD KEY `idx_name` (`name`);

--
-- Indexes for table `shopping_templates`
--
ALTER TABLE `shopping_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_yoco_subscription` (`yoco_subscription_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_company_status` (`company_id`,`status`);

--
-- Indexes for table `tax_tables_za`
--
ALTER TABLE `tax_tables_za`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_effective` (`company_id`,`effective_from`),
  ADD KEY `idx_effective` (`effective_from`,`effective_to`);

--
-- Indexes for table `timesheets`
--
ALTER TABLE `timesheets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_timesheets_item` (`item_id`,`company_id`),
  ADD KEY `idx_timesheets_project` (`project_id`,`company_id`);

--
-- Indexes for table `timesheet_entries`
--
ALTER TABLE `timesheet_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_timesheet_entries_company` (`company_id`),
  ADD KEY `idx_timesheet_entries_employee` (`employee_id`),
  ADD KEY `idx_timesheet_entries_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_session` (`session_token`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_company_seat` (`company_id`,`is_seat`,`status`),
  ADD KEY `idx_session_token` (`session_token`);

--
-- Indexes for table `user_companies`
--
ALTER TABLE `user_companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_company` (`user_id`,`company_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `user_mail_prefs`
--
ALTER TABLE `user_mail_prefs`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_company` (`company_id`);

--
-- Indexes for table `vat_entries`
--
ALTER TABLE `vat_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_date` (`company_id`,`txn_date`),
  ADD KEY `idx_return` (`vat_return_id`),
  ADD KEY `idx_company_taxcode` (`company_id`,`tax_code`);

--
-- Indexes for table `vat_returns`
--
ALTER TABLE `vat_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vat_period` (`company_id`,`period_start`),
  ADD KEY `idx_company_period` (`company_id`,`period_start`),
  ADD KEY `idx_journal` (`journal_id`);

--
-- Indexes for table `vendor_credits`
--
ALTER TABLE `vendor_credits`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `vendor_credit_allocations`
--
ALTER TABLE `vendor_credit_allocations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `vendor_credit_lines`
--
ALTER TABLE `vendor_credit_lines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `webhooks`
--
ALTER TABLE `webhooks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `idx_company_active` (`company_id`,`active`);

--
-- Indexes for table `yoco_payment_events`
--
ALTER TABLE `yoco_payment_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_event_type` (`event_type`),
  ADD KEY `idx_created` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_actions`
--
ALTER TABLE `ai_actions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `ai_candidates`
--
ALTER TABLE `ai_candidates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `ai_hints`
--
ALTER TABLE `ai_hints`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_queries`
--
ALTER TABLE `ai_queries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `ai_results`
--
ALTER TABLE `ai_results`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_settings`
--
ALTER TABLE `ai_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `api_keys`
--
ALTER TABLE `api_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ap_bills`
--
ALTER TABLE `ap_bills`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ap_bill_lines`
--
ALTER TABLE `ap_bill_lines`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ap_match_links`
--
ALTER TABLE `ap_match_links`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ap_payments`
--
ALTER TABLE `ap_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ap_payment_allocations`
--
ALTER TABLE `ap_payment_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=298;

--
-- AUTO_INCREMENT for table `auth_sessions`
--
ALTER TABLE `auth_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_file_exports`
--
ALTER TABLE `bank_file_exports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_import_batches`
--
ALTER TABLE `bank_import_batches`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_reconciliations`
--
ALTER TABLE `bank_reconciliations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_reconciliation_lines`
--
ALTER TABLE `bank_reconciliation_lines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_activity`
--
ALTER TABLE `board_activity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_audit_log`
--
ALTER TABLE `board_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=330;

--
-- AUTO_INCREMENT for table `board_automations`
--
ALTER TABLE `board_automations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_automation_logs`
--
ALTER TABLE `board_automation_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_columns`
--
ALTER TABLE `board_columns`
  MODIFY `column_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=527;

--
-- AUTO_INCREMENT for table `board_column_settings`
--
ALTER TABLE `board_column_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_dependencies`
--
ALTER TABLE `board_dependencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_groups`
--
ALTER TABLE `board_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=273;

--
-- AUTO_INCREMENT for table `board_guests`
--
ALTER TABLE `board_guests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `board_guest_activity`
--
ALTER TABLE `board_guest_activity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `board_items`
--
ALTER TABLE `board_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1359;

--
-- AUTO_INCREMENT for table `board_item_attachments`
--
ALTER TABLE `board_item_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_item_comments`
--
ALTER TABLE `board_item_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_item_values`
--
ALTER TABLE `board_item_values`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9225;

--
-- AUTO_INCREMENT for table `board_members`
--
ALTER TABLE `board_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `board_saved_views`
--
ALTER TABLE `board_saved_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_subitems`
--
ALTER TABLE `board_subitems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `board_watchers`
--
ALTER TABLE `board_watchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budget_lines`
--
ALTER TABLE `budget_lines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendars`
--
ALTER TABLE `calendars`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `calendar_events`
--
ALTER TABLE `calendar_events`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `calendar_event_attachments`
--
ALTER TABLE `calendar_event_attachments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_event_links`
--
ALTER TABLE `calendar_event_links`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_event_participants`
--
ALTER TABLE `calendar_event_participants`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_event_reminders`
--
ALTER TABLE `calendar_event_reminders`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_event_templates`
--
ALTER TABLE `calendar_event_templates`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_notes`
--
ALTER TABLE `calendar_notes`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_settings`
--
ALTER TABLE `calendar_settings`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_subscriptions`
--
ALTER TABLE `calendar_subscriptions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_tasks`
--
ALTER TABLE `calendar_tasks`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_working_hours`
--
ALTER TABLE `calendar_working_hours`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `cost_items`
--
ALTER TABLE `cost_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `credit_notes`
--
ALTER TABLE `credit_notes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `credit_note_allocations`
--
ALTER TABLE `credit_note_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `credit_note_lines`
--
ALTER TABLE `credit_note_lines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_accounts`
--
ALTER TABLE `crm_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `crm_addresses`
--
ALTER TABLE `crm_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `crm_compliance_docs`
--
ALTER TABLE `crm_compliance_docs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `crm_compliance_types`
--
ALTER TABLE `crm_compliance_types`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `crm_contacts`
--
ALTER TABLE `crm_contacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `crm_import_history`
--
ALTER TABLE `crm_import_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_industries`
--
ALTER TABLE `crm_industries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- AUTO_INCREMENT for table `crm_interactions`
--
ALTER TABLE `crm_interactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `crm_merge_candidates`
--
ALTER TABLE `crm_merge_candidates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_opportunities`
--
ALTER TABLE `crm_opportunities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_regions`
--
ALTER TABLE `crm_regions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `crm_tags`
--
ALTER TABLE `crm_tags`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `depreciation_lines`
--
ALTER TABLE `depreciation_lines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `depreciation_runs`
--
ALTER TABLE `depreciation_runs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `doc_sequences`
--
ALTER TABLE `doc_sequences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `emails`
--
ALTER TABLE `emails`
  MODIFY `email_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_accounts`
--
ALTER TABLE `email_accounts`
  MODIFY `account_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `email_attachments`
--
ALTER TABLE `email_attachments`
  MODIFY `attachment_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_links`
--
ALTER TABLE `email_links`
  MODIFY `link_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `email_rules`
--
ALTER TABLE `email_rules`
  MODIFY `rule_id` int(10) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `email_send_log`
--
ALTER TABLE `email_send_log`
  MODIFY `log_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_signatures`
--
ALTER TABLE `email_signatures`
  MODIFY `signature_id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_sync_log`
--
ALTER TABLE `email_sync_log`
  MODIFY `log_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_tags`
--
ALTER TABLE `email_tags`
  MODIFY `tag_id` int(10) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `template_id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_threads`
--
ALTER TABLE `email_threads`
  MODIFY `thread_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `employee_payitems`
--
ALTER TABLE `employee_payitems`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fa_depreciation_lines`
--
ALTER TABLE `fa_depreciation_lines`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fa_depreciation_runs`
--
ALTER TABLE `fa_depreciation_runs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fa_disposals`
--
ALTER TABLE `fa_disposals`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_audit`
--
ALTER TABLE `fd_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_blobs`
--
ALTER TABLE `fd_blobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `fd_drives`
--
ALTER TABLE `fd_drives`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `fd_nodes`
--
ALTER TABLE `fd_nodes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `fd_node_versions`
--
ALTER TABLE `fd_node_versions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_permissions`
--
ALTER TABLE `fd_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `fd_shares`
--
ALTER TABLE `fd_shares`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gl_accounts`
--
ALTER TABLE `gl_accounts`
  MODIFY `account_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `gl_bank_accounts`
--
ALTER TABLE `gl_bank_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gl_bank_rules`
--
ALTER TABLE `gl_bank_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gl_bank_transactions`
--
ALTER TABLE `gl_bank_transactions`
  MODIFY `bank_tx_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gl_budgets`
--
ALTER TABLE `gl_budgets`
  MODIFY `budget_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gl_period_locks`
--
ALTER TABLE `gl_period_locks`
  MODIFY `lock_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gl_report_map`
--
ALTER TABLE `gl_report_map`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `gl_tax_codes`
--
ALTER TABLE `gl_tax_codes`
  MODIFY `tax_code_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `gl_vat_periods`
--
ALTER TABLE `gl_vat_periods`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `goods_received_notes`
--
ALTER TABLE `goods_received_notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grn_lines`
--
ALTER TABLE `grn_lines`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_items`
--
ALTER TABLE `inventory_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invites`
--
ALTER TABLE `invites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `invoice_lines`
--
ALTER TABLE `invoice_lines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `journal_lines`
--
ALTER TABLE `journal_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payitems`
--
ALTER TABLE `payitems`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_settings`
--
ALTER TABLE `payroll_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pay_runs`
--
ALTER TABLE `pay_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pay_run_employees`
--
ALTER TABLE `pay_run_employees`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pay_run_lines`
--
ALTER TABLE `pay_run_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pay_timesheets`
--
ALTER TABLE `pay_timesheets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `period_locks`
--
ALTER TABLE `period_locks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `project_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `project_boards`
--
ALTER TABLE `project_boards`
  MODIFY `board_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `project_members`
--
ALTER TABLE `project_members`
  MODIFY `member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_order_lines`
--
ALTER TABLE `purchase_order_lines`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qi_email_log`
--
ALTER TABLE `qi_email_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `qi_email_templates`
--
ALTER TABLE `qi_email_templates`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `qi_sequences`
--
ALTER TABLE `qi_sequences`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `qi_settings`
--
ALTER TABLE `qi_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotes`
--
ALTER TABLE `quotes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `quote_lines`
--
ALTER TABLE `quote_lines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `receipt_file`
--
ALTER TABLE `receipt_file`
  MODIFY `file_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `receipt_match`
--
ALTER TABLE `receipt_match`
  MODIFY `match_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipt_ocr`
--
ALTER TABLE `receipt_ocr`
  MODIFY `ocr_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `receipt_policy_log`
--
ALTER TABLE `receipt_policy_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipt_settings`
--
ALTER TABLE `receipt_settings`
  MODIFY `setting_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `recurring_invoices`
--
ALTER TABLE `recurring_invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recurring_invoice_lines`
--
ALTER TABLE `recurring_invoice_lines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shopping_actions`
--
ALTER TABLE `shopping_actions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `shopping_candidates`
--
ALTER TABLE `shopping_candidates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `shopping_items`
--
ALTER TABLE `shopping_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `shopping_lists`
--
ALTER TABLE `shopping_lists`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `shopping_preferences`
--
ALTER TABLE `shopping_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `shopping_price_history`
--
ALTER TABLE `shopping_price_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shopping_sessions`
--
ALTER TABLE `shopping_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `shopping_share_tokens`
--
ALTER TABLE `shopping_share_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shopping_stores`
--
ALTER TABLE `shopping_stores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shopping_store_catalog`
--
ALTER TABLE `shopping_store_catalog`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shopping_templates`
--
ALTER TABLE `shopping_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tax_tables_za`
--
ALTER TABLE `tax_tables_za`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `timesheets`
--
ALTER TABLE `timesheets`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timesheet_entries`
--
ALTER TABLE `timesheet_entries`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_companies`
--
ALTER TABLE `user_companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vat_entries`
--
ALTER TABLE `vat_entries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vat_returns`
--
ALTER TABLE `vat_returns`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_credits`
--
ALTER TABLE `vendor_credits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_credit_allocations`
--
ALTER TABLE `vendor_credit_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_credit_lines`
--
ALTER TABLE `vendor_credit_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `webhooks`
--
ALTER TABLE `webhooks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `yoco_payment_events`
--
ALTER TABLE `yoco_payment_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ap_bill_lines`
--
ALTER TABLE `ap_bill_lines`
  ADD CONSTRAINT `fk_ap_bill_lines_bill` FOREIGN KEY (`bill_id`) REFERENCES `ap_bills` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ap_payment_allocations`
--
ALTER TABLE `ap_payment_allocations`
  ADD CONSTRAINT `fk_ap_alloc_bill` FOREIGN KEY (`bill_id`) REFERENCES `ap_bills` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ap_alloc_payment` FOREIGN KEY (`ap_payment_id`) REFERENCES `ap_payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bank_reconciliation_lines`
--
ALTER TABLE `bank_reconciliation_lines`
  ADD CONSTRAINT `fk_bank_reco_lines_reco` FOREIGN KEY (`reconciliation_id`) REFERENCES `bank_reconciliations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `board_audit_log`
--
ALTER TABLE `board_audit_log`
  ADD CONSTRAINT `board_audit_log_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `board_audit_log_ibfk_2` FOREIGN KEY (`board_id`) REFERENCES `project_boards` (`board_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `board_audit_log_ibfk_3` FOREIGN KEY (`item_id`) REFERENCES `board_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `board_audit_log_ibfk_4` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `board_automations`
--
ALTER TABLE `board_automations`
  ADD CONSTRAINT `board_automations_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `project_boards` (`board_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `board_automations_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `board_dependencies`
--
ALTER TABLE `board_dependencies`
  ADD CONSTRAINT `board_dependencies_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `project_boards` (`board_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `board_dependencies_ibfk_2` FOREIGN KEY (`predecessor_item`) REFERENCES `board_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `board_dependencies_ibfk_3` FOREIGN KEY (`successor_item`) REFERENCES `board_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `board_guests`
--
ALTER TABLE `board_guests`
  ADD CONSTRAINT `board_guests_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `project_boards` (`board_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `board_guests_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `board_guests_ibfk_3` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `board_guest_activity`
--
ALTER TABLE `board_guest_activity`
  ADD CONSTRAINT `board_guest_activity_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `board_guests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `board_saved_views`
--
ALTER TABLE `board_saved_views`
  ADD CONSTRAINT `board_saved_views_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `project_boards` (`board_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `board_saved_views_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `board_subitems`
--
ALTER TABLE `board_subitems`
  ADD CONSTRAINT `fk_subitem_board` FOREIGN KEY (`board_id`) REFERENCES `project_boards` (`board_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subitem_parent` FOREIGN KEY (`parent_item_id`) REFERENCES `board_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `budget_lines`
--
ALTER TABLE `budget_lines`
  ADD CONSTRAINT `fk_budget_lines_budget` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendars`
--
ALTER TABLE `calendars`
  ADD CONSTRAINT `calendars_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD CONSTRAINT `calendar_events_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `calendar_events_ibfk_2` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_event_attachments`
--
ALTER TABLE `calendar_event_attachments`
  ADD CONSTRAINT `calendar_event_attachments_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_event_links`
--
ALTER TABLE `calendar_event_links`
  ADD CONSTRAINT `calendar_event_links_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_event_participants`
--
ALTER TABLE `calendar_event_participants`
  ADD CONSTRAINT `calendar_event_participants_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_event_reminders`
--
ALTER TABLE `calendar_event_reminders`
  ADD CONSTRAINT `calendar_event_reminders_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_event_templates`
--
ALTER TABLE `calendar_event_templates`
  ADD CONSTRAINT `calendar_event_templates_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_notes`
--
ALTER TABLE `calendar_notes`
  ADD CONSTRAINT `calendar_notes_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `calendar_notes_ibfk_2` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_settings`
--
ALTER TABLE `calendar_settings`
  ADD CONSTRAINT `calendar_settings_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_subscriptions`
--
ALTER TABLE `calendar_subscriptions`
  ADD CONSTRAINT `calendar_subscriptions_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_tasks`
--
ALTER TABLE `calendar_tasks`
  ADD CONSTRAINT `calendar_tasks_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `calendar_tasks_ibfk_2` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_working_hours`
--
ALTER TABLE `calendar_working_hours`
  ADD CONSTRAINT `calendar_working_hours_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `companies`
--
ALTER TABLE `companies`
  ADD CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`);

--
-- Constraints for table `crm_addresses`
--
ALTER TABLE `crm_addresses`
  ADD CONSTRAINT `fk_address_account` FOREIGN KEY (`account_id`) REFERENCES `crm_accounts` (`id`),
  ADD CONSTRAINT `fk_crm_addresses_account` FOREIGN KEY (`account_id`) REFERENCES `crm_accounts` (`id`);

--
-- Constraints for table `crm_compliance_docs`
--
ALTER TABLE `crm_compliance_docs`
  ADD CONSTRAINT `fk_comp_doc_account` FOREIGN KEY (`account_id`) REFERENCES `crm_accounts` (`id`),
  ADD CONSTRAINT `fk_crm_compdocs_account` FOREIGN KEY (`account_id`) REFERENCES `crm_accounts` (`id`);

--
-- Constraints for table `crm_contacts`
--
ALTER TABLE `crm_contacts`
  ADD CONSTRAINT `fk_contact_account` FOREIGN KEY (`account_id`) REFERENCES `crm_accounts` (`id`),
  ADD CONSTRAINT `fk_crm_contacts_account` FOREIGN KEY (`account_id`) REFERENCES `crm_accounts` (`id`);

--
-- Constraints for table `crm_interactions`
--
ALTER TABLE `crm_interactions`
  ADD CONSTRAINT `fk_crm_interactions_account` FOREIGN KEY (`account_id`) REFERENCES `crm_accounts` (`id`);

--
-- Constraints for table `depreciation_lines`
--
ALTER TABLE `depreciation_lines`
  ADD CONSTRAINT `fk_depr_lines_run` FOREIGN KEY (`run_id`) REFERENCES `depreciation_runs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fa_disposals`
--
ALTER TABLE `fa_disposals`
  ADD CONSTRAINT `fk_fa_disposals_asset` FOREIGN KEY (`asset_id`) REFERENCES `gl_fixed_assets` (`asset_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `fd_audit`
--
ALTER TABLE `fd_audit`
  ADD CONSTRAINT `fk_audit_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fd_drives`
--
ALTER TABLE `fd_drives`
  ADD CONSTRAINT `fk_drives_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_drives_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fd_nodes`
--
ALTER TABLE `fd_nodes`
  ADD CONSTRAINT `fk_nodes_blob` FOREIGN KEY (`blob_id`) REFERENCES `fd_blobs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_nodes_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_nodes_drive` FOREIGN KEY (`drive_id`) REFERENCES `fd_drives` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_nodes_parent` FOREIGN KEY (`parent_id`) REFERENCES `fd_nodes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_nodes_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fd_permissions`
--
ALTER TABLE `fd_permissions`
  ADD CONSTRAINT `fk_perm_node` FOREIGN KEY (`node_id`) REFERENCES `fd_nodes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fd_shares`
--
ALTER TABLE `fd_shares`
  ADD CONSTRAINT `fk_share_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_share_node` FOREIGN KEY (`node_id`) REFERENCES `fd_nodes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `gl_bank_transactions`
--
ALTER TABLE `gl_bank_transactions`
  ADD CONSTRAINT `fk_gl_bank_tx_account` FOREIGN KEY (`bank_account_id`) REFERENCES `gl_bank_accounts` (`id`);

--
-- Constraints for table `invites`
--
ALTER TABLE `invites`
  ADD CONSTRAINT `invites_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invites_ibfk_2` FOREIGN KEY (`used_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invites_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD CONSTRAINT `fk_journal_lines_journal` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `qi_email_templates`
--
ALTER TABLE `qi_email_templates`
  ADD CONSTRAINT `qi_email_templates_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `recurring_invoice_lines`
--
ALTER TABLE `recurring_invoice_lines`
  ADD CONSTRAINT `recurring_invoice_lines_ibfk_1` FOREIGN KEY (`recurring_invoice_id`) REFERENCES `recurring_invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shopping_actions`
--
ALTER TABLE `shopping_actions`
  ADD CONSTRAINT `shopping_actions_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `shopping_lists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shopping_candidates`
--
ALTER TABLE `shopping_candidates`
  ADD CONSTRAINT `shopping_candidates_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `shopping_lists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shopping_candidates_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `shopping_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shopping_items`
--
ALTER TABLE `shopping_items`
  ADD CONSTRAINT `shopping_items_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `shopping_lists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shopping_sessions`
--
ALTER TABLE `shopping_sessions`
  ADD CONSTRAINT `shopping_sessions_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `shopping_lists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shopping_share_tokens`
--
ALTER TABLE `shopping_share_tokens`
  ADD CONSTRAINT `shopping_share_tokens_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `shopping_lists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shopping_store_catalog`
--
ALTER TABLE `shopping_store_catalog`
  ADD CONSTRAINT `shopping_store_catalog_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `shopping_stores` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_companies`
--
ALTER TABLE `user_companies`
  ADD CONSTRAINT `user_companies_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_companies_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vat_entries`
--
ALTER TABLE `vat_entries`
  ADD CONSTRAINT `fk_vat_entries_return` FOREIGN KEY (`vat_return_id`) REFERENCES `vat_returns` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `yoco_payment_events`
--
ALTER TABLE `yoco_payment_events`
  ADD CONSTRAINT `yoco_payment_events_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

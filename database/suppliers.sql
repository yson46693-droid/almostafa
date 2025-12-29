SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(20) DEFAULT NULL,
  `type` enum('honey','packaging','nuts','olive_oil','derivatives','beeswax','sesame') DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_code` (`supplier_code`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

REPLACE INTO `suppliers` (`id`, `supplier_code`, `type`, `name`, `contact_person`, `phone`, `email`, `address`, `balance`, `status`, `created_at`, `updated_at`) VALUES
(1, 'HNY001', 'honey', 'مايكل عسل', NULL, NULL, NULL, NULL, '1000.00', 'active', '2025-11-22 08:49:20', '2025-11-22 09:09:07'),
(2, 'HNY002', 'honey', 'عمرو عسل', NULL, NULL, NULL, NULL, '1000.00', 'active', '2025-11-22 08:50:53', '2025-11-22 09:09:26'),
(3, 'NUT001', 'nuts', 'خالد مكسرات', NULL, NULL, NULL, NULL, '1000.00', 'active', '2025-11-22 08:51:24', '2025-11-22 09:10:30'),
(4, 'PKG001', 'packaging', 'محمد تعبئه', NULL, NULL, NULL, NULL, '1000.00', 'active', '2025-11-22 08:51:39', '2025-11-22 09:10:16'),
(5, 'SES001', 'sesame', 'عادل سمسم', NULL, NULL, NULL, NULL, '1000.00', 'active', '2025-11-22 08:51:54', '2025-11-22 09:10:06'),
(6, 'OIL001', 'olive_oil', 'احمد زيت', NULL, NULL, NULL, NULL, '1000.00', 'active', '2025-11-22 08:52:09', '2025-11-22 09:09:35'),
(7, 'DRV001', 'derivatives', 'يحيي مشتقات', NULL, NULL, NULL, NULL, '1000.00', 'active', '2025-11-22 08:52:29', '2025-11-22 09:09:56'),
(8, 'WAX001', 'beeswax', 'ابراهيم شمع', NULL, NULL, NULL, NULL, '1000.00', 'active', '2025-11-22 08:52:43', '2025-11-22 09:09:47');

ALTER TABLE `suppliers` AUTO_INCREMENT=9;

COMMIT;

-- جداول التحصيل اليومية المتعددة
-- لا تؤثر على رصيد الخزنة أو محفظة المستخدم - للتتبع فقط

SET NAMES utf8mb4;

-- الجدول الرئيسي: جدول تحصيل (يُعاد استخدامه شهرياً مع إمكانية التعديل)
CREATE TABLE IF NOT EXISTS `daily_collection_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL COMMENT 'اسم الجدول للعرض',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `daily_collection_schedules_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جداول التحصيل اليومية - للعرض والتتبع فقط';

-- بنود الجدول: عملاء محليون + مبلغ التحصيل اليومي
CREATE TABLE IF NOT EXISTS `daily_collection_schedule_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_id` int(11) NOT NULL,
  `local_customer_id` int(11) NOT NULL,
  `daily_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'مبلغ التحصيل اليومي المطلوب',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schedule_customer` (`schedule_id`,`local_customer_id`),
  KEY `schedule_id` (`schedule_id`),
  KEY `local_customer_id` (`local_customer_id`),
  CONSTRAINT `daily_collection_schedule_items_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `daily_collection_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_collection_schedule_items_ibfk_2` FOREIGN KEY (`local_customer_id`) REFERENCES `local_customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود جدول التحصيل - عميل + مبلغ يومي';

-- تخصيص من يرى الجدول (سائق، عامل إنتاج، مندوب مبيعات، إلخ)
CREATE TABLE IF NOT EXISTS `daily_collection_schedule_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schedule_user` (`schedule_id`,`user_id`),
  KEY `schedule_id` (`schedule_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `daily_collection_schedule_assignments_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `daily_collection_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_collection_schedule_assignments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_collection_schedule_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ربط الجداول بالمستخدمين المعينين';

-- سجل يومي: تم التحصيل أم قيد التحصيل (بدون تأثير على الخزنة أو المحفظة)
CREATE TABLE IF NOT EXISTS `daily_collection_daily_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_item_id` int(11) NOT NULL,
  `record_date` date NOT NULL COMMENT 'تاريخ اليوم',
  `status` enum('pending','collected') NOT NULL DEFAULT 'pending' COMMENT 'قيد التحصيل / تم التحصيل',
  `collected_at` datetime DEFAULT NULL,
  `collected_by` int(11) DEFAULT NULL COMMENT 'للتسجيل فقط - لا يخصم من المحفظة',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_date` (`schedule_item_id`,`record_date`),
  KEY `schedule_item_id` (`schedule_item_id`),
  KEY `record_date` (`record_date`),
  KEY `status` (`status`),
  CONSTRAINT `daily_collection_daily_records_ibfk_1` FOREIGN KEY (`schedule_item_id`) REFERENCES `daily_collection_schedule_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_collection_daily_records_ibfk_2` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل التحصيل اليومي - للتتبع فقط بدون تأثير مالي';

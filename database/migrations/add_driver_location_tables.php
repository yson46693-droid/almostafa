<?php
/**
 * Migration: جداول تتبع الموقع المباشر وخط سير السائقين
 *
 * - driver_live_location: آخر موقع لكل سائق وحالة الـ live
 * - driver_location_history: سجل النقاط اليومي لخط السير
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$db = db();
$result = ['success' => false, 'message' => '', 'steps' => []];

try {
    // جدول الموقع المباشر للسائق (آخر موقع + حالة الاتصال)
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'driver_live_location'");
    if (empty($tableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `driver_live_location` (
              `user_id` int(11) NOT NULL,
              `latitude` decimal(10,8) NOT NULL,
              `longitude` decimal(11,8) NOT NULL,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `is_online` tinyint(1) DEFAULT 1,
              PRIMARY KEY (`user_id`),
              CONSTRAINT `driver_live_location_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموقع المباشر للسائقين'
        ");
        $result['steps'][] = 'تم إنشاء جدول driver_live_location';
    } else {
        $result['steps'][] = 'جدول driver_live_location موجود مسبقاً';
    }

    // جدول سجل خط السير اليومي
    $tableExists2 = $db->queryOne("SHOW TABLES LIKE 'driver_location_history'");
    if (empty($tableExists2)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `driver_location_history` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `latitude` decimal(10,8) NOT NULL,
              `longitude` decimal(11,8) NOT NULL,
              `recorded_at` date NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_date` (`user_id`, `recorded_at`),
              KEY `recorded_at` (`recorded_at`),
              CONSTRAINT `driver_location_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل خط سير السائقين يومياً'
        ");
        $result['steps'][] = 'تم إنشاء جدول driver_location_history';
    } else {
        $result['steps'][] = 'جدول driver_location_history موجود مسبقاً';
    }

    $result['success'] = true;
    $result['message'] = 'تم تنفيذ Migration بنجاح';
} catch (Throwable $e) {
    $result['message'] = 'خطأ: ' . $e->getMessage();
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);

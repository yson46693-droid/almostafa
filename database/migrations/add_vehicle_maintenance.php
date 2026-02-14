<?php
/**
 * Migration: إنشاء جدول صيانات السيارة (تغيير الزيت وتفويل البنزين)
 *
 * - إنشاء جدول vehicle_maintenance
 * - يربط السيارة والسائق بنوع الصيانة والكيلومترات والصورة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$db = db();
$result = ['success' => false, 'message' => '', 'steps' => []];

try {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'vehicle_maintenance'");
    if (empty($tableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `vehicle_maintenance` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `vehicle_id` int(11) NOT NULL,
              `driver_id` int(11) NOT NULL,
              `type` enum('oil_change','fuel_refill') NOT NULL,
              `maintenance_date` date NOT NULL,
              `km_reading` int(11) NOT NULL,
              `km_diff` int(11) DEFAULT NULL,
              `photo_path` varchar(500) NOT NULL,
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `vehicle_id` (`vehicle_id`),
              KEY `driver_id` (`driver_id`),
              KEY `type` (`type`),
              KEY `maintenance_date` (`maintenance_date`),
              CONSTRAINT `vehicle_maintenance_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
              CONSTRAINT `vehicle_maintenance_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صيانات السيارة - تغيير الزيت وتفويل البنزين'
        ");
        $result['steps'][] = 'تم إنشاء جدول vehicle_maintenance';
    } else {
        $result['steps'][] = 'جدول vehicle_maintenance موجود مسبقاً';
    }

    $result['success'] = true;
    $result['message'] = 'تم تنفيذ Migration بنجاح';
} catch (Throwable $e) {
    $result['message'] = 'خطأ: ' . $e->getMessage();
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);

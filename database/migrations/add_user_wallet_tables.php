<?php
/**
 * Migration: إنشاء جدول محفظة المستخدم وإضافة user_id لجدول العهدة
 *
 * - إنشاء جدول user_wallet_transactions
 * - إضافة عمود user_id لجدول company_custody إن وُجد
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$db = db();
$result = ['success' => false, 'message' => '', 'steps' => []];

try {
    // 1. إنشاء جدول user_wallet_transactions إن لم يكن موجوداً
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'user_wallet_transactions'");
    if (empty($tableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `user_wallet_transactions` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `type` enum('deposit','withdrawal','custody_add','custody_retrieve') NOT NULL,
              `amount` decimal(15,2) NOT NULL,
              `reason` text DEFAULT NULL,
              `reference_type` varchar(50) DEFAULT NULL,
              `reference_id` int(11) DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `created_by` (`created_by`),
              KEY `idx_user_created` (`user_id`, `created_at`),
              CONSTRAINT `user_wallet_transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `user_wallet_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='معاملات محفظة المستخدم'
        ");
        $result['steps'][] = 'تم إنشاء جدول user_wallet_transactions';
    } else {
        $result['steps'][] = 'جدول user_wallet_transactions موجود مسبقاً';
    }

    // 2. إضافة user_id لجدول company_custody إن وُجد الجدول
    $custodyExists = $db->queryOne("SHOW TABLES LIKE 'company_custody'");
    if (!empty($custodyExists)) {
        $col = $db->queryOne("SHOW COLUMNS FROM company_custody LIKE 'user_id'");
        if (empty($col)) {
            $db->execute("ALTER TABLE company_custody ADD COLUMN user_id INT(11) NULL DEFAULT NULL AFTER person_name, ADD KEY user_id (user_id)");
            $result['steps'][] = 'تم إضافة عمود user_id لجدول company_custody';
        } else {
            $result['steps'][] = 'عمود user_id موجود مسبقاً في company_custody';
        }
    } else {
        $result['steps'][] = 'جدول company_custody غير موجود - سيتم إضافة user_id عند إنشائه من خزنة الشركة';
    }

    $result['success'] = true;
    $result['message'] = 'تم تنفيذ Migration بنجاح';
} catch (Throwable $e) {
    $result['message'] = 'خطأ: ' . $e->getMessage();
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);

<?php
/**
 * Migration: جدول طلبات التحصيل من العملاء المحليين (محفظة المستخدم)
 * طلب في انتظار موافقة المحاسب/المدير، عند الموافقة: خصم من رصيد العميل + إيراد خزنة + إيداع محفظة المستخدم
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$db = db();
$result = ['success' => false, 'message' => '', 'steps' => []];

try {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'user_wallet_local_collection_requests'");
    if (empty($tableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `user_wallet_local_collection_requests` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL COMMENT 'المستخدم الذي قدم الطلب (سائق/عامل إنتاج)',
              `local_customer_id` int(11) NOT NULL,
              `customer_name` varchar(255) NOT NULL COMMENT 'نسخة من اسم العميل وقت الطلب',
              `amount` decimal(15,2) NOT NULL,
              `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `approved_by` int(11) DEFAULT NULL,
              `approved_at` datetime DEFAULT NULL,
              `rejection_reason` varchar(500) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `local_customer_id` (`local_customer_id`),
              KEY `status` (`status`),
              KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات تحصيل من عميل محلي - في انتظار موافقة المحاسب/المدير'
        ");
        $result['steps'][] = 'تم إنشاء جدول user_wallet_local_collection_requests';
    } else {
        $result['steps'][] = 'جدول user_wallet_local_collection_requests موجود مسبقاً';
    }
    $result['success'] = true;
    $result['message'] = 'تم تنفيذ Migration بنجاح';
} catch (Throwable $e) {
    $result['message'] = 'خطأ: ' . $e->getMessage();
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);

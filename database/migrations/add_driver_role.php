<?php
/**
 * Migration: إضافة دور "سائق" (driver) إلى جدول users
 *
 * يضيف القيمة 'driver' إلى عمود role ENUM.
 * تشغيل: من المتصفح أو سطر الأوامر بعد التأكد من المسار.
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$db = db();
$result = ['success' => false, 'message' => '', 'already_done' => false];

try {
    $col = $db->queryOne("SHOW COLUMNS FROM users LIKE 'role'");
    if (empty($col['Type'])) {
        $result['message'] = 'عمود role غير موجود في جدول users';
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (stripos($col['Type'], 'driver') !== false) {
        $result['success'] = true;
        $result['already_done'] = true;
        $result['message'] = 'دور driver موجود مسبقاً في عمود role';
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db->execute("ALTER TABLE users MODIFY COLUMN role ENUM('accountant','sales','production','manager','developer','driver') NOT NULL");
    $result['success'] = true;
    $result['message'] = 'تم إضافة دور driver إلى جدول users بنجاح';
} catch (Throwable $e) {
    $result['message'] = 'خطأ: ' . $e->getMessage();
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);

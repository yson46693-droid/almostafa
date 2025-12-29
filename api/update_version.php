<?php
/**
 * API: تحديث رقم الإصدار يدوياً
 * تم إزالة version_helper.php - الإصدار يُقرأ مباشرة من version.json
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من الصلاحيات - فقط المطور يمكنه تحديث الإصدار
$currentUser = getCurrentUser();
if (!$currentUser || strtolower($currentUser['role'] ?? '') !== 'developer') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'غير مصرح لك بتحديث الإصدار'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // قراءة الإصدار الحالي من version.json
    $currentVersion = getCurrentVersion();
    
    echo json_encode([
        'success' => true,
        'message' => 'تم قراءة الإصدار بنجاح',
        'version' => $currentVersion,
        'note' => 'يتم تحديث الإصدار يدوياً من ملف version.json'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء قراءة الإصدار: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


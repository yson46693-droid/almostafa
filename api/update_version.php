<?php
/**
 * API: تحديث رقم الإصدار يدوياً بعد رفع تحديثات من GitHub
 * يمكن استدعاء هذا الملف بعد git pull أو deploy
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/version_helper.php';

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
    // فرض تحديث الإصدار
    $newVersion = checkAndUpdateVersion(true);
    
    echo json_encode([
        'success' => true,
        'message' => 'تم تحديث الإصدار بنجاح',
        'version' => $newVersion
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء تحديث الإصدار: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


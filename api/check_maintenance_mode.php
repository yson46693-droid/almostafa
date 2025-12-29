<?php
/**
 * API للتحقق من حالة وضع الصيانة
 */

define('ACCESS_ALLOWED', true);

// تنظيف output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

// إيقاف عرض الأخطاء على الشاشة
$oldErrorReporting = error_reporting(E_ALL);
$oldDisplayErrors = ini_set('display_errors', '0');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    
    // تعيين header JSON
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // التحقق من وضع الصيانة (من config.php)
    $maintenanceMode = isMaintenanceMode();
    
    // التحقق إذا كان المستخدم مطوراً (إذا كان مسجل دخول)
    $isDev = false;
    if (isLoggedIn()) {
        $isDev = isDeveloper();
    }
    
    $response = [
        'success' => true,
        'maintenance_mode' => $maintenanceMode ? 'on' : 'off',
        'is_developer' => $isDev,
        'allowed' => !$maintenanceMode || $isDev
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    // تنظيف output buffer في حالة الخطأ
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'maintenance_mode' => 'off',
        'is_developer' => false,
        'allowed' => true,
        'error' => 'حدث خطأ في التحقق من وضع الصيانة'
    ], JSON_UNESCAPED_UNICODE);
} finally {
    // استعادة إعدادات الأخطاء
    if (isset($oldErrorReporting)) {
        error_reporting($oldErrorReporting);
    }
    if (isset($oldDisplayErrors)) {
        ini_set('display_errors', $oldDisplayErrors);
    }
}

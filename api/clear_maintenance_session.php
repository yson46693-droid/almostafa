<?php
/**
 * API لتنظيف session وضع الصيانة للمطور
 */

define('ACCESS_ALLOWED', true);

// تنظيف output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    
    // تعيين header JSON
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // فقط المطور يمكنه تنظيف session
    if (isLoggedIn() && isDeveloper() && session_status() === PHP_SESSION_ACTIVE) {
        if (isset($_SESSION['maintenance_mode'])) {
            unset($_SESSION['maintenance_mode']);
        }
        if (isset($_SESSION['maintenance_message'])) {
            unset($_SESSION['maintenance_message']);
        }
    }
    
    $response = [
        'success' => true,
        'message' => 'Session cleaned'
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
        'error' => 'حدث خطأ في تنظيف session'
    ], JSON_UNESCAPED_UNICODE);
}

<?php
/**
 * API: تحميل ملفات CSV من مجلد reports
 */

define('ACCESS_ALLOWED', true);

error_reporting(0);
ini_set('display_errors', 0);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    
    // التحقق من تسجيل الدخول
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unauthorized';
        exit;
    }
    
    $currentUser = getCurrentUser();
    $currentRole = strtolower((string)($currentUser['role'] ?? ''));
    
    // التحقق من الصلاحيات
    $allowedRoles = ['manager', 'developer', 'accountant', 'sales'];
    if (!in_array($currentRole, $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    
    // التحقق من وجود مسار الملف
    $filePath = isset($_GET['file']) ? trim($_GET['file']) : '';
    if (empty($filePath)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File path is required';
        exit;
    }
    
    // تنظيف المسار من أي محاولات للوصول إلى ملفات خارجية
    $filePath = str_replace('\\', '/', $filePath);
    $filePath = ltrim($filePath, '/');
    
    // التحقق من أن الملف داخل مجلد reports
    if (strpos($filePath, '../') !== false || strpos($filePath, '..\\') !== false) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid file path';
        exit;
    }
    
    // إزالة 'reports/' من البداية إذا كان موجوداً (لأن REPORTS_PATH يحتوي على reports/)
    $filePath = preg_replace('/^reports\//', '', $filePath);
    
    // بناء المسار الكامل
    $fullPath = REPORTS_PATH . $filePath;
    $fullPath = str_replace('\\', '/', $fullPath);
    $fullPath = rtrim($fullPath, '/');
    
    // التحقق من وجود الملف
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File not found';
        exit;
    }
    
    // التحقق من أن الملف CSV
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if ($extension !== 'csv') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File is not a CSV file';
        exit;
    }
    
    // التأكد من أن الملف داخل مجلد reports
    $reportsBaseReal = realpath(REPORTS_PATH);
    $fileReal = realpath($fullPath);
    if (!$reportsBaseReal || !$fileReal || strpos($fileReal, $reportsBaseReal) !== 0) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access denied';
        exit;
    }
    
    // تنظيف output buffer
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // الحصول على اسم الملف
    $fileName = basename($fullPath);
    
    // إرسال headers للتنزيل
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    
    // إرسال الملف
    readfile($fullPath);
    exit;
    
} catch (Exception $e) {
    error_log('Download CSV error: ' . $e->getMessage());
    error_log('Download CSV error trace: ' . $e->getTraceAsString());
    
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error downloading file';
    exit;
}


<?php
/**
 * API: تحميل ملفات الشات مع الـ headers الصحيحة لتجنب مشكلة CORB
 */

define('ACCESS_ALLOWED', true);

error_reporting(0);
ini_set('display_errors', 0);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/auth.php';
} catch (Throwable $e) {
    error_log('chat/get_file bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error loading file';
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

try {
    // الحصول على مسار الملف من query parameter
    $filePath = isset($_GET['path']) ? $_GET['path'] : '';
    
    if (empty($filePath)) {
        http_response_code(400);
        echo 'File path is required';
        exit;
    }

    // تنظيف المسار لمنع directory traversal attacks
    $filePath = str_replace(['../', '..\\', '..', "\0"], '', $filePath);
    $filePath = ltrim($filePath, '/\\');
    
    // التأكد من أن المسار لا يحتوي على مسارات مطلقة
    if (strpos($filePath, '/') === 0 || strpos($filePath, '\\') === 0) {
        http_response_code(400);
        echo 'Invalid file path';
        exit;
    }
    
    // تحديد المسار الكامل للملف
    $baseDir = __DIR__ . '/../../uploads/chat/';
    $fullPath = realpath($baseDir . $filePath);
    
    // التأكد من أن الملف موجود وأنه داخل مجلد uploads/chat
    if (!$fullPath || !file_exists($fullPath)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }
    
    // التأكد من أن الملف داخل المجلد المسموح به
    $baseDirReal = realpath($baseDir);
    if (!$baseDirReal || strpos($fullPath, $baseDirReal) !== 0) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    
    // تحديد نوع الملف و Content-Type
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimeTypes = [
        // Images
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        // Videos
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'flv' => 'video/x-flv',
        'wmv' => 'video/x-ms-wmv',
        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'opus' => 'audio/opus',
        'webm' => 'audio/webm',
        // Documents
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Archives
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        // Text
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'html' => 'text/html',
    ];
    
    $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    // إعداد الـ headers الصحيحة
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($fullPath)) . ' GMT');
    
    // إضافة CORS headers إذا لزم الأمر
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    
    // إرسال الملف
    readfile($fullPath);
    exit;
    
} catch (Throwable $e) {
    error_log('chat/get_file error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error loading file';
    exit;
}


<?php
/**
 * API Root - يمنع 404 عند طلب مجلد /api/
 * استخدم المسار الكامل لملف API المطلوب مثل: /api/driver_location.php
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(400);
echo json_encode([
    'error' => 'استخدم مسار API محدد (مثل: /api/driver_location.php)',
    'error_en' => 'Use a specific API endpoint (e.g. /api/driver_location.php)'
], JSON_UNESCAPED_UNICODE);

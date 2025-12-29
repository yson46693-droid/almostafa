<?php
/**
 * API لتسجيل معلومات debug من JavaScript
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    
    // تحميل session logger إذا كان متوفراً
    if (file_exists(__DIR__ . '/../includes/session_logger.php')) {
        require_once __DIR__ . '/../includes/session_logger.php';
    }
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data && isset($data['type']) && $data['type'] === 'session_end_overlay') {
        if (function_exists('logSessionInfo')) {
            logSessionInfo('JavaScript: Session End Overlay Triggered', [
                'type' => 'javascript_trigger',
                'data' => $data['data'] ?? [],
            ]);
        }
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('Session debug log API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

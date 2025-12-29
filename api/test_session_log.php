
<?php
/**
 * اختبار نظام تسجيل الجلسات
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/config.php';
    
    // تحميل session logger
    if (file_exists(__DIR__ . '/../includes/session_logger.php')) {
        require_once __DIR__ . '/../includes/session_logger.php';
    }
    
    $results = [
        'session_logger_exists' => file_exists(__DIR__ . '/../includes/session_logger.php'),
        'logSessionInfo_exists' => function_exists('logSessionInfo'),
        'logSessionFailure_exists' => function_exists('logSessionFailure'),
        'logApi401_exists' => function_exists('logApi401'),
        'logs_dir_exists' => is_dir(__DIR__ . '/../logs'),
        'logs_dir_writable' => is_writable(__DIR__ . '/../logs'),
        'log_file_path' => __DIR__ . '/../logs/session_debug.log',
        'log_file_exists' => file_exists(__DIR__ . '/../logs/session_debug.log'),
        'log_file_writable' => is_writable(__DIR__ . '/../logs/session_debug.log'),
        'session_status' => session_status(),
        'session_id' => session_id() ? substr(session_id(), 0, 20) . '...' : 'NO_SESSION',
    ];
    
    // محاولة كتابة test log
    if (function_exists('logSessionInfo')) {
        logSessionInfo('TEST: اختبار نظام التسجيل', [
            'test' => true,
            'timestamp' => time(),
        ]);
        $results['test_log_written'] = true;
    } else {
        $results['test_log_written'] = false;
        $results['error'] = 'logSessionInfo function not found';
    }
    
    // محاولة الكتابة المباشرة
    $testFile = __DIR__ . '/../logs/test_write.log';
    $testWrite = @file_put_contents($testFile, "Test write at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    $results['direct_write_test'] = $testWrite !== false;
    $results['direct_write_error'] = $testWrite === false ? error_get_last() : null;
    
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}

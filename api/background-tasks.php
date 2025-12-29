<?php
/**
 * API للعمليات الخلفية الثقيلة
 * يتم استدعاؤها بشكل غير متزامن بعد تحميل الصفحة لتحسين الأداء
 * 
 * SECURITY: This endpoint requires valid session and returns JSON only
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

// Set error reporting to prevent notices/warnings from breaking JSON output
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set('display_errors', 0);

// Start session first (before any output)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON headers immediately (before any includes that might output)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Define constant for background tasks
define('BACKGROUND_TASKS_ACTIVE', true);

// ========== STRICT SESSION VALIDATION ==========
/**
 * Validate session and return JSON 401 if expired
 * This function MUST be called before any database operations
 */
function validateSessionStrict() {
    // Check if session has user_id
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'status' => 'expired',
            'message' => 'Session expired'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Try to verify session with database (with timeout protection)
    try {
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        
        // Use isLoggedIn() to verify session
        if (!isLoggedIn()) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'status' => 'expired',
                'message' => 'Session expired'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Get current user to verify they exist and are active
        $currentUser = getCurrentUser();
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'status' => 'expired',
                'message' => 'Session expired'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        return $currentUser;
    } catch (Throwable $e) {
        // On database error, treat as session expired for security
        error_log('Background tasks: Session validation error: ' . $e->getMessage());
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'status' => 'expired',
            'message' => 'Session expired'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Validate session FIRST - before any includes or processing
$currentUser = validateSessionStrict();
$userId = (int) ($currentUser['id'] ?? 0);

// Additional safety check
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'status' => 'expired',
        'message' => 'Session expired'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load required files (only after session validation)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// ========== Rate Limiting ==========
/**
 * Check rate limiting for user
 */
function checkBackgroundTasksRateLimit($userId) {
    if (!class_exists('Cache')) {
        require_once __DIR__ . '/../includes/cache.php';
    }
    
    $cacheKey = 'bg_tasks_rate_' . $userId;
    $lastCall = Cache::get($cacheKey);
    $currentTime = time();
    
    // Maximum: once every 30 seconds per user
    $minInterval = 30;
    
    if ($lastCall && ($currentTime - $lastCall) < $minInterval) {
        return false; // Called recently
    }
    
    // Update last call time
    Cache::put($cacheKey, $currentTime, 60); // Store for 1 minute
    return true;
}

// ========== Lock Mechanism ==========
/**
 * Acquire lock for background tasks
 */
function acquireBackgroundTasksLock($lockKey) {
    $lockDir = sys_get_temp_dir();
    $lockFile = $lockDir . DIRECTORY_SEPARATOR . 'bg_tasks_' . md5($lockKey) . '.lock';
    
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) {
        return false;
    }
    
    // Try to acquire non-blocking lock
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false; // Another process is running
    }
    
    // Write lock information
    fwrite($fp, json_encode([
        'lock_key' => $lockKey,
        'started_at' => time(),
        'pid' => getmypid()
    ]));
    fflush($fp);
    
    return $fp;
}

/**
 * Release lock
 */
function releaseBackgroundTasksLock($fp) {
    if ($fp && is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ========== Rate Limiting Check ==========
if (!checkBackgroundTasksRateLimit($userId)) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false,
        'message' => 'Rate limit exceeded. Please wait before trying again.',
        'retry_after' => 30
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== Lock Check ==========
$lockFile = acquireBackgroundTasksLock('user_' . $userId);
if (!$lockFile) {
    // Another process is running
    http_response_code(409); // Conflict
    echo json_encode([
        'success' => false,
        'message' => 'Another background task is already running',
        'status' => 'locked'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Cleanup lock on exit
register_shutdown_function(function() use ($lockFile) {
    releaseBackgroundTasksLock($lockFile);
});

// ========== Set Timeout ==========
// Set shorter timeout for background operations (15 seconds instead of 30)
set_time_limit(15);
ignore_user_abort(false); // Allow cancellation if user closes request

// ========== Execute Background Tasks ==========
$results = [];

// 1. Daily packaging alerts (once per day only)
if (function_exists('processDailyPackagingAlert')) {
    try {
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $lockKey = 'packaging_alert_' . date('Y-m-d');
        $cacheKey = 'packaging_alert_' . date('Y-m-d');
        
        $taskLock = acquireBackgroundTasksLock($lockKey);
        
        if ($taskLock) {
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                processDailyPackagingAlert();
                Cache::put($cacheKey, true, 86400); // 24 hours
                $results['packaging_alert'] = ['success' => true];
            } else {
                $results['packaging_alert'] = ['success' => true, 'skipped' => 'already_processed'];
            }
            
            releaseBackgroundTasksLock($taskLock);
        } else {
            $results['packaging_alert'] = ['success' => false, 'error' => 'Lock acquisition failed'];
        }
    } catch (Throwable $e) {
        error_log('Background task: Packaging alert error: ' . $e->getMessage());
        $results['packaging_alert'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 2. Auto checkout processing (once per day only)
if (function_exists('processAutoCheckoutForMissingEmployees')) {
    try {
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $cacheKey = 'auto_checkout_' . date('Y-m-d');
        $lockKey = 'auto_checkout_' . date('Y-m-d');
        $taskLock = acquireBackgroundTasksLock($lockKey);
        
        if ($taskLock) {
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                processAutoCheckoutForMissingEmployees();
                Cache::put($cacheKey, true, 86400); // 24 hours
                $results['auto_checkout'] = ['success' => true];
            } else {
                $results['auto_checkout'] = ['success' => true, 'skipped' => 'already_processed'];
            }
            
            releaseBackgroundTasksLock($taskLock);
        } else {
            $results['auto_checkout'] = ['success' => false, 'error' => 'Lock acquisition failed'];
        }
    } catch (Throwable $e) {
        error_log('Background task: Auto checkout error: ' . $e->getMessage());
        $results['auto_checkout'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 3. Warning count reset (once per month only)
if (function_exists('resetWarningCountsForNewMonth')) {
    try {
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $cacheKey = 'warning_reset_' . date('Y-m');
        $lockKey = 'warning_reset_' . date('Y-m');
        $taskLock = acquireBackgroundTasksLock($lockKey);
        
        if ($taskLock) {
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                resetWarningCountsForNewMonth();
                Cache::put($cacheKey, true, 2592000); // 30 days
                $results['warning_reset'] = ['success' => true];
            } else {
                $results['warning_reset'] = ['success' => true, 'skipped' => 'already_processed'];
            }
            
            releaseBackgroundTasksLock($taskLock);
        } else {
            $results['warning_reset'] = ['success' => false, 'error' => 'Lock acquisition failed'];
        }
    } catch (Throwable $e) {
        error_log('Background task: Warning reset error: ' . $e->getMessage());
        $results['warning_reset'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 4. Payment notifications for sales
if ($currentUser && strtolower($currentUser['role']) === 'sales') {
    if (function_exists('notifyTodayPaymentSchedules')) {
        try {
            notifyTodayPaymentSchedules($userId);
            $results['payment_notifications'] = ['success' => true];
        } catch (Throwable $e) {
            error_log('Background task: Payment notifications error: ' . $e->getMessage());
            $results['payment_notifications'] = ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// 5. Monthly reports (once per month only)
if (function_exists('maybeSendMonthlyProductionDetailedReport')) {
    try {
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $month = (int) date('n');
        $year = (int) date('Y');
        $cacheKey = 'production_report_' . $year . '_' . $month;
        $lockKey = 'production_report_' . $year . '_' . $month;
        $taskLock = acquireBackgroundTasksLock($lockKey);
        
        if ($taskLock) {
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                maybeSendMonthlyProductionDetailedReport($month, $year);
                Cache::put($cacheKey, true, 2592000); // 30 days
                $results['production_report'] = ['success' => true];
            } else {
                $results['production_report'] = ['success' => true, 'skipped' => 'already_processed'];
            }
            
            releaseBackgroundTasksLock($taskLock);
        } else {
            $results['production_report'] = ['success' => false, 'error' => 'Lock acquisition failed'];
        }
    } catch (Throwable $e) {
        error_log('Background task: Production report error: ' . $e->getMessage());
        $results['production_report'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 6. Daily low stock report (once per day only)
if (defined('ENABLE_DAILY_LOW_STOCK_REPORT') && ENABLE_DAILY_LOW_STOCK_REPORT) {
    try {
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        // Load the function if not already loaded
        if (!function_exists('triggerDailyLowStockReport')) {
            require_once __DIR__ . '/../includes/daily_low_stock_report.php';
        }
        
        if (function_exists('triggerDailyLowStockReport')) {
            
            $cacheKey = 'low_stock_report_' . date('Y-m-d');
            $lockKey = 'low_stock_report_' . date('Y-m-d');
            $taskLock = acquireBackgroundTasksLock($lockKey);
            
            if ($taskLock) {
                $alreadyProcessed = Cache::get($cacheKey);
                
                if (!$alreadyProcessed) {
                    triggerDailyLowStockReport();
                    Cache::put($cacheKey, true, 86400); // 24 hours
                    $results['low_stock_report'] = ['success' => true];
                } else {
                    $results['low_stock_report'] = ['success' => true, 'skipped' => 'already_processed'];
                }
                
                releaseBackgroundTasksLock($taskLock);
            } else {
                $results['low_stock_report'] = ['success' => false, 'error' => 'Lock acquisition failed'];
            }
        }
    } catch (Throwable $e) {
            error_log('Background task: Low stock report error: ' . $e->getMessage());
            $results['low_stock_report'] = ['success' => false, 'error' => $e->getMessage()];
        }
}

// 7. Daily backup delivery (once per day only)
if (defined('ENABLE_DAILY_BACKUP_DELIVERY') && ENABLE_DAILY_BACKUP_DELIVERY) {
    try {
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        // Load the function if not already loaded
        if (!function_exists('triggerDailyBackupDelivery')) {
            require_once __DIR__ . '/../includes/daily_backup_sender.php';
        }
        
        if (function_exists('triggerDailyBackupDelivery')) {
            
            $cacheKey = 'daily_backup_' . date('Y-m-d');
            $lockKey = 'daily_backup_' . date('Y-m-d');
            $taskLock = acquireBackgroundTasksLock($lockKey);
            
            if ($taskLock) {
                $alreadyProcessed = Cache::get($cacheKey);
                
                if (!$alreadyProcessed) {
                    triggerDailyBackupDelivery();
                    Cache::put($cacheKey, true, 86400); // 24 hours
                    $results['daily_backup'] = ['success' => true];
                } else {
                    $results['daily_backup'] = ['success' => true, 'skipped' => 'already_processed'];
                }
                
                releaseBackgroundTasksLock($taskLock);
            } else {
                $results['daily_backup'] = ['success' => false, 'error' => 'Lock acquisition failed'];
            }
        }
    } catch (Throwable $e) {
            error_log('Background task: Daily backup error: ' . $e->getMessage());
            $results['daily_backup'] = ['success' => false, 'error' => $e->getMessage()];
        }
}

// Return results as JSON
echo json_encode([
    'success' => true,
    'results' => $results,
    'timestamp' => time()
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

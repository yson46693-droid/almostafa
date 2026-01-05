<?php
/**
 * API موحد لجميع طلبات الـ Polling
 * يجمع: session keep-alive, background tasks, maintenance mode, notifications, chat messages
 * يقلل عدد الطلبات بشكل كبير من 6+ طلبات منفصلة إلى طلب واحد كل 60 ثانية
 */

define('ACCESS_ALLOWED', true);

// إعدادات timeout محسّنة (تم تقليلها لتحسين الأداء)
@set_time_limit(10);
@ini_set('max_execution_time', 10);
@ini_set('max_input_time', 5);

// تنظيف output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ملاحظة: تم إزالة معالجة OPTIONS لأن unified_polling يستخدم same-origin requests فقط
// same-origin requests لا تحتاج preflight (OPTIONS) requests
// هذا يتوافق بشكل أفضل مع InfinityFree ويتجنب مشاكل preflight غير الضرورية

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    
    $response = [
        'success' => true,
        'timestamp' => time(),
        'session' => null,
        'maintenance' => null,
        'background_tasks' => null,
        'notifications' => null,
        'chat' => null,
        'update_check' => null
    ];
    
    // الحصول على معاملات الطلب
    $includeChat = isset($_GET['chat']) && $_GET['chat'] === '1';
    $includeNotifications = isset($_GET['notifications']) && $_GET['notifications'] === '1';
    
    // 1. التحقق من الجلسة وتجديدها (أساسي - يعمل دائماً)
    if (isLoggedIn()) {
        // تحديث وقت آخر نشاط
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['last_activity'] = time();
        }
        
        $currentUser = getCurrentUser();
        $userId = (int) ($currentUser['id'] ?? 0);
        
        $response['session'] = [
            'active' => true,
            'user_id' => $userId
        ];
        
        // 2. التحقق من وضع الصيانة
        $maintenanceMode = isMaintenanceMode();
        $isDev = isDeveloper();
        
        $response['maintenance'] = [
            'mode' => $maintenanceMode ? 'on' : 'off',
            'is_developer' => $isDev,
            'allowed' => !$maintenanceMode || $isDev
        ];
        
        // 3. Background Tasks (كل 5 دقائق فقط)
        if (class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $lastBackgroundCheck = Cache::get('bg_tasks_last_' . $userId) ?? 0;
        $currentTime = time();
        
        if (($currentTime - $lastBackgroundCheck) >= 300) { // 5 دقائق
            $bgTasksResults = [];
            
            // Daily packaging alerts
            if (function_exists('processDailyPackagingAlert')) {
                try {
                    $cacheKey = 'packaging_alert_' . date('Y-m-d');
                    if (!Cache::get($cacheKey)) {
                        processDailyPackagingAlert();
                        Cache::put($cacheKey, true, 86400);
                        $bgTasksResults['packaging_alert'] = ['success' => true];
                    }
                } catch (Throwable $e) {
                    $bgTasksResults['packaging_alert'] = ['success' => false];
                }
            }
            
            // Auto checkout
            if (function_exists('processAutoCheckoutForMissingEmployees')) {
                try {
                    $cacheKey = 'auto_checkout_' . date('Y-m-d');
                    if (!Cache::get($cacheKey)) {
                        processAutoCheckoutForMissingEmployees();
                        Cache::put($cacheKey, true, 86400);
                        $bgTasksResults['auto_checkout'] = ['success' => true];
                    }
                } catch (Throwable $e) {
                    // Error handling
                }
            }
            
            // Warning reset
            if (function_exists('resetWarningCountsForNewMonth')) {
                try {
                    $cacheKey = 'warning_reset_' . date('Y-m');
                    if (!Cache::get($cacheKey)) {
                        resetWarningCountsForNewMonth();
                        Cache::put($cacheKey, true, 2592000);
                        $bgTasksResults['warning_reset'] = ['success' => true];
                    }
                } catch (Throwable $e) {
                    // Error handling
                }
            }
            
            // Payment notifications for sales
            if (strtolower($currentUser['role'] ?? '') === 'sales' && function_exists('notifyTodayPaymentSchedules')) {
                try {
                    notifyTodayPaymentSchedules($userId);
                    $bgTasksResults['payment_notifications'] = ['success' => true];
                } catch (Throwable $e) {
                    // Error handling
                }
            }
            
            // Monthly reports
            if (function_exists('maybeSendMonthlyProductionDetailedReport')) {
                try {
                    $month = (int) date('n');
                    $year = (int) date('Y');
                    $cacheKey = 'production_report_' . $year . '_' . $month;
                    if (!Cache::get($cacheKey)) {
                        maybeSendMonthlyProductionDetailedReport($month, $year);
                        Cache::put($cacheKey, true, 2592000);
                        $bgTasksResults['production_report'] = ['success' => true];
                    }
                } catch (Throwable $e) {
                    // Error handling
                }
            }
            
            // Daily backup delivery (once per day only)
            if (defined('ENABLE_DAILY_BACKUP_DELIVERY') && ENABLE_DAILY_BACKUP_DELIVERY) {
                try {
                    if (!function_exists('triggerDailyBackupDelivery')) {
                        require_once __DIR__ . '/../includes/daily_backup_sender.php';
                    }
                    
                    if (function_exists('triggerDailyBackupDelivery')) {
                        $cacheKey = 'daily_backup_' . date('Y-m-d');
                        if (!Cache::get($cacheKey)) {
                            triggerDailyBackupDelivery();
                            Cache::put($cacheKey, true, 86400); // 24 hours
                            $bgTasksResults['daily_backup'] = ['success' => true];
                        } else {
                            $bgTasksResults['daily_backup'] = ['success' => true, 'skipped' => 'already_processed'];
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Unified polling: Daily backup error: ' . $e->getMessage());
                    $bgTasksResults['daily_backup'] = ['success' => false, 'error' => $e->getMessage()];
                }
            }
            
            $response['background_tasks'] = [
                'executed' => true,
                'results' => $bgTasksResults
            ];
            
            Cache::put('bg_tasks_last_' . $userId, $currentTime, 3600);
        } else {
            $response['background_tasks'] = [
                'executed' => false,
                'next_check_in' => 300 - ($currentTime - $lastBackgroundCheck)
            ];
        }
        
        // 4. Notifications (إذا طُلب)
        if ($includeNotifications) {
            try {
                $db = db();
                
                // الحصول على آخر إشعارات
                $lastNotificationId = isset($_GET['last_notification_id']) ? (int)$_GET['last_notification_id'] : 0;
                $limit = 20;
                
                $sql = "SELECT n.*, u.name as created_by_name 
                        FROM notifications n 
                        LEFT JOIN users u ON n.created_by = u.id 
                        WHERE n.user_id = ? AND n.id > ?
                        ORDER BY n.created_at DESC 
                        LIMIT ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$userId, $lastNotificationId, $limit]);
                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // حساب عدد الإشعارات غير المقروءة
                $unreadSql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
                $unreadStmt = $db->prepare($unreadSql);
                $unreadStmt->execute([$userId]);
                $unreadCount = $unreadStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                
                $response['notifications'] = [
                    'count' => count($notifications),
                    'unread_count' => (int)$unreadCount,
                    'notifications' => $notifications
                ];
            } catch (Throwable $e) {
                $response['notifications'] = [
                    'error' => 'Failed to fetch notifications',
                    'count' => 0,
                    'unread_count' => 0,
                    'notifications' => []
                ];
            }
        }
        
        // 5. Chat Messages (إذا طُلب)
        if ($includeChat) {
            try {
                $db = db();
                $lastMessageId = isset($_GET['last_message_id']) ? (int)$_GET['last_message_id'] : 0;
                $chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
                
                if ($chatId > 0) {
                    // جلب الرسائل الجديدة فقط (تم تقليل LIMIT من 50 إلى 20 لتحسين الأداء)
                    $sql = "SELECT m.*, u.name as user_name, u.role as user_role 
                            FROM chat_messages m
                            LEFT JOIN users u ON m.user_id = u.id
                            WHERE m.chat_id = ? AND m.id > ?
                            ORDER BY m.created_at ASC
                            LIMIT 20";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$chatId, $lastMessageId]);
                    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $response['chat'] = [
                        'messages' => $messages,
                        'count' => count($messages)
                    ];
                } else {
                    $response['chat'] = [
                        'messages' => [],
                        'count' => 0
                    ];
                }
            } catch (Throwable $e) {
                $response['chat'] = [
                    'error' => 'Failed to fetch messages',
                    'messages' => [],
                    'count' => 0
                ];
            }
        }
        
    } else {
        // الجلسة منتهية
        $response['success'] = false;
        $response['session'] = [
            'active' => false,
            'expired' => true
        ];
        $response['maintenance'] = [
            'mode' => 'off',
            'is_developer' => false,
            'allowed' => true
        ];
    }
    
    // 6. Update Check (يعمل دائماً - لا يحتاج لتسجيل دخول)
    try {
        $currentVersion = defined('APP_VERSION') ? APP_VERSION : '1.0.0';
        
        // حساب hash للملفات الرئيسية للتحقق من التغييرات
        $mainFiles = [
            __DIR__ . '/../index.php',
            __DIR__ . '/../templates/header.php',
            __DIR__ . '/../templates/footer.php',
            __DIR__ . '/../includes/config.php'
        ];
        
        $fileHashes = [];
        $lastModified = 0;
        
        foreach ($mainFiles as $file) {
            if (file_exists($file)) {
                $fileHashes[] = md5_file($file);
                $mtime = filemtime($file);
                if ($mtime > $lastModified) {
                    $lastModified = $mtime;
                }
            }
        }
        
        // إنشاء hash فريد بناءً على جميع الملفات
        $contentHash = md5(implode('', $fileHashes) . $currentVersion);
        
        $response['update_check'] = [
            'version' => $currentVersion,
            'last_modified' => $lastModified,
            'content_hash' => $contentHash
        ];
    } catch (Throwable $e) {
        $response['update_check'] = [
            'error' => 'Failed to check updates'
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'حدث خطأ في معالجة الطلب',
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
}

<?php
/**
 * API: الحصول على عدد الموافقات المعلقة
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/approval_system.php';
    
    // تحميل session logger إذا كان متوفراً
    if (file_exists(__DIR__ . '/../includes/session_logger.php')) {
        require_once __DIR__ . '/../includes/session_logger.php';
    }
} catch (Throwable $bootstrapError) {
    error_log('approvals API bootstrap error: ' . $bootstrapError->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Initialization error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تسجيل معلومات الجلسة قبل التحقق
// استخدام error_log مباشرة كـ fallback
error_log("=== API approvals.php - قبل التحقق من isLoggedIn() ===");
error_log("Session status: " . session_status());
error_log("Has session: " . (isset($_SESSION) ? 'YES' : 'NO'));
error_log("Has user_id: " . (isset($_SESSION['user_id']) ? 'YES (' . $_SESSION['user_id'] . ')' : 'NO'));
error_log("Has logged_in: " . (isset($_SESSION['logged_in']) ? 'YES (' . ($_SESSION['logged_in'] ? 'true' : 'false') . ')' : 'NO'));
error_log("Session ID: " . (session_id() ? substr(session_id(), 0, 20) . '...' : 'NO_SESSION'));

if (function_exists('logSessionInfo')) {
    logSessionInfo('API approvals.php - قبل التحقق من isLoggedIn()', [
        'session_status' => session_status(),
        'has_session' => isset($_SESSION),
        'has_user_id' => isset($_SESSION['user_id']),
        'has_logged_in' => isset($_SESSION['logged_in']),
        'logged_in_value' => $_SESSION['logged_in'] ?? null,
    ]);
} else {
    error_log("WARNING: logSessionInfo function not found!");
}

// التحقق من تسجيل الدخول مع محاولة إصلاح الجلسة إذا كانت صالحة
$isLoggedInResult = isLoggedIn();

if (!$isLoggedInResult) {
    // محاولة إصلاح الجلسة إذا كانت الجلسة PHP صالحة لكن isLoggedIn() رجع false
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && 
        isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        
        // تسجيل محاولة الإصلاح
        if (function_exists('logSessionInfo')) {
            logSessionInfo('محاولة إصلاح الجلسة - الجلسة PHP صالحة لكن isLoggedIn() رجع false', [
                'user_id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'] ?? 'unknown',
                'role' => $_SESSION['role'] ?? 'unknown',
            ]);
        }
        
        // محاولة إعادة إنشاء الجلسة في قاعدة البيانات
        try {
            $db = db();
            $userId = $_SESSION['user_id'];
            $sessionId = session_id();
            
            if ($sessionId && ensureSessionsTable()) {
                // التحقق من أن المستخدم موجود ونشط
                $user = $db->queryOne("SELECT * FROM users WHERE id = ? AND status = 'active'", [$userId]);
                
                if ($user) {
                    // إعادة إنشاء الجلسة في قاعدة البيانات
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $expiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                    
                    // حذف جميع الجلسات القديمة للمستخدم أولاً (لتجنب الجلسات المتعددة)
                    $db->execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);
                    
                    // إضافة الجلسة الجديدة (واحدة فقط)
                    $db->execute(
                        "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                         VALUES (?, ?, ?, ?, ?, NOW())",
                        [$userId, $sessionId, $ipAddress, $userAgent, $expiresAt]
                    );
                    
                    // إعادة التحقق من isLoggedIn()
                    $isLoggedInResult = isLoggedIn();
                    
                    if (function_exists('logSessionInfo')) {
                        logSessionInfo('تم إصلاح الجلسة بنجاح', [
                            'is_logged_in_after_fix' => $isLoggedInResult ? 'YES' : 'NO',
                        ]);
                    }
                } else {
                    if (function_exists('logSessionFailure')) {
                        logSessionFailure('المستخدم غير موجود أو غير نشط', [
                            'user_id' => $userId,
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            if (function_exists('logSessionInfo')) {
                logSessionInfo('خطأ في محاولة إصلاح الجلسة', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    } else {
        // الجلسة غير صالحة فعلاً
        if (function_exists('logApi401')) {
            logApi401('api/approvals.php', 'isLoggedIn() رجع false - الجلسة غير صالحة', [
                'has_session' => isset($_SESSION),
                'has_user_id' => null !== ($_SESSION['user_id'] ?? null),
                'has_logged_in' => null !== ($_SESSION['logged_in'] ?? null),
                'logged_in_value' => $_SESSION['logged_in'] ?? null,
            ]);
        }
    }
    
    // إذا لم يتم إصلاح الجلسة، إرجاع 401
    if (!$isLoggedInResult) {
        // تسجيل مفصل قبل إرجاع 401
        error_log("=== API approvals.php - إرجاع 401 ===");
        error_log("isLoggedIn() result: FALSE");
        error_log("Session status: " . session_status());
        error_log("Has session: " . (isset($_SESSION) ? 'YES' : 'NO'));
        error_log("Has user_id: " . (isset($_SESSION['user_id']) ? 'YES (' . $_SESSION['user_id'] . ')' : 'NO'));
        error_log("Has logged_in: " . (isset($_SESSION['logged_in']) ? 'YES (' . ($_SESSION['logged_in'] ? 'true' : 'false') . ')' : 'NO'));
        error_log("Session ID: " . (session_id() ? substr(session_id(), 0, 20) . '...' : 'NO_SESSION'));
        error_log("Cookie session name: " . session_name());
        error_log("Cookie exists: " . (isset($_COOKIE[session_name()]) ? 'YES' : 'NO'));
        if (isset($_COOKIE[session_name()])) {
            error_log("Cookie session ID: " . substr($_COOKIE[session_name()], 0, 20) . '...');
            error_log("Cookie matches session: " . ($_COOKIE[session_name()] === session_id() ? 'YES' : 'NO'));
        }
        
        if (function_exists('logApi401')) {
            logApi401('api/approvals.php', 'isLoggedIn() رجع false بعد محاولة الإصلاح', [
                'has_session' => isset($_SESSION),
                'has_user_id' => null !== ($_SESSION['user_id'] ?? null),
                'has_logged_in' => null !== ($_SESSION['logged_in'] ?? null),
                'logged_in_value' => $_SESSION['logged_in'] ?? null,
            ]);
        }
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// التحقق من أن المستخدم مدير فقط
error_log("=== API approvals.php - بعد isLoggedIn() SUCCESS ===");
error_log("Calling getCurrentUser()...");

$currentUser = getCurrentUser();

error_log("getCurrentUser() result: " . ($currentUser ? 'EXISTS' : 'NULL'));
if ($currentUser) {
    error_log("User ID: " . ($currentUser['id'] ?? 'NOT_SET'));
    error_log("User Role: " . ($currentUser['role'] ?? 'NOT_SET'));
    error_log("User Status: " . ($currentUser['status'] ?? 'NOT_SET'));
} else {
    error_log("getCurrentUser() returned NULL - هذا قد يكون السبب!");
    if (function_exists('logSessionInfo')) {
        logSessionInfo('getCurrentUser() رجع NULL رغم أن isLoggedIn() نجح', [
            'user_id_in_session' => $_SESSION['user_id'] ?? null,
            'logged_in' => $_SESSION['logged_in'] ?? null,
        ]);
    }
}

if (!$currentUser || ($currentUser['role'] ?? '') !== 'manager') {
    $reason = !$currentUser ? 'getCurrentUser() returned NULL' : 'User role is not manager: ' . ($currentUser['role'] ?? 'NOT_SET');
    error_log("=== API approvals.php - إرجاع 403 ===");
    error_log("Reason: {$reason}");
    
    if (function_exists('logApi401')) {
        logApi401('api/approvals.php', $reason, [
            'getCurrentUser_result' => $currentUser ? 'EXISTS' : 'NULL',
            'user_role' => $currentUser['role'] ?? 'NOT_SET',
            'user_id_in_session' => $_SESSION['user_id'] ?? null,
        ]);
    }
    
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

error_log("API approvals.php - SUCCESS: User is manager, returning count");

try {
    $count = getPendingApprovalsCount();
    
    echo json_encode([
        'success' => true,
        'count' => (int)$count
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Approvals count API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ], JSON_UNESCAPED_UNICODE);
}


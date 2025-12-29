<?php
/**
 * API لإدارة بيانات WebAuthn (البصمة)
 */

define('ACCESS_ALLOWED', true);
// تعريف ثابت لمنع حذف الجلسة في webauthn_credentials API
define('WEBAUTHN_API_ACTIVE', true);

// التأكد من بدء الجلسة قبل أي شيء
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

// === التحقق من تسجيل الدخول - استخدام isLoggedIn() ===
$isAuthenticated = false;
$userId = null;

// التأكد من أن الثابت معرّف
if (!defined('WEBAUTHN_API_ACTIVE')) {
    define('WEBAUTHN_API_ACTIVE', true);
}

// استخدام isLoggedIn() للتحقق من تسجيل الدخول
if (isLoggedIn() && isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $isAuthenticated = true;
}

// إذا فشلت جميع محاولات التحقق
if (!$isAuthenticated || !$userId) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول',
        'debug' => [
            'session_id' => session_id(),
            'session_user_id' => $_SESSION['user_id'] ?? 'not set',
            'session_logged_in' => $_SESSION['logged_in'] ?? 'not set',
            'isLoggedIn_result' => isLoggedIn() ? 'true' : 'false'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
// CORS headers
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    try {
        // === استخدام user_id من التحقق الأولي ===
        // $userId تم تعيينه بالفعل في التحقق الأولي أعلاه
        // إذا لم يكن موجوداً، هذا يعني أن التحقق فشل بالفعل وتم إرجاع 401
        if (!$userId || empty($userId)) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'غير مصرح به - تعذر تحميل بيانات المستخدم',
                'debug' => [
                    'session_user_id' => $_SESSION['user_id'] ?? 'not set',
                    'session_logged_in' => $_SESSION['logged_in'] ?? 'not set',
                    'session_id' => session_id()
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التأكد من وجود اتصال بقاعدة البيانات
        if (!isset($db)) {
            $db = db();
        }
        
        if (!$db) {
            throw new Exception('فشل الاتصال بقاعدة البيانات');
        }
        
        $credentials = $db->query(
            "SELECT id, credential_id, device_name, created_at, last_used 
             FROM webauthn_credentials 
             WHERE user_id = ? 
             ORDER BY created_at DESC",
            [$userId]
        );
        
        // التأكد من أن $credentials هو array
        if (!is_array($credentials)) {
            $credentials = [];
        }
        
        echo json_encode([
            'success' => true,
            'credentials' => $credentials
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("WebAuthn Credentials List Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'خطأ في تحميل البصمات: ' . $e->getMessage(),
            'debug' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    // حذف اعتماد محدد
    // $userId تم تعيينه بالفعل في التحقق الأولي أعلاه
    if (!$userId || empty($userId)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'غير مصرح به - تعذر تحميل بيانات المستخدم'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $credentialId = $_POST['credential_id'] ?? '';
    
    if (empty($credentialId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'معرّف الاعتماد مطلوب']);
        exit;
    }
    
    $db = db();
    
    // التحقق من أن الاعتماد يخص المستخدم الحالي
    $credential = $db->queryOne(
        "SELECT id FROM webauthn_credentials WHERE id = ? AND user_id = ?",
        [$credentialId, $userId]
    );
    
    if (!$credential) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'الاعتماد غير موجود أو غير مسموح']);
        exit;
    }
    
    // حذف الاعتماد
    $db->execute(
        "DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?",
        [$credentialId, $userId]
    );
    
    // التحقق من وجود اعتماديات أخرى للمستخدم
    $remainingCount = $db->queryOne(
        "SELECT COUNT(*) as count FROM webauthn_credentials WHERE user_id = ?",
        [$userId]
    );
    
    // إذا لم يبق أي اعتماد، تحديث حالة المستخدم
    if ($remainingCount['count'] == 0) {
        $db->execute(
            "UPDATE users SET webauthn_enabled = 0, updated_at = NOW() WHERE id = ?",
            [$userId]
        );
    }
    
    // تسجيل في سجل التدقيق
    logAudit($userId, 'delete_webauthn_credential', 'webauthn_credentials', $credentialId, null, null);
    
    echo json_encode([
        'success' => true,
        'message' => 'تم حذف البصمة بنجاح',
        'remaining_count' => $remainingCount['count']
    ]);
    
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'إجراء غير صحيح']);
}


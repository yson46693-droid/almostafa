<?php
/**
 * API تسجيل WebAuthn - نظام جديد مبسط
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/webauthn.php';

header('Content-Type: application/json; charset=utf-8');
// CORS headers لضمان إرسال credentials بشكل صحيح
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'يجب استخدام طريقة POST']);
    exit;
}

// التحقق من تسجيل الدخول مع معالجة أفضل للأخطاء
$loginCheck = isLoggedIn();
if (!$loginCheck) {
    // تسجيل مفصل للمساعدة في التشخيص
    $sessionId = session_id();
    $hasSession = session_status() === PHP_SESSION_ACTIVE;
    $hasUserId = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $hasLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    
    error_log("WebAuthn Register 401: isLoggedIn() returned false. Session ID: " . ($sessionId ? substr($sessionId, 0, 20) . "..." : "none") . 
              ", Session Active: " . ($hasSession ? "yes" : "no") . 
              ", Has user_id: " . ($hasUserId ? "yes" : "no") . 
              ", Has logged_in: " . ($hasLoggedIn ? "yes" : "no"));
    
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول',
        'error' => 'Unauthorized'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التأكد من وجود user_id
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    error_log("WebAuthn Register ERROR: isLoggedIn() returned true but user_id is missing");
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول',
        'error' => 'Session invalid'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = $_SESSION['user_id'];
$db = db();

try {
    // قراءة البيانات من JSON
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!$input) {
        $input = $_POST;
    }

    $action = $input['action'] ?? 'challenge';

    if ($action === 'challenge') {
        // === تحديث الجلسة في قاعدة البيانات قبل إنشاء challenge ===
        try {
            if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                $sessionId = session_id();
                if (!empty($sessionId) && !empty($userId)) {
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    
                    // محاولة تحديث الجلسة الموجودة
                    $sessionUpdated = $db->execute(
                        "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                        [$newExpiresAt, $userId, $sessionId]
                    );
                    
                    // إذا لم توجد جلسة، إنشاء واحدة جديدة
                    if (!$sessionUpdated || ($sessionUpdated['affected_rows'] ?? 0) === 0) {
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                        
                        $db->execute(
                            "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                             VALUES (?, ?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE last_activity = NOW(), expires_at = ?",
                            [$userId, $sessionId, $ipAddress, $userAgent, $newExpiresAt, $newExpiresAt]
                        );
                    }
                }
            }
        } catch (Exception $e) {
            // لا نوقف العملية إذا فشل تحديث الجلسة، فقط نسجل الخطأ
            error_log("WebAuthn challenge - Error updating session in database: " . $e->getMessage());
        }
        
        // إنشاء challenge للتسجيل
        $user = getUserById($userId);
        
        if (!$user) {
            throw new Exception('المستخدم غير موجود');
        }

        $challenge = WebAuthn::createRegistrationChallenge($userId, $user['username']);
        
        if (!$challenge) {
            throw new Exception('فشل في إنشاء challenge');
        }

        echo json_encode([
            'success' => true,
            'data' => $challenge
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($action === 'verify') {
        // التحقق من البصمة وحفظها
        $response = $input['response'] ?? null;
        
        if (!$response) {
            throw new Exception('بيانات الاعتماد غير مكتملة');
        }

        // تحويل response من JSON string إلى array إذا لزم الأمر
        if (is_string($response)) {
            $response = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('استجابة غير صحيحة: ' . json_last_error_msg());
            }
        }

        // التحقق من البصمة
        $result = WebAuthn::verifyRegistration(json_encode($response), $userId);
        
        if ($result) {
            // تحديث حالة المستخدم
            $db->execute("UPDATE users SET webauthn_enabled = 1, updated_at = NOW() WHERE id = ?", [$userId]);
            
            // === تحديث الجلسة في قاعدة البيانات لضمان بقائها نشطة ===
            try {
                if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                    $sessionId = session_id();
                    if (!empty($sessionId) && !empty($userId)) {
                        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                        $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                        
                        // محاولة تحديث الجلسة الموجودة
                        $sessionUpdated = $db->execute(
                            "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                            [$newExpiresAt, $userId, $sessionId]
                        );
                        
                        // إذا لم توجد جلسة، إنشاء واحدة جديدة
                        if (!$sessionUpdated || ($sessionUpdated['affected_rows'] ?? 0) === 0) {
                            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                            
                            $db->execute(
                                "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                                 VALUES (?, ?, ?, ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE last_activity = NOW(), expires_at = ?",
                                [$userId, $sessionId, $ipAddress, $userAgent, $newExpiresAt, $newExpiresAt]
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                // لا نوقف العملية إذا فشل تحديث الجلسة، فقط نسجل الخطأ
                error_log("WebAuthn register - Error updating session in database: " . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تسجيل البصمة بنجاح'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('فشل التحقق من البصمة. تحقق من سجلات الخادم.');
        }

    } else {
        throw new Exception('إجراء غير صحيح');
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("WebAuthn register error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

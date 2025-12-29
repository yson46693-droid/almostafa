<?php
/**
 * CSRF Protection (InfinityFree Compatible)
 * حماية CSRF - مدمجة مع النظام الحالي
 * 
 * هذا الملف يحسّن حماية CSRF ويتكامل مع generateCSRFToken() الموجود في auth.php
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * تحسين دالة التحقق من CSRF - يعمل بدون جلسات
 * تستخدم cookies بدلاً من الجلسات
 */
function verifyCSRFTokenEnhanced($token = null, $allowPreviousToken = true) {
    // إذا لم يتم تمرير token، احصل عليه من POST أو GET
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;
    }
    
    // تنظيف token من أي مسافات أو أحرف غير مرئية
    if ($token !== null) {
        $token = trim($token);
    }
    
    // إذا لم يكن هناك token في الطلب، فشل التحقق
    if ($token === null || $token === '') {
        error_log("CSRF: Token missing in request. POST: " . (isset($_POST['csrf_token']) ? 'exists' : 'missing') . ", GET: " . (isset($_GET['csrf_token']) ? 'exists' : 'missing'));
        return false;
    }
    
    // التحقق من وجود token في cookie
    $cookieName = 'csrf_token';
    $currentToken = isset($_COOKIE[$cookieName]) ? trim((string)$_COOKIE[$cookieName]) : '';
    
    // إذا لم يكن هناك token في cookie، فشل التحقق
    if (empty($currentToken)) {
        error_log("CSRF: No token in cookie");
        return false;
    }
    
    // التحقق من token
    if (!empty($currentToken) && hash_equals($currentToken, $token)) {
        return true;
    }
    
    // فشل التحقق
    error_log("CSRF: Token verification failed. Cookie token length: " . strlen($currentToken) . ", Request token length: " . strlen($token));
    
    return false;
}

/**
 * الحصول على CSRF Token - يعمل بدون جلسات
 */
function getCSRFToken() {
    // استخدام الدالة الموجودة في auth.php إذا كانت متاحة
    if (function_exists('generateCSRFToken')) {
        return generateCSRFToken();
    }
    
    // Fallback: إنشاء token جديد
    $cookieName = 'csrf_token';
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    
    $token = bin2hex(random_bytes(32));
    
    // التحقق من أن headers لم يتم إرسالها بعد
    if (!headers_sent()) {
        setcookie($cookieName, $token, [
            'expires' => time() + 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    
    $_COOKIE[$cookieName] = $token;
    
    return $token;
}

/**
 * إنشاء حقل CSRF Token للنماذج
 */
function csrf_token_field() {
    $token = getCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * حماية النموذج من CSRF - متوافقة مع النظام الحالي
 * محسّنة لدعم تسجيل الدخول وإعادة توليد الجلسة
 */
function protectFormFromCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isLoginRequest = (
            basename($uri) === 'index.php' || 
            basename($_SERVER['PHP_SELF'] ?? '') === 'index.php' ||
            (isset($_POST['username']) && isset($_POST['password']))
        );
        
        // === تعطيل التحقق من CSRF تماماً لطلبات تسجيل الدخول ===
        // لأن session_regenerate_id() يغير session_id وبالتالي يفقد CSRF token
        if ($isLoginRequest) {
            return true; // السماح بطلبات تسجيل الدخول بدون التحقق من CSRF
        }
        
        // استثناءات (APIs و WebAuthn)
        if (strpos($uri, '/api/') !== false || 
            strpos($uri, '/webauthn/') !== false ||
            (isset($_POST['login_method']) && $_POST['login_method'] === 'webauthn')) {
            return true;
        }
        
        // تم إزالة نظام الجلسات - التحقق من وجود CSRF token في cookie
        $cookieName = 'csrf_token';
        if (!isset($_COOKIE[$cookieName]) || empty($_COOKIE[$cookieName])) {
            // إنشاء token جديد إذا لم يكن موجوداً
            if (function_exists('generateCSRFToken')) {
                generateCSRFToken(true);
            } else {
                getCSRFToken();
            }
            error_log("CSRF: Generated new token in cookie");
        }
        
        // التحقق من CSRF Token
        // للطلبات الخاصة بتسجيل الدخول، نسمح باستخدام token السابق (لإعادة توليد الجلسة)
        $allowPreviousToken = $isLoginRequest;
        $isValid = verifyCSRFTokenEnhanced(null, $allowPreviousToken);
        
        if (!$isValid) {
            // تسجيل معلومات إضافية للمساعدة في التشخيص
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $hasTokenInPost = isset($_POST['csrf_token']) && !empty($_POST['csrf_token']);
            $hasTokenInCookie = isset($_COOKIE['csrf_token']) && !empty($_COOKIE['csrf_token']);
            
            $logMessage = sprintf(
                "CSRF Validation Failed - IP: %s, UserAgent: %s, HasTokenInPost: %s, HasTokenInCookie: %s, IsLoginRequest: %s",
                $ipAddress,
                $userAgent,
                ($hasTokenInPost ? 'yes' : 'no'),
                ($hasTokenInCookie ? 'yes' : 'no'),
                ($isLoginRequest ? 'yes' : 'no')
            );
            
            error_log($logMessage);
            
            // لطلبات تسجيل الدخول، نسمح بتجاوز التحقق إذا كان هناك token في الطلب
            if ($isLoginRequest && $hasTokenInPost) {
                error_log("CSRF: Allowing login request to proceed despite validation failure");
                return true; // نسمح للمتابعة
            }
            
            // فقط في حالة عدم كونها طلب تسجيل دخول أو في حالة عدم وجود token نهائياً
            if (!$isLoginRequest || (!$hasTokenInPost && !$hasTokenInCookie)) {
                http_response_code(403);
                die('خطأ في التحقق الأمني. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
            }
            
            // لطلبات تسجيل الدخول بدون token، نعيد false للسماح للمعالج الأعلى بالتعامل معها
            return false;
        }
    }
    
    return true;
}

/**
 * دالة مساعدة للحصول على CSRF Token (للاستخدام في JavaScript)
 */
function csrf_token() {
    return getCSRFToken();
}

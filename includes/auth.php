<?php
/**
 * نظام المصادقة والتحقق من الأدوار
 * نظام إدارة الشركات المتكامل
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// بدء الجلسات إذا لم تكن قد بدأت
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    
    session_start();
}

// تحميل path_helper إذا كان متوفراً
if (!function_exists('getRelativeUrl') && file_exists(__DIR__ . '/path_helper.php')) {
    require_once __DIR__ . '/path_helper.php';
}

/**
 * الحصول على الحد الأدنى لطول كلمة المرور من الإعدادات
 *
 * @return int
 */
function getPasswordMinLength(): int
{
    static $cachedValue = null;

    if ($cachedValue !== null) {
        return $cachedValue;
    }

    if (defined('PASSWORD_MIN_LENGTH')) {
        $value = (int) PASSWORD_MIN_LENGTH;
    } else {
        $value = 8;
    }

    if ($value < 1) {
        $value = 1;
    }

    return $cachedValue = $value;
}

/**
 * التحقق من تسجيل الدخول - يعتمد على الجلسات (PHP Sessions)
 */
function isLoggedIn() {
    // التحقق من وجود الجلسة والمستخدم المسجل فيها
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }
    
    // إعلان المتغيرات الثابتة مرة واحدة في بداية الدالة
    static $retryCount = [];
    static $errorCount = [];
    
    // التحقق من أن المستخدم موجود في قاعدة البيانات
    try {
        $db = db();
        $user = $db->queryOne("SELECT id, status FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']]);
        if (!$user) {
            // التحقق إذا كان هذا من background-tasks.php - لا نحذف الجلسة عند أخطاء قاعدة البيانات
            $isBackgroundTask = defined('BACKGROUND_TASKS_ACTIVE') && BACKGROUND_TASKS_ACTIVE === true;
            if ($isBackgroundTask) {
                // في background tasks، لا نحذف الجلسة عند فشل الاستعلام
                // قد يكون السبب timeout في قاعدة البيانات
                error_log("isLoggedIn() - User not found but BACKGROUND_TASKS_ACTIVE - skipping session destroy");
                return false; // نرجع false لكن لا نحذف الجلسة
            }
            
            // المستخدم غير موجود أو غير مفعّل - حذف الجلسة فقط بعد التحقق المتكرر
            // إضافة آلية retry لمنع حذف الجلسة عند أخطاء مؤقتة
            $sessionId = session_id();
            if (!isset($retryCount[$sessionId])) {
                $retryCount[$sessionId] = 0;
            }
            
            // إذا فشل التحقق أقل من 3 مرات، نعتبره خطأ مؤقت ونحتفظ بالجلسة
            if ($retryCount[$sessionId] < 3) {
                $retryCount[$sessionId]++;
                // نرجع true مؤقتاً على افتراض أن الخطأ مؤقت
                return true;
            }
            
            // بعد 3 محاولات فاشلة، نحذف الجلسة
            unset($retryCount[$sessionId]);
            session_destroy();
            return false;
        }
        
        // نجح التحقق - إعادة تعيين عداد المحاولات
        $sessionId = session_id();
        if (isset($retryCount[$sessionId])) {
            unset($retryCount[$sessionId]);
        }
        
        return true;
    } catch (Exception $e) {
        // في حالة خطأ في قاعدة البيانات، لا نحذف الجلسة فوراً
        $isBackgroundTask = defined('BACKGROUND_TASKS_ACTIVE') && BACKGROUND_TASKS_ACTIVE === true;
        if ($isBackgroundTask) {
            error_log("isLoggedIn() - Database error in background tasks: " . $e->getMessage());
            // نعتبر المستخدم مسجل دخول إذا كانت الجلسة موجودة
            return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
        }
        
        // إضافة آلية retry للأخطاء المؤقتة
        $sessionId = session_id();
        if (!isset($errorCount[$sessionId])) {
            $errorCount[$sessionId] = 0;
        }
        
        // التحقق من نوع الخطأ - إذا كان timeout أو connection error، نعتبره مؤقت
        $errorMessage = strtolower($e->getMessage());
        $isTemporaryError = (
            strpos($errorMessage, 'timeout') !== false ||
            strpos($errorMessage, 'connection') !== false ||
            strpos($errorMessage, 'lost connection') !== false ||
            strpos($errorMessage, 'gone away') !== false ||
            strpos($errorMessage, 'server has gone away') !== false
        );
        
        // إذا كان الخطأ مؤقتاً وأقل من 5 مرات، نعتبره خطأ مؤقت
        if ($isTemporaryError && $errorCount[$sessionId] < 5) {
            $errorCount[$sessionId]++;
            error_log("isLoggedIn() - Temporary database error (attempt {$errorCount[$sessionId]}): " . $e->getMessage());
            // نرجع true مؤقتاً على افتراض أن الخطأ مؤقت
            return true;
        }
        
        // إذا كان الخطأ غير مؤقت أو تجاوز 5 محاولات، نعتبر الجلسة منتهية
        if (!$isTemporaryError || $errorCount[$sessionId] >= 5) {
            if ($errorCount[$sessionId] >= 5) {
                unset($errorCount[$sessionId]);
            }
            error_log("isLoggedIn() error (persistent): " . $e->getMessage());
            return false;
        }
        
        return false;
    }
}

/**
 * دالة فارغة للتوافق مع الملفات القديمة
 */
function ensureRememberTokensTable() {
    return false; // تم إزالة نظام remember_token
}

/**
 * دالة فارغة للتوافق مع الملفات القديمة
 */
function ensureSessionsTable() {
    return true; // الجلسات تعمل تلقائياً في PHP
}

/**
 * تنظيف الجلسات المنتهية
 */
function cleanupExpiredSessions($days = 30) {
    // PHP يدير الجلسات تلقائياً - لا حاجة لتنظيف يدوي
    return [
        'success' => true,
        'deleted' => 0,
        'message' => 'الجلسات تُدار تلقائياً من PHP'
    ];
}

/**
 * الحصول على معلومات المستخدم الحالي - يعتمد على الجلسات (PHP Sessions)
 */
function getCurrentUser() {
    // التحقق من وجود الجلسة
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return null;
    }
    
    // إعلان المتغيرات الثابتة مرة واحدة في بداية الدالة
    static $retryCount = [];
    static $errorCount = [];
    
    // جلب بيانات المستخدم من قاعدة البيانات
    try {
        $db = db();
        $user = $db->queryOne("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']]);
        
        if (!$user) {
            // التحقق إذا كان هذا من background-tasks.php - لا نحذف الجلسة عند أخطاء قاعدة البيانات
            $isBackgroundTask = defined('BACKGROUND_TASKS_ACTIVE') && BACKGROUND_TASKS_ACTIVE === true;
            if ($isBackgroundTask) {
                // في background tasks، لا نحذف الجلسة عند فشل الاستعلام
                error_log("getCurrentUser() - User not found but BACKGROUND_TASKS_ACTIVE - skipping session destroy");
                return null; // نرجع null لكن لا نحذف الجلسة
            }
            
            // المستخدم غير موجود أو غير مفعّل - حذف الجلسة فقط بعد التحقق المتكرر
            // إضافة آلية retry لمنع حذف الجلسة عند أخطاء مؤقتة
            $sessionId = session_id();
            if (!isset($retryCount[$sessionId])) {
                $retryCount[$sessionId] = 0;
            }
            
            // إذا فشل التحقق أقل من 3 مرات، نعتبره خطأ مؤقت
            if ($retryCount[$sessionId] < 3) {
                $retryCount[$sessionId]++;
                // نرجع null لكن لا نحذف الجلسة
                return null;
            }
            
            // بعد 3 محاولات فاشلة، نحذف الجلسة
            unset($retryCount[$sessionId]);
            session_destroy();
            return null;
        }
        
        // نجح التحقق - إعادة تعيين عداد المحاولات
        $sessionId = session_id();
        if (isset($retryCount[$sessionId])) {
            unset($retryCount[$sessionId]);
        }
        
        return $user;
    } catch (Exception $e) {
        // في حالة خطأ في قاعدة البيانات، لا نحذف الجلسة فوراً
        $isBackgroundTask = defined('BACKGROUND_TASKS_ACTIVE') && BACKGROUND_TASKS_ACTIVE === true;
        if ($isBackgroundTask) {
            error_log("getCurrentUser() - Database error in background tasks: " . $e->getMessage());
            // نحاول جلب المستخدم مباشرة بدون شرط status
            try {
                $db = db();
                $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
                return $user; // نرجع المستخدم حتى لو كان غير active مؤقتاً
            } catch (Exception $retryError) {
                error_log("getCurrentUser() - Retry failed: " . $retryError->getMessage());
                return null;
            }
        }
        
        // إضافة آلية retry للأخطاء المؤقتة
        $sessionId = session_id();
        if (!isset($errorCount[$sessionId])) {
            $errorCount[$sessionId] = 0;
        }
        
        // التحقق من نوع الخطأ - إذا كان timeout أو connection error، نعتبره مؤقت
        $errorMessage = strtolower($e->getMessage());
        $isTemporaryError = (
            strpos($errorMessage, 'timeout') !== false ||
            strpos($errorMessage, 'connection') !== false ||
            strpos($errorMessage, 'lost connection') !== false ||
            strpos($errorMessage, 'gone away') !== false ||
            strpos($errorMessage, 'server has gone away') !== false
        );
        
        // إذا كان الخطأ مؤقتاً وأقل من 5 مرات، نعتبره خطأ مؤقت
        if ($isTemporaryError && $errorCount[$sessionId] < 5) {
            $errorCount[$sessionId]++;
            error_log("getCurrentUser() - Temporary database error (attempt {$errorCount[$sessionId]}): " . $e->getMessage());
            // في حالة الخطأ المؤقت، نرجع بيانات المستخدم من الجلسة بدلاً من null
            // لمنع اعتبار الجلسة منتهية عند الأخطاء المؤقتة
            if (isset($_SESSION['user_id']) && isset($_SESSION['username']) && isset($_SESSION['role'])) {
                return [
                    'id' => $_SESSION['user_id'],
                    'username' => $_SESSION['username'],
                    'role' => $_SESSION['role'],
                    'status' => 'active', // افتراض أن المستخدم نشط
                    '_cached' => true // علامة أن البيانات من الجلسة وليست من قاعدة البيانات
                ];
            }
            return null;
        }
        
        // إذا كان الخطأ غير مؤقت أو تجاوز 5 محاولات، نعتبر الجلسة منتهية
        if (!$isTemporaryError || $errorCount[$sessionId] >= 5) {
            if ($errorCount[$sessionId] >= 5) {
                unset($errorCount[$sessionId]);
            }
            error_log("getCurrentUser() error (persistent): " . $e->getMessage());
            return null;
        }
        
        return null;
    }
}

/**
 * جلب بيانات المستخدم من قاعدة البيانات (دالة مساعدة)
 * @param int $userId معرف المستخدم
 * @return array|null بيانات المستخدم أو null
 */
function getCurrentUserFromDatabase($userId) {
    // استخدام طرق متعددة للتحقق لضمان العمل على جميع الأجهزة (Windows, Android, iOS)
    $isProfilePage = false;
    $isAttendancePage = false;
    $isSalesPage = false;
    $isNotificationsAPI = false;
    $isWebAuthnAPI = false;
    
    // الطريقة 1: التحقق من الثوابت (الأكثر موثوقية)
    if (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) {
        $isProfilePage = true;
    }
    if (defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true) {
        $isAttendancePage = true;
    }
    if (defined('SALES_PAGE_ACTIVE') && SALES_PAGE_ACTIVE === true) {
        $isSalesPage = true;
    }
    if (defined('NOTIFICATIONS_API_ACTIVE') && NOTIFICATIONS_API_ACTIVE === true) {
        $isNotificationsAPI = true;
    }
    if (defined('WEBAUTHN_API_ACTIVE') && WEBAUTHN_API_ACTIVE === true) {
        $isWebAuthnAPI = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        } elseif (strpos($currentScript, 'sales.php') !== false || basename($currentScript) === 'sales.php' || strpos($currentScript, 'dashboard/sales.php') !== false) {
            $isSalesPage = true;
        } elseif (strpos($currentScript, 'notifications.php') !== false || basename($currentScript) === 'notifications.php') {
            $isNotificationsAPI = true;
        } elseif (strpos($currentScript, 'webauthn_credentials.php') !== false || basename($currentScript) === 'webauthn_credentials.php') {
            $isWebAuthnAPI = true;
        }
    }
    
    // الطريقة 3: استخدام debug_backtrace كبديل
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($backtrace as $trace) {
            if (isset($trace['file'])) {
                $fileName = basename($trace['file']);
                if ($fileName === 'profile.php' || strpos($trace['file'], 'profile.php') !== false) {
                    $isProfilePage = true;
                    break;
                } elseif ($fileName === 'attendance.php' || strpos($trace['file'], 'attendance.php') !== false) {
                    $isAttendancePage = true;
                    break;
                } elseif ($fileName === 'sales.php' || strpos($trace['file'], 'sales.php') !== false || strpos($trace['file'], 'dashboard/sales.php') !== false) {
                    $isSalesPage = true;
                    break;
                } elseif ($fileName === 'notifications.php' || strpos($trace['file'], 'notifications.php') !== false) {
                    $isNotificationsAPI = true;
                    break;
                } elseif ($fileName === 'webauthn_credentials.php' || strpos($trace['file'], 'webauthn_credentials.php') !== false) {
                    $isWebAuthnAPI = true;
                    break;
                }
            }
        }
    }
    
    // الطريقة 4: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        } elseif (strpos($requestUri, 'sales.php') !== false || strpos($requestUri, 'dashboard/sales') !== false || strpos($requestUri, 'modules/sales') !== false) {
            $isSalesPage = true;
        } elseif (strpos($requestUri, 'notifications.php') !== false || strpos($requestUri, '/api/notifications') !== false) {
            $isNotificationsAPI = true;
        } elseif (strpos($requestUri, 'webauthn_credentials.php') !== false || strpos($requestUri, '/api/webauthn_credentials') !== false) {
            $isWebAuthnAPI = true;
        }
    }
    
    // الطريقة 5: التحقق من الدور من الجلسة - حماية شاملة للمندوبين
    $isSalesUser = false;
    $currentUser = getCurrentUser();
    if ($currentUser && isset($currentUser['role']) && strtolower($currentUser['role']) === 'sales') {
        $isSalesUser = true;
    }
    
    // تحديد إذا كان الصفحة محمية (profile أو attendance أو sales أو notifications API أو webauthn API أو مستخدم مندوب)
    $isProtectedPage = $isProfilePage || $isAttendancePage || $isSalesPage || $isNotificationsAPI || $isWebAuthnAPI || $isSalesUser;
    
    // جلب جميع بيانات المستخدم من قاعدة البيانات
    try {
        $db = db();
        $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
    } catch (Exception $e) {
        // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
        // error_log("getCurrentUserFromDatabase - Database error for user ID {$userId}: " . $e->getMessage());
        // في profile.php، لا نحذف remember_token حتى لو كان هناك خطأ في قاعدة البيانات
        if ($isProfilePage) {
            return null; // نرجع null لكن لا نحذف remember_token
        }
        return null;
    }
    
    // إذا كان المستخدم غير موجود أو محذوف من قاعدة البيانات
    if (!$user) {
        // منع حذف الجلسة إذا كان الطلب من profile.php أو attendance.php أو sales.php أو إذا كان المستخدم مندوب
        if ($isProtectedPage) {
            $pageName = $isProfilePage ? 'profile.php' : ($isAttendancePage ? 'attendance.php' : ($isSalesPage ? 'sales.php' : ($isSalesUser ? 'sales user' : 'notifications.php')));
            error_log("Security: User ID {$userId} not found in database - Skipping session deletion in {$pageName}");
            return null;
        }
        
        // إلغاء تسجيل الدخول تلقائياً لأسباب أمنية
        error_log("Security: User ID {$userId} not found in database - Auto logout");
        session_destroy();
        return null;
    }
    
    // التحقق من حالة المستخدم - إذا كان غير مفعّل
    if (isset($user['status']) && $user['status'] !== 'active') {
        // منع حذف الجلسة إذا كان الطلب من profile.php أو attendance.php أو sales.php أو إذا كان المستخدم مندوب
        if ($isProtectedPage) {
            $pageName = $isProfilePage ? 'profile.php' : ($isAttendancePage ? 'attendance.php' : ($isSalesPage ? 'sales.php' : ($isSalesUser ? 'sales user' : 'notifications.php')));
            error_log("Security: User ID {$userId} status is '{$user['status']}' - Skipping session deletion in {$pageName}");
            return $user; // إرجاع بيانات المستخدم حتى لو كان غير مفعّل
        }
        
        // إلغاء تسجيل الدخول تلقائياً لأسباب أمنية
        error_log("Security: User ID {$userId} status is '{$user['status']}' - Auto logout");
        session_destroy();
        return null;
    }
    
    // تنظيف جميع القيم المالية
    $financialFields = ['hourly_rate', 'salary', 'basic_salary', 'bonus', 'deductions', 'total_amount'];
    foreach ($financialFields as $field) {
        if (isset($user[$field])) {
            $user[$field] = cleanFinancialValue($user[$field]);
        }
    }
    
    return $user;
}

/**
 * الحصول على معلومات المستخدم حسب ID
 */
function getUserById($userId) {
    $db = db();
    return $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
}

/**
 * الحصول على معلومات المستخدم حسب اسم المستخدم
 */
function getUserByUsername($username) {
    try {
        // محاولة الحصول على اتصال قاعدة البيانات
        $db = null;
        try {
            $db = db();
        } catch (Throwable $dbError) {
            error_log("getUserByUsername: Failed to get database connection - " . $dbError->getMessage());
            throw new Exception("Database connection failed: " . $dbError->getMessage());
        }
        
        // التحقق من أن الاتصال موجود
        if (!$db) {
            error_log("getUserByUsername: Database connection is null");
            throw new Exception("Database connection is null");
        }
        
        // التحقق من أن الاتصال نشط
        $connection = $db->getConnection();
        if (!$connection) {
            error_log("getUserByUsername: Database connection object is null");
            throw new Exception("Database connection object is null");
        }
        
        // التحقق من وجود جدول users (مع معالجة الأخطاء)
        $tableExists = false;
        try {
            $tableCheck = $db->rawQuery("SHOW TABLES LIKE 'users'");
            if ($tableCheck instanceof mysqli_result) {
                $tableExists = $tableCheck->num_rows > 0;
                $tableCheck->free();
            } elseif ($tableCheck) {
                $tableExists = true;
            }
        } catch (Throwable $tableError) {
            error_log("getUserByUsername: Table check error - " . $tableError->getMessage());
            // نستمر في المحاولة حتى لو فشل فحص الجدول
            $tableExists = true; // نفترض أن الجدول موجود
        }
        
        if (!$tableExists) {
            error_log("getUserByUsername: Table 'users' does not exist");
            throw new Exception("Table 'users' does not exist. Please run database installation.");
        }
        
        // تنفيذ الاستعلام
        try {
            $user = $db->queryOne(
                "SELECT * FROM users WHERE username = ?",
                [$username]
            );
            return $user;
        } catch (mysqli_sql_exception $sqlError) {
            error_log("getUserByUsername SQL error: " . $sqlError->getMessage());
            error_log("getUserByUsername SQL error code: " . $sqlError->getCode());
            throw new Exception("Database query failed: " . $sqlError->getMessage() . " (Error code: " . $sqlError->getCode() . ")");
        } catch (Exception $queryError) {
            error_log("getUserByUsername query error: " . $queryError->getMessage());
            throw $queryError;
        }
        
    } catch (Exception $e) {
        $errorDetails = "getUserByUsername error: " . $e->getMessage();
        $errorDetails .= " | File: " . $e->getFile() . " | Line: " . $e->getLine();
        error_log($errorDetails);
        error_log("getUserByUsername stack trace: " . $e->getTraceAsString());
        throw $e; // إعادة رمي الاستثناء للتعامل معه في دالة login()
    } catch (Throwable $e) {
        $errorDetails = "getUserByUsername fatal error: " . $e->getMessage();
        $errorDetails .= " | File: " . $e->getFile() . " | Line: " . $e->getLine();
        error_log($errorDetails);
        error_log("getUserByUsername stack trace: " . $e->getTraceAsString());
        throw $e; // إعادة رمي الاستثناء للتعامل معه في دالة login()
    }
}

/**
 * التحقق من كلمة المرور
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * التحقق من حالة وضع الصيانة
 */
function isMaintenanceMode() {
    // قراءة من constant في config.php
    return defined('MAINTENANCE_MODE') && MAINTENANCE_MODE === true;
}

/**
 * التحقق إذا كان المستخدم الحالي مطور
 */
function isDeveloper() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        return false;
    }
    
    return isset($currentUser['role']) && strtolower($currentUser['role']) === 'developer';
}

/**
 * التحقق من وضع الصيانة ورفض الوصول للمستخدمين العاديين
 * المطورون يستطيعون الوصول دائماً حتى في وضع الصيانة
 */
function checkMaintenanceMode() {
    // إذا كان وضع الصيانة معطلاً، السماح بالوصول
    if (!isMaintenanceMode()) {
        return ['allowed' => true];
    }
    
    // إذا كان المستخدم مطوراً، السماح بالوصول دائماً
    if (isDeveloper()) {
        return ['allowed' => true];
    }
    
    // في حالة وضع الصيانة والمستخدم ليس مطوراً، رفض الوصول
    return [
        'allowed' => false,
        'message' => 'التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق'
    ];
}

/**
 * تسجيل الدخول
 */
/**
 * تسجيل الدخول - يعتمد على remember_token فقط بدون جلسات
 * @param string $username اسم المستخدم
 * @param string $password كلمة المرور
 * @param bool $rememberMe تفعيل "تذكرني"
 * @return array نتيجة تسجيل الدخول
 */
function login($username, $password, $rememberMe = true) {
    try {
        // التحقق من حظر IP
        if (file_exists(__DIR__ . '/security.php')) {
            require_once __DIR__ . '/security.php';
        }
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // التحقق من حظر IP (مع معالجة الأخطاء)
        try {
            if (function_exists('isIPBlocked') && isIPBlocked($ipAddress)) {
                if (function_exists('logLoginAttempt')) {
                    logLoginAttempt($username, false, 'IP محظور');
                }
                return ['success' => false, 'message' => 'عنوان IP محظور. يرجى الاتصال بالإدارة.'];
            }
        } catch (Throwable $e) {
            error_log("Login IP check error: " . $e->getMessage());
            // استمر في العملية حتى لو فشل فحص IP
        }
        
        // الحصول على معلومات المستخدم
        try {
            $user = getUserByUsername($username);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            $errorDetails = "Login getUserByUsername error: " . $errorMessage;
            $errorDetails .= " | File: " . $e->getFile() . " | Line: " . $e->getLine();
            
            error_log($errorDetails);
            error_log("Login getUserByUsername stack trace: " . $e->getTraceAsString());
            
            // إرجاع رسالة خطأ أكثر تفصيلاً في وضع التطوير
            $isDevelopment = (defined('DEBUG_MODE') && DEBUG_MODE) || 
                             (isset($_SERVER['SERVER_NAME']) && 
                              (strpos($_SERVER['SERVER_NAME'], 'localhost') !== false || 
                               strpos($_SERVER['SERVER_NAME'], '127.0.0.1') !== false));
            
            $userMessage = 'حدث خطأ في الاتصال بقاعدة البيانات. يرجى المحاولة مرة أخرى.';
            if ($isDevelopment) {
                $userMessage .= ' (تفاصيل: ' . htmlspecialchars($errorMessage) . ')';
            }
            
            return ['success' => false, 'message' => $userMessage, 'error_details' => $isDevelopment ? $errorDetails : null];
        }
        
        if (!$user) {
            try {
                if (function_exists('logLoginAttempt')) {
                    logLoginAttempt($username, false, 'مستخدم غير موجود');
                }
            } catch (Throwable $e) {
                error_log("Login attempt log error: " . $e->getMessage());
            }
            return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
        }
        
        if ($user['status'] !== 'active') {
            try {
                if (function_exists('logLoginAttempt')) {
                    logLoginAttempt($username, false, 'حساب غير مفعّل');
                }
            } catch (Throwable $e) {
                error_log("Login attempt log error: " . $e->getMessage());
            }
            return ['success' => false, 'message' => 'الحساب غير مفعّل'];
        }
        
        if (!verifyPassword($password, $user['password_hash'])) {
            try {
                if (function_exists('logLoginAttempt')) {
                    logLoginAttempt($username, false, 'كلمة مرور خاطئة');
                }
            } catch (Throwable $e) {
                error_log("Login attempt log error: " . $e->getMessage());
            }
            return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
        }
        
        // التحقق من وضع الصيانة قبل السماح بتسجيل الدخول
        // المطورون يستطيعون تسجيل الدخول دائماً حتى في وضع الصيانة
        try {
            if (isMaintenanceMode() && strtolower($user['role']) !== 'developer') {
                if (function_exists('logLoginAttempt')) {
                    logLoginAttempt($username, false, 'وضع الصيانة مفعّل');
                }
                return ['success' => false, 'message' => 'التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق'];
            }
        } catch (Throwable $e) {
            error_log("Maintenance mode check error: " . $e->getMessage());
            // استمر في العملية حتى لو فشل فحص وضع الصيانة
        }
        
        // === إنشاء الجلسة ===
        // التأكد من بدء الجلسة
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // تنظيف أي جلسة قديمة
        if (session_status() === PHP_SESSION_ACTIVE) {
            try {
                session_regenerate_id(true);
            } catch (Throwable $e) {
                error_log("Session regenerate error: " . $e->getMessage());
                // استمر في العملية حتى لو فشل تجديد الجلسة
            }
        }
        
        // حفظ بيانات المستخدم في الجلسة
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();
        
        // إذا كان rememberMe مفعّل، نمدد مدة الجلسة
        // ملاحظة: لا يمكن تغيير session settings بعد بدء الجلسة
        // سيتم استخدام القيم الافتراضية أو القيم المعينة قبل بدء الجلسة
        // if ($rememberMe) {
        //     ini_set('session.gc_maxlifetime', 2592000); // 30 يوم
        // } else {
        //     ini_set('session.gc_maxlifetime', 86400); // يوم واحد
        // }
        
        // تسجيل محاولة ناجحة (مع معالجة الأخطاء)
        try {
            if (function_exists('logLoginAttempt')) {
                logLoginAttempt($username, true);
            }
        } catch (Throwable $e) {
            error_log("Login attempt log error: " . $e->getMessage());
            // لا نوقف العملية إذا فشل تسجيل محاولة الدخول
        }
        
        // تسجيل سجل التدقيق (مع معالجة الأخطاء)
        try {
            if (file_exists(__DIR__ . '/audit_log.php')) {
                require_once __DIR__ . '/audit_log.php';
                if (function_exists('logAudit')) {
                    logAudit($user['id'], 'login', 'user', $user['id'], null, [
                        'method' => 'password',
                        'remember_me' => $rememberMe ? 'yes' : 'no'
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log("Audit log error: " . $e->getMessage());
            // لا نوقف العملية إذا فشل تسجيل سجل التدقيق
        }
        
        return ['success' => true, 'user' => $user];
        
    } catch (Throwable $e) {
        // تسجيل الخطأ في error_log
        error_log("Login function fatal error: " . $e->getMessage());
        error_log("Login function error file: " . $e->getFile() . " line: " . $e->getLine());
        error_log("Login function stack trace: " . $e->getTraceAsString());
        
        // إرجاع رسالة خطأ آمنة للمستخدم
        return ['success' => false, 'message' => 'حدث خطأ أثناء تسجيل الدخول. يرجى المحاولة مرة أخرى أو الاتصال بالدعم الفني.'];
    }
}

/**
 * تنظيف Cache للمستخدم
 * 
 * @param int|null $userId معرف المستخدم (null لحذف Cache المستخدم الحالي من الجلسة)
 */
function clearUserCache($userId = null) {
    if ($userId === null) {
        // الحصول على user_id من الجلسة
        $userId = $_SESSION['user_id'] ?? null;
    }
    
    if ($userId) {
        // تحميل نظام Cache
        if (!class_exists('Cache')) {
            $cacheFile = __DIR__ . '/cache.php';
            if (file_exists($cacheFile)) {
                require_once $cacheFile;
            }
        }
        
        if (class_exists('Cache')) {
            Cache::forget("user_{$userId}");
        }
    }
}

/**
 * تسجيل الخروج
 */
/**
 * تسجيل الخروج - يعتمد على الجلسات (PHP Sessions)
 */
function logout() {
    // التأكد من بدء الجلسة قبل محاولة الوصول إليها
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // الحصول على user_id من الجلسة قبل حذفها
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    
    // تنظيف Cache قبل تسجيل الخروج
    if ($userId && function_exists('clearUserCache')) {
        try {
            clearUserCache($userId);
        } catch (Exception $e) {
            error_log("Logout Cache Clear Error: " . $e->getMessage());
        }
    }
    
    // تسجيل سجل التدقيق قبل حذف الجلسة
    if ($userId) {
        try {
            require_once __DIR__ . '/audit_log.php';
            if (function_exists('logAudit')) {
                logAudit($userId, 'logout', 'user', $userId, null, null);
            }
        } catch (Exception $e) {
            error_log("Logout Audit Log Error: " . $e->getMessage());
        }
    }
    
    // حذف جميع بيانات الجلسة
    $_SESSION = [];
    
    // الحصول على اسم الجلسة
    $sessionName = session_name();
    
    // حذف cookie الجلسة من المتصفح
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );
    
    // حذف cookie الجلسة بطرق متعددة لضمان الحذف
    if (isset($_COOKIE[$sessionName])) {
        // حذف من جميع المسارات الممكنة
        setcookie($sessionName, '', time() - 3600, '/', '', $isHttps, true);
        setcookie($sessionName, '', time() - 3600, '/', null, false, true);
        setcookie($sessionName, '', time() - 3600);
    }
    
    // تنظيف $_COOKIE array
    if (isset($_COOKIE[$sessionName])) {
        unset($_COOKIE[$sessionName]);
    }
    
    // تدمير الجلسة
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    
    // إعادة تهيئة الجلسة الفارغة لمنع إعادة استخدام الجلسة القديمة
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        $_SESSION = [];
        session_destroy();
    }
}

/**
 * التحقق من الدور - يعتمد على remember_token فقط
 */
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }

    if (is_array($role)) {
        return hasAnyRole($role);
    }

    if (!is_string($role) || $role === '') {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = $user['role'];
    if (!is_string($currentRole) || $currentRole === '') {
        return false;
    }
    
    return strtolower($currentRole) === strtolower($role);
}

/**
 * التحقق إذا كان المستخدم مطور أو مدير (لديهم صلاحيات كاملة) - يعتمد على remember_token فقط
 */
function isAdminOrDeveloper() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = strtolower($user['role']);
    return in_array($currentRole, ['manager', 'developer'], true);
}

/**
 * التحقق إذا كان المستخدم يمكنه التعديل - يعتمد على remember_token فقط
 */
function canEdit($allowedRoles = ['manager', 'developer']) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = strtolower($user['role']);
    
    // المطور دائماً يمكنه التعديل
    if ($currentRole === 'developer') {
        return true;
    }
    
    // التحقق من الأدوار المسموحة
    if (is_array($allowedRoles)) {
        return in_array($currentRole, $allowedRoles, true);
    }
    
    return $currentRole === strtolower($allowedRoles);
}

/**
 * التحقق إذا كان المستخدم يمكنه الحذف - يعتمد على remember_token فقط
 */
function canDelete($allowedRoles = ['manager', 'developer']) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = strtolower($user['role']);
    
    // المطور دائماً يمكنه الحذف
    if ($currentRole === 'developer') {
        return true;
    }
    
    // التحقق من الأدوار المسموحة
    if (is_array($allowedRoles)) {
        return in_array($currentRole, $allowedRoles, true);
    }
    
    return $currentRole === strtolower($allowedRoles);
}

/**
 * التحقق من أي دور من الأدوار المحددة - يعتمد على remember_token فقط
 */
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = $user['role'];
    if ($currentRole === null) {
        return false;
    }
    
    $normalizedRoles = array_map(static function ($role) {
        return strtolower((string) $role);
    }, array_filter((array) $roles, static function ($role) {
        return $role !== null && $role !== '';
    }));
    
    if (empty($normalizedRoles)) {
        return false;
    }
    
    return in_array(strtolower((string) $currentRole), $normalizedRoles, true);
}

/**
 * التحقق من جميع الأدوار المحددة
 */
function hasAllRoles($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    foreach ($roles as $role) {
        if (!hasRole($role)) {
            return false;
        }
    }
    
    return true;
}

/**
 * التحقق من الوصول - إعادة توجيه إذا لم يكن مسجلاً
 */
function requireLogin() {
    // التحقق من أننا في profile.php أو attendance.php أو sales.php أو APIs - منع إعادة التوجيه
    $isProfilePage = false;
    $isAttendancePage = false;
    $isSalesPage = false;
    $isNotificationsAPI = false;
    $isWebAuthnAPI = false;
    
    // الطريقة 1: التحقق من الثوابت (الأكثر موثوقية)
    if (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) {
        $isProfilePage = true;
    }
    if (defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true) {
        $isAttendancePage = true;
    }
    if (defined('SALES_PAGE_ACTIVE') && SALES_PAGE_ACTIVE === true) {
        $isSalesPage = true;
    }
    if (defined('NOTIFICATIONS_API_ACTIVE') && NOTIFICATIONS_API_ACTIVE === true) {
        $isNotificationsAPI = true;
    }
    if (defined('WEBAUTHN_API_ACTIVE') && WEBAUTHN_API_ACTIVE === true) {
        $isWebAuthnAPI = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        } elseif (strpos($currentScript, 'sales.php') !== false || basename($currentScript) === 'sales.php' || strpos($currentScript, 'dashboard/sales.php') !== false || strpos($currentScript, 'modules/sales') !== false) {
            $isSalesPage = true;
        } elseif (strpos($currentScript, 'notifications.php') !== false || basename($currentScript) === 'notifications.php') {
            $isNotificationsAPI = true;
        } elseif (strpos($currentScript, 'webauthn_credentials.php') !== false || basename($currentScript) === 'webauthn_credentials.php') {
            $isWebAuthnAPI = true;
        }
    }
    
    // الطريقة 3: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        } elseif (strpos($requestUri, 'sales.php') !== false || strpos($requestUri, 'dashboard/sales') !== false || strpos($requestUri, 'modules/sales') !== false) {
            $isSalesPage = true;
        } elseif (strpos($requestUri, 'notifications.php') !== false || strpos($requestUri, '/api/notifications') !== false) {
            $isNotificationsAPI = true;
        } elseif (strpos($requestUri, 'webauthn_credentials.php') !== false || strpos($requestUri, '/api/webauthn_credentials') !== false) {
            $isWebAuthnAPI = true;
        }
    }
    
    // الطريقة 4: التحقق من الدور من الجلسة - حماية شاملة للمندوبين
    $isSalesUser = false;
    $user = getCurrentUser();
    if ($user && isset($user['role']) && strtolower($user['role']) === 'sales') {
        $isSalesUser = true;
    }
    
    $isProtectedPage = $isProfilePage || $isAttendancePage || $isSalesPage || $isNotificationsAPI || $isWebAuthnAPI || $isSalesUser;
    
    // التحقق من تسجيل الدخول - يعتمد على الجلسات
    try {
        $loginCheckResult = isLoggedIn();
    } catch (Throwable $e) {
        error_log("requireLogin() ERROR: Failed to check login status: " . $e->getMessage());
        $loginCheckResult = false;
    }
    
    if ($loginCheckResult) {
        // التحقق من وضع الصيانة بعد التحقق من تسجيل الدخول
        $maintenanceCheck = checkMaintenanceMode();
        if (!$maintenanceCheck['allowed']) {
            // تم إزالة نظام الجلسات - يمكن استخدام cookies أو JavaScript للتعامل مع وضع الصيانة
            // السماح للصفحة بالتحميل - سيتم التعامل مع وضع الصيانة في JavaScript
        }
        
        // المستخدم مسجل دخول - المتابعة (سواء كان في وضع الصيانة أم لا - سيتم التعامل معه في JavaScript)
        if (!function_exists('logRequestUsage')) {
            $monitorPath = __DIR__ . '/request_monitor.php';
            if (file_exists($monitorPath)) {
                require_once $monitorPath;
            }
        }
        if (function_exists('logRequestUsage')) {
            logRequestUsage();
        }
        return;
    }

    // === المستخدم غير مسجل دخول - الجلسة غير موجودة في قاعدة البيانات ===
    // تسجيل محاولة الوصول غير المصرح به
    error_log("requireLogin() FAILED: User attempted to access protected page without valid session | Script: " . ($_SERVER['SCRIPT_NAME'] ?? 'unknown') . " | IsProtectedPage: " . ($isProtectedPage ? 'true' : 'false'));
    
    // في الصفحات المحمية (API endpoints)، نعيد JSON response بدلاً من حذف الجلسة
    if ($isProtectedPage && ($isNotificationsAPI || $isWebAuthnAPI)) {
        // API endpoint - إرجاع JSON response
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // تم إزالة نظام الجلسات - لا حاجة لحذف الجلسات

    // المستخدم غير مسجل دخول - إعادة التوجيه (إلا إذا كنا في صفحة محمية)
    if (!$isProtectedPage) {
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getRelativeUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        
        // تنظيف URL من معاملات _nocache و _refresh وغيرها
        $loginUrl = preg_replace('/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/', '', $loginUrl);
        $loginUrl = rtrim($loginUrl, '?&');
        
        // تم إزالة نظام الجلسات - لا حاجة لحفظ رسائل في session
        
        // تنظيف output buffer قبل إعادة التوجيه
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        // التأكد من تنظيف URL من أي بروتوكول أو hostname
        $loginUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $loginUrl);
        $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
        if (strpos($loginUrl, '/') !== 0) {
            $loginUrl = '/' . $loginUrl;
        }
        $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            // استخدام replace بدلاً من href لتجنب إضافة URL للتاريخ
            // التأكد من أن loginUrl مسار نسبي فقط لمنع ERR_FAILED
            $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
            echo '<script>';
            echo 'try {';
            echo '  var loginUrl = ' . json_encode($loginUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
            echo '  // تنظيف URL لضمان أنه مسار نسبي فقط';
            echo '  loginUrl = loginUrl.replace(/^https?:\\/\\//i, "");';
            echo '  loginUrl = loginUrl.replace(/^\\/\\/+/, "/");';
            echo '  loginUrl = loginUrl.replace(/^[^\\/]+:[0-9]+\\//, "/");';
            echo '  if (!loginUrl.startsWith("/")) loginUrl = "/" + loginUrl;';
            echo '  loginUrl = loginUrl.replace(/\\/+/g, "/");';
            echo '  window.location.replace(loginUrl);';
            echo '} catch(e) {';
            echo '  console.error("Redirect error:", e);';
            echo '  window.location.href = "/index.php";';
            echo '}';
            echo '</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
            echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $loginUrl, true, 303);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
        echo '<script>';
        echo 'try {';
        echo '  var loginUrl = ' . json_encode($loginUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        echo '  // تنظيف URL لضمان أنه مسار نسبي فقط';
        echo '  loginUrl = loginUrl.replace(/^https?:\\/\\//i, "");';
        echo '  loginUrl = loginUrl.replace(/^\\/\\/+/, "/");';
        echo '  loginUrl = loginUrl.replace(/^[^\\/]+:[0-9]+\\//, "/");';
        echo '  if (!loginUrl.startsWith("/")) loginUrl = "/" + loginUrl;';
        echo '  loginUrl = loginUrl.replace(/\\/+/g, "/");';
        echo '  window.location.replace(loginUrl);';
        echo '} catch(e) {';
        echo '  console.error("Redirect error:", e);';
        echo '  window.location.href = "/index.php";';
        echo '}';
        echo '</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
        exit;
    }
}

/**
 * التحقق من الدور المحدد
 * يدعم string أو array من الأدوار
 */
function requireRole($role) {
    // معالجة الأخطاء: إذا فشل requireLogin()، نعيد التوجيه
    try {
        requireLogin();
    } catch (Throwable $e) {
        // إذا حدث خطأ في requireLogin() (مثلاً فشل الاتصال بقاعدة البيانات)
        // نعيد التوجيه مباشرة إلى تسجيل الدخول
        error_log("requireRole() ERROR: Failed in requireLogin(): " . $e->getMessage());
        
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        $loginUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $loginUrl);
        $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
        if (strpos($loginUrl, '/') !== 0) {
            $loginUrl = '/' . $loginUrl;
        }
        $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
        
        // تم إزالة نظام الجلسات - لا حاجة لحفظ رسائل في session
        
        // تنظيف output buffer
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        if (!@headers_sent()) {
            @header('Location: ' . $loginUrl, true, 303);
            exit;
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
            echo '<script>window.location.replace("' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '");</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
            echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
            exit;
        }
    }
    
    // فحص أمني: التأكد من أن المستخدم موجود في قاعدة البيانات
    try {
        $currentUser = getCurrentUser();
    } catch (Throwable $e) {
        // إذا فشل getCurrentUser()، نعيد التوجيه
        error_log("requireRole() ERROR: Failed to get current user: " . $e->getMessage());
        $currentUser = null;
    }
    
    if (!$currentUser || !is_array($currentUser) || empty($currentUser)) {
        // المستخدم محذوف أو غير موجود - تم إلغاء تسجيل الدخول تلقائياً من getCurrentUser()
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        $loginUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $loginUrl);
        $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
        if (strpos($loginUrl, '/') !== 0) {
            $loginUrl = '/' . $loginUrl;
        }
        $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
        
        // تم إزالة نظام الجلسات - لا حاجة لحفظ رسائل في session
        
        // تنظيف output buffer
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        if (!headers_sent()) {
            header('Location: ' . $loginUrl, true, 303);
            exit;
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
            echo '<script>window.location.replace("' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '");</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
            echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
            exit;
        }
    }
    
    // إذا كان array، استخدم requireAnyRole
    if (is_array($role)) {
        return requireAnyRole($role);
    }
    
        if (!hasRole($role)) {
        $user = getCurrentUser();
        $userRole = $user['role'] ?? 'accountant';
        
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getDashboardUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $dashboardUrl = function_exists('getDashboardUrl') ? getDashboardUrl($userRole) : '/dashboard/' . $userRole . '.php';
        
        // تنظيف شامل للمسار لضمان عدم تكرار الخطأ
        // 1. إزالة أي بروتوكول مع hostname ومنفذ
        $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        
        // 2. إزالة hostname مع منفذ إذا كان موجوداً (مثل localhost:8000)
        if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
            $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
        }
        
        // 3. التأكد من أن المسار يبدأ بـ /
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        
        // 4. تنظيف المسار (إزالة // المكررة)
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // 5. إزالة أي hostname إذا كان موجوداً (بما في ذلك localhost:8000)
        if (preg_match('/^\/[^\/]+(\.[a-z]|:[0-9])/i', $dashboardUrl)) {
            $parts = explode('/', $dashboardUrl);
            $dashboardIndex = array_search('dashboard', $parts);
            if ($dashboardIndex !== false) {
                $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
            } else {
                $dashboardUrl = '/dashboard/' . $userRole . '.php';
            }
        }
        
        // 6. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
        if (strpos($dashboardUrl, '/dashboard') === false) {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // 7. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
        if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
            $parsed = parse_url($dashboardUrl);
            $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
        }
        
        // 8. تنظيف نهائي مع إزالة أي منفذ
        $dashboardUrl = trim($dashboardUrl);
        if (empty($dashboardUrl) || $dashboardUrl === '/') {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $dashboardUrl);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
        exit;
    }
}

/**
 * التحقق من أي دور من الأدوار المحددة
 */
function requireAnyRole($roles) {
    requireLogin();
    
    if (!hasAnyRole($roles)) {
        $user = getCurrentUser();
        $userRole = $user['role'] ?? 'accountant';
        
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getDashboardUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $dashboardUrl = function_exists('getDashboardUrl') ? getDashboardUrl($userRole) : '/dashboard/' . $userRole . '.php';
        
        // تنظيف شامل للمسار لضمان عدم تكرار الخطأ
        // 1. إزالة أي بروتوكول مع hostname ومنفذ
        $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        
        // 2. إزالة hostname مع منفذ إذا كان موجوداً (مثل localhost:8000)
        if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
            $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
        }
        
        // 3. التأكد من أن المسار يبدأ بـ /
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        
        // 4. تنظيف المسار (إزالة // المكررة)
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // 5. إزالة أي hostname إذا كان موجوداً (بما في ذلك localhost:8000)
        if (preg_match('/^\/[^\/]+(\.[a-z]|:[0-9])/i', $dashboardUrl)) {
            $parts = explode('/', $dashboardUrl);
            $dashboardIndex = array_search('dashboard', $parts);
            if ($dashboardIndex !== false) {
                $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
            } else {
                $dashboardUrl = '/dashboard/' . $userRole . '.php';
            }
        }
        
        // 6. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
        if (strpos($dashboardUrl, '/dashboard') === false) {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // 7. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
        if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
            $parsed = parse_url($dashboardUrl);
            $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
        }
        
        // 8. تنظيف نهائي مع إزالة أي منفذ
        $dashboardUrl = trim($dashboardUrl);
        if (empty($dashboardUrl) || $dashboardUrl === '/') {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // تنظيف شامل للمسار: إزالة أي بروتوكول أو hostname أو منفذ لمنع ERR_FAILED
        $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
            $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
        }
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $dashboardUrl);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
        exit;
    }
}

/**
 * إنشاء كلمة مرور مشفرة
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * إنشاء رمز CSRF - يعمل بدون جلسات باستخدام cookies
 */
function generateCSRFToken($forceRefresh = false) {
    $cookieName = 'csrf_token';
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    
    // إذا كان هناك token موجود في cookie ولم نطلب تحديثه، استخدمه
    if (!$forceRefresh && isset($_COOKIE[$cookieName]) && !empty($_COOKIE[$cookieName])) {
        return $_COOKIE[$cookieName];
    }
    
    // إنشاء token جديد
    $token = bin2hex(random_bytes(32));
    
    // حفظ token في cookie (صالح لمدة ساعة)
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
    
    // حفظ في $_COOKIE للطلبات اللاحقة في نفس الطلب
    $_COOKIE[$cookieName] = $token;
    
    return $token;
}

/**
 * التحقق من رمز CSRF - يعمل بدون جلسات
 */
function verifyCSRFToken($token) {
    $cookieName = 'csrf_token';
    
    if (!isset($_COOKIE[$cookieName]) || empty($_COOKIE[$cookieName])) {
        return false;
    }
    
    return hash_equals($_COOKIE[$cookieName], $token);
}


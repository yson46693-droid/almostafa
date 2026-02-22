<?php
/**
 * لوحة التحكم للمحاسب
 */
// التأكد من عدم وجود output قبل DOCTYPE
if (ob_get_level() > 0) {
    ob_clean();
}
ob_start();

define('ACCESS_ALLOWED', true);

// إضافة Permissions-Policy header للسماح بالوصول إلى Geolocation, Camera, Microphone
// ملاحظة: notifications تم إزالته من Feature-Policy لأنه غير مدعوم
// يجب أن يكون في البداية قبل أي output
if (!headers_sent()) {
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=(self)");
    // Feature-Policy كبديل للمتصفحات القديمة (بدون notifications)
    header("Feature-Policy: geolocation 'self'; camera 'self'; microphone 'self'");
}

require_once __DIR__ . '/../includes/config.php';

// التحقق من الجلسة قبل تحميل باقي الملفات (لتجنب الأخطاء في قاعدة البيانات)
try {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    
    // التحقق من الجلسة أولاً
    if (!function_exists('isLoggedIn')) {
        // إذا لم يتم تحميل الدالة، نعتبر أن هناك مشكلة ونعيد التوجيه
        $loginUrl = '/index.php';
        // محاولة بناء URL نسبي
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $pathParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
        $basePath = '';
        foreach ($pathParts as $part) {
            if (in_array($part, ['dashboard', 'modules', 'api', 'assets', 'includes']) || strpos($part, '.php') !== false) {
                break;
            }
            if (!empty($part)) {
                $basePath .= '/' . $part;
            }
        }
        if (!empty($basePath)) {
            $loginUrl = $basePath . '/index.php';
        }
        $loginUrl = preg_replace('/^https?:\/\/[^\/]+/', '', $loginUrl);
        $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
        if (strpos($loginUrl, '/') !== 0) {
            $loginUrl = '/' . $loginUrl;
        }
        
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
} catch (Throwable $e) {
    // إذا حدث خطأ في تحميل الملفات، نعيد التوجيه إلى تسجيل الدخول
    error_log("Accountant dashboard ERROR: Failed to load required files: " . $e->getMessage());
    
    $loginUrl = '/index.php';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $pathParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
    $basePath = '';
    foreach ($pathParts as $part) {
        if (in_array($part, ['dashboard', 'modules', 'api', 'assets', 'includes']) || strpos($part, '.php') !== false) {
            break;
        }
        if (!empty($part)) {
            $basePath .= '/' . $part;
        }
    }
    if (!empty($basePath)) {
        $loginUrl = $basePath . '/index.php';
    }
    $loginUrl = preg_replace('/^https?:\/\/[^\/]+/', '', $loginUrl);
    $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
    if (strpos($loginUrl, '/') !== 0) {
        $loginUrl = '/' . $loginUrl;
    }
    
    // حفظ رسالة الخطأ
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['session_error'] = 'حدث خطأ في النظام. يرجى تسجيل الدخول مرة أخرى.';
        $_SESSION['session_failed'] = true;
        $_SESSION['session_expired'] = true;
    }
    
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

require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/table_styles.php';
require_once __DIR__ . '/../includes/production_reports.php';

// التحقق من الجلسة والأدوار - مع معالجة الأخطاء
try {
    requireRole(['accountant', 'developer']);
} catch (Throwable $e) {
    // إذا حدث خطأ في requireRole، نعيد التوجيه إلى تسجيل الدخول
    error_log("Accountant dashboard ERROR: requireRole failed: " . $e->getMessage());
    
    // حماية من حلقة إعادة التوجيه: التحقق من معامل _redirect في URL
    $hasRedirectParam = isset($_GET['_redirect']) && $_GET['_redirect'] === '1';
    if ($hasRedirectParam) {
        // إذا كان هناك معامل _redirect=1، يعني أننا أعدنا التوجيه للتو من index.php
        // لا نعيد التوجيه مرة أخرى لمنع الحلقة - نتابع تحميل الصفحة
        error_log("Accountant dashboard: _redirect parameter detected, skipping redirect to prevent loop");
        // نتابع تحميل الصفحة بدلاً من إعادة التوجيه
    } else {
        // حماية من حلقة إعادة التوجيه: إذا كان المستخدم قد سجل دخوله للتو (في آخر 30 ثانية)، لا نعيد التوجيه
        $loginTime = $_SESSION['login_time'] ?? 0;
        $timeSinceLogin = time() - $loginTime;
        if ($timeSinceLogin < 30 && isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            // المستخدم قد سجل دخوله للتو - قد يكون هناك تأخير في قاعدة البيانات
            // نترك الصفحة تعمل بدلاً من إعادة التوجيه لتجنب الحلقة
            error_log("Accountant dashboard: User just logged in ({$timeSinceLogin}s ago), skipping redirect to prevent loop");
            // نتابع تحميل الصفحة بدلاً من إعادة التوجيه
        } else {
            // المستخدم لم يسجل دخوله للتو - إعادة التوجيه آمنة
            $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
            $loginUrl = preg_replace('/^https?:\/\/[^\/]+/', '', $loginUrl);
            $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
            if (strpos($loginUrl, '/') !== 0) {
                $loginUrl = '/' . $loginUrl;
            }
            $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
            
            // حفظ رسالة الخطأ
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['session_error'] = 'انتهت الجلسة أو حدث خطأ. يرجى تسجيل الدخول مرة أخرى.';
                $_SESSION['session_failed'] = true;
                $_SESSION['session_expired'] = true;
            }
            
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
    }
}

$currentUser = getCurrentUser();
$db = db();
$page = $_GET['page'] ?? 'dashboard';

// منع تخزين صفحة أوردرات الإنتاج في الكاش لضمان عرض أحدث البيانات من قاعدة البيانات فقط
if ($page === 'production_tasks' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

// معالجة AJAX لتتبع السائقين - عبر نفس الصفحة لضمان مشاركة الجلسة
if ($page === 'driver_tracking' && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'driver_location') {
    $apiPath = __DIR__ . '/../api/driver_location.php';
    if (file_exists($apiPath)) {
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        require_once __DIR__ . '/../includes/path_helper.php';
        include $apiPath;
        exit;
    }
}

// توجيه صفحة الخزنة (financial) إلى خزنة المحاسب (accountant_cash) لتفادي تعطّل الصفحة
if ($page === 'financial' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $redirectUrl = getDashboardUrl() . 'accountant.php?page=accountant_cash';
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl, true, 302);
        exit;
    }
    echo '<script>window.location.replace("' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '");</script><noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

// معالجة POST لصفحة representatives_customers قبل أي شيء
if ($page === 'representatives_customers' && 
    $_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_POST['action']) && 
    $_POST['action'] === 'collect_debt') {
    
    // تسجيل بداية معالجة POST
    error_log('=== POST Collection Request Started (Accountant) ===');
    error_log('POST data: ' . json_encode($_POST));
    
    try {
        // التحقق من تسجيل الدخول
        if (!isLoggedIn()) {
            error_log('User not logged in - redirecting to login');
            header('Location: ' . getRelativeUrl('index.php'));
            exit;
        }
        
        error_log('User is logged in');
        
        // تحميل الملفات الإضافية المطلوبة
        require_once __DIR__ . '/../includes/invoices.php';
        require_once __DIR__ . '/../includes/notifications.php';
        
        // التحقق من الصلاحيات مباشرة
        $currentUser = getCurrentUser();
        $userRole = strtolower($currentUser['role'] ?? '');
        
        error_log('User role: ' . $userRole);
        
        if (!in_array($userRole, ['manager', 'accountant'], true)) {
            error_log('User does not have required role - redirecting');
            $_SESSION['error_message'] = 'غير مصرح لك بالوصول إلى هذه الصفحة.';
            header('Location: ' . getRelativeUrl('accountant.php'));
            exit;
        }
        
        error_log('User has required role');
        
        // تضمين الملف مباشرة لمعالجة POST
        $modulePath = __DIR__ . '/../modules/manager/representatives_customers.php';
        if (file_exists($modulePath)) {
            error_log('Module file exists, including...');
            
            // تعريف ثابت لتجنب requireRole داخل الملف
            define('COLLECTION_POST_PROCESSING', true);
            
            // سيتم معالجة POST داخل الملف وإعادة التوجيه
            include $modulePath;
            
            error_log('Module file processed');
            // بعد معالجة POST، يجب إيقاف التنفيذ
            exit;
        } else {
            error_log('ERROR: Module file does not exist: ' . $modulePath);
            $_SESSION['error_message'] = 'صفحة التحصيل غير متاحة حالياً.';
            header('Location: ' . getRelativeUrl('accountant.php'));
            exit;
        }
        
    } catch (Throwable $e) {
        error_log('CRITICAL ERROR in POST processing (Accountant): ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        $_SESSION['error_message'] = 'حدث خطأ أثناء معالجة التحصيل. يرجى المحاولة مرة أخرى.';
        header('Location: ' . getRelativeUrl('accountant.php?page=representatives_customers'));
        exit;
    }
}

// معالجة AJAX لجلب أرقام الهواتف من صفحة representatives_customers
if ($page === 'representatives_customers' && 
    $_SERVER['REQUEST_METHOD'] === 'GET' && 
    isset($_GET['action']) && 
    trim($_GET['action']) === 'get_customer_phones') {
    
    // تنظيف أي output buffer قبل أي شيء
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // إيقاف عرض الأخطاء على الشاشة
    $oldErrorReporting = error_reporting(E_ALL);
    $oldDisplayErrors = ini_set('display_errors', '0');
    
    try {
        // تحميل الملفات الأساسية فقط
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        require_once __DIR__ . '/../includes/audit_log.php';
        require_once __DIR__ . '/../includes/path_helper.php';
        
        // التحقق من الصلاحيات
        requireRole(['accountant', 'manager', 'developer']);
        
        // تعريف ثابت لتجنب requireRole داخل الملف
        define('COLLECTION_POST_PROCESSING', true);
        
        // تنظيف أي output buffer مرة أخرى بعد تحميل الملفات
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تحميل ملف representatives_customers.php مباشرة للتعامل مع AJAX
        $modulePath = __DIR__ . '/../modules/manager/representatives_customers.php';
        if (file_exists($modulePath)) {
            // الملف نفسه سيتعامل مع AJAX ويخرج JSON
            include $modulePath;
            exit; // إيقاف التنفيذ بعد معالجة AJAX
        } else {
            throw new Exception('Module file not found');
        }
        
    } catch (Throwable $e) {
        // تنظيف أي output buffer في حالة الخطأ
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } finally {
        // استعادة إعدادات الأخطاء
        if (isset($oldErrorReporting)) {
            error_reporting($oldErrorReporting);
        }
        if (isset($oldDisplayErrors)) {
            ini_set('display_errors', $oldDisplayErrors);
        }
    }
}

// معالجة AJAX لجلب بيانات الأوردر للتعديل - قبل أي إخراج HTML
if ($page === 'production_tasks' && 
    $_SERVER['REQUEST_METHOD'] === 'GET' && 
    isset($_GET['action']) && 
    trim($_GET['action']) === 'get_task_for_edit' &&
    isset($_GET['task_id'])) {
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $modulePath = __DIR__ . '/../modules/manager/production_tasks.php';
    if (file_exists($modulePath)) {
        include $modulePath;
        exit;
    }
    
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false]);
    exit;
}

/**
 * التأكد من وجود جدول accountant_transactions
 */
function ensureAccountantTransactionsTable() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    
    try {
        $db = db();
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
        if (empty($tableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `accountant_transactions` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `transaction_type` enum('collection_from_sales_rep','expense','income','transfer','other') NOT NULL COMMENT 'نوع المعاملة',
                  `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ',
                  `sales_rep_id` int(11) DEFAULT NULL COMMENT 'معرف المندوب (للتحصيل)',
                  `description` text NOT NULL COMMENT 'الوصف',
                  `reference_number` varchar(50) DEFAULT NULL COMMENT 'رقم مرجعي',
                  `payment_method` enum('cash','bank_transfer','check','other') DEFAULT 'cash' COMMENT 'طريقة الدفع',
                  `status` enum('pending','approved','rejected') DEFAULT 'approved' COMMENT 'الحالة',
                  `approved_by` int(11) DEFAULT NULL COMMENT 'من وافق',
                  `approved_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
                  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
                  `created_by` int(11) NOT NULL COMMENT 'من أنشأ السجل',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
                  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
                  PRIMARY KEY (`id`),
                  KEY `transaction_type` (`transaction_type`),
                  KEY `sales_rep_id` (`sales_rep_id`),
                  KEY `status` (`status`),
                  KEY `created_by` (`created_by`),
                  KEY `approved_by` (`approved_by`),
                  KEY `created_at` (`created_at`),
                  KEY `reference_number` (`reference_number`),
                  CONSTRAINT `accountant_transactions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                  CONSTRAINT `accountant_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `accountant_transactions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المعاملات المحاسبية'
            ");
        }
    } catch (Throwable $e) {
        error_log('Error creating accountant_transactions table: ' . $e->getMessage());
    }
}

// التأكد من وجود الجدول
ensureAccountantTransactionsTable();

// معالجة طلبات AJAX لـ my_salary قبل إرسال أي HTML
$isAjaxRequest = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (!empty($_POST['is_ajax'])) ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

if ($isAjaxRequest && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_advance') {
    $pageParam = $_GET['page'] ?? 'dashboard';
    if ($pageParam === 'my_salary') {
        // تنظيف أي output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تحميل الملفات الأساسية
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        
        // التحقق من تسجيل الدخول
        if (!isLoggedIn()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'يجب تسجيل الدخول أولاً'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        
        // تضمين وحدة my_salary
        $modulePath = __DIR__ . '/../modules/user/my_salary.php';
        if (file_exists($modulePath)) {
            include $modulePath;
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'صفحة الراتب غير متاحة.'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

// معالجة AJAX لجلب أرقام الهواتف من صفحة local_customers
if ($page === 'local_customers' && 
    $_SERVER['REQUEST_METHOD'] === 'GET' && 
    isset($_GET['action']) && 
    trim($_GET['action']) === 'get_local_customer_phones') {
    
    // تنظيف أي output buffer قبل أي شيء
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // إيقاف عرض الأخطاء على الشاشة
    $oldErrorReporting = error_reporting(E_ALL);
    $oldDisplayErrors = ini_set('display_errors', '0');
    
    try {
        // تحميل الملفات الأساسية فقط
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        require_once __DIR__ . '/../includes/audit_log.php';
        require_once __DIR__ . '/../includes/path_helper.php';
        
        // التحقق من الصلاحيات
        requireRole(['accountant', 'manager', 'developer']);
        
        // تعريف ثابت لتجنب requireRole داخل الملف
        define('LOCAL_CUSTOMERS_MODULE_BOOTSTRAPPED', true);
        
        // تنظيف أي output buffer مرة أخرى بعد تحميل الملفات
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تحميل ملف local_customers.php مباشرة للتعامل مع AJAX
        $modulePath = __DIR__ . '/../modules/manager/local_customers.php';
        if (file_exists($modulePath)) {
            // الملف نفسه سيتعامل مع AJAX ويخرج JSON
            include $modulePath;
            exit; // إيقاف التنفيذ بعد معالجة AJAX
        } else {
            throw new Exception('Module file not found');
        }
        
    } catch (Throwable $e) {
        // تنظيف أي output buffer في حالة الخطأ
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } finally {
        // استعادة إعدادات الأخطاء
        if (isset($oldErrorReporting)) {
            error_reporting($oldErrorReporting);
        }
        if (isset($oldDisplayErrors)) {
            ini_set('display_errors', $oldDisplayErrors);
        }
    }
}

// معالجة AJAX لجلب عملاء المندوب من صفحة orders
if ($page === 'orders' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_customers' && isset($_GET['sales_rep_id'])) {
    // تنظيف أي output buffer قبل أي شيء
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // إيقاف عرض الأخطاء على الشاشة
    $oldErrorReporting = error_reporting(E_ALL);
    $oldDisplayErrors = ini_set('display_errors', '0');
    
    try {
        // تحميل الملفات الأساسية فقط
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        require_once __DIR__ . '/../includes/path_helper.php';
        
        // التحقق من الصلاحيات بدون إخراج HTML
        $currentUser = getCurrentUser();
        if (!$currentUser) {
            throw new Exception('غير مصرح بالوصول');
        }
        
        $allowedRoles = ['manager', 'accountant', 'sales'];
        if (!in_array(strtolower($currentUser['role'] ?? ''), $allowedRoles, true)) {
            throw new Exception('غير مصرح بالوصول');
        }
        
        // تنظيف أي output buffer مرة أخرى بعد تحميل الملفات
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تعيين header JSON
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        
        $salesRepId = intval($_GET['sales_rep_id']);
        
        if ($salesRepId > 0) {
            $db = db();
            
            // إذا كان المستخدم مندوب (sales)، يمكنه فقط رؤية العملاء الذين أنشأهم (created_by فقط)
            // وليس بناءً على rep_id - هذا يضمن عدم ظهور عملاء المندوب القديم للمندوب الجديد
            $currentUserRole = strtolower($currentUser['role'] ?? '');
            $currentUserId = (int)($currentUser['id'] ?? 0);
            
            if ($currentUserRole === 'sales' && $currentUserId > 0) {
                // إذا كان المستخدم مندوب ويريد جلب عملائه، يجب أن يكون salesRepId = currentUserId
                if ($salesRepId !== $currentUserId) {
                    $response = [
                        'success' => false,
                        'message' => 'غير مصرح لك بالوصول'
                    ];
                    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
                // استخدام created_by فقط للمندوب
                $customers = $db->query(
                    "SELECT id, name FROM customers WHERE created_by = ? AND status = 'active' ORDER BY name ASC",
                    [$salesRepId]
                );
            } else {
                // للمديرين والمحاسبين: يمكنهم رؤية عملاء المندوب (rep_id OR created_by)
                $customers = $db->query(
                    "SELECT id, name FROM customers WHERE (created_by = ? OR rep_id = ?) AND status = 'active' ORDER BY name ASC",
                    [$salesRepId, $salesRepId]
                );
            }
            
            $response = [
                'success' => true,
                'customers' => $customers
            ];
        } else {
            $response = [
                'success' => false,
                'message' => 'معرف المندوب غير صحيح'
            ];
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
    } catch (Throwable $e) {
        // تنظيف أي output buffer في حالة الخطأ
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    } finally {
        // استعادة إعدادات الأخطاء
        if (isset($oldErrorReporting)) {
            error_reporting($oldErrorReporting);
        }
        if (isset($oldDisplayErrors)) {
            ini_set('display_errors', $oldDisplayErrors);
        }
    }
    
    exit;
}

// معالجة AJAX لجلب رصيد المندوب - يجب أن يكون في البداية قبل أي output
if ($page === 'financial' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_sales_rep_balance') {
    // تعطيل عرض الأخطاء في المتصفح
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    
    // تنظيف أي output buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // إرسال headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    
    $response = ['success' => false, 'message' => ''];
    $salesRepId = isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : 0;
    
    if ($salesRepId <= 0) {
        $response['message'] = 'معرف المندوب غير صحيح';
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
    
    try {
        $balance = calculateSalesRepCashBalance($salesRepId);
        
        $salesRep = $db->queryOne(
            "SELECT id, username, full_name FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
            [$salesRepId]
        );
        
        if (empty($salesRep)) {
            $response['message'] = 'المندوب غير موجود أو غير نشط';
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            exit;
        }
        
        $response = [
            'success' => true,
            'balance' => floatval($balance),
            'sales_rep_name' => htmlspecialchars($salesRep['full_name'] ?? $salesRep['username'], ENT_QUOTES, 'UTF-8')
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } catch (Throwable $e) {
        // تسجيل الخطأ في ملف log بدلاً من إرساله للمتصفح
        error_log('Error getting sales rep balance [ID: ' . $salesRepId . ']: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        
        $response['message'] = 'حدث خطأ أثناء جلب رصيد المندوب. يرجى المحاولة مرة أخرى.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    
    exit;
}

// طلب AJAX لجلب جدول الحركات المالية فقط (تنقل سلس بدون ريفريش)
if ($page === 'financial' && isset($_GET['ajax']) && $_GET['ajax'] === 'transactions_list') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    if (!function_exists('getAccountantFinancialTransactionsHtml')) {
        function getAccountantFinancialTransactionsHtml($db) {
            $searchType = isset($_GET['search_type']) && $_GET['search_type'] !== '' ? $_GET['search_type'] : null;
            $searchStatus = isset($_GET['search_status']) && $_GET['search_status'] !== '' ? $_GET['search_status'] : null;
            $searchDateFrom = isset($_GET['search_date_from']) && $_GET['search_date_from'] !== '' ? $_GET['search_date_from'] : null;
            $searchDateTo = isset($_GET['search_date_to']) && $_GET['search_date_to'] !== '' ? $_GET['search_date_to'] : null;
            $searchAmountFrom = isset($_GET['search_amount_from']) && $_GET['search_amount_from'] !== '' ? floatval($_GET['search_amount_from']) : null;
            $searchAmountTo = isset($_GET['search_amount_to']) && $_GET['search_amount_to'] !== '' ? floatval($_GET['search_amount_to']) : null;
            $searchDescription = isset($_GET['search_description']) && $_GET['search_description'] !== '' ? trim($_GET['search_description']) : null;
            $searchReference = isset($_GET['search_reference']) && $_GET['search_reference'] !== '' ? trim($_GET['search_reference']) : null;
            $searchCreatedBy = isset($_GET['search_created_by']) && $_GET['search_created_by'] !== '' ? intval($_GET['search_created_by']) : null;
            $searchApprovedBy = isset($_GET['search_approved_by']) && $_GET['search_approved_by'] !== '' ? $_GET['search_approved_by'] : null;
            $whereConditions = [];
            $queryParams = [];
            if ($searchType !== null) { $whereConditions[] = "combined.type = ?"; $queryParams[] = $searchType; }
            if ($searchStatus !== null) { $whereConditions[] = "combined.status = ?"; $queryParams[] = $searchStatus; }
            if ($searchDateFrom !== null) { $whereConditions[] = "DATE(combined.created_at) >= ?"; $queryParams[] = $searchDateFrom; }
            if ($searchDateTo !== null) { $whereConditions[] = "DATE(combined.created_at) <= ?"; $queryParams[] = $searchDateTo; }
            if ($searchAmountFrom !== null) { $whereConditions[] = "combined.amount >= ?"; $queryParams[] = $searchAmountFrom; }
            if ($searchAmountTo !== null) { $whereConditions[] = "combined.amount <= ?"; $queryParams[] = $searchAmountTo; }
            if ($searchDescription !== null) { $whereConditions[] = "combined.description LIKE ?"; $queryParams[] = '%' . $searchDescription . '%'; }
            if ($searchReference !== null) { $whereConditions[] = "combined.reference_number LIKE ?"; $queryParams[] = '%' . $searchReference . '%'; }
            if ($searchCreatedBy !== null) { $whereConditions[] = "combined.created_by = ?"; $queryParams[] = $searchCreatedBy; }
            if ($searchApprovedBy !== null) {
                if ($searchApprovedBy === 'null') { $whereConditions[] = "combined.approved_by IS NULL"; }
                else { $whereConditions[] = "combined.approved_by = ?"; $queryParams[] = intval($searchApprovedBy); }
            }
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
            $perPage = 6;
            $offset = ($pageNum - 1) * $perPage;
            $countQuery = "SELECT COUNT(*) as total FROM (SELECT id,type,amount,description,reference_number,status,created_by,approved_by,created_at FROM financial_transactions UNION ALL SELECT id, CASE WHEN transaction_type='collection_from_sales_rep' THEN 'income' WHEN transaction_type='expense' THEN 'expense' WHEN transaction_type='income' THEN 'income' WHEN transaction_type='transfer' THEN 'transfer' WHEN transaction_type='payment' THEN 'payment' ELSE 'other' END, amount,description,reference_number,status,created_by,approved_by,created_at FROM accountant_transactions) as combined $whereClause";
            $totalCountResult = $db->queryOne($countQuery, $queryParams);
            $totalCount = (int)($totalCountResult['total'] ?? 0);
            $totalPages = ceil($totalCount / $perPage);
            $dataQuery = "SELECT combined.*, u1.full_name as created_by_name, u2.full_name as approved_by_name FROM (SELECT id,type,amount,description,reference_number,status,created_by,approved_by,created_at,NULL as transaction_type,'financial_transactions' as source_table FROM financial_transactions UNION ALL SELECT id, CASE WHEN transaction_type='collection_from_sales_rep' THEN 'income' WHEN transaction_type='expense' THEN 'expense' WHEN transaction_type='income' THEN 'income' WHEN transaction_type='transfer' THEN 'transfer' WHEN transaction_type='payment' THEN 'payment' ELSE 'other' END, amount,description,reference_number,status,created_by,approved_by,created_at,transaction_type,'accountant_transactions' as source_table FROM accountant_transactions) as combined LEFT JOIN users u1 ON combined.created_by = u1.id LEFT JOIN users u2 ON combined.approved_by = u2.id $whereClause ORDER BY combined.created_at DESC LIMIT ? OFFSET ?";
            $queryParams[] = $perPage;
            $queryParams[] = $offset;
            $financialTransactions = $db->query($dataQuery, $queryParams) ?: [];
            $typeLabels = ['income' => 'إيراد', 'expense' => 'مصروف', 'transfer' => 'تحويل', 'payment' => 'دفعة', 'other' => 'أخرى'];
            $statusLabels = ['pending' => 'معلق', 'approved' => 'معتمد', 'rejected' => 'مرفوض'];
            $statusColors = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
            ob_start();
            ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-light"><tr><th>التاريخ</th><th>النوع</th><th>المبلغ</th><th>الوصف</th><th>الرقم المرجعي</th><th>أنشأه</th><th>إجراءات</th></tr></thead>
                    <tbody>
                        <?php if (empty($financialTransactions)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-inbox me-2"></i>لا توجد حركات مالية حالياً</td></tr>
                        <?php else: foreach ($financialTransactions as $trans):
                            $rowClass = $trans['type'] === 'expense' ? 'table-danger' : ($trans['type'] === 'income' ? 'table-success' : ''); ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td><?php echo formatDateTime($trans['created_at']); ?></td>
                                <td><span class="badge bg-<?php echo $trans['type'] === 'income' ? 'success' : ($trans['type'] === 'expense' ? 'danger' : 'info'); ?>"><?php echo htmlspecialchars($typeLabels[$trans['type']] ?? $trans['type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="fw-bold <?php echo $trans['type'] === 'income' ? 'text-success' : 'text-danger'; ?>"><?php echo $trans['type'] === 'income' ? '+' : '-'; ?><?php echo formatCurrency($trans['amount']); ?></td>
                                <td><?php echo htmlspecialchars($trans['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php if ($trans['reference_number']) { echo '<span class="text-muted small">'.htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8').'</span>'; } else { echo '<span class="text-muted">-</span>'; } ?></td>
                                <td><span class="badge bg-<?php echo $statusColors[$trans['status']] ?? 'secondary'; ?>"><?php echo htmlspecialchars($statusLabels[$trans['status']] ?? $trans['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($trans['created_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php
                                    $isCollectionFromSalesRep = (($trans['source_table'] ?? '') === 'accountant_transactions' && ($trans['type'] ?? '') === 'income' && ($trans['transaction_type'] ?? '') === 'collection_from_sales_rep');
                                    if ($isCollectionFromSalesRep) { $printUrl = getRelativeUrl('print_collection_receipt.php?id=' . $trans['id']); ?>
                                        <a href="<?php echo htmlspecialchars($printUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="طباعة فاتورة التحصيل"><i class="bi bi-printer"></i></a>
                                    <?php } else { echo '<span class="text-muted small">-</span>'; } ?></td>
                                <td><?php echo htmlspecialchars($trans['approved_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): $searchParams = []; if ($searchType !== null) $searchParams['search_type']=$searchType; if ($searchStatus !== null) $searchParams['search_status']=$searchStatus; if ($searchDateFrom !== null) $searchParams['search_date_from']=$searchDateFrom; if ($searchDateTo !== null) $searchParams['search_date_to']=$searchDateTo; if ($searchAmountFrom !== null) $searchParams['search_amount_from']=$searchAmountFrom; if ($searchAmountTo !== null) $searchParams['search_amount_to']=$searchAmountTo; if ($searchDescription !== null) $searchParams['search_description']=$searchDescription; if ($searchReference !== null) $searchParams['search_reference']=$searchReference; if ($searchCreatedBy !== null) $searchParams['search_created_by']=$searchCreatedBy; if ($searchApprovedBy !== null) $searchParams['search_approved_by']=$searchApprovedBy; $baseUrl = '?page=accountant_cash'; $searchQueryString = !empty($searchParams) ? '&' . http_build_query($searchParams) : ''; ?>
            <nav aria-label="Page navigation" class="mt-3">
                <ul class="pagination justify-content-center flex-wrap">
                    <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $baseUrl . ($pageNum > 1 ? '&p=' . ($pageNum - 1) : '') . $searchQueryString; ?>"><i class="bi bi-chevron-right"></i></a></li>
                    <?php $startPage = max(1, $pageNum - 2); $endPage = min($totalPages, $pageNum + 2); if ($startPage > 1): ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&p=1' . $searchQueryString; ?>">1</a></li><?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; endif; ?>
                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?><li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $baseUrl . '&p=' . $i . $searchQueryString; ?>"><?php echo $i; ?></a></li><?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?><?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&p=' . $totalPages . $searchQueryString; ?>"><?php echo $totalPages; ?></a></li><?php endif; ?>
                    <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $baseUrl . '&p=' . ($pageNum + 1) . $searchQueryString; ?>"><i class="bi bi-chevron-left"></i></a></li>
                </ul>
                <div class="text-center text-muted small mt-2">عرض <?php echo number_format(($pageNum - 1) * $perPage + 1); ?> - <?php echo number_format(min($pageNum * $perPage, $totalCount)); ?> من أصل <?php echo number_format($totalCount); ?> حركة<?php if (!empty($searchParams)): ?> <span class="badge bg-info ms-2">نتائج البحث</span><?php endif; ?></div>
            </nav>
            <?php endif; ?>
            <?php
            return ob_get_clean();
        }
    }
    echo json_encode(['html' => getAccountantFinancialTransactionsHtml($db)], JSON_UNESCAPED_UNICODE);
    exit;
}

$customersModulePath = __DIR__ . '/../modules/sales/customers.php';
if (
    isset($_GET['ajax'], $_GET['action']) &&
    $_GET['ajax'] === 'purchase_history' &&
    $_GET['action'] === 'purchase_history'
) {
    if (!defined('CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
        define('CUSTOMERS_PURCHASE_HISTORY_AJAX', true);
    }
    if (file_exists($customersModulePath)) {
        include $customersModulePath;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'وحدة العملاء غير متاحة.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// معالجة طلب update_location قبل إرسال أي HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'update_location') {
    $pageParam = $_GET['page'] ?? 'dashboard';
    if ($pageParam === 'customers') {
        // تنظيف أي output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تحميل الملفات الأساسية
        if (!defined('CUSTOMERS_MODULE_BOOTSTRAPPED')) {
            require_once __DIR__ . '/../includes/config.php';
            require_once __DIR__ . '/../includes/db.php';
            require_once __DIR__ . '/../includes/auth.php';
            require_once __DIR__ . '/../includes/audit_log.php';
            require_once __DIR__ . '/../includes/path_helper.php';
            require_once __DIR__ . '/../includes/customer_history.php';
            require_once __DIR__ . '/../includes/invoices.php';
            require_once __DIR__ . '/../includes/salary_calculator.php';
            
            requireRole(['sales', 'accountant', 'manager']);
        }
        
        // تضمين وحدة customers التي تحتوي على معالج update_location
        if (file_exists($customersModulePath)) {
            define('CUSTOMERS_MODULE_BOOTSTRAPPED', true);
            if (!defined('CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
                define('CUSTOMERS_PURCHASE_HISTORY_AJAX', true);
            }
            include $customersModulePath;
        } else {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'وحدة العملاء غير متاحة.'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

$financialSuccess = '';
$financialError = '';
$financialFormData = [];

if ($page === 'financial') {
    if (isset($_SESSION['financial_success'])) {
        $financialSuccess = $_SESSION['financial_success'];
        unset($_SESSION['financial_success']);
    }
    if (isset($_SESSION['financial_error'])) {
        $financialError = $_SESSION['financial_error'];
        unset($_SESSION['financial_error']);
    }
    if (isset($_SESSION['financial_form_data'])) {
        $financialFormData = $_SESSION['financial_form_data'];
        unset($_SESSION['financial_form_data']);
    }
}

$reportsSuccess = '';
$reportsError = '';
if ($page === 'reports') {
    if (isset($_SESSION['reports_success'])) {
        $reportsSuccess = $_SESSION['reports_success'];
        unset($_SESSION['reports_success']);
    }
    if (isset($_SESSION['reports_error'])) {
        $reportsError = $_SESSION['reports_error'];
        unset($_SESSION['reports_error']);
    }
}

// معالجة صفحة نقطة البيع (POS) - متاحة للمحاسب
// لا حاجة لتغيير $page هنا، سيتم معالجتها لاحقاً

// معالجة AJAX قبل أي إخراج HTML - خاصة لصفحة مخزن أدوات التعبئة
if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
    // تحميل ملف packaging_warehouse.php مباشرة للتعامل مع AJAX
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        // الملف نفسه سيتعامل مع AJAX ويخرج JSON
        include $modulePath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}


if ($page === 'financial' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'collect_from_sales_rep') {
        $salesRepId = isset($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : 0;
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        if ($salesRepId <= 0) {
            $_SESSION['financial_error'] = 'يرجى اختيار مندوب صحيح.';
        } elseif ($amount <= 0) {
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
        } else {
            try {
                require_once __DIR__ . '/../includes/approval_system.php';
                $currentBalance = calculateSalesRepCashBalance($salesRepId);
                
                if ($amount > $currentBalance) {
                    $_SESSION['financial_error'] = 'المبلغ المطلوب (' . formatCurrency($amount) . ') أكبر من رصيد المندوب (' . formatCurrency($currentBalance) . ').';
                } else {
                    $db->beginTransaction();
                    
                    // الحصول على بيانات المندوب
                    $salesRep = $db->queryOne(
                        "SELECT id, username, full_name FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                        [$salesRepId]
                    );
                    
                    if (empty($salesRep)) {
                        throw new Exception('المندوب غير موجود أو غير نشط');
                    }
                    
                    $salesRepName = $salesRep['full_name'] ?? $salesRep['username'];
                    $finalDescription = 'تحصيل من مندوب: ' . $salesRepName;
                    $referenceNumber = 'COL-REP-' . $salesRepId . '-' . date('YmdHis');
                    
                    // 1. إضافة تحصيل في جدول accountant_transactions
                    $db->execute(
                        "INSERT INTO accountant_transactions (transaction_type, amount, sales_rep_id, description, reference_number, status, approved_by, created_by, approved_at)
                         VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, NOW())",
                        [
                            'collection_from_sales_rep',
                            $amount,
                            $salesRepId,
                            $finalDescription,
                            $referenceNumber,
                            $currentUser['id'],
                            $currentUser['id']
                        ]
                    );
                    
                    $accountantTransactionId = $db->getLastInsertId();
                    
                    // ملاحظة: لا نضيف سجل في financial_transactions لأن:
                    // 1. التحصيل من المندوب يُسجل فقط في accountant_transactions
                    // 2. عند حساب الإيرادات، يتم حسابها من accountant_transactions (collection_from_sales_rep)
                    // 3. إضافة سجل في financial_transactions سيؤدي إلى حساب المبلغ مرتين (مرة من كل جدول)
                    // 4. خصم المبلغ من رصيد المندوب يتم حسابه من خلال calculateSalesRepCashBalance
                    //    الذي يحسب: (collections + invoices) - (accountant_transactions collection_from_sales_rep)
                    
                    $transactionId = $accountantTransactionId; // استخدام معرف المعاملة المحاسبية
                    
                    logAudit(
                        $currentUser['id'],
                        'collect_from_sales_rep',
                        'financial_transaction',
                        $transactionId,
                        null,
                        [
                            'sales_rep_id' => $salesRepId,
                            'sales_rep_name' => $salesRepName,
                            'amount' => $amount,
                        ]
                    );
                    
                    // إرسال إشعار للمندوب
                    try {
                        require_once __DIR__ . '/../includes/path_helper.php';
                        $collectorName = $currentUser['full_name'] ?? $currentUser['username'];
                        $notificationTitle = 'تحصيل من خزنتك';
                        $notificationMessage = 'تم تحصيل مبلغ ' . formatCurrency($amount) . ' من رصيد خزنتك من قبل ' . htmlspecialchars($collectorName) . ' - رقم المرجع: ' . $referenceNumber;
                        $notificationLink = getRelativeUrl('dashboard/sales.php?page=cash_register');
                        
                        createNotification(
                            $salesRepId,
                            $notificationTitle,
                            $notificationMessage,
                            'warning',
                            $notificationLink,
                            true // إرسال Telegram
                        );
                    } catch (Throwable $notifError) {
                        // لا نوقف العملية إذا فشل الإشعار
                        error_log('Failed to send notification to sales rep: ' . $notifError->getMessage());
                    }
                    
                    $db->commit();
                    
                    // حفظ معرف المعاملة في الجلسة للطباعة
                    require_once __DIR__ . '/../includes/path_helper.php';
                    $_SESSION['last_collection_transaction_id'] = $accountantTransactionId;
                    $_SESSION['financial_success'] = 'تم تحصيل ' . formatCurrency($amount) . ' من مندوب: ' . htmlspecialchars($salesRepName) . ' بنجاح.';
                    $_SESSION['last_collection_print_link'] = getRelativeUrl('print_collection_receipt.php?id=' . $accountantTransactionId);
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Collect from sales rep failed: ' . $e->getMessage());
                $_SESSION['financial_error'] = 'حدث خطأ أثناء التحصيل: ' . $e->getMessage();
            }
        }
        
        $redirectTarget = strtok($_SERVER['REQUEST_URI'] ?? '?page=accountant_cash', '#');
        if (!headers_sent()) {
            header('Location: ' . $redirectTarget);
        } else {
            echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
        }
        exit;
    }

    if ($action === 'add_quick_expense') {
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $description = trim($_POST['description'] ?? '');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        
        // المحاسب يعتمد المصروف تلقائياً
        $markAsApproved = true;

        $_SESSION['financial_form_data'] = [
            'amount' => $_POST['amount'] ?? '',
            'description' => $description,
            'reference_number' => $referenceNumber,
        ];

        if ($amount <= 0) {
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ مصروف صحيح.';
        } else {
            try {
                // المحاسب يعتمد المصروف تلقائياً
                $status = 'approved';
                $approvedBy = $currentUser['id'];
                $approvedAt = date('Y-m-d H:i:s');

                $db->execute(
                    "INSERT INTO financial_transactions (type, amount, supplier_id, description, reference_number, status, approved_by, created_by, approved_at)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)",
                    [
                        'expense',
                        $amount,
                        $description,
                        $referenceNumber !== '' ? $referenceNumber : null,
                        $status,
                        $approvedBy,
                        $currentUser['id'],
                        $approvedAt
                    ]
                );

                $transactionId = $db->getLastInsertId();

                logAudit(
                    $currentUser['id'],
                    'quick_expense_create',
                    'financial_transaction',
                    $transactionId,
                    null,
                    [
                        'amount' => $amount,
                        'status' => $status,
                        'reference' => $referenceNumber !== '' ? $referenceNumber : null
                    ]
                );

                unset($_SESSION['financial_form_data']);

                $_SESSION['financial_success'] = 'تم تسجيل المصروف واعتماده تلقائياً.';
            } catch (Throwable $e) {
                error_log('Quick expense insertion failed: ' . $e->getMessage());
                $_SESSION['financial_error'] = 'حدث خطأ أثناء تسجيل المصروف. حاول مرة أخرى.';
            }
        }

            $redirectTarget = strtok($_SERVER['REQUEST_URI'] ?? '?page=accountant_cash', '#');
        if (!headers_sent()) {
            header('Location: ' . $redirectTarget);
        } else {
            echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
        }
        exit;
    }
}

if ($page === 'reports' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['reports_error'] = 'رمز الحماية غير صالح. يرجى إعادة المحاولة.';
        header('Location: accountant.php?page=reports');
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'send_monthly_production_report') {
        $reportMonth = isset($_POST['report_month']) ? max(1, min(12, (int) $_POST['report_month'])) : (int) date('n');
        $reportYear = isset($_POST['report_year']) ? max(2000, (int) $_POST['report_year']) : (int) date('Y');

        $result = sendMonthlyProductionDetailedReportToTelegram(
            $reportMonth,
            $reportYear,
            [
                'force' => true,
                'triggered_by' => $currentUser['id'] ?? null,
                'date_to' => date('Y-m-d'),
            ]
        );

        if (!empty($result['success'])) {
            $_SESSION['reports_success'] = $result['message'] ?? 'تم إرسال التقرير الشهري التفصيلي إلى Telegram.';
        } else {
            $_SESSION['reports_error'] = $result['message'] ?? 'تعذر إرسال التقرير الشهري التفصيلي.';
        }

        header('Location: accountant.php?page=reports');
        exit;
    }
}

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['accountant_dashboard']) ? $lang['accountant_dashboard'] : 'لوحة المحاسب';
$pageDescription = 'لوحة تحكم المحاسب - إدارة المعاملات المالية والمصروفات والتقارير - ' . APP_NAME;

// التحقق من طلب AJAX للتنقل (AJAX Navigation)
$isAjaxNavigation = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    isset($_SERVER['HTTP_ACCEPT']) && 
    stripos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false
);

// إذا كان طلب AJAX للتنقل، نعيد المحتوى فقط بدون header/footer
if ($isAjaxNavigation) {
    // تنظيف output buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // إرسال headers للـ AJAX response
    header('Content-Type: text/html; charset=utf-8');
    header('X-AJAX-Navigation: true');
    
    // بدء output buffering
    ob_start();
}
?>
<?php if (!$isAjaxNavigation): ?>
<?php include __DIR__ . '/../templates/header.php'; ?>
<?php endif; ?>

            <?php if ($page === 'dashboard' || $page === ''): ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-speedometer2"></i><?php echo isset($lang['accountant_dashboard']) ? $lang['accountant_dashboard'] : 'لوحة المحاسب'; ?></h2>
                </div>
                
                <!-- لوحة مالية مصغرة -->
                <div class="cards-grid">
                    <?php
                    // حساب ملخص الخزنة بشكل شامل ومحدث
                    $treasurySummary = $db->queryOne("
                        SELECT
                            (SELECT COALESCE(SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                            (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('collection_from_sales_rep', 'income') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_income,
                            (SELECT COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND status = 'approved' 
                                AND (description NOT LIKE '%سلفة%' AND description NOT LIKE '%سلف%')
                                AND description NOT LIKE '%تسوية رصيد دائن ل%'
                                THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_expense,
                            (SELECT COALESCE(SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' AND status = 'approved' AND (description NOT LIKE '%تسوية راتب%' OR description IS NULL) THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_payment
                    ");
                    
                    $approvedIncome = (float)($treasurySummary['approved_income'] ?? 0);
                    $approvedExpense = (float)($treasurySummary['approved_expense'] ?? 0);
                    $approvedPayment = (float)($treasurySummary['approved_payment'] ?? 0);
                    
                    // حساب إجمالي المرتبات (المعتمدة والمدفوعة)
                    $totalSalaries = 0.0;
                    $salariesTableExists = $db->queryOne("SHOW TABLES LIKE 'salaries'");
                    if (!empty($salariesTableExists)) {
                        $salariesResult = $db->queryOne(
                            "SELECT COALESCE(SUM(total_amount), 0) as total_salaries
                             FROM salaries
                             WHERE status IN ('approved', 'paid')"
                        );
                        $totalSalaries = (float)($salariesResult['total_salaries'] ?? 0);
                    }
                    
                    // حساب إجمالي تسويات المرتبات (يشمل التسويات والسلف)
                    $totalSalaryAdjustments = 0.0;
                    $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                    if (!empty($accountantTableExists)) {
                        $adjustmentsResult = $db->queryOne(
                            "SELECT COALESCE(SUM(amount), 0) as total_adjustments
                             FROM accountant_transactions
                             WHERE status = 'approved'
                             AND (
                                 (transaction_type = 'payment' AND description LIKE '%تسوية راتب%')
                                 OR (transaction_type = 'expense' AND (description LIKE '%سلفة%' OR description LIKE '%سلف%'))
                             )"
                        );
                        $totalSalaryAdjustments = (float)($adjustmentsResult['total_adjustments'] ?? 0);
                    }
                    
                    // حساب إجمالي تسويات أرصدة العملاء
                    $totalCustomerCreditSettlements = 0.0;
                    if (!empty($accountantTableExists)) {
                        $customerSettlementsResult = $db->queryOne(
                            "SELECT COALESCE(SUM(amount), 0) as total_settlements
                             FROM accountant_transactions
                             WHERE transaction_type = 'expense' 
                             AND status = 'approved'
                             AND (description LIKE '%تسوية رصيد دائن لعميل محلي%' OR description LIKE '%تسوية رصيد دائن لعميل مندوب%')"
                        );
                        $totalCustomerCreditSettlements = (float)($customerSettlementsResult['total_settlements'] ?? 0);
                    }
                    
                    // حساب إجمالي توريدات الإدارة
                    $totalManagementSupplies = 0.0;
                    if (!empty($accountantTableExists)) {
                        $managementSuppliesResult = $db->queryOne(
                            "SELECT COALESCE(SUM(amount), 0) as total_supplies
                             FROM accountant_transactions
                             WHERE transaction_type = 'income' 
                             AND status = 'approved'
                             AND description LIKE '%تحصيل للإدارة%'"
                        );
                        $totalManagementSupplies = (float)($managementSuppliesResult['total_supplies'] ?? 0);
                    }
                    
                    // حساب صافي الرصيد المعتمد بشكل شامل
                    $cashBalance = $approvedIncome - $approvedExpense - $approvedPayment - $totalSalaries - $totalSalaryAdjustments - $totalCustomerCreditSettlements - $totalManagementSupplies;
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo (isset($lang) && isset($lang['cash_balance'])) ? $lang['cash_balance'] : 'رصيد الخزينة'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($cashBalance); ?></div>
                    </div>
                    
                    <?php
                    $expenses = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total
                         FROM financial_transactions 
                         WHERE type = 'expense' AND status = 'approved' 
                         AND MONTH(created_at) = MONTH(NOW())"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon red">
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['expenses']) ? $lang['expenses'] : 'المصروفات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($expenses['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                </div>
                
            <?php elseif ($page === 'chat'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/chat/group_chat.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">وحدة الدردشة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'reports'): ?>
                <?php $reportsCsrfToken = generateCSRFToken(); ?>
                <div class="page-header mb-4">
                    <h2><i class="bi bi-bar-chart-fill me-2"></i>تقارير الإنتاج</h2>
                    <p class="text-muted mb-0">الوصول السريع للتقرير الشهري التفصيلي لخط الإنتاج وإرساله عبر Telegram.</p>
                </div>

                <?php if ($reportsError): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($reportsError, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($reportsSuccess): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($reportsSuccess, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h5 class="mb-1"><i class="bi bi-clipboard-pulse me-2 text-primary"></i>التقرير الشهري المفصل لخط الإنتاج</h5>
                            <p class="text-muted mb-0">يتضمن ملخص استهلاك المواد الخام وأدوات التعبئة بالإضافة إلى سجل التوريدات ويرسل مباشرة إلى قناة الإدارة على Telegram.</p>
                        </div>
                        <form method="post" class="d-flex flex-column flex-sm-row gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($reportsCsrfToken); ?>">
                            <input type="hidden" name="action" value="send_monthly_production_report">
                            <input type="hidden" name="report_month" value="<?php echo (int) date('n'); ?>">
                            <input type="hidden" name="report_year" value="<?php echo (int) date('Y'); ?>">
                            <button class="btn btn-primary">
                                <i class="bi bi-send-fill me-1"></i>إرسال التقرير الشهري المفصل
                            </button>
                        </form>
                    </div>
                </div>

            <?php elseif ($page === 'financial'): ?>
                <?php
                // تحميل صفحة الخزنة من وحدة خزنة الشركة لتفادي ERR_FAILED عند غياب الجداول أو أخطاء الاستعلام
                $financialUseModule = false;
                $companyCashPath = __DIR__ . '/../modules/manager/company_cash.php';
                if (file_exists($companyCashPath)) {
                    include $companyCashPath;
                    $financialUseModule = true;
                } else {
                ?>
                <!-- صفحة الخزنة (احتياطي عند غياب الوحدة) -->
                <div class="page-header mb-4">
                    <h2><i class="bi bi-safe me-2"></i><?php echo isset($lang['menu_financial']) ? $lang['menu_financial'] : 'الخزنة'; ?></h2>
                </div>
                <div class="alert alert-warning">صفحة الخزنة غير متاحة حالياً. <a href="<?php echo htmlspecialchars(getDashboardUrl() . 'accountant.php?page=accountant_cash'); ?>">خزنة المحاسب</a>.</div>
                <?php
                }
                if (!$financialUseModule):
                ?>
                <?php if ($financialError): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($financialError, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($financialSuccess): ?>
                    <div class="alert alert-success alert-dismissible fade show d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php echo htmlspecialchars($financialSuccess, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="d-flex align-items-center gap-2" style="background:rgb(30, 124, 30); padding: 6px 12px; border-radius: 7px;">
                            <?php if (!empty($_SESSION['last_collection_print_link'])): ?>
                                <a href="<?php echo htmlspecialchars($_SESSION['last_collection_print_link'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-light">
                                    <i class="bi bi-printer me-1"></i>طباعة فاتورة التحصيل
                                </a>
                                <?php unset($_SESSION['last_collection_print_link']); ?>
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    </div>
                <?php endif; ?>
                
                
            <?php
            if (!$financialUseModule) {
            // حساب ملخص الخزينة من financial_transactions و accountant_transactions
            $treasurySummary = $db->queryOne("
                SELECT
                    (SELECT COALESCE(SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                    (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('collection_from_sales_rep', 'income') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_income,
                    (SELECT COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                    (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND status = 'approved' 
                        AND (description NOT LIKE '%سلفة%' AND description NOT LIKE '%سلف%')
                        AND description NOT LIKE '%تسوية رصيد دائن ل%'
                        THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_expense,
                    (SELECT COALESCE(SUM(CASE WHEN type = 'transfer' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                    (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'transfer' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_transfer,
                    (SELECT COALESCE(SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                    (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' AND status = 'approved' AND (description NOT LIKE '%تسوية راتب%' OR description IS NULL) THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_payment,
                    (SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                    (SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS pending_total
            ");
            
            // حساب المعاملات المعلقة من financial_transactions و accountant_transactions
            $pendingStats = $db->queryOne("
                SELECT 
                    (SELECT COUNT(*) FROM financial_transactions WHERE status = 'pending') +
                    (SELECT COUNT(*) FROM accountant_transactions WHERE status = 'pending') AS total_pending,
                    (SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE status = 'pending') +
                    (SELECT COALESCE(SUM(amount), 0) FROM accountant_transactions WHERE status = 'pending') AS pending_amount
            ");
            
            $pendingTransactionsRaw = $db->query("
                SELECT id, type, amount, description, created_at 
                FROM financial_transactions
                WHERE status = 'pending'
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $pendingTransactions = is_array($pendingTransactionsRaw) ? $pendingTransactionsRaw : [];
            
            // حساب إجمالي المرتبات (المعتمدة والمدفوعة)
            $totalSalaries = 0.0;
            $salariesTableExists = $db->queryOne("SHOW TABLES LIKE 'salaries'");
            if (!empty($salariesTableExists)) {
                $salariesResult = $db->queryOne(
                    "SELECT COALESCE(SUM(total_amount), 0) as total_salaries
                     FROM salaries
                     WHERE status IN ('approved', 'paid')"
                );
                $totalSalaries = (float) ($salariesResult['total_salaries'] ?? 0);
            }
            
            // حساب إجمالي تسويات المرتبات (يشمل التسويات والسلف)
            $totalSalaryAdjustments = 0.0;
            $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
            if (!empty($accountantTableExists)) {
                $adjustmentsResult = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_adjustments
                     FROM accountant_transactions
                     WHERE status = 'approved'
                     AND (
                         (transaction_type = 'payment' AND description LIKE '%تسوية راتب%')
                         OR (transaction_type = 'expense' AND (description LIKE '%سلفة%' OR description LIKE '%سلف%'))
                     )"
                );
                $totalSalaryAdjustments = (float) ($adjustmentsResult['total_adjustments'] ?? 0);
            }
            
            // حساب إجمالي تسويات أرصدة العملاء
            $totalCustomerCreditSettlements = 0.0;
            if (!empty($accountantTableExists)) {
                $customerSettlementsResult = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_settlements
                     FROM accountant_transactions
                     WHERE transaction_type = 'expense' 
                     AND status = 'approved'
                     AND (description LIKE '%تسوية رصيد دائن لعميل محلي%' OR description LIKE '%تسوية رصيد دائن لعميل مندوب%')"
                );
                $totalCustomerCreditSettlements = (float) ($customerSettlementsResult['total_settlements'] ?? 0);
            }
            
            // حساب إجمالي توريدات الإدارة
            $totalManagementSupplies = 0.0;
            if (!empty($accountantTableExists)) {
                $managementSuppliesResult = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_supplies
                     FROM accountant_transactions
                     WHERE transaction_type = 'income' 
                     AND status = 'approved'
                     AND description LIKE '%تحصيل للإدارة%'"
                );
                $totalManagementSupplies = (float) ($managementSuppliesResult['total_supplies'] ?? 0);
            }
            
            $netApprovedBalance = 
                ($treasurySummary['approved_income'] ?? 0) 
                - ($treasurySummary['approved_expense'] ?? 0)
                - ($treasurySummary['approved_payment'] ?? 0)
                - $totalSalaries
                - $totalSalaryAdjustments
                - $totalCustomerCreditSettlements
                - $totalManagementSupplies;
            
            $approvedIncome = (float) ($treasurySummary['approved_income'] ?? 0);
            $approvedExpense = (float) ($treasurySummary['approved_expense'] ?? 0);
            $approvedPayment = (float) ($treasurySummary['approved_payment'] ?? 0);
            
            $movementTotal = $approvedIncome + $approvedExpense + $approvedPayment + $totalSalaries + $totalSalaryAdjustments + $totalCustomerCreditSettlements + $totalManagementSupplies;
            $shareDenominator = $movementTotal > 0 ? $movementTotal : 1;
            $incomeShare = $shareDenominator > 0 ? round(($approvedIncome / $shareDenominator) * 100) : 0;
            $expenseShare = $shareDenominator > 0 ? round(($approvedExpense / $shareDenominator) * 100) : 0;
            $paymentShare = $shareDenominator > 0 ? round(($approvedPayment / $shareDenominator) * 100) : 0;
            $salariesShare = $shareDenominator > 0 ? round(($totalSalaries / $shareDenominator) * 100) : 0;
            $adjustmentsShare = $shareDenominator > 0 ? round(($totalSalaryAdjustments / $shareDenominator) * 100) : 0;
            $customerSettlementsShare = $shareDenominator > 0 ? round(($totalCustomerCreditSettlements / $shareDenominator) * 100) : 0;
            $managementSuppliesShare = $shareDenominator > 0 ? round(($totalManagementSupplies / $shareDenominator) * 100) : 0;
            $pendingCount = intval($pendingStats['total_pending'] ?? 0);
            $pendingAmount = (float) ($pendingStats['pending_amount'] ?? 0);
            $pendingPreview = array_slice($pendingTransactions, 0, 3);
            
            $typeLabelMap = [
                'income' => $lang['income'] ?? 'إيراد',
                'expense' => $lang['expense'] ?? 'مصروف',
                'transfer' => isset($lang['transfer']) ? $lang['transfer'] : 'تحويل',
                'payment' => isset($lang['payment']) ? $lang['payment'] : 'دفعة'
            ];
            
            $typeColorMap = [
                'income' => 'success',
                'expense' => 'danger',
                'transfer' => 'primary',
                'payment' => 'warning'
            ];
            }
            ?>
            
            <div class="row g-3 mt-4">
                <div class="col-12 col-xxl-7">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-graph-up-arrow me-2 text-primary"></i>ملخص الخزنة</span>
                            <span class="badge bg-primary text-white">محدّث</span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                                <div>
                                    <span class="text-muted text-uppercase small">صافي الرصيد المعتمد</span>
                                    <div class="display-6 fw-bold mt-1"><?php echo formatCurrency($netApprovedBalance); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="badge bg-success text-white fw-semibold px-3 py-2">
                                        <?php echo formatCurrency($approvedIncome); ?> إيرادات
                                    </div>
                                </div>
                            </div>
                            <div class="row g-3 mt-3">
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">إيرادات معتمدة</span>
                                            <i class="bi bi-arrow-up-right-circle text-success"></i>
                                        </div>
                                        <div class="h5 text-success mt-2"><?php echo formatCurrency($approvedIncome); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo max(0, min(100, $incomeShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $incomeShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">مصروفات معتمدة</span>
                                            <i class="bi bi-arrow-down-right-circle text-danger"></i>
                                        </div>
                                        <div class="h5 text-danger mt-2"><?php echo formatCurrency($approvedExpense); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo max(0, min(100, $expenseShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $expenseShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">مدفوعات الموردين</span>
                                            <i class="bi bi-credit-card-2-back text-warning"></i>
                                        </div>
                                        <div class="h5 text-warning mt-2"><?php echo formatCurrency($approvedPayment); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo max(0, min(100, $paymentShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $paymentShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                                
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">تسويات المرتبات</span>
                                            <i class="bi bi-currency-exchange text-info"></i>
                                        </div>
                                        <div class="h5 text-info mt-2"><?php echo formatCurrency($totalSalaryAdjustments); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo max(0, min(100, $adjustmentsShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $adjustmentsShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">تسويات أرصدة العملاء</span>
                                            <i class="bi bi-wallet2 text-secondary"></i>
                                        </div>
                                        <div class="h5 text-secondary mt-2"><?php echo formatCurrency($totalCustomerCreditSettlements); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-secondary" role="progressbar" style="width: <?php echo max(0, min(100, $customerSettlementsShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $customerSettlementsShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">توريدات للإدارة</span>
                                            <i class="bi bi-building text-dark"></i>
                                        </div>
                                        <div class="h5 text-dark mt-2"><?php echo formatCurrency($totalManagementSupplies); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-dark" role="progressbar" style="width: <?php echo max(0, min(100, $managementSuppliesShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $managementSuppliesShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                            </div>
                           
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xxl-5">
                    <div class="row g-3">
                        <!-- تسجيل مصروف سريع -->
                        <div class="col-12 col-lg-12 col-xxl-12">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-light fw-bold">
                                    <i class="bi bi-pencil-square me-2 text-success"></i>تسجيل مصروف سريع
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="row g-3">
                                        <input type="hidden" name="action" value="add_quick_expense">
                                        <div class="col-12">
                                            <label for="quickExpenseAmount" class="form-label">قيمة المصروف <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">ج.م</span>
                                                <input type="number" step="0.01" min="0.01" class="form-control" id="quickExpenseAmount" name="amount" required value="<?php echo htmlspecialchars($financialFormData['amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label for="quickExpenseReference" class="form-label">رقم مرجعي</label>
                                            <?php
                                            $generatedRef = 'REF-' . mt_rand(100000, 999999);?>
                                            <input type="text" class="form-control" id="quickExpenseReference" name="reference_number" value="<?php echo $generatedRef; ?>" readonly style="background:#f5f5f5; cursor:not-allowed;">
                                        </div>
                                        <div class="col-12">
                                            <label for="quickExpenseDescription" class="form-label">وصف المصروف <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="quickExpenseDescription" name="description" rows="3" required placeholder="أدخل تفاصيل المصروف..."><?php echo htmlspecialchars($financialFormData['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                        </div>
                                        <div class="col-12 d-flex justify-content-end gap-2">
                                            <button type="reset" class="btn btn-outline-secondary">تفريغ الحقول</button>
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-send me-1"></i>حفظ المصروف
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- تحصيل من مندوب -->
                        <div class="col-12 col-lg-12 col-xxl-12">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-light fw-bold">
                                    <i class="bi bi-cash-coin me-2 text-primary"></i>تحصيل من مندوب
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="collectFromRepCardForm" class="row g-3">
                                        <input type="hidden" name="action" value="collect_from_sales_rep">
                                        <div class="col-12">
                                            <label for="collectFromRepCardSalesRepSelect" class="form-label">اختر المندوب <span class="text-danger">*</span></label>
                                            <select class="form-select" id="collectFromRepCardSalesRepSelect" name="sales_rep_id" required>
                                                <option value="">-- اختر المندوب --</option>
                                                <?php
                                                $salesReps = $db->query("
                                                    SELECT id, username, full_name 
                                                    FROM users 
                                                    WHERE role = 'sales' AND status = 'active'
                                                    ORDER BY full_name ASC, username ASC
                                                ") ?: [];
                                                foreach ($salesReps as $rep):
                                                ?>
                                                    <option value="<?php echo $rep['id']; ?>">
                                                        <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-12">
                                            <label for="collectFromRepCardRepBalanceAmount" class="form-label">رصيد المندوب</label>
                                            <div class="input-group">
                                                <span class="input-group-text">ج.م</span>
                                                <input type="text" class="form-control" id="collectFromRepCardRepBalanceAmount" readonly value="-- اختر مندوب أولاً --" style="background:#f5f5f5; cursor:not-allowed; font-weight: bold;">
                                            </div>
                                        </div>
                                        
                                        <div class="col-12">
                                            <label for="collectFromRepCardAmount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">ج.م</span>
                                                <input type="number" step="0.01" min="0.01" class="form-control" id="collectFromRepCardAmount" name="amount" required placeholder="أدخل المبلغ">
                                            </div>
                                            <small class="text-muted d-block mt-1">يجب أن يكون المبلغ أقل من أو يساوي رصيد المندوب</small>
                                        </div>
                                        
                                        <div class="col-12 d-flex justify-content-end gap-2">
                                            <button type="reset" class="btn btn-outline-secondary">تفريغ الحقول</button>
                                            <button type="submit" class="btn btn-primary" id="collectFromRepCardSubmitBtn">
                                                <i class="bi bi-check-circle me-1"></i>تحصيل
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- إنشاء تقرير تفصيلي -->
                        <div class="col-12 col-lg-12 col-xxl-12">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-light fw-bold">
                                    <i class="bi bi-file-earmark-text me-2 text-success"></i>إنشاء تقرير تفصيلي
                                </div>
                                <div class="card-body">
                                    <form method="GET" id="generateReportCardForm" onsubmit="return handleReportCardSubmit(event)" class="row g-3">
                                        <div class="col-12">
                                            <div class="alert alert-info mb-0">
                                                <i class="bi bi-info-circle me-2"></i>
                                                <small><strong>ملاحظة:</strong> سيتم إنشاء تقرير تفصيلي لجميع حركات خزنة الشركة في الفترة المحددة.</small>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label for="generateReportCardDateFrom" class="form-label">
                                                <i class="bi bi-calendar-event me-1"></i>من تاريخ <span class="text-danger">*</span>
                                            </label>
                                            <input type="date" 
                                                   class="form-control" 
                                                   id="generateReportCardDateFrom" 
                                                   name="date_from" 
                                                   required
                                                   value="<?php echo date('Y-m-01'); ?>">
                                        </div>
                                        <div class="col-12">
                                            <label for="generateReportCardDateTo" class="form-label">
                                                <i class="bi bi-calendar-event me-1"></i>إلى تاريخ <span class="text-danger">*</span>
                                            </label>
                                            <input type="date" 
                                                   class="form-control" 
                                                   id="generateReportCardDateTo" 
                                                   name="date_to" 
                                                   required
                                                   value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="generateReportCardIncludePending" name="include_pending" value="1">
                                                <label class="form-check-label" for="generateReportCardIncludePending">
                                                    تضمين المعاملات المعلقة
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="generateReportCardGroupByType" name="group_by_type" value="1" checked>
                                                <label class="form-check-label" for="generateReportCardGroupByType">
                                                    تجميع الحركات حسب النوع
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-12 d-flex justify-content-end gap-2">
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-file-earmark-pdf me-1"></i>إنشاء التقرير
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- جدول الحركات المالية -->
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-ul me-2 text-primary"></i>الحركات المالية</span>
                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#advancedSearchCollapse" aria-expanded="false" aria-controls="advancedSearchCollapse">
                        <i class="bi bi-funnel me-1"></i>بحث متقدم
                    </button>
                </div>
                <div class="card-body">
                    <!-- نموذج البحث المتقدم -->
                    <div class="collapse mb-4" id="advancedSearchCollapse">
                        <div class="card card-body bg-light">
                            <form method="GET" action="" id="advancedSearchForm">
                                <input type="hidden" name="page" value="financial">
                                <div class="row g-3">
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label for="searchType" class="form-label">نوع الحركة</label>
                                        <select class="form-select" id="searchType" name="search_type">
                                            <option value="">جميع الأنواع</option>
                                            <option value="income" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'income') ? 'selected' : ''; ?>>إيراد</option>
                                            <option value="expense" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'expense') ? 'selected' : ''; ?>>مصروف</option>
                                            <option value="transfer" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'transfer') ? 'selected' : ''; ?>>تحويل</option>
                                            <option value="payment" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'payment') ? 'selected' : ''; ?>>دفعة</option>
                                            <option value="other" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'other') ? 'selected' : ''; ?>>أخرى</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label for="searchDateFrom" class="form-label">من تاريخ</label>
                                        <input type="date" class="form-control" id="searchDateFrom" name="search_date_from" value="<?php echo htmlspecialchars($_GET['search_date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label for="searchDateTo" class="form-label">إلى تاريخ</label>
                                        <input type="date" class="form-control" id="searchDateTo" name="search_date_to" value="<?php echo htmlspecialchars($_GET['search_date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label for="searchAmountFrom" class="form-label">من مبلغ</label>
                                        <input type="number" step="0.01" min="0" class="form-control" id="searchAmountFrom" name="search_amount_from" placeholder="0.00" value="<?php echo htmlspecialchars($_GET['search_amount_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label for="searchAmountTo" class="form-label">إلى مبلغ</label>
                                        <input type="number" step="0.01" min="0" class="form-control" id="searchAmountTo" name="search_amount_to" placeholder="0.00" value="<?php echo htmlspecialchars($_GET['search_amount_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label for="searchDescription" class="form-label">الوصف</label>
                                        <input type="text" class="form-control" id="searchDescription" name="search_description" placeholder="ابحث في الوصف..." value="<?php echo htmlspecialchars($_GET['search_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label for="searchReference" class="form-label">الرقم المرجعي</label>
                                        <input type="text" class="form-control" id="searchReference" name="search_reference" placeholder="ابحث في الرقم المرجعي..." value="<?php echo htmlspecialchars($_GET['search_reference'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label for="searchCreatedBy" class="form-label">أنشأه</label>
                                        <select class="form-select" id="searchCreatedBy" name="search_created_by">
                                            <option value="">الجميع</option>
                                            <?php
                                            $allUsers = $db->query("SELECT id, full_name, username FROM users WHERE status = 'active' ORDER BY full_name ASC, username ASC") ?: [];
                                            foreach ($allUsers as $user):
                                                $selected = (isset($_GET['search_created_by']) && $_GET['search_created_by'] == $user['id']) ? 'selected' : '';
                                                $displayName = htmlspecialchars($user['full_name'] ?? $user['username'], ENT_QUOTES, 'UTF-8');
                                            ?>
                                                <option value="<?php echo $user['id']; ?>" <?php echo $selected; ?>><?php echo $displayName; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-search me-1"></i>بحث
                                            </button>
                                            <a href="?page=accountant_cash" class="btn btn-outline-secondary">
                                                <i class="bi bi-x-circle me-1"></i>إعادة تعيين
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div id="accountant-financial-transactions-list">
                    <?php echo getAccountantFinancialTransactionsHtml($db); ?>
                    </div>
                </div>
            </div>
            
            <!-- Modal تحصيل من مندوب -->
            <div class="modal fade" id="collectFromRepModal" tabindex="-1" aria-labelledby="collectFromRepModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="collectFromRepModalLabel">
                                <i class="bi bi-cash-coin me-2"></i>تحصيل من مندوب
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="POST" id="collectFromRepForm">
                            <input type="hidden" name="action" value="collect_from_sales_rep">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="salesRepSelect" class="form-label">اختر المندوب <span class="text-danger">*</span></label>
                                    <select class="form-select" id="salesRepSelect" name="sales_rep_id" required>
                                        <option value="">-- اختر المندوب --</option>
                                        <?php
                                        $salesReps = $db->query("
                                            SELECT id, username, full_name 
                                            FROM users 
                                            WHERE role = 'sales' AND status = 'active'
                                            ORDER BY full_name ASC, username ASC
                                        ") ?: [];
                                        foreach ($salesReps as $rep):
                                        ?>
                                            <option value="<?php echo $rep['id']; ?>">
                                                <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="repBalanceAmount" class="form-label">رصيد المندوب</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-wallet2 me-1"></i>رصيد المندوب</span>
                                        <input type="text" class="form-control" id="repBalanceAmount" readonly value="-- اختر مندوب أولاً --" style="background-color: #f8f9fa; font-weight: bold;">
                                        <span class="input-group-text">ج.م</span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="collectAmount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">ج.م</span>
                                        <input type="number" step="0.01" min="0.01" class="form-control" id="collectAmount" name="amount" required placeholder="أدخل المبلغ">
                                    </div>
                                    <small class="text-muted">يجب أن يكون المبلغ أقل من أو يساوي رصيد المندوب</small>
                                </div>
                                
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="submit" class="btn btn-primary" id="submitCollectBtn">
                                    <i class="bi bi-check-circle me-1"></i>تحصيل
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Modal إنشاء تقرير تفصيلي -->
            <div class="modal fade" id="generateReportModal" tabindex="-1" aria-labelledby="generateReportModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title" id="generateReportModalLabel">
                                <i class="bi bi-file-earmark-text me-2"></i>إنشاء تقرير تفصيلي
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="GET" id="reportForm" onsubmit="return handleReportSubmit(event)">
                            <div class="modal-body">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>ملاحظة:</strong> سيتم إنشاء تقرير تفصيلي لجميع حركات خزنة الشركة في الفترة المحددة.
                                </div>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label for="reportDateFrom" class="form-label">
                                            <i class="bi bi-calendar-event me-1"></i>من تاريخ <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" 
                                               class="form-control" 
                                               id="reportDateFrom" 
                                               name="date_from" 
                                               required
                                               value="<?php echo date('Y-m-01'); ?>">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label for="reportDateTo" class="form-label">
                                            <i class="bi bi-calendar-event me-1"></i>إلى تاريخ <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" 
                                               class="form-control" 
                                               id="reportDateTo" 
                                               name="date_to" 
                                               required
                                               value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="includePending" name="include_pending" value="1">
                                            <label class="form-check-label" for="includePending">
                                                تضمين المعاملات المعلقة
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="groupByType" name="group_by_type" value="1" checked>
                                            <label class="form-check-label" for="groupByType">
                                                تجميع الحركات حسب النوع
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>إنشاء التقرير
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Modal إنشاء تقرير تفصيلي -->
            <div class="modal fade" id="generateReportModal" tabindex="-1" aria-labelledby="generateReportModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title" id="generateReportModalLabel">
                                <i class="bi bi-file-earmark-text me-2"></i>إنشاء تقرير تفصيلي
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="GET" id="reportForm" onsubmit="return handleReportSubmit(event)">
                            <div class="modal-body">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>ملاحظة:</strong> سيتم إنشاء تقرير تفصيلي لجميع حركات خزنة الشركة في الفترة المحددة.
                                </div>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label for="reportDateFrom" class="form-label">
                                            <i class="bi bi-calendar-event me-1"></i>من تاريخ <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" 
                                               class="form-control" 
                                               id="reportDateFrom" 
                                               name="date_from" 
                                               required
                                               value="<?php echo date('Y-m-01'); ?>">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label for="reportDateTo" class="form-label">
                                            <i class="bi bi-calendar-event me-1"></i>إلى تاريخ <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" 
                                               class="form-control" 
                                               id="reportDateTo" 
                                               name="date_to" 
                                               required
                                               value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="includePending" name="include_pending" value="1">
                                            <label class="form-check-label" for="includePending">
                                                تضمين المعاملات المعلقة
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="groupByType" name="group_by_type" value="1" checked>
                                            <label class="form-check-label" for="groupByType">
                                                تجميع الحركات حسب النوع
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>إنشاء التقرير
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <style>
            /* إصلاح شامل للمساحة البيضاء في النماذج */
            #generateReportModal .modal-dialog.modal-dialog-centered,
            #collectFromRepModal .modal-dialog.modal-dialog-centered {
                margin: 0.5rem auto;
                display: flex;
                flex-direction: column;
                max-height: calc(100vh - 1rem);
            }
            #generateReportModal .modal-content,
            #collectFromRepModal .modal-content {
                display: flex !important;
                flex-direction: column !important;
                height: auto !important;
                max-height: 100% !important;
                overflow: hidden !important;
            }
            #generateReportModal .modal-body,
            #collectFromRepModal .modal-body {
                flex: 0 1 auto !important;
                flex-grow: 0 !important;
                flex-shrink: 1 !important;
                flex-basis: auto !important;
                min-height: 0 !important;
                height: auto !important;
                max-height: none !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                padding-bottom: 1rem !important;
                margin-bottom: 0 !important;
            }
            #generateReportModal .modal-header,
            #collectFromRepModal .modal-header {
                flex-shrink: 0 !important;
                flex-grow: 0 !important;
            }
            #generateReportModal .modal-footer,
            #collectFromRepModal .modal-footer {
                flex-shrink: 0 !important;
                flex-grow: 0 !important;
                margin-top: 0 !important;
                margin-bottom: 0 !important;
                padding-top: 1rem !important;
                padding-bottom: 1rem !important;
                border-top: 1px solid #dee2e6 !important;
            }
            #generateReportModal .modal-content::after,
            #collectFromRepModal .modal-content::after,
            #generateReportModal .modal-content::before,
            #collectFromRepModal .modal-content::before {
                display: none !important;
                content: none !important;
            }
            #generateReportModal .modal-dialog.modal-dialog-scrollable .modal-content,
            #collectFromRepModal .modal-dialog.modal-dialog-scrollable .modal-content {
                max-height: 100% !important;
                overflow: hidden !important;
            }
            #generateReportModal .modal-dialog.modal-dialog-scrollable .modal-body,
            #collectFromRepModal .modal-dialog.modal-dialog-scrollable .modal-body {
                flex: 0 1 auto !important;
                overflow-y: auto !important;
                max-height: calc(100vh - 250px) !important;
            }
            @media (max-width: 768px) {
                #generateReportModal .modal-dialog,
                #collectFromRepModal .modal-dialog {
                    margin: 0.5rem !important;
                    max-width: calc(100% - 1rem) !important;
                    max-height: calc(100vh - 1rem) !important;
                    height: auto !important;
                }
                #generateReportModal .modal-content,
                #collectFromRepModal .modal-content {
                    max-height: calc(100vh - 1rem) !important;
                    height: auto !important;
                }
                #collectFromRepModal .modal-body {
                    flex: 0 1 auto !important;
                    flex-grow: 0 !important;
                    padding-bottom: 1rem !important;
                    max-height: none !important;
                    height: auto !important;
                    overflow-y: visible !important;
                }
                #generateReportModal .modal-body {
                    flex: 0 1 auto !important;
                    flex-grow: 0 !important;
                    padding-bottom: 1rem !important;
                    max-height: calc(100vh - 250px) !important;
                    overflow-y: auto !important;
                    height: auto !important;
                }
                #generateReportModal .modal-footer,
                #collectFromRepModal .modal-footer {
                    flex-shrink: 0 !important;
                    flex-grow: 0 !important;
                    margin-top: 0 !important;
                    padding-top: 1rem !important;
                    padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px)) !important;
                }
                #collectFromRepModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body {
                    overflow-y: visible !important;
                    max-height: none !important;
                }
            }
            </style>
            <?php endif; ?>
                
            <?php elseif ($page === 'accountant_cash'): ?>
                <!-- صفحة خزنة المحاسب - نفس صفحة خزنة الشركة (company_cash) التي تسمح بدور المحاسب -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/company_cash.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة خزنة المحاسب غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'user_wallets_control'): ?>
                <!-- صفحة التحكم في محافظ المستخدمين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/user_wallets_control.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة التحكم في محافظ المستخدمين غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'suppliers'): ?>
                <!-- صفحة الموردين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/suppliers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة الموردين غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'orders'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/customer_orders.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant orders module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة طلبات العملاء: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة طلبات العملاء غير متاحة حالياً</div>';
                }
                ?>
                
                
            <?php elseif ($page === 'invoices'): ?>
                <!-- صفحة الفواتير -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/invoices.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'collections'): ?>
                <!-- صفحة التحصيلات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/collections.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<h2><i class="bi bi-cash-coin me-2"></i>' . (isset($lang['collections']) ? $lang['collections'] : 'التحصيلات') . '</h2>';
                    echo '<div class="card shadow-sm"><div class="card-body"><p>صفحة التحصيلات - سيتم إضافتها</p></div></div>';
                }
                ?>
                
            <?php elseif ($page === 'salaries'): ?>
                <!-- صفحة الرواتب -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/salaries.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    include __DIR__ . '/../modules/accountant/salaries.php';
                }
                ?>
                
            <?php elseif ($page === 'my_salary'): ?>
                <!-- صفحة مرتب المستخدم -->
                <?php 
                $modulePath = __DIR__ . '/../modules/user/my_salary.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'company_products'): ?>
                <!-- صفحة منتجات الشركة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/company_products.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant company products module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة منتجات الشركة: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة منتجات الشركة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'product_storage'): ?>
                <!-- صفحة تشوين المنتجات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/shared/product_storage.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant product_storage error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة تشوين المنتجات.</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة تشوين المنتجات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'transferred_to_me'): ?>
                <!-- صفحة المنتجات المنقولة لي -->
                <?php 
                $modulePath = __DIR__ . '/../modules/shared/transferred_to_me.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant transferred_to_me error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة المنتجات المنقولة لي.</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة المنتجات المنقولة لي غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'pos'): ?>
                <!-- صفحة نقطة البيع -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/pos.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant POS module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة نقطة البيع: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة نقطة البيع غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'rep_customers_view'): ?>
                <!-- صفحة عملاء المندوب -->
                <?php 
                $modulePath = __DIR__ . '/../modules/shared/rep_customers_view.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant rep customers view error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">تعذر تحميل صفحة عملاء المندوب: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة عرض عملاء المندوب غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'representatives_customers'): ?>
                <!-- صفحة عملاء المندوبين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/representatives_customers.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant representatives customers module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة عملاء المندوبين: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة عملاء المندوبين غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'attendance'): ?>
                <!-- صفحة الحضور -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/attendance.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'attendance_management'): ?>
                <!-- صفحة متابعة الحضور مع الإحصائيات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/attendance_management.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'advance_requests'): ?>
                <!-- صفحة طلبات السلفة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/advance_requests.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'packaging_warehouse'): ?>
                <!-- صفحة مخزن أدوات التعبئة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن أدوات التعبئة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'factory_waste_warehouse'): ?>
                <!-- صفحة مخزن توالف المصنع -->
                <?php 
                $modulePath = __DIR__ . '/../modules/warehouse/factory_waste_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن توالف المصنع غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'raw_materials_warehouse'): ?>
                <!-- صفحة مخزن الخامات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/raw_materials_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن الخامات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'batch_reader'): ?>
                <!-- صفحة قارئ أرقام التشغيلات -->
                <div class="container-fluid p-0" style="height: 100vh; overflow: hidden;">
                    <iframe src="<?php echo getRelativeUrl('reader/index.php'); ?>" 
                            style="width: 100%; height: 100%; border: none; display: block;"
                            data-no-loading="true"></iframe>
                </div>
                <script>
                    // منع ظهور شاشة التحميل عند تحميل iframe
                    (function() {
                        const iframe = document.querySelector('iframe[data-no-loading="true"]');
                        if (iframe) {
                            // إخفاء شاشة التحميل فوراً عند تحميل iframe
                            const loadingOverlay = document.getElementById('professionalLoadingOverlay');
                            if (loadingOverlay) {
                                loadingOverlay.style.display = 'none';
                                loadingOverlay.style.opacity = '0';
                                loadingOverlay.classList.remove('show');
                                loadingOverlay.setAttribute('aria-hidden', 'true');
                            }
                        }
                    })();
                </script>
                
            <?php elseif ($page === 'company_payment_schedules'): ?>
                <!-- صفحة جداول التحصيل - عملاء الشركة -->
                <?php
                $modulePath = __DIR__ . '/../modules/manager/company_payment_schedules.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-danger">الصفحة غير متاحة حالياً.</div>';
                }
                ?>

            <?php elseif ($page === 'local_customers'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/manager/local_customers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة العملاء المحليين غير متاحة حالياً</div>';
                }
                ?>

            <?php elseif ($page === 'custom_prices'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/manager/custom_prices.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة الأسعار المخصصة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'shipping_orders'): ?>
                <!-- صفحة طلبات الشحن -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/shipping_orders.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant shipping orders module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة طلبات الشحن: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة طلبات الشحن غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'production_tasks'): ?>
                <!-- صفحة تسجيل مهام الإنتاج -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/production_tasks.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant production tasks module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة مهام الإنتاج: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة تسجيل مهام الإنتاج غير متاحة حالياً</div>';
                }
                ?>

            <?php elseif ($page === 'driver_tracking'): ?>
                <!-- صفحة تتبع الموقع المباشر للسائقين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/driver_tracking.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة تتبع السائقين غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'returns'): ?>
                <!-- صفحة المرتجعات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/returns.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant returns module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة المرتجعات: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة المرتجعات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'customer_credit_balances'): ?>
                <!-- صفحة أرصدة العملاء الدائنة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/customer_credit_balances.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant customer credit balances module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة أرصدة العملاء الدائنة: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة أرصدة العملاء الدائنة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'warehouse_transfers'): ?>
                <!-- صفحة نقل المخازن -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/warehouse_transfers.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant warehouse transfers module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة نقل المخازن: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة نقل المخازن غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'vehicles'): ?>
                <!-- صفحة السيارات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/vehicles.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant vehicles module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة السيارات: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة السيارات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'vehicle_maintenance'): ?>
                <!-- صفحة صيانات السيارة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/driver/vehicle_maintenance.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant vehicle maintenance module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة صيانات السيارة: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة صيانات السيارة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'production_reports'): ?>
                <!-- صفحة التقارير -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/production_reports.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant production reports module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة التقارير: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة التقارير غير متاحة حالياً</div>';
                }
                ?>
                
            <?php endif; ?>

<?php if ($page === 'financial'): ?>
<script>
// معالجة إرسال نموذج التقرير (يجب أن تكون في النطاق العام)
function handleReportSubmit(event) {
    event.preventDefault();
    
    const form = document.getElementById('reportForm');
    if (!form) return false;
    
    const dateFrom = document.getElementById('reportDateFrom');
    const dateTo = document.getElementById('reportDateTo');
    
    if (!dateFrom || !dateTo) return false;
    
    const fromDate = new Date(dateFrom.value);
    const toDate = new Date(dateTo.value);
    
    if (fromDate > toDate) {
        alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
        dateFrom.focus();
        return false;
    }
    
    // بناء URL للتقرير
    // استخدام window.location.origin للحصول على النطاق
    const origin = window.location.origin;
    const currentPath = window.location.pathname;
    
    // استخراج المسار الأساسي (إزالة dashboard/accountant.php أو أي مسار آخر)
    let basePath = currentPath;
    // إزالة /dashboard/accountant.php أو /dashboard/manager.php
    basePath = basePath.replace(/\/dashboard\/[^\/]+\.php.*$/, '');
    // إزالة /modules/manager/company_cash.php إذا كان موجوداً
    basePath = basePath.replace(/\/modules\/[^\/]+\/[^\/]+\.php.*$/, '');
    
    // تنظيف المسار
    basePath = basePath.replace(/\/$/, ''); // إزالة / من النهاية
    if (!basePath) {
        basePath = '';
    }
    
    // بناء URL للتقرير
    const reportUrl = origin + basePath + '/print_company_cash_report.php';
    
    // جمع معاملات النموذج
    const formData = new FormData(form);
    const params = new URLSearchParams();
    
    for (const [key, value] of formData.entries()) {
        params.append(key, value);
    }
    
    // إضافة checkboxes غير المحددة كقيم فارغة
    const includePending = document.getElementById('includePending');
    const groupByType = document.getElementById('groupByType');
    
    if (!includePending.checked) {
        params.delete('include_pending');
    }
    if (!groupByType.checked) {
        params.delete('group_by_type');
    }
    
    // فتح التقرير في تبويب جديد
    const fullUrl = reportUrl + '?' + params.toString();
    console.log('Opening report URL:', fullUrl); // للتشخيص
    window.open(fullUrl, '_blank');
    
    // إغلاق Modal
    const modalElement = document.getElementById('generateReportModal');
    if (modalElement) {
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    }
    
    return false;
}

// معالجة إرسال نموذج التقرير من Card
function handleReportCardSubmit(event) {
    event.preventDefault();
    
    const form = document.getElementById('generateReportCardForm');
    if (!form) return false;
    
    const dateFrom = document.getElementById('generateReportCardDateFrom');
    const dateTo = document.getElementById('generateReportCardDateTo');
    
    if (!dateFrom || !dateTo) return false;
    
    const fromDate = new Date(dateFrom.value);
    const toDate = new Date(dateTo.value);
    
    if (fromDate > toDate) {
        alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
        dateFrom.focus();
        return false;
    }
    
    // بناء URL للتقرير
    const origin = window.location.origin;
    const currentPath = window.location.pathname;
    
    let basePath = currentPath;
    basePath = basePath.replace(/\/dashboard\/[^\/]+\.php.*$/, '');
    basePath = basePath.replace(/\/modules\/[^\/]+\/[^\/]+\.php.*$/, '');
    basePath = basePath.replace(/\/$/, '');
    if (!basePath) {
        basePath = '';
    }
    
    const reportUrl = origin + basePath + '/print_company_cash_report.php';
    
    // جمع معاملات النموذج
    const formData = new FormData(form);
    const params = new URLSearchParams();
    
    for (const [key, value] of formData.entries()) {
        params.append(key, value);
    }
    
    const includePending = document.getElementById('generateReportCardIncludePending');
    const groupByType = document.getElementById('generateReportCardGroupByType');
    
    if (!includePending.checked) {
        params.delete('include_pending');
    }
    if (!groupByType.checked) {
        params.delete('group_by_type');
    }
    
    // فتح التقرير في تبويب جديد
    const fullUrl = reportUrl + '?' + params.toString();
    window.open(fullUrl, '_blank');
    
    // إعادة تعيين النموذج بعد فتح التقرير
    if (form) {
        form.reset();
        // إعادة تعيين القيم الافتراضية
        if (dateFrom) dateFrom.value = '<?php echo date('Y-m-01'); ?>';
        if (dateTo) dateTo.value = '<?php echo date('Y-m-d'); ?>';
        if (groupByType) groupByType.checked = true;
    }
    
    return false;
}

// دالة مشتركة لجلب رصيد المندوب
function loadSalesRepBalance(salesRepId, repBalanceElement, collectAmountElement) {
    if (!salesRepId || salesRepId === '') {
        if (repBalanceElement) {
            repBalanceElement.value = '-- اختر مندوب أولاً --';
            repBalanceElement.style.color = '#6c757d';
        }
        if (collectAmountElement) {
            collectAmountElement.max = '';
            collectAmountElement.removeAttribute('data-max-balance');
        }
        return;
    }
    
    // إظهار loading state
    if (repBalanceElement) {
        repBalanceElement.value = 'جاري التحميل...';
        repBalanceElement.style.color = '#6c757d';
    }
    
    // جلب رصيد المندوب
    const url = new URL(window.location.href);
    url.searchParams.set('ajax', 'get_sales_rep_balance');
    url.searchParams.set('sales_rep_id', salesRepId);
    
    fetch(url.toString(), {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-cache'
    })
    .then(response => {
        const contentType = response.headers.get('content-type') || '';
        
        return response.text().then(text => {
            if (!contentType.includes('application/json')) {
                console.error('Server response (first 500 chars):', text.substring(0, 500));
                throw new Error('Invalid response type. Expected JSON but got: ' + contentType);
            }
            
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            
            if (!text || text.trim() === '') {
                throw new Error('Empty response from server');
            }
            
            try {
                return JSON.parse(text);
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError);
                console.error('Response text:', text.substring(0, 500));
                throw new Error('Invalid JSON response: ' + parseError.message);
            }
        });
    })
    .then(data => {
        if (!data || typeof data !== 'object') {
            throw new Error('Invalid response format');
        }
        
        if (data.success) {
            const balance = parseFloat(data.balance) || 0;
            const formattedBalance = balance.toLocaleString('ar-EG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            
            if (repBalanceElement) {
                repBalanceElement.value = formattedBalance;
                repBalanceElement.style.color = balance > 0 ? '#198754' : '#6c757d';
            }
            
            if (collectAmountElement) {
                collectAmountElement.max = balance;
                collectAmountElement.setAttribute('data-max-balance', balance);
            }
        } else {
            const errorMsg = data.message || 'فشل جلب رصيد المندوب';
            if (repBalanceElement) {
                repBalanceElement.value = 'خطأ: ' + errorMsg;
                repBalanceElement.style.color = '#dc3545';
            }
            console.error('Error:', errorMsg);
        }
    })
    .catch(error => {
        console.error('Fetch Error:', error);
        const errorMsg = error.message || 'حدث خطأ أثناء جلب رصيد المندوب';
        if (repBalanceElement) {
            repBalanceElement.value = 'خطأ في الاتصال';
            repBalanceElement.style.color = '#dc3545';
        }
    });
}

// معالجة تحصيل من مندوب
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded - Setting up collect from rep forms');
    
    // Modal elements
    const salesRepSelect = document.getElementById('salesRepSelect');
    const repBalanceAmount = document.getElementById('repBalanceAmount');
    const collectAmount = document.getElementById('collectAmount');
    const collectForm = document.getElementById('collectFromRepForm');
    const submitBtn = document.getElementById('submitCollectBtn');
    
    // Card elements
    const collectCardSalesRepSelect = document.getElementById('collectFromRepCardSalesRepSelect');
    const collectCardRepBalanceAmount = document.getElementById('collectFromRepCardRepBalanceAmount');
    const collectCardAmount = document.getElementById('collectFromRepCardAmount');
    const collectCardForm = document.getElementById('collectFromRepCardForm');
    const collectCardSubmitBtn = document.getElementById('collectFromRepCardSubmitBtn');
    
    console.log('Card elements found:', {
        select: !!collectCardSalesRepSelect,
        balance: !!collectCardRepBalanceAmount,
        amount: !!collectCardAmount,
        form: !!collectCardForm
    });
    
    // معالجة تغيير المندوب في Modal
    if (salesRepSelect) {
        salesRepSelect.addEventListener('change', function() {
            loadSalesRepBalance(this.value, repBalanceAmount, collectAmount);
        });
    }
    
    // معالجة تغيير المندوب في Card
    if (collectCardSalesRepSelect && collectCardRepBalanceAmount && collectCardAmount) {
        collectCardSalesRepSelect.addEventListener('change', function() {
            const salesRepId = this.value;
            loadSalesRepBalance(salesRepId, collectCardRepBalanceAmount, collectCardAmount);
        });
        
        // تحميل الرصيد تلقائياً إذا كان هناك قيمة محفوظة
        if (collectCardSalesRepSelect.value) {
            loadSalesRepBalance(collectCardSalesRepSelect.value, collectCardRepBalanceAmount, collectCardAmount);
        }
    }
    
    // دالة مشتركة للتحقق من المبلغ قبل الإرسال
    function validateCollectAmount(amountInput, maxBalance, submitButton) {
        const amount = parseFloat(amountInput.value);
        const maxBalanceValue = parseFloat(maxBalance || '0');
        
        if (amount <= 0) {
            alert('يرجى إدخال مبلغ صحيح أكبر من الصفر');
            amountInput.focus();
            return false;
        }
        
        if (maxBalanceValue > 0 && amount > maxBalanceValue) {
            alert('المبلغ المطلوب (' + amount.toLocaleString('ar-EG') + ' ج.م) أكبر من رصيد المندوب (' + maxBalanceValue.toLocaleString('ar-EG') + ' ج.م)');
            amountInput.focus();
            return false;
        }
        
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التحصيل...';
        }
        
        return true;
    }
    
    // التحقق من المبلغ قبل الإرسال - Modal
    if (collectForm) {
        collectForm.addEventListener('submit', function(e) {
            if (!validateCollectAmount(collectAmount, collectAmount.getAttribute('data-max-balance'), submitBtn)) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // التحقق من المبلغ قبل الإرسال - Card
    if (collectCardForm) {
        collectCardForm.addEventListener('submit', function(e) {
            if (!validateCollectAmount(collectCardAmount, collectCardAmount.getAttribute('data-max-balance'), collectCardSubmitBtn)) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // إعادة تعيين النموذج عند إغلاق Modal
    const collectModal = document.getElementById('collectFromRepModal');
    if (collectModal) {
        collectModal.addEventListener('hidden.bs.modal', function() {
            if (collectForm) {
                collectForm.reset();
            }
            // إعادة تعيين حقل الرصيد
            if (repBalanceAmount) {
                repBalanceAmount.value = '-- اختر مندوب أولاً --';
                repBalanceAmount.style.color = '#6c757d';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تحصيل';
            }
        });
    }

    // ===== تنقل سلس بين صفحات الحركات المالية (بدون ريفريش) =====
    var transactionsListEl = document.getElementById('accountant-financial-transactions-list');
    if (transactionsListEl) {
        function buildAjaxUrl(href) {
            if (!href || href === '#' || href === '') return null;
            var sep = href.indexOf('?') >= 0 ? '&' : '?';
            var withAjax = href + sep + 'ajax=transactions_list';
            if (withAjax.indexOf('http') === 0) return withAjax;
            var base = window.location.origin + window.location.pathname;
            return (withAjax.charAt(0) === '?' ? base + withAjax : base + '?' + withAjax);
        }
        function loadTransactionsList(url, pushState) {
            if (!url) return;
            transactionsListEl.style.opacity = '0.6';
            transactionsListEl.style.pointerEvents = 'none';
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-cache' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.html) {
                        transactionsListEl.innerHTML = data.html;
                        if (pushState !== false) {
                            try {
                                var u = new URL(url, window.location.origin);
                                u.searchParams.delete('ajax');
                                window.history.pushState({ financialList: true }, '', u.pathname + '?' + u.searchParams.toString());
                            } catch (e) {}
                        }
                    }
                })
                .catch(function() {})
                .finally(function() {
                    transactionsListEl.style.opacity = '';
                    transactionsListEl.style.pointerEvents = '';
                });
        }
        transactionsListEl.addEventListener('click', function(e) {
            var link = e.target.closest('a.page-link');
            if (!link || !link.href || link.closest('.page-item.disabled')) return;
            var href = link.getAttribute('href') || link.href || '';
            if (href.indexOf('page=financial') === -1) return;
            e.preventDefault();
            e.stopPropagation();
            var ajaxUrl = buildAjaxUrl(href);
            if (ajaxUrl) loadTransactionsList(ajaxUrl, true);
            return false;
        });
        window.addEventListener('popstate', function(e) {
            if (window.location.search.indexOf('page=financial') >= 0) {
                var ajaxUrl = buildAjaxUrl(window.location.href);
                if (ajaxUrl) loadTransactionsList(ajaxUrl, false);
            }
        });
    }
});
</script>
<?php endif; ?>

<script>
    // تمرير بيانات المستخدم للـ JavaScript
    window.currentUser = {
        id: <?php echo $currentUser['id']; ?>,
        role: '<?php echo htmlspecialchars($currentUser['role']); ?>'
    };
</script>
<script src="<?php echo ASSETS_URL; ?>js/attendance_notifications.js"></script>

<?php if (!$isAjaxNavigation): ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="<?php echo ASSETS_URL; ?>js/local_customers_search.js?v=2" defer></script>
<script src="<?php echo ASSETS_URL; ?>js/reports.js"></script>
<?php else: ?>
<?php
// إذا كان طلب AJAX، نعيد المحتوى فقط
$content = ob_get_clean();
// استخراج المحتوى من <main> فقط
if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $content, $matches)) {
    echo $matches[1];
} else {
    // Fallback: إرجاع كل المحتوى
    echo $content;
}
exit;
?>
<?php endif; ?>
<?php
// إرسال المحتوى من output buffer
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>

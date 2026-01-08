<?php
/**
 * إعدادات النظام العامة
 * نظام إدارة الشركات المتكامل
 */
//th
// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

/**
 * معالج الأخطاء الشامل - يمنع ظهور رسائل خطأ المتصفح ويرسل المستخدم لتسجيل الدخول
 */
function handleErrorAndRedirect($errno, $errstr, $errfile, $errline, $context = null) {
    // تجاهل الأخطاء التي تم إيقافها باستخدام @
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    // تجاهل بعض الأخطاء غير الحرجة
    $nonCriticalErrors = [E_NOTICE, E_WARNING, E_DEPRECATED];
    if (in_array($errno, $nonCriticalErrors, true)) {
        return false; // استمر التنفيذ للأخطاء غير الحرجة
    }
    
    // للأخطاء الحرجة (Fatal Errors، Parse Errors، إلخ) - إعادة التوجيه
    // تجنب الحلقات اللانهائية من خلال التحقق من أننا لسنا في صفحة تسجيل الدخول
    $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    if (strpos($currentScript, 'index.php') !== false && basename($currentScript) === 'index.php') {
        return false; // لا نعيد التوجيه إذا كنا بالفعل في صفحة تسجيل الدخول
    }
    
    // تم إزالة نظام الجلسات - لا حاجة لحفظ معلومات الخطأ في session
    
    // تنظيف output buffer
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // محاولة إعادة التوجيه إلى صفحة تسجيل الدخول
    $loginUrl = '/index.php';
    if (function_exists('getRelativeUrl')) {
        $loginUrl = getRelativeUrl('index.php');
    } else {
        // محاولة بناء URL نسبي بناءً على المسار الحالي
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
    }
    
    // تنظيف URL
    $loginUrl = preg_replace('/^https?:\/\/[^\/]+/', '', $loginUrl);
    $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
    if (strpos($loginUrl, '/') !== 0) {
        $loginUrl = '/' . $loginUrl;
    }
    
    // إرسال header redirect إذا أمكن
    if (!@headers_sent()) {
        @header('Location: ' . $loginUrl, true, 303);
        exit;
    } else {
        // استخدام JavaScript redirect إذا تم إرسال headers بالفعل
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
        echo '<script>window.location.replace("' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '");</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
        exit;
    }
}

/**
 * معالج الأخطاء القاتلة (Fatal Errors)
 */
function handleFatalError() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR], true)) {
        handleErrorAndRedirect($error['type'], $error['message'], $error['file'], $error['line']);
    }
}

/**
 * معالج الاستثناءات (Exceptions)
 */
function handleException($exception) {
    // تجنب الحلقات اللانهائية
    $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    if (strpos($currentScript, 'index.php') !== false && basename($currentScript) === 'index.php') {
        // إذا كنا في صفحة تسجيل الدخول، اعرض الخطأ بشكل طبيعي
        return false;
    }
    
    // تم إزالة نظام الجلسات - لا حاجة لحفظ معلومات الخطأ في session
    
    // تنظيف output buffer
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // إعادة التوجيه إلى صفحة تسجيل الدخول
    $loginUrl = '/index.php';
    if (function_exists('getRelativeUrl')) {
        $loginUrl = getRelativeUrl('index.php');
    }
    
    // تنظيف URL
    $loginUrl = preg_replace('/^https?:\/\/[^\/]+/', '', $loginUrl);
    $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
    if (strpos($loginUrl, '/') !== 0) {
        $loginUrl = '/' . $loginUrl;
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

// تسجيل معالجات الأخطاء (فقط إذا لم نكن في صفحة تسجيل الدخول)
$currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
$isLoginPage = (strpos($currentScript, 'index.php') !== false && basename($currentScript) === 'index.php');

if (!$isLoginPage) {
    // تسجيل معالج الأخطاء
    set_error_handler('handleErrorAndRedirect', E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
    
    // تسجيل معالج الأخطاء القاتلة
    register_shutdown_function('handleFatalError');
    
    // تسجيل معالج الاستثناءات
    set_exception_handler('handleException');
}

define('DB_PORT', '3306');
define('DB_NAME', '2');
define('DB_PASS', '5s9tuW25_');
define('DB_CHARSET', 'utf8mb4');
define('DB_HOST', 'localhost:3306');
define('DB_USER', 'osama744');

// إعدادات المنطقة الزمنية - مصر/القاهرة   
date_default_timezone_set('Africa/Cairo');

// إعدادات اللغة
if (!defined('DEFAULT_LANGUAGE')) {
    define('DEFAULT_LANGUAGE', 'ar');
}
if (!defined('SUPPORTED_LANGUAGES')) {
    define('SUPPORTED_LANGUAGES', ['ar', 'en']);
}

// إعدادات العملة
if (!defined('CURRENCY')) {
    define('CURRENCY', 'جنيه');
}
if (!defined('CURRENCY_SYMBOL')) {
    // تنظيف رمز العملة من أي آثار لـ 262145
    $currencySymbol = 'ج.م';
    $currencySymbol = str_replace('262145', '', $currencySymbol);
    $currencySymbol = preg_replace('/262145\s*/', '', $currencySymbol);
    $currencySymbol = preg_replace('/\s*262145/', '', $currencySymbol);
    $currencySymbol = trim($currencySymbol);
    // إذا أصبح فارغاً بعد التنظيف، استخدم القيمة الافتراضية
    if (empty($currencySymbol)) {
        $currencySymbol = 'ج.م';
    }
    define('CURRENCY_SYMBOL', $currencySymbol);
}
if (!defined('CURRENCY_CODE')) {
    define('CURRENCY_CODE', 'EGP');
}

// إعدادات التاريخ والوقت
define('DATE_FORMAT', 'd/m/Y');
define('TIME_FORMAT', 'g:i A'); // نظام 12 ساعة صباحاً ومساءً
define('DATETIME_FORMAT', 'd/m/Y g:i A');

// تم إزالة نظام الجلسات - لا حاجة لإعدادات الجلسة

// تعطيل تسجيل الأخطاء المفرط لتقليل الضغط على السيرفر
if (!defined('ERROR_LOGGING_ENABLED')) {
    define('ERROR_LOGGING_ENABLED', false); // تعطيل تسجيل الأخطاء الروتينية
}

// تعطيل request_monitor لتقليل الضغط على السيرفر
if (!defined('REQUEST_USAGE_MONITOR_ENABLED')) {
    define('REQUEST_USAGE_MONITOR_ENABLED', false);
}

// إعدادات timeout لمنع توقف الخادم
// زيادة timeout للطلبات الطويلة (مثل keep-alive)
if (!ini_get('max_execution_time') || ini_get('max_execution_time') < 60) {
    @ini_set('max_execution_time', 60); // 60 ثانية للطلبات العادية
}
if (!ini_get('max_input_time') || ini_get('max_input_time') < 60) {
    @ini_set('max_input_time', 60); // 60 ثانية لمعالجة المدخلات
}
// إعدادات timeout للاتصال بقاعدة البيانات
// تقليل timeout لتجنب التعليق
if (!ini_get('default_socket_timeout') || ini_get('default_socket_timeout') > 10) {
    @ini_set('default_socket_timeout', 5); // 5 ثواني للاتصال بقاعدة البيانات (مخفض)
}

// إعدادات SOAP WSDL Cache - منع خطأ open_basedir restriction
// تعطيل WSDL caching أو تعيينه لمجلد مسموح به
@ini_set('soap.wsdl_cache_enabled', '0'); // تعطيل WSDL caching
@ini_set('soap.wsdl_cache_ttl', '0'); // تعطيل TTL للكاش
// إذا كان يجب تفعيل الكاش، استخدم مجلد داخل المسارات المسموحة
if (defined('PRIVATE_STORAGE_PATH') && is_dir(PRIVATE_STORAGE_PATH)) {
    $wsdlCacheDir = PRIVATE_STORAGE_PATH . '/wsdlcache';
    if (!is_dir($wsdlCacheDir)) {
        @mkdir($wsdlCacheDir, 0755, true);
    }
    if (is_dir($wsdlCacheDir) && is_writable($wsdlCacheDir)) {
        @ini_set('soap.wsdl_cache_dir', $wsdlCacheDir);
    }
}

// تم إزالة نظام الجلسات بالكامل - لا حاجة لأي كود متعلق بالجلسات

// إعدادات الأمان
define('PASSWORD_MIN_LENGTH', 6);
define('CSRF_TOKEN_NAME', 'csrf_token');
// REQUEST_USAGE_MONITOR_ENABLED تم تعريفه مسبقاً في السطر 203-204
define('REQUEST_USAGE_THRESHOLD_PER_USER', 4000); // الحد اليومي لكل مستخدم قبل إنشاء تنبيه
define('REQUEST_USAGE_THRESHOLD_PER_IP', 30000);    // الحد اليومي لكل عنوان IP قبل إنشاء تنبيه
define('REQUEST_USAGE_ALERT_WINDOW_MINUTES', 1440); // فترة المراقبة بالدقائق (افتراضياً يوم كامل)

// إعدادات المسارات
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/uploads/');
define('REPORTS_PATH', BASE_PATH . '/reports/');

// استخدام مسار داخل BASE_PATH لتجنب مشاكل open_basedir
$privateStorageBase = BASE_PATH . '/storage';
if (!defined('PRIVATE_STORAGE_PATH')) {
    define('PRIVATE_STORAGE_PATH', $privateStorageBase);
}
if (!defined('REPORTS_PRIVATE_PATH')) {
    define('REPORTS_PRIVATE_PATH', PRIVATE_STORAGE_PATH . '/reports');
}

/**
 * ضمان وجود مجلد خاص للتخزين وإنشائه تلقائياً إذا لم يكن موجوداً.
 * يتعامل مع قيود open_basedir بشكل آمن.
 *
 * @param string $directory
 * @return void
 */
function ensurePrivateDirectory(string $directory): void
{
    // قمع تحذيرات open_basedir والتحقق من وجود المجلد
    $error = null;
    set_error_handler(function($errno, $errstr) use (&$error) {
        if (strpos($errstr, 'open_basedir') !== false) {
            $error = $errstr;
            return true; // قمع الخطأ
        }
        return false;
    }, E_WARNING);
    
    $dirExists = @is_dir($directory);
    restore_error_handler();
    
    if ($dirExists) {
        return;
    }
    
    // إذا كان هناك خطأ open_basedir، تجاهل محاولة إنشاء المجلد
    if ($error !== null) {
        error_log('Cannot access directory due to open_basedir restriction: ' . $directory);
        return;
    }

    $parent = dirname($directory);
    if (!@is_dir($parent) && $parent !== $directory) {
        ensurePrivateDirectory($parent);
    }

    if (!@mkdir($directory, 0755, true) && !@is_dir($directory)) {
        error_log('Failed to create directory: ' . $directory);
    }
}

ensurePrivateDirectory(PRIVATE_STORAGE_PATH);
ensurePrivateDirectory(REPORTS_PRIVATE_PATH);

$logsDirectory = PRIVATE_STORAGE_PATH . '/logs';
ensurePrivateDirectory($logsDirectory);

$defaultErrorLog = $logsDirectory . '/php-errors.log';
if (is_dir($logsDirectory) && is_writable($logsDirectory)) {
    if (!file_exists($defaultErrorLog)) {
        @touch($defaultErrorLog);
    }

    if (is_writable($defaultErrorLog)) {
        ini_set('log_errors', '1');
        ini_set('error_log', $defaultErrorLog);
        if (!defined('APP_ERROR_LOG')) {
            define('APP_ERROR_LOG', $defaultErrorLog);
        }
    } else {
        error_log('Error log file is not writable: ' . $defaultErrorLog);
    }
} else {
    error_log('Logs directory is not writable: ' . $logsDirectory);
}
define('ASSETS_PATH', dirname(__DIR__) . '/assets/');

// إعدادات تكامل aPDF.io - يمكن تخزين المفتاح في متغير بيئة APDF_IO_API_KEY لأمان أفضل
define('APDF_IO_ENDPOINT', 'https://api.apdf.io/v1/pdf/html');
define('APDF_IO_API_KEY', getenv('APDF_IO_API_KEY') ?: 'UQFfHN7tBIgv0Zjy1nelyZWMJC93m3NMXCWfWe9246a95eed');

// تحديد ASSETS_URL بناءً على موقع الملف
// استخدام REQUEST_URI للحصول على المسار الكامل (يعمل بشكل أفضل على الموبايل)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

// استخراج base path من REQUEST_URI أو SCRIPT_NAME
$basePath = '';

// محاولة 1: من REQUEST_URI (أفضل للموبايل)
if (!empty($requestUri)) {
    $parsedUri = parse_url($requestUri);
    $path = $parsedUri['path'] ?? '';
    $path = str_replace('\\', '/', $path);
    
    // إزالة /dashboard و /modules و API من المسار
    $pathParts = explode('/', trim($path, '/'));
    $baseParts = [];
    
    foreach ($pathParts as $part) {
        if ($part === 'dashboard' || $part === 'modules' || $part === 'api' || strpos($part, '.php') !== false) {
            break;
        }
        if (!empty($part)) {
            $baseParts[] = $part;
        }
    }
    
    if (!empty($baseParts)) {
        $basePath = '/' . implode('/', $baseParts);
    }
}

// محاولة 2: من SCRIPT_NAME إذا فشلت المحاولة الأولى
if (empty($basePath)) {
    $scriptDir = dirname($scriptName);
    
    // إزالة /dashboard أو /modules من المسار
    if (strpos($scriptDir, '/dashboard') !== false) {
        $scriptDir = dirname($scriptDir);
    }
    if (strpos($scriptDir, '/modules') !== false) {
        $scriptDir = dirname(dirname($scriptDir));
    }
    
    // تنظيف المسار
    $scriptDir = str_replace('\\', '/', $scriptDir);
    $scriptDir = trim($scriptDir, '/');
    
    if (!empty($scriptDir) && $scriptDir !== '.' && $scriptDir !== '/') {
        $basePath = '/' . $scriptDir;
    }
}

// تحديد ASSETS_URL النهائي
if (empty($basePath)) {
    define('ASSETS_URL', '/assets/');
} else {
    define('ASSETS_URL', rtrim($basePath, '/') . '/assets/');
}

// إعدادات التطبيق
define('APP_NAME', 'شركة البركة');
define('APP_VERSION', '1.0.0');
// إصدار الملفات الثابتة (Assets) - يتم تحديثه يدوياً عند تغيير CSS/JS
if (!defined('ASSETS_VERSION')) {
    define('ASSETS_VERSION', '1.0.0');
}
define('COMPANY_NAME', 'شركة البركة');

// إعدادات وضع الصيانة
// تغيير هذا إلى true لتفعيل وضع الصيانة
if (!defined('MAINTENANCE_MODE')) {
    define('MAINTENANCE_MODE', false);
}

// إعدادات التقارير
define('REPORTS_AUTO_DELETE', true); // حذف التقارير بعد الإرسال
define('REPORTS_RETENTION_HOURS', 24); // الاحتفاظ بالتقارير لمدة 24 ساعة

// إعدادات الإشعارات
if (!defined('NOTIFICATIONS_ENABLED')) {
    define('NOTIFICATIONS_ENABLED', true);
}
if (!defined('BROWSER_NOTIFICATIONS_ENABLED')) {
    define('BROWSER_NOTIFICATIONS_ENABLED', true);
}
if (!defined('NOTIFICATION_POLL_INTERVAL')) {
    define('NOTIFICATION_POLL_INTERVAL', 30000); // 30 ثانية - محسّن للأداء
}
if (!defined('NOTIFICATION_AUTO_REFRESH_ENABLED')) {
    define('NOTIFICATION_AUTO_REFRESH_ENABLED', true);
}

// إعدادات Telegram Bot
// للحصول على Bot Token: تحدث مع @BotFather في Telegram
// للحصول على Chat ID: أرسل رسالة للبوت ثم افتح: https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates
define('TELEGRAM_BOT_TOKEN', '6286098014:AAGr6q-6mvUHYIa3elUkssoijFhY7OXBrew'); // ضع توكن البوت هنا
define('TELEGRAM_CHAT_ID', '-1003293835035'); // ضع معرف المحادثة هنا (يمكن أن يكون رقم أو -100... للمجموعات)

// إعدادات WebAuthn
define('WEBAUTHN_RP_NAME', 'نظام الإدارة المتكاملة');
define('WEBAUTHN_RP_ID', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');

// التحقق من HTTPS - إجبار استخدام HTTPS في الإنتاج
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
);

// استخدام HTTPS دائماً في الإنتاج (على الاستضافة)
// فقط في localhost يمكن استخدام HTTP
$isLocalhost = (
    (isset($_SERVER['HTTP_HOST']) && ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0 || $_SERVER['HTTP_HOST'] === '127.0.0.1' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1:') === 0))
);

$webauthnProtocol = ($isHttps || !$isLocalhost) ? 'https' : 'http';
define('WEBAUTHN_ORIGIN', $webauthnProtocol . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));

// إعدادات التصميم
define('PRIMARY_COLOR', '#1e3a5f');
define('SECONDARY_COLOR', '#2c5282');
define('ACCENT_COLOR', '#3498db');

// إعدادات عرض الأخطاء - تعتمد على البيئة
$isProduction = !$isLocalhost; // الإنتاج = ليس localhost

if ($isProduction) {
    // في الإنتاج: تعطيل عرض الأخطاء وتسجيلها فقط
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
} else {
    // في التطوير: عرض جميع الأخطاء
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

// إعدادات UTF-8
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

/**
 * دالة مساعدة لمسح الكاش بعد العمليات بشكل فوري
 * يمكن استدعاؤها من أي مكان لضمان ظهور النتائج بشكل لحظي
 * يتم المسح فوراً بدون انتظار لضمان التحديث الفوري
 */
function clearCache() {
    if (file_exists(__DIR__ . '/cache.php')) {
        require_once __DIR__ . '/cache.php';
        if (class_exists('Cache')) {
            try {
                // مسح الكاش فوراً بشكل متزامن
                Cache::flush();
            } catch (Exception $e) {
                error_log("Cache flush error: " . $e->getMessage());
            } catch (Throwable $e) {
                error_log("Cache flush error (Throwable): " . $e->getMessage());
            }
        }
    }
}

// دالة مساعدة للحصول على اللغة الحالية
function getCurrentLanguage() {
    // تم إزالة نظام الجلسات - استخدام اللغة الافتراضية
    return DEFAULT_LANGUAGE;
}

// دالة مساعدة للحصول على رمز العملة بعد تنظيفه من 262145
function getCurrencySymbol() {
    $symbol = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'ج.م';
    // تنظيف رمز العملة من 262145
    $symbol = str_replace('262145', '', $symbol);
    $symbol = preg_replace('/262145\s*/', '', $symbol);
    $symbol = preg_replace('/\s*262145/', '', $symbol);
    $symbol = trim($symbol);
    // إذا أصبح فارغاً بعد التنظيف، استخدم القيمة الافتراضية
    if (empty($symbol)) {
        $symbol = 'ج.م';
    }
    return $symbol;
}

// دالة مساعدة لتنسيق الأرقام
function formatCurrency($amount, $allowNegative = true) {
    // تنظيف القيمة باستخدام cleanFinancialValue
    // السماح بالقيم السالبة افتراضياً لأنها تستخدم للرصيد الدائن للعملاء
    $amount = cleanFinancialValue($amount, $allowNegative);
    
    // استخدام getCurrencySymbol للحصول على رمز العملة المنظف
    $currencySymbol = function_exists('getCurrencySymbol') ? getCurrencySymbol() : (defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'ج.م');
    
    $formatted = number_format($amount, 2, '.', ',') . ' ' . $currencySymbol;
    
    // حذف أي آثار لـ 262145 من النص النهائي (حماية إضافية)
    $formatted = str_replace('262145', '', $formatted);
    $formatted = str_replace('262,145', '', $formatted);
    $formatted = preg_replace('/\s+/', ' ', $formatted);
    
    return trim($formatted);
}

/**
 * دالة لتنظيف القيم المالية وضمان صحتها
 * Validate and clean financial values
 * @param mixed $value القيمة المراد تنظيفها
 * @param bool $allowNegative السماح بالقيم السالبة (للرصيد الدائن)
 */
function cleanFinancialValue($value, $allowNegative = false) {
    // إذا كانت القيمة null أو فارغة، إرجاع 0
    if ($value === null || $value === '' || $value === false) {
        return 0;
    }
    
    // تحويل إلى نص أولاً
    $valueStr = (string)$value;

    // إزالة آثار الرقم الافتراضي القديم 262145 إن وُجدت بأي شكل
    $valueStr = str_replace('262145', '', $valueStr);
    $valueStr = preg_replace('/262145\s*/', '', $valueStr);
    $valueStr = preg_replace('/\s*262145/', '', $valueStr);

    // إزالة أي أحرف غير رقمية (باستثناء النقطة والعلامة السالبة)
    $valueStr = preg_replace('/[^0-9.\-]/', '', trim($valueStr));
    
    // إذا أصبح النص فارغاً بعد التنظيف، إرجاع 0
    if (empty($valueStr) || $valueStr === '-') {
        return 0;
    }
    
    // تحويل إلى رقم
    $value = floatval($valueStr);
    
    // التحقق من القيم غير المنطقية
    if (is_nan($value) || is_infinite($value)) {
        return 0;
    }
    
    // التحقق من القيم الكبيرة جداً أو السالبة
    if ($allowNegative) {
        // إذا كان مسموحاً بالقيم السالبة (للرصيد الدائن)، فقط التحقق من الحد الأقصى
        // القيم المقبولة: من -1000000 إلى 1000000
        if ($value > 1000000 || $value < -1000000) {
            return 0;
        }
    } else {
        // القيم المقبولة: من 0 إلى 10000 جنيه/ساعة (للأجور والمدفوعات)
        if ($value > 10000 || $value < 0) {
            return 0;
        }
    }
    
    // تقريب إلى منزلتين عشريتين
    return round($value, 2);
}

// دالة مساعدة لتنسيق التاريخ
function formatDate($date, $format = DATE_FORMAT) {
    if (empty($date)) return '';
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق الوقت
function formatTime($time, $format = TIME_FORMAT) {
    if (empty($time)) return '';
    $timestamp = is_numeric($time) ? $time : strtotime($time);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق التاريخ والوقت
function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    if (empty($datetime)) return '';
    $timestamp = is_numeric($datetime) ? $datetime : strtotime($datetime);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق الساعات من الصيغة العشرية إلى ساعات ودقائق
// مثال: 2.30 ساعة → "2 ساعة و 30 دقيقة"
function formatHours($decimalHours) {
    if (empty($decimalHours) || $decimalHours == 0) {
        return '0 ساعة';
    }
    
    $decimalHours = floatval($decimalHours);
    
    // استخراج الساعات الكاملة
    $hours = floor($decimalHours);
    
    // استخراج الدقائق من الجزء العشري
    $decimalPart = $decimalHours - $hours;
    $minutes = round($decimalPart * 60);
    
    // إذا كانت الدقائق 60، أضف ساعة واحدة
    if ($minutes >= 60) {
        $hours += 1;
        $minutes = 0;
    }
    
    // بناء النص
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ساعة';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' دقيقة';
    }
    
    if (empty($parts)) {
        return '0 ساعة';
    }
    
    return implode(' و ', $parts);
}

// دالة مساعدة للحصول على الاتجاه (RTL/LTR)
function getDirection() {
    return getCurrentLanguage() === 'ar' ? 'rtl' : 'ltr';
}

// دالة مساعدة للحصول على الاتجاه المعاكس في CSS
function getTextAlign() {
    return getCurrentLanguage() === 'ar' ? 'right' : 'left';
}

/**
 * منع تكرار الطلبات عند refresh
 * يستخدم Post-Redirect-Get (PRG) pattern
 * 
 * @param string|null $successMessage رسالة النجاح (اختياري)
 * @param array $redirectParams معاملات إعادة التوجيه
 * @param string|null $redirectUrl URL لإعادة التوجيه (اختياري)
 * @param string|null $role دور المستخدم لإعادة التوجيه (للاستخدام مع getDashboardUrl)
 * @param string|null $errorMessage رسالة الخطأ (اختياري)
 */
function preventDuplicateSubmission($successMessage = null, $redirectParams = [], $redirectUrl = null, $role = null, $errorMessage = null) {
    // تم إزالة نظام الجلسات - إضافة الرسائل كـ query parameters
    if ($successMessage !== null && $successMessage !== '') {
        $redirectParams['success'] = urlencode($successMessage);
        error_log("preventDuplicateSubmission: Added success message to redirect params: " . $successMessage);
    }
    
    // إذا كانت هناك رسالة خطأ، إضافتها كـ query parameter
    if ($errorMessage !== null && $errorMessage !== '') {
        $redirectParams['error'] = urlencode($errorMessage);
        error_log("preventDuplicateSubmission: Added error message to redirect params: " . $errorMessage);
    }
    
    // بناء URL إعادة التوجيه
    if ($redirectUrl === null) {
        // إذا كان هناك role و page في redirectParams، استخدم getDashboardUrl
        if ($role !== null && isset($redirectParams['page'])) {
            require_once __DIR__ . '/path_helper.php';
            $page = $redirectParams['page'];
            unset($redirectParams['page']);
            
            $baseUrl = getDashboardUrl($role);
            if (!empty($redirectParams)) {
                $queryString = http_build_query($redirectParams);
                $redirectUrl = $baseUrl . '?page=' . urlencode($page) . '&' . $queryString;
            } else {
                $redirectUrl = $baseUrl . '?page=' . urlencode($page);
            }
        } else {
            // استخدام URL الحالي بدون POST parameters
            $currentUrl = $_SERVER['REQUEST_URI'];
            $urlParts = parse_url($currentUrl);
            $path = $urlParts['path'] ?? '';
            
            // إضافة GET parameters إذا كانت موجودة
            if (!empty($redirectParams)) {
                $queryString = http_build_query($redirectParams);
                $redirectUrl = $path . '?' . $queryString;
            } else {
                // إزالة query string من URL الحالي
                $redirectUrl = $path;
            }
        }
    }
    
    // إضافة cache-busting parameters لضمان تحديث الصفحة بعد إنشاء/تحديث البيانات
    // هذا يمنع المتصفح من عرض نسخة قديمة من الصفحة
    $separator = (strpos($redirectUrl, '?') !== false) ? '&' : '?';
    $timestamp = time();
    
    // إذا كان _v موجود في redirectParams، استخدمه، وإلا أضف _t و _v جديدين
    if (isset($redirectParams['_v'])) {
        $redirectUrl .= $separator . '_v=' . $redirectParams['_v'] . '&_t=' . $redirectParams['_v'];
    } else {
        $redirectUrl .= $separator . '_v=' . $timestamp . '&_t=' . $timestamp;
    }
    
    // تنظيف شامل: إزالة أي بروتوكول أو hostname أو منفذ من redirectUrl
    // 1. إزالة أي بروتوكول كامل مع hostname ومنفذ
    $redirectUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $redirectUrl);
    $redirectUrl = preg_replace('/^\/\//', '/', $redirectUrl);
    
    // 2. إزالة أي hostname مع منفذ إذا كان موجوداً
    if (preg_match('/^\/[^\/]+:[0-9]+\//', $redirectUrl)) {
        $redirectUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $redirectUrl);
    }
    
    // 3. التأكد من أن المسار نسبي فقط (يبدأ بـ /)
    if (!preg_match('/^https?:\/\//i', $redirectUrl)) {
        // استخدام substr بدلاً من str_starts_with للتوافق مع PHP < 8.0
        if (substr($redirectUrl, 0, 1) !== '/') {
            $redirectUrl = '/' . ltrim($redirectUrl, '/');
        }
    } else {
        // إذا كان لا يزال يحتوي على بروتوكول، استخراج المسار فقط
        $parsed = parse_url($redirectUrl);
        $redirectUrl = $parsed['path'] ?? '/';
        if (!empty($parsed['query'])) {
            $redirectUrl .= '?' . $parsed['query'];
        }
    }
    
    // 4. إزالة أي منفذ من المسار (للاحتياط)
    $redirectUrl = preg_replace('/:[0-9]+\//', '/', $redirectUrl);
    $redirectUrl = preg_replace('/:[0-9]+$/', '', $redirectUrl);
    
    // 5. تنظيف نهائي
    $redirectUrl = preg_replace('/\/+/', '/', $redirectUrl);
    
    // إضافة headers لمنع caching عند إعادة التوجيه
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // التحقق من أن headers لم يتم إرسالها بعد
    if (headers_sent($file, $line)) {
        // إذا تم إرسال headers بالفعل، استخدم JavaScript redirect مع force reload
        echo '<script>';
        echo 'window.location.replace(' . json_encode($redirectUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');';
        echo '</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
    
    // إعادة التوجيه مع force reload
    header('Location: ' . $redirectUrl, true, 303); // 303 See Other يمنع caching
    exit;
}

/**
 * التحقق من وجود رسالة نجاح في query parameters وعرضها
 * يجب استدعاؤها في بداية الصفحة بعد معالجة POST
 * 
 * @return string|null رسالة النجاح أو null
 */
function getSuccessMessage() {
    // تم إزالة نظام الجلسات - قراءة الرسالة من query parameters
    if (isset($_GET['success']) && !empty($_GET['success'])) {
        return urldecode($_GET['success']);
    }
    return null;
}

/**
 * التحقق من وجود رسالة خطأ في query parameters وعرضها
 * يجب استدعاؤها في بداية الصفحة بعد معالجة POST
 * 
 * @return string|null رسالة الخطأ أو null
 */
function getErrorMessage() {
    // تم إزالة نظام الجلسات - قراءة الرسالة من query parameters
    if (isset($_GET['error']) && !empty($_GET['error'])) {
        return urldecode($_GET['error']);
    }
    return null;
}

/**
 * دالة مساعدة لتطبيق PRG pattern على الطلبات POST
 * تقرأ الرسائل من query parameters وتعرضها (تم إزالة نظام الجلسات)
 * 
 * @param string|null $defaultError متغير لرسالة الخطأ الافتراضي
 * @param string|null $defaultSuccess متغير لرسالة النجاح الافتراضي
 * @return void
 */
function applyPRGPattern(&$defaultError = null, &$defaultSuccess = null) {
    $sessionSuccess = getSuccessMessage();
    $sessionError = getErrorMessage();
    
    if ($sessionSuccess !== null) {
        $defaultSuccess = $sessionSuccess;
    }
    
    if ($sessionError !== null) {
        $defaultError = $sessionError;
    }
}


if (!defined('ENABLE_DAILY_LOW_STOCK_REPORT')) {
    define('ENABLE_DAILY_LOW_STOCK_REPORT', false);
}
if (!defined('ENABLE_DAILY_PACKAGING_ALERT')) {
    define('ENABLE_DAILY_PACKAGING_ALERT', false);
}
if (!defined('ENABLE_DAILY_CONSUMPTION_REPORT')) {
    define('ENABLE_DAILY_CONSUMPTION_REPORT', false);
}
if (!defined('ENABLE_PAGE_LOADER')) {
    define('ENABLE_PAGE_LOADER', true);
}
if (!defined('ENABLE_DAILY_BACKUP_DELIVERY')) {
    define('ENABLE_DAILY_BACKUP_DELIVERY', true);
}
if (!defined('ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP')) {
    define('ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP', true);
}


if (ENABLE_DAILY_CONSUMPTION_REPORT) {
    require_once __DIR__ . '/daily_consumption_sender.php';
    triggerDailyConsumptionReport();
}


if (ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP) {
    // ملف العلم لتتبع آخر مرة تم فيها التنظيف
    $cleanupFlagFile = PRIVATE_STORAGE_PATH . '/logs/attendance_photos_cleanup_last_run.txt';
    $today = date('Y-m-d');
    $shouldRun = false;
    
    // التحقق من آخر مرة تم فيها التنظيف
    if (file_exists($cleanupFlagFile)) {
        $lastRunDate = trim(@file_get_contents($cleanupFlagFile));
        if ($lastRunDate !== $today) {
            $shouldRun = true;
        }
    } else {
        // إذا لم يكن الملف موجوداً، قم بالتنظيف
        $shouldRun = true;
    }
    
    // تشغيل التنظيف إذا لزم الأمر
    if ($shouldRun) {
        try {
            // حفظ تاريخ اليوم في ملف العلم أولاً لمنع التشغيل المتكرر
            $logsDir = dirname($cleanupFlagFile);
            if (!is_dir($logsDir)) {
                @mkdir($logsDir, 0755, true);
            }
            @file_put_contents($cleanupFlagFile, $today, LOCK_EX);
            
            // تحميل الملفات المطلوبة
            if (!function_exists('cleanupOldAttendancePhotos')) {
                require_once __DIR__ . '/attendance.php';
            }
            if (!function_exists('db')) {
                require_once __DIR__ . '/db.php';
            }
            
            // تشغيل التنظيف (30 يوم كافتراضي)
            if (function_exists('cleanupOldAttendancePhotos')) {
                $stats = cleanupOldAttendancePhotos(30);
                
                // تسجيل النتائج
                $message = sprintf(
                    "Attendance photos cleanup (automatic daily): %d files deleted, %d folders deleted, %.2f MB freed, %d errors",
                    $stats['deleted_files'],
                    $stats['deleted_folders'],
                    $stats['total_size_freed'] / (1024 * 1024),
                    $stats['errors']
                );
                error_log($message);
            }
        } catch (Exception $e) {
            error_log('Daily attendance photos cleanup error: ' . $e->getMessage());
            // في حالة الخطأ، احذف ملف العلم للسماح بإعادة المحاولة لاحقاً
            if (file_exists($cleanupFlagFile)) {
                @unlink($cleanupFlagFile);
            }
        } catch (Throwable $e) {
            error_log('Daily attendance photos cleanup error: ' . $e->getMessage());
            // في حالة الخطأ، احذف ملف العلم للسماح بإعادة المحاولة لاحقاً
            if (file_exists($cleanupFlagFile)) {
                @unlink($cleanupFlagFile);
            }
        }
    }
}

/**
 * تشغيل إرسال التذكيرات اليومية مرة واحدة كل يوم
 * يتم تشغيله مع أول زائر لأي صفحة من صفحات الموقع
 */
$dailyRemindersFlagFile = PRIVATE_STORAGE_PATH . '/logs/daily_reminders_last_run.txt';
$today = date('Y-m-d');
$shouldRunReminders = false;

// التحقق من آخر مرة تم فيها التشغيل
if (file_exists($dailyRemindersFlagFile)) {
    $lastRunDate = trim(@file_get_contents($dailyRemindersFlagFile));
    if ($lastRunDate !== $today) {
        $shouldRunReminders = true;
    }
} else {
    // إذا لم يكن الملف موجوداً، قم بالتشغيل
    $shouldRunReminders = true;
}

// تشغيل إرسال التذكيرات إذا لزم الأمر
if ($shouldRunReminders) {
    try {
        // حفظ تاريخ اليوم في ملف العلم أولاً لمنع التشغيل المتكرر
        $logsDir = dirname($dailyRemindersFlagFile);
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        @file_put_contents($dailyRemindersFlagFile, $today, LOCK_EX);
        
        // تعيين متغير لتجنب إعادة تحميل config.php من داخل test_daily_reminders.php
        $GLOBALS['DAILY_REMINDERS_CALLED_FROM_CONFIG'] = true;
        
        // تعريف ACCESS_ALLOWED إذا لم يكن معرّفاً
        if (!defined('ACCESS_ALLOWED')) {
            define('ACCESS_ALLOWED', true);
        }
        
        // استدعاء ملف الاختبار مع منع ظهور المخرجات
        $testFile = dirname(__DIR__) . '/test_daily_reminders.php';
        if (file_exists($testFile)) {
            // استخدام output buffering لمنع ظهور المخرجات في الصفحة
            ob_start();
            include $testFile;
            $output = ob_get_clean();
            
            // تسجيل المخرجات في error_log
            error_log("Daily payment reminders executed on {$today} - تم الاستدعاء بنجاح:\n" . $output);
        } else {
            error_log("Daily payment reminders: test_daily_reminders.php file not found at {$testFile}");
        }
        
        // إزالة المتغير بعد الانتهاء
        unset($GLOBALS['DAILY_REMINDERS_CALLED_FROM_CONFIG']);
    } catch (Exception $e) {
        error_log('Daily payment reminders error: ' . $e->getMessage());
        // في حالة الخطأ، احذف ملف العلم للسماح بإعادة المحاولة لاحقاً
        if (file_exists($dailyRemindersFlagFile)) {
            @unlink($dailyRemindersFlagFile);
        }
        unset($GLOBALS['DAILY_REMINDERS_CALLED_FROM_CONFIG']);
    } catch (Throwable $e) {
        error_log('Daily payment reminders error: ' . $e->getMessage());
        // في حالة الخطأ، احذف ملف العلم للسماح بإعادة المحاولة لاحقاً
        if (file_exists($dailyRemindersFlagFile)) {
            @unlink($dailyRemindersFlagFile);
        }
        unset($GLOBALS['DAILY_REMINDERS_CALLED_FROM_CONFIG']);
    }
}

/**
 * تشغيل إنشاء الرواتب التلقائي مرة واحدة كل يوم
 * يتم فحص قاعدة البيانات أولاً للتأكد من عدم التنفيذ اليوم
 * إذا لم يتم التنفيذ، ينفذ العملية ثم يسجل التاريخ
 */
if (function_exists('isLoggedIn') && isLoggedIn()) {
    try {
        if (!function_exists('runAutoSalaryInit')) {
            require_once __DIR__ . '/auto_salary_init.php';
        }
        if (function_exists('runAutoSalaryInit')) {
            runAutoSalaryInit();
        }
    } catch (Exception $e) {
        error_log('Auto salary init error in config.php: ' . $e->getMessage());
    } catch (Throwable $e) {
        error_log('Auto salary init error in config.php: ' . $e->getMessage());
    }
}

/**
 * تنظيف تلقائي للاتصالات المعلقة في قاعدة البيانات
 * يتم تشغيله كل 5 دقائق لتقليل عدد الاتصالات المعلقة
 */
if (!defined('DB_CONNECTION_CLEANUP_ENABLED')) {
    define('DB_CONNECTION_CLEANUP_ENABLED', true);
}

if (DB_CONNECTION_CLEANUP_ENABLED) {
    try {
        $cleanupFlagFile = PRIVATE_STORAGE_PATH . '/logs/db_cleanup_last_run.txt';
        $now = time();
        $shouldRun = false;
        
        // التحقق من آخر مرة تم فيها التنظيف (كل 5 دقائق)
        if (file_exists($cleanupFlagFile)) {
            $lastRunTime = (int)@file_get_contents($cleanupFlagFile);
            if (($now - $lastRunTime) > 300) { // 5 دقائق
                $shouldRun = true;
            }
        } else {
            $shouldRun = true;
        }
        
        if ($shouldRun) {
            // حفظ وقت التنظيف أولاً لمنع التشغيل المتكرر
            $logsDir = dirname($cleanupFlagFile);
            if (!is_dir($logsDir)) {
                @mkdir($logsDir, 0755, true);
            }
            @file_put_contents($cleanupFlagFile, $now, LOCK_EX);
            
            // تشغيل تنظيف الاتصالات في الخلفية (non-blocking)
            // استخدام curl أو file_get_contents مع timeout قصير
            $cleanupUrl = null;
            if (function_exists('getRelativeUrl')) {
                $cleanupUrl = getRelativeUrl('api/cleanup_db_connections.php?token=1');
            } else {
                // بناء URL يدوياً
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
                    $cleanupUrl = $basePath . '/api/cleanup_db_connections.php?token=1';
                } else {
                    $cleanupUrl = '/api/cleanup_db_connections.php?token=1';
                }
            }
            
            if ($cleanupUrl) {
                // استخدام file_get_contents مع timeout قصير (non-blocking)
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 1, // timeout 1 ثانية فقط
                        'ignore_errors' => true,
                    ]
                ]);
                @file_get_contents($cleanupUrl, false, $context);
            }
        }
    } catch (Exception $e) {
        error_log('DB connection cleanup error: ' . $e->getMessage());
    } catch (Throwable $e) {
        error_log('DB connection cleanup error: ' . $e->getMessage());
    }
}

/**
 * الحصول على رقم الإصدار الحالي من version.json
 * @return string رقم الإصدار (مثال: v1.0)
 * 
 * ملاحظة: تم حذف version_helper.php - الإصدار يُقرأ مباشرة من version.json
 */
if (!function_exists('getCurrentVersion')) {
    function getCurrentVersion(): string {
    $versionFile = __DIR__ . '/../version.json';
    
    if (!file_exists($versionFile)) {
        // إنشاء ملف version.json افتراضي إذا لم يكن موجوداً
        $defaultVersion = [
            'version' => '1.0',
            'build' => 0,
            'last_updated' => date('Y-m-d H:i:s')
        ];
        @file_put_contents($versionFile, json_encode($defaultVersion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return 'v1.0';
    }
    
    try {
        $versionData = json_decode(file_get_contents($versionFile), true);
        if (isset($versionData['version'])) {
            return 'v' . $versionData['version'];
        }
    } catch (Exception $e) {
        error_log('Error reading version.json: ' . $e->getMessage());
    }
    
    return 'v1.0';
    }
}

/**
 * تحديث رقم الإصدار تلقائياً عند التعديلات
 * يزيد رقم البناء (build) تلقائياً
 * @return string رقم الإصدار المحدث
 */
function incrementVersionBuild(): string {
    $versionFile = __DIR__ . '/../version.json';
    $version = '1.0';
    $build = 0;
    
    if (file_exists($versionFile)) {
        try {
            $versionData = json_decode(file_get_contents($versionFile), true);
            $version = $versionData['version'] ?? '1.0';
            $build = isset($versionData['build']) ? (int)$versionData['build'] : 0;
        } catch (Exception $e) {
            error_log('Error reading version.json: ' . $e->getMessage());
        }
    }
    
    // زيادة رقم البناء
    $build++;
    
    // تحديث الإصدار بناءً على رقم البناء
    // كل 10 تعديلات = زيادة في الإصدار الثانوي (1.0 -> 1.1 -> 1.2)
    $minorVersion = floor($build / 10);
    $versionParts = explode('.', $version);
    $majorVersion = $versionParts[0] ?? '1';
    $currentMinor = isset($versionParts[1]) ? (int)$versionParts[1] : 0;
    
    // تحديث الإصدار الثانوي إذا لزم الأمر
    if ($minorVersion > $currentMinor) {
        $version = $majorVersion . '.' . $minorVersion;
    }
    
    // حفظ الإصدار المحدث
    $versionData = [
        'version' => $version,
        'build' => $build,
        'last_updated' => date('Y-m-d H:i:s')
    ];
    
    @file_put_contents($versionFile, json_encode($versionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    return 'v' . $version;
}



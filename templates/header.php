<?php
/**
 * رأس الصفحة المشتركة
 * دعم RTL/LTR وتبديل اللغة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// تعريف ثابت للإشارة إلى أن header.php تم تضمينه - يجب أن يكون في البداية
if (!defined('HEADER_INCLUDED')) {
    define('HEADER_INCLUDED', true);
}

// إضافة Permissions-Policy header للسماح بالوصول إلى Geolocation, Camera, Microphone
// ملاحظة: notifications تم إزالته من Feature-Policy لأنه غير مدعوم
if (!headers_sent()) {
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=(self)");
    // تم إزالة Feature-Policy لأنه deprecated ويسبب تحذيرات في المتصفحات الحديثة
    
    // === Cache Control Headers - محسّن لـ bfcache (back/forward cache) ===
    // تم تعديلها للسماح بـ bfcache مع الحفاظ على تحديث البيانات
    // استخدام private بدلاً من no-store للسماح بـ bfcache
    header('Cache-Control: private, max-age=0, must-revalidate');
    // إزالة Pragma و Expires لأنها تمنع bfcache
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('ETag: "' . md5(time() . rand() . uniqid()) . '"');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/packaging_alerts.php';
require_once __DIR__ . '/../includes/payment_schedules.php';
require_once __DIR__ . '/../includes/production_reports.php';
// تم حذف version_helper.php - الإصدار يُقرأ مباشرة من version.json

// تحديد اللغة الحالية
$currentLang = getCurrentLanguage();
$dir = getDirection();

// تحميل ملفات اللغة - فقط إذا لم يتم تحميلها بالفعل
if (!isset($translations) || empty($translations)) {
    $translations = [];
    if (file_exists(__DIR__ . '/../includes/lang/' . $currentLang . '.php')) {
        require_once __DIR__ . '/../includes/lang/' . $currentLang . '.php';
    }
}

// استخدام $lang الموجود إذا كان موجوداً، وإلا استخدام $translations
if (!isset($lang) || empty($lang)) {
    $lang = isset($translations) ? $translations : [];
}
// تحميل $currentUser فقط إذا لم يكن محملاً بالفعل
if (!isset($currentUser) || $currentUser === null) {
    $currentUser = getCurrentUser();
}

// التحقق من أننا في profile.php - منع حذف الجلسة في profile.php
$isProfilePage = false;

// الطريقة 1: التحقق من الثابت (الأكثر موثوقية)
if (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) {
    $isProfilePage = true;
}

// الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
if (!$isProfilePage) {
    $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
        $isProfilePage = true;
    }
}

// الطريقة 3: التحقق من REQUEST_URI
if (!$isProfilePage) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($requestUri, 'profile.php') !== false) {
        $isProfilePage = true;
    }
}

// فحص أمني: إذا كان المستخدم مسجل دخول لكن غير موجود في قاعدة البيانات
// (getCurrentUser() يقوم بإلغاء تسجيل الدخول تلقائياً، لكن نتأكد من عدم وجود جلسة نشطة)
// استثناء: لا نعيد التوجيه في profile.php لمنع حذف الجلسة
// إضافة retry logic محسّن لمنع إنهاء الجلسة بعد تسجيل الدخول مباشرة
if (!$isProfilePage && isLoggedIn() && (!$currentUser || !is_array($currentUser) || empty($currentUser))) {
    // إعادة المحاولة عدة مرات - قد يكون هناك تأخير في قاعدة البيانات بعد تسجيل الدخول
    $maxRetries = 3;
    $retryDelay = 100000; // 100ms
    
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        usleep($retryDelay);
        $currentUser = getCurrentUser();
        
        // إذا نجحت إعادة المحاولة، توقف
        if ($currentUser && is_array($currentUser) && !empty($currentUser)) {
            break;
        }
        
        // زيادة وقت الانتظار في كل محاولة
        $retryDelay *= 1.5;
    }
    
    // فقط إذا استمر الفشل بعد جميع محاولات إعادة المحاولة، نعيد التوجيه
    if (!$currentUser || !is_array($currentUser) || empty($currentUser)) {
        // التحقق من أن الجلسة لا تزال موجودة (لم يتم حذفها)
        // قد يكون المستخدم محذوفاً أو غير مفعّل
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            // الجلسة موجودة لكن المستخدم غير موجود - قد يكون هناك مشكلة في قاعدة البيانات
            // سجل الخطأ لكن لا تقم بإعادة التوجيه فوراً (قد يكون خطأ مؤقت)
            error_log("Warning: User ID {$_SESSION['user_id']} logged in but getCurrentUser() returned empty. Retrying...");
            
            // إعادة المحاولة مرة أخيرة بعد انتظار أطول
            usleep(200000); // 200ms
            $currentUser = getCurrentUser();
            
            // فقط إذا استمر الفشل، قم بإعادة التوجيه
            if (!$currentUser || !is_array($currentUser) || empty($currentUser)) {
                // المستخدم مسجل دخول لكن غير موجود أو محذوف - تم إلغاء تسجيل الدخول تلقائياً
                // إعادة التوجيه لتسجيل الدخول
                $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
                if (!headers_sent()) {
                    header('Location: ' . $loginUrl);
                    exit;
                } else {
                    echo '<script>window.location.href = "' . htmlspecialchars($loginUrl) . '";</script>';
                    exit;
                }
            }
        }
    }
}

$currentUserRole = strtolower((string) (isset($currentUser['role']) ? $currentUser['role'] : ''));

// تم نقل العمليات الثقيلة إلى api/background-tasks.php لتحسين الأداء
// سيتم تنفيذها بشكل غير متزامن بعد تحميل الصفحة

// العمليات السريعة فقط - يمكن تنفيذها مباشرة
if ($currentUser && function_exists('handleAttendanceRemindersForUser')) {
    // هذا قد يحتاج أن يعمل مباشرة لإظهار الإشعارات
    // لكن يمكن تحسينه لاحقاً ليعمل عبر AJAX أيضاً
    try {
        handleAttendanceRemindersForUser($currentUser);
    } catch (Throwable $e) {
        error_log('Attendance reminders error: ' . $e->getMessage());
    }
}

/* تم نقل العمليات التالية إلى api/background-tasks.php
 * سيتم تنفيذها بشكل غير متزامن بعد تحميل الصفحة لتحسين الأداء
 * 
if (function_exists('processDailyPackagingAlert')) {
    processDailyPackagingAlert();
}

if (function_exists('processAutoCheckoutForMissingEmployees')) {
    try {
        processAutoCheckoutForMissingEmployees();
    } catch (Throwable $autoCheckoutError) {
        error_log('Auto checkout processing error: ' . $autoCheckoutError->getMessage());
    }
}

if (function_exists('resetWarningCountsForNewMonth')) {
    try {
        resetWarningCountsForNewMonth();
    } catch (Throwable $resetWarningError) {
        error_log('Warning count reset error: ' . $resetWarningError->getMessage());
    }
}

if ($currentUser && $currentUserRole === 'sales') {
    try {
        notifyTodayPaymentSchedules((int) (isset($currentUser['id']) ? $currentUser['id'] : 0));
    } catch (Throwable $paymentNotificationError) {
        error_log('Sales payment notification error: ' . $paymentNotificationError->getMessage());
    }
}

if ($currentUser) {
    try {
        maybeSendMonthlyProductionDetailedReport((int) date('n'), (int) date('Y'));
    } catch (Throwable $productionReportAutoError) {
        error_log('Automatic monthly production detailed report dispatch failed: ' . $productionReportAutoError->getMessage());
    }
}
*/

// التأكد من عدم وجود محتوى قبل DOCTYPE - تنظيف أي output غير مرغوب
if (ob_get_level() > 0) {
    $bufferContent = ob_get_contents();
    // إذا كان هناك محتوى في الـ buffer ولا يبدأ بـ DOCTYPE، امسحه
    if (!empty(trim($bufferContent)) && stripos(trim($bufferContent), '<!DOCTYPE') !== 0) {
        ob_clean();
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <?php
    // منع zoom في صفحة الدردشة (الشات)
    $isChatPage = (isset($_GET['page']) && $_GET['page'] === 'chat');
    $viewportContent = $isChatPage 
        ? 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no' 
        : 'width=device-width, initial-scale=1.0';
    ?>
    <meta name="viewport" content="<?php echo htmlspecialchars($viewportContent, ENT_QUOTES, 'UTF-8'); ?>">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Permissions-Policy" content="geolocation=(self), camera=(self), microphone=(self)">
    <!-- تم إزالة Feature-Policy meta tag لأنه deprecated -->
    <!-- Cache Control Meta Tags - محسّن لـ bfcache (back/forward cache) -->
    <!-- تم تعديلها للسماح بـ bfcache مع الحفاظ على تحديث البيانات -->
    <meta http-equiv="Cache-Control" content="private, max-age=0, must-revalidate">
    <!-- إزالة Pragma و Expires لأنها تمنع bfcache -->
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <?php
    // تحديد pageDescription إذا لم يكن محدداً
    if (!isset($pageDescription)) {
        $pageDescription = 'نظام إدارة متكامل لشركة البركة - إدارة المخازن والمبيعات  ';
    }
    
    // تحديد Canonical URL
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $canonicalUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
    $ogImage = $baseUrl . ASSETS_URL . 'icons/icon-512x512.png';
    ?>
    
    <!-- Meta Tags for SEO -->
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="نظام إدارة, شركة البركة, إدارة المخازن, إدارة المبيعات, إدارة الموارد البشرية, نظام محاسبة, إدارة العملاء">
    <meta name="author" content="<?php echo COMPANY_NAME; ?>">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <meta name="language" content="Arabic">
    <meta name="geo.region" content="EG">
    <meta name="geo.placename" content="مصر">
    <meta name="application-name" content="<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    
    <!-- Open Graph Tags -->
    <meta property="og:title" content="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' - ' : ''; ?><?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:locale" content="ar_EG">
    <meta property="og:site_name" content="<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' - ' : ''; ?><?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    
    <?php
    // كشف الموبايل لتحسين الأداء (يجب أن يكون قبل استخدامه)
    if (!isset($isMobile)) {
        $isMobile = (bool) preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
    
    // تحديد ASSETS_URL بشكل صحيح (يجب أن يكون قبل استخدامه في preload)
    $assetsUrl = ASSETS_URL;
    // إذا كان ASSETS_URL يبدأ بـ //، أزل /
    if (strpos($assetsUrl, '//') === 0) {
        $assetsUrl = '/' . ltrim($assetsUrl, '/');
    }
    // إذا لم يبدأ بـ /، أضفه
    if (strpos($assetsUrl, '/') !== 0) {
        $assetsUrl = '/' . $assetsUrl;
    }
    // إزالة /assets/ المكرر
    $assetsUrl = rtrim($assetsUrl, '/') . '/';
    
    // استخدام رقم version ثابت لتحسين caching - يمكن تحديثه يدوياً عند الحاجة
    // بدلاً من time() لتجنب cache invalidation في كل طلب وتحسين الأداء
    $cacheVersion = defined('ASSETS_VERSION') ? ASSETS_VERSION : (defined('APP_VERSION') ? APP_VERSION : '1.0.0');
    ?>
    
    <!-- Performance: Preconnect to CDNs - محسّن للموبايل -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://code.jquery.com" crossorigin>
    <link rel="dns-prefetch" href="https://code.jquery.com">
    <!-- Google Fonts Preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Performance: Preload Critical Resources - محسّن لـ LCP -->
    <?php if (!$isMobile): ?>
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style" crossorigin="anonymous">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" as="style" crossorigin="anonymous">
    <link rel="preload" href="<?php echo $assetsUrl; ?>css/homeline-dashboard.css?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="<?php echo $assetsUrl; ?>css/topbar.css?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="https://code.jquery.com/jquery-3.7.0.min.js" as="script" crossorigin="anonymous">
    <link rel="preload" href="<?php echo $assetsUrl; ?>js/main.js?v=<?php echo $cacheVersion; ?>" as="script">
    <?php else: ?>
    <!-- Mobile: Preload Critical Resources فقط -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin="anonymous">
    <link rel="preload" href="<?php echo $assetsUrl; ?>css/homeline-dashboard.css?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="<?php echo $assetsUrl; ?>css/topbar.css?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="<?php echo $assetsUrl; ?>js/main.js?v=<?php echo $cacheVersion; ?>" as="script">
    <?php endif; ?>
    
    <!-- Bootstrap 5 CSS - تحميل غير متزامن على الموبايل لتحسين الأداء -->
    <?php if ($isMobile && !$isChatPage): ?>
    <!-- Mobile: تحميل Bootstrap CSS بشكل غير متزامن (ما عدا صفحة الشات) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" media="print" onload="this.media='all'" crossorigin="anonymous">
    <noscript><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous"></noscript>
    <?php else: ?>
    <!-- Desktop أو صفحة الشات: تحميل عادي لضمان التحميل الكامل -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <?php endif; ?>
    
    <!-- Bootstrap Icons - تحميل مشروط للموبايل (أقل) -->
    <?php if ($isMobile && !$isChatPage): ?>
    <!-- Mobile: تحميل Bootstrap Icons مع lazy loading (ما عدا صفحة الشات) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" media="print" onload="this.media='all'" crossorigin="anonymous">
    <noscript><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" crossorigin="anonymous"></noscript>
    <?php else: ?>
    <!-- Desktop أو صفحة الشات: تحميل عادي لضمان التحميل الكامل -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" crossorigin="anonymous">
    <?php endif; ?>
    
    <!-- CSS Async Loader Script (must be before async CSS) -->
    <script>
        !function(e){"use strict";var t=function(t,n,o){var i,r=e.document,a=r.createElement("link");if(n)i=n;else{var l=(r.body||r.getElementsByTagName("head")[0]).childNodes;i=l[l.length-1]}var d=r.styleSheets;a.rel="stylesheet",a.href=t,a.media="only x",function e(t){if(r.body)return t();setTimeout(function(){e(t)})}(function(){i.parentNode.insertBefore(a,n?i:i.nextSibling)});var f=function(e){for(var t=a.href,n=d.length;n--;)if(d[n].href===t)return e();setTimeout(function(){f(e)})};return a.addEventListener&&a.addEventListener("load",function(){this.media=o||"all"}),a.onloadcssdefined=f,f(function(){a.media!==o&&(a.media=o||"all")}),a};"undefined"!=typeof exports?exports.loadCSS=t:e.loadCSS=t}("undefined"!=typeof global?global:this);
    </script>
    
    <!-- Google Fonts - Cairo (for chat and other components) - محسّن مع font-display: swap -->
    <!-- Preconnect تم نقله للأعلى مع باقي preconnects (السطر 298-299) -->
    <!-- تم إزالة preload للخط لأن الرابط المباشر غير متاح - سيتم تحميل الخط عبر CSS -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet"></noscript>
    
    <!-- Custom CSS - Homeline Dashboard Design -->
    <!-- Critical CSS - تحميل مباشر (حرجة للتصيير الأولي) -->
    <link href="<?php echo $assetsUrl; ?>css/homeline-dashboard.css?v=<?php echo $cacheVersion; ?>" rel="preload" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/homeline-dashboard.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    <link href="<?php echo $assetsUrl; ?>css/topbar.css?v=<?php echo $cacheVersion; ?>" rel="preload" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/topbar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    <?php if ($isMobile): ?>
    <!-- Mobile: تحميل responsive.css بشكل غير متزامن -->
    <link href="<?php echo $assetsUrl; ?>css/responsive.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/responsive.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    <?php else: ?>
    <!-- Desktop: تحميل عادي -->
    <link href="<?php echo $assetsUrl; ?>css/responsive.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <?php endif; ?>
    
    <!-- Medium Priority CSS - تحميل مشروط -->
    <link href="<?php echo $assetsUrl; ?>css/sidebar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/sidebar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <!-- Modal Mobile Fix CSS - إصلاح النماذج على الهواتف المحمولة -->
    <link href="<?php echo $assetsUrl; ?>css/modal-mobile-fix.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/modal-mobile-fix.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <link href="<?php echo $assetsUrl; ?>css/cards.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/cards.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <link href="<?php echo $assetsUrl; ?>css/tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <!-- Mobile-specific CSS - تحميل فقط على الموبايل -->
    <?php if ($isMobile): ?>
    <link href="<?php echo $assetsUrl; ?>css/mobile-tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <?php else: ?>
    <!-- Desktop: تحميل مع lazy loading -->
    <link href="<?php echo $assetsUrl; ?>css/mobile-tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="(max-width: 767.98px)">
    <?php endif; ?>
    
    <!-- Low Priority CSS - تحميل متأخر -->
    <link href="<?php echo $assetsUrl; ?>css/pwa.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/pwa.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <link href="<?php echo $assetsUrl; ?>css/modal-iframe.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/modal-iframe.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <link href="<?php echo $assetsUrl; ?>css/dark-mode.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/dark-mode.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <!-- Accessibility Improvements - تحميل غير متزامن -->
    <link href="<?php echo $assetsUrl; ?>css/accessibility-improvements.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/accessibility-improvements.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <!-- Image Optimization - تحميل غير متزامن -->
    <link href="<?php echo $assetsUrl; ?>css/image-optimization.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/image-optimization.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    <?php if (!empty($pageStylesheets) && is_array($pageStylesheets)): ?>
        <?php foreach ($pageStylesheets as $stylesheetPath): ?>
            <?php
            if (!is_string($stylesheetPath)) {
                continue;
            }
            $stylesheetPath = trim($stylesheetPath);
            if ($stylesheetPath === '') {
                continue;
            }

            $hasProtocol = (bool) preg_match('#^https?://#i', $stylesheetPath);
            $isProtocolRelative = !$hasProtocol && strpos($stylesheetPath, '//') === 0;
            if ($hasProtocol || $isProtocolRelative) {
                $href = $stylesheetPath;
            } else {
                if (strpos($stylesheetPath, '/') === 0) {
                    $normalizedPath = preg_replace('#/+#', '/', $stylesheetPath);
                    $href = getRelativeUrl(ltrim($normalizedPath, '/'));
                } else {
                    $baseHref = (strpos($stylesheetPath, 'assets/') === 0)
                        ? '/' . ltrim($stylesheetPath, '/')
                        : rtrim($assetsUrl, '/') . '/' . ltrim($stylesheetPath, '/');
                    $href = getRelativeUrl(ltrim($baseHref, '/'));
                }
            }

            if (strpos($href, '?') === false) {
                $href .= '?v=' . $cacheVersion;
            }
            ?>
            <link href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($dir === 'rtl'): ?>
    <?php if ($isMobile): ?>
    <!-- Mobile: تحميل RTL CSS بشكل غير متزامن -->
    <link href="<?php echo $assetsUrl; ?>css/rtl.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/rtl.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    <?php else: ?>
    <!-- Desktop: تحميل عادي -->
    <link href="<?php echo $assetsUrl; ?>css/rtl.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <?php endif; ?>
    <?php endif; ?>
    
    <!-- Structured Data (JSON-LD) for SEO -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebApplication",
      "name": "<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>",
      "alternateName": "<?php echo htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8'); ?>",
      "description": "<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>",
      "url": "<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>",
      "applicationCategory": "BusinessApplication",
      "operatingSystem": "Web Browser",
      "softwareVersion": "<?php echo defined('APP_VERSION') ? htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') : '1.0.0'; ?>",
      "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "EGP",
        "availability": "https://schema.org/InStock"
      },
      "provider": {
        "@type": "Organization",
        "name": "<?php echo htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8'); ?>",
        "url": "<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>",
        "logo": {
          "@type": "ImageObject",
          "url": "<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>",
          "width": 512,
          "height": 512
        }
      },
      "inLanguage": "<?php echo $currentLang; ?>",
      "browserRequirements": "Requires JavaScript. Requires HTML5.",
      "featureList": [
        "إدارة المخازن",
        "إدارة المبيعات",
        "إدارة العملاء",
        "إدارة الموارد البشرية",
        "تقارير شاملة"
      ],
      "screenshot": "<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>"
    }
    </script>
    
    <!-- Additional Structured Data: Organization for SEO -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "<?php echo htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8'); ?>",
      "url": "<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>",
      "logo": "<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>",
      "contactPoint": {
        "@type": "ContactPoint",
        "contactType": "customer service",
        "areaServed": "EG",
        "availableLanguage": ["ar", "Arabic"]
      },
      "sameAs": []
    }
    </script>
    
    <!-- Additional Structured Data: BreadcrumbList for SEO -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      "itemListElement": [
        {
          "@type": "ListItem",
          "position": 1,
          "name": "<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>",
          "item": "<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>"
        },
        {
          "@type": "ListItem",
          "position": 2,
          "name": "<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'الصفحة'; ?>",
          "item": "<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>"
        }
      ]
    }
    </script>
    
    <style>
        /* Critical CSS - Above-the-fold styles */
        body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;background:#f8f9fa}
        .dashboard-wrapper{display:flex;min-height:100vh;background:#f8f9fa}
        .homeline-sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:#fff;border-right:1px solid #e5e7eb;z-index:1000;overflow-y:auto}
        .homeline-topbar{position:fixed;top:0;left:260px;right:0;height:64px;background:#fff;border-bottom:1px solid #e5e7eb;z-index:999;display:flex;align-items:center;padding:0 24px;box-shadow:0 1px 2px rgba(0,0,0,.05)}
        [dir="rtl"] .homeline-topbar{left:0;right:260px}
        .dashboard-main{flex:1;margin-left:260px;padding-top:64px}
        [dir="rtl"] .dashboard-main{margin-left:0;margin-right:260px}
        @media (max-width:768px){
            .homeline-topbar{left:0!important;right:0!important;width:100%!important;height:56px;padding:0 8px}
            .homeline-sidebar{transform:translateX(-100%);z-index:1050}
            .dashboard-main{margin-left:0!important;margin-right:0!important}
        }
        
        /* منع Layout forced - إخفاء المحتوى حتى تحميل CSS */
        body:not(.css-loaded) {
            visibility: hidden;
        }
        body.css-loaded {
            visibility: visible;
        }
        
        /* Accessibility: Skip to main content link */
        .skip-link {
            position: absolute;
            top: -40px;
            left: 0;
            background: #000;
            color: #fff;
            padding: 8px 16px;
            text-decoration: none;
            z-index: 10000;
            border-radius: 0 0 4px 0;
        }
        .skip-link:focus {
            top: 0;
            outline: 3px solid #3498db;
            outline-offset: 2px;
        }
        
        /* Accessibility: Enhanced Focus Indicators */
        *:focus-visible {
            outline: 3px solid #3498db;
            outline-offset: 2px;
            border-radius: 2px;
        }
        button:focus-visible,
        a:focus-visible,
        input:focus-visible,
        select:focus-visible,
        textarea:focus-visible {
            outline: 3px solid #3498db;
            outline-offset: 2px;
        }
        
        /* Accessibility: Visually Hidden but accessible to screen readers */
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }
        .visually-hidden-focusable:focus {
            position: static;
            width: auto;
            height: auto;
            padding: inherit;
            margin: inherit;
            overflow: visible;
            clip: auto;
            white-space: normal;
        }
        
        /* Performance: Font Display Optimization - تحسين عرض الخطوط */
        @font-face {
            font-family: 'bootstrap-icons';
            font-display: swap;
        }
        
        @font-face {
            font-family: 'Cairo';
            font-display: swap;
        }
        
        /* تحسين تحميل الخطوط - استخدام fallback fonts */
        body {
            font-family: 'Cairo', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        /* منع FOIT (Flash of Invisible Text) */
        @font-face {
            font-family: 'Cairo';
            font-display: swap;
            font-weight: 300;
        }
        @font-face {
            font-family: 'Cairo';
            font-display: swap;
            font-weight: 400;
        }
        @font-face {
            font-family: 'Cairo';
            font-display: swap;
            font-weight: 500;
        }
        @font-face {
            font-family: 'Cairo';
            font-display: swap;
            font-weight: 600;
        }
        @font-face {
            font-family: 'Cairo';
            font-display: swap;
            font-weight: 700;
        }
        
        /* Layout Shift Prevention - منع تغييرات التخطيط */
        /* Aspect ratio containers للصور */
        .img-container {
            position: relative;
            width: 100%;
            overflow: hidden;
        }
        
        .img-container img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Placeholder للصور أثناء التحميل */
        img[loading="lazy"] {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
        }
        
        /* Aspect ratios شائعة */
        .aspect-ratio-16-9 { aspect-ratio: 16 / 9; }
        .aspect-ratio-4-3 { aspect-ratio: 4 / 3; }
        .aspect-ratio-1-1 { aspect-ratio: 1 / 1; }
        .aspect-ratio-3-2 { aspect-ratio: 3 / 2; }
        
        /* منع Layout Shift للعناصر الديناميكية */
        .card, .modal-content, .table {
            min-height: 1px;
        }
    </style>
    <script>
        // CSS Async Loader - تحميل CSS بشكل غير متزامن
        (function() {
            function loadCSS(href) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                var head = document.getElementsByTagName('head')[0];
                head.appendChild(link);
            }
            // دالة لتحويل preload إلى stylesheet
            window.loadCSSAsync = function(href) {
                if (document.querySelector('link[href="' + href + '"]')) {
                    return;
                }
                loadCSS(href);
            };
        })();
        
        window.APP_CONFIG = window.APP_CONFIG || {};
        window.APP_CONFIG.passwordMinLength = <?php echo json_encode(getPasswordMinLength(), JSON_UNESCAPED_UNICODE); ?>;
        
        // دالة للتحقق من تحميل جميع stylesheets
        window.stylesheetsLoaded = false;
        (function() {
            function checkStylesheets() {
                const stylesheets = document.querySelectorAll('link[rel="stylesheet"]');
                let allLoaded = true;
                
                stylesheets.forEach(function(link) {
                    if (!link.sheet && link.href && !link.href.startsWith('data:')) {
                        allLoaded = false;
                    }
                });
                
                if (allLoaded && stylesheets.length > 0) {
                    window.stylesheetsLoaded = true;
                    document.dispatchEvent(new CustomEvent('stylesheetsLoaded'));
                } else if (stylesheets.length === 0) {
                    window.stylesheetsLoaded = true;
                    document.dispatchEvent(new CustomEvent('stylesheetsLoaded'));
                } else {
                    setTimeout(checkStylesheets, 50);
                }
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(checkStylesheets, 100);
                });
            } else {
                setTimeout(checkStylesheets, 100);
            }
            
            // Fallback: اعتبارها محملة بعد وقت معقول
            window.addEventListener('load', function() {
                setTimeout(function() {
                    if (!window.stylesheetsLoaded) {
                        window.stylesheetsLoaded = true;
                        document.dispatchEvent(new CustomEvent('stylesheetsLoaded'));
                    }
                    // إظهار المحتوى بعد تحميل CSS
                    document.body.classList.add('css-loaded');
                }, 300);
            });
            
            // عند تحميل stylesheets، أظهر المحتوى
            document.addEventListener('stylesheetsLoaded', function() {
                document.body.classList.add('css-loaded');
            });
        })();
        
        // Performance: تحسين تحميل CSS بشكل غير متزامن على الموبايل
        <?php if ($isMobile): ?>
        (function() {
            // تحميل CSS بشكل غير متزامن باستخدام media="print" trick
            function loadCSS(href, onload) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.media = 'print';
                link.onload = function() {
                    this.media = 'all';
                    if (onload) onload();
                };
                document.head.appendChild(link);
            }
            
            // تحسين تحميل CSS المتبقي بعد تحميل الصفحة
            window.addEventListener('load', function() {
                setTimeout(function() {
                    // تحميل CSS غير الحرجة بشكل متأخر
                    const lazyCSS = document.querySelectorAll('link[rel="stylesheet"][media="print"]');
                    lazyCSS.forEach(function(link) {
                        if (link.onload) {
                            link.onload();
                        } else {
                            link.media = 'all';
                        }
                    });
                }, 100);
            });
        })();
        <?php endif; ?>
    </script>
    
    <!-- Favicon -->
    <?php 
    // Use existing favicon.svg if available, otherwise use PNG icons
    $faviconSvg = __DIR__ . '/../assets/icons/favicon.svg';
    $icon32x32 = __DIR__ . '/../assets/icons/icon-32x32.png';
    $icon16x16 = __DIR__ . '/../assets/icons/icon-16x16.png';
    ?>
    <?php if (file_exists($faviconSvg)): ?>
    <link rel="icon" type="image/svg+xml" href="<?php echo ASSETS_URL; ?>icons/favicon.svg">
    <?php endif; ?>
    <?php if (file_exists($icon32x32)): ?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo ASSETS_URL; ?>icons/icon-32x32.png">
    <?php endif; ?>
    <?php if (file_exists($icon16x16)): ?>
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo ASSETS_URL; ?>icons/icon-16x16.png">
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/../favicon.ico')): ?>
    <link rel="icon" type="image/x-icon" href="<?php echo getRelativeUrl('favicon.ico'); ?>">
    <link rel="shortcut icon" href="<?php echo getRelativeUrl('favicon.ico'); ?>">
    <?php endif; ?>
    
    <!-- Apple Touch Icons -->
    <?php if (file_exists(__DIR__ . '/../assets/icons/apple-touch-icon.png')): ?>
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo ASSETS_URL; ?>icons/apple-touch-icon.png">
    <?php else: ?>
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo ASSETS_URL; ?>icons/apple-touch-icon.svg">
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/../assets/icons/icon-152x152.png')): ?>
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo ASSETS_URL; ?>icons/icon-152x152.png">
    <?php else: ?>
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo ASSETS_URL; ?>icons/icon-152x152.svg">
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/../assets/icons/icon-144x144.png')): ?>
    <link rel="apple-touch-icon" sizes="144x144" href="<?php echo ASSETS_URL; ?>icons/icon-144x144.png">
    <?php else: ?>
    <link rel="apple-touch-icon" sizes="144x144" href="<?php echo ASSETS_URL; ?>icons/icon-144x144.svg">
    <?php endif; ?>
    
    <!-- Android Icons -->
    <?php if (file_exists(__DIR__ . '/../assets/icons/icon-192x192.png')): ?>
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo ASSETS_URL; ?>icons/icon-192x192.png">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml" sizes="192x192" href="<?php echo ASSETS_URL; ?>icons/icon-192x192.svg">
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/../assets/icons/icon-512x512.png')): ?>
    <link rel="icon" type="image/png" sizes="512x512" href="<?php echo ASSETS_URL; ?>icons/icon-512x512.png">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml" sizes="512x512" href="<?php echo ASSETS_URL; ?>icons/icon-512x512.svg">
    <?php endif; ?>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#f1c40f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo APP_NAME; ?>">
    <meta name="mobile-web-app-capable" content="yes">
    
    <!-- Android PWA Meta Tags -->
    <meta name="application-name" content="<?php echo APP_NAME; ?>">
    <meta name="msapplication-TileColor" content="#f1c40f">
    <meta name="msapplication-tap-highlight" content="no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo APP_NAME; ?>">
    
    <!-- Manifest -->
    <link rel="manifest" href="<?php echo getRelativeUrl('manifest.php'); ?>">
    
    <!-- Android Chrome PWA Support -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#f1c40f">
    <meta name="color-scheme" content="light">
    
    <!-- Modal Fix CSS -->
    <style>
        /* التأكد من أن الأزرار والعناصر التفاعلية قابلة للنقر */
        .topbar-action:not([style*="pointer-events: none"]),
        .topbar-action:not([style*="pointer-events: none"]) *,
        button:not([style*="pointer-events: none"]),
        input[type="checkbox"]:not([style*="pointer-events: none"]),
        input[type="button"]:not([style*="pointer-events: none"]),
        input[type="submit"]:not([style*="pointer-events: none"]),
        a.topbar-action:not([style*="pointer-events: none"]) {
            pointer-events: auto !important;
            z-index: auto !important;
            position: relative !important;
        }
        
        /* منع التفاعل مع العناصر المعطلة */
        .topbar-action.disabled-action,
        .topbar-action.disabled-action *,
        .topbar-user.disabled-action,
        .topbar-user.disabled-action *,
        .dropdown-item.disabled-action,
        .dropdown-item.disabled-action * {
            pointer-events: none !important;
            cursor: not-allowed !important;
            user-select: none !important;
        }
        
        /* التأكد من أن topbar قابلة للنقر */
        .homeline-topbar,
        .homeline-topbar * {
            pointer-events: auto !important;
        }
        
        /* استثناء العناصر المعطلة من topbar */
        .homeline-topbar .disabled-action,
        .homeline-topbar .disabled-action * {
            pointer-events: none !important;
        }
        
        /* إصلاح Modal - قيم z-index صحيحة */
        .modal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 1055 !important;
            width: 100% !important;
            height: 100% !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
            outline: 0 !important;
        }
        
        .modal.show {
            display: block !important;
        }
        
        .modal:not(.show) {
            display: none !important;
        }
        
        /* إزالة أي transitions قديمة قد تسبب lag */
        .modal:not(.show) .modal-dialog {
            transition: none !important;
        }
        
        .modal-backdrop {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 1050 !important;
            width: 100vw !important;
            height: 100vh !important;
            background-color: rgba(0, 0, 0, 0.5) !important;
            opacity: 1 !important;
        }
        
        .modal-backdrop.show {
            opacity: 0.5 !important;
        }
        
        /* التأكد من وجود backdrop واحد فقط */
        .modal-backdrop ~ .modal-backdrop {
            display: none !important;
        }
        
        .modal-dialog {
            position: relative !important;
            width: auto !important;
            margin: 1.75rem auto !important;
            pointer-events: none !important;
            z-index: auto !important;
        }
        
        .modal.show .modal-dialog {
            pointer-events: auto !important;
        }
        
        .modal-content {
            position: relative !important;
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            pointer-events: auto !important;
            background-color: #fff !important;
            background-clip: padding-box !important;
            border: 1px solid rgba(0, 0, 0, 0.2) !important;
            border-radius: 0.3rem !important;
            outline: 0 !important;
            z-index: auto !important;
        }
        
        /* منع تداخل الجداول مع الـ modal */
        .dashboard-table-wrapper,
        .table-responsive,
        .table {
            position: relative !important;
            z-index: 1 !important;
        }
        
        .dashboard-table thead th {
            position: sticky !important;
            z-index: 10 !important;
        }
        
        /* إصلاح مشكلة عدم ظهور خيارات الـ dropdown في النماذج */
        /* السماح لخيارات select بالظهور خارج modal-body */
        .modal-content {
            overflow: visible !important;
        }
        
        .modal-dialog {
            overflow: visible !important;
        }
        
        /* التأكد من أن modal-content لا يمنع التمرير */
        .modal-dialog-scrollable .modal-content {
            max-height: 100%;
            overflow: hidden;
        }
        
        /* إصلاح خاص للـ modal-body - السماح للـ dropdown بالظهور */
        .modal-body {
            overflow-x: hidden !important;
            /* لا نضبط overflow-y هنا حتى لا نكسر الـ scroll */
            position: relative;
        }
        
        /* للنماذج التي تحتوي على overflow-y في الـ style مباشرة */
        .modal-body[style*="overflow-y: auto"],
        .modal-body[style*="overflow-y:auto"],
        .modal-body[style*="overflow-y: scroll"],
        .modal-body[style*="overflow-y:scroll"] {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            position: relative;
            /* السماح للعناصر المطلقة الموضع بالظهور خارج الحدود */
            contain: none !important;
        }
        
        /* إصلاح خاص لـ modal-dialog-scrollable */
        .modal-dialog-scrollable {
            overflow: visible !important;
        }
        
        .modal-dialog-scrollable .modal-body {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            position: relative;
            contain: none !important;
            max-height: calc(100vh - 200px) !important;
        }
        
        /* للتأكد من أن النماذج الكبيرة قابلة للتمرير */
        .modal-dialog.modal-lg.modal-dialog-scrollable .modal-body {
            max-height: calc(100vh - 180px) !important;
        }
        
        .modal-dialog.modal-xl.modal-dialog-scrollable .modal-body {
            max-height: calc(100vh - 160px) !important;
        }
        
        /* التأكد من أن modal-content لا يمنع التمرير */
        .modal-dialog-scrollable .modal-content {
            max-height: 100%;
            overflow: hidden;
        }
        
        /* إصلاح إضافي: التأكد من أن modal-dialog-scrollable يعمل بشكل صحيح */
        .modal-dialog-scrollable {
            height: auto !important;
            max-height: calc(100vh - 1rem) !important;
        }
        
        .modal-dialog-scrollable .modal-content {
            height: auto !important;
            max-height: 100% !important;
        }
        
        /* التأكد من أن select dropdown يظهر بشكل صحيح */
        .modal-body select.form-select,
        .modal-body select {
            position: relative;
        }
        
        .modal-body select.form-select:focus,
        .modal-body select.form-select:active,
        .modal-body select:focus,
        .modal-body select:active {
            z-index: 1060 !important;
            position: relative;
        }
        
        /* حل خاص: عندما يكون select في modal-body مع overflow */
        .modal.show .modal-body {
            /* السماح بالتداخل للعناصر المطلقة الموضع */
            contain: none !important;
        }
        
        /* إصلاح لـ Bootstrap 5 select في modals */
        .modal-body .form-select:focus {
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        /* حل إضافي: التأكد من أن dropdown options يمكنها الظهور */
        /* هذا يساعد مع native HTML select elements */
        .modal.show {
            overflow: visible !important;
        }
        
        .modal.show .modal-dialog {
            overflow: visible !important;
        }
        
        /* تأثير انسدال سلس (slide down) للـ modals عند فتحها - يطبق على جميع الـ modals */
        /* استخدام GPU acceleration لتحسين الأداء وإزالة الـ lag */
        .modal .modal-dialog,
        .modal.fade .modal-dialog,
        .modal:not(.fade) .modal-dialog {
            will-change: transform, opacity;
            backface-visibility: hidden;
            perspective: 1000px;
            transform: translate3d(0, -30px, 0) !important;
            opacity: 0 !important;
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.25s ease-out !important;
        }
        
        /* حالة الـ modal عند الفتح - animation سلس */
        .modal.show .modal-dialog,
        .modal.fade.show .modal-dialog,
        .modal.showing .modal-dialog,
        .modal:not(.fade).show .modal-dialog {
            transform: translate3d(0, 0, 0) !important;
            opacity: 1 !important;
        }
        
        /* حالة البداية - قبل إظهار الـ modal (لا transition هنا لتجنب lag) */
        .modal:not(.show):not(.showing) .modal-dialog,
        .modal.fade:not(.show):not(.showing) .modal-dialog,
        .modal:not(.fade):not(.show):not(.showing) .modal-dialog {
            transform: translate3d(0, -30px, 0) !important;
            opacity: 0 !important;
            transition: none !important;
        }
        
        /* تأثير fade in سلس للـ backdrop */
        .modal-backdrop {
            will-change: opacity;
            transition: opacity 0.2s ease-out !important;
        }
        
        .modal-backdrop.fade {
            opacity: 0 !important;
        }
        
        .modal-backdrop.show {
            opacity: 0.5 !important;
        }
        
        /* تحسين الأداء - منع reflow/repaint غير الضروري */
        .modal.show .modal-dialog,
        .modal.fade.show .modal-dialog {
            will-change: auto;
        }
        
        /* لوجو PWA */
        .loader-logo {
            width: 180px;
            height: 180px;
            margin-bottom: 2rem;
            animation: logoFadeIn 0.8s ease-out, logoFloat 3s ease-in-out infinite 0.8s;
            filter: drop-shadow(0 8px 25px rgba(0, 0, 0, 0.3));
        }
        
        @keyframes logoFadeIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        /* العنوان */
        .loader-title {
            color: white;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: 2px;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            animation: titleFadeIn 1s ease-out 0.3s both;
        }
        
        @keyframes titleFadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* حاوية السبينر - مخفية */
        .loader-spinner {
            display: none;
        }
        
        /* الدوائر المتحركة - مخفية */
        .spinner-circle {
            border-top-color: rgba(79, 172, 254, 1);
            border-right-color: rgba(79, 172, 254, 0.6);
            animation-duration: 2s;
            animation-direction: reverse;
            box-shadow: 0 0 20px rgba(79, 172, 254, 0.5);
        }
        
        .spinner-circle:nth-child(3) {
            border-top-color: rgba(0, 242, 254, 1);
            border-right-color: rgba(0, 242, 254, 0.5);
            animation-duration: 2.5s;
            box-shadow: 0 0 20px rgba(0, 242, 254, 0.5);
        }
        
        /* نص التحميل */
        .loader-text {
            color: white;
            font-size: 1.1rem;
            margin-top: 2.5rem;
            font-weight: 600;
            letter-spacing: 2px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        /* النقاط المتحركة */
        .loading-dots {
            display: inline-block;
        }
        
        .loading-dots span {
            animation: blink 1.4s infinite;
            animation-fill-mode: both;
        }
        
        .loading-dots span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .loading-dots span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        /* شريط التقدم - تدرج أزرق */
        .loader-progress {
            width: 280px;
            height: 5px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 2.5rem;
            box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        .loader-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, 
                #f1c40f 0%, 
                #fff 25%, 
                #f4d03f 50%, 
                #fff 75%, 
                #f1c40f 100%
            );
            background-size: 200% 100%;
            animation: progressMove 1.8s ease-in-out infinite;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(241, 196, 15, 0.8), 0 0 30px rgba(244, 208, 63, 0.6);
        }
        
        /* الأنيميشنات */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.85; }
        }
        
        @keyframes blink {
            0%, 80%, 100% { opacity: 0.3; }
            40% { opacity: 1; }
        }
        
        @keyframes progressMove {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* تأثير التلاشي للمحتوى */
        .content-fade-in {
            animation: contentFadeIn 0.6s ease-out forwards;
        }
        
        @keyframes contentFadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* تأثير للروابط */
        a {
            transition: all 0.3s ease;
        }
    </style>
    
    <!-- Service Worker Registration with Auto-Update -->
    <script>
        // تفعيل Service Worker لعرض صفحة offline عند عدم الاتصال
        if ('serviceWorker' in navigator) {
            let registration;
            let updateCheckInterval;
            
            window.addEventListener('load', async function() {
                // حساب مسار Service Worker - استخدام مسار مطلق بسيط
                const currentPath = window.location.pathname;
                const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && !p.endsWith('.php'));
                
                // حساب المسار من الجذر (ديناميكي - يعمل مع أي مسار)
                let swPath = '/service-worker.js';
                if (pathParts.length > 0) {
                    // إذا كنا في مجلد فرعي مثل /v1/ أو /tr/
                    swPath = '/' + pathParts[0] + '/service-worker.js';
                }
                
                const scope = pathParts.length > 0 ? '/' + pathParts[0] + '/' : '/';
                
                // CRITICAL FIX: إجبار إلغاء تسجيل service worker ثم إعادة تسجيله
                // هذا يضمن استخدام النسخة الجديدة دائماً وعدم استخدام النسخة القديمة
                try {
                    // الحصول على جميع service workers المسجلة
                    const registrations = await navigator.serviceWorker.getRegistrations();
                    
                    // إلغاء تسجيل جميع service workers الموجودة
                    const unregisterPromises = [];
                    for (let reg of registrations) {
                        const regScope = reg.scope;
                        const currentOrigin = window.location.origin;
                        
                        // إلغاء تسجيل service workers في نفس النطاق
                        if (regScope.startsWith(currentOrigin)) {
                            console.log('Unregistering old service worker:', regScope);
                            unregisterPromises.push(
                                reg.unregister().then(success => {
                                    if (success) {
                                        console.log('Service worker unregistered successfully:', regScope);
                                    } else {
                                        console.log('Failed to unregister service worker:', regScope);
                                    }
                                }).catch(err => {
                                    console.log('Error unregistering service worker:', regScope, err);
                                })
                            );
                        }
                    }
                    
                    // انتظار إكمال جميع عمليات إلغاء التسجيل
                    if (unregisterPromises.length > 0) {
                        await Promise.allSettled(unregisterPromises);
                        // الانتظار قليلاً للتأكد من إكمال إلغاء التسجيل
                        await new Promise(resolve => setTimeout(resolve, 200));
                    }
                    
                    console.log('Old service workers unregistered, registering new one...');
                } catch (error) {
                    console.log('Error unregistering service workers:', error);
                }
                
                // إعادة تسجيل service worker الجديد
                navigator.serviceWorker.register(swPath, { scope: scope })
                    .then(function(reg) {
                        registration = reg;
                        console.log('Service Worker registered:', reg);
                        
                        // تعطيل التحقق التلقائي من التحديثات لتجنب إعادة التحميل المستمرة
                        // reg.update();
                        
                        // التحقق من التحديثات كل 5 دقائق - معطل مؤقتاً
                        // updateCheckInterval = setInterval(function() {
                        //     reg.update().catch(function(error) {
                        //         console.log('Update check failed:', error);
                        //     });
                        // }, 5 * 60 * 1000); // 5 دقائق
                        
                        // تم إزالة إشعار Service Worker Update - استخدام إشعار التحديث الفعلي من footer.php بدلاً منه
                        // لا حاجة لإظهار إشعار Service Worker منفصل
                        reg.addEventListener('updatefound', function() {
                            const newWorker = reg.installing;
                            
                            newWorker.addEventListener('statechange', function() {
                                if (newWorker.state === 'installed') {
                                    if (!navigator.serviceWorker.controller) {
                                        // أول تثبيت
                                        console.log('Service Worker installed for the first time');
                                    }
                                }
                                
                                if (newWorker.state === 'activated') {
                                    console.log('Service Worker activated');
                                }
                            });
                        });
                        
                        // الاستماع للرسائل من Service Worker (معطل لتجنب التكرار)
                        // تم تعطيله لأن الإشعارات يتم التعامل معها في updatefound event فقط
                        // navigator.serviceWorker.addEventListener('message', function(event) {
                        //     if (event.data && event.data.type === 'SW_ACTIVATED') {
                        //         console.log('New Service Worker activated, cache:', event.data.cacheName);
                        //         // لا نعرض إشعار هنا لتجنب التكرار
                        //     }
                        // });
                    })
                    .catch(function(error) {
                        if (error.message && error.message.includes('CORS')) {
                            console.log('Service Worker registration skipped due to CORS policy');
                            return;
                        }
                        console.log('Service Worker registration failed:', error);
                    });
            });
            
            // تم إزالة إشعار Service Worker Update - استخدام إشعار التحديث الفعلي من footer.php بدلاً منه
            
            // إعادة تحميل عند تركيز النافذة (للتحقق من التحديثات)
            window.addEventListener('focus', function() {
                if (registration) {
                    registration.update().catch(function(error) {
                        console.log('Update check on focus failed:', error);
                    });
                }
            });
            
            // تنظيف عند إغلاق الصفحة - استخدام pagehide لإعادة تفعيل bfcache
            window.addEventListener('pagehide', function() {
                if (updateCheckInterval) {
                    clearInterval(updateCheckInterval);
                }
            });
        }
        
        // إلغاء تسجيل Service Workers القديمة إذا كانت موجودة
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(function(registrations) {
                for(let registration of registrations) {
                    registration.unregister().then(function(success) {
                        if (success) {
                            console.log('Old Service Worker unregistered');
                        }
                    });
                }
            });
        }
    </script>
    <!-- منع الضغط بالزر الأيمن وفتح أدوات المطور -->
    <script>
    (function() {
        'use strict';
        
        // تم تعطيل منع الضغط بالزر الأيمن للسماح بفتح أدوات المطور
        // document.addEventListener('contextmenu', function(e) {
        //     e.preventDefault();
        //     return false;
        // }, true);
        
        // تم تعطيل منع اختصارات لوحة المفاتيح للسماح بفتح أدوات المطور
        // document.addEventListener('keydown', function(e) {
        //     // F12 - فتح أدوات المطور
        //     if (e.keyCode === 123) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+Shift+I - فتح أدوات المطور
        //     if (e.ctrlKey && e.shiftKey && e.keyCode === 73) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+Shift+J - فتح Console
        //     if (e.ctrlKey && e.shiftKey && e.keyCode === 74) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+Shift+C - فتح Element Inspector
        //     if (e.ctrlKey && e.shiftKey && e.keyCode === 67) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+U - عرض مصدر الصفحة
        //     if (e.ctrlKey && e.keyCode === 85) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+S - حفظ الصفحة
        //     if (e.ctrlKey && e.keyCode === 83) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+P - طباعة
        //     if (e.ctrlKey && e.keyCode === 80) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+Shift+P - Command Palette (في بعض المتصفحات)
        //     if (e.ctrlKey && e.shiftKey && e.keyCode === 80) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+Shift+K - Network Monitor (في Firefox)
        //     if (e.ctrlKey && e.shiftKey && e.keyCode === 75) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        //     
        //     // Ctrl+Shift+E - Network Panel (في Chrome)
        //     if (e.ctrlKey && e.shiftKey && e.keyCode === 69) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        // }, true);
        
        // تم تعطيل منع فتح أدوات المطور عبر DevTools API
        // (function() {
        //     var devtools = {
        //         open: false,
        //         orientation: null
        //     };
        //     var threshold = 160;
        //     
        //     setInterval(function() {
        //         if (window.outerHeight - window.innerHeight > threshold || 
        //             window.outerWidth - window.innerWidth > threshold) {
        //             if (!devtools.open) {
        //                 devtools.open = true;
        //                 // يمكن إضافة إجراء هنا مثل إعادة تحميل الصفحة
        //                 // window.location.reload();
        //             }
        //         } else {
        //             if (devtools.open) {
        //                 devtools.open = false;
        //             }
        //         }
        //     }, 500);
        // })();
        
        // تم تعطيل منع فحص العناصر (Inspect Element)
        // document.addEventListener('keydown', function(e) {
        //     // Ctrl+Shift+C
        //     if (e.ctrlKey && e.shiftKey && (e.keyCode === 67 || e.keyCode === 73)) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //         return false;
        //     }
        // }, true);
        
        // تم تعطيل منع فتح أدوات المطور عبر Console API
        // (function() {
        //     var noop = function() {};
        //     var methods = ['log', 'debug', 'info', 'warn', 'error', 'assert', 'dir', 'dirxml', 
        //                  'group', 'groupEnd', 'time', 'timeEnd', 'count', 'trace', 'profile', 'profileEnd'];
        //     var length = methods.length;
        //     var console = (window.console = window.console || {});
        //     
        //     while (length--) {
        //         console[methods[length]] = noop;
        //     }
        // })();
        
        // تم تعطيل منع فتح أدوات المطور عبر Debugger
        // setInterval(function() {
        //     (function() {
        //         return false;
        //     })('devtools');
        // }, 4000);
        
        // تم تعطيل كل كود منع أدوات المطور للسماح بفتحها
        /*
        // منع فتح أدوات المطور عبر Console.clear
        if (window.console && window.console.clear) {
            window.console.clear = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.log
        if (window.console && window.console.log) {
            window.console.log = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.debug
        if (window.console && window.console.debug) {
            window.console.debug = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.info
        if (window.console && window.console.info) {
            window.console.info = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.warn
        if (window.console && window.console.warn) {
            window.console.warn = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.error
        if (window.console && window.console.error) {
            window.console.error = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.trace
        if (window.console && window.console.trace) {
            window.console.trace = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.table
        if (window.console && window.console.table) {
            window.console.table = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.group
        if (window.console && window.console.group) {
            window.console.group = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.groupEnd
        if (window.console && window.console.groupEnd) {
            window.console.groupEnd = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.time
        if (window.console && window.console.time) {
            window.console.time = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.timeEnd
        if (window.console && window.console.timeEnd) {
            window.console.timeEnd = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.count
        if (window.console && window.console.count) {
            window.console.count = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.dir
        if (window.console && window.console.dir) {
            window.console.dir = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.dirxml
        if (window.console && window.console.dirxml) {
            window.console.dirxml = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.profile
        if (window.console && window.console.profile) {
            window.console.profile = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.profileEnd
        if (window.console && window.console.profileEnd) {
            window.console.profileEnd = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.assert
        if (window.console && window.console.assert) {
            window.console.assert = function() {};
        }
        
        // منع فتح أدوات المطور عبر Console.trace
        if (window.console && window.console.trace) {
            window.console.trace = function() {};
        }
        
        // منع فتح أدوات المطور عبر Debugger Statement
        setInterval(function() {
            try {
                eval('debugger');
            } catch (e) {
                // تجاهل الأخطاء
            }
        }, 1000);
        
        // منع فتح أدوات المطور عبر window.open - لكن السماح بالاستخدامات المشروعة
        var originalOpen = window.open;
        window.open = function(url, target, features) {
            // السماح بفتح النوافذ المشروعة (تقارير، روابط خارجية، إلخ)
            if (url && typeof url === 'string') {
                // التحقق من أن الرابط صحيح وليس محاولة لفتح أدوات المطور
                const lowerUrl = url.toLowerCase();
                // منع محاولات فتح أدوات المطور
                if (lowerUrl.includes('devtools') || 
                    lowerUrl.includes('chrome-devtools') || 
                    lowerUrl.includes('javascript:void') ||
                    lowerUrl === 'about:blank' && !features) {
                    return null;
                }
                // السماح بجميع الروابط الأخرى (تقارير، روابط خارجية، إلخ)
                return originalOpen.call(window, url, target || '_blank', features);
            }
            // إذا لم يكن هناك رابط، منع الفتح (محاولة مشبوهة)
            return null;
        };
        
        // منع فتح أدوات المطور عبر document.write
        var originalWrite = document.write;
        document.write = function() {
            return false;
        };
        
        // منع فتح أدوات المطور عبر document.writeln
        var originalWriteln = document.writeln;
        document.writeln = function() {
            return false;
        };
        
        // منع فتح أدوات المطور عبر eval
        var originalEval = window.eval;
        window.eval = function() {
            return null;
        };
        
        // منع فتح أدوات المطور عبر Function constructor
        var originalFunction = window.Function;
        window.Function = function() {
            return function() {};
        };
        
        // منع فتح أدوات المطور عبر setTimeout مع eval
        var originalSetTimeout = window.setTimeout;
        window.setTimeout = function(func, delay) {
            if (typeof func === 'string') {
                return originalSetTimeout(function() {}, delay);
            }
            return originalSetTimeout(func, delay);
        };
        
        // منع فتح أدوات المطور عبر setInterval مع eval
        var originalSetInterval = window.setInterval;
        window.setInterval = function(func, delay) {
            if (typeof func === 'string') {
                return originalSetInterval(function() {}, delay);
            }
            return originalSetInterval(func, delay);
        };
        
        // منع فتح أدوات المطور عبر Object.defineProperty
        try {
            Object.defineProperty(document, 'hidden', {
                get: function() {
                    return false;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ visibilityState
        try {
            Object.defineProperty(document, 'visibilityState', {
                get: function() {
                    return 'visible';
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ webkitVisibilityState
        try {
            Object.defineProperty(document, 'webkitVisibilityState', {
                get: function() {
                    return 'visible';
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ mozVisibilityState
        try {
            Object.defineProperty(document, 'mozVisibilityState', {
                get: function() {
                    return 'visible';
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ msVisibilityState
        try {
            Object.defineProperty(document, 'msVisibilityState', {
                get: function() {
                    return 'visible';
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ hasFocus
        try {
            Object.defineProperty(document, 'hasFocus', {
                get: function() {
                    return true;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ activeElement
        try {
            Object.defineProperty(document, 'activeElement', {
                get: function() {
                    return document.body;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ focused
        try {
            Object.defineProperty(document, 'focused', {
                get: function() {
                    return true;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ mozHidden
        try {
            Object.defineProperty(document, 'mozHidden', {
                get: function() {
                    return false;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ webkitHidden
        try {
            Object.defineProperty(document, 'webkitHidden', {
                get: function() {
                    return false;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ msHidden
        try {
            Object.defineProperty(document, 'msHidden', {
                get: function() {
                    return false;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ hidden
        try {
            Object.defineProperty(document, 'hidden', {
                get: function() {
                    return false;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ visibilitychange
        try {
            Object.defineProperty(document, 'visibilitychange', {
                get: function() {
                    return null;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ webkitvisibilitychange
        try {
            Object.defineProperty(document, 'webkitvisibilitychange', {
                get: function() {
                    return null;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ mozvisibilitychange
        try {
            Object.defineProperty(document, 'mozvisibilitychange', {
                get: function() {
                    return null;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ msvisibilitychange
        try {
            Object.defineProperty(document, 'msvisibilitychange', {
                get: function() {
                    return null;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ onvisibilitychange
        try {
            Object.defineProperty(document, 'onvisibilitychange', {
                get: function() {
                    return null;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ onwebkitvisibilitychange
        try {
            Object.defineProperty(document, 'onwebkitvisibilitychange', {
                get: function() {
                    return null;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        */
        
        // تم تعطيل باقي كود منع أدوات المطور
        /*
        // منع فتح أدوات المطور عبر Object.defineProperty للـ onmozvisibilitychange
        try {
            Object.defineProperty(document, 'onmozvisibilitychange', {
                get: function() {
                    return null;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ onmsvisibilitychange
        try {
            Object.defineProperty(document, 'onmsvisibilitychange', {
                get: function() {
                    return null;
                }
            });
        } catch (e) {
            // تجاهل الأخطاء
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ addEventListener
        var originalAddEventListener = document.addEventListener;
        document.addEventListener = function(type, listener, options) {
            if (type === 'visibilitychange' || type === 'webkitvisibilitychange' || 
                type === 'mozvisibilitychange' || type === 'msvisibilitychange') {
                return;
            }
            return originalAddEventListener.call(this, type, listener, options);
        };
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ removeEventListener
        var originalRemoveEventListener = document.removeEventListener;
        document.removeEventListener = function(type, listener, options) {
            if (type === 'visibilitychange' || type === 'webkitvisibilitychange' || 
                type === 'mozvisibilitychange' || type === 'msvisibilitychange') {
                return;
            }
            return originalRemoveEventListener.call(this, type, listener, options);
        };
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ dispatchEvent
        var originalDispatchEvent = document.dispatchEvent;
        document.dispatchEvent = function(event) {
            if (event.type === 'visibilitychange' || event.type === 'webkitvisibilitychange' || 
                event.type === 'mozvisibilitychange' || event.type === 'msvisibilitychange') {
                return false;
            }
            return originalDispatchEvent.call(this, event);
        };
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEvent
        var originalCreateEvent = document.createEvent;
        document.createEvent = function(type) {
            if (type === 'VisibilityChangeEvent' || type === 'webkitVisibilityChangeEvent' || 
                type === 'mozVisibilityChangeEvent' || type === 'msVisibilityChangeEvent') {
                return null;
            }
            return originalCreateEvent.call(this, type);
        };
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventObject
        var originalCreateEventObject = document.createEventObject;
        if (originalCreateEventObject) {
            document.createEventObject = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        */
        
        // تم تعطيل باقي كود منع أدوات المطور
        /*
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
        // منع فتح أدوات المطور عبر Object.defineProperty للـ createEventNS
        var originalCreateEventNS = document.createEventNS;
        if (originalCreateEventNS) {
            document.createEventNS = function() {
                return null;
            };
        }
        
    })();
    </script>
    <!-- معالجة Refresh لمنع Error Code: -2 -->
    <script>
    (function() {
        'use strict';
        
        // منع Error Code: -2 عند Refresh - استخدام pagehide لإعادة تفعيل bfcache
        window.addEventListener('pagehide', function(event) {
            // حفظ flag أن المستخدم يقوم بـ Refresh (إذا لم يكن من bfcache)
            if (!event.persisted) {
                try {
                    sessionStorage.setItem('is_refreshing', 'true');
                    sessionStorage.setItem('refresh_timestamp', Date.now().toString());
                    sessionStorage.setItem('refresh_url', window.location.href);
                } catch (e) {
                    // تجاهل إذا كان sessionStorage غير متاح
                }
            }
        });
        
        // معالجة Refresh عند تحميل الصفحة
        window.addEventListener('pageshow', function(event) {
            try {
                const isRefreshing = sessionStorage.getItem('is_refreshing') === 'true';
                const refreshUrl = sessionStorage.getItem('refresh_url');
                const currentUrl = window.location.href;
                
                // إذا كان هذا refresh وكان URL مختلف، قد يكون هناك redirect
                if (isRefreshing && refreshUrl && refreshUrl !== currentUrl) {
                    // إزالة flags
                    sessionStorage.removeItem('is_refreshing');
                    sessionStorage.removeItem('refresh_timestamp');
                    sessionStorage.removeItem('refresh_url');
                    
                    // إذا كانت الصفحة من cache، أعد تحميلها من السيرفر
                    if (event.persisted) {
                        const url = new URL(window.location.href);
                        if (!url.searchParams.has('_refresh')) {
                            url.searchParams.set('_refresh', Date.now().toString());
                            setTimeout(function() {
                                window.location.href = url.toString();
                            }, 100);
                        }
                    }
                } else if (isRefreshing) {
                    // إزالة flags بعد 2 ثانية
                    setTimeout(function() {
                        sessionStorage.removeItem('is_refreshing');
                        sessionStorage.removeItem('refresh_timestamp');
                        sessionStorage.removeItem('refresh_url');
                    }, 2000);
                }
            } catch (e) {
                // تجاهل الأخطاء
                console.warn('Error in refresh handler:', e);
            }
        });
        
        // معالجة أخطاء الاتصال عند Refresh
        window.addEventListener('error', function(event) {
            if (event.message && typeof event.message === 'string') {
                const message = event.message.toLowerCase();
                if (message.includes('error code: -2') || message.includes('err_failed') || 
                    message.includes('connection failed') || message.includes('connection refused')) {
                    // إذا كان هناك refresh نشط، حاول إعادة المحاولة
                    const isRefreshing = sessionStorage.getItem('is_refreshing') === 'true';
                    if (isRefreshing) {
                        console.warn('Connection error during refresh, retrying...');
                        setTimeout(function() {
                            const url = new URL(window.location.href);
                            url.searchParams.set('_retry', Date.now().toString());
                            window.location.href = url.toString();
                        }, 1000);
                    }
                }
            }
        }, true);
    })();
    </script>
    <!-- معالجة زر تحديث الصفحة -->
    <script>
    (function() {
        'use strict';
        
        // تهيئة زر التحديث في القائمة المنسدلة
        function initRefreshButtonDropdown() {
            const refreshBtnDropdown = document.getElementById('refreshPageBtnDropdown');
            if (!refreshBtnDropdown) return;
            
            refreshBtnDropdown.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                try {
                    // إنشاء URL جديد مع إزالة معاملات cache القديمة
                    const url = new URL(window.location.href);
                    
                    // إزالة معاملات cache القديمة
                    url.searchParams.delete('_nocache');
                    url.searchParams.delete('_refresh');
                    url.searchParams.delete('_cache_bust');
                    url.searchParams.delete('_t');
                    url.searchParams.delete('_r');
                    url.searchParams.delete('_auto_refresh');
                    url.searchParams.delete('_retry');
                    
                    // إضافة timestamp جديد لفرض إعادة تحميل من السيرفر
                    url.searchParams.set('_nocache', Date.now().toString());
                    
                    // بناء URL النهائي مع hash إن وجد
                    const newUrl = url.pathname + url.search + (window.location.hash || '');
                    
                    // محاولة مسح cache إن أمكن
                    if ('caches' in window) {
                        caches.keys().then(function(names) {
                            names.forEach(function(name) {
                                caches.delete(name).catch(function(err) {
                                    console.warn('Failed to delete cache:', name, err);
                                });
                            });
                        }).catch(function(err) {
                            console.warn('Error accessing caches:', err);
                        });
                    }
                    
                    // إعادة تحميل الصفحة
                    window.location.replace(newUrl);
                } catch (error) {
                    console.error('Error refreshing page:', error);
                    // في حالة الخطأ، استخدم طريقة بسيطة
                    window.location.reload(true);
                }
                
                return false;
            });
        }
        
        // تهيئة زر التحديث في القائمة المنسدلة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initRefreshButtonDropdown);
        } else {
            initRefreshButtonDropdown();
        }
        
    })();
    </script>
    
    <!-- معالج الأخطاء JavaScript - منع ظهور رسائل خطأ المتصفح (ERR_FAILED) -->
    <script>
    (function() {
        'use strict';
        
        // دالة للحصول على URL صفحة تسجيل الدخول
        function getLoginUrl() {
            const currentPath = window.location.pathname || '/';
            const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && p !== 'modules' && !p.endsWith('.php'));
            const basePath = pathParts.length > 0 ? '/' + pathParts[0] : '';
            return (basePath ? basePath : '') + '/index.php';
        }
        
        // دالة لإعادة التوجيه إلى صفحة تسجيل الدخول مع رسالة تنبيه
        function redirectToLogin(reason) {
            // لا نعيد التوجيه تلقائياً - فقط عند تسجيل الخروج يدوياً
            console.warn('Redirect to login attempted but blocked:', reason || 'انتهت الجلسة أو حدث خطأ في الاتصال');
        }
        
        // معالج أخطاء JavaScript العامة
        let globalErrorCount = 0;
        let lastGlobalErrorTime = 0;
        const MAX_GLOBAL_ERRORS = 5; // عدد الأخطاء المسموح قبل إعادة التوجيه
        const GLOBAL_ERROR_WINDOW = 10000; // نافذة زمنية بالمللي ثانية (10 ثوان)
        
        window.addEventListener('error', function(event) {
            // تجاهل الأخطاء من مصادر خارجية (CDN، إلخ)
            if (event.filename && (
                event.filename.includes('cdn.jsdelivr.net') ||
                event.filename.includes('code.jquery.com') ||
                event.filename.includes('googleapis.com')
            )) {
                return;
            }
            
            // معالجة أخطاء الاتصال (ERR_FAILED، NetworkError، إلخ)
            const errorMessage = (event.message || '').toLowerCase();
            if (errorMessage.includes('failed to fetch') ||
                errorMessage.includes('networkerror') ||
                errorMessage.includes('err_failed') ||
                errorMessage.includes('connection') ||
                errorMessage.includes('network')) {
                
                // التحقق من أننا لسنا في صفحة تسجيل الدخول
                const currentPath = window.location.pathname || '';
                const isOnLoginPage = currentPath.includes('index.php');
                
                // التحقق من أن المستخدم مسجل دخول (من خلال الجلسة)
                const hasSession = true; // الجلسات تُدار من جانب الخادم
                
                if (!isOnLoginPage) {
                    const now = Date.now();
                    
                    // إعادة تعيين العداد إذا مرت فترة زمنية كافية
                    if (now - lastGlobalErrorTime > GLOBAL_ERROR_WINDOW) {
                        globalErrorCount = 0;
                    }
                    
                    globalErrorCount++;
                    lastGlobalErrorTime = now;
                    
                    // فقط إذا تجاوز عدد الأخطاء الحد المسموح
                    if (globalErrorCount >= MAX_GLOBAL_ERRORS) {
                        // لا نعيد التوجيه تلقائياً - فقط نعيد تعيين العداد
                        console.warn('Multiple global errors detected, resetting counter');
                        globalErrorCount = 0;
                    }
                }
            }
        }, true);
        
        // معالج رفض Promise (unhandledrejection) - للأخطاء غير المعالجة في Promises
        let promiseErrorCount = 0;
        let lastPromiseErrorTime = 0;
        const MAX_PROMISE_ERRORS = 5; // عدد الأخطاء المسموح قبل إعادة التوجيه
        const PROMISE_ERROR_WINDOW = 10000; // نافذة زمنية بالمللي ثانية (10 ثوان)
        
        window.addEventListener('unhandledrejection', function(event) {
            const reason = event.reason;
            
            // التحقق من أن الخطأ متعلق بالاتصال
            if (reason && typeof reason === 'object') {
                const errorMessage = (reason.message || reason.toString() || '').toLowerCase();
                if (errorMessage.includes('failed to fetch') ||
                    errorMessage.includes('networkerror') ||
                    errorMessage.includes('err_failed') ||
                    errorMessage.includes('connection') ||
                    errorMessage.includes('network')) {
                    
                    // التحقق من أننا لسنا في صفحة تسجيل الدخول
                    const currentPath = window.location.pathname || '';
                    const isOnLoginPage = currentPath.includes('index.php');
                    
                    // التحقق من وجود remember_token cookie (المستخدم مسجل دخول)
                    const hasRememberToken = document.cookie.includes('remember_token=');
                    
                    if (!isOnLoginPage) {
                        const now = Date.now();
                        
                        // إعادة تعيين العداد إذا مرت فترة زمنية كافية
                        if (now - lastPromiseErrorTime > PROMISE_ERROR_WINDOW) {
                            promiseErrorCount = 0;
                        }
                        
                        promiseErrorCount++;
                        lastPromiseErrorTime = now;
                        
                        // فقط إذا تجاوز عدد الأخطاء الحد المسموح
                        if (promiseErrorCount >= MAX_PROMISE_ERRORS) {
                            // لا نعيد التوجيه تلقائياً - فقط نعيد تعيين العداد
                            console.warn('Multiple promise errors detected, resetting counter');
                            promiseErrorCount = 0;
                        }
                    }
                }
            }
        });
        
        // اعتراض طلبات fetch للتحقق من الأخطاء
        const originalFetch = window.fetch;
        let fetchErrorCount = 0;
        let lastFetchErrorTime = 0;
        const MAX_FETCH_ERRORS = 3; // عدد الأخطاء المسموح قبل إعادة التوجيه
        const FETCH_ERROR_WINDOW = 5000; // نافذة زمنية بالمللي ثانية (5 ثوان)
        
        window.fetch = function(...args) {
            return originalFetch.apply(this, args).catch(function(error) {
                const errorMessage = (error.message || error.toString() || '').toLowerCase();
                
                // التحقق من أخطاء الاتصال
                if (errorMessage.includes('failed to fetch') ||
                    errorMessage.includes('networkerror') ||
                    errorMessage.includes('err_failed') ||
                    errorMessage.includes('connection') ||
                    errorMessage.includes('network') ||
                    error.name === 'TypeError' ||
                    error.name === 'NetworkError') {
                    
                    const url = args[0];
                    const urlString = url && typeof url === 'string' ? url : (url && url.url ? url.url : '');
                    
                    // قائمة بيضاء للـ URLs التي لا يجب إعادة التوجيه عند فشلها
                    const whitelistedUrls = [
                        'index.php',
                        'login',
                        'notifications',
                        'check_session',
                        'session_keepalive',
                        'api/notifications',
                        'api/check_session',
                        'api/session_keepalive'
                    ];
                    
                    // التحقق من أن URL ليس في القائمة البيضاء
                    const isWhitelisted = whitelistedUrls.some(whitelisted => urlString.includes(whitelisted));
                    
                    // التحقق من أن الطلب ليس لصفحة تسجيل الدخول نفسها
                    const isLoginPage = urlString.includes('index.php');
                    
                    // التحقق من أننا لسنا في صفحة تسجيل الدخول
                    const currentPath = window.location.pathname || '';
                    const isOnLoginPage = currentPath.includes('index.php');
                    
                    // التحقق من وجود remember_token cookie (المستخدم مسجل دخول)
                    const hasRememberToken = document.cookie.includes('remember_token=');
                    
                    // فقط إذا لم يكن في القائمة البيضاء وليس في صفحة تسجيل الدخول
                    if (!isWhitelisted && !isLoginPage && !isOnLoginPage) {
                        const now = Date.now();
                        
                        // إعادة تعيين العداد إذا مرت فترة زمنية كافية
                        if (now - lastFetchErrorTime > FETCH_ERROR_WINDOW) {
                            fetchErrorCount = 0;
                        }
                        
                        fetchErrorCount++;
                        lastFetchErrorTime = now;
                        
                        // فقط إذا تجاوز عدد الأخطاء الحد المسموح
                        if (fetchErrorCount >= MAX_FETCH_ERRORS) {
                            // لا نعيد التوجيه تلقائياً - فقط نعيد تعيين العداد
                            console.warn('Multiple fetch errors detected, resetting counter');
                            fetchErrorCount = 0;
                        }
                    }
                }
                
                // إعادة رمي الخطأ للتعامل معه بشكل طبيعي
                throw error;
            });
        };
    })();
    </script>
</head>
<body class="dashboard-body<?php echo isset($pageBodyClass) ? ' ' . htmlspecialchars($pageBodyClass) : ''; ?>"
      data-user-role="<?php echo htmlspecialchars(isset($currentUser['role']) ? $currentUser['role'] : ''); ?>"
      data-user-id="<?php echo isset($currentUser['id']) ? (int) $currentUser['id'] : 0; ?>">
    <!-- Accessibility: Skip to main content -->
    <a href="#main-content" class="skip-link visually-hidden-focusable">
        <?php echo isset($lang['skip_to_main']) ? $lang['skip_to_main'] : 'تخطي إلى المحتوى الرئيسي'; ?>
    </a>

    <div class="dashboard-wrapper">
        <!-- Homeline Style Sidebar -->
        <?php if (isLoggedIn()): ?>
        <?php include __DIR__ . '/homeline_sidebar.php'; ?>
        <?php endif; ?>
        
        <!-- Developer Quick Access Bar -->
        <?php if (isLoggedIn() && isDeveloper()): ?>
        <div class="developer-quick-access-bar" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 10px 0; border-bottom: 2px solid #5568d3; position: sticky; top: 0; z-index: 1030; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-12">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-code-slash"></i>
                                <strong>وصول سريع للمطور:</strong>
                            </div>
                            <div class="d-flex align-items-center flex-wrap gap-2" style="flex: 1; justify-content: flex-end;">
                                <a href="<?php echo getRelativeUrl('dashboard/developer.php'); ?>" class="btn btn-sm btn-light shadow-sm" style="white-space: nowrap; font-weight: 500;">
                                    <i class="bi bi-code-slash me-1"></i> لوحة المطور
                                </a>
                                <a href="<?php echo getRelativeUrl('dashboard/manager.php'); ?>" class="btn btn-sm btn-light shadow-sm" style="white-space: nowrap; font-weight: 500;">
                                    <i class="bi bi-speedometer2 me-1"></i> لوحة المدير
                                </a>
                                <a href="<?php echo getRelativeUrl('dashboard/developer.php?page=system_settings'); ?>" class="btn btn-sm btn-light shadow-sm" style="white-space: nowrap; font-weight: 500;">
                                    <i class="bi bi-gear me-1"></i> إعدادات النظام
                                </a>
                                <a href="<?php echo getRelativeUrl('dashboard/sales.php'); ?>" class="btn btn-sm btn-light shadow-sm" style="white-space: nowrap; font-weight: 500;">
                                    <i class="bi bi-cart me-1"></i> لوحة المبيعات
                                </a>
                                <a href="<?php echo getRelativeUrl('dashboard/accountant.php'); ?>" class="btn btn-sm btn-light shadow-sm" style="white-space: nowrap; font-weight: 500;">
                                    <i class="bi bi-calculator me-1"></i> لوحة المحاسبة
                                </a>
                                <a href="<?php echo getRelativeUrl('dashboard/production.php'); ?>" class="btn btn-sm btn-light shadow-sm" style="white-space: nowrap; font-weight: 500;">
                                    <i class="bi bi-gear-wide me-1"></i> لوحة الإنتاج
                                </a>
                                <div class="dropdown" style="display: inline-block;">
                                    <button class="btn btn-sm btn-light dropdown-toggle" type="button" id="developerQuickAccessDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="white-space: nowrap;">
                                        <i class="bi bi-three-dots-vertical"></i> المزيد
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="developerQuickAccessDropdown">
                                        <li><h6 class="dropdown-header">صفحات المدير</h6></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/manager.php?page=users'); ?>"><i class="bi bi-people me-2"></i>المستخدمين</a></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/manager.php?page=security'); ?>"><i class="bi bi-shield-lock me-2"></i>الأمان</a></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/manager.php?page=company_products'); ?>"><i class="bi bi-box-seam me-2"></i>منتجات الشركة</a></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/manager.php?page=approvals'); ?>"><i class="bi bi-check-circle me-2"></i>الموافقات</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><h6 class="dropdown-header">صفحات المبيعات</h6></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/sales.php?page=customers'); ?>"><i class="bi bi-people me-2"></i>العملاء</a></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/sales.php?page=orders'); ?>"><i class="bi bi-cart-check me-2"></i>الطلبات</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><h6 class="dropdown-header">صفحات المحاسبة</h6></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/accountant.php?page=financial'); ?>"><i class="bi bi-safe me-2"></i>الخزنة</a></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/accountant.php?page=invoices'); ?>"><i class="bi bi-receipt me-2"></i>الفواتير</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><h6 class="dropdown-header">صفحات الإنتاج</h6></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/production.php?page=production'); ?>"><i class="bi bi-gear-wide me-2"></i>الإنتاج</a></li>
                                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('dashboard/production.php?page=tasks'); ?>"><i class="bi bi-list-task me-2"></i>المهام</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Top Bar -->
        <div class="homeline-topbar">
            <div class="topbar-left">
                <!-- Mobile Menu Toggle -->
                 <button class="mobile-menu-toggle d-md-none" 
                         id="mobileMenuToggle" 
                         type="button"
                         aria-label="<?php echo isset($lang['menu']) ? $lang['menu'] : 'القائمة'; ?>"
                         aria-expanded="false"
                         aria-controls="sidebar">
                     <i class="bi bi-list" aria-hidden="true"></i>
                     <span class="visually-hidden"><?php echo isset($lang['menu']) ? $lang['menu'] : 'القائمة'; ?></span>
                 </button>
                 <button class="mobile-reload-btn d-md-none" 
                         id="mobileReloadBtn" 
                         type="button"
                         aria-label="<?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?>">
                     <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                     <span class="visually-hidden"><?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?></span>
                 </button>
                 <button class="mobile-dark-toggle d-md-none" 
                         id="mobileDarkToggle" 
                         type="button"
                         aria-label="<?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?>"
                         aria-pressed="false">
                     <i class="bi bi-moon-stars" aria-hidden="true"></i>
                     <span class="visually-hidden"><?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?></span>
                 </button>
                <div class="breadcrumb-nav">
                    <?php 
                    $pageTitleText = isset($pageTitle) ? $pageTitle : (isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم');
                    ?>
                    <a href="<?php echo getDashboardUrl(isset($currentUser['role']) ? $currentUser['role'] : 'accountant'); ?>"><?php echo APP_NAME; ?></a>
                    <span class="mx-2">/</span>
                    <span><?php echo $pageTitleText; ?></span>
                </div>
            </div>
            
            <div class="topbar-center">
                <div class="topbar-search">
                    <label for="globalSearch" class="visually-hidden">
                        <?php echo isset($lang['search']) ? $lang['search'] : 'بحث'; ?>
                    </label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="text" 
                           placeholder="<?php echo isset($lang['search']) ? $lang['search'] : 'بحث'; ?>" 
                           id="globalSearch"
                           aria-label="<?php echo isset($lang['search']) ? $lang['search'] : 'بحث'; ?>"
                           autocomplete="off"
                           aria-describedby="search-help">
                    <span class="search-shortcut" aria-hidden="true">⌘K</span>
                    <span id="search-help" class="visually-hidden">
                        <?php echo isset($lang['search_help']) ? $lang['search_help'] : 'استخدم للبحث في النظام'; ?>
                    </span>
                </div>
            </div>
            
            <div class="topbar-right">
                <!-- Notifications -->
                <?php if (isLoggedIn()): ?>
                <div class="topbar-dropdown">
                    <a href="#" 
                       class="topbar-action" 
                       id="notificationsDropdown" 
                       role="button" 
                       aria-label="<?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?>"
                       aria-expanded="false"
                       aria-haspopup="true"
                       data-bs-toggle="dropdown" 
                       data-bs-toggle="tooltip" 
                       title="<?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?>">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <span class="badge" id="notificationBadge" aria-live="polite" aria-atomic="true">0</span>
                        <span class="visually-hidden"><?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end notifications-dropdown" aria-labelledby="notificationsDropdown" data-bs-auto-close="outside">
                        <li><h6 class="dropdown-header">
                            <form method="POST" 
                                  action="<?php echo getRelativeUrl('api/notifications.php'); ?>" 
                                  id="clearAllNotificationsForm"
                                  style="display: inline-block; float: left; margin-right: 0.5rem;"
                                  onsubmit="if(typeof handleClearAllNotifications === 'function') { event.preventDefault(); event.stopPropagation(); return handleClearAllNotifications(event); } return false;">
                                <input type="hidden" name="action" value="delete_all">
                                <button type="submit" 
                                        class="btn btn-sm btn-link text-danger p-0" 
                                        id="clearAllNotificationsBtn" 
                                        title="مسح كل الإشعارات" 
                                        aria-label="<?php echo isset($lang['clear_all_notifications']) ? $lang['clear_all_notifications'] : 'مسح كل الإشعارات'; ?>"
                                        style="font-size: 11px; text-decoration: none; pointer-events: auto; z-index: 1000; position: relative; cursor: pointer; border: none; background: transparent; padding: 0;">
                                    <i class="bi bi-trash" aria-hidden="true"></i> 
                                    <span>مسح الكل</span>
                                </button>
                            </form>
                            <?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?>
                        </h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><div class="dropdown-item-text text-center" id="notificationsList">
                            <small class="text-muted"><?php echo isset($lang['loading']) ? $lang['loading'] : 'جاري التحميل...'; ?></small>
                        </div></li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Quick Actions Dropdown Menu -->
                <div class="topbar-dropdown quick-actions-dropdown">
                    <a href="#" 
                       class="topbar-action quick-actions-toggle" 
                       id="quickActionsDropdown" 
                       role="button" 
                       aria-label="القائمة السريعة"
                       aria-expanded="false"
                       aria-haspopup="true"
                       data-bs-toggle="dropdown" 
                       data-bs-toggle="tooltip" 
                       title="القائمة السريعة">
                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                        <span class="visually-hidden">القائمة السريعة</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end quick-actions-menu" aria-labelledby="quickActionsDropdown">
                        <!-- Version Badge -->
                        <li class="dropdown-item-text">
                            <div class="version-badge-container-inline">
                                <span class="version-badge-inline" title="إصدار النظام">
                                    <?php 
                                    // قراءة الإصدار مباشرة من version.json (عداد يدوي)
                                    $versionFile = __DIR__ . '/../version.json';
                                    $currentVersion = 'v1.0.0'; // افتراضي
                                    
                                    if (file_exists($versionFile)) {
                                        try {
                                            $versionData = json_decode(file_get_contents($versionFile), true);
                                            if (isset($versionData['version']) && !empty($versionData['version'])) {
                                                $version = trim($versionData['version']);
                                                // إضافة v في البداية إذا لم تكن موجودة
                                                if (strpos($version, 'v') !== 0) {
                                                    $version = 'v' . $version;
                                                }
                                                $currentVersion = $version;
                                            }
                                        } catch (Exception $e) {
                                            // في حالة الخطأ، استخدام الإصدار الافتراضي
                                        }
                                    }
                                    
                                    echo htmlspecialchars($currentVersion);
                                    ?>
                                </span>
                                <span style="margin-right: 8px; color: var(--gray-600); font-size: 0.875rem;">إصدار النظام</span>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        
                        <!-- Fingerprint Registration Button -->
                        <?php if (isLoggedIn()): ?>
                        <li>
                            <a class="dropdown-item" href="<?php echo getRelativeUrl('register_fingerprint.php'); ?>">
                                <i class="bi bi-fingerprint me-2" aria-hidden="true"></i>
                                <span>تسجيل البصمة والملف الشخصي</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Refresh Page Button -->
                        <li>
                            <a class="dropdown-item" href="#" id="refreshPageBtnDropdown">
                                <i class="bi bi-arrow-clockwise me-2" aria-hidden="true"></i>
                                <span><?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?></span>
                            </a>
                        </li>
                        
                        <!-- Dark Mode Toggle -->
                        <li>
                            <div class="dropdown-item">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>
                                        <i class="bi bi-moon-stars me-2" aria-hidden="true"></i>
                                        <span><?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?></span>
                                    </span>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="darkModeToggleDropdown" 
                                               aria-label="<?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?>"
                                               style="cursor: pointer;">
                                    </div>
                                </div>
                            </div>
                        </li>
                        
                        <!-- Logout Button -->
                        <?php if (isLoggedIn()): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?php echo getRelativeUrl('logout.php'); ?>">
                                <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>
                                <span><?php echo isset($lang['logout']) ? $lang['logout'] : 'تسجيل الخروج'; ?></span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        
        
        <!-- Main Content Area -->
        <main class="dashboard-main" id="main-content" role="main" aria-label="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'المحتوى الرئيسي'; ?>">


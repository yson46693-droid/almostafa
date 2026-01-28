<?php
/**
 * Helper functions for path management
 */

// السماح بالتحميل من ملفات أخرى (مثل auth.php) حتى لو لم يكن ACCESS_ALLOWED معرف
// هذا ضروري لأن auth.php قد يحتاج تحميل path_helper في حالات الطوارئ
// عندما تكون الـ headers قد أُرسلت بالفعل

/**
 * Get base URL path
 */
function getBasePath() {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Normalize paths
    $scriptName = str_replace('\\', '/', $scriptName);
    $requestUri = str_replace('\\', '/', $requestUri);
    
    // استخراج base path من SCRIPT_NAME
    // المسار سيكون ديناميكياً بناءً على موقع الملف
    $parts = explode('/', trim($scriptName, '/'));
    
    // إزالة 'dashboard', 'modules', وملفات PHP من المسار
    $baseParts = [];
    foreach ($parts as $part) {
        // توقف عند الوصول إلى مجلدات خاصة أو ملفات PHP
        if ($part === 'dashboard' || $part === 'modules' || $part === 'api' || strpos($part, '.php') !== false) {
            break;
        }
        $baseParts[] = $part;
    }
    
    // إذا كان هناك base path، ارجعه
    if (!empty($baseParts)) {
        return '/' . implode('/', $baseParts);
    }
    
    // محاولة اكتشاف المسار من REQUEST_URI
    $parsedUri = parse_url($requestUri);
    $path = $parsedUri['path'] ?? '';
    $path = str_replace('\\', '/', $path);
    
    // استخراج base path من REQUEST_URI
    $pathParts = explode('/', trim($path, '/'));
    $baseParts = [];
    foreach ($pathParts as $part) {
        // توقف عند الوصول إلى مجلدات خاصة أو ملفات PHP
        if ($part === 'dashboard' || $part === 'modules' || $part === 'api' || strpos($part, '.php') !== false) {
            break;
        }
        $baseParts[] = $part;
    }
    
    // إذا كان هناك base path، ارجعه
    if (!empty($baseParts)) {
        return '/' . implode('/', $baseParts);
    }
    
    // إذا كان المسار في الجذر، ارجع string فارغ
    if ($path === '/' || $path === '') {
        return '';
    }
    
    // Fallback: إذا كان dirname فقط
    $scriptDir = dirname($scriptName);
    
    // إزالة /dashboard و /modules من المسار
    while (strpos($scriptDir, '/dashboard') !== false || strpos($scriptDir, '/modules') !== false) {
        $scriptDir = dirname($scriptDir);
    }
    
    // Normalize path separators
    $scriptDir = str_replace('\\', '/', $scriptDir);
    
    // If in root, return empty string
    if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
        return '';
    }
    
    // التأكد من أن المسار يبدأ بـ /
    if (strpos($scriptDir, '/') !== 0) {
        $scriptDir = '/' . $scriptDir;
    }
    
    return $scriptDir;
}

/**
 * Get dashboard URL
 * دالة محسّنة لضمان عدم تكرار خطأ DNS_PROBE_FINISHED_NXDOMAIN و ERR_FAILED
 * تضمن إرجاع مسار نسبي فقط بدون أي بروتوكول أو hostname أو منفذ
 */
function getDashboardUrl($role = null) {
    
    $base = getBasePath();
    
    // التأكد من أن base يبدأ بـ / أو يكون فارغاً
    if (!empty($base) && strpos($base, '/') !== 0) {
        $base = '/' . $base;
    }
    
    // إزالة / من النهاية إذا كان موجوداً
    $base = rtrim($base, '/');
    
    // إزالة 'dashboard' من base إذا كان موجوداً لمنع تكرار dashboard/dashboard
    if (!empty($base)) {
        $baseParts = explode('/', trim($base, '/'));
        $baseParts = array_filter($baseParts, function($part) {
            return $part !== 'dashboard' && !empty($part);
        });
        $base = !empty($baseParts) ? '/' . implode('/', $baseParts) : '';
    }
    
    // بناء المسار - دائماً يبدأ بـ /
    if ($role) {
        $url = ($base ? $base : '') . '/dashboard/' . $role . '.php';
    } else {
        $url = ($base ? $base : '') . '/dashboard/';
    }
    
    // تنظيف شامل للمسار - إزالة أي بروتوكول أو hostname أو منفذ
    // 1. إزالة أي بروتوكول كامل مع hostname ومنفذ (http://hostname:port أو https://hostname:port)
    $url = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $url);
    $url = preg_replace('/^\/\//', '/', $url);
    
    // 2. إزالة أي hostname مع منفذ إذا كان موجوداً في بداية المسار
    // مثال: localhost:8000/dashboard/production.php -> /dashboard/production.php
    if (preg_match('/^\/[^\/]+:[0-9]+\//', $url)) {
        $url = preg_replace('/^\/[^\/]+:[0-9]+/', '', $url);
    }
    
    // 3. التأكد من أن المسار يبدأ بـ /
    if (strpos($url, '/') !== 0) {
        $url = '/' . $url;
    }
    
    // 4. تنظيف المسار (إزالة // المكررة)
    $url = preg_replace('/\/+/', '/', $url);
    
    // 5. إزالة أي hostname إذا كان موجوداً (مثل albarakah.free.nf أو localhost:8000)
    // إذا كان المسار يحتوي على نقطة أو نقطتين بعد / مباشرة، قد يكون hostname
    if (preg_match('/^\/[^\/]+(\.[a-z]|:[0-9])/i', $url)) {
        // إذا كان يبدو كـ hostname، استخراج المسار فقط
        $parts = explode('/', $url);
        // البحث عن 'dashboard' في المسار
        $dashboardIndex = array_search('dashboard', $parts);
        if ($dashboardIndex !== false && $dashboardIndex > 0) {
            // استخراج المسار من dashboard فصاعداً
            $url = '/' . implode('/', array_slice($parts, $dashboardIndex));
        } else {
            // إذا لم يكن هناك dashboard، استخدم المسار الافتراضي
            $url = ($base ? $base : '') . '/dashboard/' . ($role ? $role . '.php' : '');
        }
    }
    
    // 6. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
    if (strpos($url, '/dashboard') === false) {
        $url = ($base ? $base : '') . '/dashboard/' . ($role ? $role . '.php' : '');
    }
    
    // 7. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        $parsed = parse_url($url);
        $url = $parsed['path'] ?? (($base ? $base : '') . '/dashboard/' . ($role ? $role . '.php' : ''));
    }
    
    // 8. التحقق النهائي: التأكد من أن المسار يبدأ بـ / ولا يحتوي على بروتوكول
    if (strpos($url, '/') !== 0) {
        $url = '/' . $url;
    }
    
    // 9. تنظيف نهائي: إزالة أي مسافات
    $url = trim($url);
    
    // 10. التأكد من أن المسار لا يحتوي على :// (بروتوكول)
    if (strpos($url, '://') !== false) {
        $parsed = parse_url($url);
        $url = $parsed['path'] ?? (($base ? $base : '') . '/dashboard/' . ($role ? $role . '.php' : ''));
    }
    
    // 11. إزالة أي منفذ من المسار (مثل :8000 في منتصف المسار - يجب ألا يحدث لكن للاحتياط)
    $url = preg_replace('/:[0-9]+\//', '/', $url);
    $url = preg_replace('/:[0-9]+$/', '', $url);
    
    // 12. تنظيف نهائي للمسار
    $url = preg_replace('/\/+/', '/', $url);
    
    // 12.5. إزالة أي تكرار لـ 'dashboard' في المسار (مثل /dashboard/dashboard/manager.php)
    $urlParts = explode('/', trim($url, '/'));
    $cleanedParts = [];
    $dashboardFound = false;
    foreach ($urlParts as $part) {
        if ($part === 'dashboard') {
            if (!$dashboardFound) {
                $cleanedParts[] = $part;
                $dashboardFound = true;
            }
            // تجاهل أي 'dashboard' إضافي
        } else {
            $cleanedParts[] = $part;
        }
    }
    $url = '/' . implode('/', $cleanedParts);
    
    // 12.6. فحص نهائي: التأكد من أن المسار يحتوي على '/dashboard/' وأن role موجود
    if ($role) {
        // إذا كان role موجوداً، يجب أن يكون المسار /dashboard/role.php
        if (strpos($url, '/dashboard/') === false) {
            // إذا لم يكن هناك dashboard في المسار، أعد بناءه من الصفر
            $url = '/dashboard/' . $role . '.php';
        } else {
            // التحقق من أن role موجود في نهاية المسار
            $expectedEnd = '/dashboard/' . $role . '.php';
            if (substr($url, -strlen($expectedEnd)) !== $expectedEnd && substr($url, -strlen('/' . $role . '.php')) !== '/' . $role . '.php') {
                // إذا كان role غير موجود في نهاية المسار، أعد بناءه
                $url = '/dashboard/' . $role . '.php';
            }
        }
    } else {
        // إذا لم يكن هناك role، يجب أن يكون المسار /dashboard/
        if (strpos($url, '/dashboard') === false) {
            $url = '/dashboard/';
        }
    }
    
    // 13. التأكد من أن المسار صحيح نهائياً ولا يحتوي على أي بروتوكول أو hostname
    if (empty($url) || $url === '/') {
        $url = '/dashboard/' . ($role ? $role . '.php' : '');
    }
    
    // 14. التحقق النهائي: التأكد من أن المسار نسبي فقط (يبدأ بـ / ولا يحتوي على :// أو :port)
    if (strpos($url, '://') !== false || preg_match('/:[0-9]+/', $url)) {
        // إذا كان لا يزال يحتوي على بروتوكول أو منفذ، استخراج المسار فقط
        $parsed = parse_url($url);
        if ($parsed && isset($parsed['path'])) {
            $url = $parsed['path'];
            // إزالة تكرار dashboard مرة أخرى بعد parse_url
            $urlParts = explode('/', trim($url, '/'));
            $cleanedParts = [];
            $dashboardFound = false;
            foreach ($urlParts as $part) {
                if ($part === 'dashboard') {
                    if (!$dashboardFound) {
                        $cleanedParts[] = $part;
                        $dashboardFound = true;
                    }
                } else {
                    $cleanedParts[] = $part;
                }
            }
            $url = '/' . implode('/', $cleanedParts);
        } else {
            // كحل أخير، استخدم المسار الافتراضي
            $url = '/dashboard/' . ($role ? $role . '.php' : '');
        }
        // التأكد من أن المسار يبدأ بـ /
        if (strpos($url, '/') !== 0) {
            $url = '/' . $url;
        }
    }
    
    // 15. فحص نهائي نهائي: التأكد من أن المسار صحيح 100%
    // إزالة أي منفذ نهائياً
    $url = preg_replace('/:[0-9]+/', '', $url);
    $url = preg_replace('/\/+/', '/', $url);
    
    // التأكد من أن المسار يحتوي على /dashboard/ إذا كان role موجوداً
    if ($role) {
        $expectedPath = '/dashboard/' . $role . '.php';
        // إذا كان المسار لا يحتوي على dashboard أو role، أعد بناءه بالكامل
        if (strpos($url, '/dashboard/') === false) {
            error_log("getDashboardUrl WARNING: Missing /dashboard/ in URL: {$url}, rebuilding to: {$expectedPath}");
            $url = $expectedPath;
        } elseif (substr($url, -strlen($role . '.php')) !== $role . '.php') {
            error_log("getDashboardUrl WARNING: Role mismatch in URL: {$url}, expected: {$expectedPath}");
            $url = $expectedPath;
        }
        
        // فحص إضافي: إذا كان المسار يبدأ مباشرة بـ role.php بدون dashboard
        if (strpos($url, '/' . $role . '.php') === 0 || $url === '/' . $role . '.php') {
            error_log("getDashboardUrl ERROR: URL missing /dashboard/ prefix: {$url}, fixing to: {$expectedPath}");
            $url = $expectedPath;
        }
    } else {
        // إذا لم يكن هناك role، يجب أن ينتهي بـ /dashboard/
        if (strpos($url, '/dashboard') === false) {
            $url = '/dashboard/';
        }
    }
    
    // تنظيف نهائي نهائي
    $url = trim($url);
    if (empty($url) || $url === '/') {
        $url = '/dashboard/' . ($role ? $role . '.php' : '');
    }
    
    // التأكد من أن المسار يبدأ بـ / ولا يحتوي على أي بروتوكول أو منفذ
    if (strpos($url, '/') !== 0) {
        $url = '/' . $url;
    }
    
    // إزالة أي بروتوكول نهائياً (للاحتياط)
    $url = preg_replace('/^https?:\/\//', '', $url);
    $url = preg_replace('/^\/\//', '/', $url);
    
    // فحص نهائي نهائي نهائي: التأكد من أن المسار صحيح 100%
    if ($role && (strpos($url, '/dashboard/') === false || substr($url, -strlen($role . '.php')) !== $role . '.php')) {
        error_log("getDashboardUrl CRITICAL: Final URL validation failed: {$url}, forcing: /dashboard/{$role}.php");
        $url = '/dashboard/' . $role . '.php';
    }
    
    return $url;
}

/**
 * Get relative URL (for use in templates)
 */
function getRelativeUrl($path) {
    // إذا كان المسار مطلقاً (يبدأ بـ /)، استخدمه مباشرة
    if (strpos($path, '/') === 0) {
        return $path;
    }
    
    $base = getBasePath();
    
    // Remove leading slash if present
    $path = ltrim($path, '/');
    
    // إذا كان base فارغاً، استخدم المسار مباشرة
    if (empty($base)) {
        return '/' . $path;
    }
    
    // التأكد من أن base يبدأ بـ /
    if (strpos($base, '/') !== 0) {
        $base = '/' . $base;
    }
    
    // إزالة / من النهاية
    $base = rtrim($base, '/');
    
    // إزالة 'dashboard' من base إذا كان المسار المطلوب يبدأ بـ 'dashboard/'
    // لمنع تكرار dashboard/dashboard
    if (strpos($path, 'dashboard/') === 0) {
        $baseParts = explode('/', trim($base, '/'));
        $baseParts = array_filter($baseParts, function($part) {
            return $part !== 'dashboard' && !empty($part);
        });
        $base = !empty($baseParts) ? '/' . implode('/', $baseParts) : '';
    }
    
    return $base . '/' . $path;
}

/**
 * Get absolute URL (for use in templates)
 * يحصل على الدومين الحالي ديناميكياً من مصادر متعددة
 */
function getAbsoluteUrl($path) {
    // التحقق من HTTPS - إجبار استخدام HTTPS في الإنتاج
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
    );
    
    // محاولة الحصول على الدومين من مصادر متعددة (بترتيب الأولوية)
    $host = null;
    
    // 1. محاولة من HTTP_HOST (الأولوية الأولى)
    if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
        // إزالة المنفذ إذا كان موجوداً (مثل: domain.com:8080 -> domain.com)
        if (strpos($host, ':') !== false) {
            $hostParts = explode(':', $host);
            $host = $hostParts[0];
        }
    }
    
    // 2. إذا لم يكن متاحاً، محاولة من HTTP_X_FORWARDED_HOST (للمواقع خلف proxy)
    if (empty($host) && isset($_SERVER['HTTP_X_FORWARDED_HOST']) && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
        // قد يحتوي على قائمة مفصولة بفواصل، نأخذ الأول
        if (strpos($host, ',') !== false) {
            $hostParts = explode(',', $host);
            $host = trim($hostParts[0]);
        }
        // إزالة المنفذ
        if (strpos($host, ':') !== false) {
            $hostParts = explode(':', $host);
            $host = $hostParts[0];
        }
    }
    
    // 3. إذا لم يكن متاحاً، محاولة من SERVER_NAME
    if (empty($host) && isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
        $host = $_SERVER['SERVER_NAME'];
    }
    
    // 4. إذا لم يكن متاحاً، محاولة من HTTP_REFERER (كحل أخير)
    if (empty($host) && isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
        $refererParts = parse_url($_SERVER['HTTP_REFERER']);
        if (isset($refererParts['host']) && !empty($refererParts['host'])) {
            $host = $refererParts['host'];
        }
    }
    
    // 5. إذا لم يكن متاحاً نهائياً، استخدام localhost كقيمة افتراضية
    if (empty($host)) {
        $host = 'localhost';
        error_log('getAbsoluteUrl: Could not determine host from any source, using localhost fallback');
    }
    
    // التحقق من localhost لتحديد البروتوكول
    $isLocalhost = (
        $host === 'localhost' ||
        strpos($host, 'localhost:') === 0 ||
        $host === '127.0.0.1' ||
        strpos($host, '127.0.0.1:') === 0
    );
    
    $protocol = ($isHttps || !$isLocalhost) ? "https://" : "http://";
    $base = getBasePath();
    
    // Remove leading slash if present
    $path = ltrim($path, '/');
    
    return $protocol . $host . $base . '/' . $path;
}

/**
 * إعادة التوجيه بعد معالجة POST (POST-Redirect-GET pattern)
 * لمنع تكرار الطلب عند refresh
 * 
 * @param string $page اسم الصفحة (مثل 'warehouse_transfers')
 * @param array $filters معاملات الفلترة للحفاظ عليها
 * @param array $excludeParams معاملات لإزالتها من URL (مثل 'id')
 * @param string $role دور المستخدم (manager, accountant, etc.)
 * @param int|null $pageNum رقم الصفحة للباجينيشن
 */
function redirectAfterPost($page, $filters = [], $excludeParams = ['id'], $role = 'manager', $pageNum = null) {
    // مسح الكاش بعد كل عملية تحديث لجلب البيانات المحدثة
    if (class_exists('Cache')) {
        try {
            Cache::flush();
        } catch (Exception $cacheError) {
            error_log("Error flushing cache in redirectAfterPost: " . $cacheError->getMessage());
        }
    }
    
    // إزالة المعاملات المطلوب استثناؤها
    $redirectParams = array_merge(['page' => $page], $filters);
    
    foreach ($excludeParams as $param) {
        unset($redirectParams[$param]);
    }
    
    // إضافة رقم الصفحة إذا كان موجوداً
    if ($pageNum !== null && $pageNum > 1) {
        $redirectParams['p'] = $pageNum;
    }
    
    // إضافة معامل _nocache لمسح الكاش
    $redirectParams['_nocache'] = time() * 1000 + rand(0, 999);
    
    $redirectUrl = getDashboardUrl($role) . '?' . http_build_query($redirectParams);
    
    // تسجيل محاولة الـ redirect
    $logDir = __DIR__ . '/../storage/logs';
    if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
        $logFile = $logDir . '/php-errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] redirectAfterPost called | URL: {$redirectUrl} | Page: {$page} | Role: {$role} | Headers sent: " . (headers_sent() ? 'yes' : 'no') . PHP_EOL;
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    }

    $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<script>window.location.href = "' . $escapedUrl . '";</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $escapedUrl . '"></noscript>';
    exit;
}

/**
 * التحقق من وجود قيمة في قائمة قبل تعيين selected في select dropdown
 * 
 * @param mixed $value القيمة للتحقق منها
 * @param array $list قائمة العناصر (مصفوفة من arrays مع 'id' key)
 * @param string $keyName اسم المفتاح للبحث (افتراضي: 'id')
 * @return bool true إذا كانت القيمة موجودة في القائمة
 */
function isValidSelectValue($value, $list, $keyName = 'id') {
    if (empty($value) || $value == 0 || $value == '') {
        return false;
    }
    
    $value = intval($value);
    if ($value <= 0 || $value > 100000) {
        return false; // قيم كبيرة غير منطقية (مثل 262145)
    }
    
    foreach ($list as $item) {
        if (isset($item[$keyName]) && intval($item[$keyName]) == $value) {
            return true;
        }
    }
    
    return false;
}


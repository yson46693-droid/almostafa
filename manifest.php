<?php
/**
 * Serve manifest.json with correct Content-Type
 * This file ensures the manifest is served without BOM or encoding issues
 */

// إزالة أي output buffer قديم
while (ob_get_level()) {
    ob_end_clean();
}

// التأكد من عدم وجود أي output قبل headers
if (ob_get_level() === 0) {
    ob_start();
}

// قراءة ملف manifest.json مباشرة
$manifestPath = __DIR__ . '/manifest.json';
if (!file_exists($manifestPath)) {
    ob_clean();
    http_response_code(404);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Manifest not found'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

$content = @file_get_contents($manifestPath);
if ($content === false) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Failed to read manifest'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

// إزالة BOM بطرق متعددة (UTF-8, UTF-16, UTF-32)
$content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // UTF-8 BOM
$content = preg_replace('/^\xFF\xFE/', '', $content); // UTF-16 LE BOM
$content = preg_replace('/^\xFE\xFF/', '', $content); // UTF-16 BE BOM
$content = preg_replace('/^\x00\x00\xFE\xFF/', '', $content); // UTF-32 BE BOM
$content = preg_replace('/^\xFF\xFE\x00\x00/', '', $content); // UTF-32 LE BOM

// إزالة أي مسافات أو أحرف غير مرئية في البداية والنهاية
$content = trim($content);
$content = ltrim($content);
$content = rtrim($content);

// إزالة أي أحرف غير مرئية أخرى في البداية
$content = preg_replace('/^[\x00-\x1F\x7F-\x9F]+/u', '', $content);

// التحقق من أن المحتوى JSON صحيح
$json = @json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

// تصحيح المسارات في manifest.json
// تحديد base path بناءً على موقع الملف ديناميكياً
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = '';
if ($scriptPath !== '/' && $scriptPath !== '' && $scriptPath !== '.') {
    $basePath = rtrim($scriptPath, '/');
}

// تحديث المسارات في JSON
if ($json && isset($json['icons'])) {
    foreach ($json['icons'] as &$icon) {
        if (isset($icon['src'])) {
            // إذا كان المسار نسبي (يبدأ بـ assets/)، أضف basePath
            if (strpos($icon['src'], 'assets/') === 0) {
                $icon['src'] = $basePath . '/' . $icon['src'];
            }
            // إذا كان المسار مطلق (يبدأ بـ /assets/)، أضف basePath
            elseif (strpos($icon['src'], '/assets/') === 0) {
                $icon['src'] = $basePath . $icon['src'];
            }
        }
    }
    unset($icon);
}

// تحديث id و start_url و scope أولاً (قبل معالجة shortcuts)
if (isset($json['id'])) {
    // إذا كان المسار نسبي (لا يبدأ بـ /)، أضف basePath
    if (strpos($json['id'], '/') !== 0) {
        $json['id'] = $basePath . '/' . $json['id'];
    }
    // إذا كان المسار مطلق (يبدأ بـ /) ولم يكن يحتوي على basePath، أضفه
    elseif (strpos($json['id'], $basePath) !== 0) {
        $json['id'] = $basePath . $json['id'];
    }
    // التأكد من أن id ينتهي بـ / (مطلوب لـ Android)
    if (substr($json['id'], -1) !== '/') {
        $json['id'] .= '/';
    }
}
if (isset($json['start_url'])) {
    // إذا كان المسار نسبي (لا يبدأ بـ /)، أضف basePath
    if (strpos($json['start_url'], '/') !== 0) {
        $json['start_url'] = $basePath . '/' . $json['start_url'];
    }
    // إذا كان المسار مطلق (يبدأ بـ /) ولم يكن يحتوي على basePath، أضفه
    elseif (strpos($json['start_url'], $basePath) !== 0) {
        $json['start_url'] = $basePath . $json['start_url'];
    }
    // التأكد من أن start_url ينتهي بـ / (مطلوب لـ Android)
    if (substr($json['start_url'], -1) !== '/') {
        $json['start_url'] .= '/';
    }
}
if (isset($json['scope'])) {
    // إذا كان المسار نسبي (لا يبدأ بـ /)، أضف basePath
    if (strpos($json['scope'], '/') !== 0) {
        $json['scope'] = $basePath . '/' . $json['scope'];
    }
    // إذا كان المسار مطلق (يبدأ بـ /) ولم يكن يحتوي على basePath، أضفه
    elseif (strpos($json['scope'], $basePath) !== 0) {
        $json['scope'] = $basePath . $json['scope'];
    }
    // التأكد من أن scope ينتهي بـ / (مطلوب لـ Android)
    if (substr($json['scope'], -1) !== '/') {
        $json['scope'] .= '/';
    }
}

// تحديث shortcuts icons و URLs (بعد تحديث start_url و scope)
if (isset($json['shortcuts']) && is_array($json['shortcuts'])) {
    // الحصول على scope النهائي (بعد التحديث)
    $finalScope = isset($json['scope']) ? $json['scope'] : ($basePath ? $basePath . '/' : '/');
    $finalStartUrl = isset($json['start_url']) ? trim($json['start_url']) : ($basePath ? $basePath . '/' : '/');
    
    // التأكد من أن start_url غير فارغ
    if (empty($finalStartUrl)) {
        $finalStartUrl = $basePath ? $basePath . '/' : '/';
    }
    
    // التأكد من أن start_url ينتهي بـ / إذا لم يكن كذلك
    if (substr($finalStartUrl, -1) !== '/') {
        $finalStartUrl .= '/';
    }
    
    $validShortcuts = [];
    foreach ($json['shortcuts'] as $shortcutIndex => $shortcut) {
        // التأكد من أن shortcut هو array
        if (!is_array($shortcut)) {
            continue;
        }
        
        // تحديث icons في shortcut
        if (isset($shortcut['icons']) && is_array($shortcut['icons'])) {
            foreach ($shortcut['icons'] as &$icon) {
                if (isset($icon['src'])) {
                    // إذا كان المسار نسبي (يبدأ بـ assets/)، أضف basePath
                    if (strpos($icon['src'], 'assets/') === 0) {
                        $icon['src'] = $basePath . '/' . $icon['src'];
                    }
                    // إذا كان المسار مطلق (يبدأ بـ /assets/)، أضف basePath
                    elseif (strpos($icon['src'], '/assets/') === 0) {
                        $icon['src'] = $basePath . $icon['src'];
                    }
                }
            }
            unset($icon);
        }
        
        // معالجة url في shortcuts - يجب أن تكون ضمن scope
        // التأكد من أن كل shortcut لديه url صحيح
        if (!isset($shortcut['url']) || empty(trim($shortcut['url']))) {
            // إذا لم يكن هناك url أو كان فارغاً، استخدم start_url المحدث
            $shortcut['url'] = $finalStartUrl;
        }
        
        // تنظيف URL من المسافات
        $shortcut['url'] = trim($shortcut['url']);
        
        // إذا كان URL فارغاً بعد التنظيف، استخدم start_url
        if (empty($shortcut['url'])) {
            $shortcut['url'] = $finalStartUrl;
        }
        
        $shortcutUrl = $shortcut['url'];
        
        // تنظيف scope للاستخدام (إزالة / في النهاية)
        $scopePath = rtrim($finalScope, '/');
        if (empty($scopePath)) {
            $scopePath = '/';
        }
        
        // معالجة URL ليكون ضمن scope
        // إذا كان URL نسبي (لا يبدأ بـ / أو http/https)، أضف / في البداية
        if (strpos($shortcutUrl, 'http://') !== 0 && strpos($shortcutUrl, 'https://') !== 0) {
            // إذا كان URL لا يبدأ بـ /، أضف /
            if (strpos($shortcutUrl, '/') !== 0) {
                $shortcutUrl = '/' . $shortcutUrl;
            }
            
            // إضافة basePath إذا كان موجوداً ولم يكن موجوداً في URL
            if ($basePath && strpos($shortcutUrl, $basePath) !== 0) {
                $shortcutUrl = $basePath . $shortcutUrl;
            }
        }
        
        // التأكد من أن URL ضمن scope
        // إزالة أي query parameters و hash للتحقق
        $urlPath = parse_url($shortcutUrl, PHP_URL_PATH);
        if ($urlPath === null) {
            $urlPath = $shortcutUrl;
        }
        
        // تنظيف URL path (إزالة / في النهاية للتحقق)
        $urlPathClean = rtrim($urlPath, '/');
        if (empty($urlPathClean)) {
            $urlPathClean = '/';
        }
        
        // التحقق من أن URL ضمن scope
        // يجب أن يبدأ URL بنفس scope
        $isWithinScope = false;
        if ($scopePath === '/') {
            // إذا كان scope هو /، فإن أي URL مطلق (يبدأ بـ /) يكون ضمن scope
            $isWithinScope = (strpos($urlPath, '/') === 0);
        } else {
            // يجب أن يبدأ URL بـ scope بالضبط
            // مثال: scope = "/albarakah" و URL = "/albarakah/dashboard/manager.php" ✓
            // مثال: scope = "/albarakah" و URL = "/dashboard/manager.php" ✗
            // يجب أن يكون URL يبدأ بـ scope + / أو يساوي scope
            $isWithinScope = (
                strpos($urlPath, $scopePath . '/') === 0 || 
                $urlPath === $scopePath || 
                $urlPath === $scopePath . '/'
            );
        }
        
        if ($isWithinScope) {
            // URL ضمن scope، استخدمه
            $shortcut['url'] = $shortcutUrl;
        } else {
            // إذا كان خارج scope، استخدم start_url المحدث
            // هذا يمنع تحذير Manifest "property 'url' ignored, should be within scope"
            $shortcut['url'] = $finalStartUrl;
        }
        
        // التأكد النهائي من وجود url صحيح قبل الإضافة
        // تنظيف url مرة أخرى للتأكد
        if (!isset($shortcut['url']) || $shortcut['url'] === null) {
            $shortcut['url'] = $finalStartUrl;
        }
        
        // تحويل url إلى string وتنظيفه
        $shortcut['url'] = trim((string)$shortcut['url']);
        
        // إذا كان url فارغاً بعد التنظيف، استخدم start_url كبديل نهائي
        if (empty($shortcut['url'])) {
            $shortcut['url'] = $finalStartUrl;
        }
        
        // التحقق النهائي: يجب أن يكون url موجوداً وغير فارغ
        // التأكد من أن url هو string غير فارغ
        $finalUrl = trim((string)$shortcut['url']);
        if (!empty($finalUrl)) {
            $shortcut['url'] = $finalUrl;
            $validShortcuts[] = $shortcut;
        } else {
            // إذا لم يكن هناك url صحيح بعد كل المحاولات، تخطى هذا shortcut
            // لا نضيفه إلى القائمة لتجنب التحذيرات
            continue;
        }
    }
    
    // استبدال shortcuts بالقائمة الصالحة فقط
    if (!empty($validShortcuts)) {
        $json['shortcuts'] = array_values($validShortcuts);
    } else {
        // إذا كانت shortcuts فارغة، احذفها تماماً لتجنب التحذيرات
        unset($json['shortcuts']);
    }
}

// تحويل JSON مرة أخرى
$content = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// تنظيف output buffer قبل إرسال headers
ob_clean();

// إرسال headers - يجب أن يكون قبل أي output
if (!headers_sent()) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
}

// إرسال المحتوى بدون أي output إضافي
echo $content;
ob_end_flush();
exit(0);

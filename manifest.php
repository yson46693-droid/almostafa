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

// تحديث shortcuts icons أيضاً
if (isset($json['shortcuts'])) {
    // الحصول على scope النهائي (سيتم تحديثه لاحقاً إذا لم يكن محدداً)
    $finalScope = isset($json['scope']) ? $json['scope'] : ($basePath ? $basePath . '/' : '/');
    
    $validShortcuts = [];
    foreach ($json['shortcuts'] as $shortcutIndex => $shortcut) {
        if (isset($shortcut['icons'])) {
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
            // إذا لم يكن هناك url أو كان فارغاً، استخدم start_url
            if (isset($json['start_url']) && !empty(trim($json['start_url']))) {
                $shortcut['url'] = trim($json['start_url']);
            } else {
                // إذا لم يكن هناك start_url، تخطى هذا shortcut
                continue;
            }
        }
        
        // تنظيف URL من المسافات
        $shortcut['url'] = trim($shortcut['url']);
        
        $shortcutUrl = $shortcut['url'];
        
        // إذا كان المسار نسبي (لا يبدأ بـ /)، أضف basePath
        if (strpos($shortcutUrl, '/') !== 0 && strpos($shortcutUrl, 'http') !== 0) {
            $shortcutUrl = $basePath . '/' . $shortcutUrl;
        }
        // إذا كان المسار مطلق (يبدأ بـ /) ولم يكن يحتوي على basePath، أضفه
        elseif (strpos($shortcutUrl, '/') === 0 && strpos($shortcutUrl, $basePath) !== 0 && $basePath) {
            $shortcutUrl = $basePath . $shortcutUrl;
        }
        
        // التأكد من أن URL ضمن scope (يبدأ بنفس scope)
        // إزالة أي query parameters للتحقق
        $urlPath = parse_url($shortcutUrl, PHP_URL_PATH) ?? $shortcutUrl;
        $scopePath = rtrim($finalScope, '/');
        
        // التحقق من أن URL ضمن scope
        if ($scopePath && strpos($urlPath, $scopePath) === 0) {
            $shortcut['url'] = $shortcutUrl;
        } else {
            // إذا كان خارج scope، استخدم start_url بدلاً من حذف url
            // هذا يمنع تحذير Manifest "property 'url' not present"
            if (isset($json['start_url'])) {
                $shortcut['url'] = $json['start_url'];
            } else {
                // إذا لم يكن هناك start_url، تخطى هذا shortcut
                continue;
            }
        }
        
        // إضافة shortcut إلى القائمة الصالحة
        $validShortcuts[] = $shortcut;
    }
    // استبدال shortcuts بالقائمة الصالحة فقط
    // التأكد من أن جميع shortcuts لديها url صحيح قبل الإضافة
    $json['shortcuts'] = array_values(array_filter($validShortcuts, function($shortcut) {
        return isset($shortcut['url']) && !empty(trim($shortcut['url']));
    }));
    
    // إذا كانت shortcuts فارغة، احذفها تماماً لتجنب التحذيرات
    if (empty($json['shortcuts'])) {
        unset($json['shortcuts']);
    }
}

// تحديث id و start_url و scope
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

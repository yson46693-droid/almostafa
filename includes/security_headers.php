<?php
/**
 * Security Headers (InfinityFree Compatible)
 * رؤوس الأمان - متوافقة مع InfinityFree
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class SecurityHeaders {
    /**
     * تطبيق رؤوس الأمان - متوافقة مع InfinityFree
     */
    public static function apply() {
        // التحقق من أن الرؤوس لم يتم إرسالها بعد
        if (headers_sent()) {
            return;
        }
        
        // X-Frame-Options: منع clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // X-Content-Type-Options: منع MIME sniffing
        header('X-Content-Type-Options: nosniff');
        
        // X-XSS-Protection: حماية XSS (للمتصفحات القديمة)
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer-Policy: التحكم في معلومات المرجع
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy - محسّنة للأمان
        // ملاحظة: 'unsafe-inline' و 'unsafe-eval' مطلوبان لبعض المكتبات القديمة
        // يمكن إزالتها تدريجياً بعد تحديث الكود لاستخدام nonces
        $csp = "default-src 'self' " .
               "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com " .
               "https://api.telegram.org " .
               "https://api.apdf.io; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' " .
               "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
               "style-src 'self' 'unsafe-inline' " .
               "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
               "img-src 'self' data: https: blob:; " .
               "font-src 'self' data: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
               "connect-src 'self' https://api.telegram.org https://api.apdf.io; " .
               "frame-src 'self'; " .
               "object-src 'none'; " .
               "base-uri 'self'; " .
               "form-action 'self';";
        
        header("Content-Security-Policy: {$csp}");
        
        // HSTS - فقط إذا كان HTTPS متاحاً
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
        );
        
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

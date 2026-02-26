<?php
/**
 * واجهة دردشة جماعية شبيهة بـ Signal
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// منع الكاش عند التبديل بين تبويبات الشريط الجانبي لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/chat.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'production', 'sales', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentUserName = $currentUser['full_name'] ?? ($currentUser['username'] ?? 'عضو');
$currentUserRole = $currentUser['role'] ?? 'member';

$apiBase = getRelativeUrl('api/chat');
$roomName = 'الشات';

$chatCssRelative = 'assets/css/chat.css';
$chatJsRelative = 'assets/js/chat.js';
$chatCssUrl = getRelativeUrl($chatCssRelative);
$chatJsUrl = getRelativeUrl($chatJsRelative);

$chatCssContent = '';
$chatCssFile = __DIR__ . '/../../' . $chatCssRelative;
if (file_exists($chatCssFile) && is_readable($chatCssFile)) {
    $chatCssContent = trim((string) file_get_contents($chatCssFile));
}

$chatJsContent = '';
$chatJsFile = __DIR__ . '/../../' . $chatJsRelative;
if (file_exists($chatJsFile) && is_readable($chatJsFile)) {
    $chatJsContent = trim((string) file_get_contents($chatJsFile));
}

$onlineUsers = getActiveUsers();
$onlineCount = 0;
foreach ($onlineUsers as $onlineUser) {
    if (!empty($onlineUser['is_online'])) {
        $onlineCount++;
    }
}
$membersCount = count($onlineUsers);
?>

<style>
/* Hide footer in chat page */
body .footer {
  display: none !important;
}

/* منع zoom في صفحة الدردشة */
html, body {
  touch-action: pan-x pan-y;
  -ms-touch-action: pan-x pan-y;
  user-select: none;
  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
}

.chat-app {
  touch-action: pan-x pan-y;
  -ms-touch-action: pan-x pan-y;
}

/* منع zoom بالضغط المزدوج على العناصر */
* {
  -webkit-tap-highlight-color: transparent;
  -webkit-touch-callout: none;
}
</style>
<script>
// منع zoom باستخدام JavaScript كحماية إضافية
(function() {
    'use strict';
    
    // منع zoom بالضغط المزدوج (double tap)
    let lastTouchEnd = 0;
    document.addEventListener('touchend', function(event) {
        const now = Date.now();
        if (now - lastTouchEnd <= 300) {
            event.preventDefault();
        }
        lastTouchEnd = now;
    }, false);
    
    // منع zoom بالضغط والتمرير (pinch zoom)
    let touches = [];
    document.addEventListener('touchstart', function(event) {
        touches = event.touches;
    }, {passive: true});
    
    document.addEventListener('touchmove', function(event) {
        if (touches.length === 2) {
            // إذا كان هناك إصبعان، منع zoom
            event.preventDefault();
        }
    }, {passive: false});
    
    // منع zoom بالضغط المزدوج على النصوص
    document.addEventListener('gesturestart', function(event) {
        event.preventDefault();
    });
    
    document.addEventListener('gesturechange', function(event) {
        event.preventDefault();
    });
    
    document.addEventListener('gestureend', function(event) {
        event.preventDefault();
    });
    
    // التأكد من viewport meta tag
    function ensureViewportMeta() {
        let viewport = document.querySelector('meta[name="viewport"]');
        if (viewport) {
            viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no');
        } else {
            viewport = document.createElement('meta');
            viewport.name = 'viewport';
            viewport.content = 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no';
            document.head.appendChild(viewport);
        }
    }
    
    // التأكد من viewport عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ensureViewportMeta);
    } else {
        ensureViewportMeta();
    }
})();
</script>

<?php if ($chatCssContent !== ''): ?>
<style><?php echo $chatCssContent; ?></style>
<?php else: ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($chatCssUrl, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>

<!-- Bootstrap CSS و Bootstrap Icons يتم تحميلهما في header.php -->

<div class="chat-app" dir="rtl" data-chat-app
     data-current-user-id="<?php echo $currentUserId; ?>"
     data-current-user-name="<?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?>"
     data-current-user-role="<?php echo htmlspecialchars($currentUserRole, ENT_QUOTES, 'UTF-8'); ?>">
    <button class="chat-sidebar-toggle" type="button" data-chat-sidebar-toggle aria-label="تبديل قائمة الأعضاء">
        <span class="chat-sidebar-toggle-icon">☰</span>
    </button>
    <div class="chat-sidebar-overlay" data-chat-sidebar-overlay></div>
    <aside class="chat-sidebar" data-chat-sidebar>
        <div class="chat-sidebar-header">
            <h2>الأعضاء</h2>
            <div class="chat-sidebar-header-right">
                <span class="chat-loading">تحديث</span>
                <button class="chat-sidebar-close" type="button" data-chat-sidebar-close aria-label="إغلاق قائمة الأعضاء">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>
        <div class="chat-sidebar-search">
            <input type="search" placeholder="ابحث عن عضو..." data-chat-search>
        </div>
        <div class="chat-user-list" data-chat-users>
            <!-- سيتم تعبئته عبر JavaScript -->
        </div>
    </aside>
    <main class="chat-main">
        <header class="chat-header">
            <div class="chat-header-left">
                <button class="chat-menu-button" type="button" data-chat-members-toggle aria-label="القائمة">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                    <span class="chat-notification-badge" data-chat-notification-badge>99</span>
                </button>
            </div>
            <div class="chat-header-center">
                <div class="chat-app-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                        <circle cx="7" cy="8" r="1.5"/>
                        <circle cx="12" cy="8" r="1.5"/>
                        <circle cx="17" cy="8" r="1.5"/>
                    </svg>
                </div>
                <h1><?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
            <div class="chat-header-right">
                <button class="chat-button chat-theme-toggle" type="button" data-chat-theme-toggle aria-label="تبديل الوضع الليلي">
                    <span class="chat-theme-icon">🌙</span>
                </button>
            </div>
        </header>
        <section class="chat-messages" data-chat-messages>
            <div class="chat-empty-state" data-chat-empty>
                <h3>ابدأ المحادثة الآن</h3>
                <p>شارك فريقك آخر المستجدات، إرسال الرسائل يتم تحديثه فورياً مع ظهور إشعارات عند وصول أي رسالة جديدة.</p>
            </div>
        </section>
        <footer class="chat-composer" data-chat-composer>
            <div class="chat-reply-bar" data-chat-reply>
                <div class="chat-reply-info">
                    <strong data-chat-reply-name></strong>
                    <span data-chat-reply-text></span>
                </div>
                <button class="chat-reply-dismiss" type="button" data-chat-reply-dismiss>&times;</button>
            </div>
            <div class="chat-input-wrapper">
                <div class="chat-emoji-wrapper">
                    <button class="chat-icon-button chat-emoji-button" type="button" title="إيموجي" data-chat-emoji aria-label="إيموجي">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                            <line x1="9" y1="9" x2="9.01" y2="9"/>
                            <line x1="15" y1="9" x2="15.01" y2="9"/>
                        </svg>
                    </button>
                    <div class="chat-emoji-picker" data-chat-emoji-picker>
                        <div class="chat-emoji-picker-header">
                            <span>اختر إيموجي</span>
                            <button class="chat-emoji-picker-close" type="button" data-chat-emoji-close>&times;</button>
                        </div>
                        <div class="chat-emoji-picker-body" data-chat-emoji-list>
                            <!-- سيتم تعبئته عبر JavaScript -->
                        </div>
                    </div>
                </div>
                <textarea
                    class="chat-input"
                    data-chat-input
                    rows="1"
                    placeholder="Type message..."
                    autocomplete="off"></textarea>
                <input type="file" id="chat-file-input" style="display: none;" accept="*/*" data-chat-file-input>
                <input type="file" id="chat-image-input" style="display: none;" accept="image/*,video/*" capture="environment" data-chat-image-input>
                <button class="chat-icon-button chat-image-button" type="button" title="صور/فيديو" data-chat-image aria-label="صور/فيديو">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                    </svg>
                </button>
                <button class="chat-icon-button chat-attach-button" type="button" title="مرفق" data-chat-attach aria-label="مرفق">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                    </svg>
                </button>
                <button class="chat-icon-button chat-send-button" type="button" title="إرسال" data-chat-send aria-label="إرسال الرسالة">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                </button>
            </div>
        </footer>
        <div class="chat-toast" data-chat-toast>تم تحديث الدردشة</div>
    </main>
</div>
<script>
    window.CHAT_API_BASE = '<?php echo htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8'); ?>';
</script>
<?php if ($chatJsContent !== ''): ?>
<script><?php echo $chatJsContent; ?></script>
<?php else: ?>
<script src="<?php echo htmlspecialchars($chatJsUrl, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php endif; ?>


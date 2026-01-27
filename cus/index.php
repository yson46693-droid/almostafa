<?php
/**
 * PWA مستقلة لإدارة العملاء المحليين
 * يمكن تثبيتها كتطبيق منفصل على أي جهاز
 */

define('ACCESS_ALLOWED', true);
define('STANDALONE_PWA', true); // تعريف ثابت للكشف عن PWA المستقل

// تنظيف أي output buffer سابق
while (ob_get_level() > 0) {
    ob_end_clean();
}

session_start([
    'cookie_lifetime' => 0,
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'use_strict_mode' => true,
]);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

requireRole(['manager', 'accountant', 'developer']);

// تعريف ثابت لتجنب requireRole داخل الملف
define('LOCAL_CUSTOMERS_MODULE_BOOTSTRAPPED', true);

// تحميل الملفات الإضافية
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/table_styles.php';

$currentUser = getCurrentUser();
$baseUrl = getBasePath();
$assetsUrl = ASSETS_URL;
$cacheVersion = time(); // يمكن استخدام version.json لاحقاً

// بدء output buffering
ob_start();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#f1c40f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="عملاء الشركة">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="عملاء الشركة">
    <meta name="msapplication-TileColor" content="#f1c40f">
    <meta name="msapplication-tap-highlight" content="no">
    
    <title>عملاء الشركة - Customers</title>
    
    <!-- Manifest -->
    <link rel="manifest" href="manifest.php">
    
    <!-- Icons -->
    <link rel="icon" type="image/png" sizes="512x512" href="cus.png">
    <link rel="apple-touch-icon" href="cus.png">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" crossorigin="anonymous">
    
    <!-- Google Fonts - Cairo -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="<?php echo $assetsUrl; ?>css/homeline-dashboard.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/topbar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/responsive.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/sidebar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/cards.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/modal-mobile-fix.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }
        .pwa-header {
            background: linear-gradient(135deg, #f1c40f 0%, #f39c12 100%);
            color: white;
            padding: 1rem;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .pwa-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }
        .pwa-install-prompt {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 0.75rem;
            text-align: center;
            display: none;
            position: sticky;
            top: 0;
            z-index: 999;
        }
        .pwa-install-prompt.show {
            display: block;
        }
        .pwa-install-prompt button {
            margin: 0.25rem;
        }
        main {
            min-height: calc(100vh - 200px);
        }
    </style>
</head>
<body>
    <div class="pwa-header">
        <h1><i class="bi bi-people-fill me-2"></i>عملاء الشركة</h1>
    </div>
    
    <div class="pwa-install-prompt" id="installPrompt">
        <div class="d-flex justify-content-between align-items-center">
            <span>يمكنك تثبيت هذا التطبيق على جهازك</span>
            <div>
                <button class="btn btn-sm btn-primary" id="installButton">تثبيت</button>
                <button class="btn btn-sm btn-secondary" id="dismissInstall">إخفاء</button>
            </div>
        </div>
    </div>
    
    <main class="container-fluid py-3">
        <?php
        // تحميل صفحة العملاء المحليين
        $modulePath = __DIR__ . '/../modules/manager/local_customers.php';
        if (!file_exists($modulePath)) {
            echo '<div class="alert alert-danger">صفحة العملاء المحليين غير متاحة حالياً</div>';
        } else {
            include $modulePath;
        }
        ?>
    </main>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js" crossorigin="anonymous"></script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    
    <!-- Custom JS -->
    <script src="<?php echo $assetsUrl; ?>js/main.js?v=<?php echo $cacheVersion; ?>"></script>
    
    <!-- PWA Install Script -->
    <script>
    (function() {
        'use strict';
        
        let deferredPrompt = null;
        const installPrompt = document.getElementById('installPrompt');
        const installButton = document.getElementById('installButton');
        const dismissButton = document.getElementById('dismissInstall');
        
        // التحقق من أن التطبيق مثبت بالفعل
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                            window.navigator.standalone ||
                            window.matchMedia('(display-mode: fullscreen)').matches;
        
        if (isStandalone) {
            // إخفاء زر التثبيت إذا كان التطبيق مثبتاً بالفعل
            if (installPrompt) {
                installPrompt.style.display = 'none';
            }
        }
        
        // معالجة حدث beforeinstallprompt
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            
            // إظهار رسالة التثبيت إذا لم يتم إخفاؤها مسبقاً
            if (!localStorage.getItem('pwa-install-dismissed') && installPrompt) {
                installPrompt.classList.add('show');
            }
        });
        
        // زر التثبيت
        if (installButton) {
            installButton.addEventListener('click', async () => {
                if (!deferredPrompt) {
                    return;
                }
                
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                
                if (outcome === 'accepted') {
                    if (installPrompt) {
                        installPrompt.classList.remove('show');
                    }
                }
                
                deferredPrompt = null;
            });
        }
        
        // زر الإخفاء
        if (dismissButton) {
            dismissButton.addEventListener('click', () => {
                if (installPrompt) {
                    installPrompt.classList.remove('show');
                    localStorage.setItem('pwa-install-dismissed', '1');
                }
            });
        }
        
        // معالجة حدث appinstalled
        window.addEventListener('appinstalled', () => {
            if (installPrompt) {
                installPrompt.classList.remove('show');
            }
            deferredPrompt = null;
        });
        
        // تسجيل Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                // استخدام مسار نسبي من موقع الصفحة الحالية
                const swPath = new URL('service-worker.js', window.location.href).href;
                navigator.serviceWorker.register(swPath, {
                    scope: './'
                })
                    .then((registration) => {
                        console.log('Service Worker registered successfully:', registration.scope);
                        // التحقق من التحديثات
                        registration.addEventListener('updatefound', () => {
                            const newWorker = registration.installing;
                            if (newWorker) {
                                newWorker.addEventListener('statechange', () => {
                                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                        console.log('New service worker available. Reload to update.');
                                    }
                                });
                            }
                        });
                    })
                    .catch((error) => {
                        console.error('Service Worker registration failed:', error);
                        // لا نعرض رسالة خطأ للمستخدم إلا في وضع التطوير
                        if (window.location.hostname === 'localhost' || 
                            window.location.hostname === '127.0.0.1' ||
                            window.location.hostname.includes('egsystem.top')) {
                            console.warn('Service Worker error details:', {
                                message: error.message,
                                stack: error.stack,
                                swPath: swPath
                            });
                        }
                    });
            });
        }
    })();
    </script>
</body>
</html>
<?php
ob_end_flush();
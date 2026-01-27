/**
 * Auto Refresh Navigation Script
 * سكريبت إعادة التحميل التلقائي للتنقل
 * 
 * يعمل هذا السكريبت مع حسابات: مدير، مندوب مبيعات، عامل إنتاج
 * عند النقر على أي رابط في الشريط الجانبي، يتم إعادة تحميل الصفحة تلقائياً
 * مع إظهار تنبيه للمستخدم
 */

(function() {
    'use strict';
    
    // الأدوار المطلوبة لهذه الوظيفة
    const TARGET_ROLES = ['manager', 'sales', 'production'];
    
    // مفتاح للتخزين المحلي لتتبع حالة إعادة التحميل
    const REFRESH_FLAG_KEY = 'auto_refresh_navigation_flag';
    
    /**
     * الحصول على دور المستخدم من data attribute
     */
    function getUserRole() {
        const body = document.body;
        if (!body) return null;
        
        const role = body.getAttribute('data-user-role');
        return role ? role.toLowerCase().trim() : null;
    }
    
    /**
     * التحقق من أن المستخدم من الأدوار المستهدفة
     */
    function shouldApplyRefresh() {
        const userRole = getUserRole();
        if (!userRole) return false;
        
        return TARGET_ROLES.includes(userRole);
    }
    
    /**
     * إنشاء وإظهار تنبيه على الشاشة
     */
    function showRefreshNotification() {
        // إزالة أي تنبيه سابق
        const existingNotification = document.getElementById('auto-refresh-notification');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        // إنشاء عنصر التنبيه
        const notification = document.createElement('div');
        notification.id = 'auto-refresh-notification';
        notification.innerHTML = `
            <div class="auto-refresh-notification-content">
                <i class="bi bi-arrow-clockwise spin-animation"></i>
                <span>جاري إعادة تحميل الصفحة للتأكد من تحميل جميع العناصر</span>
            </div>
        `;
        
        // إضافة الأنماط المطلوبة
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease-out;
        `;
        
        // إضافة الأنماط للأيقونة
        const style = document.createElement('style');
        style.id = 'auto-refresh-notification-styles';
        style.textContent = `
            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateX(-50%) translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateX(-50%) translateY(0);
                }
            }
            
            @keyframes spin {
                from {
                    transform: rotate(0deg);
                }
                to {
                    transform: rotate(360deg);
                }
            }
            
            .spin-animation {
                animation: spin 1s linear infinite;
            }
            
            .auto-refresh-notification-content {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .auto-refresh-notification-content i {
                font-size: 18px;
            }
        `;
        
        // إضافة الأنماط إذا لم تكن موجودة
        if (!document.getElementById('auto-refresh-notification-styles')) {
            document.head.appendChild(style);
        }
        
        // إضافة التنبيه إلى الصفحة
        document.body.appendChild(notification);
        
        return notification;
    }
    
    /**
     * إخفاء التنبيه
     */
    function hideRefreshNotification() {
        const notification = document.getElementById('auto-refresh-notification');
        if (notification) {
            notification.style.animation = 'slideDown 0.3s ease-out reverse';
            setTimeout(() => {
                notification.remove();
            }, 300);
        }
    }
    
    /**
     * إعادة تحميل الصفحة
     */
    function refreshPage() {
        // إظهار التنبيه
        showRefreshNotification();
        
        // تعيين علامة في sessionStorage لتتبع أننا أجرينا إعادة تحميل
        sessionStorage.setItem(REFRESH_FLAG_KEY, 'true');
        
        // إعادة التحميل بعد تأخير بسيط لإظهار التنبيه
        setTimeout(() => {
            window.location.reload();
        }, 500);
    }
    
    /**
     * التحقق من حالة إعادة التحميل بعد تحميل الصفحة
     */
    function checkRefreshState() {
        const wasRefreshed = sessionStorage.getItem(REFRESH_FLAG_KEY);
        const isSidebarNavigation = sessionStorage.getItem('sidebar_navigation');
        
        if (isSidebarNavigation === 'true' && wasRefreshed !== 'true') {
            // هذا تحميل جديد للصفحة من الشريط الجانبي - نحتاج لإعادة التحميل
            // إظهار التنبيه أولاً
            showRefreshNotification();
            
            // ثم إعادة التحميل بعد تأخير قصير
            setTimeout(() => {
                sessionStorage.setItem(REFRESH_FLAG_KEY, 'true');
                window.location.reload();
            }, 800);
            return;
        }
        
        if (wasRefreshed === 'true') {
            // تم إعادة التحميل - إخفاء التنبيه وإزالة العلامات
            sessionStorage.removeItem(REFRESH_FLAG_KEY);
            sessionStorage.removeItem('sidebar_navigation');
            sessionStorage.removeItem('sidebar_navigation_url');
            
            // إخفاء أي تنبيه موجود بعد تحميل الصفحة
            setTimeout(() => {
                hideRefreshNotification();
            }, 500);
        }
    }
    
    /**
     * كشف نوع الاتصال
     */
    function detectConnectionType() {
        if ('connection' in navigator) {
            const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (conn) {
                const effectiveType = conn.effectiveType || 'unknown';
                const type = conn.type || 'unknown';
                const saveData = conn.saveData || false;
                
                if (saveData || type === 'cellular' || effectiveType === '2g' || effectiveType === 'slow-2g') {
                    return true; // بيانات هاتف
                }
            }
        }
        const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        return isMobileDevice;
    }
    
    /**
     * تنظيف URL وإصلاح أي تكرار في اسم الملف
     */
    function normalizeUrl(url) {
        if (!url || typeof url !== 'string') {
            return url;
        }
        
        try {
            // إنشاء URL object للتعامل معه بشكل صحيح
            const urlObj = new URL(url, window.location.origin);
            
            // إصلاح تكرار اسم الملف في pathname (مثل manager.phpmanager.php)
            let pathname = urlObj.pathname;
            
            // البحث عن نمط ملف.php مكرر
            const phpFilePattern = /([a-z_]+\.php)\1+/gi;
            if (phpFilePattern.test(pathname)) {
                // إزالة التكرار
                pathname = pathname.replace(phpFilePattern, '$1');
            }
            
            // إصلاح أي تكرار آخر لـ .php
            pathname = pathname.replace(/([a-z_]+\.php)+/gi, (match) => {
                // إذا كان هناك تكرار، احتفظ بالاسم الأول فقط
                const parts = match.split('.php');
                return parts[0] + '.php';
            });
            
            // تحديث pathname
            urlObj.pathname = pathname;
            
            return urlObj.href;
        } catch (e) {
            // إذا فشل parsing، حاول إصلاح URL يدوياً
            // إصلاح تكرار manager.phpmanager.php
            url = url.replace(/([a-z_]+\.php)\1+/gi, '$1');
            // إصلاح أي تكرار آخر
            url = url.replace(/([a-z_]+\.php)+/gi, (match) => {
                const parts = match.split('.php');
                return parts[0] + '.php';
            });
            return url;
        }
    }
    
    /**
     * محاولة استخدام AJAX navigation إذا كان متاحاً
     */
    function tryAjaxNavigation(url) {
        // تنظيف URL قبل الاستخدام
        url = normalizeUrl(url);
        
        // التحقق من وجود AjaxNavigation API من ajax-navigation.js
        if (window.AjaxNavigation && typeof window.AjaxNavigation.load === 'function') {
            try {
                window.AjaxNavigation.load(url);
                return true;
            } catch (e) {
                console.warn('AJAX navigation failed, falling back to full reload:', e);
                return false;
            }
        }
        return false;
    }
    
    /**
     * معالجة النقر على روابط الشريط الجانبي
     */
    function handleSidebarLinkClick(event) {
        const link = event.currentTarget;
        
        // التحقق من أن الرابط موجود وأنه رابط تنقل
        if (!link || !link.href) return;
        
        // في PWA، نترك AJAX navigation يتعامل مع التنقل
        if (isPWA()) {
            return; // لا نعترض الأحداث في PWA
        }
        
        // الحصول على URL الهدف وتنظيفه
        const targetUrl = normalizeUrl(link.href);
        
        // إذا كان الرابط هو نفس الصفحة الحالية، لا تفعل شيء
        const currentUrl = window.location.href.split('?')[0];
        const newUrl = targetUrl.split('?')[0];
        
        if (newUrl === currentUrl) {
            // نفس الصفحة - فقط إعادة تحميل
            refreshPage();
            return;
        }
        
        // إضافة علامة خاصة على الرابط لمنع sidebar.js من إغلاق الشريط الجانبي
        link.setAttribute('data-navigating', 'true');
        
        // على الهاتف، إغلاق الشريط الجانبي فوراً قبل منع الانتشار
        const isMobile = window.innerWidth <= 768;
        if (isMobile) {
            const dashboardWrapper = document.querySelector('.dashboard-wrapper');
            if (dashboardWrapper && dashboardWrapper.classList.contains('sidebar-open')) {
                dashboardWrapper.classList.remove('sidebar-open');
                document.body.classList.remove('sidebar-open');
            }
        }
        
        // منع التنقل الافتراضي وإيقاف انتشار الحدث لمنع ajax-navigation و sidebar.js من اعتراضه
        // يجب منع الانتشار بعد إغلاق الشريط الجانبي
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        
        // إزالة العلامة بعد قليل
        setTimeout(() => {
            link.removeAttribute('data-navigating');
        }, 1000);
        
        // كشف نوع الاتصال
        const isMobileData = detectConnectionType();
        
        // عند استخدام بيانات الهاتف، استخدام AJAX navigation لتسريع التنقل
        if (isMobileData) {
            // محاولة استخدام AJAX navigation
            if (tryAjaxNavigation(targetUrl)) {
                // نجح AJAX navigation - لا حاجة لإعادة التحميل الكاملة
                return;
            }
        }
        
        // عند استخدام WiFi أو فشل AJAX navigation، استخدام الطريقة القديمة
        // تعيين علامة في sessionStorage للإشارة إلى أننا ننتقل من الشريط الجانبي
        sessionStorage.setItem('sidebar_navigation', 'true');
        sessionStorage.setItem('sidebar_navigation_url', targetUrl);
        // إزالة علامة إعادة التحميل السابقة
        sessionStorage.removeItem(REFRESH_FLAG_KEY);
        
        // الانتقال إلى الصفحة الجديدة (سيتم إظهار التنبيه وإعادة التحميل في الصفحة الجديدة)
        window.location.href = targetUrl;
    }
    
    /**
     * كشف إذا كان التطبيق يعمل كـ PWA
     */
    function isPWA() {
        // كشف PWA من خلال عدة طرق
        // 1. التحقق من display mode
        if (window.matchMedia('(display-mode: standalone)').matches) {
            return true;
        }
        
        // 2. التحقق من Service Worker
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            return true;
        }
        
        // 3. التحقق من manifest
        if (window.matchMedia('(display-mode: standalone)').matches || 
            window.matchMedia('(display-mode: fullscreen)').matches ||
            window.matchMedia('(display-mode: minimal-ui)').matches) {
            return true;
        }
        
        // 4. التحقق من وجود start_url في manifest
        const manifestLink = document.querySelector('link[rel="manifest"]');
        if (manifestLink) {
            return true;
        }
        
        return false;
    }
    
    /**
     * تهيئة السكريبت
     */
    function init() {
        // في PWA، نعطل auto-refresh-navigation تماماً ونستخدم AJAX navigation فقط
        if (isPWA()) {
            console.log('PWA detected - disabling auto-refresh-navigation, using AJAX navigation only');
            return; // لا نضيف event listeners في PWA
        }
        
        // التحقق من أن المستخدم من الأدوار المستهدفة
        if (!shouldApplyRefresh()) {
            return;
        }
        
        // التحقق من حالة إعادة التحميل بعد تحميل الصفحة
        checkRefreshState();
        
        // الانتظار حتى يتم تحميل DOM بالكامل
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachEventListeners);
        } else {
            attachEventListeners();
        }
    }
    
    /**
     * إرفاق مستمعي الأحداث
     */
    function attachEventListeners() {
        // البحث عن جميع روابط الشريط الجانبي
        const sidebarLinks = document.querySelectorAll('.homeline-sidebar .nav-link, .sidebar .nav-link');
        
        if (sidebarLinks.length === 0) {
            // محاولة مرة أخرى بعد تأخير قصير
            setTimeout(attachEventListeners, 100);
            return;
        }
        
        // إضافة مستمع الأحداث لكل رابط
        sidebarLinks.forEach(link => {
            // التحقق من أن الرابط ليس للتنقل الخارجي أو خاص
            const href = link.getAttribute('href');
            if (!href || href === '#' || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                return;
            }
            
            // إضافة مستمع الأحداث في مرحلة الالتقاط (capture) لضمان التنفيذ قبل ajax-navigation
            link.addEventListener('click', handleSidebarLinkClick, true);
        });
        
        // معالجة النقر على شعار الشريط الجانبي أيضاً
        const sidebarLogo = document.querySelector('.homeline-sidebar .sidebar-logo, .sidebar .sidebar-logo');
        if (sidebarLogo) {
            sidebarLogo.addEventListener('click', function(event) {
                // التحقق من أن الرابط موجود
                const href = this.getAttribute('href');
                if (href && href !== '#' && !href.startsWith('javascript:')) {
                    const currentUrl = window.location.href.split('?')[0];
                    const newUrl = href.split('?')[0];
                    
                    if (newUrl === currentUrl) {
                        // نفس الصفحة - فقط إعادة تحميل
                        event.preventDefault();
                        event.stopPropagation();
                        event.stopImmediatePropagation();
                        refreshPage();
                    } else {
                        // صفحة جديدة - نفس منطق روابط الشريط الجانبي
                        event.preventDefault();
                        event.stopPropagation();
                        event.stopImmediatePropagation();
                        sessionStorage.setItem('sidebar_navigation', 'true');
                        sessionStorage.setItem('sidebar_navigation_url', href);
                        sessionStorage.removeItem(REFRESH_FLAG_KEY);
                        window.location.href = href;
                    }
                }
            });
        }
    }
    
    // تشغيل السكريبت
    init();
    
})();

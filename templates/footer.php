            
<?php


if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}
?>
    <!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light border-top safe-area-bottom">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6 text-center text-md-start">
                    <small class="text-muted">
                        powered by <a href="https://www.wa.me/+201102289090" target="_blank">Osama Saied</a>
                    </small>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- حل شامل لحذف Cache بعد أي طلب في أي نموذج -->
    <script>
    (function() {
        'use strict';
        
        // منع تخزين الصفحة في cache عند عمل refresh - محسّن لمنع Error Code: -2
        // مع منع refresh loop
        let pageshowRefreshHandled = false;
        window.addEventListener('pageshow', function(event) {
            // منع refresh loop - فقط معالجة مرة واحدة لكل صفحة
            if (pageshowRefreshHandled) {
                return;
            }
            
            // التحقق من أن الصفحة من cache وليس هناك _refresh parameter موجود بالفعل
            if (event.persisted) {
                const currentUrl = new URL(window.location.href);
                const hasRefreshParam = currentUrl.searchParams.has('_refresh') || 
                                       currentUrl.searchParams.has('_t') ||
                                       currentUrl.searchParams.has('_nocache');
                
                // إذا كان هناك معامل refresh موجود بالفعل، لا نقوم بإضافة واحد جديد
                if (hasRefreshParam) {
                    pageshowRefreshHandled = true;
                    return;
                }
                
                // التحقق من أن الصفحة ليست صفحة الحضور (attendance) - منع refresh عند فتح الكاميرا
                const isAttendancePage = window.location.pathname.includes('attendance.php') ||
                                         window.location.pathname.includes('attendance');
                
                if (isAttendancePage) {
                    // التحقق من أن modal الكاميرا مفتوح - إذا كان مفتوحاً، لا تقم بعمل refresh
                    const cameraModal = document.getElementById('cameraModal');
                    if (cameraModal) {
                        // التحقق من class show أو style display
                        const isModalVisible = cameraModal.classList.contains('show') || 
                                             window.getComputedStyle(cameraModal).display !== 'none';
                        if (isModalVisible) {
                            pageshowRefreshHandled = true;
                            return;
                        }
                    }
                    
                    // التحقق من أن الكاميرا تعمل - إذا كانت تعمل، لا تقم بعمل refresh
                    const video = document.getElementById('video');
                    if (video) {
                        const hasStream = video.srcObject !== null;
                        const isPlaying = !video.paused && video.readyState >= 2;
                        const isVisible = window.getComputedStyle(video).display !== 'none' && 
                                        window.getComputedStyle(video).visibility !== 'hidden';
                        
                        if (hasStream || isPlaying || isVisible) {
                            pageshowRefreshHandled = true;
                            return;
                        }
                    }
                    
                    // التحقق من أن حالة التحميل نشطة - إذا كانت نشطة، لا تقم بعمل refresh
                    const cameraLoading = document.getElementById('cameraLoading');
                    if (cameraLoading) {
                        const loadingStyle = window.getComputedStyle(cameraLoading);
                        const isLoading = loadingStyle.display !== 'none' && 
                                       loadingStyle.visibility !== 'hidden';
                        if (isLoading) {
                            pageshowRefreshHandled = true;
                            return;
                        }
                    }
                    
                    // منع refresh تماماً على صفحة attendance إذا كان هناك أي نشاط كاميرا
                    // هذا يضمن عدم حدوث refresh أثناء استخدام الكاميرا
                    pageshowRefreshHandled = true;
                    return;
                }
                
                // التحقق من sessionStorage لمنع refresh متكرر في نفس الجلسة
                try {
                    const lastRefresh = sessionStorage.getItem('last_pageshow_refresh');
                    const now = Date.now();
                    
                    // إذا كان آخر refresh منذ أقل من 5 ثوان، تجاهل
                    if (lastRefresh && (now - parseInt(lastRefresh, 10)) < 5000) {
                        pageshowRefreshHandled = true;
                        return;
                    }
                    
                    // حفظ timestamp آخر refresh
                    sessionStorage.setItem('last_pageshow_refresh', now.toString());
                } catch (e) {
                    // تجاهل أخطاء sessionStorage
                }
                
                // إذا كانت الصفحة من cache، أعد تحميلها من السيرفر
                // استخدام طريقة آمنة لمنع Error Code: -2
                try {
                    // إضافة timestamp للـ URL لفرض reload من السيرفر
                    const url = new URL(window.location.href);
                    url.searchParams.set('_refresh', Date.now().toString());
                    
                    // استخدام replaceState أولاً ثم reload
                    window.history.replaceState({}, '', url.toString());
                    
                    // تعيين flag لمنع refresh loop
                    pageshowRefreshHandled = true;
                    
                    // استخدام setTimeout لمنع مشاكل التوقيت
                    setTimeout(function() {
                        // استخدام location.href بدلاً من reload(true) لمنع Error Code: -2
                        window.location.href = url.toString();
                    }, 50);
                } catch (e) {
                    // في حالة الخطأ، استخدم reload عادي
                    console.warn('Error in pageshow handler, using fallback:', e);
                    pageshowRefreshHandled = true;
                    setTimeout(function() {
                        window.location.reload();
                    }, 50);
                }
            }
        });
        
        // معالجة جميع النماذج لحذف cache بعد الإرسال
        function setupFormCacheBusting() {
            document.querySelectorAll('form[method="POST"]').forEach(function(form) {
                // التحقق من عدم إضافة listener مرتين
                if (form.dataset.cacheBustingSetup === 'true') {
                    return;
                }
                form.dataset.cacheBustingSetup = 'true';
                
                form.addEventListener('submit', function(e) {
                    // حفظ flag في sessionStorage أن هناك طلب جديد
                    try {
                        sessionStorage.setItem('form_submitted_' + Date.now(), 'true');
                        sessionStorage.setItem('last_form_submit_time', Date.now().toString());
                    } catch (err) {
                        // تجاهل إذا كان sessionStorage غير متاح
                    }
                    
                    // إضافة timestamp كمعامل خفي لفرض reload من السيرفر
                    const timestamp = Date.now();
                    let hasTimestampInput = false;
                    
                    // التحقق من وجود input timestamp
                    const existingInputs = form.querySelectorAll('input[type="hidden"]');
                    for (let input of existingInputs) {
                        if (input.name === '_cache_bust' || input.name === '_t' || input.name === '_nocache') {
                            input.value = timestamp;
                            hasTimestampInput = true;
                            break;
                        }
                    }
                    
                    // إضافة input timestamp إذا لم يكن موجوداً
                    if (!hasTimestampInput) {
                        const timestampInput = document.createElement('input');
                        timestampInput.type = 'hidden';
                        timestampInput.name = '_cache_bust';
                        timestampInput.value = timestamp;
                        form.appendChild(timestampInput);
                    }
                });
            });
        }
        
        // تهيئة عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupFormCacheBusting);
        } else {
            setupFormCacheBusting();
        }
        
        // إعادة تهيئة عند إضافة نماذج ديناميكية
        if (typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length > 0) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) {
                                // التحقق من إضافة نماذج جديدة
                                if (node.tagName === 'FORM' && node.method === 'POST') {
                                    setupFormCacheBusting();
                                } else if (node.querySelectorAll) {
                                    const forms = node.querySelectorAll('form[method="POST"]');
                                    if (forms.length > 0) {
                                        setupFormCacheBusting();
                                    }
                                }
                            }
                        });
                    }
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
        
        // عند تحميل الصفحة، التحقق من وجود طلبات حديثة
        window.addEventListener('load', function() {
            try {
                const lastSubmitTime = sessionStorage.getItem('last_form_submit_time');
                if (lastSubmitTime) {
                    const timeDiff = Date.now() - parseInt(lastSubmitTime);
                    // إذا كان الطلب منذ أقل من 30 ثانية، أزل cache parameters من URL
                    if (timeDiff < 30000) {
                        const url = new URL(window.location.href);
                        let urlChanged = false;
                        
                        ['_cache_bust', '_t', '_nocache', '_r', '_refresh', '_auto_refresh'].forEach(function(param) {
                            if (url.searchParams.has(param)) {
                                url.searchParams.delete(param);
                                urlChanged = true;
                            }
                        });
                        
                        if (urlChanged) {
                            window.history.replaceState({}, '', url.toString());
                        }
                    }
                }
            } catch (e) {
                // تجاهل
            }
        });
        
        // إضافة meta tags محسّنة لـ bfcache (back/forward cache)
        // تم تعديلها للسماح بـ bfcache مع الحفاظ على تحديث البيانات
        if (!document.querySelector('meta[http-equiv="Cache-Control"]')) {
            const metaCache = document.createElement('meta');
            metaCache.httpEquiv = 'Cache-Control';
            metaCache.content = 'private, max-age=0, must-revalidate';
            document.head.insertBefore(metaCache, document.head.firstChild);
        }
        // إزالة Pragma و Expires لأنها تمنع bfcache
    })();
    </script>
    
    <!-- Unified Polling System - نظام موحد لجميع طلبات الـ Polling -->
    <script>
    (function() {
        'use strict';
        
        if (window.__unifiedPollingActive) {
            return;
        }
        window.__unifiedPollingActive = true;
        
        let lastActivity = Date.now();
        
        // نظام كشف نوع الاتصال وتحسين الأداء على بيانات الهاتف
        function detectConnectionType() {
            // استخدام Network Information API إذا كان متاحاً
            if ('connection' in navigator) {
                const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                if (conn) {
                    const effectiveType = conn.effectiveType || 'unknown';
                    const type = conn.type || 'unknown';
                    const saveData = conn.saveData || false;
                    
                    // إذا كان saveData مفعلاً أو نوع الاتصال mobile، نعتبره بيانات هاتف
                    if (saveData || type === 'cellular' || effectiveType === '2g' || effectiveType === 'slow-2g') {
                        return { isMobileData: true, effectiveType, saveData };
                    }
                }
            }
            
            // كشف بديل: إذا كان المستخدم على هاتف محمول
            const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            return { isMobileData: isMobileDevice, effectiveType: 'unknown', saveData: false };
        }
        
        const connectionInfo = detectConnectionType();
        const isMobileData = connectionInfo.isMobileData;
        
        // تحديد فترة Polling حسب نوع الاتصال
        // WiFi: 90 ثانية | بيانات الهاتف: 180 ثانية (3 دقائق) لتقليل استهلاك البيانات
        const POLLING_INTERVAL = isMobileData ? 180 * 1000 : 90 * 1000;
        const ACTIVITY_TIMEOUT = 15 * 60 * 1000; // 15 دقيقة
        
        const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click', 'keydown'];
        let unifiedPollInterval;
        let isPolling = false;
        let consecutiveFailures = 0;
        const MAX_CONSECUTIVE_FAILURES = 3;
        
        // حساب مسار API - تصديره عالمياً للاستخدام من قبل initUpdateChecker
        function getApiPath(endpoint) {
            const cleanEndpoint = String(endpoint || '').replace(/^\/+/, '');
            const currentPath = window.location.pathname || '/';
            const parts = currentPath.split('/').filter(Boolean);
            const stopSegments = new Set(['dashboard', 'modules', 'api', 'assets', 'includes']);
            const baseParts = [];
            
            for (const part of parts) {
                if (stopSegments.has(part) || part.endsWith('.php')) {
                    break;
                }
                baseParts.push(part);
            }
            
            const basePath = baseParts.length ? '/' + baseParts.join('/') : '';
            const apiPath = (basePath + '/' + cleanEndpoint).replace(/\/+/g, '/');
            return apiPath.startsWith('/') ? apiPath : '/' + apiPath;
        }
        
        // تصدير getApiPath عالمياً للاستخدام من قبل initUpdateChecker
        window.getApiPath = getApiPath;
        
        function updateActivity() {
            lastActivity = Date.now();
        }
        
        // تحديد ما يجب إضافته للطلب بناءً على الصفحة ونوع الاتصال
        function getPollingParams() {
            const params = new URLSearchParams();
            const path = window.location.pathname || '';
            
            // إضافة notifications دائماً (وظيفة أساسية - لا يتم تعطيلها)
            params.set('notifications', '1');
            if (typeof window.lastNotificationId !== 'undefined' && window.lastNotificationId) {
                params.set('last_notification_id', window.lastNotificationId);
            }
            
            // إضافة chat إذا كانت الصفحة تحتوي على chat
            // عند استخدام بيانات الهاتف، نستمر في جلب الرسائل لكن بفترة أطول
            if (document.querySelector('[data-chat-app]')) {
                params.set('chat', '1');
                if (typeof window.lastChatMessageId !== 'undefined' && window.lastChatMessageId) {
                    params.set('last_message_id', window.lastChatMessageId);
                }
                if (typeof window.currentChatId !== 'undefined' && window.currentChatId) {
                    params.set('chat_id', window.currentChatId);
                }
            }
            
            return params.toString();
        }
        
        function executeUnifiedPolling() {
            if (isPolling || document.hidden) {
                return;
            }
            
            const timeSinceActivity = Date.now() - lastActivity;
            if (timeSinceActivity > ACTIVITY_TIMEOUT) {
                return;
            }
            
            isPolling = true;
            const params = getPollingParams();
            const apiPath = getApiPath('api/unified_polling.php') + (params ? '?' + params : '');
            
            // زيادة timeout عند استخدام بيانات الهاتف لتجنب إلغاء الطلبات المبكر
            const requestTimeout = isMobileData ? 12000 : 8000; // 12 ثانية للهاتف، 8 ثواني للWiFi
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), requestTimeout);
            
            fetch(apiPath, {
                method: 'GET',
                cache: 'no-cache',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache'
                }
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    return response.json().then(data => {
                        if (data && (data.expired || (data.session && !data.session.active))) {
                            const loginUrl = getApiPath('index.php').split('?')[0];
                            const cleanUrl = loginUrl.replace(/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/g, '');
                            if (window.location.pathname !== cleanUrl.split('?')[0]) {
                                window.location.replace(cleanUrl);
                            }
                            return null;
                        }
                        throw new Error('Polling failed');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (!data) return;
                
                if (data.success) {
                    consecutiveFailures = 0;
                    lastActivity = Date.now();
                    
                    // معالجة الجلسة
                    if (data.session && !data.session.active) {
                        if (typeof handleSessionStatus === 'function') {
                            handleSessionStatus(401);
                        }
                        return;
                    }
                    
                    // معالجة وضع الصيانة
                    if (data.maintenance) {
                        if (data.maintenance.mode === 'on' && !data.maintenance.is_developer) {
                            if (typeof showMaintenanceModal === 'function') {
                                showMaintenanceModal();
                            }
                        } else {
                            if (typeof hideMaintenanceModal === 'function') {
                                hideMaintenanceModal();
                            }
                        }
                    }
                    
                    // معالجة الإشعارات
                    if (data.notifications && typeof window.handleUnifiedNotifications === 'function') {
                        window.handleUnifiedNotifications(data.notifications);
                        if (data.notifications.notifications && data.notifications.notifications.length > 0) {
                            const lastId = data.notifications.notifications[0].id;
                            if (lastId) {
                                window.lastNotificationId = lastId;
                            }
                        }
                    }
                    
                    // معالجة رسائل الدردشة
                    if (data.chat && data.chat.messages && typeof window.handleChatMessages === 'function') {
                        window.handleChatMessages(data.chat.messages);
                        if (data.chat.messages.length > 0) {
                            const lastMsg = data.chat.messages[data.chat.messages.length - 1];
                            if (lastMsg && lastMsg.id) {
                                window.lastChatMessageId = lastMsg.id;
                            }
                        }
                    }
                    
                    // معالجة update check (اختياري - للاستخدام من initUpdateChecker)
                    if (data.update_check && typeof window.handleUpdateCheck === 'function') {
                        window.handleUpdateCheck(data.update_check);
                    }
                    
                } else {
                    consecutiveFailures++;
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                if (error.name !== 'AbortError') {
                    // إذا كانت الصفحة مخفية، لا نعد الفشل
                    if (document.hidden) {
                        consecutiveFailures = Math.max(0, consecutiveFailures - 1);
                    } else {
                        consecutiveFailures++;
                    }
                    
                    if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
                        console.warn('Unified polling: Multiple failures detected');
                        const checkUrl = getApiPath('index.php').split('?')[0];
                        const checkController = new AbortController();
                        const checkTimeout = setTimeout(() => checkController.abort(), 5000);
                        
                        fetch(checkUrl, {
                            method: 'HEAD',
                            cache: 'no-cache',
                            credentials: 'same-origin',
                            signal: checkController.signal
                        })
                        .then(() => {
                            clearTimeout(checkTimeout);
                            consecutiveFailures = Math.floor(MAX_CONSECUTIVE_FAILURES / 2);
                        })
                        .catch(() => {
                            clearTimeout(checkTimeout);
                        });
                    }
                }
            })
            .finally(() => {
                isPolling = false;
            });
        }
        
        // إضافة مستمعي الأحداث للنشاط
        activityEvents.forEach(event => {
            document.addEventListener(event, updateActivity, { passive: true });
        });
        
        // بدء الـ polling الموحد
        unifiedPollInterval = setInterval(executeUnifiedPolling, POLLING_INTERVAL);
        
        // تنفيذ أول مرة بعد 5 ثواني
        setTimeout(executeUnifiedPolling, 5000);
        
        // تنظيف عند مغادرة الصفحة - استخدام pagehide بدلاً من beforeunload لإعادة تفعيل bfcache
        window.addEventListener('pagehide', () => {
            if (unifiedPollInterval) {
                clearInterval(unifiedPollInterval);
                unifiedPollInterval = null;
            }
            if (navigator.sendBeacon) {
                const params = getPollingParams();
                navigator.sendBeacon(getApiPath('api/unified_polling.php') + (params ? '?' + params : ''));
            }
        });
        
        // استخدام visibilitychange لإيقاف/استئناف polling عند إخفاء/إظهار الصفحة
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // إيقاف polling مؤقتاً عند إخفاء الصفحة
                if (unifiedPollInterval) {
                    clearInterval(unifiedPollInterval);
                    unifiedPollInterval = null;
                }
            } else {
                // عند العودة، استئناف polling فوراً
                if (!unifiedPollInterval) {
                    // تنفيذ polling فوراً أولاً
                    executeUnifiedPolling();
                    // ثم البدء بالـ interval
                    unifiedPollInterval = setInterval(executeUnifiedPolling, POLLING_INTERVAL);
                }
            }
        });
        
        // ربط مع stopAllPolling
        if (typeof stopAllPolling === 'function') {
            const originalStopAllPolling = stopAllPolling;
            window.stopAllPolling = function() {
                originalStopAllPolling();
                if (unifiedPollInterval) {
                    clearInterval(unifiedPollInterval);
                    unifiedPollInterval = null;
                }
            };
        }
        
        // Export للوصول من ملفات أخرى
        window.unifiedPolling = {
            execute: executeUnifiedPolling,
            stop: function() {
                if (unifiedPollInterval) {
                    clearInterval(unifiedPollInterval);
                    unifiedPollInterval = null;
                }
            }
        };
        
    })();
    </script>
    
    <!-- Install Banner -->
    <div class="install-banner" id="installBanner">
        <div class="install-banner-content">
            <div class="install-banner-icon">
                <i class="bi bi-download"></i>
            </div>
            <div class="install-banner-text">
                <h6 class="install-banner-title">تثبيت التطبيق</h6>
                <p class="install-banner-description">ثبت التطبيق للوصول السريع والاستخدام بدون إنترنت</p>
            </div>
            <button class="install-banner-button" id="installButton">
                <i class="bi bi-plus-circle me-1"></i>تثبيت
            </button>
        </div>
        <button type="button" class="install-banner-close" id="dismissInstallBanner" aria-label="إغلاق">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div id="pwa-modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
        <div id="pwa-modal">
            <button type="button" class="pwa-modal-close" data-modal-close>
                <i class="bi bi-x-lg"></i>
            </button>
            <iframe src="about:blank" title="Embedded content"></iframe>
        </div>
    </div>
    
    <?php
    // استخدام نفس cache version من header.php لتحسين caching
    $cacheVersion = defined('ASSETS_VERSION') ? ASSETS_VERSION : (defined('APP_VERSION') ? APP_VERSION : '1.0.0');
    ?>
    <!-- Performance: Load jQuery with defer for better performance -->
    <?php if ($isMobile): ?>
    <!-- Mobile: تحميل jQuery بعد DOMContentLoaded لتحسين الأداء -->
    <script>
        // تحميل jQuery بعد DOMContentLoaded على الموبايل (أسرع من load event)
        document.addEventListener('DOMContentLoaded', function() {
            const script = document.createElement('script');
            script.src = 'https://code.jquery.com/jquery-3.7.0.min.js';
            script.crossOrigin = 'anonymous';
            script.async = true; // استخدام async بدلاً من defer
            script.onload = function() {
                // التأكد من أن jQuery متاح عالمياً
                if (typeof window.jQuery === 'undefined') {
                    window.jQuery = typeof jQuery !== 'undefined' ? jQuery : (typeof $ !== 'undefined' ? $ : null);
                }
                if (typeof window.$ === 'undefined') {
                    window.$ = typeof $ !== 'undefined' ? $ : (typeof jQuery !== 'undefined' ? jQuery : null);
                }
                document.dispatchEvent(new CustomEvent('jqueryLoaded'));
            };
            document.head.appendChild(script);
        });
    </script>
    <?php else: ?>
    <!-- Desktop: تحميل عادي -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js" defer crossorigin="anonymous"></script>
    <script>
        // الانتظار حتى تحميل jQuery
        (function() {
            function initJQuery() {
                if (typeof jQuery === 'undefined' && typeof $ === 'undefined') {
                    setTimeout(initJQuery, 50);
                    return;
                }
                // التأكد من أن jQuery متاح عالمياً
                if (typeof window.jQuery === 'undefined') {
                    window.jQuery = typeof jQuery !== 'undefined' ? jQuery : (typeof $ !== 'undefined' ? $ : null);
                }
                if (typeof window.$ === 'undefined') {
                    window.$ = typeof $ !== 'undefined' ? $ : (typeof jQuery !== 'undefined' ? jQuery : null);
                }
            }
            window.addEventListener('load', function() {
                setTimeout(initJQuery, 100);
            });
        })();
    </script>
    <?php endif; ?>
    <!-- Performance: Load Bootstrap JS with defer -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer crossorigin="anonymous"></script>
    <!-- Custom JS -->
    <?php
    // التأكد من أن ASSETS_URL صحيح
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
    ?>
    <?php
    // كشف الموبايل لتحسين الأداء (نفس المستخدم في header.php)
    if (!isset($isMobile)) {
        $isMobile = (bool) preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
    ?>
    
    <!-- Critical JS - تحميل مباشر -->
    <!-- Performance: Load Local Storage Cache Manager early -->
    <script src="<?php echo $assetsUrl; ?>js/local-storage-cache.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- Performance: Load Lazy Loading Manager early -->
    <script src="<?php echo $assetsUrl; ?>js/lazy-loading.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- Main JS -->
    <script src="<?php echo $assetsUrl; ?>js/main.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <!-- شاشة التحميل الديناميكية التفاعلية (جميع الصفحات) -->
    <script src="<?php echo $assetsUrl; ?>js/global-loading.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/sidebar.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- Auto Refresh Navigation - إعادة تحميل تلقائي للتنقل (مدير، مندوب مبيعات، عامل إنتاج) -->
    <!-- يجب تحميل هذا السكريبت قبل ajax-navigation.js لاعتراض النقرات أولاً -->
    <script src="<?php echo $assetsUrl; ?>js/auto-refresh-navigation.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- AJAX Navigation - تحسين الأداء على الموبايل -->
    <script src="<?php echo $assetsUrl; ?>js/ajax-navigation.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- Modal Mobile Fix JS - إصلاح النماذج على الهواتف المحمولة -->
    <script src="<?php echo $assetsUrl; ?>js/modal-mobile-fix.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- البحث عن عميل في قوائم العملاء (دروب داون) -->
    <script src="<?php echo $assetsUrl; ?>js/customer-select-search.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- معالجة زر مسح الكل - بدون تنبيهات -->
    <script>
    // دالة مساعدة لحساب المسار الصحيح لـ API (مثل getApiPath في notifications.js)
    function getNotificationsApiPath() {
        const cleanEndpoint = 'api/notifications.php';
        const currentPath = window.location.pathname || '/';
        const parts = currentPath.split('/').filter(Boolean);
        const stopSegments = new Set(['dashboard', 'modules', 'api', 'assets', 'includes']);
        const baseParts = [];

        for (const part of parts) {
            if (stopSegments.has(part) || part.endsWith('.php')) {
                break;
            }
            baseParts.push(part);
        }

        const basePath = baseParts.length ? '/' + baseParts.join('/') : '';
        const apiPath = (basePath + '/' + cleanEndpoint).replace(/\/+/g, '/');

        return apiPath.startsWith('/') ? apiPath : '/' + apiPath;
    }
    
    function handleClearAllNotifications(event) {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        
        // منع إغلاق الـ dropdown
        const dropdown = document.querySelector('.notifications-dropdown');
        if (dropdown) {
            dropdown.classList.add('show');
            dropdown.setAttribute('data-bs-auto-close', 'false');
        }
        
        const form = event.target.closest('form');
        if (!form) return false;
        
        // استخدام getNotificationsApiPath بدلاً من form.action لضمان المسار الصحيح
        const apiPath = getNotificationsApiPath();
        const formData = new URLSearchParams();
        formData.append('action', 'delete_all');
        
        // إظهار loading
        const notificationsList = document.getElementById('notificationsList');
        if (notificationsList) {
            notificationsList.innerHTML = '<small class="text-muted">جاري حذف الإشعارات...</small>';
        }
        
        // إرسال الطلب مع معالجة أفضل للأخطاء
        fetch(apiPath, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData
        })
        .then(response => {
            // التحقق من حالة الاستجابة
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            // التحقق من نوع المحتوى
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('Invalid response type');
            }
            return response.json();
        })
        .then(data => {
            if (data && data.success) {
                // تحديث القائمة
                if (notificationsList) {
                    notificationsList.innerHTML = '<small class="text-muted">لا توجد إشعارات</small>';
                }
                
                // تحديث العداد
                const badge = document.getElementById('notificationBadge');
                if (badge) {
                    badge.textContent = '0';
                    badge.style.display = 'none';
                }
                
                // إعادة تحميل الإشعارات بعد تأخير بسيط
                setTimeout(() => {
                    if (typeof loadNotifications === 'function') {
                        loadNotifications();
                    } else if (typeof window.loadNotifications === 'function') {
                        window.loadNotifications();
                    }
                }, 100);
            } else {
                throw new Error(data?.error || 'Unknown error');
            }
        })
        .catch(error => {
            console.error('Error deleting notifications:', error);
            // إعادة تحميل الإشعارات في حالة الخطأ
            if (notificationsList) {
                notificationsList.innerHTML = '<small class="text-muted">خطأ في حذف الإشعارات</small>';
            }
            setTimeout(() => {
                if (typeof loadNotifications === 'function') {
                    loadNotifications();
                } else if (typeof window.loadNotifications === 'function') {
                    window.loadNotifications();
                }
            }, 500);
        });
        
        return false;
    }
    
    // إضافة event listener مباشر للزر لضمان عمله على الموبايل
    (function() {
        function setupClearAllButtonListener() {
            const clearAllBtn = document.getElementById('clearAllNotificationsBtn');
            if (!clearAllBtn) {
                // إعادة المحاولة بعد قليل إذا لم يكن الزر موجوداً
                setTimeout(setupClearAllButtonListener, 100);
                return;
            }
            
            // إزالة أي event listeners سابقة
            const newBtn = clearAllBtn.cloneNode(true);
            const form = clearAllBtn.closest('form');
            if (form) {
                form.replaceChild(newBtn, clearAllBtn);
            }
            
            // إضافة event listeners للـ click و touch
            ['click', 'touchend'].forEach(eventType => {
                newBtn.addEventListener(eventType, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    
                    // منع إغلاق الـ dropdown
                    const dropdown = document.querySelector('.notifications-dropdown');
                    if (dropdown) {
                        dropdown.classList.add('show');
                        dropdown.setAttribute('data-bs-auto-close', 'false');
                    }
                    
                    // استخدام getNotificationsApiPath بدلاً من form.action
                    const apiPath = getNotificationsApiPath();
                    const formData = new URLSearchParams();
                    formData.append('action', 'delete_all');
                    
                    // إظهار loading
                    const notificationsList = document.getElementById('notificationsList');
                    if (notificationsList) {
                        notificationsList.innerHTML = '<small class="text-muted">جاري حذف الإشعارات...</small>';
                    }
                    
                    // إرسال الطلب
                    fetch(apiPath, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        const contentType = response.headers.get('content-type') || '';
                        if (!contentType.includes('application/json')) {
                            throw new Error('Invalid response type');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data && data.success) {
                            if (notificationsList) {
                                notificationsList.innerHTML = '<small class="text-muted">لا توجد إشعارات</small>';
                            }
                            
                            const badge = document.getElementById('notificationBadge');
                            if (badge) {
                                badge.textContent = '0';
                                badge.style.display = 'none';
                            }
                            
                            setTimeout(() => {
                                if (typeof loadNotifications === 'function') {
                                    loadNotifications();
                                } else if (typeof window.loadNotifications === 'function') {
                                    window.loadNotifications();
                                }
                            }, 100);
                        } else {
                            throw new Error(data?.error || 'Unknown error');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting notifications:', error);
                        if (notificationsList) {
                            notificationsList.innerHTML = '<small class="text-muted">خطأ في حذف الإشعارات</small>';
                        }
                        setTimeout(() => {
                            if (typeof loadNotifications === 'function') {
                                loadNotifications();
                            } else if (typeof window.loadNotifications === 'function') {
                                window.loadNotifications();
                            }
                        }, 500);
                    });
                }, { capture: true, passive: false });
            });
        }
        
        // إعداد الزر عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupClearAllButtonListener);
        } else {
            setupClearAllButtonListener();
        }
        
        // إعادة إعداد الزر بعد تحديث قائمة الإشعارات
        const notificationsList = document.getElementById('notificationsList');
        if (notificationsList) {
            const observer = new MutationObserver(function() {
                setTimeout(setupClearAllButtonListener, 50);
            });
            observer.observe(notificationsList, {
                childList: true,
                subtree: true
            });
        }
    })();
    </script>
    
    <!-- Medium Priority JS - تحميل مشروط -->
    <?php if ($isMobile): ?>
    <!-- Mobile: تحميل متأخر للـ JS غير الحرجة -->
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                const scripts = [
                    '<?php echo $assetsUrl; ?>js/fix-modal-interaction.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/notifications.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/image-lazy-loading.js?v=<?php echo $cacheVersion; ?>'
                ];
                
                scripts.forEach(function(src) {
                    const script = document.createElement('script');
                    script.src = src;
                    script.defer = true;
                    document.body.appendChild(script);
                });
            }, 800); // بعد 800ms من تحميل الصفحة
        });
    </script>
    <?php else: ?>
    <!-- Desktop: تحميل مباشر -->
    <script src="<?php echo $assetsUrl; ?>js/fix-modal-interaction.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/notifications.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/image-lazy-loading.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <?php endif; ?>
    
    <!-- Low Priority JS - تحميل متأخر على الموبايل -->
    <?php if (!$isMobile): ?>
    <!-- Desktop: تحميل جميع الملفات -->
    <script src="<?php echo $assetsUrl; ?>js/tables.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/dark-mode.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/pwa-install.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/modal-link-interceptor.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/keyboard-shortcuts-global.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <?php else: ?>
    <!-- Mobile: تحميل متأخر للـ JS غير الحرجة -->
    <script>
        // تحميل JS غير الحرجة بعد تحميل الصفحة على الموبايل
        window.addEventListener('load', function() {
            setTimeout(function() {
                const scripts = [
                    '<?php echo $assetsUrl; ?>js/tables.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/dark-mode.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/pwa-install.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/modal-link-interceptor.js?v=<?php echo $cacheVersion; ?>'
                ];
                
                scripts.forEach(function(src) {
                    const script = document.createElement('script');
                    script.src = src;
                    script.defer = true;
                    document.body.appendChild(script);
                });
            }, 1000); // بعد ثانية من تحميل الصفحة
        });
    </script>
    <?php endif; ?>
    <script>
    // التحقق من تحميل ملفات JavaScript بشكل صحيح
    (function() {
        const scripts = document.querySelectorAll('script[src*=".js"]');
        scripts.forEach(function(script) {
            script.addEventListener('error', function() {
                console.error('Failed to load script:', script.src);
                // محاولة تحميل من مسار بديل
                const src = script.getAttribute('src');
                if (src && !src.startsWith('http')) {
                    const basePath = '<?php echo getBasePath(); ?>';
                    const fallbackSrc = (basePath ? basePath : '') + src.replace(/^\/[^\/]+/, '/assets');
                    console.warn('Trying fallback path:', fallbackSrc);
                }
            });
        });
    })();
    </script>
    
    <?php if (isset($extraScripts)): ?>
        <?php foreach ($extraScripts as $script): ?>
            <script src="<?php echo $script; ?>" defer></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <script>
        // تنظيف console.log في production (Best Practices)
        (function() {
            const isProduction = window.location.hostname !== 'localhost' && 
                                 window.location.hostname !== '127.0.0.1' && 
                                 !window.location.hostname.includes('.local');
            
            if (isProduction && typeof console !== 'undefined') {
                const noop = function() {};
                // الاحتفاظ بـ console.error للخطوط الحقيقية
                console.log = noop;
                console.debug = noop;
                console.info = noop;
                // console.warn و console.error تبقى كما هي للأخطاء المهمة
            }
        })();
        
        // تهيئة النظام
        document.addEventListener('DOMContentLoaded', function() {
            // تم دمج background tasks في unified polling system
            // لا حاجة لـ background tasks polling منفصل بعد الآن
            
            // إغلاق القائمة المنسدلة عند النقر على أي رابط
            const mainMenuDropdown = document.getElementById('mainMenuDropdown');
            const mainMenuDropdownMenu = document.querySelector('.main-menu-dropdown');
            
            if (mainMenuDropdown && mainMenuDropdownMenu) {
                // إغلاق القائمة عند النقر على أي رابط
                const menuLinks = mainMenuDropdownMenu.querySelectorAll('.dropdown-item');
                menuLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        // إغلاق القائمة باستخدام Bootstrap
                        const dropdownInstance = bootstrap.Dropdown.getInstance(mainMenuDropdown);
                        if (dropdownInstance) {
                            dropdownInstance.hide();
                        }
                    });
                });
                
                // إغلاق القائمة عند النقر خارجها
                document.addEventListener('click', function(event) {
                    if (!mainMenuDropdown.contains(event.target) && !mainMenuDropdownMenu.contains(event.target)) {
                        const dropdownInstance = bootstrap.Dropdown.getInstance(mainMenuDropdown);
                        if (dropdownInstance && mainMenuDropdownMenu.classList.contains('show')) {
                            dropdownInstance.hide();
                        }
                    }
                });
            }
            
            // إعداد معلمات الإشعارات العالمية (محسّن للأداء)
            window.NOTIFICATION_POLL_INTERVAL = <?php echo (int) NOTIFICATION_POLL_INTERVAL; ?>;
            window.NOTIFICATION_AUTO_REFRESH_ENABLED = <?php echo NOTIFICATION_AUTO_REFRESH_ENABLED ? 'true' : 'false'; ?>;
            window.NOTIFICATION_POLL_INTERVAL = Number(window.NOTIFICATION_POLL_INTERVAL) || 30000; // 30 ثانية افتراضياً
            if (typeof loadNotifications === 'function') {
                if (!window.__notificationInitialLoadDone) {
                    loadNotifications();
                    window.__notificationInitialLoadDone = true;
                }
            }
            
            // تهيئة نظام التحقق من التحديثات
            initUpdateChecker();
        });
        
        // Register Service Worker (يتم تسجيله في header.php)
        
        // Offline Detection - التحقق من عدم إعادة تعريف المتغير
        if (typeof window.offlineIndicatorHandlersInitialized === 'undefined') {
            window.offlineIndicatorHandlersInitialized = false;
        }
        
        if (!window.offlineIndicatorHandlersInitialized) {
            const offlineIndicator = document.getElementById('offlineIndicator');
            if (offlineIndicator) {
                // إزالة event listeners القديمة إذا كانت موجودة
                const onlineHandler = () => {
                    offlineIndicator.classList.remove('show');
                };
                const offlineHandler = () => {
                    offlineIndicator.classList.add('show');
                };
                
                window.addEventListener('online', onlineHandler);
                window.addEventListener('offline', offlineHandler);
                
                window.offlineIndicatorHandlersInitialized = true;
            }
        }
        
        /**
         * نظام التحقق من التحديثات
         */
        function initUpdateChecker() {
            const STORAGE_KEY = 'app_last_version';
            const VERSION_STORAGE_KEY = 'app_display_version';
            const LAST_CHECK_KEY = 'app_last_update_check';
            const CHECK_INTERVAL = 30 * 60 * 1000; // كل 30 دقيقة
            const MIN_MANUAL_INTERVAL = 5 * 60 * 1000; // الحد الأدنى بين التحقق اليدوي
            let updateCheckInterval = null;
            let updateCheckTimeout = null;
            let isChecking = false;
            
            /**
             * التحقق من وجود تحديثات
             */
            async function checkForUpdates() {
                if (isChecking) return;
                isChecking = true;
                
                try {
                    // استخدام unified_polling بدلاً من check_update.php
                    const apiPath = (typeof window.getApiPath === 'function' 
                        ? window.getApiPath('api/unified_polling.php')
                        : '/api/unified_polling.php');
                    const response = await fetch(apiPath + '?t=' + Date.now(), {
                        method: 'GET',
                        cache: 'no-cache',
                        credentials: 'same-origin'
                    });
                    
                    if (!response.ok) {
                        throw new Error('Failed to check for updates');
                    }
                    
                    const data = await response.json();
                    
                    if (data.success && data.update_check) {
                        const updateData = data.update_check;
                        const currentHash = updateData.content_hash || updateData.version || updateData.last_modified;
                        const storedHash = localStorage.getItem(STORAGE_KEY);
                        const storedDisplay = localStorage.getItem(VERSION_STORAGE_KEY) || '';
                        const serverVersion = (updateData.version || '').toString().trim();
                        let displayVersion = storedDisplay || serverVersion || 'جديد';

                        if (storedHash && storedHash !== currentHash) {
                            // التحقق من عدم إظهار نفس الإشعار مؤخراً (خلال آخر ساعتين)
                            const lastNotificationKey = 'last_update_notification_' + currentHash;
                            const lastNotification = localStorage.getItem(lastNotificationKey);
                            const now = Date.now();
                            const twoHours = 2 * 60 * 60 * 1000; // ساعتين
                            
                            if (!lastNotification || (now - parseInt(lastNotification)) > twoHours) {
                                displayVersion = serverVersion || 'جديد';
                                showUpdateAvailableNotification(displayVersion);
                                localStorage.setItem(lastNotificationKey, now.toString());
                            }
                        }

                        localStorage.setItem(STORAGE_KEY, currentHash);
                        localStorage.setItem(VERSION_STORAGE_KEY, displayVersion);
                    }
                } catch (error) {
                    console.log('Update check error:', error);
                } finally {
                    try {
                        localStorage.setItem(LAST_CHECK_KEY, Date.now().toString());
                    } catch (storageError) {
                        console.log('Update check storage error:', storageError);
                    }
                    isChecking = false;
                }
            }
            
            /**
             * إظهار إشعار التحديث
             */
            function showUpdateAvailableNotification(version) {
                // التحقق من عدم وجود إشعار موجود بالفعل
                if (document.getElementById('updateNotification')) {
                    return;
                }
                
                const notification = document.createElement('div');
                notification.id = 'updateNotification';
                notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
                notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);';
                
                const displayVersion = (version || '').toString().trim() || 'جديد';
                localStorage.setItem(VERSION_STORAGE_KEY, displayVersion);
                
                notification.innerHTML = `
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-arrow-clockwise me-2 fs-5"></i>
                                <strong>تحديث متاح!</strong>
                            </div>
                            <p class="mb-2 small">يتوفر تحديث جديد للموقع. يرجى تحديث الصفحة للحصول على أحدث الميزات.</p>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-primary" onclick="refreshPage()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>تحديث الآن
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="dismissUpdateNotification()">
                                    لاحقاً
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn-close ms-2" onclick="dismissUpdateNotification()" aria-label="إغلاق"></button>
                    </div>
                `;
                
                document.body.appendChild(notification);
                
                // إضافة دوال عامة
                window.refreshPage = function() {
                    // إزالة cache
                    if ('caches' in window) {
                        caches.keys().then(names => {
                            names.forEach(name => {
                                caches.delete(name);
                            });
                        });
                    }
                    // تحديث الصفحة
                    window.location.reload(true);
                };
                
                window.dismissUpdateNotification = function() {
                    const notif = document.getElementById('updateNotification');
                    if (notif) {
                        notif.classList.remove('show');
                        setTimeout(() => notif.remove(), 300);
                    }
                };
                
                notification.dataset.version = version;
                
                // إزالة الإشعار تلقائياً بعد 60 ثانية
                setTimeout(() => {
                    window.dismissUpdateNotification();
                }, 60000);
            }
            
            function getLastCheckTimestamp() {
                try {
                    const raw = localStorage.getItem(LAST_CHECK_KEY);
                    const parsed = raw ? parseInt(raw, 10) : 0;
                    return Number.isFinite(parsed) ? parsed : 0;
                } catch (error) {
                    return 0;
                }
            }

            function shouldCheckNow(minInterval = CHECK_INTERVAL) {
                const lastCheck = getLastCheckTimestamp();
                if (!lastCheck) {
                    return true;
                }
                return (Date.now() - lastCheck) >= minInterval;
            }

            function scheduleBackgroundChecks() {
                if (updateCheckTimeout) {
                    clearTimeout(updateCheckTimeout);
                    updateCheckTimeout = null;
                }
                if (updateCheckInterval) {
                    clearInterval(updateCheckInterval);
                    updateCheckInterval = null;
                }

                if (shouldCheckNow()) {
                    checkForUpdates();
                    updateCheckInterval = setInterval(checkForUpdates, CHECK_INTERVAL);
                } else {
                    const lastCheck = getLastCheckTimestamp();
                    const elapsed = Date.now() - lastCheck;
                    const remaining = Math.max(CHECK_INTERVAL - elapsed, MIN_MANUAL_INTERVAL);
                    updateCheckTimeout = setTimeout(function() {
                        checkForUpdates();
                        updateCheckInterval = setInterval(checkForUpdates, CHECK_INTERVAL);
                    }, remaining);
                }
            }

            scheduleBackgroundChecks();
            
            // التحقق عند إعادة التركيز على النافذة
            window.addEventListener('focus', function() {
                if (!isChecking && shouldCheckNow(MIN_MANUAL_INTERVAL)) {
                    checkForUpdates();
                }
            });
            
            // التحقق عند الاتصال بالإنترنت
            window.addEventListener('online', function() {
                if (!isChecking && shouldCheckNow(MIN_MANUAL_INTERVAL)) {
                    setTimeout(checkForUpdates, 2000);
                }
            });
            
            // تنظيف عند إغلاق الصفحة - استخدام pagehide لإعادة تفعيل bfcache
            window.addEventListener('pagehide', function() {
                if (updateCheckInterval) {
                    clearInterval(updateCheckInterval);
                    updateCheckInterval = null;
                }
                if (updateCheckTimeout) {
                    clearTimeout(updateCheckTimeout);
                    updateCheckTimeout = null;
                }
            });
        }
    </script>
        
    <!-- 🚀 Performance: Prefetch and Navigation Optimization -->
    <script>
        (function() {
            'use strict';
            
            // تحسين التنقل: إضافة prefetch للروابط الشائعة عند hover
            // محدود للروابط المهمة فقط لتجنب الطلبات الكثيرة
            function addPrefetchOnHover() {
                // فقط الروابط المهمة (روابط التنقل الرئيسية)
                const importantSelectors = [
                    '.navbar a[href]',
                    '.nav-link[href]',
                    '.nav-item a[href]',
                    '.sidebar a[href]',
                    '[role="navigation"] a[href]'
                ];
                
                let importantLinks = [];
                importantSelectors.forEach(function(selector) {
                    const links = document.querySelectorAll(selector);
                    importantLinks = importantLinks.concat(Array.from(links));
                });
                
                // إزالة التكرارات
                importantLinks = Array.from(new Set(importantLinks));
                
                // تحديد عدد max للروابط التي يمكن عمل prefetch لها عند hover
                let hoverPrefetchCount = 0;
                const MAX_HOVER_PREFETCH = 5;
                
                importantLinks.forEach(function(link) {
                    // فقط للروابط الداخلية
                    if (link.hostname === window.location.hostname || !link.hostname) {
                        let prefetchLink = null;
                        let hoverTimeout = null;
                        
                        // عند hover: prefetch بعد 200ms (زيادة من 100ms لتقليل الطلبات)
                        link.addEventListener('mouseenter', function() {
                            // التحقق من الحد الأقصى
                            if (hoverPrefetchCount >= MAX_HOVER_PREFETCH) {
                                return;
                            }
                            
                            hoverTimeout = setTimeout(function() {
                                const href = link.getAttribute('href');
                                
                                // التحقق من أن الرابط ليس logout أو API أو صفحة ديناميكية
                                // تجنب prefetch للصفحات مع معامل page= لأنها كبيرة وتستغرق وقتاً طويلاً
                                const hasPageParam = href && (href.includes('?page=') || href.includes('&page='));
                                
                                if (href && 
                                    !href.includes('#') && 
                                    !href.includes('logout') &&
                                    !href.includes('api/') &&
                                    !hasPageParam && // تجنب prefetch للصفحات مع معامل page=
                                    !document.querySelector('link[rel="prefetch"][href="' + href + '"]')) {
                                    
                                    prefetchLink = document.createElement('link');
                                    prefetchLink.rel = 'prefetch';
                                    prefetchLink.href = href;
                                    document.head.appendChild(prefetchLink);
                                    
                                    hoverPrefetchCount++;
                                }
                            }, 200);
                        });
                        
                        // إلغاء prefetch إذا تم إلغاء hover
                        link.addEventListener('mouseleave', function() {
                            if (hoverTimeout) {
                                clearTimeout(hoverTimeout);
                                hoverTimeout = null;
                            }
                        });
                        
                        // عند click: إضافة preload فوري (محدود)
                        link.addEventListener('click', function(e) {
                            const href = link.getAttribute('href');
                            if (href && 
                                !href.includes('#') && 
                                !href.includes('logout') &&
                                !href.includes('api/') &&
                                !link.hasAttribute('data-no-splash')) {
                                // إضافة preload للصفحة التالية
                                const preloadLink = document.createElement('link');
                                preloadLink.rel = 'preload';
                                preloadLink.as = 'document';
                                preloadLink.href = href;
                                document.head.appendChild(preloadLink);
                            }
                        }, { once: true });
                    }
                });
            }
            
            // تحسين: استخدام Intersection Observer لتحميل الصفحات مسبقاً عند اقترابها من viewport
            // محدود للروابط المهمة فقط لتجنب طلبات كثيرة
            function addIntersectionPrefetch() {
                if (!('IntersectionObserver' in window)) {
                    return;
                }
                
                // فقط الروابط المهمة (روابط التنقل الرئيسية، وليس كل الروابط)
                const importantSelectors = [
                    '.navbar a[href]',
                    '.nav-link[href]',
                    '.nav-item a[href]',
                    '.sidebar a[href]',
                    '[role="navigation"] a[href]'
                ];
                
                let importantLinks = [];
                importantSelectors.forEach(function(selector) {
                    const links = document.querySelectorAll(selector);
                    importantLinks = importantLinks.concat(Array.from(links));
                });
                
                // إزالة التكرارات
                importantLinks = Array.from(new Set(importantLinks));
                
                // تحديد عدد max للروابط التي يمكن عمل prefetch لها (لتجنب الطلبات الكثيرة)
                const MAX_PREFETCH_LINKS = 10;
                
                const observer = new IntersectionObserver(function(entries) {
                    let prefetchedCount = 0;
                    
                    entries.forEach(function(entry) {
                        if (prefetchedCount >= MAX_PREFETCH_LINKS) {
                            return; // توقف عند الوصول للحد الأقصى
                        }
                        
                        if (entry.isIntersecting) {
                            const link = entry.target;
                            const href = link.getAttribute('href');
                            
                            // فقط للروابط الداخلية والمهمة
                            // تجنب prefetch للصفحات الديناميكية الكبيرة (مع معاملات page=)
                            const urlObj = new URL(href, window.location.origin);
                            const hasPageParam = urlObj.searchParams.has('page');
                            
                            if (href && 
                                (link.hostname === window.location.hostname || !link.hostname) && 
                                !href.includes('#') &&
                                !href.includes('logout') &&
                                !href.includes('api/') &&
                                !hasPageParam && // تجنب prefetch للصفحات مع معامل page=
                                !document.querySelector('link[rel="prefetch"][href="' + href + '"]')) {
                                
                                // تحقق من أن الرابط ليس صفحة حالية
                                const currentPath = window.location.pathname;
                                const linkPath = new URL(href, window.location.origin).pathname;
                                if (linkPath === currentPath) {
                                    observer.unobserve(link);
                                    return;
                                }
                                
                                const prefetchLink = document.createElement('link');
                                prefetchLink.rel = 'prefetch';
                                prefetchLink.href = href;
                                document.head.appendChild(prefetchLink);
                                
                                prefetchedCount++;
                                observer.unobserve(link);
                            }
                        }
                    });
                }, {
                    rootMargin: '150px' // تقليل من 200px إلى 150px
                });
                
                // مراقبة فقط الروابط المهمة
                importantLinks.forEach(function(link) {
                    if (link.hostname === window.location.hostname || !link.hostname) {
                        observer.observe(link);
                    }
                });
            }
            
            // تحسين: استخدام requestIdleCallback لتحميل الصفحات المهمة
            // تم تقييد prefetch للصفحات الديناميكية (مع معاملات) لتجنب الطلبات الكثيرة
            function prefetchImportantPages() {
                if (!('requestIdleCallback' in window)) {
                    return;
                }
                
                requestIdleCallback(function() {
                    // الحصول على base URL من الصفحة الحالية
                    let baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
                    const currentPath = window.location.pathname;
                    
                    // إزالة 'dashboard/' من baseUrl إذا كان موجوداً لمنع تكرار dashboard/dashboard
                    if (baseUrl.endsWith('/dashboard/')) {
                        baseUrl = baseUrl.replace(/\/dashboard\/$/, '/');
                    }
                    
                    let dashboardUrl = baseUrl;
                    
                    // تحديد dashboard URL بناءً على الصفحة الحالية
                    if (currentPath.includes('manager.php')) {
                        dashboardUrl = baseUrl + 'dashboard/manager.php';
                    } else if (currentPath.includes('sales.php')) {
                        dashboardUrl = baseUrl + 'dashboard/sales.php';
                    } else if (currentPath.includes('accountant.php')) {
                        dashboardUrl = baseUrl + 'dashboard/accountant.php';
                    } else if (currentPath.includes('production.php')) {
                        dashboardUrl = baseUrl + 'dashboard/production.php';
                    }
                    
                    // فقط prefetch للصفحة الرئيسية (بدون معاملات) لتجنب الطلبات الكثيرة
                    // الصفحات الديناميكية (مع معاملات page=) كبيرة وتستغرق وقتاً طويلاً
                    // يتم تحميلها عند hover بدلاً من prefetch تلقائي
                    const importantPages = [
                        dashboardUrl
                    ];
                    
                    importantPages.forEach(function(url) {
                        // تحقق من أن الرابط ليس الصفحة الحالية
                        const currentUrl = window.location.pathname + (window.location.search || '');
                        const prefetchUrl = new URL(url, window.location.origin);
                        const prefetchPath = prefetchUrl.pathname + (prefetchUrl.search || '');
                        
                        if (currentUrl === prefetchPath) {
                            return; // تجاهل الصفحة الحالية
                        }
                        
                        if (!document.querySelector('link[rel="prefetch"][href="' + url + '"]')) {
                            const prefetchLink = document.createElement('link');
                            prefetchLink.rel = 'prefetch';
                            prefetchLink.href = url;
                            document.head.appendChild(prefetchLink);
                        }
                    });
                }, { timeout: 3000 }); // زيادة timeout لتقليل الطلبات الفورية
            }
            
            // تشغيل التحسينات بعد تحميل الصفحة
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    addPrefetchOnHover();
                    addIntersectionPrefetch();
                    prefetchImportantPages();
                });
            } else {
                addPrefetchOnHover();
                addIntersectionPrefetch();
                prefetchImportantPages();
            }
        })();
    </script>
    
    <?php if (isset($currentUser) && ($currentUser['role'] ?? '') === 'manager'): ?>
    <script>
    /**
     * تحديث عداد الموافقات المعلقة للمديرين
     */
    (function() {
        // التحقق من أن المستخدم مدير قبل تشغيل الكود
        const currentUserRole = '<?php echo $currentUser['role'] ?? ''; ?>';
        if (currentUserRole !== 'manager') {
            // المستخدم ليس مدير - لا نحتاج لتحديث العداد
            return;
        }
        
        async function updateApprovalBadge() {
            try {
                const badge = document.getElementById('approvalBadge');
                if (!badge) {
                    return;
                }
                
                const basePath = '<?php echo getBasePath(); ?>';
                const apiPath = basePath + '/api/approvals.php';
                const response = await fetch(apiPath, {
                    credentials: 'same-origin',
                    cache: 'no-cache',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                // التعامل مع 403 (Forbidden) بشكل صحيح - هذا ليس خطأ في الجلسة
                if (response.status === 403) {
                    // المستخدم ليس مدير - إخفاء العداد
                    if (badge) {
                        badge.style.display = 'none';
                    }
                    return;
                }
                
                if (!response.ok) {
                    // تجاهل الأخطاء الأخرى (401, 500, etc.) - لا نريد إزعاج المستخدم
                    return;
                }
                
                // التحقق من content-type قبل parse JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    console.warn('updateApprovalBadge: Expected JSON but got', contentType);
                    return;
                }
                
                const text = await response.text();
                if (!text || text.trim().startsWith('<')) {
                    console.warn('updateApprovalBadge: Received HTML instead of JSON');
                    return;
                }
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.warn('updateApprovalBadge: Failed to parse JSON:', parseError);
                    return;
                }
                
                if (data && data.success && typeof data.count === 'number') {
                    const count = Math.max(0, parseInt(data.count, 10));
                    if (badge) {
                        badge.textContent = count.toString();
                        if (badge.style) {
                            if (count > 0) {
                                badge.style.display = 'inline-block';
                                badge.classList.add('badge-danger', 'bg-danger');
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    }
                }
            } catch (error) {
                // تجاهل الأخطاء بصمت لتجنب إزعاج المستخدم
                if (error.name !== 'SyntaxError') {
                    console.error('Error updating approval badge:', error);
                }
            }
        }
        
        // تحديث العداد عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                updateApprovalBadge();
                // تحديث العداد كل 2 دقيقة (120 ثانية) لتقليل الاستهلاك
                setInterval(function() {
                    if (!document.hidden) {
                        updateApprovalBadge();
                    }
                }, 120000);
            });
        } else {
            updateApprovalBadge();
            // تحديث العداد كل 2 دقيقة (120 ثانية) لتقليل الاستهلاك
            setInterval(function() {
                if (!document.hidden) {
                    updateApprovalBadge();
                }
            }, 120000);
        }
        
        // تحديث العداد عند استلام حدث
        document.addEventListener('approvalUpdated', function() {
            setTimeout(updateApprovalBadge, 1000);
        });
    })();
    </script>
    <?php endif; ?>
    
    <!-- Error Handler: منع عرض ERR_FAILED وإعادة التوجيه تلقائياً -->
    <script>
    (function() {
        'use strict';
        
        // منع التكرار
        if (window.__errorHandlerActive) {
            return;
        }
        window.__errorHandlerActive = true;
        
        // متغير لتتبع حالة التنقل
        let isNavigating = false;
        let navigationStartTime = 0;
        const NAVIGATION_TIMEOUT = 10000; // 10 ثوانٍ
        
        // تتبع النقرات على روابط الشريط الجانبي
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a');
            if (link && link.href && !link.href.includes('#') && !link.href.includes('javascript:')) {
                // التحقق من أن الرابط من نفس النطاق
                try {
                    const linkUrl = new URL(link.href, window.location.origin);
                    const currentUrl = new URL(window.location.href);
                    
                    if (linkUrl.origin === currentUrl.origin && 
                        !linkUrl.pathname.includes('/api/') &&
                        (link.classList.contains('nav-link') || link.closest('.homeline-sidebar'))) {
                        // هذا رابط من الشريط الجانبي - تعيين flag التنقل
                        isNavigating = true;
                        navigationStartTime = Date.now();
                        
                        // إزالة flag بعد timeout
                        setTimeout(function() {
                            if (Date.now() - navigationStartTime >= NAVIGATION_TIMEOUT) {
                                isNavigating = false;
                            }
                        }, NAVIGATION_TIMEOUT);
                    }
                } catch (urlError) {
                    // تجاهل أخطاء URL parsing
                }
            }
        }, true);
        
        // إزالة flag عند اكتمال تحميل الصفحة
        window.addEventListener('load', function() {
            setTimeout(function() {
                isNavigating = false;
            }, 2000); // إزالة flag بعد ثانيتين من تحميل الصفحة
        });
        
        // تتبع التنقل عبر أزرار المتصفح (back/forward)
        window.addEventListener('popstate', function() {
            isNavigating = true;
            navigationStartTime = Date.now();
            setTimeout(function() {
                if (Date.now() - navigationStartTime >= NAVIGATION_TIMEOUT) {
                    isNavigating = false;
                }
            }, NAVIGATION_TIMEOUT);
        });
        
        // تتبع تغيير URL (للتنقل البرمجي)
        let urlCheckInterval = setInterval(function() {
            const currentUrl = window.location.href;
            if (window.__lastCheckedUrl && window.__lastCheckedUrl !== currentUrl) {
                // URL تغير - قد يكون تنقل
                isNavigating = true;
                navigationStartTime = Date.now();
                setTimeout(function() {
                    if (Date.now() - navigationStartTime >= NAVIGATION_TIMEOUT) {
                        isNavigating = false;
                    }
                }, NAVIGATION_TIMEOUT);
            }
            window.__lastCheckedUrl = currentUrl;
        }, 1000);
        
        // تنظيف عند إغلاق الصفحة - استخدام pagehide لإعادة تفعيل bfcache
        window.addEventListener('pagehide', function() {
            if (urlCheckInterval) {
                clearInterval(urlCheckInterval);
            }
        });
        
        // حساب مسار صفحة تسجيل الدخول
        function getLoginUrl() {
            const currentPath = window.location.pathname || '/';
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
            const basePath = pathParts.length ? '/' + pathParts[0] : '';
            return basePath ? basePath + '/index.php' : '/index.php';
        }
        
        // إعادة التوجيه إلى صفحة تسجيل الدخول
        function redirectToLogin() {
            const loginUrl = getLoginUrl();
            // استخدام replace بدلاً من href لمنع إضافة صفحة إلى history
            window.location.replace(loginUrl);
        }
        
        // تم تعطيل فحص حالة الصفحة - checkPageStatus معطلة
        // التحقق من حالة الصفحة
        // function checkPageStatus() {
        //     // إذا كان التنقل قيد التقدم، لا نتحقق من حالة الصفحة
        //     if (isNavigating) {
        //         return;
        //     }
        //     
        //     // التحقق من أن الصفحة تم تحميلها بشكل صحيح
        //     if (document.readyState === 'complete') {
        //         // استثناء صفحات معينة من التحقق (مثل tasks.php التي قد تستغرق وقتاً في التحميل)
        //         const currentUrl = window.location.href || '';
        //         const currentPath = window.location.pathname || '';
        //         
        //         // قائمة الصفحات المستثناة من التحقق التلقائي
        //         const excludedPages = [
        //             'tasks.php',
        //             'page=tasks',
        //             'production.php?page=tasks',
        //             'manager.php?page=tasks',
        //             'index.php',
        //             'login'
        //         ];
        //         
        //         // إذا كانت الصفحة الحالية في قائمة الاستثناءات، لا نتحقق منها
        //         const isExcluded = excludedPages.some(page => 
        //             currentUrl.includes(page) || currentPath.includes(page)
        //         );
        //         
        //         if (isExcluded) {
        //             return; // لا نتحقق من هذه الصفحة
        //         }
        //         
        //         // التحقق من وجود محتوى أساسي في الصفحة
        //         const mainContent = document.getElementById('main-content') || document.querySelector('main') || document.body;
        //         
        //         // تحسين المنطق: التحقق من وجود محتوى فعلي وليس فقط عدد العناصر
        //         const hasContent = mainContent && (
        //             mainContent.children.length > 0 || 
        //             mainContent.innerHTML.trim().length > 500 || // زيادة الحد الأدنى من 100 إلى 500
        //             document.querySelector('.container-fluid') ||
        //             document.querySelector('.card') ||
        //             document.querySelector('table') ||
        //             document.querySelector('form')
        //         );
        //         
        //         if (!hasContent && document.body.innerHTML.trim().length < 500) {
        //             // الصفحة فارغة أو لم يتم تحميلها - إعادة التوجيه
        //             // لكن فقط إذا لم يكن التنقل قيد التقدم
        //             if (!isNavigating) {
        //                 console.warn('Page appears empty or failed to load - redirecting to login');
        //                 redirectToLogin();
        //             }
        //             return;
        //         }
        //     }
        // }
        
        // معالجة أخطاء التحميل
        window.addEventListener('error', function(event) {
            // التحقق من أخطاء الشبكة أو التحميل
            if (event.target && (event.target.tagName === 'SCRIPT' || event.target.tagName === 'LINK')) {
                const src = event.target.src || event.target.href || '';
                // إذا كان الخطأ في تحميل ملف مهم (مثل main.js أو header)
                if (src.includes('.js') || src.includes('.css')) {
                    console.warn('Failed to load resource:', src);
                    // لا نعيد التوجيه فوراً - قد يكون خطأ مؤقت
                }
            }
        }, true);
        
        // معالجة أخطاء Promise غير المعالجة
        window.addEventListener('unhandledrejection', function(event) {
            const error = event.reason;
            if (error && typeof error === 'object') {
                const errorMessage = error.message || error.toString() || '';
                // التحقق من أخطاء الشبكة أو ERR_FAILED
                if (errorMessage.includes('ERR_FAILED') || 
                    errorMessage.includes('Failed to fetch') || 
                    errorMessage.includes('NetworkError') ||
                    errorMessage.includes('Load failed')) {
                    console.warn('Network error detected:', errorMessage);
                    
                    // استثناء صفحات tasks من إعادة التوجيه التلقائية
                    const currentUrl = window.location.href || '';
                    const isTasksPage = currentUrl.includes('tasks.php') || 
                                       currentUrl.includes('page=tasks') ||
                                       currentUrl.includes('production.php?page=tasks') ||
                                       currentUrl.includes('manager.php?page=tasks');
                    
                    if (!isTasksPage) {
                        // إعادة التوجيه بعد تأخير قصير (فقط للصفحات غير tasks)
                        setTimeout(redirectToLogin, 1000);
                    } else {
                        console.warn('Tasks page detected - skipping auto-redirect');
                    }
                }
            }
        });
        
        // التحقق من حالة الاتصال
        window.addEventListener('online', function() {
            // تم تعطيل فحص حالة الصفحة - checkPageStatus معطلة
            // setTimeout(checkPageStatus, 5000);
        });
        
        window.addEventListener('offline', function() {
            // عند انقطاع الاتصال، لا نعيد التوجيه فوراً
            // سننتظر حتى يعود الاتصال
        });
        
        // تم تعطيل فحص حالة الصفحة - checkPageStatus معطلة
        // التحقق من حالة الصفحة بعد التحميل
        // زيادة التأخير لمنح الصفحات وقتاً أطول للتحميل (خاصة tasks.php)
        // if (document.readyState === 'loading') {
        //     document.addEventListener('DOMContentLoaded', function() {
        //         // تأخير أطول (8 ثوانٍ) لمنح الصفحات وقتاً كافياً للتحميل بعد التنقل
        //         setTimeout(checkPageStatus, 8000);
        //     });
        // } else {
        //     setTimeout(checkPageStatus, 8000);
        // }
        
        // تم تعطيل فحص حالة الصفحة - checkPageStatus معطلة
        // التحقق من حالة الصفحة بعد تحميلها بالكامل
        // window.addEventListener('load', function() {
        //     // تأخير أطول (5 ثوانٍ) لمنح الصفحات وقتاً كافياً للتحميل بعد التنقل
        //     setTimeout(checkPageStatus, 5000);
        // });
        
        // معالجة أخطاء fetch (للطلبات AJAX)
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            return originalFetch.apply(this, args)
                .catch(function(error) {
                    // التحقق من أخطاء ERR_FAILED
                    if (error && (error.message && (
                        error.message.includes('ERR_FAILED') ||
                        error.message.includes('Failed to fetch') ||
                        error.message.includes('NetworkError')
                    ))) {
                        console.warn('Fetch error detected:', error.message);
                        // إذا كان الخطأ في طلب مهم (مثل session check)، أعد التوجيه
                        const url = args[0] || '';
                        if (typeof url === 'string' && (
                            url.includes('check_session') ||
                            url.includes('session_keepalive') ||
                            url.includes('isLoggedIn')
                        )) {
                            // استثناء صفحات tasks من إعادة التوجيه التلقائية
                            const currentUrl = window.location.href || '';
                            const isTasksPage = currentUrl.includes('tasks.php') || 
                                               currentUrl.includes('page=tasks') ||
                                               currentUrl.includes('production.php?page=tasks') ||
                                               currentUrl.includes('manager.php?page=tasks');
                            
                            if (!isTasksPage) {
                                setTimeout(redirectToLogin, 1000);
                            } else {
                                console.warn('Tasks page detected - skipping auto-redirect for fetch error');
                            }
                        }
                    }
                    throw error;
                });
        };
        
        // تم تعطيل فحص حالة الصفحة - checkPageStatus معطلة
        // مراقبة تغييرات الصفحة (للتحقق من أخطاء التوجيه) - تقليل التكرار
        // let lastUrl = window.location.href;
        // setInterval(function() {
        //     // إذا كان التنقل قيد التقدم، لا نتحقق
        //     if (isNavigating) {
        //         return;
        //     }
        //     
        //     const currentUrl = window.location.href;
        //     // إذا تغيرت الصفحة إلى صفحة خطأ أو ERR_FAILED
        //     if (currentUrl !== lastUrl) {
        //         lastUrl = currentUrl;
        //         // التحقق من أن الصفحة الحالية ليست صفحة تسجيل الدخول
        //         if (!currentUrl.includes('index.php') && !currentUrl.includes('login')) {
        //             // استثناء صفحات tasks من التحقق التلقائي
        //             const isTasksPage = currentUrl.includes('tasks.php') || 
        //                                currentUrl.includes('page=tasks') ||
        //                                currentUrl.includes('production.php?page=tasks') ||
        //                                currentUrl.includes('manager.php?page=tasks');
        //             
        //             if (!isTasksPage) {
        //                 // التحقق من أن الصفحة تم تحميلها بشكل صحيح (بعد تأخير أطول)
        //                 // زيادة التأخير لمنح الصفحة وقتاً كافياً للتحميل
        //                 setTimeout(checkPageStatus, 8000);
        //             }
        //         }
        //     }
        // }, 15000); // 15 ثانية بدلاً من 10 ثوانٍ لتقليل الاستخدام
        
        // معالجة أخطاء XMLHttpRequest (للتوافق مع الكود القديم)
        if (window.XMLHttpRequest) {
            const originalOpen = XMLHttpRequest.prototype.open;
            const originalSend = XMLHttpRequest.prototype.send;
            
            XMLHttpRequest.prototype.open = function(method, url, ...args) {
                this._url = url;
                return originalOpen.apply(this, [method, url, ...args]);
            };
            
            XMLHttpRequest.prototype.send = function(...args) {
                this.addEventListener('error', function() {
                    const url = this._url || '';
                    if (url.includes('check_session') || url.includes('session_keepalive')) {
                        // استثناء صفحات tasks من إعادة التوجيه التلقائية
                        const currentUrl = window.location.href || '';
                        const isTasksPage = currentUrl.includes('tasks.php') || 
                                           currentUrl.includes('page=tasks') ||
                                           currentUrl.includes('production.php?page=tasks') ||
                                           currentUrl.includes('manager.php?page=tasks');
                        
                        if (!isTasksPage) {
                            console.warn('XHR error for session check - redirecting to login');
                            setTimeout(redirectToLogin, 1000);
                        } else {
                            console.warn('Tasks page detected - skipping auto-redirect for XHR error');
                        }
                    }
                });
                
                return originalSend.apply(this, args);
            };
        }
    })();
    </script>
    
    <!-- Maintenance Mode Modal -->
    <div class="modal fade" id="maintenanceModeModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="maintenanceModeModalLabel" aria-hidden="true" style="z-index: 9999;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="maintenanceModeModalLabel">
                        <i class="bi bi-tools me-2"></i>وضع الصيانة
                    </h5>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="mb-3">التطبيق تحت الصيانة</h5>
                    <p class="text-muted mb-0">التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Maintenance Mode Overlay -->
    <div id="maintenanceModeOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 9998; pointer-events: all;"></div>
    
    <style>
        /* منع التفاعلات مع المحتوى عند وضع الصيانة */
        body.maintenance-mode-active {
            overflow: hidden;
            pointer-events: none;
        }
        
        body.maintenance-mode-active #maintenanceModeModal {
            pointer-events: all;
        }
        
        body.maintenance-mode-active #maintenanceModeOverlay {
            display: block !important;
        }
        
        /* إخفاء جميع العناصر التفاعلية عند وضع الصيانة */
        body.maintenance-mode-active * {
            pointer-events: none !important;
        }
        
        body.maintenance-mode-active #maintenanceModeModal,
        body.maintenance-mode-active #maintenanceModeModal * {
            pointer-events: all !important;
        }
    </style>
    
    <script>
    (function() {
        'use strict';
        
        // دالة للتحقق من وضع الصيانة
        function checkMaintenanceMode() {
            // التحقق أولاً من API (الأكثر دقة)
            fetch(getApiPath('api/check_maintenance_mode.php'))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.maintenance_mode === 'on' && !data.is_developer) {
                        // وضع الصيانة مفعّل والمستخدم ليس مطوراً
                        showMaintenanceModal();
                    } else {
                        // وضع الصيانة معطل أو المستخدم مطور - إخفاء Modal
                        hideMaintenanceModal();
                        // تنظيف session إذا كان مطوراً
                        if (data.is_developer && data.success) {
                            // المطور لديه وصول - إزالة علامة وضع الصيانة من session
                            fetch(getApiPath('api/clear_maintenance_session.php')).catch(() => {});
                        }
                    }
                })
                .catch(error => {
                    console.warn('Error checking maintenance mode:', error);
                    // في حالة الخطأ، التحقق من session كبديل (لكن فقط إذا لم يكن مطوراً)
                    <?php 
                    $currentUser = getCurrentUser();
                    $isDev = isset($currentUser['role']) && strtolower($currentUser['role']) === 'developer';
                    if (!$isDev && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['maintenance_mode']) && $_SESSION['maintenance_mode']): 
                    ?>
                    showMaintenanceModal();
                    <?php else: ?>
                    hideMaintenanceModal();
                    <?php endif; ?>
                });
        }
        
        // عرض Modal وضع الصيانة
        function showMaintenanceModal() {
            const modal = document.getElementById('maintenanceModeModal');
            const overlay = document.getElementById('maintenanceModeOverlay');
            const body = document.body;
            
            if (modal && overlay) {
                body.classList.add('maintenance-mode-active');
                overlay.style.display = 'block';
                
                // استخدام Bootstrap Modal إذا كان متاحاً
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    const bsModal = new bootstrap.Modal(modal, {
                        backdrop: 'static',
                        keyboard: false
                    });
                    bsModal.show();
                } else {
                    // Fallback: عرض Modal يدوياً
                    modal.style.display = 'block';
                    modal.classList.add('show');
                    modal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('modal-open');
                    const modalBackdrop = document.createElement('div');
                    modalBackdrop.className = 'modal-backdrop fade show';
                    modalBackdrop.id = 'maintenanceModalBackdrop';
                    document.body.appendChild(modalBackdrop);
                }
            }
        }
        
        // إخفاء Modal وضع الصيانة
        function hideMaintenanceModal() {
            const modal = document.getElementById('maintenanceModeModal');
            const overlay = document.getElementById('maintenanceModeOverlay');
            const body = document.body;
            
            if (modal && overlay) {
                body.classList.remove('maintenance-mode-active');
                overlay.style.display = 'none';
                
                // إغلاق Bootstrap Modal إذا كان متاحاً
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) {
                        bsModal.hide();
                    }
                } else {
                    // Fallback: إخفاء Modal يدوياً
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('modal-open');
                    const backdrop = document.getElementById('maintenanceModalBackdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
            }
        }
        
        // التحقق من وضع الصيانة عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(checkMaintenanceMode, 500);
            });
        } else {
            setTimeout(checkMaintenanceMode, 500);
        }
        
        // تم دمج التحقق من وضع الصيانة في unified polling system
        // لا حاجة لـ setInterval منفصل بعد الآن
    })();
    </script>
    <?php
    if (isset($currentUser) && is_array($currentUser) && ($currentUser['role'] ?? '') === 'driver' && function_exists('getRelativeUrl')) {
        $apiUrl = htmlspecialchars(getRelativeUrl('api/driver_location.php'), ENT_QUOTES);
        $scriptUrl = htmlspecialchars(getRelativeUrl('assets/js/driver_location_tracker.js'));
        echo "<script>window.DRIVER_LOCATION_API_URL = '$apiUrl';</script>";
        echo "<script src=\"$scriptUrl\"></script>";
    }
    ?>
        </main>
    </div>
</body>
</html>


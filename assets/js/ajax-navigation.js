/**
 * AJAX Navigation System
 * نظام التنقل بدون إعادة تحميل كامل للصفحة
 * يحسن الأداء بشكل كبير خاصة على الهواتف المحمولة
 */

(function() {
    'use strict';

    // كشف نوع الاتصال لتحسين الأداء على بيانات الهاتف
    function detectConnectionType() {
        if ('connection' in navigator) {
            const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (conn) {
                const effectiveType = conn.effectiveType || 'unknown';
                const type = conn.type || 'unknown';
                const saveData = conn.saveData || false;
                
                if (saveData || type === 'cellular' || effectiveType === '2g' || effectiveType === 'slow-2g') {
                    return { isMobileData: true, effectiveType, saveData };
                }
            }
        }
        const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        return { isMobileData: isMobileDevice, effectiveType: 'unknown', saveData: false };
    }
    
    const connectionInfo = detectConnectionType();
    const isMobileData = connectionInfo.isMobileData;

    // إعدادات - محسّنة حسب نوع الاتصال
    const CONFIG = {
        // العناصر التي يجب تحديثها
        contentSelector: 'main',
        // الروابط التي يجب اعتراضها
        linkSelector: '.sidebar-nav a, .topbar a[href*="dashboard"], a[href*="?page="]',
        // استثناءات - روابط لا يجب اعتراضها
        excludeSelectors: 'a[target="_blank"], a[download], a[data-ajax="false"], a[href^="#"], a[href^="javascript:"]',
        // Timeout للطلبات - محسّن حسب نوع الاتصال
        // WiFi: 30 ثانية | بيانات الهاتف: 20 ثانية (أسرع للكشف عن الأخطاء)
        requestTimeout: isMobileData ? 20000 : 30000,
        // Cache للصفحات المحملة
        cacheEnabled: true,
        cacheMaxSize: isMobileData ? 15 : 10, // زيادة حجم cache على الهاتف لتسريع التنقل
        // Loading indicator
        showLoading: true
    };

    // صفحات لا تُخزَّن في الكاش أبداً (بيانات حية من قاعدة البيانات)
    function isNoCachePage(url) {
        if (!url) return false;
        try {
            const u = typeof url === 'string' ? url : (url.url || '');
            return u.indexOf('page=production_tasks') !== -1 || u.indexOf('page=daily_collection_schedules') !== -1;
        } catch (e) {
            return false;
        }
    }

    // Cache للصفحات
    const pageCache = new Map();
    let currentUrl = window.location.href;
    let isLoading = false;
    let loadingIndicator = null;
    let loadingTimeoutId = null;
    // تقليل تأخير loading indicator على بيانات الهاتف لتسريع الاستجابة
    const LOADING_DELAY = isMobileData ? 150 : 300; // 150ms للهاتف، 300ms للWiFi

    /**
     * إنشاء Loading Indicator
     */
    function createLoadingIndicator() {
        if (loadingIndicator) return loadingIndicator;
        
        const indicator = document.createElement('div');
        indicator.id = 'ajax-loading-indicator';
        indicator.innerHTML = `
            <div class="ajax-loading-overlay">
                <div class="ajax-loading-spinner">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-2 text-muted">جاري التحميل...</p>
                </div>
            </div>
        `;
        indicator.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        `;
        
        const overlay = indicator.querySelector('.ajax-loading-overlay');
        overlay.style.cssText = `
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        `;
        
        document.body.appendChild(indicator);
        loadingIndicator = indicator;
        return indicator;
    }

    /**
     * إظهار Loading Indicator
     * @param {HTMLElement} targetElement - العنصر المستهدف لوضع المؤشر فوقه (اختياري)
     */
    function showLoading(targetElement = null) {
        if (!CONFIG.showLoading) return;
        
        // إلغاء أي timeout سابق
        if (loadingTimeoutId) {
            clearTimeout(loadingTimeoutId);
            loadingTimeoutId = null;
        }
        
        // تأخير إظهار loading dialog
        loadingTimeoutId = setTimeout(() => {
            const indicator = createLoadingIndicator();
            
            if (targetElement) {
                // الحصول على موقع العنصر
                const rect = targetElement.getBoundingClientRect();
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
                
                // تحديد موقع المؤشر فوق العنصر
                indicator.style.position = 'absolute';
                indicator.style.top = (rect.top + scrollTop) + 'px';
                indicator.style.left = (rect.left + scrollLeft) + 'px';
                indicator.style.width = rect.width + 'px';
                indicator.style.height = rect.height + 'px';
                indicator.style.margin = '0';
            } else {
                // الوضع الافتراضي: ملء الشاشة بالكامل
                indicator.style.position = 'fixed';
                indicator.style.top = '0';
                indicator.style.left = '0';
                indicator.style.width = '100%';
                indicator.style.height = '100%';
            }
            
            indicator.style.display = 'flex';
            loadingTimeoutId = null;
        }, LOADING_DELAY);
    }

    /**
     * إخفاء Loading Indicator
     */
    function hideLoading() {
        // إلغاء timeout إذا كان لم ينفذ بعد
        if (loadingTimeoutId) {
            clearTimeout(loadingTimeoutId);
            loadingTimeoutId = null;
        }
        
        if (loadingIndicator) {
            loadingIndicator.style.display = 'none';
        }
    }

    /**
     * استخراج المحتوى الرئيسي من HTML
     */
    function extractContent(html) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // استخراج المحتوى الرئيسي
        const mainContent = doc.querySelector(CONFIG.contentSelector);
        if (!mainContent) {
            return null;
        }

        // استخراج العنوان
        const title = doc.querySelector('title')?.textContent || document.title;

        return {
            content: mainContent.innerHTML,
            title: title
        };
    }

    /**
     * تحديث حالة active في الشريط الجانبي
     */
    function updateSidebarActiveState() {
        const currentUrlObj = new URL(window.location.href);
        const currentPage = currentUrlObj.pathname.split('/').pop() || '';
        const currentPageParam = currentUrlObj.searchParams.get('page') || '';
        
        // إزالة active من جميع الروابط - استخدام selector أكثر تحديداً
        const allNavLinks = document.querySelectorAll('.homeline-sidebar .nav-link, .sidebar-nav .nav-link');
        
        // التأكد من أننا نجد الروابط فعلاً
        if (allNavLinks.length === 0) {
            // محاولة مرة أخرى بعد قليل إذا لم تكن الروابط جاهزة
            setTimeout(updateSidebarActiveState, 50);
            return;
        }
        
        // إزالة active من جميع الروابط أولاً
        allNavLinks.forEach(link => {
            link.classList.remove('active');
        });
        
        // إضافة active للرابط المطابق
        let foundActive = false;
        allNavLinks.forEach(link => {
            if (!link.href) return;
            
            try {
                const linkUrl = new URL(link.href, window.location.origin);
                const linkPage = linkUrl.pathname.split('/').pop() || '';
                const linkPageParam = linkUrl.searchParams.get('page') || '';
                
                // مطابقة الصفحة والمعامل
                if (linkPage === currentPage) {
                    // حالة الصفحة الرئيسية (بدون page parameter)
                    if (currentPageParam === '' && linkPageParam === '') {
                        link.classList.add('active');
                        foundActive = true;
                    } 
                    // حالة الصفحات مع page parameter
                    else if (currentPageParam !== '' && linkPageParam !== '' && currentPageParam === linkPageParam) {
                        link.classList.add('active');
                        foundActive = true;
                    }
                }
            } catch (e) {
                // تجاهل أخطاء URL parsing
                console.warn('Error parsing URL in updateSidebarActiveState:', e);
            }
        });
    }

    /**
     * تحديث المحتوى في الصفحة
     */
    function updatePageContent(data) {
        const mainElement = document.querySelector(CONFIG.contentSelector);
        if (!mainElement) {
            console.error('Main content element not found');
            return false;
        }

        // تحديث العنوان
        if (data.title) {
            document.title = data.title;
        }

        // تحديث المحتوى
        mainElement.innerHTML = data.content;

        // إعادة تهيئة الشريط العلوي فوراً بعد تحديث المحتوى - مهم جداً
        // استخدام setTimeout(0) لضمان تنفيذها بعد اكتمال تحديث DOM
        setTimeout(() => {
            reinitializeTopbarEvents();
        }, 0);

        // تنفيذ scripts المدمجة في المحتوى
        // يجب تنفيذ scripts بعد تحديث innerHTML مباشرة
        const scripts = Array.from(mainElement.querySelectorAll('script'));
        
        // فصل scripts الخارجية عن المدمجة
        const externalScripts = [];
        const inlineScripts = [];
        
        scripts.forEach(script => {
            if (script.src) {
                externalScripts.push(script);
            } else {
                inlineScripts.push(script);
            }
        });
        
        // تنفيذ scripts المدمجة أولاً (عادة تكون أسرع)
        inlineScripts.forEach((oldScript, index) => {
            setTimeout(() => {
                try {
                    const scriptContent = oldScript.textContent || oldScript.innerHTML;
                    
                    // إزالة script القديم من DOM أولاً
                    if (oldScript.parentNode) {
                        oldScript.parentNode.removeChild(oldScript);
                    }
                    
                    // التحقق من أن المحتوى صالح للتنفيذ (ليس HTML أو PHP)
                    if (scriptContent && scriptContent.trim()) {
                        const trimmedContent = scriptContent.trim();
                        
                        // تخطي المحتوى الذي يبدأ بـ < (HTML) أو <? (PHP)
                        if (trimmedContent.startsWith('<') || trimmedContent.startsWith('<?')) {
                            console.warn('Skipping script with HTML/PHP content:', trimmedContent.substring(0, 50));
                            return;
                        }
                        
                        // دالة ذكية للتحقق من HTML tags - تتحقق من أن HTML tags ليست داخل strings أو comments
                        function hasRealHTMLTags(content) {
                            // إزالة strings (single quotes, double quotes, template literals)
                            let withoutStrings = content
                                .replace(/`[^`]*`/g, '') // template literals
                                .replace(/'[^']*'/g, '') // single quotes
                                .replace(/"[^"]*"/g, ''); // double quotes
                            
                            // إزالة comments
                            withoutStrings = withoutStrings
                                .replace(/\/\/[^\n]*/g, '') // single line comments
                                .replace(/\/\*[\s\S]*?\*\//g, ''); // multi-line comments
                            
                            // التحقق من وجود HTML tags في الكود المتبقي (خارج strings و comments)
                            const htmlTagPattern = /<[a-z][a-z0-9]*[\s\S]*?>/i;
                            return htmlTagPattern.test(withoutStrings);
                        }
                        
                        // تخطي المحتوى الذي يحتوي على HTML tags (فقط إذا كانت خارج strings و comments)
                        if (hasRealHTMLTags(trimmedContent)) {
                            console.warn('Skipping script with HTML tags in code (not in strings/comments)');
                            return;
                        }
                        
                        // تخطي المحتوى الذي يحتوي على PHP tags (فقط إذا كانت خارج strings و comments)
                        const withoutStrings = trimmedContent
                            .replace(/`[^`]*`/g, '')
                            .replace(/'[^']*'/g, '')
                            .replace(/"[^"]*"/g, '')
                            .replace(/\/\/[^\n]*/g, '')
                            .replace(/\/\*[\s\S]*?\*\//g, '');
                        
                        if (withoutStrings.includes('<?php') || withoutStrings.includes('<?=')) {
                            console.warn('Skipping script with PHP tags in code (not in strings/comments)');
                            return;
                        }
                        
                        try {
                            // محاولة تنفيذ الكود مباشرة أولاً
                            // استخدام Function constructor (أكثر أماناً من eval)
                            const scriptFunction = new Function(trimmedContent);
                            scriptFunction();
                        } catch (functionError) {
                            const errorMessage = functionError.message || '';
                            const isAlreadyDeclared = errorMessage.includes('already been declared') || 
                                                      errorMessage.includes('has already been declared') ||
                                                      errorMessage.includes('Identifier') && errorMessage.includes('already been declared');
                            
                            // التحقق من خطأ "already declared" - هذا خطأ شائع عند التنقل عبر AJAX
                            if (isAlreadyDeclared) {
                                // تجاهل الخطأ بصمت - المتغير معرف مسبقاً وهذا طبيعي عند التنقل عبر AJAX
                                // console.warn('Variable already declared, skipping script execution:', functionError.message);
                                return;
                            }
                            
                            // التحقق من خطأ syntax (HTML/PHP)
                            if (errorMessage.includes('Unexpected token') || 
                                errorMessage.includes('Invalid or unexpected token')) {
                                console.warn('Skipping script with syntax error (likely HTML/PHP):', errorMessage);
                                return;
                            }
                            
                            // Fallback: محاولة تنفيذ الكود في IIFE لتجنب تعارض المتغيرات
                            try {
                                const wrappedContent = `(function() {
                                    try {
                                        ${trimmedContent}
                                    } catch (e) {
                                        // تجاهل أخطاء المتغيرات المعرفة مسبقاً
                                        if (e.message && (e.message.includes('already been declared') || 
                                                          e.message.includes('has already been declared'))) {
                                            return;
                                        }
                                        throw e;
                                    }
                                })();`;
                                const wrappedFunction = new Function(wrappedContent);
                                wrappedFunction();
                            } catch (wrappedError) {
                                const wrappedErrorMessage = wrappedError.message || '';
                                const isWrappedAlreadyDeclared = wrappedErrorMessage.includes('already been declared') || 
                                                                  wrappedErrorMessage.includes('has already been declared');
                                
                                // إذا كان الخطأ بسبب "already declared"، نتجاهله
                                if (isWrappedAlreadyDeclared) {
                                    // تجاهل الخطأ بصمت
                                    return;
                                }
                                
                                // فقط تسجيل الخطأ إذا لم يكن بسبب HTML/PHP أو already declared
                                if (!wrappedErrorMessage.includes('Unexpected token') && 
                                    !wrappedErrorMessage.includes('Invalid or unexpected token')) {
                                    console.warn('Error in wrapped script execution:', wrappedErrorMessage);
                                }
                            }
                        }
                    }
                } catch (e) {
                    console.error('Error processing inline script:', e);
                }
            }, index * 30); // تأخير بسيط بين كل script
        });
        
        // تحميل scripts الخارجية بعد scripts المدمجة
        externalScripts.forEach((oldScript, index) => {
            setTimeout(() => {
                const scriptSrc = oldScript.src;
                
                // التحقق من وجود الـ script في DOM بالفعل (في head أو body)
                // إذا كان موجوداً، لا نحتاج لإعادة تحميله
                const existingScript = document.querySelector(`script[src="${scriptSrc}"]`);
                if (existingScript && existingScript !== oldScript) {
                    // الـ script موجود بالفعل - تخطي إعادة التحميل
                    console.log('Script already loaded, skipping:', scriptSrc);
                    // إزالة script القديم من DOM فقط
                    if (oldScript.parentNode) {
                        oldScript.parentNode.removeChild(oldScript);
                    }
                    return;
                }
                
                // التحقق من وجود الـ script في head أو body بالفعل
                const allScripts = document.querySelectorAll('script[src]');
                let scriptExists = false;
                allScripts.forEach(script => {
                    // مقارنة URL بشكل صحيح (مع معالجة query strings و fragments)
                    const scriptUrl = new URL(script.src, window.location.origin);
                    const oldScriptUrl = new URL(scriptSrc, window.location.origin);
                    if (scriptUrl.href === oldScriptUrl.href && script !== oldScript) {
                        scriptExists = true;
                    }
                });
                
                if (scriptExists) {
                    // الـ script موجود بالفعل - تخطي إعادة التحميل
                    console.log('Script already loaded, skipping:', scriptSrc);
                    // إزالة script القديم من DOM فقط
                    if (oldScript.parentNode) {
                        oldScript.parentNode.removeChild(oldScript);
                    }
                    return;
                }
                
                // التحقق من flags التحميل للـ scripts المعروفة
                const scriptName = scriptSrc.split('/').pop().split('?')[0];
                const loadFlags = {
                    'main.js': '__mainJsLoaded',
                    'notifications.js': '__notificationsJsLoaded',
                    'pwa-install.js': '__pwaInstallJsLoaded',
                    'attendance_notifications.js': 'AttendanceNotificationManager'
                };
                
                // التحقق من flags التحميل
                if (loadFlags[scriptName]) {
                    if (scriptName === 'attendance_notifications.js') {
                        // للـ attendance_notifications.js، نتحقق من وجود الـ class
                        if (typeof window[loadFlags[scriptName]] !== 'undefined') {
                            console.log('Script already loaded (class check), skipping:', scriptSrc);
                            if (oldScript.parentNode) {
                                oldScript.parentNode.removeChild(oldScript);
                            }
                            return;
                        }
                    } else if (window[loadFlags[scriptName]]) {
                        // للـ scripts الأخرى، نتحقق من flag التحميل
                        console.log('Script already loaded (flag check), skipping:', scriptSrc);
                        if (oldScript.parentNode) {
                            oldScript.parentNode.removeChild(oldScript);
                        }
                        return;
                    }
                }
                
                const newScript = document.createElement('script');
                
                // نسخ جميع attributes
                Array.from(oldScript.attributes).forEach(attr => {
                    newScript.setAttribute(attr.name, attr.value);
                });
                
                // إضافة attribute لتتبع الـ scripts المحملة
                newScript.setAttribute('data-ajax-loaded', 'true');
                
                // معالجة الأخطاء
                newScript.onload = function() {
                    // Script تم تحميله بنجاح
                };
                newScript.onerror = function() {
                    console.warn('Failed to load script:', oldScript.src);
                };
                
                newScript.src = scriptSrc;
                
                // إزالة script القديم من DOM قبل إضافة الجديد
                if (oldScript.parentNode) {
                    oldScript.parentNode.removeChild(oldScript);
                }
                
                document.head.appendChild(newScript);
            }, (inlineScripts.length * 30) + (index * 100)); // تأخير أكبر للـ scripts الخارجية
        });

        // إغلاق الشريط الجانبي على الموبايل بعد التنقل
        closeSidebarOnMobile();

        // تحديث حالة active في الشريط الجانبي - مع تأخير بسيط لضمان اكتمال تحديث DOM
        // استخدام requestAnimationFrame لضمان تحديث DOM قبل تحديث حالة active
        requestAnimationFrame(() => {
            updateSidebarActiveState();
        });

        // إعادة تهيئة الشريط العلوي فوراً - مهم جداً لضمان عمل الأزرار
        // استخدام requestAnimationFrame لضمان اكتمال تحديث DOM
        requestAnimationFrame(() => {
            reinitializeTopbarEvents();
        });

        // إعادة تهيئة الأحداث - مع تأخير أكبر لضمان تنفيذ scripts
        // حساب الوقت المطلوب بناءً على عدد scripts
        const totalScriptsDelay = (inlineScripts.length * 30) + (externalScripts.length * 100);
        const reinitDelay = Math.max(200, totalScriptsDelay + 100);
        
        // إعادة تهيئة الأحداث على مراحل لضمان اكتمال جميع العمليات
        setTimeout(() => {
            reinitializeEvents();
            // إعادة تهيئة جميع الأحداث بشكل شامل
            reinitializeAllEvents();
            // إعادة تهيئة الشريط العلوي مرة أخرى للتأكد
            reinitializeTopbarEvents();
        }, reinitDelay);
        
        // إعادة تهيئة إضافية بعد تأخير أكبر لضمان عمل جميع الأزرار
        setTimeout(() => {
            // إعادة تهيئة جميع Bootstrap components مرة أخرى - بدون clone
            if (typeof bootstrap !== 'undefined') {
                // إعادة تهيئة Dropdowns - بدون clone للحفاظ على event listeners
                const allDropdowns = document.querySelectorAll('[data-bs-toggle="dropdown"]');
                allDropdowns.forEach(dropdown => {
                    try {
                        const instance = bootstrap.Dropdown.getInstance(dropdown);
                        if (instance) {
                            instance.dispose();
                        }
                        
                        // إعادة تهيئة بدون clone
                        const isTopbarElement = dropdown.closest('.homeline-topbar, .topbar-right');
                        if (isTopbarElement) {
                            new bootstrap.Dropdown(dropdown, {
                                boundary: 'viewport',
                                popperConfig: {
                                    modifiers: [
                                        {
                                            name: 'preventOverflow',
                                            options: {
                                                boundary: document.body
                                            }
                                        }
                                    ]
                                }
                            });
                        } else {
                            new bootstrap.Dropdown(dropdown);
                        }
                    } catch (e) {
                        // تجاهل الأخطاء
                        console.warn('Error in additional dropdown reinitialization:', e);
                    }
                });
                
                // إعادة تهيئة خاصة لزر القائمة السريعة - بدون clone
                const quickActionsDropdown = document.getElementById('quickActionsDropdown');
                if (quickActionsDropdown) {
                    try {
                        const instance = bootstrap.Dropdown.getInstance(quickActionsDropdown);
                        if (instance) {
                            instance.dispose();
                        }
                        
                        // إعادة تهيئة بدون clone
                        new bootstrap.Dropdown(quickActionsDropdown, {
                            boundary: 'viewport',
                            popperConfig: {
                                modifiers: [
                                    {
                                        name: 'preventOverflow',
                                        options: {
                                            boundary: document.body
                                        }
                                    }
                                ]
                            }
                        });
                    } catch (e) {
                        console.warn('Error reinitializing quick actions dropdown in updatePageContent:', e);
                    }
                }
                
                // إعادة تهيئة Tooltips
                const allTooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
                allTooltips.forEach(tooltipEl => {
                    try {
                        const instance = bootstrap.Tooltip.getInstance(tooltipEl);
                        if (!instance) {
                            new bootstrap.Tooltip(tooltipEl);
                        }
                    } catch (e) {
                        // تجاهل الأخطاء
                    }
                });
            }
            
            // التأكد من أن جميع الأزرار قابلة للنقر
            const allClickableElements = document.querySelectorAll('button, a.btn, input[type="button"], input[type="submit"], .topbar-action, a[href], [onclick]');
            allClickableElements.forEach(element => {
                // إزالة أي قيود على النقر
                if (element.style.pointerEvents === 'none') {
                    element.style.pointerEvents = '';
                }
                if (element.style.cursor === 'not-allowed' && !element.disabled) {
                    element.style.cursor = '';
                }
                // التأكد من أن العنصر ليس معطلاً بدون سبب
                if (element.hasAttribute('disabled') && !element.classList.contains('disabled')) {
                    // لا نفعل شيء - العنصر معطّل بشكل صحيح
                }
            });
        }, reinitDelay + 300);

        // إطلاق حدث مخصص - مع تأخير لضمان اكتمال جميع العمليات
        setTimeout(() => {
            window.dispatchEvent(new CustomEvent('ajaxNavigationComplete', {
                detail: { url: currentUrl }
            }));
        }, 100);

        return true;
    }

    /**
     * إغلاق الشريط الجانبي على الموبايل
     */
    function closeSidebarOnMobile() {
        if (window.innerWidth <= 768) {
            const dashboardWrapper = document.querySelector('.dashboard-wrapper');
            if (dashboardWrapper && dashboardWrapper.classList.contains('sidebar-open')) {
                dashboardWrapper.classList.remove('sidebar-open');
                document.body.classList.remove('sidebar-open');
            }
        }
    }

    /**
     * إعادة تهيئة الأحداث في الشريط العلوي (topbar)
     */
    function reinitializeTopbarEvents() {
        // إعادة تهيئة Bootstrap Dropdowns في الشريط العلوي - بدون clone للحفاظ على event listeners
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            // البحث عن جميع Dropdowns في التوب بار - باستخدام selectors متعددة
            const topbarDropdowns = document.querySelectorAll(
                '.homeline-topbar [data-bs-toggle="dropdown"], ' +
                '.topbar-right [data-bs-toggle="dropdown"], ' +
                '#quickActionsDropdown, ' +
                '.quick-actions-toggle, ' +
                '.topbar-action[data-bs-toggle="dropdown"], ' +
                '[id="notificationsDropdown"], ' +
                '[id="quickActionsDropdown"]'
            );
            
            topbarDropdowns.forEach(dropdown => {
                try {
                    // إزالة instance القديم فقط - بدون clone للحفاظ على event listeners الأخرى
                    const oldInstance = bootstrap.Dropdown.getInstance(dropdown);
                    if (oldInstance) {
                        try {
                            oldInstance.dispose();
                        } catch (disposeError) {
                            // تجاهل أخطاء dispose
                        }
                    }
                    
                    // إعادة تهيئة Bootstrap Dropdown بدون clone
                    // هذا يحافظ على event listeners التي تم إضافتها من scripts أخرى
                    new bootstrap.Dropdown(dropdown, {
                        boundary: 'viewport',
                        popperConfig: {
                            modifiers: [
                                {
                                    name: 'preventOverflow',
                                    options: {
                                        boundary: document.body
                                    }
                                }
                            ]
                        }
                    });
                } catch (e) {
                    console.warn('Error reinitializing topbar dropdown:', e);
                }
            });
            
            // إعادة تهيئة خاصة لزر القائمة السريعة - بدون clone
            const quickActionsDropdown = document.getElementById('quickActionsDropdown');
            if (quickActionsDropdown) {
                try {
                    const oldInstance = bootstrap.Dropdown.getInstance(quickActionsDropdown);
                    if (oldInstance) {
                        oldInstance.dispose();
                    }
                    
                    // إعادة تهيئة بدون clone للحفاظ على event listeners
                    new bootstrap.Dropdown(quickActionsDropdown, {
                        boundary: 'viewport',
                        popperConfig: {
                            modifiers: [
                                {
                                    name: 'preventOverflow',
                                    options: {
                                        boundary: document.body
                                    }
                                }
                            ]
                        }
                    });
                } catch (e) {
                    console.warn('Error reinitializing quick actions dropdown:', e);
                }
            }
        }

        // إعادة تهيئة Tooltips في الشريط العلوي
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            const topbarTooltips = document.querySelectorAll('.homeline-topbar [data-bs-toggle="tooltip"]');
            topbarTooltips.forEach(tooltipEl => {
                try {
                    // إزالة instance القديم إن وجد
                    const oldInstance = bootstrap.Tooltip.getInstance(tooltipEl);
                    if (oldInstance) {
                        oldInstance.dispose();
                    }
                    // إنشاء instance جديد
                    new bootstrap.Tooltip(tooltipEl);
                } catch (e) {
                    console.warn('Error reinitializing topbar tooltip:', e);
                }
            });
        }

        // إعادة تهيئة زر إعادة التحميل على الموبايل - بدون clone
        const mobileReloadBtn = document.getElementById('mobileReloadBtn');
        if (mobileReloadBtn) {
            // التحقق من وجود event listener قبل إضافته
            if (!mobileReloadBtn.hasAttribute('data-listener-added')) {
                mobileReloadBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('_refresh', Date.now().toString());
                        window.location.href = url.toString();
                    } catch (err) {
                        window.location.reload();
                    }
                });
                mobileReloadBtn.setAttribute('data-listener-added', 'true');
            }
        }

        // إعادة تهيئة زر القائمة على الموبايل
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        if (mobileMenuToggle && typeof window.initMobileMenu === 'function') {
            // إعادة تهيئة mobile menu
            try {
                window.initMobileMenu();
            } catch (e) {
                console.warn('Error reinitializing mobile menu:', e);
            }
        }

        // إعادة تهيئة زر الوضع الداكن على الموبايل - بدون clone
        const mobileDarkToggle = document.getElementById('mobileDarkToggle');
        if (mobileDarkToggle) {
            // التحقق من وجود event listener قبل إضافته
            if (!mobileDarkToggle.hasAttribute('data-listener-added')) {
                mobileDarkToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (typeof toggleDarkMode === 'function') {
                        toggleDarkMode();
                    }
                });
                mobileDarkToggle.setAttribute('data-listener-added', 'true');
            }
        }

        // إعادة تهيئة البحث العام
        const globalSearch = document.getElementById('globalSearch');
        if (globalSearch && typeof window.initGlobalSearch === 'function') {
            try {
                window.initGlobalSearch();
            } catch (e) {
                console.warn('Error reinitializing global search:', e);
            }
        }

        // إعادة تهيئة notifications dropdown - مهم جداً!
        const notificationDropdown = document.getElementById('notificationsDropdown');
        if (notificationDropdown) {
            try {
                const oldInstance = bootstrap.Dropdown.getInstance(notificationDropdown);
                if (oldInstance) {
                    oldInstance.dispose();
                }
                // إعادة تهيئة بدون clone
                new bootstrap.Dropdown(notificationDropdown);
                
                // إعادة تهيئة notifications إذا كانت الدالة متاحة
                if (typeof window.loadNotifications === 'function') {
                    window.loadNotifications();
                }
            } catch (e) {
                console.warn('Error reinitializing notifications dropdown:', e);
            }
        }

        // التأكد من أن جميع العناصر في الشريط العلوي قابلة للنقر
        const topbarActions = document.querySelectorAll('.homeline-topbar .topbar-action, .homeline-topbar button, .homeline-topbar a');
        topbarActions.forEach(action => {
            if (action.style.pointerEvents === 'none') {
                action.style.pointerEvents = '';
            }
            if (action.disabled && !action.hasAttribute('disabled')) {
                action.disabled = false;
            }
        });
    }

    /**
     * إعادة تهيئة الأحداث بعد تحديث المحتوى
     */
    function reinitializeEvents() {
        // إعادة تهيئة Bootstrap components
        if (typeof bootstrap !== 'undefined') {
            // إزالة tooltips القديمة
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                const tooltip = bootstrap.Tooltip.getInstance(tooltipTriggerEl);
                if (tooltip) {
                    tooltip.dispose();
                }
            });

            // إعادة تهيئة tooltips
            const newTooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            newTooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // إعادة تهيئة popovers
            const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.map(function (popoverTriggerEl) {
                const popover = bootstrap.Popover.getInstance(popoverTriggerEl);
                if (popover) {
                    popover.dispose();
                }
                return new bootstrap.Popover(popoverTriggerEl);
            });
            
            // ملاحظة: Modals و Dropdowns لا تحتاج إعادة تهيئة لأن Bootstrap يتعامل معها تلقائياً
            // لكن يمكن إعادة تهيئة Dropdowns إذا لزم الأمر
            const dropdownToggleList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
            dropdownToggleList.forEach(function (dropdownToggleEl) {
                // Bootstrap يعيد تهيئة dropdowns تلقائياً، لكن يمكننا التأكد
                try {
                    const dropdown = bootstrap.Dropdown.getInstance(dropdownToggleEl);
                    if (!dropdown) {
                        new bootstrap.Dropdown(dropdownToggleEl);
                    }
                } catch (e) {
                    // تجاهل الأخطاء
                }
            });
        }

        // إعادة تهيئة الأحداث المخصصة
        if (typeof window.initPageEvents === 'function') {
            try {
                window.initPageEvents();
            } catch (e) {
                console.warn('Error calling initPageEvents:', e);
            }
        }

        // إعادة تهيئة جداول البيانات
        if (typeof window.initDataTables === 'function') {
            try {
                window.initDataTables();
            } catch (e) {
                console.warn('Error calling initDataTables:', e);
            }
        }
        
        // إعادة تهيئة sidebar إذا كانت الدالة متاحة
        if (typeof window.initSidebar === 'function') {
            try {
                window.initSidebar();
            } catch (e) {
                console.warn('Error calling initSidebar:', e);
            }
        }
        
        // إعادة تهيئة mobile menu إذا كانت الدالة متاحة
        if (typeof window.initMobileMenu === 'function') {
            try {
                window.initMobileMenu();
            } catch (e) {
                console.warn('Error calling initMobileMenu:', e);
            }
        }
        
        // إعادة تهيئة جميع الأحداث في الشريط العلوي (topbar)
        reinitializeTopbarEvents();
        
        // إعادة ربط جميع الأزرار والأحداث في الصفحة
        // هذا يضمن أن جميع الأزرار تعمل بعد التنقل
        setTimeout(() => {
            // إعادة ربط أحداث النقر على الأزرار التي قد تكون فقدت event listeners
            const buttons = document.querySelectorAll('button, a.btn, input[type="button"], input[type="submit"], .topbar-action, a[href]');
            buttons.forEach(button => {
                // التأكد من أن الأزرار قابلة للنقر
                if (button.disabled) {
                    // لا نفعل شيء للأزرار المعطلة
                    return;
                }
                // إزالة أي event listeners مكررة قد تسبب مشاكل
                // ملاحظة: لا يمكن إزالة event listeners بدون مرجع، لكن يمكننا التأكد من أن العنصر نشط
                if (button.style.pointerEvents === 'none') {
                    button.style.pointerEvents = '';
                }
                // التأكد من أن العنصر قابل للنقر
                if (button.style.cursor === 'not-allowed') {
                    button.style.cursor = '';
                }
            });
            
            // إعادة تهيئة جميع النماذج (Modals) في Bootstrap
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    try {
                        // التأكد من أن Modal معرّف بشكل صحيح
                        const modalInstance = bootstrap.Modal.getInstance(modal);
                        if (!modalInstance) {
                            // إنشاء instance جديد إذا لم يكن موجوداً
                            new bootstrap.Modal(modal, {});
                        }
                    } catch (e) {
                        // تجاهل الأخطاء
                    }
                });
            }
            
            // إعادة تهيئة جميع Dropdowns في Bootstrap (مهم للشريط العلوي)
            if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
                const dropdowns = document.querySelectorAll('[data-bs-toggle="dropdown"]');
                dropdowns.forEach(dropdown => {
                    try {
                        // إزالة instance القديم إن وجد
                        const oldInstance = bootstrap.Dropdown.getInstance(dropdown);
                        if (oldInstance) {
                            oldInstance.dispose();
                        }
                        // إنشاء instance جديد
                        new bootstrap.Dropdown(dropdown);
                    } catch (e) {
                        // تجاهل الأخطاء
                    }
                });
            }
        }, 200);
        
        // إعادة تهيئة الباركودات (لصفحة مخزن السيارات وغيرها)
        // البحث عن دالة generateAllBarcodes في scripts المحملة وتنفيذها
        setTimeout(function() {
            // محاولة استدعاء generateAllBarcodes إذا كانت موجودة كدالة عامة
            if (typeof window.generateAllBarcodes === 'function') {
                try {
                    window.generateAllBarcodes();
                } catch (e) {
                    console.warn('Error calling generateAllBarcodes:', e);
                }
            }
            
            // أيضاً محاولة توليد الباركودات مباشرة إذا كانت مكتبة JsBarcode متاحة
            if (typeof JsBarcode !== 'undefined') {
                const barcodeContainers = document.querySelectorAll('.inventory-barcode-container[data-batch]');
                if (barcodeContainers.length > 0) {
                    barcodeContainers.forEach(function(container) {
                        const batchNumber = container.getAttribute('data-batch');
                        const svg = container.querySelector('svg.barcode-svg');
                        
                        if (svg && batchNumber && batchNumber.trim() !== '') {
                            try {
                                // التحقق من أن الباركود لم يتم توليده بعد
                                if (svg.children.length === 0 || svg.querySelector('text')) {
                                    svg.innerHTML = '';
                                    JsBarcode(svg, batchNumber, {
                                        format: "CODE128",
                                        width: 2,
                                        height: 50,
                                        displayValue: false,
                                        margin: 5,
                                        background: "#ffffff",
                                        lineColor: "#000000"
                                    });
                                }
                            } catch (error) {
                                console.error('Error generating barcode for ' + batchNumber + ':', error);
                                if (svg.children.length === 0) {
                                    svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="12" fill="#666" font-family="Arial">' + batchNumber + '</text>';
                                }
                            }
                        }
                    });
                }
            }
        }, 300); // تأخير 300ms لضمان تحميل جميع المكتبات
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
     * تحميل الصفحة عبر AJAX
     */
    async function loadPage(url) {
        // تنظيف URL قبل الاستخدام
        url = normalizeUrl(url);
        
        if (isLoading) {
            return false;
        }

        // التحقق من Cache المحلي أولاً (الأسرع) - مع استثناء الصفحات التي يجب أن تكون دائماً محدثة
        if (CONFIG.cacheEnabled && !isNoCachePage(url) && pageCache.has(url)) {
            if (typeof window.showPageLoading === 'function') window.showPageLoading();
            const cachedData = pageCache.get(url);
            currentUrl = url;
            updatePageContent(cachedData);
            updateHistory(url);
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                });
            });
            return true;
        }

        // إزالة صفحة أوردرات الإنتاج من الكاش إن وُجدت لضمان جلب بيانات جديدة
        if (isNoCachePage(url)) {
            pageCache.delete(url);
        }
        
        // في PWA، محاولة استخدام Service Worker cache فوراً قبل network (ما عدا صفحات no-cache)
        if (!isNoCachePage(url) && 'caches' in window && window.matchMedia('(display-mode: standalone)').matches) {
            try {
                const cacheNames = ['albarakah-static-v2.0.0', 'albarakah-precache-v2.0.0'];
                
                for (const cacheName of cacheNames) {
                    try {
                        const cache = await caches.open(cacheName);
                        const cachedResponse = await cache.match(url);
                        
                        if (cachedResponse) {
                            // استخدام cache فوراً - تحميل فوري بدون انتظار network
                            const html = await cachedResponse.text();
                            
                            // حفظ في pageCache للاستخدام القادم
                            if (CONFIG.cacheEnabled) {
                                pageCache.set(url, html);
                                // تنظيف cache إذا تجاوز الحد الأقصى
                                if (pageCache.size > CONFIG.cacheMaxSize) {
                                    const firstKey = pageCache.keys().next().value;
                                    pageCache.delete(firstKey);
                                }
                            }
                            
                            currentUrl = url;
                            updatePageContent(html);
                            updateHistory(url);
                            
                            // تحديث cache في الخلفية
                            fetch(url, {
                                method: 'GET',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'Accept': 'text/html'
                                },
                                cache: 'no-cache'
                            }).then(async (networkResponse) => {
                                if (networkResponse.ok) {
                                    try {
                                        const cache = await caches.open(cacheName);
                                        await cache.put(url, networkResponse.clone());
                                        
                                        // تحديث pageCache أيضاً
                                        const updatedHtml = await networkResponse.text();
                                        if (CONFIG.cacheEnabled) {
                                            pageCache.set(url, updatedHtml);
                                        }
                                    } catch (e) {
                                        // تجاهل أخطاء cache
                                    }
                                }
                            }).catch(() => {
                                // تجاهل أخطاء التحديث في الخلفية
                            });
                            
                            return true;
                        }
                    } catch (e) {
                        // تجاهل أخطاء cache معين، جرب cache آخر
                        continue;
                    }
                }
            } catch (cacheError) {
                // إذا فشل cache، نستخدم network
            }
        }

        isLoading = true;
        showLoading();
        if (typeof window.showPageLoading === 'function') window.showPageLoading();

        let timeoutId = null;
        try {
            // محاولة استخدام Service Worker cache أولاً (لتحسين الأداء في PWA) - ما عدا صفحات no-cache
            let response = null;
            if (!isNoCachePage(url) && 'caches' in window) {
                try {
                    // محاولة فتح جميع caches المحتملة
                    const cacheNames = ['albarakah-static-v2.0.0', 'albarakah-precache-v2.0.0'];
                    
                    for (const cacheName of cacheNames) {
                        try {
                            const cache = await caches.open(cacheName);
                            const cachedResponse = await cache.match(url);
                            if (cachedResponse) {
                                // استخدام cache إذا كان متاحاً - هذا يسرع التحميل بشكل كبير
                                response = cachedResponse;
                                
                                // تحديث cache في الخلفية بدون انتظار
                                fetch(url, {
                                    method: 'GET',
                                    headers: {
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'Accept': 'text/html'
                                    },
                                    cache: 'no-cache'
                                }).then(async (networkResponse) => {
                                    if (networkResponse.ok) {
                                        try {
                                            const cache = await caches.open(cacheName);
                                            await cache.put(url, networkResponse.clone());
                                        } catch (e) {
                                            // تجاهل أخطاء cache
                                        }
                                    }
                                }).catch(() => {
                                    // تجاهل أخطاء التحديث في الخلفية
                                });
                                
                                break; // وجدنا cache، لا نحتاج للبحث في باقي caches
                            }
                        } catch (e) {
                            // تجاهل أخطاء cache معين، جرب cache آخر
                            continue;
                        }
                    }
                } catch (cacheError) {
                    // إذا فشل cache، نستخدم network
                }
            }
            
            // إذا لم يكن هناك cache، نستخدم network
            if (!response) {
                // إضافة timeout فعلي باستخدام AbortController
                const controller = new AbortController();
                timeoutId = setTimeout(() => controller.abort(), CONFIG.requestTimeout);

                response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html'
                    },
                    cache: isNoCachePage(url) ? 'no-store' : 'default',
                    signal: controller.signal,
                    redirect: 'follow'
                });
            }

            if (timeoutId) {
                clearTimeout(timeoutId);
                timeoutId = null;
            }

            if (!response || !response.ok) {
                throw new Error(`HTTP error! status: ${response ? response.status : 'unknown'}`);
            }

            // التحقق من حدوث redirect إلى صفحة تسجيل الدخول
            const responseUrl = response.url || url;
            
            // قراءة HTML أولاً للتحقق من المحتوى
            const html = await response.text();
            
            // التحقق من أن المحتوى يحتوي على <main> (صفحات dashboard تحتوي على <main>)
            const hasMainContent = html.includes('<main') || html.includes('<main>');
            
            // تحقق أكثر دقة: يجب أن ينتهي URL بـ index.php أو يحتوي على /index.php في نهاية المسار
            // وليس فقط وجود index.php في أي مكان
            const urlPath = new URL(responseUrl, window.location.origin).pathname;
            const isLoginPageUrl = urlPath.endsWith('/index.php') || 
                                  urlPath.endsWith('index.php') || 
                                  urlPath.includes('/index.php?') ||
                                  urlPath.includes('/index.php#') ||
                                  (urlPath === '/' && responseUrl.includes('index.php'));
            
            // التحقق من محتوى HTML للتأكد من أنه صفحة تسجيل دخول فعلاً
            // يجب أن يحتوي على عناصر تسجيل الدخول ولا يحتوي على <main>
            const isLoginPageContent = !hasMainContent && (
                                      html.includes('تسجيل الدخول') || 
                                      html.includes('اسم المستخدم') ||
                                      (html.includes('form') && html.includes('password') && html.includes('username'))
            );
            
            // إذا تم redirect إلى صفحة تسجيل الدخول وكان المحتوى يؤكد ذلك، نعيد التوجيه الكامل
            if (response.redirected && isLoginPageUrl && isLoginPageContent) {
                hideLoading();
                if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                isLoading = false;
                window.location.href = responseUrl;
                return false;
            }

            // إذا كان المحتوى يشير إلى صفحة تسجيل دخول (حتى بدون redirect)، نعيد التوجيه
            if (isLoginPageContent && isLoginPageUrl && !hasMainContent) {
                hideLoading();
                if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                isLoading = false;
                window.location.href = responseUrl;
                return false;
            }
            
            // إذا كان المحتوى لا يحتوي على <main> وليس صفحة تسجيل دخول، قد تكون هناك مشكلة
            // لكن لا نعيد التوجيه إلا إذا كان URL يشير فعلاً إلى index.php
            if (!hasMainContent && isLoginPageUrl && !isLoginPageContent) {
                // قد تكون صفحة خطأ أو صفحة فارغة - نترك الكود يستمر
                console.warn('Page content missing <main> tag but URL suggests login page');
            }

            const data = extractContent(html);

            if (!data) {
                if (response.redirected && isLoginPageUrl && isLoginPageContent) {
                    hideLoading();
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    isLoading = false;
                    window.location.href = responseUrl;
                    return false;
                }
                if (!hasMainContent && isLoginPageUrl) {
                    hideLoading();
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    isLoading = false;
                    window.location.href = responseUrl;
                    return false;
                }
                throw new Error('Failed to extract content from response');
            }

            // حفظ في Cache (ما عدا الصفحات التي يجب أن تبقى دائماً محدثة)
            if (CONFIG.cacheEnabled && !isNoCachePage(url)) {
                // تنظيف Cache إذا تجاوز الحد الأقصى
                if (pageCache.size >= CONFIG.cacheMaxSize) {
                    const firstKey = pageCache.keys().next().value;
                    pageCache.delete(firstKey);
                }
                pageCache.set(url, data);
            }

            // تحديث URL أولاً
            currentUrl = url;
            // تحديث الصفحة
            updatePageContent(data);
            updateHistory(url);

            // إخفاء شاشة التحميل العامة بعد رسم المحتوى المحدث
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                });
            });

            return true;
        } catch (error) {
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
            console.error('AJAX navigation error:', error);

            hideLoading();
            if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
            
            // إذا كان timeout، أظهر رسالة خطأ قبل fallback
            if (error.name === 'AbortError' || error.message.includes('timeout')) {
                // إظهار رسالة خطأ مؤقتة
                const mainElement = document.querySelector(CONFIG.contentSelector);
                if (mainElement) {
                    mainElement.innerHTML = `
                        <div class="alert alert-warning">
                            <h5>انتهت مهلة الاتصال</h5>
                            <p>جاري إعادة تحميل الصفحة...</p>
                        </div>
                    `;
                }
                
                // إعادة تحميل بعد تأخير بسيط
                setTimeout(() => {
                    window.location.href = url;
                }, 1000);
            } else {
                // Fallback فوري للأخطاء الأخرى
                window.location.href = url;
            }
            return false;
        } finally {
            isLoading = false;
            hideLoading();
            // ملاحظة: hidePageLoading يُستدعى بعد رسم المحتوى في مسار النجاح، أو في catch/redirect أعلاه
        }
    }

    /**
     * تحديث History API
     */
    function updateHistory(url) {
        if (window.history && window.history.pushState) {
            window.history.pushState({ url: url }, '', url);
        }
    }

    /**
     * التحقق من أن الرابط يجب اعتراضه
     */
    function shouldInterceptLink(link) {
        // التحقق من الاستثناءات
        if (link.matches(CONFIG.excludeSelectors)) {
            return false;
        }

        // صفحة أوردرات الإنتاج: فتح كتحميل عادي (لا AJAX) لتجنب timeout في Chrome وبعض المتصفحات
        try {
            const u = (link.href || '').toString();
            if (u.indexOf('page=production_tasks') !== -1) {
                return false;
            }
        } catch (e) {}

        // التحقق من أن الرابط في نفس النطاق
        try {
            const linkUrl = new URL(link.href, window.location.origin);
            const currentUrlObj = new URL(window.location.href);
            
            if (linkUrl.origin !== currentUrlObj.origin) {
                return false;
            }

            // التحقق من أن الرابط من الشريط الجانبي (أهم تحسين لـ PWA)
            const isSidebarLink = link.closest('.homeline-sidebar') || 
                                  link.closest('.sidebar') ||
                                  link.closest('.sidebar-nav') ||
                                  link.classList.contains('nav-link');
            
            // التحقق من أن الرابط يحتوي على dashboard أو page parameter أو أنه رابط من الشريط الجانبي
            if (isSidebarLink || 
                linkUrl.pathname.includes('/dashboard/') || 
                linkUrl.pathname.includes('/modules/') ||
                linkUrl.searchParams.has('page')) {
                return true;
            }
        } catch (e) {
            return false;
        }

        return false;
    }

    /**
     * معالج النقر على الروابط
     */
    function handleLinkClick(event) {
        const link = event.target.closest('a');
        if (!link) return;

        // تنقل سلس لترقيم الحركات المالية (خزنة الشركة / صفحة المحاسب) بدون ريفريش للمحتوى
        const transactionsContainer = link.closest('#company-cash-transactions-list') || link.closest('#accountant-financial-transactions-list');
        if (transactionsContainer && link.classList.contains('page-link') && !link.closest('.page-item.disabled')) {
            const href = link.getAttribute('href') || link.href || '';
            if (href && (href.indexOf('page=company_cash') !== -1 || href.indexOf('page=accountant_cash') !== -1 || href.indexOf('page=financial') !== -1)) {
                event.preventDefault();
                event.stopPropagation();
                const sep = href.indexOf('?') >= 0 ? '&' : '?';
                let ajaxUrl = href + sep + 'ajax=transactions_list';
                if (ajaxUrl.indexOf('http') !== 0) {
                    const base = window.location.origin + window.location.pathname;
                    ajaxUrl = (ajaxUrl.charAt(0) === '?' ? base + ajaxUrl : base + '?' + ajaxUrl);
                }
                transactionsContainer.style.opacity = '0.6';
                transactionsContainer.style.pointerEvents = 'none';
                fetch(ajaxUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-cache' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data && data.html) {
                            transactionsContainer.innerHTML = data.html;
                            try {
                                const u = new URL(ajaxUrl, window.location.origin);
                                u.searchParams.delete('ajax');
                                window.history.pushState({ url: u.pathname + '?' + u.searchParams.toString() }, '', u.pathname + '?' + u.searchParams.toString());
                                currentUrl = window.location.href;
                            } catch (e) {}
                        }
                    })
                    .catch(function() {})
                    .finally(function() {
                        transactionsContainer.style.opacity = '';
                        transactionsContainer.style.pointerEvents = '';
                    });
                return;
            }
        }

        if (!shouldInterceptLink(link)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        // تنظيف URL قبل الاستخدام
        let url = normalizeUrl(link.href);
        if (url === currentUrl) {
            return; // نفس الصفحة
        }

        loadPage(url);
    }

    /**
     * معالج زر الرجوع/الأمام
     */
    function handlePopState(event) {
        if (event.state && event.state.url) {
            loadPage(event.state.url);
        } else {
            // Fallback: إعادة تحميل كامل
            window.location.reload();
        }
    }

    /**
     * تهيئة النظام
     */
    function init() {
        // تحديث حالة active في الشريط الجانبي عند التحميل الأولي
        // استخدام setTimeout لضمان أن DOM جاهز بالكامل
        setTimeout(() => {
            updateSidebarActiveState();
        }, 0);

        // إضافة معالج النقر على الروابط
        document.addEventListener('click', handleLinkClick, true);

        // إضافة معالج زر الرجوع/الأمام
        window.addEventListener('popstate', handlePopState);

        // حفظ حالة الصفحة الحالية
        if (window.history && window.history.replaceState) {
            window.history.replaceState({ url: currentUrl }, '', currentUrl);
        }

        // إضافة CSS للـ loading indicator
        if (!document.getElementById('ajax-navigation-styles')) {
            const style = document.createElement('style');
            style.id = 'ajax-navigation-styles';
            style.textContent = `
                #ajax-loading-indicator {
                    backdrop-filter: blur(2px);
                }
                .ajax-loading-spinner {
                    background: white;
                    padding: 2rem;
                    border-radius: 8px;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                }
                [data-theme="dark"] .ajax-loading-spinner {
                    background: #374151;
                    color: #f3f4f6;
                }
                [data-theme="dark"] .ajax-loading-spinner .text-muted {
                    color: #9ca3af !important;
                }
                .ajax-loading-spinner .spinner-border {
                    width: 3rem;
                    height: 3rem;
                }
            `;
            document.head.appendChild(style);
        }

        // إضافة event listener للتنظيف عند إعادة تحميل الصفحة - استخدام pagehide لإعادة تفعيل bfcache
        window.addEventListener('pagehide', function() {
            pageCache.clear();
        });
    }

    // تهيئة النظام عند تحميل DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /**
     * إعادة تهيئة جميع الأحداث بشكل شامل
     * هذه الدالة تعيد تهيئة جميع الأحداث بعد التنقل عبر AJAX
     */
    function reinitializeAllEvents() {
        // 1. إعادة تهيئة Bootstrap Dropdowns - بدون clone للحفاظ على event listeners
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            // البحث عن جميع Dropdowns في الصفحة
            const dropdowns = document.querySelectorAll('[data-bs-toggle="dropdown"]');
            dropdowns.forEach(dropdown => {
                try {
                    // إزالة instance القديم فقط - بدون clone
                    const oldInstance = bootstrap.Dropdown.getInstance(dropdown);
                    if (oldInstance) {
                        oldInstance.dispose();
                    }
                    
                    // إعادة تهيئة Bootstrap Dropdown بدون clone
                    // هذا يحافظ على event listeners التي تم إضافتها من scripts أخرى
                    const isTopbarElement = dropdown.closest('.homeline-topbar, .topbar-right');
                    if (isTopbarElement) {
                        // للعناصر في التوب بار، استخدم إعدادات خاصة
                        new bootstrap.Dropdown(dropdown, {
                            boundary: 'viewport',
                            popperConfig: {
                                modifiers: [
                                    {
                                        name: 'preventOverflow',
                                        options: {
                                            boundary: document.body
                                        }
                                    }
                                ]
                            }
                        });
                    } else {
                        // للعناصر الأخرى، إعادة تهيئة عادية
                        new bootstrap.Dropdown(dropdown);
                    }
                } catch (e) {
                    console.warn('Error reinitializing dropdown:', e);
                }
            });
            
            // إعادة تهيئة خاصة لزر القائمة السريعة - بدون clone
            const quickActionsDropdown = document.getElementById('quickActionsDropdown');
            if (quickActionsDropdown) {
                try {
                    const oldInstance = bootstrap.Dropdown.getInstance(quickActionsDropdown);
                    if (oldInstance) {
                        oldInstance.dispose();
                    }
                    
                    // إعادة تهيئة بدون clone
                    new bootstrap.Dropdown(quickActionsDropdown, {
                        boundary: 'viewport',
                        popperConfig: {
                            modifiers: [
                                {
                                    name: 'preventOverflow',
                                    options: {
                                        boundary: document.body
                                    }
                                }
                            ]
                        }
                    });
                } catch (e) {
                    console.warn('Error reinitializing quick actions dropdown in reinitializeAllEvents:', e);
                }
            }
        }

        // 2. إعادة تهيئة Bootstrap Tooltips
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            tooltips.forEach(tooltipEl => {
                try {
                    const oldInstance = bootstrap.Tooltip.getInstance(tooltipEl);
                    if (oldInstance) {
                        oldInstance.dispose();
                    }
                    new bootstrap.Tooltip(tooltipEl);
                } catch (e) {
                    console.warn('Error reinitializing tooltip:', e);
                }
            });
        }

        // 3. إعادة تهيئة Bootstrap Popovers
        if (typeof bootstrap !== 'undefined' && bootstrap.Popover) {
            const popovers = document.querySelectorAll('[data-bs-toggle="popover"]');
            popovers.forEach(popoverEl => {
                try {
                    const oldInstance = bootstrap.Popover.getInstance(popoverEl);
                    if (oldInstance) {
                        oldInstance.dispose();
                    }
                    new bootstrap.Popover(popoverEl);
                } catch (e) {
                    console.warn('Error reinitializing popover:', e);
                }
            });
        }

        // 4. إعادة تهيئة زر إعادة التحميل على الموبايل - بدون clone
        const mobileReloadBtn = document.getElementById('mobileReloadBtn');
        if (mobileReloadBtn) {
            // التحقق من وجود event listener قبل إضافته
            if (!mobileReloadBtn.hasAttribute('data-listener-added')) {
                mobileReloadBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('_refresh', Date.now().toString());
                        window.location.href = url.toString();
                    } catch (err) {
                        window.location.reload();
                    }
                });
                mobileReloadBtn.setAttribute('data-listener-added', 'true');
            }
        }

        // 5. إعادة تهيئة زر الوضع الداكن على الموبايل - بدون clone
        const mobileDarkToggle = document.getElementById('mobileDarkToggle');
        if (mobileDarkToggle) {
            // التحقق من وجود event listener قبل إضافته
            if (!mobileDarkToggle.hasAttribute('data-listener-added')) {
                mobileDarkToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (typeof toggleDarkMode === 'function') {
                        toggleDarkMode();
                    }
                });
                mobileDarkToggle.setAttribute('data-listener-added', 'true');
            }
        }

        // 6. إعادة تهيئة notifications dropdown
        const notificationDropdown = document.getElementById('notificationsDropdown');
        if (notificationDropdown) {
            // إعادة تهيئة Bootstrap Dropdown للإشعارات
            try {
                const oldInstance = bootstrap.Dropdown.getInstance(notificationDropdown);
                if (oldInstance) {
                    oldInstance.dispose();
                }
                // إعادة تهيئة بدون clone
                new bootstrap.Dropdown(notificationDropdown);
                
                // إعادة تهيئة notifications إذا كانت الدالة متاحة
                if (typeof window.loadNotifications === 'function') {
                    window.loadNotifications();
                }
            } catch (e) {
                console.warn('Error reinitializing notifications dropdown:', e);
            }
            
            // إعادة ربط event listener لطلب الإذن - فقط إذا لم يكن موجوداً
            if (!notificationDropdown.hasAttribute('data-notification-listener-added')) {
                notificationDropdown.addEventListener('click', function(e) {
                    if (typeof checkNotificationPermission === 'function' && 
                        typeof requestNotificationPermission === 'function') {
                        if (checkNotificationPermission() === 'default') {
                            requestNotificationPermission().catch(err => {
                                console.error('Error requesting notification permission:', err);
                            });
                        }
                    }
                }, { once: false, passive: true });
                notificationDropdown.setAttribute('data-notification-listener-added', 'true');
            }
        }

        // 7. إعادة تهيئة mobile menu
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        if (mobileMenuToggle && typeof window.initMobileMenu === 'function') {
            try {
                window.initMobileMenu();
            } catch (e) {
                console.warn('Error reinitializing mobile menu:', e);
            }
        }

        // 8. إزالة جميع قيود pointer-events من الأزرار والعناصر القابلة للنقر
        const allClickableElements = document.querySelectorAll('button, a.btn, input[type="button"], input[type="submit"], .topbar-action, a[href], [onclick], .dropdown-toggle, [data-bs-toggle]');
        allClickableElements.forEach(element => {
            // إزالة قيود pointer-events
            if (element.style.pointerEvents === 'none') {
                element.style.pointerEvents = '';
            }
            // إزالة قيود cursor
            if (element.style.cursor === 'not-allowed' && !element.disabled) {
                element.style.cursor = '';
            }
            // التأكد من أن العنصر ليس معطلاً بدون سبب
            if (element.hasAttribute('disabled') && !element.classList.contains('disabled') && !element.disabled) {
                // لا نفعل شيء - العنصر معطّل بشكل صحيح
            }
            // إزالة أي opacity منخفضة قد تشير إلى تعطيل
            if (element.style.opacity === '0.5' || element.style.opacity === '0.6') {
                // لا نغير opacity - قد يكون مقصوداً
            }
        });
        
        // 8.1. إعادة تهيئة جميع العناصر في الشريط العلوي بشكل خاص
        const topbarElements = document.querySelectorAll('.homeline-topbar button, .homeline-topbar a, .homeline-topbar .topbar-action');
        topbarElements.forEach(element => {
            // إزالة جميع القيود
            element.style.pointerEvents = '';
            if (!element.disabled) {
                element.style.cursor = '';
                element.style.opacity = '';
            }
            // التأكد من أن العنصر قابل للنقر
            if (element.onclick || element.getAttribute('onclick') || element.getAttribute('data-bs-toggle')) {
                // العنصر لديه event handler - لا نحتاج لفعل شيء
            }
        });

        // 9. إعادة تهيئة الأحداث المخصصة إذا كانت متاحة
        if (typeof window.initPageEvents === 'function') {
            try {
                window.initPageEvents();
            } catch (e) {
                console.warn('Error calling initPageEvents:', e);
            }
        }

        // 10. إعادة تهيئة البحث العام إذا كانت الدالة متاحة
        if (typeof window.initGlobalSearch === 'function') {
            try {
                window.initGlobalSearch();
            } catch (e) {
                console.warn('Error calling initGlobalSearch:', e);
            }
        }
    }

    // الاستماع لحدث ajaxNavigationComplete لإعادة تهيئة الأحداث
    // هذا يضمن أن جميع الأحداث تعمل بعد التنقل
    window.addEventListener('ajaxNavigationComplete', function(event) {
        // إعادة تهيئة فورية للشريط العلوي - مهم جداً
        reinitializeTopbarEvents();
        
        // إعادة تهيئة الأحداث بعد تأخير بسيط لضمان اكتمال تحديث DOM
        setTimeout(() => {
            reinitializeAllEvents();
            reinitializeTopbarEvents(); // إعادة تهيئة خاصة للتوب بار
        }, 50);
        
        // إعادة تهيئة إضافية بعد تأخير أكبر لضمان اكتمال جميع العمليات
        setTimeout(() => {
            reinitializeAllEvents();
            reinitializeTopbarEvents(); // إعادة تهيئة خاصة للتوب بار
        }, 200);
        
        // إعادة تهيئة نهائية بعد تأخير أكبر لضمان عمل جميع الأزرار
        setTimeout(() => {
            // إعادة تهيئة خاصة لزر القائمة السريعة - بدون clone
            const quickActionsDropdown = document.getElementById('quickActionsDropdown');
            if (quickActionsDropdown && typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
                try {
                    const oldInstance = bootstrap.Dropdown.getInstance(quickActionsDropdown);
                    if (oldInstance) {
                        try {
                            oldInstance.dispose();
                        } catch (disposeError) {
                            // تجاهل أخطاء dispose
                        }
                    }
                    
                    // إعادة تهيئة بدون clone
                    new bootstrap.Dropdown(quickActionsDropdown, {
                        boundary: 'viewport',
                        popperConfig: {
                            modifiers: [
                                {
                                    name: 'preventOverflow',
                                    options: {
                                        boundary: document.body
                                    }
                                }
                            ]
                        }
                    });
                } catch (e) {
                    console.warn('Error in final quick actions dropdown reinitialization:', e);
                }
            }
            
            // إعادة تهيئة جميع Dropdowns في التوب بار
            reinitializeTopbarEvents();
        }, 500);
    });

    // تصدير API عام
    window.AjaxNavigation = {
        load: loadPage,
        clearCache: () => pageCache.clear(),
        isEnabled: () => true,
        reinitializeEvents: reinitializeEvents,
        reinitializeTopbarEvents: reinitializeTopbarEvents,
        reinitializeAllEvents: reinitializeAllEvents
    };
})();


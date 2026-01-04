/**
 * AJAX Navigation System
 * نظام التنقل بدون إعادة تحميل كامل للصفحة
 * يحسن الأداء بشكل كبير خاصة على الهواتف المحمولة
 */

(function() {
    'use strict';

    // إعدادات
    const CONFIG = {
        // العناصر التي يجب تحديثها
        contentSelector: 'main',
        // الروابط التي يجب اعتراضها
        linkSelector: '.sidebar-nav a, .topbar a[href*="dashboard"], a[href*="?page="]',
        // استثناءات - روابط لا يجب اعتراضها
        excludeSelectors: 'a[target="_blank"], a[download], a[data-ajax="false"], a[href^="#"], a[href^="javascript:"]',
        // Timeout للطلبات
        requestTimeout: 30000,
        // Cache للصفحات المحملة
        cacheEnabled: true,
        cacheMaxSize: 10,
        // Loading indicator
        showLoading: true
    };

    // Cache للصفحات
    const pageCache = new Map();
    let currentUrl = window.location.href;
    let isLoading = false;
    let loadingIndicator = null;

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
            background: rgba(0, 0, 0, 0.3);
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
    }

    /**
     * إخفاء Loading Indicator
     */
    function hideLoading() {
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
        
        // إزالة active من جميع الروابط
        const allNavLinks = document.querySelectorAll('.homeline-sidebar .nav-link, .sidebar-nav .nav-link');
        allNavLinks.forEach(link => {
            link.classList.remove('active');
        });
        
        // إضافة active للرابط المطابق
        allNavLinks.forEach(link => {
            if (!link.href) return;
            
            try {
                const linkUrl = new URL(link.href, window.location.origin);
                const linkPage = linkUrl.pathname.split('/').pop() || '';
                const linkPageParam = linkUrl.searchParams.get('page') || '';
                
                // مطابقة الصفحة والمعامل
                if (linkPage === currentPage) {
                    if (currentPageParam === '' && linkPageParam === '') {
                        // الصفحة الرئيسية
                        link.classList.add('active');
                    } else if (currentPageParam !== '' && linkPageParam === currentPageParam) {
                        // نفس معامل page
                        link.classList.add('active');
                    }
                }
            } catch (e) {
                // تجاهل أخطاء URL parsing
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

        // تحديث حالة active في الشريط الجانبي
        updateSidebarActiveState();

        // إعادة تهيئة الأحداث
        reinitializeEvents();

        // إطلاق حدث مخصص
        window.dispatchEvent(new CustomEvent('ajaxNavigationComplete', {
            detail: { url: currentUrl }
        }));

        return true;
    }

    /**
     * إعادة تهيئة الأحداث بعد تحديث المحتوى
     */
    function reinitializeEvents() {
        // إعادة تهيئة Bootstrap tooltips و popovers
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
        }

        // إعادة تهيئة الأحداث المخصصة
        if (typeof window.initPageEvents === 'function') {
            window.initPageEvents();
        }

        // إعادة تهيئة جداول البيانات
        if (typeof window.initDataTables === 'function') {
            window.initDataTables();
        }
    }

    /**
     * تحميل الصفحة عبر AJAX
     */
    async function loadPage(url) {
        if (isLoading) {
            return false;
        }

        // التحقق من Cache
        if (CONFIG.cacheEnabled && pageCache.has(url)) {
            const cachedData = pageCache.get(url);
            updatePageContent(cachedData);
            updateHistory(url);
            return true;
        }

        isLoading = true;
        showLoading();

        let timeoutId = null;
        try {
            // إضافة timeout فعلي باستخدام AbortController
            const controller = new AbortController();
            timeoutId = setTimeout(() => controller.abort(), CONFIG.requestTimeout);

            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                },
                cache: 'default',
                signal: controller.signal,
                redirect: 'follow'
            });

            clearTimeout(timeoutId);
            timeoutId = null;

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            // التحقق من حدوث redirect إلى صفحة تسجيل الدخول
            const responseUrl = response.url || url;
            const isLoginPage = responseUrl.includes('index.php') || responseUrl.includes('/login');
            
            // إذا تم redirect إلى صفحة تسجيل الدخول، نعيد التوجيه الكامل للصفحة
            if (isLoginPage && response.redirected) {
                hideLoading();
                isLoading = false;
                window.location.href = responseUrl;
                return false;
            }

            const html = await response.text();
            const data = extractContent(html);

            if (!data) {
                // إذا فشل استخراج المحتوى وكانت هناك redirect إلى صفحة تسجيل الدخول، نعيد التوجيه الكامل
                if (response.redirected && isLoginPage) {
                    hideLoading();
                    isLoading = false;
                    window.location.href = responseUrl;
                    return false;
                }
                throw new Error('Failed to extract content from response');
            }

            // حفظ في Cache
            if (CONFIG.cacheEnabled) {
                // تنظيف Cache إذا تجاوز الحد الأقصى
                if (pageCache.size >= CONFIG.cacheMaxSize) {
                    const firstKey = pageCache.keys().next().value;
                    pageCache.delete(firstKey);
                }
                pageCache.set(url, data);
            }

            // تحديث الصفحة
            updatePageContent(data);
            updateHistory(url);
            currentUrl = url;

            return true;
        } catch (error) {
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
            console.error('AJAX navigation error:', error);
            
            // إخفاء loading قبل fallback
            hideLoading();
            
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

        // التحقق من أن الرابط في نفس النطاق
        try {
            const linkUrl = new URL(link.href, window.location.origin);
            const currentUrlObj = new URL(window.location.href);
            
            if (linkUrl.origin !== currentUrlObj.origin) {
                return false;
            }

            // التحقق من أن الرابط يحتوي على dashboard أو page parameter
            if (linkUrl.pathname.includes('/dashboard/') || linkUrl.searchParams.has('page')) {
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
        if (!link || !shouldInterceptLink(link)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const url = link.href;
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
        updateSidebarActiveState();

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

    // تصدير API عام
    window.AjaxNavigation = {
        load: loadPage,
        clearCache: () => pageCache.clear(),
        isEnabled: () => true
    };
})();


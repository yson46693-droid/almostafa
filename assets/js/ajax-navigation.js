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

        // تنفيذ scripts المدمجة في المحتوى
        // يجب تنفيذ scripts بعد تحديث innerHTML مباشرة
        const scripts = Array.from(mainElement.querySelectorAll('script'));
        scripts.forEach((oldScript, index) => {
            // استخدام setTimeout لتنفيذ scripts بشكل متسلسل
            setTimeout(() => {
                const newScript = document.createElement('script');
                
                // نسخ جميع attributes
                Array.from(oldScript.attributes).forEach(attr => {
                    newScript.setAttribute(attr.name, attr.value);
                });
                
                if (oldScript.src) {
                    // Script خارجي - تحميله
                    newScript.src = oldScript.src;
                    document.head.appendChild(newScript);
                } else {
                    // Script مدمج - تنفيذه مباشرة
                    // استخدام eval أو Function لتجنب مشاكل scope
                    try {
                        newScript.textContent = oldScript.textContent;
                        document.head.appendChild(newScript);
                        // إزالة script بعد التنفيذ لتجنب التكرار
                        setTimeout(() => {
                            if (newScript.parentNode) {
                                newScript.parentNode.removeChild(newScript);
                            }
                        }, 100);
                    } catch (e) {
                        console.error('Error executing inline script:', e);
                        // Fallback: استخدام eval مباشرة
                        try {
                            eval(oldScript.textContent);
                        } catch (evalError) {
                            console.error('Error with eval fallback:', evalError);
                        }
                    }
                }
                
                // إزالة الـ script القديم من المحتوى
                if (oldScript.parentNode) {
                    oldScript.parentNode.removeChild(oldScript);
                }
            }, index * 10); // تأخير بسيط بين كل script
        });

        // تحديث حالة active في الشريط الجانبي - مع تأخير بسيط لضمان اكتمال تحديث DOM
        // استخدام requestAnimationFrame لضمان تحديث DOM قبل تحديث حالة active
        requestAnimationFrame(() => {
            updateSidebarActiveState();
        });

        // إعادة تهيئة الأحداث - مع تأخير أكبر لضمان تنفيذ scripts
        setTimeout(() => {
            reinitializeEvents();
        }, 100);

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
            window.initPageEvents();
        }

        // إعادة تهيئة جداول البيانات
        if (typeof window.initDataTables === 'function') {
            window.initDataTables();
        }
        
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
     * تحميل الصفحة عبر AJAX
     */
    async function loadPage(url) {
        if (isLoading) {
            return false;
        }

        // التحقق من Cache
        if (CONFIG.cacheEnabled && pageCache.has(url)) {
            const cachedData = pageCache.get(url);
            // تحديث URL أولاً لتحديث حالة active بشكل صحيح
            currentUrl = url;
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

            // تحديث URL أولاً
            currentUrl = url;
            // تحديث الصفحة
            updatePageContent(data);
            updateHistory(url);

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


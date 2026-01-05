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
    let loadingTimeoutId = null;
    const LOADING_DELAY = 300; // تأخير 300ms قبل إظهار loading dialog

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
                    const scriptContent = oldScript.textContent;
                    
                    // إزالة script القديم من DOM أولاً
                    if (oldScript.parentNode) {
                        oldScript.parentNode.removeChild(oldScript);
                    }
                    
                    // تنفيذ المحتوى مباشرة
                    if (scriptContent && scriptContent.trim()) {
                        try {
                            // استخدام Function constructor (أكثر أماناً من eval)
                            const scriptFunction = new Function(scriptContent);
                            scriptFunction();
                        } catch (functionError) {
                            // Fallback: استخدام eval إذا فشل Function constructor
                            try {
                                eval(scriptContent);
                            } catch (evalError) {
                                console.error('Error executing inline script:', evalError);
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
                const newScript = document.createElement('script');
                
                // نسخ جميع attributes
                Array.from(oldScript.attributes).forEach(attr => {
                    newScript.setAttribute(attr.name, attr.value);
                });
                
                // معالجة الأخطاء
                newScript.onload = function() {
                    // Script تم تحميله بنجاح
                };
                newScript.onerror = function() {
                    console.warn('Failed to load script:', oldScript.src);
                };
                
                newScript.src = oldScript.src;
                
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

        // إعادة تهيئة الأحداث - مع تأخير أكبر لضمان تنفيذ scripts
        // حساب الوقت المطلوب بناءً على عدد scripts
        const totalScriptsDelay = (inlineScripts.length * 30) + (externalScripts.length * 100);
        const reinitDelay = Math.max(200, totalScriptsDelay + 100);
        
        // إعادة تهيئة الأحداث على مراحل لضمان اكتمال جميع العمليات
        setTimeout(() => {
            reinitializeEvents();
            // إعادة تهيئة جميع الأحداث بشكل شامل
            reinitializeAllEvents();
        }, reinitDelay);
        
        // إعادة تهيئة إضافية بعد تأخير أكبر لضمان عمل جميع الأزرار
        setTimeout(() => {
            // إعادة تهيئة جميع Bootstrap components مرة أخرى
            if (typeof bootstrap !== 'undefined') {
                // إعادة تهيئة Dropdowns
                const allDropdowns = document.querySelectorAll('[data-bs-toggle="dropdown"]');
                allDropdowns.forEach(dropdown => {
                    try {
                        const instance = bootstrap.Dropdown.getInstance(dropdown);
                        if (!instance) {
                            new bootstrap.Dropdown(dropdown);
                        }
                    } catch (e) {
                        // تجاهل الأخطاء
                    }
                });
                
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
        // إعادة تهيئة Bootstrap Dropdowns في الشريط العلوي
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            const topbarDropdowns = document.querySelectorAll('.homeline-topbar [data-bs-toggle="dropdown"]');
            topbarDropdowns.forEach(dropdown => {
                try {
                    // إزالة instance القديم إن وجد
                    const oldInstance = bootstrap.Dropdown.getInstance(dropdown);
                    if (oldInstance) {
                        oldInstance.dispose();
                    }
                    // إنشاء instance جديد
                    new bootstrap.Dropdown(dropdown);
                } catch (e) {
                    console.warn('Error reinitializing topbar dropdown:', e);
                }
            });
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

        // إعادة تهيئة زر إعادة التحميل على الموبايل
        const mobileReloadBtn = document.getElementById('mobileReloadBtn');
        if (mobileReloadBtn) {
            // إزالة event listeners القديمة (إذا أمكن)
            const newReloadBtn = mobileReloadBtn.cloneNode(true);
            if (mobileReloadBtn.parentNode) {
                mobileReloadBtn.parentNode.replaceChild(newReloadBtn, mobileReloadBtn);
            }
            // إعادة ربط event listener
            newReloadBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.location.reload();
            });
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

        // إعادة تهيئة زر الوضع الداكن على الموبايل
        const mobileDarkToggle = document.getElementById('mobileDarkToggle');
        if (mobileDarkToggle) {
            // إزالة event listeners القديمة
            const newDarkToggle = mobileDarkToggle.cloneNode(true);
            if (mobileDarkToggle.parentNode) {
                mobileDarkToggle.parentNode.replaceChild(newDarkToggle, mobileDarkToggle);
            }
            // إعادة ربط event listener
            newDarkToggle.addEventListener('click', function(e) {
                e.preventDefault();
                const currentTheme = localStorage.getItem('theme') || 'light';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                localStorage.setItem('theme', newTheme);
                document.documentElement.setAttribute('data-theme', newTheme);
                if (newTheme === 'dark') {
                    document.documentElement.classList.add('dark-theme');
                } else {
                    document.documentElement.classList.remove('dark-theme');
                }
                window.dispatchEvent(new CustomEvent('themeChange', { detail: { theme: newTheme } }));
            });
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

        // التأكد من أن جميع العناصر في الشريط العلوي قابلة للنقر
        const topbarActions = document.querySelectorAll('.homeline-topbar .topbar-action, .homeline-topbar button, .homeline-topbar a');
        topbarActions.forEach(action => {
            if (action.style.pointerEvents === 'none') {
                action.style.pointerEvents = '';
            }
            if (action.disabled) {
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
        // 1. إعادة تهيئة Bootstrap Dropdowns
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            const dropdowns = document.querySelectorAll('[data-bs-toggle="dropdown"]');
            dropdowns.forEach(dropdown => {
                try {
                    const oldInstance = bootstrap.Dropdown.getInstance(dropdown);
                    if (oldInstance) {
                        oldInstance.dispose();
                    }
                    new bootstrap.Dropdown(dropdown);
                } catch (e) {
                    console.warn('Error reinitializing dropdown:', e);
                }
            });
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

        // 4. إعادة تهيئة زر إعادة التحميل على الموبايل
        const mobileReloadBtn = document.getElementById('mobileReloadBtn');
        if (mobileReloadBtn) {
            // إزالة event listeners القديمة
            const newReloadBtn = mobileReloadBtn.cloneNode(true);
            if (mobileReloadBtn.parentNode) {
                mobileReloadBtn.parentNode.replaceChild(newReloadBtn, mobileReloadBtn);
            }
            // إعادة ربط event listener
            newReloadBtn.addEventListener('click', function(e) {
                e.preventDefault();
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('_refresh', Date.now().toString());
                    window.location.href = url.toString();
                } catch (err) {
                    window.location.reload();
                }
            });
        }

        // 5. إعادة تهيئة زر الوضع الداكن على الموبايل
        const mobileDarkToggle = document.getElementById('mobileDarkToggle');
        if (mobileDarkToggle) {
            // إزالة event listeners القديمة
            const newDarkToggle = mobileDarkToggle.cloneNode(true);
            if (mobileDarkToggle.parentNode) {
                mobileDarkToggle.parentNode.replaceChild(newDarkToggle, mobileDarkToggle);
            }
            // دالة لتحديث الأيقونة
            function updateMobileDarkIcon(theme) {
                const icon = newDarkToggle.querySelector('i');
                if (!icon) return;
                if ((theme || '').toLowerCase() === 'dark') {
                    icon.classList.remove('bi-moon-stars');
                    icon.classList.add('bi-brightness-high');
                } else {
                    icon.classList.remove('bi-brightness-high');
                    icon.classList.add('bi-moon-stars');
                }
            }
            // تحديث الأيقونة حسب الوضع الحالي
            const currentTheme = document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light';
            updateMobileDarkIcon(currentTheme);
            // إعادة ربط event listener
            newDarkToggle.addEventListener('click', function(e) {
                e.preventDefault();
                const currentTheme = localStorage.getItem('theme') || 'light';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                localStorage.setItem('theme', newTheme);
                document.documentElement.setAttribute('data-theme', newTheme);
                if (newTheme === 'dark') {
                    document.documentElement.classList.add('dark-theme');
                } else {
                    document.documentElement.classList.remove('dark-theme');
                }
                updateMobileDarkIcon(newTheme);
                window.dispatchEvent(new CustomEvent('themeChange', { detail: { theme: newTheme } }));
            });
            // الاستماع لتغييرات الوضع الداكن
            window.addEventListener('themeChange', function(e) {
                const theme = e && e.detail && e.detail.theme ? e.detail.theme : (document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light');
                updateMobileDarkIcon(theme);
            });
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
                new bootstrap.Dropdown(notificationDropdown);
            } catch (e) {
                console.warn('Error reinitializing notifications dropdown:', e);
            }
            
            // إعادة ربط event listener لطلب الإذن
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
        // إعادة تهيئة الأحداث بعد تأخير بسيط لضمان اكتمال تحديث DOM
        setTimeout(() => {
            reinitializeAllEvents();
        }, 100);
        
        // إعادة تهيئة إضافية بعد تأخير أكبر لضمان اكتمال جميع العمليات
        setTimeout(() => {
            reinitializeAllEvents();
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


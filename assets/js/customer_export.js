/**
 * Customer Export to Excel
 * Handles customer selection modal, Excel generation, print, download, and share functionality
 */

(function() {
    'use strict';
    
    // متغيرات عامة
    let selectedCustomers = [];
    let collectionAmounts = {};
    let currentSection = 'company'; // 'company' or 'delegates'
    let generatedFileUrl = null;
    let generatedFilePath = null;
    
    // متغيرات pagination للعملاء المحليين
    let localCustomersPage = 1;
    let localCustomersTotalPages = 1;
    let localCustomersTotal = 0;
    let allLocalCustomers = []; // لتخزين جميع العملاء المحددين عبر الصفحات
    
    // متغيرات pagination لعملاء المندوبين
    let repCustomersPage = 1;
    let repCustomersTotalPages = 1;
    let repCustomersTotal = 0;
    let allRepCustomers = []; // لتخزين جميع العملاء المحددين عبر الصفحات
    let currentRepId = null; // لتخزين معرف المندوب الحالي
    
    /**
     * التحقق من الموبايل
     */
    function isMobile() {
        return window.innerWidth <= 768;
    }
    
    /**
     * الحصول على Container الصحيح (Modal أو Card) حسب الجهاز
     */
    function getExportContainer() {
        if (isMobile()) {
            // على الموبايل: البحث عن Card
            const card = document.getElementById('customerExportCard');
            if (card) {
                return card;
            }
        }
        // على الكمبيوتر: البحث عن Modal
        return document.getElementById('customerExportModal');
    }
    
    /**
     * الحصول على عنصر داخل Container الصحيح
     * يبحث في Modal أو Card حسب الجهاز
     */
    function getExportElement(elementId) {
        const container = getExportContainer();
        if (container) {
            // البحث داخل Container أولاً
            const element = container.querySelector('#' + elementId);
            if (element) {
                return element;
            }
        }
        // Fallback: البحث في كامل الصفحة
        return document.getElementById(elementId);
    }
    
    /**
     * حساب مسار API بناءً على موقع الصفحة الحالية
     * يستخدم CUSTOMER_EXPORT_CONFIG من PHP إذا كان متاحاً
     */
    function getApiPath(apiFile) {
        // أولاً: محاولة استخدام المسار من PHP إذا كان متاحاً
        if (window.CUSTOMER_EXPORT_CONFIG && window.CUSTOMER_EXPORT_CONFIG.apiBasePath) {
            const apiBase = window.CUSTOMER_EXPORT_CONFIG.apiBasePath;
            const cleanApiFile = apiFile.replace(/^\/+/, '');
            return (apiBase + '/' + cleanApiFile).replace(/\/+/g, '/');
        }
        
        // ثانياً: استخدام basePath من PHP إذا كان متاحاً
        if (window.CUSTOMER_EXPORT_CONFIG && window.CUSTOMER_EXPORT_CONFIG.basePath) {
            const basePath = window.CUSTOMER_EXPORT_CONFIG.basePath;
            const cleanApiFile = apiFile.replace(/^\/+/, '');
            return (basePath + '/api/' + cleanApiFile).replace(/\/+/g, '/');
        }
        
        // ثالثاً: حساب المسار ديناميكياً (fallback)
        const pathname = window.location.pathname;
        let basePath = pathname;
        
        // إزالة /dashboard أو /modules/... من المسار
        basePath = basePath.replace(/\/dashboard\/?$/, '');
        basePath = basePath.replace(/\/modules\/[^\/]+\.php$/, '');
        basePath = basePath.replace(/\/modules\/[^\/]+$/, '');
        
        // إزالة أي ملف PHP من نهاية المسار
        basePath = basePath.replace(/\/[^\/]+\.php$/, '');
        
        // إذا كان المسار يحتوي على /dashboard/، أزل /dashboard
        if (basePath.includes('/dashboard')) {
            basePath = basePath.replace(/\/dashboard.*$/, '');
        }
        
        // إذا كان المسار يحتوي على /modules/، أزل /modules وكل ما بعده
        if (basePath.includes('/modules')) {
            basePath = basePath.replace(/\/modules.*$/, '');
        }
        
        // التأكد من أن المسار يبدأ بـ /
        if (!basePath.startsWith('/')) {
            basePath = '/' + basePath;
        }
        
        // إزالة / من النهاية
        basePath = basePath.replace(/\/+$/, '');
        
        // إذا كان المسار فارغاً أو فقط /، استخدم المسار الجذري
        if (!basePath || basePath === '/') {
            basePath = '';
        }
        
        // إرجاع المسار الكامل
        const cleanApiFile = apiFile.replace(/^\/+/, '');
        return (basePath + '/api/' + cleanApiFile).replace(/\/+/g, '/');
    }
    
    /**
     * تهيئة المودال عند تحميل الصفحة
     */
    function initExportModal() {
        const exportModal = document.getElementById('customerExportModal');
        const exportCard = document.getElementById('customerExportCard');
        
        // تهيئة Modal إذا كان موجوداً
        if (exportModal) {
            initModalEvents(exportModal);
        }
        
        // تهيئة Card إذا كان موجوداً
        if (exportCard) {
            initCardEvents(exportCard);
        }
    }
    
    /**
     * تهيئة أحداث Modal
     */
    function initModalEvents(exportModal) {
        if (!exportModal) {
            return;
        }
        
        // ضمان أن المودال قابل للتفاعل دائماً - فوري بدون تأخير
        const ensureModalInteractive = function() {
            // استخدام requestAnimationFrame للتأكد من التطبيق الفوري
            requestAnimationFrame(function() {
                // ضمان أن المودال نفسه قابل للتفاعل
                exportModal.style.pointerEvents = 'auto';
                exportModal.style.zIndex = '1055';
                exportModal.style.position = 'fixed';
                
                const modalDialog = exportModal.querySelector('.modal-dialog');
                if (modalDialog) {
                    modalDialog.style.pointerEvents = 'auto';
                    modalDialog.style.zIndex = '1056';
                    modalDialog.style.position = 'relative';
                }
                
                const modalContent = exportModal.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.style.pointerEvents = 'auto';
                    modalContent.style.zIndex = '1057';
                    modalContent.style.position = 'relative';
                }
                
                // ضمان أن جميع العناصر داخل المودال قابلة للتفاعل
                const allElements = exportModal.querySelectorAll('*');
                allElements.forEach(function(el) {
                    el.style.pointerEvents = 'auto';
                });
                
                // ضمان أن backdrop تحت المودال
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(function(backdrop) {
                    backdrop.style.zIndex = '1054';
                });
            });
        };
        
        // إزالة backdrop إضافي
        const removeExtraBackdrops = function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 1) {
                for (let i = 1; i < backdrops.length; i++) {
                    backdrops[i].remove();
                }
            }
        };
        
        // عند بدء فتح المودال - فوري جداً
        exportModal.addEventListener('show.bs.modal', function() {
            // ضمان أن المودال قابل للتفاعل فوراً - بدون أي تأخير
            ensureModalInteractive();
            removeExtraBackdrops();
            
            // استدعاء فوري مرة أخرى للتأكد
            requestAnimationFrame(function() {
                ensureModalInteractive();
                removeExtraBackdrops();
            });
        });
        
        // استدعاء فوري عند إضافة class "show" للمودال
        const modalObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    if (exportModal.classList.contains('show') || exportModal.classList.contains('showing')) {
                        ensureModalInteractive();
                        removeExtraBackdrops();
                    }
                }
            });
        });
        
        modalObserver.observe(exportModal, {
            attributes: true,
            attributeFilter: ['class']
        });
        
        // مراقبة أي backdrop جديد يظهر
        let backdropObserver = null;
        
        exportModal.addEventListener('show.bs.modal', function() {
            // بدء المراقبة عند فتح المودال
            backdropObserver = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1 && node.classList && node.classList.contains('modal-backdrop')) {
                            // ضمان أن backdrop جديد تحت المودال
                            node.style.zIndex = '1054';
                            removeExtraBackdrops();
                            ensureModalInteractive();
                        }
                    });
                });
            });
            
            backdropObserver.observe(document.body, {
                childList: true,
                subtree: true
            });
        });
        
        // إيقاف المراقبة عند إغلاق المودال
        exportModal.addEventListener('hidden.bs.modal', function() {
            if (backdropObserver) {
                backdropObserver.disconnect();
                backdropObserver = null;
            }
        });
        
        // عند اكتمال فتح المودال - التأكد من أن المودال قابل للتفاعل
        exportModal.addEventListener('shown.bs.modal', function() {
            ensureModalInteractive();
            removeExtraBackdrops();
            
            // التأكد مرة أخرى بعد تأخير قصير جداً
            requestAnimationFrame(function() {
                ensureModalInteractive();
                removeExtraBackdrops();
            });
        });
        
        // تنظيف backdrop المتبقي فقط عند إغلاق المودال
        exportModal.addEventListener('hidden.bs.modal', function() {
            // تنظيف backdrop المتبقي فقط إذا كان هناك أكثر من واحد
            setTimeout(function() {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    for (let i = 1; i < backdrops.length; i++) {
                        backdrops[i].remove();
                    }
                }
                // إزالة class modal-open فقط إذا لم يكن هناك modals أخرى مفتوحة
                const openModals = document.querySelectorAll('.modal.show');
                if (openModals.length === 0) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            }, 100);
        });
        
        // إعادة تعيين عند فتح المودال (بعد تنظيف backdrop)
        exportModal.addEventListener('shown.bs.modal', function() {
            resetExportModal();
            
            // تحديد القسم الحالي من data attribute
            const currentSection = exportModal.getAttribute('data-section') || '';
            
            // التحقق من وجود hidden input للمندوب (إذا كان المستخدم مندوب)
            const repSelect = document.getElementById('exportRepSelect');
            const isSalesUser = repSelect && repSelect.tagName === 'INPUT' && repSelect.value && repSelect.value !== '';
            
            // إذا كان المستخدم مندوب (sales user)، يجب جلب عملائه من API وليس عملاء الشركة
            if (isSalesUser) {
                const repId = parseInt(repSelect.value, 10);
                if (repId > 0) {
                    loadCustomersByRep(repId);
                    return;
                }
            }
            
            // إذا كان قسم عملاء الشركة (وليس مندوب)، جلب عملاء الشركة مباشرة
            if (currentSection === 'company') {
                loadCompanyCustomers();
                return;
            }
            
            // إذا كان قسم العملاء المحليين، جلب العملاء المحليين مباشرة
            if (currentSection === 'local') {
                loadLocalCustomers();
                return;
            }
            
            // إذا كان قسم عملاء المندوبين (delegates)، انتظر اختيار المندوب
            if (currentSection === 'delegates') {
                // إظهار رسالة اختيار المندوب
                showSelectRepMessage();
                return;
            }
            
            // إذا كان هناك اختيار مندوب، انتظر اختياره
            if (repSelect && repSelect.tagName === 'SELECT') {
                // إظهار رسالة اختيار المندوب
                showSelectRepMessage();
            } else {
                // إذا كان هناك معرف مندوب في hidden input (لحالات أخرى)
                const repId = repSelect ? repSelect.value : null;
                if (repId && repId !== '') {
                    loadCustomersByRep(parseInt(repId, 10));
                } else {
                    // إذا لم يكن هناك معرف مندوب، إظهار رسالة اختيار المندوب
                    showSelectRepMessage();
                }
            }
        });
        
        // ربط حدث اختيار المندوب بدون debounce - فوري على الهواتف
        const repSelect = document.getElementById('exportRepSelect');
        if (repSelect && repSelect.tagName === 'SELECT') {
            repSelect.addEventListener('change', function() {
                const selectEl = this;
                const repId = parseInt(selectEl.value, 10);
                
                if (repId > 0) {
                    // تعطيل الـ select أثناء التحميل
                    selectEl.disabled = true;
                    
                    // إظهار loading فوراً
                    const selectRepMessage = getExportElement('selectRepMessage');
                    const customersSection = getExportElement('customersSection');
                    if (selectRepMessage) {
                        selectRepMessage.style.display = 'block';
                        selectRepMessage.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل عملاء المندوب...';
                    }
                    if (customersSection) {
                        customersSection.style.display = 'none';
                    }
                    
                    // تحميل فوري بدون debounce
                    loadCustomersByRep(repId).finally(() => {
                        selectEl.disabled = false;
                    });
                } else {
                    showSelectRepMessage();
                }
            });
        }
        
        // ربط أحداث الأزرار
        const generateBtn = document.getElementById('generateExcelBtn');
        const generateBtnHeader = document.getElementById('generateExcelBtnHeader');
        const printBtn = document.getElementById('printExcelBtn');
        const selectAllBtn = document.getElementById('selectAllCustomers');
        const deselectAllBtn = document.getElementById('deselectAllCustomers');
        
        if (generateBtn) {
            generateBtn.addEventListener('click', handleGenerateExcel);
        }
        
        if (generateBtnHeader) {
            generateBtnHeader.addEventListener('click', handleGenerateExcel);
        }
        
        if (printBtn) {
            printBtn.addEventListener('click', handlePrintExcel);
        }
        
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                selectAllCustomers(true);
            });
        }
        
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function() {
                selectAllCustomers(false);
            });
        }
        
        const selectAllAndGenerateBtn = document.getElementById('selectAllCustomersAndGenerate');
        if (selectAllAndGenerateBtn) {
            selectAllAndGenerateBtn.addEventListener('click', function() {
                selectAllCustomersAndGenerate();
            });
        }
    }
    
    /**
     * تهيئة أحداث Card (للموبايل)
     */
    function initCardEvents(exportCard) {
        if (!exportCard) {
            return;
        }
        
        // ربط أحداث الأزرار - نفس الأحداث في Modal
        const generateBtn = exportCard.querySelector('#generateExcelBtn');
        const printBtn = exportCard.querySelector('#printExcelBtn');
        const selectAllBtn = exportCard.querySelector('#selectAllCustomers');
        const deselectAllBtn = exportCard.querySelector('#deselectAllCustomers');
        
        if (generateBtn) {
            generateBtn.addEventListener('click', handleGenerateExcel);
        }
        
        if (printBtn) {
            printBtn.addEventListener('click', handlePrintExcel);
        }
        
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                selectAllCustomers(true);
            });
        }
        
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function() {
                selectAllCustomers(false);
            });
        }
        
        const selectAllAndGenerateBtn = exportCard.querySelector('#selectAllCustomersAndGenerate');
        if (selectAllAndGenerateBtn) {
            selectAllAndGenerateBtn.addEventListener('click', function() {
                selectAllCustomersAndGenerate();
            });
        }
        
        // عند فتح Card (من خلال MutationObserver أو event مخصص)
        // سنستخدم MutationObserver لمراقبة تغيير display style
        const cardObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    const isVisible = exportCard.style.display !== 'none';
                    if (isVisible) {
                        // Card أصبح مرئياً - إعادة تعيين وتحويل البيانات
                        resetExportModal();
                        
                        // تحديد القسم الحالي من data attribute
                        const currentSection = exportCard.getAttribute('data-section') || 'local';
                        
                        // جلب العملاء بناءً على القسم
                        if (currentSection === 'company') {
                            loadCompanyCustomers();
                        } else if (currentSection === 'local') {
                            loadLocalCustomers();
                        }
                    }
                }
            });
        });
        
        cardObserver.observe(exportCard, {
            attributes: true,
            attributeFilter: ['style']
        });
    }
    
    /**
     * دالة مساعدة لتحديث كلا زرين التوليد (في الـ header والـ footer)
     */
    function updateGenerateButtons(callback) {
        const generateBtn = getExportElement('generateExcelBtn');
        const generateBtnHeader = document.getElementById('generateExcelBtnHeader'); // Header موجود فقط في Modal
        
        if (generateBtn && callback) {
            callback(generateBtn);
        }
        
        if (generateBtnHeader && callback) {
            callback(generateBtnHeader);
        }
    }
    
    /**
     * إعادة تعيين المودال
     */
    function resetExportModal() {
        // إلغاء أي طلبات جارية
        if (currentLoadAbortController) {
            currentLoadAbortController.abort();
            currentLoadAbortController = null;
        }
        
        selectedCustomers = [];
        collectionAmounts = {};
        generatedFileUrl = null;
        generatedFilePath = null;
        
        // إعادة تعيين pagination
        localCustomersPage = 1;
        localCustomersTotalPages = 1;
        localCustomersTotal = 0;
        allLocalCustomers = [];
        
        // إعادة تعيين pagination لعملاء المندوبين
        repCustomersPage = 1;
        repCustomersTotalPages = 1;
        repCustomersTotal = 0;
        allRepCustomers = [];
        currentRepId = null;
        
        const customersList = getExportElement('exportCustomersList');
        if (customersList) {
            customersList.innerHTML = '';
        }
        
        const customersSection = getExportElement('customersSection');
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        const selectRepMessage = getExportElement('selectRepMessage');
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
        }
        
        // إخفاء أزرار الطباعة والتحميل والمشاركة
        const actionButtons = getExportElement('exportActionButtons');
        if (actionButtons) {
            actionButtons.style.display = 'none';
        }
        
        // إظهار زر التوليد
        updateGenerateButtons(function(btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-file-earmark-excel me-1"></i>توليد ملف Excel';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-primary');
        });
        
        // إعادة تعيين اختيار المندوب
        const repSelect = document.getElementById('exportRepSelect');
        if (repSelect && repSelect.tagName === 'SELECT') {
            repSelect.value = '';
            repSelect.disabled = false;
        }
    }
    
    /**
     * إظهار رسالة اختيار المندوب
     */
    function showSelectRepMessage() {
        const customersSection = getExportElement('customersSection');
        const selectRepMessage = getExportElement('selectRepMessage');
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<i class="bi bi-info-circle me-2"></i>يرجى اختيار المندوب أولاً لعرض عملائه';
        }
        
        updateGenerateButtons(function(btn) {
            btn.disabled = true;
        });
    }
    
    /**
     * جلب العملاء المحليين عبر API مع pagination
     */
    async function loadLocalCustomers(page = 1) {
        const customersList = getExportElement('exportCustomersList');
        const customersSection = getExportElement('customersSection');
        const selectRepMessage = getExportElement('selectRepMessage');
        
        if (!customersList) {
            return;
        }
        
        // إظهار رسالة التحميل
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل العملاء المحليين المدينين...';
        }
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        try {
            const response = await fetch(getApiPath('get_local_customers_for_export.php') + '?page=' + page, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'فشل في جلب العملاء المحليين');
            }
            
            // تحديث معلومات pagination
            localCustomersPage = result.page || page;
            localCustomersTotalPages = result.total_pages || 1;
            localCustomersTotal = result.total || 0;
            
            // عرض قائمة العملاء مع pagination
            displayCustomersList(result.customers || [], {
                hasPagination: true,
                currentPage: localCustomersPage,
                totalPages: localCustomersTotalPages,
                total: localCustomersTotal,
                onPageChange: loadLocalCustomers
            });
            
        } catch (error) {
            console.error('Load local customers error:', error);
            if (selectRepMessage) {
                selectRepMessage.style.display = 'block';
                selectRepMessage.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء جلب العملاء المحليين: ' + escapeHtml(error.message) + '</div>';
            }
        }
    }
    
    /**
     * جلب عملاء الشركة عبر API
     */
    async function loadCompanyCustomers() {
        const customersList = getExportElement('exportCustomersList');
        const customersSection = getExportElement('customersSection');
        const selectRepMessage = getExportElement('selectRepMessage');
        
        if (!customersList) {
            return;
        }
        
        // إظهار رسالة التحميل
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل عملاء الشركة...';
        }
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        try {
            const response = await fetch(getApiPath('get_company_customers_for_export.php'), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'فشل في جلب عملاء الشركة');
            }
            
            // عرض قائمة العملاء (ستقوم الدالة بإخفاء/إظهار الأقسام حسب الحاجة)
            displayCustomersList(result.customers || []);
            
        } catch (error) {
            console.error('Load company customers error:', error);
            if (selectRepMessage) {
                selectRepMessage.style.display = 'block';
                selectRepMessage.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء جلب عملاء الشركة: ' + escapeHtml(error.message) + '</div>';
            }
        }
    }
    
    // متغير لحفظ آخر AbortController
    let currentLoadAbortController = null;
    
    /**
     * جلب عملاء المندوب عبر API مع pagination
     */
    async function loadCustomersByRep(repId, page = 1) {
        const customersList = getExportElement('exportCustomersList');
        const customersSection = getExportElement('customersSection');
        const selectRepMessage = getExportElement('selectRepMessage');
        
        if (!customersList || !repId || repId <= 0) {
            return;
        }
        
        // إلغاء الطلب السابق إذا كان موجوداً
        if (currentLoadAbortController) {
            currentLoadAbortController.abort();
            currentLoadAbortController = null;
        }
        
        // إنشاء AbortController جديد
        currentLoadAbortController = new AbortController();
        
        // حفظ معرف المندوب الحالي
        if (currentRepId !== repId) {
            // إذا تغير المندوب، إعادة تعيين pagination
            repCustomersPage = 1;
            repCustomersTotalPages = 1;
            repCustomersTotal = 0;
            allRepCustomers = [];
            currentRepId = repId;
            page = 1; // إعادة تعيين الصفحة
        }
        
        // إظهار رسالة التحميل
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل عملاء المندوب...';
        }
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        // مسح قائمة العملاء السابقة
        if (customersList) {
            customersList.innerHTML = '';
        }
        
        // بدء قياس الوقت للتشخيص
        const startTime = performance.now();
        
        try {
            const apiUrl = `${getApiPath('get_rep_customers_for_export.php')}?rep_id=${repId}&page=${page}&_t=${Date.now()}`;
            
            // استخدام AbortController مع timeout أقصر
            const timeoutId = setTimeout(() => {
                if (currentLoadAbortController) {
                    currentLoadAbortController.abort();
                }
            }, 15000); // 15 ثانية timeout (تقليل من 30)
            
            const response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                },
                signal: currentLoadAbortController.signal,
                cache: 'no-store',
                credentials: 'same-origin'
            });
            
            clearTimeout(timeoutId);
            
            // التحقق من نوع الاستجابة
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('استجابة غير صحيحة من الخادم');
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'فشل في جلب عملاء المندوب');
            }
            
            // تحديث معلومات pagination
            repCustomersPage = result.page || page;
            repCustomersTotalPages = result.total_pages || 1;
            repCustomersTotal = result.total || 0;
            
            // التحقق من صحة البيانات القادمة من API
            const customers = result.customers || [];
            if (!Array.isArray(customers)) {
                console.warn('API returned invalid customers data:', customers);
                displayCustomersList([], {
                    hasPagination: true,
                    currentPage: repCustomersPage,
                    totalPages: repCustomersTotalPages,
                    total: repCustomersTotal,
                    onPageChange: function(newPage) {
                        loadCustomersByRep(repId, newPage);
                    }
                });
                return;
            }
            
            // استخدام البيانات مباشرة بدون فلترة إضافية - API يضمن صحة البيانات
            const validCustomers = customers;
            
            // قياس الوقت المستغرق
            const loadTime = performance.now() - startTime;
            if (loadTime > 1000) {
                console.warn('Slow load time:', loadTime.toFixed(2), 'ms');
            }
            
            // عرض قائمة العملاء مع pagination مباشرة - بدون setTimeout
            displayCustomersList(validCustomers, {
                hasPagination: true,
                currentPage: repCustomersPage,
                totalPages: repCustomersTotalPages,
                total: repCustomersTotal,
                onPageChange: function(newPage) {
                    loadCustomersByRep(repId, newPage);
                }
            });
            
        } catch (error) {
            // تجاهل الأخطاء الناتجة عن إلغاء الطلب
            if (error.name === 'AbortError') {
                return;
            }
            
            console.error('Load customers error:', error);
            if (selectRepMessage) {
                selectRepMessage.style.display = 'block';
                selectRepMessage.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء جلب عملاء المندوب: ' + escapeHtml(error.message) + '</div>';
            }
        } finally {
            // تنظيف AbortController
            if (currentLoadAbortController) {
                currentLoadAbortController = null;
            }
        }
    }
    
    /**
     * عرض قائمة العملاء في الجدول مع دعم pagination
     */
    function displayCustomersList(customers, paginationOptions = null) {
        const customersList = getExportElement('exportCustomersList');
        const customersSection = getExportElement('customersSection');
        const selectRepMessage = getExportElement('selectRepMessage');
        
        if (!customersList) {
            return;
        }
        
        // استخدام البيانات مباشرة بدون فلترة - API يضمن صحة البيانات
        const validCustomers = Array.isArray(customers) ? customers : [];
        
        // إذا لم يكن هناك عملاء
        if (validCustomers.length === 0 && (!paginationOptions || paginationOptions.currentPage === 1)) {
            // إخفاء قسم العملاء
            if (customersSection) {
                customersSection.style.display = 'none';
            }
            
            // إظهار رسالة في المكان المناسب
            if (selectRepMessage) {
                selectRepMessage.style.display = 'block';
                selectRepMessage.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>لا توجد عملاء لديهم رصيد مدين متاحة للتصدير</div>';
            } else {
                customersList.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>لا توجد عملاء لديهم رصيد مدين متاحة للتصدير</div>';
            }
            
            // تعطيل زر التوليد
            updateGenerateButtons(function(btn) {
                btn.disabled = true;
            });
            return;
        }
        
        // إخفاء رسالة اختيار المندوب أولاً
        if (selectRepMessage) {
            selectRepMessage.style.display = 'none';
        }
        
        // إظهار قسم العملاء - استخدام force reflow لضمان العرض
        if (customersSection) {
            customersSection.style.display = 'block';
            // Force reflow لضمان العرض على الهواتف
            customersSection.offsetHeight;
        }
        
        // إنشاء جدول العملاء
        let html = '<table class="table table-sm table-hover mb-0">';
        html += '<thead class="table-light"><tr>';
        html += '<th style="width: 40px;"><input type="checkbox" id="exportSelectAllCheckbox" class="form-check-input"></th>';
        html += '<th>اسم العميل</th>';
        html += '<th>رقم الهاتف</th>';
        html += '<th>العنوان</th>';
        html += '<th>المنطقة</th>';
        html += '<th>الرصيد</th>';
        html += '<th>المبلغ المراد تحصيله</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        validCustomers.forEach(function(customer) {
            const customerId = parseInt(customer.id, 10);
            const customerName = escapeHtml(customer.name.trim());
            const phone = escapeHtml((customer.phone || '').trim() || '-');
            const address = escapeHtml((customer.address || '').trim() || '-');
            const regionName = escapeHtml((customer.region_name || '').trim() || '-');
            const balance = customer.balance_formatted || '0.00 ج.م';
            
            // التحقق من أن العميل محدد مسبقاً
            const isChecked = selectedCustomers.indexOf(customerId) !== -1;
            const checkedAttr = isChecked ? ' checked' : '';
            
            // التأكد مرة أخرى من صحة البيانات قبل إضافتها
            if (customerId > 0 && customerName !== '') {
                html += '<tr data-customer-id="' + customerId + '">';
                html += '<td><input type="checkbox" class="form-check-input customer-export-checkbox" value="' + customerId + '"' + checkedAttr + '></td>';
                html += '<td><strong>' + customerName + '</strong></td>';
                html += '<td>' + phone + '</td>';
                html += '<td>' + address + '</td>';
                html += '<td>' + regionName + '</td>';
                html += '<td>' + balance + '</td>';
                const collectionAmount = collectionAmounts[customerId] || '';
                html += '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm collection-amount-input" data-customer-id="' + customerId + '" placeholder="مبلغ اختياري" value="' + (collectionAmount ? collectionAmount : '') + '"></td>';
                html += '</tr>';
            }
        });
        
        html += '</tbody></table>';
        
        // إضافة pagination إذا كان مطلوباً
        if (paginationOptions && paginationOptions.hasPagination && paginationOptions.totalPages > 1) {
            html += '<nav aria-label="تنقل الصفحات" class="mt-3">';
            html += '<ul class="pagination justify-content-center mb-0">';
            
            // زر الصفحة السابقة
            if (paginationOptions.currentPage > 1) {
                html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (paginationOptions.currentPage - 1) + '">السابق</a></li>';
            } else {
                html += '<li class="page-item disabled"><span class="page-link">السابق</span></li>';
            }
            
            // أرقام الصفحات (عرض 5 صفحات حول الصفحة الحالية)
            const startPage = Math.max(1, paginationOptions.currentPage - 2);
            const endPage = Math.min(paginationOptions.totalPages, paginationOptions.currentPage + 2);
            
            if (startPage > 1) {
                html += '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                if (startPage > 2) {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                if (i === paginationOptions.currentPage) {
                    html += '<li class="page-item active"><span class="page-link">' + i + '</span></li>';
                } else {
                    html += '<li class="page-item"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
                }
            }
            
            if (endPage < paginationOptions.totalPages) {
                if (endPage < paginationOptions.totalPages - 1) {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                html += '<li class="page-item"><a class="page-link" href="#" data-page="' + paginationOptions.totalPages + '">' + paginationOptions.totalPages + '</a></li>';
            }
            
            // زر الصفحة التالية
            if (paginationOptions.currentPage < paginationOptions.totalPages) {
                html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (paginationOptions.currentPage + 1) + '">التالي</a></li>';
            } else {
                html += '<li class="page-item disabled"><span class="page-link">التالي</span></li>';
            }
            
            html += '</ul>';
            html += '<div class="text-center text-muted small mt-2">';
            html += 'عرض ' + validCustomers.length + ' من ' + paginationOptions.total + ' عميل (صفحة ' + paginationOptions.currentPage + ' من ' + paginationOptions.totalPages + ')';
            html += '</div>';
            html += '</nav>';
        }
        
        // تحديث HTML مباشرة - بدون force reflow لتسريع العرض
        customersList.innerHTML = html;
        
        // إظهار القسم مباشرة
        if (customersSection && validCustomers.length > 0) {
            customersSection.style.display = 'block';
        }
        
        // ربط أحداث pagination
        if (paginationOptions && paginationOptions.hasPagination && paginationOptions.onPageChange) {
            const pageLinks = customersList.querySelectorAll('.page-link[data-page]');
            pageLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const page = parseInt(this.getAttribute('data-page'), 10);
                    if (page > 0 && page <= paginationOptions.totalPages) {
                        paginationOptions.onPageChange(page);
                    }
                });
            });
        }
        
        // تفعيل زر التوليد إذا كان هناك عملاء محددين
        updateGenerateButtons(function(btn) {
            btn.disabled = selectedCustomers.length === 0;
        });
        
        // ربط أحداث checkbox
        const checkboxes = customersList.querySelectorAll('.customer-export-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateSelectedCustomers();
            });
        });
        
        // ربط حدث select all
        const selectAllCheckbox = document.getElementById('exportSelectAllCheckbox');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checked = this.checked;
                checkboxes.forEach(function(cb) {
                    cb.checked = checked;
                });
                updateSelectedCustomers();
            });
        }
        
        // ربط أحداث مبالغ التحصيل
        const amountInputs = customersList.querySelectorAll('.collection-amount-input');
        amountInputs.forEach(function(input) {
            input.addEventListener('input', function() {
                const customerId = parseInt(this.getAttribute('data-customer-id'), 10);
                const value = parseFloat(this.value);
                if (value > 0) {
                    collectionAmounts[customerId] = value;
                } else {
                    delete collectionAmounts[customerId];
                }
            });
        });
    }
    
    /**
     * جلب قائمة العملاء من الجدول الحالي (legacy - للاستخدام مع العملاء المباشرين)
     */
    function loadCustomersList() {
        const customersList = document.getElementById('exportCustomersList');
        if (!customersList) {
            return;
        }
        
        // البحث عن جدول العملاء
        const customersTable = document.querySelector('.customers-table-container table tbody, .dashboard-table-wrapper table tbody');
        if (!customersTable) {
            customersList.innerHTML = '<div class="alert alert-warning">لا توجد عملاء متاحة للتصدير</div>';
            return;
        }
        
        const rows = customersTable.querySelectorAll('tr');
        if (rows.length === 0) {
            customersList.innerHTML = '<div class="alert alert-warning">لا توجد عملاء متاحة للتصدير</div>';
            return;
        }
        
        // إنشاء قائمة العملاء
        let html = '<div class="table-responsive"><table class="table table-sm table-hover">';
        html += '<thead><tr>';
        html += '<th style="width: 40px;"><input type="checkbox" id="exportSelectAllCheckbox" class="form-check-input"></th>';
        html += '<th>اسم العميل</th>';
        html += '<th>رقم الهاتف</th>';
        html += '<th>الرصيد</th>';
        html += '<th>المبلغ المراد تحصيله</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        rows.forEach(function(row) {
            // محاولة استخراج بيانات العميل من الصف
            const cells = row.querySelectorAll('td');
            if (cells.length === 0) {
                return;
            }
            
            // البحث عن معرف العميل من data attributes في الصف أو من أي عنصر داخل الصف
            let customerId = null;
            
            // أولاً: محاولة الحصول من الصف مباشرة
            const rowCustomerId = row.getAttribute('data-customer-id');
            if (rowCustomerId) {
                customerId = parseInt(rowCustomerId, 10);
            }
            
            // ثانياً: البحث في أي عنصر داخل الصف يحتوي على data-customer-id
            if (!customerId || customerId <= 0) {
                const customerIdElement = row.querySelector('[data-customer-id]');
                if (customerIdElement) {
                    customerId = parseInt(customerIdElement.getAttribute('data-customer-id'), 10);
                }
            }
            
            // ثالثاً: البحث في أزرار الإجراءات (عادة في آخر خلية)
            if (!customerId || customerId <= 0 && cells.length > 0) {
                const lastCell = cells[cells.length - 1];
                const actionBtn = lastCell ? lastCell.querySelector('[data-customer-id]') : null;
                if (actionBtn) {
                    customerId = parseInt(actionBtn.getAttribute('data-customer-id'), 10);
                }
            }
            
            if (!customerId || customerId <= 0) {
                return;
            }
            
            // استخراج اسم العميل (عادة من الخلية الأولى)
            const nameCell = cells[0];
            const customerName = nameCell ? nameCell.textContent.trim() : '';
            
            // استخراج رقم الهاتف (عادة من الخلية الثانية)
            const phoneCell = cells[1];
            let phoneNumber = '';
            if (phoneCell) {
                const phoneLink = phoneCell.querySelector('a[href^="tel:"]');
                if (phoneLink) {
                    phoneNumber = phoneLink.getAttribute('href').replace('tel:', '');
                } else {
                    phoneNumber = phoneCell.textContent.trim();
                }
            }
            
            // استخراج الرصيد (عادة من الخلية الثالثة)
            const balanceCell = cells[2];
            let balance = '';
            if (balanceCell) {
                balance = balanceCell.textContent.trim();
            }
            
            html += '<tr data-customer-id="' + customerId + '">';
            html += '<td><input type="checkbox" class="form-check-input customer-export-checkbox" value="' + customerId + '"></td>';
            html += '<td><strong>' + escapeHtml(customerName) + '</strong></td>';
            html += '<td>' + escapeHtml(phoneNumber) + '</td>';
            html += '<td>' + escapeHtml(balance) + '</td>';
            html += '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm collection-amount-input" data-customer-id="' + customerId + '" placeholder="مبلغ اختياري"></td>';
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        customersList.innerHTML = html;
        
        // ربط أحداث checkbox
        const checkboxes = customersList.querySelectorAll('.customer-export-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateSelectedCustomers();
            });
        });
        
        // ربط حدث select all
        const selectAllCheckbox = document.getElementById('exportSelectAllCheckbox');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checked = this.checked;
                checkboxes.forEach(function(cb) {
                    cb.checked = checked;
                });
                updateSelectedCustomers();
            });
        }
        
        // ربط أحداث مبالغ التحصيل
        const amountInputs = customersList.querySelectorAll('.collection-amount-input');
        amountInputs.forEach(function(input) {
            input.addEventListener('input', function() {
                const customerId = parseInt(this.getAttribute('data-customer-id'), 10);
                const value = parseFloat(this.value);
                if (value > 0) {
                    collectionAmounts[customerId] = value;
                } else {
                    delete collectionAmounts[customerId];
                }
            });
        });
    }
    
    /**
     * تحديث قائمة العملاء المحددين
     */
    function updateSelectedCustomers() {
        const checkboxes = document.querySelectorAll('.customer-export-checkbox:checked');
        selectedCustomers = Array.from(checkboxes).map(function(cb) {
            return parseInt(cb.value, 10);
        });
        
        // تحديث حالة زر التوليد
        updateGenerateButtons(function(btn) {
            btn.disabled = selectedCustomers.length === 0;
        });
    }
    
    /**
     * تحديد/إلغاء تحديد جميع العملاء
     */
    function selectAllCustomers(select) {
        const checkboxes = document.querySelectorAll('.customer-export-checkbox');
        const selectAllCheckbox = document.getElementById('exportSelectAllCheckbox');
        
        checkboxes.forEach(function(cb) {
            cb.checked = select;
        });
        
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = select;
        }
        
        updateSelectedCustomers();
    }
    
    /**
     * تحديد جميع العملاء المدينين وإنشاء ملف Excel تلقائياً
     */
    async function selectAllCustomersAndGenerate() {
        const selectAllAndGenerateBtn = document.getElementById('selectAllCustomersAndGenerate') || 
                                         document.getElementById('selectAllCustomersAndGenerateCard');
        
        if (!selectAllAndGenerateBtn) {
            return;
        }
        
        // تعطيل الزر وإظهار loading
        const originalText = selectAllAndGenerateBtn.innerHTML;
        selectAllAndGenerateBtn.disabled = true;
        selectAllAndGenerateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التحميل...';
        
        try {
            // تحديد القسم الحالي
            const exportModal = document.getElementById('customerExportModal');
            const exportCard = document.getElementById('customerExportCard');
            const container = exportModal || exportCard;
            
            if (!container) {
                throw new Error('لم يتم العثور على نموذج التصدير');
            }
            
            currentSection = container.getAttribute('data-section') || 'company';
            
            // جلب معرف المندوب (فقط إذا كان قسم عملاء المندوبين)
            const repSelect = document.getElementById('exportRepSelect');
            const repId = (currentSection === 'delegates' && repSelect) ? parseInt(repSelect.value, 10) : null;
            
            if (currentSection === 'delegates' && !repId) {
                throw new Error('يرجى اختيار المندوب أولاً');
            }
            
            // جلب جميع العملاء المدينين بدون pagination
            let allCustomers = [];
            
            if (currentSection === 'local') {
                // جلب جميع العملاء المحليين
                allCustomers = await fetchAllLocalCustomers();
            } else if (currentSection === 'company') {
                // جلب جميع عملاء الشركة
                allCustomers = await fetchAllCompanyCustomers();
            } else if (currentSection === 'delegates' && repId) {
                // جلب جميع عملاء المندوب
                allCustomers = await fetchAllRepCustomers(repId);
            } else {
                throw new Error('قسم غير معروف');
            }
            
            if (allCustomers.length === 0) {
                alert('لا توجد عملاء مدينين متاحة للتصدير');
                return;
            }
            
            // تحديد جميع العملاء
            selectedCustomers = allCustomers.map(function(customer) {
                return parseInt(customer.id, 10);
            });
            
            // تحديث collectionAmounts (يمكن إضافة مبالغ اختيارية لاحقاً)
            collectionAmounts = {};
            
            // تحديث واجهة المستخدم
            updateSelectedCustomers();
            
            // تحديث حالة الأزرار
            selectAllAndGenerateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التوليد...';
            
            // إنشاء ملف Excel تلقائياً
            await handleGenerateExcel();
            
        } catch (error) {
            console.error('Error in selectAllCustomersAndGenerate:', error);
            alert('حدث خطأ: ' + error.message);
        } finally {
            // إعادة تعيين الزر
            if (selectAllAndGenerateBtn) {
                selectAllAndGenerateBtn.disabled = false;
                selectAllAndGenerateBtn.innerHTML = originalText;
            }
        }
    }
    
    /**
     * جلب جميع العملاء المحليين المدينين
     */
    async function fetchAllLocalCustomers() {
        const allCustomers = [];
        let page = 1;
        let hasMore = true;
        
        while (hasMore) {
            try {
                const response = await fetch(getApiPath('get_local_customers_for_export.php') + '?page=' + page + '&all=1', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'فشل في جلب العملاء المحليين');
                }
                
                if (result.customers && result.customers.length > 0) {
                    allCustomers.push(...result.customers);
                }
                
                hasMore = result.has_more && result.customers && result.customers.length > 0;
                page++;
                
            } catch (error) {
                console.error('Error fetching local customers:', error);
                throw error;
            }
        }
        
        return allCustomers;
    }
    
    /**
     * جلب جميع عملاء الشركة المدينين
     */
    async function fetchAllCompanyCustomers() {
        try {
            const response = await fetch(getApiPath('get_company_customers_for_export.php') + '?all=1', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'فشل في جلب عملاء الشركة');
            }
            
            // فلترة العملاء المدينين فقط
            return (result.customers || []).filter(function(customer) {
                return customer.balance && parseFloat(customer.balance) > 0;
            });
            
        } catch (error) {
            console.error('Error fetching company customers:', error);
            throw error;
        }
    }
    
    /**
     * جلب جميع عملاء المندوب المدينين
     */
    async function fetchAllRepCustomers(repId) {
        const allCustomers = [];
        let page = 1;
        let hasMore = true;
        
        while (hasMore) {
            try {
                const response = await fetch(getApiPath('get_rep_customers_for_export.php') + '?rep_id=' + repId + '&page=' + page + '&all=1', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'فشل في جلب عملاء المندوب');
                }
                
                if (result.customers && result.customers.length > 0) {
                    allCustomers.push(...result.customers);
                }
                
                hasMore = result.has_more && result.customers && result.customers.length > 0;
                page++;
                
            } catch (error) {
                console.error('Error fetching rep customers:', error);
                throw error;
            }
        }
        
        return allCustomers;
    }
    
    /**
     * معالجة توليد ملف Excel
     */
    async function handleGenerateExcel() {
        if (selectedCustomers.length === 0) {
            alert('يرجى تحديد عملاء للتصدير');
            return;
        }
        
        updateGenerateButtons(function(btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التوليد...';
        });
        
        try {
            // تحديد القسم الحالي من المودال
            const exportModal = document.getElementById('customerExportModal');
            currentSection = exportModal ? (exportModal.getAttribute('data-section') || 'company') : 'company';
            
            // جلب معرف المندوب (فقط إذا كان قسم عملاء المندوبين)
            const repSelect = document.getElementById('exportRepSelect');
            const repId = (currentSection === 'delegates' && repSelect) ? parseInt(repSelect.value, 10) : null;
            
            // إذا كان قسم العملاء المحليين، لا حاجة لمعرف مندوب
            if (currentSection === 'local') {
                // العملاء المحليين لا يحتاجون مندوب
            }
            
            // تحضير البيانات
            const payload = {
                customer_ids: selectedCustomers,
                collection_amounts: collectionAmounts,
                section: currentSection,
                rep_id: repId
            };
            
            // إرسال الطلب باستخدام FormData لدعم قوائم كبيرة
            const formData = new FormData();
            formData.append('customer_ids', JSON.stringify(selectedCustomers));
            formData.append('collection_amounts', JSON.stringify(collectionAmounts));
            formData.append('section', currentSection);
            if (repId) {
                formData.append('rep_id', repId.toString());
            }
            
            // إرسال الطلب مع timeout أطول
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 300000); // 5 دقائق
            
            let response;
            try {
                response = await fetch(getApiPath('export_customers_excel.php'), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData,
                    signal: controller.signal
                });
                clearTimeout(timeoutId);
            } catch (fetchError) {
                clearTimeout(timeoutId);
                if (fetchError.name === 'AbortError') {
                    throw new Error('انتهت مهلة الطلب. يرجى المحاولة مرة أخرى أو تقليل عدد العملاء المحددين.');
                }
                // تحسين رسالة الخطأ لتشمل تفاصيل أكثر
                const errorMessage = fetchError.message || 'خطأ غير معروف';
                console.error('Fetch error details:', {
                    name: fetchError.name,
                    message: errorMessage,
                    stack: fetchError.stack
                });
                throw new Error('حدث خطأ في الاتصال بالخادم. يرجى التحقق من الاتصال بالإنترنت والمحاولة مرة أخرى. (' + errorMessage + ')');
            }
            
            // التحقق من نوع الاستجابة
            const contentType = response.headers.get('content-type') || '';
            let result;
            let responseText = '';
            
            try {
                responseText = await response.text();
            } catch (textError) {
                console.error('Error reading response text:', textError);
                throw new Error('فشل في قراءة استجابة الخادم. يرجى المحاولة مرة أخرى.');
            }
            
            if (contentType.includes('application/json')) {
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    console.error('Response status:', response.status);
                    console.error('Response headers:', Object.fromEntries(response.headers.entries()));
                    console.error('Response text (first 1000 chars):', responseText.substring(0, 1000));
                    throw new Error('استجابة غير صحيحة من الخادم. يرجى المحاولة مرة أخرى. (خطأ في تنسيق JSON)');
                }
            } else {
                console.error('Non-JSON response. Status:', response.status);
                console.error('Content-Type:', contentType);
                console.error('Response text (first 1000 chars):', responseText.substring(0, 1000));
                throw new Error('استجابة غير صحيحة من الخادم. قد تكون الجلسة منتهية أو هناك خطأ في الخادم. (حالة: ' + response.status + ')');
            }
            
            // التحقق من حالة الاستجابة
            if (!response.ok) {
                const errorMsg = result && result.message ? result.message : 'فشل في توليد ملف Excel';
                console.error('Server error response:', {
                    status: response.status,
                    statusText: response.statusText,
                    result: result
                });
                throw new Error(errorMsg + ' (حالة الخادم: ' + response.status + ')');
            }
            
            if (!result || !result.success) {
                const errorMsg = result && result.message ? result.message : 'فشل في توليد ملف Excel';
                console.error('Unsuccessful response:', result);
                throw new Error(errorMsg);
            }
            
            // حفظ رابط الملف ومسار الملف
            generatedFileUrl = result.file_url;
            generatedFilePath = result.file_path || result.relative_path || null;
            
            // إظهار أزرار الإجراءات
            const actionButtons = getExportElement('exportActionButtons');
            if (actionButtons) {
                actionButtons.style.display = 'block';
            }
            
            // إعادة تعيين زر التوليد
            updateGenerateButtons(function(btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تم التوليد بنجاح';
                btn.classList.add('btn-success');
                btn.classList.remove('btn-primary');
            });
            
            // إظهار رسالة نجاح
            showAlert('success', 'تم توليد ملف Excel بنجاح');
            
        } catch (error) {
            console.error('Export error:', error);
            alert('حدث خطأ أثناء توليد ملف Excel: ' + error.message);
            
            updateGenerateButtons(function(btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-file-earmark-excel me-1"></i>توليد ملف Excel';
            });
        }
    }
    
    /**
     * معالجة طباعة الملف
     */
    function handlePrintExcel() {
        if (!generatedFileUrl || !generatedFilePath) {
            alert('لم يتم توليد ملف Excel بعد');
            return;
        }
        
        try {
            // استخدام مسار الملف المباشر من الاستجابة
            let filePath = generatedFilePath;
            
            // التأكد من أن المسار يبدأ بـ reports/
            if (filePath.indexOf('reports/') !== 0) {
                filePath = 'reports/' + filePath.replace(/^\/+/, '');
            }
            
            // إزالة البداية / إذا كانت موجودة
            filePath = filePath.replace(/^\/+/, '');
            
            // بناء URL لعرض CSV كـ HTML
            const printUrl = getApiPath('view_csv_for_print.php') + '?file=' + encodeURIComponent(filePath) + '&print=1';
            
            // فتح الملف في نافذة جديدة للطباعة
            const printWindow = window.open(printUrl, '_blank');
            if (printWindow) {
                // الانتظار قليلاً ثم محاولة الطباعة
                setTimeout(function() {
                    try {
                        printWindow.print();
                    } catch (e) {
                        console.error('Print error:', e);
                        // إذا فشلت الطباعة التلقائية، المستخدم يمكنه استخدام زر الطباعة في الصفحة
                    }
                }, 1000);
            } else {
                alert('تعذر فتح نافذة الطباعة. يرجى التحقق من إعدادات المتصفح (حظر النوافذ المنبثقة)');
            }
        } catch (error) {
            console.error('Print error:', error);
            alert('حدث خطأ أثناء محاولة الطباعة: ' + error.message);
        }
    }
    
    /**
     * معالجة تحميل الملف
     */
    function handleDownloadExcel() {
        if (!generatedFileUrl) {
            alert('لم يتم توليد ملف Excel بعد');
            return;
        }
        
        // إنشاء رابط تحميل
        const link = document.createElement('a');
        link.href = generatedFileUrl;
        link.download = 'customers_export_' + new Date().getTime() + '.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    /**
     * معالجة مشاركة الملف
     */
    async function handleShareExcel() {
        if (!generatedFileUrl) {
            alert('لم يتم توليد ملف Excel بعد');
            return;
        }
        
        // محاولة استخدام Web Share API
        if (navigator.share) {
            try {
                // تحميل الملف كـ blob
                let response;
                try {
                    response = await fetch(generatedFileUrl, {
                        method: 'GET',
                        headers: {
                            'Accept': 'text/csv, application/csv, */*'
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error('فشل في تحميل الملف: ' + response.status);
                    }
                    
                    const blob = await response.blob();
                    const file = new File([blob], 'customers_export_' + new Date().getTime() + '.csv', { 
                        type: 'text/csv;charset=utf-8' 
                    });
                    
                    await navigator.share({
                        title: 'تصدير العملاء',
                        text: 'ملف تصدير العملاء المحددين',
                        files: [file]
                    });
                    
                    showAlert('success', 'تم مشاركة الملف بنجاح');
                    return;
                } catch (fetchError) {
                    console.error('Error fetching file for share:', fetchError);
                    // Fallback: نسخ الرابط
                    copyLinkToClipboard();
                    return;
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Share error:', error);
                    // Fallback: نسخ الرابط
                    copyLinkToClipboard();
                }
            }
        } else {
            // Fallback: نسخ الرابط
            copyLinkToClipboard();
        }
    }
    
    /**
     * نسخ الرابط إلى الحافظة
     */
    function copyLinkToClipboard() {
        if (!generatedFileUrl) {
            return;
        }
        
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(generatedFileUrl).then(function() {
                showAlert('success', 'تم نسخ رابط الملف إلى الحافظة');
            }).catch(function() {
                fallbackCopyToClipboard(generatedFileUrl);
            });
        } else {
            fallbackCopyToClipboard(generatedFileUrl);
        }
    }
    
    /**
     * طريقة بديلة لنسخ الرابط
     */
    function fallbackCopyToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showAlert('success', 'تم نسخ رابط الملف إلى الحافظة');
        } catch (err) {
            showAlert('warning', 'تعذر نسخ الرابط تلقائياً. يرجى نسخه يدوياً: ' + text);
        }
        document.body.removeChild(textArea);
    }
    
    /**
     * إظهار تنبيه
     */
    function showAlert(type, message) {
        // البحث عن container للتنبيهات
        const alertContainer = document.querySelector('.customer-export-alerts') || document.body;
        
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-' + type + ' alert-dismissible fade show';
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        
        alertContainer.appendChild(alertDiv);
        
        // إزالة التنبيه تلقائياً بعد 5 ثوان
        setTimeout(function() {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
    
    /**
     * تهريب HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // تهيئة عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initExportModal);
    } else {
        initExportModal();
    }
    
    // تصدير الدوال للاستخدام الخارجي
    window.customerExport = {
        openModal: function() {
            const modal = document.getElementById('customerExportModal');
            if (modal && typeof bootstrap !== 'undefined') {
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            }
        }
    };
    
})();


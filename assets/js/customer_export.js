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
        if (!exportModal) {
            return;
        }
        
        // إضافة event handlers لإغلاق فوري
        exportModal.addEventListener('hide.bs.modal', function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.transition = 'none';
                backdrop.style.opacity = '0';
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        exportModal.addEventListener('hidden.bs.modal', function() {
            // تنظيف backdrop المتبقي (احتياطي)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        // إعادة تعيين عند فتح المودال
        exportModal.addEventListener('show.bs.modal', function() {
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
        
        // ربط حدث اختيار المندوب مع debouncing محسّن
        const repSelect = document.getElementById('exportRepSelect');
        let repSelectTimeout = null;
        if (repSelect && repSelect.tagName === 'SELECT') {
            repSelect.addEventListener('change', function() {
                // إلغاء timeout السابق
                if (repSelectTimeout) {
                    clearTimeout(repSelectTimeout);
                }
                
                // تعطيل الـ select أثناء التحميل
                const selectEl = this;
                const originalValue = selectEl.value;
                selectEl.disabled = true;
                
                // إظهار loading فوراً
                const selectRepMessage = document.getElementById('selectRepMessage');
                const customersSection = document.getElementById('customersSection');
                if (selectRepMessage) {
                    selectRepMessage.style.display = 'block';
                    selectRepMessage.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل عملاء المندوب...';
                }
                if (customersSection) {
                    customersSection.style.display = 'none';
                }
                
                // debounce الطلب - تقليل الوقت على الهواتف
                const isMobile = window.innerWidth <= 768;
                const debounceTime = isMobile ? 50 : 100;
                
                repSelectTimeout = setTimeout(() => {
                    const repId = parseInt(selectEl.value, 10);
                    if (repId > 0 && repId === parseInt(originalValue, 10)) {
                        loadCustomersByRep(repId).finally(() => {
                            selectEl.disabled = false;
                        });
                    } else {
                        showSelectRepMessage();
                        selectEl.disabled = false;
                    }
                }, debounceTime);
            });
        }
        
        // ربط أحداث الأزرار
        const generateBtn = document.getElementById('generateExcelBtn');
        const printBtn = document.getElementById('printExcelBtn');
        const downloadBtn = document.getElementById('downloadExcelBtn');
        const shareBtn = document.getElementById('shareExcelBtn');
        const selectAllBtn = document.getElementById('selectAllCustomers');
        const deselectAllBtn = document.getElementById('deselectAllCustomers');
        
        if (generateBtn) {
            generateBtn.addEventListener('click', handleGenerateExcel);
        }
        
        if (printBtn) {
            printBtn.addEventListener('click', handlePrintExcel);
        }
        
        if (downloadBtn) {
            downloadBtn.addEventListener('click', handleDownloadExcel);
        }
        
        if (shareBtn) {
            shareBtn.addEventListener('click', handleShareExcel);
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
        
        const customersList = document.getElementById('exportCustomersList');
        if (customersList) {
            customersList.innerHTML = '';
        }
        
        const customersSection = document.getElementById('customersSection');
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        const selectRepMessage = document.getElementById('selectRepMessage');
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
        }
        
        // إخفاء أزرار الطباعة والتحميل والمشاركة
        const actionButtons = document.getElementById('exportActionButtons');
        if (actionButtons) {
            actionButtons.style.display = 'none';
        }
        
        // إظهار زر التوليد
        const generateBtn = document.getElementById('generateExcelBtn');
        if (generateBtn) {
            generateBtn.disabled = true;
            generateBtn.innerHTML = '<i class="bi bi-file-earmark-excel me-2"></i>توليد ملف Excel';
            generateBtn.classList.remove('btn-success');
            generateBtn.classList.add('btn-primary');
        }
        
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
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<i class="bi bi-info-circle me-2"></i>يرجى اختيار المندوب أولاً لعرض عملائه';
        }
        
        const generateBtn = document.getElementById('generateExcelBtn');
        if (generateBtn) {
            generateBtn.disabled = true;
        }
    }
    
    /**
     * جلب العملاء المحليين عبر API مع pagination
     */
    async function loadLocalCustomers(page = 1) {
        const customersList = document.getElementById('exportCustomersList');
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        
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
        const customersList = document.getElementById('exportCustomersList');
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        
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
        const customersList = document.getElementById('exportCustomersList');
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        
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
        
        try {
            const apiUrl = `${getApiPath('get_rep_customers_for_export.php')}?rep_id=${repId}&page=${page}&_t=${Date.now()}`;
            const response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache'
                },
                signal: currentLoadAbortController.signal,
                cache: 'no-store'
            });
            
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
            
            // فلترة إضافية للتأكد من صحة البيانات
            const validCustomers = customers.filter(function(customer) {
                return customer && 
                       typeof customer === 'object' && 
                       customer.id && 
                       parseInt(customer.id, 10) > 0 &&
                       customer.name && 
                       typeof customer.name === 'string' && 
                       customer.name.trim() !== '';
            });
            
            // عرض قائمة العملاء مع pagination مباشرة
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
        const customersList = document.getElementById('exportCustomersList');
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        const generateBtn = document.getElementById('generateExcelBtn');
        
        if (!customersList) {
            return;
        }
        
        // فلترة العملاء للتأكد من صحة البيانات
        const validCustomers = [];
        if (customers && Array.isArray(customers)) {
            customers.forEach(function(customer) {
                if (customer && 
                    typeof customer === 'object' && 
                    customer.id && 
                    parseInt(customer.id, 10) > 0 &&
                    customer.name && 
                    typeof customer.name === 'string' && 
                    customer.name.trim() !== '') {
                    validCustomers.push(customer);
                }
            });
        }
        
        // إذا لم يكن هناك عملاء صالحين
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
            if (generateBtn) {
                generateBtn.disabled = true;
            }
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
        
        // تحديث HTML مباشرة
        customersList.innerHTML = html;
        
        // Force reflow لضمان العرض على الهواتف
        const hasContent = customersList.querySelector('table');
        if (hasContent) {
            // Force reflow
            customersList.offsetHeight;
            
            // التأكد من إظهار القسم
            if (customersSection) {
                customersSection.style.display = 'block';
                customersSection.offsetHeight; // Force reflow
            }
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
        if (generateBtn) {
            generateBtn.disabled = selectedCustomers.length === 0;
        }
        
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
        const generateBtn = document.getElementById('generateExcelBtn');
        if (generateBtn) {
            generateBtn.disabled = selectedCustomers.length === 0;
        }
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
     * معالجة توليد ملف Excel
     */
    async function handleGenerateExcel() {
        if (selectedCustomers.length === 0) {
            alert('يرجى تحديد عملاء للتصدير');
            return;
        }
        
        const generateBtn = document.getElementById('generateExcelBtn');
        if (generateBtn) {
            generateBtn.disabled = true;
            generateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التوليد...';
        }
        
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
            const actionButtons = document.getElementById('exportActionButtons');
            if (actionButtons) {
                actionButtons.style.display = 'block';
            }
            
            // إعادة تعيين زر التوليد
            if (generateBtn) {
                generateBtn.disabled = false;
                generateBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تم التوليد بنجاح';
                generateBtn.classList.add('btn-success');
                generateBtn.classList.remove('btn-primary');
            }
            
            // إظهار رسالة نجاح
            showAlert('success', 'تم توليد ملف Excel بنجاح');
            
        } catch (error) {
            console.error('Export error:', error);
            alert('حدث خطأ أثناء توليد ملف Excel: ' + error.message);
            
            if (generateBtn) {
                generateBtn.disabled = false;
                generateBtn.innerHTML = '<i class="bi bi-file-earmark-excel me-2"></i>توليد ملف Excel';
            }
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


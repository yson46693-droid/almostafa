# نظام التنقل عبر AJAX - التوثيق الكامل

## نظرة عامة

نظام التنقل عبر AJAX (AJAX Navigation System) هو نظام متقدم يسمح بالتنقل بين صفحات Dashboard بدون إعادة تحميل كامل للصفحة. هذا يحسن الأداء بشكل كبير خاصة على الهواتف المحمولة.

## الملفات الرئيسية

### 1. `assets/js/ajax-navigation.js`
الملف الرئيسي المسؤول عن نظام التنقل عبر AJAX.

### 2. `assets/js/sidebar.js`
الملف المسؤول عن التحكم في الشريط الجانبي.

### 3. `templates/homeline_sidebar.php`
القالب الذي يولد الشريط الجانبي بناءً على دور المستخدم.

---

## آلية التنقل بين التبويبات

### 1. نظام AJAX Navigation (`ajax-navigation.js`)

عند النقر على رابط في الشريط الجانبي:

#### أ) اعتراض النقر
```javascript
// في ajax-navigation.js - السطر 1330
function handleLinkClick(event) {
    const link = event.target.closest('a');
    if (!link || !shouldInterceptLink(link)) {
        return;
    }
    event.preventDefault(); // منع التنقل العادي
    loadPage(link.href); // تحميل الصفحة عبر AJAX
}
```

**الشروط للاعتراض:**
- الرابط يجب أن يكون في نفس النطاق
- يجب أن يحتوي على `/dashboard/` أو `?page=`
- لا يتم اعتراض الروابط التي تحتوي على:
  - `target="_blank"`
  - `download`
  - `data-ajax="false"`
  - `href^="#"`
  - `href^="javascript:"`

#### ب) تحميل الصفحة
```javascript
// السطر 1122
async function loadPage(url) {
    // 1. التحقق من Cache أولاً
    if (pageCache.has(url)) {
        updatePageContent(pageCache.get(url));
        return;
    }
    
    // 2. إظهار Loading Indicator (بعد تأخير 300ms)
    showLoading();
    
    // 3. جلب HTML من الخادم
    const response = await fetch(url, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'text/html'
        }
    });
    
    // 4. استخراج المحتوى من <main> فقط
    const data = extractContent(html);
    
    // 5. تحديث الصفحة
    updatePageContent(data);
    
    // 6. حفظ في Cache
    if (CONFIG.cacheEnabled) {
        pageCache.set(url, data);
    }
}
```

### 2. تحديث المحتوى (`updatePageContent`)

```javascript
// السطر 216
function updatePageContent(data) {
    // 1. تحديث العنوان
    document.title = data.title;
    
    // 2. تحديث محتوى <main>
    mainElement.innerHTML = data.content;
    
    // 3. تنفيذ Scripts المدمجة
    // - تنفيذ inline scripts أولاً (بعد 30ms لكل script)
    // - تحميل external scripts بعد ذلك (بعد 100ms لكل script)
    
    // 4. إعادة تهيئة الأحداث (مهم جداً!)
    setTimeout(() => {
        reinitializeTopbarEvents(); // للشريط العلوي
    }, 0);
    
    requestAnimationFrame(() => {
        reinitializeTopbarEvents(); // مرة أخرى
    });
    
    // 5. إعادة تهيئة الأحداث العامة
    const totalScriptsDelay = (inlineScripts.length * 30) + (externalScripts.length * 100);
    const reinitDelay = Math.max(200, totalScriptsDelay + 100);
    
    setTimeout(() => {
        reinitializeEvents();
        reinitializeAllEvents();
        reinitializeTopbarEvents();
    }, reinitDelay);
    
    // 6. تحديث حالة active في الشريط الجانبي
    requestAnimationFrame(() => {
        updateSidebarActiveState();
    });
    
    // 7. إغلاق الشريط الجانبي على الموبايل
    closeSidebarOnMobile();
    
    // 8. إطلاق حدث مخصص
    setTimeout(() => {
        window.dispatchEvent(new CustomEvent('ajaxNavigationComplete', {
            detail: { url: currentUrl }
        }));
    }, 100);
}
```

### 3. إعادة تهيئة الأزرار والعناصر

#### أ) الشريط العلوي (`reinitializeTopbarEvents`)
```javascript
// السطر 676
function reinitializeTopbarEvents() {
    // 1. إعادة تهيئة Bootstrap Dropdowns
    const topbarDropdowns = document.querySelectorAll(
        '.homeline-topbar [data-bs-toggle="dropdown"]',
        '.topbar-right [data-bs-toggle="dropdown"]',
        '#quickActionsDropdown',
        '.quick-actions-toggle'
    );
    
    topbarDropdowns.forEach(dropdown => {
        // إزالة instance القديم
        const oldInstance = bootstrap.Dropdown.getInstance(dropdown);
        if (oldInstance) {
            oldInstance.dispose();
        }
        
        // Clone العنصر لإزالة event listeners القديمة
        const parent = dropdown.parentNode;
        const nextSibling = dropdown.nextSibling;
        const newDropdown = dropdown.cloneNode(true);
        
        parent.removeChild(dropdown);
        if (nextSibling) {
            parent.insertBefore(newDropdown, nextSibling);
        } else {
            parent.appendChild(newDropdown);
        }
        
        // إنشاء instance جديد
        new bootstrap.Dropdown(newDropdown, {
            boundary: 'viewport',
            popperConfig: {
                modifiers: [{
                    name: 'preventOverflow',
                    options: {
                        boundary: document.body
                    }
                }]
            }
        });
    });
    
    // 2. إعادة تهيئة Tooltips
    const topbarTooltips = document.querySelectorAll('.homeline-topbar [data-bs-toggle="tooltip"]');
    topbarTooltips.forEach(tooltipEl => {
        const oldInstance = bootstrap.Tooltip.getInstance(tooltipEl);
        if (oldInstance) {
            oldInstance.dispose();
        }
        new bootstrap.Tooltip(tooltipEl);
    });
    
    // 3. إعادة تهيئة زر إعادة التحميل على الموبايل
    // 4. إعادة تهيئة زر الوضع الداكن على الموبايل
    // 5. إعادة تهيئة البحث العام
}
```

#### ب) الأحداث العامة (`reinitializeEvents`)
```javascript
// السطر 930
function reinitializeEvents() {
    // 1. Bootstrap Components
    if (typeof bootstrap !== 'undefined') {
        // Tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(tooltipTriggerEl => {
            const tooltip = bootstrap.Tooltip.getInstance(tooltipTriggerEl);
            if (tooltip) {
                tooltip.dispose();
            }
            new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Popovers
        const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
        popoverTriggerList.forEach(popoverTriggerEl => {
            const popover = bootstrap.Popover.getInstance(popoverTriggerEl);
            if (popover) {
                popover.dispose();
            }
            new bootstrap.Popover(popoverTriggerEl);
        });
        
        // Dropdowns
        const dropdownToggleList = document.querySelectorAll('[data-bs-toggle="dropdown"]');
        dropdownToggleList.forEach(dropdownToggleEl => {
            const dropdown = bootstrap.Dropdown.getInstance(dropdownToggleEl);
            if (!dropdown) {
                new bootstrap.Dropdown(dropdownToggleEl);
            }
        });
    }
    
    // 2. الأحداث المخصصة
    if (typeof window.initPageEvents === 'function') {
        window.initPageEvents();
    }
    
    // 3. جداول البيانات
    if (typeof window.initDataTables === 'function') {
        window.initDataTables();
    }
    
    // 4. Sidebar
    if (typeof window.initSidebar === 'function') {
        window.initSidebar();
    }
    
    // 5. Mobile Menu
    if (typeof window.initMobileMenu === 'function') {
        window.initMobileMenu();
    }
}
```

#### ج) إعادة تهيئة شاملة (`reinitializeAllEvents`)
```javascript
// السطر 1426
function reinitializeAllEvents() {
    // 1. إعادة تهيئة Bootstrap Dropdowns بشكل شامل
    // 2. إعادة تهيئة Bootstrap Tooltips
    // 3. إعادة تهيئة Bootstrap Popovers
    // 4. إعادة تهيئة زر إعادة التحميل على الموبايل
    // 5. إعادة تهيئة زر الوضع الداكن على الموبايل
    // 6. إعادة تهيئة notifications dropdown
    // 7. إعادة تهيئة mobile menu
    // 8. إزالة جميع قيود pointer-events من الأزرار
    // 9. إعادة تهيئة الأحداث المخصصة
    // 10. إعادة تهيئة البحث العام
}
```

### 4. تحديث حالة الشريط الجانبي

```javascript
// السطر 163
function updateSidebarActiveState() {
    const currentUrlObj = new URL(window.location.href);
    const currentPage = currentUrlObj.pathname.split('/').pop() || '';
    const currentPageParam = currentUrlObj.searchParams.get('page') || '';
    
    // 1. إزالة active من جميع الروابط
    const allNavLinks = document.querySelectorAll('.homeline-sidebar .nav-link, .sidebar-nav .nav-link');
    allNavLinks.forEach(link => {
        link.classList.remove('active');
    });
    
    // 2. إضافة active للرابط المطابق
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
                } 
                // حالة الصفحات مع page parameter
                else if (currentPageParam !== '' && linkPageParam !== '' && currentPageParam === linkPageParam) {
                    link.classList.add('active');
                }
            }
        } catch (e) {
            console.warn('Error parsing URL in updateSidebarActiveState:', e);
        }
    });
}
```

### 5. تسلسل التنفيذ الكامل

```
1. النقر على رابط في الشريط الجانبي
   ↓
2. handleLinkClick() - اعتراض الحدث
   ↓
3. shouldInterceptLink() - التحقق من صحة الرابط
   ↓
4. loadPage(url) - بدء تحميل الصفحة
   ↓
5. التحقق من Cache
   ├─ موجود → استخدام Cache
   └─ غير موجود → المتابعة
   ↓
6. showLoading() - إظهار Loading Indicator (بعد 300ms)
   ↓
7. fetch(url) - جلب HTML من الخادم
   ↓
8. extractContent(html) - استخراج <main> من HTML
   ↓
9. updatePageContent(data) - تحديث المحتوى
   ├─ تحديث document.title
   ├─ تحديث mainElement.innerHTML
   ├─ تنفيذ inline scripts (بعد 30ms لكل script)
   ├─ تحميل external scripts (بعد 100ms لكل script)
   ├─ reinitializeTopbarEvents() (فوراً + بعد 0ms)
   ├─ reinitializeEvents() (بعد reinitDelay)
   ├─ reinitializeAllEvents() (بعد reinitDelay)
   ├─ updateSidebarActiveState() (في requestAnimationFrame)
   ├─ closeSidebarOnMobile()
   └─ dispatchEvent('ajaxNavigationComplete')
   ↓
10. حفظ في Cache (إذا كان مفعّل)
   ↓
11. updateHistory(url) - تحديث History API
   ↓
12. إخفاء Loading Indicator
```

### 6. معالجة Scripts

#### أ) Inline Scripts
```javascript
// السطر 254
inlineScripts.forEach((oldScript, index) => {
    setTimeout(() => {
        const scriptContent = oldScript.textContent || oldScript.innerHTML;
        
        // إزالة script القديم من DOM
        if (oldScript.parentNode) {
            oldScript.parentNode.removeChild(oldScript);
        }
        
        // التحقق من صحة المحتوى (ليس HTML أو PHP)
        if (scriptContent && scriptContent.trim()) {
            // تخطي المحتوى الذي يحتوي على HTML/PHP tags
            if (/* contains HTML/PHP */) {
                return;
            }
            
            // تنفيذ الكود
            try {
                const scriptFunction = new Function(scriptContent.trim());
                scriptFunction();
            } catch (functionError) {
                // معالجة الأخطاء
            }
        }
    }, index * 30); // تأخير 30ms بين كل script
});
```

#### ب) External Scripts
```javascript
// السطر 378
externalScripts.forEach((oldScript, index) => {
    setTimeout(() => {
        const scriptSrc = oldScript.src;
        
        // التحقق من وجود الـ script في DOM بالفعل
        const existingScript = document.querySelector(`script[src="${scriptSrc}"]`);
        if (existingScript && existingScript !== oldScript) {
            // الـ script موجود بالفعل - تخطي إعادة التحميل
            if (oldScript.parentNode) {
                oldScript.parentNode.removeChild(oldScript);
            }
            return;
        }
        
        // إنشاء script جديد
        const newScript = document.createElement('script');
        Array.from(oldScript.attributes).forEach(attr => {
            newScript.setAttribute(attr.name, attr.value);
        });
        newScript.setAttribute('data-ajax-loaded', 'true');
        newScript.src = scriptSrc;
        
        // إزالة script القديم
        if (oldScript.parentNode) {
            oldScript.parentNode.removeChild(oldScript);
        }
        
        document.head.appendChild(newScript);
    }, (inlineScripts.length * 30) + (index * 100)); // تأخير أكبر للـ scripts الخارجية
});
```

### 7. Cache System

```javascript
// السطر 28
const pageCache = new Map();
const CONFIG = {
    cacheEnabled: true,
    cacheMaxSize: 10
};

// حفظ في Cache
if (CONFIG.cacheEnabled) {
    // تنظيف Cache إذا تجاوز الحد الأقصى
    if (pageCache.size >= CONFIG.cacheMaxSize) {
        const firstKey = pageCache.keys().next().value;
        pageCache.delete(firstKey);
    }
    pageCache.set(url, data);
}

// استخدام Cache
if (CONFIG.cacheEnabled && pageCache.has(url)) {
    const cachedData = pageCache.get(url);
    currentUrl = url;
    updatePageContent(cachedData);
    updateHistory(url);
    return true;
}
```

### 8. Loading Indicator

```javascript
// السطر 38
function createLoadingIndicator() {
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
    document.body.appendChild(indicator);
    return indicator;
}

// إظهار Loading (بعد تأخير 300ms)
function showLoading(targetElement = null) {
    loadingTimeoutId = setTimeout(() => {
        const indicator = createLoadingIndicator();
        // تحديد موقع المؤشر
        if (targetElement) {
            // فوق العنصر المستهدف
        } else {
            // ملء الشاشة بالكامل
        }
        indicator.style.display = 'flex';
    }, LOADING_DELAY); // 300ms
}
```

### 9. أحداث مخصصة

```javascript
// إطلاق حدث بعد اكتمال التنقل
window.dispatchEvent(new CustomEvent('ajaxNavigationComplete', {
    detail: { url: currentUrl }
}));

// الاستماع للحدث
window.addEventListener('ajaxNavigationComplete', function(event) {
    // إعادة تهيئة فورية للشريط العلوي
    reinitializeTopbarEvents();
    
    // إعادة تهيئة بعد تأخير بسيط
    setTimeout(() => {
        reinitializeAllEvents();
        reinitializeTopbarEvents();
    }, 50);
    
    // إعادة تهيئة إضافية بعد تأخير أكبر
    setTimeout(() => {
        reinitializeAllEvents();
        reinitializeTopbarEvents();
    }, 200);
    
    // إعادة تهيئة نهائية
    setTimeout(() => {
        // خاصة لزر القائمة السريعة
        reinitializeTopbarEvents();
    }, 500);
});
```

### 10. معالجة الأخطاء

```javascript
// معالجة Timeout
if (error.name === 'AbortError' || error.message.includes('timeout')) {
    mainElement.innerHTML = `
        <div class="alert alert-warning">
            <h5>انتهت مهلة الاتصال</h5>
            <p>جاري إعادة تحميل الصفحة...</p>
        </div>
    `;
    setTimeout(() => {
        window.location.href = url;
    }, 1000);
}

// معالجة Redirect إلى صفحة تسجيل الدخول
if (response.redirected && isLoginPageUrl && isLoginPageContent) {
    hideLoading();
    isLoading = false;
    window.location.href = responseUrl;
    return false;
}
```

---

## نقاط مهمة

### 1. استخدام Clone لإزالة Event Listeners
```javascript
// السطر 724
const newDropdown = dropdown.cloneNode(true);
parent.removeChild(dropdown);
parent.insertBefore(newDropdown, nextSibling);
new bootstrap.Dropdown(newDropdown);
```
هذا يضمن إزالة جميع event listeners القديمة وإنشاء ones جديدة.

### 2. Cache للصفحات
- يحفظ آخر 10 صفحات محملة
- يسرع التنقل بين الصفحات المفتوحة مسبقاً
- يتم تنظيفه تلقائياً عند تجاوز الحد الأقصى

### 3. معالجة Scripts
- Inline scripts: تنفيذ مباشر مع معالجة الأخطاء
- External scripts: التحقق من التحميل المسبق قبل إعادة التحميل
- تخطي Scripts التي تحتوي على HTML/PHP tags

### 4. أحداث متعددة لإعادة التهيئة
- `ajaxNavigationComplete`: بعد اكتمال التنقل
- `DOMContentLoaded`: عند تحميل DOM
- `setTimeout` متعددة بفترات مختلفة (0ms, 50ms, 200ms, 500ms)

### 5. معالجة Mobile
- إغلاق الشريط الجانبي تلقائياً بعد التنقل
- إعادة تهيئة أزرار الموبايل (القائمة، إعادة التحميل، الوضع الداكن)

---

## الخلاصة

- عند التنقل، يتم استبدال محتوى `<main>` فقط (بدون إعادة تحميل كامل)
- يتم إعادة تهيئة جميع الأحداث والأزرار بعد التحديث
- يتم تحديث حالة active في الشريط الجانبي تلقائياً
- يتم استخدام Cache لتسريع التنقل
- يتم إعادة تهيئة Bootstrap Components (Dropdowns, Tooltips, Modals) بعد كل تنقل

هذا يضمن عمل جميع الأزرار والعناصر بشكل صحيح بعد كل تنقل بين التبويبات.

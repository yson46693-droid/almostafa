/**
 * JavaScript الرئيسي
 */

// ========== إعدادات التطوير/الإنتاج ==========
// تعيين DEBUG = false في الإنتاج لإزالة console.log
// التحقق من وجود DEBUG قبل الإعلان لتجنب إعادة الإعلان عند تحميل الملف عدة مرات
if (typeof window.DEBUG === 'undefined') {
    window.DEBUG = window.location.hostname === 'localhost' || 
                   window.location.hostname === '127.0.0.1' || 
                   window.location.hostname.includes('localhost:');
}
// استخدام var للسماح بإعادة الإعلان (مع التحقق أعلاه لمنع ذلك)
var DEBUG = window.DEBUG;

// دالة console.log آمنة (لا تطبع في الإنتاج)
window.safeLog = function(...args) {
    if (DEBUG) {
        console.log(...args);
    }
};

// دالة console.error آمنة (تطبع دائماً للأخطاء)
window.safeError = function(...args) {
    console.error(...args);
};

// دالة console.warn آمنة (تطبع في التطوير فقط)
window.safeWarn = function(...args) {
    if (DEBUG) {
        console.warn(...args);
    }
};

// ========== دوال مساعدة للأمان (XSS Protection) ==========
// دالة لتنظيف النص من HTML tags
function escapeHTML(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

// دالة لتنظيف attributes
function escapeAttribute(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#x27;');
}

// ========== حل جذري لمنع أخطاء classList ==========
// معالج أخطاء عام لالتقاط أخطاء classList بشكل آمن
(function() {
    'use strict';
    
    // دالة مساعدة آمنة للتعامل مع classList
    window.safeClassList = function(element) {
        if (!element || typeof element !== 'object') {
            return {
                add: function() { return false; },
                remove: function() { return false; },
                toggle: function() { return false; },
                contains: function() { return false; },
                replace: function() { return false; },
                item: function() { return null; },
                toString: function() { return ''; },
                length: 0
            };
        }
        
        // إذا كان العنصر موجوداً وله classList، استخدمه
        if (element.classList && typeof element.classList === 'object') {
            return element.classList;
        }
        
        // إذا لم يكن موجوداً، أرجع كائن آمن
        return {
            add: function() { return false; },
            remove: function() { return false; },
            toggle: function() { return false; },
            contains: function() { return false; },
            replace: function() { return false; },
            item: function() { return null; },
            toString: function() { return ''; },
            length: 0
        };
    };
    
    // دالة مساعدة لإضافة class بشكل آمن
    window.safeAddClass = function(element, className) {
        if (!element || !className) return false;
        try {
            if (element.classList) {
                element.classList.add(className);
                return true;
            }
        } catch (e) {
            console.warn('safeAddClass error:', e);
        }
        return false;
    };
    
    // دالة مساعدة لإزالة class بشكل آمن
    window.safeRemoveClass = function(element, className) {
        if (!element || !className) return false;
        try {
            if (element.classList) {
                element.classList.remove(className);
                return true;
            }
        } catch (e) {
            console.warn('safeRemoveClass error:', e);
        }
        return false;
    };
    
    // دالة مساعدة للتحقق من وجود class بشكل آمن
    window.safeHasClass = function(element, className) {
        if (!element || !className) return false;
        try {
            if (element.classList) {
                return element.classList.contains(className);
            }
        } catch (e) {
            console.warn('safeHasClass error:', e);
        }
        return false;
    };
    
    // دالة مساعدة للتبديل بين class بشكل آمن
    window.safeToggleClass = function(element, className) {
        if (!element || !className) return false;
        try {
            if (element.classList) {
                return element.classList.toggle(className);
            }
        } catch (e) {
            console.warn('safeToggleClass error:', e);
        }
        return false;
    };
    
    // حماية classList من الأخطاء عبر Proxy
    if (typeof Element !== 'undefined' && Element.prototype) {
        const originalClassListDescriptor = Object.getOwnPropertyDescriptor(Element.prototype, 'classList');
        
        if (originalClassListDescriptor && originalClassListDescriptor.get) {
            const originalGetter = originalClassListDescriptor.get;
            
            Object.defineProperty(Element.prototype, 'classList', {
                get: function() {
                    try {
                        const classList = originalGetter.call(this);
                        if (classList && typeof classList === 'object') {
                            return classList;
                        }
                    } catch (e) {
                        // في حالة الخطأ، أرجع كائن آمن
                        console.warn('classList access error on element:', e);
                    }
                    
                    // أرجع كائن classList آمن
                    return {
                        add: function() { return false; },
                        remove: function() { return false; },
                        toggle: function() { return false; },
                        contains: function() { return false; },
                        replace: function() { return false; },
                        item: function() { return null; },
                        toString: function() { return ''; },
                        length: 0
                    };
                },
                configurable: true,
                enumerable: true
            });
        }
    }
    
    // معالج أخطاء عام لالتقاط أخطاء classList وأخطاء الشبكة (ERR_FAILED)
    window.addEventListener('error', function(event) {
        // التحقق من أخطاء الشبكة (ERR_FAILED) - Error Code: -2
        if (event.message && typeof event.message === 'string') {
            const message = event.message.toLowerCase();
            if (message.includes('failed') || message.includes('network error') || 
                message.includes('err_failed') || message.includes('load failed') ||
                message.includes('error code: -2') || message.includes('error code:-2')) {
                // محاولة منع عرض صفحة الخطأ
                event.preventDefault();
                event.stopPropagation();
                
                console.error('Network error detected (ERR_FAILED / Error Code: -2):', event.message);
                
                const currentPath = window.location.pathname || '';
                const isProfilePage = currentPath.includes('profile.php');
                const isLoginPage = currentPath.includes('index.php');
                
                // إذا كنا في صفحة تسجيل الدخول، لا نعيد التوجيه
                if (isLoginPage) {
                    // إظهار رسالة خطأ واضحة للمستخدم
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                    errorDiv.style.zIndex = '9999';
                    errorDiv.style.maxWidth = '600px';
                    errorDiv.innerHTML = `
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>خطأ في الاتصال بالخادم (Error Code: -2)</strong><br>
                        <small>يرجى التحقق من:
                        <ul class="mb-0 mt-2 text-start">
                            <li>أن الخادم يعمل على المنفذ 8000</li>
                            <li>أن لا يوجد جدار ناري يمنع الاتصال</li>
                            <li>اتصال الإنترنت</li>
                        </ul>
                        </small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.body.appendChild(errorDiv);
                    
                    // إزالة الرسالة بعد 10 ثوان
                    setTimeout(() => {
                        if (errorDiv.parentNode) {
                            errorDiv.remove();
                        }
                    }, 10000);
                    
                    return true;
                }
                
                if (!isProfilePage) {
                    // محاولة التوجيه إلى صفحة تسجيل الدخول
                    setTimeout(() => {
                        const loginUrl = '/index.php';
                        if (window.location.pathname !== loginUrl) {
                            window.location.href = loginUrl;
                        }
                    }, 100);
                }
                
                return true;
            }
        }
        
        // التحقق من error code -2 في event.error
        if (event.error && typeof event.error === 'object') {
            const errorCode = event.error.code || event.error.errorCode || event.error.errno;
            if (errorCode === -2 || errorCode === '-2') {
                event.preventDefault();
                event.stopPropagation();
                console.error('Error Code -2 detected:', event.error);
                
                const currentPath = window.location.pathname || '';
                const isLoginPage = currentPath.includes('index.php');
                
                if (isLoginPage) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                    errorDiv.style.zIndex = '9999';
                    errorDiv.style.maxWidth = '600px';
                    errorDiv.innerHTML = `
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>خطأ في الاتصال بالخادم (Error Code: -2)</strong><br>
                        <small>يرجى التحقق من أن الخادم يعمل على المنفذ 8000</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.body.appendChild(errorDiv);
                    setTimeout(() => {
                        if (errorDiv.parentNode) {
                            errorDiv.remove();
                        }
                    }, 10000);
                }
                
                return true;
            }
        }
        
        // التحقق من أن الخطأ متعلق بـ classList
        if (event.error && event.error.message) {
            const errorMessage = event.error.message.toLowerCase();
            if (errorMessage.includes('classlist') || errorMessage.includes('class list')) {
                // منع عرض الخطأ للمستخدم
                event.preventDefault();
                event.stopPropagation();
                
                // تسجيل تحذير في console فقط (للمطورين)
                console.warn('classList error caught and handled:', event.error.message, event.error);
                
                // إرجاع true لمنع عرض الخطأ
                return true;
            }
        }
        
        // التحقق من رسالة الخطأ في النص
        if (event.message && typeof event.message === 'string') {
            const message = event.message.toLowerCase();
            if (message.includes('classlist') || message.includes('class list') || 
                message.includes('cannot read properties of null') && message.includes('classlist')) {
                event.preventDefault();
                event.stopPropagation();
                console.warn('classList error caught and handled:', event.message);
                return true;
            }
        }
    }, true);
    
    // معالج أخطاء غير معالجة (unhandledrejection)
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason && event.reason.message) {
            const errorMessage = event.reason.message.toLowerCase();
            if (errorMessage.includes('classlist') || errorMessage.includes('class list')) {
                event.preventDefault();
                console.warn('classList promise rejection caught and handled:', event.reason.message);
            }
            // معالجة أخطاء الشبكة في promises
            if (errorMessage.includes('failed') || errorMessage.includes('network error') || 
                errorMessage.includes('err_failed') || errorMessage.includes('load failed')) {
                event.preventDefault();
                const currentPath = window.location.pathname || '';
                const isProfilePage = currentPath.includes('profile.php');
                if (!isProfilePage) {
                    setTimeout(() => {
                        const loginUrl = '/index.php';
                        if (window.location.pathname !== loginUrl) {
                            window.location.href = loginUrl;
                        }
                    }, 100);
                }
            }
        }
    });
    
    // معالج أخطاء التنقل (page navigation errors)
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason && typeof event.reason === 'object') {
            const reason = event.reason;
            // التحقق من أخطاء fetch/network
            if (reason.name === 'TypeError' && reason.message && 
                (reason.message.includes('fetch') || reason.message.includes('network') || 
                 reason.message.includes('Failed to fetch'))) {
                event.preventDefault();
                const currentPath = window.location.pathname || '';
                const isProfilePage = currentPath.includes('profile.php');
                if (!isProfilePage) {
                    setTimeout(() => {
                        const loginUrl = '/index.php';
                        if (window.location.pathname !== loginUrl) {
                            window.location.href = loginUrl;
                        }
                    }, 100);
                }
            }
            
            // التحقق من error code -2 في promise rejection
            const errorCode = reason.code || reason.errorCode || reason.errno;
            if (errorCode === -2 || errorCode === '-2') {
                event.preventDefault();
                console.error('Error Code -2 detected in promise rejection:', reason);
                
                const currentPath = window.location.pathname || '';
                const isLoginPage = currentPath.includes('index.php');
                
                if (isLoginPage) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                    errorDiv.style.zIndex = '9999';
                    errorDiv.style.maxWidth = '600px';
                    errorDiv.innerHTML = `
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>خطأ في الاتصال بالخادم (Error Code: -2)</strong><br>
                        <small>يرجى التحقق من أن الخادم يعمل على المنفذ 8000</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.body.appendChild(errorDiv);
                    setTimeout(() => {
                        if (errorDiv.parentNode) {
                            errorDiv.remove();
                        }
                    }, 10000);
                }
            }
        }
    });
    
    // معالج أخطاء تحميل الصفحة (page load errors) - Error Code: -2
    window.addEventListener('error', function(event) {
        // التحقق من error code -2 في event.target (للصور، scripts، etc.)
        if (event.target && event.target.tagName) {
            const targetTag = event.target.tagName.toLowerCase();
            if (targetTag === 'script' || targetTag === 'link' || targetTag === 'img') {
                const errorMessage = (event.message || '').toLowerCase();
                if (errorMessage.includes('error code: -2') || errorMessage.includes('error code:-2') ||
                    errorMessage.includes('err_failed') || errorMessage.includes('load failed')) {
                    console.error('Error Code -2 detected in resource load:', {
                        tag: targetTag,
                        src: event.target.src || event.target.href,
                        message: event.message
                    });
                    
                    const currentPath = window.location.pathname || '';
                    const isLoginPage = currentPath.includes('index.php');
                    
                    if (isLoginPage) {
                        // إظهار رسالة خطأ واحدة فقط
                        if (!document.querySelector('.alert-danger[data-error-code-2]')) {
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                            errorDiv.style.zIndex = '9999';
                            errorDiv.style.maxWidth = '600px';
                            errorDiv.setAttribute('data-error-code-2', 'true');
                            errorDiv.innerHTML = `
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <strong>خطأ في تحميل الموارد (Error Code: -2)</strong><br>
                                <small>يرجى التحقق من:
                                <ul class="mb-0 mt-2 text-start">
                                    <li>أن الخادم يعمل على المنفذ 8000</li>
                                    <li>أن جميع الملفات موجودة</li>
                                    <li>اتصال الإنترنت</li>
                                </ul>
                                </small>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            `;
                            document.body.appendChild(errorDiv);
                            setTimeout(() => {
                                if (errorDiv.parentNode) {
                                    errorDiv.remove();
                                }
                            }, 10000);
                        }
                    }
                }
            }
        }
    }, true);
    
    // حماية querySelector و querySelectorAll من إرجاع null
    const originalQuerySelector = Element.prototype.querySelector;
    const originalQuerySelectorAll = Element.prototype.querySelectorAll;
    
    Element.prototype.querySelector = function(selector) {
        try {
            const result = originalQuerySelector.call(this, selector);
            return result;
        } catch (e) {
            console.warn('querySelector error:', e);
            return null;
        }
    };
    
    Element.prototype.querySelectorAll = function(selector) {
        try {
            const result = originalQuerySelectorAll.call(this, selector);
            return result;
        } catch (e) {
            console.warn('querySelectorAll error:', e);
            return [];
        }
    };
    
    // حماية getElementById
    const originalGetElementById = Document.prototype.getElementById;
    Document.prototype.getElementById = function(id) {
        try {
            return originalGetElementById.call(this, id);
        } catch (e) {
            console.warn('getElementById error:', e);
            return null;
        }
    };
    
})();

// حماية الروابط في الشريط العلوي من إنهاء الجلسة
(function() {
    'use strict';
    
    // إضافة event listener عام على جميع الروابط لمنع إنهاء الجلسة عند التنقل
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (!link || !link.href) {
            return;
        }
        
        // التحقق من أن الرابط ليس logout
        if (link.href.includes('logout.php')) {
            return; // السماح لـ logout بالعمل بشكل طبيعي
        }
        
        // التحقق من أن الرابط داخلي (نفس النطاق)
        try {
            const linkUrl = new URL(link.href, window.location.origin);
            const currentUrl = new URL(window.location.href);
            
            // إذا كان الرابط من نفس النطاق وليس API call
            if (linkUrl.origin === currentUrl.origin && !linkUrl.pathname.includes('/api/')) {
                // إضافة flag لمنع handleSessionStatus من إعادة التوجيه
                link.setAttribute('data-navigation-link', 'true');
                
                // إزالة flag بعد التنقل (باستخدام timeout)
                setTimeout(function() {
                    link.removeAttribute('data-navigation-link');
                }, 1000);
            }
        } catch (urlError) {
            // تجاهل أخطاء URL parsing
        }
    }, true); // استخدام capture phase
})();

// تهيئة النظام
document.addEventListener('DOMContentLoaded', function() {
    // تهيئة Tooltips
    if (typeof bootstrap !== 'undefined') {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    const mobileReloadBtn = document.getElementById('mobileReloadBtn');
    if (mobileReloadBtn) {
        mobileReloadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            // استخدام طريقة آمنة للـ reload لمنع Error Code: -2
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('_refresh', Date.now().toString());
                window.location.href = url.toString();
            } catch (err) {
                // في حالة الخطأ، استخدم reload عادي
                window.location.reload();
            }
        });
    }
    
    const mobileDarkToggle = document.getElementById('mobileDarkToggle');
    function updateMobileDarkIcon(theme) {
        if (!mobileDarkToggle) {
            return;
        }
        const icon = mobileDarkToggle.querySelector('i');
        if (!icon) {
            return;
        }
        if ((theme || '').toLowerCase() === 'dark') {
            icon.classList.remove('bi-moon-stars');
            icon.classList.add('bi-brightness-high');
        } else {
            icon.classList.remove('bi-brightness-high');
            icon.classList.add('bi-moon-stars');
        }
    }
    if (mobileDarkToggle) {
        updateMobileDarkIcon(document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light');
        mobileDarkToggle.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof toggleDarkMode === 'function') {
                toggleDarkMode();
            }
            updateMobileDarkIcon(document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light');
        });
        window.addEventListener('themeChange', function(e) {
            const theme = e && e.detail && e.detail.theme ? e.detail.theme : (document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light');
            updateMobileDarkIcon(theme);
        });
    }
    
    // تهيئة Popovers
    if (typeof bootstrap !== 'undefined') {
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
    }
    
    // إخفاء الرسائل تلقائياً بعد 5 ثوان
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // تأكيد الحذف
    document.querySelectorAll('[data-confirm-delete]').forEach(function(element) {
        element.addEventListener('click', function(e) {
            if (!confirm('هل أنت متأكد من الحذف؟')) {
                e.preventDefault();
            }
        });
    });
    
    // إضافة data-label للجداول على الهاتف
    if (window.innerWidth <= 767) {
        const tables = document.querySelectorAll('.table');
        tables.forEach(function(table) {
            const headers = table.querySelectorAll('thead th');
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(function(row) {
                const cells = row.querySelectorAll('td');
                cells.forEach(function(cell, index) {
                    if (headers[index]) {
                        cell.setAttribute('data-label', headers[index].textContent.trim());
                    }
                });
            });
        });
    }
    
    // تحديث data-label عند تغيير حجم النافذة
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth <= 767) {
                const tables = document.querySelectorAll('.table');
                tables.forEach(function(table) {
                    const headers = table.querySelectorAll('thead th');
                    const rows = table.querySelectorAll('tbody tr');
                    
                    rows.forEach(function(row) {
                        const cells = row.querySelectorAll('td');
                        cells.forEach(function(cell, index) {
                            if (headers[index]) {
                                cell.setAttribute('data-label', headers[index].textContent.trim());
                            }
                        });
                    });
                });
            }
        }, 250);
    });
});

// دوال مساعدة
function formatCurrency(amount) {
    return new Intl.NumberFormat('ar-EG', {
        style: 'currency',
        currency: 'EGP'
    }).format(amount);
}

function formatDate(date) {
    return new Date(date).toLocaleDateString('ar-EG');
}

function formatDateTime(date) {
    return new Date(date).toLocaleString('ar-EG');
}

// تحديث تلقائي للبيانات
let autoRefreshInterval = null;

function startAutoRefresh(callback, interval = 30000) {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    autoRefreshInterval = setInterval(callback, interval);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

    // إيقاف التحديث عند مغادرة الصفحة - استخدام pagehide لإعادة تفعيل bfcache
window.addEventListener('pagehide', function() {
    stopAutoRefresh();
    
    // حفظ flag في sessionStorage أن المستخدم يقوم بـ Refresh (إذا لم يكن من bfcache)
    if (!event.persisted) {
        try {
            sessionStorage.setItem('is_refreshing', 'true');
            sessionStorage.setItem('refresh_timestamp', Date.now().toString());
        } catch (e) {
            // تجاهل إذا كان sessionStorage غير متاح
        }
    }
});

// معالجة Refresh لمنع Error Code: -2
// مع منع refresh loop - تم تحسينه لمنع الشاشة البيضاء عند العودة للتبويبة
let mainPageshowHandled = false;
window.addEventListener('pageshow', function(event) {
    // منع refresh loop - فقط معالجة مرة واحدة لكل صفحة
    if (mainPageshowHandled) {
        return;
    }
    
    try {
        // تنظيف flags القديمة فقط
        const isRefreshing = sessionStorage.getItem('is_refreshing') === 'true';
        if (isRefreshing) {
            sessionStorage.removeItem('is_refreshing');
            sessionStorage.removeItem('refresh_timestamp');
        }
        
        // إذا كانت الصفحة من cache، نستأنف polling فقط بدلاً من عمل refresh
        // هذا يحل مشكلة الشاشة البيضاء
        if (event.persisted) {
            // استئناف polling إذا كان متوقفاً
            if (typeof window.unifiedPolling !== 'undefined' && window.unifiedPolling.execute) {
                setTimeout(() => {
                    window.unifiedPolling.execute();
                }, 500);
            }
        }
        
        mainPageshowHandled = true;
    } catch (e) {
        // تجاهل الأخطاء
        console.warn('Error in pageshow handler:', e);
        mainPageshowHandled = true;
    }
});

// معالجة البحث في التوب بار
document.addEventListener('DOMContentLoaded', function() {
    const globalSearch = document.getElementById('globalSearch');
    let activeHighlight = null;
    let lastSearchTerm = '';

    function ensureHighlightStyle() {
        if (document.getElementById('global-search-highlight-style')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'global-search-highlight-style';
        style.textContent = `
            mark.global-search-highlight {
                background-color: #ffe066;
                color: inherit;
                padding: 0.1rem 0.2rem;
                border-radius: 0.25rem;
                box-shadow: 0 0 0 0.15rem rgba(255, 224, 102, 0.45);
            }
        `;
        document.head.appendChild(style);
    }

    function clearSearchHighlight() {
        if (!activeHighlight || !activeHighlight.parentNode) {
            activeHighlight = null;
            return;
        }

        const parent = activeHighlight.parentNode;
        while (activeHighlight.firstChild) {
            parent.insertBefore(activeHighlight.firstChild, activeHighlight);
        }
        parent.removeChild(activeHighlight);
        parent.normalize();
        activeHighlight = null;
    }

    function highlightSearchTerm(term, startFromHighlight = false) {
        if (!term) {
            clearSearchHighlight();
            return false;
        }

        ensureHighlightStyle();

        const lowerTerm = term.toLowerCase();
        let walkerStartNode = document.body;

        if (startFromHighlight && activeHighlight && activeHighlight.parentNode) {
            walkerStartNode = activeHighlight;
        }

        const walker = document.createTreeWalker(
            walkerStartNode,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    if (!node.nodeValue || !node.nodeValue.trim()) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    if (!node.parentNode) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    const disallowed = node.parentNode.closest('script, style, noscript, head, title, meta, link');
                    return disallowed ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
                }
            }
        );

        // If continuing search, skip the first (current) node
        if (startFromHighlight) {
            walker.nextNode();
        }

        let node;
        while ((node = walker.nextNode())) {
            const text = node.nodeValue;
            const index = text.toLowerCase().indexOf(lowerTerm);
            if (index !== -1) {
                clearSearchHighlight();
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + term.length);
                const mark = document.createElement('mark');
                mark.className = 'global-search-highlight';
                mark.setAttribute('data-global-search-highlight', 'true');

                try {
                    range.surroundContents(mark);
                } catch (err) {
                    // fallback: skip problematic node
                    continue;
                }

                activeHighlight = mark;
                mark.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return true;
            }
        }

        return false;
    }

    function handleGlobalSearch(term, continueSearch = false) {
        const searchTerm = term.trim();
        if (!searchTerm) {
            clearSearchHighlight();
            lastSearchTerm = '';
            return;
        }

        const continueFromHighlight = continueSearch && searchTerm === lastSearchTerm;
        const found = highlightSearchTerm(searchTerm, continueFromHighlight);

        if (!found && continueFromHighlight) {
            // Loop from top again
            const wrappedFound = highlightSearchTerm(searchTerm, false);
            if (!wrappedFound) {
                clearSearchHighlight();
                window.alert('لم يتم العثور على النتائج المطابقة.');
            } else {
                window.alert('تم الوصول إلى بداية النتائج مرة أخرى.');
            }
        } else if (!found) {
            clearSearchHighlight();
            window.alert('لم يتم العثور على النتائج المطابقة.');
        }

        lastSearchTerm = searchTerm;
    }

    if (globalSearch) {
        globalSearch.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleGlobalSearch(this.value, true);
            }
        });

        globalSearch.addEventListener('input', function() {
            if (this.value.trim() === '') {
                clearSearchHighlight();
                lastSearchTerm = '';
            }
        });
    }
    
    // إصلاح نماذج GET بدون action ومنع البحث بدون مدخلات
    document.querySelectorAll('form[method="GET"]').forEach(function(form) {
        const currentUrl = new URL(window.location.href);
        const pathname = currentUrl.pathname;
        
        // إذا كان action فارغاً أو غير موجود، اجعله الصفحة الحالية
        if (!form.action || form.action === '' || form.action === window.location.href.split('?')[0]) {
            // إذا كان action فارغاً، استخدم المسار الحالي
            if (!form.action || form.action === '') {
                form.action = pathname;
            }
        }
        
        // التأكد من وجود input hidden للصفحة
        const pageInput = form.querySelector('input[name="page"]');
        if (!pageInput) {
            // محاولة استخراج page من URL الحالي
            const currentPage = currentUrl.searchParams.get('page');
            if (currentPage) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'page';
                hiddenInput.value = currentPage;
                form.appendChild(hiddenInput);
            }
        }
        
        // منع إرسال النموذج بدون مدخلات بحث
        const searchButton = form.querySelector('button[type="submit"]');
        if (searchButton) {
            form.addEventListener('submit', function(e) {
                // جمع جميع حقول البحث والفلترة
                const searchInputs = form.querySelectorAll('input[name="search"], input[name="date_from"], input[name="date_to"], input[type="text"]:not([type="hidden"]), input[type="number"]:not([type="hidden"])');
                const selectInputs = form.querySelectorAll('select');
                
                let hasInput = false;
                let hasFilter = false;
                
                // التحقق من وجود نص في حقول البحث
                searchInputs.forEach(function(input) {
                    if (input.value && input.value.trim() !== '') {
                        hasInput = true;
                    }
                });
                
                // التحقق من وجود فلتر في select (غير القيمة الافتراضية)
                selectInputs.forEach(function(select) {
                    if (select.value && select.value !== '' && select.value !== '0') {
                        hasFilter = true;
                    }
                });
                
                // إذا لم يكن هناك مدخلات بحث أو فلترة، منع الإرسال
                if (!hasInput && !hasFilter) {
                    e.preventDefault();
                    // إظهار رسالة للمستخدم
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-warning alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                    alertDiv.style.zIndex = '9999';
                    alertDiv.style.maxWidth = '500px';
                    alertDiv.innerHTML = `
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>يرجى إدخال معايير البحث أو اختيار فلتر</strong>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.body.appendChild(alertDiv);
                    
                    // إزالة الرسالة بعد 3 ثوان
                    setTimeout(function() {
                        if (alertDiv.parentNode) {
                            alertDiv.remove();
                        }
                    }, 3000);
                    
                    return false;
                }
                
                // التأكد من أن action يشير للصفحة الحالية وليس للداشبورد
                const formUrl = new URL(form.action, window.location.origin);
                const currentPath = window.location.pathname;
                
                // إذا كان action مختلف عن الصفحة الحالية، تصحيحه
                if (formUrl.pathname !== currentPath) {
                    form.action = currentPath;
                }
            });
        }
    });
    
    // إزالة القيم الافتراضية الكبيرة من select dropdowns
    document.querySelectorAll('select').forEach(function(select) {
        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const value = parseInt(selectedOption.value);
            // إذا كانت القيمة أكبر من 100000 (غير منطقية)، إزالة selected
            if (!isNaN(value) && value > 100000) {
                // التحقق من أن القيمة ليست في قاعدة البيانات
                // إذا كانت القيمة كبيرة جداً، إزالة selected
                selectedOption.removeAttribute('selected');
                select.selectedIndex = 0; // تحديد الخيار الأول (عادة "اختر..." أو "الكل")
                
                // إذا كان هناك option بقيمة فارغة أو 0، حدده
                const emptyOption = Array.from(select.options).find(function(option) {
                    return option.value === '' || option.value === '0' || option.value === null;
                });
                if (emptyOption) {
                    emptyOption.selected = true;
                }
            }
        }
    });
    
});

(function() {
    if (window.__sessionOverlayInstalled) {
        return;
    }
    window.__sessionOverlayInstalled = true;

    const SESSION_END_STATUS = new Set([401, 419, 440]);
    // 403 (Forbidden) ليس خطأ في الجلسة - هو خطأ في الصلاحيات
    const FORBIDDEN_STATUS = 403;

    /**
     * Stop all intervals, timeouts, and background jobs
     * Called when session expires to prevent continued polling
     */
    function stopAllPolling() {
        // Stop auto-refresh interval if exists
        if (typeof stopAutoRefresh === 'function') {
            stopAutoRefresh();
        }
        
        // Stop background tasks interval (from footer.php)
        if (window.backgroundTasksInterval) {
            clearInterval(window.backgroundTasksInterval);
            window.backgroundTasksInterval = null;
        }
        
        // Clear any other intervals registered in window
        for (let i = 1; i < 9999; i++) {
            clearInterval(i);
            clearTimeout(i);
        }
        
        // Stop any intervals stored in window
        if (window.__intervals) {
            window.__intervals.forEach(function(id) {
                clearInterval(id);
                clearTimeout(id);
            });
            window.__intervals = [];
        }
        
        safeLog('All polling stopped due to session expiration');
    }
    
    // Export function globally
    window.stopAllPolling = stopAllPolling;
    
    function getOverlayElement() {
        return null; // الـ overlay تم إزالته
    }

    function getLoginUrl(overlay) {
        // حساب المسار الصحيح لصفحة تسجيل الدخول بناءً على المسار الحالي
        const currentPath = window.location.pathname || '/';
        const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && p !== 'modules' && !p.endsWith('.php'));
        const basePath = pathParts.length > 0 ? '/' + pathParts[0] : '';
        const loginUrl = (basePath + '/index.php').replace(/\/+/g, '/');
        return loginUrl.startsWith('/') ? loginUrl : '/' + loginUrl;
    }
    
    // دالة مساعدة للحصول على مسار API
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
    
    // دالة مساعدة لإنشاء AbortSignal مع timeout
    function createTimeoutSignal(timeoutMs) {
        if (typeof AbortSignal !== 'undefined' && AbortSignal.timeout) {
            return AbortSignal.timeout(timeoutMs);
        }
        // Fallback للمتصفحات القديمة
        const controller = new AbortController();
        setTimeout(() => controller.abort(), timeoutMs);
        return controller.signal;
    }

    function redirectToLogin(loginUrl) {
        // تنظيف URL لضمان أنه مسار نسبي فقط (بدون بروتوكول أو hostname)
        let cleanLoginUrl = (loginUrl || '/index.php').split('?')[0];
        
        // إزالة أي بروتوكول (http:// أو https://)
        cleanLoginUrl = cleanLoginUrl.replace(/^https?:\/\//i, '');
        
        // إزالة // المكررة من البداية
        cleanLoginUrl = cleanLoginUrl.replace(/^\/\/+/, '/');
        
        // إزالة hostname مع منفذ إذا كان موجوداً (مثل localhost:8000/)
        cleanLoginUrl = cleanLoginUrl.replace(/^[^\/]+:[0-9]+\//, '/');
        
        // إزالة hostname بدون منفذ إذا كان موجوداً
        if (!cleanLoginUrl.startsWith('/')) {
            cleanLoginUrl = cleanLoginUrl.replace(/^[^\/]+\//, '/');
        }
        
        // إزالة أي منفذ من منتصف المسار
        cleanLoginUrl = cleanLoginUrl.replace(/:[0-9]+\//g, '/');
        cleanLoginUrl = cleanLoginUrl.replace(/:[0-9]+$/g, '');
        
        // التأكد من أن المسار يبدأ بـ /
        if (!cleanLoginUrl.startsWith('/')) {
            cleanLoginUrl = '/' + cleanLoginUrl;
        }
        
        // تنظيف المسارات المكررة
        cleanLoginUrl = cleanLoginUrl.replace(/\/+/g, '/');
        
        // إزالة أي مسافات
        cleanLoginUrl = cleanLoginUrl.trim();
        
        // التأكد من أن المسار لا يحتوي على بروتوكول أو hostname
        if (cleanLoginUrl.includes('://')) {
            // إذا كان يحتوي على بروتوكول، استخرج المسار فقط
            try {
                const urlObj = new URL(cleanLoginUrl, window.location.origin);
                cleanLoginUrl = urlObj.pathname;
            } catch (e) {
                // في حالة الخطأ، استخدم المسار الافتراضي
                cleanLoginUrl = '/index.php';
            }
        }
        
        // استخدام replace بدلاً من href لمنع إضافة الصفحة إلى التاريخ
        // واستخدام مسار نسبي فقط لمنع ERR_FAILED
        try {
            window.location.replace(cleanLoginUrl);
        } catch (e) {
            // في حالة الخطأ، استخدم href كحل بديل
            console.warn('Error using replace, trying href:', e);
            window.location.href = cleanLoginUrl;
        }
    }

    function showSessionOverlay(loginUrl) {
        // إعادة التوجيه مباشرة إلى صفحة تسجيل الدخول مع تنظيف الرابط
        const finalLoginUrl = loginUrl || getLoginUrl();
        redirectToLogin(finalLoginUrl);
    }

    function handleSessionStatus(status, responseUrl = null, requestUrl = null) {
        // قائمة الصفحات المحمية - لا نعيد التوجيه فيها
        const currentPath = window.location.pathname || '';
        const protectedPages = [
            'profile.php',
            'attendance.php',
            'index.php' // صفحة تسجيل الدخول
        ];
        
        // التحقق من أننا في صفحة محمية
        const isProtectedPage = protectedPages.some(page => 
            currentPath.includes(page) || 
            currentPath.endsWith('/' + page.replace('.php', ''))
        ) || document.querySelector('body[data-page="profile"]') ||
           document.querySelector('body[data-page="attendance"]');
        
        const numericStatus = Number(status);
        if (!Number.isFinite(numericStatus)) {
            return;
        }
        
        // تجاهل 403 (Forbidden) - هذا ليس خطأ في الجلسة
        if (numericStatus === FORBIDDEN_STATUS) {
            return;
        }
        
        // فقط نعيد التوجيه إذا كان status هو 401/419/440 وكان من API call
        if (SESSION_END_STATUS.has(numericStatus)) {
            // STOP ALL POLLING IMMEDIATELY when session expires
            stopAllPolling();
            
            if (isProtectedPage) {
                // في الصفحات المحمية، نوقف الـ polling لكن لا نعيد التوجيه
                // لأن الجلسة قد تكون صالحة لكن هناك مشكلة في الاتصال
                return;
            }
        
            // التحقق من وجود رابط تنقل نشط - منع إعادة التوجيه أثناء التنقل
            const activeNavigationLink = document.querySelector('a[data-navigation-link="true"]');
            if (activeNavigationLink) {
                // هناك عملية تنقل نشطة - لا نعيد التوجيه
                return;
            }
            // استخدام requestUrl إذا كان متوفراً، وإلا استخدم responseUrl
            const urlToCheck = requestUrl || responseUrl || '';
            
            // التحقق بدقة من أن الطلب هو API call
            // يجب أن يحتوي URL على /api/ أو يكون من ملفات API المحددة
            const isApiCall = urlToCheck && typeof urlToCheck === 'string' && (
                urlToCheck.includes('/api/') || 
                urlToCheck.includes('notifications.php') ||
                urlToCheck.includes('attendance.php?action=')
            );
            
            // إذا كان responseUrl يحتوي على .php وليس /api/، فهو navigation عادي - تجاهله
            if (responseUrl && !responseUrl.includes('/api/') && (responseUrl.includes('.php') || responseUrl.endsWith('/'))) {
                // هذا طلب navigation عادي - لا نعيد التوجيه
                return;
            }
            
            // التحقق من أن الطلب ليس من form submission عادي
            // إذا كان URL هو نفس الصفحة الحالية أو لا يحتوي على /api/، تجاهله
            if (responseUrl && !isApiCall) {
                const responsePath = new URL(responseUrl, window.location.origin).pathname;
                const currentPathNormalized = currentPath.replace(/\/$/, '');
                const responsePathNormalized = responsePath.replace(/\/$/, '');
                
                // إذا كان responseUrl يشير إلى نفس الصفحة الحالية، فهو form submission عادي - تجاهله
                if (responsePathNormalized === currentPathNormalized || responsePathNormalized === currentPath) {
                    return;
                }
            }
            
                // فقط نعرض رسالة انتهاء الجلسة إذا كان الطلب فعلاً API call
                // لكن أولاً نتحقق من أن الجلسة منتهية فعلياً في قاعدة البيانات
                if (isApiCall) {
                    // التحقق من حالة الجلسة في قاعدة البيانات قبل إعادة التوجيه
                    // لا نعيد التوجيه إلا إذا كانت الجلسة منتهية فعلياً
                    // حساب مسار API
                    const currentPath = window.location.pathname || '/';
                    const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && p !== 'modules' && !p.endsWith('.php'));
                    const basePath = pathParts.length > 0 ? '/' + pathParts[0] : '';
                    const checkSessionUrl = (basePath + '/api/check_session.php').replace(/\/+/g, '/');
                    
                    // دالة مساعدة لإنشاء AbortSignal مع timeout
                    function createTimeoutSignal(timeoutMs) {
                        if (typeof AbortSignal !== 'undefined' && AbortSignal.timeout) {
                            return AbortSignal.timeout(timeoutMs);
                        }
                        // Fallback للمتصفحات القديمة
                        const controller = new AbortController();
                        setTimeout(() => controller.abort(), timeoutMs);
                        return controller.signal;
                    }
                
                fetch(checkSessionUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-cache'
                })
                .then(function(response) {
                    return response.json().catch(() => ({ success: false }));
                })
                .then(function(data) {
                    // فقط إذا كانت الجلسة غير موجودة في قاعدة البيانات، نعيد التوجيه
                    if (!data.success || !data.session_exists) {
                        // تسجيل سبب ظهور رسالة انتهاء الجلسة
                        const logData = {
                            timestamp: new Date().toISOString(),
                            status: numericStatus,
                            requestUrl: requestUrl,
                            responseUrl: responseUrl,
                            currentPath: currentPath,
                            userAgent: navigator.userAgent,
                            sessionStorage: {
                                hasSession: typeof sessionStorage !== 'undefined',
                                keys: typeof sessionStorage !== 'undefined' ? Object.keys(sessionStorage) : []
                            }
                        };
                        
                        console.error('=== SESSION END OVERLAY TRIGGERED (Session verified as expired) ===');
                        console.error('Status Code:', numericStatus);
                        console.error('Request URL:', requestUrl);
                        console.error('Response URL:', responseUrl);
                        console.error('Current Path:', currentPath);
                        console.error('Is API Call:', isApiCall);
                        console.error('Full Log Data:', logData);
                        
                        // Ensure all polling is stopped
                        stopAllPolling();
                        
                        // محاولة إرسال log إلى الخادم (اختياري)
                        try {
                            const basePath = window.location.pathname.split('/').slice(0, -1).join('/') || '';
                            fetch((basePath || '') + '/api/session_debug_log.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({
                                    type: 'session_end_overlay',
                                    data: logData
                                }),
                                credentials: 'same-origin'
                            }).catch(() => {
                                // تجاهل أخطاء إرسال log
                            });
                        } catch (e) {
                            console.error('Error sending session debug log:', e);
                        }
                        
                        const overlay = getOverlayElement();
                        const loginUrl = getLoginUrl(overlay);
                        showSessionOverlay(loginUrl);
                    } else {
                        // الجلسة لا تزال صالحة في قاعدة البيانات - لا نعيد التوجيه
                        console.log('Session still valid in database, ignoring 401/419/440 status');
                    }
                })
                .catch(function(error) {
                    // في حالة خطأ في التحقق من الجلسة، لا نعيد التوجيه
                    // نترك المستخدم في الصفحة الحالية
                    console.warn('Error checking session status, not redirecting:', error);
                });
            }
            // إذا لم يكن API call وليس لدينا responseUrl، نتجاهل (قد يكون redirect عادي أو form submission)
        }
    }

    const originalFetch = window.fetch;
    if (typeof originalFetch === 'function') {
        window.fetch = async function() {
            try {
                const response = await originalFetch.apply(this, arguments);
                
                // التحقق من أن الاستجابة ليست من errors.infinityfree.net
                if (response && response.url && (response.url.includes('errors.infinityfree.net') || response.url.includes('infinityfree'))) {
                    // تجاهل هذا الخطأ بصمت (لأنه خطأ من الخادم وليس من التطبيق)
                    // لا نريد إظهار رسالة خطأ للمستخدم لأنها ليست خطأ في التطبيق
                    console.warn('Ignoring infinityfree error domain response:', response.url);
                    // إرجاع استجابة فارغة بنجاح لتجنب معالجتها كخطأ
                    return new Response('', {
                        status: 200,
                        statusText: 'OK'
                    });
                }
                
                try {
                    // تمرير response.url و requestUrl للتحقق من نوع الطلب
                    const requestUrl = arguments[0] && typeof arguments[0] === 'string' ? arguments[0] : 
                                      (arguments[0] && arguments[0].url ? arguments[0].url : null);
                    
                    // تسجيل جميع الطلبات التي ترجع 401/419/440
                    if (response && SESSION_END_STATUS.has(response.status)) {
                        console.error('=== FETCH REQUEST RETURNED SESSION END STATUS ===');
                        console.error('Status:', response.status);
                        console.error('Request URL:', requestUrl);
                        console.error('Response URL:', response.url);
                        console.error('Method:', arguments[0] && arguments[0].method ? arguments[0].method : 'GET');
                    }
                    
                    handleSessionStatus(response && response.status, response && response.url, requestUrl);
                } catch (statusError) {
                    console.error('Session overlay handler (fetch) error:', statusError);
                }
                return response;
            } catch (fetchError) {
                // التعامل مع أخطاء الشبكة والأخطاء الأخرى (ERR_FAILED / Error Code: -2)
                
                // التحقق من نوع الخطأ
                const errorMessage = (fetchError.message || '').toLowerCase();
                const errorName = fetchError.name || '';
                
                // تجاهل أخطاء prefetch المباشرة (AbortError من prefetch requests)
                const isAbortError = errorName === 'AbortError' || 
                                   errorMessage.includes('aborted') ||
                                   errorMessage.includes('user aborted');
                
                // إذا كان خطأ إلغاء (مثل prefetch aborted)، تجاهله بصمت
                if (isAbortError) {
                    // هذا خطأ عادي من prefetch requests - لا نريد التعامل معه
                    return new Response(JSON.stringify({
                        success: false,
                        message: 'Request aborted',
                        aborted: true
                    }), {
                        status: 0,
                        statusText: 'Aborted'
                    });
                }
                
                // التحقق من error code -2
                const isErrorCodeMinus2 = errorMessage.includes('error code: -2') || 
                                         errorMessage.includes('error code:-2') ||
                                         errorMessage.includes('err_failed') ||
                                         (fetchError.code === -2 || fetchError.errorCode === -2);
                
                if (isErrorCodeMinus2) {
                    console.error('Error Code -2 (ERR_FAILED) detected in fetch:', fetchError);
                }
                
                // فقط تسجيل الأخطاء الحقيقية
                if (!isAbortError) {
                    console.error('Fetch error:', fetchError);
                }
                
                // إذا كان الخطأ بسبب انتهاء الجلسة أو خطأ في الاتصال
                // محاولة التوجيه إلى صفحة تسجيل الدخول بدلاً من عرض ERR_FAILED
                // فقط إذا كان من API call وليس navigation عادي أو prefetch
                const fetchUrl = arguments[0] || ''; // URL الأول من fetch arguments
                const fetchOptions = arguments[1] || {};
                
                // التحقق من أن الطلب ليس prefetch
                const isPrefetch = fetchOptions.mode === 'no-cors' || 
                                 (typeof fetchUrl === 'string' && fetchUrl.includes('prefetch')) ||
                                 document.querySelector('link[rel="prefetch"][href*="' + (typeof fetchUrl === 'string' ? fetchUrl.split('?')[0] : '') + '"]');
                
                const isApiCall = typeof fetchUrl === 'string' && (
                    fetchUrl.includes('/api/') || 
                    fetchUrl.includes('notifications.php') ||
                    fetchUrl.includes('attendance.php?action=')
                );
                
                const currentPath = window.location.pathname || '';
                const protectedPages = ['profile.php', 'attendance.php', 'index.php'];
                const isProtectedPage = protectedPages.some(page => currentPath.includes(page)) ||
                                       document.querySelector('body[data-page="profile"]') ||
                                       document.querySelector('body[data-page="attendance"]');
                
                // تجاهل أخطاء prefetch
                if (isPrefetch || isAbortError) {
                    return new Response(JSON.stringify({
                        success: false,
                        message: 'Request aborted or prefetch error',
                        aborted: true
                    }), {
                        status: 0,
                        statusText: 'Aborted'
                    });
                }
                
                // إذا كان error code -2 في صفحة تسجيل الدخول، أظهر رسالة واضحة
                if (isErrorCodeMinus2 && currentPath.includes('index.php')) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                    errorDiv.style.zIndex = '9999';
                    errorDiv.style.maxWidth = '600px';
                    errorDiv.innerHTML = `
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>خطأ في الاتصال بالخادم (Error Code: -2)</strong><br>
                        <small>يرجى التحقق من:
                        <ul class="mb-0 mt-2 text-start">
                            <li>أن الخادم يعمل على المنفذ 8000</li>
                            <li>أن لا يوجد جدار ناري يمنع الاتصال</li>
                            <li>اتصال الإنترنت</li>
                        </ul>
                        </small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.body.appendChild(errorDiv);
                    setTimeout(() => {
                        if (errorDiv.parentNode) {
                            errorDiv.remove();
                        }
                    }, 10000);
                    
                    // لا نعيد التوجيه في صفحة تسجيل الدخول
                    return new Response(JSON.stringify({
                        success: false,
                        message: 'Network error',
                        error_code: -2
                    }), {
                        status: 500,
                        statusText: 'Network Error',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });
                }
                
                // منع إعادة التوجيه في الصفحات المحمية أو إذا لم يكن API call
                // لكن إذا كان ERR_FAILED في صفحة الداشبورد بعد انتهاء الجلسة، نعيد التوجيه
                if (!isProtectedPage && isApiCall && !isPrefetch) {
                    // إذا كان ERR_FAILED في صفحة الداشبورد، قد تكون الجلسة منتهية
                    // محاولة التحقق من الجلسة أولاً
                    if (isErrorCodeMinus2 && currentPath.includes('/dashboard/')) {
                        // محاولة التحقق من الجلسة قبل إعادة التوجيه
                        const checkSessionUrl = getApiPath('api/check_session.php');
                        fetch(checkSessionUrl, {
                            method: 'GET',
                            credentials: 'same-origin',
                            cache: 'no-cache',
                            signal: createTimeoutSignal(5000)
                        })
                        .then(function(response) {
                            return response.json().catch(() => ({ success: false }));
                        })
                        .then(function(data) {
                            // إذا كانت الجلسة منتهية، أعد التوجيه
                            if (!data.success || !data.session_exists) {
                                const overlay = getOverlayElement();
                                const loginUrl = getLoginUrl(overlay);
                                redirectToLogin(loginUrl);
                            }
                        })
                        .catch(function() {
                            // في حالة الخطأ، لا نعيد التوجيه فوراً - قد يكون خطأ مؤقت
                            console.warn('Session check failed, but not redirecting - may be temporary error');
                        });
                    } else if (!isErrorCodeMinus2) {
                        // محاولة تحديد URL تسجيل الدخول
                        const overlay = getOverlayElement();
                        const loginUrl = getLoginUrl(overlay);
                        
                        // إظهار overlay بدلاً من ERR_FAILED
                        setTimeout(() => {
                            try {
                                showSessionOverlay(loginUrl);
                            } catch (e) {
                                // إذا فشل overlay، التوجيه المباشر
                                console.warn('Could not show overlay, redirecting:', e);
                                redirectToLogin(loginUrl);
                            }
                        }, 100);
                    }
                }
                
                // إرجاع استجابة خطأ بديلة لمنع عرض ERR_FAILED
                return new Response(JSON.stringify({
                    success: false,
                    message: isErrorCodeMinus2 ? 
                        'خطأ في الاتصال بالخادم (Error Code: -2). يرجى التحقق من أن الخادم يعمل.' :
                        'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.',
                    error_code: isErrorCodeMinus2 ? -2 : undefined
                }), {
                    status: 500,
                    statusText: 'Network Error',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
            }
        };
    }

    const originalXhrOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url) {
        const xhrUrl = url || '';
        const isApiCall = typeof xhrUrl === 'string' && (
            xhrUrl.includes('/api/') || 
            xhrUrl.includes('notifications.php') ||
            xhrUrl.includes('attendance.php?action=')
        );
        
        // حفظ URL الأصلي للطلب والـ method
        this._requestUrl = xhrUrl;
        this._method = method;
        
        this.addEventListener('load', function() {
            // تسجيل جميع الطلبات التي ترجع 401/419/440
            if (SESSION_END_STATUS.has(this.status)) {
                console.error('=== XHR REQUEST RETURNED SESSION END STATUS ===');
                console.error('Status:', this.status);
                console.error('Request URL:', this._requestUrl);
                console.error('Response URL:', this.responseURL);
                console.error('Method:', this._method || 'GET');
            }
            
            // تمرير URL للتحقق من نوع الطلب (استخدم requestUrl المحفوظ)
            handleSessionStatus(this.status, this.responseURL || this._requestUrl, this._requestUrl);
        });
        this.addEventListener('error', function() {
            // معالجة أخطاء الشبكة (ERR_FAILED) في XMLHttpRequest
            // فقط إذا كان من API call
            if (isApiCall) {
                const currentPath = window.location.pathname || '';
                const protectedPages = ['profile.php', 'attendance.php', 'index.php'];
                const isProtectedPage = protectedPages.some(page => currentPath.includes(page)) ||
                                       document.querySelector('body[data-page="profile"]') ||
                                       document.querySelector('body[data-page="attendance"]');
                
                if (!isProtectedPage) {
                    const overlay = getOverlayElement();
                    const loginUrl = getLoginUrl(overlay);
                    setTimeout(() => {
                        try {
                            showSessionOverlay(loginUrl);
                        } catch (e) {
                            redirectToLogin(loginUrl);
                        }
                    }, 100);
                }
            }
            // تمرير URL للتحقق من نوع الطلب (استخدم requestUrl المحفوظ)
            handleSessionStatus(this.status, this.responseURL || this._requestUrl, this._requestUrl);
        });
        return originalXhrOpen.apply(this, arguments);
    };

    window.addEventListener('forceSessionEndNotice', function() {
        const loginUrl = '/index.php';
        showSessionOverlay(loginUrl);
    });
})();

// ========== إصلاح مشكلة عدم ظهور خيارات الـ dropdown في النماذج ==========
// هذا الحل يعمل على جميع النماذج في التطبيق
(function() {
    'use strict';
    
    function fixModalDropdowns() {
        // معالجة جميع النماذج
        const modals = document.querySelectorAll('.modal');
        
        modals.forEach(modal => {
            // عند فتح النموذج
            modal.addEventListener('shown.bs.modal', function() {
                const modalBody = this.querySelector('.modal-body');
                const modalContent = this.querySelector('.modal-content');
                const modalDialog = this.querySelector('.modal-dialog');
                
                if (!modalBody) return;
                
                // التأكد من أن modal-content و modal-dialog يسمحان بالـ overflow
                if (modalContent) {
                    modalContent.style.overflow = 'visible';
                }
                if (modalDialog) {
                    modalDialog.style.overflow = 'visible';
                }
                
                // إيجاد جميع select elements داخل modal-body
                const selects = modalBody.querySelectorAll('select.form-select, select');
                
                selects.forEach(select => {
                    // عند click على select (لأن native select يفتح dropdown عند click)
                    select.addEventListener('mousedown', function(e) {
                        const bodyStyle = window.getComputedStyle(modalBody);
                        
                        // إذا كان modal-body له overflow-y، نحاول حل المشكلة
                        if (bodyStyle.overflowY === 'auto' || bodyStyle.overflowY === 'scroll') {
                            // تحديد موقع select بالنسبة لـ modal-body
                            const selectRect = this.getBoundingClientRect();
                            const bodyRect = modalBody.getBoundingClientRect();
                            
                            // إذا كان select قريب من أسفل modal-body، قد نحتاج لـ scroll
                            const distanceFromBottom = bodyRect.bottom - selectRect.bottom;
                            
                            // التأكد من أن select مرئي بشكل كامل
                            if (distanceFromBottom < 200) {
                                // Scroll إلى select لضمان ظهور dropdown بشكل أفضل
                                setTimeout(() => {
                                    this.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                                }, 10);
                            }
                            
                            // تحسين z-index
                            this.style.position = 'relative';
                            this.style.zIndex = '1060';
                        }
                    });
                    
                    // عند focus أيضاً
                    select.addEventListener('focus', function() {
                        const bodyStyle = window.getComputedStyle(modalBody);
                        if (bodyStyle.overflowY === 'auto' || bodyStyle.overflowY === 'scroll') {
                            this.style.position = 'relative';
                            this.style.zIndex = '1060';
                        }
                    });
                });
            });
        });
    }
    
    // تشغيل الحل عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fixModalDropdowns);
    } else {
        fixModalDropdowns();
    }
    
    // إعادة تشغيل الحل عند إضافة نماذج ديناميكية
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1 && (node.classList.contains('modal') || node.querySelector('.modal'))) {
                            fixModalDropdowns();
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
})();


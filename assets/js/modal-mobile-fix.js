/**
 * Modal Mobile Fix - Dynamic Height Adjustment
 * إصلاح النماذج على الهواتف المحمولة - ضبط الارتفاع الديناميكي
 */

(function() {
    'use strict';
    
    // التحقق من أننا على هاتف محمول
    function isMobile() {
        return window.innerWidth <= 768;
    }
    
    /**
     * حساب الارتفاع الفعلي المتاح للشاشة
     */
    function getAvailableHeight() {
        return window.innerHeight;
    }
    
    /**
     * ضبط ارتفاع النموذج الديناميكي
     */
    function adjustModalHeight(modalElement) {
        if (!modalElement || !isMobile()) return;
        
        const modalDialog = modalElement.querySelector('.modal-dialog');
        const modalContent = modalElement.querySelector('.modal-content');
        const modalHeader = modalElement.querySelector('.modal-header');
        const modalBody = modalElement.querySelector('.modal-body');
        const modalFooter = modalElement.querySelector('.modal-footer');
        
        if (!modalDialog || !modalContent) return;
        
        // حساب الارتفاع الفعلي المتاح (استخدام window.innerHeight)
        const availableHeight = getAvailableHeight();
        const margin = 16; // 0.5rem = 8px * 2 = 16px
        const maxHeight = availableHeight - margin;
        
        // تعيين الارتفاع الأقصى للنموذج
        modalDialog.style.maxHeight = maxHeight + 'px';
        modalDialog.style.height = maxHeight + 'px';
        
        // التأكد من أن modal-dialog يستخدم flexbox
        modalDialog.style.display = 'flex';
        modalDialog.style.flexDirection = 'column';
        modalDialog.style.overflow = 'hidden';
        
        // تعيين الارتفاع الأقصى لمحتوى النموذج
        modalContent.style.maxHeight = '100%';
        modalContent.style.height = '100%';
        modalContent.style.display = 'flex';
        modalContent.style.flexDirection = 'column';
        modalContent.style.overflow = 'hidden';
        
        // حساب ارتفاع الرأس والتذييل
        const headerHeight = modalHeader ? modalHeader.offsetHeight : 0;
        const footerHeight = modalFooter ? modalFooter.offsetHeight : 0;
        
        // حساب ارتفاع الجسم المتاح
        const bodyMaxHeight = maxHeight - headerHeight - footerHeight;
        
        // تعيين خصائص الرأس
        if (modalHeader) {
            modalHeader.style.flexShrink = '0';
        }
        
        // التحقق من وجود modal-dialog-scrollable
        const isScrollable = modalDialog.classList.contains('modal-dialog-scrollable');
        
        // إذا كان modal-dialog-scrollable، نحتاج تغيير سلوك Bootstrap
        if (isScrollable) {
            // منع overflow على modal-content - هذا مهم جداً
            modalContent.style.overflow = 'hidden';
            modalContent.style.overflowY = 'hidden';
            modalContent.style.overflowX = 'hidden';
        }
        
        // تعيين خصائص الجسم
        if (modalBody) {
            // إزالة أي max-height محدد مسبقاً (inline styles)
            modalBody.style.maxHeight = '';
            modalBody.style.height = '';
            
            // تعيين خصائص flexbox - استخدام 0 1 auto لمنع التمدد التلقائي
            modalBody.style.flex = '0 1 auto';
            modalBody.style.flexGrow = '0';
            modalBody.style.flexShrink = '1';
            modalBody.style.flexBasis = 'auto';
            modalBody.style.minHeight = '0';
            modalBody.style.overflowY = 'auto';
            modalBody.style.overflowX = 'hidden';
            modalBody.style.webkitOverflowScrolling = 'touch';
            modalBody.style.position = 'relative';
            
            // إضافة padding-bottom إضافي لضمان ظهور جميع الحقول
            const safeAreaBottom = parseInt(getComputedStyle(document.documentElement)
                .getPropertyValue('env(safe-area-inset-bottom)')) || 0;
            const currentPadding = parseInt(getComputedStyle(modalBody).paddingBottom) || 16;
            // زيادة padding-bottom بشكل كبير لضمان ظهور جميع الحقول والأزرار
            modalBody.style.paddingBottom = Math.max(currentPadding, 60 + safeAreaBottom) + 'px';
            
            // التأكد من أن modal-body يمكن أن يكون scrollable
            // إزالة أي قيود على الارتفاع
            modalBody.style.maxHeight = 'none';
            
            // التأكد من أن التمرير يعمل - إزالة أي CSS متعارض
            modalBody.style.overflow = 'auto';
            modalBody.style.overflowY = 'auto';
            modalBody.style.overflowX = 'hidden';
            
            // إضافة event listener للتأكد من أن التمرير يعمل
            setTimeout(function() {
                if (modalBody.scrollHeight > modalBody.clientHeight) {
                    // المحتوى أكبر من المساحة المتاحة - التمرير يجب أن يعمل
                    modalBody.style.overflowY = 'auto';
                }
            }, 100);
        }
        
        // التأكد من أن التذييل مرئي دائماً
        if (modalFooter) {
            modalFooter.style.flexShrink = '0';
            modalFooter.style.position = 'relative';
            modalFooter.style.zIndex = '10';
            modalFooter.style.backgroundColor = '#fff';
            modalFooter.style.borderTop = '1px solid #dee2e6';
            modalFooter.style.marginTop = '0';
            
            const safeAreaBottom = parseInt(getComputedStyle(document.documentElement)
                .getPropertyValue('env(safe-area-inset-bottom)')) || 0;
            const currentPadding = parseInt(getComputedStyle(modalFooter).paddingBottom) || 16;
            modalFooter.style.paddingBottom = Math.max(currentPadding, 16 + safeAreaBottom) + 'px';
        }
    }
    
    /**
     * ضبط جميع النماذج المفتوحة
     */
    function adjustAllOpenModals() {
        if (!isMobile()) return;
        
        const openModals = document.querySelectorAll('.modal.show, .modal.showing');
        openModals.forEach(function(modal) {
            adjustModalHeight(modal);
        });
    }
    
    /**
     * تهيئة مراقب للنماذج
     */
    function initModalObserver() {
        if (!isMobile()) return;
        
        // استخدام Bootstrap Modal Events
        document.addEventListener('show.bs.modal', function(event) {
            const modal = event.target;
            if (modal && modal.classList.contains('modal') && isMobile()) {
                // إزالة الأنماط المضمنة قبل فتح النموذج
                const modalBody = modal.querySelector('.modal-body');
                const modalDialog = modal.querySelector('.modal-dialog');
                const modalContent = modal.querySelector('.modal-content');
                
                if (modalBody && modalBody.style.maxHeight) {
                    modalBody.style.maxHeight = '';
                }
                
                // ضبط أولي للنموذج
                if (modalDialog) {
                    modalDialog.style.display = 'flex';
                    modalDialog.style.flexDirection = 'column';
                }
                
                if (modalContent) {
                    modalContent.style.display = 'flex';
                    modalContent.style.flexDirection = 'column';
                }
            }
        });
        
        document.addEventListener('shown.bs.modal', function(event) {
            const modal = event.target;
            if (modal && modal.classList.contains('modal') && isMobile()) {
                // ضبط الارتفاع فوراً
                adjustModalHeight(modal);
                
                // إعادة ضبط بعد 10ms - مهم جداً للتأكد من التطبيق
                setTimeout(function() {
                    adjustModalHeight(modal);
                }, 10);
                
                // إعادة ضبط بعد 50ms للسماح بعرض النموذج بشكل كامل
                setTimeout(function() {
                    adjustModalHeight(modal);
                }, 50);
                
                // إعادة ضبط بعد 100ms
                setTimeout(function() {
                    adjustModalHeight(modal);
                }, 100);
                
                // إعادة ضبط بعد 200ms لضمان تطبيق جميع الأنماط
                setTimeout(function() {
                    adjustModalHeight(modal);
                }, 200);
                
                // إعادة ضبط بعد 500ms بعد تحميل المحتوى الديناميكي
                setTimeout(function() {
                    adjustModalHeight(modal);
                }, 500);
                
                // إعادة ضبط بعد 1000ms للتأكد النهائي
                setTimeout(function() {
                    adjustModalHeight(modal);
                }, 1000);
            }
        });
        
        // إعادة ضبط عند تغيير حجم النافذة
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function() {
                if (isMobile()) {
                    adjustAllOpenModals();
                }
            }, 50);
        });
        
        // إعادة ضبط عند scroll - للتأكد من أن التمرير يعمل
        document.addEventListener('scroll', function(e) {
            if (!isMobile()) return;
            
            // التحقق من أن e.target هو عنصر DOM وله دالة closest
            if (!e.target || typeof e.target.closest !== 'function') {
                // البحث عن modal-body من العنصر الذي تم التمرير عليه
                const scrollableElement = document.elementFromPoint(0, window.scrollY);
                if (!scrollableElement || typeof scrollableElement.closest !== 'function') return;
                
                const modalBody = scrollableElement.closest('.modal-body');
                if (!modalBody) return;
                
                const modal = modalBody.closest('.modal');
                if (modal && (modal.classList.contains('show') || modal.classList.contains('showing'))) {
                    const modalFooter = modal.querySelector('.modal-footer');
                    if (modalFooter) {
                        modalFooter.style.position = 'relative';
                        modalFooter.style.zIndex = '10';
                    }
                }
                return;
            }
            
            if (e.target.closest('.modal-body')) {
                const modal = e.target.closest('.modal');
                if (modal && (modal.classList.contains('show') || modal.classList.contains('showing'))) {
                    // التأكد من أن التذييل مرئي
                    const modalFooter = modal.querySelector('.modal-footer');
                    if (modalFooter) {
                        modalFooter.style.position = 'relative';
                        modalFooter.style.zIndex = '10';
                    }
                }
            }
        }, true);
        
        // إعادة ضبط عند تغيير اتجاه الشاشة
        window.addEventListener('orientationchange', function() {
            setTimeout(function() {
                if (isMobile()) {
                    adjustAllOpenModals();
                }
            }, 200);
        });
    }
    
    /**
     * مراقبة التغييرات في محتوى النموذج
     */
    function observeModalContent() {
        if (!isMobile()) return;
        
        // استخدام MutationObserver لمراقبة التغييرات في modal-body
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' || mutation.type === 'attributes') {
                    const modal = mutation.target.closest('.modal');
                    if (modal && (modal.classList.contains('show') || modal.classList.contains('showing'))) {
                        setTimeout(function() {
                            adjustModalHeight(modal);
                        }, 100);
                    }
                }
            });
        });
        
        // مراقبة جميع النماذج
        document.querySelectorAll('.modal').forEach(function(modal) {
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                observer.observe(modalBody, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['style', 'class']
                });
            }
        });
    }
    
    /**
     * إزالة الأنماط المضمنة من النماذج عند التحميل
     */
    function removeInlineMaxHeightStyles() {
        if (!isMobile()) return;
        
        // البحث عن جميع modal-body التي تحتوي على max-height في inline style
        document.querySelectorAll('.modal-body[style*="max-height"]').forEach(function(modalBody) {
            // حفظ overflow-y إذا كان موجوداً
            const overflowY = modalBody.style.overflowY || 'auto';
            
            // إزالة max-height من inline style (سيتم تعيينه ديناميكياً لاحقاً)
            modalBody.style.maxHeight = '';
            
            // التأكد من وجود overflow-y
            modalBody.style.overflowY = overflowY;
        });
    }
    
    /**
     * تهيئة عند تحميل الصفحة
     */
    function init() {
        if (!isMobile()) return;
        
        // إزالة الأنماط المضمنة
        removeInlineMaxHeightStyles();
        
        // تهيئة مراقب النماذج
        initModalObserver();
        
        // مراقبة التغييرات في المحتوى
        observeModalContent();
        
        // ضبط النماذج المفتوحة حالياً (في حالة إعادة تحميل الصفحة)
        const initModals = function() {
            removeInlineMaxHeightStyles();
            setTimeout(function() {
                adjustAllOpenModals();
            }, 500);
        };
        
        // إذا كانت الصفحة محملة بالفعل
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initModals);
        } else {
            initModals();
        }
    }
    
    // تهيئة عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // تصدير الدوال للاستخدام الخارجي إذا لزم الأمر
    window.ModalMobileFix = {
        adjustModalHeight: adjustModalHeight,
        adjustAllOpenModals: adjustAllOpenModals
    };
})();


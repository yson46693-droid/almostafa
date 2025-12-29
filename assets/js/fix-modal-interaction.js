/**
 * إصلاح مشكلة عدم القدرة على التفاعل مع Modal
 * Fix Modal Interaction Issue
 * إضافة تأثير انسدال (slide down) للـ modals
 */

(function () {
    'use strict';

    function enableModal(modal) {
        if (!modal || !modal.style) {
            return;
        }

        try {
            modal.style.pointerEvents = 'auto';
            const focusable = modal.querySelector('input, select, textarea, button');
            if (focusable) {
                focusable.focus({ preventScroll: true });
            }
        } catch (error) {
            console.warn('Error enabling modal:', error);
        }
    }

    // إضافة class "fade" للـ modals التي لا تحتوي عليها لضمان عمل الـ animation
    function ensureFadeClass(modal) {
        if (modal && !modal.classList.contains('fade')) {
            modal.classList.add('fade');
        }
    }

    // عند بدء فتح الـ modal - إعداد الـ animation بشكل سلس بدون lag
    document.addEventListener('show.bs.modal', function (event) {
        const modal = event.target;
        ensureFadeClass(modal);
        enableModal(modal);
        
        const modalDialog = modal.querySelector('.modal-dialog');
        if (modalDialog) {
            // إعداد الحالة الأولية قبل بدء الـ animation
            // استخدام requestAnimationFrame لضمان عدم وجود lag
            requestAnimationFrame(function() {
                // إزالة أي styles inline قد تتعارض
                modalDialog.style.transform = '';
                modalDialog.style.opacity = '';
                modalDialog.style.transition = '';
                
                // إضافة class مؤقت لضمان بدء الـ animation بشكل صحيح
                modal.classList.add('showing');
                
                // إزالة class بعد بدء الـ animation مباشرة
                requestAnimationFrame(function() {
                    modal.classList.remove('showing');
                });
            });
        }
    });

    // عند اكتمال فتح الـ modal
    document.addEventListener('shown.bs.modal', function (event) {
        const modal = event.target;
        enableModal(modal);
        
        // التأكد من إزالة أي styles inline بعد اكتمال الـ animation
        const modalDialog = modal.querySelector('.modal-dialog');
        if (modalDialog) {
            // استخدام setTimeout مع مدة أقل لتتناسب مع CSS (0.25s)
            setTimeout(function() {
                modalDialog.style.transform = '';
                modalDialog.style.opacity = '';
                modalDialog.style.transition = '';
            }, 250);
        }
    });

    // عند بدء إغلاق الـ modal - السماح لـ CSS بالتحكم في الـ animation
    document.addEventListener('hide.bs.modal', function (event) {
        const modal = event.target;
        const modalDialog = modal.querySelector('.modal-dialog');
        if (modalDialog) {
            // إزالة أي styles inline للسماح لـ CSS بالتحكم في animation الإغلاق
            requestAnimationFrame(function() {
                modalDialog.style.transform = '';
                modalDialog.style.opacity = '';
                modalDialog.style.transition = '';
            });
        }
    });

    // التأكد من أن جميع الـ modals الموجودة لديها class "fade"
    function initModals() {
        const modals = document.querySelectorAll('.modal:not(.fade)');
        modals.forEach(function(modal) {
            modal.classList.add('fade');
        });
    }

    // تشغيل عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initModals);
    } else {
        initModals();
    }

    // مراقبة الـ modals الجديدة المضافة ديناميكياً
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) {
                        if (node.classList && node.classList.contains('modal')) {
                            ensureFadeClass(node);
                        } else if (node.querySelectorAll) {
                            const modals = node.querySelectorAll('.modal:not(.fade)');
                            modals.forEach(function(modal) {
                                ensureFadeClass(modal);
                            });
                        }
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
})();


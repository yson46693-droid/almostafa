/**
 * شاشة التحميل الديناميكية التفاعلية
 * تظهر عند الإرسال وتختفي بعد التأكد من تخزين وعرض البيانات المحدثة
 */
(function() {
    'use strict';

    var overlay = document.getElementById('global-loading-overlay');
    var loadingCount = 0;

    function showPageLoading() {
        if (!overlay) return;
        loadingCount++;
        overlay.classList.add('is-active');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function hidePageLoading() {
        if (!overlay) return;
        loadingCount = Math.max(0, loadingCount - 1);
        if (loadingCount <= 0) {
            loadingCount = 0;
            overlay.classList.remove('is-active');
            overlay.setAttribute('aria-hidden', 'true');
        }
    }

    function resetPageLoading() {
        if (!overlay) return;
        loadingCount = 0;
        overlay.classList.remove('is-active');
        overlay.setAttribute('aria-hidden', 'true');
    }

    window.showPageLoading = showPageLoading;
    window.hidePageLoading = hidePageLoading;
    window.resetPageLoading = resetPageLoading;

    function bindFormLoading() {
        overlay = document.getElementById('global-loading-overlay');
        if (!overlay) return;

        document.querySelectorAll('form:not([data-no-loading])').forEach(function(form) {
            if (form.dataset.globalLoadingBound === 'true') return;
            form.dataset.globalLoadingBound = 'true';
            form.addEventListener('submit', function() {
                showPageLoading();
            }, true);
        });

        if (!document.body.dataset.globalLoadingSubmitBound) {
            document.body.dataset.globalLoadingSubmitBound = 'true';
            document.body.addEventListener('submit', function(e) {
                var form = e.target && e.target.tagName === 'FORM' ? e.target : (e.target && e.target.closest ? e.target.closest('form') : null);
                if (!form || form.dataset.noLoading === 'true') return;
                showPageLoading();
            }, true);
        }

        window.addEventListener('pageshow', function onPageShow() {
            resetPageLoading();
        }, { once: false });

        if (typeof MutationObserver !== 'undefined') {
            var mo = new MutationObserver(function() {
                document.querySelectorAll('form:not([data-no-loading]):not([data-global-loading-bound])').forEach(function(form) {
                    form.dataset.globalLoadingBound = 'true';
                    form.addEventListener('submit', function() {
                        showPageLoading();
                    }, true);
                });
            });
            if (document.body) {
                mo.observe(document.body, { childList: true, subtree: true });
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindFormLoading);
    } else {
        bindFormLoading();
    }
})();

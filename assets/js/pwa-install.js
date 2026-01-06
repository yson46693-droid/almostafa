/**
 * PWA Install Handler
 */

// استخدام نطاق عام للتأكد من توفر المتغير
if (typeof window.pwaInstallHandler === 'undefined') {
    window.pwaInstallHandler = {};
}

// التحقق من وجود deferredPrompt قبل الإعلان لتجنب إعادة الإعلان عند تحميل الملف عدة مرات
if (typeof window.deferredPrompt === 'undefined') {
    window.deferredPrompt = null;
}
// استخدام var للسماح بإعادة الإعلان (مع التحقق أعلاه لمنع ذلك)
// لكن فقط إذا لم يكن معرفاً مسبقاً
if (typeof deferredPrompt === 'undefined') {
    var deferredPrompt = window.deferredPrompt;
} else {
    // إذا كان معرفاً مسبقاً، استخدم القيمة الموجودة
    deferredPrompt = window.deferredPrompt;
}

// التحقق من الجهاز المحمول
function isMobileDevice() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
}

// التحقق من iOS
function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

// التحقق من Android - محسّن لجميع الأجهزة القديمة والحديثة
function isAndroid() {
    const ua = navigator.userAgent.toLowerCase();
    // كشف Android بطرق متعددة لدعم الأجهزة القديمة
    return /android/i.test(ua) || 
           /android/i.test(navigator.platform) ||
           /android/i.test(navigator.vendor) ||
           (ua.indexOf('mobile') !== -1 && ua.indexOf('android') !== -1);
}

// التحقق من إصدار Android (مهم للأجهزة القديمة)
function getAndroidVersion() {
    const ua = navigator.userAgent.toLowerCase();
    const match = ua.match(/android\s([0-9\.]*)/);
    return match ? parseFloat(match[1]) : null;
}

// التحقق من Chrome على Android
function isChromeAndroid() {
    const ua = navigator.userAgent.toLowerCase();
    return isAndroid() && (
        ua.indexOf('chrome') !== -1 ||
        ua.indexOf('chromium') !== -1 ||
        ua.indexOf('crios') !== -1 // Chrome on iOS
    );
}

// التحقق من Samsung Internet Browser
function isSamsungBrowser() {
    return /samsungbrowser/i.test(navigator.userAgent);
}

// التحقق من Firefox على Android
function isFirefoxAndroid() {
    const ua = navigator.userAgent.toLowerCase();
    return isAndroid() && ua.indexOf('firefox') !== -1;
}

// التحقق من متصفح يدعم PWA (الأجهزة القديمة قد لا تدعم)
function supportsPWA() {
    // التحقق من دعم Service Worker
    if (!('serviceWorker' in navigator)) {
        return false;
    }
    
    // التحقق من دعم manifest
    if (!('serviceWorker' in navigator) && !window.matchMedia) {
        return false;
    }
    
    return true;
}

// التحقق من دعم beforeinstallprompt (الأجهزة القديمة قد لا تدعم)
function supportsBeforeInstallPrompt() {
    return typeof window !== 'undefined' && 
           'BeforeInstallPromptEvent' in window ||
           typeof window !== 'undefined' && 
           (window.chrome && window.chrome.webstore);
}

// التحقق من التثبيت - محسّن لجميع الأجهزة
function isInstalled() {
    // طريقة 1: التحقق من display-mode
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
        return true;
    }
    
    // طريقة 2: iOS Safari
    if (window.navigator.standalone === true) {
        return true;
    }
    
    // طريقة 3: Android Chrome (الطريقة القديمة)
    if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) {
        return true;
    }
    
    // طريقة 4: التحقق من وجود installed flag في sessionStorage
    try {
        if (sessionStorage.getItem('pwa_installed') === 'true') {
            return true;
        }
    } catch (e) {
        // تجاهل الأخطاء
    }
    
    return false;
}

// التحقق من حالة إخفاء الإشعار
function shouldShowBanner() {
    try {
        const dismissed = localStorage.getItem('pwa_install_dismissed');
        const dismissedTime = localStorage.getItem('pwa_install_dismissed_time');
        
        if (dismissed === 'true' && dismissedTime) {
            const timeDiff = Date.now() - parseInt(dismissedTime);
            const oneDay = 24 * 60 * 60 * 1000; // يوم واحد بالميلي ثانية
            
            if (timeDiff < oneDay) {
                // تم إخفاء الإشعار منذ أقل من يوم، لا نعرضه
                const remainingHours = Math.ceil((oneDay - timeDiff) / (60 * 60 * 1000));
                console.log(`Install banner was dismissed, will show again in ${remainingHours} hours`);
                return false;
            } else {
                // أكثر من يوم، يمكن إظهاره مرة أخرى - حذف القيم القديمة
                localStorage.removeItem('pwa_install_dismissed');
                localStorage.removeItem('pwa_install_dismissed_time');
                return true;
            }
        }
        return true; // لم يتم إخفاؤه من قبل، يمكن عرضه
    } catch (e) {
        console.error('Error checking dismiss state:', e);
        return true; // في حالة الخطأ، نعرض البانر
    }
}

// إظهار البانر
function showInstallBanner() {
    // التحقق من حالة إخفاء الإشعار أولاً
    if (!shouldShowBanner()) {
        console.log('Install banner was dismissed, not showing');
        return;
    }
    
    const banner = document.getElementById('installBanner');
    if (banner && !isInstalled()) {
        banner.classList.add('show');
        console.log('Install banner shown');
        
        // على الهواتف، إظهار البانر بعد تأخير قصير
        if (isMobileDevice()) {
            // إخفاء البانر تلقائياً بعد 30 ثانية إذا لم يتم التثبيت
            setTimeout(() => {
                if (banner.classList.contains('show') && !isInstalled()) {
                    banner.classList.add('auto-hide');
                    setTimeout(() => {
                        banner.classList.remove('show');
                    }, 500);
                }
            }, 30000);
        }
    }
}

// إخفاء البانر
function hideInstallBanner() {
    const banner = document.getElementById('installBanner');
    if (banner) {
        banner.classList.remove('show');
        console.log('Install banner hidden');
    }
}

// إخفاء البانر إذا كان التطبيق مثبتاً بالفعل
if (isInstalled()) {
    hideInstallBanner();
}

// معالجة حدث beforeinstallprompt
window.addEventListener('beforeinstallprompt', (e) => {
    console.log('beforeinstallprompt event fired', e);
    
    // Stash the event so it can be triggered later
    deferredPrompt = e;
    window.deferredPrompt = e;
    
    // معالجة فورية (لا ننتظر DOMContentLoaded)
    // لأن beforeinstallprompt يُطلق بعد تحميل الصفحة
    handleInstallPrompt(e);
    
    // على Android، إظهار البانر فوراً عند استقبال الحدث
    if (isAndroid()) {
        console.log('Android beforeinstallprompt received, showing banner');
        setTimeout(() => {
            showInstallBanner();
        }, 500);
    }
});

// دالة معالجة beforeinstallprompt
function handleInstallPrompt(e) {
    // التحقق من وجود زر التثبيت والبانر في الصفحة
    const installButton = document.getElementById('installButton');
    const installBanner = document.getElementById('installBanner');
    
    // إذا كان هناك زر تثبيت وبانر، منع البانر التلقائي وعرض البانر الخاص بنا
    if (installButton && installBanner) {
        // التحقق من حالة إخفاء الإشعار
        if (!shouldShowBanner()) {
            console.log('Install banner was dismissed, allowing default prompt');
            // لا نمنع البانر الأصلي إذا كان المستخدم قد أخفى البانر
            return;
        }
        
        // منع البانر التلقائي وعرض البانر الخاص بنا
        e.preventDefault();
        console.log('Prevented default install prompt, showing custom banner');
        // Show install banner (سيتم التحقق من حالة الإخفاء داخلياً)
        showInstallBanner();
    } else {
        // إذا لم يكن هناك زر أو بانر، لا نمنع البانر الأصلي
        console.log('Install button or banner not found, allowing default install prompt');
        // لا نستدعي preventDefault() - نسمح للمتصفح بعرض البانر الأصلي
    }
}

// معالجة النقر على زر التثبيت وإغلاق البانر
document.addEventListener('DOMContentLoaded', function() {
    const installButton = document.getElementById('installButton');
    const dismissButton = document.getElementById('dismissInstallBanner');
    
    // معالجة إغلاق البانر
    if (dismissButton) {
        dismissButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            hideInstallBanner();
            
            // إذا كان deferredPrompt موجوداً ولم يتم استدعاء prompt() بعد، 
            // نعيد تفعيله للسماح للمتصفح بعرض البانر الأصلي في المرة القادمة
            if (deferredPrompt) {
                console.log('Banner dismissed, deferredPrompt will be available for next time');
                // لا نحذف deferredPrompt - نتركه للمتصفح لاستخدامه لاحقاً
            }
            
            // حفظ في localStorage أن المستخدم رفض التثبيت لمدة يوم
            try {
                localStorage.setItem('pwa_install_dismissed', 'true');
                localStorage.setItem('pwa_install_dismissed_time', Date.now().toString());
                console.log('Install banner dismissed, will not show for 24 hours');
            } catch (e) {
                console.error('Error saving dismiss state:', e);
            }
        });
    }
    
    // التحقق من حالة إخفاء الإشعار
    const canShowBanner = shouldShowBanner();
    
    // إظهار البانر على الهواتف حتى لو لم يكن beforeinstallprompt متاحاً
    if (canShowBanner && isMobileDevice() && !isInstalled()) {
        // على Android، إظهار البانر دائماً (حتى لو لم يكن هناك deferredPrompt)
        if (isAndroid()) {
            console.log('Android device detected, scheduling install banner');
            const androidVersion = getAndroidVersion();
            const isOldAndroid = androidVersion && androidVersion < 5.0;
            const isChrome = isChromeAndroid();
            const isSamsung = isSamsungBrowser();
            const isFirefox = isFirefoxAndroid();
            
            console.log('Android details:', {
                version: androidVersion,
                isOld: isOldAndroid,
                isChrome: isChrome,
                isSamsung: isSamsung,
                isFirefox: isFirefox,
                supportsPWA: supportsPWA(),
                supportsBeforeInstallPrompt: supportsBeforeInstallPrompt()
            });
            
            // دالة لإظهار البانر مع التحقق المتكرر
            function tryShowBanner() {
                const banner = document.getElementById('installBanner');
                if (banner) {
                    console.log('Banner element found, showing install banner on Android');
                    
                    // تحديث نص البانر بناءً على نوع المتصفح والإصدار
                    const bannerTitle = banner.querySelector('.install-banner-title');
                    const bannerDescription = banner.querySelector('.install-banner-description');
                    if ((bannerTitle || bannerDescription) && !deferredPrompt) {
                        let title = 'تثبيت التطبيق';
                        let description = '';
                        
                        if (isSamsung) {
                            description = 'اضغط على زر القائمة (⋮) ثم اختر "إضافة إلى الشاشة الرئيسية"';
                        } else if (isFirefox) {
                            description = 'اضغط على زر القائمة (⋮) ثم اختر "تثبيت" أو "Install"';
                        } else if (isOldAndroid || !isChrome) {
                            description = 'اضغط على زر القائمة (⋮) ثم اختر "إضافة إلى الشاشة الرئيسية" أو "Add to Home Screen"';
                        } else {
                            description = 'اضغط على زر التثبيت أدناه أو من قائمة المتصفح';
                        }
                        
                        if (bannerTitle) {
                            bannerTitle.textContent = title;
                        }
                        if (bannerDescription) {
                            bannerDescription.textContent = description;
                        }
                    }
                    
                    // التحقق من أن beforeinstallprompt لم يحدث بعد
                    // إذا لم يكن هناك deferredPrompt، نعرض البانر مع تعليمات يدوية
                    if (!deferredPrompt) {
                        console.log('No deferredPrompt yet, showing banner with manual instructions');
                        showInstallBanner();
                    } else {
                        console.log('deferredPrompt available, showing banner');
                        showInstallBanner();
                    }
                } else {
                    console.log('Banner element not found yet, retrying...');
                    // إعادة المحاولة بعد 500ms
                    setTimeout(tryShowBanner, 500);
                }
            }
            
            // محاولة إظهار البانر بعد تحميل الصفحة
            // نستخدم window.load للتأكد من تحميل جميع الموارد
            if (document.readyState === 'complete') {
                // الصفحة محملة بالفعل
                setTimeout(tryShowBanner, isOldAndroid ? 3000 : 2000);
            } else {
                // انتظر تحميل الصفحة بالكامل
                window.addEventListener('load', function() {
                    setTimeout(tryShowBanner, isOldAndroid ? 3000 : 2000);
                }, { once: true });
            }
            
            // أيضاً محاولة بعد DOMContentLoaded (للموارد السريعة)
            setTimeout(tryShowBanner, isOldAndroid ? 4000 : 3000);
        }
        
        // على iOS، إظهار البانر دائماً مع تعليمات خاصة
        if (isIOS()) {
            console.log('iOS device detected, scheduling install banner');
            setTimeout(() => {
                console.log('Showing install banner on iOS');
                showInstallBanner();
                // تحديث نص البانر لـ iOS
                const banner = document.getElementById('installBanner');
                if (banner) {
                    const bannerTitle = banner.querySelector('.install-banner-title');
                    const bannerDescription = banner.querySelector('.install-banner-description');
                    if (bannerTitle) {
                        bannerTitle.textContent = 'تثبيت التطبيق';
                    }
                    if (bannerDescription) {
                        bannerDescription.innerHTML = 'اضغط على زر المشاركة <i class="bi bi-share"></i> ثم اختر "إضافة إلى الشاشة الرئيسية"';
                    }
                    const installBtn = document.getElementById('installButton');
                    if (installBtn) {
                        installBtn.innerHTML = '<i class="bi bi-info-circle me-1"></i>كيفية التثبيت';
                    }
                }
            }, 2000);
        }
    }
    
    if (installButton) {
        console.log('Install button found, adding event listener');
        
        installButton.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Install button clicked');
            
            // على iOS، عرض تعليمات التثبيت
            if (isIOS()) {
                e.preventDefault();
                alert('لتثبيت التطبيق على iPhone/iPad:\n\n1. اضغط على زر المشاركة (Share) في أسفل المتصفح\n2. اختر "إضافة إلى الشاشة الرئيسية" (Add to Home Screen)\n3. اضغط "إضافة" (Add) في الأعلى\n\nسيظهر التطبيق على الشاشة الرئيسية بعد ذلك.');
                return;
            }
            
            if (!deferredPrompt) {
                console.warn('No deferred prompt available');
                // على Android، إعطاء تعليمات بديلة مخصصة لكل متصفح
                if (isAndroid()) {
                    const androidVersion = getAndroidVersion();
                    const isOldAndroid = androidVersion && androidVersion < 5.0;
                    const isChrome = isChromeAndroid();
                    const isSamsung = isSamsungBrowser();
                    const isFirefox = isFirefoxAndroid();
                    
                    let instructions = 'لتثبيت التطبيق على Android:\n\n';
                    
                    if (isSamsung) {
                        instructions += 'Samsung Internet Browser:\n' +
                            '1. اضغط على زر القائمة (⋮) في أسفل المتصفح\n' +
                            '2. اختر "إضافة إلى الشاشة الرئيسية" أو "Add to Home Screen"\n' +
                            '3. اضغط "إضافة" أو "Add"\n\n';
                    } else if (isFirefox) {
                        instructions += 'Firefox Browser:\n' +
                            '1. اضغط على زر القائمة (⋮) في أعلى المتصفح\n' +
                            '2. اختر "تثبيت" أو "Install"\n' +
                            '3. اضغط "تثبيت" أو "Install" للتأكيد\n\n';
                    } else if (isChrome) {
                        instructions += 'Chrome Browser:\n' +
                            'الطريقة 1 (الأسهل):\n' +
                            '1. اضغط على زر القائمة (⋮) في أعلى المتصفح\n' +
                            '2. ابحث عن "إضافة إلى الشاشة الرئيسية" أو "Install app" أو "Add to Home Screen"\n' +
                            '3. اضغط عليه واتبع التعليمات\n\n' +
                            'الطريقة 2:\n' +
                            '1. انتظر حتى يظهر إشعار "إضافة إلى الشاشة الرئيسية" تلقائياً\n' +
                            '2. اضغط على الإشعار واتبع التعليمات\n\n';
                    } else {
                        instructions += 'الطريقة 1:\n' +
                            '1. اضغط على زر القائمة (⋮) في أعلى المتصفح\n' +
                            '2. ابحث عن "إضافة إلى الشاشة الرئيسية" أو "Add to Home Screen"\n' +
                            '3. اضغط عليه واتبع التعليمات\n\n' +
                            'الطريقة 2:\n' +
                            '1. في بعض المتصفحات، يمكنك الضغط على "إضافة إلى الشاشة الرئيسية" من قائمة الصفحة\n' +
                            '2. ابحث في الإعدادات عن خيار "إضافة إلى الشاشة الرئيسية"\n\n';
                    }
                    
                    if (isOldAndroid) {
                        instructions += 'ملاحظة: على إصدارات Android القديمة، قد تحتاج إلى:\n' +
                            '1. التأكد من أن الموقع يعمل على HTTPS\n' +
                            '2. التحقق من إعدادات المتصفح للسماح بالتثبيت\n' +
                            '3. تحديث المتصفح إلى آخر إصدار إذا أمكن\n\n';
                    }
                    
                    instructions += 'ملاحظة عامة: يجب أن يكون الموقع على HTTPS أو localhost لعمل التثبيت.';
                    
                    alert(instructions);
                } else {
                    alert('زر التثبيت غير متاح حالياً. يرجى المحاولة لاحقاً أو تثبيت التطبيق من قائمة المتصفح.');
                }
                return;
            }
            
            try {
                // Show the install prompt
                console.log('Showing install prompt');
                
                // التأكد من أن deferredPrompt صالح
                if (!deferredPrompt || typeof deferredPrompt.prompt !== 'function') {
                    throw new Error('Install prompt is not available');
                }
                
                // استدعاء prompt() - هذا يحل مشكلة التحذير
                deferredPrompt.prompt();
                
                // Wait for the user to respond to the prompt
                const { outcome } = await deferredPrompt.userChoice;
                
                console.log(`User response: ${outcome}`);
                
                if (outcome === 'accepted') {
                    console.log('User accepted the install prompt');
                    hideInstallBanner();
                    // البانر سيختفي تلقائياً بعد التثبيت
                } else {
                    console.log('User dismissed the install prompt');
                    // إذا رفض المستخدم، إخفاء البانر
                    hideInstallBanner();
                }
                
                // Clear the deferredPrompt بعد استخدامه
                deferredPrompt = null;
                window.deferredPrompt = null;
                
            } catch (error) {
                console.error('Error showing install prompt:', error);
                
                // إذا فشل prompt()، إعادة تفعيل deferredPrompt
                if (deferredPrompt && error.message.includes('not available')) {
                    deferredPrompt = null;
                    window.deferredPrompt = null;
                }
                
                alert('حدث خطأ أثناء محاولة التثبيت: ' + error.message);
            }
        });
    }
    // Silent fail - install button may not exist on all pages
});

// تتبع التثبيت - محسّن لجميع الأجهزة
window.addEventListener('appinstalled', () => {
    console.log('PWA was installed');
    hideInstallBanner();
    deferredPrompt = null;
    window.deferredPrompt = null;
    
    // حفظ حالة التثبيت في sessionStorage (للدعم على الأجهزة القديمة)
    try {
        sessionStorage.setItem('pwa_installed', 'true');
    } catch (e) {
        // تجاهل الأخطاء
    }
    
    // إظهار رسالة نجاح
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.maxWidth = '90%';
    alertDiv.innerHTML = `
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>تم تثبيت التطبيق بنجاح!</strong><br>
        <small>يمكنك الآن فتحه من الشاشة الرئيسية</small>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
});

// التحقق من التثبيت عند تحميل الصفحة (للأجهزة القديمة)
if (isInstalled()) {
    try {
        sessionStorage.setItem('pwa_installed', 'true');
    } catch (e) {
        // تجاهل الأخطاء
    }
    hideInstallBanner();
}

// التحقق من تسجيل Service Worker (مهم لـ Android PWA)
function checkServiceWorkerRegistration() {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(registrations => {
            if (registrations.length > 0) {
                console.log('Service Worker is registered:', registrations.length, 'registration(s)');
                registrations.forEach((reg, index) => {
                    console.log(`SW ${index + 1}:`, {
                        scope: reg.scope,
                        active: reg.active ? 'active' : 'inactive',
                        installing: reg.installing ? 'installing' : 'none',
                        waiting: reg.waiting ? 'waiting' : 'none'
                    });
                });
            } else {
                console.warn('No Service Worker registrations found - PWA installation may not work');
            }
        }).catch(err => {
            console.error('Error checking Service Worker:', err);
        });
    } else {
        console.warn('Service Worker not supported in this browser');
    }
}

// التحقق من Manifest (مهم لـ Android PWA)
function checkManifest() {
    const manifestLink = document.querySelector('link[rel="manifest"]');
    if (manifestLink) {
        const manifestUrl = manifestLink.href;
        console.log('Manifest link found:', manifestUrl);
        
        // محاولة جلب manifest للتحقق من صحته
        fetch(manifestUrl)
            .then(response => {
                if (response.ok) {
                    return response.json();
                }
                throw new Error('Manifest fetch failed: ' + response.status);
            })
            .then(manifest => {
                console.log('Manifest loaded successfully:', {
                    name: manifest.name,
                    short_name: manifest.short_name,
                    start_url: manifest.start_url,
                    display: manifest.display,
                    icons: manifest.icons ? manifest.icons.length : 0,
                    has_192_icon: manifest.icons?.some(icon => icon.sizes === '192x192'),
                    has_512_icon: manifest.icons?.some(icon => icon.sizes === '512x512')
                });
                
                // التحقق من المتطلبات الأساسية لـ Android
                const requirements = {
                    hasName: !!manifest.name,
                    hasShortName: !!manifest.short_name,
                    hasStartUrl: !!manifest.start_url,
                    hasDisplay: manifest.display === 'standalone' || manifest.display === 'fullscreen',
                    hasIcons: manifest.icons && manifest.icons.length > 0,
                    has192Icon: manifest.icons?.some(icon => icon.sizes === '192x192' || icon.sizes?.includes('192')),
                    has512Icon: manifest.icons?.some(icon => icon.sizes === '512x512' || icon.sizes?.includes('512'))
                };
                
                const allMet = Object.values(requirements).every(v => v === true);
                if (allMet) {
                    console.log('✅ All PWA requirements met for Android installation');
                } else {
                    console.warn('⚠️ Some PWA requirements not met:', requirements);
                }
            })
            .catch(err => {
                console.error('Error loading manifest:', err);
            });
    } else {
        console.warn('Manifest link not found in HTML');
    }
}

// تشغيل التحقق بعد تحميل الصفحة
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            checkServiceWorkerRegistration();
            checkManifest();
        }, 1000);
    });
} else {
    setTimeout(() => {
        checkServiceWorkerRegistration();
        checkManifest();
    }, 1000);
}

// إضافة معالج خاص للأجهزة القديمة التي لا تدعم beforeinstallprompt
// هذا يساعد على إظهار البانر حتى لو لم يكن الحدث متاحاً
if (isAndroid() && !supportsBeforeInstallPrompt() && !isInstalled()) {
    // للأجهزة القديمة: محاولة إظهار البانر بعد فترة معينة
    setTimeout(() => {
        if (!deferredPrompt && !isInstalled() && shouldShowBanner()) {
            console.log('Old Android device detected, showing manual install instructions');
            const androidVersion = getAndroidVersion();
            const isOldAndroid = androidVersion && androidVersion < 5.0;
            
            // تأخير أطول للأجهزة القديمة
            const delay = isOldAndroid ? 5000 : 3000;
            
            setTimeout(() => {
                showInstallBanner();
                
                // تحديث النص للأجهزة القديمة
                const banner = document.getElementById('installBanner');
                if (banner) {
                    const bannerTitle = banner.querySelector('.install-banner-title');
                    const bannerDescription = banner.querySelector('.install-banner-description');
                    if (bannerTitle) {
                        bannerTitle.textContent = 'تثبيت التطبيق';
                    }
                    if (bannerDescription) {
                        bannerDescription.textContent = 'اضغط على زر القائمة (⋮) ثم اختر "إضافة إلى الشاشة الرئيسية"';
                    }
                }
            }, delay);
        }
    }, 1000);
}

// حفظ في النطاق العام للوصول من أي مكان
window.pwaInstallHandler = {
    deferredPrompt: () => deferredPrompt,
    showInstallBanner: showInstallBanner,
    hideInstallBanner: hideInstallBanner,
    isInstalled: isInstalled,
    checkServiceWorker: checkServiceWorkerRegistration,
    checkManifest: checkManifest,
    isAndroid: isAndroid,
    getAndroidVersion: getAndroidVersion,
    supportsPWA: supportsPWA,
    supportsBeforeInstallPrompt: supportsBeforeInstallPrompt,
    isChromeAndroid: isChromeAndroid,
    isSamsungBrowser: isSamsungBrowser,
    isFirefoxAndroid: isFirefoxAndroid
};


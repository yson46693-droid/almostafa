/**
 * نظام تسجيل الحضور والانصراف مع الكاميرا
 */

let currentStream = null;
let capturedPhoto = null;
let currentAction = null;

// دالة للتحقق من الموبايل
function isMobile() {
    // التحقق من عرض الشاشة
    const width = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
    // التحقق من user agent أيضاً
    const isMobileUA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    return width <= 768 || isMobileUA;
}

// دالة للـ scroll تلقائي محسّنة
function scrollToElement(element) {
    if (!element) return;
    
    // الانتظار قليلاً للتأكد من أن العنصر ظاهر
    setTimeout(function() {
        // استخدام getBoundingClientRect للحصول على الموضع النسبي
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80; // offset من الأعلى (لإعطاء مساحة للـ header)
        const targetPosition = elementTop - offset;
        
        // استخدام requestAnimationFrame لضمان smooth scroll
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, targetPosition), // التأكد من عدم السكرول لأعلى من الصفحة
                behavior: 'smooth'
            });
        });
    }, 200);
}

// الحصول على API path ديناميكياً
function getAttendanceApiPath() {
    const currentPath = window.location.pathname;
    const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php') && p !== 'dashboard' && p !== 'modules');
    
    // بناء المسار الأساسي
    let basePath = '/';
    if (pathParts.length > 0) {
        basePath = '/' + pathParts[0] + '/';
    }
    
    return basePath + 'api/attendance.php';
}

// تهيئة الكاميرا
async function initCamera() {
    try {
        console.log('initCamera called');
        
        // تحديد العناصر حسب نوع الجهاز
        const isMobileDevice = isMobile();
        console.log('Device type:', isMobileDevice ? 'mobile' : 'desktop');
        
        const video = isMobileDevice ? document.getElementById('videoCard') : document.getElementById('video');
        const cameraLoading = isMobileDevice ? document.getElementById('cameraLoadingCard') : document.getElementById('cameraLoading');
        const cameraError = isMobileDevice ? document.getElementById('cameraErrorCard') : document.getElementById('cameraError');
        
        console.log('Video elements:', {
            video: !!video,
            videoId: isMobileDevice ? 'videoCard' : 'video',
            cameraLoading: !!cameraLoading,
            cameraError: !!cameraError
        });
        
        if (!video) {
            const errorMsg = 'عنصر الفيديو غير موجود (ID: ' + (isMobileDevice ? 'videoCard' : 'video') + ')';
            console.error(errorMsg);
            showCameraError(errorMsg);
            return;
        }
        
        // التأكد من أن عنصر الفيديو مرئي
        const videoStyle = window.getComputedStyle(video);
        console.log('Video element styles:', {
            display: videoStyle.display,
            visibility: videoStyle.visibility,
            width: videoStyle.width,
            height: videoStyle.height
        });
        
        // إظهار حالة التحميل
        if (cameraLoading) {
            cameraLoading.style.display = 'block';
            cameraLoading.style.visibility = 'visible';
            console.log('Camera loading indicator shown');
        }
        if (cameraError) {
            cameraError.style.display = 'none';
            cameraError.style.visibility = 'hidden';
        }
        video.style.display = 'none';
        video.style.visibility = 'hidden';
        
        console.log('Requesting camera access...');
        
        // التحقق من دعم getUserMedia
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            // محاولة استخدام API القديم
            const getUserMedia = navigator.getUserMedia || 
                                navigator.webkitGetUserMedia || 
                                navigator.mozGetUserMedia || 
                                navigator.msGetUserMedia;
            
            if (!getUserMedia) {
                throw new Error('الكاميرا غير مدعومة في هذا المتصفح');
            }
        }
        
        // إيقاف أي stream سابق
        if (currentStream) {
            stopCamera();
        }
        
        // إعادة تعيين srcObject
        video.srcObject = null;
        
        // محاولة الوصول للكاميرا مع خيارات مختلفة
        const constraints = {
            video: {
                width: { ideal: 1280, min: 640 },
                height: { ideal: 720, min: 480 },
                aspectRatio: { ideal: 16/9 },
                facingMode: { ideal: 'user' } // الكاميرا الأمامية بشكل افتراضي
            }
        };
        
        // محاولة الوصول للكاميرا
        let stream = null;
        try {
            console.log('Attempting to get user media with constraints:', constraints);
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                stream = await navigator.mediaDevices.getUserMedia(constraints);
                console.log('Camera stream obtained successfully');
            } else {
                // استخدام API القديم
                console.log('Using legacy getUserMedia API');
                return new Promise((resolve, reject) => {
                    const getUserMedia = navigator.getUserMedia || 
                                        navigator.webkitGetUserMedia || 
                                        navigator.mozGetUserMedia;
                    if (!getUserMedia) {
                        reject(new Error('getUserMedia not supported'));
                        return;
                    }
                    getUserMedia.call(navigator, constraints, (stream) => {
                        console.log('Legacy API: Camera stream obtained');
                        resolve(stream);
                    }, (error) => {
                        console.error('Legacy API: Error getting camera:', error);
                        reject(error);
                    });
                });
            }
        } catch (firstError) {
            console.warn('First attempt failed:', firstError.name, firstError.message);
            // إذا فشلت المحاولة الأولى، جرب بدون تحديد facingMode
            if (constraints.video.facingMode) {
                delete constraints.video.facingMode;
                console.log('Retrying without facingMode constraint');
                try {
                    stream = await navigator.mediaDevices.getUserMedia(constraints);
                    console.log('Camera stream obtained on retry');
                } catch (secondError) {
                    console.error('Retry also failed:', secondError.name, secondError.message);
                    throw firstError;
                }
            } else {
                throw firstError;
            }
        }
        
        console.log('Camera stream obtained, attaching to video element');
        currentStream = stream;
        video.srcObject = currentStream;
        
        // إظهار عنصر الفيديو
        video.style.display = 'block';
        video.style.visibility = 'visible';
        video.style.opacity = '1';
        
        // إجبار reflow
        video.offsetHeight;
        
        console.log('Waiting for video to be ready...');
        
        // انتظر حتى يكون الفيديو جاهزاً
        await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => {
                if (video.readyState < 2) {
                    console.error('Timeout waiting for video to load, readyState:', video.readyState);
                    reject(new Error('Timeout waiting for video to load'));
                }
            }, 10000);
            
            const onLoadedMetadata = () => {
                console.log('Video metadata loaded, readyState:', video.readyState);
                clearTimeout(timeout);
                video.play().then(() => {
                    console.log('Video playback started successfully');
                    resolve();
                }).catch(err => {
                    console.error('Error playing video:', err);
                    reject(err);
                });
            };
            
            const onError = (e) => {
                console.error('Video error event:', e);
                clearTimeout(timeout);
                reject(new Error('Video playback error: ' + (e.message || 'Unknown error')));
            };
            
            video.addEventListener('loadedmetadata', onLoadedMetadata, { once: true });
            video.addEventListener('error', onError, { once: true });
            
            // إذا كان الفيديو جاهزاً بالفعل
            if (video.readyState >= 2) {
                console.log('Video already ready, readyState:', video.readyState);
                clearTimeout(timeout);
                video.play().then(() => {
                    console.log('Video playback started (already ready)');
                    resolve();
                }).catch(err => {
                    console.error('Error playing video (already ready):', err);
                    reject(err);
                });
            }
        });
        
        // إخفاء حالة التحميل
        if (cameraLoading) {
            cameraLoading.style.display = 'none';
            cameraLoading.style.visibility = 'hidden';
            console.log('Camera loading indicator hidden');
        }
        if (cameraError) {
            cameraError.style.display = 'none';
            cameraError.style.visibility = 'hidden';
        }
        
        // التأكد من أن الفيديو مرئي
        const videoRect = video.getBoundingClientRect();
        const videoComputedStyle = window.getComputedStyle(video);
        console.log('Video element final state:', {
            display: videoComputedStyle.display,
            visibility: videoComputedStyle.visibility,
            width: videoRect.width,
            height: videoRect.height,
            readyState: video.readyState,
            paused: video.paused,
            srcObject: !!video.srcObject
        });
        
        // إظهار زر التقاط الصورة بشكل واضح
        const captureBtn = isMobileDevice ? document.getElementById('captureBtnCard') : document.getElementById('captureBtn');
        if (captureBtn) {
            // استخدام setProperty مع !important لضمان الإظهار
            captureBtn.style.setProperty('display', 'inline-block', 'important');
            captureBtn.style.setProperty('visibility', 'visible', 'important');
            captureBtn.style.setProperty('opacity', '1', 'important');
            captureBtn.style.setProperty('pointer-events', 'auto', 'important');
            captureBtn.disabled = false;
            // إجبار reflow لضمان أن الزر مرئي
            captureBtn.offsetHeight;
            console.log('Capture button shown:', {
                display: captureBtn.style.display,
                visibility: captureBtn.style.visibility,
                opacity: captureBtn.style.opacity,
                disabled: captureBtn.disabled,
                buttonId: isMobileDevice ? 'captureBtnCard' : 'captureBtn'
            });
        } else {
            console.error('Capture button not found!', { 
                isMobileDevice,
                expectedId: isMobileDevice ? 'captureBtnCard' : 'captureBtn'
            });
        }
        
        console.log('Camera initialized successfully');
        
    } catch (error) {
        console.error('Error accessing camera:', error);
        showCameraError(error);
        
        // محاولة إظهار زر التقاط حتى لو فشلت الكاميرا (للمستخدم يمكنه المحاولة مرة أخرى)
        const isMobileDevice = isMobile();
        const captureBtn = isMobileDevice ? document.getElementById('captureBtnCard') : document.getElementById('captureBtn');
        if (captureBtn) {
            captureBtn.style.setProperty('display', 'inline-block', 'important');
            captureBtn.style.setProperty('visibility', 'visible', 'important');
            captureBtn.style.setProperty('opacity', '1', 'important');
            captureBtn.style.setProperty('pointer-events', 'auto', 'important');
            captureBtn.disabled = false;
            console.log('Capture button shown as fallback after camera error');
        }
    }
}

// إظهار رسالة خطأ الكاميرا
function showCameraError(error) {
    const isMobileDevice = isMobile();
    const cameraError = isMobileDevice ? document.getElementById('cameraErrorCard') : document.getElementById('cameraError');
    const cameraErrorText = isMobileDevice ? document.getElementById('cameraErrorTextCard') : document.getElementById('cameraErrorText');
    const captureBtn = isMobileDevice ? document.getElementById('captureBtnCard') : document.getElementById('captureBtn');
    const cameraLoading = isMobileDevice ? document.getElementById('cameraLoadingCard') : document.getElementById('cameraLoading');
    const video = isMobileDevice ? document.getElementById('videoCard') : document.getElementById('video');
    
    // إخفاء حالة التحميل
    if (cameraLoading) cameraLoading.style.display = 'none';
    
    // إظهار عنصر الفيديو حتى لو كان هناك خطأ (لإظهار الصندوق الأسود بدلاً من عدم وجود شيء)
    if (video) video.style.display = 'block';
    
    if (cameraError) cameraError.style.display = 'block';
    if (cameraErrorText) {
        let errorMessage = 'فشل في الوصول إلى الكاميرا. يرجى التأكد من السماح بالوصول إلى الكاميرا.';
        
        if (error && (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError')) {
            errorMessage = 'تم رفض الوصول إلى الكاميرا. يرجى السماح بالوصول في إعدادات المتصفح وإعادة المحاولة.';
        } else if (error && (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError')) {
            errorMessage = 'لم يتم العثور على كاميرا. يرجى التأكد من وجود كاميرا متصلة.';
        } else if (error && (error.name === 'NotReadableError' || error.name === 'TrackStartError')) {
            errorMessage = 'الكاميرا مستخدمة من قبل تطبيق آخر. يرجى إغلاق التطبيقات الأخرى وإعادة المحاولة.';
        } else if (error && error.message && error.message.includes('Timeout')) {
            errorMessage = 'انتهت مهلة الوصول للكاميرا. يرجى إعادة المحاولة.';
        } else if (error && error.message) {
            errorMessage = 'خطأ في الكاميرا: ' + error.message;
        } else if (typeof error === 'string') {
            errorMessage = error;
        }
        
        cameraErrorText.textContent = errorMessage;
    }
    // إظهار زر التقاط حتى لو كان هناك خطأ (للمستخدم يمكنه المحاولة مرة أخرى)
    if (captureBtn) {
        captureBtn.style.setProperty('display', 'inline-block', 'important');
        captureBtn.style.setProperty('visibility', 'visible', 'important');
        captureBtn.style.setProperty('opacity', '1', 'important');
        captureBtn.style.setProperty('pointer-events', 'auto', 'important');
        captureBtn.disabled = false;
    }
}

// إيقاف الكاميرا
function stopCamera() {
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
    }
}

// التقاط صورة
async function capturePhoto() {
    // تحديد العناصر حسب نوع الجهاز
    const isMobileDevice = isMobile();
    const video = isMobileDevice ? document.getElementById('videoCard') : document.getElementById('video');
    const canvas = isMobileDevice ? document.getElementById('canvasCard') : document.getElementById('canvas');
    const capturedImage = isMobileDevice ? document.getElementById('capturedImageCard') : document.getElementById('capturedImage');
    const cameraContainer = isMobileDevice ? document.getElementById('cameraContainerCard') : document.getElementById('cameraContainer');
    const capturedImageContainer = isMobileDevice ? document.getElementById('capturedImageContainerCard') : document.getElementById('capturedImageContainer');
    const delayReasonContainer = isMobileDevice ? document.getElementById('delayReasonContainerCard') : document.getElementById('delayReasonContainer');
    const delayReasonInput = isMobileDevice ? document.getElementById('delayReasonCard') : document.getElementById('delayReason');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    
    // تحويل إلى base64
    capturedPhoto = canvas.toDataURL('image/jpeg', 0.8);
    
    // التحقق من أن العناصر موجودة
    if (!capturedImage) {
        console.error('Captured image element not found!');
        return;
    }
    
    if (!capturedImageContainer) {
        console.error('Captured image container not found!');
        return;
    }
    
    // إيقاف الكاميرا
    stopCamera();
    
    // إخفاء الكاميرا أولاً
    if (cameraContainer) {
        cameraContainer.style.setProperty('display', 'none', 'important');
        cameraContainer.style.setProperty('visibility', 'hidden', 'important');
    }
    
    // تعيين src للصورة
    capturedImage.src = capturedPhoto;
    
    // إظهار container الصورة
    capturedImageContainer.style.setProperty('display', 'block', 'important');
    capturedImageContainer.style.setProperty('visibility', 'visible', 'important');
    capturedImageContainer.style.setProperty('opacity', '1', 'important');
    capturedImageContainer.style.setProperty('text-align', 'center', 'important');
    
    // إظهار الصورة نفسها
    capturedImage.style.setProperty('display', 'block', 'important');
    capturedImage.style.setProperty('visibility', 'visible', 'important');
    capturedImage.style.setProperty('max-width', '100%', 'important');
    capturedImage.style.setProperty('height', 'auto', 'important');
    capturedImage.style.setProperty('border-radius', '8px', 'important');
    capturedImage.style.setProperty('margin', '0 auto', 'important');
    
    // الانتظار حتى يتم تحميل الصورة
    capturedImage.onload = function() {
        console.log('Image loaded successfully:', {
            width: capturedImage.width,
            height: capturedImage.height,
            src: capturedImage.src ? 'exists' : 'missing'
        });
        
        // التأكد من أن الصورة مرئية
        capturedImageContainer.style.setProperty('display', 'block', 'important');
        capturedImage.style.setProperty('display', 'block', 'important');
    };
    
    capturedImage.onerror = function() {
        console.error('Error loading captured image');
        alert('حدث خطأ في عرض الصورة الملتقطة. يرجى المحاولة مرة أخرى.');
    };
    
    console.log('Image preview setup completed:', {
        src: capturedImage.src ? 'exists' : 'missing',
        containerDisplay: capturedImageContainer.style.display,
        imageDisplay: capturedImage.style.display
    });
    
    // إظهار أزرار إعادة التقاط والتأكيد
    if (isMobileDevice) {
        const captureBtnCard = document.getElementById('captureBtnCard');
        const retakeBtnCard = document.getElementById('retakeBtnCard');
        const submitBtnCard = document.getElementById('submitBtnCard');
        
        if (captureBtnCard) captureBtnCard.style.setProperty('display', 'none', 'important');
        if (retakeBtnCard) {
            retakeBtnCard.style.setProperty('display', 'inline-block', 'important');
            retakeBtnCard.style.setProperty('visibility', 'visible', 'important');
        }
        if (submitBtnCard) {
            submitBtnCard.style.setProperty('display', 'inline-block', 'important');
            submitBtnCard.style.setProperty('visibility', 'visible', 'important');
        }
    } else {
        const captureBtn = document.getElementById('captureBtn');
        const retakeBtn = document.getElementById('retakeBtn');
        const submitBtn = document.getElementById('submitBtn');
        
        if (captureBtn) captureBtn.style.setProperty('display', 'none', 'important');
        if (retakeBtn) {
            retakeBtn.style.setProperty('display', 'inline-block', 'important');
            retakeBtn.style.setProperty('visibility', 'visible', 'important');
        }
        if (submitBtn) {
            submitBtn.style.setProperty('display', 'inline-block', 'important');
            submitBtn.style.setProperty('visibility', 'visible', 'important');
        }
    }
    
    // تحديث ملخص الوقت بعد التقاط الصورة
    if (currentAction === 'check_in') {
        updateTimeSummary();
    }
    
    // التحقق من التأخير فقط عند تسجيل الحضور
    if (currentAction === 'check_in' && delayReasonContainer && delayReasonInput) {
        try {
            // الحصول على موعد العمل الرسمي
            const apiPath = getAttendanceApiPath();
            const response = await fetch(apiPath + '?action=get_work_time', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8'
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.work_time) {
                    const now = new Date();
                    const today = now.toISOString().split('T')[0];
                    const officialStartTime = new Date(today + 'T' + data.work_time.start);
                    const checkInTime = new Date(now);
                    
                    // التحقق من التأخير (إذا كان وقت الحضور بعد موعد الحضور الرسمي)
                    if (checkInTime > officialStartTime) {
                        // هناك تأخير - تفعيل الحقل
                        delayReasonContainer.style.display = 'block';
                        delayReasonInput.disabled = false;
                        delayReasonInput.placeholder = 'يرجى كتابة سبب التأخير...';
                        delayReasonInput.style.opacity = '1';
                        delayReasonInput.style.cursor = 'text';
                    } else {
                        // لا يوجد تأخير - تعطيل الحقل
                        delayReasonContainer.style.display = 'block';
                        delayReasonInput.disabled = true;
                        delayReasonInput.value = '';
                        delayReasonInput.placeholder = 'لا يوجد تأخير - الحضور في الوقت المحدد';
                        delayReasonInput.style.opacity = '0.6';
                        delayReasonInput.style.cursor = 'not-allowed';
                    }
                } else {
                    // في حالة عدم توفر موعد العمل، إخفاء الحقل
                    delayReasonContainer.style.display = 'none';
                }
            } else {
                // في حالة خطأ، إخفاء الحقل
                delayReasonContainer.style.display = 'none';
            }
        } catch (error) {
            console.error('Error checking work time:', error);
            // في حالة خطأ، إخفاء الحقل
            delayReasonContainer.style.display = 'none';
        }
    } else {
        // عند تسجيل الانصراف، إخفاء حقل سبب التأخير
        if (delayReasonContainer) {
            delayReasonContainer.style.display = 'none';
        }
    }
}

// إعادة التقاط
function retakePhoto() {
    const isMobileDevice = isMobile();
    capturedPhoto = null;
    
    const capturedImageContainer = isMobileDevice ? document.getElementById('capturedImageContainerCard') : document.getElementById('capturedImageContainer');
    const cameraContainer = isMobileDevice ? document.getElementById('cameraContainerCard') : document.getElementById('cameraContainer');
    const retakeBtn = isMobileDevice ? document.getElementById('retakeBtnCard') : document.getElementById('retakeBtn');
    const submitBtn = isMobileDevice ? document.getElementById('submitBtnCard') : document.getElementById('submitBtn');
    const captureBtn = isMobileDevice ? document.getElementById('captureBtnCard') : document.getElementById('captureBtn');
    const delayReasonContainer = isMobileDevice ? document.getElementById('delayReasonContainerCard') : document.getElementById('delayReasonContainer');
    const delayReasonInput = isMobileDevice ? document.getElementById('delayReasonCard') : document.getElementById('delayReason');
    
    // إخفاء preview الصورة
    if (capturedImageContainer) {
        capturedImageContainer.style.setProperty('display', 'none', 'important');
        capturedImageContainer.style.setProperty('visibility', 'hidden', 'important');
    }
    
    // إظهار الكاميرا
    if (cameraContainer) {
        cameraContainer.style.setProperty('display', 'block', 'important');
        cameraContainer.style.setProperty('visibility', 'visible', 'important');
    }
    
    // إخفاء أزرار إعادة التقاط والتأكيد
    if (retakeBtn) retakeBtn.style.setProperty('display', 'none', 'important');
    if (submitBtn) submitBtn.style.setProperty('display', 'none', 'important');
    
    // إظهار زر التقاط
    if (captureBtn) {
        captureBtn.style.setProperty('display', 'inline-block', 'important');
        captureBtn.style.setProperty('visibility', 'visible', 'important');
    }
    
    // إخفاء حقل سبب التأخير
    if (delayReasonContainer) {
        delayReasonContainer.style.setProperty('display', 'none', 'important');
    }
    if (delayReasonInput) {
        delayReasonInput.value = '';
        delayReasonInput.disabled = true;
    }
    
    // إعادة تهيئة الكاميرا
    initCamera();
}

// قفل منع الإرسال المزدوج (ضغط متكرر أو طلبات متعددة)
let isSubmittingAttendance = false;

// إرسال تسجيل الحضور/الانصراف
async function submitAttendance(action) {
    if (isSubmittingAttendance) {
        console.warn('submitAttendance: already submitting, ignoring duplicate call');
        return;
    }
    if (!capturedPhoto) {
        alert('يجب التقاط صورة أولاً');
        return;
    }
    
    isSubmittingAttendance = true;
    const isMobileDevice = isMobile();
    const submitBtn = isMobileDevice ? document.getElementById('submitBtnCard') : document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الإرسال...';
    
    try {
        const apiPath = getAttendanceApiPath();
        
        // تسجيل معلومات الصورة في console للتأكد
        console.log('Submitting attendance:', {
            action: action,
            photoLength: capturedPhoto.length,
            photoPrefix: capturedPhoto.substring(0, 50),
            apiPath: apiPath
        });
        
        // الحصول على سبب التأخير (فقط عند تسجيل الحضور)
        // تحديد العناصر حسب نوع الجهاز
        const isMobileDevice = isMobile();
        const delayReasonInput = isMobileDevice ? document.getElementById('delayReasonCard') : document.getElementById('delayReason');
        const delayReason = (action === 'check_in' && delayReasonInput && !delayReasonInput.disabled) 
            ? delayReasonInput.value.trim() 
            : '';
        
        // إرسال الصورة كـ JSON (أفضل للبيانات الكبيرة)
        const payload = {
            action: action,
            photo: capturedPhoto,
            delay_reason: delayReason
        };
        
        console.log('Payload photo value:', payload.photo ? 'exists (length: ' + payload.photo.length + ')' : 'missing');
        
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            },
            body: JSON.stringify(payload)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            throw new Error('استجابة غير صحيحة من الخادم');
        }
        
        const data = await response.json();
        
        console.log('API Response:', data);
        
        if (data.success) {
            // إغلاق الـ modal أو card
            const isMobileDevice = isMobile();
            if (isMobileDevice) {
                const card = document.getElementById('cameraCard');
                if (card) {
                    card.style.display = 'none';
                }
            } else {
                const modal = bootstrap.Modal.getInstance(document.getElementById('cameraModal'));
                if (modal) {
                    modal.hide();
                }
            }
            
            // إظهار رسالة نجاح
            showAlert('success', data.message || 'تم التسجيل بنجاح');
            
            // تحديث حالة الأزرار بناءً على الإجراء
            updateButtonsState(action);
            
            // إعادة تحميل الصفحة مع cache-busting بعد ثانية ونصف
            isSubmittingAttendance = false;
            setTimeout(() => {
                // إضافة timestamp لضمان إعادة تحميل من السيرفر وليس من cache
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('_refresh', Date.now());
                window.location.href = currentUrl.toString();
            }, 1500);
        } else {
            showAlert('danger', data.message || 'فشل التسجيل');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تأكيد وإرسال';
            }
            isSubmittingAttendance = false;
        }
        
    } catch (error) {
        console.error('Error submitting attendance:', error);
        showAlert('danger', 'حدث خطأ أثناء الإرسال: ' + error.message);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تأكيد وإرسال';
        }
        isSubmittingAttendance = false;
    }
}

// تحديث حالة الأزرار بعد التسجيل
function updateButtonsState(action) {
    const checkInBtn = document.getElementById('checkInBtn');
    const checkOutBtn = document.getElementById('checkOutBtn');
    
    if (action === 'check_in') {
        // بعد تسجيل حضور، تعطيل زر الحضور وتفعيل زر الانصراف
        if (checkInBtn) {
            checkInBtn.disabled = true;
            checkInBtn.innerHTML = '<i class="bi bi-camera me-2"></i>تم تسجيل الحضور';
            // تحديث النص التوضيحي
            const checkInCard = checkInBtn.closest('.card-body');
            if (checkInCard) {
                const smallText = checkInCard.querySelector('small');
                if (smallText) {
                    smallText.className = 'text-warning d-block mt-2';
                    smallText.innerHTML = '<i class="bi bi-info-circle me-1"></i>يجب تسجيل الانصراف أولاً';
                }
            }
        }
        if (checkOutBtn) {
            checkOutBtn.disabled = false;
            checkOutBtn.innerHTML = '<i class="bi bi-camera me-2"></i>تسجيل الانصراف';
            // تحديث النص التوضيحي
            const checkOutCard = checkOutBtn.closest('.card-body');
            if (checkOutCard) {
                const smallText = checkOutCard.querySelector('small');
                if (smallText) {
                    smallText.className = 'text-muted d-block mt-2';
                    smallText.innerHTML = 'سيتم التقاط صورة تلقائياً';
                }
            }
        }
    } else if (action === 'check_out') {
        // بعد تسجيل انصراف، تفعيل زر الحضور وتعطيل زر الانصراف
        if (checkInBtn) {
            checkInBtn.disabled = false;
            checkInBtn.innerHTML = '<i class="bi bi-camera me-2"></i>تسجيل الحضور';
            // تحديث النص التوضيحي
            const checkInCard = checkInBtn.closest('.card-body');
            if (checkInCard) {
                const smallText = checkInCard.querySelector('small');
                if (smallText) {
                    smallText.className = 'text-muted d-block mt-2';
                    smallText.innerHTML = 'سيتم التقاط صورة تلقائياً';
                }
            }
        }
        if (checkOutBtn) {
            checkOutBtn.disabled = true;
            checkOutBtn.innerHTML = '<i class="bi bi-camera me-2"></i>لا يمكن تسجيل الانصراف';
            // تحديث النص التوضيحي
            const checkOutCard = checkOutBtn.closest('.card-body');
            if (checkOutCard) {
                const smallText = checkOutCard.querySelector('small');
                if (smallText) {
                    smallText.className = 'text-warning d-block mt-2';
                    smallText.innerHTML = '<i class="bi bi-info-circle me-1"></i>يجب تسجيل الحضور أولاً';
                }
            }
        }
    }
}

// إظهار تنبيه
function showAlert(type, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
    alertDiv.style.zIndex = '9999';
    alertDiv.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}-fill me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

// متغيرات لتخزين موعد العمل و interval للتحديث
let workTimeData = null;
let timeSummaryInterval = null;

// عرض ملخص الوقت
async function updateTimeSummary() {
    const isMobileDevice = isMobile();
    const timeSummaryContainer = isMobileDevice ? document.getElementById('timeSummaryContainerCard') : document.getElementById('timeSummaryContainer');
    const currentTimeDisplay = isMobileDevice ? document.getElementById('currentTimeDisplayCard') : document.getElementById('currentTimeDisplay');
    const officialTimeDisplay = isMobileDevice ? document.getElementById('officialTimeDisplayCard') : document.getElementById('officialTimeDisplay');
    const timeStatusDisplay = isMobileDevice ? document.getElementById('timeStatusDisplayCard') : document.getElementById('timeStatusDisplay');
    
    if (currentAction !== 'check_in') {
        if (timeSummaryContainer) {
            timeSummaryContainer.style.display = 'none';
        }
        // إيقاف interval إذا كان يعمل
        if (timeSummaryInterval) {
            clearInterval(timeSummaryInterval);
            timeSummaryInterval = null;
        }
        return;
    }
    
    try {
        // إذا لم نكن قد حصلنا على موعد العمل بعد، احصل عليه
        if (!workTimeData) {
            const apiPath = getAttendanceApiPath();
            const response = await fetch(apiPath + '?action=get_work_time', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8'
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.work_time) {
                    workTimeData = data.work_time;
                } else {
                    return;
                }
            } else {
                return;
            }
        }
        
        // تحديث العرض
        const now = new Date();
        const today = now.toISOString().split('T')[0];
        const officialStartTime = new Date(today + 'T' + workTimeData.start);
        const checkInTime = new Date(now);
        
        // تحديث عرض الوقت الحالي
        if (currentTimeDisplay) {
            const hours = String(checkInTime.getHours()).padStart(2, '0');
            const minutes = String(checkInTime.getMinutes()).padStart(2, '0');
            currentTimeDisplay.textContent = hours + ':' + minutes;
        }
        
        // تحديث عرض موعد العمل
        if (officialTimeDisplay) {
            const officialHours = String(officialStartTime.getHours()).padStart(2, '0');
            const officialMinutes = String(officialStartTime.getMinutes()).padStart(2, '0');
            officialTimeDisplay.textContent = officialHours + ':' + officialMinutes;
        }
        
        // تحديث عرض الحالة
        if (timeStatusDisplay) {
            const diffMs = checkInTime - officialStartTime;
            const diffMinutes = Math.round(diffMs / 60000);
            
            if (diffMinutes > 0) {
                // تأخير
                timeStatusDisplay.innerHTML = '<span class="badge bg-warning">متأخر ' + diffMinutes + ' دقيقة</span>';
                timeStatusDisplay.className = 'fw-bold text-warning';
            } else if (diffMinutes < 0) {
                // مبكر
                const earlyMinutes = Math.abs(diffMinutes);
                timeStatusDisplay.innerHTML = '<span class="badge bg-info">مبكر ' + earlyMinutes + ' دقيقة</span>';
                timeStatusDisplay.className = 'fw-bold text-info';
            } else {
                // في الوقت
                timeStatusDisplay.innerHTML = '<span class="badge bg-success">في الوقت</span>';
                timeStatusDisplay.className = 'fw-bold text-success';
            }
        }
        
        // إظهار ملخص الوقت
        if (timeSummaryContainer) {
            timeSummaryContainer.style.display = 'block';
        }
        
    } catch (error) {
        console.error('Error updating time summary:', error);
        if (timeSummaryContainer) {
            timeSummaryContainer.style.display = 'none';
        }
    }
}

// معالجة فتح الـ modal
// دالة للتحقق من وجود العناصر وإعادة المحاولة
function initAttendanceButtons() {
    const cameraModal = document.getElementById('cameraModal');
    const checkInBtn = document.getElementById('checkInBtn');
    const checkOutBtn = document.getElementById('checkOutBtn');
    const captureBtn = document.getElementById('captureBtn');
    const retakeBtn = document.getElementById('retakeBtn');
    const submitBtn = document.getElementById('submitBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    
    console.log('initAttendanceButtons called', {
        cameraModal: !!cameraModal,
        checkInBtn: !!checkInBtn,
        checkOutBtn: !!checkOutBtn
    });
    
    // التحقق من وجود العناصر الأساسية
    if (!checkInBtn && !checkOutBtn) {
        console.warn('Attendance buttons not found, retrying in 100ms...');
        setTimeout(initAttendanceButtons, 100);
        return;
    }
    
    // التحقق من وجود modal (اختياري - قد لا يكون موجوداً على الموبايل)
    if (!cameraModal) {
        console.warn('Camera modal not found (this is OK on mobile)');
    }
    
    // دالة لإزالة backdrop
    function removeBackdrop() {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => {
            backdrop.style.display = 'none';
            backdrop.style.opacity = '0';
            backdrop.style.pointerEvents = 'none';
            backdrop.style.zIndex = '-1';
            backdrop.remove();
        });
    }
    
    // عند فتح الـ modal (للكمبيوتر فقط)
    cameraModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        
        // إذا لم يكن button موجوداً (أي تم فتح الـ modal برمجياً)، استخدم currentAction
        if (button) {
            currentAction = button.getAttribute('data-action');
        }
        
        // إزالة backdrop فوراً
        removeBackdrop();
        
        // تحديث العنوان
        const title = document.getElementById('cameraModalTitle');
        if (title) {
            if (currentAction === 'check_in') {
                title.textContent = 'تسجيل الحضور - التقاط صورة';
            } else {
                title.textContent = 'تسجيل الانصراف - التقاط صورة';
            }
        }
        
        // إعادة تعيين الحالة (للكمبيوتر فقط)
        resetCameraState(false);
        
        // إزالة backdrop فقط - لا نستدعي initCamera هنا
        // سنستدعيها في shown.bs.modal بعد أن يكون Modal مرئياً تماماً
        removeBackdrop();
        
        // مراقبة مستمرة لإزالة backdrop
        const backdropInterval = setInterval(() => {
            removeBackdrop();
        }, 1000);
        
        // حفظ interval ID لإيقافه لاحقاً
        cameraModal.dataset.backdropInterval = backdropInterval;
    });
    
    // عند اكتمال فتح الـ modal (بعد أن يكون مرئياً تماماً) - مهم جداً للموبايل
    cameraModal.addEventListener('shown.bs.modal', function(event) {
        // كشف ما إذا كان الجهاز موبايل
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        
        // إزالة backdrop
        removeBackdrop();
        
        // على الموبايل، ننتظر وقت أطول لضمان أن Modal مرئي تماماً
        const delay = isMobile ? 300 : 150;
        
        // استخدام requestAnimationFrame لضمان أن Modal مرئي تماماً
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                setTimeout(async () => {
                    // التأكد مرة أخرى من إزالة backdrop
                    removeBackdrop();
                    
                    // التأكد من أن Modal مرئي
                    const modalElement = document.getElementById('cameraModal');
                    if (!modalElement || !modalElement.classList.contains('show')) {
                        console.warn('Modal not visible, retrying in 200ms...');
                        setTimeout(async () => {
                            try {
                                await initCamera();
                            } catch (error) {
                                console.error('Error initializing camera in modal (retry):', error);
                            }
                        }, 200);
                        return;
                    }
                    
                    // التأكد من أن container مرئي
                    const cameraContainer = document.getElementById('cameraContainer');
                    if (cameraContainer) {
                        cameraContainer.style.display = 'block';
                        cameraContainer.style.visibility = 'visible';
                        cameraContainer.style.opacity = '1';
                        cameraContainer.offsetHeight; // إجبار reflow
                    }
                    
                    // التأكد من أن video element جاهز
                    const video = document.getElementById('video');
                    if (video) {
                        video.style.position = 'relative';
                        video.style.zIndex = '2';
                        video.offsetHeight; // إجبار reflow
                    }
                    
                    try {
                        console.log('Initializing camera in modal...');
                        await initCamera();
                        console.log('Camera initialized successfully in modal');
                    } catch (error) {
                        console.error('Error initializing camera in modal:', error);
                        // محاولة إظهار زر التقاط حتى لو فشلت الكاميرا
                        const captureBtn = document.getElementById('captureBtn');
                        if (captureBtn) {
                            captureBtn.style.setProperty('display', 'inline-block', 'important');
                            captureBtn.style.setProperty('visibility', 'visible', 'important');
                        }
                    }
                }, delay);
            });
        });
    });
    
    // عند إغلاق الـ modal
    cameraModal.addEventListener('hidden.bs.modal', function() {
        stopCamera();
        capturedPhoto = null;
        currentAction = null;
        workTimeData = null; // إعادة تعيين
        
        // إيقاف interval تحديث الوقت
        if (timeSummaryInterval) {
            clearInterval(timeSummaryInterval);
            timeSummaryInterval = null;
        }
        
        // إيقاف مراقبة backdrop
        if (cameraModal.dataset.backdropInterval) {
            clearInterval(parseInt(cameraModal.dataset.backdropInterval));
            delete cameraModal.dataset.backdropInterval;
        }
        
        // إزالة backdrop نهائياً
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
    });
    
    // دالة لفتح الكاميرا (Modal للكمبيوتر أو Card للموبايل)
    // جعلها متاحة عالمياً
    window.openCamera = function(action) {
        currentAction = action;
        const isMobileDevice = isMobile();
        
        console.log('openCamera called:', { action, isMobileDevice });
        
        if (isMobileDevice) {
            // على الموبايل: استخدام Card
            const card = document.getElementById('cameraCard');
            const cardTitle = document.getElementById('cameraCardTitle');
            
            console.log('Opening card:', { card: !!card, cardTitle: !!cardTitle });
            
            if (!card) {
                console.error('cameraCard element not found in DOM!');
                alert('حدث خطأ: لم يتم العثور على بطاقة الكاميرا. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
                return;
            }
            
            if (!cardTitle) {
                console.error('cameraCardTitle element not found in DOM!');
                alert('حدث خطأ: لم يتم العثور على عنوان البطاقة. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
                return;
            }
            
            // تحديث العنوان
            if (action === 'check_in') {
                cardTitle.textContent = 'تسجيل الحضور - التقاط صورة';
            } else {
                cardTitle.textContent = 'تسجيل الانصراف - التقاط صورة';
            }
            
            // إعادة تعيين الحالة
            resetCameraState(true);
            
            // إزالة أي classes قد تخفي البطاقة
            card.classList.remove('d-none');
            card.classList.add('d-md-none'); // إظهار على الموبايل فقط
            
            // إظهار Card - استخدام setProperty مع !important
            card.style.setProperty('display', 'block', 'important');
            card.style.setProperty('visibility', 'visible', 'important');
            card.style.setProperty('opacity', '1', 'important');
            card.style.setProperty('position', 'relative', 'important');
            card.style.setProperty('z-index', '1000', 'important');
            
            // إجبار reflow لضمان أن التغييرات مطبقة
            card.offsetHeight;
            
            console.log('Card display properties:', {
                display: card.style.display,
                visibility: card.style.visibility,
                opacity: card.style.opacity,
                computedDisplay: window.getComputedStyle(card).display,
                computedVisibility: window.getComputedStyle(card).visibility
            });
            
            // التمرير التلقائي
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
            
            // تهيئة الكاميرا بعد التأكد من أن Card مرئي
            // استخدام requestAnimationFrame لضمان أن البطاقة مرئية تماماً
            requestAnimationFrame(() => {
                requestAnimationFrame(async () => {
                    // التحقق من أن البطاقة مرئية
                    const cardRect = card.getBoundingClientRect();
                    const isVisible = cardRect.width > 0 && cardRect.height > 0 && 
                                     window.getComputedStyle(card).display !== 'none';
                    
                    console.log('Initializing camera, card visible:', isVisible, {
                        width: cardRect.width,
                        height: cardRect.height,
                        display: window.getComputedStyle(card).display
                    });
                    
                    if (!isVisible) {
                        console.warn('Card not visible yet, retrying in 100ms');
                        setTimeout(async () => {
                            try {
                                await initCamera();
                            } catch (error) {
                                console.error('Error initializing camera in card:', error);
                                showCameraError(error);
                            }
                        }, 100);
                        return;
                    }
                    
                    try {
                        console.log('Calling initCamera...');
                        await initCamera();
                        console.log('initCamera completed successfully');
                    } catch (error) {
                        console.error('Error initializing camera in card:', error);
                        showCameraError(error);
                    }
                });
            });
        } else {
            // على الكمبيوتر: استخدام Modal
            console.log('Opening modal');
            const modal = new bootstrap.Modal(cameraModal);
            modal.show();
        }
    };
    
    // دالة لإعادة تعيين حالة الكاميرا
    function resetCameraState(isMobileDevice) {
        capturedPhoto = null;
        
        if (isMobileDevice) {
            const cameraContainer = document.getElementById('cameraContainerCard');
            const capturedImageContainer = document.getElementById('capturedImageContainerCard');
            const delayReasonContainer = document.getElementById('delayReasonContainerCard');
            const delayReasonInput = document.getElementById('delayReasonCard');
            
            if (cameraContainer) {
                cameraContainer.style.display = 'block';
                cameraContainer.style.visibility = 'visible';
            }
            if (capturedImageContainer) {
                capturedImageContainer.style.display = 'none';
                capturedImageContainer.style.visibility = 'hidden';
            }
            if (delayReasonContainer) delayReasonContainer.style.display = 'none';
            if (delayReasonInput) {
                delayReasonInput.value = '';
                delayReasonInput.disabled = true;
            }
            
            const captureBtn = document.getElementById('captureBtnCard');
            const retakeBtn = document.getElementById('retakeBtnCard');
            const submitBtn = document.getElementById('submitBtnCard');
            
            if (captureBtn) {
                captureBtn.style.display = 'none';
                captureBtn.style.visibility = 'hidden';
                captureBtn.disabled = false;
            }
            if (retakeBtn) retakeBtn.style.display = 'none';
            if (submitBtn) submitBtn.style.display = 'none';
        } else {
            const cameraContainer = document.getElementById('cameraContainer');
            const capturedImageContainer = document.getElementById('capturedImageContainer');
            const delayReasonContainer = document.getElementById('delayReasonContainer');
            const delayReasonInput = document.getElementById('delayReason');
            
            if (cameraContainer) {
                cameraContainer.style.display = 'block';
                cameraContainer.style.visibility = 'visible';
            }
            if (capturedImageContainer) {
                capturedImageContainer.style.display = 'none';
                capturedImageContainer.style.visibility = 'hidden';
            }
            if (delayReasonContainer) delayReasonContainer.style.display = 'none';
            if (delayReasonInput) {
                delayReasonInput.value = '';
                delayReasonInput.disabled = true;
            }
            
            const captureBtn = document.getElementById('captureBtn');
            const retakeBtn = document.getElementById('retakeBtn');
            const submitBtn = document.getElementById('submitBtn');
            
            if (captureBtn) {
                captureBtn.style.display = 'none';
                captureBtn.style.visibility = 'hidden';
                captureBtn.disabled = false;
            }
            if (retakeBtn) retakeBtn.style.display = 'none';
            if (submitBtn) submitBtn.style.display = 'none';
        }
        
        // إيقاف أي stream سابق
        stopCamera();
        
        // تحديث ملخص الوقت (للتسجيل الحضور فقط)
        workTimeData = null; // إعادة تعيين
        if (currentAction === 'check_in') {
            updateTimeSummary();
            // تحديث الوقت كل ثانية
            if (timeSummaryInterval) {
                clearInterval(timeSummaryInterval);
            }
            timeSummaryInterval = setInterval(updateTimeSummary, 1000);
        } else {
            const timeSummaryContainer = isMobileDevice ? document.getElementById('timeSummaryContainerCard') : document.getElementById('timeSummaryContainer');
            if (timeSummaryContainer) {
                timeSummaryContainer.style.display = 'none';
            }
            if (timeSummaryInterval) {
                clearInterval(timeSummaryInterval);
                timeSummaryInterval = null;
            }
        }
    }
    
    // إضافة event listeners للأزرار مع التحقق من حالة التعطيل
    if (checkInBtn) {
        // التحقق من وجود event listener مسبقاً
        if (checkInBtn.dataset.listenerAttached === 'true') {
            console.log('checkInBtn listeners already attached, skipping');
        } else {
            // إضافة event listeners متعددة لضمان العمل على جميع الأجهزة
            checkInBtn.addEventListener('click', function(e) {
                console.log('checkInBtn clicked', { 
                    disabled: checkInBtn.disabled,
                    isMobile: isMobile()
                });
                e.preventDefault();
                e.stopPropagation();
                
                if (checkInBtn.disabled) {
                    console.warn('checkInBtn is disabled');
                    return false;
                }
                
                // تعيين الإجراء وفتح modal/card
                try {
                    openCamera('check_in');
                } catch (error) {
                    console.error('Error opening camera for check_in:', error);
                    alert('حدث خطأ في فتح الكاميرا. يرجى المحاولة مرة أخرى.');
                }
                return false;
            }, true); // استخدام capture phase
            
            // إضافة touchstart للموبايل أيضاً
            checkInBtn.addEventListener('touchstart', function(e) {
                console.log('checkInBtn touchstart', { 
                    disabled: checkInBtn.disabled,
                    isMobile: isMobile()
                });
                e.preventDefault();
                e.stopPropagation();
                
                if (checkInBtn.disabled) {
                    console.warn('checkInBtn is disabled');
                    return false;
                }
                
                // تعيين الإجراء وفتح modal/card
                try {
                    openCamera('check_in');
                } catch (error) {
                    console.error('Error opening camera for check_in:', error);
                    alert('حدث خطأ في فتح الكاميرا. يرجى المحاولة مرة أخرى.');
                }
                return false;
            }, { passive: false, capture: true });
            
            // إضافة mousedown أيضاً كحل بديل
            checkInBtn.addEventListener('mousedown', function(e) {
                if (isMobile()) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!checkInBtn.disabled) {
                        openCamera('check_in');
                    }
                    return false;
                }
            }, true);
            
            // وضع علامة أن event listeners تم إضافتها
            checkInBtn.dataset.listenerAttached = 'true';
            console.log('checkInBtn event listeners added');
        }
    } else {
        console.error('checkInBtn not found!');
    }
    
    if (checkOutBtn) {
        // التحقق من وجود event listener مسبقاً
        if (checkOutBtn.dataset.listenerAttached === 'true') {
            console.log('checkOutBtn listeners already attached, skipping');
        } else {
            // إضافة event listeners متعددة لضمان العمل على جميع الأجهزة
            checkOutBtn.addEventListener('click', function(e) {
                console.log('checkOutBtn clicked', { 
                    disabled: checkOutBtn.disabled,
                    isMobile: isMobile()
                });
                e.preventDefault();
                e.stopPropagation();
                
                if (checkOutBtn.disabled) {
                    console.warn('checkOutBtn is disabled');
                    return false;
                }
                
                // تعيين الإجراء وفتح modal/card
                try {
                    openCamera('check_out');
                } catch (error) {
                    console.error('Error opening camera for check_out:', error);
                    alert('حدث خطأ في فتح الكاميرا. يرجى المحاولة مرة أخرى.');
                }
                return false;
            }, true); // استخدام capture phase
            
            // إضافة touchstart للموبايل أيضاً
            checkOutBtn.addEventListener('touchstart', function(e) {
                console.log('checkOutBtn touchstart', { 
                    disabled: checkOutBtn.disabled,
                    isMobile: isMobile()
                });
                e.preventDefault();
                e.stopPropagation();
                
                if (checkOutBtn.disabled) {
                    console.warn('checkOutBtn is disabled');
                    return false;
                }
                
                // تعيين الإجراء وفتح modal/card
                try {
                    openCamera('check_out');
                } catch (error) {
                    console.error('Error opening camera for check_out:', error);
                    alert('حدث خطأ في فتح الكاميرا. يرجى المحاولة مرة أخرى.');
                }
                return false;
            }, { passive: false, capture: true });
            
            // إضافة mousedown أيضاً كحل بديل
            checkOutBtn.addEventListener('mousedown', function(e) {
                if (isMobile()) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!checkOutBtn.disabled) {
                        openCamera('check_out');
                    }
                    return false;
                }
            }, true);
            
            // وضع علامة أن event listeners تم إضافتها
            checkOutBtn.dataset.listenerAttached = 'true';
            console.log('checkOutBtn event listeners added');
        }
    } else {
        console.error('checkOutBtn not found!');
    }
    
    // أحداث الأزرار (Modal و Card) - نضيفها مرة واحدة فقط لمنع تسجيل الحضور المتكرر
    const modalButtonsAttr = 'data-attendance-modal-buttons-attached';
    if (!document.body.hasAttribute(modalButtonsAttr)) {
        document.body.setAttribute(modalButtonsAttr, 'true');
        if (captureBtn) {
            captureBtn.addEventListener('click', capturePhoto);
        }
        if (retakeBtn) {
            retakeBtn.addEventListener('click', retakePhoto);
        }
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                if (currentAction) {
                    submitAttendance(currentAction);
                }
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                stopCamera();
            });
        }
        const captureBtnCard = document.getElementById('captureBtnCard');
        const retakeBtnCard = document.getElementById('retakeBtnCard');
        const submitBtnCard = document.getElementById('submitBtnCard');
        const cancelBtnCard = document.getElementById('cancelBtnCard');
        if (captureBtnCard) {
            captureBtnCard.addEventListener('click', capturePhoto);
        }
        if (retakeBtnCard) {
            retakeBtnCard.addEventListener('click', retakePhoto);
        }
        if (submitBtnCard) {
            submitBtnCard.addEventListener('click', function() {
                if (currentAction) {
                    submitAttendance(currentAction);
                }
            });
        }
        if (cancelBtnCard) {
            cancelBtnCard.addEventListener('click', function() {
                stopCamera();
                const card = document.getElementById('cameraCard');
                if (card) {
                    card.style.display = 'none';
                }
            });
        }
    }
    
    // إرجاع العناصر المحدثة
    return {
        checkInBtn: checkInBtn ? document.getElementById('checkInBtn') : null,
        checkOutBtn: checkOutBtn ? document.getElementById('checkOutBtn') : null
    };
}

// تهيئة عند تحميل DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAttendanceButtons);
} else {
    // DOM محمل بالفعل
    initAttendanceButtons();
}

// إعادة المحاولة بعد تحميل الصفحة بالكامل
window.addEventListener('load', function() {
    setTimeout(initAttendanceButtons, 100);
});

// إعادة المحاولة كل 500ms حتى يتم ربط المستمعات (نستخدم listenerAttached لعدم إعادة الربط أكثر من مرة)
let retryCount = 0;
const maxRetries = 6;
const retryInterval = setInterval(function() {
    const checkInBtn = document.getElementById('checkInBtn');
    const checkOutBtn = document.getElementById('checkOutBtn');
    const alreadyAttached = (checkInBtn && checkInBtn.dataset.listenerAttached === 'true') ||
        (checkOutBtn && checkOutBtn.dataset.listenerAttached === 'true');
    if (alreadyAttached || retryCount >= maxRetries) {
        clearInterval(retryInterval);
        if (retryCount >= maxRetries && !alreadyAttached) {
            console.warn('Max retries reached for attendance buttons');
        }
        return;
    }
    if (checkInBtn || checkOutBtn) {
        console.log('Retrying to attach event listeners, attempt:', retryCount + 1);
        initAttendanceButtons();
        retryCount++;
    }
}, 500);

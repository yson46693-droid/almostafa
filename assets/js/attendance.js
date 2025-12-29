/**
 * نظام تسجيل الحضور والانصراف مع الكاميرا
 */

// ========== إعدادات التطوير/الإنتاج ==========
const DEBUG = window.location.hostname === 'localhost' || 
              window.location.hostname === '127.0.0.1' || 
              window.location.hostname.includes('localhost:');

// دالة console.log آمنة (لا تطبع في الإنتاج)
if (typeof window.safeLog === 'undefined') {
    window.safeLog = function(...args) {
        if (DEBUG) {
            console.log(...args);
        }
    };
}

let currentStream = null;
let capturedPhoto = null;
let currentAction = null;

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
    const video = document.getElementById('video');
    const cameraLoading = document.getElementById('cameraLoading');
    const cameraError = document.getElementById('cameraError');
    
    try {
        if (!video) {
            console.error('Video element not found');
            showCameraError('عنصر الفيديو غير موجود');
            return;
        }
        
        // إظهار حالة التحميل
        if (cameraLoading) {
            cameraLoading.style.display = 'block';
            cameraLoading.style.visibility = 'visible';
        }
        if (cameraError) {
            cameraError.style.display = 'none';
            cameraError.style.visibility = 'hidden';
        }
        video.style.display = 'none';
        video.style.visibility = 'hidden';
        
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
        
        // التحقق من الصلاحيات قبل محاولة الوصول للكاميرا (إذا كان متاحاً)
        try {
            if (navigator.permissions && navigator.permissions.query) {
                const permissionStatus = await navigator.permissions.query({ name: 'camera' });
                if (permissionStatus.state === 'denied') {
                    throw new Error('تم رفض الوصول إلى الكاميرا. يرجى السماح بالوصول في إعدادات المتصفح.');
                }
            }
        } catch (permError) {
            // تجاهل أخطاء التحقق من الصلاحيات - سنحاول الوصول للكاميرا مباشرة
            safeLog('Permission check failed, continuing anyway:', permError);
        }
        
        // إيقاف أي stream سابق
        if (currentStream) {
            stopCamera();
        }
        
        // إعادة تعيين srcObject
        video.srcObject = null;
        
        // كشف ما إذا كان الجهاز موبايل
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        
        // محاولة الوصول للكاميرا مع خيارات مختلفة
        // على الموبايل، استخدم constraints أبسط لتحسين التوافق
        let constraints = isMobile ? {
            video: {
                facingMode: { ideal: 'user' }
            }
        } : {
            video: {
                width: { ideal: 1280, min: 640 },
                height: { ideal: 720, min: 480 },
                aspectRatio: { ideal: 16/9 },
                facingMode: { ideal: 'user' }
            }
        };
        
        // محاولة الوصول للكاميرا
        let stream = null;
        let getUserMediaError = null;
        
        try {
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                stream = await navigator.mediaDevices.getUserMedia(constraints);
            } else {
                // استخدام API القديم
                stream = await new Promise((resolve, reject) => {
                    const getUserMedia = navigator.getUserMedia || 
                                        navigator.webkitGetUserMedia || 
                                        navigator.mozGetUserMedia;
                    if (!getUserMedia) {
                        reject(new Error('الكاميرا غير مدعومة في هذا المتصفح'));
                        return;
                    }
                    getUserMedia.call(navigator, constraints, resolve, reject);
                });
            }
        } catch (firstError) {
            getUserMediaError = firstError;
            // إذا فشلت المحاولة الأولى، جرب بدون تحديد facingMode
            try {
                delete constraints.video.facingMode;
                if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    stream = await navigator.mediaDevices.getUserMedia(constraints);
                } else {
                    const getUserMedia = navigator.getUserMedia || 
                                        navigator.webkitGetUserMedia || 
                                        navigator.mozGetUserMedia;
                    if (getUserMedia) {
                        stream = await new Promise((resolve, reject) => {
                            getUserMedia.call(navigator, constraints, resolve, reject);
                        });
                    } else {
                        throw firstError;
                    }
                }
            } catch (secondError) {
                // إذا فشلت المحاولة الثانية، جرب مع constraints أبسط
                try {
                    constraints = { video: true };
                    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                        stream = await navigator.mediaDevices.getUserMedia(constraints);
                    } else {
                        const getUserMedia = navigator.getUserMedia || 
                                            navigator.webkitGetUserMedia || 
                                            navigator.mozGetUserMedia;
                        if (getUserMedia) {
                            stream = await new Promise((resolve, reject) => {
                                getUserMedia.call(navigator, constraints, resolve, reject);
                            });
                        } else {
                            throw firstError;
                        }
                    }
                } catch (thirdError) {
                    throw firstError;
                }
            }
        }
        
        if (!stream) {
            throw new Error('فشل في الوصول إلى الكاميرا');
        }
        
        currentStream = stream;
        video.srcObject = currentStream;
        
        // إظهار الفيديو فوراً بعد تعيين srcObject
        video.style.display = 'block';
        video.style.visibility = 'visible';
        video.style.opacity = '1';
        
        // انتظر حتى يكون الفيديو جاهزاً مع timeout
        await new Promise((resolve, reject) => {
            let timeoutId = null;
            let resolved = false;
            
            const cleanup = () => {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                    timeoutId = null;
                }
            };
            
            const resolveOnce = () => {
                if (!resolved) {
                    resolved = true;
                    cleanup();
                    resolve();
                }
            };
            
            const rejectOnce = (error) => {
                if (!resolved) {
                    resolved = true;
                    cleanup();
                    reject(error);
                }
            };
            
            timeoutId = setTimeout(() => {
                if (video.readyState < 2) {
                    rejectOnce(new Error('انتهت مهلة تحميل الكاميرا. يرجى إعادة المحاولة.'));
                } else {
                    // التأكد من أن الفيديو مرئي
                    video.style.display = 'block';
                    video.style.visibility = 'visible';
                    video.style.opacity = '1';
                    resolveOnce();
                }
            }, 15000);
            
            video.onloadedmetadata = () => {
                cleanup();
                // التأكد من أن الفيديو مرئي قبل التشغيل
                video.style.display = 'block';
                video.style.visibility = 'visible';
                video.style.opacity = '1';
                // إضافة event listener للتحقق من أن الفيديو يعمل
                video.addEventListener('playing', () => {
                    safeLog('Video is now playing');
                    // التأكد من إظهار زر التقاط الصورة بعد بدء تشغيل الفيديو
                    const captureBtn = document.getElementById('captureBtn');
                    if (captureBtn) {
                        captureBtn.style.display = 'inline-block';
                        captureBtn.style.visibility = 'visible';
                        captureBtn.style.opacity = '1';
                    }
                }, { once: true });
                video.play().then(() => {
                    resolveOnce();
                }).catch((playError) => {
                    rejectOnce(new Error('فشل في تشغيل الفيديو: ' + playError.message));
                });
            };
            
            video.onerror = (e) => {
                rejectOnce(new Error('خطأ في تشغيل الفيديو'));
            };
            
            // إذا كان الفيديو جاهزاً بالفعل
            if (video.readyState >= 2) {
                // التأكد من أن الفيديو مرئي قبل التشغيل
                video.style.display = 'block';
                video.style.visibility = 'visible';
                video.style.opacity = '1';
                // إضافة event listener للتحقق من أن الفيديو يعمل
                video.addEventListener('playing', () => {
                    safeLog('Video is now playing (readyState >= 2)');
                    // التأكد من إظهار زر التقاط الصورة بعد بدء تشغيل الفيديو
                    const captureBtn = document.getElementById('captureBtn');
                    if (captureBtn) {
                        captureBtn.style.display = 'inline-block';
                        captureBtn.style.visibility = 'visible';
                        captureBtn.style.opacity = '1';
                    }
                }, { once: true });
                video.play().then(() => {
                    resolveOnce();
                }).catch((playError) => {
                    rejectOnce(new Error('فشل في تشغيل الفيديو: ' + playError.message));
                });
            }
        });
        
        // إخفاء حالة التحميل
        if (cameraLoading) cameraLoading.style.display = 'none';
        if (cameraError) cameraError.style.display = 'none';
        
        // التأكد من أن الفيديو مرئي
        if (video) {
            video.style.display = 'block';
            video.style.visibility = 'visible';
            video.style.opacity = '1';
        }
        
        // إظهار زر التقاط الصورة
        const captureBtn = document.getElementById('captureBtn');
        if (captureBtn) {
            captureBtn.style.display = 'inline-block';
            captureBtn.style.visibility = 'visible';
            captureBtn.style.opacity = '1';
            // التأكد من أن الزر قابل للنقر
            captureBtn.disabled = false;
        }
        
        // التأكد من أن الفيديو يعمل بشكل صحيح
        // إضافة event listener للتحقق من أن الفيديو يعمل
        const checkVideoPlaying = () => {
            if (video && video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0) {
                safeLog('Video is playing successfully', {
                    width: video.videoWidth,
                    height: video.videoHeight,
                    readyState: video.readyState
                });
            } else {
                safeLog('Video not ready yet', {
                    readyState: video?.readyState,
                    width: video?.videoWidth,
                    height: video?.videoHeight
                });
            }
        };
        
        // التحقق بعد ثانية من تحميل الكاميرا
        setTimeout(checkVideoPlaying, 1000);
        
        safeLog('Camera initialized successfully');
        
    } catch (error) {
        console.error('Error accessing camera:', error);
        // إخفاء حالة التحميل عند حدوث خطأ
        if (cameraLoading) {
            cameraLoading.style.display = 'none';
            cameraLoading.style.visibility = 'hidden';
        }
        // التأكد من إخفاء الفيديو عند حدوث خطأ
        if (video) {
            video.style.display = 'none';
            video.style.visibility = 'hidden';
        }
        // إخفاء زر التقاط الصورة عند حدوث خطأ
        const captureBtn = document.getElementById('captureBtn');
        if (captureBtn) {
            captureBtn.style.display = 'none';
            captureBtn.style.visibility = 'hidden';
        }
        showCameraError(error);
    }
}

// إظهار رسالة خطأ الكاميرا
function showCameraError(error) {
    const cameraError = document.getElementById('cameraError');
    const cameraErrorText = document.getElementById('cameraErrorText');
    const captureBtn = document.getElementById('captureBtn');
    const cameraLoading = document.getElementById('cameraLoading');
    const video = document.getElementById('video');
    
    // إخفاء حالة التحميل
    if (cameraLoading) cameraLoading.style.display = 'none';
    
    // إظهار عنصر الفيديو حتى لو كان هناك خطأ (لإظهار الصندوق الأسود بدلاً من عدم وجود شيء)
    if (video) {
        video.style.display = 'block';
        video.style.visibility = 'visible';
    }
    
    if (cameraError) {
        cameraError.style.display = 'block';
        cameraError.style.visibility = 'visible';
    }
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
    if (captureBtn) captureBtn.style.display = 'none';
}

// إعادة محاولة تحميل الكاميرا
async function retryCamera() {
    const cameraError = document.getElementById('cameraError');
    const cameraLoading = document.getElementById('cameraLoading');
    const video = document.getElementById('video');
    
    // إخفاء رسالة الخطأ
    if (cameraError) cameraError.style.display = 'none';
    
    // إعادة تعيين الفيديو
    if (video) {
        video.srcObject = null;
        video.style.display = 'none';
        video.style.visibility = 'hidden';
    }
    
    // إيقاف أي stream سابق
    stopCamera();
    
    // إعادة محاولة تحميل الكاميرا
    try {
        await initCamera();
    } catch (error) {
        console.error('Error retrying camera:', error);
        // showCameraError سيتم استدعاؤها من داخل initCamera
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
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const capturedImage = document.getElementById('capturedImage');
    const cameraContainer = document.getElementById('cameraContainer');
    const capturedImageContainer = document.getElementById('capturedImageContainer');
    const delayReasonContainer = document.getElementById('delayReasonContainer');
    const delayReasonInput = document.getElementById('delayReason');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    
    // تحويل إلى base64
    capturedPhoto = canvas.toDataURL('image/jpeg', 0.8);
    capturedImage.src = capturedPhoto;
    
    // إيقاف الكاميرا
    stopCamera();
    
    // إخفاء الكاميرا وإظهار الصورة
    cameraContainer.style.display = 'none';
    capturedImageContainer.style.display = 'block';
    
    // إظهار أزرار إعادة التقاط والتأكيد
    document.getElementById('captureBtn').style.display = 'none';
    document.getElementById('retakeBtn').style.display = 'inline-block';
    document.getElementById('submitBtn').style.display = 'inline-block';
    
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
async function retakePhoto() {
    capturedPhoto = null;
    document.getElementById('capturedImageContainer').style.display = 'none';
    document.getElementById('cameraContainer').style.display = 'block';
    document.getElementById('retakeBtn').style.display = 'none';
    document.getElementById('submitBtn').style.display = 'none';
    document.getElementById('captureBtn').style.display = 'inline-block';
    
    // إخفاء حقل سبب التأخير
    const delayReasonContainer = document.getElementById('delayReasonContainer');
    if (delayReasonContainer) {
        delayReasonContainer.style.display = 'none';
    }
    const delayReasonInput = document.getElementById('delayReason');
    if (delayReasonInput) {
        delayReasonInput.value = '';
    }
    
    try {
        await initCamera();
    } catch (error) {
        console.error('Error reinitializing camera:', error);
        // showCameraError سيتم استدعاؤها من داخل initCamera
    }
}

// إرسال تسجيل الحضور/الانصراف
async function submitAttendance(action) {
    if (!capturedPhoto) {
        alert('يجب التقاط صورة أولاً');
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الإرسال...';
    
    try {
        const apiPath = getAttendanceApiPath();
        
        // تسجيل معلومات الصورة في console للتأكد
        safeLog('Submitting attendance:', {
            action: action,
            photoLength: capturedPhoto.length,
            photoPrefix: capturedPhoto.substring(0, 50),
            apiPath: apiPath
        });
        
        // الحصول على سبب التأخير (فقط عند تسجيل الحضور)
        const delayReasonInput = document.getElementById('delayReason');
        const delayReason = (action === 'check_in' && delayReasonInput && !delayReasonInput.disabled) 
            ? delayReasonInput.value.trim() 
            : '';
        
        // إرسال الصورة كـ JSON (أفضل للبيانات الكبيرة)
        const payload = {
            action: action,
            photo: capturedPhoto,
            delay_reason: delayReason
        };
        
        safeLog('Payload photo value:', payload.photo ? 'exists (length: ' + payload.photo.length + ')' : 'missing');
        
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
        
        safeLog('API Response:', data);
        
        if (data.success) {
            // إغلاق الـ modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('cameraModal'));
            if (modal) {
                modal.hide();
            }
            
            // إظهار رسالة نجاح
            showAlert('success', data.message || 'تم التسجيل بنجاح');
            
            // تحديث حالة الأزرار بناءً على الإجراء
            updateButtonsState(action);
            
            // إعادة تحميل الصفحة مع cache-busting بعد ثانية ونصف
            setTimeout(() => {
                // إضافة timestamp لضمان إعادة تحميل من السيرفر وليس من cache
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('_refresh', Date.now());
                window.location.href = currentUrl.toString();
            }, 1500);
        } else {
            showAlert('danger', data.message || 'فشل التسجيل');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تأكيد وإرسال';
        }
        
    } catch (error) {
        console.error('Error submitting attendance:', error);
        showAlert('danger', 'حدث خطأ أثناء الإرسال: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تأكيد وإرسال';
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
    // دالة مساعدة لتنظيف HTML (إذا لم تكن موجودة)
    if (typeof escapeHTML === 'undefined') {
        window.escapeHTML = function(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        };
    }
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
    alertDiv.style.zIndex = '9999';
    alertDiv.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}-fill me-2"></i>
        ${escapeHTML(message)}
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
let visibilityHandlerAdded = false;

// دالة لتحميل تفاصيل حضور جميع الموظفين
async function loadAllEmployeesAttendance() {
    const container = document.getElementById('allEmployeesAttendanceContainer');
    const loading = document.getElementById('allEmployeesAttendanceLoading');
    const list = document.getElementById('allEmployeesAttendanceList');
    const error = document.getElementById('allEmployeesAttendanceError');
    const tableBody = document.getElementById('allEmployeesAttendanceTableBody');
    
    if (!container || !loading || !list || !error || !tableBody) {
        return;
    }
    
    // إظهار الحاوية وإخفاء القائمة وإظهار التحميل
    container.style.display = 'block';
    list.style.display = 'none';
    error.style.display = 'none';
    loading.style.display = 'block';
    
    try {
        const apiPath = getAttendanceApiPath();
        const today = new Date().toISOString().split('T')[0];
        const response = await fetch(apiPath + '?action=get_all_employees_today&date=' + today, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            }
        });
        
        if (!response.ok) {
            throw new Error('فشل تحميل البيانات');
        }
        
        const data = await response.json();
        
        if (data.success && data.employees && Array.isArray(data.employees)) {
            // مسح الجدول
            tableBody.innerHTML = '';
            
            if (data.employees.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted" style="font-size: 0.75rem;">لا يوجد موظفين</td></tr>';
            } else {
                data.employees.forEach(employee => {
                    const row = document.createElement('tr');
                    
                    // الاسم
                    const nameCell = document.createElement('td');
                    nameCell.style.fontSize = '0.75rem';
                    nameCell.style.padding = '0.25rem';
                    nameCell.textContent = employee.full_name || employee.username || '-';
                    row.appendChild(nameCell);
                    
                    // الموعد الرسمي
                    const officialTimeCell = document.createElement('td');
                    officialTimeCell.style.fontSize = '0.75rem';
                    officialTimeCell.style.padding = '0.25rem';
                    if (employee.official_start_time) {
                        const timeParts = employee.official_start_time.split(':');
                        const hours = parseInt(timeParts[0]);
                        const minutes = timeParts[1];
                        officialTimeCell.textContent = String(hours).padStart(2, '0') + ':' + minutes;
                    } else {
                        officialTimeCell.textContent = '-';
                    }
                    row.appendChild(officialTimeCell);
                    
                    // وقت الحضور الفعلي
                    const checkInTimeCell = document.createElement('td');
                    checkInTimeCell.style.fontSize = '0.75rem';
                    checkInTimeCell.style.padding = '0.25rem';
                    if (employee.has_checked_in && employee.check_in_time) {
                        const checkInDate = new Date(employee.check_in_time);
                        const hours = String(checkInDate.getHours()).padStart(2, '0');
                        const minutes = String(checkInDate.getMinutes()).padStart(2, '0');
                        checkInTimeCell.innerHTML = '<strong>' + hours + ':' + minutes + '</strong>';
                    } else {
                        checkInTimeCell.innerHTML = '<span class="text-muted">لم يسجل</span>';
                    }
                    row.appendChild(checkInTimeCell);
                    
                    // التأخير
                    const delayCell = document.createElement('td');
                    delayCell.style.fontSize = '0.75rem';
                    delayCell.style.padding = '0.25rem';
                    if (employee.has_checked_in) {
                        if (employee.delay_minutes > 0) {
                            delayCell.innerHTML = '<span class="badge bg-warning" style="font-size: 0.7rem;">' + employee.delay_minutes + ' دقيقة</span>';
                        } else {
                            delayCell.innerHTML = '<span class="badge bg-success" style="font-size: 0.7rem;">في الوقت</span>';
                        }
                    } else {
                        delayCell.innerHTML = '<span class="text-muted">-</span>';
                    }
                    row.appendChild(delayCell);
                    
                    tableBody.appendChild(row);
                });
            }
            
            // إخفاء التحميل وإظهار القائمة
            loading.style.display = 'none';
            list.style.display = 'block';
        } else {
            throw new Error('بيانات غير صحيحة');
        }
    } catch (err) {
        console.error('Error loading all employees attendance:', err);
        loading.style.display = 'none';
        error.style.display = 'block';
        const errorSmall = error.querySelector('small');
        if (errorSmall) {
            errorSmall.textContent = 'حدث خطأ أثناء تحميل البيانات: ' + (err.message || 'خطأ غير معروف');
        }
    }
}

// عرض ملخص الوقت
async function updateTimeSummary() {
    if (currentAction !== 'check_in') {
        const timeSummaryContainer = document.getElementById('timeSummaryContainer');
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
        const currentTimeDisplay = document.getElementById('currentTimeDisplay');
        if (currentTimeDisplay) {
            const hours = String(checkInTime.getHours()).padStart(2, '0');
            const minutes = String(checkInTime.getMinutes()).padStart(2, '0');
            currentTimeDisplay.textContent = hours + ':' + minutes;
        }
        
        // تحديث عرض موعد العمل
        const officialTimeDisplay = document.getElementById('officialTimeDisplay');
        if (officialTimeDisplay) {
            const officialHours = String(officialStartTime.getHours()).padStart(2, '0');
            const officialMinutes = String(officialStartTime.getMinutes()).padStart(2, '0');
            officialTimeDisplay.textContent = officialHours + ':' + officialMinutes;
        }
        
        // تحديث عرض الحالة
        const timeStatusDisplay = document.getElementById('timeStatusDisplay');
        if (timeStatusDisplay) {
            const diffMs = checkInTime - officialStartTime;
            const diffMinutes = Math.round(diffMs / 60000);
            
            if (diffMinutes > 0) {
                // تأخير
                // تنظيف HTML لمنع XSS
                const safeMinutes = escapeHTML ? escapeHTML(String(diffMinutes)) : String(diffMinutes);
                timeStatusDisplay.innerHTML = '<span class="badge bg-warning">متأخر ' + safeMinutes + ' دقيقة</span>';
                timeStatusDisplay.className = 'fw-bold text-warning';
            } else if (diffMinutes < 0) {
                // مبكر
                const earlyMinutes = Math.abs(diffMinutes);
                // تنظيف HTML لمنع XSS
                const safeEarlyMinutes = escapeHTML ? escapeHTML(String(earlyMinutes)) : String(earlyMinutes);
                timeStatusDisplay.innerHTML = '<span class="badge bg-info">مبكر ' + safeEarlyMinutes + ' دقيقة</span>';
                timeStatusDisplay.className = 'fw-bold text-info';
            } else {
                // في الوقت
                timeStatusDisplay.innerHTML = '<span class="badge bg-success">في الوقت</span>';
                timeStatusDisplay.className = 'fw-bold text-success';
            }
        }
        
        // إظهار ملخص الوقت
        const timeSummaryContainer = document.getElementById('timeSummaryContainer');
        if (timeSummaryContainer) {
            timeSummaryContainer.style.display = 'block';
        }
        
    } catch (error) {
        console.error('Error updating time summary:', error);
        const timeSummaryContainer = document.getElementById('timeSummaryContainer');
        if (timeSummaryContainer) {
            timeSummaryContainer.style.display = 'none';
        }
    }
}

// معالجة فتح الـ modal
document.addEventListener('DOMContentLoaded', function() {
    const cameraModal = document.getElementById('cameraModal');
    const checkInBtn = document.getElementById('checkInBtn');
    const checkOutBtn = document.getElementById('checkOutBtn');
    const captureBtn = document.getElementById('captureBtn');
    const retakeBtn = document.getElementById('retakeBtn');
    const submitBtn = document.getElementById('submitBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    
    // التحقق من وجود العناصر
    if (!cameraModal) {
        console.error('Camera modal not found');
        return;
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
    
    // عند فتح الـ modal
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
        
        // تحميل تفاصيل حضور جميع الموظفين (فقط للمحاسبين والمديرين)
        const userRole = window.currentUser?.role;
        if (userRole === 'accountant' || userRole === 'manager') {
            loadAllEmployeesAttendance();
        } else {
            // إخفاء قسم تفاصيل حضور جميع الموظفين للمستخدمين العاديين
            const container = document.getElementById('allEmployeesAttendanceContainer');
            if (container) {
                container.style.display = 'none';
            }
        }
        
        // إعادة تعيين الحالة
        capturedPhoto = null;
        const cameraContainer = document.getElementById('cameraContainer');
        const capturedImageContainer = document.getElementById('capturedImageContainer');
        const delayReasonContainer = document.getElementById('delayReasonContainer');
        const delayReasonInput = document.getElementById('delayReason');
        const video = document.getElementById('video');
        
        if (cameraContainer) {
            cameraContainer.style.display = 'block';
            cameraContainer.style.visibility = 'visible';
        }
        if (capturedImageContainer) {
            capturedImageContainer.style.display = 'none';
            capturedImageContainer.style.visibility = 'hidden';
        }
        if (captureBtn) {
            captureBtn.style.display = 'none';
            captureBtn.style.visibility = 'hidden';
        }
        if (retakeBtn) {
            retakeBtn.style.display = 'none';
            retakeBtn.style.visibility = 'hidden';
        }
        if (submitBtn) {
            submitBtn.style.display = 'none';
            submitBtn.style.visibility = 'hidden';
        }
        if (delayReasonContainer) delayReasonContainer.style.display = 'none';
        if (delayReasonInput) {
            delayReasonInput.value = '';
            delayReasonInput.disabled = true;
        }
        
        // إعادة تعيين الفيديو
        if (video) {
            video.srcObject = null;
            video.style.display = 'none';
            video.style.visibility = 'hidden';
        }
        
        // إيقاف أي stream سابق
        stopCamera();
        
        // تحديث ملخص الوقت (للتسجيل الحضور فقط)
        workTimeData = null; // إعادة تعيين
        if (currentAction === 'check_in') {
            updateTimeSummary();
            // تحديث الوقت كل ثانية - إيقاف عند إخفاء الصفحة لتقليل الضغط
            if (timeSummaryInterval) {
                clearInterval(timeSummaryInterval);
            }
            
            // إيقاف عند إخفاء الصفحة وإعادة التشغيل عند الظهور
            if (!visibilityHandlerAdded) {
                document.addEventListener('visibilitychange', function handleVisibility() {
                    if (document.hidden) {
                        // إيقاف interval عند إخفاء الصفحة
                        if (timeSummaryInterval) {
                            clearInterval(timeSummaryInterval);
                            timeSummaryInterval = null;
                        }
                    } else if (currentAction === 'check_in' && !timeSummaryInterval) {
                        // إعادة التشغيل عند الظهور
                        updateTimeSummary();
                        timeSummaryInterval = setInterval(updateTimeSummary, 1000);
                    }
                });
                visibilityHandlerAdded = true;
            }
            
            // بدء interval فقط إذا كانت الصفحة مرئية
            if (!document.hidden && !timeSummaryInterval) {
                timeSummaryInterval = setInterval(updateTimeSummary, 1000);
            }
        } else {
            const timeSummaryContainer = document.getElementById('timeSummaryContainer');
            if (timeSummaryContainer) {
                timeSummaryContainer.style.display = 'none';
            }
            if (timeSummaryInterval) {
                clearInterval(timeSummaryInterval);
                timeSummaryInterval = null;
            }
        }
        
        // إزالة backdrop بعد تأخير قصير (للتأكد من إزالته حتى لو تم إنشاؤه بعد فتح Modal)
        // استخدام requestAnimationFrame لضمان أن الـ modal مرئي تماماً قبل تحميل الكاميرا
        requestAnimationFrame(() => {
            requestAnimationFrame(async () => {
                removeBackdrop();
                try {
                    await initCamera();
                } catch (error) {
                    console.error('Error initializing camera in modal:', error);
                    // showCameraError سيتم استدعاؤها من داخل initCamera
                }
            });
        });
        
        // مراقبة مستمرة لإزالة backdrop (زيادة الفترة من 50ms إلى 1000ms لتقليل الضغط)
        const backdropInterval = setInterval(() => {
            removeBackdrop();
        }, 1000);
        
        // حفظ interval ID لإيقافه لاحقاً
        cameraModal.dataset.backdropInterval = backdropInterval;
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
    
    // إضافة event listeners للأزرار مع التحقق من حالة التعطيل
    if (checkInBtn) {
        checkInBtn.addEventListener('click', function(e) {
            if (checkInBtn.disabled) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            // تعيين الإجراء وفتح modal
            currentAction = 'check_in';
            const modal = new bootstrap.Modal(cameraModal);
            modal.show();
        });
    }
    
    if (checkOutBtn) {
        checkOutBtn.addEventListener('click', function(e) {
            if (checkOutBtn.disabled) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            // تعيين الإجراء وفتح modal
            currentAction = 'check_out';
            const modal = new bootstrap.Modal(cameraModal);
            modal.show();
        });
    }
    
    // أحداث الأزرار
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
    
    // زر إعادة المحاولة للكاميرا
    const retryCameraBtn = document.getElementById('retryCameraBtn');
    if (retryCameraBtn) {
        retryCameraBtn.addEventListener('click', function() {
            retryCamera();
        });
    }
});


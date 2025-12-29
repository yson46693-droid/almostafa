<?php
/**
 * صفحة تسجيل البصمة للمستخدم
 */

define('ACCESS_ALLOWED', true);
// تعريف ثابت لمنع حذف الجلسة في register_fingerprint.php
define('WEBAUTHN_API_ACTIVE', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// التحقق من تسجيل الدخول - يجب أن يكون في البداية قبل أي شيء آخر
if (!isLoggedIn()) {
    // تنظيف output buffer
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // إعادة التوجيه إلى صفحة تسجيل الدخول
    $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
    $loginUrl = preg_replace('/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/', '', $loginUrl);
    $loginUrl = rtrim($loginUrl, '?&');
    $loginUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $loginUrl);
    $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
    if (strpos($loginUrl, '/') !== 0) {
        $loginUrl = '/' . $loginUrl;
    }
    $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
    
    if (!@headers_sent()) {
        @header('Location: ' . $loginUrl, true, 303);
        exit;
    } else {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
        echo '<script>window.location.replace(' . json_encode($loginUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
        exit;
    }
}

// التحقق الإضافي باستخدام requireLogin() كحماية ثانوية
requireLogin();

// التحقق من وجود بيانات المستخدم بعد تسجيل الدخول
$user = getCurrentUser();
if (!$user || !isset($user['id']) || empty($user['id'])) {
    // تنظيف output buffer
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // إعادة التوجيه إلى صفحة تسجيل الدخول
    $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
    $loginUrl = preg_replace('/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/', '', $loginUrl);
    $loginUrl = rtrim($loginUrl, '?&');
    $loginUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $loginUrl);
    $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
    if (strpos($loginUrl, '/') !== 0) {
        $loginUrl = '/' . $loginUrl;
    }
    $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
    
    if (!@headers_sent()) {
        @header('Location: ' . $loginUrl, true, 303);
        exit;
    } else {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
        echo '<script>window.location.replace(' . json_encode($loginUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
        exit;
    }
}

// تحميل اللغة
require_once __DIR__ . '/includes/lang/' . getCurrentLanguage() . '.php';
$lang = $translations;
$pageTitle = 'تسجيل البصمة';
include __DIR__ . '/templates/header.php';

// الحصول على بيانات المستخدم (تم التحقق منها أعلاه)
$userRole = $user['role'] ?? 'accountant';
$dashboardUrl = getDashboardUrl($userRole);
?>
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-lg border-0">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-fingerprint me-2"></i>تسجيل البصمة
                    </h4>
                </div>
                <div class="card-body p-4">
                    <!-- حالة WebAuthn -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <strong>حالة البصمة:</strong>
                            <span class="badge bg-<?php echo $user['webauthn_enabled'] ? 'success' : 'secondary'; ?>">
                                <?php echo $user['webauthn_enabled'] ? 'مفعّل' : 'غير مفعّل'; ?>
                            </span>
                        </div>
                        
                        <!-- قائمة البصمات المسجلة -->
                        <div id="credentialsList" class="mb-3">
                            <div class="text-center">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">جاري التحميل...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- زر تسجيل بصمة جديدة -->
                    <div class="d-grid gap-2 mb-3">
                        <button type="button" class="btn btn-primary btn-lg" id="registerWebAuthnBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة بصمة جديدة
                        </button>
                    </div>
                    
                    <!-- معلومات -->
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>
                            يمكنك تسجيل بصمة أو Passkey لاستخدامه في تسجيل الدخول بسهولة وأمان.
                            <br>
                            <strong>دعم جميع الأجهزة:</strong>
                            <ul class="mb-0 mt-2" style="font-size: 0.9em;">
                                <li><strong>Android:</strong> البصمة، Face Unlock، Google Passkeys</li>
                                <li><strong>iOS:</strong> Face ID، Touch ID، iCloud Keychain</li>
                                <li><strong>Desktop:</strong> Windows Hello، Touch ID (Mac)، USB Keys</li>
                                <li><strong>Google Passkeys:</strong> يتم حفظ Passkey في حساب Google تلقائياً</li>
                            </ul>
                            <strong class="mt-2 d-block">ملاحظة:</strong> تأكد من تفعيل البصمة/Face ID/Touch ID في إعدادات جهازك.
                        </small>
                    </div>
                    
                </div>
            </div>
            
            <!-- قسم الملف الشخصي -->
            <div class="card shadow-lg border-0 mt-4">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-person-circle me-2"></i>الملف الشخصي
                    </h4>
                </div>
                <div class="card-body p-4">
                    <form id="profileForm">
                        <!-- الاسم الكامل -->
                        <div class="mb-3">
                            <label for="fullName" class="form-label">
                                <i class="bi bi-person me-2"></i>الاسم الكامل
                            </label>
                            <input type="text" 
                                   class="form-control" 
                                   id="fullName" 
                                   name="full_name"
                                   value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>"
                                   required>
                        </div>
                        
                        <!-- رقم الهاتف -->
                        <div class="mb-3">
                            <label for="phone" class="form-label">
                                <i class="bi bi-telephone me-2"></i>رقم الهاتف
                            </label>
                            <input type="tel" 
                                   class="form-control" 
                                   id="phone" 
                                   name="phone"
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                   placeholder="مثال: 01234567890">
                        </div>
                        
                        <!-- البريد الإلكتروني (للعرض فقط) -->
                        <div class="mb-3">
                            <label for="email" class="form-label">
                                <i class="bi bi-envelope me-2"></i>البريد الإلكتروني
                            </label>
                            <input type="email" 
                                   class="form-control" 
                                   id="email" 
                                   name="email"
                                   value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                                   required>
                            <small class="text-muted">لا يستخدم لتسجيل الدخول</small>
                        </div>
                        
                        <!-- اسم المستخدم (للعرض فقط) -->
                        <div class="mb-3">
                            <label for="username" class="form-label">
                                <i class="bi bi-person-badge me-2"></i>اسم المستخدم
                            </label>
                            <input type="text" 
                                   class="form-control" 
                                   id="username" 
                                   value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>"
                                   disabled>
                            <small class="text-muted">لا يمكن تغيير اسم المستخدم</small>
                        </div>
                        
                        <!-- زر الحفظ -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="saveProfileBtn">
                                <i class="bi bi-save me-2"></i>حفظ التغييرات
                            </button>
                        </div>
                    </form>
                    
                    <hr class="my-4">
                    
                    <!-- تغيير كلمة المرور -->
                    <h5 class="mb-3">
                        <i class="bi bi-key me-2"></i>تغيير كلمة المرور
                    </h5>
                    <form id="passwordForm">
                        <div class="mb-3">
                            <label for="currentPassword" class="form-label">كلمة المرور الحالية</label>
                            <input type="password" 
                                   class="form-control" 
                                   id="currentPassword" 
                                   name="current_password"
                                   required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">كلمة المرور الجديدة</label>
                            <input type="password" 
                                   class="form-control" 
                                   id="newPassword" 
                                   name="new_password"
                                   minlength="<?php echo getPasswordMinLength(); ?>"
                                   required>
                            <small class="text-muted">يجب أن تكون على الأقل <?php echo getPasswordMinLength(); ?> أحرف</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label">تأكيد كلمة المرور</label>
                            <input type="password" 
                                   class="form-control" 
                                   id="confirmPassword" 
                                   name="confirm_password"
                                   minlength="<?php echo getPasswordMinLength(); ?>"
                                   required>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-warning btn-lg" id="changePasswordBtn">
                                <i class="bi bi-key-fill me-2"></i>تغيير كلمة المرور
                            </button>
                        </div>
                    </form>
                    
                    <!-- زر العودة -->
                    <div class="mt-4 text-center">
                        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right me-2"></i>العودة إلى لوحة التحكم
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>

<!-- WebAuthn Script -->
<script src="<?php echo ASSETS_URL; ?>js/webauthn.js"></script>

<style>
#registerWebAuthnBtn {
    position: relative !important;
    z-index: 1001 !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}

#registerWebAuthnBtn:disabled {
    opacity: 0.6;
    cursor: not-allowed !important;
}
</style>

<script>
// تحميل قائمة البصمات المسجلة
async function loadCredentials() {
    try {
        const listContainer = document.getElementById('credentialsList');
        if (!listContainer) {
            console.error('credentialsList element not found');
            return;
        }
        
        // الحصول على المسار الصحيح لـ API
        const pathParts = window.location.pathname.split('/').filter(p => p && !p.endsWith('.php'));
        let apiPath;
        
        if (pathParts.length === 0) {
            apiPath = 'api/webauthn_credentials.php';
        } else {
            apiPath = '/' + pathParts[0] + '/api/webauthn_credentials.php';
        }
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);
        
        const response = await fetch(apiPath + '?action=list', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            },
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        if (!response.ok) {
            // معالجة خاصة لخطأ 401
            if (response.status === 401) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error('انتهت جلسة العمل. يرجى إعادة تسجيل الدخول ثم المحاولة مرة أخرى.');
            }
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            throw new Error('استجابة غير صحيحة من الخادم');
        }
        
        const data = await response.json();
        
        if (!data.success) {
            listContainer.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>خطأ في تحميل البصمات: ' + (data.error || 'حدث خطأ غير معروف') + '</div>';
            return;
        }
        
        if (data.credentials.length === 0) {
            listContainer.innerHTML = `
                <div class="alert alert-secondary mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    لا توجد بصمات مسجلة حالياً
                </div>
            `;
            return;
        }
        
        let html = '<div class="list-group">';
        data.credentials.forEach(cred => {
            const createdDate = new Date(cred.created_at).toLocaleDateString('ar-EG');
            const lastUsed = cred.last_used ? new Date(cred.last_used).toLocaleDateString('ar-EG') : 'لم يتم الاستخدام';
            
            html += `
                <div class="list-group-item d-flex justify-content-between align-items-center" data-credential-id="${cred.id}">
                    <div>
                        <div class="fw-bold">
                            <i class="bi bi-fingerprint me-2"></i>
                            ${cred.device_name || 'جهاز غير معروف'}
                        </div>
                        <small class="text-muted">
                            <div>تاريخ التسجيل: ${createdDate}</div>
                            <div>آخر استخدام: ${lastUsed}</div>
                        </small>
                    </div>
                    <button class="btn btn-sm btn-danger delete-credential-btn" data-credential-id="${cred.id}" data-device-name="${(cred.device_name || 'البصمة').replace(/"/g, '&quot;')}">
                        <i class="bi bi-trash"></i> حذف
                    </button>
                </div>
            `;
        });
        html += '</div>';
        
        listContainer.innerHTML = html;
        
        // إضافة event listeners لأزرار الحذف
        listContainer.querySelectorAll('.delete-credential-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const credentialId = this.getAttribute('data-credential-id');
                const deviceName = this.getAttribute('data-device-name');
                deleteCredential(credentialId, deviceName);
            });
        });
        
    } catch (error) {
        console.error('Error loading credentials:', error);
        const listContainer = document.getElementById('credentialsList');
        if (listContainer) {
            let errorMessage = 'خطأ في تحميل البصمات';
            
            if (error.name === 'AbortError' || error.message.includes('aborted')) {
                errorMessage = 'انتهت مهلة التحميل. يمكنك المحاولة مرة أخرى.';
            } else if (error.message.includes('انتهت جلسة العمل')) {
                errorMessage = error.message + '<br><a href="' + window.location.origin + '/index.php" class="alert-link">اضغط هنا لتسجيل الدخول</a>';
            } else if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
                errorMessage += ': ' + error.message;
            } else {
                errorMessage += ': ' + error.message;
            }
            
            listContainer.innerHTML = 
                '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + errorMessage + '</div>';
        }
    }
}

// تسجيل بصمة جديدة
async function registerNewCredential() {
    const btn = document.getElementById('registerWebAuthnBtn');
    if (!btn) {
        console.error('registerWebAuthnBtn button not found');
        return;
    }
    
    const originalHTML = btn.innerHTML;
    
    try {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التسجيل...';
        
        if (typeof simpleWebAuthn === 'undefined') {
            throw new Error('نظام WebAuthn غير محمّل. يرجى تحديث الصفحة.');
        }
        
        const result = await simpleWebAuthn.register();
        
        if (result.success) {
            // إعادة تحميل قائمة البصمات
            await loadCredentials();
            
            // إظهار رسالة نجاح
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="bi bi-check-circle-fill me-2"></i>
                ${result.message || 'تم تسجيل البصمة بنجاح!'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            const cardBody = document.querySelector('.card-body');
            if (cardBody) {
                cardBody.insertBefore(alertDiv, cardBody.firstChild);
                setTimeout(() => {
                    alertDiv.remove();
                }, 5000);
            }
        } else {
            alert(result.message || 'فشل تسجيل البصمة');
        }
        
    } catch (error) {
        console.error('WebAuthn Registration Error:', error);
        
        let errorMessage = error.message || 'حدث خطأ أثناء تسجيل البصمة';
        
        if (errorMessage.includes('تم إلغاء العملية') || errorMessage.includes('تأكد من:')) {
            alert(errorMessage);
        } else {
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            if (isMobile) {
                errorMessage += '\n\nملاحظة للموبايل:\n';
                errorMessage += '1. تأكد من تفعيل Face ID/Touch ID في إعدادات الجهاز\n';
                errorMessage += '2. اضغط "Allow" عند ظهور نافذة طلب الإذن\n';
                errorMessage += '3. تأكد من أن الموقع يعمل على HTTPS';
            }
            alert(errorMessage);
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

// حذف بصمة
async function deleteCredential(credentialId, deviceName) {
    const deviceNameSafe = typeof deviceName === 'string' ? deviceName : 'البصمة';
    if (!confirm(`هل أنت متأكد من حذف البصمة "${deviceNameSafe}"؟\n\nسيتم حذف هذه البصمة ولن تتمكن من استخدامها في تسجيل الدخول.`)) {
        return;
    }
    
    try {
        const pathParts = window.location.pathname.split('/').filter(p => p && !p.endsWith('.php'));
        let apiPath;
        
        if (pathParts.length === 0) {
            apiPath = 'api/webauthn_credentials.php';
        } else {
            apiPath = '/' + pathParts[0] + '/api/webauthn_credentials.php';
        }
        
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'delete',
                credential_id: credentialId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('تم حذف البصمة بنجاح');
            await loadCredentials();
        } else {
            alert('خطأ: ' + (data.error || 'حدث خطأ أثناء حذف البصمة'));
        }
        
    } catch (error) {
        console.error('Error deleting credential:', error);
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    }
}

// تهيئة عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
    // إضافة event listener للزر
    const registerBtn = document.getElementById('registerWebAuthnBtn');
    if (registerBtn) {
        registerBtn.addEventListener('click', function(e) {
            e.preventDefault();
            registerNewCredential();
        });
    }
    
    // التأكد من تحميل simpleWebAuthn
    if (typeof simpleWebAuthn === 'undefined') {
        console.error('simpleWebAuthn is not defined. Make sure webauthn.js is loaded.');
        if (registerBtn) {
            registerBtn.disabled = true;
            registerBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>WebAuthn غير متاح';
        }
    }
    
    // تحميل قائمة البصمات
    loadCredentials();
    
    // معالجة تحديث الملف الشخصي
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            await updateProfile();
        });
    }
    
    // معالجة تغيير كلمة المرور
    const passwordForm = document.getElementById('passwordForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            await changePassword();
        });
    }
});

// تحديث الملف الشخصي
async function updateProfile() {
    const btn = document.getElementById('saveProfileBtn');
    if (!btn) return;
    
    const originalHTML = btn.innerHTML;
    
    try {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الحفظ...';
        
        const formData = new FormData(document.getElementById('profileForm'));
        formData.append('action', 'update_profile');
        
        const pathParts = window.location.pathname.split('/').filter(p => p && !p.endsWith('.php'));
        let apiPath;
        
        if (pathParts.length === 0) {
            apiPath = 'api/update_profile.php';
        } else {
            apiPath = '/' + pathParts[0] + '/api/update_profile.php';
        }
        
        const response = await fetch(apiPath, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // إظهار رسالة نجاح
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="bi bi-check-circle-fill me-2"></i>
                ${data.message || 'تم تحديث الملف الشخصي بنجاح!'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            const cardBody = document.querySelector('#profileForm').closest('.card-body');
            if (cardBody) {
                cardBody.insertBefore(alertDiv, cardBody.firstChild);
                setTimeout(() => {
                    alertDiv.remove();
                }, 5000);
            }
        } else {
            alert('خطأ: ' + (data.error || 'حدث خطأ أثناء تحديث الملف الشخصي'));
        }
        
    } catch (error) {
        console.error('Error updating profile:', error);
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

// تغيير كلمة المرور
async function changePassword() {
    const btn = document.getElementById('changePasswordBtn');
    if (!btn) return;
    
    const originalHTML = btn.innerHTML;
    const form = document.getElementById('passwordForm');
    
    try {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التغيير...';
        
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        
        if (newPassword !== confirmPassword) {
            alert('كلمة المرور الجديدة غير متطابقة');
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            return;
        }
        
        const formData = new FormData(form);
        formData.append('action', 'change_password');
        
        const pathParts = window.location.pathname.split('/').filter(p => p && !p.endsWith('.php'));
        let apiPath;
        
        if (pathParts.length === 0) {
            apiPath = 'api/update_profile.php';
        } else {
            apiPath = '/' + pathParts[0] + '/api/update_profile.php';
        }
        
        const response = await fetch(apiPath, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // إظهار رسالة نجاح
            alert(data.message || 'تم تغيير كلمة المرور بنجاح!');
            
            // مسح الحقول
            form.reset();
        } else {
            alert('خطأ: ' + (data.error || 'حدث خطأ أثناء تغيير كلمة المرور'));
        }
        
    } catch (error) {
        console.error('Error changing password:', error);
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}
</script>


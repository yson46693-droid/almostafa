<?php
/**
 * صفحة إعدادات النظام للمدير
 */

// تعيين ترميز UTF-8
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole(['manager', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// التحقق من وضع الصيانة الحالي
$maintenanceMode = isMaintenanceMode();
$configFilePath = __DIR__ . '/../../includes/config.php';

// معالجة عمليات الإعدادات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_maintenance_mode') {
        try {
            $oldValue = $maintenanceMode;
            $newValue = !$maintenanceMode;
            
            // قراءة ملف config.php
            if (!file_exists($configFilePath) || !is_readable($configFilePath)) {
                throw new Exception('لا يمكن قراءة ملف الإعدادات');
            }
            
            $configContent = file_get_contents($configFilePath);
            if ($configContent === false) {
                throw new Exception('فشل قراءة ملف الإعدادات');
            }
            
            $newValueStr = $newValue ? 'true' : 'false';
            
            // البحث عن define MAINTENANCE_MODE مع if (!defined)
            $patternWithIf = "/(if\s*\(\s*!defined\s*\(\s*['\"]MAINTENANCE_MODE['\"]\s*\)\s*\)\s*\{[\s\S]*?define\s*\(\s*['\"]MAINTENANCE_MODE['\"]\s*,\s*)(true|false)(\s*\);\s*\})/";
            
            // البحث عن define MAINTENANCE_MODE مباشر
            $patternDirect = "/(define\s*\(\s*['\"]MAINTENANCE_MODE['\"]\s*,\s*)(true|false)(\s*\))/";
            
            if (preg_match($patternWithIf, $configContent)) {
                // استبدال القيمة في if (!defined)
                $configContent = preg_replace($patternWithIf, '$1' . $newValueStr . '$3', $configContent);
            } elseif (preg_match($patternDirect, $configContent)) {
                // استبدال القيمة في define مباشر
                $configContent = preg_replace($patternDirect, '$1' . $newValueStr . '$3', $configContent);
            } else {
                // إذا لم يكن موجوداً، أضفه بعد COMPANY_NAME
                $insertPattern = "/(define\s*\(\s*['\"]COMPANY_NAME['\"]\s*,\s*[^)]+\)\s*;)/";
                $replacement = '$1' . "\n\n// إعدادات وضع الصيانة\n// تغيير هذا إلى true لتفعيل وضع الصيانة\nif (!defined('MAINTENANCE_MODE')) {\n    define('MAINTENANCE_MODE', " . $newValueStr . ");\n}";
                $configContent = preg_replace($insertPattern, $replacement, $configContent, 1);
            }
            
            // كتابة المحتوى المحدث إلى الملف
            if (!is_writable($configFilePath)) {
                throw new Exception('ملف الإعدادات غير قابل للكتابة. يرجى التحقق من صلاحيات الملف');
            }
            
            $result = file_put_contents($configFilePath, $configContent, LOCK_EX);
            if ($result === false) {
                throw new Exception('فشل كتابة ملف الإعدادات');
            }
            
            // تسجيل العملية في audit log
            logAudit($currentUser['id'], 'toggle_maintenance_mode', 'config', null, ['old_value' => $oldValue ? 'true' : 'false'], ['new_value' => $newValue ? 'true' : 'false']);
            
            $success = $newValue ? 'تم تفعيل وضع الصيانة بنجاح. سيتم تطبيق التغيير بعد إعادة تحميل الصفحة.' : 'تم تعطيل وضع الصيانة بنجاح. سيتم تطبيق التغيير بعد إعادة تحميل الصفحة.';
            
            // إعادة تحميل الصفحة لتحديث الحالة (سيتم تحميل config.php مرة أخرى)
            preventDuplicateSubmission($success, [], null, null, null);
            
        } catch (Exception $e) {
            error_log("Error toggling maintenance mode: " . $e->getMessage());
            $error = 'حدث خطأ في تحديث إعدادات النظام: ' . $e->getMessage();
        }
    }
}

// تطبيق PRG pattern
applyPRGPattern($error, $success);
?>

<style>
/* إصلاح مشاكل التصميم في صفحة إعدادات النظام */
.system-settings-container .row {
    margin-left: 0;
    margin-right: 0;
    overflow: visible;
}

.system-settings-container .col-md-8,
.system-settings-container .col-md-4 {
    padding-left: 15px;
    padding-right: 15px;
    position: relative;
    overflow: visible;
}

/* إصلاح مشكلة التفاف النص */
.system-settings-container code {
    word-break: keep-all;
    white-space: nowrap;
    display: inline-block;
    padding: 0.2em 0.4em;
    font-size: 0.875em;
}

.system-settings-container .text-muted code {
    word-break: keep-all;
    white-space: normal;
    word-wrap: break-word;
}

/* إصلاح مشكلة الشارة المقطوعة */
.system-settings-container .badge {
    padding: 0.5em 0.75em;
    font-size: 0.875em;
    line-height: 1.4;
    min-height: 1.8em;
    display: inline-flex;
    align-items: center;
    vertical-align: middle;
    white-space: nowrap;
}

/* إصلاح تخطيط البطاقات */
.system-settings-container .card {
    margin-bottom: 1.5rem;
    overflow: visible;
    position: relative;
}

.system-settings-container .card-body {
    overflow: visible;
}

/* إصلاح مشكلة العنصر العمودي المقطوع */
.system-settings-container .col-md-4 .card {
    position: relative;
    z-index: 1;
    height: 100%;
}

/* إصلاح مشكلة النص في قسم وضع الصيانة */
.system-settings-container .d-flex.align-items-center {
    flex-wrap: wrap;
    gap: 1rem;
}

.system-settings-container .d-flex.align-items-center > div:first-child {
    flex: 1;
    min-width: 200px;
}

/* تحسين عرض النص في code tags */
.system-settings-container small code {
    font-size: 0.85em;
    word-break: break-all;
    white-space: pre-wrap;
    max-width: 100%;
}

/* إصلاح مشكلة overflow */
.system-settings-container {
    overflow-x: hidden;
}

@media (max-width: 768px) {
    .system-settings-container .col-md-8,
    .system-settings-container .col-md-4 {
        margin-bottom: 1rem;
    }
    
    .system-settings-container .d-flex.align-items-center {
        flex-direction: column;
        align-items: flex-start !important;
    }
}
</style>

<div class="container-fluid system-settings-container">
    <div class="page-header mb-4">
        <h2><i class="bi bi-gear"></i> إعدادات النظام</h2>
        <p class="text-muted">إدارة إعدادات النظام العامة</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-tools me-2"></i>وضع الصيانة</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        عند تفعيل وضع الصيانة، لن يتمكن أي مستخدم من استخدام النظام عدا حساب المطور.
                        سيتم عرض رسالة تنبيه للمستخدمين العاديين تطلب منهم إعادة المحاولة لاحقاً.
                        <br><strong>ملاحظة:</strong> يتم حفظ إعدادات وضع الصيانة في ملف <code class="text-break">includes/config.php</code>
                    </p>

                    <?php if (!is_writable($configFilePath)): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>تحذير:</strong> ملف الإعدادات غير قابل للكتابة. يرجى التحقق من صلاحيات الملف <code>includes/config.php</code>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="toggle_maintenance_mode">
                        
                        <div class="d-flex align-items-center justify-content-between p-3 border rounded">
                            <div>
                                <h6 class="mb-1">وضع الصيانة</h6>
                                <small class="text-muted d-block mb-2">
                                    الحالة الحالية: 
                                    <span class="badge <?php echo $maintenanceMode ? 'bg-danger' : 'bg-success'; ?> ms-1">
                                        <?php echo $maintenanceMode ? 'مفعّل' : 'معطّل'; ?>
                                    </span>
                                </small>
                                <small class="text-muted d-block mt-2">
                                    الموقع: <code class="d-inline">includes/config.php</code> - <code class="d-inline">MAINTENANCE_MODE</code>
                                </small>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" 
                                       id="maintenanceModeSwitch" 
                                       <?php echo $maintenanceMode ? 'checked' : ''; ?>
                                       onchange="this.form.submit()"
                                       style="width: 3rem; height: 1.5rem;"
                                       <?php echo !is_writable($configFilePath) ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="maintenanceModeSwitch">
                                    <?php echo $maintenanceMode ? 'مفعّل' : 'معطّل'; ?>
                                </label>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-<?php echo $maintenanceMode ? 'warning' : 'primary'; ?>" <?php echo !is_writable($configFilePath) ? 'disabled' : ''; ?>>
                                <i class="bi bi-<?php echo $maintenanceMode ? 'x-circle' : 'check-circle'; ?> me-2"></i>
                                <?php echo $maintenanceMode ? 'تعطيل وضع الصيانة' : 'تفعيل وضع الصيانة'; ?>
                            </button>
                        </div>
                    </form>

                    <?php if ($maintenanceMode): ?>
                        <div class="alert alert-warning mt-3">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>تنبيه:</strong> وضع الصيانة مفعّل حالياً. جميع المستخدمين (عدا حساب المطور) لن يتمكنوا من استخدام النظام.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>معلومات مهمة</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-3">
                            <i class="bi bi-shield-check text-success me-2"></i>
                            <strong>حساب المطور:</strong><br>
                            <small class="text-muted">يمكن لحساب المطور تسجيل الدخول واستخدام النظام حتى عندما يكون وضع الصيانة مفعلاً.</small>
                        </li>
                        <li class="mb-3">
                            <i class="bi bi-people text-danger me-2"></i>
                            <strong>المستخدمون الآخرون:</strong><br>
                            <small class="text-muted">سيتم منعهم من تسجيل الدخول واستخدام النظام عند تفعيل وضع الصيانة.</small>
                        </li>
                        <li>
                            <i class="bi bi-bell text-warning me-2"></i>
                            <strong>الرسالة:</strong><br>
                            <small class="text-muted">سيتم عرض رسالة تنبيه للمستخدمين: "التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق"</small>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

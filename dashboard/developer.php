<?php
/**
 * لوحة التحكم للمطور
 */

define('ACCESS_ALLOWED', true);

// تنظيف أي output buffer سابق قبل أي شيء
while (ob_get_level() > 0) {
    ob_end_clean();
}

// تحديد الصفحة أولاً
$page = $_GET['page'] ?? 'overview';

// تحميل الملفات المطلوبة
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

// المطور فقط يمكنه الوصول
requireRole('developer');

// معالجة redirects قبل أي output
if ($page === 'backups') {
    // تنظيف أي output buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $redirectUrl = getRelativeUrl('dashboard/developer.php?page=security&tab=backup');
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        // Fallback: JavaScript redirect
        echo '<script>window.location.replace("' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '");</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

// تحميل باقي الملفات المطلوبة
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/activity_summary.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/table_styles.php';

$currentUser = getCurrentUser();
$db = db();

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = $translations;
$pageTitle = 'لوحة المطور';
$pageDescription = 'لوحة تحكم المطور - إدارة النظام والصيانة - ' . APP_NAME;
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

            <?php if ($page === 'overview' || $page === ''): ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-code-slash"></i>لوحة المطور</h2>
                    <p class="text-muted">لوحة التحكم الخاصة بالمطور - إدارة النظام والصيانة</p>
                </div>

                <?php
                $quickLinks = [
                    [
                        'label' => 'إعدادات النظام',
                        'icon' => 'bi-gear',
                        'url' => getRelativeUrl('dashboard/developer.php?page=system_settings')
                    ],
                    [
                        'label' => 'السجلات والتدقيق',
                        'icon' => 'bi-journal-text',
                        'url' => getRelativeUrl('dashboard/developer.php?page=audit_logs')
                    ],
                    [
                        'label' => 'إدارة المستخدمين',
                        'icon' => 'bi-people',
                        'url' => getRelativeUrl('dashboard/developer.php?page=users')
                    ],
                    [
                        'label' => 'الأمان',
                        'icon' => 'bi-shield-lock',
                        'url' => getRelativeUrl('dashboard/developer.php?page=security')
                    ],
                    [
                        'label' => 'النسخ الاحتياطية',
                        'icon' => 'bi-database',
                        'url' => getRelativeUrl('dashboard/developer.php?page=backups')
                    ],
                    [
                        'label' => 'لوحة المدير',
                        'icon' => 'bi-speedometer2',
                        'url' => getRelativeUrl('dashboard/manager.php')
                    ]
                ];
                ?>

                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="mb-0"><i class="bi bi-lightning-charge-fill me-2"></i>اختصارات سريعة</h5>
                        <span class="text-muted small">روابط سريعة لأهم الصفحات</span>
                    </div>
                    <div class="card-body">
                        <style>
                            .quick-links-grid {
                                display: grid;
                                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                                gap: 1rem;
                            }
                            .quick-link-item {
                                display: flex;
                                flex-direction: column;
                                align-items: center;
                                justify-content: center;
                                padding: 1.5rem;
                                background: #f8f9fa;
                                border-radius: 0.5rem;
                                text-decoration: none;
                                color: inherit;
                                transition: all 0.3s ease;
                                border: 2px solid transparent;
                            }
                            .quick-link-item:hover {
                                background: #e9ecef;
                                border-color: #0d6efd;
                                color: #0d6efd;
                                transform: translateY(-2px);
                                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                            }
                            .quick-link-icon {
                                font-size: 2rem;
                                margin-bottom: 0.5rem;
                            }
                            .quick-link-label {
                                font-weight: 500;
                                text-align: center;
                            }
                        </style>
                        <div class="quick-links-grid">
                            <?php foreach ($quickLinks as $link): ?>
                                <a href="<?php echo htmlspecialchars($link['url']); ?>" class="quick-link-item">
                                    <i class="bi <?php echo htmlspecialchars($link['icon']); ?> quick-link-icon"></i>
                                    <span class="quick-link-label"><?php echo htmlspecialchars($link['label']); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- معلومات النظام -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>معلومات النظام</h5>
                            </div>
                            <div class="card-body">
                                <dl class="row mb-0">
                                    <dt class="col-sm-6">اسم التطبيق:</dt>
                                    <dd class="col-sm-6"><?php echo APP_NAME; ?></dd>
                                    
                                    <dt class="col-sm-6">الإصدار:</dt>
                                    <dd class="col-sm-6"><?php echo APP_VERSION; ?></dd>
                                    
                                    <dt class="col-sm-6">وضع الصيانة:</dt>
                                    <dd class="col-sm-6">
                                        <span class="badge <?php echo isMaintenanceMode() ? 'bg-danger' : 'bg-success'; ?>">
                                            <?php echo isMaintenanceMode() ? 'مفعّل' : 'معطّل'; ?>
                                        </span>
                                    </dd>
                                    
                                    <dt class="col-sm-6">المستخدم الحالي:</dt>
                                    <dd class="col-sm-6"><?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>صلاحيات المطور</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-2">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        الوصول الكامل لجميع صفحات النظام
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        الوصول في وضع الصيانة
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        إدارة إعدادات النظام
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        إدارة المستخدمين والصلاحيات
                                    </li>
                                    <li>
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        الوصول إلى سجلات التدقيق والأمان
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($page === 'system_settings'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/manager/system_settings.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>

            <?php elseif ($page === 'audit_logs'): ?>
                <div class="page-header mb-4">
                    <h2><i class="bi bi-journal-text me-2"></i>سجلات التدقيق</h2>
                    <p class="text-muted">عرض جميع سجلات التدقيق في النظام</p>
                </div>
                <div class="card">
                    <div class="card-body">
                        <p>صفحة سجلات التدقيق - سيتم إضافتها قريباً</p>
                    </div>
                </div>

            <?php elseif ($page === 'users'): ?>
                <?php
                // تعيين context للمطور
                $usersModuleContext = 'users';
                $modulePath = __DIR__ . '/../modules/manager/users.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>

            <?php elseif ($page === 'security'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/manager/security.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>

            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    الصفحة المطلوبة غير موجودة.
                </div>
            <?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>

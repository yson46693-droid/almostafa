<?php
/**
 * لوحة تحكم السائق
 * صفحتان: الحضور والانصراف، مهام الإنتاج (أوردرات مع المندوب فقط)
 */

define('ACCESS_ALLOWED', true);

while (ob_get_level() > 0) {
    ob_end_clean();
}
if (!ob_get_level()) {
    ob_start();
}

// منع الكاش عند التبديل بين الصفحات/الحسابات لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/path_helper.php';

requireRole(['driver', 'manager', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$page = trim($_GET['page'] ?? 'dashboard');
if ($page === '') {
    $page = 'dashboard';
}

if ($page === 'attendance') {
    header('Location: ' . getRelativeUrl('attendance.php'));
    exit;
}

if ($page === 'user_wallet' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    while (ob_get_level() > 0) ob_end_clean();
    include __DIR__ . '/../modules/user/user_wallet.php';
    exit;
}

$isAjaxNavigation = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    isset($_SERVER['HTTP_ACCEPT']) &&
    stripos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false
);

if ($isAjaxNavigation) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-AJAX-Navigation: true');
    ob_start();
}

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = 'لوحة السائق';
$pageDescription = 'لوحة تحكم السائق - الحضور والانصراف ومهام التوصيل - ' . APP_NAME;
?>
<?php if (!$isAjaxNavigation): ?>
<?php include __DIR__ . '/../templates/header.php'; ?>
<?php endif; ?>

            <?php if ($page === 'user_wallet'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/user/user_wallet.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة محفظة المستخدم غير متاحة حالياً</div>';
                }
                ?>
            <?php elseif ($page === 'vehicle_maintenance'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/driver/vehicle_maintenance.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة صيانات السيارة غير متاحة حالياً</div>';
                }
                ?>
            <?php elseif ($page === 'tasks'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/production/tasks.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة المهام غير متاحة حالياً</div>';
                }
                ?>
            <?php elseif ($page === 'daily_collection_my_tables'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/shared/daily_collection_my_tables.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة جداول التحصيل اليومية غير متاحة حالياً</div>';
                }
                ?>
            <?php elseif ($page === 'dashboard'): ?>
                <?php
                $baseUrlDriver = rtrim(getBasePath(), '/') . '/dashboard/driver.php';
                if (strpos($baseUrlDriver, '/') !== 0) {
                    $baseUrlDriver = '/' . $baseUrlDriver;
                }
                $attendanceUrl = getRelativeUrl('attendance.php');
                $tasksUrl = $baseUrlDriver . '?page=tasks';
                $hasUserWallet = file_exists(__DIR__ . '/../modules/user/user_wallet.php');
                $maintenanceUrl = $baseUrlDriver . '?page=vehicle_maintenance';
                $dailyCollectionUrl = $baseUrlDriver . '?page=daily_collection_my_tables';
                $hasDailyCollection = file_exists(__DIR__ . '/../modules/shared/daily_collection_my_tables.php');
                require_once __DIR__ . '/../includes/vehicle_maintenance.php';
                $driverVehicle = getDriverVehicle($currentUser['id']);
                $driverOilAlert = $driverVehicle ? getVehicleOilChangeAlert($driverVehicle['id']) : null;
                $showDriverOilAlert = $driverOilAlert && !empty($driverOilAlert['need_alert']);
                ?>
                <div class="container-fluid">
                    <div class="page-header mb-4">
                        <h2><i class="bi bi-speedometer2 me-2"></i>لوحة السائق</h2>
                        <p class="text-muted mb-0">الحضور والانصراف، وأوردرات التوصيل (مع المندوب)</p>
                    </div>

                    <?php if ($showDriverOilAlert): ?>
                    <div class="alert alert-warning alert-dismissible fade show border-warning mb-4" role="alert">
                        <span class="badge bg-warning text-dark me-2">سائق</span>
                        <i class="bi bi-droplet-fill me-2"></i>
                        السيارة تحتاج إلى تغيير الزيت في أقرب وقت (تم قطع <?php echo number_format($driverOilAlert['km_since_oil']); ?> كم منذ آخر تغيير زيت).
                        <a href="<?php echo htmlspecialchars($maintenanceUrl); ?>" class="alert-link ms-2">صيانات السيارة <i class="bi bi-arrow-left"></i></a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6 col-lg-4">
                            <a href="<?php echo htmlspecialchars($attendanceUrl); ?>" class="text-decoration-none">
                                <div class="card shadow-sm h-100 border-primary border-2 hover-shadow">
                                    <div class="card-body text-center py-4">
                                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex p-3 mb-2">
                                            <i class="bi bi-calendar-check fs-2 text-primary"></i>
                                        </div>
                                        <h5 class="card-title text-dark">الحضور والانصراف</h5>
                                        <p class="card-text text-muted small mb-0">تسجيل الحضور والانصراف (مواعيد العمل 1 م – 10 م)</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <a href="<?php echo htmlspecialchars($tasksUrl); ?>" class="text-decoration-none">
                                <div class="card shadow-sm h-100 border-info border-2 hover-shadow">
                                    <div class="card-body text-center py-4">
                                        <div class="rounded-circle bg-info bg-opacity-10 d-inline-flex p-3 mb-2">
                                            <i class="bi bi-list-task fs-2 text-info"></i>
                                        </div>
                                        <h5 class="card-title text-dark">الاوردرات</h5>
                                        <p class="card-text text-muted small mb-0">عرض الاوردرات  وتحديث حالتها إلى «تم التوصيل» أو «تم الارجاع».</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php if ($hasUserWallet): ?>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <a href="<?php echo htmlspecialchars($baseUrlDriver . '?page=user_wallet'); ?>" class="text-decoration-none">
                                <div class="card shadow-sm h-100 border-success border-2 hover-shadow">
                                    <div class="card-body text-center py-4">
                                        <div class="rounded-circle bg-success bg-opacity-10 d-inline-flex p-3 mb-2">
                                            <i class="bi bi-wallet2 fs-2 text-success"></i>
                                        </div>
                                        <h5 class="card-title text-dark">محفظة المستخدم</h5>
                                        <p class="card-text text-muted small mb-0">عرض المحفظة والرصيد</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasDailyCollection): ?>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <a href="<?php echo htmlspecialchars($dailyCollectionUrl); ?>" class="text-decoration-none">
                                <div class="card shadow-sm h-100 border-info border-2 hover-shadow">
                                    <div class="card-body text-center py-4">
                                        <div class="rounded-circle bg-info bg-opacity-10 d-inline-flex p-3 mb-2">
                                            <i class="bi bi-calendar2-range fs-2 text-info"></i>
                                        </div>
                                        <h5 class="card-title text-dark">جداول التحصيل اليومية</h5>
                                        <p class="card-text text-muted small mb-0">عرض الجداول المخصصة وتحديث حالة التحصيل</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h6 class="card-subtitle mb-2 text-muted"><i class="bi bi-info-circle me-1"></i>ملخص</h6>
                            <p class="card-text mb-0">من لوحة السائق يمكنك تسجيل الحضور والانصراف ضمن مواعيد العمل (من 1 مساءً إلى 10 مساءً)، وعرض الأوردرات ذات الحالة «مع المندوب» وتحديث حالتها إلى «تم التوصيل» أو «تم الارجاع».</p>
                        </div>
                    </div>
                </div>
                <style>
                .hover-shadow { transition: box-shadow 0.2s ease, transform 0.2s ease; }
                .hover-shadow:hover { box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important; transform: translateY(-2px); }
                </style>
            <?php else: ?>
                <div class="container-fluid">
                    <div class="page-header mb-4">
                        <h2><i class="bi bi-speedometer2 me-2"></i>لوحة السائق</h2>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <p class="text-muted mb-0">اختر من القائمة: الحضور والانصراف، مهام الإنتاج (أوردرات مع المندوب)، أو محفظة المستخدم.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

<script>
window.currentUser = {
    id: <?php echo (int)($currentUser['id'] ?? 0); ?>,
    role: '<?php echo htmlspecialchars($currentUser['role'] ?? ''); ?>'
};
</script>

<?php if (!$isAjaxNavigation): ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<?php else: ?>
<?php
$content = ob_get_clean();
if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $content, $matches)) {
    echo $matches[1];
} else {
    echo $content;
}
exit;
?>
<?php endif; ?>

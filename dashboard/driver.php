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

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/path_helper.php';

requireRole(['driver', 'manager', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$page = $_GET['page'] ?? 'attendance';

if ($page === 'attendance') {
    header('Location: ' . getRelativeUrl('attendance.php'));
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
            <?php elseif ($page === 'tasks'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/production/tasks.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة المهام غير متاحة حالياً</div>';
                }
                ?>
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

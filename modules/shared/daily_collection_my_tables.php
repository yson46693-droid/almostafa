<?php
/**
 * جداول التحصيل اليومية - واجهة المستخدم (سائق، مندوب مبيعات، عامل إنتاج)
 * تعرض الجداول المخصصة للمستخدم مع تمييز "تم التحصيل" و "قيد التحصيل" وأزرار إجراءات.
 * لا تؤثر على رصيد الخزنة أو محفظة المستخدم - للتتبع فقط.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['driver', 'sales', 'production', 'manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
$db = db();

// التأكد من وجود الجداول
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'daily_collection_schedules'");
if (empty($tableCheck)) {
    echo '<div class="container-fluid"><div class="alert alert-warning">جداول التحصيل غير مفعّلة بعد. يرجى تشغيل migration أو طلب تفعيلها من المدير.</div></div>';
    return;
}

$today = date('Y-m-d');
$viewDate = isset($_GET['date']) ? date('Y-m-d', strtotime($_GET['date'])) : $today;
$success = '';
$error = '';

// معالجة تسجيل التحصيل أو إلغائه (للمستخدمين المعينين فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['item_id'])) {
    $itemId = (int)$_POST['item_id'];
    $recordDate = isset($_POST['record_date']) ? date('Y-m-d', strtotime($_POST['record_date'])) : $today;
    $action = $_POST['action'];

    $item = $db->queryOne(
        "SELECT si.id, si.schedule_id, si.local_customer_id
         FROM daily_collection_schedule_items si
         INNER JOIN daily_collection_schedule_assignments a ON a.schedule_id = si.schedule_id AND a.user_id = ?
         WHERE si.id = ?",
        [$userId, $itemId]
    );
    if (!$item) {
        $error = 'البند غير موجود أو غير مخصص لك.';
    } else {
        if ($action === 'mark_collected') {
            $existing = $db->queryOne("SELECT id, status FROM daily_collection_daily_records WHERE schedule_item_id = ? AND record_date = ?", [$itemId, $recordDate]);
            if ($existing) {
                $db->execute("UPDATE daily_collection_daily_records SET status = 'collected', collected_at = NOW(), collected_by = ? WHERE id = ?", [$userId, $existing['id']]);
            } else {
                $db->execute(
                    "INSERT INTO daily_collection_daily_records (schedule_item_id, record_date, status, collected_at, collected_by) VALUES (?, ?, 'collected', NOW(), ?)",
                    [$itemId, $recordDate, $userId]
                );
            }
            $success = 'تم تسجيل التحصيل لهذا اليوم.';
        } elseif ($action === 'mark_pending') {
            $db->execute("UPDATE daily_collection_daily_records SET status = 'pending', collected_at = NULL, collected_by = NULL WHERE schedule_item_id = ? AND record_date = ?", [$itemId, $recordDate]);
            $success = 'تم إرجاع الحالة إلى قيد التحصيل.';
        }
    }
    if (!headers_sent() && (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => empty($error), 'message' => $error ?: $success]);
        exit;
    }
}

// الجداول المخصصة للمستخدم الحالي (أو كل الجداول للمدير/المحاسب)
$isControlRole = in_array(strtolower($currentUser['role'] ?? ''), ['manager', 'accountant', 'developer'], true);
if ($isControlRole) {
    $schedules = $db->query(
        "SELECT s.id, s.name FROM daily_collection_schedules s ORDER BY s.name ASC"
    ) ?: [];
} else {
    $schedules = $db->query(
        "SELECT s.id, s.name FROM daily_collection_schedules s
         INNER JOIN daily_collection_schedule_assignments a ON a.schedule_id = s.id AND a.user_id = ?
         ORDER BY s.name ASC",
        [$userId]
    ) ?: [];
}

$schedulesWithItems = [];
foreach ($schedules as $s) {
    $items = $db->query(
        "SELECT si.id AS item_id, si.daily_amount, lc.id AS customer_id, lc.name AS customer_name
         FROM daily_collection_schedule_items si
         LEFT JOIN local_customers lc ON lc.id = si.local_customer_id
         WHERE si.schedule_id = ? ORDER BY si.sort_order, si.id",
        [$s['id']]
    ) ?: [];
    $itemIds = array_column($items, 'item_id');
    $records = [];
    if (!empty($itemIds)) {
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $params = array_merge($itemIds, [$viewDate]);
        $rows = $db->query("SELECT schedule_item_id, status, collected_at FROM daily_collection_daily_records WHERE schedule_item_id IN ($ph) AND record_date = ?", $params);
        foreach ($rows ?: [] as $r) {
            $records[$r['schedule_item_id']] = $r;
        }
    }
    foreach ($items as &$it) {
        $it['record'] = $records[$it['item_id']] ?? null;
        $it['status'] = ($it['record']['status'] ?? 'pending') === 'collected' ? 'collected' : 'pending';
    }
    unset($it);
    $schedulesWithItems[] = ['schedule' => $s, 'items' => $items];
}

$baseUrl = getDashboardUrl();
$dashboardScript = 'driver.php';
if (strpos($_SERVER['PHP_SELF'] ?? '', 'accountant.php') !== false) $dashboardScript = 'accountant.php';
elseif (strpos($_SERVER['PHP_SELF'] ?? '', 'manager.php') !== false) $dashboardScript = 'manager.php';
elseif (strpos($_SERVER['PHP_SELF'] ?? '', 'production.php') !== false) $dashboardScript = 'production.php';
elseif (strpos($_SERVER['PHP_SELF'] ?? '', 'sales.php') !== false) $dashboardScript = 'sales.php';
$pageName = 'daily_collection_my_tables';
?>
<div class="container-fluid">
    <div class="page-header mb-4">
        <h2><i class="bi bi-calendar2-range me-2"></i>جداول التحصيل اليومية</h2>
        <p class="text-muted mb-0">عرض وتحديث حالة التحصيل اليومي (لا يؤثر على الخزنة أو المحفظة)</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <label class="form-label mb-0">التاريخ:</label>
        <input type="date" id="view-date-picker" class="form-control form-control-sm" style="max-width:160px" value="<?php echo htmlspecialchars($viewDate); ?>">
    </div>

    <?php if (empty($schedulesWithItems)): ?>
        <div class="alert alert-info">لا توجد جداول مخصصة لك. تواصل مع المدير أو المحاسب لربطك بجدول تحصيل.</div>
    <?php else: ?>
        <?php foreach ($schedulesWithItems as $row): ?>
            <?php $sch = $row['schedule']; $items = $row['items']; ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-table me-1"></i><?php echo htmlspecialchars($sch['name']); ?></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>العميل</th>
                                    <th>مبلغ التحصيل اليومي</th>
                                    <th>الحالة</th>
                                    <?php if (!$isControlRole): ?>
                                        <th class="text-end">إجراءات</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <tr class="<?php echo $it['status'] === 'collected' ? 'table-success' : ''; ?>">
                                        <td><?php echo htmlspecialchars($it['customer_name'] ?? '—'); ?></td>
                                        <td><?php echo function_exists('formatCurrency') ? formatCurrency($it['daily_amount']) : number_format($it['daily_amount'], 2); ?></td>
                                        <td>
                                            <?php if ($it['status'] === 'collected'): ?>
                                                <span class="badge bg-success">تم التحصيل</span>
                                                <?php if (!empty($it['record']['collected_at'])): ?>
                                                    <small class="text-muted d-block"><?php echo date('H:i', strtotime($it['record']['collected_at'])); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">قيد التحصيل</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if (!$isControlRole): ?>
                                            <td class="text-end">
                                                <form method="post" class="d-inline form-daily-collection-action" data-item="<?php echo $it['item_id']; ?>" data-date="<?php echo $viewDate; ?>">
                                                    <input type="hidden" name="record_date" value="<?php echo $viewDate; ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo $it['item_id']; ?>">
                                                    <?php if ($it['status'] === 'collected'): ?>
                                                        <input type="hidden" name="action" value="mark_pending">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">إلغاء التحصيل</button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="action" value="mark_collected">
                                                        <button type="submit" class="btn btn-sm btn-success">تم التحصيل</button>
                                                    <?php endif; ?>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
(function() {
    var picker = document.getElementById('view-date-picker');
    if (picker) {
        picker.addEventListener('change', function() {
            var url = new URL(window.location.href);
            url.searchParams.set('date', this.value);
            window.location.href = url.toString();
        });
    }
    document.querySelectorAll('.form-daily-collection-action').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (window.location.protocol !== 'file:' && typeof fetch === 'function') {
                e.preventDefault();
                var fd = new FormData(form);
                fetch(window.location.href, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) window.location.reload();
                    else if (data.message) alert(data.message);
                }).catch(function() { form.submit(); });
            }
        });
    });
})();
</script>

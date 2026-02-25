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

// معالجة تسجيل التحصيل أو إلغائه (المدير/المحاسب/المطور يمكنهم تعديل أي بند؛ غيرهم فقط المعينون)
$isControlRole = in_array(strtolower(getCurrentUser()['role'] ?? ''), ['manager', 'accountant', 'developer'], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['item_id'])) {
    $itemId = (int)$_POST['item_id'];
    $recordDate = isset($_POST['record_date']) ? date('Y-m-d', strtotime($_POST['record_date'])) : $today;
    $action = $_POST['action'];

    if ($isControlRole) {
        $item = $db->queryOne(
            "SELECT si.id, si.schedule_id, si.local_customer_id FROM daily_collection_schedule_items si WHERE si.id = ?",
            [$itemId]
        );
    } else {
        $item = $db->queryOne(
            "SELECT si.id, si.schedule_id, si.local_customer_id
             FROM daily_collection_schedule_items si
             INNER JOIN daily_collection_schedule_assignments a ON a.schedule_id = si.schedule_id AND a.user_id = ?
             WHERE si.id = ?",
            [$userId, $itemId]
        );
    }
    if (!$item) {
        $error = $isControlRole ? 'البند غير موجود.' : 'البند غير موجود أو غير مخصص لك.';
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

// فلتر الحالة وفلتر اسم الجدول
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['collected', 'pending'], true) ? $_GET['status'] : 'all';
$scheduleFilter = isset($_GET['schedule_id']) && $_GET['schedule_id'] !== '' ? (int)$_GET['schedule_id'] : null;

// الجداول المخصصة للمستخدم الحالي (أو كل الجداول للمدير/المحاسب)
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
$isControlRole = in_array(strtolower($currentUser['role'] ?? ''), ['manager', 'accountant', 'developer'], true);

// بناء قائمة مسطحة من كل البنود مع اسم الجدول والحالة
$allItems = [];
foreach ($schedules as $s) {
    $sql = "SELECT si.id AS item_id, si.schedule_id, si.daily_amount, lc.id AS customer_id, lc.name AS customer_name
            FROM daily_collection_schedule_items si
            LEFT JOIN local_customers lc ON lc.id = si.local_customer_id
            WHERE si.schedule_id = ? ORDER BY si.sort_order, si.id";
    $items = $db->query($sql, [$s['id']]) ?: [];
    $itemIds = array_column($items, 'item_id');
    $records = [];
    if (!empty($itemIds)) {
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $paramsR = array_merge($itemIds, [$viewDate]);
        $rows = $db->query("SELECT schedule_item_id, status, collected_at FROM daily_collection_daily_records WHERE schedule_item_id IN ($ph) AND record_date = ?", $paramsR);
        foreach ($rows ?: [] as $r) {
            $records[$r['schedule_item_id']] = $r;
        }
    }
    foreach ($items as $it) {
        $rec = $records[$it['item_id']] ?? null;
        $status = ($rec['status'] ?? 'pending') === 'collected' ? 'collected' : 'pending';
        if ($statusFilter !== 'all' && $status !== $statusFilter) continue;
        if ($scheduleFilter !== null && $s['id'] != $scheduleFilter) continue;
        $allItems[] = [
            'schedule_id' => $s['id'],
            'schedule_name' => $s['name'],
            'item_id' => $it['item_id'],
            'customer_name' => $it['customer_name'] ?? '—',
            'daily_amount' => $it['daily_amount'],
            'status' => $status,
            'record' => $rec
        ];
    }
}

// الترقيم (pagination)
$perPage = 15;
$totalItems = count($allItems);
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$totalPages = $totalItems > 0 ? (int)ceil($totalItems / $perPage) : 1;
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$itemsPage = array_slice($allItems, $offset, $perPage);

$queryBase = ['page' => 'daily_collection_my_tables', 'date' => $viewDate];
if ($statusFilter !== 'all') $queryBase['status'] = $statusFilter;
if ($scheduleFilter !== null) $queryBase['schedule_id'] = $scheduleFilter;

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

    <form method="get" action="" id="daily-collection-filters" class="mb-4">
        <input type="hidden" name="page" value="daily_collection_my_tables">
        <div class="row g-2 align-items-end flex-wrap">
            <div class="col-auto">
                <label class="form-label small mb-0">التاريخ</label>
                <input type="date" name="date" class="form-control form-control-sm" style="max-width:160px" value="<?php echo htmlspecialchars($viewDate); ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0">الحالة</label>
                <select name="status" class="form-select form-select-sm" style="max-width:160px">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                    <option value="collected" <?php echo $statusFilter === 'collected' ? 'selected' : ''; ?>>تم التحصيل</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>قيد التحصيل</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0">اسم الجدول</label>
                <select name="schedule_id" class="form-select form-select-sm" style="max-width:200px">
                    <option value="">الكل</option>
                    <?php foreach ($schedules as $sch): ?>
                        <option value="<?php echo (int)$sch['id']; ?>" <?php echo $scheduleFilter === (int)$sch['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sch['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>عرض</button>
            </div>
        </div>
    </form>

    <?php if (empty($schedules)): ?>
        <div class="alert alert-info">لا توجد جداول مخصصة لك. تواصل مع المدير أو المحاسب لربطك بجدول تحصيل.</div>
    <?php elseif ($totalItems === 0): ?>
        <div class="alert alert-info">لا توجد بنود تطابق الفلتر المحدد.</div>
    <?php else: ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="bi bi-table me-1"></i>بنود التحصيل</h5>
                <span class="text-muted small"><?php echo $totalItems; ?> بند</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>الجدول</th>
                                <th>العميل</th>
                                <th>مبلغ التحصيل اليومي</th>
                                <th>الحالة</th>
                                <?php if (!$isControlRole): ?>
                                    <th class="text-end">إجراءات</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemsPage as $it): ?>
                                <tr class="<?php echo $it['status'] === 'collected' ? 'table-success' : ''; ?>">
                                    <td><?php echo htmlspecialchars($it['schedule_name']); ?></td>
                                    <td><?php echo htmlspecialchars($it['customer_name']); ?></td>
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
                                    <td class="text-end">
                                        <?php if ($it['status'] !== 'collected'): ?>
                                        <form method="post" class="d-inline form-daily-collection-action" data-item="<?php echo $it['item_id']; ?>" data-date="<?php echo $viewDate; ?>">
                                            <input type="hidden" name="record_date" value="<?php echo $viewDate; ?>">
                                            <input type="hidden" name="item_id" value="<?php echo $it['item_id']; ?>">
                                            <input type="hidden" name="action" value="mark_collected">
                                            <button type="submit" class="btn btn-sm btn-success">تم التحصيل</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small text-muted">صفحة <?php echo $page; ?> من <?php echo $totalPages; ?></span>
                <nav aria-label="ترقيم البنود">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $q = $queryBase;
                        $qStr = http_build_query($q);
                        $sep = $qStr ? '&' : '';
                        if ($page > 1):
                            $q['p'] = $page - 1;
                        ?>
                        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($q); ?>"><i class="bi bi-chevron-right"></i></a></li>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                            $q['p'] = $i;
                        ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query($q); ?>"><?php echo $i; ?></a></li>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): $q['p'] = $page + 1; ?>
                        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($q); ?>"><i class="bi bi-chevron-left"></i></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
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

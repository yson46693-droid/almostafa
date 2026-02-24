<?php
/**
 * صفحة جداول التحصيل اليومية المتعددة - واجهة التحكم
 * للمدير والمحاسب: إنشاء/تعديل/حذف الجداول، وتحديد من يظهر له كل جدول، وتخصيص التحصيلات المرتبطة بالعملاء.
 * لا تؤثر على رصيد الخزنة أو محفظة المستخدم - للتتبع فقط.
 * ملاحظة: الأخطاء تُسجّل عبر error_log() فقط (إعداد log في php.ini إن لزم).
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

// الصلاحيات يتحقق منها manager.php / accountant.php قبل تضمين هذا الملف - لا نكرر requireRole لتجنب إعادة التوجيه
$currentUser = null;
$db = null;
try {
    $currentUser = getCurrentUser();
    $db = db();
} catch (Throwable $e) {
    error_log('Daily collection schedules init: ' . $e->getMessage());
    echo '<div class="container-fluid"><div class="alert alert-danger">تعذر تحميل الصفحة. تأكد من تسجيل الدخول.</div></div>';
    return;
}

/** التحقق من وجود جدول باستخدام rawQuery (تجنب مشكلة prepared مع SHOW TABLES في MariaDB) */
function dailyCollectionTableExists($db, $tableName) {
    $safe = str_replace("'", "''", $tableName);
    $r = @$db->rawQuery("SHOW TABLES LIKE '" . $safe . "'");
    $exists = $r && ($r instanceof mysqli_result) && $r->num_rows > 0;
    if ($r && $r instanceof mysqli_result) $r->free();
    return $exists;
}

/**
 * التأكد من وجود جداول التحصيل اليومية (إنشاء الناقص منها فقط)
 */
function ensureDailyCollectionTables($db) {
    $tables = ['daily_collection_schedules', 'daily_collection_schedule_items', 'daily_collection_schedule_assignments', 'daily_collection_daily_records'];
    foreach ($tables as $t) {
        if (dailyCollectionTableExists($db, $t)) continue;
        $migrationPath = __DIR__ . '/../../database/migrations/daily_collection_schedules.sql';
        if (!file_exists($migrationPath)) continue;
        $sql = file_get_contents($migrationPath);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '' || strpos($stmt, '--') === 0) continue;
            if (stripos($stmt, 'CREATE TABLE') === false) continue;
            $tableName = null;
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`]?(\w+)[`]?/i', $stmt, $m)) $tableName = $m[1];
            if ($tableName && $tableName !== $t) continue;
            try {
                $db->rawQuery($stmt . ';');
            } catch (Throwable $e) {
                error_log('Daily collection migration (' . $tableName . '): ' . $e->getMessage());
            }
            break;
        }
    }
}

try {
    ensureDailyCollectionTables($db);

    if (!dailyCollectionTableExists($db, 'local_customers')) {
        echo '<div class="container-fluid"><div class="alert alert-warning">جدول العملاء المحليين غير موجود. يرجى استخدام صفحة العملاء المحليين أولاً.</div></div>';
        return;
    }

    foreach (['daily_collection_schedules', 'daily_collection_schedule_items', 'daily_collection_schedule_assignments', 'daily_collection_daily_records'] as $t) {
        if (!dailyCollectionTableExists($db, $t)) {
            echo '<div class="container-fluid"><div class="alert alert-danger">جدول ' . htmlspecialchars($t) . ' غير موجود. يرجى تشغيل ملف <code>database/migrations/daily_collection_schedules.sql</code> من phpMyAdmin أو سطر الأوامر.</div></div>';
            return;
        }
    }
} catch (Throwable $e) {
    error_log('Daily collection schedules load: ' . $e->getMessage());
    echo '<div class="container-fluid"><div class="alert alert-danger">حدث خطأ أثناء تحميل الصفحة. يرجى تشغيل ملف <code>database/migrations/daily_collection_schedules.sql</code> أو مراجعة سجل الأخطاء.</div></div>';
    return;
}

$error = '';
$success = '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$baseUrl = (strpos($_SERVER['PHP_SELF'] ?? '', 'accountant.php') !== false)
    ? (getDashboardUrl() . 'accountant.php')
    : (getDashboardUrl() . 'manager.php');
$pageParam = (strpos($_SERVER['PHP_SELF'] ?? '', 'accountant.php') !== false) ? 'accountant' : 'manager';

// AJAX: بحث العملاء المحليين للإدخال اليدوي (autocomplete)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'search_local_customers') {
    $q = trim($_GET['q'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    if ($q === '') {
        echo json_encode(['success' => true, 'customers' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $list = $db->query(
            "SELECT id, name, phone FROM local_customers WHERE status = 'active' AND (name LIKE ? OR phone LIKE ?) ORDER BY name ASC LIMIT 25",
            [$like, $like]
        );
        echo json_encode(['success' => true, 'customers' => $list ?: []], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'customers' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

try {
if (isset($_SESSION['daily_collection_success'])) {
    $success = $_SESSION['daily_collection_success'];
    unset($_SESSION['daily_collection_success']);
}

// حذف جدول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_schedule') {
    $id = (int)($_POST['schedule_id'] ?? 0);
    if ($id > 0) {
        $schedule = $db->queryOne("SELECT id, name FROM daily_collection_schedules WHERE id = ?", [$id]);
        if ($schedule) {
            try {
                $db->execute("DELETE FROM daily_collection_schedules WHERE id = ?", [$id]);
                if (function_exists('logAudit')) {
                    logAudit($currentUser['id'], 'daily_collection_schedule_deleted', 'daily_collection_schedules', $id, null, ['name' => $schedule['name']]);
                }
                $_SESSION['daily_collection_success'] = 'تم حذف الجدول بنجاح.';
            } catch (Throwable $e) {
                error_log('Delete daily collection schedule: ' . $e->getMessage());
                $error = 'حدث خطأ أثناء الحذف.';
            }
        } else {
            $error = 'الجدول غير موجود.';
        }
    }
    $redirect = $baseUrl . '?page=daily_collection_schedules';
    if (!headers_sent()) { header('Location: ' . $redirect); exit; }
}

// إنشاء أو تحديث جدول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create_schedule', 'update_schedule'], true)) {
    $name = trim($_POST['name'] ?? '');
    $customerIds = isset($_POST['customer_ids']) ? array_filter(array_map('intval', (array)$_POST['customer_ids'])) : [];
    $amounts = isset($_POST['amounts']) ? (array)$_POST['amounts'] : [];
    $assignUserIds = isset($_POST['assign_user_ids']) ? array_filter(array_map('intval', (array)$_POST['assign_user_ids'])) : [];
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);

    if ($name === '') {
        $error = 'يرجى إدخال اسم الجدول.';
    } elseif (empty($customerIds)) {
        $error = 'يرجى اختيار عميل محلي واحد على الأقل.';
    } else {
        try {
            $db->beginTransaction();
            if ($_POST['action'] === 'create_schedule') {
                $db->execute("INSERT INTO daily_collection_schedules (name, created_by) VALUES (?, ?)", [$name, $currentUser['id']]);
                $scheduleId = (int)$db->getLastInsertId();
                if (function_exists('logAudit')) {
                    logAudit($currentUser['id'], 'daily_collection_schedule_created', 'daily_collection_schedules', $scheduleId, null, ['name' => $name]);
                }
            } else {
                if ($scheduleId <= 0) throw new InvalidArgumentException('معرف الجدول غير صحيح.');
                $existing = $db->queryOne("SELECT id FROM daily_collection_schedules WHERE id = ?", [$scheduleId]);
                if (!$existing) throw new InvalidArgumentException('الجدول غير موجود.');
                $db->execute("UPDATE daily_collection_schedules SET name = ?, updated_at = NOW() WHERE id = ?", [$name, $scheduleId]);
                $db->execute("DELETE FROM daily_collection_schedule_items WHERE schedule_id = ?", [$scheduleId]);
                $db->execute("DELETE FROM daily_collection_schedule_assignments WHERE schedule_id = ?", [$scheduleId]);
            }

            $sortOrder = 0;
            foreach ($customerIds as $i => $cid) {
                if ($cid <= 0) continue;
                $amount = isset($amounts[$i]) ? (float)str_replace(',', '', $amounts[$i]) : 0;
                $db->execute(
                    "INSERT INTO daily_collection_schedule_items (schedule_id, local_customer_id, daily_amount, sort_order) VALUES (?, ?, ?, ?)",
                    [$scheduleId, $cid, $amount, $sortOrder++]
                );
            }
            foreach ($assignUserIds as $uid) {
                if ($uid <= 0) continue;
                $db->execute(
                    "INSERT INTO daily_collection_schedule_assignments (schedule_id, user_id, assigned_by) VALUES (?, ?, ?)",
                    [$scheduleId, $uid, $currentUser['id']]
                );
            }
            $db->commit();
            $_SESSION['daily_collection_success'] = ($_POST['action'] === 'create_schedule') ? 'تم إنشاء الجدول بنجاح.' : 'تم تحديث الجدول بنجاح.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Daily collection schedule save: ' . $e->getMessage());
            $error = 'حدث خطأ: ' . ($e->getMessage() ?: 'يرجى المحاولة مرة أخرى.');
        }
    }
    if (empty($error)) {
        $redirect = $baseUrl . '?page=daily_collection_schedules';
        if (!headers_sent()) { header('Location: ' . $redirect); exit; }
    }
}

// قائمة الجداول
$schedules = $db->query(
    "SELECT s.id, s.name, s.created_at, u.full_name AS created_by_name
     FROM daily_collection_schedules s
     LEFT JOIN users u ON u.id = s.created_by
     ORDER BY s.created_at DESC"
) ?: [];

$scheduleIds = array_column($schedules, 'id');
$itemsCount = [];
$assignmentsBySchedule = [];
if (!empty($scheduleIds)) {
    $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
    $counts = $db->query("SELECT schedule_id, COUNT(*) AS cnt FROM daily_collection_schedule_items WHERE schedule_id IN ($placeholders) GROUP BY schedule_id", $scheduleIds);
    foreach ($counts ?: [] as $row) {
        $itemsCount[$row['schedule_id']] = (int)$row['cnt'];
    }
    $assigns = $db->query(
        "SELECT a.schedule_id, a.user_id, u.full_name, u.role
         FROM daily_collection_schedule_assignments a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.schedule_id IN ($placeholders)",
        $scheduleIds
    );
    foreach ($assigns ?: [] as $row) {
        $assignmentsBySchedule[$row['schedule_id']][] = $row;
    }
}

$localCustomers = $db->query("SELECT id, name, phone FROM local_customers WHERE status = 'active' ORDER BY name ASC") ?: [];
$assignableUsers = $db->query(
    "SELECT id, full_name, username, role FROM users WHERE status = 'active' AND role IN ('driver', 'sales', 'production') ORDER BY role, full_name, username"
) ?: [];
$roleLabels = ['driver' => 'سائق', 'sales' => 'مندوب مبيعات', 'production' => 'عامل إنتاج'];

$editSchedule = null;
$editItems = [];
$editAssignments = [];
if ($editId > 0) {
    $editSchedule = $db->queryOne("SELECT id, name FROM daily_collection_schedules WHERE id = ?", [$editId]);
    if ($editSchedule) {
        $editItems = $db->query(
            "SELECT si.id, si.local_customer_id, si.daily_amount, lc.name AS customer_name
             FROM daily_collection_schedule_items si
             LEFT JOIN local_customers lc ON lc.id = si.local_customer_id
             WHERE si.schedule_id = ? ORDER BY si.sort_order, si.id",
            [$editId]
        ) ?: [];
        $editAssignments = $db->query("SELECT user_id FROM daily_collection_schedule_assignments WHERE schedule_id = ?", [$editId]);
        $editAssignments = array_column($editAssignments ?: [], 'user_id');
    } else {
        $editId = 0;
    }
}
} catch (Throwable $e) {
    error_log('Daily collection schedules data: ' . $e->getMessage());
    $error = 'حدث خطأ أثناء تحميل البيانات. يرجى تشغيل ملف database/migrations/daily_collection_schedules.sql أو مراجعة سجل الأخطاء.';
    $schedules = [];
    $itemsCount = [];
    $assignmentsBySchedule = [];
    $localCustomers = [];
    $assignableUsers = [];
    $roleLabels = ['driver' => 'سائق', 'sales' => 'مندوب مبيعات', 'production' => 'عامل إنتاج'];
    $editSchedule = null;
    $editItems = [];
    $editAssignments = [];
}
?>
<div class="container-fluid">
    <div class="page-header mb-4">
        <h2><i class="bi bi-calendar2-range me-2"></i>جداول التحصيل اليومية المتعددة</h2>
        <p class="text-muted mb-0">إنشاء وتعديل جداول تحصيل يومية (لا تؤثر على الخزنة أو المحفظة)</p>
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

    <div class="row">
        <div class="col-12 col-lg-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-1"></i><?php echo $editId ? 'تعديل الجدول' : 'جدول تحصيل جديد'; ?></h5>
                    <?php if ($editId): ?>
                        <a href="<?php echo $baseUrl; ?>?page=daily_collection_schedules" class="btn btn-outline-secondary btn-sm">إلغاء</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="post" action="" id="daily-collection-form" novalidate>
                        <input type="hidden" name="action" value="<?php echo $editId ? 'update_schedule' : 'create_schedule'; ?>">
                        <?php if ($editId): ?><input type="hidden" name="schedule_id" value="<?php echo $editId; ?>"><?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">اسم الجدول <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="200"
                                   value="<?php echo $editSchedule ? htmlspecialchars($editSchedule['name']) : ''; ?>"
                                   placeholder="مثال: جدول تحصيل منطقة أ">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">العملاء المحليون ومبلغ التحصيل اليومي <span class="text-danger">*</span></label>
                            <small class="text-muted d-block mb-1">اكتب اسم العميل أو رقم الهاتف لعرض التطابقات من جدول العملاء المحليين</small>
                            <div id="schedule-items-container" class="border rounded p-2 bg-light">
                                <?php
                                if (!empty($editItems)) {
                                    foreach ($editItems as $idx => $item) {
                                        $custName = $item['customer_name'] ?? '';
                                        $custId = (int)($item['local_customer_id'] ?? 0);
                                        ?>
                                        <div class="row g-2 mb-2 schedule-item-row">
                                            <div class="col-12 col-md-6 position-relative">
                                                <input type="text" class="form-control form-control-sm customer-search-input" placeholder="ابحث أو اكتب اسم العميل..." value="<?php echo htmlspecialchars($custName); ?>" autocomplete="off">
                                                <input type="hidden" name="customer_ids[]" class="customer-id-input" value="<?php echo $custId ?: ''; ?>">
                                                <div class="customer-search-results list-group position-absolute w-100" style="z-index:1050;display:none;max-height:200px;overflow-y:auto;"></div>
                                            </div>
                                            <div class="col-8 col-md-4">
                                                <input type="text" name="amounts[]" class="form-control form-control-sm" placeholder="مبلغ يومي" value="<?php echo htmlspecialchars($item['daily_amount'] ?? ''); ?>">
                                            </div>
                                            <div class="col-4 col-md-2">
                                                <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item" title="حذف"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </div>
                                    <?php }
                                } else {
                                    ?>
                                    <div class="row g-2 mb-2 schedule-item-row">
                                        <div class="col-12 col-md-6 position-relative">
                                            <input type="text" class="form-control form-control-sm customer-search-input" placeholder="ابحث أو اكتب اسم العميل..." autocomplete="off">
                                            <input type="hidden" name="customer_ids[]" class="customer-id-input" value="">
                                            <div class="customer-search-results list-group position-absolute w-100" style="z-index:1050;display:none;max-height:200px;overflow-y:auto;"></div>
                                        </div>
                                        <div class="col-8 col-md-4">
                                            <input type="text" name="amounts[]" class="form-control form-control-sm" placeholder="مبلغ يومي">
                                        </div>
                                        <div class="col-4 col-md-2">
                                            <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item" title="حذف"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-1" id="add-schedule-item"><i class="bi bi-plus me-1"></i>إضافة عميل</button>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">إظهار الجدول للمستخدمين</label>
                            <select name="assign_user_ids[]" class="form-select" multiple size="5">
                                <?php foreach ($assignableUsers as $u): ?>
                                                    <option value="<?php echo $u['id']; ?>" <?php echo in_array($u['id'], $editAssignments) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?> (<?php echo $roleLabels[$u['role']] ?? $u['role']; ?>)</option>
                                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">يمكن اختيار أكثر من مستخدم (سائق، مندوب مبيعات، عامل إنتاج)</small>
                        </div>
                        <button type="submit" class="btn btn-primary"><?php echo $editId ? 'حفظ التعديلات' : 'إنشاء الجدول'; ?></button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-list-ul me-1"></i>الجداول الحالية</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($schedules)): ?>
                        <p class="text-muted p-3 mb-0">لا توجد جداول. أنشئ جدولاً جديداً من النموذج.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>الاسم</th>
                                        <th>عدد العملاء</th>
                                        <th>المُعيّنون</th>
                                        <th class="text-end">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($schedules as $s): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($s['name']); ?></td>
                                            <td><?php echo $itemsCount[$s['id']] ?? 0; ?></td>
                                            <td>
                                                <?php
                                                $assigned = $assignmentsBySchedule[$s['id']] ?? [];
                                                if (empty($assigned)) {
                                                    echo '<span class="text-muted">—</span>';
                                                } else {
                                                    echo implode('، ', array_map(function ($a) use ($roleLabels) {
                                                        return htmlspecialchars($a['full_name'] ?: $a['user_id']) . ' (' . ($roleLabels[$a['role']] ?? $a['role']) . ')';
                                                    }, $assigned));
                                                }
                                                ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="<?php echo $baseUrl; ?>?page=daily_collection_schedules&edit=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-primary me-1" title="تعديل"><i class="bi bi-pencil"></i></a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('حذف هذا الجدول وجميع بنوده وسجلاته؟');">
                                                    <input type="hidden" name="action" value="delete_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $s['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var container = document.getElementById('schedule-items-container');
    var addBtn = document.getElementById('add-schedule-item');
    if (!container || !addBtn) return;

    function buildSearchUrl() {
        var base = (window.location.pathname || '') + (window.location.search || '');
        if (base.indexOf('page=daily_collection_schedules') === -1) base += (base.indexOf('?') >= 0 ? '&' : '?') + 'page=daily_collection_schedules';
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'ajax=search_local_customers';
    }

    var searchUrl = buildSearchUrl();
    var searchTimeout = null;

    function attachAutocomplete(wrap) {
        var input = wrap.querySelector('.customer-search-input');
        var hidden = wrap.querySelector('.customer-id-input');
        var resultsDiv = wrap.querySelector('.customer-search-results');
        if (!input || !hidden || !resultsDiv) return;
        input.addEventListener('input', function() {
            var q = (input.value || '').trim();
            if (q.length < 1) { resultsDiv.style.display = 'none'; resultsDiv.innerHTML = ''; hidden.value = ''; return; }
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                fetch(searchUrl + '&q=' + encodeURIComponent(q)).then(function(r) { return r.json(); }).then(function(data) {
                    resultsDiv.innerHTML = '';
                    if (!data.success || !data.customers || data.customers.length === 0) {
                        resultsDiv.innerHTML = '<div class="list-group-item list-group-item-secondary small">لا توجد تطابقات</div>';
                    } else {
                        data.customers.forEach(function(c) {
                            var el = document.createElement('button');
                            el.type = 'button';
                            el.className = 'list-group-item list-group-item-action list-group-item-light text-start small';
                            el.textContent = (c.name || '') + (c.phone ? ' - ' + c.phone : '');
                            el.addEventListener('click', function() {
                                hidden.value = c.id;
                                input.value = c.name || '';
                                resultsDiv.style.display = 'none';
                                resultsDiv.innerHTML = '';
                            });
                            resultsDiv.appendChild(el);
                        });
                    }
                    resultsDiv.style.display = 'block';
                }).catch(function() { resultsDiv.innerHTML = '<div class="list-group-item list-group-item-danger small">خطأ في البحث</div>'; resultsDiv.style.display = 'block'; });
            }, 250);
        });
        input.addEventListener('focus', function() { if (resultsDiv.innerHTML) resultsDiv.style.display = 'block'; });
        input.addEventListener('blur', function() { setTimeout(function() { resultsDiv.style.display = 'none'; }, 200); });
    }

    container.querySelectorAll('.schedule-item-row').forEach(function(row) {
        var wrap = row.querySelector('.position-relative');
        if (wrap) attachAutocomplete(wrap);
    });

    function addRow() {
        var row = document.createElement('div');
        row.className = 'row g-2 mb-2 schedule-item-row';
        row.innerHTML = '<div class="col-12 col-md-6 position-relative">' +
            '<input type="text" class="form-control form-control-sm customer-search-input" placeholder="ابحث أو اكتب اسم العميل..." autocomplete="off">' +
            '<input type="hidden" name="customer_ids[]" class="customer-id-input" value="">' +
            '<div class="customer-search-results list-group position-absolute w-100" style="z-index:1050;display:none;max-height:200px;overflow-y:auto;"></div></div>' +
            '<div class="col-8 col-md-4"><input type="text" name="amounts[]" class="form-control form-control-sm" placeholder="مبلغ يومي"></div>' +
            '<div class="col-4 col-md-2"><button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item" title="حذف"><i class="bi bi-trash"></i></button></div>';
        container.appendChild(row);
        attachAutocomplete(row.querySelector('.position-relative'));
        row.querySelector('.remove-item').addEventListener('click', function() {
            if (container.querySelectorAll('.schedule-item-row').length <= 1) return;
            row.remove();
        });
    }
    addBtn.addEventListener('click', addRow);
    container.addEventListener('click', function(e) {
        if (e.target.closest('.remove-item') && container.querySelectorAll('.schedule-item-row').length > 1) {
            e.target.closest('.schedule-item-row').remove();
        }
    });

    document.getElementById('daily-collection-form').addEventListener('submit', function(e) {
        var ids = container.querySelectorAll('.customer-id-input');
        var hasOne = false;
        for (var i = 0; i < ids.length; i++) { if (ids[i].value && parseInt(ids[i].value, 10) > 0) { hasOne = true; break; } }
        if (!hasOne) {
            e.preventDefault();
            alert('يرجى اختيار عميل واحد على الأقل من خلال البحث واختيار نتيجة من القائمة.');
        }
    });
})();
</script>

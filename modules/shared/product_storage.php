<?php
/**
 * صفحة تشوين المنتجات - عرض منتجات الشركة ونقلها لمستخدم أو اسم يدوي
 * Product Storage - All roles
 * المدير والمحاسب: نقل لأي مستخدم + استلام المنقول لهم
 * المبيعات والإنتاج: نقل لمستخدم أو اسم يدوي فقط
 */

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole(['manager', 'accountant', 'sales', 'production', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';
$isManagerOrAccountant = in_array(strtolower($currentUser['role'] ?? ''), ['manager', 'accountant', 'developer'], true);

// إنشاء جدول product_transfers إذا لم يكن موجوداً
try {
    $tbl = $db->queryOne("SHOW TABLES LIKE 'product_transfers'");
    if (empty($tbl)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_transfers` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `from_user_id` int(11) NOT NULL,
              `to_type` enum('user','manual') NOT NULL DEFAULT 'user',
              `to_user_id` int(11) DEFAULT NULL,
              `to_manual_name` varchar(100) DEFAULT NULL,
              `transfer_type` enum('external','factory') NOT NULL,
              `product_id` int(11) DEFAULT NULL,
              `batch_id` int(11) DEFAULT NULL,
              `product_name` varchar(255) NOT NULL,
              `quantity` decimal(12,3) NOT NULL DEFAULT 0,
              `unit` varchar(50) DEFAULT 'قطعة',
              `transferred_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `status` enum('pending','received') NOT NULL DEFAULT 'pending',
              `received_at` datetime DEFAULT NULL,
              `received_by` int(11) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `from_user_id` (`from_user_id`),
              KEY `to_user_id` (`to_user_id`),
              KEY `status` (`status`),
              KEY `to_user_status` (`to_user_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) {
    error_log('product_storage: create table error: ' . $e->getMessage());
}

// رسائل من GET
if (!empty($_GET['success'])) {
    $success = trim($_GET['success']);
}
if (!empty($_GET['error'])) {
    $error = trim($_GET['error']);
}

// معالجة النقل (يدعم عدة منتجات في طلب واحد)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'transfer') {
        $recipientType = $_POST['recipient_type'] ?? 'user';
        $toUserId = $recipientType === 'user' ? (int)($_POST['to_user_id'] ?? 0) : null;
        $toManualName = $recipientType === 'manual' ? trim($_POST['to_manual_name'] ?? '') : null;
        $notes = trim($_POST['notes'] ?? '');

        $types = isset($_POST['transfer_type']) && is_array($_POST['transfer_type']) ? $_POST['transfer_type'] : [$_POST['transfer_type'] ?? ''];
        $sources = isset($_POST['product_source_id']) && is_array($_POST['product_source_id']) ? $_POST['product_source_id'] : [$_POST['product_source_id'] ?? ''];
        $quantities = isset($_POST['quantity']) && is_array($_POST['quantity']) ? $_POST['quantity'] : [$_POST['quantity'] ?? 0];

        if ($recipientType === 'user' && $toUserId <= 0) {
            $error = 'يرجى اختيار المستخدم المستقبل.';
        } elseif ($recipientType === 'manual' && $toManualName === '') {
            $error = 'يرجى إدخال اسم المستقبل.';
        } else {
            $inserted = 0;
            $maxRows = max(count($types), count($sources), count($quantities));
            for ($i = 0; $i < $maxRows; $i++) {
                $transferType = $types[$i] ?? '';
                $productSourceRaw = $sources[$i] ?? '';
                $quantity = (float)($quantities[$i] ?? 0);
                if (!in_array($transferType, ['external', 'factory'], true) || $quantity <= 0) continue;

                $productName = '';
                $unit = 'قطعة';
                $productId = null;
                $batchId = null;

                try {
                    if ($transferType === 'external') {
                        $productSourceId = (int)$productSourceRaw;
                        if ($productSourceId <= 0) continue;
                        $row = $db->queryOne(
                            "SELECT id, name, COALESCE(unit, 'قطعة') AS unit FROM products WHERE id = ? AND (product_type = 'external' OR product_type IS NULL) AND status = 'active'",
                            [$productSourceId]
                        );
                        if (!$row) continue;
                        $productName = $row['name'];
                        $unit = $row['unit'];
                        $productId = (int)$row['id'];
                    } else {
                        if (strpos((string)$productSourceRaw, 'internal_') === 0) {
                            $productSourceId = (int)substr($productSourceRaw, 9);
                            if ($productSourceId <= 0) continue;
                            $row = $db->queryOne(
                                "SELECT id, name, COALESCE(unit, 'قطعة') AS unit FROM products WHERE id = ? AND status = 'active'",
                                [$productSourceId]
                            );
                            if (!$row) continue;
                            $productName = $row['name'];
                            $unit = $row['unit'];
                            $productId = (int)$row['id'];
                            $batchId = null;
                        } else {
                            $batchIdRaw = (string)$productSourceRaw;
                            if (strpos($batchIdRaw, 'batch_') === 0) {
                                $productSourceId = (int)substr($batchIdRaw, 6);
                            } else {
                                $productSourceId = (int)$batchIdRaw;
                            }
                            if ($productSourceId <= 0) continue;
                            $fp = $db->queryOne(
                                "SELECT fp.id, fp.batch_number,
                                        COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'منتج مصنع') AS product_name,
                                        COALESCE(fp.product_id, bn.product_id) AS product_id
                                 FROM finished_products fp
                                 LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                                 LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                                 WHERE fp.id = ? AND (fp.quantity_produced > 0)",
                                [$productSourceId]
                            );
                            if (!$fp) continue;
                            $productName = $fp['product_name'] ?: ('دفعة ' . ($fp['batch_number'] ?? ''));
                            $unit = 'قطعة';
                            $batchId = (int)$fp['id'];
                            $productId = !empty($fp['product_id']) ? (int)$fp['product_id'] : null;
                        }
                    }

                    if ($productName !== '') {
                        $db->execute(
                            "INSERT INTO product_transfers (from_user_id, to_type, to_user_id, to_manual_name, transfer_type, product_id, batch_id, product_name, quantity, unit, notes)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $currentUser['id'],
                                $recipientType,
                                $toUserId ?: null,
                                $toManualName ?: null,
                                $transferType,
                                $productId,
                                $batchId,
                                $productName,
                                $quantity,
                                $unit,
                                $notes ?: null
                            ]
                        );
                        $inserted++;
                        logAudit($currentUser['id'], 'product_transfer', 'product_transfer', $db->getLastInsertId(), null, [
                            'to_type' => $recipientType,
                            'product_name' => $productName,
                            'quantity' => $quantity
                        ]);
                    }
                } catch (Exception $e) {
                    error_log('product_storage transfer row: ' . $e->getMessage());
                }
            }
            if ($inserted > 0) {
                preventDuplicateSubmission(
                    $inserted === 1 ? 'تم تسجيل نقل المنتج بنجاح.' : 'تم تسجيل نقل ' . $inserted . ' منتج بنجاح.',
                    ['page' => 'product_storage'],
                    null,
                    $currentUser['role'],
                    null
                );
            } elseif ($error === '') {
                $error = 'يرجى إضافة منتج واحد على الأقل بكمية صحيحة.';
            }
        }
        if ($error !== '') {
            preventDuplicateSubmission(null, ['page' => 'product_storage'], null, $currentUser['role'], $error);
        }
    } elseif ($action === 'receive' && $isManagerOrAccountant) {
        $transferId = (int)($_POST['transfer_id'] ?? 0);
        if ($transferId <= 0) {
            preventDuplicateSubmission(null, ['page' => 'product_storage'], null, $currentUser['role'], 'معرف النقل غير صالح.');
        }
        $row = $db->queryOne("SELECT id, to_user_id, status FROM product_transfers WHERE id = ?", [$transferId]);
        if (!$row || (int)$row['to_user_id'] !== (int)$currentUser['id']) {
            preventDuplicateSubmission(null, ['page' => 'product_storage'], null, $currentUser['role'], 'غير مصرح باستلام هذه النقلة.');
        }
        if ($row['status'] === 'received') {
            preventDuplicateSubmission(null, ['page' => 'product_storage'], null, $currentUser['role'], 'تم استلام هذه النقلة مسبقاً.');
        }
        try {
            $db->execute(
                "UPDATE product_transfers SET status = 'received', received_at = NOW(), received_by = ? WHERE id = ?",
                [$currentUser['id'], $transferId]
            );
            logAudit($currentUser['id'], 'product_transfer_receive', 'product_transfer', $transferId, null, []);
            preventDuplicateSubmission(
                'تم استلام المنتجات بنجاح.',
                ['page' => 'product_storage'],
                null,
                $currentUser['role'],
                null
            );
        } catch (Exception $e) {
            error_log('product_storage receive: ' . $e->getMessage());
            preventDuplicateSubmission(null, ['page' => 'product_storage'], null, $currentUser['role'], 'تعذر تسجيل الاستلام.');
        }
    }
}

// قائمة المستخدمين (الكل ما عدا الحالي للاختيار)
$usersList = [];
try {
    $usersList = $db->query(
        "SELECT id, full_name, username FROM users WHERE status = 'active' AND id != ? ORDER BY full_name, username",
        [$currentUser['id']]
    );
} catch (Exception $e) {
    error_log('product_storage users: ' . $e->getMessage());
}

// منتجات خارجية (كمية أكبر من صفر فقط)
$externalProducts = [];
try {
    $externalProducts = $db->query("
        SELECT id, name, quantity, COALESCE(unit, 'قطعة') AS unit
        FROM products
        WHERE (product_type = 'external' OR product_type IS NULL) AND status = 'active'
          AND (quantity > 0)
        ORDER BY name ASC
    ");
} catch (Exception $e) {
    error_log('product_storage external: ' . $e->getMessage());
}

// دفعات المصنع (منتجات نهائية) - نفس منطق صفحة منتجات الشركة مع batch_numbers
$factoryBatches = [];
try {
    $fpExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    if ($fpExists) {
        $orderColumn = 'fp.id';
        try {
            $col = $db->queryOne("SHOW COLUMNS FROM finished_products LIKE 'production_date'");
            if (!empty($col)) {
                $orderColumn = 'fp.production_date DESC, fp.id';
            }
        } catch (Exception $e) { /* استخدم id فقط */ }
        $factoryBatches = $db->query("
            SELECT fp.id,
                   fp.batch_number,
                   fp.quantity_produced,
                   COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'منتج مصنع') AS product_name
            FROM finished_products fp
            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
            LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
            WHERE (fp.quantity_produced > 0)
            ORDER BY " . $orderColumn . " DESC
            LIMIT 500
        ");
    }
} catch (Exception $e) {
    error_log('product_storage factory: ' . $e->getMessage());
}

// منتجات المصنع المضافة يدوياً (كمية أكبر من صفر فقط)
$internalProducts = [];
try {
    $col = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
    if (!empty($col)) {
        $internalProducts = $db->query("
            SELECT id, name, quantity, COALESCE(unit, 'قطعة') AS unit
            FROM products
            WHERE product_type = 'internal' AND status = 'active'
              AND (quantity > 0)
            ORDER BY name ASC
        ");
    }
} catch (Exception $e) {
    error_log('product_storage internal: ' . $e->getMessage());
}

// قائمة موحدة لعرض "منتجات المصنع" (دفعات + مضافة يدوياً) في التبويب
$factoryProducts = [];
foreach ($factoryBatches as $fb) {
    $factoryProducts[] = [
        'id' => $fb['id'],
        'batch_number' => $fb['batch_number'] ?? '',
        'quantity_produced' => $fb['quantity_produced'] ?? '',
        'product_name' => $fb['product_name'] ?? '',
        'source' => 'batch'
    ];
}
foreach ($internalProducts as $ip) {
    $factoryProducts[] = [
        'id' => 'internal_' . $ip['id'],
        'batch_number' => '',
        'quantity_produced' => $ip['quantity'],
        'product_name' => $ip['name'],
        'source' => 'internal'
    ];
}

// نقول معلقة للمدير/المحاسب (المنقولة لهم)
$pendingToMe = [];
if ($isManagerOrAccountant) {
    try {
        $pendingToMe = $db->query("
            SELECT t.id, t.product_name, t.quantity, t.unit, t.transferred_at, t.notes,
                   u.full_name AS from_name, u.username AS from_username
            FROM product_transfers t
            LEFT JOIN users u ON t.from_user_id = u.id
            WHERE t.to_user_id = ? AND t.to_type = 'user' AND t.status = 'pending'
            ORDER BY t.transferred_at DESC
        ", [$currentUser['id']]);
    } catch (Exception $e) {
        error_log('product_storage pending: ' . $e->getMessage());
    }
}

?>
<div class="product-storage-page container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h2 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>تشوين المنتجات</h2>
        <a href="?page=transferred_to_me" class="btn btn-outline-primary">
            <i class="bi bi-inbox me-1"></i>المنتجات المنقولة لي
        </a>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- نموذج النقل (أكثر من منتج) -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>نقل منتجات</h5>
        </div>
        <div class="card-body">
            <form method="post" action="" id="transferForm">
                <input type="hidden" name="action" value="transfer" />
                <div class="table-responsive mb-3">
                    <table class="table table-bordered align-middle" id="transferRowsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:22%">نوع المنتج</th>
                                <th style="width:30%">المنتج / الدفعة</th>
                                <th style="width:15%">الكمية</th>
                                <th style="width:8%"></th>
                            </tr>
                        </thead>
                        <tbody id="transferRowsBody">
                            <tr class="transfer-row">
                                <td>
                                    <select name="transfer_type[]" class="form-select form-select-sm row-transfer-type">
                                        <option value="">-- اختر --</option>
                                        <option value="external">منتج خارجي</option>
                                        <option value="factory">دفعة مصنع / منتج مصنع</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="product_source_id[]" class="form-select form-select-sm row-product-select">
                                        <option value="">-- اختر نوع المنتج أولاً --</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control form-control-sm" step="0.001" min="0.001" placeholder="0" />
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-danger btn-sm row-remove" title="حذف الصف" disabled><i class="bi bi-dash-lg"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mb-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addTransferRow"><i class="bi bi-plus-lg me-1"></i>إضافة منتج</button>
                </div>
                <hr />
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">المستلم</label>
                        <select name="recipient_type" id="recipient_type" class="form-select">
                            <option value="user">مستخدم في النظام</option>
                            <option value="manual">اسم يدوي</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="wrap_to_user">
                        <label class="form-label">المستخدم</label>
                        <select name="to_user_id" id="to_user_id" class="form-select">
                            <option value="">-- اختر --</option>
                            <?php foreach ($usersList as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-none" id="wrap_manual_name">
                        <label class="form-label">الاسم</label>
                        <input type="text" name="to_manual_name" id="to_manual_name" class="form-control" placeholder="الاسم يدوياً" />
                    </div>
                    <div class="col-12">
                        <label class="form-label">ملاحظات (اختياري)</label>
                        <input type="text" name="notes" class="form-control" placeholder="ملاحظات" />
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>تسجيل النقل</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($isManagerOrAccountant && !empty($pendingToMe)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="bi bi-inbox me-2"></i>منتجات منقولة إليك (بانتظار الاستلام)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
                            <th>من</th>
                            <th>التاريخ</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingToMe as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($r['unit']); ?></td>
                            <td><?php echo htmlspecialchars($r['from_name'] ?: $r['from_username'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['transferred_at']); ?></td>
                            <td>
                                <form method="post" class="d-inline" onsubmit="return confirm('تأكيد استلام المنتجات؟');">
                                    <input type="hidden" name="action" value="receive" />
                                    <input type="hidden" name="transfer_id" value="<?php echo (int)$r['id']; ?>" />
                                    <button type="submit" class="btn btn-sm btn-success">استلام</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- قائمة منتجات الشركة (عرض فقط) -->
    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><i class="bi bi-box-seam me-2"></i>منتجات الشركة (للاطلاع)</h5>
        </div>
        <div class="card-body p-0">
            <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-external">منتجات خارجية</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-factory">دفعات المصنع</button>
                </li>
            </ul>
            <div class="tab-content p-3">
                <div class="tab-pane fade show active" id="tab-external">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead><tr><th>المنتج</th><th>الكمية</th><th>الوحدة</th></tr></thead>
                            <tbody>
                                <?php foreach ($externalProducts as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td><?php echo htmlspecialchars($p['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($p['unit']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($externalProducts)): ?>
                                <tr><td colspan="3" class="text-muted">لا توجد منتجات خارجية.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-factory">
                    <p class="text-muted small mb-2">دفعات المصنع + المنتجات المضافة يدوياً لمنتجات المصنع</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead><tr><th>الدفعة / النوع</th><th>المنتج</th><th>الكمية</th></tr></thead>
                            <tbody>
                                <?php foreach ($factoryProducts as $p):
                                    $batchNum = trim($p['batch_number'] ?? '');
                                    $prodName = trim($p['product_name'] ?? '');
                                    if ($prodName === '' && $batchNum === '') $prodName = 'دفعة #' . (is_numeric($p['id'] ?? '') ? $p['id'] : '');
                                    $typeLabel = (!empty($p['source']) && $p['source'] === 'internal') ? 'مضاف يدوياً' : ($batchNum ?: '—');
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($typeLabel); ?></td>
                                    <td><?php echo htmlspecialchars($prodName ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($p['quantity_produced'] ?? '—'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($factoryProducts)): ?>
                                <tr><td colspan="3" class="text-muted">لا توجد دفعات أو منتجات مصنع.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var externalData = <?php echo json_encode(array_map(function($p) { return ['id' => (int)$p['id'], 'name' => $p['name'], 'unit' => $p['unit']]; }, $externalProducts)); ?>;
    var factoryData = <?php
        $fd = [];
        foreach ($factoryBatches as $p) {
            $batch = trim($p['batch_number'] ?? '');
            $name = trim($p['product_name'] ?? '');
            $label = $batch !== '' ? $batch . ' - ' . $name : $name;
            if ($label === '') $label = 'دفعة #' . (int)($p['id']);
            $fd[] = ['id' => 'batch_' . (int)$p['id'], 'name' => $label];
        }
        foreach ($internalProducts as $p) {
            $fd[] = ['id' => 'internal_' . (int)$p['id'], 'name' => $p['name']];
        }
        echo json_encode($fd);
    ?>;

    function fillRowProductSelect(selectEl, type) {
        if (!selectEl) return;
        selectEl.innerHTML = '<option value="">-- اختر --</option>';
        if (type === 'external') {
            externalData.forEach(function(p) { selectEl.innerHTML += '<option value="' + p.id + '">' + (p.name || '') + '</option>'; });
        } else if (type === 'factory') {
            factoryData.forEach(function(p) { selectEl.innerHTML += '<option value="' + p.id + '">' + (p.name || '') + '</option>'; });
        }
    }

    function onRowTypeChange(row) {
        var typeSelect = row.querySelector('.row-transfer-type');
        var productSelect = row.querySelector('.row-product-select');
        if (typeSelect && productSelect) fillRowProductSelect(productSelect, typeSelect.value);
    }

    function updateRemoveButtons() {
        var rows = document.querySelectorAll('#transferRowsBody .transfer-row');
        rows.forEach(function(row, i) {
            var btn = row.querySelector('.row-remove');
            if (btn) btn.disabled = rows.length <= 1;
        });
    }

    document.getElementById('addTransferRow').addEventListener('click', function() {
        var tbody = document.getElementById('transferRowsBody');
        var firstRow = tbody.querySelector('.transfer-row');
        if (!firstRow) return;
        var clone = firstRow.cloneNode(true);
        clone.querySelector('.row-transfer-type').value = '';
        clone.querySelector('.row-product-select').innerHTML = '<option value="">-- اختر نوع المنتج أولاً --</option>';
        clone.querySelector('.row-product-select').value = '';
        clone.querySelector('input[name="quantity[]"]').value = '';
        tbody.appendChild(clone);
        updateRemoveButtons();
    });

    document.getElementById('transferRowsBody').addEventListener('change', function(e) {
        if (e.target.classList.contains('row-transfer-type')) {
            onRowTypeChange(e.target.closest('.transfer-row'));
        }
    });

    document.getElementById('transferRowsBody').addEventListener('click', function(e) {
        if (e.target.closest('.row-remove')) {
            var row = e.target.closest('.transfer-row');
            if (row && document.querySelectorAll('#transferRowsBody .transfer-row').length > 1) {
                row.remove();
                updateRemoveButtons();
            }
        }
    });

    var recipientType = document.getElementById('recipient_type');
    var wrapUser = document.getElementById('wrap_to_user');
    var wrapManual = document.getElementById('wrap_manual_name');
    var toUserId = document.getElementById('to_user_id');
    var toManualName = document.getElementById('to_manual_name');
    function toggleRecipient() {
        var isUser = recipientType.value === 'user';
        wrapUser.classList.toggle('d-none', !isUser);
        wrapManual.classList.toggle('d-none', isUser);
        toUserId.required = isUser;
        toManualName.required = !isUser;
    }
    recipientType.addEventListener('change', toggleRecipient);
    toggleRecipient();
    updateRemoveButtons();
})();
</script>

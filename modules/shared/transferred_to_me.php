<?php
/**
 * صفحة المنتجات المنقولة لي - عرض فقط
 * Transferred to Me - View only (all roles)
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

requireRole(['manager', 'accountant', 'sales', 'production', 'developer']);

$currentUser = getCurrentUser();
$db = db();

$transfers = [];
try {
    $tbl = $db->queryOne("SHOW TABLES LIKE 'product_transfers'");
    if ($tbl) {
        $transfers = $db->query("
            SELECT t.id, t.product_name, t.quantity, t.unit, t.transferred_at, t.status, t.received_at, t.notes,
                   u.full_name AS from_name, u.username AS from_username
            FROM product_transfers t
            LEFT JOIN users u ON t.from_user_id = u.id
            WHERE t.to_user_id = ? AND t.to_type = 'user'
            ORDER BY t.transferred_at DESC
        ", [$currentUser['id']]);
    }
} catch (Exception $e) {
    error_log('transferred_to_me: ' . $e->getMessage());
}
?>
<div class="transferred-to-me-page container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h2 class="h4 mb-0"><i class="bi bi-inbox me-2"></i>المنتجات المنقولة لي</h2>
        <a href="?page=product_storage" class="btn btn-outline-primary">
            <i class="bi bi-box-seam me-1"></i>تشوين المنتجات
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
                            <th>من</th>
                            <th>تاريخ النقل</th>
                            <th>الحالة</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($r['unit']); ?></td>
                            <td><?php echo htmlspecialchars($r['from_name'] ?: $r['from_username'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['transferred_at']); ?></td>
                            <td>
                                <?php if (($r['status'] ?? '') === 'received'): ?>
                                    <span class="badge bg-success">تم الاستلام</span>
                                    <?php if (!empty($r['received_at'])): ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($r['received_at']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">بانتظار الاستلام</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($r['notes'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($transfers)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">لا توجد منتجات منقولة إليك.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

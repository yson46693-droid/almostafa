<?php
/**
 * صفحة محفظة المستخدم
 * للسائق وعامل الإنتاج: إضافة مبالغ، عرض الرصيد وسجل المعاملات
 */

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

if (!defined('CONFIG_LOADED')) {
    require_once __DIR__ . '/../../includes/config.php';
}
if (!function_exists('db')) {
    require_once __DIR__ . '/../../includes/db.php';
}
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/../../includes/auth.php';
}
if (!function_exists('logAudit')) {
    require_once __DIR__ . '/../../includes/audit_log.php';
}
if (!function_exists('getRelativeUrl')) {
    require_once __DIR__ . '/../../includes/path_helper.php';
}

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    requireLogin();
}

$currentUser = getCurrentUser();
if (!$currentUser || !is_array($currentUser) || empty($currentUser['id'])) {
    $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
    if (!headers_sent()) {
        header('Location: ' . $loginUrl);
        exit;
    }
}

// الصلاحية: سائق أو عامل إنتاج فقط
$role = strtolower($currentUser['role'] ?? '');
if (!in_array($role, ['driver', 'production'], true)) {
    echo '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>هذه الصفحة متاحة للسائق وعامل الإنتاج فقط.</div>';
    return;
}

$db = db();

// التأكد من وجود جدول المحفظة
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'user_wallet_transactions'");
if (empty($tableCheck)) {
    echo '<div class="alert alert-warning">جدول محفظة المستخدم غير متوفر. يرجى تشغيل ملف <code>database/migrations/add_user_wallet_tables.php</code></div>';
    return;
}

$error = '';
$success = '';

/**
 * حساب رصيد المحفظة للمستخدم
 */
function getWalletBalance($db, $userId) {
    $credits = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM user_wallet_transactions WHERE user_id = ? AND type IN ('deposit', 'custody_add')",
        [$userId]
    );
    $debits = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM user_wallet_transactions WHERE user_id = ? AND type IN ('withdrawal', 'custody_retrieve')",
        [$userId]
    );
    return (float)($credits['total'] ?? 0) - (float)($debits['total'] ?? 0);
}

// معالجة إضافة مبلغ عام
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_deposit') {
    $amount = isset($_POST['amount']) ? (float) str_replace(',', '', $_POST['amount']) : 0;
    $reason = trim($_POST['reason'] ?? '');

    if ($amount <= 0) {
        $error = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
    } elseif (empty($reason)) {
        $error = 'يرجى ذكر سبب الإضافة إلى المحفظة.';
    } else {
        try {
            $db->execute(
                "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'deposit', ?, ?, ?)",
                [$currentUser['id'], $amount, $reason, $currentUser['id']]
            );
            logAudit($currentUser['id'], 'wallet_deposit', 'user_wallet_transactions', $db->getLastInsertId(), null, ['amount' => $amount, 'reason' => $reason]);
            $success = 'تم إضافة ' . formatCurrency($amount) . ' إلى محفظتك بنجاح.';
        } catch (Throwable $e) {
            error_log('Wallet deposit failed: ' . $e->getMessage());
            $error = 'حدث خطأ أثناء الإضافة. يرجى المحاولة مرة أخرى.';
        }
    }
}

// معالجة تحصيل من أوردر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_order_collection') {
    $amount = isset($_POST['order_amount']) ? (float) str_replace(',', '', $_POST['order_amount']) : 0;
    $orderNumber = trim($_POST['order_number'] ?? '');

    if ($amount <= 0) {
        $error = 'يرجى إدخال مبلغ التحصيل صحيح أكبر من الصفر.';
    } elseif (empty($orderNumber)) {
        $error = 'يرجى إدخال رقم الأوردر.';
    } else {
        $reason = 'تحصيل من أوردر #' . $orderNumber;
        try {
            $db->execute(
                "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'deposit', ?, ?, ?)",
                [$currentUser['id'], $amount, $reason, $currentUser['id']]
            );
            logAudit($currentUser['id'], 'wallet_order_collection', 'user_wallet_transactions', $db->getLastInsertId(), null, ['amount' => $amount, 'order_number' => $orderNumber]);
            $success = 'تم إضافة تحصيل ' . formatCurrency($amount) . ' من أوردر #' . htmlspecialchars($orderNumber) . ' إلى محفظتك بنجاح.';
        } catch (Throwable $e) {
            error_log('Wallet order collection failed: ' . $e->getMessage());
            $error = 'حدث خطأ أثناء الإضافة. يرجى المحاولة مرة أخرى.';
        }
    }
}

$balance = getWalletBalance($db, $currentUser['id']);

// جلب سجل المعاملات
$transactions = $db->query(
    "SELECT t.*, u.full_name as created_by_name
     FROM user_wallet_transactions t
     LEFT JOIN users u ON u.id = t.created_by
     WHERE t.user_id = ?
     ORDER BY t.created_at DESC
     LIMIT 100",
    [$currentUser['id']]
) ?: [];

$typeLabels = [
    'deposit' => 'إيداع',
    'withdrawal' => 'سحب',
    'custody_add' => 'عهدة',
    'custody_retrieve' => 'استرجاع عهدة'
];
?>
<div class="container-fluid">
    <div class="page-header mb-4">
        <h2><i class="bi bi-wallet2 me-2"></i>محفظة المستخدم</h2>
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
        <div class="col-12 col-lg-4 mb-4">
            <div class="card shadow-sm h-100 border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-cash-stack me-2"></i>رصيد المحفظة</h5>
                </div>
                <div class="card-body text-center py-4">
                    <p class="display-5 fw-bold text-primary mb-0"><?php echo formatCurrency($balance); ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-8 mb-4">
            <!-- بطاقة إضافة مبلغ عام -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-plus-circle me-2"></i>إضافة مبلغ للمحفظة
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="add_deposit">
                        <div class="col-12 col-md-4">
                            <label for="walletAmount" class="form-label">المبلغ <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">ج.م</span>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="walletAmount" name="amount" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="walletReason" class="form-label">سبب الإضافة <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="walletReason" name="reason" required placeholder="مثال: مبلغ تسليم - توصيل طلب #123">
                        </div>
                        <div class="col-12 col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-plus-lg me-1"></i>إضافة
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- بطاقة تحصيل من أوردر -->
            <div class="card shadow-sm">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-cart-check me-2"></i>تحصيل من أوردر
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="add_order_collection">
                        <div class="col-12 col-md-4">
                            <label for="orderAmount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">ج.م</span>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="orderAmount" name="order_amount" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="orderNumber" class="form-label">رقم الأوردر <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="orderNumber" name="order_number" required placeholder="أدخل رقم الأوردر يدوياً">
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-cash-coin me-1"></i>إضافة التحصيل
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-light fw-bold">
            <i class="bi bi-journal-text me-2"></i>سجل المعاملات
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>التاريخ</th>
                            <th>النوع</th>
                            <th>المبلغ</th>
                            <th>السبب / الوصف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">لا توجد معاملات بعد</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i', strtotime($t['created_at'])); ?></td>
                                    <td><span class="badge bg-<?php echo in_array($t['type'], ['deposit', 'custody_add']) ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($typeLabels[$t['type']] ?? $t['type']); ?></span></td>
                                    <td class="fw-bold <?php echo in_array($t['type'], ['deposit', 'custody_add']) ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo in_array($t['type'], ['deposit', 'custody_add']) ? '+' : '-'; ?><?php echo formatCurrency($t['amount']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($t['reason'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

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

$collectionRequestsTableExists = $db->queryOne("SHOW TABLES LIKE 'user_wallet_local_collection_requests'");
$localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");

// قائمة العملاء المحليين للبحث (نفس الاستعلام كما في الأسعار المخصصة / مهام الإنتاج)
$localCustomersForWallet = [];
if (!empty($localCustomersTableExists)) {
    try {
        $rows = $db->query("SELECT id, name, COALESCE(balance, 0) AS balance FROM local_customers WHERE status = 'active' ORDER BY name ASC");
        foreach ($rows as $r) {
            $localCustomersForWallet[] = [
                'id' => (int)$r['id'],
                'name' => trim((string)($r['name'] ?? '')),
                'balance' => (float)($r['balance'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        error_log('user_wallet local_customers: ' . $e->getMessage());
    }
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

// معالجة طلب تحصيل من عميل محلي (في انتظار موافقة المحاسب/المدير)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_local_collection' && !empty($collectionRequestsTableExists)) {
    $customerId = isset($_POST['local_customer_id']) ? (int)$_POST['local_customer_id'] : 0;
    $customerName = trim($_POST['local_customer_name'] ?? '');
    $amount = isset($_POST['collection_amount']) ? (float) str_replace(',', '', $_POST['collection_amount']) : 0;

    if ($customerId <= 0 || $customerName === '') {
        $error = 'يرجى اختيار العميل من نتائج البحث.';
    } elseif ($amount <= 0) {
        $error = 'يرجى إدخال مبلغ التحصيل صحيح أكبر من الصفر.';
    } else {
        try {
            $db->execute(
                "INSERT INTO user_wallet_local_collection_requests (user_id, local_customer_id, customer_name, amount, status) VALUES (?, ?, ?, ?, 'pending')",
                [$currentUser['id'], $customerId, $customerName, $amount]
            );
            $requestId = $db->getLastInsertId();
            if (function_exists('logAudit')) {
                logAudit($currentUser['id'], 'wallet_local_collection_request', 'user_wallet_local_collection_requests', $requestId, null, ['local_customer_id' => $customerId, 'amount' => $amount]);
            }
            $success = 'تم تسجيل طلب التحصيل (' . formatCurrency($amount) . ' من ' . htmlspecialchars($customerName) . ') في انتظار موافقة المحاسب أو المدير.';
        } catch (Throwable $e) {
            error_log('Wallet local collection request failed: ' . $e->getMessage());
            $error = 'حدث خطأ أثناء تسجيل الطلب. يرجى المحاولة مرة أخرى.';
        }
    }
}

$balance = getWalletBalance($db, $currentUser['id']);

// طلبات التحصيل من العملاء المحليين (في انتظار الموافقة) للمستخدم الحالي
$pendingLocalCollectionRequests = [];
if (!empty($collectionRequestsTableExists)) {
    $pendingLocalCollectionRequests = $db->query(
        "SELECT * FROM user_wallet_local_collection_requests WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 20",
        [$currentUser['id']]
    ) ?: [];
}

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
            <div class="card shadow-sm mb-4">
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
            <?php if (!empty($collectionRequestsTableExists) && !empty($localCustomersTableExists)): ?>
            <!-- بطاقة تحصيل من عميل محلي (نفس خانة البحث كما في الأسعار المخصصة / مهام الإنتاج) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-person-lines-fill me-2"></i>تحصيل من عميل محلي
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">اختر العميل من نتائج البحث، ثم أدخل مبلغ التحصيل. يُسجّل الطلب في انتظار موافقة المحاسب أو المدير.</p>
                    <form method="POST" id="localCollectionForm">
                        <input type="hidden" name="action" value="submit_local_collection">
                        <input type="hidden" name="local_customer_id" id="wallet_local_customer_id" value="">
                        <input type="hidden" name="local_customer_name" id="wallet_local_customer_name" value="">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label small">ابحث عن العميل المحلي <span class="text-danger">*</span></label>
                                <div class="search-wrap position-relative">
                                    <input type="text" id="wallet_local_customer_search" class="form-control form-control-sm" placeholder="اكتب للبحث..." autocomplete="off">
                                    <div id="wallet_local_customer_dropdown" class="search-dropdown-wallet d-none"></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-2">
                                <label class="form-label small">رصيد العميل</label>
                                <div class="form-control form-control-sm bg-light fw-bold small py-2" id="wallet_customer_balance_display">-</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="wallet_collection_amount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="number" step="0.01" min="0.01" class="form-control form-control-lg" id="wallet_collection_amount" name="collection_amount" required placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-12 col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-send-check me-1"></i>تسجيل الطلب
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php if (!empty($pendingLocalCollectionRequests)): ?>
            <div class="card shadow-sm mb-4 border-warning">
                <div class="card-header bg-warning bg-opacity-25 fw-bold">
                    <i class="bi bi-hourglass-split me-2"></i>طلبات التحصيل في انتظار الموافقة
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingLocalCollectionRequests as $req): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i', strtotime($req['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($req['customer_name']); ?></td>
                                    <td class="fw-bold"><?php echo formatCurrency($req['amount']); ?></td>
                                    <td><span class="badge bg-warning text-dark">في انتظار الموافقة</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
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
<?php if (!empty($collectionRequestsTableExists) && !empty($localCustomersTableExists) && !empty($localCustomersForWallet)): ?>
<style>
.search-wrap.position-relative { position: relative; }
.search-dropdown-wallet { position: absolute; left: 0; right: 0; top: 100%; z-index: 1050; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin-top: 2px; }
.search-dropdown-wallet .search-dropdown-item-wallet { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
.search-dropdown-wallet .search-dropdown-item-wallet:hover { background: #f8f9fa; }
.search-dropdown-wallet .search-dropdown-item-wallet:last-child { border-bottom: none; }
</style>
<script>
(function() {
    var localCustomers = <?php echo json_encode($localCustomersForWallet); ?>;
    var searchInput = document.getElementById('wallet_local_customer_search');
    var dropdown = document.getElementById('wallet_local_customer_dropdown');
    var hiddenId = document.getElementById('wallet_local_customer_id');
    var hiddenName = document.getElementById('wallet_local_customer_name');
    var balanceDisplay = document.getElementById('wallet_customer_balance_display');
    if (!searchInput || !dropdown) return;
    function matchSearch(text, q) {
        if (!q || !text) return true;
        var t = (text + '').toLowerCase();
        var k = (q + '').trim().toLowerCase();
        return t.indexOf(k) !== -1;
    }
    function showDropdown() {
        var q = (searchInput.value || '').trim();
        var filtered = q ? localCustomers.filter(function(c) { return matchSearch(c.name, q); }) : localCustomers.slice(0, 50);
        dropdown.innerHTML = '';
        if (filtered.length === 0) {
            dropdown.classList.add('d-none');
            return;
        }
        filtered.forEach(function(c) {
            var div = document.createElement('div');
            div.className = 'search-dropdown-item-wallet';
            div.textContent = c.name + (c.balance > 0 ? ' — رصيد: ' + parseFloat(c.balance).toFixed(2) + ' ج.م' : '');
            div.dataset.id = c.id;
            div.dataset.name = c.name;
            div.dataset.balance = c.balance;
            div.addEventListener('click', function() {
                hiddenId.value = this.dataset.id;
                hiddenName.value = this.dataset.name;
                searchInput.value = this.dataset.name;
                var bal = parseFloat(this.dataset.balance || 0);
                balanceDisplay.textContent = bal > 0 ? bal.toFixed(2) + ' ج.م (رصيد مدين)' : (bal < 0 ? Math.abs(bal).toFixed(2) + ' ج.م (رصيد دائن)' : '0.00 ج.م');
                dropdown.classList.add('d-none');
            });
            dropdown.appendChild(div);
        });
        dropdown.classList.remove('d-none');
    }
    searchInput.addEventListener('input', function() {
        hiddenId.value = '';
        hiddenName.value = '';
        balanceDisplay.textContent = '-';
        showDropdown();
    });
    searchInput.addEventListener('focus', function() { showDropdown(); });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-wrap')) dropdown.classList.add('d-none');
    });
})();
</script>
<?php endif; ?>

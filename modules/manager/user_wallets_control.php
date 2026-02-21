<?php
/**
 * صفحة التحكم في محافظ المستخدمين
 * للمحاسب والمدير: عرض أرصدة المحافظ وسحب الأموال (لا تُضاف للخزنة)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$db = db();

// التأكد من وجود جدول المحفظة
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'user_wallet_transactions'");
if (empty($tableCheck)) {
    echo '<div class="alert alert-warning">جدول محفظة المستخدم غير متوفر. يرجى تشغيل ملف <code>database/migrations/add_user_wallet_tables.php</code></div>';
    return;
}

/**
 * حساب رصيد المحفظة للمستخدم
 */
function getWalletBalanceForControl($db, $userId) {
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

$error = '';
$success = '';
$selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

if (isset($_SESSION['user_wallets_success'])) {
    $success = $_SESSION['user_wallets_success'];
    unset($_SESSION['user_wallets_success']);
}

// معالجة السحب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'withdraw') {
    $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $amount = isset($_POST['amount']) ? (float) str_replace(',', '', $_POST['amount']) : 0;
    $notes = trim($_POST['notes'] ?? '');

    if ($targetUserId <= 0) {
        $error = 'يرجى اختيار المستخدم.';
    } elseif ($amount <= 0) {
        $error = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
    } else {
        $targetUser = $db->queryOne("SELECT id, full_name, username, role FROM users WHERE id = ? AND role IN ('driver', 'production') AND status = 'active'", [$targetUserId]);
        if (empty($targetUser)) {
            $error = 'المستخدم غير موجود أو غير مسموح له بالمحفظة.';
        } else {
            $balance = getWalletBalanceForControl($db, $targetUserId);
            if ($amount > $balance) {
                $error = 'رصيد المحفظة (' . formatCurrency($balance) . ') غير كافٍ للسحب.';
            } else {
                try {
                    $db->execute(
                        "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'withdrawal', ?, ?, ?)",
                        [$targetUserId, $amount, $notes ?: 'سحب من قبل ' . ($currentUser['full_name'] ?? $currentUser['username']), $currentUser['id']]
                    );
                    logAudit($currentUser['id'], 'wallet_withdrawal', 'user_wallet_transactions', $db->getLastInsertId(), null, ['user_id' => $targetUserId, 'amount' => $amount]);
                    $_SESSION['user_wallets_success'] = 'تم سحب ' . formatCurrency($amount) . ' من محفظة ' . htmlspecialchars($targetUser['full_name'] ?? $targetUser['username']) . ' بنجاح. (لا يُضاف للخزنة)';
                    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'manager.php') . '?page=user_wallets_control&user_id=' . $targetUserId;
                    if (!headers_sent()) {
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                } catch (Throwable $e) {
                    error_log('Wallet withdrawal failed: ' . $e->getMessage());
                    $error = 'حدث خطأ أثناء السحب. يرجى المحاولة مرة أخرى.';
                }
            }
        }
    }
}

// جدول طلبات التحصيل من العملاء المحليين
$collectionRequestsTableExists = $db->queryOne("SHOW TABLES LIKE 'user_wallet_local_collection_requests'");

// معالجة الموافقة على طلب تحصيل من عميل محلي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_local_collection_request' && !empty($collectionRequestsTableExists)) {
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    if ($requestId <= 0) {
        $error = 'معرف الطلب غير صحيح.';
    } else {
        $req = $db->queryOne("SELECT * FROM user_wallet_local_collection_requests WHERE id = ? AND status = 'pending'", [$requestId]);
        if (empty($req)) {
            $error = 'الطلب غير موجود أو تمت معالجته مسبقاً.';
        } else {
            $customerId = (int)$req['local_customer_id'];
            $amount = (float)$req['amount'];
            $userId = (int)$req['user_id'];
            $customerName = $req['customer_name'] ?? '';
            try {
                $db->beginTransaction();
                $customer = $db->queryOne("SELECT id, name, balance FROM local_customers WHERE id = ? FOR UPDATE", [$customerId]);
                if (!$customer) {
                    throw new InvalidArgumentException('العميل غير موجود.');
                }
                $currentBalance = (float)($customer['balance'] ?? 0);
                $newBalance = round($currentBalance - $amount, 2);
                $db->execute("UPDATE local_customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
                if (function_exists('logAudit')) {
                    logAudit($currentUser['id'], 'approve_wallet_local_collection', 'local_customer', $customerId, null, ['request_id' => $requestId, 'amount' => $amount, 'previous_balance' => $currentBalance, 'new_balance' => $newBalance]);
                }
                $localCollectionsExists = $db->queryOne("SHOW TABLES LIKE 'local_collections'");
                $collectionId = null;
                if (!empty($localCollectionsExists)) {
                    $cols = $db->queryOne("SHOW COLUMNS FROM local_collections LIKE 'status'");
                    $collColumns = ['customer_id', 'amount', 'date', 'payment_method', 'collected_by'];
                    $collValues = [$customerId, $amount, date('Y-m-d'), 'cash', $currentUser['id']];
                    if (!empty($cols)) {
                        $collColumns[] = 'status';
                        $collValues[] = 'approved';
                    }
                    $ph = implode(',', array_fill(0, count($collColumns), '?'));
                    $db->execute("INSERT INTO local_collections (" . implode(',', $collColumns) . ") VALUES ($ph)", $collValues);
                    $collectionId = $db->getLastInsertId();
                }
                $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                if (!empty($accountantTableExists)) {
                    $desc = 'تحصيل من عميل محلي (محفظة مستخدم): ' . $customerName;
                    $ref = 'WALLET-LOC-' . $requestId . '-' . date('YmdHis');
                    $db->execute(
                        "INSERT INTO accountant_transactions (transaction_type, amount, description, reference_number, payment_method, status, created_by, approved_by, approved_at) VALUES ('income', ?, ?, ?, 'cash', 'approved', ?, ?, NOW())",
                        [$amount, $desc, $ref, $currentUser['id'], $currentUser['id']]
                    );
                }
                $db->execute(
                    "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'deposit', ?, ?, ?)",
                    [$userId, $amount, 'تحصيل من عميل محلي: ' . $customerName . ' (تمت الموافقة)', $currentUser['id']]
                );
                $db->execute(
                    "UPDATE user_wallet_local_collection_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
                    [$currentUser['id'], $requestId]
                );
                $db->commit();
                $_SESSION['user_wallets_success'] = 'تمت الموافقة على طلب التحصيل (' . formatCurrency($amount) . ' من ' . htmlspecialchars($customerName) . ') وخصم المبلغ من رصيد العميل وإضافته لخزنة الشركة ومحفظة المستخدم.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('approve_local_collection_request: ' . $e->getMessage());
                $error = $e->getMessage();
                if (strpos($error, 'InvalidArgumentException') !== false) {
                    $error = 'العميل غير موجود أو البيانات غير صحيحة.';
                } else {
                    $error = 'حدث خطأ أثناء الموافقة. يرجى المحاولة مرة أخرى.';
                }
            }
        }
    }
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'manager.php') . '?page=user_wallets_control';
    if ($selectedUserId > 0) {
        $redirectUrl .= '&user_id=' . $selectedUserId;
    }
    if (!headers_sent() && empty($error)) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// معالجة رفض طلب تحصيل من عميل محلي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_local_collection_request' && !empty($collectionRequestsTableExists)) {
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $rejectionReason = trim($_POST['rejection_reason'] ?? '');
    if ($requestId <= 0) {
        $error = 'معرف الطلب غير صحيح.';
    } else {
        $updated = $db->execute("UPDATE user_wallet_local_collection_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND status = 'pending'", [$currentUser['id'], $rejectionReason ?: null, $requestId]);
        if ($updated) {
            $_SESSION['user_wallets_success'] = 'تم رفض طلب التحصيل.';
            $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'manager.php') . '?page=user_wallets_control';
            if ($selectedUserId > 0) {
                $redirectUrl .= '&user_id=' . $selectedUserId;
            }
            if (!headers_sent()) {
                header('Location: ' . $redirectUrl);
                exit;
            }
        } else {
            $error = 'الطلب غير موجود أو تمت معالجته مسبقاً.';
        }
    }
}

$pendingLocalCollectionRequests = [];
if (!empty($collectionRequestsTableExists)) {
    $pendingLocalCollectionRequests = $db->query(
        "SELECT r.*, u.full_name AS user_full_name, u.username AS user_username FROM user_wallet_local_collection_requests r LEFT JOIN users u ON u.id = r.user_id WHERE r.status = 'pending' ORDER BY r.created_at ASC"
    ) ?: [];
}

// جلب قائمة المستخدمين (سائق، عامل إنتاج) مع أرصدتهم
$walletUsers = $db->query(
    "SELECT u.id, u.full_name, u.username, u.role FROM users u WHERE u.status = 'active' AND u.role IN ('driver', 'production') ORDER BY u.role, u.full_name ASC, u.username ASC"
) ?: [];

$userBalances = [];
foreach ($walletUsers as $u) {
    $userBalances[$u['id']] = getWalletBalanceForControl($db, $u['id']);
}

$selectedUser = null;
$selectedTransactions = [];
if ($selectedUserId > 0) {
    $selectedUser = $db->queryOne("SELECT id, full_name, username, role FROM users WHERE id = ? AND role IN ('driver', 'production')", [$selectedUserId]);
    if ($selectedUser) {
        $selectedTransactions = $db->query(
            "SELECT t.*, u.full_name as created_by_name FROM user_wallet_transactions t LEFT JOIN users u ON u.id = t.created_by WHERE t.user_id = ? ORDER BY t.created_at DESC LIMIT 50",
            [$selectedUserId]
        ) ?: [];
    }
}

$typeLabels = [
    'deposit' => 'إيداع',
    'withdrawal' => 'سحب',
    'custody_add' => 'عهدة',
    'custody_retrieve' => 'استرجاع عهدة'
];
$roleLabels = ['driver' => 'سائق', 'production' => 'عامل إنتاج'];
?>
<div class="container-fluid">
    <div class="page-header mb-4">
        <h2><i class="bi bi-wallet2 me-2"></i>التحكم في محافظ المستخدمين</h2>
        <p class="text-muted mb-0">سحب الأموال من محافظ السائقين وعمال الإنتاج (لا تُضاف للخزنة)</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($collectionRequestsTableExists) && !empty($pendingLocalCollectionRequests)): ?>
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header bg-warning bg-opacity-25 fw-bold">
            <i class="bi bi-hourglass-split me-2"></i>طلبات التحصيل من العملاء المحليين (في انتظار الموافقة)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>التاريخ</th>
                            <th>المستخدم</th>
                            <th>العميل</th>
                            <th>المبلغ</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingLocalCollectionRequests as $req): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i', strtotime($req['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($req['user_full_name'] ?: $req['user_username'] ?? '#' . $req['user_id']); ?></td>
                            <td><?php echo htmlspecialchars($req['customer_name']); ?></td>
                            <td class="fw-bold"><?php echo formatCurrency($req['amount']); ?></td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm('الموافقة على هذا الطلب ستخصم المبلغ من رصيد العميل وتضيفه لخزنة الشركة ومحفظة المستخدم. متأكد؟');">
                                    <input type="hidden" name="action" value="approve_local_collection_request">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>موافقة</button>
                                </form>
                                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo (int)$req['id']; ?>"><i class="bi bi-x-lg me-1"></i>رفض</button>
                                <div class="modal fade" id="rejectModal<?php echo (int)$req['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">رفض طلب التحصيل</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="reject_local_collection_request">
                                                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                                    <p class="mb-2">طلب: <?php echo formatCurrency($req['amount']); ?> من <?php echo htmlspecialchars($req['customer_name']); ?></p>
                                                    <label class="form-label">سبب الرفض (اختياري)</label>
                                                    <input type="text" class="form-control" name="rejection_reason" placeholder="سبب الرفض">
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                                    <button type="submit" class="btn btn-danger">رفض الطلب</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-lg-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-people me-2"></i>المستخدمون ذوو المحافظ
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($walletUsers as $u): ?>
                            <?php $bal = $userBalances[$u['id']] ?? 0; ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['user_id' => $u['id'], 'page' => 'user_wallets_control'])); ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $selectedUserId == $u['id'] ? 'active' : ''; ?>">
                                <span><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?> <small class="text-muted">(<?php echo $roleLabels[$u['role']] ?? $u['role']; ?>)</small></span>
                                <span class="badge bg-<?php echo $bal >= 0 ? 'success' : 'danger'; ?> rounded-pill"><?php echo formatCurrency($bal); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($walletUsers)): ?>
                            <div class="list-group-item text-muted text-center">لا يوجد مستخدمون مسجلون</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-8 mb-4">
            <?php if ($selectedUser): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-cash-stack me-2"></i>سحب من محفظة <?php echo htmlspecialchars($selectedUser['full_name'] ?: $selectedUser['username']); ?>
                        <span class="badge bg-primary ms-2">الرصيد: <?php echo formatCurrency($userBalances[$selectedUser['id']] ?? 0); ?></span>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="user_id" value="<?php echo (int) $selectedUser['id']; ?>">
                            <div class="col-12 col-md-4">
                                <label for="withdrawAmount" class="form-label">المبلغ <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="number" step="0.01" min="0.01" max="<?php echo ($userBalances[$selectedUser['id']] ?? 0); ?>" class="form-control" id="withdrawAmount" name="amount" required placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="withdrawNotes" class="form-label">ملاحظات (اختياري)</label>
                                <input type="text" class="form-control" id="withdrawNotes" name="notes" placeholder="سبب السحب أو تفاصيل">
                            </div>
                            <div class="col-12 col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-danger w-100">
                                    <i class="bi bi-dash-circle me-1"></i>سحب
                                </button>
                            </div>
                        </form>
                        <small class="text-muted">ملاحظة: المبلغ المسحوب لا يُضاف إلى خزنة الشركة</small>
                    </div>
                </div>
                <div class="card shadow-sm">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-journal-text me-2"></i>سجل معاملات <?php echo htmlspecialchars($selectedUser['full_name'] ?: $selectedUser['username']); ?>
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
                                        <th>بواسطة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($selectedTransactions)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">لا توجد معاملات</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($selectedTransactions as $t): ?>
                                            <tr>
                                                <td><?php echo date('Y-m-d H:i', strtotime($t['created_at'])); ?></td>
                                                <td><span class="badge bg-<?php echo in_array($t['type'], ['deposit', 'custody_add']) ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($typeLabels[$t['type']] ?? $t['type']); ?></span></td>
                                                <td class="fw-bold <?php echo in_array($t['type'], ['deposit', 'custody_add']) ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo in_array($t['type'], ['deposit', 'custody_add']) ? '+' : '-'; ?><?php echo formatCurrency($t['amount']); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($t['reason'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($t['created_by_name'] ?? '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-person-plus display-4"></i>
                        <p class="mt-2 mb-0">اختر مستخدماً من القائمة لعرض محفظته وإجراء السحب</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

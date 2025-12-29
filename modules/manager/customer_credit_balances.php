<?php
/**
 * صفحة العملاء ذوي الرصيد الدائن
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/approval_system.php';

requireRole(['manager', 'accountant', 'developer']);

/**
 * حساب صافي رصيد خزنة الشركة
 * الصيغة: الإيرادات المعتمدة - المصروفات المعتمدة - المدفوعات
 */
if (!function_exists('calculateCompanyCashBalance')) {
    function calculateCompanyCashBalance($db) {
        // حساب ملخص الخزنة من financial_transactions و accountant_transactions
        $treasurySummary = $db->queryOne("
            SELECT
                (SELECT COALESCE(SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('collection_from_sales_rep', 'income') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_income,
                (SELECT COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND status = 'approved' 
                    AND description NOT LIKE '%تسوية رصيد دائن ل%'
                    THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_expense,
                (SELECT COALESCE(SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_payment
        ");
        
        $approvedIncome = (float) ($treasurySummary['approved_income'] ?? 0);
        $approvedExpense = (float) ($treasurySummary['approved_expense'] ?? 0);
        $approvedPayment = (float) ($treasurySummary['approved_payment'] ?? 0);
        
        // حساب صافي الرصيد
        // ملاحظة: تسويات الرواتب تُحسب كـ expenses في accountant_transactions، لذلك تُخصم تلقائياً
        $netBalance = $approvedIncome - $approvedExpense - $approvedPayment;
        
        return $netBalance; // يمكن أن يكون سالباً إذا كانت المصروفات أكبر من الإيرادات
    }
}

$currentUser = getCurrentUser();
$db = db();

$success = '';
$error = '';

if (isset($_SESSION['customer_credit_success'])) {
    $success = $_SESSION['customer_credit_success'];
    unset($_SESSION['customer_credit_success']);
}

if (isset($_SESSION['customer_credit_error'])) {
    $error = $_SESSION['customer_credit_error'];
    unset($_SESSION['customer_credit_error']);
}

// معالجة تسوية الرصيد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_credit_balance') {
    $customerId = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $customerType = isset($_POST['customer_type']) ? trim($_POST['customer_type']) : 'rep'; // 'rep' أو 'local'
    $settlementAmount = isset($_POST['settlement_amount']) ? cleanFinancialValue($_POST['settlement_amount']) : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    if ($customerId <= 0) {
        $_SESSION['customer_credit_error'] = 'معرف العميل غير صحيح.';
    } elseif ($settlementAmount <= 0) {
        $_SESSION['customer_credit_error'] = 'يجب إدخال مبلغ تسوية صحيح أكبر من الصفر.';
    } else {
        try {
            // جلب بيانات العميل حسب النوع
            $customer = null;
            $tableName = '';
            if ($customerType === 'local') {
                $tableName = 'local_customers';
                $customer = $db->queryOne(
                    "SELECT id, name, balance, created_by FROM local_customers WHERE id = ?",
                    [$customerId]
                );
            } else {
                $tableName = 'customers';
                $customer = $db->queryOne(
                    "SELECT id, name, balance, rep_id, created_by FROM customers WHERE id = ?",
                    [$customerId]
                );
            }
            
            if (empty($customer)) {
                throw new Exception('العميل غير موجود.');
            }
            
            $currentBalance = (float)($customer['balance'] ?? 0);
            
            // التحقق من أن الرصيد سالب (رصيد دائن)
            if ($currentBalance >= 0) {
                throw new Exception('هذا العميل ليس لديه رصيد دائن.');
            }
            
            $creditAmount = abs($currentBalance);
            
            // التحقق من أن مبلغ التسوية لا يتجاوز الرصيد الدائن
            if ($settlementAmount > $creditAmount) {
                throw new Exception('مبلغ التسوية (' . formatCurrency($settlementAmount) . ') يتجاوز الرصيد الدائن المتاح (' . formatCurrency($creditAmount) . ').');
            }
            
            // التحقق من رصيد خزنة الشركة
            require_once __DIR__ . '/../../includes/approval_system.php';
            $companyBalance = calculateCompanyCashBalance($db);
            
            if ($settlementAmount > $companyBalance) {
                throw new Exception('رصيد خزنة الشركة (' . formatCurrency($companyBalance) . ') غير كافٍ لتسوية الرصيد الدائن (' . formatCurrency($settlementAmount) . ').');
            }
            
            $db->beginTransaction();
            
            try {
                // حساب الرصيد الجديد بعد التسوية
                $newBalance = round($currentBalance + $settlementAmount, 2);
                
                // تحديث رصيد العميل
                $db->execute(
                    "UPDATE {$tableName} SET balance = ? WHERE id = ?",
                    [$newBalance, $customerId]
                );
                
                // إضافة معاملة expense في accountant_transactions (خصم من خزنة الشركة)
                $customerName = htmlspecialchars($customer['name'] ?? '', ENT_QUOTES, 'UTF-8');
                $customerTypeLabel = $customerType === 'local' ? 'عميل محلي' : 'عميل مندوب';
                $description = 'تسوية رصيد دائن ل' . $customerTypeLabel . ': ' . $customerName;
                if ($notes) {
                    $description .= ' - ' . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8');
                }
                $referenceNumber = 'CUST-CREDIT-SETTLE-' . ($customerType === 'local' ? 'LOCAL-' : 'REP-') . $customerId . '-' . date('YmdHis');
                
                // التأكد من وجود جدول accountant_transactions
                $accountantTableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                if (!empty($accountantTableCheck)) {
                    $db->execute(
                        "INSERT INTO accountant_transactions 
                            (transaction_type, amount, description, reference_number, 
                             status, approved_by, created_by, approved_at, notes)
                         VALUES (?, ?, ?, ?, 'approved', ?, ?, NOW(), ?)",
                        [
                            'expense',
                            $settlementAmount,
                            $description,
                            $referenceNumber,
                            $currentUser['id'],
                            $currentUser['id'],
                            $notes ?: null
                        ]
                    );
                }
                
                // تسجيل في audit_logs
                logAudit(
                    $currentUser['id'],
                    'settle_customer_credit',
                    $customerType === 'local' ? 'local_customer' : 'customer',
                    $customerId,
                    null,
                    [
                        'customer_name' => $customerName,
                        'customer_type' => $customerType,
                        'settlement_amount' => $settlementAmount,
                        'old_balance' => $currentBalance,
                        'new_balance' => $newBalance,
                        'reference_number' => $referenceNumber
                    ]
                );
                
                $db->commit();
                
                $_SESSION['customer_credit_success'] = 'تم تسوية رصيد ' . $customerTypeLabel . ' ' . $customerName . ' بمبلغ ' . formatCurrency($settlementAmount) . ' بنجاح.';
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            error_log('Settle customer credit error: ' . $e->getMessage());
            $_SESSION['customer_credit_error'] = 'حدث خطأ أثناء تسوية الرصيد: ' . $e->getMessage();
        }
    }
    
    $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
    if (!headers_sent()) {
        header('Location: ' . $redirectTarget);
    } else {
        echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
    }
    exit;
}

// جلب العملاء ذوي الرصيد الدائن (رصيد سالب) - عملاء المندوبين
$repCreditorCustomers = [];
try {
    $repCreditorCustomers = $db->query(
        "SELECT 
            c.id,
            c.name,
            c.phone,
            c.address,
            c.balance,
            c.created_at,
            u.full_name AS rep_name,
            u.id AS rep_id,
            'rep' AS customer_type
        FROM customers c
        LEFT JOIN users u ON (c.rep_id = u.id OR c.created_by = u.id)
        WHERE (c.rep_id IN (SELECT id FROM users WHERE role = 'sales') 
               OR c.created_by IN (SELECT id FROM users WHERE role = 'sales'))
          AND c.balance < 0
        ORDER BY ABS(c.balance) DESC, c.name ASC"
    );
} catch (Throwable $creditorError) {
    error_log('Rep creditor customers query error: ' . $creditorError->getMessage());
    $repCreditorCustomers = [];
}

// جلب العملاء المحليين ذوي الرصيد الدائن
$localCreditorCustomers = [];
try {
    $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (!empty($localCustomersTableExists)) {
        $localCreditorCustomers = $db->query(
            "SELECT 
                c.id,
                c.name,
                c.phone,
                c.address,
                c.balance,
                c.created_at,
                NULL AS rep_name,
                NULL AS rep_id,
                'local' AS customer_type
            FROM local_customers c
            WHERE c.balance < 0
            ORDER BY ABS(c.balance) DESC, c.name ASC"
        );
    }
} catch (Throwable $localCreditorError) {
    error_log('Local creditor customers query error: ' . $localCreditorError->getMessage());
    $localCreditorCustomers = [];
}

// دمج العملاء مع إضافة نوع العميل
$creditorCustomers = [];
foreach ($repCreditorCustomers as $customer) {
    $customer['customer_type'] = 'rep';
    $creditorCustomers[] = $customer;
}
foreach ($localCreditorCustomers as $customer) {
    $customer['customer_type'] = 'local';
    $creditorCustomers[] = $customer;
}

// ترتيب حسب الرصيد الدائن
usort($creditorCustomers, function($a, $b) {
    $balanceA = abs((float)($a['balance'] ?? 0));
    $balanceB = abs((float)($b['balance'] ?? 0));
    if ($balanceA == $balanceB) {
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    }
    return $balanceB <=> $balanceA;
});

// حساب الإجماليات
$totalCreditBalance = 0.0;
$customerCount = count($creditorCustomers);
foreach ($creditorCustomers as $customer) {
    $balanceValue = (float)($customer['balance'] ?? 0.0);
    $totalCreditBalance += abs($balanceValue);
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>

<!-- صفحة العملاء ذوي الرصيد الدائن -->
<div class="page-header mb-4">
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-lg-8">
                    <div class="d-flex align-items-center mb-3">
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-wallet2 text-primary fs-3"></i>
                        </div>
                        <div>
                            <h2 class="fw-bold mb-1">العملاء ذوو الرصيد الدائن</h2>
                            <p class="text-muted small mb-0">إدارة وتسوية أرصدة العملاء الدائنة</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-center p-2 bg-success bg-opacity-10 rounded">
                                <i class="bi bi-cash-coin me-2 text-success fs-5"></i>
                                <div>
                                    <small class="text-muted d-block small">إجمالي الرصيد الدائن</small>
                                    <strong class="text-success"><?php echo formatCurrency($totalCreditBalance); ?></strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-center p-2 bg-info bg-opacity-10 rounded">
                                <i class="bi bi-people me-2 text-info fs-5"></i>
                                <div>
                                    <small class="text-muted d-block small">عدد العملاء</small>
                                    <strong class="text-info"><?php echo number_format($customerCount); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <label for="customerSearchInput" class="form-label fw-semibold">
                        <i class="bi bi-search me-1"></i>البحث في القائمة
                    </label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" 
                               class="form-control border-start-0" 
                               id="customerSearchInput" 
                               placeholder="ابحث بالاسم، رقم الهاتف، أو العنوان..."
                               autocomplete="off">
                        <button class="btn btn-outline-secondary border-start-0" 
                                type="button" 
                                id="clearSearchBtn" 
                                style="display: none;"
                                title="مسح البحث">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <small class="text-muted d-block mt-1">
                        <i class="bi bi-info-circle me-1"></i>
                        ابحث في جميع بيانات العملاء
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (empty($creditorCustomers)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-wallet2 text-muted" style="font-size: 4rem;"></i>
            <h5 class="mt-3 text-muted">لا يوجد عملاء ذوو رصيد دائن حالياً</h5>
            <p class="text-muted">جميع العملاء لديهم رصيد صفر أو رصيد مدين</p>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0">
                <div class="card-header bg-gradient bg-light border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0 fw-semibold">
                    <i class="bi bi-list-ul me-2 text-primary"></i>
                    قائمة العملاء
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary">إجمالي: <span id="totalCountBadge"><?php echo number_format($customerCount); ?></span></span>
                    <span class="badge bg-primary">عرض: <span id="visibleCountBadge"><?php echo number_format($customerCount); ?></span></span>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="customersTable">
                        <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>نوع العميل</th>
                            <th>اسم العميل</th>
                            <th>رقم الهاتف</th>
                            <th>العنوان</th>
                            <th>الرصيد الدائن</th>
                            <th>المندوب</th>
                            <th>تاريخ الإضافة</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="customersTableBody">
                        <?php foreach ($creditorCustomers as $index => $customer): ?>
                            <?php
                            $balanceValue = (float)($customer['balance'] ?? 0.0);
                            $creditAmount = abs($balanceValue);
                            ?>
                            <?php
                            $customerType = $customer['customer_type'] ?? 'rep';
                            $customerTypeLabel = $customerType === 'local' ? 'عميل محلي' : 'عميل مندوب';
                            $customerTypeBadge = $customerType === 'local' ? 'bg-info' : 'bg-secondary';
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <span class="badge <?php echo $customerTypeBadge; ?>">
                                        <?php echo htmlspecialchars($customerTypeLabel); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($customer['name'] ?? '-'); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary fs-6 px-3 py-2">
                                        <?php echo formatCurrency($creditAmount); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($customerType === 'rep' && !empty($customer['rep_name'])): ?>
                                        <span class="text-muted">
                                            <?php echo htmlspecialchars($customer['rep_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo function_exists('formatDate') 
                                        ? formatDate($customer['created_at'] ?? '') 
                                        : htmlspecialchars((string)($customer['created_at'] ?? '-')); ?>
                                </td>
                                <td>
                                    <button type="button" 
                                            class="btn btn-sm btn-success settle-credit-btn" 
                                            onclick="showSettleCreditModal(this)"
                                            data-customer-id="<?php echo $customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? ''); ?>"
                                            data-customer-type="<?php echo htmlspecialchars($customerType); ?>"
                                            data-customer-type-label="<?php echo htmlspecialchars($customerTypeLabel); ?>"
                                            data-credit-amount="<?php echo $creditAmount; ?>"
                                            data-credit-formatted="<?php echo formatCurrency($creditAmount); ?>"
                                            title="تسوية الرصيد الدائن">
                                        <i class="bi bi-cash-coin me-1"></i>تسوية الرصيد
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Modal تسوية الرصيد - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="settleCreditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-cash-coin me-2"></i>تسوية رصيد دائن
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="settleCreditForm">
                <input type="hidden" name="action" value="settle_credit_balance">
                <input type="hidden" name="customer_id" id="settleCustomerId">
                <input type="hidden" name="customer_type" id="settleCustomerType">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل</label>
                        <input type="text" class="form-control" id="settleCustomerName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع العميل</label>
                        <input type="text" class="form-control" id="settleCustomerTypeLabel" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الدائن الحالي</label>
                        <input type="text" class="form-control fw-bold text-primary" id="settleCreditDisplay" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">مبلغ التسوية <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">ج.م</span>
                            <input type="number" step="0.01" min="0.01" class="form-control" 
                                   id="settleAmount" name="settlement_amount" required>
                        </div>
                        <small class="text-muted" id="settleMaxHint"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات (اختياري)</label>
                        <textarea class="form-control" name="notes" rows="2" id="settleNotes"></textarea>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>ملاحظة:</strong> سيتم خصم مبلغ التسوية من خزنة الشركة.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i>تأكيد التسوية
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card تسوية الرصيد - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="settleCreditCard" style="display: none;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">
            <i class="bi bi-cash-coin me-2"></i>تسوية رصيد دائن
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" id="settleCreditCardForm">
            <input type="hidden" name="action" value="settle_credit_balance">
            <input type="hidden" name="customer_id" id="settleCreditCardCustomerId">
            <input type="hidden" name="customer_type" id="settleCreditCardCustomerType">
            <div class="mb-3">
                <label class="form-label">اسم العميل</label>
                <input type="text" class="form-control" id="settleCreditCardCustomerName" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">نوع العميل</label>
                <input type="text" class="form-control" id="settleCreditCardCustomerTypeLabel" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">الرصيد الدائن الحالي</label>
                <input type="text" class="form-control fw-bold text-primary" id="settleCreditCardCreditDisplay" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">مبلغ التسوية <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">ج.م</span>
                    <input type="number" step="0.01" min="0.01" class="form-control" 
                           id="settleCreditCardAmount" name="settlement_amount" required>
                </div>
                <small class="text-muted" id="settleCreditCardMaxHint"></small>
            </div>
            <div class="mb-3">
                <label class="form-label">ملاحظات (اختياري)</label>
                <textarea class="form-control" name="notes" rows="2" id="settleCreditCardNotes"></textarea>
            </div>
            <div class="alert alert-info mb-3">
                <i class="bi bi-info-circle me-2"></i>
                <strong>ملاحظة:</strong> سيتم خصم مبلغ التسوية من خزنة الشركة.
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>تأكيد التسوية
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeSettleCreditCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
// ===== دوال أساسية للـ Modal/Card Dual System =====

// دالة التحقق من الموبايل
function isMobile() {
    return window.innerWidth <= 768;
}

// دالة للـ scroll تلقائي محسّنة
function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80; // مساحة للـ header
        const targetPosition = elementTop - offset;
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, targetPosition),
                behavior: 'smooth'
            });
        });
    }, 200);
}

// دالة لإغلاق جميع النماذج المفتوحة
function closeAllForms() {
    // إغلاق جميع Cards على الموبايل
    const settleCard = document.getElementById('settleCreditCard');
    
    if (settleCard && settleCard.style.display !== 'none') {
        settleCard.style.display = 'none';
        const form = settleCard.querySelector('form');
        if (form) form.reset();
    }
    
    // إغلاق جميع Modals على الكمبيوتر
    const modals = ['settleCreditModal'];
    
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// دالة فتح نموذج تسوية الرصيد
function showSettleCreditModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    const customerId = button.getAttribute('data-customer-id') || '';
    const customerName = button.getAttribute('data-customer-name') || '-';
    const customerType = button.getAttribute('data-customer-type') || 'rep';
    const customerTypeLabel = button.getAttribute('data-customer-type-label') || 'عميل مندوب';
    const creditAmount = parseFloat(button.getAttribute('data-credit-amount')) || 0;
    const creditFormatted = button.getAttribute('data-credit-formatted') || '0.00';
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('settleCreditCard');
        if (card) {
            const customerIdInput = card.querySelector('#settleCreditCardCustomerId');
            const customerTypeInput = card.querySelector('#settleCreditCardCustomerType');
            const customerNameInput = card.querySelector('#settleCreditCardCustomerName');
            const customerTypeLabelInput = card.querySelector('#settleCreditCardCustomerTypeLabel');
            const creditDisplay = card.querySelector('#settleCreditCardCreditDisplay');
            const amountInput = card.querySelector('#settleCreditCardAmount');
            const maxHint = card.querySelector('#settleCreditCardMaxHint');
            const notesInput = card.querySelector('#settleCreditCardNotes');
            
            if (customerIdInput) customerIdInput.value = customerId;
            if (customerTypeInput) customerTypeInput.value = customerType;
            if (customerNameInput) customerNameInput.value = customerName;
            if (customerTypeLabelInput) customerTypeLabelInput.value = customerTypeLabel;
            if (creditDisplay) creditDisplay.value = creditFormatted;
            if (amountInput) {
                amountInput.value = creditAmount.toFixed(2);
                amountInput.max = creditAmount;
                amountInput.setAttribute('data-max-amount', creditAmount);
            }
            if (maxHint) maxHint.textContent = 'الحد الأقصى: ' + creditFormatted;
            if (notesInput) notesInput.value = '';
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('settleCreditModal');
        if (modal) {
            const customerIdInput = modal.querySelector('#settleCustomerId');
            const customerTypeInput = modal.querySelector('#settleCustomerType');
            const customerNameInput = modal.querySelector('#settleCustomerName');
            const customerTypeLabelInput = modal.querySelector('#settleCustomerTypeLabel');
            const creditDisplay = modal.querySelector('#settleCreditDisplay');
            const amountInput = modal.querySelector('#settleAmount');
            const maxHint = modal.querySelector('#settleMaxHint');
            const notesInput = modal.querySelector('#settleNotes');
            
            if (customerIdInput) customerIdInput.value = customerId;
            if (customerTypeInput) customerTypeInput.value = customerType;
            if (customerNameInput) customerNameInput.value = customerName;
            if (customerTypeLabelInput) customerTypeLabelInput.value = customerTypeLabel;
            if (creditDisplay) creditDisplay.value = creditFormatted;
            if (amountInput) {
                amountInput.value = creditAmount.toFixed(2);
                amountInput.max = creditAmount;
                amountInput.setAttribute('data-max-amount', creditAmount);
            }
            if (maxHint) maxHint.textContent = 'الحد الأقصى: ' + creditFormatted;
            if (notesInput) notesInput.value = '';
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// دالة إغلاق Card تسوية الرصيد
function closeSettleCreditCard() {
    const card = document.getElementById('settleCreditCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
        
        // إعادة تعيين الحقول
        const customerIdInput = document.getElementById('settleCreditCardCustomerId');
        const customerTypeInput = document.getElementById('settleCreditCardCustomerType');
        const customerNameInput = document.getElementById('settleCreditCardCustomerName');
        const customerTypeLabelInput = document.getElementById('settleCreditCardCustomerTypeLabel');
        const creditDisplay = document.getElementById('settleCreditCardCreditDisplay');
        const amountInput = document.getElementById('settleCreditCardAmount');
        const maxHint = document.getElementById('settleCreditCardMaxHint');
        const notesInput = document.getElementById('settleCreditCardNotes');
        
        if (customerIdInput) customerIdInput.value = '';
        if (customerTypeInput) customerTypeInput.value = '';
        if (customerNameInput) customerNameInput.value = '';
        if (customerTypeLabelInput) customerTypeLabelInput.value = '';
        if (creditDisplay) creditDisplay.value = '';
        if (amountInput) {
            amountInput.value = '';
            amountInput.max = '';
            amountInput.removeAttribute('data-max-amount');
        }
        if (maxHint) maxHint.textContent = '';
        if (notesInput) notesInput.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // ========== وظيفة البحث في الجدول ==========
    var searchInput = document.getElementById('customerSearchInput');
    var clearSearchBtn = document.getElementById('clearSearchBtn');
    var customersTableBody = document.getElementById('customersTableBody');
    var visibleCountBadge = document.getElementById('visibleCountBadge');
    
    if (searchInput && customersTableBody) {
        searchInput.addEventListener('input', filterTable);
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterTable();
            }
        });
        
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                filterTable();
                searchInput.focus();
            });
        }
        
        function filterTable() {
            var searchTerm = searchInput.value.trim().toLowerCase();
            var rows = customersTableBody.querySelectorAll('tr:not(.no-results-row)');
            var visibleCount = 0;
            
            rows.forEach(function(row) {
                var cells = row.querySelectorAll('td');
                var rowText = '';
                cells.forEach(function(cell, index) {
                    if (index < cells.length - 1) {
                        rowText += ' ' + (cell.textContent || '').toLowerCase();
                    }
                });
                
                if (searchTerm === '' || rowText.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCountBadge) {
                visibleCountBadge.textContent = visibleCount;
            }
            
            if (clearSearchBtn) {
                clearSearchBtn.style.display = searchTerm.length > 0 ? 'block' : 'none';
            }
        }
    }
    
    // ========== التحقق من النموذج قبل الإرسال - Modal ==========
    var settleForm = document.getElementById('settleCreditForm');
    if (settleForm) {
        settleForm.addEventListener('submit', function(e) {
            var amountInput = document.getElementById('settleAmount');
            var amount = parseFloat(amountInput.value) || 0;
            var maxAmount = parseFloat(amountInput.getAttribute('data-max-amount')) || parseFloat(amountInput.max) || 0;
            
            if (amount <= 0) {
                e.preventDefault();
                alert('يجب إدخال مبلغ صحيح أكبر من الصفر.');
                amountInput.focus();
                return false;
            }
            
            if (maxAmount > 0 && amount > maxAmount) {
                e.preventDefault();
                alert('مبلغ التسوية يتجاوز الرصيد الدائن المتاح.');
                amountInput.focus();
                return false;
            }
            
            if (!confirm('هل أنت متأكد من تسوية الرصيد الدائن؟\nسيتم خصم المبلغ من خزنة الشركة.')) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // ========== التحقق من النموذج قبل الإرسال - Card ==========
    var settleCardForm = document.getElementById('settleCreditCardForm');
    if (settleCardForm) {
        settleCardForm.addEventListener('submit', function(e) {
            var amountInput = document.getElementById('settleCreditCardAmount');
            var amount = parseFloat(amountInput.value) || 0;
            var maxAmount = parseFloat(amountInput.getAttribute('data-max-amount')) || parseFloat(amountInput.max) || 0;
            
            if (amount <= 0) {
                e.preventDefault();
                alert('يجب إدخال مبلغ صحيح أكبر من الصفر.');
                amountInput.focus();
                return false;
            }
            
            if (maxAmount > 0 && amount > maxAmount) {
                e.preventDefault();
                alert('مبلغ التسوية يتجاوز الرصيد الدائن المتاح.');
                amountInput.focus();
                return false;
            }
            
            if (!confirm('هل أنت متأكد من تسوية الرصيد الدائن؟\nسيتم خصم المبلغ من خزنة الشركة.')) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // تنظيف Modal عند الإغلاق (على الكمبيوتر فقط)
    var settleCreditModal = document.getElementById('settleCreditModal');
    if (settleCreditModal) {
        settleCreditModal.addEventListener('hidden.bs.modal', function() {
            const form = settleCreditModal.querySelector('form');
            if (form) form.reset();
            
            document.getElementById('settleCustomerId').value = '';
            document.getElementById('settleCustomerType').value = '';
            document.getElementById('settleCustomerName').value = '';
            document.getElementById('settleCustomerTypeLabel').value = '';
            document.getElementById('settleCreditDisplay').value = '';
            const amountInput = document.getElementById('settleAmount');
            if (amountInput) {
                amountInput.value = '';
                amountInput.max = '';
                amountInput.removeAttribute('data-max-amount');
            }
            document.getElementById('settleMaxHint').textContent = '';
            document.getElementById('settleNotes').value = '';
        });
    }
});
</script>

<style>
/* تحسينات التصميم */
#customersTable thead th {
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #dee2e6;
}

#customersTable tbody tr {
    transition: all 0.2s ease;
}

#customersTable tbody tr:hover {
    background-color: #f8f9fa !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

#customerSearchInput:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.page-header .card {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border: 1px solid #e9ecef;
}

.no-results-row td {
    background-color: #fff3cd !important;
}

/* ===== CSS مبسط - Modal للكمبيوتر فقط، Card للموبايل ===== */

/* إخفاء Modal على الموبايل */
@media (max-width: 768px) {
    #settleCreditModal {
        display: none !important;
    }
}

/* إخفاء Card على الكمبيوتر */
@media (min-width: 769px) {
    #settleCreditCard {
        display: none !important;
    }
}

/* منع الملفات العامة من التأثير على Modals */
#settleCreditModal {
    height: auto !important;
    max-height: none !important;
}

#settleCreditModal .modal-dialog {
    display: block !important;
    height: auto !important;
    max-height: none !important;
    margin: 1.75rem auto !important;
}

#settleCreditModal .modal-content {
    height: auto !important;
    max-height: none !important;
}

#settleCreditModal .modal-body {
    height: auto !important;
    max-height: none !important;
    overflow-y: visible !important;
}
</style>

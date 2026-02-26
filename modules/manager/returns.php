<?php
/**
 * صفحة إدارة المرتجعات - حساب المدير
 * Manager Returns Management Page
 * 
 * هذه الصفحة مختلفة عن صفحة المندوب وتحتوي على:
 * - جدول لاستقبال طلبات المرتجعات من المندوبين والموافقة عليها
 * - جدول لعرض آخر عمليات المرتجعات
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// منع الكاش عند التبديل بين تبويبات الشريط الجانبي لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/returns_system.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$basePath = getBasePath();

// Pagination for pending returns
$pendingPageNum = isset($_GET['pending_p']) ? max(1, intval($_GET['pending_p'])) : 1;
$pendingPerPage = 15;
$pendingOffset = ($pendingPageNum - 1) * $pendingPerPage;

// Pagination for latest returns
$latestPageNum = isset($_GET['latest_p']) ? max(1, intval($_GET['latest_p'])) : 1;
$latestPerPage = 20;
$latestOffset = ($latestPageNum - 1) * $latestPerPage;

// Get pending return requests from delegates and local customers
$entityColumn = getApprovalsEntityColumn();

// التحقق من وجود جدول local_returns
$localReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_returns'");

// جلب مرتجعات المندوبين المعلقة
$pendingReturns = $db->query(
    "SELECT r.*, c.name as customer_name, c.balance as customer_balance,
            u.full_name as sales_rep_name,
            a.id as approval_id, a.created_at as request_date,
            req.full_name as requested_by_name,
            'delegate' as return_type,
            NULL as local_customer_id
     FROM returns r
     INNER JOIN approvals a ON a.type = 'return_request' AND a.{$entityColumn} = r.id
     LEFT JOIN customers c ON r.customer_id = c.id
     LEFT JOIN users u ON r.sales_rep_id = u.id
     LEFT JOIN users req ON a.requested_by = req.id
     WHERE r.status = 'pending' AND a.status = 'pending'
     ORDER BY r.created_at DESC"
);

// جلب مرتجعات العملاء المحليين المعلقة (إذا كان الجدول موجوداً)
$pendingLocalReturns = [];
if (!empty($localReturnsTableExists)) {
    $pendingLocalReturns = $db->query(
        "SELECT lr.*, lc.name as customer_name, lc.balance as customer_balance,
                NULL as sales_rep_name,
                NULL as approval_id,
                lr.created_at as request_date,
                creator.full_name as requested_by_name,
                'local' as return_type,
                lr.customer_id as local_customer_id
         FROM local_returns lr
         LEFT JOIN local_customers lc ON lr.customer_id = lc.id
         LEFT JOIN users creator ON lr.created_by = creator.id
         WHERE lr.status = 'pending'
         ORDER BY lr.created_at DESC"
    ) ?: [];
}

// دمج المرتجعات وترتيبها حسب التاريخ
$allPendingReturns = array_merge($pendingReturns ?: [], $pendingLocalReturns);
usort($allPendingReturns, function($a, $b) {
    return strtotime($b['request_date'] ?? $b['created_at']) - strtotime($a['request_date'] ?? $a['created_at']);
});

// تطبيق pagination
$totalPendingCount = count($allPendingReturns);
$totalPendingPages = ceil($totalPendingCount / $pendingPerPage);
$allPendingReturns = array_slice($allPendingReturns, $pendingOffset, $pendingPerPage);

// Get return items for each pending return
foreach ($allPendingReturns as &$return) {
    if ($return['return_type'] === 'local') {
        // مرتجعات العملاء المحليين
        $returnItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_return_items'");
        if (!empty($returnItemsTableExists)) {
            $return['items'] = $db->query(
                "SELECT lri.*, p.name as product_name, p.unit
                 FROM local_return_items lri
                 LEFT JOIN products p ON lri.product_id = p.id
                 WHERE lri.return_id = ?
                 ORDER BY lri.id",
                [(int)$return['id']]
            ) ?: [];
        } else {
            $return['items'] = [];
        }
    } else {
        // مرتجعات المندوبين
        $return['items'] = $db->query(
            "SELECT ri.*, p.name as product_name, p.unit
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?
             ORDER BY ri.id",
            [(int)$return['id']]
        ) ?: [];
    }
    
    // Calculate customer debt/credit
    $balance = (float)($return['customer_balance'] ?? 0);
    $return['customer_debt'] = $balance > 0 ? $balance : 0;
    $return['customer_credit'] = $balance < 0 ? abs($balance) : 0;
}
unset($return);

// Get latest return operations (approved, rejected, completed)
// مرتجعات المندوبين
$latestReturns = $db->query(
    "SELECT r.*, c.name as customer_name, c.balance as customer_balance,
            u.full_name as sales_rep_name,
            approver.full_name as approved_by_name,
            i.invoice_number,
            'delegate' as return_type,
            NULL as local_customer_id
     FROM returns r
     LEFT JOIN customers c ON r.customer_id = c.id
     LEFT JOIN users u ON r.sales_rep_id = u.id
     LEFT JOIN users approver ON r.approved_by = approver.id
     LEFT JOIN invoices i ON r.invoice_id = i.id
     WHERE r.status IN ('approved', 'rejected', 'processed', 'completed')
     ORDER BY COALESCE(r.approved_at, r.updated_at, r.created_at) DESC"
) ?: [];

// مرتجعات العملاء المحليين
$latestLocalReturns = [];
if (!empty($localReturnsTableExists)) {
    // جلب رقم الفاتورة من local_return_items (أول فاتورة مرتبطة)
    $latestLocalReturns = $db->query(
        "SELECT lr.*, lc.name as customer_name, lc.balance as customer_balance,
                NULL as sales_rep_name,
                approver.full_name as approved_by_name,
                (SELECT li.invoice_number 
                 FROM local_return_items lri 
                 LEFT JOIN local_invoices li ON lri.invoice_id = li.id 
                 WHERE lri.return_id = lr.id 
                 LIMIT 1) as invoice_number,
                'local' as return_type,
                lr.customer_id as local_customer_id
         FROM local_returns lr
         LEFT JOIN local_customers lc ON lr.customer_id = lc.id
         LEFT JOIN users approver ON lr.approved_by = approver.id
         WHERE lr.status IN ('approved', 'rejected', 'processed', 'completed')
         ORDER BY COALESCE(lr.approved_at, lr.updated_at, lr.created_at) DESC"
    ) ?: [];
}

// دمج المرتجعات وترتيبها حسب التاريخ
$allLatestReturns = array_merge($latestReturns, $latestLocalReturns);
usort($allLatestReturns, function($a, $b) {
    $dateA = $a['approved_at'] ?? $a['updated_at'] ?? $a['created_at'];
    $dateB = $b['approved_at'] ?? $b['updated_at'] ?? $b['created_at'];
    return strtotime($dateB) - strtotime($dateA);
});

// تطبيق pagination
$totalLatestCount = count($allLatestReturns);
$totalLatestPages = ceil($totalLatestCount / $latestPerPage);
$allLatestReturns = array_slice($allLatestReturns, $latestOffset, $latestPerPage);

// Get return items for each latest return
foreach ($allLatestReturns as &$return) {
    if ($return['return_type'] === 'local') {
        // مرتجعات العملاء المحليين
        $returnItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_return_items'");
        if (!empty($returnItemsTableExists)) {
            $return['items'] = $db->query(
                "SELECT lri.*, p.name as product_name, p.unit
                 FROM local_return_items lri
                 LEFT JOIN products p ON lri.product_id = p.id
                 WHERE lri.return_id = ?
                 ORDER BY lri.id",
                [(int)$return['id']]
            ) ?: [];
        } else {
            $return['items'] = [];
        }
    } else {
        // مرتجعات المندوبين
        $return['items'] = $db->query(
            "SELECT ri.*, p.name as product_name, p.unit
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?
             ORDER BY ri.id",
            [(int)$return['id']]
        ) ?: [];
    }
    
    // Calculate customer debt/credit
    $balance = (float)($return['customer_balance'] ?? 0);
    $return['customer_debt'] = $balance > 0 ? $balance : 0;
    $return['customer_credit'] = $balance < 0 ? abs($balance) : 0;
}
unset($return);

// Get statistics (including local returns)
$stats = [
    'pending' => $totalPendingCount,
    'approved_today' => 0,
    'total_amount_pending' => 0.0,
    'total_amount_approved_today' => 0.0,
];

// إحصائيات مرتجعات المندوبين
$delegateApprovedToday = (int)$db->queryOne(
    "SELECT COUNT(*) as total
     FROM returns r
     WHERE r.status = 'approved' AND DATE(r.approved_at) = CURDATE()"
)['total'] ?? 0;

$delegateAmountPending = (float)$db->queryOne(
    "SELECT COALESCE(SUM(r.refund_amount), 0) as total
     FROM returns r
     INNER JOIN approvals a ON a.type = 'return_request' AND a.{$entityColumn} = r.id
     WHERE r.status = 'pending' AND a.status = 'pending'"
)['total'] ?? 0;

$delegateAmountApprovedToday = (float)$db->queryOne(
    "SELECT COALESCE(SUM(r.refund_amount), 0) as total
     FROM returns r
     WHERE r.status = 'approved' AND DATE(r.approved_at) = CURDATE()"
)['total'] ?? 0;

$stats['approved_today'] += $delegateApprovedToday;
$stats['total_amount_pending'] += $delegateAmountPending;
$stats['total_amount_approved_today'] += $delegateAmountApprovedToday;

// إحصائيات مرتجعات العملاء المحليين
if (!empty($localReturnsTableExists)) {
    $localApprovedToday = (int)$db->queryOne(
        "SELECT COUNT(*) as total
         FROM local_returns lr
         WHERE lr.status = 'approved' AND DATE(lr.approved_at) = CURDATE()"
    )['total'] ?? 0;
    
    $localAmountPending = (float)$db->queryOne(
        "SELECT COALESCE(SUM(lr.refund_amount), 0) as total
         FROM local_returns lr
         WHERE lr.status = 'pending'"
    )['total'] ?? 0;
    
    $localAmountApprovedToday = (float)$db->queryOne(
        "SELECT COALESCE(SUM(lr.refund_amount), 0) as total
         FROM local_returns lr
         WHERE lr.status = 'approved' AND DATE(lr.approved_at) = CURDATE()"
    )['total'] ?? 0;
    
    $stats['approved_today'] += $localApprovedToday;
    $stats['total_amount_pending'] += $localAmountPending;
    $stats['total_amount_approved_today'] += $localAmountApprovedToday;
}

// جلب العملاء المحليين لبطاقات تسجيل المرتجعات (مرتجع ورقي + مرتجع منتجات)
$localCustomersForReturns = [];
if (!empty($localReturnsTableExists)) {
    $localCustomersForReturns = $db->query("SELECT id, name FROM local_customers ORDER BY name") ?: [];
}

?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-3">
                <i class="bi bi-arrow-return-left me-2"></i>إدارة المرتجعات
            </h2>
        </div>
    </div>

    <!-- بطاقات ثابتة لتسجيل مرتجعات العملاء المحليين -->
    <?php if (!empty($localReturnsTableExists)): ?>
    <script>
    window.returnsPageLocalCustomersList = <?php echo json_encode(array_map(function($c) { return ['id' => (int)$c['id'], 'name' => $c['name']]; }, $localCustomersForReturns)); ?>;
    </script>
    <style>
    .returns-page-autocomplete-dropdown { top: 100%; left: 0; right: 0; margin-top: 2px; border-radius: 0.375rem; border: 1px solid rgba(0,0,0,.125); }
    .returns-page-autocomplete-dropdown .list-group-item { cursor: pointer; font-size: 0.9rem; }
    .returns-page-autocomplete-dropdown .list-group-item:hover { background-color: var(--bs-primary-bg-subtle, #e7f1ff); }
    </style>
    <div class="row mb-4">
        <div class="col-12 col-lg-6 mb-3 mb-lg-0">
            <div class="card shadow-sm border-warning h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-arrow-return-left me-2"></i>مرتجع من فاتورة ورقية</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($localCustomersForReturns)): ?>
                        <div class="alert alert-info small py-2">لا يوجد عملاء محليون. يمكنك إضافتهم من صفحة <a href="?page=local_customers">العملاء المحليين</a>.</div>
                    <?php endif; ?>
                    <p class="text-muted small">اختر العميل ثم ارفع صورة الفاتورة/المرتجع وأدخل رقم الفاتورة ومبلغ المرتجع. يُخصم المبلغ من الرصيد المدين.</p>
                    <input type="hidden" id="returnsPagePaperCustomerId" value="">
                    <div class="mb-3 position-relative">
                        <label class="form-label">العميل <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="returnsPagePaperCustomerInput" placeholder="ابحث بالاسم..." autocomplete="off">
                        <div class="list-group position-absolute shadow-sm returns-page-autocomplete-dropdown" id="returnsPagePaperCustomerDropdown" style="display: none; z-index: 1050; max-height: 220px; overflow-y: auto; width: 100%;"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صورة الفاتورة / المرتجع <span class="text-danger">*</span></label>
                        <input type="file" id="returnsPagePaperImageInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div id="returnsPagePaperImagePreview" class="mt-2 text-center" style="display: none;"><img id="returnsPagePaperPreviewImg" src="" alt="معاينة" class="img-fluid rounded border" style="max-height: 160px;"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الفاتورة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="returnsPagePaperInvoiceNumber" placeholder="رقم الفاتورة المرتجعة">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">مبلغ المرتجع (ج.م) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" id="returnsPagePaperReturnAmount" placeholder="0.00">
                    </div>
                    <div id="returnsPagePaperMessage" class="alert d-none mb-2"></div>
                    <button type="button" class="btn btn-warning w-100" id="returnsPagePaperSubmitBtn" onclick="submitReturnsPagePaperReturn()"><i class="bi bi-check-lg me-1"></i>حفظ وخصم من الرصيد</button>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-success h-100">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-arrow-return-left me-2"></i>مرتجع (إرجاع منتجات)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($localCustomersForReturns)): ?>
                        <div class="alert alert-info small py-2">لا يوجد عملاء محليون. يمكنك إضافتهم من صفحة <a href="?page=local_customers">العملاء المحليين</a>.</div>
                    <?php endif; ?>
                    <p class="text-muted small">اختر العميل ثم أدخل رقم الفاتورة لتحميل المنتجات وتحديد الكميات المراد إرجاعها.</p>
                    <div class="mb-3 position-relative">
                        <label class="form-label">العميل <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="returnsPageLocalCustomerInput" placeholder="ابحث بالاسم..." autocomplete="off">
                        <div class="list-group position-absolute shadow-sm returns-page-autocomplete-dropdown" id="returnsPageLocalCustomerDropdown" style="display: none; z-index: 1050; max-height: 220px; overflow-y: auto; width: 100%;"></div>
                    </div>
                    <input type="hidden" id="returnsPageLocalCustomerId" value="">
                    <div class="card mb-3 border-primary border-2">
                        <div class="card-body py-2">
                            <label class="form-label fw-bold mb-1 text-primary"><i class="bi bi-receipt me-2"></i>رقم الفاتورة</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="returnsPageLocalInvoiceNumber" placeholder="مثال: LOC-202501-0001">
                                <button type="button" class="btn btn-primary" id="returnsPageLocalLoadBtn" onclick="loadReturnsPageLocalInvoice()"><i class="bi bi-search me-1"></i>تحميل</button>
                            </div>
                            <div id="returnsPageLocalInvoiceLoadError" class="alert alert-danger mt-2 d-none small"></div>
                            <div id="returnsPageLocalInvoiceLoading" class="text-center py-2 d-none"><div class="spinner-border spinner-border-sm text-primary"></div><span class="ms-2">جاري التحقق...</span></div>
                        </div>
                    </div>
                    <div class="card mb-3 d-none" id="returnsPageLocalInvoiceInfoCard">
                        <div class="card-body py-2 small">
                            <span class="text-muted">العميل:</span> <strong id="returnsPageLocalCustomerNameDisp">-</strong>
                            &nbsp;|&nbsp;
                            <span class="text-muted">المبلغ الإجمالي للمرتجع:</span> <strong id="returnsPageLocalTotalAmountCard" class="text-danger">0.00 ج.م</strong>
                        </div>
                    </div>
                    <div class="mb-3 d-none" id="returnsPageLocalRefundMethodCard">
                        <label class="form-label fw-bold">طريقة الاسترداد</label>
                        <div class="d-flex gap-3 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="returnsPageLocalRefundMethod" id="returnsPageLocalRefundCredit" value="credit" checked>
                                <label class="form-check-label" for="returnsPageLocalRefundCredit">خصم من الرصيد</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="returnsPageLocalRefundMethod" id="returnsPageLocalRefundCash" value="cash">
                                <label class="form-check-label" for="returnsPageLocalRefundCash">استرداد نقدي</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 d-none" id="returnsPageLocalProductsCard">
                        <label class="form-label fw-bold">المنتجات - حدد الكمية للإرجاع</label>
                        <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>المنتج</th>
                                        <th style="width:90px">المتاح</th>
                                        <th style="width:100px">الكمية</th>
                                        <th style="width:80px">السعر</th>
                                        <th style="width:90px">الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody id="returnsPageLocalItemsList"></tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="4" class="text-end">الإجمالي:</th>
                                        <th class="text-danger" id="returnsPageLocalTotalAmount">0.00 ج.م</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <div class="mb-3 d-none" id="returnsPageLocalNotesCard">
                        <label class="form-label">ملاحظات (اختياري)</label>
                        <textarea class="form-control form-control-sm" id="returnsPageLocalNotes" rows="2" placeholder="ملاحظات..."></textarea>
                    </div>
                    <div id="returnsPageLocalMessage" class="alert d-none mb-2"></div>
                    <button type="button" class="btn btn-success w-100 d-none" id="returnsPageLocalSubmitBtn" onclick="submitReturnsPageLocalReturn()"><i class="bi bi-check-circle me-1"></i>تسجيل المرتجع</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">طلبات معلقة</h6>
                            <h3 class="mb-0 text-warning"><?php echo $stats['pending']; ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2.5rem;">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">معتمدة اليوم</h6>
                            <h3 class="mb-0 text-primary"><?php echo $stats['approved_today']; ?></h3>
                        </div>
                        <div class="text-primary" style="font-size: 2.5rem;">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">مبلغ معلق</h6>
                            <h3 class="mb-0 text-danger"><?php echo number_format($stats['total_amount_pending'], 2); ?> ج.م</h3>
                        </div>
                        <div class="text-danger" style="font-size: 2.5rem;">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">معتمد اليوم</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($stats['total_amount_approved_today'], 2); ?> ج.م</h3>
                        </div>
                        <div class="text-success" style="font-size: 2.5rem;">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Return Requests Section -->
    <div class="row mb-4" id="pending-returns">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        طلبات المرتجعات المعلقة (<?php echo $totalPendingCount; ?>)
                    </h5>
                    <span class="badge bg-light text-dark">يتطلب مراجعة</span>
                </div>
                <div class="card-body">
                    <?php if (empty($pendingReturns)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>لا توجد طلبات مرتجعات معلقة حالياً
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 120px;">رقم المرتجع</th>
                                        <th>النوع</th>
                                        <th>العميل</th>
                                        <th>المندوب</th>
                                        <th>المبلغ</th>
                                        <th>رصيد العميل</th>
                                        <th>المنتجات</th>
                                        <th style="width: 120px;">تاريخ الطلب</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allPendingReturns as $return): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-primary"><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($return['return_type'] === 'local'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-shop me-1"></i>عميل محلي
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary">
                                                        <i class="bi bi-person-badge me-1"></i>مندوب
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($return['customer_name'] ?? 'غير معروف'); ?></strong>
                                                    <?php if (!empty($return['invoice_number'])): ?>
                                                        <br><small class="text-muted">فاتورة: <?php echo htmlspecialchars($return['invoice_number']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($return['return_type'] === 'local'): ?>
                                                    <span class="badge bg-secondary">-</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-person me-1"></i>
                                                        <?php echo htmlspecialchars($return['sales_rep_name'] ?? 'غير معروف'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="text-primary fs-5">
                                                    <?php echo number_format((float)$return['refund_amount'], 2); ?> ج.م
                                                </strong>
                                            </td>
                                            <td>
                                                <?php if ($return['customer_debt'] > 0): ?>
                                                    <span class="badge bg-danger">
                                                        <i class="bi bi-exclamation-circle me-1"></i>
                                                        دين: <?php echo number_format($return['customer_debt'], 2); ?> ج.م
                                                    </span>
                                                <?php elseif ($return['customer_credit'] > 0): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle me-1"></i>
                                                        رصيد: <?php echo number_format($return['customer_credit'], 2); ?> ج.م
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">صفر</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php 
                                                    $itemCount = count($return['items']);
                                                    $displayedItems = array_slice($return['items'], 0, 2);
                                                    foreach ($displayedItems as $item): 
                                                    ?>
                                                        <div class="mb-1">
                                                            <span class="badge bg-light text-dark">
                                                                <?php echo htmlspecialchars($item['product_name'] ?? 'غير معروف'); ?>
                                                                (<?php echo number_format((float)$item['quantity'], 2); ?>)
                                                                <?php if (!empty($item['batch_number'])): ?>
                                                                    <br><small class="text-muted">تشغيلة: <?php echo htmlspecialchars($item['batch_number']); ?></small>
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if ($itemCount > 2): ?>
                                                        <small class="text-muted">+ <?php echo ($itemCount - 2); ?> منتج آخر</small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <small><?php echo date('Y-m-d H:i', strtotime($return['request_date'])); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($totalPendingPages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-3">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $pendingPageNum <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&pending_p=<?php echo $pendingPageNum - 1; ?>#pending-returns">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $startPage = max(1, $pendingPageNum - 2);
                                    $endPage = min($totalPendingPages, $pendingPageNum + 2);
                                    
                                    if ($startPage > 1): ?>
                                        <li class="page-item"><a class="page-link" href="?page=returns&pending_p=1#pending-returns">1</a></li>
                                        <?php if ($startPage > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <li class="page-item <?php echo $i == $pendingPageNum ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=returns&pending_p=<?php echo $i; ?>#pending-returns"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($endPage < $totalPendingPages): ?>
                                        <?php if ($endPage < $totalPendingPages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item"><a class="page-link" href="?page=returns&pending_p=<?php echo $totalPendingPages; ?>#pending-returns"><?php echo $totalPendingPages; ?></a></li>
                                    <?php endif; ?>
                                    
                                    <li class="page-item <?php echo $pendingPageNum >= $totalPendingPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&pending_p=<?php echo $pendingPageNum + 1; ?>#pending-returns">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Latest Return Operations Section -->
    <div class="row" id="latest-returns">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history me-2"></i>
                        آخر عمليات المرتجعات (<?php echo $totalLatestCount; ?>)
                    </h5>
                    <span class="badge bg-light text-dark">سجل العمليات</span>
                </div>
                <div class="card-body">
                    <?php if (empty($latestReturns)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>لا توجد عمليات مرتجعات سابقة
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 120px;">رقم المرتجع</th>
                                        <th>النوع</th>
                                        <th>العميل</th>
                                        <th>المندوب</th>
                                        <th>المبلغ</th>
                                        <th>الحالة</th>
                                        <th>المعتمد بواسطة</th>
                                        <th>المنتجات</th>
                                        <th style="width: 120px;">التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allLatestReturns as $return): ?>
                                        <?php
                                        $statusClass = '';
                                        $statusText = '';
                                        $statusIcon = '';
                                        switch ($return['status']) {
                                            case 'approved':
                                                $statusClass = 'success';
                                                $statusText = 'معتمد';
                                                $statusIcon = 'check-circle';
                                                break;
                                            case 'rejected':
                                                $statusClass = 'danger';
                                                $statusText = 'مرفوض';
                                                $statusIcon = 'x-circle';
                                                break;
                                            case 'processed':
                                            case 'completed':
                                                $statusClass = 'info';
                                                $statusText = 'مكتمل';
                                                $statusIcon = 'check-all';
                                                break;
                                            default:
                                                $statusClass = 'secondary';
                                                $statusText = $return['status'];
                                                $statusIcon = 'question-circle';
                                        }
                                        $actionDate = $return['approved_at'] ?? $return['updated_at'] ?? $return['created_at'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong class="text-primary"><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($return['return_type'] === 'local'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-shop me-1"></i>عميل محلي
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary">
                                                        <i class="bi bi-person-badge me-1"></i>مندوب
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($return['customer_name'] ?? 'غير معروف'); ?></strong>
                                                    <?php if (!empty($return['invoice_number'])): ?>
                                                        <br><small class="text-muted">فاتورة: <?php echo htmlspecialchars($return['invoice_number']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($return['return_type'] === 'local'): ?>
                                                    <span class="badge bg-secondary">-</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-person me-1"></i>
                                                        <?php echo htmlspecialchars($return['sales_rep_name'] ?? 'غير معروف'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="text-primary">
                                                    <?php echo number_format((float)$return['refund_amount'], 2); ?> ج.م
                                                </strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $statusClass; ?>">
                                                    <i class="bi bi-<?php echo $statusIcon; ?> me-1"></i>
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($return['approved_by_name'])): ?>
                                                    <small><?php echo htmlspecialchars($return['approved_by_name']); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php 
                                                    $itemCount = count($return['items']);
                                                    $displayedItems = array_slice($return['items'], 0, 2);
                                                    foreach ($displayedItems as $item): 
                                                    ?>
                                                        <div class="mb-1">
                                                            <span class="badge bg-light text-dark">
                                                                <?php echo htmlspecialchars($item['product_name'] ?? 'غير معروف'); ?>
                                                                (<?php echo number_format((float)$item['quantity'], 2); ?>)
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if ($itemCount > 2): ?>
                                                        <small class="text-muted">+ <?php echo ($itemCount - 2); ?> منتج آخر</small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <small><?php echo date('Y-m-d H:i', strtotime($actionDate)); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($totalLatestPages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-3">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $latestPageNum <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&latest_p=<?php echo $latestPageNum - 1; ?>#latest-returns">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $startPage = max(1, $latestPageNum - 2);
                                    $endPage = min($totalLatestPages, $latestPageNum + 2);
                                    
                                    if ($startPage > 1): ?>
                                        <li class="page-item"><a class="page-link" href="?page=returns&latest_p=1#latest-returns">1</a></li>
                                        <?php if ($startPage > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <li class="page-item <?php echo $i == $latestPageNum ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=returns&latest_p=<?php echo $i; ?>#latest-returns"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($endPage < $totalLatestPages): ?>
                                        <?php if ($endPage < $totalLatestPages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item"><a class="page-link" href="?page=returns&latest_p=<?php echo $totalLatestPages; ?>#latest-returns"><?php echo $totalLatestPages; ?></a></li>
                                    <?php endif; ?>
                                    
                                    <li class="page-item <?php echo $latestPageNum >= $totalLatestPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&latest_p=<?php echo $latestPageNum + 1; ?>#latest-returns">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<!-- Modal للكمبيوتر فقط - تفاصيل المرتجع -->
<div class="modal fade d-none d-md-block" id="returnDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل طلب المرتجع</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="returnDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
            </div>
        </div>
    </div>
</div>

<!-- Card للموبايل - تفاصيل المرتجع -->
<div class="card shadow-sm mb-4 d-md-none" id="returnDetailsCard" style="display: none;">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">تفاصيل طلب المرتجع</h5>
    </div>
    <div class="card-body" id="returnDetailsCardContent">
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">جاري التحميل...</span>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <button type="button" class="btn btn-secondary" onclick="closeReturnDetailsCard()">إغلاق</button>
    </div>
</div>

<script>
// ===== دوال أساسية =====

function isMobile() {
    return window.innerWidth <= 768;
}

function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80;
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, elementTop - offset),
                behavior: 'smooth'
            });
        });
    }, 200);
}

function closeAllForms() {
    const cards = ['returnDetailsCard'];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
        }
    });
    
    const modals = ['returnDetailsModal'];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// ===== دوال إغلاق Cards =====

function closeReturnDetailsCard() {
    const card = document.getElementById('returnDetailsCard');
    if (card) {
        card.style.display = 'none';
    }
}

const basePath = '<?php echo $basePath; ?>';

// ----- حقول البحث التلقائي عن العميل (صفحة المرتجعات) -----
function initReturnsPageCustomerAutocomplete(inputId, hiddenId, dropdownId) {
    var list = window.returnsPageLocalCustomersList || [];
    var input = document.getElementById(inputId);
    var hidden = document.getElementById(hiddenId);
    var dropdown = document.getElementById(dropdownId);
    if (!input || !hidden || !dropdown) return;
    var hideTimer = null;
    function showDropdown(items) {
        dropdown.innerHTML = '';
        if (items.length === 0) {
            dropdown.style.display = 'none';
            return;
        }
        items.slice(0, 15).forEach(function(c) {
            var a = document.createElement('a');
            a.href = '#';
            a.className = 'list-group-item list-group-item-action py-2';
            a.textContent = c.name;
            a.dataset.id = String(c.id);
            a.dataset.name = c.name;
            a.addEventListener('click', function(e) {
                e.preventDefault();
                input.value = c.name;
                hidden.value = String(c.id);
                dropdown.style.display = 'none';
            });
            dropdown.appendChild(a);
        });
        dropdown.style.display = 'block';
    }
    function filterList(q) {
        q = (q || '').trim().toLowerCase();
        if (q.length === 0) return list.slice(0, 15);
        return list.filter(function(c) { return (c.name || '').toLowerCase().indexOf(q) !== -1; });
    }
    input.addEventListener('input', function() {
        if (hideTimer) clearTimeout(hideTimer);
        var q = input.value.trim();
        if (q.length === 0) { hidden.value = ''; }
        showDropdown(filterList(q));
    });
    input.addEventListener('focus', function() {
        if (hideTimer) clearTimeout(hideTimer);
        showDropdown(filterList(input.value));
    });
    input.addEventListener('blur', function() {
        hideTimer = setTimeout(function() { dropdown.style.display = 'none'; }, 200);
    });
    dropdown.addEventListener('mousedown', function(e) { e.preventDefault(); }); // منع blur قبل النقر
}
if (window.returnsPageLocalCustomersList && window.returnsPageLocalCustomersList.length > 0) {
    (function runAutocompleteInit() {
        if (document.getElementById('returnsPagePaperCustomerInput')) {
            initReturnsPageCustomerAutocomplete('returnsPagePaperCustomerInput', 'returnsPagePaperCustomerId', 'returnsPagePaperCustomerDropdown');
            initReturnsPageCustomerAutocomplete('returnsPageLocalCustomerInput', 'returnsPageLocalCustomerId', 'returnsPageLocalCustomerDropdown');
        } else {
            setTimeout(runAutocompleteInit, 50);
        }
    })();
}

// ----- بطاقة مرتجع من فاتورة ورقية (صفحة المرتجعات) -----
(function() {
    var paperFileInput = document.getElementById('returnsPagePaperImageInput');
    var paperPreview = document.getElementById('returnsPagePaperImagePreview');
    var paperPreviewImg = document.getElementById('returnsPagePaperPreviewImg');
    if (paperFileInput && paperPreview && paperPreviewImg) {
        paperFileInput.addEventListener('change', function() {
            var file = this.files && this.files[0];
            if (file && file.type.indexOf('image') !== -1) {
                var reader = new FileReader();
                reader.onload = function(e) { paperPreviewImg.src = e.target.result; paperPreview.style.display = 'block'; };
                reader.readAsDataURL(file);
            } else {
                paperPreview.style.display = 'none';
                paperPreviewImg.src = '';
            }
        });
    }
})();
function submitReturnsPagePaperReturn() {
    var customerIdEl = document.getElementById('returnsPagePaperCustomerId');
    var customerId = (customerIdEl && customerIdEl.value) ? customerIdEl.value.trim() : '';
    var invoiceNumber = (document.getElementById('returnsPagePaperInvoiceNumber') && document.getElementById('returnsPagePaperInvoiceNumber').value) ? document.getElementById('returnsPagePaperInvoiceNumber').value.trim() : '';
    var amountEl = document.getElementById('returnsPagePaperReturnAmount');
    var returnAmount = amountEl ? amountEl.value.replace(',', '.').trim() : '';
    var fileInput = document.getElementById('returnsPagePaperImageInput');
    var file = fileInput && fileInput.files && fileInput.files[0];
    var msgEl = document.getElementById('returnsPagePaperMessage');
    var submitBtn = document.getElementById('returnsPagePaperSubmitBtn');
    if (!customerId) { alert('يرجى اختيار العميل'); return; }
    if (!invoiceNumber) { alert('يرجى إدخال رقم الفاتورة'); return; }
    if (!returnAmount || isNaN(parseFloat(returnAmount)) || parseFloat(returnAmount) <= 0) { alert('يرجى إدخال مبلغ مرتجع صحيح'); return; }
    if (!file) { alert('يرجى اختيار صورة الفاتورة/المرتجع'); return; }
    if (msgEl) { msgEl.classList.add('d-none'); msgEl.innerHTML = ''; }
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...'; }
    var formData = new FormData();
    formData.append('action', 'save');
    formData.append('customer_id', customerId);
    formData.append('invoice_number', invoiceNumber);
    formData.append('return_amount', returnAmount);
    formData.append('image', file);
    fetch(basePath + '/api/local_paper_invoice_return.php', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (msgEl) { msgEl.classList.remove('d-none'); msgEl.className = data.success ? 'alert alert-success mb-2' : 'alert alert-danger mb-2'; msgEl.textContent = data.message || (data.success ? 'تم الحفظ.' : 'حدث خطأ.'); }
            if (data.success) {
                document.getElementById('returnsPagePaperInvoiceNumber').value = '';
                document.getElementById('returnsPagePaperReturnAmount').value = '';
                if (fileInput) fileInput.value = '';
                var p = document.getElementById('returnsPagePaperImagePreview');
                if (p) p.style.display = 'none';
                setTimeout(function() { location.reload(); }, 1500);
            }
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ وخصم من الرصيد'; }
        })
        .catch(function() { if (msgEl) { msgEl.classList.remove('d-none'); msgEl.className = 'alert alert-danger mb-2'; msgEl.textContent = 'حدث خطأ في الاتصال.'; } if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ وخصم من الرصيد'; } });
}

// ----- بطاقة مرتجع منتجات (صفحة المرتجعات) -----
var returnsPageLocalLoadedData = null;
function loadReturnsPageLocalInvoice() {
    var customerIdEl = document.getElementById('returnsPageLocalCustomerId');
    var customerInput = document.getElementById('returnsPageLocalCustomerInput');
    var customerId = customerIdEl ? customerIdEl.value : '';
    var customerName = (customerInput && customerInput.value) ? customerInput.value.trim() : '';
    if (!customerId) { alert('يرجى اختيار العميل من نتائج البحث'); return; }
    var invoiceNumber = (document.getElementById('returnsPageLocalInvoiceNumber') && document.getElementById('returnsPageLocalInvoiceNumber').value) ? document.getElementById('returnsPageLocalInvoiceNumber').value.trim() : '';
    if (!invoiceNumber) { alert('يرجى إدخال رقم الفاتورة'); return; }
    var loadError = document.getElementById('returnsPageLocalInvoiceLoadError');
    var loadSpinner = document.getElementById('returnsPageLocalInvoiceLoading');
    var loadBtn = document.getElementById('returnsPageLocalLoadBtn');
    if (loadError) { loadError.classList.add('d-none'); loadError.textContent = ''; }
    if (loadSpinner) loadSpinner.classList.remove('d-none');
    if (loadBtn) loadBtn.disabled = true;
    var apiBaseUrl = basePath + '/api/customer_purchase_history.php';
    var url = apiBaseUrl + (apiBaseUrl.indexOf('?') !== -1 ? '&' : '?') + 'action=get_invoice_by_number&customer_id=' + encodeURIComponent(customerId) + '&invoice_number=' + encodeURIComponent(invoiceNumber) + '&type=local';
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function(r) { return r.text().then(function(text) { var data; try { data = text ? JSON.parse(text) : {}; } catch (e) { data = {}; } if (!r.ok) { if (loadError) { loadError.textContent = (data && data.message) ? data.message : 'الفاتورة غير موجودة أو غير مرتبطة بهذا العميل'; loadError.classList.remove('d-none'); } return Promise.reject(new Error('load failed')); } return data; }); })
        .then(function(data) {
            if (loadSpinner) loadSpinner.classList.add('d-none');
            if (loadBtn) loadBtn.disabled = false;
            if (!data || !data.success) {
                if (loadError) { loadError.textContent = (data && data.message) ? data.message : 'الفاتورة غير موجودة أو غير مرتبطة بهذا العميل'; loadError.classList.remove('d-none'); }
                return;
            }
            if (loadError) { loadError.classList.add('d-none'); loadError.textContent = ''; }
            returnsPageLocalLoadedData = data;
            document.getElementById('returnsPageLocalInvoiceInfoCard').classList.remove('d-none');
            document.getElementById('returnsPageLocalCustomerNameDisp').textContent = customerName || '-';
            document.getElementById('returnsPageLocalRefundMethodCard').classList.remove('d-none');
            document.getElementById('returnsPageLocalProductsCard').classList.remove('d-none');
            document.getElementById('returnsPageLocalNotesCard').classList.remove('d-none');
            document.getElementById('returnsPageLocalSubmitBtn').classList.remove('d-none');
            renderReturnsPageLocalItems();
        })
        .catch(function() {
            if (loadSpinner) loadSpinner.classList.add('d-none');
            if (loadBtn) loadBtn.disabled = false;
            if (loadError && loadError.classList.contains('d-none')) { loadError.textContent = 'حدث خطأ أثناء التحقق من الفاتورة'; loadError.classList.remove('d-none'); }
        });
}
function renderReturnsPageLocalItems() {
    var tbody = document.getElementById('returnsPageLocalItemsList');
    if (!tbody || !returnsPageLocalLoadedData || !returnsPageLocalLoadedData.items) return;
    var items = returnsPageLocalLoadedData.items.filter(function(it) { return it.can_return; });
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted small">لا يوجد منتجات متاحة للإرجاع</td></tr>';
        document.getElementById('returnsPageLocalTotalAmount').textContent = '0.00 ج.م';
        document.getElementById('returnsPageLocalTotalAmountCard').textContent = '0.00 ج.م';
        return;
    }
    tbody.innerHTML = '';
    items.forEach(function(item, idx) {
        var maxQty = item.available_to_return;
        var row = document.createElement('tr');
        row.className = 'align-middle';
        row.innerHTML = '<td><strong class="small">' + (item.product_name || '-') + '</strong></td>' +
            '<td class="text-center small">' + maxQty.toFixed(2) + '</td>' +
            '<td><input type="number" class="form-control form-control-sm returns-page-local-qty text-center" data-invoice-item-id="' + item.invoice_item_id + '" data-unit-price="' + item.unit_price + '" data-max="' + maxQty + '" value="0" min="0" max="' + maxQty + '" step="0.01" style="max-width:90px"></td>' +
            '<td class="text-end small">' + item.unit_price.toFixed(2) + '</td>' +
            '<td class="text-end small returns-page-local-line-total">0.00 ج.م</td>';
        tbody.appendChild(row);
    });
    document.getElementById('returnsPageLocalTotalAmount').textContent = '0.00 ج.م';
    document.getElementById('returnsPageLocalTotalAmountCard').textContent = '0.00 ج.م';
    tbody.querySelectorAll('.returns-page-local-qty').forEach(function(input) {
        input.addEventListener('input', returnsPageLocalRecalcTotal);
        input.addEventListener('change', returnsPageLocalRecalcTotal);
    });
}
function returnsPageLocalRecalcTotal() {
    var total = 0;
    document.querySelectorAll('#returnsPageLocalItemsList tr').forEach(function(tr) {
        var qtyInput = tr.querySelector('.returns-page-local-qty');
        var lineTotal = tr.querySelector('.returns-page-local-line-total');
        if (!qtyInput || !lineTotal) return;
        var qty = parseFloat(qtyInput.value) || 0;
        var unitPrice = parseFloat(qtyInput.getAttribute('data-unit-price')) || 0;
        var maxQty = parseFloat(qtyInput.getAttribute('data-max')) || 0;
        if (qty > maxQty) { qtyInput.value = maxQty; qty = maxQty; }
        if (qty < 0) { qtyInput.value = 0; qty = 0; }
        var line = qty * unitPrice;
        lineTotal.textContent = line.toFixed(2) + ' ج.م';
        total += line;
    });
    var totalEl = document.getElementById('returnsPageLocalTotalAmount');
    var cardEl = document.getElementById('returnsPageLocalTotalAmountCard');
    if (totalEl) totalEl.textContent = total.toFixed(2) + ' ج.م';
    if (cardEl) cardEl.textContent = total.toFixed(2) + ' ج.م';
}
function submitReturnsPageLocalReturn() {
    var customerIdEl = document.getElementById('returnsPageLocalCustomerId');
    var customerId = customerIdEl ? customerIdEl.value : '';
    if (!customerId) { alert('يرجى اختيار العميل من نتائج البحث'); return; }
    var items = [];
    document.querySelectorAll('#returnsPageLocalItemsList tr').forEach(function(tr) {
        var qtyInput = tr.querySelector('.returns-page-local-qty');
        if (!qtyInput) return;
        var qty = parseFloat(qtyInput.value) || 0;
        if (qty <= 0) return;
        items.push({
            invoice_item_id: parseInt(qtyInput.getAttribute('data-invoice-item-id'), 10),
            quantity: qty
        });
    });
    if (items.length === 0) { alert('يرجى تحديد كمية مرتجعة لمنتج واحد على الأقل'); return; }
    var refundMethod = document.querySelector('input[name="returnsPageLocalRefundMethod"]:checked');
    refundMethod = refundMethod ? refundMethod.value : 'credit';
    var notes = (document.getElementById('returnsPageLocalNotes') && document.getElementById('returnsPageLocalNotes').value) ? document.getElementById('returnsPageLocalNotes').value.trim() : '';
    var submitBtn = document.getElementById('returnsPageLocalSubmitBtn');
    var msgEl = document.getElementById('returnsPageLocalMessage');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري المعالجة...'; }
    if (msgEl) { msgEl.classList.add('d-none'); msgEl.textContent = ''; }
    fetch(basePath + '/api/local_returns.php?action=create_return', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ customer_id: parseInt(customerId, 10), items: items, refund_method: refundMethod, notes: notes })
    })
        .then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) return r.text().then(function(t) { throw new Error('استجابة غير صحيحة'); });
            return r.json();
        })
        .then(function(data) {
            if (data.success) {
                if (msgEl) { msgEl.classList.remove('d-none'); msgEl.className = 'alert alert-success mb-2'; msgEl.textContent = 'تم تسجيل المرتجع بنجاح. رقم المرتجع: ' + (data.return_number || ''); }
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                if (msgEl) { msgEl.classList.remove('d-none'); msgEl.className = 'alert alert-danger mb-2'; msgEl.textContent = data.message || 'حدث خطأ'; }
                if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تسجيل المرتجع'; }
            }
        })
        .catch(function(err) {
            if (msgEl) { msgEl.classList.remove('d-none'); msgEl.className = 'alert alert-danger mb-2'; msgEl.textContent = 'حدث خطأ في الاتصال: ' + (err.message || ''); }
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تسجيل المرتجع'; }
        });
}

function approveReturn(returnId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    if (!confirm('هل أنت متأكد من الموافقة على طلب المرتجع؟')) {
        return;
    }
    
    const btn = event ? event.target.closest('button') : null;
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    fetch(basePath + '/api/returns.php?action=approve', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            notes: ''
        })
    })
    .then(response => {
        console.log('Response Status:', response.status);
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Expected JSON but got:', contentType, text.substring(0, 500));
                throw new Error('استجابة غير صحيحة من الخادم');
            });
        }
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
            }).catch(() => {
                throw new Error('حدث خطأ في الطلب: ' + response.status);
            });
        }
        return response.json();
    })
    .then(data => {
        console.log('Response Data:', data);
        if (data.success) {
            let successMsg = '✅ تمت الموافقة على طلب المرتجع بنجاح!\n\n';
            if (data.financial_note) {
                successMsg += '📊 التفاصيل المالية:\n' + data.financial_note + '\n\n';
            }
            if (data.items_returned && data.items_returned > 0) {
                successMsg += '📦 تم إرجاع ' + data.items_returned + ' منتج(ات) إلى مخزن السيارة\n\n';
            }
            if (data.return_number) {
                successMsg += '🔢 رقم المرتجع: ' + data.return_number;
            }
            alert(successMsg);
            location.reload();
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error approving return:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ: ' + (error.message || 'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.'));
    });
}

function rejectReturn(returnId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    const notes = prompt('يرجى إدخال سبب الرفض (اختياري):');
    if (notes === null) {
        return; // User cancelled
    }
    
    const btn = event ? event.target.closest('button') : null;
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    fetch(basePath + '/api/returns.php?action=reject', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            notes: notes || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تم رفض الطلب بنجاح');
            location.reload();
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error rejecting return:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    });
}

function viewReturnDetails(returnId, type) {
    closeAllForms();
    
    const content = isMobile() ? document.getElementById('returnDetailsCardContent') : document.getElementById('returnDetailsContent');
    
    if (!content) return;
    
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    
    if (isMobile()) {
        const card = document.getElementById('returnDetailsCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = new bootstrap.Modal(document.getElementById('returnDetailsModal'));
        modal.show();
    }
    
    // Fetch return details
    fetch(basePath + '/api/return_requests.php?action=get_return_details&return_id=' + returnId, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.return) {
            const ret = data.return;
            let html = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>رقم المرتجع:</strong> ${ret.return_number || '-'}
                    </div>
                    <div class="col-md-6">
                        <strong>التاريخ:</strong> ${ret.return_date || '-'}
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>العميل:</strong> ${ret.customer_name || '-'}
                    </div>
                    <div class="col-md-6">
                        <strong>المندوب:</strong> ${ret.sales_rep_name || '-'}
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>المبلغ:</strong> <span class="text-primary">${parseFloat(ret.refund_amount || 0).toFixed(2)} ج.م</span>
                    </div>
                    <div class="col-md-6">
                        <strong>الحالة:</strong> <span class="badge bg-${ret.status === 'approved' ? 'success' : ret.status === 'rejected' ? 'danger' : 'warning'}">${ret.status || '-'}</span>
                    </div>
                </div>
                <hr>
                <h6>المنتجات:</h6>
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>رقم التشغيلة</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            if (ret.items && ret.items.length > 0) {
                ret.items.forEach(item => {
                    html += `
                        <tr>
                            <td>${item.product_name || '-'}</td>
                            <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
                            <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
                            <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
                            <td>${item.batch_number || '-'}</td>
                        </tr>
                    `;
                });
            } else {
                html += '<tr><td colspan="5" class="text-center">لا توجد منتجات</td></tr>';
            }
            
            html += `
                    </tbody>
                </table>
            `;
            
            if (ret.notes) {
                html += `<hr><strong>ملاحظات:</strong><p>${ret.notes}</p>`;
            }
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div class="alert alert-warning">لا يمكن تحميل تفاصيل المرتجع</div>';
        }
    })
    .catch(error => {
        console.error('Error fetching return details:', error);
        content.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل التفاصيل</div>';
    });
}
</script>


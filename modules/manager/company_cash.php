<?php
/**
 * صفحة خزنة الشركة - نسخة من صفحة المعاملات المالية للمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$db = db();

// التأكد من وجود جدول accountant_transactions
function ensureAccountantTransactionsTable() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    
    try {
        $db = db();
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
        if (empty($tableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `accountant_transactions` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `transaction_type` enum('collection_from_sales_rep','expense','income','transfer','payment','other') NOT NULL COMMENT 'نوع المعاملة',
                  `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ',
                  `sales_rep_id` int(11) DEFAULT NULL COMMENT 'معرف المندوب (للتحصيل)',
                  `description` text NOT NULL COMMENT 'الوصف',
                  `reference_number` varchar(50) DEFAULT NULL COMMENT 'رقم مرجعي',
                  `payment_method` enum('cash','bank_transfer','check','other') DEFAULT 'cash' COMMENT 'طريقة الدفع',
                  `status` enum('pending','approved','rejected') DEFAULT 'approved' COMMENT 'الحالة',
                  `approved_by` int(11) DEFAULT NULL COMMENT 'من وافق',
                  `approved_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
                  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
                  `created_by` int(11) NOT NULL COMMENT 'من أنشأ السجل',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
                  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
                  PRIMARY KEY (`id`),
                  KEY `transaction_type` (`transaction_type`),
                  KEY `sales_rep_id` (`sales_rep_id`),
                  KEY `status` (`status`),
                  KEY `created_by` (`created_by`),
                  KEY `approved_by` (`approved_by`),
                  KEY `created_at` (`created_at`),
                  KEY `reference_number` (`reference_number`),
                  CONSTRAINT `accountant_transactions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                  CONSTRAINT `accountant_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `accountant_transactions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المعاملات المحاسبية'
            ");
        } else {
            // تحديث enum إذا كان الجدول موجوداً ولكن لا يحتوي على 'payment'
            try {
                $columnInfo = $db->queryOne("SHOW COLUMNS FROM accountant_transactions WHERE Field = 'transaction_type'");
                if (!empty($columnInfo) && stripos($columnInfo['Type'], "'payment'") === false) {
                    $db->execute("
                        ALTER TABLE `accountant_transactions` 
                        MODIFY COLUMN `transaction_type` enum('collection_from_sales_rep','expense','income','transfer','payment','other') NOT NULL COMMENT 'نوع المعاملة'
                    ");
                    error_log('Updated accountant_transactions.transaction_type enum to include payment');
                }
            } catch (Throwable $updateError) {
                error_log('Error updating accountant_transactions enum: ' . $updateError->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('Error creating accountant_transactions table: ' . $e->getMessage());
    }
}

// التأكد من وجود جدول financial_transactions (لتجنب تعطّل الصفحة عند غيابه على السيرفر)
function ensureFinancialTransactionsTable() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $db = db();
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'financial_transactions'");
        if (empty($tableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `financial_transactions` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `type` enum('expense','income','transfer','payment') NOT NULL,
                  `amount` decimal(15,2) NOT NULL,
                  `supplier_id` int(11) DEFAULT NULL,
                  `description` text NOT NULL,
                  `reference_number` varchar(50) DEFAULT NULL,
                  `status` enum('pending','approved','rejected') DEFAULT 'pending',
                  `approved_by` int(11) DEFAULT NULL,
                  `created_by` int(11) NOT NULL,
                  `approved_at` timestamp NULL DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `supplier_id` (`supplier_id`),
                  KEY `created_by` (`created_by`),
                  KEY `approved_by` (`approved_by`),
                  KEY `status` (`status`),
                  KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (Throwable $e) {
        error_log('Error ensuring financial_transactions table: ' . $e->getMessage());
    }
}

// التأكد من وجود الجداول
ensureAccountantTransactionsTable();
ensureFinancialTransactionsTable();

// معالجة AJAX لجلب رصيد المندوب
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_sales_rep_balance') {
    // تعطيل عرض الأخطاء في المتصفح
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    
    // تنظيف أي output buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // بدء output buffering جديد
    ob_start();
    
    // إرسال headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    
    $response = ['success' => false, 'message' => ''];
    $salesRepId = isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : 0;
    
    try {
        if ($salesRepId <= 0) {
            $response['message'] = 'معرف المندوب غير صحيح';
        } else {
            // التأكد من تحميل الدالة
            if (!function_exists('calculateSalesRepCashBalance')) {
                require_once __DIR__ . '/../../includes/approval_system.php';
            }
            
            $balance = calculateSalesRepCashBalance($salesRepId);
            
            $salesRep = $db->queryOne(
                "SELECT id, username, full_name FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                [$salesRepId]
            );
            
            if (empty($salesRep)) {
                $response['message'] = 'المندوب غير موجود أو غير نشط';
            } else {
                $response = [
                    'success' => true,
                    'balance' => floatval($balance),
                    'sales_rep_name' => htmlspecialchars($salesRep['full_name'] ?? $salesRep['username'], ENT_QUOTES, 'UTF-8')
                ];
            }
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        $errorTrace = $e->getTraceAsString();
        error_log('Error getting sales rep balance [ID: ' . $salesRepId . ']: ' . $errorMessage . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine() . ' | Trace: ' . $errorTrace);
        $response['message'] = 'حدث خطأ أثناء جلب رصيد المندوب. يرجى المحاولة مرة أخرى.';
        // إضافة تفاصيل الخطأ للتصحيح (احذف هذا بعد الإصلاح)
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $response['debug_error'] = $errorMessage;
            $response['debug_file'] = $e->getFile();
            $response['debug_line'] = $e->getLine();
        }
    }
    
    // تنظيف output buffer وإرسال JSON
    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    ob_end_flush();
    exit;
}

/**
 * إرجاع HTML جدول المعاملات المالية مع الترقيم (للاستخدام العادي ولطلبات AJAX)
 */
function getCompanyCashTransactionsHtml($db) {
    $searchType = isset($_GET['search_type']) && $_GET['search_type'] !== '' ? $_GET['search_type'] : null;
    $searchStatus = isset($_GET['search_status']) && $_GET['search_status'] !== '' ? $_GET['search_status'] : null;
    $searchDateFrom = isset($_GET['search_date_from']) && $_GET['search_date_from'] !== '' ? $_GET['search_date_from'] : null;
    $searchDateTo = isset($_GET['search_date_to']) && $_GET['search_date_to'] !== '' ? $_GET['search_date_to'] : null;
    $searchAmountFrom = isset($_GET['search_amount_from']) && $_GET['search_amount_from'] !== '' ? floatval($_GET['search_amount_from']) : null;
    $searchAmountTo = isset($_GET['search_amount_to']) && $_GET['search_amount_to'] !== '' ? floatval($_GET['search_amount_to']) : null;
    $searchDescription = isset($_GET['search_description']) && $_GET['search_description'] !== '' ? trim($_GET['search_description']) : null;
    $searchReference = isset($_GET['search_reference']) && $_GET['search_reference'] !== '' ? trim($_GET['search_reference']) : null;
    $searchCreatedBy = isset($_GET['search_created_by']) && $_GET['search_created_by'] !== '' ? intval($_GET['search_created_by']) : null;
    $searchApprovedBy = isset($_GET['search_approved_by']) && $_GET['search_approved_by'] !== '' ? $_GET['search_approved_by'] : null;

    $whereConditions = [];
    $queryParams = [];

    if ($searchType !== null) {
        $whereConditions[] = "combined.type = ?";
        $queryParams[] = $searchType;
    }
    if ($searchStatus !== null) {
        $whereConditions[] = "combined.status = ?";
        $queryParams[] = $searchStatus;
    }
    if ($searchDateFrom !== null) {
        $whereConditions[] = "DATE(combined.created_at) >= ?";
        $queryParams[] = $searchDateFrom;
    }
    if ($searchDateTo !== null) {
        $whereConditions[] = "DATE(combined.created_at) <= ?";
        $queryParams[] = $searchDateTo;
    }
    if ($searchAmountFrom !== null) {
        $whereConditions[] = "combined.amount >= ?";
        $queryParams[] = $searchAmountFrom;
    }
    if ($searchAmountTo !== null) {
        $whereConditions[] = "combined.amount <= ?";
        $queryParams[] = $searchAmountTo;
    }
    if ($searchDescription !== null) {
        $whereConditions[] = "combined.description LIKE ?";
        $queryParams[] = '%' . $searchDescription . '%';
    }
    if ($searchReference !== null) {
        $whereConditions[] = "combined.reference_number LIKE ?";
        $queryParams[] = '%' . $searchReference . '%';
    }
    if ($searchCreatedBy !== null) {
        $whereConditions[] = "combined.created_by = ?";
        $queryParams[] = $searchCreatedBy;
    }
    if ($searchApprovedBy !== null) {
        if ($searchApprovedBy === 'null') {
            $whereConditions[] = "combined.approved_by IS NULL";
        } else {
            $whereConditions[] = "combined.approved_by = ?";
            $queryParams[] = intval($searchApprovedBy);
        }
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $perPage = 6;
    $offset = ($pageNum - 1) * $perPage;

    $countQuery = "
        SELECT COUNT(*) as total
        FROM (
            SELECT id, type, amount, description, reference_number, status, created_by, approved_by, created_at
            FROM financial_transactions
            UNION ALL
            SELECT id,
                CASE
                    WHEN transaction_type = 'collection_from_sales_rep' THEN 'income'
                    WHEN transaction_type = 'expense' THEN 'expense'
                    WHEN transaction_type = 'income' THEN 'income'
                    WHEN transaction_type = 'transfer' THEN 'transfer'
                    WHEN transaction_type = 'payment' THEN 'payment'
                    ELSE 'other'
                END as type,
                amount, description, reference_number, status, created_by, approved_by, created_at
            FROM accountant_transactions
        ) as combined
        $whereClause
    ";
    $totalCountResult = $db->queryOne($countQuery, $queryParams);
    $totalCount = (int)($totalCountResult['total'] ?? 0);
    $totalPages = ceil($totalCount / $perPage);

    $dataQuery = "
        SELECT combined.*, u1.full_name as created_by_name, u2.full_name as approved_by_name
        FROM (
            SELECT id, type, amount, description, reference_number, status, created_by, approved_by, created_at,
                NULL as transaction_type, 'financial_transactions' as source_table
            FROM financial_transactions
            UNION ALL
            SELECT id,
                CASE
                    WHEN transaction_type = 'collection_from_sales_rep' THEN 'income'
                    WHEN transaction_type = 'expense' THEN 'expense'
                    WHEN transaction_type = 'income' THEN 'income'
                    WHEN transaction_type = 'transfer' THEN 'transfer'
                    WHEN transaction_type = 'payment' THEN 'payment'
                    ELSE 'other'
                END as type,
                amount, description, reference_number, status, created_by, approved_by, created_at,
                transaction_type, 'accountant_transactions' as source_table
            FROM accountant_transactions
        ) as combined
        LEFT JOIN users u1 ON combined.created_by = u1.id
        LEFT JOIN users u2 ON combined.approved_by = u2.id
        $whereClause
        ORDER BY combined.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $queryParams[] = $perPage;
    $queryParams[] = $offset;
    $financialTransactions = $db->query($dataQuery, $queryParams) ?: [];

    $typeLabels = ['income' => 'إيراد', 'expense' => 'مصروف', 'transfer' => 'تحويل', 'payment' => 'دفعة', 'other' => 'أخرى'];
    $statusLabels = ['pending' => 'معلق', 'approved' => 'معتمد', 'rejected' => 'مرفوض'];
    $statusColors = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];

    ob_start();
    ?>
    <div class="table-responsive">
        <table class="table table-hover table-striped">
            <thead class="table-light">
                <tr>
                    <th>التاريخ</th>
                    <th>النوع</th>
                    <th>المبلغ</th>
                    <th>الوصف</th>
                    <th>الرقم المرجعي</th>
                    <th>الحالة</th>
                    <th>أنشأه</th>
                    <th>إجراءات</th>
                    <th>اعتمده</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($financialTransactions)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            <i class="bi bi-inbox me-2"></i>لا توجد حركات مالية حالياً
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($financialTransactions as $trans): ?>
                        <?php
                        $rowClass = $trans['type'] === 'expense' ? 'table-danger' : ($trans['type'] === 'income' ? 'table-success' : '');
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td><?php echo formatDateTime($trans['created_at']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $trans['type'] === 'income' ? 'success' : ($trans['type'] === 'expense' ? 'danger' : 'info'); ?>">
                                    <?php echo htmlspecialchars($typeLabels[$trans['type']] ?? $trans['type'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="fw-bold <?php echo $trans['type'] === 'income' ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $trans['type'] === 'income' ? '+' : '-'; ?><?php echo formatCurrency($trans['amount']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($trans['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($trans['reference_number']): ?>
                                    <span class="text-muted small"><?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $statusColors[$trans['status']] ?? 'secondary'; ?>">
                                    <?php echo htmlspecialchars($statusLabels[$trans['status']] ?? $trans['status'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($trans['created_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php
                                $isCollectionFromSalesRep = (
                                    ($trans['source_table'] ?? '') === 'accountant_transactions' &&
                                    ($trans['type'] ?? '') === 'income' &&
                                    ($trans['transaction_type'] ?? '') === 'collection_from_sales_rep'
                                );
                                if ($isCollectionFromSalesRep):
                                    $printUrl = getRelativeUrl('print_collection_receipt.php?id=' . $trans['id']);
                                ?>
                                    <a href="<?php echo htmlspecialchars($printUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="طباعة فاتورة التحصيل">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($trans['approved_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <?php
    $searchParams = [];
    if ($searchType !== null) $searchParams['search_type'] = $searchType;
    if ($searchStatus !== null) $searchParams['search_status'] = $searchStatus;
    if ($searchDateFrom !== null) $searchParams['search_date_from'] = $searchDateFrom;
    if ($searchDateTo !== null) $searchParams['search_date_to'] = $searchDateTo;
    if ($searchAmountFrom !== null) $searchParams['search_amount_from'] = $searchAmountFrom;
    if ($searchAmountTo !== null) $searchParams['search_amount_to'] = $searchAmountTo;
    if ($searchDescription !== null) $searchParams['search_description'] = $searchDescription;
    if ($searchReference !== null) $searchParams['search_reference'] = $searchReference;
    if ($searchCreatedBy !== null) $searchParams['search_created_by'] = $searchCreatedBy;
    if ($searchApprovedBy !== null) $searchParams['search_approved_by'] = $searchApprovedBy;
    $baseUrl = '?page=company_cash';
    $searchQueryString = !empty($searchParams) ? '&' . http_build_query($searchParams) : '';
    ?>
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center flex-wrap">
            <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo $baseUrl . ($pageNum > 1 ? '&p=' . ($pageNum - 1) : '') . $searchQueryString; ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
            <?php $startPage = max(1, $pageNum - 2); $endPage = min($totalPages, $pageNum + 2); ?>
            <?php if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&p=1' . $searchQueryString; ?>">1</a></li>
                <?php if ($startPage > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseUrl . '&p=' . $i . $searchQueryString; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?php echo $baseUrl . '&p=' . $totalPages . $searchQueryString; ?>"><?php echo $totalPages; ?></a></li>
            <?php endif; ?>
            <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo $baseUrl . '&p=' . ($pageNum + 1) . $searchQueryString; ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
        </ul>
        <div class="text-center text-muted small mt-2">
            عرض <?php echo number_format(($pageNum - 1) * $perPage + 1); ?> - <?php echo number_format(min($pageNum * $perPage, $totalCount)); ?> من أصل <?php echo number_format($totalCount); ?> حركة
            <?php if (!empty($searchParams)): ?>
                <span class="badge bg-info ms-2">نتائج البحث</span>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// طلب AJAX لجلب جدول المعاملات فقط (تنقل سلس بين الصفحات بدون ريفريش)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'transactions_list') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode(['html' => getCompanyCashTransactionsHtml($db)], JSON_UNESCAPED_UNICODE);
    exit;
}

$financialSuccess = '';
$financialError = '';
$financialFormData = [];

if (isset($_SESSION['financial_success'])) {
    $financialSuccess = $_SESSION['financial_success'];
    unset($_SESSION['financial_success']);
}
if (isset($_SESSION['financial_error'])) {
    $financialError = $_SESSION['financial_error'];
    unset($_SESSION['financial_error']);
}
if (isset($_SESSION['financial_form_data'])) {
    $financialFormData = $_SESSION['financial_form_data'];
    unset($_SESSION['financial_form_data']);
}

// معالجة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'collect_from_sales_rep') {
        $salesRepId = isset($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : 0;
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        if ($salesRepId <= 0) {
            $_SESSION['financial_error'] = 'يرجى اختيار مندوب صحيح.';
        } elseif ($amount <= 0) {
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
        } else {
            try {
                require_once __DIR__ . '/../../includes/approval_system.php';
                $currentBalance = calculateSalesRepCashBalance($salesRepId);
                
                if ($amount > $currentBalance) {
                    $_SESSION['financial_error'] = 'المبلغ المطلوب (' . formatCurrency($amount) . ') أكبر من رصيد المندوب (' . formatCurrency($currentBalance) . ').';
                } else {
                    $db->beginTransaction();
                    
                    // الحصول على بيانات المندوب
                    $salesRep = $db->queryOne(
                        "SELECT id, username, full_name FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                        [$salesRepId]
                    );
                    
                    if (empty($salesRep)) {
                        throw new Exception('المندوب غير موجود أو غير نشط');
                    }
                    
                    $salesRepName = $salesRep['full_name'] ?? $salesRep['username'];
                    $finalDescription = 'تحصيل من مندوب: ' . $salesRepName;
                    $referenceNumber = 'COL-REP-' . $salesRepId . '-' . date('YmdHis');
                    
                    // إضافة تحصيل في جدول accountant_transactions
                    $db->execute(
                        "INSERT INTO accountant_transactions (transaction_type, amount, sales_rep_id, description, reference_number, status, approved_by, created_by, approved_at)
                         VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, NOW())",
                        [
                            'collection_from_sales_rep',
                            $amount,
                            $salesRepId,
                            $finalDescription,
                            $referenceNumber,
                            $currentUser['id'],
                            $currentUser['id']
                        ]
                    );
                    
                    $accountantTransactionId = $db->getLastInsertId();
                    $transactionId = $accountantTransactionId;
                    
                    logAudit(
                        $currentUser['id'],
                        'collect_from_sales_rep',
                        'financial_transaction',
                        $transactionId,
                        null,
                        [
                            'sales_rep_id' => $salesRepId,
                            'sales_rep_name' => $salesRepName,
                            'amount' => $amount,
                        ]
                    );
                    
                    // إرسال إشعار للمندوب
                    try {
                        $collectorName = $currentUser['full_name'] ?? $currentUser['username'];
                        $notificationTitle = 'تحصيل من خزنتك';
                        $notificationMessage = 'تم تحصيل مبلغ ' . formatCurrency($amount) . ' من رصيد خزنتك من قبل ' . htmlspecialchars($collectorName) . ' - رقم المرجع: ' . $referenceNumber;
                        $notificationLink = getRelativeUrl('dashboard/sales.php?page=cash_register');
                        
                        createNotification(
                            $salesRepId,
                            $notificationTitle,
                            $notificationMessage,
                            'warning',
                            $notificationLink,
                            true // إرسال Telegram
                        );
                    } catch (Throwable $notifError) {
                        // لا نوقف العملية إذا فشل الإشعار
                        error_log('Failed to send notification to sales rep: ' . $notifError->getMessage());
                    }
                    
                    $db->commit();
                    
                    // حفظ معرف المعاملة في الجلسة للطباعة
                    $_SESSION['last_collection_transaction_id'] = $accountantTransactionId;
                    $_SESSION['financial_success'] = 'تم تحصيل ' . formatCurrency($amount) . ' من مندوب: ' . htmlspecialchars($salesRepName) . ' بنجاح.';
                    $_SESSION['last_collection_print_link'] = getRelativeUrl('print_collection_receipt.php?id=' . $accountantTransactionId);
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Collect from sales rep failed: ' . $e->getMessage());
                $_SESSION['financial_error'] = 'حدث خطأ أثناء التحصيل: ' . $e->getMessage();
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

    if ($action === 'add_quick_expense') {
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $description = trim($_POST['description'] ?? '');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        
        // تحديد دور المستخدم
        $userRole = strtolower($currentUser['role'] ?? '');
        $isManager = ($userRole === 'manager');
        $isAccountant = ($userRole === 'accountant');
        
        // إذا كان المدير أو المحاسب، المصروف معتمد تلقائياً
        if ($isManager || $isAccountant) {
            $markAsApproved = true; // المدير والمحاسب يعتمدان المصروف تلقائياً
        } else {
            $markAsApproved = isset($_POST['mark_as_approved']);
        }

        $_SESSION['financial_form_data'] = [
            'amount' => $_POST['amount'] ?? '',
            'description' => $description,
            'reference_number' => $referenceNumber,
            'mark_as_approved' => $markAsApproved ? '1' : '0',
        ];

        if ($amount <= 0) {
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ مصروف صحيح.';
        } else {
            try {
                // إذا كان المدير أو المحاسب، المصروف معتمد تلقائياً
                if ($isManager || $isAccountant) {
                    $status = 'approved';
                    $approvedBy = $currentUser['id'];
                    $approvedAt = date('Y-m-d H:i:s');
                } else {
                    // المستخدمون الآخرون: يعتمد على الاختيار
                    $status = $markAsApproved ? 'approved' : 'pending';
                    $approvedBy = $markAsApproved ? $currentUser['id'] : null;
                    $approvedAt = $markAsApproved ? date('Y-m-d H:i:s') : null;
                }

                $db->execute(
                    "INSERT INTO financial_transactions (type, amount, supplier_id, description, reference_number, status, approved_by, created_by, approved_at)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)",
                    [
                        'expense',
                        $amount,
                        $description,
                        $referenceNumber !== '' ? $referenceNumber : null,
                        $status,
                        $approvedBy,
                        $currentUser['id'],
                        $approvedAt
                    ]
                );

                $transactionId = $db->getLastInsertId();

                // إذا كانت الحالة pending، إرسال طلب موافقة للمدير
                // (لا يحدث هذا للمدير أو المحاسب لأنهما يعتمدان تلقائياً)
                if ($status === 'pending') {
                    $approvalNotes = sprintf(
                        "مصروف سريع\nالمبلغ: %s ج.م\nالوصف: %s%s",
                        formatCurrency($amount),
                        $description,
                        $referenceNumber !== '' ? "\nالرقم المرجعي: " . $referenceNumber : ''
                    );
                    
                    $approvalResult = requestApproval('financial', $transactionId, $currentUser['id'], $approvalNotes);
                    
                    if (!$approvalResult['success']) {
                        error_log('Failed to create approval request for expense: ' . ($approvalResult['message'] ?? 'Unknown error'));
                    }
                }

                logAudit(
                    $currentUser['id'],
                    'quick_expense_create',
                    'financial_transaction',
                    $transactionId,
                    null,
                    [
                        'amount' => $amount,
                        'status' => $status,
                        'reference' => $referenceNumber !== '' ? $referenceNumber : null
                    ]
                );

                unset($_SESSION['financial_form_data']);

                // رسالة النجاح
                if ($isManager || $isAccountant) {
                    $_SESSION['financial_success'] = 'تم تسجيل المصروف واعتماده تلقائياً.';
                } else {
                    $_SESSION['financial_success'] = $markAsApproved
                        ? 'تم تسجيل المصروف واعتماده فوراً.'
                        : 'تم تسجيل المصروف وإرساله للاعتماد.';
                }
            } catch (Throwable $e) {
                error_log('Quick expense insertion failed: ' . $e->getMessage());
                $_SESSION['financial_error'] = 'حدث خطأ أثناء تسجيل المصروف. حاول مرة أخرى.';
            }
        }

        // إعداد URL مع مسح الكاش بعد تسجيل المصروف
        $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
        $urlParts = parse_url($redirectTarget);
        $queryParams = [];
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParams);
        }
        
        // إزالة معاملات cache القديمة
        unset($queryParams['_nocache'], $queryParams['_refresh'], $queryParams['_cache_bust'], 
              $queryParams['_t'], $queryParams['_r'], $queryParams['_auto_refresh']);
        
        // إضافة timestamp جديد لمسح الكاش
        $queryParams['_nocache'] = time() * 1000 + rand(0, 999);
        
        // بناء URL جديد
        $redirectTarget = $urlParts['path'] ?? '';
        if (!empty($queryParams)) {
            $redirectTarget .= '?' . http_build_query($queryParams);
        }
        if (isset($urlParts['fragment'])) {
            $redirectTarget .= '#' . $urlParts['fragment'];
        }
        
        if (!headers_sent()) {
            header('Location: ' . $redirectTarget);
        } else {
            echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
        }
        exit;
    }
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['menu_financial']) ? $lang['menu_financial'] : 'خزنة الشركة';
?>

<link rel="stylesheet" href="<?php echo getRelativeUrl('assets/css/responsive-modals.css'); ?>">

<!-- صفحة الخزنة -->
<div class="page-header mb-4">
    <h2><i class="bi bi-safe me-2"></i><?php echo isset($lang['menu_financial']) ? $lang['menu_financial'] : 'خزنة الشركة'; ?></h2>
</div>

<?php if ($financialError): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($financialError, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($financialSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($financialSuccess, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="d-flex align-items-center gap-2" style="background:rgb(30, 124, 30); padding: 6px 12px; border-radius: 7px;">
            <?php if (!empty($_SESSION['last_collection_print_link'])): ?>
                <a href="<?php echo htmlspecialchars($_SESSION['last_collection_print_link'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-printer me-1"></i>طباعة فاتورة التحصيل
                </a>
                <?php unset($_SESSION['last_collection_print_link']); ?>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>

<!-- Cards للموبايل - يجب أن تكون في بداية الصفحة بعد الرسائل -->


<?php
$company_cash_error = null;
try {
// حساب ملخص الخزينة من financial_transactions و accountant_transactions
$treasurySummary = $db->queryOne("
    SELECT
        (SELECT COALESCE(SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('collection_from_sales_rep', 'income') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_income,
        (SELECT COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND status = 'approved' 
            AND (description NOT LIKE '%سلفة%' AND description NOT LIKE '%سلف%')
            AND description NOT LIKE '%تسوية رصيد دائن ل%'
            THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_expense,
        (SELECT COALESCE(SUM(CASE WHEN type = 'transfer' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'transfer' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_transfer,
        (SELECT COALESCE(SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' AND status = 'approved' AND (description NOT LIKE '%تسوية راتب%' OR description IS NULL) THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_payment,
        (SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS pending_total
");

// حساب المعاملات المعلقة
$pendingStats = $db->queryOne("
    SELECT 
        (SELECT COUNT(*) FROM financial_transactions WHERE status = 'pending') +
        (SELECT COUNT(*) FROM accountant_transactions WHERE status = 'pending') AS total_pending,
        (SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE status = 'pending') +
        (SELECT COALESCE(SUM(amount), 0) FROM accountant_transactions WHERE status = 'pending') AS pending_amount
");

$pendingTransactionsRaw = $db->query("
    SELECT id, type, amount, description, created_at 
    FROM financial_transactions
    WHERE status = 'pending'
    ORDER BY created_at DESC
    LIMIT 5
");
$pendingTransactions = is_array($pendingTransactionsRaw) ? $pendingTransactionsRaw : [];

// حساب إجمالي المرتبات
$totalSalaries = 0.0;
$salariesTableExists = $db->queryOne("SHOW TABLES LIKE 'salaries'");
if (!empty($salariesTableExists)) {
    $salariesResult = $db->queryOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total_salaries
         FROM salaries
         WHERE status IN ('approved', 'paid')"
    );
    $totalSalaries = (float) ($salariesResult['total_salaries'] ?? 0);
}

// حساب إجمالي تسويات المرتبات (يشمل التسويات والسلف)
$totalSalaryAdjustments = 0.0;
$accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
if (!empty($accountantTableExists)) {
    $adjustmentsResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total_adjustments
         FROM accountant_transactions
         WHERE status = 'approved'
         AND (
             (transaction_type = 'payment' AND description LIKE '%تسوية راتب%')
             OR (transaction_type = 'expense' AND (description LIKE '%سلفة%' OR description LIKE '%سلف%'))
         )"
    );
    $totalSalaryAdjustments = (float) ($adjustmentsResult['total_adjustments'] ?? 0);
}

// حساب إجمالي تسويات أرصدة العملاء
$totalCustomerCreditSettlements = 0.0;
if (!empty($accountantTableExists)) {
    $customerSettlementsResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total_settlements
         FROM accountant_transactions
         WHERE transaction_type = 'expense' 
         AND status = 'approved'
         AND (description LIKE '%تسوية رصيد دائن لعميل محلي%' OR description LIKE '%تسوية رصيد دائن لعميل مندوب%')"
    );
    $totalCustomerCreditSettlements = (float) ($customerSettlementsResult['total_settlements'] ?? 0);
}

// حساب إجمالي توريدات الإدارة
$totalManagementSupplies = 0.0;
if (!empty($accountantTableExists)) {
    $managementSuppliesResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total_supplies
         FROM accountant_transactions
         WHERE transaction_type = 'income' 
         AND status = 'approved'
         AND description LIKE '%تحصيل للإدارة%'"
    );
    $totalManagementSupplies = (float) ($managementSuppliesResult['total_supplies'] ?? 0);
}

$netApprovedBalance = 
    ($treasurySummary['approved_income'] ?? 0) 
    - ($treasurySummary['approved_expense'] ?? 0)
    - ($treasurySummary['approved_payment'] ?? 0)
    - $totalSalaries
    - $totalSalaryAdjustments
    - $totalCustomerCreditSettlements
    - $totalManagementSupplies;

$approvedIncome = (float) ($treasurySummary['approved_income'] ?? 0);
$approvedExpense = (float) ($treasurySummary['approved_expense'] ?? 0);
$approvedPayment = (float) ($treasurySummary['approved_payment'] ?? 0);

$movementTotal = $approvedIncome + $approvedExpense + $approvedPayment + $totalSalaries + $totalSalaryAdjustments + $totalCustomerCreditSettlements + $totalManagementSupplies;
$shareDenominator = $movementTotal > 0 ? $movementTotal : 1;
$incomeShare = $shareDenominator > 0 ? round(($approvedIncome / $shareDenominator) * 100) : 0;
$expenseShare = $shareDenominator > 0 ? round(($approvedExpense / $shareDenominator) * 100) : 0;
$paymentShare = $shareDenominator > 0 ? round(($approvedPayment / $shareDenominator) * 100) : 0;
$salariesShare = $shareDenominator > 0 ? round(($totalSalaries / $shareDenominator) * 100) : 0;
$adjustmentsShare = $shareDenominator > 0 ? round(($totalSalaryAdjustments / $shareDenominator) * 100) : 0;
$customerSettlementsShare = $shareDenominator > 0 ? round(($totalCustomerCreditSettlements / $shareDenominator) * 100) : 0;
$managementSuppliesShare = $shareDenominator > 0 ? round(($totalManagementSupplies / $shareDenominator) * 100) : 0;
$pendingCount = intval($pendingStats['total_pending'] ?? 0);
$pendingAmount = (float) ($pendingStats['pending_amount'] ?? 0);
$pendingPreview = array_slice($pendingTransactions, 0, 3);

$typeLabelMap = [
    'income' => $lang['income'] ?? 'إيراد',
    'expense' => $lang['expense'] ?? 'مصروف',
    'transfer' => isset($lang['transfer']) ? $lang['transfer'] : 'تحويل',
    'payment' => isset($lang['payment']) ? $lang['payment'] : 'دفعة'
];

$typeColorMap = [
    'income' => 'success',
    'expense' => 'danger',
    'transfer' => 'primary',
    'payment' => 'warning'
];
?>

<div class="row g-3 mt-4">
    <div class="col-12 col-xxl-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-graph-up-arrow me-2 text-primary"></i>ملخص الخزنة</span>
                <span class="badge bg-primary text-white">محدّث</span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <span class="text-muted text-uppercase small">صافي الرصيد المعتمد</span>
                        <div class="display-6 fw-bold mt-1"><?php echo formatCurrency($netApprovedBalance); ?></div>
                    </div>
                    <div class="text-end">
                        <div class="badge bg-success text-white fw-semibold px-3 py-2">
                            <?php echo formatCurrency($approvedIncome); ?> إيرادات
                        </div>
                    </div>
                </div>
                <div class="row g-3 mt-3">
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">إيرادات معتمدة</span>
                                <i class="bi bi-arrow-up-right-circle text-success"></i>
                            </div>
                            <div class="h5 text-success mt-2"><?php echo formatCurrency($approvedIncome); ?></div>
                            <div class="progress mt-3" style="height: 6px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo max(0, min(100, $incomeShare)); ?>%;"></div>
                            </div>
                            <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $incomeShare)); ?>% من إجمالي الحركة</small>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">مصروفات معتمدة</span>
                                <i class="bi bi-arrow-down-right-circle text-danger"></i>
                            </div>
                            <div class="h5 text-danger mt-2"><?php echo formatCurrency($approvedExpense); ?></div>
                            <div class="progress mt-3" style="height: 6px;">
                                <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo max(0, min(100, $expenseShare)); ?>%;"></div>
                            </div>
                            <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $expenseShare)); ?>% من إجمالي الحركة</small>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">مدفوعات الموردين</span>
                                <i class="bi bi-credit-card-2-back text-warning"></i>
                            </div>
                            <div class="h5 text-warning mt-2"><?php echo formatCurrency($approvedPayment); ?></div>
                            <div class="progress mt-3" style="height: 6px;">
                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo max(0, min(100, $paymentShare)); ?>%;"></div>
                            </div>
                            <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $paymentShare)); ?>% من إجمالي الحركة</small>
                        </div>
                    </div>
                    
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">تسويات المرتبات</span>
                                <i class="bi bi-currency-exchange text-info"></i>
                            </div>
                            <div class="h5 text-info mt-2"><?php echo formatCurrency($totalSalaryAdjustments); ?></div>
                            <div class="progress mt-3" style="height: 6px;">
                                <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo max(0, min(100, $adjustmentsShare)); ?>%;"></div>
                            </div>
                            <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $adjustmentsShare)); ?>% من إجمالي الحركة</small>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">تسويات أرصدة العملاء</span>
                                <i class="bi bi-wallet2 text-secondary"></i>
                            </div>
                            <div class="h5 text-secondary mt-2"><?php echo formatCurrency($totalCustomerCreditSettlements); ?></div>
                            <div class="progress mt-3" style="height: 6px;">
                                <div class="progress-bar bg-secondary" role="progressbar" style="width: <?php echo max(0, min(100, $customerSettlementsShare)); ?>%;"></div>
                            </div>
                            <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $customerSettlementsShare)); ?>% من إجمالي الحركة</small>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">توريدات للإدارة</span>
                                <i class="bi bi-building text-dark"></i>
                            </div>
                            <div class="h5 text-dark mt-2"><?php echo formatCurrency($totalManagementSupplies); ?></div>
                            <div class="progress mt-3" style="height: 6px;">
                                <div class="progress-bar bg-dark" role="progressbar" style="width: <?php echo max(0, min(100, $managementSuppliesShare)); ?>%;"></div>
                            </div>
                            <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $managementSuppliesShare)); ?>% من إجمالي الحركة</small>
                        </div>
                    </div>
                </div>
               
            </div>
        </div>
    </div>
    <div class="col-12 col-xxl-5">
        <div class="row g-3">
            <!-- تسجيل مصروف سريع -->
            <div class="col-12 col-lg-12 col-xxl-12">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-pencil-square me-2 text-success"></i>تسجيل مصروف سريع
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="add_quick_expense">
                            <div class="col-12">
                                <label for="quickExpenseAmount" class="form-label">قيمة المصروف <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="quickExpenseAmount" name="amount" required value="<?php echo htmlspecialchars($financialFormData['amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="quickExpenseReference" class="form-label">رقم مرجعي</label>
                                <?php
                                $generatedRef = 'REF-' . mt_rand(100000, 999999);?>
                                <input type="text" class="form-control" id="quickExpenseReference" name="reference_number" value="<?php echo $generatedRef; ?>" readonly style="background:#f5f5f5; cursor:not-allowed;">
                            </div>
                            <div class="col-12">
                                <label for="quickExpenseDescription" class="form-label">وصف المصروف <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="quickExpenseDescription" name="description" rows="3" required placeholder="أدخل تفاصيل المصروف..."><?php echo htmlspecialchars($financialFormData['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <?php
                            // إخفاء خيار الاعتماد للمدير والمحاسب (كلاهما يعتمد تلقائياً)
                            $userRole = strtolower($currentUser['role'] ?? '');
                            $isManager = ($userRole === 'manager');
                            $isAccountant = ($userRole === 'accountant');
                            
                            if (!$isManager && !$isAccountant): // عرض الخيار فقط للمستخدمين الآخرين
                            ?>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="quickExpenseApproved" name="mark_as_approved" value="1" <?php echo isset($financialFormData['mark_as_approved']) && $financialFormData['mark_as_approved'] === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="quickExpenseApproved">
                                        اعتماد المعاملة فوراً (يُستخدم عند تسجيل مصروف مؤكد)
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">إذا تُرك غير محدد فسيتم إرسال المصروف للموافقة لاحقاً.</small>
                            </div>
                            <?php endif; ?>
                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="reset" class="btn btn-outline-secondary">تفريغ الحقول</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-send me-1"></i>حفظ المصروف
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- تحصيل من مندوب -->
            <div class="col-12 col-lg-12 col-xxl-12">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-cash-coin me-2 text-primary"></i>تحصيل من مندوب
                    </div>
                    <div class="card-body">
                        <form method="POST" id="collectFromRepCardForm" class="row g-3">
                            <input type="hidden" name="action" value="collect_from_sales_rep">
                            <div class="col-12">
                                <label for="collectFromRepCardSalesRepSelect" class="form-label">اختر المندوب <span class="text-danger">*</span></label>
                                <select class="form-select" id="collectFromRepCardSalesRepSelect" name="sales_rep_id" required>
                                    <option value="">-- اختر المندوب --</option>
                                    <?php
                                    $salesReps = $db->query("
                                        SELECT id, username, full_name 
                                        FROM users 
                                        WHERE role = 'sales' AND status = 'active'
                                        ORDER BY full_name ASC, username ASC
                                    ") ?: [];
                                    foreach ($salesReps as $rep):
                                    ?>
                                        <option value="<?php echo $rep['id']; ?>">
                                            <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="collectFromRepCardRepBalanceAmount" class="form-label">رصيد المندوب</label>
                                <div class="input-group">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="text" class="form-control" id="collectFromRepCardRepBalanceAmount" readonly value="-- اختر مندوب أولاً --" style="background:#f5f5f5; cursor:not-allowed; font-weight: bold;">
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="collectFromRepCardAmount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="collectFromRepCardAmount" name="amount" required placeholder="أدخل المبلغ">
                                </div>
                                <small class="text-muted d-block mt-1">يجب أن يكون المبلغ أقل من أو يساوي رصيد المندوب</small>
                            </div>
                            
                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="reset" class="btn btn-outline-secondary">تفريغ الحقول</button>
                                <button type="submit" class="btn btn-primary" id="collectFromRepCardSubmitBtn">
                                    <i class="bi bi-check-circle me-1"></i>تحصيل
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- إنشاء تقرير تفصيلي -->
            <div class="col-12 col-lg-12 col-xxl-12">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-file-earmark-text me-2 text-success"></i>إنشاء تقرير تفصيلي
                    </div>
                    <div class="card-body">
                        <form method="GET" id="generateReportCardForm" onsubmit="return handleReportCardSubmit(event)" class="row g-3">
                            <div class="col-12">
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <small><strong>ملاحظة:</strong> سيتم إنشاء تقرير تفصيلي لجميع حركات خزنة الشركة في الفترة المحددة.</small>
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="generateReportCardDateFrom" class="form-label">
                                    <i class="bi bi-calendar-event me-1"></i>من تاريخ <span class="text-danger">*</span>
                                </label>
                                <input type="date" 
                                       class="form-control" 
                                       id="generateReportCardDateFrom" 
                                       name="date_from" 
                                       required
                                       value="<?php echo date('Y-m-01'); ?>">
                            </div>
                            <div class="col-12">
                                <label for="generateReportCardDateTo" class="form-label">
                                    <i class="bi bi-calendar-event me-1"></i>إلى تاريخ <span class="text-danger">*</span>
                                </label>
                                <input type="date" 
                                       class="form-control" 
                                       id="generateReportCardDateTo" 
                                       name="date_to" 
                                       required
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="generateReportCardIncludePending" name="include_pending" value="1">
                                    <label class="form-check-label" for="generateReportCardIncludePending">
                                        تضمين المعاملات المعلقة
                                    </label>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="generateReportCardGroupByType" name="group_by_type" value="1" checked>
                                    <label class="form-check-label" for="generateReportCardGroupByType">
                                        تجميع الحركات حسب النوع
                                    </label>
                                </div>
                            </div>
                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>إنشاء التقرير
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- جدول الحركات المالية -->
<div class="card shadow-sm mt-4">
    <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2 text-primary"></i>الحركات المالية</span>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#advancedSearchCollapse" aria-expanded="false" aria-controls="advancedSearchCollapse">
            <i class="bi bi-funnel me-1"></i>بحث متقدم
        </button>
    </div>
    <div class="card-body">
        <!-- نموذج البحث المتقدم -->
        <div class="collapse mb-4" id="advancedSearchCollapse">
            <div class="card card-body bg-light">
                <form method="GET" action="" id="advancedSearchForm">
                    <input type="hidden" name="page" value="company_cash">
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchType" class="form-label">نوع الحركة</label>
                            <select class="form-select" id="searchType" name="search_type">
                                <option value="">جميع الأنواع</option>
                                <option value="income" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'income') ? 'selected' : ''; ?>>إيراد</option>
                                <option value="expense" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'expense') ? 'selected' : ''; ?>>مصروف</option>
                                <option value="transfer" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'transfer') ? 'selected' : ''; ?>>تحويل</option>
                                <option value="payment" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'payment') ? 'selected' : ''; ?>>دفعة</option>
                                <option value="other" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'other') ? 'selected' : ''; ?>>أخرى</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchStatus" class="form-label">الحالة</label>
                            <select class="form-select" id="searchStatus" name="search_status">
                                <option value="">جميع الحالات</option>
                                <option value="pending" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] === 'pending') ? 'selected' : ''; ?>>معلق</option>
                                <option value="approved" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] === 'approved') ? 'selected' : ''; ?>>معتمد</option>
                                <option value="rejected" <?php echo (isset($_GET['search_status']) && $_GET['search_status'] === 'rejected') ? 'selected' : ''; ?>>مرفوض</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchDateFrom" class="form-label">من تاريخ</label>
                            <input type="date" class="form-control" id="searchDateFrom" name="search_date_from" value="<?php echo htmlspecialchars($_GET['search_date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchDateTo" class="form-label">إلى تاريخ</label>
                            <input type="date" class="form-control" id="searchDateTo" name="search_date_to" value="<?php echo htmlspecialchars($_GET['search_date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchAmountFrom" class="form-label">من مبلغ</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="searchAmountFrom" name="search_amount_from" placeholder="0.00" value="<?php echo htmlspecialchars($_GET['search_amount_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchAmountTo" class="form-label">إلى مبلغ</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="searchAmountTo" name="search_amount_to" placeholder="0.00" value="<?php echo htmlspecialchars($_GET['search_amount_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchDescription" class="form-label">الوصف</label>
                            <input type="text" class="form-control" id="searchDescription" name="search_description" placeholder="ابحث في الوصف..." value="<?php echo htmlspecialchars($_GET['search_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchReference" class="form-label">الرقم المرجعي</label>
                            <input type="text" class="form-control" id="searchReference" name="search_reference" placeholder="ابحث في الرقم المرجعي..." value="<?php echo htmlspecialchars($_GET['search_reference'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchCreatedBy" class="form-label">أنشأه</label>
                            <select class="form-select" id="searchCreatedBy" name="search_created_by">
                                <option value="">الجميع</option>
                                <?php
                                $allUsers = $db->query("SELECT id, full_name, username FROM users WHERE status = 'active' ORDER BY full_name ASC, username ASC") ?: [];
                                foreach ($allUsers as $user):
                                    $selected = (isset($_GET['search_created_by']) && $_GET['search_created_by'] == $user['id']) ? 'selected' : '';
                                    $displayName = htmlspecialchars($user['full_name'] ?? $user['username'], ENT_QUOTES, 'UTF-8');
                                ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo $selected; ?>><?php echo $displayName; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label for="searchApprovedBy" class="form-label">اعتمده</label>
                            <select class="form-select" id="searchApprovedBy" name="search_approved_by">
                                <option value="">الجميع</option>
                                <option value="null" <?php echo (isset($_GET['search_approved_by']) && $_GET['search_approved_by'] === 'null') ? 'selected' : ''; ?>>غير معتمد</option>
                                <?php
                                foreach ($allUsers as $user):
                                    $selected = (isset($_GET['search_approved_by']) && $_GET['search_approved_by'] == $user['id']) ? 'selected' : '';
                                    $displayName = htmlspecialchars($user['full_name'] ?? $user['username'], ENT_QUOTES, 'UTF-8');
                                ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo $selected; ?>><?php echo $displayName; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search me-1"></i>بحث
                                </button>
                                <a href="?page=company_cash" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1"></i>إعادة تعيين
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div id="company-cash-transactions-list">
        <?php echo getCompanyCashTransactionsHtml($db); ?>
        </div>
    </div>
</div>

<!-- تم حذف المودالات - Cards أصبحت ثابتة دائماً ظاهرة -->

<script>
// ===== دوال أساسية للـ Cards =====

// دالة لإغلاق جميع النماذج المفتوحة
function closeAllForms() {
    // إغلاق جميع Modals على الكمبيوتر
    const modals = ['collectFromRepModal', 'generateReportModal'];
    
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
    
    // إزالة backdrop
    const backdrops = document.querySelectorAll('.modal-backdrop');
    backdrops.forEach(function(backdrop) {
        backdrop.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
}

// ملاحظة: تم إزالة دوال showCollectFromRepModal و showGenerateReportModal
// لأن Cards أصبحت ثابتة دائماً ظاهرة ولا تحتاج إلى أزرار

// معالجة إرسال نموذج التقرير من Card
function handleReportCardSubmit(event) {
    event.preventDefault();
    
    const form = document.getElementById('generateReportCardForm');
    if (!form) return false;
    
    const dateFrom = document.getElementById('generateReportCardDateFrom');
    const dateTo = document.getElementById('generateReportCardDateTo');
    
    if (!dateFrom || !dateTo) return false;
    
    const fromDate = new Date(dateFrom.value);
    const toDate = new Date(dateTo.value);
    
    if (fromDate > toDate) {
        alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
        dateFrom.focus();
        return false;
    }
    
    // بناء URL للتقرير
    const origin = window.location.origin;
    const currentPath = window.location.pathname;
    
    let basePath = currentPath;
    basePath = basePath.replace(/\/dashboard\/[^\/]+\.php.*$/, '');
    basePath = basePath.replace(/\/modules\/[^\/]+\/[^\/]+\.php.*$/, '');
    basePath = basePath.replace(/\/$/, '');
    if (!basePath) {
        basePath = '';
    }
    
    const reportUrl = origin + basePath + '/print_company_cash_report.php';
    
    // جمع معاملات النموذج
    const formData = new FormData(form);
    const params = new URLSearchParams();
    
    for (const [key, value] of formData.entries()) {
        params.append(key, value);
    }
    
    const includePending = document.getElementById('generateReportCardIncludePending');
    const groupByType = document.getElementById('generateReportCardGroupByType');
    
    if (!includePending.checked) {
        params.delete('include_pending');
    }
    if (!groupByType.checked) {
        params.delete('group_by_type');
    }
    
    // فتح التقرير في تبويب جديد
    const fullUrl = reportUrl + '?' + params.toString();
    window.open(fullUrl, '_blank');
    
    // إعادة تعيين النموذج بعد فتح التقرير
    if (form) {
        form.reset();
        // إعادة تعيين القيم الافتراضية
        const dateFrom = document.getElementById('generateReportCardDateFrom');
        const dateTo = document.getElementById('generateReportCardDateTo');
        const groupByType = document.getElementById('generateReportCardGroupByType');
        if (dateFrom) dateFrom.value = '<?php echo date('Y-m-01'); ?>';
        if (dateTo) dateTo.value = '<?php echo date('Y-m-d'); ?>';
        if (groupByType) groupByType.checked = true;
    }
    
    return false;
}

// تم حذف handleReportSubmit - لم تعد هناك مودالات

// دالة مشتركة لجلب رصيد المندوب (تعمل مع Modal و Card)
function loadSalesRepBalance(salesRepId, repBalanceElement, collectAmountElement) {
    if (!salesRepId || salesRepId === '') {
        if (repBalanceElement) {
            repBalanceElement.value = '-- اختر مندوب أولاً --';
            repBalanceElement.style.color = '#6c757d';
        }
        if (collectAmountElement) {
            collectAmountElement.max = '';
            collectAmountElement.removeAttribute('data-max-balance');
        }
        return;
    }
    
    // إظهار loading state
    if (repBalanceElement) {
        repBalanceElement.value = 'جاري التحميل...';
        repBalanceElement.style.color = '#6c757d';
    }
    
    // جلب رصيد المندوب
    const url = new URL(window.location.href);
    url.searchParams.set('ajax', 'get_sales_rep_balance');
    url.searchParams.set('sales_rep_id', salesRepId);
    
    fetch(url.toString(), {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-cache'
    })
    .then(response => {
        const contentType = response.headers.get('content-type') || '';
        
        return response.text().then(text => {
            if (!contentType.includes('application/json')) {
                console.error('Server response (first 500 chars):', text.substring(0, 500));
                throw new Error('Invalid response type. Expected JSON but got: ' + contentType);
            }
            
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            
            if (!text || text.trim() === '') {
                throw new Error('Empty response from server');
            }
            
            try {
                return JSON.parse(text);
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError);
                console.error('Response text:', text.substring(0, 500));
                throw new Error('Invalid JSON response: ' + parseError.message);
            }
        });
    })
    .then(data => {
        if (!data || typeof data !== 'object') {
            throw new Error('Invalid response format');
        }
        
        if (data.success) {
            const balance = parseFloat(data.balance) || 0;
            const formattedBalance = balance.toLocaleString('ar-EG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            
            if (repBalanceElement) {
                repBalanceElement.value = formattedBalance;
                repBalanceElement.style.color = balance > 0 ? '#198754' : '#6c757d';
            }
            
            if (collectAmountElement) {
                collectAmountElement.max = balance;
                collectAmountElement.setAttribute('data-max-balance', balance);
            }
        } else {
            const errorMsg = data.message || 'فشل جلب رصيد المندوب';
            if (repBalanceElement) {
                repBalanceElement.value = 'خطأ: ' + errorMsg;
                repBalanceElement.style.color = '#dc3545';
            }
            console.error('Error:', errorMsg);
        }
    })
    .catch(error => {
        console.error('Fetch Error:', error);
        const errorMsg = error.message || 'حدث خطأ أثناء جلب رصيد المندوب';
        if (repBalanceElement) {
            repBalanceElement.value = 'خطأ في الاتصال';
            repBalanceElement.style.color = '#dc3545';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Cards فقط - لا توجد مودالات بعد الآن
    
    // Card elements only
    const collectCardSalesRepSelect = document.getElementById('collectFromRepCardSalesRepSelect');
    const collectCardRepBalanceAmount = document.getElementById('collectFromRepCardRepBalanceAmount');
    const collectCardAmount = document.getElementById('collectFromRepCardAmount');
    const collectCardForm = document.getElementById('collectFromRepCardForm');
    const collectCardSubmitBtn = document.getElementById('collectFromRepCardSubmitBtn');
    
    // معالجة تغيير المندوب في Card
    if (collectCardSalesRepSelect && collectCardRepBalanceAmount && collectCardAmount) {
        collectCardSalesRepSelect.addEventListener('change', function() {
            const salesRepId = this.value;
            loadSalesRepBalance(salesRepId, collectCardRepBalanceAmount, collectCardAmount);
        });
        
        // تحميل الرصيد تلقائياً إذا كان هناك قيمة محفوظة
        if (collectCardSalesRepSelect.value) {
            loadSalesRepBalance(collectCardSalesRepSelect.value, collectCardRepBalanceAmount, collectCardAmount);
        }
    }
    
    // دالة مشتركة للتحقق من المبلغ قبل الإرسال
    function validateCollectAmount(amountInput, maxBalance, submitButton) {
        const amount = parseFloat(amountInput.value);
        const maxBalanceValue = parseFloat(maxBalance || '0');
        
        if (amount <= 0) {
            alert('يرجى إدخال مبلغ صحيح أكبر من الصفر');
            amountInput.focus();
            return false;
        }
        
        if (maxBalanceValue > 0 && amount > maxBalanceValue) {
            alert('المبلغ المطلوب (' + amount.toLocaleString('ar-EG') + ' ج.م) أكبر من رصيد المندوب (' + maxBalanceValue.toLocaleString('ar-EG') + ' ج.م)');
            amountInput.focus();
            return false;
        }
        
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التحصيل...';
        }
        
        return true;
    }
    
    // التحقق من المبلغ قبل الإرسال - Card
    if (collectCardForm) {
        collectCardForm.addEventListener('submit', function(e) {
            if (!validateCollectAmount(collectCardAmount, collectCardAmount.getAttribute('data-max-balance'), collectCardSubmitBtn)) {
                e.preventDefault();
                return false;
            }
        });
    }

    // ===== تنقل سلس بين صفحات سجل المعاملات (بدون ريفريش) =====
    var transactionsListEl = document.getElementById('company-cash-transactions-list');
    if (transactionsListEl) {
        function buildAjaxUrl(href) {
            if (!href || href === '#' || href === '') return null;
            var sep = href.indexOf('?') >= 0 ? '&' : '?';
            var withAjax = href + sep + 'ajax=transactions_list';
            if (withAjax.indexOf('http') === 0) return withAjax;
            var base = window.location.origin + window.location.pathname;
            return (withAjax.charAt(0) === '?' ? base + withAjax : base + '?' + withAjax);
        }
        function loadTransactionsList(url, pushState) {
            if (!url) return;
            transactionsListEl.style.opacity = '0.6';
            transactionsListEl.style.pointerEvents = 'none';
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-cache' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.html) {
                        transactionsListEl.innerHTML = data.html;
                        if (pushState !== false) {
                            try {
                                var u = new URL(url, window.location.origin);
                                u.searchParams.delete('ajax');
                                window.history.pushState({ companyCashList: true }, '', u.pathname + '?' + u.searchParams.toString());
                            } catch (e) {}
                        }
                    }
                })
                .catch(function() {})
                .finally(function() {
                    transactionsListEl.style.opacity = '';
                    transactionsListEl.style.pointerEvents = '';
                });
        }
        transactionsListEl.addEventListener('click', function(e) {
            var link = e.target.closest('a.page-link');
            if (!link || !link.href || link.closest('.page-item.disabled')) return;
            var href = link.getAttribute('href') || link.href || '';
            if (href.indexOf('page=company_cash') === -1) return;
            e.preventDefault();
            e.stopPropagation();
            var ajaxUrl = buildAjaxUrl(href);
            if (ajaxUrl) loadTransactionsList(ajaxUrl, true);
            return false;
        });
        window.addEventListener('popstate', function(e) {
            if (window.location.search.indexOf('page=company_cash') >= 0) {
                var ajaxUrl = buildAjaxUrl(window.location.href);
                if (ajaxUrl) loadTransactionsList(ajaxUrl, false);
            }
        });
    }
});
</script>
<?php } catch (Throwable $company_cash_ex) {
    $company_cash_error = $company_cash_ex->getMessage();
    error_log('Company cash page error: ' . $company_cash_error . ' | ' . $company_cash_ex->getTraceAsString());
}
if (!empty($company_cash_error)) {
    echo '<div class="alert alert-danger m-3"><i class="bi bi-exclamation-triangle me-2"></i>حدث خطأ في تحميل صفحة الخزنة. يرجى المحاولة لاحقاً أو مراجعة المدير.</div>';
    return;
}
?>
<!-- تم حذف المودالات - Cards أصبحت ثابتة دائماً ظاهرة -->


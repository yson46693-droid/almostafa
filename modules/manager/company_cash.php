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

// التأكد من وجود جدول accountant_transactions (لا نعيد التعريف إذا كانت الدالة موجودة من accountant.php)
if (!function_exists('ensureAccountantTransactionsTable')) {
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

// التأكد من وجود جدول عهدة الأموال وسجلات الاسترجاع
function ensureCompanyCustodyTables() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db = db();
        if (empty($db->queryOne("SHOW TABLES LIKE 'company_custody'"))) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `company_custody` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `person_name` varchar(255) NOT NULL COMMENT 'اسم صاحب العهدة',
                  `user_id` int(11) DEFAULT NULL COMMENT 'معرف المستخدم صاحب العهدة للمحفظة',
                  `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ الأصلي',
                  `source` enum('from_safe','from_management') NOT NULL COMMENT 'من الخزنة أو من الإدارة',
                  `remaining_amount` decimal(15,2) NOT NULL COMMENT 'المبلغ المتبقي',
                  `expense_transaction_id` int(11) DEFAULT NULL COMMENT 'معرف مصروف الخزنة عند العهدة من الخزنة',
                  `notes` text DEFAULT NULL,
                  `created_by` int(11) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `source` (`source`),
                  KEY `created_by` (`created_by`),
                  KEY `expense_transaction_id` (`expense_transaction_id`),
                  KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عهدة الأموال'
            ");
        } else {
            $col = $db->queryOne("SHOW COLUMNS FROM company_custody LIKE 'user_id'");
            if (empty($col)) {
                try {
                    $db->execute("ALTER TABLE company_custody ADD COLUMN user_id INT(11) NULL DEFAULT NULL AFTER person_name, ADD KEY user_id (user_id)");
                } catch (Throwable $ex) {
                    error_log('Add user_id to company_custody: ' . $ex->getMessage());
                }
            }
        }
        if (empty($db->queryOne("SHOW TABLES LIKE 'custody_retrievals'"))) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `custody_retrievals` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `custody_id` int(11) NOT NULL,
                  `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ المسترجع',
                  `income_transaction_id` int(11) DEFAULT NULL COMMENT 'إيراد في الخزنة عند استرجاع عهدة من الخزنة',
                  `created_by` int(11) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `custody_id` (`custody_id`),
                  KEY `created_by` (`created_by`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='استرجاع عهدة'
            ");
        }
    } catch (Throwable $e) {
        error_log('Error creating company_custody tables: ' . $e->getMessage());
    }
}

// التأكد من وجود الجداول
ensureAccountantTransactionsTable();
ensureFinancialTransactionsTable();
ensureCompanyCustodyTables();

// عند تحميل الصفحة من لوحة المحاسب نستخدم accountant_cash في روابط الترقيم والـ AJAX
$cashPageParam = (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'accountant.php') !== false) ? 'accountant_cash' : 'company_cash';

// توليد رقم مرجعي عشوائي فريد من 6 أرقام
function generateUniqueReferenceNumber($db) {
    $maxAttempts = 20;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $ref = (string) mt_rand(100000, 999999);
        $inAt = $db->queryOne("SELECT 1 FROM accountant_transactions WHERE reference_number = ? LIMIT 1", [$ref]);
        $inFt = $db->queryOne("SELECT 1 FROM financial_transactions WHERE reference_number = ? LIMIT 1", [$ref]);
        if (empty($inAt) && empty($inFt)) {
            return $ref;
        }
    }
    return (string) mt_rand(100000, 999999); // fallback
}

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
    global $cashPageParam;
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
    $baseUrl = '?page=' . (isset($cashPageParam) ? $cashPageParam : 'company_cash');
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
                    $referenceNumber = generateUniqueReferenceNumber($db);
                    
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

    if ($action === 'external_collection') {
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $description = trim($_POST['description'] ?? '');

        if ($amount <= 0) {
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
        } elseif (empty($description)) {
            $_SESSION['financial_error'] = 'يرجى إدخال وصف للعملية.';
        } else {
            try {
                $referenceNumber = generateUniqueReferenceNumber($db);
                
                $db->execute(
                    "INSERT INTO financial_transactions (type, amount, description, reference_number, status, approved_by, created_by, approved_at)
                     VALUES (?, ?, ?, ?, 'approved', ?, ?, NOW())",
                    [
                        'income',
                        $amount,
                        $description,
                        $referenceNumber,
                        $currentUser['id'],
                        $currentUser['id']
                    ]
                );

                $transactionId = $db->getLastInsertId();

                logAudit(
                    $currentUser['id'],
                    'external_collection',
                    'financial_transaction',
                    $transactionId,
                    null,
                    [
                        'amount' => $amount,
                        'description' => $description,
                        'reference_number' => $referenceNumber
                    ]
                );

                $_SESSION['financial_success'] = 'تم تسجيل التحصيل الخارجي بنجاح.';
            } catch (Throwable $e) {
                error_log('External collection failed: ' . $e->getMessage());
                $_SESSION['financial_error'] = 'حدث خطأ أثناء تسجيل التحصيل: ' . $e->getMessage();
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

        if ($amount <= 0) {
            $referenceNumber = (string) mt_rand(100000, 999999);
            $_SESSION['financial_form_data'] = [
                'amount' => $_POST['amount'] ?? '',
                'description' => $description,
                'reference_number' => $referenceNumber,
                'mark_as_approved' => $markAsApproved ? '1' : '0',
            ];
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ مصروف صحيح.';
        } else {
            $referenceNumber = generateUniqueReferenceNumber($db);
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
                        $referenceNumber,
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
                        "\nالرقم المرجعي: " . $referenceNumber
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
                        'reference' => $referenceNumber
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

    // عهدة الأموال - إضافة
    if ($action === 'add_custody') {
        $custodyUserId = isset($_POST['custody_user_id']) ? (int) $_POST['custody_user_id'] : 0;
        $amount = isset($_POST['custody_amount']) ? (float) str_replace(',', '', $_POST['custody_amount']) : 0;
        $source = isset($_POST['custody_source']) && $_POST['custody_source'] === 'from_management' ? 'from_management' : 'from_safe';
        $notes = trim($_POST['custody_notes'] ?? '');

        $personName = '';
        if ($custodyUserId > 0) {
            $custodyUser = $db->queryOne("SELECT id, full_name, username FROM users WHERE id = ? AND status = 'active' AND role IN ('accountant', 'production', 'sales', 'driver')", [$custodyUserId]);
            $personName = $custodyUser ? trim($custodyUser['full_name'] ?? $custodyUser['username'] ?? '') : '';
        }

        if ($personName === '') {
            $_SESSION['financial_error'] = 'يرجى اختيار صاحب العهدة من القائمة.';
        } elseif ($amount <= 0) {
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
        } else {
            try {
                $expenseTransactionId = null;
                if ($source === 'from_safe') {
                    $treasurySummary = $db->queryOne("
                        SELECT
                        (SELECT COALESCE(SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                        (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('collection_from_sales_rep', 'income') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_income,
                        (SELECT COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND status = 'approved' AND (description NOT LIKE '%سلفة%' AND description NOT LIKE '%سلف%') AND description NOT LIKE '%تسوية رصيد دائن ل%' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_expense,
                        (SELECT COALESCE(SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' AND status = 'approved' AND (description NOT LIKE '%تسوية راتب%' OR description IS NULL) THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_payment
                    ");
                    $totalSalaries = 0.0;
                    if (!empty($db->queryOne("SHOW TABLES LIKE 'salaries'"))) {
                        $sr = $db->queryOne("SELECT COALESCE(SUM(total_amount), 0) as t FROM salaries WHERE status IN ('approved', 'paid')");
                        $totalSalaries = (float)($sr['t'] ?? 0);
                    }
                    $adjResult = $db->queryOne("SELECT COALESCE(SUM(amount), 0) as t FROM accountant_transactions WHERE status = 'approved' AND ((transaction_type = 'payment' AND description LIKE '%تسوية راتب%') OR (transaction_type = 'expense' AND (description LIKE '%سلفة%' OR description LIKE '%سلف%')))");
                    $settResult = $db->queryOne("SELECT COALESCE(SUM(amount), 0) as t FROM accountant_transactions WHERE transaction_type = 'expense' AND status = 'approved' AND (description LIKE '%تسوية رصيد دائن لعميل محلي%' OR description LIKE '%تسوية رصيد دائن لعميل مندوب%')");
                    $mgmtResult = $db->queryOne("SELECT COALESCE(SUM(amount), 0) as t FROM accountant_transactions WHERE transaction_type = 'income' AND status = 'approved' AND description LIKE '%تحصيل للإدارة%'");
                    $totalAdj = (float)($adjResult['t'] ?? 0);
                    $totalSett = (float)($settResult['t'] ?? 0);
                    $totalMgmt = (float)($mgmtResult['t'] ?? 0);
                    $netBalance = ($treasurySummary['approved_income'] ?? 0) - ($treasurySummary['approved_expense'] ?? 0) - ($treasurySummary['approved_payment'] ?? 0) - $totalSalaries - $totalAdj - $totalSett - $totalMgmt;
                    if ($amount > $netBalance) {
                        $_SESSION['financial_error'] = 'رصيد الخزنة غير كافٍ. الصافي: ' . formatCurrency($netBalance);
                        $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
                        if (!headers_sent()) header('Location: ' . $redirectTarget); else echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
                        exit;
                    }
                    $ref = generateUniqueReferenceNumber($db);
                    $desc = 'عهدة أموال (من الخزنة) - ' . $personName;
                    $db->execute(
                        "INSERT INTO accountant_transactions (transaction_type, amount, sales_rep_id, description, reference_number, status, approved_by, created_by, approved_at) VALUES ('expense', ?, NULL, ?, ?, 'approved', ?, ?, NOW())",
                        [$amount, $desc, $ref, $currentUser['id'], $currentUser['id']]
                    );
                    $expenseTransactionId = $db->getLastInsertId();
                }
                $custodyUserIdVal = $custodyUserId > 0 ? $custodyUserId : null;
                $db->execute(
                    "INSERT INTO company_custody (person_name, user_id, amount, source, remaining_amount, expense_transaction_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$personName, $custodyUserIdVal, $amount, $source, $amount, $expenseTransactionId, $notes ?: null, $currentUser['id']]
                );
                $custodyId = $db->getLastInsertId();
                logAudit($currentUser['id'], 'custody_add', 'company_custody', $custodyId, null, ['person_name' => $personName, 'amount' => $amount, 'source' => $source]);
                if ($custodyUserId > 0 && !empty($db->queryOne("SHOW TABLES LIKE 'user_wallet_transactions'"))) {
                    $db->execute(
                        "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, reference_type, reference_id, created_by) VALUES (?, 'custody_add', ?, ?, 'company_custody', ?, ?)",
                        [$custodyUserId, $amount, 'عهدة أموال - ' . $personName, $custodyId, $currentUser['id']]
                    );
                }
                $_SESSION['financial_success'] = 'تم تسجيل العهدة لـ ' . htmlspecialchars($personName) . ' بنجاح.';
            } catch (Throwable $e) {
                error_log('Add custody failed: ' . $e->getMessage());
                $_SESSION['financial_error'] = 'حدث خطأ: ' . $e->getMessage();
            }
        }
        $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
        if (!headers_sent()) header('Location: ' . $redirectTarget); else echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
        exit;
    }

    // عهدة الأموال - تعديل المبلغ (للمدير والمحاسب فقط، ويُسمح إذا لم يكن هناك استرجاع)
    if ($action === 'edit_custody') {
        $custodyId = isset($_POST['custody_id']) ? (int) $_POST['custody_id'] : 0;
        $newAmount = isset($_POST['custody_edit_amount']) ? (float) str_replace(',', '', $_POST['custody_edit_amount']) : 0;
        $userRole = strtolower($currentUser['role'] ?? '');
        if (!in_array($userRole, ['manager', 'accountant', 'developer'], true)) {
            $_SESSION['financial_error'] = 'غير مصرح بتعديل العهدة.';
        } elseif ($custodyId <= 0 || $newAmount <= 0) {
            $_SESSION['financial_error'] = 'بيانات غير صحيحة.';
        } else {
            $row = $db->queryOne("SELECT id, person_name, amount, remaining_amount, source, expense_transaction_id FROM company_custody WHERE id = ?", [$custodyId]);
            if (empty($row)) {
                $_SESSION['financial_error'] = 'سجل العهدة غير موجود.';
            } elseif ((float)$row['remaining_amount'] !== (float)$row['amount']) {
                $_SESSION['financial_error'] = 'لا يمكن تعديل المبلغ بعد وجود استرجاع. المتبقي: ' . formatCurrency($row['remaining_amount']);
            } else {
                try {
                    $oldAmount = (float) $row['amount'];
                    if ($row['source'] === 'from_safe' && $row['expense_transaction_id']) {
                        $db->execute("UPDATE accountant_transactions SET amount = ?, description = ? WHERE id = ?", [$newAmount, 'عهدة أموال (من الخزنة) - ' . $row['person_name'], $row['expense_transaction_id']]);
                    }
                    $db->execute("UPDATE company_custody SET amount = ?, remaining_amount = ?, updated_at = NOW() WHERE id = ?", [$newAmount, $newAmount, $custodyId]);
                    logAudit($currentUser['id'], 'custody_edit', 'company_custody', $custodyId, null, ['old_amount' => $oldAmount, 'new_amount' => $newAmount]);
                    $_SESSION['financial_success'] = 'تم تعديل مبلغ العهدة بنجاح.';
                } catch (Throwable $e) {
                    $_SESSION['financial_error'] = 'حدث خطأ: ' . $e->getMessage();
                }
            }
        }
        $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
        if (!headers_sent()) header('Location: ' . $redirectTarget); else echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
        exit;
    }

    // عهدة الأموال - استرجاع (كامل أو جزء)
    if ($action === 'retrieve_custody') {
        $custodyId = isset($_POST['custody_id']) ? (int) $_POST['custody_id'] : 0;
        $retrieveAmount = isset($_POST['retrieve_amount']) ? (float) str_replace(',', '', $_POST['retrieve_amount']) : 0;
        if ($custodyId <= 0 || $retrieveAmount <= 0) {
            $_SESSION['financial_error'] = 'بيانات غير صحيحة.';
        } else {
            $row = $db->queryOne("SELECT id, person_name, user_id, amount, remaining_amount, source, expense_transaction_id FROM company_custody WHERE id = ?", [$custodyId]);
            if (empty($row)) {
                $_SESSION['financial_error'] = 'سجل العهدة غير موجود.';
            } elseif ($retrieveAmount > (float) $row['remaining_amount']) {
                $_SESSION['financial_error'] = 'مبلغ الاسترجاع أكبر من المتبقي (' . formatCurrency($row['remaining_amount']) . ').';
            } else {
                try {
                    $incomeTransactionId = null;
                    if ($row['source'] === 'from_safe') {
                        $ref = generateUniqueReferenceNumber($db);
                        $desc = 'استرجاع عهدة أموال (من الخزنة) - ' . $row['person_name'];
                        $db->execute(
                            "INSERT INTO accountant_transactions (transaction_type, amount, sales_rep_id, description, reference_number, status, approved_by, created_by, approved_at) VALUES ('income', ?, NULL, ?, ?, 'approved', ?, ?, NOW())",
                            [$retrieveAmount, $desc, $ref, $currentUser['id'], $currentUser['id']]
                        );
                        $incomeTransactionId = $db->getLastInsertId();
                    }
                    $newRemaining = (float) $row['remaining_amount'] - $retrieveAmount;
                    $db->execute("INSERT INTO custody_retrievals (custody_id, amount, income_transaction_id, created_by) VALUES (?, ?, ?, ?)", [$custodyId, $retrieveAmount, $incomeTransactionId, $currentUser['id']]);
                    $db->execute("UPDATE company_custody SET remaining_amount = ? WHERE id = ?", [$newRemaining, $custodyId]);
                    $custodyUserId = !empty($row['user_id']) ? (int) $row['user_id'] : 0;
                    if ($custodyUserId > 0 && !empty($db->queryOne("SHOW TABLES LIKE 'user_wallet_transactions'"))) {
                        $db->execute(
                            "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, reference_type, reference_id, created_by) VALUES (?, 'custody_retrieve', ?, ?, 'company_custody', ?, ?)",
                            [$custodyUserId, $retrieveAmount, 'استرجاع عهدة أموال - ' . $row['person_name'], $custodyId, $currentUser['id']]
                        );
                    }
                    logAudit($currentUser['id'], 'custody_retrieve', 'company_custody', $custodyId, null, ['amount' => $retrieveAmount]);
                    $_SESSION['financial_success'] = 'تم استرجاع ' . formatCurrency($retrieveAmount) . ' من عهدة ' . htmlspecialchars($row['person_name']) . ' بنجاح.';
                } catch (Throwable $e) {
                    error_log('Retrieve custody failed: ' . $e->getMessage());
                    $_SESSION['financial_error'] = 'حدث خطأ: ' . $e->getMessage();
                }
            }
        }
        $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
        if (!headers_sent()) header('Location: ' . $redirectTarget); else echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
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
                                $generatedRef = (string) mt_rand(100000, 999999);
                                if (!empty($financialFormData['reference_number'])) $generatedRef = $financialFormData['reference_number']; ?>
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
            
            <!-- تحصيل خارجي -->
            <div class="col-12 col-lg-12 col-xxl-12">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-cash-stack me-2 text-success"></i>تحصيل خارجي
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="external_collection">
                            <div class="col-12">
                                <label for="externalCollectionAmount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="externalCollectionAmount" name="amount" required placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="externalCollectionDescription" class="form-label">الوصف <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="externalCollectionDescription" name="description" rows="3" required placeholder="تفاصيل التحصيل..."></textarea>
                            </div>
                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="reset" class="btn btn-outline-secondary">تفريغ الحقول</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save me-1"></i>حفظ التحصيل
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- عهدة الأموال -->
            <?php
            $custodyUsers = $db->query("SELECT id, full_name, username, role FROM users WHERE status = 'active' AND role IN ('accountant', 'production', 'sales', 'driver') ORDER BY full_name ASC, username ASC") ?: [];
            $roleLabels = ['accountant' => 'محاسب', 'production' => 'عامل إنتاج', 'sales' => 'مندوب مبيعات', 'driver' => 'سائق'];
            ?>
            <div class="col-12 col-lg-12 col-xxl-12">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-person-badge me-2 text-info"></i>عهدة الأموال
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="add_custody">
                            <div class="col-12">
                                <label for="custodyUserId" class="form-label">صاحب العهدة <span class="text-danger">*</span></label>
                                <select class="form-select" id="custodyUserId" name="custody_user_id" required>
                                    <option value="">-- اختر من القائمة --</option>
                                    <?php foreach ($custodyUsers as $cu): ?>
                                        <option value="<?php echo (int)$cu['id']; ?>">
                                            <?php echo htmlspecialchars($cu['full_name'] ?: $cu['username'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php echo ' (' . ($roleLabels[$cu['role']] ?? $cu['role']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">محاسب - عامل إنتاج - مندوب مبيعات - سائق</small>
                            </div>
                            <div class="col-12">
                                <label for="custodyAmount" class="form-label">المبلغ <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="custodyAmount" name="custody_amount" required placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">مصدر العهدة</label>
                                <div class="d-flex gap-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="custody_source" id="custodyFromSafe" value="from_safe" checked>
                                        <label class="form-check-label" for="custodyFromSafe">من الخزنة</label>
                                        <small class="text-muted d-block">يُخصم من صافي رصيد الخزنة</small>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="custody_source" id="custodyFromManagement" value="from_management">
                                        <label class="form-check-label" for="custodyFromManagement">من الإدارة</label>
                                        <small class="text-muted d-block">لا يُخصم من رصيد الخزنة</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="custodyNotes" class="form-label">ملاحظات</label>
                                <textarea class="form-control" id="custodyNotes" name="custody_notes" rows="2" placeholder="اختياري"></textarea>
                            </div>
                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="reset" class="btn btn-outline-secondary">تفريغ</button>
                                <button type="submit" class="btn btn-info text-white">
                                    <i class="bi bi-plus-circle me-1"></i>تسجيل العهدة
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

<?php
$custodyList = [];
$custodyTableExists = $db->queryOne("SHOW TABLES LIKE 'company_custody'");
if (!empty($custodyTableExists)) {
    $custodyList = $db->query("
        SELECT c.id, c.person_name, c.amount, c.source, c.remaining_amount, c.created_at, u.full_name as created_by_name
        FROM company_custody c
        LEFT JOIN users u ON c.created_by = u.id
        ORDER BY c.created_at DESC
    ") ?: [];
}
$userRoleForCustody = strtolower($currentUser['role'] ?? '');
$canEditCustody = in_array($userRoleForCustody, ['manager', 'accountant', 'developer'], true);
?>

<!-- عهدة الأموال - سجل السجلات -->
<div class="card shadow-sm mt-4">
    <div class="card-header bg-light fw-bold">
        <span><i class="bi bi-person-badge me-2 text-info"></i>سجل عهدة الأموال</span>
    </div>
    <div class="card-body">
        <?php if (empty($custodyList)): ?>
            <p class="text-muted text-center py-4 mb-0"><i class="bi bi-inbox me-2"></i>لا توجد سجلات عهدة حالياً</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>اسم صاحب العهدة</th>
                            <th>المبلغ الأصلي</th>
                            <th>المصدر</th>
                            <th>المبلغ المتبقي</th>
                            <th>التاريخ</th>
                            <th>أنشأه</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($custodyList as $c): ?>
                            <?php
                            $remaining = (float) $c['remaining_amount'];
                            $original = (float) $c['amount'];
                            $canEditThis = $canEditCustody && ($remaining === $original);
                            $sourceLabel = ($c['source'] ?? '') === 'from_management' ? 'من الإدارة' : 'من الخزنة';
                            $sourceBadge = ($c['source'] ?? '') === 'from_management' ? 'bg-secondary' : 'bg-primary';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['person_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo formatCurrency($c['amount']); ?></td>
                                <td><span class="badge <?php echo $sourceBadge; ?>"><?php echo $sourceLabel; ?></span></td>
                                <td class="fw-bold"><?php echo formatCurrency($c['remaining_amount']); ?></td>
                                <td><?php echo formatDateTime($c['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($c['created_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($canEditThis): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning me-1 custody-edit-btn" data-custody-id="<?php echo (int)$c['id']; ?>" data-custody-name="<?php echo htmlspecialchars($c['person_name'], ENT_QUOTES, 'UTF-8'); ?>" data-custody-amount="<?php echo htmlspecialchars($c['amount'], ENT_QUOTES, 'UTF-8'); ?>" title="تعديل المبلغ">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($remaining > 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-success custody-retrieve-btn" data-custody-id="<?php echo (int)$c['id']; ?>" data-custody-name="<?php echo htmlspecialchars($c['person_name'], ENT_QUOTES, 'UTF-8'); ?>" data-custody-remaining="<?php echo htmlspecialchars($c['remaining_amount'], ENT_QUOTES, 'UTF-8'); ?>" title="استرجاع">
                                            <i class="bi bi-arrow-return-left"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">مسترد بالكامل</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- بطاقة تعديل مبلغ العهدة (تظهر عند الضغط على تعديل) -->
<div class="card shadow-sm mt-3 d-none" id="editCustodyCard">
    <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pencil-square me-2 text-warning"></i>تعديل مبلغ العهدة</span>
        <button type="button" class="btn btn-sm btn-outline-secondary custody-card-close" data-card="edit" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
        <form method="POST" id="editCustodyForm">
            <input type="hidden" name="action" value="edit_custody">
            <input type="hidden" name="custody_id" id="editCustodyId" value="">
            <p class="text-muted small mb-2">صاحب العهدة: <strong id="editCustodyNameDisplay">—</strong></p>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-4">
                    <label for="custodyEditAmount" class="form-label">المبلغ الجديد <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">ج.م</span>
                        <input type="number" step="0.01" min="0.01" class="form-control" id="custodyEditAmount" name="custody_edit_amount" placeholder="0.00">
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                    <button type="submit" class="btn btn-warning" id="editCustodySubmitBtn">
                        <i class="bi bi-check2 me-1"></i>حفظ التعديل
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- بطاقة استرجاع عهدة (تظهر عند الضغط على استرجاع) -->
<div class="card shadow-sm mt-3 d-none" id="retrieveCustodyCard">
    <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-arrow-return-left me-2 text-success"></i>استرجاع عهدة</span>
        <button type="button" class="btn btn-sm btn-outline-secondary custody-card-close" data-card="retrieve" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
        <form method="POST" id="retrieveCustodyForm">
            <input type="hidden" name="action" value="retrieve_custody">
            <input type="hidden" name="custody_id" id="retrieveCustodyId" value="">
            <p class="text-muted small mb-1">صاحب العهدة: <strong id="retrieveCustodyNameDisplay">—</strong></p>
            <p class="small mb-2">المبلغ المتبقي: <strong id="retrieveCustodyRemainingDisplay" class="text-success">—</strong></p>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-4">
                    <label for="retrieveAmount" class="form-label">مبلغ الاسترجاع <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">ج.م</span>
                        <input type="number" step="0.01" min="0.01" class="form-control" id="retrieveAmount" name="retrieve_amount" placeholder="0.00">
                    </div>
                    <small class="text-muted">كامل المبلغ أو جزء منه</small>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                    <button type="submit" class="btn btn-success" id="retrieveCustodySubmitBtn">
                        <i class="bi bi-arrow-return-left me-1"></i>استرجاع
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var editCard = document.getElementById('editCustodyCard');
    var retrieveCard = document.getElementById('retrieveCustodyCard');
    var editCustodyId = document.getElementById('editCustodyId');
    var editCustodyNameDisplay = document.getElementById('editCustodyNameDisplay');
    var custodyEditAmount = document.getElementById('custodyEditAmount');
    var editCustodySubmitBtn = document.getElementById('editCustodySubmitBtn');
    var retrieveCustodyId = document.getElementById('retrieveCustodyId');
    var retrieveCustodyNameDisplay = document.getElementById('retrieveCustodyNameDisplay');
    var retrieveCustodyRemainingDisplay = document.getElementById('retrieveCustodyRemainingDisplay');
    var retrieveAmount = document.getElementById('retrieveAmount');
    var retrieveCustodySubmitBtn = document.getElementById('retrieveCustodySubmitBtn');

    function scrollToCard(el) {
        if (el && el.scrollIntoView) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function showEditCard() {
        if (retrieveCard) retrieveCard.classList.add('d-none');
        if (editCard) { editCard.classList.remove('d-none'); scrollToCard(editCard); }
    }
    function showRetrieveCard() {
        if (editCard) editCard.classList.add('d-none');
        if (retrieveCard) { retrieveCard.classList.remove('d-none'); scrollToCard(retrieveCard); }
    }
    function closeEditCard() {
        if (editCard) editCard.classList.add('d-none');
    }
    function closeRetrieveCard() {
        if (retrieveCard) retrieveCard.classList.add('d-none');
    }

    document.querySelectorAll('.custody-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-custody-id') || '';
            var name = this.getAttribute('data-custody-name') || '—';
            var amount = this.getAttribute('data-custody-amount') || '0';
            if (editCustodyId) editCustodyId.value = id;
            if (editCustodyNameDisplay) editCustodyNameDisplay.textContent = name;
            if (custodyEditAmount) { custodyEditAmount.value = amount; custodyEditAmount.required = true; }
            if (editCustodySubmitBtn) editCustodySubmitBtn.disabled = false;
            showEditCard();
        });
    });

    document.querySelectorAll('.custody-retrieve-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-custody-id') || '';
            var name = this.getAttribute('data-custody-name') || '—';
            var remaining = parseFloat(this.getAttribute('data-custody-remaining') || 0);
            if (retrieveCustodyId) retrieveCustodyId.value = id;
            if (retrieveCustodyNameDisplay) retrieveCustodyNameDisplay.textContent = name;
            if (retrieveCustodyRemainingDisplay) retrieveCustodyRemainingDisplay.textContent = remaining.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
            if (retrieveAmount) { retrieveAmount.setAttribute('max', remaining); retrieveAmount.value = remaining; retrieveAmount.required = true; }
            if (retrieveCustodySubmitBtn) retrieveCustodySubmitBtn.disabled = false;
            showRetrieveCard();
        });
    });

    document.querySelectorAll('.custody-card-close').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var card = this.getAttribute('data-card');
            if (card === 'edit') closeEditCard();
            else if (card === 'retrieve') closeRetrieveCard();
        });
    });

    var editForm = document.getElementById('editCustodyForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            if (!editCustodyId || !editCustodyId.value || !custodyEditAmount || parseFloat(custodyEditAmount.value) <= 0) {
                e.preventDefault();
                alert('يرجى اختيار سجل عهدة من الجدول أولاً ثم تعديل المبلغ.');
                return false;
            }
        });
    }
    var retrieveForm = document.getElementById('retrieveCustodyForm');
    if (retrieveForm) {
        retrieveForm.addEventListener('submit', function(e) {
            if (!retrieveCustodyId || !retrieveCustodyId.value) {
                e.preventDefault();
                alert('يرجى اختيار سجل عهدة من الجدول أولاً.');
                return false;
            }
            var maxVal = parseFloat(retrieveAmount ? retrieveAmount.getAttribute('max') : 0) || 0;
            var val = parseFloat(retrieveAmount ? retrieveAmount.value : 0) || 0;
            if (val <= 0 || val > maxVal) {
                e.preventDefault();
                alert('مبلغ الاسترجاع يجب أن يكون بين 0.01 و ' + maxVal.toLocaleString('ar-EG', { minimumFractionDigits: 2 }) + ' ج.م');
                return false;
            }
        });
    }
});
</script>

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
                    <input type="hidden" name="page" value="<?php echo htmlspecialchars($cashPageParam ?? 'company_cash', ENT_QUOTES, 'UTF-8'); ?>">
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
                        <div class="col-12">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search me-1"></i>بحث
                                </button>
                                <a href="?page=<?php echo htmlspecialchars($cashPageParam ?? 'company_cash', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
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
            if (href.indexOf('page=company_cash') === -1 && href.indexOf('page=accountant_cash') === -1) return;
            e.preventDefault();
            e.stopPropagation();
            var ajaxUrl = buildAjaxUrl(href);
            if (ajaxUrl) loadTransactionsList(ajaxUrl, true);
            return false;
        });
        window.addEventListener('popstate', function(e) {
            if (window.location.search.indexOf('page=company_cash') >= 0 || window.location.search.indexOf('page=accountant_cash') >= 0) {
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


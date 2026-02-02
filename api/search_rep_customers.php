<?php
/**
 * API: البحث الديناميكي في عملاء المندوبين
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من الصلاحيات
$currentUser = getCurrentUser();
if (!in_array($currentUser['role'], ['manager', 'developer', 'accountant'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Pagination
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 6;
$offset = ($page - 1) * $perPage;

// البحث والفلترة
$search = trim($_GET['cs'] ?? '');
$debtStatus = $_GET['cds'] ?? 'all';
$repFilter = isset($_GET['rep_filter']) ? (int)$_GET['rep_filter'] : 0;

$allowedDebtStatuses = ['all', 'debtor', 'clear'];
if (!in_array($debtStatus, $allowedDebtStatuses, true)) {
    $debtStatus = 'all';
}

try {
    $db = db();
    
    // بناء استعلام SQL
    $sql = "SELECT c.*, 
            COALESCE(rep1.full_name, rep2.full_name) as rep_name, 
            r.name as region_name,
            COALESCE(c.credit_limit, 0) as credit_limit
            FROM customers c
            LEFT JOIN users rep1 ON c.rep_id = rep1.id AND rep1.role = 'sales'
            LEFT JOIN users rep2 ON c.created_by = rep2.id AND rep2.role = 'sales'
            LEFT JOIN regions r ON c.region_id = r.id
            WHERE ";
    
    // بناء استعلام COUNT
    $countSql = "SELECT COUNT(*) as total FROM customers c";
    
    $params = [];
    $countParams = [];
    
    // إضافة JOINs لاستعلام COUNT إذا كان هناك بحث أو فلتر مندوب
    if ($search || $repFilter > 0) {
        $countSql .= " LEFT JOIN users rep1 ON c.rep_id = rep1.id AND rep1.role = 'sales'
            LEFT JOIN users rep2 ON c.created_by = rep2.id AND rep2.role = 'sales'
            LEFT JOIN regions r ON c.region_id = r.id";
    }
    
    $countSql .= " WHERE ";
    
    // بناء شرط WHERE حسب الفلتر
    if ($repFilter > 0) {
        $sql .= "(c.rep_id = ? OR c.created_by = ?)";
        $countSql .= "(c.rep_id = ? OR c.created_by = ?)";
        $params[] = $repFilter;
        $params[] = $repFilter;
        $countParams[] = $repFilter;
        $countParams[] = $repFilter;
    } else {
        $sql .= "((c.rep_id IS NOT NULL AND c.rep_id IN (SELECT id FROM users WHERE role = 'sales'))
               OR (c.created_by IS NOT NULL AND c.created_by IN (SELECT id FROM users WHERE role = 'sales')))";
        $countSql .= "((c.rep_id IS NOT NULL AND c.rep_id IN (SELECT id FROM users WHERE role = 'sales'))
               OR (c.created_by IS NOT NULL AND c.created_by IN (SELECT id FROM users WHERE role = 'sales')))";
    }
    
    if ($debtStatus === 'debtor') {
        $sql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
        $countSql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    } elseif ($debtStatus === 'clear') {
        $sql .= " AND (c.balance IS NULL OR c.balance <= 0)";
        $countSql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    }
    
    if ($search) {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR r.name LIKE ? OR rep1.full_name LIKE ? OR rep2.full_name LIKE ? OR CAST(COALESCE(c.balance, 0) AS CHAR) LIKE ?)";
        $countSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR r.name LIKE ? OR rep1.full_name LIKE ? OR rep2.full_name LIKE ? OR CAST(COALESCE(c.balance, 0) AS CHAR) LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
    }
    
    // جلب العدد الإجمالي
    $totalResult = $db->queryOne($countSql, $countParams);
    $totalCustomers = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalCustomers / $perPage);
    
    // جلب العملاء
    $sql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    $customers = $db->query($sql, $params);
    
    // بناء HTML للجدول
    ob_start();
    ?>
    <?php if (empty($customers)): ?>
        <tr>
            <td colspan="8" class="text-center text-muted">لا توجد عملاء للمندوبين</td>
        </tr>
    <?php else: ?>
        <?php foreach ($customers as $customer): ?>
            <tr data-customer-id="<?php echo (int)$customer['id']; ?>">
                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                <td>
                    <?php
                    // جلب أرقام الهواتف من جدول customer_phones
                    $customerPhones = $db->query(
                        "SELECT phone FROM customer_phones WHERE customer_id = ? ORDER BY is_primary DESC, id ASC",
                        [$customer['id']]
                    );
                    if (empty($customerPhones) && !empty($customer['phone'])) {
                        $customerPhones = [['phone' => $customer['phone']]];
                    }
                    if (!empty($customerPhones)) {
                        foreach ($customerPhones as $phoneData) {
                            $phoneNumber = trim($phoneData['phone'] ?? '');
                            if (!empty($phoneNumber)) {
                                echo '<a href="tel:' . htmlspecialchars($phoneNumber) . '" class="btn btn-sm btn-outline-primary me-1 mb-1" title="اتصل بـ ' . htmlspecialchars($phoneNumber) . '">';
                                echo '<i class="bi bi-telephone-fill"></i> ';
                                echo '</a>';
                            }
                        }
                    } else {
                        echo '-';
                    }
                    ?>
                </td>
                <td>
                    <?php
                    $customerBalanceValue = isset($customer['balance']) ? (float) $customer['balance'] : 0.0;
                    $balanceBadgeClass = $customerBalanceValue > 0
                        ? 'bg-warning-subtle text-warning'
                        : ($customerBalanceValue < 0 ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary');
                    $displayBalanceValue = $customerBalanceValue < 0 ? abs($customerBalanceValue) : $customerBalanceValue;
                    ?>
                    <strong><?php echo formatCurrency($displayBalanceValue); ?></strong>
                    <?php if ($customerBalanceValue !== 0.0): ?>
                        <span class="badge <?php echo $balanceBadgeClass; ?> ms-1">
                            <?php echo $customerBalanceValue > 0 ? 'رصيد مدين' : 'رصيد دائن'; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($customer['region_name'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($customer['rep_name'] ?? '-'); ?></td>
                <td>
                    <?php
                    $hasLocation = isset($customer['latitude'], $customer['longitude']) &&
                        $customer['latitude'] !== null &&
                        $customer['longitude'] !== null;
                    $latValue = $hasLocation ? (float)$customer['latitude'] : null;
                    $lngValue = $hasLocation ? (float)$customer['longitude'] : null;
                    ?>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary all-customers-location-capture-btn"
                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                        >
                            <i class="bi bi-geo-alt me-1"></i>تحديد
                        </button>
                        <?php if ($hasLocation): ?>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info all-customers-location-view-btn"
                                data-customer-id="<?php echo (int)$customer['id']; ?>"
                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                data-latitude="<?php echo htmlspecialchars(number_format($latValue, 8, '.', '')); ?>"
                                data-longitude="<?php echo htmlspecialchars(number_format($lngValue, 8, '.', '')); ?>"
                            >
                                <i class="bi bi-map me-1"></i>عرض
                            </button>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary">غير محدد</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php
                    $customerBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
                    $displayBalanceForButton = $customerBalance < 0 ? abs($customerBalance) : $customerBalance;
                    $formattedBalance = formatCurrency($displayBalanceForButton);
                    $rawBalance = number_format($customerBalance, 2, '.', '');
                    $currentRole = strtolower((string)($currentUser['role'] ?? ''));
                    ?>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if (in_array($currentRole, ['manager', 'developer', 'accountant', 'sales'], true)): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-warning edit-rep-customer-btn"
                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                            data-customer-region-id="<?php echo (int)($customer['region_id'] ?? 0); ?>"
                            data-customer-balance="<?php echo htmlspecialchars($rawBalance); ?>"
                        >
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </button>
                        <?php endif; ?>
                        <?php if ($currentRole === 'manager'): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-info set-credit-limit-btn"
                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                            data-customer-balance="<?php echo htmlspecialchars($rawBalance); ?>"
                            data-credit-limit="<?php echo htmlspecialchars(number_format((float)($customer['credit_limit'] ?? 0), 2, '.', '')); ?>"
                        >
                            <i class="bi bi-credit-card me-1"></i>الحد الائتماني
                        </button>
                        <?php endif; ?>
                        <button
                            type="button"
                            class="btn btn-sm <?php echo $customerBalance > 0 ? 'btn-success' : 'btn-outline-secondary'; ?> all-customers-collect-btn"
                            onclick="showAllCustomersCollectPaymentModal(this)"
                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                            data-customer-balance="<?php echo htmlspecialchars($rawBalance); ?>"
                            data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>"
                            <?php echo $customerBalance > 0 ? '' : 'disabled'; ?>
                        >
                            <i class="bi bi-cash-coin me-1"></i>تحصيل
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-info js-all-customers-purchase-history"
                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                        >
                            <i class="bi bi-receipt me-1"></i>سجل 
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-warning js-all-customers-return-products"
                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                        >
                            <i class="bi bi-arrow-return-left me-1"></i>مرتجع
                        </button>
                        <?php if ($currentRole === 'manager' || $currentRole === 'developer'): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary change-sales-rep-btn"
                            onclick="openChangeSalesRepModal(this)"
                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                            data-current-rep-id="<?php echo (int)($customer['rep_id'] ?? $customer['created_by'] ?? 0); ?>"
                            data-current-rep-name="<?php echo htmlspecialchars($customer['rep_name'] ?? 'غير محدد'); ?>"
                        >
                            <i class="bi bi-arrow-left-right me-1"></i>نقل لمندوب آخر
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    $tableRows = ob_get_clean();
    
    // بناء HTML للـ Pagination
    ob_start();
    ?>
    <?php if ($totalPages > 1): ?>
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center flex-wrap">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="javascript:void(0);" onclick="loadRepCustomers(<?php echo $page - 1; ?>)">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="javascript:void(0);" onclick="loadRepCustomers(1)">1</a></li>
                <?php if ($startPage > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="javascript:void(0);" onclick="loadRepCustomers(<?php echo $i; ?>)">
                        <?php echo $i; ?>
                    </a>
                </li>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="javascript:void(0);" onclick="loadRepCustomers(<?php echo $totalPages; ?>)"><?php echo $totalPages; ?></a></li>
            <?php endif; ?>
            
            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                <a class="page-link" href="javascript:void(0);" onclick="loadRepCustomers(<?php echo $page + 1; ?>)">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
    <?php
    $paginationHtml = ob_get_clean();
    
    $response = [
        'success' => true,
        'tableRows' => $tableRows,
        'pagination' => $paginationHtml,
        'totalCustomers' => $totalCustomers,
        'currentPage' => $page,
        'totalPages' => $totalPages
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Search Rep Customers Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في البحث'], JSON_UNESCAPED_UNICODE);
}

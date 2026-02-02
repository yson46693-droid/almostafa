<?php
/**
 * API: البحث الديناميكي في العملاء
 * Returns customer data with search and filters for AJAX requests
 */

// ===== بداية الإعداد الحرج لضمان JSON فقط =====

// تعطيل عرض الأخطاء تماماً قبل أي شيء
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

// تنظيف أي output موجود
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// بدء output buffering جديد
ob_start();

// تعريف ثوابت الوصول
define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

// دالة الإرجاع JSON
function returnJsonResponse(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    }
    
    $json = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"success":false,"message":"خطأ في تنسيق البيانات"}';
    }
    
    echo $json;
    exit;
}

// ===== تحميل الملفات المطلوبة =====
try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    
    // التحقق من تسجيل الدخول
    if (!isLoggedIn()) {
        returnJsonResponse([
            'success' => false,
            'message' => 'غير مصرح لك بالوصول'
        ], 401);
    }
    
    $currentUser = getCurrentUser();
    $currentRole = strtolower((string)($currentUser['role'] ?? ''));
    
    // التحقق من الصلاحيات
    $allowedRoles = ['manager', 'developer', 'accountant', 'sales'];
    if (!in_array($currentRole, $allowedRoles, true)) {
        returnJsonResponse([
            'success' => false,
            'message' => 'غير مصرح لك بتنفيذ هذه العملية'
        ], 403);
    }
    
    // التحقق من نوع الطلب
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        returnJsonResponse([
            'success' => false,
            'message' => 'طريقة الطلب غير صحيحة'
        ], 405);
    }
    
    $db = db();
    
    // التحقق من صلاحيات المندوب
    $isSalesUser = ($currentRole === 'sales');
    
    // معاملات البحث والفلترة
    $search = trim($_GET['search'] ?? '');
    $debtStatus = $_GET['debt_status'] ?? 'all';
    $allowedDebtStatuses = ['all', 'debtor', 'clear'];
    if (!in_array($debtStatus, $allowedDebtStatuses, true)) {
        $debtStatus = 'all';
    }
    
    $regionFilter = isset($_GET['region_id']) && $_GET['region_id'] !== '' ? (int)$_GET['region_id'] : null;
    
    // معاملات pagination
    $pageNum = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
    $isMobile = isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/(android|iphone|ipad|mobile)/i', $_SERVER['HTTP_USER_AGENT']);
    $perPage = $isMobile ? 10 : 20;
    $offset = ($pageNum - 1) * $perPage;
    
    // بناء استعلام SQL
    $sql = "SELECT c.*, u.full_name as created_by_name, r.name as region_name
            FROM customers c
            LEFT JOIN users u ON c.created_by = u.id
            LEFT JOIN regions r ON c.region_id = r.id
            WHERE 1=1";
    
    $countSql = "SELECT COUNT(*) as total FROM customers WHERE 1=1";
    $params = [];
    $countParams = [];
    
    // فحص أمني: العملاء يظهرون فقط للمندوب الذي أنشأهم (created_by)
    if ($isSalesUser) {
        $currentUserId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
        $sql .= " AND c.created_by = ?";
        $countSql .= " AND created_by = ?";
        $params[] = $currentUserId;
        $countParams[] = $currentUserId;
    }
    
    // فلتر حالة الديون
    if ($debtStatus === 'debtor') {
        $sql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
        $countSql .= " AND (balance IS NOT NULL AND balance > 0)";
    } elseif ($debtStatus === 'clear') {
        $sql .= " AND (c.balance IS NULL OR c.balance <= 0)";
        $countSql .= " AND (balance IS NULL OR balance <= 0)";
    }
    
    // البحث في جميع بيانات العميل: الاسم، الهاتف، العنوان، المنطقة، الرقم، الهواتف الإضافية، من أضاف العميل، رصيد العميل
    if ($search) {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR r.name LIKE ? OR c.id LIKE ?
            OR EXISTS (SELECT 1 FROM customer_phones cp WHERE cp.customer_id = c.id AND cp.phone LIKE ?)
            OR u.full_name LIKE ? OR CAST(COALESCE(c.balance, 0) AS CHAR) LIKE ?)";
        $countSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ? OR region_id IN (SELECT id FROM regions WHERE name LIKE ?) OR id LIKE ?
            OR EXISTS (SELECT 1 FROM customer_phones cp WHERE cp.customer_id = customers.id AND cp.phone LIKE ?)
            OR created_by IN (SELECT id FROM users WHERE full_name LIKE ?) OR CAST(COALESCE(balance, 0) AS CHAR) LIKE ?)";
        $searchParam = '%' . $search . '%';
        for ($i = 0; $i < 8; $i++) { $params[] = $searchParam; }
        for ($i = 0; $i < 8; $i++) { $countParams[] = $searchParam; }
    }
    
    // فلتر المنطقة
    if ($regionFilter !== null) {
        $sql .= " AND c.region_id = ?";
        $countSql .= " AND region_id = ?";
        $params[] = $regionFilter;
        $countParams[] = $regionFilter;
    }
    
    // جلب العدد الإجمالي
    try {
        $totalResult = $db->queryOne($countSql, $countParams);
        $totalCustomers = $totalResult['total'] ?? 0;
        $totalPages = ceil($totalCustomers / $perPage);
    } catch (Exception $e) {
        error_log('Error executing count query: ' . $e->getMessage());
        $totalCustomers = 0;
        $totalPages = 0;
    }
    
    // جلب العملاء
    $sql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    $customers = $db->query($sql, $params);
    
    // جلب جميع أرقام الهواتف في استعلام واحد
    $customerPhonesMap = [];
    if (!empty($customers)) {
        $customerIds = array_column($customers, 'id');
        if (!empty($customerIds)) {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
            $allPhones = $db->query(
                "SELECT customer_id, phone, is_primary 
                 FROM customer_phones 
                 WHERE customer_id IN ($placeholders) 
                 ORDER BY customer_id, is_primary DESC, id ASC",
                $customerIds
            );
            
            // تجميع أرقام الهواتف حسب customer_id
            foreach ($allPhones as $phoneRow) {
                $customerId = (int)$phoneRow['customer_id'];
                if (!isset($customerPhonesMap[$customerId])) {
                    $customerPhonesMap[$customerId] = [];
                }
                $customerPhonesMap[$customerId][] = $phoneRow['phone'];
            }
        }
    }
    
    // بناء HTML للجدول
    ob_start();
    ?>
    <?php if (empty($customers)): ?>
        <tr>
            <td colspan="8" class="text-center text-muted">لا توجد عملاء</td>
        </tr>
    <?php else: ?>
        <?php foreach ($customers as $customer): ?>
            <?php
            $customerId = (int)$customer['id'];
            $phones = $customerPhonesMap[$customerId] ?? [];
            
            // إذا لم تكن هناك أرقام في customer_phones، استخدم الرقم القديم
            if (empty($phones) && !empty($customer['phone'])) {
                $phones = [$customer['phone']];
            }
            
            $customerBalanceValue = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
            $balanceBadgeClass = $customerBalanceValue > 0
                ? 'bg-warning-subtle text-warning'
                : ($customerBalanceValue < 0 ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary');
            $displayBalanceValue = $customerBalanceValue < 0 ? abs($customerBalanceValue) : $customerBalanceValue;
            
            $hasLocation = isset($customer['latitude'], $customer['longitude']) &&
                $customer['latitude'] !== null &&
                $customer['longitude'] !== null;
            $latValue = $hasLocation ? (float)$customer['latitude'] : null;
            $lngValue = $hasLocation ? (float)$customer['longitude'] : null;
            
            $rawBalance = number_format($customerBalanceValue, 2, '.', '');
            $formattedBalance = formatCurrency($displayBalanceValue);
            ?>
            <tr>
                <td><strong><?php echo $customerId; ?></strong></td>
                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                <td>
                    <?php
                    if (!empty($phones)) {
                        foreach ($phones as $phoneNumber) {
                            $phoneNumber = trim($phoneNumber ?? '');
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
                    <strong><?php echo $formattedBalance; ?></strong>
                    <?php if ($customerBalanceValue !== 0.0): ?>
                        <span class="badge <?php echo $balanceBadgeClass; ?> ms-1">
                            <?php echo $customerBalanceValue > 0 ? 'رصيد مدين' : 'رصيد دائن'; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($customer['region_name'] ?? '-'); ?></td>
                <td>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary location-capture-btn"
                            data-customer-id="<?php echo $customerId; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                        >
                            <i class="bi bi-geo-alt me-1"></i>تحديد
                        </button>
                        <?php if ($hasLocation): ?>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info location-view-btn"
                                data-customer-id="<?php echo $customerId; ?>"
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
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if (in_array($currentRole, ['manager', 'developer', 'accountant', 'sales'], true)): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-warning edit-customer-btn"
                            onclick="showEditCustomerModal(this)"
                            data-customer-id="<?php echo $customerId; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                            data-customer-region-id="<?php echo (int)($customer['region_id'] ?? 0); ?>"
                            data-customer-balance="<?php echo $rawBalance; ?>"
                        >
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </button>
                        <?php endif; ?>
                        <button
                            type="button"
                            class="btn btn-sm <?php echo $customerBalanceValue > 0 ? 'btn-success' : 'btn-outline-secondary'; ?>"
                            onclick="showCollectPaymentModal(this)"
                            data-customer-id="<?php echo $customerId; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                            data-customer-balance="<?php echo $rawBalance; ?>"
                            data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>"
                            <?php echo $customerBalanceValue > 0 ? '' : 'disabled'; ?>
                        >
                            <i class="bi bi-cash-coin me-1"></i>تحصيل
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-info customer-purchase-history-btn"
                            onclick="loadPurchaseHistory(<?php echo $customerId; ?>)"
                            data-customer-id="<?php echo $customerId; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                        >
                            <i class="bi bi-receipt me-1"></i>سجل
                        </button>
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
            <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="javascript:void(0);" onclick="loadCustomers(<?php echo $pageNum - 1; ?>)">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
            
            <?php
            $startPage = max(1, $pageNum - 2);
            $endPage = min($totalPages, $pageNum + 2);
            
            if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="javascript:void(0);" onclick="loadCustomers(1)">1</a></li>
                <?php if ($startPage > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                    <a class="page-link" href="javascript:void(0);" onclick="loadCustomers(<?php echo $i; ?>)">
                        <?php echo $i; ?>
                    </a>
                </li>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="javascript:void(0);" onclick="loadCustomers(<?php echo $totalPages; ?>)"><?php echo $totalPages; ?></a></li>
            <?php endif; ?>
            
            <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                <a class="page-link" href="javascript:void(0);" onclick="loadCustomers(<?php echo $pageNum + 1; ?>)">
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
        'currentPage' => $pageNum,
        'totalPages' => $totalPages
    ];
    
    returnJsonResponse($response);
    
} catch (Throwable $e) {
    error_log('Error in search_customers.php: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب البيانات'
    ], 500);
}

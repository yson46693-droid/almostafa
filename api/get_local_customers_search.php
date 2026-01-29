<?php
/**
 * API for Real-time Search of Local Customers
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
    $allowedRoles = ['manager', 'developer', 'accountant'];
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
            FROM local_customers c
            LEFT JOIN users u ON c.created_by = u.id
            LEFT JOIN regions r ON c.region_id = r.id
            WHERE 1=1";
    
    $countSql = "SELECT COUNT(*) as total FROM local_customers WHERE 1=1";
    $params = [];
    $countParams = [];
    
    // فلتر حالة الديون
    if ($debtStatus === 'debtor') {
        $sql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
        $countSql .= " AND (balance IS NOT NULL AND balance > 0)";
    } elseif ($debtStatus === 'clear') {
        $sql .= " AND (c.balance IS NULL OR c.balance <= 0)";
        $countSql .= " AND (balance IS NULL OR balance <= 0)";
    }
    
    // البحث في جميع بيانات العميل: الاسم، الهاتف، العنوان، المنطقة، الرقم، الهواتف الإضافية، من أضاف العميل
    if ($search) {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR r.name LIKE ? OR c.id LIKE ?
            OR EXISTS (SELECT 1 FROM local_customer_phones lcp WHERE lcp.customer_id = c.id AND lcp.phone LIKE ?)
            OR u.full_name LIKE ?)";
        $countSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ? OR region_id IN (SELECT id FROM regions WHERE name LIKE ?) OR id LIKE ?
            OR EXISTS (SELECT 1 FROM local_customer_phones lcp WHERE lcp.customer_id = local_customers.id AND lcp.phone LIKE ?)
            OR created_by IN (SELECT id FROM users WHERE full_name LIKE ?))";
        $searchParam = '%' . $search . '%';
        for ($i = 0; $i < 7; $i++) { $params[] = $searchParam; }
        for ($i = 0; $i < 7; $i++) { $countParams[] = $searchParam; }
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
                 FROM local_customer_phones 
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
    
    // تحضير البيانات للإرجاع
    $result = [];
    foreach ($customers as $customer) {
        $customerId = (int)$customer['id'];
        $phones = $customerPhonesMap[$customerId] ?? [];
        
        // إذا لم تكن هناك أرقام في local_customer_phones، استخدم الرقم القديم
        if (empty($phones) && !empty($customer['phone'])) {
            $phones = [$customer['phone']];
        }
        
        $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
        $displayBalance = $balance < 0 ? abs($balance) : $balance;
        $balanceBadgeClass = $balance > 0
            ? 'bg-warning-subtle text-warning'
            : ($balance < 0 ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary');
        
        $hasLocation = isset($customer['latitude'], $customer['longitude']) &&
            $customer['latitude'] !== null &&
            $customer['longitude'] !== null;

        $balanceUpdatedAt = isset($customer['balance_updated_at']) ? trim($customer['balance_updated_at']) : '';
        $balance_updated_at_formatted = '';
        if (!empty($balanceUpdatedAt) && function_exists('formatDateTime')) {
            $balance_updated_at_formatted = formatDateTime($balanceUpdatedAt);
        } elseif (!empty($balanceUpdatedAt)) {
            $balance_updated_at_formatted = date('Y-m-d H:i', strtotime($balanceUpdatedAt));
        }
        
        $result[] = [
            'id' => $customerId,
            'name' => htmlspecialchars($customer['name'] ?? ''),
            'phones' => $phones,
            'phone' => htmlspecialchars($customer['phone'] ?? ''),
            'balance' => $balance,
            'balance_display' => $displayBalance,
            'balance_formatted' => formatCurrency($displayBalance),
            'balance_badge_class' => $balanceBadgeClass,
            'balance_badge_text' => $balance > 0 ? 'رصيد مدين' : ($balance < 0 ? 'رصيد دائن' : ''),
            'address' => htmlspecialchars($customer['address'] ?? ''),
            'region_name' => htmlspecialchars($customer['region_name'] ?? ''),
            'region_id' => (int)($customer['region_id'] ?? 0),
            'has_location' => $hasLocation,
            'latitude' => $hasLocation ? (float)$customer['latitude'] : null,
            'longitude' => $hasLocation ? (float)$customer['longitude'] : null,
            'raw_balance' => number_format($balance, 2, '.', ''),
            'balance_updated_at' => $balanceUpdatedAt,
            'balance_updated_at_formatted' => $balance_updated_at_formatted,
        ];
    }
    
    returnJsonResponse([
        'success' => true,
        'customers' => $result,
        'pagination' => [
            'current_page' => $pageNum,
            'total_pages' => $totalPages,
            'total_customers' => $totalCustomers,
            'per_page' => $perPage,
        ],
        'filters' => [
            'search' => $search,
            'debt_status' => $debtStatus,
            'region_id' => $regionFilter,
        ],
    ]);
    
} catch (Throwable $e) {
    error_log('Error in get_local_customers_search.php: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب البيانات'
    ], 500);
}

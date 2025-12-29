<?php
/**
 * API for Getting Local Customers for Export
 * Returns detailed customer data for local customers
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
    
    // معاملات pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 4; // 5 عميل في كل صفحة
    $offset = ($page - 1) * $perPage;
    
    // جلب إجمالي عدد العملاء المدينين فقط
    $totalCountResult = $db->queryOne(
        "SELECT COUNT(*) as total
         FROM local_customers c
         WHERE c.status = 'active' AND c.balance > 0"
    );
    $totalCount = (int)($totalCountResult['total'] ?? 0);
    $totalPages = ceil($totalCount / $perPage);
    
    // جلب العملاء المحليين المدينين فقط مع pagination
    $customers = $db->query(
        "SELECT c.*, r.name as region_name
         FROM local_customers c
         LEFT JOIN regions r ON c.region_id = r.id
         WHERE c.status = 'active' AND c.balance > 0
         ORDER BY c.name ASC
         LIMIT ? OFFSET ?",
        [$perPage, $offset]
    );
    
    // جلب أرقام الهواتف الإضافية لكل عميل
    $result = [];
    foreach ($customers as $customer) {
        $customerId = (int)($customer['id'] ?? 0);
        
        // جلب أرقام الهواتف الإضافية (غير الأساسية)
        $additionalPhones = [];
        try {
            $phones = $db->query(
                "SELECT phone FROM local_customer_phones WHERE customer_id = ? AND is_primary = 0 ORDER BY id ASC",
                [$customerId]
            );
            
            foreach ($phones as $phoneRow) {
                $phone = trim($phoneRow['phone'] ?? '');
                if (!empty($phone)) {
                    $additionalPhones[] = $phone;
                }
            }
        } catch (Exception $e) {
            error_log('Error fetching local customer phones: ' . $e->getMessage());
        }
        
        $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
        
        $result[] = [
            'id' => $customerId,
            'name' => trim($customer['name'] ?? ''),
            'phone' => trim($customer['phone'] ?? ''),
            'alternative_phones' => $additionalPhones,
            'balance' => $balance,
            'balance_formatted' => number_format(abs($balance), 2) . ' ج.م',
            'address' => trim($customer['address'] ?? ''),
            'region_name' => trim($customer['region_name'] ?? ''),
        ];
    }
    
    returnJsonResponse([
        'success' => true,
        'customers' => $result,
        'total' => $totalCount,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'has_more' => $page < $totalPages
    ]);
    
} catch (Exception $e) {
    error_log('Get local customers API error: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب بيانات العملاء'
    ], 500);
}


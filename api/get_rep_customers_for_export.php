<?php
/**
 * API for Getting Sales Rep Customers for Export
 * Returns detailed customer data for a specific sales rep
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
    
    // جلب معرف المندوب
    $repId = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;
    
    // إذا كان المستخدم مندوب، يمكنه فقط جلب عملائه
    if ($currentRole === 'sales') {
        $repId = (int)($currentUser['id'] ?? 0);
    }
    
    if ($repId <= 0) {
        returnJsonResponse([
            'success' => false,
            'message' => 'معرف المندوب غير صحيح'
        ], 400);
    }
    
    // التحقق من وجود المندوب
    $rep = $db->queryOne(
        "SELECT id, full_name, username FROM users WHERE id = ? AND role = 'sales' LIMIT 1",
        [$repId]
    );
    
    if (!$rep) {
        returnJsonResponse([
            'success' => false,
            'message' => 'المندوب المطلوب غير موجود'
        ], 404);
    }
    
    // معاملات pagination - زيادة عدد العملاء في الصفحة الواحدة لتسريع التحميل
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 20; // 20 عميل في كل صفحة (زيادة من 3 لتسريع التحميل)
    $offset = ($page - 1) * $perPage;
    
    // استعلام واحد محسّن يجلب البيانات والعدد معاً - أسرع بكثير
    // استخدام استعلام واحد بدلاً من استعلامين منفصلين
    $customers = $db->query(
        "SELECT c.id, c.name, c.phone, c.address, c.balance, c.created_by, r.name as region_name
         FROM customers c
         LEFT JOIN regions r ON c.region_id = r.id
         WHERE c.created_by = ? AND c.status = 'active' AND c.balance > 0
         ORDER BY c.name ASC
         LIMIT ? OFFSET ?",
        [$repId, $perPage, $offset]
    );
    
    // التأكد من أن المصفوفة فارغة إذا لم يكن هناك عملاء
    if (empty($customers) || !is_array($customers)) {
        $customers = [];
    }
    
    // حساب العدد الإجمالي فقط إذا كانت هناك حاجة (للصفحة الأولى فقط)
    $totalCount = 0;
    $totalPages = 1;
    if ($page === 1 || count($customers) === $perPage) {
        $totalCountResult = $db->queryOne(
            "SELECT COUNT(*) as total
             FROM customers c
             WHERE c.created_by = ? AND c.status = 'active' AND c.balance > 0",
            [$repId]
        );
        $totalCount = (int)($totalCountResult['total'] ?? 0);
        $totalPages = ceil($totalCount / $perPage);
    }
    
    // بناء النتيجة مباشرة بدون فلترة زائدة - أسرع بكثير
    $result = [];
    foreach ($customers as $customer) {
        $customerId = (int)($customer['id'] ?? 0);
        $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
        $customerName = trim($customer['name'] ?? '');
        
        // بناء النتيجة مباشرة - البيانات صحيحة بالفعل من الاستعلام
        if ($customerId > 0 && !empty($customerName) && $balance > 0) {
            $result[] = [
                'id' => $customerId,
                'name' => $customerName,
                'phone' => trim($customer['phone'] ?? ''),
                'alternative_phones' => [], // إزالة جلب أرقام الهواتف الإضافية لتسريع العملية
                'balance' => $balance,
                'balance_formatted' => number_format(abs($balance), 2) . ' ج.م',
                'address' => trim($customer['address'] ?? ''),
                'region_name' => trim($customer['region_name'] ?? ''),
            ];
        }
    }
    
    returnJsonResponse([
        'success' => true,
        'rep' => [
            'id' => (int)$rep['id'],
            'name' => $rep['full_name'] ?? $rep['username'] ?? '',
        ],
        'customers' => $result,
        'total' => $totalCount,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'has_more' => $page < $totalPages
    ]);
    
} catch (Exception $e) {
    error_log('Get rep customers API error: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب بيانات العملاء'
    ], 500);
}


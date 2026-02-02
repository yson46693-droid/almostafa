<?php
/**
 * API للبحث الفوري في العملاء المحليين مع Autocomplete
 * يعمل كـ endpoint للبحث الفوري أثناء الكتابة
 */

// تعطيل عرض الأخطاء
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
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/path_helper.php';
    
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
    
    // معامل البحث
    $search = trim($_GET['q'] ?? '');
    
    // إذا لم يكن هناك بحث، إرجاع قائمة فارغة
    if (empty($search) || strlen($search) < 1) {
        returnJsonResponse([
            'success' => true,
            'results' => [],
            'count' => 0
        ]);
    }
    
    // حد أقصى للنتائج في autocomplete (10 نتائج)
    $limit = 10;
    
    // بناء استعلام SQL للبحث في جميع بيانات العميل (بما فيها رصيد العميل)
    $sql = "SELECT DISTINCT c.id, c.name, c.phone, c.address, c.balance, 
                   r.name as region_name, u.full_name as created_by_name
            FROM local_customers c
            LEFT JOIN regions r ON c.region_id = r.id
            LEFT JOIN users u ON c.created_by = u.id
            WHERE (c.name LIKE ? 
                OR c.phone LIKE ? 
                OR c.address LIKE ? 
                OR r.name LIKE ? 
                OR CAST(c.id AS CHAR) LIKE ?
                OR EXISTS (SELECT 1 FROM local_customer_phones lcp WHERE lcp.customer_id = c.id AND lcp.phone LIKE ?)
                OR u.full_name LIKE ?
                OR CAST(COALESCE(c.balance, 0) AS CHAR) LIKE ?)
            ORDER BY c.name ASC
            LIMIT ?";
    
    $searchParam = '%' . $search . '%';
    $params = [
        $searchParam, // c.name
        $searchParam, // c.phone
        $searchParam, // c.address
        $searchParam, // r.name
        $searchParam, // c.id
        $searchParam, // lcp.phone
        $searchParam, // u.full_name
        $searchParam, // c.balance
        $limit
    ];
    
    try {
        $customers = $db->query($sql, $params);
        
        // جلب أرقام الهواتف الإضافية للعملاء
        $customerPhonesMap = [];
        if (!empty($customers)) {
            $customerIds = array_column($customers, 'id');
            if (!empty($customerIds)) {
                $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
                $allPhones = $db->query(
                    "SELECT customer_id, phone 
                     FROM local_customer_phones 
                     WHERE customer_id IN ($placeholders)",
                    $customerIds
                );
                
                foreach ($allPhones as $phoneRow) {
                    $customerId = (int)$phoneRow['customer_id'];
                    if (!isset($customerPhonesMap[$customerId])) {
                        $customerPhonesMap[$customerId] = [];
                    }
                    $customerPhonesMap[$customerId][] = $phoneRow['phone'];
                }
            }
        }
        
        // تحضير النتائج
        $results = [];
        foreach ($customers as $customer) {
            $customerId = (int)$customer['id'];
            $phones = $customerPhonesMap[$customerId] ?? [];
            
            // إضافة الهاتف الأساسي إذا لم يكن موجوداً في القائمة
            if (!empty($customer['phone']) && !in_array($customer['phone'], $phones)) {
                array_unshift($phones, $customer['phone']);
            }
            
            $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
            
            // بناء نص العرض للنتيجة
            $displayText = htmlspecialchars($customer['name'] ?? '');
            $subText = [];
            
            if (!empty($phones)) {
                $subText[] = implode('، ', array_slice($phones, 0, 2));
            }
            if (!empty($customer['address'])) {
                $subText[] = htmlspecialchars($customer['address']);
            }
            if (!empty($customer['region_name'])) {
                $subText[] = htmlspecialchars($customer['region_name']);
            }
            
            $results[] = [
                'id' => $customerId,
                'name' => htmlspecialchars($customer['name'] ?? ''),
                'phone' => htmlspecialchars($customer['phone'] ?? ''),
                'phones' => $phones,
                'address' => htmlspecialchars($customer['address'] ?? ''),
                'region_name' => htmlspecialchars($customer['region_name'] ?? ''),
                'created_by_name' => htmlspecialchars($customer['created_by_name'] ?? ''),
                'balance' => $balance,
                'balance_formatted' => formatCurrency(abs($balance)),
                'display_text' => $displayText,
                'sub_text' => implode(' • ', $subText),
                'highlight' => $search // للتمييز في الواجهة
            ];
        }
        
        returnJsonResponse([
            'success' => true,
            'results' => $results,
            'count' => count($results),
            'query' => $search
        ]);
        
    } catch (Exception $e) {
        error_log('Error in fs.php search: ' . $e->getMessage());
        returnJsonResponse([
            'success' => false,
            'message' => 'حدث خطأ أثناء البحث'
        ], 500);
    }
    
} catch (Throwable $e) {
    error_log('Error in fs.php: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب البيانات'
    ], 500);
}

<?php
/**
 * API for Exporting Selected Customers to Excel
 * API endpoint for generating Excel file with selected customers data
 */

// ===== بداية الإعداد الحرج لضمان JSON فقط =====

// تعطيل عرض الأخطاء تماماً قبل أي شيء
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

// زيادة timeout و memory limit لدعم قوائم كبيرة
@ini_set('max_execution_time', '300'); // 5 دقائق
@ini_set('memory_limit', '512M');

// تنظيف أي output موجود
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// بدء output buffering جديد
ob_start();

// تعريف ثوابت الوصول
define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

// دالة الإرجاع JSON - معرّفة مبكراً للاستخدام في حالات الخطأ
function returnJsonResponse(array $data, int $status = 200): void
{
    // تنظيف أي output
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // إرسال headers
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    }
    
    // تحويل إلى JSON
    $json = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"success":false,"message":"خطأ في تنسيق البيانات"}';
    }
    
    echo $json;
    exit;
}

// معالج الأخطاء المخصص
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
}

// معالج الاستثناءات المخصص
function customExceptionHandler($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ غير متوقع'
    ], 500);
}

// معالج الإغلاق للأخطاء القاتلة
function shutdownHandler() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}");
        
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        
        echo '{"success":false,"message":"حدث خطأ في الخادم"}';
    }
}

// تسجيل المعالجات
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');
register_shutdown_function('shutdownHandler');

// ===== تحميل الملفات المطلوبة =====
try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    require_once __DIR__ . '/../includes/simple_export.php';
    
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        returnJsonResponse([
            'success' => false,
            'message' => 'طريقة الطلب غير صحيحة'
        ], 405);
    }
    
    // قراءة البيانات المرسلة (دعم FormData و JSON)
    $data = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // محاولة قراءة من FormData أولاً
        if (isset($_POST['customer_ids']) && isset($_POST['section'])) {
            $data['customer_ids'] = json_decode($_POST['customer_ids'], true);
            $data['collection_amounts'] = json_decode($_POST['collection_amounts'] ?? '{}', true);
            $data['section'] = $_POST['section'] ?? 'company';
            $data['rep_id'] = isset($_POST['rep_id']) ? (int)$_POST['rep_id'] : null;
        } else {
            // محاولة قراءة من JSON
            $input = file_get_contents('php://input');
            if (!empty($input)) {
                $data = json_decode($input, true);
            }
        }
    }
    
    if (!is_array($data) || empty($data)) {
        returnJsonResponse([
            'success' => false,
            'message' => 'بيانات غير صحيحة'
        ], 400);
    }
    
    // التحقق من وجود معرفات العملاء
    $customerIds = $data['customer_ids'] ?? [];
    if (!is_array($customerIds) || empty($customerIds)) {
        returnJsonResponse([
            'success' => false,
            'message' => 'لم يتم تحديد أي عملاء للتصدير'
        ], 400);
    }
    
    // تنظيف معرفات العملاء
    $customerIds = array_map('intval', $customerIds);
    $customerIds = array_filter($customerIds, function($id) {
        return $id > 0;
    });
    
    if (empty($customerIds)) {
        returnJsonResponse([
            'success' => false,
            'message' => 'معرفات العملاء غير صحيحة'
        ], 400);
    }
    
    // قراءة مبالغ التحصيل
    $collectionAmounts = $data['collection_amounts'] ?? [];
    if (!is_array($collectionAmounts)) {
        $collectionAmounts = [];
    }
    
    $db = db();
    
    // تحديد نوع العملاء (company/delegates/local)
    $section = $data['section'] ?? 'company';
    $isLocalCustomers = ($section === 'local');
    
    // بناء استعلام SQL لجلب بيانات العملاء
    $placeholders = str_repeat('?,', count($customerIds) - 1) . '?';
    
    if ($isLocalCustomers) {
        // جلب العملاء المحليين
        $sql = "SELECT c.*, r.name as region_name
                FROM local_customers c
                LEFT JOIN regions r ON c.region_id = r.id
                WHERE c.id IN ($placeholders)
                ORDER BY c.name ASC";
        $phonesTable = 'local_customer_phones';
        $customerIdColumn = 'customer_id';
    } else {
        // جلب عملاء الشركة أو المندوبين
        $sql = "SELECT c.*, r.name as region_name
                FROM customers c
                LEFT JOIN regions r ON c.region_id = r.id
                WHERE c.id IN ($placeholders)
                ORDER BY c.name ASC";
        $phonesTable = 'customer_phones';
        $customerIdColumn = 'customer_id';
    }
    
    $customers = $db->query($sql, $customerIds);
    
    if (empty($customers)) {
        returnJsonResponse([
            'success' => false,
            'message' => 'لم يتم العثور على أي عملاء'
        ], 404);
    }
    
    // التحقق من صلاحيات المندوب (sales)
    if ($currentRole === 'sales') {
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $filteredCustomers = [];
        foreach ($customers as $customer) {
            // المندوب يمكنه فقط تصدير العملاء الذين أنشأهم
            if (isset($customer['created_by']) && (int)$customer['created_by'] === $currentUserId) {
                $filteredCustomers[] = $customer;
            }
        }
        $customers = $filteredCustomers;
        
        if (empty($customers)) {
            returnJsonResponse([
                'success' => false,
                'message' => 'غير مصرح لك بتصدير هؤلاء العملاء'
            ], 403);
        }
    }
    
    // جلب أرقام الهواتف الإضافية لكل عميل
    $exportData = [];
    foreach ($customers as $customer) {
        $customerId = (int)($customer['id'] ?? 0);
        
        // جلب أرقام الهواتف الإضافية (غير الأساسية)
        $additionalPhones = [];
        try {
            $phones = $db->query(
                "SELECT phone FROM {$phonesTable} WHERE {$customerIdColumn} = ? AND is_primary = 0 ORDER BY id ASC",
                [$customerId]
            );
            
            foreach ($phones as $phoneRow) {
                $phone = trim($phoneRow['phone'] ?? '');
                if (!empty($phone)) {
                    $additionalPhones[] = $phone;
                }
            }
        } catch (Exception $e) {
            error_log('Error fetching customer phones: ' . $e->getMessage());
        }
        
        // الهاتف الأساسي
        $primaryPhone = trim($customer['phone'] ?? '');
        
        // رقم الهاتف الآخر (جميع الأرقام الإضافية مفصولة بفواصل)
        $alternativePhone = !empty($additionalPhones) ? implode('، ', $additionalPhones) : '';
        
        // الرصيد
        $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
        $formattedBalance = number_format($balance, 2);
        
        // العنوان
        $address = trim($customer['address'] ?? '');
        
        // المنطقة
        $regionName = trim($customer['region_name'] ?? '');
        
        // مبلغ التحصيل (إن وُجد)
        $collectionAmount = '';
        if (isset($collectionAmounts[$customerId]) && $collectionAmounts[$customerId] !== '' && $collectionAmounts[$customerId] !== null) {
            $amount = (float)$collectionAmounts[$customerId];
            if ($amount > 0) {
                $collectionAmount = number_format($amount, 2);
            }
        }
        
        $exportData[] = [
            'اسم العميل' => trim($customer['name'] ?? ''),
            'رقم الهاتف' => $primaryPhone,
            'رقم الهاتف الآخر' => $alternativePhone,
            'العنوان' => $address,
            'المنطقة' => $regionName,
            'رصيد العميل' => $formattedBalance,
            'المبلغ المراد تحصيله' => $collectionAmount,
        ];
    }
    
    // تحديد العنوان بناءً على نوع العملاء
    $title = 'تصدير_العملاء_المحددين';
    if (isset($data['section'])) {
        if ($data['section'] === 'delegates') {
            $title = 'تصدير_عملاء_المندوبين';
        } elseif ($data['section'] === 'company') {
            $title = 'تصدير_عملاء_الشركة';
        } elseif ($data['section'] === 'local') {
            $title = 'تصدير_العملاء_المحليين';
        }
    }
    
    // إنشاء ملف Excel/CSV
    try {
        $filePath = exportCSV($exportData, $title, []);
        
        // إنشاء رابط الملف
        $relativePath = str_replace(BASE_PATH, '', $filePath);
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');
        
        // التأكد من أن المسار يبدأ بـ reports/
        if (strpos($relativePath, 'reports/') !== 0) {
            $relativePath = 'reports/' . ltrim($relativePath, '/');
        }
        
        // استخدام getAbsoluteUrl لإنشاء رابط الملف بشكل صحيح
        $fileUrl = getAbsoluteUrl($relativePath);
        
        // مسار نسبي للاستخدام في view_csv_for_print.php
        $filePathForView = $relativePath;
        
        returnJsonResponse([
            'success' => true,
            'message' => 'تم إنشاء ملف Excel بنجاح',
            'file_url' => $fileUrl,
            'file_path' => $filePathForView,
            'relative_path' => $filePathForView,
            'total_customers' => count($exportData)
        ]);
        
    } catch (Exception $e) {
        error_log('Excel export error: ' . $e->getMessage());
        error_log('Excel export error trace: ' . $e->getTraceAsString());
        returnJsonResponse([
            'success' => false,
            'message' => 'فشل في إنشاء ملف Excel: ' . $e->getMessage()
        ], 500);
    }
    
} catch (Exception $e) {
    error_log('Export customers API error: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ أثناء تصدير العملاء'
    ], 500);
}


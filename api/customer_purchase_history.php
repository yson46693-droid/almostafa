<?php
/**
 * API for Customer Purchase History
 * API endpoint for retrieving customer purchase history with batch numbers
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
    return true; // منع PHP من معالجة الخطأ بشكل افتراضي
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
        
        // تنظيف أي output
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
    
    // تحميل product_name_helper إذا كان موجوداً
    if (file_exists(__DIR__ . '/../includes/product_name_helper.php')) {
        require_once __DIR__ . '/../includes/product_name_helper.php';
    }
} catch (Throwable $e) {
    error_log('Error loading includes: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'خطأ في تحميل ملفات النظام'
    ], 500);
}

// تنظيف أي output بعد تحميل الملفات
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// إعادة تعطيل عرض الأخطاء بعد تحميل config
@ini_set('display_errors', '0');
error_reporting(0);

// ===== معالجة الطلب =====
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$action) {
    returnJsonResponse(['success' => false, 'message' => 'الإجراء غير معروف'], 400);
}

// التحقق من تسجيل الدخول
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    returnJsonResponse(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
}

$currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
if (!$currentUser) {
    returnJsonResponse(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
}

$allowedRoles = ['sales', 'manager', 'accountant'];
if (!in_array($currentUser['role'] ?? '', $allowedRoles, true)) {
    returnJsonResponse(['success' => false, 'message' => 'ليس لديك صلاحية للوصول إلى هذه الصفحة'], 403);
}

try {
    switch ($action) {
        case 'get_history':
            if ($method !== 'GET') {
                returnJsonResponse(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleGetHistory($currentUser);
            break;
            
        case 'search':
            if ($method !== 'GET') {
                returnJsonResponse(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleSearch($currentUser);
            break;

        case 'get_invoice_by_number':
            if ($method !== 'GET') {
                returnJsonResponse(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleGetInvoiceByNumber($currentUser);
            break;
            
        default:
            returnJsonResponse(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('customer_purchase_history API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    returnJsonResponse(['success' => false, 'message' => 'حدث خطأ غير متوقع أثناء جلب البيانات'], 500);
}

/**
 * Get customer purchase history
 */
function handleGetHistory($currentUser): void
{
    try {
        $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
        $customerType = isset($_GET['type']) ? trim($_GET['type']) : 'normal';
        $isLocalCustomer = ($customerType === 'local');
        
        // تسجيل للتشخيص
        error_log("handleGetHistory: customer_id=$customerId, type=$customerType, isLocal=" . ($isLocalCustomer ? 'true' : 'false'));
        
        if ($customerId <= 0) {
            returnJsonResponse(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
        }
        
        $db = db();
        
        // Verify customer exists
        if ($isLocalCustomer) {
            $customer = $db->queryOne(
                "SELECT id, name, phone, address, balance FROM local_customers WHERE id = ?",
                [$customerId]
            );
        } else {
            $customer = $db->queryOne(
                "SELECT id, name, phone, address, created_by, balance FROM customers WHERE id = ?",
                [$customerId]
            );
            
            // التحقق من ملكية العميل للمندوب
            if ($currentUser['role'] === 'sales') {
                $salesRepId = (int)$currentUser['id'];
                if ((int)($customer['created_by'] ?? 0) !== $salesRepId) {
                    returnJsonResponse(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
                }
            }
        }
        
        if (!$customer) {
            returnJsonResponse(['success' => false, 'message' => 'العميل غير موجود'], 404);
        }
        
        $purchaseHistory = [];
        
        // Get purchase history based on customer type
        if ($isLocalCustomer) {
            $purchaseHistory = getLocalCustomerPurchaseHistory($db, $customerId);
        } else {
            $purchaseHistory = getNormalCustomerPurchaseHistory($db, $customerId);
        }
        
        // Calculate returned quantities
        $returnedQuantities = getReturnedQuantities($db, $customerId, $isLocalCustomer);
        
        // Format results
        $result = formatPurchaseHistory($purchaseHistory, $returnedQuantities, $isLocalCustomer, $db);
        
        $paperInvoices = [];
        if ($isLocalCustomer) {
            $paperInvoices = getLocalCustomerPaperInvoices($db, $customerId);
        }
        
        returnJsonResponse([
            'success' => true,
            'customer' => [
                'id' => (int)$customer['id'],
                'name' => $customer['name'],
                'phone' => $customer['phone'] ?? '',
                'address' => $customer['address'] ?? '',
                'balance' => (float)($customer['balance'] ?? 0)
            ],
            'purchase_history' => $result,
            'paper_invoices' => $paperInvoices
        ]);
        
    } catch (Throwable $e) {
        error_log('handleGetHistory error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        returnJsonResponse([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب سجل المشتريات',
            'purchase_history' => []
        ], 500);
    }
}

/**
 * Get local customer purchase history
 */
function getLocalCustomerPurchaseHistory($db, $customerId): array
{
    try {
        // التحقق من وجود الجداول
        $localInvoicesExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
        if (empty($localInvoicesExists)) {
            return [];
        }
        
        $localInvoiceItemsExists = $db->queryOne("SHOW TABLES LIKE 'local_invoice_items'");
        if (empty($localInvoiceItemsExists)) {
            return [];
        }
        
        // التحقق من وجود الأعمدة
        $hasBatchNumber = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_number'"));
        $hasBatchId = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_id'"));
        $hasDescription = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'description'"));
        
        // بناء SELECT للمنتج
        $productSelect = "COALESCE(NULLIF(TRIM(p.name), ''), 'غير محدد') as product_name";
        if ($hasDescription) {
            $productSelect = "COALESCE(
                CASE WHEN ii.description IS NOT NULL AND TRIM(ii.description) != '' AND ii.description NOT LIKE 'منتج رقم%' 
                     THEN TRIM(ii.description) ELSE NULL END,
                    NULLIF(TRIM(p.name), ''),
                    'غير محدد'
                ) as product_name";
            }
            
        // بناء SELECT للتشغيلة
        $batchSelect = "'' as batch_numbers, '' as batch_number_ids";
        if ($hasBatchNumber) {
            $batchSelect = "COALESCE(NULLIF(TRIM(ii.batch_number), ''), '') as batch_numbers, '' as batch_number_ids";
        }
        
        // تسجيل للتشخيص
        error_log("getLocalCustomerPurchaseHistory: Fetching history for customer_id=$customerId");
        
        // التحقق من وجود العميل أولاً
        $customerExists = $db->queryOne(
            "SELECT id, name FROM local_customers WHERE id = ?",
            [$customerId]
        );
        
        if (empty($customerExists)) {
            error_log("getLocalCustomerPurchaseHistory: Customer with id=$customerId does not exist, returning empty array");
            return [];
        }
        
        error_log("getLocalCustomerPurchaseHistory: Customer exists - ID: {$customerExists['id']}, Name: {$customerExists['name']}");
        
        // التحقق من عدد الفواتير الموجودة لهذا العميل
        $invoiceCount = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM local_invoices WHERE customer_id = ? AND customer_id IS NOT NULL AND customer_id > 0",
            [$customerId]
        );
        error_log("getLocalCustomerPurchaseHistory: Invoice count for customer_id=$customerId is " . ($invoiceCount['cnt'] ?? 0));
        
        // التحقق من وجود فواتير بدون customer_id صحيح (للتشخيص)
        $orphanedInvoices = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM local_invoices WHERE (customer_id IS NULL OR customer_id = 0 OR customer_id NOT IN (SELECT id FROM local_customers))"
        );
        if (!empty($orphanedInvoices) && (int)($orphanedInvoices['cnt'] ?? 0) > 0) {
            error_log("getLocalCustomerPurchaseHistory: WARNING - Found " . ($orphanedInvoices['cnt'] ?? 0) . " orphaned invoices (without valid customer_id)");
        }
        
        // إذا لم تكن هناك فواتير، أرجع مصفوفة فارغة مباشرة
        if (empty($invoiceCount) || (int)($invoiceCount['cnt'] ?? 0) === 0) {
            error_log("getLocalCustomerPurchaseHistory: No invoices found for customer_id=$customerId, returning empty array");
            return [];
        }
        
        $query = "SELECT 
            i.id as invoice_id,
            i.invoice_number,
            i.date as invoice_date,
            i.total_amount,
            i.paid_amount,
            i.status as invoice_status,
            i.customer_id as invoice_customer_id,
            ii.id as invoice_item_id,
            ii.product_id,
            $productSelect,
            COALESCE(p.unit, 'قطعة') as unit,
            ii.quantity,
            ii.unit_price,
            ii.total_price,
            $batchSelect
        FROM local_invoices i
        INNER JOIN local_invoice_items ii ON i.id = ii.invoice_id
        LEFT JOIN products p ON ii.product_id = p.id
        WHERE i.customer_id = ? AND i.customer_id IS NOT NULL AND i.customer_id > 0
        ORDER BY i.date DESC, i.id DESC, ii.id ASC";
        
        $results = $db->query($query, [$customerId]) ?: [];
        
        // فحص إضافي: التأكد من أن جميع الفواتير تنتمي للعميل المطلوب
        $filteredResults = [];
        foreach ($results as $row) {
            $rowCustomerId = (int)($row['invoice_customer_id'] ?? 0);
            if ($rowCustomerId === $customerId) {
                // إزالة invoice_customer_id من النتائج النهائية
                unset($row['invoice_customer_id']);
                $filteredResults[] = $row;
            } else {
                error_log("getLocalCustomerPurchaseHistory: WARNING - Found invoice with wrong customer_id. Invoice ID: " . ($row['invoice_id'] ?? 'N/A') . ", Expected customer_id: $customerId, Found customer_id: $rowCustomerId");
            }
        }
        
        error_log("getLocalCustomerPurchaseHistory: Found " . count($results) . " items, filtered to " . count($filteredResults) . " items for customer_id=$customerId");
        
        return $filteredResults;
        
    } catch (Throwable $e) {
        error_log('getLocalCustomerPurchaseHistory error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get local customer paper invoices (فاتورة ورقية - رصيد دائن + صورة)
 */
function getLocalCustomerPaperInvoices($db, $customerId): array
{
    try {
        $tableExists = $db->queryOne("SHOW TABLES LIKE 'local_customer_paper_invoices'");
        if (empty($tableExists)) {
            return [];
        }
        $invoiceNumberCol = $db->queryOne("SHOW COLUMNS FROM local_customer_paper_invoices LIKE 'invoice_number'");
        if (empty($invoiceNumberCol)) {
            @$db->rawQuery("ALTER TABLE local_customer_paper_invoices ADD COLUMN invoice_number varchar(100) DEFAULT NULL COMMENT 'رقم الفاتورة (يدوي)' AFTER customer_id");
        }
        $rows = $db->query(
            "SELECT id, customer_id, invoice_number, total_amount, image_path, created_at FROM local_customer_paper_invoices WHERE customer_id = ? ORDER BY created_at DESC, id DESC",
            [$customerId]
        );
        if (!$rows) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $invNum = !empty(trim($row['invoice_number'] ?? '')) ? trim($row['invoice_number']) : ('ورقية-' . (int)$row['id']);
            $out[] = [
                'id' => (int)$row['id'],
                'customer_id' => (int)$row['customer_id'],
                'total_amount' => (float)($row['total_amount'] ?? 0),
                'image_path' => $row['image_path'] ?? '',
                'created_at' => $row['created_at'] ?? '',
                'invoice_number' => $invNum,
                'invoice_date' => isset($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : ''
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getLocalCustomerPaperInvoices error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get normal customer purchase history
 */
function getNormalCustomerPurchaseHistory($db, $customerId): array
{
    try {
        $query = "SELECT 
                i.id as invoice_id,
                i.invoice_number,
                i.date as invoice_date,
                i.total_amount,
                i.paid_amount,
                i.status as invoice_status,
                ii.id as invoice_item_id,
                ii.product_id,
            COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('منتج رقم ', p.id)) as product_name,
            COALESCE(p.unit, 'قطعة') as unit,
                ii.quantity,
                ii.unit_price,
                ii.total_price,
            COALESCE(NULLIF(TRIM(GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ')), ''), '') as batch_numbers,
            COALESCE(NULLIF(TRIM(GROUP_CONCAT(DISTINCT bn.id ORDER BY bn.id SEPARATOR ',')), ''), '') as batch_number_ids
            FROM invoices i
            INNER JOIN invoice_items ii ON i.id = ii.invoice_id
            LEFT JOIN products p ON ii.product_id = p.id
            LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
            LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id AND bn.batch_number IS NOT NULL AND TRIM(bn.batch_number) != ''
            WHERE i.customer_id = ?
            GROUP BY i.id, ii.id, ii.product_id, ii.quantity, ii.unit_price, ii.total_price, p.name, p.unit
        ORDER BY i.date DESC, i.id DESC, ii.id ASC";
        
        return $db->query($query, [$customerId]) ?: [];
        
    } catch (Throwable $e) {
        error_log('getNormalCustomerPurchaseHistory error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get returned quantities
 */
function getReturnedQuantities($db, $customerId, $isLocalCustomer): array
{
    $returnedQuantities = [];
    
    try {
        // التحقق من وجود عمود invoice_item_id
        $hasInvoiceItemId = !empty($db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'"));
        if (!$hasInvoiceItemId) {
            return [];
        }
        
        if ($isLocalCustomer) {
            // للعملاء المحليين
            $localReturnsExists = $db->queryOne("SHOW TABLES LIKE 'local_returns'");
            $localReturnItemsExists = $db->queryOne("SHOW TABLES LIKE 'local_return_items'");
            
            if (!empty($localReturnsExists) && !empty($localReturnItemsExists)) {
                    $hasLocalInvoiceItemId = !empty($db->queryOne("SHOW COLUMNS FROM local_return_items LIKE 'invoice_item_id'"));
                    if ($hasLocalInvoiceItemId) {
                    $rows = $db->query(
                            "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                             FROM local_return_items ri
                             INNER JOIN local_returns r ON r.id = ri.return_id
                             WHERE r.customer_id = ?
                               AND r.status IN ('pending', 'approved', 'processed', 'completed')
                               AND ri.invoice_item_id IS NOT NULL
                             GROUP BY ri.invoice_item_id",
                            [$customerId]
                        ) ?: [];
                        
                    foreach ($rows as $row) {
                        $returnedQuantities[(int)$row['invoice_item_id']] = (float)$row['returned_quantity'];
                    }
                }
            }
        } else {
            // للعملاء العاديين
            $rows = $db->query(
                "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                 FROM return_items ri
                 INNER JOIN returns r ON r.id = ri.return_id
                 WHERE r.customer_id = ?
                   AND r.status IN ('pending', 'approved', 'processed', 'completed')
                   AND ri.invoice_item_id IS NOT NULL
                 GROUP BY ri.invoice_item_id",
                [$customerId]
            ) ?: [];
            
            foreach ($rows as $row) {
                $returnedQuantities[(int)$row['invoice_item_id']] = (float)$row['returned_quantity'];
            }
        }
    } catch (Throwable $e) {
        error_log('getReturnedQuantities error: ' . $e->getMessage());
    }
    
    return $returnedQuantities;
}

/**
 * Format purchase history results
 */
function formatPurchaseHistory(array $purchaseHistory, array $returnedQuantities, bool $isLocalCustomer, $db): array
{
    $result = [];
    
    foreach ($purchaseHistory as $item) {
        $invoiceItemId = (int)$item['invoice_item_id'];
        $quantity = (float)$item['quantity'];
        
        // معالجة batch numbers
        $batchNumbers = [];
        $batchNumberIds = [];
        
        if (!empty($item['batch_numbers']) && trim($item['batch_numbers']) !== '') {
                $batchNumbersStr = trim($item['batch_numbers']);
                if (strpos($batchNumbersStr, ',') !== false) {
                    $batchNumbers = array_filter(array_map('trim', explode(',', $batchNumbersStr)));
                } else {
                    $batchNumbers = [$batchNumbersStr];
            }
        }
        
        if (!empty($item['batch_number_ids']) && trim($item['batch_number_ids']) !== '') {
            $batchNumberIds = array_map('intval', array_filter(explode(',', trim($item['batch_number_ids']))));
        }
        
        // حساب الكمية المرتجعة
        $returnedQuantity = $returnedQuantities[$invoiceItemId] ?? 0.0;
        $availableToReturn = max(0, $quantity - $returnedQuantity);
        
        $result[] = [
            'invoice_id' => (int)$item['invoice_id'],
            'invoice_number' => $item['invoice_number'] ?? '',
            'invoice_date' => $item['invoice_date'] ?? '',
            'invoice_item_id' => $invoiceItemId,
            'product_id' => (int)($item['product_id'] ?? 0),
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? 'قطعة',
            'quantity' => $quantity,
            'returned_quantity' => $returnedQuantity,
            'available_to_return' => $availableToReturn,
            'unit_price' => (float)($item['unit_price'] ?? 0),
            'total_price' => (float)($item['total_price'] ?? 0),
            'batch_numbers' => $batchNumbers,
            'batch_number_ids' => $batchNumberIds,
            'can_return' => $availableToReturn > 0
        ];
    }
    
    return $result;
}

/**
 * Get invoice by number for return form - validate invoice belongs to customer and return items with available_to_return
 */
function handleGetInvoiceByNumber($currentUser): void
{
    try {
        $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
        $invoiceNumber = isset($_GET['invoice_number']) ? trim($_GET['invoice_number']) : '';
        $customerType = isset($_GET['type']) ? trim($_GET['type']) : 'local';
        $isLocalCustomer = ($customerType === 'local');

        if ($customerId <= 0) {
            returnJsonResponse(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
        }
        if ($invoiceNumber === '') {
            returnJsonResponse(['success' => false, 'message' => 'يرجى إدخال رقم الفاتورة'], 422);
        }

        $db = db();

        if ($isLocalCustomer) {
            $invoice = $db->queryOne(
                "SELECT id, invoice_number, date, total_amount, customer_id FROM local_invoices WHERE invoice_number = ? AND customer_id = ? LIMIT 1",
                [$invoiceNumber, $customerId]
            );
            if (!$invoice) {
                returnJsonResponse(['success' => false, 'message' => 'الفاتورة غير موجودة أو غير مرتبطة بهذا العميل'], 404);
            }
            $returnedQuantities = getReturnedQuantities($db, $customerId, true);
            $hasDescription = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'description'"));
            $productSelect = "COALESCE(NULLIF(TRIM(p.name), ''), 'غير محدد') as product_name";
            if ($hasDescription) {
                $productSelect = "COALESCE(
                    CASE WHEN ii.description IS NOT NULL AND TRIM(ii.description) != '' AND ii.description NOT LIKE 'منتج رقم%' THEN TRIM(ii.description) ELSE NULL END,
                    NULLIF(TRIM(p.name), ''),
                    'غير محدد'
                ) as product_name";
            }
            $rows = $db->query(
                "SELECT ii.id as invoice_item_id, ii.product_id, $productSelect, ii.quantity, ii.unit_price, ii.total_price
                 FROM local_invoice_items ii
                 LEFT JOIN products p ON ii.product_id = p.id
                 WHERE ii.invoice_id = ?
                 ORDER BY ii.id ASC",
                [$invoice['id']]
            ) ?: [];
        } else {
            $customer = $db->queryOne("SELECT id, created_by FROM customers WHERE id = ?", [$customerId]);
            if (!$customer) {
                returnJsonResponse(['success' => false, 'message' => 'العميل غير موجود'], 404);
            }
            if ($currentUser['role'] === 'sales') {
                $salesRepId = (int)$currentUser['id'];
                if ((int)($customer['created_by'] ?? 0) !== $salesRepId) {
                    returnJsonResponse(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
                }
            }
            $invoice = $db->queryOne(
                "SELECT id, invoice_number, date, total_amount, customer_id FROM invoices WHERE invoice_number = ? AND customer_id = ? LIMIT 1",
                [$invoiceNumber, $customerId]
            );
            if (!$invoice) {
                returnJsonResponse(['success' => false, 'message' => 'الفاتورة غير موجودة أو غير مرتبطة بهذا العميل'], 404);
            }
            $returnedQuantities = getReturnedQuantities($db, $customerId, false);
            $rows = $db->query(
                "SELECT ii.id as invoice_item_id, ii.product_id,
                 COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('منتج رقم ', p.id)) as product_name,
                 ii.quantity, ii.unit_price, ii.total_price
                 FROM invoice_items ii
                 LEFT JOIN products p ON ii.product_id = p.id
                 WHERE ii.invoice_id = ?
                 ORDER BY ii.id ASC",
                [$invoice['id']]
            ) ?: [];
        }

        $items = [];
        foreach ($rows as $row) {
            $invoiceItemId = (int)$row['invoice_item_id'];
            $quantity = (float)$row['quantity'];
            $returnedQuantity = $returnedQuantities[$invoiceItemId] ?? 0.0;
            $availableToReturn = max(0, $quantity - $returnedQuantity);
            $unitPrice = (float)($row['unit_price'] ?? 0);
            $totalPrice = (float)($row['total_price'] ?? 0);
            $items[] = [
                'invoice_item_id' => $invoiceItemId,
                'product_id' => (int)($row['product_id'] ?? 0),
                'product_name' => $row['product_name'] ?? 'غير معروف',
                'quantity' => $quantity,
                'returned_quantity' => $returnedQuantity,
                'available_to_return' => $availableToReturn,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'can_return' => $availableToReturn > 0
            ];
        }

        returnJsonResponse([
            'success' => true,
            'invoice' => [
                'id' => (int)$invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'date' => $invoice['date'] ?? '',
                'total_amount' => (float)($invoice['total_amount'] ?? 0)
            ],
            'items' => $items
        ]);
    } catch (Throwable $e) {
        error_log('handleGetInvoiceByNumber error: ' . $e->getMessage());
        returnJsonResponse(['success' => false, 'message' => 'حدث خطأ أثناء جلب بيانات الفاتورة'], 500);
    }
}

/**
 * Search purchase history
 */
function handleSearch($currentUser): void
{
    try {
        $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
        $batchNumber = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';
        $productName = isset($_GET['product_name']) ? trim($_GET['product_name']) : '';
        
        if ($customerId <= 0) {
            returnJsonResponse(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
        }
        
        $db = db();
        
        // Verify customer
        $customer = $db->queryOne(
            "SELECT id, name, created_by FROM customers WHERE id = ?",
            [$customerId]
        );
        
        if (!$customer) {
            returnJsonResponse(['success' => false, 'message' => 'العميل غير موجود'], 404);
        }
        
        if ($currentUser['role'] === 'sales') {
            $salesRepId = (int)$currentUser['id'];
            if ((int)($customer['created_by'] ?? 0) !== $salesRepId) {
                returnJsonResponse(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
            }
        }
        
        // Build search query
        $sql = "SELECT 
                i.id as invoice_id,
                i.invoice_number,
                i.date as invoice_date,
                ii.id as invoice_item_id,
                ii.product_id,
                COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('منتج رقم ', p.id)) as product_name,
                p.unit,
                ii.quantity,
                ii.unit_price,
                ii.total_price,
                GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ') as batch_numbers,
                GROUP_CONCAT(DISTINCT bn.id ORDER BY bn.id SEPARATOR ',') as batch_number_ids
            FROM invoices i
            INNER JOIN invoice_items ii ON i.id = ii.invoice_id
            LEFT JOIN products p ON ii.product_id = p.id
            LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
            LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
            WHERE i.customer_id = ?";
        
        $params = [$customerId];
        
        if ($batchNumber) {
            $sql .= " AND bn.batch_number LIKE ?";
            $params[] = "%{$batchNumber}%";
        }
        
        if ($productName) {
            $sql .= " AND p.name LIKE ?";
            $params[] = "%{$productName}%";
        }
        
        $sql .= " GROUP BY i.id, ii.id ORDER BY i.date DESC, i.id DESC";
        
        $results = $db->query($sql, $params) ?: [];
        
        $formatted = [];
        foreach ($results as $item) {
            $formatted[] = [
                'invoice_id' => (int)$item['invoice_id'],
                'invoice_number' => $item['invoice_number'],
                'invoice_date' => $item['invoice_date'],
                'invoice_item_id' => (int)$item['invoice_item_id'],
                'product_id' => (int)$item['product_id'],
                'product_name' => $item['product_name'] ?? 'غير معروف',
                'unit' => $item['unit'] ?? 'قطعة',
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'total_price' => (float)$item['total_price'],
                'batch_numbers' => !empty($item['batch_numbers']) ? explode(', ', $item['batch_numbers']) : [],
                'batch_number_ids' => !empty($item['batch_number_ids']) ? array_map('intval', explode(',', $item['batch_number_ids'])) : []
            ];
        }
        
        returnJsonResponse([
            'success' => true,
            'results' => $formatted
        ]);
        
    } catch (Throwable $e) {
        error_log('handleSearch error: ' . $e->getMessage());
        returnJsonResponse([
            'success' => false,
            'message' => 'حدث خطأ أثناء البحث'
        ], 500);
    }
}

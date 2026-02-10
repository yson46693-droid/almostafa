<?php
/**
 * API for Real-time Search of Company Products
 * Returns product data with search and filters for AJAX requests
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
    $allowedRoles = ['manager', 'developer', 'accountant', 'production'];
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
    $productType = $_GET['product_type'] ?? 'all'; // all, factory, external
    $categoryFilter = isset($_GET['category']) && $_GET['category'] !== '' ? trim($_GET['category']) : null;
    $minPrice = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : null;
    $maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : null;
    $minQuantity = isset($_GET['min_quantity']) && $_GET['min_quantity'] !== '' ? (float)$_GET['min_quantity'] : null;
    $maxQuantity = isset($_GET['max_quantity']) && $_GET['max_quantity'] !== '' ? (float)$_GET['max_quantity'] : null;
    
    // جلب منتجات المصنع
    $factoryProducts = [];
    if ($productType === 'all' || $productType === 'factory') {
        try {
            $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
            
            if (!empty($finishedProductsTableExists)) {
                $sql = "
                    SELECT 
                        base.id,
                        base.batch_id,
                        base.batch_number,
                        base.product_id,
                        base.product_name,
                        base.product_category,
                        base.production_date,
                        base.quantity_produced,
                        base.unit_price,
                        base.total_price,
                        CASE 
                            WHEN base.unit_price > 0 
                                THEN (base.unit_price * COALESCE(base.quantity_produced, 0))
                            WHEN base.unit_price = 0 AND base.total_price IS NOT NULL AND base.total_price > 0 
                                THEN base.total_price
                            ELSE 0
                        END AS calculated_total_price,
                        base.workers
                    FROM (
                        SELECT 
                            fp.id,
                            fp.batch_id,
                            fp.batch_number,
                            COALESCE(fp.product_id, bn.product_id) AS product_id,
                            COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                            pr.category as product_category,
                            fp.production_date,
                            fp.quantity_produced,
                            COALESCE(
                                NULLIF(fp.unit_price, 0),
                                (SELECT pt.unit_price 
                                 FROM product_templates pt 
                                 WHERE pt.status = 'active' 
                                   AND pt.unit_price IS NOT NULL 
                                   AND pt.unit_price > 0
                                   AND pt.unit_price <= 10000
                                   AND (
                                       (
                                           COALESCE(fp.product_id, bn.product_id) IS NOT NULL 
                                           AND COALESCE(fp.product_id, bn.product_id) > 0
                                           AND pt.product_id IS NOT NULL 
                                           AND pt.product_id > 0 
                                           AND pt.product_id = COALESCE(fp.product_id, bn.product_id)
                                       )
                                       OR (
                                           pt.product_name IS NOT NULL 
                                           AND pt.product_name != ''
                                           AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                           AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                           AND (
                                               LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                               OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                               OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                           )
                                       )
                                       OR (
                                           (pt.product_id IS NULL OR pt.product_id = 0)
                                           AND pt.product_name IS NOT NULL 
                                           AND pt.product_name != ''
                                           AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                           AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                           AND (
                                               LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                               OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                               OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                           )
                                       )
                                   )
                                 ORDER BY pt.unit_price DESC
                                 LIMIT 1),
                                0
                            ) AS unit_price,
                            fp.total_price,
                            GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers
                        FROM finished_products fp
                        LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                        LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                        LEFT JOIN batch_workers bw ON fp.batch_id = bw.batch_id
                        LEFT JOIN users u ON bw.employee_id = u.id
                        WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                        GROUP BY fp.id
                    ) AS base
                    WHERE 1=1";
                
                $params = [];
                
                // البحث
                if ($search) {
                    $sql .= " AND (
                        base.product_name LIKE ? 
                        OR base.batch_number LIKE ? 
                        OR base.product_category LIKE ?
                    )";
                    $searchParam = '%' . $search . '%';
                    $params[] = $searchParam;
                    $params[] = $searchParam;
                    $params[] = $searchParam;
                }
                
                // فلتر الفئة
                if ($categoryFilter) {
                    $sql .= " AND base.product_category LIKE ?";
                    $params[] = '%' . $categoryFilter . '%';
                }
                
                $sql .= " ORDER BY base.production_date DESC, base.id DESC";
                
                $factoryProducts = $db->query($sql, $params);
                
                // حساب الكمية المتاحة لكل منتج
                foreach ($factoryProducts as &$product) {
                    $quantityProduced = (float)($product['quantity_produced'] ?? 0);
                    $batchNumber = $product['batch_number'] ?? '';
                    $batchId = $product['id'] ?? null;
                    
                    $soldQty = 0;
                    $pendingQty = 0;
                    
                    if (!empty($batchNumber) && $batchId) {
                        try {
                            $sold = $db->queryOne("
                                SELECT COALESCE(SUM(ii.quantity), 0) AS sold_quantity
                                FROM invoice_items ii
                                INNER JOIN invoices i ON ii.invoice_id = i.id
                                INNER JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                                INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                                WHERE bn.batch_number = ?
                            ", [$batchNumber]);
                            $soldQty = (float)($sold['sold_quantity'] ?? 0);
                            
                            $pending = $db->queryOne("
                                SELECT COALESCE(SUM(oi.quantity), 0) AS pending_quantity
                                FROM customer_order_items oi
                                INNER JOIN customer_orders co ON oi.order_id = co.id
                                INNER JOIN finished_products fp2 ON fp2.product_id = oi.product_id AND fp2.batch_number = ?
                                WHERE co.status = 'pending'
                            ", [$batchNumber]);
                            $pendingQty = (float)($pending['pending_quantity'] ?? 0);
                        } catch (Throwable $calcError) {
                            error_log('Error calculating available quantity: ' . $calcError->getMessage());
                        }
                    }
                    
                    $product['available_quantity'] = max(0, $quantityProduced - $pendingQty);
                    $product['sold_quantity'] = $soldQty;
                    $product['pending_quantity'] = $pendingQty;
                    
                    // تطبيق فلاتر السعر والكمية
                    $unitPrice = floatval($product['unit_price'] ?? 0);
                    $availableQuantity = floatval($product['available_quantity'] ?? 0);
                    
                    if ($minPrice !== null && $unitPrice < $minPrice) {
                        continue;
                    }
                    if ($maxPrice !== null && $unitPrice > $maxPrice) {
                        continue;
                    }
                    if ($minQuantity !== null && $availableQuantity < $minQuantity) {
                        continue;
                    }
                    if ($maxQuantity !== null && $availableQuantity > $maxQuantity) {
                        continue;
                    }
                }
                unset($product);
                
                // إعادة ترتيب بعد الفلترة
                $factoryProducts = array_values($factoryProducts);
            }
        } catch (Exception $e) {
            error_log('Error fetching factory products: ' . $e->getMessage());
        }
    }
    
    // جلب المنتجات الخارجية
    $externalProducts = [];
    if ($productType === 'all' || $productType === 'external') {
        try {
            $sql = "SELECT 
                        id,
                        name,
                        category,
                        quantity,
                        COALESCE(unit, 'قطعة') as unit,
                        unit_price,
                        (quantity * unit_price) as total_value,
                        created_at,
                        updated_at
                    FROM products
                    WHERE product_type = 'external'
                      AND status = 'active'";
            
            $params = [];
            
            // البحث
            if ($search) {
                $sql .= " AND (name LIKE ? OR unit LIKE ?)";
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            // فلتر الصنف
            if ($categoryFilter) {
                $sql .= " AND category = ?";
                $params[] = $categoryFilter;
            }
            
            // فلاتر السعر والكمية
            if ($minPrice !== null || $maxPrice !== null || $minQuantity !== null || $maxQuantity !== null) {
                $conditions = [];
                if ($minPrice !== null) {
                    $conditions[] = "unit_price >= ?";
                    $params[] = $minPrice;
                }
                if ($maxPrice !== null) {
                    $conditions[] = "unit_price <= ?";
                    $params[] = $maxPrice;
                }
                if ($minQuantity !== null) {
                    $conditions[] = "quantity >= ?";
                    $params[] = $minQuantity;
                }
                if ($maxQuantity !== null) {
                    $conditions[] = "quantity <= ?";
                    $params[] = $maxQuantity;
                }
                if (!empty($conditions)) {
                    $sql .= " AND " . implode(" AND ", $conditions);
                }
            }
            
            $sql .= " ORDER BY name ASC";
            
            $externalProducts = $db->query($sql, $params);
        } catch (Exception $e) {
            error_log('Error fetching external products: ' . $e->getMessage());
        }
    }
    
    // تحضير البيانات للإرجاع
    $factoryResults = [];
    foreach ($factoryProducts as $product) {
        $batchNumber = $product['batch_number'] ?? '';
        $productName = $product['product_name'] ?? 'غير محدد';
        $category = $product['product_category'] ?? '—';
        $productionDate = !empty($product['production_date']) ? $product['production_date'] : null;
        $quantityProduced = (float)($product['quantity_produced'] ?? 0);
        $availableQuantity = (float)($product['available_quantity'] ?? $quantityProduced);
        $unitPrice = floatval($product['unit_price'] ?? 0);
        $totalPrice = $unitPrice * $availableQuantity;
        
        $factoryResults[] = [
            'id' => (int)$product['id'],
            'batch_id' => (int)($product['batch_id'] ?? 0),
            'batch_number' => htmlspecialchars($batchNumber),
            'product_id' => (int)($product['product_id'] ?? 0),
            'product_name' => htmlspecialchars($productName),
            'product_category' => htmlspecialchars($category),
            'production_date' => $productionDate,
            'quantity_produced' => $quantityProduced,
            'available_quantity' => $availableQuantity,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'type' => 'factory'
        ];
    }
    
    $externalResults = [];
    foreach ($externalProducts as $product) {
        $productName = $product['name'] ?? 'غير محدد';
        $category = $product['category'] ?? '—';
        $quantity = (float)($product['quantity'] ?? 0);
        $unit = $product['unit'] ?? 'قطعة';
        $unitPrice = floatval($product['unit_price'] ?? 0);
        $totalValue = floatval($product['total_value'] ?? 0);
        
        $externalResults[] = [
            'id' => (int)$product['id'],
            'name' => htmlspecialchars($productName),
            'category' => htmlspecialchars($category),
            'quantity' => $quantity,
            'unit' => htmlspecialchars($unit),
            'unit_price' => $unitPrice,
            'total_value' => $totalValue,
            'type' => 'external'
        ];
    }
    
    // حساب الإحصائيات
    $totalFactoryProducts = count($factoryResults);
    $totalExternalProducts = count($externalResults);
    $totalFactoryValue = 0;
    foreach ($factoryResults as $p) {
        $totalFactoryValue += $p['total_price'];
    }
    $totalExternalValue = 0;
    foreach ($externalResults as $p) {
        $totalExternalValue += $p['total_value'];
    }
    
    returnJsonResponse([
        'success' => true,
        'factory_products' => $factoryResults,
        'external_products' => $externalResults,
        'statistics' => [
            'total_factory_products' => $totalFactoryProducts,
            'total_external_products' => $totalExternalProducts,
            'total_factory_value' => $totalFactoryValue,
            'total_external_value' => $totalExternalValue,
        ],
        'filters' => [
            'search' => $search,
            'product_type' => $productType,
            'category' => $categoryFilter,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'min_quantity' => $minQuantity,
            'max_quantity' => $maxQuantity,
        ],
    ]);
    
} catch (Throwable $e) {
    error_log('Error in search_company_products.php: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب البيانات'
    ], 500);
}

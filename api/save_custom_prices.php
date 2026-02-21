<?php
/**
 * API: حفظ الأسعار المخصصة للعميل
 * POST: customer_type (local|rep), customer_id (أو null لعميل جديد يدوي), manual_customer_name, manual_phone (اختياري)، items[] = {product_name, unit, price}
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $currentUser = getCurrentUser();
    $role = strtolower($currentUser['role'] ?? '');
    if (!in_array($role, ['manager', 'accountant', 'developer'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = db();
    $userId = (int)($currentUser['id'] ?? 0);

    // إنشاء الجدول إذا لم يكن موجوداً
    $t = $db->queryOne("SHOW TABLES LIKE 'custom_customer_prices'");
    if (empty($t)) {
        $db->rawQuery("
            CREATE TABLE `custom_customer_prices` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `customer_type` enum('local','rep') NOT NULL COMMENT 'local=عميل محلي، rep=عميل مندوب',
                `customer_id` int(11) NOT NULL,
                `customer_name` varchar(255) NOT NULL,
                `product_name` varchar(255) NOT NULL,
                `unit` varchar(50) NOT NULL DEFAULT 'قطعة',
                `price` decimal(12,2) NOT NULL DEFAULT 0.00,
                `created_by` int(11) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `customer_type_id` (`customer_type`,`customer_id`),
                KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الأسعار المخصصة للعملاء'
        ");
    }

    $customerType = trim((string)($_POST['customer_type'] ?? ''));
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $manualCustomerName = trim((string)($_POST['manual_customer_name'] ?? ''));
    $manualPhone = trim((string)($_POST['manual_phone'] ?? ''));
    $items = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];

    if ($customerType === 'manual' || ($customerType === '' && $manualCustomerName !== '')) {
        // عميل جديد يدوي: إضافته إلى العملاء المحليين ثم استخدامه
        if ($manualCustomerName === '') {
            echo json_encode(['success' => false, 'error' => 'اسم العميل الجديد مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once __DIR__ . '/../includes/customer_code_generator.php';
        $hasUniqueCode = $db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'unique_code'");
        $uniqueCode = '';
        if (!empty($hasUniqueCode)) {
            if (function_exists('ensureCustomerUniqueCodeColumn')) {
                ensureCustomerUniqueCodeColumn('local_customers');
            }
            if (function_exists('generateUniqueCustomerCode')) {
                $uniqueCode = generateUniqueCustomerCode('local_customers');
            }
        }
        $cols = ['name', 'phone', 'address', 'balance', 'status', 'created_by'];
        $vals = [$manualCustomerName, $manualPhone ?: null, null, 0, 'active', $userId];
        $placeholders = ['?', '?', '?', '?', '?', '?'];
        if ($uniqueCode !== '') {
            array_unshift($cols, 'unique_code');
            array_unshift($vals, $uniqueCode);
            array_unshift($placeholders, '?');
        }
        $db->execute(
            'INSERT INTO local_customers (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')',
            $vals
        );
        $customerId = (int)$db->getLastInsertId();
        if ($customerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'فشل إضافة العميل'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $customerType = 'local';
        $customerName = $manualCustomerName;
    } else {
        if (!in_array($customerType, ['local', 'rep'], true) || $customerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'اختر العميل أو أدخل اسم عميل جديد'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($customerType === 'local') {
            $row = $db->queryOne('SELECT id, name FROM local_customers WHERE id = ? AND status = ?', [$customerId, 'active']);
        } else {
            $row = $db->queryOne('SELECT id, name FROM customers WHERE id = ?', [$customerId]);
        }
        if (empty($row)) {
            echo json_encode(['success' => false, 'error' => 'العميل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $customerName = $row['name'];
    }

    $allowedUnits = ['كرتونه', 'عبوة', 'كيلو', 'جرام', 'شرينك', 'جركن', 'قطعة'];
    $validItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $productName = trim((string)($item['product_name'] ?? ''));
        $unit = trim((string)($item['unit'] ?? 'قطعة'));
        if (!in_array($unit, $allowedUnits, true)) $unit = 'قطعة';
        $price = isset($item['price']) ? (float)$item['price'] : 0;
        if ($productName === '' && $price <= 0) continue;
        if ($productName === '') continue;
        $validItems[] = ['product_name' => $productName, 'unit' => $unit, 'price' => $price];
    }

    if (empty($validItems)) {
        echo json_encode(['success' => false, 'error' => 'أضف منتجاً واحداً على الأقل مع السعر'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف الأسعار القديمة لهذا العميل ثم إدراج الجديدة
    $db->execute('DELETE FROM custom_customer_prices WHERE customer_type = ? AND customer_id = ?', [$customerType, $customerId]);

    foreach ($validItems as $item) {
        $db->execute(
            'INSERT INTO custom_customer_prices (customer_type, customer_id, customer_name, product_name, unit, price, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$customerType, $customerId, $customerName, $item['product_name'], $item['unit'], $item['price'], $userId]
        );
    }

    echo json_encode([
        'success' => true,
        'customer_type' => $customerType,
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'message' => 'تم حفظ الأسعار المخصصة بنجاح'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('save_custom_prices error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'خطأ في الخادم'], JSON_UNESCAPED_UNICODE);
}

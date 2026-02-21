<?php
/**
 * API: جلب الأسعار المخصصة لعميل معين (لبطاقة عرض الأسعار)
 * GET: customer_type=local|rep, customer_id=عدد
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

    $customerType = trim((string)($_GET['customer_type'] ?? ''));
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    if (!in_array($customerType, ['local', 'rep'], true) || $customerId <= 0) {
        echo json_encode(['success' => false, 'error' => 'معرف العميل مطلوب', 'items' => []]);
        exit;
    }

    $db = db();
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'custom_customer_prices'");
    if (empty($tableExists)) {
        echo json_encode(['success' => true, 'customer_name' => '', 'items' => []]);
        exit;
    }

    $rows = $db->query(
        "SELECT customer_name, product_name, unit, price FROM custom_customer_prices WHERE customer_type = ? AND customer_id = ? ORDER BY product_name ASC",
        [$customerType, $customerId]
    );
    $customerName = '';
    $items = [];
    foreach ($rows as $r) {
        if ($customerName === '') $customerName = $r['customer_name'];
        $items[] = [
            'product_name' => $r['product_name'],
            'unit' => $r['unit'],
            'price' => (float)$r['price'],
        ];
    }

    echo json_encode([
        'success' => true,
        'customer_name' => $customerName,
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_custom_prices_by_customer error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'خطأ في الخادم', 'items' => []]);
}

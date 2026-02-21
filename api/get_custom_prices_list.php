<?php
/**
 * API: قائمة العملاء الذين لديهم أسعار مخصصة (لعرض البطاقات المحفوظة)
 * GET: لا parameters
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

    $db = db();

    $tableExists = $db->queryOne("SHOW TABLES LIKE 'custom_customer_prices'");
    if (empty($tableExists)) {
        echo json_encode(['success' => true, 'list' => []]);
        exit;
    }

    $rows = $db->query("
        SELECT customer_type, customer_id, customer_name, COUNT(*) as items_count, MAX(created_at) as updated_at
        FROM custom_customer_prices
        GROUP BY customer_type, customer_id, customer_name
        ORDER BY customer_name ASC
    ");
    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            'customer_type' => $r['customer_type'],
            'customer_id' => (int)$r['customer_id'],
            'customer_name' => $r['customer_name'],
            'items_count' => (int)$r['items_count'],
            'updated_at' => $r['updated_at'],
        ];
    }

    echo json_encode(['success' => true, 'list' => $list], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_custom_prices_list error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'خطأ في الخادم', 'list' => []], JSON_UNESCAPED_UNICODE);
}

<?php
/**
 * API سجل المعاملات المالية للموظف (نوته - مبالغ وملاحظات لكل شهر)
 * GET: قائمة السجلات لموظف + شهر + سنة
 * POST: إضافة سجل (amount, notes)
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = getCurrentUser();
$allowedRoles = ['accountant', 'manager', 'developer'];
if (!in_array($currentUser['role'] ?? '', $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = db();

// التحقق من وجود الجدول
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'employee_financial_notes'");
if (empty($tableCheck)) {
    echo json_encode(['success' => false, 'message' => 'جدول سجل المعاملات غير متوفر', 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = intval($_GET['user_id'] ?? 0);
    $month = intval($_GET['month'] ?? date('n'));
    $year = intval($_GET['year'] ?? date('Y'));

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف الموظف غير صحيح', 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($month < 1 || $month > 12) {
        $month = (int) date('n');
    }
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }

    $items = $db->query(
        "SELECT n.id, n.user_id, n.month, n.year, n.amount, n.notes, n.created_at,
                u.full_name as created_by_name
         FROM employee_financial_notes n
         LEFT JOIN users u ON n.created_by = u.id
         WHERE n.user_id = ? AND n.month = ? AND n.year = ?
         ORDER BY n.created_at DESC",
        [$userId, $month, $year]
    ) ?: [];

    $total = 0;
    foreach ($items as $row) {
        $total += (float) ($row['amount'] ?? 0);
    }

    echo json_encode([
        'success' => true,
        'items' => $items,
        'total' => round($total, 2),
        'month' => $month,
        'year' => $year,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = intval($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $month = intval($_POST['month'] ?? $_GET['month'] ?? date('n'));
    $year = intval($_POST['year'] ?? $_GET['year'] ?? date('Y'));
    $amount = isset($_POST['amount']) ? (float) str_replace(',', '.', $_POST['amount']) : 0;
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف الموظف غير صحيح'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($month < 1 || $month > 12) {
        $month = (int) date('n');
    }
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }

    $createdBy = (int) ($currentUser['id'] ?? 0);

    try {
        $db->execute(
            "INSERT INTO employee_financial_notes (user_id, month, year, amount, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)",
            [$userId, $month, $year, $amount, $notes ?: null, $createdBy ?: null]
        );
    } catch (Exception $e) {
        error_log("employee_financial_notes insert: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'فشل الحفظ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'تمت إضافة السجل',
        'id' => (int) $db->getLastInsertId(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'طريقة غير مدعومة'], JSON_UNESCAPED_UNICODE);

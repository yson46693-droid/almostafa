<?php
/**
 * API: حفظ وعرض فواتير ورقية للعملاء المحليين
 * - حفظ: صورة + إجمالي → يضاف الإجمالي كرصيد دائن للعميل ويُسجّل في سجل المشتريات
 * - عرض الصورة: للمستخدمين المسجلين فقط
 */

@ini_set('display_errors', '0');
error_reporting(0);

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'انتهت جلسة العمل'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedRoles = ['manager', 'accountant', 'sales', 'developer'];
if (!in_array($currentUser['role'] ?? '', $allowedRoles, true)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;

// طلب عرض الصورة (GET) - يُرجَع كصورة
if ($action === 'view_image' || (isset($_GET['id']) && !isset($_POST['action']))) {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid id';
        exit;
    }
    $db = db();
    $row = $db->queryOne(
        "SELECT id, customer_id, image_path FROM local_customer_paper_invoices WHERE id = ?",
        [$id]
    );
    if (!$row || empty($row['image_path'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) {
        $uploadsRoot = __DIR__ . '/../uploads';
    }
    $baseDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'paper_invoices';
    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($row['image_path']));
    if (strpos($relativePath, '..') !== false) {
        http_response_code(403);
        exit;
    }
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $relativePath;
    $realPath = realpath($fullPath);
    if (!$realPath || !is_file($realPath) || strpos($realPath, realpath($baseDir)) !== 0) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $mime = 'image/jpeg';
    if ($ext === 'png') $mime = 'image/png';
    elseif ($ext === 'gif') $mime = 'image/gif';
    elseif ($ext === 'webp') $mime = 'image/webp';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: inline; filename="paper-invoice-' . $id . '.' . $ext . '"');
    readfile($realPath);
    exit;
}

// حفظ فاتورة ورقية (POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'save') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'الإجراء غير معروف أو طريقة الطلب غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
$totalAmount = isset($_POST['total_amount']) ? trim($_POST['total_amount']) : '';

if ($customerId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'معرف العميل غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalAmount = str_replace(',', '.', $totalAmount);
if (!is_numeric($totalAmount) || (float)$totalAmount <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'يرجى إدخال إجمالي صحيح للفاتورة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalAmount = (float)$totalAmount;

$db = db();
$customer = $db->queryOne("SELECT id, name, balance FROM local_customers WHERE id = ?", [$customerId]);
if (!$customer) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'العميل غير موجود'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadsRoot = realpath(__DIR__ . '/../uploads');
if ($uploadsRoot === false) {
    $uploadsRoot = __DIR__ . '/../uploads';
}
$uploadDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'paper_invoices';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'تعذر إنشاء مجلد رفع الصور'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$imagePath = null;
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed, true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'نوع الملف غير مسموح. المسموح: JPG, PNG, GIF, WEBP'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext)) ?: 'jpg';
    $filename = 'inv_' . $customerId . '_' . date('YmdHis') . '_' . substr(uniqid(), -6) . '.' . $ext;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'تعذر حفظ صورة الفاتورة'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $imagePath = $filename;
}

try {
    $db->beginTransaction();
    $currentBalance = (float)($customer['balance'] ?? 0);
    $newBalance = $currentBalance + $totalAmount; // رصيد مدين = زيادة الرصيد
    $db->execute(
        "UPDATE local_customers SET balance = ?, balance_updated_at = NOW() WHERE id = ?",
        [$newBalance, $customerId]
    );
    $db->execute(
        "INSERT INTO local_customer_paper_invoices (customer_id, total_amount, image_path, created_by) VALUES (?, ?, ?, ?)",
        [$customerId, $totalAmount, $imagePath, $currentUser['id']]
    );
    $paperInvoiceId = (int)$db->getLastInsertId();
    $db->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'تم تسجيل الفاتورة الورقية وإضافة المبلغ كرصيد دائن للعميل.',
        'paper_invoice_id' => $paperInvoiceId,
        'new_balance' => $newBalance
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $db->rollback();
    error_log('local_paper_invoice save error: ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء الحفظ'], JSON_UNESCAPED_UNICODE);
}

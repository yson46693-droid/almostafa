<?php
/**
 * API: حفظ وعرض مرتجعات الفواتير الورقية للعملاء المحليين
 * - حفظ: صورة + رقم الفاتورة + مبلغ المرتجع → يخصم من الرصيد المدين؛ إن زاد المبلغ عن الدين يتحول الفرق لرصيد دائن
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

// طلب عرض الصورة (GET)
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
        "SELECT id, customer_id, image_path FROM local_customer_paper_invoice_returns WHERE id = ?",
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
    $baseDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'paper_invoice_returns';
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
    $mtime = filemtime($realPath);
    $fsize = filesize($realPath);
    $etag = '"' . md5('local-paper-return-' . $id . '-' . $mtime . '-' . $fsize) . '"';
    $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    header('Cache-Control: private, max-age=2592000, immutable');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime) {
        http_response_code(304);
        exit;
    }
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $mime = 'image/jpeg';
    if ($ext === 'png') $mime = 'image/png';
    elseif ($ext === 'gif') $mime = 'image/gif';
    elseif ($ext === 'webp') $mime = 'image/webp';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="paper-return-' . $id . '.' . $ext . '"');
    readfile($realPath);
    exit;
}

// حفظ مرتجع فاتورة ورقية (POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'save') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'الإجراء غير معروف أو طريقة الطلب غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
$invoiceNumber = isset($_POST['invoice_number']) ? trim($_POST['invoice_number']) : '';
$returnAmount = isset($_POST['return_amount']) ? trim($_POST['return_amount']) : '';

if ($customerId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'معرف العميل غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($invoiceNumber === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'يرجى إدخال رقم الفاتورة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$returnAmount = str_replace(',', '.', $returnAmount);
if (!is_numeric($returnAmount) || (float)$returnAmount <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'يرجى إدخال مبلغ مرتجع صحيح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$returnAmount = (float)$returnAmount;

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
$uploadDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'paper_invoice_returns';
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
    $filename = 'ret_' . $customerId . '_' . date('YmdHis') . '_' . substr(uniqid(), -6) . '.' . $ext;
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
    // خصم مبلغ المرتجع من الرصيد (موجب = مدين). إن أصبح الناتج سالباً = رصيد دائن
    $newBalance = $currentBalance - $returnAmount;
    $db->execute(
        "UPDATE local_customers SET balance = ?, balance_updated_at = NOW() WHERE id = ?",
        [$newBalance, $customerId]
    );
    $db->execute(
        "INSERT INTO local_customer_paper_invoice_returns (customer_id, invoice_number, return_amount, image_path, created_by) VALUES (?, ?, ?, ?, ?)",
        [$customerId, $invoiceNumber, $returnAmount, $imagePath, $currentUser['id']]
    );
    $returnId = (int)$db->getLastInsertId();
    $db->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'تم تسجيل مرتجع الفاتورة الورقية وخصم المبلغ من رصيد العميل.' . ($newBalance < 0 ? ' الفائض تحوّل إلى رصيد دائن.' : ''),
        'paper_invoice_return_id' => $returnId,
        'new_balance' => $newBalance
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $db->rollback();
    error_log('local_paper_invoice_return save error: ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء الحفظ'], JSON_UNESCAPED_UNICODE);
}

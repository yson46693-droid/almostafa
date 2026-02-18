<?php
/**
 * API: قائمة وحفظ وعرض الفواتير الورقية لشركات الشحن
 * - list: قائمة فواتير شركة معينة
 * - save: رفع صورة + رقم + إجمالي، وتسجيل الفاتورة مع إضافة الإجمالي إلى ديون شركة الشحن
 * - view_image: عرض صورة الفاتورة
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

$allowedRoles = ['manager', 'accountant', 'developer'];
if (!in_array($currentUser['role'] ?? '', $allowedRoles, true)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = db();

// طلب عرض الصورة (GET)
if (isset($_GET['action']) && $_GET['action'] === 'view_image' && isset($_GET['id'])) {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid id';
        exit;
    }
    $row = $db->queryOne(
        "SELECT id, shipping_company_id, image_path FROM shipping_company_paper_invoices WHERE id = ?",
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
    header('Content-Disposition: inline; filename="shipping-paper-inv-' . $id . '.' . $ext . '"');
    readfile($realPath);
    exit;
}

// قائمة الفواتير الورقية لشركة (GET)
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    $companyId = isset($_GET['shipping_company_id']) ? (int)$_GET['shipping_company_id'] : 0;
    if ($companyId <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'معرف الشركة غير صالح'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $company = $db->queryOne("SELECT id, name FROM shipping_companies WHERE id = ?", [$companyId]);
    if (!$company) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'شركة الشحن غير موجودة'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'shipping_company_paper_invoices'");
    if (empty($tableExists)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'paper_invoices' => [], 'company_name' => $company['name']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $list = $db->query(
        "SELECT id, shipping_company_id, invoice_number, total_amount, image_path, created_at FROM shipping_company_paper_invoices WHERE shipping_company_id = ? ORDER BY created_at DESC, id DESC",
        [$companyId]
    );
    $out = [];
    foreach ($list ?: [] as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'invoice_number' => $row['invoice_number'] ?? '',
            'total_amount' => (float)$row['total_amount'],
            'image_path' => $row['image_path'],
            'created_at' => $row['created_at'],
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'paper_invoices' => $out, 'company_name' => $company['name']], JSON_UNESCAPED_UNICODE);
    exit;
}

// حفظ فاتورة ورقية (POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'save') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'الإجراء غير معروف أو طريقة الطلب غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$companyId = isset($_POST['shipping_company_id']) ? (int)$_POST['shipping_company_id'] : 0;
$invoiceNumber = isset($_POST['invoice_number']) ? trim($_POST['invoice_number']) : '';
$totalAmount = isset($_POST['total_amount']) ? trim($_POST['total_amount']) : '';

if ($companyId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'معرف شركة الشحن غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($invoiceNumber === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'يرجى إدخال رقم الفاتورة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalAmount = str_replace(',', '.', $totalAmount);
if (!is_numeric($totalAmount) || (float)$totalAmount <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'يرجى إدخال إجمالي صحيح للفاتورة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalAmount = (float)$totalAmount;

$company = $db->queryOne("SELECT id, name FROM shipping_companies WHERE id = ?", [$companyId]);
if (!$company) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'شركة الشحن غير موجودة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tableExists = $db->queryOne("SHOW TABLES LIKE 'shipping_company_paper_invoices'");
if (empty($tableExists)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'جدول الفواتير الورقية غير متوفر'], JSON_UNESCAPED_UNICODE);
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
    $filename = 'sc_' . $companyId . '_' . date('YmdHis') . '_' . substr(uniqid(), -6) . '.' . $ext;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'تعذر حفظ صورة الفاتورة'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $imagePath = $filename;
}

try {
    $db->execute(
        "INSERT INTO shipping_company_paper_invoices (shipping_company_id, invoice_number, total_amount, image_path, created_by) VALUES (?, ?, ?, ?, ?)",
        [$companyId, $invoiceNumber, $totalAmount, $imagePath, $currentUser['id']]
    );
    $paperInvoiceId = (int)$db->getLastInsertId();
    // إضافة إجمالي الفاتورة إلى ديون شركة الشحن
    $db->execute(
        "UPDATE shipping_companies SET balance = balance + ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
        [$totalAmount, $currentUser['id'], $companyId]
    );
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'تم تسجيل الفاتورة الورقية وإضافة الإجمالي إلى ديون الشركة.',
        'paper_invoice_id' => $paperInvoiceId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('shipping_company_paper_invoice save error: ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء الحفظ'], JSON_UNESCAPED_UNICODE);
}

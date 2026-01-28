<?php
/**
 * API: الحصول على رابط الفاتورة للمشاركة
 */

define('ACCESS_ALLOWED', true);

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/invoices.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    require_once __DIR__ . '/../includes/auth.php';
} catch (Throwable $e) {
    error_log('get_invoice_url bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Initialization error']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $invoiceId = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);

    if ($invoiceId <= 0) {
        throw new InvalidArgumentException('رقم الفاتورة غير صحيح');
    }

    // الحصول على بيانات الفاتورة
    $invoice = getInvoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('الفاتورة غير موجودة');
    }

    // إنشاء رابط الفاتورة
    $invoiceUrl = getRelativeUrl('print_invoice.php?id=' . $invoiceId . '&format=a4');
    $invoiceNumber = htmlspecialchars($invoice['invoice_number'] ?? 'INV-' . $invoiceId, ENT_QUOTES, 'UTF-8');
    
    // الحصول على اسم العميل إذا كان متاحاً
    $customerName = '';
    if (isset($invoice['customer_name'])) {
        $customerName = htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8');
    }

    echo json_encode([
        'success' => true,
        'url' => $invoiceUrl,
        'invoice_number' => $invoiceNumber,
        'customer_name' => $customerName,
        'title' => 'فاتورة رقم: ' . $invoiceNumber . ($customerName ? ' - ' . $customerName : '')
    ]);
} catch (InvalidArgumentException $invalid) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $invalid->getMessage()]);
} catch (Throwable $e) {
    error_log('get_invoice_url error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

<?php
/**
 * API: مشاركة ملف الفاتورة إلى الشات
 */

define('ACCESS_ALLOWED', true);

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/chat.php';
    require_once __DIR__ . '/../../includes/invoices.php';
    require_once __DIR__ . '/../../includes/path_helper.php';
} catch (Throwable $e) {
    error_log('chat/share_invoice bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Initialization error: ' . $e->getMessage()]);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $currentUser = getCurrentUser();
    $userId = (int) $currentUser['id'];

    $payload = json_decode(file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $invoiceId = isset($payload['invoice_id']) ? (int) $payload['invoice_id'] : 0;

    if ($invoiceId <= 0) {
        throw new InvalidArgumentException('رقم الفاتورة غير صحيح');
    }

    // الحصول على بيانات الفاتورة
    $invoice = getInvoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('الفاتورة غير موجودة');
    }

    // إنشاء محتوى HTML للفاتورة
    // استخدام نفس الطريقة المستخدمة في print_invoice.php
    ob_start();
    
    // إعداد المتغيرات المطلوبة (نفس المتغيرات في print_invoice.php)
    $selectedInvoice = $invoice;
    $invoiceData = $invoice;
    $companyName = COMPANY_NAME;
    
    // تعطيل ACCESS_ALLOWED check مؤقتاً
    $oldAccessAllowed = defined('ACCESS_ALLOWED') ? ACCESS_ALLOWED : false;
    if (!defined('ACCESS_ALLOWED')) {
        define('ACCESS_ALLOWED', true);
    }
    
    // تضمين ملف طباعة الفاتورة
    $invoicePrintPath = __DIR__ . '/../../modules/accountant/invoice_print.php';
    if (file_exists($invoicePrintPath)) {
        // استخدام output buffering لتجميع HTML
        include $invoicePrintPath;
    } else {
        ob_end_clean();
        throw new RuntimeException('ملف طباعة الفاتورة غير موجود');
    }
    
    $invoiceHtml = ob_get_clean();

    if (empty($invoiceHtml)) {
        throw new RuntimeException('فشل في إنشاء محتوى الفاتورة');
    }

    // حفظ HTML كملف
    $uploadDir = __DIR__ . '/../../uploads/chat/files/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // إنشاء اسم ملف فريد
    $invoiceNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'INV'));
    $filename = 'invoice-' . $invoiceNumber . '-' . date('Ymd-His') . '-' . uniqid() . '.html';
    $filepath = $uploadDir . $filename;

    // حفظ الملف
    if (file_put_contents($filepath, $invoiceHtml) === false) {
        throw new RuntimeException('فشل في حفظ ملف الفاتورة');
    }

    // إنشاء رابط الملف
    $relativeUrl = 'uploads/chat/files/' . $filename;
    $fullUrl = getRelativeUrl($relativeUrl);

    // إرسال رسالة إلى الشات مع الملف
    $db = db();
    $db->beginTransaction();

    try {
        $invoiceNumber = htmlspecialchars($invoice['invoice_number'] ?? 'INV-' . $invoiceId, ENT_QUOTES, 'UTF-8');
        $messageText = "فاتورة رقم: {$invoiceNumber}\n[FILE:" . $fullUrl . ":فاتورة-{$invoiceNumber}.html]";

        $result = $db->execute(
            "INSERT INTO messages (user_id, message_text, reply_to) VALUES (?, ?, ?)",
            [
                $userId,
                $messageText,
                null,
            ]
        );

        $messageId = (int) $result['insert_id'];
        $db->commit();

        $message = getChatMessageById($messageId, $userId);
        markMessageAsRead($messageId, $userId);

        echo json_encode([
            'success' => true,
            'data' => $message,
            'message' => 'تم مشاركة الفاتورة بنجاح',
        ]);
    } catch (Throwable $e) {
        $db->rollback();
        // حذف الملف في حالة الفشل
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        throw $e;
    }
} catch (InvalidArgumentException $invalid) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $invalid->getMessage()]);
} catch (Throwable $e) {
    error_log('chat/share_invoice error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
}


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
    require_once __DIR__ . '/../includes/pdf_helper.php';
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

    $invoiceNumber = htmlspecialchars($invoice['invoice_number'] ?? 'INV-' . $invoiceId, ENT_QUOTES, 'UTF-8');
    
    // الحصول على اسم العميل إذا كان متاحاً
    $customerName = '';
    if (isset($invoice['customer_name'])) {
        $customerName = htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8');
    }

    // إنشاء محتوى HTML للفاتورة (HTML كامل مع head و body)
    ob_start();
    
    // إعداد المتغيرات المطلوبة (نفس المتغيرات في print_invoice.php)
    $selectedInvoice = $invoice;
    $invoiceData = $invoice;
    $companyName = COMPANY_NAME;
    $printFormat = 'a4';
    
    // بدء HTML كامل
    echo '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة ' . htmlspecialchars($invoiceNumber) . '</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: "Tajawal", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #ffffff;
            padding: 20px;
            color: #1f2937;
        }
        @media print {
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>';
    
    // تضمين ملف طباعة الفاتورة
    $invoicePrintPath = __DIR__ . '/../old-recipt.php';
    if (file_exists($invoicePrintPath)) {
        // استخدام output buffering لتجميع HTML
        include $invoicePrintPath;
    } else {
        ob_end_clean();
        throw new RuntimeException('ملف طباعة الفاتورة غير موجود');
    }
    
    echo '</body>
</html>';
    
    $invoiceHtml = ob_get_clean();

    if (empty($invoiceHtml)) {
        throw new RuntimeException('فشل في إنشاء محتوى الفاتورة');
    }

    // إنشاء مجلد uploads/invoices إذا لم يكن موجوداً
    $uploadDir = __DIR__ . '/../uploads/invoices/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // إنشاء اسم ملف PDF فريد
    $normalizedNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $invoiceNumber);
    $filename = 'invoice-' . $normalizedNumber . '-' . date('Ymd-His') . '-' . uniqid() . '.pdf';
    $filepath = $uploadDir . $filename;

    // تحويل HTML إلى PDF وحفظه
    $pdfGenerated = false;
    
    // التحقق من وجود API key و cURL قبل المحاولة
    $hasApiKey = defined('APDF_IO_API_KEY') && APDF_IO_API_KEY !== '' && APDF_IO_API_KEY !== 'your-api-key-here';
    $hasCurl = function_exists('curl_init');
    
    if ($hasApiKey && $hasCurl) {
        try {
            // محاولة إنشاء PDF
            $result = apdfGeneratePdf($invoiceHtml, [
                'pageSize' => 'A4',
                'printBackground' => true,
                'margin' => [
                    'top' => '15mm',
                    'right' => '12mm',
                    'bottom' => '15mm',
                    'left' => '12mm',
                ],
            ]);
            
            if ($result['success'] && isset($result['data']) && strlen($result['data']) > 200) {
                // حفظ PDF
                if (file_put_contents($filepath, $result['data']) !== false) {
                    $pdfGenerated = true;
                    error_log('PDF generated successfully: ' . $filename . ' (' . strlen($result['data']) . ' bytes)');
                } else {
                    error_log('Failed to save PDF file: ' . $filepath);
                }
            } else {
                $status = $result['status'] ?? 'unknown';
                $preview = $result['preview'] ?? 'no preview';
                error_log('PDF generation failed - Status: ' . $status . ', Preview: ' . substr($preview, 0, 200));
            }
        } catch (Throwable $pdfError) {
            error_log('PDF generation exception: ' . $pdfError->getMessage());
            error_log('PDF generation file: ' . $pdfError->getFile() . ':' . $pdfError->getLine());
        }
    } else {
        if (!$hasApiKey) {
            error_log('PDF generation skipped: APDF_IO_API_KEY not defined or empty');
        }
        if (!$hasCurl) {
            error_log('PDF generation skipped: cURL extension not available');
        }
    }
    
    // إذا فشل إنشاء PDF، حفظ HTML كبديل
    if (!$pdfGenerated) {
        $htmlFilename = str_replace('.pdf', '.html', $filename);
        $htmlFilepath = $uploadDir . $htmlFilename;
        if (file_put_contents($htmlFilepath, $invoiceHtml) === false) {
            throw new RuntimeException('فشل في حفظ ملف الفاتورة');
        }
        $filename = $htmlFilename;
        $filepath = $htmlFilepath;
        error_log('HTML file saved as fallback: ' . $filename);
    }

    // إنشاء رابط الملف - استخدام absolute URL
    $relativeUrl = 'uploads/invoices/' . $filename;
    
    // بناء absolute URL يدوياً (بدون api/)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // الحصول على base path وإزالة api منه
    $basePath = getBasePath();
    $basePath = str_replace('/api', '', $basePath);
    $basePath = rtrim($basePath, '/');
    
    // بناء URL للملف
    $fileUrl = $protocol . $host . $basePath . '/' . $relativeUrl;
    
    // رابط الطباعة أيضاً (بدون api/)
    $printUrl = $protocol . $host . $basePath . '/print_invoice.php?id=' . $invoiceId . '&format=a4';

    $isPdf = strpos($filename, '.pdf') !== false;
    
    $response = [
        'success' => true,
        'url' => $printUrl,
        'file_url' => $fileUrl,
        'file_path' => $relativeUrl,
        'file_type' => $isPdf ? 'pdf' : 'html',
        'invoice_number' => $invoiceNumber,
        'customer_name' => $customerName,
        'title' => 'فاتورة رقم: ' . $invoiceNumber . ($customerName ? ' - ' . $customerName : '')
    ];
    
    // إضافة معلومات إضافية للتشخيص (فقط في حالة عدم إنشاء PDF)
    if (!$isPdf) {
        $response['pdf_failed'] = true;
        $response['pdf_failed_reason'] = !$hasApiKey ? 'API key missing' : (!$hasCurl ? 'cURL not available' : 'PDF generation failed');
    }
    
    echo json_encode($response);
} catch (InvalidArgumentException $invalid) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $invalid->getMessage()]);
} catch (Throwable $e) {
    error_log('get_invoice_url error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

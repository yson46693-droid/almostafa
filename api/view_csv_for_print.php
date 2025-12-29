<?php
/**
 * API for Viewing CSV File as HTML for Printing
 * Converts CSV file to HTML table format for printing
 */

// ===== بداية الإعداد الحرج لضمان HTML فقط =====

// تعطيل عرض الأخطاء تماماً قبل أي شيء
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

// تنظيف أي output موجود
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// بدء output buffering جديد
ob_start();

// تعريف ثوابت الوصول
define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

// ===== تحميل الملفات المطلوبة =====
try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    
    // التحقق من تسجيل الدخول
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>غير مصرح</title></head><body><h1>غير مصرح لك بالوصول</h1></body></html>';
        exit;
    }
    
    $currentUser = getCurrentUser();
    $currentRole = strtolower((string)($currentUser['role'] ?? ''));
    
    // التحقق من الصلاحيات
    $allowedRoles = ['manager', 'developer', 'accountant', 'sales'];
    if (!in_array($currentRole, $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>غير مصرح</title></head><body><h1>غير مصرح لك بتنفيذ هذه العملية</h1></body></html>';
        exit;
    }
    
    // التحقق من وجود مسار الملف
    $filePath = isset($_GET['file']) ? trim($_GET['file']) : '';
    if (empty($filePath)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>مسار الملف غير محدد</h1></body></html>';
        exit;
    }
    
    // تنظيف المسار من أي محاولات للوصول إلى ملفات خارجية
    $filePath = str_replace('\\', '/', $filePath);
    $filePath = ltrim($filePath, '/');
    
    // التحقق من أن الملف داخل مجلد reports
    if (strpos($filePath, '../') !== false || strpos($filePath, '..\\') !== false) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>مسار الملف غير آمن</h1></body></html>';
        exit;
    }
    
    // إزالة 'reports/' من البداية إذا كان موجوداً (لأن REPORTS_PATH يحتوي على reports/)
    $filePath = preg_replace('/^reports\//', '', $filePath);
    
    // بناء المسار الكامل
    $fullPath = REPORTS_PATH . $filePath;
    $fullPath = str_replace('\\', '/', $fullPath);
    $fullPath = rtrim($fullPath, '/');
    
    // التحقق من وجود الملف
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>الملف غير موجود</h1></body></html>';
        exit;
    }
    
    // التحقق من أن الملف CSV
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if ($extension !== 'csv') {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>الملف ليس ملف CSV</h1></body></html>';
        exit;
    }
    
    // قراءة محتوى الملف
    $csvContent = @file_get_contents($fullPath);
    if ($csvContent === false) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>فشل في قراءة الملف</h1></body></html>';
        exit;
    }
    
    // إزالة BOM UTF-8 إذا كان موجوداً
    if (substr($csvContent, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF)) {
        $csvContent = substr($csvContent, 3);
    }
    
    // قراءة CSV باستخدام fopen لضمان قراءة صحيحة
    $handle = @fopen($fullPath, 'r');
    if ($handle === false) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>فشل في فتح الملف</h1></body></html>';
        exit;
    }
    
    // إزالة BOM إذا كان موجوداً
    $firstBytes = fread($handle, 3);
    if ($firstBytes !== chr(0xEF).chr(0xBB).chr(0xBF)) {
        rewind($handle);
    }
    
    $rows = [];
    $lineNumber = 0;
    
    // قراءة الملف سطراً بسطر
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        // تجاهل الصفوف الفارغة تماماً
        if (count($row) === 1 && trim($row[0]) === '') {
            continue;
        }
        
        // تنظيف القيم من المسافات الزائدة
        $row = array_map('trim', $row);
        
        // إضافة الصف إذا كان يحتوي على بيانات
        if (!empty($row) && !(count($row) === 1 && $row[0] === '')) {
            $rows[] = $row;
            $lineNumber++;
        }
    }
    
    fclose($handle);
    
    if (empty($rows)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>الملف فارغ أو لا يحتوي على بيانات</h1></body></html>';
        exit;
    }
    
    // تنظيف output buffer
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // إرسال headers
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    
    // إنشاء HTML للطباعة
    // البحث عن العنوان (عادة في السطر الأول)
    $title = 'تصدير العملاء';
    $companyName = COMPANY_NAME;
    $date = date('Y-m-d H:i:s');
    $dataStartIndex = 0;
    
    // البحث عن بداية البيانات بشكل ذكي
    // عادة الملف يحتوي على:
    // السطر 0: العنوان
    // السطر 1: اسم الشركة
    // السطر 2: تاريخ التقرير
    // السطر 3: سطر فارغ
    // السطر 4: العناوين (headers)
    // السطر 5+: البيانات
    
    // التحقق من السطر الأول (العنوان)
    if (isset($rows[0][0]) && !empty(trim($rows[0][0]))) {
        $firstRow = trim($rows[0][0]);
        // إذا كان السطر الأول لا يحتوي على كلمات مثل "اسم العميل" أو "رقم الهاتف"، فهو عنوان
        if (strpos($firstRow, 'اسم العميل') === false && 
            strpos($firstRow, 'رقم الهاتف') === false &&
            strpos($firstRow, 'العنوان') === false) {
            $title = $firstRow;
            $dataStartIndex = 1;
        }
    }
    
    // التحقق من السطر الثاني (اسم الشركة)
    if ($dataStartIndex > 0 && isset($rows[1][0]) && !empty(trim($rows[1][0]))) {
        $secondRow = trim($rows[1][0]);
        if (strpos($secondRow, 'اسم العميل') === false && 
            strpos($secondRow, 'رقم الهاتف') === false) {
            $companyName = $secondRow;
            $dataStartIndex = 2;
        }
    }
    
    // التحقق من السطر الثالث (التاريخ)
    if ($dataStartIndex > 1 && isset($rows[2][0]) && !empty(trim($rows[2][0]))) {
        $thirdRow = trim($rows[2][0]);
        if (strpos($thirdRow, 'تاريخ') !== false || 
            preg_match('/\d{4}-\d{2}-\d{2}/', $thirdRow)) {
            $date = $thirdRow;
            $dataStartIndex = 3;
        }
    }
    
    // البحث عن السطر الفارغ بعد العنوان
    if (isset($rows[$dataStartIndex])) {
        $rowStr = implode('', $rows[$dataStartIndex]);
        if (empty(trim($rowStr))) {
            $dataStartIndex++;
        }
    }
    
    // استخراج العناوين من أول صف بيانات (بعد العنوان والشركة والتاريخ)
    $headers = [];
    $dataRows = [];
    
    // البحث عن صف العناوين (عادة يحتوي على "اسم العميل" أو "رقم الهاتف")
    $headerRowIndex = $dataStartIndex;
    for ($i = $dataStartIndex; $i < min($dataStartIndex + 5, count($rows)); $i++) {
        if (isset($rows[$i]) && is_array($rows[$i])) {
            $rowStr = implode(' ', $rows[$i]);
            // إذا كان الصف يحتوي على كلمات مثل "اسم العميل" أو "رقم الهاتف"، فهو صف العناوين
            if (strpos($rowStr, 'اسم العميل') !== false || 
                strpos($rowStr, 'رقم الهاتف') !== false ||
                strpos($rowStr, 'العنوان') !== false ||
                strpos($rowStr, 'المنطقة') !== false ||
                strpos($rowStr, 'رصيد') !== false) {
                $headerRowIndex = $i;
                break;
            }
        }
    }
    
    if (isset($rows[$headerRowIndex])) {
        $headers = $rows[$headerRowIndex];
        $headers = array_map('trim', $headers);
        $headers = array_filter($headers, function($h) { return !empty($h); });
        $headers = array_values($headers);
        
        // باقي الصفوف هي البيانات
        $dataRows = array_slice($rows, $headerRowIndex + 1);
    }
    
    // تنظيف صفوف البيانات من الصفوف الفارغة
    $dataRows = array_filter($dataRows, function($row) {
        if (empty($row) || !is_array($row)) return false;
        $rowStr = implode('', $row);
        return !empty(trim($rowStr));
    });
    $dataRows = array_values($dataRows);
    
    // تسجيل معلومات للتشخيص
    error_log("CSV View Debug - Total rows: " . count($rows) . ", Headers count: " . count($headers) . ", Data rows count: " . count($dataRows) . ", Data start index: " . $dataStartIndex);
    
    // إنشاء HTML
    $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head>';
    $html .= '<meta charset="utf-8">';
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $html .= '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    $html .= '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">';
    $html .= '<style>
        @page { 
            margin: 15mm 10mm; 
            size: A4;
        }
        *, *::before, *::after { 
            box-sizing: border-box; 
        }
        body { 
            font-family: "Cairo", "Tajawal", "Segoe UI", sans-serif; 
            direction: rtl; 
            text-align: right; 
            color: #0f172a; 
            margin: 0; 
            background: #f5f7fa; 
            padding: 20px;
            line-height: 1.6;
        }
        .report-wrapper { 
            max-width: 1000px; 
            margin: 0 auto; 
            padding: 0; 
            background: #ffffff; 
            border-radius: 0; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.08); 
            overflow: hidden;
            position: relative;
        }
        .report-wrapper::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #0f4c81 0%, #3b82f6 50%, #60a5fa 100%);
        }
        .actions { 
            display: flex; 
            flex-direction: column; 
            gap: 8px; 
            align-items: flex-start; 
            margin-bottom: 24px; 
            padding: 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .actions button { 
            background: linear-gradient(135deg, #0f4c81 0%, #3b82f6 100%); 
            color: #ffffff; 
            border: none; 
            padding: 12px 24px; 
            border-radius: 8px; 
            font-size: 15px; 
            font-weight: 600;
            cursor: pointer; 
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(15, 76, 129, 0.2);
        }
        .actions button:hover { 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 76, 129, 0.3);
        }
        .actions .hint { 
            font-size: 13px; 
            color: #64748b; 
            margin-top: 4px;
        }
        .report-header { 
            margin-bottom: 30px; 
            padding: 30px;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border-bottom: 2px solid #e2e8f0;
        }
        .report-header h1 { 
            margin: 0 0 16px; 
            font-size: 32px; 
            font-weight: 700; 
            color: #0f4c81;
            text-align: center;
            letter-spacing: -0.5px;
        }
        .report-header .meta { 
            display: flex; 
            gap: 16px; 
            justify-content: center; 
            flex-wrap: wrap; 
            margin-top: 20px;
        }
        .report-header .meta span { 
            background: linear-gradient(135deg, #0f4c81 0%, #3b82f6 100%); 
            color: #ffffff;
            padding: 10px 20px; 
            border-radius: 8px; 
            font-size: 14px; 
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(15, 76, 129, 0.15);
        }
        .table-wrapper { 
            overflow-x: auto; 
            margin: 0 30px 30px;
            border-radius: 12px; 
            border: 1px solid #e2e8f0; 
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .report-table { 
            width: 100%; 
            border-collapse: collapse; 
            min-width: 800px; 
        }
        .report-table thead th { 
            background: linear-gradient(135deg, #0f4c81 0%, #3b82f6 100%); 
            color: #ffffff; 
            padding: 16px 18px; 
            font-size: 15px; 
            font-weight: 700; 
            text-align: right;
            border-bottom: 2px solid rgba(255,255,255,0.2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 13px;
        }
        .report-table thead th:first-child {
            border-top-right-radius: 12px;
        }
        .report-table thead th:last-child {
            border-top-left-radius: 12px;
        }
        .report-table tbody td { 
            padding: 14px 18px; 
            border-bottom: 1px solid #f1f5f9; 
            font-size: 14px; 
            color: #1e293b; 
            text-align: right;
            font-weight: 500;
        }
        .report-table tbody tr {
            transition: background-color 0.2s ease;
        }
        .report-table tbody tr:hover {
            background-color: #f8fafc;
        }
        .report-table tbody tr:nth-child(even) { 
            background: #fafbfc; 
        }
        .report-table tbody tr:last-child td {
            border-bottom: none;
        }
        .report-table tbody tr:last-child td:first-child {
            border-bottom-right-radius: 12px;
        }
        .report-table tbody tr:last-child td:last-child {
            border-bottom-left-radius: 12px;
        }
        .empty { 
            padding: 60px 32px; 
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); 
            border: 2px dashed #cbd5e1; 
            border-radius: 12px; 
            text-align: center; 
            font-size: 16px; 
            color: #64748b;
            margin: 30px;
        }
        .footer-info {
            padding: 20px 30px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #64748b;
            font-size: 13px;
        }
        @media print { 
            @page {
                margin: 10mm;
            }
            body { 
                background: #ffffff; 
                padding: 0; 
            } 
            .report-wrapper { 
                box-shadow: none; 
                border-radius: 0; 
                padding: 0;
                max-width: 100%;
            }
            .report-wrapper::before {
                display: none;
            }
            .actions { 
                display: none !important; 
            }
            .report-header {
                padding: 20px;
                border-bottom: 3px solid #0f4c81;
            }
            .report-header h1 {
                font-size: 28px;
            }
            .table-wrapper { 
                overflow: visible;
                margin: 0 20px 20px;
                border: 1px solid #e2e8f0;
                page-break-inside: avoid;
            }
            .report-table {
                min-width: auto;
            }
            .report-table thead th {
                background: #0f4c81 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .report-table tbody tr {
                page-break-inside: avoid;
            }
            .footer-info {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: #f8fafc;
            }
        }
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            .report-header {
                padding: 20px;
            }
            .report-header h1 {
                font-size: 24px;
            }
            .table-wrapper {
                margin: 0 10px 20px;
            }
            .report-table {
                min-width: 600px;
            }
        }
    </style>';
    $html .= '</head><body>';
    $html .= '<div class="report-wrapper">';
    $html .= '<div class="actions">';
    $html .= '<button id="printReportButton" type="button">طباعة / حفظ كـ PDF</button>';
    $html .= '<span class="hint">يمكنك استخدام زر الطباعة أو حفظ الصفحة كـ PDF من المتصفح.</span>';
    $html .= '</div>';
    $html .= '<header class="report-header">';
    $html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    $html .= '<div class="meta">';
    $html .= '<span>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '<span>' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '</div>';
    $html .= '</header>';
    
    // إنشاء الجدول
    if (!empty($headers) && !empty($dataRows)) {
        $html .= '<div class="table-wrapper">';
        $html .= '<table class="report-table">';
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $header = trim($header);
            if (!empty($header)) {
                $html .= '<th>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
            }
        }
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        
        $headerCount = count($headers);
        foreach ($dataRows as $row) {
            // التأكد من أن الصف يحتوي على بيانات
            $rowStr = is_array($row) ? implode('', $row) : (string)$row;
            if (empty(trim($rowStr))) {
                continue;
            }
            
            $html .= '<tr>';
            for ($i = 0; $i < $headerCount; $i++) {
                $value = isset($row[$i]) ? trim($row[$i]) : '';
                $html .= '<td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
    } else {
        $html .= '<div class="empty">لا توجد بيانات متاحة لعرضها.</div>';
        // إضافة معلومات للتشخيص في وضع التطوير
        if (defined('DEBUG') && DEBUG) {
            $html .= '<div style="padding: 20px; background: #f1f5f9; margin-top: 20px; border-radius: 8px;">';
            $html .= '<strong>معلومات التشخيص:</strong><br>';
            $html .= 'عدد العناوين: ' . count($headers) . '<br>';
            $html .= 'عدد صفوف البيانات: ' . count($dataRows) . '<br>';
            $html .= 'مؤشر بداية البيانات: ' . $dataStartIndex . '<br>';
            $html .= 'إجمالي الصفوف في الملف: ' . count($rows) . '<br>';
            $html .= '</div>';
        }
    }
    
    $html .= '</div>';
    
    // إضافة معلومات التذييل
    $html .= '<div class="footer-info">';
    $html .= '<div>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . ' - ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '<div style="margin-top: 8px; font-size: 12px; opacity: 0.8;">تم إنشاء هذا التقرير تلقائياً من النظام</div>';
    $html .= '</div>';
    
    $html .= '</div>'; // إغلاق report-wrapper
    
    // إضافة سكريبت الطباعة
    $shouldPrint = isset($_GET['print']) && $_GET['print'] === '1';
    $printScript = '<script>
        (function() {
            function triggerPrint() {
                window.print();
            }
            document.addEventListener("DOMContentLoaded", function() {
                var btn = document.getElementById("printReportButton");
                if (btn) {
                    btn.addEventListener("click", function(e) {
                        e.preventDefault();
                        triggerPrint();
                    });
                }
                var params = new URLSearchParams(window.location.search);
                if (params.get("print") === "1") {
                    setTimeout(triggerPrint, 800);
                }
            });
        })();
    </script>';
    
    $html .= $printScript;
    $html .= '</body></html>';
    
    echo $html;
    exit;
    
} catch (Exception $e) {
    error_log('View CSV for print error: ' . $e->getMessage());
    error_log('View CSV for print error trace: ' . $e->getTraceAsString());
    
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>حدث خطأ أثناء عرض الملف</h1></body></html>';
    exit;
}


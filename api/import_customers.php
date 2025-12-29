<?php
/**
 * API لاستيراد العملاء من ملف CSV
 */

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

// تنظيف أي output سابق
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

// التحقق من الصلاحيات
requireRole(['manager', 'accountant', 'sales']);

$currentUser = getCurrentUser();
$db = db();

// التحقق من نوع الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// دالة تسجيل مخصصة تكتب مباشرة في ملف السجلات
// يتم تعريفها قبل أي استخدام
// تحسين للعمل حتى مع مشاكل الاستضافة
function logImport($message) {
    global $importDebugLog; // لتخزين السجلات في الذاكرة أيضاً
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    
    // تخزين في الذاكرة للعرض لاحقاً
    if (!isset($importDebugLog)) {
        $importDebugLog = [];
    }
    $importDebugLog[] = $logMessage;
    
    // تسجيل في error_log العادي (يعمل دائماً)
    error_log('[IMPORT] ' . $message);
    
    // قائمة المسارات المحتملة (من الأكثر احتمالاً للأقل)
    $possiblePaths = [];
    
    // 1. PRIVATE_STORAGE_PATH إذا كان معرفاً
    if (defined('PRIVATE_STORAGE_PATH')) {
        $possiblePaths[] = PRIVATE_STORAGE_PATH . '/logs/import_customers.log';
    }
    
    // 2. المسار النسبي storage/logs
    $possiblePaths[] = __DIR__ . '/../storage/logs/import_customers.log';
    
    // 3. في مجلد api
    $possiblePaths[] = __DIR__ . '/import_customers_debug.log';
    
    // 4. في المجلد الرئيسي
    $possiblePaths[] = dirname(__DIR__) . '/import_customers_debug.log';
    
    // 5. في temp directory
    $tempDir = sys_get_temp_dir();
    if ($tempDir && is_writable($tempDir)) {
        $possiblePaths[] = $tempDir . '/import_customers_debug.log';
    }
    
    // 6. APP_ERROR_LOG إذا كان معرفاً
    if (defined('APP_ERROR_LOG')) {
        $possiblePaths[] = APP_ERROR_LOG;
    }
    
    // محاولة الكتابة في جميع المسارات
    $written = false;
    foreach ($possiblePaths as $logFile) {
        $logDir = dirname($logFile);
        
        // محاولة إنشاء المجلد إذا لم يكن موجوداً
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        // محاولة الكتابة
        if (is_dir($logDir) && (is_writable($logDir) || @is_writable($logFile))) {
            $result = @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            if ($result !== false) {
                $written = true;
                // لا نكسر الحلقة - نكتب في جميع الملفات المتاحة
            }
        }
    }
    
    // إذا فشلت جميع المحاولات، على الأقل error_log يعمل
    if (!$written) {
        error_log("CRITICAL: Could not write to any log file. Message: $message");
        // محاولة أخيرة: كتابة في stdout (قد يعمل في بعض الاستضافات)
        if (defined('STDOUT') && is_resource(STDOUT)) {
            @fwrite(STDOUT, $logMessage);
        }
    }
    
    // تسجيل معلومات إضافية عن حالة الكتابة (فقط للرسائل المهمة)
    if (strpos($message, '=== ') !== false || strpos($message, 'ERROR') !== false || strpos($message, 'SUCCESS') !== false) {
        error_log("[IMPORT-DEBUG] Written: " . ($written ? 'YES' : 'NO') . " | Message: " . substr($message, 0, 100));
    }
}

// التأكد من وجود جدول customer_phones
try {
    $customerPhonesTable = $db->queryOne("SHOW TABLES LIKE 'customer_phones'");
    if (empty($customerPhonesTable)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `customer_phones` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `customer_id` int(11) NOT NULL,
                `phone` varchar(20) NOT NULL,
                `is_primary` tinyint(1) DEFAULT 0,
                `title` varchar(50) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `customer_id` (`customer_id`),
                CONSTRAINT `customer_phones_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        error_log('Table customer_phones created successfully in import API');
    } else {
        // التحقق من وجود عمود title وإضافته إذا لم يكن موجوداً
        $titleColumn = $db->queryOne("SHOW COLUMNS FROM customer_phones LIKE 'title'");
        if (empty($titleColumn)) {
            try {
                $db->execute("ALTER TABLE customer_phones ADD COLUMN `title` varchar(50) DEFAULT NULL AFTER `is_primary`");
                error_log('Column title added to customer_phones table');
            } catch (Exception $alterException) {
                error_log('Error adding title column to customer_phones: ' . $alterException->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log('Error creating customer_phones table in import API: ' . $e->getMessage());
    // لا نوقف العملية، قد يكون الجدول موجوداً بالفعل
}

// اختبار دالة التسجيل في البداية
logImport('=== API CALLED ===');
logImport('Request method: ' . $_SERVER['REQUEST_METHOD']);
logImport('Timestamp: ' . date('Y-m-d H:i:s'));
logImport('PHP Version: ' . PHP_VERSION);
logImport('Memory limit: ' . ini_get('memory_limit'));
logImport('Max execution time: ' . ini_get('max_execution_time'));

// تسجيل معلومات الاستضافة
logImport('Server: ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'));
logImport('Document root: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'));
logImport('Script path: ' . __FILE__);
logImport('Base dir: ' . __DIR__);

// التحقق من الصلاحيات
logImport('Current user ID: ' . ($currentUser['id'] ?? 'Unknown'));
logImport('Current user role: ' . ($currentUser['role'] ?? 'Unknown'));

// تسجيل معلومات الملف
if (isset($_FILES['excel_file'])) {
    logImport('File name: ' . ($_FILES['excel_file']['name'] ?? 'Unknown'));
    logImport('File size: ' . ($_FILES['excel_file']['size'] ?? 0) . ' bytes');
    logImport('File error: ' . ($_FILES['excel_file']['error'] ?? 'Unknown'));
} else {
    logImport('WARNING: No file uploaded');
}

// التحقق من نوع الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logImport('ERROR: Invalid request method: ' . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من وجود الملف
if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = 'لم يتم رفع الملف بنجاح';
    $errorCode = $_FILES['excel_file']['error'] ?? 'UNKNOWN';
    logImport('ERROR: File upload failed. Error code: ' . $errorCode);
    
    if (isset($_FILES['excel_file']['error'])) {
        switch ($_FILES['excel_file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMessage = 'حجم الملف كبير جداً';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMessage = 'تم رفع الملف جزئياً فقط';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMessage = 'لم يتم اختيار ملف';
                break;
        }
    }
    
    logImport('ERROR MESSAGE: ' . $errorMessage);
    
    echo json_encode([
        'success' => false,
        'message' => $errorMessage
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['excel_file'];
$fileName = $file['name'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];

// التحقق من نوع الملف (CSV أو Excel)
$allowedExtensions = ['csv', 'xlsx', 'xls'];
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($fileExtension, $allowedExtensions, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'نوع الملف غير مدعوم. يرجى رفع ملف CSV أو Excel (.csv, .xlsx أو .xls)'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من حجم الملف (10 ميجابايت)
if ($fileSize > 10 * 1024 * 1024) {
    echo json_encode([
        'success' => false,
        'message' => 'حجم الملف كبير جداً. الحد الأقصى 10 ميجابايت'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    logImport('========================================');
    logImport('=== STARTING CSV IMPORT ===');
    logImport('File: ' . $fileName);
    logImport('File size: ' . $fileSize . ' bytes');
    logImport('File extension: ' . $fileExtension);
    logImport('Timestamp: ' . date('Y-m-d H:i:s'));
    logImport('========================================');
    
    $rows = [];
    
    // قراءة الملف حسب نوعه
    if ($fileExtension === 'csv') {
        // قراءة ملف CSV مباشرة
        $handle = fopen($fileTmpPath, 'r');
        if ($handle === false) {
            throw new Exception('لا يمكن فتح الملف');
        }
        
        // قراءة الملف مع دعم UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        // قراءة البيانات
        $maxColumns = 0;
        while (($row = fgetcsv($handle, 1000, ',', '"', '\\')) !== false) {
            // تحديث العدد الأقصى للأعمدة
            if (count($row) > $maxColumns) {
                $maxColumns = count($row);
            }
            
            // تنظيف البيانات من BOM ومسافات إضافية
            $row = array_map(function($cell) {
                if ($cell === null) {
                    return '';
                }
                $cell = trim($cell);
                // إزالة BOM إذا كان موجوداً
                if (substr($cell, 0, 3) === "\xEF\xBB\xBF") {
                    $cell = substr($cell, 3);
                }
                return $cell;
            }, $row);
            
            // التأكد من أن الصف له نفس عدد الأعمدة (ملء الأعمدة الفارغة)
            while (count($row) < $maxColumns) {
                $row[] = '';
            }
            
            // تخطي الصفوف الفارغة تماماً
            $isEmpty = true;
            foreach ($row as $cell) {
                if (trim($cell) !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if (!$isEmpty) {
                $rows[] = $row;
            }
        }
        fclose($handle);
    } else {
        // محاولة قراءة Excel كـ CSV (إذا كان يمكن تحويله)
        // أو استخدام مكتبة إذا كانت متوفرة
        $usePhpSpreadsheet = false;
        
        // التحقق من وجود PhpSpreadsheet (اختياري)
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            $usePhpSpreadsheet = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
        } elseif (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $usePhpSpreadsheet = true;
        }
        
        if ($usePhpSpreadsheet) {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fileTmpPath);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($fileTmpPath);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
            } catch (Exception $spreadsheetError) {
                error_log('PhpSpreadsheet error: ' . $spreadsheetError->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'خطأ في قراءة ملف Excel. يرجى تصدير الملف كـ CSV:\n\n' .
                                'في Excel:\n' .
                                '1. ملف > حفظ باسم\n' .
                                '2. اختر: CSV UTF-8 (مفصول بفواصل)\n' .
                                '3. احفظ وارفعه مرة أخرى'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            // محاولة قراءة Excel كـ CSV كحل بديل (للملفات البسيطة)
            // ملاحظة: هذه الطريقة محدودة وقد لا تعمل مع جميع ملفات Excel
            try {
                $handle = @fopen($fileTmpPath, 'r');
                if ($handle === false) {
                    throw new Exception('لا يمكن فتح الملف');
                }
                
                // محاولة قراءة كـ CSV
                $lineCount = 0;
                while (($row = fgetcsv($handle, 1000, ',', '"', '\\')) !== false && $lineCount < 1000) {
                    $row = array_map(function($cell) {
                        return trim($cell);
                    }, $row);
                    // تخطي الصفوف الفارغة تماماً
                    $isEmpty = true;
                    foreach ($row as $cell) {
                        if (trim($cell) !== '') {
                            $isEmpty = false;
                            break;
                        }
                    }
                    if (!$isEmpty) {
                        $rows[] = $row;
                    }
                    $lineCount++;
                }
                fclose($handle);
                
                // إذا لم نحصل على بيانات، أعط رسالة خطأ واضحة
                if (empty($rows) || count($rows) < 2) {
                    throw new Exception('لا يمكن قراءة ملف Excel مباشرة');
                }
            } catch (Exception $csvReadError) {
                error_log('Excel CSV fallback error: ' . $csvReadError->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'لا يمكن قراءة ملف Excel مباشرة. يرجى تصدير الملف كـ CSV من Excel:\n\n' .
                                'الخطوات:\n' .
                                '1. في Excel: ملف > حفظ باسم\n' .
                                '2. اختر نوع الملف: CSV UTF-8 (مفصول بفواصل)\n' .
                                '3. احفظ الملف وارفعه مرة أخرى\n\n' .
                                'ملاحظة: يُفضل استخدام ملف CSV مباشرة لتجنب مشاكل التنسيق'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    
    if (empty($rows)) {
        logImport('ERROR: No rows found in file');
        echo json_encode([
            'success' => false,
            'message' => 'الملف فارغ أو لا يمكن قراءته'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (count($rows) < 2) {
        logImport('ERROR: File has only ' . count($rows) . ' row(s). Need at least 2 rows (header + data).');
        echo json_encode([
            'success' => false,
            'message' => 'الملف يجب أن يحتوي على رأس الأعمدة في الصف الأول وصف واحد على الأقل من البيانات. الملف الحالي يحتوي على ' . count($rows) . ' صف فقط.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    logImport('File read successfully. Total rows: ' . count($rows) . ' (1 header + ' . (count($rows) - 1) . ' data rows)');
    
    // قراءة رؤوس الأعمدة من الصف الأول
    logImport('=== READING HEADERS ===');
    logImport('First row (raw): ' . json_encode($rows[0], JSON_UNESCAPED_UNICODE));
    
    $originalHeaders = $rows[0]; // حفظ النسخة الأصلية للتسجيل
    $headers = array_map(function($header) {
        // تنظيف من BOM
        if (substr($header, 0, 3) === "\xEF\xBB\xBF") {
            $header = substr($header, 3);
        }
        return mb_strtolower(trim($header), 'UTF-8');
    }, $rows[0]);
    
    // تسجيل رؤوس الأعمدة الأصلية
    logImport('=== ORIGINAL CSV HEADERS ===');
    foreach ($originalHeaders as $idx => $hdr) {
        logImport("  Column $idx: '$hdr'");
    }
    logImport('=== NORMALIZED HEADERS ===');
    foreach ($headers as $idx => $hdr) {
        logImport("  Column $idx: '$hdr'");
    }
    
    // البحث عن أعمدة البيانات المطلوبة
    $customerIdIndex = -1;
    $nameIndex = -1;
    $phoneIndex = -1;
    $phone2Index = -1;
    $phone3Index = -1;
    $addressIndex = -1;
    $balanceIndex = -1;
    $regionIndex = -1;
    
    // البحث عن الأعمدة بطرق مختلفة (عربي/إنجليزي)
    $customerIdVariations = ['ايدي العميل', 'id', 'customer_id', 'معرف العميل', 'رقم العميل', 'ايدي', 'معرف', 'م', 'معرف العميل', 'رقم العميل'];
    $nameVariations = ['اسم العميل', 'الاسم', 'name', 'customer name', 'اسم', 'اسم_العميل', 'اسم العميل', 'الاسم الكامل'];
    $phoneVariations = ['رقم الهاتف', 'الهاتف', 'phone', 'mobile', 'tel', 'رقم_الهاتف', 'رقم الهاتف (الأول)', 'تليفون', 'تلفون', 'telephone', 'رقم التليفون', 'رقم التلفون', 'هاتف', 'موبايل'];
    $phone2Variations = ['رقم الهاتف (الثاني)', 'الهاتف الثاني', 'phone2', 'mobile2', 'tel2', 'رقم_الهاتف_الثاني', 'رقم الهاتف الثاني', 'تليفون 2', 'تلفون 2', 'هاتف 2', 'هاتف ثاني'];
    $phone3Variations = ['رقم الهاتف (الثالث)', 'الهاتف الثالث', 'phone3', 'mobile3', 'tel3', 'رقم_الهاتف_الثالث', 'رقم الهاتف الثالث', 'تليفون 3', 'تلفون 3', 'هاتف 3', 'هاتف ثالث'];
    $addressVariations = ['العنوان', 'address', 'location', 'عنوان', 'العنوان الكامل', 'عنوان العميل'];
    $balanceVariations = ['الرصيد', 'الديون', 'balance', 'debt', 'رصيد', 'ديون', 'صافى المبلغ', 'صافي المبلغ', 'صافى', 'صافي', 'المبلغ', 'net amount', 'رصيد العميل', 'رصيد العميل', 'الرصيد الحالي', 'المبلغ المستحق', 'المستحق', 'الديون المستحقة'];
    $regionVariations = ['المنطقة', 'region', 'منطقة', 'المنطقة', 'منطقة العميل'];
    
    // قائمة لتخزين جميع أعمدة "تليفون" (لحالة وجود عمودين بنفس الاسم)
    $phoneColumns = [];
    
    foreach ($headers as $index => $header) {
        // تنظيف اسم العمود من BOM ومسافات إضافية
        $header = trim($header);
        if (substr($header, 0, 3) === "\xEF\xBB\xBF") {
            $header = substr($header, 3);
        }
        // إزالة المسافات الزائدة والأحرف الخاصة
        $header = preg_replace('/\s+/', ' ', $header);
        $headerLower = mb_strtolower(trim($header), 'UTF-8');
        
        // ايدي العميل
        if ($customerIdIndex === -1 && (
            in_array($headerLower, $customerIdVariations, true) || 
            $headerLower === 'م' ||
            mb_strpos($headerLower, 'ايدي') !== false || 
            mb_strpos($headerLower, 'id') !== false ||
            mb_strpos($headerLower, 'معرف') !== false
        )) {
            $customerIdIndex = $index;
        }
        
        // اسم العميل
        if ($nameIndex === -1 && (
            in_array($headerLower, $nameVariations, true) || 
            mb_strpos($headerLower, 'اسم') !== false || 
            mb_strpos($headerLower, 'name') !== false
        )) {
            $nameIndex = $index;
        }
        
        // رقم الهاتف - جمع جميع أعمدة "تليفون" أولاً
        if (in_array($headerLower, $phoneVariations, true) || 
            mb_strpos($headerLower, 'تليفون') !== false ||
            mb_strpos($headerLower, 'تلفون') !== false ||
            (mb_strpos($headerLower, 'هاتف') !== false && mb_strpos($headerLower, 'ثاني') === false && mb_strpos($headerLower, '2') === false) ||
            mb_strpos($headerLower, 'phone') !== false ||
            mb_strpos($headerLower, 'mobile') !== false ||
            mb_strpos($headerLower, 'tel') !== false) {
            $phoneColumns[] = $index;
        }
        
        // العنوان
        if ($addressIndex === -1 && (
            in_array($headerLower, $addressVariations, true) || 
            mb_strpos($headerLower, 'عنوان') !== false || 
            mb_strpos($headerLower, 'address') !== false
        )) {
            $addressIndex = $index;
        }
        
        // الرصيد - تحسين البحث ليشمل المزيد من الاختلافات
        if ($balanceIndex === -1 && (
            in_array($headerLower, $balanceVariations, true) || 
            mb_strpos($headerLower, 'رصيد') !== false || 
            mb_strpos($headerLower, 'صافى') !== false ||
            mb_strpos($headerLower, 'صافي') !== false ||
            mb_strpos($headerLower, 'balance') !== false ||
            mb_strpos($headerLower, 'debt') !== false ||
            mb_strpos($headerLower, 'net') !== false ||
            mb_strpos($headerLower, 'مبلغ') !== false ||
            mb_strpos($headerLower, 'مستحق') !== false ||
            mb_strpos($headerLower, 'ديون') !== false
        )) {
            $balanceIndex = $index;
        }
        
        // المنطقة
        if ($regionIndex === -1 && (
            in_array($headerLower, $regionVariations, true) || 
            mb_strpos($headerLower, 'منطقة') !== false || 
            mb_strpos($headerLower, 'region') !== false
        )) {
            $regionIndex = $index;
        }
    }
    
    // معالجة أعمدة الهواتف - إذا كان هناك عمودان "تليفون"، الأول هو phoneIndex والثاني هو phone2Index
    if (!empty($phoneColumns)) {
        if (count($phoneColumns) >= 1) {
            $phoneIndex = $phoneColumns[0];
        }
        if (count($phoneColumns) >= 2) {
            $phone2Index = $phoneColumns[1];
        }
        if (count($phoneColumns) >= 3) {
            $phone3Index = $phoneColumns[2];
        }
        
        // البحث عن أعمدة الهواتف المحددة بشكل صريح
        if ($phoneIndex === -1 || $phone2Index === -1 || $phone3Index === -1) {
            foreach ($headers as $index => $header) {
                $headerLower = mb_strtolower(trim($header), 'UTF-8');
                
                // البحث عن الهاتف الثاني
                if ($phone2Index === -1 && $index !== $phoneIndex && (
                    in_array($headerLower, $phone2Variations, true) || 
                    (strpos($headerLower, 'هاتف') !== false && (strpos($headerLower, 'ثاني') !== false || strpos($headerLower, '2') !== false) && strpos($headerLower, '3') === false) ||
                    strpos($headerLower, '2') !== false ||
                    strpos($headerLower, 'mobile2') !== false
                )) {
                    $phone2Index = $index;
                }
                
                // البحث عن الهاتف الثالث
                if ($phone3Index === -1 && $index !== $phoneIndex && $index !== $phone2Index && (
                    in_array($headerLower, $phone3Variations, true) || 
                    (strpos($headerLower, 'هاتف') !== false && (strpos($headerLower, 'ثالث') !== false || strpos($headerLower, '3') !== false)) ||
                    strpos($headerLower, 'phone3') !== false ||
                    strpos($headerLower, 'mobile3') !== false
                )) {
                    $phone3Index = $index;
                }
            }
        }
    }
    
    // التحقق من وجود عمود الاسم (مطلوب)
    if ($nameIndex === -1) {
        echo json_encode([
            'success' => false,
            'message' => 'لم يتم العثور على عمود "اسم العميل" في الملف. يرجى التأكد من وجود هذا العمود في الصف الأول. الأعمدة المدعومة: ايدي العميل (اختياري - للتحديث)، اسم العميل (مطلوب)، رقم الهاتف، رقم الهاتف (الثاني)، رقم الهاتف (الثالث)، الرصيد، العنوان، المنطقة'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // تسجيل الأعمدة التي تم العثور عليها (للتشخيص)
    $foundColumns = [
        'customerIdIndex' => $customerIdIndex,
        'nameIndex' => $nameIndex,
        'phoneIndex' => $phoneIndex,
        'phone2Index' => $phone2Index,
        'addressIndex' => $addressIndex,
        'balanceIndex' => $balanceIndex,
        'regionIndex' => $regionIndex,
        'phoneColumns' => $phoneColumns
    ];
    logImport('=== IMPORT COLUMNS FOUND (BEFORE FLEXIBLE SEARCH) ===');
    logImport(json_encode($foundColumns, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    logImport('Headers (all): ' . json_encode($headers, JSON_UNESCAPED_UNICODE));
    
    // تسجيل تفصيلي لكل عمود
    foreach ($headers as $idx => $hdr) {
        logImport("Column $idx: '$hdr' (original: '{$originalHeaders[$idx]}')");
    }
    
    // التحقق من أن الأعمدة المهمة موجودة - بحث مرن جداً في كلا النسختين
    if ($balanceIndex === -1) {
        logImport('WARNING: balanceIndex not found in first pass!');
        logImport('  - Normalized headers: ' . implode(' | ', $headers));
        logImport('  - Original headers: ' . implode(' | ', $originalHeaders));
        
        // البحث في الأسماء الأصلية أولاً (أكثر دقة)
        foreach ($originalHeaders as $idx => $hdr) {
            $hdrClean = trim($hdr);
            $hdrLower = mb_strtolower($hdrClean, 'UTF-8');
            
            // البحث عن أي كلمة متعلقة بالرصيد أو المبلغ - بحث شامل جداً
            $balanceKeywords = ['رصيد', 'مبلغ', 'balance', 'debt', 'مستحق', 'ديون', 'صاف', 'صافي', 'المبلغ', 'الرصيد', 'رصيد العميل'];
            $found = false;
            foreach ($balanceKeywords as $keyword) {
                if (mb_stripos($hdrClean, $keyword) !== false || mb_stripos($hdrLower, $keyword) !== false) {
                    $found = true;
                    break;
                }
            }
            
            if ($found || preg_match('/رصيد|مبلغ|balance|debt|مستحق|ديون|صاف|صافي/i', $hdrClean)) {
                $balanceIndex = $idx;
                logImport("✓ FOUND balance column at index $idx: '$hdr' (normalized: '$hdrLower')");
                break;
            }
        }
        
        // إذا لم يُعثر عليه، ابحث في الأسماء المطابقة
        if ($balanceIndex === -1) {
            foreach ($headers as $idx => $hdr) {
                if (preg_match('/رصيد|مبلغ|balance|debt|مستحق|ديون|صاف|صافي/i', $hdr)) {
                    $balanceIndex = $idx;
                    logImport("✓ FOUND balance column (normalized) at index $idx: '$hdr'");
                    break;
                }
            }
        }
    }
    
    if ($phoneIndex === -1) {
        logImport('WARNING: phoneIndex not found in first pass!');
        logImport('  - Normalized headers: ' . implode(' | ', $headers));
        logImport('  - Original headers: ' . implode(' | ', $originalHeaders));
        
        // البحث في الأسماء الأصلية أولاً (أكثر دقة)
        foreach ($originalHeaders as $idx => $hdr) {
            $hdrClean = trim($hdr);
            $hdrLower = mb_strtolower($hdrClean, 'UTF-8');
            
            // البحث عن أي كلمة متعلقة بالهاتف - بحث شامل جداً
            $phoneKeywords = ['هاتف', 'تليفون', 'تلفون', 'phone', 'mobile', 'tel', 'telephone', 'موبايل'];
            $found = false;
            $isSecond = false;
            
            foreach ($phoneKeywords as $keyword) {
                if (mb_stripos($hdrClean, $keyword) !== false || mb_stripos($hdrLower, $keyword) !== false) {
                    // التحقق من أنه ليس هاتف ثاني
                    if (mb_stripos($hdrClean, 'ثاني') !== false || 
                        mb_stripos($hdrClean, '2') !== false ||
                        mb_stripos($hdrLower, 'second') !== false) {
                        $isSecond = true;
                    } else {
                        $found = true;
                    }
                    break;
                }
            }
            
            if ($found && !$isSecond) {
                $phoneIndex = $idx;
                logImport("✓ FOUND phone column at index $idx: '$hdr' (normalized: '$hdrLower')");
                break;
            }
        }
        
        // إذا لم يُعثر عليه، ابحث في الأسماء المطابقة
        if ($phoneIndex === -1) {
            foreach ($headers as $idx => $hdr) {
                if (preg_match('/هاتف|تليفون|تلفون|phone|mobile|tel/i', $hdr) && 
                    !preg_match('/ثاني|2|second/i', $hdr)) {
                    $phoneIndex = $idx;
                    logImport("✓ FOUND phone column (normalized) at index $idx: '$hdr'");
                    break;
                }
            }
        }
    }
    
    // تسجيل النتيجة النهائية
    logImport('=== FINAL COLUMN INDICES ===');
    logImport("nameIndex: $nameIndex");
    logImport("phoneIndex: $phoneIndex");
    logImport("phone2Index: $phone2Index");
    logImport("phone3Index: $phone3Index");
    logImport("balanceIndex: $balanceIndex");
    logImport("addressIndex: $addressIndex");
    
    // معالجة البيانات
    logImport('=== STARTING DATA PROCESSING ===');
    logImport('Total rows in file: ' . count($rows));
    logImport('Total data rows (excluding header): ' . (count($rows) - 1));
    logImport('Current user role: ' . ($currentUser['role'] ?? 'unknown'));
    logImport('Current user ID: ' . ($currentUser['id'] ?? 'unknown'));
    
    $imported = 0;
    $skipped = 0;
    $errors = [];
    $emptyRows = 0;
    
    // التحقق من وجود أعمدة في جدول customers
    $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
    $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
    $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
    $hasRegionIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'region_id'"));
    
    // بدء المعاملة
    $transactionStarted = false;
    $transactionCommitted = false;
    try {
        $db->beginTransaction();
        $transactionStarted = true;
        logImport('✓ Transaction started');
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            // تسجيل الصف الكامل للأول 5 صفوف
            if ($i <= 5) {
                logImport("=== Processing Row $i ===");
                logImport("Row length: " . count($row));
                logImport("Full row data: " . json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            
            // قراءة اسم العميل أولاً للتحقق من أنه غير فارغ
            $name = '';
            if (isset($row[$nameIndex])) {
                $name = trim($row[$nameIndex]);
                // إزالة BOM إذا كان موجوداً
                if (substr($name, 0, 3) === "\xEF\xBB\xBF") {
                    $name = substr($name, 3);
                }
            }
            
            // تخطي الصفوف الفارغة
            if (empty($name) || $name === '') {
                $emptyRows++;
                logImport("Row $i - Skipping empty row (name is empty or null)");
                continue;
            }
            
            logImport("Row $i - Processing customer: '$name'");
            
            // قراءة البيانات مع التعامل مع الأعمدة الفارغة
            // قراءة ايدي العميل
            $customerIdFromFile = null;
            if ($customerIdIndex !== -1 && isset($row[$customerIdIndex])) {
                $rawValue = trim($row[$customerIdIndex]);
                if ($rawValue !== '' && is_numeric($rawValue)) {
                    $customerIdFromFile = (int)$rawValue;
                    logImport("Row $i - Found customer ID in file: $customerIdFromFile");
                }
            }
            
            // قراءة رقم الهاتف الأول
            $phone = null;
            if ($phoneIndex !== -1) {
                // تسجيل معلومات التصحيح
                if ($i <= 5) {
                    logImport("Row $i - Reading phone from index $phoneIndex");
                    logImport("  - Row length: " . count($row));
                    logImport("  - Index exists: " . (isset($row[$phoneIndex]) ? 'YES' : 'NO'));
                    if (isset($row[$phoneIndex])) {
                        logImport("  - Raw value: " . var_export($row[$phoneIndex], true));
                        logImport("  - Value type: " . gettype($row[$phoneIndex]));
                        logImport("  - Is null: " . ($row[$phoneIndex] === null ? 'YES' : 'NO'));
                        logImport("  - Is empty: " . (empty($row[$phoneIndex]) ? 'YES' : 'NO'));
                    }
                }
                
                if (isset($row[$phoneIndex]) && $row[$phoneIndex] !== null && $row[$phoneIndex] !== '') {
                    $rawPhone = trim((string)$row[$phoneIndex]);
                    // تسجيل القيمة الأصلية
                    if ($i <= 5) {
                        logImport("Row $i - Raw phone value from CSV: '" . var_export($rawPhone, true) . "'");
                    }
                    // إزالة أي مسافات أو أحرف غير ضرورية
                    $rawPhone = str_replace([' ', '-', '_', '(', ')', '.', '/', '+'], '', $rawPhone);
                    // إزالة BOM إذا كان موجوداً
                    if (substr($rawPhone, 0, 3) === "\xEF\xBB\xBF") {
                        $rawPhone = substr($rawPhone, 3);
                    }
                    // إزالة أي أحرف غير رقمية
                    $rawPhone = preg_replace('/[^\d]/', '', $rawPhone);
                    if ($rawPhone !== '' && strlen($rawPhone) > 0) {
                        $phone = $rawPhone;
                        if ($i <= 5) {
                            logImport("Row $i - ✓ Cleaned phone: '$phone'");
                        }
                    } else {
                        if ($i <= 5) {
                            logImport("Row $i - ⚠ Phone became empty after cleaning");
                        }
                    }
                } else {
                    if ($i <= 5) {
                        logImport("Row $i - ⚠ Phone value is null or empty at index $phoneIndex");
                    }
                }
            } else {
                if ($i <= 5) {
                    logImport("Row $i - ✗ ERROR: phoneIndex is -1! Cannot read phone number.");
                    logImport("  - Available row indices: 0 to " . (count($row) - 1));
                }
            }
            
            // قراءة رقم الهاتف الثاني
            $phone2 = null;
            if ($phone2Index !== -1 && isset($row[$phone2Index])) {
                $rawPhone2 = trim((string)$row[$phone2Index]);
                // إزالة أي مسافات أو أحرف غير ضرورية
                $rawPhone2 = str_replace([' ', '-', '_', '(', ')', '.', '/', '+'], '', $rawPhone2);
                // إزالة BOM إذا كان موجوداً
                if (substr($rawPhone2, 0, 3) === "\xEF\xBB\xBF") {
                    $rawPhone2 = substr($rawPhone2, 3);
                }
                // إزالة أي أحرف غير رقمية
                $rawPhone2 = preg_replace('/[^\d]/', '', $rawPhone2);
                if ($rawPhone2 !== '' && strlen($rawPhone2) > 0) {
                    $phone2 = $rawPhone2;
                }
            } else {
                if ($i <= 5 && $phone2Index !== -1) {
                    logImport("WARNING: Row $i - phone2Index exists but row[$phone2Index] is empty. phone2Index=$phone2Index");
                }
            }
            
            // قراءة رقم الهاتف الثالث
            $phone3 = null;
            if ($phone3Index !== -1 && isset($row[$phone3Index])) {
                $rawPhone3 = trim((string)$row[$phone3Index]);
                // إزالة أي مسافات أو أحرف غير ضرورية
                $rawPhone3 = str_replace([' ', '-', '_', '(', ')', '.', '/', '+'], '', $rawPhone3);
                // إزالة BOM إذا كان موجوداً
                if (substr($rawPhone3, 0, 3) === "\xEF\xBB\xBF") {
                    $rawPhone3 = substr($rawPhone3, 3);
                }
                // إزالة أي أحرف غير رقمية
                $rawPhone3 = preg_replace('/[^\d]/', '', $rawPhone3);
                if ($rawPhone3 !== '' && strlen($rawPhone3) > 0) {
                    $phone3 = $rawPhone3;
                }
            } else {
                if ($i <= 5 && $phone3Index !== -1) {
                    logImport("WARNING: Row $i - phone3Index exists but row[$phone3Index] is empty. phone3Index=$phone3Index");
                }
            }
            
            // قراءة العنوان
            $address = null;
            if ($addressIndex !== -1 && isset($row[$addressIndex])) {
                $rawAddress = trim($row[$addressIndex]);
                if ($rawAddress !== '') {
                    $address = $rawAddress;
                }
            }
            
            // قراءة الرصيد
            $balance = 0.0;
            if ($balanceIndex !== -1) {
                // تسجيل معلومات التصحيح
                if ($i <= 5) {
                    logImport("Row $i - Reading balance from index $balanceIndex");
                    logImport("  - Row length: " . count($row));
                    logImport("  - Index exists: " . (isset($row[$balanceIndex]) ? 'YES' : 'NO'));
                    if (isset($row[$balanceIndex])) {
                        logImport("  - Raw value: " . var_export($row[$balanceIndex], true));
                        logImport("  - Value type: " . gettype($row[$balanceIndex]));
                        logImport("  - Is null: " . ($row[$balanceIndex] === null ? 'YES' : 'NO'));
                        logImport("  - Is empty: " . (empty($row[$balanceIndex]) ? 'YES' : 'NO'));
                    }
                }
                
                if (isset($row[$balanceIndex]) && $row[$balanceIndex] !== null && $row[$balanceIndex] !== '') {
                    $rawBalance = $row[$balanceIndex];
                    // تسجيل القيمة الأصلية
                    if ($i <= 5) {
                        logImport("Row $i - Raw balance value from CSV: '" . var_export($rawBalance, true) . "' (type: " . gettype($rawBalance) . ")");
                    }
                    // تحويل إلى نص وإزالة المسافات
                    $rawBalance = trim((string)$rawBalance);
                    // إزالة BOM إذا كان موجوداً
                    if (substr($rawBalance, 0, 3) === "\xEF\xBB\xBF") {
                        $rawBalance = substr($rawBalance, 3);
                    }
                    if ($rawBalance !== '' && $rawBalance !== null) {
                    // إزالة الفواصل من الأرقام (مثل 1,000.50 أو 1.000,50)
                    $rawBalance = str_replace(',', '', $rawBalance);
                    // إزالة أي مسافات إضافية
                    $rawBalance = str_replace(' ', '', $rawBalance);
                    // إزالة أي أحرف غير رقمية في البداية والنهاية (مثل $ أو ر.س أو ج.م)
                    $rawBalance = preg_replace('/^[^\d\-+.]*/u', '', $rawBalance);
                    $rawBalance = preg_replace('/[^\d\-+.]*$/u', '', $rawBalance);
                    // إزالة الأحرف العربية الشائعة (ر.س، ج.م، د.إ، إلخ)
                    $rawBalance = preg_replace('/[رجددإأآا]\.?[سمعإ]/u', '', $rawBalance);
                    // التحقق من أن القيمة رقمية
                    if (is_numeric($rawBalance)) {
                        $balance = (float)$rawBalance;
                        if ($i <= 5) {
                            logImport("Row $i - Parsed balance: $balance");
                        }
                    } else {
                        // محاولة إزالة المزيد من الأحرف غير الرقمية
                        $cleanedBalance = preg_replace('/[^\d.\-+]/', '', $rawBalance);
                        if (is_numeric($cleanedBalance)) {
                            $balance = (float)$cleanedBalance;
                            if ($i <= 5) {
                                logImport("Row $i - Parsed balance (after cleaning): $balance");
                            }
                        } else {
                            logImport("WARNING: Row $i - Balance value '$rawBalance' (cleaned: '$cleanedBalance') is not numeric");
                        }
                    }
                } else {
                        if ($i <= 5) {
                            logImport("Row $i - ⚠ Balance value is empty or null after trimming");
                        }
                    }
                } else {
                    if ($i <= 5) {
                        logImport("Row $i - ⚠ Balance value is null or empty at index $balanceIndex");
                    }
                }
            } else {
                if ($i <= 5) {
                    logImport("Row $i - ✗ ERROR: balanceIndex is -1! Cannot read balance.");
                    logImport("  - Available row indices: 0 to " . (count($row) - 1));
                }
            }
            
            // قراءة المنطقة
            $regionName = null;
            if ($regionIndex !== -1 && isset($row[$regionIndex])) {
                $rawRegion = trim($row[$regionIndex]);
                if ($rawRegion !== '') {
                    $regionName = $rawRegion;
                }
            }
            
            // تسجيل البيانات المقروءة (للتشخيص - يمكن إزالتها لاحقاً)
            if ($i <= 10) { // تسجيل أول 10 صفوف
                $rowData = [
                    'row' => $i,
                    'name' => $name,
                    'phone' => $phone ?? 'NULL',
                    'phone2' => $phone2 ?? 'NULL',
                    'phone3' => $phone3 ?? 'NULL',
                    'balance' => $balance,
                    'address' => $address ?? 'NULL',
                    'region' => $regionName ?? 'NULL',
                    'indices' => [
                        'phoneIndex' => $phoneIndex,
                        'phone2Index' => $phone2Index,
                        'phone3Index' => $phone3Index,
                        'balanceIndex' => $balanceIndex,
                        'addressIndex' => $addressIndex
                    ],
                    'raw_values' => [
                        'balance' => ($balanceIndex !== -1 && isset($row[$balanceIndex])) ? $row[$balanceIndex] : 'NOT_SET',
                        'phone' => ($phoneIndex !== -1 && isset($row[$phoneIndex])) ? $row[$phoneIndex] : 'NOT_SET',
                        'phone2' => ($phone2Index !== -1 && isset($row[$phone2Index])) ? $row[$phone2Index] : 'NOT_SET',
                        'phone3' => ($phone3Index !== -1 && isset($row[$phone3Index])) ? $row[$phone3Index] : 'NOT_SET'
                    ],
                    'row_length' => count($row),
                    'all_row_values' => $row,
                    'headers_mapping' => []
                ];
                
                // إضافة م mapping بين الأعمدة والقيم
                foreach ($originalHeaders as $colIdx => $colName) {
                    $rowData['headers_mapping'][$colName] = isset($row[$colIdx]) ? $row[$colIdx] : '';
                }
                
                logImport("=== ROW $i DATA ===");
                logImport(json_encode($rowData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            
            // البحث عن region_id إذا كان اسم المنطقة موجوداً
            $regionId = null;
            if ($hasRegionIdColumn && $regionName && $regionName !== '') {
                $region = $db->queryOne("SELECT id FROM regions WHERE name = ?", [$regionName]);
                if ($region) {
                    $regionId = $region['id'];
                }
            }
            
            $customerId = null;
            $isUpdate = false;
            
            // إذا كان هناك ايدي عميل في الملف، حاول التحديث
            if ($customerIdFromFile && $customerIdFromFile > 0) {
                // للمندوبين: التحقق من أن العميل ينتمي لهم
                if (isset($currentUser['role']) && $currentUser['role'] === 'sales') {
                    $existingCustomer = $db->queryOne(
                        "SELECT id FROM customers WHERE id = ? AND (created_by = ? OR rep_id = ?)",
                        [$customerIdFromFile, $currentUser['id'], $currentUser['id']]
                    );
                } else {
                    $existingCustomer = $db->queryOne("SELECT id FROM customers WHERE id = ?", [$customerIdFromFile]);
                }
                
                if ($existingCustomer) {
                    $customerId = $customerIdFromFile;
                    $isUpdate = true;
                    logImport("Row $i - Will UPDATE existing customer ID=$customerIdFromFile");
                } else {
                    // إذا كان مندوب يحاول تحديث عميل لا ينتمي له، ننشئ عميل جديد بدلاً من التخطي
                    if (isset($currentUser['role']) && $currentUser['role'] === 'sales') {
                        logImport("Row $i - Customer ID=$customerIdFromFile does not belong to sales rep. Creating new customer instead.");
                        // سنستمر في العملية لإنشاء عميل جديد (isUpdate سيبقى false)
                    } else {
                        logImport("Row $i - Customer ID=$customerIdFromFile not found. Creating new customer.");
                        // سنستمر في العملية لإنشاء عميل جديد
                    }
                }
            }
            
            // إذا لم يكن هناك تحديث، تحقق من التكرار
            if (!$isUpdate) {
                // للمندوبين: التحقق من التكرار فقط في عملائهم
                if (isset($currentUser['role']) && $currentUser['role'] === 'sales') {
                    // التحقق من التكرار بناءً على الاسم + الهاتف (إذا كان موجوداً)
                    if ($phone && trim($phone) !== '') {
                        // إذا كان هناك هاتف، نتحقق من الاسم + الهاتف
                        $duplicateCheck = "SELECT id FROM customers WHERE name = ? AND phone = ? AND (created_by = ? OR rep_id = ?)";
                        $duplicateParams = [$name, $phone, $currentUser['id'], $currentUser['id']];
                    } else {
                        // إذا لم يكن هناك هاتف، نتحقق من الاسم فقط (لكن فقط إذا كان هناك عميل بنفس الاسم بدون هاتف)
                        $duplicateCheck = "SELECT id FROM customers WHERE name = ? AND (phone IS NULL OR phone = '') AND (created_by = ? OR rep_id = ?)";
                        $duplicateParams = [$name, $currentUser['id'], $currentUser['id']];
                    }
                } else {
                    // للمدير/المحاسب: التحقق من التكرار في جميع العملاء
                    if ($phone && trim($phone) !== '') {
                        // إذا كان هناك هاتف، نتحقق من الاسم + الهاتف
                        $duplicateCheck = "SELECT id FROM customers WHERE name = ? AND phone = ?";
                        $duplicateParams = [$name, $phone];
                    } else {
                        // إذا لم يكن هناك هاتف، نتحقق من الاسم فقط (لكن فقط إذا كان هناك عميل بنفس الاسم بدون هاتف)
                        $duplicateCheck = "SELECT id FROM customers WHERE name = ? AND (phone IS NULL OR phone = '')";
                        $duplicateParams = [$name];
                    }
                }
                
                logImport("Row $i - Checking for duplicate: name='$name', phone=" . ($phone ? "'$phone'" : "NULL"));
                logImport("Row $i - Duplicate check SQL: $duplicateCheck");
                logImport("Row $i - Duplicate check params: " . json_encode($duplicateParams, JSON_UNESCAPED_UNICODE));
                
                $existing = $db->queryOne($duplicateCheck, $duplicateParams);
                
                if ($existing) {
                    $skipped++;
                    logImport("Row $i - ✗ DUPLICATE FOUND - Skipping customer: '$name' " . ($phone ? "(Phone: $phone)" : "(No phone)") . " (Existing ID: {$existing['id']})");
                    continue;
                } else {
                    logImport("Row $i - ✓ No duplicate found for: '$name' " . ($phone ? "(Phone: $phone)" : "(No phone)") . " - Will import");
                }
            }
            
            try {
                if ($isUpdate) {
                    // تحديث العميل الموجود
                    $updateFields = ['name = ?'];
                    $updateValues = [$name];
                    
                    // تحديث رقم الهاتف - دائماً تحديثه حتى لو كان null
                    $updateFields[] = 'phone = ?';
                    $updateValues[] = $phone;
                    
                    // تحديث الرصيد - دائماً تحديثه حتى لو كان 0
                    $updateFields[] = 'balance = ?';
                    $updateValues[] = $balance;
                    
                    // تحديث العنوان - دائماً تحديثه حتى لو كان null
                    $updateFields[] = 'address = ?';
                    $updateValues[] = $address;
                    
                    if ($hasRegionIdColumn && $regionId !== null) {
                        $updateFields[] = 'region_id = ?';
                        $updateValues[] = $regionId;
                    }
                    
                    $updateValues[] = $customerId;
                    
                    // تسجيل البيانات قبل التحديث
                    if ($i <= 5) {
                        logImport("Row $i - About to UPDATE customer ID=$customerId:");
                        logImport("  - Update fields: " . implode(', ', $updateFields));
                        logImport("  - Update values: " . json_encode($updateValues, JSON_UNESCAPED_UNICODE));
                    }
                    
                    try {
                        $db->execute(
                            "UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                            $updateValues
                        );
                        logImport("✓ Customer updated successfully: ID=$customerId");
                        logImport("  - Name: '$name'");
                        logImport("  - Phone: " . ($phone ?? 'NULL') . " (from index $phoneIndex)");
                        logImport("  - Balance: $balance (from index $balanceIndex, type: " . gettype($balance) . ")");
                        logImport("  - Address: " . ($address ?? 'NULL'));
                        
                        // التحقق من البيانات المحفوظة فوراً
                        $savedCustomer = $db->queryOne("SELECT name, phone, balance, address FROM customers WHERE id = ?", [$customerId]);
                        if ($savedCustomer) {
                            logImport("✓ VERIFIED saved data from database:");
                            logImport("  - Name: '{$savedCustomer['name']}'");
                            logImport("  - Phone: " . ($savedCustomer['phone'] ?? 'NULL'));
                            logImport("  - Balance: " . ($savedCustomer['balance'] ?? 'NULL') . " (type: " . gettype($savedCustomer['balance']) . ")");
                            logImport("  - Address: " . ($savedCustomer['address'] ?? 'NULL'));
                        } else {
                            logImport("✗ ERROR: Could not verify saved customer data!");
                        }
                    } catch (Exception $updateException) {
                        logImport("✗ ERROR updating customer: " . $updateException->getMessage());
                        logImport("  - SQL: UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?");
                        logImport("  - Values: " . json_encode($updateValues, JSON_UNESCAPED_UNICODE));
                        throw $updateException;
                    }
                    
                    // حذف أرقام الهواتف القديمة
                    $db->execute("DELETE FROM customer_phones WHERE customer_id = ?", [$customerId]);
                    logImport("Deleted old phones for customer $customerId");
                    
                    logAudit($currentUser['id'], 'update_customer', 'customer', $customerId, null, [
                        'name' => $name,
                        'source' => 'csv_import'
                    ]);
                } else {
                    // إدراج عميل جديد
                    // تسجيل البيانات قبل الحفظ
                    if ($i <= 5) {
                        logImport("Row $i - About to INSERT customer:");
                        logImport("  - Name: '$name'");
                        logImport("  - Phone: " . ($phone ?? 'NULL') . " (from index $phoneIndex)");
                        logImport("  - Balance: $balance (from index $balanceIndex)");
                        logImport("  - Address: " . ($address ?? 'NULL'));
                    }
                    
                    // تسجيل القيم قبل الحفظ
                    logImport("Row $i - Values before INSERT:");
                    logImport("  - name: '$name'");
                    logImport("  - phone: " . var_export($phone, true) . " (index: $phoneIndex)");
                    logImport("  - balance: " . var_export($balance, true) . " (index: $balanceIndex, type: " . gettype($balance) . ")");
                    logImport("  - address: " . var_export($address, true));
                    
                    // تحديد rep_id بناءً على دور المستخدم
                    $repId = null;
                    if (isset($currentUser['role']) && $currentUser['role'] === 'sales') {
                        $repId = $currentUser['id'];
                    }
                    
                    // توليد unique_code فريد للعميل
                    require_once __DIR__ . '/../includes/customer_code_generator.php';
                    ensureCustomerUniqueCodeColumn('customers');
                    $uniqueCode = generateUniqueCustomerCode('customers');
                    
                    $customerColumns = ['unique_code', 'name', 'phone', 'balance', 'address', 'status', 'created_by', 'rep_id', 'created_from_pos', 'created_by_admin'];
                    $customerValues = [
                        $uniqueCode,
                        $name,
                        $phone,  // قد يكون null
                        $balance, // قد يكون 0.0
                        $address, // قد يكون null
                        'active',
                        $currentUser['id'],
                        $repId, // تعيين rep_id للمندوبين
                        0,
                        ($currentUser['role'] === 'sales' ? 0 : 1) // created_by_admin = 0 للمندوبين، 1 للمدير/المحاسب
                    ];
                    $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?'];
                    
                    // تسجيل SQL Query
                    logImport("Row $i - SQL: INSERT INTO customers (" . implode(', ', $customerColumns) . ") VALUES (" . implode(', ', $customerPlaceholders) . ")");
                    logImport("Row $i - Values: " . json_encode($customerValues, JSON_UNESCAPED_UNICODE));
                    
                    // إضافة region_id إذا كان موجوداً
                    if ($hasRegionIdColumn && $regionId !== null) {
                        $customerColumns[] = 'region_id';
                        $customerValues[] = $regionId;
                        $customerPlaceholders[] = '?';
                    }
                    
                    try {
                        logImport("Row $i - Attempting INSERT for customer: '$name'");
                        logImport("  - rep_id: " . ($repId ?? 'NULL'));
                        logImport("  - created_by: " . $currentUser['id']);
                        logImport("  - created_by_admin: " . ($currentUser['role'] === 'sales' ? 0 : 1));
                        
                        $db->execute(
                            "INSERT INTO customers (" . implode(', ', $customerColumns) . ") 
                             VALUES (" . implode(', ', $customerPlaceholders) . ")",
                            $customerValues
                        );
                        
                        $customerId = $db->getLastInsertId();
                        if ($customerId <= 0) {
                            logImport("✗ ERROR: Insert succeeded but customerId is invalid: $customerId");
                            throw new Exception("فشل الحصول على معرف العميل بعد الإدراج");
                        }
                        logImport("✓ Customer inserted successfully: ID=$customerId");
                        logImport("  - Name: '$name'");
                        logImport("  - Phone: " . ($phone ?? 'NULL') . " (from index $phoneIndex)");
                        logImport("  - Balance: $balance (from index $balanceIndex, type: " . gettype($balance) . ")");
                        logImport("  - Address: " . ($address ?? 'NULL'));
                        
                        // التحقق من البيانات المحفوظة فوراً
                        $savedCustomer = $db->queryOne("SELECT name, phone, balance, address FROM customers WHERE id = ?", [$customerId]);
                        if ($savedCustomer) {
                            logImport("✓ VERIFIED saved data from database:");
                            logImport("  - Name: '{$savedCustomer['name']}'");
                            logImport("  - Phone: " . ($savedCustomer['phone'] ?? 'NULL'));
                            logImport("  - Balance: " . ($savedCustomer['balance'] ?? 'NULL') . " (type: " . gettype($savedCustomer['balance']) . ")");
                            logImport("  - Address: " . ($savedCustomer['address'] ?? 'NULL'));
                        } else {
                            logImport("✗ ERROR: Could not verify saved customer data!");
                        }
                    } catch (Exception $insertException) {
                        logImport("✗ ERROR inserting customer: " . $insertException->getMessage());
                        logImport("  - SQL: INSERT INTO customers (" . implode(', ', $customerColumns) . ") VALUES (" . implode(', ', $customerPlaceholders) . ")");
                        logImport("  - Values: " . json_encode($customerValues, JSON_UNESCAPED_UNICODE));
                        throw $insertException;
                    }
                    
                    logAudit($currentUser['id'], 'import_customer', 'customer', $customerId, null, [
                        'name' => $name,
                        'source' => 'csv_import'
                    ]);
                }
                
                // حفظ أرقام الهواتف في جدول customer_phones
                try {
                    $phonesToSave = [];
                    if ($phone !== null && trim($phone) !== '') {
                        $phonesToSave[] = ['phone' => trim($phone), 'title' => null, 'is_primary' => true];
                    }
                    if ($phone2 !== null && trim($phone2) !== '') {
                        $phonesToSave[] = ['phone' => trim($phone2), 'title' => null, 'is_primary' => false];
                    }
                    if ($phone3 !== null && trim($phone3) !== '') {
                        $phonesToSave[] = ['phone' => trim($phone3), 'title' => '2', 'is_primary' => false];
                    }
                    
                    logImport("Customer $customerId ($name) - Phones to save: " . json_encode($phonesToSave, JSON_UNESCAPED_UNICODE));
                    logImport("  - Phone1 (index $phoneIndex): " . ($phone ?? 'NULL'));
                    logImport("  - Phone2 (index $phone2Index): " . ($phone2 ?? 'NULL'));
                    logImport("  - Phone3 (index $phone3Index): " . ($phone3 ?? 'NULL') . " (title: 2)");
                    
                    if (!empty($phonesToSave)) {
                        // حذف أرقام الهواتف القديمة أولاً (في حالة التحديث)
                        if ($isUpdate) {
                            $db->execute("DELETE FROM customer_phones WHERE customer_id = ?", [$customerId]);
                            logImport("Deleted old phones for customer $customerId");
                        }
                        
                        $savedCount = 0;
                        foreach ($phonesToSave as $phoneData) {
                            $phoneNumber = trim($phoneData['phone']);
                            $phoneTitle = $phoneData['title'];
                            $isPrimary = $phoneData['is_primary'] ? 1 : 0;
                            
                            if (!empty($phoneNumber) && strlen($phoneNumber) > 0) {
                                try {
                                    // التحقق من عدم وجود الرقم مسبقاً
                                    $existingPhone = $db->queryOne(
                                        "SELECT id FROM customer_phones WHERE customer_id = ? AND phone = ?",
                                        [$customerId, $phoneNumber]
                                    );
                                    
                                    if (!$existingPhone) {
                                        // بناء استعلام INSERT مع أو بدون title
                                        if ($phoneTitle !== null) {
                                            $result = $db->execute(
                                                "INSERT INTO customer_phones (customer_id, phone, is_primary, title) VALUES (?, ?, ?, ?)",
                                                [$customerId, $phoneNumber, $isPrimary, $phoneTitle]
                                            );
                                            logImport("✓ Phone saved: ID=" . $db->getLastInsertId() . ", Customer ID=$customerId ($name), Phone=$phoneNumber, Primary=$isPrimary, Title=$phoneTitle");
                                        } else {
                                            $result = $db->execute(
                                                "INSERT INTO customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                                                [$customerId, $phoneNumber, $isPrimary]
                                            );
                                            logImport("✓ Phone saved: ID=" . $db->getLastInsertId() . ", Customer ID=$customerId ($name), Phone=$phoneNumber, Primary=$isPrimary");
                                        }
                                        $savedCount++;
                                    } else {
                                        logImport("⚠ Phone already exists: Customer ID $customerId, Phone: $phoneNumber");
                                    }
                                } catch (Exception $phoneInsertError) {
                                    // تسجيل الخطأ ولكن لا نوقف العملية
                                    logImport('✗ Error inserting phone number "' . $phoneNumber . '" for customer ' . $customerId . ': ' . $phoneInsertError->getMessage());
                                }
                            }
                        }
                        logImport("Total phones saved for customer $customerId: $savedCount");
                        
                        // التحقق من الأرقام المحفوظة
                        $savedPhones = $db->query("SELECT phone, is_primary, title FROM customer_phones WHERE customer_id = ?", [$customerId]);
                        logImport("✓ Verified saved phones for customer $customerId: " . json_encode($savedPhones, JSON_UNESCAPED_UNICODE));
                    } else {
                        logImport("⚠ No phones to save for customer: $name (ID: $customerId) - Phone1: " . ($phone ?? 'NULL') . " (index: $phoneIndex), Phone2: " . ($phone2 ?? 'NULL') . " (index: $phone2Index), Phone3: " . ($phone3 ?? 'NULL') . " (index: $phone3Index)");
                    }
                } catch (Exception $phonesError) {
                    // تسجيل الخطأ ولكن لا نوقف العملية
                    logImport('✗ Error saving phone numbers for customer ' . $customerId . ': ' . $phonesError->getMessage());
                    logImport('Stack trace: ' . $phonesError->getTraceAsString());
                }
                
                $imported++;
                logImport("Row $i - Customer imported successfully. Total imported so far: $imported");
            } catch (Exception $insertError) {
                logImport("Row $i - ERROR: " . $insertError->getMessage());
                $errors[] = "سطر " . ($i + 1) . ": " . $insertError->getMessage();
            }
        }
        
        logImport("=== LOOP COMPLETED ===");
        logImport("Total imported: $imported");
        logImport("Total skipped: $skipped");
        logImport("Total errors: " . count($errors));
        logImport("Transaction started: " . ($transactionStarted ? 'YES' : 'NO'));
        
        // تأكيد المعاملة - يجب أن يتم قبل أي شيء آخر
        logImport('=== COMMITTING TRANSACTION ===');
        $transactionCommitted = false;
        try {
            if ($transactionStarted) {
                logImport('Attempting to commit transaction...');
                $commitResult = $db->commit();
                logImport('Commit result: ' . ($commitResult ? 'SUCCESS' : 'FAILED'));
                
                if ($commitResult === false) {
                    $errorMsg = 'Commit returned false - transaction may have failed';
                    logImport('✗ ERROR: ' . $errorMsg);
                    throw new Exception($errorMsg);
                }
                
                $transactionCommitted = true;
                $transactionStarted = false; // تم commit، لا نحتاج rollback
                logImport('✓ Transaction committed successfully');
                
                // التحقق النهائي من البيانات المحفوظة - بعد commit مباشرة
                if ($imported > 0) {
                    logImport('=== VERIFYING SAVED DATA AFTER COMMIT ===');
                    try {
                        // استخدام استعلام بسيط للتحقق - بدون prepared statement لتجنب أي مشاكل
                        $userId = (int)$currentUser['id'];
                        $verifyQuery = "SELECT COUNT(*) as count FROM customers WHERE created_by = $userId AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
                        $verifyResult = $db->rawQuery($verifyQuery, false);
                        
                        if ($verifyResult) {
                            $row = $verifyResult->fetch_assoc();
                            $recentCount = (int)($row['count'] ?? 0);
                            logImport("Recent customers (last 5 minutes): $recentCount (expected: $imported)");
                            
                            if ($recentCount < $imported) {
                                logImport("⚠ WARNING: Recent count ($recentCount) is less than imported count ($imported)");
                                logImport("  This may indicate that data was not saved properly");
                                
                                // محاولة التحقق مرة أخرى بعد ثانية
                                sleep(1);
                                $verifyResult2 = $db->rawQuery($verifyQuery, false);
                                if ($verifyResult2) {
                                    $row2 = $verifyResult2->fetch_assoc();
                                    $recentCount2 = (int)($row2['count'] ?? 0);
                                    logImport("Second verification (after 1 second): $recentCount2");
                                }
                            } else {
                                logImport("✓ SUCCESS: All imported customers are saved in database");
                            }
                        } else {
                            logImport("⚠ Could not execute verification query");
                        }
                    } catch (Exception $verifyError) {
                        logImport('⚠ Warning: Could not verify saved data: ' . $verifyError->getMessage());
                    }
                }
            } else {
                logImport('⚠ No transaction to commit');
            }
        } catch (Exception $commitError) {
            logImport('✗ ERROR committing transaction: ' . $commitError->getMessage());
            logImport('✗ Stack trace: ' . $commitError->getTraceAsString());
            if ($transactionStarted) {
                try {
                    $db->rollBack();
                    logImport('✓ Transaction rolled back after commit error');
                } catch (Exception $rollbackError) {
                    logImport('✗ ERROR during rollback after commit error: ' . $rollbackError->getMessage());
                }
                $transactionStarted = false;
            }
            throw $commitError;
        }
        
        logImport('=== IMPORT COMPLETED ===');
        logImport("Imported: $imported customers");
        logImport("Skipped: $skipped customers");
        logImport("Errors: " . count($errors));
        logImport("Empty rows skipped: $emptyRows");
        logImport("Total rows processed: " . (count($rows) - 1));
        
        // التحقق من أن هناك استيراد فعلي
        if ($imported === 0 && $skipped === 0 && empty($errors)) {
            logImport('WARNING: No customers imported, skipped, or errors. File may be empty or invalid.');
            $totalDataRows = count($rows) - 1;
            $errorMessage = 'لم يتم استيراد أي عميل.';
            if ($emptyRows > 0) {
                $errorMessage .= " تم تخطي {$emptyRows} صف فارغ.";
            }
            if ($totalDataRows === 0) {
                $errorMessage .= ' الملف لا يحتوي على صفوف بيانات (فقط رأس الأعمدة).';
            } else {
                $errorMessage .= ' يرجى التحقق من: 1) أن عمود "اسم العميل" موجود في الصف الأول، 2) أن البيانات غير فارغة، 3) أن الأعمدة مطابقة للأسماء المدعومة';
            }
            echo json_encode([
                'success' => false,
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'message' => $errorMessage,
                'debug_info' => [
                    'total_rows' => $totalDataRows,
                    'empty_rows' => $emptyRows,
                    'name_index' => $nameIndex
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // مسح الكاش بعد commit (لا يؤثر على البيانات المحفوظة)
        try {
            if (class_exists('Cache')) {
                Cache::flush();
            }
        } catch (Exception $cacheError) {
            // لا نوقف العملية إذا فشل مسح الكاش
            logImport('⚠ Warning: Failed to flush cache: ' . $cacheError->getMessage());
        }
        
        $message = "تم استيراد {$imported} عميل بنجاح";
        if ($skipped > 0) {
            $message .= " وتم تخطي {$skipped} عميل (مكرر)";
        }
        if (!empty($errors)) {
            $message .= ". حدثت أخطاء في " . count($errors) . " سطر";
        }
        
        // إذا لم يتم استيراد أي عميل ولكن تم تخطي بعضها، فهذا يعني أن جميع العملاء مكررين
        if ($imported === 0 && $skipped > 0) {
            $message = "تم تخطي جميع العملاء ({$skipped} عميل) لأنهم موجودون مسبقاً في قاعدة البيانات";
        }
        
        // إضافة معلومات التصحيح في response
        $response = [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => $message
        ];
        
        // إضافة معلومات التصحيح إذا كانت متاحة
        global $importDebugLog;
        if (isset($importDebugLog) && !empty($importDebugLog)) {
            // إضافة آخر 30 سطر من السجلات
            $response['debug_log'] = array_slice($importDebugLog, -30);
            $response['debug_log_count'] = count($importDebugLog);
        } else {
            $response['debug_log_warning'] = 'No debug log available - logImport may not be working';
        }
        
        // إضافة معلومات عن حالة المعاملة
        $response['transaction_info'] = [
            'started' => $transactionStarted ?? false,
            'committed' => $transactionCommitted ?? false
        ];
        
        // التحقق النهائي من البيانات المحفوظة
        if ($imported > 0 && $transactionCommitted) {
            try {
                $verifyQuery = "SELECT COUNT(*) as count FROM customers WHERE created_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
                $verifyResult = $db->queryOne($verifyQuery, [$currentUser['id']]);
                $savedCount = (int)($verifyResult['count'] ?? 0);
                $response['verified_saved'] = $savedCount;
                $response['verified_match'] = ($savedCount >= $imported);
                
                if ($savedCount < $imported) {
                    $response['warning'] = "تم حفظ $savedCount عميل فقط من أصل $imported";
                }
            } catch (Exception $verifyError) {
                $response['verify_error'] = $verifyError->getMessage();
            }
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        // تسجيل الخطأ فوراً في error_log (يعمل دائماً)
        error_log('=== IMPORT EXCEPTION CAUGHT ===');
        error_log('Error: ' . $e->getMessage());
        error_log('File: ' . $e->getFile());
        error_log('Line: ' . $e->getLine());
        error_log('Trace: ' . $e->getTraceAsString());
        
        // التحقق من حالة المعاملة قبل rollback
        // فقط إذا كانت المعاملة لا تزال نشطة ولم يتم commit
        if ($transactionStarted && !$transactionCommitted) {
            try {
                if ($db->inTransaction()) {
                    $db->rollBack();
                    logImport('✓ Transaction rolled back due to error');
                    error_log('Transaction rolled back');
                } else {
                    logImport('⚠ Transaction not active, skipping rollback');
                    error_log('Transaction not active, skipping rollback');
                }
            } catch (Exception $rollbackError) {
                logImport('✗ ERROR during rollback: ' . $rollbackError->getMessage());
                error_log('Rollback error: ' . $rollbackError->getMessage());
            }
            $transactionStarted = false;
        } else {
            if ($transactionCommitted) {
                logImport('⚠ Transaction already committed, data is saved. Error occurred after commit.');
                error_log('Transaction already committed - data should be saved');
            } else {
                logImport('⚠ No transaction to rollback');
                error_log('No transaction to rollback');
            }
        }
        
        $errorMsg = 'Transaction error: ' . $e->getMessage();
        $errorTrace = 'Stack trace: ' . $e->getTraceAsString();
        
        logImport('✗ TRANSACTION ERROR: ' . $errorMsg);
        logImport('✗ STACK TRACE: ' . $errorTrace);
        
        error_log($errorMsg);
        error_log($errorTrace);
        
        // إذا تم commit بالفعل، لا نرمي exception - البيانات محفوظة
        // فقط نرمي exception إذا كان commit لم يحدث
        if (!$transactionCommitted) {
            throw $e;
        } else {
            // البيانات محفوظة، لكن حدث خطأ بعد commit
            // نرسل response بنجاح مع تحذير
            logImport('⚠ Data was saved but error occurred after commit. Sending success response.');
            error_log('Data was saved but error occurred after commit');
            
            global $importDebugLog;
            $response = [
                'success' => true,
                'imported' => $imported ?? 0,
                'skipped' => $skipped ?? 0,
                'errors' => $errors ?? [],
                'message' => "تم استيراد " . ($imported ?? 0) . " عميل بنجاح (حدث خطأ بعد الحفظ ولكن البيانات محفوظة)",
                'warning' => 'حدث خطأ بعد حفظ البيانات ولكن جميع البيانات تم حفظها بنجاح',
                'error_after_commit' => $e->getMessage()
            ];
            
            if (isset($importDebugLog) && !empty($importDebugLog)) {
                $response['debug_log'] = array_slice($importDebugLog, -30);
            }
            
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
} catch (Exception $e) {
    // تسجيل فوراً في error_log (يعمل دائماً)
    error_log('=== OUTER EXCEPTION HANDLER ===');
    error_log('Import customers error: ' . $e->getMessage());
    error_log('File: ' . $e->getFile());
    error_log('Line: ' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    $errorMsg = 'Import customers error: ' . $e->getMessage();
    $errorTrace = 'Stack trace: ' . $e->getTraceAsString();
    
    // تسجيل في logImport
    logImport('✗ EXCEPTION: ' . $errorMsg);
    logImport('✗ STACK TRACE: ' . $errorTrace);
    
    // تسجيل في error_log مرة أخرى للتأكد
    error_log($errorMsg);
    error_log($errorTrace);
    
    // التأكد من عدم وجود output قبل JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء استيراد البيانات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('Import customers fatal error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // التأكد من عدم وجود output قبل JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ فادح أثناء استيراد البيانات. يرجى التحقق من ملف error_log'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

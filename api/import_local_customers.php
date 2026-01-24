<?php
/**
 * API لاستيراد العملاء المحليين من ملف CSV
 */

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

// تنظيف أي output سابق
while (ob_get_level() > 0) {
    ob_end_clean();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من الصلاحيات
requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();

// التأكد من وجود جدول local_customer_phones لحفظ أكثر من رقم لكل عميل
try {
    $localCustomerPhonesTable = $db->queryOne("SHOW TABLES LIKE 'local_customer_phones'");
    if (empty($localCustomerPhonesTable)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `local_customer_phones` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `customer_id` int(11) NOT NULL,
                `phone` varchar(20) NOT NULL,
                `is_primary` tinyint(1) DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `customer_id` (`customer_id`),
                CONSTRAINT `local_customer_phones_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `local_customers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        error_log('Table local_customer_phones created successfully in import_local_customers API');
    }
} catch (Exception $e) {
    // لا نوقف العملية إذا فشل إنشاء الجدول، فقط نسجل الخطأ
    error_log('Error creating local_customer_phones table in import_local_customers API: ' . $e->getMessage());
}

// التأكد من وجود جدول المناطق وحقل region_id في local_customers
try {
    // إنشاء جدول regions إذا لم يكن موجوداً
    $regionsTable = $db->queryOne("SHOW TABLES LIKE 'regions'");
    if (empty($regionsTable)) {
        $createRegionsTableSql = "
            CREATE TABLE IF NOT EXISTS `regions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المناطق'
        ";
        try {
            $db->execute($createRegionsTableSql);
            error_log('Table regions created successfully in import_local_customers API');
        } catch (Exception $e) {
            error_log('Error creating regions table in import_local_customers API: ' . $e->getMessage());
        }
    }
    
    // إضافة حقل region_id إلى جدول local_customers إذا لم يكن موجوداً
    $regionIdColumn = $db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'region_id'");
    if (empty($regionIdColumn)) {
        try {
            $db->execute("ALTER TABLE `local_customers` ADD COLUMN `region_id` int(11) DEFAULT NULL AFTER `address`, ADD KEY `region_id` (`region_id`)");
            // محاولة إضافة المفتاح الأجنبي إذا لم يكن موجوداً
            $fkCheck = $db->queryOne("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'local_customers' AND CONSTRAINT_NAME = 'local_customers_ibfk_region'");
            if (empty($fkCheck)) {
                $db->execute("ALTER TABLE `local_customers` ADD CONSTRAINT `local_customers_ibfk_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL");
            }
            error_log('Column region_id added to local_customers in import_local_customers API');
        } catch (Exception $e) {
            error_log('Error adding region_id column to local_customers in import_local_customers API: ' . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log('Error ensuring regions table/column in import_local_customers API: ' . $e->getMessage());
}

// التحقق من نوع الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    $rows = [];
    
    // قراءة الملف حسب نوعه
    if ($fileExtension === 'csv') {
        // قراءة ملف CSV مباشرة
        $handle = @fopen($fileTmpPath, 'r');
        if ($handle === false) {
            throw new Exception('لا يمكن فتح الملف. يرجى التأكد من أن الملف موجود وصالح');
        }
        
        // قراءة الملف مع دعم UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        // قراءة البيانات
        $lineNumber = 0;
        while (($row = fgetcsv($handle, 1000, ',', '"', '\\')) !== false) {
            $lineNumber++;
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
        
        if (empty($rows)) {
            throw new Exception('الملف فارغ أو لا يحتوي على بيانات صالحة');
        }
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
                    'message' => 'خطأ في قراءة ملف Excel. يرجى تصدير الملف كـ CSV وإعادة المحاولة، أو تثبيت مكتبة PhpSpreadsheet'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            // إذا لم تكن المكتبة متوفرة، اطلب من المستخدم تصدير Excel كـ CSV
            echo json_encode([
                'success' => false,
                'message' => 'ملفات Excel تتطلب مكتبة إضافية. يرجى تصدير الملف كـ CSV من Excel (ملف > حفظ باسم > CSV UTF-8) وإعادة المحاولة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    if (empty($rows)) {
        echo json_encode([
            'success' => false,
            'message' => 'الملف فارغ أو لا يحتوي على بيانات'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (count($rows) < 2) {
        echo json_encode([
            'success' => false,
            'message' => 'الملف يجب أن يحتوي على رؤوس الأعمدة في الصف الأول وصف واحد على الأقل من البيانات'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // قراءة رؤوس الأعمدة من الصف الأول
    $headers = array_map(function($header) {
        return mb_strtolower(trim($header), 'UTF-8');
    }, $rows[0]);
    
    // البحث عن أعمدة البيانات المطلوبة
    $nameIndex = -1;
    $phoneIndex = -1;
    $phone2Index = -1;
    $addressIndex = -1;
    $balanceIndex = -1;
    $regionIndex = -1;
    
    // البحث عن الأعمدة بطرق مختلفة (عربي/إنجليزي)
    $nameVariations = ['اسم العميل', 'الاسم', 'name', 'customer name', 'اسم', 'اسم_العميل'];
    $phoneVariations = [
        'رقم الهاتف',
        'الهاتف',
        'phone',
        'mobile',
        'tel',
        'رقم_الهاتف',
        'تليفون',
        'تلفون',
        'telephone',
        'رقم التليفون',
        'رقم التلفون',
        'هاتف'
    ];
    $phone2Variations = [
        'رقم الهاتف (الثاني)',
        'رقم الهاتف الثاني',
        'الهاتف الثاني',
        'phone2',
        'mobile2',
        'tel2',
        'رقم_الهاتف_الثاني',
        'رقم الهاتف2',
        'رقم_الهاتف2',
        'تليفون 2',
        'تلفون 2',
        'تليفون2',
        'تلفون2',
        'هاتف 2',
        'هاتف ثاني'
    ];
    $addressVariations = ['العنوان', 'address', 'location', 'عنوان'];
    $balanceVariations = ['الرصيد', 'الديون', 'balance', 'debt', 'رصيد', 'ديون'];
    $regionVariations = ['المنطقة', 'region', 'منطقة'];
    
    foreach ($headers as $index => $header) {
        $headerLower = mb_strtolower(trim($header), 'UTF-8');
        
        if ($nameIndex === -1 && (
            in_array($headerLower, $nameVariations, true) || 
            strpos($headerLower, 'اسم') !== false || 
            strpos($headerLower, 'name') !== false
        )) {
            $nameIndex = $index;
        }
        // رقم الهاتف الأول
        if ($phoneIndex === -1 && (
            in_array($headerLower, $phoneVariations, true) || 
            (strpos($headerLower, 'هاتف') !== false && strpos($headerLower, 'ثاني') === false && strpos($headerLower, '2') === false) || 
            (strpos($headerLower, 'phone') !== false && strpos($headerLower, '2') === false) ||
            (strpos($headerLower, 'mobile') !== false && strpos($headerLower, '2') === false) ||
            (strpos($headerLower, 'tel') !== false && strpos($headerLower, '2') === false)
        )) {
            $phoneIndex = $index;
        }
        // رقم الهاتف الثاني
        if ($phone2Index === -1 && (
            in_array($headerLower, $phone2Variations, true) ||
            (strpos($headerLower, 'هاتف') !== false && (strpos($headerLower, 'ثاني') !== false || strpos($headerLower, '2') !== false)) ||
            strpos($headerLower, 'phone2') !== false ||
            strpos($headerLower, 'mobile2') !== false ||
            strpos($headerLower, 'tel2') !== false
        )) {
            $phone2Index = $index;
        }
        if ($addressIndex === -1 && (
            in_array($headerLower, $addressVariations, true) || 
            strpos($headerLower, 'عنوان') !== false || 
            strpos($headerLower, 'address') !== false
        )) {
            $addressIndex = $index;
        }
        if ($balanceIndex === -1 && (
            in_array($headerLower, $balanceVariations, true) || 
            strpos($headerLower, 'رصيد') !== false || 
            strpos($headerLower, 'balance') !== false ||
            strpos($headerLower, 'debt') !== false
        )) {
            $balanceIndex = $index;
        }
        if ($regionIndex === -1 && (
            in_array($headerLower, $regionVariations, true) || 
            strpos($headerLower, 'منطقة') !== false || 
            strpos($headerLower, 'region') !== false
        )) {
            $regionIndex = $index;
        }
    }
    
    // التحقق من وجود عمود الاسم (مطلوب)
    if ($nameIndex === -1) {
        echo json_encode([
            'success' => false,
            'message' => 'لم يتم العثور على عمود "اسم العميل" في الملف. يرجى التأكد من وجود هذا العمود في الصف الأول. الأعمدة المدعومة: اسم العميل، رقم الهاتف، العنوان، الرصيد، المنطقة'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // معالجة البيانات
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    // التحقق من وجود أعمدة في جدول local_customers
    $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'latitude'"));
    $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'longitude'"));
    $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'location_captured_at'"));
    $hasRegionIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'region_id'"));
    
    // بدء المعاملة
    $db->beginTransaction();
    
    try {
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            // تخطي الصفوف الفارغة
            if (empty($row[$nameIndex]) || trim($row[$nameIndex]) === '') {
                continue;
            }
            
            $name = trim($row[$nameIndex]);
            $phone = ($phoneIndex !== -1 && isset($row[$phoneIndex])) ? trim($row[$phoneIndex]) : null;
            $phone2 = ($phone2Index !== -1 && isset($row[$phone2Index])) ? trim($row[$phone2Index]) : null;
            $address = ($addressIndex !== -1 && isset($row[$addressIndex])) ? trim($row[$addressIndex]) : null;
            $balance = ($balanceIndex !== -1 && isset($row[$balanceIndex])) ? trim($row[$balanceIndex]) : '0';
            $regionName = ($regionIndex !== -1 && isset($row[$regionIndex])) ? trim($row[$regionIndex]) : null;
            
            // تنظيف البيانات
            $phone = $phone !== null && $phone !== '' ? $phone : null;
            $phone2 = $phone2 !== null && $phone2 !== '' ? $phone2 : null;
            $address = $address !== null && $address !== '' ? $address : null;
            $balance = is_numeric($balance) ? (float)$balance : 0.0;
            
            // البحث عن region_id إذا كان اسم المنطقة موجوداً
            $regionId = null;
            if ($hasRegionIdColumn && $regionName && $regionName !== '') {
                $normalizedRegionName = trim($regionName);
                if ($normalizedRegionName !== '') {
                    static $regionsCache = [];
                    
                    if (isset($regionsCache[$normalizedRegionName])) {
                        $regionId = $regionsCache[$normalizedRegionName];
                    } else {
                        // محاولة العثور على المنطقة أولاً
                        $region = $db->queryOne("SELECT id FROM regions WHERE name = ?", [$normalizedRegionName]);
                        
                        if ($region) {
                            $regionId = (int)$region['id'];
                            $regionsCache[$normalizedRegionName] = $regionId;
                        } else {
                            // إذا لم توجد، نقوم بإنشائها ثم نستخدمها
                            try {
                                $db->execute(
                                    "INSERT INTO regions (name) VALUES (?)",
                                    [$normalizedRegionName]
                                );
                                $regionId = (int)$db->getLastInsertId();
                                $regionsCache[$normalizedRegionName] = $regionId;
                            } catch (Exception $regionInsertError) {
                                // في حالة خطأ (مثلاً تكرار الاسم)، نحاول جلبها مرة أخرى
                                error_log('Error inserting region in import_local_customers API: ' . $regionInsertError->getMessage());
                                $region = $db->queryOne("SELECT id FROM regions WHERE name = ?", [$normalizedRegionName]);
                                if ($region) {
                                    $regionId = (int)$region['id'];
                                    $regionsCache[$normalizedRegionName] = $regionId;
                                }
                            }
                        }
                    }
                }
            }
            
            // التحقق من التكرار (بناءً على الاسم فقط أو الاسم + الهاتف)
            $duplicateCheck = "SELECT id FROM local_customers WHERE name = ?";
            $duplicateParams = [$name];
            
            if ($phone) {
                $duplicateCheck .= " AND (phone = ? OR phone IS NULL)";
                $duplicateParams[] = $phone;
            }
            
            $existing = $db->queryOne($duplicateCheck, $duplicateParams);
            
            if ($existing) {
                $skipped++;
                continue;
            }
            
            // توليد unique_code فريد للعميل
            require_once __DIR__ . '/../includes/customer_code_generator.php';
            ensureCustomerUniqueCodeColumn('local_customers');
            $uniqueCode = generateUniqueCustomerCode('local_customers');
            
            // إعداد البيانات للإدراج
            $customerColumns = ['unique_code', 'name', 'phone', 'balance', 'address', 'status', 'created_by'];
            $customerValues = [
                $uniqueCode,
                $name,
                $phone,
                $balance,
                $address,
                'active',
                $currentUser['id']
            ];
            $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?'];
            
            // إضافة region_id إذا كان موجوداً
            if ($hasRegionIdColumn && $regionId !== null) {
                $customerColumns[] = 'region_id';
                $customerValues[] = $regionId;
                $customerPlaceholders[] = '?';
            }
            
            // إدراج العميل
            try {
                $db->execute(
                    "INSERT INTO local_customers (" . implode(', ', $customerColumns) . ") 
                     VALUES (" . implode(', ', $customerPlaceholders) . ")",
                    $customerValues
                );
                
                $customerId = $db->getLastInsertId();
                
                // حفظ أرقام الهواتف في جدول local_customer_phones (الهاتف الأول والثاني)
                try {
                    $phonesToSave = [];
                    if ($phone !== null && $phone !== '') {
                        $phonesToSave[] = ['phone' => $phone, 'is_primary' => 1];
                    }
                    if ($phone2 !== null && $phone2 !== '') {
                        $phonesToSave[] = ['phone' => $phone2, 'is_primary' => 0];
                    }
                    
                    if (!empty($phonesToSave) && $customerId) {
                        foreach ($phonesToSave as $phoneData) {
                            $db->execute(
                                "INSERT INTO local_customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                                [$customerId, $phoneData['phone'], $phoneData['is_primary'] ? 1 : 0]
                            );
                        }
                    }
                } catch (Exception $phoneError) {
                    // في حال فشل حفظ أرقام الهواتف، لا نوقف الاستيراد بالكامل
                    error_log('Error inserting local_customer_phones for customer ' . $customerId . ': ' . $phoneError->getMessage());
                }
                
                // تسجيل في سجل التدقيق
                logAudit($currentUser['id'], 'import_local_customer', 'local_customer', $customerId, null, [
                    'name' => $name,
                    'source' => 'csv_import'
                ]);
                
                $imported++;
            } catch (Exception $insertError) {
                $errors[] = "سطر " . ($i + 1) . ": " . $insertError->getMessage();
            }
        }
        
        // تأكيد المعاملة
        $db->commit();
        
        // مسح الكاش
        if (class_exists('Cache')) {
            Cache::flush();
        }
        
        echo json_encode([
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => "تم استيراد {$imported} عميل محلي بنجاح" . ($skipped > 0 ? " وتم تخطي {$skipped} عميل (مكرر)" : "")
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('Import local customers error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // التأكد من عدم وجود output قبل JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء استيراد البيانات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('Import local customers fatal error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // التأكد من عدم وجود output قبل JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ فادح أثناء استيراد البيانات. يرجى التحقق من ملف error_log'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

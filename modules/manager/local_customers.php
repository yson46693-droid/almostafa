<?php
/**
 * صفحة إدارة العملاء المحليين للمدير والمحاسب
 * منفصلة تماماً عن عملاء المندوبين
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// ملاحظة: Headers يتم إرسالها من dashboard/accountant.php أو dashboard/manager.php
// لا حاجة لإرسالها هنا لتجنب مشاكل headers already sent

if (!defined('LOCAL_CUSTOMERS_MODULE_BOOTSTRAPPED')) {
    define('LOCAL_CUSTOMERS_MODULE_BOOTSTRAPPED', true);

    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/audit_log.php';
    require_once __DIR__ . '/../../includes/path_helper.php';

    requireRole(['accountant', 'manager', 'developer']);
}

if (!defined('LOCAL_CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
    require_once __DIR__ . '/../../includes/table_styles.php';
}

$currentUser = getCurrentUser();
$db = db();

// إنشاء جدول local_customer_phones إذا لم يكن موجوداً
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
        error_log('Table local_customer_phones created successfully');
    }
} catch (Exception $e) {
    error_log('Error creating local_customer_phones table: ' . $e->getMessage());
}

// التأكد من وجود الجداول
try {
    // إنشاء جدول regions إذا لم يكن موجوداً
    $regionsTable = $db->queryOne("SHOW TABLES LIKE 'regions'");
    if (empty($regionsTable)) {
        $createRegionsTableSql = "CREATE TABLE IF NOT EXISTS `regions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المناطق'";
        
        try {
            $db->rawQuery($createRegionsTableSql);
            error_log('Table regions created successfully');
        } catch (Throwable $e) {
            error_log('Error creating regions table: ' . $e->getMessage());
        }
    }
    
    // إضافة حقل region_id إلى جدول local_customers إذا لم يكن موجوداً
    $regionIdColumn = $db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'region_id'");
    if (empty($regionIdColumn)) {
        try {
            $db->rawQuery("ALTER TABLE `local_customers` ADD COLUMN `region_id` int(11) DEFAULT NULL AFTER `address`, ADD KEY `region_id` (`region_id`)");
            // إضافة foreign key constraint
            $fkCheck = $db->queryOne("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'local_customers' AND CONSTRAINT_NAME = 'local_customers_ibfk_region'");
            if (empty($fkCheck)) {
                $db->rawQuery("ALTER TABLE `local_customers` ADD CONSTRAINT `local_customers_ibfk_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL");
            }
            error_log('Column region_id added to local_customers');
        } catch (Throwable $e) {
            error_log('Error adding region_id column to local_customers: ' . $e->getMessage());
        }
    }
    
    // إضافة حقل region_id إلى جدول customers إذا لم يكن موجوداً
    $customersRegionIdColumn = $db->queryOne("SHOW COLUMNS FROM customers LIKE 'region_id'");
    if (empty($customersRegionIdColumn)) {
        try {
            $db->rawQuery("ALTER TABLE `customers` ADD COLUMN `region_id` int(11) DEFAULT NULL AFTER `address`, ADD KEY `region_id` (`region_id`)");
            // إضافة foreign key constraint
            $fkCheck = $db->queryOne("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND CONSTRAINT_NAME = 'customers_ibfk_region'");
            if (empty($fkCheck)) {
                $db->rawQuery("ALTER TABLE `customers` ADD CONSTRAINT `customers_ibfk_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL");
            }
            error_log('Column region_id added to customers');
        } catch (Throwable $e) {
            error_log('Error adding region_id column to customers: ' . $e->getMessage());
        }
    }
    
    // إنشاء جدول local_customers إذا لم يكن موجوداً
    $localCustomersTable = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (empty($localCustomersTable)) {
        // إنشاء جدول local_customers مباشرة
        $createTableSql = "CREATE TABLE IF NOT EXISTS `local_customers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `balance` decimal(15,2) DEFAULT 0.00 COMMENT 'رصيد العميل (موجب = دين، سالب = رصيد دائن)',
            `status` enum('active','inactive') DEFAULT 'active',
            `created_by` int(11) NOT NULL COMMENT 'المستخدم الذي أضاف العميل',
            `latitude` decimal(10,8) DEFAULT NULL COMMENT 'خط العرض',
            `longitude` decimal(11,8) DEFAULT NULL COMMENT 'خط الطول',
            `location_captured_at` datetime DEFAULT NULL COMMENT 'تاريخ تحديد الموقع',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `created_by` (`created_by`),
            KEY `name` (`name`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول العملاء المحليين (منفصل عن عملاء المندوبين)'";
        
        try {
            // استخدام rawQuery للاستعلامات DDL
            $result = $db->rawQuery($createTableSql);
            
            if ($result === false) {
                $errorMsg = $db->getLastError() ?: 'Unknown error';
                throw new Exception('Table creation query failed: ' . $errorMsg);
            }
            
            error_log('Table local_customers creation query executed');
            
            // التحقق من أن الجدول تم إنشاؤه فعلياً
            $verifyTable = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
            if (empty($verifyTable)) {
                // محاولة مرة أخرى بعد انتظار قصير
                usleep(100000); // 0.1 ثانية
                $verifyTable = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
                if (empty($verifyTable)) {
                    throw new Exception('Table creation failed - table not found after creation. Database error: ' . ($db->getLastError() ?: 'Unknown'));
                }
            }
            
            error_log('Table local_customers verified successfully');
            
            // محاولة إضافة foreign key constraint إذا كان جدول users موجوداً
            try {
                $usersTableExists = $db->queryOne("SHOW TABLES LIKE 'users'");
                if (!empty($usersTableExists)) {
                    // التحقق من وجود constraint مسبقاً
                    try {
                        $fkCheck = $db->queryOne("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'local_customers' AND CONSTRAINT_NAME = 'local_customers_ibfk_1'");
                        if (empty($fkCheck)) {
                            $fkResult = $db->rawQuery("ALTER TABLE `local_customers` ADD CONSTRAINT `local_customers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE");
                            if ($fkResult) {
                                error_log('Foreign key constraint added to local_customers');
                            } else {
                                error_log('Failed to add foreign key constraint: ' . ($db->getLastError() ?: 'Unknown error'));
                            }
                        }
                    } catch (Throwable $fkError) {
                        error_log('Could not add foreign key constraint: ' . $fkError->getMessage());
                        // لا نوقف العملية، الجدول موجود بدون constraint
                    }
                }
            } catch (Throwable $fkError) {
                error_log('Error checking users table for FK: ' . $fkError->getMessage());
                // لا نوقف العملية
            }
        } catch (Throwable $e) {
            error_log('Error creating local_customers table: ' . $e->getMessage());
            error_log('SQL Error: ' . ($db->getLastError() ?? 'No error message'));
            error_log('SQL Error Number: ' . $db->getLastErrno());
            // إظهار رسالة خطأ واضحة للمستخدم
            die('<div class="alert alert-danger">
                <h5>خطأ في إنشاء جدول العملاء المحليين</h5>
                <p>يرجى التحقق من:</p>
                <ul>
                    <li>صلاحيات قاعدة البيانات (CREATE TABLE)</li>
                    <li>اتصال قاعدة البيانات</li>
                    <li>سجلات الأخطاء في الخادم</li>
                </ul>
                <p><strong>تفاصيل الخطأ:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
                <p><strong>خطأ قاعدة البيانات:</strong> ' . htmlspecialchars($connection->error ?? 'غير متاح') . '</p>
            </div>');
        }
    }
    
    // التحقق النهائي من وجود الجدول قبل المتابعة
    $finalCheck = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (empty($finalCheck)) {
        error_log('CRITICAL: local_customers table does not exist after creation attempt');
        die('<div class="alert alert-danger">خطأ: جدول العملاء المحليين غير موجود. يرجى التحقق من قاعدة البيانات أو الاتصال بالدعم الفني.</div>');
    }
    
    // إنشاء جدول local_collections إذا لم يكن موجوداً
    $localCollectionsTable = $db->queryOne("SHOW TABLES LIKE 'local_collections'");
    if (empty($localCollectionsTable)) {
        $createCollectionsTableSql = "CREATE TABLE IF NOT EXISTS `local_collections` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `collection_number` varchar(50) DEFAULT NULL COMMENT 'رقم التحصيل',
            `customer_id` int(11) NOT NULL COMMENT 'معرف العميل المحلي',
            `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ المحصل',
            `date` date NOT NULL COMMENT 'تاريخ التحصيل',
            `payment_method` enum('cash','bank','cheque','other') DEFAULT 'cash' COMMENT 'طريقة الدفع',
            `reference_number` varchar(50) DEFAULT NULL COMMENT 'رقم مرجعي',
            `notes` text DEFAULT NULL COMMENT 'ملاحظات',
            `collected_by` int(11) NOT NULL COMMENT 'من قام بالتحصيل',
            `status` enum('pending','approved','rejected') DEFAULT 'pending' COMMENT 'حالة التحصيل',
            `approved_by` int(11) DEFAULT NULL COMMENT 'من وافق على التحصيل',
            `approved_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `collection_number` (`collection_number`),
            KEY `customer_id` (`customer_id`),
            KEY `collected_by` (`collected_by`),
            KEY `approved_by` (`approved_by`),
            KEY `date` (`date`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول التحصيلات للعملاء المحليين'";
        
        try {
            $db->rawQuery($createCollectionsTableSql);
            error_log('Table local_collections created successfully');
        } catch (Throwable $e) {
            error_log('Error creating local_collections table: ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    error_log('Error checking local_customers tables: ' . $e->getMessage());
}

// معالجة get_local_customer_phones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && trim($_GET['action']) === 'get_local_customer_phones') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    
    if ($customerId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'معرف العميل غير صحيح'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $phones = $db->query(
            "SELECT phone FROM local_customer_phones WHERE customer_id = ? ORDER BY is_primary DESC, id ASC",
            [$customerId]
        );
        
        $phoneNumbers = array_map(function($row) {
            return $row['phone'];
        }, $phones);
        
        echo json_encode([
            'success' => true,
            'phones' => $phoneNumbers
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('Get local customer phones error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب أرقام الهواتف'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// معالجة add_region_ajax قبل أي شيء آخر لمنع أي output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'add_region_ajax') {
    // تنظيف أي output سابق بشكل كامل
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $userRole = strtolower($currentUser['role'] ?? '');
    if ($userRole !== 'manager') {
        echo json_encode([
            'success' => false,
            'message' => 'غير مصرح لك بإضافة مناطق'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $name = trim($_POST['name'] ?? '');
    if (empty($name)) {
        echo json_encode([
            'success' => false,
            'message' => 'يجب إدخال اسم المنطقة'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        // التحقق من عدم التكرار
        $existing = $db->queryOne("SELECT id FROM regions WHERE name = ?", [$name]);
        if ($existing) {
            echo json_encode([
                'success' => false,
                'message' => 'المنطقة موجودة بالفعل'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $db->execute("INSERT INTO regions (name) VALUES (?)", [$name]);
        $regionId = $db->getLastInsertId();
        
        logAudit($currentUser['id'], 'add_region', 'region', $regionId, null, ['name' => $name]);
        
        echo json_encode([
            'success' => true,
            'message' => 'تم إضافة المنطقة بنجاح',
            'region' => [
                'id' => $regionId,
                'name' => $name
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('Add region AJAX error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء إضافة المنطقة'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// معالجة update_location قبل أي شيء آخر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'update_location') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $isAjaxRequest = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
    
    if (!$isAjaxRequest) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'طلب غير صالح.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;

    if ($customerId <= 0 || $latitude === null || $longitude === null) {
        echo json_encode([
            'success' => false,
            'message' => 'بيانات الموقع غير مكتملة.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        echo json_encode([
            'success' => false,
            'message' => 'إحداثيات الموقع غير صالحة.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $latitude = (float)$latitude;
    $longitude = (float)$longitude;

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        echo json_encode([
            'success' => false,
            'message' => 'نطاق الإحداثيات غير صحيح.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $customer = $db->queryOne("SELECT id FROM local_customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            throw new InvalidArgumentException('العميل المطلوب غير موجود.');
        }

        $db->execute(
            "UPDATE local_customers SET latitude = ?, longitude = ?, location_captured_at = NOW() WHERE id = ?",
            [$latitude, $longitude, $customerId]
        );

        logAudit(
            $currentUser['id'],
            'update_local_customer_location',
            'local_customer',
            $customerId,
            null,
            [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]
        );

        echo json_encode([
            'success' => true,
            'message' => 'تم تحديث موقع العميل بنجاح.',
        ], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $invalidLocation) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $invalidLocation->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $updateLocationError) {
        error_log('Update local customer location error: ' . $updateLocationError->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء حفظ الموقع. حاول مرة أخرى.',
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

// معالجة حذف العميل المحلي قبل أي شيء آخر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'delete_local_customer') {
    // التحقق من الصلاحيات - فقط المدير يمكنه حذف العملاء المحليين
    $userRole = strtolower($currentUser['role'] ?? '');
    if ($userRole !== 'manager') {
        $_SESSION['error_message'] = 'غير مصرح لك بحذف العملاء المحليين. هذه الصلاحية متاحة للمدير فقط.';
        redirectAfterPost('local_customers', [], [], $userRole);
        exit;
    }
    
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    
    if ($customerId <= 0) {
        $_SESSION['error_message'] = 'معرف العميل غير صحيح.';
        redirectAfterPost('local_customers', [], [], $userRole);
        exit;
    }
    
    try {
        // التحقق من وجود العميل
        $customer = $db->queryOne("SELECT id, name FROM local_customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            $_SESSION['error_message'] = 'العميل غير موجود.';
            redirectAfterPost('local_customers', [], [], $userRole);
            exit;
        }
        
        $customerName = $customer['name'] ?? 'غير معروف';
        
        // بدء المعاملة
        $db->beginTransaction();
        
        try {
            // 1. حذف local_return_items المرتبطة بـ local_returns الخاصة بهذا العميل
            $returnsIds = $db->query("SELECT id FROM local_returns WHERE customer_id = ?", [$customerId]);
            if (!empty($returnsIds)) {
                $returnIdsArray = array_column($returnsIds, 'id');
                if (!empty($returnIdsArray)) {
                    $placeholders = implode(',', array_fill(0, count($returnIdsArray), '?'));
                    $db->execute("DELETE FROM local_return_items WHERE return_id IN ($placeholders)", $returnIdsArray);
                }
            }
            
            // 2. حذف local_returns الخاصة بالعميل
            $db->execute("DELETE FROM local_returns WHERE customer_id = ?", [$customerId]);
            
            // 3. حذف local_invoice_items المرتبطة بـ local_invoices الخاصة بهذا العميل
            $invoicesIds = $db->query("SELECT id FROM local_invoices WHERE customer_id = ?", [$customerId]);
            if (!empty($invoicesIds)) {
                $invoiceIdsArray = array_column($invoicesIds, 'id');
                if (!empty($invoiceIdsArray)) {
                    $placeholders = implode(',', array_fill(0, count($invoiceIdsArray), '?'));
                    $db->execute("DELETE FROM local_invoice_items WHERE invoice_id IN ($placeholders)", $invoiceIdsArray);
                }
            }
            
            // 4. حذف local_invoices الخاصة بالعميل
            $db->execute("DELETE FROM local_invoices WHERE customer_id = ?", [$customerId]);
            
            // 5. حذف local_collections الخاصة بالعميل
            $db->execute("DELETE FROM local_collections WHERE customer_id = ?", [$customerId]);
            
            // 6. حذف local_customer_phones الخاصة بالعميل
            $db->execute("DELETE FROM local_customer_phones WHERE customer_id = ?", [$customerId]);
            
            // 7. حذف العميل نفسه
            $db->execute("DELETE FROM local_customers WHERE id = ?", [$customerId]);
            
            // تسجيل العملية في audit log
            logAudit(
                $currentUser['id'],
                'delete_local_customer',
                'local_customer',
                $customerId,
                json_encode([
                    'customer_name' => $customerName,
                    'deleted_tables' => [
                        'local_returns',
                        'local_return_items',
                        'local_invoices',
                        'local_invoice_items',
                        'local_collections',
                        'local_customer_phones'
                    ]
                ]),
                null
            );
            
            // تأكيد المعاملة
            $db->commit();
            
            $_SESSION['success_message'] = 'تم حذف العميل المحلي "' . htmlspecialchars($customerName) . '" وجميع السجلات المرتبطة به بنجاح.';
            
        } catch (Throwable $deleteError) {
            // إلغاء المعاملة في حالة الخطأ
            $db->rollback();
            error_log('Delete local customer error: ' . $deleteError->getMessage());
            error_log('Stack trace: ' . $deleteError->getTraceAsString());
            throw $deleteError;
        }
        
    } catch (Throwable $e) {
        error_log('Delete local customer transaction error: ' . $e->getMessage());
        $_SESSION['error_message'] = 'حدث خطأ أثناء حذف العميل المحلي: ' . $e->getMessage();
    }
    
    redirectAfterPost('local_customers', [], [], $userRole);
    exit;
}

$error = '';
$success = '';

// قراءة الرسائل من session
applyPRGPattern($error, $success);

$customerStats = [
    'total_count' => 0,
    'debtor_count' => 0,
    'total_debt' => 0.0,
];
$totalCollectionsAmount = 0.0;

// تحديد المسار الأساسي للروابط
$currentRole = strtolower((string)($currentUser['role'] ?? 'manager'));
$localCustomersBaseScript = 'manager.php';
if ($currentRole === 'accountant') {
    $localCustomersBaseScript = 'accountant.php';
}
$localCustomersPageBase = $localCustomersBaseScript . '?page=local_customers';

// معالجة POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'collect_debt') {
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $amount = isset($_POST['amount']) ? cleanFinancialValue($_POST['amount']) : 0;

        if ($customerId <= 0) {
            $error = 'معرف العميل غير صالح.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } else {
            $transactionStarted = false;

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                $customer = $db->queryOne(
                    "SELECT id, name, balance FROM local_customers WHERE id = ? FOR UPDATE",
                    [$customerId]
                );

                if (!$customer) {
                    throw new InvalidArgumentException('لم يتم العثور على العميل المطلوب.');
                }

                $currentBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;

                if ($currentBalance <= 0) {
                    throw new InvalidArgumentException('لا توجد ديون نشطة على هذا العميل.');
                }

                if ($amount > $currentBalance) {
                    throw new InvalidArgumentException('المبلغ المدخل أكبر من ديون العميل الحالية.');
                }

                $newBalance = round(max($currentBalance - $amount, 0), 2);

                $db->execute(
                    "UPDATE local_customers SET balance = ? WHERE id = ?",
                    [$newBalance, $customerId]
                );

                logAudit(
                    $currentUser['id'],
                    'collect_local_customer_debt',
                    'local_customer',
                    $customerId,
                    null,
                    [
                        'collected_amount'   => $amount,
                        'previous_balance'   => $currentBalance,
                        'new_balance'        => $newBalance,
                    ]
                );

                $collectionNumber = null;
                $collectionId = null;

                // حفظ التحصيل في جدول local_collections
                $localCollectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_collections'");
                if (!empty($localCollectionsTableExists)) {
                    $hasStatusColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_collections LIKE 'status'"));
                    $hasCollectionNumberColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_collections LIKE 'collection_number'"));
                    $hasNotesColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_collections LIKE 'notes'"));

                    // توليد رقم التحصيل
                    if ($hasCollectionNumberColumn) {
                        $year = date('Y');
                        $month = date('m');
                        $lastCollection = $db->queryOne(
                            "SELECT collection_number FROM local_collections WHERE collection_number LIKE ? ORDER BY collection_number DESC LIMIT 1 FOR UPDATE",
                            ["LOC-COL-{$year}{$month}-%"]
                        );

                        $serial = 1;
                        if (!empty($lastCollection['collection_number'])) {
                            $parts = explode('-', $lastCollection['collection_number']);
                            $serial = intval($parts[3] ?? 0) + 1;
                        }

                        $collectionNumber = sprintf("LOC-COL-%s%s-%04d", $year, $month, $serial);
                    }

                    $collectionDate = date('Y-m-d');
                    $collectionColumns = ['customer_id', 'amount', 'date', 'payment_method', 'collected_by'];
                    $collectionValues = [$customerId, $amount, $collectionDate, 'cash', $currentUser['id']];
                    $collectionPlaceholders = array_fill(0, count($collectionColumns), '?');

                    if ($hasCollectionNumberColumn && $collectionNumber !== null) {
                        array_unshift($collectionColumns, 'collection_number');
                        array_unshift($collectionValues, $collectionNumber);
                        array_unshift($collectionPlaceholders, '?');
                    }

                    if ($hasNotesColumn) {
                        $collectionColumns[] = 'notes';
                        $collectionValues[] = 'تحصيل من صفحة العملاء المحليين';
                        $collectionPlaceholders[] = '?';
                    }

                    if ($hasStatusColumn) {
                        $collectionColumns[] = 'status';
                        // التحصيلات من هذه الصفحة تُضاف كإيراد معتمد مباشرة في خزنة الشركة
                        // لذلك يجب أن تكون معتمدة مباشرة
                        $collectionValues[] = 'approved';
                        $collectionPlaceholders[] = '?';
                    }

                    $db->execute(
                        "INSERT INTO local_collections (" . implode(', ', $collectionColumns) . ") VALUES (" . implode(', ', $collectionPlaceholders) . ")",
                        $collectionValues
                    );

                    $collectionId = $db->getLastInsertId();

                    logAudit(
                        $currentUser['id'],
                        'add_local_collection_from_customers_page',
                        'local_collection',
                        $collectionId,
                        null,
                        [
                            'collection_number' => $collectionNumber,
                            'customer_id' => $customerId,
                            'amount' => $amount,
                        ]
                    );
                }

                // توزيع التحصيل على الفواتير المحلية (إن وجدت)
                $localInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
                if (!empty($localInvoicesTableExists)) {
                    try {
                        require_once __DIR__ . '/../../includes/local_invoices_helper.php';
                        if (function_exists('distributeLocalCollectionToInvoices')) {
                            distributeLocalCollectionToInvoices($customerId, $amount, $currentUser['id']);
                        }
                    } catch (Throwable $e) {
                        error_log('Error distributing local collection to invoices: ' . $e->getMessage());
                    }
                }

                // إضافة إيراد معتمد في خزنة الشركة (accountant_transactions)
                try {
                    // التأكد من وجود جدول accountant_transactions
                    $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                    if (!empty($accountantTableExists)) {
                        // التحقق من وجود عمود local_customer_id وإضافته إذا لم يكن موجوداً
                        $hasLocalCustomerIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM accountant_transactions LIKE 'local_customer_id'"));
                        
                        // التحقق من وجود عمود local_collection_id وإضافته إذا لم يكن موجوداً
                        $hasLocalCollectionIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM accountant_transactions LIKE 'local_collection_id'"));
                        
                        // إعداد الوصف
                        $customerName = $customer['name'] ?? 'عميل محلي';
                        $description = 'تحصيل من عميل محلي: ' . $customerName;
                        
                        // إعداد رقم مرجعي
                        $referenceNumber = $collectionNumber ?? ('LOC-CUST-' . $customerId . '-' . date('YmdHis'));
                        
                        // إعداد الأعمدة والقيم
                        $transactionColumns = [
                            'transaction_type',
                            'amount',
                            'description',
                            'reference_number',
                            'payment_method',
                            'status',
                            'created_by',
                            'approved_by',
                            'approved_at'
                        ];
                        
                        $transactionValues = [
                            'income',
                            $amount,
                            $description,
                            $referenceNumber,
                            'cash',
                            'approved',
                            $currentUser['id'],
                            $currentUser['id'],
                            date('Y-m-d H:i:s')
                        ];
                        
                        // إضافة local_customer_id إذا كان العمود موجوداً
                        if ($hasLocalCustomerIdColumn) {
                            $transactionColumns[] = 'local_customer_id';
                            $transactionValues[] = $customerId;
                        }
                        
                        // إضافة local_collection_id إذا كان العمود موجوداً وكان هناك collection_id
                        if ($hasLocalCollectionIdColumn && $collectionId !== null) {
                            $transactionColumns[] = 'local_collection_id';
                            $transactionValues[] = $collectionId;
                        }
                        
                        $transactionPlaceholders = array_fill(0, count($transactionColumns), '?');
                        
                        // إدراج السجل في accountant_transactions
                        $db->execute(
                            "INSERT INTO accountant_transactions (" . implode(', ', $transactionColumns) . ") 
                             VALUES (" . implode(', ', $transactionPlaceholders) . ")",
                            $transactionValues
                        );
                        
                        $transactionId = $db->getLastInsertId();
                        
                        logAudit(
                            $currentUser['id'],
                            'add_income_from_local_customer_collection',
                            'accountant_transaction',
                            $transactionId,
                            null,
                            [
                                'local_customer_id' => $customerId,
                                'amount' => $amount,
                                'collection_id' => $collectionId,
                                'reference_number' => $referenceNumber,
                            ]
                        );
                    }
                } catch (Throwable $incomeError) {
                    error_log('Error adding income from local customer collection: ' . $incomeError->getMessage());
                    // لا نوقف العملية في حالة فشل إضافة الإيراد، فقط نسجل الخطأ
                }

                $db->commit();
                $transactionStarted = false;

                $messageParts = ['تم تحصيل المبلغ بنجاح.'];
                if ($collectionNumber !== null) {
                    $messageParts[] = 'رقم التحصيل: ' . $collectionNumber . '.';
                }

                $_SESSION['success_message'] = implode(' ', array_filter($messageParts));

                redirectAfterPost(
                    'local_customers',
                    [],
                    [],
                    $currentRole
                );
            } catch (InvalidArgumentException $userError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                $error = $userError->getMessage();
            } catch (Throwable $collectionError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                error_log('Local customer collection error: ' . $collectionError->getMessage());
                $error = 'حدث خطأ أثناء تحصيل المبلغ. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'edit_customer') {
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $regionId = isset($_POST['region_id']) && $_POST['region_id'] !== '' ? (int)$_POST['region_id'] : null;
        
        if ($customerId <= 0) {
            $error = 'معرف العميل غير صحيح';
        } else {
            try {
                // التحقق من وجود العميل
                $customer = $db->queryOne("SELECT id, name FROM local_customers WHERE id = ?", [$customerId]);
                if (!$customer) {
                    $error = 'العميل غير موجود';
                } else {
                    // التحقق من الصلاحيات
                    $canEdit = false;
                    $allowedFields = [];
                    
                    if (in_array($currentRole, ['manager', 'developer'], true)) {
                        // المدير والمطور يعدلون جميع البيانات ما عدا اسم العميل
                        $canEdit = true;
                        $allowedFields = ['phone', 'address', 'region_id', 'balance'];
                    } elseif (in_array($currentRole, ['accountant', 'sales'], true)) {
                        // المحاسب والمندوب يعدلون فقط (العنوان – الهاتف – المنطقة)
                        $canEdit = true;
                        $allowedFields = ['phone', 'address', 'region_id'];
                    }
                    
                    if (!$canEdit) {
                        $error = 'غير مصرح لك بتعديل هذا العميل';
                    } else {
                        $updateFields = [];
                        $updateValues = [];
                        
                        if (in_array('phone', $allowedFields)) {
                            $updateFields[] = 'phone = ?';
                            $updateValues[] = $phone ?: null;
                            
                            // تحديث أرقام الهواتف في جدول local_customer_phones
                            $phones = $_POST['phones'] ?? [];
                            if (is_array($phones) && !empty($phones)) {
                                // حذف الأرقام القديمة
                                $db->execute("DELETE FROM local_customer_phones WHERE customer_id = ?", [$customerId]);
                                
                                // إضافة الأرقام الجديدة
                                $firstPhone = true;
                                foreach ($phones as $phoneNumber) {
                                    $phoneNumber = trim($phoneNumber);
                                    if (!empty($phoneNumber)) {
                                        $db->execute(
                                            "INSERT INTO local_customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                                            [$customerId, $phoneNumber, $firstPhone ? 1 : 0]
                                        );
                                        $firstPhone = false;
                                    }
                                }
                            } elseif (!empty($phone)) {
                                // إذا لم تكن هناك أرقام متعددة، احفظ الرقم الواحد
                                $db->execute("DELETE FROM local_customer_phones WHERE customer_id = ?", [$customerId]);
                                $db->execute(
                                    "INSERT INTO local_customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                                    [$customerId, $phone, 1]
                                );
                            }
                        }
                        
                        if (in_array('address', $allowedFields)) {
                            $updateFields[] = 'address = ?';
                            $updateValues[] = $address ?: null;
                        }
                        
                        if (in_array('region_id', $allowedFields)) {
                            $updateFields[] = 'region_id = ?';
                            $updateValues[] = $regionId;
                        }
                        
                        if (in_array('balance', $allowedFields)) {
                            $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : null;
                            if ($balance !== null) {
                                $updateFields[] = 'balance = ?';
                                $updateValues[] = $balance;
                            }
                        }
                        
                        if (!empty($updateFields)) {
                            $updateValues[] = $customerId;
                            $db->execute(
                                "UPDATE local_customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                                $updateValues
                            );
                            
                            logAudit($currentUser['id'], 'edit_local_customer', 'local_customer', $customerId, null, [
                                'name' => $customer['name'],
                                'updated_fields' => $allowedFields
                            ]);
                            
                            $_SESSION['success_message'] = 'تم تعديل بيانات العميل بنجاح';
                            
                            redirectAfterPost(
                                'local_customers',
                                [],
                                [],
                                $currentRole
                            );
                        } else {
                            $error = 'لم يتم تحديد أي حقول للتعديل';
                        }
                    }
                }
            } catch (Throwable $editError) {
                error_log('Edit local customer error: ' . $editError->getMessage());
                $error = 'حدث خطأ أثناء تعديل بيانات العميل';
            }
        }
    } elseif ($action === 'add_customer') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : 0;
        $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? trim($_POST['latitude']) : null;
        $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? trim($_POST['longitude']) : null;

        if (empty($name)) {
            $error = 'يجب إدخال اسم العميل';
        } else {
            try {
                // التحقق من عدم تكرار بيانات العميل
                $duplicateCheckConditions = ["name = ?"];
                $duplicateCheckParams = [$name];
                
                if (!empty($phone)) {
                    $duplicateCheckConditions[] = "phone = ?";
                    $duplicateCheckParams[] = $phone;
                }
                
                if (!empty($address)) {
                    $duplicateCheckConditions[] = "address = ?";
                    $duplicateCheckParams[] = $address;
                }
                
                $duplicateQuery = "SELECT id, name, phone, address FROM local_customers WHERE " . implode(" AND ", $duplicateCheckConditions) . " LIMIT 1";
                $duplicateCustomer = $db->queryOne($duplicateQuery, $duplicateCheckParams);
                
                if ($duplicateCustomer) {
                    $duplicateInfo = [];
                    if (!empty($duplicateCustomer['phone'])) {
                        $duplicateInfo[] = "رقم الهاتف: " . $duplicateCustomer['phone'];
                    }
                    if (!empty($duplicateCustomer['address'])) {
                        $duplicateInfo[] = "العنوان: " . $duplicateCustomer['address'];
                    }
                    $duplicateMessage = "يوجد عميل محلي مسجل مسبقاً بنفس البيانات";
                    if (!empty($duplicateInfo)) {
                        $duplicateMessage .= " (" . implode(", ", $duplicateInfo) . ")";
                    }
                    $duplicateMessage .= ". يرجى اختيار العميل الموجود من القائمة أو تعديل البيانات.";
                    throw new InvalidArgumentException($duplicateMessage);
                }

                // التحقق من وجود أعمدة اللوكيشن
                $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'latitude'"));
                $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'longitude'"));
                $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'location_captured_at'"));
                
                $regionId = isset($_POST['region_id']) && $_POST['region_id'] !== '' ? (int)$_POST['region_id'] : null;
                
                // توليد unique_code فريد للعميل
                require_once __DIR__ . '/../../includes/customer_code_generator.php';
                ensureCustomerUniqueCodeColumn('local_customers');
                $uniqueCode = generateUniqueCustomerCode('local_customers');
                
                $customerColumns = ['unique_code', 'name', 'phone', 'balance', 'address', 'status', 'created_by'];
                $customerValues = [
                    $uniqueCode,
                    $name,
                    $phone ?: null,
                    $balance,
                    $address ?: null,
                    'active',
                    $currentUser['id'],
                ];
                $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?'];
                
                // إضافة region_id إذا كان موجوداً
                $hasRegionIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'region_id'"));
                if ($hasRegionIdColumn && $regionId !== null) {
                    $customerColumns[] = 'region_id';
                    $customerValues[] = $regionId;
                    $customerPlaceholders[] = '?';
                }
                
                if ($hasLatitudeColumn && $latitude !== null) {
                    $customerColumns[] = 'latitude';
                    $customerValues[] = (float)$latitude;
                    $customerPlaceholders[] = '?';
                }
                
                if ($hasLongitudeColumn && $longitude !== null) {
                    $customerColumns[] = 'longitude';
                    $customerValues[] = (float)$longitude;
                    $customerPlaceholders[] = '?';
                }
                
                if ($hasLocationCapturedAtColumn && $latitude !== null && $longitude !== null) {
                    $customerColumns[] = 'location_captured_at';
                    $customerValues[] = date('Y-m-d H:i:s');
                    $customerPlaceholders[] = '?';
                }

                $result = $db->execute(
                    "INSERT INTO local_customers (" . implode(', ', $customerColumns) . ") 
                     VALUES (" . implode(', ', $customerPlaceholders) . ")",
                    $customerValues
                );

                // الحصول على ID العميل المدرج
                $customerId = isset($result['insert_id']) && $result['insert_id'] > 0
                    ? (int)$result['insert_id']
                    : (int)$db->getLastInsertId();
                
                // التحقق من أن customerId صحيح
                if ($customerId <= 0) {
                    throw new Exception('فشل الحصول على معرف العميل بعد الإدراج');
                }
                
                // حفظ أرقام الهواتف المتعددة
                $phones = $_POST['phones'] ?? [];
                if (is_array($phones) && !empty($phones)) {
                    $firstPhone = true;
                    foreach ($phones as $phoneNumber) {
                        $phoneNumber = trim($phoneNumber);
                        if (!empty($phoneNumber)) {
                            $db->execute(
                                "INSERT INTO local_customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                                [$customerId, $phoneNumber, $firstPhone ? 1 : 0]
                            );
                            $firstPhone = false;
                        }
                    }
                } elseif (!empty($phone)) {
                    // إذا لم تكن هناك أرقام متعددة، احفظ الرقم الواحد في جدول local_customer_phones
                    $db->execute(
                        "INSERT INTO local_customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                        [$customerId, $phone, 1]
                    );
                }

                logAudit($currentUser['id'], 'add_local_customer', 'local_customer', $customerId, null, [
                    'name' => $name
                ]);

                $_SESSION['success_message'] = 'تم إضافة العميل المحلي بنجاح';

                redirectAfterPost(
                    'local_customers',
                    [],
                    [],
                    $currentRole
                );
            } catch (InvalidArgumentException $userError) {
                $error = $userError->getMessage();
            } catch (Throwable $addCustomerError) {
                error_log('Add local customer error: ' . $addCustomerError->getMessage());
                $error = 'حدث خطأ أثناء إضافة العميل. يرجى المحاولة لاحقاً.';
            }
        }
    }
}

// Pagination - تقليل عدد العناصر على الموبايل لتحسين الأداء
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
// تحديد عدد العناصر حسب نوع الجهاز (10 للموبايل، 20 للديسكتوب)
$isMobile = isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/(android|iphone|ipad|mobile)/i', $_SERVER['HTTP_USER_AGENT']);
$perPage = $isMobile ? 10 : 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = trim($_GET['search'] ?? '');
$debtStatus = $_GET['debt_status'] ?? 'all';
$allowedDebtStatuses = ['all', 'debtor', 'clear'];
if (!in_array($debtStatus, $allowedDebtStatuses, true)) {
    $debtStatus = 'all';
}

// البحث والفلترة
$regionFilter = isset($_GET['region_id']) && $_GET['region_id'] !== '' ? (int)$_GET['region_id'] : null;

// بناء استعلام SQL
$sql = "SELECT c.*, u.full_name as created_by_name, r.name as region_name
        FROM local_customers c
        LEFT JOIN users u ON c.created_by = u.id
        LEFT JOIN regions r ON c.region_id = r.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM local_customers WHERE 1=1";
// استعلام منفصل للإحصائيات العامة (لا يتأثر بالفلاتر)
// إجمالي الديون = إجمالي الرصيد المدين (balance > 0) من جدول local_customers فقط
$summaryStatsSql = "SELECT 
                COUNT(*) AS total_count,
                COUNT(CASE WHEN COALESCE(balance, 0) > 0 THEN 1 END) AS debtor_count,
                COALESCE(SUM(CASE WHEN COALESCE(balance, 0) > 0 THEN COALESCE(balance, 0) ELSE 0 END), 0) AS total_debt
            FROM local_customers";
// استعلام للإحصائيات المفلترة (للعرض في الجدول)
$statsSql = "SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS debtor_count,
                COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt
            FROM local_customers
            WHERE 1=1";
$params = [];
$countParams = [];
$statsParams = [];
$summaryStatsParams = [];

if ($debtStatus === 'debtor') {
    $sql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    $countSql .= " AND (balance IS NOT NULL AND balance > 0)";
    $statsSql .= " AND (balance IS NOT NULL AND balance > 0)";
} elseif ($debtStatus === 'clear') {
    $sql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    $countSql .= " AND (balance IS NULL OR balance <= 0)";
    $statsSql .= " AND (balance IS NULL OR balance <= 0)";
}

if ($search) {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR r.name LIKE ?)";
    $countSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ? OR region_id IN (SELECT id FROM regions WHERE name LIKE ?))";
    $statsSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ? OR region_id IN (SELECT id FROM regions WHERE name LIKE ?))";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $statsParams[] = $searchParam;
    $statsParams[] = $searchParam;
    $statsParams[] = $searchParam;
    $statsParams[] = $searchParam;
}

// فلتر المنطقة
if ($regionFilter !== null) {
    $sql .= " AND c.region_id = ?";
    $countSql .= " AND region_id = ?";
    $statsSql .= " AND region_id = ?";
    $params[] = $regionFilter;
    $countParams[] = $regionFilter;
    $statsParams[] = $regionFilter;
}

// التحقق النهائي من وجود الجدول قبل تنفيذ الاستعلامات
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
if (empty($tableCheck)) {
    error_log('CRITICAL: local_customers table does not exist before query execution');
    die('<div class="alert alert-danger">خطأ: جدول العملاء المحليين غير موجود. يرجى التحقق من قاعدة البيانات أو الاتصال بالدعم الفني.</div>');
}

try {
    $totalResult = $db->queryOne($countSql, $countParams);
    $totalCustomers = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalCustomers / $perPage);
} catch (Exception $e) {
    error_log('Error executing count query: ' . $e->getMessage());
    // محاولة إنشاء الجدول مرة أخرى
    try {
        $createTableSql = "CREATE TABLE IF NOT EXISTS `local_customers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `balance` decimal(15,2) DEFAULT 0.00 COMMENT 'رصيد العميل (موجب = دين، سالب = رصيد دائن)',
            `status` enum('active','inactive') DEFAULT 'active',
            `created_by` int(11) NOT NULL COMMENT 'المستخدم الذي أضاف العميل',
            `latitude` decimal(10,8) DEFAULT NULL COMMENT 'خط العرض',
            `longitude` decimal(11,8) DEFAULT NULL COMMENT 'خط الطول',
            `location_captured_at` datetime DEFAULT NULL COMMENT 'تاريخ تحديد الموقع',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `created_by` (`created_by`),
            KEY `name` (`name`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول العملاء المحليين (منفصل عن عملاء المندوبين)'";
        $db->rawQuery($createTableSql);
        // إعادة المحاولة
        $totalResult = $db->queryOne($countSql, $countParams);
        $totalCustomers = $totalResult['total'] ?? 0;
        $totalPages = ceil($totalCustomers / $perPage);
    } catch (Exception $createError) {
        die('<div class="alert alert-danger">خطأ في إنشاء جدول العملاء المحليين. يرجى التحقق من صلاحيات قاعدة البيانات.<br>تفاصيل الخطأ: ' . htmlspecialchars($createError->getMessage()) . '</div>');
    }
}

$sql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$customers = $db->query($sql, $params);

// تحسين الأداء: جلب جميع أرقام الهواتف في استعلام واحد بدلاً من N+1 queries
$customerPhonesMap = [];
if (!empty($customers)) {
    $customerIds = array_column($customers, 'id');
    if (!empty($customerIds)) {
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $allPhones = $db->query(
            "SELECT customer_id, phone, is_primary 
             FROM local_customer_phones 
             WHERE customer_id IN ($placeholders) 
             ORDER BY customer_id, is_primary DESC, id ASC",
            $customerIds
        );
        
        // تجميع أرقام الهواتف حسب customer_id
        foreach ($allPhones as $phoneRow) {
            $customerId = (int)$phoneRow['customer_id'];
            if (!isset($customerPhonesMap[$customerId])) {
                $customerPhonesMap[$customerId] = [];
            }
            $customerPhonesMap[$customerId][] = $phoneRow['phone'];
        }
    }
}

// جلب الإحصائيات العامة (للمربعات - لا تتأثر بالفلاتر)
// تحسين الأداء: دمج جميع استعلامات الإحصائيات في استعلام واحد
try {
    // استعلام واحد محسّن لحساب جميع الإحصائيات
    $statsResult = $db->queryOne(
        "SELECT 
            COUNT(*) AS total_count,
            COUNT(CASE WHEN balance > 0 THEN 1 END) AS debtor_count,
            COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt
        FROM local_customers"
    );
    
    // تعيين القيم مباشرة
    $customerStats['total_count'] = (int)($statsResult['total_count'] ?? 0);
    $customerStats['debtor_count'] = (int)($statsResult['debtor_count'] ?? 0);
    $customerStats['total_debt'] = (float)($statsResult['total_debt'] ?? 0.0);
} catch (Throwable $statsError) {
    error_log('Error calculating local customers summary stats: ' . $statsError->getMessage());
    // في حالة الخطأ، نستخدم القيم الافتراضية
    $customerStats['total_count'] = 0;
    $customerStats['debtor_count'] = 0;
    $customerStats['total_debt'] = 0.0;
}

try {
    // حساب إجمالي التحصيلات من جميع العملاء المحليين (بغض النظر عن الفلتر)
    $totalCollectionsAmount = 0.0;
    
    // 1. حساب التحصيلات من جدول local_collections
    $localCollectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_collections'");
    if (!empty($localCollectionsTableExists)) {
        $collectionsStatusExists = false;
        $statusCheck = $db->query("SHOW COLUMNS FROM local_collections LIKE 'status'");
        if (!empty($statusCheck)) {
            $collectionsStatusExists = true;
        }

        // حساب إجمالي التحصيلات من جميع العملاء المحليين بدون فلاتر
        // نحسب جميع التحصيلات (pending و approved) لأنها جميعاً من العملاء المحليين
        $collectionsSql = "SELECT COALESCE(SUM(col.amount), 0) AS total_collections
                           FROM local_collections col";
        $collectionsParams = [];

        // حساب جميع التحصيلات (pending و approved) - نستثني المرفوضة فقط
        if ($collectionsStatusExists) {
            $collectionsSql .= " WHERE col.status IN ('pending', 'approved')";
        }

        $collectionsResult = $db->queryOne($collectionsSql, $collectionsParams);
        if (!empty($collectionsResult)) {
            $totalCollectionsAmount += (float)($collectionsResult['total_collections'] ?? 0);
        }
    }
    
    // 2. إضافة المبيعات المدفوعة بالكامل من الفواتير المحلية
    $localInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
    if (!empty($localInvoicesTableExists)) {
        try {
            // التحقق من وجود عمود status في جدول local_invoices
            $hasStatusColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_invoices LIKE 'status'"));
            
            if ($hasStatusColumn) {
                // حساب المبيعات المدفوعة بالكامل (paid_amount للفواتير التي status = 'paid')
                $paidSalesResult = $db->queryOne(
                    "SELECT COALESCE(SUM(paid_amount), 0) AS total_paid_sales
                     FROM local_invoices
                     WHERE status = 'paid' AND paid_amount > 0"
                );
                
                if (!empty($paidSalesResult)) {
                    $paidSalesAmount = (float)($paidSalesResult['total_paid_sales'] ?? 0);
                    $totalCollectionsAmount += $paidSalesAmount;
                }
            } else {
                // إذا لم يكن هناك عمود status، نحسب جميع المبالغ المدفوعة
                $paidSalesResult = $db->queryOne(
                    "SELECT COALESCE(SUM(paid_amount), 0) AS total_paid_sales
                     FROM local_invoices
                     WHERE paid_amount > 0"
                );
                
                if (!empty($paidSalesResult)) {
                    $paidSalesAmount = (float)($paidSalesResult['total_paid_sales'] ?? 0);
                    $totalCollectionsAmount += $paidSalesAmount;
                }
            }
        } catch (Throwable $paidSalesError) {
            error_log('Local customers paid sales calculation error: ' . $paidSalesError->getMessage());
        }
    }
} catch (Throwable $collectionsError) {
    error_log('Local customers collections summary error: ' . $collectionsError->getMessage());
    $totalCollectionsAmount = 0.0;
}

// تعيين القيم النهائية
$summaryDebtorCount = $customerStats['debtor_count'] ?? 0;
$summaryTotalDebt = (float)($customerStats['total_debt'] ?? 0.0);
$summaryTotalCustomers = $customerStats['total_count'] ?? $totalCustomers;
?>

<!-- Responsive Modals CSS - يجب أن يكون في البداية قبل أي محتوى -->
<link rel="stylesheet" href="<?php echo getRelativeUrl('assets/css/responsive-modals.css'); ?>">

<script>
// تعريفات مؤقتة - سيتم استبدالها بالدوال الكاملة عند تحميل الصفحة
(function() {
    'use strict';
    
    // دالة مساعدة للتحقق من الموبايل
    function checkIsMobile() {
        return window.innerWidth <= 768;
    }
    
    // دالة مساعدة للتمرير
    function doScrollToElement(element) {
        if (!element) return;
        setTimeout(function() {
            const rect = element.getBoundingClientRect();
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const elementTop = rect.top + scrollTop;
            const offset = 80;
            requestAnimationFrame(function() {
                window.scrollTo({
                    top: Math.max(0, elementTop - offset),
                    behavior: 'smooth'
                });
            });
        }, 200);
    }
    
    // دالة مساعدة لإغلاق النماذج
    function doCloseAllForms() {
        const cards = ['importLocalCustomersCard', 'addLocalCustomerCard', 'customerExportCard'];
        cards.forEach(function(cardId) {
            const card = document.getElementById(cardId);
            if (card && card.style.display !== 'none') {
                card.style.display = 'none';
            }
        });
        const modals = ['importLocalCustomersModal', 'addLocalCustomerModal', 'customerExportModal'];
        modals.forEach(function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal && typeof bootstrap !== 'undefined') {
                const modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) modalInstance.hide();
            }
        });
    }
    
    if (typeof window.showImportLocalCustomersModal === 'undefined') {
        window.showImportLocalCustomersModal = function() {
            doCloseAllForms();
            if (checkIsMobile()) {
                const card = document.getElementById('importLocalCustomersCard');
                if (card) {
                    card.style.display = 'block';
                    setTimeout(function() {
                        doScrollToElement(card);
                    }, 50);
                }
            } else {
                const modal = document.getElementById('importLocalCustomersModal');
                if (modal && typeof bootstrap !== 'undefined') {
                    const modalInstance = new bootstrap.Modal(modal);
                    modalInstance.show();
                }
            }
        };
    }
    
    if (typeof window.showCustomerExportModal === 'undefined') {
        window.showCustomerExportModal = function(event) {
            doCloseAllForms();
            const button = event ? (event.target.closest('button') || event.target) : null;
            const section = button ? (button.getAttribute('data-section') || 'local') : 'local';
            
            if (checkIsMobile()) {
                const card = document.getElementById('customerExportCard');
                if (card) {
                    card.setAttribute('data-section', section);
                    card.style.display = 'block';
                    setTimeout(function() {
                        doScrollToElement(card);
                    }, 50);
                }
            } else {
                const modal = document.getElementById('customerExportModal');
                if (modal && typeof bootstrap !== 'undefined') {
                    modal.setAttribute('data-section', section);
                    const modalInstance = new bootstrap.Modal(modal);
                    modalInstance.show();
                }
            }
        };
    }
    
    if (typeof window.showAddLocalCustomerModal === 'undefined') {
        window.showAddLocalCustomerModal = function() {
            doCloseAllForms();
            if (checkIsMobile()) {
                const card = document.getElementById('addLocalCustomerCard');
                if (card) {
                    card.style.display = 'block';
                    setTimeout(function() {
                        doScrollToElement(card);
                    }, 50);
                }
            } else {
                const modal = document.getElementById('addLocalCustomerModal');
                if (modal && typeof bootstrap !== 'undefined') {
                    const modalInstance = new bootstrap.Modal(modal);
                    modalInstance.show();
                }
            }
        };
    }
})();
</script>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
    <h2 class="mb-2 mb-md-0">
        <i class="bi bi-people me-2"></i>العملاء المحليين
    </h2>
    <div class="d-flex gap-2">
        <button class="btn btn-success" onclick="showImportLocalCustomersModal()">
            <i class="bi bi-file-earmark-spreadsheet me-2"></i>استيراد من CSV
        </button>
        <?php if (in_array($currentRole, ['manager', 'developer', 'accountant'], true)): ?>
        <button type="button" class="btn btn-info" onclick="showCustomerExportModal(event)" data-section="local">
            <i class="bi bi-download me-2"></i>تصدير عملاء محددين
        </button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="showAddLocalCustomerModal()">
            <i class="bi bi-person-plus me-2"></i>إضافة عميل محلي جديد
        </button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">عدد العملاء المحليين</div>
                    <div class="fs-4 fw-bold mb-0"><?php echo number_format((int)$summaryTotalCustomers); ?></div>
                </div>
                <span class="text-primary display-6"><i class="bi bi-people-fill"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">العملاء المدينون</div>
                    <div class="fs-4 fw-bold mb-0"><?php echo number_format((int)$summaryDebtorCount); ?></div>
                </div>
                <span class="text-danger display-6"><i class="bi bi-exclamation-circle-fill"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">إجمالي الديون</div>
                    <div class="fs-5 fw-bold mb-0"><?php 
                        // استخدام number_format مباشرة لتجنب مشكلة cleanFinancialValue التي تقيد القيمة بـ 1000000
                        $formattedDebt = number_format((float)$summaryTotalDebt, 2, '.', ',') . ' ' . getCurrencySymbol();
                        echo $formattedDebt;
                    ?></div>
                </div>
                <span class="text-warning display-6"><i class="bi bi-cash-stack"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">إجمالي التحصيلات</div>
                    <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($totalCollectionsAmount); ?></div>
                </div>
                <span class="text-success display-6"><i class="bi bi-cash-coin"></i></span>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- البحث -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-2 g-md-3 align-items-end">
            <input type="hidden" name="page" value="local_customers">
            <div class="col-12 col-md-6 col-lg-5">
                <label for="customerSearch" class="visually-hidden">بحث عن العملاء</label>
                <div class="input-group input-group-sm shadow-sm">
                    <span class="input-group-text bg-light text-muted border-end-0">
                        <i class="bi bi-search"></i>
                    </span>
                    <input
                        type="text"
                        class="form-control border-start-0"
                        id="customerSearch"
                        name="search"
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="بحث سريع بالاسم أو الهاتف"
                        autocomplete="off"
                    >
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="debtStatusFilter" class="visually-hidden">تصفية حسب حالة الديون</label>
                <select class="form-select form-select-sm shadow-sm" id="debtStatusFilter" name="debt_status">
                    <option value="all" <?php echo $debtStatus === 'all' ? 'selected' : ''; ?>>الكل</option>
                    <option value="debtor" <?php echo $debtStatus === 'debtor' ? 'selected' : ''; ?>>مدين</option>
                    <option value="clear" <?php echo $debtStatus === 'clear' ? 'selected' : ''; ?>>غير مدين / لديه رصيد</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="regionFilter" class="visually-hidden">تصفية حسب المنطقة</label>
                <select class="form-select form-select-sm shadow-sm" id="regionFilter" name="region_id">
                    <option value="">جميع المناطق</option>
                    <?php
                    $regions = $db->query("SELECT id, name FROM regions ORDER BY name ASC");
                    foreach ($regions as $region):
                    ?>
                        <option value="<?php echo $region['id']; ?>" <?php echo $regionFilter === $region['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>
                    <span>بحث</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة العملاء -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة العملاء المحليين (<?php echo $totalCustomers; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper" style="will-change: scroll-position;">
            <table class="table dashboard-table align-middle" style="table-layout: auto;">
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>رقم الهاتف</th>
                        <th>الرصيد</th>
                        <th>العنوان</th>
                        <th>المنطقة</th>
                        <th>الموقع</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد عملاء محليين</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                <td>
                                    <?php
                                    // استخدام البيانات المجمعة مسبقاً بدلاً من استعلام منفصل
                                    $customerId = (int)$customer['id'];
                                    $phones = $customerPhonesMap[$customerId] ?? [];
                                    
                                    // إذا لم تكن هناك أرقام في local_customer_phones، استخدم الرقم القديم
                                    if (empty($phones) && !empty($customer['phone'])) {
                                        $phones = [$customer['phone']];
                                    }
                                    
                                    if (!empty($phones)) {
                                        foreach ($phones as $phoneNumber) {
                                            $phoneNumber = trim($phoneNumber ?? '');
                                            if (!empty($phoneNumber)) {
                                                echo '<a href="tel:' . htmlspecialchars($phoneNumber) . '" class="btn btn-sm btn-outline-primary me-1 mb-1" title="اتصل بـ ' . htmlspecialchars($phoneNumber) . '">';
                                                echo '<i class="bi bi-telephone-fill"></i> ' ;
                                                echo '</a>';
                                            }
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        $customerBalanceValue = isset($customer['balance']) ? (float) $customer['balance'] : 0.0;
                                        $balanceBadgeClass = $customerBalanceValue > 0
                                            ? 'bg-warning-subtle text-warning'
                                            : ($customerBalanceValue < 0 ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary');
                                        $displayBalanceValue = $customerBalanceValue < 0 ? abs($customerBalanceValue) : $customerBalanceValue;
                                    ?>
                                    <strong><?php echo formatCurrency($displayBalanceValue); ?></strong>
                                    <?php if ($customerBalanceValue !== 0.0): ?>
                                        <span class="badge <?php echo $balanceBadgeClass; ?> ms-1">
                                            <?php echo $customerBalanceValue > 0 ? 'رصيد مدين' : 'رصيد دائن'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($customer['region_name'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                    $hasLocation = isset($customer['latitude'], $customer['longitude']) &&
                                        $customer['latitude'] !== null &&
                                        $customer['longitude'] !== null;
                                    $latValue = $hasLocation ? (float)$customer['latitude'] : null;
                                    $lngValue = $hasLocation ? (float)$customer['longitude'] : null;
                                    ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary location-capture-btn"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-geo-alt me-1"></i>تحديد
                                        </button>
                                        <?php if ($hasLocation): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-info location-view-btn"
                                                data-customer-id="<?php echo (int)$customer['id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                data-latitude="<?php echo htmlspecialchars(number_format($latValue, 8, '.', '')); ?>"
                                                data-longitude="<?php echo htmlspecialchars(number_format($lngValue, 8, '.', '')); ?>"
                                            >
                                                <i class="bi bi-map me-1"></i>عرض
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary">غير محدد</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $customerBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
                                    $displayBalanceForButton = $customerBalance < 0 ? abs($customerBalance) : $customerBalance;
                                    $formattedBalance = formatCurrency($displayBalanceForButton);
                                    $rawBalance = number_format($customerBalance, 2, '.', '');
                                    ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <?php if (in_array($currentRole, ['manager', 'developer', 'accountant', 'sales'], true)): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-warning"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editLocalCustomerModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                            data-customer-region-id="<?php echo (int)($customer['region_id'] ?? 0); ?>"
                                            data-customer-balance="<?php echo $rawBalance; ?>"
                                        >
                                            <i class="bi bi-pencil me-1"></i>تعديل
                                        </button>
                                        <?php endif; ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm <?php echo $customerBalance > 0 ? 'btn-success' : 'btn-outline-secondary'; ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#collectPaymentModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-balance="<?php echo $rawBalance; ?>"
                                            data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>"
                                            <?php echo $customerBalance > 0 ? '' : 'disabled'; ?>
                                        >
                                            <i class="bi bi-cash-coin me-1"></i>تحصيل
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-info"
                                            data-bs-toggle="modal"
                                            data-bs-target="#localCustomerPurchaseHistoryModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                        >
                                            <i class="bi bi-receipt me-1"></i>سجل
                                        </button>
                                        <?php if ($currentRole === 'manager'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteLocalCustomerModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-trash3 me-1"></i>حذف
                                        </button>
                                        <?php endif; ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-warning"
                                            data-bs-toggle="modal"
                                            data-bs-target="#localCustomerReturnModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                        >
                                            <i class="bi bi-arrow-return-left me-1"></i>مرتجع
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=local_customers&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=local_customers&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=local_customers&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=local_customers&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=local_customers&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal تحصيل ديون العميل - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="collectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل المحلي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="collect_debt">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">العميل</div>
                        <div class="fs-5 collection-customer-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning collection-current-debt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="collectionAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input
                            type="number"
                            class="form-control"
                            id="collectionAmount"
                            name="amount"
                            step="0.01"
                            min="0.01"
                            required
                        >
                        <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">تحصيل المبلغ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card تحصيل ديون العميل - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="collectPaymentCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل المحلي</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
            <input type="hidden" name="action" value="collect_debt">
            <input type="hidden" name="customer_id" id="collectPaymentCardCustomerId">
            <div class="mb-3">
                <div class="fw-semibold text-muted">العميل</div>
                <div class="fs-5" id="collectPaymentCardCustomerName">-</div>
            </div>
            <div class="mb-3">
                <div class="fw-semibold text-muted">الديون الحالية</div>
                <div class="fs-5 text-warning" id="collectPaymentCardCurrentDebt">-</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="collectPaymentCardAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                <input
                    type="number"
                    class="form-control"
                    id="collectPaymentCardAmount"
                    name="amount"
                    step="0.01"
                    min="0.01"
                    required
                >
                <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">تحصيل المبلغ</button>
                <button type="button" class="btn btn-secondary" onclick="closeCollectPaymentCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal إضافة عميل محلي جديد - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="addLocalCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة عميل محلي جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="add_customer">
                <div class="modal-body">
                    <!-- على الهاتف: 2 حقل في كل صف | على الشاشات الكبيرة: حقل واحد في كل صف -->
                    <div class="row g-2 g-md-3">
                        <!-- اسم العميل - يأخذ الصف كاملاً على جميع الشاشات -->
                        <div class="col-12 mb-2 mb-md-3">
                            <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <!-- أرقام الهاتف - يأخذ الصف كاملاً على جميع الشاشات -->
                        <div class="col-12 mb-2 mb-md-3">
                            <label class="form-label">أرقام الهاتف</label>
                            <div id="phoneNumbersContainer">
                                <div class="input-group mb-2">
                                    <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                                    <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="addPhoneBtn">
                                <i class="bi bi-plus-circle"></i> إضافة رقم آخر
                            </button>
                            <input type="hidden" name="phone" value=""> <!-- للحفاظ على التوافق مع الكود القديم -->
                        </div>
                        
                        <!-- ديون العميل - يأخذ نصف الصف على الهاتف فقط -->
                        <div class="col-6 col-md-12 mb-2 mb-md-3">
                            <label class="form-label">ديون العميل / رصيد العميل</label>
                            <input type="number" class="form-control" name="balance" step="0.01" value="0" placeholder="مثال: 0 أو -500">
                            <small class="text-muted d-block mt-1 small">
                                <strong>إدخال قيمة سالبة:</strong> يتم اعتبارها رصيد دائن للعميل (مبلغ متاح للعميل). 
                                لا يتم تحصيل هذا الرصيد، ويمكن للعميل استخدامه عند شراء فواتير حيث يتم خصم قيمة الفاتورة من الرصيد تلقائياً دون تسجيلها كدين.
                            </small>
                        </div>
                        
                        <!-- العنوان - يأخذ نصف الصف على الهاتف فقط -->
                        <div class="col-6 col-md-12 mb-2 mb-md-3">
                            <label class="form-label">العنوان</label>
                            <textarea class="form-control" name="address" rows="2"></textarea>
                        </div>
                        
                        <!-- المنطقة - يأخذ نصف الصف على الهاتف فقط -->
                        <div class="col-6 col-md-12 mb-2 mb-md-3">
                            <label class="form-label">المنطقة</label>
                            <div class="input-group">
                                <select class="form-select" name="region_id" id="addLocalCustomerRegionId">
                                    <option value="">اختر المنطقة</option>
                                    <?php
                                    $regions = $db->query("SELECT id, name FROM regions ORDER BY name ASC");
                                    foreach ($regions as $region):
                                    ?>
                                        <option value="<?php echo $region['id']; ?>"><?php echo htmlspecialchars($region['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRegionFromLocalCustomerModal">
                                    <i class="bi bi-plus-circle"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- الموقع الجغرافي - يأخذ نصف الصف على الهاتف فقط -->
                        <div class="col-6 col-md-12 mb-2 mb-md-3">
                            <label class="form-label">الموقع الجغرافي</label>
                            <div class="d-flex gap-2 mb-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="getLocationBtn">
                                    <i class="bi bi-geo-alt"></i> الحصول على الموقع الحالي
                                </button>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="text" class="form-control" name="latitude" id="addCustomerLatitude" placeholder="خط العرض" readonly>
                                </div>
                                <div class="col-6">
                                    <input type="text" class="form-control" name="longitude" id="addCustomerLongitude" placeholder="خط الطول" readonly>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-1 small">يمكنك الحصول على الموقع تلقائياً أو إدخاله يدوياً</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card إضافة عميل محلي جديد - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="addLocalCustomerCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">إضافة عميل محلي جديد</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
            <input type="hidden" name="action" value="add_customer">
            <div class="row g-2">
                <div class="col-12 mb-2">
                    <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required>
                </div>
                <div class="col-12 mb-2">
                    <label class="form-label">أرقام الهاتف</label>
                    <div id="addCustomerCardPhoneNumbersContainer">
                        <div class="input-group mb-2">
                            <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                            <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addCustomerCardPhoneBtn">
                        <i class="bi bi-plus-circle"></i> إضافة رقم آخر
                    </button>
                    <input type="hidden" name="phone" value="">
                </div>
                <div class="col-6 mb-2">
                    <label class="form-label">ديون العميل / رصيد العميل</label>
                    <input type="number" class="form-control" name="balance" step="0.01" value="0" placeholder="مثال: 0 أو -500">
                </div>
                <div class="col-6 mb-2">
                    <label class="form-label">العنوان</label>
                    <textarea class="form-control" name="address" rows="2"></textarea>
                </div>
                <div class="col-6 mb-2">
                    <label class="form-label">المنطقة</label>
                    <div class="input-group">
                        <select class="form-select" name="region_id" id="addCustomerCardRegionId">
                            <option value="">اختر المنطقة</option>
                            <?php
                            $regions = $db->query("SELECT id, name FROM regions ORDER BY name ASC");
                            foreach ($regions as $region):
                            ?>
                                <option value="<?php echo $region['id']; ?>"><?php echo htmlspecialchars($region['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
                        <button type="button" class="btn btn-outline-primary" onclick="showAddRegionModal()">
                            <i class="bi bi-plus-circle"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-6 mb-2">
                    <label class="form-label">الموقع الجغرافي</label>
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="getLocationCardBtn">
                            <i class="bi bi-geo-alt"></i> الحصول على الموقع
                        </button>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <input type="text" class="form-control" name="latitude" id="addCustomerCardLatitude" placeholder="خط العرض" readonly>
                        </div>
                        <div class="col-6">
                            <input type="text" class="form-control" name="longitude" id="addCustomerCardLongitude" placeholder="خط الطول" readonly>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">إضافة</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddLocalCustomerCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal سجل مشتريات العميل المحلي - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="localCustomerPurchaseHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>
                    سجل مشتريات العميل المحلي
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <!-- Customer Info -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-muted small">العميل</div>
                                <div class="fs-5 fw-bold" id="localPurchaseHistoryCustomerName">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">الهاتف</div>
                                <div id="localPurchaseHistoryCustomerPhone">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">العنوان</div>
                                <div id="localPurchaseHistoryCustomerAddress">-</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" 
                                       id="localPurchaseHistorySearchProduct" 
                                       placeholder="البحث باسم المنتج">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" 
                                       id="localPurchaseHistorySearchBatch" 
                                       placeholder="البحث برقم التشغيلة">
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-primary btn-sm w-100" 
                                        onclick="loadLocalCustomerPurchaseHistory()">
                                    <i class="bi bi-search me-1"></i>بحث
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading -->
                <div class="text-center py-4" id="localPurchaseHistoryLoading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>

                <!-- Error -->
                <div class="alert alert-danger d-none" id="localPurchaseHistoryError"></div>

                <!-- Purchase History Table -->
                <div id="localPurchaseHistoryTable" class="d-none">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">
                                        <input type="checkbox" id="localSelectAllItems" onchange="localToggleAllItems()">
                                    </th>
                                    <th>رقم الفاتورة</th>
                                    <th>رقم التشغيلة</th>
                                    <th>اسم المنتج</th>
                                    <th>الكمية المشتراة</th>
                                    <th>الكمية المرتجعة</th>
                                    <th>المتاح للإرجاع</th>
                                    <th>سعر الوحدة</th>
                                    <th>السعر الإجمالي</th>
                                    <th>تاريخ الشراء</th>
                                    <th style="width: 100px;">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="localPurchaseHistoryTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="printLocalCustomerStatementBtn" onclick="printLocalCustomerStatement()" style="display: none;">
                    <i class="bi bi-printer me-1"></i>طباعة كشف الحساب
                </button>
                <button type="button" class="btn btn-success" id="localCustomerReturnBtn" onclick="openLocalCustomerReturnModal()" style="display: none;">
                    <i class="bi bi-arrow-return-left me-1"></i>إرجاع منتجات
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Card سجل مشتريات العميل المحلي - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="localCustomerPurchaseHistoryCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-receipt me-2"></i>
            سجل مشتريات العميل المحلي
        </h5>
    </div>
    <div class="card-body">
        <!-- Customer Info -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="row">
                    <div class="col-12 mb-2">
                        <div class="text-muted small">العميل</div>
                        <div class="fs-5 fw-bold" id="localPurchaseHistoryCardCustomerName">-</div>
                    </div>
                    <div class="col-6 mb-2">
                        <div class="text-muted small">الهاتف</div>
                        <div id="localPurchaseHistoryCardCustomerPhone">-</div>
                    </div>
                    <div class="col-6 mb-2">
                        <div class="text-muted small">العنوان</div>
                        <div id="localPurchaseHistoryCardCustomerAddress">-</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-12">
                        <input type="text" class="form-control form-control-sm" 
                               id="localPurchaseHistoryCardSearchProduct" 
                               placeholder="البحث باسم المنتج">
                    </div>
                    <div class="col-12">
                        <input type="text" class="form-control form-control-sm" 
                               id="localPurchaseHistoryCardSearchBatch" 
                               placeholder="البحث برقم التشغيلة">
                    </div>
                    <div class="col-12">
                        <button type="button" class="btn btn-primary btn-sm w-100" 
                                onclick="loadLocalCustomerPurchaseHistory()">
                            <i class="bi bi-search me-1"></i>بحث
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading -->
        <div class="text-center py-4" id="localPurchaseHistoryCardLoading">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">جاري التحميل...</span>
            </div>
        </div>

        <!-- Error -->
        <div class="alert alert-danger d-none" id="localPurchaseHistoryCardError"></div>

        <!-- Purchase History Table -->
        <div id="localPurchaseHistoryCardTable" class="d-none">
            <div class="table-responsive">
                <table class="table table-hover table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="localSelectAllItemsCard" onchange="localToggleAllItems()">
                            </th>
                            <th>رقم الفاتورة</th>
                            <th>رقم التشغيلة</th>
                            <th>اسم المنتج</th>
                            <th>الكمية المشتراة</th>
                            <th>الكمية المرتجعة</th>
                            <th>المتاح للإرجاع</th>
                            <th>سعر الوحدة</th>
                            <th>السعر الإجمالي</th>
                            <th>تاريخ الشراء</th>
                            <th style="width: 100px;">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="localPurchaseHistoryCardTableBody">
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-primary" id="printLocalCustomerStatementCardBtn" onclick="printLocalCustomerStatement()" style="display: none;">
                <i class="bi bi-printer me-1"></i>طباعة كشف الحساب
            </button>
            <button type="button" class="btn btn-success" id="localCustomerReturnCardBtn" onclick="openLocalCustomerReturnModal()" style="display: none;">
                <i class="bi bi-arrow-return-left me-1"></i>إرجاع منتجات
            </button>
            <button type="button" class="btn btn-secondary" onclick="closeLocalCustomerPurchaseHistoryCard()">إغلاق</button>
        </div>
    </div>
</div>

<!-- Modal إرجاع منتجات العميل المحلي - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="localCustomerReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content shadow-lg">
            <div class="modal-header bg-warning text-dark border-bottom border-warning">
                <h5 class="modal-title d-flex align-items-center">
                    <i class="bi bi-arrow-return-left me-2 fs-4"></i>
                    <span>إرجاع منتجات - <strong id="localReturnCustomerName">عميل محلي</strong></span>
                </h5>
                <button type="button" class="btn-close btn-close-dark" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4">
                <!-- معلومات العميل -->
                <div class="card bg-light mb-4 border-0">
                    <div class="card-body py-2">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1">العميل</small>
                                <strong id="localReturnCustomerNameCard" class="text-dark">-</strong>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <small class="text-muted d-block mb-1">المبلغ الإجمالي</small>
                                <strong id="localReturnTotalAmountCard" class="text-danger fs-5">0.00 ج.م</strong>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- طريقة الاسترداد -->
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <label class="form-label fw-bold mb-0">
                            <i class="bi bi-cash-coin me-2 text-primary"></i>طريقة الاسترداد <span class="text-danger">*</span>
                        </label>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check p-3 border rounded h-100" style="cursor: pointer; transition: all 0.3s;" onmouseover="this.style.backgroundColor='#f8f9fa'" onmouseout="this.style.backgroundColor=''">
                                    <input class="form-check-input" type="radio" name="localRefundMethod" id="localRefundCredit" value="credit" checked onchange="updateRefundMethodInfo()">
                                    <label class="form-check-label w-100" for="localRefundCredit" style="cursor: pointer;">
                                        <div class="fw-semibold mb-1">
                                            <i class="bi bi-wallet2 me-2 text-success"></i>خصم من الرصيد
                                        </div>
                                        <small class="text-muted d-block">
                                            إذا كان العميل مدين: خصم من الدين<br>
                                            إذا كان غير مدين: إضافة رصيد دائن
                                        </small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check p-3 border rounded h-100" style="cursor: pointer; transition: all 0.3s;" onmouseover="this.style.backgroundColor='#f8f9fa'" onmouseout="this.style.backgroundColor=''">
                                    <input class="form-check-input" type="radio" name="localRefundMethod" id="localRefundCash" value="cash" onchange="updateRefundMethodInfo()">
                                    <label class="form-check-label w-100" for="localRefundCash" style="cursor: pointer;">
                                        <div class="fw-semibold mb-1">
                                            <i class="bi bi-cash-stack me-2 text-warning"></i>استرداد نقدي
                                        </div>
                                        <small class="text-muted d-block">
                                            يتم إضافة مصروف في خزنة الشركة
                                        </small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- المنتجات المحددة -->
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <label class="form-label fw-bold mb-0">
                            <i class="bi bi-box-seam me-2 text-primary"></i>المنتجات المحددة للإرجاع
                        </label>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 120px;">رقم الفاتورة</th>
                                        <th>اسم المنتج</th>
                                        <th style="width: 150px;">الكمية</th>
                                        <th style="width: 120px;">سعر الوحدة</th>
                                        <th style="width: 120px;">الإجمالي</th>
                                        <th style="width: 80px;" class="text-center">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody id="localReturnItemsList">
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox me-2"></i>لا توجد منتجات محددة
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="4" class="text-end align-middle">
                                            <span class="fs-6">المبلغ الإجمالي:</span>
                                        </th>
                                        <th class="text-danger fs-5 align-middle" id="localReturnTotalAmount">0.00 ج.م</th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- الملاحظات -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <label class="form-label fw-bold mb-0" for="localReturnNotes">
                            <i class="bi bi-sticky me-2 text-primary"></i>ملاحظات (اختياري)
                        </label>
                    </div>
                    <div class="card-body">
                        <textarea 
                            class="form-control" 
                            id="localReturnNotes" 
                            rows="3" 
                            placeholder="أضف أي ملاحظات حول المرتجع..."
                            style="resize: vertical;"
                        ></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-success btn-lg" id="localReturnSubmitBtn" onclick="submitLocalCustomerReturn()">
                    <i class="bi bi-check-circle me-1"></i>تسجيل المرتجع
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal عرض موقع العميل - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="viewLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>موقع العميل المحلي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">العميل</div>
                    <div class="fs-5 fw-bold location-customer-name">-</div>
                </div>
                <div class="ratio ratio-16x9">
                    <iframe
                        class="location-map-frame border rounded"
                        data-src=""
                        src="about:blank"
                        title="معاينة موقع العميل"
                        allowfullscreen
                        loading="lazy"
                        allow="geolocation; camera; microphone"
                    ></iframe>
                </div>
                <p class="mt-3 text-muted mb-0">
                    يمكنك متابعة الموقع داخل المعاينة أو فتحه في خرائط Google للحصول على اتجاهات دقيقة.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <a href="#" target="_blank" rel="noopener" class="btn btn-primary location-open-map">
                    <i class="bi bi-map"></i> فتح في الخرائط
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Card عرض موقع العميل - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="viewLocationCard" style="display: none;">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>موقع العميل المحلي</h5>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="text-muted small fw-semibold">العميل</div>
            <div class="fs-5 fw-bold location-customer-name-card">-</div>
        </div>
        <div class="ratio ratio-16x9">
            <iframe
                class="location-map-frame-card border rounded"
                data-src=""
                src="about:blank"
                title="معاينة موقع العميل"
                allowfullscreen
                loading="lazy"
                allow="geolocation; camera; microphone"
            ></iframe>
        </div>
        <p class="mt-3 text-muted mb-0">
            يمكنك متابعة الموقع داخل المعاينة أو فتحه في خرائط Google للحصول على اتجاهات دقيقة.
        </p>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-secondary flex-fill" onclick="closeViewLocationCard()">إغلاق</button>
            <a href="#" target="_blank" rel="noopener" class="btn btn-primary flex-fill location-open-map-card">
                <i class="bi bi-map"></i> فتح في الخرائط
            </a>
        </div>
    </div>
</div>

<script>
// ===== دوال النظام المزدوج (Modal للكمبيوتر / Card للموبايل) =====

// دالة للتحقق من الموبايل
function isMobile() {
    return window.innerWidth <= 768;
}

// دالة للـ scroll تلقائي محسّنة
function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80;
        const targetPosition = elementTop - offset;
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, targetPosition),
                behavior: 'smooth'
            });
        });
    }, 200);
}

// دالة لإغلاق جميع النماذج المفتوحة
function closeAllForms() {
    // إغلاق جميع Cards على الموبايل
    const cards = [
        'collectPaymentCard', 
        'addLocalCustomerCard', 
        'editLocalCustomerCard',
        'addRegionFromLocalCustomerCard', 
        'importLocalCustomersCard', 
        'deleteLocalCustomerCard',
        'customerExportCard',
        'viewLocationCard',
        'localCustomerPurchaseHistoryCard'
    ];
    
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    // إغلاق جميع Modals على الكمبيوتر
    const modals = [
        'collectPaymentModal', 
        'addLocalCustomerModal', 
        'editLocalCustomerModal', 
        'localCustomerPurchaseHistoryModal', 
        'localCustomerReturnModal', 
        'viewLocationModal',
        'addRegionFromLocalCustomerModal', 
        'importLocalCustomersModal', 
        'deleteLocalCustomerModal',
        'customerExportModal',
        'viewLocationModal'
    ];
    
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// دالة فتح نموذج استيراد العملاء المحليين
window.showImportLocalCustomersModal = function() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('importLocalCustomersCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('importLocalCustomersModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
};

// دالة فتح نموذج تحصيل الديون
function showCollectPaymentModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    const customerId = button.getAttribute('data-customer-id') || '';
    const customerName = button.getAttribute('data-customer-name') || '-';
    const balanceRaw = button.getAttribute('data-customer-balance') || '0';
    const balanceFormatted = button.getAttribute('data-customer-balance-formatted') || balanceRaw;
    const numericBalance = parseFloat(balanceRaw);
    const debtAmount = (numericBalance > 0 ? numericBalance : 0);
    
    if (isMobile()) {
        const card = document.getElementById('collectPaymentCard');
        if (card) {
            const customerIdInput = card.querySelector('#collectPaymentCardCustomerId');
            const customerNameEl = card.querySelector('#collectPaymentCardCustomerName');
            const debtEl = card.querySelector('#collectPaymentCardCurrentDebt');
            const amountInput = card.querySelector('#collectPaymentCardAmount');
            
            if (customerIdInput) customerIdInput.value = customerId;
            if (customerNameEl) customerNameEl.textContent = customerName;
            if (debtEl) debtEl.textContent = balanceFormatted;
            if (amountInput) {
                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
            }
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('collectPaymentModal');
        if (modal) {
            const customerIdInput = modal.querySelector('input[name="customer_id"]');
            const customerNameEl = modal.querySelector('.collection-customer-name');
            const debtEl = modal.querySelector('.collection-current-debt');
            const amountInput = modal.querySelector('#collectionAmount');
            
            if (customerIdInput) customerIdInput.value = customerId;
            if (customerNameEl) customerNameEl.textContent = customerName;
            if (debtEl) debtEl.textContent = balanceFormatted;
            if (amountInput) {
                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
            }
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// دالة فتح نموذج تعديل عميل محلي
function showEditLocalCustomerModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    const customerId = button.getAttribute('data-customer-id') || '';
    const customerName = button.getAttribute('data-customer-name') || '';
    const customerPhone = button.getAttribute('data-customer-phone') || '';
    const customerAddress = button.getAttribute('data-customer-address') || '';
    const customerRegionId = button.getAttribute('data-customer-region-id') || '';
    const customerBalance = button.getAttribute('data-customer-balance') || '0';
    
    if (!customerId) {
        console.error('Customer ID not found');
        return;
    }
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('editLocalCustomerCard');
        if (card) {
            const idInput = document.getElementById('editLocalCustomerCardId');
            const nameInput = document.getElementById('editLocalCustomerCardName');
            const addressInput = document.getElementById('editLocalCustomerCardAddress');
            const regionInput = document.getElementById('editLocalCustomerCardRegionId');
            const balanceInput = document.getElementById('editLocalCustomerCardBalance');
            const editPhoneContainer = document.getElementById('editLocalCustomerCardPhoneNumbersContainer');
            
            if (idInput) idInput.value = customerId;
            if (nameInput) nameInput.value = customerName || '';
            if (addressInput) addressInput.value = customerAddress;
            if (regionInput) regionInput.value = customerRegionId;
            if (balanceInput) balanceInput.value = customerBalance;
            
            // تحميل أرقام الهواتف المتعددة
            if (editPhoneContainer) {
                editPhoneContainer.innerHTML = '';
                fetch('?action=get_local_customer_phones&customer_id=' + customerId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.phones && data.phones.length > 0) {
                            data.phones.forEach(function(phone) {
                                const phoneGroup = document.createElement('div');
                                phoneGroup.className = 'input-group mb-2';
                                phoneGroup.innerHTML = `
                                    <input type="text" class="form-control phone-input" name="phones[]" value="${phone}" placeholder="مثال: 01234567890">
                                    <button type="button" class="btn btn-outline-danger remove-phone-btn">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                `;
                                editPhoneContainer.appendChild(phoneGroup);
                            });
                        } else if (customerPhone) {
                            const phoneGroup = document.createElement('div');
                            phoneGroup.className = 'input-group mb-2';
                            phoneGroup.innerHTML = `
                                <input type="text" class="form-control phone-input" name="phones[]" value="${customerPhone}" placeholder="مثال: 01234567890">
                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            `;
                            editPhoneContainer.appendChild(phoneGroup);
                        } else {
                            const phoneGroup = document.createElement('div');
                            phoneGroup.className = 'input-group mb-2';
                            phoneGroup.innerHTML = `
                                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            `;
                            editPhoneContainer.appendChild(phoneGroup);
                        }
                        if (typeof updateEditRemoveButtons === 'function') {
                            updateEditRemoveButtons();
                        }
                    })
                    .catch(error => {
                        console.error('Error loading phones:', error);
                        if (customerPhone) {
                            const phoneGroup = document.createElement('div');
                            phoneGroup.className = 'input-group mb-2';
                            phoneGroup.innerHTML = `
                                <input type="text" class="form-control phone-input" name="phones[]" value="${customerPhone}" placeholder="مثال: 01234567890">
                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            `;
                            editPhoneContainer.appendChild(phoneGroup);
                        }
                        if (typeof updateEditRemoveButtons === 'function') {
                            updateEditRemoveButtons();
                        }
                    });
            }
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('editLocalCustomerModal');
        if (modal) {
            const idInput = document.getElementById('editLocalCustomerId');
            const nameInput = document.getElementById('editLocalCustomerName');
            const addressInput = document.getElementById('editLocalCustomerAddress');
            const regionInput = document.getElementById('editLocalCustomerRegionId');
            const balanceInput = document.getElementById('editLocalCustomerBalance');
            const editPhoneContainer = document.getElementById('editPhoneNumbersContainer');
            
            if (idInput) idInput.value = customerId;
            if (nameInput) nameInput.value = customerName || '';
            if (addressInput) addressInput.value = customerAddress;
            if (regionInput) regionInput.value = customerRegionId;
            if (balanceInput) balanceInput.value = customerBalance;
            
            // تحميل أرقام الهواتف المتعددة
            if (editPhoneContainer) {
                editPhoneContainer.innerHTML = '';
                fetch('?action=get_local_customer_phones&customer_id=' + customerId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.phones && data.phones.length > 0) {
                            data.phones.forEach(function(phone) {
                                const phoneGroup = document.createElement('div');
                                phoneGroup.className = 'input-group mb-2';
                                phoneGroup.innerHTML = `
                                    <input type="text" class="form-control phone-input" name="phones[]" value="${phone}" placeholder="مثال: 01234567890">
                                    <button type="button" class="btn btn-outline-danger remove-phone-btn">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                `;
                                editPhoneContainer.appendChild(phoneGroup);
                            });
                        } else if (customerPhone) {
                            const phoneGroup = document.createElement('div');
                            phoneGroup.className = 'input-group mb-2';
                            phoneGroup.innerHTML = `
                                <input type="text" class="form-control phone-input" name="phones[]" value="${customerPhone}" placeholder="مثال: 01234567890">
                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            `;
                            editPhoneContainer.appendChild(phoneGroup);
                        } else {
                            const phoneGroup = document.createElement('div');
                            phoneGroup.className = 'input-group mb-2';
                            phoneGroup.innerHTML = `
                                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            `;
                            editPhoneContainer.appendChild(phoneGroup);
                        }
                        if (typeof updateEditRemoveButtons === 'function') {
                            updateEditRemoveButtons();
                        }
                    })
                    .catch(error => {
                        console.error('Error loading phones:', error);
                        if (customerPhone) {
                            const phoneGroup = document.createElement('div');
                            phoneGroup.className = 'input-group mb-2';
                            phoneGroup.innerHTML = `
                                <input type="text" class="form-control phone-input" name="phones[]" value="${customerPhone}" placeholder="مثال: 01234567890">
                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            `;
                            editPhoneContainer.appendChild(phoneGroup);
                        }
                        if (typeof updateEditRemoveButtons === 'function') {
                            updateEditRemoveButtons();
                        }
                    });
            }
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// دالة فتح نموذج سجل مشتريات العميل المحلي
function showLocalCustomerPurchaseHistoryModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    const customerId = button.getAttribute('data-customer-id');
    const customerName = button.getAttribute('data-customer-name');
    const customerPhone = button.getAttribute('data-customer-phone') || '-';
    const customerAddress = button.getAttribute('data-customer-address') || '-';
    
    if (!customerId) return;
    
    // تحويل customerId إلى رقم للتأكد من صحته
    const customerIdNum = parseInt(customerId, 10);
    if (isNaN(customerIdNum) || customerIdNum <= 0) {
        console.error('Invalid customer ID:', customerId);
        return;
    }
    
    // تعيين المتغيرات العامة
    if (typeof currentLocalCustomerId !== 'undefined') {
        currentLocalCustomerId = customerIdNum;
    }
    if (typeof currentLocalCustomerName !== 'undefined') {
        currentLocalCustomerName = customerName;
    }
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('localCustomerPurchaseHistoryCard');
        if (card) {
            const nameElement = card.querySelector('#localPurchaseHistoryCardCustomerName');
            const phoneElement = card.querySelector('#localPurchaseHistoryCardCustomerPhone');
            const addressElement = card.querySelector('#localPurchaseHistoryCardCustomerAddress');
            
            if (nameElement) nameElement.textContent = customerName || '-';
            if (phoneElement) phoneElement.textContent = customerPhone || '-';
            if (addressElement) addressElement.textContent = customerAddress || '-';
            
            // إظهار loading وإخفاء المحتوى
            const loadingElement = card.querySelector('#localPurchaseHistoryCardLoading');
            const contentElement = card.querySelector('#localPurchaseHistoryCardTable');
            const errorElement = card.querySelector('#localPurchaseHistoryCardError');
            
            if (loadingElement) loadingElement.classList.remove('d-none');
            if (contentElement) contentElement.classList.add('d-none');
            if (errorElement) {
                errorElement.classList.add('d-none');
                errorElement.textContent = '';
            }
            
            // إخفاء الأزرار مؤقتاً
            const printBtn = card.querySelector('#printLocalCustomerStatementCardBtn');
            const returnBtn = card.querySelector('#localCustomerReturnCardBtn');
            if (printBtn) printBtn.style.display = 'none';
            if (returnBtn) returnBtn.style.display = 'none';
            
            // إعادة تعيين العناصر المحددة
            if (typeof localSelectedItemsForReturn !== 'undefined') {
                localSelectedItemsForReturn = [];
            }
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
            
            // تحميل البيانات بعد إظهار الـ card
            setTimeout(function() {
                if (typeof loadLocalCustomerPurchaseHistory === 'function') {
                    loadLocalCustomerPurchaseHistory();
                }
            }, 100);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('localCustomerPurchaseHistoryModal');
        if (modal) {
            const nameElement = document.getElementById('localPurchaseHistoryCustomerName');
            const phoneElement = document.getElementById('localPurchaseHistoryCustomerPhone');
            const addressElement = document.getElementById('localPurchaseHistoryCustomerAddress');
            
            if (nameElement) nameElement.textContent = customerName || '-';
            if (phoneElement) phoneElement.textContent = customerPhone || '-';
            if (addressElement) addressElement.textContent = customerAddress || '-';
            
            // إظهار loading وإخفاء المحتوى
            const loadingElement = document.getElementById('localPurchaseHistoryLoading');
            const contentElement = document.getElementById('localPurchaseHistoryTable');
            const errorElement = document.getElementById('localPurchaseHistoryError');
            
            if (loadingElement) loadingElement.classList.remove('d-none');
            if (contentElement) contentElement.classList.add('d-none');
            if (errorElement) {
                errorElement.classList.add('d-none');
                errorElement.textContent = '';
            }
            
            // إخفاء الأزرار مؤقتاً
            const printBtn = document.getElementById('printLocalCustomerStatementBtn');
            const returnBtn = document.getElementById('localCustomerReturnBtn');
            if (printBtn) printBtn.style.display = 'none';
            if (returnBtn) returnBtn.style.display = 'none';
            
            // إعادة تعيين العناصر المحددة
            if (typeof localSelectedItemsForReturn !== 'undefined') {
                localSelectedItemsForReturn = [];
            }
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
            
            // تحميل البيانات بعد إظهار الـ modal
            modal.addEventListener('shown.bs.modal', function loadData() {
                modal.removeEventListener('shown.bs.modal', loadData);
                if (typeof loadLocalCustomerPurchaseHistory === 'function') {
                    loadLocalCustomerPurchaseHistory();
                }
            }, { once: true });
        }
    }
}

// دالة فتح نموذج إضافة عميل
window.showAddLocalCustomerModal = function() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addLocalCustomerCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addLocalCustomerModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
};

// دوال إغلاق Cards
function closeCollectPaymentCard() {
    const card = document.getElementById('collectPaymentCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeAddLocalCustomerCard() {
    const card = document.getElementById('addLocalCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeEditLocalCustomerCard() {
    const card = document.getElementById('editLocalCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeLocalCustomerPurchaseHistoryCard() {
    const card = document.getElementById('localCustomerPurchaseHistoryCard');
    if (card) {
        card.style.display = 'none';
        // إعادة تعيين المتغيرات
        if (typeof currentLocalCustomerId !== 'undefined') {
            currentLocalCustomerId = null;
        }
        if (typeof currentLocalCustomerName !== 'undefined') {
            currentLocalCustomerName = null;
        }
        if (typeof localSelectedItemsForReturn !== 'undefined') {
            localSelectedItemsForReturn = [];
        }
        if (typeof localPurchaseHistoryData !== 'undefined') {
            localPurchaseHistoryData = [];
        }
    }
}

// دالة فتح نموذج تصدير العملاء
window.showCustomerExportModal = function(event) {
    closeAllForms();
    
    // الحصول على section من الزر إذا كان موجوداً
    const button = event ? (event.target.closest('button') || event.target) : null;
    const section = button ? (button.getAttribute('data-section') || 'local') : 'local';
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('customerExportCard');
        if (card) {
            // تعيين data-section للـ Card
            card.setAttribute('data-section', section);
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
            // initCardEvents سيتعامل مع تحميل البيانات عبر MutationObserver
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('customerExportModal');
        if (modal) {
            modal.setAttribute('data-section', section);
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
};

// دالة إغلاق Card تصدير العملاء
function closeCustomerExportCard() {
    const card = document.getElementById('customerExportCard');
    if (card) {
        card.style.display = 'none';
    }
}

// دالة إغلاق Card عرض الموقع
function closeViewLocationCard() {
    const card = document.getElementById('viewLocationCard');
    if (card) {
        card.style.display = 'none';
        // إعادة تعيين الخريطة
        const mapFrame = card.querySelector('.location-map-frame-card');
        if (mapFrame) {
            mapFrame.src = 'about:blank';
            mapFrame.removeAttribute('data-src');
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // معالج نموذج التحصيل
    var collectionModal = document.getElementById('collectPaymentModal');
    if (collectionModal) {
        var nameElement = collectionModal.querySelector('.collection-customer-name');
        var debtElement = collectionModal.querySelector('.collection-current-debt');
        var customerIdInput = collectionModal.querySelector('input[name="customer_id"]');
        var amountInput = collectionModal.querySelector('input[name="amount"]');

        if (nameElement && debtElement && customerIdInput && amountInput) {
            collectionModal.addEventListener('show.bs.modal', function (event) {
                var triggerButton = event.relatedTarget;
                if (!triggerButton) {
                    return;
                }

                var customerName = triggerButton.getAttribute('data-customer-name') || '-';
                var balanceRaw = triggerButton.getAttribute('data-customer-balance') || '0';
                var balanceFormatted = triggerButton.getAttribute('data-customer-balance-formatted') || balanceRaw;
                var numericBalance = parseFloat(balanceRaw);
                if (!Number.isFinite(numericBalance)) {
                    numericBalance = 0;
                }
                var debtAmount = numericBalance > 0 ? numericBalance : 0;

                nameElement.textContent = customerName;
                debtElement.textContent = balanceFormatted;
                customerIdInput.value = triggerButton.getAttribute('data-customer-id') || '';

                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
                amountInput.focus();
            });

            collectionModal.addEventListener('hidden.bs.modal', function () {
                nameElement.textContent = '-';
                debtElement.textContent = '-';
                customerIdInput.value = '';
                amountInput.value = '';
                amountInput.removeAttribute('max');
            });
        }
    }

    // معالج الموقع الجغرافي
    var locationCaptureButtons = document.querySelectorAll('.location-capture-btn');
    var viewLocationModal = document.getElementById('viewLocationModal');
    var locationMapFrame = viewLocationModal ? viewLocationModal.querySelector('.location-map-frame') : null;
    var locationCustomerName = viewLocationModal ? viewLocationModal.querySelector('.location-customer-name') : null;
    var locationExternalLink = viewLocationModal ? viewLocationModal.querySelector('.location-open-map') : null;
    var locationViewButtons = document.querySelectorAll('.location-view-btn');

    function setButtonLoading(button, isLoading) {
        if (!button) {
            return;
        }

        if (isLoading) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>جارٍ التحديد';
        } else {
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
            button.disabled = false;
        }
    }

    function showAlert(message) {
        window.alert(message);
    }

    locationCaptureButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name') || '';

            if (!customerId) {
                showAlert('تعذر تحديد العميل.');
                return;
            }

            if (!navigator.geolocation) {
                showAlert('المتصفح الحالي لا يدعم تحديد الموقع الجغرافي.');
                return;
            }

            // التحقق من الصلاحيات قبل الطلب
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                    if (result.state === 'denied') {
                        showAlert('تم رفض إذن الموقع الجغرافي. يرجى السماح بالوصول في إعدادات المتصفح.');
                        return;
                    }
                    requestGeolocation();
                }).catch(function() {
                    // إذا فشل query، حاول مباشرة
                    requestGeolocation();
                });
            } else {
                requestGeolocation();
            }

            function requestGeolocation() {
                setButtonLoading(button, true);

                navigator.geolocation.getCurrentPosition(function (position) {
                var latitude = position.coords.latitude.toFixed(8);
                var longitude = position.coords.longitude.toFixed(8);
                var requestUrl = window.location.pathname + window.location.search;

                var formData = new URLSearchParams();
                formData.append('action', 'update_location');
                formData.append('customer_id', customerId);
                formData.append('latitude', latitude);
                formData.append('longitude', longitude);

                fetch(requestUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (data) {
                        setButtonLoading(button, false);
                        if (data.success) {
                            showAlert('تم حفظ الموقع بنجاح!');
                            location.reload();
                        } else {
                            showAlert(data.message || 'حدث خطأ أثناء حفظ الموقع.');
                        }
                    })
                    .catch(function (error) {
                        setButtonLoading(button, false);
                        showAlert('حدث خطأ في الاتصال بالخادم.');
                        console.error('Error:', error);
                    });
                }, function (error) {
                    setButtonLoading(button, false);
                    var errorMessage = 'تعذر الحصول على الموقع.';
                    if (error.code === 1) {
                        errorMessage = 'تم رفض طلب الحصول على الموقع. يرجى السماح بالوصول إلى الموقع في إعدادات المتصفح.';
                    } else if (error.code === 2) {
                        errorMessage = 'تعذر تحديد الموقع. يرجى المحاولة مرة أخرى.';
                    } else if (error.code === 3) {
                        errorMessage = 'انتهت مهلة طلب الموقع. يرجى المحاولة مرة أخرى.';
                    }
                    showAlert(errorMessage);
                }, {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                });
            }
        });
    });

    if (locationViewButtons && locationViewButtons.length > 0) {
        locationViewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var customerName = button.getAttribute('data-customer-name') || '-';
                var latitude = button.getAttribute('data-latitude');
                var longitude = button.getAttribute('data-longitude');

                if (!latitude || !longitude) {
                    return;
                }

                if (isMobile()) {
                    // على الموبايل: استخدام Card
                    var locationCard = document.getElementById('viewLocationCard');
                    if (locationCard) {
                        var locationCardCustomerName = locationCard.querySelector('.location-customer-name-card');
                        var locationCardMapFrame = locationCard.querySelector('.location-map-frame-card');
                        var locationCardExternalLink = locationCard.querySelector('.location-open-map-card');

                        if (locationCardCustomerName) {
                            locationCardCustomerName.textContent = customerName;
                        }

                        if (locationCardMapFrame) {
                            var mapUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                            locationCardMapFrame.src = mapUrl;
                        }

                        if (locationCardExternalLink) {
                            locationCardExternalLink.href = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                        }

                        locationCard.style.display = 'block';
                        setTimeout(function() {
                            scrollToElement(locationCard);
                        }, 50);
                    }
                } else {
                    // على الكمبيوتر: استخدام Modal
                    if (viewLocationModal) {
                        if (locationCustomerName) {
                            locationCustomerName.textContent = customerName;
                        }

                        if (locationMapFrame) {
                            var mapUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                            // استخدام data-src للـ lazy loading
                            locationMapFrame.setAttribute('data-src', mapUrl);
                            // تحميل الخريطة فقط عند فتح الـ modal
                            viewLocationModal.addEventListener('shown.bs.modal', function loadMap() {
                                if (locationMapFrame.getAttribute('data-src')) {
                                    locationMapFrame.src = locationMapFrame.getAttribute('data-src');
                                    locationMapFrame.removeAttribute('data-src');
                                }
                                viewLocationModal.removeEventListener('shown.bs.modal', loadMap);
                            }, { once: true });
                        }

                        if (locationExternalLink) {
                            locationExternalLink.href = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                        }

                        var modal = new bootstrap.Modal(viewLocationModal);
                        modal.show();
                    }
                }
            });
        });
    }

    // معالج الحصول على الموقع عند إضافة عميل جديد
    var addCustomerModal = document.getElementById('addLocalCustomerModal');
    
    // استخدام event delegation على المودال للتأكد من عمل الزر
    if (addCustomerModal) {
        // استخدام event delegation للتعامل مع النقرات داخل المودال
        addCustomerModal.addEventListener('click', function(e) {
            // التحقق من أن العنصر المنقور عليه هو الزر المطلوب أو داخل الزر
            var clickedElement = e.target;
            // البحث عن الزر الأقرب (button) ثم التحقق من ID
            var button = clickedElement.closest('button');
            
            if (button && button.id === 'getLocationBtn') {
                e.preventDefault();
                e.stopPropagation();
                
                if (!navigator.geolocation) {
                    showAlert('المتصفح لا يدعم تحديد الموقع الجغرافي.');
                    return;
                }

                var originalText = button.innerHTML;
                
                // الحصول على العناصر
                var latitudeInput = document.getElementById('addCustomerLatitude');
                var longitudeInput = document.getElementById('addCustomerLongitude');
                
                if (!latitudeInput || !longitudeInput) {
                    showAlert('تعذر العثور على حقول الموقع.');
                    return;
                }
                
                // التحقق من الصلاحيات قبل الطلب
                function requestGeolocationForNewCustomer() {
                    button.disabled = true;
                    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحصول على الموقع...';

                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            latitudeInput.value = position.coords.latitude.toFixed(8);
                            longitudeInput.value = position.coords.longitude.toFixed(8);
                            button.disabled = false;
                            button.innerHTML = originalText;
                            showAlert('تم الحصول على الموقع بنجاح!');
                        },
                        function(error) {
                            button.disabled = false;
                            button.innerHTML = originalText;
                            var errorMessage = 'تعذر الحصول على الموقع.';
                            if (error.code === 1) {
                                errorMessage = 'تم رفض طلب الحصول على الموقع. يرجى السماح بالوصول إلى الموقع في إعدادات المتصفح.';
                            } else if (error.code === 2) {
                                errorMessage = 'تعذر تحديد الموقع. يرجى المحاولة مرة أخرى.';
                            } else if (error.code === 3) {
                                errorMessage = 'انتهت مهلة طلب الموقع. يرجى المحاولة مرة أخرى.';
                            }
                            showAlert(errorMessage);
                        },
                        {
                            enableHighAccuracy: true,
                            timeout: 10000,
                            maximumAge: 0
                        }
                    );
                }
                
                if (navigator.permissions && navigator.permissions.query) {
                    navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                        if (result.state === 'denied') {
                            showAlert('تم رفض إذن الموقع الجغرافي. يرجى السماح بالوصول في إعدادات المتصفح.');
                            return;
                        }
                        requestGeolocationForNewCustomer();
                    }).catch(function() {
                        // إذا فشل query، حاول مباشرة
                        requestGeolocationForNewCustomer();
                    });
                } else {
                    requestGeolocationForNewCustomer();
                }
            }
        });
    }

    if (addCustomerModal) {
        addCustomerModal.addEventListener('hidden.bs.modal', function() {
            if (addCustomerLatitudeInput) {
                addCustomerLatitudeInput.value = '';
            }
            if (addCustomerLongitudeInput) {
                addCustomerLongitudeInput.value = '';
            }
        });
    }

    // معالج الحصول على الموقع عند إضافة عميل جديد (للموبايل - Card)
    var getLocationCardBtn = document.getElementById('getLocationCardBtn');
    var addCustomerCardLatitudeInput = document.getElementById('addCustomerCardLatitude');
    var addCustomerCardLongitudeInput = document.getElementById('addCustomerCardLongitude');
    var addLocalCustomerCard = document.getElementById('addLocalCustomerCard');

    if (getLocationCardBtn && addCustomerCardLatitudeInput && addCustomerCardLongitudeInput) {
        getLocationCardBtn.addEventListener('click', function() {
            if (!navigator.geolocation) {
                showAlert('المتصفح لا يدعم تحديد الموقع الجغرافي.');
                return;
            }

            var button = this;
            var originalText = button.innerHTML;
            
            // التحقق من الصلاحيات قبل الطلب
            function requestGeolocationForNewCustomerCard() {
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحصول...';

                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        addCustomerCardLatitudeInput.value = position.coords.latitude.toFixed(8);
                        addCustomerCardLongitudeInput.value = position.coords.longitude.toFixed(8);
                        button.disabled = false;
                        button.innerHTML = originalText;
                        showAlert('تم الحصول على الموقع بنجاح!');
                    },
                    function(error) {
                        button.disabled = false;
                        button.innerHTML = originalText;
                        var errorMessage = 'تعذر الحصول على الموقع.';
                        if (error.code === 1) {
                            errorMessage = 'تم رفض طلب الحصول على الموقع. يرجى السماح بالوصول إلى الموقع في إعدادات المتصفح.';
                        } else if (error.code === 2) {
                            errorMessage = 'تعذر تحديد الموقع. يرجى المحاولة مرة أخرى.';
                        } else if (error.code === 3) {
                            errorMessage = 'انتهت مهلة طلب الموقع. يرجى المحاولة مرة أخرى.';
                        }
                        showAlert(errorMessage);
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 10000,
                        maximumAge: 0
                    }
                );
            }
            
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                    if (result.state === 'denied') {
                        showAlert('تم رفض إذن الموقع الجغرافي. يرجى السماح بالوصول في إعدادات المتصفح.');
                        return;
                    }
                    requestGeolocationForNewCustomerCard();
                }).catch(function() {
                    // إذا فشل query، حاول مباشرة
                    requestGeolocationForNewCustomerCard();
                });
            } else {
                requestGeolocationForNewCustomerCard();
            }
        });
    }
});

// إعادة تحميل الصفحة تلقائياً بعد أي رسالة
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        setTimeout(function() {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();

// معالجة سجل مشتريات العملاء المحليين
var currentLocalCustomerId = null;
var currentLocalCustomerName = null;
var localPurchaseHistoryData = [];
var localSelectedItemsForReturn = [];

// دالة طباعة كشف حساب العميل المحلي
function printLocalCustomerStatement() {
    if (!currentLocalCustomerId) {
        alert('يرجى فتح سجل مشتريات عميل أولاً');
        return;
    }
    
    const basePath = '<?php echo getBasePath(); ?>';
    // استخدام صفحة طباعة كشف الحساب مع تحديد نوع العميل
    const printUrl = basePath + '/print_customer_statement.php?customer_id=' + encodeURIComponent(currentLocalCustomerId) + '&type=local';
    window.open(printUrl, '_blank');
}

// دالة فتح modal إرجاع المنتجات للعميل المحلي
function openLocalCustomerReturnModal() {
    if (!currentLocalCustomerId) {
        alert('يرجى فتح سجل مشتريات عميل أولاً');
        return;
    }
    if (localSelectedItemsForReturn.length === 0) {
        alert('يرجى تحديد منتج واحد على الأقل للإرجاع');
        return;
    }
    
    // إظهار modal إرجاع المنتجات
    const returnModal = document.getElementById('localCustomerReturnModal');
    if (returnModal) {
        // تحديث قائمة المنتجات المحددة
        updateLocalReturnItemsList();
        
        const modal = new bootstrap.Modal(returnModal);
        modal.show();
    } else {
        alert('حدث خطأ: لم يتم العثور على نافذة إرجاع المنتجات');
    }
}

// دالة تحديث قائمة المنتجات المحددة للإرجاع
function updateLocalReturnItemsList() {
    const itemsList = document.getElementById('localReturnItemsList');
    if (!itemsList) return;
    
    itemsList.innerHTML = '';
    
    if (localSelectedItemsForReturn.length === 0) {
        itemsList.innerHTML = '<tr><td colspan="6" class="text-center text-muted">لا توجد منتجات محددة</td></tr>';
        return;
    }
    
    let totalRefund = 0;
    
    localSelectedItemsForReturn.forEach(function(item, index) {
        const itemQuantity = item.quantity || item.available_to_return;
        const itemTotal = item.unit_price * itemQuantity;
        totalRefund += itemTotal;
        
        const row = document.createElement('tr');
        row.className = 'align-middle';
        row.innerHTML = `
            <td>
                <span class="badge bg-info">${item.invoice_number || '-'}</span>
            </td>
            <td>
                <strong>${item.product_name || '-'}</strong>
            </td>
            <td>
                <input type="number" 
                       class="form-control form-control-sm local-return-quantity text-center fw-bold" 
                       data-index="${index}"
                       value="${itemQuantity.toFixed(2)}"
                       min="0.01"
                       max="${item.available_to_return.toFixed(2)}"
                       step="0.01"
                       onchange="localUpdateReturnQuantity(${index}, this.value)"
                       style="max-width: 120px;">
            </td>
            <td class="text-end">
                <span class="text-muted">${item.unit_price.toFixed(2)}</span> <small class="text-muted">ج.م</small>
            </td>
            <td class="text-end">
                <strong class="text-primary local-return-item-total">${itemTotal.toFixed(2)}</strong> <small class="text-muted">ج.م</small>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="localRemoveReturnItem(${index})" title="إزالة">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        itemsList.appendChild(row);
    });
    
    // تحديث المبلغ الإجمالي
    const totalElement = document.getElementById('localReturnTotalAmount');
    if (totalElement) {
        totalElement.textContent = totalRefund.toFixed(2) + ' ج.م';
    }
    
    // تحديث المبلغ في الكارد العلوي
    const totalCardElement = document.getElementById('localReturnTotalAmountCard');
    if (totalCardElement) {
        totalCardElement.textContent = totalRefund.toFixed(2) + ' ج.م';
    }
    
    // تحديث اسم العميل في modal الإرجاع
    const customerNameElement = document.getElementById('localReturnCustomerName');
    const customerNameCardElement = document.getElementById('localReturnCustomerNameCard');
    if (currentLocalCustomerName) {
        if (customerNameElement) {
            customerNameElement.textContent = currentLocalCustomerName;
        }
        if (customerNameCardElement) {
            customerNameCardElement.textContent = currentLocalCustomerName;
        }
    }
}

// دالة تحديث معلومات طريقة الاسترداد
function updateRefundMethodInfo() {
    const refundMethod = document.querySelector('input[name="localRefundMethod"]:checked')?.value || 'credit';
    // يمكن إضافة معلومات إضافية هنا إذا لزم الأمر
}

// دالة تحديث كمية الإرجاع
function localUpdateReturnQuantity(index, quantity) {
    if (index < 0 || index >= localSelectedItemsForReturn.length) return;
    
    const item = localSelectedItemsForReturn[index];
    const qty = parseFloat(quantity) || 0;
    const maxQty = item.available_to_return;
    
    if (qty > maxQty) {
        alert(`الكمية المتاحة للإرجاع هي ${maxQty.toFixed(2)} فقط`);
        const input = document.querySelector(`.local-return-quantity[data-index="${index}"]`);
        if (input) input.value = maxQty.toFixed(2);
        return;
    }
    
    if (qty <= 0) {
        alert('الكمية يجب أن تكون أكبر من صفر');
        const input = document.querySelector(`.local-return-quantity[data-index="${index}"]`);
        if (input) input.value = maxQty.toFixed(2);
        return;
    }
    
    // تحديث الكمية في المصفوفة
    item.quantity = qty;
    
    // تحديث السعر الإجمالي للعنصر
    const row = document.querySelector(`.local-return-quantity[data-index="${index}"]`).closest('tr');
    const totalCell = row.querySelector('.local-return-item-total');
    if (totalCell) {
        const itemTotal = item.unit_price * qty;
        totalCell.textContent = itemTotal.toFixed(2) + ' ج.م';
    }
    
    // تحديث المبلغ الإجمالي
    let totalRefund = 0;
    localSelectedItemsForReturn.forEach(function(itm) {
        totalRefund += itm.unit_price * (itm.quantity || itm.available_to_return);
    });
    
    const totalElement = document.getElementById('localReturnTotalAmount');
    if (totalElement) {
        totalElement.textContent = totalRefund.toFixed(2) + ' ج.م';
    }
}

// دالة إزالة عنصر من قائمة الإرجاع
function localRemoveReturnItem(index) {
    if (index < 0 || index >= localSelectedItemsForReturn.length) return;
    
    if (confirm('هل أنت متأكد من إزالة هذا المنتج من قائمة الإرجاع؟')) {
        const removedItem = localSelectedItemsForReturn[index];
        localSelectedItemsForReturn.splice(index, 1);
        updateLocalReturnItemsList();
        
        // إخفاء زر الإرجاع إذا لم يعد هناك منتجات
        const returnBtn = document.getElementById('localCustomerReturnBtn');
        if (returnBtn) {
            returnBtn.style.display = localSelectedItemsForReturn.length > 0 ? 'inline-block' : 'none';
        }
        
        // تحديث checkbox في جدول المشتريات
        const checkbox = document.querySelector(`.local-item-checkbox[data-invoice-item-id="${removedItem.invoice_item_id}"]`);
        if (checkbox) {
            checkbox.checked = false;
        }
    }
}

// دالة إرسال طلب الإرجاع
function submitLocalCustomerReturn() {
    if (!currentLocalCustomerId) {
        alert('يرجى فتح سجل مشتريات عميل أولاً');
        return;
    }
    
    if (localSelectedItemsForReturn.length === 0) {
        alert('يرجى تحديد منتج واحد على الأقل للإرجاع');
        return;
    }
    
    const refundMethod = document.querySelector('input[name="localRefundMethod"]:checked')?.value || 'credit';
    const notes = document.getElementById('localReturnNotes')?.value.trim() || '';
    
    // التحقق من الكميات
    const items = [];
    for (let i = 0; i < localSelectedItemsForReturn.length; i++) {
        const item = localSelectedItemsForReturn[i];
        const quantityInput = document.querySelector(`.local-return-quantity[data-index="${i}"]`);
        const quantity = quantityInput ? parseFloat(quantityInput.value) : item.available_to_return;
        
        if (quantity <= 0 || quantity > item.available_to_return) {
            alert(`الكمية غير صحيحة للمنتج: ${item.product_name}`);
            return;
        }
        
        items.push({
            invoice_item_id: item.invoice_item_id,
            quantity: quantity
        });
    }
    
    // تعطيل زر الإرسال
    const submitBtn = document.getElementById('localReturnSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري المعالجة...';
    }
    
    const basePath = '<?php echo getBasePath(); ?>';
    const payload = {
        customer_id: currentLocalCustomerId,
        items: items,
        refund_method: refundMethod,
        notes: notes
    };
    
    fetch(basePath + '/api/local_returns.php?action=create_return', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    })
    .then(response => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('استجابة غير صحيحة من الخادم');
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('تم تسجيل المرتجع بنجاح!\nرقم المرتجع: ' + data.return_number + '\nالمبلغ: ' + data.refund_amount.toFixed(2) + ' ج.م');
            
            // إغلاق modal الإرجاع
            const returnModal = document.getElementById('localCustomerReturnModal');
            if (returnModal) {
                const modal = bootstrap.Modal.getInstance(returnModal);
                if (modal) modal.hide();
            }
            
            // إغلاق modal سجل المشتريات
            const purchaseHistoryModal = document.getElementById('localCustomerPurchaseHistoryModal');
            if (purchaseHistoryModal) {
                const modal = bootstrap.Modal.getInstance(purchaseHistoryModal);
                if (modal) modal.hide();
            }
            
            // إعادة تحميل الصفحة
            window.location.reload();
        } else {
            alert('حدث خطأ: ' + (data.message || 'خطأ غير معروف'));
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تسجيل المرتجع';
            }
        }
    })
    .catch(error => {
        console.error('Error submitting return:', error);
        alert('حدث خطأ في الاتصال بالخادم: ' + error.message);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تسجيل المرتجع';
        }
    });
}

// دالة تحديث كمية الإرجاع
function localUpdateReturnQuantity(index, quantity) {
    if (index < 0 || index >= localSelectedItemsForReturn.length) return;
    
    const item = localSelectedItemsForReturn[index];
    const qty = parseFloat(quantity) || 0;
    const maxQty = item.available_to_return;
    
    if (qty > maxQty) {
        alert(`الكمية المتاحة للإرجاع هي ${maxQty.toFixed(2)} فقط`);
        const input = document.querySelector(`.local-return-quantity[data-index="${index}"]`);
        if (input) input.value = maxQty.toFixed(2);
        return;
    }
    
    if (qty <= 0) {
        alert('الكمية يجب أن تكون أكبر من صفر');
        const input = document.querySelector(`.local-return-quantity[data-index="${index}"]`);
        if (input) input.value = maxQty.toFixed(2);
        return;
    }
    
    // تحديث الكمية في المصفوفة
    item.quantity = qty;
    
    // تحديث السعر الإجمالي للعنصر
    const row = document.querySelector(`.local-return-quantity[data-index="${index}"]`).closest('tr');
    const totalCell = row.querySelector('.local-return-item-total');
    if (totalCell) {
        const itemTotal = item.unit_price * qty;
        totalCell.textContent = itemTotal.toFixed(2) + ' ج.م';
    }
    
    // تحديث المبلغ الإجمالي
    let totalRefund = 0;
    localSelectedItemsForReturn.forEach(function(itm) {
        totalRefund += itm.unit_price * (itm.quantity || itm.available_to_return);
    });
    
    const totalElement = document.getElementById('localReturnTotalAmount');
    if (totalElement) {
        totalElement.textContent = totalRefund.toFixed(2) + ' ج.م';
    }
}

// دالة إزالة عنصر من قائمة الإرجاع
function localRemoveReturnItem(index) {
    if (index < 0 || index >= localSelectedItemsForReturn.length) return;
    
    if (confirm('هل أنت متأكد من إزالة هذا المنتج من قائمة الإرجاع؟')) {
        const removedItem = localSelectedItemsForReturn[index];
        localSelectedItemsForReturn.splice(index, 1);
        updateLocalReturnItemsList();
        
        // إخفاء زر الإرجاع إذا لم يعد هناك منتجات
        const returnBtn = document.getElementById('localCustomerReturnBtn');
        if (returnBtn) {
            returnBtn.style.display = localSelectedItemsForReturn.length > 0 ? 'inline-block' : 'none';
        }
        
        // تحديث checkbox في جدول المشتريات
        const checkbox = document.querySelector(`.local-item-checkbox[data-invoice-item-id="${removedItem.invoice_item_id}"]`);
        if (checkbox) {
            checkbox.checked = false;
        }
    }
}

// دالة إرسال طلب الإرجاع
function submitLocalCustomerReturn() {
    if (!currentLocalCustomerId) {
        alert('يرجى فتح سجل مشتريات عميل أولاً');
        return;
    }
    
    if (localSelectedItemsForReturn.length === 0) {
        alert('يرجى تحديد منتج واحد على الأقل للإرجاع');
        return;
    }
    
    const refundMethod = document.querySelector('input[name="localRefundMethod"]:checked')?.value || 'credit';
    const notes = document.getElementById('localReturnNotes')?.value.trim() || '';
    
    // التحقق من الكميات
    const items = [];
    for (let i = 0; i < localSelectedItemsForReturn.length; i++) {
        const item = localSelectedItemsForReturn[i];
        const quantityInput = document.querySelector(`.local-return-quantity[data-index="${i}"]`);
        const quantity = quantityInput ? parseFloat(quantityInput.value) : item.available_to_return;
        
        if (quantity <= 0 || quantity > item.available_to_return) {
            alert(`الكمية غير صحيحة للمنتج: ${item.product_name}`);
            return;
        }
        
        items.push({
            invoice_item_id: item.invoice_item_id,
            quantity: quantity
        });
    }
    
    // تعطيل زر الإرسال
    const submitBtn = document.getElementById('localReturnSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري المعالجة...';
    }
    
    const basePath = '<?php echo getBasePath(); ?>';
    const payload = {
        customer_id: currentLocalCustomerId,
        items: items,
        refund_method: refundMethod,
        notes: notes
    };
    
    fetch(basePath + '/api/local_returns.php?action=create_return', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    })
    .then(response => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('استجابة غير صحيحة من الخادم');
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('تم تسجيل المرتجع بنجاح!\nرقم المرتجع: ' + data.return_number + '\nالمبلغ: ' + data.refund_amount.toFixed(2) + ' ج.م');
            
            // إغلاق modal الإرجاع
            const returnModal = document.getElementById('localCustomerReturnModal');
            if (returnModal) {
                const modal = bootstrap.Modal.getInstance(returnModal);
                if (modal) modal.hide();
            }
            
            // إغلاق modal سجل المشتريات
            const purchaseHistoryModal = document.getElementById('localCustomerPurchaseHistoryModal');
            if (purchaseHistoryModal) {
                const modal = bootstrap.Modal.getInstance(purchaseHistoryModal);
                if (modal) modal.hide();
            }
            
            // إعادة تحميل الصفحة
            window.location.reload();
        } else {
            alert('حدث خطأ: ' + (data.message || 'خطأ غير معروف'));
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تسجيل المرتجع';
            }
        }
    })
    .catch(error => {
        console.error('Error submitting return:', error);
        alert('حدث خطأ في الاتصال بالخادم: ' + error.message);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تسجيل المرتجع';
        }
    });
}

// دالة تحميل سجل المشتريات للعميل المحلي
function loadLocalCustomerPurchaseHistory() {
    if (!currentLocalCustomerId) {
        return;
    }
    
    const basePath = '<?php echo getBasePath(); ?>';
    const isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    // تحديد العناصر حسب نوع الجهاز
    const loadingElement = isMobileDevice 
        ? document.getElementById('localPurchaseHistoryCardLoading')
        : document.getElementById('localPurchaseHistoryLoading');
    const contentElement = isMobileDevice
        ? document.getElementById('localPurchaseHistoryCardTable')
        : document.getElementById('localPurchaseHistoryTable');
    const errorElement = isMobileDevice
        ? document.getElementById('localPurchaseHistoryCardError')
        : document.getElementById('localPurchaseHistoryError');
    const tableBody = isMobileDevice
        ? document.getElementById('localPurchaseHistoryCardTableBody')
        : document.getElementById('localPurchaseHistoryTableBody');
    
    // إظهار loading وإخفاء المحتوى
    if (loadingElement) loadingElement.classList.remove('d-none');
    if (contentElement) contentElement.classList.add('d-none');
    if (errorElement) {
        errorElement.classList.add('d-none');
        errorElement.textContent = '';
    }
    
    // التأكد من أن currentLocalCustomerId رقم صحيح
    if (!currentLocalCustomerId || isNaN(currentLocalCustomerId) || currentLocalCustomerId <= 0) {
        console.error('Invalid customer ID:', currentLocalCustomerId);
        if (errorElement) {
            errorElement.textContent = 'خطأ: معرف العميل غير صالح';
            errorElement.classList.remove('d-none');
        }
        if (loadingElement) loadingElement.classList.add('d-none');
        return;
    }
    
    // جلب البيانات من API (سنستخدم نفس API مع تحديد نوع العميل)
    const apiUrl = basePath + '/api/customer_purchase_history.php?action=get_history&customer_id=' + encodeURIComponent(currentLocalCustomerId) + '&type=local';
    console.log('Loading purchase history for customer ID:', currentLocalCustomerId, 'URL:', apiUrl);
    
    fetch(apiUrl, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('استجابة غير صحيحة من الخادم');
            });
        }
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
            });
        }
        return response.json();
    })
    .then(data => {
        if (loadingElement) loadingElement.classList.add('d-none');
        
        if (data.success && data.purchase_history) {
            localPurchaseHistoryData = data.purchase_history || [];
            displayLocalPurchaseHistory(localPurchaseHistoryData);
            if (contentElement) contentElement.classList.remove('d-none');
            
            // إظهار زر الطباعة
            const printBtn = isMobileDevice
                ? document.getElementById('printLocalCustomerStatementCardBtn')
                : document.getElementById('printLocalCustomerStatementBtn');
            if (printBtn) printBtn.style.display = 'inline-block';
        } else {
            if (errorElement) {
                errorElement.textContent = data.message || 'حدث خطأ أثناء تحميل سجل المشتريات';
                errorElement.classList.remove('d-none');
            }
        }
    })
    .catch(error => {
        if (loadingElement) loadingElement.classList.add('d-none');
        if (errorElement) {
            errorElement.textContent = 'خطأ: ' + (error.message || 'حدث خطأ في الاتصال بالخادم');
            errorElement.classList.remove('d-none');
        }
        console.error('Error loading purchase history:', error);
    });
}

// دالة عرض سجل المشتريات
function displayLocalPurchaseHistory(history) {
    const isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    const tableBody = isMobileDevice
        ? document.getElementById('localPurchaseHistoryCardTableBody')
        : document.getElementById('localPurchaseHistoryTableBody');
    
    if (!tableBody) {
        console.error('Purchase history table body element not found');
        return;
    }
    
    tableBody.innerHTML = '';
    
    if (!history || history.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4"><i class="bi bi-info-circle me-2"></i>لا توجد مشتريات مسجلة لهذا العميل</td></tr>';
        return;
    }
    
    history.forEach(function(item) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                ${item.can_return ? `<input type="checkbox" class="local-item-checkbox" 
                       data-invoice-id="${item.invoice_id}"
                       data-invoice-item-id="${item.invoice_item_id}"
                       data-product-id="${item.product_id}"
                       data-product-name="${item.product_name}"
                       data-unit-price="${item.unit_price}"
                       data-batch-number-ids='${JSON.stringify(item.batch_number_ids || [])}'
                       data-batch-numbers='${JSON.stringify(item.batch_numbers || [])}'
                       onchange="localUpdateSelectedItems()">` : '-'}
            </td>
            <td>${item.invoice_number || '-'}</td>
            <td>${item.batch_numbers ? (Array.isArray(item.batch_numbers) ? item.batch_numbers.join(', ') : item.batch_numbers) : '-'}</td>
            <td>${item.product_name || '-'}</td>
            <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
            <td>${parseFloat(item.returned_quantity || 0).toFixed(2)}</td>
            <td><strong>${parseFloat(item.available_to_return || 0).toFixed(2)}</strong></td>
            <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
            <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
            <td>${item.invoice_date || '-'}</td>
            <td>
                ${item.can_return ? `<button class="btn btn-sm btn-primary" 
                        onclick="localSelectItemForReturn(${item.invoice_item_id}, ${item.product_id})"
                        title="إرجاع جزئي">
                    <i class="bi bi-arrow-return-left"></i>
                </button>` : '<span class="text-muted small">-</span>'}
            </td>
        `;
        tableBody.appendChild(row);
    });
}

// دوال مساعدة
function localToggleAllItems() {
    const isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    const selectAll = isMobileDevice
        ? document.getElementById('localSelectAllItemsCard')
        : document.getElementById('localSelectAllItems');
    const checkboxes = document.querySelectorAll('.local-item-checkbox');
    
    if (selectAll) {
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = selectAll.checked;
        });
    }
    
    localUpdateSelectedItems();
}

function localUpdateSelectedItems() {
    const checkboxes = document.querySelectorAll('.local-item-checkbox:checked');
    localSelectedItemsForReturn = [];
    
    checkboxes.forEach(function(checkbox) {
        const row = checkbox.closest('tr');
        const available = parseFloat(row.querySelector('td:nth-child(7)').textContent.trim());
        const invoiceNumber = row.querySelector('td:nth-child(2)').textContent.trim();
        
        const invoiceItemId = parseInt(checkbox.dataset.invoiceItemId);
        let latestAvailable = available;
        
        if (localPurchaseHistoryData && localPurchaseHistoryData.length > 0) {
            const historyItem = localPurchaseHistoryData.find(function(h) {
                return h.invoice_item_id === invoiceItemId;
            });
            if (historyItem) {
                latestAvailable = parseFloat(historyItem.available_to_return) || 0;
            }
        }
        
        if (latestAvailable > 0) {
            localSelectedItemsForReturn.push({
                invoice_id: parseInt(checkbox.dataset.invoiceId),
                invoice_number: invoiceNumber,
                invoice_item_id: invoiceItemId,
                product_id: parseInt(checkbox.dataset.productId),
                product_name: checkbox.dataset.productName,
                unit_price: parseFloat(checkbox.dataset.unitPrice),
                batch_number_ids: JSON.parse(checkbox.dataset.batchNumberIds || '[]'),
                batch_numbers: JSON.parse(checkbox.dataset.batchNumbers || '[]'),
                available_to_return: latestAvailable
            });
        }
    });
    
    const isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    const returnBtn = isMobileDevice
        ? document.getElementById('localCustomerReturnCardBtn')
        : document.getElementById('localCustomerReturnBtn');
    if (returnBtn) {
        returnBtn.style.display = localSelectedItemsForReturn.length > 0 ? 'inline-block' : 'none';
    }
}

function localSelectItemForReturn(invoiceItemId, productId) {
    const checkbox = document.querySelector(`.local-item-checkbox[data-invoice-item-id="${invoiceItemId}"][data-product-id="${productId}"]`);
    if (checkbox) {
        checkbox.checked = true;
        localUpdateSelectedItems();
        openLocalCustomerReturnModal();
    }
}

// إعادة تعيين المتغيرات عند إغلاق الـ modal
document.addEventListener('DOMContentLoaded', function() {
    const purchaseHistoryModal = document.getElementById('localCustomerPurchaseHistoryModal');
    if (purchaseHistoryModal) {
        purchaseHistoryModal.addEventListener('hidden.bs.modal', function() {
            if (typeof currentLocalCustomerId !== 'undefined') {
                currentLocalCustomerId = null;
            }
            if (typeof currentLocalCustomerName !== 'undefined') {
                currentLocalCustomerName = null;
            }
            if (typeof localSelectedItemsForReturn !== 'undefined') {
                localSelectedItemsForReturn = [];
            }
            if (typeof localPurchaseHistoryData !== 'undefined') {
                localPurchaseHistoryData = [];
            }
        });
    }
});

// معالج تعديل العميل المحلي - تم نقله إلى دالة showEditLocalCustomerModal
    
    // معالج إضافة منطقة جديدة من نموذج العميل المحلي (للمدير فقط)
    var addRegionFromLocalCustomerForm = document.getElementById('addRegionFromLocalCustomerForm');
    var addRegionFromLocalCustomerModal = document.getElementById('addRegionFromLocalCustomerModal');
    var addLocalCustomerRegionSelect = document.getElementById('addLocalCustomerRegionId');
    var editLocalCustomerRegionSelect = document.getElementById('editLocalCustomerRegionId');
    
    console.log('Local region form elements:', {
        form: addRegionFromLocalCustomerForm,
        modal: addRegionFromLocalCustomerModal,
        addSelect: addLocalCustomerRegionSelect,
        editSelect: editLocalCustomerRegionSelect
    });
    
    if (addRegionFromLocalCustomerForm && addRegionFromLocalCustomerModal) {
        console.log('Setting up add region from local customer form handler');
        addRegionFromLocalCustomerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Add region from local customer form submitted');
            
            var regionNameInput = document.getElementById('newLocalRegionName');
            var regionName = regionNameInput ? regionNameInput.value.trim() : '';
            var messageDiv = document.getElementById('addLocalRegionMessage');
            var submitBtn = document.getElementById('addLocalRegionSubmitBtn');
            var spinner = document.getElementById('addLocalRegionSpinner');
            
            if (!regionName) {
                if (messageDiv) {
                    messageDiv.className = 'alert alert-danger';
                    messageDiv.textContent = 'يجب إدخال اسم المنطقة';
                    messageDiv.classList.remove('d-none');
                }
                return;
            }
            
            // إظهار loading
            if (submitBtn) submitBtn.disabled = true;
            if (spinner) spinner.classList.remove('d-none');
            if (messageDiv) messageDiv.classList.add('d-none');
            
            // إرسال طلب AJAX
            var formData = new FormData();
            formData.append('action', 'add_region_ajax');
            formData.append('name', regionName);
            
            console.log('Sending AJAX request to add region:', regionName);
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                console.log('Response data:', data);
                if (submitBtn) submitBtn.disabled = false;
                if (spinner) spinner.classList.add('d-none');
                
                if (data && data.success) {
                    console.log('Region added successfully:', data.region);
                    // إضافة المنطقة الجديدة إلى select
                    var newOption = document.createElement('option');
                    newOption.value = data.region.id;
                    newOption.textContent = data.region.name;
                    newOption.selected = true;
                    
                    if (addLocalCustomerRegionSelect) {
                        addLocalCustomerRegionSelect.appendChild(newOption);
                        addLocalCustomerRegionSelect.value = data.region.id;
                    }
                    
                    if (editLocalCustomerRegionSelect) {
                        var editOption = newOption.cloneNode(true);
                        editLocalCustomerRegionSelect.appendChild(editOption);
                    }
                    
                    // إظهار رسالة نجاح
                    if (messageDiv) {
                        messageDiv.className = 'alert alert-success';
                        messageDiv.textContent = data.message || 'تم إضافة المنطقة بنجاح';
                        messageDiv.classList.remove('d-none');
                    }
                    
                    // إغلاق modal بعد ثانيتين
                    setTimeout(function() {
                        if (addRegionFromLocalCustomerModal) {
                            var modal = bootstrap.Modal.getInstance(addRegionFromLocalCustomerModal);
                            if (modal) {
                                modal.hide();
                            }
                        }
                        // مسح الحقول
                        if (regionNameInput) regionNameInput.value = '';
                        if (messageDiv) messageDiv.classList.add('d-none');
                    }, 1500);
                } else {
                    if (messageDiv) {
                        messageDiv.className = 'alert alert-danger';
                        messageDiv.textContent = (data && data.message) ? data.message : 'حدث خطأ أثناء إضافة المنطقة';
                        messageDiv.classList.remove('d-none');
                    }
                }
            })
            .catch(function(error) {
                if (submitBtn) submitBtn.disabled = false;
                if (spinner) spinner.classList.add('d-none');
                console.error('Error adding region:', error);
                if (messageDiv) {
                    messageDiv.className = 'alert alert-danger';
                    messageDiv.textContent = 'حدث خطأ أثناء الاتصال بالخادم: ' + error.message;
                    messageDiv.classList.remove('d-none');
                }
            });
        });
    } else {
        if (!addRegionFromLocalCustomerForm) {
            console.warn('Add region from local customer form not found');
        }
        if (!addRegionFromLocalCustomerModal) {
            console.warn('Add region from local customer modal not found');
        }
    }
    
    // معالج استيراد العملاء المحليين من CSV
    var importLocalCustomersForm = document.getElementById('importLocalCustomersForm');
    var importLocalCustomersModal = document.getElementById('importLocalCustomersModal');
    
    if (importLocalCustomersForm) {
        importLocalCustomersForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var fileInput = document.getElementById('localExcelFileInput');
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                alert('يرجى اختيار ملف CSV أو Excel');
                return;
            }
            
            var file = fileInput.files[0];
            if (file.size > 10 * 1024 * 1024) {
                alert('حجم الملف يجب ألا يتجاوز 10 ميجابايت');
                return;
            }
            
            var formData = new FormData();
            formData.append('excel_file', file);
            formData.append('action', 'import_local_customers');
            
            // إظهار شريط التقدم
            var progressDiv = document.getElementById('localImportProgress');
            var progressBar = progressDiv.querySelector('.progress-bar');
            var statusDiv = document.getElementById('localImportStatus');
            var resultsDiv = document.getElementById('localImportResults');
            var errorsDiv = document.getElementById('localImportErrors');
            var submitBtn = document.getElementById('localImportSubmitBtn');
            
            progressDiv.classList.remove('d-none');
            resultsDiv.classList.add('d-none');
            errorsDiv.classList.add('d-none');
            progressBar.style.width = '0%';
            statusDiv.textContent = 'جاري رفع الملف...';
            submitBtn.disabled = true;
            
            fetch('<?php echo getRelativeUrl("api/import_local_customers.php"); ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                // قراءة النص أولاً للتحقق من نوع المحتوى
                return response.text().then(text => {
                    // محاولة parse JSON
                    try {
                        const data = JSON.parse(text);
                        if (!response.ok) {
                            throw new Error(data.message || 'خطأ في الخادم: ' + response.status);
                        }
                        return data;
                    } catch (parseError) {
                        // إذا لم يكن JSON، قد يكون HTML error page
                        if (!response.ok) {
                            throw new Error('خطأ في الخادم: ' + response.status + ' ' + response.statusText + (text ? ' - ' + text.substring(0, 200) : ''));
                        }
                        throw new Error('استجابة غير صحيحة من الخادم');
                    }
                });
            })
            .then(data => {
                if (progressBar) progressBar.style.width = '100%';
                
                if (data.success) {
                    if (statusDiv) {
                        statusDiv.textContent = 'تم الاستيراد بنجاح!';
                        statusDiv.className = 'text-center text-success';
                    }
                    
                    var resultsContent = document.getElementById('localImportResultsContent');
                    if (resultsContent) {
                        var html = '<ul class="mb-0">';
                        html += '<li>تم استيراد: <strong>' + (data.imported || 0) + '</strong> عميل محلي</li>';
                        if (data.skipped && data.skipped > 0) {
                            html += '<li>تم تخطي: <strong>' + data.skipped + '</strong> عميل (مكرر)</li>';
                        }
                        if (data.errors && data.errors.length > 0) {
                            html += '<li>أخطاء: <strong>' + data.errors.length + '</strong> سطر</li>';
                        }
                        html += '</ul>';
                        resultsContent.innerHTML = html;
                    }
                    if (resultsDiv) {
                        resultsDiv.classList.remove('d-none');
                    }
                    
                    // إعادة تحميل الصفحة بعد 2 ثانية
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    if (statusDiv) {
                        statusDiv.textContent = 'فشل الاستيراد';
                        statusDiv.className = 'text-center text-danger';
                    }
                    
                    var errorsContent = document.getElementById('localImportErrorsContent');
                    if (errorsContent) {
                        var html = '<p>' + (data.message || 'حدث خطأ أثناء الاستيراد') + '</p>';
                        if (data.errors && data.errors.length > 0) {
                            html += '<ul class="mb-0"><li>' + data.errors.join('</li><li>') + '</li></ul>';
                        }
                        errorsContent.innerHTML = html;
                    }
                    if (errorsDiv) {
                        errorsDiv.classList.remove('d-none');
                    }
                }
                
                if (submitBtn) submitBtn.disabled = false;
            })
            .catch(error => {
                console.error('Import error:', error);
                if (statusDiv) {
                    statusDiv.textContent = 'حدث خطأ في الاتصال بالخادم';
                    statusDiv.className = 'text-center text-danger';
                }
                if (errorsDiv) {
                    errorsDiv.classList.remove('d-none');
                }
                var errorMessage = error.message || 'حدث خطأ غير معروف';
                var errorsContent = document.getElementById('localImportErrorsContent');
                if (errorsContent) {
                    errorsContent.innerHTML = '<p>' + errorMessage + '</p><p class="text-muted small mt-2">يرجى التحقق من ملف error_log في الخادم لمزيد من التفاصيل</p>';
                } else {
                    console.error('localImportErrorsContent element not found');
                    alert('حدث خطأ: ' + errorMessage);
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            });
        });
    }
    
    // إعادة تعيين النموذج عند إغلاق الـ Modal
    if (importLocalCustomersModal) {
        importLocalCustomersModal.addEventListener('hidden.bs.modal', function() {
            if (importLocalCustomersForm) {
                importLocalCustomersForm.reset();
            }
            var progressDiv = document.getElementById('localImportProgress');
            var resultsDiv = document.getElementById('localImportResults');
            var errorsDiv = document.getElementById('localImportErrors');
            if (progressDiv) progressDiv.classList.add('d-none');
            if (resultsDiv) resultsDiv.classList.add('d-none');
            if (errorsDiv) errorsDiv.classList.add('d-none');
        });
    }
    
    // معالج استيراد العملاء المحليين من CSV - للـ Card
    var importLocalCustomersCardForm = document.getElementById('importLocalCustomersCardForm');
    
    if (importLocalCustomersCardForm) {
        importLocalCustomersCardForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var fileInput = document.getElementById('localExcelFileInputCard');
            if (!fileInput || !fileInput.files || !fileInput.files.length) {
                alert('يرجى اختيار ملف CSV أو Excel');
                return;
            }
            
            var file = fileInput.files[0];
            if (file.size > 10 * 1024 * 1024) {
                alert('حجم الملف يجب ألا يتجاوز 10 ميجابايت');
                return;
            }
            
            var formData = new FormData();
            formData.append('excel_file', file);
            formData.append('action', 'import_local_customers');
            
            // إظهار شريط التقدم
            var progressDiv = document.getElementById('localImportProgressCard');
            var progressBar = progressDiv ? progressDiv.querySelector('.progress-bar') : null;
            var statusDiv = document.getElementById('localImportStatusCard');
            var resultsDiv = document.getElementById('localImportResultsCard');
            var errorsDiv = document.getElementById('localImportErrorsCard');
            var submitBtn = document.getElementById('localImportSubmitBtnCard');
            
            if (progressDiv) progressDiv.classList.remove('d-none');
            if (resultsDiv) resultsDiv.classList.add('d-none');
            if (errorsDiv) errorsDiv.classList.add('d-none');
            if (progressBar) progressBar.style.width = '0%';
            if (statusDiv) statusDiv.textContent = 'جاري رفع الملف...';
            if (submitBtn) submitBtn.disabled = true;
            
            fetch('<?php echo getRelativeUrl("api/import_local_customers.php"); ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                // قراءة النص أولاً للتحقق من نوع المحتوى
                return response.text().then(text => {
                    // محاولة parse JSON
                    try {
                        const data = JSON.parse(text);
                        if (!response.ok) {
                            throw new Error(data.message || 'خطأ في الخادم: ' + response.status);
                        }
                        return data;
                    } catch (parseError) {
                        // إذا لم يكن JSON، قد يكون HTML error page
                        if (!response.ok) {
                            throw new Error('خطأ في الخادم: ' + response.status + ' ' + response.statusText + (text ? ' - ' + text.substring(0, 200) : ''));
                        }
                        throw new Error('استجابة غير صحيحة من الخادم');
                    }
                });
            })
            .then(data => {
                console.log('Import Card response data:', data);
                if (progressBar) progressBar.style.width = '100%';
                
                if (data && data.success) {
                    if (statusDiv) {
                        statusDiv.textContent = 'تم الاستيراد بنجاح!';
                        statusDiv.className = 'text-center text-success';
                    }
                    
                    var resultsContent = document.getElementById('localImportResultsContentCard');
                    if (resultsContent) {
                        var html = '<ul class="mb-0">';
                        html += '<li>تم استيراد: <strong>' + (data.imported || 0) + '</strong> عميل محلي</li>';
                        if (data.skipped && data.skipped > 0) {
                            html += '<li>تم تخطي: <strong>' + data.skipped + '</strong> عميل (مكرر)</li>';
                        }
                        if (data.errors && data.errors.length > 0) {
                            html += '<li>أخطاء: <strong>' + data.errors.length + '</strong> سطر</li>';
                        }
                        html += '</ul>';
                        resultsContent.innerHTML = html;
                    }
                    if (resultsDiv) resultsDiv.classList.remove('d-none');
                    
                    // إعادة تحميل الصفحة بعد 2 ثانية
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    if (statusDiv) {
                        statusDiv.textContent = 'فشل الاستيراد';
                        statusDiv.className = 'text-center text-danger';
                    }
                    
                    var errorsContent = document.getElementById('localImportErrorsContentCard');
                    if (errorsContent) {
                        var html = '<p>' + (data.message || 'حدث خطأ أثناء الاستيراد') + '</p>';
                        if (data.errors && data.errors.length > 0) {
                            html += '<ul class="mb-0"><li>' + data.errors.join('</li><li>') + '</li></ul>';
                        }
                        errorsContent.innerHTML = html;
                    }
                    if (errorsDiv) errorsDiv.classList.remove('d-none');
                }
                
                if (submitBtn) submitBtn.disabled = false;
            })
            .catch(error => {
                console.error('Import Card error:', error);
                console.error('Error details:', {
                    message: error.message,
                    stack: error.stack,
                    name: error.name
                });
                if (statusDiv) {
                    statusDiv.textContent = 'حدث خطأ في الاتصال بالخادم';
                    statusDiv.className = 'text-center text-danger';
                }
                if (errorsDiv) errorsDiv.classList.remove('d-none');
                var errorMessage = error.message || 'حدث خطأ غير معروف';
                var errorsContent = document.getElementById('localImportErrorsContentCard');
                if (errorsContent) {
                    errorsContent.innerHTML = '<p><strong>خطأ:</strong> ' + errorMessage + '</p><p class="text-muted small mt-2">يرجى التحقق من ملف error_log في الخادم لمزيد من التفاصيل</p>';
                } else {
                    console.error('localImportErrorsContentCard element not found');
                    alert('حدث خطأ: ' + errorMessage);
                }
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }
});

// إدارة أرقام الهواتف المتعددة
(function() {
    // للنموذج الإضافة
    const addPhoneBtn = document.getElementById('addPhoneBtn');
    const phoneContainer = document.getElementById('phoneNumbersContainer');
    
    if (addPhoneBtn && phoneContainer) {
        // إضافة رقم هاتف جديد
        addPhoneBtn.addEventListener('click', function() {
            const phoneInputGroup = document.createElement('div');
            phoneInputGroup.className = 'input-group mb-2';
            phoneInputGroup.innerHTML = `
                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                <button type="button" class="btn btn-outline-danger remove-phone-btn">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            phoneContainer.appendChild(phoneInputGroup);
            
            // إظهار أزرار الحذف
            updateRemoveButtons(phoneContainer);
        });
        
        // حذف رقم هاتف
        phoneContainer.addEventListener('click', function(e) {
            if (e.target.closest('.remove-phone-btn')) {
                e.target.closest('.input-group').remove();
                updateRemoveButtons(phoneContainer);
            }
        });
    }
    
    // للـ Card على الموبايل
    const addCustomerCardPhoneBtn = document.getElementById('addCustomerCardPhoneBtn');
    const cardPhoneContainer = document.getElementById('addCustomerCardPhoneNumbersContainer');
    
    if (addCustomerCardPhoneBtn && cardPhoneContainer) {
        // إضافة رقم هاتف جديد
        addCustomerCardPhoneBtn.addEventListener('click', function() {
            const phoneInputGroup = document.createElement('div');
            phoneInputGroup.className = 'input-group mb-2';
            phoneInputGroup.innerHTML = `
                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                <button type="button" class="btn btn-outline-danger remove-phone-btn">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            cardPhoneContainer.appendChild(phoneInputGroup);
            
            // إظهار أزرار الحذف
            updateRemoveButtons(cardPhoneContainer);
        });
        
        // حذف رقم هاتف
        cardPhoneContainer.addEventListener('click', function(e) {
            if (e.target.closest('.remove-phone-btn')) {
                e.target.closest('.input-group').remove();
                updateRemoveButtons(cardPhoneContainer);
            }
        });
        
        // تحديث عند التحميل
        updateRemoveButtons(cardPhoneContainer);
    }
    
    // للنموذج التعديل
    const addEditPhoneBtn = document.getElementById('addEditPhoneBtn');
    const editPhoneContainer = document.getElementById('editPhoneNumbersContainer');
    
    if (addEditPhoneBtn && editPhoneContainer) {
        // إضافة رقم هاتف جديد
        addEditPhoneBtn.addEventListener('click', function() {
            const phoneInputGroup = document.createElement('div');
            phoneInputGroup.className = 'input-group mb-2';
            phoneInputGroup.innerHTML = `
                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                <button type="button" class="btn btn-outline-danger remove-phone-btn">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            editPhoneContainer.appendChild(phoneInputGroup);
            
            // إظهار أزرار الحذف
            updateEditRemoveButtons();
        });
        
        // حذف رقم هاتف
        editPhoneContainer.addEventListener('click', function(e) {
            if (e.target.closest('.remove-phone-btn')) {
                e.target.closest('.input-group').remove();
                updateEditRemoveButtons();
            }
        });
    }
    
    // تحديث حالة أزرار الحذف للنموذج الإضافة
    function updateRemoveButtons(container) {
        const phoneGroups = container.querySelectorAll('.input-group');
        phoneGroups.forEach((group, index) => {
            const removeBtn = group.querySelector('.remove-phone-btn');
            if (removeBtn) {
                removeBtn.style.display = phoneGroups.length > 1 ? 'block' : 'none';
            }
        });
    }
    
    // تحديث حالة أزرار الحذف للنموذج التعديل
    function updateEditRemoveButtons() {
        if (editPhoneContainer) {
            const phoneGroups = editPhoneContainer.querySelectorAll('.input-group');
            phoneGroups.forEach((group, index) => {
                const removeBtn = group.querySelector('.remove-phone-btn');
                if (removeBtn) {
                    removeBtn.style.display = phoneGroups.length > 1 ? 'block' : 'none';
                }
            });
        }
    }
    
    // جعل الدالة متاحة عالمياً
    window.updateEditRemoveButtons = updateEditRemoveButtons;
    
    // تحديث عند التحميل
    if (phoneContainer) {
        updateRemoveButtons(phoneContainer);
    }
})();
</script>

<!-- Modal تعديل عميل محلي - للكمبيوتر فقط -->
<?php if (in_array($currentRole, ['manager', 'accountant', 'sales'], true)): ?>
<div class="modal fade d-none d-md-block" id="editLocalCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل بيانات العميل المحلي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="edit_customer">
                <input type="hidden" name="customer_id" id="editLocalCustomerId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل</label>
                        <input type="text" class="form-control" id="editLocalCustomerName" disabled>
                        <small class="text-muted">لا يمكن تعديل اسم العميل</small>
                    </div>
                    <?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
                    <div class="mb-3">
                        <label class="form-label">ديون العميل / رصيد العميل</label>
                        <input type="number" class="form-control" name="balance" id="editLocalCustomerBalance" step="0.01" placeholder="مثال: 0 أو -500">
                        <small class="text-muted">
                            <strong>إدخال قيمة سالبة:</strong> يتم اعتبارها رصيد دائن للعميل (مبلغ متاح للعميل).
                        </small>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">أرقام الهاتف</label>
                        <div id="editPhoneNumbersContainer">
                            <div class="input-group mb-2">
                                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addEditPhoneBtn">
                            <i class="bi bi-plus-circle"></i> إضافة رقم آخر
                        </button>
                        <input type="hidden" name="phone" id="editLocalCustomerPhone" value="">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea class="form-control" name="address" id="editLocalCustomerAddress" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المنطقة</label>
                        <div class="input-group">
                            <select class="form-select" name="region_id" id="editLocalCustomerRegionId">
                                <option value="">اختر المنطقة</option>
                                <?php
                                $regions = $db->query("SELECT id, name FROM regions ORDER BY name ASC");
                                foreach ($regions as $region):
                                ?>
                                    <option value="<?php echo $region['id']; ?>"><?php echo htmlspecialchars($region['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRegionFromLocalCustomerModal">
                                <i class="bi bi-plus-circle"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card تعديل عميل محلي - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="editLocalCustomerCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">تعديل بيانات العميل المحلي</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
            <input type="hidden" name="action" value="edit_customer">
            <input type="hidden" name="customer_id" id="editLocalCustomerCardId">
            <div class="mb-3">
                <label class="form-label">اسم العميل</label>
                <input type="text" class="form-control" id="editLocalCustomerCardName" disabled>
                <small class="text-muted">لا يمكن تعديل اسم العميل</small>
            </div>
            <?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
            <div class="mb-3">
                <label class="form-label">ديون العميل / رصيد العميل</label>
                <input type="number" class="form-control" name="balance" id="editLocalCustomerCardBalance" step="0.01" placeholder="مثال: 0 أو -500">
            </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label">أرقام الهاتف</label>
                <div id="editLocalCustomerCardPhoneNumbersContainer">
                    <div class="input-group mb-2">
                        <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                        <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addEditLocalCustomerCardPhoneBtn">
                    <i class="bi bi-plus-circle"></i> إضافة رقم آخر
                </button>
                <input type="hidden" name="phone" id="editLocalCustomerCardPhone" value="">
            </div>
            <div class="mb-3">
                <label class="form-label">العنوان</label>
                <textarea class="form-control" name="address" id="editLocalCustomerCardAddress" rows="2"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">المنطقة</label>
                <div class="input-group">
                    <select class="form-select" name="region_id" id="editLocalCustomerCardRegionId">
                        <option value="">اختر المنطقة</option>
                        <?php
                        $regions = $db->query("SELECT id, name FROM regions ORDER BY name ASC");
                        foreach ($regions as $region):
                        ?>
                            <option value="<?php echo $region['id']; ?>"><?php echo htmlspecialchars($region['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
                    <button type="button" class="btn btn-outline-primary" onclick="showAddRegionModal()">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditLocalCustomerCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal إضافة منطقة جديدة (من نموذج العميل المحلي) -->
<?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="addRegionFromLocalCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة منطقة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addRegionFromLocalCustomerForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنطقة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="newLocalRegionName" required>
                    </div>
                    <div id="addLocalRegionMessage" class="alert d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary" id="addLocalRegionSubmitBtn">
                        <span class="spinner-border spinner-border-sm d-none me-2" id="addLocalRegionSpinner"></span>
                        إضافة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</script>

<?php endif; ?>

<?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
<!-- Card للموبايل - إضافة منطقة -->
<div class="card shadow-sm mb-4 d-md-none" id="addRegionFromLocalCustomerCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">إضافة منطقة جديدة</h5>
    </div>
    <div class="card-body">
        <form id="addRegionFromLocalCustomerCardForm">
            <div class="mb-3">
                <label class="form-label">اسم المنطقة <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="newLocalRegionCardName" required>
            </div>
            <div id="addLocalRegionCardMessage" class="alert d-none"></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="addLocalRegionCardSubmitBtn">
                    <span class="spinner-border spinner-border-sm d-none me-2" id="addLocalRegionCardSpinner"></span>
                    إضافة
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeAddRegionFromLocalCustomerCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal تصدير العملاء المحددين - للكمبيوتر فقط -->
<?php if (in_array($currentRole, ['manager', 'developer', 'accountant'], true)): ?>
<div class="modal fade d-none d-md-block" id="customerExportModal" tabindex="-1" aria-hidden="true" data-section="local">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-download me-2"></i>تصدير عملاء محددين إلى Excel
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="customer-export-alerts mb-3"></div>
                
                <!-- قائمة العملاء -->
                <div class="mb-3" id="customersSection" style="display: none;">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-2 gap-2">
                        <h6 class="mb-0">حدد العملاء المراد تصديرهم:</h6>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary" id="selectAllCustomers">
                                <i class="bi bi-check-square me-1"></i>تحديد الكل
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="deselectAllCustomers">
                                <i class="bi bi-square me-1"></i>إلغاء التحديد
                            </button>
                        </div>
                    </div>
                    <div id="exportCustomersList" class="table-responsive">
                        <!-- سيتم ملؤه عبر JavaScript -->
                    </div>
                </div>
                
                <!-- رسالة التحميل -->
                <div id="selectRepMessage" class="text-center text-muted py-4">
                    <span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل قائمة العملاء المحليين المدينين...
                </div>
                
                <!-- أزرار الإجراءات بعد التوليد -->
                <div id="exportActionButtons" style="display: none;" class="mt-3 p-3 bg-light rounded">
                    <h6 class="mb-3">تم توليد ملف Excel بنجاح</h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-primary btn-sm" id="printExcelBtn">
                            <i class="bi bi-printer me-2"></i>طباعة
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex flex-column flex-sm-row gap-2">
                <button type="button" class="btn btn-secondary w-100 w-sm-auto" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-primary w-100 w-sm-auto" id="generateExcelBtn" disabled>
                    <i class="bi bi-file-earmark-excel me-2"></i>توليد ملف Excel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Card تصدير العملاء المحددين - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="customerExportCard" style="display: none;">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">
            <i class="bi bi-download me-2"></i>تصدير عملاء محددين إلى Excel
        </h5>
    </div>
    <div class="card-body">
        <div class="customer-export-alerts mb-3"></div>
        
        <!-- قائمة العملاء -->
        <div class="mb-3" id="customersSection" style="display: none;">
            <div class="d-flex flex-column justify-content-between align-items-center mb-2 gap-2">
                <h6 class="mb-0 w-100">حدد العملاء المراد تصديرهم:</h6>
                <div class="btn-group btn-group-sm w-100">
                    <button type="button" class="btn btn-outline-primary" id="selectAllCustomers">
                        <i class="bi bi-check-square me-1"></i>تحديد الكل
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="deselectAllCustomers">
                        <i class="bi bi-square me-1"></i>إلغاء التحديد
                    </button>
                </div>
            </div>
            <div id="exportCustomersList" class="table-responsive">
                <!-- سيتم ملؤه عبر JavaScript -->
            </div>
        </div>
        
        <!-- رسالة التحميل -->
        <div id="selectRepMessage" class="text-center text-muted py-4">
            <span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل قائمة العملاء المحليين المدينين...
        </div>
        
        <!-- أزرار الإجراءات بعد التوليد -->
        <div id="exportActionButtons" style="display: none;" class="mt-3 p-3 bg-light rounded">
            <h6 class="mb-3">تم توليد ملف Excel بنجاح</h6>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-primary btn-sm" id="printExcelBtn">
                    <i class="bi bi-printer me-2"></i>طباعة
                </button>
            </div>
        </div>
        
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-secondary flex-fill" onclick="closeCustomerExportCard()">إغلاق</button>
            <button type="button" class="btn btn-primary flex-fill" id="generateExcelBtn" disabled>
                <i class="bi bi-file-earmark-excel me-2"></i>توليد ملف Excel
            </button>
        </div>
    </div>
</div>

<style>
/* تحسينات responsive للمودال - متوافقة مع responsive-modals.css */
@media (max-width: 768px) {
    /* القواعد العامة للـ modal-dialog موجودة في responsive-modals.css */
    /* نضيف فقط القواعد الخاصة بالجدول والأزرار */
    
    #customerExportModal .table-responsive {
        font-size: 0.875rem;
    }
    
    #customerExportModal .btn-group {
        width: 100%;
    }
    
    #customerExportModal .btn-group .btn {
        flex: 1;
    }
    
    /* padding للـ footer موجود في responsive-modals.css */
    /* نضيف فقط حجم الخط للأزرار */
    #customerExportModal .modal-footer .btn {
        font-size: 0.875rem;
    }
    
    #customerExportModal table {
        font-size: 0.8rem;
    }
    
    #customerExportModal .table th,
    #customerExportModal .table td {
        padding: 0.5rem 0.25rem;
    }
    
    /* إزالة أي backdrop يؤثر على النموذج */
    #customerExportModal.modal.show,
    #customerExportModal.modal.showing {
        pointer-events: auto !important;
        z-index: 1055 !important;
    }
    
    #customerExportModal .modal-dialog {
        pointer-events: auto !important;
        z-index: 1056 !important;
        touch-action: manipulation !important;
    }
    
    #customerExportModal .modal-content {
        pointer-events: auto !important;
        z-index: 1057 !important;
        touch-action: manipulation !important;
    }
    
    #customerExportModal .modal-body,
    #customerExportModal .modal-header,
    #customerExportModal .modal-footer {
        pointer-events: auto !important;
        touch-action: manipulation !important;
    }
    
    #customerExportModal button,
    #customerExportModal input,
    #customerExportModal select,
    #customerExportModal a,
    #customerExportModal .btn,
    #customerExportModal .form-check-input,
    #customerExportModal .page-link {
        pointer-events: auto !important;
        touch-action: manipulation !important;
        -webkit-tap-highlight-color: rgba(0, 123, 255, 0.2) !important;
    }
    
    /* ضمان أن backdrop تحت المودال */
    #customerExportModal + .modal-backdrop,
    .modal-backdrop.show,
    .modal-backdrop {
        z-index: 1054 !important;
    }
    
    /* ضمان أن المودال فوق backdrop وقابل للتفاعل */
    #customerExportModal.modal.show {
        z-index: 1055 !important;
        pointer-events: auto !important;
    }
    
    #customerExportModal .modal-dialog {
        z-index: 1056 !important;
        pointer-events: auto !important;
        position: relative !important;
    }
    
    #customerExportModal .modal-content {
        z-index: 1057 !important;
        pointer-events: auto !important;
        position: relative !important;
    }
    
    /* إزالة أي backdrop إضافي */
    body.modal-open .modal-backdrop ~ .modal-backdrop {
        display: none !important;
        pointer-events: none !important;
    }
    
    /* ضمان أن backdrop واحد فقط موجود */
    body.modal-open .modal-backdrop:not(:first-of-type) {
        display: none !important;
        pointer-events: none !important;
    }
    
    /* إصلاح خاص للجدول داخل المودال */
    #customerExportModal .table-responsive {
        -webkit-overflow-scrolling: touch !important;
        touch-action: pan-y !important;
        pointer-events: auto !important;
    }
    
    #customerExportModal table {
        touch-action: manipulation !important;
        pointer-events: auto !important;
    }
}

/* إزالة backdrop الإضافي على جميع الشاشات */
#customerExportModal + .modal-backdrop ~ .modal-backdrop,
body.modal-open .modal-backdrop:not(:first-of-type) {
    display: none !important;
    pointer-events: none !important;
    z-index: -1 !important;
}

/* الحل الأساسي: ضمان أن المودال قابل للتفاعل دائماً على جميع الشاشات */
#customerExportModal.modal.show,
#customerExportModal.modal.showing {
    pointer-events: auto !important;
    z-index: 1055 !important;
    position: fixed !important;
}

#customerExportModal .modal-dialog {
    pointer-events: auto !important;
    z-index: 1056 !important;
    position: relative !important;
}

#customerExportModal .modal-content {
    pointer-events: auto !important;
    z-index: 1057 !important;
    position: relative !important;
}

/* ضمان أن جميع العناصر داخل المودال قابلة للتفاعل */
#customerExportModal * {
    pointer-events: auto !important;
}

/* backdrop يجب أن يكون تحت المودال */
.modal-backdrop {
    z-index: 1054 !important;
}

/* إصلاح خاص للهواتف - ضمان التفاعل الفوري */
@media (max-width: 768px) {
    #customerExportModal.modal.show,
    #customerExportModal.modal.showing {
        pointer-events: auto !important;
        z-index: 1055 !important;
        position: fixed !important;
        touch-action: manipulation !important;
    }
    
    #customerExportModal .modal-dialog {
        pointer-events: auto !important;
        z-index: 1056 !important;
        position: relative !important;
        touch-action: manipulation !important;
    }
    
    #customerExportModal .modal-content {
        pointer-events: auto !important;
        z-index: 1057 !important;
        position: relative !important;
        touch-action: manipulation !important;
    }
    
    #customerExportModal * {
        pointer-events: auto !important;
        touch-action: manipulation !important;
    }
}

/* تحسينات الأداء على الموبايل */
@media (max-width: 768px) {
    /* تحسين الجدول على الموبايل */
    .dashboard-table-wrapper {
        -webkit-overflow-scrolling: touch;
        overflow-x: auto;
        transform: translateZ(0); /* تفعيل hardware acceleration */
    }
    
    .dashboard-table {
        min-width: 600px; /* عرض أدنى للجدول */
    }
    
    .dashboard-table th,
    .dashboard-table td {
        padding: 0.5rem 0.25rem;
        font-size: 0.875rem;
    }
    
    /* تحسين الأزرار على الموبايل */
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    /* تحسين الكروت */
    .card {
        will-change: transform;
    }
    
    /* تحسين التمرير */
    * {
        -webkit-tap-highlight-color: transparent;
    }
}

/* تحسين الأداء العام */
.dashboard-table-wrapper {
    contain: layout style paint;
}

.dashboard-table tbody tr {
    contain: layout style;
}
</style>

<!-- Customer Export Script -->
<script>
// تمرير المسارات الأساسية من PHP إلى JavaScript
window.CUSTOMER_EXPORT_CONFIG = {
    basePath: '<?php echo getBasePath(); ?>',
    apiBasePath: '<?php echo getRelativeUrl("api"); ?>'
};
</script>
<script src="<?php echo ASSETS_URL; ?>js/customer_export.js?v=<?php echo time(); ?>" defer></script>
<?php endif; ?>

<!-- Modal استيراد العملاء المحليين من CSV -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="importLocalCustomersModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>استيراد العملاء المحليين من ملف CSV
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="importLocalCustomersForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اختر ملف CSV أو Excel <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="excel_file" id="localExcelFileInput" accept=".csv,.xlsx,.xls" required>
                        <small class="text-muted">الحجم الأقصى: 10 ميجابايت | يُفضل استخدام ملف CSV</small>
                    </div>
                    <div id="localImportProgress" class="d-none">
                        <div class="progress mb-3">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div id="localImportStatus" class="text-center"></div>
                    </div>
                    <div id="localImportResults" class="d-none">
                        <div class="alert alert-success">
                            <h6><i class="bi bi-check-circle me-2"></i>تم الاستيراد بنجاح</h6>
                            <div id="localImportResultsContent"></div>
                        </div>
                    </div>
                    <div id="localImportErrors" class="d-none">
                        <div class="alert alert-danger">
                            <h6><i class="bi bi-exclamation-triangle me-2"></i>أخطاء أثناء الاستيراد</h6>
                            <div id="localImportErrorsContent"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success" id="localImportSubmitBtn">
                        <i class="bi bi-upload me-2"></i>استيراد
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card استيراد العملاء المحليين من CSV - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="importLocalCustomersCard" style="display: none;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">
            <i class="bi bi-file-earmark-spreadsheet me-2"></i>استيراد العملاء المحليين من ملف CSV
        </h5>
    </div>
    <div class="card-body">
        <form id="importLocalCustomersCardForm" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">اختر ملف CSV أو Excel <span class="text-danger">*</span></label>
                <input type="file" class="form-control" name="excel_file" id="localExcelFileInputCard" accept=".csv,.xlsx,.xls" required>
                <small class="text-muted">الحجم الأقصى: 10 ميجابايت | يُفضل استخدام ملف CSV</small>
            </div>
            <div id="localImportProgressCard" class="d-none">
                <div class="progress mb-3">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                </div>
                <div id="localImportStatusCard" class="text-center"></div>
            </div>
            <div id="localImportResultsCard" class="d-none">
                <div class="alert alert-success">
                    <h6><i class="bi bi-check-circle me-2"></i>تم الاستيراد بنجاح</h6>
                    <div id="localImportResultsContentCard"></div>
                </div>
            </div>
            <div id="localImportErrorsCard" class="d-none">
                <div class="alert alert-danger">
                    <h6><i class="bi bi-exclamation-triangle me-2"></i>أخطاء أثناء الاستيراد</h6>
                    <div id="localImportErrorsContentCard"></div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-secondary flex-fill" onclick="closeImportLocalCustomersCard()">إلغاء</button>
                <button type="submit" class="btn btn-success flex-fill" id="localImportSubmitBtnCard">
                    <i class="bi bi-upload me-2"></i>استيراد
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ===== CSS مبسط - Modal للكمبيوتر فقط، Card للموبايل ===== */

/* إخفاء Modal على الموبايل */
@media (max-width: 768px) {
    #collectPaymentModal,
    #addLocalCustomerModal,
    #editLocalCustomerModal,
    #localCustomerPurchaseHistoryModal,
    #localCustomerReturnModal,
    #viewLocationModal {
        display: none !important;
    }
}

/* إخفاء Card على الكمبيوتر */
@media (min-width: 769px) {
    #collectPaymentCard,
    #addLocalCustomerCard,
    #editLocalCustomerCard,
    #customerExportCard,
    #viewLocationCard,
    #importLocalCustomersCard {
        display: none !important;
    }
}

/* منع الملفات العامة من التأثير على Modals (على الكمبيوتر فقط) */
#collectPaymentModal,
#addLocalCustomerModal,
#editLocalCustomerModal,
#localCustomerPurchaseHistoryModal,
#localCustomerReturnModal,
#viewLocationModal {
    height: auto !important;
    max-height: none !important;
}

#collectPaymentModal .modal-dialog,
#addLocalCustomerModal .modal-dialog,
#editLocalCustomerModal .modal-dialog,
#localCustomerPurchaseHistoryModal .modal-dialog,
#localCustomerReturnModal .modal-dialog,
#viewLocationModal .modal-dialog {
    display: block !important;
    height: auto !important;
    max-height: none !important;
    margin: 1.75rem auto !important;
}

#collectPaymentModal .modal-content,
#addLocalCustomerModal .modal-content,
#editLocalCustomerModal .modal-content,
#localCustomerPurchaseHistoryModal .modal-content,
#localCustomerReturnModal .modal-content,
#viewLocationModal .modal-content {
    height: auto !important;
    max-height: none !important;
}

#collectPaymentModal .modal-body,
#addLocalCustomerModal .modal-body,
#editLocalCustomerModal .modal-body,
#localCustomerPurchaseHistoryModal .modal-body,
#localCustomerReturnModal .modal-body,
#viewLocationModal .modal-body {
    height: auto !important;
    max-height: none !important;
    overflow-y: visible !important;
}

/* تحسين جداول العملاء على الهواتف */
/* الأزرار في عمود الإجراءات: 2×2 على جميع الشاشات */
.dashboard-table tbody td:last-child .d-flex {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 0.25rem !important;
    width: 100% !important;
}

.dashboard-table tbody td:last-child .btn {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
}

@media (max-width: 767.98px) {
    /* تحسين الجدول الرئيسي للعملاء المحليين */
    .dashboard-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -0.75rem;
        padding: 0 0.75rem;
    }
    
    .dashboard-table {
        min-width: 850px;
        font-size: 0.85rem;
        width: 100%;
        table-layout: fixed;
    }
    
    /* تحديد عرض الأعمدة بناءً على طول المحتوى */
    /* الاسم: 15 حرف */
    .dashboard-table thead th:nth-child(1),
    .dashboard-table tbody td:nth-child(1) {
        width: 20%;
        min-width: 110px;
    }
    
    /* رقم الهاتف: 11 حرف */
    .dashboard-table thead th:nth-child(2),
    .dashboard-table tbody td:nth-child(2) {
        width: 15%;
        min-width: 85px;
    }
    
    /* الرصيد: 7 حرف */
    .dashboard-table thead th:nth-child(3),
    .dashboard-table tbody td:nth-child(3) {
        width: 10%;
        min-width: 65px;
    }
    
    /* العنوان: 8 حرف */
    .dashboard-table thead th:nth-child(4),
    .dashboard-table tbody td:nth-child(4) {
        width: 12%;
        min-width: 75px;
        word-wrap: break-word;
        white-space: normal;
    }
    
    /* المنطقة: 7 حرف */
    .dashboard-table thead th:nth-child(5),
    .dashboard-table tbody td:nth-child(5) {
        width: 10%;
        min-width: 65px;
    }
    
    /* الموقع */
    .dashboard-table thead th:nth-child(6),
    .dashboard-table tbody td:nth-child(6) {
        width: 13%;
        min-width: 90px;
    }
    
    /* الإجراءات */
    .dashboard-table thead th:nth-child(7),
    .dashboard-table tbody td:nth-child(7) {
        width: 20%;
        min-width: 140px;
    }
    
    .dashboard-table thead th {
        font-size: 0.8rem;
        padding: 0.5rem 0.35rem;
        white-space: nowrap;
        font-weight: 600;
    }
    
    .dashboard-table tbody td {
        padding: 0.5rem 0.35rem;
        font-size: 0.8rem;
        vertical-align: middle;
    }
    
    /* تحسين الأزرار في الجدول */
    .dashboard-table .btn {
        font-size: 0.7rem;
        padding: 0.25rem 0.4rem;
        white-space: nowrap;
    }
    
    .dashboard-table .btn i {
        font-size: 0.75rem;
    }
    
    
    /* تحسين Badge الرصيد */
    .dashboard-table .badge {
        font-size: 0.65rem;
        padding: 0.2rem 0.4rem;
    }
    
    /* تحسين عمود الموقع */
    .dashboard-table tbody td:nth-child(6) .d-flex {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.3rem;
    }
    
    .dashboard-table tbody td:nth-child(6) .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 575.98px) {
    .dashboard-table {
        min-width: 800px;
        font-size: 0.8rem;
    }
    
    .dashboard-table thead th {
        font-size: 0.75rem;
        padding: 0.45rem 0.3rem;
    }
    
    .dashboard-table tbody td {
        font-size: 0.75rem;
        padding: 0.45rem 0.3rem;
    }
    
    .dashboard-table .btn {
        font-size: 0.65rem;
        padding: 0.2rem 0.35rem;
    }
    
    .dashboard-table .btn i {
        font-size: 0.7rem;
    }
    
    
    /* عمود العنوان يبقى ظاهراً - مهم جداً */
    .dashboard-table thead th:nth-child(4),
    .dashboard-table tbody td:nth-child(4) {
        display: table-cell !important;
    }
    
    .dashboard-table .badge {
        font-size: 0.6rem;
        padding: 0.15rem 0.35rem;
    }
    
    /* تقليل min-width للأعمدة على الشاشات الصغيرة */
    .dashboard-table thead th:nth-child(1),
    .dashboard-table tbody td:nth-child(1) {
        min-width: 100px;
    }
    
    .dashboard-table thead th:nth-child(2),
    .dashboard-table tbody td:nth-child(2) {
        min-width: 80px;
    }
    
    .dashboard-table thead th:nth-child(3),
    .dashboard-table tbody td:nth-child(3) {
        min-width: 60px;
    }
    
    .dashboard-table thead th:nth-child(4),
    .dashboard-table tbody td:nth-child(4) {
        min-width: 70px;
    }
    
    .dashboard-table thead th:nth-child(5),
    .dashboard-table tbody td:nth-child(5) {
        min-width: 60px;
    }
    
    .dashboard-table thead th:nth-child(6),
    .dashboard-table tbody td:nth-child(6) {
        min-width: 85px;
    }
    
    .dashboard-table thead th:nth-child(7),
    .dashboard-table tbody td:nth-child(7) {
        min-width: 130px;
    }
}

/* ===== تنسيقات نموذج تحصيل من عميل محلي - نفس أبعاد نموذج تحصيل من مندوب ===== */
@media (min-width: 769px) {
    #collectPaymentModal .modal-dialog.modal-dialog-centered {
        margin: 0.5rem auto;
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 1rem);
    }

    #collectPaymentModal .modal-content {
        display: flex !important;
        flex-direction: column !important;
        height: auto !important;
        max-height: 100% !important;
        overflow: hidden !important;
    }

    /* إصلاح المساحة البيضاء - منع modal-body من التمدد */
    #collectPaymentModal .modal-body {
        flex: 0 1 auto !important; /* منع التمدد التلقائي */
        flex-grow: 0 !important;
        flex-shrink: 1 !important;
        flex-basis: auto !important;
        min-height: 0 !important;
        height: auto !important;
        max-height: none !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        padding-bottom: 1rem !important;
        margin-bottom: 0 !important;
    }
}

/* قواعد عامة للـ header والـ footer (لا تتعارض مع media queries) */
#collectPaymentModal .modal-header {
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
}

#collectPaymentModal .modal-footer {
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    border-top: 1px solid #dee2e6 !important;
}

/* padding للـ header والـ footer على الشاشات الكبيرة فقط */
@media (min-width: 769px) {
    #collectPaymentModal .modal-footer {
        padding-top: 1rem !important;
        padding-bottom: 1rem !important;
    }
}

/* إزالة أي pseudo-elements قد تسبب مساحة فارغة */
#collectPaymentModal .modal-content::after,
#collectPaymentModal .modal-content::before {
    display: none !important;
    content: none !important;
}

/* إصلاح خاص لـ modal-dialog-scrollable (للشاشات الكبيرة فقط) */
@media (min-width: 769px) {
    #collectPaymentModal .modal-dialog.modal-dialog-scrollable .modal-content {
        max-height: 100% !important;
        overflow: hidden !important;
    }

    #collectPaymentModal .modal-dialog.modal-dialog-scrollable .modal-body {
        flex: 0 1 auto !important;
        overflow-y: auto !important;
        max-height: calc(100vh - 250px) !important;
    }
}

/* تنسيقات للشاشات الصغيرة */
@media (max-width: 768px) {
    #collectPaymentModal .modal-dialog {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
        max-height: calc(100vh - 1rem) !important;
        height: auto !important;
    }
    
    #collectPaymentModal .modal-content {
        max-height: calc(100vh - 1rem) !important;
        height: auto !important;
    }
    
    #collectPaymentModal .modal-body {
        flex: 0 1 auto !important;
        flex-grow: 0 !important;
        padding-bottom: 1rem !important;
        max-height: none !important;
        height: auto !important;
        overflow-y: visible !important;
    }
    
    #collectPaymentModal .modal-footer {
        flex-shrink: 0 !important;
        flex-grow: 0 !important;
        margin-top: 0 !important;
        padding-top: 1rem !important;
        padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px)) !important;
    }
    
    #collectPaymentModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body {
        overflow-y: visible !important;
        max-height: none !important;
    }
}

/* ===== تنسيقات جميع النماذج الأخرى - نفس أبعاد نموذج تحصيل من مندوب ===== */
/* قائمة النماذج: localCustomerPurchaseHistoryModal, localCustomerReturnModal, 
   editLocalCustomerModal, addRegionFromLocalCustomerModal, customerExportModal, importLocalCustomersModal, 
   viewLocationModal, deleteLocalCustomerModal */
/* ملاحظة: addLocalCustomerModal له قسم خاص منفصل أدناه */

@media (min-width: 769px) {
    #localCustomerPurchaseHistoryModal .modal-dialog.modal-dialog-centered,
    #localCustomerReturnModal .modal-dialog.modal-dialog-centered,
    #editLocalCustomerModal .modal-dialog.modal-dialog-centered,
    #addRegionFromLocalCustomerModal .modal-dialog.modal-dialog-centered,
    #customerExportModal .modal-dialog.modal-dialog-centered,
    #importLocalCustomersModal .modal-dialog.modal-dialog-centered,
    #viewLocationModal .modal-dialog.modal-dialog-centered,
    #deleteLocalCustomerModal .modal-dialog.modal-dialog-centered {
        margin: 0.5rem auto;
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 1rem);
    }

    #localCustomerPurchaseHistoryModal .modal-content,
    #localCustomerReturnModal .modal-content,
    #editLocalCustomerModal .modal-content,
    #addRegionFromLocalCustomerModal .modal-content,
    #customerExportModal .modal-content,
    #importLocalCustomersModal .modal-content,
    #viewLocationModal .modal-content,
    #deleteLocalCustomerModal .modal-content {
        display: flex !important;
        flex-direction: column !important;
        height: auto !important;
        max-height: 100% !important;
        overflow: hidden !important;
    }

    /* إصلاح المساحة البيضاء - منع modal-body من التمدد */
    #localCustomerPurchaseHistoryModal .modal-body,
    #localCustomerReturnModal .modal-body,
    #editLocalCustomerModal .modal-body,
    #addRegionFromLocalCustomerModal .modal-body,
    #customerExportModal .modal-body,
    #importLocalCustomersModal .modal-body,
    #viewLocationModal .modal-body,
    #deleteLocalCustomerModal .modal-body {
        flex: 0 1 auto !important;
        flex-grow: 0 !important;
        flex-shrink: 1 !important;
        flex-basis: auto !important;
        min-height: 0 !important;
        height: auto !important;
        max-height: none !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        padding-bottom: 1rem !important;
        margin-bottom: 0 !important;
    }
}

/* قواعد عامة للـ header والـ footer */
#localCustomerPurchaseHistoryModal .modal-header,
#localCustomerReturnModal .modal-header,
#editLocalCustomerModal .modal-header,
#addRegionFromLocalCustomerModal .modal-header,
#customerExportModal .modal-header,
#importLocalCustomersModal .modal-header,
#viewLocationModal .modal-header,
#deleteLocalCustomerModal .modal-header {
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
}

#localCustomerPurchaseHistoryModal .modal-footer,
#localCustomerReturnModal .modal-footer,
#editLocalCustomerModal .modal-footer,
#addRegionFromLocalCustomerModal .modal-footer,
#customerExportModal .modal-footer,
#importLocalCustomersModal .modal-footer,
#viewLocationModal .modal-footer,
#deleteLocalCustomerModal .modal-footer {
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    border-top: 1px solid #dee2e6 !important;
}

/* padding للـ header والـ footer على الشاشات الكبيرة فقط */
@media (min-width: 769px) {
    #localCustomerPurchaseHistoryModal .modal-footer,
    #localCustomerReturnModal .modal-footer,
    #editLocalCustomerModal .modal-footer,
    #addRegionFromLocalCustomerModal .modal-footer,
    #customerExportModal .modal-footer,
    #importLocalCustomersModal .modal-footer,
    #viewLocationModal .modal-footer,
    #deleteLocalCustomerModal .modal-footer {
        padding-top: 1rem !important;
        padding-bottom: 1rem !important;
    }
}

/* إزالة أي pseudo-elements قد تسبب مساحة فارغة */
#localCustomerPurchaseHistoryModal .modal-content::after,
#localCustomerPurchaseHistoryModal .modal-content::before,
#localCustomerReturnModal .modal-content::after,
#localCustomerReturnModal .modal-content::before,
#editLocalCustomerModal .modal-content::after,
#editLocalCustomerModal .modal-content::before,
#addRegionFromLocalCustomerModal .modal-content::after,
#addRegionFromLocalCustomerModal .modal-content::before,
#customerExportModal .modal-content::after,
#customerExportModal .modal-content::before,
#importLocalCustomersModal .modal-content::after,
#importLocalCustomersModal .modal-content::before,
#viewLocationModal .modal-content::after,
#viewLocationModal .modal-content::before,
#deleteLocalCustomerModal .modal-content::after,
#deleteLocalCustomerModal .modal-content::before {
    display: none !important;
    content: none !important;
}

/* إصلاح خاص لـ modal-dialog-scrollable (للشاشات الكبيرة فقط) */
@media (min-width: 769px) {
    #localCustomerPurchaseHistoryModal .modal-dialog.modal-dialog-scrollable .modal-content,
    #localCustomerReturnModal .modal-dialog.modal-dialog-scrollable .modal-content,
    #editLocalCustomerModal .modal-dialog.modal-dialog-scrollable .modal-content,
    #customerExportModal .modal-dialog.modal-dialog-scrollable .modal-content,
    #importLocalCustomersModal .modal-dialog.modal-dialog-scrollable .modal-content,
    #viewLocationModal .modal-dialog.modal-dialog-scrollable .modal-content,
    #deleteLocalCustomerModal .modal-dialog.modal-dialog-scrollable .modal-content {
        max-height: 100% !important;
        overflow: hidden !important;
    }

    #localCustomerPurchaseHistoryModal .modal-dialog.modal-dialog-scrollable .modal-body,
    #localCustomerReturnModal .modal-dialog.modal-dialog-scrollable .modal-body,
    #editLocalCustomerModal .modal-dialog.modal-dialog-scrollable .modal-body,
    #customerExportModal .modal-dialog.modal-dialog-scrollable .modal-body,
    #importLocalCustomersModal .modal-dialog.modal-dialog-scrollable .modal-body,
    #viewLocationModal .modal-dialog.modal-dialog-scrollable .modal-body,
    #deleteLocalCustomerModal .modal-dialog.modal-dialog-scrollable .modal-body {
        flex: 0 1 auto !important;
        overflow-y: auto !important;
        max-height: calc(100vh - 250px) !important;
    }
}

/* تنسيقات للشاشات الصغيرة */
@media (max-width: 768px) {
    #localCustomerPurchaseHistoryModal .modal-dialog,
    #localCustomerReturnModal .modal-dialog,
    #editLocalCustomerModal .modal-dialog,
    #addRegionFromLocalCustomerModal .modal-dialog,
    #customerExportModal .modal-dialog,
    #importLocalCustomersModal .modal-dialog,
    #viewLocationModal .modal-dialog,
    #deleteLocalCustomerModal .modal-dialog {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
        max-height: calc(100vh - 1rem) !important;
        height: auto !important;
    }
    
    #localCustomerPurchaseHistoryModal .modal-content,
    #localCustomerReturnModal .modal-content,
    #editLocalCustomerModal .modal-content,
    #addRegionFromLocalCustomerModal .modal-content,
    #customerExportModal .modal-content,
    #importLocalCustomersModal .modal-content,
    #viewLocationModal .modal-content,
    #deleteLocalCustomerModal .modal-content {
        max-height: calc(100vh - 1rem) !important;
        height: auto !important;
    }
    
    #localCustomerPurchaseHistoryModal .modal-body,
    #localCustomerReturnModal .modal-body,
    #editLocalCustomerModal .modal-body,
    #addRegionFromLocalCustomerModal .modal-body,
    #customerExportModal .modal-body,
    #importLocalCustomersModal .modal-body,
    #viewLocationModal .modal-body,
    #deleteLocalCustomerModal .modal-body {
        flex: 0 1 auto !important;
        flex-grow: 0 !important;
        padding-bottom: 1rem !important;
        max-height: none !important;
        height: auto !important;
        overflow-y: visible !important;
    }
    
    #localCustomerPurchaseHistoryModal .modal-footer,
    #localCustomerReturnModal .modal-footer,
    #editLocalCustomerModal .modal-footer,
    #addRegionFromLocalCustomerModal .modal-footer,
    #customerExportModal .modal-footer,
    #importLocalCustomersModal .modal-footer,
    #viewLocationModal .modal-footer,
    #deleteLocalCustomerModal .modal-footer {
        flex-shrink: 0 !important;
        flex-grow: 0 !important;
        margin-top: 0 !important;
        padding-top: 1rem !important;
        padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px)) !important;
    }
    
    #localCustomerPurchaseHistoryModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body,
    #localCustomerReturnModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body,
    #editLocalCustomerModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body,
    #addRegionFromLocalCustomerModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body,
    #customerExportModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body,
    #importLocalCustomersModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body,
    #viewLocationModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body,
    #deleteLocalCustomerModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body {
        overflow-y: visible !important;
        max-height: none !important;
    }
}

/* ===== إصلاح نموذج إضافة عميل محلي جديد - تمرير داخلي وإزالة backdrop ===== */
#addLocalCustomerModal .modal-dialog {
    max-height: calc(100vh - 2rem) !important;
    margin: 1rem auto !important;
    display: flex !important;
    flex-direction: column !important;
    height: auto !important;
}

#addLocalCustomerModal .modal-content {
    max-height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    height: auto !important;
}

#addLocalCustomerModal .modal-header {
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
}

#addLocalCustomerModal .modal-body {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    min-height: 0 !important;
    -webkit-overflow-scrolling: touch !important;
    max-height: calc(100vh - 200px) !important;
}

#addLocalCustomerModal .modal-footer {
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
    margin-top: 0 !important;
    border-top: 1px solid #dee2e6 !important;
}

/* على الهاتف */
@media (max-width: 768px) {
    #addLocalCustomerModal .modal-dialog {
        max-height: calc(100vh - 1rem) !important;
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
        height: calc(100vh - 1rem) !important;
    }
    
    #addLocalCustomerModal .modal-content {
        max-height: calc(100vh - 1rem) !important;
        height: 100% !important;
    }
    
    #addLocalCustomerModal .modal-body {
        flex: 1 1 auto !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch !important;
        max-height: calc(100vh - 180px) !important;
        padding-bottom: 1rem !important;
    }
    
    #addLocalCustomerModal .modal-footer {
        padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px)) !important;
        background: white !important;
        flex-shrink: 0 !important;
    }
}

/* إزالة أي backdrop يؤثر على النموذج */
#addLocalCustomerModal.modal.show,
#addLocalCustomerModal.modal.showing {
    pointer-events: auto !important;
    z-index: 1055 !important;
}

#addLocalCustomerModal .modal-dialog {
    pointer-events: auto !important;
    z-index: 1056 !important;
    touch-action: manipulation !important;
}

#addLocalCustomerModal .modal-content {
    pointer-events: auto !important;
    z-index: 1057 !important;
    touch-action: manipulation !important;
}

#addLocalCustomerModal .modal-body,
#addLocalCustomerModal .modal-header,
#addLocalCustomerModal .modal-footer {
    pointer-events: auto !important;
    touch-action: manipulation !important;
}

#addLocalCustomerModal button,
#addLocalCustomerModal input,
#addLocalCustomerModal select,
#addLocalCustomerModal textarea,
#addLocalCustomerModal a,
#addLocalCustomerModal .btn,
#addLocalCustomerModal .form-control,
#addLocalCustomerModal .form-select {
    pointer-events: auto !important;
    touch-action: manipulation !important;
    -webkit-tap-highlight-color: rgba(0, 123, 255, 0.2) !important;
}

/* إزالة backdrop الإضافي الذي يمنع التفاعل */
#addLocalCustomerModal + .modal-backdrop,
.modal-backdrop.show {
    z-index: 1054 !important;
}

/* إزالة أي backdrop إضافي */
body.modal-open #addLocalCustomerModal ~ .modal-backdrop,
body.modal-open .modal-backdrop ~ .modal-backdrop {
    display: none !important;
    pointer-events: none !important;
}

/* ضمان أن backdrop واحد فقط موجود */
body.modal-open .modal-backdrop:not(:first-of-type) {
    display: none !important;
    pointer-events: none !important;
}

</style>

<!-- Modal حذف العميل المحلي -->
<?php if ($currentRole === 'manager'): ?>
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="deleteLocalCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="delete_local_customer">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="bi bi-trash3 me-2"></i>حذف العميل المحلي</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>تحذير:</strong> سيتم حذف العميل المحلي <strong class="delete-local-customer-name">-</strong> وجميع السجلات المرتبطة به بشكل نهائي:
                        <ul class="mb-0 mt-2">
                            <li>جميع الفواتير المحلية (local_invoices)</li>
                            <li>جميع عناصر الفواتير (local_invoice_items)</li>
                            <li>جميع المرتجعات (local_returns)</li>
                            <li>جميع عناصر المرتجعات (local_return_items)</li>
                            <li>جميع التحصيلات (local_collections)</li>
                            <li>جميع أرقام الهواتف (local_customer_phones)</li>
                            <li>سجل المشتريات</li>
                        </ul>
                    </div>
                    <p class="mb-0 text-danger fw-bold">لا يمكن التراجع عن هذه العملية!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card للموبايل - حذف عميل -->
<div class="card shadow-sm mb-4 d-md-none" id="deleteLocalCustomerCard" style="display: none;">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-trash3 me-2"></i>حذف العميل المحلي</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
            <input type="hidden" name="action" value="delete_local_customer">
            <input type="hidden" name="customer_id" id="deleteLocalCustomerCardId" value="">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>تحذير:</strong> سيتم حذف العميل المحلي <strong class="delete-local-customer-card-name">-</strong> وجميع السجلات المرتبطة به بشكل نهائي:
                <ul class="mb-0 mt-2">
                    <li>جميع الفواتير المحلية (local_invoices)</li>
                    <li>جميع عناصر الفواتير (local_invoice_items)</li>
                    <li>جميع المرتجعات (local_returns)</li>
                    <li>جميع عناصر المرتجعات (local_return_items)</li>
                    <li>جميع التحصيلات (local_collections)</li>
                    <li>جميع أرقام الهواتف (local_customer_phones)</li>
                    <li>سجل المشتريات</li>
                </ul>
            </div>
            <p class="mb-3 text-danger fw-bold">لا يمكن التراجع عن هذه العملية!</p>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteLocalCustomerCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal سجل مشتريات العميل المحلي -->
<div class="modal fade" id="localCustomerPurchaseHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-clock-history me-2"></i>
                    سجل مشتريات العميل: <span class="purchase-history-customer-name text-primary">-</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th>رقم الفاتورة</th>
                                <th>التاريخ</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>سعر الوحدة</th>
                                <th>الإجمالي</th>
                                <th>رقم التشغيلة</th>
                            </tr>
                        </thead>
                        <tbody id="purchaseHistoryTableBody">
                            <tr>
                                <td colspan="7" class="text-center text-muted">
                                    <div class="spinner-border spinner-border-sm me-2"></div>
                                    جاري التحميل...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
// ===== دوال فتح النماذج =====

function showAddRegionFromLocalCustomerModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addRegionFromLocalCustomerCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addRegionFromLocalCustomerModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

function showDeleteLocalCustomerModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    const customerId = button.getAttribute('data-customer-id') || '';
    const customerName = button.getAttribute('data-customer-name') || '-';
    
    if (isMobile()) {
        const card = document.getElementById('deleteLocalCustomerCard');
        if (card) {
            document.getElementById('deleteLocalCustomerCardId').value = customerId;
            card.querySelector('.delete-local-customer-card-name').textContent = customerName;
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('deleteLocalCustomerModal');
        if (modal) {
            modal.querySelector('input[name="customer_id"]').value = customerId;
            modal.querySelector('.delete-local-customer-name').textContent = customerName;
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// ===== دوال إغلاق Cards =====

function closeAddRegionFromLocalCustomerCard() {
    const card = document.getElementById('addRegionFromLocalCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeImportLocalCustomersCard() {
    const card = document.getElementById('importLocalCustomersCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeDeleteLocalCustomerCard() {
    const card = document.getElementById('deleteLocalCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var deleteModal = document.getElementById('deleteLocalCustomerModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            if (!button) {
                return;
            }
            var customerId = button.getAttribute('data-customer-id') || '';
            var customerName = button.getAttribute('data-customer-name') || '-';
            var modal = this;
            modal.querySelector('input[name="customer_id"]').value = customerId;
            modal.querySelector('.delete-local-customer-name').textContent = customerName;
        });
    }
    
    // ===== إصلاح مشكلة freeze بعد إغلاق النماذج - تنظيف backdrop و body =====
    // قائمة بجميع النماذج في الصفحة
    const modals = [
        'collectPaymentModal',
        'addLocalCustomerModal',
        'localCustomerPurchaseHistoryModal',
        'localCustomerReturnModal',
        'viewLocationModal',
        'editLocalCustomerModal',
        'addRegionFromLocalCustomerModal',
        'customerExportModal',
        'importLocalCustomersModal',
        'deleteLocalCustomerModal'
    ];
    
    // دالة تنظيف backdrop و body
    function cleanupAfterModalClose() {
        // الانتظار قليلاً للتأكد من اكتمال animation
        setTimeout(function() {
            // التحقق من عدم وجود نماذج مفتوحة أخرى
            const openModals = document.querySelectorAll('.modal.show, .modal.showing');
            
            if (openModals.length === 0) {
                // إزالة جميع backdrops
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(function(backdrop) {
                    backdrop.remove();
                });
                
                // تنظيف body
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
                
                // إزالة أي pointer-events أو touch-action styles يدوية
                document.body.style.pointerEvents = '';
                document.body.style.touchAction = '';
                
                // إزالة أي styles أخرى قد تمنع التفاعل
                document.body.style.position = '';
                document.body.style.height = '';
            }
        }, 300);
    }
    
    // إضافة event listeners لجميع النماذج
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            // عند بدء الإغلاق
            modal.addEventListener('hide.bs.modal', function() {
                // إزالة backdrop فوراً
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(function(backdrop) {
                    backdrop.style.transition = 'opacity 0.15s linear';
                    backdrop.style.opacity = '0';
                });
            });
            
            // بعد الإغلاق الكامل
            modal.addEventListener('hidden.bs.modal', function() {
                cleanupAfterModalClose();
            });
        }
    });
    
    // تنظيف إضافي عند تحميل الصفحة
    setTimeout(function() {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 0) {
            backdrops.forEach(function(backdrop) {
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            document.body.style.pointerEvents = '';
            document.body.style.touchAction = '';
        }
    }, 100);
    
    // ===== ملء بيانات Modals عند فتحها =====
    
    // Modal تعديل عميل
    var editModal = document.getElementById('editLocalCustomerModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            if (!button) return;
            
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name');
            var customerPhone = button.getAttribute('data-customer-phone');
            var customerAddress = button.getAttribute('data-customer-address');
            var regionId = button.getAttribute('data-customer-region-id');
            
            var modal = this;
            var idInput = modal.querySelector('input[name="customer_id"]');
            var nameInput = modal.querySelector('input[name="name"]');
            var addressInput = modal.querySelector('textarea[name="address"]');
            var regionSelect = modal.querySelector('select[name="region_id"]');
            
            if (idInput) idInput.value = customerId || '';
            if (nameInput) nameInput.value = customerName || '';
            if (addressInput) addressInput.value = customerAddress || '';
            if (regionSelect) regionSelect.value = regionId || '';
        });
    }
    
    // Modal تحصيل
    var collectModal = document.getElementById('collectPaymentModal');
    if (collectModal) {
        collectModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            if (!button) return;
            
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name');
            var balance = button.getAttribute('data-customer-balance');
            var balanceFormatted = button.getAttribute('data-customer-balance-formatted');
            
            var modal = this;
            var idInput = modal.querySelector('input[name="customer_id"]');
            var nameEl = modal.querySelector('.collection-customer-name');
            var debtEl = modal.querySelector('.collection-current-debt');
            var amountInput = modal.querySelector('#collectionAmount');
            
            if (idInput) idInput.value = customerId || '';
            if (nameEl) nameEl.textContent = customerName || '';
            if (debtEl) debtEl.textContent = balanceFormatted || '0.00';
            if (amountInput) {
                var numBalance = parseFloat(balance) || 0;
                amountInput.value = numBalance > 0 ? numBalance.toFixed(2) : '0.00';
                amountInput.max = numBalance.toFixed(2);
            }
        });
    }
    
    // Modal سجل المشتريات
    var historyModal = document.getElementById('localCustomerPurchaseHistoryModal');
    if (historyModal) {
        historyModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            if (!button) return;
            
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name');
            
            var modal = this;
            var nameEl = modal.querySelector('.purchase-history-customer-name');
            if (nameEl) nameEl.textContent = customerName || '';
            
            // عرض حالة التحميل
            var tableBody = modal.querySelector('#purchaseHistoryTableBody');
            if (tableBody) {
                tableBody.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm me-2"></div>جاري تحميل سجل المشتريات...</td></tr>';
            }
            
            // جلب سجل المشتريات عبر AJAX
            if (customerId) {
                fetch('/api/customer_purchase_history.php?action=get_history&customer_id=' + customerId + '&type=local')
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('فشل تحميل البيانات');
                        }
                        return response.json();
                    })
                    .then(function(data) {
                        if (!tableBody) {
                            console.error('Table body element not found');
                            return;
                        }
                        
                        if (data.success && data.purchase_history && data.purchase_history.length > 0) {
                            var html = '';
                            data.purchase_history.forEach(function(item) {
                                var batchNumbers = item.batch_numbers && item.batch_numbers.length > 0 
                                    ? item.batch_numbers.join(', ') 
                                    : '-';
                                
                                html += '<tr>';
                                html += '<td>' + (item.invoice_number || '-') + '</td>';
                                html += '<td>' + (item.invoice_date || '-') + '</td>';
                                html += '<td>' + (item.product_name || 'غير معروف') + '</td>';
                                html += '<td>' + item.quantity.toFixed(2) + ' ' + (item.unit || 'قطعة') + '</td>';
                                html += '<td>' + item.unit_price.toFixed(2) + '</td>';
                                html += '<td>' + item.total_price.toFixed(2) + '</td>';
                                html += '<td>' + batchNumbers + '</td>';
                                html += '</tr>';
                            });
                            tableBody.innerHTML = html;
                        } else {
                            tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">لا توجد مشتريات مسجلة لهذا العميل</td></tr>';
                        }
                    })
                    .catch(function(error) {
                        console.error('Error loading purchase history:', error);
                        if (tableBody) {
                            tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-danger"><i class="bi bi-exclamation-triangle me-2"></i>حدث خطأ أثناء تحميل سجل المشتريات</td></tr>';
                        }
                    });
            }
        });
    }
    
    // Modal حذف
    var deleteModal = document.getElementById('deleteLocalCustomerModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            if (!button) return;
            
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name');
            
            var modal = this;
            var idInput = modal.querySelector('input[name="customer_id"]');
            var nameEl = modal.querySelector('.delete-local-customer-name');
            
            if (idInput) idInput.value = customerId || '';
            if (nameEl) nameEl.textContent = customerName || '';
        });
    }
    
    // Modal مرتجعات
    var returnModal = document.getElementById('localCustomerReturnModal');
    if (returnModal) {
        returnModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            if (!button) return;
            
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name');
            
            var modal = this;
            var nameEl = modal.querySelector('#localReturnCustomerName');
            if (nameEl) nameEl.textContent = customerName || '';
            
            console.log('Loading returns for customer:', customerId);
        });
    }
    
    // ===== زر الحصول على الموقع الجغرافي =====
    
    // زر الموقع في Modal
    var getLocationBtn = document.getElementById('getLocationBtn');
    if (getLocationBtn) {
        getLocationBtn.addEventListener('click', function() {
            if (navigator.geolocation) {
                this.disabled = true;
                this.innerHTML = '<i class="bi bi-hourglass-split"></i> جاري الحصول على الموقع...';
                
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        document.getElementById('addCustomerLatitude').value = position.coords.latitude.toFixed(6);
                        document.getElementById('addCustomerLongitude').value = position.coords.longitude.toFixed(6);
                        getLocationBtn.disabled = false;
                        getLocationBtn.innerHTML = '<i class="bi bi-check-circle"></i> تم الحصول على الموقع';
                        setTimeout(function() {
                            getLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i> الحصول على الموقع الحالي';
                        }, 2000);
                    },
                    function(error) {
                        getLocationBtn.disabled = false;
                        getLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i> الحصول على الموقع الحالي';
                        alert('خطأ في الحصول على الموقع: ' + error.message);
                    }
                );
            } else {
                alert('المتصفح لا يدعم خاصية تحديد الموقع الجغرافي');
            }
        });
    }
    
    // زر الموقع في Card
    var getLocationCardBtn = document.getElementById('getLocationCardBtn');
    if (getLocationCardBtn) {
        getLocationCardBtn.addEventListener('click', function() {
            if (navigator.geolocation) {
                this.disabled = true;
                this.innerHTML = '<i class="bi bi-hourglass-split"></i> جاري الحصول...';
                
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        document.getElementById('addCustomerCardLatitude').value = position.coords.latitude.toFixed(6);
                        document.getElementById('addCustomerCardLongitude').value = position.coords.longitude.toFixed(6);
                        getLocationCardBtn.disabled = false;
                        getLocationCardBtn.innerHTML = '<i class="bi bi-check-circle"></i> تم';
                        setTimeout(function() {
                            getLocationCardBtn.innerHTML = '<i class="bi bi-geo-alt"></i> الحصول على الموقع';
                        }, 2000);
                    },
                    function(error) {
                        getLocationCardBtn.disabled = false;
                        getLocationCardBtn.innerHTML = '<i class="bi bi-geo-alt"></i> الحصول على الموقع';
                        alert('خطأ: ' + error.message);
                    }
                );
            } else {
                alert('المتصفح لا يدعم تحديد الموقع');
            }
        });
    }
    
}); // End of DOMContentLoaded
</script>
<?php endif; ?>
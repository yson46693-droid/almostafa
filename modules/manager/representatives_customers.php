<?php

declare(strict_types=1);

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/components/customers/section_header.php';
require_once __DIR__ . '/../../includes/components/customers/rep_card.php';
require_once __DIR__ . '/../sales/table_styles.php';

// التحقق من الصلاحيات فقط إذا لم نكن في وضع معالجة POST
if (!defined('COLLECTION_POST_PROCESSING')) {
    requireRole(['manager', 'accountant', 'developer']);
}

$currentUser = getCurrentUser();
$currentRole = strtolower((string)($currentUser['role'] ?? 'manager'));
$db = db();

// إنشاء جدول customer_phones إذا لم يكن موجوداً (يستخدم نفس الجدول للعملاء)
try {
    $customerPhonesTable = $db->queryOne("SHOW TABLES LIKE 'customer_phones'");
    if (empty($customerPhonesTable)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `customer_phones` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `customer_id` int(11) NOT NULL,
                `phone` varchar(20) NOT NULL,
                `is_primary` tinyint(1) DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `customer_id` (`customer_id`),
                CONSTRAINT `customer_phones_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        error_log('Table customer_phones created successfully');
    }
} catch (Exception $e) {
    error_log('Error creating customer_phones table: ' . $e->getMessage());
}

// التحقق من وجود عمود credit_limit في جدول customers وإنشاؤه إذا لم يكن موجوداً
try {
    $creditLimitColumn = $db->queryOne("SHOW COLUMNS FROM customers LIKE 'credit_limit'");
    if (empty($creditLimitColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(15,2) DEFAULT 0.00 AFTER balance");
        error_log('Column credit_limit added to customers table successfully');
    }
} catch (Exception $e) {
    error_log('Error adding credit_limit column: ' . $e->getMessage());
}

// معالجة get_customer_phones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && trim($_GET['action']) === 'get_customer_phones') {
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
            "SELECT phone FROM customer_phones WHERE customer_id = ? ORDER BY is_primary DESC, id ASC",
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
        error_log('Get customer phones error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب أرقام الهواتف'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// معالجة update_location قبل أي شيء آخر لمنع أي output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'update_location') {
    // تنظيف أي output سابق بشكل كامل
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // التأكد من أن الطلب AJAX
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
        $customer = $db->queryOne("SELECT id, rep_id, created_by FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            throw new InvalidArgumentException('العميل المطلوب غير موجود.');
        }

        // التحقق من الصلاحيات - يمكن للمدير والمحاسب والمطور تحديث موقع أي عميل
        // يمكن للمندوب تحديث موقع عملائه فقط
        $canUpdate = false;
        if (in_array($currentRole, ['manager', 'accountant', 'developer'], true)) {
            $canUpdate = true;
        } elseif ($currentRole === 'sales') {
            $customerRepId = (int)($customer['rep_id'] ?? 0);
            $customerCreatedBy = (int)($customer['created_by'] ?? 0);
            if ($customerRepId > 0 && $customerRepId === (int)$currentUser['id']) {
                $canUpdate = true;
            } elseif ($customerCreatedBy > 0 && $customerCreatedBy === (int)$currentUser['id']) {
                $canUpdate = true;
            }
        }

        if (!$canUpdate) {
            throw new InvalidArgumentException('غير مصرح لك بتحديث موقع هذا العميل.');
        }

        $db->execute(
            "UPDATE customers SET latitude = ?, longitude = ?, location_captured_at = NOW() WHERE id = ?",
            [$latitude, $longitude, $customerId]
        );

        logAudit(
            $currentUser['id'],
            'update_customer_location',
            'customer',
            $customerId,
            null,
            [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]
        );
        
        // مسح الكاش بعد تحديث موقع العميل
        if (class_exists('Cache')) {
            Cache::flush();
        }

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
        error_log('Update customer location error: ' . $updateLocationError->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء حفظ الموقع. حاول مرة أخرى.',
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

// تحديد dashboard script بشكل صريح بناءً على الدور
$dashboardScript = 'manager.php';
if ($currentRole === 'accountant') {
    $dashboardScript = 'accountant.php';
}

// معالجة POST يجب أن تكون في البداية قبل أي شيء آخر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_debt') {
    error_log('=== Collection POST Processing Started in Module ===');
    error_log('Customer ID: ' . ($_POST['customer_id'] ?? 'not set'));
    error_log('Amount: ' . ($_POST['amount'] ?? 'not set'));
    
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $amount = isset($_POST['amount']) ? cleanFinancialValue($_POST['amount']) : 0;
    
    error_log('Parsed - Customer ID: ' . $customerId . ', Amount: ' . $amount);
    
    if ($customerId <= 0) {
        error_log('ERROR: Invalid customer ID');
        $_SESSION['error_message'] = 'معرف العميل غير صالح.';
    } elseif ($amount <= 0) {
        error_log('ERROR: Invalid amount');
        $_SESSION['error_message'] = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
    } else {
        error_log('Starting transaction...');
        $transactionStarted = false;
        
        try {
            $db->beginTransaction();
            $transactionStarted = true;
            
            // جلب بيانات العميل مع معلومات المندوب
            $customer = $db->queryOne(
                "SELECT id, name, balance, created_by, rep_id FROM customers WHERE id = ? FOR UPDATE",
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
            
            // تحديث رصيد العميل
            $db->execute(
                "UPDATE customers SET balance = ? WHERE id = ?",
                [$newBalance, $customerId]
            );
            
            // تحديد المندوب المسؤول عن العميل
            $customerRepId = (int)($customer['rep_id'] ?? 0);
            $customerCreatedBy = (int)($customer['created_by'] ?? 0);
            $salesRepId = null;
            if ($customerRepId > 0) {
                $salesRepId = $customerRepId;
            } elseif ($customerCreatedBy > 0) {
                $repCheck = $db->queryOne(
                    "SELECT id FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                    [$customerCreatedBy]
                );
                if ($repCheck) {
                    $salesRepId = $customerCreatedBy;
                }
            }
            
            // التأكد من وجود جدول accountant_transactions
            $accountantTableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
            if (empty($accountantTableCheck)) {
                $db->execute("
                    CREATE TABLE IF NOT EXISTS `accountant_transactions` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `transaction_type` enum('collection_from_sales_rep','expense','income','transfer','payment','other') NOT NULL,
                      `amount` decimal(15,2) NOT NULL,
                      `sales_rep_id` int(11) DEFAULT NULL,
                      `description` text NOT NULL,
                      `reference_number` varchar(50) DEFAULT NULL,
                      `payment_method` enum('cash','bank_transfer','check','other') DEFAULT 'cash',
                      `status` enum('pending','approved','rejected') DEFAULT 'approved',
                      `approved_by` int(11) DEFAULT NULL,
                      `approved_at` timestamp NULL DEFAULT NULL,
                      `notes` text DEFAULT NULL,
                      `created_by` int(11) NOT NULL,
                      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (`id`),
                      KEY `transaction_type` (`transaction_type`),
                      KEY `sales_rep_id` (`sales_rep_id`),
                      KEY `status` (`status`),
                      KEY `created_by` (`created_by`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // الحصول على اسم المندوب
            $salesRepName = '';
            if ($salesRepId) {
                $salesRep = $db->queryOne(
                    "SELECT id, full_name, username FROM users WHERE id = ? AND role = 'sales'",
                    [$salesRepId]
                );
                $salesRepName = $salesRep ? ($salesRep['full_name'] ?? $salesRep['username'] ?? '') : '';
            }
            
            // توليد رقم مرجعي
            $referenceNumber = 'COL-CUST-' . $customerId . '-' . date('YmdHis');
            
            // وصف المعاملة
            $description = 'تحصيل من عميل: ' . htmlspecialchars($customer['name'] ?? '');
            if ($salesRepName) {
                $description .= ' (مندوب: ' . htmlspecialchars($salesRepName) . ')';
            }
            
            // إضافة المعاملة في جدول accountant_transactions
            $db->execute(
                "INSERT INTO accountant_transactions (transaction_type, amount, sales_rep_id, description, reference_number, payment_method, status, approved_by, created_by, approved_at, notes)
                 VALUES (?, ?, ?, ?, ?, 'cash', 'approved', ?, ?, NOW(), ?)",
                [
                    'income',
                    $amount,
                    $salesRepId,
                    $description,
                    $referenceNumber,
                    $currentUser['id'],
                    $currentUser['id'],
                    'تحصيل من قبل ' . ($currentUser['full_name'] ?? $currentUser['username'] ?? 'مدير/محاسب') . ' - لا يتم احتساب نسبة للمندوب'
                ]
            );
            
            $accountantTransactionId = $db->getLastInsertId();
            
            // تسجيل سجل التدقيق
            logAudit(
                $currentUser['id'],
                'collect_customer_debt_by_manager_accountant',
                'accountant_transaction',
                $accountantTransactionId,
                null,
                [
                    'customer_id' => $customerId,
                    'customer_name' => $customer['name'] ?? '',
                    'sales_rep_id' => $salesRepId,
                    'amount' => $amount,
                    'reference_number' => $referenceNumber,
                ]
            );
            
            // إرسال إشعار للمندوب
            if ($salesRepId && $salesRepId > 0) {
                try {
                    $collectorName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'مدير/محاسب';
                    $notificationTitle = 'تحصيل من عميلك';
                    $notificationMessage = 'تم تحصيل مبلغ ' . formatCurrency($amount) . ' من العميل ' . htmlspecialchars($customer['name'] ?? '') . 
                                         ' بواسطة ' . htmlspecialchars($collectorName) . 
                                         ' - رقم المرجع: ' . $referenceNumber . 
                                         ' (ملاحظة: لا يتم احتساب نسبة تحصيلات على هذا المبلغ)';
                    $notificationLink = getRelativeUrl('dashboard/sales.php?page=customers');
                    
                    createNotification(
                        $salesRepId,
                        $notificationTitle,
                        $notificationMessage,
                        'info',
                        $notificationLink,
                        true
                    );
                } catch (Throwable $notifError) {
                    error_log('Failed to send notification to sales rep: ' . $notifError->getMessage());
                }
            }
            
            // توزيع التحصيل على فواتير العميل
            $distributionResult = null;
            try {
                if (function_exists('distributeCollectionToInvoices')) {
                    error_log('Starting invoice distribution...');
                    $distributionResult = distributeCollectionToInvoices($customerId, $amount, $currentUser['id']);
                    error_log('Invoice distribution result: ' . json_encode($distributionResult));
                } else {
                    error_log('WARNING: distributeCollectionToInvoices function does not exist');
                }
            } catch (Throwable $distError) {
                error_log('ERROR in invoice distribution: ' . $distError->getMessage());
                // لا نوقف العملية إذا فشل التوزيع
                $distributionResult = ['success' => false, 'message' => 'فشل توزيع التحصيل على الفواتير: ' . $distError->getMessage()];
            }
            
            error_log('Committing transaction...');
            $db->commit();
            $transactionStarted = false;
            error_log('Transaction committed successfully');
            
            $messageParts = ['تم تحصيل المبلغ بنجاح وإضافته إلى خزنة الشركة.'];
            $messageParts[] = 'رقم المرجع: ' . $referenceNumber . '.';
            if ($salesRepName) {
                $messageParts[] = 'تم إرسال إشعار للمندوب: ' . htmlspecialchars($salesRepName) . '.';
            }
            if ($distributionResult && !empty($distributionResult['updated_invoices'])) {
                $messageParts[] = 'تم تحديث ' . count($distributionResult['updated_invoices']) . ' فاتورة.';
            } elseif ($distributionResult && !empty($distributionResult['message'])) {
                $messageParts[] = 'ملاحظة: ' . $distributionResult['message'];
            }
            
            $_SESSION['success_message'] = implode(' ', array_filter($messageParts));
            error_log('Collection successful, redirecting...');
            
            redirectAfterPost(
                'representatives_customers',
                [],
                [],
                $currentRole
            );
            
        } catch (Exception $e) {
            if ($transactionStarted) {
                $db->rollback();
                error_log('Transaction rolled back due to Exception');
            }
            $errorMsg = 'حدث خطأ أثناء التحصيل: ' . $e->getMessage();
            $_SESSION['error_message'] = $errorMsg;
            error_log('Collection ERROR (Exception): ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            redirectAfterPost(
                'representatives_customers',
                [],
                [],
                $currentRole
            );
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $db->rollback();
                error_log('Transaction rolled back due to Throwable');
            }
            $errorMsg = 'حدث خطأ أثناء التحصيل: ' . $e->getMessage();
            $_SESSION['error_message'] = $errorMsg;
            error_log('Collection ERROR (Throwable): ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            redirectAfterPost(
                'representatives_customers',
                [],
                [],
                $currentRole
            );
        }
    }
}

// معالجة تعديل العميل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'edit_customer') {
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $regionId = isset($_POST['region_id']) && $_POST['region_id'] !== '' ? (int)$_POST['region_id'] : null;
    
    if ($customerId <= 0) {
        $_SESSION['error_message'] = 'معرف العميل غير صحيح';
    } else {
        try {
            // التحقق من وجود العميل
            $customer = $db->queryOne("SELECT id, name FROM customers WHERE id = ?", [$customerId]);
            if (!$customer) {
                $_SESSION['error_message'] = 'العميل غير موجود';
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
                    $_SESSION['error_message'] = 'غير مصرح لك بتعديل هذا العميل';
                } else {
                    $updateFields = [];
                    $updateValues = [];
                    
                    if (in_array('phone', $allowedFields)) {
                        $updateFields[] = 'phone = ?';
                        $updateValues[] = $phone ?: null;
                        
                        // تحديث أرقام الهواتف في جدول customer_phones
                        $phones = $_POST['phones'] ?? [];
                        if (is_array($phones) && !empty($phones)) {
                            // حذف الأرقام القديمة
                            $db->execute("DELETE FROM customer_phones WHERE customer_id = ?", [$customerId]);
                            
                            // إضافة الأرقام الجديدة
                            $firstPhone = true;
                            foreach ($phones as $phoneNumber) {
                                $phoneNumber = trim($phoneNumber);
                                if (!empty($phoneNumber)) {
                                    $db->execute(
                                        "INSERT INTO customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                                        [$customerId, $phoneNumber, $firstPhone ? 1 : 0]
                                    );
                                    $firstPhone = false;
                                }
                            }
                        } elseif (!empty($phone)) {
                            // إذا لم تكن هناك أرقام متعددة، احفظ الرقم الواحد
                            $db->execute("DELETE FROM customer_phones WHERE customer_id = ?", [$customerId]);
                            $db->execute(
                                "INSERT INTO customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
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
                            "UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                            $updateValues
                        );
                        
                        logAudit($currentUser['id'], 'edit_rep_customer', 'customer', $customerId, null, [
                            'name' => $customer['name'],
                            'updated_fields' => $allowedFields
                        ]);
                        
                        $_SESSION['success_message'] = 'تم تعديل بيانات العميل بنجاح';
                        
                        redirectAfterPost(
                            'representatives_customers',
                            [],
                            [],
                            $currentRole
                        );
                    } else {
                        $_SESSION['error_message'] = 'لم يتم تحديد أي حقول للتعديل';
                    }
                }
            }
        } catch (Throwable $editError) {
            error_log('Edit rep customer error: ' . $editError->getMessage());
            $_SESSION['error_message'] = 'حدث خطأ أثناء تعديل بيانات العميل';
        }
    }
}

// معالجة تحديد الحد الائتماني (للمدير فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'set_credit_limit') {
    // التحقق من أن المستخدم هو مدير فقط
    if ($currentRole !== 'manager') {
        $errorMessage = 'غير مصرح لك بتحديد الحد الائتماني. هذه الصلاحية متاحة للمدير فقط.';
        preventDuplicateSubmission(
            null,
            ['page' => 'representatives_customers'],
            null,
            $currentRole,
            $errorMessage
        );
    } else {
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $creditLimit = isset($_POST['credit_limit']) ? cleanFinancialValue($_POST['credit_limit']) : 0;
        
        if ($customerId <= 0) {
            $errorMessage = 'معرف العميل غير صحيح';
            preventDuplicateSubmission(
                null,
                ['page' => 'representatives_customers'],
                null,
                $currentRole,
                $errorMessage
            );
        } elseif ($creditLimit < 0) {
            $errorMessage = 'الحد الائتماني يجب أن يكون أكبر من أو يساوي صفر.';
            preventDuplicateSubmission(
                null,
                ['page' => 'representatives_customers'],
                null,
                $currentRole,
                $errorMessage
            );
        } else {
            try {
                // التحقق من وجود العميل
                $customer = $db->queryOne("SELECT id, name, balance FROM customers WHERE id = ?", [$customerId]);
                if (!$customer) {
                    $errorMessage = 'العميل غير موجود';
                    preventDuplicateSubmission(
                        null,
                        ['page' => 'representatives_customers'],
                        null,
                        $currentRole,
                        $errorMessage
                    );
                } else {
                    // تحديث الحد الائتماني
                    $db->execute(
                        "UPDATE customers SET credit_limit = ? WHERE id = ?",
                        [$creditLimit, $customerId]
                    );
                    
                    // تسجيل في audit log
                    logAudit($currentUser['id'], 'set_customer_credit_limit', 'customer', $customerId, null, [
                        'customer_name' => $customer['name'],
                        'credit_limit' => $creditLimit,
                        'current_balance' => $customer['balance'] ?? 0
                    ]);
                    
                    $successMessage = 'تم تحديد الحد الائتماني للعميل ' . htmlspecialchars($customer['name']) . ' بنجاح.';
                    
                    preventDuplicateSubmission(
                        $successMessage,
                        ['page' => 'representatives_customers'],
                        null,
                        $currentRole
                    );
                }
            } catch (Throwable $e) {
                error_log('Set credit limit error: ' . $e->getMessage());
                $errorMessage = 'حدث خطأ أثناء تحديد الحد الائتماني';
                preventDuplicateSubmission(
                    null,
                    ['page' => 'representatives_customers'],
                    null,
                    $currentRole,
                    $errorMessage
                );
            }
        }
    }
}

// قراءة الرسائل من session بعد معالجة POST
$error = '';
$success = '';
applyPRGPattern($error, $success);

$representatives = [];
$representativeSummary = [
    'total' => 0,
    'customers' => 0,
    'debtors' => 0,
    'debt' => 0.0,
    'total_collections' => 0.0,
    'total_returns' => 0.0,
    'creditors' => 0,
    'total_credit' => 0.0,
];

try {
    // التحقق من وجود عمود status في جدول collections
    $collectionsStatusCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasCollectionsStatus = !empty($collectionsStatusCheck);
    
    // التحقق من وجود جدول returns
    $returnsTableCheck = $db->queryOne("SHOW TABLES LIKE 'returns'");
    $hasReturnsTable = !empty($returnsTableCheck);
    
    // التحقق من وجود الأعمدة في جدول users
    $hasLastLoginAt = false;
    $hasProfileImage = false;
    $hasProfilePhoto = false;
    try {
        $lastLoginCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'last_login_at'");
        $hasLastLoginAt = !empty($lastLoginCheck);
        $profileImageCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'profile_image'");
        $hasProfileImage = !empty($profileImageCheck);
        $profilePhotoCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'profile_photo'");
        $hasProfilePhoto = !empty($profilePhotoCheck);
    } catch (Throwable $e) {
        error_log('Column check error: ' . $e->getMessage());
    }
    
    // بناء SELECT بشكل ديناميكي بناءً على الأعمدة الموجودة
    $selectColumns = [
        'u.id',
        'u.full_name',
        'u.username',
        'u.phone',
        'u.status'
    ];
    
    if ($hasLastLoginAt) {
        $selectColumns[] = 'u.last_login_at';
    } else {
        $selectColumns[] = 'NULL AS last_login_at';
    }
    
    if ($hasProfileImage) {
        $selectColumns[] = 'u.profile_image';
    } elseif ($hasProfilePhoto) {
        $selectColumns[] = 'u.profile_photo AS profile_image';
    } else {
        $selectColumns[] = 'NULL AS profile_image';
    }
    
    $selectSql = implode(', ', $selectColumns);
    
    // استعلام المندوبين - استخدام استعلام أبسط أولاً ثم حساب الإحصائيات
    $representatives = $db->query(
        "SELECT {$selectSql}
        FROM users u
        WHERE u.role = 'sales'
        ORDER BY u.full_name ASC"
    );
    
    // إذا لم يتم العثور على مندوبين، جرب بدون فلتر status
    if (empty($representatives)) {
        $selectColumnsAlt = [
            'id',
            'full_name',
            'username',
            'phone',
            'status'
        ];
        
        if ($hasLastLoginAt) {
            $selectColumnsAlt[] = 'last_login_at';
        } else {
            $selectColumnsAlt[] = 'NULL AS last_login_at';
        }
        
        if ($hasProfileImage) {
            $selectColumnsAlt[] = 'profile_image';
        } elseif ($hasProfilePhoto) {
            $selectColumnsAlt[] = 'profile_photo AS profile_image';
        } else {
            $selectColumnsAlt[] = 'NULL AS profile_image';
        }
        
        $selectSqlAlt = implode(', ', $selectColumnsAlt);
        
        $representatives = $db->query(
            "SELECT {$selectSqlAlt}
            FROM users
            WHERE role = 'sales' OR role LIKE '%sales%'
            ORDER BY full_name ASC"
        );
    }
    
    // حساب إحصائيات العملاء لكل مندوب
    foreach ($representatives as &$rep) {
        $repId = (int)($rep['id'] ?? 0);
        if ($repId > 0) {
            try {
                // استخدام استعلام بسيط وواضح
                $customerStats = $db->queryOne(
                    "SELECT 
                        COUNT(*) AS customer_count,
                        COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt,
                        COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) AS debtor_count,
                        COALESCE(SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END), 0) AS total_credit,
                        COALESCE(SUM(CASE WHEN balance < 0 THEN 1 ELSE 0 END), 0) AS creditor_count
                    FROM customers
                    WHERE rep_id = ? OR created_by = ?",
                    [$repId, $repId]
                );
                
                if ($customerStats !== null && is_array($customerStats)) {
                    $rep['customer_count'] = (int)($customerStats['customer_count'] ?? 0);
                    $rep['total_debt'] = (float)($customerStats['total_debt'] ?? 0.0);
                    $rep['debtor_count'] = (int)($customerStats['debtor_count'] ?? 0);
                    $rep['total_credit'] = (float)($customerStats['total_credit'] ?? 0.0);
                    $rep['creditor_count'] = (int)($customerStats['creditor_count'] ?? 0);
                } else {
                    $rep['customer_count'] = 0;
                    $rep['total_debt'] = 0.0;
                    $rep['debtor_count'] = 0;
                    $rep['total_credit'] = 0.0;
                    $rep['creditor_count'] = 0;
                }
            } catch (Throwable $statsError) {
                error_log('Customer stats error for rep ' . $repId . ': ' . $statsError->getMessage());
                $rep['customer_count'] = 0;
                $rep['total_debt'] = 0.0;
                $rep['debtor_count'] = 0;
            }
        } else {
            $rep['customer_count'] = 0;
            $rep['total_debt'] = 0.0;
            $rep['debtor_count'] = 0;
        }
    }
    unset($rep);
    
    // ترتيب المندوبين حسب عدد العملاء
    usort($representatives, function($a, $b) {
        $countA = (int)($a['customer_count'] ?? 0);
        $countB = (int)($b['customer_count'] ?? 0);
        if ($countA === $countB) {
            return strcmp($a['full_name'] ?? '', $b['full_name'] ?? '');
        }
        return $countB - $countA;
    });

    // حساب إجمالي التحصيلات والمرتجعات لكل مندوب
    foreach ($representatives as &$repRow) {
        $repId = (int)($repRow['id'] ?? 0);
        
        // حساب إجمالي التحصيلات للمندوب
        $collectionsTotal = 0.0;
        try {
            $collectionsTableCheck = $db->queryOne("SHOW TABLES LIKE 'collections'");
            if (!empty($collectionsTableCheck)) {
                if ($hasCollectionsStatus) {
                    $collectionsResult = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) AS total_collections
                         FROM collections
                         WHERE collected_by = ? AND status IN ('pending', 'approved')",
                        [$repId]
                    );
                } else {
                    $collectionsResult = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) AS total_collections
                         FROM collections
                         WHERE collected_by = ?",
                        [$repId]
                    );
                }
                $collectionsTotal = (float)($collectionsResult['total_collections'] ?? 0.0);
            }
        } catch (Throwable $collectionsError) {
            error_log('Collections calculation error for rep ' . $repId . ': ' . $collectionsError->getMessage());
        }
        
        // حساب إجمالي المرتجعات للمندوب
        $returnsTotal = 0.0;
        if ($hasReturnsTable) {
            try {
                $returnsResult = $db->queryOne(
                    "SELECT COALESCE(SUM(refund_amount), 0) AS total_returns
                     FROM returns
                     WHERE sales_rep_id = ? AND status IN ('approved', 'processed', 'completed')",
                    [$repId]
                );
                $returnsTotal = (float)($returnsResult['total_returns'] ?? 0.0);
            } catch (Throwable $returnsError) {
                error_log('Returns calculation error for rep ' . $repId . ': ' . $returnsError->getMessage());
            }
        }
        
        $repRow['total_collections'] = $collectionsTotal;
        $repRow['total_returns'] = $returnsTotal;
        
        // تحديث الإحصائيات الإجمالية
        $representativeSummary['total']++;
        $representativeSummary['customers'] += (int)($repRow['customer_count'] ?? 0);
        $representativeSummary['debtors'] += (int)($repRow['debtor_count'] ?? 0);
        $representativeSummary['debt'] += (float)($repRow['total_debt'] ?? 0.0);
        $representativeSummary['creditors'] += (int)($repRow['creditor_count'] ?? 0);
        $representativeSummary['total_credit'] += (float)($repRow['total_credit'] ?? 0.0);
        $representativeSummary['total_collections'] += $collectionsTotal;
        $representativeSummary['total_returns'] += $returnsTotal;
    }
    unset($repRow);
    
} catch (Throwable $repsError) {
    error_log('Manager representatives list error: ' . $repsError->getMessage());
    error_log('Stack trace: ' . $repsError->getTraceAsString());
    $representatives = [];
    
    // محاولة استعلام بسيط للتحقق من وجود مندوبين
    try {
        $simpleTest = $db->query("SELECT id, full_name, username, role FROM users WHERE role = 'sales' LIMIT 10");
        error_log('Simple test query found ' . count($simpleTest) . ' sales reps');
        if (!empty($simpleTest)) {
            // إعادة بناء القائمة بشكل بسيط
            $representatives = [];
            foreach ($simpleTest as $rep) {
                $representatives[] = [
                    'id' => (int)($rep['id'] ?? 0),
                    'full_name' => $rep['full_name'] ?? $rep['username'] ?? '',
                    'username' => $rep['username'] ?? '',
                    'phone' => '',
                    'status' => 'active',
                    'last_login_at' => null,
                    'profile_image' => null,
                    'customer_count' => 0,
                    'total_debt' => 0.0,
                    'debtor_count' => 0,
                    'total_collections' => 0.0,
                    'total_returns' => 0.0,
                ];
            }
        }
    } catch (Throwable $testError) {
        error_log('Simple test query also failed: ' . $testError->getMessage());
    }
}

renderCustomersSectionHeader([
    'title' => 'عملاء المندوبين',
    'active_tab' => null,
    'tabs' => [],
    'primary_btn' => null,
]);

if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">عدد المناديب</div>
                <div class="fs-4 fw-bold mb-0"><?php echo number_format($representativeSummary['total']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">إجمالي العملاء</div>
                <div class="fs-4 fw-bold mb-0"><?php echo number_format($representativeSummary['customers']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">العملاء المدينون</div>
                <div class="fs-4 fw-bold text-warning mb-0"><?php echo number_format($representativeSummary['debtors']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">إجمالي الديون</div>
                <div class="fs-4 fw-bold text-danger mb-0"><?php echo formatCurrency($representativeSummary['debt']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">إجمالي التحصيلات</div>
                    <div class="fs-4 fw-bold text-success mb-0"><?php echo formatCurrency($representativeSummary['total_collections']); ?></div>
                </div>
                <span class="text-success display-6"><i class="bi bi-cash-coin"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">إجمالي المرتجعات</div>
                    <div class="fs-4 fw-bold text-info mb-0"><?php echo formatCurrency($representativeSummary['total_returns']); ?></div>
                </div>
                <span class="text-info display-6"><i class="bi bi-arrow-left-right"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">العملاء الدائنين</div>
                    <div class="fs-4 fw-bold text-primary mb-0"><?php echo number_format($representativeSummary['creditors']); ?></div>
                </div>
                <span class="text-primary display-6"><i class="bi bi-people"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">إجمالي الرصيد الدائن</div>
                    <div class="fs-4 fw-bold text-primary mb-0"><?php echo formatCurrency($representativeSummary['total_credit']); ?></div>
                </div>
                <span class="text-primary display-6"><i class="bi bi-wallet2"></i></span>
            </div>
        </div>
    </div>
</div>

<?php
// بناء الرابط بشكل صحيح
$baseUrl = getRelativeUrl($dashboardScript);
$viewBaseUrl = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=rep_customers_view';
renderRepresentativeCards($representatives, [
    'view_base_url' => $viewBaseUrl,
]);
?>

<?php
// جلب جميع عملاء المندوبين للجدول
$allCustomersPageNum = isset($_GET['cp']) ? max(1, intval($_GET['cp'])) : 1;
$allCustomersPerPage = 6;
$allCustomersOffset = ($allCustomersPageNum - 1) * $allCustomersPerPage;

$allCustomersSearch = trim($_GET['cs'] ?? '');
$allCustomersDebtStatus = $_GET['cds'] ?? 'all';
$allCustomersRepFilter = isset($_GET['rep_filter']) ? (int)$_GET['rep_filter'] : 0;
$allowedDebtStatuses = ['all', 'debtor', 'clear'];
if (!in_array($allCustomersDebtStatus, $allowedDebtStatuses, true)) {
    $allCustomersDebtStatus = 'all';
}

// بناء استعلام SQL لعملاء المندوبين
$allCustomersSql = "SELECT c.*, 
        COALESCE(rep1.full_name, rep2.full_name) as rep_name, 
        r.name as region_name,
        COALESCE(c.credit_limit, 0) as credit_limit
        FROM customers c
        LEFT JOIN users rep1 ON c.rep_id = rep1.id AND rep1.role = 'sales'
        LEFT JOIN users rep2 ON c.created_by = rep2.id AND rep2.role = 'sales'
        LEFT JOIN regions r ON c.region_id = r.id
        WHERE ";

// بناء استعلام COUNT - نحتاج JOINs فقط إذا كان هناك بحث
$allCustomersCountSql = "SELECT COUNT(*) as total 
        FROM customers c";

$allCustomersParams = [];
$allCustomersCountParams = [];

// إضافة JOINs لاستعلام COUNT إذا كان هناك بحث
if ($allCustomersSearch) {
    $allCustomersCountSql .= " LEFT JOIN users rep1 ON c.rep_id = rep1.id AND rep1.role = 'sales'
        LEFT JOIN users rep2 ON c.created_by = rep2.id AND rep2.role = 'sales'
        LEFT JOIN regions r ON c.region_id = r.id";
}

$allCustomersCountSql .= " WHERE ";

// بناء شرط WHERE حسب الفلتر
if ($allCustomersRepFilter > 0) {
    // إذا تم اختيار مندوب محدد، فلتر حسب هذا المندوب فقط
    $allCustomersSql .= "(c.rep_id = ? OR c.created_by = ?)";
    $allCustomersCountSql .= "(c.rep_id = ? OR c.created_by = ?)";
    $allCustomersParams[] = $allCustomersRepFilter;
    $allCustomersParams[] = $allCustomersRepFilter;
    $allCustomersCountParams[] = $allCustomersRepFilter;
    $allCustomersCountParams[] = $allCustomersRepFilter;
} else {
    // إذا لم يتم اختيار مندوب محدد، عرض جميع عملاء المندوبين
    $allCustomersSql .= "((c.rep_id IS NOT NULL AND c.rep_id IN (SELECT id FROM users WHERE role = 'sales'))
           OR (c.created_by IS NOT NULL AND c.created_by IN (SELECT id FROM users WHERE role = 'sales')))";
    $allCustomersCountSql .= "((c.rep_id IS NOT NULL AND c.rep_id IN (SELECT id FROM users WHERE role = 'sales'))
           OR (c.created_by IS NOT NULL AND c.created_by IN (SELECT id FROM users WHERE role = 'sales')))";
}

if ($allCustomersDebtStatus === 'debtor') {
    $allCustomersSql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    $allCustomersCountSql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
} elseif ($allCustomersDebtStatus === 'clear') {
    $allCustomersSql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    $allCustomersCountSql .= " AND (c.balance IS NULL OR c.balance <= 0)";
}

if ($allCustomersSearch) {
    $allCustomersSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR r.name LIKE ? OR rep1.full_name LIKE ? OR rep2.full_name LIKE ?)";
    $allCustomersCountSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR r.name LIKE ? OR rep1.full_name LIKE ? OR rep2.full_name LIKE ?)";
    $searchParam = '%' . $allCustomersSearch . '%';
    $allCustomersParams[] = $searchParam;
    $allCustomersParams[] = $searchParam;
    $allCustomersParams[] = $searchParam;
    $allCustomersParams[] = $searchParam;
    $allCustomersParams[] = $searchParam;
    $allCustomersParams[] = $searchParam;
    $allCustomersCountParams[] = $searchParam;
    $allCustomersCountParams[] = $searchParam;
    $allCustomersCountParams[] = $searchParam;
    $allCustomersCountParams[] = $searchParam;
    $allCustomersCountParams[] = $searchParam;
    $allCustomersCountParams[] = $searchParam;
}

$allCustomersSql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
$allCustomersParams[] = $allCustomersPerPage;
$allCustomersParams[] = $allCustomersOffset;

try {
    $allCustomersTotalResult = $db->queryOne($allCustomersCountSql, $allCustomersCountParams);
    $allCustomersTotal = $allCustomersTotalResult['total'] ?? 0;
    $allCustomersTotalPages = ceil($allCustomersTotal / $allCustomersPerPage);
    
    $allCustomers = $db->query($allCustomersSql, $allCustomersParams);
} catch (Throwable $e) {
    error_log('Error fetching all rep customers: ' . $e->getMessage());
    $allCustomers = [];
    $allCustomersTotal = 0;
    $allCustomersTotalPages = 1;
}
?>

<!-- Responsive Modals CSS - يجب أن يكون في البداية قبل أي محتوى -->
<link rel="stylesheet" href="<?php echo getRelativeUrl('assets/css/responsive-modals.css'); ?>">

<!-- جدول جميع عملاء المندوبين -->
<div class="card shadow-sm mt-4">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">جميع عملاء المندوبين (<span id="repCustomersTotalCount"><?php echo $allCustomersTotal; ?></span>)</h5>
            <button class="btn btn-light btn-sm" id="openCustomerExportBtn" onclick="openCustomerExport()">
                <i class="bi bi-download me-2"></i>تصدير عملاء محددين
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- البحث والفلترة -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-2 g-md-3 align-items-end" id="repCustomersSearchForm">
                    <input type="hidden" name="page" value="representatives_customers">
                    <div class="col-12 col-md-6 col-lg-5">
                        <label for="allCustomersSearch" class="visually-hidden">بحث عن العملاء</label>
                        <div class="input-group input-group-sm shadow-sm position-relative">
                            <span class="input-group-text bg-light text-muted border-end-0">
                                <i class="bi bi-search"></i>
                            </span>
                            <input
                                type="text"
                                class="form-control border-start-0"
                                id="allCustomersSearch"
                                name="cs"
                                value="<?php echo htmlspecialchars($allCustomersSearch); ?>"
                                placeholder="بحث سريع بالاسم أو الهاتف أو المندوب"
                                autocomplete="off"
                                aria-autocomplete="list"
                                aria-expanded="false"
                                aria-controls="autocompleteDropdown"
                            >
                            <!-- Autocomplete Dropdown -->
                            <div id="autocompleteDropdown" class="autocomplete-dropdown" role="listbox" aria-label="نتائج البحث"></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="allCustomersRepFilter" class="visually-hidden">تصفية حسب المندوب</label>
                        <select class="form-select form-select-sm shadow-sm" id="allCustomersRepFilter" name="rep_filter">
                            <option value="0" <?php echo $allCustomersRepFilter === 0 ? 'selected' : ''; ?>>جميع المندوبين</option>
                            <?php
                            // جلب قائمة المندوبين للفلتر
                            try {
                                $filterReps = $db->query(
                                    "SELECT id, full_name, username FROM users WHERE role = 'sales' ORDER BY full_name ASC"
                                );
                                foreach ($filterReps as $rep) {
                                    $repId = (int)($rep['id'] ?? 0);
                                    $repName = htmlspecialchars($rep['full_name'] ?? $rep['username'] ?? 'غير معروف');
                                    $selected = ($allCustomersRepFilter === $repId) ? 'selected' : '';
                                    echo "<option value=\"{$repId}\" {$selected}>{$repName}</option>";
                                }
                            } catch (Throwable $e) {
                                error_log('Error fetching reps for filter: ' . $e->getMessage());
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="allCustomersDebtStatusFilter" class="visually-hidden">تصفية حسب حالة الديون</label>
                        <select class="form-select form-select-sm shadow-sm" id="allCustomersDebtStatusFilter" name="cds">
                            <option value="all" <?php echo $allCustomersDebtStatus === 'all' ? 'selected' : ''; ?>>الكل</option>
                            <option value="debtor" <?php echo $allCustomersDebtStatus === 'debtor' ? 'selected' : ''; ?>>مدين</option>
                            <option value="clear" <?php echo $allCustomersDebtStatus === 'clear' ? 'selected' : ''; ?>>غير مدين / لديه رصيد</option>
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

        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>رقم الهاتف</th>
                        <th>الرصيد</th>
                        <th>العنوان</th>
                        <th>المنطقة</th>
                        <th>المندوب</th>
                        <th>الموقع</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allCustomers)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد عملاء للمندوبين</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($allCustomers as $customer): ?>
                            <tr data-customer-id="<?php echo (int)$customer['id']; ?>">
                                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                <td>
                                    <?php
                                    // جلب أرقام الهواتف من جدول customer_phones
                                    $customerPhones = $db->query(
                                        "SELECT phone FROM customer_phones WHERE customer_id = ? ORDER BY is_primary DESC, id ASC",
                                        [$customer['id']]
                                    );
                                    if (empty($customerPhones) && !empty($customer['phone'])) {
                                        // إذا لم تكن هناك أرقام في customer_phones، استخدم الرقم القديم
                                        $customerPhones = [['phone' => $customer['phone']]];
                                    }
                                    if (!empty($customerPhones)) {
                                        foreach ($customerPhones as $phoneData) {
                                            $phoneNumber = trim($phoneData['phone'] ?? '');
                                            if (!empty($phoneNumber)) {
                                                echo '<a href="tel:' . htmlspecialchars($phoneNumber) . '" class="btn btn-sm btn-outline-primary me-1 mb-1" title="اتصل بـ ' . htmlspecialchars($phoneNumber) . '">';
                                                echo '<i class="bi bi-telephone-fill"></i> ';
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
                                <td><?php echo htmlspecialchars($customer['rep_name'] ?? '-'); ?></td>
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
                                            class="btn btn-sm btn-outline-primary all-customers-location-capture-btn"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-geo-alt me-1"></i>تحديد
                                        </button>
                                        <?php if ($hasLocation): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-info all-customers-location-view-btn"
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
                                            class="btn btn-sm btn-outline-warning edit-rep-customer-btn"
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
                                        <?php if ($currentRole === 'manager'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-info set-credit-limit-btn"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-balance="<?php echo $rawBalance; ?>"
                                            data-credit-limit="<?php echo htmlspecialchars(number_format((float)($customer['credit_limit'] ?? 0), 2, '.', '')); ?>"
                                        >
                                            <i class="bi bi-credit-card me-1"></i>الحد الائتماني
                                        </button>
                                        <?php endif; ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm <?php echo $customerBalance > 0 ? 'btn-success' : 'btn-outline-secondary'; ?> all-customers-collect-btn"
                                            onclick="showAllCustomersCollectPaymentModal(this)"
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
                                            class="btn btn-sm btn-outline-info js-all-customers-purchase-history"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                        >
                                            <i class="bi bi-receipt me-1"></i>سجل 
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-warning js-all-customers-return-products"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-arrow-return-left me-1"></i>مرتجع
                                        </button>
                                        <?php if ($currentRole === 'manager' || $currentRole === 'developer'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary change-sales-rep-btn"
                                            onclick="openChangeSalesRepModal(this)"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-current-rep-id="<?php echo (int)($customer['rep_id'] ?? $customer['created_by'] ?? 0); ?>"
                                            data-current-rep-name="<?php echo htmlspecialchars($customer['rep_name'] ?? 'غير محدد'); ?>"
                                        >
                                            <i class="bi bi-arrow-left-right me-1"></i>نقل لمندوب آخر
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div id="repCustomersPagination">
        <?php if ($allCustomersTotalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $allCustomersPageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=representatives_customers&cp=<?php echo $allCustomersPageNum - 1; ?><?php echo $allCustomersSearch ? '&cs=' . urlencode($allCustomersSearch) : ''; ?>&cds=<?php echo urlencode($allCustomersDebtStatus); ?><?php echo $allCustomersRepFilter > 0 ? '&rep_filter=' . $allCustomersRepFilter : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                // عرض 3 أرقام صفحات فقط
                $pagesToShow = 3;
                $halfPages = floor($pagesToShow / 2);
                
                // حساب الصفحات للعرض
                if ($allCustomersTotalPages <= $pagesToShow) {
                    // إذا كان العدد الإجمالي للصفحات أقل من أو يساوي 3، اعرض كل الصفحات
                    $startPage = 1;
                    $endPage = $allCustomersTotalPages;
                } else {
                    // حساب الصفحات حول الصفحة الحالية
                    $startPage = max(1, $allCustomersPageNum - $halfPages);
                    $endPage = min($allCustomersTotalPages, $allCustomersPageNum + $halfPages);
                    
                    // تعديل إذا كنا في البداية أو النهاية
                    if ($endPage - $startPage < $pagesToShow - 1) {
                        if ($startPage == 1) {
                            $endPage = min($allCustomersTotalPages, $startPage + $pagesToShow - 1);
                        } else {
                            $startPage = max(1, $endPage - $pagesToShow + 1);
                        }
                    }
                }
                
                // عرض أزرار الصفحات
                for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $allCustomersPageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=representatives_customers&cp=<?php echo $i; ?><?php echo $allCustomersSearch ? '&cs=' . urlencode($allCustomersSearch) : ''; ?>&cds=<?php echo urlencode($allCustomersDebtStatus); ?><?php echo $allCustomersRepFilter > 0 ? '&rep_filter=' . $allCustomersRepFilter : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $allCustomersPageNum >= $allCustomersTotalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=representatives_customers&cp=<?php echo $allCustomersPageNum + 1; ?><?php echo $allCustomersSearch ? '&cs=' . urlencode($allCustomersSearch) : ''; ?>&cds=<?php echo urlencode($allCustomersDebtStatus); ?><?php echo $allCustomersRepFilter > 0 ? '&rep_filter=' . $allCustomersRepFilter : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
        </div>
    </div>
</div>

<style>
.representative-card-link {
    display: block;
    cursor: pointer;
}
.representative-card {
    border-radius: 18px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    cursor: pointer;
}
.representative-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
}
.rep-stat-card {
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 12px;
    padding: 0.75rem;
    background: #fff;
}
.rep-stat-card.border-success {
    border-color: rgba(25, 135, 84, 0.3) !important;
    background: rgba(25, 135, 84, 0.05);
}
.rep-stat-card.border-info {
    border-color: rgba(13, 202, 240, 0.3) !important;
    background: rgba(13, 202, 240, 0.05);
}
.rep-stat-value {
    font-size: 1.1rem;
    font-weight: 600;
}

/* ضمان ظهور زر الطباعة على الهاتف */
#repDetailsModal .modal-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: space-between;
    align-items: center;
}

#repDetailsModal .modal-footer #repPrintStatementBtn {
    flex: 1;
    min-width: 150px;
    white-space: nowrap;
}

@media (max-width: 576px) {
    #repDetailsModal .modal-footer {
        flex-direction: column;
    }
    
    #repDetailsModal .modal-footer #repPrintStatementBtn {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    #repDetailsModal .modal-footer .btn-secondary {
        width: 100%;
    }
    }
    
    /* Autocomplete Dropdown للبحث الفوري */
    .autocomplete-dropdown {
        position: absolute;
        top: calc(100% + 2px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-top: none;
        border-radius: 0 0 10px 10px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        max-height: 400px;
        overflow-y: auto;
        z-index: 1050 !important;
        margin-top: 0;
        display: none;
        width: 100%;
        visibility: hidden;
        opacity: 0;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .autocomplete-dropdown.show {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    .autocomplete-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    .autocomplete-item:last-child {
        border-bottom: none;
    }
    .autocomplete-item:hover,
    .autocomplete-item.selected {
        background-color: #f8fafc;
    }
    .autocomplete-item-name {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.95rem;
    }
    .autocomplete-item-sub {
        font-size: 0.85rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .autocomplete-item-balance {
        margin-right: auto;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .autocomplete-item-balance.positive {
        background-color: #fef3c7;
        color: #92400e;
    }
    .autocomplete-item-balance.negative {
        background-color: #dbeafe;
        color: #1e40af;
    }
    .autocomplete-item-balance.zero {
        background-color: #f1f5f9;
        color: #64748b;
    }
    .autocomplete-no-results {
        padding: 1rem;
        text-align: center;
        color: #94a3b8;
        font-size: 0.9rem;
    }
    .autocomplete-loading {
        padding: 1rem;
        text-align: center;
        color: #64748b;
        font-size: 0.9rem;
    }
    
    @media (max-width: 768px) {
        .autocomplete-dropdown {
            max-height: 300px;
            font-size: 0.9rem;
        }
        .autocomplete-item {
            padding: 0.65rem 0.85rem;
        }
        .autocomplete-item-name {
            font-size: 0.9rem;
        }
        .autocomplete-item-sub {
            font-size: 0.8rem;
        }
        .autocomplete-item-balance {
            font-size: 0.75rem;
            padding: 0.1rem 0.4rem;
        }
    }
</style>

<!-- Modal تفاصيل المندوب - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="repDetailsModal" tabindex="-1" aria-labelledby="repDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="repDetailsModalLabel">
                    <i class="bi bi-person-circle me-2"></i>
                    <span id="repModalName">تفاصيل المندوب</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="repDetailsLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-3 text-muted">جاري تحميل البيانات...</p>
                </div>
                <div id="repDetailsContent" style="display: none;">
                    <!-- معلومات المندوب الأساسية -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <div class="mb-2">
                                        <span class="text-muted small">اسم المندوب:</span>
                                        <div class="fw-semibold" id="repFullName">—</div>
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-muted small">اسم المستخدم:</span>
                                        <div class="fw-semibold" id="repUsername">—</div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="mb-2">
                                        <span class="text-muted small">الهاتف:</span>
                                        <div class="fw-semibold" id="repPhone">—</div>
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-muted small">عدد الفواتير:</span>
                                        <div class="fw-semibold" id="repInvoiceCount">—</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- الإحصائيات -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="text-muted small mb-2">عدد العملاء</div>
                                    <div class="h4 mb-0 text-primary" id="repCustomerCount">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="text-muted small mb-2">إجمالي الديون</div>
                                    <div class="h4 mb-0 text-danger" id="repTotalDebt">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="text-muted small mb-2">إجمالي التحصيلات</div>
                                    <div class="h4 mb-0 text-success" id="repTotalCollections">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="text-muted small mb-2">إجمالي المرتجعات</div>
                                    <div class="h4 mb-0 text-info" id="repTotalReturns">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- قائمة العملاء -->
                    <div class="mb-4">
                        <h6 class="mb-3"><i class="bi bi-people me-2"></i>قائمة العملاء</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>اسم العميل</th>
                                        <th>الهاتف</th>
                                        <th>الرصيد</th>
                                        <th>الموقع</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody id="repCustomersList">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">لا توجد بيانات</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- قائمة التحصيلات -->
                    <div class="mb-4">
                        <h6 class="mb-3"><i class="bi bi-cash-coin me-2"></i>التحصيلات</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>العميل</th>
                                        <th>المبلغ</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody id="repCollectionsList">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">لا توجد تحصيلات</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- قائمة المرتجعات -->
                    <div>
                        <h6 class="mb-3"><i class="bi bi-arrow-left-right me-2"></i>المرتجعات</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>العميل</th>
                                        <th>المبلغ</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody id="repReturnsList">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">لا توجد مرتجعات</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="repPrintStatementBtn" target="_blank" class="btn btn-primary">
                    <i class="bi bi-printer me-2"></i>طباعة كشف حساب شامل
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
// متغير لتخزين آخر AbortController لإلغاء الطلبات السابقة
let currentRepDetailsAbortController = null;

function loadRepDetails(repId, repName) {
    // إلغاء أي طلب سابق إذا كان موجوداً
    if (currentRepDetailsAbortController) {
        currentRepDetailsAbortController.abort();
        currentRepDetailsAbortController = null;
    }
    
    // إنشاء AbortController جديد للطلب الحالي
    currentRepDetailsAbortController = new AbortController();
    
    // تحديث اسم المندوب في الـ modal
    const modalNameEl = document.getElementById('repModalName');
    if (modalNameEl) {
        modalNameEl.textContent = 'تفاصيل: ' + repName;
    }
    
    // إظهار loading وإخفاء المحتوى
    const loadingEl = document.getElementById('repDetailsLoading');
    const contentEl = document.getElementById('repDetailsContent');
    if (loadingEl) {
        loadingEl.style.display = 'block';
        loadingEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    }
    if (contentEl) contentEl.style.display = 'none';
    
    // تعريف baseUrl لاستخدامه في الدالة
    const baseUrl = <?php echo json_encode(getRelativeUrl($dashboardScript), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    
    // تحديث رابط عرض جميع العملاء
    const viewCustomersLink = document.getElementById('repViewCustomersLink');
    if (viewCustomersLink) {
        viewCustomersLink.href = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=rep_customers_view&rep_id=' + repId;
    }
    
    // تحديث رابط زر طباعة كشف الحساب الشامل
    const printStatementBtn = document.getElementById('repPrintStatementBtn');
    if (printStatementBtn) {
        const printUrl = <?php echo json_encode(getRelativeUrl("print_cash_register_statement.php"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> + '?sales_rep_id=' + repId;
        printStatementBtn.href = printUrl;
    }
    
    // جلب البيانات من API
    const apiUrl = <?php echo json_encode(getRelativeUrl("api/get_rep_details.php"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    fetch(apiUrl + '?rep_id=' + repId, {
        signal: currentRepDetailsAbortController.signal,
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // تحديث معلومات المندوب الأساسية
                if (data.rep) {
                    document.getElementById('repFullName').textContent = data.rep.full_name || data.rep.username || '—';
                    document.getElementById('repUsername').textContent = data.rep.username || '—';
                    document.getElementById('repPhone').textContent = data.rep.phone || '—';
                    document.getElementById('repInvoiceCount').textContent = data.stats.invoice_count || 0;
                }
                
                // تحديث الإحصائيات
                document.getElementById('repCustomerCount').textContent = data.stats.customer_count || 0;
                document.getElementById('repTotalDebt').textContent = formatCurrency(data.stats.total_debt || 0);
                document.getElementById('repTotalCollections').textContent = formatCurrency(data.stats.total_collections || 0);
                document.getElementById('repTotalReturns').textContent = formatCurrency(data.stats.total_returns || 0);
                
                // تحديث قائمة العملاء
                const customersList = document.getElementById('repCustomersList');
                if (data.customers && data.customers.length > 0) {
                    customersList.innerHTML = data.customers.map(customer => {
                        const balance = parseFloat(customer.balance || 0);
                        const rawBalance = balance.toFixed(2);
                        const formattedBalance = formatCurrency(Math.abs(balance));
                        const balanceClass = balance > 0 ? 'text-danger' : balance < 0 ? 'text-success' : '';
                        const collectDisabled = balance <= 0 ? 'disabled' : '';
                        const collectBtnClass = balance > 0 ? 'btn-success' : 'btn-outline-secondary';
                        
                        // معالجة الموقع
                        const hasLocation = customer.latitude !== null && customer.longitude !== null;
                        const latValue = hasLocation ? parseFloat(customer.latitude) : null;
                        const lngValue = hasLocation ? parseFloat(customer.longitude) : null;
                        const locationButtons = hasLocation ? `
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info rep-location-view-btn"
                                data-latitude="${latValue.toFixed(8)}"
                                data-longitude="${lngValue.toFixed(8)}"
                                data-customer-name="${escapeHtml(customer.name || '—')}"
                                title="عرض الموقع على الخريطة"
                            >
                                <i class="bi bi-map me-1"></i>عرض
                            </button>
                        ` : `
                            <span class="badge bg-secondary-subtle text-secondary">غير محدد</span>
                        `;
                        
                        return `
                        <tr>
                            <td>${escapeHtml(customer.name || '—')}</td>
                            <td>${escapeHtml(customer.phone || '—')}</td>
                            <td class="${balanceClass}">
                                ${formatCurrency(balance || 0)}
                            </td>
                            <td>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary rep-location-capture-btn"
                                        data-customer-id="${customer.id || 0}"
                                        data-customer-name="${escapeHtml(customer.name || '—')}"
                                        title="تحديد موقع العميل"
                                    >
                                        <i class="bi bi-geo-alt me-1"></i>تحديد
                                    </button>
                                    ${locationButtons}
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm ${collectBtnClass} rep-collect-btn"
                                        onclick="showRepCollectPaymentModal(this)"
                                        data-customer-id="${customer.id || 0}"
                                        data-customer-name="${escapeHtml(customer.name || '—')}"
                                        data-customer-balance="${rawBalance}"
                                        data-customer-balance-formatted="${formattedBalance}"
                                        ${collectDisabled}
                                    >
                                        <i class="bi bi-cash-coin me-1"></i>تحصيل
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-dark rep-history-btn js-customer-history"
                                        data-customer-id="${customer.id || 0}"
                                        data-customer-name="${escapeHtml(customer.name || '—')}"
                                    >
                                        <i class="bi bi-journal-text me-1"></i>سجل المشتريات
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                    }).join('');
                } else {
                    customersList.innerHTML = '<tr><td colspan="5" class="text-center text-muted">لا يوجد عملاء</td></tr>';
                }
                
                // تحديث قائمة التحصيلات
                const collectionsList = document.getElementById('repCollectionsList');
                if (data.collections && data.collections.length > 0) {
                    collectionsList.innerHTML = data.collections.map(collection => `
                        <tr>
                            <td>${formatDate(collection.date || collection.created_at)}</td>
                            <td>${escapeHtml(collection.customer_name || '—')}</td>
                            <td class="text-success">${formatCurrency(collection.amount || 0)}</td>
                            <td>
                                <span class="badge ${collection.status === 'approved' ? 'bg-success' : collection.status === 'pending' ? 'bg-warning' : 'bg-secondary'}">
                                    ${collection.status === 'approved' ? 'معتمد' : collection.status === 'pending' ? 'معلق' : collection.status || '—'}
                                </span>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    collectionsList.innerHTML = '<tr><td colspan="4" class="text-center text-muted">لا توجد تحصيلات</td></tr>';
                }
                
                // تحديث قائمة المرتجعات
                const returnsList = document.getElementById('repReturnsList');
                if (data.returns && data.returns.length > 0) {
                    returnsList.innerHTML = data.returns.map(returnItem => `
                        <tr>
                            <td>${formatDate(returnItem.return_date || returnItem.created_at)}</td>
                            <td>${escapeHtml(returnItem.customer_name || '—')}</td>
                            <td class="text-info">${formatCurrency(returnItem.refund_amount || 0)}</td>
                            <td>
                                <span class="badge ${returnItem.status === 'approved' || returnItem.status === 'completed' ? 'bg-success' : returnItem.status === 'pending' ? 'bg-warning' : 'bg-secondary'}">
                                    ${returnItem.status === 'approved' ? 'معتمد' : returnItem.status === 'completed' ? 'مكتمل' : returnItem.status === 'pending' ? 'معلق' : returnItem.status || '—'}
                                </span>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    returnsList.innerHTML = '<tr><td colspan="4" class="text-center text-muted">لا توجد مرتجعات</td></tr>';
                }
                
                // إخفاء loading وإظهار المحتوى
                if (loadingEl) loadingEl.style.display = 'none';
                if (contentEl) contentEl.style.display = 'block';
            } else {
                document.getElementById('repDetailsLoading').innerHTML = 
                    '<div class="alert alert-danger">فشل تحميل البيانات: ' + (data.error || 'خطأ غير معروف') + '</div>';
            }
        })
        .catch(error => {
            // تجاهل الأخطاء الناتجة عن إلغاء الطلب
            if (error.name === 'AbortError') {
                console.log('Request aborted');
                return;
            }
            
            console.error('Error loading rep details:', error);
            if (loadingEl) {
                loadingEl.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل البيانات: ' + (error.message || 'خطأ غير معروف') + '</div>';
            }
        })
        .finally(() => {
            // تنظيف AbortController بعد اكتمال الطلب
            if (currentRepDetailsAbortController) {
                currentRepDetailsAbortController = null;
            }
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(amount) {
    if (typeof amount === 'string') {
        amount = parseFloat(amount) || 0;
    }
    return new Intl.NumberFormat('ar-EG', {
        style: 'currency',
        currency: 'EGP',
        minimumFractionDigits: 2
    }).format(amount || 0);
}

function formatDate(dateString) {
    if (!dateString) return '—';
    const date = new Date(dateString);
    return date.toLocaleDateString('ar-EG', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

/**
 * دالة مساعدة لجلب JSON من API مع معالجة أخطاء محسنة
 * @param {string} url - URL للـ API endpoint
 * @returns {Promise<Object>} - البيانات كـ JSON object
 */
function fetchJson(url) {
    return fetch(url, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        // قراءة النص أولاً للتحقق من نوع المحتوى
        return response.text().then(text => {
            const contentType = response.headers.get('content-type') || '';
            
            // التحقق من أن الاستجابة JSON
            if (!contentType.includes('application/json')) {
                console.error('Expected JSON but got:', contentType);
                console.error('Response text:', text.substring(0, 1000));
                
                // محاولة التحقق من أن النص هو HTML (صفحة خطأ أو redirect)
                if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
                    throw new Error('الخادم يعيد صفحة HTML بدلاً من JSON. قد تكون الجلسة منتهية أو هناك خطأ في الخادم.');
                }
                
                throw new Error('استجابة غير صحيحة من الخادم. يرجى التحقق من أن endpoint يعيد JSON.');
            }
            
            if (!response.ok) {
                console.error('Error response:', text.substring(0, 500));
                
                // محاولة تحليل JSON للخطأ
                try {
                    const errorData = JSON.parse(text);
                    throw new Error(errorData.message || 'تعذر تحميل البيانات. حالة الخادم: ' + response.status);
                } catch (e) {
                    throw new Error('تعذر تحميل البيانات. حالة الخادم: ' + response.status);
                }
            }
            
            // محاولة تحليل JSON
            try {
                return JSON.parse(text);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                console.error('Response text:', text.substring(0, 500));
                throw new Error('فشل تحليل استجابة JSON من الخادم.');
            }
        });
    });
}

// دوال مساعدة لبناء HTML من JSON (استخدام formatCurrency الموجود أعلاه)

function formatCurrencySimple(value) {
    const number = Number(value || 0);
    return number.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
}

function renderRepInvoices(rows, tableBody) {
    if (!tableBody) return;
    tableBody.innerHTML = '';
    
    // تحديد عدد الأعمدة بناءً على السياق (Card أو Modal)
    const isCard = tableBody.closest('#repCustomerHistoryCard') !== null;
    const colSpan = isCard ? 4 : 8;
    
    if (!Array.isArray(rows) || rows.length === 0) {
        const emptyRow = document.createElement('tr');
        const emptyCell = document.createElement('td');
        emptyCell.colSpan = colSpan;
        emptyCell.className = 'text-center text-muted py-4';
        emptyCell.textContent = 'لا توجد فواتير خلال النافذة الزمنية.';
        emptyRow.appendChild(emptyCell);
        tableBody.appendChild(emptyRow);
        return;
    }
    
    const basePath = '<?php echo getBasePath(); ?>';
    
    rows.forEach(function (row) {
        const tr = document.createElement('tr');
        const invoiceId = row.invoice_id || 0;
        const printUrl = basePath + '/print_invoice.php?id=' + encodeURIComponent(invoiceId);
        const printButton = invoiceId > 0 ? `
            <a href="${printUrl}" target="_blank" class="btn btn-sm btn-outline-primary" title="طباعة الفاتورة">
                <i class="bi bi-printer me-1"></i>طباعة
            </a>
        ` : '<span class="text-muted">—</span>';
        
        if (isCard) {
            // نسخة مبسطة للـ Card (4 أعمدة فقط)
            tr.innerHTML = `
                <td>${row.invoice_number || '—'}</td>
                <td>${row.invoice_date || '—'}</td>
                <td>${formatCurrencySimple(row.invoice_total || 0)}</td>
                <td>${row.invoice_status || '—'}</td>
            `;
        } else {
            // النسخة الكاملة للـ Modal (8 أعمدة)
            tr.innerHTML = `
                <td>${row.invoice_number || '—'}</td>
                <td>${row.invoice_date || '—'}</td>
                <td>${formatCurrencySimple(row.invoice_total || 0)}</td>
                <td>${formatCurrencySimple(row.paid_amount || 0)}</td>
                <td>
                    <span class="text-danger fw-semibold">${formatCurrencySimple(row.return_total || 0)}</span>
                    <div class="text-muted small">${row.return_count || 0} مرتجع</div>
                </td>
                <td>${formatCurrencySimple(row.net_total || 0)}</td>
                <td>${row.invoice_status || '—'}</td>
                <td>${printButton}</td>
            `;
        }
        
        tableBody.appendChild(tr);
    });
}

function renderRepReturns(list, container) {
    if (!container) return;
    container.innerHTML = '';
    
    if (!Array.isArray(list) || list.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'text-muted';
        empty.textContent = 'لا توجد مرتجعات خلال الفترة.';
        container.appendChild(empty);
        return;
    }
    
    const group = document.createElement('div');
    group.className = 'list-group list-group-flush';
    
    list.forEach(function (item) {
        const row = document.createElement('div');
        row.className = 'list-group-item';
        row.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">رقم المرتجع: ${item.return_number || '—'}</div>
                    <div class="text-muted small">
                        التاريخ: ${item.return_date || '—'} | النوع: ${item.return_type || '—'}
                    </div>
                </div>
                <div class="text-danger fw-semibold">${formatCurrencySimple(item.refund_amount || 0)}</div>
            </div>
            <div class="text-muted small mt-1">الحالة: ${item.status || '—'}</div>
        `;
        group.appendChild(row);
    });
    
    container.appendChild(group);
}

function displayRepReturnHistory(history, tableBody) {
    if (!tableBody) return;
    tableBody.innerHTML = '';
    
    if (!Array.isArray(history) || history.length === 0) {
        // تحديد عدد الأعمدة بناءً على السياق (Card أو Modal)
        const isCard = tableBody.closest('#repCustomerReturnCard') !== null;
        const colSpan = isCard ? 6 : 11;
        tableBody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center text-muted">لا توجد مشتريات متاحة للإرجاع</td></tr>`;
        return;
    }
    
    // تحديد نوع checkbox بناءً على السياق
    const isCard = tableBody.closest('#repCustomerReturnCard') !== null;
    const checkboxClass = isCard ? 'rep-return-card-item-checkbox' : 'rep-return-item-checkbox';
    
    history.forEach(function(item) {
        if (!item.can_return) {
            return; // Skip items that can't be returned
        }
        
        const row = document.createElement('tr');
        
        if (isCard) {
            // نسخة مبسطة للـ Card (أعمدة أقل)
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="form-check-input ${checkboxClass}" 
                           data-invoice-id="${item.invoice_id}"
                           data-invoice-item-id="${item.invoice_item_id}"
                           data-product-id="${item.product_id}"
                           data-product-name="${escapeHtml(item.product_name || '')}"
                           data-unit-price="${item.unit_price || 0}"
                           data-batch-number-ids='${JSON.stringify(item.batch_number_ids || [])}'
                           data-batch-numbers='${JSON.stringify(item.batch_numbers || [])}'>
                </td>
                <td>${item.invoice_number || '-'}</td>
                <td>${escapeHtml(item.product_name || '-')}</td>
                <td><strong>${parseFloat(item.available_to_return || 0).toFixed(2)}</strong></td>
                <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewRepReturnItemDetails(${item.invoice_item_id})">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            `;
        } else {
            // النسخة الكاملة للـ Modal
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="form-check-input ${checkboxClass}" 
                           data-invoice-id="${item.invoice_id}"
                           data-invoice-item-id="${item.invoice_item_id}"
                           data-product-id="${item.product_id}"
                           data-product-name="${escapeHtml(item.product_name || '')}"
                           data-unit-price="${item.unit_price || 0}"
                           data-batch-number-ids='${JSON.stringify(item.batch_number_ids || [])}'
                           data-batch-numbers='${JSON.stringify(item.batch_numbers || [])}'>
                </td>
                <td>${item.invoice_number || '-'}</td>
                <td>${(item.batch_numbers || []).join(', ') || '-'}</td>
                <td>${escapeHtml(item.product_name || '-')}</td>
                <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
                <td>${parseFloat(item.returned_quantity || 0).toFixed(2)}</td>
                <td><strong>${parseFloat(item.available_to_return || 0).toFixed(2)}</strong></td>
                <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
                <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
                <td>${item.purchase_date || '-'}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewRepReturnItemDetails(${item.invoice_item_id})">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            `;
        }
        
        tableBody.appendChild(row);
    });
}

function viewRepReturnItemDetails(invoiceItemId) {
    // يمكن إضافة وظيفة لعرض تفاصيل العنصر
    console.log('View details for item:', invoiceItemId);
}

    // تهيئة modal تفاصيل المندوب - تنظيف الطلبات عند إغلاق الـ modal
document.addEventListener('DOMContentLoaded', function() {
    const repDetailsModal = document.getElementById('repDetailsModal');
    if (repDetailsModal) {
        // إزالة backdrop فوراً عند بدء الإغلاق
        repDetailsModal.addEventListener('hide.bs.modal', function(e) {
            // إزالة backdrop فوراً بدون انتظار
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.transition = 'none';
                backdrop.style.opacity = '0';
                backdrop.remove();
            });
            // تنظيف body classes فوراً
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        repDetailsModal.addEventListener('hidden.bs.modal', function() {
            // إلغاء أي طلبات جارية عند إغلاق الـ modal
            if (currentRepDetailsAbortController) {
                currentRepDetailsAbortController.abort();
                currentRepDetailsAbortController = null;
            }
            
            // تنظيف backdrop المتبقي (احتياطي)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
    }
});

    // تهيئة modal التحصيل
document.addEventListener('DOMContentLoaded', function() {
    const repCollectModal = document.getElementById('repCollectPaymentModal');
    if (repCollectModal) {
        // إزالة backdrop فوراً عند بدء الإغلاق
        repCollectModal.addEventListener('hide.bs.modal', function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.transition = 'none';
                backdrop.style.opacity = '0';
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        repCollectModal.addEventListener('hidden.bs.modal', function() {
            // تنظيف backdrop المتبقي (احتياطي)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        const nameElement = repCollectModal.querySelector('.rep-collection-customer-name');
        const debtElement = repCollectModal.querySelector('.rep-collection-current-debt');
        const customerIdInput = repCollectModal.querySelector('#repCollectionCustomerId');
        const amountInput = repCollectModal.querySelector('input[name="amount"]');
        const collectionForm = repCollectModal.querySelector('#repCollectionForm');
        const errorDiv = repCollectModal.querySelector('#repCollectionError');
        const successDiv = repCollectModal.querySelector('#repCollectionSuccess');
        const submitBtn = repCollectModal.querySelector('#repCollectionSubmitBtn');

        // معالجة إرسال النموذج عبر AJAX
        if (collectionForm) {
            collectionForm.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const customerId = customerIdInput?.value || '';
                const amount = amountInput?.value || '';
                
                if (!customerId || customerId <= 0) {
                    if (errorDiv) {
                        errorDiv.textContent = 'معرف العميل غير صالح';
                        errorDiv.classList.remove('d-none');
                    }
                    return;
                }
                
                if (!amount || parseFloat(amount) <= 0) {
                    if (errorDiv) {
                        errorDiv.textContent = 'يجب إدخال مبلغ تحصيل أكبر من صفر';
                        errorDiv.classList.remove('d-none');
                    }
                    return;
                }
                
                // إخفاء رسائل الخطأ والنجاح السابقة
                if (errorDiv) errorDiv.classList.add('d-none');
                if (successDiv) successDiv.classList.add('d-none');
                
                // تعطيل الزر وإظهار loading
                const originalBtnHtml = submitBtn?.innerHTML || '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التحصيل...';
                }
                
                // إرسال الطلب عبر AJAX
                const apiUrl = '<?php echo getRelativeUrl("api/collect_from_rep_customer.php"); ?>';
                const formData = new FormData();
                formData.append('customer_id', customerId);
                formData.append('amount', amount);
                
                fetch(apiUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => {
                    // التحقق من نوع الاستجابة
                    const contentType = response.headers.get('content-type') || '';
                    if (!contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('استجابة غير صحيحة من الخادم');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('API Response:', data);
                    if (data.success) {
                        if (successDiv) {
                            successDiv.textContent = data.message || 'تم التحصيل بنجاح';
                            successDiv.classList.remove('d-none');
                        }
                        
                        // إعادة تحميل الصفحة بعد ثانيتين
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        if (errorDiv) {
                            errorDiv.textContent = data.message || 'حدث خطأ أثناء التحصيل';
                            errorDiv.classList.remove('d-none');
                        }
                        
                        // إعادة تعيين الزر
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnHtml;
                        }
                    }
                })
                .catch(error => {
                    console.error('Collection error:', error);
                    if (errorDiv) {
                        errorDiv.textContent = 'حدث خطأ أثناء إرسال الطلب. يرجى المحاولة مرة أخرى.';
                        errorDiv.classList.remove('d-none');
                    }
                    
                    // إعادة تعيين الزر
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnHtml;
                    }
                });
            });
        }

        if (nameElement && debtElement && customerIdInput && amountInput) {
            repCollectModal.addEventListener('show.bs.modal', function (event) {
                const triggerButton = event.relatedTarget;
                if (!triggerButton) {
                    return;
                }

                const customerName = triggerButton.getAttribute('data-customer-name') || '-';
                const balanceRaw = triggerButton.getAttribute('data-customer-balance') || '0';
                const balanceFormatted = triggerButton.getAttribute('data-customer-balance-formatted') || balanceRaw;
                let numericBalance = parseFloat(balanceRaw);
                if (!Number.isFinite(numericBalance)) {
                    numericBalance = 0;
                }
                const debtAmount = numericBalance > 0 ? numericBalance : 0;

                nameElement.textContent = customerName;
                debtElement.textContent = balanceFormatted;
                if (customerIdInput) {
                    customerIdInput.value = triggerButton.getAttribute('data-customer-id') || '';
                }
                
                // إخفاء رسائل الخطأ والنجاح
                if (errorDiv) errorDiv.classList.add('d-none');
                if (successDiv) successDiv.classList.add('d-none');

                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
                if (debtAmount > 0) {
                    amountInput.focus();
                }
            });

            repCollectModal.addEventListener('hidden.bs.modal', function () {
                if (amountInput) {
                    amountInput.value = '';
                    amountInput.removeAttribute('max');
                    amountInput.removeAttribute('min');
                    amountInput.readOnly = false;
                }
                if (customerIdInput) {
                    customerIdInput.value = '';
                }
            });
        }
    }
    
    // معالجة أزرار التحصيل لمنع propagation
    document.addEventListener('click', function(e) {
        if (e.target.closest('.rep-collect-btn')) {
            const button = e.target.closest('.rep-collect-btn');
            if (button.hasAttribute('disabled')) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            // السماح للـ Bootstrap modal بالعمل بشكل طبيعي
        }
    });

    // معالجة أزرار سجل المشتريات (delegation للعناصر الديناميكية)
    document.addEventListener('click', function(e) {
        if (e.target.closest('.rep-history-btn.js-customer-history')) {
            e.preventDefault();
            e.stopPropagation();
            const button = e.target.closest('.rep-history-btn.js-customer-history');
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name') || '-';
            
            closeAllForms();
            
            var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    if (isMobileDevice) {
                // على الموبايل: استخدام Card
                const card = document.getElementById('repCustomerHistoryCard');
                if (card) {
                    const nameElement = card.querySelector('.rep-history-card-customer-name');
                    const loadingElement = card.querySelector('.rep-history-card-loading');
                    const contentElement = card.querySelector('.rep-history-card-content');
                    const errorElement = card.querySelector('.rep-history-card-error');
                    const invoicesTableBody = card.querySelector('.rep-history-card-table tbody');
                    const returnsContainer = card.querySelector('.rep-history-card-returns');
                    const totalInvoicesEl = card.querySelector('.rep-history-card-total-invoices');
                    const totalInvoicedEl = card.querySelector('.rep-history-card-total-invoiced');
                    const totalReturnsEl = card.querySelector('.rep-history-card-total-returns');
                    const netTotalEl = card.querySelector('.rep-history-card-net-total');
                    
                    if (nameElement) nameElement.textContent = customerName;
                    if (loadingElement) loadingElement.classList.remove('d-none');
                    if (contentElement) contentElement.classList.add('d-none');
                    if (errorElement) errorElement.classList.add('d-none');
                    if (invoicesTableBody) invoicesTableBody.innerHTML = '';
                    if (returnsContainer) returnsContainer.innerHTML = '';
                    
                    card.style.display = 'block';
                    setTimeout(function() {
                        if (typeof scrollToElement === 'function') {
                            scrollToElement(card);
                        } else {
                            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }, 50);
                    
                    // جلب بيانات سجل المشتريات من API endpoint
                    const basePath = '<?php echo getBasePath(); ?>';
                    const historyUrl = basePath + '/api/customer_history_api.php?customer_id=' + encodeURIComponent(customerId);
                    
                    console.log('Fetching history from:', historyUrl);
                    
                    fetchJson(historyUrl)
                    .then(payload => {
                        if (!payload || !payload.success) {
                            throw new Error(payload?.message || 'فشل تحميل بيانات السجل.');
                        }
                        
                        const history = payload.history || {};
                        const totals = history.totals || {};
                        
                        // تحديث الإحصائيات
                        if (totalInvoicesEl) {
                            totalInvoicesEl.textContent = Number(totals.invoice_count || 0).toLocaleString('ar-EG');
                        }
                        if (totalInvoicedEl) {
                            totalInvoicedEl.textContent = formatCurrencySimple(totals.total_invoiced || 0);
                        }
                        if (totalReturnsEl) {
                            totalReturnsEl.textContent = formatCurrencySimple(totals.total_returns || 0);
                        }
                        if (netTotalEl) {
                            netTotalEl.textContent = formatCurrencySimple(totals.net_total || 0);
                        }
                        
                        // عرض البيانات - استخدام نفس الدوال
                        if (invoicesTableBody) {
                            renderRepInvoices(Array.isArray(history.invoices) ? history.invoices : [], invoicesTableBody);
                        }
                        if (returnsContainer) {
                            renderRepReturns(Array.isArray(history.returns) ? history.returns : [], returnsContainer);
                        }
                        
                        // إخفاء loading وإظهار المحتوى
                        if (loadingElement) loadingElement.classList.add('d-none');
                        if (contentElement) contentElement.classList.remove('d-none');
                        if (errorElement) errorElement.classList.add('d-none');
                    })
                    .catch(error => {
                        console.error('Error loading history:', error);
                        if (loadingElement) loadingElement.classList.add('d-none');
                        if (errorElement) {
                            errorElement.textContent = error.message || 'حدث خطأ أثناء تحميل سجل المشتريات';
                            errorElement.classList.remove('d-none');
                        }
                    });
                }
            } else {
                // على الكمبيوتر: استخدام Modal
                const historyModal = document.getElementById('repCustomerHistoryModal');
                if (historyModal) {
                    const nameElement = historyModal.querySelector('.rep-history-customer-name');
                    const loadingElement = historyModal.querySelector('.rep-history-loading');
                    const contentElement = historyModal.querySelector('.rep-history-content');
                    const errorElement = historyModal.querySelector('.rep-history-error');
                    const invoicesTableBody = historyModal.querySelector('.rep-history-table tbody');
                    const returnsContainer = historyModal.querySelector('.rep-history-returns');
                    const totalInvoicesEl = historyModal.querySelector('.rep-history-total-invoices');
                    const totalInvoicedEl = historyModal.querySelector('.rep-history-total-invoiced');
                    const totalReturnsEl = historyModal.querySelector('.rep-history-total-returns');
                    const netTotalEl = historyModal.querySelector('.rep-history-net-total');
                    
                    if (nameElement) nameElement.textContent = customerName;
                    if (loadingElement) loadingElement.classList.remove('d-none');
                    if (contentElement) contentElement.classList.add('d-none');
                    if (errorElement) errorElement.classList.add('d-none');
                    if (invoicesTableBody) invoicesTableBody.innerHTML = '';
                    if (returnsContainer) returnsContainer.innerHTML = '';
                    
                    const modalInstance = bootstrap.Modal.getOrCreateInstance(historyModal);
                    modalInstance.show();
                
                // جلب بيانات سجل المشتريات من API endpoint
                const basePath = '<?php echo getBasePath(); ?>';
                const historyUrl = basePath + '/api/customer_history_api.php?customer_id=' + encodeURIComponent(customerId);
                
                console.log('Fetching history from:', historyUrl);
                
                fetchJson(historyUrl)
                .then(payload => {
                    if (!payload || !payload.success) {
                        throw new Error(payload?.message || 'فشل تحميل بيانات السجل.');
                    }
                    
                    const history = payload.history || {};
                    const totals = history.totals || {};
                    
                    // تحديث الإحصائيات
                    if (totalInvoicesEl) {
                        totalInvoicesEl.textContent = Number(totals.invoice_count || 0).toLocaleString('ar-EG');
                    }
                    if (totalInvoicedEl) {
                        totalInvoicedEl.textContent = formatCurrencySimple(totals.total_invoiced || 0);
                    }
                    if (totalReturnsEl) {
                        totalReturnsEl.textContent = formatCurrencySimple(totals.total_returns || 0);
                    }
                    if (netTotalEl) {
                        netTotalEl.textContent = formatCurrencySimple(totals.net_total || 0);
                    }
                    
                    // عرض البيانات
                    renderRepInvoices(Array.isArray(history.invoices) ? history.invoices : [], invoicesTableBody);
                    renderRepReturns(Array.isArray(history.returns) ? history.returns : [], returnsContainer);
                    
                    // إخفاء loading وإظهار المحتوى
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (contentElement) contentElement.classList.remove('d-none');
                    if (errorElement) errorElement.classList.add('d-none');
                })
                .catch(error => {
                    console.error('Error loading history:', error);
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (errorElement) {
                        errorElement.textContent = error.message || 'حدث خطأ أثناء تحميل سجل المشتريات';
                        errorElement.classList.remove('d-none');
                    }
                });
            } else {
                // Fallback: فتح في نافذة جديدة
                const baseUrl = <?php echo json_encode(getRelativeUrl($dashboardScript), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                const historyUrl = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=customers&section=company&action=purchase_history&customer_id=' + encodeURIComponent(customerId);
                window.open(historyUrl, '_blank');
            }
        }
        
        if (e.target.closest('.rep-return-btn.js-customer-purchase-history')) {
            e.preventDefault();
            e.stopPropagation();
            const button = e.target.closest('.rep-return-btn.js-customer-purchase-history');
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name') || '-';
            
            const returnModal = document.getElementById('repCustomerReturnModal');
            if (returnModal) {
                const nameElement = returnModal.querySelector('.rep-return-customer-name');
                const loadingElement = returnModal.querySelector('.rep-return-loading');
                const contentElement = returnModal.querySelector('.rep-return-content');
                const errorElement = returnModal.querySelector('.rep-return-error');
                const tableBody = returnModal.querySelector('.rep-return-table tbody');
                const customerPhoneEl = returnModal.querySelector('.rep-return-customer-phone');
                const customerAddressEl = returnModal.querySelector('.rep-return-customer-address');
                
                if (nameElement) nameElement.textContent = customerName;
                if (loadingElement) loadingElement.classList.remove('d-none');
                if (contentElement) contentElement.classList.add('d-none');
                if (errorElement) errorElement.classList.add('d-none');
                if (tableBody) tableBody.innerHTML = '';
                
                const modalInstance = bootstrap.Modal.getOrCreateInstance(returnModal);
                modalInstance.show();
                
                // جلب بيانات سجل المشتريات للإرجاع من API
                const basePath = '<?php echo getBasePath(); ?>';
                const returnUrl = basePath + '/api/customer_purchase_history.php?action=get_history&customer_id=' + encodeURIComponent(customerId);
                
                fetchJson(returnUrl)
                .then(data => {
                    if (loadingElement) loadingElement.classList.add('d-none');
                    
                    if (data.success) {
                        // تحديث معلومات العميل
                        if (data.customer) {
                            if (customerPhoneEl) customerPhoneEl.textContent = data.customer.phone || '-';
                            if (customerAddressEl) customerAddressEl.textContent = data.customer.address || '-';
                        }
                        
                        // عرض بيانات المشتريات
                        const purchaseHistory = data.purchase_history || [];
                        displayRepReturnHistory(purchaseHistory, tableBody);
                        
                        if (contentElement) {
                            contentElement.classList.remove('d-none');
                        }
                    } else {
                        if (errorElement) {
                            errorElement.textContent = data.message || 'حدث خطأ أثناء تحميل بيانات الإرجاع';
                            errorElement.classList.remove('d-none');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading return data:', error);
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (errorElement) {
                        errorElement.textContent = error.message || 'حدث خطأ أثناء تحميل بيانات الإرجاع';
                        errorElement.classList.remove('d-none');
                    }
                });
            } else {
                // Fallback: فتح في نافذة جديدة
                const baseUrl = <?php echo json_encode(getRelativeUrl($dashboardScript), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                const returnUrl = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=customers&section=company&action=purchase_history&ajax=purchase_history&customer_id=' + encodeURIComponent(customerId);
                window.open(returnUrl, '_blank');
            }
        }
        
        // معالجة أزرار عرض الموقع
        if (e.target.closest('.rep-location-view-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const button = e.target.closest('.rep-location-view-btn');
            const latitude = button.getAttribute('data-latitude');
            const longitude = button.getAttribute('data-longitude');
            const customerName = button.getAttribute('data-customer-name') || button.closest('tr')?.querySelector('td:first-child')?.textContent?.trim() || '-';
            
            if (!latitude || !longitude) {
                alert('لا يوجد موقع مسجل لهذا العميل.');
                return;
            }
            
            closeAllForms();
            
            var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    if (isMobileDevice) {
                // على الموبايل: استخدام Card
                const card = document.getElementById('repViewLocationCard');
                if (card) {
                    const locationCustomerName = card.querySelector('.rep-location-card-customer-name');
                    const locationMapFrame = card.querySelector('.rep-location-card-map-frame');
                    const locationExternalLink = card.querySelector('.rep-location-card-open-map');
                    
                    if (locationCustomerName) {
                        locationCustomerName.textContent = customerName;
                    }
                    
                    if (locationMapFrame) {
                        const embedUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                        locationMapFrame.src = embedUrl;
                    }
                    
                    if (locationExternalLink) {
                        const externalUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                        locationExternalLink.href = externalUrl;
                    }
                    
                    card.style.display = 'block';
                    setTimeout(function() {
                        if (typeof scrollToElement === 'function') {
                            scrollToElement(card);
                        } else {
                            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }, 50);
                }
            } else {
                // على الكمبيوتر: استخدام Modal
                const viewLocationModal = document.getElementById('repViewLocationModal');
                if (viewLocationModal) {
                    const locationCustomerName = viewLocationModal.querySelector('.rep-location-customer-name');
                    const locationMapFrame = viewLocationModal.querySelector('.rep-location-map-frame');
                    const locationExternalLink = viewLocationModal.querySelector('.rep-location-open-map');
                    
                    if (locationCustomerName) {
                        locationCustomerName.textContent = customerName;
                    }
                    
                    if (locationMapFrame) {
                        const embedUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                        locationMapFrame.src = embedUrl;
                    }
                    
                    if (locationExternalLink) {
                        const externalUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                        locationExternalLink.href = externalUrl;
                    }
                    
                    const modalInstance = bootstrap.Modal.getOrCreateInstance(viewLocationModal);
                    modalInstance.show();
                } else {
                    // Fallback: فتح في نافذة جديدة إذا لم يوجد modal
                    const url = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                    window.open(url, '_blank');
                }
            }
        }
        
        // معالجة أزرار تحديد الموقع
        if (e.target.closest('.rep-location-capture-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const button = e.target.closest('.rep-location-capture-btn');
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name') || '';
            
            if (!customerId) {
                alert('تعذر تحديد العميل.');
                return;
            }
            
            if (!navigator.geolocation) {
                alert('المتصفح الحالي لا يدعم تحديد الموقع الجغرافي.');
                return;
            }
            
            // تعطيل الزر وإظهار loading
            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جارٍ التحديد...';
            
            navigator.geolocation.getCurrentPosition(
                function (position) {
                    const latitude = position.coords.latitude.toFixed(8);
                    const longitude = position.coords.longitude.toFixed(8);
                    const baseUrl = <?php echo json_encode(getRelativeUrl($dashboardScript), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                    const requestUrl = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=representatives_customers';
                    
                    const formData = new URLSearchParams();
                    formData.append('action', 'update_location');
                    formData.append('customer_id', customerId);
                    formData.append('latitude', latitude);
                    formData.append('longitude', longitude);
                    
                    fetch(requestUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: formData.toString()
                    })
                    .then(response => {
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            return response.text().then(text => {
                                throw new Error('استجابة غير صالحة من الخادم');
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            alert('تم تحديد موقع العميل بنجاح.');
                            // إعادة تحميل بيانات المندوب
                            const repId = button.closest('#repDetailsModal')?.dataset?.repId || 
                                         document.querySelector('[data-rep-id]')?.dataset?.repId ||
                                         document.querySelector('.representative-card[data-rep-id]')?.dataset?.repId;
                            if (repId) {
                                const repName = document.getElementById('repModalName')?.textContent?.replace('تفاصيل: ', '') || '';
                                loadRepDetails(repId, repName);
                            }
                        } else {
                            alert(data.message || 'فشل تحديد الموقع. يرجى المحاولة مرة أخرى.');
                        }
                    })
                    .catch(error => {
                        console.error('Error updating location:', error);
                        alert('حدث خطأ أثناء تحديد الموقع. يرجى المحاولة مرة أخرى.');
                    })
                    .finally(() => {
                        button.disabled = false;
                        button.innerHTML = originalHtml;
                    });
                },
                function (error) {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                    if (error.code === error.PERMISSION_DENIED) {
                        alert('لم يتم منح صلاحية الموقع. يرجى تمكينها من إعدادات المتصفح.');
                    } else {
                        alert('تعذر تحديد الموقع. يرجى المحاولة مرة أخرى.');
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }
    });
});
</script>

<!-- Modal تحصيل ديون العميل - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="repCollectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form id="repCollectionForm">
                <input type="hidden" name="customer_id" id="repCollectionCustomerId" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">العميل</div>
                        <div class="fs-5 rep-collection-customer-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning rep-collection-current-debt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="repCollectionAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input
                            type="number"
                            class="form-control"
                            id="repCollectionAmount"
                            name="amount"
                            step="0.01"
                            min="0.01"
                            required
                        >
                        <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
                    </div>
                    <div id="repCollectionError" class="alert alert-danger d-none" role="alert"></div>
                    <div id="repCollectionSuccess" class="alert alert-success d-none" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary" id="repCollectionSubmitBtn">
                        <i class="bi bi-check-circle me-1"></i>تحصيل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card تحصيل ديون العميل - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="repCollectPaymentCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل
        </h5>
    </div>
    <div class="card-body">
        <form id="repCollectionCardForm">
            <input type="hidden" name="customer_id" id="repCollectionCardCustomerId" value="">
            <div class="mb-3">
                <div class="fw-semibold text-muted">العميل</div>
                <div class="fs-5 rep-collection-card-customer-name">-</div>
            </div>
            <div class="mb-3">
                <div class="fw-semibold text-muted">الديون الحالية</div>
                <div class="fs-5 text-warning rep-collection-card-current-debt">-</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="repCollectionCardAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                <input
                    type="number"
                    class="form-control"
                    id="repCollectionCardAmount"
                    name="amount"
                    step="0.01"
                    min="0.01"
                    required
                >
                <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
            </div>
            <div id="repCollectionCardError" class="alert alert-danger d-none" role="alert"></div>
            <div id="repCollectionCardSuccess" class="alert alert-success d-none" role="alert"></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="repCollectionCardSubmitBtn">
                    <i class="bi bi-check-circle me-1"></i>تحصيل
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeRepCollectPaymentCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
// معالج form submit للـ Card على الموبايل
document.addEventListener('DOMContentLoaded', function() {
    const repCollectionCardForm = document.getElementById('repCollectionCardForm');
    if (repCollectionCardForm) {
        repCollectionCardForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const customerId = document.getElementById('repCollectionCardCustomerId')?.value || '';
            const amount = document.getElementById('repCollectionCardAmount')?.value || '';
            const errorDiv = document.getElementById('repCollectionCardError');
            const successDiv = document.getElementById('repCollectionCardSuccess');
            const submitBtn = document.getElementById('repCollectionCardSubmitBtn');
            
            if (!customerId || customerId <= 0) {
                if (errorDiv) {
                    errorDiv.textContent = 'معرف العميل غير صالح';
                    errorDiv.classList.remove('d-none');
                }
                return;
            }
            
            if (!amount || parseFloat(amount) <= 0) {
                if (errorDiv) {
                    errorDiv.textContent = 'يجب إدخال مبلغ تحصيل أكبر من صفر';
                    errorDiv.classList.remove('d-none');
                }
                return;
            }
            
            // إخفاء رسائل الخطأ والنجاح السابقة
            if (errorDiv) errorDiv.classList.add('d-none');
            if (successDiv) successDiv.classList.add('d-none');
            
            // تعطيل الزر وإظهار loading
            const originalBtnHtml = submitBtn?.innerHTML || '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التحصيل...';
            }
            
            // إرسال الطلب عبر AJAX
            const apiUrl = '<?php echo getRelativeUrl("api/collect_from_rep_customer.php"); ?>';
            const formData = new FormData();
            formData.append('customer_id', customerId);
            formData.append('amount', amount);
            
            fetch(apiUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    return response.text().then(text => {
                        console.error('Non-JSON response:', text);
                        throw new Error('استجابة غير صحيحة من الخادم');
                    });
                }
                return response.json();
            })
            .then(data => {
                console.log('API Response:', data);
                if (data.success) {
                    if (successDiv) {
                        successDiv.textContent = data.message || 'تم التحصيل بنجاح';
                        successDiv.classList.remove('d-none');
                    }
                    
                    // إعادة تحميل الصفحة بعد ثانيتين
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    if (errorDiv) {
                        errorDiv.textContent = data.message || 'حدث خطأ أثناء التحصيل';
                        errorDiv.classList.remove('d-none');
                    }
                    
                    // إعادة تعيين الزر
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnHtml;
                    }
                }
            })
            .catch(error => {
                console.error('Collection error:', error);
                if (errorDiv) {
                    errorDiv.textContent = 'حدث خطأ أثناء إرسال الطلب. يرجى المحاولة مرة أخرى.';
                    errorDiv.classList.remove('d-none');
                }
                
                // إعادة تعيين الزر
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml;
                }
            });
        });
    }
});
</script>

<style>
/* تحسينات المودالات على الهواتف - repCollectPaymentModal */
#repCollectPaymentModal .modal-dialog {
    margin: 0.5rem;
    display: flex;
    flex-direction: column;
}

#repCollectPaymentModal .modal-content {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    max-height: none !important;
    height: auto !important;
}

/* إصلاح المساحة البيضاء الفارغة - استخدام auto height بدلاً من flex: 1 */
#repCollectPaymentModal .modal-body {
    overflow-y: auto;
    flex: 0 1 auto !important; /* تغيير من 1 1 auto إلى 0 1 auto لإزالة المساحة الفارغة */
    min-height: 0;
    padding-bottom: 1rem;
    max-height: none !important;
    height: auto !important;
}

#repCollectPaymentModal .modal-footer {
    flex-shrink: 0 !important;
    margin-top: 0 !important; /* إزالة margin-top: auto */
    padding-top: 1rem;
    padding-bottom: 1rem;
    border-top: 1px solid #dee2e6;
}

/* إصلاح الجزء الأبيض الفارغ */
#repCollectPaymentModal .modal-content::after {
    display: none;
}

/* تسريع إغلاق النماذج - إزالة جميع الـ transitions */
.modal-backdrop {
    transition: none !important; /* إزالة transition تماماً */
}

.modal-backdrop.fade {
    opacity: 0 !important;
}

.modal-backdrop.show {
    opacity: 0.5 !important;
}

/* إزالة animation الإغلاق تماماً */
.modal.fade .modal-dialog {
    transition: none !important; /* إزالة transition تماماً */
}

.modal.fade:not(.show) .modal-dialog {
    transform: none !important;
    opacity: 0 !important;
}

/* تحسينات إضافية للهواتف */
@media (max-width: 768px) {
    #repCollectPaymentModal .modal-dialog {
        margin: 0.5rem;
        max-width: calc(100% - 1rem);
        max-height: calc(100vh - 1rem) !important;
    }
    
    #repCollectPaymentModal .modal-content {
        max-height: calc(100vh - 1rem) !important;
        height: auto !important;
    }
    
    #repCollectPaymentModal .modal-body {
        flex: 0 1 auto !important;
        padding-bottom: 1rem;
        max-height: none !important;
        height: auto !important;
    }
    
    #repCollectPaymentModal .modal-footer {
        padding-top: 1rem;
        padding-bottom: 1rem;
        margin-top: 0 !important;
    }
    }
    
    /* Autocomplete Dropdown للبحث الفوري */
    .autocomplete-dropdown {
        position: absolute;
        top: calc(100% + 2px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-top: none;
        border-radius: 0 0 10px 10px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        max-height: 400px;
        overflow-y: auto;
        z-index: 1050 !important;
        margin-top: 0;
        display: none;
        width: 100%;
        visibility: hidden;
        opacity: 0;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .autocomplete-dropdown.show {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    .autocomplete-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    .autocomplete-item:last-child {
        border-bottom: none;
    }
    .autocomplete-item:hover,
    .autocomplete-item.selected {
        background-color: #f8fafc;
    }
    .autocomplete-item-name {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.95rem;
    }
    .autocomplete-item-sub {
        font-size: 0.85rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .autocomplete-item-balance {
        margin-right: auto;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .autocomplete-item-balance.positive {
        background-color: #fef3c7;
        color: #92400e;
    }
    .autocomplete-item-balance.negative {
        background-color: #dbeafe;
        color: #1e40af;
    }
    .autocomplete-item-balance.zero {
        background-color: #f1f5f9;
        color: #64748b;
    }
    .autocomplete-no-results {
        padding: 1rem;
        text-align: center;
        color: #94a3b8;
        font-size: 0.9rem;
    }
    .autocomplete-loading {
        padding: 1rem;
        text-align: center;
        color: #64748b;
        font-size: 0.9rem;
    }
    
    @media (max-width: 768px) {
        .autocomplete-dropdown {
            max-height: 300px;
            font-size: 0.9rem;
        }
        .autocomplete-item {
            padding: 0.65rem 0.85rem;
        }
        .autocomplete-item-name {
            font-size: 0.9rem;
        }
        .autocomplete-item-sub {
            font-size: 0.8rem;
        }
        .autocomplete-item-balance {
            font-size: 0.75rem;
            padding: 0.1rem 0.4rem;
        }
    }
</style>

<!-- Modal عرض موقع العميل - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="repViewLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>موقع العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">العميل</div>
                    <div class="fs-5 fw-bold rep-location-customer-name">-</div>
                </div>
                <div class="ratio ratio-16x9">
                    <iframe
                        class="rep-location-map-frame border rounded"
                        src=""
                        title="معاينة موقع العميل"
                        allowfullscreen
                        loading="lazy"
                    ></iframe>
                </div>
                <p class="mt-3 text-muted mb-0">
                    يمكنك متابعة الموقع داخل المعاينة أو فتحه في خرائط Google للحصول على اتجاهات دقيقة.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <a href="#" target="_blank" rel="noopener" class="btn btn-primary rep-location-open-map">
                    <i class="bi bi-map me-1"></i> فتح في الخرائط
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Card عرض موقع العميل - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="repViewLocationCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-geo-alt me-2"></i>موقع العميل
        </h5>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="text-muted small fw-semibold">العميل</div>
            <div class="fs-5 fw-bold rep-location-card-customer-name">-</div>
        </div>
        <div class="ratio ratio-16x9">
            <iframe
                class="rep-location-card-map-frame border rounded"
                src=""
                title="معاينة موقع العميل"
                allowfullscreen
                loading="lazy"
            ></iframe>
        </div>
        <p class="mt-3 text-muted mb-0">
            يمكنك متابعة الموقع داخل المعاينة أو فتحه في خرائط Google للحصول على اتجاهات دقيقة.
        </p>
        <div class="d-flex gap-2 mt-3">
            <a href="#" target="_blank" rel="noopener" class="btn btn-primary rep-location-card-open-map">
                <i class="bi bi-map me-1"></i>فتح في الخرائط
            </a>
            <button type="button" class="btn btn-secondary" onclick="closeRepViewLocationCard()">إغلاق</button>
        </div>
    </div>
</div>

<!-- Modal سجل المشتريات - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="repCustomerHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="bi bi-journal-text me-2"></i>
                    سجل مشتريات العميل - <span class="rep-history-customer-name">-</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="rep-history-loading text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-3 text-muted">جاري تحميل سجل المشتريات...</p>
                </div>
                <div class="rep-history-error alert alert-danger d-none" role="alert"></div>
                <div class="rep-history-content d-none">
                    <!-- الإحصائيات -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">عدد الفواتير</div>
                                    <div class="fs-4 fw-semibold rep-history-total-invoices">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">إجمالي الفواتير</div>
                                    <div class="fs-4 fw-semibold rep-history-total-invoiced">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">إجمالي المرتجعات</div>
                                    <div class="fs-4 fw-semibold text-danger rep-history-total-returns">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">الصافي</div>
                                    <div class="fs-4 fw-semibold text-primary rep-history-net-total">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- الفواتير -->
                    <div class="mb-4">
                        <h6 class="mb-3"><i class="bi bi-receipt me-2"></i>الفواتير</h6>
                        <div class="table-responsive">
                            <table class="table table-hover rep-history-table">
                                <thead>
                                    <tr>
                                        <th>رقم الفاتورة</th>
                                        <th>التاريخ</th>
                                        <th>إجمالي الفاتورة</th>
                                        <th>المدفوع</th>
                                        <th>المرتجعات</th>
                                        <th>الصافي</th>
                                        <th>الحالة</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- المرتجعات -->
                    <div class="mb-4">
                        <h6 class="mb-3"><i class="bi bi-arrow-left me-2"></i>المرتجعات</h6>
                        <div class="rep-history-returns"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Card سجل المشتريات - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="repCustomerHistoryCard" style="display: none;">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="bi bi-journal-text me-2"></i>
            سجل مشتريات العميل - <span class="rep-history-card-customer-name">-</span>
        </h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeRepCustomerHistoryCard()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="card-body">
        <div class="rep-history-card-loading text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">جاري التحميل...</span>
            </div>
            <p class="mt-3 text-muted">جاري تحميل سجل المشتريات...</p>
        </div>
        <div class="rep-history-card-error alert alert-danger d-none" role="alert"></div>
        <div class="rep-history-card-content d-none">
            <!-- الإحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <div class="text-muted small">عدد الفواتير</div>
                            <div class="fs-4 fw-semibold rep-history-card-total-invoices">0</div>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <div class="text-muted small">إجمالي الفواتير</div>
                            <div class="fs-4 fw-semibold rep-history-card-total-invoiced">0.00 ج.م</div>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <div class="text-muted small">إجمالي المرتجعات</div>
                            <div class="fs-4 fw-semibold text-danger rep-history-card-total-returns">0.00 ج.م</div>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <div class="text-muted small">الصافي</div>
                            <div class="fs-4 fw-semibold text-primary rep-history-card-net-total">0.00 ج.م</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- الفواتير -->
            <div class="mb-4">
                <h6 class="mb-3"><i class="bi bi-receipt me-2"></i>الفواتير</h6>
                <div class="table-responsive">
                    <table class="table table-hover rep-history-card-table">
                        <thead>
                            <tr>
                                <th>رقم الفاتورة</th>
                                <th>التاريخ</th>
                                <th>الإجمالي</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            
            <!-- المرتجعات -->
            <div class="mb-4">
                <h6 class="mb-3"><i class="bi bi-arrow-left me-2"></i>المرتجعات</h6>
                <div class="rep-history-card-returns"></div>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <button type="button" class="btn btn-secondary w-100" onclick="closeRepCustomerHistoryCard()">إغلاق</button>
    </div>
</div>

<!-- Modal إنشاء مرتجع - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="repCustomerReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-return-left me-2"></i>
                    سجل مشتريات العميل - إنشاء مرتجع - <span class="rep-return-customer-name">-</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <!-- معلومات العميل -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-muted small">العميل</div>
                                <div class="fs-5 fw-bold rep-return-customer-name">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">الهاتف</div>
                                <div class="rep-return-customer-phone">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">العنوان</div>
                                <div class="rep-return-customer-address">-</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="rep-return-loading text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-3 text-muted">جاري تحميل بيانات المشتريات...</p>
                </div>
                <div class="rep-return-error alert alert-danger d-none" role="alert"></div>
                <div class="rep-return-content d-none">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered rep-return-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">
                                        <input type="checkbox" id="repSelectAllItems" onchange="repToggleAllItems()">
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
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-success" id="repCreateReturnBtn" style="display: none;">
                    <i class="bi bi-arrow-return-left me-1"></i>إنشاء مرتجع
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Card إنشاء مرتجع - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="repCustomerReturnCard" style="display: none;">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="bi bi-arrow-return-left me-2"></i>
            سجل مشتريات العميل - إنشاء مرتجع - <span class="rep-return-card-customer-name">-</span>
        </h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeRepCustomerReturnCard()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="card-body">
        <!-- معلومات العميل -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="row">
                    <div class="col-12 mb-2">
                        <div class="text-muted small">العميل</div>
                        <div class="fs-5 fw-bold rep-return-card-customer-name">-</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">الهاتف</div>
                        <div class="rep-return-card-customer-phone">-</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">العنوان</div>
                        <div class="rep-return-card-customer-address">-</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="rep-return-card-loading text-center py-5">
            <div class="spinner-border text-success" role="status">
                <span class="visually-hidden">جاري التحميل...</span>
            </div>
            <p class="mt-3 text-muted">جاري تحميل بيانات المشتريات...</p>
        </div>
        <div class="rep-return-card-error alert alert-danger d-none" role="alert"></div>
        <div class="rep-return-card-content d-none">
            <div class="table-responsive">
                <table class="table table-hover table-bordered rep-return-card-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="repSelectAllItemsCard" onchange="repToggleAllItemsCard()">
                            </th>
                            <th>رقم الفاتورة</th>
                            <th>اسم المنتج</th>
                            <th>المتاح للإرجاع</th>
                            <th>سعر الوحدة</th>
                            <th style="width: 100px;">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-secondary flex-fill" onclick="closeRepCustomerReturnCard()">إلغاء</button>
            <button type="button" class="btn btn-success flex-fill" id="repCreateReturnCardBtn" style="display: none;">
                <i class="bi bi-arrow-return-left me-1"></i>إنشاء مرتجع
            </button>
        </div>
    </div>
</div>

<script>
function repToggleAllItems() {
    const selectAll = document.getElementById('repSelectAllItems');
    const checkboxes = document.querySelectorAll('.rep-return-item-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
    });
}

function repToggleAllItemsCard() {
    const selectAll = document.getElementById('repSelectAllItemsCard');
    if (!selectAll) return;
    
    const card = document.getElementById('repCustomerReturnCard');
    if (!card) return;
    
    const checkboxes = card.querySelectorAll('.rep-return-card-item-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
    });
}

// دالة فتح Card/Modal تصدير العملاء
function openCustomerExport() {
    closeAllForms();
    
    var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('customerExportCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('customerExportModal');
        if (modal) {
            const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
            modalInstance.show();
        }
    }
}
</script>

<script>
// تنظيف modal عرض الموقع عند الإغلاق
document.addEventListener('DOMContentLoaded', function() {
    const viewLocationModal = document.getElementById('repViewLocationModal');
    if (viewLocationModal) {
        viewLocationModal.addEventListener('hidden.bs.modal', function () {
            const locationMapFrame = viewLocationModal.querySelector('.rep-location-map-frame');
            const locationCustomerName = viewLocationModal.querySelector('.rep-location-customer-name');
            const locationExternalLink = viewLocationModal.querySelector('.rep-location-open-map');
            
            if (locationMapFrame) locationMapFrame.src = '';
            if (locationCustomerName) locationCustomerName.textContent = '-';
            if (locationExternalLink) locationExternalLink.href = '#';
        });
    }
});
</script>

<!-- CSS للجدول الجديد -->
<style>
/* الأزرار في عمود الإجراءات: 2×2 على الشاشات الكبيرة */
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
    .dashboard-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -0.75rem;
        padding: 0 0.75rem;
    }
    
    .dashboard-table {
        min-width: 950px;
        font-size: 0.85rem;
        width: 100%;
        table-layout: fixed;
    }
    
    /* تحديد عرض الأعمدة - 8 أعمدة */
    .dashboard-table thead th:nth-child(1),
    .dashboard-table tbody td:nth-child(1) {
        width: 18%;
        min-width: 110px;
    }
    
    .dashboard-table thead th:nth-child(2),
    .dashboard-table tbody td:nth-child(2) {
        width: 12%;
        min-width: 85px;
    }
    
    .dashboard-table thead th:nth-child(3),
    .dashboard-table tbody td:nth-child(3) {
        width: 10%;
        min-width: 65px;
    }
    
    .dashboard-table thead th:nth-child(4),
    .dashboard-table tbody td:nth-child(4) {
        width: 12%;
        min-width: 75px;
        word-wrap: break-word;
        white-space: normal;
    }
    
    .dashboard-table thead th:nth-child(5),
    .dashboard-table tbody td:nth-child(5) {
        width: 10%;
        min-width: 65px;
    }
    
    .dashboard-table thead th:nth-child(6),
    .dashboard-table tbody td:nth-child(6) {
        width: 10%;
        min-width: 80px;
    }
    
    .dashboard-table thead th:nth-child(7),
    .dashboard-table tbody td:nth-child(7) {
        width: 13%;
        min-width: 90px;
    }
    
    .dashboard-table thead th:nth-child(8),
    .dashboard-table tbody td:nth-child(8) {
        width: 15%;
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
    
    .dashboard-table .btn {
        font-size: 0.7rem;
        padding: 0.25rem 0.4rem;
        white-space: nowrap;
    }
    
    .dashboard-table .btn i {
        font-size: 0.75rem;
    }
    
    .dashboard-table .badge {
        font-size: 0.65rem;
        padding: 0.2rem 0.4rem;
    }
    
    .dashboard-table tbody td:nth-child(7) .d-flex {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.3rem;
    }
    
    .dashboard-table tbody td:nth-child(7) .btn {
        width: 100%;
        justify-content: center;
    }
    
    /* إصلاح تخطيط أزرار الإجراءات على الهاتف - استخدام 3 أعمدة */
    .dashboard-table tbody td:last-child .d-flex {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 0.3rem !important;
        width: 100% !important;
    }
    
    .dashboard-table tbody td:last-child .btn {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        font-size: 0.65rem !important;
        padding: 0.3rem 0.25rem !important;
        white-space: normal !important;
        line-height: 1.2 !important;
        text-align: center !important;
    }
    
    .dashboard-table tbody td:last-child .btn i {
        font-size: 0.7rem !important;
        margin-right: 0.2rem !important;
    }
    
    .dashboard-table tbody td:last-child .badge {
        grid-column: 1 / -1;
        width: 100%;
        text-align: center;
        font-size: 0.6rem !important;
        padding: 0.2rem 0.3rem !important;
    }
}

@media (max-width: 575.98px) {
    .dashboard-table {
        min-width: 900px;
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
    
    .dashboard-table thead th:nth-child(4),
    .dashboard-table tbody td:nth-child(4) {
        display: table-cell !important;
    }
    
    .dashboard-table .badge {
        font-size: 0.6rem;
        padding: 0.15rem 0.35rem;
    }
    
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
        min-width: 75px;
    }
    
    .dashboard-table thead th:nth-child(7),
    .dashboard-table tbody td:nth-child(7) {
        min-width: 85px;
    }
    
    .dashboard-table thead th:nth-child(8),
    .dashboard-table tbody td:nth-child(8) {
        min-width: 130px;
    }
    
    /* تحسين أزرار الإجراءات على الشاشات الصغيرة جداً */
    .dashboard-table tbody td:last-child .d-flex {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.25rem !important;
    }
    
    .dashboard-table tbody td:last-child .btn {
        font-size: 0.6rem !important;
        padding: 0.25rem 0.2rem !important;
    }
    }
    
    /* Autocomplete Dropdown للبحث الفوري */
    .autocomplete-dropdown {
        position: absolute;
        top: calc(100% + 2px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-top: none;
        border-radius: 0 0 10px 10px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        max-height: 400px;
        overflow-y: auto;
        z-index: 1050 !important;
        margin-top: 0;
        display: none;
        width: 100%;
        visibility: hidden;
        opacity: 0;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .autocomplete-dropdown.show {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    .autocomplete-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    .autocomplete-item:last-child {
        border-bottom: none;
    }
    .autocomplete-item:hover,
    .autocomplete-item.selected {
        background-color: #f8fafc;
    }
    .autocomplete-item-name {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.95rem;
    }
    .autocomplete-item-sub {
        font-size: 0.85rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .autocomplete-item-balance {
        margin-right: auto;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .autocomplete-item-balance.positive {
        background-color: #fef3c7;
        color: #92400e;
    }
    .autocomplete-item-balance.negative {
        background-color: #dbeafe;
        color: #1e40af;
    }
    .autocomplete-item-balance.zero {
        background-color: #f1f5f9;
        color: #64748b;
    }
    .autocomplete-no-results {
        padding: 1rem;
        text-align: center;
        color: #94a3b8;
        font-size: 0.9rem;
    }
    .autocomplete-loading {
        padding: 1rem;
        text-align: center;
        color: #64748b;
        font-size: 0.9rem;
    }
    
    @media (max-width: 768px) {
        .autocomplete-dropdown {
            max-height: 300px;
            font-size: 0.9rem;
        }
        .autocomplete-item {
            padding: 0.65rem 0.85rem;
        }
        .autocomplete-item-name {
            font-size: 0.9rem;
        }
        .autocomplete-item-sub {
            font-size: 0.8rem;
        }
        .autocomplete-item-balance {
            font-size: 0.75rem;
            padding: 0.1rem 0.4rem;
        }
    }
</style>

<!-- Modal تحصيل ديون العميل - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="allCustomersCollectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="collect_debt">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">العميل</div>
                        <div class="fs-5 all-customers-collection-customer-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning all-customers-collection-current-debt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="allCustomersCollectionAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input
                            type="number"
                            class="form-control"
                            id="allCustomersCollectionAmount"
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

<!-- Modal عرض موقع العميل -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="allCustomersViewLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>موقع العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">العميل</div>
                    <div class="fs-5 fw-bold all-customers-location-customer-name">-</div>
                </div>
                <div class="ratio ratio-16x9">
                    <iframe
                        class="all-customers-location-map-frame border rounded"
                        src=""
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
                <a href="#" target="_blank" rel="noopener" class="btn btn-primary all-customers-location-open-map">
                    <i class="bi bi-map"></i> فتح في الخرائط
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Card عرض موقع العميل - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="allCustomersViewLocationCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-geo-alt me-2"></i>موقع العميل
        </h5>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="text-muted small fw-semibold">العميل</div>
            <div class="fs-5 fw-bold all-customers-location-card-customer-name">-</div>
        </div>
        <div class="ratio ratio-16x9">
            <iframe
                class="all-customers-location-card-map-frame border rounded"
                src=""
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
            <a href="#" target="_blank" rel="noopener" class="btn btn-primary all-customers-location-card-open-map">
                <i class="bi bi-map me-1"></i>فتح في الخرائط
            </a>
            <button type="button" class="btn btn-secondary" onclick="closeAllCustomersViewLocationCard()">إغلاق</button>
        </div>
    </div>
</div>

<!-- Card تحصيل ديون العميل - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="allCustomersCollectPaymentCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
            <input type="hidden" name="action" value="collect_debt">
            <input type="hidden" name="customer_id" id="allCustomersCollectionCardCustomerId" value="">
            <div class="mb-3">
                <div class="fw-semibold text-muted">العميل</div>
                <div class="fs-5 all-customers-collection-card-customer-name">-</div>
            </div>
            <div class="mb-3">
                <div class="fw-semibold text-muted">الديون الحالية</div>
                <div class="fs-5 text-warning all-customers-collection-card-current-debt">-</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="allCustomersCollectionCardAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                <input
                    type="number"
                    class="form-control"
                    id="allCustomersCollectionCardAmount"
                    name="amount"
                    step="0.01"
                    min="0.01"
                    required
                >
                <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">تحصيل المبلغ</button>
                <button type="button" class="btn btn-secondary" onclick="closeAllCustomersCollectPaymentCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
// معالج modal التحصيل
document.addEventListener('DOMContentLoaded', function () {
    var collectionModal = document.getElementById('allCustomersCollectPaymentModal');
    if (collectionModal) {
        // إزالة backdrop فوراً عند بدء الإغلاق
        collectionModal.addEventListener('hide.bs.modal', function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.transition = 'none';
                backdrop.style.opacity = '0';
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        collectionModal.addEventListener('hidden.bs.modal', function() {
            // تنظيف backdrop المتبقي (احتياطي)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        var nameElement = collectionModal.querySelector('.all-customers-collection-customer-name');
        var debtElement = collectionModal.querySelector('.all-customers-collection-current-debt');
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
                if (debtAmount > 0) {
                    amountInput.focus();
                }
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

    // معالج أزرار عرض الموقع
    var locationViewButtons = document.querySelectorAll('.all-customers-location-view-btn');
    var viewLocationModal = document.getElementById('allCustomersViewLocationModal');
    var locationMapFrame = viewLocationModal ? viewLocationModal.querySelector('.all-customers-location-map-frame') : null;
    var locationCustomerName = viewLocationModal ? viewLocationModal.querySelector('.all-customers-location-customer-name') : null;
    var locationExternalLink = viewLocationModal ? viewLocationModal.querySelector('.all-customers-location-open-map') : null;

    if (locationViewButtons && locationViewButtons.length > 0) {
        locationViewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var customerName = button.getAttribute('data-customer-name') || '-';
                var latitude = button.getAttribute('data-latitude');
                var longitude = button.getAttribute('data-longitude');
                
                if (!latitude || !longitude) {
                    alert('لا يوجد موقع مسجل لهذا العميل.');
                    return;
                }
                
                closeAllForms();
                
                var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    if (isMobileDevice) {
                    // على الموبايل: استخدام Card
                    var card = document.getElementById('allCustomersViewLocationCard');
                    if (card) {
                        var cardCustomerName = card.querySelector('.all-customers-location-card-customer-name');
                        var cardMapFrame = card.querySelector('.all-customers-location-card-map-frame');
                        var cardExternalLink = card.querySelector('.all-customers-location-card-open-map');
                        
                        if (cardCustomerName) {
                            cardCustomerName.textContent = customerName;
                        }
                        
                        if (cardMapFrame) {
                            var mapUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                            cardMapFrame.src = mapUrl;
                        }
                        
                        if (cardExternalLink) {
                            cardExternalLink.href = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                        }
                        
                        card.style.display = 'block';
                        setTimeout(function() {
                            scrollToElement(card);
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
                            locationMapFrame.src = mapUrl;
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

    // معالج أزرار تحديد الموقع
    var locationCaptureButtons = document.querySelectorAll('.all-customers-location-capture-btn');
    locationCaptureButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name') || '';

            if (!customerId) {
                alert('تعذر تحديد العميل.');
                return;
            }

            if (!navigator.geolocation) {
                alert('المتصفح الحالي لا يدعم تحديد الموقع الجغرافي.');
                return;
            }

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
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData.toString()
                })
                    .then(function (response) {
                        const contentType = response.headers.get('content-type') || '';
                        if (!contentType.includes('application/json')) {
                            return response.text().then(function (text) {
                                console.error('Non-JSON response:', text.substring(0, 500));
                                throw new Error('استجابة غير صالحة من الخادم');
                            });
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        setButtonLoading(button, false);
                        if (data.success) {
                            alert('تم حفظ الموقع بنجاح!');
                            location.reload();
                        } else {
                            alert(data.message || 'حدث خطأ أثناء حفظ الموقع.');
                        }
                    })
                    .catch(function (error) {
                        setButtonLoading(button, false);
                        console.error('Error:', error);
                        if (error.message) {
                            alert('حدث خطأ: ' + error.message);
                        } else {
                            alert('حدث خطأ في الاتصال بالخادم.');
                        }
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
                alert(errorMessage);
            }, {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            });
        });
    });

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

    // معالج أزرار سجل المشتريات
    document.addEventListener('click', function(e) {
        const button = e.target.closest('.js-all-customers-purchase-history');
        if (!button) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name') || '-';
        
        if (!customerId) return;
        
        closeAllForms();
        
        var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
        
        if (isMobileDevice) {
            // على الموبايل: استخدام Card مباشرة
            const card = document.getElementById('repCustomerHistoryCard');
            if (card) {
                const nameElement = card.querySelector('.rep-history-card-customer-name');
                const loadingElement = card.querySelector('.rep-history-card-loading');
                const contentElement = card.querySelector('.rep-history-card-content');
                const errorElement = card.querySelector('.rep-history-card-error');
                const invoicesTableBody = card.querySelector('.rep-history-card-table tbody');
                const returnsContainer = card.querySelector('.rep-history-card-returns');
                const totalInvoicesEl = card.querySelector('.rep-history-card-total-invoices');
                const totalInvoicedEl = card.querySelector('.rep-history-card-total-invoiced');
                const totalReturnsEl = card.querySelector('.rep-history-card-total-returns');
                const netTotalEl = card.querySelector('.rep-history-card-net-total');
                
                if (nameElement) nameElement.textContent = customerName;
                if (loadingElement) loadingElement.classList.remove('d-none');
                if (contentElement) contentElement.classList.add('d-none');
                if (errorElement) errorElement.classList.add('d-none');
                if (invoicesTableBody) invoicesTableBody.innerHTML = '';
                if (returnsContainer) returnsContainer.innerHTML = '';
                
                card.style.display = 'block';
                setTimeout(function() {
                    if (typeof scrollToElement === 'function') {
                        scrollToElement(card);
                    } else {
                        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }, 50);
                
                // جلب بيانات سجل المشتريات من API endpoint
                const basePath = '<?php echo getBasePath(); ?>';
                const historyUrl = basePath + '/api/customer_history_api.php?customer_id=' + encodeURIComponent(customerId);
                
                console.log('Fetching history from:', historyUrl);
                
                // التحقق من وجود fetchJson
                if (typeof fetchJson !== 'function') {
                    console.error('fetchJson is not defined');
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (errorElement) {
                        errorElement.textContent = 'خطأ في تحميل البيانات: الدالة fetchJson غير معرفة';
                        errorElement.classList.remove('d-none');
                    }
                    return;
                }
                
                fetchJson(historyUrl)
                .then(payload => {
                    console.log('History payload received:', payload);
                    
                    if (!payload || !payload.success) {
                        throw new Error(payload?.message || 'فشل تحميل بيانات السجل.');
                    }
                    
                    const history = payload.history || {};
                    const totals = history.totals || {};
                    
                    console.log('History data:', history);
                    console.log('Totals:', totals);
                    
                    // تحديث الإحصائيات
                    if (totalInvoicesEl) {
                        totalInvoicesEl.textContent = Number(totals.invoice_count || 0).toLocaleString('ar-EG');
                    }
                    if (totalInvoicedEl) {
                        if (typeof formatCurrencySimple === 'function') {
                            totalInvoicedEl.textContent = formatCurrencySimple(totals.total_invoiced || 0);
                        } else {
                            totalInvoicedEl.textContent = (totals.total_invoiced || 0).toFixed(2) + ' ج.م';
                        }
                    }
                    if (totalReturnsEl) {
                        if (typeof formatCurrencySimple === 'function') {
                            totalReturnsEl.textContent = formatCurrencySimple(totals.total_returns || 0);
                        } else {
                            totalReturnsEl.textContent = (totals.total_returns || 0).toFixed(2) + ' ج.م';
                        }
                    }
                    if (netTotalEl) {
                        if (typeof formatCurrencySimple === 'function') {
                            netTotalEl.textContent = formatCurrencySimple(totals.net_total || 0);
                        } else {
                            netTotalEl.textContent = (totals.net_total || 0).toFixed(2) + ' ج.م';
                        }
                    }
                    
                    // عرض البيانات - استخدام نفس الدوال مع Card
                    if (invoicesTableBody && typeof renderRepInvoices === 'function') {
                        renderRepInvoices(Array.isArray(history.invoices) ? history.invoices : [], invoicesTableBody);
                    } else {
                        console.error('renderRepInvoices is not defined or invoicesTableBody is null');
                    }
                    if (returnsContainer && typeof renderRepReturns === 'function') {
                        renderRepReturns(Array.isArray(history.returns) ? history.returns : [], returnsContainer);
                    } else {
                        console.error('renderRepReturns is not defined or returnsContainer is null');
                    }
                    
                    // إخفاء loading وإظهار المحتوى
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (contentElement) contentElement.classList.remove('d-none');
                    if (errorElement) errorElement.classList.add('d-none');
                })
                .catch(error => {
                    console.error('Error loading history:', error);
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (errorElement) {
                        errorElement.textContent = error.message || 'حدث خطأ أثناء تحميل سجل المشتريات';
                        errorElement.classList.remove('d-none');
                    }
                });
            }
        } else {
            // على الكمبيوتر: استخدام Modal
            const historyButton = document.querySelector('.rep-history-btn.js-customer-history[data-customer-id="' + customerId + '"]');
            if (!historyButton) {
                // إنشاء زر مؤقت إذا لم يوجد
                const tempButton = document.createElement('button');
                tempButton.className = 'rep-history-btn js-customer-history';
                tempButton.setAttribute('data-customer-id', customerId);
                tempButton.setAttribute('data-customer-name', customerName);
                tempButton.style.display = 'none';
                document.body.appendChild(tempButton);
                tempButton.click();
                document.body.removeChild(tempButton);
            } else {
                historyButton.click();
            }
        }
    });

    // معالج أزرار الإرجاع
    document.addEventListener('click', function(e) {
        const button = e.target.closest('.js-all-customers-return-products');
        if (!button) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name') || '-';
        
        if (!customerId) return;
        
        closeAllForms();
        
        var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
        
        if (isMobileDevice) {
            // على الموبايل: استخدام Card مباشرة
            const card = document.getElementById('repCustomerReturnCard');
            if (card) {
                const nameElement = card.querySelector('.rep-return-card-customer-name');
                const loadingElement = card.querySelector('.rep-return-card-loading');
                const contentElement = card.querySelector('.rep-return-card-content');
                const errorElement = card.querySelector('.rep-return-card-error');
                const tableBody = card.querySelector('.rep-return-card-table tbody');
                const customerPhoneEl = card.querySelector('.rep-return-card-customer-phone');
                const customerAddressEl = card.querySelector('.rep-return-card-customer-address');
                
                if (nameElement) nameElement.textContent = customerName;
                if (loadingElement) loadingElement.classList.remove('d-none');
                if (contentElement) contentElement.classList.add('d-none');
                if (errorElement) errorElement.classList.add('d-none');
                if (tableBody) tableBody.innerHTML = '';
                
                card.style.display = 'block';
                setTimeout(function() {
                    if (typeof scrollToElement === 'function') {
                        scrollToElement(card);
                    } else {
                        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }, 50);
                
                // جلب بيانات سجل المشتريات للإرجاع من API
                const basePath = '<?php echo getBasePath(); ?>';
                const returnUrl = basePath + '/api/customer_purchase_history.php?action=get_history&customer_id=' + encodeURIComponent(customerId);
                
                console.log('Fetching return history from:', returnUrl);
                
                // التحقق من وجود fetchJson
                if (typeof fetchJson !== 'function') {
                    console.error('fetchJson is not defined');
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (errorElement) {
                        errorElement.textContent = 'خطأ في تحميل البيانات: الدالة fetchJson غير معرفة';
                        errorElement.classList.remove('d-none');
                    }
                    return;
                }
                
                fetchJson(returnUrl)
                .then(data => {
                    console.log('Return history data received:', data);
                    
                    if (loadingElement) loadingElement.classList.add('d-none');
                    
                    if (data.success) {
                        // تحديث معلومات العميل
                        if (data.customer) {
                            if (customerPhoneEl) customerPhoneEl.textContent = data.customer.phone || '-';
                            if (customerAddressEl) customerAddressEl.textContent = data.customer.address || '-';
                        }
                        
                        // عرض بيانات المشتريات
                        const purchaseHistory = data.purchase_history || [];
                        console.log('Purchase history:', purchaseHistory);
                        
                        if (tableBody && typeof displayRepReturnHistory === 'function') {
                            displayRepReturnHistory(purchaseHistory, tableBody);
                        } else {
                            console.error('displayRepReturnHistory is not defined or tableBody is null');
                            if (tableBody) {
                                tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">خطأ في عرض البيانات</td></tr>';
                            }
                        }
                        
                        if (contentElement) {
                            contentElement.classList.remove('d-none');
                        }
                    } else {
                        if (errorElement) {
                            errorElement.textContent = data.message || 'حدث خطأ أثناء تحميل بيانات الإرجاع';
                            errorElement.classList.remove('d-none');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading return data:', error);
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (errorElement) {
                        errorElement.textContent = error.message || 'حدث خطأ أثناء تحميل بيانات الإرجاع';
                        errorElement.classList.remove('d-none');
                    }
                });
            }
        } else {
            // على الكمبيوتر: استخدام Modal
            const returnButton = document.querySelector('.rep-return-btn.js-customer-purchase-history[data-customer-id="' + customerId + '"]');
            if (!returnButton) {
                // إنشاء زر مؤقت إذا لم يوجد
                const tempButton = document.createElement('button');
                tempButton.className = 'rep-return-btn js-customer-purchase-history';
                tempButton.setAttribute('data-customer-id', customerId);
                tempButton.setAttribute('data-customer-name', customerName);
                tempButton.style.display = 'none';
                document.body.appendChild(tempButton);
                tempButton.click();
                document.body.removeChild(tempButton);
            } else {
                returnButton.click();
            }
        }
    });
});

// معالج تعديل عميل المندوب
document.addEventListener('DOMContentLoaded', function() {
    var editRepCustomerButtons = document.querySelectorAll('.edit-rep-customer-btn');
    var editRepCustomerModal = document.getElementById('editRepCustomerModal');
    
    if (editRepCustomerModal) {
        // إزالة backdrop بسرعة عند بدء الإغلاق
        editRepCustomerModal.addEventListener('hide.bs.modal', function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.transition = 'none';
                backdrop.style.opacity = '0';
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        editRepCustomerModal.addEventListener('hidden.bs.modal', function() {
            // تنظيف backdrop المتبقي (احتياطي)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
    }
    
    if (editRepCustomerButtons.length > 0) {
        editRepCustomerButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                closeAllForms();
                
                var customerId = this.getAttribute('data-customer-id');
                var customerName = this.getAttribute('data-customer-name');
                var customerPhone = this.getAttribute('data-customer-phone') || '';
                var customerAddress = this.getAttribute('data-customer-address') || '';
                var customerRegionId = this.getAttribute('data-customer-region-id') || '';
                var customerBalance = this.getAttribute('data-customer-balance') || '0';
                
                if (!customerId) {
                    console.error('Customer ID not found');
                    return;
                }
                
                // انتظار قليل لضمان إغلاق النماذج السابقة
                setTimeout(function() {
                    var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    if (isMobileDevice) {
                        // على الموبايل: استخدام Card
                        const card = document.getElementById('editRepCustomerCard');
                        if (card) {
                            var idInput = document.getElementById('editRepCustomerCardId');
                            var nameInput = document.getElementById('editRepCustomerCardName');
                            var addressInput = document.getElementById('editRepCustomerCardAddress');
                            var regionInput = document.getElementById('editRepCustomerCardRegionId');
                            var balanceInput = document.getElementById('editRepCustomerCardBalance');
                            var editPhoneContainer = document.getElementById('editRepPhoneNumbersCardContainer');
                            
                            if (idInput) idInput.value = customerId;
                            if (nameInput) nameInput.value = customerName || '';
                            if (addressInput) addressInput.value = customerAddress;
                            if (regionInput) regionInput.value = customerRegionId;
                            if (balanceInput) balanceInput.value = customerBalance;
                            
                            // تحميل أرقام الهواتف
                            if (editPhoneContainer) {
                                editPhoneContainer.innerHTML = '';
                                fetch('?action=get_customer_phones&customer_id=' + customerId)
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success && data.phones && data.phones.length > 0) {
                                            data.phones.forEach(function(phone) {
                                                var phoneGroup = document.createElement('div');
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
                                            var phoneGroup = document.createElement('div');
                                            phoneGroup.className = 'input-group mb-2';
                                            phoneGroup.innerHTML = `
                                                <input type="text" class="form-control phone-input" name="phones[]" value="${customerPhone}" placeholder="مثال: 01234567890">
                                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            `;
                                            editPhoneContainer.appendChild(phoneGroup);
                                        } else {
                                            var phoneGroup = document.createElement('div');
                                            phoneGroup.className = 'input-group mb-2';
                                            phoneGroup.innerHTML = `
                                                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            `;
                                            editPhoneContainer.appendChild(phoneGroup);
                                        }
                                        if (typeof updateEditRepCardRemoveButtons === 'function') {
                                            updateEditRepCardRemoveButtons();
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error loading phones:', error);
                                        if (customerPhone) {
                                            var phoneGroup = document.createElement('div');
                                            phoneGroup.className = 'input-group mb-2';
                                            phoneGroup.innerHTML = `
                                                <input type="text" class="form-control phone-input" name="phones[]" value="${customerPhone}" placeholder="مثال: 01234567890">
                                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            `;
                                            editPhoneContainer.appendChild(phoneGroup);
                                        }
                                        if (typeof updateEditRepCardRemoveButtons === 'function') {
                                            updateEditRepCardRemoveButtons();
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
                        if (editRepCustomerModal) {
                            var idInput = document.getElementById('editRepCustomerId');
                            var nameInput = document.getElementById('editRepCustomerName');
                            var phoneInput = document.getElementById('editRepCustomerPhone');
                            var addressInput = document.getElementById('editRepCustomerAddress');
                            var regionInput = document.getElementById('editRepCustomerRegionId');
                            var balanceInput = document.getElementById('editRepCustomerBalance');
                            var editPhoneContainer = document.getElementById('editRepPhoneNumbersContainer');
                            
                            if (idInput) idInput.value = customerId;
                            if (nameInput) nameInput.value = customerName || '';
                            if (addressInput) addressInput.value = customerAddress;
                            if (regionInput) regionInput.value = customerRegionId;
                            if (balanceInput) balanceInput.value = customerBalance;
                            
                            // تحميل أرقام الهواتف المتعددة
                            if (editPhoneContainer) {
                                editPhoneContainer.innerHTML = '';
                                fetch('?action=get_customer_phones&customer_id=' + customerId)
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success && data.phones && data.phones.length > 0) {
                                            data.phones.forEach(function(phone, index) {
                                                var phoneGroup = document.createElement('div');
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
                                            var phoneGroup = document.createElement('div');
                                            phoneGroup.className = 'input-group mb-2';
                                            phoneGroup.innerHTML = `
                                                <input type="text" class="form-control phone-input" name="phones[]" value="${customerPhone}" placeholder="مثال: 01234567890">
                                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            `;
                                            editPhoneContainer.appendChild(phoneGroup);
                                        } else {
                                            var phoneGroup = document.createElement('div');
                                            phoneGroup.className = 'input-group mb-2';
                                            phoneGroup.innerHTML = `
                                                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            `;
                                            editPhoneContainer.appendChild(phoneGroup);
                                        }
                                        if (typeof updateEditRepRemoveButtons === 'function') {
                                            updateEditRepRemoveButtons();
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error loading phones:', error);
                                        if (customerPhone) {
                                            var phoneGroup = document.createElement('div');
                                            phoneGroup.className = 'input-group mb-2';
                                            phoneGroup.innerHTML = `
                                                <input type="text" class="form-control phone-input" name="phones[]" value="${customerPhone}" placeholder="مثال: 01234567890">
                                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            `;
                                            editPhoneContainer.appendChild(phoneGroup);
                                        }
                                        if (typeof updateEditRepRemoveButtons === 'function') {
                                            updateEditRepRemoveButtons();
                                        }
                                    });
                            } else if (phoneInput) {
                                phoneInput.value = customerPhone;
                            }
                            
                            try {
                                var modal = bootstrap.Modal.getOrCreateInstance(editRepCustomerModal);
                                modal.show();
                            } catch (err) {
                                console.error('Error showing modal:', err);
                                var modal = new bootstrap.Modal(editRepCustomerModal);
                                modal.show();
                            }
                        }
                    }
                }, 100);
            });
        });
    } else {
        if (!editRepCustomerModal) {
            console.warn('Edit rep customer modal not found');
        }
        if (editRepCustomerButtons.length === 0) {
            console.warn('Edit rep customer buttons not found');
        }
    }
    
    // معالج إضافة رقم هاتف في نموذج التعديل
    const addEditRepPhoneBtn = document.getElementById('addEditRepPhoneBtn');
    const editRepPhoneContainer = document.getElementById('editRepPhoneNumbersContainer');
    
    if (addEditRepPhoneBtn && editRepPhoneContainer) {
        addEditRepPhoneBtn.addEventListener('click', function() {
            var phoneInputGroup = document.createElement('div');
            phoneInputGroup.className = 'input-group mb-2';
            phoneInputGroup.innerHTML = `
                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                <button type="button" class="btn btn-outline-danger remove-phone-btn">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            editRepPhoneContainer.appendChild(phoneInputGroup);
            updateEditRepRemoveButtons();
        });
        
        editRepPhoneContainer.addEventListener('click', function(e) {
            if (e.target.closest('.remove-phone-btn')) {
                e.target.closest('.input-group').remove();
                updateEditRepRemoveButtons();
            }
        });
    }
    
    // تحديث حالة أزرار الحذف للنموذج التعديل
    function updateEditRepRemoveButtons() {
        if (editRepPhoneContainer) {
            const phoneGroups = editRepPhoneContainer.querySelectorAll('.input-group');
            phoneGroups.forEach(function(group, index) {
                const removeBtn = group.querySelector('.remove-phone-btn');
                if (removeBtn) {
                    removeBtn.style.display = phoneGroups.length > 1 ? 'block' : 'none';
                }
            });
        }
    }
    window.updateEditRepRemoveButtons = updateEditRepRemoveButtons;
    
    // معالج إضافة رقم هاتف في Card التعديل (للموبايل)
    const addEditRepPhoneCardBtn = document.getElementById('addEditRepPhoneCardBtn');
    const editRepPhoneCardContainer = document.getElementById('editRepPhoneNumbersCardContainer');
    
    if (addEditRepPhoneCardBtn && editRepPhoneCardContainer) {
        addEditRepPhoneCardBtn.addEventListener('click', function() {
            var phoneInputGroup = document.createElement('div');
            phoneInputGroup.className = 'input-group mb-2';
            phoneInputGroup.innerHTML = `
                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                <button type="button" class="btn btn-outline-danger remove-phone-btn">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            editRepPhoneCardContainer.appendChild(phoneInputGroup);
            updateEditRepCardRemoveButtons();
        });
        
        editRepPhoneCardContainer.addEventListener('click', function(e) {
            if (e.target.closest('.remove-phone-btn')) {
                e.target.closest('.input-group').remove();
                updateEditRepCardRemoveButtons();
            }
        });
    }
    
    // تحديث حالة أزرار الحذف لـ Card التعديل
    function updateEditRepCardRemoveButtons() {
        if (editRepPhoneCardContainer) {
            const phoneGroups = editRepPhoneCardContainer.querySelectorAll('.input-group');
            phoneGroups.forEach(function(group, index) {
                const removeBtn = group.querySelector('.remove-phone-btn');
                if (removeBtn) {
                    removeBtn.style.display = phoneGroups.length > 1 ? 'block' : 'none';
                }
            });
        }
    }
    window.updateEditRepCardRemoveButtons = updateEditRepCardRemoveButtons;
});
</script>

<!-- Modal تعديل عميل المندوب - للكمبيوتر فقط -->
<?php if (in_array($currentRole, ['manager', 'accountant', 'sales'], true)): ?>
<div class="modal fade d-none d-md-block" id="editRepCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل بيانات عميل المندوب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="edit_customer">
                <input type="hidden" name="customer_id" id="editRepCustomerId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل</label>
                        <input type="text" class="form-control" id="editRepCustomerName" disabled>
                        <small class="text-muted">لا يمكن تعديل اسم العميل</small>
                    </div>
                    <?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
                    <div class="mb-3">
                        <label class="form-label">ديون العميل / رصيد العميل</label>
                        <input type="number" class="form-control" name="balance" id="editRepCustomerBalance" step="0.01" placeholder="مثال: 0 أو -500">
                        <small class="text-muted">
                            <strong>إدخال قيمة سالبة:</strong> يتم اعتبارها رصيد دائن للعميل (مبلغ متاح للعميل).
                        </small>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">أرقام الهاتف</label>
                        <div id="editRepPhoneNumbersContainer">
                            <div class="input-group mb-2">
                                <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                                <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addEditRepPhoneBtn">
                            <i class="bi bi-plus-circle"></i> إضافة رقم آخر
                        </button>
                        <input type="hidden" name="phone" id="editRepCustomerPhone" value="">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea class="form-control" name="address" id="editRepCustomerAddress" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المنطقة</label>
                        <div class="input-group">
                            <select class="form-select" name="region_id" id="editRepCustomerRegionId">
                                <option value="">اختر المنطقة</option>
                                <?php
                                $regions = $db->query("SELECT id, name FROM regions ORDER BY name ASC");
                                foreach ($regions as $region):
                                ?>
                                    <option value="<?php echo $region['id']; ?>"><?php echo htmlspecialchars($region['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
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
<?php endif; ?>

<!-- Card تعديل عميل المندوب - للموبايل فقط -->
<?php if (in_array($currentRole, ['manager', 'accountant', 'sales'], true)): ?>
<div class="card shadow-sm mb-4 d-md-none" id="editRepCustomerCard" style="display: none;">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="bi bi-pencil-square me-2"></i>تعديل بيانات عميل المندوب
        </h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeEditRepCustomerCard()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" id="editRepCustomerCardForm">
            <input type="hidden" name="action" value="edit_customer">
            <input type="hidden" name="customer_id" id="editRepCustomerCardId">
            <div class="mb-3">
                <label class="form-label">اسم العميل</label>
                <input type="text" class="form-control" id="editRepCustomerCardName" disabled>
                <small class="text-muted">لا يمكن تعديل اسم العميل</small>
            </div>
            <?php if (in_array($currentRole, ['manager', 'developer'], true)): ?>
            <div class="mb-3">
                <label class="form-label">ديون العميل / رصيد العميل</label>
                <input type="number" class="form-control" name="balance" id="editRepCustomerCardBalance" step="0.01" placeholder="مثال: 0 أو -500">
                <small class="text-muted">
                    <strong>إدخال قيمة سالبة:</strong> يتم اعتبارها رصيد دائن للعميل (مبلغ متاح للعميل).
                </small>
            </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label">أرقام الهاتف</label>
                <div id="editRepPhoneNumbersCardContainer">
                    <div class="input-group mb-2">
                        <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                        <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addEditRepPhoneCardBtn">
                    <i class="bi bi-plus-circle"></i> إضافة رقم آخر
                </button>
                <input type="hidden" name="phone" id="editRepCustomerCardPhone" value="">
            </div>
            <div class="mb-3">
                <label class="form-label">العنوان</label>
                <textarea class="form-control" name="address" id="editRepCustomerCardAddress" rows="2"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">المنطقة</label>
                <div class="input-group">
                    <select class="form-select" name="region_id" id="editRepCustomerCardRegionId">
                        <option value="">اختر المنطقة</option>
                        <?php
                        $regions = $db->query("SELECT id, name FROM regions ORDER BY name ASC");
                        foreach ($regions as $region):
                        ?>
                            <option value="<?php echo $region['id']; ?>"><?php echo htmlspecialchars($region['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="button" class="btn btn-secondary flex-fill" onclick="closeEditRepCustomerCard()">إلغاء</button>
                <button type="submit" class="btn btn-primary flex-fill">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal تصدير العملاء المحددين - للكمبيوتر فقط -->
<?php
// جلب قائمة المندوبين
$salesRepsList = [];
try {
    $salesRepsList = $db->query(
        "SELECT id, full_name, username FROM users WHERE role = 'sales' AND status = 'active' ORDER BY full_name ASC"
    );
} catch (Exception $e) {
    error_log('Error fetching sales reps: ' . $e->getMessage());
    $salesRepsList = [];
}
?>
<div class="modal fade d-none d-md-block" id="customerExportModal" tabindex="-1" aria-hidden="true" data-section="delegates">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="modal-title mb-0">
                    <i class="bi bi-download me-2"></i>تصدير عملاء محددين إلى Excel
                </h5>
                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-light btn-sm" id="generateExcelBtnHeader" disabled>
                        <i class="bi bi-file-earmark-excel me-1"></i>توليد ملف Excel
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
            </div>
            <div class="modal-body">
                <div class="customer-export-alerts mb-3"></div>
                
                <!-- اختيار المندوب -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">اختر المندوب:</label>
                    <select class="form-select" id="exportRepSelect" required>
                        <option value="">-- اختر المندوب --</option>
                        <?php foreach ($salesRepsList as $rep): ?>
                            <option value="<?php echo (int)$rep['id']; ?>">
                                <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
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
                            <button type="button" class="btn btn-outline-success" id="selectAllCustomersAndGenerate">
                                <i class="bi bi-check-all me-1"></i>كل العملاء
                            </button>
                            <button type="button" class="btn btn-outline-danger" id="printDebtorCustomersBtn" onclick="window.open('<?php echo getRelativeUrl('print_debtor_customers.php'); ?>', '_blank');">
                                <i class="bi bi-people me-1"></i>العملاء المدينين
                            </button>
                        </div>
                    </div>
                    <div id="exportCustomersList" class="table-responsive">
                        <!-- سيتم ملؤه عبر JavaScript -->
                    </div>
                </div>
                
                <!-- رسالة اختيار المندوب -->
                <div id="selectRepMessage" class="text-center text-muted py-4">
                    <i class="bi bi-info-circle me-2"></i>يرجى اختيار المندوب أولاً لعرض عملائه
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
        </div>
    </div>
</div>

<!-- Card تصدير عملاء محددين - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="customerExportCard" style="display: none;">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="bi bi-download me-2"></i>تصدير عملاء محددين إلى Excel
        </h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeCustomerExportCard()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="card-body">
        <div class="customer-export-card-alerts mb-3"></div>
        
        <!-- اختيار المندوب -->
        <div class="mb-4">
            <label class="form-label fw-semibold">اختر المندوب:</label>
            <select class="form-select" id="exportRepSelectCard" required>
                <option value="">-- اختر المندوب --</option>
                <?php foreach ($salesRepsList as $rep): ?>
                    <option value="<?php echo (int)$rep['id']; ?>">
                        <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username'] ?? ''); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- قائمة العملاء -->
        <div class="mb-3" id="customersCardSection" style="display: none;">
            <div class="d-flex flex-column justify-content-between align-items-center mb-2 gap-2">
                <h6 class="mb-0">حدد العملاء المراد تصديرهم:</h6>
                <div class="d-flex gap-2 flex-wrap w-100">
                    <div class="btn-group btn-group-sm flex-fill">
                        <button type="button" class="btn btn-outline-primary" id="selectAllCustomersCard">
                            <i class="bi bi-check-square me-1"></i>تحديد الكل
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="deselectAllCustomersCard">
                            <i class="bi bi-square me-1"></i>إلغاء التحديد
                        </button>
                        <button type="button" class="btn btn-outline-success" id="selectAllCustomersAndGenerateCard">
                            <i class="bi bi-check-all me-1"></i>كل العملاء
                        </button>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="printDebtorCustomersCardBtn" onclick="printDebtorCustomers()">
                        <i class="bi bi-people me-1"></i>العملاء المدينين
                    </button>
                </div>
            </div>
            <div id="exportCustomersCardList" class="table-responsive">
                <!-- سيتم ملؤه عبر JavaScript -->
            </div>
        </div>
        
        <!-- رسالة اختيار المندوب -->
        <div id="selectRepCardMessage" class="text-center text-muted py-4">
            <i class="bi bi-info-circle me-2"></i>يرجى اختيار المندوب أولاً لعرض عملائه
        </div>
        
        <!-- أزرار الإجراءات بعد التوليد -->
        <div id="exportCardActionButtons" style="display: none;" class="mt-3 p-3 bg-light rounded">
            <h6 class="mb-3">تم توليد ملف Excel بنجاح</h6>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-primary btn-sm" id="printExcelCardBtn">
                    <i class="bi bi-printer me-2"></i>طباعة
                </button>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-secondary flex-fill" onclick="closeCustomerExportCard()">إغلاق</button>
            <button type="button" class="btn btn-primary flex-fill" id="generateExcelCardBtn" disabled>
                <i class="bi bi-file-earmark-excel me-1"></i>توليد ملف Excel
            </button>
        </div>
    </div>
</div>

<style>
/* تحسينات عامة للأداء - إزالة animations على الهواتف */
@media (max-width: 768px) {
    /* إزالة animations كلياً على الهواتف */
    .modal.fade .modal-dialog,
    .modal.show .modal-dialog,
    .modal.showing .modal-dialog {
        transition: none !important;
        transform: translate3d(0, 0, 0) !important;
        opacity: 1 !important;
    }
    
    .modal:not(.show) .modal-dialog {
        transition: none !important;
        transform: translate3d(0, 0, 0) !important;
        opacity: 0 !important;
    }
    
    /* إزالة backdrop transition على الهواتف */
    .modal-backdrop,
    .modal-backdrop.fade,
    .modal-backdrop.show {
        transition: none !important;
    }
    
    /* إصلاح الجزء الأبيض الفارغ في modals صغيرة على الهواتف */
    .modal-dialog:not(.modal-xl):not(.modal-lg) .modal-content {
        height: auto !important;
        max-height: calc(100vh - 2rem) !important;
    }
    
    .modal-dialog:not(.modal-xl):not(.modal-lg) .modal-body {
        flex-shrink: 0;
        max-height: calc(100vh - 12rem) !important;
        overflow-y: auto;
        padding-bottom: 0.5rem;
    }
    
    .modal-dialog:not(.modal-xl):not(.modal-lg) .modal-footer {
        flex-shrink: 0;
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
        margin-top: 0;
    }
}

/* تحسينات عامة للأداء - تقليل animations على الشاشات الكبيرة */
.modal.fade .modal-dialog {
    transition: transform 0.1s ease-out, opacity 0.1s ease-out !important;
}

.modal-backdrop.fade {
    transition: opacity 0.05s linear !important;
}

.modal-backdrop.show {
    opacity: 0.5 !important;
}

/* إزالة backdrop بسرعة */
.modal-backdrop {
    will-change: opacity;
    transition: opacity 0.05s linear !important;
}

/* منع overflow غير ضروري */
.modal-dialog {
    max-height: calc(100vh - 2rem) !important;
    margin: 1rem auto !important;
}

.modal-content {
    max-height: calc(100vh - 2rem) !important;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.modal-body {
    overflow-y: auto;
    flex: 1 1 auto;
    min-height: 0;
    padding-bottom: 1rem;
}

.modal-footer {
    flex-shrink: 0;
    margin-top: auto;
    padding-top: 1rem;
    padding-bottom: 1rem;
}

/* إصلاح الجزء الأبيض الفارغ في modals صغيرة */
.modal-dialog-centered .modal-content {
    max-height: none !important;
    height: auto !important;
}

.modal-dialog:not(.modal-xl):not(.modal-lg) .modal-body {
    padding-bottom: 0.5rem;
    flex-shrink: 0;
}

.modal-dialog:not(.modal-xl):not(.modal-lg) .modal-footer {
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
    margin-top: 0;
    flex-shrink: 0;
}

/* إزالة أي مسافات زائدة */
.modal-dialog:not(.modal-xl):not(.modal-lg) .modal-content {
    height: auto !important;
    max-height: none !important;
}

/* منع overflow غير ضروري في modals صغيرة */
@media (max-width: 768px) {
    .modal-dialog:not(.modal-xl):not(.modal-lg) .modal-content {
        max-height: calc(100vh - 2rem) !important;
    }
    
    .modal-dialog:not(.modal-xl):not(.modal-lg) .modal-body {
        max-height: calc(100vh - 10rem) !important;
        overflow-y: auto;
    }
}

/* إصلاح مشكلة Taskbar يغطي زر توليد Excel - تحسينات للمودال */
#customerExportModal .modal-dialog {
    margin: 0.5rem;
    max-height: calc(100vh - 1rem);
    display: flex;
    flex-direction: column;
}

#customerExportModal .modal-content {
    max-height: calc(100vh - 1rem);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

#customerExportModal .modal-body {
    overflow-y: auto;
    flex: 1 1 auto;
    min-height: 0;
    padding-bottom: 1rem;
}

#customerExportModal .modal-footer {
    flex-shrink: 0;
    margin-top: auto;
    padding-top: 1rem;
    padding-bottom: 1rem;
    border-top: 1px solid #dee2e6;
    background-color: #fff;
    position: sticky;
    bottom: 0;
    z-index: 10;
}

/* إصلاح مشكلة التولبار يغطي الأزرار على الهواتف */
@media (max-width: 768px) {
    #customerExportModal .modal-dialog {
        margin: 0.25rem;
        margin-bottom: 100px !important; /* مسافة كبيرة من الأسفل لتجنب التولبار */
        max-height: calc(100vh - 100px) !important;
        max-width: calc(100% - 0.5rem);
    }
    
    #customerExportModal .modal-content {
        max-height: calc(100vh - 100px) !important;
        margin-bottom: 0;
    }
    
    #customerExportModal .modal-body {
        padding-bottom: 1rem;
        max-height: calc(100vh - 250px) !important; /* مساحة للأزرار والتولبار */
    }
    
    #customerExportModal .modal-footer {
        padding-top: 1rem;
        padding-bottom: 2rem !important; /* مسافة كبيرة من الأسفل */
        margin-bottom: 0;
        position: sticky;
        bottom: 0;
        z-index: 15;
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
    }
    
    /* إضافة مسافة إضافية من الأسفل للمحتوى */
    #customerExportModal .modal-body::after {
        content: '';
        display: block;
        height: 80px;
        flex-shrink: 0;
    }
}

/* إصلاح الجزء الأبيض الفارغ */
#customerExportModal .modal-content::after {
    display: none;
}

/* إزالة animations على الهواتف - إغلاق فوري */
@media (max-width: 768px) {
    #customerExportModal.modal.fade .modal-dialog,
    #customerExportModal.modal.show .modal-dialog,
    #customerExportModal.modal.showing .modal-dialog {
        transition: none !important;
        transform: none !important;
        opacity: 1 !important;
    }
    
    #customerExportModal.modal:not(.show) .modal-dialog {
        transition: none !important;
        transform: none !important;
        opacity: 0 !important;
    }
    
    /* إزالة backdrop transition تماماً */
    #customerExportModal + .modal-backdrop,
    .modal-backdrop {
        transition: none !important;
    }
}

/* تحسينات responsive للمودال */
@media (max-width: 768px) {
    #customerExportModal .modal-dialog {
        margin: 0.25rem;
        margin-bottom: 100px !important; /* مسافة كبيرة من الأسفل */
        max-height: calc(100vh - 100px) !important;
        max-width: calc(100% - 0.5rem);
    }
    
    #customerExportModal .modal-content {
        max-height: calc(100vh - 100px) !important;
    }
    
    #customerExportModal .modal-body {
        padding-bottom: 1rem;
        max-height: calc(100vh - 250px) !important; /* مساحة للأزرار والتولبار */
    }
    
    #customerExportModal .modal-footer {
        padding-top: 1rem;
        padding-bottom: 2rem !important; /* مسافة كبيرة من الأسفل */
        position: sticky;
        bottom: 0;
        z-index: 15;
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
        background-color: #fff;
    }
    
    /* إضافة مسافة إضافية من الأسفل للمحتوى */
    #customerExportModal .modal-body::after {
        content: '';
        display: block;
        height: 80px;
        flex-shrink: 0;
    }
    
    #customerExportModal .table-responsive {
        font-size: 0.875rem;
    }
    
    #customerExportModal .btn-group {
        width: 100%;
    }
    
    #customerExportModal .btn-group .btn {
        flex: 1;
    }
    
    #customerExportModal .modal-footer .btn {
        font-size: 0.875rem;
        min-height: 44px; /* حجم مناسب للمس */
    }
    
    #customerExportModal table {
        font-size: 0.8rem;
    }
    
    #customerExportModal .table th,
    #customerExportModal .table td {
        padding: 0.5rem 0.25rem;
    }
    
    /* تحسين مظهر الأزرار في الـ header على الشاشات الصغيرة */
    #customerExportModal .modal-header {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    #customerExportModal .modal-header .d-flex {
        flex-wrap: wrap;
        gap: 0.25rem;
    }
    
    #customerExportModal .modal-header .btn {
        font-size: 0.75rem;
        padding: 0.375rem 0.5rem;
        min-height: 36px;
    }
    
    #customerExportModal .modal-header .btn-close {
        margin-right: 0;
    }
}

/* للشاشات الكبيرة أيضاً */
@media (min-width: 769px) {
    #customerExportModal .modal-dialog {
        margin: 1rem auto;
        max-height: calc(100vh - 2rem);
    }
    
    #customerExportModal .modal-content {
        max-height: calc(100vh - 2rem);
    }
    
    #customerExportModal .modal-footer {
        padding-bottom: 1rem;
    }
    }
    
    /* Autocomplete Dropdown للبحث الفوري */
    .autocomplete-dropdown {
        position: absolute;
        top: calc(100% + 2px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-top: none;
        border-radius: 0 0 10px 10px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        max-height: 400px;
        overflow-y: auto;
        z-index: 1050 !important;
        margin-top: 0;
        display: none;
        width: 100%;
        visibility: hidden;
        opacity: 0;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .autocomplete-dropdown.show {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    .autocomplete-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    .autocomplete-item:last-child {
        border-bottom: none;
    }
    .autocomplete-item:hover,
    .autocomplete-item.selected {
        background-color: #f8fafc;
    }
    .autocomplete-item-name {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.95rem;
    }
    .autocomplete-item-sub {
        font-size: 0.85rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .autocomplete-item-balance {
        margin-right: auto;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .autocomplete-item-balance.positive {
        background-color: #fef3c7;
        color: #92400e;
    }
    .autocomplete-item-balance.negative {
        background-color: #dbeafe;
        color: #1e40af;
    }
    .autocomplete-item-balance.zero {
        background-color: #f1f5f9;
        color: #64748b;
    }
    .autocomplete-no-results {
        padding: 1rem;
        text-align: center;
        color: #94a3b8;
        font-size: 0.9rem;
    }
    .autocomplete-loading {
        padding: 1rem;
        text-align: center;
        color: #64748b;
        font-size: 0.9rem;
    }
    
    @media (max-width: 768px) {
        .autocomplete-dropdown {
            max-height: 300px;
            font-size: 0.9rem;
        }
        .autocomplete-item {
            padding: 0.65rem 0.85rem;
        }
        .autocomplete-item-name {
            font-size: 0.9rem;
        }
        .autocomplete-item-sub {
            font-size: 0.8rem;
        }
        .autocomplete-item-balance {
            font-size: 0.75rem;
            padding: 0.1rem 0.4rem;
        }
    }
</style>

<!-- Modal تحديد الحد الائتماني - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="setCreditLimitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-credit-card me-2"></i>تحديد الحد الائتماني</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" id="setCreditLimitForm">
                <input type="hidden" name="action" value="set_credit_limit">
                <input type="hidden" name="customer_id" id="creditLimitCustomerId" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">العميل</div>
                        <div class="fs-5 credit-limit-customer-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الرصيد المدين الحالي</div>
                        <div class="fs-5 text-warning credit-limit-current-balance">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="creditLimitAmount">الحد الائتماني <span class="text-danger">*</span></label>
                        <input
                            type="number"
                            class="form-control"
                            id="creditLimitAmount"
                            name="credit_limit"
                            step="0.01"
                            min="0"
                            required
                            placeholder="0.00"
                        >
                        <div class="form-text">
                            <strong>ملاحظة:</strong> إذا كان رصيد العميل المدين أكبر من أو يساوي الحد الائتماني، لن يتمكن المندوب من البيع بالأجل أو بتحصيل جزئي لهذا العميل.
                            <br>ضع <strong>0</strong> لإلغاء الحد الائتماني (السماح بالبيع بالأجل بدون قيود).
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card تحديد الحد الائتماني - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="setCreditLimitCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-credit-card me-2"></i>تحديد الحد الائتماني
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" id="setCreditLimitCardForm">
            <input type="hidden" name="action" value="set_credit_limit">
            <input type="hidden" name="customer_id" id="creditLimitCardCustomerId" value="">
            <div class="mb-3">
                <div class="fw-semibold text-muted">العميل</div>
                <div class="fs-5 credit-limit-card-customer-name">-</div>
            </div>
            <div class="mb-3">
                <div class="fw-semibold text-muted">الرصيد المدين الحالي</div>
                <div class="fs-5 text-warning credit-limit-card-current-balance">-</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="creditLimitCardAmount">الحد الائتماني <span class="text-danger">*</span></label>
                <input
                    type="number"
                    class="form-control"
                    id="creditLimitCardAmount"
                    name="credit_limit"
                    step="0.01"
                    min="0"
                    required
                    placeholder="0.00"
                >
                <div class="form-text">
                    <strong>ملاحظة:</strong> إذا كان رصيد العميل المدين أكبر من أو يساوي الحد الائتماني، لن يتمكن المندوب من البيع بالأجل أو بتحصيل جزئي لهذا العميل.
                    <br>ضع <strong>0</strong> لإلغاء الحد الائتماني (السماح بالبيع بالأجل بدون قيود).
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>حفظ
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeSetCreditLimitCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Customer Export Script -->
<script>
// تمرير المسارات الأساسية من PHP إلى JavaScript
window.CUSTOMER_EXPORT_CONFIG = {
    basePath: '<?php echo getBasePath(); ?>',
    apiBasePath: '<?php echo getRelativeUrl("api"); ?>'
};

// دالة لطباعة العملاء المدينين
function printDebtorCustomers() {
    const basePath = window.CUSTOMER_EXPORT_CONFIG?.basePath || '';
    const printUrl = (basePath ? basePath + '/' : '/') + 'print_debtor_customers.php';
    window.open(printUrl, '_blank');
}
</script>
<script src="<?php echo ASSETS_URL; ?>js/customer_export.js?v=<?php echo time(); ?>"></script>

<script>
// معالج Modal تصدير العملاء - إغلاق فوري
document.addEventListener('DOMContentLoaded', function() {
    const customerExportModal = document.getElementById('customerExportModal');
    if (customerExportModal) {
        // إزالة backdrop فوراً عند بدء الإغلاق - بدون أي delays
        customerExportModal.addEventListener('hide.bs.modal', function(e) {
            // إزالة backdrop فوراً بدون انتظار
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.transition = 'none';
                backdrop.style.opacity = '0';
                backdrop.style.display = 'none';
                backdrop.remove();
            });
            
            // تنظيف body classes فوراً
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            document.body.style.position = '';
            
            // إزالة أي styles متبقية
            if (document.body.hasAttribute('style') && !document.body.getAttribute('style')) {
                document.body.removeAttribute('style');
            }
        });
        
        customerExportModal.addEventListener('hidden.bs.modal', function() {
            // تنظيف backdrop المتبقي (احتياطي)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.remove();
            });
            
            // تنظيف body classes
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            document.body.style.position = '';
            
            // إزالة أي styles متبقية
            if (document.body.hasAttribute('style') && !document.body.getAttribute('style')) {
                document.body.removeAttribute('style');
            }
        });
    }
});

// معالج Modal تحديد الحد الائتماني
document.addEventListener('DOMContentLoaded', function() {
    var creditLimitModal = document.getElementById('setCreditLimitModal');
    if (creditLimitModal) {
        // إزالة backdrop فوراً عند بدء الإغلاق
        creditLimitModal.addEventListener('hide.bs.modal', function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.transition = 'none';
                backdrop.style.opacity = '0';
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        creditLimitModal.addEventListener('hidden.bs.modal', function() {
            // تنظيف backdrop المتبقي (احتياطي)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        var nameElement = creditLimitModal.querySelector('.credit-limit-customer-name');
        var balanceElement = creditLimitModal.querySelector('.credit-limit-current-balance');
        var customerIdInput = creditLimitModal.querySelector('#creditLimitCustomerId');
        var creditLimitInput = creditLimitModal.querySelector('#creditLimitAmount');
        var creditLimitForm = creditLimitModal.querySelector('#setCreditLimitForm');

        if (nameElement && balanceElement && customerIdInput && creditLimitInput) {
            creditLimitModal.addEventListener('show.bs.modal', function (event) {
                var triggerButton = event.relatedTarget;
                if (!triggerButton) {
                    return;
                }

                var customerName = triggerButton.getAttribute('data-customer-name') || '-';
                var balanceRaw = triggerButton.getAttribute('data-customer-balance') || '0';
                var creditLimitRaw = triggerButton.getAttribute('data-credit-limit') || '0';
                
                var numericBalance = parseFloat(balanceRaw);
                if (!Number.isFinite(numericBalance)) {
                    numericBalance = 0;
                }
                var displayBalance = numericBalance > 0 ? numericBalance : 0;
                
                var numericCreditLimit = parseFloat(creditLimitRaw);
                if (!Number.isFinite(numericCreditLimit)) {
                    numericCreditLimit = 0;
                }

                nameElement.textContent = customerName;
                balanceElement.textContent = formatCurrency(displayBalance);
                customerIdInput.value = triggerButton.getAttribute('data-customer-id') || '';
                creditLimitInput.value = numericCreditLimit.toFixed(2);
            });

            creditLimitModal.addEventListener('hidden.bs.modal', function () {
                nameElement.textContent = '-';
                balanceElement.textContent = '-';
                customerIdInput.value = '';
                creditLimitInput.value = '';
            });
        }
    }
    
    // معالج أزرار تحديد الحد الائتماني - دعم Modal/Card
    var setCreditLimitButtons = document.querySelectorAll('.set-credit-limit-btn');
    if (setCreditLimitButtons && setCreditLimitButtons.length > 0) {
        setCreditLimitButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                closeAllForms();
                
                var customerId = button.getAttribute('data-customer-id') || '';
                var customerName = button.getAttribute('data-customer-name') || '-';
                var balanceRaw = button.getAttribute('data-customer-balance') || '0';
                var creditLimitRaw = button.getAttribute('data-credit-limit') || '0';
                
                var numericBalance = parseFloat(balanceRaw);
                if (!Number.isFinite(numericBalance)) {
                    numericBalance = 0;
                }
                var displayBalance = numericBalance > 0 ? numericBalance : 0;
                
                var numericCreditLimit = parseFloat(creditLimitRaw);
                if (!Number.isFinite(numericCreditLimit)) {
                    numericCreditLimit = 0;
                }
                
                var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
                
                if (isMobileDevice) {
                    // على الموبايل: استخدام Card
                    var card = document.getElementById('setCreditLimitCard');
                    if (card) {
                        var cardCustomerName = card.querySelector('.credit-limit-card-customer-name');
                        var cardBalance = card.querySelector('.credit-limit-card-current-balance');
                        var cardCustomerId = card.querySelector('#creditLimitCardCustomerId');
                        var cardCreditLimit = card.querySelector('#creditLimitCardAmount');
                        
                        if (cardCustomerName) cardCustomerName.textContent = customerName;
                        if (cardBalance) cardBalance.textContent = formatCurrency(displayBalance);
                        if (cardCustomerId) cardCustomerId.value = customerId;
                        if (cardCreditLimit) cardCreditLimit.value = numericCreditLimit.toFixed(2);
                        
                        card.style.display = 'block';
                        setTimeout(function() {
                            if (typeof scrollToElement === 'function') {
                                scrollToElement(card);
                            } else {
                                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        }, 50);
                    }
                } else {
                    // على الكمبيوتر: استخدام Modal
                    if (creditLimitModal) {
                        var modalNameElement = creditLimitModal.querySelector('.credit-limit-customer-name');
                        var modalBalanceElement = creditLimitModal.querySelector('.credit-limit-current-balance');
                        var modalCustomerIdInput = creditLimitModal.querySelector('#creditLimitCustomerId');
                        var modalCreditLimitInput = creditLimitModal.querySelector('#creditLimitAmount');
                        
                        if (modalNameElement) modalNameElement.textContent = customerName;
                        if (modalBalanceElement) modalBalanceElement.textContent = formatCurrency(displayBalance);
                        if (modalCustomerIdInput) modalCustomerIdInput.value = customerId;
                        if (modalCreditLimitInput) modalCreditLimitInput.value = numericCreditLimit.toFixed(2);
                        
                        var modalInstance = new bootstrap.Modal(creditLimitModal);
                        modalInstance.show();
                    }
                }
            });
        });
    }
    
    // دالة formatCurrency (إذا لم تكن موجودة)
    if (typeof formatCurrency === 'undefined') {
        function formatCurrency(amount) {
            if (typeof amount === 'string') {
                amount = parseFloat(amount) || 0;
            }
            return new Intl.NumberFormat('ar-EG', {
                style: 'currency',
                currency: 'EGP',
                minimumFractionDigits: 2
            }).format(amount || 0);
        }
    }
});
</script>

<!-- Modal لتغيير المندوب - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="changeSalesRepModal" tabindex="-1" aria-labelledby="changeSalesRepModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="changeSalesRepModalLabel">
                    <i class="bi bi-arrow-left-right me-2"></i>نقل العميل لمندوب آخر
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="changeSalesRepForm">
                <div class="modal-body">
                    <input type="hidden" id="changeSalesRepCustomerId" name="customer_id">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>العميل:</strong> <span id="changeSalesRepCustomerName"></span><br>
                        <strong>المندوب الحالي:</strong> <span id="changeSalesRepCurrentRepName"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label for="newSalesRepSelect" class="form-label">
                            <i class="bi bi-person-badge me-2"></i>اختر المندوب الجديد <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="newSalesRepSelect" name="new_sales_rep_id" required>
                            <option value="">-- اختر المندوب --</option>
                            <?php
                            // جلب قائمة المندوبين النشطين
                            $salesReps = $db->query(
                                "SELECT id, full_name, username FROM users WHERE role = 'sales' AND status = 'active' ORDER BY full_name ASC, username ASC"
                            );
                            foreach ($salesReps as $rep):
                                $repName = htmlspecialchars($rep['full_name'] ?? $rep['username'] ?? '');
                                $repId = (int)$rep['id'];
                            ?>
                                <option value="<?php echo $repId; ?>"><?php echo $repName; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">سيتم نقل العميل وجميع بياناته المرتبطة إلى المندوب الجديد</div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="transferInvoices" name="transfer_invoices" value="1" checked>
                            <label class="form-check-label" for="transferInvoices">
                                نقل الفواتير المرتبطة بهذا العميل
                            </label>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>ملاحظة مهمة:</strong> التحصيلات <strong>لن يتم نقلها</strong> وستبقى مع المندوب الأصلي للحفاظ على السجلات التاريخية.
                    </div>
                    
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>تحذير:</strong> هذه العملية لا يمكن التراجع عنها. سيتم تحديث جميع السجلات المرتبطة بالعميل.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>نقل العميل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card نقل العميل لمندوب آخر - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="changeSalesRepCard" style="display: none;">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="bi bi-arrow-left-right me-2"></i>نقل العميل لمندوب آخر
        </h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeChangeSalesRepCard()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <form id="changeSalesRepCardForm">
        <div class="card-body">
            <input type="hidden" id="changeSalesRepCardCustomerId" name="customer_id">
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>العميل:</strong> <span id="changeSalesRepCardCustomerName"></span><br>
                <strong>المندوب الحالي:</strong> <span id="changeSalesRepCardCurrentRepName"></span>
            </div>
            
            <div class="mb-3">
                <label for="newSalesRepCardSelect" class="form-label">
                    <i class="bi bi-person-badge me-2"></i>اختر المندوب الجديد <span class="text-danger">*</span>
                </label>
                <select class="form-select" id="newSalesRepCardSelect" name="new_sales_rep_id" required>
                    <option value="">-- اختر المندوب --</option>
                    <?php
                    // جلب قائمة المندوبين النشطين
                    $salesReps = $db->query(
                        "SELECT id, full_name, username FROM users WHERE role = 'sales' AND status = 'active' ORDER BY full_name ASC, username ASC"
                    );
                    foreach ($salesReps as $rep):
                        $repName = htmlspecialchars($rep['full_name'] ?? $rep['username'] ?? '');
                        $repId = (int)$rep['id'];
                    ?>
                        <option value="<?php echo $repId; ?>"><?php echo $repName; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">سيتم نقل العميل وجميع بياناته المرتبطة إلى المندوب الجديد</div>
            </div>
            
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="transferInvoicesCard" name="transfer_invoices" value="1" checked>
                    <label class="form-check-label" for="transferInvoicesCard">
                        نقل الفواتير المرتبطة بهذا العميل
                    </label>
                </div>
            </div>
            
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>ملاحظة مهمة:</strong> التحصيلات <strong>لن يتم نقلها</strong> وستبقى مع المندوب الأصلي للحفاظ على السجلات التاريخية.
            </div>
            
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>تحذير:</strong> هذه العملية لا يمكن التراجع عنها. سيتم تحديث جميع السجلات المرتبطة بالعميل.
            </div>
        </div>
        <div class="card-footer">
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-secondary flex-fill" onclick="closeChangeSalesRepCard()">
                    <i class="bi bi-x-circle me-2"></i>إلغاء
                </button>
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-check-circle me-2"></i>نقل العميل
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    // دالة فتح Modal تغيير المندوب
function openChangeSalesRepModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    const customerId = button.getAttribute('data-customer-id');
    const customerName = button.getAttribute('data-customer-name');
    const currentRepId = button.getAttribute('data-current-rep-id');
    const currentRepName = button.getAttribute('data-current-rep-name');
    
    var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('changeSalesRepCard');
        if (card) {
            const cardCustomerId = card.querySelector('#changeSalesRepCardCustomerId');
            const cardCustomerName = card.querySelector('#changeSalesRepCardCustomerName');
            const cardCurrentRepName = card.querySelector('#changeSalesRepCardCurrentRepName');
            const newSalesRepCardSelect = card.querySelector('#newSalesRepCardSelect');
            
            if (cardCustomerId) cardCustomerId.value = customerId;
            if (cardCustomerName) cardCustomerName.textContent = customerName;
            if (cardCurrentRepName) cardCurrentRepName.textContent = currentRepName;
            if (newSalesRepCardSelect) {
                newSalesRepCardSelect.value = '';
                const options = newSalesRepCardSelect.querySelectorAll('option');
                options.forEach(option => {
                    if (option.value === currentRepId) {
                        option.style.display = 'none';
                    } else {
                        option.style.display = '';
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
        const changeSalesRepModal = document.getElementById('changeSalesRepModal');
        const changeSalesRepCustomerId = document.getElementById('changeSalesRepCustomerId');
        const changeSalesRepCustomerName = document.getElementById('changeSalesRepCustomerName');
        const changeSalesRepCurrentRepName = document.getElementById('changeSalesRepCurrentRepName');
        const newSalesRepSelect = document.getElementById('newSalesRepSelect');
        
        if (!changeSalesRepModal || !changeSalesRepCustomerId || !changeSalesRepCustomerName || !changeSalesRepCurrentRepName || !newSalesRepSelect) {
            console.error('Change sales rep modal elements not found');
            return;
        }
        
        // انتظار قليل لضمان إغلاق النماذج السابقة
        setTimeout(function() {
            // تعيين القيم
            changeSalesRepCustomerId.value = customerId;
            changeSalesRepCustomerName.textContent = customerName;
            changeSalesRepCurrentRepName.textContent = currentRepName;
            
            // إزالة التحديد السابق
            newSalesRepSelect.value = '';
            
            // إخفاء المندوب الحالي من القائمة
            const options = newSalesRepSelect.querySelectorAll('option');
            options.forEach(option => {
                if (option.value === currentRepId) {
                    option.style.display = 'none';
                } else {
                    option.style.display = '';
                }
            });
            
            // فتح Modal
            const modalInstance = bootstrap.Modal.getOrCreateInstance(changeSalesRepModal);
            modalInstance.show();
        }, 100);
    }
}

    // معالجة Modal تغيير المندوب
(function() {
    const changeSalesRepModal = document.getElementById('changeSalesRepModal');
    const changeSalesRepForm = document.getElementById('changeSalesRepForm');
    const newSalesRepSelect = document.getElementById('newSalesRepSelect');
    
    if (changeSalesRepModal) {
        // إزالة backdrop بسرعة عند بدء الإغلاق
        changeSalesRepModal.addEventListener('hide.bs.modal', function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => {
                backdrop.style.transition = 'none';
                backdrop.style.opacity = '0';
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
        
        changeSalesRepModal.addEventListener('hidden.bs.modal', function() {
            // تنظيف backdrop المتبقي (احتياطي)
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            if (changeSalesRepForm) changeSalesRepForm.reset();
            // إظهار جميع الخيارات مرة أخرى
            if (newSalesRepSelect) {
                const options = newSalesRepSelect.querySelectorAll('option');
                options.forEach(option => {
                    option.style.display = '';
                });
            }
        });
    }
    
    // معالجة إرسال النموذج
    if (changeSalesRepForm) {
        changeSalesRepForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = changeSalesRepForm.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            // تعطيل الزر
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري النقل...';
            
            // إعداد FormData
            const formData = new FormData(changeSalesRepForm);
            
            // إرسال طلب AJAX
            fetch('<?php echo getRelativeUrl("api/change_customer_sales_rep.php"); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // إغلاق Modal
                    const modalInstance = bootstrap.Modal.getInstance(changeSalesRepModal);
                    modalInstance.hide();
                    
                    // إظهار رسالة نجاح
                    showAlert('success', data.message);
                    
                    // إعادة تحميل الصفحة بعد ثانيتين
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showAlert('danger', data.message);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            });
        });
    }
    
    // دالة لإظهار رسائل التنبيه
    function showAlert(type, message) {
        const alertContainer = document.querySelector('.container-fluid, .container, main') || document.body;
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        const firstChild = alertContainer.firstElementChild;
        if (firstChild) {
            alertContainer.insertBefore(alertDiv, firstChild);
        } else {
            alertContainer.appendChild(alertDiv);
        }
        
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
})();
</script>

<!-- JavaScript Functions الأساسية للـ Modal/Card Dual System -->
<script>
// دالة التحقق من الموبايل
function isMobile() {
    return window.innerWidth <= 768;
}

// دالة Scroll تلقائي
function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80; // مساحة للـ header
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, elementTop - offset),
                behavior: 'smooth'
            });
        });
    }, 200);
}

// دالة إغلاق جميع النماذج
function closeAllForms() {
    // إغلاق جميع Cards على الموبايل
    const cards = [
        'repDetailsCard', 'repCollectPaymentCard', 'repViewLocationCard',
        'repCustomerHistoryCard', 'repCustomerReturnCard',
        'allCustomersCollectPaymentCard', 'allCustomersViewLocationCard',
        'editRepCustomerCard', 'customerExportCard',
        'setCreditLimitCard', 'changeSalesRepCard'
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
        'repDetailsModal', 'repCollectPaymentModal', 'repViewLocationModal',
        'repCustomerHistoryModal', 'repCustomerReturnModal',
        'allCustomersCollectPaymentModal', 'allCustomersViewLocationModal',
        'editRepCustomerModal', 'customerExportModal',
        'setCreditLimitModal', 'changeSalesRepModal'
    ];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// دالة فتح نموذج تحصيل ديون العميل (Modal أو Card)
function showRepCollectPaymentModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    const customerId = button.getAttribute('data-customer-id') || '';
    const customerName = button.getAttribute('data-customer-name') || '-';
    const balanceRaw = button.getAttribute('data-customer-balance') || '0';
    const balanceFormatted = button.getAttribute('data-customer-balance-formatted') || balanceRaw;
    let numericBalance = parseFloat(balanceRaw);
    if (!Number.isFinite(numericBalance)) {
        numericBalance = 0;
    }
    const debtAmount = numericBalance > 0 ? numericBalance : 0;
    
    var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('repCollectPaymentCard');
        if (card) {
            const customerIdInput = card.querySelector('#repCollectionCardCustomerId');
            const customerNameEl = card.querySelector('.rep-collection-card-customer-name');
            const debtEl = card.querySelector('.rep-collection-card-current-debt');
            const amountInput = card.querySelector('#repCollectionCardAmount');
            const errorDiv = card.querySelector('#repCollectionCardError');
            const successDiv = card.querySelector('#repCollectionCardSuccess');
            
            if (customerIdInput) customerIdInput.value = customerId;
            if (customerNameEl) customerNameEl.textContent = customerName;
            if (debtEl) debtEl.textContent = balanceFormatted;
            if (amountInput) {
                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
            }
            if (errorDiv) errorDiv.classList.add('d-none');
            if (successDiv) successDiv.classList.add('d-none');
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('repCollectPaymentModal');
        if (modal) {
            const customerIdInput = modal.querySelector('#repCollectionCustomerId');
            const customerNameEl = modal.querySelector('.rep-collection-customer-name');
            const debtEl = modal.querySelector('.rep-collection-current-debt');
            const amountInput = modal.querySelector('input[name="amount"]');
            const errorDiv = modal.querySelector('#repCollectionError');
            const successDiv = modal.querySelector('#repCollectionSuccess');
            
            if (customerIdInput) customerIdInput.value = customerId;
            if (customerNameEl) customerNameEl.textContent = customerName;
            if (debtEl) debtEl.textContent = balanceFormatted;
            if (amountInput) {
                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
                if (debtAmount > 0) amountInput.focus();
            }
            if (errorDiv) errorDiv.classList.add('d-none');
            if (successDiv) successDiv.classList.add('d-none');
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// دالة إغلاق Card تحصيل الديون
function closeRepCollectPaymentCard() {
    const card = document.getElementById('repCollectPaymentCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
        const errorDiv = card.querySelector('#repCollectionCardError');
        const successDiv = card.querySelector('#repCollectionCardSuccess');
        if (errorDiv) errorDiv.classList.add('d-none');
        if (successDiv) successDiv.classList.add('d-none');
    }
}

// دالة فتح نموذج تحصيل ديون العميل (لجميع العملاء) - Modal أو Card
function showAllCustomersCollectPaymentModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    const customerId = button.getAttribute('data-customer-id') || '';
    const customerName = button.getAttribute('data-customer-name') || '-';
    const balanceRaw = button.getAttribute('data-customer-balance') || '0';
    const balanceFormatted = button.getAttribute('data-customer-balance-formatted') || balanceRaw;
    let numericBalance = parseFloat(balanceRaw);
    if (!Number.isFinite(numericBalance)) {
        numericBalance = 0;
    }
    const debtAmount = numericBalance > 0 ? numericBalance : 0;
    
    var isMobileDevice = typeof isMobile === 'function' ? isMobile() : window.innerWidth <= 768;
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('allCustomersCollectPaymentCard');
        if (card) {
            const customerIdInput = card.querySelector('#allCustomersCollectionCardCustomerId');
            const customerNameEl = card.querySelector('.all-customers-collection-card-customer-name');
            const debtEl = card.querySelector('.all-customers-collection-card-current-debt');
            const amountInput = card.querySelector('#allCustomersCollectionCardAmount');
            
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
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('allCustomersCollectPaymentModal');
        if (modal) {
            const customerIdInput = modal.querySelector('input[name="customer_id"]');
            const customerNameEl = modal.querySelector('.all-customers-collection-customer-name');
            const debtEl = modal.querySelector('.all-customers-collection-current-debt');
            const amountInput = modal.querySelector('#allCustomersCollectionAmount');
            
            if (customerIdInput) customerIdInput.value = customerId;
            if (customerNameEl) customerNameEl.textContent = customerName;
            if (debtEl) debtEl.textContent = balanceFormatted;
            if (amountInput) {
                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
                if (debtAmount > 0) amountInput.focus();
            }
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// دالة إغلاق Card تحصيل الديون (لجميع العملاء)
function closeAllCustomersCollectPaymentCard() {
    const card = document.getElementById('allCustomersCollectPaymentCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

// دالة إغلاق Card عرض موقع العميل
function closeRepViewLocationCard() {
    const card = document.getElementById('repViewLocationCard');
    if (card) {
        card.style.display = 'none';
        const mapFrame = card.querySelector('.rep-location-card-map-frame');
        const customerNameEl = card.querySelector('.rep-location-card-customer-name');
        const externalLink = card.querySelector('.rep-location-card-open-map');
        if (mapFrame) mapFrame.src = '';
        if (customerNameEl) customerNameEl.textContent = '-';
        if (externalLink) externalLink.href = '#';
    }
}

// دالة إغلاق Card عرض موقع العميل (لجميع العملاء)
function closeAllCustomersViewLocationCard() {
    const card = document.getElementById('allCustomersViewLocationCard');
    if (card) {
        card.style.display = 'none';
        const mapFrame = card.querySelector('.all-customers-location-card-map-frame');
        const customerNameEl = card.querySelector('.all-customers-location-card-customer-name');
        const externalLink = card.querySelector('.all-customers-location-card-open-map');
        if (mapFrame) mapFrame.src = '';
        if (customerNameEl) customerNameEl.textContent = '-';
        if (externalLink) externalLink.href = '#';
    }
}

// دالة إغلاق Card تحديد الحد الائتماني
function closeSetCreditLimitCard() {
    const card = document.getElementById('setCreditLimitCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
        const customerNameEl = card.querySelector('.credit-limit-card-customer-name');
        const balanceEl = card.querySelector('.credit-limit-card-current-balance');
        const customerIdInput = card.querySelector('#creditLimitCardCustomerId');
        if (customerNameEl) customerNameEl.textContent = '-';
        if (balanceEl) balanceEl.textContent = '-';
        if (customerIdInput) customerIdInput.value = '';
    }
}

// دالة إغلاق Card سجل المشتريات
function closeRepCustomerHistoryCard() {
    const card = document.getElementById('repCustomerHistoryCard');
    if (card) {
        card.style.display = 'none';
        const nameElement = card.querySelector('.rep-history-card-customer-name');
        const loadingElement = card.querySelector('.rep-history-card-loading');
        const contentElement = card.querySelector('.rep-history-card-content');
        const errorElement = card.querySelector('.rep-history-card-error');
        const invoicesTableBody = card.querySelector('.rep-history-card-table tbody');
        const returnsContainer = card.querySelector('.rep-history-card-returns');
        if (nameElement) nameElement.textContent = '-';
        if (loadingElement) loadingElement.classList.remove('d-none');
        if (contentElement) contentElement.classList.add('d-none');
        if (errorElement) {
            errorElement.classList.add('d-none');
            errorElement.textContent = '';
        }
        if (invoicesTableBody) invoicesTableBody.innerHTML = '';
        if (returnsContainer) returnsContainer.innerHTML = '';
    }
}

// دالة إغلاق Card المرتجع
function closeRepCustomerReturnCard() {
    const card = document.getElementById('repCustomerReturnCard');
    if (card) {
        card.style.display = 'none';
        const nameElement = card.querySelector('.rep-return-card-customer-name');
        const phoneElement = card.querySelector('.rep-return-card-customer-phone');
        const addressElement = card.querySelector('.rep-return-card-customer-address');
        const loadingElement = card.querySelector('.rep-return-card-loading');
        const contentElement = card.querySelector('.rep-return-card-content');
        const errorElement = card.querySelector('.rep-return-card-error');
        const tableBody = card.querySelector('.rep-return-card-table tbody');
        if (nameElement) nameElement.textContent = '-';
        if (phoneElement) phoneElement.textContent = '-';
        if (addressElement) addressElement.textContent = '-';
        if (loadingElement) loadingElement.classList.remove('d-none');
        if (contentElement) contentElement.classList.add('d-none');
        if (errorElement) {
            errorElement.classList.add('d-none');
            errorElement.textContent = '';
        }
        if (tableBody) tableBody.innerHTML = '';
    }
}

// دالة إغلاق Card تصدير العملاء
function closeCustomerExportCard() {
    const card = document.getElementById('customerExportCard');
    if (card) {
        card.style.display = 'none';
        const repSelect = card.querySelector('#exportRepSelectCard');
        const customersSection = card.querySelector('#customersCardSection');
        const selectRepMessage = card.querySelector('#selectRepCardMessage');
        const exportCardList = card.querySelector('#exportCustomersCardList');
        const actionButtons = card.querySelector('#exportCardActionButtons');
        if (repSelect) repSelect.value = '';
        if (customersSection) customersSection.style.display = 'none';
        if (selectRepMessage) selectRepMessage.style.display = 'block';
        if (exportCardList) exportCardList.innerHTML = '';
        if (actionButtons) actionButtons.style.display = 'none';
    }
}

// دالة إغلاق Card نقل المندوب
function closeChangeSalesRepCard() {
    const card = document.getElementById('changeSalesRepCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
        const customerIdInput = card.querySelector('#changeSalesRepCardCustomerId');
        const customerNameEl = card.querySelector('#changeSalesRepCardCustomerName');
        const currentRepNameEl = card.querySelector('#changeSalesRepCardCurrentRepName');
        const newRepSelect = card.querySelector('#newSalesRepCardSelect');
        if (customerIdInput) customerIdInput.value = '';
        if (customerNameEl) customerNameEl.textContent = '';
        if (currentRepNameEl) currentRepNameEl.textContent = '';
        if (newRepSelect) newRepSelect.value = '';
    }
}

// دالة إغلاق Card تعديل عميل المندوب
function closeEditRepCustomerCard() {
    const card = document.getElementById('editRepCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
        const idInput = card.querySelector('#editRepCustomerCardId');
        const nameInput = card.querySelector('#editRepCustomerCardName');
        const addressInput = card.querySelector('#editRepCustomerCardAddress');
        const regionInput = card.querySelector('#editRepCustomerCardRegionId');
        const balanceInput = card.querySelector('#editRepCustomerCardBalance');
        const phoneContainer = card.querySelector('#editRepPhoneNumbersCardContainer');
        if (idInput) idInput.value = '';
        if (nameInput) nameInput.value = '';
        if (addressInput) addressInput.value = '';
        if (regionInput) regionInput.value = '';
        if (balanceInput) balanceInput.value = '';
        if (phoneContainer) {
            phoneContainer.innerHTML = `
                <div class="input-group mb-2">
                    <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
                    <button type="button" class="btn btn-outline-danger remove-phone-btn" style="display: none;">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
        }
        if (typeof updateEditRepCardRemoveButtons === 'function') {
            updateEditRepCardRemoveButtons();
        }
    }
}

// البحث الديناميكي في عملاء المندوبين
(function() {
    const searchInput = document.getElementById('allCustomersSearch');
    const searchForm = document.getElementById('repCustomersSearchForm');
    const tableBody = document.getElementById('repCustomersTableBody');
    const paginationContainer = document.getElementById('repCustomersPagination');
    const totalCountSpan = document.getElementById('repCustomersTotalCount');
    const repFilter = document.getElementById('allCustomersRepFilter');
    const debtStatusFilter = document.getElementById('allCustomersDebtStatusFilter');
    
    let searchTimeout = null;
    let currentAbortController = null;
    let currentPage = <?php echo $allCustomersPageNum; ?>;
    
    if (!searchInput || !tableBody) {
        return;
    }
    
    // منع الإرسال الافتراضي للنموذج
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            loadRepCustomers(1);
        });
    }
    
    // ===== البحث الفوري مع Autocomplete =====
    var autocompleteDropdown = document.getElementById('autocompleteDropdown');
    var autocompleteTimeout = null;
    var autocompleteAbortController = null;
    var selectedAutocompleteIndex = -1;
    var autocompleteResults = [];
    
    // دالة للبحث الفوري في autocomplete
    function performAutocompleteSearch(query) {
        if (!autocompleteDropdown) return;
        
        if (!query || query.length < 1) {
            hideAutocomplete();
            return;
        }
        
        // إلغاء الطلب السابق إن وجد
        if (autocompleteAbortController) {
            autocompleteAbortController.abort();
        }
        autocompleteAbortController = new AbortController();
        
        // إظهار حالة التحميل
        showAutocompleteLoading();
        
        // جلب النتائج من API
        var apiUrl = '<?php echo getRelativeUrl("api/get_customers_autocomplete.php"); ?>';
        var url = apiUrl + '?q=' + encodeURIComponent(query) + '&section=delegates';
        
        fetch(url, {
            method: 'GET',
            credentials: 'include',
            signal: autocompleteAbortController.signal
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.results) {
                autocompleteResults = data.results;
                displayAutocompleteResults(data.results, query);
            } else {
                showAutocompleteNoResults();
            }
        })
        .catch(function(error) {
            if (error.name !== 'AbortError') {
                console.error('Autocomplete search error:', error);
                hideAutocomplete();
            }
        });
    }
    
    // دالة لعرض نتائج autocomplete
    function displayAutocompleteResults(results, query) {
        if (!autocompleteDropdown) return;
        
        if (results.length === 0) {
            showAutocompleteNoResults();
            return;
        }
        
        var html = '';
        results.forEach(function(result, index) {
            var balanceClass = 'zero';
            var balanceText = 'رصيد: ' + result.balance_formatted;
            if (result.balance > 0) {
                balanceClass = 'positive';
                balanceText = 'مدين: ' + result.balance_formatted;
            } else if (result.balance < 0) {
                balanceClass = 'negative';
                balanceText = 'دائن: ' + result.balance_formatted;
            }
            
            html += '<div class="autocomplete-item" data-index="' + index + '" data-customer-id="' + result.id + '" role="option" tabindex="0">';
            html += '<div class="autocomplete-item-name">' + escapeHtml(result.name) + '</div>';
            html += '<div class="autocomplete-item-sub">';
            if (result.sub_text) {
                html += '<span>' + escapeHtml(result.sub_text) + '</span>';
            }
            html += '<span class="autocomplete-item-balance ' + balanceClass + '">' + balanceText + '</span>';
            html += '</div>';
            html += '</div>';
        });
        
        autocompleteDropdown.innerHTML = html;
        autocompleteDropdown.classList.add('show');
        autocompleteDropdown.style.display = 'block';
        if (searchInput) {
            searchInput.setAttribute('aria-expanded', 'true');
        }
        
        // إضافة event listeners للعناصر
        autocompleteDropdown.querySelectorAll('.autocomplete-item').forEach(function(item, index) {
            item.addEventListener('click', function() {
                selectAutocompleteResult(results[index]);
            });
            item.addEventListener('mouseenter', function() {
                removeAutocompleteSelection();
                selectedAutocompleteIndex = index;
                item.classList.add('selected');
            });
        });
        
        selectedAutocompleteIndex = -1;
    }
    
    // دالة لإظهار حالة التحميل
    function showAutocompleteLoading() {
        if (!autocompleteDropdown) return;
        autocompleteDropdown.innerHTML = '<div class="autocomplete-loading"><i class="bi bi-hourglass-split"></i> جاري البحث...</div>';
        autocompleteDropdown.classList.add('show');
        autocompleteDropdown.style.display = 'block';
        if (searchInput) {
            searchInput.setAttribute('aria-expanded', 'true');
        }
    }
    
    // دالة لإظهار عدم وجود نتائج
    function showAutocompleteNoResults() {
        if (!autocompleteDropdown) return;
        autocompleteDropdown.innerHTML = '<div class="autocomplete-no-results">لا توجد نتائج</div>';
        autocompleteDropdown.classList.add('show');
        autocompleteDropdown.style.display = 'block';
        if (searchInput) {
            searchInput.setAttribute('aria-expanded', 'true');
        }
    }
    
    // دالة لإخفاء autocomplete
    function hideAutocomplete() {
        if (!autocompleteDropdown) return;
        autocompleteDropdown.classList.remove('show');
        autocompleteDropdown.style.display = 'none';
        autocompleteDropdown.innerHTML = '';
        if (searchInput) {
            searchInput.setAttribute('aria-expanded', 'false');
        }
        selectedAutocompleteIndex = -1;
        autocompleteResults = [];
    }
    
    // دالة لإزالة التمييز من جميع العناصر
    function removeAutocompleteSelection() {
        if (autocompleteDropdown) {
            autocompleteDropdown.querySelectorAll('.autocomplete-item').forEach(function(item) {
                item.classList.remove('selected');
            });
        }
    }
    
    // دالة لاختيار نتيجة من autocomplete
    function selectAutocompleteResult(result) {
        if (!result) return;
        
        // تعيين قيمة البحث واخفاء autocomplete
        searchInput.value = result.name;
        hideAutocomplete();
        
        // تنفيذ البحث الكامل
        if (searchTimeout) clearTimeout(searchTimeout);
        if (currentAbortController) currentAbortController.abort();
        loadRepCustomers(1);
    }
    
    // دالة للهروب من HTML
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // البحث الديناميكي عند الكتابة
    searchInput.addEventListener('input', function() {
        const searchValue = this.value.trim();
        
        // البحث الفوري في autocomplete
        if (autocompleteTimeout) clearTimeout(autocompleteTimeout);
        if (autocompleteAbortController) autocompleteAbortController.abort();
        
        if (searchValue.length >= 1) {
            autocompleteTimeout = setTimeout(function() {
                performAutocompleteSearch(searchValue);
            }, 200); // debounce 200ms للبحث الفوري
        } else {
            hideAutocomplete();
        }
        
        // إلغاء أي timeout سابق
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // إلغاء أي طلب AJAX سابق
        if (currentAbortController) {
            currentAbortController.abort();
        }
        
        // البحث بعد 500ms من توقف المستخدم عن الكتابة (debounce)
        searchTimeout = setTimeout(function() {
            loadRepCustomers(1);
        }, 500);
    });
    
    // البحث عند الضغط على Enter
    searchInput.addEventListener('keydown', function(e) {
        // إذا كان autocomplete مفتوحاً، معالجة مفاتيح التنقل
        if (autocompleteDropdown && autocompleteDropdown.classList.contains('show')) {
            var items = autocompleteDropdown.querySelectorAll('.autocomplete-item');
            
            if (e.key === 'ArrowDown' || e.keyCode === 40) {
                e.preventDefault();
                removeAutocompleteSelection();
                selectedAutocompleteIndex = Math.min(selectedAutocompleteIndex + 1, items.length - 1);
                if (items[selectedAutocompleteIndex]) {
                    items[selectedAutocompleteIndex].classList.add('selected');
                    items[selectedAutocompleteIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
                return;
            }
            
            if (e.key === 'ArrowUp' || e.keyCode === 38) {
                e.preventDefault();
                removeAutocompleteSelection();
                selectedAutocompleteIndex = Math.max(selectedAutocompleteIndex - 1, -1);
                if (selectedAutocompleteIndex >= 0 && items[selectedAutocompleteIndex]) {
                    items[selectedAutocompleteIndex].classList.add('selected');
                    items[selectedAutocompleteIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
                return;
            }
            
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                if (selectedAutocompleteIndex >= 0 && autocompleteResults[selectedAutocompleteIndex]) {
                    selectAutocompleteResult(autocompleteResults[selectedAutocompleteIndex]);
                } else {
                    // تنفيذ البحث الكامل
                    hideAutocomplete();
                    if (searchTimeout) clearTimeout(searchTimeout);
                    if (currentAbortController) currentAbortController.abort();
                    loadRepCustomers(1);
                }
                return;
            }
            
            if (e.key === 'Escape' || e.keyCode === 27) {
                e.preventDefault();
                hideAutocomplete();
                return;
            }
        }
        
        // Enter للبحث الكامل إذا لم يكن autocomplete مفتوحاً
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            hideAutocomplete();
            if (searchTimeout) clearTimeout(searchTimeout);
            if (currentAbortController) currentAbortController.abort();
            loadRepCustomers(1);
        }
    });
    
    // إخفاء autocomplete عند النقر خارج الحقل
    document.addEventListener('click', function(e) {
        if (autocompleteDropdown && 
            !searchInput.contains(e.target) && 
            !autocompleteDropdown.contains(e.target)) {
            hideAutocomplete();
        }
    });
    
    // إخفاء autocomplete عند فقدان التركيز
    searchInput.addEventListener('blur', function() {
        setTimeout(function() {
            if (document.activeElement !== searchInput && 
                !autocompleteDropdown.contains(document.activeElement)) {
                hideAutocomplete();
            }
        }, 200);
    });
    
    // البحث عند تغيير الفلاتر
    if (repFilter) {
        repFilter.addEventListener('change', function() {
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            loadRepCustomers(1);
        });
    }
    
    if (debtStatusFilter) {
        debtStatusFilter.addEventListener('change', function() {
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            loadRepCustomers(1);
        });
    }
    
    // دالة تحميل عملاء المندوبين
    function loadRepCustomers(page) {
        const searchValue = searchInput.value.trim();
        const repFilterValue = repFilter ? repFilter.value : '0';
        const debtStatusValue = debtStatusFilter ? debtStatusFilter.value : 'all';
        currentPage = page;
        
        // إلغاء أي طلب سابق
        if (currentAbortController) {
            currentAbortController.abort();
        }
        
        // إنشاء AbortController جديد
        currentAbortController = new AbortController();
        
        // إظهار مؤشر التحميل
        tableBody.innerHTML = '<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">جاري التحميل...</span></div></td></tr>';
        
        // بناء URL للبحث
        const params = new URLSearchParams();
        params.append('cs', searchValue);
        params.append('cds', debtStatusValue);
        params.append('rep_filter', repFilterValue);
        params.append('p', page);
        params.append('per_page', <?php echo $allCustomersPerPage; ?>);
        
        // إرسال طلب AJAX
        fetch('<?php echo getRelativeUrl("api/search_rep_customers.php"); ?>?' + params.toString(), {
            method: 'GET',
            credentials: 'include',
            signal: currentAbortController.signal
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // تحديث الجدول
                tableBody.innerHTML = data.tableRows;
                
                // تحديث Pagination
                if (paginationContainer) {
                    paginationContainer.innerHTML = data.pagination;
                }
                
                // تحديث العدد الإجمالي
                if (totalCountSpan) {
                    totalCountSpan.textContent = data.totalCustomers;
                }
                
                // تحديث URL بدون إعادة تحميل الصفحة
                const url = new URL(window.location);
                url.searchParams.set('cs', searchValue);
                url.searchParams.set('cds', debtStatusValue);
                url.searchParams.set('rep_filter', repFilterValue);
                url.searchParams.set('cp', page);
                window.history.pushState({}, '', url);
            } else {
                tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">' + (data.message || 'حدث خطأ في البحث') + '</td></tr>';
            }
        })
        .catch(error => {
            if (error.name === 'AbortError') {
                // تم إلغاء الطلب، لا حاجة لعرض رسالة خطأ
                return;
            }
            console.error('Error loading rep customers:', error);
            tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">حدث خطأ في تحميل البيانات</td></tr>';
        })
        .finally(() => {
            currentAbortController = null;
        });
    }
    
    // جعل دالة loadRepCustomers متاحة عالمياً للاستخدام من Pagination
    window.loadRepCustomers = loadRepCustomers;
})();
</script>


<?php
/**
 * صفحة إدارة طلبات العملاء
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// منع الكاش عند التبديل بين تبويبات الشريط الجانبي لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/path_helper.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// دالة مساعدة للحصول على اسم جدول عناصر الطلب
function getOrderItemsTableName($db) {
    $tableNames = ['order_items', 'customer_order_items'];
    foreach ($tableNames as $tableName) {
        // استخدام escape للاسم الآمن
        $escapedTableName = $db->escape($tableName);
        $tableCheck = $db->queryOne("SHOW TABLES LIKE '{$escapedTableName}'");
        if (!empty($tableCheck)) {
            return $tableName;
        }
    }
    // إذا لم يكن الجدول موجوداً، نعيد اسم الجدول الافتراضي
    return 'order_items';
}

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

$userRole = $currentUser['role'] ?? '';
$isSalesUser = $userRole === 'sales';
$isManagerOrAccountant = in_array($userRole, ['manager', 'accountant'], true);

// التحقق من وجود عمود order_type وإضافته إذا لم يكن موجوداً
try {
    $hasOrderTypeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customer_orders LIKE 'order_type'"));
    
    if (!$hasOrderTypeColumn) {
        $db->execute("ALTER TABLE customer_orders ADD COLUMN order_type ENUM('sales_rep', 'company') DEFAULT 'sales_rep' AFTER sales_rep_id");
        error_log('Added order_type column to customer_orders table');
    }
} catch (Throwable $e) {
    error_log('Error checking/adding order_type column: ' . $e->getMessage());
}

// التحقق من وجود عمود order_type وإضافته إذا لم يكن موجوداً
try {
    $hasOrderTypeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customer_orders LIKE 'order_type'"));
    
    if (!$hasOrderTypeColumn) {
        $db->execute("ALTER TABLE customer_orders ADD COLUMN order_type ENUM('sales_rep', 'company') DEFAULT 'sales_rep' AFTER sales_rep_id");
        error_log('Added order_type column to customer_orders table');
    }
} catch (Throwable $e) {
    error_log('Error checking/adding order_type column: ' . $e->getMessage());
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'order_number' => $_GET['order_number'] ?? '',
    'status' => $_GET['status'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'sales_rep_id' => isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : ''
];

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة AJAX يتم التعامل معها في manager.php قبل تضمين هذا الملف

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_order') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        // إذا كان المستخدم مندوب مبيعات، استخدم معرفه مباشرة
        if ($isSalesUser) {
            $salesRepId = $currentUser['id'];
        } else {
            $salesRepId = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : null;
        }
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $deliveryDate = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
        $priority = $_POST['priority'] ?? 'normal';
        $notes = '';
        $createNewCustomer = isset($_POST['create_new_customer']) && $_POST['create_new_customer'] === '1';
        $newCustomerName = trim($_POST['new_customer_name'] ?? '');
        $newCustomerPhone = trim($_POST['new_customer_phone'] ?? '');
        $newCustomerAddress = trim($_POST['new_customer_address'] ?? '');
        $newCustomerLatitude = isset($_POST['new_customer_latitude']) && $_POST['new_customer_latitude'] !== '' ? trim($_POST['new_customer_latitude']) : null;
        $newCustomerLongitude = isset($_POST['new_customer_longitude']) && $_POST['new_customer_longitude'] !== '' ? trim($_POST['new_customer_longitude']) : null;
        
        // معالجة العناصر - الآن template_name بدلاً من product_id
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $templateName = trim($item['template_name'] ?? '');
                $quantity = floatval($item['quantity'] ?? 0);
                if (!empty($templateName) && $quantity > 0) {
                    $items[] = [
                        'template_name' => $templateName,
                        'quantity' => $quantity,
                        'unit_price' => 0.0,
                        'total_price' => 0.0
                    ];
                }
            }
        }
        
        if (empty($items)) {
            $error = 'يجب إضافة عنصر واحد على الأقل للطلب.';
        } elseif (!$createNewCustomer && $customerId <= 0) {
            $error = 'يجب اختيار العميل أو تحديد خيار العميل الجديد.';
        } elseif ($createNewCustomer && $newCustomerName === '') {
            $error = 'اسم العميل الجديد مطلوب.';
        } else {
            $transactionStarted = false;
            $orderNumber = '';
            $orderId = null;
            $totalAmount = 0.0;
            $newCustomerCreated = false;
            $customerCreatorId = $salesRepId ?: ($currentUser['id'] ?? null);

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                if ($createNewCustomer) {
                    // التحقق من عدم تكرار بيانات العميل الجديد مع عملاء المندوب الحاليين
                    $newCustomerCreator = $customerCreatorId ?? $currentUser['id'];
                    $newCustomerRepId = $salesRepId ?? ($isSalesUser ? $currentUser['id'] : null);
                    $createdByAdminFlag = ($isSalesUser && $newCustomerRepId) ? 0 : 1;

                    // التحقق من وجود عميل مطابق في عملاء المندوب
                    if ($newCustomerRepId) {
                        $duplicateCheckConditions = [
                            "(rep_id = ? OR created_by = ?)",
                            "name = ?"
                        ];
                        $duplicateCheckParams = [$newCustomerRepId, $newCustomerRepId, $newCustomerName];
                        
                        // إضافة فحص رقم الهاتف إذا كان موجوداً
                        if (!empty($newCustomerPhone)) {
                            $duplicateCheckConditions[] = "phone = ?";
                            $duplicateCheckParams[] = $newCustomerPhone;
                        }
                        
                        // إضافة فحص العنوان إذا كان موجوداً
                        if (!empty($newCustomerAddress)) {
                            $duplicateCheckConditions[] = "address = ?";
                            $duplicateCheckParams[] = $newCustomerAddress;
                        }
                        
                        $duplicateQuery = "SELECT id, name, phone, address FROM customers WHERE " . implode(" AND ", $duplicateCheckConditions) . " LIMIT 1";
                        $duplicateCustomer = $db->queryOne($duplicateQuery, $duplicateCheckParams);
                        
                        if ($duplicateCustomer) {
                            $duplicateInfo = [];
                            if (!empty($duplicateCustomer['phone'])) {
                                $duplicateInfo[] = "رقم الهاتف: " . $duplicateCustomer['phone'];
                            }
                            if (!empty($duplicateCustomer['address'])) {
                                $duplicateInfo[] = "العنوان: " . $duplicateCustomer['address'];
                            }
                            $duplicateMessage = "يوجد عميل مسجل مسبقاً بنفس البيانات في قائمة عملائك";
                            if (!empty($duplicateInfo)) {
                                $duplicateMessage .= " (" . implode(", ", $duplicateInfo) . ")";
                            }
                            $duplicateMessage .= ". يرجى اختيار العميل الموجود من القائمة أو تعديل البيانات.";
                            throw new InvalidArgumentException($duplicateMessage);
                        }
                    }

                    // التحقق من وجود أعمدة اللوكيشن
                    $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
                    $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
                    $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
                    
                    // توليد unique_code فريد للعميل
                    require_once __DIR__ . '/../../includes/customer_code_generator.php';
                    ensureCustomerUniqueCodeColumn('customers');
                    $uniqueCode = generateUniqueCustomerCode('customers');
                    
                    $customerColumns = ['unique_code', 'name', 'phone', 'address', 'balance', 'status', 'created_by', 'rep_id', 'created_from_pos', 'created_by_admin'];
                    $customerValues = [
                        $uniqueCode,
                        $newCustomerName,
                        $newCustomerPhone !== '' ? $newCustomerPhone : null,
                        $newCustomerAddress !== '' ? $newCustomerAddress : null,
                        0.0, // رصيد العميل الجديد يجب أن يكون 0
                        'active',
                        $newCustomerCreator,
                        $newCustomerRepId,
                        0,
                        $createdByAdminFlag,
                    ];
                    $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?'];
                    
                    if ($hasLatitudeColumn && $newCustomerLatitude !== null) {
                        $customerColumns[] = 'latitude';
                        $customerValues[] = (float)$newCustomerLatitude;
                        $customerPlaceholders[] = '?';
                    }
                    
                    if ($hasLongitudeColumn && $newCustomerLongitude !== null) {
                        $customerColumns[] = 'longitude';
                        $customerValues[] = (float)$newCustomerLongitude;
                        $customerPlaceholders[] = '?';
                    }
                    
                    if ($hasLocationCapturedAtColumn && $newCustomerLatitude !== null && $newCustomerLongitude !== null) {
                        $customerColumns[] = 'location_captured_at';
                        $customerValues[] = date('Y-m-d H:i:s');
                        $customerPlaceholders[] = '?';
                    }

                    $db->execute(
                        "INSERT INTO customers (" . implode(', ', $customerColumns) . ") 
                         VALUES (" . implode(', ', $customerPlaceholders) . ")",
                        $customerValues
                    );
                    $customerId = (int)$db->getLastInsertId();
                    $newCustomerCreated = true;
                }

                if ($customerId <= 0) {
                    throw new RuntimeException('تعذر تحديد العميل المرتبط بالطلب.');
                }

                $year = date('Y');
                $month = date('m');
                $lastOrder = $db->queryOne(
                    "SELECT order_number FROM customer_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1",
                    ["ORD-{$year}{$month}-%"]
                );

                $serial = 1;
                if ($lastOrder) {
                    $parts = explode('-', $lastOrder['order_number']);
                    $serial = intval($parts[2] ?? 0) + 1;
                }
                $orderNumber = sprintf("ORD-%s%s-%04d", $year, $month, $serial);

                $subtotal = 0.0;
                $discountAmount = 0.0;
                $totalAmount = 0.0;

                // تحديد نوع الطلب
                $orderType = 'sales_rep'; // طلبات المناديب
                
                $db->execute(
                    "INSERT INTO customer_orders 
                    (order_number, customer_id, sales_rep_id, order_type, order_date, delivery_date, 
                     subtotal, discount_amount, total_amount, priority, notes, created_by, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [
                        $orderNumber,
                        $customerId,
                        $salesRepId ?? $currentUser['id'],
                        $orderType,
                        $orderDate,
                        $deliveryDate,
                        $subtotal,
                        $discountAmount,
                        $totalAmount,
                        $priority,
                        $notes,
                        $currentUser['id']
                    ]
                );

                $orderId = (int)$db->getLastInsertId();

                if ($orderId <= 0) {
                    throw new RuntimeException('فشل إنشاء الطلب: لم يتم الحصول على معرف الطلب.');
                }

                // التحقق من وجود الجدول وإنشائه إذا لم يكن موجوداً
                $orderItemsTable = null;
                $tableNames = ['order_items', 'customer_order_items'];
                foreach ($tableNames as $tableName) {
                    // استخدام escape للاسم الآمن
                    $escapedTableName = $db->escape($tableName);
                    $tableCheck = $db->queryOne("SHOW TABLES LIKE '{$escapedTableName}'");
                    if (!empty($tableCheck)) {
                        $orderItemsTable = $tableName;
                        break;
                    }
                }
                
                // إذا لم يكن الجدول موجوداً، إنشاؤه
                if (empty($orderItemsTable)) {
                    $orderItemsTable = 'order_items';
                    try {
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `order_items` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `order_id` int(11) NOT NULL,
                              `product_id` int(11) NULL,
                              `template_id` int(11) NULL,
                              `quantity` decimal(10,2) NOT NULL,
                              `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
                              `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
                              `production_status` enum('pending','in_production','completed') DEFAULT 'pending',
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              KEY `order_id` (`order_id`),
                              KEY `product_id` (`product_id`),
                              KEY `template_id` (`template_id`),
                              CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `customer_orders` (`id`) ON DELETE CASCADE
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                        error_log('Created order_items table successfully');
                    } catch (Throwable $createError) {
                        error_log('Error creating order_items table: ' . $createError->getMessage());
                        throw new RuntimeException('فشل إنشاء جدول عناصر الطلب: ' . $createError->getMessage());
                    }
                }

                // التحقق من وجود عمود template_id وإضافته إذا لم يكن موجوداً
                try {
                    $templateIdColumn = $db->queryOne("SHOW COLUMNS FROM {$orderItemsTable} LIKE 'template_id'");
                    if (empty($templateIdColumn)) {
                        $db->execute("ALTER TABLE {$orderItemsTable} ADD COLUMN template_id INT(11) NULL AFTER product_id");
                    }
                } catch (Throwable $alterError) {
                    error_log('Error checking/adding template_id column: ' . $alterError->getMessage());
                }
                
                // محاولة تعديل product_id ليكون NULL إذا كان NOT NULL
                try {
                    $productIdColumn = $db->queryOne("SHOW COLUMNS FROM {$orderItemsTable} WHERE Field = 'product_id'");
                    if (!empty($productIdColumn) && isset($productIdColumn['Null']) && $productIdColumn['Null'] === 'NO') {
                        // محاولة إزالة Foreign Key constraint أولاً
                        try {
                            $db->execute("ALTER TABLE {$orderItemsTable} DROP FOREIGN KEY order_items_ibfk_2");
                        } catch (Throwable $fkError) {
                            // قد يكون اسم الـ constraint مختلفاً أو غير موجود
                            error_log('Error dropping foreign key (may not exist): ' . $fkError->getMessage());
                        }
                        // تعديل product_id ليكون NULL
                        $db->execute("ALTER TABLE {$orderItemsTable} MODIFY COLUMN product_id INT(11) NULL");
                    }
                } catch (Throwable $modifyError) {
                    error_log('Error modifying product_id column: ' . $modifyError->getMessage());
                }
                
                // التحقق من وجود عمود product_name وإضافته إذا لم يكن موجوداً
                try {
                    $productNameColumn = $db->queryOne("SHOW COLUMNS FROM {$orderItemsTable} LIKE 'product_name'");
                    if (empty($productNameColumn)) {
                        $db->execute("ALTER TABLE {$orderItemsTable} ADD COLUMN product_name VARCHAR(255) NULL AFTER template_id");
                    }
                } catch (Throwable $alterError) {
                    error_log('Error checking/adding product_name column: ' . $alterError->getMessage());
                }

                foreach ($items as $item) {
                    $templateName = $item['template_name'];
                    
                    // البحث عن القالب بالاسم في unified_product_templates أولاً
                    $templateId = null;
                    $unifiedCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                    if (!empty($unifiedCheck)) {
                        $template = $db->queryOne(
                            "SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                            [$templateName, $templateName]
                        );
                        if ($template) {
                            $templateId = (int)$template['id'];
                        }
                    }
                    
                    // إذا لم يُعثر عليه، البحث في product_templates
                    if (!$templateId) {
                        $productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                        if (!empty($productTemplatesCheck)) {
                            $template = $db->queryOne(
                                "SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                                [$templateName, $templateName]
                            );
                            if ($template) {
                                $templateId = (int)$template['id'];
                            }
                        }
                    }
                    
                    // إدراج العنصر - حفظ الاسم في product_name إذا لم يُعثر على template_id
                    try {
                        // محاولة إدراج مع template_id إذا وُجد
                        if ($templateId) {
                            try {
                                $db->execute(
                                    "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, product_name, quantity, unit_price, total_price) 
                                     VALUES (?, NULL, ?, NULL, ?, ?, ?)",
                                    [
                                        $orderId,
                                        $templateId,
                                        $item['quantity'],
                                        $item['unit_price'],
                                        $item['total_price']
                                    ]
                                );
                            } catch (Throwable $insertError) {
                                // إذا فشل لأن product_name غير موجود، نجرب بدون product_name
                                if (stripos($insertError->getMessage(), 'product_name') !== false || 
                                    stripos($insertError->getMessage(), 'Unknown column') !== false) {
                                    $db->execute(
                                        "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, quantity, unit_price, total_price) 
                                         VALUES (?, NULL, ?, ?, ?, ?)",
                                        [
                                            $orderId,
                                            $templateId,
                                            $item['quantity'],
                                            $item['unit_price'],
                                            $item['total_price']
                                        ]
                                    );
                                } else {
                                    throw $insertError;
                                }
                            }
                        } else {
                            // إذا لم يُعثر على القالب، حفظ الاسم في product_name
                            try {
                                $db->execute(
                                    "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, product_name, quantity, unit_price, total_price) 
                                     VALUES (?, NULL, NULL, ?, ?, ?, ?)",
                                    [
                                        $orderId,
                                        $templateName,
                                        $item['quantity'],
                                        $item['unit_price'],
                                        $item['total_price']
                                    ]
                                );
                            } catch (Throwable $insertError) {
                                // إذا فشل لأن product_name غير موجود، نجرب بدون product_name
                                if (stripos($insertError->getMessage(), 'product_name') !== false || 
                                    stripos($insertError->getMessage(), 'Unknown column') !== false) {
                                    try {
                                        $db->execute(
                                            "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, quantity, unit_price, total_price) 
                                             VALUES (?, NULL, NULL, ?, ?, ?)",
                                            [
                                                $orderId,
                                                $item['quantity'],
                                                $item['unit_price'],
                                                $item['total_price']
                                            ]
                                        );
                                    } catch (Throwable $insertError2) {
                                        // إذا فشل لأن template_id غير موجود، نجرب بدون template_id
                                        if (stripos($insertError2->getMessage(), 'template_id') !== false || 
                                            stripos($insertError2->getMessage(), 'Unknown column') !== false) {
                                            $db->execute(
                                                "INSERT INTO {$orderItemsTable} (order_id, product_id, quantity, unit_price, total_price) 
                                                 VALUES (?, NULL, ?, ?, ?)",
                                                [
                                                    $orderId,
                                                    $item['quantity'],
                                                    $item['unit_price'],
                                                    $item['total_price']
                                                ]
                                            );
                                        } else {
                                            throw $insertError2;
                                        }
                                    }
                                } else {
                                    throw $insertError;
                                }
                            }
                        }
                    } catch (Throwable $insertError) {
                        error_log('Error inserting order item: ' . $insertError->getMessage());
                        throw $insertError;
                    }
                }

                $db->commit();
                $transactionStarted = false;

                if ($newCustomerCreated) {
                    logAudit($currentUser['id'], 'create_customer_from_order', 'customer', $customerId, null, [
                        'name' => $newCustomerName,
                        'phone' => $newCustomerPhone,
                        'address' => $newCustomerAddress
                    ]);
                }

                notifyManagers(
                    'طلب عميل جديد',
                    "تم إنشاء طلب جديد رقم {$orderNumber} للعميل",
                    'info',
                    "dashboard/sales.php?page=orders&id={$orderId}"
                );

                // إرسال إشعار للمندوب إذا كان الطلب من المدير أو المحاسب
                if ($isManagerOrAccountant && $salesRepId && $salesRepId > 0) {
                    $salesRep = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$salesRepId]);
                    $salesRepName = $salesRep['full_name'] ?? $salesRep['username'] ?? 'المندوب';
                    
                    createNotification(
                        $salesRepId,
                        'طلب جديد',
                        "تم إنشاء طلب جديد رقم {$orderNumber} لك من قبل " . ($currentUser['full_name'] ?? $currentUser['username'] ?? 'الإدارة'),
                        'info',
                        getRelativeUrl("dashboard/sales.php?page=orders&id={$orderId}"),
                        true // إرسال Telegram
                    );
                }

                logAudit($currentUser['id'], 'create_order', 'customer_order', $orderId, null, [
                    'order_number' => $orderNumber,
                    'total_amount' => $totalAmount
                ]);

                // تطبيق PRG pattern لمنع التكرار
                $successMessage = 'تم إنشاء الطلب بنجاح: ' . $orderNumber;
                preventDuplicateSubmission($successMessage, ['page' => 'orders'], null, $currentUser['role']);
            } catch (Throwable $createOrderError) {
                if ($transactionStarted) {
                    try {
                        $db->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log('Rollback error: ' . $rollbackError->getMessage());
                    }
                }
                error_log('Create order error: ' . $createOrderError->getMessage());
                error_log('Create order error trace: ' . $createOrderError->getTraceAsString());
                error_log('Create order POST data: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
                $error = 'حدث خطأ أثناء إنشاء الطلب: ' . $createOrderError->getMessage();
            }
        }
    } elseif ($action === 'create_company_order' && $isManagerOrAccountant) {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $deliveryDate = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
        $priority = $_POST['priority'] ?? 'normal';
        $notes = '';
        $createNewCustomer = isset($_POST['create_new_customer']) && $_POST['create_new_customer'] === '1';
        $newCustomerName = trim($_POST['new_customer_name'] ?? '');
        $newCustomerPhone = trim($_POST['new_customer_phone'] ?? '');
        $newCustomerAddress = trim($_POST['new_customer_address'] ?? '');
        $newCustomerLatitude = isset($_POST['new_customer_latitude']) && $_POST['new_customer_latitude'] !== '' ? trim($_POST['new_customer_latitude']) : null;
        $newCustomerLongitude = isset($_POST['new_customer_longitude']) && $_POST['new_customer_longitude'] !== '' ? trim($_POST['new_customer_longitude']) : null;
        
        // معالجة العناصر - الآن template_name بدلاً من product_id
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $templateName = trim($item['template_name'] ?? '');
                $quantity = floatval($item['quantity'] ?? 0);
                if (!empty($templateName) && $quantity > 0) {
                    $items[] = [
                        'template_name' => $templateName,
                        'quantity' => $quantity,
                        'unit_price' => 0.0,
                        'total_price' => 0.0
                    ];
                }
            }
        }
        
        if (empty($items)) {
            $error = 'يجب إضافة عنصر واحد على الأقل للطلب.';
        } elseif (!$createNewCustomer && $customerId <= 0) {
            $error = 'يجب اختيار العميل أو تحديد خيار العميل الجديد.';
        } elseif ($createNewCustomer && $newCustomerName === '') {
            $error = 'اسم العميل الجديد مطلوب.';
        } else {
            $transactionStarted = false;
            $orderNumber = '';
            $orderId = null;
            $totalAmount = 0.0;
            $newCustomerCreated = false;

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                if ($createNewCustomer) {
                    // إنشاء عميل جديد دائماً، حتى لو كان هناك عميل بنفس الاسم أو رقم الهاتف
                    // هذا يضمن أن كل عميل شركة يتم إنشاؤه بشكل منفصل
                    // إنشاء عميل جديد للشركة (بدون مندوب)
                        // التحقق من وجود أعمدة اللوكيشن
                        $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
                        $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
                        $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
                        
                        // توليد unique_code فريد للعميل
                        require_once __DIR__ . '/../../includes/customer_code_generator.php';
                        ensureCustomerUniqueCodeColumn('customers');
                        $uniqueCode = generateUniqueCustomerCode('customers');
                        
                        $customerColumns = ['unique_code', 'name', 'phone', 'address', 'balance', 'status', 'created_by', 'rep_id', 'created_from_pos', 'created_by_admin'];
                        $customerValues = [
                            $uniqueCode,
                            $newCustomerName,
                            $newCustomerPhone !== '' ? $newCustomerPhone : null,
                            $newCustomerAddress !== '' ? $newCustomerAddress : null,
                            0,
                            'active',
                            $currentUser['id'],
                            null,
                            0,
                            1
                        ];
                        $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?'];
                        
                        if ($hasLatitudeColumn && $newCustomerLatitude !== null) {
                            $customerColumns[] = 'latitude';
                            $customerValues[] = (float)$newCustomerLatitude;
                            $customerPlaceholders[] = '?';
                        }
                        
                        if ($hasLongitudeColumn && $newCustomerLongitude !== null) {
                            $customerColumns[] = 'longitude';
                            $customerValues[] = (float)$newCustomerLongitude;
                            $customerPlaceholders[] = '?';
                        }
                        
                        if ($hasLocationCapturedAtColumn && $newCustomerLatitude !== null && $newCustomerLongitude !== null) {
                            $customerColumns[] = 'location_captured_at';
                            $customerValues[] = date('Y-m-d H:i:s');
                            $customerPlaceholders[] = '?';
                        }
                        
                        $db->execute(
                            "INSERT INTO customers (" . implode(', ', $customerColumns) . ") 
                             VALUES (" . implode(', ', $customerPlaceholders) . ")",
                            $customerValues
                        );
                        $customerId = (int)$db->getLastInsertId();
                        $newCustomerCreated = true;
                }

                if ($customerId <= 0) {
                    throw new RuntimeException('تعذر تحديد العميل المرتبط بالطلب.');
                }

                $year = date('Y');
                $month = date('m');
                $lastOrder = $db->queryOne(
                    "SELECT order_number FROM customer_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1",
                    ["CMP-{$year}{$month}-%"]
                );

                $serial = 1;
                if ($lastOrder) {
                    $parts = explode('-', $lastOrder['order_number']);
                    $serial = intval($parts[2] ?? 0) + 1;
                }
                $orderNumber = sprintf("CMP-%s%s-%04d", $year, $month, $serial);

                $subtotal = 0.0;
                $discountAmount = 0.0;
                $totalAmount = 0.0;

                // إنشاء طلب الشركة (بدون sales_rep_id و order_type = 'company')
                $db->execute(
                    "INSERT INTO customer_orders 
                    (order_number, customer_id, sales_rep_id, order_type, order_date, delivery_date, 
                     subtotal, discount_amount, total_amount, priority, notes, created_by, status) 
                    VALUES (?, ?, NULL, 'company', ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [
                        $orderNumber,
                        $customerId,
                        $orderDate,
                        $deliveryDate,
                        $subtotal,
                        $discountAmount,
                        $totalAmount,
                        $priority,
                        $notes,
                        $currentUser['id']
                    ]
                );

                $orderId = (int)$db->getLastInsertId();

                if ($orderId <= 0) {
                    throw new RuntimeException('فشل إنشاء الطلب: لم يتم الحصول على معرف الطلب.');
                }

                // إضافة العناصر
                $orderItemsTable = getOrderItemsTableName($db);
                
                // التحقق من وجود عمود product_name وإضافته إذا لم يكن موجوداً
                try {
                    $productNameColumn = $db->queryOne("SHOW COLUMNS FROM {$orderItemsTable} LIKE 'product_name'");
                    if (empty($productNameColumn)) {
                        $db->execute("ALTER TABLE {$orderItemsTable} ADD COLUMN product_name VARCHAR(255) NULL AFTER template_id");
                    }
                } catch (Throwable $alterError) {
                    error_log('Error checking/adding product_name column: ' . $alterError->getMessage());
                }
                
                foreach ($items as $item) {
                    $templateName = $item['template_name'];
                    
                    // البحث عن القالب بالاسم في unified_product_templates أولاً
                    $templateId = null;
                    $unifiedCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                    if (!empty($unifiedCheck)) {
                        $template = $db->queryOne(
                            "SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                            [$templateName, $templateName]
                        );
                        if ($template) {
                            $templateId = (int)$template['id'];
                        }
                    }
                    
                    // إذا لم يُعثر عليه، البحث في product_templates
                    if (!$templateId) {
                        $productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                        if (!empty($productTemplatesCheck)) {
                            $template = $db->queryOne(
                                "SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                                [$templateName, $templateName]
                            );
                            if ($template) {
                                $templateId = (int)$template['id'];
                            }
                        }
                    }
                    
                    // إدراج العنصر - حفظ الاسم في product_name إذا لم يُعثر على template_id
                    try {
                        if ($templateId) {
                            try {
                                $db->execute(
                                    "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, product_name, quantity, unit_price, total_price) 
                                     VALUES (?, NULL, ?, NULL, ?, ?, ?)",
                                    [
                                        $orderId,
                                        $templateId,
                                        $item['quantity'],
                                        $item['unit_price'],
                                        $item['total_price']
                                    ]
                                );
                            } catch (Throwable $insertError) {
                                // إذا فشل لأن product_name غير موجود، نجرب بدون product_name
                                if (stripos($insertError->getMessage(), 'product_name') !== false || 
                                    stripos($insertError->getMessage(), 'Unknown column') !== false) {
                                    $db->execute(
                                        "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, quantity, unit_price, total_price) 
                                         VALUES (?, NULL, ?, ?, ?, ?)",
                                        [
                                            $orderId,
                                            $templateId,
                                            $item['quantity'],
                                            $item['unit_price'],
                                            $item['total_price']
                                        ]
                                    );
                                } else {
                                    throw $insertError;
                                }
                            }
                        } else {
                            // إذا لم يُعثر على القالب، حفظ الاسم في product_name
                            try {
                                $db->execute(
                                    "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, product_name, quantity, unit_price, total_price) 
                                     VALUES (?, NULL, NULL, ?, ?, ?, ?)",
                                    [
                                        $orderId,
                                        $templateName,
                                        $item['quantity'],
                                        $item['unit_price'],
                                        $item['total_price']
                                    ]
                                );
                            } catch (Throwable $insertError) {
                                // إذا فشل لأن product_name غير موجود، نجرب بدون product_name
                                if (stripos($insertError->getMessage(), 'product_name') !== false || 
                                    stripos($insertError->getMessage(), 'Unknown column') !== false) {
                                    try {
                                        $db->execute(
                                            "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, quantity, unit_price, total_price) 
                                             VALUES (?, NULL, NULL, ?, ?, ?)",
                                            [
                                                $orderId,
                                                $item['quantity'],
                                                $item['unit_price'],
                                                $item['total_price']
                                            ]
                                        );
                                    } catch (Throwable $insertError2) {
                                        // إذا فشل لأن template_id غير موجود، نجرب بدون template_id
                                        if (stripos($insertError2->getMessage(), 'template_id') !== false || 
                                            stripos($insertError2->getMessage(), 'Unknown column') !== false) {
                                            $db->execute(
                                                "INSERT INTO {$orderItemsTable} (order_id, product_id, quantity, unit_price, total_price) 
                                                 VALUES (?, NULL, ?, ?, ?)",
                                                [
                                                    $orderId,
                                                    $item['quantity'],
                                                    $item['unit_price'],
                                                    $item['total_price']
                                                ]
                                            );
                                        } else {
                                            throw $insertError2;
                                        }
                                    }
                                } else {
                                    throw $insertError;
                                }
                            }
                        }
                    } catch (Throwable $insertError) {
                        error_log('Error inserting company order item: ' . $insertError->getMessage());
                        throw $insertError;
                    }
                }

                if ($newCustomerCreated) {
                    logAudit($currentUser['id'], 'create_customer_from_order', 'customer', $customerId, null, [
                        'name' => $newCustomerName,
                        'phone' => $newCustomerPhone,
                        'address' => $newCustomerAddress,
                        'order_type' => 'company'
                    ]);
                }

                logAudit($currentUser['id'], 'create_company_order', 'customer_order', $orderId, null, [
                    'order_number' => $orderNumber,
                    'total_amount' => $totalAmount
                ]);

                // ملاحظة: طلبات الشركة لا ترتبط بمندوب محدد، لذلك لا نرسل إشعار لمندوب معين
                // إذا كان هناك مندوب مرتبط بالعميل، يمكن إرسال إشعار له
                $customer = $db->queryOne("SELECT rep_id, created_by FROM customers WHERE id = ?", [$customerId]);
                if ($customer && ($customer['rep_id'] || $customer['created_by'])) {
                    $repId = $customer['rep_id'] ?: $customer['created_by'];
                    if ($repId && $repId > 0) {
                        $rep = $db->queryOne("SELECT id, full_name, username FROM users WHERE id = ? AND role = 'sales'", [$repId]);
                        if ($rep) {
                            createNotification(
                                $repId,
                                'طلب شركة جديد',
                                "تم إنشاء طلب شركة جديد رقم {$orderNumber} للعميل المرتبط بك من قبل " . ($currentUser['full_name'] ?? $currentUser['username'] ?? 'الإدارة'),
                                'info',
                                getRelativeUrl("dashboard/sales.php?page=orders&id={$orderId}"),
                                true // إرسال Telegram
                            );
                        }
                    }
                }

                $db->commit();
                $transactionStarted = false;
                $success = 'تم إنشاء طلب الشركة بنجاح: ' . $orderNumber;
                preventDuplicateSubmission($success, ['page' => 'orders'], null, $currentUser['role']);
            } catch (Throwable $createOrderError) {
                if ($transactionStarted) {
                    try {
                        $db->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log('Rollback error: ' . $rollbackError->getMessage());
                    }
                }
                error_log('Create company order error: ' . $createOrderError->getMessage());
                $error = 'حدث خطأ أثناء إنشاء طلب الشركة: ' . $createOrderError->getMessage();
            }
        }
    } elseif ($action === 'update_status') {
        $orderId = intval($_POST['order_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($orderId > 0 && !empty($status)) {
            // التحقق من صلاحيات المندوب
            if ($isSalesUser) {
                // المندوب يمكنه فقط تغيير الحالة إلى "تم التسليم" أو "ملغى"
                $allowedStatuses = ['delivered', 'cancelled'];
                if (!in_array($status, $allowedStatuses, true)) {
                    $error = 'غير مصرح لك بتغيير الحالة إلى: ' . $status;
                } else {
                    // التحقق من أن الطلب يخص هذا المندوب
                    $order = $db->queryOne(
                        "SELECT id, sales_rep_id FROM customer_orders WHERE id = ?",
                        [$orderId]
                    );
                    
                    if (!$order) {
                        $error = 'الطلب غير موجود.';
                    } elseif ((int)($order['sales_rep_id'] ?? 0) !== (int)$currentUser['id']) {
                        $error = 'غير مصرح لك بتعديل هذا الطلب.';
                    } else {
                        $oldOrder = $db->queryOne("SELECT status FROM customer_orders WHERE id = ?", [$orderId]);
                        
                        $db->execute(
                            "UPDATE customer_orders SET status = ?, updated_at = NOW() WHERE id = ?",
                            [$status, $orderId]
                        );
                        
                        logAudit($currentUser['id'], 'update_order_status', 'customer_order', $orderId, 
                                 ['old_status' => $oldOrder['status']], 
                                 ['new_status' => $status]);
                        
                        $success = 'تم تحديث حالة الطلب بنجاح';
                    }
                }
            } else {
                // المدير والمحاسب يمكنهم تغيير الحالة إلى أي حالة
                $oldOrder = $db->queryOne("SELECT status FROM customer_orders WHERE id = ?", [$orderId]);
                
                $db->execute(
                    "UPDATE customer_orders SET status = ?, updated_at = NOW() WHERE id = ?",
                    [$status, $orderId]
                );
                
                logAudit($currentUser['id'], 'update_order_status', 'customer_order', $orderId, 
                         ['old_status' => $oldOrder['status']], 
                         ['new_status' => $status]);
                
                $success = 'تم تحديث حالة الطلب بنجاح';
            }
        }
    }
}

// إذا كان المستخدم مندوب مبيعات، عرض فقط طلباته (ولا تظهر طلبات الشركة)
if ($isSalesUser) {
    $filters['sales_rep_id'] = $currentUser['id'];
}

// الحصول على الطلبات
$sql = "SELECT o.*, c.name as customer_name, u.full_name as sales_rep_name
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u ON o.sales_rep_id = u.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM customer_orders WHERE 1=1";
$params = [];

// إخفاء طلبات الشركة عن المناديب (فقط المدير والمحاسب يرونها)
if ($isSalesUser) {
    $sql .= " AND (o.order_type IS NULL OR o.order_type = 'sales_rep')";
    $countSql .= " AND (order_type IS NULL OR order_type = 'sales_rep')";
}

if (!empty($filters['customer_id'])) {
    $sql .= " AND o.customer_id = ?";
    $countSql .= " AND customer_id = ?";
    $params[] = $filters['customer_id'];
}

if (!empty($filters['sales_rep_id'])) {
    $sql .= " AND o.sales_rep_id = ?";
    $countSql .= " AND sales_rep_id = ?";
    $params[] = $filters['sales_rep_id'];
}

if (!empty($filters['order_number'])) {
    $sql .= " AND o.order_number LIKE ?";
    $countSql .= " AND order_number LIKE ?";
    $params[] = "%{$filters['order_number']}%";
}

if (!empty($filters['status'])) {
    $sql .= " AND o.status = ?";
    $countSql .= " AND status = ?";
    $params[] = $filters['status'];
}

if (!empty($filters['priority'])) {
    $sql .= " AND o.priority = ?";
    $countSql .= " AND priority = ?";
    $params[] = $filters['priority'];
}

if (!empty($filters['date_from'])) {
    $sql .= " AND DATE(o.order_date) >= ?";
    $countSql .= " AND DATE(order_date) >= ?";
    $params[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $sql .= " AND DATE(o.order_date) <= ?";
    $countSql .= " AND DATE(order_date) <= ?";
    $params[] = $filters['date_to'];
}

$totalOrders = $db->queryOne($countSql, $params);
$totalOrders = $totalOrders['total'] ?? 0;
$totalPages = ceil($totalOrders / $perPage);

$sql .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$orders = $db->query($sql, $params);

// جلب عملاء الشركة (الذين ليس لديهم مندوب أو تم إنشاؤهم بواسطة المدير)
$companyCustomers = [];
try {
    // التحقق من وجود عمود created_by_admin
    $hasCreatedByAdmin = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'created_by_admin'"));
    $hasRepId = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'rep_id'"));
    
    if ($hasCreatedByAdmin && $hasRepId) {
        $companyCustomers = $db->query(
            "SELECT id, name FROM customers 
             WHERE status = 'active' 
             AND (created_by_admin = 1 OR rep_id IS NULL OR created_by IN (SELECT id FROM users WHERE role IN ('manager', 'accountant')))
             ORDER BY name"
        );
    } else {
        // إذا لم تكن الأعمدة موجودة، جلب جميع العملاء النشطين
        $companyCustomers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");
    }
} catch (Throwable $e) {
    error_log('Error fetching company customers: ' . $e->getMessage());
    $companyCustomers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");
}

$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");

// جلب القوالب من unified_product_templates و product_templates
$templates = [];
$unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
if (!empty($unifiedTemplatesCheck)) {
    $unifiedTemplates = $db->query("
        SELECT id, 
               COALESCE(product_name, CONCAT('قالب #', id)) as name,
               0 as unit_price
        FROM unified_product_templates 
        WHERE status = 'active' 
        ORDER BY name
    ");
    $templates = array_merge($templates, $unifiedTemplates);
}

$productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
if (!empty($productTemplatesCheck)) {
    $productTemplates = $db->query("
        SELECT id, 
               COALESCE(product_name, CONCAT('قالب #', id)) as name,
               0 as unit_price
        FROM product_templates 
        WHERE status = 'active' 
        ORDER BY name
    ");
    $templates = array_merge($templates, $productTemplates);
}

// جلب قوالب المنتجات من product_templates فقط (للـ dropdown)
$productTemplatesForDropdown = [];
if (!empty($productTemplatesCheck)) {
    $productTemplatesForDropdown = $db->query("
        SELECT * FROM product_templates 
        ORDER BY product_templates.product_name ASC
    ");
}

// استخدام القوالب بدلاً من المنتجات
$products = $templates;
$salesReps = $db->query("SELECT id, username, full_name FROM users WHERE role = 'sales' AND status = 'active' ORDER BY username");
$canSendOrderToRep = !empty($salesReps) && !empty($products);

// طلب محدد للعرض
$selectedOrder = null;
if (isset($_GET['id'])) {
    $orderId = intval($_GET['id']);
    $selectedOrder = $db->queryOne(
        "SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
                u.full_name as sales_rep_name
         FROM customer_orders o
         LEFT JOIN customers c ON o.customer_id = c.id
         LEFT JOIN users u ON o.sales_rep_id = u.id
         WHERE o.id = ?",
        [$orderId]
    );
    
    if ($selectedOrder) {
        $orderItemsTable = getOrderItemsTableName($db);
        
        // جلب العناصر مع اسم القالب
        $items = $db->query(
            "SELECT oi.*, oi.template_id
             FROM {$orderItemsTable} oi
             WHERE oi.order_id = ?
             ORDER BY oi.id",
            [$orderId]
        );
        
        // جلب أسماء القوالب والمنتجات
        foreach ($items as &$item) {
            $productName = '-';
            
            // أولاً: إذا كان product_name محفوظاً مباشرة في الجدول، استخدمه
            if (!empty($item['product_name'])) {
                $productName = $item['product_name'];
            }
            // ثانياً: البحث عن اسم القالب إذا كان template_id موجوداً
            elseif (!empty($item['template_id'])) {
                // البحث في unified_product_templates
                $unifiedCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                if (!empty($unifiedCheck)) {
                    $template = $db->queryOne(
                        "SELECT COALESCE(product_name, CONCAT('قالب #', id)) as name 
                         FROM unified_product_templates 
                         WHERE id = ?",
                        [$item['template_id']]
                    );
                    if ($template) {
                        $productName = $template['name'];
                    }
                }
                
                // إذا لم يُعثر عليه، البحث في product_templates
                if ($productName === '-') {
                    $productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                    if (!empty($productTemplatesCheck)) {
                        $template = $db->queryOne(
                            "SELECT COALESCE(product_name, CONCAT('قالب #', id)) as name 
                             FROM product_templates 
                             WHERE id = ?",
                            [$item['template_id']]
                        );
                        if ($template) {
                            $productName = $template['name'];
                        }
                    }
                }
            }
            // ثالثاً: إذا لم يُعثر على اسم من القالب، البحث عن المنتج إذا كان product_id موجوداً
            elseif (!empty($item['product_id'])) {
                $product = $db->queryOne(
                    "SELECT name FROM products WHERE id = ?",
                    [$item['product_id']]
                );
                if ($product) {
                    $productName = $product['name'];
                }
            }
            
            $item['product_name'] = $productName;
            // التأكد من وجود production_status
            if (!isset($item['production_status'])) {
                $item['production_status'] = 'pending';
            }
        }
        unset($item);
        
        $selectedOrder['items'] = $items;
    }
}
?>

<!-- Responsive Modals CSS - يجب أن يكون في البداية قبل أي محتوى -->
<link rel="stylesheet" href="<?php echo getRelativeUrl('assets/css/responsive-modals.css'); ?>">

<style>
/* ===== تصميم احترافي لعرض تفاصيل الطلب ===== */
.order-details-card {
    border: none;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.order-details-card .card-header {
    background: linear-gradient(135deg, rgba(29, 51, 150, 1) 0%, rgba(20, 57, 204, 1) 100%);
    border: none;
    padding: 1rem 1.5rem;
}

.order-details-card .card-body {
    padding: 1.5rem;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e2e8f0;
}

.order-info-section {
    background: #e3f2fd;
    border-radius: 8px;
    padding: 1.25rem;
    height: 100%;
    border: 1px solid #90caf9;
}

.order-status-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1.25rem;
    height: 100%;
}

.info-grid,
.status-grid {
    display: grid;
    gap: 0.75rem;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e9ecef;
}

.info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 500;
    display: flex;
    align-items: center;
}

.info-value {
    font-size: 0.95rem;
    color: #2d3748;
    font-weight: 600;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    background: #fff;
    border-radius: 6px;
    margin-bottom: 0.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.status-item:last-child {
    margin-bottom: 0;
}

.status-label {
    font-size: 0.9rem;
    color: #495057;
    font-weight: 500;
}

.status-badge,
.priority-badge {
    font-size: 0.85rem;
    padding: 0.4rem 0.75rem;
    font-weight: 600;
}

/* ===== جدول عناصر الطلب الاحترافي ===== */
.order-items-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 2px solid #e2e8f0;
}

.order-items-table-wrapper {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.order-items-table {
    margin: 0;
    background: #fff;
}

.order-items-table thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
}

.order-items-table thead th {
    border: none;
    padding: 1rem;
    font-weight: 600;
    font-size: 0.9rem;
    text-align: right;
}

.order-items-table tbody tr {
    transition: all 0.2s ease;
    border-bottom: 1px solid #e9ecef;
}

.order-items-table tbody tr:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.order-items-table tbody td {
    padding: 1rem;
    vertical-align: middle;
    border: none;
}

.quantity-badge {
    display: inline-block;
    background: #e3f2fd;
    color: #1976d2;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.9rem;
}

.production-status-badge {
    font-size: 0.85rem;
    padding: 0.4rem 0.75rem;
    font-weight: 600;
}

.order-notes-section {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 8px;
    padding: 1rem;
}

.notes-content {
    color: #856404;
    line-height: 1.6;
    margin: 0;
}

/* ===== Responsive Design للهواتف ===== */
@media (max-width: 767.98px) {
    .order-details-card .card-header {
        padding: 0.75rem 1rem;
    }
    
    .order-details-card .card-body {
        padding: 1rem;
    }
    
    .section-title {
        font-size: 1rem;
        margin-bottom: 0.75rem;
    }
    
    .order-info-section,
    .order-status-section {
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .info-item {
        padding: 0.4rem 0;
    }
    
    .info-label {
        font-size: 0.8rem;
    }
    
    .info-value {
        font-size: 0.9rem;
    }
    
    .status-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
        padding: 0.6rem;
    }
    
    /* جدول عناصر الطلب على الموبايل */
    .order-items-table-wrapper {
        border-radius: 6px;
    }
    
    .order-items-table thead {
        display: none;
    }
    
    .order-items-table tbody {
        display: block;
    }
    
    .order-items-table tbody tr {
        display: block;
        margin-bottom: 0.75rem;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 0.75rem;
        background: #fff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
    }
    
    .order-items-table tbody tr:last-child {
        margin-bottom: 0;
    }
    
    .order-items-table tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid #f0f0f0;
        text-align: right;
    }
    
    .order-items-table tbody td:last-child {
        border-bottom: none;
    }
    
    .order-items-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #495057;
        font-size: 0.85rem;
        margin-left: 0.5rem;
    }
    
    .order-items-table tbody td:first-child::before {
        content: "رقم:";
    }
    
    .order-items-table tbody td:nth-child(2)::before {
        content: "المنتج:";
    }
    
    .order-items-table tbody td:nth-child(3)::before {
        content: "الكمية:";
    }
    
    .order-items-table tbody td:nth-child(4)::before {
        content: "حالة الإنتاج:";
    }
    
    .order-items-table tbody td:first-child {
        font-weight: 600;
        color: #6c757d;
    }
    
    .quantity-badge {
        font-size: 0.85rem;
        padding: 0.3rem 0.6rem;
    }
    
    .production-status-badge {
        font-size: 0.8rem;
        padding: 0.35rem 0.6rem;
    }
}

/* ===== تحسينات للشاشات الصغيرة جداً ===== */
@media (max-width: 576px) {
    .order-details-card .card-header h5 {
        font-size: 0.95rem;
    }
    
    .section-title {
        font-size: 0.95rem;
    }
    
    .info-label,
    .status-label {
        font-size: 0.75rem;
    }
    
    .info-value {
        font-size: 0.85rem;
    }
    
    .order-items-table tbody td {
        font-size: 0.85rem;
    }
}

/* ===== تحسينات عرض الفلترة على الهواتف الصغيرة ===== */
@media (max-width: 767.98px) {
    /* تقليل المسافات بين الحقول على الهواتف */
    .card-body form.row {
        margin-bottom: 0;
    }
    
    /* تحسين حجم الخطوط في التسميات */
    .card-body .form-label.small {
        font-size: 0.8rem;
        margin-bottom: 0.25rem;
        font-weight: 500;
    }
    
    /* تحسين حجم حقول الإدخال */
    .card-body .form-control-sm,
    .card-body .form-select-sm {
        font-size: 0.85rem;
        padding: 0.4rem 0.5rem;
        height: auto;
        min-height: 38px;
    }
    
    /* تحسين الأزرار */
    .card-body .btn-sm {
        font-size: 0.85rem;
        padding: 0.4rem 0.75rem;
        min-height: 38px;
    }
    
    /* تقليل المسافات بين الصفوف */
    .card-body .row.g-2 {
        --bs-gutter-y: 0.5rem;
    }
    
    /* تحسين عرض الحقول على الشاشات الصغيرة جداً */
    @media (max-width: 400px) {
        .card-body .col-6 {
            flex: 0 0 auto;
            width: 50%;
        }
        
        .card-body .form-label.small {
            font-size: 0.75rem;
        }
        
        .card-body .form-control-sm,
        .card-body .form-select-sm {
            font-size: 0.8rem;
            padding: 0.35rem 0.45rem;
        }
    }
}

/* تحسينات إضافية للشاشات المتوسطة */
@media (min-width: 768px) and (max-width: 991.98px) {
    .card-body .form-label.small {
        font-size: 0.85rem;
    }
}

    
    /* تحسين النموذج في Modal */
    .modal-xl .modal-body .row > div {
        margin-bottom: 1rem;
    }
    
    .modal-xl .modal-body .form-label {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }
    
    .modal-xl .modal-body .form-control,
    .modal-xl .modal-body .form-select {
        font-size: 0.9rem;
        padding: 0.5rem;
    }
}

/* تحسينات إضافية لجدول تفاصيل الطلب على جميع الشاشات - محكم داخل الإطار */
.dashboard-table-details {
    margin: 0 !important;
    padding: 0 !important;
    border-spacing: 0 !important;
    border-collapse: collapse !important;
    width: 100% !important;
    max-width: 100% !important;
    table-layout: fixed !important;
    overflow-x: hidden !important;
}

.dashboard-table-details tr {
    border-bottom: 1px solid #e9ecef;
    margin-bottom: 0 !important;
    margin-top: 0 !important;
    padding: 0 !important;
}

.dashboard-table-details tr:first-child {
    padding-top: 0 !important;
    margin-top: 0 !important;
}

.dashboard-table-details tr:last-child {
    border-bottom: none;
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
}

.dashboard-table-details th {
    padding: 0.25rem 0.5rem 0.25rem 0 !important;
    font-weight: 600;
    color: #495057;
    white-space: nowrap;
    margin: 0 !important;
    border: none !important;
    max-width: 45%;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dashboard-table-details td {
    padding: 0.25rem 0.5rem 0.25rem 0 !important;
    word-break: break-word;
    margin: 0 !important;
    border: none !important;
}

/* تحسينات إضافية لجدول عناصر الطلب على جميع الشاشات - تقليل المسافات */
.dashboard-table--compact {
    margin: 0 !important;
    padding: 0 !important;
    border-spacing: 0 !important;
    border-collapse: collapse !important;
}

.dashboard-table--compact thead {
    margin: 0 !important;
    padding: 0 !important;
}

.dashboard-table--compact thead tr {
    margin: 0 !important;
    padding: 0 !important;
}

.dashboard-table--compact thead th {
    padding: 0.4rem 0.5rem !important;
    margin: 0 !important;
}

.dashboard-table--compact tbody {
    margin: 0 !important;
    padding: 0 !important;
}

.dashboard-table--compact tbody tr {
    margin-bottom: 0.25rem !important;
    margin-top: 0 !important;
}

.dashboard-table--compact tbody tr:first-child {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

.dashboard-table--compact tbody tr:last-child {
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
}

.dashboard-table--compact tbody td {
    padding: 0.15rem 0 !important;
    margin: 0 !important;
    border: none !important;
}

.dashboard-table--compact tbody td:first-child {
    padding-top: 0.15rem !important;
    margin-top: 0 !important;
}

.dashboard-table--compact tbody td:last-child {
    padding-bottom: 0.15rem !important;
    margin-bottom: 0 !important;
}

/* إزالة المسافات من dashboard-table-wrapper */
.dashboard-table-wrapper {
    margin: 0 !important;
    padding: 0 !important;
    border: none !important;
    box-shadow: none !important;
}

.dashboard-table-wrapper .table {
    margin: 0 !important;
    padding: 0 !important;
}

/* ===== CSS للـ Modal/Card Dual System ===== */

/* إخفاء Modal على الموبايل */
@media (max-width: 768px) {
    #addOrderModal,
    #addCompanyOrderModal,
    #statusModal {
        display: none !important;
    }
}

/* إخفاء Card على الكمبيوتر */
@media (min-width: 769px) {
    #addOrderCard,
    #addCompanyOrderCard,
    #statusCard {
        display: none !important;
    }
}

/* منع الملفات العامة من التأثير على Modals */
#addOrderModal,
#addCompanyOrderModal,
#statusModal {
    height: auto !important;
    max-height: none !important;
}

#addOrderModal .modal-dialog,
#addCompanyOrderModal .modal-dialog,
#statusModal .modal-dialog {
    display: block !important;
    height: auto !important;
    max-height: none !important;
    margin: 1.75rem auto !important;
}

#addOrderModal .modal-content,
#addCompanyOrderModal .modal-content,
#statusModal .modal-content {
    height: auto !important;
    max-height: none !important;
}

#addOrderModal .modal-body,
#addCompanyOrderModal .modal-body,
#statusModal .modal-body {
    height: auto !important;
    max-height: none !important;
    overflow-y: visible !important;
}
</style>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <h2 class="mb-0"><i class="bi bi-cart-check me-2"></i>إدارة طلبات العملاء</h2>
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary" onclick="showAddOrderModal()">
            <i class="bi bi-plus-circle me-2"></i>طلب عميل مندوب
        </button>
        <?php if ($isManagerOrAccountant): ?>
            <button class="btn btn-success" onclick="showAddCompanyOrderModal()">
                <i class="bi bi-building me-2"></i>طلب عميل شركة
            </button>
        <?php endif; ?>
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

<?php if ($selectedOrder): ?>
    <!-- عرض طلب محدد -->
    <div class="card shadow-sm mb-4 order-details-card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="bi bi-file-earmark-text me-2"></i>
                طلب رقم: <?php echo htmlspecialchars($selectedOrder['order_number']); ?>
            </h5>
            <a href="?page=orders" class="btn btn-light btn-sm">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>
        <div class="card-body">
            <!-- معلومات الطلب -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-6">
                    <div class="order-info-section">
                        <h6 class="section-title">
                            <i class="bi bi-info-circle me-2"></i>معلومات الطلب
                        </h6>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">
                                    <i class="bi bi-person me-1"></i>العميل:
                                </span>
                                <span class="info-value"><?php echo htmlspecialchars($selectedOrder['customer_name'] ?? '-'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">
                                    <i class="bi bi-telephone me-1"></i>رقم العميل:
                                </span>
                                <span class="info-value"><?php echo htmlspecialchars($selectedOrder['customer_phone'] ?? '-'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">
                                    <i class="bi bi-geo-alt me-1"></i>عنوان العميل:
                                </span>
                                <span class="info-value"><?php echo htmlspecialchars($selectedOrder['customer_address'] ?? '-'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">
                                    <i class="bi bi-calendar-event me-1"></i>تاريخ الطلب:
                                </span>
                                <span class="info-value"><?php echo formatDate($selectedOrder['order_date']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">
                                    <i class="bi bi-calendar-check me-1"></i>تاريخ التسليم المطلوب:
                                </span>
                                <span class="info-value"><?php echo $selectedOrder['delivery_date'] ? formatDate($selectedOrder['delivery_date']) : '-'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">
                                    <i class="bi bi-person-badge me-1"></i>مندوب المبيعات:
                                </span>
                                <span class="info-value"><?php echo htmlspecialchars($selectedOrder['sales_rep_name'] ?? '-'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="order-status-section">
                        <h6 class="section-title">
                            <i class="bi bi-clipboard-check me-2"></i>حالة الطلب
                        </h6>
                        <div class="status-grid">
                            <div class="status-item">
                                <span class="status-label">الحالة:</span>
                                <span class="badge status-badge bg-<?php 
                                    echo $selectedOrder['status'] === 'delivered' ? 'success' : 
                                        ($selectedOrder['status'] === 'in_production' ? 'info' : 
                                        ($selectedOrder['status'] === 'cancelled' ? 'danger' : 'warning')); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'pending' => 'معلق',
                                        'confirmed' => 'مؤكد',
                                        'in_production' => 'قيد الإنتاج',
                                        'ready' => 'جاهز',
                                        'delivered' => 'تم التسليم',
                                        'cancelled' => 'ملغى'
                                    ];
                                    echo $statuses[$selectedOrder['status']] ?? $selectedOrder['status'];
                                    ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <span class="status-label">الأولوية:</span>
                                <span class="badge priority-badge bg-<?php 
                                    echo $selectedOrder['priority'] === 'urgent' ? 'danger' : 
                                        ($selectedOrder['priority'] === 'high' ? 'warning' : 
                                        ($selectedOrder['priority'] === 'normal' ? 'info' : 'secondary')); 
                                ?>">
                                    <?php 
                                    $priorities = [
                                        'low' => 'منخفضة',
                                        'normal' => 'عادية',
                                        'high' => 'عالية',
                                        'urgent' => 'عاجلة'
                                    ];
                                    echo $priorities[$selectedOrder['priority']] ?? $selectedOrder['priority'];
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- عناصر الطلب -->
            <?php if (!empty($selectedOrder['items'])): ?>
                <div class="order-items-section">
                    <h6 class="section-title mb-3">
                        <i class="bi bi-list-ul me-2"></i>عناصر الطلب
                    </h6>
                    <div class="table-responsive order-items-table-wrapper">
                        <table class="table table-hover order-items-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>اسم المنتج</th>
                                    <th class="text-center" style="width: 120px;">الكمية</th>
                                    <th class="text-center" style="width: 150px;">حالة الإنتاج</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selectedOrder['items'] as $index => $item): ?>
                                    <tr>
                                        <td class="text-muted" data-label="رقم"><?php echo $index + 1; ?></td>
                                        <td data-label="المنتج">
                                            <strong><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></strong>
                                        </td>
                                        <td class="text-center" data-label="الكمية">
                                            <span class="quantity-badge"><?php echo number_format($item['quantity'], 2); ?></span>
                                        </td>
                                        <td class="text-center" data-label="حالة الإنتاج">
                                            <?php 
                                            $productionStatus = $item['production_status'] ?? 'pending';
                                            $productionStatuses = [
                                                'pending' => 'معلق',
                                                'in_production' => 'قيد الإنتاج',
                                                'completed' => 'مكتمل'
                                            ];
                                            ?>
                                            <span class="badge production-status-badge bg-<?php 
                                                echo $productionStatus === 'completed' ? 'success' : 
                                                    ($productionStatus === 'in_production' ? 'info' : 'warning'); 
                                            ?>">
                                                <?php echo $productionStatuses[$productionStatus] ?? $productionStatus; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- الملاحظات -->
            <?php if (!empty($selectedOrder['notes'])): ?>
                <div class="order-notes-section mt-4">
                    <h6 class="section-title">
                        <i class="bi bi-sticky me-2"></i>ملاحظات
                    </h6>
                    <div class="notes-content">
                        <?php echo nl2br(htmlspecialchars($selectedOrder['notes'])); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 g-md-3">
            <input type="hidden" name="page" value="orders">
            <div class="col-6 col-md-3">
                <label class="form-label small">رقم الطلب</label>
                <input type="text" class="form-control form-control-sm" name="order_number" 
                       value="<?php echo htmlspecialchars($filters['order_number'] ?? ''); ?>" 
                       placeholder="ORD-...">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">العميل</label>
                <select class="form-select form-select-sm" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedCustomerId = isset($filters['customer_id']) ? intval($filters['customer_id']) : 0;
                    $customerValid = isValidSelectValue($selectedCustomerId, $customers, 'id');
                    foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" 
                                <?php echo $customerValid && $selectedCustomerId == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($isManagerOrAccountant): ?>
            <div class="col-6 col-md-2">
                <label class="form-label small">المندوب</label>
                <select class="form-select form-select-sm" name="sales_rep_id">
                    <option value="">جميع المناديب</option>
                    <?php 
                    $selectedRepId = isset($filters['sales_rep_id']) ? intval($filters['sales_rep_id']) : 0;
                    $repValid = isValidSelectValue($selectedRepId, $salesReps, 'id');
                    foreach ($salesReps as $rep): ?>
                        <option value="<?php echo $rep['id']; ?>" <?php echo $repValid && $selectedRepId == $rep['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label small">الحالة</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="confirmed" <?php echo ($filters['status'] ?? '') === 'confirmed' ? 'selected' : ''; ?>>مؤكد</option>
                    <option value="in_production" <?php echo ($filters['status'] ?? '') === 'in_production' ? 'selected' : ''; ?>>قيد الإنتاج</option>
                    <option value="ready" <?php echo ($filters['status'] ?? '') === 'ready' ? 'selected' : ''; ?>>جاهز</option>
                    <option value="delivered" <?php echo ($filters['status'] ?? '') === 'delivered' ? 'selected' : ''; ?>>تم التسليم</option>
                    <option value="cancelled" <?php echo ($filters['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>ملغى</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">الأولوية</label>
                <select class="form-select form-select-sm" name="priority">
                    <option value="">جميع الأولويات</option>
                    <option value="low" <?php echo ($filters['priority'] ?? '') === 'low' ? 'selected' : ''; ?>>منخفضة</option>
                    <option value="normal" <?php echo ($filters['priority'] ?? '') === 'normal' ? 'selected' : ''; ?>>عادية</option>
                    <option value="high" <?php echo ($filters['priority'] ?? '') === 'high' ? 'selected' : ''; ?>>عالية</option>
                    <option value="urgent" <?php echo ($filters['priority'] ?? '') === 'urgent' ? 'selected' : ''; ?>>عاجلة</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">من تاريخ</label>
                <input type="date" class="form-control form-control-sm" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small d-block">&nbsp;</label>
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة الطلبات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة الطلبات (<?php echo $totalOrders; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>العميل</th>
                        <th>تاريخ الطلب</th>
                        <th>تاريخ التسليم</th>
                        <?php if (!$isSalesUser): ?>
                            <th>المندوب</th>
                            <th>النوع</th>
                        <?php endif; ?>
                        <th>الأولوية</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="<?php echo $isSalesUser ? 7 : 9; ?>" class="text-center text-muted">لا توجد طلبات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <a href="?page=orders&id=<?php echo $order['id']; ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($order['customer_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($order['order_date']); ?></td>
                                <td><?php echo $order['delivery_date'] ? formatDate($order['delivery_date']) : '-'; ?></td>
                                <?php if (!$isSalesUser): ?>
                                <td><?php echo htmlspecialchars($order['sales_rep_name'] ?? '-'); ?></td>
                                <td>
                                    <?php if (isset($order['order_type']) && $order['order_type'] === 'company'): ?>
                                        <span class="badge bg-success">شركة</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">مندوب</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $order['priority'] === 'urgent' ? 'danger' : 
                                            ($order['priority'] === 'high' ? 'warning' : 
                                            ($order['priority'] === 'normal' ? 'info' : 'secondary')); 
                                    ?>">
                                        <?php 
                                        $priorities = [
                                            'low' => 'منخفضة',
                                            'normal' => 'عادية',
                                            'high' => 'عالية',
                                            'urgent' => 'عاجلة'
                                        ];
                                        echo $priorities[$order['priority']] ?? $order['priority'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $order['status'] === 'delivered' ? 'success' : 
                                            ($order['status'] === 'in_production' ? 'info' : 
                                            ($order['status'] === 'cancelled' ? 'danger' : 'warning')); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'confirmed' => 'مؤكد',
                                            'in_production' => 'قيد الإنتاج',
                                            'ready' => 'جاهز',
                                            'delivered' => 'تم التسليم',
                                            'cancelled' => 'ملغى'
                                        ];
                                        echo $statuses[$order['status']] ?? $order['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="?page=orders&id=<?php echo $order['id']; ?>" 
                                           class="btn btn-info" title="عرض">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($order['status'] !== 'delivered' && $order['status'] !== 'cancelled'): ?>
                                        <button class="btn btn-warning" 
                                                onclick="showStatusModal(<?php echo $order['id']; ?>, '<?php echo $order['status']; ?>')"
                                                title="تغيير الحالة">
                                            <i class="bi bi-pencil"></i>
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
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=orders&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=orders&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=orders&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=orders&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=orders&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إنشاء طلب (للكمبيوتر فقط) -->
<div class="modal fade d-none d-md-block" id="addOrderModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء طلب جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="orderForm">
                <input type="hidden" name="action" value="create_order">
                <?php if ($isSalesUser): ?>
                    <input type="hidden" name="sales_rep_id" value="<?php echo $currentUser['id']; ?>">
                <?php endif; ?>
                <div class="modal-body">
                    <div class="row mb-3">
                        <?php if (!$isSalesUser): ?>
                        <div class="col-md-3">
                            <label class="form-label">مندوب المبيعات <span class="text-danger">*</span></label>
                            <select class="form-select" name="sales_rep_id" id="salesRepSelect" required>
                                <option value="">اختر مندوب</option>
                                <?php foreach ($salesReps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>" <?php echo $rep['id'] == $currentUser['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-<?php echo $isSalesUser ? '5' : '4'; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">العميل <span class="text-danger">*</span></label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="toggleNewCustomer" name="create_new_customer" value="1">
                                    <label class="form-check-label small" for="toggleNewCustomer">عميل جديد</label>
                                </div>
                            </div>
                            <select class="form-select" name="customer_id" id="existingCustomerSelect" <?php echo $isSalesUser ? '' : 'disabled'; ?> required>
                                <?php if ($isSalesUser): ?>
                                    <option value="">اختر العميل</option>
                                    <?php 
                                    // جلب عملاء المندوب الحالي مباشرة
                                    $currentUserCustomers = $db->query(
                                        "SELECT id, name FROM customers WHERE (created_by = ? OR rep_id = ?) AND status = 'active' ORDER BY name ASC",
                                        [$currentUser['id'], $currentUser['id']]
                                    );
                                    foreach ($currentUserCustomers as $customer): ?>
                                        <option value="<?php echo $customer['id']; ?>">
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">اختر المندوب أولاً</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاريخ الطلب <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاريخ التسليم</label>
                            <input type="date" class="form-control" name="delivery_date">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="normal">عادية</option>
                                <option value="low">منخفضة</option>
                                <option value="high">عالية</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="newCustomerFields" class="row g-3 mb-3 d-none">
                        <div class="col-md-4">
                            <label class="form-label">اسم العميل الجديد <span class="text-danger">*</span></label>
                            <input type="text" class="form-control new-customer-required" name="new_customer_name" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="text" class="form-control" name="new_customer_phone" autocomplete="off" placeholder="مثال: 01234567890">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">عنوان العميل</label>
                            <textarea class="form-control" name="new_customer_address" rows="2" autocomplete="off" placeholder="اكتب العنوان بالتفصيل"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">موقع العميل <span class="text-muted">(اختياري)</span></label>
                            <div class="d-flex gap-2">
                                <input type="text" class="form-control" name="new_customer_latitude" id="newCustomerLatitude" placeholder="خط العرض" readonly>
                                <input type="text" class="form-control" name="new_customer_longitude" id="newCustomerLongitude" placeholder="خط الطول" readonly>
                                <button type="button" class="btn btn-outline-primary" id="getLocationBtn" title="الحصول على الموقع الحالي">
                                    <i class="bi bi-geo-alt"></i>
                                </button>
                            </div>
                            <small class="text-muted">اضغط على زر الموقع للحصول على موقعك الحالي</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر الطلب</label>
                        <div id="orderItems">
                            <div class="order-item row mb-2">
                                <div class="col-md-9">
                                    <select class="form-select template-input" 
                                           name="items[0][template_name]" required>
                                        <option value="">اختر قالب المنتج</option>
                                        <?php foreach ($productTemplatesForDropdown as $template): ?>
                                            <option value="<?php echo htmlspecialchars($template['product_name'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($template['product_name'] ?? 'قالب #' . $template['id']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger w-100 remove-item">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إنشاء طلب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card إنشاء طلب (للموبايل فقط) -->
<div class="card shadow-sm mb-4 d-md-none" id="addOrderCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-plus-circle me-2"></i>إنشاء طلب جديد
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" id="orderCardForm">
            <input type="hidden" name="action" value="create_order">
            <?php if ($isSalesUser): ?>
                <input type="hidden" name="sales_rep_id" value="<?php echo $currentUser['id']; ?>">
            <?php endif; ?>
            <div class="row mb-3">
                <?php if (!$isSalesUser): ?>
                <div class="col-md-3 mb-3">
                    <label class="form-label">مندوب المبيعات <span class="text-danger">*</span></label>
                    <select class="form-select" name="sales_rep_id" id="cardSalesRepSelect" required>
                        <option value="">اختر مندوب</option>
                        <?php foreach ($salesReps as $rep): ?>
                            <option value="<?php echo $rep['id']; ?>" <?php echo $rep['id'] == $currentUser['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-<?php echo $isSalesUser ? '12' : '9'; ?> mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label mb-0">العميل <span class="text-danger">*</span></label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cardToggleNewCustomer" name="create_new_customer" value="1">
                            <label class="form-check-label small" for="cardToggleNewCustomer">عميل جديد</label>
                        </div>
                    </div>
                    <select class="form-select" name="customer_id" id="cardExistingCustomerSelect" <?php echo $isSalesUser ? '' : 'disabled'; ?> required>
                        <?php if ($isSalesUser): ?>
                            <option value="">اختر العميل</option>
                            <?php 
                            // جلب عملاء المندوب الحالي مباشرة
                            $currentUserCustomers = $db->query(
                                "SELECT id, name FROM customers WHERE (created_by = ? OR rep_id = ?) AND status = 'active' ORDER BY name ASC",
                                [$currentUser['id'], $currentUser['id']]
                            );
                            foreach ($currentUserCustomers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="">اختر المندوب أولاً</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">تاريخ الطلب <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">تاريخ التسليم</label>
                    <input type="date" class="form-control" name="delivery_date">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label">الأولوية</label>
                    <select class="form-select" name="priority">
                        <option value="normal">عادية</option>
                        <option value="low">منخفضة</option>
                        <option value="high">عالية</option>
                        <option value="urgent">عاجلة</option>
                    </select>
                </div>
            </div>
            
            <div id="cardNewCustomerFields" class="row g-3 mb-3 d-none">
                <div class="col-md-12 mb-3">
                    <label class="form-label">اسم العميل الجديد <span class="text-danger">*</span></label>
                    <input type="text" class="form-control card-new-customer-required" name="new_customer_name" autocomplete="off">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" class="form-control" name="new_customer_phone" autocomplete="off" placeholder="مثال: 01234567890">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label">عنوان العميل</label>
                    <textarea class="form-control" name="new_customer_address" rows="2" autocomplete="off" placeholder="اكتب العنوان بالتفصيل"></textarea>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">موقع العميل <span class="text-muted">(اختياري)</span></label>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control" name="new_customer_latitude" id="cardNewCustomerLatitude" placeholder="خط العرض" readonly>
                        <input type="text" class="form-control" name="new_customer_longitude" id="cardNewCustomerLongitude" placeholder="خط الطول" readonly>
                        <button type="button" class="btn btn-outline-primary" id="cardGetLocationBtn" title="الحصول على الموقع الحالي">
                            <i class="bi bi-geo-alt"></i>
                        </button>
                    </div>
                    <small class="text-muted">اضغط على زر الموقع للحصول على موقعك الحالي</small>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">عناصر الطلب</label>
                <div id="cardOrderItems">
                    <div class="order-item row mb-2">
                        <div class="col-md-9">
                            <select class="form-select card-template-input" 
                                   name="items[0][template_name]" required>
                                <option value="">اختر قالب المنتج</option>
                                <?php foreach ($productTemplatesForDropdown as $template): ?>
                                    <option value="<?php echo htmlspecialchars($template['product_name'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($template['product_name'] ?? 'قالب #' . $template['id']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" class="form-control quantity" 
                                   name="items[0][quantity]" placeholder="الكمية" required>
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-danger w-100 card-remove-item">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="cardAddItemBtn">
                    <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                </button>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">إنشاء طلب</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddOrderCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal إنشاء طلب شركة (للكمبيوتر فقط) -->
<?php if ($isManagerOrAccountant): ?>
<div class="modal fade d-none d-md-block" id="addCompanyOrderModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-building me-2"></i>إنشاء طلب عميل شركة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="companyOrderForm">
                <input type="hidden" name="action" value="create_company_order">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">العميل <span class="text-danger">*</span></label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="toggleNewCompanyCustomer" name="create_new_customer" value="1">
                                    <label class="form-check-label small" for="toggleNewCompanyCustomer">عميل جديد</label>
                                </div>
                            </div>
                            <select class="form-select" name="customer_id" id="companyCustomerSelect" required>
                                <option value="">اختر العميل</option>
                                <?php foreach ($companyCustomers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاريخ الطلب <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاريخ التسليم</label>
                            <input type="date" class="form-control" name="delivery_date">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="normal">عادية</option>
                                <option value="low">منخفضة</option>
                                <option value="high">عالية</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="newCompanyCustomerFields" class="row g-3 mb-3 d-none">
                        <div class="col-md-4">
                            <label class="form-label">اسم العميل الجديد <span class="text-danger">*</span></label>
                            <input type="text" class="form-control new-company-customer-required" name="new_customer_name" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="text" class="form-control" name="new_customer_phone" autocomplete="off" placeholder="مثال: 01234567890">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">عنوان العميل</label>
                            <textarea class="form-control" name="new_customer_address" rows="2" autocomplete="off" placeholder="اكتب العنوان بالتفصيل"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">موقع العميل <span class="text-muted">(اختياري)</span></label>
                            <div class="d-flex gap-2">
                                <input type="text" class="form-control" name="new_customer_latitude" id="companyNewCustomerLatitude" placeholder="خط العرض" readonly>
                                <input type="text" class="form-control" name="new_customer_longitude" id="companyNewCustomerLongitude" placeholder="خط الطول" readonly>
                                <button type="button" class="btn btn-outline-primary" id="companyGetLocationBtn" title="الحصول على الموقع الحالي">
                                    <i class="bi bi-geo-alt"></i>
                                </button>
                            </div>
                            <small class="text-muted">اضغط على زر الموقع للحصول على موقعك الحالي</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر الطلب</label>
                        <div id="companyOrderItems">
                            <div class="order-item row mb-2">
                                <div class="col-md-9">
                                    <select class="form-select template-input" 
                                           name="items[0][template_name]" required>
                                        <option value="">اختر قالب المنتج</option>
                                        <?php foreach ($productTemplatesForDropdown as $template): ?>
                                            <option value="<?php echo htmlspecialchars($template['product_name'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($template['product_name'] ?? 'قالب #' . $template['id']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger w-100 remove-item">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addCompanyItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">إنشاء طلب شركة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card إنشاء طلب شركة (للموبايل فقط) -->
<div class="card shadow-sm mb-4 d-md-none" id="addCompanyOrderCard" style="display: none;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">
            <i class="bi bi-building me-2"></i>إنشاء طلب عميل شركة
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" id="companyOrderCardForm">
            <input type="hidden" name="action" value="create_company_order">
            <div class="row mb-3">
                <div class="col-md-12 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label mb-0">العميل <span class="text-danger">*</span></label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cardToggleNewCompanyCustomer" name="create_new_customer" value="1">
                            <label class="form-check-label small" for="cardToggleNewCompanyCustomer">عميل جديد</label>
                        </div>
                    </div>
                    <select class="form-select" name="customer_id" id="cardCompanyCustomerSelect" required>
                        <option value="">اختر العميل</option>
                        <?php foreach ($companyCustomers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>">
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">تاريخ الطلب <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">تاريخ التسليم</label>
                    <input type="date" class="form-control" name="delivery_date">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label">الأولوية</label>
                    <select class="form-select" name="priority">
                        <option value="normal">عادية</option>
                        <option value="low">منخفضة</option>
                        <option value="high">عالية</option>
                        <option value="urgent">عاجلة</option>
                    </select>
                </div>
            </div>
            
            <div id="cardNewCompanyCustomerFields" class="row g-3 mb-3 d-none">
                <div class="col-md-12 mb-3">
                    <label class="form-label">اسم العميل الجديد <span class="text-danger">*</span></label>
                    <input type="text" class="form-control card-new-company-customer-required" name="new_customer_name" autocomplete="off">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" class="form-control" name="new_customer_phone" autocomplete="off" placeholder="مثال: 01234567890">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label">عنوان العميل</label>
                    <textarea class="form-control" name="new_customer_address" rows="2" autocomplete="off" placeholder="اكتب العنوان بالتفصيل"></textarea>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">موقع العميل <span class="text-muted">(اختياري)</span></label>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control" name="new_customer_latitude" id="cardCompanyNewCustomerLatitude" placeholder="خط العرض" readonly>
                        <input type="text" class="form-control" name="new_customer_longitude" id="cardCompanyNewCustomerLongitude" placeholder="خط الطول" readonly>
                        <button type="button" class="btn btn-outline-primary" id="cardCompanyGetLocationBtn" title="الحصول على الموقع الحالي">
                            <i class="bi bi-geo-alt"></i>
                        </button>
                    </div>
                    <small class="text-muted">اضغط على زر الموقع للحصول على موقعك الحالي</small>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">عناصر الطلب</label>
                <div id="cardCompanyOrderItems">
                    <div class="order-item row mb-2">
                        <div class="col-md-9">
                            <select class="form-select card-company-template-input" 
                                   name="items[0][template_name]" required>
                                <option value="">اختر قالب المنتج</option>
                                <?php foreach ($productTemplatesForDropdown as $template): ?>
                                    <option value="<?php echo htmlspecialchars($template['product_name'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($template['product_name'] ?? 'قالب #' . $template['id']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" class="form-control quantity" 
                                   name="items[0][quantity]" placeholder="الكمية" required>
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-danger w-100 card-company-remove-item">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="cardAddCompanyItemBtn">
                    <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                </button>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">إنشاء طلب شركة</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddCompanyOrderCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal تغيير الحالة (للكمبيوتر فقط) -->
<div class="modal fade d-none d-md-block" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تغيير حالة الطلب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" id="statusOrderId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status" id="statusSelect" required>
                            <?php if ($isSalesUser): ?>
                                <!-- للمندوب: فقط حالتين -->
                                <option value="delivered">تم التسليم</option>
                                <option value="cancelled">ملغى</option>
                            <?php else: ?>
                                <!-- للمدير والمحاسب: جميع الحالات -->
                                <option value="pending">معلق</option>
                                <option value="confirmed">مؤكد</option>
                                <option value="in_production">قيد الإنتاج</option>
                                <option value="ready">جاهز</option>
                                <option value="delivered">تم التسليم</option>
                                <option value="cancelled">ملغى</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card تغيير الحالة (للموبايل فقط) -->
<div class="card shadow-sm mb-4 d-md-none" id="statusCard" style="display: none;">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0">
            <i class="bi bi-pencil me-2"></i>تغيير حالة الطلب
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" id="statusCardOrderId">
            <div class="mb-3">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status" id="statusCardSelect" required>
                    <?php if ($isSalesUser): ?>
                        <!-- للمندوب: فقط حالتين -->
                        <option value="delivered">تم التسليم</option>
                        <option value="cancelled">ملغى</option>
                    <?php else: ?>
                        <!-- للمدير والمحاسب: جميع الحالات -->
                        <option value="pending">معلق</option>
                        <option value="confirmed">مؤكد</option>
                        <option value="in_production">قيد الإنتاج</option>
                        <option value="ready">جاهز</option>
                        <option value="delivered">تم التسليم</option>
                        <option value="cancelled">ملغى</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="closeStatusCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
let itemIndex = 1;

// لم نعد بحاجة إلى productOptions لأننا نستخدم input text الآن

// إضافة عنصر جديد
document.getElementById('addItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('orderItems');
    const newItem = document.createElement('div');
    newItem.className = 'order-item row mb-2';
    const templateOptions = <?php echo json_encode(array_map(function($t) { 
        return ['value' => htmlspecialchars($t['product_name'] ?? '', ENT_QUOTES, 'UTF-8'), 'text' => htmlspecialchars($t['product_name'] ?? 'قالب #' . $t['id'], ENT_QUOTES, 'UTF-8')]; 
    }, $productTemplatesForDropdown), JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
    let optionsHtml = '<option value="">اختر قالب المنتج</option>';
    if (templateOptions && Array.isArray(templateOptions)) {
        templateOptions.forEach(function(template) {
            const value = template.value || '';
            const text = template.text || '';
            optionsHtml += '<option value="' + value.replace(/"/g, '&quot;').replace(/'/g, '&#39;') + '">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
        });
    }
    
    newItem.innerHTML = `
        <div class="col-md-9">
            <select class="form-select template-input" 
                   name="items[${itemIndex}][template_name]" required>
                ${optionsHtml}
            </select>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control quantity" 
                   name="items[${itemIndex}][quantity]" placeholder="الكمية" required>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger w-100 remove-item">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    itemIndex++;
    attachItemEvents(newItem);
});

// حذف عنصر
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        e.target.closest('.order-item').remove();
        calculateOrderTotal();
    }
});

function updateNewCustomerState() {
    const newCustomerToggle = document.getElementById('toggleNewCustomer');
    const existingCustomerSelect = document.getElementById('existingCustomerSelect');
    const newCustomerFields = document.getElementById('newCustomerFields');
    
    if (!newCustomerToggle || !existingCustomerSelect || !newCustomerFields) {
        return false;
    }

    // البحث عن العناصر داخل الـ function لضمان الوصول إليها
    const newCustomerRequiredInputs = Array.from(newCustomerFields.querySelectorAll('.new-customer-required'));
    
    if (newCustomerToggle.checked) {
        // إظهار حقول العميل الجديد
        newCustomerFields.classList.remove('d-none');
        newCustomerFields.style.display = '';
        
        // تعطيل حقل اختيار العميل الموجود
        existingCustomerSelect.value = '';
        existingCustomerSelect.setAttribute('disabled', 'disabled');
        existingCustomerSelect.removeAttribute('required');
        
        // تفعيل الحقول المطلوبة للعميل الجديد
        newCustomerRequiredInputs.forEach(function(input) {
            input.setAttribute('required', 'required');
        });
        return true;
    } else {
        // إخفاء حقول العميل الجديد
        newCustomerFields.classList.add('d-none');
        newCustomerFields.style.display = 'none';
        
        // تفعيل حقل اختيار العميل الموجود (فقط إذا تم اختيار مندوب)
        const salesRepSelect = document.getElementById('salesRepSelect');
        if (!salesRepSelect || salesRepSelect.value) {
            existingCustomerSelect.removeAttribute('disabled');
            existingCustomerSelect.setAttribute('required', 'required');
        } else {
            // إذا لم يتم اختيار مندوب، تعطيل حقل العميل
            existingCustomerSelect.setAttribute('disabled', 'disabled');
            existingCustomerSelect.removeAttribute('required');
        }
        
        // إلغاء تفعيل الحقول المطلوبة للعميل الجديد
        newCustomerRequiredInputs.forEach(function(input) {
            input.removeAttribute('required');
        });
        return false;
    }
}

// تهيئة جميع الأحداث بعد تحميل DOM
document.addEventListener('DOMContentLoaded', function() {
    const addOrderModalElement = document.getElementById('addOrderModal');
    
    if (!addOrderModalElement) {
        console.error('addOrderModal not found');
        return;
    }
    
    // ربط أحداث toggle للعميل الجديد - استخدام event delegation
    addOrderModalElement.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'toggleNewCustomer') {
            setTimeout(function() {
                updateNewCustomerState();
            }, 10);
        }
    });
    
    // معالجة النقر على label أيضاً (لـ Bootstrap form-switch)
    addOrderModalElement.addEventListener('click', function(e) {
        let toggle = null;
        
        // إذا تم النقر مباشرة على checkbox
        if (e.target.id === 'toggleNewCustomer') {
            toggle = e.target;
        }
        // إذا تم النقر على label
        else if (e.target.tagName === 'LABEL' && e.target.getAttribute('for') === 'toggleNewCustomer') {
            toggle = document.getElementById('toggleNewCustomer');
        }
        // إذا تم النقر على عنصر داخل label
        else if (e.target.closest('label[for="toggleNewCustomer"]')) {
            toggle = document.getElementById('toggleNewCustomer');
        }
        
        if (toggle) {
            setTimeout(function() {
                updateNewCustomerState();
            }, 50);
        }
    });
    
    // عند فتح Modal
    if (typeof bootstrap !== 'undefined') {
        addOrderModalElement.addEventListener('shown.bs.modal', function() {
            // تحديث الحالة الأولية
            updateNewCustomerState();
            
            // ربط حدث المندوب
            const salesRepSelect = document.getElementById('salesRepSelect');
            const existingCustomerSelect = document.getElementById('existingCustomerSelect');
            
            if (salesRepSelect && existingCustomerSelect) {
                // حفظ القيمة الحالية قبل إعادة الربط
                const currentValue = salesRepSelect.value;
                
                // إزالة أي listeners سابقة لتجنب التكرار
                const newSelect = salesRepSelect.cloneNode(true);
                salesRepSelect.parentNode.replaceChild(newSelect, salesRepSelect);
                
                const freshSelect = document.getElementById('salesRepSelect');
                if (freshSelect) {
                    // استعادة القيمة
                    if (currentValue) {
                        freshSelect.value = currentValue;
                    }
                    
                    freshSelect.addEventListener('change', function() {
                        loadSalesRepCustomers(this.value);
                    });
                    
                    // إذا كان هناك مندوب محدد، تحميل عملاءه
                    if (freshSelect.value) {
                        loadSalesRepCustomers(freshSelect.value);
                    }
                }
            }
        });
        
        // إعادة تعيين النموذج عند إغلاق Modal
        addOrderModalElement.addEventListener('hidden.bs.modal', function() {
            const newCustomerToggle = document.getElementById('toggleNewCustomer');
            if (newCustomerToggle) {
                newCustomerToggle.checked = false;
                updateNewCustomerState();
            }
            const newCustomerRequiredInputs = Array.from(document.querySelectorAll('#addOrderModal .new-customer-required'));
            newCustomerRequiredInputs.forEach(function(input) {
                input.value = '';
            });
            const newCustomerPhoneInput = document.querySelector('#addOrderModal input[name="new_customer_phone"]');
            const newCustomerAddressInput = document.querySelector('#addOrderModal textarea[name="new_customer_address"]');
            const newCustomerLatitudeInput = document.querySelector('#addOrderModal input[name="new_customer_latitude"]');
            const newCustomerLongitudeInput = document.querySelector('#addOrderModal input[name="new_customer_longitude"]');
            if (newCustomerPhoneInput) {
                newCustomerPhoneInput.value = '';
            }
            if (newCustomerAddressInput) {
                newCustomerAddressInput.value = '';
            }
            if (newCustomerLatitudeInput) {
                newCustomerLatitudeInput.value = '';
            }
            if (newCustomerLongitudeInput) {
                newCustomerLongitudeInput.value = '';
            }
        });
    }
});


// دالة الحصول على موقع المستخدم للعميل الجديد
// ربط الأحداث عند فتح Modal لضمان وجود العناصر
function setupLocationButton() {
    const getLocationBtn = document.getElementById('getLocationBtn');
    const latitudeInput = document.getElementById('newCustomerLatitude');
    const longitudeInput = document.getElementById('newCustomerLongitude');
    
    if (getLocationBtn && latitudeInput && longitudeInput) {
        // إزالة أي event listeners سابقة
        const newBtn = getLocationBtn.cloneNode(true);
        getLocationBtn.parentNode.replaceChild(newBtn, getLocationBtn);
        
        // ربط event listener جديد
        const newGetLocationBtn = document.getElementById('getLocationBtn');
        if (newGetLocationBtn) {
            newGetLocationBtn.addEventListener('click', function() {
                if (!navigator.geolocation) {
                    alert('المتصفح لا يدعم الحصول على الموقع');
                    return;
                }
                
                newGetLocationBtn.disabled = true;
                newGetLocationBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
                
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const latInput = document.getElementById('newCustomerLatitude');
                        const lngInput = document.getElementById('newCustomerLongitude');
                        if (latInput && lngInput) {
                            latInput.value = position.coords.latitude.toFixed(8);
                            lngInput.value = position.coords.longitude.toFixed(8);
                        }
                        newGetLocationBtn.disabled = false;
                        newGetLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i>';
                    },
                    function(error) {
                        alert('فشل الحصول على الموقع: ' + error.message);
                        newGetLocationBtn.disabled = false;
                        newGetLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i>';
                    }
                );
            });
        }
    }
}

// ربط الأحداث عند فتح Modal لزر الموقع
document.addEventListener('DOMContentLoaded', function() {
    const addOrderModalElement = document.getElementById('addOrderModal');
    if (addOrderModalElement && typeof bootstrap !== 'undefined') {
        addOrderModalElement.addEventListener('shown.bs.modal', function() {
            setupLocationButton();
        });
    }
});

// أيضاً محاولة الربط مباشرة عند تحميل الصفحة
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupLocationButton);
} else {
    setupLocationButton();
}

// دالة لإعداد زر الموقع لطلب الشركة
function setupCompanyLocationButton() {
    const getLocationBtn = document.getElementById('companyGetLocationBtn');
    const latitudeInput = document.getElementById('companyNewCustomerLatitude');
    const longitudeInput = document.getElementById('companyNewCustomerLongitude');
    
    if (getLocationBtn && latitudeInput && longitudeInput) {
        // إزالة أي event listeners سابقة
        const newBtn = getLocationBtn.cloneNode(true);
        getLocationBtn.parentNode.replaceChild(newBtn, getLocationBtn);
        
        // ربط event listener جديد
        const newGetLocationBtn = document.getElementById('companyGetLocationBtn');
        if (newGetLocationBtn) {
            newGetLocationBtn.addEventListener('click', function() {
                if (!navigator.geolocation) {
                    alert('المتصفح لا يدعم الحصول على الموقع');
                    return;
                }
                
                newGetLocationBtn.disabled = true;
                newGetLocationBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
                
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const latInput = document.getElementById('companyNewCustomerLatitude');
                        const lngInput = document.getElementById('companyNewCustomerLongitude');
                        if (latInput && lngInput) {
                            latInput.value = position.coords.latitude.toFixed(8);
                            lngInput.value = position.coords.longitude.toFixed(8);
                        }
                        newGetLocationBtn.disabled = false;
                        newGetLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i>';
                    },
                    function(error) {
                        alert('فشل الحصول على الموقع: ' + error.message);
                        newGetLocationBtn.disabled = false;
                        newGetLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i>';
                    }
                );
            });
        }
    }
}

// ===== دوال أساسية للـ Modal/Card Dual System =====

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
    const cards = ['addOrderCard', 'addCompanyOrderCard', 'statusCard'];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    // إغلاق جميع Modals على الكمبيوتر
    const modals = ['addOrderModal', 'addCompanyOrderModal', 'statusModal'];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// ===== دوال فتح النماذج =====

// دالة فتح نموذج إضافة طلب
function showAddOrderModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addOrderCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addOrderModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// دالة فتح نموذج إضافة طلب شركة
function showAddCompanyOrderModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addCompanyOrderCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addCompanyOrderModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// دالة فتح نموذج تغيير الحالة
function showStatusModal(orderId, currentStatus) {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('statusCard');
        if (card) {
            const orderIdInput = card.querySelector('#statusCardOrderId');
            const statusSelect = card.querySelector('#statusCardSelect');
            
            if (orderIdInput) orderIdInput.value = orderId;
            if (statusSelect) statusSelect.value = currentStatus;
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('statusModal');
        if (modal) {
            document.getElementById('statusOrderId').value = orderId;
            document.getElementById('statusSelect').value = currentStatus;
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// ===== دوال إغلاق Cards =====

function closeAddOrderCard() {
    const card = document.getElementById('addOrderCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeAddCompanyOrderCard() {
    const card = document.getElementById('addCompanyOrderCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeStatusCard() {
    const card = document.getElementById('statusCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

// ===== دوال أخرى =====

// ربط أحداث العناصر
function attachItemEvents(item) {
    // لا حاجة لحسابات السعر والإجمالي
}

// ربط الأحداث للعناصر الموجودة
document.querySelectorAll('.order-item').forEach(item => {
    attachItemEvents(item);
});

// دالة تحميل عملاء المندوب
function loadSalesRepCustomers(salesRepId) {
    const existingCustomerSelect = document.getElementById('existingCustomerSelect');
    const newCustomerToggle = document.getElementById('toggleNewCustomer');
    
    if (!existingCustomerSelect) {
        console.error('existingCustomerSelect not found');
        return;
    }
    
    // إعادة تعيين حقل العميل
    existingCustomerSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    existingCustomerSelect.disabled = true;
    
    if (!salesRepId || salesRepId === '') {
        existingCustomerSelect.innerHTML = '<option value="">اختر المندوب أولاً</option>';
        existingCustomerSelect.disabled = true;
        if (newCustomerToggle) {
            newCustomerToggle.checked = false;
            updateNewCustomerState();
        }
        return;
    }
    
    // بناء URL بشكل صحيح
    const currentUrl = window.location.href;
    const url = new URL(currentUrl);
    url.searchParams.set('page', 'orders');
    url.searchParams.set('ajax', 'get_customers');
    url.searchParams.set('sales_rep_id', salesRepId);
    
    fetch(url.toString(), {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-cache'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Expected JSON but got ' + contentType);
        }
        return response.json();
    })
    .then(data => {
        existingCustomerSelect.innerHTML = '<option value="">اختر العميل</option>';
        
        if (data.success && data.customers && data.customers.length > 0) {
            data.customers.forEach(function(customer) {
                const option = document.createElement('option');
                option.value = customer.id;
                option.textContent = customer.name;
                existingCustomerSelect.appendChild(option);
            });
            
            existingCustomerSelect.disabled = false;
            existingCustomerSelect.setAttribute('required', 'required');
        } else {
            existingCustomerSelect.innerHTML = '<option value="">لا يوجد عملاء لهذا المندوب</option>';
            existingCustomerSelect.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error loading customers:', error);
        existingCustomerSelect.innerHTML = '<option value="">خطأ في تحميل العملاء</option>';
        existingCustomerSelect.disabled = false;
        alert('حدث خطأ في تحميل العملاء: ' + error.message);
    });
}


// JavaScript لمعالجة Modal طلب الشركة
<?php if ($isManagerOrAccountant): ?>
let companyItemIndex = 1;

// إضافة عنصر جديد لطلب الشركة
document.getElementById('addCompanyItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('companyOrderItems');
    const newItem = document.createElement('div');
    newItem.className = 'order-item row mb-2';
    const templateOptions = <?php echo json_encode(array_map(function($t) { 
        return ['value' => htmlspecialchars($t['product_name'] ?? '', ENT_QUOTES, 'UTF-8'), 'text' => htmlspecialchars($t['product_name'] ?? 'قالب #' . $t['id'], ENT_QUOTES, 'UTF-8')]; 
    }, $productTemplatesForDropdown), JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
    let optionsHtml = '<option value="">اختر قالب المنتج</option>';
    if (templateOptions && Array.isArray(templateOptions)) {
        templateOptions.forEach(function(template) {
            const value = template.value || '';
            const text = template.text || '';
            optionsHtml += '<option value="' + value.replace(/"/g, '&quot;').replace(/'/g, '&#39;') + '">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
        });
    }
    
    newItem.innerHTML = `
        <div class="col-md-9">
            <select class="form-select template-input" 
                   name="items[${companyItemIndex}][template_name]" required>
                ${optionsHtml}
            </select>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control quantity" 
                   name="items[${companyItemIndex}][quantity]" placeholder="الكمية" required>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger w-100 remove-item">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    companyItemIndex++;
});

// معالجة toggle عميل جديد للشركة
function updateNewCompanyCustomerState() {
    const toggleNewCompanyCustomer = document.getElementById('toggleNewCompanyCustomer');
    const companyCustomerSelect = document.getElementById('companyCustomerSelect');
    const newCompanyCustomerFields = document.getElementById('newCompanyCustomerFields');
    
    if (!toggleNewCompanyCustomer || !companyCustomerSelect || !newCompanyCustomerFields) {
        return;
    }

    // البحث عن العناصر داخل الـ function لضمان الوصول إليها
    const newCompanyCustomerRequiredInputs = Array.from(newCompanyCustomerFields.querySelectorAll('.new-company-customer-required'));

    if (toggleNewCompanyCustomer.checked) {
        newCompanyCustomerFields.classList.remove('d-none');
        newCompanyCustomerFields.style.display = '';
        companyCustomerSelect.value = '';
        companyCustomerSelect.setAttribute('disabled', 'disabled');
        companyCustomerSelect.removeAttribute('required');
        newCompanyCustomerRequiredInputs.forEach(function(input) {
            input.setAttribute('required', 'required');
        });
    } else {
        newCompanyCustomerFields.classList.add('d-none');
        newCompanyCustomerFields.style.display = 'none';
        companyCustomerSelect.removeAttribute('disabled');
        companyCustomerSelect.setAttribute('required', 'required');
        newCompanyCustomerRequiredInputs.forEach(function(input) {
            input.removeAttribute('required');
        });
    }
}

// إعداد Modal طلب الشركة
document.addEventListener('DOMContentLoaded', function() {
    const addCompanyOrderModalElement = document.getElementById('addCompanyOrderModal');
    
    if (addCompanyOrderModalElement && typeof bootstrap !== 'undefined') {
        // ربط أحداث toggle للعميل الجديد للشركة
        addCompanyOrderModalElement.addEventListener('change', function(e) {
            if (e.target && e.target.id === 'toggleNewCompanyCustomer') {
                setTimeout(function() {
                    updateNewCompanyCustomerState();
                }, 10);
            }
        });
        
        // معالجة النقر على label أيضاً
        addCompanyOrderModalElement.addEventListener('click', function(e) {
            let toggle = null;
            
            if (e.target.id === 'toggleNewCompanyCustomer') {
                toggle = e.target;
            } else if (e.target.tagName === 'LABEL' && e.target.getAttribute('for') === 'toggleNewCompanyCustomer') {
                toggle = document.getElementById('toggleNewCompanyCustomer');
            } else if (e.target.closest('label[for="toggleNewCompanyCustomer"]')) {
                toggle = document.getElementById('toggleNewCompanyCustomer');
            }
            
            if (toggle) {
                setTimeout(function() {
                    updateNewCompanyCustomerState();
                }, 50);
            }
        });
        
        // عند فتح الـ modal، تأكد من تحديث الحالة
        addCompanyOrderModalElement.addEventListener('shown.bs.modal', function() {
            updateNewCompanyCustomerState();
            setupCompanyLocationButton();
        });
        
        addCompanyOrderModalElement.addEventListener('hidden.bs.modal', function() {
            const toggleNewCompanyCustomer = document.getElementById('toggleNewCompanyCustomer');
            if (toggleNewCompanyCustomer) {
                toggleNewCompanyCustomer.checked = false;
                updateNewCompanyCustomerState();
            }
            // مسح حقول العميل الجديد
            const newCompanyCustomerFields = document.getElementById('newCompanyCustomerFields');
            if (newCompanyCustomerFields) {
                const requiredInputs = newCompanyCustomerFields.querySelectorAll('.new-company-customer-required');
                requiredInputs.forEach(function(input) {
                    input.value = '';
                });
            }
            const newCustomerPhoneInput = document.querySelector('#addCompanyOrderModal input[name="new_customer_phone"]');
            const newCustomerAddressInput = document.querySelector('#addCompanyOrderModal textarea[name="new_customer_address"]');
            const newCustomerLatitudeInput = document.querySelector('#addCompanyOrderModal input[name="new_customer_latitude"]');
            const newCustomerLongitudeInput = document.querySelector('#addCompanyOrderModal input[name="new_customer_longitude"]');
            if (newCustomerPhoneInput) {
                newCustomerPhoneInput.value = '';
            }
            if (newCustomerAddressInput) {
                newCustomerAddressInput.value = '';
            }
            if (newCustomerLatitudeInput) {
                newCustomerLatitudeInput.value = '';
            }
            if (newCustomerLongitudeInput) {
                newCustomerLongitudeInput.value = '';
            }
            // إعادة تعيين العناصر
            const companyOrderItems = document.getElementById('companyOrderItems');
            if (companyOrderItems) {
                const templateOptions = <?php echo json_encode(array_map(function($t) { 
                    return ['value' => htmlspecialchars($t['product_name'] ?? '', ENT_QUOTES, 'UTF-8'), 'text' => htmlspecialchars($t['product_name'] ?? 'قالب #' . $t['id'], ENT_QUOTES, 'UTF-8')]; 
                }, $productTemplatesForDropdown), JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
                let optionsHtml = '<option value="">اختر قالب المنتج</option>';
                if (templateOptions && Array.isArray(templateOptions)) {
                    templateOptions.forEach(function(template) {
                        const value = template.value || '';
                        const text = template.text || '';
                        optionsHtml += '<option value="' + value.replace(/"/g, '&quot;').replace(/'/g, '&#39;') + '">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
                    });
                }
                
                companyOrderItems.innerHTML = `
                    <div class="order-item row mb-2">
                        <div class="col-md-9">
                            <select class="form-select template-input" 
                                   name="items[0][template_name]" required>
                                ${optionsHtml}
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" class="form-control quantity" 
                                   name="items[0][quantity]" placeholder="الكمية" required>
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-danger w-100 remove-item">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
                companyItemIndex = 1;
            }
        });
    }
});
<?php endif; ?>

// ===== JavaScript للتعامل مع Cards (للموبايل) =====

// إضافة عنصر جديد لـ Card إضافة الطلب
let cardItemIndex = 1;
document.getElementById('cardAddItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('cardOrderItems');
    if (!itemsDiv) return;
    
    const newItem = document.createElement('div');
    newItem.className = 'order-item row mb-2';
    const templateOptions = <?php echo json_encode(array_map(function($t) { 
        return ['value' => htmlspecialchars($t['product_name'] ?? '', ENT_QUOTES, 'UTF-8'), 'text' => htmlspecialchars($t['product_name'] ?? 'قالب #' . $t['id'], ENT_QUOTES, 'UTF-8')]; 
    }, $productTemplatesForDropdown), JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
    let optionsHtml = '<option value="">اختر قالب المنتج</option>';
    if (templateOptions && Array.isArray(templateOptions)) {
        templateOptions.forEach(function(template) {
            const value = template.value || '';
            const text = template.text || '';
            optionsHtml += '<option value="' + value.replace(/"/g, '&quot;').replace(/'/g, '&#39;') + '">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
        });
    }
    
    newItem.innerHTML = `
        <div class="col-md-9">
            <select class="form-select card-template-input" 
                   name="items[${cardItemIndex}][template_name]" required>
                ${optionsHtml}
            </select>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control quantity" 
                   name="items[${cardItemIndex}][quantity]" placeholder="الكمية" required>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger w-100 card-remove-item">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    cardItemIndex++;
});

// حذف عنصر من Card إضافة الطلب
document.addEventListener('click', function(e) {
    if (e.target.closest('.card-remove-item')) {
        e.target.closest('.order-item').remove();
    }
});

// دالة تحديث حالة العميل الجديد في Card
function updateCardNewCustomerState() {
    const cardToggleNewCustomer = document.getElementById('cardToggleNewCustomer');
    const cardExistingCustomerSelect = document.getElementById('cardExistingCustomerSelect');
    const cardNewCustomerFields = document.getElementById('cardNewCustomerFields');
    
    if (!cardToggleNewCustomer || !cardExistingCustomerSelect || !cardNewCustomerFields) {
        return;
    }

    const cardNewCustomerRequiredInputs = Array.from(cardNewCustomerFields.querySelectorAll('.card-new-customer-required'));

    if (cardToggleNewCustomer.checked) {
        cardNewCustomerFields.classList.remove('d-none');
        cardNewCustomerFields.style.display = '';
        cardExistingCustomerSelect.value = '';
        cardExistingCustomerSelect.setAttribute('disabled', 'disabled');
        cardExistingCustomerSelect.removeAttribute('required');
        cardNewCustomerRequiredInputs.forEach(function(input) {
            input.setAttribute('required', 'required');
        });
    } else {
        cardNewCustomerFields.classList.add('d-none');
        cardNewCustomerFields.style.display = 'none';
        const cardSalesRepSelect = document.getElementById('cardSalesRepSelect');
        if (!cardSalesRepSelect || cardSalesRepSelect.value) {
            cardExistingCustomerSelect.removeAttribute('disabled');
            cardExistingCustomerSelect.setAttribute('required', 'required');
        } else {
            cardExistingCustomerSelect.setAttribute('disabled', 'disabled');
            cardExistingCustomerSelect.removeAttribute('required');
        }
        cardNewCustomerRequiredInputs.forEach(function(input) {
            input.removeAttribute('required');
        });
    }
}

// ربط أحداث toggle للعميل الجديد في Card
document.addEventListener('DOMContentLoaded', function() {
    const cardToggleNewCustomer = document.getElementById('cardToggleNewCustomer');
    if (cardToggleNewCustomer) {
        cardToggleNewCustomer.addEventListener('change', function() {
            setTimeout(function() {
                updateCardNewCustomerState();
            }, 10);
        });
    }
    
    // ربط حدث تحميل عملاء المندوب في Card
    const cardSalesRepSelect = document.getElementById('cardSalesRepSelect');
    if (cardSalesRepSelect) {
        cardSalesRepSelect.addEventListener('change', function() {
            const salesRepId = this.value;
            loadCardSalesRepCustomers(salesRepId);
        });
    }
    
    // ربط حدث الحصول على الموقع في Card
    const cardGetLocationBtn = document.getElementById('cardGetLocationBtn');
    if (cardGetLocationBtn) {
        cardGetLocationBtn.addEventListener('click', function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    document.getElementById('cardNewCustomerLatitude').value = position.coords.latitude;
                    document.getElementById('cardNewCustomerLongitude').value = position.coords.longitude;
                }, function(error) {
                    alert('فشل الحصول على الموقع: ' + error.message);
                });
            } else {
                alert('المتصفح لا يدعم الحصول على الموقع');
            }
        });
    }
});

// دالة تحميل عملاء المندوب في Card
function loadCardSalesRepCustomers(salesRepId) {
    const cardExistingCustomerSelect = document.getElementById('cardExistingCustomerSelect');
    const cardToggleNewCustomer = document.getElementById('cardToggleNewCustomer');
    
    if (!cardExistingCustomerSelect) {
        return;
    }
    
    cardExistingCustomerSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    cardExistingCustomerSelect.disabled = true;
    
    if (!salesRepId || salesRepId === '') {
        cardExistingCustomerSelect.innerHTML = '<option value="">اختر المندوب أولاً</option>';
        cardExistingCustomerSelect.disabled = true;
        if (cardToggleNewCustomer) {
            cardToggleNewCustomer.checked = false;
            updateCardNewCustomerState();
        }
        return;
    }
    
    const currentUrl = window.location.href;
    const url = new URL(currentUrl);
    url.searchParams.set('page', 'orders');
    url.searchParams.set('ajax', 'get_customers');
    url.searchParams.set('sales_rep_id', salesRepId);
    
    fetch(url.toString(), {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-cache'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Expected JSON but got ' + contentType);
        }
        return response.json();
    })
    .then(data => {
        cardExistingCustomerSelect.innerHTML = '<option value="">اختر العميل</option>';
        
        if (data.success && data.customers && data.customers.length > 0) {
            data.customers.forEach(function(customer) {
                const option = document.createElement('option');
                option.value = customer.id;
                option.textContent = customer.name;
                cardExistingCustomerSelect.appendChild(option);
            });
            
            cardExistingCustomerSelect.disabled = false;
            cardExistingCustomerSelect.setAttribute('required', 'required');
        } else {
            cardExistingCustomerSelect.innerHTML = '<option value="">لا يوجد عملاء لهذا المندوب</option>';
            cardExistingCustomerSelect.disabled = false;
        }
        updateCardNewCustomerState();
    })
    .catch(error => {
        console.error('Error loading customers:', error);
        cardExistingCustomerSelect.innerHTML = '<option value="">خطأ في تحميل العملاء</option>';
        cardExistingCustomerSelect.disabled = false;
        alert('حدث خطأ في تحميل العملاء: ' + error.message);
    });
}

// JavaScript لمعالجة Card طلب الشركة
<?php if ($isManagerOrAccountant): ?>
let cardCompanyItemIndex = 1;

// إضافة عنصر جديد لـ Card طلب الشركة
document.getElementById('cardAddCompanyItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('cardCompanyOrderItems');
    if (!itemsDiv) return;
    
    const newItem = document.createElement('div');
    newItem.className = 'order-item row mb-2';
    const templateOptions = <?php echo json_encode(array_map(function($t) { 
        return ['value' => htmlspecialchars($t['product_name'] ?? '', ENT_QUOTES, 'UTF-8'), 'text' => htmlspecialchars($t['product_name'] ?? 'قالب #' . $t['id'], ENT_QUOTES, 'UTF-8')]; 
    }, $productTemplatesForDropdown), JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
    let optionsHtml = '<option value="">اختر قالب المنتج</option>';
    if (templateOptions && Array.isArray(templateOptions)) {
        templateOptions.forEach(function(template) {
            const value = template.value || '';
            const text = template.text || '';
            optionsHtml += '<option value="' + value.replace(/"/g, '&quot;').replace(/'/g, '&#39;') + '">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
        });
    }
    
    newItem.innerHTML = `
        <div class="col-md-9">
            <select class="form-select card-company-template-input" 
                   name="items[${cardCompanyItemIndex}][template_name]" required>
                ${optionsHtml}
            </select>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control quantity" 
                   name="items[${cardCompanyItemIndex}][quantity]" placeholder="الكمية" required>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger w-100 card-company-remove-item">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    cardCompanyItemIndex++;
});

// حذف عنصر من Card طلب الشركة
document.addEventListener('click', function(e) {
    if (e.target.closest('.card-company-remove-item')) {
        e.target.closest('.order-item').remove();
    }
});

// دالة تحديث حالة العميل الجديد في Card طلب الشركة
function updateCardNewCompanyCustomerState() {
    const cardToggleNewCompanyCustomer = document.getElementById('cardToggleNewCompanyCustomer');
    const cardCompanyCustomerSelect = document.getElementById('cardCompanyCustomerSelect');
    const cardNewCompanyCustomerFields = document.getElementById('cardNewCompanyCustomerFields');
    
    if (!cardToggleNewCompanyCustomer || !cardCompanyCustomerSelect || !cardNewCompanyCustomerFields) {
        return;
    }

    const cardNewCompanyCustomerRequiredInputs = Array.from(cardNewCompanyCustomerFields.querySelectorAll('.card-new-company-customer-required'));

    if (cardToggleNewCompanyCustomer.checked) {
        cardNewCompanyCustomerFields.classList.remove('d-none');
        cardNewCompanyCustomerFields.style.display = '';
        cardCompanyCustomerSelect.value = '';
        cardCompanyCustomerSelect.setAttribute('disabled', 'disabled');
        cardCompanyCustomerSelect.removeAttribute('required');
        cardNewCompanyCustomerRequiredInputs.forEach(function(input) {
            input.setAttribute('required', 'required');
        });
    } else {
        cardNewCompanyCustomerFields.classList.add('d-none');
        cardNewCompanyCustomerFields.style.display = 'none';
        cardCompanyCustomerSelect.removeAttribute('disabled');
        cardCompanyCustomerSelect.setAttribute('required', 'required');
        cardNewCompanyCustomerRequiredInputs.forEach(function(input) {
            input.removeAttribute('required');
        });
    }
}

// ربط أحداث toggle للعميل الجديد في Card طلب الشركة
document.addEventListener('DOMContentLoaded', function() {
    const cardToggleNewCompanyCustomer = document.getElementById('cardToggleNewCompanyCustomer');
    if (cardToggleNewCompanyCustomer) {
        cardToggleNewCompanyCustomer.addEventListener('change', function() {
            setTimeout(function() {
                updateCardNewCompanyCustomerState();
            }, 10);
        });
    }
    
    // ربط حدث الحصول على الموقع في Card طلب الشركة
    const cardCompanyGetLocationBtn = document.getElementById('cardCompanyGetLocationBtn');
    if (cardCompanyGetLocationBtn) {
        cardCompanyGetLocationBtn.addEventListener('click', function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    document.getElementById('cardCompanyNewCustomerLatitude').value = position.coords.latitude;
                    document.getElementById('cardCompanyNewCustomerLongitude').value = position.coords.longitude;
                }, function(error) {
                    alert('فشل الحصول على الموقع: ' + error.message);
                });
            } else {
                alert('المتصفح لا يدعم الحصول على الموقع');
            }
        });
    }
});
<?php endif; ?>
</script>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();
</script>

<?php
// نهاية الملف
?>

<script>
// تحديث badge الطلبات الجديدة عند فتح صفحة الطلبات
document.addEventListener('DOMContentLoaded', function() {
    // إذا كان المستخدم مندوب مبيعات، قم بتحديث الإشعارات المرتبطة بالطلبات
    <?php if ($isSalesUser): ?>
    // تحديد إشعارات الطلبات كمقروءة عند فتح الصفحة
    fetch('<?php echo getRelativeUrl("api/notifications.php"); ?>?action=get_unread', {
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.notifications) {
            // البحث عن إشعارات الطلبات الجديدة
            const orderNotifications = data.notifications.filter(n => 
                (n.title === 'طلب جديد' || n.title === 'طلب شركة جديد') &&
                n.link && n.link.includes('sales.php?page=orders')
            );
            
            // تحديدها كمقروءة
            orderNotifications.forEach(notification => {
                if (notification.id) {
                    fetch('<?php echo getRelativeUrl("api/notifications.php"); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        credentials: 'same-origin',
                        body: new URLSearchParams({
                            action: 'mark_read',
                            id: notification.id
                        })
                    }).catch(err => console.error('Error marking notification as read:', err));
                }
            });
            
            // تحديث badge في الشريط الجانبي
            if (orderNotifications.length > 0) {
                const badge = document.getElementById('newOrdersBadge');
                if (badge) {
                    // إعادة حساب العدد بعد تحديث الإشعارات
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                }
            }
        }
    })
    .catch(err => console.error('Error loading notifications:', err));
    <?php endif; ?>
});
</script>




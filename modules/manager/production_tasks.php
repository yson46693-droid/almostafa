<?php
/**
 * صفحة إرسال المهام لقسم الإنتاج
 */

$isGetTaskForEdit = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_task_for_edit' && isset($_GET['task_id']);

// إرسال Cache-Control دائماً عند الإمكان لمنع أي كاش قديم (صفحة كاملة أو طلب get_task_for_edit)
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}
if (!$isGetTaskForEdit && !headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

if (!function_exists('getTasksRetentionLimit')) {
    function getTasksRetentionLimit(): int {
        if (defined('TASKS_RETENTION_MAX_ROWS')) {
            $value = (int) TASKS_RETENTION_MAX_ROWS;
            if ($value > 0) {
                return $value;
            }
        }
        return 100;
    }
}

if (!function_exists('enforceTasksRetentionLimit')) {
    function enforceTasksRetentionLimit($dbInstance = null, int $maxRows = 100) {
        $maxRows = (int) $maxRows;
        if ($maxRows < 1) {
            $maxRows = 100;
        }

        try {
            if ($dbInstance === null) {
                $dbInstance = db();
            }

            if (!$dbInstance) {
                return false;
            }

            $totalRow = $dbInstance->queryOne("SELECT COUNT(*) AS total FROM tasks");
            $total = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;

            if ($total <= $maxRows) {
                return true;
            }

            $toDelete = $total - $maxRows;
            $batchSize = 100;

            while ($toDelete > 0) {
                $currentBatch = min($batchSize, $toDelete);

                // حذف المهام الأقدم فقط، مع استثناء المهام المُنشأة في آخر دقيقة لمنع حذف المهام الجديدة
                $oldest = $dbInstance->query(
                    "SELECT id FROM tasks 
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                     ORDER BY created_at ASC, id ASC 
                     LIMIT ?",
                    [$currentBatch]
                );

                if (empty($oldest)) {
                    break;
                }

                $ids = array_map('intval', array_column($oldest, 'id'));
                $ids = array_filter($ids, static function ($id) {
                    return $id > 0;
                });

                if (empty($ids)) {
                    break;
                }

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $dbInstance->execute(
                    "DELETE FROM tasks WHERE id IN ($placeholders)",
                    $ids
                );

                $deleted = count($ids);
                $toDelete -= $deleted;

                if ($deleted < $currentBatch) {
                    break;
                }
            }

            return true;
        } catch (Throwable $e) {
            error_log('Tasks retention enforce error: ' . $e->getMessage());
            return false;
        }
    }
}

requireRole(['manager', 'accountant', 'developer']);

$db = db();
$currentUser = getCurrentUser();
$error = '';
$success = '';
$tasksRetentionLimit = getTasksRetentionLimit();

// تحديد نوع المستخدم
$isAccountant = ($currentUser['role'] ?? '') === 'accountant';
$isManager = ($currentUser['role'] ?? '') === 'manager';
$isDeveloper = ($currentUser['role'] ?? '') === 'developer';
$canPrintTasks = $isAccountant || $isManager || $isDeveloper;

// جلب القوالب (templates) لعرضها في القائمة المنسدلة
$productTemplates = [];
try {
    // محاولة جلب من unified_product_templates أولاً (الأحدث)
    $unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
    if (!empty($unifiedTemplatesCheck)) {
        $productTemplates = $db->query("
            SELECT DISTINCT product_name 
            FROM unified_product_templates 
            WHERE status = 'active' 
            ORDER BY product_name ASC
        ");
    }
    
    // إذا لم توجد قوالب في unified_product_templates، جرب product_templates
    if (empty($productTemplates)) {
        $templatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
        if (!empty($templatesCheck)) {
            $productTemplates = $db->query("
                SELECT DISTINCT product_name 
                FROM product_templates 
                WHERE status = 'active' 
                ORDER BY product_name ASC
            ");
        }
    }
} catch (Exception $e) {
    error_log('Error fetching product templates: ' . $e->getMessage());
    $productTemplates = [];
}

// جلب قائمة العملاء المحليين — نفس الاستعلام والطريقة تماماً كما في صفحة الأسعار المخصصة
$localCustomersForDropdown = [];
try {
    $t = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (!empty($t)) {
        $rows = $db->query("SELECT id, name FROM local_customers WHERE status = 'active' ORDER BY name ASC");
        foreach ($rows as $r) {
            $localCustomersForDropdown[] = [
                'id' => (int)$r['id'],
                'name' => trim((string)($r['name'] ?? '')),
                'phone' => '',
                'phones' => [],
            ];
        }
    }
} catch (Throwable $e) {
    error_log('production_tasks local_customers: ' . $e->getMessage());
    $localCustomersForDropdown = [];
}

// قائمة عملاء المندوبين (مثل صفحة الأسعار المخصصة)
$repCustomersForTask = [];
try {
    $repCustomersForTask = $db->query("
        SELECT c.id, c.name, c.phone,
               COALESCE(rep1.full_name, rep2.full_name) AS rep_name
        FROM customers c
        LEFT JOIN users rep1 ON c.rep_id = rep1.id AND rep1.role = 'sales'
        LEFT JOIN users rep2 ON c.created_by = rep2.id AND rep2.role = 'sales'
        WHERE c.status = 'active'
          AND ((c.rep_id IS NOT NULL AND c.rep_id IN (SELECT id FROM users WHERE role = 'sales'))
               OR (c.created_by IS NOT NULL AND c.created_by IN (SELECT id FROM users WHERE role = 'sales')))
        ORDER BY c.name ASC
        LIMIT 500
    ");
    $repCustomersForTask = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'name' => trim((string)($r['name'] ?? '')),
            'phone' => trim((string)($r['phone'] ?? '')),
            'rep_name' => trim((string)($r['rep_name'] ?? '')),
        ];
    }, $repCustomersForTask);
} catch (Throwable $e) {
    error_log('production_tasks rep customers: ' . $e->getMessage());
    $repCustomersForTask = [];
}

/**
 * تأكد من وجود جدول المهام (tasks)
 */
try {
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'tasks'");
    if (empty($tableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `tasks` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `title` varchar(255) NOT NULL,
              `description` text DEFAULT NULL,
              `assigned_to` int(11) DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
              `status` enum('pending','received','in_progress','completed','delivered','returned','cancelled') DEFAULT 'pending',
              `due_date` date DEFAULT NULL,
              `completed_at` timestamp NULL DEFAULT NULL,
              `received_at` timestamp NULL DEFAULT NULL,
              `started_at` timestamp NULL DEFAULT NULL,
              `related_type` varchar(50) DEFAULT NULL,
              `related_id` int(11) DEFAULT NULL,
              `product_id` int(11) DEFAULT NULL,
              `quantity` decimal(10,2) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `assigned_to` (`assigned_to`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`),
              KEY `priority` (`priority`),
              KEY `due_date` (`due_date`),
              KEY `product_id` (`product_id`),
              CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `tasks_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) {
    error_log('Manager task page table check error: ' . $e->getMessage());
}

// التحقق من وجود عمود template_id وإضافته إذا لم يكن موجوداً
try {
    $templateIdColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'template_id'");
    if (empty($templateIdColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN template_id int(11) NULL AFTER product_id");
        $db->execute("ALTER TABLE tasks ADD KEY template_id (template_id)");
        error_log('Added template_id column to tasks table');
    }
} catch (Exception $e) {
    error_log('Error checking/adding template_id column: ' . $e->getMessage());
}

// التحقق من وجود عمود product_name وإضافته إذا لم يكن موجوداً
try {
    $productNameColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'product_name'");
    if (empty($productNameColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN product_name VARCHAR(255) NULL AFTER template_id");
        error_log('Added product_name column to tasks table');
    }
} catch (Exception $e) {
    error_log('Error checking/adding product_name column: ' . $e->getMessage());
}

// التحقق من وجود عمود unit وإضافته إذا لم يكن موجوداً
try {
    $unitColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'unit'");
    if (empty($unitColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN unit VARCHAR(50) NULL DEFAULT 'قطعة' AFTER quantity");
        error_log('Added unit column to tasks table');
    }
} catch (Exception $e) {
    error_log('Error checking/adding unit column: ' . $e->getMessage());
}

// التحقق من وجود عمود customer_name وإضافته إذا لم يكن موجوداً
try {
    $customerNameColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'customer_name'");
    if (empty($customerNameColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN customer_name VARCHAR(255) NULL AFTER unit");
        error_log('Added customer_name column to tasks table');
    }
} catch (Exception $e) {
    error_log('Error checking/adding customer_name column: ' . $e->getMessage());
}

// التحقق من وجود عمود customer_phone وإضافته إذا لم يكن موجوداً
try {
    $customerPhoneColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'customer_phone'");
    if (empty($customerPhoneColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN customer_phone VARCHAR(50) NULL AFTER customer_name");
        error_log('Added customer_phone column to tasks table');
    }
} catch (Exception $e) {
    error_log('Error checking/adding customer_phone column: ' . $e->getMessage());
}

// التحقق من وجود عمود receipt_print_count لتتبع عدد مرات طباعة إيصال الأوردر
try {
    $receiptPrintCountColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'receipt_print_count'");
    if (empty($receiptPrintCountColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN receipt_print_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER notes");
        error_log('Added receipt_print_count column to tasks table');
    }
} catch (Exception $e) {
    error_log('Error checking/adding receipt_print_count column: ' . $e->getMessage());
}

/**
 * تحميل بيانات المستخدمين
 */
$productionUsers = [];

try {
    $productionUsers = $db->query("
        SELECT id, full_name
        FROM users
        WHERE status = 'active' AND role = 'production'
        ORDER BY full_name
    ");
} catch (Exception $e) {
    error_log('Manager task page users query error: ' . $e->getMessage());
}

$allowedTypes = ['shop_order', 'cash_customer', 'telegraph', 'shipping_company'];
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_production_task') {
        $taskType = $_POST['task_type'] ?? 'shop_order';
        $taskType = in_array($taskType, $allowedTypes, true) ? $taskType : 'shop_order';

        $title = trim($_POST['title'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $priority = $_POST['priority'] ?? 'normal';
        $priority = in_array($priority, $allowedPriorities, true) ? $priority : 'normal';
        $dueDate = $_POST['due_date'] ?? '';
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $assignees = $_POST['assigned_to'] ?? [];
        $shippingFees = 0;
        if (isset($_POST['shipping_fees']) && $_POST['shipping_fees'] !== '') {
            $shippingFees = (float) str_replace(',', '.', (string) $_POST['shipping_fees']);
            if ($shippingFees < 0) $shippingFees = 0;
        }
        
        // الحصول على المنتجات المتعددة
        $products = [];
        if (isset($_POST['products']) && is_array($_POST['products'])) {
            foreach ($_POST['products'] as $productData) {
                $productName = trim($productData['name'] ?? '');
                $productQuantityInput = isset($productData['quantity']) ? trim((string)$productData['quantity']) : '';
                
                if ($productName === '') {
                    continue; // تخطي المنتجات الفارغة
                }
                
                $productQuantity = null;
                $productUnit = trim($productData['unit'] ?? 'قطعة');
                $allowedUnits = ['قطعة', 'كرتونة', 'عبوة', 'شرينك', 'جرام', 'كيلو'];
                if (!in_array($productUnit, $allowedUnits, true)) {
                    $productUnit = 'قطعة'; // القيمة الافتراضية
                }
                
                // الوحدات التي يجب أن تكون أرقام صحيحة فقط
                $integerUnits = ['كيلو', 'قطعة', 'جرام'];
                $mustBeInteger = in_array($productUnit, $integerUnits, true);
                
                if ($productQuantityInput !== '') {
                    $normalizedQuantity = str_replace(',', '.', $productQuantityInput);
                    if (is_numeric($normalizedQuantity)) {
                        $productQuantity = (float)$normalizedQuantity;
                        
                        // التحقق من أن الكمية رقم صحيح للوحدات المحددة
                        if ($mustBeInteger && $productQuantity != (int)$productQuantity) {
                            $error = 'الكمية يجب أن تكون رقماً صحيحاً للوحدة "' . $productUnit . '".';
                            break;
                        }
                        
                        if ($productQuantity < 0) {
                            $error = 'لا يمكن أن تكون الكمية سالبة.';
                            break;
                        }
                        
                        // تحويل إلى رقم صحيح للوحدات المحددة
                        if ($mustBeInteger) {
                            $productQuantity = (int)$productQuantity;
                        }
                    } else {
                        $error = 'يرجى إدخال كمية صحيحة.';
                        break;
                    }
                }
                
                if ($productQuantity !== null && $productQuantity <= 0) {
                    $productQuantity = null;
                }
                
                $productPrice = null;
                $priceInput = isset($productData['price']) ? trim((string)$productData['price']) : '';
                if ($priceInput !== '' && is_numeric(str_replace(',', '.', $priceInput))) {
                    $productPrice = (float)str_replace(',', '.', $priceInput);
                    if ($productPrice < 0) {
                        $productPrice = null;
                    }
                }
                $productLineTotal = null;
                $lineTotalInput = isset($productData['line_total']) ? trim((string)$productData['line_total']) : '';
                if ($lineTotalInput !== '' && is_numeric(str_replace(',', '.', $lineTotalInput))) {
                    $productLineTotal = (float)str_replace(',', '.', $lineTotalInput);
                    if ($productLineTotal < 0) {
                        $productLineTotal = null;
                    }
                }
                $products[] = [
                    'name' => $productName,
                    'quantity' => $productQuantity,
                    'unit' => $productUnit,
                    'price' => $productPrice,
                    'line_total' => $productLineTotal
                ];
            }
        }
        
        // للتوافق مع الكود القديم: إذا لم تكن هناك منتجات في المصفوفة، جرب الحقول القديمة
        if (empty($products)) {
            $productName = trim($_POST['product_name'] ?? '');
            $productQuantityInput = isset($_POST['product_quantity']) ? trim((string)$_POST['product_quantity']) : '';
            
            if ($productName !== '') {
                $productQuantity = null;
                if ($productQuantityInput !== '') {
                    $normalizedQuantity = str_replace(',', '.', $productQuantityInput);
                    if (is_numeric($normalizedQuantity)) {
                        $productQuantity = (float)$normalizedQuantity;
                        if ($productQuantity < 0) {
                            $error = 'لا يمكن أن تكون الكمية سالبة.';
                        }
                    } else {
                        $error = 'يرجى إدخال كمية صحيحة.';
                    }
                }
                
                if ($productQuantity !== null && $productQuantity <= 0) {
                    $productQuantity = null;
                }
                
                if ($productName !== '' && !$error) {
                    $products[] = [
                        'name' => $productName,
                        'quantity' => $productQuantity,
                        'price' => null
                    ];
                }
            }
        }

        if (!is_array($assignees)) {
            $assignees = [$assignees];
        }

        $assignees = array_unique(array_filter(array_map('intval', $assignees)));
        $allowedAssignees = array_map(function ($user) {
            return (int)($user['id'] ?? 0);
        }, $productionUsers);
        $assignees = array_values(array_intersect($assignees, $allowedAssignees));

        if ($error !== '') {
            // تم ضبط رسالة الخطأ أعلاه (مثل التحقق من الكمية)
        } elseif (empty($assignees)) {
            $error = 'يجب اختيار عامل واحد على الأقل لاستلام المهمة.';
        } elseif ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $error = 'صيغة تاريخ الاستحقاق غير صحيحة.';
        } else {
            try {
                $db->beginTransaction();

                // إذا كانت المهمة تحتوي على بيانات عميل غير مسجل في العملاء المحليين، إضافته إلى local_customers
                if ($customerName !== '') {
                    $localCustomersTable = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
                    if (!empty($localCustomersTable)) {
                        $existingLocal = $db->queryOne("SELECT id FROM local_customers WHERE name = ?", [$customerName]);
                        if (empty($existingLocal)) {
                            $db->execute(
                                "INSERT INTO local_customers (name, phone, address, balance, status, created_by) VALUES (?, ?, NULL, 0, 'active', ?)",
                                [
                                    $customerName,
                                    $customerPhone !== '' ? $customerPhone : null,
                                    $currentUser['id'] ?? null,
                                ]
                            );
                        }
                    }
                }

                $relatedTypeValue = 'manager_' . $taskType;

                if ($title === '') {
                    $typeLabels = [
                        'shop_order' => 'اوردر محل',
                        'cash_customer' => 'عميل نقدي',
                        'telegraph' => 'تليجراف',
                        'shipping_company' => 'شركة شحن'
                    ];
                    $title = $typeLabels[$taskType] ?? 'مهمة جديدة';
                }

                // الحصول على أسماء العمال المختارين
                $assigneeNames = [];
                foreach ($assignees as $assignedId) {
                    foreach ($productionUsers as $user) {
                        if ((int)$user['id'] === $assignedId) {
                            $assigneeNames[] = $user['full_name'];
                            break;
                        }
                    }
                }

                // إنشاء مهمة واحدة فقط مع حفظ جميع العمال
                $columns = ['title', 'description', 'created_by', 'priority', 'status', 'related_type'];
                $values = [$title, $details ?: null, $currentUser['id'], $priority, 'pending', $relatedTypeValue];
                $placeholders = ['?', '?', '?', '?', '?', '?'];

                // وضع أول عامل في assigned_to للتوافق مع الكود الحالي
                $firstAssignee = !empty($assignees) ? (int)$assignees[0] : 0;
                if ($firstAssignee > 0) {
                    $columns[] = 'assigned_to';
                    $values[] = $firstAssignee;
                    $placeholders[] = '?';
                }

                if ($dueDate) {
                    $columns[] = 'due_date';
                    $values[] = $dueDate;
                    $placeholders[] = '?';
                }

                // حفظ المنتجات في notes بصيغة JSON
                $notesParts = [];
                if ($details) {
                    $notesParts[] = $details;
                }
                
                // حفظ المنتجات المتعددة في notes بصيغة JSON
                if (!empty($products)) {
                    $productsJson = json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $notesParts[] = '[PRODUCTS_JSON]:' . $productsJson;
                    
                    // أيضاً حفظ بصيغة نصية للتوافق مع الكود القديم
                    $productInfoLines = [];
                    foreach ($products as $product) {
                        $productInfo = 'المنتج: ' . $product['name'];
                        if ($product['quantity'] !== null) {
                            $productInfo .= ' - الكمية: ' . $product['quantity'];
                        }
                        $productInfoLines[] = $productInfo;
                    }
                    if (!empty($productInfoLines)) {
                        $notesParts[] = implode("\n", $productInfoLines);
                    }
                }
                
                // حفظ أول منتج في الحقول القديمة للتوافق
                $firstProduct = !empty($products) ? $products[0] : null;
                $productName = $firstProduct['name'] ?? '';
                $productQuantity = $firstProduct['quantity'] ?? null;
                
                // البحث عن template_id و product_id من اسم المنتج الأول - نفس طريقة customer_orders
                $templateId = null;
                $productId = null;
                if ($productName !== '') {
                    $templateName = trim($productName);
                    
                    // أولاً: البحث عن القالب بالاسم في unified_product_templates (النشطة أولاً)
                    try {
                        $unifiedCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                        if (!empty($unifiedCheck)) {
                            // البحث في القوالب النشطة أولاً
                            $template = $db->queryOne(
                                "SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                                [$templateName, $templateName]
                            );
                            if ($template) {
                                $templateId = (int)$template['id'];
                            } else {
                                // إذا لم يُعثر عليه في النشطة، البحث في جميع القوالب (بما في ذلك غير النشطة)
                                $template = $db->queryOne(
                                    "SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) LIMIT 1",
                                    [$templateName, $templateName]
                                );
                                if ($template) {
                                    $templateId = (int)$template['id'];
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log('Error searching unified_product_templates: ' . $e->getMessage());
                    }
                    
                    // ثانياً: إذا لم يُعثر عليه، البحث في product_templates
                    if (!$templateId) {
                        try {
                            $productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                            if (!empty($productTemplatesCheck)) {
                                // البحث في القوالب النشطة أولاً
                                $template = $db->queryOne(
                                    "SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                                    [$templateName, $templateName]
                                );
                                if ($template) {
                                    $templateId = (int)$template['id'];
                                } else {
                                    // إذا لم يُعثر عليه في النشطة، البحث في جميع القوالب (بما في ذلك غير النشطة)
                                    $template = $db->queryOne(
                                        "SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) LIMIT 1",
                                        [$templateName, $templateName]
                                    );
                                    if ($template) {
                                        $templateId = (int)$template['id'];
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            error_log('Error searching product_templates: ' . $e->getMessage());
                        }
                    }
                    
                    // ثالثاً: إذا لم يُعثر على template_id، البحث عن product_id في products
                    if (!$templateId) {
                        try {
                            $product = $db->queryOne(
                                "SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                                [$templateName]
                            );
                            if ($product) {
                                $productId = (int)$product['id'];
                            }
                        } catch (Exception $e) {
                            error_log('Error searching products: ' . $e->getMessage());
                        }
                    }
                }
                
                // حفظ قائمة العمال في notes
                if (count($assignees) > 1) {
                    $assigneesInfo = 'العمال المخصصون: ' . implode(', ', $assigneeNames);
                    $assigneesInfo .= "\n[ASSIGNED_WORKERS_IDS]:" . implode(',', $assignees);
                    $notesParts[] = $assigneesInfo;
                } elseif (count($assignees) === 1) {
                    $assigneesInfo = 'العامل المخصص: ' . ($assigneeNames[0] ?? '');
                    $assigneesInfo .= "\n[ASSIGNED_WORKERS_IDS]:" . $assignees[0];
                    $notesParts[] = $assigneesInfo;
                }
                
                // حفظ رسوم الشحن في notes لعرضها في الإيصال
                if ($shippingFees > 0) {
                    $notesParts[] = '[SHIPPING_FEES]:' . $shippingFees;
                }
                
                $notesValue = !empty($notesParts) ? implode("\n\n", $notesParts) : null;
                if ($notesValue) {
                    $columns[] = 'notes';
                    $values[] = $notesValue;
                    $placeholders[] = '?';
                }

                // حفظ template_id و product_name و product_id - نفس طريقة customer_orders
                // حفظ template_id (حتى لو كان null) لضمان حفظ product_name بشكل صحيح
                // عندما template_id = null، يجب أن يتم حفظ product_name لضمان عرضه في الجدول
                $columns[] = 'template_id';
                $values[] = $templateId; // يمكن أن يكون null
                $placeholders[] = '?';
                
                // حفظ product_name دائماً (حتى لو كان null أو فارغاً) لضمان الاتساق
                // هذا يضمن عرض اسم القالب في الجدول حتى لو فشل JOIN مع جداول القوالب أو كان template_id = null
                // نفس الطريقة المستخدمة في production/tasks.php (السطر 502-519)
                // نحفظ product_name دائماً لضمان الاتساق بين قاعدة البيانات و audit log
                $columns[] = 'product_name';
                $values[] = ($productName !== '') ? $productName : null; // حفظ null إذا كان فارغاً
                $placeholders[] = '?';
                
                // حفظ product_id إذا تم العثور عليه
                if ($productId !== null && $productId > 0) {
                    $columns[] = 'product_id';
                    $values[] = $productId;
                    $placeholders[] = '?';
                }

                if ($customerName !== '') {
                    $columns[] = 'customer_name';
                    $values[] = $customerName;
                    $placeholders[] = '?';
                }

                if ($customerPhone !== '') {
                    $columns[] = 'customer_phone';
                    $values[] = $customerPhone;
                    $placeholders[] = '?';
                }

                // حفظ الكمية الإجمالية (من أول منتج أو مجموع الكميات)
                $totalQuantity = null;
                $firstUnit = 'قطعة'; // القيمة الافتراضية
                if (!empty($products)) {
                    $totalQuantity = 0;
                    $firstUnit = $products[0]['unit'] ?? 'قطعة';
                    foreach ($products as $product) {
                        if ($product['quantity'] !== null) {
                            $totalQuantity += $product['quantity'];
                        }
                    }
                    if ($totalQuantity > 0) {
                        $columns[] = 'quantity';
                        $values[] = $totalQuantity;
                        $placeholders[] = '?';
                    }
                } elseif ($productQuantity !== null) {
                    $columns[] = 'quantity';
                    $values[] = $productQuantity;
                    $placeholders[] = '?';
                }
                
                // حفظ الوحدة (من أول منتج)
                if (!empty($products)) {
                    $columns[] = 'unit';
                    $values[] = $firstUnit;
                    $placeholders[] = '?';
                } elseif (!empty($_POST['unit'])) {
                    $unit = trim($_POST['unit'] ?? 'قطعة');
                    $allowedUnits = ['قطعة', 'كرتونة', 'عبوة', 'شرينك', 'جرام', 'كيلو'];
                    if (!in_array($unit, $allowedUnits, true)) {
                        $unit = 'قطعة';
                    }
                    $columns[] = 'unit';
                    $values[] = $unit;
                    $placeholders[] = '?';
                }

                $sql = "INSERT INTO tasks (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $result = $db->execute($sql, $values);
                $taskId = $result['insert_id'] ?? 0;

                if ($taskId <= 0) {
                    throw new Exception('تعذر إنشاء المهمة.');
                }

                logAudit(
                    $currentUser['id'],
                    'create_production_task',
                    'tasks',
                    $taskId,
                    null,
                    [
                        'task_type' => $taskType,
                        'assigned_to' => $assignees,
                        'assigned_count' => count($assignees),
                        'priority' => $priority,
                        'due_date' => $dueDate,
                        'product_name' => $productName ?: null,
                        'quantity' => $productQuantity
                    ]
                );

                // إرسال إشعارات لجميع العمال المختارين
                $notificationTitle = 'مهمة جديدة من الإدارة';
                $notificationMessage = $title;
                if (count($assignees) > 1) {
                    $notificationMessage .= ' (مشتركة مع ' . (count($assignees) - 1) . ' عامل آخر)';
                }

                foreach ($assignees as $assignedId) {
                    try {
                        createNotification(
                            $assignedId,
                            $notificationTitle,
                            $notificationMessage,
                            'info',
                            getRelativeUrl('production.php?page=tasks')
                        );
                    } catch (Exception $notificationException) {
                        error_log('Manager task notification error: ' . $notificationException->getMessage());
                    }
                }

                $db->commit();

                // تطبيق حد الاحتفاظ بعد الالتزام لضمان عدم حذف المهمة الجديدة
                // يتم استدعاؤه بعد الالتزام لمنع أي مشاكل في المعاملة
                enforceTasksRetentionLimit($db, $tasksRetentionLimit);

                // التوجيه إلى صفحة طباعة إيصال الأوردر مع فتح نافذة الطباعة تلقائياً (معاينة المتصفح)
                $successMessage = 'تم إرسال المهمة بنجاح إلى ' . count($assignees) . ' من عمال الإنتاج.';
                $userRole = ($currentUser['role'] ?? '') === 'accountant' ? 'accountant' : 'manager';
                $printReceiptUrl = getRelativeUrl('print_task_receipt.php?id=' . (int) $taskId . '&print=1');
                preventDuplicateSubmission($successMessage, [], $printReceiptUrl, $userRole);
                exit; // منع تنفيذ باقي الكود بعد إعادة التوجيه
            } catch (Exception $e) {
                $db->rollback();
                error_log('Manager production task creation error: ' . $e->getMessage());
                $error = 'حدث خطأ أثناء إنشاء المهام. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'update_task_status') {
        $taskId = intval($_POST['task_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');

        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح.';
        } elseif (!in_array($newStatus, ['pending', 'received', 'in_progress', 'completed', 'with_delegate', 'delivered', 'returned', 'cancelled'], true)) {
            $error = 'حالة المهمة غير صحيحة.';
        } else {
            try {
                $db->beginTransaction();

                // السماح للمحاسب والمدير بتغيير حالة أي مهمة
                $isAccountant = ($currentUser['role'] ?? '') === 'accountant';
                $isManager = ($currentUser['role'] ?? '') === 'manager';
                
                if (!$isAccountant && !$isManager) {
                    throw new Exception('غير مصرح لك بتغيير حالة المهام.');
                }
                
                // التحقق من وجود المهمة
                $task = $db->queryOne(
                    "SELECT id, title, status FROM tasks WHERE id = ? LIMIT 1",
                    [$taskId]
                );

                if (!$task) {
                    throw new Exception('المهمة غير موجودة.');
                }

                // تحديث الحالة
                $updateFields = ['status = ?'];
                $updateValues = [$newStatus];
                
                // إضافة timestamps حسب الحالة
                if (in_array($newStatus, ['completed', 'with_delegate', 'delivered', 'returned'], true)) {
                    $updateFields[] = 'completed_at = NOW()';
                } elseif ($newStatus === 'in_progress') {
                    $updateFields[] = 'started_at = NOW()';
                } elseif ($newStatus === 'received') {
                    $updateFields[] = 'received_at = NOW()';
                }
                
                $updateFields[] = 'updated_at = NOW()';
                
                $sql = "UPDATE tasks SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $updateValues[] = $taskId;
                
                $db->execute($sql, $updateValues);

                logAudit(
                    $currentUser['id'],
                    'update_task_status',
                    'tasks',
                    $taskId,
                    ['old_status' => $task['status']],
                    ['new_status' => $newStatus, 'title' => $task['title']]
                );

                $db->commit();
                
                // استخدام preventDuplicateSubmission لإعادة التوجيه مع cache-busting
                $successMessage = 'تم تحديث حالة المهمة بنجاح.';
                // تحديد role بناءً على المستخدم الحالي
                $userRole = ($currentUser['role'] ?? '') === 'accountant' ? 'accountant' : 'manager';
                preventDuplicateSubmission($successMessage, ['page' => 'production_tasks'], null, $userRole);
                exit; // منع تنفيذ باقي الكود بعد إعادة التوجيه
            } catch (Exception $updateError) {
                $db->rollBack();
                $error = 'تعذر تحديث حالة المهمة: ' . $updateError->getMessage();
            }
        }
    } elseif ($action === 'cancel_task') {
        $taskId = intval($_POST['task_id'] ?? 0);

                if ($taskId <= 0) {
                    $error = 'معرف المهمة غير صحيح.';
                } else {
                    try {
                        $db->beginTransaction();

                        // السماح للمحاسب والمدير بحذف أي مهمة
                        $isAccountant = ($currentUser['role'] ?? '') === 'accountant';
                        $isManager = ($currentUser['role'] ?? '') === 'manager';
                        
                        if ($isAccountant || $isManager) {
                            // المحاسب والمدير يمكنهم حذف أي مهمة
                            $task = $db->queryOne(
                                "SELECT id, title, status FROM tasks WHERE id = ? LIMIT 1",
                                [$taskId]
                            );
                        } else {
                            // المستخدمون الآخرون يمكنهم حذف المهام التي أنشأوها فقط
                            $task = $db->queryOne(
                                "SELECT id, title, status FROM tasks WHERE id = ? AND created_by = ? LIMIT 1",
                                [$taskId, $currentUser['id']]
                            );
                        }

                        if (!$task) {
                            if ($isAccountant || $isManager) {
                                throw new Exception('المهمة غير موجودة.');
                            } else {
                                throw new Exception('المهمة غير موجودة أو ليست من إنشائك.');
                            }
                        }

                // حذف المهمة بدلاً من تغيير الحالة إلى cancelled
                $db->execute(
                    "DELETE FROM tasks WHERE id = ?",
                    [$taskId]
                );

                // تعليم الإشعارات القديمة كمقروءة
                $db->execute(
                    "UPDATE notifications SET `read` = 1 WHERE message = ? AND type IN ('info','success','warning')",
                    [$task['title']]
                );

                logAudit(
                    $currentUser['id'],
                    'cancel_task',
                    'tasks',
                    $taskId,
                    null,
                    ['title' => $task['title']]
                );

                $db->commit();
                
                // استخدام preventDuplicateSubmission لإعادة التوجيه مع cache-busting
                $successMessage = 'تم حذف المهمة بنجاح.';
                // تحديد role بناءً على المستخدم الحالي
                $userRole = ($currentUser['role'] ?? '') === 'accountant' ? 'accountant' : 'manager';
                preventDuplicateSubmission($successMessage, ['page' => 'production_tasks'], null, $userRole);
                exit; // منع تنفيذ باقي الكود بعد إعادة التوجيه
            } catch (Exception $cancelError) {
                $db->rollBack();
                $error = 'تعذر إلغاء المهمة: ' . $cancelError->getMessage();
            }
        }
    } elseif ($action === 'update_task') {
        $taskId = intval($_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح.';
        } elseif (!($isAccountant || $isManager)) {
            $error = 'غير مصرح بتعديل المهمة.';
        } else {
            try {
                $task = $db->queryOne("SELECT id FROM tasks WHERE id = ? LIMIT 1", [$taskId]);
                if (!$task) {
                    $error = 'المهمة غير موجودة.';
                } else {
                    $taskType = $_POST['task_type'] ?? 'shop_order';
                    $taskType = in_array($taskType, $allowedTypes, true) ? $taskType : 'shop_order';
                    $priority = $_POST['priority'] ?? 'normal';
                    $priority = in_array($priority, $allowedPriorities, true) ? $priority : 'normal';
                    $dueDate = trim($_POST['due_date'] ?? '') ?: null;
                    $customerName = trim($_POST['customer_name'] ?? '') ?: null;
                    $customerPhone = trim($_POST['customer_phone'] ?? '') ?: null;
                    $details = trim($_POST['details'] ?? '') ?: null;
                    $assignees = isset($_POST['assigned_to']) && is_array($_POST['assigned_to'])
                        ? array_filter(array_map('intval', $_POST['assigned_to']))
                        : [];
                    $products = [];
                    if (isset($_POST['products']) && is_array($_POST['products'])) {
                        foreach ($_POST['products'] as $p) {
                            $name = trim($p['name'] ?? '');
                            if ($name === '') continue;
                            $qty = isset($p['quantity']) && $p['quantity'] !== '' ? (float)str_replace(',', '.', $p['quantity']) : null;
                            $unit = in_array(trim($p['unit'] ?? 'قطعة'), ['قطعة','كرتونة','عبوة','شرينك','جرام','كيلو'], true) ? trim($p['unit']) : 'قطعة';
                            $price = isset($p['price']) && $p['price'] !== '' ? (float)str_replace(',', '.', $p['price']) : null;
                            $lineTotal = isset($p['line_total']) && $p['line_total'] !== '' ? (float)str_replace(',', '.', $p['line_total']) : null;
                            $products[] = ['name' => $name, 'quantity' => $qty, 'unit' => $unit, 'price' => $price, 'line_total' => $lineTotal];
                        }
                    }
                    $notesParts = [];
                    if ($details) $notesParts[] = $details;
                    if (!empty($products)) {
                        $notesParts[] = '[PRODUCTS_JSON]:' . json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $lines = [];
                        foreach ($products as $p) {
                            $lines[] = 'المنتج: ' . $p['name'] . ($p['quantity'] !== null ? ' - الكمية: ' . $p['quantity'] : '');
                        }
                        $notesParts[] = implode("\n", $lines);
                    }
                    $assigneeNames = [];
                    foreach ($assignees as $aid) {
                        $u = $db->queryOne("SELECT full_name FROM users WHERE id = ?", [$aid]);
                        if ($u) $assigneeNames[] = $u['full_name'];
                    }
                    if (count($assignees) > 1) {
                        $notesParts[] = 'العمال المخصصون: ' . implode(', ', $assigneeNames) . "\n[ASSIGNED_WORKERS_IDS]:" . implode(',', $assignees);
                    } elseif (count($assignees) === 1) {
                        $notesParts[] = 'العامل المخصص: ' . ($assigneeNames[0] ?? '') . "\n[ASSIGNED_WORKERS_IDS]:" . $assignees[0];
                    }
                    $notesValue = !empty($notesParts) ? implode("\n\n", $notesParts) : null;
                    $firstProduct = !empty($products) ? $products[0] : null;
                    $productName = $firstProduct['name'] ?? null;
                    $quantity = $firstProduct['quantity'] ?? null;
                    $unit = $firstProduct['unit'] ?? 'قطعة';
                    if (!empty($products)) {
                        $q = 0;
                        foreach ($products as $p) {
                            if ($p['quantity'] !== null) $q += $p['quantity'];
                        }
                        $quantity = $q > 0 ? $q : null;
                    }
                    $templateId = null;
                    $productId = null;
                    if ($productName) {
                        $tn = trim($productName);
                        $t = $db->queryOne("SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1", [$tn, $tn]);
                        if ($t) $templateId = (int)$t['id'];
                        if (!$templateId) {
                            $t = $db->queryOne("SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1", [$tn, $tn]);
                            if ($t) $templateId = (int)$t['id'];
                        }
                        if (!$templateId) {
                            $p = $db->queryOne("SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1", [$tn]);
                            if ($p) $productId = (int)$p['id'];
                        }
                    }
                    $firstAssignee = !empty($assignees) ? (int)$assignees[0] : 0;
                    $relatedType = 'manager_' . $taskType;
                    $db->execute(
                        "UPDATE tasks SET task_type = ?, related_type = ?, priority = ?, due_date = ?, customer_name = ?, customer_phone = ?,
                         notes = ?, product_name = ?, quantity = ?, unit = ?, template_id = ?, product_id = ?, assigned_to = ?
                         WHERE id = ?",
                        [
                            $taskType, $relatedType, $priority, $dueDate, $customerName, $customerPhone,
                            $notesValue, $productName, $quantity, $unit, $templateId, $productId ?: null, $firstAssignee ?: null,
                            $taskId
                        ]
                    );
                    $successMessage = 'تم تعديل الأوردر بنجاح.';
                    $userRole = ($currentUser['role'] ?? '') === 'accountant' ? 'accountant' : 'manager';
                    preventDuplicateSubmission($successMessage, ['page' => 'production_tasks', '_refresh' => time()], null, $userRole);
                    exit;
                }
            } catch (Exception $e) {
                $error = 'تعذر تعديل المهمة: ' . $e->getMessage();
            }
        }
    }
}

// جلب بيانات المهمة للتعديل (AJAX) — المدير والمحاسب والمطور
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_task_for_edit' && ($isAccountant || $isManager || $isDeveloper)) {
    $taskId = isset($_GET['task_id']) ? intval($_GET['task_id']) : 0;
    if ($taskId > 0) {
        $task = $db->queryOne("SELECT id, task_type, related_type, priority, due_date, customer_name, customer_phone, notes, description, product_name, quantity, unit FROM tasks WHERE id = ?", [$taskId]);
        if ($task) {
            $displayType = (strpos($task['related_type'] ?? '', 'manager_') === 0) ? substr($task['related_type'], 8) : ($task['task_type'] ?? 'shop_order');
            $notes = (string)($task['notes'] ?? '');
            $products = [];
            $assignees = [];
            // استخراج المنتجات من [PRODUCTS_JSON] — دعم \n و \r\n
            if (preg_match('/\[PRODUCTS_JSON\]\s*:\s*(.+?)(?=\s*\n\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/s', $notes, $m)) {
                $jsonStr = trim($m[1]);
                $decoded = json_decode($jsonStr, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $p) {
                        $products[] = [
                            'name' => trim((string)($p['name'] ?? '')),
                            'quantity' => isset($p['quantity']) ? (is_numeric($p['quantity']) ? (float)$p['quantity'] : null) : null,
                            'unit' => trim((string)($p['unit'] ?? 'قطعة')) ?: 'قطعة',
                            'price' => isset($p['price']) && $p['price'] !== '' && $p['price'] !== null ? (is_numeric($p['price']) ? (float)$p['price'] : null) : null
                        ];
                    }
                }
            }
            // إذا لم نجد JSON، استخراج من النص "المنتج: X - الكمية: Y"
            if (empty($products) && preg_match_all('/المنتج:\s*([^\n]+?)(?:\s*-\s*الكمية:\s*([0-9.]+))?/u', $notes, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $products[] = [
                        'name' => trim($m[1]),
                        'quantity' => isset($m[2]) ? (float)$m[2] : null,
                        'unit' => trim((string)($task['unit'] ?? 'قطعة')) ?: 'قطعة',
                        'price' => null
                    ];
                }
            }
            // إذا لم نجد منتجات، استخدام الحقول من الجدول
            if (empty($products)) {
                $pn = trim((string)($task['product_name'] ?? ''));
                $qty = isset($task['quantity']) ? (is_numeric($task['quantity']) ? (float)$task['quantity'] : null) : null;
                if ($pn !== '' || $qty !== null) {
                    $products[] = [
                        'name' => $pn,
                        'quantity' => $qty,
                        'unit' => trim((string)($task['unit'] ?? 'قطعة')) ?: 'قطعة',
                        'price' => null
                    ];
                }
            }
            // استخراج العمال المخصصين
            if (preg_match('/\[ASSIGNED_WORKERS_IDS\]\s*:\s*([0-9,\s]+)/', $notes, $m)) {
                $assignees = array_filter(array_map('intval', preg_split('/[\s,]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)));
            }
            // استخراج التفاصيل (الوصف) — استخدام description أولاً ثم تنظيف notes
            $details = trim((string)($task['description'] ?? ''));
            if ($details === '') {
                $details = $notes;
                $details = preg_replace('/\[PRODUCTS_JSON\][\s\S]*?(?=\s*\n\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/', '', $details);
                $details = preg_replace('/\[ASSIGNED_WORKERS_IDS\]\s*:[^\n]*/', '', $details);
                $details = preg_replace('/العمال المخصصون:[^\n]*/', '', $details);
                $details = preg_replace('/العامل المخصص:[^\n]*/', '', $details);
                $details = preg_replace('/المنتج:\s*[^\n]+/m', '', $details);
                $details = preg_replace('/\n\s*\n\s*\n+/', "\n\n", trim($details));
            }
            // تنسيق تاريخ الاستحقاق لـ input type="date" (YYYY-MM-DD)
            $dueDate = $task['due_date'] ?? '';
            if ($dueDate !== '' && $dueDate !== null) {
                $dueDate = trim((string)$dueDate);
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dueDate, $d)) {
                    $dueDate = $d[1] . '-' . $d[2] . '-' . $d[3];
                }
            } else {
                $dueDate = '';
            }
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'task' => [
                    'id' => (int)$task['id'],
                    'task_type' => $displayType,
                    'priority' => $task['priority'] ?? 'normal',
                    'due_date' => $dueDate,
                    'customer_name' => trim((string)($task['customer_name'] ?? '')),
                    'customer_phone' => trim((string)($task['customer_phone'] ?? '')),
                    'details' => $details,
                    'products' => $products,
                    'assignees' => array_values($assignees)
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false]);
    exit;
}

// قراءة رسائل النجاح/الخطأ من session بعد redirect
applyPRGPattern($error, $success);

/**
 * إحصائيات سريعة للمهام التي أنشأها المدير والمحاسب
 * المحاسب والمدير يرون جميع المهام التي أنشأها أي منهما
 */
$statsTemplate = [
    'total' => 0,
    'pending' => 0,
    'received' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'with_delegate' => 0,
    'delivered' => 0,
    'returned' => 0,
    'cancelled' => 0
];

$stats = $statsTemplate;
try {
    // جلب الإحصائيات لجميع المهام التي أنشأها المدير أو المحاسب
    if ($isAccountant || $isManager) {
        // جلب معرفات جميع المديرين والمحاسبين
        $adminUsers = $db->query("
            SELECT id FROM users 
            WHERE role IN ('manager', 'accountant') AND status = 'active'
        ");
        $adminIds = array_map(function($user) {
            return (int)$user['id'];
        }, $adminUsers);
        
        if (!empty($adminIds)) {
            $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
            $counts = $db->query("
                SELECT status, COUNT(*) as total
                FROM tasks
                WHERE created_by IN ($placeholders)
                AND status != 'cancelled'
                GROUP BY status
            ", $adminIds);
        } else {
            $counts = [];
        }
    } else {
        // للمستخدمين الآخرين، عرض المهام التي أنشأوها فقط
        $counts = $db->query("
            SELECT status, COUNT(*) as total
            FROM tasks
            WHERE created_by = ?
            AND status != 'cancelled'
            GROUP BY status
        ", [$currentUser['id']]);
    }

    foreach ($counts as $row) {
        $statusKey = $row['status'] ?? '';
        if (isset($stats[$statusKey])) {
            $stats[$statusKey] = (int)$row['total'];
        }
    }
    // حساب الإجمالي من مجموع الحالات (أدق من COUNT المنفرد ويتجنب truncation في بعض بيئات MySQL/PHP)
    $stats['total'] = (int)$stats['pending'] + (int)$stats['received'] + (int)$stats['in_progress']
        + (int)$stats['completed'] + (int)$stats['with_delegate'] + (int)$stats['delivered'] + (int)$stats['returned'];
} catch (Exception $e) {
    error_log('Manager task stats error: ' . $e->getMessage());
}

$recentTasks = [];
$statusStyles = [
    'pending' => ['class' => 'warning', 'label' => 'معلقة'],
    'received' => ['class' => 'info', 'label' => 'مستلمة'],
    'completed' => ['class' => 'success', 'label' => 'مكتملة'],
    'with_delegate' => ['class' => 'info', 'label' => 'مع المندوب'],
    'delivered' => ['class' => 'success', 'label' => 'تم التوصيل'],
    'returned' => ['class' => 'secondary', 'label' => 'تم الارجاع'],
    'cancelled' => ['class' => 'danger', 'label' => 'ملغاة']
];

// طلب تفاصيل الأوردر لعرضها في المودال (إيصال الأوردر)
if (!empty($_GET['get_order_receipt']) && isset($_GET['order_id'])) {
    $orderId = (int) $_GET['order_id'];
    if ($orderId > 0) {
        $orderTableCheck = $db->queryOne("SHOW TABLES LIKE 'customer_orders'");
        if (!empty($orderTableCheck)) {
            $order = $db->queryOne(
                "SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address
                 FROM customer_orders o
                 LEFT JOIN customers c ON o.customer_id = c.id
                 WHERE o.id = ?",
                [$orderId]
            );
            if ($order) {
                $itemsTable = 'order_items';
                $itemsCheck = $db->queryOne("SHOW TABLES LIKE 'customer_order_items'");
                if (!empty($itemsCheck)) {
                    $itemsTable = 'customer_order_items';
                }
                $items = $db->query(
                    "SELECT oi.*, COALESCE(oi.product_name, p.name) AS display_name FROM {$itemsTable} oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ? ORDER BY oi.id",
                    [$orderId]
                );
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'order' => [
                        'order_number' => $order['order_number'] ?? '',
                        'customer_name' => $order['customer_name'] ?? '-',
                        'customer_phone' => $order['customer_phone'] ?? '',
                        'customer_address' => $order['customer_address'] ?? '',
                        'order_date' => $order['order_date'] ?? '',
                        'delivery_date' => $order['delivery_date'] ?? '',
                        'total_amount' => $order['total_amount'] ?? 0,
                        'notes' => $order['notes'] ?? '',
                    ],
                    'items' => array_map(function ($row) {
                        return [
                            'product_name' => $row['display_name'] ?? $row['product_name'] ?? '-',
                            'quantity' => $row['quantity'] ?? 0,
                            'unit' => $row['unit'] ?? 'قطعة',
                        ];
                    }, $items),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'الطلب غير موجود']);
    exit;
}

// Pagination لجدول آخر المهام
// Pagination
$tasksPageNum = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$tasksPerPage = 15;
$totalRecentTasks = 0;
$totalRecentPages = 1;

// Filter by status
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$statusCondition = "";
$statusParams = [];

if ($statusFilter && $statusFilter !== 'all') {
    $statusCondition = "AND t.status = ?";
    $statusParams[] = $statusFilter;
}

// البحث المتقدم والفلترة لجدول آخر المهام
$filterTaskId = isset($_GET['task_id']) ? trim((string)$_GET['task_id']) : '';
$filterCustomer = isset($_GET['search_customer']) ? trim((string)$_GET['search_customer']) : '';
$filterOrderId = isset($_GET['search_order_id']) ? trim((string)$_GET['search_order_id']) : '';
$filterTaskType = isset($_GET['task_type']) ? trim((string)$_GET['task_type']) : '';
$filterDueFrom = isset($_GET['due_date_from']) ? trim((string)$_GET['due_date_from']) : '';
$filterDueTo = isset($_GET['due_date_to']) ? trim((string)$_GET['due_date_to']) : '';
$filterSearchText = isset($_GET['search_text']) ? trim((string)$_GET['search_text']) : '';

$searchConditions = '';
$searchParams = [];

if ($filterTaskId !== '') {
    $taskIdInt = (int) $filterTaskId;
    if ($taskIdInt > 0) {
        $searchConditions .= " AND t.id = ?";
        $searchParams[] = $taskIdInt;
    }
}
if ($filterCustomer !== '') {
    $searchConditions .= " AND (t.customer_name LIKE ? OR t.customer_phone LIKE ?)";
    $customerLike = '%' . $filterCustomer . '%';
    $searchParams[] = $customerLike;
    $searchParams[] = $customerLike;
}
if ($filterOrderId !== '') {
    $orderIdInt = (int) $filterOrderId;
    if ($orderIdInt > 0) {
        $searchConditions .= " AND t.related_type = 'customer_order' AND t.related_id = ?";
        $searchParams[] = $orderIdInt;
    }
}
if ($filterTaskType !== '') {
    $searchConditions .= " AND (t.task_type = ? OR t.related_type = CONCAT('manager_', ?))";
    $searchParams[] = $filterTaskType;
    $searchParams[] = $filterTaskType;
}
if ($filterDueFrom !== '') {
    $searchConditions .= " AND t.due_date >= ?";
    $searchParams[] = $filterDueFrom;
}
if ($filterDueTo !== '') {
    $searchConditions .= " AND t.due_date <= ?";
    $searchParams[] = $filterDueTo;
}
if ($filterSearchText !== '') {
    $searchConditions .= " AND (t.title LIKE ? OR t.notes LIKE ? OR t.customer_name LIKE ? OR t.customer_phone LIKE ?)";
    $textLike = '%' . $filterSearchText . '%';
    $searchParams[] = $textLike;
    $searchParams[] = $textLike;
    $searchParams[] = $textLike;
    $searchParams[] = $textLike;
}

try {
    // جلب عدد المهام الإجمالي (للتقسيم)
    if ($isAccountant || $isManager) {
        $adminUsers = $db->query("
            SELECT id FROM users 
            WHERE role IN ('manager', 'accountant') AND status = 'active'
        ");
        $adminIds = array_map(function($user) {
            return (int)$user['id'];
        }, $adminUsers);
        
        if (!empty($adminIds)) {
            $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
            
            // دمج معايير التصفية والبحث المتقدم
            $countParams = array_merge($adminIds, $statusParams, $searchParams);
            
            $totalRow = $db->queryOne("
                SELECT COUNT(*) AS total FROM tasks t
                WHERE t.created_by IN ($placeholders) AND t.status != 'cancelled' $statusCondition $searchConditions
            ", $countParams);
            $totalRecentTasks = isset($totalRow['total']) ? (int)$totalRow['total'] : 0;
        }
    } else {
        $countParams = array_merge([$currentUser['id']], $statusParams, $searchParams);
        $totalRow = $db->queryOne("
            SELECT COUNT(*) AS total FROM tasks t
            WHERE t.created_by = ? AND t.status != 'cancelled' $statusCondition $searchConditions
        ", $countParams);
        $totalRecentTasks = isset($totalRow['total']) ? (int)$totalRow['total'] : 0;
    }
    
    $totalRecentPages = max(1, (int)ceil($totalRecentTasks / $tasksPerPage));
    $tasksPageNum = min($tasksPageNum, $totalRecentPages);
    $tasksOffset = ($tasksPageNum - 1) * $tasksPerPage;

    // جلب المهام المحدثة مع التقسيم - المحاسب والمدير يرون جميع المهام التي أنشأها أي منهما
    if ($isAccountant || $isManager) {
        // جلب معرفات جميع المديرين والمحاسبين
        $adminUsers = $db->query("
            SELECT id FROM users 
            WHERE role IN ('manager', 'accountant') AND status = 'active'
        ");
        $adminIds = array_map(function($user) {
            return (int)$user['id'];
        }, $adminUsers);
        
        if (!empty($adminIds)) {
            $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
            
            // دمج المعايير للاستعلام النهائي (فلتر الحالة + البحث المتقدم + التقسيم)
            $queryParams = array_merge($adminIds, $statusParams, $searchParams, [$tasksPerPage, $tasksOffset]);
            
            $recentTasks = $db->query("
                SELECT t.id, t.title, t.status, t.priority, t.due_date, t.created_at,
                       t.quantity, t.unit, t.customer_name, t.customer_phone, t.notes, t.product_id, t.related_type, t.related_id, t.task_type,
                       COALESCE(t.receipt_print_count, 0) AS receipt_print_count,
                       u.full_name AS assigned_name, t.assigned_to,
                       uCreator.full_name AS creator_name, t.created_by
                FROM tasks t
                LEFT JOIN users u ON t.assigned_to = u.id
                LEFT JOIN users uCreator ON t.created_by = uCreator.id
                WHERE t.created_by IN ($placeholders)
                AND t.status != 'cancelled'
                $statusCondition
                $searchConditions
                ORDER BY t.created_at DESC, t.id DESC
                LIMIT ? OFFSET ?
            ", $queryParams);
        } else {
            $recentTasks = [];
        }
    } else {
        // للمستخدمين الآخرين، عرض المهام التي أنشأوها فقط
        $queryParams = array_merge([$currentUser['id']], $statusParams, $searchParams, [$tasksPerPage, $tasksOffset]);
        
        $recentTasks = $db->query("
            SELECT t.id, t.title, t.status, t.priority, t.due_date, t.created_at,
                   t.quantity, t.unit, t.customer_name, t.customer_phone, t.notes, t.product_id, t.related_type, t.related_id, t.task_type,
                   COALESCE(t.receipt_print_count, 0) AS receipt_print_count,
                   u.full_name AS assigned_name, t.assigned_to
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.created_by = ?
            AND t.status != 'cancelled'
            $statusCondition
            $searchConditions
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT ? OFFSET ?
        ", $queryParams);
    }
    
    // استخراج جميع العمال من notes لكل مهمة واستخراج اسم المنتج
    foreach ($recentTasks as &$task) {
        $notes = $task['notes'] ?? '';
        $allWorkers = [];
        
        // محاولة استخراج IDs من notes
        if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $notes, $matches)) {
            $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
            if (!empty($workerIds)) {
                $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
                $workers = $db->query(
                    "SELECT id, full_name FROM users WHERE id IN ($placeholders) ORDER BY full_name",
                    $workerIds
                );
                foreach ($workers as $worker) {
                    $allWorkers[] = $worker['full_name'];
                }
            }
        }
        
        // إذا لم نجد عمال من notes، استخدم assigned_to
        if (empty($allWorkers) && !empty($task['assigned_name'])) {
            $allWorkers[] = $task['assigned_name'];
        }
        
        // استخراج اسم المنتج من notes والتحقق من وجوده في القوالب
        // نفس الطريقة المستخدمة في النموذج لعرض اسم القالب
        $extractedProductName = null;
        $tempProductName = null;
        
        // محاولة 1: استخدام product_id للحصول على اسم المنتج من جدول products
        if (!empty($task['product_id'])) {
            try {
                $product = $db->queryOne(
                    "SELECT name FROM products WHERE id = ? LIMIT 1",
                    [(int)$task['product_id']]
                );
                if ($product && !empty($product['name'])) {
                    $tempProductName = trim($product['name']);
                }
            } catch (Exception $e) {
                error_log('Error fetching product name from product_id: ' . $e->getMessage());
            }
        }
        
        // محاولة 2: إذا لم نجد من product_id، استخرج اسم المنتج من notes
        // الصيغة المحفوظة: "المنتج: [اسم المنتج] - الكمية: [الكمية]"
        // أو: "المنتج: [اسم المنتج]" (إذا لم تكن هناك كمية)
        if (empty($tempProductName) && !empty($notes)) {
            // محاولة 1: البحث عن "المنتج: [اسم] - الكمية:" (الصيغة القياسية المحفوظة)
            if (preg_match('/المنتج:\s*(.+?)\s*-\s*الكمية:/i', $notes, $productMatches)) {
                $tempProductName = trim($productMatches[1] ?? '');
            }
            
            // محاولة 2: إذا لم نجد، جرب البحث عن "المنتج: [اسم]" فقط (بدون كمية)
            if (empty($tempProductName) && preg_match('/المنتج:\s*(.+?)(?:\n|$)/i', $notes, $productMatches2)) {
                $tempProductName = trim($productMatches2[1] ?? '');
            }
            
            // محاولة 3: البحث البسيط عن "المنتج: " متبوعاً بأي نص حتى "-" أو نهاية السطر
            if (empty($tempProductName) && preg_match('/المنتج:\s*(.+?)(?:\s*-\s*|$)/i', $notes, $productMatches3)) {
                $tempProductName = trim($productMatches3[1] ?? '');
            }
            
            // تنظيف اسم المنتج من أي أحرف زائدة
            if (!empty($tempProductName)) {
                $tempProductName = trim($tempProductName);
                // إزالة أي "-" في البداية أو النهاية
                $tempProductName = trim($tempProductName, '-');
                $tempProductName = trim($tempProductName);
            }
        }
        
        // محاولة 3: التحقق من وجود الاسم في القوالب (unified_product_templates أو product_templates)
        // بنفس الطريقة المستخدمة في النموذج
        if (!empty($tempProductName)) {
            try {
                // محاولة البحث في unified_product_templates أولاً
                $unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                if (!empty($unifiedTemplatesCheck)) {
                    $template = $db->queryOne(
                        "SELECT DISTINCT product_name 
                         FROM unified_product_templates 
                         WHERE product_name = ? AND status = 'active' 
                         LIMIT 1",
                        [$tempProductName]
                    );
                    if ($template && !empty($template['product_name'])) {
                        $extractedProductName = trim($template['product_name']);
                    }
                }
                
                // إذا لم نجد في unified_product_templates، جرب product_templates
                if (empty($extractedProductName)) {
                    $templatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                    if (!empty($templatesCheck)) {
                        $template = $db->queryOne(
                            "SELECT DISTINCT product_name 
                             FROM product_templates 
                             WHERE product_name = ? AND status = 'active' 
                             LIMIT 1",
                            [$tempProductName]
                        );
                        if ($template && !empty($template['product_name'])) {
                            $extractedProductName = trim($template['product_name']);
                        }
                    }
                }
                
                // إذا لم نجد في القوالب، استخدم الاسم المستخرج من notes مباشرة
                // (قد يكون منتج مخصص غير موجود في القوالب)
                if (empty($extractedProductName)) {
                    $extractedProductName = $tempProductName;
                }
            } catch (Exception $e) {
                error_log('Error checking product name in templates: ' . $e->getMessage());
                // في حالة الخطأ، استخدم الاسم المستخرج من notes
                $extractedProductName = $tempProductName;
            }
        }
        
        $task['all_workers'] = $allWorkers;
        $task['workers_count'] = count($allWorkers);
        $task['extracted_product_name'] = $extractedProductName;
        
        // إضافة creator_name و creator_role إذا لم يكونا موجودين
        if (!isset($task['creator_name']) && isset($task['created_by'])) {
            $creator = $db->queryOne("SELECT full_name, role FROM users WHERE id = ?", [$task['created_by']]);
            if ($creator) {
                $task['creator_name'] = $creator['full_name'];
                $task['creator_role'] = $creator['role'];
            }
        } elseif (isset($task['created_by']) && !isset($task['creator_role'])) {
            // إذا كان creator_name موجوداً لكن creator_role غير موجود
            $creator = $db->queryOne("SELECT role FROM users WHERE id = ?", [$task['created_by']]);
            if ($creator) {
                $task['creator_role'] = $creator['role'];
            }
        }
    }
    unset($task);
} catch (Exception $e) {
    error_log('Manager recent tasks error: ' . $e->getMessage());
}

// بناء معاملات الرابط للفلترة والبحث (للاستخدام في التصفح والروابط)
$recentTasksQueryParams = ['page' => 'production_tasks'];
if ($statusFilter !== '') $recentTasksQueryParams['status'] = $statusFilter;
if ($filterTaskId !== '') $recentTasksQueryParams['task_id'] = $filterTaskId;
if ($filterCustomer !== '') $recentTasksQueryParams['search_customer'] = $filterCustomer;
if ($filterOrderId !== '') $recentTasksQueryParams['search_order_id'] = $filterOrderId;
if ($filterTaskType !== '') $recentTasksQueryParams['task_type'] = $filterTaskType;
if ($filterDueFrom !== '') $recentTasksQueryParams['due_date_from'] = $filterDueFrom;
if ($filterDueTo !== '') $recentTasksQueryParams['due_date_to'] = $filterDueTo;
if ($filterSearchText !== '') $recentTasksQueryParams['search_text'] = $filterSearchText;
$recentTasksQueryString = http_build_query($recentTasksQueryParams, '', '&', PHP_QUERY_RFC3986);

?>

<script>
// حل نهائي لمنع الكاش القديم: الطلب الأول قد يعيد كاش، فنجبر طلباً ثانياً بعنوان فريد (_t) لضمان جلب بيانات حية دائماً
(function() {
    'use strict';
    var search = window.location.search || '';
    var hasBust = /[?&]_t=|[?&]_nocache=|[?&]_v=/.test(search);
    if (!hasBust) {
        var sep = search.length ? '&' : '?';
        var newUrl = window.location.pathname + search + sep + '_t=' + Date.now();
        if (window.location.hash) newUrl += window.location.hash;
        window.location.replace(newUrl);
        return;
    }
    // إزالة معاملات cache-bust من شريط العنوان بعد التحميل الناجح
    var urlParams = new URLSearchParams(window.location.search);
    var changed = false;
    ['_t', '_nocache', '_v', '_refresh'].forEach(function(p) {
        if (urlParams.has(p)) {
            urlParams.delete(p);
            changed = true;
        }
    });
    if (changed) {
        var qs = urlParams.toString();
        var cleanUrl = window.location.pathname + (qs ? '?' + qs : '') + (window.location.hash || '');
        window.history.replaceState({}, '', cleanUrl);
    }
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    });
})();
</script>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="bi bi-list-task me-2"></i> أوردرات لقسم الإنتاج</h2>
            <p class="text-muted mb-0">قم بإنشاء أوردرات موجهة لعمال الإنتاج مع تتبّع الحالة في صفحة الأوردرات الخاصة بهم.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" id="successAlert" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-2 mb-3">
        <div class="col-4 col-sm-4 col-md-2">
            <a href="?page=production_tasks" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === '' || $statusFilter === 'all' ? 'bg-primary text-white' : 'border-primary'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === '' || $statusFilter === 'all' ? 'text-white-50' : 'text-muted'; ?> small mb-1">إجمالي المهام</div>
                        <div class="fs-5 <?php echo $statusFilter === '' || $statusFilter === 'all' ? 'text-white' : 'text-primary'; ?> fw-semibold" style="min-width: 3em; display: inline-block; overflow: visible;" title="إجمالي: <?php echo (int)$stats['total']; ?>"><?php echo (int)$stats['total']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <a href="?page=production_tasks&status=pending" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'pending' ? 'bg-warning text-dark' : 'border-warning'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'pending' ? 'text-dark-50' : 'text-muted'; ?> small mb-1">معلقة</div>
                        <div class="fs-5 <?php echo $statusFilter === 'pending' ? 'text-dark' : 'text-warning'; ?> fw-semibold"><?php echo $stats['pending']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        
        <div class="col-4 col-sm-4 col-md-2">
            <a href="?page=production_tasks&status=completed" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'completed' ? 'bg-success text-white' : 'border-success'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'completed' ? 'text-white-50' : 'text-muted'; ?> small mb-1">مكتملة</div>
                        <div class="fs-5 <?php echo $statusFilter === 'completed' ? 'text-white' : 'text-success'; ?> fw-semibold"><?php echo $stats['completed']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <a href="?page=production_tasks&status=with_delegate" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'with_delegate' ? 'bg-info text-white' : 'border-info'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'with_delegate' ? 'text-white-50' : 'text-muted'; ?> small mb-1">مع المندوب</div>
                        <div class="fs-5 <?php echo $statusFilter === 'with_delegate' ? 'text-white' : 'text-info'; ?> fw-semibold"><?php echo $stats['with_delegate']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <a href="?page=production_tasks&status=delivered" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'delivered' ? 'bg-success text-white' : 'border-success'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'delivered' ? 'text-white-50' : 'text-muted'; ?> small mb-1">تم التوصيل</div>
                        <div class="fs-5 <?php echo $statusFilter === 'delivered' ? 'text-white' : 'text-success'; ?> fw-semibold"><?php echo $stats['delivered']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <a href="?page=production_tasks&status=returned" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'returned' ? 'bg-secondary text-white' : 'border-secondary'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'returned' ? 'text-white-50' : 'text-muted'; ?> small mb-1">تم الارجاع</div>
                        <div class="fs-5 <?php echo $statusFilter === 'returned' ? 'text-white' : 'text-secondary'; ?> fw-semibold"><?php echo $stats['returned']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <a href="?page=production_tasks&status=cancelled" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'cancelled' ? 'bg-danger text-white' : 'border-danger'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'cancelled' ? 'text-white-50' : 'text-muted'; ?> small mb-1">ملغاة</div>
                        <div class="fs-5 <?php echo $statusFilter === 'cancelled' ? 'text-white' : 'text-danger'; ?> fw-semibold"><?php echo $stats['cancelled']; ?></div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <button class="btn btn-primary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#createTaskFormCollapse" aria-expanded="false" aria-controls="createTaskFormCollapse">
        <i class="bi bi-plus-circle me-1"></i>إنشاء أوردر جديد
    </button>

    <div class="collapse" id="createTaskFormCollapse">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إنشاء أوردر جديد</h5>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <input type="hidden" name="action" value="create_production_task">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">نوع الاوردر</label>
                            <select class="form-select" name="task_type" id="taskTypeSelect" required>
                                <option value="shop_order">اوردر محل</option>
                                <option value="cash_customer">عميل نقدي</option>
                                <option value="telegraph">تليجراف</option>
                                <option value="shipping_company">شركة شحن</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="low">منخفضة</option>
                                <option value="normal" selected>عادية</option>
                                <option value="high">مرتفعة</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">تاريخ الاستحقاق</label>
                            <input type="date" class="form-control" name="due_date" value="">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">اختر العمال المستهدفين</label>
                            <div style="max-height: 120px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.375rem;">
                                <select class="form-select form-select-sm border-0" name="assigned_to[]" multiple style="max-height: 100px;">
                                    <?php foreach ($productionUsers as $worker): ?>
                                        <option value="<?php echo (int)$worker['id']; ?>"><?php echo htmlspecialchars($worker['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-text small">يمكن تحديد أكثر من عامل باستخدام زر CTRL أو SHIFT.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">العميل</label>
                            <div class="customer-type-wrap d-flex flex-wrap gap-3 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="customer_type_radio_task" id="ct_task_local" value="local" checked>
                                    <label class="form-check-label" for="ct_task_local">عميل محلي</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="customer_type_radio_task" id="ct_task_rep" value="rep">
                                    <label class="form-check-label" for="ct_task_rep">عميل مندوب</label>
                                </div>
                            </div>
                            <input type="hidden" name="customer_name" id="submit_customer_name" value="">
                            <div id="customer_select_local_task" class="customer-select-block mb-2">
                                <div class="search-wrap position-relative">
                                    <input type="text" id="local_customer_search_task" class="form-control form-control-sm" placeholder="اكتب للبحث أو أدخل اسم عميل جديد..." autocomplete="off">
                                    <input type="hidden" id="local_customer_id_task" value="">
                                    <div id="local_customer_dropdown_task" class="search-dropdown-task d-none"></div>
                                </div>
                            </div>
                            <div id="customer_select_rep_task" class="customer-select-block mb-2 d-none">
                                <div class="search-wrap position-relative">
                                    <input type="text" id="rep_customer_search_task" class="form-control form-control-sm" placeholder="اكتب للبحث أو أدخل اسم عميل جديد..." autocomplete="off">
                                    <input type="hidden" id="rep_customer_id_task" value="">
                                    <div id="rep_customer_dropdown_task" class="search-dropdown-task d-none"></div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">رقم العميل</label>
                                <input type="text" name="customer_phone" id="submit_customer_phone" class="form-control form-control-sm" placeholder="رقم الهاتف" dir="ltr" value="">
                            </div>
                            <small class="form-text text-muted d-block">اختر عميلاً مسجلاً أو اكتب اسماً جديداً—يُحفظ تلقائياً كعميل جديد إن لم يكن مسجلاً</small>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">وصف وتفاصيل و ملاحظات الاوردر</label>
                            <textarea class="form-control" name="details" rows="3" placeholder="أدخل التفاصيل والتعليمات اللازمة للعمال."></textarea>
                        </div>
                        <div class="col-12" id="productsSection">
                            <label class="form-label fw-bold">المنتجات والكميات</label>
                            <div id="productsContainer">
                                <div class="product-row mb-3 p-3 border rounded" data-product-index="0">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label small">اسم المنتج</label>
                                            <input type="text" class="form-control product-name-input" name="products[0][name]" placeholder="أدخل اسم المنتج أو القالب" list="templateSuggestions" autocomplete="off">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">الكمية</label>
                                            <input type="number" class="form-control product-quantity-input" name="products[0][quantity]" step="1" min="0" placeholder="مثال: 120" id="product-quantity-0">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">الوحدة</label>
                                            <select class="form-select form-select-sm product-unit-input" name="products[0][unit]" id="product-unit-0" onchange="updateQuantityStep(0)">
                                                <option value="كرتونة">كرتونة</option>
                                                <option value="عبوة">عبوة</option>
                                                <option value="كيلو">كيلو</option>
                                                <option value="جرام">جرام</option>
                                                <option value="شرينك">شرينك</option>
                                                <option value="قطعة" selected>قطعة</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">السعر</label>
                                            <input type="number" class="form-control product-price-input" name="products[0][price]" step="0.01" min="0" placeholder="0.00" id="product-price-0">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">الإجمالي</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" class="form-control product-line-total-input" name="products[0][line_total]" step="0.01" min="0" placeholder="0.00" id="product-line-total-0" title="الإجمالي = الكمية × السعر حسب الوحدة">
                                                <span class="input-group-text">ج.م</span>
                                            </div>
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <button type="button" class="btn btn-danger btn-sm w-100 remove-product-btn" style="display: none;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <datalist id="templateSuggestions"></datalist>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addProductBtn">
                                <i class="bi bi-plus-circle me-1"></i>إضافة منتج آخر
                            </button>
                            <div class="form-text mt-2">
                                <small class="text-muted">يمكنك إضافة أكثر من منتج وكمية في نفس المهمة.</small>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4 mt-2">
                            <label class="form-label" for="createTaskShippingFees">رسوم الشحن (ج.م)</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="shipping_fees" id="createTaskShippingFees" step="0.01" min="0" placeholder="0.00" value="0">
                                <span class="input-group-text">ج.م</span>
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="card bg-light border-primary border-opacity-25" id="createTaskTotalSummaryCard">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-2"><i class="bi bi-calculator me-2"></i>ملخص الإجمالي النهائي</h6>
                                    <div class="row g-2 small">
                                        <div class="col-6 col-md-4">
                                            <span class="text-muted">إجمالي المنتجات:</span>
                                            <strong class="d-block" id="createTaskSubtotalDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-4">
                                            <span class="text-muted">رسوم الشحن:</span>
                                            <strong class="d-block" id="createTaskShippingDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <span class="text-muted">الإجمالي النهائي:</span>
                                            <strong class="d-block fs-5 text-success" id="createTaskFinalTotalDisplay">0.00 ج.م</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-4 gap-2">                        <button type="submit" class="btn btn-primary"><i class="bi bi-send-check me-1"></i>إرسال المهمة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="collapse mb-3" id="editTaskFormCollapse">
        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>تعديل الاوردر</h5>
            </div>
            <div class="card-body">
                <form method="post" action="" id="editTaskForm">
                    <input type="hidden" name="action" value="update_task">
                    <input type="hidden" name="task_id" id="editTaskId">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">نوع الاوردر</label>
                            <select class="form-select" name="task_type" id="editTaskType" required>
                                <option value="shop_order">اوردر محل</option>
                                <option value="cash_customer">عميل نقدي</option>
                                <option value="telegraph">تليجراف</option>
                                <option value="shipping_company">شركة شحن</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority" id="editPriority">
                                <option value="low">منخفضة</option>
                                <option value="normal" selected>عادية</option>
                                <option value="high">مرتفعة</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">تاريخ الاستحقاق</label>
                            <input type="date" class="form-control" name="due_date" id="editDueDate">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">اختر العمال المستهدفين</label>
                            <div style="max-height: 120px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.375rem;">
                                <select class="form-select form-select-sm border-0" name="assigned_to[]" multiple style="max-height: 100px;" id="editAssignedTo">
                                    <?php foreach ($productionUsers as $worker): ?>
                                        <option value="<?php echo (int)$worker['id']; ?>"><?php echo htmlspecialchars($worker['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-text small">يمكن تحديد أكثر من عامل باستخدام زر CTRL أو SHIFT.</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">اسم العميل</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" name="customer_name" id="editCustomerName" placeholder="بحث أو أدخل اسم العميل">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">رقم العميل</label>
                            <input type="text" class="form-control" name="customer_phone" id="editCustomerPhone" placeholder="أدخل رقم العميل" dir="ltr">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">وصف وتفاصيل و ملاحظات الاوردر</label>
                            <textarea class="form-control" name="details" id="editDetails" rows="3" placeholder="أدخل التفاصيل والتعليمات اللازمة للعمال."></textarea>
                        </div>
                        <div class="col-12" id="editProductsSection">
                            <label class="form-label fw-bold">المنتجات والكميات</label>
                            <div id="editProductsContainer"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="editAddProductBtn">
                                <i class="bi bi-plus-circle me-1"></i>إضافة منتج آخر
                            </button>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-4 gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="closeEditTaskCard()"><i class="bi bi-x-circle me-1"></i>إلغاء</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>حفظ التعديلات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>آخر المهام التي تم إرسالها</h5>
            <div class="d-flex align-items-center gap-2">
                <?php if ($canPrintTasks && !empty($recentTasks)): ?>
                <button type="button" class="btn btn-outline-primary btn-sm" id="printSelectedReceiptsBtn" title="طباعة إيصالات الأوردرات المحددة" disabled>
                    <i class="bi bi-printer me-1"></i>طباعة المحدد (<span id="selectedCount">0</span>)
                </button>
                <?php endif; ?>
                <span class="text-muted small"><?php echo $totalRecentTasks; ?> <?php echo $totalRecentTasks === 1 ? 'مهمة' : 'مهام'; ?> · صفحة <?php echo $tasksPageNum; ?> من <?php echo $totalRecentPages; ?></span>
            </div>
        </div>
        <div class="card-body p-0">
            <!-- بحث وفلترة جدول آخر المهام -->
            <div class="p-3 border-bottom bg-light">
                <form method="get" action="" id="recentTasksFilterForm" class="recent-tasks-filter-form">
                    <input type="hidden" name="page" value="production_tasks">
                    <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-12 col-md-4 col-lg-3">
                            <label class="form-label small mb-0">بحث سريع</label>
                            <input type="text" name="search_text" class="form-control form-control-sm" placeholder="نص في العنوان، الملاحظات، العميل..." value="<?php echo htmlspecialchars($filterSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-search me-1"></i>بحث
                            </button>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#recentTasksAdvancedSearch" aria-expanded="false" aria-controls="recentTasksAdvancedSearch">
                                <i class="bi bi-funnel me-1"></i>بحث متقدم
                            </button>
                        </div>
                        <?php if ($filterTaskId !== '' || $filterCustomer !== '' || $filterOrderId !== '' || $filterTaskType !== '' || $filterDueFrom !== '' || $filterDueTo !== '' || $filterSearchText !== ''): ?>
                        <div class="col-auto">
                            <a href="?<?php echo $statusFilter !== '' ? 'page=production_tasks&status=' . rawurlencode($statusFilter) : 'page=production_tasks'; ?>" class="btn btn-outline-danger btn-sm">إزالة الفلتر</a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="collapse <?php echo ($filterTaskId !== '' || $filterCustomer !== '' || $filterOrderId !== '' || $filterTaskType !== '' || $filterDueFrom !== '' || $filterDueTo !== '') ? 'show' : ''; ?>" id="recentTasksAdvancedSearch">
                        <div class="row g-2 pt-2 border-top mt-2">
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label small mb-0">رقم الطلب</label>
                                <input type="text" name="task_id" class="form-control form-control-sm" placeholder="#" value="<?php echo htmlspecialchars($filterTaskId, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label small mb-0">اسم العميل / هاتف</label>
                                <input type="text" name="search_customer" class="form-control form-control-sm" placeholder="اسم أو رقم" value="<?php echo htmlspecialchars($filterCustomer, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label small mb-0">رقم الأوردر</label>
                                <input type="text" name="search_order_id" class="form-control form-control-sm" placeholder="رقم الأوردر" value="<?php echo htmlspecialchars($filterOrderId, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label small mb-0">نوع الاوردر</label>
                                <select name="task_type" class="form-select form-select-sm">
                                    <option value="">— الكل —</option>
                                    <option value="shop_order" <?php echo $filterTaskType === 'shop_order' ? 'selected' : ''; ?>>اوردر محل</option>
                                    <option value="cash_customer" <?php echo $filterTaskType === 'cash_customer' ? 'selected' : ''; ?>>عميل نقدي</option>
                                    <option value="telegraph" <?php echo $filterTaskType === 'telegraph' ? 'selected' : ''; ?>>تليجراف</option>
                                    <option value="shipping_company" <?php echo $filterTaskType === 'shipping_company' ? 'selected' : ''; ?>>شركة شحن</option>
                                    <option value="general" <?php echo $filterTaskType === 'general' ? 'selected' : ''; ?>>مهمة عامة</option>
                                    <option value="production" <?php echo $filterTaskType === 'production' ? 'selected' : ''; ?>>إنتاج منتج</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label small mb-0">تاريخ تسليم من</label>
                                <input type="date" name="due_date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterDueFrom, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label small mb-0">تاريخ تسليم إلى</label>
                                <input type="date" name="due_date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterDueTo, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table dashboard-table--no-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <?php if ($canPrintTasks): ?>
                            <th style="width: 40px;">
                                <input type="checkbox" class="form-check-input" id="selectAllTasks" title="تحديد الكل">
                            </th>
                            <?php endif; ?>
                            <th>رقم الطلب</th>
                            <th>اسم العميل</th>
                            <th>الاوردر</th>
                            <th>نوع الاوردر</th>
                            <th>الحاله</th>
                            <th>تاريخ التسليم</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTasks)): ?>
                            <tr>
                                <td colspan="<?php echo $canPrintTasks ? 8 : 7; ?>" class="text-center text-muted py-4">لم يتم إنشاء مهام بعد.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentTasks as $index => $task): ?>
                                <tr>
                                    <?php if ($canPrintTasks): ?>
                                    <td>
                                        <input type="checkbox" class="form-check-input task-print-checkbox" value="<?php echo (int)$task['id']; ?>" data-print-url="<?php echo htmlspecialchars(getRelativeUrl('print_task_receipt.php?id=' . (int)$task['id']), ENT_QUOTES, 'UTF-8'); ?>">
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php 
                                        $printCount = (int) ($task['receipt_print_count'] ?? 0);
                                        if ($printCount > 0): 
                                        ?>
                                        <span class="badge bg-info mb-1" title="عدد مرات طباعة إيصال الأوردر" style="font-size: 0.7rem;"> <?php echo $printCount; ?> <?php echo $printCount === 1 ? '' : ''; ?></span>
                                        <?php endif; ?>
                                        <strong>#<?php echo (int)$task['id']; ?></strong>
                                    </td>
                                    <td><?php 
                                        $custName = isset($task['customer_name']) ? trim((string)$task['customer_name']) : '';
                                        $custPhone = isset($task['customer_phone']) ? trim((string)$task['customer_phone']) : '';
                                        $custPhoneEsc = $custPhone !== '' ? 'tel:' . preg_replace('/[^\d+]/', '', $custPhone) : '';
                                        
                                        if ($custName !== '') {
                                            echo '<div>' . htmlspecialchars($custName, ENT_QUOTES, 'UTF-8') . '</div>';
                                            if ($custPhone !== '') {
                                                echo '<div class="text-muted small d-flex align-items-center gap-1" dir="ltr">';
                                                echo '<span>' . htmlspecialchars($custPhone, ENT_QUOTES, 'UTF-8') . '</span>';
                                                if ($custPhoneEsc !== 'tel:') {
                                                    echo ' <a href="' . htmlspecialchars($custPhoneEsc, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-outline-success btn-icon-only p-1" title="اتصال بالعميل" aria-label="اتصال"><i class="bi bi-telephone-fill"></i></a>';
                                                }
                                                echo '</div>';
                                            }
                                        } else {
                                            if ($custPhone !== '') {
                                                echo '<div class="d-flex align-items-center gap-1" dir="ltr">';
                                                echo '<span>' . htmlspecialchars($custPhone, ENT_QUOTES, 'UTF-8') . '</span>';
                                                if ($custPhoneEsc !== 'tel:') {
                                                    echo ' <a href="' . htmlspecialchars($custPhoneEsc, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-outline-success btn-icon-only p-1" title="اتصال بالعميل" aria-label="اتصال"><i class="bi bi-telephone-fill"></i></a>';
                                                }
                                                echo '</div>';
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                        }
                                    ?></td>
                                    <td>                                        <?php 
                                        // عرض منشئ المهمة إذا كان المحاسب أو المدير
                                        if (isset($task['creator_name']) && ($isAccountant || $isManager)) {
                                            $creatorRoleLabel = '';
                                            if (isset($task['creator_role'])) {
                                                $creatorRoleLabel = ($task['creator_role'] ?? '') === 'accountant' ? 'المحاسب' : 'المدير';
                                            } elseif (isset($task['created_by'])) {
                                                $creatorUser = $db->queryOne("SELECT role FROM users WHERE id = ? LIMIT 1", [$task['created_by']]);
                                                if ($creatorUser) {
                                                    $creatorRoleLabel = ($creatorUser['role'] ?? '') === 'accountant' ? 'المحاسب' : 'المدير';
                                                }
                                            }
                                            if ($creatorRoleLabel) {
                                                echo '<div class="text-muted small"><i class="bi bi-person me-1"></i>من: ' . htmlspecialchars($task['creator_name']) . ' (' . $creatorRoleLabel . ')</div>';
                                            }
                                        }
                                        // عرض اسم المنتج المستخرج من notes (تم استخراجه مسبقاً في loop)
                                        if (!empty($task['extracted_product_name'])) {
                                            echo '<div class="text-muted small"><i class="bi bi-box me-1"></i>المنتج: ' . htmlspecialchars($task['extracted_product_name']) . '</div>';
                                        }
                                        ?>
                                        <?php if ((float)($task['quantity'] ?? 0) > 0): ?>
                                            <?php 
                                            $unit = !empty($task['unit']) ? $task['unit'] : 'قطعة';
                                            ?>
                                            <div class="text-muted small">الكمية: <?php echo number_format((float)$task['quantity'], 2) . ' ' . htmlspecialchars($unit); ?></div>
                                        <?php endif; ?>
                                        <?php
                                        $hasOrder = !empty($task['related_type']) && (string)$task['related_type'] === 'customer_order' && !empty($task['related_id']);
                                        $orderIdForBtn = $hasOrder ? (int)$task['related_id'] : 0;
                                        if ($orderIdForBtn > 0):
                                        ?>
                                            <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="openOrderReceiptModal(<?php echo $orderIdForBtn; ?>)" title="عرض تفاصيل الأوردر">
                                                <i class="bi bi-receipt me-1"></i>عرض الأوردر
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $relatedType = $task['related_type'] ?? '';
                                        $displayType = (strpos($relatedType, 'manager_') === 0) ? substr($relatedType, 8) : ($task['task_type'] ?? 'general');
                                        $orderTypeLabels = ['shop_order' => 'اوردر محل', 'cash_customer' => 'عميل نقدي', 'telegraph' => 'تليجراف', 'shipping_company' => 'شركة شحن', 'general' => 'مهمة عامة', 'production' => 'إنتاج منتج'];
                                        echo htmlspecialchars($orderTypeLabels[$displayType] ?? $displayType);
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusKey = $task['status'] ?? '';
                                        $statusMeta = $statusStyles[$statusKey] ?? ['class' => 'secondary', 'label' => 'غير معروفة'];
                                        ?>
                                        <span class="badge bg-<?php echo htmlspecialchars($statusMeta['class']); ?>">
                                            <?php echo htmlspecialchars($statusMeta['label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        if ($task['due_date']) {
                                            $dt = DateTime::createFromFormat('Y-m-d', $task['due_date']);
                                            if ($dt) {
                                                echo htmlspecialchars($dt->format('d/m'));
                                            } else {
                                                echo htmlspecialchars($task['due_date']);
                                            }
                                        } else {
                                            echo '<span class="text-muted">غير محدد</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <div class="d-flex gap-1 flex-wrap">
                                                <?php if ($canPrintTasks): ?>
                                                <a href="<?php echo getRelativeUrl('print_task_receipt.php?id=' . (int) $task['id']); ?>" target="_blank" class="btn btn-outline-primary btn-sm btn-icon-only" title="طباعة الاوردر">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if ($isAccountant || $isManager): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm btn-icon-only" onclick="openChangeStatusModal(<?php echo (int)$task['id']; ?>, '<?php echo htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?>')" title="تغيير حالة الطلب">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex gap-1 flex-wrap">
                                                <?php if ($isAccountant || $isManager): ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openEditTaskModal(<?php echo (int)$task['id']; ?>)" title="تعديل الاوردر">
                                                    <i class="bi bi-pencil-square me-1"></i>تعديل
                                                </button>
                                                <?php endif; ?>
                                                <?php if (in_array($task['status'] ?? '', ['completed', 'delivered', 'returned'], true)): ?>
                                                <span class="text-muted small align-self-center">—</span>
                                                <?php else: ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('هل أنت متأكد من حذف هذه المهمة؟ سيتم حذفها نهائياً ولن تظهر في الجدول.');">
                                                    <input type="hidden" name="action" value="cancel_task">
                                                    <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="حذف المهمة">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalRecentPages > 1): ?>
                <?php
                $paginateParams = $recentTasksQueryParams;
                $paginateBase = $recentTasksQueryString;
                ?>
                <nav aria-label="تنقل صفحات المهام" class="p-3 pt-0">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?php echo $tasksPageNum <= 1 ? 'disabled' : ''; ?>">
                            <?php $prevParams = $paginateParams; $prevParams['p'] = max(1, $tasksPageNum - 1); ?>
                            <a class="page-link" href="?<?php echo http_build_query($prevParams, '', '&', PHP_QUERY_RFC3986); ?>" aria-label="السابق">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php
                        $startPage = max(1, $tasksPageNum - 2);
                        $endPage = min($totalRecentPages, $tasksPageNum + 2);
                        if ($startPage > 1): ?>
                            <?php $p1 = $paginateParams; $p1['p'] = 1; ?>
                            <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($p1, '', '&', PHP_QUERY_RFC3986); ?>">1</a></li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php $pi = $paginateParams; $pi['p'] = $i; ?>
                            <li class="page-item <?php echo $i == $tasksPageNum ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query($pi, '', '&', PHP_QUERY_RFC3986); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($endPage < $totalRecentPages): ?>
                            <?php if ($endPage < $totalRecentPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <?php $plast = $paginateParams; $plast['p'] = $totalRecentPages; ?>
                            <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($plast, '', '&', PHP_QUERY_RFC3986); ?>"><?php echo $totalRecentPages; ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?php echo $tasksPageNum >= $totalRecentPages ? 'disabled' : ''; ?>">
                            <?php $nextParams = $paginateParams; $nextParams['p'] = min($totalRecentPages, $tasksPageNum + 1); ?>
                            <a class="page-link" href="?<?php echo http_build_query($nextParams, '', '&', PHP_QUERY_RFC3986); ?>" aria-label="التالي">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Card تغيير حالة المهمة (مخصص للموبايل) -->
<div class="container-fluid px-0">
    <div class="collapse" id="changeStatusCardCollapse">
        <div class="card shadow-sm border-info mb-3">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-gear me-2"></i>تغيير حالة الطلب
                </h5>
                <button type="button" class="btn btn-sm btn-light" onclick="closeChangeStatusCard()" aria-label="إغلاق">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <form method="POST" id="changeStatusCardForm" action="">
                <input type="hidden" name="action" value="update_task_status">
                <input type="hidden" name="task_id" id="changeStatusCardTaskId">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الحالة الحالية</label>
                        <div id="currentStatusCardDisplay" class="alert alert-info mb-0"></div>
                    </div>
                    <div class="mb-3">
                        <label for="newStatusCard" class="form-label fw-bold">اختر الحالة الجديدة <span class="text-danger">*</span></label>
                        <select class="form-select" name="status" id="newStatusCard" required>
                            <option value="">-- اختر الحالة --</option>
                            <option value="pending">معلقة</option>
                            <option value="completed">مكتملة</option>
                            <option value="with_delegate">مع المندوب</option>
                            <option value="delivered">تم التوصيل</option>
                            <option value="returned">تم الارجاع</option>
                            <option value="cancelled">ملغاة</option>
                        </select>
                        <div class="form-text">سيتم تحديث حالة الطلب فوراً بعد الحفظ.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary w-50" onclick="closeChangeStatusCard()">
                            <i class="bi bi-x-circle me-1"></i>إلغاء
                        </button>
                        <button type="submit" class="btn btn-info w-50">
                            <i class="bi bi-check-circle me-1"></i>حفظ
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تغيير حالة المهمة -->
<div class="modal fade" id="changeStatusModal" tabindex="-1" aria-labelledby="changeStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="changeStatusModalLabel">
                    <i class="bi bi-gear me-2"></i>تغيير حالة الطلب
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" id="changeStatusForm" action="">
                <input type="hidden" name="action" value="update_task_status">
                <input type="hidden" name="task_id" id="changeStatusTaskId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الحالة الحالية</label>
                        <div id="currentStatusDisplay" class="alert alert-info mb-0"></div>
                    </div>
                    <div class="mb-3">
                        <label for="newStatus" class="form-label fw-bold">اختر الحالة الجديدة <span class="text-danger">*</span></label>
                        <select class="form-select" name="status" id="newStatus" required>
                            <option value="">-- اختر الحالة --</option>
                            <option value="pending">معلقة</option>
                            <option value="completed">مكتملة</option>
                            <option value="with_delegate">مع المندوب</option>
                            <option value="delivered">تم التوصيل</option>
                            <option value="returned">تم الارجاع</option>
                            <option value="cancelled">ملغاة</option>
                        </select>
                        <div class="form-text">سيتم تحديث حالة الطلب فوراً بعد الحفظ.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-check-circle me-1"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال إيصال الأوردر -->
<div class="modal fade" id="orderReceiptModal" tabindex="-1" aria-labelledby="orderReceiptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title" id="orderReceiptModalLabel"><i class="bi bi-receipt me-2"></i>إيصال الأوردر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4" id="orderReceiptContent">
                <div class="text-center py-4 text-muted" id="orderReceiptLoading">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2 mb-0">جاري تحميل تفاصيل الأوردر...</p>
                </div>
                <div id="orderReceiptBody" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<style>
.search-wrap.position-relative { position: relative; }
.search-dropdown-task { position: absolute; left: 0; right: 0; top: 100%; z-index: 1050; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin-top: 2px; }
.search-dropdown-task .search-dropdown-item-task { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
.search-dropdown-task .search-dropdown-item-task:hover { background: #f8f9fa; }
.search-dropdown-task .search-dropdown-item-task:last-child { border-bottom: none; }
/* إخفاء خانة العميل اليدوي إن وُجدت (كاش قديم) */
#customer_manual_block_task { display: none !important; }
input[name="customer_type_radio_task"][value="manual"] { display: none !important; }
label[for="ct_task_manual"], .form-check:has(#ct_task_manual) { display: none !important; }
</style>
<script>
var __localCustomersForTask = <?php echo json_encode($localCustomersForDropdown); ?>;
var __repCustomersForTask = <?php echo json_encode($repCustomersForTask); ?>;

var editProductIndex = 0;
function buildEditProductRow(idx, product) {
    var p = product || { name: '', quantity: '', unit: 'قطعة', price: '' };
    var unitVal = String(p.unit || 'قطعة').trim();
    var opts = ['كرتونة','عبوة','كيلو','جرام','شرينك','قطعة'].map(function(u) {
        return '<option value="' + u + '"' + (u === unitVal ? ' selected' : '') + '>' + u + '</option>';
    }).join('');
    var qtyVal = (p.quantity !== null && p.quantity !== undefined && p.quantity !== '') ? String(p.quantity) : '';
    var priceVal = (p.price !== null && p.price !== undefined && p.price !== '') ? String(p.price) : '';
    var nameVal = String(p.name || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return '<div class="product-row mb-3 p-3 border rounded edit-product-row" data-edit-product-index="' + idx + '">' +
        '<div class="row g-2">' +
        '<div class="col-md-4"><label class="form-label small">اسم المنتج</label>' +
        '<input type="text" class="form-control" name="products[' + idx + '][name]" placeholder="اسم المنتج أو القالب" list="templateSuggestions" value="' + nameVal + '"></div>' +
        '<div class="col-md-2"><label class="form-label small">الكمية</label>' +
        '<input type="number" class="form-control" name="products[' + idx + '][quantity]" step="0.01" min="0" placeholder="120" value="' + qtyVal + '"></div>' +
        '<div class="col-md-2"><label class="form-label small">الوحدة</label>' +
        '<select class="form-select form-select-sm" name="products[' + idx + '][unit]">' + opts + '</select></div>' +
        '<div class="col-md-2"><label class="form-label small">السعر</label>' +
        '<input type="number" class="form-control" name="products[' + idx + '][price]" step="0.01" min="0" placeholder="0.00" value="' + priceVal + '"></div>' +
        '<div class="col-md-2 d-flex align-items-end">' +
        '<button type="button" class="btn btn-danger btn-sm w-100 edit-remove-product-btn"><i class="bi bi-trash"></i></button></div></div></div>';
}
function addEditProductRow(product) {
    var container = document.getElementById('editProductsContainer');
    if (!container) return;
    var row = document.createElement('div');
    row.innerHTML = buildEditProductRow(editProductIndex, product);
    row = row.firstElementChild;
    container.appendChild(row);
    row.querySelector('.edit-remove-product-btn').addEventListener('click', function() {
        var rows = container.querySelectorAll('.edit-product-row');
        if (rows.length > 1) row.remove();
    });
    editProductIndex++;
}
window.closeEditTaskCard = function() {
    var collapse = document.getElementById('editTaskFormCollapse');
    if (collapse) {
        var bs = bootstrap.Collapse.getInstance(collapse);
        if (bs) bs.hide();
    }
};
window.openEditTaskModal = function(taskId) {
    var createCollapse = document.getElementById('createTaskFormCollapse');
    var editCollapse = document.getElementById('editTaskFormCollapse');
    if (!editCollapse) return;
    if (createCollapse) {
        var createBs = bootstrap.Collapse.getInstance(createCollapse);
        if (createBs) createBs.hide();
    }
    document.getElementById('editTaskId').value = taskId;
    var container = document.getElementById('editProductsContainer');
    if (container) container.innerHTML = '';
    editProductIndex = 0;
    var url = new URL(window.location.href);
    url.searchParams.set('action', 'get_task_for_edit');
    url.searchParams.set('task_id', String(taskId));
    fetch(url.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.task) {
                var t = data.task;
                document.getElementById('editTaskType').value = t.task_type || 'shop_order';
                document.getElementById('editPriority').value = t.priority || 'normal';
                document.getElementById('editDueDate').value = t.due_date || '';
                document.getElementById('editCustomerName').value = t.customer_name || '';
                document.getElementById('editCustomerPhone').value = t.customer_phone || '';
                document.getElementById('editDetails').value = t.details || '';
                var assignSelect = document.getElementById('editAssignedTo');
                if (assignSelect) {
                    var assigneeIds = (t.assignees || []).map(function(a) { return parseInt(a, 10); });
                    for (var i = 0; i < assignSelect.options.length; i++) {
                        assignSelect.options[i].selected = assigneeIds.indexOf(parseInt(assignSelect.options[i].value, 10)) >= 0;
                    }
                }
                var products = Array.isArray(t.products) ? t.products : [];
                if (products.length === 0) products = [{}];
                products.forEach(function(p) {
                    var row = { name: p.name || '', quantity: p.quantity, unit: p.unit || 'قطعة', price: p.price };
                    addEditProductRow(row);
                });
            }
        })
        .catch(function() { addEditProductRow({}); });
    var editBs = bootstrap.Collapse.getInstance(editCollapse) || new bootstrap.Collapse(editCollapse, { toggle: false });
    editBs.show();
    setTimeout(function() { editCollapse.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 100);
};
document.addEventListener('DOMContentLoaded', function() {
    var editAddBtn = document.getElementById('editAddProductBtn');
    if (editAddBtn) {
        editAddBtn.addEventListener('click', function() { addEditProductRow({}); });
    }
});

window.openOrderReceiptModal = function(orderId) {
    var modalEl = document.getElementById('orderReceiptModal');
    var loadingEl = document.getElementById('orderReceiptLoading');
    var bodyEl = document.getElementById('orderReceiptBody');
    if (!modalEl || !loadingEl || !bodyEl) return;
    loadingEl.style.display = 'block';
    bodyEl.style.display = 'none';
    bodyEl.innerHTML = '';
    var modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInstance.show();
    var params = new URLSearchParams(window.location.search);
    params.set('get_order_receipt', '1');
    params.set('order_id', String(orderId));
    fetch('?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loadingEl.style.display = 'none';
            if (data.success && data.order) {
                var o = data.order;
                var items = data.items || [];
                var total = typeof o.total_amount === 'number' ? o.total_amount : parseFloat(o.total_amount) || 0;
                var rows = items.map(function(it) {
                    var qty = typeof it.quantity === 'number' ? it.quantity : parseFloat(it.quantity) || 0;
                    var un = (it.unit || 'قطعة').trim();
                    return '<tr><td>' + (it.product_name || '-') + '</td><td class="text-end">' + qty + ' ' + un + '</td></tr>';
                }).join('');
                bodyEl.innerHTML =
                    '<div class="border rounded p-3 mb-3 bg-light"><h6 class="mb-2">بيانات الطلب</h6>' +
                    '<p class="mb-1"><strong>رقم الأوردر:</strong> ' + (o.order_number || '-') + '</p>' +
                    '<p class="mb-1"><strong>العميل:</strong> ' + (o.customer_name || '-') + '</p>' +
                    (o.customer_phone ? '<p class="mb-1"><strong>الهاتف:</strong> ' + o.customer_phone + '</p>' : '') +
                    (o.customer_address ? '<p class="mb-1"><strong>العنوان:</strong> ' + o.customer_address + '</p>' : '') +
                    '<p class="mb-1"><strong>تاريخ الطلب:</strong> ' + (o.order_date || '-') + '</p>' +
                    (o.delivery_date ? '<p class="mb-1"><strong>تاريخ التسليم:</strong> ' + o.delivery_date + '</p>' : '') +
                    '</div>' +
                    '<h6 class="mb-2">تفاصيل المنتجات</h6>' +
                    '<table class="table table-sm table-bordered"><thead><tr><th>المنتج</th><th class="text-end">الكمية</th></tr></thead><tbody>' + rows + '</tbody><tfoot><tr><td class="text-end fw-bold" colspan="2">الإجمالي: ' + total.toFixed(2) + ' ر.س</td></tr></tfoot></table>' +
                    (o.notes ? '<p class="mt-2 text-muted small mb-0"><strong>ملاحظات:</strong> ' + o.notes + '</p>' : '');
                bodyEl.style.display = 'block';
            } else {
                bodyEl.innerHTML = '<p class="text-muted mb-0">الطلب غير موجود أو لا يمكن تحميل التفاصيل.</p>';
                bodyEl.style.display = 'block';
            }
        })
        .catch(function() {
            loadingEl.style.display = 'none';
            bodyEl.innerHTML = '<p class="text-danger mb-0">حدث خطأ أثناء تحميل تفاصيل الأوردر.</p>';
            bodyEl.style.display = 'block';
        });
};

document.addEventListener('DOMContentLoaded', function () {
    const taskTypeSelect = document.getElementById('taskTypeSelect');
    const titleInput = document.querySelector('input[name="title"]');
    const productWrapper = document.getElementById('productFieldWrapper');
    const quantityWrapper = document.getElementById('quantityFieldWrapper');
    const productNameInput = document.getElementById('productNameInput');
    const quantityInput = document.getElementById('productQuantityInput');
    const templateSuggestions = document.getElementById('templateSuggestions');

    // خانة العميل: عميل محلي / عميل مندوب — اسم من البحث (أو المُدخل) ورقم العميل ظاهر دائماً ويُملأ تلقائياً عند اختيار عميل مسجل
    (function initCustomerCardTask() {
        var localCustomers = (typeof __localCustomersForTask !== 'undefined' && Array.isArray(__localCustomersForTask)) ? __localCustomersForTask : [];
        var repCustomers = (typeof __repCustomersForTask !== 'undefined' && Array.isArray(__repCustomersForTask)) ? __repCustomersForTask : [];
        var submitName = document.getElementById('submit_customer_name');
        var submitPhone = document.getElementById('submit_customer_phone');
        var localSearch = document.getElementById('local_customer_search_task');
        var localId = document.getElementById('local_customer_id_task');
        var localDrop = document.getElementById('local_customer_dropdown_task');
        var repSearch = document.getElementById('rep_customer_search_task');
        var repId = document.getElementById('rep_customer_id_task');
        var repDrop = document.getElementById('rep_customer_dropdown_task');
        if (!submitName || !submitPhone) return;

        // نفس منطق matchSearch في صفحة الأسعار المخصصة: عند الفراغ نعرض الكل، وإلا بحث بسيط (نص يحتوي على الاستعلام)
        function matchSearch(text, q) {
            if (!q || !text) return true;
            var t = (text + '').toLowerCase();
            var k = (q + '').trim().toLowerCase();
            return t.indexOf(k) !== -1;
        }
        // للعميل المحلي: نفس سلوك custom_prices — البحث في الاسم (والهاتف اختياري)
        function matchLocalCustomer(c, query) {
            var q = (query + '').trim();
            if (!q) return true;
            var name = (c.name || '') + '';
            var extra = (c.phones && c.phones.length) ? c.phones.join(' ') : ((c.phone || '') + '');
            var text = (name + ' ' + extra).trim();
            return matchSearch(text, q);
        }
        // لعميل المندوب: البحث في الاسم + اسم المندوب + الهاتف
        function matchRepCustomer(c, query) {
            var q = (query + '').trim();
            if (!q) return true;
            var text = (c.name || '') + ' ' + (c.rep_name || '') + ' ' + (c.phone || '');
            return matchSearch(text, q);
        }

        function setCustomerBlocks() {
            var v = document.querySelector('input[name="customer_type_radio_task"]:checked');
            var val = v ? v.value : 'local';
            document.getElementById('customer_select_local_task').classList.toggle('d-none', val !== 'local');
            document.getElementById('customer_select_rep_task').classList.toggle('d-none', val !== 'rep');
            if (val !== 'local') {
                if (localSearch) localSearch.value = '';
                if (localId) localId.value = '';
                if (localDrop) localDrop.classList.add('d-none');
            }
            if (val !== 'rep') {
                if (repSearch) repSearch.value = '';
                if (repId) repId.value = '';
                if (repDrop) repDrop.classList.add('d-none');
            }
        }

        document.querySelectorAll('input[name="customer_type_radio_task"]').forEach(function(r) {
            r.addEventListener('change', setCustomerBlocks);
        });
        setCustomerBlocks();

        // إخفاء أي بقايا لخانة العميل اليدوي (إن وُجدت من كاش قديم)
        (function hideManualCustomerBlock() {
            var manualBlock = document.getElementById('customer_manual_block_task');
            if (manualBlock) { manualBlock.style.display = 'none'; manualBlock.remove(); }
            document.querySelectorAll('input[name="customer_type_radio_task"][value="manual"]').forEach(function(r) {
                var wrap = r.closest('.form-check');
                if (wrap) wrap.style.display = 'none';
            });
        })();

        function showCustomerDropdown(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher, onSelect) {
            if (!inputEl || !dropEl) return;
            var q = (inputEl.value || '').trim();
            var filterFn = (typeof matcher === 'function') ? function(c) { return matcher(c, q); } : function(c) { return matchSearch(getLabel(c), q); };
            var filtered = list.filter(filterFn);
            dropEl.innerHTML = '';
            if (filtered.length === 0) {
                dropEl.classList.add('d-none');
                return;
            }
            filtered.forEach(function(c) {
                var div = document.createElement('div');
                div.className = 'search-dropdown-item-task';
                div.textContent = getLabel(c);
                div.dataset.id = c.id;
                div.dataset.name = c.name;
                div.dataset.phone = (c.phone || '').toString();
                div.addEventListener('click', function() {
                    if (hiddenIdEl) hiddenIdEl.value = this.dataset.id;
                    inputEl.value = this.dataset.name;
                    submitName.value = this.dataset.name || '';
                    submitPhone.value = this.dataset.phone || '';
                    dropEl.classList.add('d-none');
                    if (onSelect) onSelect(c);
                });
                dropEl.appendChild(div);
            });
            dropEl.classList.remove('d-none');
        }

        function initCustomerSearch(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher) {
            if (!inputEl || !dropEl) return;
            function show() { showCustomerDropdown(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher); }
            inputEl.addEventListener('input', function() {
                if (hiddenIdEl) hiddenIdEl.value = '';
                show();
            });
            inputEl.addEventListener('focus', function() {
                show();
            });
        }

        initCustomerSearch(localSearch, localId, localDrop, localCustomers, function(c) { return c.name + (c.phone ? ' — ' + c.phone : ''); }, matchLocalCustomer);
        initCustomerSearch(repSearch, repId, repDrop, repCustomers, function(c) { return c.rep_name ? c.name + ' (' + c.rep_name + ')' : c.name; }, matchRepCustomer);

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-wrap')) {
                document.querySelectorAll('.search-dropdown-task').forEach(function(d) { d.classList.add('d-none'); });
            }
        });

        // عند الإرسال: أخذ اسم العميل من حقل البحث النشط (محلي أو مندوب) — إن لم يُختر من القائمة يُرسل النص المُدخل ويُحفظ كعميل جديد في الخادم إن لم يكن مسجلاً
        var form = submitName && submitName.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                var v = document.querySelector('input[name="customer_type_radio_task"]:checked');
                var val = v ? v.value : 'local';
                if (val === 'local' && localSearch) {
                    submitName.value = localSearch.value.trim();
                } else if (val === 'rep' && repSearch) {
                    submitName.value = repSearch.value.trim();
                }
            });
        }
    })();

    function updateTaskTypeUI() {
        if (!titleInput) {
            // continue to toggle other fields even إن لم يوجد العنوان
        }
        const isProduction = taskTypeSelect && taskTypeSelect.value === 'production';
        if (titleInput) {
            titleInput.placeholder = isProduction
                ? '.'
                : 'مثال: تنظيف خط الإنتاج';
        }
    }

    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', updateTaskTypeUI);
    }
    updateTaskTypeUI();

    // تحميل أسماء القوالب وتعبئة datalist
    function loadTemplateSuggestions() {
        if (!templateSuggestions) {
            return;
        }

        // الحصول على base path بشكل صحيح
        function getApiPath(endpoint) {
            const currentPath = window.location.pathname || '/';
            const pathParts = currentPath.split('/').filter(Boolean);
            const stopSegments = ['dashboard', 'modules', 'api', 'assets', 'includes'];
            const baseParts = [];

            for (const part of pathParts) {
                if (stopSegments.includes(part) || part.endsWith('.php')) {
                    break;
                }
                baseParts.push(part);
            }

            const basePath = baseParts.length ? '/' + baseParts.join('/') : '';
            const apiPath = (basePath + '/api/' + endpoint).replace(/\/+/g, '/');
            return apiPath.startsWith('/') ? apiPath : '/' + apiPath;
        }

        const apiUrl = getApiPath('get_product_templates.php');

        fetch(apiUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success && Array.isArray(data.templates)) {
                    // مسح القائمة الحالية
                    templateSuggestions.innerHTML = '';
                    
                    // إضافة الخيارات
                    data.templates.forEach(templateName => {
                        const option = document.createElement('option');
                        option.value = templateName;
                        templateSuggestions.appendChild(option);
                    });
                }
            })
            .catch(error => {
                console.error('Error loading template suggestions:', error);
            });
    }

    // تحميل الاقتراحات عند تحميل الصفحة
    loadTemplateSuggestions();
    
    // إدارة المنتجات المتعددة
    const productsContainer = document.getElementById('productsContainer');
    const addProductBtn = document.getElementById('addProductBtn');
    let productIndex = 1;
    
    function updateRemoveButtons() {
        const productRows = productsContainer.querySelectorAll('.product-row');
        productRows.forEach((row, index) => {
            const removeBtn = row.querySelector('.remove-product-btn');
            if (productRows.length > 1) {
                removeBtn.style.display = 'block';
            } else {
                removeBtn.style.display = 'none';
            }
        });
    }
    
    function addProductRow() {
        const newRow = document.createElement('div');
        newRow.className = 'product-row mb-3 p-3 border rounded';
        newRow.setAttribute('data-product-index', productIndex);
        newRow.innerHTML = `
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small">اسم المنتج</label>
                    <input type="text" class="form-control product-name-input" name="products[${productIndex}][name]" placeholder="أدخل اسم المنتج أو القالب" list="templateSuggestions" autocomplete="off">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">الكمية</label>
                    <input type="number" class="form-control product-quantity-input" name="products[${productIndex}][quantity]" step="1" min="0" placeholder="مثال: 120" id="product-quantity-${productIndex}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">الوحدة</label>
                    <select class="form-select form-select-sm product-unit-input" name="products[${productIndex}][unit]" id="product-unit-${productIndex}" onchange="updateQuantityStep(${productIndex})">
                        <option value="كرتونة">كرتونة</option>
                        <option value="عبوة">عبوة</option>
                        <option value="كيلو">كيلو</option>
                        <option value="جرام">جرام</option>
                        <option value="شرينك">شرينك</option>
                        <option value="قطعة" selected>قطعة</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">السعر</label>
                    <input type="number" class="form-control product-price-input" name="products[${productIndex}][price]" step="0.01" min="0" placeholder="0.00" id="product-price-${productIndex}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">الإجمالي (قابل للتحكم)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control product-line-total-input" name="products[${productIndex}][line_total]" step="0.01" min="0" placeholder="0.00" id="product-line-total-${productIndex}" title="الإجمالي = الكمية × السعر حسب الوحدة">
                        <span class="input-group-text">ج.م</span>
                    </div>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-danger btn-sm w-100 remove-product-btn">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
        productsContainer.appendChild(newRow);
        productIndex++;
        updateRemoveButtons();
        
        // إضافة مستمع الحدث لزر الحذف
        newRow.querySelector('.remove-product-btn').addEventListener('click', function() {
            newRow.remove();
            updateRemoveButtons();
            if (typeof updateCreateTaskSummary === 'function') updateCreateTaskSummary();
        });
        if (typeof updateCreateTaskSummary === 'function') updateCreateTaskSummary();
    }
    
    // إضافة منتج جديد
    if (addProductBtn) {
        addProductBtn.addEventListener('click', addProductRow);
    }
    
    // إضافة مستمعات الأحداث لأزرار الحذف الموجودة
    productsContainer.querySelectorAll('.remove-product-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.product-row').remove();
            updateRemoveButtons();
        });
    });
    
    // تحديث حالة أزرار الحذف عند التحميل
    updateRemoveButtons();
    
    // تحديث step للكمية بناءً على الوحدة المختارة عند التحميل
    document.querySelectorAll('.product-unit-input').forEach(function(unitSelect) {
        const index = unitSelect.id.replace('product-unit-', '');
        updateQuantityStep(index);
    });
    
    // حساب الإجمالي تلقائياً: الإجمالي = الكمية × السعر (حسب الوحدة والكمية)
    function updateProductLineTotal(row) {
        const qtyInput = row.querySelector('.product-quantity-input');
        const priceInput = row.querySelector('.product-price-input');
        const totalInput = row.querySelector('.product-line-total-input');
        if (!qtyInput || !priceInput || !totalInput) return;
        const qty = parseFloat(qtyInput.value || '0');
        const price = parseFloat(priceInput.value || '0');
        const total = qty * price;
        totalInput.value = total > 0 ? total.toFixed(2) : '';
    }
    
    function syncPriceFromLineTotal(row) {
        const qtyInput = row.querySelector('.product-quantity-input');
        const priceInput = row.querySelector('.product-price-input');
        const totalInput = row.querySelector('.product-line-total-input');
        if (!qtyInput || !priceInput || !totalInput) return;
        const qty = parseFloat(qtyInput.value || '0');
        const totalVal = parseFloat(totalInput.value || '0');
        if (qty > 0 && totalVal >= 0) {
            priceInput.value = (totalVal / qty).toFixed(2);
        }
    }
    
    productsContainer.addEventListener('input', function(e) {
        const row = e.target.closest('.product-row');
        if (!row) return;
        if (e.target.classList.contains('product-quantity-input') || e.target.classList.contains('product-price-input')) {
            updateProductLineTotal(row);
        } else if (e.target.classList.contains('product-line-total-input')) {
            syncPriceFromLineTotal(row);
        }
        updateCreateTaskSummary();
    });
    
    // تحديث الإجمالي للصفوف الموجودة عند التحميل
    productsContainer.querySelectorAll('.product-row').forEach(updateProductLineTotal);
    
    // ملخص الإجمالي النهائي (إجمالي المنتجات + رسوم الشحن)
    function updateCreateTaskSummary() {
        var subtotalEl = document.getElementById('createTaskSubtotalDisplay');
        var shippingEl = document.getElementById('createTaskShippingDisplay');
        var finalEl = document.getElementById('createTaskFinalTotalDisplay');
        var shippingInput = document.getElementById('createTaskShippingFees');
        if (!subtotalEl || !shippingEl || !finalEl) return;
        var subtotal = 0;
        productsContainer.querySelectorAll('.product-line-total-input').forEach(function(inp) {
            var v = parseFloat(inp.value || '0');
            if (!isNaN(v) && v >= 0) subtotal += v;
        });
        var shipping = 0;
        if (shippingInput) {
            var v = parseFloat(shippingInput.value || '0');
            if (!isNaN(v) && v >= 0) shipping = v;
        }
        var finalTotal = subtotal + shipping;
        subtotalEl.textContent = subtotal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
        shippingEl.textContent = shipping.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
        finalEl.textContent = finalTotal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    }
    if (document.getElementById('createTaskShippingFees')) {
        document.getElementById('createTaskShippingFees').addEventListener('input', updateCreateTaskSummary);
        document.getElementById('createTaskShippingFees').addEventListener('change', updateCreateTaskSummary);
    }
    updateCreateTaskSummary();
});

// دالة لتحديث step حقل الكمية بناءً على الوحدة المختارة
function updateQuantityStep(index) {
    const unitSelect = document.getElementById('product-unit-' + index);
    const quantityInput = document.getElementById('product-quantity-' + index);
    
    if (!unitSelect || !quantityInput) {
        return;
    }
    
    const selectedUnit = unitSelect.value;
    // الوحدات التي يجب أن تكون أرقام صحيحة فقط
    const integerUnits = ['كيلو', 'قطعة', 'جرام'];
    const mustBeInteger = integerUnits.includes(selectedUnit);
    
    if (mustBeInteger) {
        quantityInput.step = '1';
        quantityInput.setAttribute('step', '1');
        // تحويل القيمة الحالية إلى رقم صحيح إذا كانت عشرية
        if (quantityInput.value && quantityInput.value.includes('.')) {
            quantityInput.value = Math.round(parseFloat(quantityInput.value));
        }
    } else {
        quantityInput.step = '0.01';
        quantityInput.setAttribute('step', '0.01');
    }
}

// طباعة تلقائية للإيصال بعد إنشاء المهمة بنجاح
(function() {
    'use strict';
    
    // التحقق من وجود معلومات الطباعة في session
    <?php if (isset($_SESSION['print_task_id']) && isset($_SESSION['print_task_url'])): ?>
    const printTaskId = <?php echo (int)$_SESSION['print_task_id']; ?>;
    const printTaskUrl = <?php echo json_encode($_SESSION['print_task_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    
    // فتح نافذة الطباعة تلقائياً
    if (printTaskId > 0 && printTaskUrl) {
        // فتح نافذة جديدة للطباعة
        const printWindow = window.open(printTaskUrl, '_blank', 'width=400,height=600');
        
        // بعد فتح النافذة، مسح معلومات الطباعة من session
        // سيتم مسحها عند إعادة تحميل الصفحة
        <?php 
        unset($_SESSION['print_task_id']);
        unset($_SESSION['print_task_url']);
        ?>
    }
    <?php endif; ?>
})();

// دالة لفتح modal تغيير الحالة - يجب أن تكون في النطاق العام
window.openChangeStatusModal = function(taskId, currentStatus) {
    function ensureChangeStatusCardExists() {
        const existingCollapse = document.getElementById('changeStatusCardCollapse');
        if (existingCollapse) {
            // تأكد أن البطاقة كاملة (كل العناصر الداخلية موجودة)
            const taskIdInput = existingCollapse.querySelector('#changeStatusCardTaskId');
            const currentStatusDisplay = existingCollapse.querySelector('#currentStatusCardDisplay');
            const newStatusSelect = existingCollapse.querySelector('#newStatusCard');
            if (taskIdInput && currentStatusDisplay && newStatusSelect) {
                return true;
            }
            // بطاقة ناقصة (مثلاً بعد تنقل AJAX) — أزل الوعاء وأعد الإنشاء
            const wrapper = existingCollapse.closest('.container-fluid') || existingCollapse.parentElement;
            if (wrapper && wrapper.parentNode) {
                wrapper.remove();
            }
        }

        const host = document.querySelector('main') || document.getElementById('main-content') || document.body;
        if (!host) {
            return false;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'container-fluid px-0';
        wrapper.innerHTML = `
            <div class="collapse" id="changeStatusCardCollapse">
                <div class="card shadow-sm border-info mb-3">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-gear me-2"></i>تغيير حالة الطلب
                        </h5>
                        <button type="button" class="btn btn-sm btn-light" onclick="closeChangeStatusCard()" aria-label="إغلاق">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <form method="POST" id="changeStatusCardForm" action="">
                        <input type="hidden" name="action" value="update_task_status">
                        <input type="hidden" name="task_id" id="changeStatusCardTaskId">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">الحالة الحالية</label>
                                <div id="currentStatusCardDisplay" class="alert alert-info mb-0"></div>
                            </div>
                            <div class="mb-3">
                                <label for="newStatusCard" class="form-label fw-bold">اختر الحالة الجديدة <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" id="newStatusCard" required>
                                    <option value="">-- اختر الحالة --</option>
                                    <option value="pending">معلقة</option>
                                    <option value="completed">مكتملة</option>
                                    <option value="with_delegate">مع المندوب</option>
                                    <option value="delivered">تم التوصيل</option>
                                    <option value="returned">تم الارجاع</option>
                                    <option value="cancelled">ملغاة</option>
                                </select>
                                <div class="form-text">سيتم تحديث حالة الطلب فوراً بعد الحفظ.</div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-secondary w-50" onclick="closeChangeStatusCard()">
                                    <i class="bi bi-x-circle me-1"></i>إلغاء
                                </button>
                                <button type="submit" class="btn btn-info w-50">
                                    <i class="bi bi-check-circle me-1"></i>حفظ
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        `;

        // ضعه في نهاية main ليكون داخل المحتوى المعروض
        host.appendChild(wrapper);
        return !!document.getElementById('changeStatusCardCollapse');
    }

    function openChangeStatusCard(taskIdInner, currentStatusInner, retryCount = 0) {
        // في بعض حالات AJAX navigation قد لا تكون عناصر البطاقة موجودة
        if (!ensureChangeStatusCardExists()) {
            console.error('Failed to create change status card');
            return false;
        }

        const collapseEl = document.getElementById('changeStatusCardCollapse');
        if (!collapseEl) {
            if (retryCount < 3) {
                setTimeout(() => openChangeStatusCard(taskIdInner, currentStatusInner, retryCount + 1), 80);
                return false;
            }
            console.error('Change status card elements not found after retries');
            return false;
        }

        // استخراج العناصر من داخل نفس البطاقة لتجنب تداخل IDs أو بطاقة ناقصة
        const taskIdInput = collapseEl.querySelector('#changeStatusCardTaskId');
        const currentStatusDisplay = collapseEl.querySelector('#currentStatusCardDisplay');
        const newStatusSelect = collapseEl.querySelector('#newStatusCard');

        if (!taskIdInput || !currentStatusDisplay || !newStatusSelect) {
            if (retryCount < 3) {
                setTimeout(() => openChangeStatusCard(taskIdInner, currentStatusInner, retryCount + 1), 80);
                return false;
            }
            console.error('Change status card elements not found after retries');
            return false;
        }

        // تعيين معرف المهمة
        taskIdInput.value = taskIdInner;

        const statusLabels = {
            'pending': 'معلقة',
            'received': 'مستلمة',
            'completed': 'مكتملة',
            'with_delegate': 'مع المندوب',
            'delivered': 'تم التوصيل',
            'returned': 'تم الارجاع',
            'cancelled': 'ملغاة'
        };

        const statusClasses = {
            'pending': 'warning',
            'received': 'info',
            'completed': 'success',
            'with_delegate': 'info',
            'delivered': 'success',
            'returned': 'secondary',
            'cancelled': 'danger'
        };

        const currentStatusLabel = statusLabels[currentStatusInner] || currentStatusInner;
        const currentStatusClass = statusClasses[currentStatusInner] || 'secondary';

        currentStatusDisplay.className = 'alert alert-' + currentStatusClass + ' mb-0';
        currentStatusDisplay.innerHTML = '<strong>الحالة الحالية:</strong> <span class="badge bg-' + currentStatusClass + '">' + currentStatusLabel + '</span>';

        // إعادة تعيين القائمة المنسدلة
        newStatusSelect.value = '';

        // فتح البطاقة (collapse)
        const collapse = bootstrap.Collapse.getInstance(collapseEl) || new bootstrap.Collapse(collapseEl, { toggle: false });
        collapse.show();

        // سكرول للبطاقة لسهولة الاستخدام على الهاتف
        setTimeout(() => {
            collapseEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);

        return true;
    }

    // على الموبايل نعرض البطاقة بدل المودال
    const isMobile = !!(window.matchMedia && (
        window.matchMedia('(max-width: 768px)').matches ||
        window.matchMedia('(pointer: coarse)').matches
    ));
    if (isMobile) {
        openChangeStatusCard(taskId, currentStatus);
        return;
    }

    const modalElement = document.getElementById('changeStatusModal');
    if (!modalElement) {
        // fallback: لو المودال غير موجود لأي سبب (AJAX navigation)، افتح البطاقة
        openChangeStatusCard(taskId, currentStatus);
        return;
    }
    
    const modal = new bootstrap.Modal(modalElement);
    const taskIdInput = document.getElementById('changeStatusTaskId');
    const currentStatusDisplay = document.getElementById('currentStatusDisplay');
    const newStatusSelect = document.getElementById('newStatus');
    
    if (!taskIdInput || !currentStatusDisplay || !newStatusSelect) {
        // fallback: لو عناصر المودال ناقصة (بسبب استبدال المحتوى بالـAJAX)، افتح البطاقة
        openChangeStatusCard(taskId, currentStatus);
        return;
    }
    
    // تعيين معرف المهمة
    taskIdInput.value = taskId;
    
    // عرض الحالة الحالية
    const statusLabels = {
        'pending': 'معلقة',
        'received': 'مستلمة',
        'completed': 'مكتملة',
        'with_delegate': 'مع المندوب',
        'delivered': 'تم التوصيل',
        'returned': 'تم الارجاع',
        'cancelled': 'ملغاة'
    };
    
    const statusClasses = {
        'pending': 'warning',
        'received': 'info',
        'completed': 'success',
        'with_delegate': 'info',
        'delivered': 'success',
        'returned': 'secondary',
        'cancelled': 'danger'
    };
    
    const currentStatusLabel = statusLabels[currentStatus] || currentStatus;
    const currentStatusClass = statusClasses[currentStatus] || 'secondary';
    
    currentStatusDisplay.className = 'alert alert-' + currentStatusClass + ' mb-0';
    currentStatusDisplay.innerHTML = '<strong>الحالة الحالية:</strong> <span class="badge bg-' + currentStatusClass + '">' + currentStatusLabel + '</span>';
    
    // إعادة تعيين القائمة المنسدلة
    newStatusSelect.value = '';
    
    // فتح الـ modal
    modal.show();
};

// إغلاق بطاقة تغيير الحالة (موبايل)
window.closeChangeStatusCard = function() {
    const collapseEl = document.getElementById('changeStatusCardCollapse');
    if (!collapseEl) {
        return;
    }
    const collapse = bootstrap.Collapse.getInstance(collapseEl) || new bootstrap.Collapse(collapseEl, { toggle: false });
    collapse.hide();
};

// تحديد أوردرات متعددة للطباعة
(function() {
    var selectAll = document.getElementById('selectAllTasks');
    var checkboxes = document.querySelectorAll('.task-print-checkbox');
    var printBtn = document.getElementById('printSelectedReceiptsBtn');
    var selectedCountEl = document.getElementById('selectedCount');
    if (!checkboxes.length) return;

    function updateSelection() {
        var checked = document.querySelectorAll('.task-print-checkbox:checked');
        var n = checked.length;
        if (selectedCountEl) selectedCountEl.textContent = n;
        if (printBtn) printBtn.disabled = n === 0;
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
            updateSelection();
        });
    }
    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', updateSelection);
    });

    if (printBtn) {
        printBtn.addEventListener('click', function() {
            var checked = document.querySelectorAll('.task-print-checkbox:checked');
            var ids = [];
            checked.forEach(function(cb) {
                var id = cb.value;
                if (id) ids.push(id);
            });
            if (ids.length === 0) return;
            var firstUrl = document.querySelector('.task-print-checkbox') && document.querySelector('.task-print-checkbox').getAttribute('data-print-url');
            var path = firstUrl ? firstUrl.split('?')[0] : 'print_task_receipt.php';
            var url = path + '?ids=' + ids.join(',');
            window.open(url, '_blank', 'noopener,noreferrer');
        });
    }
    updateSelection();
})();

// لا حاجة لإعادة التحميل التلقائي - preventDuplicateSubmission يتولى ذلك
</script>


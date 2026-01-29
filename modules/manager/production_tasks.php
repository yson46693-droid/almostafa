<?php
/**
 * صفحة إرسال المهام لقسم الإنتاج
 */

// تعيين ترميز UTF-8
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    // إضافة headers لمنع cache لضمان عرض البيانات المحدثة
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
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
              `status` enum('pending','received','in_progress','completed','cancelled') DEFAULT 'pending',
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

$allowedTypes = ['general', 'production', 'quality', 'maintenance'];
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_production_task') {
        $taskType = $_POST['task_type'] ?? 'general';
        $taskType = in_array($taskType, $allowedTypes, true) ? $taskType : 'general';

        $title = trim($_POST['title'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $priority = $_POST['priority'] ?? 'normal';
        $priority = in_array($priority, $allowedPriorities, true) ? $priority : 'normal';
        $dueDate = $_POST['due_date'] ?? '';
        $customerName = trim($_POST['customer_name'] ?? '');
        $assignees = $_POST['assigned_to'] ?? [];
        
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
                $products[] = [
                    'name' => $productName,
                    'quantity' => $productQuantity,
                    'unit' => $productUnit,
                    'price' => $productPrice
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

                $relatedTypeValue = 'manager_' . $taskType;

                if ($title === '') {
                    $title = $taskType === 'production' ? 'مهمة إنتاج جديدة' : 'مهمة جديدة';
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
                
                // حفظ اسم العميل
                if (!empty($customerName)) {
                    $columns[] = 'customer_name';
                    $values[] = $customerName;
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

                // استخدام preventDuplicateSubmission لإعادة التوجيه مع cache-busting
                // إضافة timestamp فريد لضمان تحديث الصفحة مباشرة
                $successMessage = 'تم إرسال المهمة بنجاح إلى ' . count($assignees) . ' من عمال الإنتاج.';
                // تحديد role بناءً على المستخدم الحالي
                $userRole = ($currentUser['role'] ?? '') === 'accountant' ? 'accountant' : 'manager';
                preventDuplicateSubmission($successMessage, ['page' => 'production_tasks', '_refresh' => time()], null, $userRole);
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
        } elseif (!in_array($newStatus, ['pending', 'received', 'in_progress', 'completed', 'cancelled'], true)) {
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
                if ($newStatus === 'completed') {
                    $updateFields[] = 'completed_at = NOW()';
                } elseif ($newStatus === 'received') {
                    $updateFields[] = 'received_at = NOW()';
                } elseif ($newStatus === 'in_progress') {
                    $updateFields[] = 'started_at = NOW()';
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
    }
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
        $stats['total'] += (int)$row['total'];
    }
} catch (Exception $e) {
    error_log('Manager task stats error: ' . $e->getMessage());
}

$recentTasks = [];
$statusStyles = [
    'pending' => ['class' => 'warning', 'label' => 'معلقة'],
    'received' => ['class' => 'info', 'label' => 'مستلمة'],
    'in_progress' => ['class' => 'primary', 'label' => 'قيد التنفيذ'],
    'completed' => ['class' => 'success', 'label' => 'مكتملة'],
    'cancelled' => ['class' => 'danger', 'label' => 'ملغاة']
];

$priorityStyles = [
    'low' => ['class' => 'secondary', 'label' => 'منخفضة'],
    'normal' => ['class' => 'info', 'label' => 'عادية'],
    'high' => ['class' => 'warning', 'label' => 'مرتفعة'],
    'urgent' => ['class' => 'danger', 'label' => 'عاجلة']
];

try {
    // جلب المهام المحدثة مباشرة - المحاسب والمدير يرون جميع المهام التي أنشأها أي منهما
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
            $recentTasks = $db->query("
                SELECT t.id, t.title, t.status, t.priority, t.due_date, t.created_at,
                       t.quantity, t.unit, t.customer_name, t.notes, t.product_id, u.full_name AS assigned_name, t.assigned_to,
                       uCreator.full_name AS creator_name, t.created_by
                FROM tasks t
                LEFT JOIN users u ON t.assigned_to = u.id
                LEFT JOIN users uCreator ON t.created_by = uCreator.id
                WHERE t.created_by IN ($placeholders)
                AND t.status != 'cancelled'
                ORDER BY t.created_at DESC, t.id DESC
                LIMIT 10
            ", $adminIds);
        } else {
            $recentTasks = [];
        }
    } else {
        // للمستخدمين الآخرين، عرض المهام التي أنشأوها فقط
        $recentTasks = $db->query("
            SELECT t.id, t.title, t.status, t.priority, t.due_date, t.created_at,
                   t.quantity, t.unit, t.customer_name, t.notes, t.product_id, u.full_name AS assigned_name, t.assigned_to
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.created_by = ?
            AND t.status != 'cancelled'
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT 10
        ", [$currentUser['id']]);
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

?>

<script>
// إجبار تحديث الصفحة عند تحميلها بعد redirect لمنع cache
(function() {
    'use strict';
    
    // التحقق من وجود معامل _refresh في URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('_refresh')) {
        // إزالة معامل _refresh من URL بدون إعادة تحميل
        urlParams.delete('_refresh');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, '', newUrl);
    }
})();
</script>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="bi bi-list-task me-2"></i>إرسال مهام لقسم الإنتاج</h2>
            <p class="text-muted mb-0">قم بإنشاء مهام موجهة لعمال الإنتاج مع تتبّع الحالة في صفحة المهام الخاصة بهم.</p>
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
            <div class="card border-primary h-100">
                <div class="card-body text-center py-2 px-2">
                    <div class="text-muted small mb-1">إجمالي المهام</div>
                    <div class="fs-5 text-primary fw-semibold"><?php echo $stats['total']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <div class="card border-warning h-100">
                <div class="card-body text-center py-2 px-2">
                    <div class="text-muted small mb-1">معلقة</div>
                    <div class="fs-5 text-warning fw-semibold"><?php echo $stats['pending']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <div class="card border-info h-100">
                <div class="card-body text-center py-2 px-2">
                    <div class="text-muted small mb-1">مستلمة</div>
                    <div class="fs-5 text-info fw-semibold"><?php echo $stats['received']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <div class="card border-info h-100">
                <div class="card-body text-center py-2 px-2">
                    <div class="text-muted small mb-1">قيد التنفيذ</div>
                    <div class="fs-5 text-info fw-semibold"><?php echo $stats['in_progress']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <div class="card border-success h-100">
                <div class="card-body text-center py-2 px-2">
                    <div class="text-muted small mb-1">مكتملة</div>
                    <div class="fs-5 text-success fw-semibold"><?php echo $stats['completed']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-4 col-sm-4 col-md-2">
            <div class="card border-danger h-100">
                <div class="card-body text-center py-2 px-2">
                    <div class="text-muted small mb-1">ملغاة</div>
                    <div class="fs-5 text-danger fw-semibold"><?php echo $stats['cancelled']; ?></div>
                </div>
            </div>
        </div>
    </div>

    <button class="btn btn-primary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#createTaskFormCollapse" aria-expanded="false" aria-controls="createTaskFormCollapse">
        <i class="bi bi-plus-circle me-1"></i>إنشاء مهمة جديدة
    </button>

    <div class="collapse" id="createTaskFormCollapse">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إنشاء مهمة جديدة</h5>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <input type="hidden" name="action" value="create_production_task">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">نوع المهمة</label>
                            <select class="form-select" name="task_type" id="taskTypeSelect" required>
                                <option value="general">مهمة عامة</option>
                                <option value="production">إنتاج منتج</option>
                                <option value="quality">مهمة جودة</option>
                                <option value="maintenance">صيانة</option>
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
                                <select class="form-select form-select-sm border-0" name="assigned_to[]" multiple required style="max-height: 100px;">
                                    <?php foreach ($productionUsers as $worker): ?>
                                        <option value="<?php echo (int)$worker['id']; ?>"><?php echo htmlspecialchars($worker['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-text small">يمكن تحديد أكثر من عامل باستخدام زر CTRL أو SHIFT.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">اسم العميل</label>
                            <input type="text" class="form-control" name="customer_name" placeholder="أدخل اسم العميل">
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
                                        <div class="col-md-4">
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
                                            <input type="number" class="form-control" name="products[0][price]" step="0.01" min="0" placeholder="0.00" id="product-price-0">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
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
                    </div>
                    <div class="d-flex justify-content-end mt-4 gap-2">
                        <button type="reset" class="btn btn-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>إعادة تعيين</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send-check me-1"></i>إرسال المهمة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>آخر المهام التي تم إرسالها</h5>
            <span class="text-muted small">آخر 10 مهام</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table dashboard-table--no-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>رقم الطلب</th>
                            <th>الاوردر</th>
                            <th>الحاله</th>
                            <th>الأولوية</th>
                            <th>تاريخ التسليم</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTasks)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">لم يتم إنشاء مهام بعد.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentTasks as $index => $task): ?>
                                <tr>
                                    <td><strong>#<?php echo (int)$task['id']; ?></strong></td>
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
                                        $priorityKey = $task['priority'] ?? '';
                                        $priorityMeta = $priorityStyles[$priorityKey] ?? ['class' => 'secondary', 'label' => 'غير محدد'];
                                        ?>
                                        <span class="badge bg-<?php echo htmlspecialchars($priorityMeta['class']); ?>">
                                            <?php echo htmlspecialchars($priorityMeta['label']); ?>
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
                                    <td class="text-nowrap">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <?php if ($isAccountant || $isManager || $isAdmin || $isProduction || $isDeveloper): ?>
                                                <a href="<?php echo getRelativeUrl('print_task_receipt.php?id=' . (int) $task['id']); ?>" target="_blank" class="btn btn-outline-primary btn-sm btn-icon-only" title="طباعة الاوردر">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($isAccountant || $isManager): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm btn-icon-only" onclick="openChangeStatusModal(<?php echo (int)$task['id']; ?>, '<?php echo htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?>')" title="تغيير حالة الطلب">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($task['status'] === 'completed'): ?>
                                                <span class="text-muted small">—</span>
                                            <?php else: ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('هل أنت متأكد من حذف هذه المهمة؟ سيتم حذفها نهائياً ولن تظهر في الجدول.');">
                                                    <input type="hidden" name="action" value="cancel_task">
                                                    <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm btn-icon-only">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
                            <option value="received">مستلمة</option>
                            <option value="in_progress">قيد التنفيذ</option>
                            <option value="completed">مكتملة</option>
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
                            <option value="received">مستلمة</option>
                            <option value="in_progress">قيد التنفيذ</option>
                            <option value="completed">مكتملة</option>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const taskTypeSelect = document.getElementById('taskTypeSelect');
    const titleInput = document.querySelector('input[name="title"]');
    const productWrapper = document.getElementById('productFieldWrapper');
    const quantityWrapper = document.getElementById('quantityFieldWrapper');
    const productNameInput = document.getElementById('productNameInput');
    const quantityInput = document.getElementById('productQuantityInput');
    const templateSuggestions = document.getElementById('templateSuggestions');

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
                <div class="col-md-4">
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
                    <input type="number" class="form-control" name="products[${productIndex}][price]" step="0.01" min="0" placeholder="0.00" id="product-price-${productIndex}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
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
        });
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
                                    <option value="received">مستلمة</option>
                                    <option value="in_progress">قيد التنفيذ</option>
                                    <option value="completed">مكتملة</option>
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
            'in_progress': 'قيد التنفيذ',
            'completed': 'مكتملة',
            'cancelled': 'ملغاة'
        };

        const statusClasses = {
            'pending': 'warning',
            'received': 'info',
            'in_progress': 'primary',
            'completed': 'success',
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
        'in_progress': 'قيد التنفيذ',
        'completed': 'مكتملة',
        'cancelled': 'ملغاة'
    };
    
    const statusClasses = {
        'pending': 'warning',
        'received': 'info',
        'in_progress': 'primary',
        'completed': 'success',
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

// لا حاجة لإعادة التحميل التلقائي - preventDuplicateSubmission يتولى ذلك
</script>


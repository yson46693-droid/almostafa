<?php
/**
 * صفحة إدارة المهام (نسخة مبسطة محسّنة)
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
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['production', 'accountant', 'manager', 'developer', 'driver']);

// تشغيل migration checks مرة واحدة فقط في الجلسة لتجنب SHOW COLUMNS المتكرر
if (empty($_SESSION['_prod_tasks_migrations_done'])) {
    try {
        $db = db();
        $columns = array_column($db->query("SHOW COLUMNS FROM tasks") ?: [], 'Field');
        $columnsMap = array_flip($columns);

        if (!isset($columnsMap['product_name'])) {
            $afterCol = isset($columnsMap['template_id']) ? 'template_id' : 'product_id';
            $db->execute("ALTER TABLE tasks ADD COLUMN product_name VARCHAR(255) NULL AFTER $afterCol");
        }
        if (!isset($columnsMap['task_type'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN task_type VARCHAR(50) NULL DEFAULT 'general' AFTER status");
        }
        if (!isset($columnsMap['unit'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN unit VARCHAR(50) NULL DEFAULT 'قطعة' AFTER quantity");
        }
        if (!isset($columnsMap['customer_name'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN customer_name VARCHAR(255) NULL DEFAULT NULL AFTER unit");
        }
        if (!isset($columnsMap['customer_phone'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN customer_phone VARCHAR(50) NULL DEFAULT NULL AFTER customer_name");
        }
        if (!isset($columnsMap['receipt_print_count'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN receipt_print_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER notes");
        }
        // توسيع عمود status ليشمل كل الحالات المطلوبة
        if (isset($columnsMap['status'])) {
            $statusCol = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'status'");
            $statusType = strtolower((string) ($statusCol['Type'] ?? ''));
            // إذا كان VARCHAR فهو يقبل أي قيمة — تخطي
            if (!empty($statusType) && strpos($statusType, 'varchar') === false && stripos($statusType, 'with_shipping_company') === false) {
                try {
                    $db->execute("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending','received','in_progress','completed','with_delegate','with_driver','with_shipping_company','delivered','returned','cancelled') DEFAULT 'pending'");
                } catch (Throwable $enumErr) {
                    // فشل تعديل ENUM — تحويل إلى VARCHAR
                    error_log('tasks status ENUM alter failed, converting to VARCHAR: ' . $enumErr->getMessage());
                    try {
                        $db->execute("ALTER TABLE tasks MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
                    } catch (Throwable $varErr) {
                        error_log('tasks status VARCHAR alter also failed: ' . $varErr->getMessage());
                    }
                }
            }
        }
        // إنشاء جدول driver_assignments إذا لم يكن موجوداً
        $db->execute("CREATE TABLE IF NOT EXISTS driver_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            driver_id INT NOT NULL,
            assigned_by INT NOT NULL,
            status ENUM('pending','accepted','rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            responded_at TIMESTAMP NULL,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_da_task (task_id),
            INDEX idx_da_driver_status (driver_id, status)
        )");
        $_SESSION['_prod_tasks_migrations_done'] = 1;
    } catch (Exception $e) {
        error_log('Error checking/adding columns in production/tasks.php: ' . $e->getMessage());
    }
}

// إضافة cache headers لمنع تخزين الصفحة والتأكد من جلب البيانات المحدثة
// هذه headers ضرورية لمنع المتصفح من استخدام cached version
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('ETag: "' . md5(time() . rand()) . '"');
}

$currentUser = getCurrentUser();
$db = db();

$errorMessages = [];
$successMessages = [];

$isManager = ($currentUser['role'] ?? '') === 'manager';
$isProduction = ($currentUser['role'] ?? '') === 'production';
$isDriver = ($currentUser['role'] ?? '') === 'driver';

$hasStatusChangedBy = false;
if (empty($_SESSION['_prod_status_changed_by_done'])) {
    try {
        $scbCols = array_column($db->query("SHOW COLUMNS FROM tasks") ?: [], 'Field');
        if (!in_array('status_changed_by', $scbCols, true)) {
            $db->execute("ALTER TABLE tasks ADD COLUMN status_changed_by INT(11) NULL");
        }
        $hasStatusChangedBy = true;
        $_SESSION['_prod_status_changed_by_done'] = 1;
    } catch (Exception $e) {
        error_log('status_changed_by migration error: ' . $e->getMessage());
    }
} else {
    $hasStatusChangedBy = true;
}

if (!function_exists('tasksSafeString')) {
    function tasksSafeString($value)
    {
        if ($value === null || (!is_scalar($value) && $value !== '')) {
            return '';
        }

        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            static $supportedSources = null;

            if ($supportedSources === null) {
                $preferred = ['UTF-8', 'ISO-8859-1', 'Windows-1256', 'Windows-1252'];
                $available = array_map('strtolower', mb_list_encodings());
                $supportedSources = [];

                foreach ($preferred as $encoding) {
                    if (in_array(strtolower($encoding), $available, true)) {
                        $supportedSources[] = $encoding;
                    }
                }

                if (empty($supportedSources)) {
                    $supportedSources[] = 'UTF-8';
                }
            }

            $converted = @mb_convert_encoding($value, 'UTF-8', $supportedSources);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return trim($value);
    }
}

if (!function_exists('tasksEnsureStatusEnum')) {
    function tasksEnsureStatusEnum($db): void
    {
        try {
            $statusCol = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'status'");
            $statusType = strtolower((string) ($statusCol['Type'] ?? ''));
            // إذا كان العمود VARCHAR فهو يقبل أي قيمة — لا حاجة لتعديل
            if ($statusType === '' || strpos($statusType, 'varchar') !== false) {
                return;
            }
            // العمود ENUM — تحقق إن كانت القيمة الجديدة موجودة
            if (stripos($statusType, 'with_shipping_company') === false) {
                try {
                    $db->execute("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending','received','in_progress','completed','with_delegate','with_driver','with_shipping_company','delivered','returned','cancelled') DEFAULT 'pending'");
                } catch (Throwable $enumErr) {
                    error_log('tasksEnsureStatusEnum ENUM alter failed, converting to VARCHAR: ' . $enumErr->getMessage());
                    try {
                        $db->execute("ALTER TABLE tasks MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
                    } catch (Throwable $varErr) {
                        error_log('tasksEnsureStatusEnum VARCHAR alter also failed: ' . $varErr->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('tasksEnsureStatusEnum error: ' . $e->getMessage());
        }
    }
}

tasksEnsureStatusEnum($db);

if (!function_exists('tasksSafeJsonEncode')) {
    function tasksSafeJsonEncode($data): string
    {
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

        $json = json_encode($data, $options);
        if ($json === false) {
            $sanitized = tasksSanitizeForJson($data);
            $json = json_encode($sanitized, $options);
        }

        return $json !== false ? $json : '[]';
    }
}

if (!function_exists('tasksSanitizeForJson')) {
    function tasksSanitizeForJson($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = tasksSanitizeForJson($item);
            }
            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->$key = tasksSanitizeForJson($item);
            }
            return $value;
        }

        if (is_string($value) || is_numeric($value)) {
            return tasksSafeString($value);
        }

        return $value;
    }
}

if (!function_exists('getTasksRetentionLimit')) {
    function getTasksRetentionLimit(): int
    {
        if (defined('TASKS_RETENTION_MAX_ROWS')) {
            $limit = (int) TASKS_RETENTION_MAX_ROWS;
            if ($limit > 0) {
                return $limit;
            }
        }

        return 100;
    }
}

if (!function_exists('enforceTasksRetentionLimit')) {
    function enforceTasksRetentionLimit($dbInstance = null, int $maxRows = 100): bool
    {
        $maxRows = max(1, (int) $maxRows);

        try {
            if ($dbInstance === null) {
                $dbInstance = db();
            }

            if (!$dbInstance) {
                return false;
            }

            $totalRow = $dbInstance->queryOne('SELECT COUNT(*) AS total FROM tasks');
            $total = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;

            if ($total <= $maxRows) {
                return true;
            }

            $toDelete = $total - $maxRows;
            // حذف المهام الأقدم فقط، مع استثناء المهام المُنشأة في آخر دقيقة لمنع حذف المهام الجديدة
            $ids = $dbInstance->query(
                'SELECT id FROM tasks 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                 ORDER BY created_at ASC, id ASC 
                 LIMIT ?',
                [max(1, $toDelete)]
            );

            if (empty($ids)) {
                return true;
            }
 
            $idValues = array_map(static function ($row) {
                return (int) $row['id'];
            }, $ids);

            $placeholders = implode(',', array_fill(0, count($idValues), '?'));
            $dbInstance->execute("DELETE FROM tasks WHERE id IN ($placeholders)", $idValues);

            return true;
        } catch (Throwable $e) {
            error_log('Tasks retention error: ' . $e->getMessage());
            return false;
        }
    }
}

function tasksAddMessage(array &$bag, string $message): void
{
    $trimmed = tasksSafeString($message);
    if ($trimmed !== '') {
        $bag[] = $trimmed;
    }
}

function tasksHandleAction(string $action, array $input, array $context): array
{
    $db = $context['db'];
    $currentUser = $context['user'];
    $isManager = (bool) ($context['is_manager'] ?? false);
    $isProduction = (bool) ($context['is_production'] ?? false);
    $isDriver = (bool) ($context['is_driver'] ?? false);
    $retentionLimit = (int) $context['retention_limit'];
    $hasStatusChangedBy = (bool) ($context['has_status_changed_by'] ?? false);

    $result = ['error' => null, 'success' => null];

    try {
        switch ($action) {
            case 'add_task':
                if (!$isManager) {
                    throw new RuntimeException('غير مصرح لك بإنشاء مهام');
                }

                $title = tasksSafeString($input['title'] ?? '');
                $description = tasksSafeString($input['description'] ?? '');
                $assignedTo = isset($input['assigned_to']) ? (int) $input['assigned_to'] : 0;
                $priority = in_array(($input['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], true)
                    ? $input['priority']
                    : 'normal';
                $dueDate = tasksSafeString($input['due_date'] ?? '');
                $relatedType = tasksSafeString($input['related_type'] ?? '');
                $relatedId = isset($input['related_id']) ? (int) $input['related_id'] : 0;
                $productId = isset($input['product_id']) ? (int) $input['product_id'] : 0;
                // قراءة product_name مباشرة من POST - نفس طريقة طلبات العملاء
                // في customer_orders.php يتم استخدام templateName مباشرة (السطر 448)
                $rawProductName = isset($input['product_name']) ? $input['product_name'] : null;
                // معالجة null و empty strings
                if ($rawProductName === null || $rawProductName === 'null' || $rawProductName === '') {
                    $rawProductName = '';
                }
                // استخدام trim مباشرة بدلاً من tasksSafeString لأن tasksSafeString قد يحذف القيمة
                $productName = trim((string)$rawProductName);
                $quantity = isset($input['quantity']) ? (float) $input['quantity'] : 0.0;
                $unit = tasksSafeString($input['unit'] ?? 'قطعة');
                // التحقق من أن الوحدة من القيم المسموحة
                $allowedUnits = ['قطعة', 'كرتونة', 'شرينك', 'جرام', 'كيلو'];
                if (!in_array($unit, $allowedUnits, true)) {
                    $unit = 'قطعة'; // القيمة الافتراضية
                }
                $taskType = $input['task_type'] ?? 'general';
                $notes = tasksSafeString($input['notes'] ?? '');
                
                // إذا كان هناك quantity أو product_id، تغيير task_type تلقائياً إلى production
                // هذا يجب أن يحدث في البداية قبل أي تحقق آخر
                if (($quantity > 0 || $productId > 0) && $taskType !== 'production') {
                    error_log("⚠ Auto-changing task_type to production (quantity: $quantity, product_id: $productId)");
                    $taskType = 'production';
                }
                
                // تسجيل للتشخيص - فقط للقيم المهمة
                // يمكن حذف هذا إذا لم يكن هناك مشاكل

                if ($title === '' && $taskType !== 'production') {
                    throw new RuntimeException('يجب إدخال عنوان المهمة');
                }

                if ($taskType === 'production') {
                    // التحقق من وجود product_name أو product_id
                    if ($productId <= 0 && ($productName === '' || trim($productName) === '')) {
                        error_log("✗ ERROR: Production task requires product_name or product_id!");
                        error_log("  - productId: $productId");
                        error_log("  - productName: '$productName'");
                        throw new RuntimeException('يجب اختيار منتج لمهمة الإنتاج');
                    }

                    if ($quantity <= 0) {
                        throw new RuntimeException('يجب إدخال كمية صحيحة لمهمة الإنتاج');
                    }
                    
                    // إذا كان productName فارغاً لمهام الإنتاج، حاول الحصول عليه من product_id
                    if ((empty($productName) || trim($productName) === '') && $productId > 0) {
                        $product = $db->queryOne('SELECT name FROM products WHERE id = ?', [$productId]);
                        if ($product && !empty($product['name'])) {
                            $productName = trim($product['name']);
                            error_log("✓ Retrieved product_name from product_id: '$productName' (product_id: $productId)");
                        } else {
                            error_log("✗ Product not found in database for product_id: $productId");
                            // إذا لم يتم العثور على المنتج، رفض الطلب
                            throw new RuntimeException('المنتج المحدد غير موجود في قاعدة البيانات');
                        }
                    }
                    
                    // التحقق النهائي: يجب أن يكون لدينا product_name بعد كل المحاولات
                    if (empty($productName) || trim($productName) === '') {
                        error_log("✗ ERROR: product_name is still empty after all attempts!");
                        error_log("  - productId: $productId");
                        error_log("  - productName: '$productName'");
                        throw new RuntimeException('لم يتم العثور على اسم المنتج. يرجى اختيار منتج صحيح');
                    }

                    // إذا كان productId <= 0 أو سالب (قالب بدون product_id)، البحث عن product_id باستخدام product_name
                    // هذا مهم للقوالب التي لها id = -999999
                    if (($productId <= 0 || $productId < 0) && $productName !== '') {
                        try {
                            error_log("Searching for product_id by name: '$productName' (current productId: $productId)");
                            
                            // البحث بمطابقة دقيقة أولاً (مع status = 'active')
                            $product = $db->queryOne(
                                "SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                                [$productName]
                            );
                            
                            // إذا لم يتم العثور عليه، جرب البحث بدون شرط status
                            if (!$product) {
                                $product = $db->queryOne(
                                    "SELECT id FROM products WHERE name = ? LIMIT 1",
                                    [$productName]
                                );
                            }
                            
                            // إذا لم يتم العثور عليه، جرب البحث بمطابقة جزئية
                            if (!$product) {
                                $product = $db->queryOne(
                                    "SELECT id FROM products WHERE name LIKE ? AND status = 'active' LIMIT 1",
                                    ['%' . $productName . '%']
                                );
                            }
                            
                            // إذا لم يتم العثور عليه، جرب البحث بمطابقة جزئية بدون شرط status
                            if (!$product) {
                                $product = $db->queryOne(
                                    "SELECT id FROM products WHERE name LIKE ? LIMIT 1",
                                    ['%' . $productName . '%']
                                );
                            }
                            
                            if ($product && !empty($product['id'])) {
                                $productId = (int)$product['id'];
                                error_log("✓ Found product_id: $productId for product_name: '$productName'");
                            } else {
                                // إذا لم يتم العثور على المنتج، إنشاؤه تلقائياً في جدول products
                                // هذا يضمن أن القوالب التي لا تحتوي على product_id سيتم إنشاء product_id لها
                                error_log("✗ Product not found in products table for product_name: '$productName', creating new product");
                                try {
                                    $insertResult = $db->execute(
                                        "INSERT INTO products (name, status, created_at) VALUES (?, 'active', NOW())",
                                        [$productName]
                                    );
                                    error_log("Insert result: " . json_encode($insertResult));
                                    if ($insertResult && isset($insertResult['insert_id']) && $insertResult['insert_id'] > 0) {
                                        $productId = (int)$insertResult['insert_id'];
                                        error_log("✓ Created new product with product_id: $productId for product_name: '$productName'");
                                    } else {
                                        error_log("✗ Failed to create product - insert_id is missing or invalid. Result: " . json_encode($insertResult));
                                        // محاولة الحصول على insert_id من الاتصال مباشرة
                                        $lastInsertId = $db->getLastInsertId();
                                        if ($lastInsertId > 0) {
                                            $productId = (int)$lastInsertId;
                                            error_log("✓ Got product_id from getLastInsertId(): $productId");
                                        } else {
                                            error_log("✗ getLastInsertId() also returned 0 or invalid value");
                                        }
                                    }
                                } catch (Exception $createError) {
                                    error_log('✗ Error creating product: ' . $createError->getMessage());
                                    error_log('Exception trace: ' . $createError->getTraceAsString());
                                    // حتى لو فشل إنشاء المنتج، سنستمر في حفظ product_name في notes
                                }
                            }
                        } catch (Exception $e) {
                            error_log('Error searching for product_id by name: ' . $e->getMessage());
                            // حتى لو فشل البحث، سنستمر في حفظ product_name في notes
                        }
                    }

                    // جلب اسم المنتج لعرضه في العنوان
                    $displayProductName = $productName;
                    if ($productId > 0) {
                        $product = $db->queryOne('SELECT name FROM products WHERE id = ?', [$productId]);
                        if ($product && !empty($product['name'])) {
                            $displayProductName = $product['name'];
                        }
                    }
                }

               

                $columns = ['created_by', 'priority', 'status'];
                $values = [(int) $currentUser['id'], $priority, 'pending'];
                $placeholders = ['?', '?', '?'];

              
                if ($assignedTo > 0) {
                    $columns[] = 'assigned_to';
                    $values[] = $assignedTo;
                    $placeholders[] = '?';
                }

                if ($dueDate !== '') {
                    $columns[] = 'due_date';
                    $values[] = $dueDate;
                    $placeholders[] = '?';
                }

                if ($relatedType !== '' && $relatedId > 0) {
                    $columns[] = 'related_type';
                    $columns[] = 'related_id';
                    $values[] = $relatedType;
                    $values[] = $relatedId;
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                }

                // حفظ product_id إذا كان موجوداً وموجباً
                // بعد البحث والإنشاء، يجب أن يكون productId > 0 إذا تم العثور عليه أو إنشاؤه
                if ($productId > 0) {
                    $columns[] = 'product_id';
                    $values[] = $productId;
                    $placeholders[] = '?';
                    error_log("✓ Saving product_id: $productId to tasks table");
                } else {
                    error_log("✗ product_id is not > 0, will not save product_id. Current value: $productId");
                    error_log("  This means product was not found/created. productName was: '$productName'");
                }
                
                // حفظ اسم المنتج/القالب مباشرة في حقل product_name في جدول tasks
                // نفس الطريقة المستخدمة في طلبات العملاء (السطر 448 في customer_orders.php):
                // حفظ اسم القالب مباشرة في product_name حتى لو كان template_id أو product_id null
                $displayProductName = '';
                
                // الأولوية: استخدام product_name المرسل من النموذج (اسم القالب)
                // هذا هو نفس المنطق في customer_orders.php السطر 448: حفظ templateName مباشرة
                if (!empty($productName) && trim($productName) !== '') {
                    $displayProductName = trim($productName);
                }
                // إذا كان productName فارغاً ولكن productId موجود وموجب، جلب الاسم من قاعدة البيانات
                elseif ($productId > 0) {
                    $product = $db->queryOne('SELECT name FROM products WHERE id = ?', [$productId]);
                    if ($product && !empty($product['name'])) {
                        $displayProductName = trim($product['name']);
                    }
                }
                
                // إذا كان task_type هو production أو كان هناك product_id/quantity، يجب أن يكون لدينا product_name
                $hasProductData = ($productId > 0 || $quantity > 0);
                if (($taskType === 'production' || $hasProductData) && empty($displayProductName)) {
                    // محاولة أخيرة: جلب الاسم من product_id إذا كان موجوداً
                    if ($productId > 0) {
                        $product = $db->queryOne('SELECT name FROM products WHERE id = ?', [$productId]);
                        if ($product && !empty($product['name'])) {
                            $displayProductName = trim($product['name']);
                        }
                    }
                    
                    // إذا كان لا يزال فارغاً، هذا خطأ - لكن سنحاول الاستمرار
                    if (empty($displayProductName)) {
                        error_log("✗ ERROR: task_type is '$taskType' but product_name is empty!");
                        error_log("  - productName: '$productName'");
                        error_log("  - rawProductName: '$rawProductName'");
                        error_log("  - productId: $productId");
                        error_log("  - quantity: $quantity");
                        error_log("  - hasProductData: " . ($hasProductData ? 'true' : 'false'));
                        error_log("  - POST data: " . json_encode(['product_name' => $rawProductName, 'product_id' => $productId, 'quantity' => $quantity]));
                    }
                }
                
                // التحقق النهائي: إذا كان task_type هو production، يجب أن يكون لدينا product_name
                // هذا يحدث بعد تغيير task_type تلقائياً في البداية (إذا كان هناك quantity أو product_id)
                if ($taskType === 'production' && (empty($displayProductName) || trim($displayProductName) === '')) {
                    error_log("✗ ERROR: task_type is production but product_name is empty!");
                    error_log("  - productId: $productId");
                    error_log("  - quantity: $quantity");
                    error_log("  - productName: '$productName'");
                    throw new RuntimeException('يجب اختيار منتج لمهمة الإنتاج');
                }
                
                // حفظ product_name مباشرة في حقل product_name (نفس طريقة طلبات العملاء - السطر 444-448)
                // IMPORTANT: نحفظ product_name دائماً - حتى لو كان NULL لمهام الإنتاج، نحفظه للتوافق
                // نفس الكود في customer_orders.php: INSERT INTO ... (product_name) VALUES (?, ...)
                $columns[] = 'product_name';
                if (!empty($displayProductName)) {
                    $values[] = $displayProductName;
                    error_log("✓ Saving product_name: '$displayProductName' to tasks table (task_type: $taskType)");
                } else {
                    // حتى لو كان فارغاً، نحفظ NULL (مثل customer_orders)
                    // لكن لمهام الإنتاج، يجب أن يكون لدينا product_name
                    if ($taskType === 'production') {
                        error_log("⚠ WARNING: Saving product_name as NULL for production task!");
                        error_log("  This should not happen for production tasks. productName was: '$productName'");
                    }
                    $values[] = null;
                    error_log("⚠ Saving product_name as NULL (empty displayProductName)");
                }
                $placeholders[] = '?';
                
                // حفظ معلومات المنتج في notes أيضاً للتوافق مع الكود القديم
                if ($displayProductName !== '') {
                    $productInfo = 'المنتج: ' . $displayProductName;
                    if ($quantity > 0) {
                        $productInfo .= ' - الكمية: ' . number_format($quantity, 2) . ' ' . $unit;
                    }
                    
                    // إضافة معلومات المنتج إلى notes
                    if ($notes !== '') {
                        $notes = $productInfo . "\n\n" . $notes;
                    } else {
                        $notes = $productInfo;
                    }
                }

                if ($quantity > 0) {
                    $columns[] = 'quantity';
                    $values[] = $quantity;
                    $placeholders[] = '?';
                }

                // حفظ الوحدة دائماً إذا كانت موجودة
                $columns[] = 'unit';
                $values[] = $unit;
                $placeholders[] = '?';

                if ($notes !== '') {
                    $columns[] = 'notes';
                    $values[] = $notes;
                    $placeholders[] = '?';
                }

                // حفظ task_type دائماً
                $columns[] = 'task_type';
                $values[] = $taskType;
                $placeholders[] = '?';
                error_log("✓ Saving task_type: '$taskType' to tasks table");

                $sql = 'INSERT INTO tasks (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $insertResult = $db->execute($sql, $values);
                $insertId = $insertResult['insert_id'] ?? 0;

                if ($insertId <= 0) {
                    throw new RuntimeException('تعذر إنشاء المهمة');
                }

                enforceTasksRetentionLimit($db, $retentionLimit);
                logAudit($currentUser['id'], 'add_task', 'tasks', $insertId, null, ['title' => $title, 'type' => $taskType]);

                // إرسال إشعار واحد فقط لعمال الإنتاج والمدير والمحاسب (يظهر في صندوق الإشعارات في التوب بار)
                try {
                    $creatorName = $currentUser['full_name'] ?? $currentUser['name'] ?? 'غير معروف';
                    $taskSummary = !empty($displayProductName) ? $displayProductName : ($title ?: 'مهمة جديدة');
                    if ($quantity > 0 && !empty($displayProductName)) {
                        $taskSummary .= ' - ' . number_format($quantity, 2) . ' ' . $unit;
                    }
                    $notificationTitle = 'أوردر جديد';
                    $notificationMessage = $taskSummary . ' - أنشأه: ' . $creatorName;
                    $rolesLinks = [
                        'production' => getDashboardUrl('production') . '?page=tasks',
                        'manager'    => getDashboardUrl('manager') . '?page=tasks',
                        'accountant' => getDashboardUrl('accountant') . '?page=tasks',
                    ];
                    $notifiedUserIds = [];
                    foreach (['production', 'manager', 'accountant'] as $role) {
                        $users = $db->query("SELECT id FROM users WHERE role = ? AND status = 'active'", [$role]);
                        foreach ($users as $u) {
                            $uid = (int) ($u['id'] ?? 0);
                            if ($uid > 0 && !isset($notifiedUserIds[$uid])) {
                                $notifiedUserIds[$uid] = true;
                                $link = $rolesLinks[$role];
                                createNotification($uid, $notificationTitle, $notificationMessage, 'info', $link, true);
                            }
                        }
                    }
                } catch (Throwable $notificationError) {
                    error_log('Task order notification error: ' . $notificationError->getMessage());
                }

                $result['success'] = 'تم إضافة المهمة بنجاح';
                break;

            case 'receive_task':
            case 'start_task':
            case 'complete_task':
            case 'with_delegate_task':
            case 'with_shipping_company_task':
            case 'deliver_task':
            case 'return_task':
            case 'assign_to_driver':
                $taskId = isset($input['task_id']) ? (int) $input['task_id'] : 0;
                if ($taskId <= 0) {
                    throw new RuntimeException('معرف المهمة غير صحيح');
                }

                $task = $db->queryOne('SELECT assigned_to, status, title, created_by, notes, task_type, related_type FROM tasks WHERE id = ?', [$taskId]);
                if (!$task) {
                    throw new RuntimeException('المهمة غير موجودة');
                }

                // التحقق من أن المهمة مخصصة لعامل إنتاج (لأجل receive/start/complete ولفحص صلاحية deliver/return)
                $isAssignedToProduction = false;
                
                if (!empty($task['assigned_to'])) {
                    $assignedUser = $db->queryOne('SELECT role FROM users WHERE id = ?', [(int) $task['assigned_to']]);
                    if ($assignedUser && $assignedUser['role'] === 'production') {
                        $isAssignedToProduction = true;
                    }
                }
                
                if (!$isAssignedToProduction && !empty($task['notes'])) {
                    if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $task['notes'], $matches)) {
                        $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
                        if (in_array((int)$currentUser['id'], $workerIds, true)) {
                            $isAssignedToProduction = true;
                        }
                    }
                }

                if (in_array($action, ['deliver_task', 'return_task'], true)) {
                    // تم التوصيل / تم الارجاع: مسموح للمدير أو عامل إنتاج أو السائق عندما تكون المهمة مكتملة أو مع المندوب
                    if ($isDriver) {
                        // السائق مصرح له دائماً بتنفيذ تم التوصيل وتم الارجاع (بدون شرط المهمة المخصصة لعامل إنتاج)
                    } elseif (!$isManager && !$isProduction) {
                        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                    }
                    $currentStatus = $task['status'] ?? '';
                    $allowedDeliverStatuses = ['completed', 'with_delegate', 'with_driver', 'with_shipping_company'];
                    if (!in_array($currentStatus, $allowedDeliverStatuses, true)) {
                        throw new RuntimeException('يمكن تطبيق تم التوصيل أو تم الارجاع على المهام المكتملة أو المعطاة للمندوب أو مع السائق فقط');
                    }
                    if ($isProduction && $currentStatus === 'with_shipping_company') {
                        throw new RuntimeException('غير مصرح لعمال الإنتاج بتنفيذ هذا الإجراء عندما تكون الحالة مع شركة الشحن');
                    }
                } elseif ($action === 'with_delegate_task') {
                    if (!$isManager && !$isProduction && !$isDriver) {
                        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                    }
                    if (($task['status'] ?? '') !== 'completed') {
                        throw new RuntimeException('يمكن تطبيق مع المندوب على المهام المكتملة فقط');
                    }
                    $backendTaskType = (strpos((string)($task['related_type'] ?? ''), 'manager_') === 0) ? substr((string)$task['related_type'], 8) : ($task['task_type'] ?? 'general');
                    if ($backendTaskType !== 'telegraph') {
                        throw new RuntimeException('مع المندوب متاح فقط لأوردرات التليجراف');
                    }
                } elseif ($action === 'with_shipping_company_task') {
                    if (!$isManager && !$isProduction && !$isDriver) {
                        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                    }
                    if (($task['status'] ?? '') !== 'completed') {
                        throw new RuntimeException('يمكن تطبيق مع شركة الشحن على المهام المكتملة فقط');
                    }
                    $backendTaskType = (strpos((string)($task['related_type'] ?? ''), 'manager_') === 0) ? substr((string)$task['related_type'], 8) : ($task['task_type'] ?? 'general');
                    if ($backendTaskType !== 'telegraph') {
                        throw new RuntimeException('مع شركة الشحن متاح فقط لأوردرات التليجراف');
                    }
                } elseif ($action === 'assign_to_driver') {
                    if (!$isManager && !$isProduction) {
                        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                    }
                    if (($task['status'] ?? '') !== 'completed') {
                        throw new RuntimeException('يمكن تعيين سائق للمهام المكتملة فقط');
                    }
                    $backendTaskType = (strpos((string)($task['related_type'] ?? ''), 'manager_') === 0) ? substr((string)$task['related_type'], 8) : ($task['task_type'] ?? 'general');
                    if ($backendTaskType === 'telegraph') {
                        throw new RuntimeException('أوردرات التليجراف تستخدم "مع المندوب"');
                    }
                    $existingAssignment = $db->queryOne("SELECT id FROM driver_assignments WHERE task_id = ? AND status = 'pending'", [$taskId]);
                    if ($existingAssignment) {
                        throw new RuntimeException('يوجد تعيين سائق معلق لهذه المهمة بالفعل');
                    }
                    $driverId = isset($input['driver_id']) ? (int) $input['driver_id'] : 0;
                    if ($driverId <= 0) {
                        throw new RuntimeException('يجب اختيار سائق');
                    }
                    $driver = $db->queryOne("SELECT id, full_name FROM users WHERE id = ? AND role = 'driver' AND status = 'active'", [$driverId]);
                    if (!$driver) {
                        throw new RuntimeException('السائق غير موجود أو غير نشط');
                    }
                    $db->execute(
                        "INSERT INTO driver_assignments (task_id, driver_id, assigned_by, status, created_at) VALUES (?, ?, ?, 'pending', NOW())",
                        [$taskId, $driverId, $currentUser['id']]
                    );
                    logAudit($currentUser['id'], 'assign_to_driver', 'tasks', $taskId, null, ['driver_id' => $driverId]);
                    try {
                        $taskTitle = tasksSafeString($task['title'] ?? ('مهمة #' . $taskId));
                        createNotification($driverId, 'طلب تسليم جديد', 'تم تعيينك لتسليم "' . $taskTitle . '". يرجى الموافقة أو الرفض.', 'info', getDashboardUrl('driver') . '?page=tasks');
                    } catch (Throwable $e) {
                        error_log('Driver assignment notification error: ' . $e->getMessage());
                    }
                    $result['success'] = 'تم تعيين السائق بنجاح. بانتظار موافقته.';
                    break;
                } else {
                    if (!$isProduction) {
                        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                    }
                    /* يسمح لعامل الإنتاج بإكمال أي مهمة ظاهرة في قائمة المهام */
                }

                $statusMap = [
                    'receive_task' => ['status' => 'received', 'column' => 'received_at'],
                    'start_task' => ['status' => 'in_progress', 'column' => 'started_at'],
                    'complete_task' => ['status' => 'completed', 'column' => 'completed_at'],
                    'with_delegate_task' => ['status' => 'with_delegate', 'column' => 'completed_at'],
                    'with_shipping_company_task' => ['status' => 'with_shipping_company', 'column' => 'completed_at'],
                    'deliver_task' => ['status' => 'delivered', 'column' => 'completed_at'],
                    'return_task' => ['status' => 'returned', 'column' => 'completed_at'],
                ];

                $update = $statusMap[$action];
                if ($hasStatusChangedBy) {
                    $db->execute(
                        "UPDATE tasks SET status = ?, {$update['column']} = NOW(), status_changed_by = ? WHERE id = ?",
                        [$update['status'], $currentUser['id'], $taskId]
                    );
                } else {
                    $db->execute(
                        "UPDATE tasks SET status = ?, {$update['column']} = NOW() WHERE id = ?",
                        [$update['status'], $taskId]
                    );
                }

                logAudit($currentUser['id'], $action, 'tasks', $taskId, null, ['status' => $update['status']]);

                if ($action === 'complete_task') {
                    try {
                        $taskTitle = tasksSafeString($task['title'] ?? ('مهمة #' . $taskId));
                        // استخدام getDashboardUrl لبناء URL صحيح يحتوي على /dashboard/
                        $productionLink = getDashboardUrl('production') . '?page=tasks';
                        createNotification(
                            $currentUser['id'],
                            'تم إكمال المهمة',
                            'تم تسجيل المهمة "' . $taskTitle . '" كمكتملة.',
                            'success',
                            $productionLink
                        );

                        if (!empty($task['created_by']) && (int) $task['created_by'] !== (int) $currentUser['id']) {
                            // استخدام getDashboardUrl لبناء URL صحيح للمدير
                            $managerLink = getDashboardUrl('manager') . '?page=tasks';
                            createNotification(
                                (int) $task['created_by'],
                                'تم إكمال مهمة الإنتاج',
                                ($currentUser['full_name'] ?? $currentUser['username'] ?? 'عامل الإنتاج') .
                                    ' أكمل المهمة "' . $taskTitle . '".',
                                'success',
                                $managerLink
                            );
                        }
                    } catch (Throwable $notificationError) {
                        error_log('Task completion notification error: ' . $notificationError->getMessage());
                    }
                }

                $result['success'] = 'تم تحديث حالة المهمة بنجاح';
                $result['new_status'] = $update['status'];
                break;

            case 'change_status':
                $taskId = isset($input['task_id']) ? (int) $input['task_id'] : 0;
                $status = $input['status'] ?? 'pending';
                $validStatuses = ['pending', 'received', 'in_progress', 'completed', 'with_delegate', 'with_driver', 'with_shipping_company', 'delivered', 'returned', 'cancelled'];

                if ($taskId <= 0 || !in_array($status, $validStatuses, true)) {
                    throw new RuntimeException('بيانات غير صحيحة لتحديث المهمة');
                }

                if ($isDriver) {
                    $driverAllowedStatuses = ['with_delegate', 'with_driver', 'delivered', 'returned'];
                    if (!in_array($status, $driverAllowedStatuses, true)) {
                        throw new RuntimeException('غير مصرح لك بتغيير حالة المهمة إلى هذه الحالة');
                    }
                } elseif (!$isManager) {
                    throw new RuntimeException('غير مصرح لك بتغيير حالة المهمة');
                }

                $setParts = ['status = ?'];
                $values = [$status];
                if ($hasStatusChangedBy) {
                    $setParts[] = 'status_changed_by = ?';
                    $values[] = $currentUser['id'];
                }

                $setParts[] = in_array($status, ['completed', 'with_delegate', 'with_driver', 'with_shipping_company', 'delivered', 'returned'], true) ? 'completed_at = NOW()' : 'completed_at = NULL';
                $setParts[] = $status === 'received' ? 'received_at = NOW()' : 'received_at = NULL';
                $setParts[] = $status === 'in_progress' ? 'started_at = NOW()' : 'started_at = NULL';

                $values[] = $taskId;

                $db->execute('UPDATE tasks SET ' . implode(', ', $setParts) . ' WHERE id = ?', $values);
                logAudit($currentUser['id'], 'change_task_status', 'tasks', $taskId, null, ['status' => $status]);

                $result['success'] = 'تم تحديث حالة المهمة بنجاح';
                $result['new_status'] = $status;
                break;

            case 'delete_task':
                if (!$isManager) {
                    throw new RuntimeException('غير مصرح لك بحذف المهام');
                }

                $taskId = isset($input['task_id']) ? (int) $input['task_id'] : 0;
                if ($taskId <= 0) {
                    throw new RuntimeException('معرف المهمة غير صحيح');
                }

                $db->execute('DELETE FROM tasks WHERE id = ?', [$taskId]);
                logAudit($currentUser['id'], 'delete_task', 'tasks', $taskId, null, null);

                $result['success'] = 'تم حذف المهمة بنجاح';
                break;

            case 'accept_driver_assignment':
            case 'reject_driver_assignment':
                if (!$isDriver && !$isManager) {
                    throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                }
                $assignmentId = isset($input['assignment_id']) ? (int) $input['assignment_id'] : 0;
                if ($assignmentId <= 0) {
                    throw new RuntimeException('معرف التعيين غير صحيح');
                }
                $assignment = $db->queryOne("SELECT da.*, t.title AS task_title FROM driver_assignments da JOIN tasks t ON da.task_id = t.id WHERE da.id = ? AND da.status = 'pending'", [$assignmentId]);
                if (!$assignment) {
                    throw new RuntimeException('التعيين غير موجود أو تم الرد عليه مسبقاً');
                }
                if (!$isManager && (int) $assignment['driver_id'] !== (int) $currentUser['id']) {
                    throw new RuntimeException('هذا التعيين ليس موجهاً لك');
                }
                if ($action === 'accept_driver_assignment') {
                    $db->execute("UPDATE driver_assignments SET status = 'accepted', responded_at = NOW() WHERE id = ?", [$assignmentId]);
                    $db->execute("UPDATE tasks SET status = 'with_driver' WHERE id = ?", [(int) $assignment['task_id']]);
                    logAudit($currentUser['id'], 'accept_driver_assignment', 'driver_assignments', $assignmentId, null, ['task_id' => $assignment['task_id']]);
                    $result['success'] = 'تم قبول الطلب بنجاح';
                    $result['new_status'] = 'with_driver';
                } else {
                    $db->execute("UPDATE driver_assignments SET status = 'rejected', responded_at = NOW() WHERE id = ?", [$assignmentId]);
                    logAudit($currentUser['id'], 'reject_driver_assignment', 'driver_assignments', $assignmentId, null, ['task_id' => $assignment['task_id']]);
                    try {
                        $taskTitle = tasksSafeString($assignment['task_title'] ?? ('مهمة #' . $assignment['task_id']));
                        $driverName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'السائق';
                        createNotification((int) $assignment['assigned_by'], 'رفض تسليم', $driverName . ' رفض تسليم "' . $taskTitle . '". يمكنك تعيين سائق آخر.', 'warning', getDashboardUrl('production') . '?page=tasks');
                    } catch (Throwable $e) {
                        error_log('Driver rejection notification error: ' . $e->getMessage());
                    }
                    $result['success'] = 'تم رفض الطلب';
                }
                break;

            default:
                throw new RuntimeException('إجراء غير معروف');
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = tasksSafeString($_POST['action'] ?? '');

    if ($action !== '') {
        // تنظيف أي output buffer قبل المعالجة
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        $context = [
            'db' => $db,
            'user' => $currentUser,
            'is_manager' => $isManager,
            'is_production' => $isProduction,
            'is_driver' => $isDriver,
            'retention_limit' => getTasksRetentionLimit(),
            'has_status_changed_by' => $hasStatusChangedBy,
        ];

        $result = tasksHandleAction($action, $_POST, $context);

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // استخدام preventDuplicateSubmission لإعادة التوجيه بشكل صحيح
        $queryParams = [];
        $queryParams['page'] = 'tasks';
        
        // الحفاظ على معاملات GET الأخرى (بما فيها البحث والبحث المتقدم)
        if (isset($_GET['p']) && (int)$_GET['p'] > 0) {
            $queryParams['p'] = (int)$_GET['p'];
        }
        if (isset($_GET['search']) && $_GET['search'] !== '') {
            $queryParams['search'] = $_GET['search'];
        }
        if (isset($_GET['search_text']) && $_GET['search_text'] !== '') {
            $queryParams['search_text'] = $_GET['search_text'];
        }
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $queryParams['status'] = $_GET['status'];
        }
        if (isset($_GET['priority']) && $_GET['priority'] !== '') {
            $queryParams['priority'] = $_GET['priority'];
        }
        if (isset($_GET['assigned']) && (int)$_GET['assigned'] > 0) {
            $queryParams['assigned'] = (int)$_GET['assigned'];
        }
        if (isset($_GET['task_id']) && trim((string)$_GET['task_id']) !== '') {
            $queryParams['task_id'] = trim((string)$_GET['task_id']);
        }
        if (isset($_GET['search_customer']) && trim((string)$_GET['search_customer']) !== '') {
            $queryParams['search_customer'] = trim((string)$_GET['search_customer']);
        }
        if (isset($_GET['search_order_id']) && trim((string)$_GET['search_order_id']) !== '') {
            $queryParams['search_order_id'] = trim((string)$_GET['search_order_id']);
        }
        if (isset($_GET['task_type']) && trim((string)$_GET['task_type']) !== '') {
            $queryParams['task_type'] = trim((string)$_GET['task_type']);
        }
        if (isset($_GET['due_date_from']) && trim((string)$_GET['due_date_from']) !== '') {
            $queryParams['due_date_from'] = trim((string)$_GET['due_date_from']);
        }
        if (isset($_GET['due_date_to']) && trim((string)$_GET['due_date_to']) !== '') {
            $queryParams['due_date_to'] = trim((string)$_GET['due_date_to']);
        }
        if (isset($_GET['order_date_from']) && trim((string)$_GET['order_date_from']) !== '') {
            $queryParams['order_date_from'] = trim((string)$_GET['order_date_from']);
        }
        if (isset($_GET['order_date_to']) && trim((string)$_GET['order_date_to']) !== '') {
            $queryParams['order_date_to'] = trim((string)$_GET['order_date_to']);
        }
        if (isset($_GET['overdue']) && $_GET['overdue'] === '1') {
            $queryParams['overdue'] = '1';
        }
        
        // استخدام preventDuplicateSubmission لإعادة التوجيه
        // تحديد role بناءً على المستخدم الحالي
        $userRole = $currentUser['role'] ?? 'production';
        
        // استخدام preventDuplicateSubmission مع role و page بدلاً من URL مباشر
        // هذا يضمن بناء URL صحيح يحتوي على /dashboard/
        if ($result['error']) {
            preventDuplicateSubmission(null, $queryParams, null, $userRole, $result['error']);
        } elseif ($result['success']) {
            preventDuplicateSubmission($result['success'], $queryParams, null, $userRole);
        } else {
            // في حالة عدم وجود رسالة، إعادة التوجيه فقط
            // استخدام preventDuplicateSubmission بدون رسالة
            preventDuplicateSubmission(null, $queryParams, null, $userRole);
        }
    }
}

// قراءة رسائل النجاح/الخطأ من session بعد redirect
$error = '';
$success = '';
applyPRGPattern($error, $success);

if ($error !== '') {
    tasksAddMessage($errorMessages, $error);
}

if ($success !== '') {
    tasksAddMessage($successMessages, $success);
}

// إزالة معامل _r من URL بعد التحميل
if (isset($_GET['_r'])) {
    // سيتم إزالته عبر JavaScript لضمان تحديث الصفحة أولاً
}

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

$pageNum = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$perPage = 15;
$offset = ($pageNum - 1) * $perPage;

$search = tasksSafeString($_GET['search'] ?? '');
$statusFilter = tasksSafeString($_GET['status'] ?? '');
// السائق: لا نفرض حالة معينة حتى تعمل الفلترة ضمن (مكتملة، مع المندوب، تم التوصيل، تم الارجاع)
$priorityFilter = tasksSafeString($_GET['priority'] ?? '');
$assignedFilter = isset($_GET['assigned']) ? (int) $_GET['assigned'] : 0;
$overdueFilter = isset($_GET['overdue']) && $_GET['overdue'] === '1';

// معاملات البحث المتقدم (نفس صفحة تسجيل مهام الإنتاج)
$filterTaskId = isset($_GET['task_id']) ? trim((string)$_GET['task_id']) : '';
$filterCustomer = isset($_GET['search_customer']) ? trim((string)$_GET['search_customer']) : '';
$filterOrderId = isset($_GET['search_order_id']) ? trim((string)$_GET['search_order_id']) : '';
$filterTaskType = isset($_GET['task_type']) ? trim((string)$_GET['task_type']) : '';
$filterDueFrom = isset($_GET['due_date_from']) ? trim((string)$_GET['due_date_from']) : '';
$filterDueTo = isset($_GET['due_date_to']) ? trim((string)$_GET['due_date_to']) : '';
$filterOrderDateFrom = isset($_GET['order_date_from']) ? trim((string)$_GET['order_date_from']) : '';
$filterOrderDateTo = isset($_GET['order_date_to']) ? trim((string)$_GET['order_date_to']) : '';
$filterSearchText = isset($_GET['search_text']) ? trim((string)$_GET['search_text']) : '';

$whereConditions = [];
$params = [];

// بحث سريع: نص في العنوان، الوصف، الملاحظات، العميل، الهاتف
if ($filterSearchText !== '') {
    $whereConditions[] = '(t.title LIKE ? OR t.description LIKE ? OR t.notes LIKE ? OR t.customer_name LIKE ? OR t.customer_phone LIKE ?)';
    $textLike = '%' . $filterSearchText . '%';
    $params[] = $textLike;
    $params[] = $textLike;
    $params[] = $textLike;
    $params[] = $textLike;
    $params[] = $textLike;
} elseif ($search !== '') {
    $whereConditions[] = '(t.title LIKE ? OR t.description LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($overdueFilter) {
    $whereConditions[] = "t.status NOT IN ('completed','with_delegate','with_driver','with_shipping_company','delivered','returned','cancelled')";
    $whereConditions[] = 't.due_date < CURDATE()';
}

// السائق يرى: مكتملة، مع المندوب، تم التوصيل، تم الارجاع + مع السائق (المعينة له فقط)
// السائق لا يرى أوردرات التليجراف
if ($isDriver) {
        $driverAllowedStatuses = ['completed', 'with_delegate', 'with_driver', 'with_shipping_company', 'delivered', 'returned'];
    if ($statusFilter === 'with_driver') {
        $whereConditions[] = "t.status = 'with_driver'";
        $whereConditions[] = "t.id IN (SELECT task_id FROM driver_assignments WHERE driver_id = ? AND status = 'accepted')";
        $params[] = $currentUser['id'];
    } elseif ($statusFilter !== '' && in_array($statusFilter, $driverAllowedStatuses, true)) {
        $whereConditions[] = 't.status = ?';
        $params[] = $statusFilter;
    } else {
        $whereConditions[] = "(t.status IN ('completed', 'with_delegate', 'with_shipping_company', 'delivered', 'returned') OR (t.status = 'with_driver' AND t.id IN (SELECT task_id FROM driver_assignments WHERE driver_id = ? AND status = 'accepted')))";
        $params[] = $currentUser['id'];
    }
    // إخفاء أوردرات التليجراف عن السائق
    $whereConditions[] = "(t.task_type != 'telegraph' AND t.related_type != 'manager_telegraph')";
} elseif ($statusFilter !== '') {
    $whereConditions[] = 't.status = ?';
    $params[] = $statusFilter;
} elseif (!$overdueFilter) {
    $whereConditions[] = "t.status != 'cancelled'";
}

if ($priorityFilter !== '' && in_array($priorityFilter, ['low', 'normal', 'high', 'urgent'], true)) {
    $whereConditions[] = 't.priority = ?';
    $params[] = $priorityFilter;
}

if ($assignedFilter > 0) {
    $whereConditions[] = 't.assigned_to = ?';
    $params[] = $assignedFilter;
}

// فلترة البحث المتقدم (نفس صفحة تسجيل مهام الإنتاج)
if ($filterTaskId !== '') {
    $taskIdInt = (int) $filterTaskId;
    if ($taskIdInt > 0) {
        $whereConditions[] = 't.id = ?';
        $params[] = $taskIdInt;
    }
}
if ($filterCustomer !== '') {
    $whereConditions[] = '(t.customer_name LIKE ? OR t.customer_phone LIKE ?)';
    $customerLike = '%' . $filterCustomer . '%';
    $params[] = $customerLike;
    $params[] = $customerLike;
}
if ($filterOrderId !== '') {
    $orderIdInt = (int) $filterOrderId;
    if ($orderIdInt > 0) {
        $whereConditions[] = "t.related_type = 'customer_order' AND t.related_id = ?";
        $params[] = $orderIdInt;
    }
}
if ($filterTaskType !== '') {
    $whereConditions[] = "(t.task_type = ? OR t.related_type = CONCAT('manager_', ?))";
    $params[] = $filterTaskType;
    $params[] = $filterTaskType;
}
if ($filterDueFrom !== '') {
    $whereConditions[] = 't.due_date >= ?';
    $params[] = $filterDueFrom;
}
if ($filterDueTo !== '') {
    $whereConditions[] = 't.due_date <= ?';
    $params[] = $filterDueTo;
}
if ($filterOrderDateFrom !== '') {
    $whereConditions[] = 'DATE(t.created_at) >= ?';
    $params[] = $filterOrderDateFrom;
}
if ($filterOrderDateTo !== '') {
    $whereConditions[] = 'DATE(t.created_at) <= ?';
    $params[] = $filterOrderDateTo;
}

// السماح لجميع عمال الإنتاج برؤية جميع المهام المخصصة لأي عامل إنتاج
// لا حاجة للفلترة - جميع عمال الإنتاج يرون جميع المهام

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$totalRow = $db->queryOne('SELECT COUNT(*) AS total FROM tasks t ' . $whereClause, $params);
$totalTasks = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;
$totalPages = max(1, (int) ceil($totalTasks / $perPage));

// التحقق من وجود جداول القوالب وcustomer_orders - محفوظ في الجلسة
if (!isset($_SESSION['_prod_tasks_table_flags'])) {
    $_SESSION['_prod_tasks_table_flags'] = [
        'unified_templates' => !empty($db->queryOne("SHOW TABLES LIKE 'unified_product_templates'")),
        'product_templates' => !empty($db->queryOne("SHOW TABLES LIKE 'product_templates'")),
        'customer_orders' => !empty($db->queryOne("SHOW TABLES LIKE 'customer_orders'")),
    ];
}
$unifiedTemplatesExists = $_SESSION['_prod_tasks_table_flags']['unified_templates'];
$productTemplatesExists = $_SESSION['_prod_tasks_table_flags']['product_templates'];
$customerOrdersExists = $_SESSION['_prod_tasks_table_flags']['customer_orders'];

$templateJoins = '';
$templateSelect = '';
if ($unifiedTemplatesExists && $productTemplatesExists) {
    $templateSelect = ', COALESCE(upt.product_name, pt.product_name) AS template_name';
    $templateJoins = 'LEFT JOIN unified_product_templates upt ON t.template_id = upt.id AND upt.status = \'active\' ';
    $templateJoins .= 'LEFT JOIN product_templates pt ON t.template_id = pt.id AND pt.status = \'active\' ';
} elseif ($unifiedTemplatesExists) {
    $templateSelect = ', upt.product_name AS template_name';
    $templateJoins = 'LEFT JOIN unified_product_templates upt ON t.template_id = upt.id AND upt.status = \'active\' ';
} elseif ($productTemplatesExists) {
    $templateSelect = ', pt.product_name AS template_name';
    $templateJoins = 'LEFT JOIN product_templates pt ON t.template_id = pt.id AND pt.status = \'active\' ';
}

$orderCustomerJoin = '';
$customerDisplaySelect = ", t.customer_name, t.customer_phone, COALESCE(NULLIF(TRIM(IFNULL(t.customer_name,'')), ''), '') AS customer_display";
if ($customerOrdersExists) {
    $orderCustomerJoin = " LEFT JOIN customer_orders co ON t.related_type = 'customer_order' AND t.related_id = co.id LEFT JOIN customers cust ON co.customer_id = cust.id";
    $customerDisplaySelect = ", t.customer_name, t.customer_phone, COALESCE(NULLIF(TRIM(t.customer_name), ''), cust.name) AS customer_display";
}

$taskSql = "SELECT t.id, t.title, t.description, t.assigned_to, t.created_by, t.priority, t.status,
    t.due_date, t.completed_at, t.received_at, t.started_at, t.related_type, t.related_id,
    t.product_id, t.template_id, t.quantity, t.unit, t.notes, t.created_at, t.updated_at,
    t.product_name, t.task_type, COALESCE(t.receipt_print_count, 0) AS receipt_print_count
    " . $customerDisplaySelect . ",
    uAssign.full_name AS assigned_to_name,
    uCreate.full_name AS created_by_name,
    p.name AS product_name_from_db" . $templateSelect . "
FROM tasks t
LEFT JOIN users uAssign ON t.assigned_to = uAssign.id
LEFT JOIN users uCreate ON t.created_by = uCreate.id
LEFT JOIN products p ON t.product_id = p.id
" . $orderCustomerJoin . "
" . $templateJoins . "
$whereClause
ORDER BY t.created_at DESC, t.id DESC
LIMIT ? OFFSET ?";

$queryParams = array_merge($params, [$perPage, $offset]);
$tasks = $db->query($taskSql, $queryParams);

// Batch-fetch pending driver assignments for visible tasks
$pendingDriverAssignments = [];
if (!empty($tasks)) {
    $taskIds = array_map(function ($t) { return (int) $t['id']; }, $tasks);
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $daRows = $db->query("SELECT task_id FROM driver_assignments WHERE task_id IN ($placeholders) AND status = 'pending'", $taskIds);
    if (is_array($daRows)) {
        foreach ($daRows as $daRow) {
            $pendingDriverAssignments[(int) $daRow['task_id']] = true;
        }
    }
}

// معرفات المهام التي تم اعتماد فاتورتها (للعرض في جدول عمال الإنتاج والسائق)
$approvedTaskIds = [];
if (($isProduction || $isDriver) && !empty($tasks)) {
    $taskIdsForApproved = array_values(array_filter(array_map(function ($t) { return (int)($t['id'] ?? 0); }, $tasks)));
    if (!empty($taskIdsForApproved)) {
        $ph = implode(',', array_fill(0, count($taskIdsForApproved), '?'));
        $approvedRows = $db->query("SELECT task_id FROM customer_task_purchases WHERE task_id IN ($ph)", $taskIdsForApproved);
        $approvedTaskIds = array_column($approvedRows ?: [], 'task_id');
        try {
            $shippingApproved = $db->query("SELECT task_id FROM shipping_company_paper_invoices WHERE task_id IN ($ph) AND task_id IS NOT NULL", $taskIdsForApproved);
            foreach ($shippingApproved ?: [] as $row) {
                $tid = (int)($row['task_id'] ?? 0);
                if ($tid > 0 && !in_array($tid, $approvedTaskIds, true)) {
                    $approvedTaskIds[] = $tid;
                }
            }
        } catch (Throwable $e) {
            // الجدول أو العمود غير موجود
        }
    }
}

// ── Batch: جمع كل worker IDs من notes أولاً ثم استعلام واحد ──
$allWorkerIds = [];
$taskWorkerIdMap = []; // task index => [worker ids]
foreach ($tasks as $idx => $task) {
    $notes = $task['notes'] ?? '';
    if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $notes, $matches)) {
        $wids = array_filter(array_map('intval', explode(',', $matches[1])));
        $taskWorkerIdMap[$idx] = $wids;
        foreach ($wids as $wid) {
            $allWorkerIds[$wid] = true;
        }
    }
}
// استعلام واحد لجلب أسماء كل العمال
$workerNames = [];
if (!empty($allWorkerIds)) {
    $wids = array_keys($allWorkerIds);
    $wph = implode(',', array_fill(0, count($wids), '?'));
    $wRows = $db->query("SELECT id, full_name FROM users WHERE id IN ($wph)", $wids);
    foreach ($wRows ?: [] as $w) {
        $workerNames[(int)$w['id']] = $w['full_name'];
    }
}

// معالجة كل مهمة: تعيين العمال واسم المنتج
foreach ($tasks as $idx => &$task) {
    $notes = $task['notes'] ?? '';
    $allWorkers = [];

    // تطبيق أسماء العمال من الـ batch cache
    if (isset($taskWorkerIdMap[$idx])) {
        foreach ($taskWorkerIdMap[$idx] as $wid) {
            if (isset($workerNames[$wid])) {
                $allWorkers[] = $workerNames[$wid];
            }
        }
    }
    if (empty($allWorkers) && !empty($task['assigned_to_name'])) {
        $allWorkers[] = $task['assigned_to_name'];
    }

    // تحديد اسم المنتج بالأولوية: product_name > template_name > product_name_from_db > notes
    $finalProductName = null;
    if (!empty(trim((string)($task['product_name'] ?? '')))) {
        $finalProductName = trim((string)$task['product_name']);
    }
    if (empty($finalProductName) && !empty(trim((string)($task['template_name'] ?? '')))) {
        $finalProductName = trim((string)$task['template_name']);
    }
    if (empty($finalProductName) && !empty(trim((string)($task['product_name_from_db'] ?? '')))) {
        $finalProductName = trim((string)$task['product_name_from_db']);
    }
    if (empty($finalProductName) && !empty($notes)) {
        if (preg_match('/المنتج:\s*([^\n\r]+?)\s*-\s*الكمية:/i', $notes, $pm)) {
            $finalProductName = trim($pm[1] ?? '');
        } elseif (preg_match('/المنتج:\s*([^\n\r]+?)(?:\n|$)/i', $notes, $pm)) {
            $finalProductName = trim(preg_replace('/\s*-\s*الكمية:.*$/i', '', trim($pm[1] ?? '')));
        }
        if (!empty($finalProductName)) {
            $finalProductName = trim($finalProductName, '- ');
        }
    }

    $task['product_name'] = !empty($finalProductName) ? $finalProductName : '';
    unset($task['product_name_from_db']);
    $task['all_workers'] = $allWorkers;
    $task['workers_count'] = count($allWorkers);
}
unset($task);

$users = $db->query("SELECT id, full_name FROM users WHERE status = 'active' AND role = 'production' ORDER BY full_name");

$drivers = $db->query("SELECT id, full_name FROM users WHERE status = 'active' AND role = 'driver' ORDER BY full_name");
if (!is_array($drivers)) $drivers = [];

// ── جلب القوالب مع batch lookup لـ product IDs ──
$products = [];
try {
    $templateNames = [];
    if ($unifiedTemplatesExists) {
        $templateNames = $db->query("SELECT DISTINCT product_name as name FROM unified_product_templates WHERE status = 'active' ORDER BY product_name ASC") ?: [];
    }
    if (empty($templateNames) && $productTemplatesExists) {
        $templateNames = $db->query("SELECT DISTINCT product_name as name FROM product_templates WHERE status = 'active' ORDER BY product_name ASC") ?: [];
    }

    if (!empty($templateNames)) {
        // Batch: جلب كل product IDs بإستعلام واحد
        $tNames = array_column($templateNames, 'name');
        $tph = implode(',', array_fill(0, count($tNames), '?'));
        $pRows = $db->query("SELECT id, name FROM products WHERE name IN ($tph) AND status = 'active'", $tNames);
        $productIdMap = [];
        foreach ($pRows ?: [] as $pr) {
            $productIdMap[trim($pr['name'])] = (int)$pr['id'];
        }
        foreach ($templateNames as $template) {
            $name = $template['name'];
            $products[] = [
                'id' => $productIdMap[trim($name)] ?? -999999,
                'name' => $name
            ];
        }
    }
    
    // إذا لم توجد قوالب، جلب من products مباشرة
    if (empty($products)) {
        $products = $db->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name");
    }
} catch (Exception $e) {
    error_log('Error fetching product templates: ' . $e->getMessage());
    // في حالة الخطأ، جلب من products مباشرة
    $products = $db->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name");
}

// Fetch pending driver assignments for current driver
$pendingDriverRequests = [];
if ($isDriver) {
    $pendingDriverRequests = $db->query(
        "SELECT da.id AS assignment_id, da.task_id, da.created_at AS assigned_at,
                t.title, t.customer_name, t.product_name, t.quantity, t.unit, t.task_type, t.related_type,
                uAssign.full_name AS assigned_by_name
         FROM driver_assignments da
         JOIN tasks t ON da.task_id = t.id
         LEFT JOIN users uAssign ON da.assigned_by = uAssign.id
         WHERE da.driver_id = ? AND da.status = 'pending'
         ORDER BY da.created_at DESC",
        [$currentUser['id']]
    );
    if (!is_array($pendingDriverRequests)) $pendingDriverRequests = [];
}

if ($isDriver) {
    // السائق: إحصائيات كل الأوردرات (مكتملة، مع المندوب، تم التوصيل، تم الارجاع)
    $statsBaseConditions = ["status IN ('completed', 'with_delegate', 'with_driver', 'delivered', 'returned')"];
    $statsBaseParams = [];
} else {
    $statsBaseConditions = [];
    $statsBaseParams = [];
}

$buildStatsQuery = function (?string $extraCondition = null, array $extraParams = []) use ($db, $statsBaseConditions, $statsBaseParams) {
    $conditions = $statsBaseConditions;
    if ($extraCondition) {
        $conditions[] = $extraCondition;
    }

    $params = array_merge($statsBaseParams, $extraParams);
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $row = $db->queryOne('SELECT COUNT(*) AS total FROM tasks ' . $where, $params);
    return isset($row['total']) ? (int) $row['total'] : 0;
};

$stats = [
    'total' => $buildStatsQuery(),
    'pending' => $buildStatsQuery("status = 'pending'"),
    'received' => $buildStatsQuery("status = 'received'"),
    'in_progress' => $buildStatsQuery("status = 'in_progress'"),
    'completed' => $buildStatsQuery("status = 'completed'"),
    'with_delegate' => $buildStatsQuery("status = 'with_delegate'"),
    'with_driver' => $buildStatsQuery("status = 'with_driver'"),
    'with_shipping_company' => $buildStatsQuery("status = 'with_shipping_company'"),
    'delivered' => $buildStatsQuery("status = 'delivered'"),
    'returned' => $buildStatsQuery("status = 'returned'"),
    'overdue' => $buildStatsQuery("status NOT IN ('completed','with_delegate','with_driver','with_shipping_company','delivered','returned','cancelled') AND due_date < CURDATE()")
];

$tasksJson = tasksSafeJsonEncode($tasks);

function tasksHtml(string $value): string
{
    return htmlspecialchars(tasksSafeString($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<style>
/* عمود رقم الطلب */
.task-id-col {
    width: 52px !important;
    min-width: 52px !important;
    max-width: 52px !important;
    padding-inline: 0.35rem !important;
    text-align: center;
    white-space: nowrap;
}

/* عمود الإجراءات */
.task-actions-header,
.task-actions-cell {
    min-width: 120px;
    width: 120px;
    white-space: nowrap;
}
@media (max-width: 768px) {
    .task-actions-header,
    .task-actions-cell {
        min-width: 90px;
        width: 90px;
    }
    /* تصغير حجم الجدول على الموبايل */
    .dashboard-table thead th,
    .dashboard-table tbody td {
        padding: 0.4rem 0.5rem !important;
        font-size: 0.75rem !important;
    }
    .dashboard-table {
        min-width: 480px;
    }
    .dashboard-table tbody td .badge {
        font-size: 0.65rem !important;
        padding: 0.2rem 0.4rem !important;
    }
    .task-actions-cell .btn {
        padding: 0.2rem 0.4rem !important;
        font-size: 0.72rem !important;
    }
    /* تمديد الصفحة للحواف — تعويض padding الـ dashboard-main (16px) */
    .tasks-page-container {
        margin-left: -16px !important;
        margin-right: -16px !important;
        width: calc(100% + 32px) !important;
    }
    /* إزالة الحواف الدائرية على الكاردات الممتدة للحافة */
    .tasks-page-container .card {
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
    }
    .tasks-page-container .dashboard-table-wrapper {
        border-radius: 0 !important;
    }
}
.task-status-filter-card {
    display: block;
    width: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    text-align: inherit;
}
/* قائمة إجراءات المهام: قابلة للتمرير ومرئية فوق الجدول على الموبايل */
.task-actions-dropdown-menu-inbody {
    max-height: 70vh !important;
    overflow-y: auto !important;
    z-index: 1060 !important;
    min-width: 11rem !important;
}
.dashboard-table-wrapper .dropdown-menu {
    min-width: 11rem;
}
@media (max-width: 768px) {
    .dashboard-table-wrapper .dropdown-menu {
        max-height: 70vh;
        overflow-y: auto;
        min-width: 11rem;
    }
}
</style>
<div class="container-fluid px-0 px-md-3 tasks-page-container">
    <?php foreach ($errorMessages as $message): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo tasksHtml($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endforeach; ?>

    <?php foreach ($successMessages as $message): ?>
        <div class="alert alert-success alert-dismissible fade show" id="successAlert" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo tasksHtml($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endforeach; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <h2 class="mb-0"><i class="bi bi-list-check me-2"></i>إدارة الاوردرات</h2>
        <?php if ($isManager): ?>
            <button type="button" class="btn btn-primary" onclick="showAddTaskModal()">
                <i class="bi bi-plus-circle me-2"></i>إضافة اوردر جديد
            </button>
        <?php endif; ?>
    </div>

    <?php
    $filterBaseUrl = '?page=tasks';
    if ($filterSearchText !== '') { $filterBaseUrl .= '&search_text=' . rawurlencode($filterSearchText); }
    elseif ($search !== '') { $filterBaseUrl .= '&search=' . rawurlencode($search); }
    if ($priorityFilter !== '') { $filterBaseUrl .= '&priority=' . rawurlencode($priorityFilter); }
    if ($assignedFilter > 0) { $filterBaseUrl .= '&assigned=' . $assignedFilter; }
    if ($filterTaskId !== '') { $filterBaseUrl .= '&task_id=' . rawurlencode($filterTaskId); }
    if ($filterCustomer !== '') { $filterBaseUrl .= '&search_customer=' . rawurlencode($filterCustomer); }
    if ($filterOrderId !== '') { $filterBaseUrl .= '&search_order_id=' . rawurlencode($filterOrderId); }
    if ($filterTaskType !== '') { $filterBaseUrl .= '&task_type=' . rawurlencode($filterTaskType); }
    if ($filterDueFrom !== '') { $filterBaseUrl .= '&due_date_from=' . rawurlencode($filterDueFrom); }
    if ($filterDueTo !== '') { $filterBaseUrl .= '&due_date_to=' . rawurlencode($filterDueTo); }
    if ($filterOrderDateFrom !== '') { $filterBaseUrl .= '&order_date_from=' . rawurlencode($filterOrderDateFrom); }
    if ($filterOrderDateTo !== '') { $filterBaseUrl .= '&order_date_to=' . rawurlencode($filterOrderDateTo); }
    ?>
    <?php if (!defined('TASKS_PARTIAL_TABLE') || !TASKS_PARTIAL_TABLE): ?>
    <div class="card mb-3">
        <div class="card-header bg-transparent py-2 d-flex align-items-center justify-content-between" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#taskStatusCardsCollapse" aria-expanded="true" aria-controls="taskStatusCardsCollapse" id="taskStatusCardsHeader">
            <span class="fw-semibold small"><i class="bi bi-bar-chart-line me-1"></i>ملخص الحالات</span>
            <i class="bi bi-chevron-down tasks-status-cards-chevron" style="transition:transform .25s; transform:rotate(180deg);"></i>
        </div>
        <div class="collapse show" id="taskStatusCardsCollapse">
        <div class="card-body p-2">
    <div class="row g-2" id="taskStatusFilterCards">
        <div class="col-6 col-md-4 col-lg-2">
            <button type="button" class="text-decoration-none task-status-filter-card" data-status="all">
                <div class="card <?php echo $statusFilter === '' && !$overdueFilter ? 'bg-primary text-white' : 'border-primary'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === '' && !$overdueFilter ? 'text-white' : 'text-primary'; ?> mb-0"><?php echo $stats['total']; ?></h5>
                        <small class="<?php echo $statusFilter === '' && !$overdueFilter ? 'text-white-50' : 'text-muted'; ?>">إجمالي الاوردرات</small>
                    </div>
                </div>
            </button>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <button type="button" class="text-decoration-none task-status-filter-card" data-status="pending">
                <div class="card <?php echo $statusFilter === 'pending' ? 'bg-warning text-dark' : 'border-warning'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'pending' ? 'text-dark' : 'text-warning'; ?> mb-0"><?php echo $stats['pending']; ?></h5>
                        <small class="<?php echo $statusFilter === 'pending' ? 'text-dark-50' : 'text-muted'; ?>">معلقة</small>
                    </div>
                </div>
            </button>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
            <button type="button" class="text-decoration-none task-status-filter-card" data-status="completed">
                <div class="card <?php echo $statusFilter === 'completed' ? 'bg-success text-white' : 'border-success'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'completed' ? 'text-white' : 'text-success'; ?> mb-0"><?php echo $stats['completed']; ?></h5>
                        <small class="<?php echo $statusFilter === 'completed' ? 'text-white-50' : 'text-muted'; ?>">مكتملة</small>
                    </div>
                </div>
            </button>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <button type="button" class="text-decoration-none task-status-filter-card" data-status="with_delegate">
                <div class="card <?php echo $statusFilter === 'with_delegate' ? 'bg-info text-white' : 'border-info'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'with_delegate' ? 'text-white' : 'text-info'; ?> mb-0"><?php echo $stats['with_delegate']; ?></h5>
                        <small class="<?php echo $statusFilter === 'with_delegate' ? 'text-white-50' : 'text-muted'; ?>">مع المندوب</small>
                    </div>
                </div>
            </button>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <button type="button" class="text-decoration-none task-status-filter-card" data-status="with_shipping_company">
                <div class="card <?php echo $statusFilter === 'with_shipping_company' ? 'bg-warning text-dark' : 'border-warning'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'with_shipping_company' ? 'text-dark' : 'text-warning'; ?> mb-0"><?php echo $stats['with_shipping_company']; ?></h5>
                        <small class="<?php echo $statusFilter === 'with_shipping_company' ? 'text-dark-50' : 'text-muted'; ?>">مع شركة الشحن</small>
                    </div>
                </div>
            </button>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <button type="button" class="text-decoration-none task-status-filter-card" data-status="with_driver">
                <div class="card <?php echo $statusFilter === 'with_driver' ? 'bg-primary text-white' : 'border-primary'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'with_driver' ? 'text-white' : 'text-primary'; ?> mb-0"><?php echo $stats['with_driver']; ?></h5>
                        <small class="<?php echo $statusFilter === 'with_driver' ? 'text-white-50' : 'text-muted'; ?>">مع السائق</small>
                    </div>
                </div>
            </button>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <button type="button" class="text-decoration-none task-status-filter-card" data-status="delivered">
                <div class="card <?php echo $statusFilter === 'delivered' ? 'bg-success text-white' : 'border-success'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'delivered' ? 'text-white' : 'text-success'; ?> mb-0"><?php echo $stats['delivered']; ?></h5>
                        <small class="<?php echo $statusFilter === 'delivered' ? 'text-white-50' : 'text-muted'; ?>">تم التوصيل</small>
                    </div>
                </div>
            </button>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <button type="button" class="text-decoration-none task-status-filter-card" data-status="returned">
                <div class="card <?php echo $statusFilter === 'returned' ? 'bg-secondary text-white' : 'border-secondary'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'returned' ? 'text-white' : 'text-secondary'; ?> mb-0"><?php echo $stats['returned']; ?></h5>
                        <small class="<?php echo $statusFilter === 'returned' ? 'text-white-50' : 'text-muted'; ?>">تم الارجاع</small>
                    </div>
                </div>
            </button>
        </div>

    </div><!-- /row -->
    </div><!-- /card-body -->
    </div><!-- /collapse -->
    </div><!-- /card -->

    <?php
    $filterIsActive = ($filterSearchText !== '' || $search !== '' || $filterTaskId !== '' || $filterCustomer !== '' || $filterOrderId !== '' || $filterTaskType !== '' || $filterDueFrom !== '' || $filterDueTo !== '' || $filterOrderDateFrom !== '' || $filterOrderDateTo !== '' || $assignedFilter > 0);
    $filterCollapseShow = $filterIsActive ? 'show' : '';
    ?>
    <div class="card mb-3">
        <div class="card-header bg-transparent py-2 d-flex align-items-center justify-content-between" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#tasksFilterCollapse" aria-expanded="<?php echo $filterIsActive ? 'true' : 'false'; ?>" aria-controls="tasksFilterCollapse">
            <span class="fw-semibold small"><i class="bi bi-funnel me-1"></i>البحث والفلترة <?php if ($filterIsActive): ?><span class="badge bg-primary ms-1">نشط</span><?php endif; ?></span>
            <i class="bi bi-chevron-down tasks-filter-chevron" style="transition:transform .25s;<?php echo $filterIsActive ? 'transform:rotate(180deg);' : ''; ?>"></i>
        </div>
        <div class="collapse <?php echo $filterCollapseShow; ?>" id="tasksFilterCollapse">
        <div class="card-body p-3">
            <form method="GET" action="" id="tasksFilterForm">
                <input type="hidden" name="page" value="tasks">
                <input type="hidden" name="status" id="tasksStatusFilterInput" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($priorityFilter !== ''): ?><input type="hidden" name="priority" value="<?php echo htmlspecialchars($priorityFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                <?php if ($overdueFilter): ?><input type="hidden" name="overdue" value="1"><?php endif; ?>
                <!-- بحث متقدم ديناميكي — يفلتر الجدول فوراً مع أي مدخلات -->
                <div id="tasksAdvancedSearch" class="mt-0">
                    <div class="row g-2">
                        <div class="col-12 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">بحث سريع</label>
                            <input type="text" class="form-control form-control-sm tasks-dynamic-filter" name="search_text" id="tasksSearchText" value="<?php echo tasksHtml($filterSearchText !== '' ? $filterSearchText : $search); ?>" placeholder="نص في العنوان، الملاحظات، العميل...">
                        </div>
                        <div class="col-5 col-md-3 col-lg-2">
                            <label class="form-label small mb-0">رقم الاوردر</label>
                            <input type="text" inputmode="numeric" pattern="[0-9]*" name="task_id" class="form-control form-control-sm" id="tasksFilterTaskId" placeholder="#" value="<?php echo tasksHtml($filterTaskId); ?>">
                        </div>
                        <div class="col-auto align-self-end">
                            <button type="submit" class="btn btn-primary btn-sm px-2" id="tasksSearchBtn" title="بحث">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">اسم العميل / هاتف    </label>
                            <input type="text" name="search_customer" class="form-control form-control-sm tasks-dynamic-filter" id="tasksFilterCustomer" placeholder="اسم أو رقم" value="<?php echo tasksHtml($filterCustomer); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">نوع الاوردر</label>
                            <select name="task_type" class="form-select form-select-sm tasks-dynamic-filter" id="tasksFilterTaskType">
                                <option value="">— الكل —</option>
                                <option value="shop_order" <?php echo $filterTaskType === 'shop_order' ? 'selected' : ''; ?>>اوردر محل</option>
                                <option value="online_store" <?php echo $filterTaskType === 'online_store' ? 'selected' : ''; ?>>المتجر الالكتروني</option>
                                <option value="cash_customer" <?php echo $filterTaskType === 'cash_customer' ? 'selected' : ''; ?>>عميل نقدي</option>
                                <option value="telegraph" <?php echo $filterTaskType === 'telegraph' ? 'selected' : ''; ?>>تليجراف</option>
                                <option value="shipping_company" <?php echo $filterTaskType === 'shipping_company' ? 'selected' : ''; ?>>شركة شحن</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ تسليم من</label>
                            <input type="date" name="due_date_from" class="form-control form-control-sm tasks-dynamic-filter" id="tasksFilterDueFrom" value="<?php echo tasksHtml($filterDueFrom); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ تسليم إلى</label>
                            <input type="date" name="due_date_to" class="form-control form-control-sm tasks-dynamic-filter" id="tasksFilterDueTo" value="<?php echo tasksHtml($filterDueTo); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ الطلب من</label>
                            <input type="date" name="order_date_from" class="form-control form-control-sm tasks-dynamic-filter" id="tasksFilterOrderDateFrom" value="<?php echo tasksHtml($filterOrderDateFrom); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ الطلب إلى</label>
                            <input type="date" name="order_date_to" class="form-control form-control-sm tasks-dynamic-filter" id="tasksFilterOrderDateTo" value="<?php echo tasksHtml($filterOrderDateTo); ?>">
                        </div>
                        <div class="col-auto align-self-end">
                            <a href="?page=tasks<?php echo $statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : ''; ?><?php echo $priorityFilter !== '' ? '&priority=' . rawurlencode($priorityFilter) : ''; ?><?php echo $overdueFilter ? '&overdue=1' : ''; ?>" class="btn btn-outline-danger btn-sm">إزالة الفلتر</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-transparent border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h6 class="mb-0"><i class="bi bi-list-task me-2"></i>الاوردرات </h6>
            <?php if (($isManager || $isProduction) && !empty($tasks)): ?>
            <button type="button" class="btn btn-outline-primary btn-sm" id="printSelectedReceiptsBtn" title="طباعة إيصالات الأوردرات المحددة" disabled>
                <i class="bi bi-printer me-1"></i>طباعة المحدد (<span id="selectedCount">0</span>)
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0" id="tasksListContent">
            <?php if (empty($tasks)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-5 text-muted"></i>
                    <p class="text-muted mt-3 mb-0">لا توجد اوردرات</p>
                </div>
            <?php else: ?>
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table dashboard-table--no-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php if ($isManager || $isProduction): ?>
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input" id="selectAllTasks" title="تحديد الكل">
                                </th>
                                <?php endif; ?>
                                <th class="task-id-col">#</th>
                                <?php if (!$isDriver): ?>
                                <th>اسم العميل</th>
                                <?php else: ?>
                                <th>اسم العميل</th>
                                <?php endif; ?>
                                <th>نوع الاوردر</th>
                                <th>الحالة</th>
                                <th>التسليم</th>
                                <th class="task-actions-header">الإجراءات</th>
                            </tr>
                        </thead>
    <?php endif; ?>
                            <?php
                            if (empty($tasks)):
                                if (defined('TASKS_PARTIAL_TABLE') && TASKS_PARTIAL_TABLE):
                                    $colspan = ($isManager || $isProduction) ? 7 : 6;
                                    echo '<tr><td colspan="' . (int)$colspan . '" class="text-center py-5 text-muted">لا توجد اوردرات</td></tr>';
                                    exit;
                                endif;
                            else:
                                if (!defined('TASKS_PARTIAL_TABLE') || !TASKS_PARTIAL_TABLE):
                            ?>
                        <tbody id="tasksTableBody">
                            <?php endif;
                                ob_start();
                            ?>
                            <?php foreach ($tasks as $index => $task): ?>
                                <?php
                                $rowNumber = ($pageNum - 1) * $perPage + $index + 1;
                                $priorityClass = [
                                    'urgent' => 'danger',
                                    'high' => 'warning',
                                    'normal' => 'info',
                                    'low' => 'secondary',
                                ][$task['priority']] ?? 'secondary';

                                $statusClass = [
                                    'pending' => 'warning',
                                    'received' => 'info',
                                    'in_progress' => 'primary',
                                    'completed' => 'success',
                                    'with_delegate' => 'info',
                                    'with_shipping_company' => 'warning',
                                    'delivered' => 'success',
                                    'returned' => 'secondary',
                                    'cancelled' => 'secondary',
                                ][$task['status']] ?? 'secondary';

                                $statusLabel = [
                                    'pending' => 'معلقة',
                                    'completed' => 'مكتملة',
                                    'with_delegate' => 'مع المندوب',
                                    'with_driver' => 'مع السائق',
                                    'with_shipping_company' => 'مع شركة الشحن',
                                    'delivered' => 'تم التوصيل',
                                    'returned' => 'تم الارجاع',
                                    'cancelled' => 'ملغاة'
                                ][$task['status']] ?? tasksSafeString($task['status']);

                                $priorityLabel = [
                                    'urgent' => 'عاجلة',
                                    'high' => 'عالية',
                                    'normal' => 'عادية',
                                    'low' => 'منخفضة'
                                ][$task['priority']] ?? tasksSafeString($task['priority']);

                                $overdue = !in_array($task['status'], ['completed', 'with_delegate', 'with_shipping_company', 'delivered', 'returned', 'cancelled'], true)
                                    && !empty($task['due_date'])
                                    && strtotime((string) $task['due_date']) < time();
                                $searchParts = array_filter([
                                    $task['title'] ?? '',
                                    $task['notes'] ?? '',
                                    $task['customer_name'] ?? '',
                                    $task['customer_phone'] ?? '',
                                    $task['customer_display'] ?? ''
                                ], function($v) { return trim((string)$v) !== ''; });
                                $rowSearchText = implode(' ', array_map('trim', $searchParts));
                                $rowDueDate = !empty($task['due_date']) ? date('Y-m-d', strtotime((string)$task['due_date'])) : '';
                                $rowOrderDate = !empty($task['created_at']) ? date('Y-m-d', strtotime((string)$task['created_at'])) : '';
                                $relatedType = isset($task['related_type']) ? (string)$task['related_type'] : '';
                                $displayType = (strpos($relatedType, 'manager_') === 0) ? substr($relatedType, 8) : ($task['task_type'] ?? 'general');
                                ?>
                                <tr class="tasks-filter-row <?php echo $overdue ? 'table-danger' : ''; ?>" data-task-id="<?php echo (int) $task['id']; ?>" data-status="<?php echo htmlspecialchars((string)($task['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($rowSearchText, ENT_QUOTES, 'UTF-8'); ?>" data-customer="<?php echo htmlspecialchars(trim((string)($task['customer_display'] ?? '') . ' ' . (string)($task['customer_phone'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" data-task-type="<?php echo htmlspecialchars($displayType, ENT_QUOTES, 'UTF-8'); ?>" data-due-date="<?php echo htmlspecialchars($rowDueDate, ENT_QUOTES, 'UTF-8'); ?>" data-order-date="<?php echo htmlspecialchars($rowOrderDate, ENT_QUOTES, 'UTF-8'); ?>" data-assigned="<?php echo (int)($task['assigned_to'] ?? 0); ?>">
                                    <?php if ($isManager || $isProduction): ?>
                                    <td>
                                        <input type="checkbox" class="form-check-input task-print-checkbox" value="<?php echo (int) $task['id']; ?>" data-print-url="<?php echo htmlspecialchars(getRelativeUrl('print_task_receipt.php?id=' . (int) $task['id']), ENT_QUOTES, 'UTF-8'); ?>">
                                    </td>
                                    <?php endif; ?>
                                    <td class="task-id-col">
                                        <strong><?php echo (int) $task['id']; ?></strong><br>
                                        <span class="text-muted" style="font-size:.7rem;"><?php echo !empty($task['created_at']) ? date('d/m', strtotime($task['created_at'])) : ''; ?></span>
                                    </td>
                                    
                                    <td>
                                        <?php
                                        $customerDisplay = isset($task['customer_display']) ? trim((string)$task['customer_display']) : '';
                                        echo $customerDisplay !== '' ? tasksHtml($customerDisplay) : '<span class="text-muted">-</span>';
                                        if ($isProduction || $isDriver):
                                            $taskInvoiceApproved = in_array((int)$task['id'], $approvedTaskIds, true);
                                        ?>
                                        <?php if ($taskInvoiceApproved): ?>
                                            <i class="bi bi-check2-circle text-success ms-1" title="تم اعتماد الفاتورة" style="font-size:.8rem;"></i>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $relatedType = isset($task['related_type']) ? (string)$task['related_type'] : '';
                                        $displayType = (strpos($relatedType, 'manager_') === 0) ? substr($relatedType, 8) : ($task['task_type'] ?? 'general');
                                        $orderTypeLabels = ['shop_order' => 'اوردر محل', 'online_store' => 'المتجر الالكتروني', 'cash_customer' => 'عميل نقدي', 'telegraph' => 'تليجراف', 'shipping_company' => 'شركة شحن', 'general' => 'مهمة عامة', 'production' => 'إنتاج منتج'];
                                        echo tasksHtml($orderTypeLabels[$displayType] ?? $displayType);
                                        ?>
                                    </td>
                                    <td class="task-status-cell"><span class="badge bg-<?php echo $statusClass; ?>"><?php echo tasksHtml($statusLabel); ?></span></td>
                                    <td>
                                        <?php if (!empty($task['due_date'])): ?>
                                            <?php echo tasksHtml(date('d/m', strtotime((string) $task['due_date']))); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="task-actions-cell">
                                        <?php
                                        $taskAssignedTo = (int) ($task['assigned_to'] ?? 0);
                                        $assignedUserRole = null;
                                        $isTaskForProduction = false;
                                        if ($taskAssignedTo > 0) {
                                            $assignedUser = $db->queryOne('SELECT role FROM users WHERE id = ?', [$taskAssignedTo]);
                                            $assignedUserRole = $assignedUser['role'] ?? null;
                                            if ($assignedUserRole === 'production') $isTaskForProduction = true;
                                        }
                                        if (!$isTaskForProduction && !empty($task['notes']) && preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $task['notes'], $matches)) {
                                            $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
                                            if (!empty($workerIds)) {
                                                $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
                                                $workersCheck = $db->queryOne("SELECT COUNT(*) as count FROM users WHERE id IN ($placeholders) AND role = 'production'", $workerIds);
                                                if ($workersCheck && (int)$workersCheck['count'] > 0) $isTaskForProduction = true;
                                            }
                                        }
                                        $canWithDelegateType = (strpos(isset($task['related_type']) ? (string)$task['related_type'] : '', 'manager_') === 0) ? substr((string)$task['related_type'], 8) : ($task['task_type'] ?? 'general');
                                        $canWithDelegate = false; // مستبدل بـ "مع شركة الشحن" لأوردرات التليجراف
                                        $canWithShippingCompany = ($isManager || $isProduction || $isDriver) && ($task['status'] ?? '') === 'completed' && $canWithDelegateType === 'telegraph';
                                        $canAssignDriver = ($isManager || $isProduction) && ($task['status'] ?? '') === 'completed' && $canWithDelegateType !== 'telegraph' && empty($pendingDriverAssignments[(int) $task['id']]);
                                        $canDeliverReturn = (($isManager || $isDriver) && in_array($task['status'] ?? '', ['completed', 'with_delegate', 'with_driver', 'with_shipping_company'], true))
                                            || ($isProduction && in_array($task['status'] ?? '', ['completed', 'with_delegate', 'with_driver'], true));
                                        $canDeliverReturnDriver = in_array($task['status'] ?? '', ['completed', 'with_delegate'], true);
                                        $canDeliverAsDriver = $isDriver && ($task['status'] ?? '') === 'with_driver';
                                        $taskCustomerPhone = isset($task['customer_phone']) ? trim((string) $task['customer_phone']) : '';
                                        $hasCustomerPhone = $taskCustomerPhone !== '';
                                        $taskIdInt = (int) $task['id'];
                                        ?>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="taskActionsDropdown<?php echo $taskIdInt; ?>">
                                                <i class="bi bi-three-dots-vertical me-1"></i>إجراءات
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="taskActionsDropdown<?php echo $taskIdInt; ?>">
                                                <?php if ($isManager || $isProduction || $isDriver): ?>
                                                    <?php $taskReceiptUrl = getRelativeUrl('print_task_receipt.php?id=' . $taskIdInt . (($isDriver && !$isManager && !$isProduction) ? '' : '&print=1')); ?>
                                                    <li><a class="dropdown-item" href="<?php echo $taskReceiptUrl; ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-2"></i>طباعة إيصال</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>
                                                <?php if ($isProduction && in_array($task['status'], ['pending', 'received', 'in_progress'])): ?>
                                                    <li><button type="button" class="dropdown-item" onclick="submitTaskAction('complete_task', <?php echo $taskIdInt; ?>)"><i class="bi bi-check2-circle me-2"></i>إكمال</button></li>
                                                <?php endif; ?>
                                                <?php if ($canWithShippingCompany): ?>
                                                    <li><button type="button" class="dropdown-item" onclick="submitTaskAction('with_shipping_company_task', <?php echo $taskIdInt; ?>)"><i class="bi bi-building me-2"></i>مع شركة الشحن</button></li>
                                                <?php endif; ?>
                                                <?php if ($canAssignDriver): ?>
                                                    <li><button type="button" class="dropdown-item" onclick="openDriverAssignModal(<?php echo $taskIdInt; ?>)"><i class="bi bi-truck me-2"></i>مع السائق</button></li>
                                                <?php endif; ?>
                                                <?php if ($canDeliverReturn || ($isDriver && $canDeliverReturnDriver)): ?>
                                                    <li><button type="button" class="dropdown-item" onclick="submitTaskAction('deliver_task', <?php echo $taskIdInt; ?>)"><i class="bi bi-truck me-2"></i>تم التوصيل</button></li>
                                                    <li><button type="button" class="dropdown-item" onclick="submitTaskAction('return_task', <?php echo $taskIdInt; ?>)"><i class="bi bi-arrow-return-left me-2"></i>تم الارجاع</button></li>
                                                <?php endif; ?>
                                                <?php if ($canDeliverAsDriver && !$canDeliverReturn): ?>
                                                    <li><button type="button" class="dropdown-item" onclick="submitTaskAction('deliver_task', <?php echo $taskIdInt; ?>)"><i class="bi bi-truck me-2"></i>تم التوصيل</button></li>
                                                <?php endif; ?>
                                                <?php if ($isManager): ?>
                                                    <li><button type="button" class="dropdown-item" onclick="viewTask(<?php echo $taskIdInt; ?>)"><i class="bi bi-eye me-2"></i>عرض</button></li>
                                                    <li><button type="button" class="dropdown-item text-danger" onclick="confirmDeleteTask(<?php echo $taskIdInt; ?>)"><i class="bi bi-trash me-2"></i>حذف</button></li>
                                                <?php endif; ?>
                                                <?php if ($hasCustomerPhone): $telHref = 'tel:' . preg_replace('/[^\d+]/', '', $taskCustomerPhone); ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item" href="<?php echo tasksHtml($telHref); ?>"><i class="bi bi-telephone me-2"></i>الاتصال بالعميل</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach;
                                $tbodyContent = ob_get_clean();
                                if (defined('TASKS_PARTIAL_TABLE') && TASKS_PARTIAL_TABLE) {
                                    echo $tbodyContent;
                                    exit;
                                }
                            ?>
                            <?php echo $tbodyContent; ?>
                            <?php if (!defined('TASKS_PARTIAL_TABLE') || !TASKS_PARTIAL_TABLE): ?></tbody><?php endif; ?>
                            <?php endif; /* empty($tasks) */ ?>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <?php
                    $pagerStart = max(1, $pageNum - 2);
                    $pagerEnd = min($totalPages, $pageNum + 2);
                    $paramsPrev = $_GET;
                    $paramsPrev['p'] = max(1, $pageNum - 1);
                    $paramsNext = $_GET;
                    $paramsNext['p'] = min($totalPages, $pageNum + 1);
                    ?>
                    <nav class="my-3" aria-label="Task pagination" id="tasksPagination">
                        <ul class="pagination justify-content-center flex-wrap">
                            <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link tasks-page-link" href="<?php echo $pageNum <= 1 ? '#' : tasksHtml('?' . http_build_query($paramsPrev)); ?>" data-page="<?php echo max(1, $pageNum - 1); ?>" aria-label="السابق">السابق</a>
                            </li>
                            <?php for ($i = $pagerStart; $i <= $pagerEnd; $i++): ?>
                                <?php
                                $paramsForPage = $_GET;
                                $paramsForPage['p'] = $i;
                                $url = '?' . http_build_query($paramsForPage);
                                ?>
                                <li class="page-item <?php echo $pageNum === $i ? 'active' : ''; ?>">
                                    <a class="page-link tasks-page-link" href="<?php echo tasksHtml($url); ?>" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link tasks-page-link" href="<?php echo $pageNum >= $totalPages ? '#' : tasksHtml('?' . http_build_query($paramsNext)); ?>" data-page="<?php echo min($totalPages, $pageNum + 1); ?>" aria-label="التالي">التالي</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// تمت إزالة الطباعة التلقائية عند البحث برقم الأوردر — كانت تسبب فتح تاب غير متوقع وظهور 404
?>

<?php if ($isManager): ?>
<div class="modal fade d-none d-md-block" id="addTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="" id="addTaskForm">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة مهمة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_task">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">نوع المهمة <span class="text-danger">*</span></label>
                            <select class="form-select" name="task_type" id="task_type" required>
                                <option value="general">مهمة عامة</option>
                                <option value="production">مهمة إنتاج</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">العنوان <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="task_title">
                        </div>
                        <div class="col-12">
                            <label class="form-label">الوصف</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="تفاصيل المهمة"></textarea>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">تاريخ التسليم</label>
                            <input type="date" class="form-control" name="due_date">
                        </div>
                    </div>

                    <div class="border rounded p-3 mt-3" id="production_fields" style="display: none;">
                        <h6 class="fw-bold mb-3">بيانات مهمة الإنتاج</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">اختر من القوالب</label>
                                <select class="form-select" name="product_id" id="product_id">
                                    <option value="0">اختر القالب</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?php echo (int) $product['id']; ?>" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo tasksHtml($product['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text mt-1">أو أدخل اسم المنتج يدوياً أدناه</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="product_name" id="product_name" placeholder="أدخل اسم المنتج أو اختر من القوالب أعلاه" value="">
                                <div class="form-text mt-1">سيتم تحديث هذا الحقل تلقائياً عند اختيار قالب</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الكمية <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" class="form-control" name="quantity" id="quantity" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الوحدة</label>
                                <select class="form-select" name="unit" id="unit">
                                    <option value="قطعة">قطعة</option>
                                    <option value="كرتونة">كرتونة</option>
                                    <option value="شرينك">شرينك</option>
                                    <option value="جرام">جرام</option>
                                    <option value="كيلو">كيلو</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-text mt-2">سيتم إنشاء العنوان تلقائيًا بناءً على المنتج والكمية.</div>
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

<div class="modal fade d-none d-md-block" id="viewTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل المهمة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body" id="viewTaskContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
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

<!-- ===== Cards للموبايل ===== -->

<!-- Card إضافة مهمة للموبايل -->
<div class="card shadow-sm mb-4 d-md-none" id="addTaskCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">إضافة مهمة جديدة</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" id="addTaskFormCard">
            <input type="hidden" name="action" value="add_task">
            <div class="mb-3">
                <label class="form-label">نوع المهمة <span class="text-danger">*</span></label>
                <select class="form-select" name="task_type" id="task_type_card" required>
                    <option value="general">مهمة عامة</option>
                    <option value="production">مهمة إنتاج</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">العنوان <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="title" id="task_title_card" required>
            </div>
            <div class="mb-3">
                <label class="form-label">الوصف</label>
                <textarea class="form-control" name="description" rows="3" placeholder="تفاصيل المهمة"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">المخصص إلى</label>
                <select class="form-select" name="assigned_to">
                    <option value="0">-</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo (int) $user['id']; ?>"><?php echo tasksHtml($user['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">الأولوية</label>
                <select class="form-select" name="priority">
                    <option value="normal" selected>عادية</option>
                    <option value="low">منخفضة</option>
                    <option value="urgent">عاجلة</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">تاريخ التسليم</label>
                <input type="date" class="form-control" name="due_date">
            </div>

            <div class="border rounded p-3 mb-3" id="production_fields_card" style="display: none;">
                <h6 class="fw-bold mb-3">بيانات مهمة الإنتاج</h6>
                <div class="mb-3">
                    <label class="form-label">اختر من القوالب</label>
                    <select class="form-select" name="product_id" id="product_id_card">
                        <option value="0">اختر القالب</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo (int) $product['id']; ?>" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo tasksHtml($product['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text mt-1">أو أدخل اسم المنتج يدوياً أدناه</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="product_name" id="product_name_card" placeholder="أدخل اسم المنتج أو اختر من القوالب أعلاه" value="">
                    <div class="form-text mt-1">سيتم تحديث هذا الحقل تلقائياً عند اختيار قالب</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">الكمية <span class="text-danger">*</span></label>
                    <input type="number" step="1" min="1" class="form-control" name="quantity" id="quantity_card" placeholder="0.00">
                </div>
                <div class="mb-3">
                    <label class="form-label">الوحدة</label>
                    <select class="form-select" name="unit" id="unit_card">
                        <option value="قطعة">قطعة</option>
                        <option value="كرتونة">كرتونة</option>
                        <option value="شرينك">شرينك</option>
                        <option value="جرام">جرام</option>
                        <option value="كيلو">كيلو</option>
                    </select>
                </div>
                <div class="form-text">سيتم إنشاء العنوان تلقائيًا بناءً على المنتج والكمية.</div>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddTaskCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card عرض تفاصيل المهمة للموبايل -->
<div class="card shadow-sm mb-4 d-md-none" id="viewTaskCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">تفاصيل المهمة</h5>
    </div>
    <div class="card-body" id="viewTaskContentCard"></div>
    <div class="card-footer">
        <button type="button" class="btn btn-secondary" onclick="closeViewTaskCard()">إغلاق</button>
    </div>
</div>

<?php endif; ?>

<!-- مودال تعيين سائق -->
<div class="offcanvas offcanvas-bottom rounded-top" tabindex="-1" id="driverAssignCard" aria-labelledby="driverAssignCardLabel" style="max-height: 50vh;">
    <div class="offcanvas-header bg-info text-white">
        <h5 class="offcanvas-title" id="driverAssignCardLabel"><i class="bi bi-truck me-2"></i>تسليم للسائق</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="إغلاق"></button>
    </div>
    <div class="offcanvas-body">
        <input type="hidden" id="driverAssignTaskId" value="">
        <div class="mb-3">
            <label for="driverSelect" class="form-label">اختر السائق</label>
            <select class="form-select" id="driverSelect">
                <option value="">-- اختر سائق --</option>
                <?php foreach ($drivers as $drv): ?>
                    <option value="<?php echo (int) $drv['id']; ?>"><?php echo tasksHtml($drv['full_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-secondary btn-sm flex-fill" data-bs-dismiss="offcanvas">إلغاء</button>
            <button type="button" class="btn btn-info btn-sm text-white flex-fill" onclick="submitDriverAssignment()"><i class="bi bi-truck me-1"></i>تسليم</button>
        </div>
    </div>
</div>

<?php if ($isDriver && !empty($pendingDriverRequests)): ?>
<!-- بطاقة طلبات بانتظار موافقة السائق -->
<div class="offcanvas offcanvas-bottom rounded-top" tabindex="-1" id="pendingDriverRequestsModal" aria-labelledby="pendingDriverRequestsLabel" style="max-height: 65vh;">
    <div class="offcanvas-header bg-warning text-dark">
        <h5 class="offcanvas-title" id="pendingDriverRequestsLabel">
            <i class="bi bi-bell me-2"></i>طلبات بانتظار موافقتك (<?php echo count($pendingDriverRequests); ?>)
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="إغلاق"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div class="list-group list-group-flush">
            <?php foreach ($pendingDriverRequests as $req):
                $reqType = (strpos((string)($req['related_type'] ?? ''), 'manager_') === 0) ? substr((string)$req['related_type'], 8) : ($req['task_type'] ?? 'general');
                $reqTypeLabels = ['shop_order' => 'اوردر محل', 'online_store' => 'المتجر الالكتروني', 'cash_customer' => 'عميل نقدي', 'telegraph' => 'تليجراف', 'shipping_company' => 'شركة شحن', 'general' => 'مهمة عامة', 'production' => 'إنتاج منتج'];
            ?>
            <div class="list-group-item" id="driverRequest-<?php echo (int) $req['assignment_id']; ?>">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <strong>أوردر #<?php echo (int) $req['task_id']; ?></strong>
                        <span class="badge bg-secondary ms-1"><?php echo tasksHtml($reqTypeLabels[$reqType] ?? $reqType); ?></span>
                    </div>
                    <small class="text-muted"><?php echo tasksHtml($req['assigned_by_name'] ?? ''); ?></small>
                </div>
                <?php if (!empty($req['customer_name'])): ?>
                    <p class="mb-1 text-muted"><i class="bi bi-person me-1"></i><?php echo tasksHtml($req['customer_name']); ?></p>
                <?php endif; ?>
                <?php if (!empty($req['product_name'])): ?>
                    <p class="mb-2 text-muted"><i class="bi bi-box me-1"></i><?php echo tasksHtml($req['product_name']); ?> &times; <?php echo tasksHtml($req['quantity'] ?? ''); ?> <?php echo tasksHtml($req['unit'] ?? ''); ?></p>
                <?php endif; ?>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm flex-fill" onclick="respondDriverRequest(<?php echo (int) $req['assignment_id']; ?>, 'accept')">
                        <i class="bi bi-check-lg me-1"></i>قبول
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm flex-fill" onclick="respondDriverRequest(<?php echo (int) $req['assignment_id']; ?>, 'reject')">
                        <i class="bi bi-x-lg me-1"></i>رفض
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="POST" action="" id="taskActionForm" style="display: none;">
    <input type="hidden" name="action" value="">
    <input type="hidden" name="task_id" value="">
    <input type="hidden" name="status" value="">
</form>

<script>
(function () {
    'use strict';

    const tasksDataRaw = <?php echo $tasksJson; ?>;
    const tasksData = Array.isArray(tasksDataRaw)
        ? tasksDataRaw
        : (tasksDataRaw && typeof tasksDataRaw === 'object' ? Object.values(tasksDataRaw) : []);

    window.TASK_PAGE_FLAGS = {
        isManager: <?php echo $isManager ? 'true' : 'false'; ?>,
        isProduction: <?php echo $isProduction ? 'true' : 'false'; ?>,
        isDriver: <?php echo $isDriver ? 'true' : 'false'; ?>
    };
    var printTaskReceiptBase = <?php echo json_encode(getRelativeUrl('print_task_receipt.php')); ?>;

    const taskTypeSelect = document.getElementById('task_type');
    const productionFields = document.getElementById('production_fields');
    const productSelect = document.getElementById('product_id');
    const quantityInput = document.getElementById('quantity');
    const titleInput = document.getElementById('task_title');
    const taskActionForm = document.getElementById('taskActionForm');
    // التأكد من إرسال النموذج إلى الرابط الحالي (مع page=tasks) حتى يعمل عند السائق/عميل الإنتاج
    if (taskActionForm) {
        taskActionForm.action = window.location.href || '';
    }

    window.submitTaskAction = function (action, taskId) {
        if (taskActionForm && taskId) {
            taskActionForm.querySelector('input[name="action"]').value = (action || '').toString().replace(/[<>"']/g, '');
            taskActionForm.querySelector('input[name="task_id"]').value = parseInt(taskId, 10) || '';
            taskActionForm.submit();
        }
    };

    function hideLoader() {
        // تم حذف pageLoader
    }

    function sanitizeText(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/[\u0000-\u001F\u007F]/g, '')
            .replace(/[&<>"'`]/g, function (char) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                    '`': '&#96;'
                })[char] || char;
            });
    }

    function sanitizeMultilineText(value) {
        return sanitizeText(value).replace(/(\r\n|\r|\n)/g, '<br>');
    }

    function toggleProductionFields() {
        if (!taskTypeSelect || !productionFields || !titleInput) {
            return;
        }

        const isProductionTask = taskTypeSelect.value === 'production';
        productionFields.style.display = isProductionTask ? 'block' : 'none';
        titleInput.readOnly = isProductionTask;

        if (!isProductionTask) {
            if (productSelect) productSelect.required = false;
            if (quantityInput) quantityInput.required = false;
            titleInput.value = '';
            // مسح product_name عند تغيير نوع المهمة إلى غير production
            const productNameInput = document.getElementById('product_name');
            if (productNameInput) {
                productNameInput.value = '';
            }
            return;
        }

        if (productSelect) productSelect.required = true;
        if (quantityInput) quantityInput.required = true;
        // تحديث product_name عند تغيير نوع المهمة إلى production
        updateProductNameField();
        updateProductionTitle();
    }

    function updateProductionTitle() {
        if (!productSelect || !titleInput) {
            return;
        }

        const productId = parseInt(productSelect.value, 10);
        const quantity = quantityInput ? parseFloat(quantityInput.value) : 0;
        const productNameInput = document.getElementById('product_name');
        const unitSelect = document.getElementById('unit');
        const unit = unitSelect ? unitSelect.value : 'قطعة';
        
        // الحصول على اسم المنتج من الحقل النصي أولاً (إذا كان المستخدم أدخله يدوياً)
        let productName = '';
        if (productNameInput && productNameInput.value && productNameInput.value.trim() !== '') {
            productName = productNameInput.value.trim();
        } else {
            // إذا لم يكن هناك إدخال يدوي، جلب من القائمة المنسدلة
            const selectedOption = productSelect.options[productSelect.selectedIndex];
            if (selectedOption && selectedOption.value !== '0' && selectedOption.value !== '') {
                // الأولوية لـ data-product-name
                productName = selectedOption.getAttribute('data-product-name');
                // إذا لم يكن موجوداً، استخدم نص الخيار
                if (!productName || productName.trim() === '') {
                    productName = selectedOption.text.trim();
                }
                productName = productName.trim();
                
                // تحديث الحقل النصي بالقيمة من القائمة المنسدلة
                if (productNameInput) {
                    productNameInput.value = productName;
                }
            }
        }

        // تحديث الحقل النصي product_name دائماً (حتى لو كان product_id سالباً)
        if (productNameInput && productName) {
            console.log('updateProductionTitle: Using product_name:', productName, 'product_id:', productId);
        } else {
            console.warn('updateProductionTitle: product_name is empty');
        }

        // تحديث العنوان إذا كان هناك منتج وكمية (حتى لو كان product_id سالباً)
        if (productName && quantity > 0) {
            titleInput.value = 'إنتاج ' + sanitizeText(productName) + ' - ' + quantity.toFixed(2) + ' ' + unit;
        } else if (productName && quantity <= 0) {
            titleInput.value = 'إنتاج ' + sanitizeText(productName);
        } else if (!productName && quantity > 0) {
            titleInput.value = '';
        } else if (!productName) {
            titleInput.value = '';
        }
    }
    
    // دالة مساعدة لتحديث product_name
    function updateProductNameField() {
        if (!productSelect) {
            console.warn('productSelect not found');
            return '';
        }
        
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        // الحصول على اسم المنتج من data-product-name أو نص الخيار
        let productName = '';
        if (selectedOption && selectedOption.value !== '0' && selectedOption.value !== '') {
            // الأولوية لـ data-product-name
            productName = selectedOption.getAttribute('data-product-name');
            // إذا لم يكن موجوداً، استخدم نص الخيار
            if (!productName || productName.trim() === '') {
                productName = selectedOption.text.trim();
            }
            // تنظيف القيمة
            productName = productName.trim();
        }
        
        const productNameInput = document.getElementById('product_name');
        if (productNameInput) {
            // تحديث الحقل النصي (الآن مرئي وليس مخفياً)
            productNameInput.value = productName;
            console.log('✓ Updated product_name text field:', productName, 'product_id:', productSelect.value);
            // التحقق من أن القيمة تم تحديثها بشكل صحيح
            if (productNameInput.value !== productName) {
                console.error('✗ Failed to update product_name! Expected:', productName, 'Got:', productNameInput.value);
            } else {
                console.log('✓ product_name field updated successfully');
            }
            return productName;
        } else {
            console.error('✗ product_name input field not found!');
            return '';
        }
    }
    
    // إضافة event listener لتحديث product_name عند تغيير الاختيار
    if (productSelect) {
        productSelect.addEventListener('change', function() {
            updateProductNameField();
            updateProductionTitle();
        });
        
        // تحديث product_name عند تحميل الصفحة إذا كان هناك اختيار مسبق
        if (productSelect.value !== '0' && productSelect.value !== '') {
            updateProductNameField();
        }
    }
    
    // إضافة event listener لتحديث العنوان عند تغيير الكمية
    if (quantityInput) {
        quantityInput.addEventListener('input', updateProductionTitle);
    }
    
    // إضافة event listener لتحديث العنوان عند تغيير الوحدة
    const unitSelect = document.getElementById('unit');
    if (unitSelect) {
        unitSelect.addEventListener('change', updateProductionTitle);
    }
    
    // إضافة event listener لتحديث العنوان عند تغيير اسم المنتج يدوياً
    const productNameInput = document.getElementById('product_name');
    if (productNameInput) {
        productNameInput.addEventListener('input', function() {
            // تحديث العنوان عند الإدخال اليدوي
            updateProductionTitle();
        });
    }
    
    // التأكد من تحديث product_name عند إرسال النموذج
    const taskForm = document.getElementById('addTaskForm');
    if (taskForm && productSelect) {
        taskForm.addEventListener('submit', function(e) {
            // IMPORTANT: تحديث product_name قبل الإرسال مباشرة
            const productNameInput = document.getElementById('product_name');
            if (!productNameInput) {
                console.error('✗ product_name input field not found!');
                return;
            }
            
            const productId = productSelect ? parseInt(productSelect.value, 10) : 0;
            const quantity = quantityInput ? parseFloat(quantityInput.value) : 0;
            
            // الحصول على اسم المنتج من الحقل النصي أولاً (إذا كان المستخدم أدخله يدوياً)
            let productName = productNameInput.value.trim();
            
            // إذا كان الحقل النصي فارغاً ولكن تم اختيار قالب، استخدم اسم القالب
            if (!productName && productId > 0) {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                if (selectedOption && selectedOption.value !== '0' && selectedOption.value !== '') {
                    // الأولوية لـ data-product-name
                    productName = selectedOption.getAttribute('data-product-name');
                    // إذا لم يكن موجوداً، استخدم نص الخيار
                    if (!productName || productName.trim() === '') {
                        productName = selectedOption.text.trim();
                    }
                    productName = productName.trim();
                    
                    // تحديث الحقل النصي بالقيمة من القائمة المنسدلة
                    productNameInput.value = productName;
                }
            }
            
            // إذا كان الحقل النصي يحتوي على قيمة، استخدمها (الأولوية للإدخال اليدوي)
            if (productNameInput.value && productNameInput.value.trim() !== '') {
                productName = productNameInput.value.trim();
            }
            
            console.log('=== FORM SUBMIT DEBUG ===');
            console.log('Product select value:', productSelect.value);
            console.log('Product ID:', productId);
            console.log('Quantity:', quantity);
            console.log('Product name from option:', productName);
            console.log('Product name input value (before):', productNameInput.value);
            console.log('Task type:', taskTypeSelect ? taskTypeSelect.value : 'NOT FOUND');
            
            // إذا كان هناك كمية، تغيير task_type تلقائياً إلى production
            if (quantity > 0 && taskTypeSelect && taskTypeSelect.value !== 'production') {
                console.log('⚠ Auto-changing task_type to production (quantity detected: ' + quantity + ')');
                taskTypeSelect.value = 'production';
                toggleProductionFields();
                // تحديث product_name مرة أخرى بعد تغيير task_type
                updateProductNameField();
                // تحديث productName بعد تغيير task_type
                const updatedOption = productSelect.options[productSelect.selectedIndex];
                if (updatedOption && updatedOption.value !== '0' && updatedOption.value !== '') {
                    productName = updatedOption.getAttribute('data-product-name') || updatedOption.text.trim();
                    productName = productName.trim();
                    productNameInput.value = productName;
                }
            }
            
            // تحديث product_name مرة أخرى قبل التحقق النهائي
            const updatedProductName = updateProductNameField();
            const finalProductName = productNameInput.value.trim();
            
            // إذا كان هناك منتج محدد أو كمية، يجب أن يكون product_name موجوداً
            if ((productId > 0 || quantity > 0) && !finalProductName) {
                console.warn('⚠ Product ID or quantity exists but product_name is empty!');
                console.warn('  - Selected option:', selectedOption ? selectedOption.text : 'NONE');
                console.warn('  - data-product-name:', selectedOption ? selectedOption.getAttribute('data-product-name') : 'NONE');
                console.warn('  - productSelect.value:', productSelect.value);
                console.warn('  - productSelect.selectedIndex:', productSelect.selectedIndex);
                console.warn('  - Updated product name:', updatedProductName);
            }
            
            // التحقق النهائي - إذا كان product_name فارغاً ولكن task_type هو production، منع الإرسال
            if (taskTypeSelect && taskTypeSelect.value === 'production') {
                if (!finalProductName) {
                    console.error('✗ Cannot submit: product_name is required for production tasks!');
                    console.error('  - Selected option:', selectedOption ? selectedOption.text : 'NONE');
                    console.error('  - data-product-name:', selectedOption ? selectedOption.getAttribute('data-product-name') : 'NONE');
                    console.error('  - productSelect.value:', productSelect.value);
                    console.error('  - productSelect.selectedIndex:', productSelect.selectedIndex);
                    console.error('  - Updated product name:', updatedProductName);
                    e.preventDefault();
                    alert('يجب اختيار منتج لمهمة الإنتاج');
                    return false;
                } else {
                    console.log('✓ product_name is valid:', finalProductName);
                }
            }
            
            // التحقق الإضافي: إذا كان هناك quantity ولكن product_name فارغ، منع الإرسال
            // هذا مهم حتى لو كان task_type لم يتغير بعد
            if (quantity > 0 && !finalProductName) {
                console.error('✗ Cannot submit: quantity exists but product_name is empty!');
                console.error('  - Quantity:', quantity);
                console.error('  - Product name:', productNameInput.value);
                console.error('  - Task type:', taskTypeSelect ? taskTypeSelect.value : 'NOT FOUND');
                e.preventDefault();
                alert('يجب اختيار منتج عند إدخال كمية');
                return false;
            }
            
            console.log('Product name input value (after):', productNameInput.value);
            console.log('Final task type:', taskTypeSelect ? taskTypeSelect.value : 'NOT FOUND');
            console.log('=== END FORM SUBMIT DEBUG ===');
        });
    } 

    const statusLabelMap = {
        'pending': 'معلقة',
        'completed': 'مكتملة',
        'with_delegate': 'مع المندوب',
        'with_driver': 'مع السائق',
        'with_shipping_company': 'مع شركة الشحن',
        'delivered': 'تم التوصيل',
        'returned': 'تم الارجاع',
        'cancelled': 'ملغاة'
    };
    const statusClassMap = {
        'pending': 'warning',
        'received': 'info',
        'in_progress': 'primary',
        'completed': 'success',
        'with_delegate': 'info',
        'with_driver': 'primary',
        'with_shipping_company': 'warning',
        'delivered': 'success',
        'returned': 'secondary',
        'cancelled': 'secondary'
    };

    function buildActionsHtml(taskId, newStatus, taskType) {
        var flags = window.TASK_PAGE_FLAGS || {};
        var dropdownId = 'taskActionsDropdown' + taskId;
        var items = '';

        // طباعة إيصال
        if (flags.isManager || flags.isProduction || flags.isDriver) {
            var receiptUrl = printTaskReceiptBase + '?id=' + taskId + ((flags.isDriver && !flags.isManager && !flags.isProduction) ? '' : '&print=1');
            items += '<li><a class="dropdown-item" href="' + receiptUrl + '" target="_blank"><i class="bi bi-printer me-2"></i>طباعة إيصال</a></li>';
            items += '<li><hr class="dropdown-divider"></li>';
        }

        // إكمال (لعمال الإنتاج عندما تكون الحالة معلقة أو مستلمة أو قيد التنفيذ)
        if (flags.isProduction && ['pending', 'received', 'in_progress'].indexOf(newStatus) !== -1) {
            items += '<li><button type="button" class="dropdown-item" onclick="submitTaskAction(\'complete_task\', ' + taskId + ')"><i class="bi bi-check2-circle me-2"></i>إكمال</button></li>';
        }

        // مع شركة الشحن (نوع برقية، مكتملة) - يحل محل "مع المندوب"
        if ((flags.isManager || flags.isProduction || flags.isDriver) && newStatus === 'completed' && taskType === 'telegraph') {
            items += '<li><button type="button" class="dropdown-item" onclick="submitTaskAction(\'with_shipping_company_task\', ' + taskId + ')"><i class="bi bi-building me-2"></i>مع شركة الشحن</button></li>';
        }

        // مع السائق (غير برقية، مكتملة، مدير أو إنتاج)
        if ((flags.isManager || flags.isProduction) && newStatus === 'completed' && taskType !== 'telegraph') {
            items += '<li><button type="button" class="dropdown-item" onclick="openDriverAssignModal(' + taskId + ')"><i class="bi bi-truck me-2"></i>مع السائق</button></li>';
        }

        // تم التوصيل وتم الارجاع
        // المدير والسائق: يشمل with_shipping_company / عمال الإنتاج: لا يشمل with_shipping_company
        var canDeliverReturn = false;
        if (flags.isManager || flags.isDriver) {
            canDeliverReturn = ['completed', 'with_delegate', 'with_driver', 'with_shipping_company'].indexOf(newStatus) !== -1;
        } else if (flags.isProduction) {
            canDeliverReturn = ['completed', 'with_delegate', 'with_driver'].indexOf(newStatus) !== -1;
        }
        if (canDeliverReturn) {
            items += '<li><button type="button" class="dropdown-item" onclick="submitTaskAction(\'deliver_task\', ' + taskId + ')"><i class="bi bi-truck me-2"></i>تم التوصيل</button></li>';
            items += '<li><button type="button" class="dropdown-item" onclick="submitTaskAction(\'return_task\', ' + taskId + ')"><i class="bi bi-arrow-return-left me-2"></i>تم الارجاع</button></li>';
        }

        // عرض وحذف (للمدير فقط)
        if (flags.isManager) {
            items += '<li><button type="button" class="dropdown-item" onclick="viewTask(' + taskId + ')"><i class="bi bi-eye me-2"></i>عرض</button></li>';
            items += '<li><button type="button" class="dropdown-item text-danger" onclick="confirmDeleteTask(' + taskId + ')"><i class="bi bi-trash me-2"></i>حذف</button></li>';
        }

        var html = '<div class="dropdown">';
        html += '<button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="' + dropdownId + '">';
        html += '<i class="bi bi-three-dots-vertical me-1"></i>إجراءات';
        html += '</button>';
        html += '<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="' + dropdownId + '">' + items + '</ul>';
        html += '</div>';
        return html;
    }

    function updateTaskRow(taskId, newStatus) {
        var row = document.querySelector('tr[data-task-id="' + taskId + '"]');
        if (!row) return;
        var statusCell = row.querySelector('.task-status-cell');
        var actionsCell = row.querySelector('.task-actions-cell');
        if (statusCell) {
            var label = statusLabelMap[newStatus] || newStatus;
            var cls = statusClassMap[newStatus] || 'secondary';
            statusCell.innerHTML = '<span class="badge bg-' + cls + '">' + sanitizeText(label) + '</span>';
        }
        if (actionsCell) {
            var taskType = row.getAttribute('data-task-type') || '';
            actionsCell.innerHTML = buildActionsHtml(taskId, newStatus, taskType);
            if (typeof window.initTaskActionsDropdowns === 'function') {
                window.initTaskActionsDropdowns();
            }
        }
        row.setAttribute('data-status', newStatus);
    }

    window.submitTaskAction = function (action, taskId) {
        taskId = parseInt(taskId, 10) || 0;
        if (!taskId) return;

        var formData = new FormData();
        formData.append('action', action);
        formData.append('task_id', taskId);

        var url = window.location.href;
        if (url.indexOf('page=tasks') === -1) {
            url = url + (url.indexOf('?') !== -1 ? '&' : '?') + 'page=tasks';
        }

        fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
            credentials: 'same-origin'
        })
        .then(function (r) {
            var ct = r.headers.get('Content-Type') || '';
            if (!r.ok) {
                throw new Error(r.status === 403 ? 'غير مصرح' : r.status === 500 ? 'خطأ في الخادم' : 'خطأ ' + r.status);
            }
            if (ct.indexOf('application/json') === -1) {
                throw new Error('الرد غير متوقع');
            }
            return r.json();
        })
        .then(function (data) {
            if (data.error) {
                alert(data.error);
                return;
            }
            if (data.success) {
                if (action === 'delete_task') {
                    var row = document.querySelector('tr[data-task-id="' + taskId + '"]');
                    if (row) row.remove();
                } else if (data.new_status) {
                    updateTaskRow(taskId, data.new_status);
                }
                if (typeof window.showToast === 'function') {
                    window.showToast(data.success, 'success');
                } else {
                    alert(data.success);
                }
            }
        })
        .catch(function (err) {
            console.error('submitTaskAction error:', err);
            if (taskActionForm) {
                taskActionForm.querySelector('input[name="action"]').value = action;
                taskActionForm.querySelector('input[name="task_id"]').value = taskId;
                taskActionForm.action = url;
                taskActionForm.submit();
            } else {
                alert('حدث خطأ أثناء تحديث المهمة. ' + (err.message || ''));
            }
        });
    };

    // === Driver Assignment Functions ===

    window.openDriverAssignModal = function(taskId) {
        document.getElementById('driverAssignTaskId').value = taskId;
        document.getElementById('driverSelect').value = '';
        var card = new bootstrap.Offcanvas(document.getElementById('driverAssignCard'));
        card.show();
    };

    window.submitDriverAssignment = function() {
        var taskId = parseInt(document.getElementById('driverAssignTaskId').value, 10) || 0;
        var driverId = parseInt(document.getElementById('driverSelect').value, 10) || 0;
        if (!taskId || !driverId) {
            if (typeof window.showToast === 'function') {
                window.showToast('يجب اختيار سائق', 'danger');
            } else {
                alert('يجب اختيار سائق');
            }
            return;
        }
        var formData = new FormData();
        formData.append('action', 'assign_to_driver');
        formData.append('task_id', taskId);
        formData.append('driver_id', driverId);
        var url = window.location.href;
        if (url.indexOf('page=tasks') === -1) {
            url = url + (url.indexOf('?') !== -1 ? '&' : '?') + 'page=tasks';
        }
        fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            var card = bootstrap.Offcanvas.getInstance(document.getElementById('driverAssignCard'));
            if (card) card.hide();
            if (data.success) {
                if (typeof window.showToast === 'function') {
                    window.showToast(data.success, 'success');
                } else {
                    alert(data.success);
                }
                setTimeout(function() { window.location.reload(); }, 800);
            } else if (data.error) {
                if (typeof window.showToast === 'function') {
                    window.showToast(data.error, 'danger');
                } else {
                    alert(data.error);
                }
            }
        })
        .catch(function(err) {
            console.error('submitDriverAssignment error:', err);
            alert('حدث خطأ أثناء تعيين السائق');
        });
    };

    window.respondDriverRequest = function(assignmentId, response) {
        var action = response === 'accept' ? 'accept_driver_assignment' : 'reject_driver_assignment';
        var formData = new FormData();
        formData.append('action', action);
        formData.append('assignment_id', assignmentId);
        var url = window.location.href;
        if (url.indexOf('page=tasks') === -1) {
            url = url + (url.indexOf('?') !== -1 ? '&' : '?') + 'page=tasks';
        }
        fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (data.success) {
                var card = document.getElementById('driverRequest-' + assignmentId);
                if (card) card.remove();
                var remaining = document.querySelectorAll('#pendingDriverRequestsModal .list-group-item').length;
                var titleEl = document.getElementById('pendingDriverRequestsLabel');
                if (titleEl) {
                    titleEl.textContent = 'طلبات بانتظار موافقتك (' + remaining + ')';
                }
                if (remaining === 0) {
                    var card = bootstrap.Offcanvas.getInstance(document.getElementById('pendingDriverRequestsModal'));
                    if (card) card.hide();
                }
                if (typeof window.showToast === 'function') {
                    window.showToast(data.success, response === 'accept' ? 'success' : 'warning');
                }
                if (response === 'accept') {
                    setTimeout(function() { window.location.reload(); }, 800);
                }
            } else if (data.error) {
                if (typeof window.showToast === 'function') {
                    window.showToast(data.error, 'danger');
                } else {
                    alert(data.error);
                }
            }
        })
        .catch(function(err) {
            console.error('respondDriverRequest error:', err);
            alert('حدث خطأ');
        });
    };

    <?php if ($isDriver && !empty($pendingDriverRequests)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        var pendingCard = document.getElementById('pendingDriverRequestsModal');
        if (pendingCard) {
            var card = new bootstrap.Offcanvas(pendingCard);
            card.show();
        }
    });
    <?php endif; ?>

    window.confirmDeleteTask = function (taskId) {
        if (window.confirm('هل أنت متأكد من حذف هذه المهمة؟')) {
            submitTaskAction('delete_task', taskId);
        }
    };

    window.viewTask = function (taskId) {
        const task = tasksData.find(function (item) {
            return parseInt(item.id, 10) === parseInt(taskId, 10);
        });

        if (!task) {
            return;
        }

        const priorityText = {
            'urgent': 'عاجلة',
            'high': 'عالية',
            'normal': 'عادية',
            'low': 'منخفضة'
        };

        const statusText = {
            'pending': 'معلقة',
            'completed': 'مكتملة',
            'with_delegate': 'مع المندوب',
            'with_driver': 'مع السائق',
            'with_shipping_company': 'مع شركة الشحن',
            'delivered': 'تم التوصيل',
            'returned': 'تم الارجاع',
            'cancelled': 'ملغاة'
        };

        const title = sanitizeText(task.title || '');
        const description = task.description ? sanitizeMultilineText(task.description) : 'لا يوجد وصف';
        const productName = task.product_name ? sanitizeText(task.product_name) : '';
        const quantity = task.quantity ? sanitizeText(task.quantity) : '';
        const assignedTo = task.assigned_to_name ? sanitizeText(task.assigned_to_name) : 'غير محدد';
        const createdBy = task.created_by_name ? sanitizeText(task.created_by_name) : '';
        const dueDate = task.due_date ? sanitizeText(task.due_date) : 'غير محدد';
        const createdAt = task.created_at ? sanitizeText(task.created_at) : '';
        const notes = task.notes ? sanitizeMultilineText(task.notes) : '';

        const priorityBadgeClass = task.priority === 'urgent' ? 'danger'
            : task.priority === 'high' ? 'warning'
            : task.priority === 'normal' ? 'info'
            : 'secondary';

        const statusBadgeClass = task.status === 'pending' ? 'warning'
            : task.status === 'received' ? 'info'
            : task.status === 'in_progress' ? 'primary'
            : task.status === 'completed' ? 'success'
            : task.status === 'with_delegate' ? 'info'
            : task.status === 'with_driver' ? 'primary'
            : task.status === 'with_shipping_company' ? 'warning'
            : task.status === 'delivered' ? 'success'
            : task.status === 'returned' ? 'secondary'
            : 'secondary';

        const content = `
            <div class="mb-3">
                <h5>${title}</h5>
            </div>
            <div class="mb-3">
                <strong>الوصف:</strong>
                <p>${description}</p>
            </div>
            ${productName ? `<div class="mb-3"><strong>المنتج:</strong> ${productName}</div>` : ''}
            ${quantity ? `<div class="mb-3"><strong>الكمية:</strong> ${quantity} ${unit}</div>` : ''}
            <div class="row mb-3">
                <div class="col-md-6"><strong>المخصص إلى:</strong> ${assignedTo}</div>
                <div class="col-md-6"><strong>أنشئت بواسطة:</strong> ${createdBy}</div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>الأولوية:</strong>
                    <span class="badge bg-${priorityBadgeClass}">${sanitizeText(priorityText[task.priority] || task.priority || '')}</span>
                </div>
                <div class="col-md-6">
                    <strong>الحالة:</strong>
                    <span class="badge bg-${statusBadgeClass}">${sanitizeText(statusText[task.status] || task.status || '')}</span>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6"><strong>تاريخ التسليم:</strong> ${dueDate}</div>
                <div class="col-md-6"><strong>تاريخ الإنشاء:</strong> ${createdAt}</div>
            </div>
            ${notes ? `<div class="mb-3"><strong>ملاحظات:</strong><p>${notes}</p></div>` : ''}
        `;

        const modalContent = document.getElementById('viewTaskContent');
        if (modalContent) {
            modalContent.innerHTML = content;
        }
 
        const modalElement = document.getElementById('viewTaskModal');
        if (modalElement && typeof bootstrap !== 'undefined') {
            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
        }
    };

    window.openOrderReceiptModal = function(orderId) {
        const modalEl = document.getElementById('orderReceiptModal');
        const loadingEl = document.getElementById('orderReceiptLoading');
        const bodyEl = document.getElementById('orderReceiptBody');
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
                        '<table class="table table-sm table-bordered"><thead><tr><th>المنتج</th><th class="text-end">الكمية</th></tr></thead><tbody>' + rows + '</tbody><tfoot><tr><td class="text-end fw-bold" colspan="2">الإجمالي: ' + total.toFixed(2) + ' ' + getCurrencySymbol() + '</td></tr></tfoot></table>' +
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
        hideLoader();
        toggleProductionFields();
    });

    window.addEventListener('load', hideLoader);

    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', toggleProductionFields);
    }

    if (productSelect) {
        productSelect.addEventListener('change', updateProductionTitle);
    }

    if (quantityInput) {
        quantityInput.addEventListener('input', updateProductionTitle);
    }
})();
</script>

<!-- تدوير سهم بطاقات الفلتر + حفظ تفضيل الفتح/الإغلاق -->
<script>
(function() {
    function initCollapsePref(collapseId, chevronSelector, prefKey, defaultOpen, forceOpen) {
        var el = document.getElementById(collapseId);
        var chevron = document.querySelector(chevronSelector);
        var btn = document.querySelector('[data-bs-target="#' + collapseId + '"]');
        if (!el) return;

        function applyState(open) {
            if (open) {
                el.classList.add('show');
                if (chevron) chevron.style.transform = 'rotate(180deg)';
                if (btn) btn.setAttribute('aria-expanded', 'true');
            } else {
                el.classList.remove('show');
                if (chevron) chevron.style.transform = 'rotate(0deg)';
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }
        }

        if (!forceOpen) {
            try {
                var saved = localStorage.getItem(prefKey);
                if (saved === 'true') applyState(true);
                else if (saved === 'false') applyState(false);
                else applyState(defaultOpen);
            } catch(e) { applyState(defaultOpen); }
        }

        el.addEventListener('show.bs.collapse', function() {
            if (chevron) chevron.style.transform = 'rotate(180deg)';
            try { localStorage.setItem(prefKey, 'true'); } catch(e) {}
        });
        el.addEventListener('hide.bs.collapse', function() {
            if (chevron) chevron.style.transform = 'rotate(0deg)';
            try { localStorage.setItem(prefKey, 'false'); } catch(e) {}
        });
    }

    var filterIsActive = <?php echo $filterIsActive ? 'true' : 'false'; ?>;
    initCollapsePref('tasksFilterCollapse',    '.tasks-filter-chevron',        'tasksFilterCollapseOpen',  false, filterIsActive);
    initCollapsePref('taskStatusCardsCollapse','.tasks-status-cards-chevron',  'tasksStatusCardsOpen',     true,  false);
})();
</script>

<!-- فلترة ديناميكية لجدول الأوردرات بدون زر بحث -->
<script>
(function() {
    'use strict';

    var form = document.getElementById('tasksFilterForm');
    if (!form) return;

    var searchTextEl = document.getElementById('tasksSearchText');
    var taskIdEl = document.getElementById('tasksFilterTaskId');
    var customerEl = document.getElementById('tasksFilterCustomer');
    var taskTypeEl = document.getElementById('tasksFilterTaskType');
    var dueFromEl = document.getElementById('tasksFilterDueFrom');
    var dueToEl = document.getElementById('tasksFilterDueTo');
    var orderFromEl = document.getElementById('tasksFilterOrderDateFrom');
    var orderToEl = document.getElementById('tasksFilterOrderDateTo');
    var assignedEl = document.getElementById('tasksFilterAssigned');
    var statusInputEl = document.getElementById('tasksStatusFilterInput');

    function getTbody() {
        return document.getElementById('tasksTableBody');
    }

    function getListContent() {
        return document.getElementById('tasksListContent');
    }

    function getStatusCards() {
        return document.querySelectorAll('.task-status-filter-card');
    }

    function normalize(s) {
        if (typeof s !== 'string') return '';
        return s.replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function applyTasksFilter() {
        var tbody = getTbody();
        if (!tbody) return;

        var searchText = searchTextEl ? normalize(searchTextEl.value) : '';
        var taskId = taskIdEl ? String((taskIdEl.value || '').trim()) : '';
        var customer = customerEl ? normalize(customerEl.value) : '';
        var taskType = taskTypeEl ? (taskTypeEl.value || '').trim() : '';
        var dueFrom = dueFromEl ? (dueFromEl.value || '').trim() : '';
        var dueTo = dueToEl ? (dueToEl.value || '').trim() : '';
        var orderFrom = orderFromEl ? (orderFromEl.value || '').trim() : '';
        var orderTo = orderToEl ? (orderToEl.value || '').trim() : '';
        var assigned = assignedEl ? (assignedEl.value || '0') : '0';
        var assignedNum = parseInt(assigned, 10) || 0;
        var status = statusInputEl ? (statusInputEl.value || '').trim() : '';

        tbody.querySelectorAll('tr.tasks-filter-row').forEach(function(tr) {
            var show = true;
            var rowTaskId = String(tr.getAttribute('data-task-id') || '');
            var rowSearch = normalize(tr.getAttribute('data-search') || '');
            var rowCustomer = normalize(tr.getAttribute('data-customer') || '');
            var rowTaskType = (tr.getAttribute('data-task-type') || '').trim();
            var rowDueDate = (tr.getAttribute('data-due-date') || '').trim();
            var rowOrderDate = (tr.getAttribute('data-order-date') || '').trim();
            var rowAssigned = tr.getAttribute('data-assigned') || '0';
            var rowAssignedNum = parseInt(rowAssigned, 10) || 0;
            var rowStatus = (tr.getAttribute('data-status') || '').trim();

            if (status && rowStatus !== status) show = false;
            if (searchText && rowSearch.indexOf(searchText) === -1) show = false;
            if (taskId && rowTaskId.indexOf(taskId) === -1) show = false;
            if (customer && rowCustomer.indexOf(customer) === -1) show = false;
            if (taskType && rowTaskType !== taskType) show = false;
            if (dueFrom && rowDueDate && rowDueDate < dueFrom) show = false;
            if (dueTo && rowDueDate && rowDueDate > dueTo) show = false;
            if (orderFrom && rowOrderDate && rowOrderDate < orderFrom) show = false;
            if (orderTo && rowOrderDate && rowOrderDate > orderTo) show = false;
            if (assignedNum > 0 && rowAssignedNum !== assignedNum) show = false;

            tr.style.display = show ? '' : 'none';
        });
    }

    function updateStatusCards(activeStatus) {
        activeStatus = (activeStatus || '').trim();
        getStatusCards().forEach(function(link) {
            var status = (link.getAttribute('data-status') || 'all').trim();
            var isActive = (status === 'all' && activeStatus === '') || status === activeStatus;
            var card = link.querySelector('.card');
            var number = link.querySelector('h5');
            var small = link.querySelector('small');
            if (!card || !number || !small) return;

            if (status === 'pending') {
                card.className = 'card text-center h-100 ' + (isActive ? 'bg-warning text-dark' : 'border-warning');
                number.className = (isActive ? 'text-dark' : 'text-warning') + ' mb-0';
                small.className = isActive ? 'text-dark-50' : 'text-muted';
            } else if (status === 'completed' || status === 'delivered') {
                card.className = 'card text-center h-100 ' + (isActive ? 'bg-success text-white' : 'border-success');
                number.className = (isActive ? 'text-white' : 'text-success') + ' mb-0';
                small.className = isActive ? 'text-white-50' : 'text-muted';
            } else if (status === 'with_delegate') {
                card.className = 'card text-center h-100 ' + (isActive ? 'bg-info text-white' : 'border-info');
                number.className = (isActive ? 'text-white' : 'text-info') + ' mb-0';
                small.className = isActive ? 'text-white-50' : 'text-muted';
            } else if (status === 'with_shipping_company') {
                card.className = 'card text-center h-100 ' + (isActive ? 'bg-warning text-dark' : 'border-warning');
                number.className = (isActive ? 'text-dark' : 'text-warning') + ' mb-0';
                small.className = isActive ? 'text-dark-50' : 'text-muted';
            } else if (status === 'with_driver' || status === 'all') {
                card.className = 'card text-center h-100 ' + (isActive ? 'bg-primary text-white' : 'border-primary');
                number.className = (isActive ? 'text-white' : 'text-primary') + ' mb-0';
                small.className = isActive ? 'text-white-50' : 'text-muted';
            } else if (status === 'returned') {
                card.className = 'card text-center h-100 ' + (isActive ? 'bg-secondary text-white' : 'border-secondary');
                number.className = (isActive ? 'text-white' : 'text-secondary') + ' mb-0';
                small.className = isActive ? 'text-white-50' : 'text-muted';
            }
        });
    }

    function buildAjaxUrl(targetPage) {
        var fd = new FormData(form);
        fd.set('p', String(targetPage || 1));
        var params = [];
        fd.forEach(function(value, key) {
            if (value !== '' && value !== '0') {
                params.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            }
        });
        return '?' + params.join('&');
    }

    // بناء URL بحث برقم الأوردر فقط — بدون أي فلاتر أخرى
    function buildTaskIdOnlyUrl(taskIdVal) {
        return '?page=tasks&task_id=' + encodeURIComponent(taskIdVal) + '&p=1';
    }

    function applyResponseHtml(html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var currentListContent = getListContent();
        var newListContent = doc.getElementById('tasksListContent');

        if (currentListContent && newListContent) {
            currentListContent.innerHTML = newListContent.innerHTML;
        } else {
            var currentTbody = getTbody();
            var newTbody = doc.getElementById('tasksTableBody');
            if (currentTbody && newTbody) {
                currentTbody.innerHTML = newTbody.innerHTML;
            }
            var paginationNav = document.getElementById('tasksPagination');
            var newPagination = doc.getElementById('tasksPagination');
            if (paginationNav && newPagination) {
                paginationNav.innerHTML = newPagination.innerHTML;
            } else if (paginationNav && !newPagination) {
                paginationNav.innerHTML = '';
            }
        }
    }

    function finishAjax(url) {
        var lc = getListContent();
        if (lc) { lc.style.opacity = ''; lc.style.pointerEvents = ''; }
        applyTasksFilter();
        updateStatusCards(statusInputEl ? statusInputEl.value : '');
        window.dispatchEvent(new Event('tasks-table-updated'));
        history.replaceState({ url: url }, '', url);
    }

    // بحث عالمي برقم الأوردر فقط (يتجاوز كل الفلاتر والصفحات)
    function searchByTaskIdGlobal(taskIdVal) {
        var url = buildTaskIdOnlyUrl(taskIdVal);
        var listContent = getListContent();
        if (listContent) { listContent.style.opacity = '0.5'; listContent.style.pointerEvents = 'none'; }

        // إعادة تعيين فلاتر الحالة حتى لا يخفي applyTasksFilter النتيجة
        if (statusInputEl) statusInputEl.value = '';
        updateStatusCards('');

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                applyResponseHtml(html);
                finishAjax(url);
            })
            .catch(function() {
                var lc = getListContent();
                if (lc) { lc.style.opacity = ''; lc.style.pointerEvents = ''; }
                window.location.href = url;
            });
    }

    function doAjaxTasksPage(targetPage) {
        var url = buildAjaxUrl(targetPage);
        var listContent = getListContent();

        if (listContent) {
            listContent.style.opacity = '0.5';
            listContent.style.pointerEvents = 'none';
        }

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                applyResponseHtml(html);
                finishAjax(url);
            })
            .catch(function() {
                var refreshedListContent = getListContent();
                if (refreshedListContent) {
                    refreshedListContent.style.opacity = '';
                    refreshedListContent.style.pointerEvents = '';
                }
                window.location.href = url;
            });
    }

    // حقل رقم الأوردر: أرقام فقط + بحث عند Enter
    if (taskIdEl) {
        taskIdEl.addEventListener('keypress', function(e) {
            if (e.key && !/^\d$/.test(e.key) && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Enter'].includes(e.key)) {
                e.preventDefault();
            }
        });
        taskIdEl.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '');
        });
        taskIdEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var val = this.value.trim();
                if (val !== '') {
                    searchByTaskIdGlobal(val);
                }
            }
        });
    }

    var debounceTimer;
    function scheduleFilter() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() { doAjaxTasksPage(1); }, 400);
    }

    document.querySelectorAll('#tasksFilterForm .tasks-dynamic-filter').forEach(function(el) {
        if (el.tagName === 'SELECT' || el.type === 'date') {
            el.addEventListener('change', function() { doAjaxTasksPage(1); });
        } else {
            el.addEventListener('input', scheduleFilter);
            el.addEventListener('keyup', scheduleFilter);
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var taskIdVal = taskIdEl ? taskIdEl.value.trim() : '';
        if (taskIdVal !== '') {
            // البحث برقم الأوردر: تجاوز كل الفلاتر وابحث في كل الصفحات
            searchByTaskIdGlobal(taskIdVal);
            return;
        }
        // إذا كانت كل الفلاتر فارغة لا تبحث
        var anyFilter = (searchTextEl && searchTextEl.value.trim() !== '') ||
            (customerEl && customerEl.value.trim() !== '') ||
            (taskTypeEl && taskTypeEl.value !== '') ||
            (dueFromEl && dueFromEl.value !== '') ||
            (dueToEl && dueToEl.value !== '') ||
            (orderFromEl && orderFromEl.value !== '') ||
            (orderToEl && orderToEl.value !== '') ||
            (assignedEl && assignedEl.value !== '0' && assignedEl.value !== '') ||
            (statusInputEl && statusInputEl.value !== '');
        if (!anyFilter) return;
        doAjaxTasksPage(1);
    });

    document.addEventListener('click', function(e) {
        var statusCard = e.target && e.target.closest ? e.target.closest('.task-status-filter-card') : null;
        if (statusCard) {
            e.preventDefault();
            e.stopPropagation();
            var nextStatus = (statusCard.getAttribute('data-status') || 'all').trim();
            if (statusInputEl) {
                statusInputEl.value = nextStatus === 'all' ? '' : nextStatus;
            }
            updateStatusCards(statusInputEl ? statusInputEl.value : '');
            applyTasksFilter();
            doAjaxTasksPage(1);
            return;
        }

        var link = e.target && e.target.closest ? e.target.closest('a.tasks-page-link') : null;
        if (!link || link.closest('.page-item.disabled')) return;
        var targetPage = link.getAttribute('data-page');
        if (!targetPage) return;
        e.preventDefault();
        doAjaxTasksPage(targetPage);
    });

    updateStatusCards(statusInputEl ? statusInputEl.value : '');
    applyTasksFilter();
    window.addEventListener('tasks-table-updated', applyTasksFilter);
})();
</script>

<!-- نقل قائمة إجراءات المهام إلى body لتفادي القص داخل الجدول -->
<script>
(function() {
    'use strict';

    function findTaskActionsMenu(dropdownEl) {
        var m = dropdownEl.querySelector('.dropdown-menu');
        if (m) return m;
        var inBody = document.body.querySelectorAll('.task-actions-dropdown-menu-inbody');
        for (var i = 0; i < inBody.length; i++) {
            if (inBody[i]._taskActionsParent === dropdownEl) {
                return inBody[i];
            }
        }
        return null;
    }

    function restoreTaskActionsMenu(dropdownEl, menu) {
        if (!menu) return;
        menu.classList.remove('task-actions-dropdown-menu-inbody');
        menu.removeAttribute('style');
        menu._taskActionsParent = null;
        if (dropdownEl && dropdownEl.isConnected) {
            dropdownEl.appendChild(menu);
        } else if (menu.parentNode === document.body) {
            document.body.removeChild(menu);
        }
    }

    function bindTaskActionsMenuEvents(menu) {
        if (!menu || menu._taskActionsMenuBound) return;
        menu._taskActionsMenuBound = true;
        // منع إغلاق القائمة قبل تنفيذ onclick عند نقلها إلى body
        menu.addEventListener('click', function(e) {
            e.stopPropagation();
        }, true);
        menu.addEventListener('mousedown', function(e) {
            if (e.target.closest('button.dropdown-item, a.dropdown-item')) {
                e.stopPropagation();
            }
        }, true);
    }

    function initTaskActionsDropdowns() {
        var wrapper = document.querySelector('.dashboard-table-wrapper');
        if (!wrapper) return;
        var dropdowns = wrapper.querySelectorAll('tbody tr .dropdown');
        dropdowns.forEach(function(dropdownEl) {
            if (dropdownEl._taskActionsInit) return;
            dropdownEl._taskActionsInit = true;
            var toggle = dropdownEl.querySelector('[data-bs-toggle="dropdown"]');
            var menu = dropdownEl.querySelector('.dropdown-menu');
            if (!toggle || !menu) return;
            bindTaskActionsMenuEvents(menu);
            dropdownEl.addEventListener('show.bs.dropdown', function(ev) {
                var el = ev.currentTarget;
                var m = findTaskActionsMenu(el);
                var tgl = el.querySelector('[data-bs-toggle="dropdown"]');
                if (!m || !tgl) return;
                bindTaskActionsMenuEvents(m);
                m._taskActionsParent = el;
                var rect = tgl.getBoundingClientRect();
                m.classList.add('task-actions-dropdown-menu-inbody');
                document.body.appendChild(m);
                var menuFullHeight = m.scrollHeight || 400;
                var spaceBelow = window.innerHeight - rect.bottom - 8;
                var spaceAbove = rect.top - 8;
                var openAbove = spaceBelow < spaceAbove;
                var maxH = openAbove
                    ? Math.min(menuFullHeight, window.innerHeight * 0.7, Math.max(120, spaceAbove))
                    : Math.min(menuFullHeight, window.innerHeight * 0.7, Math.max(120, spaceBelow));
                var style = m.style;
                style.position = 'fixed';
                style.display = 'block';
                style.visibility = 'visible';
                if (document.documentElement.dir === 'rtl') {
                    style.right = (window.innerWidth - rect.right) + 'px';
                    style.left = 'auto';
                } else {
                    style.left = rect.left + 'px';
                }
                var topPos = openAbove ? Math.max(8, rect.top - maxH) : rect.bottom + 2;
                style.top = topPos + 'px';
                style.minWidth = Math.max(rect.width, 180) + 'px';
                style.maxHeight = maxH + 'px';
                style.overflowY = 'auto';
                style.zIndex = '1060';
            });
            dropdownEl.addEventListener('hide.bs.dropdown', function(ev) {
                var el = ev.currentTarget;
                var m = findTaskActionsMenu(el);
                if (!m) return;
                // تأخير إعادة القائمة حتى يُنفَّذ النقر على زر الإجراء أولاً
                setTimeout(function() {
                    restoreTaskActionsMenu(el, m);
                }, 0);
            });
        });
    }

    window.initTaskActionsDropdowns = initTaskActionsDropdowns;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTaskActionsDropdowns);
    } else {
        initTaskActionsDropdowns();
    }
    window.addEventListener('tasks-table-updated', initTaskActionsDropdowns);
})();
</script>

<!-- تحديد أوردرات متعددة للطباعة -->
<script>
(function() {
    'use strict';
    const selectAll = document.getElementById('selectAllTasks');
    const checkboxes = document.querySelectorAll('.task-print-checkbox');
    const printBtn = document.getElementById('printSelectedReceiptsBtn');
    const selectedCountEl = document.getElementById('selectedCount');

    function updateSelection() {
        const checked = document.querySelectorAll('.task-print-checkbox:checked');
        const n = checked.length;
        if (selectedCountEl) selectedCountEl.textContent = n;
        if (printBtn) printBtn.disabled = n === 0;
        if (selectAll) selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
        if (selectAll) selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
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
            const checked = document.querySelectorAll('.task-print-checkbox:checked');
            const ids = [];
            checked.forEach(function(cb) {
                const id = cb.value;
                if (id) ids.push(id);
            });
            if (ids.length === 0) return;
            const firstUrl = document.querySelector('.task-print-checkbox') && document.querySelector('.task-print-checkbox').getAttribute('data-print-url');
            const path = firstUrl ? firstUrl.split('?')[0] : 'print_task_receipt.php';
            const url = path + '?ids=' + ids.join(',');
            window.open(url, '_blank', 'noopener,noreferrer');
        });
    }
    updateSelection();
})();
</script>

<!-- آلية منع Cache وضمان تحديث البيانات -->
<script>
(function() {
    'use strict';
    
    // إزالة معاملات timestamp من URL بعد التحميل
    const url = new URL(window.location.href);
    let urlChanged = false;
    
    ['_t', '_r', '_refresh'].forEach(function(param) {
        if (url.searchParams.has(param)) {
            url.searchParams.delete(param);
            urlChanged = true;
        }
    });
    
    if (urlChanged) {
        window.history.replaceState({}, '', url.toString());
    }
    
    // التحقق من وجود رسالة نجاح أو خطأ
    // تم إزالة إعادة التوجيه التلقائية بعد 1.5 ثانية لمنع إعادة التوجيه غير المرغوب
    // يمكن للمستخدم إغلاق الرسالة يدوياً أو الانتظار حتى تختفي تلقائياً
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // إزالة معاملات success/error من URL بدون إعادة تحميل
    if (successAlert || errorAlert) {
        const url = new URL(window.location.href);
        if (url.searchParams.has('success') || url.searchParams.has('error')) {
            url.searchParams.delete('success');
            url.searchParams.delete('error');
            window.history.replaceState({}, '', url.toString());
        }
    }
    
    // === حل جذري: منع استخدام cache عند CTRL+R أو F5 ===
    
    const PAGE_LOAD_KEY = 'tasks_page_load_timestamp';
    const FORCE_RELOAD_KEY = 'tasks_force_reload';
    
    // حفظ timestamp عند تحميل الصفحة
    try {
        const currentTimestamp = Date.now().toString();
        const previousTimestamp = sessionStorage.getItem(PAGE_LOAD_KEY);
        
        // إذا كان هناك timestamp سابق، فهذا يعني refresh
        if (previousTimestamp && previousTimestamp !== currentTimestamp) {
            sessionStorage.setItem(FORCE_RELOAD_KEY, 'true');
        }
        
        sessionStorage.setItem(PAGE_LOAD_KEY, currentTimestamp);
    } catch (e) {
        // تجاهل إذا كان sessionStorage غير متاح
    }
    
    // تم تعطيل معالجة pageshow event لمنع إعادة التوجيه غير المرغوب
    // يمكن للمستخدم استخدام F5 أو CTRL+R يدوياً عند الحاجة
    // window.addEventListener('pageshow', function(event) {
    //     // كود معطل لمنع إعادة التوجيه التلقائية
    // });
    
    // عند الضغط على F5 أو CTRL+R، احفظ flag قبل reload - استخدام pagehide لإعادة تفعيل bfcache
    window.addEventListener('pagehide', function(event) {
        // فقط إذا لم يكن من bfcache (أي refresh حقيقي)
        if (!event.persisted) {
            try {
                sessionStorage.setItem(FORCE_RELOAD_KEY, 'true');
            } catch (e) {
                // تجاهل
            }
        }
    });
    
    // إزالة meta tags التي تمنع bfcache - استخدام private بدلاً من no-store
    // ملاحظة: تم إزالة هذه الـ meta tags لأنها تمنع bfcache
    // يمكن استخدام Cache-Control: private في headers بدلاً منها
})();
</script>

<?php // تمييز عمال الإنتاج لاستخدام ريفريش كامل للصفحة بدل التحديث الجزئي للجدول ?>
<script>window.TASKS_IS_PRODUCTION = <?php echo $isProduction ? 'true' : 'false'; ?>;</script>
<!-- آلية التحديث: عمال الإنتاج = ريفريش كامل للصفحة. غير الإنتاج = بدون تحديث تلقائي -->
<script>
(function() {
    'use strict';
    
    if (!window.location.search.includes('page=tasks')) return;
    
    var isProduction = window.TASKS_IS_PRODUCTION === true;
    var autoRefreshInterval = null;
    var pageRefreshTimeout = null;
    
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(function() {});
    }
    
    function detectConnectionType() {
        if ('connection' in navigator) {
            var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (conn) {
                var et = conn.effectiveType || '';
                var t = conn.type || '';
                if (conn.saveData || t === 'cellular' || et === '2g' || et === 'slow-2g') return true;
            }
        }
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }
    
    function startAutoRefresh() {
        if (!isProduction) return;
        if (autoRefreshInterval) clearInterval(autoRefreshInterval);
        if (pageRefreshTimeout) clearTimeout(pageRefreshTimeout);
        var isMobileData = detectConnectionType();
        var initialDelay = 300000;   // أول ريفريش بعد 5 دقائق
        var refreshInterval = 300000; // ريفريش كل 5 دقائق
        pageRefreshTimeout = setTimeout(function() {
            if (!document.hidden) window.location.reload();
        }, initialDelay);
        autoRefreshInterval = setInterval(function() {
            if (!document.hidden) window.location.reload();
        }, refreshInterval);
    }
    
    function stopAutoRefresh() {
        if (autoRefreshInterval) { clearInterval(autoRefreshInterval); autoRefreshInterval = null; }
        if (pageRefreshTimeout) { clearTimeout(pageRefreshTimeout); pageRefreshTimeout = null; }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startAutoRefresh);
    } else {
        startAutoRefresh();
    }
    window.addEventListener('pagehide', stopAutoRefresh);
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) stopAutoRefresh();
        else if (isProduction) startAutoRefresh();
    });
    window.handleTasksUpdate = function() {
        if (isProduction && !document.hidden) window.location.reload();
    };
})();

// ===== دوال Modal/Card Dual System =====

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
        const offset = 80;
        
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
    const cards = ['addTaskCard', 'viewTaskCard'];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    // إغلاق جميع Modals على الكمبيوتر
    const modals = ['addTaskModal', 'viewTaskModal', 'orderReceiptModal'];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// دوال إغلاق Cards
function closeAddTaskCard() {
    const card = document.getElementById('addTaskCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
        const productionFields = document.getElementById('production_fields_card');
        if (productionFields) productionFields.style.display = 'none';
    }
}

function closeViewTaskCard() {
    const card = document.getElementById('viewTaskCard');
    if (card) {
        card.style.display = 'none';
    }
}

// دالة فتح نموذج إضافة مهمة
function showAddTaskModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addTaskCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
            
            // ربط event listeners
            const taskTypeSelect = document.getElementById('task_type_card');
            const productionFields = document.getElementById('production_fields_card');
            if (taskTypeSelect && productionFields) {
                taskTypeSelect.addEventListener('change', function() {
                    if (this.value === 'production') {
                        productionFields.style.display = 'block';
                    } else {
                        productionFields.style.display = 'none';
                    }
                });
            }
        }
    } else {
        const modal = document.getElementById('addTaskModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// تعديل دالة viewTask لدعم الموبايل (تعمل دائماً سواء وُجدت الدالة الأصلية أم لا)
(function() {
    function viewTaskMobile(taskId) {
        closeAllForms();
        fetch('?ajax=1&task_id=' + encodeURIComponent(taskId))
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.task) {
                    var task = data.task;
                    var title = (task.title != null && task.title !== '') ? String(task.title) : '-';
                    var desc = (task.description != null && task.description !== '') ? String(task.description) : '-';
                    var status = (task.status != null && task.status !== '') ? String(task.status) : '-';
                    var priority = (task.priority != null && task.priority !== '') ? String(task.priority) : '-';
                    var content = '<div class="mb-3"><strong>العنوان:</strong> ' + title + '</div>' +
                        '<div class="mb-3"><strong>الوصف:</strong> ' + desc + '</div>' +
                        '<div class="mb-3"><strong>الحالة:</strong> ' + status + '</div>' +
                        '<div class="mb-3"><strong>الأولوية:</strong> ' + priority + '</div>';
                    if (isMobile()) {
                        var card = document.getElementById('viewTaskCard');
                        var contentEl = document.getElementById('viewTaskContentCard');
                        if (card && contentEl) {
                            contentEl.innerHTML = content;
                            card.style.display = 'block';
                            setTimeout(function() { scrollToElement(card); }, 50);
                        }
                    } else {
                        var modal = document.getElementById('viewTaskModal');
                        var contentEl = document.getElementById('viewTaskContent');
                        if (modal && contentEl) {
                            contentEl.innerHTML = content;
                            var modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
                            modalInstance.show();
                        }
                    }
                } else {
                    alert('حدث خطأ في تحميل بيانات المهمة');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert('حدث خطأ في تحميل بيانات المهمة');
            });
    }
    window.viewTask = viewTaskMobile;
})();

// ===== سكريبت الطباعة التلقائية عند البحث =====
(function() {
    'use strict';
    
    // إضافة event listener لزر البحث
    const searchBtn = document.getElementById('tasksSearchBtn');
    if (searchBtn) {
        searchBtn.addEventListener('click', function(e) {
            // الانتظار قليلاً لتحديث الجدول بعد البحث
            setTimeout(function() {
                const tbody = document.getElementById('tasksTableBody');
                if (tbody) {
                    // التحقق من وجود صفوف مرئية (غير مخفية)
                    const visibleRows = tbody.querySelectorAll('tr.tasks-filter-row:not([style*="display: none"])');
                    
                    if (visibleRows.length > 0) {
                        // إذا كان هناك نتائج، طباعة تلقائية
                        const taskId = visibleRows[0].getAttribute('data-task-id');
                        if (taskId) {
                            const printUrl = 'print_task_receipt.php?id=' + taskId;
                            window.open(printUrl, '_blank', 'noopener,noreferrer');
                        }
                    }
                }
            }, 500); // انتظار 500ms لتحديث الجدول
        });
    }
})();
</script>

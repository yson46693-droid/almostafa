<?php
/**
 * صفحة إدارة المهام (نسخة مبسطة محسّنة)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['production', 'accountant', 'manager', 'developer', 'driver']);

// التحقق من وجود عمود product_name في جدول tasks وإضافته إذا لم يكن موجوداً
try {
    $db = db();
    $productNameColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'product_name'");
    if (empty($productNameColumn)) {
        // محاولة إضافة الحقل بعد template_id إذا كان موجوداً
        $templateIdColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'template_id'");
        if (!empty($templateIdColumn)) {
            $db->execute("ALTER TABLE tasks ADD COLUMN product_name VARCHAR(255) NULL AFTER template_id");
        } else {
            // إذا لم يكن template_id موجوداً، أضف بعد product_id
            $db->execute("ALTER TABLE tasks ADD COLUMN product_name VARCHAR(255) NULL AFTER product_id");
        }
        error_log('Added product_name column to tasks table in production/tasks.php');
    }
    
    // التحقق من وجود عمود task_type في جدول tasks وإضافته إذا لم يكن موجوداً
    $taskTypeColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'task_type'");
    if (empty($taskTypeColumn)) {
        // إضافة الحقل بعد status
        $db->execute("ALTER TABLE tasks ADD COLUMN task_type VARCHAR(50) NULL DEFAULT 'general' AFTER status");
        error_log('Added task_type column to tasks table in production/tasks.php');
    }
    
    // التحقق من وجود عمود unit في جدول tasks وإضافته إذا لم يكن موجوداً
    $unitColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'unit'");
    if (empty($unitColumn)) {
        // إضافة الحقل بعد quantity
        $db->execute("ALTER TABLE tasks ADD COLUMN unit VARCHAR(50) NULL DEFAULT 'قطعة' AFTER quantity");
        error_log('Added unit column to tasks table in production/tasks.php');
    }
    // التحقق من وجود عمود customer_name في جدول tasks
    $customerNameColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'customer_name'");
    if (empty($customerNameColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN customer_name VARCHAR(255) NULL DEFAULT NULL AFTER unit");
        error_log('Added customer_name column to tasks table in production/tasks.php');
    }
    // التحقق من وجود عمود customer_phone في جدول tasks
    $customerPhoneColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'customer_phone'");
    if (empty($customerPhoneColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN customer_phone VARCHAR(50) NULL DEFAULT NULL AFTER customer_name");
        error_log('Added customer_phone column to tasks table in production/tasks.php');
    }
    // التحقق من وجود عمود receipt_print_count لتتبع عدد مرات طباعة إيصال الأوردر
    $receiptPrintCountColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'receipt_print_count'");
    if (empty($receiptPrintCountColumn)) {
        $db->execute("ALTER TABLE tasks ADD COLUMN receipt_print_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER notes");
        error_log('Added receipt_print_count column to tasks table in production/tasks.php');
    }
    // توسيع عمود status ليشمل "تم التوصيل" و "تم الارجاع" و "مع المندوب"
    $statusColumn = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'status'");
    if (!empty($statusColumn['Type'])) {
        $typeStr = (string) $statusColumn['Type'];
        if (stripos($typeStr, 'with_delegate') === false) {
            $db->execute("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending','received','in_progress','completed','with_delegate','delivered','returned','cancelled') DEFAULT 'pending'");
            error_log('Extended tasks.status ENUM with with_delegate in production/tasks.php');
        }
    }
} catch (Exception $e) {
    error_log('Error checking/adding columns in production/tasks.php: ' . $e->getMessage());
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

                // إرسال إشعارات للعمال المخصصين
                if ($assignedTo > 0) {
                    try {
                        $notificationTitle = 'مهمة جديدة من الإدارة';
                        $notificationMessage = $title;
                        // استخدام getDashboardUrl لبناء URL صحيح يحتوي على /dashboard/
                        $notificationUrl = getDashboardUrl('production') . '?page=tasks';
                        createNotification(
                            $assignedTo,
                            $notificationTitle,
                            $notificationMessage,
                            'info',
                            $notificationUrl
                        );
                    } catch (Throwable $notificationError) {
                        error_log('Task creation notification error: ' . $notificationError->getMessage());
                    }
                }

                // إرسال إشعار لعمال الإنتاج والمدير والمحاسب مع اسم منشئ الأوردر
                try {
                    $creatorName = $currentUser['full_name'] ?? $currentUser['name'] ?? 'غير معروف';
                    $taskSummary = !empty($displayProductName) ? $displayProductName : ($title ?: 'مهمة جديدة');
                    if ($quantity > 0 && !empty($displayProductName)) {
                        $taskSummary .= ' - ' . number_format($quantity, 2) . ' ' . $unit;
                    }
                    $notificationTitle = 'أوردر جديد';
                    $notificationMessage = $taskSummary . ' - أنشأه: ' . $creatorName;
                    $productionUrl = getDashboardUrl('production') . '?page=tasks';
                    $managerUrl = getDashboardUrl('manager') . '?page=tasks';
                    $accountantUrl = getDashboardUrl('accountant') . '?page=tasks';
                    createNotificationForRole('production', $notificationTitle, $notificationMessage, 'info', $productionUrl, true);
                    createNotificationForRole('manager', $notificationTitle, $notificationMessage, 'info', $managerUrl, true);
                    createNotificationForRole('accountant', $notificationTitle, $notificationMessage, 'info', $accountantUrl, true);
                } catch (Throwable $notificationError) {
                    error_log('Task order notification error: ' . $notificationError->getMessage());
                }

                $result['success'] = 'تم إضافة المهمة بنجاح';
                break;

            case 'receive_task':
            case 'start_task':
            case 'complete_task':
            case 'with_delegate_task':
            case 'deliver_task':
            case 'return_task':
                $taskId = isset($input['task_id']) ? (int) $input['task_id'] : 0;
                if ($taskId <= 0) {
                    throw new RuntimeException('معرف المهمة غير صحيح');
                }

                $task = $db->queryOne('SELECT assigned_to, status, title, created_by, notes FROM tasks WHERE id = ?', [$taskId]);
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
                    if ($currentStatus !== 'completed' && $currentStatus !== 'with_delegate') {
                        throw new RuntimeException('يمكن تطبيق تم التوصيل أو تم الارجاع على المهام المكتملة أو المعطاة للمندوب فقط');
                    }
                } elseif ($action === 'with_delegate_task') {
                    if (!$isManager && !$isProduction && !$isDriver) {
                        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                    }
                    if (($task['status'] ?? '') !== 'completed') {
                        throw new RuntimeException('يمكن تطبيق مع المندوب على المهام المكتملة فقط');
                    }
                } else {
                    if (!$isProduction) {
                        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                    }
                    if (!$isAssignedToProduction) {
                        throw new RuntimeException('هذه المهمة غير مخصصة لعامل إنتاج');
                    }
                }

                $statusMap = [
                    'receive_task' => ['status' => 'received', 'column' => 'received_at'],
                    'start_task' => ['status' => 'in_progress', 'column' => 'started_at'],
                    'complete_task' => ['status' => 'completed', 'column' => 'completed_at'],
                    'with_delegate_task' => ['status' => 'with_delegate', 'column' => 'completed_at'],
                    'deliver_task' => ['status' => 'delivered', 'column' => 'completed_at'],
                    'return_task' => ['status' => 'returned', 'column' => 'completed_at'],
                ];

                $update = $statusMap[$action];
                $db->execute(
                    "UPDATE tasks SET status = ?, {$update['column']} = NOW() WHERE id = ?",
                    [$update['status'], $taskId]
                );

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
                $validStatuses = ['pending', 'received', 'in_progress', 'completed', 'with_delegate', 'delivered', 'returned', 'cancelled'];

                if ($taskId <= 0 || !in_array($status, $validStatuses, true)) {
                    throw new RuntimeException('بيانات غير صحيحة لتحديث المهمة');
                }

                if ($isDriver) {
                    $driverAllowedStatuses = ['with_delegate', 'delivered', 'returned'];
                    if (!in_array($status, $driverAllowedStatuses, true)) {
                        throw new RuntimeException('غير مصرح لك بتغيير حالة المهمة إلى هذه الحالة');
                    }
                } elseif (!$isManager) {
                    throw new RuntimeException('غير مصرح لك بتغيير حالة المهمة');
                }

                $setParts = ['status = ?'];
                $values = [$status];

                $setParts[] = in_array($status, ['completed', 'with_delegate', 'delivered', 'returned'], true) ? 'completed_at = NOW()' : 'completed_at = NULL';
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
    $whereConditions[] = "t.status NOT IN ('completed','with_delegate','delivered','returned','cancelled')";
    $whereConditions[] = 't.due_date < CURDATE()';
}

// السائق يرى كل الأوردرات: مكتملة، مع المندوب، تم التوصيل، تم الارجاع — والفلترة تعمل ضمنها
if ($isDriver) {
    $driverAllowedStatuses = ['completed', 'with_delegate', 'delivered', 'returned'];
    if ($statusFilter !== '' && in_array($statusFilter, $driverAllowedStatuses, true)) {
        $whereConditions[] = 't.status = ?';
        $params[] = $statusFilter;
    } else {
        $whereConditions[] = "t.status IN ('completed', 'with_delegate', 'delivered', 'returned')";
    }
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

// السماح لجميع عمال الإنتاج برؤية جميع المهام المخصصة لأي عامل إنتاج
// لا حاجة للفلترة - جميع عمال الإنتاج يرون جميع المهام

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$totalRow = $db->queryOne('SELECT COUNT(*) AS total FROM tasks t ' . $whereClause, $params);
$totalTasks = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;
$totalPages = max(1, (int) ceil($totalTasks / $perPage));

// التحقق من وجود جداول القوالب قبل إضافة JOIN
$unifiedTemplatesExists = !empty($db->queryOne("SHOW TABLES LIKE 'unified_product_templates'"));
$productTemplatesExists = !empty($db->queryOne("SHOW TABLES LIKE 'product_templates'"));
// #region agent log
// كتابة آمنة في debug.log - التحقق من وجود المجلد أولاً
$debugLogPath = __DIR__ . '/../../.cursor/debug.log';
$debugLogDir = dirname($debugLogPath);
if (!is_dir($debugLogDir)) {
    @mkdir($debugLogDir, 0755, true);
}
if (is_dir($debugLogDir) && is_writable($debugLogDir)) {
    @file_put_contents($debugLogPath, json_encode([
        'timestamp' => time() * 1000,
        'location' => 'tasks.php:' . __LINE__,
        'message' => 'Template tables check',
        'data' => [
            'unifiedTemplatesExists' => $unifiedTemplatesExists,
            'productTemplatesExists' => $productTemplatesExists
        ],
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'B'
    ]) . "\n", FILE_APPEND);
}
// #endregion

// SQL query لجلب المهام مع استخدام t.product_name مباشرة من الجدول (نفس طريقة طلبات العملاء)
// استخدام t.product_name مباشرة قبل t.* لتجنب أي تعارض
// إضافة JOIN مع جداول القوالب لجلب اسم القالب عند وجود template_id
$templateJoins = '';
$templateSelect = '';
if ($unifiedTemplatesExists && $productTemplatesExists) {
    // كلا الجدولين موجودان: استخدام COALESCE للبحث في كليهما
    $templateSelect = ', COALESCE(upt.product_name, pt.product_name) AS template_name';
    $templateJoins = 'LEFT JOIN unified_product_templates upt ON t.template_id = upt.id AND upt.status = \'active\' ';
    $templateJoins .= 'LEFT JOIN product_templates pt ON t.template_id = pt.id AND pt.status = \'active\' ';
} elseif ($unifiedTemplatesExists) {
    // فقط unified_product_templates موجود
    $templateSelect = ', upt.product_name AS template_name';
    $templateJoins = 'LEFT JOIN unified_product_templates upt ON t.template_id = upt.id AND upt.status = \'active\' ';
} elseif ($productTemplatesExists) {
    // فقط product_templates موجود
    $templateSelect = ', pt.product_name AS template_name';
    $templateJoins = 'LEFT JOIN product_templates pt ON t.template_id = pt.id AND pt.status = \'active\' ';
}

$customerOrdersExists = !empty($db->queryOne("SHOW TABLES LIKE 'customer_orders'"));
$orderCustomerJoin = '';
$customerDisplaySelect = ", t.customer_name, COALESCE(NULLIF(TRIM(IFNULL(t.customer_name,'')), ''), '') AS customer_display";
if ($customerOrdersExists) {
    $orderCustomerJoin = " LEFT JOIN customer_orders co ON t.related_type = 'customer_order' AND t.related_id = co.id LEFT JOIN customers cust ON co.customer_id = cust.id";
    $customerDisplaySelect = ", t.customer_name, COALESCE(NULLIF(TRIM(t.customer_name), ''), cust.name) AS customer_display";
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
// #region agent log
// كتابة آمنة في debug.log - التحقق من وجود المجلد أولاً
$debugLogPath = __DIR__ . '/../../.cursor/debug.log';
$debugLogDir = dirname($debugLogPath);
if (!is_dir($debugLogDir)) {
    @mkdir($debugLogDir, 0755, true);
}
if (is_dir($debugLogDir) && is_writable($debugLogDir)) {
    @file_put_contents($debugLogPath, json_encode([
        'timestamp' => time() * 1000,
        'location' => 'tasks.php:' . __LINE__,
        'message' => 'SQL query with template joins',
        'data' => [
            'templateSelect' => $templateSelect,
            'templateJoins' => $templateJoins,
            'sql_preview' => substr($taskSql, 0, 200) . '...'
        ],
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'B'
    ]) . "\n", FILE_APPEND);
}
// #endregion
$tasks = $db->query($taskSql, $queryParams);

// استخراج جميع العمال من notes لكل مهمة واستخراج اسم المنتج من notes إذا لم يكن موجوداً
foreach ($tasks as &$task) {
    // #region agent log
    $logPath = '../../.cursor/debug.log';
    $logData = [
        'task_id' => $task['id'] ?? 0,
        'template_id' => $task['template_id'] ?? null,
        'template_name' => $task['template_name'] ?? null,
        'product_name' => $task['product_name'] ?? null,
        'product_id' => $task['product_id'] ?? null,
        'has_template_name_key' => isset($task['template_name']),
        'all_keys' => array_keys($task)
    ];
    $logEntry = json_encode([
        'timestamp' => time() * 1000,
        'location' => 'tasks.php:' . __LINE__,
        'message' => 'Task raw data from database',
        'data' => $logData,
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'B'
    ]) . "\n";
    @file_put_contents($logPath, $logEntry, FILE_APPEND);
    error_log('DEBUG: Task ' . ($task['id'] ?? 0) . ' - template_id: ' . ($task['template_id'] ?? 'NULL') . ', template_name: ' . ($task['template_name'] ?? 'NULL'));
    // #endregion
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
    if (empty($allWorkers) && !empty($task['assigned_to_name'])) {
        $allWorkers[] = $task['assigned_to_name'];
    }
    
    // استخدام اسم المنتج/القالب مباشرة من حقل product_name في جدول tasks
    // نفس الطريقة المستخدمة في طلبات العملاء (السطر 1107-1109 في customer_orders.php):
    // أولاً التحقق من product_name المحفوظ مباشرة في الجدول
    $finalProductName = null;
    
    // الأولوية الأولى: استخدام product_name من الجدول مباشرة (نفس طريقة طلبات العملاء)
    // نفس الكود في customer_orders.php السطر 1107: if (!empty($item['product_name']))
    // التحقق من وجود القيمة وعدم كونها NULL أو فارغة
    if (isset($task['product_name']) && $task['product_name'] !== null && $task['product_name'] !== '') {
        $trimmedName = trim((string)$task['product_name']);
        if ($trimmedName !== '') {
            $finalProductName = $trimmedName;
        }
    }
    
    // الأولوية الثانية: استخدام template_name من JOIN مع جداول القوالب (عند وجود template_id)
    // هذا يحل المشكلة عندما يتم حفظ template_id ولكن product_name يكون NULL
    // #region agent log
    $logPath = '../../.cursor/debug.log';
    $logData = [
        'task_id' => $task['id'] ?? 0,
        'template_id' => $task['template_id'] ?? null,
        'product_name_before' => $task['product_name'] ?? null,
        'template_name' => $task['template_name'] ?? null,
        'finalProductName_before_template' => $finalProductName,
        'template_name_empty' => empty($task['template_name']),
        'template_name_isset' => isset($task['template_name'])
    ];
    $logEntry = json_encode([
        'timestamp' => time() * 1000,
        'location' => 'tasks.php:' . __LINE__,
        'message' => 'Checking template_name for task',
        'data' => $logData,
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'A'
    ]) . "\n";
    @file_put_contents($logPath, $logEntry, FILE_APPEND);
    error_log('DEBUG: Task ' . ($task['id'] ?? 0) . ' - Checking template_name. template_id: ' . ($task['template_id'] ?? 'NULL') . ', template_name: ' . var_export($task['template_name'] ?? null, true) . ', empty: ' . (empty($task['template_name']) ? 'YES' : 'NO'));
    // #endregion
    if (empty($finalProductName) && !empty($task['template_name'])) {
        $trimmedName = trim((string)$task['template_name']);
        if ($trimmedName !== '') {
            $finalProductName = $trimmedName;
            // #region agent log
            $logEntry = json_encode([
                'timestamp' => time() * 1000,
                'location' => 'tasks.php:' . __LINE__,
                'message' => 'Using template_name as finalProductName',
                'data' => ['task_id' => $task['id'] ?? 0, 'template_name' => $trimmedName, 'finalProductName' => $finalProductName],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A'
            ]) . "\n";
            @file_put_contents($logPath, $logEntry, FILE_APPEND);
            error_log('DEBUG: Task ' . ($task['id'] ?? 0) . ' - Using template_name: ' . $trimmedName);
            // #endregion
        }
    }
    
    // الأولوية الثالثة: استخدام product_name_from_db من JOIN مع products (للتوافق مع المهام القديمة)
    if (empty($finalProductName) && !empty($task['product_name_from_db'])) {
        $trimmedName = trim((string)$task['product_name_from_db']);
        if ($trimmedName !== '') {
            $finalProductName = $trimmedName;
        }
    }
    
    // الأولوية الرابعة: استخراج من notes (للتوافق مع المهام القديمة جداً)
    if (empty($finalProductName) && !empty($notes)) {
        // البحث عن "المنتج: " متبوعاً باسم المنتج
        if (preg_match('/المنتج:\s*([^\n\r]+?)\s*-\s*الكمية:/i', $notes, $productMatches)) {
            $finalProductName = trim($productMatches[1] ?? '');
        } elseif (preg_match('/المنتج:\s*([^\n\r]+?)(?:\n|$)/i', $notes, $productMatches2)) {
            $finalProductName = trim($productMatches2[1] ?? '');
            $finalProductName = preg_replace('/\s*-\s*الكمية:.*$/i', '', $finalProductName);
            $finalProductName = trim($finalProductName);
        } elseif (preg_match('/المنتج:\s*([^\n\r]+)/i', $notes, $productMatches3)) {
            $finalProductName = trim($productMatches3[1] ?? '');
            $finalProductName = preg_replace('/\s*-\s*الكمية:.*$/i', '', $finalProductName);
            $finalProductName = trim($finalProductName);
        }
        
        if (!empty($finalProductName)) {
            $finalProductName = trim($finalProductName, '-');
            $finalProductName = trim($finalProductName);
        }
    }
    
    // تعيين اسم المنتج النهائي (نفس طريقة طلبات العملاء - السطر 1971)
    // في customer_orders.php: echo htmlspecialchars($item['product_name'] ?? '-');
    // استخدام القيمة الفارغة بدلاً من null لضمان العرض الصحيح
    $task['product_name'] = !empty($finalProductName) ? $finalProductName : '';
    // #region agent log
    // كتابة آمنة في debug.log - التحقق من وجود المجلد أولاً
    $debugLogPath = __DIR__ . '/../../.cursor/debug.log';
    $debugLogDir = dirname($debugLogPath);
    if (!is_dir($debugLogDir)) {
        @mkdir($debugLogDir, 0755, true);
    }
    if (is_dir($debugLogDir) && is_writable($debugLogDir)) {
        @file_put_contents($debugLogPath, json_encode([
            'timestamp' => time() * 1000,
            'location' => 'tasks.php:' . __LINE__,
            'message' => 'Final product_name assigned to task',
            'data' => ['task_id' => $task['id'] ?? 0, 'final_product_name' => $task['product_name']],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A'
        ]) . "\n", FILE_APPEND);
    }
    // #endregion
    
    // إزالة product_name_from_db لأنه لم يعد مطلوباً
    unset($task['product_name_from_db']);
    
    $task['all_workers'] = $allWorkers;
    $task['workers_count'] = count($allWorkers);
}
unset($task);

$users = $db->query("SELECT id, full_name FROM users WHERE status = 'active' AND role = 'production' ORDER BY full_name");

// جلب القوالب (templates) لعرضها في القائمة المنسدلة
$products = [];
try {
    // محاولة جلب من unified_product_templates أولاً (الأحدث)
    $unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
    if (!empty($unifiedTemplatesCheck)) {
        $unifiedTemplates = $db->query("
            SELECT DISTINCT product_name as name
            FROM unified_product_templates 
            WHERE status = 'active' 
            ORDER BY product_name ASC
        ");
        foreach ($unifiedTemplates as $template) {
            // البحث عن product_id المقابل في جدول products
            $product = $db->queryOne(
                "SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$template['name']]
            );
            if ($product && !empty($product['id'])) {
                $products[] = [
                    'id' => (int)$product['id'],
                    'name' => $template['name']
                ];
            } else {
                // إذا لم يتم العثور على product_id، استخدم id سالب للتمييز
                // سنبحث عن product_id عند الحفظ باستخدام product_name
                // نستخدم id سالب كبير لتجنب التعارض مع أي product_id حقيقي
                $products[] = [
                    'id' => -999999, // سيتم التعامل معه عند الحفظ
                    'name' => $template['name']
                ];
            }
        }
    }
    
    // إذا لم توجد قوالب في unified_product_templates، جرب product_templates
    if (empty($products)) {
        $templatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
        if (!empty($templatesCheck)) {
            $legacyTemplates = $db->query("
                SELECT DISTINCT product_name as name
                FROM product_templates 
                WHERE status = 'active' 
                ORDER BY product_name ASC
            ");
            foreach ($legacyTemplates as $template) {
                // البحث عن product_id المقابل في جدول products
                $product = $db->queryOne(
                    "SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                    [$template['name']]
                );
                if ($product && !empty($product['id'])) {
                    $products[] = [
                        'id' => (int)$product['id'],
                        'name' => $template['name']
                    ];
                } else {
                    // إذا لم يتم العثور على product_id، استخدم id سالب للتمييز
                    // سنبحث عن product_id عند الحفظ باستخدام product_name
                    // نستخدم id سالب كبير لتجنب التعارض مع أي product_id حقيقي
                    $products[] = [
                        'id' => -999999, // سيتم التعامل معه عند الحفظ
                        'name' => $template['name']
                    ];
                }
            }
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

if ($isDriver) {
    // السائق: إحصائيات كل الأوردرات (مكتملة، مع المندوب، تم التوصيل، تم الارجاع)
    $statsBaseConditions = ["status IN ('completed', 'with_delegate', 'delivered', 'returned')"];
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
    'delivered' => $buildStatsQuery("status = 'delivered'"),
    'returned' => $buildStatsQuery("status = 'returned'"),
    'overdue' => $buildStatsQuery("status NOT IN ('completed','with_delegate','delivered','returned') AND due_date < CURDATE()")
];

$tasksJson = tasksSafeJsonEncode($tasks);

function tasksHtml(string $value): string
{
    return htmlspecialchars(tasksSafeString($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>

<div class="container-fluid">
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
        <h2 class="mb-0"><i class="bi bi-list-check me-2"></i>إدارة المهام</h2>
        <?php if ($isManager): ?>
            <button type="button" class="btn btn-primary" onclick="showAddTaskModal()">
                <i class="bi bi-plus-circle me-2"></i>إضافة مهمة جديدة
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
    ?>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <a href="<?php echo $filterBaseUrl; ?>" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === '' && !$overdueFilter ? 'bg-primary text-white' : 'border-primary'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === '' && !$overdueFilter ? 'text-white' : 'text-primary'; ?> mb-0"><?php echo $stats['total']; ?></h5>
                        <small class="<?php echo $statusFilter === '' && !$overdueFilter ? 'text-white-50' : 'text-muted'; ?>">إجمالي المهام</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-2">
            <a href="<?php echo $filterBaseUrl . (strpos($filterBaseUrl, '?') !== false ? '&' : '?'); ?>status=pending" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'pending' ? 'bg-warning text-dark' : 'border-warning'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'pending' ? 'text-dark' : 'text-warning'; ?> mb-0"><?php echo $stats['pending']; ?></h5>
                        <small class="<?php echo $statusFilter === 'pending' ? 'text-dark-50' : 'text-muted'; ?>">معلقة</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-2">
            <a href="<?php echo $filterBaseUrl . (strpos($filterBaseUrl, '?') !== false ? '&' : '?'); ?>status=received" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'received' ? 'bg-info text-white' : 'border-info'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'received' ? 'text-white' : 'text-info'; ?> mb-0"><?php echo $stats['received']; ?></h5>
                        <small class="<?php echo $statusFilter === 'received' ? 'text-white-50' : 'text-muted'; ?>">مستلمة</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-2">
            <a href="<?php echo $filterBaseUrl . (strpos($filterBaseUrl, '?') !== false ? '&' : '?'); ?>status=completed" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'completed' ? 'bg-success text-white' : 'border-success'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'completed' ? 'text-white' : 'text-success'; ?> mb-0"><?php echo $stats['completed']; ?></h5>
                        <small class="<?php echo $statusFilter === 'completed' ? 'text-white-50' : 'text-muted'; ?>">مكتملة</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-2">
            <a href="<?php echo $filterBaseUrl . (strpos($filterBaseUrl, '?') !== false ? '&' : '?'); ?>status=with_delegate" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'with_delegate' ? 'bg-info text-white' : 'border-info'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'with_delegate' ? 'text-white' : 'text-info'; ?> mb-0"><?php echo $stats['with_delegate']; ?></h5>
                        <small class="<?php echo $statusFilter === 'with_delegate' ? 'text-white-50' : 'text-muted'; ?>">مع المندوب</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-2">
            <a href="<?php echo $filterBaseUrl . (strpos($filterBaseUrl, '?') !== false ? '&' : '?'); ?>status=delivered" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'delivered' ? 'bg-success text-white' : 'border-success'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'delivered' ? 'text-white' : 'text-success'; ?> mb-0"><?php echo $stats['delivered']; ?></h5>
                        <small class="<?php echo $statusFilter === 'delivered' ? 'text-white-50' : 'text-muted'; ?>">تم التوصيل</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-2">
            <a href="<?php echo $filterBaseUrl . (strpos($filterBaseUrl, '?') !== false ? '&' : '?'); ?>status=returned" class="text-decoration-none">
                <div class="card <?php echo $statusFilter === 'returned' ? 'bg-secondary text-white' : 'border-secondary'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $statusFilter === 'returned' ? 'text-white' : 'text-secondary'; ?> mb-0"><?php echo $stats['returned']; ?></h5>
                        <small class="<?php echo $statusFilter === 'returned' ? 'text-white-50' : 'text-muted'; ?>">تم الارجاع</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-2">
            <a href="<?php echo $filterBaseUrl . (strpos($filterBaseUrl, '?') !== false ? '&' : '?'); ?>overdue=1" class="text-decoration-none">
                <div class="card <?php echo $overdueFilter ? 'bg-danger text-white' : 'border-danger'; ?> text-center h-100">
                    <div class="card-body p-2">
                        <h5 class="<?php echo $overdueFilter ? 'text-white' : 'text-danger'; ?> mb-0"><?php echo $stats['overdue']; ?></h5>
                        <small class="<?php echo $overdueFilter ? 'text-white-50' : 'text-muted'; ?>">متأخرة</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body p-3">
            <form method="GET" action="" id="tasksFilterForm">
                <input type="hidden" name="page" value="tasks">
                <?php if ($overdueFilter): ?><input type="hidden" name="overdue" value="1"><?php endif; ?>
                <!-- بحث سريع و بحث متقدم (نفس صفحة تسجيل مهام الإنتاج) -->
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label small mb-0">بحث سريع</label>
                        <input type="text" class="form-control form-control-sm" name="search_text" value="<?php echo tasksHtml($filterSearchText !== '' ? $filterSearchText : $search); ?>" placeholder="نص في العنوان، الملاحظات، العميل...">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search me-1"></i>بحث
                        </button>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#tasksAdvancedSearch" aria-expanded="false" aria-controls="tasksAdvancedSearch">
                            <i class="bi bi-funnel me-1"></i>بحث متقدم
                        </button>
                    </div>
                    <?php if ($filterTaskId !== '' || $filterCustomer !== '' || $filterOrderId !== '' || $filterTaskType !== '' || $filterDueFrom !== '' || $filterDueTo !== '' || $filterSearchText !== '' || $search !== ''): ?>
                    <div class="col-auto">
                        <a href="?page=tasks<?php echo $statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : ''; ?><?php echo $priorityFilter !== '' ? '&priority=' . rawurlencode($priorityFilter) : ''; ?><?php echo $assignedFilter > 0 ? '&assigned=' . $assignedFilter : ''; ?><?php echo $overdueFilter ? '&overdue=1' : ''; ?>" class="btn btn-outline-danger btn-sm">إزالة الفلتر</a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="collapse <?php echo ($filterTaskId !== '' || $filterCustomer !== '' || $filterOrderId !== '' || $filterTaskType !== '' || $filterDueFrom !== '' || $filterDueTo !== '') ? 'show' : ''; ?>" id="tasksAdvancedSearch">
                    <div class="row g-2 pt-2 border-top mt-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">رقم الطلب</label>
                            <input type="text" name="task_id" class="form-control form-control-sm" placeholder="#" value="<?php echo tasksHtml($filterTaskId); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">اسم العميل / هاتف</label>
                            <input type="text" name="search_customer" class="form-control form-control-sm" placeholder="اسم أو رقم" value="<?php echo tasksHtml($filterCustomer); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">رقم الأوردر</label>
                            <input type="text" name="search_order_id" class="form-control form-control-sm" placeholder="رقم الأوردر" value="<?php echo tasksHtml($filterOrderId); ?>">
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
                            <input type="date" name="due_date_from" class="form-control form-control-sm" value="<?php echo tasksHtml($filterDueFrom); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ تسليم إلى</label>
                            <input type="date" name="due_date_to" class="form-control form-control-sm" value="<?php echo tasksHtml($filterDueTo); ?>">
                        </div>
                    </div>
                </div>
                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label mb-1">الحالة</label>
                        <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                            <option value="">الكل</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>معلقة</option>
                            <option value="received" <?php echo $statusFilter === 'received' ? 'selected' : ''; ?>>مستلمة</option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>مكتملة</option>
                            <option value="with_delegate" <?php echo $statusFilter === 'with_delegate' ? 'selected' : ''; ?>>مع المندوب</option>
                            <option value="delivered" <?php echo $statusFilter === 'delivered' ? 'selected' : ''; ?>>تم التوصيل</option>
                            <option value="returned" <?php echo $statusFilter === 'returned' ? 'selected' : ''; ?>>تم الارجاع</option>
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>ملغاة</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label mb-1">الأولوية</label>
                        <select class="form-select form-select-sm" name="priority">
                            <option value="">الكل</option>
                            <option value="urgent" <?php echo $priorityFilter === 'urgent' ? 'selected' : ''; ?>>عاجلة</option>
                            <option value="normal" <?php echo $priorityFilter === 'normal' ? 'selected' : ''; ?>>عادية</option>
                            <option value="low" <?php echo $priorityFilter === 'low' ? 'selected' : ''; ?>>منخفضة</option>
                        </select>
                    </div>
                    <?php if ($isManager): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label mb-1">المخصص إلى</label>
                        <select class="form-select form-select-sm" name="assigned">
                            <option value="0">الكل</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo (int) $user['id']; ?>" <?php echo $assignedFilter === (int) $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo tasksHtml($user['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2 col-sm-6">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-search me-1"></i>بحث
                        </button>
                    </div>
                    <div class="col-md-1 col-sm-6">
                        <a href="?page=tasks" class="btn btn-secondary btn-sm w-100" title="إعادة تعيين">
                            <i class="bi bi-x"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-transparent border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h6 class="mb-0"><i class="bi bi-list-task me-2"></i>آخر المهام التي تم إرسالها</h6>
            <?php if (($isManager || $isProduction) && !empty($tasks)): ?>
            <button type="button" class="btn btn-outline-primary btn-sm" id="printSelectedReceiptsBtn" title="طباعة إيصالات الأوردرات المحددة" disabled>
                <i class="bi bi-printer me-1"></i>طباعة المحدد (<span id="selectedCount">0</span>)
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($tasks)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-5 text-muted"></i>
                    <p class="text-muted mt-3 mb-0">لا توجد مهام</p>
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
                                <th style="width: 60px;">#</th>
                                <?php if (!$isDriver): ?>
                                <th>الكمية</th>
                                <th>اسم العميل</th>
                                <?php else: ?>
                                <th>اسم العميل</th>
                                <?php endif; ?>
                                <th>نوع الاوردر</th>
                                <th>الحالة</th>
                                <th>تاريخ التسليم</th>
                                <th style="width: 180px;">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
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
                                    'delivered' => 'success',
                                    'returned' => 'secondary',
                                    'cancelled' => 'secondary',
                                ][$task['status']] ?? 'secondary';

                                $statusLabel = [
                                    'pending' => 'معلقة',
                                    'received' => 'مستلمة',
                                    'completed' => 'مكتملة',
                                    'with_delegate' => 'مع المندوب',
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

                                $overdue = !in_array($task['status'], ['completed', 'with_delegate', 'delivered', 'returned', 'cancelled'], true)
                                    && !empty($task['due_date'])
                                    && strtotime((string) $task['due_date']) < time();
                                ?>
                                <tr class="<?php echo $overdue ? 'table-danger' : ''; ?>" data-task-id="<?php echo (int) $task['id']; ?>">
                                    <?php if ($isManager || $isProduction): ?>
                                    <td>
                                        <input type="checkbox" class="form-check-input task-print-checkbox" value="<?php echo (int) $task['id']; ?>" data-print-url="<?php echo htmlspecialchars(getRelativeUrl('print_task_receipt.php?id=' . (int) $task['id']), ENT_QUOTES, 'UTF-8'); ?>">
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php 
                                        $printCount = (int) ($task['receipt_print_count'] ?? 0);
                                        if ($printCount > 0): 
                                        ?>
                                        <span class="badge bg-info mb-1" title="عدد مرات طباعة إيصال الاوردر" style="font-size: 0.7rem;"> <?php echo $printCount; ?> <?php echo $printCount === 1 ? '' : ''; ?></span>
                                        <?php endif; ?>
                                        <strong><?php echo (int) $task['id']; ?></strong>
                                    </td>
                                    <?php if (!$isDriver): ?>
                                    <td><?php 
                                        if (isset($task['quantity']) && $task['quantity'] !== null) {
                                            $unit = !empty($task['unit']) ? $task['unit'] : 'قطعة';
                                            echo number_format((float) $task['quantity'], 2) . ' ' . htmlspecialchars($unit);
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                    ?></td>
                                    <?php endif; ?>
                                    <td><?php 
                                        $customerDisplay = isset($task['customer_display']) ? trim((string)$task['customer_display']) : '';
                                        echo $customerDisplay !== '' ? tasksHtml($customerDisplay) : '<span class="text-muted">-</span>';
                                    ?></td>
                                    <?php if (!$isDriver): ?>
                                    <?php endif; ?>
                                    <td>
                                        <?php
                                        $relatedType = isset($task['related_type']) ? (string)$task['related_type'] : '';
                                        $displayType = (strpos($relatedType, 'manager_') === 0) ? substr($relatedType, 8) : ($task['task_type'] ?? 'general');
                                        $orderTypeLabels = ['shop_order' => 'اوردر محل', 'cash_customer' => 'عميل نقدي', 'telegraph' => 'تليجراف', 'shipping_company' => 'شركة شحن', 'general' => 'مهمة عامة', 'production' => 'إنتاج منتج'];
                                        echo tasksHtml($orderTypeLabels[$displayType] ?? $displayType);
                                        ?>
                                    </td>
                                    <td class="task-status-cell"><span class="badge bg-<?php echo $statusClass; ?>"><?php echo tasksHtml($statusLabel); ?></span></td>
                                    <td>
                                        <?php if (!empty($task['due_date'])): ?>
                                            <?php echo tasksHtml(date('Y-m-d', strtotime((string) $task['due_date']))); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="task-actions-cell">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if ($isProduction): ?>
                                                <?php 
                                                // التحقق من أن المهمة مخصصة لعامل إنتاج
                                                $taskAssignedTo = (int) ($task['assigned_to'] ?? 0);
                                                $assignedUserRole = null;
                                                $isTaskForProduction = false;
                                                
                                                // التحقق من assigned_to
                                                if ($taskAssignedTo > 0) {
                                                    $assignedUser = $db->queryOne('SELECT role FROM users WHERE id = ?', [$taskAssignedTo]);
                                                    $assignedUserRole = $assignedUser['role'] ?? null;
                                                    if ($assignedUserRole === 'production') {
                                                        $isTaskForProduction = true;
                                                    }
                                                }
                                                
                                                // التحقق من notes للعثور على جميع العمال المخصصين
                                                if (!$isTaskForProduction && !empty($task['notes'])) {
                                                    if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $task['notes'], $matches)) {
                                                        $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
                                                        if (!empty($workerIds)) {
                                                            // التحقق من أن أحد العمال المخصصين هو عامل إنتاج
                                                            $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
                                                            $workersCheck = $db->queryOne(
                                                                "SELECT COUNT(*) as count FROM users WHERE id IN ($placeholders) AND role = 'production'",
                                                                $workerIds
                                                            );
                                                            if ($workersCheck && (int)$workersCheck['count'] > 0) {
                                                                $isTaskForProduction = true;
                                                            }
                                                        }
                                                    }
                                                }
                                                
                                                // السماح لأي عامل إنتاج بتغيير حالة أي مهمة مخصصة لعامل إنتاج - زر إكمال فقط
                                                if ($isTaskForProduction && in_array($task['status'], ['pending', 'received', 'in_progress'])): 
                                                ?>
                                                    <button type="button" class="btn btn-outline-success" onclick="submitTaskAction('complete_task', <?php echo (int) $task['id']; ?>)">
                                                        <i class="bi bi-check2-circle me-1"></i>إكمال
                                                    </button>
                                                <?php endif; ?>
                                                <?php
                                                // بعد مكتملة أو مع المندوب: زر مع المندوب (فقط من مكتملة)، ثم تم التوصيل و تم الارجاع (السائق يرى تم التوصيل/تم الارجاع فقط)
                                                $canWithDelegate = ($isManager || $isProduction) && ($task['status'] ?? '') === 'completed';
                                                $canDeliverReturn = ($isManager || $isProduction || $isDriver) && in_array($task['status'] ?? '', ['completed', 'with_delegate'], true);
                                                if ($canWithDelegate):
                                                ?>
                                                    <button type="button" class="btn btn-outline-info btn-sm" onclick="submitTaskAction('with_delegate_task', <?php echo (int) $task['id']; ?>)">
                                                        <i class="bi bi-person-badge me-1"></i>مع المندوب
                                                    </button>
                                                <?php endif;
                                                if ($canDeliverReturn):
                                                ?>
                                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="submitTaskAction('deliver_task', <?php echo (int) $task['id']; ?>)">
                                                        <i class="bi bi-truck me-1"></i>تم التوصيل
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="submitTaskAction('return_task', <?php echo (int) $task['id']; ?>)">
                                                        <i class="bi bi-arrow-return-left me-1"></i>تم الارجاع
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($isDriver): ?>
                                                <?php
                                                $canDeliverReturnDriver = in_array($task['status'] ?? '', ['completed', 'with_delegate'], true);
                                                if ($canDeliverReturnDriver):
                                                ?>
                                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="submitTaskAction('deliver_task', <?php echo (int) $task['id']; ?>)">
                                                        <i class="bi bi-truck me-1"></i>تم التوصيل
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="submitTaskAction('return_task', <?php echo (int) $task['id']; ?>)">
                                                        <i class="bi bi-arrow-return-left me-1"></i>تم الارجاع
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($isManager): ?>
                                                <button type="button" class="btn btn-outline-secondary" onclick="viewTask(<?php echo (int) $task['id']; ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteTask(<?php echo (int) $task['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($isManager || $isProduction || $isDriver): ?>
                                                <a href="<?php echo getRelativeUrl('print_task_receipt.php?id=' . (int) $task['id']); ?>" target="_blank" class="btn btn-outline-primary" title="طباعة إيصال المهمة">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="my-3" aria-label="Task pagination">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php
                                $paramsForPage = $_GET;
                                $paramsForPage['p'] = $i;
                                $url = '?' . http_build_query($paramsForPage);
                                ?>
                                <li class="page-item <?php echo $pageNum === $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo tasksHtml($url); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

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
                            <label class="form-label">المخصص إلى</label>
                            <select class="form-select" name="assigned_to">
                                <option value="0">غير محدد</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo (int) $user['id']; ?>"><?php echo tasksHtml($user['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="normal" selected>عادية</option>
                                <option value="low">منخفضة</option>
                                <option value="urgent">عاجلة</option>
                            </select>
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
                    <option value="0">غير محدد</option>
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
                    <input type="number" step="0.01" min="0.01" class="form-control" name="quantity" id="quantity_card" placeholder="0.00">
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
        'delivered': 'success',
        'returned': 'secondary',
        'cancelled': 'secondary'
    };

    function buildActionsHtml(taskId, newStatus) {
        var flags = window.TASK_PAGE_FLAGS || {};
        var html = '<div class="btn-group btn-group-sm" role="group">';
        if (flags.isProduction) {
            if (['pending', 'received', 'in_progress'].indexOf(newStatus) !== -1) {
                html += '<button type="button" class="btn btn-outline-success" onclick="submitTaskAction(\'complete_task\', ' + taskId + ')"><i class="bi bi-check2-circle me-1"></i>إكمال</button>';
            }
            if (newStatus === 'completed') {
                html += '<button type="button" class="btn btn-outline-info btn-sm" onclick="submitTaskAction(\'with_delegate_task\', ' + taskId + ')"><i class="bi bi-person-badge me-1"></i>مع المندوب</button>';
            }
        }
        if ((flags.isManager || flags.isProduction || flags.isDriver) && ['completed', 'with_delegate'].indexOf(newStatus) !== -1) {
            html += '<button type="button" class="btn btn-outline-success btn-sm" onclick="submitTaskAction(\'deliver_task\', ' + taskId + ')"><i class="bi bi-truck me-1"></i>تم التوصيل</button>';
            html += '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="submitTaskAction(\'return_task\', ' + taskId + ')"><i class="bi bi-arrow-return-left me-1"></i>تم الارجاع</button>';
        }
        if (flags.isManager) {
            html += '<button type="button" class="btn btn-outline-secondary" onclick="viewTask(' + taskId + ')"><i class="bi bi-eye"></i></button>';
            html += '<button type="button" class="btn btn-outline-danger" onclick="confirmDeleteTask(' + taskId + ')"><i class="bi bi-trash"></i></button>';
        }
        if (flags.isManager || flags.isProduction || flags.isDriver) {
            html += '<a href="' + printTaskReceiptBase + '?id=' + taskId + '" target="_blank" class="btn btn-outline-primary" title="طباعة إيصال المهمة"><i class="bi bi-printer"></i></a>';
        }
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
            actionsCell.innerHTML = buildActionsHtml(taskId, newStatus);
        }
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
            'received': 'مستلمة',
            'completed': 'مكتملة',
            'with_delegate': 'مع المندوب',
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

<!-- آلية التحديث التلقائي للمهام (Auto-refresh/Polling) -->
<script>
(function() {
    'use strict';
    
    // التحقق من أننا في صفحة المهام
    if (!window.location.search.includes('page=tasks')) {
        return;
    }
    
    let autoRefreshInterval = null;
    let lastUpdateTimestamp = null;
    let isRefreshing = false;
    
    // دالة جلب المهام من API
    async function fetchTasks() {
        if (isRefreshing) {
            return; // منع طلبات متعددة في نفس الوقت
        }
        
        try {
            isRefreshing = true;
            
            // بناء URL مع جميع المعاملات الحالية
            const currentUrl = new URL(window.location.href);
            const apiUrl = '/api/tasks.php?' + currentUrl.searchParams.toString();
            
            const response = await fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch tasks');
            }
            
            const data = await response.json();
            
            if (data.success && data.data) {
                const newTasks = data.data.tasks || [];
                const newStats = data.data.stats || {};
                const newTimestamp = data.data.timestamp || Date.now();
                
                // مقارنة مع المهام الحالية
                if (lastUpdateTimestamp && newTimestamp > lastUpdateTimestamp) {
                    // هناك تحديثات جديدة
                    const currentTasksIds = new Set(
                        Array.from(document.querySelectorAll('[data-task-id]')).map(el => el.getAttribute('data-task-id'))
                    );
                    
                    const newTasksIds = new Set(newTasks.map(t => String(t.id)));
                    
                    // التحقق من وجود مهام جديدة أو تغييرات
                    let hasNewTasks = false;
                    let hasChanges = false;
                    
                    // التحقق من المهام الجديدة
                    for (const task of newTasks) {
                        const taskId = String(task.id);
                        if (!currentTasksIds.has(taskId)) {
                            hasNewTasks = true;
                            break;
                        }
                    }
                    
                    // التحقق من التغييرات في الإحصائيات
                    const totalTasksElement = document.querySelector('.card.border-primary h5');
                    const pendingTasksElement = document.querySelector('.card.border-warning h5');
                    
                    if (totalTasksElement && totalTasksElement.textContent !== String(newStats.total || 0)) {
                        hasChanges = true;
                    } else if (pendingTasksElement && pendingTasksElement.textContent !== String(newStats.pending || 0)) {
                        hasChanges = true;
                    }
                    
                    // إذا كانت هناك مهام جديدة أو تغييرات، تحديث الصفحة بدون إعادة تحميل كاملة
                    // تم تعطيل إعادة التوجيه التلقائية لمنع إعادة التوجيه غير المرغوب
                    // يمكن للمستخدم تحديث الصفحة يدوياً عند الحاجة
                    if (hasNewTasks || hasChanges) {
                        // إظهار إشعار للمستخدم بدلاً من إعادة التوجيه التلقائية
                        console.log('New tasks or changes detected. Please refresh the page manually if needed.');
                        // يمكن إضافة إشعار بصري هنا بدلاً من إعادة التوجيه
                    }
                }
                
                lastUpdateTimestamp = newTimestamp;
            }
        } catch (error) {
            console.error('Error fetching tasks:', error);
        } finally {
            isRefreshing = false;
        }
    }
    
    // بدء التحديث التلقائي - تم زيادة الفترة لتقليل الاستهلاك بشكل كبير
    function startAutoRefresh() {
        // تنظيف interval السابق إن وجد
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
        
        // كشف نوع الاتصال لتحديد فترة التحديث المناسبة
        function detectConnectionType() {
            if ('connection' in navigator) {
                const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                if (conn) {
                    const effectiveType = conn.effectiveType || 'unknown';
                    const type = conn.type || 'unknown';
                    const saveData = conn.saveData || false;
                    
                    if (saveData || type === 'cellular' || effectiveType === '2g' || effectiveType === 'slow-2g') {
                        return true; // بيانات هاتف
                    }
                }
            }
            const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            return isMobileDevice;
        }
        
        const isMobileData = detectConnectionType();
        
        // جلب المهام لأول مرة بعد تأخير حسب نوع الاتصال
        const initialDelay = isMobileData ? 15000 : 10000; // 15 ثانية للهاتف، 10 ثواني للWiFi
        setTimeout(function() {
            fetchTasks();
        }, initialDelay);
        
        // تحديد فترة التحديث حسب نوع الاتصال
        // WiFi: 2 دقيقة | بيانات الهاتف: 4 دقائق لتقليل استهلاك البيانات
        const refreshInterval = isMobileData ? 240000 : 120000; // 4 دقائق للهاتف، 2 دقيقة للWiFi
        
        autoRefreshInterval = setInterval(function() {
            // التحقق من أن الصفحة مرئية ونشطة قبل الطلب
            if (!document.hidden) {
                fetchTasks();
            }
        }, refreshInterval);
    }
    
    // إيقاف التحديث التلقائي عند مغادرة الصفحة
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
    
    // بدء التحديث التلقائي عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startAutoRefresh);
    } else {
        startAutoRefresh();
    }
    
    // إيقاف التحديث عند مغادرة الصفحة - استخدام pagehide لإعادة تفعيل bfcache
    window.addEventListener('pagehide', function(event) {
        stopAutoRefresh();
    });
    
    // إيقاف التحديث عندما تكون الصفحة غير مرئية (tab inactive)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
        }
    });
    
    // دالة للتعامل مع تحديثات المهام من unified polling system (إذا تم إضافتها لاحقاً)
    window.handleTasksUpdate = function() {
        // يمكن استدعاء fetchTasks إذا لزم الأمر
        if (typeof fetchTasks === 'function' && !document.hidden) {
            fetchTasks();
        }
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

// تعديل دالة viewTask لدعم الموبايل
const originalViewTask = window.viewTask;
if (typeof originalViewTask === 'function') {
    window.viewTask = function(taskId) {
        closeAllForms();
        
        // تحميل بيانات المهمة
        fetch(`?ajax=1&task_id=${taskId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.task) {
                    const task = data.task;
                    let content = `
                        <div class="mb-3">
                            <strong>العنوان:</strong> ${task.title || '-'}
                        </div>
                        <div class="mb-3">
                            <strong>الوصف:</strong> ${task.description || '-'}
                        </div>
                        <div class="mb-3">
                            <strong>الحالة:</strong> ${task.status || '-'}
                        </div>
                        <div class="mb-3">
                            <strong>الأولوية:</strong> ${task.priority || '-'}
                        </div>
                    `;
                    
                    if (isMobile()) {
                        const card = document.getElementById('viewTaskCard');
                        const contentEl = document.getElementById('viewTaskContentCard');
                        if (card && contentEl) {
                            contentEl.innerHTML = content;
                            card.style.display = 'block';
                            setTimeout(function() {
                                scrollToElement(card);
                            }, 50);
                        }
                    } else {
                        const modal = document.getElementById('viewTaskModal');
                        const contentEl = document.getElementById('viewTaskContent');
                        if (modal && contentEl) {
                            contentEl.innerHTML = content;
                            const modalInstance = new bootstrap.Modal(modal);
                            modalInstance.show();
                        }
                    }
                } else {
                    alert('حدث خطأ في تحميل بيانات المهمة');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ في تحميل بيانات المهمة');
            });
    };
}
</script>

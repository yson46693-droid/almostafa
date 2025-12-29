<?php
/**
 * API لتغيير المندوب المسؤول عن العميل
 * يسمح للمدير فقط بنقل العميل من مندوب إلى آخر
 * ملاحظة: لا يتم نقل التحصيلات (تبقى مع المندوب الأصلي)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// تنظيف أي output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

// التحقق من الصلاحيات - المدير فقط
$currentUser = getCurrentUser();
if (!$currentUser) {
    echo json_encode([
        'success' => false,
        'message' => 'يجب تسجيل الدخول أولاً'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userRole = strtolower($currentUser['role'] ?? '');
if (!in_array($userRole, ['manager', 'developer'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'غير مصرح لك بتنفيذ هذه العملية'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من طريقة الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير صحيحة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// قراءة البيانات
$customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
$newSalesRepId = isset($_POST['new_sales_rep_id']) ? (int)$_POST['new_sales_rep_id'] : 0;
$transferInvoices = isset($_POST['transfer_invoices']) && $_POST['transfer_invoices'] === '1';

// التحقق من صحة البيانات
if ($customerId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'معرف العميل غير صحيح'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($newSalesRepId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'يجب تحديد مندوب جديد'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = db();
    
    // بدء المعاملة
    $db->beginTransaction();
    
    // التحقق من وجود العميل
    $customer = $db->queryOne(
        "SELECT id, name, created_by, rep_id FROM customers WHERE id = ? FOR UPDATE",
        [$customerId]
    );
    
    if (!$customer) {
        throw new Exception('العميل غير موجود');
    }
    
    $oldSalesRepId = (int)($customer['rep_id'] ?? $customer['created_by'] ?? 0);
    
    // التحقق من أن المندوب الجديد موجود ومندوب نشط
    $newSalesRep = $db->queryOne(
        "SELECT id, full_name, username FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
        [$newSalesRepId]
    );
    
    if (!$newSalesRep) {
        throw new Exception('المندوب الجديد غير موجود أو غير نشط');
    }
    
    // التحقق من أن المندوب الجديد مختلف عن القديم
    if ($oldSalesRepId === $newSalesRepId) {
        throw new Exception('المندوب الجديد هو نفسه المندوب الحالي');
    }
    
    // تحديث جدول customers
    // تحديث created_by و rep_id
    $db->execute(
        "UPDATE customers SET created_by = ?, rep_id = ? WHERE id = ?",
        [$newSalesRepId, $newSalesRepId, $customerId]
    );
    
    // تحديث الفواتير إذا كان الخيار مفعلاً
    if ($transferInvoices) {
        $invoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'invoices'");
        if (!empty($invoicesTableExists)) {
            // التحقق من وجود عمود original_sales_rep_id وإنشاؤه إذا لم يكن موجوداً
            $hasOriginalSalesRepIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'original_sales_rep_id'"));
            if (!$hasOriginalSalesRepIdColumn) {
                try {
                    $db->execute("ALTER TABLE invoices ADD COLUMN original_sales_rep_id INT(11) DEFAULT NULL AFTER sales_rep_id");
                    $db->execute("ALTER TABLE invoices ADD KEY idx_original_sales_rep_id (original_sales_rep_id)");
                    error_log('Added original_sales_rep_id column to invoices table');
                } catch (Throwable $e) {
                    error_log('Error adding original_sales_rep_id column: ' . $e->getMessage());
                }
            }
            
            // تحديث الفواتير مع حفظ المندوب الأصلي
            // إذا كان original_sales_rep_id NULL، نضعه كـ oldSalesRepId (أول نقل)
            // إذا كان موجوداً، نتركه كما هو (لنقل متعدد)
            $db->execute(
                "UPDATE invoices 
                 SET sales_rep_id = ?,
                     original_sales_rep_id = COALESCE(original_sales_rep_id, ?)
                 WHERE customer_id = ? AND sales_rep_id = ?",
                [$newSalesRepId, $oldSalesRepId, $customerId, $oldSalesRepId]
            );
        }
    }
    
    // ملاحظة: التحصيلات لا يتم نقلها - تبقى مع المندوب الأصلي
    // هذا مهم للحفاظ على سجلات التحصيل التاريخية
    
    // تحديث جدول sales
    $salesTableExists = $db->queryOne("SHOW TABLES LIKE 'sales'");
    if (!empty($salesTableExists)) {
        $db->execute(
            "UPDATE sales SET salesperson_id = ? WHERE customer_id = ? AND salesperson_id = ?",
            [$newSalesRepId, $customerId, $oldSalesRepId]
        );
    }
    
    // تحديث accountant_transactions (فقط المعاملات المرتبطة بالفواتير)
    $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
    if (!empty($accountantTableExists) && $transferInvoices) {
        // تحديث فقط المعاملات المرتبطة بالفواتير المنقولة
        $db->execute(
            "UPDATE accountant_transactions at
             INNER JOIN invoices i ON i.id = CAST(at.reference_number AS UNSIGNED)
             SET at.sales_rep_id = ?
             WHERE i.customer_id = ? AND i.sales_rep_id = ? AND at.sales_rep_id = ?",
            [$newSalesRepId, $customerId, $newSalesRepId, $oldSalesRepId]
        );
    }
    
    // تحديث returns
    $returnsTableExists = $db->queryOne("SHOW TABLES LIKE 'returns'");
    if (!empty($returnsTableExists)) {
        $db->execute(
            "UPDATE returns SET sales_rep_id = ? WHERE customer_id = ? AND sales_rep_id = ?",
            [$newSalesRepId, $customerId, $oldSalesRepId]
        );
    }
    
    // تحديث exchanges
    $exchangesTableExists = $db->queryOne("SHOW TABLES LIKE 'exchanges'");
    if (!empty($exchangesTableExists)) {
        $db->execute(
            "UPDATE exchanges SET sales_rep_id = ? WHERE customer_id = ? AND sales_rep_id = ?",
            [$newSalesRepId, $customerId, $oldSalesRepId]
        );
    }
    
    // تحديث damaged_returns
    $damagedReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'damaged_returns'");
    if (!empty($damagedReturnsTableExists)) {
        // تحديث من خلال return_items
        $db->execute(
            "UPDATE damaged_returns dr
             INNER JOIN return_items ri ON dr.return_item_id = ri.id
             INNER JOIN returns r ON ri.return_id = r.id
             SET dr.sales_rep_id = ?
             WHERE r.customer_id = ? AND dr.sales_rep_id = ?",
            [$newSalesRepId, $customerId, $oldSalesRepId]
        );
    }
    
    // جلب اسم المندوب القديم
    $oldSalesRep = null;
    if ($oldSalesRepId > 0) {
        $oldSalesRep = $db->queryOne(
            "SELECT full_name, username FROM users WHERE id = ?",
            [$oldSalesRepId]
        );
    }
    
    $oldSalesRepName = $oldSalesRep ? ($oldSalesRep['full_name'] ?? $oldSalesRep['username'] ?? 'غير معروف') : 'غير معروف';
    $newSalesRepName = $newSalesRep['full_name'] ?? $newSalesRep['username'] ?? 'غير معروف';
    
    // تسجيل في سجل التدقيق
    logAudit(
        $currentUser['id'],
        'change_customer_sales_rep',
        'customer',
        $customerId,
        [
            'old_sales_rep_id' => $oldSalesRepId,
            'old_sales_rep_name' => $oldSalesRepName
        ],
        [
            'new_sales_rep_id' => $newSalesRepId,
            'new_sales_rep_name' => $newSalesRepName,
            'customer_name' => $customer['name'],
            'transfer_invoices' => $transferInvoices,
            'note' => 'التحصيلات لم يتم نقلها - بقيت مع المندوب الأصلي'
        ]
    );
    
    // تأكيد المعاملة
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'تم نقل العميل بنجاح من ' . $oldSalesRepName . ' إلى ' . $newSalesRepName . '. ملاحظة: التحصيلات بقيت مع المندوب الأصلي.',
        'data' => [
            'customer_id' => $customerId,
            'old_sales_rep_id' => $oldSalesRepId,
            'new_sales_rep_id' => $newSalesRepId,
            'old_sales_rep_name' => $oldSalesRepName,
            'new_sales_rep_name' => $newSalesRepName
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    // إلغاء المعاملة في حالة الخطأ
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log('Error changing customer sales rep: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء نقل العميل: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


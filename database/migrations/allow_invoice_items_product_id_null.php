<?php
/**
 * Migration: السماح لـ product_id في invoice_items أن يكون NULL
 * لعناصر الفاتورة من نوع خامات بالوزن (بيع من مخزن الخامات في نقطة البيع المحلية)
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$db = db();
$result = ['success' => false, 'message' => '', 'steps' => []];

try {
    $col = $db->queryOne("SHOW COLUMNS FROM invoice_items WHERE Field = 'product_id'");
    if (!empty($col)) {
        $nullable = (strtoupper((string)($col['Null'] ?? '')) === 'YES');
        if (!$nullable) {
            $db->execute("ALTER TABLE invoice_items MODIFY COLUMN product_id int(11) DEFAULT NULL COMMENT 'معرف المنتج - NULL لعناصر الخامات بالوزن'");
            $result['steps'][] = 'تم تعديل عمود product_id في invoice_items ليقبل NULL';
        } else {
            $result['steps'][] = 'عمود product_id في invoice_items يقبل NULL مسبقاً';
        }
    }
    $localExists = $db->queryOne("SHOW TABLES LIKE 'local_invoice_items'");
    if (!empty($localExists)) {
        $localCol = $db->queryOne("SHOW COLUMNS FROM local_invoice_items WHERE Field = 'product_id'");
        if (!empty($localCol)) {
            $localNullable = (strtoupper((string)($localCol['Null'] ?? '')) === 'YES');
            if (!$localNullable) {
                $db->execute("ALTER TABLE local_invoice_items MODIFY COLUMN product_id int(11) DEFAULT NULL COMMENT 'معرف المنتج - NULL لعناصر الخامات بالوزن'");
                $result['steps'][] = 'تم تعديل عمود product_id في local_invoice_items ليقبل NULL';
            }
        }
    }
    $result['success'] = true;
    $result['message'] = 'تم تنفيذ Migration بنجاح';
} catch (Throwable $e) {
    $result['message'] = 'خطأ: ' . $e->getMessage();
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);

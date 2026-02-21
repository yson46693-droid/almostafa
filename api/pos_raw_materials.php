<?php
/**
 * API لجلب أقسام مخزن الخامات والمواد حسب القسم - لاستخدامها في نقطة البيع المحلية (بيع أوزان)
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['manager', 'accountant', 'developer']);

header('Content-Type: application/json; charset=utf-8');

$db = db();
$section = isset($_GET['section']) ? trim($_GET['section']) : '';

$sections = [
    ['id' => 'honey', 'name' => 'العسل'],
    ['id' => 'olive_oil', 'name' => 'زيت الزيتون'],
    ['id' => 'beeswax', 'name' => 'شمع العسل'],
    ['id' => 'nuts', 'name' => 'المكسرات'],
    ['id' => 'sesame', 'name' => 'السمسم'],
    ['id' => 'date', 'name' => 'البلح'],
    ['id' => 'turbines', 'name' => 'التلبينات'],
    ['id' => 'herbal', 'name' => 'العطاره'],
];

// إذا لم يُحدد قسم، إرجاع القائمة فقط
if ($section === '') {
    echo json_encode(['success' => true, 'sections' => $sections], JSON_UNESCAPED_UNICODE);
    exit;
}

$materials = [];
$validSections = array_column($sections, 'id');
if (!in_array($section, $validSections, true)) {
    echo json_encode(['success' => false, 'message' => 'قسم غير صالح', 'materials' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($section === 'honey') {
        $t = $db->query("SELECT id, supplier_id, honey_variety, raw_honey_quantity, filtered_honey_quantity FROM honey_stock");
        foreach ($t as $row) {
            $variety = $row['honey_variety'] ?? 'غير محدد';
            $raw = (float)($row['raw_honey_quantity'] ?? 0);
            $filtered = (float)($row['filtered_honey_quantity'] ?? 0);
            if ($raw > 0) {
                $materials[] = [
                    'stock_id' => (int)$row['id'],
                    'sub_type' => 'raw',
                    'label' => 'عسل خام - ' . $variety,
                    'available_kg' => round($raw, 3),
                ];
            }
            if ($filtered > 0) {
                $materials[] = [
                    'stock_id' => (int)$row['id'],
                    'sub_type' => 'filtered',
                    'label' => 'عسل مصفى - ' . $variety,
                    'available_kg' => round($filtered, 3),
                ];
            }
        }
    } elseif ($section === 'olive_oil') {
        $t = $db->query("SELECT id, quantity FROM olive_oil_stock WHERE quantity > 0");
        foreach ($t as $row) {
            $materials[] = [
                'stock_id' => (int)$row['id'],
                'sub_type' => null,
                'label' => 'زيت زيتون #' . $row['id'],
                'available_kg' => round((float)$row['quantity'], 3),
            ];
        }
    } elseif ($section === 'beeswax') {
        $t = $db->query("SELECT id, weight FROM beeswax_stock WHERE weight > 0");
        foreach ($t as $row) {
            $materials[] = [
                'stock_id' => (int)$row['id'],
                'sub_type' => null,
                'label' => 'شمع عسل #' . $row['id'],
                'available_kg' => round((float)$row['weight'], 3),
            ];
        }
    } elseif ($section === 'nuts') {
        $t = $db->query("SELECT ns.id, ns.nut_type, ns.quantity, s.name as supplier_name FROM nuts_stock ns LEFT JOIN suppliers s ON ns.supplier_id = s.id WHERE ns.quantity > 0");
        foreach ($t as $row) {
            $name = $row['supplier_name'] ? $row['nut_type'] . ' - ' . $row['supplier_name'] : $row['nut_type'];
            $materials[] = [
                'stock_id' => (int)$row['id'],
                'sub_type' => null,
                'stock_source' => 'single',
                'label' => $name . ' (' . number_format((float)$row['quantity'], 2) . ' كجم)',
                'available_kg' => round((float)$row['quantity'], 3),
            ];
        }
        $t = $db->query("SELECT mn.id, mn.batch_name, mn.total_quantity, s.name as supplier_name FROM mixed_nuts mn LEFT JOIN suppliers s ON mn.supplier_id = s.id WHERE mn.total_quantity > 0");
        foreach ($t as $row) {
            $materials[] = [
                'stock_id' => (int)$row['id'],
                'sub_type' => null,
                'stock_source' => 'mixed',
                'label' => 'خلطة: ' . ($row['batch_name'] ?? '') . ' (' . number_format((float)$row['total_quantity'], 2) . ' كجم)',
                'available_kg' => round((float)$row['total_quantity'], 3),
            ];
        }
    } elseif ($section === 'sesame') {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'sesame_stock'");
        if (!empty($tableCheck)) {
            $t = $db->query("SELECT id, quantity FROM sesame_stock WHERE quantity > 0");
            foreach ($t as $row) {
                $materials[] = [
                    'stock_id' => (int)$row['id'],
                    'sub_type' => null,
                    'label' => 'سمسم #' . $row['id'] . ' (' . number_format((float)$row['quantity'], 2) . ' كجم)',
                    'available_kg' => round((float)$row['quantity'], 3),
                ];
            }
        }
    } elseif ($section === 'date') {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'date_stock'");
        if (!empty($tableCheck)) {
            $t = $db->query("SELECT id, date_type, quantity FROM date_stock WHERE quantity > 0");
            foreach ($t as $row) {
                $type = $row['date_type'] ?? 'غير محدد';
                $materials[] = [
                    'stock_id' => (int)$row['id'],
                    'sub_type' => null,
                    'label' => $type . ' (' . number_format((float)$row['quantity'], 2) . ' كجم)',
                    'available_kg' => round((float)$row['quantity'], 3),
                ];
            }
        }
    } elseif ($section === 'turbines') {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'turbine_stock'");
        if (!empty($tableCheck)) {
            $t = $db->query("SELECT id, turbine_type, quantity FROM turbine_stock WHERE quantity > 0");
            foreach ($t as $row) {
                $type = $row['turbine_type'] ?? 'غير محدد';
                $materials[] = [
                    'stock_id' => (int)$row['id'],
                    'sub_type' => null,
                    'label' => $type . ' (' . number_format((float)$row['quantity'], 2) . ' كجم)',
                    'available_kg' => round((float)$row['quantity'], 3),
                ];
            }
        }
    } elseif ($section === 'herbal') {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'herbal_stock'");
        if (!empty($tableCheck)) {
            $t = $db->query("SELECT id, herbal_type, quantity FROM herbal_stock WHERE quantity > 0");
            foreach ($t as $row) {
                $type = $row['herbal_type'] ?? 'غير محدد';
                $materials[] = [
                    'stock_id' => (int)$row['id'],
                    'sub_type' => null,
                    'label' => $type . ' (' . number_format((float)$row['quantity'], 2) . ' كجم)',
                    'available_kg' => round((float)$row['quantity'], 3),
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'section' => $section, 'materials' => $materials], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('pos_raw_materials API: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في جلب البيانات', 'materials' => []], JSON_UNESCAPED_UNICODE);
}

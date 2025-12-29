<?php
/**
 * API لإعادة توليد تقرير مخزن الخامات
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/production_helper.php';
require_once __DIR__ . '/../includes/honey_varieties.php';

requireRole(['production', 'manager']);

header('Content-Type: application/json; charset=utf-8');

try {
    $currentUser = getCurrentUser();
    $db = db();
    
    // Helper functions for report generation
    $rawReportQueryOne = static function ($dbConnection, $sql, $params = []) {
        try {
            return $dbConnection->queryOne($sql, $params);
        } catch (Exception $e) {
            error_log('Raw materials report queryOne error: ' . $e->getMessage());
            return null;
        }
    };
    
    $rawReportQuery = static function ($dbConnection, $sql, $params = []) {
        try {
            return $dbConnection->query($sql, $params);
        } catch (Exception $e) {
            error_log('Raw materials report query error: ' . $e->getMessage());
            return [];
        }
    };
    
    // Load report building functions from raw_materials_warehouse.php
    // We need to define ACCESS_ALLOWED first, then include the file to get the functions
    // The functions are defined with if (!function_exists()) so they'll only be defined once
    $originalAccessAllowed = defined('ACCESS_ALLOWED') ? ACCESS_ALLOWED : false;
    if (!defined('ACCESS_ALLOWED')) {
        define('ACCESS_ALLOWED', true);
    }
    
    // Load report building functions from raw_materials_warehouse.php
    // Set API mode flag to prevent page execution
    $GLOBALS['RAW_MATERIALS_API_MODE'] = true;
    // Use output buffering to prevent any output from the included file
    ob_start();
    require_once __DIR__ . '/../modules/production/raw_materials_warehouse.php';
    ob_end_clean();
    
    // Now build the report data (same logic as in raw_materials_warehouse.php)
    $rawWarehouseReport = [
        'generated_at' => date('Y-m-d H:i'),
        'generated_by' => $currentUser['full_name'] ?? ($currentUser['username'] ?? 'مستخدم'),
        'total_suppliers' => 0,
        'sections' => [],
        'sections_order' => [],
        'total_records' => 0,
        'zero_items' => 0
    ];
    
    $rawWarehouseReport['total_suppliers'] = (int)($rawReportQueryOne($db, "
        SELECT COUNT(*) AS total 
        FROM suppliers 
        WHERE status = 'active' 
          AND type IN ('honey', 'olive_oil', 'beeswax', 'derivatives', 'nuts', 'sesame')
    ")['total'] ?? 0);
    
    // Honey summary
    $honeySummary = $rawReportQueryOne($db, "
        SELECT 
            COALESCE(SUM(raw_honey_quantity), 0) AS total_raw,
            COALESCE(SUM(filtered_honey_quantity), 0) AS total_filtered,
            COUNT(*) AS records,
            COUNT(DISTINCT supplier_id) AS suppliers,
            SUM(CASE WHEN COALESCE(raw_honey_quantity,0) + COALESCE(filtered_honey_quantity,0) <= 0 THEN 1 ELSE 0 END) AS zero_items
        FROM honey_stock
    ");
    if ($honeySummary) {
        $topHoneyVarieties = $rawReportQuery($db, "
            SELECT honey_variety, COALESCE(SUM(filtered_honey_quantity), 0) AS total_filtered
            FROM honey_stock
            GROUP BY honey_variety
            ORDER BY total_filtered DESC
            LIMIT 5
        ");
        
        $sectionKey = 'honey';
        $rawWarehouseReport['sections_order'][] = $sectionKey;
        $rawWarehouseReport['sections'][$sectionKey] = [
            'title' => 'العسل',
            'records' => (int)($honeySummary['records'] ?? 0),
            'metrics' => [
                [
                    'label' => 'إجمالي العسل الخام',
                    'value' => (float)($honeySummary['total_raw'] ?? 0),
                    'unit' => 'كجم',
                    'decimals' => 2
                ],
                [
                    'label' => 'إجمالي العسل المصفى',
                    'value' => (float)($honeySummary['total_filtered'] ?? 0),
                    'unit' => 'كجم',
                    'decimals' => 2
                ],
                [
                    'label' => 'عدد السجلات',
                    'value' => (int)($honeySummary['records'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ],
                [
                    'label' => 'عدد الموردين',
                    'value' => (int)($honeySummary['suppliers'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ]
            ],
            'top_items' => array_map(static function ($row) {
                $label = trim((string)($row['honey_variety'] ?? ''));
                if ($label === '') {
                    $label = 'نوع غير محدد';
                }
                return [
                    'label' => $label,
                    'value' => (float)($row['total_filtered'] ?? 0),
                    'unit' => 'كجم',
                    'decimals' => 2
                ];
            }, $topHoneyVarieties ?? [])
        ];
        $rawWarehouseReport['total_records'] += (int)($honeySummary['records'] ?? 0);
        $rawWarehouseReport['zero_items'] += (int)($honeySummary['zero_items'] ?? 0);
    }
    
    // Olive oil summary
    $oliveSummary = $rawReportQueryOne($db, "
        SELECT 
            COALESCE(SUM(quantity), 0) AS total_quantity,
            COUNT(*) AS records,
            COUNT(DISTINCT supplier_id) AS suppliers,
            SUM(CASE WHEN COALESCE(quantity,0) <= 0 THEN 1 ELSE 0 END) AS zero_items
        FROM olive_oil_stock
    ");
    if ($oliveSummary) {
        $sectionKey = 'olive_oil';
        $rawWarehouseReport['sections_order'][] = $sectionKey;
        $rawWarehouseReport['sections'][$sectionKey] = [
            'title' => 'زيت الزيتون',
            'records' => (int)($oliveSummary['records'] ?? 0),
            'metrics' => [
                [
                    'label' => 'إجمالي الكمية',
                    'value' => (float)($oliveSummary['total_quantity'] ?? 0),
                    'unit' => 'لتر',
                    'decimals' => 2
                ],
                [
                    'label' => 'عدد السجلات',
                    'value' => (int)($oliveSummary['records'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ],
                [
                    'label' => 'عدد الموردين',
                    'value' => (int)($oliveSummary['suppliers'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ]
            ],
            'top_items' => []
        ];
        $rawWarehouseReport['total_records'] += (int)($oliveSummary['records'] ?? 0);
        $rawWarehouseReport['zero_items'] += (int)($oliveSummary['zero_items'] ?? 0);
    }
    
    // Beeswax summary
    $beeswaxSummary = $rawReportQueryOne($db, "
        SELECT 
            COALESCE(SUM(quantity), 0) AS total_quantity,
            COUNT(*) AS records,
            COUNT(DISTINCT supplier_id) AS suppliers,
            SUM(CASE WHEN COALESCE(quantity,0) <= 0 THEN 1 ELSE 0 END) AS zero_items
        FROM beeswax_stock
    ");
    if ($beeswaxSummary) {
        $sectionKey = 'beeswax';
        $rawWarehouseReport['sections_order'][] = $sectionKey;
        $rawWarehouseReport['sections'][$sectionKey] = [
            'title' => 'شمع العسل',
            'records' => (int)($beeswaxSummary['records'] ?? 0),
            'metrics' => [
                [
                    'label' => 'إجمالي الكمية',
                    'value' => (float)($beeswaxSummary['total_quantity'] ?? 0),
                    'unit' => 'كجم',
                    'decimals' => 2
                ],
                [
                    'label' => 'عدد السجلات',
                    'value' => (int)($beeswaxSummary['records'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ],
                [
                    'label' => 'عدد الموردين',
                    'value' => (int)($beeswaxSummary['suppliers'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ]
            ],
            'top_items' => []
        ];
        $rawWarehouseReport['total_records'] += (int)($beeswaxSummary['records'] ?? 0);
        $rawWarehouseReport['zero_items'] += (int)($beeswaxSummary['zero_items'] ?? 0);
    }
    
    // Nuts summary
    $nutsSummary = $rawReportQueryOne($db, "
        SELECT 
            COALESCE(SUM(quantity), 0) AS total_quantity,
            COUNT(*) AS records,
            COUNT(DISTINCT supplier_id) AS suppliers,
            SUM(CASE WHEN COALESCE(quantity,0) <= 0 THEN 1 ELSE 0 END) AS zero_items
        FROM nuts_stock
    ");
    if ($nutsSummary) {
        $sectionKey = 'nuts';
        $rawWarehouseReport['sections_order'][] = $sectionKey;
        $rawWarehouseReport['sections'][$sectionKey] = [
            'title' => 'المكسرات',
            'records' => (int)($nutsSummary['records'] ?? 0),
            'metrics' => [
                [
                    'label' => 'إجمالي الكمية',
                    'value' => (float)($nutsSummary['total_quantity'] ?? 0),
                    'unit' => 'كجم',
                    'decimals' => 2
                ],
                [
                    'label' => 'عدد السجلات',
                    'value' => (int)($nutsSummary['records'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ],
                [
                    'label' => 'عدد الموردين',
                    'value' => (int)($nutsSummary['suppliers'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ]
            ],
            'top_items' => []
        ];
        $rawWarehouseReport['total_records'] += (int)($nutsSummary['records'] ?? 0);
        $rawWarehouseReport['zero_items'] += (int)($nutsSummary['zero_items'] ?? 0);
    }
    
    // Derivatives summary
    $derivativesSummary = $rawReportQueryOne($db, "
        SELECT 
            COALESCE(SUM(quantity), 0) AS total_quantity,
            COUNT(*) AS records,
            COUNT(DISTINCT supplier_id) AS suppliers,
            SUM(CASE WHEN COALESCE(quantity,0) <= 0 THEN 1 ELSE 0 END) AS zero_items
        FROM derivatives_stock
    ");
    if ($derivativesSummary) {
        $sectionKey = 'derivatives';
        $rawWarehouseReport['sections_order'][] = $sectionKey;
        $rawWarehouseReport['sections'][$sectionKey] = [
            'title' => 'المشتقات',
            'records' => (int)($derivativesSummary['records'] ?? 0),
            'metrics' => [
                [
                    'label' => 'إجمالي الكمية',
                    'value' => (float)($derivativesSummary['total_quantity'] ?? 0),
                    'unit' => 'كجم',
                    'decimals' => 2
                ],
                [
                    'label' => 'عدد السجلات',
                    'value' => (int)($derivativesSummary['records'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ],
                [
                    'label' => 'عدد الموردين',
                    'value' => (int)($derivativesSummary['suppliers'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ]
            ],
            'top_items' => []
        ];
        $rawWarehouseReport['total_records'] += (int)($derivativesSummary['records'] ?? 0);
        $rawWarehouseReport['zero_items'] += (int)($derivativesSummary['zero_items'] ?? 0);
    }
    
    // Sesame summary
    $sesameSummary = $rawReportQueryOne($db, "
        SELECT 
            COALESCE(SUM(quantity), 0) AS total_quantity,
            COUNT(*) AS records,
            COUNT(DISTINCT supplier_id) AS suppliers,
            SUM(CASE WHEN COALESCE(quantity,0) <= 0 THEN 1 ELSE 0 END) AS zero_items
        FROM sesame_stock
    ");
    if ($sesameSummary) {
        $sectionKey = 'sesame';
        $rawWarehouseReport['sections_order'][] = $sectionKey;
        $rawWarehouseReport['sections'][$sectionKey] = [
            'title' => 'السمسم',
            'records' => (int)($sesameSummary['records'] ?? 0),
            'metrics' => [
                [
                    'label' => 'إجمالي الكمية',
                    'value' => (float)($sesameSummary['total_quantity'] ?? 0),
                    'unit' => 'كجم',
                    'decimals' => 2
                ],
                [
                    'label' => 'عدد السجلات',
                    'value' => (int)($sesameSummary['records'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ],
                [
                    'label' => 'عدد الموردين',
                    'value' => (int)($sesameSummary['suppliers'] ?? 0),
                    'unit' => null,
                    'decimals' => 0
                ]
            ],
            'top_items' => []
        ];
        $rawWarehouseReport['total_records'] += (int)($sesameSummary['records'] ?? 0);
        $rawWarehouseReport['zero_items'] += (int)($sesameSummary['zero_items'] ?? 0);
    }
    
    $rawWarehouseReport['sections_count'] = count($rawWarehouseReport['sections']);
    
    // Store the report
    $reportMeta = storeRawMaterialsReportDocument($rawWarehouseReport);
    
    if (!$reportMeta) {
        echo json_encode([
            'success' => false,
            'error' => 'فشل في حفظ التقرير. يرجى التحقق من صلاحيات المجلد.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'viewer_url' => $reportMeta['absolute_viewer_url'] ?? '',
        'print_url' => $reportMeta['absolute_print_url'] ?? '',
        'viewer_path' => $reportMeta['viewer_path'] ?? '',
        'print_path' => $reportMeta['print_path'] ?? '',
        'generated_at' => $rawWarehouseReport['generated_at']
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Raw materials report generation error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'error' => 'حدث خطأ في توليد التقرير: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


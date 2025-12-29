<?php
/**
 * API: الحصول على أسماء القوالب للإنتاج
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $db = db();
    $templates = [];
    
    // جلب القوالب من unified_product_templates إذا كان موجوداً
    try {
        $unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
        if (!empty($unifiedTemplatesCheck)) {
            $unifiedTemplates = $db->query("
                SELECT DISTINCT product_name 
                FROM unified_product_templates 
                WHERE status = 'active' 
                ORDER BY product_name ASC
            ");
            foreach ($unifiedTemplates as $template) {
                $templateName = trim($template['product_name'] ?? '');
                if ($templateName !== '') {
                    $templates[] = $templateName;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error fetching unified_product_templates: ' . $e->getMessage());
    }
    
    // جلب القوالب من product_templates إذا كان موجوداً أيضاً (قد يكون هناك قوالب في كلا الجدولين)
    try {
        $templatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
        if (!empty($templatesCheck)) {
            $productTemplates = $db->query("
                SELECT DISTINCT product_name 
                FROM product_templates 
                WHERE status = 'active' 
                ORDER BY product_name ASC
            ");
            foreach ($productTemplates as $template) {
                $templateName = trim($template['product_name'] ?? '');
                if ($templateName !== '') {
                    $templates[] = $templateName;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error fetching product_templates: ' . $e->getMessage());
    }
    
    // إزالة التكرار وترتيب القوالب أبجدياً
    $templates = array_unique($templates);
    sort($templates);
    $templates = array_values($templates);
    
    echo json_encode([
        'success' => true,
        'templates' => $templates
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    error_log('Get product templates API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'templates' => []
    ], JSON_UNESCAPED_UNICODE);
}


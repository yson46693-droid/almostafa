<?php
/**
 * تنفيذ Migration: إصلاح ENUM status في جدول backups
 * 
 * يمكن تشغيل هذا الملف من المتصفح مباشرة
 * URL: /database/migrations/apply_fix_backups_status.php
 */

// السماح بالوصول
define('ACCESS_ALLOWED', true);

// تحميل ملفات الإعدادات
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// التحقق من أن المستخدم مدير (اختياري - يمكن إزالته للتشغيل السريع)
// require_once __DIR__ . '/../../includes/auth.php';
// requireRole('manager');

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنفيذ Migration - إصلاح جدول backups</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .migration-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
            max-width: 800px;
            width: 100%;
        }
        .log-output {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            max-height: 500px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        .info { color: #60a5fa; }
        .warning { color: #fbbf24; }
    </style>
</head>
<body>
    <div class="migration-container">
        <h2 class="text-center mb-4">
            <i class="bi bi-database-fill-gear"></i>
            تنفيذ Migration - إصلاح جدول backups
        </h2>
        
        <div class="log-output" id="logOutput">
<?php
try {
    echo "<span class='info'>بدء تنفيذ Migration: fix_backups_status_enum</span>\n";
    echo "<span class='info'>==========================================</span>\n\n";
    
    $db = db();
    
    // 1. التحقق من الحالة الحالية
    echo "<span class='info'>0. فحص الحالة الحالية...</span>\n";
    try {
        $currentStats = $db->queryOne("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM backups
        ");
        echo "   إجمالي النسخ الحالية: {$currentStats['total']}\n";
        echo "   - نجحت: {$currentStats['success_count']}\n";
        echo "   - فشلت: {$currentStats['failed_count']}\n\n";
    } catch (Exception $e) {
        echo "   <span class='warning'>تحذير: {$e->getMessage()}</span>\n\n";
    }
    
    // 2. تحديث تعريف العمود status
    echo "<span class='info'>1. تحديث تعريف العمود status...</span>\n";
    try {
        $db->execute("
            ALTER TABLE `backups` 
            MODIFY COLUMN `status` ENUM('success','failed','completed') DEFAULT 'success'
        ");
        echo "   <span class='success'>✓ تم تحديث تعريف العمود بنجاح</span>\n\n";
    } catch (Exception $e) {
        // قد يكون العمود محدث بالفعل
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already') !== false) {
            echo "   <span class='warning'>⚠ العمود محدث بالفعل</span>\n\n";
        } else {
            throw $e;
        }
    }
    
    // 3. تحديث السجلات القديمة
    echo "<span class='info'>2. تحديث السجلات القديمة...</span>\n";
    try {
        $result = $db->execute("
            UPDATE `backups` 
            SET `status` = 'success' 
            WHERE `status` IS NULL 
              AND `file_size` > 0 
              AND `filename` != ''
        ");
        
        $affectedRows = $result['affected_rows'] ?? 0;
        echo "   <span class='success'>✓ تم تحديث {$affectedRows} سجل</span>\n\n";
    } catch (Exception $e) {
        echo "   <span class='warning'>⚠ {$e->getMessage()}</span>\n\n";
    }
    
    // 4. التحقق من النتائج النهائية
    echo "<span class='info'>3. التحقق من النتائج النهائية...</span>\n";
    $finalStats = $db->queryOne("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM backups
    ");
    
    echo "   إجمالي النسخ الاحتياطية: {$finalStats['total']}\n";
    echo "   - نجحت (success): {$finalStats['success_count']}\n";
    echo "   - فشلت (failed): {$finalStats['failed_count']}\n";
    echo "   - مكتملة (completed): {$finalStats['completed_count']}\n\n";
    
    echo "<span class='info'>==========================================</span>\n";
    echo "<span class='success'>✓ تم تنفيذ Migration بنجاح!</span>\n\n";
    echo "<span class='info'>يمكنك الآن إنشاء نسخة احتياطية جديدة والتحقق من أن الحالة تظهر بشكل صحيح.</span>\n";
    
} catch (Exception $e) {
    echo "\n<span class='error'>✗ خطأ في تنفيذ Migration:</span>\n";
    echo "<span class='error'>  " . htmlspecialchars($e->getMessage()) . "</span>\n\n";
    echo "<span class='error'>Stack trace:</span>\n";
    echo "<span class='error'>" . htmlspecialchars($e->getTraceAsString()) . "</span>\n";
}
?>
        </div>
        
        <div class="text-center mt-4">
            <a href="../../modules/manager/backups.php" class="btn btn-primary">
                <i class="bi bi-arrow-right"></i> الانتقال إلى صفحة النسخ الاحتياطية
            </a>
            <button onclick="location.reload()" class="btn btn-secondary">
                <i class="bi bi-arrow-clockwise"></i> إعادة التشغيل
            </button>
        </div>
    </div>
    
    <link rel="stylesheet" href="<?php echo defined('ASSETS_URL') ? ASSETS_URL : '/assets/'; ?>bootstrap-icons/bootstrap-icons.css">
</body>
</html>

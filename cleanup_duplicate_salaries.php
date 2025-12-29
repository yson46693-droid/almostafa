<?php
/**
 * سكريبت تنظيف الرواتب المكررة
 * يبحث عن الرواتب المكررة لنفس المستخدم في نفس الشهر والسنة
 * ويحتفظ بأحدث راتب فقط (أو الراتب الذي له أكبر ID)
 * 
 * الاستخدام: قم بتشغيل هذا الملف من المتصفح أو من سطر الأوامر
 * php cleanup_duplicate_salaries.php
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// التحقق من تسجيل الدخول (اختياري - يمكن إزالة هذا إذا كنت تريد تشغيله من سطر الأوامر)
if (php_sapi_name() !== 'cli') {
    requireLogin();
    requireAnyRole(['manager', 'accountant']);
}

$db = db();
$conn = $db->getConnection();

echo "=== بدء تنظيف الرواتب المكررة ===\n\n";

try {
    // التحقق من نوع عمود month
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    $isMonthDate = stripos($monthType, 'date') !== false;
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    echo "نوع عمود month: " . ($isMonthDate ? 'DATE' : 'INT') . "\n";
    echo "وجود عمود year: " . ($hasYearColumn ? 'نعم' : 'لا') . "\n\n";
    
    // البحث عن الرواتب المكررة
    $duplicates = [];
    
    if ($isMonthDate) {
        // إذا كان month من نوع DATE
        $duplicateQuery = "
            SELECT 
                user_id,
                DATE_FORMAT(month, '%Y-%m') as year_month,
                COUNT(*) as count,
                GROUP_CONCAT(id ORDER BY id DESC) as salary_ids
            FROM salaries
            WHERE month IS NOT NULL 
            AND month != '0000-00-00'
            AND month != '1970-01-01'
            GROUP BY user_id, DATE_FORMAT(month, '%Y-%m')
            HAVING COUNT(*) > 1
            ORDER BY user_id, year_month
        ";
    } elseif ($hasYearColumn) {
        // إذا كان month من نوع INT مع وجود عمود year
        $duplicateQuery = "
            SELECT 
                user_id,
                month,
                year,
                CONCAT(year, '-', LPAD(month, 2, '0')) as year_month,
                COUNT(*) as count,
                GROUP_CONCAT(id ORDER BY id DESC) as salary_ids
            FROM salaries
            WHERE month > 0 AND month <= 12
            AND year > 2000 AND year <= 2100
            GROUP BY user_id, month, year
            HAVING COUNT(*) > 1
            ORDER BY user_id, year, month
        ";
    } else {
        // إذا كان month من نوع INT فقط (بدون year)
        $duplicateQuery = "
            SELECT 
                user_id,
                month,
                CONCAT('Unknown-', LPAD(month, 2, '0')) as year_month,
                COUNT(*) as count,
                GROUP_CONCAT(id ORDER BY id DESC) as salary_ids
            FROM salaries
            WHERE month > 0 AND month <= 12
            GROUP BY user_id, month
            HAVING COUNT(*) > 1
            ORDER BY user_id, month
        ";
    }
    
    $duplicateResults = $db->query($duplicateQuery);
    
    if (empty($duplicateResults)) {
        echo "✓ لا توجد رواتب مكررة!\n";
        exit(0);
    }
    
    echo "تم العثور على " . count($duplicateResults) . " مجموعة من الرواتب المكررة:\n\n";
    
    $totalDeleted = 0;
    $totalKept = 0;
    
    $conn->begin_transaction();
    
    try {
        foreach ($duplicateResults as $dup) {
            $userId = $dup['user_id'];
            $yearMonth = $dup['year_month'];
            $count = $dup['count'];
            $salaryIds = explode(',', $dup['salary_ids']);
            
            // الحصول على معلومات المستخدم
            $user = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$userId]);
            $userName = $user['full_name'] ?? $user['username'] ?? "User {$userId}";
            
            echo "المستخدم: {$userName} (ID: {$userId}) - الشهر: {$yearMonth} - عدد المكررات: {$count}\n";
            echo "  معرفات الرواتب: " . implode(', ', $salaryIds) . "\n";
            
            // نحتفظ بأحدث راتب (أكبر ID) ونحذف الباقي
            $keepId = (int)$salaryIds[0]; // أول ID في القائمة (الأكبر لأننا استخدمنا ORDER BY id DESC)
            $deleteIds = array_slice($salaryIds, 1); // باقي IDs للحذف
            
            echo "  ✓ سيتم الاحتفاظ بالراتب ID: {$keepId}\n";
            echo "  ✗ سيتم حذف الرواتب IDs: " . implode(', ', $deleteIds) . "\n";
            
            // حذف الرواتب المكررة
            foreach ($deleteIds as $deleteId) {
                $deleteId = (int)$deleteId;
                
                // التحقق من وجود ربط مع salary_settlements أو salary_advances
                $hasSettlements = $db->queryOne(
                    "SELECT COUNT(*) as cnt FROM salary_settlements WHERE salary_id = ?",
                    [$deleteId]
                );
                $hasAdvances = $db->queryOne(
                    "SELECT COUNT(*) as cnt FROM salary_advances WHERE deducted_from_salary_id = ?",
                    [$deleteId]
                );
                
                if (($hasSettlements['cnt'] ?? 0) > 0 || ($hasAdvances['cnt'] ?? 0) > 0) {
                    echo "    ⚠ تحذير: الراتب ID {$deleteId} له روابط مع تسويات أو سلف - سيتم تخطيه\n";
                    continue;
                }
                
                // حذف الراتب
                $db->execute("DELETE FROM salaries WHERE id = ?", [$deleteId]);
                $totalDeleted++;
                echo "    ✓ تم حذف الراتب ID: {$deleteId}\n";
            }
            
            $totalKept++;
            echo "\n";
        }
        
        $conn->commit();
        
        echo "\n=== ملخص العملية ===\n";
        echo "عدد مجموعات الرواتب المكررة: " . count($duplicateResults) . "\n";
        echo "عدد الرواتب المحفوظة: {$totalKept}\n";
        echo "عدد الرواتب المحذوفة: {$totalDeleted}\n";
        echo "\n✓ تم تنظيف الرواتب المكررة بنجاح!\n";
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo "\n✗ خطأ أثناء تنظيف الرواتب المكررة: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}


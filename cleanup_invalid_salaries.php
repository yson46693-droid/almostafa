<?php

define('ACCESS_ALLOWED', true);

// تضمين ملفات النظام
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';


header('Content-Type: text/html; charset=utf-8');

echo "<html dir='rtl'><head><title>تنظيف سجلات الرواتب</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
    h2 { color: #555; margin-top: 30px; }
    .success { color: green; background: #d4edda; padding: 10px; border-radius: 4px; margin: 10px 0; }
    .warning { color: #856404; background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0; }
    .error { color: red; background: #f8d7da; padding: 10px; border-radius: 4px; margin: 10px 0; }
    .info { color: #0c5460; background: #d1ecf1; padding: 10px; border-radius: 4px; margin: 10px 0; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
    th { background: #007bff; color: white; }
    tr:nth-child(even) { background: #f9f9f9; }
    .btn { display: inline-block; padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px; }
    .btn-success { background: #28a745; }
    .btn-warning { background: #ffc107; color: #212529; }
    .btn-info { background: #17a2b8; }
    .duplicate-row { background: #ffe6e6 !important; }
    .keep-row { background: #e6ffe6 !important; }
</style></head><body>";
echo "<div class='container'>";
echo "<h1>🧹 تنظيف وإصلاح سجلات الرواتب</h1>";

try {
    $db = db();
    
    // التحقق من نوع عمود month
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    $isMonthDate = (stripos($monthType, 'date') !== false);
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    // التحقق من وجود جدول salary_payments (يُستخدم في عدة أماكن)
    $hasPaymentsTable = false;
    try {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_payments'");
        $hasPaymentsTable = !empty($tableCheck);
    } catch (Exception $e) {
        $hasPaymentsTable = false;
    }
    
    echo "<div class='info'>";
    echo "<strong>معلومات الجدول:</strong><br>";
    echo "نوع عمود month: " . ($isMonthDate ? 'DATE' : 'INT') . "<br>";
    echo "عمود year: " . ($hasYearColumn ? 'موجود ✅' : 'غير موجود ❌') . "<br>";
    echo "جدول salary_payments: " . ($hasPaymentsTable ? 'موجود ✅' : 'غير موجود ❌');
    echo "</div>";
    
    // 1. البحث عن السجلات ذات التواريخ الخاطئة
    $invalidRecords = [];
    
    if ($isMonthDate) {
        // إذا كان month من نوع DATE
        $invalidRecords = $db->query(
            "SELECT s.*, u.full_name, u.username 
             FROM salaries s 
             LEFT JOIN users u ON s.user_id = u.id 
             WHERE s.month IS NULL 
                OR s.month = '0000-00-00' 
                OR s.month = '1970-01-01'
                OR YEAR(s.month) < 2000 
                OR YEAR(s.month) > 2100
             ORDER BY s.id"
        );
    } else {
        // إذا كان month من نوع INT
        if ($hasYearColumn) {
            $invalidRecords = $db->query(
                "SELECT s.*, u.full_name, u.username 
                 FROM salaries s 
                 LEFT JOIN users u ON s.user_id = u.id 
                 WHERE s.month IS NULL 
                    OR s.month < 1 
                    OR s.month > 12 
                    OR s.year IS NULL 
                    OR s.year < 2000 
                    OR s.year > 2100
                    OR s.year = 0
                 ORDER BY s.id"
            );
        } else {
            $invalidRecords = $db->query(
                "SELECT s.*, u.full_name, u.username 
                 FROM salaries s 
                 LEFT JOIN users u ON s.user_id = u.id 
                 WHERE s.month IS NULL 
                    OR s.month < 1 
                    OR s.month > 12
                 ORDER BY s.id"
            );
        }
    }
    
    $invalidCount = count($invalidRecords);
    
    if ($invalidCount === 0) {
        echo "<div class='success'>✅ لا توجد سجلات رواتب ذات تواريخ خاطئة!</div>";
    } else {
        echo "<div class='warning'>⚠️ تم العثور على <strong>{$invalidCount}</strong> سجل راتب بتاريخ خاطئ</div>";
        
        // عرض السجلات
        echo "<h3>السجلات المعطوبة:</h3>";
        echo "<table>";
        echo "<tr><th>ID</th><th>الموظف</th><th>الشهر</th><th>السنة</th><th>المبلغ</th><th>الحالة</th></tr>";
        
        foreach ($invalidRecords as $record) {
            $monthValue = $record['month'] ?? 'NULL';
            $yearValue = $hasYearColumn ? ($record['year'] ?? 'NULL') : 'غير موجود';
            $userName = $record['full_name'] ?? $record['username'] ?? 'غير معروف';
            $totalAmount = number_format($record['total_amount'] ?? 0, 2);
            $status = $record['status'] ?? 'غير محدد';
            
            echo "<tr>";
            echo "<td>{$record['id']}</td>";
            echo "<td>{$userName}</td>";
            echo "<td>{$monthValue}</td>";
            echo "<td>{$yearValue}</td>";
            echo "<td>{$totalAmount}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // التنفيذ الفعلي للحذف
        if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
            echo "<h3>🗑️ جاري الحذف...</h3>";
            
            $deletedCount = 0;
            $errors = [];
            
            foreach ($invalidRecords as $record) {
                try {
                    // التحقق من أن السجل ليس له مدفوعات مرتبطة (فقط إذا كان الجدول موجوداً)
                    if ($hasPaymentsTable) {
                        $hasPayments = $db->queryOne(
                            "SELECT COUNT(*) as cnt FROM salary_payments WHERE salary_id = ?",
                            [$record['id']]
                        );
                        
                        if (!empty($hasPayments) && $hasPayments['cnt'] > 0) {
                            $errors[] = "السجل ID:{$record['id']} له مدفوعات مرتبطة - تم تخطيه";
                            continue;
                        }
                    }
                    
                    // حذف السجل
                    $db->execute("DELETE FROM salaries WHERE id = ?", [$record['id']]);
                    $deletedCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "خطأ في حذف السجل ID:{$record['id']}: " . $e->getMessage();
                }
            }
            
            if ($deletedCount > 0) {
                echo "<div class='success'>✅ تم حذف <strong>{$deletedCount}</strong> سجل بنجاح!</div>";
            }
            
            if (!empty($errors)) {
                echo "<div class='error'>";
                echo "<strong>تنبيهات:</strong><br>";
                foreach ($errors as $err) {
                    echo "- {$err}<br>";
                }
                echo "</div>";
            }
            
        } else {
            // رابط التأكيد
            echo "<a href='?confirm=yes' class='btn' onclick='return confirm(\"هل أنت متأكد من حذف هذه السجلات؟ هذا الإجراء لا يمكن التراجع عنه!\");'>🗑️ حذف السجلات المعطوبة</a>";
            echo "<p style='color: gray; font-size: 12px;'>ملاحظة: لن يتم حذف السجلات التي لها مدفوعات مرتبطة</p>";
        }
    }
    
    // 2. إضافة عمود year إذا لم يكن موجوداً
    if (!$hasYearColumn) {
        echo "<h3>⚙️ إضافة عمود year المفقود</h3>";
        
        if (isset($_GET['add_year']) && $_GET['add_year'] === 'yes') {
            try {
                $db->execute("ALTER TABLE salaries ADD COLUMN year INT(4) DEFAULT NULL AFTER month");
                echo "<div class='success'>✅ تم إضافة عمود year بنجاح!</div>";
                
                // محاولة استخراج السنة من month إذا كان من نوع DATE
                if ($isMonthDate) {
                    $db->execute("UPDATE salaries SET year = YEAR(month) WHERE year IS NULL AND month IS NOT NULL AND month != '0000-00-00'");
                    echo "<div class='success'>✅ تم تحديث قيم year من عمود month!</div>";
                }
                
            } catch (Exception $e) {
                echo "<div class='error'>❌ خطأ: " . $e->getMessage() . "</div>";
            }
        } else {
            echo "<a href='?add_year=yes' class='btn btn-success'>➕ إضافة عمود year</a>";
        }
    }
    
    // 3. البحث عن السجلات المكررة (نفس الموظف ونفس الشهر والسنة)
    echo "<h2>🔄 السجلات المكررة</h2>";
    
    $duplicates = [];
    
    if ($hasYearColumn) {
        // البحث عن التكرارات باستخدام month و year
        $duplicates = $db->query(
            "SELECT s.user_id, s.month, s.year, u.full_name, u.username,
                    COUNT(*) as duplicate_count,
                    GROUP_CONCAT(s.id ORDER BY s.id) as salary_ids,
                    GROUP_CONCAT(s.total_amount ORDER BY s.id) as amounts,
                    GROUP_CONCAT(s.base_amount ORDER BY s.id) as base_amounts
             FROM salaries s
             LEFT JOIN users u ON s.user_id = u.id
             WHERE s.year IS NOT NULL AND s.year > 0
             GROUP BY s.user_id, s.month, s.year
             HAVING COUNT(*) > 1
             ORDER BY s.user_id, s.year, s.month"
        );
    } elseif ($isMonthDate) {
        // البحث عن التكرارات باستخدام DATE_FORMAT
        $duplicates = $db->query(
            "SELECT s.user_id, DATE_FORMAT(s.month, '%Y-%m') as month_year, u.full_name, u.username,
                    COUNT(*) as duplicate_count,
                    GROUP_CONCAT(s.id ORDER BY s.id) as salary_ids,
                    GROUP_CONCAT(s.total_amount ORDER BY s.id) as amounts,
                    GROUP_CONCAT(s.base_amount ORDER BY s.id) as base_amounts
             FROM salaries s
             LEFT JOIN users u ON s.user_id = u.id
             WHERE s.month IS NOT NULL AND s.month != '0000-00-00'
             GROUP BY s.user_id, DATE_FORMAT(s.month, '%Y-%m')
             HAVING COUNT(*) > 1
             ORDER BY s.user_id, month_year"
        );
    }
    
    $duplicateCount = count($duplicates);
    
    if ($duplicateCount === 0) {
        echo "<div class='success'>✅ لا توجد سجلات رواتب مكررة!</div>";
    } else {
        echo "<div class='warning'>⚠️ تم العثور على <strong>{$duplicateCount}</strong> حالة تكرار</div>";
        
        // عرض التكرارات
        echo "<table>";
        echo "<tr><th>الموظف</th><th>الشهر/السنة</th><th>عدد التكرارات</th><th>IDs</th><th>المبالغ</th><th>المبالغ الأساسية</th></tr>";
        
        foreach ($duplicates as $dup) {
            $userName = $dup['full_name'] ?? $dup['username'] ?? 'غير معروف';
            $monthYear = $hasYearColumn ? "{$dup['month']}/{$dup['year']}" : $dup['month_year'];
            
            echo "<tr>";
            echo "<td>{$userName}</td>";
            echo "<td>{$monthYear}</td>";
            echo "<td>{$dup['duplicate_count']}</td>";
            echo "<td>{$dup['salary_ids']}</td>";
            echo "<td>{$dup['amounts']}</td>";
            echo "<td>{$dup['base_amounts']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // حذف التكرارات (الاحتفاظ بالسجل الذي يحتوي على أعلى base_amount أو أحدث تاريخ)
        if (isset($_GET['fix_duplicates']) && $_GET['fix_duplicates'] === 'yes') {
            echo "<h3>🔧 جاري إصلاح التكرارات...</h3>";
            
            $fixedCount = 0;
            $fixErrors = [];
            
            foreach ($duplicates as $dup) {
                try {
                    $salaryIds = explode(',', $dup['salary_ids']);
                    $baseAmounts = explode(',', $dup['base_amounts']);
                    
                    // تحديد السجل الذي سيتم الاحتفاظ به (الأعلى في base_amount)
                    $maxBaseAmount = 0;
                    $keepId = $salaryIds[0]; // افتراضياً الأول
                    
                    foreach ($salaryIds as $index => $id) {
                        $baseAmount = floatval($baseAmounts[$index] ?? 0);
                        if ($baseAmount > $maxBaseAmount) {
                            $maxBaseAmount = $baseAmount;
                            $keepId = $id;
                        }
                    }
                    
                    // حذف السجلات المكررة ما عدا السجل المحتفظ به
                    foreach ($salaryIds as $id) {
                        if ($id != $keepId) {
                            // التحقق من وجود مدفوعات مرتبطة
                            $hasPayments = false;
                            if ($hasPaymentsTable) {
                                $paymentCheck = $db->queryOne(
                                    "SELECT COUNT(*) as cnt FROM salary_payments WHERE salary_id = ?",
                                    [$id]
                                );
                                $hasPayments = ($paymentCheck['cnt'] ?? 0) > 0;
                            }
                            
                            if (!$hasPayments) {
                                $db->execute("DELETE FROM salaries WHERE id = ?", [$id]);
                                $fixedCount++;
                            } else {
                                $fixErrors[] = "السجل ID:{$id} له مدفوعات مرتبطة - تم تخطيه";
                            }
                        }
                    }
                    
                } catch (Exception $e) {
                    $fixErrors[] = "خطأ في معالجة التكرار: " . $e->getMessage();
                }
            }
            
            if ($fixedCount > 0) {
                echo "<div class='success'>✅ تم حذف <strong>{$fixedCount}</strong> سجل مكرر!</div>";
            }
            
            if (!empty($fixErrors)) {
                echo "<div class='warning'>";
                echo "<strong>تنبيهات:</strong><br>";
                foreach ($fixErrors as $err) {
                    echo "- {$err}<br>";
                }
                echo "</div>";
            }
            
        } else {
            echo "<a href='?fix_duplicates=yes' class='btn btn-warning' onclick='return confirm(\"سيتم الاحتفاظ بالسجل ذو أعلى مبلغ أساسي وحذف البقية. هل أنت متأكد؟\");'>🔧 إصلاح التكرارات تلقائياً</a>";
            echo "<p style='color: gray; font-size: 12px;'>ملاحظة: سيتم الاحتفاظ بالسجل الذي يحتوي على أعلى مبلغ أساسي (base_amount) وحذف البقية</p>";
        }
    }
    
    // 4. إصلاح التواريخ الصفرية (تحويلها لتاريخ الشهر الحالي)
    echo "<h2>📅 السجلات بتواريخ صفرية (0000-00-00)</h2>";
    
    $zeroDateRecords = [];
    if ($isMonthDate) {
        $zeroDateRecords = $db->query(
            "SELECT s.*, u.full_name, u.username 
             FROM salaries s 
             LEFT JOIN users u ON s.user_id = u.id 
             WHERE s.month = '0000-00-00' OR s.month = '1970-01-01'
             ORDER BY s.id"
        );
    }
    
    $zeroDateCount = count($zeroDateRecords);
    
    if ($zeroDateCount === 0) {
        echo "<div class='success'>✅ لا توجد سجلات بتواريخ صفرية!</div>";
    } else {
        echo "<div class='warning'>⚠️ تم العثور على <strong>{$zeroDateCount}</strong> سجل بتاريخ صفري</div>";
        
        echo "<table>";
        echo "<tr><th>ID</th><th>الموظف</th><th>التاريخ الحالي</th><th>المبلغ الأساسي</th><th>المبلغ الإجمالي</th><th>الساعات</th></tr>";
        
        foreach ($zeroDateRecords as $record) {
            $userName = $record['full_name'] ?? $record['username'] ?? 'غير معروف';
            echo "<tr>";
            echo "<td>{$record['id']}</td>";
            echo "<td>{$userName}</td>";
            echo "<td>{$record['month']}</td>";
            echo "<td>" . number_format($record['base_amount'] ?? 0, 2) . "</td>";
            echo "<td>" . number_format($record['total_amount'] ?? 0, 2) . "</td>";
            echo "<td>" . number_format($record['total_hours'] ?? 0, 2) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // خيار تحديث التواريخ الصفرية للشهر الحالي
        if (isset($_GET['fix_zero_dates']) && $_GET['fix_zero_dates'] === 'yes') {
            $currentMonth = date('Y-m-01');
            $currentMonthNum = (int)date('n');
            $currentYear = (int)date('Y');
            
            try {
                if ($hasYearColumn) {
                    // تحديث month و year
                    $db->execute(
                        "UPDATE salaries SET month = ?, year = ? WHERE month = '0000-00-00' OR month = '1970-01-01'",
                        [$currentMonthNum, $currentYear]
                    );
                } else {
                    // تحديث month فقط كتاريخ
                    $db->execute(
                        "UPDATE salaries SET month = ? WHERE month = '0000-00-00' OR month = '1970-01-01'",
                        [$currentMonth]
                    );
                }
                echo "<div class='success'>✅ تم تحديث التواريخ الصفرية إلى الشهر الحالي ({$currentMonth})!</div>";
            } catch (Exception $e) {
                echo "<div class='error'>❌ خطأ في التحديث: " . $e->getMessage() . "</div>";
            }
        } else {
            $currentMonth = date('Y-m-01');
            echo "<a href='?fix_zero_dates=yes' class='btn btn-info' onclick='return confirm(\"سيتم تحديث التواريخ الصفرية إلى {$currentMonth}. هل أنت متأكد؟\");'>📅 تحديث التواريخ للشهر الحالي</a>";
        }
    }
    
    // 5. إحصائيات
    echo "<h2>📊 إحصائيات الرواتب</h2>";
    
    $totalSalaries = $db->queryOne("SELECT COUNT(*) as cnt FROM salaries");
    $uniqueUsers = $db->queryOne("SELECT COUNT(DISTINCT user_id) as cnt FROM salaries");
    
    // عرض جميع سجلات الرواتب الحالية
    echo "<div class='info'>";
    echo "إجمالي سجلات الرواتب: <strong>" . ($totalSalaries['cnt'] ?? 0) . "</strong><br>";
    echo "عدد الموظفين: <strong>" . ($uniqueUsers['cnt'] ?? 0) . "</strong>";
    echo "</div>";
    
    // عرض جدول بجميع الرواتب
    echo "<h3>جميع سجلات الرواتب:</h3>";
    
    if ($hasYearColumn) {
        $allSalaries = $db->query(
            "SELECT s.*, u.full_name, u.username 
             FROM salaries s 
             LEFT JOIN users u ON s.user_id = u.id 
             ORDER BY s.year DESC, s.month DESC, s.user_id"
        );
    } else {
        $allSalaries = $db->query(
            "SELECT s.*, u.full_name, u.username 
             FROM salaries s 
             LEFT JOIN users u ON s.user_id = u.id 
             ORDER BY s.month DESC, s.user_id"
        );
    }
    
    echo "<table>";
    echo "<tr><th>ID</th><th>الموظف</th><th>الشهر</th><th>السنة</th><th>الساعات</th><th>المبلغ الأساسي</th><th>المكافآت</th><th>الخصومات</th><th>الإجمالي</th><th>الحالة</th></tr>";
    
    foreach ($allSalaries as $salary) {
        $userName = $salary['full_name'] ?? $salary['username'] ?? 'غير معروف';
        $monthVal = $salary['month'] ?? 'N/A';
        $yearVal = $hasYearColumn ? ($salary['year'] ?? 'N/A') : '-';
        
        // تحديد لون الصف
        $rowClass = '';
        if ($monthVal == '0000-00-00' || $monthVal == '1970-01-01' || $yearVal == 0 || $yearVal === null) {
            $rowClass = 'class="duplicate-row"';
        }
        
        echo "<tr {$rowClass}>";
        echo "<td>{$salary['id']}</td>";
        echo "<td>{$userName}</td>";
        echo "<td>{$monthVal}</td>";
        echo "<td>{$yearVal}</td>";
        echo "<td>" . number_format($salary['total_hours'] ?? 0, 2) . "</td>";
        echo "<td>" . number_format($salary['base_amount'] ?? 0, 2) . "</td>";
        echo "<td>" . number_format($salary['bonus'] ?? $salary['bonuses'] ?? 0, 2) . "</td>";
        echo "<td>" . number_format($salary['deductions'] ?? 0, 2) . "</td>";
        echo "<td>" . number_format($salary['total_amount'] ?? 0, 2) . "</td>";
        echo "<td>" . ($salary['status'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 6. رابط لتنظيف كل شيء دفعة واحدة
    echo "<h2>🚀 تنظيف شامل</h2>";
    
    if (isset($_GET['clean_all']) && $_GET['clean_all'] === 'yes') {
        echo "<div class='info'>جاري التنظيف الشامل...</div>";
        
        $totalCleaned = 0;
        
        // 1. حذف السجلات بتواريخ خاطئة
        if ($isMonthDate) {
            $result = $db->execute(
                "DELETE FROM salaries WHERE month IS NULL OR month = '0000-00-00' OR month = '1970-01-01'"
            );
            $totalCleaned += $result['affected_rows'] ?? 0;
        }
        
        // 2. حذف التكرارات (الاحتفاظ بالأعلى base_amount)
        foreach ($duplicates as $dup) {
            $salaryIds = explode(',', $dup['salary_ids']);
            $baseAmounts = explode(',', $dup['base_amounts']);
            
            $maxBaseAmount = 0;
            $keepId = $salaryIds[0];
            
            foreach ($salaryIds as $index => $id) {
                $baseAmount = floatval($baseAmounts[$index] ?? 0);
                if ($baseAmount > $maxBaseAmount) {
                    $maxBaseAmount = $baseAmount;
                    $keepId = $id;
                }
            }
            
            foreach ($salaryIds as $id) {
                if ($id != $keepId) {
                    try {
                        $db->execute("DELETE FROM salaries WHERE id = ?", [$id]);
                        $totalCleaned++;
                    } catch (Exception $e) {
                        // تجاهل الأخطاء
                    }
                }
            }
        }
        
        echo "<div class='success'>✅ تم تنظيف <strong>{$totalCleaned}</strong> سجل بنجاح! <a href='?' class='btn btn-success'>تحديث الصفحة</a></div>";
        
    } else {
        echo "<a href='?clean_all=yes' class='btn' onclick='return confirm(\"سيتم حذف جميع السجلات المعطوبة والمكررة. هذا الإجراء لا يمكن التراجع عنه! هل أنت متأكد؟\");'>🧹 تنظيف شامل (حذف الكل)</a>";
        echo "<p style='color: gray; font-size: 12px;'>سيقوم بحذف جميع السجلات ذات التواريخ الخاطئة والسجلات المكررة</p>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>❌ خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage() . "</div>";
}

echo "</div></body></html>";

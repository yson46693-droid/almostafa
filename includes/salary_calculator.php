<?php
/**
 * نظام حساب الرواتب
 * حساب الرواتب بناءً على سعر الساعة وعدد الساعات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';

/**
 * التأكد من وجود أعمدة مكافآت التحصيلات في جدول الرواتب.
 *
 * @return bool
 */
function ensureCollectionsBonusColumn(): bool {
    static $collectionsColumnsEnsured = null;
    
    if ($collectionsColumnsEnsured !== null) {
        return $collectionsColumnsEnsured;
    }
    
    try {
        $db = db();
        
        // التحقق من وجود عمود bonus أو bonuses لتحديد موضع الإضافة
        $bonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field IN ('bonus', 'bonuses')");
        $afterColumn = 'deductions'; // افتراضي: بعد deductions
        if (!empty($bonusColumnCheck)) {
            $afterColumn = $bonusColumnCheck['Field'];
        } else {
            // التحقق من وجود deductions
            $deductionsCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'deductions'");
            if (empty($deductionsCheck)) {
                $afterColumn = 'base_amount'; // إذا لم يكن deductions موجوداً، استخدم base_amount
            }
        }
        
        $bonusColumnExists = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'collections_bonus'");
        if (empty($bonusColumnExists)) {
            $db->execute("
                ALTER TABLE `salaries`
                ADD COLUMN `collections_bonus` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'مكافآت التحصيلات 2%' 
                AFTER `{$afterColumn}`
            ");
        }
        
        $amountColumnExists = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'collections_amount'");
        if (empty($amountColumnExists)) {
            $db->execute("
                ALTER TABLE `salaries`
                ADD COLUMN `collections_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'إجمالي مبالغ التحصيلات للمندوب'
                AFTER `collections_bonus`
            ");
        }
        
        $collectionsColumnsEnsured = true;
    } catch (Throwable $columnError) {
        error_log('Failed to ensure collections bonus columns: ' . $columnError->getMessage());
        $collectionsColumnsEnsured = false;
    }
    
    return $collectionsColumnsEnsured;
}

/**
 * حساب عدد الساعات الشهرية للمستخدم
 * يستخدم جدول attendance_records الجديد
 */
function calculateMonthlyHours($userId, $month, $year) {
    $db = db();
    $hasCollectionsBonusColumn = ensureCollectionsBonusColumn();
    
    // التحقق من وجود جدول attendance_records
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    
    if (!empty($tableCheck)) {
        // استخدام الجدول الجديد
        $monthKey = sprintf('%04d-%02d', $year, $month);
        
        // 1. حساب الساعات من السجلات المكتملة (التي لديها check_out_time)
        $completedResult = $db->queryOne(
            "SELECT COALESCE(SUM(work_hours), 0) as total_hours 
             FROM attendance_records 
             WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
             AND check_out_time IS NOT NULL
             AND work_hours IS NOT NULL
             AND work_hours > 0",
            [$userId, $monthKey]
        );
        
        $totalHours = round($completedResult['total_hours'] ?? 0, 2);
        
        // 2. حساب الساعات من السجلات غير المكتملة (حضور بدون انصراف)
        $incompleteRecords = $db->query(
            "SELECT id, date, check_in_time 
             FROM attendance_records 
             WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
             AND check_out_time IS NULL
             AND check_in_time IS NOT NULL",
            [$userId, $monthKey]
        );
        
        // الحصول على موعد العمل الرسمي للمستخدم
        // استخدام نفس المنطق الموجود في attendance.php
        $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
        $workTime = null;
        if ($user) {
            $role = $user['role'];
            if ($role === 'accountant') {
                $workTime = ['start' => '10:00:00', 'end' => '19:00:00'];
            } elseif ($role === 'sales') {
                $workTime = ['start' => '10:00:00', 'end' => '19:00:00'];
            } elseif ($role !== 'manager') {
                // عمال الإنتاج
                $workTime = ['start' => '09:00:00', 'end' => '19:00:00'];
            }
        }
        
        foreach ($incompleteRecords as $record) {
            // إذا لم يسجل المستخدم الانصراف، يحتسب النظام 5 ساعات فقط
            $totalHours += 5.0;
        }
        
        $totalHours = round($totalHours, 2);
        
        return $totalHours;
    } else {
        // Fallback للجدول القديم
        $totalHours = 0;
        
        $attendanceRecords = $db->query(
            "SELECT * FROM attendance 
             WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?
             AND check_in IS NOT NULL AND check_out IS NOT NULL
             ORDER BY date ASC",
            [$userId, $month, $year]
        );
        
        foreach ($attendanceRecords as $record) {
            $checkIn = strtotime($record['date'] . ' ' . $record['check_in']);
            $checkOut = strtotime($record['date'] . ' ' . $record['check_out']);
            
            if ($checkOut > $checkIn) {
                $hours = ($checkOut - $checkIn) / 3600;
                $totalHours += $hours;
            }
        }
        
        return round($totalHours, 2);
    }
}

/**
 * حساب الساعات المكتملة فقط (التي تم تسجيل الانصراف لها)
 * هذه الدالة تحسب فقط الساعات من السجلات التي لديها check_out_time
 * ولا تشمل الساعات من السجلات غير المكتملة (حضور بدون انصراف)
 */
function calculateCompletedMonthlyHours($userId, $month, $year) {
    $db = db();
    
    // التحقق من وجود جدول attendance_records
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    
    if (!empty($tableCheck)) {
        // استخدام الجدول الجديد
        $monthKey = sprintf('%04d-%02d', $year, $month);
        
        // حساب الساعات من السجلات المكتملة فقط (التي لديها check_out_time)
        $completedResult = $db->queryOne(
            "SELECT COALESCE(SUM(work_hours), 0) as total_hours 
             FROM attendance_records 
             WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
             AND check_out_time IS NOT NULL
             AND work_hours IS NOT NULL
             AND work_hours > 0",
            [$userId, $monthKey]
        );
        
        $completedHours = round($completedResult['total_hours'] ?? 0, 2);
        
        return $completedHours;
    } else {
        // Fallback للجدول القديم - يعيد نفس النتيجة لأن الجدول القديم يحتوي فقط على السجلات المكتملة
        $totalHours = 0;
        
        $attendanceRecords = $db->query(
            "SELECT * FROM attendance 
             WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?
             AND check_in IS NOT NULL AND check_out IS NOT NULL
             ORDER BY date ASC",
            [$userId, $month, $year]
        );
        
        foreach ($attendanceRecords as $record) {
            $checkIn = strtotime($record['date'] . ' ' . $record['check_in']);
            $checkOut = strtotime($record['date'] . ' ' . $record['check_out']);
            
            if ($checkOut > $checkIn) {
                $hours = ($checkOut - $checkIn) / 3600;
                $totalHours += $hours;
            }
        }
        
        return round($totalHours, 2);
    }
}

/**
 * حساب مجموع المبالغ المستحقة للمكافأة 2% للمندوب خلال الشهر
 * الحالات:
 * 1. المبيعات بكامل: 2% على إجمالي الفاتورة
 * 2. التحصيلات الجزئية: 2% على المبلغ المحصل
 * 3. التحصيلات من عملاء المندوب: 2% على المبلغ المحصل
 */
function calculateSalesCollections($userId, $month, $year) {
    $db = db();
    
    $totalCommissionBase = 0;
    
    // القواعد الجديدة لنسبة التحصيل:
    // نسبة التحصيل 2% تُحسب للمندوب فقط في 3 حالات:
    //   1. البيع الكاش (full payment) - تُحتسب على كامل المبلغ
    //   2. البيع بالتحصيل الجزئي - تُحتسب فقط على المبلغ المحصل (وليس على المبلغ المخصوم من الرصيد الدائن)
    //   3. التحصيل من صفحة العملاء - تُحتسب على المبلغ المحصل
    // 
    // ملاحظات مهمة:
    // - عند خصم من الرصيد الدائن لعميل لديه سجل مشتريات: يتم حساب نسبة 2% مباشرة كـ bonus
    //   (بدون إضافة للإجمالي أو السجل) في pos.php، لذا لا تُحسب هنا
    // - عند خصم من الرصيد الدائن لعميل ليس لديه سجل مشتريات: لا تُحسب نسبة
    // - المبالغ المخصومة من الرصيد الدائن لا تُحسب في التحصيلات (يتم استبعادها)
    
    // الحالة 1: المبيعات بكامل (البيع الكاش) - حساب 2% على إجمالي الفاتورة
    // الفواتير المدفوعة بالكامل (status='paid' و paid_amount = total_amount)
    // استبعاد الفواتير المدفوعة من رصيد دائن (لأنها تُحسب مباشرة في pos.php كـ bonus)
    $invoicesTableCheck = $db->queryOne("SHOW TABLES LIKE 'invoices'");
    if (!empty($invoicesTableCheck)) {
        // التحقق من وجود عمود paid_from_credit
        $hasPaidFromCreditColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'paid_from_credit'"));
        
        // التحقق من وجود جدول collections لاستبعاد الفواتير التي أصبحت paid بعد تحصيلات
        $collectionsTableCheck = $db->queryOne("SHOW TABLES LIKE 'collections'");
        $hasCollectionsTable = !empty($collectionsTableCheck);
        
        // التحقق من وجود عمود original_sales_rep_id
        $hasOriginalSalesRepIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'original_sales_rep_id'"));
        
        $fullPaymentSalesSql = "SELECT COALESCE(SUM(inv.total_amount), 0) as total 
             FROM invoices inv
             WHERE (inv.sales_rep_id = ?";
        
        // إذا كان هناك عمود original_sales_rep_id، نحسب أيضاً الفواتير المنقولة للمندوب الأصلي
        // (الفواتير التي original_sales_rep_id = userId لكن sales_rep_id != userId)
        if ($hasOriginalSalesRepIdColumn) {
            $fullPaymentSalesSql .= " OR (inv.original_sales_rep_id = ? AND inv.original_sales_rep_id != inv.sales_rep_id)";
        }
        
        $fullPaymentSalesSql .= ")
             AND MONTH(inv.date) = ? 
             AND YEAR(inv.date) = ?
             AND inv.status = 'paid'
             AND ABS(inv.paid_amount - inv.total_amount) < 0.01";
        
        // استبعاد الفواتير المنقولة للمندوب الجديد فقط
        // إذا كان original_sales_rep_id موجوداً ومختلفاً عن sales_rep_id الحالي، فهذا يعني أن الفاتورة تم نقلها
        // ولا تُحسب للمندوب الجديد (تُحسب للمندوب الأصلي فقط)
        if ($hasOriginalSalesRepIdColumn) {
            $fullPaymentSalesSql .= " AND (inv.original_sales_rep_id IS NULL OR inv.original_sales_rep_id = inv.sales_rep_id OR inv.original_sales_rep_id = ?)";
        }
        
        // استبعاد الفواتير المدفوعة من رصيد دائن
        // هذه الفواتير لها معاملة خاصة: تُحسب النسبة مباشرة كـ bonus في pos.php
        // (لعميل لديه سجل مشتريات) أو لا تُحسب (لعميل ليس لديه سجل مشتريات)
        // المبالغ المدفوعة من الرصيد الدائن لا تُحسب في التحصيلات لأن المندوب لم يقم بتحصيلها نقدياً
        if ($hasPaidFromCreditColumn) {
            $fullPaymentSalesSql .= " AND (inv.paid_from_credit IS NULL OR inv.paid_from_credit = 0)";
        } else {
            // إذا لم يكن العمود موجوداً، استبعد الفواتير التي لديها credit_used > 0
            // كحل بديل لضمان عدم حساب الفواتير المدفوعة من الرصيد الدائن
            $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
            if ($hasCreditUsedColumn) {
                $fullPaymentSalesSql .= " AND (inv.credit_used IS NULL OR inv.credit_used = 0 OR inv.credit_used <= 0.01)";
            }
        }
        
        // استبعاد الفواتير التي أصبحت paid بعد تحصيلات جزئية في نفس الشهر
        // هذه الفواتير تُحسب فقط في الحالة 2 (partial collections) على المبلغ المحصل
        // لتجنب العد المزدوج: حسابها في الحالة 1 على total_amount + حسابها في الحالة 2 على المبلغ المحصل
        if ($hasCollectionsTable) {
            // التحقق من وجود عمود invoice_id في collections
            $hasInvoiceIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'invoice_id'"));
            $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
            $hasStatusColumn = !empty($statusColumnCheck);
            
            if ($hasInvoiceIdColumn) {
                // إذا كان هناك عمود invoice_id، نستخدمه للربط المباشر
                $fullPaymentSalesSql .= " AND NOT EXISTS (
                    SELECT 1 FROM collections c
                    WHERE c.invoice_id = inv.id
                    AND MONTH(c.date) = ?
                    AND YEAR(c.date) = ?";
                if ($hasStatusColumn) {
                    $fullPaymentSalesSql .= " AND c.status IN ('pending', 'approved')";
                }
                $fullPaymentSalesSql .= ")";
                // إعداد المعاملات: userId, month, year, month, year
                // إذا كان hasOriginalSalesRepIdColumn موجوداً، نضيف userId مرة أخرى
                $params = [$userId, $month, $year, $month, $year];
                if ($hasOriginalSalesRepIdColumn) {
                    $params = [$userId, $userId, $month, $year, $month, $year, $userId];
                }
                $fullPaymentSales = $db->queryOne($fullPaymentSalesSql, $params);
            } else {
                // إذا لم يكن هناك عمود invoice_id، استبعاد الفواتير التي أصبحت paid في نفس الشهر
                // إذا كان للعميل تحصيلات في نفس الشهر (لتجنب العد المزدوج)
                // هذه الفواتير تُحسب فقط في الحالة 2 أو 3 على المبلغ المحصل
                // التحقق من وجود عمود updated_at في invoices
                $hasUpdatedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'updated_at'"));
                
                if ($hasUpdatedAtColumn) {
                    // استبعاد الفواتير التي أصبحت paid بعد التحديث (ليست عند الإنشاء)
                    // إذا كان للعميل تحصيلات في نفس الشهر (لتجنب العد المزدوج)
                    // هذه الفواتير تُحسب فقط في الحالة 2 أو 3 على المبلغ المحصل
                    // استبعاد فقط الفواتير التي تم تحديثها بعد الإنشاء لتكون paid
                    $fullPaymentSalesSql .= " AND NOT (
                        EXISTS (
                            SELECT 1 FROM collections c
                            WHERE c.customer_id = inv.customer_id
                            AND MONTH(c.date) = ?
                            AND YEAR(c.date) = ?";
                    if ($hasStatusColumn) {
                        $fullPaymentSalesSql .= " AND c.status IN ('pending', 'approved')";
                    }
                    $fullPaymentSalesSql .= "
                        )
                        AND MONTH(inv.updated_at) = ?
                        AND YEAR(inv.updated_at) = ?
                        AND (
                            DATE(inv.updated_at) > DATE(inv.date)
                            OR TIMESTAMPDIFF(HOUR, inv.date, inv.updated_at) > 1
                        )
                    )";
                    // إعداد المعاملات: userId, month, year, month, year, month, year
                    // إذا كان hasOriginalSalesRepIdColumn موجوداً، نضيف userId مرتين في البداية
                    $params = [$userId, $month, $year, $month, $year, $month, $year];
                    if ($hasOriginalSalesRepIdColumn) {
                        $params = [$userId, $userId, $month, $year, $month, $year, $month, $year, $userId];
                    }
                    $fullPaymentSales = $db->queryOne($fullPaymentSalesSql, $params);
                } else {
                    // إذا لم يكن هناك عمود updated_at، نستخدم notes للبحث عن رقم الفاتورة
                    $fullPaymentSalesSql .= " AND NOT EXISTS (
                        SELECT 1 FROM collections c
                        WHERE c.notes LIKE CONCAT('%فاتورة ', inv.invoice_number, '%')
                        AND MONTH(c.date) = ?
                        AND YEAR(c.date) = ?";
                    if ($hasStatusColumn) {
                        $fullPaymentSalesSql .= " AND c.status IN ('pending', 'approved')";
                    }
                    $fullPaymentSalesSql .= ")";
                    // إعداد المعاملات: userId, month, year, month, year
                    // إذا كان hasOriginalSalesRepIdColumn موجوداً، نضيف userId مرة أخرى
                    $params = [$userId, $month, $year, $month, $year];
                    if ($hasOriginalSalesRepIdColumn) {
                        $params = [$userId, $userId, $month, $year, $month, $year, $userId];
                    }
                    $fullPaymentSales = $db->queryOne($fullPaymentSalesSql, $params);
                }
            }
        } else {
            // إعداد المعاملات: userId, month, year
            // إذا كان hasOriginalSalesRepIdColumn موجوداً، نضيف userId مرة أخرى
            $params = [$userId, $month, $year];
            if ($hasOriginalSalesRepIdColumn) {
                $params = [$userId, $userId, $month, $year, $userId];
            }
            $fullPaymentSales = $db->queryOne($fullPaymentSalesSql, $params);
        }
        
        $totalCommissionBase += floatval($fullPaymentSales['total'] ?? 0);
    }
    
    // الحالة 2 و 3: التحصيلات الجزئية والتحصيلات من عملاء المندوب
    // حساب 2% على المبلغ المحصل فقط (وليس على المبلغ المخصوم من الرصيد الدائن)
    $collectionsTableCheck = $db->queryOne("SHOW TABLES LIKE 'collections'");
    if (!empty($collectionsTableCheck)) {
        // التحقق من وجود عمود status في collections
        $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
        $hasStatus = !empty($statusColumnCheck);
        
        // التحقق من وجود جدول customers
        $customersTableCheck = $db->queryOne("SHOW TABLES LIKE 'customers'");
        $hasCustomers = !empty($customersTableCheck);
        
        if ($hasCustomers && !empty($invoicesTableCheck)) {
            // الحالة 2: التحصيلات من الفواتير الجزئية (البيع بالتحصيل الجزئي)
            // التحصيلات التي تمت على فواتير بحالة partial للمندوب
            // نستخدم subquery لتجنب العد المزدوج إذا كان هناك أكثر من فاتورة جزئية للعميل نفسه
            // استبعاد التحصيلات من الفواتير المدفوعة من رصيد دائن
            // ملاحظة: التحصيلات الجزئية تُحتسب فقط على المبلغ المحصل (وليس على الرصيد الدائن)
            $hasPaidFromCreditColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'paid_from_credit'"));
            
            // التحقق من وجود عمود original_sales_rep_id
            $hasOriginalSalesRepIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'original_sales_rep_id'"));
            
            $partialCollectionsSql = "SELECT COALESCE(SUM(c.amount), 0) as total 
                 FROM collections c
                 WHERE c.customer_id IN (
                     SELECT DISTINCT inv.customer_id 
                     FROM invoices inv
                     WHERE inv.sales_rep_id = ?
                     AND inv.status = 'partial'";
            
            // استبعاد الفواتير المنقولة (التي تم نقلها من مندوب آخر)
            if ($hasOriginalSalesRepIdColumn) {
                $partialCollectionsSql .= " AND (inv.original_sales_rep_id IS NULL OR inv.original_sales_rep_id = inv.sales_rep_id)";
            }
            
            // استبعاد الفواتير المدفوعة من رصيد دائن
            if ($hasPaidFromCreditColumn) {
                $partialCollectionsSql .= " AND (inv.paid_from_credit IS NULL OR inv.paid_from_credit = 0)";
            }
            
            $partialCollectionsSql .= "
                 )
                 AND MONTH(c.date) = ?
                 AND YEAR(c.date) = ?" . 
                 ($hasStatus ? " AND c.status IN ('pending','approved')" : "");
            
            // إعداد المعاملات: userId (للـ subquery), month, year (للـ main query)
            // الاستعلام يحتاج فقط إلى 3 معاملات: userId في subquery، و month و year في main query
            $partialParams = [$userId, $month, $year];
            $partialCollections = $db->queryOne($partialCollectionsSql, $partialParams);
            $partialAmount = floatval($partialCollections['total'] ?? 0);
            
            // الحالة 3: التحصيلات من عملاء المندوب (من صفحة العملاء)
            // أي مبلغ يقوم المندوب بتحصيله من العملاء من خلال صفحة العملاء
            // نحسب جميع التحصيلات من عملاء المندوب
            // إذا كان التحصيل مؤهلاً للحالتين 2 و 3، سيتم احتسابه مرة واحدة فقط (في الحالة 2)
            // لذلك نستثني التحصيلات التي تم احتسابها في الحالة 2 (من عملاء لديهم فواتير جزئية)
            // استبعاد التحصيلات من العملاء الذين لديهم رصيد دائن وقت التحصيل
            // (لأن خصم الرصيد الدائن له معاملة خاصة: تُحسب النسبة مباشرة كـ bonus في pos.php)
            $customerCollectionsSql = "SELECT COALESCE(SUM(c.amount), 0) as total 
                 FROM collections c
                 INNER JOIN customers cust ON c.customer_id = cust.id
                 WHERE cust.created_by = ?
                 AND MONTH(c.date) = ?
                 AND YEAR(c.date) = ?
                 AND c.customer_id NOT IN (
                     SELECT DISTINCT inv.customer_id 
                     FROM invoices inv
                     WHERE inv.sales_rep_id = ?
                     AND inv.status = 'partial'";
            
            // استبعاد الفواتير المدفوعة من رصيد دائن
            if ($hasPaidFromCreditColumn) {
                $customerCollectionsSql .= " AND (inv.paid_from_credit IS NULL OR inv.paid_from_credit = 0)";
            }
            
            $customerCollectionsSql .= "
                 )
                 AND cust.balance >= 0" . // استبعاد العملاء الذين لديهم رصيد دائن
                 ($hasStatus ? " AND c.status IN ('pending','approved')" : "");
            
            $customerCollections = $db->queryOne($customerCollectionsSql, [$userId, $month, $year, $userId]);
            $customerAmount = floatval($customerCollections['total'] ?? 0);
            
            // الحالة 4: التحصيلات التي قام بها المندوب مباشرة (collected_by)
            // نستثني التحصيلات التي تم احتسابها في الحالتين 2 و 3 لتجنب العد المزدوج
            // استبعاد التحصيلات من العملاء الذين لديهم رصيد دائن
            // (لأن خصم الرصيد الدائن له معاملة خاصة)
            $collectedByQuery = "
                SELECT COALESCE(SUM(c.amount), 0) as total 
                FROM collections c
                LEFT JOIN customers cust ON c.customer_id = cust.id
                WHERE c.collected_by = ?
                AND MONTH(c.date) = ?
                AND YEAR(c.date) = ?
                AND c.customer_id NOT IN (
                    SELECT DISTINCT inv.customer_id 
                    FROM invoices inv
                    WHERE inv.sales_rep_id = ?
                    AND inv.status = 'partial'";
            
            // استبعاد الفواتير المدفوعة من رصيد دائن
            if ($hasPaidFromCreditColumn) {
                $collectedByQuery .= " AND (inv.paid_from_credit IS NULL OR inv.paid_from_credit = 0)";
            }
            
            $collectedByQuery .= "
                )
                AND (c.customer_id NOT IN (
                    SELECT DISTINCT cust2.id
                    FROM customers cust2
                    WHERE cust2.created_by = ?
                ) OR c.customer_id IS NULL)
                AND (cust.balance IS NULL OR cust.balance >= 0)" . // استبعاد العملاء الذين لديهم رصيد دائن
                ($hasStatus ? " AND c.status IN ('pending','approved')" : "");
            
            $collectedByResult = $db->queryOne($collectedByQuery, [$userId, $month, $year, $userId, $userId]);
            $collectedByAmount = floatval($collectedByResult['total'] ?? 0);
            
            $totalCommissionBase += $partialAmount + $customerAmount + $collectedByAmount;
        } elseif ($hasCustomers) {
            // إذا لم يكن جدول invoices موجوداً، نحسب التحصيلات من عملاء المندوب
            // استبعاد التحصيلات من العملاء الذين لديهم رصيد دائن
            $customerCollections = $db->queryOne(
                "SELECT COALESCE(SUM(c.amount), 0) as total 
                 FROM collections c
                 INNER JOIN customers cust ON c.customer_id = cust.id
                 WHERE cust.created_by = ?
                 AND MONTH(c.date) = ?
                 AND YEAR(c.date) = ?
                 AND cust.balance >= 0" . // استبعاد العملاء الذين لديهم رصيد دائن
                 ($hasStatus ? " AND c.status IN ('pending','approved')" : ""),
                [$userId, $month, $year]
            );
            $customerAmount = floatval($customerCollections['total'] ?? 0);
            
            // التحصيلات التي قام بها المندوب مباشرة (collected_by) من عملاء ليسوا من عملاء المندوب
            // استبعاد التحصيلات من العملاء الذين لديهم رصيد دائن
            $collectedByQuery = "
                SELECT COALESCE(SUM(c.amount), 0) as total 
                FROM collections c
                LEFT JOIN customers cust ON c.customer_id = cust.id
                WHERE c.collected_by = ?
                AND MONTH(c.date) = ?
                AND YEAR(c.date) = ?
                AND (c.customer_id NOT IN (
                    SELECT DISTINCT cust2.id
                    FROM customers cust2
                    WHERE cust2.created_by = ?
                ) OR c.customer_id IS NULL)
                AND (cust.balance IS NULL OR cust.balance >= 0)" . // استبعاد العملاء الذين لديهم رصيد دائن
                ($hasStatus ? " AND c.status IN ('pending','approved')" : "");
            
            $collectedByResult = $db->queryOne($collectedByQuery, [$userId, $month, $year, $userId]);
            $collectedByAmount = floatval($collectedByResult['total'] ?? 0);
            
            $totalCommissionBase += $customerAmount + $collectedByAmount;
        } else {
            // إذا لم يكن جدول customers موجوداً، نستخدم الطريقة القديمة
            if ($hasStatus) {
                $result = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_collections 
                     FROM collections 
                     WHERE collected_by = ? 
                     AND MONTH(date) = ? 
                     AND YEAR(date) = ?
                     AND status IN ('pending','approved')",
                    [$userId, $month, $year]
                );
            } else {
                $result = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_collections 
                     FROM collections 
                     WHERE collected_by = ? 
                     AND MONTH(date) = ? 
                     AND YEAR(date) = ?",
                    [$userId, $month, $year]
                );
            }
            $totalCommissionBase += floatval($result['total_collections'] ?? 0);
        }
    }
    
    return round($totalCommissionBase, 2);
}

/**
 * إضافة (أو خصم) مكافأة فورية بنسبة 2% على تحصيل مندوب المبيعات
 *
 * @param int $salesUserId      معرف المندوب
 * @param float $collectionAmount قيمة التحصيل
 * @param string|null $collectionDate تاريخ التحصيل (يستخدم لتحديد الشهر/السنة)
 * @param int|null $collectionId  معرف عملية التحصيل (لأغراض السجل)
 * @param int|null $triggeredBy   معرف المستخدم الذي نفّذ العملية (للتدقيق)
 * @param bool $reverse           في حالة true يتم خصم المكافأة (مثلاً عند حذف التحصيل)
 * @return bool نجاح أو فشل العملية
 */
function applyCollectionInstantReward($salesUserId, $collectionAmount, $collectionDate = null, $collectionId = null, $triggeredBy = null, $reverse = false) {
    $salesUserId = (int)$salesUserId;
    $collectionAmount = (float)$collectionAmount;
    
    if ($salesUserId <= 0 || $collectionAmount <= 0) {
        return false;
    }
    
    $collectionDate = $collectionDate ?: date('Y-m-d');
    $timestamp = strtotime($collectionDate) ?: time();
    $targetMonth = (int)date('n', $timestamp);
    $targetYear = (int)date('Y', $timestamp);
    
    $rewardAmount = round($collectionAmount * 0.02, 2);
    if ($reverse) {
        $rewardAmount *= -1;
    }
    
    if ($rewardAmount == 0.0) {
        return true;
    }
    
    $db = db();
    
    $summary = getSalarySummary($salesUserId, $targetMonth, $targetYear);
    if (!$summary['exists']) {
        $creation = createOrUpdateSalary($salesUserId, $targetMonth, $targetYear);
        if (!($creation['success'] ?? false)) {
            error_log('Instant reward: failed to ensure salary record for user ' . $salesUserId . ' (collection ' . ($collectionId ?? 'N/A') . ')');
            return false;
        }
        $summary = getSalarySummary($salesUserId, $targetMonth, $targetYear);
        if (!$summary['exists']) {
            return false;
        }
    }
    
    $salary = $summary['salary'];
    $salaryId = (int)($salary['id'] ?? 0);
    if ($salaryId <= 0) {
        return false;
    }
    
    static $salaryRewardColumns = null;
    if ($salaryRewardColumns === null) {
        $salaryRewardColumns = [
            'bonus' => null,
            'collections_bonus' => null,
            'collections_amount' => null,
            'total_amount' => null,
            'accumulated_amount' => null,
            'updated_at' => null,
        ];
        
        try {
            $columns = $db->query("SHOW COLUMNS FROM salaries");
            foreach ($columns as $column) {
                $field = $column['Field'] ?? '';
                if ($field === '') {
                    continue;
                }
                
                if ($salaryRewardColumns['bonus'] === null && in_array($field, ['bonus', 'bonuses', 'total_bonus'], true)) {
                    $salaryRewardColumns['bonus'] = $field;
                } elseif ($salaryRewardColumns['collections_bonus'] === null && $field === 'collections_bonus') {
                    $salaryRewardColumns['collections_bonus'] = $field;
                } elseif ($salaryRewardColumns['collections_amount'] === null && $field === 'collections_amount') {
                    $salaryRewardColumns['collections_amount'] = $field;
                } elseif ($salaryRewardColumns['total_amount'] === null && in_array($field, ['total_amount', 'amount', 'net_total'], true)) {
                    $salaryRewardColumns['total_amount'] = $field;
                } elseif ($salaryRewardColumns['accumulated_amount'] === null && $field === 'accumulated_amount') {
                    $salaryRewardColumns['accumulated_amount'] = $field;
                } elseif ($salaryRewardColumns['updated_at'] === null && in_array($field, ['updated_at', 'modified_at', 'last_updated'], true)) {
                    $salaryRewardColumns['updated_at'] = $field;
                }
            }
        } catch (Throwable $columnError) {
            error_log('Instant reward: failed to read salaries columns - ' . $columnError->getMessage());
        }
        
        if ($salaryRewardColumns['collections_bonus'] === null && $hasCollectionsBonusColumn) {
            $salaryRewardColumns['collections_bonus'] = 'collections_bonus';
        }
        if ($salaryRewardColumns['collections_amount'] === null && $hasCollectionsBonusColumn) {
            $salaryRewardColumns['collections_amount'] = 'collections_amount';
        }
        
        if ($salaryRewardColumns['total_amount'] === null) {
            $salaryRewardColumns['total_amount'] = 'total_amount';
        }
    }
    
    $updateParts = [];
    $params = [];
    
    // لا نضيف إلى bonus إذا كان collections_bonus موجوداً
    // لأن نسبة التحصيلات يجب أن تُضاف فقط إلى collections_bonus وليس إلى bonus
    // bonus يُستخدم للمكافآت الأخرى (غير نسبة التحصيلات)
    // if (!empty($salaryRewardColumns['bonus'])) {
    //     $updateParts[] = "{$salaryRewardColumns['bonus']} = COALESCE({$salaryRewardColumns['bonus']}, 0) + ?";
    //     $params[] = $rewardAmount;
    // }
    
    if (!empty($salaryRewardColumns['collections_bonus'])) {
        $updateParts[] = "{$salaryRewardColumns['collections_bonus']} = COALESCE({$salaryRewardColumns['collections_bonus']}, 0) + ?";
        $params[] = $rewardAmount;
    }
    
    if (!empty($salaryRewardColumns['collections_amount'])) {
        $updateParts[] = "{$salaryRewardColumns['collections_amount']} = COALESCE({$salaryRewardColumns['collections_amount']}, 0) + ?";
        $params[] = $reverse ? -abs($collectionAmount) : abs($collectionAmount);
    }
    
    if (!empty($salaryRewardColumns['total_amount'])) {
        $updateParts[] = "{$salaryRewardColumns['total_amount']} = COALESCE({$salaryRewardColumns['total_amount']}, 0) + ?";
        $params[] = $rewardAmount;
    }
    
    if (!empty($salaryRewardColumns['accumulated_amount'])) {
        $updateParts[] = "{$salaryRewardColumns['accumulated_amount']} = COALESCE({$salaryRewardColumns['accumulated_amount']}, 0) + ?";
        $params[] = $rewardAmount;
    }
    
    if (!empty($salaryRewardColumns['updated_at'])) {
        $updateParts[] = "{$salaryRewardColumns['updated_at']} = NOW()";
    }
    
    if (empty($updateParts)) {
        return false;
    }
    
    $params[] = $salaryId;
    $db->execute(
        "UPDATE salaries SET " . implode(', ', $updateParts) . " WHERE id = ?",
        $params
    );
    
    if (function_exists('logAudit')) {
        logAudit(
            $triggeredBy ?: $salesUserId,
            $rewardAmount > 0 ? 'collection_reward_add' : 'collection_reward_remove',
            'salary',
            $salaryId,
            null,
            [
                'collection_id' => $collectionId,
                'collection_amount' => $collectionAmount,
                'reward_amount' => $rewardAmount,
                'month' => $targetMonth,
                'year' => $targetYear
            ]
        );
    }
    
    return true;
}

/**
 * حساب الراتب الشهري
 * للمندوبين: يضاف 2% من مجموع التحصيلات المعتمدة
 */
function calculateSalary($userId, $month, $year, $bonus = 0, $deductions = 0) {
    $db = db();
    
    // الحصول على بيانات المستخدم
    $user = $db->queryOne("SELECT hourly_rate, role FROM users WHERE id = ?", [$userId]);
    
    if (!$user) {
        return [
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ];
    }
    
    // تنظيف hourly_rate من 262145 - تنظيف شامل
    $hourlyRateRaw = $user['hourly_rate'] ?? 0;
    $hourlyRateStr = (string)$hourlyRateRaw;
    $hourlyRateStr = str_replace('262145', '', $hourlyRateStr);
    $hourlyRateStr = preg_replace('/262145\s*/', '', $hourlyRateStr);
    $hourlyRateStr = preg_replace('/\s*262145/', '', $hourlyRateStr);
    $hourlyRateStr = preg_replace('/\s+/', '', trim($hourlyRateStr));
    $hourlyRateStr = preg_replace('/[^0-9.]/', '', $hourlyRateStr);
    $hourlyRate = cleanFinancialValue($hourlyRateStr ?: 0);
    
    $role = $user['role'];
    
    if ($hourlyRate <= 0) {
        $errorMessage = ($role === 'sales') 
            ? 'لم يتم تحديد الراتب الشهري للمندوب'
            : 'لم يتم تحديد سعر الساعة للمستخدم';
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }
    
    // حساب عدد الساعات
    $totalHours = calculateMonthlyHours($userId, $month, $year);
    
    // حساب الراتب الأساسي
    // لجميع الأدوار: الراتب الأساسي = الساعات المكتملة فقط × سعر الساعة
    // لا نحسب الراتب من الساعات غير المكتملة (حضور بدون انصراف)
    // لا يوجد راتب أساسي حتى يتم تسجيل الانصراف
    $completedHours = calculateCompletedMonthlyHours($userId, $month, $year);
    
    // الراتب الأساسي = الساعات المكتملة فقط × سعر الساعة (لجميع الأدوار)
    $baseAmount = $completedHours * $hourlyRate;
    
    // حساب نسبة التحصيلات للمندوبين (2%)
    $collectionsBonus = 0;
    $collectionsAmount = 0;
    
    if ($role === 'sales') {
        $collectionsAmount = calculateSalesCollections($userId, $month, $year);
        $collectionsBonus = $collectionsAmount * 0.02; // 2%
    }
    
    // إضافة نسبة التحصيلات إلى المكافأة
    $totalBonus = $bonus + $collectionsBonus;
    
    // حساب السلف المعتمدة التي لم يتم خصمها بعد
    $advancesDeduction = 0;
    $advancesTableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_advances'");
    if (!empty($advancesTableCheck)) {
        $approvedAdvances = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM salary_advances 
             WHERE user_id = ? 
             AND status = 'manager_approved' 
             AND deducted_from_salary_id IS NULL",
            [$userId]
        );
        $advancesDeduction = floatval($approvedAdvances['total'] ?? 0);
    }
    
    // حساب الراتب الإجمالي (مع خصم السلف)
    $totalAmount = $baseAmount + $totalBonus - $deductions - $advancesDeduction;
    
    return [
        'success' => true,
        'hourly_rate' => $hourlyRate,
        'total_hours' => $totalHours,
        'base_amount' => round($baseAmount, 2),
        'collections_amount' => $collectionsAmount,
        'collections_bonus' => round($collectionsBonus, 2),
        'bonus' => $bonus,
        'total_bonus' => round($totalBonus, 2),
        'deductions' => $deductions,
        'advances_deduction' => round($advancesDeduction, 2),
        'total_amount' => round($totalAmount, 2)
    ];
}

/**
 * إنشاء أو تحديث راتب للمستخدم
 */
function createOrUpdateSalary($userId, $month, $year, $bonus = 0, $deductions = 0, $notes = null) {
    // التحقق من صحة المعاملات
    $userId = (int)$userId;
    $month = (int)$month;
    $year = (int)$year;
    
    // التحقق الصارم من القيم - منع التواريخ الصفرية والقيم غير الصحيحة
    if ($userId <= 0) {
        error_log("createOrUpdateSalary: Invalid user_id: {$userId}");
        return [
            'success' => false,
            'message' => 'معرف المستخدم غير صالح'
        ];
    }
    
    // تصحيح الشهر إذا كان غير صحيح - استخدام الشهر الحالي كبديل
    if ($month < 1 || $month > 12) {
        error_log("createOrUpdateSalary: Invalid month: {$month} for user: {$userId}");
        $month = (int)date('n');
        error_log("createOrUpdateSalary: Using current month: {$month}");
    }
    
    // تصحيح السنة إذا كانت غير صحيحة - استخدام السنة الحالية كبديل
    if ($year < 2000 || $year > 2100) {
        error_log("createOrUpdateSalary: Invalid year: {$year} for user: {$userId}");
        $year = (int)date('Y');
        error_log("createOrUpdateSalary: Using current year: {$year}");
    }
    
    // إنشاء تاريخ صحيح للشهر (أول يوم من الشهر) - مهم جداً لمنع التواريخ الصفرية
    $targetDate = sprintf('%04d-%02d-01', $year, $month);
    $targetYearMonth = sprintf('%04d-%02d', $year, $month);
    
    error_log("createOrUpdateSalary: Processing user {$userId} for month {$month}/{$year} (targetDate: {$targetDate})");
    
    $db = db();
    $hasCollectionsBonusColumn = ensureCollectionsBonusColumn();
    
    // الحصول على المستخدم الحالي لاستخدامه في created_by
    $currentUser = getCurrentUser();
    $createdBy = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
    
    // التحقق من وجود عمود created_by
    $createdByColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'created_by'");
    $hasCreatedByColumn = !empty($createdByColumnCheck);
    
    // إذا كان created_by موجوداً ولكن القيمة null، استخدم user_id كبديل
    if ($hasCreatedByColumn && $createdBy === null) {
        $createdBy = $userId;
    }
    
    // التحقق من نوع عمود month أولاً - هذا مهم جداً
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    $isMonthDate = stripos($monthType, 'date') !== false;
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    error_log("createOrUpdateSalary: monthType={$monthType}, isMonthDate=" . ($isMonthDate ? 'true' : 'false') . ", hasYearColumn=" . ($hasYearColumn ? 'true' : 'false'));
    
    // التحقق من وجود عمود bonus - بشكل آمن
    // بشكل افتراضي، افترض أن العمود غير موجود لتجنب الأخطاء
    $hasBonusColumn = false;
    try {
        // محاولة التحقق من وجود العمود باستخدام INFORMATION_SCHEMA
        $columnExists = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'salaries' 
             AND COLUMN_NAME = 'bonus'"
        );
        // تأكد من أن القيمة أكبر من 0 وصحيحة
        if (!empty($columnExists) && isset($columnExists['cnt'])) {
            $hasBonusColumn = (int)$columnExists['cnt'] > 0;
        }
    } catch (Exception $e) {
        // إذا فشل، جرب طريقة بديلة
        try {
            $bonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'bonus'");
            if (!empty($bonusColumnCheck) && isset($bonusColumnCheck['Field']) && $bonusColumnCheck['Field'] === 'bonus') {
                $hasBonusColumn = true;
            }
        } catch (Exception $e2) {
            // في حالة الخطأ، ابق القيمة false
            $hasBonusColumn = false;
        }
    }
    
    // تأكد نهائي - إذا لم نكن متأكدين، افترض false
    if (!$hasBonusColumn) {
        $hasBonusColumn = false;
    }

    // التحقق من وجود عمود notes
    $hasNotesColumn = false;
    try {
        $columnExists = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'salaries' 
             AND COLUMN_NAME = 'notes'"
        );
        if (!empty($columnExists) && isset($columnExists['cnt'])) {
            $hasNotesColumn = (int)$columnExists['cnt'] > 0;
        }
    } catch (Exception $e) {
        try {
            $notesColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'notes'");
            if (!empty($notesColumnCheck) && isset($notesColumnCheck['Field']) && $notesColumnCheck['Field'] === 'notes') {
                $hasNotesColumn = true;
            }
        } catch (Exception $e2) {
            $hasNotesColumn = false;
        }
    }
    if (!$hasNotesColumn) {
        $hasNotesColumn = false;
    }
    
    // التحقق من وجود عمود updated_at
    $hasUpdatedAtColumn = false;
    try {
        $columnExists = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'salaries' 
             AND COLUMN_NAME = 'updated_at'"
        );
        if (!empty($columnExists) && isset($columnExists['cnt'])) {
            $hasUpdatedAtColumn = (int)$columnExists['cnt'] > 0;
        }
    } catch (Exception $e) {
        try {
            $updatedAtColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'updated_at'");
            if (!empty($updatedAtColumnCheck) && isset($updatedAtColumnCheck['Field']) && $updatedAtColumnCheck['Field'] === 'updated_at') {
                $hasUpdatedAtColumn = true;
            }
        } catch (Exception $e2) {
            $hasUpdatedAtColumn = false;
        }
    }
    if (!$hasUpdatedAtColumn) {
        $hasUpdatedAtColumn = false;
    }
    
    // التحقق من وجود راتب موجود - تحقق شامل لمنع التكرار
    // نتحقق من جميع السجلات الموجودة للمستخدم في نفس الشهر/السنة أو بتواريخ صفرية
    $existingSalary = null;
    
    // البحث بناءً على نوع عمود month - هذا هو المنطق الصحيح
    if ($isMonthDate) {
        // عمود month من نوع DATE - البحث باستخدام DATE_FORMAT
        error_log("createOrUpdateSalary: Searching for existing salary with DATE type month column");
        
        // 1. البحث عن راتب موجود بنفس الشهر والسنة
        $existingSalary = $db->queryOne(
            "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ? AND month != '0000-00-00' AND month IS NOT NULL LIMIT 1",
            [$userId, $targetYearMonth]
        );
        
        // 2. البحث عن سجلات بتواريخ صفرية لنفس المستخدم وتحديثها
        if (empty($existingSalary)) {
            $zeroDateSalary = $db->queryOne(
                "SELECT id FROM salaries WHERE user_id = ? AND (month IS NULL OR month = '0000-00-00' OR month = '1970-01-01' OR YEAR(month) < 2000 OR YEAR(month) > 2100) ORDER BY base_amount DESC, id DESC LIMIT 1",
                [$userId]
            );
            if (!empty($zeroDateSalary)) {
                $existingSalary = $zeroDateSalary;
                // تحديث التاريخ للسجل الصفري
                try {
                    $db->execute(
                        "UPDATE salaries SET month = ? WHERE id = ?",
                        [$targetDate, $existingSalary['id']]
                    );
                    error_log("Fixed zero-date salary record ID: {$existingSalary['id']} for user: {$userId}, set to {$targetDate}");
                } catch (Exception $e) {
                    error_log("Failed to fix zero-date salary ID {$existingSalary['id']}: " . $e->getMessage());
                    $existingSalary = null;
                }
            }
        }
    } elseif ($hasYearColumn) {
        // عمود month من نوع INT مع وجود عمود year منفصل
        error_log("createOrUpdateSalary: Searching for existing salary with INT month and separate year column");
        
        // 1. البحث عن راتب موجود بنفس الشهر والسنة
        $existingSalary = $db->queryOne(
            "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ? LIMIT 1",
            [$userId, $month, $year]
        );
        
        // 2. البحث عن سجلات بتواريخ صفرية لنفس المستخدم
        if (empty($existingSalary)) {
            $zeroDateSalary = $db->queryOne(
                "SELECT id FROM salaries WHERE user_id = ? AND (
                    year IS NULL OR year = 0 OR year < 2000 OR year > 2100 OR
                    month IS NULL OR month = 0 OR month < 1 OR month > 12
                ) ORDER BY base_amount DESC, id DESC LIMIT 1",
                [$userId]
            );
            
            if (!empty($zeroDateSalary)) {
                $existingSalary = $zeroDateSalary;
                try {
                    $db->execute(
                        "UPDATE salaries SET month = ?, year = ? WHERE id = ?",
                        [$month, $year, $existingSalary['id']]
                    );
                    error_log("Fixed zero-date salary record ID: {$existingSalary['id']} for user: {$userId}, set to {$month}/{$year}");
                } catch (Exception $e) {
                    error_log("Failed to fix zero-date salary ID {$existingSalary['id']}: " . $e->getMessage());
                    $existingSalary = null;
                }
            }
        }
    } else {
        // عمود month من نوع INT بدون عمود year منفصل
        error_log("createOrUpdateSalary: Searching for existing salary with INT month only (no year column)");
        
        $existingSalary = $db->queryOne(
            "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND month >= 1 AND month <= 12 LIMIT 1",
            [$userId, $month]
        );
        
        // البحث عن سجلات بتواريخ صفرية
        if (empty($existingSalary)) {
            $zeroDateSalary = $db->queryOne(
                "SELECT id FROM salaries WHERE user_id = ? AND (month IS NULL OR month = 0 OR month < 1 OR month > 12) ORDER BY base_amount DESC, id DESC LIMIT 1",
                [$userId]
            );
            if (!empty($zeroDateSalary)) {
                $existingSalary = $zeroDateSalary;
                try {
                    $db->execute(
                        "UPDATE salaries SET month = ? WHERE id = ?",
                        [$month, $existingSalary['id']]
                    );
                    error_log("Fixed zero-date salary record ID: {$existingSalary['id']} for user: {$userId}, set month to: {$month}");
                } catch (Exception $e) {
                    error_log("Failed to fix zero-date salary ID {$existingSalary['id']}: " . $e->getMessage());
                    $existingSalary = null;
                }
            }
        }
    }
    
    error_log("createOrUpdateSalary: existingSalary = " . ($existingSalary ? "ID: {$existingSalary['id']}" : "null"));
    
    // التحقق من وجود عمود accumulated_amount
    $accumulatedColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'accumulated_amount'");
    $hasAccumulatedColumn = !empty($accumulatedColumnCheck);
    
    // قراءة الخصومات الموجودة من قاعدة البيانات أولاً (إذا كان الراتب موجوداً)
    // هذا يضمن أن الخصومات تبقى ثابتة ولا تتغير عند إعادة حساب الراتب
    $existingDeductions = 0;
    if ($existingSalary) {
        $existingDeductionsQuery = "SELECT deductions FROM salaries WHERE id = ?";
        $existingDeductionsData = $db->queryOne($existingDeductionsQuery, [$existingSalary['id']]);
        $existingDeductions = floatval($existingDeductionsData['deductions'] ?? 0);
    }
    
    // إذا كان $deductions الممرر = 0 وكانت هناك خصومات موجودة، استخدم الخصومات الموجودة
    // هذا يضمن أن الخصومات تبقى ثابتة ولا يتم تصفيرها عند إعادة حساب الراتب
    if ($deductions == 0 && $existingDeductions > 0) {
        $deductions = $existingDeductions;
    }
    
    // حساب الراتب (بعد قراءة الخصومات)
    $calculation = calculateSalary($userId, $month, $year, $bonus, $deductions);
    $collectionsBonusCalc = cleanFinancialValue($calculation['collections_bonus'] ?? 0);
    $collectionsAmountCalc = cleanFinancialValue($calculation['collections_amount'] ?? ($collectionsBonusCalc > 0 ? $collectionsBonusCalc / 0.02 : 0));
    
    // إضافة المبالغ المدفوعة من الرصيد الدائن إلى collections_bonus
    // هذه المبالغ تُحسب مباشرة في pos.php وتُضاف إلى collections_bonus
    // لذا يجب أن نضيفها إلى الحساب هنا أيضاً لتجنب فقدانها عند إعادة الحساب
    $creditBalanceCommission = 0;
    $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
    if ($user && strtolower((string)($user['role'] ?? '')) === 'sales') {
        try {
            $invoicesTableCheck = $db->queryOne("SHOW TABLES LIKE 'invoices'");
            if (!empty($invoicesTableCheck)) {
                $hasPaidFromCreditColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'paid_from_credit'"));
                $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
                
                if ($hasPaidFromCreditColumn) {
                    // التحقق من وجود عمود original_sales_rep_id
                    $hasOriginalSalesRepIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'original_sales_rep_id'"));
                    
                    // حساب إجمالي المبالغ المدفوعة من الرصيد الدائن (paid_from_credit > 0)
                    $creditUsedSql = "SELECT COALESCE(SUM(paid_from_credit), 0) as total_credit_used
                         FROM invoices
                         WHERE sales_rep_id = ?
                         AND MONTH(date) = ?
                         AND YEAR(date) = ?
                         AND (paid_from_credit IS NOT NULL AND paid_from_credit > 0)";
                    
                    // استبعاد الفواتير المنقولة
                    if ($hasOriginalSalesRepIdColumn) {
                        $creditUsedSql .= " AND (original_sales_rep_id IS NULL OR original_sales_rep_id = sales_rep_id)";
                    }
                    
                    $creditUsedResult = $db->queryOne($creditUsedSql, [$userId, $month, $year]);
                    $totalCreditUsed = floatval($creditUsedResult['total_credit_used'] ?? 0);
                } elseif ($hasCreditUsedColumn) {
                    // التحقق من وجود عمود original_sales_rep_id
                    $hasOriginalSalesRepIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'original_sales_rep_id'"));
                    
                    // استخدام credit_used كبديل إذا لم يكن paid_from_credit موجوداً
                    $creditUsedSql = "SELECT COALESCE(SUM(credit_used), 0) as total_credit_used
                         FROM invoices
                         WHERE sales_rep_id = ?
                         AND MONTH(date) = ?
                         AND YEAR(date) = ?
                         AND (credit_used IS NOT NULL AND credit_used > 0)";
                    
                    // استبعاد الفواتير المنقولة
                    if ($hasOriginalSalesRepIdColumn) {
                        $creditUsedSql .= " AND (original_sales_rep_id IS NULL OR original_sales_rep_id = sales_rep_id)";
                    }
                    
                    $creditUsedResult = $db->queryOne($creditUsedSql, [$userId, $month, $year]);
                    $totalCreditUsed = floatval($creditUsedResult['total_credit_used'] ?? 0);
                } else {
                    $totalCreditUsed = 0;
                }
                
                // حساب نسبة 2% من المبالغ المدفوعة من الرصيد الدائن
                // هذه النسبة تُضاف مباشرة في pos.php، لذا يجب أن نضيفها هنا أيضاً
                $creditBalanceCommission = round($totalCreditUsed * 0.02, 2);
                
                if ($creditBalanceCommission > 0) {
                    error_log(sprintf(
                        'Adding credit balance commission to collections_bonus: userId=%d, month=%d, year=%d, totalCreditUsed=%.2f, commission=%.2f',
                        $userId, $month, $year, $totalCreditUsed, $creditBalanceCommission
                    ));
                }
            }
        } catch (Throwable $creditCommissionError) {
            error_log('Error calculating credit balance commission: ' . $creditCommissionError->getMessage());
        }
    }
    
    // إضافة نسبة المبالغ المدفوعة من الرصيد الدائن إلى collections_bonus
    $collectionsBonusCalc = $collectionsBonusCalc + $creditBalanceCommission;
    
    if (!$calculation['success']) {
        return $calculation;
    }
    
    if ($existingSalary) {
        // تحديث الراتب الموجود
        // الحصول على المبلغ التراكمي الحالي والسلفات المخصومة والخصومات الموجودة
        $currentAccumulated = 0.00;
        $hasDeductedAdvances = false;
        $currentTotalAmount = 0.00;
        $currentDeductions = 0.00;
        $existingCollectionsBonus = 0;
        $existingCollectionsAmount = 0;
        $existingCollectionsAmount = 0;
        
        if ($hasAccumulatedColumn) {
            // قراءة جميع البيانات الحالية بما في ذلك collections_bonus للمحافظة على المكافآت الفورية
            $currentSalaryQuery = "SELECT accumulated_amount, total_amount, advances_deduction, deductions, paid_amount";
            if ($hasCollectionsBonusColumn) {
                $currentSalaryQuery .= ", collections_bonus, collections_amount";
            }
            $currentSalaryQuery .= " FROM salaries WHERE id = ?";
            $currentSalary = $db->queryOne($currentSalaryQuery, [$existingSalary['id']]);
            $currentAccumulated = floatval($currentSalary['accumulated_amount'] ?? 0);
            $currentTotalAmount = floatval($currentSalary['total_amount'] ?? 0);
            $currentPaidAmount = floatval($currentSalary['paid_amount'] ?? 0);
            $currentAdvancesDeduction = floatval($currentSalary['advances_deduction'] ?? 0);
            $currentDeductions = floatval($currentSalary['deductions'] ?? 0);
            
                // قراءة collections_bonus الحالي (للرجوع إليه فقط)
                $existingCollectionsBonus = 0;
                if ($hasCollectionsBonusColumn) {
                    $existingCollectionsBonus = floatval($currentSalary['collections_bonus'] ?? 0);
                    // collections_bonus يجب أن يكون دائماً = القيمة المحسوبة من جميع التحصيلات في الشهر
                    // لكن يجب أن نستخدم القيمة الأكبر بين القيمة الموجودة والقيمة المحسوبة
                    // لأن القيمة الموجودة قد تحتوي على مبالغ مضافة مسبقاً من المبالغ المدفوعة من الرصيد الدائن
                    // التي تُضاف مباشرة في pos.php قبل استدعاء refreshSalesCommissionForUser
                    $calculatedCollectionsBonus = round($collectionsBonusCalc, 2);
                    // استخدام القيمة الأكبر لتجنب فقدان المبالغ المضافة مسبقاً
                    $collectionsBonusCalc = max($existingCollectionsBonus, $calculatedCollectionsBonus);
                    $calculation['collections_bonus'] = $collectionsBonusCalc;
                    $calculation['total_bonus'] = $calculation['bonus'] + $collectionsBonusCalc;
                
                // تسجيل لأغراض التتبع
                error_log(sprintf(
                    'Collections bonus for user %d: existing=%.2f, calculated=%.2f, final=%.2f',
                    $userId,
                    $existingCollectionsBonus,
                    $calculatedCollectionsBonus,
                    $collectionsBonusCalc
                ));
            }
            
            // التحقق من وجود سلفات مخصومة بالفعل
            if ($currentAdvancesDeduction > 0) {
                $hasDeductedAdvances = true;
            } else {
                // التحقق من وجود سلفات مخصومة في جدول salary_advances
                $deductedAdvancesCheck = $db->queryOne(
                    "SELECT COUNT(*) as count FROM salary_advances 
                     WHERE deducted_from_salary_id = ? AND status = 'manager_approved'",
                    [$existingSalary['id']]
                );
                if (!empty($deductedAdvancesCheck) && intval($deductedAdvancesCheck['count'] ?? 0) > 0) {
                    $hasDeductedAdvances = true;
                }
            }
            
            // حساب المبلغ التراكمي الجديد = total_amount الجديد - paid_amount الحالي
            // المبلغ التراكمي = المتبقي من الراتب الحالي فقط
            $currentAccumulated = max(0, $calculation['total_amount'] - $currentPaidAmount);
        } else {
            // إذا لم يكن هناك عمود accumulated_amount، احصل على total_amount الحالي والخصومات
            // قراءة جميع البيانات الحالية بما في ذلك collections_bonus للمحافظة على المكافآت الفورية
            $currentSalaryQuery = "SELECT total_amount, advances_deduction, deductions";
            if ($hasCollectionsBonusColumn) {
                $currentSalaryQuery .= ", collections_bonus, collections_amount";
            }
            $currentSalaryQuery .= " FROM salaries WHERE id = ?";
            $currentSalary = $db->queryOne($currentSalaryQuery, [$existingSalary['id']]);
            $currentTotalAmount = floatval($currentSalary['total_amount'] ?? 0);
            $currentAdvancesDeduction = floatval($currentSalary['advances_deduction'] ?? 0);
            $currentDeductions = floatval($currentSalary['deductions'] ?? 0);
            
            // قراءة collections_bonus الحالي (للرجوع إليه فقط)
            if ($hasCollectionsBonusColumn) {
                $existingCollectionsBonus = floatval($currentSalary['collections_bonus'] ?? 0);
                // collections_bonus يجب أن يكون دائماً = القيمة المحسوبة من جميع التحصيلات في الشهر
                // لكن يجب أن نستخدم القيمة الأكبر بين القيمة الموجودة والقيمة المحسوبة
                // لأن القيمة الموجودة قد تحتوي على مبالغ مضافة مسبقاً من المبالغ المدفوعة من الرصيد الدائن
                // التي تُضاف مباشرة في pos.php قبل استدعاء refreshSalesCommissionForUser
                $calculatedCollectionsBonus = round($collectionsBonusCalc, 2);
                // استخدام القيمة الأكبر لتجنب فقدان المبالغ المضافة مسبقاً
                $collectionsBonusCalc = max($existingCollectionsBonus, $calculatedCollectionsBonus);
                $calculation['collections_bonus'] = $collectionsBonusCalc;
                $calculation['total_bonus'] = $calculation['bonus'] + $collectionsBonusCalc;
                
                // تسجيل لأغراض التتبع
                error_log(sprintf(
                    'Collections bonus for user %d: existing=%.2f, calculated=%.2f, final=%.2f',
                    $userId,
                    $existingCollectionsBonus,
                    $calculatedCollectionsBonus,
                    $collectionsBonusCalc
                ));
            }
            
            // التحقق من وجود سلفات مخصومة بالفعل
            if ($currentAdvancesDeduction > 0) {
                $hasDeductedAdvances = true;
            } else {
                // التحقق من وجود سلفات مخصومة في جدول salary_advances
                $deductedAdvancesCheck = $db->queryOne(
                    "SELECT COUNT(*) as count FROM salary_advances 
                     WHERE deducted_from_salary_id = ? AND status = 'manager_approved'",
                    [$existingSalary['id']]
                );
                if (!empty($deductedAdvancesCheck) && intval($deductedAdvancesCheck['count'] ?? 0) > 0) {
                    $hasDeductedAdvances = true;
                }
            }
        }
        
        // الحصول على إجمالي السلفات المخصومة (إذا كانت موجودة) لاستخدامها لاحقاً
        $deductedAdvancesTotal = 0;
        if ($hasDeductedAdvances) {
            if ($currentAdvancesDeduction > 0) {
                $deductedAdvancesTotal = $currentAdvancesDeduction;
            } else {
                $deductedAdvancesQuery = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total FROM salary_advances 
                     WHERE deducted_from_salary_id = ? AND status = 'manager_approved'",
                    [$existingSalary['id']]
                );
                $deductedAdvancesTotal = floatval($deductedAdvancesQuery['total'] ?? 0);
            }
        }
        
        // ===== إصلاح جذري: الحفاظ على الخصومات بشكل مطلق =====
        // الخصومات تم قراءتها من قاعدة البيانات واستخدامها في calculateSalary
        // يجب أن تبقى ثابتة ولا تتغير أبداً إلا بتعديل يدوي من المدير/المحاسب
        // لذلك نستخدم القيمة من قاعدة البيانات مباشرة
        $calculation['deductions'] = $existingDeductions;
        
        // إعادة حساب total_amount بناءً على الخصومات الثابتة
        // total_amount = base_amount + total_bonus - deductions - advances_deduction
        $calculation['total_amount'] = round(
            $calculation['base_amount'] + 
            $calculation['total_bonus'] - 
            $calculation['deductions'] - 
            $calculation['advances_deduction'], 
            2
        );
        
        // تسجيل لأغراض التتبع
        error_log(sprintf(
            'Salary calculation for user %d: base=%.2f, bonus=%.2f, collections_bonus=%.2f, deductions=%.2f (preserved from DB), advances=%.2f, total=%.2f',
            $userId,
            $calculation['base_amount'],
            $calculation['bonus'],
            $calculation['collections_bonus'],
            $calculation['deductions'],
            $calculation['advances_deduction'],
            $calculation['total_amount']
        ));
        
        if ($hasBonusColumn) {
            if ($hasNotesColumn) {
                if ($hasAccumulatedColumn) {
                    $updateFields = [
                        'hourly_rate = ?',
                        'total_hours = ?',
                        'base_amount = ?',
                        'bonus = ?',
                        'deductions = ?',
                        'total_amount = ?',
                        'accumulated_amount = ?',
                        'notes = ?'
                    ];
                    $updateParams = [
                        $calculation['hourly_rate'],
                        $calculation['total_hours'],
                        $calculation['base_amount'],
                        $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                        $calculation['deductions'],
                        $calculation['total_amount'],
                        $currentAccumulated,
                        $notes
                    ];
                    if ($hasUpdatedAtColumn) {
                        $updateFields[] = 'updated_at = NOW()';
                    }
                    $updateParams[] = $existingSalary['id'];
                    $db->execute(
                        "UPDATE salaries SET " . implode(', ', $updateFields) . " WHERE id = ?",
                        $updateParams
                    );
                } else {
                    $updateFields = [
                        'hourly_rate = ?',
                        'total_hours = ?',
                        'base_amount = ?',
                        'bonus = ?',
                        'deductions = ?',
                        'total_amount = ?',
                        'notes = ?'
                    ];
                    $updateParams = [
                        $calculation['hourly_rate'],
                        $calculation['total_hours'],
                        $calculation['base_amount'],
                        $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                        $calculation['deductions'],
                        $calculation['total_amount'],
                        $notes
                    ];
                    if ($hasUpdatedAtColumn) {
                        $updateFields[] = 'updated_at = NOW()';
                    }
                    $updateParams[] = $existingSalary['id'];
                    $db->execute(
                        "UPDATE salaries SET " . implode(', ', $updateFields) . " WHERE id = ?",
                        $updateParams
                    );
                }
            } else {
                $updateFields = [
                    'hourly_rate = ?',
                    'total_hours = ?',
                    'base_amount = ?',
                    'bonus = ?',
                    'deductions = ?',
                    'total_amount = ?'
                ];
                $updateParams = [
                    $calculation['hourly_rate'],
                    $calculation['total_hours'],
                    $calculation['base_amount'],
                    $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                    $calculation['deductions'],
                    $calculation['total_amount']
                ];
                if ($hasUpdatedAtColumn) {
                    $updateFields[] = 'updated_at = NOW()';
                }
                $updateParams[] = $existingSalary['id'];
                $db->execute(
                    "UPDATE salaries SET " . implode(', ', $updateFields) . " WHERE id = ?",
                    $updateParams
                );
            }
        } else {
            if ($hasNotesColumn) {
                $updateFields = [
                    'hourly_rate = ?',
                    'total_hours = ?',
                    'base_amount = ?',
                    'deductions = ?',
                    'total_amount = ?',
                    'notes = ?'
                ];
                $updateParams = [
                    $calculation['hourly_rate'],
                    $calculation['total_hours'],
                    $calculation['base_amount'],
                    $calculation['deductions'],
                    $calculation['total_amount'],
                    $notes
                ];
                if ($hasUpdatedAtColumn) {
                    $updateFields[] = 'updated_at = NOW()';
                }
                $updateParams[] = $existingSalary['id'];
                $db->execute(
                    "UPDATE salaries SET " . implode(', ', $updateFields) . " WHERE id = ?",
                    $updateParams
                );
            } else {
                $updateFields = [
                    'hourly_rate = ?',
                    'total_hours = ?',
                    'base_amount = ?',
                    'deductions = ?',
                    'total_amount = ?'
                ];
                $updateParams = [
                    $calculation['hourly_rate'],
                    $calculation['total_hours'],
                    $calculation['base_amount'],
                    $calculation['deductions'],
                    $calculation['total_amount']
                ];
                if ($hasUpdatedAtColumn) {
                    $updateFields[] = 'updated_at = NOW()';
                }
                $updateParams[] = $existingSalary['id'];
                $db->execute(
                    "UPDATE salaries SET " . implode(', ', $updateFields) . " WHERE id = ?",
                    $updateParams
                );
            }
        }
        
        if ($hasCollectionsBonusColumn) {
            try {
                // قراءة collections_bonus الحالي (إذا لم يكن قد تم قراءته بالفعل)
                if (!isset($existingCollectionsBonus)) {
                    $currentSalaryBonus = $db->queryOne(
                        "SELECT collections_bonus, collections_amount FROM salaries WHERE id = ?", 
                        [$existingSalary['id']]
                    );
                    $existingCollectionsBonus = floatval($currentSalaryBonus['collections_bonus'] ?? 0);
                    $existingCollectionsAmount = floatval($currentSalaryBonus['collections_amount'] ?? 0);
                }
                
                // collections_bonus يجب أن يكون دائماً = القيمة المحسوبة من جميع التحصيلات في الشهر
                // لكن يجب أن نستخدم القيمة الأكبر بين القيمة الموجودة والقيمة المحسوبة
                // لأن القيمة الموجودة قد تحتوي على مبالغ مضافة مسبقاً من المبالغ المدفوعة من الرصيد الدائن
                // التي تُضاف مباشرة في pos.php قبل استدعاء refreshSalesCommissionForUser
                $calculatedCollectionsBonus = round($collectionsBonusCalc, 2);
                $calculatedCollectionsAmount = round($collectionsAmountCalc, 2);
                // استخدام القيمة الأكبر لتجنب فقدان المبالغ المضافة مسبقاً
                $finalCollectionsBonus = max($existingCollectionsBonus, $calculatedCollectionsBonus);
                $finalCollectionsAmount = $calculatedCollectionsAmount;
                
                // تحديث فقط إذا كانت القيمة مختلفة
                if (abs($finalCollectionsBonus - $existingCollectionsBonus) > 0.01 || 
                    abs($finalCollectionsAmount - $existingCollectionsAmount) > 0.01) {
                    // حساب الفرق في collections_bonus لتحديث total_amount
                    $collectionsBonusDiff = $finalCollectionsBonus - $existingCollectionsBonus;
                    
                    // تحديث collections_bonus و collections_amount
                    $db->execute(
                        "UPDATE salaries SET collections_bonus = ?, collections_amount = ? WHERE id = ?",
                        [$finalCollectionsBonus, $finalCollectionsAmount, $existingSalary['id']]
                    );
                    
                    // تحديث total_amount ليعكس الفرق في collections_bonus
                    if (abs($collectionsBonusDiff) > 0.01) {
                        $db->execute(
                            "UPDATE salaries SET total_amount = COALESCE(total_amount, 0) + ? WHERE id = ?",
                            [$collectionsBonusDiff, $existingSalary['id']]
                        );
                    }
                }
            } catch (Throwable $collectionsBonusError) {
                error_log('Failed to update collections bonus columns (existing salary): ' . $collectionsBonusError->getMessage());
            }
        }
        
        return [
            'success' => true,
            'message' => 'تم تحديث الراتب بنجاح',
            'salary_id' => $existingSalary['id'],
            'calculation' => $calculation
        ];
    } else {
        // إنشاء راتب جديد
        // حساب المبلغ التراكمي من المتبقي من الشهر السابق (accumulated - paid)
        $previousAccumulatedAmount = 0;
        if ($hasAccumulatedColumn) {
            // حساب المتبقي من الرواتب السابقة فقط (بدون الراتب الحالي)
            $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
            $hasYearColumn = !empty($yearColumnCheck);
            
            // جلب الرواتب السابقة
            if ($hasYearColumn) {
                $previousSalaries = $db->query(
                    "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                            COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                     FROM salaries s
                     WHERE s.user_id = ? 
                     AND s.year IS NOT NULL AND s.month IS NOT NULL
                     AND (s.year < ? OR (s.year = ? AND s.month < ?))
                     ORDER BY s.year ASC, s.month ASC",
                    [$userId, $year, $year, $month]
                );
            } else {
                // التحقق من نوع month
                $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
                $monthType = $monthColumnCheck['Type'] ?? '';
                $isMonthDate = stripos($monthType, 'date') !== false;
                
                if ($isMonthDate) {
                    $targetDate = sprintf('%04d-%02d-01', $year, $month);
                    $previousSalaries = $db->query(
                        "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                                COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                         FROM salaries s
                         WHERE s.user_id = ? 
                         AND s.month IS NOT NULL 
                         AND s.month != '0000-00-00' 
                         AND s.month != '1970-01-01'
                         AND s.month < ?
                         ORDER BY s.month ASC",
                        [$userId, $targetDate]
                    );
                } else {
                    $previousSalaries = $db->query(
                        "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                                COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                         FROM salaries s
                         WHERE s.user_id = ? 
                         AND s.month IS NOT NULL
                         AND s.month < ?
                         ORDER BY s.month ASC",
                        [$userId, $month]
                    );
                }
            }
            
            // جمع المتبقي من الرواتب السابقة
            foreach ($previousSalaries as $prevSalary) {
                $prevAccumulated = cleanFinancialValue($prevSalary['prev_accumulated'] ?? $prevSalary['total_amount'] ?? 0);
                $prevPaid = cleanFinancialValue($prevSalary['paid_amount'] ?? 0);
                
                // حساب المتبقي من الراتب السابق
                $prevRemaining = max(0, $prevAccumulated - $prevPaid);
                
                // إضافة المتبقي إلى المبلغ التراكمي فقط إذا كان هناك متبقي
                if ($prevRemaining > 0.01) {
                    $previousAccumulatedAmount += $prevRemaining;
                }
            }
            
            // إضافة المبلغ التراكمي للراتب الإجمالي
            $calculation['total_amount'] = round($calculation['total_amount'] + $previousAccumulatedAmount, 2);
        }
        
        // تحقق نهائي قبل الإدراج مع LOCK لمنع race conditions
        // استخدام SELECT FOR UPDATE للحصول على lock على السجلات المطابقة
        // هذا مهم جداً لمنع إنشاء رواتب مكررة في حالة الاستدعاءات المتزامنة
        if (empty($existingSalary)) {
            try {
                // بدء transaction
                if (!$db->inTransaction()) {
                    $db->beginTransaction();
                }
                
                // SELECT FOR UPDATE بناءً على نوع عمود month
                // هذا يحصل على lock على السجلات المطابقة ويمنع أي عملية أخرى من إنشاء راتب مكرر
                if ($isMonthDate) {
                    $finalCheck = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ? AND month != '0000-00-00' AND month IS NOT NULL LIMIT 1 FOR UPDATE",
                        [$userId, $targetYearMonth]
                    );
                } elseif ($hasYearColumn) {
                    $finalCheck = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ? AND month > 0 AND year > 0 LIMIT 1 FOR UPDATE",
                        [$userId, $month, $year]
                    );
                } else {
                    $finalCheck = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND month > 0 AND month <= 12 LIMIT 1 FOR UPDATE",
                        [$userId, $month]
                    );
                }
                
                if (!empty($finalCheck)) {
                    $existingSalary = $finalCheck;
                    error_log("Final duplicate check with LOCK: Found existing salary ID: {$existingSalary['id']} for user: {$userId}, month: {$month}, year: {$year}");
                    
                    // إذا وُجد راتب موجود، نرجع بدون إنشاء راتب جديد
                    // سنقوم بتحديث الراتب الموجود بدلاً من إنشاء راتب جديد
                    if ($db->inTransaction()) {
                        $db->commit();
                    }
                } else {
                    // لا يوجد راتب موجود - نستمر في إنشاء راتب جديد
                    // سنقوم بالـ commit بعد إدراج الراتب
                    // لا نغلق transaction هنا - سنغلقها بعد إدراج الراتب
                }
            } catch (Exception $e) {
                try {
                    if ($db->inTransaction()) {
                        $db->rollback();
                    }
                } catch (Exception $rollbackEx) {
                    error_log("Rollback failed: " . $rollbackEx->getMessage());
                }
                error_log("Error in final duplicate check with lock: " . $e->getMessage());
                // في حالة الخطأ، نستمر في محاولة إنشاء الراتب (ON DUPLICATE KEY سيمنع التكرار)
            }
        }
        
        // ===== إدراج الراتب الجديد - بناءً على نوع عمود month =====
        // القيمة التي سيتم إدراجها في عمود month
        $monthValue = $isMonthDate ? $targetDate : $month;
        
        error_log("createOrUpdateSalary: Inserting new salary with monthValue={$monthValue}, isMonthDate=" . ($isMonthDate ? 'true' : 'false'));
        
        if ($isMonthDate || $hasYearColumn) {
            // عمود month من نوع DATE أو يوجد عمود year منفصل
            if ($hasBonusColumn) {
                if ($hasNotesColumn) {
                    if ($hasCreatedByColumn) {
                        // استخدام ON DUPLICATE KEY UPDATE - إذا كان السجل موجود، يتم التحديث فقط
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, created_by, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                             ON DUPLICATE KEY UPDATE
                                hourly_rate = VALUES(hourly_rate),
                                total_hours = VALUES(total_hours),
                                base_amount = VALUES(base_amount),
                                bonus = VALUES(bonus),
                                deductions = VALUES(deductions),
                                total_amount = VALUES(total_amount),
                                notes = VALUES(notes)" . ($hasUpdatedAtColumn ? ",
                                updated_at = NOW()" : "") . "
                            ",
                            [
                                $userId,
                                $monthValue,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes,
                                $createdBy
                            ]
                        );
                    } else {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                             ON DUPLICATE KEY UPDATE
                                hourly_rate = VALUES(hourly_rate),
                                total_hours = VALUES(total_hours),
                                base_amount = VALUES(base_amount),
                                bonus = VALUES(bonus),
                                deductions = VALUES(deductions),
                                total_amount = VALUES(total_amount),
                                notes = VALUES(notes)" . ($hasUpdatedAtColumn ? ",
                                updated_at = NOW()" : "") . "
                            ",
                            [
                                $userId,
                                $monthValue,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes
                            ]
                        );
                    }
                } else {
                    if ($hasCreatedByColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, created_by, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                             ON DUPLICATE KEY UPDATE
                                hourly_rate = VALUES(hourly_rate),
                                total_hours = VALUES(total_hours),
                                base_amount = VALUES(base_amount),
                                bonus = VALUES(bonus),
                                deductions = VALUES(deductions),
                                total_amount = VALUES(total_amount)" . ($hasUpdatedAtColumn ? ",
                                updated_at = NOW()" : "") . "
                            ",
                            [
                                $userId,
                                $monthValue,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $createdBy
                            ]
                        );
                    } else {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                             ON DUPLICATE KEY UPDATE
                                hourly_rate = VALUES(hourly_rate),
                                total_hours = VALUES(total_hours),
                                base_amount = VALUES(base_amount),
                                bonus = VALUES(bonus),
                                deductions = VALUES(deductions),
                                total_amount = VALUES(total_amount)" . ($hasUpdatedAtColumn ? ",
                                updated_at = NOW()" : "") . "
                            ",
                            [
                                $userId,
                                $monthValue,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'],
                                $calculation['deductions'],
                                $calculation['total_amount']
                            ]
                        );
                    }
                }
            } else {
                if ($hasNotesColumn) {
                    if ($hasCreatedByColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, created_by, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                             ON DUPLICATE KEY UPDATE
                                hourly_rate = VALUES(hourly_rate),
                                total_hours = VALUES(total_hours),
                                base_amount = VALUES(base_amount),
                                deductions = VALUES(deductions),
                                total_amount = VALUES(total_amount),
                                notes = VALUES(notes)" . ($hasUpdatedAtColumn ? ",
                                updated_at = NOW()" : "") . "
                            ",
                            [
                                $userId,
                                $monthValue,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes,
                                $createdBy
                            ]
                        );
                    } else {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                             ON DUPLICATE KEY UPDATE
                                hourly_rate = VALUES(hourly_rate),
                                total_hours = VALUES(total_hours),
                                base_amount = VALUES(base_amount),
                                deductions = VALUES(deductions),
                                total_amount = VALUES(total_amount),
                                notes = VALUES(notes)" . ($hasUpdatedAtColumn ? ",
                                updated_at = NOW()" : "") . "
                            ",
                            [
                                $userId,
                                $monthValue,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes
                            ]
                        );
                    }
                } else {
                    if ($hasCreatedByColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, created_by, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                             ON DUPLICATE KEY UPDATE
                                hourly_rate = VALUES(hourly_rate),
                                total_hours = VALUES(total_hours),
                                base_amount = VALUES(base_amount),
                                deductions = VALUES(deductions),
                                total_amount = VALUES(total_amount)" . ($hasUpdatedAtColumn ? ",
                                updated_at = NOW()" : "") . "
                            ",
                            [
                                $userId,
                                $monthValue,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $createdBy
                            ]
                        );
                    } else {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                             ON DUPLICATE KEY UPDATE
                                hourly_rate = VALUES(hourly_rate),
                                total_hours = VALUES(total_hours),
                                base_amount = VALUES(base_amount),
                                deductions = VALUES(deductions),
                                total_amount = VALUES(total_amount)" . ($hasUpdatedAtColumn ? ",
                                updated_at = NOW()" : "") . "
                            ",
                            [
                                $userId,
                                $monthValue,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['deductions'],
                                $calculation['total_amount']
                            ]
                        );
                    }
                }
            }
        } else {
            // ===== Fallback: إذا لم يكن month من نوع DATE ولا يوجد year =====
            // نضيف عمود year تلقائياً
            try {
                $db->execute("ALTER TABLE salaries ADD COLUMN year INT(4) DEFAULT NULL AFTER month");
                $hasYearColumn = true;
                error_log("Added missing 'year' column to salaries table in createOrUpdateSalary");
            } catch (Exception $alterEx) {
                error_log("Could not add year column in createOrUpdateSalary: " . $alterEx->getMessage());
                $yearColumnRecheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
                $hasYearColumn = !empty($yearColumnRecheck);
            }
            
            // استخدام $month مباشرة (عدد صحيح) لأن العمود من نوع INT
            if ($hasBonusColumn) {
                if ($hasNotesColumn) {
                    if ($hasCreatedByColumn) {
                        if ($hasYearColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $year,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes,
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes,
                                    $createdBy
                                ]
                            );
                        }
                    } else {
                        if ($hasYearColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $year,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes
                                ]
                            );
                        }
                    }
                } else {
                    // hasBonusColumn = false
                    if ($hasNotesColumn) {
                        if ($hasCreatedByColumn) {
                            if ($hasYearColumn) {
                                $result = $db->execute(
                                    "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, created_by, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                    [
                                        $userId,
                                        $month,
                                        $year,
                                        $calculation['hourly_rate'],
                                        $calculation['total_hours'],
                                        $calculation['base_amount'],
                                        $calculation['deductions'],
                                        $calculation['total_amount'],
                                        $notes,
                                        $createdBy
                                    ]
                                );
                            } else {
                                $result = $db->execute(
                                    "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, created_by, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                    [
                                        $userId,
                                        $month,
                                        $calculation['hourly_rate'],
                                        $calculation['total_hours'],
                                        $calculation['base_amount'],
                                        $calculation['deductions'],
                                        $calculation['total_amount'],
                                        $notes,
                                        $createdBy
                                    ]
                                );
                            }
                        } else {
                            if ($hasYearColumn) {
                                $result = $db->execute(
                                    "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                    [
                                        $userId,
                                        $month,
                                        $year,
                                        $calculation['hourly_rate'],
                                        $calculation['total_hours'],
                                        $calculation['base_amount'],
                                        $calculation['deductions'],
                                        $calculation['total_amount'],
                                        $notes
                                    ]
                                );
                            } else {
                                $result = $db->execute(
                                    "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                    [
                                        $userId,
                                        $month,
                                        $calculation['hourly_rate'],
                                        $calculation['total_hours'],
                                        $calculation['base_amount'],
                                        $calculation['deductions'],
                                        $calculation['total_amount'],
                                        $notes
                                    ]
                                );
                            }
                        }
                    } else {
                        // hasNotesColumn = false
                        if ($hasCreatedByColumn) {
                            if ($hasYearColumn) {
                                $result = $db->execute(
                                    "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, created_by, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                    [
                                        $userId,
                                        $month,
                                        $year,
                                        $calculation['hourly_rate'],
                                        $calculation['total_hours'],
                                        $calculation['base_amount'],
                                        $calculation['deductions'],
                                        $calculation['total_amount'],
                                        $createdBy
                                    ]
                                );
                            } else {
                                $result = $db->execute(
                                    "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, created_by, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                    [
                                        $userId,
                                        $month,
                                        $calculation['hourly_rate'],
                                        $calculation['total_hours'],
                                        $calculation['base_amount'],
                                        $calculation['deductions'],
                                        $calculation['total_amount'],
                                        $createdBy
                                    ]
                                );
                            }
                        } else {
                            if ($hasYearColumn) {
                                $result = $db->execute(
                                    "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                    [
                                        $userId,
                                        $month,
                                        $year,
                                        $calculation['hourly_rate'],
                                        $calculation['total_hours'],
                                        $calculation['base_amount'],
                                        $calculation['deductions'],
                                        $calculation['total_amount']
                                    ]
                                );
                            } else {
                                $result = $db->execute(
                                    "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
                                    [
                                        $userId,
                                        $month,
                                        $calculation['hourly_rate'],
                                        $calculation['total_hours'],
                                        $calculation['base_amount'],
                                        $calculation['deductions'],
                                        $calculation['total_amount']
                                    ]
                                );
                            }
                        }
                    }
                }
            }
        }
        
        // تسجيل نتيجة الإدراج
        if (isset($result) && $result) {
            // Commit transaction إذا كنا في transaction
            try {
                if ($db->inTransaction()) {
                    $db->commit();
                    error_log("createOrUpdateSalary: Committed transaction after inserting salary for user: {$userId}");
                }
            } catch (Exception $commitEx) {
                error_log("createOrUpdateSalary: Failed to commit transaction: " . $commitEx->getMessage());
                // نستمر حتى لو فشل commit لأن الراتب تم إدراجه بالفعل
            }
            
            $insertId = $db->getLastInsertId();
            
            // فحص نهائي: التأكد من عدم وجود رواتب مكررة
            // هذا فحص إضافي للتأكد من أن UNIQUE KEY عمل بشكل صحيح
            if ($insertId) {
                try {
                    if ($isMonthDate) {
                        $duplicateCheck = $db->query(
                            "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ? AND month != '0000-00-00' AND month IS NOT NULL AND id != ?",
                            [$userId, $targetYearMonth, $insertId]
                        );
                    } elseif ($hasYearColumn) {
                        $duplicateCheck = $db->query(
                            "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ? AND month > 0 AND year > 0 AND id != ?",
                            [$userId, $month, $year, $insertId]
                        );
                    } else {
                        $duplicateCheck = $db->query(
                            "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND month > 0 AND month <= 12 AND id != ?",
                            [$userId, $month, $insertId]
                        );
                    }
                    
                    if (!empty($duplicateCheck) && count($duplicateCheck) > 0) {
                        error_log("WARNING: createOrUpdateSalary: Found duplicate salaries for user {$userId}, month {$month}, year {$year}. Created ID: {$insertId}, Duplicates: " . json_encode($duplicateCheck));
                    }
                } catch (Exception $checkEx) {
                    error_log("createOrUpdateSalary: Error checking for duplicates: " . $checkEx->getMessage());
                }
            }
            
            error_log("createOrUpdateSalary: Successfully inserted salary ID: {$insertId} for user: {$userId}, month: {$monthValue}, year: {$year}");
            return [
                'success' => true,
                'message' => 'تم إنشاء الراتب بنجاح',
                'salary_id' => $insertId,
                'calculation' => $calculation
            ];
        } else {
            // Rollback في حالة فشل الإدراج
            try {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
            } catch (Exception $rollbackEx) {
                error_log("createOrUpdateSalary: Failed to rollback transaction: " . $rollbackEx->getMessage());
            }
            
            error_log("createOrUpdateSalary: Insert failed for user: {$userId}");
            // محاولة قراءة الخطأ إذا كان متاحاً
            return [
                'success' => false,
                'message' => 'فشل في إنشاء الراتب - راجع سجل الأخطاء',
                'calculation' => $calculation
            ];
        }
    }
    
    // إذا وصلنا إلى هنا، يعني أن هناك راتب موجود وتم تحديثه
    return [
        'success' => true,
        'message' => 'تم تحديث الراتب الموجود بنجاح',
        'salary_id' => $existingSalary['id'] ?? null,
        'calculation' => $calculation
    ];
}

/**
 * دالة مساعدة لحذف الكود القديم غير المستخدم
 * هذه الدالة فارغة لأن الكود القديم تم حذفه
 */
function _legacy_salary_insert_removed() {
    // تم حذف الكود القديم المكرر
    // الكود الجديد أعلاه يتعامل مع جميع الحالات بشكل صحيح
}

// ===== بداية الكود القديم المحذوف - يمكن حذف هذا القسم بالكامل =====
// تم استبدال كل الكود القديم بالكود الجديد أعلاه
// ===== نهاية الكود القديم المحذوف =====

/**
 * دالة مساعدة: تنظيف القيم المالية - تم تعريفها في مكان آخر
 * هذه مجرد إشارة للدالة الموجودة
 */
// function cleanFinancialValue($value) { ... }

// ===== استكمال الدوال الأخرى في الملف =====

/**
 * حساب جميع الرواتب للشهر المحدد
 * @deprecated استخدم createOrUpdateSalary بدلاً من هذه الدالة
 */
function calculateAllSalariesOldCode($month, $year) {
    // هذه الدالة قديمة - استخدم createOrUpdateSalary
    // تم الاحتفاظ بها للتوافق مع الكود القديم
    return ['success' => false, 'message' => 'استخدم createOrUpdateSalary بدلاً من هذه الدالة'];
}

// ===== نهاية الإصلاحات =====

// الكود التالي هو بقية الملف الأصلي (إذا وجد)
// تم تنظيف الكود المكرر والخاطئ

/*
 * ملاحظة: تم حذف الكود القديم التالي لأنه كان يسبب مشاكل:
 * - كود INSERT المكرر بدون استخدام $monthValue
 * - كود INSERT بدون التحقق من نوع عمود month
 * - كود يستخدم $targetDate بدلاً من $monthValue
 * 
 * الكود الجديد يتعامل مع جميع الحالات:
 * 1. إذا كان month من نوع DATE: يستخدم $targetDate (مثل 2025-12-01)
 * 2. إذا كان month من نوع INT مع year: يستخدم $month و $year منفصلين
 * 3. إذا كان month من نوع INT بدون year: يستخدم $month فقط
 */

// ===== بقية الملف - الدوال الأخرى =====

/**
 * حساب رواتب جميع المستخدمين للشهر
 * يستبعد المديرين (role = 'manager')
 */
function calculateAllSalaries($month, $year) {
    $db = db();
    
    // استبعاد المديرين - ليس لديهم رواتب
    $users = $db->query(
        "SELECT id FROM users 
         WHERE status = 'active' 
         AND role != 'manager' 
         AND hourly_rate > 0"
    );
    
    $results = [];
    
    foreach ($users as $user) {
        $result = createOrUpdateSalary($user['id'], $month, $year);
        $results[] = [
            'user_id' => $user['id'],
            'result' => $result
        ];
    }
    
    return $results;
}

/**
 * الحصول على معرف المندوب المستحق للعمولة من عميل معين
 * يبحث عن المندوب من خلال:
 * 1. created_by في جدول customers (المندوب الذي أنشأ العميل)
 * 2. sales_rep_id في جدول invoices (المندوب المرتبط بفواتير العميل)
 *
 * @param int $customerId معرف العميل
 * @return int|null معرف المندوب أو null إذا لم يتم العثور عليه
 */
function getSalesRepForCustomer($customerId) {
    $customerId = (int)$customerId;
    if ($customerId <= 0) {
        return null;
    }
    
    try {
        $db = db();
        
        // أولاً: البحث عن المندوب من خلال created_by في جدول customers
        $customer = $db->queryOne("SELECT created_by FROM customers WHERE id = ?", [$customerId]);
        if ($customer && !empty($customer['created_by'])) {
            $salesRepId = intval($customer['created_by']);
            // التحقق من أن المستخدم مندوب نشط
            $salesRep = $db->queryOne(
                "SELECT id FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                [$salesRepId]
            );
            if ($salesRep) {
                return $salesRepId;
            }
        }
        
        // ثانياً: البحث عن المندوب من خلال sales_rep_id في جدول invoices
        $invoicesTableCheck = $db->queryOne("SHOW TABLES LIKE 'invoices'");
        if (!empty($invoicesTableCheck)) {
            $invoice = $db->queryOne(
                "SELECT sales_rep_id FROM invoices 
                 WHERE customer_id = ? AND sales_rep_id IS NOT NULL 
                 ORDER BY date DESC LIMIT 1",
                [$customerId]
            );
            if ($invoice && !empty($invoice['sales_rep_id'])) {
                $salesRepId = intval($invoice['sales_rep_id']);
                // التحقق من أن المستخدم مندوب نشط
                $salesRep = $db->queryOne(
                    "SELECT id FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                    [$salesRepId]
                );
                if ($salesRep) {
                    return $salesRepId;
                }
            }
        }
        
        return null;
    } catch (Throwable $e) {
        error_log('Error getting sales rep for customer ' . $customerId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * إعادة احتساب راتب المندوب مباشرةً بعد حدوث عملية تؤثر على نسبة التحصيل.
 *
 * @param int $userId
 * @param string|null $referenceDate تاريخ العملية (يتم استخدام تاريخ اليوم إذا تُرك فارغاً)
 * @param string|null $reason ملاحظة يتم تمريرها لدالة الحساب (اختياري)
 * @return bool true عند نجاح إعادة الحساب أو false عند الفشل/تجاهل المستخدم
 */
function refreshSalesCommissionForUser($userId, $referenceDate = null, $reason = null) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return false;
    }
    
    try {
        $db = db();
        $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
    } catch (Throwable $e) {
        error_log('Failed to read user role while refreshing salary: ' . $e->getMessage());
        return false;
    }
    
    if (!$user || strtolower((string)($user['role'] ?? '')) !== 'sales') {
        return false;
    }
    
    $timestamp = $referenceDate ? strtotime($referenceDate) : time();
    if ($timestamp === false) {
        $timestamp = time();
    }
    
    $month = (int)date('n', $timestamp);
    $year = (int)date('Y', $timestamp);
    
    $note = $reason ?: 'تحديث تلقائي بعد عملية تحصيل';
    
    try {
        $result = createOrUpdateSalary($userId, $month, $year, 0, 0, $note);
        if (!($result['success'] ?? false)) {
            error_log('Failed to refresh salary after collection for user ' . $userId . ': ' . ($result['message'] ?? 'unknown error'));
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('Exception while refreshing salary after collection for user ' . $userId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * توليد تقرير رواتب شهري شامل
 */
function generateMonthlySalaryReport($month, $year) {
    $db = db();
    
    // استبعاد المديرين فقط - عرض جميع الأدوار الأخرى (production, accountant, sales)
    // حتى لو لم يكن لديهم hourly_rate (سيظهر لهم 0)
    $users = $db->query(
        "SELECT u.id, u.username, u.full_name, u.role, COALESCE(u.hourly_rate, 0) as hourly_rate
         FROM users u
         WHERE u.status = 'active' 
         AND u.role != 'manager'
         AND u.role IN ('production', 'accountant', 'sales')
         ORDER BY 
            CASE u.role 
                WHEN 'production' THEN 1
                WHEN 'accountant' THEN 2
                WHEN 'sales' THEN 3
                ELSE 4
            END,
            u.full_name ASC"
    );
    
    $report = [
        'month' => $month,
        'year' => $year,
        'total_users' => 0,
        'total_hours' => 0,
        'total_amount' => 0,
        'total_delay_minutes' => 0,
        'average_delay_minutes' => 0,
        'salaries' => []
    ];
    
    foreach ($users as $user) {
        // حساب أو الحصول على الراتب
        $delaySummary = [
            'total_minutes' => 0.0,
            'average_minutes' => 0.0,
            'delay_days' => 0,
            'attendance_days' => 0,
        ];

        if (function_exists('calculateMonthlyDelaySummary')) {
            $delaySummary = calculateMonthlyDelaySummary($user['id'], $month, $year);
        }
        
        // عرض جميع المستخدمين النشطين من الأدوار المطلوبة (production, accountant, sales)
        // حتى لو لم يكن لديهم حضور أو راتب مسجل في الشهر
        
        $salaryData = getSalarySummary($user['id'], $month, $year);
        
        if ($salaryData['exists']) {
            $salary = $salaryData['salary'];
            
            // حساب نسبة التحصيلات إذا كان مندوب
            $collectionsAmount = 0;
            $collectionsBonus = 0;
            if ($user['role'] === 'sales') {
                $collectionsAmount = calculateSalesCollections($user['id'], $month, $year);
                $collectionsBonus = $collectionsAmount * 0.02;
            }
            
            $report['salaries'][] = [
                'user_id' => $user['id'],
                'user_name' => $user['full_name'] ?? $user['username'],
                'role' => $user['role'],
                'hourly_rate' => $salary['hourly_rate'],
                'total_hours' => $salary['total_hours'],
                'base_amount' => $salary['base_amount'],
                'collections_amount' => $collectionsAmount,
                'collections_bonus' => round($collectionsBonus, 2),
                'bonus' => $salary['bonus'] ?? 0,
                'deductions' => $salary['deductions'] ?? 0,
                'total_amount' => $salary['total_amount'],
                'status' => $salary['status'] ?? 'pending',
                'total_delay_minutes' => $delaySummary['total_minutes'],
                'average_delay_minutes' => $delaySummary['average_minutes'],
                'delay_days' => $delaySummary['delay_days'],
                'attendance_days' => $delaySummary['attendance_days'],
            ];
            
            $report['total_hours'] += $salary['total_hours'];
            $report['total_amount'] += $salary['total_amount'];
            $report['total_delay_minutes'] += $delaySummary['total_minutes'];
        } else if (isset($salaryData['calculation']) && $salaryData['calculation']['success']) {
            // حساب الراتب إذا لم يكن موجوداً
            $calc = $salaryData['calculation'];
            $report['salaries'][] = [
                'user_id' => $user['id'],
                'user_name' => $user['full_name'] ?? $user['username'],
                'role' => $user['role'],
                'hourly_rate' => $calc['hourly_rate'],
                'total_hours' => $calc['total_hours'],
                'base_amount' => $calc['base_amount'],
                'collections_amount' => $calc['collections_amount'] ?? 0,
                'collections_bonus' => $calc['collections_bonus'] ?? 0,
                'bonus' => $calc['bonus'],
                'deductions' => $calc['deductions'],
                'total_amount' => $calc['total_amount'],
                'status' => 'not_calculated',
                'total_delay_minutes' => $delaySummary['total_minutes'],
                'average_delay_minutes' => $delaySummary['average_minutes'],
                'delay_days' => $delaySummary['delay_days'],
                'attendance_days' => $delaySummary['attendance_days'],
            ];
            
            $report['total_hours'] += $calc['total_hours'];
            $report['total_amount'] += $calc['total_amount'];
            $report['total_delay_minutes'] += $delaySummary['total_minutes'];
        } else {
            // حتى لو لم يكن لديهم راتب محسوب، نضيفهم للتقرير مع بيانات الحضور
            $monthHours = calculateMonthlyHours($user['id'], $month, $year);
            $hourlyRate = (float)($user['hourly_rate'] ?? 0);
            
            // حساب نسبة التحصيلات إذا كان مندوب
            $collectionsAmount = 0;
            $collectionsBonus = 0;
            if ($user['role'] === 'sales') {
                $collectionsAmount = calculateSalesCollections($user['id'], $month, $year);
                $collectionsBonus = $collectionsAmount * 0.02;
            }
            
            $baseAmount = round($monthHours * $hourlyRate, 2);
            $totalAmount = round($baseAmount + $collectionsBonus, 2);
            
            $report['salaries'][] = [
                'user_id' => $user['id'],
                'user_name' => $user['full_name'] ?? $user['username'],
                'role' => $user['role'],
                'hourly_rate' => $hourlyRate,
                'total_hours' => $monthHours,
                'base_amount' => $baseAmount,
                'collections_amount' => $collectionsAmount,
                'collections_bonus' => round($collectionsBonus, 2),
                'bonus' => 0,
                'deductions' => 0,
                'total_amount' => $totalAmount,
                'status' => 'not_calculated',
                'total_delay_minutes' => $delaySummary['total_minutes'],
                'average_delay_minutes' => $delaySummary['average_minutes'],
                'delay_days' => $delaySummary['delay_days'],
                'attendance_days' => $delaySummary['attendance_days'],
            ];
            
            $report['total_hours'] += $monthHours;
            $report['total_amount'] += $totalAmount;
            $report['total_delay_minutes'] += $delaySummary['total_minutes'];
        }
    }
    
    $report['total_users'] = count($report['salaries']);
    $report['average_delay_minutes'] = $report['total_users'] > 0
        ? round($report['total_delay_minutes'] / $report['total_users'], 2)
        : 0;
    
    return $report;
}

/**
 * الحصول على ملخص الراتب
 */
function getSalarySummary($userId, $month, $year) {
    $db = db();
    
    // التحقق من وجود جدول salaries
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'salaries'");
    if (empty($tableCheck)) {
        return [
            'exists' => false,
            'calculation' => calculateSalary($userId, $month, $year)
        ];
    }
    
    // التحقق من نوع عمود month أولاً - هذا مهم جداً
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    $isMonthDate = stripos($monthType, 'date') !== false;
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    // إعداد التاريخ الصحيح للبحث
    $targetYearMonth = sprintf('%04d-%02d', $year, $month);
    
    // بناء الاستعلام بناءً على نوع عمود month ووجود عمود year
    if ($isMonthDate) {
        // عمود month من نوع DATE - البحث باستخدام DATE_FORMAT
        $whereClause = "s.user_id = ? AND DATE_FORMAT(s.month, '%Y-%m') = ? AND s.month != '0000-00-00' AND s.month IS NOT NULL";
        $params = [$userId, $targetYearMonth];
        
        // إذا كان عمود year موجوداً، نضيفه كشرط إضافي للتحقق
        if ($hasYearColumn) {
            $whereClause .= " AND s.year = ? AND s.year > 0";
            $params[] = $year;
        }
        
        $salary = $db->queryOne(
            "SELECT s.*, u.full_name, u.username, u.hourly_rate as current_hourly_rate
             FROM salaries s
             LEFT JOIN users u ON s.user_id = u.id
             WHERE {$whereClause}",
            $params
        );
    } elseif ($hasYearColumn) {
        // عمود month من نوع INT مع وجود عمود year منفصل
        $salary = $db->queryOne(
            "SELECT s.*, u.full_name, u.username, u.hourly_rate as current_hourly_rate
             FROM salaries s
             LEFT JOIN users u ON s.user_id = u.id
             WHERE s.user_id = ? AND s.month = ? AND s.year = ? AND s.month > 0 AND s.year > 0",
            [$userId, $month, $year]
        );
    } else {
        // عمود month من نوع INT بدون عمود year
        $salary = $db->queryOne(
            "SELECT s.*, u.full_name, u.username, u.hourly_rate as current_hourly_rate
             FROM salaries s
             LEFT JOIN users u ON s.user_id = u.id
             WHERE s.user_id = ? AND s.month = ? AND s.month > 0",
            [$userId, $month]
        );
    }
    
    if (!$salary) {
        // حساب الراتب إذا لم يكن موجوداً
        $calculation = calculateSalary($userId, $month, $year);
        return [
            'exists' => false,
            'calculation' => $calculation
        ];
    }
    
    // تنظيف جميع القيم المالية من 262145
    if (isset($salary['hourly_rate'])) {
        $salary['hourly_rate'] = cleanFinancialValue($salary['hourly_rate']);
    }
    if (isset($salary['base_amount'])) {
        $salary['base_amount'] = cleanFinancialValue($salary['base_amount']);
    }
    if (isset($salary['total_amount'])) {
        $salary['total_amount'] = cleanFinancialValue($salary['total_amount']);
    }
    if (isset($salary['bonus'])) {
        $salary['bonus'] = cleanFinancialValue($salary['bonus']);
    }
    if (isset($salary['deductions'])) {
        $salary['deductions'] = cleanFinancialValue($salary['deductions']);
    }
    if (isset($salary['current_hourly_rate'])) {
        $salary['current_hourly_rate'] = cleanFinancialValue($salary['current_hourly_rate']);
    }
    if (isset($salary['collections_bonus'])) {
        $salary['collections_bonus'] = cleanFinancialValue($salary['collections_bonus']);
    }
    if (isset($salary['collections_amount'])) {
        $salary['collections_amount'] = cleanFinancialValue($salary['collections_amount']);
    }
    if (isset($salary['settlements_advances'])) {
        $salary['settlements_advances'] = cleanFinancialValue($salary['settlements_advances']);
    }
    
    return [
        'exists' => true,
        'salary' => $salary
    ];
}

/**
 * الحصول على خريطة أعمدة جدول الرواتب المستخدمة في خصم السلف.
 *
 * @return array{
 *     deductions: string|null,
 *     advances_deduction: string|null,
 *     total_amount: string|null,
 *     updated_at: string|null
 * }
 */
function salaryAdvanceGetSalaryColumns(?Database $dbInstance = null): array
{
    static $columnMap = null;

    if ($columnMap !== null) {
        return $columnMap;
    }

    $db = $dbInstance ?: db();

    try {
        $columns = $db->query("SHOW COLUMNS FROM salaries");
    } catch (Throwable $e) {
        error_log('Unable to read salaries columns for advances deduction: ' . $e->getMessage());
        $columnMap = [
            'deductions' => null,
            'advances_deduction' => null,
            'total_amount' => null,
            'updated_at' => null,
        ];
        return $columnMap;
    }

    $columnMap = [
        'deductions' => null,
        'advances_deduction' => null,
        'total_amount' => null,
        'updated_at' => null,
    ];

    foreach ($columns as $column) {
        $field = $column['Field'] ?? '';
        if ($field === '') {
            continue;
        }

        if ($columnMap['deductions'] === null && in_array($field, ['deductions', 'total_deductions'], true)) {
            $columnMap['deductions'] = $field;
        } elseif ($columnMap['advances_deduction'] === null && $field === 'advances_deduction') {
            $columnMap['advances_deduction'] = $field;
        } elseif ($columnMap['total_amount'] === null && in_array($field, ['total_amount', 'net_total', 'amount'], true)) {
            $columnMap['total_amount'] = $field;
        } elseif ($columnMap['updated_at'] === null && in_array($field, ['updated_at', 'modified_at', 'last_updated'], true)) {
            $columnMap['updated_at'] = $field;
        }
    }

    return $columnMap;
}

/**
 * تجهيز الراتب الذي سيتم خصم السلفة منه، وإنشاء الراتب إن لم يكن موجوداً.
 *
 * @return array{
 *     success: bool,
 *     salary?: array,
 *     salary_id?: int,
 *     month?: int,
 *     year?: int,
 *     message?: string
 * }
 */
function salaryAdvanceResolveSalary(array $advance, ?Database $dbInstance = null): array
{
    $db = $dbInstance ?: db();

    $userId = isset($advance['user_id']) ? (int)$advance['user_id'] : 0;
    $amount = isset($advance['amount']) ? (float)$advance['amount'] : 0.0;

    if ($userId <= 0) {
        return ['success' => false, 'message' => 'معرف المستخدم غير صالح للسلفة.'];
    }

    if ($amount <= 0) {
        return ['success' => false, 'message' => 'مبلغ السلفة غير صالح للخصم.'];
    }

    $targetDate = $advance['request_date'] ?? date('Y-m-d');
    $timestamp = strtotime($targetDate) ?: time();
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp);

    $summary = getSalarySummary($userId, $month, $year);

    if (!$summary['exists']) {
        $creation = createOrUpdateSalary($userId, $month, $year);
        if (!($creation['success'] ?? false)) {
            $message = $creation['message'] ?? 'تعذر إنشاء الراتب لخصم السلفة.';
            return ['success' => false, 'message' => $message];
        }

        $summary = getSalarySummary($userId, $month, $year);
        if (!($summary['exists'] ?? false)) {
            return ['success' => false, 'message' => 'لم يتم العثور على الراتب بعد إنشائه.'];
        }
    }

    $salary = $summary['salary'];
    $salaryId = isset($salary['id']) ? (int)$salary['id'] : 0;

    if ($salaryId <= 0) {
        return ['success' => false, 'message' => 'تعذر تحديد الراتب لخصم السلفة.'];
    }

    return [
        'success' => true,
        'salary' => $salary,
        'salary_id' => $salaryId,
        'month' => $month,
        'year' => $year,
    ];
}

/**
 * تطبيق خصم السلفة على الراتب المحدد.
 *
 * @return array{success: bool, message?: string}
 */
function salaryAdvanceApplyDeduction(array $advance, array $salaryData, ?Database $dbInstance = null): array
{
    $db = $dbInstance ?: db();
    $amount = isset($advance['amount']) ? (float)$advance['amount'] : 0.0;

    if ($amount <= 0) {
        return ['success' => false, 'message' => 'مبلغ السلفة غير صالح.'];
    }

    $columns = salaryAdvanceGetSalaryColumns($db);
    if (
        $columns['advances_deduction'] === null
        && $columns['total_amount'] === null
    ) {
        return ['success' => false, 'message' => 'لا يمكن تنفيذ الخصم لعدم توفر الأعمدة المطلوبة.'];
    }

    $salaryId = isset($salaryData['id']) ? (int)$salaryData['id'] : 0;
    if ($salaryId <= 0) {
        return ['success' => false, 'message' => 'معرف الراتب غير صالح.'];
    }

    $updates = [];
    $params = [];

    // تسجيل السلفة في عمود advances_deduction فقط
    if ($columns['advances_deduction'] !== null) {
        $updates[] = "{$columns['advances_deduction']} = COALESCE({$columns['advances_deduction']}, 0) + ?";
        $params[] = $amount;
    }

    // تسجيل السلفة في عمود settlements_advances (التسويات والسلف)
    // التحقق من وجود العمود أولاً
    try {
        $settlementsAdvancesCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'settlements_advances'");
        if (!empty($settlementsAdvancesCheck)) {
            $updates[] = "settlements_advances = COALESCE(settlements_advances, 0) + ?";
            $params[] = $amount;
        }
    } catch (Throwable $e) {
        error_log('Error checking settlements_advances column: ' . $e->getMessage());
    }

    // ملاحظة: لا يتم تسجيل السلفة في عمود deductions لتجنب التسجيل المزدوج
    // السلفة تُسجل فقط في advances_deduction و settlements_advances

    if ($columns['total_amount'] !== null) {
        $updates[] = "{$columns['total_amount']} = GREATEST(COALESCE({$columns['total_amount']}, 0) - ?, 0)";
        $params[] = $amount;
    }

    if ($columns['updated_at'] !== null) {
        $updates[] = "{$columns['updated_at']} = NOW()";
    }

    if (empty($updates)) {
        return ['success' => false, 'message' => 'لا توجد أعمدة صالحة لتحديث الراتب.'];
    }

    $params[] = $salaryId;

    try {
        $db->execute(
            "UPDATE salaries SET " . implode(', ', $updates) . " WHERE id = ?",
            $params
        );
    } catch (Throwable $e) {
        error_log('Failed to apply salary advance deduction: ' . $e->getMessage());
        return ['success' => false, 'message' => 'تعذر تحديث الراتب بخصم السلفة.'];
    }

    return ['success' => true];
}

/**
 * حساب الراتب الإجمالي بشكل صحيح مع نسبة التحصيلات
 * تستخدم نفس المنطق المستخدم في صفحة "مرتبي"
 */
function calculateTotalSalaryWithCollections($salaryRecord, $userId, $month, $year, $role) {
    $baseAmount = cleanFinancialValue($salaryRecord['base_amount'] ?? 0);
    $bonus = cleanFinancialValue($salaryRecord['bonus'] ?? 0);
    $deductions = cleanFinancialValue($salaryRecord['deductions'] ?? 0);
    $totalSalaryBase = cleanFinancialValue($salaryRecord['total_amount'] ?? 0);
    
    // حساب نسبة التحصيلات للمندوبين
    $collectionsBonus = 0;
    if ($role === 'sales') {
        $collectionsAmount = calculateSalesCollections($userId, $month, $year);
        $collectionsBonus = round($collectionsAmount * 0.02, 2);
        
        // إذا كان الراتب محفوظاً، تحقق من وجود نسبة التحصيلات المحفوظة
        if (isset($salaryRecord['collections_bonus'])) {
            $savedCollectionsBonus = cleanFinancialValue($salaryRecord['collections_bonus'] ?? 0);
            // استخدم القيمة المحسوبة حديثاً إذا كانت أكبر من القيمة المحفوظة
            if ($collectionsBonus > $savedCollectionsBonus || $savedCollectionsBonus == 0) {
                // استخدم القيمة المحسوبة حديثاً
            } else {
                $collectionsBonus = $savedCollectionsBonus;
            }
        }
    }
    
    // حساب الراتب الإجمالي - دائماً احسبه من المكونات لضمان الدقة
    // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
    $totalSalary = $baseAmount + $bonus + $collectionsBonus - $deductions;
    
    // إذا كان الراتب الإجمالي المحفوظ ($totalSalaryBase) أكبر من الراتب المحسوب من المكونات
    // فهذا يعني أن هناك مكونات إضافية (مثل سلفات مخصومة)، لذا استخدم القيمة المحفوظة
    // لكن تأكد من تضمين نسبة التحصيلات إذا لم تكن مضمنة
    if ($role === 'sales' && $collectionsBonus > 0) {
        // حساب الراتب المتوقع بدون نسبة التحصيلات
        $expectedTotalWithoutCollections = $baseAmount + $bonus - $deductions;
        
        // إذا كان الراتب الإجمالي المحفوظ يساوي الراتب المتوقع بدون نسبة التحصيلات
        // فهذا يعني أن نسبة التحصيلات غير مضمنة، لذا أضفها
        if (abs($totalSalaryBase - $expectedTotalWithoutCollections) < 0.01) {
            // نسبة التحصيلات غير مضمنة، أضفها
            $totalSalary = $totalSalaryBase + $collectionsBonus;
        } else {
            // نسبة التحصيلات مضمنة أو هناك خصومات إضافية (مثل سلفات)
            // استخدم الراتب المحسوب من المكونات
            $totalSalary = $baseAmount + $bonus + $collectionsBonus - $deductions;
        }
    } else {
        // للمستخدمين الآخرين أو إذا لم تكن هناك نسبة تحصيلات
        // استخدم الراتب المحسوب من المكونات
        $totalSalary = $baseAmount + $bonus - $deductions;
    }
    
    return [
        'total_salary' => cleanFinancialValue($totalSalary),
        'collections_bonus' => $collectionsBonus,
        'base_amount' => $baseAmount,
        'bonus' => $bonus,
        'deductions' => $deductions
    ];
}

/**
 * حساب وتحديث total_hours في جدول salaries من attendance_records
 * تستخدم استعلام SELECT * FROM attendance_records ORDER BY work_hours ASC
 * لحساب إجمالي ساعات العمل لكل موظف بشكل صحيح
 * 
 * @param int|null $userId معرّف المستخدم (اختياري - إذا كان null يتم تحديث جميع المستخدمين)
 * @param int|null $month الشهر (اختياري - إذا كان null يتم تحديث جميع الأشهر)
 * @param int|null $year السنة (اختياري - إذا كان null يتم تحديث جميع السنوات)
 * @return array نتيجة العملية
 */
/**
 * حساب المبلغ التراكمي للراتب بناءً على الراتب الحالي والرواتب السابقة
 * هذه الدالة توحد منطق الحساب في جميع أنحاء النظام
 * 
 * @param int $userId معرف الموظف
 * @param int $salaryId معرف الراتب الحالي (0 إذا لم يكن محفوظاً)
 * @param float $currentTotalAmount المبلغ الإجمالي للراتب الحالي
 * @param int $currentMonth الشهر الحالي (1-12)
 * @param int $currentYear السنة الحالية
 * @param Database|null $dbInstance مثيل قاعدة البيانات (اختياري)
 * @return array ['accumulated' => float, 'paid' => float, 'remaining' => float]
 */
function calculateSalaryAccumulatedAmount($userId, $salaryId, $currentTotalAmount, $currentMonth, $currentYear, $dbInstance = null) {
    if ($dbInstance === null) {
        $db = db();
    } else {
        $db = $dbInstance;
    }
    
    require_once __DIR__ . '/config.php';
    if (!function_exists('cleanFinancialValue')) {
        function cleanFinancialValue($value) {
            return floatval(str_replace(',', '', $value ?? 0));
        }
    }
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    // التحقق من نوع عمود month
    $isMonthDate = false;
    try {
        $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
        if (!empty($monthColumnCheck) && isset($monthColumnCheck['Type'])) {
            $monthType = $monthColumnCheck['Type'];
            $isMonthDate = stripos($monthType, 'date') !== false;
        }
    } catch (Exception $e) {
        // افتراض أن month من نوع INT في حالة الخطأ
        $isMonthDate = false;
    }
    
    // ابدأ بالراتب الحالي
    $accumulated = cleanFinancialValue($currentTotalAmount);
    
    // جلب الرواتب السابقة
    // ملاحظة: الرواتب التي month NULL يتم استبعادها من حساب المبلغ التراكمي
    // لأننا لا نستطيع تحديد ما إذا كانت سابقة أم لا
    if ($hasYearColumn) {
        if ($salaryId > 0) {
            $previousSalaries = $db->query(
                "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                        COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                 FROM salaries s
                 WHERE s.user_id = ? AND s.id != ? 
                 AND s.year IS NOT NULL AND s.month IS NOT NULL
                 AND s.year > 0 AND s.month > 0 AND s.month <= 12
                 AND (s.year < ? OR (s.year = ? AND s.month < ?))
                 ORDER BY s.year ASC, s.month ASC",
                [$userId, $salaryId, $currentYear, $currentYear, $currentMonth]
            );
        } else {
            $previousSalaries = $db->query(
                "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                        COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                 FROM salaries s
                 WHERE s.user_id = ? 
                 AND s.year IS NOT NULL AND s.month IS NOT NULL
                 AND s.year > 0 AND s.month > 0 AND s.month <= 12
                 AND (s.year < ? OR (s.year = ? AND s.month < ?))
                 ORDER BY s.year ASC, s.month ASC",
                [$userId, $currentYear, $currentYear, $currentMonth]
            );
        }
    } else {
        if ($isMonthDate) {
            // month من نوع DATE
            if ($salaryId > 0) {
                $previousSalaries = $db->query(
                    "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                            COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                     FROM salaries s
                     WHERE s.user_id = ? AND s.id != ? 
                     AND s.month IS NOT NULL 
                     AND s.month != '0000-00-00' 
                     AND s.month != '1970-01-01'
                     AND s.month < ?
                     ORDER BY s.month ASC",
                    [$userId, $salaryId, $currentMonth]
                );
            } else {
                $previousSalaries = $db->query(
                    "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                            COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                     FROM salaries s
                     WHERE s.user_id = ? 
                     AND s.month IS NOT NULL 
                     AND s.month != '0000-00-00' 
                     AND s.month != '1970-01-01'
                     AND s.month < ?
                     ORDER BY s.month ASC",
                    [$userId, $currentMonth]
                );
            }
        } else {
            // month من نوع INT بدون year
            if ($salaryId > 0) {
                $previousSalaries = $db->query(
                    "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                            COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                     FROM salaries s
                     WHERE s.user_id = ? AND s.id != ? 
                     AND s.month IS NOT NULL
                     AND s.month > 0 AND s.month <= 12
                     AND s.month < ?
                     ORDER BY s.month ASC",
                    [$userId, $salaryId, $currentMonth]
                );
            } else {
                $previousSalaries = $db->query(
                    "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                            COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
                     FROM salaries s
                     WHERE s.user_id = ? 
                     AND s.month IS NOT NULL
                     AND s.month > 0 AND s.month <= 12
                     AND s.month < ?
                     ORDER BY s.month ASC",
                    [$userId, $currentMonth]
                );
            }
        }
    }
    
    // جمع المتبقي من الرواتب السابقة
    foreach ($previousSalaries as $prevSalary) {
        $prevTotal = cleanFinancialValue($prevSalary['total_amount'] ?? 0);
        $prevPaid = cleanFinancialValue($prevSalary['paid_amount'] ?? 0);
        $prevAccumulated = cleanFinancialValue($prevSalary['prev_accumulated'] ?? $prevTotal);
        
        // حساب المتبقي من الراتب السابق
        $prevRemaining = max(0, $prevAccumulated - $prevPaid);
        
        // إضافة المتبقي إلى المبلغ التراكمي فقط إذا كان هناك متبقي
        if ($prevRemaining > 0.01) {
            $accumulated += $prevRemaining;
        }
    }
    
    return [
        'accumulated' => $accumulated
    ];
}

function updateTotalHoursFromAttendanceRecords($userId = null, $month = null, $year = null) {
    try {
        $db = db();
        
        // التحقق من وجود جدول attendance_records
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
        if (empty($tableCheck)) {
            return [
                'success' => false,
                'message' => 'جدول attendance_records غير موجود'
            ];
        }
        
        // التحقق من وجود عمود total_hours في جدول salaries
        $totalHoursColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'total_hours'");
        if (empty($totalHoursColumnCheck)) {
            return [
                'success' => false,
                'message' => 'عمود total_hours غير موجود في جدول salaries'
            ];
        }
        
        // التحقق من وجود عمود updated_at
        $hasUpdatedAtColumn = false;
        try {
            $columnExists = $db->queryOne(
                "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'salaries' 
                 AND COLUMN_NAME = 'updated_at'"
            );
            if (!empty($columnExists) && isset($columnExists['cnt'])) {
                $hasUpdatedAtColumn = (int)$columnExists['cnt'] > 0;
            }
        } catch (Exception $e) {
            try {
                $updatedAtColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'updated_at'");
                if (!empty($updatedAtColumnCheck) && isset($updatedAtColumnCheck['Field']) && $updatedAtColumnCheck['Field'] === 'updated_at') {
                    $hasUpdatedAtColumn = true;
                }
            } catch (Exception $e2) {
                $hasUpdatedAtColumn = false;
            }
        }
        
        // التحقق من وجود الأعمدة في جدول salaries
        $columns = $db->query("SHOW COLUMNS FROM salaries");
        $columnNames = [];
        foreach ($columns as $column) {
            $columnNames[] = $column['Field'] ?? '';
        }
        
        $hasYearColumn = in_array('year', $columnNames, true);
        
        // التحقق من نوع عمود month
        $isMonthDate = false;
        try {
            $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
            if (!empty($monthColumnCheck) && isset($monthColumnCheck['Type'])) {
                $monthType = $monthColumnCheck['Type'];
                $isMonthDate = stripos($monthType, 'date') !== false;
            }
        } catch (Exception $e) {
            $isMonthDate = false;
        }
        
        // استخدام الاستعلام المطلوب: SELECT * FROM attendance_records ORDER BY work_hours ASC
        // ثم تجميع الساعات لكل موظف وشهر
        // بناء شروط WHERE للاستعلام الداخلي
        $innerWhereConditions = [];
        $innerParams = [];
        
        if ($userId !== null) {
            $innerWhereConditions[] = "user_id = ?";
            $innerParams[] = $userId;
        }
        
        if ($month !== null && $year !== null) {
            $innerWhereConditions[] = "DATE_FORMAT(date, '%Y-%m') = ?";
            $innerParams[] = sprintf('%04d-%02d', $year, $month);
        }
        
        $innerWhereClause = !empty($innerWhereConditions) ? "WHERE " . implode(" AND ", $innerWhereConditions) : "";
        
        $query = "
            SELECT 
                ar.user_id,
                DATE_FORMAT(ar.date, '%Y-%m') as month_year,
                YEAR(ar.date) as year,
                MONTH(ar.date) as month,
                COALESCE(SUM(ar.work_hours), 0) as total_hours
            FROM (
                SELECT * 
                FROM attendance_records 
                {$innerWhereClause}
                ORDER BY attendance_records.work_hours ASC
            ) ar
            WHERE ar.work_hours IS NOT NULL 
              AND ar.work_hours > 0
              AND ar.check_out_time IS NOT NULL
            GROUP BY ar.user_id, DATE_FORMAT(ar.date, '%Y-%m')
        ";
        
        // استخدام نفس المعاملات للاستعلام الداخلي
        $params = $innerParams;
        
        // تنفيذ الاستعلام
        $results = $db->query($query, $params);
        
        if (empty($results)) {
            return [
                'success' => true,
                'message' => 'لا توجد سجلات حضور لتحديثها',
                'updated_count' => 0
            ];
        }
        
        $updatedCount = 0;
        $errors = [];
        
        // تحديث total_hours لكل سجل راتب
        foreach ($results as $result) {
            $userIdValue = intval($result['user_id']);
            $monthValue = intval($result['month']);
            $yearValue = intval($result['year']);
            $totalHours = round(floatval($result['total_hours']), 2);
            
            try {
                // البحث عن سجل الراتب المطابق
                // بناء WHERE clause بناءً على وجود عمود year
                if ($hasYearColumn) {
                    $selectSql = "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ?";
                    $selectParams = [$userIdValue, $monthValue, $yearValue];
                } else {
                    // إذا لم يكن year موجوداً، تحقق من نوع month
                    if ($isMonthDate) {
                        // إذا كان month من نوع DATE
                        $selectSql = "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ?";
                        $selectParams = [$userIdValue, sprintf('%04d-%02d', $yearValue, $monthValue)];
                    } else {
                        // إذا كان month من نوع INT فقط
                        $selectSql = "SELECT id FROM salaries WHERE user_id = ? AND month = ?";
                        $selectParams = [$userIdValue, $monthValue];
                    }
                }
                
                $salary = $db->queryOne($selectSql, $selectParams);
                
                if ($salary) {
                    // تحديث total_hours
                    $updateFields = ['total_hours = ?'];
                    $updateParams = [$totalHours];
                    if ($hasUpdatedAtColumn) {
                        $updateFields[] = 'updated_at = NOW()';
                    }
                    $updateParams[] = $salary['id'];
                    $db->execute(
                        "UPDATE salaries SET " . implode(', ', $updateFields) . " WHERE id = ?",
                        $updateParams
                    );
                    $updatedCount++;
                } else {
                    // إذا لم يكن هناك سجل راتب، يمكن إنشاؤه (اختياري)
                    // أو تسجيله كخطأ
                    $errors[] = "لا يوجد سجل راتب للمستخدم #{$userIdValue} للشهر {$monthValue}/{$yearValue}";
                }
            } catch (Exception $e) {
                $errors[] = "خطأ في تحديث راتب المستخدم #{$userIdValue} للشهر {$monthValue}/{$yearValue}: " . $e->getMessage();
                error_log("Error updating total_hours for user {$userIdValue}, month {$monthValue}/{$yearValue}: " . $e->getMessage());
            }
        }
        
        return [
            'success' => true,
            'message' => "تم تحديث {$updatedCount} سجل راتب بنجاح",
            'updated_count' => $updatedCount,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        error_log("Error in updateTotalHoursFromAttendanceRecords: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ أثناء تحديث total_hours: ' . $e->getMessage()
        ];
    }
}


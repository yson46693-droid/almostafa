<?php
/**
 * صفحة إدارة الرواتب الشاملة - حساب وعرض وتعديل الرواتب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/salary_calculator.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';

// تحميل نظام Cache إذا كان متوفراً
if (file_exists(__DIR__ . '/../../includes/cache.php')) {
    require_once __DIR__ . '/../../includes/cache.php';
}

requireAnyRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$approvalsEntityColumn = getApprovalsEntityColumn();
$error = '';
$success = '';

/**
 * حساب صافي رصيد خزنة الشركة
 * الصيغة: الإيرادات المعتمدة - المصروفات المعتمدة - المدفوعات
 * ملاحظة: تسويات الرواتب تُحسب كـ expenses في accountant_transactions، لذلك لا نحتاج لخصم إجمالي المرتبات منفصلاً
 */
function calculateCompanyCashBalance($db) {
    // حساب ملخص الخزنة من financial_transactions و accountant_transactions
    $treasurySummary = $db->queryOne("
        SELECT
            (SELECT COALESCE(SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
            (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('collection_from_sales_rep', 'income') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_income,
            (SELECT COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND status = 'approved' 
                AND description NOT LIKE '%تسوية رصيد دائن ل%'
                THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_expense,
            (SELECT COALESCE(SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_payment
    ");
    
    $approvedIncome = (float) ($treasurySummary['approved_income'] ?? 0);
    $approvedExpense = (float) ($treasurySummary['approved_expense'] ?? 0);
    $approvedPayment = (float) ($treasurySummary['approved_payment'] ?? 0);
    
    // حساب صافي الرصيد
    // ملاحظة: تسويات الرواتب تُحسب كـ payments في accountant_transactions، لذلك تُخصم تلقائياً
    $netBalance = $approvedIncome - $approvedExpense - $approvedPayment;
    
    return $netBalance; // يمكن أن يكون سالباً إذا كانت المصروفات أكبر من الإيرادات
}

// إنشاء جدول السلف
$advancesTableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_advances'");
if (empty($advancesTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `salary_advances` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL COMMENT 'الموظف',
              `amount` decimal(10,2) NOT NULL COMMENT 'مبلغ السلفة',
              `reason` text DEFAULT NULL COMMENT 'سبب السلفة',
              `request_date` date NOT NULL COMMENT 'تاريخ الطلب',
              `status` enum('pending','accountant_approved','manager_approved','rejected') DEFAULT 'pending' COMMENT 'حالة الطلب',
              `accountant_approved_by` int(11) DEFAULT NULL,
              `accountant_approved_at` timestamp NULL DEFAULT NULL,
              `manager_approved_by` int(11) DEFAULT NULL,
              `manager_approved_at` timestamp NULL DEFAULT NULL,
              `deducted_from_salary_id` int(11) DEFAULT NULL COMMENT 'الراتب الذي تم خصم السلفة منه',
              `total_salary_before_advance` decimal(10,2) DEFAULT NULL COMMENT 'الراتب الإجمالي قبل خصم السلفة',
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `status` (`status`),
              KEY `request_date` (`request_date`),
              KEY `accountant_approved_by` (`accountant_approved_by`),
              KEY `manager_approved_by` (`manager_approved_by`),
              KEY `deducted_from_salary_id` (`deducted_from_salary_id`),
              CONSTRAINT `salary_advances_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_advances_ibfk_2` FOREIGN KEY (`accountant_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `salary_advances_ibfk_3` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `salary_advances_ibfk_4` FOREIGN KEY (`deducted_from_salary_id`) REFERENCES `salaries` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating salary_advances table: " . $e->getMessage());
    }
} else {
    // التأكد من وجود عمود total_salary_before_advance
    try {
        $columnCheck = $db->queryOne("SHOW COLUMNS FROM salary_advances LIKE 'total_salary_before_advance'");
        if (empty($columnCheck)) {
            $db->execute("
                ALTER TABLE `salary_advances`
                ADD COLUMN `total_salary_before_advance` decimal(10,2) DEFAULT NULL COMMENT 'الراتب الإجمالي قبل خصم السلفة'
                AFTER `deducted_from_salary_id`
            ");
        }
    } catch (Exception $e) {
        error_log("Error adding total_salary_before_advance column: " . $e->getMessage());
    }
}

// إضافة عمود advances_deduction في جدول salaries
$advancesColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'advances_deduction'");
if (empty($advancesColumnCheck)) {
    try {
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `advances_deduction` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'خصم السلف' 
            AFTER `deductions`
        ");
    } catch (Exception $e) {
        error_log("Error adding advances_deduction column: " . $e->getMessage());
    }
}

// إضافة عمود settlements_advances في جدول salaries (التسويات والسلف)
$settlementsAdvancesColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'settlements_advances'");
if (empty($settlementsAdvancesColumnCheck)) {
    try {
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `settlements_advances` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'التسويات والسلف' 
            AFTER `advances_deduction`
        ");
    } catch (Exception $e) {
        error_log("Error adding settlements_advances column: " . $e->getMessage());
    }
}

// إضافة عمود notes في جدول salaries إذا لم يكن موجوداً
$notesColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'notes'");
if (empty($notesColumnCheck)) {
    try {
        // التحقق من وجود عمود updated_at أو استخدام عمود آخر
        $updatedAtCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field IN ('updated_at', 'modified_at', 'last_updated')");
        $afterColumn = 'created_at'; // افتراضي: بعد created_at
        if (!empty($updatedAtCheck)) {
            $afterColumn = $updatedAtCheck['Field'];
        } else {
            // التحقق من وجود total_amount
            $totalAmountCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field IN ('total_amount', 'amount', 'net_total')");
            if (!empty($totalAmountCheck)) {
                $afterColumn = $totalAmountCheck['Field'];
            }
        }
        
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات' 
            AFTER `{$afterColumn}`
        ");
    } catch (Exception $e) {
        error_log("Error adding notes column: " . $e->getMessage());
    }
}

// إضافة أعمدة accumulated_amount و paid_amount في جدول salaries
$accumulatedColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'accumulated_amount'");
if (empty($accumulatedColumnCheck)) {
    try {
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `accumulated_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'المبلغ التراكمي' 
            AFTER `total_amount`
        ");
    } catch (Exception $e) {
        error_log("Error adding accumulated_amount column: " . $e->getMessage());
    }
}

$paidAmountColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'paid_amount'");
if (empty($paidAmountColumnCheck)) {
    try {
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `paid_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'المبلغ المدفوع' 
            AFTER `accumulated_amount`
        ");
    } catch (Exception $e) {
        error_log("Error adding paid_amount column: " . $e->getMessage());
    }
}

// إنشاء جدول salary_payments لتسجيل المدفوعات (اختياري - يُستخدم للتحقق من المدفوعات المرتبطة)
$paymentsTableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_payments'");
if (empty($paymentsTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `salary_payments` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `salary_id` int(11) NOT NULL COMMENT 'معرف الراتب',
              `user_id` int(11) NOT NULL COMMENT 'معرف الموظف',
              `payment_amount` decimal(10,2) NOT NULL COMMENT 'مبلغ الدفعة',
              `payment_date` date NOT NULL COMMENT 'تاريخ الدفعة',
              `payment_method` varchar(50) DEFAULT NULL COMMENT 'طريقة الدفع',
              `notes` text DEFAULT NULL COMMENT 'ملاحظات',
              `created_by` int(11) DEFAULT NULL COMMENT 'من قام بالتسجيل',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `salary_id` (`salary_id`),
              KEY `user_id` (`user_id`),
              KEY `payment_date` (`payment_date`),
              KEY `created_by` (`created_by`),
              CONSTRAINT `salary_payments_ibfk_1` FOREIGN KEY (`salary_id`) REFERENCES `salaries` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_payments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_payments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating salary_payments table: " . $e->getMessage());
    }
}

// إنشاء جدول salary_settlements لتسجيل عمليات التسوية
$settlementsTableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_settlements'");
if (empty($settlementsTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `salary_settlements` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `salary_id` int(11) NOT NULL COMMENT 'معرف الراتب',
              `user_id` int(11) NOT NULL COMMENT 'معرف الموظف',
              `settlement_amount` decimal(10,2) NOT NULL COMMENT 'مبلغ التسوية',
              `previous_accumulated` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ التراكمي السابق',
              `remaining_after_settlement` decimal(10,2) DEFAULT 0.00 COMMENT 'المتبقي بعد التسوية',
              `settlement_type` enum('full','partial') DEFAULT 'partial' COMMENT 'نوع التسوية',
              `settlement_date` date NOT NULL COMMENT 'تاريخ التسوية',
              `notes` text DEFAULT NULL COMMENT 'ملاحظات',
              `created_by` int(11) DEFAULT NULL COMMENT 'من قام بالتسوية',
              `invoice_path` varchar(500) DEFAULT NULL COMMENT 'مسار فاتورة PDF',
              `telegram_sent` tinyint(1) DEFAULT 0 COMMENT 'تم الإرسال إلى تليجرام',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `salary_id` (`salary_id`),
              KEY `user_id` (`user_id`),
              KEY `settlement_date` (`settlement_date`),
              KEY `created_by` (`created_by`),
              CONSTRAINT `salary_settlements_ibfk_1` FOREIGN KEY (`salary_id`) REFERENCES `salaries` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_settlements_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_settlements_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating salary_settlements table: " . $e->getMessage());
    }
}

// الحصول على الشهر والسنة الحالية
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// التحقق من صحة الشهر والسنة
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = date('n');
}
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = date('Y');
}
$selectedUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$salaryId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$view = isset($_GET['view']) ? $_GET['view'] : ''; // 'list' أو 'pending' أو 'advances'

// تحديد التبويب الافتراضي
if (empty($view)) {
    $view = 'list'; // الافتراضي: قائمة الرواتب
}

// التحقق من طلب عرض التقرير الشهري
$showReport = isset($_GET['report']) && $_GET['report'] == '1';

// تعريف دالة بناء الروابط (يجب تعريفها مبكراً لاستخدامها في معالجة POST)
require_once __DIR__ . '/../../includes/path_helper.php';
$rawScript = $_SERVER['PHP_SELF'] ?? '/dashboard/accountant.php';
$rawScript = ltrim($rawScript, '/');
if ($rawScript === '') {
    $currentScript = 'dashboard/accountant.php';
} else {
    $dashboardPos = strpos($rawScript, 'dashboard/');
    if ($dashboardPos !== false) {
        $currentScript = substr($rawScript, $dashboardPos);
    } else {
        $currentScript = $rawScript;
    }
    if (strpos($currentScript, 'dashboard/') !== 0) {
        $currentScript = 'dashboard/accountant.php';
    }
}
$currentUrl = getRelativeUrl($currentScript);
$viewBaseQuery = [
    'page' => 'salaries',
    'month' => $selectedMonth,
    'year' => $selectedYear,
];
$buildViewUrl = function (string $targetView, array $extra = []) use ($currentUrl, $viewBaseQuery) {
    $query = array_merge($viewBaseQuery, ['view' => $targetView], $extra);
    return $currentUrl . '?' . http_build_query($query);
};

// قراءة الرسائل من session (Post-Redirect-Get pattern)
if (isset($_SESSION['salaries_success'])) {
    $success = $_SESSION['salaries_success'];
    unset($_SESSION['salaries_success']);
}

if (isset($_SESSION['salaries_error'])) {
    $error = $_SESSION['salaries_error'];
    unset($_SESSION['salaries_error']);
}

// قراءة حالة التقرير من session
if (isset($_SESSION['salaries_show_report']) && $_SESSION['salaries_show_report']) {
    $showReport = true;
    if (isset($_SESSION['salaries_report_month'])) {
        $selectedMonth = (int)$_SESSION['salaries_report_month'];
        // التحقق من صحة الشهر
        if ($selectedMonth < 1 || $selectedMonth > 12) {
            $selectedMonth = date('n');
        }
    }
    if (isset($_SESSION['salaries_report_year'])) {
        $selectedYear = (int)$_SESSION['salaries_report_year'];
        // التحقق من صحة السنة
        if ($selectedYear < 2000 || $selectedYear > 2100) {
            $selectedYear = date('Y');
        }
    }
    // تنظيف session
    unset($_SESSION['salaries_show_report']);
    unset($_SESSION['salaries_report_month']);
    unset($_SESSION['salaries_report_year']);
}

$monthlyReport = null;

if ($showReport) {
    $monthlyReport = generateMonthlySalaryReport($selectedMonth, $selectedYear);
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'calculate_all') {
        $month = intval($_POST['month'] ?? $selectedMonth);
        $year = intval($_POST['year'] ?? $selectedYear);
        
        $results = calculateAllSalaries($month, $year);
        
        $successCount = 0;
        foreach ($results as $result) {
            if ($result['result']['success']) {
                $successCount++;
                // طلب موافقة لكل راتب
                if (isset($result['result']['salary_id'])) {
                    requestApproval('salary', $result['result']['salary_id'], $currentUser['id'], 'حساب تلقائي لجميع الرواتب');
                }
            }
        }
        
        logAudit($currentUser['id'], 'calculate_all_salaries', 'salary', null, null, [
            'month' => $month,
            'year' => $year,
            'count' => $successCount
        ]);
        
        $success = "تم حساب $successCount راتب بنجاح";
        $view = 'list'; // الانتقال إلى قائمة الرواتب
    } elseif ($action === 'send_attendance_report') {
        $month = intval($_POST['month'] ?? $selectedMonth);
        $year  = intval($_POST['year'] ?? $selectedYear);

        $reportResult = sendMonthlyAttendanceReportToTelegram($month, $year, [
            'force' => true,
            'triggered_by' => $currentUser['id'] ?? null,
        ]);

        // حفظ الرسالة في session لمنع التكرار
        if ($reportResult['success']) {
            $_SESSION['salaries_success'] = $reportResult['message'];
        } else {
            $_SESSION['salaries_error'] = $reportResult['message'];
        }

        // حفظ حالة التقرير في session
        $_SESSION['salaries_show_report'] = true;
        $_SESSION['salaries_report_month'] = $month;
        $_SESSION['salaries_report_year'] = $year;

        // عمل redirect لمنع التكرار عند refresh (Post-Redirect-Get pattern)
        // تحديد URL الصحيح بناءً على الصفحة الحالية
        $rawScript = $_SERVER['PHP_SELF'] ?? '/dashboard/accountant.php';
        $rawScript = ltrim($rawScript, '/');
        
        $isManagerPage = (strpos($rawScript, 'manager.php') !== false);
        
        if ($isManagerPage) {
            $redirectUrl = getRelativeUrl('dashboard/manager.php');
        } else {
            $redirectUrl = getRelativeUrl('dashboard/accountant.php');
        }
        
        $redirectUrl .= '?page=salaries&view=list&report=1&month=' . $month . '&year=' . $year;
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'update_total_hours') {
        // تحديث total_hours من attendance_records
        require_once __DIR__ . '/../../includes/salary_calculator.php';
        
        $month = intval($_POST['month'] ?? $selectedMonth);
        $year = intval($_POST['year'] ?? $selectedYear);
        $userId = isset($_POST['user_id']) && intval($_POST['user_id']) > 0 ? intval($_POST['user_id']) : null;
        
        $result = updateTotalHoursFromAttendanceRecords($userId, $month, $year);
        
        if ($result['success']) {
            $message = $result['message'];
            if (!empty($result['errors'])) {
                $message .= ' (' . count($result['errors']) . ' أخطاء)';
            }
            $_SESSION['salaries_success'] = $message;
            
            // تسجيل سجل التدقيق
            logAudit($currentUser['id'], 'update_total_hours', 'salary', null, null, [
                'month' => $month,
                'year' => $year,
                'user_id' => $userId,
                'updated_count' => $result['updated_count'] ?? 0
            ]);
        } else {
            $_SESSION['salaries_error'] = $result['message'];
        }
        
        // عمل redirect
        $redirectUrl = getRelativeUrl('dashboard/accountant.php') . '?page=salaries&view=list&month=' . $month . '&year=' . $year;
        if ($userId) {
            $redirectUrl .= '&user_id=' . $userId;
        }
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'modify_salary') {
        // وظيفة تعديل الراتب من salary_details.php
        $salaryId = intval($_POST['salary_id'] ?? 0);
        $bonus = floatval($_POST['bonus'] ?? 0);
        $deductions = floatval($_POST['deductions'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($salaryId <= 0) {
            $error = 'معرف الراتب غير صحيح';
        } else {
            // الحصول على بيانات الراتب
            $salary = $db->queryOne(
                "SELECT s.*, u.full_name, u.username, u.role 
                 FROM salaries s 
                 LEFT JOIN users u ON s.user_id = u.id 
                 WHERE s.id = ?",
                [$salaryId]
            );
            
            if (!$salary) {
                $error = 'الراتب غير موجود';
            } else {
                // الحصول على الشهر والسنة من الراتب
                $salaryMonth = intval($salary['month'] ?? $selectedMonth);
                $salaryYear = intval($salary['year'] ?? $selectedYear);
                $userId = intval($salary['user_id'] ?? 0);
                
                // الراتب الأساسي هو حقل تراكمي يُحسب من الساعات × سعر الساعة
                // لا يجب تحديث base_amount عند تعديل الراتب (إضافة مكافأة أو خصم)
                // سنستخدم base_amount الحالي من قاعدة البيانات لحساب total_amount فقط
                $currentBaseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
                
                // إذا لم يكن base_amount موجوداً، احسبه من الساعات (فقط للاستخدام في الحساب)
                if ($currentBaseAmount <= 0) {
                    require_once __DIR__ . '/../../includes/salary_calculator.php';
                    $hourlyRate = cleanFinancialValue($salary['hourly_rate'] ?? 0);
                    $completedHours = calculateCompletedMonthlyHours($userId, $salaryMonth, $salaryYear);
                    $currentBaseAmount = round($completedHours * $hourlyRate, 2);
                }
                
                // استخدام base_amount الحالي (لن يتم تحديثه في قاعدة البيانات)
                // base_amount هو حقل تراكمي لا يجب تغييره عند تعديل الراتب
                
                // حساب نسبة التحصيلات إذا كان مندوب مبيعات
                // عند تعديل الراتب بإضافة مكافأة، نحافظ على نسبة التحصيلات الحالية ولا نعيد حسابها
                $collectionsBonus = 0;
                if ($salary['role'] === 'sales') {
                    // الحفاظ على نسبة التحصيلات الحالية من قاعدة البيانات (عدم إعادة الحساب)
                    $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                } else {
                    $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                }
                
                // التحقق من وجود عمود bonus أو bonuses
                $bonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field IN ('bonus', 'bonuses')");
                $hasBonusColumn = !empty($bonusColumnCheck);
                $bonusColumnName = $hasBonusColumn ? $bonusColumnCheck['Field'] : null;
                
                // الحصول على المكافآت والخصومات الحالية من الراتب
                // استخدام اسم العمود الصحيح
                $currentBonus = 0;
                if ($hasBonusColumn && $bonusColumnName) {
                    $currentBonus = cleanFinancialValue($salary[$bonusColumnName] ?? 0);
                }
                $currentDeductions = cleanFinancialValue($salary['deductions'] ?? 0);
                
                // حساب المكافآت والخصومات النهائية
                if ($hasBonusColumn) {
                    // المكافآت النهائية = المكافآت الحالية + المكافآت الجديدة المضافة
                    $finalBonus = $currentBonus + $bonus;
                    // الخصومات النهائية = الخصومات الحالية + الخصومات الجديدة المضافة
                    $finalDeductions = $currentDeductions + $deductions;
                } else {
                    // إذا لم يكن هناك عمود bonus، استخدم القيم المضافة فقط
                    $finalBonus = $bonus;
                    $finalDeductions = $deductions;
                }
                
                // حساب الراتب الجديد: الراتب الأساسي (الحالي من قاعدة البيانات) + المكافآت (الحالية + الجديدة) + نسبة التحصيلات - الخصومات (الحالية + الجديدة)
                // ملاحظة: base_amount لا يتم تحديثه لأنه حقل تراكمي يُحسب من الساعات × سعر الساعة
                $newTotalAmount = $currentBaseAmount + $finalBonus + $collectionsBonus - $finalDeductions;
                $newTotalAmount = max(0, $newTotalAmount);
                
                // إذا كان المحاسب فقط (وليس المدير)، يحتاج موافقة
                if ($currentUser['role'] === 'accountant') {
                    // التحقق من وجود موافقة معلقة
                    $pendingApproval = $db->queryOne(
                        "SELECT id FROM approvals 
                         WHERE type = 'salary_modification' AND `{$approvalsEntityColumn}` = ? AND status = 'pending'",
                        [$salaryId]
                    );
                    
                    if ($pendingApproval) {
                        $error = 'يوجد طلب تعديل معلق بالفعل على هذا الراتب';
                    } else {
                        // حفظ بيانات التعديل في JSON
                        $modificationData = json_encode([
                            'bonus' => $bonus,
                            'deductions' => $deductions,
                            'original_bonus' => $salary['bonus_standardized'] ?? ($salary['bonus'] ?? $salary['bonuses'] ?? 0),
                            'original_deductions' => $salary['deductions'] ?? 0,
                            'notes' => $notes
                        ]);
                        
                        // طلب موافقة المدير
                        $approvalNotes = "طلب تعديل راتب للمستخدم {$salary['full_name']}. مكافأة: " . number_format($bonus, 2) . " جنيه, خصومات: " . number_format($deductions, 2) . " جنيه. " . ($notes ? "السبب: {$notes}" : "");
                        
                        $approvalResult = requestApproval(
                            'salary_modification',
                            $salaryId,
                            $currentUser['id'],
                            $approvalNotes . "\n[DATA]:" . $modificationData
                        );
                        
                        if ($approvalResult['success']) {
                            logAudit($currentUser['id'], 'request_salary_modification', 'salary', $salaryId, null, [
                                'bonus' => $bonus,
                                'deductions' => $deductions,
                                'approval_id' => $approvalResult['approval_id']
                            ]);
                            
                            $_SESSION['salaries_success'] = 'تم إرسال طلب التعديل. في انتظار موافقة المدير.';
                            $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
                            header('Location: ' . $redirectUrl);
                            exit;
                        } else {
                            $_SESSION['salaries_error'] = $approvalResult['message'] ?? 'فشل إرسال طلب الموافقة';
                            $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
                            header('Location: ' . $redirectUrl);
                            exit;
                        }
                    }
                } else {
                    // المدير - يمكنه الموافقة مباشرة
                    // تسجيل القيم للتأكد من صحتها
                    error_log("Modify salary - salaryId: {$salaryId}, currentBonus: {$currentBonus}, bonus: {$bonus}, finalBonus: {$finalBonus}, currentDeductions: {$currentDeductions}, deductions: {$deductions}, finalDeductions: {$finalDeductions}, newTotalAmount: {$newTotalAmount}, collectionsBonus: {$collectionsBonus}");
                    
                    if ($hasBonusColumn && $bonusColumnName) {
                        // التحقق من وجود عمود collections_bonus
                        $collectionsBonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'collections_bonus'");
                        $hasCollectionsBonusColumn = !empty($collectionsBonusColumnCheck);
                        
                        // عند تعديل راتب مندوب مبيعات بإضافة مكافأة، لا نقوم بتحديث نسبة التحصيلات
                        // نحافظ على القيمة الحالية ولا نعدلها
                        $isSalesRep = ($salary['role'] === 'sales');
                        $shouldUpdateCollectionsBonus = $hasCollectionsBonusColumn && !$isSalesRep;
                        
                        if ($shouldUpdateCollectionsBonus) {
                            // لا نقوم بتحديث base_amount لأنه حقل تراكمي يُحسب من الساعات × سعر الساعة
                            $db->execute(
                                "UPDATE salaries SET 
                                    {$bonusColumnName} = ?,
                                    deductions = ?,
                                    collections_bonus = ?,
                                    total_amount = ?,
                                    notes = ?
                                 WHERE id = ?",
                                [$finalBonus, $finalDeductions, $collectionsBonus, $newTotalAmount, $notes ?: null, $salaryId]
                            );
                        } else {
                            // لا نقوم بتحديث collections_bonus لمندوب المبيعات عند إضافة مكافأة
                            // ولا نقوم بتحديث base_amount لأنه حقل تراكمي يُحسب من الساعات × سعر الساعة
                            $db->execute(
                                "UPDATE salaries SET 
                                    {$bonusColumnName} = ?,
                                    deductions = ?,
                                    total_amount = ?,
                                    notes = ?
                                 WHERE id = ?",
                                [$finalBonus, $finalDeductions, $newTotalAmount, $notes ?: null, $salaryId]
                            );
                        }
                    } else {
                        // إذا لم يكن هناك عمود bonus، لا يمكن حفظ المكافآت
                        // لكن يجب تحديث total_amount مع المكافآت
                        // ملاحظة: base_amount لا يتم تحديثه لأنه حقل تراكمي يُحسب من الساعات × سعر الساعة
                        $db->execute(
                            "UPDATE salaries SET 
                                deductions = ?,
                                total_amount = ?,
                                notes = ?
                             WHERE id = ?",
                            [$finalDeductions, $newTotalAmount, $notes ?: null, $salaryId]
                        );
                    }
                    
                    // إرسال إشعار للمستخدم
                    createNotification(
                        $salary['user_id'],
                        'تم تعديل راتبك',
                        "تم تعديل راتبك للشهر " . date('F', mktime(0, 0, 0, $selectedMonth, 1)) . ". مكافأة: " . formatCurrency($finalBonus) . ", خصومات: " . formatCurrency($finalDeductions),
                        'info',
                        null,
                        false
                    );
                    
                    logAudit($currentUser['id'], 'modify_salary', 'salary', $salaryId, null, [
                        'bonus' => $finalBonus,
                        'deductions' => $finalDeductions,
                        'bonus_added' => $bonus,
                        'deductions_added' => $deductions
                    ]);
                    
                    $_SESSION['salaries_success'] = 'تم تعديل الراتب بنجاح';
                    $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
                    header('Location: ' . $redirectUrl);
                    exit;
                }
            }
        }
    }
    
    // طلب سلفة جديدة
    elseif ($action === 'request_advance') {
        $userId = intval($_POST['user_id'] ?? $currentUser['id']);
        $amount = floatval($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $requestDate = $_POST['request_date'] ?? date('Y-m-d');
        
        if ($amount <= 0) {
            $error = 'يجب إدخال مبلغ السلفة';
        } else {
            // التحقق من آخر تسجيل حضور/انصراف
            $attendanceCheck = canRequestAdvance($userId);
            if (!$attendanceCheck['allowed']) {
                $error = $attendanceCheck['message'];
            } else {
                // التحقق من وجود طلب سلفة معلق (pending أو accountant_approved)
                $existingRequest = $db->queryOne(
                    "SELECT id FROM salary_advances 
                     WHERE user_id = ? AND status IN ('pending', 'accountant_approved')",
                    [$userId]
                );
                
                if ($existingRequest) {
                    $error = 'يوجد طلب سلفة معلق بالفعل لهذا الموظف في انتظار الموافقة النهائية';
                } else {
                try {
                    $result = $db->execute(
                        "INSERT INTO salary_advances (user_id, amount, reason, request_date, status) 
                         VALUES (?, ?, ?, ?, 'pending')",
                        [$userId, $amount, $reason ?: null, $requestDate]
                    );
                    
                    logAudit($currentUser['id'], 'request_advance', 'salary_advance', $result['insert_id'], null, [
                        'user_id' => $userId,
                        'amount' => $amount
                    ]);
                    
                    $success = 'تم إرسال طلب السلفة بنجاح. في انتظار موافقة المحاسب.';
                } catch (Exception $e) {
                    $error = 'حدث خطأ في إرسال الطلب: ' . $e->getMessage();
                }
                }
            }
        }
    }
    
    // موافقة المحاسب على السلفة
    elseif ($action === 'accountant_approve' || $action === 'accountant_approve_advance') {
        if ($currentUser['role'] !== 'accountant' && $currentUser['role'] !== 'manager') {
            $error = 'غير مصرح لك بهذا الإجراء';
        } else {
            $advanceId = intval($_POST['advance_id'] ?? 0);
            $finalApproval = isset($_POST['final_approval']) && $_POST['final_approval'] === '1';
            
            if ($advanceId <= 0) {
                $error = 'معرف السلفة غير صحيح';
            } else {
                $advance = $db->queryOne("SELECT * FROM salary_advances WHERE id = ?", [$advanceId]);
                
                if (!$advance) {
                    $error = 'السلفة غير موجودة';
                } elseif ($advance['status'] !== 'pending') {
                    $error = 'تمت معالجة هذا الطلب بالفعل';
                } elseif (!empty($advance['deducted_from_salary_id'])) {
                    $error = 'تم خصم هذه السلفة من الراتب بالفعل.';
                } else {
                    // إذا كان المحاسب يريد الموافقة النهائية والخصم مباشرة
                    if ($finalApproval && $currentUser['role'] === 'accountant') {
                        $resolution = salaryAdvanceResolveSalary($advance, $db);
                        if (!($resolution['success'] ?? false)) {
                            $error = $resolution['message'] ?? 'تعذر تحديد الراتب المناسب لخصم السلفة.';
                        } else {
                            $salaryData = $resolution['salary'];
                            $salaryId = (int) ($resolution['salary_id'] ?? 0);
                            $month = $resolution['month'] ?? date('n');
                            $year = $resolution['year'] ?? date('Y');

                            if ($salaryId <= 0) {
                                $error = 'تعذر تحديد الراتب المراد الخصم منه.';
                            } else {
                                // حساب الراتب الإجمالي قبل خصم السلفة باستخدام نفس الدالة المستخدمة في صفحة my_salary
                                $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$advance['user_id']]);
                                $userRole = $user['role'] ?? 'production';
                                
                                // إنشاء نسخة من salaryData بدون السلفة لحساب الراتب الإجمالي قبل الخصم
                                $salaryRecordForCalculation = $salaryData;
                                // إزالة السلفة من deductions إذا كانت موجودة
                                $currentDeductions = $salaryData['deductions'] ?? 0;
                                $currentAdvancesDeduction = $salaryData['advances_deduction'] ?? 0;
                                if ($currentAdvancesDeduction > 0) {
                                    $salaryRecordForCalculation['deductions'] = $currentDeductions - $currentAdvancesDeduction;
                                } else {
                                    $salaryRecordForCalculation['deductions'] = max(0, $currentDeductions - $advance['amount']);
                                }
                                
                                $salaryCalculation = calculateTotalSalaryWithCollections(
                                    $salaryRecordForCalculation,
                                    $advance['user_id'],
                                    $month,
                                    $year,
                                    $userRole
                                );
                                $totalSalaryBeforeAdvance = $salaryCalculation['total_salary'];
                                
                                try {
                                    $db->beginTransaction();

                                    $deductionResult = salaryAdvanceApplyDeduction($advance, $salaryData, $db);
                                    if (!($deductionResult['success'] ?? false)) {
                                        $message = $deductionResult['message'] ?? 'تعذر تطبيق الخصم على الراتب.';
                                        throw new Exception($message);
                                    }

                                    // تحديث حالة السلفة إلى manager_approved مع تعيين المحاسب كموافق نهائي وحفظ الراتب الإجمالي قبل الخصم
                                    $db->execute(
                                        "UPDATE salary_advances 
                                         SET status = 'manager_approved', 
                                             accountant_approved_by = ?, 
                                             accountant_approved_at = NOW(), 
                                             manager_approved_by = ?, 
                                             manager_approved_at = NOW(),
                                             deducted_from_salary_id = ?,
                                             total_salary_before_advance = ?
                                         WHERE id = ?",
                                        [$currentUser['id'], $currentUser['id'], $salaryId, $totalSalaryBeforeAdvance, $advanceId]
                                    );

                                    // تسجيل السلفة في accountant_transactions كـ payment (تسوية راتب معتمدة) لخصمها من خزنة الشركة
                                    $user = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$advance['user_id']]);
                                    $employeeName = $user['full_name'] ?? $user['username'] ?? 'غير محدد';
                                    $advanceDescription = 'تسوية راتب - سلفة موظف: ' . $employeeName . 
                                                         ' (السلفة #' . $advanceId . ')';
                                    $referenceNumber = 'ADV-' . $advanceId . '-' . date('YmdHis');
                                    
                                    // التأكد من وجود جدول accountant_transactions
                                    $accountantTableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                                    if (!empty($accountantTableCheck)) {
                                        $db->execute(
                                            "INSERT INTO accountant_transactions 
                                                (transaction_type, amount, description, reference_number, 
                                                 status, approved_by, created_by, approved_at)
                                             VALUES (?, ?, ?, ?, 'approved', ?, ?, NOW())",
                                            [
                                                'payment',
                                                $advance['amount'],
                                                $advanceDescription,
                                                $referenceNumber,
                                                $currentUser['id'],
                                                $currentUser['id']
                                            ]
                                        );
                                    }

                                    $db->commit();
                                } catch (Throwable $approvalError) {
                                    $db->rollback();
                                    $error = $approvalError->getMessage() ?: 'تعذر إتمام الموافقة على السلفة.';
                                }

                                if (empty($error)) {
                                    logAudit($currentUser['id'], 'accountant_final_approve_advance', 'salary_advance', $advanceId, null, [
                                        'user_id' => $advance['user_id'],
                                        'amount' => $advance['amount'],
                                        'salary_id' => $salaryId
                                    ]);
                                    
                                    createNotification(
                                        $advance['user_id'],
                                        'تمت الموافقة على طلب السلفة',
                                        "تمت الموافقة على طلب السلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م. وتم خصمها من راتبك الحالي.",
                                        'success',
                                        null,
                                        false
                                    );
                                    
                                    // إعادة توجيه مع رابط طباعة الفاتورة
                                    $printUrl = getRelativeUrl('print_advance.php?id=' . $advanceId);
                                    $success = 'تمت الموافقة على السلفة وتم خصمها من الراتب الحالي. <a href="' . $printUrl . '" target="_blank" class="alert-link">طباعة الفاتورة</a>';
                                }
                            }
                        }
                    } else {
                        // الموافقة الأولية فقط (الاستلام) - السلوك القديم
                        $db->execute(
                            "UPDATE salary_advances 
                             SET status = 'accountant_approved', 
                                 accountant_approved_by = ?, 
                                 accountant_approved_at = NOW() 
                             WHERE id = ?",
                            [$currentUser['id'], $advanceId]
                        );
                        
                        logAudit($currentUser['id'], 'accountant_approve_advance', 'salary_advance', $advanceId, null, [
                            'user_id' => $advance['user_id'],
                            'amount' => $advance['amount']
                        ]);
                        
                        // إرسال إشعار للمدير
                        $managers = $db->query("SELECT id FROM users WHERE role = 'manager' AND status = 'active'");
                        $managerAdvancesLink = $buildViewUrl('advances');
                        foreach ($managers as $manager) {
                            createNotification(
                                $manager['id'],
                                'طلب سلفة يحتاج موافقتك',
                                "طلب سلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م يحتاج موافقتك النهائية.",
                                'warning',
                                $managerAdvancesLink,
                                false
                            );
                        }
                        
                        $success = 'تم استلام طلب السلفة وإرساله للمدير للموافقة النهائية.';
                    }
                }
            }
        }
    }
    
    // موافقة المدير على السلفة
    elseif ($action === 'manager_approve' || $action === 'manager_approve_advance') {
        if ($currentUser['role'] !== 'manager') {
            $error = 'غير مصرح لك بهذا الإجراء';
        } else {
            $advanceId = intval($_POST['advance_id'] ?? 0);
            
            if ($advanceId <= 0) {
                $error = 'معرف السلفة غير صحيح';
            } else {
                $advance = $db->queryOne("SELECT * FROM salary_advances WHERE id = ?", [$advanceId]);
                
                if (!$advance) {
                    $error = 'السلفة غير موجودة';
                } elseif (!in_array($advance['status'], ['pending', 'accountant_approved'], true)) {
                    $error = 'لا يمكن الموافقة على الطلب في حالته الحالية';
                } elseif (!empty($advance['deducted_from_salary_id'])) {
                    $error = 'تم خصم هذه السلفة من الراتب بالفعل.';
                } else {
                    $resolution = salaryAdvanceResolveSalary($advance, $db);
                    if (!($resolution['success'] ?? false)) {
                        $error = $resolution['message'] ?? 'تعذر تحديد الراتب المناسب لخصم السلفة.';
                    } else {
                        $salaryData = $resolution['salary'];
                        $salaryId = (int) ($resolution['salary_id'] ?? 0);
                        $month = $resolution['month'] ?? date('n');
                        $year = $resolution['year'] ?? date('Y');

                        if ($salaryId <= 0) {
                            $error = 'تعذر تحديد الراتب المراد الخصم منه.';
                        } else {
                            // حساب الراتب الإجمالي قبل خصم السلفة باستخدام نفس الدالة المستخدمة في صفحة my_salary
                            $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$advance['user_id']]);
                            $userRole = $user['role'] ?? 'production';
                            
                            // إنشاء نسخة من salaryData بدون السلفة لحساب الراتب الإجمالي قبل الخصم
                            $salaryRecordForCalculation = $salaryData;
                            // إزالة السلفة من deductions إذا كانت موجودة
                            $currentDeductions = $salaryData['deductions'] ?? 0;
                            $currentAdvancesDeduction = $salaryData['advances_deduction'] ?? 0;
                            if ($currentAdvancesDeduction > 0) {
                                $salaryRecordForCalculation['deductions'] = $currentDeductions - $currentAdvancesDeduction;
                            } else {
                                $salaryRecordForCalculation['deductions'] = max(0, $currentDeductions - $advance['amount']);
                            }
                            
                            $salaryCalculation = calculateTotalSalaryWithCollections(
                                $salaryRecordForCalculation,
                                $advance['user_id'],
                                $month,
                                $year,
                                $userRole
                            );
                            $totalSalaryBeforeAdvance = $salaryCalculation['total_salary'];
                            
                            try {
                                $db->beginTransaction();

                                $deductionResult = salaryAdvanceApplyDeduction($advance, $salaryData, $db);
                                if (!($deductionResult['success'] ?? false)) {
                                    $message = $deductionResult['message'] ?? 'تعذر تطبيق الخصم على الراتب.';
                                    throw new Exception($message);
                                }

                                if ($advance['status'] === 'pending') {
                                    $db->execute(
                                        "UPDATE salary_advances 
                                         SET status = 'manager_approved', 
                                             accountant_approved_by = ?, 
                                             accountant_approved_at = NOW(), 
                                             manager_approved_by = ?, 
                                             manager_approved_at = NOW(),
                                             deducted_from_salary_id = ?,
                                             total_salary_before_advance = ?
                                         WHERE id = ?",
                                        [$currentUser['id'], $currentUser['id'], $salaryId, $totalSalaryBeforeAdvance, $advanceId]
                                    );
                                } else {
                                    $db->execute(
                                        "UPDATE salary_advances 
                                         SET status = 'manager_approved', 
                                             manager_approved_by = ?, 
                                             manager_approved_at = NOW(),
                                             deducted_from_salary_id = ?,
                                             total_salary_before_advance = ?
                                         WHERE id = ?",
                                        [$currentUser['id'], $salaryId, $totalSalaryBeforeAdvance, $advanceId]
                                    );
                                }

                                // تسجيل السلفة في accountant_transactions كـ payment (تسوية راتب معتمدة)
                                $user = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$advance['user_id']]);
                                $employeeName = $user['full_name'] ?? $user['username'] ?? 'غير محدد';
                                $advanceDescription = 'تسوية راتب - سلفة موظف: ' . $employeeName . 
                                                     ' (السلفة #' . $advanceId . ')';
                                $referenceNumber = 'ADV-' . $advanceId . '-' . date('YmdHis');
                                
                                // التأكد من وجود جدول accountant_transactions
                                $accountantTableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                                if (!empty($accountantTableCheck)) {
                                    $db->execute(
                                        "INSERT INTO accountant_transactions 
                                            (transaction_type, amount, description, reference_number, 
                                             status, approved_by, created_by, approved_at)
                                         VALUES (?, ?, ?, ?, 'approved', ?, ?, NOW())",
                                        [
                                            'payment',
                                            $advance['amount'],
                                            $advanceDescription,
                                            $referenceNumber,
                                            $currentUser['id'],
                                            $currentUser['id']
                                        ]
                                    );
                                }

                                $db->commit();
                            } catch (Throwable $approvalError) {
                                $db->rollback();
                                $error = $approvalError->getMessage() ?: 'تعذر إتمام الموافقة على السلفة.';
                            }

                            if (empty($error)) {
                                logAudit($currentUser['id'], 'manager_approve_advance', 'salary_advance', $advanceId, null, [
                                    'user_id' => $advance['user_id'],
                                    'amount' => $advance['amount'],
                                    'salary_id' => $salaryId
                                ]);
                                
                                createNotification(
                                    $advance['user_id'],
                                    'تمت الموافقة على طلب السلفة',
                                    "تمت الموافقة على طلب السلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م. وتم خصمها من راتبك الحالي.",
                                    'success',
                                    null,
                                    false
                                );
                                
                                // إعادة توجيه مع رابط طباعة الفاتورة
                                $printUrl = getRelativeUrl('print_advance.php?id=' . $advanceId);
                                $success = 'تمت الموافقة على السلفة وتم خصمها من الراتب الحالي. <a href="' . $printUrl . '" target="_blank" class="alert-link">طباعة الفاتورة</a>';
                            }
                        }
                    }
                }
            }
        }
    }
    
    // رفض السلفة
    elseif ($action === 'reject' || $action === 'reject_advance') {
        if ($currentUser['role'] !== 'accountant' && $currentUser['role'] !== 'manager') {
            $error = 'غير مصرح لك بهذا الإجراء';
        } else {
            $advanceId = intval($_POST['advance_id'] ?? 0);
            $rejectionReason = trim($_POST['rejection_reason'] ?? '');
            
            if ($advanceId <= 0) {
                $error = 'معرف السلفة غير صحيح';
            } else {
                $advance = $db->queryOne("SELECT * FROM salary_advances WHERE id = ?", [$advanceId]);
                
                if (!$advance) {
                    $error = 'السلفة غير موجودة';
                } elseif ($advance['status'] === 'manager_approved') {
                    $error = 'لا يمكن رفض سلفة تمت الموافقة عليها';
                } else {
                    $db->execute(
                        "UPDATE salary_advances 
                         SET status = 'rejected', 
                             notes = ? 
                         WHERE id = ?",
                        [$rejectionReason ?: 'تم رفض الطلب', $advanceId]
                    );
                    
                    logAudit($currentUser['id'], 'reject_advance', 'salary_advance', $advanceId, null, [
                        'user_id' => $advance['user_id'],
                        'amount' => $advance['amount']
                    ]);
                    
                    // إرسال إشعار للموظف
                    createNotification(
                        $advance['user_id'],
                        'تم رفض طلب السلفة',
                        "تم رفض طلب السلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م. السبب: " . ($rejectionReason ?: 'غير محدد'),
                        'error',
                        null,
                        false
                    );
                    
                    // الحصول على معاملات URL للحفاظ عليها
                    $month = intval($_POST['month'] ?? $selectedMonth);
                    $year = intval($_POST['year'] ?? $selectedYear);
                    $view = trim($_POST['view'] ?? 'advances');
                    
                    // حفظ رسالة النجاح في session
                    $_SESSION['salaries_success'] = 'تم رفض السلفة بنجاح';
                    
                    // إعادة التوجيه مع الحفاظ على معاملات URL
                    $redirectUrl = $buildViewUrl($view, ['month' => $month, 'year' => $year]);
                    if (!headers_sent()) {
                        header('Location: ' . $redirectUrl);
                        exit;
                    } else {
                        echo '<script>window.location.href = ' . json_encode($redirectUrl, JSON_UNESCAPED_SLASHES) . ';</script>';
                        exit;
                    }
                }
            }
        }
    } elseif ($action === 'settle_salary') {
        // معالجة تسوية مستحقات الموظف
        // استخدام selected_salary_id إذا كان موجوداً (من قائمة الرواتب)، وإلا استخدام salary_id
        $salaryId = intval($_POST['selected_salary_id'] ?? $_POST['salary_id'] ?? 0);
        $settlementAmount = floatval($_POST['settlement_amount'] ?? 0);
        $settlementDate = trim($_POST['settlement_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');
        
        if ($salaryId <= 0) {
            $_SESSION['salaries_error'] = 'معرف الراتب غير صحيح';
            $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
            header('Location: ' . $redirectUrl);
            exit;
        } elseif ($settlementAmount <= 0) {
            $_SESSION['salaries_error'] = 'يجب إدخال مبلغ تسوية صحيح';
            $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            // الحصول على بيانات الراتب
            $salary = $db->queryOne(
                "SELECT s.*, u.full_name, u.username, u.role 
                 FROM salaries s 
                 LEFT JOIN users u ON s.user_id = u.id 
                 WHERE s.id = ?",
                [$salaryId]
            );
            
            if (!$salary) {
                $_SESSION['salaries_error'] = 'الراتب غير موجود';
                $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                // الحصول على المبلغ التراكمي الحالي - استخدام نفس الدالة المستخدمة في الواجهة
                $userId = intval($salary['user_id'] ?? 0);
                $salaryMonth = intval($salary['month'] ?? $selectedMonth);
                $salaryYear = intval($salary['year'] ?? $selectedYear);
                
                // حساب الراتب الإجمالي الحالي (نفس الطريقة المستخدمة في بطاقة الموظف)
                require_once __DIR__ . '/../../includes/salary_calculator.php';
                
                // في بطاقة الموظف، يتم دائماً حساب الراتب من المكونات (حتى لو كان total_amount موجوداً)
                // لضمان التطابق مع بطاقة الموظف
                $baseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
                $bonus = cleanFinancialValue($salary['bonus_standardized'] ?? ($salary['bonus'] ?? $salary['bonuses'] ?? 0));
                $deductions = cleanFinancialValue($salary['deductions'] ?? 0);
                $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                
                // إعادة حساب الراتب الإجمالي من المكونات لضمان الدقة (مطابق لبطاقة الموظف)
                // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
                $currentTotal = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
                $currentTotal = max(0, $currentTotal);
                
                // استخدام الدالة المشتركة لحساب المبلغ التراكمي (نفس المستخدم في الواجهة)
                $accumulatedData = calculateSalaryAccumulatedAmount(
                    $userId, 
                    $salaryId, 
                    $currentTotal, 
                    $salaryMonth, 
                    $salaryYear, 
                    $db
                );
                
                $currentAccumulated = $accumulatedData['accumulated'];
                // حساب المتبقي بناءً على settlements_advances وليس paid_amount لتجنب المضاعفة
                $currentSettlementsAdvances = cleanFinancialValue($salary['settlements_advances'] ?? 0);
                
                // التحقق من أن settlements_advances لا يتجاوز accumulated_amount
                // إذا كان هناك خطأ في البيانات (settlements_advances > accumulated_amount)، نصححه
                if ($currentSettlementsAdvances > $currentAccumulated + 0.01) {
                    error_log("settle_salary: WARNING - settlements_advances ($currentSettlementsAdvances) exceeds accumulated_amount ($currentAccumulated). This may be from a previous salary or data error.");
                    // تصحيح settlements_advances ليكون مساوياً لـ accumulated_amount كحد أقصى
                    // لكن نسمح بالتسوية إذا كان المبلغ الجديد لا يتجاوز accumulated_amount
                    $correctedSettlementsAdvances = min($currentSettlementsAdvances, $currentAccumulated);
                    $currentRemaining = max(0, $currentAccumulated - $correctedSettlementsAdvances);
                    
                    // إذا كان المبلغ المطلوب تسويته يتجاوز المتبقي الصحيح، نرفض الطلب
                    if ($settlementAmount > $currentRemaining + 0.01) {
                        $_SESSION['salaries_error'] = 'مبلغ التسوية (' . formatCurrency($settlementAmount) . ') يتجاوز المبلغ المتبقي المتاح (' . formatCurrency($currentRemaining) . ' من أصل ' . formatCurrency($currentAccumulated) . '). ملاحظة: يوجد خطأ في البيانات - التسويات والسلف (' . formatCurrency($currentSettlementsAdvances) . ') تتجاوز المبلغ التراكمي. يرجى مراجعة البيانات أولاً.';
                        error_log("settle_salary: Validation failed - settlementAmount ($settlementAmount) > currentRemaining ($currentRemaining) after correction");
                        $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                    
                    // إذا كان المبلغ المطلوب تسويته لا يتجاوز المتبقي الصحيح، نستخدم settlements_advances المصحح
                    $currentSettlementsAdvances = $correctedSettlementsAdvances;
                } else {
                    $currentRemaining = max(0, $currentAccumulated - $currentSettlementsAdvances);
                }
                
                // تسجيل المعلومات للتشخيص
                error_log("settle_salary: salaryId=$salaryId, userId=$userId, month=$salaryMonth, year=$salaryYear");
                error_log("settle_salary: currentTotal=$currentTotal, currentAccumulated=$currentAccumulated, currentSettlementsAdvances=$currentSettlementsAdvances, currentRemaining=$currentRemaining");
                error_log("settle_salary: settlementAmount=$settlementAmount");
                
                // التحقق من أن مبلغ التسوية لا يتجاوز المتبقي (وليس المبلغ التراكمي)
                if ($settlementAmount > $currentRemaining + 0.01) { // إضافة 0.01 للتسامح مع الأخطاء الحسابية الطفيفة
                    $_SESSION['salaries_error'] = 'مبلغ التسوية (' . formatCurrency($settlementAmount) . ') يتجاوز المبلغ المتبقي المتاح (' . formatCurrency($currentRemaining) . ' من أصل ' . formatCurrency($currentAccumulated) . ' - تسويات وسلف: ' . formatCurrency($currentSettlementsAdvances) . ')';
                    error_log("settle_salary: Validation failed - settlementAmount ($settlementAmount) > currentRemaining ($currentRemaining)");
                    $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
                    header('Location: ' . $redirectUrl);
                    exit;
                } else {
                    // التحقق من رصيد خزنة الشركة قبل التسوية
                    $companyBalance = calculateCompanyCashBalance($db);
                    
                    if ($settlementAmount > $companyBalance) {
                        $_SESSION['salaries_error'] = 'رصيد خزنة الشركة غير كافي. الرصيد المتاح: ' . formatCurrency($companyBalance) . 
                                                       ' | المبلغ المطلوب: ' . formatCurrency($settlementAmount);
                        $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                    
                    try {
                        $db->beginTransaction();
                        
                        // حساب المتبقي بعد التسوية (من المتبقي الحالي، وليس من المبلغ التراكمي)
                        $remainingAfter = max(0, $currentRemaining - $settlementAmount);
                        $settlementType = ($remainingAfter <= 0.01) ? 'full' : 'partial';
                        
                        // التحقق المنطقي: التأكد من أن المتبقي بعد التسوية لا يكون سالباً
                        if ($remainingAfter < -0.01) {
                            throw new Exception('خطأ في الحساب: المتبقي بعد التسوية سالب (' . formatCurrency($remainingAfter) . ')');
                        }
                        
                        // تحديث settlements_advances فقط (لا نستخدم paid_amount لتجنب المضاعفة)
                        $newSettlementsAdvances = $currentSettlementsAdvances + $settlementAmount;
                        
                        // التحقق المنطقي: التأكد من أن التسويات والسلف لا تتجاوز المبلغ التراكمي
                        if ($newSettlementsAdvances > $currentAccumulated + 0.01) {
                            // إذا كان المبلغ الجديد يتجاوز المبلغ التراكمي، نحدده إلى المبلغ التراكمي
                            // هذا يحدث عادة عندما يكون settlements_advances من راتب سابق
                            error_log("settle_salary: WARNING - newSettlementsAdvances ($newSettlementsAdvances) would exceed accumulated_amount ($currentAccumulated). Limiting to accumulated_amount.");
                            $newSettlementsAdvances = $currentAccumulated;
                        }
                        
                        // تحديث settlements_advances فقط (accumulated_amount يبقى كما هو)
                        $db->execute(
                            "UPDATE salaries SET 
                                settlements_advances = ?
                             WHERE id = ?",
                            [$newSettlementsAdvances, $salaryId]
                        );
                        
                        // إنشاء سجل التسوية
                        $db->execute(
                            "INSERT INTO salary_settlements 
                                (salary_id, user_id, settlement_amount, previous_accumulated, 
                                 remaining_after_settlement, settlement_type, settlement_date, 
                                 notes, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $salaryId,
                                $salary['user_id'],
                                $settlementAmount,
                                $currentAccumulated,
                                $remainingAfter,
                                $settlementType,
                                $settlementDate,
                                $notes,
                                $currentUser['id']
                            ]
                        );
                        
                        $settlementId = $db->getLastInsertId();
                        
                        // تسجيل تسوية الراتب في accountant_transactions كـ payment (دفعة معتمدة)
                        $user = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$salary['user_id']]);
                        $employeeName = $user['full_name'] ?? $user['username'] ?? 'غير محدد';
                        $settlementDescription = 'تسوية راتب موظف: ' . $employeeName . 
                                                 ' (تسوية #' . $settlementId . ' - راتب #' . $salaryId . ')';
                        $referenceNumber = 'SAL-SETTLE-' . $settlementId . '-' . date('YmdHis');
                        
                        // التأكد من وجود جدول accountant_transactions
                        $accountantTableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                        if (!empty($accountantTableCheck)) {
                            $db->execute(
                                "INSERT INTO accountant_transactions 
                                    (transaction_type, amount, description, reference_number, 
                                     status, approved_by, created_by, approved_at)
                                 VALUES (?, ?, ?, ?, 'approved', ?, ?, NOW())",
                                [
                                    'payment',
                                    $settlementAmount,
                                    $settlementDescription,
                                    $referenceNumber,
                                    $currentUser['id'],
                                    $currentUser['id']
                                ]
                            );
                        }
                        
                        // إنشاء فاتورة PDF
                        require_once __DIR__ . '/../../includes/invoices.php';
                        $invoicePath = generateSalarySettlementInvoice($settlementId, $salary, $settlementAmount, $currentAccumulated, $remainingAfter, $settlementDate, $notes);
                        
                        // تحديث مسار الفاتورة
                        if ($invoicePath) {
                            $db->execute(
                                "UPDATE salary_settlements SET invoice_path = ? WHERE id = ?",
                                [$invoicePath, $settlementId]
                            );
                        }
                        
                        // إعادة جلب بيانات الراتب من قاعدة البيانات مباشرة (بدون كاش) لضمان الحصول على القيم الصحيحة
                        // هذا يضمن أن الأرقام في رسالة Telegram تطابق الأرقام في بطاقة الموظف
                        $updatedSalary = $db->queryOne(
                            "SELECT s.*, u.full_name, u.username, u.role 
                             FROM salaries s 
                             LEFT JOIN users u ON s.user_id = u.id 
                             WHERE s.id = ?",
                            [$salaryId]
                        );
                        
                        if ($updatedSalary) {
                            // إعادة حساب المبالغ باستخدام نفس الطريقة المستخدمة في بطاقة الموظف
                            // في بطاقة الموظف، يتم دائماً حساب الراتب من المكونات (حتى لو كان total_amount موجوداً)
                            // لضمان التطابق مع بطاقة الموظف
                            $baseAmount = cleanFinancialValue($updatedSalary['base_amount'] ?? 0);
                            $bonus = cleanFinancialValue($updatedSalary['bonus_standardized'] ?? ($updatedSalary['bonus'] ?? $updatedSalary['bonuses'] ?? 0));
                            $deductions = cleanFinancialValue($updatedSalary['deductions'] ?? 0);
                            $collectionsBonus = cleanFinancialValue($updatedSalary['collections_bonus'] ?? 0);
                            
                            // إعادة حساب الراتب الإجمالي من المكونات لضمان الدقة (مطابق لبطاقة الموظف)
                            // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
                            $updatedTotal = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
                            $updatedTotal = max(0, $updatedTotal);
                            
                            // إعادة حساب المبلغ التراكمي باستخدام نفس الدالة المستخدمة في بطاقة الموظف
                            $updatedAccumulatedData = calculateSalaryAccumulatedAmount(
                                $userId, 
                                $salaryId, 
                                $updatedTotal, 
                                $salaryMonth, 
                                $salaryYear, 
                                $db
                            );
                            
                            $updatedAccumulated = $updatedAccumulatedData['accumulated'];
                            $updatedSettlementsAdvances = cleanFinancialValue($updatedSalary['settlements_advances'] ?? 0);
                            $updatedRemainingAfter = max(0, $updatedAccumulated - $updatedSettlementsAdvances);
                            
                            // استخدام القيم المحدثة في رسالة Telegram
                            // المبلغ التراكمي لا يتغير بعد التسوية (فقط settlements_advances يتغير)
                            // لذا $updatedAccumulated يجب أن يكون نفس $currentAccumulated (لأن accumulated_amount لا يتغير)
                            // لكن للتأكد من التطابق مع بطاقة الموظف، نستخدم $updatedAccumulated المحسوب من البيانات المحدثة
                            // و $currentAccumulated كـ previousAccumulated (قبل التسوية) لضمان التطابق مع بطاقة الموظف
                            $telegramAccumulated = $currentAccumulated; // المبلغ التراكمي قبل التسوية (لضمان التطابق مع بطاقة الموظف)
                            $telegramRemainingAfter = $updatedRemainingAfter; // المتبقي بعد التسوية (من البيانات المحدثة)
                            
                            // التحقق من أن $updatedAccumulated مطابق لـ $currentAccumulated (لأن accumulated_amount لا يتغير)
                            // إذا كان هناك اختلاف، نستخدم $currentAccumulated لضمان التطابق
                            if (abs($updatedAccumulated - $currentAccumulated) > 0.01) {
                                error_log("settle_salary: WARNING - updatedAccumulated ($updatedAccumulated) differs from currentAccumulated ($currentAccumulated). Using currentAccumulated for Telegram.");
                                $telegramAccumulated = $currentAccumulated;
                            }
                        } else {
                            // إذا فشل جلب البيانات المحدثة، استخدم القيم المحسوبة سابقاً
                            $telegramAccumulated = $currentAccumulated;
                            $telegramRemainingAfter = $remainingAfter;
                            $updatedSalary = $salary; // استخدام البيانات القديمة كبديل
                        }
                        
                        // إرسال الفاتورة إلى تليجرام باستخدام القيم المحدثة
                        require_once __DIR__ . '/../../includes/simple_telegram.php';
                        $telegramSent = sendSalarySettlementToTelegram($settlementId, $updatedSalary, $settlementAmount, $telegramAccumulated, $telegramRemainingAfter, $settlementType, $settlementDate, $invoicePath);
                        
                        if ($telegramSent) {
                            $db->execute(
                                "UPDATE salary_settlements SET telegram_sent = 1 WHERE id = ?",
                                [$settlementId]
                            );
                        }
                        
                        $db->commit();
                        
                        // مسح الكاش بعد تسوية المستحقات لضمان ظهور التعديلات بشكل لحظي
                        if (class_exists('Cache')) {
                            // مسح كاش الراتب المحدد
                            Cache::forget("salary_{$salaryId}");
                            Cache::forget("salary_user_{$userId}");
                            Cache::forget("salary_user_{$userId}_{$salaryMonth}_{$salaryYear}");
                            // مسح كاش قائمة الرواتب
                            Cache::flush();
                        }
                        
                        logAudit($currentUser['id'], 'settle_salary', 'salary', $salaryId, null, [
                            'settlement_amount' => $settlementAmount,
                            'previous_accumulated' => $currentAccumulated,
                            'remaining' => $remainingAfter,
                            'settlement_type' => $settlementType,
                            'company_balance_before' => $companyBalance,
                            'company_balance_after' => $companyBalance - $settlementAmount
                        ]);
                        
                        $_SESSION['salaries_success'] = 'تم تسوية مستحقات الموظف بنجاح. المبلغ المسدد: ' . formatCurrency($settlementAmount) . 
                                   ($remainingAfter > 0 ? ' | المتبقي: ' . formatCurrency($remainingAfter) : '') .
                                   ' | رصيد الخزنة بعد التسوية: ' . formatCurrency($companyBalance - $settlementAmount);
                        
                        // إضافة versioning parameter لضمان عدم عرض كاش قديم
                        $version = time();
                        $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear, '_v' => $version]);
                        header('Location: ' . $redirectUrl);
                        exit;
                        
                    } catch (Exception $e) {
                        $db->rollback();
                        error_log('Error settling salary: ' . $e->getMessage());
                        $_SESSION['salaries_error'] = 'حدث خطأ أثناء تسوية المستحقات: ' . $e->getMessage();
                        $redirectUrl = $buildViewUrl($view, ['month' => $selectedMonth, 'year' => $selectedYear]);
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                }
            }
        }
    }
}

// الحصول على قائمة المستخدمين (استبعاد المديرين) - فقط الأدوار التي لها رواتب
$usersQuery = "SELECT id, username, full_name, hourly_rate, role 
               FROM users 
               WHERE status = 'active' 
               AND role != 'manager'
               AND role IN ('production', 'accountant', 'sales')";
               
if ($selectedUserId > 0) {
    $usersQuery .= " AND id = " . intval($selectedUserId);
}

$usersQuery .= " ORDER BY 
    CASE role 
        WHEN 'production' THEN 1
        WHEN 'accountant' THEN 2
        WHEN 'sales' THEN 3
        ELSE 4
    END,
    full_name ASC";

$users = $db->query($usersQuery);

// إنشاء رواتب تلقائياً لأول مرة كل شهر للموظفين الذين لا يملكون رواتب
// يتم ذلك مرة واحدة فقط لكل شهر باستخدام createOrUpdateSalary (تتضمن حماية من التكرار)
// استخدام session flag لمنع التنفيذ المتكرر (infinite loop protection)
$autoCreateKey = "auto_created_salaries_{$selectedYear}_{$selectedMonth}";

// التحقق من أن هذا لم يتم تنفيذه من قبل في هذه الجلسة
// وأيضاً التحقق من أن الطلب ليس POST (لأن POST requests قد تسبب redirects)
// السماح بإعادة المحاولة إذا تم تمرير retry_auto_create=1
$isPostRequest = ($_SERVER['REQUEST_METHOD'] === 'POST');
$retryRequested = isset($_GET['retry_auto_create']) && $_GET['retry_auto_create'] == '1';

// إذا طُلب إعادة المحاولة، أزل session flags
if ($retryRequested) {
    unset($_SESSION[$autoCreateKey]);
    unset($_SESSION[$autoCreateKey . '_count']);
    unset($_SESSION[$autoCreateKey . '_time']);
    error_log("Retry requested for auto-creation of salaries for {$selectedMonth}/{$selectedYear}");
}

$shouldAutoCreate = !$isPostRequest && !isset($_SESSION[$autoCreateKey]);

if ($shouldAutoCreate) {
    require_once __DIR__ . '/../../includes/salary_calculator.php';
    
    // التحقق من وجود عمود year و نوع عمود month
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    $isMonthDate = stripos($monthType, 'date') !== false;
    
    // التحقق من أن الشهر والسنة صحيحين - تحقق صارم لمنع التواريخ الصفرية
    if ($selectedMonth >= 1 && $selectedMonth <= 12 && $selectedYear >= 2000 && $selectedYear <= 2100) {
        $createdCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $totalUsers = count($users);
        
        // إعداد التاريخ الصحيح للبحث والإدراج
        $targetDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
        $targetYearMonth = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
        
        error_log("=== Auto-creating salaries for month {$selectedMonth}/{$selectedYear} (targetDate: {$targetDate}) - Total users: {$totalUsers} ===");
        
        foreach ($users as $user) {
        $userId = intval($user['id']);
        $hourlyRate = cleanFinancialValue($user['hourly_rate'] ?? 0);
        $userName = $user['full_name'] ?? $user['username'] ?? "User {$userId}";
        
        // تخطي المستخدمين بدون سعر ساعة
        if ($hourlyRate <= 0) {
            error_log("Skipping user {$userId} ({$userName}): hourly_rate is 0 or empty");
            $skippedCount++;
            continue;
        }
        
        // التحقق من وجود راتب للمستخدم في الشهر المحدد
        // استخدام نفس منطق التحقق الموجود في createOrUpdateSalary لضمان الاتساق
        $hasSalary = false;
        $existingSalary = null;
        try {
                // استخدام transaction مع SELECT FOR UPDATE لمنع race conditions
                $conn = $db->getConnection();
                // التحقق من وجود property in_transaction (متوفر في PHP 8.1+)
                $wasInTransaction = property_exists($conn, 'in_transaction') ? $conn->in_transaction : false;
                
                if (!$wasInTransaction) {
                    $conn->begin_transaction();
                }
                
                // البحث بناءً على نوع عمود month - نفس المنطق في createOrUpdateSalary
                if ($isMonthDate) {
                    // عمود month من نوع DATE - البحث باستخدام DATE_FORMAT مع FOR UPDATE
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ? AND month != '0000-00-00' AND month IS NOT NULL LIMIT 1 FOR UPDATE",
                        [$userId, $targetYearMonth]
                    );
                    $hasSalary = !empty($existingSalary);
                } elseif ($hasYearColumn) {
                    // عمود month من نوع INT مع وجود عمود year منفصل
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ? AND month > 0 AND year > 0 LIMIT 1 FOR UPDATE",
                        [$userId, $selectedMonth, $selectedYear]
                    );
                    $hasSalary = !empty($existingSalary);
                } else {
                    // عمود month من نوع INT بدون عمود year
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND month > 0 AND month <= 12 LIMIT 1 FOR UPDATE",
                        [$userId, $selectedMonth]
                    );
                    $hasSalary = !empty($existingSalary);
                }
                
                // إذا لم يكن في transaction من قبل، نغلق transaction الآن
                $isInTransaction = property_exists($conn, 'in_transaction') ? $conn->in_transaction : false;
                if (!$wasInTransaction && $isInTransaction) {
                    $conn->commit();
                }
            } catch (Exception $checkEx) {
                // في حالة الخطأ في التحقق، نغلق transaction إذا كنا قد بدأناه
                try {
                    $conn = $db->getConnection();
                    $isInTransaction = property_exists($conn, 'in_transaction') ? $conn->in_transaction : false;
                    if ($isInTransaction && !$wasInTransaction) {
                        $conn->rollback();
                    }
                } catch (Exception $rollbackEx) {
                    error_log("Failed to rollback transaction in salary check: " . $rollbackEx->getMessage());
                }
                error_log("Error checking for existing salary for user {$userId}: " . $checkEx->getMessage());
                $hasSalary = false; // نعتبر أنه لا يوجد راتب في حالة الخطأ
            }
            
            // إذا لم يكن للمستخدم راتب في هذا الشهر، قم بإنشائه
            try {
                if (!$hasSalary) {
                    error_log("User {$userId} ({$userName}) has no salary for {$selectedMonth}/{$selectedYear}, creating...");
                    
                    // استخدام createOrUpdateSalary التي تحتوي على حماية من التكرار
                    $result = createOrUpdateSalary(
                        $userId,
                        $selectedMonth,
                        $selectedYear,
                        0, // bonus
                        0, // deductions
                        'إنشاء تلقائي عند فتح صفحة الرواتب - أول مرة في الشهر'
                    );
                    
                    if ($result['success']) {
                        $createdCount++;
                        $salaryId = $result['salary_id'] ?? 'N/A';
                        error_log("✓ Successfully created salary for user {$userId} ({$userName}), salary_id: {$salaryId}");
                    } else {
                        $errorCount++;
                        $errorMsg = $result['message'] ?? 'Unknown error';
                        error_log("✗ Failed to create salary for user {$userId} ({$userName}): {$errorMsg}");
                    }
                } else {
                    $salaryId = $existingSalary['id'] ?? 'N/A';
                    error_log("User {$userId} ({$userName}) already has salary (ID: {$salaryId}) for {$selectedMonth}/{$selectedYear}, skipping");
                }
            } catch (Exception $e) {
                $errorCount++;
                error_log("✗ Exception while processing user {$userId} ({$userName}): " . $e->getMessage());
            } catch (Throwable $e) {
                $errorCount++;
                error_log("✗ Fatal error while processing user {$userId} ({$userName}): " . $e->getMessage());
            }
    }
    
        // تسجيل ملخص العملية
        error_log("=== Auto-create summary: Created={$createdCount}, Skipped={$skippedCount}, Errors={$errorCount}, Total={$totalUsers} ===");
        
        // تعيين session flag لمنع التنفيذ المتكرر - مهم جداً: يتم تعيينه دائماً حتى لو كان createdCount = 0
        $_SESSION[$autoCreateKey] = true;
        $_SESSION[$autoCreateKey . '_count'] = $createdCount;
        $_SESSION[$autoCreateKey . '_time'] = time();
        $_SESSION[$autoCreateKey . '_completed'] = true; // علامة إضافية لتأكيد اكتمال العملية
        
        // إظهار رسالة نجاح للمستخدم (إذا تم إنشاء رواتب)
        if ($createdCount > 0 && empty($success) && empty($error)) {
            $success = "تم إنشاء {$createdCount} راتب تلقائياً للشهر الحالي";
        } elseif ($createdCount > 0 && !empty($success)) {
            $success .= " | تم إنشاء {$createdCount} راتب تلقائياً";
        }
    } else {
        error_log("Invalid month/year for auto-creation: month={$selectedMonth}, year={$selectedYear}");
        // تعيين session flag حتى في حالة الخطأ لمنع التكرار
        $_SESSION[$autoCreateKey] = true;
        $_SESSION[$autoCreateKey . '_count'] = 0;
        $_SESSION[$autoCreateKey . '_time'] = time();
        $_SESSION[$autoCreateKey . '_completed'] = true;
    }
} else {
    // تم تنفيذ العملية من قبل في هذه الجلسة - لا نعيد المحاولة تلقائياً
    $previousCount = $_SESSION[$autoCreateKey . '_count'] ?? 0;
    $previousTime = $_SESSION[$autoCreateKey . '_time'] ?? 0;
    $isCompleted = $_SESSION[$autoCreateKey . '_completed'] ?? false;
    
    if ($previousCount > 0 || $isCompleted) {
        $timeAgo = time() - $previousTime;
        $minutesAgo = round($timeAgo / 60);
        error_log("Auto-creation already executed for {$selectedMonth}/{$selectedYear} in this session (created {$previousCount} salaries {$minutesAgo} minutes ago)");
        
        // لا نقوم بإعادة التوجيه التلقائي لتجنب التكرار اللانهائي
        // المستخدم يمكنه استخدام retry_auto_create=1 يدوياً إذا أراد
    } else {
        error_log("Auto-creation already executed for {$selectedMonth}/{$selectedYear} in this session but no count was recorded");
    }
}

// الحصول على الرواتب للشهر المحدد مع فلترة
// التحقق من نوع عمود month أولاً - هذا مهم جداً
$monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
$monthType = $monthColumnCheck['Type'] ?? '';
$isMonthDate = stripos($monthType, 'date') !== false;

// التحقق من وجود عمود year
$yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
$hasYearColumn = !empty($yearColumnCheck);

$whereConditions = [];
$params = [];

// إعداد التاريخ الصحيح للبحث
$targetYearMonth = sprintf('%04d-%02d', $selectedYear, $selectedMonth);

// بناء استعلام WHERE بناءً على نوع عمود month
if ($isMonthDate) {
    // عمود month من نوع DATE - البحث باستخدام DATE_FORMAT
    $whereConditions[] = "DATE_FORMAT(s.month, '%Y-%m') = ? AND s.month != '0000-00-00' AND s.month IS NOT NULL";
    $params[] = $targetYearMonth;
    
    // إذا كان عمود year موجوداً، نضيفه كشرط إضافي للتحقق
if ($hasYearColumn) {
        $whereConditions[] = "s.year = ? AND s.year > 0";
        $params[] = $selectedYear;
    }
} elseif ($hasYearColumn) {
    // عمود month من نوع INT مع وجود عمود year منفصل
    $whereConditions[] = "s.month = ? AND s.year = ? AND s.month > 0 AND s.year > 0";
    $params[] = $selectedMonth;
    $params[] = $selectedYear;
} else {
    // عمود month من نوع INT بدون عمود year
        $whereConditions[] = "s.month = ? AND s.month > 0";
        $params[] = $selectedMonth;
}

if ($selectedUserId > 0) {
    $whereConditions[] = "s.user_id = ?";
    $params[] = $selectedUserId;
}

if ($salaryId > 0) {
    $whereConditions[] = "s.id = ?";
    $params[] = $salaryId;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// تحديد اسم عمود المكافآت الصحيح (bonus أو bonuses)
$bonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field IN ('bonus', 'bonuses')");
$bonusColumnName = $bonusColumnCheck ? $bonusColumnCheck['Field'] : 'bonus'; // افتراضي: bonus

$salariesFromDb = $db->query(
    "SELECT s.*, u.full_name, u.username, u.role, u.hourly_rate as current_hourly_rate,
            approver.full_name as approver_name
     FROM salaries s
     LEFT JOIN users u ON s.user_id = u.id
     LEFT JOIN users approver ON s.approved_by = approver.id
     $whereClause
     ORDER BY u.full_name ASC",
    $params
);

// إضافة عمود bonus_standardized لجميع السجلات لتسهيل الوصول
foreach ($salariesFromDb as &$salary) {
    $salary['bonus_standardized'] = $salary[$bonusColumnName] ?? 0;
}
unset($salary);

// تحديث total_hours تلقائياً لجميع الرواتب إذا كانت مختلفة عن القيمة الفعلية
foreach ($salariesFromDb as &$salary) {
    $userId = intval($salary['user_id'] ?? 0);
    if ($userId > 0 && !empty($salary['id'])) {
        // استخراج شهر وسنة الراتب من بيانات الراتب نفسه (وليس من الفلترة)
        $salaryMonth = null;
        $salaryYear = null;
        
        // التحقق من نوع عمود month
        $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
        $monthType = $monthColumnCheck['Type'] ?? '';
        $isMonthDate = stripos($monthType, 'date') !== false;
        
        if ($isMonthDate && !empty($salary['month'])) {
            // إذا كان month من نوع DATE، استخراج الشهر والسنة منه
            $monthDate = DateTime::createFromFormat('Y-m-d', $salary['month']);
            if ($monthDate) {
                $salaryMonth = (int)$monthDate->format('n');
                $salaryYear = (int)$monthDate->format('Y');
            }
        } else {
            // إذا كان month من نوع INT، استخدمه مباشرة
            $salaryMonth = isset($salary['month']) ? (int)$salary['month'] : null;
        }
        
        // استخراج السنة من عمود year إذا كان موجوداً
        if (isset($salary['year']) && !empty($salary['year'])) {
            $salaryYear = (int)$salary['year'];
        }
        
        // إذا لم نتمكن من استخراج الشهر والسنة، استخدم القيم من الفلترة كبديل
        if (!$salaryMonth || !$salaryYear) {
            $salaryMonth = $selectedMonth;
            $salaryYear = $selectedYear;
        }
        
        // حساب الساعات الفعلية من attendance_records باستخدام شهر وسنة الراتب
        $actualHours = calculateMonthlyHours($userId, $salaryMonth, $salaryYear);
        $savedTotalHours = floatval($salary['total_hours'] ?? 0);
        
        // إذا كانت القيمة مختلفة، قم بالتحديث
        if (abs($actualHours - $savedTotalHours) > 0.01) {
            try {
                $db->execute(
                    "UPDATE salaries SET total_hours = ? WHERE id = ?",
                    [$actualHours, $salary['id']]
                );
                // تحديث القيمة في المتغير
                $salary['total_hours'] = $actualHours;
            } catch (Exception $e) {
                error_log("Error auto-updating total_hours for salary ID {$salary['id']}: " . $e->getMessage());
            }
        }
    }
}
unset($salary); // إلغاء المرجع

// إضافة bonus_standardized لجميع السجلات في salariesFromDb
// لا نعيد حساب نسبة التحصيلات هنا - نستخدم القيمة المحفوظة في قاعدة البيانات مباشرة (مطابق لصفحة "مرتبي")
// لأن القيمة المحفوظة تتضمن 2% من collections + 2% من creditUsed من pos.php
// بينما calculateSalesCollections() تحسب فقط من collections ولا تتضمن creditUsed
foreach ($salariesFromDb as &$salary) {
    if (!isset($salary['bonus_standardized'])) {
        $salary['bonus_standardized'] = $salary[$bonusColumnName] ?? 0;
    }
}
unset($salary);

// إنشاء مصفوفة مرتبة برقم المستخدم للبحث السريع
$salariesMap = [];
foreach ($salariesFromDb as $salary) {
    $userId = intval($salary['user_id'] ?? 0);
    if ($userId > 0) {
        $salariesMap[$userId] = $salary;
    }
}

// دمج جميع المستخدمين مع رواتبهم (أو إنشاء سجل فارغ إذا لم يكن لديهم راتب)
$salaries = [];
foreach ($users as $user) {
    $userId = intval($user['id']);
    if (isset($salariesMap[$userId])) {
        // المستخدم لديه راتب مسجل
        $salaries[] = $salariesMap[$userId];
    } else {
        // المستخدم ليس لديه راتب مسجل - التحقق أولاً قبل الإنشاء
        // التحقق من وجود راتب لهذا المستخدم والشهر والسنة
        // استخدام نفس المنطق المستخدم في الاستعلام الرئيسي
        $existingSalary = null;
        
        if ($isMonthDate) {
            // عمود month من نوع DATE
            $targetYearMonth = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
            $existingSalary = $db->queryOne(
                "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ? AND month != '0000-00-00' AND month IS NOT NULL",
                [$userId, $targetYearMonth]
            );
        } elseif ($hasYearColumn) {
            // عمود month من نوع INT مع وجود عمود year
            $existingSalary = $db->queryOne(
                "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ? AND month > 0 AND year > 0",
                [$userId, $selectedMonth, $selectedYear]
            );
        } else {
            // عمود month من نوع INT بدون عمود year
            $existingSalary = $db->queryOne(
                "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND month > 0",
                [$userId, $selectedMonth]
            );
        }
        
        // إذا كان الراتب موجوداً بالفعل، استخدمه بدلاً من إنشاء جديد
        if ($existingSalary) {
            $existingSalaryData = $db->queryOne(
                "SELECT s.*, u.full_name, u.username, u.role, u.hourly_rate as current_hourly_rate,
                        approver.full_name as approver_name
                 FROM salaries s
                 LEFT JOIN users u ON s.user_id = u.id
                 LEFT JOIN users approver ON s.approved_by = approver.id
                 WHERE s.id = ?",
                [$existingSalary['id']]
            );
            if ($existingSalaryData) {
                $salaries[] = $existingSalaryData;
                continue; // تخطي إنشاء سجل جديد
            }
        }
        
        // لا نقوم بإنشاء رواتب تلقائياً عند فتح الصفحة لمنع التكرار
        // يجب على المستخدم استخدام زر "حساب جميع الرواتب" لإنشاء الرواتب بشكل صريح
        // هذا يمنع إنشاء رواتب مكررة عند فتح الصفحة أو تحديثها
        
        // بدلاً من ذلك، نضيف سجل فارغ للعرض فقط (بدون حفظ في قاعدة البيانات)
        // المستخدم سيرى أن المستخدم ليس لديه راتب ويمكنه إنشاؤه يدوياً
        $emptySalary = [
            'id' => null,
            'user_id' => $userId,
            'month' => $selectedMonth,
            'year' => $selectedYear,
            'hourly_rate' => cleanFinancialValue($user['hourly_rate'] ?? 0),
            'total_hours' => 0,
            'base_amount' => 0,
            'bonus' => 0,
            'deductions' => 0,
            'total_amount' => 0,
            'status' => 'none',
            'full_name' => $user['full_name'] ?? $user['username'] ?? '',
            'username' => $user['username'] ?? '',
            'role' => $user['role'] ?? '',
            'current_hourly_rate' => cleanFinancialValue($user['hourly_rate'] ?? 0),
            'bonus_standardized' => 0
        ];
        $salaries[] = $emptySalary;
        continue; // تخطي باقي الكود وانتقل للمستخدم التالي
    }
}

// استبعاد المديرين من قائمة الرواتب المعروضة (للأمان)
$salaries = array_values(array_filter($salaries, function ($salary) {
    $role = strtolower($salary['role'] ?? '');
    $hourlyRate = isset($salary['hourly_rate']) ? floatval($salary['hourly_rate']) : (isset($salary['current_hourly_rate']) ? floatval($salary['current_hourly_rate']) : 0);
    return $role !== 'manager';
}));

// الحصول على طلبات تعديل الرواتب المعلقة (للمدير فقط)
$pendingModifications = [];
if (in_array($currentUser['role'] ?? '', ['manager', 'developer'], true)) {
    try {
        $entityColumn = getApprovalsEntityColumn();
        
        $sql = "SELECT a.*, s.user_id, u.full_name, u.username
                FROM approvals a
                LEFT JOIN salaries s ON a.`" . $entityColumn . "` = s.id
                LEFT JOIN users u ON s.user_id = u.id
                WHERE a.type = 'salary_modification' AND a.status = 'pending'
                ORDER BY a.created_at DESC";
        
        $pendingModifications = $db->query($sql);
    } catch (Exception $e) {
        error_log("Error fetching pending modifications: " . $e->getMessage());
        $pendingModifications = [];
    }
}

// جلب طلبات السلف مع الفلاتر
$advances = [];
$advanceStats = [
    'pending' => 0,
    'accountant_approved' => 0,
    'manager_approved' => 0,
    'rejected' => 0,
    'pending_amount' => 0
];

if ($view === 'advances' || $currentUser['role'] === 'accountant' || $currentUser['role'] === 'manager') {
    // الفلاتر
    $advanceStatusFilter = $_GET['advance_status'] ?? 'all';
    $advanceMonthFilter = isset($_GET['advance_month']) ? intval($_GET['advance_month']) : 0;
    $advanceYearFilter = isset($_GET['advance_year']) ? intval($_GET['advance_year']) : 0;
    
    $whereClauses = [];
    $params = [];
    
    if ($advanceStatusFilter && $advanceStatusFilter !== 'all') {
        $whereClauses[] = "sa.status = ?";
        $params[] = $advanceStatusFilter;
    }
    
    if ($advanceMonthFilter > 0) {
        $whereClauses[] = "MONTH(sa.request_date) = ?";
        $params[] = $advanceMonthFilter;
    }
    
    if ($advanceYearFilter > 0) {
        $whereClauses[] = "YEAR(sa.request_date) = ?";
        $params[] = $advanceYearFilter;
    }
    
    $whereClause = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";
    
    $advancesQuery = "
        SELECT sa.*, 
               u.full_name as user_name, u.username, u.role,
               accountant.full_name as accountant_name,
               accountant.username as accountant_username,
               manager.full_name as manager_name,
               manager.username as manager_username,
               salaries.total_amount AS salary_total
        FROM salary_advances sa
        INNER JOIN users u ON sa.user_id = u.id
        LEFT JOIN users accountant ON sa.accountant_approved_by = accountant.id
        LEFT JOIN users manager ON sa.manager_approved_by = manager.id
        LEFT JOIN salaries ON sa.deducted_from_salary_id = salaries.id
        $whereClause
        ORDER BY sa.created_at DESC
    ";
    
    $advances = $db->query($advancesQuery, $params);
    
    // حساب الإحصائيات
    $advanceStats['pending'] = $db->queryOne("SELECT COUNT(*) as count FROM salary_advances WHERE status = 'pending'")['count'] ?? 0;
    $advanceStats['accountant_approved'] = $db->queryOne("SELECT COUNT(*) as count FROM salary_advances WHERE status = 'accountant_approved'")['count'] ?? 0;
    $advanceStats['manager_approved'] = $db->queryOne("SELECT COUNT(*) as count FROM salary_advances WHERE status = 'manager_approved'")['count'] ?? 0;
    $advanceStats['rejected'] = $db->queryOne("SELECT COUNT(*) as count FROM salary_advances WHERE status = 'rejected'")['count'] ?? 0;
    $advanceStats['pending_amount'] = $db->queryOne("SELECT COALESCE(SUM(amount), 0) as total FROM salary_advances WHERE status IN ('pending','accountant_approved')")['total'] ?? 0;
}

// معالجة طباعة كشف حساب المرتب
if (isset($_GET['action']) && $_GET['action'] === 'print_statement') {
    $statementSalaryId = intval($_GET['salary_id'] ?? 0);
    $statementUserId = intval($_GET['user_id'] ?? 0);
    $periodType = $_GET['period_type'] ?? 'current_month';
    
    if ($statementUserId <= 0) {
        die('معرف الموظف غير صحيح');
    }
    
    // الحصول على بيانات الموظف
    $employee = $db->queryOne(
        "SELECT id, full_name, username, role, hourly_rate FROM users WHERE id = ?",
        [$statementUserId]
    );
    
    if (!$employee) {
        die('الموظف غير موجود');
    }
    
    // تحديد الفترة الزمنية
    $fromDate = null;
    $toDate = null;
    $periodLabel = '';
    
    if ($periodType === 'current_month') {
        $month = intval($_GET['month'] ?? date('n'));
        $year = intval($_GET['year'] ?? date('Y'));
        $fromDate = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
        $toDate = date('Y-m-d');
        $periodLabel = 'الشهر الحالي حتى اليوم (' . date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year . ')';
    } elseif ($periodType === 'specific_month') {
        $month = intval($_GET['month'] ?? date('n'));
        $year = intval($_GET['year'] ?? date('Y'));
        $fromDate = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
        $daysInMonth = date('t', mktime(0, 0, 0, $month, 1, $year));
        $toDate = date('Y-m-' . $daysInMonth, mktime(0, 0, 0, $month, 1, $year));
        $periodLabel = date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year;
    } elseif ($periodType === 'date_range') {
        $fromDate = $_GET['from_date'] ?? date('Y-m-01');
        $toDate = $_GET['to_date'] ?? date('Y-m-d');
        $periodLabel = 'من ' . date('d/m/Y', strtotime($fromDate)) . ' إلى ' . date('d/m/Y', strtotime($toDate));
    }
    
    // جلب الرواتب خلال الفترة
    // التحقق من نوع عمود month أولاً - هذا مهم جداً
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    $isMonthDate = stripos($monthType, 'date') !== false;
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    $fromYear = intval(date('Y', strtotime($fromDate)));
    $fromMonth = intval(date('n', strtotime($fromDate)));
    $toYear = intval(date('Y', strtotime($toDate)));
    $toMonth = intval(date('n', strtotime($toDate)));
    
    // بناء الاستعلام بناءً على نوع عمود month
    if ($isMonthDate) {
        // عمود month من نوع DATE - استخدام DATE() للفلترة
        $statementSalaries = $db->query(
            "SELECT s.* FROM salaries s 
             WHERE s.user_id = ? 
             AND s.month IS NOT NULL 
             AND s.month != '0000-00-00' 
             AND s.month != '1970-01-01'
             AND DATE(s.month) BETWEEN ? AND ?
             ORDER BY s.month ASC",
            [$statementUserId, $fromDate, $toDate]
        );
    } elseif ($hasYearColumn) {
        // عمود month من نوع INT مع وجود عمود year منفصل
        $statementSalaries = $db->query(
            "SELECT s.* FROM salaries s 
             WHERE s.user_id = ? 
             AND s.month > 0 
             AND s.month <= 12 
             AND s.year > 0
             AND ((s.year > ? OR (s.year = ? AND s.month >= ?)) AND (s.year < ? OR (s.year = ? AND s.month <= ?)))
             ORDER BY s.year ASC, s.month ASC",
            [$statementUserId, $fromYear, $fromYear, $fromMonth, $toYear, $toYear, $toMonth]
        );
    } else {
        // عمود month من نوع INT بدون عمود year
        // في هذه الحالة، نستخدم الفترة الحالية فقط
        $statementSalaries = $db->query(
            "SELECT s.* FROM salaries s 
             WHERE s.user_id = ? 
             AND s.month > 0 
             AND s.month <= 12
             AND s.month >= ? 
             AND s.month <= ?
             ORDER BY s.month ASC",
            [$statementUserId, $fromMonth, $toMonth]
        );
    }
    
    // جلب السلف خلال الفترة
    $advancesQuery = "SELECT sa.* FROM salary_advances sa 
                      WHERE sa.user_id = ? 
                      AND DATE(sa.request_date) BETWEEN ? AND ?
                      AND sa.status = 'manager_approved'
                      ORDER BY sa.request_date ASC";
    $statementAdvances = $db->query($advancesQuery, [$statementUserId, $fromDate, $toDate]);
    
    // جلب التسويات خلال الفترة
    $settlementsQuery = "SELECT ss.* FROM salary_settlements ss 
                        WHERE ss.user_id = ? 
                        AND DATE(ss.settlement_date) BETWEEN ? AND ?
                        ORDER BY ss.settlement_date ASC";
    $statementSettlements = $db->query($settlementsQuery, [$statementUserId, $fromDate, $toDate]);
    
    // إعادة حساب القيم لكل راتب بنفس منطق بطاقة الموظف
    require_once __DIR__ . '/../../includes/salary_calculator.php';
    $userRole = $employee['role'] ?? 'production';
    $hourlyRate = cleanFinancialValue($employee['hourly_rate'] ?? 0);
    
    // التحقق من وجود عمود bonus أو bonuses
    $bonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field IN ('bonus', 'bonuses')");
    $bonusColumnName = $bonusColumnCheck ? $bonusColumnCheck['Field'] : 'bonus';
    
    foreach ($statementSalaries as &$sal) {
        // استخراج شهر وسنة الراتب
        $salaryMonth = null;
        $salaryYear = null;
        
        if ($isMonthDate && !empty($sal['month'])) {
            $monthDate = $sal['month'];
            if ($monthDate && $monthDate !== '0000-00-00' && $monthDate !== '1970-01-01') {
                $date = DateTime::createFromFormat('Y-m-d', $monthDate);
                if ($date) {
                    $salaryMonth = (int)$date->format('n');
                    $salaryYear = (int)$date->format('Y');
                }
            }
        } else {
            $salaryMonth = isset($sal['month']) ? (int)$sal['month'] : null;
            $salaryYear = isset($sal['year']) ? (int)$sal['year'] : null;
        }
        
        if (!$salaryMonth || !$salaryYear) {
            continue; // تخطي إذا لم نتمكن من استخراج الشهر والسنة
        }
        
        // حساب الساعات من الحضور مباشرة لضمان الدقة (مطابق لبطاقة الموظف)
        $actualHours = calculateMonthlyHours($statementUserId, $salaryMonth, $salaryYear);
        $sal['total_hours'] = $actualHours;
        
        // حساب الراتب الأساسي دائماً من الساعات × سعر الساعة (مطابق لبطاقة الموظف)
        $completedHours = calculateCompletedMonthlyHours($statementUserId, $salaryMonth, $salaryYear);
        $sal['base_amount'] = round($completedHours * $hourlyRate, 2);
        
        // حساب نسبة التحصيلات الخاصة بالشهر المحدد فقط (للمندوبين)
        if ($userRole === 'sales') {
            $collectionsAmount = calculateSalesCollections($statementUserId, $salaryMonth, $salaryYear);
            $collectionsBonus = round($collectionsAmount * 0.02, 2);
            $sal['collections_bonus'] = $collectionsBonus;
            $sal['collections_amount'] = $collectionsAmount;
        } else {
            $sal['collections_bonus'] = 0;
            $sal['collections_amount'] = 0;
        }
        
        // حساب الراتب الإجمالي من المكونات (مطابق لبطاقة الموظف)
        $bonus = cleanFinancialValue($sal[$bonusColumnName] ?? $sal['bonus'] ?? $sal['bonuses'] ?? 0);
        $deductions = cleanFinancialValue($sal['deductions'] ?? 0);
        $sal['total_amount'] = round($sal['base_amount'] + $bonus + $sal['collections_bonus'] - $deductions, 2);
        $sal['total_amount'] = max(0, $sal['total_amount']);
        
        // إضافة bonus_standardized للعرض
        $sal['bonus_standardized'] = $bonus;
    }
    unset($sal);
    
    // حساب الإجماليات
    $totalSalaries = 0;
    $totalAdvances = 0;
    $totalSettlements = 0;
    
    foreach ($statementSalaries as $sal) {
        $totalSalaries += cleanFinancialValue($sal['total_amount'] ?? 0);
    }
    
    foreach ($statementAdvances as $adv) {
        $totalAdvances += cleanFinancialValue($adv['amount'] ?? 0);
    }
    
    foreach ($statementSettlements as $set) {
        $totalSettlements += cleanFinancialValue($set['settlement_amount'] ?? 0);
    }
    
    $netAmount = $totalSalaries - $totalAdvances - $totalSettlements;
    
    // حساب المتبقي (المبلغ التراكمي - المبلغ المدفوع) - استخدام نفس الدالة المستخدمة في بطاقة الموظف
    require_once __DIR__ . '/../../includes/salary_calculator.php';
    
    // الحصول على آخر راتب في الفترة لحساب المبلغ التراكمي بدقة
    $lastSalaryInPeriod = null;
    if (!empty($statementSalaries)) {
        // أخذ آخر راتب في الفترة
        $lastSalaryInPeriod = end($statementSalaries);
        reset($statementSalaries);
    }
    
    // استخدام accumulated_amount المحفوظ في قاعدة البيانات مباشرة
    // المبلغ التراكمي = المتبقي من الراتب الحالي (accumulated_amount - paid_amount)
    $accumulatedAmount = 0;
    $paidAmount = 0;
    
    if ($lastSalaryInPeriod) {
        // استخدام accumulated_amount المحفوظ في قاعدة البيانات
        $accumulatedAmount = cleanFinancialValue($lastSalaryInPeriod['accumulated_amount'] ?? $lastSalaryInPeriod['total_amount'] ?? 0);
        $paidAmount = cleanFinancialValue($lastSalaryInPeriod['paid_amount'] ?? 0);
    } else {
        // إذا لم يكن هناك راتب في الفترة، نحسب من آخر راتب موجود
        $lastSalaryRecord = null;
        
        // استخدام نفس المنطق المستخدم في الاستعلام الرئيسي
        if ($isMonthDate) {
            // عمود month من نوع DATE
            $lastSalaryRecord = $db->queryOne(
                "SELECT id, month, total_amount, accumulated_amount, paid_amount FROM salaries 
                 WHERE user_id = ? 
                 AND month IS NOT NULL 
                 AND month != '0000-00-00' 
                 AND month != '1970-01-01'
                 AND DATE(month) < ?
                 ORDER BY month DESC LIMIT 1",
                [$statementUserId, $toDate]
            );
        } elseif ($hasYearColumn) {
            // عمود month من نوع INT مع وجود عمود year
            $lastSalaryRecord = $db->queryOne(
                "SELECT id, month, year, total_amount, accumulated_amount, paid_amount FROM salaries 
                 WHERE user_id = ? 
                 AND month > 0 
                 AND month <= 12 
                 AND year > 0
                 AND (year < ? OR (year = ? AND month < ?))
                 ORDER BY year DESC, month DESC LIMIT 1",
                [$statementUserId, $toYear, $toYear, $toMonth]
            );
        } else {
            // عمود month من نوع INT بدون عمود year
            $lastSalaryRecord = $db->queryOne(
                "SELECT id, month, total_amount, accumulated_amount, paid_amount FROM salaries 
                 WHERE user_id = ? 
                 AND month > 0 
                 AND month <= 12
                 AND month < ?
                 ORDER BY month DESC LIMIT 1",
                [$statementUserId, $toMonth]
            );
        }
        
        if ($lastSalaryRecord) {
            $accumulatedAmount = cleanFinancialValue($lastSalaryRecord['accumulated_amount'] ?? $lastSalaryRecord['total_amount'] ?? 0);
            $paidAmount = cleanFinancialValue($lastSalaryRecord['paid_amount'] ?? 0);
        }
    }
    
    // استخدام paid_amount المحفوظ في قاعدة البيانات مباشرة
    // paid_amount يتم تحديثه تلقائياً عند إجراء التسويات
    // لا نحتاج لإعادة حسابه من التسويات
    
    // حساب المتبقي (الراتب الفعلي) = المبلغ التراكمي - المبلغ المدفوع
    $remainingAmount = max(0, $accumulatedAmount - $paidAmount);
    
    // إضافة القيم المحسوبة إلى بيانات الموظف (مطابقة لبطاقة الموظف)
    $employee['actual_salary'] = $remainingAmount;
    $employee['accumulated_amount'] = $accumulatedAmount;
    $employee['paid_amount'] = $paidAmount;
    
    // التأكد من تعريف ACCESS_ALLOWED قبل استدعاء صفحة الطباعة
    if (!defined('ACCESS_ALLOWED')) {
        define('ACCESS_ALLOWED', true);
    }
    
    // عرض صفحة الطباعة
    include __DIR__ . '/salary_statement_print.php';
    exit;
}

// معالجة AJAX لتفاصيل الراتب
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && $salaryId > 0) {
    $salary = $db->queryOne(
        "SELECT s.*, u.full_name, u.username, u.role, u.hourly_rate as current_hourly_rate,
                approver.full_name as approver_name
         FROM salaries s
         LEFT JOIN users u ON s.user_id = u.id
         LEFT JOIN users approver ON s.approved_by = approver.id
         WHERE s.id = ?
           AND (u.role IS NULL OR LOWER(u.role) != 'manager')
           AND (u.hourly_rate IS NULL OR u.hourly_rate > 0)",
        [$salaryId]
    );
    
    if ($salary) {
        $userId = intval($salary['user_id'] ?? 0);
        $userRole = $salary['role'] ?? 'production';
        $salaryMonth = intval($salary['month'] ?? $selectedMonth);
        $salaryYear = intval($salary['year'] ?? $selectedYear);
        
        // حساب الساعات من الحضور مباشرة لضمان الدقة (مطابقة مع صفحة الحضور)
        $actualHours = calculateMonthlyHours($userId, $salaryMonth, $salaryYear);
        
        // تحديث total_hours تلقائياً إذا كان مختلفاً عن القيمة الفعلية
        $savedTotalHours = floatval($salary['total_hours'] ?? 0);
        $wasUpdated = false;
        if (abs($actualHours - $savedTotalHours) > 0.01) {
            // تحديث total_hours في قاعدة البيانات
            try {
                $db->execute(
                    "UPDATE salaries SET total_hours = ? WHERE id = ?",
                    [$actualHours, $salary['id']]
                );
                // إعادة جلب البيانات من قاعدة البيانات للتأكد من الحصول على القيمة المحدثة
                $updatedSalary = $db->queryOne(
                    "SELECT total_hours FROM salaries WHERE id = ?",
                    [$salary['id']]
                );
                if ($updatedSalary) {
                    $salary['total_hours'] = floatval($updatedSalary['total_hours'] ?? $actualHours);
                } else {
                    $salary['total_hours'] = $actualHours;
                }
                $wasUpdated = true;
            } catch (Exception $e) {
                error_log("Error updating total_hours for salary ID {$salary['id']}: " . $e->getMessage());
            }
        }
        
        // حساب القيم المالية بنفس طريقة بطاقة الموظف
        $hourlyRate = cleanFinancialValue($salary['hourly_rate'] ?? $salary['current_hourly_rate'] ?? 0);
        $bonus = cleanFinancialValue($salary['bonus_standardized'] ?? ($salary['bonus'] ?? $salary['bonuses'] ?? 0));
        $deductions = cleanFinancialValue($salary['deductions'] ?? 0);
        
        // حساب الراتب الأساسي دائماً من الساعات × سعر الساعة (حقل ثابت لا يتأثر بالتسويات)
        // لا نستخدم base_amount المحفوظ في قاعدة البيانات لأنه قد يكون متأثراً بالتسويات
        require_once __DIR__ . '/../../includes/salary_calculator.php';
        $completedHours = calculateCompletedMonthlyHours($userId, $salaryMonth, $salaryYear);
        $baseAmount = round($completedHours * $hourlyRate, 2);
        
        // حساب نسبة التحصيلات للمندوبين الخاصة بالشهر المحدد فقط
        // يجب أن تُحسب نسبة التحصيلات من التحصيلات الخاصة بالشهر المحدد فقط (وليس من رصيد الخزنة الإجمالي)
        // لا نستخدم القيمة المحفوظة في collections_bonus لأنها قد تكون من شهر آخر
        $collectionsBonus = 0;
        $collectionsAmount = 0;
        
        // متغير لعرض مبلغ التحصيلات الخاص بالشهر المحدد
        $displayCashBalance = 0.0;
        
        if ($userRole === 'sales') {
            // حساب نسبة التحصيلات: مطابق لصفحة "مرتبي" الخاصة بالمندوب
            // يجب أن تشمل:
            // 1. 2% من المبالغ المحصلة فعلياً من جدول collections
            // 2. المكافآت المضافة مباشرة في pos.php (2% من creditUsed)
            // استخدام القيمة المحفوظة في collections_bonus (تتضمن جميع المكافآت)
            // وإذا لم تكن موجودة، نحسبها من جدول collections فقط (مطابق لصفحة "مرتبي")
            
            // حساب إجمالي التحصيلات من جدول collections فقط (مطابق لصفحة "مرتبي")
            $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
            $collectionsAmount = 0.0;
            if (!empty($collectionsTableExists)) {
                // التحقق من وجود عمود status
                $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
                $hasStatusColumn = !empty($statusColumnCheck);
                
                if ($hasStatusColumn) {
                    // حساب جميع التحصيلات (pending و approved) للشهر والسنة المحددين
                    $collectionsResult = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total_collections
                         FROM collections
                         WHERE collected_by = ? 
                         AND MONTH(date) = ? 
                         AND YEAR(date) = ?
                         AND status IN ('pending', 'approved')",
                        [$userId, $salaryMonth, $salaryYear]
                    );
                } else {
                    $collectionsResult = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total_collections
                         FROM collections
                         WHERE collected_by = ? 
                         AND MONTH(date) = ? 
                         AND YEAR(date) = ?",
                        [$userId, $salaryMonth, $salaryYear]
                    );
                }
                $collectionsAmount = (float)($collectionsResult['total_collections'] ?? 0);
            }
            
            // استخدام نسبة التحصيلات المحفوظة في قاعدة البيانات
            // (تتضمن 2% من collections + 2% من creditUsed المضافة في pos.php)
            $savedCollectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
            
            if ($savedCollectionsBonus > 0) {
                // استخدام القيمة المحفوظة (تتضمن جميع المكافآت من pos.php)
                $collectionsBonus = $savedCollectionsBonus;
            } else {
                // إذا لم تكن هناك قيمة محفوظة، احسبها من جدول collections فقط
                // (لن تتضمن المكافآت من pos.php حتى يتم حساب الراتب)
                $collectionsBonus = round($collectionsAmount * 0.02, 2);
            }
            
            $displayCashBalance = cleanFinancialValue($collectionsAmount);
        }
        
        // حساب الراتب الإجمالي الصحيح دائماً من المكونات (مطابق لصفحة "مرتبي" الخاصة بالمندوب)
        // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
        // ملاحظة: يجب دائماً إعادة حساب الراتب الإجمالي من المكونات لضمان الدقة
        // وعدم الاعتماد على القيمة المحفوظة في قاعدة البيانات (قد تكون خاطئة)
        // ملاحظة: لا نخصم التسويات والسلف هنا لأنها تُخصم عند حساب المتبقي فقط
        $totalAmount = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
        
        // التأكد من أن الراتب الإجمالي لا يكون سالباً
        $totalAmount = max(0, $totalAmount);
        
        // تسجيل معلومات التشخيص للتأكد من الحساب الصحيح
        error_log(sprintf(
            'Salary card calculation: userId=%d, baseAmount=%.2f, bonus=%.2f, collectionsBonus=%.2f, deductions=%.2f, totalAmount=%.2f',
            $userId, $baseAmount, $bonus, $collectionsBonus, $deductions, $totalAmount
        ));
        
        // حساب بيانات التأخير (مطابق لبطاقة الموظف)
        $delaySummary = calculateMonthlyDelaySummary($userId, $salaryMonth, $salaryYear);
        
        // حساب المبلغ التراكمي والمتبقي بشكل صحيح (مطابق لبطاقة الموظف)
        // يجب استخدام الدالة المشتركة لحساب المبلغ التراكمي الذي يشمل المتبقي من الشهور السابقة
        require_once __DIR__ . '/../../includes/salary_calculator.php';
        
        // استخدام الدالة المشتركة لحساب المبلغ التراكمي (يشمل المتبقي من الشهور السابقة)
        $accumulatedData = calculateSalaryAccumulatedAmount(
            $userId, 
            intval($salary['id'] ?? 0), 
            $totalAmount, 
            $salaryMonth, 
            $salaryYear, 
            $db
        );
        
        $accumulated = $accumulatedData['accumulated'];
        $paid = floatval($salary['paid_amount'] ?? 0);
        $remaining = max(0, $accumulated - $paid);
        
        ?>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">معلومات الراتب</h6>
                    </div>
                    <div class="card-body">
                        <p><strong>المستخدم:</strong> <?php echo htmlspecialchars($salary['full_name'] ?? $salary['username']); ?></p>
                        <p><strong>الشهر:</strong> <?php echo date('F', mktime(0, 0, 0, $salaryMonth, 1)); ?> <?php echo $salaryYear; ?></p>
                        <p><strong>سعر الساعة:</strong> <?php echo formatCurrency($hourlyRate); ?></p>
                        <?php if ($userRole !== 'sales'): ?>
                        <p><strong>عدد الساعات:</strong> <?php echo formatHours($actualHours); ?> 
                            <?php if ($wasUpdated): ?>
                                <span class="badge bg-success text-white ms-2" title="تم تحديث القيمة من <?php echo formatHours($savedTotalHours); ?> إلى <?php echo formatHours($actualHours); ?>">تم التحديث</span>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                        <p><strong>إجمالي التأخير:</strong> <?php echo number_format($delaySummary['total_minutes'] ?? 0, 2); ?> دقيقة</p>
                        <p><strong>متوسط التأخير:</strong> <?php echo number_format($delaySummary['average_minutes'] ?? 0, 2); ?> دقيقة</p>
                        <p><strong>الراتب الأساسي:</strong> <?php echo formatCurrency($baseAmount); ?></p>
                        <?php if ($userRole === 'sales'): ?>
                        <p><strong>نسبة التحصيلات:</strong> <?php echo formatCurrency($collectionsBonus); ?>
                            <small class="text-muted d-block" style="font-size: 11px; margin-top: 2px;">
                                (من <?php echo formatCurrency($displayCashBalance); ?>)
                            </small>
                        </p>
                        <?php endif; ?>
                        <p><strong>المكافآت:</strong> <?php echo formatCurrency($bonus); ?></p>
                        <p><strong>الخصومات:</strong> <?php echo formatCurrency($deductions); ?></p>
                        <p><strong>الراتب الإجمالي:</strong> <strong class="text-success"><?php echo formatCurrency($totalAmount); ?></strong></p>
                        <?php if ($userRole === 'sales' && $collectionsBonus > 0): ?>
                        <p class="text-muted small mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            ملاحظة: الراتب الإجمالي يتضمن نسبة التحصيلات (<?php echo formatCurrency($collectionsBonus); ?>)
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">تفاصيل إضافية</h6>
                    </div>
                    <div class="card-body">
                        <p><strong>الحالة:</strong> 
                            <span class="badge bg-<?php 
                                echo $salary['status'] === 'approved' ? 'success' : 
                                    ($salary['status'] === 'rejected' ? 'danger' : 
                                    ($salary['status'] === 'paid' ? 'info' : 
                                    ($salary['status'] === 'calculated' ? 'primary' : 'warning'))); 
                            ?>">
                                <?php 
                                $statusLabels = [
                                    'pending' => 'قيد الحساب',
                                    'approved' => 'موافق عليه',
                                    'rejected' => 'مرفوض',
                                    'paid' => 'مدفوع',
                                    'calculated' => 'محسوب'
                                ];
                                echo $statusLabels[$salary['status']] ?? $salary['status'];
                                ?>
                            </span>
                        </p>
                        <p><strong>المبلغ التراكمي:</strong> <span class="text-primary"><?php echo formatCurrency($accumulated); ?></span></p>
                        <p><strong>المبلغ المدفوع:</strong> <span class="text-success"><?php echo formatCurrency($paid); ?></span></p>
                        <p><strong>المتبقي:</strong> <span class="<?php echo $remaining > 0 ? 'text-warning' : 'text-success'; ?>"><?php echo formatCurrency($remaining); ?></span></p>
                        <?php if (!empty($salary['notes'])): ?>
                            <p><strong>ملاحظات:</strong><br><?php echo nl2br(htmlspecialchars($salary['notes'])); ?></p>
                        <?php endif; ?>
                        <p><strong>تاريخ الإنشاء:</strong> <?php echo formatDateTime($salary['created_at']); ?></p>
                        <?php if (!empty($salary['updated_at'])): ?>
                            <p><strong>آخر تحديث:</strong> <?php echo formatDateTime($salary['updated_at']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    } else {
        echo '<div class="alert alert-danger">الراتب غير موجود أو غير متاح للعرض.</div>';
    }
    exit;
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700&display=swap');

* {
    box-sizing: border-box;
}

body {
    font-family: 'Tajawal', sans-serif;
}

/* Modern Gradient Header */
.salary-page-header {
    background: linear-gradient(135deg,rgb(8, 69, 166) 0%,rgb(6, 104, 180) 100%);
    color: white;
    padding: 25px 30px;
    border-radius: 16px;
    margin-bottom: 30px;
    box-shadow: 0 8px 24px rgba(102, 126, 234, 0.25);
    font-family: 'Tajawal', sans-serif;
}

.salary-page-header h1 {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: white;
    font-family: 'Tajawal', sans-serif;
}

.salary-page-header .header-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.salary-page-header .header-controls .form-select {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    font-weight: 500;
}

.salary-page-header .header-controls .form-select option {
    background:rgb(42, 75, 222);
    color: white;
}

/* Export Buttons */
.btn-export {
    background: #3b82f6;
    color: white;
    border: 1px solid #2563eb;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
}

.btn-export:hover {
    background: #2563eb;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

/* Employee Cards Grid */
.employee-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

@media (max-width: 768px) {
    .employee-cards-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
}

/* Employee Card */
.employee-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.employee-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.employee-card-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f3f4f6;
}

/* Profile Icon (WhatsApp-style) */
.profile-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.profile-icon.production {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}

.profile-icon.accountant {
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
}

.profile-icon.sales {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.employee-info {
    flex: 1;
    min-width: 0;
}

.employee-name {
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 5px 0;
    font-family: 'Tajawal', sans-serif;
}

/* Role Badge */
.role-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 5px;
}

.role-badge.production {
    background: #dbeafe;
    color: #1e40af;
}

.role-badge.accountant {
    background: #cffafe;
    color: #0e7490;
}

.role-badge.sales {
    background: #d1fae5;
    color: #065f46;
}

/* Salary Amount */
.salary-amount {
    font-size: 24px;
    font-weight: 700;
    color: #059669;
    margin: 10px 0;
    font-family: 'Tajawal', sans-serif;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin: 8px 0;
}

.status-badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.approved {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.paid {
    background: #dbeafe;
    color: #1e40af;
}

.status-badge.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge.calculated {
    background: #dbeafe;
    color: #1e40af;
}

/* Legacy status classes for advances section */
.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-approved {
    background: #d1fae5;
    color: #065f46;
}

.status-accountant {
    background: #dbeafe;
    color: #1e40af;
}

.status-rejected {
    background: #fee2e2;
    color: #991b1b;
}

/* View Details Button */
.btn-view-details {
    width: 100%;
    background: #f9fafb;
    color: #374151;
    border: 1px solid #e5e7eb;
    padding: 10px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 15px;
}

.btn-view-details:hover {
    background: #f3f4f6;
    color: #1f2937;
    border-color: #d1d5db;
}

.btn-view-details .arrow-icon {
    transition: transform 0.3s ease;
}

.btn-view-details[aria-expanded="true"] .arrow-icon {
    transform: rotate(180deg);
}

/* Collapsible Details */
.salary-details-collapse {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #f3f4f6;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-weight: 600;
    color: #6b7280;
    font-size: 14px;
}

.detail-value {
    font-weight: 700;
    color: #1f2937;
    font-size: 15px;
}

.detail-actions {
    display: flex;
    gap: 8px;
    margin-top: 15px;
    flex-wrap: wrap;
}

.detail-actions .btn {
    flex: 1;
    min-width: 80px;
    font-size: 13px;
    padding: 8px 12px;
}

/* Filter Card */
.filter-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

/* Summary Cards for Monthly Report */
.salary-summary-card {
    border-radius: 16px;
    transition: all 0.3s ease;
    border: none !important;
}

.salary-summary-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25) !important;
}

.salary-card-blue {
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #60a5fa 100%) !important;
}

.salary-card-green {
    background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%) !important;
}

.salary-card-yellow {
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%) !important;
}

/* Button Styles */
.btn-primary-salary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
}

.btn-primary-salary:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    color: white;
}

/* Advances Section Styling */
.advances-table-wrapper {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    overflow-x: auto;
}

/* Responsive */
@media (max-width: 768px) {
    .salary-page-header {
        padding: 20px;
    }
    
    .salary-page-header h1 {
        font-size: 22px;
    }
    
    .employee-card {
        padding: 15px;
    }
    
    .profile-icon {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    
    .employee-name {
        font-size: 16px;
    }
    
    .salary-amount {
        font-size: 20px;
    }
}

@media (max-width: 576px) {
    .salary-page-header {
        padding: 15px;
    }
    
    .salary-page-header h1 {
        font-size: 18px;
    }
    
    .header-controls {
        width: 100%;
    }
    
    .header-controls .form-select,
    .header-controls .btn-export {
        width: 100%;
        margin-bottom: 8px;
    }
}
</style>

<?php 
$monthName = date('F', mktime(0, 0, 0, $selectedMonth, 1));
$pageTitle = ($view === 'advances') ? 'السلف' : (($view === 'pending') ? 'طلبات معلقة' : 'الرواتب');
?>

<?php if ($view === 'advances'): ?>
    <!-- صفحة السلف - عرض جدول طلبات السلف فقط -->
    <!-- Header للشهر والسنة -->
    <div class="salary-page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <h1>السلف - <?php echo htmlspecialchars($monthName); ?> <?php echo $selectedYear; ?></h1>
            <div class="header-controls">
                <a href="<?php echo htmlspecialchars($buildViewUrl('list')); ?>" class="btn btn-export">
                    <i class="bi bi-arrow-right me-2"></i>الرجوع
                </a>
            </div>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" id="errorAlert" data-auto-refresh="true">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" id="successAlert" data-auto-refresh="true">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success; // السماح بعرض HTML للروابط ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php else: ?>
<!-- Header للشهر والسنة -->
<div class="salary-page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
        <div class="header-controls">
            <form method="GET" class="d-inline" action="<?php echo htmlspecialchars($currentUrl); ?>">
                <input type="hidden" name="page" value="salaries">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
               
            </form>
        </div>
    </div>
</div>

<!-- رسائل النجاح والخطأ -->
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo $success; // السماح بعرض HTML للروابط ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- التبويبات -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link <?php echo $view === 'list' ? 'active' : ''; ?>" 
           href="<?php echo htmlspecialchars($buildViewUrl('list')); ?>">
            <i class="bi bi-list-ul me-2"></i>قائمة الرواتب
        </a>
    </li>
    <?php if (in_array($currentUser['role'] ?? '', ['manager', 'developer'], true) && !empty($pendingModifications)): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?php echo $view === 'pending' ? 'active' : ''; ?>" 
           href="<?php echo htmlspecialchars($buildViewUrl('pending')); ?>">
            <i class="bi bi-hourglass-split me-2"></i>طلبات معلقة 
            <span class="badge bg-warning text-dark ms-1"><?php echo count($pendingModifications); ?></span>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?php echo $view === 'advances' ? 'active' : ''; ?>" 
           href="<?php echo htmlspecialchars($buildViewUrl('advances')); ?>">
            <i class="bi bi-cash-coin me-2"></i>السلف
            <?php 
            $pendingAdvances = array_filter($advances, function($adv) {
                return $adv['status'] === 'pending' || $adv['status'] === 'accountant_approved';
            });
            if (count($pendingAdvances) > 0): 
            ?>
            <span class="badge bg-danger ms-1"><?php echo count($pendingAdvances); ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>
<?php endif; ?>

<?php if ($view !== 'advances' && $showReport && $monthlyReport): ?>
<!-- تقرير رواتب شهري -->
<div class="salary-card mb-4">
    <div class="salary-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>
            تقرير رواتب شهري - <?php echo date('F', mktime(0, 0, 0, $selectedMonth, 1)); ?> <?php echo $selectedYear; ?>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo $currentUrl; ?>?page=salaries&view=<?php echo $view; ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>" class="btn btn-light btn-sm">
                <i class="bi bi-x-lg"></i> إغلاق التقرير
            </a>
        </div>
    </div>
    <div>
        <!-- ملخص التقرير -->
        <div class="row mb-3 g-2">
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-blue text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center">
                        <div class="mb-1">
                            <i class="bi bi-people"></i>
                        </div>
                        <h6 class="card-title mb-1 fw-bold text-uppercase small">عدد الموظفين</h6>
                        <h2 class="mb-0 fw-bold"><?php echo $monthlyReport['total_users']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-green text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center">
                        <div class="mb-1">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <h6 class="card-title mb-1 fw-bold text-uppercase small">إجمالي الساعات</h6>
                        <h2 class="mb-0 fw-bold"><?php echo formatHours($monthlyReport['total_hours']); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-yellow text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center">
                        <div class="mb-1">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <h6 class="card-title mb-1 fw-bold text-uppercase small">إجمالي الرواتب</h6>
                        <h2 class="mb-0 fw-bold"><?php echo formatCurrency($monthlyReport['total_amount']); ?></h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- جدول الرواتب -->
        <div class="salary-table-wrapper">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المستخدم</th>
                        <th>الدور</th>
                        <th>سعر الساعة</th>
                        <th>عدد الساعات</th>
                        <th>إجمالي التأخير (دقائق)</th>
                        <th>متوسط التأخير</th>
                        <th>الراتب الأساسي</th>
                        <th>مكافأة</th>
                        <th>نسبة التحصيلات (2%)</th>
                        <th>خصومات</th>
                        <th>الإجمالي</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthlyReport['salaries'])): ?>
                        <tr>
                            <td colspan="13" class="text-center text-muted">لا توجد رواتب</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($monthlyReport['salaries'] as $index => $salary): ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="المستخدم">
                                    <strong><?php echo htmlspecialchars($salary['user_name']); ?></strong>
                                </td>
                                <td data-label="الدور">
                                    <?php
                                    $roleColors = [
                                        'production' => 'bg-primary',
                                        'accountant' => 'bg-info',
                                        'sales' => 'bg-success'
                                    ];
                                    $roleLabels = [
                                        'production' => 'إنتاج',
                                        'accountant' => 'محاسب',
                                        'sales' => 'مندوب'
                                    ];
                                    $roleColor = $roleColors[$salary['role']] ?? 'bg-secondary';
                                    $roleLabel = $roleLabels[$salary['role']] ?? $salary['role'];
                                    ?>
                                    <span class="badge <?php echo $roleColor; ?> fs-6 px-3 py-2"><?php echo htmlspecialchars($roleLabel); ?></span>
                                </td>
                                <td data-label="سعر الساعة"><?php echo formatCurrency($salary['hourly_rate']); ?></td>
                                <td data-label="عدد الساعات">
                                    <?php 
                                    // استخراج شهر وسنة الراتب من بيانات الراتب نفسه
                                    $salaryMonthForTable = null;
                                    $salaryYearForTable = null;
                                    
                                    // التحقق من نوع عمود month
                                    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
                                    $monthType = $monthColumnCheck['Type'] ?? '';
                                    $isMonthDate = stripos($monthType, 'date') !== false;
                                    
                                    if ($isMonthDate && !empty($salary['month'])) {
                                        // إذا كان month من نوع DATE، استخراج الشهر والسنة منه
                                        $monthDate = DateTime::createFromFormat('Y-m-d', $salary['month']);
                                        if ($monthDate) {
                                            $salaryMonthForTable = (int)$monthDate->format('n');
                                            $salaryYearForTable = (int)$monthDate->format('Y');
                                        }
                                    } else {
                                        // إذا كان month من نوع INT، استخدمه مباشرة
                                        $salaryMonthForTable = isset($salary['month']) ? (int)$salary['month'] : null;
                                    }
                                    
                                    // استخراج السنة من عمود year إذا كان موجوداً
                                    if (isset($salary['year']) && !empty($salary['year'])) {
                                        $salaryYearForTable = (int)$salary['year'];
                                    }
                                    
                                    // إذا لم نتمكن من استخراج الشهر والسنة، استخدم القيم من الفلترة كبديل
                                    if (!$salaryMonthForTable || !$salaryYearForTable) {
                                        $salaryMonthForTable = $selectedMonth;
                                        $salaryYearForTable = $selectedYear;
                                    }
                                    
                                    // حساب الساعات مباشرة من الحضور لضمان الدقة (مطابقة مع صفحة الحضور)
                                    $actualHoursForTable = calculateMonthlyHours($salary['user_id'], $salaryMonthForTable, $salaryYearForTable);
                                    ?>
                                    <strong><?php echo formatHours($actualHoursForTable); ?></strong>
                                </td>
                                <td data-label="إجمالي التأخير (دقائق)">
                                    <strong><?php echo number_format($salary['total_delay_minutes'] ?? 0, 2); ?></strong>
                                    <?php if (!empty($salary['delay_days'])): ?>
                                        <div class="text-muted small"><?php echo (int) $salary['delay_days']; ?> يوم متأخر</div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="متوسط التأخير">
                                    <strong><?php echo number_format($salary['average_delay_minutes'] ?? 0, 2); ?> دقيقة</strong>
                                    <?php if (!empty($salary['attendance_days'])): ?>
                                        <div class="text-muted small">من <?php echo (int) $salary['attendance_days']; ?> يوم حضور</div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="الراتب الأساسي"><?php echo formatCurrency($salary['base_amount']); ?></td>
                                <td data-label="مكافأة"><?php echo formatCurrency($salary['bonus_standardized'] ?? ($salary['bonus'] ?? $salary['bonuses'] ?? 0)); ?></td>
                                <td data-label="نسبة التحصيلات (2%)">
                                    <?php if (isset($salary['collections_bonus']) && $salary['collections_bonus'] > 0): ?>
                                        <span class="text-info">
                                            <?php echo formatCurrency($salary['collections_bonus']); ?>
                                            <br><small>(من <?php echo formatCurrency($salary['collections_amount'] ?? 0); ?>)</small>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="خصومات"><?php echo formatCurrency($salary['deductions'] ?? 0); ?></td>
                                <td data-label="الإجمالي">
                                    <strong class="text-success"><?php echo formatCurrency($salary['total_amount']); ?></strong>
                                </td>
                                <td data-label="الحالة">
                                    <span class="badge bg-<?php 
                                        echo $salary['status'] === 'approved' ? 'success' : 
                                            ($salary['status'] === 'rejected' ? 'danger' : 
                                            ($salary['status'] === 'paid' ? 'info' : 
                                            ($salary['status'] === 'calculated' ? 'primary' : 'warning'))); 
                                    ?>">
                                        <?php 
                                        $statusLabels = [
                                            'pending' => 'معلق',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض',
                                            'paid' => 'مدفوع',
                                            'calculated' => 'محسوب'
                                        ];
                                        echo $statusLabels[$salary['status']] ?? $salary['status']; 
                                        ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <td colspan="4" class="text-end"><strong>الإجمالي:</strong></td>
                        <td><strong><?php echo formatHours($monthlyReport['total_hours']); ?></strong></td>
                        <td><strong><?php echo number_format($monthlyReport['total_delay_minutes'], 2); ?> دقيقة</strong></td>
                        <td><strong><?php echo number_format($monthlyReport['average_delay_minutes'], 2); ?> دقيقة</strong></td>
                        <td colspan="4"></td>
                        <td><strong><?php echo formatCurrency($monthlyReport['total_amount']); ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<style>
/* تحسين عرض بطاقات الملخص - الألوان المطلوبة */
.salary-card-blue {
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #60a5fa 100%) !important;
}

.salary-card-green {
    background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%) !important;
}

.salary-card-yellow {
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%) !important;
}

.salary-card-red {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 50%, #f87171 100%) !important;
}

.salary-summary-card {
    border-radius: 16px;
    transition: all 0.3s ease;
    border: none !important;
}

.salary-summary-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25) !important;
}

.salary-summary-card .card-body {
    position: relative;
    padding: 1rem !important;
}

.salary-summary-card i {
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
    font-size: 1.5rem !important;
}

.salary-summary-card h2 {
    font-size: 1.25rem;
    font-weight: 800;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    margin-bottom: 0.25rem !important;
}

.salary-summary-card h6 {
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    margin-bottom: 0.5rem !important;
}

/* تحسين رأس التقرير - تدرج الأزرق والأبيض */
.salary-header-gradient {
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 30%, #60a5fa 60%, #93c5fd 100%) !important;
    border: none;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    color: #ffffff !important;
}

.salary-header-gradient h5,
.salary-header-gradient .text-white {
    color: #ffffff !important;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}

/* تحسين عرض الجدول - تدرج الأزرق والأبيض */
.salary-report-table {
    font-size: 0.8rem;
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    table-layout: auto;
}

.salary-report-table thead th {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 30%, #3b82f6 60%, #60a5fa 100%);
    color: #ffffff;
    font-weight: 700;
    padding: 0.5rem 0.4rem;
    border: none;
    text-align: center;
    vertical-align: middle;
    font-size: 0.7rem;
    white-space: nowrap;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    border-bottom: 3px solid #1e3a8a;
}

.salary-report-table thead th:first-child {
    border-top-right-radius: 12px;
}

.salary-report-table thead th:last-child {
    border-top-left-radius: 12px;
}

.salary-report-table tbody td {
    padding: 0.45rem 0.4rem;
    vertical-align: middle;
    text-align: center;
    border-bottom: 1px solid #e5e7eb;
    background-color: #ffffff;
    transition: all 0.2s ease;
    font-size: 0.75rem;
}

.salary-report-table tbody td .small,
.salary-report-table tbody td small {
    font-size: 0.65rem;
    line-height: 1.2;
}

.salary-report-table tbody tr {
    transition: all 0.2s ease;
}

.salary-report-table tbody tr:hover {
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.05) 0%, rgba(96, 165, 250, 0.1) 100%);
    transform: scale(1.01);
}

.salary-report-table tbody tr:nth-child(even) {
    background-color: #f8fafc;
}

.salary-report-table tbody tr:nth-child(even):hover {
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.08) 0%, rgba(96, 165, 250, 0.12) 100%);
}

.salary-report-table tbody td strong {
    font-weight: 700;
    color: #1e293b;
}

.salary-report-table .badge {
    font-size: 0.65rem;
    padding: 0.2rem 0.45rem;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    white-space: nowrap;
}

/* تحسين عرض تذييل الجدول - تدرج الأزرق الفاتح والأبيض */
.salary-report-table tfoot {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 50%, #bfdbfe 100%);
}

.salary-report-table tfoot td {
    font-weight: 800;
    font-size: 0.9rem;
    padding: 0.75rem 0.4rem;
    border-top: 3px solid #3b82f6;
    color: #1e3a8a;
}

.salary-report-table tfoot td:first-child {
    border-bottom-right-radius: 12px;
}

.salary-report-table tfoot td:last-child {
    border-bottom-left-radius: 12px;
}

/* تحسين عرض الأدوار */
.badge.bg-primary {
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%) !important;
    color: #ffffff;
}

.badge.bg-info {
    background: linear-gradient(135deg, #0369a1 0%, #0ea5e9 100%) !important;
    color: #ffffff;
}

.badge.bg-success {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%) !important;
    color: #ffffff;
}

/* تحسين حجم الأرقام */
.salary-report-table .text-success {
    font-weight: 800;
    font-size: 0.9rem;
    color: #059669 !important;
}

/* تحسين الاستجابة */
@media (max-width: 768px) {
    .salary-summary-card h2 {
        font-size: 1.1rem;
    }
    
    .salary-summary-card .card-body {
        padding: 0.65rem !important;
    }
    
    .salary-summary-card i {
        font-size: 1.25rem !important;
    }
    
    .salary-report-table {
        font-size: 0.7rem;
    }
    
    .salary-report-table thead th,
    .salary-report-table tbody td {
        padding: 0.4rem 0.3rem;
        font-size: 0.7rem;
    }
}
</style>
<?php endif; ?>

<?php if ($view !== 'advances'): ?>
<!-- تبويب قائمة الرواتب -->
<div id="listTab" class="tab-content">
    <!-- فلترة -->
    <div class="filter-card">
        <form method="GET" class="row g-3" action="<?php echo htmlspecialchars($currentUrl); ?>">
            <input type="hidden" name="page" value="salaries">
            <input type="hidden" name="view" value="list">
            <div class="col-md-3">
                <label class="form-label">الشهر</label>
                <select name="month" class="form-select" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">السنة</label>
                <select name="year" class="form-select" onchange="this.form.submit()">
                    <?php for ($y = date('Y'); $y >= date('Y') - 10; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">المستخدم</label>
                <select name="user_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">جميع المستخدمين</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedUserIdValid = isValidSelectValue($selectedUserId, $users, 'id');
                    foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $selectedUserIdValid && $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
           
        </form>
        
    </div>

    <!-- قائمة الرواتب - Employee Cards -->
    <?php if (empty($salaries)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
            <h5>لا توجد رواتب مسجلة</h5>
        </div>
    <?php else: ?>
        <div class="employee-cards-grid">
            <?php foreach ($salaries as $salary): ?>
                <?php 
                $hasSalaryId = !empty($salary['id']);
                $roleLabels = [
                    'production' => 'إنتاج',
                    'accountant' => 'محاسب',
                    'sales' => 'مندوب مبيعات'
                ];
                $roleLabel = $roleLabels[$salary['role']] ?? $salary['role'];
                $roleClass = $salary['role'] ?? 'production';
                $employeeName = htmlspecialchars($salary['full_name'] ?? $salary['username']);
                $firstName = mb_substr($employeeName, 0, 1, 'UTF-8');
                $status = $salary['status'] ?? 'calculated';
                
                // استخدام القيم مباشرة من جدول salaries دون إعادة حساب
                $userId = intval($salary['user_id'] ?? 0);
                $hourlyRate = cleanFinancialValue($salary['hourly_rate'] ?? $salary['current_hourly_rate'] ?? 0);
                $bonus = cleanFinancialValue($salary['bonus_standardized'] ?? ($salary['bonus'] ?? $salary['bonuses'] ?? 0));
                $deductions = cleanFinancialValue($salary['deductions'] ?? 0);
                
                // استخدام القيم مباشرة من قاعدة البيانات
                $baseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
                $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                $collectionsAmount = cleanFinancialValue($salary['collections_amount'] ?? 0);
                $totalAmount = cleanFinancialValue($salary['total_amount'] ?? 0);
                
                // الحصول على الشهر والسنة من الراتب (مطلوب لحساب نسبة التحصيلات)
                $salaryMonth = intval($salary['month'] ?? $selectedMonth);
                $salaryYear = intval($salary['year'] ?? $selectedYear);
                
                // التأكد من أن القيم صحيحة
                if ($salaryMonth <= 0 || $salaryMonth > 12) {
                    $salaryMonth = $selectedMonth;
                }
                if ($salaryYear <= 0 || $salaryYear > 9999) {
                    $salaryYear = $selectedYear;
                }
                
                // إعادة حساب نسبة التحصيلات للمندوبين الخاصة بالشهر المحدد فقط
                // يجب أن تُحسب نسبة التحصيلات من التحصيلات الخاصة بالشهر المحدد فقط (وليس من رصيد الخزنة الإجمالي)
                $userRole = $salary['role'] ?? 'production';
                $displayCashBalance = 0.0;
                
                if ($userRole === 'sales') {
                    // حساب نسبة التحصيلات: مطابق لصفحة "مرتبي" الخاصة بالمندوب
                    // يجب أن تشمل:
                    // 1. 2% من المبالغ المحصلة فعلياً من جدول collections
                    // 2. المكافآت المضافة مباشرة في pos.php (2% من creditUsed)
                    // استخدام القيمة المحفوظة في collections_bonus (تتضمن جميع المكافآت)
                    // وإذا لم تكن موجودة، نحسبها من جدول collections فقط (مطابق لصفحة "مرتبي")
                    
                    // حساب إجمالي التحصيلات من جدول collections فقط (مطابق لصفحة "مرتبي")
                    $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
                    $recalculatedCollectionsAmount = 0.0;
                    if (!empty($collectionsTableExists)) {
                        // التحقق من وجود عمود status
                        $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
                        $hasStatusColumn = !empty($statusColumnCheck);
                        
                        if ($hasStatusColumn) {
                            // حساب جميع التحصيلات (pending و approved) للشهر والسنة المحددين
                            $collectionsResult = $db->queryOne(
                                "SELECT COALESCE(SUM(amount), 0) as total_collections
                                 FROM collections
                                 WHERE collected_by = ? 
                                 AND MONTH(date) = ? 
                                 AND YEAR(date) = ?
                                 AND status IN ('pending', 'approved')",
                                [$userId, $salaryMonth, $salaryYear]
                            );
                        } else {
                            $collectionsResult = $db->queryOne(
                                "SELECT COALESCE(SUM(amount), 0) as total_collections
                                 FROM collections
                                 WHERE collected_by = ? 
                                 AND MONTH(date) = ? 
                                 AND YEAR(date) = ?",
                                [$userId, $salaryMonth, $salaryYear]
                            );
                        }
                        $recalculatedCollectionsAmount = (float)($collectionsResult['total_collections'] ?? 0);
                    }
                    
                    // استخدام نسبة التحصيلات المحفوظة في قاعدة البيانات
                    // (تتضمن 2% من collections + 2% من creditUsed المضافة في pos.php)
                    $savedCollectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                    
                    if ($savedCollectionsBonus > 0) {
                        // استخدام القيمة المحفوظة (تتضمن جميع المكافآت من pos.php)
                        $collectionsBonus = $savedCollectionsBonus;
                    } else {
                        // إذا لم تكن هناك قيمة محفوظة، احسبها من جدول collections فقط
                        // (لن تتضمن المكافآت من pos.php حتى يتم حساب الراتب)
                        $collectionsBonus = round($recalculatedCollectionsAmount * 0.02, 2);
                    }
                    
                    $collectionsAmount = $recalculatedCollectionsAmount;
                    $displayCashBalance = (float)$recalculatedCollectionsAmount;
                }
                
                // إعادة حساب الراتب الإجمالي من المكونات لضمان الدقة (مطابق لصفحة "مرتبي" و collapse)
                // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
                $totalSalary = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
                
                // التأكد من أن الراتب الإجمالي لا يكون سالباً
                $totalSalary = max(0, $totalSalary);
                
                // إضافة القيم إلى البيانات المرسلة للنموذج
                $salary['calculated_base_amount'] = $baseAmount;
                $salary['calculated_collections_bonus'] = $collectionsBonus;
                $salary['calculated_total_amount'] = $totalSalary; // استخدام الراتب المحسوب بدلاً من totalAmount
                
                // حساب المبلغ التراكمي والمتبقي بشكل صحيح
                // يجب استخدام الدالة المشتركة لحساب المبلغ التراكمي الذي يشمل المتبقي من الشهور السابقة
                require_once __DIR__ . '/../../includes/salary_calculator.php';
                
                // استخدام الدالة المشتركة لحساب المبلغ التراكمي (يشمل المتبقي من الشهور السابقة)
                // استخدام الراتب المحسوب من المكونات ($totalSalary) بدلاً من totalAmount من قاعدة البيانات
                $accumulatedData = calculateSalaryAccumulatedAmount(
                    $userId, 
                    intval($salary['id'] ?? 0), 
                    $totalSalary, 
                    $salaryMonth, 
                    $salaryYear, 
                    $db
                );
                
                $accumulated = $accumulatedData['accumulated'];
                // حساب المتبقي بناءً على التسويات والسلف (settlements_advances) وليس paid_amount
                // لتجنب المضاعفة لأن التسويات تُسجل في settlements_advances
                $settlementsAdvances = cleanFinancialValue($salary['settlements_advances'] ?? 0);
                $remaining = max(0, $accumulated - $settlementsAdvances);
                
                // حفظ القيمة المحسوبة للمبلغ التراكمي لاستخدامها في التسوية
                $salary['calculated_accumulated'] = $accumulated;
                
                // إضافة المتبقي إلى بيانات $salary لاستخدامه في نافذة تعديل الراتب
                // هذا يضمن أن نافذة تعديل الراتب تستخدم نفس قيمة المتبقي من بطاقة الموظف
                $salary['calculated_remaining'] = $remaining;
                
                $collapseId = 'collapse_' . ($salary['id'] ?? 'temp_' . uniqid());
                ?>
                <div class="employee-card">
                    <div class="employee-card-header">
                        <div class="profile-icon <?php echo $roleClass; ?>">
                            <?php echo $firstName; ?>
                        </div>
                        <div class="employee-info">
                            <h3 class="employee-name"><?php echo $employeeName; ?></h3>
                            <span class="role-badge <?php echo $roleClass; ?>"><?php echo htmlspecialchars($roleLabel); ?></span>
                        </div>
                    </div>
                    
                    <div class="salary-amount">
                        <?php echo formatCurrency($remaining); ?>
                    </div>
                    
                    <div>
                        <span class="status-badge <?php 
                            echo $status === 'approved' ? 'approved' : 
                                ($status === 'rejected' ? 'rejected' : 
                                ($status === 'paid' ? 'paid' : 
                                ($status === 'calculated' ? 'calculated' : 'pending'))); 
                        ?>">
                            <?php 
                            $statusLabels = [
                                'pending' => 'قيد الحساب',
                                'approved' => 'موافق عليه',
                                'rejected' => 'مرفوض',
                                'paid' => 'مدفوع',
                                'calculated' => 'محسوب'
                            ];
                            echo $statusLabels[$status] ?? 'غير محدد';
                            ?>
                        </span>
                        <?php if ($remaining > 0): ?>
                            <span class="status-badge pending ms-2">
                                متبقي: <?php echo formatCurrency($remaining); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <button class="btn btn-view-details" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false" aria-controls="<?php echo $collapseId; ?>">
                        <span>عرض التفاصيل</span>
                        <i class="bi bi-chevron-down arrow-icon"></i>
                    </button>
                    
                    <div class="collapse salary-details-collapse" id="<?php echo $collapseId; ?>">
                        <?php
                        // استخراج شهر وسنة الراتب من بيانات الراتب نفسه
                        $salaryMonthForDetails = null;
                        $salaryYearForDetails = null;
                        
                        // التحقق من نوع عمود month
                        $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
                        $monthType = $monthColumnCheck['Type'] ?? '';
                        $isMonthDate = stripos($monthType, 'date') !== false;
                        
                        if ($isMonthDate && !empty($salary['month'])) {
                            // إذا كان month من نوع DATE، استخراج الشهر والسنة منه
                            $monthDate = DateTime::createFromFormat('Y-m-d', $salary['month']);
                            if ($monthDate) {
                                $salaryMonthForDetails = (int)$monthDate->format('n');
                                $salaryYearForDetails = (int)$monthDate->format('Y');
                            }
                        } else {
                            // إذا كان month من نوع INT، استخدمه مباشرة
                            $salaryMonthForDetails = isset($salary['month']) ? (int)$salary['month'] : null;
                        }
                        
                        // استخراج السنة من عمود year إذا كان موجوداً
                        if (isset($salary['year']) && !empty($salary['year'])) {
                            $salaryYearForDetails = (int)$salary['year'];
                        }
                        
                        // إذا لم نتمكن من استخراج الشهر والسنة، استخدم القيم من الفلترة كبديل
                        if (!$salaryMonthForDetails || !$salaryYearForDetails) {
                            $salaryMonthForDetails = $selectedMonth;
                            $salaryYearForDetails = $selectedYear;
                        }
                        
                        // حساب بيانات التأخير
                        $userId = intval($salary['user_id'] ?? 0);
                        $delaySummary = calculateMonthlyDelaySummary($userId, $salaryMonthForDetails, $salaryYearForDetails);
                        
                        // الحصول على سعر الساعة
                        $hourlyRate = cleanFinancialValue($salary['hourly_rate'] ?? $salary['current_hourly_rate'] ?? 0);
                        $userRole = $salary['role'] ?? 'production';
                        
                        // استخدام القيم من جدول salaries وإعادة حساب الراتب الإجمالي من المكونات لضمان الدقة
                        // (مطابق لصفحة "مرتبي" الخاصة بالموظف)
                        $baseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
                        $bonus = cleanFinancialValue($salary['bonus_standardized'] ?? ($salary['bonus'] ?? $salary['bonuses'] ?? 0));
                        $deductions = cleanFinancialValue($salary['deductions'] ?? 0);
                        $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                        $collectionsAmount = cleanFinancialValue($salary['collections_amount'] ?? 0);
                        // التسويات والسلف (من العمود الجديد)
                        $settlementsAdvances = cleanFinancialValue($salary['settlements_advances'] ?? 0);
                        
                        // إعادة حساب نسبة التحصيلات للمندوبين (مطابق لصفحة "مرتبي")
                        // استخدام القيمة المحفوظة في collections_bonus (تتضمن جميع المكافآت من pos.php)
                        // وإذا لم تكن موجودة، نحسبها من جدول collections للشهر المحدد فقط (مطابق لصفحة "مرتبي")
                        if ($userRole === 'sales') {
                            // استخدام القيمة المحفوظة في collections_bonus (تتضمن جميع المكافآت من pos.php)
                            $savedCollectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                            
                            if ($savedCollectionsBonus > 0) {
                                // استخدام القيمة المحفوظة (تتضمن جميع المكافآت من pos.php)
                                $collectionsBonus = $savedCollectionsBonus;
                                // الحصول على collections_amount المحفوظة أيضاً
                                $collectionsAmount = cleanFinancialValue($salary['collections_amount'] ?? 0);
                            } else {
                                // إذا لم تكن هناك قيمة محفوظة، احسبها من جدول collections للشهر المحدد فقط
                                require_once __DIR__ . '/../../includes/salary_calculator.php';
                                $collectionsAmount = calculateSalesCollections($userId, $salaryMonthForDetails, $salaryYearForDetails);
                                $collectionsBonus = round($collectionsAmount * 0.02, 2);
                            }
                        }
                        
                        // إعادة حساب الراتب الإجمالي من المكونات لضمان الدقة (مطابق لصفحة "مرتبي")
                        // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
                        // ملاحظة: لا نخصم التسويات والسلف هنا لأنها تُخصم عند حساب المتبقي فقط
                        $totalSalary = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
                        
                        // التأكد من أن الراتب الإجمالي لا يكون سالباً
                        $totalSalary = max(0, $totalSalary);
                        
                        // حساب المبلغ التراكمي والمتبقي بشكل صحيح
                        // يجب استخدام الدالة المشتركة لحساب المبلغ التراكمي الذي يشمل المتبقي من الشهور السابقة
                        require_once __DIR__ . '/../../includes/salary_calculator.php';
                        
                        // الحصول على الشهر والسنة من الراتب
                        $salaryMonth = intval($salary['month'] ?? $selectedMonth);
                        $salaryYear = intval($salary['year'] ?? $selectedYear);
                        
                        // التأكد من أن القيم صحيحة
                        if ($salaryMonth <= 0 || $salaryMonth > 12) {
                            $salaryMonth = $selectedMonth;
                        }
                        if ($salaryYear <= 0 || $salaryYear > 9999) {
                            $salaryYear = $selectedYear;
                        }
                        
                        // استخدام الدالة المشتركة لحساب المبلغ التراكمي (يشمل المتبقي من الشهور السابقة)
                        $accumulatedData = calculateSalaryAccumulatedAmount(
                            $userId, 
                            intval($salary['id'] ?? 0), 
                            $totalSalary, 
                            $salaryMonth, 
                            $salaryYear, 
                            $db
                        );
                        
                        $accumulated = $accumulatedData['accumulated'];
                        // حساب المتبقي بناءً على التسويات والسلف (settlements_advances) وليس paid_amount
                        // لتجنب المضاعفة لأن التسويات تُسجل في settlements_advances
                        $remaining = max(0, $accumulated - $settlementsAdvances);
                        
                        // تحديث القيمة المحسوبة للمبلغ التراكمي في البيانات
                        $salary['calculated_accumulated'] = $accumulated;
                        
                        // تحديث نسبة التحصيلات المحسوبة في البيانات لاستخدامها في نافذة تعديل الراتب
                        $salary['calculated_collections_bonus'] = $collectionsBonus;
                        
                        // تحديث المتبقي في بيانات $salary لاستخدامه في نافذة تعديل الراتب
                        $salary['calculated_remaining'] = $remaining;
                        ?>
                        <div class="detail-row">
                            <span class="detail-label">سعر الساعة:</span>
                            <span class="detail-value"><?php echo formatCurrency($hourlyRate); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">عدد الساعات:</span>
                            <?php 
                            // استخراج شهر وسنة الراتب من بيانات الراتب نفسه
                            $salaryMonthForModal = null;
                            $salaryYearForModal = null;
                            
                            // التحقق من نوع عمود month
                            $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
                            $monthType = $monthColumnCheck['Type'] ?? '';
                            $isMonthDate = stripos($monthType, 'date') !== false;
                            
                            if ($hasSalaryId && $isMonthDate && !empty($salary['month'])) {
                                // إذا كان month من نوع DATE، استخراج الشهر والسنة منه
                                $monthDate = DateTime::createFromFormat('Y-m-d', $salary['month']);
                                if ($monthDate) {
                                    $salaryMonthForModal = (int)$monthDate->format('n');
                                    $salaryYearForModal = (int)$monthDate->format('Y');
                                }
                            } elseif ($hasSalaryId && isset($salary['month'])) {
                                // إذا كان month من نوع INT، استخدمه مباشرة
                                $salaryMonthForModal = (int)$salary['month'];
                            }
                            
                            // استخراج السنة من عمود year إذا كان موجوداً
                            if ($hasSalaryId && isset($salary['year']) && !empty($salary['year'])) {
                                $salaryYearForModal = (int)$salary['year'];
                            }
                            
                            // إذا لم نتمكن من استخراج الشهر والسنة، استخدم القيم من الفلترة كبديل
                            if (!$salaryMonthForModal || !$salaryYearForModal) {
                                $salaryMonthForModal = $selectedMonth;
                                $salaryYearForModal = $selectedYear;
                            }
                            
                            // حساب الساعات مباشرة من الحضور لضمان الدقة (مطابقة مع صفحة الحضور)
                            $actualHoursForModal = calculateMonthlyHours($userId, $salaryMonthForModal, $salaryYearForModal);
                            
                            // تحديث total_hours تلقائياً إذا كان مختلفاً عن القيمة الفعلية
                            if ($hasSalaryId) {
                                $savedTotalHoursForModal = floatval($salary['total_hours'] ?? 0);
                                if (abs($actualHoursForModal - $savedTotalHoursForModal) > 0.01) {
                                    // تحديث total_hours في قاعدة البيانات
                                    try {
                                        $db->execute(
                                            "UPDATE salaries SET total_hours = ? WHERE id = ?",
                                            [$actualHoursForModal, $salary['id']]
                                        );
                                        // إعادة جلب البيانات من قاعدة البيانات للتأكد من الحصول على القيمة المحدثة
                                        $updatedSalary = $db->queryOne(
                                            "SELECT total_hours FROM salaries WHERE id = ?",
                                            [$salary['id']]
                                        );
                                        if ($updatedSalary) {
                                            $salary['total_hours'] = floatval($updatedSalary['total_hours'] ?? $actualHoursForModal);
                                        } else {
                                            $salary['total_hours'] = $actualHoursForModal;
                                        }
                                    } catch (Exception $e) {
                                        error_log("Error updating total_hours for salary ID {$salary['id']} in modal: " . $e->getMessage());
                                    }
                                }
                            }
                            ?>
                            <span class="detail-value"><?php echo formatHours($actualHoursForModal); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">إجمالي التأخير:</span>
                            <span class="detail-value"><?php echo number_format($delaySummary['total_minutes'] ?? 0, 2); ?> دقيقة</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">متوسط التأخير:</span>
                            <span class="detail-value"><?php echo number_format($delaySummary['average_minutes'] ?? 0, 2); ?> دقيقة</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">الراتب الأساسي:</span>
                            <span class="detail-value"><?php echo formatCurrency($baseAmount); ?></span>
                        </div>
                        <?php if ($userRole === 'sales'): ?>
                        <div class="detail-row">
                            <span class="detail-label">نسبة التحصيلات:</span>
                            <span class="detail-value text-info">
                                <?php echo formatCurrency($collectionsBonus); ?>                                
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span class="detail-label">المكافآت:</span>
                            <span class="detail-value"><?php echo formatCurrency($bonus); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">الخصومات:</span>
                            <span class="detail-value"><?php echo formatCurrency($deductions); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">التسويات والسلف:</span>
                            <span class="detail-value <?php echo $settlementsAdvances > 0 ? 'text-danger' : ''; ?>"><?php echo formatCurrency($settlementsAdvances); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label"><strong>الراتب الإجمالي:</strong></span>
                            <span class="detail-value"><strong><?php echo formatCurrency($totalSalary); ?></strong></span>
                        </div>
                        <?php if ($userRole === 'sales' && $collectionsBonus > 0): ?>
                        <div class="detail-row" style="border-bottom: none; padding-top: 8px;">
                            <span class="detail-label" style="font-size: 12px; color: #6b7280; font-weight: normal;">
                                <i class="bi bi-info-circle me-1"></i>
                                ملاحظة: الراتب الإجمالي يتضمن نسبة التحصيلات (<?php echo formatCurrency($collectionsBonus); ?>)
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasSalaryId): ?>
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #f3f4f6;">
                        <div class="detail-row">
                            <span class="detail-label">المتبقي:</span>
                            <span class="detail-value <?php echo $remaining > 0 ? 'text-warning' : 'text-success'; ?>">
                                <?php echo formatCurrency($remaining); ?>
                            </span>
                        </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-actions">
                            <button class="btn btn-warning btn-sm" 
                                    onclick="openModifyModal(<?php echo $hasSalaryId ? $salary['id'] : 0; ?>, <?php echo htmlspecialchars(json_encode($salary), ENT_QUOTES); ?>)" 
                                    title="تعديل">
                                <i class="bi bi-pencil me-1"></i>تعديل
                            </button>
                            
                            <?php 
                            // استخدام القيم المحسوبة من collapse (الحساب الثاني) التي تطابق بطاقة الموظف
                            // هذه القيم تم حسابها في السطر 3320-3341 باستخدام $totalSalary المحسوب من المكونات
                            // وليس $totalAmount من قاعدة البيانات
                            // استخدام القيم المحسوبة من الحساب الثاني (داخل collapse)
                            $calculatedRemaining = isset($salary['calculated_remaining']) ? (float)$salary['calculated_remaining'] : (float)$remaining;
                            $calculatedAccumulated = isset($salary['calculated_accumulated']) ? (float)$salary['calculated_accumulated'] : (float)$accumulated;
                            
                            // استخدام القيم المحسوبة من الحساب الثاني في الشرط أيضاً
                            if ($hasSalaryId && $calculatedRemaining > 0): ?>
                            <?php
                            // استخدام القيم المحسوبة من الحساب الثاني مباشرة
                            $settleRemaining = $calculatedRemaining;
                            $settleAccumulated = $calculatedAccumulated;
                            
                            // التأكد من أن القيم المحسوبة موجودة في مصفوفة $salary لتمريرها في JSON
                            $salaryForJson = $salary;
                            
                            // التأكد من وجود user_id - استخدم القيمة من $salary أولاً، ثم $userId
                            if (empty($salaryForJson['user_id']) || intval($salaryForJson['user_id']) <= 0) {
                                if (!empty($userId) && intval($userId) > 0) {
                                    $salaryForJson['user_id'] = intval($userId);
                                } else {
                                    // إذا لم يكن user_id موجوداً، استخدم user_id من $salary مباشرة
                                    $salaryForJson['user_id'] = intval($salary['user_id'] ?? 0);
                                }
                            } else {
                                $salaryForJson['user_id'] = intval($salaryForJson['user_id']);
                            }
                            
                            // التأكد من استخدام القيم المحسوبة من collapse (الحساب الثاني)
                            // هذه القيم تم تعيينها في السطر 3332 و 3341
                            if (!isset($salaryForJson['calculated_remaining'])) {
                                $salaryForJson['calculated_remaining'] = $settleRemaining;
                            }
                            if (!isset($salaryForJson['calculated_accumulated'])) {
                                $salaryForJson['calculated_accumulated'] = $settleAccumulated;
                            }
                            
                            // التأكد من أن القيم المستخدمة في openSettleModal مطابقة للقيم المعروضة في بطاقة الموظف
                            // استخدام القيم من $salary['calculated_remaining'] و $salary['calculated_accumulated']
                            // التي تم تعيينها في السطر 3332 و 3341 من الحساب الثاني
                            $settleRemaining = (float)$salaryForJson['calculated_remaining'];
                            $settleAccumulated = (float)$salaryForJson['calculated_accumulated'];
                            ?>
                            <button class="btn btn-success btn-sm" 
                                    onclick="openSettleModal(<?php echo $salary['id']; ?>, <?php echo htmlspecialchars(json_encode($salaryForJson, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo $settleRemaining; ?>, <?php echo $settleAccumulated; ?>)" 
                                    title="تسوية مستحقات">
                                <i class="bi bi-cash-coin me-1"></i>تسوية
                            </button>
                            <?php elseif ($hasSalaryId && $remaining <= 0): ?>
                            <button class="btn btn-success btn-sm" disabled title="لا يوجد مبلغ متبقي">
                                <i class="bi bi-cash-coin me-1"></i>تسوية
                            </button>
                            <?php else: ?>
                            <button class="btn btn-success btn-sm" disabled title="يجب حساب الراتب أولاً">
                                <i class="bi bi-cash-coin me-1"></i>تسوية
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($hasSalaryId): ?>
                            <button class="btn btn-primary btn-sm" 
                                    onclick="openStatementModal(<?php echo $salary['id']; ?>, <?php echo $userId; ?>, '<?php echo htmlspecialchars($employeeName); ?>')" 
                                    title="كشف حساب">
                                <i class="bi bi-file-earmark-text me-1"></i>كشف حساب
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- تبويب الطلبات المعلقة (للمدير فقط) -->
<?php if ($view !== 'advances' && $currentUser['role'] === 'manager' && !empty($pendingModifications)): ?>
<div id="pendingTab" class="tab-content" style="display: <?php echo $view === 'pending' ? 'block' : 'none'; ?>;">
    <div class="salary-card">
        <div class="salary-card-header" style="background: #f59e0b;">
            <h5 class="mb-0"><i class="bi bi-hourglass-split me-2"></i>طلبات تعديل رواتب معلقة (<?php echo count($pendingModifications); ?>)</h5>
        </div>
        <div class="salary-table-wrapper">
            <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>المستخدم</th>
                            <th>المحاسب</th>
                            <th>التاريخ</th>
                            <th>التفاصيل</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingModifications as $mod): ?>
                            <?php
                            // استخراج بيانات التعديل من notes
                            $modificationDetails = '';
                            $notesText = '';
                            $dataStart = strpos($mod['notes'] ?? '', '[DATA]:');
                            if ($dataStart !== false) {
                                $jsonData = substr($mod['notes'], $dataStart + 7);
                                $modificationData = json_decode($jsonData, true);
                                
                                if ($modificationData) {
                                    $bonus = floatval($modificationData['bonus'] ?? 0);
                                    $deductions = floatval($modificationData['deductions'] ?? 0);
                                    $originalBonus = floatval($modificationData['original_bonus'] ?? 0);
                                    $originalDeductions = floatval($modificationData['original_deductions'] ?? 0);
                                    $notesText = $modificationData['notes'] ?? '';
                                    
                                    // الحصول على بيانات الراتب
                                    $entityColumn = getApprovalsEntityColumn();
                                    $salaryId = intval($mod[$entityColumn] ?? 0);
                                    $salary = $db->queryOne(
                                        "SELECT base_amount, total_amount FROM salaries WHERE id = ?",
                                        [$salaryId]
                                    );
                                    
                                    if ($salary) {
                                        $baseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
                                        $currentTotal = cleanFinancialValue($salary['total_amount'] ?? 0);
                                        $newTotal = $baseAmount + $bonus - $deductions;
                                        
                                        $modificationDetails = sprintf(
                                            '<div class="small">' .
                                            '<div class="mb-1"><strong>الراتب الحالي:</strong> %s</div>' .
                                            '<div class="mb-1"><strong>المكافأة:</strong> %s → %s</div>' .
                                            '<div class="mb-1"><strong>الخصومات:</strong> %s → %s</div>' .
                                            '<div class="mb-1"><strong>الراتب الجديد:</strong> <span class="text-success">%s</span></div>' .
                                            '%s' .
                                            '</div>',
                                            formatCurrency($currentTotal),
                                            formatCurrency($originalBonus),
                                            formatCurrency($bonus),
                                            formatCurrency($originalDeductions),
                                            formatCurrency($deductions),
                                            formatCurrency($newTotal),
                                            $notesText ? '<div class="mt-2 text-muted"><em>ملاحظة: ' . htmlspecialchars($notesText) . '</em></div>' : ''
                                        );
                                    } else {
                                        $modificationDetails = sprintf(
                                            '<div class="small">' .
                                            '<div class="mb-1"><strong>المكافأة:</strong> %s</div>' .
                                            '<div class="mb-1"><strong>الخصومات:</strong> %s</div>' .
                                            '%s' .
                                            '</div>',
                                            formatCurrency($bonus),
                                            formatCurrency($deductions),
                                            $notesText ? '<div class="mt-2 text-muted"><em>ملاحظة: ' . htmlspecialchars($notesText) . '</em></div>' : ''
                                        );
                                    }
                                }
                            }
                            
                            // إذا لم يتم استخراج البيانات، عرض الملاحظات الأصلية
                            if (empty($modificationDetails)) {
                                $modificationDetails = htmlspecialchars($mod['notes'] ?? '-');
                            }
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($mod['full_name'] ?? $mod['username']); ?></strong></td>
                                <td><?php 
                                    $requester = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$mod['requested_by']]);
                                    echo htmlspecialchars($requester['full_name'] ?? $requester['username']);
                                ?></td>
                                <td><?php echo formatDateTime($mod['created_at']); ?></td>
                                <td><?php echo $modificationDetails; ?></td>
                                <td>
                                    <a href="<?php echo $currentUrl; ?>?page=salaries&approval_id=<?php echo $mod['id']; ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>&view=pending" 
                                       class="btn btn-sm btn-primary-salary">
                                        <i class="bi bi-eye"></i> مراجعة
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal تفاصيل الراتب -->
<div class="modal fade d-none d-md-block" id="salaryDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">تفاصيل الراتب</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="salaryDetailsContent">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal تعديل الراتب -->
<div class="modal fade d-none d-md-block" id="modifySalaryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">تعديل الراتب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="modifySalaryForm">
                <input type="hidden" name="action" value="modify_salary">
                <input type="hidden" name="salary_id" id="modifySalaryId">
                <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المستخدم</label>
                        <input type="text" class="form-control" id="modifyUserName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الراتب الأساسي</label>
                        <input type="text" class="form-control" id="modifyBaseAmount" readonly>
                    </div>
                    <input type="hidden" id="modifyCollectionsBonus" value="0">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modifyBonus" class="form-label">مكافأة</label>
                            <input type="number" step="0.01" class="form-control" id="modifyBonus" name="bonus" value="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modifyDeductions" class="form-label">خصومات</label>
                            <input type="number" step="0.01" class="form-control" id="modifyDeductions" name="deductions" value="0" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="modifyNotes" class="form-label">ملاحظات</label>
                        <textarea class="form-control" id="modifyNotes" name="notes" rows="3" 
                                  placeholder="اذكر سبب التعديل (اختياري)"></textarea>
                    </div>
                    <div class="alert alert-info">
                        <strong>الراتب الجديد:</strong> <span id="newTotalAmount">0.00</span>
                    </div>
                    <?php if ($currentUser['role'] === 'accountant'): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-2"></i>
                            هذا التعديل يحتاج موافقة من المدير
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle me-2"></i>
                        <?php echo $currentUser['role'] === 'accountant' ? 'إرسال للموافقة' : 'تأكيد التعديل'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($view === 'advances'): ?>
<!-- قسم السلف - جدول طلبات السلف مع الإحصائيات والفلاتر -->
<?php 
$advanceStatusFilter = $_GET['advance_status'] ?? 'all';
$advanceMonthFilter = isset($_GET['advance_month']) ? intval($_GET['advance_month']) : 0;
$advanceYearFilter = isset($_GET['advance_year']) ? intval($_GET['advance_year']) : 0;

$advanceStatusLabels = [
    'pending' => ['class' => 'warning', 'text' => 'قيد الانتظار (بانتظار المحاسب)'],
    'accountant_approved' => ['class' => 'info', 'text' => 'تم الاستلام من المحاسب'],
    'manager_approved' => ['class' => 'success', 'text' => 'تمت الموافقة النهائية'],
    'rejected' => ['class' => 'danger', 'text' => 'مرفوض']
];
?>

<!-- إحصائيات -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-card-icon warning me-3">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <div class="text-muted small">طلبات بانتظار المحاسب</div>
                    <div class="h4 mb-0"><?php echo $advanceStats['pending']; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-card-icon info me-3">
                    <i class="bi bi-person-check"></i>
                </div>
                <div>
                    <div class="text-muted small">بانتظار المدير</div>
                    <div class="h4 mb-0"><?php echo $advanceStats['accountant_approved']; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-card-icon success me-3">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <div class="text-muted small">طلبات معتمدة</div>
                    <div class="h4 mb-0"><?php echo $advanceStats['manager_approved']; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-card-icon danger me-3">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div>
                    <div class="text-muted small">طلبات مرفوضة</div>
                    <div class="h4 mb-0"><?php echo $advanceStats['rejected']; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- فلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="salaries">
            <input type="hidden" name="view" value="advances">
            <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
            <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
            <div class="col-md-4">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="advance_status" onchange="this.form.submit()">
                    <option value="all" <?php echo $advanceStatusFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                    <option value="pending" <?php echo $advanceStatusFilter === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                    <option value="accountant_approved" <?php echo $advanceStatusFilter === 'accountant_approved' ? 'selected' : ''; ?>>بانتظار المدير</option>
                    <option value="manager_approved" <?php echo $advanceStatusFilter === 'manager_approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo $advanceStatusFilter === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">الشهر</label>
                <select class="form-select" name="advance_month" onchange="this.form.submit()">
                    <option value="0">الكل</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $advanceMonthFilter === $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">السنة</label>
                <select class="form-select" name="advance_year" onchange="this.form.submit()">
                    <option value="0">الكل</option>
                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $advanceYearFilter === $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- قائمة طلبات السلفة -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة طلبات السلفة</h5>
        <?php if ($advanceStats['pending'] + $advanceStats['accountant_approved'] > 0): ?>
            <span class="badge bg-light text-dark">إجمالي المبالغ المعلقة: <?php echo formatCurrency($advanceStats['pending_amount']); ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>التاريخ</th>
                        <th>المبلغ</th>
                        <th>السبب</th>
                        <th>الحالة</th>
                        <th>المحاسب</th>
                        <th>المدير</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($advances)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">لا توجد طلبات سلفة</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($advances as $index => $request): ?>
                            <?php 
                            $statusInfo = $advanceStatusLabels[$request['status']] ?? ['class' => 'secondary', 'text' => $request['status']];
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['user_name'] ?? $request['username']); ?></strong>
                                    <br><small class="text-muted">@<?php echo htmlspecialchars($request['username']); ?></small>
                                </td>
                                <td>
                                    <?php echo formatDate($request['request_date']); ?>
                                    <br><small class="text-muted"><?php echo formatDateTime($request['created_at']); ?></small>
                                </td>
                                <td><strong class="text-primary"><?php echo formatCurrency($request['amount']); ?></strong></td>
                                <td>
                                    <?php if (!empty($request['reason'])): ?>
                                        <small><?php echo htmlspecialchars($request['reason']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $statusInfo['class']; ?>">
                                        <?php echo $statusInfo['text']; ?>
                                    </span>
                                    <?php if ($request['status'] === 'rejected' && !empty($request['notes'])): ?>
                                        <br><small class="text-danger">سبب الرفض: <?php echo htmlspecialchars($request['notes']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($request['accountant_name'])): ?>
                                        <small><?php echo htmlspecialchars($request['accountant_name']); ?><br><?php echo formatDateTime($request['accountant_approved_at']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($request['manager_name'])): ?>
                                        <small><?php echo htmlspecialchars($request['manager_name']); ?><br><?php echo formatDateTime($request['manager_approved_at']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    // للمحاسب: يمكنه استلام الطلبات المعلقة أو الموافقة النهائية مباشرة
                                    if ($request['status'] === 'pending' && $currentUser['role'] === 'accountant'): 
                                    ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <form method="POST" onsubmit="return confirm('تأكيد الموافقة النهائية على السلفة وخصمها من الراتب وطباعة الفاتورة؟');" style="display: inline;">
                                                <input type="hidden" name="action" value="accountant_approve">
                                                <input type="hidden" name="advance_id" value="<?php echo $request['id']; ?>">
                                                <input type="hidden" name="final_approval" value="1">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check-circle-fill me-1"></i>موافقة نهائية
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('تأكيد استلام الطلب من قبل المحاسب فقط (بدون خصم)؟');" style="display: inline;">
                                                <input type="hidden" name="action" value="accountant_approve">
                                                <input type="hidden" name="advance_id" value="<?php echo $request['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-info">
                                                    <i class="bi bi-check-circle me-1"></i>استلام فقط
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="openRejectModal(<?php echo $request['id']; ?>)">
                                                <i class="bi bi-x-circle me-1"></i>رفض
                                            </button>
                                        </div>
                                    <?php 
                                    // للمدير: يمكنه الموافقة مباشرة على الطلبات المعلقة (pending) أو الموافق عليها من المحاسب (accountant_approved)
                                    elseif (in_array($currentUser['role'] ?? '', ['manager', 'developer'], true) && in_array($request['status'], ['pending', 'accountant_approved'])): 
                                    ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <form method="POST" onsubmit="return confirm('<?php echo $request['status'] === 'pending' ? 'تأكيد الموافقة المباشرة على السلفة وخصمها من الراتب؟ (سيتم تجاوز موافقة المحاسب)' : 'تأكيد الموافقة النهائية على السلفة؟'; ?>');">
                                                <input type="hidden" name="action" value="manager_approve">
                                                <input type="hidden" name="advance_id" value="<?php echo $request['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check-circle-fill me-1"></i>
                                                    <?php echo $request['status'] === 'pending' ? 'موافقة مباشرة' : 'موافقة'; ?>
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="openRejectModal(<?php echo $request['id']; ?>)">
                                                <i class="bi bi-x-circle me-1"></i>رفض
                                            </button>
                                        </div>
                                    <?php elseif ($request['status'] === 'manager_approved'): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <a href="<?php echo getRelativeUrl('print_advance.php?id=' . $request['id']); ?>" 
                                               target="_blank" 
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-printer me-1"></i>طباعة الفاتورة
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal طلب سلفة جديدة -->
<div class="modal fade d-none d-md-block" id="requestAdvanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>طلب سلفة جديدة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="request_advance">
                <div class="modal-body">
                    <?php if (in_array($currentUser['role'] ?? '', ['accountant', 'manager', 'developer'], true)): ?>
                    <div class="mb-3">
                        <label class="form-label">الموظف <span class="text-danger">*</span></label>
                        <select name="user_id" class="form-select" required>
                            <option value="">اختر الموظف</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">مبلغ السلفة (ج.م) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="1" required 
                               placeholder="0.00">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">سبب الطلب (اختياري)</label>
                        <textarea name="reason" class="form-control" rows="3" 
                                  placeholder="اذكر سبب طلب السلفة..."></textarea>
                        <small class="text-muted">يمكنك ترك هذا الحقل فارغاً</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تاريخ الطلب</label>
                        <input type="date" name="request_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>ملاحظة:</strong> سيتم خصم مبلغ السلفة من راتبك القادم بعد موافقة المحاسب والمدير.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-send me-1"></i>إرسال الطلب
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal رفض السلفة -->
<div class="modal fade d-none d-md-block" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>رفض طلب السلفة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="advance_id" id="rejectAdvanceId">
                <input type="hidden" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                <input type="hidden" name="year" value="<?php echo htmlspecialchars($selectedYear); ?>">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">سبب الرفض <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" required placeholder="اذكر سبب الرفض"></textarea>
                    </div>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        سيتم إرسال السبب للموظف في إشعار فوري.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">رفض الطلب</button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php endif; ?>

<script>
// ===== دوال أساسية للموبايل - متاحة لجميع الأقسام =====
function isMobile() {
    // التحقق من عرض الشاشة
    const width = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
    return width <= 768;
}

// دالة آمنة لفتح النماذج - تتحقق من الموبايل أولاً
function safeShowModal(modalId, cardId) {
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById(cardId);
        if (card) {
            card.style.display = 'block';
            setTimeout(() => scrollToElement(card), 50);
        }
        return null;
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
            modalInstance.show();
            return modalInstance;
        }
    }
    return null;
}

// التأكد من أن النماذج مخفية على الموبايل حتى لو تم فتحها
(function() {
    function hideModalsOnMobile() {
        if (isMobile()) {
            // إخفاء جميع النماذج
            const modals = document.querySelectorAll('.modal.d-none.d-md-block, .modal.show, .modal[class*="modal"]');
            modals.forEach(function(modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
                // إغلاق أي modal instance مفتوح
                const modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            });
            
            // إزالة backdrop
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(function(backdrop) {
                backdrop.remove();
            });
            
            // تنظيف body classes
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    }
    
    // تشغيل عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hideModalsOnMobile);
    } else {
        hideModalsOnMobile();
    }
    
    // تشغيل عند تغيير حجم النافذة
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(hideModalsOnMobile, 100);
    });
    
    // تشغيل بشكل دوري للتأكد (في حالة فتح modal بطريقة غير متوقعة)
    setInterval(hideModalsOnMobile, 300);
    
    // مراقبة DOM للتغييرات (MutationObserver)
    const observer = new MutationObserver(function(mutations) {
        if (isMobile()) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    const target = mutation.target;
                    if (target.classList && target.classList.contains('modal') && target.classList.contains('show')) {
                        hideModalsOnMobile();
                    }
                }
                if (mutation.addedNodes) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            if (node.classList && node.classList.contains('modal-backdrop')) {
                                node.remove();
                            }
                            if (node.classList && node.classList.contains('modal') && node.classList.contains('show')) {
                                hideModalsOnMobile();
                            }
                        }
                    });
                }
            });
        }
    });
    
    // بدء المراقبة
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class']
    });
})();

function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80; // مساحة للـ header
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, elementTop - offset),
                behavior: 'smooth'
            });
        });
    }, 200);
}

function closeAllForms() {
    // إغلاق جميع Cards على الموبايل
    const cards = [
        'salaryDetailsCard', 'modifySalaryCard', 'requestAdvanceCard',
        'rejectCard', 'settleSalaryCard', 'salaryStatementCard'
    ];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            // إخفاء Card أولاً
            card.style.display = 'none';
            // إعادة تعيين النموذج بعد إخفاء Card (لكن لا نمسح القيم المخفية)
            const form = card.querySelector('form');
            if (form) {
                // إعادة تعيين النموذج لكن مع الحفاظ على العناصر المخفية
                form.reset();
            }
        }
    });
    
    // إغلاق جميع Modals على الكمبيوتر
    const modals = [
        'salaryDetailsModal', 'modifySalaryModal', 'requestAdvanceModal',
        'rejectModal', 'settleSalaryModal', 'salaryStatementModal'
    ];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// Export Functions
function exportToPDF() {
    // Placeholder for PDF export - can be connected to backend handler
    alert('تصدير PDF - سيتم إضافة هذه الوظيفة قريباً');
    // Example: window.location.href = '<?php echo $currentUrl; ?>?page=salaries&export=pdf&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>';
}

function exportToExcel() {
    // Placeholder for Excel export - can be connected to backend handler
    alert('تصدير Excel - سيتم إضافة هذه الوظيفة قريباً');
    // Example: window.location.href = '<?php echo $currentUrl; ?>?page=salaries&export=excel&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>';
}

// Arrow rotation on collapse toggle
document.addEventListener('DOMContentLoaded', function() {
    const collapseButtons = document.querySelectorAll('.btn-view-details[data-bs-toggle="collapse"]');
    collapseButtons.forEach(button => {
        button.addEventListener('click', function() {
            const arrowIcon = this.querySelector('.arrow-icon');
            if (arrowIcon) {
                // Bootstrap will handle the aria-expanded attribute
                // CSS will handle the rotation based on aria-expanded
            }
        });
    });
});

function calculateAllSalaries() {
    if (confirm('هل تريد حساب رواتب جميع المستخدمين للشهر الحالي؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="calculate_all">
            <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
            <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// ===== دوال إغلاق Cards =====
function closeSalaryDetailsCard() {
    const card = document.getElementById('salaryDetailsCard');
    if (card) card.style.display = 'none';
}

function closeModifySalaryCard() {
    const card = document.getElementById('modifySalaryCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeRequestAdvanceCard() {
    const card = document.getElementById('requestAdvanceCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeRejectCard() {
    const card = document.getElementById('rejectCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeSettleSalaryCard() {
    const card = document.getElementById('settleSalaryCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeSalaryStatementCard() {
    const card = document.getElementById('salaryStatementCard');
    if (card) card.style.display = 'none';
}

function viewSalaryDetails(salaryId) {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('salaryDetailsCard');
        const content = document.getElementById('salaryDetailsContentCard');
        if (card && content) {
            // تحميل AJAX
            fetch(<?php echo json_encode($currentUrl, JSON_UNESCAPED_SLASHES); ?> + '?page=salaries&ajax=1&id=' + salaryId)
                .then(response => response.text())
                .then(html => {
                    content.innerHTML = html;
                    card.style.display = 'block';
                    setTimeout(() => scrollToElement(card), 50);
                })
                .catch(error => {
                    content.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل التفاصيل</div>';
                    card.style.display = 'block';
                    setTimeout(() => scrollToElement(card), 50);
                });
        }
    } else {
        const modal = document.getElementById('salaryDetailsModal');
        const content = document.getElementById('salaryDetailsContent');
        if (modal && content) {
            fetch(<?php echo json_encode($currentUrl, JSON_UNESCAPED_SLASHES); ?> + '?page=salaries&ajax=1&id=' + salaryId)
                .then(response => response.text())
                .then(html => {
                    content.innerHTML = html;
                    // استخدام الدالة الآمنة لفتح النموذج
                    if (!isMobile()) {
                        new bootstrap.Modal(modal).show();
                    } else {
                        // على الموبايل: استخدام Card
                        const card = document.getElementById('salaryDetailsCard');
                        if (card) {
                            const cardContent = document.getElementById('salaryDetailsContentCard');
                            if (cardContent) {
                                cardContent.innerHTML = html;
                            }
                            card.style.display = 'block';
                            setTimeout(() => scrollToElement(card), 50);
                        }
                    }
                })
                .catch(error => {
                    content.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل التفاصيل</div>';
                });
        }
    }
}

function openModifyModal(salaryId, salaryData) {
    closeAllForms();
    
    // التحقق من أن salaryId صحيح (ليس null أو 0 أو 'null')
    const validSalaryId = (salaryId && salaryId !== 'null' && salaryId !== null && salaryId !== 0) ? salaryId : '';
    
    // استخدام المتبقي كالراتب الأساسي الفعلي من بطاقة الموظف
    const remaining = salaryData.calculated_remaining !== undefined ? salaryData.calculated_remaining : 
                      (salaryData.remaining || 
                       ((salaryData.calculated_accumulated || salaryData.accumulated_amount || salaryData.total_amount || 0) - (salaryData.paid_amount || 0)));
    const baseAmount = Math.max(0, remaining);
    const collectionsBonus = salaryData.calculated_collections_bonus !== undefined ? salaryData.calculated_collections_bonus : (salaryData.collections_bonus || 0);
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('modifySalaryCard');
        if (card) {
            document.getElementById('modifySalaryIdCard').value = validSalaryId;
            document.getElementById('modifyUserNameCard').value = salaryData.full_name || salaryData.username;
            
            const baseAmountElement = document.getElementById('modifyBaseAmountCard');
            baseAmountElement.value = formatCurrency(baseAmount);
            baseAmountElement.setAttribute('data-numeric-value', baseAmount);
            
            document.getElementById('modifyBonusCard').value = salaryData.bonus || 0;
            document.getElementById('modifyDeductionsCard').value = salaryData.deductions || 0;
            document.getElementById('modifyCollectionsBonusCard').value = collectionsBonus;
            
            calculateNewTotalCard();
            card.style.display = 'block';
            setTimeout(() => scrollToElement(card), 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        document.getElementById('modifySalaryId').value = validSalaryId;
        document.getElementById('modifyUserName').value = salaryData.full_name || salaryData.username;
        
        const baseAmountElement = document.getElementById('modifyBaseAmount');
        baseAmountElement.value = formatCurrency(baseAmount);
        baseAmountElement.setAttribute('data-numeric-value', baseAmount);
        
        document.getElementById('modifyBonus').value = salaryData.bonus || 0;
        document.getElementById('modifyDeductions').value = salaryData.deductions || 0;
        document.getElementById('modifyCollectionsBonus').value = collectionsBonus;
        
        calculateNewTotal();
        // استخدام الدالة الآمنة لفتح النموذج
        safeShowModal('modifySalaryModal', 'modifySalaryCard');
    }
}

function calculateNewTotal() {
    // للـ Modal
    const baseAmountElement = document.getElementById('modifyBaseAmount');
    if (baseAmountElement) {
        const baseAmount = parseFloat(baseAmountElement.getAttribute('data-numeric-value') || baseAmountElement.value.replace(/[^\d.]/g, '')) || 0;
        const bonus = parseFloat(document.getElementById('modifyBonus')?.value) || 0;
        const deductions = parseFloat(document.getElementById('modifyDeductions')?.value) || 0;
        const collectionsBonus = parseFloat(document.getElementById('modifyCollectionsBonus')?.value) || 0;
        
        const newTotal = baseAmount + bonus + collectionsBonus - deductions;
        const newTotalElement = document.getElementById('newTotalAmount');
        if (newTotalElement) {
            newTotalElement.textContent = formatCurrency(Math.max(0, newTotal));
        }
    }
}

function calculateNewTotalCard() {
    // للـ Card
    const baseAmountElement = document.getElementById('modifyBaseAmountCard');
    if (baseAmountElement) {
        const baseAmount = parseFloat(baseAmountElement.getAttribute('data-numeric-value') || baseAmountElement.value.replace(/[^\d.]/g, '')) || 0;
        const bonus = parseFloat(document.getElementById('modifyBonusCard')?.value) || 0;
        const deductions = parseFloat(document.getElementById('modifyDeductionsCard')?.value) || 0;
        const collectionsBonus = parseFloat(document.getElementById('modifyCollectionsBonusCard')?.value) || 0;
        
        const newTotal = baseAmount + bonus + collectionsBonus - deductions;
        const newTotalElement = document.getElementById('newTotalAmountCard');
        if (newTotalElement) {
            newTotalElement.textContent = formatCurrency(Math.max(0, newTotal));
        }
    }
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('ar-EG', {
        style: 'currency',
        currency: 'EGP',
        minimumFractionDigits: 2
    }).format(amount);
}

// دالة لتحويل النص المنسق (مع أرقام عربية) إلى رقم
function parseFormattedCurrency(text) {
    if (!text) return 0;
    
    // استبدال الأرقام العربية بالأرقام الإنجليزية
    const arabicToEnglish = {
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9'
    };
    
    let cleaned = text.toString();
    // استبدال الأرقام العربية
    for (let arabic in arabicToEnglish) {
        cleaned = cleaned.replace(new RegExp(arabic, 'g'), arabicToEnglish[arabic]);
    }
    
    // إزالة جميع الأحرف غير الرقمية والنقطة (بما في ذلك رمز العملة والفواصل)
    cleaned = cleaned.replace(/[^\d.]/g, '');
    
    const result = parseFloat(cleaned) || 0;
    return result;
}

function showMonthlyReport() {
    window.location.href = <?php echo json_encode($currentUrl, JSON_UNESCAPED_SLASHES); ?> + '?page=salaries&report=1&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>&view=<?php echo $view; ?>';
}

// حساب الراتب الجديد عند التغيير
document.getElementById('modifyBonus')?.addEventListener('input', calculateNewTotal);
document.getElementById('modifyDeductions')?.addEventListener('input', calculateNewTotal);

// وظائف السلف
function rejectAdvance(advanceId) {
    openRejectModal(advanceId);
}

function openRejectModal(advanceId) {
    closeAllForms();
    
    if (isMobile()) {
        document.getElementById('rejectAdvanceIdCard').value = advanceId;
        const card = document.getElementById('rejectCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(() => scrollToElement(card), 50);
        }
    } else {
        document.getElementById('rejectAdvanceId').value = advanceId;
        // استخدام الدالة الآمنة لفتح النموذج
        safeShowModal('rejectModal', 'rejectCard');
    }
}

function viewAdvanceDetails(advanceId) {
    alert('عرض تفاصيل السلفة #' + advanceId);
    // يمكن إضافة modal لعرض التفاصيل لاحقاً
}

// متغيرات عامة لحفظ القيم المحسوبة من بطاقة الموظف
let settleModalCalculatedValues = {
    accumulated: 0,
    paid: 0,
    remaining: 0,
    salaryId: 0
};

// متغير لحفظ القيم الأصلية للراتب الحالي (الذي تم فتح النموذج به)
// هذه القيم لا تتغير عند اختيار رواتب أخرى
let originalSalaryValues = {
    accumulated: 0,
    remaining: 0,
    salaryId: 0
};

// متغير لحفظ القيمة الفعلية للمتبقي (بدون تنسيق)
let settleModalRemainingValue = 0;

// دالة منفصلة لفتح نموذج التسوية على الموبايل
function openSettleModalMobile(salaryId, salaryData, remainingAmount, calculatedAccumulated) {
    // الحصول على user_id من البيانات
    let userId = null;
    if (salaryData.user_id !== undefined && salaryData.user_id !== null && salaryData.user_id > 0) {
        userId = parseInt(salaryData.user_id);
    } else if (salaryData.userId !== undefined && salaryData.userId !== null && salaryData.userId > 0) {
        userId = parseInt(salaryData.userId);
    } else if (salaryData.user_id === 0 || salaryData.userId === 0) {
        userId = 0;
    }
    
    // استخدام القيم المحسوبة مباشرة من بطاقة الموظف
    const accumulated = parseFloat(calculatedAccumulated || salaryData.calculated_accumulated || salaryData.accumulated_amount || salaryData.total_amount || 0);
    const remaining = parseFloat(remainingAmount || salaryData.calculated_remaining || 0);
    
    // حفظ القيم في متغير عام
    settleModalCalculatedValues = {
        accumulated: accumulated,
        remaining: remaining,
        salaryId: salaryId
    };
    
    // حفظ القيم الأصلية للراتب الحالي
    originalSalaryValues = {
        accumulated: accumulated,
        remaining: remaining,
        salaryId: salaryId
    };
    
    settleModalRemainingValue = remaining;
    
    // على الموبايل: استخدام Card
    const card = document.getElementById('settleSalaryCard');
    if (!card) {
        console.error('settleSalaryCard element not found in DOM');
        alert('خطأ: لم يتم العثور على نافذة التسوية. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        return;
    }
    
    // التحقق من وجود جميع العناصر المطلوبة مع تسجيل تفصيلي
    const settleSalaryIdCard = document.getElementById('settleSalaryIdCard');
    const settleUserIdCard = document.getElementById('settleUserIdCard');
    const settleUserNameCard = document.getElementById('settleUserNameCard');
    
    console.log('Checking required elements:', {
        settleSalaryIdCard: !!settleSalaryIdCard,
        settleUserIdCard: !!settleUserIdCard,
        settleUserNameCard: !!settleUserNameCard,
        cardExists: !!card
    });
    
    if (!settleSalaryIdCard || !settleUserIdCard || !settleUserNameCard) {
        console.error('Required elements not found in settleSalaryCard', {
            settleSalaryIdCard: settleSalaryIdCard,
            settleUserIdCard: settleUserIdCard,
            settleUserNameCard: settleUserNameCard,
            card: card
        });
        
        // التحقق مرة أخرى بعد انتظار قصير (قد تكون العناصر لا تزال في حالة إعادة تعيين)
        setTimeout(() => {
            const retrySettleSalaryIdCard = document.getElementById('settleSalaryIdCard');
            const retrySettleUserIdCard = document.getElementById('settleUserIdCard');
            const retrySettleUserNameCard = document.getElementById('settleUserNameCard');
            
            console.log('Retry check:', {
                retrySettleSalaryIdCard: !!retrySettleSalaryIdCard,
                retrySettleUserIdCard: !!retrySettleUserIdCard,
                retrySettleUserNameCard: !!retrySettleUserNameCard
            });
            
            if (retrySettleSalaryIdCard && retrySettleUserIdCard && retrySettleUserNameCard) {
                console.log('Elements found on retry, opening modal again');
                // استدعاء الدالة مرة أخرى مع نفس المعاملات
                openSettleModalMobile(salaryId, salaryData, remainingAmount, calculatedAccumulated);
            } else {
                console.error('Elements still not found after retry');
                alert('خطأ: بعض العناصر المطلوبة غير موجودة. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
            }
        }, 200);
        return;
    }
    
    const settleAccumulatedAmountCard = document.getElementById('settleAccumulatedAmountCard');
    const settleRemainingAmountCard = document.getElementById('settleRemainingAmountCard');
    const settleRemainingAmount2Card = document.getElementById('settleRemainingAmount2Card');
    const settleAmountCard = document.getElementById('settleAmountCard');
    const settleSubmitBtnCard = document.getElementById('settleSubmitBtnCard');
    
    try {
        settleSalaryIdCard.value = salaryId;
        settleUserIdCard.value = userId;
        settleUserNameCard.textContent = salaryData.full_name || salaryData.username || 'غير محدد';
        
        if (settleAccumulatedAmountCard) {
            settleAccumulatedAmountCard.textContent = formatCurrency(accumulated);
        }
        if (settleRemainingAmountCard) {
            settleRemainingAmountCard.textContent = formatCurrency(remaining);
        }
        if (settleRemainingAmount2Card) {
            settleRemainingAmount2Card.textContent = formatCurrency(remaining);
        }
        
        if (settleAmountCard) {
            settleAmountCard.value = '';
            settleAmountCard.max = remaining;
            settleAmountCard.min = 0;
        }
        
        if (settleSubmitBtnCard) {
            settleSubmitBtnCard.disabled = true;
        }
        
        // تحميل الرواتب للموظف
        loadUserSalariesForSettlementCard(userId, salaryId);
        
        // إظهار Card
        card.style.display = 'block';
        console.log('Settle card displayed on mobile');
        setTimeout(() => {
            scrollToElement(card);
        }, 50);
    } catch (error) {
        console.error('Error in openSettleModalMobile:', error);
        console.error('Error stack:', error.stack);
        alert('حدث خطأ أثناء فتح نافذة التسوية: ' + error.message);
    }
}

function openSettleModal(salaryId, salaryData, remainingAmount, calculatedAccumulated) {
    try {
        console.log('openSettleModal called - isMobile:', isMobile(), 'window width:', window.innerWidth);
        
        // إغلاق النماذج المفتوحة أولاً (لكن على الموبايل، سنفتح Card بعد ذلك)
        closeAllForms();
        
        
        // الحصول على user_id من البيانات - محاولة عدة مصادر
        let userId = null;
        if (salaryData.user_id !== undefined && salaryData.user_id !== null && salaryData.user_id > 0) {
            userId = parseInt(salaryData.user_id);
        } else if (salaryData.userId !== undefined && salaryData.userId !== null && salaryData.userId > 0) {
            userId = parseInt(salaryData.userId);
        } else if (salaryData.user_id === 0 || salaryData.userId === 0) {
            userId = 0;
        }
        
        // تسجيل للتشخيص
        console.log('openSettleModal called with:', {
            salaryId: salaryId,
            salaryData: salaryData,
            userId: userId,
            remainingAmount: remainingAmount,
            calculatedAccumulated: calculatedAccumulated,
            isMobile: isMobile()
        });
    
    // التحقق من وجود user_id
    if (!userId || userId <= 0 || isNaN(userId)) {
        console.error('Invalid user_id in openSettleModal:', userId, 'salaryData:', salaryData);
        alert('خطأ: لم يتم العثور على معرف الموظف. يرجى المحاولة مرة أخرى.\n\n' + 
              'تفاصيل: salaryId=' + salaryId + ', user_id=' + (salaryData.user_id || 'undefined'));
        return;
    }
    
    // استخدام القيم المحسوبة مباشرة من بطاقة الموظف
    const accumulated = parseFloat(calculatedAccumulated || salaryData.calculated_accumulated || salaryData.accumulated_amount || salaryData.total_amount || 0);
    const remaining = parseFloat(remainingAmount || salaryData.calculated_remaining || 0);
    
    // حفظ القيم في متغير عام
    settleModalCalculatedValues = {
        accumulated: accumulated,
        remaining: remaining,
        salaryId: salaryId
    };
    
    // حفظ القيم الأصلية للراتب الحالي (لا تتغير عند اختيار رواتب أخرى)
    originalSalaryValues = {
        accumulated: accumulated,
        remaining: remaining,
        salaryId: salaryId
    };
    
    settleModalRemainingValue = remaining;
    
    if (isMobile()) {
        // على الموبايل: استخدام دالة منفصلة لفتح Card
        // انتظار قليل لضمان اكتمال إغلاق النماذج السابقة وإعادة تهيئة العناصر
        setTimeout(() => {
            openSettleModalMobile(salaryId, salaryData, remainingAmount, calculatedAccumulated);
        }, 200);
        return;
    }
    
    // على الكمبيوتر: استخدام Modal
    const settleModal = document.getElementById('settleSalaryModal');
    if (!settleModal) {
        console.error('settleSalaryModal element not found in DOM');
        alert('خطأ: لم يتم العثور على نافذة التسوية. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        return;
    }
    
    // التحقق من وجود العناصر قبل الوصول إليها
    const userIdInput = document.getElementById('settleUserId');
    const userNameSpan = document.getElementById('settleUserName');
    const settleSalaryIdEl = document.getElementById('settleSalaryId');
    const settleAccumulatedAmountEl = document.getElementById('settleAccumulatedAmount');
    const settleRemainingAmountEl = document.getElementById('settleRemainingAmount');
    const settleRemainingAmount2El = document.getElementById('settleRemainingAmount2');
    const settleAmountEl = document.getElementById('settleAmount');
    
    if (!userIdInput) {
        console.error('settleUserId element not found in settleSalaryModal');
    } else {
        userIdInput.value = userId;
    }
    
    if (!userNameSpan) {
        console.error('settleUserName element not found in settleSalaryModal');
    } else {
        userNameSpan.textContent = salaryData.full_name || salaryData.username || 'غير محدد';
    }
    
    console.log('Using calculated values from employee card:', {
        accumulated: accumulated,
        remaining: remaining,
        calculatedAccumulated: calculatedAccumulated,
        remainingAmount: remainingAmount,
        salaryData_calculated_accumulated: salaryData.calculated_accumulated,
        salaryData_calculated_remaining: salaryData.calculated_remaining
    });
    
    // تحديث القيم مباشرة من بطاقة الموظف
    if (settleSalaryIdEl) settleSalaryIdEl.value = salaryId;
    if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(accumulated);
    if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(remaining);
    if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(remaining);
    if (settleAmountEl) {
        settleAmountEl.value = '';
        settleAmountEl.max = remaining;
        settleAmountEl.min = 0;
    }
    
    // تحديث حالة الزر مباشرة بعد تعيين القيم
    const submitBtn = document.getElementById('settleSubmitBtn');
    if (submitBtn && remaining > 0) {
        submitBtn.disabled = true;
        console.log('Submit button initialized as disabled (waiting for amount input), remaining:', remaining);
    } else if (submitBtn && remaining <= 0) {
        submitBtn.disabled = true;
        console.log('Submit button disabled (no remaining amount)');
    }
    
    // التحقق من وجود modal instance سابق وإغلاقه أولاً
    let existingModalInstance = bootstrap.Modal.getInstance(settleModal);
    const openNewModal = function() {
        // إنشاء Modal instance إذا لم يكن موجوداً
        let modalInstance = bootstrap.Modal.getInstance(settleModal);
        if (!modalInstance) {
            modalInstance = new bootstrap.Modal(settleModal);
        }
        
        // إضافة مستمع لحدث shown.bs.modal قبل فتح Modal
        const updateAfterModalShown = function() {
            setTimeout(() => {
                updateSettleRemaining();
                
                const submitBtn = document.getElementById('settleSubmitBtn');
                if (submitBtn) {
                    const settleAmountEl = document.getElementById('settleAmount');
                    const remainingEl = document.getElementById('settleRemainingAmount');
                    if (settleAmountEl && remainingEl) {
                        const settleAmount = parseFloat(settleAmountEl.value) || 0;
                        const remainingText = remainingEl.textContent || remainingEl.innerText || '0';
                        const remaining = parseFormattedCurrency(remainingText);
                        
                        console.log('Checking submit button after modal shown:', {
                            settleAmount: settleAmount,
                            remaining: remaining,
                            shouldEnable: remaining > 0
                        });
                    }
                }
            }, 300);
            
            console.log('Loading salaries for user_id:', userId, 'currentSalaryId:', salaryId);
            loadUserSalariesForSettlement(userId, salaryId);
            
            if (salaryId > 0) {
                setTimeout(() => {
                    const select = document.getElementById('settleSalarySelect');
                    if (select) {
                        select.value = salaryId;
                    }
                }, 500);
            }
        };
        
        settleModal.addEventListener('shown.bs.modal', updateAfterModalShown, { once: true });
        
        // فتح Modal - التحقق من الموبايل أولاً
        if (!isMobile()) {
            modalInstance.show();
        } else {
            // على الموبايل: استخدام Card (تم فتحه مسبقاً في openSettleModal)
            // لا حاجة لفعل شيء إضافي هنا
        }
    };
    
    // إذا كان هناك modal مفتوح، ننتظر حتى يُغلق أولاً
    if (existingModalInstance && settleModal.classList.contains('show')) {
        // الانتظار حتى يكتمل إغلاق النموذج السابق
        const waitForClose = function() {
            settleModal.removeEventListener('hidden.bs.modal', waitForClose);
            // انتظار قليل إضافي لضمان اكتمال الإغلاق
            setTimeout(openNewModal, 100);
        };
        settleModal.addEventListener('hidden.bs.modal', waitForClose, { once: true });
        existingModalInstance.hide();
    } else {
        // لا يوجد modal مفتوح، يمكن فتحه مباشرة
        console.log('No existing modal, opening new modal...');
        setTimeout(openNewModal, 150);
    }
    } catch (error) {
        console.error('Error in openSettleModal:', error);
        console.error('Error stack:', error.stack);
        alert('حدث خطأ أثناء فتح نافذة التسوية: ' + error.message);
    }
}

function loadUserSalariesForSettlement(userId, currentSalaryId) {
    // التحقق من صحة user_id
    if (!userId || userId <= 0) {
        console.error('Invalid user_id in loadUserSalariesForSettlement:', userId);
        const select = document.getElementById('settleSalarySelect');
        if (select) {
            select.innerHTML = '<option value="">خطأ: معرف الموظف غير صحيح</option>';
        }
        return;
    }
    
    const select = document.getElementById('settleSalarySelect');
    if (!select) {
        console.error('settleSalarySelect element not found');
        return;
    }
    
    // مسح الخيارات السابقة
    select.innerHTML = '<option value="">-- جاري التحميل --</option>';
    
    const apiUrl = '<?php echo getBasePath(); ?>/api/get_user_salaries.php?user_id=' + userId;
    console.log('Fetching salaries from:', apiUrl);
    
    // جلب الرواتب من API
    fetch(apiUrl, {
        method: 'GET',
        credentials: 'include', // إرسال الـ cookies (session) مع الطلب
        headers: {
            'Accept': 'application/json'
        }
    })
        .then(response => {
            // التحقق من نوع الاستجابة
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Invalid response type. Expected JSON but got:', contentType);
                    console.error('Response text:', text.substring(0, 500));
                    throw new Error('Invalid response type. Expected JSON but got: ' + contentType);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Salaries data received:', data);
            console.log('Salaries data type:', typeof data);
            console.log('Salaries data.success:', data?.success);
            console.log('Salaries data.salaries:', data?.salaries);
            console.log('Salaries data.salaries length:', data?.salaries?.length);
            
            if (!data) {
                console.error('Empty response from server');
                throw new Error('Empty response from server');
            }
            
            select.innerHTML = '<option value="">-- اختر راتب للتسوية --</option>';
            
            if (data.success !== false) {
                if (data.salaries && Array.isArray(data.salaries) && data.salaries.length > 0) {
                    console.log('Processing ' + data.salaries.length + ' salaries');
                    data.salaries.forEach((salary, index) => {
                        console.log('Processing salary[' + index + ']:', salary);
                        const option = document.createElement('option');
                        option.value = salary.id;
                        const remaining = parseFloat(salary.remaining || 0);
                        // استخدام month_label إذا كان موجوداً، وإلا إنشاء تسمية من month و year
                        let monthLabel = salary.month_label || '';
                        if (!monthLabel && salary.month && salary.year) {
                            const monthNames = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 
                                               'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
                            const month = parseInt(salary.month) || 0;
                            const year = parseInt(salary.year) || new Date().getFullYear();
                            if (month >= 1 && month <= 12) {
                                monthLabel = monthNames[month] + ' ' + year;
                            } else {
                                monthLabel = 'شهر غير معروف ' + year;
                            }
                        }
                        if (!monthLabel) {
                            monthLabel = 'غير محدد';
                        }
                        option.textContent = monthLabel + ' - المتبقي: ' + formatCurrency(remaining);
                        if (salary.id == currentSalaryId) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    });
                    
                    // تحميل بيانات الراتب المحدد
                    if (currentSalaryId > 0) {
                        setTimeout(() => {
                            loadSelectedSalaryData();
                        }, 200);
                    }
                } else {
                    console.warn('No salaries found or empty array:', data);
                    console.warn('data.salaries:', data.salaries);
                    console.warn('data.salaries type:', typeof data.salaries);
                    console.warn('data.salaries isArray:', Array.isArray(data.salaries));
                    console.warn('data.salaries length:', data.salaries?.length);
                    
                    // التحقق من أن الرواتب فارغة وليس هناك خطأ
                    const message = (data && data.message) ? data.message : 'لا توجد رواتب متاحة لهذا الموظف';
                    
                    // إذا كان هناك راتب محدد مسبقاً (currentSalaryId)، نضيفه كخيار واحد
                    if (currentSalaryId > 0) {
                        // البحث عن معلومات الراتب الحالي من Modal
                        const currentSalaryInfo = 'الراتب الحالي'; // يمكن تحسينه لاحقاً
                        select.innerHTML = '<option value="' + currentSalaryId + '">' + currentSalaryInfo + '</option>';
                        console.info('Using current salary ID:', currentSalaryId, 'since no other salaries found');
                    } else {
                        select.innerHTML = '<option value="">' + message + '</option>';
                        console.info('No current salary ID and no salaries found. Message:', message);
                    }
                    
                    // إظهار تحذير للمستخدم (بدون إزعاج)
                    if (data && data.message) {
                        console.info('API message:', data.message);
                    }
                }
            } else {
                // API أرجعت success: false
                console.error('API returned success: false', data);
                const errorMessage = (data && data.message) ? data.message : 'حدث خطأ في جلب الرواتب';
                select.innerHTML = '<option value="">' + errorMessage + '</option>';
                if (data && data.debug && data.debug.error) {
                    console.error('API Debug Error:', data.debug);
                }
            }
        })
        .catch(error => {
            console.error('Error loading salaries:', error);
            console.error('Error details:', {
                message: error.message,
                stack: error.stack,
                name: error.name,
                userId: userId,
                currentSalaryId: currentSalaryId
            });
            
            // إذا كان هناك راتب محدد مسبقاً، نستخدمه
            if (currentSalaryId > 0) {
                select.innerHTML = '<option value="' + currentSalaryId + '">الراتب الحالي (لا يمكن تحميل الرواتب الأخرى)</option>';
                console.warn('Using current salary ID due to API error');
            } else {
                select.innerHTML = '<option value="">خطأ في تحميل الرواتب</option>';
            }
            
            // لا نعرض alert لأن هذا قد يكون مجرد مشكلة في تحميل القائمة
            // الراتب الحالي موجود بالفعل في Modal
        });
}

// دالة Card لتحميل الرواتب
function loadUserSalariesForSettlementCard(userId, currentSalaryId) {
    // التحقق من صحة user_id
    if (!userId || userId <= 0) {
        console.error('Invalid user_id in loadUserSalariesForSettlementCard:', userId);
        const select = document.getElementById('settleSalarySelectCard');
        if (select) {
            select.innerHTML = '<option value="">خطأ: معرف الموظف غير صحيح</option>';
        }
        return;
    }
    
    const select = document.getElementById('settleSalarySelectCard');
    if (!select) {
        console.error('settleSalarySelectCard element not found');
        return;
    }
    
    // مسح الخيارات السابقة
    select.innerHTML = '<option value="">-- جاري التحميل --</option>';
    
    const apiUrl = '<?php echo getBasePath(); ?>/api/get_user_salaries.php?user_id=' + userId;
    console.log('Fetching salaries from Card:', apiUrl);
    
    // جلب الرواتب من API
    fetch(apiUrl, {
        method: 'GET',
        credentials: 'include',
        headers: {
            'Accept': 'application/json'
        }
    })
        .then(response => {
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Invalid response type. Expected JSON but got:', contentType);
                    throw new Error('Invalid response type');
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Salaries data received in Card:', data);
            
            if (!data) {
                throw new Error('Empty response from server');
            }
            
            select.innerHTML = '<option value="">-- اختر راتب للتسوية --</option>';
            
            if (data.success !== false) {
                if (data.salaries && Array.isArray(data.salaries) && data.salaries.length > 0) {
                    console.log('Processing ' + data.salaries.length + ' salaries in Card');
                    data.salaries.forEach(function(salary) {
                        const option = document.createElement('option');
                        option.value = salary.id;
                        const remaining = parseFloat(salary.calculated_remaining || salary.remaining || 0);
                        // استخدام month_label إذا كان موجوداً، وإلا إنشاء تسمية من month و year
                        let monthLabel = salary.month_label || '';
                        if (!monthLabel && salary.month && salary.year) {
                            const monthNames = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 
                                               'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
                            const month = parseInt(salary.month) || 0;
                            const year = parseInt(salary.year) || new Date().getFullYear();
                            if (month >= 1 && month <= 12) {
                                monthLabel = monthNames[month] + ' ' + year;
                            } else {
                                monthLabel = 'شهر غير معروف ' + year;
                            }
                        }
                        if (!monthLabel) {
                            monthLabel = 'غير محدد';
                        }
                        option.textContent = monthLabel + ' - المتبقي: ' + formatCurrency(remaining);
                        if (salary.id == currentSalaryId) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    });
                    
                    // تحميل بيانات الراتب المحدد
                    if (currentSalaryId > 0) {
                        setTimeout(() => {
                            loadSelectedSalaryDataCard();
                        }, 200);
                    }
                } else {
                    console.warn('No salaries found in Card');
                    const message = (data && data.message) ? data.message : 'لا توجد رواتب متاحة لهذا الموظف';
                    if (currentSalaryId > 0) {
                        select.innerHTML = '<option value="' + currentSalaryId + '">الراتب الحالي</option>';
                    } else {
                        select.innerHTML = '<option value="">' + message + '</option>';
                    }
                }
            } else {
                console.error('API returned success: false in Card', data);
                const errorMessage = (data && data.message) ? data.message : 'حدث خطأ في جلب الرواتب';
                select.innerHTML = '<option value="">' + errorMessage + '</option>';
            }
        })
        .catch(error => {
            console.error('Error loading salaries in Card:', error);
            if (currentSalaryId > 0) {
                select.innerHTML = '<option value="' + currentSalaryId + '">الراتب الحالي (لا يمكن تحميل الرواتب الأخرى)</option>';
            } else {
                select.innerHTML = '<option value="">خطأ في تحميل الرواتب</option>';
            }
        });
}

function loadSelectedSalaryData() {
    const select = document.getElementById('settleSalarySelect');
    const salaryId = select ? select.value : '';
    
    // الحصول على جميع العناصر المطلوبة
    const settleSalaryIdEl = document.getElementById('settleSalaryId');
    const settleAccumulatedAmountEl = document.getElementById('settleAccumulatedAmount');
    const settleRemainingAmountEl = document.getElementById('settleRemainingAmount');
    const settleRemainingAmount2El = document.getElementById('settleRemainingAmount2');
    const settleAmountEl = document.getElementById('settleAmount');
    
    if (!salaryId || salaryId === '') {
        // إعادة تعيين القيم مع التحقق من وجود العناصر
        if (settleSalaryIdEl) settleSalaryIdEl.value = '';
        if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(0);
        if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(0);
        if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(0);
        if (settleAmountEl) {
            settleAmountEl.value = '';
            settleAmountEl.max = 0;
        }
        
        // تحديث حالة الزر بعد تحديث القيم - مع انتظار صغير للتأكد من أن جميع العناصر جاهزة
        setTimeout(function() {
            updateSettleRemaining();
        }, 200);
        return;
    }
    
    if (!settleSalaryIdEl) {
        console.error('settleSalaryId element not found');
        return;
    }
    
    // إذا كان الراتب المحدد هو نفس الراتب الأصلي من بطاقة الموظف، استخدم القيم الأصلية المحفوظة
    if (originalSalaryValues.salaryId > 0 && parseInt(salaryId) === originalSalaryValues.salaryId) {
        console.log('Using original saved calculated values from employee card for salary ID:', salaryId);
        const accumulated = originalSalaryValues.accumulated;
        const remaining = originalSalaryValues.remaining;
        
        // حفظ القيمة الفعلية للمتبقي
        settleModalRemainingValue = remaining;
        
        // تحديث settleModalCalculatedValues أيضاً لضمان التزامن
        settleModalCalculatedValues = {
            accumulated: accumulated,
            remaining: remaining,
            salaryId: parseInt(salaryId)
        };
        
        settleSalaryIdEl.value = salaryId;
        if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(accumulated);
        if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(remaining);
        if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(remaining);
        if (settleAmountEl) {
            settleAmountEl.value = '';
            settleAmountEl.max = remaining;
        }
        
        // تحديث حالة الزر بعد تحديث القيم - مع انتظار صغير للتأكد من أن جميع العناصر جاهزة
        setTimeout(function() {
            updateSettleRemaining();
        }, 200);
        return;
    }
    
    // إذا كان راتب مختلف، جلب البيانات من API
    settleSalaryIdEl.value = salaryId;
    
    // إظهار حالة التحميل
    if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = 'جاري التحميل...';
    if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = 'جاري التحميل...';
    if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = 'جاري التحميل...';
    
    // جلب بيانات الراتب المحدد
    const apiUrl = '<?php echo getBasePath(); ?>/api/get_salary_details.php?salary_id=' + salaryId;
    console.log('Fetching salary details from API for different salary:', apiUrl);
    
    fetch(apiUrl, {
        method: 'GET',
        credentials: 'include', // إرسال الـ cookies (session) مع الطلب
        headers: {
            'Accept': 'application/json'
        }
    })
        .then(response => {
            // التحقق من نوع الاستجابة
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Invalid response type. Expected JSON but got:', contentType);
                    console.error('Response text:', text.substring(0, 500));
                    throw new Error('Invalid response type. Expected JSON but got: ' + contentType + '. Response: ' + text.substring(0, 200));
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Salary details received from API:', data);
            
            if (!data) {
                throw new Error('Empty response from server');
            }
            
            if (data.success && data.salary) {
                const salary = data.salary;
                
                // استخدام القيم المحسوبة من API (يجب أن تكون صحيحة لأنها تستخدم calculateSalaryAccumulatedAmount)
                const accumulated = parseFloat(salary.calculated_accumulated || salary.accumulated_amount || salary.total_amount || 0);
                // استخدام calculated_remaining الذي يتم حسابه بناءً على settlements_advances وليس paid_amount
                const remaining = parseFloat(salary.calculated_remaining || salary.remaining || 0);
                
                // حفظ القيم في متغير عام لاستخدامها لاحقاً
                settleModalCalculatedValues = {
                    accumulated: accumulated,
                    remaining: remaining,
                    salaryId: parseInt(salaryId)
                };
                
                // حفظ القيمة الفعلية للمتبقي
                settleModalRemainingValue = remaining;
                
                console.log('Salary data from API (updated):', {
                    accumulated: accumulated,
                    remaining: remaining,
                    calculated_accumulated: salary.calculated_accumulated,
                    calculated_remaining: salary.calculated_remaining,
                    salary_id: salary.id
                });
                
                // تحديث القيم مع التحقق من وجود العناصر
                if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(accumulated);
                if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(remaining);
                if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(remaining);
                if (settleAmountEl) {
                    settleAmountEl.value = '';
                    settleAmountEl.max = remaining;
                    settleAmountEl.min = 0;
                }
                
                // تحديث حالة الزر بعد تحديث القيم
                const submitBtn = document.getElementById('settleSubmitBtn');
                if (submitBtn) {
                    if (remaining > 0) {
                        submitBtn.disabled = true; // سيتم تفعيله عند إدخال مبلغ
                    } else {
                        submitBtn.disabled = true; // لا يوجد متبقي
                    }
                }
                
                // تحديث المتبقي بعد التسوية
                setTimeout(function() {
                    updateSettleRemaining();
                }, 200);
            } else {
                const errorMsg = data && data.message ? data.message : 'خطأ في تحميل بيانات الراتب';
                console.error('API Error:', errorMsg);
                
                // إعادة تعيين القيم في حالة الخطأ
                if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(0);
                if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(0);
                if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(0);
                
                alert(errorMsg);
            }
        })
        .catch(error => {
            console.error('Error loading salary details:', error);
            const errorMsg = error.message || 'خطأ في تحميل بيانات الراتب';
            
            // إعادة تعيين القيم في حالة الخطأ
            if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(0);
            if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(0);
            if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(0);
            
            alert('خطأ في تحميل بيانات الراتب: ' + errorMsg);
        });
}

function updateSettleRemaining() {
    // التحقق من أن Modal موجود في DOM أولاً
    const settleModal = document.getElementById('settleSalaryModal');
    if (!settleModal) {
        // Modal غير موجود في DOM، لا نحتاج لتحديث أي شيء
        return;
    }
    
    // التحقق من أن Modal مرئي (منفتح)
    const isModalVisible = settleModal.classList.contains('show') || 
                          (settleModal.offsetParent !== null);
    
    if (!isModalVisible) {
        // Modal غير مفتوح، لا نحتاج لتحديث أي شيء
        return;
    }
    
    const remainingElement = document.getElementById('settleRemainingAmount');
    const settleAmountElement = document.getElementById('settleAmount');
    const settleNewRemainingElement = document.getElementById('settleNewRemaining');
    const settleRemainingAmount2El = document.getElementById('settleRemainingAmount2');
    
    // التحقق من وجود العناصر الأساسية فقط - إذا لم تكن موجودة، نتوقف
    if (!remainingElement || !settleAmountElement) {
        // لا نعرض تحذير لتجنب التكرار - العناصر ستكون موجودة لاحقاً
        return;
    }
    
    // العناصر الاختيارية (settleNewRemainingElement و settleRemainingAmount2El) - نعمل بدونهما إذا لم تكونا موجودة
    
    // استخدام القيمة المحفوظة مباشرة بدلاً من قراءة النص المنسق
    // هذا يضمن الدقة وعدم وجود أخطاء في التحويل
    let remaining = settleModalRemainingValue;
    let remainingText = '';
    
    // إذا لم تكن القيمة محفوظة، حاول قراءتها من النص المنسق
    if (remaining <= 0) {
        remainingText = remainingElement.textContent || remainingElement.innerText || '0';
        remaining = parseFormattedCurrency(remainingText);
        // حفظ القيمة للمرة القادمة
        settleModalRemainingValue = remaining;
    } else {
        remainingText = formatCurrency(remaining);
    }
    
    const settleAmount = parseFloat(settleAmountElement.value) || 0;
    const newRemaining = Math.max(0, remaining - settleAmount);
    
    // تحديث المتبقي الجديد (عنصر اختياري)
    if (settleNewRemainingElement) {
        settleNewRemainingElement.textContent = formatCurrency(newRemaining);
    }
    
    // تحديث الحد الأقصى المتاح في النص التوضيحي
    if (settleRemainingAmount2El) {
        settleRemainingAmount2El.textContent = formatCurrency(remaining);
    }
    
    // تحديث الحد الأقصى في حقل الإدخال (مهم جداً!)
    if (settleAmountElement) {
        settleAmountElement.max = remaining;
        // التأكد من أن القيمة الحالية لا تتجاوز الحد الأقصى
        if (settleAmount > remaining) {
            settleAmountElement.value = remaining;
        }
    }
    
    const submitBtn = document.getElementById('settleSubmitBtn');
    if (submitBtn) {
        // الزر يكون معطلاً إذا:
        // 1. المتبقي <= 0 (لا يوجد مبلغ متبقي للتسوية)
        // 2. المبلغ المُدخل <= 0 (لم يتم إدخال مبلغ أو كان صفر)
        // 3. المبلغ المُدخل > المتبقي (المبلغ أكبر من المتاح)
        const hasRemaining = remaining > 0;
        const hasValidAmount = settleAmount > 0 && settleAmount <= remaining;
        const shouldDisable = !hasRemaining || !hasValidAmount;
        
        submitBtn.disabled = shouldDisable;
        
        // إضافة class للتأثير البصري
        if (shouldDisable) {
            submitBtn.classList.add('disabled');
        } else {
            submitBtn.classList.remove('disabled');
        }
        
        // إضافة معلومات تشخيصية مفصلة
        console.log('Settle button state:', {
            disabled: shouldDisable,
            hasRemaining: hasRemaining,
            hasValidAmount: hasValidAmount,
            reason: shouldDisable ? (
                !hasRemaining ? 'no_remaining' : 
                settleAmount <= 0 ? 'no_amount_entered' : 
                settleAmount > remaining ? 'amount_too_large' : 'unknown'
            ) : 'enabled',
            remaining: remaining,
            settleAmount: settleAmount,
            remainingText: remainingText,
            settleModalRemainingValue: settleModalRemainingValue,
            buttonElement: submitBtn ? 'found' : 'not_found'
        });
        
        // إضافة رسالة مرئية للمستخدم إذا كان الزر معطلاً بسبب عدم إدخال مبلغ
        if (shouldDisable && hasRemaining && settleAmount <= 0) {
            // لا نعرض أي شيء - الزر معطّل بشكل طبيعي حتى يتم إدخال مبلغ
        }
    }
    
    console.log('updateSettleRemaining:', {
        remainingText: remainingText,
        remaining: remaining,
        settleAmount: settleAmount,
        newRemaining: newRemaining,
        max: settleAmountElement.max,
        settleRemainingAmount2: settleRemainingAmount2El ? settleRemainingAmount2El.textContent : 'N/A',
        submitBtnDisabled: submitBtn ? submitBtn.disabled : 'N/A'
    });
}

// دالة Card لتحميل بيانات الراتب المحدد
function loadSelectedSalaryDataCard() {
    const select = document.getElementById('settleSalarySelectCard');
    const salaryId = select ? select.value : '';
    
    // الحصول على جميع العناصر المطلوبة
    const settleSalaryIdEl = document.getElementById('settleSalaryIdCard');
    const settleAccumulatedAmountEl = document.getElementById('settleAccumulatedAmountCard');
    const settleRemainingAmountEl = document.getElementById('settleRemainingAmountCard');
    const settleRemainingAmount2El = document.getElementById('settleRemainingAmount2Card');
    const settleAmountEl = document.getElementById('settleAmountCard');
    
    if (!salaryId || salaryId === '') {
        // إعادة تعيين القيم
        if (settleSalaryIdEl) settleSalaryIdEl.value = '';
        if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(0);
        if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(0);
        if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(0);
        if (settleAmountEl) {
            settleAmountEl.value = '';
            settleAmountEl.max = 0;
        }
        setTimeout(function() {
            updateSettleRemainingCard();
        }, 200);
        return;
    }
    
    if (!settleSalaryIdEl) {
        console.error('settleSalaryIdCard element not found');
        return;
    }
    
    // إذا كان الراتب المحدد هو نفس الراتب الأصلي من بطاقة الموظف، استخدم القيم الأصلية المحفوظة
    if (originalSalaryValues.salaryId > 0 && parseInt(salaryId) === originalSalaryValues.salaryId) {
        console.log('Using original saved calculated values from employee card for salary ID (Card):', salaryId);
        const accumulated = originalSalaryValues.accumulated;
        const remaining = originalSalaryValues.remaining;
        
        settleModalRemainingValue = remaining;
        
        // تحديث settleModalCalculatedValues أيضاً لضمان التزامن
        settleModalCalculatedValues = {
            accumulated: accumulated,
            remaining: remaining,
            salaryId: parseInt(salaryId)
        };
        
        settleSalaryIdEl.value = salaryId;
        if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(accumulated);
        if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(remaining);
        if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(remaining);
        if (settleAmountEl) {
            settleAmountEl.value = '';
            settleAmountEl.max = remaining;
        }
        
        setTimeout(function() {
            updateSettleRemainingCard();
        }, 200);
        return;
    }
    
    // إذا كان راتب مختلف، جلب البيانات من API
    settleSalaryIdEl.value = salaryId;
    
    // إظهار حالة التحميل
    if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = 'جاري التحميل...';
    if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = 'جاري التحميل...';
    if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = 'جاري التحميل...';
    
    // جلب بيانات الراتب المحدد
    const apiUrl = '<?php echo getBasePath(); ?>/api/get_salary_details.php?salary_id=' + salaryId;
    console.log('Fetching salary details from API for different salary (Card):', apiUrl);
    
    fetch(apiUrl, {
        method: 'GET',
        credentials: 'include',
        headers: {
            'Accept': 'application/json'
        }
    })
        .then(response => {
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Invalid response type. Expected JSON but got:', contentType);
                    throw new Error('Invalid response type');
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Salary details received from API (Card):', data);
            
            if (!data) {
                throw new Error('Empty response from server');
            }
            
            if (data.success && data.salary) {
                const salary = data.salary;
                
                const accumulated = parseFloat(salary.calculated_accumulated || salary.accumulated_amount || salary.total_amount || 0);
                const remaining = parseFloat(salary.calculated_remaining || salary.remaining || 0);
                
                settleModalCalculatedValues = {
                    accumulated: accumulated,
                    remaining: remaining,
                    salaryId: parseInt(salaryId)
                };
                
                settleModalRemainingValue = remaining;
                
                // تحديث القيم
                if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(accumulated);
                if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(remaining);
                if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(remaining);
                if (settleAmountEl) {
                    settleAmountEl.value = '';
                    settleAmountEl.max = remaining;
                    settleAmountEl.min = 0;
                }
                
                // تحديث حالة الزر
                const submitBtn = document.getElementById('settleSubmitBtnCard');
                if (submitBtn) {
                    submitBtn.disabled = remaining <= 0;
                }
                
                setTimeout(function() {
                    updateSettleRemainingCard();
                }, 200);
            } else {
                const errorMsg = data && data.message ? data.message : 'خطأ في تحميل بيانات الراتب';
                console.error('API Error (Card):', errorMsg);
                
                if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(0);
                if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(0);
                if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(0);
                
                alert(errorMsg);
            }
        })
        .catch(error => {
            console.error('Error loading salary details (Card):', error);
            const errorMsg = error.message || 'خطأ في تحميل بيانات الراتب';
            
            if (settleAccumulatedAmountEl) settleAccumulatedAmountEl.textContent = formatCurrency(0);
            if (settleRemainingAmountEl) settleRemainingAmountEl.textContent = formatCurrency(0);
            if (settleRemainingAmount2El) settleRemainingAmount2El.textContent = formatCurrency(0);
            
            alert('خطأ في تحميل بيانات الراتب: ' + errorMsg);
        });
}

// دالة Card لتحديث المتبقي
function updateSettleRemainingCard() {
    const card = document.getElementById('settleSalaryCard');
    if (!card || card.style.display === 'none') {
        return;
    }
    
    const remainingElement = document.getElementById('settleRemainingAmountCard');
    const settleAmountElement = document.getElementById('settleAmountCard');
    const settleNewRemainingElement = document.getElementById('settleNewRemainingCard');
    const settleRemainingAmount2El = document.getElementById('settleRemainingAmount2Card');
    
    if (!remainingElement || !settleAmountElement) {
        return;
    }
    
    let remaining = settleModalRemainingValue;
    let remainingText = '';
    
    if (remaining <= 0) {
        remainingText = remainingElement.textContent || remainingElement.innerText || '0';
        remaining = parseFormattedCurrency(remainingText);
        settleModalRemainingValue = remaining;
    } else {
        remainingText = formatCurrency(remaining);
    }
    
    const settleAmount = parseFloat(settleAmountElement.value) || 0;
    const newRemaining = Math.max(0, remaining - settleAmount);
    
    if (settleNewRemainingElement) {
        settleNewRemainingElement.textContent = formatCurrency(newRemaining);
    }
    
    if (settleRemainingAmount2El) {
        settleRemainingAmount2El.textContent = formatCurrency(remaining);
    }
    
    if (settleAmountElement) {
        settleAmountElement.max = remaining;
        if (settleAmount > remaining) {
            settleAmountElement.value = remaining;
        }
    }
    
    const submitBtn = document.getElementById('settleSubmitBtnCard');
    if (submitBtn) {
        const hasRemaining = remaining > 0;
        const hasValidAmount = settleAmount > 0 && settleAmount <= remaining;
        const shouldDisable = !hasRemaining || !hasValidAmount;
        
        submitBtn.disabled = shouldDisable;
        
        if (shouldDisable) {
            submitBtn.classList.add('disabled');
        } else {
            submitBtn.classList.remove('disabled');
        }
    }
}

function openStatementModal(salaryId, userId, employeeName) {
    closeAllForms();
    
    const today = new Date();
    const currentMonth = today.getMonth() + 1;
    const currentYear = today.getFullYear();
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('salaryStatementCard');
        if (card) {
            document.getElementById('statementSalaryIdCard').value = salaryId;
            document.getElementById('statementUserIdCard').value = userId;
            document.getElementById('statementEmployeeNameCard').value = employeeName;
            
            document.getElementById('statementPeriodTypeCard').value = 'current_month';
            document.getElementById('statementMonthCard').value = currentMonth;
            document.getElementById('statementYearCard').value = currentYear;
            
            updateStatementPeriodFieldsCard();
            card.style.display = 'block';
            setTimeout(() => scrollToElement(card), 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        document.getElementById('statementSalaryId').value = salaryId;
        document.getElementById('statementUserId').value = userId;
        document.getElementById('statementEmployeeName').value = employeeName;
        
        document.getElementById('statementPeriodType').value = 'current_month';
        document.getElementById('statementMonth').value = currentMonth;
        document.getElementById('statementYear').value = currentYear;
        
        updateStatementPeriodFields();
        // استخدام الدالة الآمنة لفتح النموذج
        safeShowModal('salaryStatementModal', 'salaryStatementCard');
    }
}

function updateStatementPeriodFields() {
    const periodType = document.getElementById('statementPeriodType')?.value;
    const monthFields = document.getElementById('statementMonthFields');
    const dateRangeFields = document.getElementById('statementDateRangeFields');
    
    if (monthFields && dateRangeFields) {
        if (periodType === 'current_month' || periodType === 'specific_month') {
            monthFields.style.display = 'block';
            dateRangeFields.style.display = 'none';
        } else if (periodType === 'date_range') {
            monthFields.style.display = 'none';
            dateRangeFields.style.display = 'block';
        }
    }
}

function updateStatementPeriodFieldsCard() {
    const periodType = document.getElementById('statementPeriodTypeCard')?.value;
    const monthFields = document.getElementById('statementMonthFieldsCard');
    const dateRangeFields = document.getElementById('statementDateRangeFieldsCard');
    
    if (monthFields && dateRangeFields) {
        if (periodType === 'current_month' || periodType === 'specific_month') {
            monthFields.style.display = 'block';
            dateRangeFields.style.display = 'none';
        } else if (periodType === 'date_range') {
            monthFields.style.display = 'none';
            dateRangeFields.style.display = 'block';
        }
    }
}

function printSalaryStatement() {
    const salaryId = document.getElementById('statementSalaryId').value;
    const userId = document.getElementById('statementUserId').value;
    const periodType = document.getElementById('statementPeriodType').value;
    
    // التحقق من وجود القيم المطلوبة
    if (!salaryId || !userId) {
        alert('خطأ: يرجى التأكد من تحديد الموظف والراتب');
        return false;
    }
    
    // بناء الرابط بشكل صحيح
    // استخدام window.location.pathname كقاعدة
    let baseUrl = '<?php echo isset($currentUrl) && !empty($currentUrl) ? htmlspecialchars($currentUrl) : htmlspecialchars($_SERVER["PHP_SELF"] ?? "/dashboard/accountant.php"); ?>';
    
    // إذا كان baseUrl فارغاً أو غير صحيح، استخدم window.location.pathname
    if (!baseUrl || baseUrl.trim() === '') {
        baseUrl = window.location.pathname;
    }
    
    let url = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=salaries&action=print_statement&salary_id=' + salaryId + '&user_id=' + userId + '&period_type=' + periodType;
    
    if (periodType === 'current_month' || periodType === 'specific_month') {
        const month = document.getElementById('statementMonth')?.value;
        const year = document.getElementById('statementYear')?.value;
        if (month && year) {
            url += '&month=' + encodeURIComponent(month) + '&year=' + encodeURIComponent(year);
        }
    } else if (periodType === 'date_range') {
        const fromDate = document.getElementById('statementFromDate')?.value;
        const toDate = document.getElementById('statementToDate')?.value;
        if (fromDate && toDate) {
            url += '&from_date=' + encodeURIComponent(fromDate) + '&to_date=' + encodeURIComponent(toDate);
        }
    }
    
    // تسجيل الرابط للتشخيص
    console.log('Print salary statement URL:', url);
    
    // محاولة فتح النافذة الجديدة
    try {
        const printWindow = window.open(url, '_blank', 'width=1200,height=800,scrollbars=yes');
        if (!printWindow || printWindow.closed || typeof printWindow.closed === 'undefined') {
            // إذا فشل window.open (بسبب popup blocker)، استخدم window.location
            console.warn('Popup blocked, using window.location instead');
            window.location.href = url;
        } else {
            // انتظار قليل ثم التحقق من أن النافذة تم فتحها
            setTimeout(function() {
                if (printWindow.closed) {
                    console.warn('Print window was closed immediately');
                }
            }, 500);
        }
    } catch (e) {
        console.error('Error opening print window:', e);
        // في حالة الخطأ، استخدم window.location
        window.location.href = url;
    }
    
    return false;
}

function printSalaryStatementCard() {
    const salaryId = document.getElementById('statementSalaryIdCard')?.value;
    const userId = document.getElementById('statementUserIdCard')?.value;
    const periodType = document.getElementById('statementPeriodTypeCard')?.value;
    
    if (!salaryId || !userId) {
        alert('خطأ: يرجى التأكد من تحديد الموظف والراتب');
        return false;
    }
    
    let baseUrl = '<?php echo isset($currentUrl) && !empty($currentUrl) ? htmlspecialchars($currentUrl) : htmlspecialchars($_SERVER["PHP_SELF"] ?? "/dashboard/accountant.php"); ?>';
    if (!baseUrl || baseUrl.trim() === '') {
        baseUrl = window.location.pathname;
    }
    
    let url = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=salaries&action=print_statement&salary_id=' + salaryId + '&user_id=' + userId + '&period_type=' + periodType;
    
    if (periodType === 'current_month' || periodType === 'specific_month') {
        const month = document.getElementById('statementMonthCard')?.value;
        const year = document.getElementById('statementYearCard')?.value;
        if (month && year) {
            url += '&month=' + encodeURIComponent(month) + '&year=' + encodeURIComponent(year);
        }
    } else if (periodType === 'date_range') {
        const fromDate = document.getElementById('statementFromDateCard')?.value;
        const toDate = document.getElementById('statementToDateCard')?.value;
        if (fromDate && toDate) {
            url += '&from_date=' + encodeURIComponent(fromDate) + '&to_date=' + encodeURIComponent(toDate);
        }
    }
    
    try {
        const printWindow = window.open(url, '_blank', 'width=1200,height=800,scrollbars=yes');
        if (!printWindow || printWindow.closed || typeof printWindow.closed === 'undefined') {
            window.location.href = url;
        }
    } catch (e) {
        window.location.href = url;
    }
    
    return false;
}
</script>

<!-- Modal تسوية مستحقات الموظف -->
<div class="modal fade d-none d-md-block" id="settleSalaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تسوية مستحقات موظف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="settleSalaryForm">
                <input type="hidden" name="action" value="settle_salary">
                <input type="hidden" name="salary_id" id="settleSalaryId">
                <input type="hidden" name="user_id" id="settleUserId">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>الموظف:</strong> <span id="settleUserName"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">اختر الراتب للتسوية <span class="text-danger">*</span></label>
                        <select class="form-select" name="selected_salary_id" id="settleSalarySelect" required onchange="loadSelectedSalaryData()">
                            <option value="">-- اختر راتب للتسوية --</option>
                        </select>
                        <small class="text-muted">يمكنك اختيار راتب من الشهر الحالي أو شهر ماضي. ملاحظة: عند اختيار راتب آخر، سيتم إعادة حساب المبالغ من قاعدة البيانات.</small>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">المبلغ التراكمي</label>
                            <div class="form-control-plaintext fw-bold text-primary" id="settleAccumulatedAmount">0.00</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">المتبقي</label>
                            <div class="form-control-plaintext fw-bold text-warning" id="settleRemainingAmount">0.00</div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label">مبلغ التسوية <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" class="form-control" name="settlement_amount" id="settleAmount" required oninput="updateSettleRemaining()" onchange="updateSettleRemaining()" onkeyup="updateSettleRemaining()">
                        <small class="text-muted">أقصى مبلغ متاح: <span id="settleRemainingAmount2"></span></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تاريخ التسوية <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="date" class="form-control" name="settlement_date" id="settleDate" required value="<?php echo date('Y-m-d'); ?>">
                            <span class="input-group-text" style="cursor: pointer;" onclick="document.getElementById('settleDate').showPicker ? document.getElementById('settleDate').showPicker() : document.getElementById('settleDate').focus();">
                                <i class="bi bi-calendar3"></i>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" id="settleNotes" rows="3" placeholder="ملاحظات إضافية (اختياري)"></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>المتبقي بعد التسوية:</strong> <span id="settleNewRemaining" class="fw-bold">0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success" id="settleSubmitBtn" disabled>
                        <i class="bi bi-check-circle me-2"></i>تأكيد التسوية
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// تحديث المتبقي عند إدخال مبلغ التسوية
document.addEventListener('DOMContentLoaded', function() {
    const settleAmountEl = document.getElementById('settleAmount');
    if (settleAmountEl) {
        settleAmountEl.addEventListener('input', function() {
            console.log('Amount input changed, calling updateSettleRemaining()');
            updateSettleRemaining();
        });
        
        settleAmountEl.addEventListener('change', function() {
            console.log('Amount input changed (change event), calling updateSettleRemaining()');
            updateSettleRemaining();
        });
        
        settleAmountEl.addEventListener('keyup', function() {
            console.log('Amount input keyup, calling updateSettleRemaining()');
            updateSettleRemaining();
        });
    }
    
    // تحديث المتبقي عند فتح modal
    const settleModal = document.getElementById('settleSalaryModal');
    if (settleModal) {
        settleModal.addEventListener('shown.bs.modal', function() {
            console.log('Settle modal shown, updating remaining amount');
            
            // تعيين التاريخ الافتراضي إلى اليوم إذا لم يكن محدداً
            const settleDateEl = document.getElementById('settleDate');
            if (settleDateEl && !settleDateEl.value) {
                const today = new Date().toISOString().split('T')[0];
                settleDateEl.value = today;
            }
            
            // انتظار صغير للتأكد من أن جميع العناصر قد تم تحديثها
            setTimeout(function() {
                updateSettleRemaining();
                console.log('updateSettleRemaining() called after modal shown');
            }, 150);
        });
        
        // تحديث عند إغلاق Modal لضمان إعادة التعيين
        settleModal.addEventListener('hidden.bs.modal', function() {
            console.log('Settle modal hidden, resetting values');
            // إعادة تعيين القيم عند إغلاق Modal
            const settleAmountEl = document.getElementById('settleAmount');
            if (settleAmountEl) {
                settleAmountEl.value = '';
            }
            
            // إعادة تعيين التاريخ إلى اليوم
            const settleDateEl = document.getElementById('settleDate');
            if (settleDateEl) {
                const today = new Date().toISOString().split('T')[0];
                settleDateEl.value = today;
            }
            
            settleModalRemainingValue = 0;
            
            // إعادة تعطيل الزر
            const submitBtn = document.getElementById('settleSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
            }
        });
    }
});
</script>

<!-- Modal كشف حساب المرتب -->
<div class="modal fade d-none d-md-block" id="salaryStatementModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>كشف حساب المرتب</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">الموظف</label>
                    <input type="text" class="form-control" id="statementEmployeeName" readonly>
                    <input type="hidden" id="statementSalaryId">
                    <input type="hidden" id="statementUserId">
                </div>
                
                <div class="mb-3">
                    <label class="form-label">نوع الفترة <span class="text-danger">*</span></label>
                    <select class="form-select" id="statementPeriodType" onchange="updateStatementPeriodFields()">
                        <option value="current_month">الشهر الحالي حتى اليوم</option>
                        <option value="specific_month">شهر محدد</option>
                        <option value="date_range">فترة محددة</option>
                    </select>
                </div>
                
                <div id="statementMonthFields">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الشهر</label>
                            <select class="form-select" id="statementMonth">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">السنة</label>
                            <select class="form-select" id="statementYear">
                                <?php for ($y = date('Y'); $y >= date('Y') - 10; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div id="statementDateRangeFields" style="display: none;">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">من تاريخ</label>
                            <input type="date" class="form-control" id="statementFromDate" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">إلى تاريخ</label>
                            <input type="date" class="form-control" id="statementToDate" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="printSalaryStatement()">
                    <i class="bi bi-printer me-2"></i>طباعة كشف الحساب
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Cards للموبايل - نماذج الرواتب -->
<!-- Card تفاصيل الراتب - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="salaryDetailsCard" style="display: none;">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">تفاصيل الراتب</h5>
    </div>
    <div class="card-body" id="salaryDetailsContentCard">
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">جاري التحميل...</span>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <button type="button" class="btn btn-secondary" onclick="closeSalaryDetailsCard()">إغلاق</button>
    </div>
</div>

<!-- Card تعديل الراتب - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="modifySalaryCard" style="display: none;">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0">تعديل الراتب</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="modifySalaryFormCard">
            <input type="hidden" name="action" value="modify_salary">
            <input type="hidden" name="salary_id" id="modifySalaryIdCard">
            <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
            <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
            <div class="mb-3">
                <label class="form-label">المستخدم</label>
                <input type="text" class="form-control" id="modifyUserNameCard" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">الراتب الأساسي</label>
                <input type="text" class="form-control" id="modifyBaseAmountCard" readonly>
            </div>
            <input type="hidden" id="modifyCollectionsBonusCard" value="0">
            <div class="row">
                <div class="col-6 mb-3">
                    <label for="modifyBonusCard" class="form-label">مكافأة</label>
                    <input type="number" step="0.01" class="form-control" id="modifyBonusCard" name="bonus" value="0" min="0">
                </div>
                <div class="col-6 mb-3">
                    <label for="modifyDeductionsCard" class="form-label">خصومات</label>
                    <input type="number" step="0.01" class="form-control" id="modifyDeductionsCard" name="deductions" value="0" min="0">
                </div>
            </div>
            <div class="mb-3">
                <label for="modifyNotesCard" class="form-label">ملاحظات</label>
                <textarea class="form-control" id="modifyNotesCard" name="notes" rows="3" 
                          placeholder="اذكر سبب التعديل (اختياري)"></textarea>
            </div>
            <div class="alert alert-info">
                <strong>الراتب الجديد:</strong> <span id="newTotalAmountCard">0.00</span>
            </div>
            <?php if ($currentUser['role'] === 'accountant'): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-info-circle me-2"></i>
                    هذا التعديل يحتاج موافقة من المدير
                </div>
            <?php endif; ?>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo $currentUser['role'] === 'accountant' ? 'إرسال للموافقة' : 'تأكيد التعديل'; ?>
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeModifySalaryCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card طلب سلفة جديدة - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="requestAdvanceCard" style="display: none;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>طلب سلفة جديدة</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="request_advance">
            <?php if (in_array($currentUser['role'] ?? '', ['accountant', 'manager', 'developer'], true)): ?>
            <div class="mb-3">
                <label class="form-label">الموظف <span class="text-danger">*</span></label>
                <select name="user_id" class="form-select" required>
                    <option value="">اختر الموظف</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>">
                            <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
            <?php endif; ?>
            
            <div class="mb-3">
                <label class="form-label">مبلغ السلفة (ج.م) <span class="text-danger">*</span></label>
                <input type="number" name="amount" class="form-control" step="0.01" min="1" required 
                       placeholder="0.00">
            </div>
            
            <div class="mb-3">
                <label class="form-label">سبب الطلب (اختياري)</label>
                <textarea name="reason" class="form-control" rows="3" 
                          placeholder="اذكر سبب طلب السلفة..."></textarea>
                <small class="text-muted">يمكنك ترك هذا الحقل فارغاً</small>
            </div>
            
            <div class="mb-3">
                <label class="form-label">تاريخ الطلب</label>
                <input type="date" name="request_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>ملاحظة:</strong> سيتم خصم مبلغ السلفة من راتبك القادم بعد موافقة المحاسب والمدير.
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-send me-1"></i>إرسال الطلب
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeRequestAdvanceCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card رفض السلفة - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="rejectCard" style="display: none;">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-x-circle me-2"></i>رفض طلب السلفة</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="advance_id" id="rejectAdvanceIdCard">
            <input type="hidden" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
            <input type="hidden" name="year" value="<?php echo htmlspecialchars($selectedYear); ?>">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            <div class="mb-3">
                <label for="rejection_reasonCard" class="form-label">سبب الرفض <span class="text-danger">*</span></label>
                <textarea name="rejection_reason" id="rejection_reasonCard" class="form-control" rows="3" required placeholder="اذكر سبب الرفض"></textarea>
            </div>
            <div class="alert alert-warning mb-3">
                <i class="bi bi-info-circle me-2"></i>
                سيتم إرسال السبب للموظف في إشعار فوري.
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">رفض الطلب</button>
                <button type="button" class="btn btn-secondary" onclick="closeRejectCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card تسوية مستحقات - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="settleSalaryCard" style="display: none;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>تسوية مستحقات موظف</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="settleSalaryFormCard">
            <input type="hidden" name="action" value="settle_salary">
            <input type="hidden" name="salary_id" id="settleSalaryIdCard">
            <input type="hidden" name="user_id" id="settleUserIdCard">
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>الموظف:</strong> <span id="settleUserNameCard"></span>
            </div>
            
            <div class="mb-3">
                <label class="form-label">اختر الراتب للتسوية <span class="text-danger">*</span></label>
                <select class="form-select" name="selected_salary_id" id="settleSalarySelectCard" required onchange="loadSelectedSalaryDataCard()">
                    <option value="">-- اختر راتب للتسوية --</option>
                </select>
                <small class="text-muted">يمكنك اختيار راتب من الشهر الحالي أو شهر ماضي.</small>
            </div>
            
            <div class="row mb-3">
                <div class="col-6">
                    <label class="form-label">المبلغ التراكمي</label>
                    <div class="form-control-plaintext fw-bold text-primary" id="settleAccumulatedAmountCard">0.00</div>
                </div>
                <div class="col-6">
                    <label class="form-label">المتبقي</label>
                    <div class="form-control-plaintext fw-bold text-warning" id="settleRemainingAmountCard">0.00</div>
                </div>
            </div>
            
            <hr>
            
            <div class="mb-3">
                <label class="form-label">مبلغ التسوية <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" class="form-control" name="settlement_amount" id="settleAmountCard" required oninput="updateSettleRemainingCard()" onchange="updateSettleRemainingCard()" onkeyup="updateSettleRemainingCard()">
                <small class="text-muted">أقصى مبلغ متاح: <span id="settleRemainingAmount2Card"></span></small>
            </div>
            
            <div class="mb-3">
                <label class="form-label">تاريخ التسوية <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="settlement_date" id="settleDateCard" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            
            <div class="mb-3">
                <label class="form-label">ملاحظات</label>
                <textarea class="form-control" name="notes" id="settleNotesCard" rows="3" placeholder="ملاحظات إضافية (اختياري)"></textarea>
            </div>
            
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>المتبقي بعد التسوية:</strong> <span id="settleNewRemainingCard" class="fw-bold">0.00</span>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success" id="settleSubmitBtnCard" disabled>
                    <i class="bi bi-check-circle me-2"></i>تأكيد التسوية
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeSettleSalaryCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card كشف حساب المرتب - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="salaryStatementCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>كشف حساب المرتب</h5>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label">الموظف</label>
            <input type="text" class="form-control" id="statementEmployeeNameCard" readonly>
            <input type="hidden" id="statementSalaryIdCard">
            <input type="hidden" id="statementUserIdCard">
        </div>
        
        <div class="mb-3">
            <label class="form-label">نوع الفترة <span class="text-danger">*</span></label>
            <select class="form-select" id="statementPeriodTypeCard" onchange="updateStatementPeriodFieldsCard()">
                <option value="current_month">الشهر الحالي حتى اليوم</option>
                <option value="specific_month">شهر محدد</option>
                <option value="date_range">فترة محددة</option>
            </select>
        </div>
        
        <div id="statementMonthFieldsCard">
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">الشهر</label>
                    <select class="form-select" id="statementMonthCard">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">السنة</label>
                    <select class="form-select" id="statementYearCard">
                        <?php for ($y = date('Y'); $y >= date('Y') - 10; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div id="statementDateRangeFieldsCard" style="display: none;">
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">من تاريخ</label>
                    <input type="date" class="form-control" id="statementFromDateCard" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" class="form-control" id="statementToDateCard" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
        </div>
        
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" onclick="printSalaryStatementCard()">
                <i class="bi bi-printer me-2"></i>طباعة كشف الحساب
            </button>
            <button type="button" class="btn btn-secondary" onclick="closeSalaryStatementCard()">إلغاء</button>
        </div>
    </div>
</div>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// دالة تحديث الساعات
function updateTotalHours() {
    if (confirm('هل تريد تحديث عدد الساعات (total_hours) لجميع الرواتب من سجلات الحضور؟')) {
        // تحديث القيم في النموذج المخفي
        const filterForm = document.querySelector('.filter-card form');
        if (filterForm) {
            const monthInput = filterForm.querySelector('select[name="month"]');
            const yearInput = filterForm.querySelector('select[name="year"]');
            const userIdInput = filterForm.querySelector('select[name="user_id"]');
            
            if (monthInput) {
                document.getElementById('updateHoursMonth').value = monthInput.value;
            }
            if (yearInput) {
                document.getElementById('updateHoursYear').value = yearInput.value;
            }
            if (userIdInput) {
                document.getElementById('updateHoursUserId').value = userIdInput.value || '0';
            }
        }
        
        // إرسال النموذج
        document.getElementById('updateHoursForm').submit();
    }
}

// منع فتح النماذج مباشرة على الموبايل - استخدام البطاقات بدلاً منها
(function() {
    const modalToCardMap = {
        'salaryDetailsModal': 'salaryDetailsCard',
        'modifySalaryModal': 'modifySalaryCard',
        'requestAdvanceModal': 'requestAdvanceCard',
        'rejectModal': 'rejectCard',
        'settleSalaryModal': 'settleSalaryCard',
        'salaryStatementModal': 'salaryStatementCard'
    };
    
    // حماية قوية: منع فتح النماذج على الموبايل قبل أن تفتح
    document.addEventListener('show.bs.modal', function(event) {
        if (isMobile()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            
            const modal = event.target;
            const modalId = modal.id;
            
            // إخفاء النموذج فوراً
            modal.style.display = 'none';
            modal.classList.remove('show');
            
            // محاولة استخدام البطاقة المقابلة
            const cardId = modalToCardMap[modalId];
            if (cardId) {
                const card = document.getElementById(cardId);
                if (card) {
                    card.style.display = 'block';
                    setTimeout(() => scrollToElement(card), 50);
                }
            }
            return false;
        }
    }, true);
    
    // مستمع لحدث shown.bs.modal لإخفاء النماذج فوراً على الموبايل (حماية إضافية)
    document.addEventListener('shown.bs.modal', function(event) {
        if (isMobile()) {
            const modal = event.target;
            const modalId = modal.id;
            
            // إخفاء النموذج فوراً
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) {
                modalInstance.hide();
            }
            modal.style.display = 'none';
            modal.classList.remove('show');
            
            // إزالة backdrop
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // محاولة استخدام البطاقة المقابلة
            const cardId = modalToCardMap[modalId];
            if (cardId) {
                const card = document.getElementById(cardId);
                if (card) {
                    card.style.display = 'block';
                    setTimeout(() => scrollToElement(card), 50);
                }
            }
        }
    });
    
    // منع فتح النماذج عبر data-bs-toggle على الموبايل
    document.addEventListener('click', function(event) {
        if (!isMobile()) return;
        
        const target = event.target.closest('[data-bs-toggle="modal"]');
        if (target) {
            const modalSelector = target.getAttribute('data-bs-target') || target.getAttribute('href');
            if (modalSelector) {
                event.preventDefault();
                event.stopPropagation();
                
                const modalId = modalSelector.replace('#', '');
                const cardId = modalToCardMap[modalId];
                if (cardId) {
                    const card = document.getElementById(cardId);
                    if (card) {
                        card.style.display = 'block';
                        setTimeout(() => scrollToElement(card), 50);
                    }
                }
            }
        }
    }, true);
    
    // منع فتح النماذج عبر show.bs.modal
    document.addEventListener('show.bs.modal', function(event) {
        if (isMobile()) {
            event.preventDefault();
            event.stopPropagation();
            
            const modalId = event.target.id;
            const cardId = modalToCardMap[modalId];
            if (cardId) {
                const card = document.getElementById(cardId);
                if (card) {
                    card.style.display = 'block';
                    setTimeout(() => scrollToElement(card), 50);
                }
            }
        }
    }, true);
})();

// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();
</script>

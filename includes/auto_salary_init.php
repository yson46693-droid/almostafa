<?php
/**
 * ملف إنشاء الرواتب التلقائي
 * يتم استدعاؤه عند أول زيارة للموقع من أي حساب
 * يقوم بإنشاء الرواتب للموظفين مرة واحدة لكل شهر
 * 
 * @author System
 * @version 1.1
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

/**
 * فحص فوري من قاعدة البيانات
 * لمنع أي استدعاءات إضافية إذا تم التنفيذ اليوم
 * يفحص وجود سجل بتاريخ اليوم في جدول auto_salary_init_logs
 */
function checkAutoSalaryInitToday() {
    $today = date('Y-m-d');
    $cacheKey = 'auto_salary_init_today_check_' . $today;

    // استخدام GLOBALS cache لتقليل استعلامات قاعدة البيانات
    if (!isset($GLOBALS[$cacheKey])) {
        try {
            // تحميل db.php إذا لم يكن محملاً
            if (!function_exists('db')) {
                require_once __DIR__ . '/db.php';
            }
            
            $db = db();
            
            // التحقق من وجود جدول السجلات وإنشاؤه إذا لم يكن موجوداً
            $tableExists = $db->queryOne("SHOW TABLES LIKE 'auto_salary_init_logs'");
            
            if (empty($tableExists)) {
                // إنشاء الجدول إذا لم يكن موجوداً
                try {
                    $db->execute("
                        CREATE TABLE IF NOT EXISTS `auto_salary_init_logs` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `call_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          `user_id` int(11) DEFAULT NULL,
                          `status` enum('executed','skipped','error') NOT NULL DEFAULT 'skipped',
                          `reason` varchar(255) DEFAULT NULL,
                          `created_count` int(11) DEFAULT 0,
                          `skipped_count` int(11) DEFAULT 0,
                          `error_count` int(11) DEFAULT 0,
                          `month` int(2) DEFAULT NULL,
                          `year` int(4) DEFAULT NULL,
                          `ip_address` varchar(45) DEFAULT NULL,
                          `request_uri` varchar(500) DEFAULT NULL,
                          PRIMARY KEY (`id`),
                          KEY `call_time` (`call_time`),
                          KEY `user_id` (`user_id`),
                          KEY `status` (`status`),
                          KEY `month_year` (`month`, `year`),
                          KEY `status_date` (`status`, `call_time`),
                          KEY `date_status` (DATE(`call_time`), `status`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    error_log("auto_salary_init: Created auto_salary_init_logs table");
                } catch (Exception $e) {
                    error_log("auto_salary_init: Failed to create table: " . $e->getMessage());
                }
            }
            
            // فحص دقيق: هل تم التنفيذ اليوم؟ (نفحص أي سجل بتاريخ اليوم بغض النظر عن الحالة)
            // هذا يضمن أننا لا نستدعي الملف مرة أخرى في نفس اليوم حتى لو فشل التنفيذ السابق
            $todayExecution = $db->queryOne(
                "SELECT id, status FROM auto_salary_init_logs 
                 WHERE DATE(call_time) = CURDATE()
                 LIMIT 1"
            );
            
            $GLOBALS[$cacheKey] = !empty($todayExecution);
            
            if ($GLOBALS[$cacheKey]) {
                error_log("auto_salary_init: Found existing log for today (status: " . ($todayExecution['status'] ?? 'unknown') . ") - skipping execution");
            }
        } catch (Exception $e) {
            // في حالة الخطأ، نعتبر أنه لم يتم التنفيذ
            $GLOBALS[$cacheKey] = false;
            error_log("auto_salary_init: Error checking today's execution: " . $e->getMessage());
        }
    }
    
    return $GLOBALS[$cacheKey] ?? false;
}

/**
 * التأكد من وجود جدول سجلات استدعاءات auto_salary_init
 */
function ensureAutoSalaryInitLogsTable($db) {
    static $tableChecked = false;
    
    if ($tableChecked) {
        return true;
    }
    
    try {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'auto_salary_init_logs'");
        if (empty($tableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `auto_salary_init_logs` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `call_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `user_id` int(11) DEFAULT NULL,
                  `status` enum('executed','skipped','error') NOT NULL DEFAULT 'skipped',
                  `reason` varchar(255) DEFAULT NULL,
                  `created_count` int(11) DEFAULT 0,
                  `skipped_count` int(11) DEFAULT 0,
                  `error_count` int(11) DEFAULT 0,
                  `month` int(2) DEFAULT NULL,
                  `year` int(4) DEFAULT NULL,
                  `ip_address` varchar(45) DEFAULT NULL,
                  `request_uri` varchar(500) DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `call_time` (`call_time`),
                  KEY `user_id` (`user_id`),
                  KEY `status` (`status`),
                  KEY `month_year` (`month`, `year`),
                  KEY `status_date` (`status`, `call_time`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        $tableChecked = true;
        return true;
    } catch (Exception $e) {
        error_log("auto_salary_init: Failed to create logs table: " . $e->getMessage());
        return false;
    }
}

/**
 * تسجيل محاولة استدعاء auto_salary_init
 * يتم تسجيل جميع الاستدعاءات المهمة (executed و error و skipped المهمة)
 * هذه الدالة تستخدم فقط للتسجيل اليدوي، بينما runAutoSalaryInit تسجل تلقائياً
 */
function logAutoSalaryInitCall($db, $status, $reason = null, $createdCount = 0, $skippedCount = 0, $errorCount = 0, $month = null, $year = null) {
    try {
        ensureAutoSalaryInitLogsTable($db);
        
        $userId = null;
        if (function_exists('getCurrentUser')) {
            $currentUser = getCurrentUser();
            $userId = $currentUser['id'] ?? null;
        } elseif (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
        }
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $requestUri = $_SERVER['REQUEST_URI'] ?? null;
        
        $db->execute(
            "INSERT INTO auto_salary_init_logs 
             (call_time, user_id, status, reason, created_count, skipped_count, error_count, month, year, ip_address, request_uri)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$userId, $status, $reason, $createdCount, $skippedCount, $errorCount, $month, $year, $ipAddress, $requestUri]
        );
        
        return true;
    } catch (Exception $e) {
        error_log("auto_salary_init: Failed to log call: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على مسار ملف lock
 */
function getAutoSalaryInitLockFile() {
    $lockDir = sys_get_temp_dir();
    $lockFile = $lockDir . DIRECTORY_SEPARATOR . 'auto_salary_init_' . date('Y-m-d') . '.lock';
    return $lockFile;
}

/**
 * محاولة الحصول على lock للتنفيذ
 * @return resource|false ملف lock أو false إذا فشل
 */
function acquireAutoSalaryInitLock() {
    $lockFile = getAutoSalaryInitLockFile();
    $fp = @fopen($lockFile, 'c+');
    
    if (!$fp) {
        return false;
    }
    
    // محاولة الحصول على lock غير متزامن (non-blocking)
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    
    return $fp;
}

/**
 * إطلاق lock
 */
function releaseAutoSalaryInitLock($fp) {
    if ($fp && is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * التحقق من أن auto_salary_init تم استدعاؤه اليوم بالفعل
 * فحص سريع بدون transaction للسرعة
 */
function wasAutoSalaryInitCalledToday($db) {
    return checkAutoSalaryInitToday();
}

/**
 * إنشاء الرواتب التلقائية للشهر الحالي
 * يتم تنفيذها مرة واحدة فقط لكل شهر
 * 
 * @return array نتيجة العملية
 */
function initializeMonthSalaries() {
    // التحقق من تسجيل الدخول
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return ['success' => false, 'message' => 'User not logged in', 'created' => 0];
    }
    
    // الحصول على الشهر والسنة الحاليين
    $currentMonth = (int)date('n');
    $currentYear = (int)date('Y');
    
    // التحقق الصارم من صحة الشهر والسنة
    if ($currentMonth < 1 || $currentMonth > 12) {
        error_log("auto_salary_init: Invalid current month: {$currentMonth}");
        return ['success' => false, 'message' => 'Invalid month', 'created' => 0];
    }
    
    if ($currentYear < 2000 || $currentYear > 2100) {
        error_log("auto_salary_init: Invalid current year: {$currentYear}");
        return ['success' => false, 'message' => 'Invalid year', 'created' => 0];
    }
    
    try {
        $db = db();
        $conn = $db->getConnection();
        
        // استخدام transaction مع SELECT FOR UPDATE لمنع race condition
        $conn->begin_transaction();
        
        try {
            // التحقق من أن auto_salary_init تم استدعاؤه اليوم بالفعل مع lock
            // نفحص أي سجل بتاريخ اليوم (بغض النظر عن الحالة) لأن runAutoSalaryInit تسجل السجل قبل الاستدعاء
            $todayCall = $db->queryOne(
                "SELECT id, status, call_time 
                 FROM auto_salary_init_logs 
                 WHERE DATE(call_time) = CURDATE()
                 ORDER BY call_time DESC 
                 LIMIT 1
                 FOR UPDATE"
            );
            
            if (!empty($todayCall)) {
                $conn->commit();
                // تم التنفيذ اليوم بالفعل - نرجع بدون إنشاء رواتب جديدة
                return ['success' => true, 'message' => 'Already executed today', 'created' => 0, 'skipped' => true];
            }
            
            // مفتاح الجلسة لمنع التكرار
            $sessionKey = "auto_salaries_initialized_{$currentYear}_{$currentMonth}";
            
            // التحقق من أن العملية لم تتم من قبل في هذه الجلسة
            if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
                $conn->commit();
                // لا نسجل skipped لتقليل عدد السجلات
                return ['success' => true, 'message' => 'Already initialized this session', 'created' => 0, 'skipped' => true];
            }
            
            // نستمر في transaction للتنفيذ
            
            // التحقق من وجود جدول salaries
            $tableCheck = $db->queryOne("SHOW TABLES LIKE 'salaries'");
            if (empty($tableCheck)) {
                $conn->rollback();
                error_log("auto_salary_init: salaries table does not exist");
                return ['success' => false, 'message' => 'Salaries table not found', 'created' => 0];
            }
            
            // التحقق من وجود علامة في قاعدة البيانات لهذا الشهر
            // نستخدم جدول system_settings لتخزين حالة التهيئة
            $settingKey = "salaries_initialized_{$currentYear}_{$currentMonth}";
            
            // التحقق من وجود جدول system_settings
            $settingsTableCheck = $db->queryOne("SHOW TABLES LIKE 'system_settings'");
            if (!empty($settingsTableCheck)) {
                $existingSetting = $db->queryOne(
                    "SELECT `value` FROM system_settings WHERE `key` = ?",
                    [$settingKey]
                );
                
            if (!empty($existingSetting) && $existingSetting['value'] === '1') {
                // تم التهيئة من قبل لهذا الشهر
                $conn->commit();
                $_SESSION[$sessionKey] = true;
                // لا نسجل skipped لتقليل عدد السجلات
                return ['success' => true, 'message' => 'Already initialized this month', 'created' => 0, 'skipped' => true];
            }
            }
        
        // التحقق من نوع عمود month
        $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
        $monthType = $monthColumnCheck['Type'] ?? '';
        $isMonthDate = stripos($monthType, 'date') !== false;
        
        // التحقق من وجود عمود year
        $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
        $hasYearColumn = !empty($yearColumnCheck);
        
            // إعداد التاريخ الصحيح
            $targetDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
            $targetYearMonth = sprintf('%04d-%02d', $currentYear, $currentMonth);
            
            error_log("auto_salary_init: Starting initialization for {$currentMonth}/{$currentYear} (targetDate: {$targetDate}, isMonthDate: " . ($isMonthDate ? 'true' : 'false') . ")");
            
            // الحصول على قائمة الموظفين النشطين (باستثناء المديرين)
            $users = $db->query(
                "SELECT id, full_name, username, hourly_rate, role 
                 FROM users 
                 WHERE status = 'active' 
                 AND role != 'manager' 
                 AND hourly_rate > 0
                 ORDER BY role, full_name"
            );
            
            if (empty($users)) {
                $conn->commit();
                error_log("auto_salary_init: No active users found with hourly_rate > 0");
                $_SESSION[$sessionKey] = true;
                // ملاحظة: لا نسجل سجل هنا لأن runAutoSalaryInit تسجل السجل قبل الاستدعاء وتقوم بتحديثه بعد الانتهاء
                return ['success' => true, 'message' => 'No users to process', 'created' => 0, 'skipped' => true];
            }
        
            // تحميل salary_calculator إذا لم يكن محملاً
            if (!function_exists('createOrUpdateSalary')) {
                $salaryCalcPath = __DIR__ . '/salary_calculator.php';
                if (file_exists($salaryCalcPath)) {
                    require_once $salaryCalcPath;
                } else {
                    $conn->rollback();
                    error_log("auto_salary_init: salary_calculator.php not found");
                    return ['success' => false, 'message' => 'Salary calculator not found', 'created' => 0];
                }
            }
            
            $createdCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            $totalUsers = count($users);
            
            foreach ($users as $user) {
            $userId = intval($user['id']);
            $hourlyRate = floatval($user['hourly_rate'] ?? 0);
            $userName = $user['full_name'] ?? $user['username'] ?? "User {$userId}";
            
            // تخطي المستخدمين بدون سعر ساعة
            if ($hourlyRate <= 0) {
                $skippedCount++;
                continue;
            }
            
            // التحقق من وجود راتب للمستخدم في الشهر الحالي
            $hasSalary = false;
            
            try {
                if ($isMonthDate) {
                    // عمود month من نوع DATE
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ? AND month != '0000-00-00' AND month IS NOT NULL LIMIT 1",
                        [$userId, $targetYearMonth]
                    );
                    $hasSalary = !empty($existingSalary);
                } elseif ($hasYearColumn) {
                    // عمود month من نوع INT مع وجود عمود year
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ? AND month > 0 AND year > 0 LIMIT 1",
                        [$userId, $currentMonth, $currentYear]
                    );
                    $hasSalary = !empty($existingSalary);
                } else {
                    // عمود month من نوع INT بدون عمود year
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND month > 0 LIMIT 1",
                        [$userId, $currentMonth]
                    );
                    $hasSalary = !empty($existingSalary);
                }
                
                if ($hasSalary) {
                    $skippedCount++;
                    continue;
                }
                
                // إنشاء الراتب
                $result = createOrUpdateSalary(
                    $userId,
                    $currentMonth,
                    $currentYear,
                    0, // bonus
                    0, // deductions
                    'إنشاء تلقائي عند أول زيارة للموقع'
                );
                
                if ($result['success']) {
                    $createdCount++;
                    error_log("auto_salary_init: Created salary for user {$userId} ({$userName})");
                } else {
                    $errorCount++;
                    error_log("auto_salary_init: Failed to create salary for user {$userId} ({$userName}): " . ($result['message'] ?? 'Unknown error'));
                }
                
            } catch (Exception $e) {
                $errorCount++;
                error_log("auto_salary_init: Exception for user {$userId} ({$userName}): " . $e->getMessage());
            }
        }
        
            // تسجيل ملخص العملية
            error_log("auto_salary_init: Summary - Created: {$createdCount}, Skipped: {$skippedCount}, Errors: {$errorCount}, Total: {$totalUsers}");
            
            // تسجيل علامة التهيئة في قاعدة البيانات
            if (!empty($settingsTableCheck)) {
                try {
                    $db->execute(
                        "INSERT INTO system_settings (`key`, `value`, updated_at) VALUES (?, '1', NOW())
                         ON DUPLICATE KEY UPDATE `value` = '1', updated_at = NOW()",
                        [$settingKey]
                    );
                } catch (Exception $e) {
                    error_log("auto_salary_init: Failed to save setting: " . $e->getMessage());
                }
            }
            
            // تسجيل علامة الجلسة
            $_SESSION[$sessionKey] = true;
            
            // ملاحظة: لا نسجل سجل جديد هنا لأن runAutoSalaryInit تسجل السجل قبل الاستدعاء
            // وتقوم بتحديثه بعد الانتهاء من التنفيذ
            
            // إتمام transaction
            $conn->commit();
            
            return [
                'success' => true,
                'message' => "تم إنشاء {$createdCount} راتب للشهر الحالي",
                'created' => $createdCount,
                'skipped' => $skippedCount,
                'errors' => $errorCount,
                'total' => $totalUsers,
                'month' => $currentMonth,
                'year' => $currentYear
            ];
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        error_log("auto_salary_init: Critical error: " . $e->getMessage());
        try {
            $db = db();
            logAutoSalaryInitCall($db, 'error', 'خطأ حرج: ' . $e->getMessage(), 0, 0, 0, $currentMonth ?? null, $currentYear ?? null);
        } catch (Exception $logError) {
            error_log("auto_salary_init: Failed to log error: " . $logError->getMessage());
        }
        return ['success' => false, 'message' => $e->getMessage(), 'created' => 0];
    }
}

/**
 * تنفيذ التهيئة التلقائية
 * يتم استدعاؤها تلقائياً مرة واحدة كل يوم
 * يفحص قاعدة البيانات أولاً للتأكد من عدم التنفيذ اليوم
 * إذا لم يتم التنفيذ، ينفذ العملية ثم يسجل التاريخ
 */
function runAutoSalaryInit() {
    // منع التنفيذ المتكرر في نفس الطلب
    if (!empty($GLOBALS['auto_salary_init_executed'])) {
        return;
    }
    
    // فحص أولي: هل تم التنفيذ اليوم؟ (من cache)
    if (checkAutoSalaryInitToday()) {
        return;
    }
    
    // فحص سريع جداً قبل أي عمليات
    // التحقق من أن المستخدم مسجل الدخول
    if (!function_exists('isLoggedIn')) {
        return;
    }
    
    if (!isLoggedIn()) {
        return;
    }
    
    // علامة التنفيذ لمنع التكرار
    $GLOBALS['auto_salary_init_executed'] = true;
    
    $lockFile = null;
    
    try {
        // محاولة الحصول على lock لمنع التنفيذ المتزامن
        $lockFile = acquireAutoSalaryInitLock();
        
        if (!$lockFile) {
            // لا يمكن الحصول على lock - يعني أن هناك عملية أخرى قيد التنفيذ
            // نتحقق من قاعدة البيانات مرة أخرى
            $db = db();
            if (wasAutoSalaryInitCalledToday($db)) {
                return;
            }
            // إذا لم يتم التنفيذ، نتخطى (لا ننتظر)
            return;
        }
        
        $db = db();
        
        // التحقق مرة أخرى من قاعدة البيانات بعد الحصول على lock
        // هذا الفحص الحاسم: إذا وُجد سجل بتاريخ اليوم، لا ننفذ
        $todayExecution = $db->queryOne(
            "SELECT id, status FROM auto_salary_init_logs 
             WHERE DATE(call_time) = CURDATE()
             LIMIT 1"
        );
        
        if (!empty($todayExecution)) {
            // تم التنفيذ اليوم بالفعل - تحديث cache والخروج
            $today = date('Y-m-d');
            $cacheKey = 'auto_salary_init_today_check_' . $today;
            $GLOBALS[$cacheKey] = true;
            releaseAutoSalaryInitLock($lockFile);
            error_log("auto_salary_init: Already executed today (found log ID: {$todayExecution['id']}, status: {$todayExecution['status']}) - skipping");
            return;
        }
        
        // لم يتم التنفيذ اليوم - نبدأ التنفيذ
        error_log("auto_salary_init: Starting auto salary initialization for " . date('Y-m-d'));
        
        // تسجيل بداية التنفيذ في قاعدة البيانات فوراً (قبل التنفيذ الفعلي)
        // هذا يمنع أي محاولات أخرى من التنفيذ في نفس الوقت
        try {
            ensureAutoSalaryInitLogsTable($db);
            
            $userId = null;
            if (function_exists('getCurrentUser')) {
                $currentUser = getCurrentUser();
                $userId = $currentUser['id'] ?? null;
            } elseif (isset($_SESSION['user_id'])) {
                $userId = $_SESSION['user_id'];
            }
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $requestUri = $_SERVER['REQUEST_URI'] ?? null;
            
            // تسجيل سجل أولي بحالة 'executed' (سيتم تحديثه لاحقاً)
            $db->execute(
                "INSERT INTO auto_salary_init_logs 
                 (call_time, user_id, status, reason, created_count, skipped_count, error_count, month, year, ip_address, request_uri)
                 VALUES (NOW(), ?, 'executed', 'بدء التنفيذ', 0, 0, 0, ?, ?, ?, ?)",
                [$userId, (int)date('n'), (int)date('Y'), $ipAddress, $requestUri]
            );
            
            // تحديث cache فوراً
            $today = date('Y-m-d');
            $cacheKey = 'auto_salary_init_today_check_' . $today;
            $GLOBALS[$cacheKey] = true;
            
            error_log("auto_salary_init: Logged initial execution record for today");
        } catch (Exception $e) {
            error_log("auto_salary_init: Failed to log initial execution: " . $e->getMessage());
            // نستمر في التنفيذ حتى لو فشل التسجيل الأولي
        }
        
        // تنفيذ التهيئة
        $result = initializeMonthSalaries();
        
        // تحديث السجل بالنتائج النهائية
        $currentMonth = $result['month'] ?? (int)date('n');
        $currentYear = $result['year'] ?? (int)date('Y');
        
        try {
            $status = 'executed';
            $reason = 'تم التنفيذ بنجاح';
            
            if (isset($result['skipped']) && $result['skipped']) {
                $status = 'skipped';
                $reason = $result['message'] ?? 'تم التخطي - تم التهيئة من قبل';
            } elseif (isset($result['created']) && $result['created'] > 0) {
                $reason = "تم إنشاء {$result['created']} راتب";
            } elseif (isset($result['errors']) && $result['errors'] > 0) {
                $status = 'error';
                $reason = "تم التنفيذ مع {$result['errors']} أخطاء";
            } elseif (isset($result['success']) && !$result['success']) {
                $status = 'error';
                $reason = $result['message'] ?? 'فشل التنفيذ';
            }
            
            // تحديث السجل الذي أنشأناه سابقاً
            $db->execute(
                "UPDATE auto_salary_init_logs 
                 SET status = ?, reason = ?, created_count = ?, skipped_count = ?, error_count = ?, month = ?, year = ?
                 WHERE DATE(call_time) = CURDATE()
                 ORDER BY id DESC
                 LIMIT 1",
                [
                    $status,
                    $reason,
                    $result['created'] ?? 0,
                    $result['skipped'] ?? 0,
                    $result['errors'] ?? 0,
                    $currentMonth,
                    $currentYear
                ]
            );
            
            error_log("auto_salary_init: Updated execution log with final results (status: $status, created: " . ($result['created'] ?? 0) . ")");
        } catch (Exception $e) {
            error_log("auto_salary_init: Failed to update execution log: " . $e->getMessage());
        }
        
        // تسجيل النتيجة في error_log (للتتبع)
        if (isset($result['created']) && $result['created'] > 0) {
            error_log("auto_salary_init: SUCCESS - Auto-created {$result['created']} salaries for month {$result['month']}/{$result['year']}");
        } elseif (isset($result['skipped']) && $result['skipped']) {
            error_log("auto_salary_init: Skipped - Already initialized or no users to process");
        } else {
            error_log("auto_salary_init: Result - " . json_encode($result));
        }
        
    } catch (Exception $e) {
        error_log("auto_salary_init: Error in runAutoSalaryInit: " . $e->getMessage());
        
        // في حالة الخطأ، نسجل السجل بحالة 'error'
        try {
            $db = db();
            ensureAutoSalaryInitLogsTable($db);
            
            $userId = null;
            if (function_exists('getCurrentUser')) {
                $currentUser = getCurrentUser();
                $userId = $currentUser['id'] ?? null;
            } elseif (isset($_SESSION['user_id'])) {
                $userId = $_SESSION['user_id'];
            }
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $requestUri = $_SERVER['REQUEST_URI'] ?? null;
            
            // التحقق من وجود سجل اليوم
            $todayLog = $db->queryOne(
                "SELECT id FROM auto_salary_init_logs 
                 WHERE DATE(call_time) = CURDATE()
                 LIMIT 1"
            );
            
            if (empty($todayLog)) {
                // إنشاء سجل خطأ جديد
                $db->execute(
                    "INSERT INTO auto_salary_init_logs 
                     (call_time, user_id, status, reason, created_count, skipped_count, error_count, month, year, ip_address, request_uri)
                     VALUES (NOW(), ?, 'error', ?, 0, 0, 0, ?, ?, ?, ?)",
                    [$userId, 'خطأ في التنفيذ: ' . $e->getMessage(), (int)date('n'), (int)date('Y'), $ipAddress, $requestUri]
                );
            } else {
                // تحديث السجل الموجود
                $db->execute(
                    "UPDATE auto_salary_init_logs 
                     SET status = 'error', reason = ?
                     WHERE DATE(call_time) = CURDATE()
                     ORDER BY id DESC
                     LIMIT 1",
                    ['خطأ في التنفيذ: ' . $e->getMessage()]
                );
            }
            
            // تحديث cache
            $today = date('Y-m-d');
            $cacheKey = 'auto_salary_init_today_check_' . $today;
            $GLOBALS[$cacheKey] = true;
        } catch (Exception $logError) {
            error_log("auto_salary_init: Failed to log error: " . $logError->getMessage());
        }
    } finally {
        // إطلاق lock في جميع الحالات
        if ($lockFile) {
            releaseAutoSalaryInitLock($lockFile);
        }
    }
}

// يتم استدعاء runAutoSalaryInit() تلقائياً من includes/config.php مرة واحدة كل يوم
// كما يمكن استدعاؤه يدوياً من modules/manager/users.php عند إضافة مستخدم جديد


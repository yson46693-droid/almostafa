<?php
/**
 * نظام الجداول الزمنية للتحصيل
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';

// دالة مساعدة لتنسيق العملة (إذا لم تكن موجودة)
if (!function_exists('formatCurrency')) {
    require_once __DIR__ . '/config.php';
}

/**
 * إنشاء جدول زمني للتحصيل
 */
function createPaymentSchedule($saleId, $customerId, $salesRepId, $totalAmount, $installments, 
                                $firstDueDate, $intervalDays = 30, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        if (!$createdBy) {
            return ['success' => false, 'message' => 'يجب تسجيل الدخول'];
        }
        
        $installmentAmount = $totalAmount / $installments;
        $schedules = [];
        
        for ($i = 1; $i <= $installments; $i++) {
            $dueDate = date('Y-m-d', strtotime($firstDueDate . " + " . (($i - 1) * $intervalDays) . " days"));
            
            $status = $i === 1 ? 'pending' : 'pending';
            
            $db->execute(
                "INSERT INTO payment_schedules 
                (sale_id, customer_id, sales_rep_id, amount, due_date, installment_number, 
                 total_installments, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $saleId,
                    $customerId,
                    $salesRepId,
                    $installmentAmount,
                    $dueDate,
                    $i,
                    $installments,
                    $status,
                    $createdBy
                ]
            );
            
            $schedules[] = [
                'installment_number' => $i,
                'amount' => $installmentAmount,
                'due_date' => $dueDate
            ];
        }
        
        logAudit($createdBy, 'create_payment_schedule', 'payment_schedule', $saleId, null, [
            'installments' => $installments,
            'total_amount' => $totalAmount
        ]);
        
        return ['success' => true, 'schedules' => $schedules];
        
    } catch (Exception $e) {
        error_log("Payment Schedule Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء الجدول الزمني'];
    }
}

/**
 * الحصول على الجداول الزمنية
 */
function getPaymentSchedules($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    // التحقق من وجود عمود sale_number في جدول sales
    $saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
    $hasSaleNumberColumn = !empty($saleNumberColumnCheck);
    
    if ($hasSaleNumberColumn) {
        $sql = "SELECT ps.*, s.sale_number, c.name as customer_name, 
                       u.full_name as sales_rep_name, u.username as sales_rep_username
                FROM payment_schedules ps
                LEFT JOIN sales s ON ps.sale_id = s.id
                LEFT JOIN customers c ON ps.customer_id = c.id
                LEFT JOIN users u ON ps.sales_rep_id = u.id
                WHERE 1=1";
    } else {
        $sql = "SELECT ps.*, s.id as sale_number, c.name as customer_name, 
                       u.full_name as sales_rep_name, u.username as sales_rep_username
                FROM payment_schedules ps
                LEFT JOIN sales s ON ps.sale_id = s.id
                LEFT JOIN customers c ON ps.customer_id = c.id
                LEFT JOIN users u ON ps.sales_rep_id = u.id
                WHERE 1=1";
    }
    
    $params = [];
    
    if (!empty($filters['sales_rep_id'])) {
        $sql .= " AND ps.sales_rep_id = ?";
        $params[] = $filters['sales_rep_id'];
    }
    
    if (!empty($filters['customer_id'])) {
        $sql .= " AND ps.customer_id = ?";
        $params[] = $filters['customer_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND ps.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['due_date_from'])) {
        $sql .= " AND ps.due_date >= ?";
        $params[] = $filters['due_date_from'];
    }
    
    if (!empty($filters['due_date_to'])) {
        $sql .= " AND ps.due_date <= ?";
        $params[] = $filters['due_date_to'];
    }
    
    if (!empty($filters['overdue_only'])) {
        $sql .= " AND ps.status = 'pending' AND ps.due_date < CURDATE()";
    }
    
    $sql .= " ORDER BY ps.due_date ASC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * تسجيل دفعة
 */
function recordPayment($scheduleId, $paymentDate, $amount = null, $notes = null, $recordedBy = null) {
    try {
        $db = db();
        
        if ($recordedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $recordedBy = $currentUser['id'] ?? null;
        }
        
        $schedule = $db->queryOne("SELECT * FROM payment_schedules WHERE id = ?", [$scheduleId]);
        
        if (!$schedule) {
            return ['success' => false, 'message' => 'الجدول الزمني غير موجود'];
        }
        
        if ($schedule['status'] === 'paid') {
            return ['success' => false, 'message' => 'تم دفع هذه الدفعة بالفعل'];
        }
        
        $paymentAmount = $amount ?? $schedule['amount'];
        
        $db->execute(
            "UPDATE payment_schedules 
             SET payment_date = ?, status = 'paid', updated_at = NOW() 
             WHERE id = ?",
            [$paymentDate, $scheduleId]
        );
        
        // تحديث المبيعات (إذا كانت الأعمدة موجودة)
        if (!empty($schedule['sale_id'])) {
            // التحقق من وجود أعمدة paid_amount و remaining_amount في جدول sales
            $paidAmountColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'paid_amount'");
            $remainingAmountColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'remaining_amount'");
            
            if (!empty($paidAmountColumnCheck) && !empty($remainingAmountColumnCheck)) {
                $db->execute(
                    "UPDATE sales 
                     SET paid_amount = COALESCE(paid_amount, 0) + ?, 
                         remaining_amount = COALESCE(remaining_amount, 0) - ? 
                     WHERE id = ?",
                    [$paymentAmount, $paymentAmount, $schedule['sale_id']]
                );
            }
        }
        
        logAudit($recordedBy, 'record_payment', 'payment_schedule', $scheduleId, 
                 ['old_status' => $schedule['status']], 
                 ['new_status' => 'paid', 'amount' => $paymentAmount]);
        
        return ['success' => true, 'message' => 'تم تسجيل الدفعة بنجاح'];
        
    } catch (Exception $e) {
        error_log("Payment Recording Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تسجيل الدفعة'];
    }
}

/**
 * الحصول على التذكيرات المعلقة
 */
function getPendingReminders($salesRepId = null) {
    $db = db();
    
    $sql = "SELECT ps.*, c.name as customer_name, c.phone as customer_phone,
                   u.full_name as sales_rep_name
            FROM payment_schedules ps
            LEFT JOIN customers c ON ps.customer_id = c.id
            LEFT JOIN users u ON ps.sales_rep_id = u.id
            WHERE ps.status = 'pending' 
            AND ps.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND (ps.reminder_sent = 0 OR ps.reminder_sent_at < DATE_SUB(CURDATE(), INTERVAL 3 DAY))";
    
    $params = [];
    
    if ($salesRepId) {
        $sql .= " AND ps.sales_rep_id = ?";
        $params[] = $salesRepId;
    }
    
    $sql .= " ORDER BY ps.due_date ASC";
    
    return $db->query($sql, $params);
}

/**
 * إنشاء تذكير تلقائي
 */
function createAutoReminder($scheduleId, $daysBeforeDue = 3, $createdBy = null) {
    error_log('=== createAutoReminder START ===');
    error_log('Schedule ID: ' . $scheduleId . ', Days Before Due: ' . $daysBeforeDue . ', Created By: ' . ($createdBy ?? 'null'));
    
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        $schedule = $db->queryOne("SELECT * FROM payment_schedules WHERE id = ?", [$scheduleId]);
        
        if (!$schedule) {
            error_log('ERROR: Schedule not found for ID: ' . $scheduleId);
            return false;
        }
        
        if ($schedule['status'] === 'paid') {
            error_log('WARNING: Schedule ID ' . $scheduleId . ' is already paid - skipping reminder creation');
            return false;
        }
        
        error_log('Schedule found - Due Date: ' . ($schedule['due_date'] ?? 'null') . ', Status: ' . ($schedule['status'] ?? 'null') . ', Sales Rep ID: ' . ($schedule['sales_rep_id'] ?? 'null'));
        
        $reminderDate = date('Y-m-d', strtotime($schedule['due_date'] . " - {$daysBeforeDue} days"));
        
        error_log('Calculated reminder_date: ' . $reminderDate . ' (due_date: ' . ($schedule['due_date'] ?? 'null') . ' - ' . $daysBeforeDue . ' days)');
        
        // التحقق من وجود تذكير مسبق (بأي عدد أيام)
        $existing = $db->queryOne(
            "SELECT id, days_before_due, reminder_date, sent_status FROM payment_reminders 
             WHERE payment_schedule_id = ? AND reminder_type = 'before_due'",
            [$scheduleId]
        );
        
        if ($existing) {
            error_log('Existing reminder found - ID: ' . ($existing['id'] ?? 'null') . ', Days: ' . ($existing['days_before_due'] ?? 'null') . ', Reminder Date: ' . ($existing['reminder_date'] ?? 'null') . ', Sent Status: ' . ($existing['sent_status'] ?? 'null'));
            
            // إذا كان التذكير الموجود بنفس عدد الأيام، لا حاجة للتحديث
            if ($existing['days_before_due'] == $daysBeforeDue) {
                error_log('Existing reminder has same days_before_due - returning true without update');
                return true;
            }
            
            // حذف التذكير القديم وإنشاء واحد جديد بالعدد الجديد
            error_log('Deleting old reminder ID: ' . $existing['id']);
            $db->execute(
                "DELETE FROM payment_reminders WHERE id = ?",
                [$existing['id']]
            );
        }
        
        // تحديد نوع المستلم: إذا كان هناك sales_rep_id، أرسل للمندوب، وإلا أرسل للمحاسبين والمديرين
        $sentTo = ($schedule['sales_rep_id'] && $schedule['sales_rep_id'] > 0) ? 'sales_rep' : 'manager_accountant';
        
        error_log('Creating new reminder - sent_to: ' . $sentTo);
        
        $db->execute(
            "INSERT INTO payment_reminders 
            (payment_schedule_id, reminder_type, reminder_date, days_before_due, 
             sent_to, created_by) 
            VALUES (?, 'before_due', ?, ?, ?, ?)",
            [$scheduleId, $reminderDate, $daysBeforeDue, $sentTo, $createdBy]
        );
        
        $reminderId = $db->getLastInsertId();
        error_log('Reminder created successfully - ID: ' . $reminderId);
        error_log('=== createAutoReminder END - SUCCESS ===');
        
        return true;
        
    } catch (Exception $e) {
        error_log("ERROR in createAutoReminder: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        error_log('=== createAutoReminder END - ERROR ===');
        return false;
    }
}

/**
 * إرسال التذكيرات
 */
function sendPaymentReminders($salesRepId = null) {
    $db = db();
    
    $sql = "SELECT pr.*, ps.amount, ps.due_date, ps.sales_rep_id, ps.status as schedule_status,
                   COALESCE(c.name, lc.name) as customer_name,
                   u.full_name as sales_rep_name, u.id as sales_rep_id
            FROM payment_reminders pr
            JOIN payment_schedules ps ON pr.payment_schedule_id = ps.id
            LEFT JOIN customers c ON ps.customer_id = c.id AND ps.sales_rep_id IS NOT NULL
            LEFT JOIN local_customers lc ON ps.customer_id = lc.id AND ps.sales_rep_id IS NULL
            LEFT JOIN users u ON ps.sales_rep_id = u.id
            WHERE pr.sent_status = 'pending' 
              AND pr.reminder_date = CURDATE()
              AND ps.status IN ('pending', 'overdue')";
    $params = [];
    
    if ($salesRepId === null) {
        // للعملاء المحليين فقط (sales_rep_id IS NULL)
        $sql .= " AND ps.sales_rep_id IS NULL";
    } elseif (!empty($salesRepId)) {
        // لمندوب مبيعات محدد
        $sql .= " AND ps.sales_rep_id = ?";
        $params[] = $salesRepId;
    }
    
    error_log('=== sendPaymentReminders START ===');
    error_log('Sales Rep ID: ' . ($salesRepId ?? 'null'));
    error_log('Current Date: ' . date('Y-m-d'));
    error_log('Current DateTime: ' . date('Y-m-d H:i:s'));
    error_log('SQL Query: ' . $sql);
    error_log('SQL Params: ' . json_encode($params));
    
    // استعلام شامل للتحقق من جميع التذكيرات الموجودة
    $diagnosticSql = "SELECT pr.id, pr.payment_schedule_id, pr.reminder_date, pr.days_before_due, 
                            pr.sent_status, pr.reminder_type, pr.created_at,
                            ps.due_date, ps.status as schedule_status, ps.sales_rep_id,
                            DATEDIFF(pr.reminder_date, CURDATE()) as days_diff
                     FROM payment_reminders pr
                     JOIN payment_schedules ps ON pr.payment_schedule_id = ps.id
                     WHERE pr.reminder_type = 'before_due'";
    $diagnosticParams = [];
    if ($salesRepId === null) {
        $diagnosticSql .= " AND ps.sales_rep_id IS NULL";
    } elseif (!empty($salesRepId)) {
        $diagnosticSql .= " AND ps.sales_rep_id = ?";
        $diagnosticParams[] = $salesRepId;
    }
    $diagnosticSql .= " ORDER BY pr.reminder_date ASC LIMIT 50";
    
    $allRemindersDiagnostic = $db->query($diagnosticSql, $diagnosticParams);
    error_log('=== DIAGNOSTIC: All reminders in database ===');
    error_log('Total reminders found: ' . count($allRemindersDiagnostic));
    if (!empty($allRemindersDiagnostic)) {
        foreach ($allRemindersDiagnostic as $diag) {
            error_log('Reminder ID: ' . ($diag['id'] ?? 'N/A') . 
                      ' | reminder_date: ' . ($diag['reminder_date'] ?? 'N/A') . 
                      ' | due_date: ' . ($diag['due_date'] ?? 'N/A') . 
                      ' | days_before_due: ' . ($diag['days_before_due'] ?? 'N/A') . 
                      ' | sent_status: ' . ($diag['sent_status'] ?? 'N/A') . 
                      ' | schedule_status: ' . ($diag['schedule_status'] ?? 'N/A') . 
                      ' | days_diff: ' . ($diag['days_diff'] ?? 'N/A'));
        }
    } else {
        error_log('WARNING: No reminders found in database at all!');
    }
    
    // التحقق من التذكيرات القريبة من اليوم (خلال 7 أيام)
    $nearbySql = "SELECT pr.id, pr.reminder_date, pr.days_before_due, pr.sent_status,
                         ps.due_date, ps.status as schedule_status,
                         DATEDIFF(pr.reminder_date, CURDATE()) as days_diff
                  FROM payment_reminders pr
                  JOIN payment_schedules ps ON pr.payment_schedule_id = ps.id
                  WHERE pr.reminder_type = 'before_due'
                    AND pr.reminder_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) 
                                            AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    $nearbyParams = [];
    if ($salesRepId === null) {
        $nearbySql .= " AND ps.sales_rep_id IS NULL";
    } elseif (!empty($salesRepId)) {
        $nearbySql .= " AND ps.sales_rep_id = ?";
        $nearbyParams[] = $salesRepId;
    }
    $nearbySql .= " ORDER BY pr.reminder_date ASC";
    
    $nearbyReminders = $db->query($nearbySql, $nearbyParams);
    error_log('=== DIAGNOSTIC: Reminders within 7 days ===');
    error_log('Nearby reminders count: ' . count($nearbyReminders));
    if (!empty($nearbyReminders)) {
        foreach ($nearbyReminders as $nearby) {
            error_log('Nearby Reminder - ID: ' . ($nearby['id'] ?? 'N/A') . 
                      ' | reminder_date: ' . ($nearby['reminder_date'] ?? 'N/A') . 
                      ' | due_date: ' . ($nearby['due_date'] ?? 'N/A') . 
                      ' | days_diff: ' . ($nearby['days_diff'] ?? 'N/A') . 
                      ' | sent_status: ' . ($nearby['sent_status'] ?? 'N/A'));
        }
    }
    
    // التحقق من التذكيرات التي يجب أن تُرسل اليوم ولكن sent_status != 'pending'
    $todayButNotPendingSql = "SELECT pr.id, pr.reminder_date, pr.sent_status, pr.sent_at,
                                      ps.due_date, ps.status as schedule_status
                               FROM payment_reminders pr
                               JOIN payment_schedules ps ON pr.payment_schedule_id = ps.id
                               WHERE pr.reminder_date = CURDATE()
                                 AND pr.sent_status != 'pending'
                                 AND pr.reminder_type = 'before_due'";
    $todayButNotPendingParams = [];
    if ($salesRepId === null) {
        $todayButNotPendingSql .= " AND ps.sales_rep_id IS NULL";
    } elseif (!empty($salesRepId)) {
        $todayButNotPendingSql .= " AND ps.sales_rep_id = ?";
        $todayButNotPendingParams[] = $salesRepId;
    }
    $todayButNotPendingSql .= " LIMIT 10";
    
    $todayButNotPending = $db->query($todayButNotPendingSql, $todayButNotPendingParams);
    error_log('=== DIAGNOSTIC: Reminders with reminder_date = TODAY but sent_status != pending ===');
    error_log('Count: ' . count($todayButNotPending));
    if (!empty($todayButNotPending)) {
        foreach ($todayButNotPending as $notPending) {
            error_log('Already sent reminder - ID: ' . ($notPending['id'] ?? 'N/A') . 
                      ' | sent_status: ' . ($notPending['sent_status'] ?? 'N/A') . 
                      ' | sent_at: ' . ($notPending['sent_at'] ?? 'N/A'));
        }
    }
    
    // استعلام إضافي للتحقق من التذكيرات التي يجب أن تُرسل اليوم
    $checkSql = "SELECT pr.id, pr.payment_schedule_id, pr.reminder_date, pr.days_before_due, 
                        pr.sent_status, ps.due_date, ps.status as schedule_status
                 FROM payment_reminders pr
                 JOIN payment_schedules ps ON pr.payment_schedule_id = ps.id
                 WHERE pr.reminder_type = 'before_due' 
                   AND ps.status IN ('pending', 'overdue')";
    $checkParams = [];
    if ($salesRepId === null) {
        $checkSql .= " AND ps.sales_rep_id IS NULL";
    } elseif (!empty($salesRepId)) {
        $checkSql .= " AND ps.sales_rep_id = ?";
        $checkParams[] = $salesRepId;
    }
    $checkSql .= " ORDER BY pr.reminder_date ASC LIMIT 20";
    
    $allReminders = $db->query($checkSql, $checkParams);
    error_log('All pending/overdue reminders (last 20): ' . json_encode(array_map(function($r) {
        return [
            'id' => $r['id'] ?? null,
            'reminder_date' => $r['reminder_date'] ?? null,
            'due_date' => $r['due_date'] ?? null,
            'sent_status' => $r['sent_status'] ?? null,
            'days_before_due' => $r['days_before_due'] ?? null
        ];
    }, $allReminders), JSON_UNESCAPED_UNICODE));
    
    $reminders = $db->query($sql, $params);
    
    error_log('Found reminders matching TODAY (' . date('Y-m-d') . ') count: ' . count($reminders));
    
    if (empty($reminders)) {
        error_log('INFO: No reminders to send today. Checking if there are any reminders with reminder_date = ' . date('Y-m-d'));
        
        // استعلام للتحقق من وجود تذكيرات بتاريخ اليوم ولكن بحالة مختلفة
        $checkTodaySql = "SELECT pr.id, pr.reminder_date, pr.sent_status, pr.days_before_due,
                                 ps.due_date, ps.status as schedule_status, ps.sales_rep_id
                          FROM payment_reminders pr
                          JOIN payment_schedules ps ON pr.payment_schedule_id = ps.id
                          WHERE pr.reminder_date = CURDATE()";
        $checkTodayParams = [];
        if ($salesRepId === null) {
            $checkTodaySql .= " AND ps.sales_rep_id IS NULL";
        } elseif (!empty($salesRepId)) {
            $checkTodaySql .= " AND ps.sales_rep_id = ?";
            $checkTodayParams[] = $salesRepId;
        }
        $checkTodaySql .= " LIMIT 10";
        
        $todayReminders = $db->query($checkTodaySql, $checkTodayParams);
        error_log('Reminders with reminder_date = TODAY (any status): ' . count($todayReminders));
        if (!empty($todayReminders)) {
            error_log('Today reminders details: ' . json_encode(array_map(function($r) {
                return [
                    'id' => $r['id'] ?? null,
                    'reminder_date' => $r['reminder_date'] ?? null,
                    'sent_status' => $r['sent_status'] ?? null,
                    'schedule_status' => $r['schedule_status'] ?? null,
                    'days_before_due' => $r['days_before_due'] ?? null
                ];
            }, $todayReminders), JSON_UNESCAPED_UNICODE));
        }
    }
    
    $sentCount = 0;
    
    foreach ($reminders as $reminder) {
        error_log('--- Processing Reminder ID: ' . ($reminder['id'] ?? 'N/A') . ' ---');
        error_log('Reminder Details: ' . json_encode([
            'reminder_id' => $reminder['id'] ?? null,
            'payment_schedule_id' => $reminder['payment_schedule_id'] ?? null,
            'reminder_date' => $reminder['reminder_date'] ?? null,
            'days_before_due' => $reminder['days_before_due'] ?? null,
            'sent_to' => $reminder['sent_to'] ?? null,
            'due_date' => $reminder['due_date'] ?? null,
            'schedule_status' => $reminder['schedule_status'] ?? null,
            'sales_rep_id' => $reminder['sales_rep_id'] ?? null,
            'customer_name' => $reminder['customer_name'] ?? null,
            'amount' => $reminder['amount'] ?? null
        ], JSON_UNESCAPED_UNICODE));
        
        // التحقق من أن التذكير لم يُرسل من قبل في نفس اليوم
        // هذا يضمن عدم إرسال نفس التذكير مرتين في نفس اليوم حتى لو تم استدعاء الدالة عدة مرات
        $reminderCheck = $db->queryOne(
            "SELECT sent_status, sent_at FROM payment_reminders 
             WHERE id = ? 
             LIMIT 1",
            [$reminder['id']]
        );
        
        // إذا كان التذكير قد أُرسل بالفعل في نفس اليوم، تخطيه
        if ($reminderCheck && $reminderCheck['sent_status'] === 'sent' && 
            !empty($reminderCheck['sent_at']) && 
            date('Y-m-d', strtotime($reminderCheck['sent_at'])) === date('Y-m-d')) {
            $logMessage = sprintf(
                "[PAYMENT_REMINDER_SKIPPED] Reminder ID: %d | Schedule ID: %d | Customer: %s | Reason: Already sent today at %s",
                $reminder['id'],
                $reminder['payment_schedule_id'],
                $reminder['customer_name'] ?? 'N/A',
                $reminderCheck['sent_at']
            );
            error_log($logMessage);
            continue;
        }
        
        // محاولة تحديث حالة التذكير إلى 'processing' لتجنب معالجته مرتين في نفس الوقت
        // إذا فشل التحديث، يعني أن تذكيراً آخر يعالجه حالياً أو تم إرساله بالفعل
        $updateResult = $db->execute(
            "UPDATE payment_reminders 
             SET sent_status = 'processing', sent_at = NOW() 
             WHERE id = ? 
               AND sent_status = 'pending'
               AND reminder_date = CURDATE()
             LIMIT 1",
            [$reminder['id']]
        );
        
        if (empty($updateResult) || ($updateResult['affected_rows'] ?? 0) === 0) {
            $logMessage = sprintf(
                "[PAYMENT_REMINDER_SKIPPED] Reminder ID: %d | Schedule ID: %d | Customer: %s | Reason: Being processed by another request or already sent",
                $reminder['id'],
                $reminder['payment_schedule_id'],
                $reminder['customer_name'] ?? 'N/A'
            );
            error_log($logMessage);
            continue;
        }
        
        error_log('Reminder ID ' . $reminder['id'] . ' locked for processing');
        
        $message = "تذكير: موعد تحصيل مبلغ " . formatCurrency($reminder['amount']) . 
                  " من العميل " . $reminder['customer_name'] . 
                  " في تاريخ " . formatDate($reminder['due_date']);
        
        $notificationSent = false;
        
        // إرسال للمندوب إذا كان موجوداً
        if (($reminder['sent_to'] === 'sales_rep' || $reminder['sent_to'] === 'both') && 
            !empty($reminder['sales_rep_id'])) {
            
            error_log('Sending to sales rep: ' . $reminder['sales_rep_id']);
            
            $salesRepLink = "dashboard/sales.php?page=payment_schedules&id={$reminder['payment_schedule_id']}";
            
            // التحقق من وجود إشعار في نفس اليوم لتجنب الإشعارات المكررة
            $existingNotification = $db->queryOne(
                "SELECT id FROM notifications 
                 WHERE user_id = ? 
                   AND type = 'warning'
                   AND DATE(created_at) = CURDATE()
                   AND link = ?
                 LIMIT 1",
                [$reminder['sales_rep_id'], $salesRepLink]
            );
            
            if ($existingNotification) {
                $logMessage = sprintf(
                    "[PAYMENT_REMINDER_NOTIFICATION_EXISTS] Reminder ID: %d | Sales Rep ID: %d | Schedule ID: %d | Customer: %s | Reason: Notification already exists for today",
                    $reminder['id'],
                    $reminder['sales_rep_id'],
                    $reminder['payment_schedule_id'],
                    $reminder['customer_name'] ?? 'N/A'
                );
                error_log($logMessage);
                // إذا كان هناك إشعار موجود بالفعل، نعتبر التذكير قد أُرسل
                $notificationSent = true;
            } else {
                try {
                    createNotification(
                        $reminder['sales_rep_id'],
                        'تذكير بموعد تحصيل',
                        $message,
                        'warning',
                        $salesRepLink,
                        true // إرسال Telegram
                    );
                    error_log('Notification sent to sales rep ' . $reminder['sales_rep_id']);
                    $notificationSent = true;
                } catch (Exception $e) {
                    error_log('ERROR sending notification to sales rep: ' . $e->getMessage());
                }
            }
        }
        
        // إرسال للمحاسبين والمديرين (للعملاء غير المحليين فقط)
        // ملاحظة: تم إيقاف إرسال الإشعارات الداخلية للعملاء المحليين (sales_rep_id IS NULL)
        // سيتم إرسال تقرير يومي واحد عبر Telegram بدلاً منها
        if (($reminder['sent_to'] === 'manager_accountant' || 
             $reminder['sent_to'] === 'both') &&
            !empty($reminder['sales_rep_id'])) {
            // هذا للعملاء غير المحليين فقط (يوجد sales_rep_id)
            
            error_log('Sending to managers/accountants. sent_to: ' . ($reminder['sent_to'] ?? 'null') . ', sales_rep_id: ' . ($reminder['sales_rep_id'] ?? 'null'));
            
            // جلب جميع المحاسبين والمديرين النشطين
            $managersAndAccountants = $db->query(
                "SELECT id FROM users 
                 WHERE role IN ('manager', 'accountant', 'developer') 
                 AND status = 'active'"
            );
            
            error_log('Found managers/accountants: ' . count($managersAndAccountants));
            
            // تحديد رابط الصفحة المناسب
            $link = "dashboard/accountant.php?page=company_payment_schedules&id={$reminder['payment_schedule_id']}";
            
            foreach ($managersAndAccountants as $user) {
                $userId = (int)($user['id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                
                // التحقق من وجود إشعار في نفس اليوم لتجنب الإشعارات المكررة
                $existingNotification = $db->queryOne(
                    "SELECT id FROM notifications 
                     WHERE user_id = ? 
                       AND type = 'warning'
                       AND DATE(created_at) = CURDATE()
                       AND link = ?
                     LIMIT 1",
                    [$userId, $link]
                );
                
                if ($existingNotification) {
                    $logMessage = sprintf(
                        "[PAYMENT_REMINDER_NOTIFICATION_EXISTS] Reminder ID: %d | User ID: %d | Schedule ID: %d | Customer: %s | Reason: Notification already exists for today",
                        $reminder['id'],
                        $userId,
                        $reminder['payment_schedule_id'],
                        $reminder['customer_name'] ?? 'N/A'
                    );
                    error_log($logMessage);
                    // إذا كان هناك إشعار موجود بالفعل، نعتبر التذكير قد أُرسل
                    $notificationSent = true;
                } else {
                    try {
                        createNotification(
                            $userId,
                            'تذكير بموعد تحصيل',
                            $message,
                            'warning',
                            $link,
                            true // إرسال Telegram
                        );
                        error_log('Notification sent to user ' . $userId);
                        $notificationSent = true;
                    } catch (Exception $e) {
                        error_log('ERROR sending notification to user ' . $userId . ': ' . $e->getMessage());
                    }
                }
            }
        } elseif (empty($reminder['sales_rep_id'])) {
            // للعملاء المحليين: تخطي إرسال الإشعارات الداخلية
            // سيتم إرسال تقرير يومي واحد عبر Telegram بدلاً منها
            error_log('Skipping internal notifications for local customer (schedule ID: ' . $reminder['payment_schedule_id'] . ') - Daily Telegram report will be sent instead');
            // نعتبر التذكير قد أُرسل لتحديث الحالة
            $notificationSent = true;
        }
        
        // تحديث حالة التذكير فقط إذا تم إرسال إشعار
        if ($notificationSent) {
            try {
                $db->execute(
                    "UPDATE payment_reminders 
                     SET sent_status = 'sent', sent_at = NOW() 
                     WHERE id = ?",
                    [$reminder['id']]
                );
                
                // تسجيل مفصل في logs
                $logMessage = sprintf(
                    "[PAYMENT_REMINDER_SENT] Reminder ID: %d | Schedule ID: %d | Customer: %s | Amount: %s | Due Date: %s | Reminder Date: %s | Sent At: %s",
                    $reminder['id'],
                    $reminder['payment_schedule_id'],
                    $reminder['customer_name'] ?? 'N/A',
                    formatCurrency($reminder['amount'] ?? 0),
                    $reminder['due_date'] ?? 'N/A',
                    $reminder['reminder_date'] ?? 'N/A',
                    date('Y-m-d H:i:s')
                );
                error_log($logMessage);
                
                // تحديث حالة الجدول الزمني
                $db->execute(
                    "UPDATE payment_schedules 
                     SET reminder_sent = 1, reminder_sent_at = NOW() 
                     WHERE id = ?",
                    [$reminder['payment_schedule_id']]
                );
                
                $sentCount++;
            } catch (Exception $e) {
                error_log('ERROR updating reminder status: ' . $e->getMessage());
            }
        } else {
            // إذا لم يتم إرسال إشعار، إعادة حالة التذكير إلى 'pending' للسماح بإعادة المحاولة لاحقاً
            // لكن فقط إذا لم يكن هناك إشعار موجود بالفعل (لأن الفحص السابق قد منع الإرسال)
            try {
                $db->execute(
                    "UPDATE payment_reminders 
                     SET sent_status = 'pending', sent_at = NULL 
                     WHERE id = ? 
                       AND sent_status = 'processing'",
                    [$reminder['id']]
                );
                error_log('Reminder status reset to pending for reminder ID: ' . $reminder['id'] . ' (no notification sent)');
            } catch (Exception $e) {
                error_log('ERROR resetting reminder status: ' . $e->getMessage());
            }
            error_log('WARNING: No notification was sent for reminder ID: ' . ($reminder['id'] ?? 'N/A') . ' - status reset to pending');
        }
    }
    
    error_log('=== sendPaymentReminders END - Total sent: ' . $sentCount . ' ===');
    
    return $sentCount;
}

/**
 * الحصول على جداول التحصيل المستحقة اليوم لمندوب معين
 */
function getTodayDuePaymentSchedules($salesRepId) {
    if (!$salesRepId) {
        return [];
    }

    try {
        $db = db();
        return $db->query(
            "SELECT ps.id, ps.amount, ps.due_date, ps.status, ps.customer_id,
                    c.name AS customer_name
             FROM payment_schedules ps
             LEFT JOIN customers c ON ps.customer_id = c.id
             WHERE ps.sales_rep_id = ?
               AND ps.status IN ('pending', 'overdue')
               AND ps.due_date <= CURDATE()
             ORDER BY ps.due_date ASC",
            [$salesRepId]
        );
    } catch (Exception $e) {
        error_log('Today due schedules fetch error: ' . $e->getMessage());
        return [];
    }
}

/**
 * إرسال إشعارات بالتحصيلات المستحقة اليوم لمندوب المبيعات
 */
function notifyTodayPaymentSchedules($salesRepId) {
    if (!$salesRepId) {
        return;
    }

    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    } catch (Throwable $sessionError) {
        // تجاهل أخطاء الجلسة بصمت ولكن الاستمرار بإرسال الإشعارات
    }

    $db = db();
    $schedules = getTodayDuePaymentSchedules($salesRepId);
    if (empty($schedules)) {
        return;
    }

    $notifiedCustomers = [];

    $todayDate = new DateTimeImmutable('today');

    foreach ($schedules as $schedule) {
        $scheduleId = (int) ($schedule['id'] ?? 0);
        if ($scheduleId <= 0) {
            continue;
        }

        $customerId = (int) ($schedule['customer_id'] ?? 0);
        if ($customerId > 0 && isset($notifiedCustomers[$customerId])) {
            continue;
        }

        $link = $customerId > 0
            ? getRelativeUrl('dashboard/sales.php?page=payment_schedules&customer_id=' . $customerId)
            : getRelativeUrl('dashboard/sales.php?page=payment_schedules&id=' . $scheduleId);

        $scheduleDueDate = null;
        $notificationType = 'payment_due';
        $isOverdue = false;

        try {
            if (!empty($schedule['due_date'])) {
                $scheduleDueDate = new DateTimeImmutable($schedule['due_date']);
                $isOverdue = $scheduleDueDate < $todayDate;
                if ($isOverdue) {
                    $notificationType = 'payment_overdue';
                }
            }
        } catch (Exception $dateParseError) {
            error_log('Invalid payment schedule due date: ' . $dateParseError->getMessage());
        }

        try {
            // التحقق من وجود إشعار غير مقروء وغير محذوف من نفس النوع والرابط في نفس اليوم
            // نتحقق من وجود إشعار غير مقروء فقط (إذا تم حذفه، فلن يوجد في النتائج)
            $existing = $db->queryOne(
                "SELECT id FROM notifications 
                 WHERE user_id = ? 
                   AND type = ? 
                   AND DATE(created_at) = CURDATE()
                   AND link = ?
                   AND (`read` = 0 OR `read` IS NULL)
                 LIMIT 1",
                [$salesRepId, $notificationType, $link]
            );

            if ($existing) {
                if ($customerId > 0) {
                    $notifiedCustomers[$customerId] = true;
                }
                continue;
            }
        } catch (Exception $checkError) {
            error_log('Payment due notification check error: ' . $checkError->getMessage());
        }

        $customerName = $schedule['customer_name'] ?? 'عميل';
        $amount = (float) ($schedule['amount'] ?? 0);
        $dueDateString = $scheduleDueDate ? $scheduleDueDate->format('Y-m-d') : ($schedule['due_date'] ?? date('Y-m-d'));

        if ($isOverdue && $scheduleDueDate instanceof DateTimeImmutable) {
            $daysOverdue = max(1, (int) $scheduleDueDate->diff($todayDate)->format('%a'));
            $title = 'تنبيه تحصيل متأخر';
            $message = sprintf(
                'مر %s يوم على موعد تحصيل مبلغ %s من العميل %s (الموعد في %s). يرجى المتابعة فوراً.',
                $daysOverdue,
                formatCurrency($amount),
                $customerName,
                formatDate($dueDateString)
            );
        } else {
            $title = 'تذكير بالتحصيل اليوم';
            $message = sprintf(
                'موعد تحصيل مبلغ %s من العميل %s اليوم (%s).',
                formatCurrency($amount),
                $customerName,
                formatDate($dueDateString)
            );
        }

        $notificationTypeToSend = $notificationType;
        createNotification(
            $salesRepId,
            $title,
            $message,
            $notificationTypeToSend,
            $link,
            false
        );

        if ($customerId > 0) {
            $notifiedCustomers[$customerId] = true;
        }
    }
}

/**
 * إرسال إشعارات للمواعيد المتأخرة للمحاسبين والمديرين (للعملاء المحليين)
 * ملاحظة: تم تعطيل إرسال الإشعارات الداخلية - سيتم إرسال تقرير يومي واحد عبر Telegram بدلاً منها
 */
function notifyOverduePaymentSchedulesForManagers() {
    // تم تعطيل إرسال الإشعارات الداخلية للعملاء المحليين
    // سيتم إرسال تقرير يومي واحد عبر Telegram بدلاً منها
    error_log('notifyOverduePaymentSchedulesForManagers: Internal notifications disabled - Daily Telegram report will be sent instead');
    return 0;
    
    /* الكود القديم - معطل
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    } catch (Throwable $sessionError) {
        // تجاهل أخطاء الجلسة
    }

    $db = db();
    
    // جلب جميع المواعيد المتأخرة للعملاء المحليين (sales_rep_id IS NULL)
    $schedules = $db->query(
        "SELECT ps.id, ps.amount, ps.due_date, ps.status, ps.customer_id,
                lc.name AS customer_name
         FROM payment_schedules ps
         INNER JOIN local_customers lc ON ps.customer_id = lc.id
         WHERE ps.sales_rep_id IS NULL
           AND lc.status = 'active'
           AND ps.status IN ('pending', 'overdue')
           AND ps.due_date < CURDATE()
         ORDER BY ps.due_date ASC"
    );
    
    if (empty($schedules)) {
        return 0;
    }

    // جلب جميع المحاسبين والمديرين النشطين
    $managersAndAccountants = $db->query(
        "SELECT id FROM users 
         WHERE role IN ('manager', 'accountant', 'developer') 
         AND status = 'active'"
    );
    
    if (empty($managersAndAccountants)) {
        return 0;
    }

    $notifiedCount = 0;
    $notifiedSchedules = [];
    $todayDate = new DateTimeImmutable('today');
    $todayString = $todayDate->format('Y-m-d');

    foreach ($schedules as $schedule) {
        $scheduleId = (int) ($schedule['id'] ?? 0);
        if ($scheduleId <= 0) {
            continue;
        }

        // تجنب إرسال إشعارات مكررة لنفس الجدول في نفس اليوم
        if (isset($notifiedSchedules[$scheduleId])) {
            continue;
        }

        // التحقق من أن إشعار متأخر لم يُرسل لهذا الجدول في نفس اليوم
        // نستخدم reminder_sent_at للتحقق من آخر مرة تم إرسال إشعار فيها
        $scheduleCheck = $db->queryOne(
            "SELECT reminder_sent_at FROM payment_schedules 
             WHERE id = ? 
             LIMIT 1",
            [$scheduleId]
        );
        
        // إذا تم إرسال إشعار في نفس اليوم، تخطي هذا الجدول
        if ($scheduleCheck && !empty($scheduleCheck['reminder_sent_at'])) {
            $reminderSentDate = date('Y-m-d', strtotime($scheduleCheck['reminder_sent_at']));
            if ($reminderSentDate === $todayString) {
                $logMessage = sprintf(
                    "[PAYMENT_OVERDUE_NOTIFICATION_SKIPPED] Schedule ID: %d | Customer: %s | Reason: Overdue notification already sent today (reminder_sent_at: %s)",
                    $scheduleId,
                    $schedule['customer_name'] ?? 'N/A',
                    $scheduleCheck['reminder_sent_at']
                );
                error_log($logMessage);
                continue;
            }
        }

        $link = getRelativeUrl('dashboard/accountant.php?page=company_payment_schedules&id=' . $scheduleId);

        $scheduleDueDate = null;
        $isOverdue = false;

        try {
            if (!empty($schedule['due_date'])) {
                $scheduleDueDate = new DateTimeImmutable($schedule['due_date']);
                $isOverdue = $scheduleDueDate < $todayDate;
            }
        } catch (Exception $dateParseError) {
            error_log('Invalid payment schedule due date: ' . $dateParseError->getMessage());
            continue;
        }

        if (!$isOverdue) {
            continue;
        }

        $customerName = $schedule['customer_name'] ?? 'عميل';
        $amount = (float) ($schedule['amount'] ?? 0);
        $dueDateString = $scheduleDueDate ? $scheduleDueDate->format('Y-m-d') : ($schedule['due_date'] ?? date('Y-m-d'));
        $daysOverdue = max(1, (int) $scheduleDueDate->diff($todayDate)->format('%a'));

        $title = 'تنبيه تحصيل متأخر';
        $message = sprintf(
            'مر %s يوم على موعد تحصيل مبلغ %s من العميل المحلي %s (الموعد في %s). يرجى المتابعة فوراً.',
            $daysOverdue,
            formatCurrency($amount),
            $customerName,
            formatDate($dueDateString)
        );

        // إرسال إشعار لكل محاسب ومدير
        foreach ($managersAndAccountants as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            try {
                // التحقق من وجود إشعار من نفس النوع والرابط في نفس اليوم (مقروء أو غير مقروء أو محذوف)
                // لإرسال الإشعار مرة واحدة فقط في اليوم
                // نتحقق من أي إشعار في نفس اليوم بنفس الرابط والنوع، بغض النظر عن حالة القراءة
                $existing = $db->queryOne(
                    "SELECT id FROM notifications 
                     WHERE user_id = ? 
                       AND type = 'payment_overdue'
                       AND DATE(created_at) = CURDATE()
                       AND link = ?
                     LIMIT 1",
                    [$userId, $link]
                );

                if ($existing) {
                    $logMessage = sprintf(
                        "[PAYMENT_OVERDUE_NOTIFICATION_SKIPPED] User ID: %d | Schedule ID: %d | Customer: %s | Reason: Notification already exists for today",
                        $userId,
                        $scheduleId,
                        $customerName
                    );
                    error_log($logMessage);
                    continue;
                }

                createNotification(
                    $userId,
                    $title,
                    $message,
                    'payment_overdue',
                    $link,
                    true // إرسال Telegram
                );
                
                // تحديث reminder_sent_at في payment_schedules لتتبع إرسال إشعار متأخر
                // هذا يضمن عدم إرسال إشعار متأخر مرة أخرى في نفس اليوم حتى لو تم حذف الإشعار
                try {
                    $db->execute(
                        "UPDATE payment_schedules 
                         SET reminder_sent_at = NOW() 
                         WHERE id = ?",
                        [$scheduleId]
                    );
                } catch (Exception $updateError) {
                    error_log('Error updating reminder_sent_at for schedule ' . $scheduleId . ': ' . $updateError->getMessage());
                }
                
                $logMessage = sprintf(
                    "[PAYMENT_OVERDUE_NOTIFICATION_SENT] User ID: %d | Schedule ID: %d | Customer: %s | Amount: %s | Days Overdue: %d",
                    $userId,
                    $scheduleId,
                    $customerName,
                    formatCurrency($amount),
                    $daysOverdue
                );
                error_log($logMessage);
                
                $notifiedCount++;
            } catch (Exception $notifError) {
                error_log('Error sending overdue notification to user ' . $userId . ': ' . $notifError->getMessage());
            }
        }

        $notifiedSchedules[$scheduleId] = true;
    }

    return $notifiedCount;
    */ // نهاية الكود القديم المعطل
}

/**
 * تحديث حالة الجداول المتأخرة
 */
function updateOverdueSchedules() {
    $db = db();
    
    $updated = $db->execute(
        "UPDATE payment_schedules 
         SET status = 'overdue' 
         WHERE status = 'pending' 
         AND due_date < CURDATE()"
    );
    
    return $updated['affected_rows'] ?? 0;
}

/**
 * إرسال تقرير يومي واحد عبر Telegram عن جداول التحصيل للعملاء المحليين
 * يتم إرسال الرسالة مرة واحدة فقط يومياً
 */
function sendDailyLocalPaymentSchedulesTelegramReport() {
    require_once __DIR__ . '/simple_telegram.php';
    
    if (!isTelegramConfigured()) {
        error_log('Daily Payment Schedules Report: Telegram not configured');
        return false;
    }
    
    $db = db();
    $jobKey = 'daily_local_payment_schedules_report';
    $today = date('Y-m-d');
    
    // التأكد من وجود جدول system_daily_jobs
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `system_daily_jobs` (
              `job_key` varchar(120) NOT NULL,
              `last_sent_at` datetime DEFAULT NULL,
              `last_file_path` varchar(512) DEFAULT NULL,
              `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`job_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('Daily Payment Schedules Report: Failed to create system_daily_jobs table - ' . $e->getMessage());
    }
    
    // التحقق من إرسال الرسالة اليوم
    $lastSent = $db->queryOne(
        "SELECT last_sent_at FROM system_daily_jobs WHERE job_key = ?",
        [$jobKey]
    );
    
    if ($lastSent && !empty($lastSent['last_sent_at'])) {
        $lastSentDate = date('Y-m-d', strtotime($lastSent['last_sent_at']));
        if ($lastSentDate === $today) {
            error_log('Daily Payment Schedules Report: Already sent today at ' . $lastSent['last_sent_at']);
            return false;
        }
    }
    
    // جلب إحصائيات الجداول للعملاء المحليين
    $stats = $db->queryOne("
        SELECT 
            COUNT(CASE WHEN ps.status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN ps.status = 'overdue' THEN 1 END) as overdue_count,
            COALESCE(SUM(CASE WHEN ps.status = 'pending' THEN ps.amount END), 0) as pending_amount,
            COALESCE(SUM(CASE WHEN ps.status = 'overdue' THEN ps.amount END), 0) as overdue_amount,
            COUNT(*) as total_count
        FROM payment_schedules ps
        INNER JOIN local_customers lc ON ps.customer_id = lc.id
        WHERE lc.status = 'active' 
          AND ps.sales_rep_id IS NULL
          AND ps.status IN ('pending', 'overdue')
    ");
    
    if (!$stats || ($stats['total_count'] ?? 0) == 0) {
        error_log('Daily Payment Schedules Report: No pending or overdue schedules found');
        // تحديث last_sent_at حتى لو لم يكن هناك جداول
        $db->execute(
            "INSERT INTO system_daily_jobs (job_key, last_sent_at) 
             VALUES (?, NOW()) 
             ON DUPLICATE KEY UPDATE last_sent_at = NOW()",
            [$jobKey]
        );
        return false;
    }
    
    $pendingCount = (int)($stats['pending_count'] ?? 0);
    $overdueCount = (int)($stats['overdue_count'] ?? 0);
    $pendingAmount = (float)($stats['pending_amount'] ?? 0);
    $overdueAmount = (float)($stats['overdue_amount'] ?? 0);
    $totalCount = (int)($stats['total_count'] ?? 0);
    
    // جلب أفضل 10 جداول متأخرة
    $topOverdue = $db->query("
        SELECT ps.id, ps.amount, ps.due_date, lc.name as customer_name,
               DATEDIFF(CURDATE(), ps.due_date) as days_overdue
        FROM payment_schedules ps
        INNER JOIN local_customers lc ON ps.customer_id = lc.id
        WHERE lc.status = 'active' 
          AND ps.sales_rep_id IS NULL
          AND ps.status = 'overdue'
        ORDER BY ps.due_date ASC
        LIMIT 10
    ");
    
    // بناء الرسالة
    $message = "📊 <b>تقرير يومي - جداول التحصيل (العملاء المحليين)</b>\n\n";
    $message .= "📅 التاريخ: " . formatDate($today) . "\n\n";
    
    $message .= "📈 <b>الإحصائيات:</b>\n";
    $message .= "• إجمالي الجداول المعلقة/المتأخرة: <b>" . number_format($totalCount) . "</b>\n";
    $message .= "• معلقة: <b>" . number_format($pendingCount) . "</b> جدول (" . formatCurrency($pendingAmount) . ")\n";
    $message .= "• متأخرة: <b>" . number_format($overdueCount) . "</b> جدول (" . formatCurrency($overdueAmount) . ")\n\n";
    
    if (!empty($topOverdue)) {
        $message .= "⚠️ <b>أهم الجداول المتأخرة:</b>\n";
        $counter = 1;
        foreach ($topOverdue as $schedule) {
            $daysOverdue = (int)($schedule['days_overdue'] ?? 0);
            $message .= $counter . ". " . htmlspecialchars($schedule['customer_name']) . 
                       " - " . formatCurrency($schedule['amount']) . 
                       " (متأخر " . $daysOverdue . " يوم)\n";
            $counter++;
            if ($counter > 10) break;
        }
        $message .= "\n";
    }
    
    $message .= "🔗 للتفاصيل: افتح صفحة جداول التحصيل - العملاء المحليين\n";
    
    // إرسال الرسالة
    $result = sendTelegramMessage($message);
    
    if ($result) {
        // تحديث last_sent_at
        $db->execute(
            "INSERT INTO system_daily_jobs (job_key, last_sent_at) 
             VALUES (?, NOW()) 
             ON DUPLICATE KEY UPDATE last_sent_at = NOW()",
            [$jobKey]
        );
        error_log('Daily Payment Schedules Report: Telegram message sent successfully');
        return true;
    } else {
        error_log('Daily Payment Schedules Report: Failed to send Telegram message');
        return false;
    }
}

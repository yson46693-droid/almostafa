<?php
/**
 * Cron Job لإرسال تذكيرات التحصيل التلقائية
 * يجب تشغيله يومياً (يفضل كل ساعة)
 * 
 * هذا الـ cron job يرسل التذكيرات للجميع:
 * - مندوبي المبيعات (sales reps)
 * - العملاء المحليين (local customers)
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/payment_schedules.php';
require_once __DIR__ . '/../includes/notifications.php';

$startTime = microtime(true);
$logPrefix = '[CRON_PAYMENT_REMINDERS]';

error_log("{$logPrefix} ====== START ====== " . date('Y-m-d H:i:s'));

// تحديث الحالات المتأخرة
error_log("{$logPrefix} Updating overdue schedules...");
$updatedOverdue = updateOverdueSchedules();
error_log("{$logPrefix} Updated {$updatedOverdue} overdue schedules");

// إرسال التذكيرات للعملاء المحليين (sales_rep_id IS NULL)
error_log("{$logPrefix} Sending reminders for LOCAL customers (sales_rep_id IS NULL)...");
$sentCountLocal = sendPaymentReminders(null);
error_log("{$logPrefix} Sent {$sentCountLocal} reminders for local customers");

// إرسال التذكيرات لكل مندوب مبيعات
error_log("{$logPrefix} Sending reminders for SALES REPS...");
$db = db();
$salesReps = $db->query(
    "SELECT DISTINCT ps.sales_rep_id 
     FROM payment_schedules ps
     INNER JOIN payment_reminders pr ON pr.payment_schedule_id = ps.id
     WHERE ps.sales_rep_id IS NOT NULL
       AND pr.sent_status = 'pending'
       AND pr.reminder_date = CURDATE()
       AND ps.status IN ('pending', 'overdue')
     GROUP BY ps.sales_rep_id"
);

$sentCountSalesReps = 0;
foreach ($salesReps as $rep) {
    $salesRepId = (int)($rep['sales_rep_id'] ?? 0);
    if ($salesRepId > 0) {
        error_log("{$logPrefix} Sending reminders for sales rep ID: {$salesRepId}");
        $count = sendPaymentReminders($salesRepId);
        $sentCountSalesReps += $count;
        error_log("{$logPrefix} Sent {$count} reminders for sales rep ID: {$salesRepId}");
    }
}

$totalSent = $sentCountLocal + $sentCountSalesReps;
error_log("{$logPrefix} Total reminders sent: {$totalSent} (Local: {$sentCountLocal}, Sales Reps: {$sentCountSalesReps})");

// إرسال تذكيرات يومية مباشرة للجداول المستحقة اليوم والمتأخرة للعملاء المحليين
error_log("{$logPrefix} Sending daily payment reminders for local customers (due today and overdue)...");
$dailyRemindersSent = sendDailyLocalPaymentSchedulesReminders();
error_log("{$logPrefix} Daily payment reminders sent: {$dailyRemindersSent}");

// إرسال تقرير يومي واحد عبر Telegram للعملاء المحليين (بدلاً من الإشعارات الداخلية)
error_log("{$logPrefix} Sending daily Telegram report for local customers...");
$telegramReportSent = sendDailyLocalPaymentSchedulesTelegramReport();
error_log("{$logPrefix} Daily Telegram report sent: " . ($telegramReportSent ? 'Yes' : 'No (already sent today or no data)'));

// إنشاء تذكيرات تلقائية للجداول القادمة (3 أيام قبل الاستحقاق)
error_log("{$logPrefix} Creating auto reminders for upcoming schedules (3 days before due date)...");
$pendingSchedules = $db->query(
    "SELECT id FROM payment_schedules 
     WHERE status = 'pending' 
     AND due_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)
     AND reminder_sent = 0"
);

$createdCount = 0;
foreach ($pendingSchedules as $schedule) {
    if (createAutoReminder($schedule['id'], 3)) {
        $createdCount++;
    }
}
error_log("{$logPrefix} Created {$createdCount} new auto reminders");

$endTime = microtime(true);
$executionTime = round($endTime - $startTime, 2);

error_log("{$logPrefix} ====== END ====== Execution time: {$executionTime}s | Total sent: {$totalSent} | Daily reminders: {$dailyRemindersSent} | Telegram report: " . ($telegramReportSent ? 'Sent' : 'Not sent') . " | Created: {$createdCount}");

echo "تم إرسال {$totalSent} تذكير (محلي: {$sentCountLocal}, مندوبين: {$sentCountSalesReps}) و {$dailyRemindersSent} تذكير يومي مباشر وإرسال تقرير Telegram يومي وإنشاء {$createdCount} تذكير جديد\n";


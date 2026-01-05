<?php
/**
 * ملف اختبار لإرسال التذكيرات اليومية
 * يمكن تشغيله يدوياً للتحقق من عمل الدالة
 * 
 * الاستخدام:
 * php test_daily_reminders.php
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/payment_schedules.php';

echo "=== اختبار إرسال التذكيرات اليومية ===\n";
echo "التاريخ: " . date('Y-m-d H:i:s') . "\n\n";

// تحديث الحالات المتأخرة
echo "1. تحديث الحالات المتأخرة...\n";
$updated = updateOverdueSchedules();
echo "   تم تحديث {$updated} جدول متأخر\n\n";

// فحص الجداول المستحقة والمتأخرة
$db = db();
$totalCheck = $db->queryOne("
    SELECT COUNT(*) as total
    FROM payment_schedules ps
    INNER JOIN local_customers lc ON ps.customer_id = lc.id
    WHERE lc.status = 'active' 
      AND ps.sales_rep_id IS NULL
      AND ps.status IN ('pending', 'overdue')
      AND ps.due_date <= CURDATE()
");
$totalSchedules = (int)($totalCheck['total'] ?? 0);
echo "2. عدد الجداول المستحقة اليوم أو المتأخرة: {$totalSchedules}\n\n";

// فحص الجداول التي لم يتم إرسال تذكير لها اليوم
$notSentToday = $db->queryOne("
    SELECT COUNT(*) as total
    FROM payment_schedules ps
    INNER JOIN local_customers lc ON ps.customer_id = lc.id
    WHERE lc.status = 'active' 
      AND ps.sales_rep_id IS NULL
      AND ps.status IN ('pending', 'overdue')
      AND ps.due_date <= CURDATE()
      AND (
          ps.reminder_sent_at IS NULL OR
          DATE(ps.reminder_sent_at) != CURDATE()
      )
");
$notSentCount = (int)($notSentToday['total'] ?? 0);
echo "3. عدد الجداول التي لم يتم إرسال تذكير لها اليوم: {$notSentCount}\n\n";

// عرض بعض الجداول كمثال
$sampleSchedules = $db->query("
    SELECT ps.id, ps.amount, ps.due_date, ps.status, ps.reminder_sent_at,
           lc.name AS customer_name,
           DATEDIFF(CURDATE(), ps.due_date) as days_diff
    FROM payment_schedules ps
    INNER JOIN local_customers lc ON ps.customer_id = lc.id
    WHERE lc.status = 'active' 
      AND ps.sales_rep_id IS NULL
      AND ps.status IN ('pending', 'overdue')
      AND ps.due_date <= CURDATE()
    ORDER BY ps.due_date ASC
    LIMIT 5
");
echo "4. أمثلة على الجداول (أول 5):\n";
if (empty($sampleSchedules)) {
    echo "   لا توجد جداول مستحقة أو متأخرة\n";
} else {
    foreach ($sampleSchedules as $schedule) {
        $daysDiff = (int)($schedule['days_diff'] ?? 0);
        $status = $schedule['status'] ?? 'pending';
        $lastReminder = $schedule['reminder_sent_at'] ?? 'لم يتم إرسال تذكير';
        echo "   - ID: {$schedule['id']} | العميل: {$schedule['customer_name']} | المبلغ: " . formatCurrency($schedule['amount']) . " | الاستحقاق: {$schedule['due_date']} | متأخر: {$daysDiff} يوم | الحالة: {$status} | آخر تذكير: {$lastReminder}\n";
    }
}
echo "\n";

// التحقق من إعداد Telegram
echo "5. التحقق من إعداد Telegram...\n";
if (function_exists('isTelegramConfigured') && isTelegramConfigured()) {
    echo "   ✓ Telegram Bot مُعد بشكل صحيح\n\n";
} else {
    echo "   ✗ Telegram Bot غير مُعد - لن يتم إرسال التذكيرات\n\n";
}

// استدعاء الدالة
echo "6. استدعاء دالة إرسال التذكيرات...\n";
$sentCount = sendDailyLocalPaymentSchedulesReminders();
echo "   تم إرسال {$sentCount} تذكير\n\n";

echo "=== انتهى الاختبار ===\n";
echo "\nملاحظة: راجع error_log لمزيد من التفاصيل\n";

# إعداد التذكيرات اليومية للجداول الزمنية

## نظرة عامة

يتم إرسال التذكيرات اليومية للجداول المستحقة والمتأخرة عبر:
1. **Cron Job** (الطريقة المفضلة) - يعمل تلقائياً كل يوم
2. **فحص تلقائي عند فتح الصفحة** - كنسخة احتياطية إذا لم يعمل cron job

## إعداد Cron Job

### على Windows (Task Scheduler)

1. افتح **Task Scheduler** (جدولة المهام)
2. أنشئ مهمة جديدة (Create Basic Task)
3. حدد الاسم: `Payment Reminders Daily`
4. حدد التكرار: **Daily** (يومياً)
5. حدد الوقت: **8:00 AM** (أو أي وقت تفضله)
6. حدد الإجراء: **Start a program**
7. في **Program/script**: أدخل مسار PHP
   ```
   C:\php\php.exe
   ```
8. في **Add arguments**: أدخل مسار الملف
   ```
   C:\path\to\albarakah\cron\payment_reminders.php
   ```
9. في **Start in**: أدخل مجلد المشروع
   ```
   C:\path\to\albarakah
   ```

### على Linux/Unix (Crontab)

1. افتح crontab:
   ```bash
   crontab -e
   ```

2. أضف السطر التالي (يعمل كل يوم الساعة 8 صباحاً):
   ```cron
   0 8 * * * /usr/bin/php /path/to/albarakah/cron/payment_reminders.php >> /path/to/albarakah/logs/cron.log 2>&1
   ```

3. لحفظ الملف: اضغط `Ctrl+X` ثم `Y` ثم `Enter`

### على Linux/Unix (كل ساعة - موصى به)

```cron
0 * * * * /usr/bin/php /path/to/albarakah/cron/payment_reminders.php >> /path/to/albarakah/logs/cron.log 2>&1
```

## آلية النسخ الاحتياطي

إذا لم يعمل cron job، سيتم فحص التذكيرات تلقائياً عند فتح صفحة **جداول التحصيل - العملاء المحليين** (`company_payment_schedules`).

- يتم الفحص مرة واحدة فقط في اليوم
- يتم تسجيل العملية في error_log

## التحقق من عمل النظام

### 1. فحص السجلات (Error Log)

ابحث عن:
- `[CRON_PAYMENT_REMINDERS]` - سجلات cron job
- `[DAILY_PAYMENT_REMINDER_SENT]` - تذكيرات تم إرسالها
- `Daily Payment Schedules Reminders` - تفاصيل العملية

### 2. تشغيل ملف الاختبار

```bash
php test_daily_reminders.php
```

### 3. فحص قاعدة البيانات

```sql
-- فحص الجداول المستحقة والمتأخرة
SELECT COUNT(*) as total
FROM payment_schedules ps
INNER JOIN local_customers lc ON ps.customer_id = lc.id
WHERE lc.status = 'active' 
  AND ps.sales_rep_id IS NULL
  AND ps.status IN ('pending', 'overdue')
  AND ps.due_date <= CURDATE();

-- فحص آخر مرة تم إرسال تذكير
SELECT job_key, last_sent_at 
FROM system_daily_jobs 
WHERE job_key = 'daily_local_payment_reminders_check';
```

## استكشاف الأخطاء

### المشكلة: لا يتم إرسال التذكيرات

1. **تحقق من إعداد Telegram Bot**
   - تأكد من أن `TELEGRAM_BOT_TOKEN` و `TELEGRAM_CHAT_ID` موجودة في `config.php`

2. **تحقق من cron job**
   - تأكد من أن cron job يعمل بشكل صحيح
   - راجع سجلات cron job

3. **تحقق من الجداول**
   - تأكد من وجود جداول مستحقة أو متأخرة
   - تأكد من أن `reminder_sent_at` ليس اليوم

4. **تحقق من السجلات**
   - راجع error_log للبحث عن أخطاء

### المشكلة: يتم إرسال تذكيرات مكررة

- النظام مصمم لتجنب الإرسال المكرر في نفس اليوم
- إذا حدث ذلك، تحقق من:
  - وجود عدة cron jobs تعمل في نفس الوقت
  - مشكلة في قاعدة البيانات (reminder_sent_at)

## ملاحظات مهمة

1. **التذكيرات اليومية**: يتم إرسال تذكير واحد فقط لكل جدول في اليوم
2. **الجداول المستحقة**: الجداول التي `due_date = CURDATE()`
3. **الجداول المتأخرة**: الجداول التي `due_date < CURDATE()` و `status = 'overdue'`
4. **العملاء المحليين فقط**: النظام يرسل تذكيرات للعملاء المحليين فقط (`sales_rep_id IS NULL`)

## الملفات ذات الصلة

- `cron/payment_reminders.php` - ملف cron job الرئيسي
- `includes/payment_schedules.php` - الدوال المساعدة
- `modules/manager/company_payment_schedules.php` - صفحة إدارة الجداول
- `test_daily_reminders.php` - ملف اختبار

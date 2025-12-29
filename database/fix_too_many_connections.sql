-- حل مشكلة "Too many connections" في MySQL
-- قم بتشغيل هذا الملف في MySQL CLI أو phpMyAdmin

-- 1. عرض الحد الحالي للاتصالات
SHOW VARIABLES LIKE 'max_connections';

-- 2. زيادة حد الاتصالات إلى 500 (يمكن تعديل القيمة حسب الحاجة)
SET GLOBAL max_connections = 500;

-- 3. عرض الاتصالات الحالية النشطة
SHOW PROCESSLIST;

-- 4. قتل الاتصالات المعلقة القديمة (اختياري - احذر: سيقطع الاتصالات النشطة)
-- قم بتشغيل هذا الاستعلام فقط إذا كنت متأكداً من إغلاق الاتصالات المعلقة
-- KILL <process_id>; -- استبدل <process_id> برقم العملية من SHOW PROCESSLIST

-- 5. عرض الإحصائيات عن الاتصالات
SHOW STATUS LIKE 'Threads_connected';
SHOW STATUS LIKE 'Threads_running';
SHOW STATUS LIKE 'Max_used_connections';

-- ملاحظة: إذا كنت تستخدم XAMPP أو WAMP، يمكنك تعديل ملف my.ini أو my.cnf:
-- [mysqld]
-- max_connections = 500
-- wait_timeout = 300
-- interactive_timeout = 300
--
-- ثم قم بإعادة تشغيل MySQL


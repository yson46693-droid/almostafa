# إصلاح مشكلة "Too many connections" في MySQL

## المشكلة
يظهر الخطأ `MySQL connection error: Too many connections` عندما يصل عدد الاتصالات المتزامنة مع قاعدة البيانات إلى الحد الأقصى المسموح به في MySQL.

## الحلول المطبقة

### 1. تحسين استخدام الاتصالات في `local_customers.php`
- تم استبدال جميع استخدامات `getConnection()->query()` بـ `rawQuery()` method
- إضافة methods جديدة في `Database` class:
  - `getLastError()`: للحصول على آخر رسالة خطأ
  - `getLastErrno()`: للحصول على رقم آخر خطأ
- هذا يضمن استخدام اتصال واحد مشترك (Singleton pattern) بدلاً من محاولات إنشاء اتصالات إضافية

### 2. استخدام Singleton Pattern
النظام يستخدم Singleton pattern في `Database` class لضمان وجود اتصال واحد فقط لكل طلب PHP.

## الحلول الإضافية الموصى بها

### حل 1: زيادة حد الاتصالات في MySQL (موصى به للمشاريع الكبيرة)

قم بتعديل إعدادات MySQL:

```sql
-- عرض الحد الحالي
SHOW VARIABLES LIKE 'max_connections';

-- زيادة الحد (مثال: 200)
SET GLOBAL max_connections = 200;
```

أو في ملف `my.ini` (لـ XAMPP/WAMP):
```ini
[mysqld]
max_connections = 200
```

### حل 2: إغلاق الاتصالات غير المستخدمة

قم بتنفيذ الاستعلام التالي لرؤية الاتصالات الحالية:
```sql
SHOW PROCESSLIST;
```

لإغلاق اتصال معين:
```sql
KILL [connection_id];
```

### حل 3: فحص وإغلاق الاتصالات المعلقة

إنشاء script PHP لتنظيف الاتصالات:

```php
<?php
// cleanup_connections.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$db = db();
$connections = $db->query("SHOW PROCESSLIST");

foreach ($connections as $conn) {
    // إغلاق الاتصالات التي تستغرق وقتاً طويلاً (أكثر من 60 ثانية)
    if ($conn['Time'] > 60 && $conn['Command'] == 'Sleep') {
        $db->rawQuery("KILL " . (int)$conn['Id']);
        echo "Closed connection ID: " . $conn['Id'] . "\n";
    }
}
```

### حل 4: تحسين إعدادات MySQL

في ملف `my.ini`:
```ini
[mysqld]
max_connections = 200
wait_timeout = 60
interactive_timeout = 60
```

هذا سيغلق الاتصالات غير النشطة بعد 60 ثانية تلقائياً.

### حل 5: استخدام Connection Pooling (للمشاريع الكبيرة جداً)

للأنظمة الكبيرة جداً، يمكن استخدام connection pooling عبر PDO أو MySQL Proxy.

## فحص المشكلة

للتأكد من عدد الاتصالات الحالية:
```sql
SHOW STATUS LIKE 'Threads_connected';
SHOW VARIABLES LIKE 'max_connections';
```

## ملاحظات مهمة

1. **Singleton Pattern**: النظام يستخدم Singleton pattern، لذا يجب أن يكون هناك اتصال واحد فقط لكل طلب
2. **Background Tasks**: جميع background tasks تستخدم نفس Singleton connection
3. **Cron Jobs**: تأكد من أن cron jobs تغلق الاتصالات بشكل صحيح
4. **Long-running Scripts**: تأكد من أن scripts طويلة المدى لا تفتح اتصالات متعددة

## التغييرات المطبقة

### في `includes/db.php`:
- إضافة `getLastError()` method
- إضافة `getLastErrno()` method

### في `modules/manager/local_customers.php`:
- استبدال `getConnection()->query()` بـ `rawQuery()`
- استخدام `getLastError()` و `getLastErrno()` بدلاً من الوصول المباشر لـ `connection->error`

## المراقبة المستمرة

يُنصح بمراقبة عدد الاتصالات بانتظام:

```sql
-- عدد الاتصالات الحالية
SELECT COUNT(*) as current_connections 
FROM information_schema.PROCESSLIST;

-- نسبة الاستخدام
SELECT 
    VARIABLE_VALUE as max_connections,
    (SELECT COUNT(*) FROM information_schema.PROCESSLIST) as current_connections,
    ROUND((SELECT COUNT(*) FROM information_schema.PROCESSLIST) / VARIABLE_VALUE * 100, 2) as usage_percent
FROM information_schema.GLOBAL_VARIABLES 
WHERE VARIABLE_NAME = 'max_connections';
```

## الدعم

إذا استمرت المشكلة، تحقق من:
1. عدد المستخدمين المتزامنين
2. وجود scripts طويلة المدى تعمل
3. وجود cron jobs تفتح اتصالات ولا تغلقها
4. إعدادات MySQL (max_connections, max_user_connections, wait_timeout, interactive_timeout)

## Scripts للمراقبة والتنظيف

تم إنشاء scripts جديدة للمساعدة في مراقبة وإصلاح مشاكل الاتصالات:

### 1. التحقق من الاتصالات الحالية
```
api/check_db_connections.php
```
يعرض معلومات عن الاتصالات الحالية، عددها، ونسبة الاستخدام.

### 2. تنظيف الاتصالات المعلقة
```
api/cleanup_db_connections.php?token=cleanup_db_connections_2024
```
يغلق الاتصالات المعلقة (Sleep) التي تستغرق أكثر من 5 دقائق.

**تحذير:** استخدم هذا script بحذر، خاصة في بيئة الإنتاج.

## إصلاحات حديثة

### تحديثات في `includes/db.php`:
1. ✅ تحسين معالجة خطأ `max_user_connections` - يتم الكشف عنه بشكل صحيح الآن
2. ✅ رسائل خطأ أفضل تشرح المشكلة والحلول المقترحة
3. ✅ تحسين منطق المحاولات عند الوصول لحد الاتصالات
4. ✅ تجنب إنشاء اتصالات اختبار غير ضرورية عند حد الاتصالات


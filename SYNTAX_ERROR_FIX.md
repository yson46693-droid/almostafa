# إصلاح Syntax Error في accountant.php:1683

## المشكلة
خطأ JavaScript: "Uncaught SyntaxError: Invalid or unexpected token (at accountant.php:1683:9)"

## التحليل
السطر 1683 في PHP يبدو طبيعياً:
```php
if ($searchStatus !== null) $searchParams['search_status'] = $searchStatus;
```

لكن الخطأ يقول "Invalid or unexpected token" في JavaScript، مما يعني أن المشكلة قد تكون في:
1. JavaScript داخل `<script>` tag قبل هذا السطر
2. قيمة PHP يتم إخراجها في JavaScript بدون escape صحيح
3. مشكلة في quotes أو special characters

## الحل الموصى به

### 1. فحص JavaScript في الصفحة
افتح الصفحة في المتصفح وافحص:
- Developer Tools > Sources > accountant.php
- ابحث عن السطر 1683
- افحص JavaScript حول هذا السطر

### 2. فحص القيم المخرجة في JavaScript
ابحث عن أي `echo` أو `<?php echo` داخل `<script>` tags قبل السطر 1683

### 3. استخدام json_encode للقيم
إذا كنت تخرج قيم PHP في JavaScript، استخدم:
```php
<?php echo json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
```

### 4. فحص Special Characters
تأكد من عدم وجود special characters في القيم التي يتم إخراجها في JavaScript

## الخطوات التالية
1. فتح accountant.php في المتصفح
2. فتح Developer Tools > Console
3. فحص الخطأ الدقيق
4. فحص Sources tab للعثور على السطر المحدد
5. إصلاح المشكلة بناءً على الخطأ الدقيق

## ملاحظة
إذا كان الخطأ يظهر في console لكن لا يؤثر على وظائف الصفحة، قد يكون خطأ في JavaScript غير مستخدم أو في comment.


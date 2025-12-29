<?php
/**
 * توليد كود فريد للعملاء
 * كود مكون من 5 أحرف (أرقام وحروف عشوائية)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/db.php';

/**
 * توليد كود فريد للعميل من 5 أحرف (أرقام وحروف)
 * 
 * @param string $tableName اسم الجدول ('customers' أو 'local_customers')
 * @return string كود فريد من 5 أحرف
 */
function generateUniqueCustomerCode(string $tableName = 'customers'): string
{
    $db = db();
    $maxAttempts = 1000; // حد أقصى للمحاولات
    $attempt = 0;
    
    // الأحرف المسموح بها: أرقام 0-9 وحروف A-Z (بدون I, O لتجنب الخلط مع 1, 0)
    $chars = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $charsLength = strlen($chars);
    
    do {
        // توليد كود عشوائي من 5 أحرف
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $chars[random_int(0, $charsLength - 1)];
        }
        
        // التحقق من عدم وجود الكود في الجدول المحدد
        $existing = $db->queryOne(
            "SELECT id FROM {$tableName} WHERE unique_code = ? LIMIT 1",
            [$code]
        );
        
        if (empty($existing)) {
            return $code;
        }
        
        $attempt++;
    } while ($attempt < $maxAttempts);
    
    // في حالة فشل جميع المحاولات، استخدم timestamp + random
    $timestamp = substr((string)time(), -3);
    $random = '';
    for ($i = 0; $i < 2; $i++) {
        $random .= $chars[random_int(0, $charsLength - 1)];
    }
    $code = $timestamp . $random;
    
    // التحقق مرة أخرى
    $existing = $db->queryOne(
        "SELECT id FROM {$tableName} WHERE unique_code = ? LIMIT 1",
        [$code]
    );
    
    if (empty($existing)) {
        return $code;
    }
    
    // كحل أخير، استخدم microtime + random
    $microtime = substr(str_replace('.', '', (string)microtime(true)), -2);
    $random = '';
    for ($i = 0; $i < 3; $i++) {
        $random .= $chars[random_int(0, $charsLength - 1)];
    }
    $code = $microtime . $random;
    
    // التحقق النهائي
    $existing = $db->queryOne(
        "SELECT id FROM {$tableName} WHERE unique_code = ? LIMIT 1",
        [$code]
    );
    
    if (empty($existing)) {
        return $code;
    }
    
    // إذا فشل كل شيء، استخدم UUID مختصر
    $uuid = bin2hex(random_bytes(3));
    return strtoupper(substr($uuid, 0, 5));
}

/**
 * التأكد من وجود عمود unique_code في جدول العملاء
 * 
 * @param string $tableName اسم الجدول ('customers' أو 'local_customers')
 * @return bool true إذا كان العمود موجوداً أو تم إنشاؤه بنجاح
 */
function ensureCustomerUniqueCodeColumn(string $tableName = 'customers'): bool
{
    try {
        $db = db();
        
        // التحقق من وجود العمود
        $columnExists = $db->queryOne(
            "SHOW COLUMNS FROM {$tableName} LIKE 'unique_code'"
        );
        
        if (!empty($columnExists)) {
            return true;
        }
        
        // إضافة العمود
        $db->execute("
            ALTER TABLE {$tableName}
            ADD COLUMN unique_code VARCHAR(5) NULL UNIQUE AFTER id,
            ADD INDEX idx_unique_code (unique_code)
        ");
        
        // توليد unique_code للعملاء الموجودين
        $existingCustomers = $db->query(
            "SELECT id FROM {$tableName} WHERE unique_code IS NULL OR unique_code = ''"
        );
        
        foreach ($existingCustomers as $customer) {
            $uniqueCode = generateUniqueCustomerCode($tableName);
            $db->execute(
                "UPDATE {$tableName} SET unique_code = ? WHERE id = ?",
                [$uniqueCode, $customer['id']]
            );
        }
        
        return true;
    } catch (Throwable $e) {
        error_log("Error ensuring unique_code column in {$tableName}: " . $e->getMessage());
        return false;
    }
}


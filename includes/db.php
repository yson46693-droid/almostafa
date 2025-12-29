<?php
/**
 * اتصال قاعدة البيانات MySQL
 * نظام إدارة الشركات المتكامل
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';

// تحميل نظام الكاش إذا كان متوفراً
if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}

class Database {
    private static $instance = null;
    private $connection;
    private $inTransaction = false;
    
    private function __construct() {
        try {
            // إعدادات timeout للاتصال بقاعدة البيانات
            // استخدام timeout قصير لمنع تعليق الخادم
            $connectTimeout = 3; // 3 ثواني للاتصال (مخفض)
            $readTimeout = 5; // 5 ثواني للقراءة (مخفض)
            $writeTimeout = 5; // 5 ثواني للكتابة (مخفض)
            
            // تعيين timeout قبل الاتصال
            ini_set('default_socket_timeout', $connectTimeout);
            
            // إنشاء الاتصال مع timeout
            // استخدام mysqli_report لتقليل الأخطاء أثناء الاتصال
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            
            // معالجة كلمة المرور: إذا كانت فارغة، استخدم null
            $dbPassword = (defined('DB_PASS') && DB_PASS !== '') ? DB_PASS : null;
            
            try {
                $this->connection = @new mysqli(DB_HOST, DB_USER, $dbPassword, DB_NAME, DB_PORT);
            } catch (mysqli_sql_exception $e) {
                // إذا فشل الاتصال، حاول بدون قاعدة البيانات أولاً للتحقق من الاتصال
                try {
                    $testConnection = @new mysqli(DB_HOST, DB_USER, $dbPassword, null, DB_PORT);
                    if ($testConnection->connect_error) {
                        throw new Exception("Cannot connect to MySQL server: " . $testConnection->connect_error);
                    }
                    $testConnection->close();
                    throw new Exception("Database '" . DB_NAME . "' not found or access denied. Please check database name and user permissions.");
                } catch (mysqli_sql_exception $testError) {
                    throw new Exception("MySQL connection error: " . $testError->getMessage());
                }
            }
            
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            
            // تعيين timeout للاتصال بعد الاتصال الناجح
            if (method_exists($this->connection, 'options')) {
                // استخدام القيم الرقمية للثوابت إذا لم تكن معرّفة (لتوافق مع إصدارات PHP القديمة)
                $optConnectTimeout = defined('MYSQLI_OPT_CONNECT_TIMEOUT') ? MYSQLI_OPT_CONNECT_TIMEOUT : 2;
                $optReadTimeout = defined('MYSQLI_OPT_READ_TIMEOUT') ? MYSQLI_OPT_READ_TIMEOUT : 11;
                $optWriteTimeout = defined('MYSQLI_OPT_WRITE_TIMEOUT') ? MYSQLI_OPT_WRITE_TIMEOUT : 12;
                
                @$this->connection->options($optConnectTimeout, $connectTimeout);
                @$this->connection->options($optReadTimeout, $readTimeout);
                @$this->connection->options($optWriteTimeout, $writeTimeout);
            }
            
            // تعيين ترميز UTF-8
            $this->connection->set_charset("utf8mb4");
            
            // تعيين المنطقة الزمنية لتوقيت القاهرة (UTC+2)
            // مصر تستخدم توقيت UTC+2 بدون توقيت صيفي
            $this->connection->query("SET time_zone = '+02:00'");
            
            try {
                $columnCheck = $this->connection->query("SHOW COLUMNS FROM `users` LIKE 'profile_photo'");
                if ($columnCheck instanceof mysqli_result) {
                    if ($columnCheck->num_rows === 0) {
                        $this->connection->query("ALTER TABLE `users` ADD COLUMN `profile_photo` LONGTEXT NULL AFTER `phone`");
                        // مسح الكاش بعد تعديل الجدول
                        $this->clearCache();
                    }
                    $columnCheck->free();
                }
            } catch (Throwable $migrationError) {
                error_log('Profile photo column migration error: ' . $migrationError->getMessage());
            }

            // إنشاء جدول جلسات PWA Splash Screen تلقائياً من ملف SQL
            try {
                $tableCheck = $this->connection->query("SHOW TABLES LIKE 'pwa_splash_sessions'");
                if ($tableCheck instanceof mysqli_result && $tableCheck->num_rows === 0) {
                    // قراءة ملف SQL وتنفيذه
                    $migrationFile = __DIR__ . '/../database/migrations/add_pwa_splash_sessions.sql';
                    if (file_exists($migrationFile)) {
                        $sql = file_get_contents($migrationFile);
                        
                        // إزالة التعليقات والمسافات الزائدة
                        $sql = preg_replace('/--.*$/m', '', $sql);
                        $sql = trim($sql);
                        
                        // تنفيذ الاستعلامات
                        if (!empty($sql)) {
                            // تقسيم الاستعلامات إذا كان هناك أكثر من واحد
                            $queries = array_filter(array_map('trim', explode(';', $sql)));
                            foreach ($queries as $query) {
                                if (!empty($query) && !preg_match('/^--/', $query)) {
                                    $this->connection->query($query);
                                    // مسح الكاش بعد تنفيذ الاستعلام
                                    $this->clearCache();
                                }
                            }
                        }
                    } else {
                        // إذا لم يوجد الملف، استخدم الكود المباشر كبديل
                        $this->connection->query("
                            CREATE TABLE IF NOT EXISTS `pwa_splash_sessions` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `user_id` int(11) DEFAULT NULL,
                              `session_token` varchar(64) NOT NULL,
                              `ip_address` varchar(45) DEFAULT NULL,
                              `user_agent` text DEFAULT NULL,
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              `expires_at` timestamp NOT NULL,
                              PRIMARY KEY (`id`),
                              UNIQUE KEY `session_token` (`session_token`),
                              KEY `user_id` (`user_id`),
                              KEY `expires_at` (`expires_at`),
                              CONSTRAINT `pwa_splash_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                        // مسح الكاش بعد إنشاء الجدول
                        $this->clearCache();
                    }
                }
                if ($tableCheck instanceof mysqli_result) {
                    $tableCheck->free();
                }
            } catch (Throwable $migrationError) {
                error_log('PWA splash sessions table migration error: ' . $migrationError->getMessage());
            }

            // التحقق من وجود أعمدة created_from_pos و created_by_admin في جدول customers
            // تم تعطيله مؤقتاً لتجنب timeout - يمكن تفعيله لاحقاً بعد إصلاح المشكلة
            // try {
            //     $this->ensureCustomerFlagsMigration();
            // } catch (Throwable $e) {
            //     error_log('Customer flags migration error (non-critical): ' . $e->getMessage());
            // }

            // تم تعطيله مؤقتاً لتجنب timeout
            // $this->ensureVehicleInventoryAutoUpgrade();
            
        } catch (Exception $e) {
            // تسجيل الخطأ في ملف السجل
            error_log("Database connection error: " . $e->getMessage());
            error_log("Connection details - Host: " . DB_HOST . ", Port: " . DB_PORT . ", Database: " . DB_NAME . ", User: " . DB_USER);
            
            // رسالة خطأ واضحة للمستخدم
            $errorMessage = "خطأ في الاتصال بقاعدة البيانات.\n\n";
            $errorMessage .= "الرجاء التحقق من:\n";
            $errorMessage .= "1. أن خادم MySQL (XAMPP) يعمل\n";
            $errorMessage .= "2. إعدادات الاتصال في ملف config.php صحيحة\n";
            $errorMessage .= "3. اسم قاعدة البيانات موجود\n";
            $errorMessage .= "4. المستخدم لديه صلاحيات الوصول\n\n";
            $errorMessage .= "تفاصيل الخطأ: " . htmlspecialchars($e->getMessage());
            
            die("<div style='font-family: Arial, sans-serif; padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; color: #721c24; direction: rtl; text-align: right;'>" . 
                "<h3 style='margin-top: 0;'>⚠️ خطأ في الاتصال بقاعدة البيانات</h3>" . 
                "<pre style='background: white; padding: 10px; border-radius: 3px; white-space: pre-wrap;'>" . 
                htmlspecialchars($errorMessage) . 
                "</pre></div>");
        } catch (Throwable $e) {
            // معالجة جميع أنواع الأخطاء
            error_log("Database connection error (Throwable): " . $e->getMessage());
            error_log("Connection details - Host: " . DB_HOST . ", Port: " . DB_PORT . ", Database: " . DB_NAME . ", User: " . DB_USER);
            
            $errorMessage = "خطأ في الاتصال بقاعدة البيانات.\n\n";
            $errorMessage .= "الرجاء التحقق من:\n";
            $errorMessage .= "1. أن خادم MySQL (XAMPP) يعمل\n";
            $errorMessage .= "2. إعدادات الاتصال في ملف config.php صحيحة\n";
            $errorMessage .= "3. اسم قاعدة البيانات موجود\n";
            $errorMessage .= "4. المستخدم لديه صلاحيات الوصول\n\n";
            $errorMessage .= "تفاصيل الخطأ: " . htmlspecialchars($e->getMessage());
            
            die("<div style='font-family: Arial, sans-serif; padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; color: #721c24; direction: rtl; text-align: right;'>" . 
                "<h3 style='margin-top: 0;'>⚠️ خطأ في الاتصال بقاعدة البيانات</h3>" . 
                "<pre style='background: white; padding: 10px; border-radius: 3px; white-space: pre-wrap;'>" . 
                htmlspecialchars($errorMessage) . 
                "</pre></div>");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // منع الاستنساخ
    private function __clone() {}
    
    // منع إلغاء التسلسل
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
    
    /**
     * تنفيذ استعلام SELECT
     */
    public function query($sql, $params = []) {
        try {
            // التحقق من أن الاتصال موجود
            if (!$this->connection) {
                error_log("query: Database connection is null");
                throw new Exception("Database connection is not available");
            }
            
            // في PHP 8.2+، mysqli لا يعيد الاتصال تلقائياً
            // إذا كان الاتصال غير نشط، سيتم رمي استثناء تلقائياً وسيتم التعامل معه في catch block
            $stmt = $this->connection->prepare($sql);
            
            if (!$stmt) {
                $errorMsg = $this->connection->error ?: "Unknown prepare error";
                error_log("query prepare failed: " . $errorMsg . " | SQL: " . $sql);
                throw new Exception("Prepare failed: " . $errorMsg);
            }
            
            if (!empty($params)) {
                $types = '';
                $values = [];
                
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $values[] = $param;
                }
                
                if (!empty($values)) {
                    $stmt->bind_param($types, ...$values);
                }
            }
            
            if (!$stmt->execute()) {
                $errorMsg = $stmt->error ?: "Unknown execute error";
                error_log("query execute failed: " . $errorMsg . " | SQL: " . $sql);
                $stmt->close();
                throw new Exception("Execute failed: " . $errorMsg);
            }
            
            $result = $stmt->get_result();
            $data = [];
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
                $result->free();
            }
            
            $stmt->close();
            return $data;
            
        } catch (mysqli_sql_exception $e) {
            error_log("query mysqli error: " . $e->getMessage());
            error_log("query SQL: " . $sql);
            error_log("query params: " . json_encode($params));
            throw new Exception("Database query error: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("query error: " . $e->getMessage());
            error_log("query SQL: " . $sql);
            throw $e;
        } catch (Throwable $e) {
            error_log("query fatal error: " . $e->getMessage());
            error_log("query SQL: " . $sql);
            throw new Exception("Database query fatal error: " . $e->getMessage());
        }
    }
    
    /**
     * تنفيذ استعلام SELECT واحد
     */
    public function queryOne($sql, $params = []) {
        try {
            // التحقق من أن الاتصال موجود
            if (!$this->connection) {
                $errorMsg = "queryOne: Database connection is null";
                error_log($errorMsg);
                throw new Exception("Database connection is not available");
            }
            
            // في PHP 8.2+، mysqli لا يعيد الاتصال تلقائياً
            // إذا كان الاتصال غير نشط، سيتم رمي استثناء تلقائياً وسيتم التعامل معه في catch block
            $result = $this->query($sql, $params);
            return !empty($result) ? $result[0] : null;
            
        } catch (mysqli_sql_exception $e) {
            $errorMsg = "queryOne mysqli error: " . $e->getMessage() . " (Error code: " . $e->getCode() . ")";
            error_log($errorMsg);
            error_log("queryOne SQL: " . $sql);
            error_log("queryOne params: " . json_encode($params));
            throw new Exception("Database query error: " . $e->getMessage() . " (Error code: " . $e->getCode() . ")");
        } catch (Exception $e) {
            error_log("queryOne error: " . $e->getMessage());
            error_log("queryOne SQL: " . $sql);
            error_log("queryOne params: " . json_encode($params));
            throw $e;
        } catch (Throwable $e) {
            error_log("queryOne fatal error: " . $e->getMessage());
            error_log("queryOne SQL: " . $sql);
            error_log("queryOne params: " . json_encode($params));
            throw new Exception("Database query fatal error: " . $e->getMessage());
        }
    }
    
    /**
     * تنفيذ استعلام INSERT/UPDATE/DELETE
     */
    public function execute($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $values = [];
            
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
                $values[] = $param;
            }
            
            $stmt->bind_param($types, ...$values);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $insertId = $stmt->insert_id;
        $stmt->close();
        
        // مسح الكاش فوراً بعد أي عملية تعديل (INSERT/UPDATE/DELETE) لضمان ظهور النتائج بشكل لحظي
        // يتم المسح فوراً بدون انتظار لضمان التحديث الفوري
        if (class_exists('Cache')) {
            try {
                // التحقق من نوع الاستعلام للتأكد أنه تعديلي
                $sqlUpper = strtoupper(trim($sql));
                $isModifying = preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)/i', $sqlUpper);
                
                if ($isModifying) {
                    // مسح الكاش فوراً بشكل متزامن لضمان التحديث الفوري
                    Cache::flush();
                    
                    // مسح الكاش من الذاكرة أيضاً للتأكد من التحديث الفوري
                    if (method_exists('Cache', 'flush')) {
                        // التأكد من مسح جميع أنواع الكاش
                        Cache::flush();
                    }
                }
            } catch (Exception $e) {
                // تجاهل أخطاء مسح الكاش لتجنب تعطيل العملية
                error_log("Cache flush error: " . $e->getMessage());
            } catch (Throwable $e) {
                // معالجة جميع أنواع الأخطاء
                error_log("Cache flush error (Throwable): " . $e->getMessage());
            }
        }
        
        return [
            'affected_rows' => $affectedRows,
            'insert_id' => $insertId
        ];
    }
    
    /**
     * بدء معاملة
     */
    public function beginTransaction() {
        $this->inTransaction = true;
        return $this->connection->begin_transaction();
    }
    
    /**
     * تأكيد المعاملة
     */
    public function commit() {
        $this->inTransaction = false;
        $result = $this->connection->commit();
        
        // مسح الكاش فوراً بعد تأكيد المعاملة لضمان ظهور التغييرات بشكل لحظي
        if ($result && class_exists('Cache')) {
            try {
                // مسح الكاش فوراً بدون انتظار
                Cache::flush();
            } catch (Exception $e) {
                // تجاهل أخطاء مسح الكاش
                error_log("Cache flush error after commit: " . $e->getMessage());
            }
        }
        
        return $result;
    }
    
    /**
     * إلغاء المعاملة
     */
    public function rollback() {
        $this->inTransaction = false;
        return $this->connection->rollback();
    }
    
    /**
     * التحقق من وجود معاملة نشطة
     */
    public function inTransaction() {
        return $this->inTransaction;
    }
    
    /**
     * الهروب من الأحرف الخاصة
     */
    public function escape($string) {
        return $this->connection->real_escape_string($string);
    }
    
    /**
     * الحصول على آخر معرف تم إدراجه
     */
    public function getLastInsertId() {
        return $this->connection->insert_id;
    }
    
    /**
     * الحصول على آخر خطأ من قاعدة البيانات
     */
    public function getLastError() {
        if ($this->connection) {
            $error = $this->connection->error;
            $errno = $this->connection->errno;
            if ($error) {
                return "Error #{$errno}: {$error}";
            }
        }
        return '';
    }
    
    /**
     * إغلاق الاتصال
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
    
    /**
     * مسح الكاش بعد العمليات بشكل فوري
     * يمكن استدعاؤها يدوياً بعد العمليات التي تستخدم connection->query مباشرة
     * يتم المسح فوراً لضمان ظهور النتائج بشكل لحظي
     */
    public function clearCache() {
        if (class_exists('Cache')) {
            try {
                // مسح الكاش فوراً بشكل متزامن
                Cache::flush();
            } catch (Exception $e) {
                error_log("Cache flush error: " . $e->getMessage());
            } catch (Throwable $e) {
                error_log("Cache flush error (Throwable): " . $e->getMessage());
            }
        }
    }
    
    /**
     * تنفيذ استعلام مباشر مع مسح الكاش التلقائي للعمليات التعديلية
     * هذه الدالة للاستعلامات التي لا يمكن استخدام prepared statements معها
     * 
     * @param string $sql الاستعلام
     * @param bool $isModifying هل الاستعلام يعدل البيانات (INSERT/UPDATE/DELETE/ALTER)
     * @return mysqli_result|bool نتيجة الاستعلام
     */
    public function rawQuery($sql, $isModifying = false) {
        // تحديد نوع الاستعلام تلقائياً إذا لم يتم تحديده
        if (!$isModifying) {
            $sqlUpper = strtoupper(trim($sql));
            $isModifying = preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)/i', $sqlUpper);
        }
        
        $result = $this->connection->query($sql);
        
        // مسح الكاش فوراً بعد أي عملية تعديل
        if ($isModifying && $result !== false && class_exists('Cache')) {
            try {
                Cache::flush();
            } catch (Exception $e) {
                error_log("Cache flush error after rawQuery: " . $e->getMessage());
            }
        }
        
        return $result;
    }

    /**
     * تشغيل ترقية مخزن سيارات المندوبين تلقائياً مرة واحدة
     */
    private function ensureVehicleInventoryAutoUpgrade(): void
    {
        static $upgradeEnsured = false;

        if ($upgradeEnsured) {
            return;
        }

        $upgradeEnsured = true;

        try {
            $flagFile = dirname(__DIR__) . '/runtime/vehicle_inventory_upgrade.flag';

            if (file_exists($flagFile)) {
                return;
            }

            $tableExists = $this->connection->query("SHOW TABLES LIKE 'vehicle_inventory'");
            if (!$tableExists instanceof mysqli_result || $tableExists->num_rows === 0) {
                return;
            }
            $tableExists->free();

            $columnsResult = $this->connection->query("SHOW COLUMNS FROM vehicle_inventory");
            $existingColumns = [];
            if ($columnsResult instanceof mysqli_result) {
                while ($column = $columnsResult->fetch_assoc()) {
                    if (!empty($column['Field'])) {
                        $existingColumns[strtolower($column['Field'])] = true;
                    }
                }
                $columnsResult->free();
            }

            $alterParts = [];

            if (!isset($existingColumns['warehouse_id'])) {
                $alterParts[] = "ADD COLUMN `warehouse_id` int(11) DEFAULT NULL COMMENT 'مخزن السيارة' AFTER `vehicle_id`";
            }
            if (!isset($existingColumns['product_name'])) {
                $alterParts[] = "ADD COLUMN `product_name` varchar(255) DEFAULT NULL AFTER `product_id`";
            }
            if (!isset($existingColumns['product_category'])) {
                $alterParts[] = "ADD COLUMN `product_category` varchar(100) DEFAULT NULL AFTER `product_name`";
            }
            if (!isset($existingColumns['product_unit'])) {
                $alterParts[] = "ADD COLUMN `product_unit` varchar(50) DEFAULT NULL AFTER `product_category`";
            }
            if (!isset($existingColumns['product_unit_price'])) {
                $alterParts[] = "ADD COLUMN `product_unit_price` decimal(15,2) DEFAULT NULL AFTER `product_unit`";
            }
            if (!isset($existingColumns['product_snapshot'])) {
                $alterParts[] = "ADD COLUMN `product_snapshot` longtext DEFAULT NULL AFTER `product_unit_price`";
            }
            if (!isset($existingColumns['manager_unit_price'])) {
                $alterParts[] = "ADD COLUMN `manager_unit_price` decimal(15,2) DEFAULT NULL AFTER `product_unit_price`";
            }
            if (!isset($existingColumns['finished_batch_id'])) {
                $alterParts[] = "ADD COLUMN `finished_batch_id` int(11) DEFAULT NULL AFTER `manager_unit_price`";
            }
            if (!isset($existingColumns['finished_batch_number'])) {
                $alterParts[] = "ADD COLUMN `finished_batch_number` varchar(100) DEFAULT NULL AFTER `finished_batch_id`";
            }
            if (!isset($existingColumns['finished_production_date'])) {
                $alterParts[] = "ADD COLUMN `finished_production_date` date DEFAULT NULL AFTER `finished_batch_number`";
            }
            if (!isset($existingColumns['finished_quantity_produced'])) {
                $alterParts[] = "ADD COLUMN `finished_quantity_produced` decimal(12,2) DEFAULT NULL AFTER `finished_production_date`";
            }
            if (!isset($existingColumns['finished_workers'])) {
                $alterParts[] = "ADD COLUMN `finished_workers` text DEFAULT NULL AFTER `finished_quantity_produced`";
            }
            if (!isset($existingColumns['quantity'])) {
                $alterParts[] = "ADD COLUMN `quantity` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `finished_workers`";
            }
            if (!isset($existingColumns['last_updated_by'])) {
                $alterParts[] = "ADD COLUMN `last_updated_by` int(11) DEFAULT NULL AFTER `quantity`";
            }
            if (!isset($existingColumns['last_updated_at'])) {
                $alterParts[] = "ADD COLUMN `last_updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `last_updated_by`";
            }
            if (!isset($existingColumns['created_at'])) {
                $alterParts[] = "ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_updated_at`";
            }

            if (!empty($alterParts)) {
                $this->connection->query("ALTER TABLE vehicle_inventory " . implode(', ', $alterParts));
                // مسح الكاش بعد تعديل الجدول
                $this->clearCache();
            }

            $indexesResult = $this->connection->query("SHOW INDEXES FROM vehicle_inventory");
            $existingIndexes = [];
            if ($indexesResult instanceof mysqli_result) {
                while ($index = $indexesResult->fetch_assoc()) {
                    if (!empty($index['Key_name'])) {
                        $existingIndexes[strtolower($index['Key_name'])] = true;
                    }
                }
                $indexesResult->free();
            }

            $indexAlterParts = [];
            if (!isset($existingIndexes['finished_batch_id'])) {
                $indexAlterParts[] = "ADD KEY `finished_batch_id` (`finished_batch_id`)";
            }
            if (!isset($existingIndexes['finished_batch_number'])) {
                $indexAlterParts[] = "ADD KEY `finished_batch_number` (`finished_batch_number`)";
            }
            if (!isset($existingIndexes['warehouse_id'])) {
                $indexAlterParts[] = "ADD KEY `warehouse_id` (`warehouse_id`)";
            }
            if (!isset($existingIndexes['product_id'])) {
                $indexAlterParts[] = "ADD KEY `product_id` (`product_id`)";
            }
            if (!isset($existingIndexes['last_updated_by'])) {
                $indexAlterParts[] = "ADD KEY `last_updated_by` (`last_updated_by`)";
            }

            if (!empty($indexAlterParts)) {
                $this->connection->query("ALTER TABLE vehicle_inventory " . implode(', ', $indexAlterParts));
                // مسح الكاش بعد تعديل الفهارس
                $this->clearCache();
            }

            // تحديث القيد UNIQUE ليشمل finished_batch_id للسماح بمنتجات من نفس النوع برقم تشغيلة مختلف
            // يجب تنفيذ DROP و ADD في أوامر منفصلة
            $hasOldConstraint = isset($existingIndexes['vehicle_product_unique']) || isset($existingIndexes['vehicle_product']);
            $hasNewConstraint = isset($existingIndexes['vehicle_product_batch_unique']);
            
            if ($hasOldConstraint && !$hasNewConstraint) {
                try {
                    // حذف القيد القديم
                    if (isset($existingIndexes['vehicle_product_unique'])) {
                        $this->connection->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`");
                    }
                    if (isset($existingIndexes['vehicle_product'])) {
                        $this->connection->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`");
                    }
                    // إضافة القيد الجديد الذي يشمل finished_batch_id
                    // في MySQL، يمكن أن يكون هناك عدة صفوف بنفس (vehicle_id, product_id) إذا كان finished_batch_id NULL
                    // ولكن يجب أن يكون هناك صف واحد فقط لكل (vehicle_id, product_id, finished_batch_id) حيث finished_batch_id NOT NULL
                    $this->connection->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)");
                    // مسح الكاش بعد تعديل القيد
                    $this->clearCache();
                } catch (Throwable $constraintError) {
                    error_log("Error updating vehicle_inventory unique constraint: " . $constraintError->getMessage());
                }
            } elseif (!$hasNewConstraint && !$hasOldConstraint) {
                // إضافة القيد الجديد مباشرة إذا لم يكن هناك قيد قديم
                try {
                    $this->connection->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)");
                    // مسح الكاش بعد إضافة القيد
                    $this->clearCache();
                } catch (Throwable $constraintError) {
                    error_log("Error adding vehicle_inventory unique constraint: " . $constraintError->getMessage());
                }
            }

            $this->connection->query(
                "INSERT INTO system_settings (`key`, `value`, updated_at) VALUES ('vehicle_inventory_upgraded', '1', NOW())
                 ON DUPLICATE KEY UPDATE `value` = '1', updated_at = NOW()"
            );
            // مسح الكاش بعد تحديث الإعدادات
            $this->clearCache();

            $flagDir = dirname($flagFile);
            if (!is_dir($flagDir)) {
                @mkdir($flagDir, 0775, true);
            }
            @file_put_contents($flagFile, date('c'));

        } catch (Throwable $upgradeError) {
            error_log('Vehicle inventory auto upgrade error: ' . $upgradeError->getMessage());
        }
    }
    
    /**
     * التحقق من وجود أعمدة created_from_pos و created_by_admin في جدول customers
     * يتم تشغيلها تلقائياً مرة واحدة فقط
     */
    private function ensureCustomerFlagsMigration(): void
    {
        static $migrationEnsured = false;

        if ($migrationEnsured) {
            return;
        }

        $migrationEnsured = true;

        try {
            $flagFile = dirname(__DIR__) . '/runtime/customer_flags_migration.flag';

            // إذا تم تشغيل الهجرة من قبل، تخطي
            if (file_exists($flagFile)) {
                return;
            }

            $tableCheck = $this->connection->query("SHOW TABLES LIKE 'customers'");
            if (!$tableCheck instanceof mysqli_result || $tableCheck->num_rows === 0) {
                if ($tableCheck instanceof mysqli_result) {
                    $tableCheck->free();
                }
                return;
            }
            $tableCheck->free();

            // التحقق من وجود الأعمدة بسرعة باستخدام استعلامات سريعة
            $createdFromPosCheck = $this->connection->query("SHOW COLUMNS FROM `customers` LIKE 'created_from_pos'");
            $createdByAdminCheck = $this->connection->query("SHOW COLUMNS FROM `customers` LIKE 'created_by_admin'");
            $repIdCheck = $this->connection->query("SHOW COLUMNS FROM `customers` LIKE 'rep_id'");
            
            $needsRepId = !($repIdCheck instanceof mysqli_result && $repIdCheck->num_rows > 0);
            $needsCreatedFromPos = !($createdFromPosCheck instanceof mysqli_result && $createdFromPosCheck->num_rows > 0);
            $needsCreatedByAdmin = !($createdByAdminCheck instanceof mysqli_result && $createdByAdminCheck->num_rows > 0);
            
            if ($repIdCheck instanceof mysqli_result) {
                $repIdCheck->free();
            }
            if ($createdFromPosCheck instanceof mysqli_result) {
                $createdFromPosCheck->free();
            }
            if ($createdByAdminCheck instanceof mysqli_result) {
                $createdByAdminCheck->free();
            }

            if (!$needsRepId && !$needsCreatedFromPos && !$needsCreatedByAdmin) {
                // جميع الأعمدة موجودة، إنشاء flag file وتخطي
                $flagDir = dirname($flagFile);
                if (!is_dir($flagDir)) {
                    @mkdir($flagDir, 0775, true);
                }
                @file_put_contents($flagFile, date('c'));
                return;
            }

            // إضافة الأعمدة واحداً تلو الآخر بالترتيب الصحيح
            if ($needsRepId) {
                $this->connection->query("ALTER TABLE `customers` ADD COLUMN `rep_id` INT NULL AFTER `id`");
                // مسح الكاش بعد تعديل الجدول
                $this->clearCache();
            }
            if ($needsCreatedFromPos) {
                $afterColumn = $needsRepId ? 'rep_id' : 'id';
                $this->connection->query("ALTER TABLE `customers` ADD COLUMN `created_from_pos` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$afterColumn}`");
                // مسح الكاش بعد تعديل الجدول
                $this->clearCache();
            }
            if ($needsCreatedByAdmin) {
                if (!$needsCreatedFromPos) {
                    $afterColumn = $needsRepId ? 'rep_id' : 'id';
                } else {
                    $afterColumn = 'created_from_pos';
                }
                $this->connection->query("ALTER TABLE `customers` ADD COLUMN `created_by_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$afterColumn}`");
                // مسح الكاش بعد تعديل الجدول
                $this->clearCache();
            }

            // إضافة مفتاح rep_id إذا تم إضافته
            if ($needsRepId) {
                try {
                    $indexCheck = $this->connection->query("SHOW INDEX FROM `customers` WHERE Key_name = 'rep_id'");
                    $hasIndex = ($indexCheck instanceof mysqli_result && $indexCheck->num_rows > 0);
                    if ($indexCheck instanceof mysqli_result) {
                        $indexCheck->free();
                    }
                    if (!$hasIndex) {
                        $this->connection->query("ALTER TABLE `customers` ADD KEY `rep_id` (`rep_id`)");
                        // مسح الكاش بعد إضافة الفهرس
                        $this->clearCache();
                    }
                } catch (Throwable $indexError) {
                    error_log('Customers rep_id index migration error: ' . $indexError->getMessage());
                }

                // تحديث rep_id للعملاء الموجودين - تم تعطيله لتجنب timeout
                // يمكن تشغيله لاحقاً عبر cron job أو يدوياً
                // try {
                //     $this->connection->query("
                //         UPDATE customers c
                //         INNER JOIN users u ON c.rep_id IS NULL AND c.created_by = u.id AND u.role = 'sales'
                //         SET c.rep_id = u.id
                //     ");
                // } catch (Throwable $updateError) {
                //     error_log('Customers rep_id update error (non-critical): ' . $updateError->getMessage());
                // }
            }

            // إنشاء flag file للإشارة إلى اكتمال الهجرة
            $flagDir = dirname($flagFile);
            if (!is_dir($flagDir)) {
                @mkdir($flagDir, 0775, true);
            }
            @file_put_contents($flagFile, date('c'));

        } catch (Throwable $customerFlagsError) {
            error_log('Customers flags migration error: ' . $customerFlagsError->getMessage());
        }
    }
    
    /**
     * تحديث قيد UNIQUE في vehicle_inventory ليشمل finished_batch_id
     * يتم استدعاؤها تلقائياً عند الاتصال بقاعدة البيانات
     */
    private function updateVehicleInventoryUniqueConstraint(): void
    {
        static $updated = false;
        
        if ($updated) {
            return;
        }
        
        try {
            // التحقق من وجود الجدول
            $tableExists = $this->connection->query("SHOW TABLES LIKE 'vehicle_inventory'");
            if (!$tableExists instanceof mysqli_result || $tableExists->num_rows === 0) {
                return;
            }
            $tableExists->free();
            
            // الحصول على الفهارس الموجودة
            $indexesResult = $this->connection->query("SHOW INDEXES FROM vehicle_inventory");
            $existingIndexes = [];
            if ($indexesResult instanceof mysqli_result) {
                while ($index = $indexesResult->fetch_assoc()) {
                    if (!empty($index['Key_name'])) {
                        $existingIndexes[strtolower($index['Key_name'])] = true;
                    }
                }
                $indexesResult->free();
            }
            
            $hasOldConstraint = isset($existingIndexes['vehicle_product_unique']) || isset($existingIndexes['vehicle_product']);
            $hasNewConstraint = isset($existingIndexes['vehicle_product_batch_unique']);
            
            // إذا كان القيد الجديد موجود بالفعل، لا حاجة للتحديث
            if ($hasNewConstraint) {
                $updated = true;
                return;
            }
            
            // حذف القيد القديم وإضافة الجديد
            if ($hasOldConstraint) {
                try {
                    if (isset($existingIndexes['vehicle_product_unique'])) {
                        $this->connection->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`");
                    }
                    if (isset($existingIndexes['vehicle_product'])) {
                        $this->connection->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`");
                    }
                } catch (Throwable $dropError) {
                    error_log("Error dropping old constraint: " . $dropError->getMessage());
                }
            }
            
            // إضافة القيد الجديد
            try {
                $this->connection->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)");
                // مسح الكاش بعد إضافة القيد
                $this->clearCache();
                $updated = true;
            } catch (Throwable $addError) {
                // قد يكون القيد موجود بالفعل أو هناك مشكلة أخرى
                error_log("Error adding new constraint: " . $addError->getMessage());
            }
            
        } catch (Throwable $e) {
            error_log("Error in updateVehicleInventoryUniqueConstraint: " . $e->getMessage());
        }
    }
}

// دالة مساعدة للحصول على اتصال قاعدة البيانات
function getDB() {
    return Database::getInstance()->getConnection();
}

// دالة مساعدة للحصول على كائن قاعدة البيانات
function db() {
    return Database::getInstance();
}
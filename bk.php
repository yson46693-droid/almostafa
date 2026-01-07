<?php
/**
 * db_backup.php
 * سكربت لعمل نسخة احتياطية من قاعدة بيانات MySQL (هيكل + بيانات)
 *
 * الاستخدام:
 * - ضع هذا الملف في مكان آمن على السيرفر.
 * - يمكن استخدامه يدوياً أو تلقائياً عبر النظام
 * - شغِّل عبر CLI أو كرون أو من المتصفح (يفضل CLI).
 *
 * المخرجات:
 * - ملف SQL مضغوط gzip داخل مجلد backups/ باسم: backup_<dbname>_YYYY-MM-DD_HH-MM-SS.sql.gz
 *
 * ملاحظة: السكربت يحاول استخدام mysqldump أولاً (إن وُجد)، وإلا يستخدم PDO لتصدير.
 */

set_time_limit(0);
date_default_timezone_set('Africa/Cairo'); // ضبط المنطقة الزمنية للمستخدم

/**
 * دالة لإنشاء نسخة احتياطية باستخدام bk.php
 * يمكن استدعاؤها من backup.php أو من أي مكان آخر في النظام
 * 
 * @param string|null $backupDir مجلد النسخ الاحتياطي (اختياري)
 * @param bool $exportStructureOnly تصدير الهيكل فقط بدون بيانات (افتراضي: false)
 * @param bool $useMysqldumpIfAvailable استخدام mysqldump إن كان متاحاً (افتراضي: true)
 * @return array|false مصفوفة تحتوي على ['success' => true, 'file_path' => ..., 'filename' => ...] أو false في حالة الفشل
 */
if (!function_exists('createBackupUsingBkScript')) {
    function createBackupUsingBkScript($backupDir = null, $exportStructureOnly = false, $useMysqldumpIfAvailable = true) {
        set_time_limit(0);
        
        // ---------- إعدادات الاتصال (من config.php) ----------
        if (file_exists(__DIR__ . '/includes/config.php')) {
            if (!defined('ACCESS_ALLOWED')) {
                define('ACCESS_ALLOWED', true);
            }
            require_once __DIR__ . '/includes/config.php';
            
            $dbHost = defined('DB_HOST') ? DB_HOST : 'sql110.infinityfree.com';
            $dbPort = defined('DB_PORT') ? DB_PORT : '3306';
            $dbName = defined('DB_NAME') ? DB_NAME : 'if0_40278066_co_db';
            $dbUser = defined('DB_USER') ? DB_USER : 'if0_40278066';
            $dbPass = defined('DB_PASS') ? DB_PASS : 'Osama744';
        } else {
            $dbHost = 'sql110.infinityfree.com';
            $dbPort = '3306';
            $dbName = 'if0_40278066_co_db';
            $dbUser = 'if0_40278066';
            $dbPass = 'Osama744';
        }
        
        // تحديد مجلد النسخ الاحتياطي
        if ($backupDir === null) {
            $backupDir = defined('BASE_PATH') ? BASE_PATH . '/backups' : __DIR__ . '/backups';
        }
        
        // إنشاء مجلد النسخ الاحتياطي إن لم يكن موجودًا
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                return ['success' => false, 'message' => "فشل في إنشاء مجلد النسخ الاحتياطي: $backupDir"];
            }
        }
        
        // اسم الملف
        $ts = date('Y-m-d_H-i-s');
        $filenameSql = "backup_{$dbName}_{$ts}.sql";
        $filenameGz  = $filenameSql . '.gz';
        $fullPathGz  = $backupDir . DIRECTORY_SEPARATOR . $filenameGz;
        
        // محاولة mysqldump أولاً
        if ($useMysqldumpIfAvailable && function_exists('exec')) {
            $whichCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where mysqldump' : 'which mysqldump';
            @exec($whichCmd, $out, $ret);
            $mysqldumpPath = ($ret === 0 && !empty($out)) ? trim($out[0]) : null;
            
            if ($mysqldumpPath) {
                $structureOnlyFlag = $exportStructureOnly ? '--no-data' : '';
                // إضافة خيارات كاملة لضمان تصدير جميع الجداول والبيانات
                $cmd = escapeshellcmd($mysqldumpPath)
                    . " --host=" . escapeshellarg($dbHost)
                    . " --port=" . escapeshellarg($dbPort)
                    . " --user=" . escapeshellarg($dbUser)
                    . " --password=" . escapeshellarg($dbPass)
                    . " --routines --triggers --events --single-transaction --quick --hex-blob"
                    . " --complete-insert --add-drop-table --extended-insert --lock-tables=false"
                    . " --default-character-set=utf8mb4 --set-charset"
                    . " $structureOnlyFlag "
                    . " " . escapeshellarg($dbName)
                    . " 2>&1";
                
                $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filenameSql;
                $errorLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filenameSql . '.error.log';
                $cmdOut = $cmd . " > " . escapeshellarg($tmpSql) . " 2> " . escapeshellarg($errorLog);
                
                exec($cmdOut, $dumpOut, $dumpRet);
                
                // قراءة سجل الأخطاء إن وُجد
                $errorOutput = '';
                if (file_exists($errorLog)) {
                    $errorOutput = file_get_contents($errorLog);
                    @unlink($errorLog);
                }
                
                // تسجيل معلومات mysqldump
                error_log("Backup mysqldump: Return code = $dumpRet, Output lines = " . count($dumpOut));
                if (!empty($errorOutput)) {
                    error_log("Backup mysqldump error output: " . $errorOutput);
                }
                if (!empty($dumpOut)) {
                    error_log("Backup mysqldump stdout: " . implode("\n", array_slice($dumpOut, 0, 10)));
                }
                
                if ($dumpRet === 0 && file_exists($tmpSql)) {
                    // التحقق من أن الملف ليس فارغاً
                    $fileSize = filesize($tmpSql);
                    if ($fileSize === false || $fileSize === 0) {
                        error_log("Backup mysqldump: File exists but is empty or unreadable");
                        @unlink($tmpSql);
                        // الاستمرار في استخدام الطريقة البديلة
                    } else {
                        // قراءة جزء من الملف للتحقق من محتواه
                        $sampleContent = file_get_contents($tmpSql, false, null, 0, 1000);
                        if (empty($sampleContent) || strpos($sampleContent, 'CREATE TABLE') === false) {
                            error_log("Backup mysqldump: File exists but doesn't contain valid SQL content");
                            @unlink($tmpSql);
                            // الاستمرار في استخدام الطريقة البديلة
                        } else {
                            $fpIn = fopen($tmpSql, 'rb');
                            if ($fpIn === false) {
                                return ['success' => false, 'message' => "فشل في فتح الملف المؤقت للتصدير."];
                            }
                            $fpOut = gzopen($fullPathGz, 'wb9');
                            if ($fpOut === false) {
                                fclose($fpIn);
                                return ['success' => false, 'message' => "فشل في إنشاء الملف المضغوط: $fullPathGz"];
                            }
                            while (!feof($fpIn)) {
                                $chunk = fread($fpIn, 1024 * 512);
                                gzwrite($fpOut, $chunk);
                            }
                            fclose($fpIn);
                            gzclose($fpOut);
                            @unlink($tmpSql);
                            
                            error_log("Backup mysqldump: Successfully created backup file, size = " . $fileSize . " bytes");
                            return [
                                'success' => true,
                                'file_path' => $fullPathGz,
                                'filename' => $filenameGz,
                                'method' => 'mysqldump'
                            ];
                        }
                    }
                } else {
                    // فشل mysqldump - تسجيل الخطأ والاستمرار في الطريقة البديلة
                    $errorMsg = "mysqldump failed with return code: $dumpRet";
                    if (!empty($errorOutput)) {
                        $errorMsg .= ", Error: " . trim($errorOutput);
                    }
                    if (!empty($dumpOut)) {
                        $errorMsg .= ", Output: " . implode("; ", array_slice($dumpOut, 0, 5));
                    }
                    error_log("Backup: " . $errorMsg . " - Falling back to PHP method");
                }
            }
        }
        
        // طريقة PDO أو mysqli (تصدير عبر PHP)
        // التحقق من وجود pdo_mysql أو mysqli extension
        $usePdo = extension_loaded('pdo_mysql');
        $useMysqli = extension_loaded('mysqli');
        
        if (!$usePdo && !$useMysqli) {
            return ['success' => false, 'message' => "pdo_mysql أو mysqli extension غير محمّل. يرجى تفعيل أحدهما في ملف php.ini"];
        }
        
        $pdo = null;
        $mysqli = null;
        
        // محاولة الاتصال باستخدام PDO أولاً (إن كان متاحاً)
        if ($usePdo) {
            try {
                // استخدام TCP/IP للاتصال (مهم لـ InfinityFree)
                $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
                // إضافة خيارات إضافية لضمان الاتصال عبر TCP/IP
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 30,
                    PDO::ATTR_PERSISTENT => false
                ];
                
                // إضافة MYSQL_ATTR_INIT_COMMAND فقط إذا كان pdo_mysql محمّل ومعرّف
                if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
                }
                
                $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
                
                // اختبار الاتصال وتعيين charset
                $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                // محاولة إصلاح خطأ socket
                if (strpos($errorMsg, 'No such file or directory') !== false || strpos($errorMsg, '2002') !== false) {
                    // إعادة المحاولة مع إجبار TCP/IP
                    try {
                        $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
                        $retryOptions = [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                        ];
                        
                        // إضافة MYSQL_ATTR_INIT_COMMAND فقط إذا كان معرّف
                        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                            $retryOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
                        }
                        
                        $pdo = new PDO($dsn, $dbUser, $dbPass, $retryOptions);
                        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                    } catch (PDOException $e2) {
                        // إذا فشل PDO، جرب mysqli
                        $pdo = null;
                    }
                } else {
                    // إذا فشل PDO، جرب mysqli
                    $pdo = null;
                }
            } catch (Exception $e) {
                // إذا فشل PDO، جرب mysqli
                $pdo = null;
            }
        }
        
        // إذا فشل PDO أو لم يكن متاحاً، استخدم mysqli
        if (!$pdo && $useMysqli) {
            try {
                $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
                if ($mysqli->connect_error) {
                    throw new Exception("فشل الاتصال: " . $mysqli->connect_error);
                }
                $mysqli->set_charset("utf8mb4");
                $mysqli->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Exception $e) {
                return ['success' => false, 'message' => "فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . " (Host: $dbHost, Port: $dbPort)"];
            }
        }
        
        // التحقق من وجود اتصال صالح
        if (!$pdo && !$mysqli) {
            return ['success' => false, 'message' => "فشل الاتصال بقاعدة البيانات: لا يمكن استخدام PDO أو mysqli"];
        }
        
        // Helper functions للتعامل مع PDO و mysqli بشكل موحد
        $dbQuery = function($sql) use ($pdo, $mysqli) {
            if ($pdo) {
                return $pdo->query($sql);
            } else {
                $result = $mysqli->query($sql);
                if (!$result) {
                    throw new Exception($mysqli->error);
                }
                return $result;
            }
        };
        
        $dbFetch = function($result, $fetchMode = null) use ($pdo, $mysqli) {
            if ($pdo) {
                if ($fetchMode === PDO::FETCH_NUM) {
                    return $result->fetch(PDO::FETCH_NUM);
                } else {
                    return $result->fetch(PDO::FETCH_ASSOC);
                }
            } else {
                if ($fetchMode === 'NUM') {
                    return $result->fetch_array(MYSQLI_NUM);
                } else {
                    return $result->fetch_assoc();
                }
            }
        };
        
        $dbFetchAll = function($result) use ($pdo, $mysqli) {
            if ($pdo) {
                return $result->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                return $rows;
            }
        };
        
        $dbPrepare = function($sql) use ($pdo, $mysqli) {
            if ($pdo) {
                return $pdo->prepare($sql);
            } else {
                return $mysqli->prepare($sql);
            }
        };
        
        $dbQuote = function($value) use ($pdo, $mysqli) {
            if ($pdo) {
                return $pdo->quote($value);
            } else {
                return "'" . $mysqli->real_escape_string($value) . "'";
            }
        };
        
        // ملف مؤقت نصي
        $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filenameSql;
        $fh = fopen($tmpSql, 'w');
        if (!$fh) {
            // إغلاق الاتصال قبل إرجاع الخطأ
            if ($pdo) {
                $pdo = null;
            }
            if ($mysqli) {
                $mysqli->close();
            }
            return ['success' => false, 'message' => "فشل في إنشاء الملف المؤقت: $tmpSql"];
        }
        
        // استخدام try-finally لضمان إغلاق الاتصال في جميع الحالات
        try {
            // رأس الملف
            fwrite($fh, "-- Backup created by db_backup.php\n");
            fwrite($fh, "-- Database: {$dbName}\n");
            fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . " (Africa/Cairo)\n\n");
            fwrite($fh, "SET NAMES utf8mb4;\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");
        
        // جلب قائمة الجداول - استخدام SHOW FULL TABLES لضمان جلب جميع الجداول
        $tables = [];
        try {
            // استخدام SHOW FULL TABLES للحصول على جميع الجداول مع نوعها
            $result = $dbQuery("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            while ($row = $dbFetch($result, $pdo ? PDO::FETCH_NUM : 'NUM')) {
                $tables[] = $row[0];
            }
            if ($mysqli) {
                $result->free();
            }
            
            // التحقق من وجود جداول إضافية قد لا تظهر في SHOW FULL TABLES
            // (بعض الخوادم قد لا تدعم Table_type بشكل صحيح)
            $allTablesResult = $dbQuery("SHOW TABLES");
            $allTables = [];
            while ($row = $dbFetch($allTablesResult, $pdo ? PDO::FETCH_NUM : 'NUM')) {
                $allTables[] = $row[0];
            }
            if ($mysqli && $allTablesResult) {
                $allTablesResult->free();
            }
            
            // إضافة أي جداول مفقودة (ليست views)
            foreach ($allTables as $tableName) {
                if (!in_array($tableName, $tables)) {
                    // التحقق من أن الجدول ليس view
                    try {
                        $checkResult = $dbQuery("SHOW CREATE TABLE `{$tableName}`");
                        $checkRow = $dbFetch($checkResult);
                        if ($mysqli && $checkResult) {
                            $checkResult->free();
                        }
                        // إذا كان لدينا CREATE TABLE (وليس CREATE VIEW)، أضفه
                        if ($checkRow && isset($checkRow['Create Table'])) {
                            $tables[] = $tableName;
                        }
                    } catch (Exception $e) {
                        // تجاهل الأخطاء في التحقق من الجدول
                    }
                }
            }
        } catch (Exception $e) {
            // في حالة الفشل، استخدام الطريقة البديلة
            error_log("Backup: Error getting tables list, using fallback method: " . $e->getMessage());
            try {
                $result = $dbQuery("SHOW TABLES");
                while ($row = $dbFetch($result, $pdo ? PDO::FETCH_NUM : 'NUM')) {
                    $tables[] = $row[0];
                }
                if ($mysqli) {
                    $result->free();
                }
            } catch (Exception $e2) {
                error_log("Backup: Fallback method also failed: " . $e2->getMessage());
            }
        }
        
        // تسجيل عدد الجداول التي سيتم تصديرها
        error_log("Backup: Found " . count($tables) . " tables to export: " . implode(', ', $tables));
        
        // أيضاً جلب views
        $views = [];
        $result = $dbQuery("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        while ($row = $dbFetch($result, $pdo ? PDO::FETCH_NUM : 'NUM')) {
            $views[] = $row[0];
        }
        if ($mysqli) {
            $result->free();
        }
        
        // تصدير كل جدول (CREATE + بيانات)
        foreach ($tables as $table) {
            try {
                $result = $dbQuery("SHOW CREATE TABLE `{$table}`");
                $cr = $dbFetch($result);
                if ($mysqli) {
                    $result->free();
                }
                $createSql = $cr['Create Table'] ?? $cr['Create View'] ?? null;
                if ($createSql) {
                    fwrite($fh, "-- --------------------------------------------------------\n");
                    fwrite($fh, "-- Table structure for table `{$table}`\n");
                    fwrite($fh, "-- --------------------------------------------------------\n\n");
                    fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($fh, $createSql . ";\n\n");
                } else {
                    error_log("Backup: Warning - Could not get CREATE statement for table: {$table}");
                    // الاستمرار في التصدير حتى لو فشل الحصول على CREATE statement
                    fwrite($fh, "-- --------------------------------------------------------\n");
                    fwrite($fh, "-- Table structure for table `{$table}` (CREATE statement not available)\n");
                    fwrite($fh, "-- --------------------------------------------------------\n\n");
                }
            } catch (Exception $e) {
                error_log("Backup: Error exporting structure for table {$table}: " . $e->getMessage());
                // الاستمرار في التصدير حتى لو فشل تصدير هيكل جدول واحد
                fwrite($fh, "-- --------------------------------------------------------\n");
                fwrite($fh, "-- Error exporting structure for table `{$table}`: " . $e->getMessage() . "\n");
                fwrite($fh, "-- --------------------------------------------------------\n\n");
            }
            
            if (!$exportStructureOnly) {
                try {
                // تصدير البيانات (INSERTs)
                $colResult = $dbQuery("DESCRIBE `{$table}`");
                $cols = [];
                while ($c = $dbFetch($colResult)) {
                    $cols[] = "`" . $c['Field'] . "`";
                }
                if ($mysqli) {
                    $colResult->free();
                }
                $colList = implode(', ', $cols);
                
                $rowCountResult = $dbQuery("SELECT COUNT(*) AS c FROM `{$table}`");
                $rowCountRow = $dbFetch($rowCountResult);
                $rowCount = (int)$rowCountRow['c'];
                if ($mysqli) {
                    $rowCountResult->free();
                }
                if ($rowCount === 0) {
                    fwrite($fh, "-- Empty table `{$table}`\n\n");
                    continue;
                }
                
                $batchSize = 500;
                $offset = 0;
                while ($offset < $rowCount) {
                    if ($pdo) {
                        $select = $pdo->prepare("SELECT * FROM `{$table}` LIMIT :lim OFFSET :off");
                        $select->bindValue(':lim', (int)$batchSize, PDO::PARAM_INT);
                        $select->bindValue(':off', (int)$offset, PDO::PARAM_INT);
                        $select->execute();
                        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $select = $mysqli->prepare("SELECT * FROM `{$table}` LIMIT ? OFFSET ?");
                        $select->bind_param("ii", $batchSize, $offset);
                        $select->execute();
                        $result = $select->get_result();
                        $rows = $dbFetchAll($result);
                        $result->free();
                        $select->close();
                    }
                    if (!$rows) break;
                    
                    $values = [];
                    foreach ($rows as $r) {
                        $escaped = [];
                        foreach ($r as $v) {
                            if ($v === null) {
                                $escaped[] = "NULL";
                            } else {
                                $escaped[] = $dbQuote($v);
                            }
                        }
                        $values[] = "(" . implode(", ", $escaped) . ")";
                    }
                    
                    fwrite($fh, "LOCK TABLES `{$table}` WRITE;\n");
                    fwrite($fh, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");
                    fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n");
                    fwrite($fh, implode(",\n", $values) . ";\n");
                    fwrite($fh, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n");
                    fwrite($fh, "UNLOCK TABLES;\n\n");
                    
                    $offset += $batchSize;
                }
                } catch (Exception $e) {
                    error_log("Backup: Error exporting data for table {$table}: " . $e->getMessage());
                    // الاستمرار في التصدير حتى لو فشل تصدير بيانات جدول واحد
                    fwrite($fh, "-- Error exporting data for table `{$table}`: " . $e->getMessage() . "\n\n");
                }
            }
        }
        
        // تصدير الـ views
        foreach ($views as $view) {
            $result = $dbQuery("SHOW CREATE VIEW `{$view}`");
            $cr = $dbFetch($result);
            if ($mysqli) {
                $result->free();
            }
            if (!empty($cr['Create View'])) {
                fwrite($fh, "-- --------------------------------------------------------\n");
                fwrite($fh, "-- View structure for view `{$view}`\n");
                fwrite($fh, "-- --------------------------------------------------------\n\n");
                fwrite($fh, "DROP VIEW IF EXISTS `{$view}`;\n");
                fwrite($fh, $cr['Create View'] . ";\n\n");
            }
        }
        
        // تصدير الإجراءات المخزنة (Stored Procedures)
        try {
            $procedures = [];
            $result = $dbQuery("SHOW PROCEDURE STATUS WHERE Db = " . $dbQuote($dbName));
            while ($row = $dbFetch($result)) {
                $procedures[] = $row['Name'];
            }
            if ($mysqli && $result) {
                $result->free();
            }
            
            foreach ($procedures as $proc) {
                $result = $dbQuery("SHOW CREATE PROCEDURE `{$proc}`");
                $cr = $dbFetch($result);
                if ($mysqli) {
                    $result->free();
                }
                if (!empty($cr['Create Procedure'])) {
                    fwrite($fh, "-- --------------------------------------------------------\n");
                    fwrite($fh, "-- Procedure structure for procedure `{$proc}`\n");
                    fwrite($fh, "-- --------------------------------------------------------\n\n");
                    fwrite($fh, "DROP PROCEDURE IF EXISTS `{$proc}`;\n");
                    // إزالة DEFINER من الإجراء لتجنب مشاكل الصلاحيات
                    $procSql = preg_replace('/DEFINER\s*=\s*[^\s]+\s+/i', '', $cr['Create Procedure']);
                    fwrite($fh, $procSql . ";\n\n");
                }
            }
        } catch (Exception $e) {
            fwrite($fh, "-- Error exporting procedures: " . $e->getMessage() . "\n\n");
        }
        
        // تصدير الدوال (Functions)
        try {
            $functions = [];
            $result = $dbQuery("SHOW FUNCTION STATUS WHERE Db = " . $dbQuote($dbName));
            while ($row = $dbFetch($result)) {
                $functions[] = $row['Name'];
            }
            if ($mysqli && $result) {
                $result->free();
            }
            
            foreach ($functions as $func) {
                $result = $dbQuery("SHOW CREATE FUNCTION `{$func}`");
                $cr = $dbFetch($result);
                if ($mysqli) {
                    $result->free();
                }
                if (!empty($cr['Create Function'])) {
                    fwrite($fh, "-- --------------------------------------------------------\n");
                    fwrite($fh, "-- Function structure for function `{$func}`\n");
                    fwrite($fh, "-- --------------------------------------------------------\n\n");
                    fwrite($fh, "DROP FUNCTION IF EXISTS `{$func}`;\n");
                    // إزالة DEFINER من الدالة لتجنب مشاكل الصلاحيات
                    $funcSql = preg_replace('/DEFINER\s*=\s*[^\s]+\s+/i', '', $cr['Create Function']);
                    fwrite($fh, $funcSql . ";\n\n");
                }
            }
        } catch (Exception $e) {
            fwrite($fh, "-- Error exporting functions: " . $e->getMessage() . "\n\n");
        }
        
        // تصدير المشغلات (Triggers)
        try {
            $triggers = [];
            $result = $dbQuery("SHOW TRIGGERS");
            while ($row = $dbFetch($result)) {
                $triggers[] = $row['Trigger'];
            }
            if ($mysqli && $result) {
                $result->free();
            }
            
            foreach ($triggers as $trigger) {
                $result = $dbQuery("SHOW CREATE TRIGGER `{$trigger}`");
                $cr = $dbFetch($result);
                if ($mysqli) {
                    $result->free();
                }
                if (!empty($cr['SQL Original Statement'])) {
                    fwrite($fh, "-- --------------------------------------------------------\n");
                    fwrite($fh, "-- Trigger structure for trigger `{$trigger}`\n");
                    fwrite($fh, "-- --------------------------------------------------------\n\n");
                    fwrite($fh, "DROP TRIGGER IF EXISTS `{$trigger}`;\n");
                    // إزالة DEFINER من المشغل لتجنب مشاكل الصلاحيات
                    $triggerSql = preg_replace('/DEFINER\s*=\s*[^\s]+\s+/i', '', $cr['SQL Original Statement']);
                    fwrite($fh, $triggerSql . ";\n\n");
                }
            }
        } catch (Exception $e) {
            fwrite($fh, "-- Error exporting triggers: " . $e->getMessage() . "\n\n");
        }
        
        // تصدير الأحداث (Events)
        try {
            $events = [];
            $result = $dbQuery("SHOW EVENTS");
            while ($row = $dbFetch($result)) {
                $events[] = $row['Name'];
            }
            if ($mysqli && $result) {
                $result->free();
            }
            
            foreach ($events as $event) {
                $result = $dbQuery("SHOW CREATE EVENT `{$event}`");
                $cr = $dbFetch($result);
                if ($mysqli) {
                    $result->free();
                }
                if (!empty($cr['Create Event'])) {
                    fwrite($fh, "-- --------------------------------------------------------\n");
                    fwrite($fh, "-- Event structure for event `{$event}`\n");
                    fwrite($fh, "-- --------------------------------------------------------\n\n");
                    fwrite($fh, "DROP EVENT IF EXISTS `{$event}`;\n");
                    // إزالة DEFINER من الحدث لتجنب مشاكل الصلاحيات
                    $eventSql = preg_replace('/DEFINER\s*=\s*[^\s]+\s+/i', '', $cr['Create Event']);
                    fwrite($fh, $eventSql . ";\n\n");
                }
            }
        } catch (Exception $e) {
            fwrite($fh, "-- Error exporting events: " . $e->getMessage() . "\n\n");
        }
        
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($fh);
            
            // حفظ نوع الاتصال قبل إغلاقه
            $connectionMethod = $pdo ? 'pdo' : 'mysqli';
            
            // ضغط الملف المؤقت إلى gzip النهائي
            $fpIn = fopen($tmpSql, 'rb');
            $fpOut = gzopen($fullPathGz, 'wb9');
            if ($fpIn && $fpOut) {
                while (!feof($fpIn)) {
                    gzwrite($fpOut, fread($fpIn, 1024 * 512));
                }
                fclose($fpIn);
                gzclose($fpOut);
                @unlink($tmpSql);
                
                return [
                    'success' => true,
                    'file_path' => $fullPathGz,
                    'filename' => $filenameGz,
                    'method' => $connectionMethod
                ];
            } else {
                if ($fpIn) fclose($fpIn);
                if ($fpOut) gzclose($fpOut);
                return ['success' => false, 'message' => "فشل أثناء ضغط الملف إلى gzip."];
            }
        } finally {
            // إغلاق الاتصال في جميع الحالات (نجاح أو فشل)
            if ($pdo) {
                $pdo = null;
            }
            if ($mysqli) {
                try {
                    $mysqli->close();
                } catch (Exception $e) {
                    error_log("Error closing mysqli connection in bk.php: " . $e->getMessage());
                }
            }
            // إغلاق ملف SQL إذا كان لا يزال مفتوحاً
            if (isset($fh) && is_resource($fh)) {
                @fclose($fh);
            }
        }
    }
}

// ---------- الكود التنفيذي عند الاستدعاء المباشر (CLI أو المتصفح) ----------
// يتم تنفيذ هذا الكود فقط عند استدعاء الملف مباشرة وليس كدالة
if (php_sapi_name() === 'cli' || !function_exists('createDatabaseBackup')) {
    // إعدادات الاتصال
    if (file_exists(__DIR__ . '/includes/config.php')) {
        if (!defined('ACCESS_ALLOWED')) {
            define('ACCESS_ALLOWED', true);
        }
        require_once __DIR__ . '/includes/config.php';
        
        $dbHost = defined('DB_HOST') ? DB_HOST : 'sql110.infinityfree.com';
        $dbPort = defined('DB_PORT') ? DB_PORT : '3306';
        $dbName = defined('DB_NAME') ? DB_NAME : 'if0_40278066_co_db';
        $dbUser = defined('DB_USER') ? DB_USER : 'if0_40278066';
        $dbPass = defined('DB_PASS') ? DB_PASS : 'Osama744';
    } else {
        $dbHost = 'sql110.infinityfree.com';
        $dbPort = '3306';
        $dbName = 'if0_40278066_co_db';
        $dbUser = 'if0_40278066';
        $dbPass = 'Osama744';
    }
    
    // إعدادات التصدير
    $exportStructureOnly = false;
    $backupDir = defined('BASE_PATH') ? BASE_PATH . '/backups' : __DIR__ . '/backups';
    $useMysqldumpIfAvailable = true;
    
    // إنشاء مجلد النسخ الاحتياطي
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            die("فشل في إنشاء مجلد النسخ الاحتياطي: $backupDir\n");
        }
    }
    
    // استدعاء الدالة
    $result = createBackupUsingBkScript($backupDir, $exportStructureOnly, $useMysqldumpIfAvailable);
    
    if ($result && $result['success']) {
        echo "النسخة الاحتياطية (" . ($result['method'] ?? 'unknown') . ") حفظت: " . $result['file_path'] . "\n";
        exit(0);
    } else {
        echo "فشل إنشاء النسخة الاحتياطية: " . ($result['message'] ?? 'سبب غير معروف') . "\n";
        exit(1);
    }
}

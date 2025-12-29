<?php
/**
 * نظام النسخ الاحتياطي لقاعدة البيانات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * إنشاء نسخة احتياطية لقاعدة البيانات
 * يستخدم bk.php لإنشاء النسخة الاحتياطية (يشمل كل جداول قاعدة البيانات)
 */
function createDatabaseBackup($backupType = 'daily', $userId = null) {
    set_time_limit(0);

    try {
        if (!defined('BASE_PATH')) {
            throw new Exception("BASE_PATH غير معرف. تحقق من ملف config.php");
        }

        $db = db();
        // إزالة الشرطة المائلة الأخيرة من المسار
        $backupDir = rtrim(BASE_PATH . '/backups', '/\\');

        if (!file_exists($backupDir)) {
            if (!@mkdir($backupDir, 0777, true)) {
                $error = error_get_last();
                throw new Exception("فشل إنشاء مجلد النسخ الاحتياطية: " . $backupDir . " - " . ($error['message'] ?? 'سبب غير معروف'));
            }
            @chmod($backupDir, 0777);
        }

        if (!is_dir($backupDir)) {
            throw new Exception("المجلد غير موجود: " . $backupDir);
        }

        if (!is_readable($backupDir)) {
            @chmod($backupDir, 0777);
            if (!is_readable($backupDir)) {
                $perms = substr(sprintf('%o', fileperms($backupDir)), -4);
                throw new Exception("المجلد غير قابل للقراءة: " . $backupDir . " - الصلاحيات الحالية: " . $perms);
            }
        }

        if (!is_writable($backupDir)) {
            @chmod($backupDir, 0777);
            if (!is_writable($backupDir)) {
                $perms = substr(sprintf('%o', fileperms($backupDir)), -4);
                throw new Exception("المجلد غير قابل للكتابة: " . $backupDir . " - الصلاحيات الحالية: " . $perms . " - حاول تغيير صلاحيات المجلد إلى 777");
            }
        }

        // استخدام bk.php لإنشاء النسخة الاحتياطية (يشمل كل جداول قاعدة البيانات)
        $bkScriptPath = BASE_PATH . '/bk.php';
        if (!file_exists($bkScriptPath)) {
            throw new Exception("ملف bk.php غير موجود: " . $bkScriptPath);
        }

        // تحميل bk.php للحصول على دالة createBackupUsingBkScript
        require_once $bkScriptPath;

        if (!function_exists('createBackupUsingBkScript')) {
            throw new Exception("دالة createBackupUsingBkScript غير موجودة في bk.php");
        }

        // استدعاء دالة النسخ الاحتياطي من bk.php
        // استخدام false للتصدير الكامل (هيكل + بيانات) و true لاستخدام mysqldump إن كان متاحاً
        $backupResult = createBackupUsingBkScript($backupDir, false, true);

        if (!$backupResult || !$backupResult['success']) {
            $errorMessage = $backupResult['message'] ?? 'فشل إنشاء النسخة الاحتياطية';
            throw new Exception($errorMessage);
        }

        $filePath = $backupResult['file_path'];
        $filename = $backupResult['filename'];

        // الملف تم إنشاؤه بواسطة bk.php (مضغوط بالفعل بصيغة .gz)

        if (!file_exists($filePath)) {
            throw new Exception('ملف النسخة الاحتياطية غير موجود بعد الحفظ: ' . $filePath);
        }

        $finalFileSize = filesize($filePath);
        if ($finalFileSize === false || $finalFileSize === 0) {
            throw new Exception('حجم ملف النسخة الاحتياطية غير صحيح');
        }

        $db->execute(
            "INSERT INTO backups (filename, file_path, file_size, backup_type, status, created_by) 
             VALUES (?, ?, ?, ?, 'completed', ?)",
            [$filename, $filePath, $finalFileSize, $backupType, $userId]
        );

        $maxBackupsToKeep = 9;
        deleteOldBackups($backupType, $maxBackupsToKeep);
        enforceBackupLimit($maxBackupsToKeep);

        // إرسال النسخة الاحتياطية عبر تليجرام للنسخ اليدوية (إن كان تليجرام مُعد)
        if ($backupType === 'manual') {
            try {
                if (file_exists(__DIR__ . '/simple_telegram.php')) {
                    require_once __DIR__ . '/simple_telegram.php';
                    
                    if (function_exists('isTelegramConfigured') && isTelegramConfigured() && function_exists('sendTelegramFile')) {
                        $captionLines = [
                            '🗃️ نسخة احتياطية يدوية',
                            'التاريخ: ' . date('Y-m-d H:i:s'),
                            'الملف: ' . $filename,
                            'الحجم: ' . formatFileSize($finalFileSize)
                        ];
                        if ($userId) {
                            try {
                                $user = $db->queryOne("SELECT username FROM users WHERE id = ?", [$userId]);
                                if ($user && !empty($user['username'])) {
                                    $captionLines[] = 'أنشأها: ' . $user['username'];
                                }
                            } catch (Exception $e) {
                                // تجاهل خطأ جلب اسم المستخدم
                            }
                        }
                        $caption = implode("\n", $captionLines);
                        
                        $sendResult = sendTelegramFile($filePath, $caption);
                        if ($sendResult === false) {
                            error_log('Failed to send manual backup to Telegram: ' . $filename);
                        }
                    }
                }
            } catch (Exception $telegramError) {
                // لا نريد أن نفشل إنشاء النسخة الاحتياطية إذا فشل الإرسال عبر تليجرام
                error_log('Error sending manual backup to Telegram: ' . $telegramError->getMessage());
            }
        }

        return [
            'success' => true,
            'filename' => $filename,
            'file_path' => $filePath,
            'file_size' => $finalFileSize,
            'message' => 'تم إنشاء النسخة الاحتياطية بنجاح'
        ];
    } catch (Exception $e) {
        // حذف الملف في حالة الخطأ
        if (isset($filePath) && file_exists($filePath)) {
            @unlink($filePath);
        }

        if (isset($db)) {
            try {
                $db->execute(
                    "INSERT INTO backups (filename, file_path, backup_type, status, error_message, created_by) 
                     VALUES (?, ?, ?, 'failed', ?, ?)",
                    ['', '', $backupType, $e->getMessage(), $userId]
                );
            } catch (Exception $dbError) {
                error_log('Failed to log backup error: ' . $dbError->getMessage());
            }
        }

        return [
            'success' => false,
            'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()
        ];
    }
}

/**
 * ضغط ملف النسخة الاحتياطية
 */
function compressBackup($filePath) {
    if (!function_exists('gzencode')) {
        return false;
    }
    
    $compressedPath = $filePath . '.gz';
    $content = file_get_contents($filePath);
    $compressed = gzencode($content, 9);
    
    if (file_put_contents($compressedPath, $compressed)) {
        return $compressedPath;
    }
    
    return false;
}

/**
 * حذف النسخ الاحتياطية القديمة
 */
function deleteOldBackups($backupType = 'daily', $keepCount = 30) {
    try {
        $db = db();
        
        // الحصول على النسخ القديمة
        $oldBackups = $db->query(
            "SELECT id, file_path FROM backups 
             WHERE backup_type = ? AND status IN ('completed', 'success')
             ORDER BY created_at DESC
             LIMIT 1000 OFFSET ?",
            [$backupType, $keepCount]
        );
        
        foreach ($oldBackups as $backup) {
            // حذف الملف
            if (file_exists($backup['file_path'])) {
                @unlink($backup['file_path']);
            }
            
            // حذف من قاعدة البيانات
            $db->execute("DELETE FROM backups WHERE id = ?", [$backup['id']]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error deleting old backups: " . $e->getMessage());
        return false;
    }
}

/**
 * ضمان عدم تجاوز عدد النسخ الاحتياطية للحد المسموح
 */
function enforceBackupLimit($maxCount = 9) {
    if ($maxCount < 1) {
        return true;
    }

    try {
        $db = db();

        $totalRow = $db->queryOne(
            "SELECT COUNT(*) AS total FROM backups WHERE status IN ('completed', 'success')"
        );

        $total = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;

        if ($total < $maxCount + 1) {
            return true;
        }

        $toDelete = $total - $maxCount;
        if ($toDelete <= 0) {
            return true;
        }

        $oldBackups = $db->query(
            "SELECT id, file_path FROM backups WHERE status IN ('completed', 'success') ORDER BY created_at ASC LIMIT " . (int) $toDelete
        );

        foreach ($oldBackups as $backup) {
            if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
                @unlink($backup['file_path']);
            }

            if (!empty($backup['id'])) {
                $db->execute("DELETE FROM backups WHERE id = ?", [(int) $backup['id']]);
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Error enforcing backup limit: " . $e->getMessage());
        return false;
    }
}

/**
 * استعادة قاعدة البيانات من نسخة احتياطية
 */
function restoreDatabase($backupId) {
    set_time_limit(0);

    try {
        $db = db();

        $backup = $db->queryOne(
            "SELECT * FROM backups WHERE id = ? AND status IN ('completed', 'success')",
            [$backupId]
        );

        if (!$backup) {
            throw new Exception('النسخة الاحتياطية غير موجودة');
        }

        if (!file_exists($backup['file_path'])) {
            throw new Exception('ملف النسخة الاحتياطية غير موجود');
        }

        $rawContent = file_get_contents($backup['file_path']);
        if ($rawContent === false) {
            throw new Exception('تعذر قراءة ملف النسخة الاحتياطية');
        }

        if (pathinfo($backup['file_path'], PATHINFO_EXTENSION) === 'gz') {
            $decoded = gzdecode($rawContent);
            if ($decoded === false) {
                throw new Exception('تعذر فك ضغط ملف النسخة الاحتياطية');
            }
            $rawContent = $decoded;
        }

        if (trim($rawContent) === '') {
            throw new Exception('ملف النسخة الاحتياطية فارغ');
        }

        $statements = splitSqlStatements($rawContent);
        if (empty($statements)) {
            throw new Exception('تعذر تحليل استعلامات النسخة الاحتياطية');
        }

        $connection = getDB();
        if (!$connection) {
            throw new Exception('فشل الاتصال بقاعدة البيانات');
        }
        
        $connection->set_charset('utf8mb4');

        $connection->autocommit(false);
        $connection->query('SET FOREIGN_KEY_CHECKS = 0');
        $connection->query('SET UNIQUE_CHECKS = 0');
        $connection->query('SET SQL_MODE = ""');

        $executedCount = 0;
        $skippedCount = 0;
        
        try {
            foreach ($statements as $index => $statement) {
                $statement = trim($statement);
                if (empty($statement) || $statement === ';') {
                    $skippedCount++;
                    continue;
                }

                $normalized = strtoupper(ltrim($statement));
                
                // تخطي التعليقات والبيانات الوصفية
                if (
                    strpos($normalized, '--') === 0 ||
                    strpos($normalized, '/*') === 0 ||
                    strpos($normalized, '#') === 0
                ) {
                    $skippedCount++;
                    continue;
                }

                // تخطي أوامر الإدارة
                if (
                    strpos($normalized, 'START TRANSACTION') === 0 ||
                    strpos($normalized, 'COMMIT') === 0 ||
                    strpos($normalized, 'ROLLBACK') === 0 ||
                    strpos($normalized, 'SET AUTOCOMMIT') === 0 ||
                    strpos($normalized, 'LOCK TABLES') === 0 ||
                    strpos($normalized, 'UNLOCK TABLES') === 0 ||
                    strpos($normalized, 'SET NAMES') === 0 ||
                    strpos($normalized, 'SET CHARACTER SET') === 0 ||
                    strpos($normalized, 'SET FOREIGN_KEY_CHECKS') === 0 ||
                    strpos($normalized, 'SET UNIQUE_CHECKS') === 0 ||
                    strpos($normalized, 'SET SQL_MODE') === 0 ||
                    strpos($normalized, 'DELIMITER') === 0
                ) {
                    $skippedCount++;
                    continue;
                }

                // تنفيذ الاستعلام
                if ($connection->query($statement) === false) {
                    $error = $connection->error ?: 'خطأ غير معروف أثناء تنفيذ الاستعلام';
                    $errorCode = $connection->errno ?: 0;
                    throw new Exception("خطأ في الاستعلام #" . ($index + 1) . ": " . $error . " (كود الخطأ: " . $errorCode . ")");
                }
                
                $executedCount++;
            }

            $connection->commit();
        } catch (Exception $executionError) {
            try {
                $connection->rollback();
            } catch (Exception $rollbackError) {
                error_log('Failed to rollback: ' . $rollbackError->getMessage());
            }
            throw $executionError;
        } finally {
            try {
                $connection->query('SET FOREIGN_KEY_CHECKS = 1');
                $connection->query('SET UNIQUE_CHECKS = 1');
                $connection->autocommit(true);
            } catch (Exception $cleanupError) {
                error_log('Failed to restore database settings: ' . $cleanupError->getMessage());
            }
        }

        return [
            'success' => true,
            'message' => 'تم استعادة قاعدة البيانات بنجاح'
        ];
    } catch (Exception $e) {
        if (isset($connection) && $connection instanceof mysqli) {
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
            $connection->query('SET UNIQUE_CHECKS = 1');
            $connection->autocommit(true);
        }

        return [
            'success' => false,
            'message' => 'فشل استعادة النسخة الاحتياطية: ' . $e->getMessage()
        ];
    }
}

/**
 * الحصول على قائمة النسخ الاحتياطية
 */
function getBackups($limit = 50, $backupType = null) {
    $db = db();
    
    $sql = "SELECT b.*, u.username as created_by_name 
            FROM backups b
            LEFT JOIN users u ON b.created_by = u.id";
    
    $params = [];
    
    if ($backupType) {
        $sql .= " WHERE b.backup_type = ?";
        $params[] = $backupType;
    }
    
    $sql .= " ORDER BY b.created_at DESC LIMIT ?";
    $params[] = $limit;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على إحصائيات النسخ الاحتياطية
 */
function getBackupStats() {
    $db = db();
    
    $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'total_size' => 0,
        'daily' => 0,
        'weekly' => 0,
        'monthly' => 0,
        'manual' => 0
    ];
    
    $result = $db->query(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('completed', 'success') THEN 1 ELSE 0 END) as success,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(file_size) as total_size,
            SUM(CASE WHEN backup_type = 'daily' THEN 1 ELSE 0 END) as daily,
            SUM(CASE WHEN backup_type = 'weekly' THEN 1 ELSE 0 END) as weekly,
            SUM(CASE WHEN backup_type = 'monthly' THEN 1 ELSE 0 END) as monthly,
            SUM(CASE WHEN backup_type = 'manual' THEN 1 ELSE 0 END) as manual
         FROM backups"
    );
    
    if (!empty($result)) {
        $stats = array_merge($stats, $result[0]);
    }
    
    return $stats;
}

/**
 * تنسيق حجم الملف
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

function formatInsertRow(\mysqli $connection, array $columns, array $row): string {
    $values = [];

    foreach ($columns as $column) {
        if (!array_key_exists($column, $row) || $row[$column] === null) {
            $values[] = 'NULL';
            continue;
        }

        $value = $row[$column];

        if (is_bool($value)) {
            $values[] = $value ? '1' : '0';
            continue;
        }

        $values[] = "'" . $connection->real_escape_string((string) $value) . "'";
    }

    return '(' . implode(',', $values) . ')';
}

function splitSqlStatements(string $sqlContent): array {
    $statements = [];
    $length = strlen($sqlContent);
    $buffer = '';

    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sqlContent[$i];
        $nextChar = $i + 1 < $length ? $sqlContent[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $nextChar === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($char === '-' && $nextChar === '-' && ($i + 2 < $length && ($sqlContent[$i + 2] === ' ' || $sqlContent[$i + 2] === "\t"))) {
                $inLineComment = true;
                $i++;
                continue;
            }

            if ($char === '#') {
                $inLineComment = true;
                continue;
            }

            if ($char === '/' && $nextChar === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === '\\' && ($inSingle || $inDouble)) {
            $buffer .= $char;
            if ($i + 1 < $length) {
                $buffer .= $sqlContent[++$i];
            }
            continue;
        }

        if ($char === "'" && !$inDouble && !$inBacktick) {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
        } elseif ($char === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement . ';';
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}


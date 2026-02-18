<?php
/**
 * تنظيف سجل خط سير السائقين: حذف تلقائي لأي يوم مخزن له خط سير مر عليه أكثر من أسبوع
 * يُشغّل مرة واحدة يومياً عند استخدام API تتبع السائقين
 */

if (!defined('ACCESS_ALLOWED')) {
    return;
}

if (!function_exists('cleanupOldDriverLocationHistory')) {
    /**
     * حذف سجلات خط السير الأقدم من عدد الأيام المحدد (افتراضي: 7 أيام)
     * @param int $daysOld عدد الأيام - يحذف الأيام التي مر عليها أكثر من هذا العدد
     * @return array ['deleted' => int, 'run' => bool] عدد السجلات المحذوفة، وهل تم تشغيل التنظيف
     */
    function cleanupOldDriverLocationHistory($daysOld = 7) {
        $result = ['deleted' => 0, 'run' => false];
        try {
            $db = db();
            $tableExists = $db->queryOne("SHOW TABLES LIKE 'driver_location_history'");
            if (empty($tableExists)) {
                return $result;
            }

            $daysOld = max(1, (int) $daysOld);

            $countBefore = (int) $db->queryOne(
                "SELECT COUNT(*) AS c FROM driver_location_history WHERE recorded_at < DATE_SUB(CURDATE(), INTERVAL ? DAY)",
                [$daysOld]
            )['c'];

            $db->execute(
                "DELETE FROM driver_location_history WHERE recorded_at < DATE_SUB(CURDATE(), INTERVAL ? DAY)",
                [$daysOld]
            );

            $result['deleted'] = $countBefore;
            $result['run'] = true;
            if ($countBefore > 0) {
                error_log("Driver location cleanup: deleted {$countBefore} records older than {$daysOld} days");
            }
        } catch (Throwable $e) {
            error_log('Driver location cleanup error: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('runDriverLocationCleanupOnceDaily')) {
    /**
     * تشغيل تنظيف خط السير مرة واحدة يومياً (عبر system_daily_jobs)
     */
    function runDriverLocationCleanupOnceDaily() {
        $jobKey = 'driver_location_history_cleanup';
        try {
            $db = db();
            $tableExists = $db->queryOne("SHOW TABLES LIKE 'system_daily_jobs'");
            if (empty($tableExists)) {
                $db->execute("
                    CREATE TABLE IF NOT EXISTS `system_daily_jobs` (
                      `job_key` varchar(120) NOT NULL,
                      `last_sent_at` datetime DEFAULT NULL,
                      `last_file_path` varchar(512) DEFAULT NULL,
                      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (`job_key`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            $row = $db->queryOne(
                "SELECT last_sent_at FROM system_daily_jobs WHERE job_key = ?",
                [$jobKey]
            );
            $today = date('Y-m-d');
            if ($row && !empty($row['last_sent_at'])) {
                $lastRun = date('Y-m-d', strtotime($row['last_sent_at']));
                if ($lastRun === $today) {
                    return ['deleted' => 0, 'run' => false];
                }
            }

            $result = cleanupOldDriverLocationHistory(7);

            if ($result['run']) {
                if ($row) {
                    $db->execute(
                        "UPDATE system_daily_jobs SET last_sent_at = NOW(), updated_at = NOW() WHERE job_key = ?",
                        [$jobKey]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO system_daily_jobs (job_key, last_sent_at) VALUES (?, NOW())",
                        [$jobKey]
                    );
                }
            }
            return $result;
        } catch (Throwable $e) {
            error_log('runDriverLocationCleanupOnceDaily error: ' . $e->getMessage());
            return ['deleted' => 0, 'run' => false];
        }
    }
}

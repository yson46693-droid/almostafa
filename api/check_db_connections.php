<?php
/**
 * Script للتحقق من اتصالات قاعدة البيانات وإغلاق الاتصالات المعلقة
 * يمكن تشغيله عبر cron job أو يدوياً
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    
    $db = db();
    $connection = $db->getConnection();
    
    // جلب معلومات الاتصالات الحالية
    $connections = $connection->query("
        SELECT 
            Id as connection_id,
            User as username,
            Host as host,
            db as database_name,
            Command as command,
            Time as time_seconds,
            State as state,
            Info as query_info
        FROM information_schema.PROCESSLIST
        WHERE User = '" . $connection->real_escape_string(DB_USER) . "'
        ORDER BY Time DESC
    ");
    
    $connectionList = [];
    $totalConnections = 0;
    $sleepingConnections = 0;
    $longRunningConnections = 0;
    
    if ($connections instanceof mysqli_result) {
        while ($row = $connections->fetch_assoc()) {
            $totalConnections++;
            if ($row['command'] === 'Sleep') {
                $sleepingConnections++;
            }
            if ($row['time_seconds'] > 60) {
                $longRunningConnections++;
            }
            $connectionList[] = $row;
        }
        $connections->free();
    }
    
    // جلب حد الاتصالات
    $maxConnections = $connection->query("SHOW VARIABLES LIKE 'max_connections'");
    $maxUserConnections = $connection->query("SHOW VARIABLES LIKE 'max_user_connections'");
    
    $maxConnectionsValue = 0;
    $maxUserConnectionsValue = 0;
    
    if ($maxConnections instanceof mysqli_result) {
        $row = $maxConnections->fetch_assoc();
        $maxConnectionsValue = (int)($row['Value'] ?? 0);
        $maxConnections->free();
    }
    
    if ($maxUserConnections instanceof mysqli_result) {
        $row = $maxUserConnections->fetch_assoc();
        $maxUserConnectionsValue = (int)($row['Value'] ?? 0);
        $maxUserConnections->free();
    }
    
    // حساب نسبة الاستخدام
    $usagePercent = 0;
    if ($maxUserConnectionsValue > 0) {
        $usagePercent = round(($totalConnections / $maxUserConnectionsValue) * 100, 2);
    }
    
    $response = [
        'success' => true,
        'stats' => [
            'total_connections' => $totalConnections,
            'sleeping_connections' => $sleepingConnections,
            'long_running_connections' => $longRunningConnections,
            'max_connections' => $maxConnectionsValue,
            'max_user_connections' => $maxUserConnectionsValue,
            'usage_percent' => $usagePercent,
            'warning' => $usagePercent > 80 ? 'High connection usage' : null
        ],
        'connections' => $connectionList
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error checking connections: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


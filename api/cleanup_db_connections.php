<?php
/**
 * Script لإغلاق الاتصالات المعلقة القديمة (أكثر من 5 دقائق)
 * يجب تشغيله بحذر - سيغلق الاتصالات النشطة
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');

// التحقق من الصلاحيات (يمكن تخصيصه)
$allowedToCleanup = false;
$token = null;

// التحقق من وجود token في GET أو POST فقط
// لا نستخدم التحقق من تسجيل الدخول لأن قاعدة البيانات قد تكون معطلة
$token = null;
if (isset($_GET['token'])) {
    $token = $_GET['token'];
} elseif (isset($_POST['token'])) {
    $token = $_POST['token'];
}

// التحقق من صحة الـ token فقط
if ($token !== '1') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Provide valid token.',
        'help' => [
            'method' => 'Add token as GET parameter: ?token=cleanup_db_connections_2024',
            'example_url' => 'api/cleanup_db_connections.php?token=cleanup_db_connections_2024'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    
    $db = db();
    $connection = $db->getConnection();
    
    // جلب الاتصالات المعلقة (Sleep) التي تستغرق أكثر من 60 ثانية (1 دقيقة) بدلاً من 300
    $connections = $connection->query("
        SELECT Id as connection_id, User as username, Time as time_seconds
        FROM information_schema.PROCESSLIST
        WHERE User = '" . $connection->real_escape_string(DB_USER) . "'
        AND Command = 'Sleep'
        AND Time > 60
        AND Id != CONNECTION_ID()
    ");
    
    $killedConnections = [];
    $errors = [];
    
    if ($connections instanceof mysqli_result) {
        while ($row = $connections->fetch_assoc()) {
            $connectionId = (int)$row['connection_id'];
            try {
                $killResult = $connection->query("KILL " . $connectionId);
                if ($killResult) {
                    $killedConnections[] = [
                        'connection_id' => $connectionId,
                        'time_seconds' => (int)$row['time_seconds']
                    ];
                } else {
                    $errors[] = "Failed to kill connection ID: " . $connectionId;
                }
            } catch (Exception $e) {
                $errors[] = "Error killing connection ID {$connectionId}: " . $e->getMessage();
            }
        }
        $connections->free();
    }
    
    $response = [
        'success' => true,
        'killed_connections' => count($killedConnections),
        'connections' => $killedConnections,
        'errors' => $errors
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error cleaning up connections: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


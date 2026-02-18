<?php
/**
 * API: تحديث واسترجاع مواقع السائقين المباشرة وخطوط السير
 *
 * للسائق: POST لتحديث الموقع
 * للمدير/المحاسب: GET live, status, route
 */

header('Content-Type: application/json; charset=utf-8');
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = getCurrentUser();
if (!$currentUser || empty($currentUser['id'])) {
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = strtolower(trim($currentUser['role'] ?? ''));
$db = db();

// --- POST: تحديث الموقع (السائق فقط) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'driver') {
        echo json_encode(['success' => false, 'message' => 'المسموح للسائقين فقط بتحديث الموقع'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $inputData = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $rawInput = file_get_contents('php://input');
        $inputData = json_decode($rawInput, true) ?? [];
    } else {
        $inputData = $_POST;
    }

    $latitude = isset($inputData['latitude']) ? (float) $inputData['latitude'] : null;
    $longitude = isset($inputData['longitude']) ? (float) $inputData['longitude'] : null;

    if ($latitude === null || $longitude === null ||
        $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        echo json_encode(['success' => false, 'message' => 'إحداثيات غير صالحة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $today = date('Y-m-d');
        $db->execute(
            "INSERT INTO driver_live_location (user_id, latitude, longitude, updated_at, is_online) 
             VALUES (?, ?, ?, NOW(), 1)
             ON DUPLICATE KEY UPDATE 
               latitude = VALUES(latitude), 
               longitude = VALUES(longitude), 
               updated_at = NOW(), 
               is_online = 1",
            [$currentUser['id'], $latitude, $longitude]
        );
        $db->execute(
            "INSERT INTO driver_location_history (user_id, latitude, longitude, recorded_at) VALUES (?, ?, ?, ?)",
            [$currentUser['id'], $latitude, $longitude, $today]
        );
        echo json_encode(['success' => true, 'message' => 'تم تحديث الموقع'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('Driver location update error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء حفظ الموقع'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- GET: استرجاع البيانات (المدير والمحاسب والمطور) ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير مدعومة'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($role, ['manager', 'accountant', 'developer'], true)) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح بالوصول'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim($_GET['action'] ?? 'live');

// التحقق من وجود الجداول
$tablesExist = $db->queryOne("SHOW TABLES LIKE 'driver_live_location'");
if (empty($tablesExist)) {
    echo json_encode(['success' => false, 'message' => 'جداول التتبع غير موجودة. قم بتشغيل الـ migration أولاً.'], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
    case 'live':
        // المواقع المباشرة لجميع السائقين
        $rows = $db->queryAll(
            "SELECT d.user_id, d.latitude, d.longitude, d.updated_at, d.is_online,
                    u.full_name, u.username
             FROM driver_live_location d
             JOIN users u ON u.id = d.user_id AND u.role = 'driver' AND u.status = 'active'
             ORDER BY u.full_name"
        );
        echo json_encode(['success' => true, 'locations' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    case 'status':
        // جدول حالة Live للسائقين (يعمل / لا يعمل)
        $drivers = $db->queryAll(
            "SELECT u.id, u.full_name, u.username,
                    d.latitude, d.longitude, d.updated_at, d.is_online,
                    CASE 
                      WHEN d.user_id IS NULL THEN 0
                      WHEN TIMESTAMPDIFF(MINUTE, d.updated_at, NOW()) > 5 THEN 0
                      ELSE 1
                    END AS location_active
             FROM users u
             LEFT JOIN driver_live_location d ON d.user_id = u.id
             WHERE u.role = 'driver' AND u.status = 'active'
             ORDER BY location_active DESC, u.full_name"
        );
        echo json_encode(['success' => true, 'drivers' => $drivers], JSON_UNESCAPED_UNICODE);
        break;

    case 'route':
        // خط سير سائق في يوم معين
        $driverId = (int) ($_GET['driver_id'] ?? 0);
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        if ($driverId <= 0) {
            echo json_encode(['success' => false, 'message' => 'معرف السائق مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $points = $db->queryAll(
            "SELECT latitude, longitude, created_at 
             FROM driver_location_history 
             WHERE user_id = ? AND recorded_at = ? 
             ORDER BY created_at ASC",
            [$driverId, $date]
        );
        $driver = $db->queryOne("SELECT full_name, username FROM users WHERE id = ? AND role = 'driver'", [$driverId]);
        echo json_encode([
            'success' => true,
            'route' => $points,
            'driver' => $driver,
            'date' => $date
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'available_dates':
        // تواريخ متاحة لسائق معين
        $driverId = (int) ($_GET['driver_id'] ?? 0);
        if ($driverId <= 0) {
            echo json_encode(['success' => false, 'message' => 'معرف السائق مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $dates = $db->queryAll(
            "SELECT DISTINCT recorded_at as date FROM driver_location_history 
             WHERE user_id = ? ORDER BY recorded_at DESC LIMIT 90",
            [$driverId]
        );
        echo json_encode(['success' => true, 'dates' => array_column($dates, 'date')], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'إجراء غير معروف'], JSON_UNESCAPED_UNICODE);
}

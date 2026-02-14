<?php
/**
 * API: عرض صورة صيانة السيارة (لتجنب 403 من مجلد uploads المحمي)
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vehicle_maintenance.php';

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}

$currentUser = getCurrentUser();
if (!$currentUser || empty($currentUser['id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}

$role = strtolower(trim($currentUser['role'] ?? ''));
$allowedRoles = ['manager', 'accountant', 'driver', 'developer'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid id';
    exit;
}

$db = db();
$record = $db->queryOne(
    "SELECT id, driver_id, photo_path FROM vehicle_maintenance WHERE id = ?",
    [$id]
);

if (!$record || empty($record['photo_path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

// المدير والمحاسب والمطور يمكنهم رؤية أي صورة؛ السائق فقط سجلاته
if ($role === 'driver' && (int) $record['driver_id'] !== (int) $currentUser['id']) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$absolutePath = getMaintenancePhotoAbsolutePath($record['photo_path']);
if (!$absolutePath || !file_exists($absolutePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found';
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($absolutePath));
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="maintenance-' . $id . '.jpg"');
readfile($absolutePath);
exit;

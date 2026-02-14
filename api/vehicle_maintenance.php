<?php
/**
 * API: تسجيل صيانات السيارة (تغيير الزيت، تفويل البنزين)
 */

header('Content-Type: application/json; charset=utf-8');
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vehicle_maintenance.php';
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
if ($role !== 'driver') {
    echo json_encode(['success' => false, 'message' => 'المسموح للسائقين فقط بإضافة سجلات الصيانة'], JSON_UNESCAPED_UNICODE);
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

$type = trim($inputData['type'] ?? '');
$kmReading = isset($inputData['km_reading']) ? (int) $inputData['km_reading'] : 0;
$photoBase64 = $inputData['photo'] ?? $inputData['photo_base64'] ?? '';
$notes = trim($inputData['notes'] ?? '');

if (!in_array($type, ['oil_change', 'fuel_refill'], true)) {
    echo json_encode(['success' => false, 'message' => 'نوع الصيانة غير صحيح'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($kmReading <= 0) {
    echo json_encode(['success' => false, 'message' => 'يجب إدخال عدد الكيلومترات'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($photoBase64)) {
    echo json_encode(['success' => false, 'message' => 'يجب التقاط صورة بالكاميرا'], JSON_UNESCAPED_UNICODE);
    exit;
}

$vehicle = getDriverVehicle($currentUser['id']);
if (!$vehicle) {
    echo json_encode(['success' => false, 'message' => 'لم يتم العثور على سيارة مرتبطة بحسابك. تواصل مع المدير لربطك بسيارة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

[$absPath, $relativePath] = saveMaintenancePhoto($photoBase64, $currentUser['id'], $type);
if (!$relativePath) {
    echo json_encode(['success' => false, 'message' => 'فشل حفظ الصورة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = saveVehicleMaintenance(
    $vehicle['id'],
    $currentUser['id'],
    $type,
    $kmReading,
    $relativePath,
    $notes ?: null,
    $absPath
);

echo json_encode([
    'success' => $result['success'],
    'message' => $result['message'],
    'id' => $result['id'] ?? null,
    'km_diff' => $result['km_diff'] ?? null,
    'telegram_sent' => $result['telegram_sent'] ?? false,
], JSON_UNESCAPED_UNICODE);

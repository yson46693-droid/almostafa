<?php
/**
 * صفحة صيانات السيارة - تغيير الزيت وتفويل البنزين
 * متاحة للسائق (إضافة) والمدير والمحاسب (عرض فقط)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/vehicle_maintenance.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

$currentUser = getCurrentUser();
$db = db();
$role = strtolower(trim($currentUser['role'] ?? ''));
$isDriver = ($role === 'driver');
$isManager = ($role === 'manager');
$isAccountant = ($role === 'accountant');

$error = '';
$success = '';
$vehicle = null;
if ($isDriver) {
    $vehicle = getDriverVehicle($currentUser['id']);
}

$currentBase = getDashboardUrl($role ?: 'driver');

$filters = [
    'vehicle_id' => isset($_GET['vehicle_id']) ? (int) $_GET['vehicle_id'] : null,
    'type' => isset($_GET['type']) ? $_GET['type'] : null,
    'date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : null,
    'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : null,
];
if ($isDriver && $vehicle) {
    $filters['vehicle_id'] = $vehicle['id'];
}

$pageNum = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$records = getVehicleMaintenanceRecords($filters, $perPage, $offset);
$totalRecords = countVehicleMaintenanceRecords($filters);
$totalPages = $perPage > 0 ? (int) ceil($totalRecords / $perPage) : 1;

$vehicles = [];
if ($isManager || $isAccountant) {
    $vehicles = $db->query("SELECT id, vehicle_number, model FROM vehicles WHERE status = 'active' ORDER BY vehicle_number");
}

/** تنبيه تغيير الزيت: ربط آخر تغيير زيت بآخر تفويل بنزين (فرق 2400–3000 كم) */
$oilChangeAlert = null;
$vehiclesNeedingOilAlert = [];
/** بيانات المتبقي على تغيير الزيت التالي (للمستطيل فوق الجدول) — سيارة واحدة فقط */
$oilRemainingInfo = null;
if ($isDriver && $vehicle) {
    $oilChangeAlert = getVehicleOilChangeAlert($vehicle['id']);
    $oilRemainingInfo = $oilChangeAlert;
}
if ($isManager || $isAccountant) {
    if (!empty($filters['vehicle_id'])) {
        $oilChangeAlert = getVehicleOilChangeAlert($filters['vehicle_id']);
        $oilRemainingInfo = $oilChangeAlert;
    } else {
        $vehiclesNeedingOilAlert = getVehiclesNeedingOilChangeAlert();
    }
}
$oilTargetKm = defined('OIL_CHANGE_ALERT_KM_MAX') ? (int) OIL_CHANGE_ALERT_KM_MAX : 3000;

$apiBase = getRelativeUrl('api/vehicle_maintenance.php');
?>
<div class="container-fluid">
    <div class="page-header mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="mb-0"><i class="bi bi-wrench-adjustable me-2"></i>صيانات السيارة</h2>
        <a href="<?php echo htmlspecialchars($currentBase); ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i>رجوع
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php
    /** تنبيه ذكي: السيارة تحتاج إلى تغيير الزيت في أقرب وقت (الفرق 2400–3000 كم) حسب الدور */
    if ($oilChangeAlert && !empty($oilChangeAlert['need_alert'])):
        $roleLabel = $isDriver ? 'سائق' : ($isManager ? 'مدير' : 'محاسب');
        $msg = $isDriver
            ? 'السيارة تحتاج إلى تغيير الزيت في أقرب وقت (تم قطع ' . number_format($oilChangeAlert['km_since_oil']) . ' كم منذ آخر تغيير زيت).'
            : 'السيارة «' . htmlspecialchars($oilChangeAlert['vehicle_number']) . '» تحتاج إلى تغيير الزيت في أقرب وقت (تم قطع ' . number_format($oilChangeAlert['km_since_oil']) . ' كم منذ آخر تغيير زيت).';
    ?>
    <div class="alert alert-warning alert-dismissible fade show border-warning" role="alert">
        <span class="badge bg-warning text-dark me-2"><?php echo htmlspecialchars($roleLabel); ?></span>
        <i class="bi bi-droplet-fill me-2"></i><?php echo $msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (!empty($vehiclesNeedingOilAlert)): ?>
    <div class="alert alert-warning alert-dismissible fade show border-warning" role="alert">
        <span class="badge bg-warning text-dark me-2"><?php echo $isManager ? 'مدير' : 'محاسب'; ?></span>
        <i class="bi bi-droplet-fill me-2"></i>
        السيارات التالية تحتاج إلى تغيير الزيت في أقرب وقت (الفرق بين آخر تغيير زيت وآخر تفويل بنزين 2400–3000 كم):
        <ul class="mb-0 mt-2">
            <?php foreach ($vehiclesNeedingOilAlert as $v): ?>
                <li><strong><?php echo htmlspecialchars($v['vehicle_number']); ?></strong> — <?php echo number_format($v['km_since_oil']); ?> كم منذ آخر تغيير زيت</li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($isDriver): ?>
        <?php if (!$vehicle): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                لم يتم ربطك بأي سيارة. تواصل مع المدير لربطك بسيارة قبل تسجيل الصيانات.
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <!-- بطاقة تغيير الزيت -->
                <div class="col-md-6">
                    <div class="card shadow-sm h-100 border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-droplet me-2"></i>تغيير الزيت</h5>
                        </div>
                        <div class="card-body">
                            <form id="formOilChange" class="maintenance-form" data-type="oil_change">
                                <input type="hidden" name="photo_base64" id="photoOilChange" value="">
                                <div class="mb-3">
                                    <label class="form-label">الصورة (بالكاميرا فقط)</label>
                                    <div class="d-flex gap-2 align-items-center">
                                        <button type="button" class="btn btn-outline-primary" id="captureOilBtn">
                                            <i class="bi bi-camera me-1"></i>التقاط صورة
                                        </button>
                                        <span id="photoStatusOil" class="text-muted small"></span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">عدد الكيلومترات <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="km_reading" id="kmOilChange" required min="0" placeholder="مثال: 50000">
                                </div>
                                <button type="submit" class="btn btn-warning w-100" id="submitOilBtn" disabled>
                                    <i class="bi bi-check-circle me-1"></i>حفظ
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- بطاقة تفويل البنزين -->
                <div class="col-md-6">
                    <div class="card shadow-sm h-100 border-info">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-fuel-pump me-2"></i>تفويل البنزين</h5>
                        </div>
                        <div class="card-body">
                            <form id="formFuelRefill" class="maintenance-form" data-type="fuel_refill">
                                <input type="hidden" name="photo_base64" id="photoFuelRefill" value="">
                                <div class="mb-3">
                                    <label class="form-label">الصورة (بالكاميرا فقط)</label>
                                    <div class="d-flex gap-2 align-items-center">
                                        <button type="button" class="btn btn-outline-primary" id="captureFuelBtn">
                                            <i class="bi bi-camera me-1"></i>التقاط صورة
                                        </button>
                                        <span id="photoStatusFuel" class="text-muted small"></span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">عدد الكيلومترات <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="km_reading" id="kmFuelRefill" required min="0" placeholder="مثال: 50100">
                                </div>
                                <button type="submit" class="btn btn-info w-100" id="submitFuelBtn" disabled>
                                    <i class="bi bi-check-circle me-1"></i>حفظ
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- فلاتر للمدير والمحاسب -->
    <?php if ($isManager || $isAccountant): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2" id="filterForm" action="<?php echo htmlspecialchars($currentBase); ?>">
                    <input type="hidden" name="page" value="vehicle_maintenance">
                    <div class="col-md-2">
                        <label class="form-label small">السيارة</label>
                        <select name="vehicle_id" class="form-select form-select-sm">
                            <option value="">الكل</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>" <?php echo ($filters['vehicle_id'] ?? '') == $v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">النوع</label>
                        <select name="type" class="form-select form-select-sm">
                            <option value="">الكل</option>
                            <option value="oil_change" <?php echo ($filters['type'] ?? '') === 'oil_change' ? 'selected' : ''; ?>>تغيير زيت</option>
                            <option value="fuel_refill" <?php echo ($filters['type'] ?? '') === 'fuel_refill' ? 'selected' : ''; ?>>تفويل بنزين</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">من تاريخ</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">إلى تاريخ</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>بحث</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- مستطيل المتبقي على تغيير الزيت التالي (فوق جدول سجل الصيانات) -->
    <div class="card shadow-sm mb-3 border-warning">
        <div class="card-body py-3">
            <div class="d-flex align-items-center flex-wrap gap-2">
                <div class="flex-shrink-0 rounded bg-warning bg-opacity-25 p-2">
                    <i class="bi bi-droplet-half text-warning fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <?php
                    if ($oilRemainingInfo !== null && isset($oilRemainingInfo['km_since_oil']) && $oilRemainingInfo['km_since_oil'] !== null):
                        $kmSince = (int) $oilRemainingInfo['km_since_oil'];
                        $remaining = max(0, $oilTargetKm - $kmSince);
                        $vehicleLabel = ($isManager || $isAccountant) && !empty($oilRemainingInfo['vehicle_number']) ? ' — ' . htmlspecialchars($oilRemainingInfo['vehicle_number']) : '';
                    ?>
                        <span class="text-muted small d-block">المتبقي على تغيير الزيت التالي<?php echo $vehicleLabel; ?></span>
                        <?php if ($remaining > 0): ?>
                            <span class="fw-bold text-dark"><span id="oilRemainingKm"><?php echo number_format($remaining); ?></span> كم</span>
                            <span class="text-muted small">(تم قطع <?php echo number_format($kmSince); ?> كم منذ آخر تغيير زيت — الموعد المقترح عند <?php echo number_format($oilTargetKm); ?> كم)</span>
                        <?php else: ?>
                            <span class="fw-bold text-danger">٠ كم — يُنصح بتغيير الزيت</span>
                            <span class="text-muted small">(تم قطع <?php echo number_format($kmSince); ?> كم منذ آخر تغيير زيت)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted small d-block">المتبقي على تغيير الزيت التالي</span>
                        <?php if (($isManager || $isAccountant) && empty($filters['vehicle_id'])): ?>
                            <span class="text-muted">اختر سيارة من الفلتر لعرض المتبقي</span>
                        <?php else: ?>
                            <span class="text-muted">لا يوجد بيانات كافية (يُحتسب من آخر تغيير زيت وآخر تفويل بنزين)</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول السجلات -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>سجل الصيانات</h5>
        </div>
        <div class="card-body">
            <?php if (empty($records)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    لا توجد سجلات صيانة
                </div>
            <?php else: ?>
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <?php if ($isManager || $isAccountant): ?><th>السيارة</th><?php endif; ?>
                                <th>النوع</th>
                                <th>السائق</th>
                                <th>التاريخ</th>
                                <th>الكيلومترات</th>
                                <th>الفرق</th>
                                <th>الصورة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $i => $r): ?>
                                <tr>
                                    <td><?php echo ($pageNum - 1) * $perPage + $i + 1; ?></td>
                                    <?php if ($isManager || $isAccountant): ?>
                                        <td><?php echo htmlspecialchars($r['vehicle_number'] ?? '-'); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if (($r['type'] ?? '') === 'oil_change'): ?>
                                            <span class="badge bg-warning text-dark">تغيير زيت</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">تفويل بنزين</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($r['driver_name'] ?? $r['driver_username'] ?? '-'); ?></td>
                                    <td><?php echo function_exists('formatDate') ? formatDate($r['maintenance_date']) : $r['maintenance_date']; ?></td>
                                    <td><?php echo number_format($r['km_reading'] ?? 0); ?> كم</td>
                                    <td><?php echo isset($r['km_diff']) && $r['km_diff'] !== null ? number_format($r['km_diff']) . ' كم' : '-'; ?></td>
                                    <td>
                                        <?php if (!empty($r['photo_path'])): ?>
                                            <?php $photoUrl = getRelativeUrl('api/view_maintenance_photo.php?id=' . (int) $r['id']); ?>
                                            <a href="<?php echo htmlspecialchars($photoUrl); ?>" target="_blank" class="btn btn-sm btn-outline-primary">عرض</a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center pagination-sm">
                            <?php
                            $queryParams = array_filter([
                                'page' => 'vehicle_maintenance',
                                'vehicle_id' => $filters['vehicle_id'] ?? null,
                                'type' => $filters['type'] ?? null,
                                'date_from' => $filters['date_from'] ?? null,
                                'date_to' => $filters['date_to'] ?? null,
                            ]);
                            $baseQuery = http_build_query($queryParams);
                            ?>
                            <?php if ($pageNum > 1): ?>
                                <li class="page-item"><a class="page-link" href="?<?php echo $baseQuery; ?>&p=<?php echo $pageNum - 1; ?>">السابق</a></li>
                            <?php endif; ?>
                            <li class="page-item disabled"><span class="page-link"><?php echo $pageNum; ?> / <?php echo $totalPages; ?></span></li>
                            <?php if ($pageNum < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="?<?php echo $baseQuery; ?>&p=<?php echo $pageNum + 1; ?>">التالي</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isDriver && $vehicle): ?>
<!-- بطاقة ثابتة للكاميرا (ليست مودال) -->
<div id="maintenanceCameraCard" class="d-none" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1060; width: 90%; max-width: 420px; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.2); border-radius: 12px; overflow: hidden;">
    <div class="card border-0 h-100">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2">
            <h5 class="modal-title mb-0" id="maintenanceCameraTitle">التقاط صورة</h5>
            <button type="button" class="btn btn-link btn-sm text-white text-decoration-none p-0" id="maintenanceCameraCloseBtn" aria-label="إغلاق">
                <i class="bi bi-x-lg fs-5"></i>
            </button>
        </div>
        <div class="card-body p-3">
            <div id="maintenanceCameraContainer" class="text-center">
                <div id="maintenanceCameraLoading" class="mb-3" style="display: none;">
                    <div class="spinner-border text-primary"></div>
                    <p class="mt-2 text-muted small">جاري تحميل الكاميرا...</p>
                </div>
                <video id="maintenanceVideo" autoplay playsinline muted style="width: 100%; border-radius: 8px; background: #000; max-height: 320px;"></video>
                <canvas id="maintenanceCanvas" style="display: none;"></canvas>
                <div id="maintenanceCameraError" class="alert alert-danger mt-2 small" style="display: none;"></div>
            </div>
            <div id="maintenanceCapturedContainer" style="display: none; text-align: center;">
                <img id="maintenanceCapturedImg" src="" alt="الصورة الملتقطة" style="max-width: 100%; border-radius: 8px; max-height: 320px;">
            </div>
        </div>
        <div class="card-footer bg-light d-flex flex-wrap gap-2 justify-content-end py-2">
            <button type="button" class="btn btn-secondary btn-sm" id="maintenanceCameraCancelBtn">إلغاء</button>
            <button type="button" class="btn btn-primary btn-sm" id="maintenanceCaptureBtn" style="display: none;">
                <i class="bi bi-camera me-1"></i>التقاط
            </button>
            <button type="button" class="btn btn-success btn-sm" id="maintenanceConfirmBtn" style="display: none;">
                <i class="bi bi-check me-1"></i>تأكيد
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="maintenanceRetakeBtn" style="display: none;">
                <i class="bi bi-arrow-counterclockwise me-1"></i>إعادة التقاط
            </button>
        </div>
    </div>
</div>
<div id="maintenanceCameraBackdrop" class="d-none" style="position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1055;" aria-hidden="true"></div>

<script>
(function() {
    const apiUrl = <?php echo json_encode($apiBase); ?>;
    let currentStream = null;
    let currentType = null;
    let capturedBase64 = null;

    const cameraCard = document.getElementById('maintenanceCameraCard');
    const cameraBackdrop = document.getElementById('maintenanceCameraBackdrop');
    const video = document.getElementById('maintenanceVideo');
    const canvas = document.getElementById('maintenanceCanvas');
    const captureBtn = document.getElementById('maintenanceCaptureBtn');
    const confirmBtn = document.getElementById('maintenanceConfirmBtn');
    const retakeBtn = document.getElementById('maintenanceRetakeBtn');
    const loading = document.getElementById('maintenanceCameraLoading');
    const errorEl = document.getElementById('maintenanceCameraError');
    const cameraContainer = document.getElementById('maintenanceCameraContainer');
    const capturedContainer = document.getElementById('maintenanceCapturedContainer');
    const capturedImg = document.getElementById('maintenanceCapturedImg');

    function closeCameraCard() {
        stopCamera();
        cameraCard.classList.add('d-none');
        cameraBackdrop.classList.add('d-none');
    }

    function openCamera(type) {
        currentType = type;
        capturedBase64 = null;
        loading.style.display = 'block';
        errorEl.style.display = 'none';
        captureBtn.style.display = 'none';
        confirmBtn.style.display = 'none';
        retakeBtn.style.display = 'none';
        cameraContainer.style.display = 'block';
        capturedContainer.style.display = 'none';
        video.style.display = 'block';

        cameraCard.classList.remove('d-none');
        cameraBackdrop.classList.remove('d-none');

        const constraints = { video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } } };
        navigator.mediaDevices.getUserMedia(constraints).then(function(stream) {
            currentStream = stream;
            video.srcObject = stream;
            loading.style.display = 'none';
            captureBtn.style.display = 'inline-block';
        }).catch(function(err) {
            loading.style.display = 'none';
            errorEl.textContent = 'تعذر الوصول إلى الكاميرا: ' + (err.message || 'خطأ غير معروف');
            errorEl.style.display = 'block';
        });
    }

    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(function(t) { t.stop(); });
            currentStream = null;
        }
        video.srcObject = null;
    }

    if (cameraBackdrop) cameraBackdrop.addEventListener('click', closeCameraCard);
    const closeBtn = document.getElementById('maintenanceCameraCloseBtn');
    const cancelBtn = document.getElementById('maintenanceCameraCancelBtn');
    if (closeBtn) closeBtn.addEventListener('click', closeCameraCard);
    if (cancelBtn) cancelBtn.addEventListener('click', closeCameraCard);

    captureBtn.addEventListener('click', function() {
        const ctx = canvas.getContext('2d');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0);
        capturedBase64 = canvas.toDataURL('image/jpeg', 0.85);
        capturedImg.src = capturedBase64;
        cameraContainer.style.display = 'none';
        capturedContainer.style.display = 'block';
        captureBtn.style.display = 'none';
        confirmBtn.style.display = 'inline-block';
        retakeBtn.style.display = 'inline-block';
        stopCamera();
    });

    retakeBtn.addEventListener('click', function() {
        capturedBase64 = null;
        capturedContainer.style.display = 'none';
        cameraContainer.style.display = 'block';
        captureBtn.style.display = 'inline-block';
        confirmBtn.style.display = 'none';
        retakeBtn.style.display = 'none';
        openCamera(currentType);
    });

    confirmBtn.addEventListener('click', function() {
        if (capturedBase64 && currentType) {
            const photoInput = document.getElementById('photo' + (currentType === 'oil_change' ? 'OilChange' : 'FuelRefill'));
            const statusEl = document.getElementById('photoStatus' + (currentType === 'oil_change' ? 'Oil' : 'Fuel'));
            const submitBtn = document.getElementById('submit' + (currentType === 'oil_change' ? 'Oil' : 'Fuel') + 'Btn');
            if (photoInput) photoInput.value = capturedBase64;
            if (statusEl) statusEl.textContent = 'تم التقاط الصورة';
            if (submitBtn) submitBtn.disabled = false;
        }
        closeCameraCard();
    });

    document.getElementById('captureOilBtn').addEventListener('click', function() { openCamera('oil_change'); });
    document.getElementById('captureFuelBtn').addEventListener('click', function() { openCamera('fuel_refill'); });

    document.querySelectorAll('.maintenance-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const type = form.dataset.type;
            const kmInput = form.querySelector('input[name="km_reading"]');
            const photoInput = form.querySelector('input[name="photo_base64"]');
            const submitBtn = form.querySelector('button[type="submit"]');

            if (!photoInput || !photoInput.value) {
                alert('يجب التقاط صورة بالكاميرا أولاً');
                return;
            }
            if (!kmInput || !kmInput.value || parseInt(kmInput.value, 10) <= 0) {
                alert('يجب إدخال عدد الكيلومترات');
                return;
            }

            submitBtn.disabled = true;

            fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: type,
                    km_reading: parseInt(kmInput.value, 10),
                    photo: photoInput.value
                })
            }).then(function(r) { return r.json(); }).then(function(data) {
                if (data.success) {
                    alert(data.message + (data.km_diff !== null ? ' - الفرق: ' + data.km_diff + ' كم' : ''));
                    window.location.reload();
                } else {
                    alert(data.message || 'حدث خطأ');
                    submitBtn.disabled = false;
                }
            }).catch(function(err) {
                alert('حدث خطأ في الاتصال');
                submitBtn.disabled = false;
            });
        });
    });
})();
</script>
<?php endif; ?>

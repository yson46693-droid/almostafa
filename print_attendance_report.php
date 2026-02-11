<?php
/**
 * طباعة تقرير سجلات الحضور والانصراف لمستخدم خلال الشهر المحدد
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['accountant', 'manager', 'developer']);

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$month = isset($_GET['month']) ? trim((string) $_GET['month']) : date('Y-m');

if ($userId <= 0) {
    die('معرف المستخدم غير صحيح');
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$db = db();

$user = $db->queryOne(
    "SELECT id, username, full_name, role FROM users WHERE id = ? AND role != 'manager' AND status = 'active'",
    [$userId]
);

if (!$user) {
    die('المستخدم غير موجود أو لا يخضع لنظام الحضور والانصراف');
}

$tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
if (empty($tableCheck)) {
    $records = [];
} else {
    $records = $db->query(
        "SELECT * FROM attendance_records 
         WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
         ORDER BY date ASC, check_in_time ASC",
        [$userId, $month]
    );
}

$stats = getAttendanceStatistics($userId, $month);
$delaySummary = calculateMonthlyDelaySummary($userId, $month);

$userName = $user['full_name'] ?? $user['username'];
$monthLabel = date('F Y', strtotime($month . '-01'));
$companyName = defined('COMPANY_NAME') ? COMPANY_NAME : 'النظام';

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير الحضور والانصراف - <?php echo htmlspecialchars($userName); ?> - <?php echo $monthLabel; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { size: A4; margin: 15mm; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; padding: 0; }
        }
        body {
            font-family: 'Tajawal', 'Arial', sans-serif;
            background: #f5f5f5;
            padding: 20px;
            font-size: 14px;
            line-height: 1.5;
        }
        .report-container {
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .report-header {
            text-align: center;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .report-header .company {
            font-size: 18px;
            font-weight: 700;
            color: #0d6efd;
            margin-bottom: 5px;
        }
        .report-header h1 {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin: 10px 0 5px;
        }
        .report-header .subtitle {
            font-size: 14px;
            color: #666;
        }
        .info-block {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .info-block span {
            font-weight: 600;
            color: #333;
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 6px;
            margin-bottom: 12px;
        }
        .summary-card {
            padding: 6px 8px;
            background: #f8f9fa;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #dee2e6;
        }
        .summary-card .value {
            font-size: 13px;
            font-weight: 700;
            color: #0d6efd;
        }
        .summary-card .label {
            font-size: 10px;
            color: #666;
            margin-top: 2px;
        }
        table.report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 11px;
        }
        table.report-table th,
        table.report-table td {
            border: 1px solid #dee2e6;
            padding: 4px 6px;
            text-align: right;
        }
        table.report-table th {
            background: #0d6efd;
            color: #fff;
            font-weight: 600;
            font-size: 11px;
        }
        table.report-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        table.report-table .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 10px;
        }
        .badge-success { background: #198754; color: #fff; }
        .badge-warning { background: #ffc107; color: #000; }
        .footer-note {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        .btn-print {
            display: inline-block;
            margin: 0 auto 20px;
            padding: 10px 24px;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-print:hover { background: #0b5ed7; color: #fff; }
        .print-actions { text-align: center; }
    </style>
</head>
<body>
    <div class="print-actions no-print">
        <button type="button" class="btn-print" onclick="window.print();">
            طباعة التقرير
        </button>
    </div>
    <div class="report-container">
        <div class="report-header">
            <div class="company"><?php echo htmlspecialchars($companyName); ?></div>
            <h1>تقرير سجلات الحضور والانصراف</h1>
            <div class="subtitle">للمستخدم خلال الشهر المحدد</div>
        </div>
        <div class="info-block">
            <span>المستخدم:</span> <?php echo htmlspecialchars($userName); ?>
            &nbsp;|&nbsp;
            <span>الشهر:</span> <?php echo $monthLabel; ?>
            &nbsp;|&nbsp;
            <span>الدور:</span> <?php echo htmlspecialchars($user['role']); ?>
        </div>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="value"><?php echo (int) $stats['present_days']; ?></div>
                <div class="label">أيام الحضور</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo formatHours($stats['total_hours']); ?></div>
                <div class="label">إجمالي ساعات العمل</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo number_format($delaySummary['average_minutes'] ?? 0, 1); ?></div>
                <div class="label">متوسط التأخير (دقيقة)</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo (int) ($delaySummary['delay_days'] ?? 0); ?></div>
                <div class="label">مرات التأخير</div>
            </div>
        </div>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ</th>
                    <th>تسجيل الحضور</th>
                    <th>تسجيل الانصراف</th>
                    <th>التأخير</th>
                    <th>سبب التأخير</th>
                    <th>ساعات العمل</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #666;">لا توجد سجلات لهذا الشهر</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $i => $record): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo formatDate($record['date']); ?></td>
                            <td><?php echo formatDateTime($record['check_in_time']); ?></td>
                            <td><?php echo $record['check_out_time'] ? formatDateTime($record['check_out_time']) : '-'; ?></td>
                            <td>
                                <?php if (($record['delay_minutes'] ?? 0) > 0): ?>
                                    <span class="badge badge-warning"><?php echo (int) $record['delay_minutes']; ?> دقيقة</span>
                                <?php else: ?>
                                    <span class="badge badge-success">في الوقت</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($record['delay_reason']) && ($record['delay_minutes'] ?? 0) > 0 ? htmlspecialchars($record['delay_reason']) : '-'; ?></td>
                            <td><?php echo isset($record['work_hours']) && $record['work_hours'] > 0 ? formatHours($record['work_hours']) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="footer-note">
            تم إنشاء التقرير في <?php echo date('Y-m-d H:i'); ?> — تقرير الحضور والانصراف (شهري)
        </div>
    </div>
    <script>
        // طباعة تلقائية اختيارية (يمكن تعطيلها)
        // window.onload = function() { window.print(); };
    </script>
</body>
</html>

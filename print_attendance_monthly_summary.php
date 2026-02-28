<?php
/**
 * طباعة تقرير الحضور الشهري المجمع لجميع العمال
 * ملخص سطر واحد لكل عامل: أيام الحضور، ساعات العمل، مرات التأخير، متوسط التأخير
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['accountant', 'manager', 'developer']);

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

$month = isset($_GET['month']) ? trim((string) $_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$db = db();

$users = $db->query(
    "SELECT id, username, full_name, role FROM users WHERE status = 'active' AND role != 'manager' ORDER BY full_name ASC"
);

$rows = [];
foreach ($users as $user) {
    $stats = getAttendanceStatistics($user['id'], $month);
    $delaySummary = calculateMonthlyDelaySummary($user['id'], $month);
    $rows[] = [
        'user' => $user,
        'present_days' => (int) ($stats['present_days'] ?? 0),
        'total_hours' => (float) ($stats['total_hours'] ?? 0),
        'delay_count' => (int) ($delaySummary['delay_days'] ?? 0),
        'average_delay_minutes' => (float) ($delaySummary['average_minutes'] ?? 0),
    ];
}

$monthLabel = date('F Y', strtotime($month . '-01'));
$companyName = defined('COMPANY_NAME') ? COMPANY_NAME : 'النظام';

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير الحضور الشهري المجمع - <?php echo $monthLabel; ?></title>
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
            margin-bottom: 20px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            text-align: center;
        }
        .info-block span { font-weight: 600; color: #333; }
        table.report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 12px;
        }
        table.report-table th,
        table.report-table td {
            border: 1px solid #dee2e6;
            padding: 8px 10px;
            text-align: right;
        }
        table.report-table th {
            background: #0d6efd;
            color: #fff;
            font-weight: 600;
        }
        table.report-table tr:nth-child(even) {
            background: #f8f9fa;
        }
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
        @media (max-width: 576px) {
            table.report-table { font-size: 11px; }
            table.report-table th, table.report-table td { padding: 6px 4px; }
        }
    </style>
</head>
<body>
    <div class="print-actions no-print" style="display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 12px; margin-bottom: 16px;">
        <label for="report-month" style="font-weight: 600;">الشهر:</label>
        <input type="month" id="report-month" value="<?php echo htmlspecialchars($month); ?>" style="padding: 6px 10px; border: 1px solid #dee2e6; border-radius: 6px;">
        <button type="button" class="btn-print" onclick="var m = document.getElementById('report-month').value; if(m) window.location.href = '?month=' + encodeURIComponent(m);">
            عرض التقرير
        </button>
        <button type="button" class="btn-print" onclick="window.print();">
            طباعة التقرير
        </button>
    </div>
    <div class="report-container">
        <div class="report-header">
            <div class="company"><?php echo htmlspecialchars($companyName); ?></div>
            <h1>تقرير الحضور الشهري المجمع</h1>
            <div class="subtitle">ملخص لكل العمال — <?php echo $monthLabel; ?></div>
        </div>
        <div class="info-block">
            <span>الشهر:</span> <?php echo $monthLabel; ?>
        </div>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الدور</th>
                    <th>أيام الحضور</th>
                    <th>ساعات العمل</th>
                    <th>مرات التأخير</th>
                    <th>متوسط التأخير (دقيقة)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #666;">لا يوجد مستخدمون خاضعون لنظام الحضور</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($row['user']['full_name'] ?? $row['user']['username']); ?></td>
                            <td><?php echo htmlspecialchars($row['user']['role']); ?></td>
                            <td><?php echo $row['present_days']; ?></td>
                            <td><?php echo formatHours($row['total_hours']); ?></td>
                            <td><?php echo $row['delay_count']; ?></td>
                            <td><?php echo number_format($row['average_delay_minutes'], 1); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="footer-note">
            تم إنشاء التقرير في <?php echo date('Y-m-d H:i'); ?> — تقرير الحضور الشهري المجمع
        </div>
    </div>
</body>
</html>

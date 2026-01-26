<?php
/**
 * صفحة طباعة إيصال مهمة إنتاج (80mm)
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['production', 'accountant', 'manager']);

$taskId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($taskId <= 0) {
    die('رقم المهمة غير صحيح');
}

$db = db();
$currentUser = getCurrentUser();

// جلب بيانات المهمة
$task = $db->queryOne(
    "SELECT t.*,
            uAssign.full_name AS assigned_to_name,
            uCreate.full_name AS created_by_name,
            p.name AS product_name_from_db
     FROM tasks t
     LEFT JOIN users uAssign ON t.assigned_to = uAssign.id
     LEFT JOIN users uCreate ON t.created_by = uCreate.id
     LEFT JOIN products p ON t.product_id = p.id
     WHERE t.id = ?",
    [$taskId]
);

if (!$task) {
    die('المهمة غير موجودة');
}

$companyName = COMPANY_NAME;
$taskNumber = $taskId;
$taskTitle = $task['title'] ?? 'مهمة إنتاج';
$productName = $task['product_name'] ?? $task['product_name_from_db'] ?? '';
$quantity = isset($task['quantity']) && $task['quantity'] !== null ? (float) $task['quantity'] : 0;
$description = $task['description'] ?? '';
$notes = $task['notes'] ?? '';
$priority = $task['priority'] ?? 'normal';
$status = $task['status'] ?? 'pending';
$assignedTo = $task['assigned_to_name'] ?? 'غير محدد';
$createdBy = $task['created_by_name'] ?? 'غير محدد';
$createdAt = $task['created_at'] ?? date('Y-m-d H:i:s');
$dueDate = $task['due_date'] ?? null;
$taskType = $task['task_type'] ?? 'general';

// تسميات الحالة والأولوية
$statusLabels = [
    'pending' => 'معلقة',
    'received' => 'مستلمة',
    'in_progress' => 'قيد التنفيذ',
    'completed' => 'مكتملة',
    'cancelled' => 'ملغاة'
];

$priorityLabels = [
    'urgent' => 'عاجلة',
    'high' => 'عالية',
    'normal' => 'عادية',
    'low' => 'منخفضة'
];

$statusLabel = $statusLabels[$status] ?? $status;
$priorityLabel = $priorityLabels[$priority] ?? $priority;

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إيصال مهمة - <?php echo htmlspecialchars($taskNumber); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @page {
            size: 80mm auto;
            margin: 5mm;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 0;
                background: #ffffff;
            }
            .receipt-container {
                box-shadow: none;
                border: none;
                padding: 0;
                margin: 0;
            }
        }
        
        body {
            font-family: 'Tajawal', Arial, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
        }
        
        .receipt-container {
            max-width: 80mm;
            margin: 0 auto;
            padding: 15px;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .receipt-header h1 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #000;
        }
        
        .receipt-header .company-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .receipt-header .receipt-type {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        .task-number {
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            margin: 15px 0;
            padding: 8px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 12px;
        }
        
        .info-table tr {
            border-bottom: 1px solid #eee;
        }
        
        .info-table td {
            padding: 6px 4px;
            vertical-align: top;
        }
        
        .info-table td:first-child {
            font-weight: 600;
            width: 35%;
            color: #333;
        }
        
        .info-table td:last-child {
            text-align: right;
            color: #000;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            margin: 15px 0 8px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #000;
        }
        
        .task-details {
            margin: 10px 0;
        }
        
        .detail-item {
            margin: 8px 0;
            font-size: 12px;
        }
        
        .detail-label {
            font-weight: 600;
            display: inline-block;
            width: 35%;
            color: #333;
        }
        
        .detail-value {
            display: inline-block;
            width: 64%;
            text-align: right;
            color: #000;
        }
        
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        .divider {
            border-top: 1px dashed #ccc;
            margin: 10px 0;
        }
        
        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .no-print button {
            padding: 10px 20px;
            margin: 5px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        
        .btn-print {
            background: #007bff;
            color: white;
        }
        
        .btn-print:hover {
            background: #0056b3;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
        }
        
        .btn-back:hover {
            background: #545b62;
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="no-print">
            <button class="btn-print" onclick="window.print()">طباعة</button>
            <?php
            // استخدام getDashboardUrl إذا كان متاحاً
            if (function_exists('getDashboardUrl')) {
                $backUrl = getDashboardUrl('production') . '?page=tasks';
            } else {
                $backUrl = getRelativeUrl('dashboard/production.php?page=tasks');
            }
            ?>
            <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn-back" style="text-decoration: none; display: inline-block;">
                رجوع
            </a>
        </div>
        
        <div class="receipt-header">
            <h1>إيصال مهمة إنتاج</h1>
            <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
        </div>
        
        <div class="task-number">
            رقم المهمة: <?php echo htmlspecialchars($taskNumber); ?>
        </div>
        
        <div class="section-title">معلومات المهمة</div>
        <table class="info-table">
            
            <?php if (!empty($productName)): ?>
            <tr>
                <td>المنتج:</td>
                <td><?php echo htmlspecialchars($productName); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($quantity > 0): ?>
            <tr>
                <td>الكمية:</td>
                <td><?php echo number_format((int)$quantity); ?> قطعة</td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>نوع المهمة:</td>
                <td><?php echo $taskType === 'production' ? 'مهمة إنتاج' : 'مهمة عامة'; ?></td>
            </tr>
            <tr>
                <td>الأولوية:</td>
                <td><?php echo htmlspecialchars($priorityLabel); ?></td>
            </tr>
        </table>
        
        <div class="divider"></div>
        
        <div class="section-title">تفاصيل إضافية</div>
        <table class="info-table">
            <tr>
                <td>أنشأها:</td>
                <td><?php echo htmlspecialchars($createdBy); ?></td>
            </tr>
            <tr>
                <td>تاريخ الإنشاء:</td>
                <td><?php echo date('Y-m-d', strtotime($createdAt)) . ' | ' . date('h:i A', strtotime($createdAt)); ?></td>
            </tr>
            <?php if ($dueDate): ?>
            <tr>
                <td>تاريخ الاستحقاق:</td>
                <td><?php echo date('Y-m-d', strtotime($dueDate)); ?></td>
            </tr>
            <?php endif; ?>
        </table>
        <?php if (!empty($notes)): ?>
        <div class="divider"></div>
        <div class="section-title">ملاحظات</div>
        <div class="task-details">
            <div style="font-size: 12px; line-height: 1.6; padding: 5px 0;">
                <?php 
                // إزالة معلومات العمال من الملاحظات للعرض
                $displayNotes = preg_replace('/\[ASSIGNED_WORKERS_IDS\]:\s*[0-9,]+/', '', $notes);
                $displayNotes = preg_replace('/المنتج:\s*[^\n]+/', '', $displayNotes);
                $displayNotes = preg_replace('/الكمية:\s*[0-9.]+/', '', $displayNotes);
                $displayNotes = trim($displayNotes);
                if (!empty($displayNotes)) {
                    echo nl2br(htmlspecialchars($displayNotes)); 
                } else {
                    echo '<span style="color: #999;">لا توجد ملاحظات</span>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="divider"></div>
        
        <div class="footer">
            <p>شكراً لكم</p>
            <p>تم إنشاء هذا الإيصال تلقائياً</p>
            <p><?php echo date('Y/m/d H:i:s'); ?></p>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة مع معامل print
        window.onload = function() {
            if (window.location.search.includes('print=1')) {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        };
    </script>
</body>
</html>

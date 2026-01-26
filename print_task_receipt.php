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

// استخراج المنتجات المتعددة من notes
$products = [];
if (!empty($notes)) {
    // محاولة استخراج JSON من notes
    if (preg_match('/\[PRODUCTS_JSON\]:(.+?)(?=\n|$)/', $notes, $matches)) {
        $productsJson = trim($matches[1]);
        $decodedProducts = json_decode($productsJson, true);
        if (is_array($decodedProducts) && !empty($decodedProducts)) {
            $products = $decodedProducts;
        }
    }
    
    // إذا لم نجد JSON، حاول استخراج من الصيغة النصية
    if (empty($products)) {
        $lines = explode("\n", $notes);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/المنتج:\s*(.+?)(?:\s*-\s*الكمية:\s*([0-9.]+))?/i', $line, $matches)) {
                $productName = trim($matches[1]);
                $productQuantity = isset($matches[2]) ? (float)$matches[2] : null;
                if (!empty($productName)) {
                    $products[] = [
                        'name' => $productName,
                        'quantity' => $productQuantity
                    ];
                }
            }
        }
    }
}

// إذا لم نجد منتجات متعددة، استخدم المنتج الواحد (للتوافق مع الكود القديم)
if (empty($products) && !empty($productName)) {
    $products[] = [
        'name' => $productName,
        'quantity' => $quantity > 0 ? $quantity : null
    ];
}

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
            font-family: 'Tajawal', 'Arial', 'Helvetica', sans-serif;
            background-color: #f5f5f5;
            padding: 10px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        .receipt-container {
            max-width: 80mm;
            margin: 0 auto;
            padding: 5px;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .receipt-header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 12px;
            margin-bottom: 10px;
        }
        
        .receipt-header h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
            color: #000;
            letter-spacing: 0.5px;
            line-height: 1.4;
        }
        
        .receipt-header .company-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
            color: #000;
            letter-spacing: 0.3px;
        }
        
        .receipt-header .receipt-type {
            font-size: 15px;
            color: #333;
            margin-top: 6px;
            font-weight: 500;
        }
        
        .task-number {
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            margin: 18px 0;
            padding: 10px;
            background-color: #f0f0f0;
            border: 2px solid #000;
            letter-spacing: 0.5px;
            line-height: 1.5;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .info-table tr {
            border-bottom: 1px solid #ddd;
        }
        
        .info-table td {
            padding: 8px 5px;
            vertical-align: top;
            line-height: 1.6;
        }
        
        .info-table td:first-child {
            font-weight: 700;
            width: 35%;
            color: #000;
            font-size: 14px;
        }
        
        .info-table td:last-child {
            text-align: right;
            color: #000;
            font-weight: 500;
            font-size: 14px;
        }
        
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 13px;
        }
        
        .products-table thead {
            background-color: #f8f9fa;
        }
        
        .products-table th {
            padding: 8px 5px;
            text-align: right;
            font-weight: 700;
            font-size: 13px;
            border-bottom: 2px solid #000;
            color: #000;
        }
        
        .products-table td {
            padding: 8px 5px;
            text-align: right;
            border-bottom: 1px solid #ddd;
            color: #000;
            font-weight: 500;
        }
        
        .products-table tr:last-child td {
            border-bottom: none;
        }
        
        .products-table .product-name {
            font-weight: 600;
        }
        
        .products-table .product-quantity {
            text-align: center;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin: 18px 0 10px 0;
            padding-bottom: 6px;
            border-bottom: 2px solid #000;
            color: #000;
            letter-spacing: 0.3px;
        }
        
        .task-details {
            margin: 12px 0;
        }
        
        .detail-item {
            margin: 10px 0;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .detail-label {
            font-weight: 700;
            display: inline-block;
            width: 35%;
            color: #000;
            font-size: 14px;
        }
        
        .detail-value {
            display: inline-block;
            width: 64%;
            text-align: right;
            color: #000;
            font-weight: 500;
            font-size: 14px;
        }
        
        .footer {
            margin-top: 20px;
            padding-top: 12px;
            border-top: 2px solid #ddd;
            text-align: center;
            font-size: 12px;
            color: #333;
            font-weight: 500;
            line-height: 1.6;
        }
        
        .divider {
            border-top: 2px dashed #999;
            margin: 12px 0;
        }
        
        .no-print {
            text-align: center;
            margin-bottom: 10px;
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
            <h1>إيصال اوردر إنتاج</h1>
            <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
        </div>
        
        <div class="task-number">
            رقم الاوردر: <?php echo htmlspecialchars($taskNumber); ?>
        </div>
        
        <div class="section-title">تفاصيل الاوردر</div>
        <?php if (!empty($products)): ?>
        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 60%;">المنتج</th>
                    <th style="width: 40%; text-align: center;">الكمية</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totalQuantity = 0;
                foreach ($products as $product): 
                    $productQty = $product['quantity'] ?? null;
                    if ($productQty !== null) {
                        $totalQuantity += $productQty;
                    }
                ?>
                <tr>
                    <td class="product-name"><?php echo htmlspecialchars($product['name']); ?></td>
                    <td class="product-quantity">
                        <?php 
                        if ($productQty !== null) {
                            echo number_format($productQty, 2) . ' قطعة';
                        } else {
                            echo '<span style="color: #999;">-</span>';
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($products) > 1 && $totalQuantity > 0): ?>
                <tr style="border-top: 2px solid #000; font-weight: 700;">
                    <td style="text-align: left; padding-top: 10px;">الإجمالي:</td>
                    <td style="text-align: center; padding-top: 10px;"><?php echo number_format($totalQuantity, 2); ?> قطعة</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php else: ?>
        <table class="info-table">
            <tr>
                <td>لا توجد منتجات</td>
                <td>-</td>
            </tr>
        </table>
        <?php endif; ?>
        
        <div class="divider"></div>
        
        <table class="info-table">
            <tr>
                <td>الأولوية:</td>
                <td style="font-weight: 600;"><?php echo htmlspecialchars($priorityLabel); ?></td>
            </tr>
        </table>
        
        <div class="divider"></div>
        
        <div class="section-title">تفاصيل إضافية</div>
        <table class="info-table">
          
            <tr>
                <td>منشئ الطلب:</td>
                <td style="font-weight: 600;"><?php echo htmlspecialchars($createdBy); ?></td>
            </tr>
            <tr>
                <td>تاريخ الطلب:</td>
                <td style="font-weight: 600;"><?php echo date('Y-m-d', strtotime($createdAt)) . ' | ' . date('h:i A', strtotime($createdAt)); ?></td>
            </tr>
            <?php if ($dueDate): ?>
            <tr>
                <td>تاريخ الاستحقاق:</td>
                <td style="font-weight: 600;"><?php echo date('Y-m-d', strtotime($dueDate)); ?></td>
            </tr>
            <?php endif; ?>
        </table>
        <?php if (!empty($notes)): ?>
        <div class="section-title">ملاحظات</div>
        <div class="task-details">
            <div style="font-size: 14px; line-height: 1.8; padding: 4px 0; font-weight: 500; color: #000;">
                <?php 
                // إزالة معلومات العمال من الملاحظات للعرض
                $displayNotes = preg_replace('/\[ASSIGNED_WORKERS_IDS\]:\s*[0-9,]+/', '', $notes);
                // إزالة JSON المنتجات
                $displayNotes = preg_replace('/\[PRODUCTS_JSON\]:[^\n]*/', '', $displayNotes);
                // إزالة معلومات المنتج النصية القديمة
                $displayNotes = preg_replace('/المنتج:\s*[^\n]+/', '', $displayNotes);
                $displayNotes = preg_replace('/الكمية:\s*[0-9.]+/', '', $displayNotes);
                // إزالة الأسطر الفارغة المتعددة
                $displayNotes = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $displayNotes);
                $displayNotes = trim($displayNotes);
                if (!empty($displayNotes)) {
                    echo nl2br(htmlspecialchars($displayNotes)); 
                } else {
                    echo '<span style="color: #666; font-weight: 500;">لا توجد ملاحظات</span>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
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

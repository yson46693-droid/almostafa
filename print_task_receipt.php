<?php
/**
 * صفحة طباعة إيصال مهمة إنتاج (80mm)
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['production', 'accountant', 'manager', 'driver']);

// منع الكاش عند التبديل بين الصفحات لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

// دعم طباعة إيصال واحد (id=) أو عدة إيصالات (ids=1,2,3)
$taskIds = [];
if (!empty($_GET['ids'])) {
    $taskIds = array_filter(array_map('intval', explode(',', (string) $_GET['ids'])));
}
if (empty($taskIds) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    if ($id > 0) {
        $taskIds = [$id];
    }
}
if (empty($taskIds)) {
    die('رقم المهمة غير صحيح');
}

$db = db();
$currentUser = getCurrentUser();

$taskTypeLabels = [
    'shop_order' => 'اوردر محل',
    'cash_customer' => 'عميل نقدي',
    'telegraph' => 'تليجراف',
    'shipping_company' => 'شركة شحن',
    'general' => 'مهمة عامة',
    'production' => 'إنتاج منتج',
    'quality' => 'مهمة جودة',
    'maintenance' => 'صيانة'
];
$statusLabels = [
    'pending' => 'معلقة',
    'received' => 'مستلمة',
    'completed' => 'مكتملة',
    'with_delegate' => 'مع المندوب',
    'delivered' => 'تم التوصيل',
    'returned' => 'تم الارجاع',
    'cancelled' => 'ملغاة'
];
$priorityLabels = [
    'urgent' => 'عاجلة',
    'high' => 'عالية',
    'normal' => 'عادية',
    'low' => 'منخفضة'
];

$receipts = [];
foreach ($taskIds as $taskId) {
    if ($taskId <= 0) continue;
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
    if (!$task) continue;

    try {
        $db->execute("UPDATE tasks SET receipt_print_count = COALESCE(receipt_print_count, 0) + 1 WHERE id = ?", [$taskId]);
    } catch (Exception $e) {
        error_log('print_task_receipt: failed to increment receipt_print_count: ' . $e->getMessage());
    }

    $notes = $task['notes'] ?? '';
    $productName = $task['product_name'] ?? $task['product_name_from_db'] ?? '';
    $quantity = isset($task['quantity']) && $task['quantity'] !== null ? (float) $task['quantity'] : 0;
    $unit = !empty($task['unit']) ? $task['unit'] : 'قطعة';
    $relatedType = $task['related_type'] ?? '';
    $taskType = $task['task_type'] ?? 'general';
    if (strpos($relatedType, 'manager_') === 0) {
        $taskType = substr($relatedType, 8);
    }

    $products = [];
    if (!empty($notes)) {
        if (preg_match('/\[PRODUCTS_JSON\]:(.+?)(?=\n|$)/', $notes, $matches)) {
            $productsJson = trim($matches[1]);
            $decodedProducts = json_decode($productsJson, true);
            if (is_array($decodedProducts) && !empty($decodedProducts)) {
                $products = $decodedProducts;
            }
        }
        if (empty($products)) {
            $lines = explode("\n", $notes);
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/المنتج:\s*(.+?)(?:\s*-\s*الكمية:\s*([0-9.]+))?/i', $line, $m)) {
                    $pn = trim($m[1]);
                    $pq = isset($m[2]) ? (float)$m[2] : null;
                    if ($pn !== '') {
                        $products[] = ['name' => $pn, 'quantity' => $pq];
                    }
                }
            }
        }
    }
    if (empty($products) && $productName !== '') {
        $products[] = ['name' => $productName, 'quantity' => $quantity > 0 ? $quantity : null, 'unit' => $unit];
    } else {
        foreach ($products as &$p) {
            if (!isset($p['unit']) || $p['unit'] === '') {
                $p['unit'] = $unit;
            }
        }
        unset($p);
    }

    $displayNotes = '';
    if (!empty($notes)) {
        $displayNotes = preg_replace('/\[ASSIGNED_WORKERS_IDS\]:\s*[0-9,]+/', '', $notes);
        $displayNotes = preg_replace('/\[PRODUCTS_JSON\]:[^\n]*/', '', $displayNotes);
        $displayNotes = preg_replace('/\[SHIPPING_FEES\]:\s*[0-9.]+/', '', $displayNotes);
        $displayNotes = preg_replace('/المنتج:\s*[^\n]+/', '', $displayNotes);
        $displayNotes = preg_replace('/الكمية:\s*[0-9.]+/', '', $displayNotes);
        $displayNotes = preg_replace('/^[^\n]*العامل المخصص[^\n]*\n?/m', '', $displayNotes);
        $displayNotes = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $displayNotes);
        $displayNotes = trim($displayNotes);
    }

    $shippingFees = 0;
    if (!empty($notes) && preg_match('/\[SHIPPING_FEES\]:\s*([0-9.]+)/', $notes, $m)) {
        $shippingFees = (float) $m[1];
    }

    $receipts[] = [
        'task' => $task,
        'taskNumber' => $taskId,
        'taskTypeLabel' => $taskTypeLabels[$taskType] ?? $taskType,
        'priorityLabel' => $priorityLabels[$task['priority'] ?? 'normal'] ?? $task['priority'] ?? 'normal',
        'statusLabel' => $statusLabels[$task['status'] ?? 'pending'] ?? $task['status'] ?? 'pending',
        'products' => $products,
        'unit' => $unit,
        'displayNotes' => $displayNotes,
        'shippingFees' => $shippingFees,
    ];
}

if (empty($receipts)) {
    die('لا توجد مهام صالحة للطباعة');
}

$companyName = COMPANY_NAME;
$singleReceipt = count($receipts) === 1;

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="<?php echo $singleReceipt ? '' : 'multi-receipt'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $singleReceipt ? 'إيصال مهمة - ' . (int)$receipts[0]['taskNumber'] : 'طباعة إيصالات (' . count($receipts) . ')'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        <?php if ($singleReceipt): ?>
        @page { size: 80mm auto; margin: 5mm; }
        <?php else: ?>
        /* عدة إيصالات: ورقة A4 لكل إيصال لضمان خروج كل واحد في ورقة منفصلة */
        @page { size: A4; margin: 10mm; }
        <?php endif; ?>
        
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
            /* إجبار كل إيصال في ورقة منفصلة: ارتفاع الصفحة = ورقة واحدة */
            body.multi-receipt .receipt-sheet {
                height: 277mm !important;
                min-height: 277mm !important;
                max-height: 277mm !important;
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                overflow: hidden !important;
                display: block !important;
            }
            body.multi-receipt .receipt-sheet .receipt-sheet-inner {
                max-width: 80mm;
                margin: 0 auto;
            }
            .receipt-sheet {
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .receipt-sheet:not(:first-child) {
                page-break-before: always !important;
                break-before: page !important;
            }
            .page-break-before {
                page-break-after: avoid !important;
                height: 0 !important;
                overflow: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
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
        
        .info-table.customer-priority-row td:nth-child(1),
        .info-table.customer-priority-row td:nth-child(3) {
            font-weight: 700;
            width: 15%;
            color: #000;
        }
        .info-table.customer-priority-row td:nth-child(2),
        .info-table.customer-priority-row td:nth-child(4) {
            font-weight: 600;
            text-align: right;
            color: #000;
            width: 35%;
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
        /* فصل كل إيصال في ورقة منفصلة عند الطباعة */
        .receipt-sheet {
            page-break-after: always;
            break-after: page;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .receipt-sheet:not(:first-child) {
            page-break-before: always;
            break-before: page;
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
        .btn-open-separate {
            background: #28a745;
            color: white;
            margin: 5px;
        }
        .btn-open-separate:hover {
            background: #218838;
            color: white;
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="no-print">
            <button class="btn-print" onclick="window.print()"><?php echo $singleReceipt ? 'طباعة' : 'طباعة الكل (' . count($receipts) . ')'; ?></button>
            <?php
            $receiptUserRole = $currentUser['role'] ?? 'production';
            $backToProductionTasks = ($receiptUserRole === 'manager' || $receiptUserRole === 'accountant');
            if (function_exists('getDashboardUrl')) {
                $backUrl = getDashboardUrl($receiptUserRole) . ($backToProductionTasks ? '?page=production_tasks' : '?page=tasks');
            } else {
                if ($receiptUserRole === 'driver') {
                    $backUrl = getRelativeUrl('dashboard/driver.php?page=tasks');
                } elseif ($receiptUserRole === 'manager') {
                    $backUrl = getRelativeUrl('dashboard/manager.php?page=production_tasks');
                } elseif ($receiptUserRole === 'accountant') {
                    $backUrl = getRelativeUrl('dashboard/accountant.php?page=production_tasks');
                } else {
                    $backUrl = getRelativeUrl('dashboard/production.php?page=tasks');
                }
            }
            ?>
            <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn-back" style="text-decoration: none; display: inline-block;">
                رجوع
            </a>
        </div>
        <?php
        foreach ($receipts as $idx => $r):
            if ($idx > 0) {
                echo '<div class="page-break-before" style="page-break-before: always; break-before: page;"></div>';
            }
            $task = $r['task'];
            $taskNumber = $r['taskNumber'];
            $taskTypeLabel = $r['taskTypeLabel'];
            $priorityLabel = $r['priorityLabel'];
            $products = $r['products'];
            $unit = $r['unit'];
            $displayNotes = $r['displayNotes'];
            $customerName = !empty($task['customer_name']) ? $task['customer_name'] : '';
            $createdAt = $task['created_at'] ?? date('Y-m-d H:i:s');
            $dueDate = $task['due_date'] ?? null;
        ?>
        <div class="receipt-sheet">
        <div class="receipt-sheet-inner">
        <div class="task-number">
            رقم الاوردر: <?php echo htmlspecialchars($taskNumber); ?>
        </div>
        
        <table class="info-table customer-priority-row" style="margin: 12px 0;">
            <tr>
                <td>العميل:</td>
                <td><?php echo $customerName !== '' ? htmlspecialchars($customerName) : '-'; ?></td>
                <td>النوع :</td>
                <td><?php echo htmlspecialchars($taskTypeLabel); ?></td>
            </tr>
            <tr>
                <td>الطلب :</td>
                <td><?php echo date('m-d', strtotime($createdAt)) . ' | ' . date('g:i', strtotime($createdAt)); ?></td>
                <td>تسليم:</td>
                <td><?php echo $dueDate ? date('m-d', strtotime($dueDate)) : '-'; ?></td>
            </tr>
        </table>
        
        <div class="section-title">تفاصيل الاوردر</div>
        <?php if (!empty($products)): ?>
        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 35%;">المنتج</th>
                    <th style="width: 20%; text-align: center;">ك</th>
                    <th style="width: 20%; text-align: center;">السعر</th>
                    <th style="width: 25%; text-align: center;">اجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grandTotal = 0;
                foreach ($products as $product): 
                    $productQty = $product['quantity'] ?? null;
                    $productUnit = !empty($product['unit']) ? $product['unit'] : $unit;
                    $productPrice = isset($product['price']) && $product['price'] !== null && $product['price'] !== '' ? (float)$product['price'] : null;
                    // الإجمالي = القيمة المحفوظة من النموذج (line_total) أو الكمية × السعر
                    $lineTotal = null;
                    if (isset($product['line_total']) && $product['line_total'] !== '' && $product['line_total'] !== null && is_numeric($product['line_total'])) {
                        $lineTotal = (float)$product['line_total'];
                    } elseif ($productQty !== null && $productQty > 0 && $productPrice !== null) {
                        $lineTotal = round((float)$productQty * $productPrice, 2);
                    }
                    if ($lineTotal !== null) {
                        $grandTotal += $lineTotal;
                    }
                ?>
                <tr>
                    <td class="product-name"><?php echo htmlspecialchars($product['name']); ?></td>
                    <td class="product-quantity">
                        <?php 
                        if ($productQty !== null) {
                            echo number_format($productQty, 2) . ' ' . htmlspecialchars($productUnit);
                        } else {
                            echo '<span style="color: #999;">-</span>';
                        }
                        ?>
                    </td>
                    <td class="product-quantity" style="text-align: center;">
                        <?php 
                        if ($productPrice !== null) {
                            echo number_format($productPrice, 2);
                        } else {
                            echo '<span style="color: #999;">-</span>';
                        }
                        ?>
                    </td>
                    <td class="product-quantity" style="text-align: center; font-weight: 600;">
                        <?php 
                        if ($lineTotal !== null) {
                            echo number_format($lineTotal, 2);
                        } else {
                            echo '<span style="color: #999;">-</span>';
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="border-top: 2px solid #000; font-weight: 700; background-color: #f0f0f0;">
                    <td colspan="3" style="text-align: left; padding: 8px 5px;">الإجمالي</td>
                    <td style="text-align: center; padding: 8px 5px;"><?php echo number_format($grandTotal, 2); ?> ج.م</td>
                </tr>
                <?php 
                $receiptShippingFees = isset($r['shippingFees']) ? (float)$r['shippingFees'] : 0;
                $finalTotal = $grandTotal + $receiptShippingFees;
                if ($receiptShippingFees > -1): ?>
                <tr style="font-weight: 700; background-color: #f8f8f8;">
                    <td colspan="3" style="text-align: left; padding: 6px 5px;">رسوم الشحن</td>
                    <td style="text-align: center; padding: 6px 5px;"><?php echo number_format($receiptShippingFees, 2); ?> ج.م</td>
                </tr>
                <?php endif; ?>
                <tr style="font-weight: 700; background-color: #e8f5e9;">
                    <td colspan="3" style="text-align: left; padding: 8px 5px;">الإجمالي النهائي</td>
                    <td style="text-align: center; padding: 8px 5px;"><?php echo number_format($finalTotal, 2); ?> ج.م</td>
                </tr>
            </tfoot>
        </table>
        <?php else: ?>
        <table class="info-table">
            <tr>
                <td>لا توجد منتجات</td>
                <td>-</td>
            </tr>
            <?php 
            $receiptShippingFeesEmpty = isset($r['shippingFees']) ? (float)$r['shippingFees'] : 0;
            if ($receiptShippingFeesEmpty > -1): ?>
            <tr style="font-weight: 700;">
                <td>رسوم الشحن</td>
                <td><?php echo number_format($receiptShippingFeesEmpty, 2); ?> ج.م</td>
            </tr>
            <tr style="font-weight: 700; background-color: #e8f5e9;">
                <td>الإجمالي النهائي</td>
                <td><?php echo number_format($receiptShippingFeesEmpty, 2); ?> ج.م</td>
            </tr>
            <?php endif; ?>
        </table>
        <?php endif; ?>
        <div class="section-title">ملاحظات</div>
        <div class="task-details">
            <div style="font-size: 14px; line-height: 1.8; padding: 4px 0; font-weight: 500; color: #000;">
                <?php if ($displayNotes !== ''): ?>
                    <?php echo nl2br(htmlspecialchars($displayNotes)); ?>
                <?php else: ?>
                    <span style="color: #666; font-weight: 500;">لا توجد ملاحظات</span>
                <?php endif; ?>
                <?php if (!empty($task['customer_phone'])): ?>
                    <div style="margin-top: 8px; padding-top: 6px; border-top: 1px dashed #ccc;">
                        <i class="bi bi-telephone-fill" style="color: #0d6efd; margin-left: 4px;"></i><?php echo htmlspecialchars($task['customer_phone']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <script>
        var receiptIdsForTabs = [<?php echo implode(',', array_map(function($r) { return (int)$r['taskNumber']; }, $receipts)); ?>];
        function openEachReceiptInNewTab() {
            var base = window.location.pathname;
            if (receiptIdsForTabs.length === 0) return;
            receiptIdsForTabs.forEach(function(id, i) {
                setTimeout(function() {
                    window.open(base + '?id=' + id, '_blank', 'noopener');
                }, i * 400);
            });
        }
        window.onload = function() {
            if (window.location.search.includes('print=1')) {
                setTimeout(function() { window.print(); }, 500);
            }
        };
    </script>
</body>
</html>

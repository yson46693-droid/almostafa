<?php
/**
 * تقرير تفصيلي لحركات خزنة الشركة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/approval_system.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();

// الحصول على الفترة من GET
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-d');
$includePending = isset($_GET['include_pending']) && $_GET['include_pending'] == '1';
$groupByType = isset($_GET['group_by_type']) && $_GET['group_by_type'] == '1';

// التحقق من صحة التواريخ
if (!strtotime($dateFrom) || !strtotime($dateTo)) {
    die('تواريخ غير صحيحة');
}

if (strtotime($dateFrom) > strtotime($dateTo)) {
    die('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
}

// حساب ملخص الخزنة للفترة
$statusFilter = $includePending ? "('approved', 'pending')" : "('approved')";

// إصلاح SQL injection - استخدام prepared statements
$statusPlaceholders = $includePending ? "?, ?" : "?";
$statusParams = $includePending ? ['approved', 'pending'] : ['approved'];

// جلب جميع الحركات المالية من financial_transactions
$financialQuery = "
    SELECT 
        id,
        type,
        amount,
        description,
        reference_number,
        status,
        created_at,
        created_by,
        approved_by,
        approved_at,
        'financial_transactions' as source_table
    FROM financial_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND status IN ({$statusPlaceholders})
    ORDER BY created_at ASC
";
$financialParams = array_merge([$dateFrom, $dateTo], $statusParams);
$financialTransactions = $db->query($financialQuery, $financialParams) ?: [];

// جلب جميع الحركات من accountant_transactions
$accountantQuery = "
    SELECT 
        id,
        CASE 
            WHEN transaction_type = 'collection_from_sales_rep' THEN 'income'
            WHEN transaction_type = 'expense' THEN 'expense'
            WHEN transaction_type = 'income' THEN 'income'
            WHEN transaction_type = 'transfer' THEN 'transfer'
            WHEN transaction_type = 'payment' THEN 'payment'
            ELSE 'other'
        END as type,
        amount,
        description,
        reference_number,
        status,
        created_at,
        created_by,
        approved_by,
        approved_at,
        transaction_type,
        'accountant_transactions' as source_table
    FROM accountant_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND status IN ({$statusPlaceholders})
    ORDER BY created_at ASC
";
$accountantParams = array_merge([$dateFrom, $dateTo], $statusParams);
$accountantTransactions = $db->query($accountantQuery, $accountantParams) ?: [];

// دمج الحركات
$allTransactions = [];
foreach ($financialTransactions as $trans) {
    $trans['transaction_type'] = null;
    $allTransactions[] = $trans;
}
foreach ($accountantTransactions as $trans) {
    $allTransactions[] = $trans;
}

// ترتيب حسب التاريخ
usort($allTransactions, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

// حساب الإجماليات
$totalIncome = 0.0;
$totalExpense = 0.0;
$totalPayment = 0.0;
$totalSalaryAdjustments = 0.0;
$totalCustomerSettlements = 0.0;
$totalCollections = 0.0;

$transactionsByType = [
    'income' => [],
    'expense' => [],
    'payment' => [],
    'transfer' => [],
    'other' => []
];

foreach ($allTransactions as $trans) {
    $type = $trans['type'] ?? 'other';
    $amount = (float)($trans['amount'] ?? 0);
    
    if (!isset($transactionsByType[$type])) {
        $transactionsByType[$type] = [];
    }
    $transactionsByType[$type][] = $trans;
    
    // حساب الإجماليات
    if ($type === 'income') {
        $totalIncome += $amount;
        // التحقق من نوع الإيراد
        if (isset($trans['transaction_type']) && $trans['transaction_type'] === 'collection_from_sales_rep') {
            $totalCollections += $amount;
        }
    } elseif ($type === 'expense') {
        $totalExpense += $amount;
        // التحقق من نوع المصروف
        $description = strtolower($trans['description'] ?? '');
        if (strpos($description, 'تسوية راتب') !== false) {
            $totalSalaryAdjustments += $amount;
        } elseif (strpos($description, 'تسوية رصيد دائن لعميل') !== false || strpos($description, 'تسوية رصيد دائن ل') !== false) {
            $totalCustomerSettlements += $amount;
        }
    } elseif ($type === 'payment') {
        $totalPayment += $amount;
    }
}

$netBalance = $totalIncome - $totalExpense - $totalPayment;

// جلب أسماء المستخدمين
$userIds = [];
foreach ($allTransactions as $trans) {
    if (!empty($trans['created_by'])) $userIds[] = $trans['created_by'];
    if (!empty($trans['approved_by'])) $userIds[] = $trans['approved_by'];
}
$userIds = array_unique($userIds);

$users = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $usersResult = $db->query("SELECT id, full_name, username FROM users WHERE id IN ($placeholders)", $userIds) ?: [];
    foreach ($usersResult as $user) {
        $users[$user['id']] = $user;
    }
}

// دالة مساعدة للحصول على اسم المستخدم
function getUserName($userId, $users) {
    if (empty($userId) || !isset($users[$userId])) {
        return '-';
    }
    return htmlspecialchars($users[$userId]['full_name'] ?? $users[$userId]['username'] ?? '-', ENT_QUOTES, 'UTF-8');
}

// دالة لتنسيق التاريخ
function formatReportDate($date) {
    return date('Y/m/d', strtotime($date));
}

// دالة لتنسيق التاريخ والوقت
function formatReportDateTime($datetime) {
    return date('Y/m/d H:i', strtotime($datetime));
}

$typeLabels = [
    'income' => 'إيراد',
    'expense' => 'مصروف',
    'payment' => 'دفعة',
    'transfer' => 'تحويل',
    'other' => 'أخرى'
];

$statusLabels = [
    'pending' => 'معلق',
    'approved' => 'معتمد',
    'rejected' => 'مرفوض'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير تفصيلي - خزنة الشركة</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Cairo', 'Segoe UI', 'Tajawal', Tahoma, Geneva, Verdana, sans-serif;
            background: #ffffff;
            padding: 15px;
            color: #000000;
            line-height: 1.5;
            font-size: 12px;
        }
        
        .report-container {
            max-width: 210mm;
            margin: 0 auto;
            background: #ffffff;
            padding: 15mm;
        }
        
        .report-header {
            text-align: center;
            border-bottom: 2px solid #000000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .report-header h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #000000;
        }
        
        .report-header .period {
            font-size: 14px;
            margin-top: 8px;
            font-weight: 600;
        }
        
        .report-header .meta-info {
            margin-top: 10px;
            font-size: 11px;
            padding-top: 10px;
            border-top: 1px solid #000000;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .summary-section {
            border: 1px solid #000000;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .summary-section h2 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 15px;
            text-align: center;
            border-bottom: 1px solid #000000;
            padding-bottom: 8px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .summary-item {
            border: 1px solid #000000;
            padding: 10px;
            text-align: center;
        }
        
        .summary-item-label {
            font-size: 11px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .summary-item-value {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .section-title {
            border: 1px solid #000000;
            background: #f5f5f5;
            padding: 10px 15px;
            margin: 20px 0 10px 0;
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 20px;
            border: 1px solid #000000;
        }
        
        .transactions-table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
            background: #ffffff;
        }
        
        .transactions-table thead {
            background: #f5f5f5;
        }
        
        .transactions-table th {
            padding: 8px 6px;
            text-align: right;
            font-weight: 700;
            font-size: 11px;
            border: 1px solid #000000;
            white-space: nowrap;
        }
        
        .transactions-table td {
            padding: 6px;
            border: 1px solid #000000;
            font-size: 11px;
            vertical-align: top;
            word-wrap: break-word;
        }
        
        .transactions-table tbody tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .transactions-table tbody tr:nth-child(odd) {
            background: #ffffff;
        }
        
        .transactions-table tbody tr.total-row {
            background: #e5e5e5;
            font-weight: 700;
            font-size: 12px;
        }
        
        .transactions-table tbody tr td:first-child {
            font-weight: 700;
            text-align: center;
        }
        
        .type-badge {
            display: inline-block;
            padding: 3px 8px;
            border: 1px solid #000000;
            font-size: 10px;
            font-weight: 600;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border: 1px solid #000000;
            font-size: 10px;
            font-weight: 600;
        }
        
        .amount {
            font-weight: 700;
            font-size: 11px;
        }
        
        .footer {
            margin-top: 30px;
            padding: 15px;
            border-top: 1px solid #000000;
            text-align: center;
            font-size: 10px;
        }
        
        .footer p {
            margin: 5px 0;
        }
        
        .print-button {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 1000;
            background: #000000;
            color: white;
            border: 1px solid #000000;
            padding: 10px 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Cairo', sans-serif;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            border: 1px solid #000000;
            margin: 20px 0;
        }
        
        .empty-state h3 {
            font-size: 16px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .empty-state p {
            font-size: 12px;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .report-container {
                max-width: 100%;
                padding: 10mm;
            }
            
            .no-print {
                display: none !important;
            }
            
            .table-wrapper {
                overflow: visible;
                page-break-inside: auto;
            }
            
            .transactions-table {
                page-break-inside: auto;
            }
            
            .transactions-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            .summary-section {
                page-break-inside: avoid;
            }
            
            .section-title {
                page-break-after: avoid;
            }
            
            @page {
                size: A4;
                margin: 10mm;
            }
        }
        
        @media screen and (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .report-container {
                padding: 10px;
            }
            
            .table-wrapper {
                -webkit-overflow-scrolling: touch;
                overflow-x: scroll;
                overflow-y: visible;
            }
            
            .transactions-table {
                min-width: 1000px;
            }
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()" title="طباعة التقرير">
        طباعة التقرير
    </button>
    
    <div class="report-container">
        <div class="report-header">
            <h1>تقرير تفصيلي - خزنة الشركة</h1>
            <div class="period">
                الفترة: من <strong><?php echo formatReportDate($dateFrom); ?></strong> إلى <strong><?php echo formatReportDate($dateTo); ?></strong>
            </div>
            <div class="meta-info">
                <span>تاريخ الإنشاء: <strong><?php echo date('Y/m/d H:i'); ?></strong></span>
                <span>|</span>
                <span>أنشأه: <strong><?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8'); ?></strong></span>
            </div>
        </div>
        
        <div class="summary-section">
            <h2>ملخص الحركات المالية</h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-item-label">إجمالي الإيرادات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalIncome); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">إجمالي المصروفات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalExpense); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">إجمالي المدفوعات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalPayment); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">صافي الرصيد</div>
                    <div class="summary-item-value">
                        <?php echo formatCurrency($netBalance); ?>
                    </div>
                </div>
                <?php if ($totalCollections > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">التحصيلات من المندوبين</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalCollections); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($totalSalaryAdjustments > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">تسويات المرتبات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalSalaryAdjustments); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($totalCustomerSettlements > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">تسويات أرصدة العملاء</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalCustomerSettlements); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (empty($allTransactions)): ?>
            <div class="empty-state">
                <h3>لا توجد حركات مالية</h3>
                <p>لا توجد حركات مالية في الفترة المحددة (من <?php echo formatReportDate($dateFrom); ?> إلى <?php echo formatReportDate($dateTo); ?>)</p>
            </div>
        <?php elseif ($groupByType): ?>
            <?php foreach ($transactionsByType as $type => $transactions): ?>
                <?php if (!empty($transactions)): ?>
                    <div class="section-title">
                        <span><?php echo htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span style="margin-right: 10px; font-size: 12px;">
                            (<?php echo count($transactions); ?> حركة)
                        </span>
                    </div>
                    <div class="table-wrapper">
                    <table class="transactions-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>المبلغ</th>
                                <th>الوصف</th>
                                <th>الرقم المرجعي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $typeTotal = 0.0;
                            foreach ($transactions as $index => $trans): 
                                $typeTotal += (float)($trans['amount'] ?? 0);
                            ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php echo formatReportDateTime($trans['created_at']); ?>
                                    </td>
                                    <td>
                                        <span class="amount">
                                            <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($trans['amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td>
                                <?php if (!empty($trans['reference_number'])): ?>
                                    <?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="2" style="font-size: 15px;">
                                    <strong>الإجمالي</strong>
                                </td>
                                <td>
                                    <span class="amount">
                                        <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($typeTotal); ?>
                                    </span>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="section-title">
                <span>جميع الحركات المالية</span>
                <span style="margin-right: 10px; font-size: 12px;">
                    (<?php echo count($allTransactions); ?> حركة)
                </span>
            </div>
            <div class="table-wrapper">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>النوع</th>
                        <th>المبلغ</th>
                        <th>الوصف</th>
                        <th>الرقم المرجعي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allTransactions as $index => $trans): ?>
                        <?php $type = $trans['type'] ?? 'other'; ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <?php echo formatReportDateTime($trans['created_at']); ?>
                            </td>
                            <td>
                                <span class="type-badge">
                                    <?php echo htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="amount">
                                    <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($trans['amount']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <?php if (!empty($trans['reference_number'])): ?>
                                    <?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>تم إنشاء هذا التقرير تلقائياً من نظام إدارة خزنة الشركة</p>
            <p>© <?php echo date('Y'); ?> - جميع الحقوق محفوظة</p>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة (اختياري)
        // window.onload = function() {
        //     window.print();
        // }
    </script>
</body>
</html>

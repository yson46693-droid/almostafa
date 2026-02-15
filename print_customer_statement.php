<?php
/**
 * صفحة طباعة كشف حساب العميل (Statement)
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoices.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/customer_history.php';

requireRole(['accountant', 'sales', 'manager']);

$customerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$customerType = isset($_GET['type']) ? trim($_GET['type']) : 'normal'; // 'normal' or 'local'

if ($customerId <= 0) {
    die('معرف العميل غير صحيح');
}

$db = db();
$currentUser = getCurrentUser();

// جلب بيانات العميل حسب النوع
$customer = null;
$isLocalCustomer = ($customerType === 'local');

if ($isLocalCustomer) {
    // عميل محلي
    $customer = $db->queryOne(
        "SELECT c.*, NULL as sales_rep_name, NULL as sales_rep_username
         FROM local_customers c
         WHERE c.id = ?",
        [$customerId]
    );
} else {
    // عميل عادي (مندوب)
    // التحقق من ملكية العميل للمندوب (إذا كان المستخدم مندوب)
    if ($currentUser['role'] === 'sales') {
        $customer = $db->queryOne("SELECT id, created_by FROM customers WHERE id = ?", [$customerId]);
        if (!$customer || (int)($customer['created_by'] ?? 0) !== (int)$currentUser['id']) {
            die('غير مصرح لك بعرض كشف حساب هذا العميل');
        }
    }
    
    // جلب بيانات العميل
    $customer = $db->queryOne(
        "SELECT c.*, u.full_name as sales_rep_name, u.username as sales_rep_username
         FROM customers c
         LEFT JOIN users u ON c.created_by = u.id
         WHERE c.id = ?",
        [$customerId]
    );
}

if (!$customer) {
    die('العميل غير موجود');
}

// جلب كل الحركات للعميل
if ($isLocalCustomer) {
    $statementData = getLocalCustomerStatementData($customerId);
} else {
    $statementData = getCustomerStatementData($customerId);
}

$companyName = COMPANY_NAME;
$companySubtitle = 'نظام إدارة المبيعات';
$companyAddress = 'نطاق التوزيع :  الاسكندريه - شحن لجميع انحاء الجمهوريه';
$companyPhone = '01003533905';
$companyEmail = 'صفحة فيسبوك  : عسل نحل المصطفي';

$customerName = $customer['name'] ?? 'عميل';
$customerPhone = $customer['phone'] ?? '';
$customerAddress = $customer['address'] ?? '';
$salesRepName = $customer['sales_rep_name'] ?? $customer['sales_rep_username'] ?? null;
$customerCreatedAt = $customer['created_at'] ?? null;
// الرصيد المعروض = الرصيد المحسوب من سجل الحركات (لضمان تطابقه مع الجدول بعد التحصيل)
$displayBalance = isset($statementData['totals']['net_balance']) ? (float)$statementData['totals']['net_balance'] : (float)($customer['balance'] ?? 0);
$customerBalance = (float)($customer['balance'] ?? 0);

$statementDate = formatDate(date('Y-m-d'));
$customerJoinDate = $customerCreatedAt ? formatDate($customerCreatedAt) : 'غير محدد';

// باركود فيسبوك
$facebookPageUrl = 'https://www.facebook.com/share/1AHxSmFhEp/';
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($facebookPageUrl);

/**
 * جلب بيانات statement للعميل كسجل حركات مالية موحد (من الأقدم للأحدث مع الرصيد بعد كل معاملة)
 */
function getCustomerStatementData($customerId) {
    $db = db();
    $movements = [];
    
    // الفواتير: مدين = إجمالي الفاتورة
    $invoices = $db->query(
        "SELECT id, invoice_number, date, total_amount
         FROM invoices WHERE customer_id = ?",
        [$customerId]
    ) ?: [];
    foreach ($invoices as $inv) {
        $movements[] = [
            'sort_date' => $inv['date'],
            'sort_id' => (int)$inv['id'],
            'type_order' => 1, // فاتورة أولاً
            'type' => 'invoice',
            'date' => $inv['date'],
            'label' => 'فاتورة ' . ($inv['invoice_number'] ?? ''),
            'debit' => (float)($inv['total_amount'] ?? 0),
            'credit' => 0.0,
        ];
    }
    
    // المرتجعات: دائن
    $returns = $db->query(
        "SELECT id, return_number, return_date, refund_amount,
            (SELECT invoice_number FROM invoices WHERE id = returns.invoice_id) as invoice_number
         FROM returns WHERE customer_id = ?",
        [$customerId]
    ) ?: [];
    foreach ($returns as $ret) {
        $movements[] = [
            'sort_date' => $ret['return_date'],
            'sort_id' => (int)$ret['id'],
            'type_order' => 2, // مرتجع ثانياً
            'type' => 'return',
            'date' => $ret['return_date'],
            'label' => 'مرتجع ' . ($ret['return_number'] ?? '') . ($ret['invoice_number'] ? ' - فاتورة ' . $ret['invoice_number'] : ''),
            'debit' => 0.0,
            'credit' => (float)($ret['refund_amount'] ?? 0),
        ];
    }
    
    // التحصيلات: دائن
    $hasInvoiceIdColumn = false;
    try {
        $invoiceIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'invoice_id'");
        $hasInvoiceIdColumn = !empty($invoiceIdColumnCheck);
    } catch (Throwable $e) {
        $hasInvoiceIdColumn = false;
    }
    $collections = $db->query(
        $hasInvoiceIdColumn
            ? "SELECT id, amount, date, payment_method,
                (SELECT invoice_number FROM invoices WHERE id = collections.invoice_id) as invoice_number
               FROM collections WHERE customer_id = ?"
            : "SELECT id, amount, date, payment_method, NULL as invoice_number
               FROM collections WHERE customer_id = ?",
        [$customerId]
    ) ?: [];
    foreach ($collections as $col) {
        $movements[] = [
            'sort_date' => $col['date'],
            'sort_id' => (int)$col['id'],
            'type_order' => 3, // تحصيل ثالثاً
            'type' => 'collection',
            'date' => $col['date'],
            'label' => 'تحصيل #' . $col['id'] . ($col['invoice_number'] ? ' - فاتورة ' . $col['invoice_number'] : ''),
            'debit' => 0.0,
            'credit' => (float)($col['amount'] ?? 0),
        ];
    }
    
    // إذا كان إجمالي الدائن أكبر من المدين، نضيف "رصيد أول المدة" لتصفير الرصيد الصافي
    $sumDebit = 0.0;
    $sumCredit = 0.0;
    foreach ($movements as $m) {
        $sumDebit += $m['debit'];
        $sumCredit += $m['credit'];
    }
    if ($sumCredit > $sumDebit) {
        $openingDebit = round($sumCredit - $sumDebit, 2);
        $minDate = !empty($movements) ? min(array_column($movements, 'sort_date')) : date('Y-m-d');
        array_unshift($movements, [
            'sort_date' => $minDate,
            'sort_id' => 0,
            'type_order' => 0,
            'type' => 'opening',
            'date' => $minDate,
            'label' => 'رصيد أول المدة / دين سابق',
            'debit' => $openingDebit,
            'credit' => 0.0,
        ]);
    }
    
    // ترتيب من الأقدم للأحدث، وفي نفس اليوم: فاتورة ثم مرتجع ثم تحصيل
    usort($movements, function ($a, $b) {
        $c = strcmp($a['sort_date'], $b['sort_date']);
        if ($c !== 0) return $c;
        $to = ($a['type_order'] ?? 9) - ($b['type_order'] ?? 9);
        return $to !== 0 ? $to : ($a['sort_id'] - $b['sort_id']);
    });
    
    // حساب الرصيد بعد كل معاملة
    $balance = 0.0;
    foreach ($movements as &$m) {
        $balance += $m['debit'] - $m['credit'];
        $m['balance_after'] = $balance;
    }
    unset($m);
    
    $totals = ['total_debit' => 0, 'total_credit' => 0, 'net_balance' => $balance];
    foreach ($movements as $m) {
        $totals['total_debit'] += $m['debit'];
        $totals['total_credit'] += $m['credit'];
    }
    
    return [
        'movements' => $movements,
        'totals' => $totals,
    ];
}

/**
 * جلب بيانات statement للعميل المحلي كسجل حركات مالية موحد (من الأقدم للأحدث مع الرصيد بعد كل معاملة)
 */
function getLocalCustomerStatementData($customerId) {
    $db = db();
    $movements = [];
    
    // الفواتير المحلية: مدين
    $localInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
    if (!empty($localInvoicesTableExists)) {
        $invoices = $db->query(
            "SELECT id, invoice_number, date, total_amount
             FROM local_invoices WHERE customer_id = ?",
            [$customerId]
        ) ?: [];
        foreach ($invoices as $inv) {
            $movements[] = [
                'sort_date' => $inv['date'],
                'sort_id' => (int)$inv['id'],
                'type_order' => 1,
                'type' => 'invoice',
                'date' => $inv['date'],
                'label' => 'فاتورة ' . ($inv['invoice_number'] ?? ''),
                'debit' => (float)($inv['total_amount'] ?? 0),
                'credit' => 0.0,
            ];
        }
    }
    
    // الفواتير الورقية المحلية: مدين (تزيد رصيد العميل)
    $paperInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customer_paper_invoices'");
    if (!empty($paperInvoicesTableExists)) {
        $hasInvNumCol = $db->queryOne("SHOW COLUMNS FROM local_customer_paper_invoices LIKE 'invoice_number'");
        $paperInvoices = $db->query(
            "SELECT id, " . (!empty($hasInvNumCol) ? "invoice_number, " : "") . "total_amount, created_at FROM local_customer_paper_invoices WHERE customer_id = ?",
            [$customerId]
        ) ?: [];
        foreach ($paperInvoices as $pi) {
            $invNum = (!empty($hasInvNumCol) && !empty(trim($pi['invoice_number'] ?? ''))) ? trim($pi['invoice_number']) : ('#' . $pi['id']);
            $movements[] = [
                'sort_date' => date('Y-m-d', strtotime($pi['created_at'])),
                'sort_id' => (int)$pi['id'],
                'type_order' => 1,
                'type' => 'paper_invoice',
                'date' => $pi['created_at'],
                'label' => 'فاتورة ورقية ' . $invNum,
                'debit' => (float)($pi['total_amount'] ?? 0),
                'credit' => 0.0,
            ];
        }
    }
    
    // المرتجعات المحلية: دائن
    $localReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_returns'");
    if (!empty($localReturnsTableExists)) {
        $returns = $db->query(
            "SELECT id, return_number, return_date, refund_amount
             FROM local_returns WHERE customer_id = ?",
            [$customerId]
        ) ?: [];
        foreach ($returns as $ret) {
            $movements[] = [
                'sort_date' => $ret['return_date'],
                'sort_id' => (int)$ret['id'],
                'type_order' => 2,
                'type' => 'return',
                'date' => $ret['return_date'],
                'label' => 'مرتجع ' . ($ret['return_number'] ?? ''),
                'debit' => 0.0,
                'credit' => (float)($ret['refund_amount'] ?? 0),
            ];
        }
    }
    
    // التحصيلات المحلية: دائن
    $localCollectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_collections'");
    if (!empty($localCollectionsTableExists)) {
        $collections = $db->query(
            "SELECT id, amount, date, payment_method
             FROM local_collections WHERE customer_id = ?",
            [$customerId]
        ) ?: [];
        foreach ($collections as $col) {
            $movements[] = [
                'sort_date' => $col['date'],
                'sort_id' => (int)$col['id'],
                'type_order' => 3,
                'type' => 'collection',
                'date' => $col['date'],
                'label' => 'تحصيل #' . $col['id'],
                'debit' => 0.0,
                'credit' => (float)($col['amount'] ?? 0),
            ];
        }
    }
    
    // إذا كان إجمالي الدائن أكبر من المدين (تحصيلات/مرتجعات بدون فواتير مسجلة)، نضيف "رصيد أول المدة" لتصفير الرصيد الصافي
    $sumDebit = 0.0;
    $sumCredit = 0.0;
    foreach ($movements as $m) {
        $sumDebit += $m['debit'];
        $sumCredit += $m['credit'];
    }
    if ($sumCredit > $sumDebit) {
        $openingDebit = round($sumCredit - $sumDebit, 2);
        $minDate = !empty($movements) ? min(array_column($movements, 'sort_date')) : date('Y-m-d');
        array_unshift($movements, [
            'sort_date' => $minDate,
            'sort_id' => 0,
            'type_order' => 0,
            'type' => 'opening',
            'date' => $minDate,
            'label' => 'رصيد أول المدة / دين سابق',
            'debit' => $openingDebit,
            'credit' => 0.0,
        ]);
    }
    
    // ترتيب من الأقدم للأحدث، وفي نفس اليوم: فاتورة ثم مرتجع ثم تحصيل
    usort($movements, function ($a, $b) {
        $c = strcmp($a['sort_date'], $b['sort_date']);
        if ($c !== 0) return $c;
        $to = ($a['type_order'] ?? 9) - ($b['type_order'] ?? 9);
        return $to !== 0 ? $to : ($a['sort_id'] - $b['sort_id']);
    });
    
    // حساب الرصيد بعد كل معاملة
    $balance = 0.0;
    foreach ($movements as &$m) {
        $balance += $m['debit'] - $m['credit'];
        $m['balance_after'] = $balance;
    }
    unset($m);
    
    $totals = ['total_debit' => 0, 'total_credit' => 0, 'net_balance' => $balance];
    foreach ($movements as $m) {
        $totals['total_debit'] += $m['debit'];
        $totals['total_credit'] += $m['credit'];
    }
    
    return [
        'movements' => $movements,
        'totals' => $totals,
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كشف حساب - <?php echo htmlspecialchars($customerName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 0;
            }
            .statement-wrapper {
                box-shadow: none;
                border: none;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
            color: #1f2937;
        }
        
        .statement-wrapper {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            padding: 32px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
        }
        
        .statement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .brand-block {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .logo-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 24px;
            background: linear-gradient(135deg,rgb(6, 59, 134) 0%,rgb(3, 71, 155) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 64px;
            font-weight: bold;
            overflow: hidden;
            position: relative;
        }
        
        .company-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 4px;
        }
        
        .logo-letter {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }
        
        .company-name {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        
        .company-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .statement-meta {
            text-align: left;
        }
        
        .statement-title {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .statement-date {
            font-size: 14px;
            color: #6b7280;
        }
        
        .customer-info {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .customer-info-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 12px;
        }
        
        .customer-info-item {
            display: flex;
            flex-direction: column;
        }
        
        .customer-info-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .customer-info-value {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        
        .transactions-table th {
            background: #f9fafb;
            padding: 12px;
            text-align: right;
            font-weight: 600;
            font-size: 14px;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .transactions-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .transactions-table tr:hover {
            background: #f9fafb;
        }
        
        .amount-positive {
            color: #059669;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #dc2626;
            font-weight: 600;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-partial {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .summary-section {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .summary-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 18px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 2px solid #1f2937;
        }
        
        .summary-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .summary-value {
            color: #1f2937;
            font-weight: 600;
            font-size: 16px;
        }
        
        .print-button {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <i class="bi bi-printer me-2"></i>طباعة
    </button>
    
    <div class="statement-wrapper">
        <header class="statement-header">
            <div class="brand-block">
                <div class="logo-placeholder">
                    <img src="<?php echo getRelativeUrl('assets/icons/icon-192x192.svg'); ?>" alt="Logo" class="company-logo-img" onerror="this.onerror=null; this.src='<?php echo getRelativeUrl('assets/icons/icon-192x192.png'); ?>'; this.onerror=function(){this.style.display='none'; this.nextElementSibling.style.display='flex';};">
                    <span class="logo-letter" style="display:none;"><?php echo mb_substr($companyName, 0, 1); ?></span>
                </div>
                <div>
                    <h1 class="company-name"><?php echo htmlspecialchars($companyName); ?></h1>
                    <div class="company-subtitle"><?php echo htmlspecialchars($companySubtitle); ?></div>
                </div>
            </div>
            <div class="statement-meta">
                <div class="statement-title">سجل الحركات المالية للعميل</div>
                <div class="statement-date">تاريخ الطباعة: <?php echo $statementDate; ?></div>
            </div>
        </header>
        
        <div class="customer-info">
            <div class="customer-info-row">
                <div class="customer-info-item">
                    <div class="customer-info-label">اسم العميل</div>
                    <div class="customer-info-value"><?php echo htmlspecialchars($customerName); ?></div>
                </div>
                <div class="customer-info-item">
                    <div class="customer-info-label">رقم الهاتف</div>
                    <div class="customer-info-value"><?php echo htmlspecialchars($customerPhone ?: '-'); ?></div>
                </div>
                <div class="customer-info-item">
                    <div class="customer-info-label">العنوان</div>
                    <div class="customer-info-value"><?php echo htmlspecialchars($customerAddress ?: '-'); ?></div>
                </div>
            </div>
            <div class="customer-info-row">
                <div class="customer-info-item">
                    <div class="customer-info-label">تاريخ الإضافة</div>
                    <div class="customer-info-value"><?php echo $customerJoinDate; ?></div>
                </div>
                <?php if (!$isLocalCustomer): ?>
                <div class="customer-info-item">
                    <div class="customer-info-label">مندوب المبيعات</div>
                    <div class="customer-info-value"><?php echo htmlspecialchars($salesRepName ?: '-'); ?></div>
                </div>
                <?php else: ?>
                <div class="customer-info-item">
                    <div class="customer-info-label">نوع العميل</div>
                    <div class="customer-info-value">عميل محلي</div>
                </div>
                <?php endif; ?>
                <div class="customer-info-item">
                    <div class="customer-info-label">الرصيد الحالي (حسب سجل الحركات)</div>
                    <div class="customer-info-value <?php echo $displayBalance >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                        <?php echo formatCurrency(abs($displayBalance)); ?>
                        <?php echo $displayBalance < 0 ? ' (دائن)' : ' (مدين)'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- سجل الحركات المالية (جدول واحد مرتب من الأقدم للأحدث مع الرصيد بعد كل معاملة) -->
        <h2 class="section-title">سجل الحركات المالية للعميل</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>البيان</th>
                    <th>مدين</th>
                    <th>دائن</th>
                    <th>الرصيد بعد المعاملة</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $movements = $statementData['movements'] ?? [];
                if (empty($movements)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">لا توجد حركات مالية</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                        <tr>
                            <td><?php echo formatDate($m['date']); ?></td>
                            <td><?php echo htmlspecialchars($m['label']); ?></td>
                            <td class="<?php echo $m['debit'] > 0 ? 'amount-positive' : ''; ?>">
                                <?php echo $m['debit'] > 0 ? formatCurrency($m['debit']) : '-'; ?>
                            </td>
                            <td class="<?php echo $m['credit'] > 0 ? 'amount-negative' : ''; ?>">
                                <?php echo $m['credit'] > 0 ? formatCurrency($m['credit']) : '-'; ?>
                            </td>
                            <td class="<?php echo $m['balance_after'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                <?php echo formatCurrency($m['balance_after']); ?>
                                <?php echo $m['balance_after'] < 0 ? ' (دائن)' : ' (مدين)'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- الملخص -->
        <div class="summary-section">
            <h2 class="section-title" style="margin-top: 0;">ملخص الحساب</h2>
            <div class="summary-row">
                <span class="summary-label">إجمالي المدين</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($statementData['totals']['total_debit'] ?? 0); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">إجمالي الدائن</span>
                <span class="summary-value amount-negative"><?php echo formatCurrency($statementData['totals']['total_credit'] ?? 0); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">الرصيد الصافي</span>
                <span class="summary-value <?php echo ($statementData['totals']['net_balance'] ?? 0) >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                    <?php echo formatCurrency(abs($statementData['totals']['net_balance'] ?? 0)); ?>
                    <?php echo ($statementData['totals']['net_balance'] ?? 0) < 0 ? ' (دائن)' : ' (مدين)'; ?>
                </span>
            </div>
        </div>
        
        <footer style="margin-top: 32px; padding-top: 24px; border-top: 2px solid #e2e8f0; text-align: center; color: #6b7280; font-size: 14px;">
            <div style="margin-bottom: 8px;">نشكركم على ثقتكم بنا</div>
            <div>لأي استفسارات يرجى التواصل على: <?php echo htmlspecialchars($companyPhone); ?></div>
        </footer>
    </div>
</body>
</html>


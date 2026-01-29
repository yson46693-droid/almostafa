<?php
/**
 * طباعة فاتورة بيع بتصميم مطابق للصورة (لوجو، بطاقات، بيانات الشركة/العميل، جدول المنتجات، الملخص)
 * بمقاس 80mm للطباعة الحرارية
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoices.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['accountant', 'sales', 'manager']);

$invoiceId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($invoiceId <= 0) {
    die('رقم الفاتورة غير صحيح');
}

$invoice = getInvoice($invoiceId);

if (!$invoice) {
    die('الفاتورة غير موجودة');
}

// تطبيع عناصر الفاتورة لقالب invoice_print (total_price إن لزم)
if (!empty($invoice['items']) && is_array($invoice['items'])) {
    foreach ($invoice['items'] as &$item) {
        if (!isset($item['total_price']) || $item['total_price'] === null) {
            $qty = isset($item['quantity']) ? (float)$item['quantity'] : 0;
            $up = isset($item['unit_price']) ? (float)$item['unit_price'] : 0;
            $item['total_price'] = round($qty * $up, 2);
        }
    }
    unset($item);
}

$selectedInvoice = $invoice;
$invoiceData = $invoice;

$companyName = COMPANY_NAME;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة بيع <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 8px;
            background: #e5e7eb;
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .print-sale-80mm-wrap {
            max-width: 80mm;
            margin: 0 auto;
            background: #fff;
            min-height: 100px;
        }
        .no-print {
            max-width: 80mm;
            margin: 0 auto 12px auto;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .no-print .btn { font-size: 13px; padding: 6px 12px; }
        @media print {
            html, body {
                width: 80mm !important;
                max-width: 80mm !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow-x: hidden !important;
                background: #fff !important;
            }
            .no-print { display: none !important; }
            .print-sale-80mm-wrap {
                width: 80mm !important;
                max-width: 80mm !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                box-shadow: none !important;
            }
            @page { size: 80mm auto; margin: 3mm; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>طباعة
        </button>
        <a href="<?php echo htmlspecialchars(getRelativeUrl('dashboard/accountant.php?page=invoices')); ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-right me-1"></i>رجوع
        </a>
    </div>
    <div class="print-sale-80mm-wrap">
        <?php include __DIR__ . '/modules/accountant/invoice_print.php'; ?>
    </div>
    <script>
        if (window.location.search.indexOf('print=1') !== -1) {
            setTimeout(function() { window.print(); }, 400);
        }
    </script>
</body>
</html>

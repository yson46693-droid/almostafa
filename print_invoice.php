<?php
/**
 * صفحة طباعة الفاتورة (منفصلة للطباعة)
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

// تحديد نوع الطباعة (A4 أو 80mm)
$printFormat = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'a4';
if (!in_array($printFormat, ['a4', '80mm'])) {
    $printFormat = 'a4';
}

// تمرير المتغيرات المطلوبة
$selectedInvoice = $invoice;
$invoiceData = $invoice;

$companyName = COMPANY_NAME;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 10px;
            background: #f5f5f5;
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }

        .invoice-container {
            max-width: <?php echo $printFormat === '80mm' ? '80mm' : '800px'; ?>;
            margin: 0 auto;
            padding: <?php echo $printFormat === '80mm' ? '0' : '30px'; ?>;
            background: white;
            box-shadow: <?php echo $printFormat === '80mm' ? 'none' : '0 0 20px rgba(0,0,0,0.1)'; ?>;
            width: 100%;
        }
        
        <?php if ($printFormat === '80mm'): ?>
        .no-print {
            max-width: 80mm;
            margin: 0 auto 15px auto;
            padding: 0 10px;
        }
        <?php endif; ?>

        @media (max-width: 768px) {
            body {
                padding: 5px !important;
                margin: 0 !important;
            }

            .invoice-container {
                max-width: 100% !important;
                width: 100% !important;
                padding: 15px 10px !important;
                margin: 0 !important;
                box-shadow: none !important;
            }

            .no-print {
                padding: 10px !important;
            }

            .no-print .btn {
                font-size: 14px !important;
                padding: 8px 16px !important;
            }
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
            }
            .invoice-container {
                box-shadow: none !important;
                border: none !important;
                padding: 20px !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            @page {
                size: <?php echo $printFormat === '80mm' ? '80mm auto' : 'A4'; ?>;
                margin: <?php echo $printFormat === '80mm' ? '0mm' : '1cm'; ?>;
            }
            
            <?php if ($printFormat === '80mm'): ?>
            .invoice-container {
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            <?php endif; ?>
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <?php if ($printFormat === '80mm'): ?>
        <!-- الأزرار خارج الفاتورة للطباعة 80mm -->
        <div class="row mb-3 no-print">
            <div class="col-12 text-end">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>طباعة
                </button>
                <button class="btn btn-success" onclick="shareInvoiceToChat(<?php echo $invoiceId; ?>)">
                    <i class="bi bi-share me-2"></i>مشاركة إلى الشات
                </button>
                <a href="<?php echo getRelativeUrl('dashboard/accountant.php?page=invoices'); ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>رجوع
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="invoice-container">
            <?php if ($printFormat !== '80mm'): ?>
            <!-- الأزرار داخل الفاتورة للطباعة A4 -->
            <div class="row mb-4 no-print">
                <div class="col-12 text-end">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>طباعة
                    </button>
                    <button class="btn btn-success" onclick="shareInvoiceToChat(<?php echo $invoiceId; ?>)">
                        <i class="bi bi-share me-2"></i>مشاركة إلى الشات
                    </button>
                    <a href="<?php echo getRelativeUrl('dashboard/accountant.php?page=invoices'); ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-2"></i>رجوع
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php 
            require_once __DIR__ . '/includes/path_helper.php';
            
            // تعريف ACCESS_ALLOWED للسماح بالوصول إلى ملفات الطباعة
            if (!defined('ACCESS_ALLOWED')) {
                define('ACCESS_ALLOWED', true);
            }
            
            // استخدام old-recipt.php للطباعة A4
            if ($printFormat === 'a4') {
                include __DIR__ . '/old-recipt.php';
            } else {
                // استخدام تصميم 80mm
                include __DIR__ . '/print_invoice_80mm.php';
            }
            ?>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة
        window.onload = function() {
            if (window.location.search.includes('print=')) {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        };

        async function shareInvoiceToChat(invoiceId) {
            if (!invoiceId || invoiceId <= 0) {
                alert('رقم الفاتورة غير صحيح');
                return;
            }

            // إظهار مؤشر التحميل
            const button = event.target.closest('button');
            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>جاري الإرسال...';

            try {
                const response = await fetch('<?php echo getRelativeUrl("api/chat/share_invoice.php"); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        invoice_id: invoiceId
                    })
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'تعذر مشاركة الفاتورة');
                }

                alert('تم مشاركة الفاتورة بنجاح في الشات');
                
                // فتح الشات إذا كان متاحاً
                if (typeof window.openChat !== 'undefined') {
                    window.openChat();
                }
            } catch (error) {
                console.error('Error sharing invoice:', error);
                alert(error.message || 'حدث خطأ أثناء مشاركة الفاتورة');
            } finally {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        }
    </script>
</body>
</html>


<?php
/**
 * تصميم فاتورة 80mm محسّن للطباعة الحرارية
 */

// السماح بالوصول من print_invoice.php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

$invoiceData = $selectedInvoice ?? $invoice ?? null;

if (!$invoiceData) {
    die('الفاتورة غير موجودة');
}

$companyName      = COMPANY_NAME;
$companyAddress   = $invoiceData['company_address'] ?? 'نطاق التوزيع :  الاسكندريه - شحن لجميع انحاء الجمهوريه';
$companyPhone     = $invoiceData['company_phone']   ?? '01003533905';

$issueDate = formatDate($invoiceData['date']);
$dueDateRaw = $invoiceData['due_date'] ?? null;
$dueDate = !empty($dueDateRaw) ? formatDate($dueDateRaw) : 'أجل غير مسمى';
$status    = $invoiceData['status'] ?? 'draft';

$customerName    = $invoiceData['customer_name']    ?? 'عميل نقدي';
$customerPhone   = $invoiceData['customer_phone']   ?? '';
$customerAddress = $invoiceData['customer_address'] ?? '';
$repName         = $invoiceData['sales_rep_name']   ?? null;

$subtotal        = (float)($invoiceData['subtotal'] ?? 0);
$discount        = (float)($invoiceData['discount_amount'] ?? 0);
$total           = (float)($invoiceData['total_amount'] ?? 0);
$paidAmount      = (float)($invoiceData['paid_amount'] ?? 0);
$creditUsed      = (float)($invoiceData['credit_used'] ?? 0);

// حساب المبلغ المتبقي بشكل صحيح
if (isset($invoiceData['remaining_amount']) && $invoiceData['remaining_amount'] !== null) {
    $dueAmount = (float)$invoiceData['remaining_amount'];
    if ($dueAmount < 0) {
        $dueAmount = 0;
    } elseif ($dueAmount > $total) {
        $dueAmount = max(0, $total - $paidAmount);
    }
} else {
    $dueAmount = max(0, round($total - $paidAmount, 2));
}

if ($paidAmount >= $total || abs($paidAmount - $total) < 0.01) {
    $dueAmount = 0;
}

$notes = trim((string)($invoiceData['notes'] ?? ''));

$statusLabelsMap = [
    'draft'     => 'مسودة',
    'approved'  => 'معتمدة',
    'paid'      => 'مدفوعة',
    'partial'   => 'مدفوع جزئياً',
    'cancelled' => 'ملغاة',
    'sent'      => 'مرسلة',
    'overdue'   => 'متأخرة'
];
$statusLabel = $statusLabelsMap[$status] ?? 'مسودة';
?>

<div class="invoice-wrapper-80mm">
    <div class="invoice-80mm">
        <!-- رأس الفاتورة -->
        <div class="invoice-header-80mm">
            <div class="company-name-80mm"><?php echo htmlspecialchars($companyName); ?></div>
            <div class="company-address-80mm"><?php echo htmlspecialchars($companyAddress); ?></div>
            <div class="company-phone-80mm"><?php echo htmlspecialchars($companyPhone); ?></div>
        </div>

        <div class="invoice-divider"></div>

        <!-- معلومات الفاتورة -->
        <div class="invoice-info-80mm">
            <div class="info-row-dual">
                <div class="info-item">
                    <span class="label">فاتورة رقم:</span>
                    <span class="value"><?php echo htmlspecialchars($invoiceData['invoice_number']); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">التاريخ:</span>
                    <span class="value"><?php echo $issueDate; ?></span>
                </div>
            </div>
            <?php if ($dueDate !== 'أجل غير مسمى'): ?>
            <div class="info-row-dual">
                <div class="info-item">
                    <span class="label">تاريخ الاستحقاق:</span>
                    <span class="value"><?php echo $dueDate; ?></span>
                </div>
                <div class="info-item">
                    <span class="label">الحالة:</span>
                    <span class="value"><?php echo $statusLabel; ?></span>
                </div>
            </div>
            <?php else: ?>
            <div class="info-row">
                <span class="label">الحالة:</span>
                <span class="value"><?php echo $statusLabel; ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="invoice-divider"></div>

        <!-- بيانات العميل -->
        <div class="customer-info-80mm">
            <div class="section-title">بيانات العميل</div>
            <div class="info-row-dual">
                <div class="info-item">
                    <span class="label">الاسم:</span>
                    <span class="value"><?php echo htmlspecialchars($customerName); ?></span>
                </div>
                <?php if (!empty($customerPhone)): ?>
                <div class="info-item">
                    <span class="label">الهاتف:</span>
                    <span class="value"><?php echo htmlspecialchars($customerPhone); ?></span>
                </div>
                <?php else: ?>
                <div class="info-item"></div>
                <?php endif; ?>
            </div>
            <?php if (!empty($customerAddress) || $repName): ?>
            <div class="info-row-dual">
                <?php if (!empty($customerAddress)): ?>
                <div class="info-item">
                    <span class="label">العنوان:</span>
                    <span class="value"><?php echo htmlspecialchars($customerAddress); ?></span>
                </div>
                <?php else: ?>
                <div class="info-item"></div>
                <?php endif; ?>
                <?php if ($repName): ?>
                <div class="info-item">
                    <span class="label">المندوب:</span>
                    <span class="value"><?php echo htmlspecialchars($repName); ?></span>
                </div>
                <?php else: ?>
                <div class="info-item"></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="invoice-divider"></div>

        <!-- جدول المنتجات -->
        <div class="items-section-80mm">
            <div class="section-title">المنتجات</div>
            <table class="items-table-80mm">
                <thead>
                    <tr>
                        <th class="col-product">المنتج</th>
                        <th class="col-batch">رقم التشغيلة</th>
                        <th class="col-qty">الكمية</th>
                        <th class="col-price">السعر</th>
                        <th class="col-total">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (empty($invoiceData['items']) || !is_array($invoiceData['items'])) {
                        echo '<tr><td colspan="5" style="text-align: center; padding: 8px;">لا توجد منتجات</td></tr>';
                    } else {
                        foreach ($invoiceData['items'] as $item): 
                            $quantity   = isset($item['quantity']) ? number_format($item['quantity'], 2) : '0.00';
                            $unitPrice  = isset($item['unit_price']) ? formatCurrency($item['unit_price']) : formatCurrency(0);
                            $totalPrice = isset($item['total_price']) ? formatCurrency($item['total_price']) : formatCurrency(0);
                            $batchNumber = $item['batch_number'] ?? null;
                    ?>
                    <tr>
                        <td class="col-product"><?php echo htmlspecialchars($item['product_name'] ?? 'منتج'); ?></td>
                        <td class="col-batch"><?php echo $batchNumber ? htmlspecialchars($batchNumber) : '-'; ?></td>
                        <td class="col-qty"><?php echo $quantity; ?></td>
                        <td class="col-price"><?php echo $unitPrice; ?></td>
                        <td class="col-total"><?php echo $totalPrice; ?></td>
                    </tr>
                    <?php 
                        endforeach;
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <div class="invoice-divider"></div>

        <!-- الملخص المالي -->
        <div class="summary-section-80mm">
            <div class="summary-row">
                <span class="label">المجموع الفرعي:</span>
                <span class="value"><?php echo formatCurrency($subtotal); ?></span>
            </div>
            <?php if ($discount > 0): ?>
            <div class="summary-row">
                <span class="label">الخصم:</span>
                <span class="value text-danger">-<?php echo formatCurrency($discount); ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row total">
                <span class="label">الإجمالي النهائي:</span>
                <span class="value"><?php echo formatCurrency($total); ?></span>
            </div>
            <?php 
            $cashPaidAmount = max(0, $paidAmount - $creditUsed);
            if ($cashPaidAmount > 0.01): 
            ?>
            <div class="summary-row">
                <span class="label">المدفوع:</span>
                <span class="value text-success"><?php echo formatCurrency($cashPaidAmount); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($creditUsed > 0): ?>
            <div class="summary-row">
                <span class="label">من رصيد العميل:</span>
                <span class="value text-info"><?php echo formatCurrency($creditUsed); ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row due">
                <span class="label">المتبقي:</span>
                <span class="value remaining-amount"><?php echo formatCurrency($dueAmount); ?></span>
            </div>
        </div>

        <?php if (!empty($notes)): ?>
        <div class="invoice-divider"></div>
        <div class="notes-section-80mm">
            <div class="section-title">ملاحظات</div>
            <div class="notes-text"><?php echo nl2br(htmlspecialchars($notes)); ?></div>
        </div>
        <?php endif; ?>

        <div class="invoice-divider"></div>
        
        <!-- تذييل الفاتورة -->
        <div class="invoice-footer-80mm">
            <div class="thanks">نشكركم على ثقتكم بنا</div>
            <div class="terms">يرجى التأكد من مطابقة المنتجات عند الاستلام</div>
        </div>
    </div>
</div>

<style>
/* التصميم الأساسي */
.invoice-wrapper-80mm {
    width: 100%;
    max-width: 80mm;
    margin: 0 auto;
}

.invoice-80mm {
    font-family: 'Tajawal', 'Arial', 'Helvetica', sans-serif;
    width: 100%;
    max-width: 80mm;
    margin: 0;
    padding: 0;
    background: #ffffff;
    color: #000;
    font-size: 10px;
    line-height: 1.4;
    box-sizing: border-box;
}

/* رأس الفاتورة */
.invoice-header-80mm {
    text-align: center;
    padding: 3mm 1mm 2mm 1mm;
    border-bottom: 2px solid #000;
}

.company-name-80mm {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 3px;
    text-transform: uppercase;
    line-height: 1.2;
}

.company-address-80mm,
.company-phone-80mm {
    font-size: 10px;
    margin-bottom: 2px;
    line-height: 1.3;
}

/* الفواصل */
.invoice-divider {
    border-top: 1px solid #000;
    margin: 3px 0;
}

/* معلومات الفاتورة والعميل */
.invoice-info-80mm,
.customer-info-80mm {
    padding: 2mm 1mm;
}

.section-title {
    font-weight: 700;
    font-size: 11px;
    margin-bottom: 4px;
    text-align: center;
    background: #f0f0f0;
    padding: 3px 2px;
    border: 1px solid #ddd;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 3px;
    font-size: 10px;
    line-height: 1.4;
}

.info-row .label {
    font-weight: 600;
    margin-left: 6px;
    white-space: nowrap;
    flex-shrink: 0;
}

.info-row .value {
    text-align: left;
    flex: 1;
    font-weight: 500;
    min-width: 0;
}

.info-row-dual {
    display: flex;
    justify-content: space-between;
    margin-bottom: 3px;
    gap: 6px;
    font-size: 10px;
}

.info-row-dual .info-item {
    flex: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-width: 0;
}

.info-row-dual .info-item .label {
    font-weight: 600;
    margin-left: 3px;
    white-space: nowrap;
    flex-shrink: 0;
    font-size: 10px;
}

.info-row-dual .info-item .value {
    text-align: left;
    flex: 1;
    font-weight: 500;
    font-size: 10px;
    min-width: 0;
}

/* جدول المنتجات */
.items-section-80mm {
    padding: 2mm 1mm;
}

.items-table-80mm {
    width: 100%;
    border-collapse: collapse;
    font-size: 9px;
    margin-top: 3px;
    table-layout: fixed;
    border-spacing: 0;
}

.items-table-80mm thead {
    background: #f0f0f0;
    border-bottom: 2px solid #000;
}

.items-table-80mm th {
    padding: 4px 2px;
    text-align: center;
    font-weight: 700;
    font-size: 9px;
    border-left: 1px solid #000;
    line-height: 1.3;
}

.items-table-80mm th:first-child {
    border-left: none;
}

.items-table-80mm td {
    padding: 3px 2px;
    text-align: center;
    border-bottom: 1px solid #000;
    border-left: 1px solid #000;
    font-size: 9px;
    line-height: 1.3;
    vertical-align: top;
    font-weight: 500;
}

.items-table-80mm td:first-child {
    border-left: none;
    text-align: right;
}

.items-table-80mm .col-product {
    width: 30%;
    text-align: right;
    padding-right: 2px;
}

.items-table-80mm .col-batch {
    width: 20%;
    font-size: 8px;
}

.items-table-80mm .col-qty {
    width: 12%;
}

.items-table-80mm .col-price {
    width: 19%;
    text-align: left;
    padding-left: 2px;
}

.items-table-80mm .col-total {
    width: 19%;
    text-align: left;
    font-weight: 600;
    padding-left: 2px;
}

/* الملخص المالي */
.summary-section-80mm {
    padding: 2mm 1mm;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 3px;
    font-size: 10px;
    line-height: 1.4;
}

.summary-row .label {
    font-weight: 600;
    margin-left: 6px;
    white-space: nowrap;
    flex-shrink: 0;
}

.summary-row .value {
    text-align: left;
    flex: 1;
    font-weight: 500;
}

.summary-row.total {
    border-top: 2px solid #000;
    border-bottom: 2px solid #000;
    padding: 4px 0;
    margin: 4px 0;
    font-weight: 700;
    font-size: 11px;
}

.summary-row.due {
    font-weight: 700;
    font-size: 12px;
    margin-top: 4px;
    padding-top: 3px;
    border-top: 1px solid #000;
}

.summary-row.due .remaining-amount {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #000;
}

.text-success { color: #28a745; }
.text-danger { color: #dc3545; }
.text-info { color: #17a2b8; }

/* الملاحظات */
.notes-section-80mm {
    padding: 2mm 1mm;
}

.notes-text {
    font-size: 9px;
    padding: 4px;
    background: #f9f9f9;
    border: 1px solid #000;
    margin-top: 4px;
    line-height: 1.4;
}

/* تذييل الفاتورة */
.invoice-footer-80mm {
    text-align: center;
    padding: 2mm 1mm 1mm 1mm;
    border-top: 1px solid #000;
}

.thanks {
    font-weight: 700;
    font-size: 12px;
    margin-bottom: 4px;
}

.terms {
    font-size: 11px;
    color: #000;
    font-weight: 600;
    line-height: 1.5;
}

/* أنماط الطباعة */
@media print {
    @page {
        size: 80mm auto;
        margin: 0mm;
        padding: 0mm;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }

    html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        width: 80mm;
    }

    .invoice-wrapper-80mm {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
    }

    .invoice-80mm {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    .invoice-header-80mm {
        padding: 2mm 0.5mm 1.5mm 0.5mm !important;
        margin: 0 !important;
    }

    .company-name-80mm {
        font-size: 15px !important;
    }

    .invoice-info-80mm,
    .customer-info-80mm,
    .items-section-80mm,
    .summary-section-80mm,
    .notes-section-80mm {
        padding: 1.5mm 0.5mm !important;
    }

    .items-table-80mm {
        font-size: 9px !important;
    }

    .items-table-80mm th,
    .items-table-80mm td {
        font-size: 9px !important;
        padding: 3px 1px !important;
        border: 1px solid #000 !important;
    }

    .items-table-80mm th {
        background: #f0f0f0 !important;
        font-weight: 700 !important;
    }

    .items-table-80mm td {
        font-weight: 500 !important;
    }

    .summary-row.due .remaining-amount {
        color: #000 !important;
        font-size: 13px !important;
        font-weight: 700 !important;
    }

    .terms {
        font-size: 11px !important;
        color: #000 !important;
        font-weight: 600 !important;
    }

    .section-title {
        background: #f0f0f0 !important;
        font-size: 11px !important;
    }

    .no-print {
        display: none !important;
    }
}
</style>

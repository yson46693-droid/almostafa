<?php
/**
 * تصميم فاتورة 80mm بسيط للطباعة الحرارية
 */

// السماح بالوصول من print_invoice.php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// التأكد من تضمين config.php إذا لم يكن متضمناً بالفعل
// (يتم تضمينه من print_invoice.php)

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

$subtotal        = $invoiceData['subtotal'] ?? 0;
$discount        = $invoiceData['discount_amount'] ?? 0;
$total           = $invoiceData['total_amount'] ?? 0;
$paidAmount      = $invoiceData['paid_amount'] ?? 0;
$dueAmount       = isset($invoiceData['remaining_amount']) && $invoiceData['remaining_amount'] !== null 
    ? (float)$invoiceData['remaining_amount'] 
    : max(0, $total - $paidAmount);
if ($total > 0 && $paidAmount <= 0.01 && $dueAmount <= 0.01) {
    $dueAmount = $total;
}
$creditUsed      = (float)($invoiceData['credit_used'] ?? 0);
$notes           = trim((string)($invoiceData['notes'] ?? ''));

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

<div class="invoice-80mm">
    <div class="invoice-header-80mm">
        <div class="company-name-80mm"><?php echo htmlspecialchars($companyName); ?></div>
        <div class="company-address-80mm"><?php echo htmlspecialchars($companyAddress); ?></div>
        <div class="company-phone-80mm"><?php echo htmlspecialchars($companyPhone); ?></div>
        <div class="invoice-divider"></div>
    </div>

    <div class="invoice-info-80mm">
        <div class="info-row">
            <span class="label">فاتورة رقم:</span>
            <span class="value"><?php echo htmlspecialchars($invoiceData['invoice_number']); ?></span>
        </div>
        <div class="info-row">
            <span class="label">التاريخ:</span>
            <span class="value"><?php echo $issueDate; ?></span>
        </div>
        <?php if ($dueDate !== 'أجل غير مسمى'): ?>
        <div class="info-row">
            <span class="label">تاريخ الاستحقاق:</span>
            <span class="value"><?php echo $dueDate; ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="label">الحالة:</span>
            <span class="value"><?php echo $statusLabel; ?></span>
        </div>
    </div>

    <div class="invoice-divider"></div>

    <div class="customer-info-80mm">
        <div class="section-title">بيانات العميل</div>
        <div class="info-row">
            <span class="label">الاسم:</span>
            <span class="value"><?php echo htmlspecialchars($customerName); ?></span>
        </div>
        <?php if (!empty($customerPhone)): ?>
        <div class="info-row">
            <span class="label">الهاتف:</span>
            <span class="value"><?php echo htmlspecialchars($customerPhone); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($customerAddress)): ?>
        <div class="info-row">
            <span class="label">العنوان:</span>
            <span class="value"><?php echo htmlspecialchars($customerAddress); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($repName): ?>
        <div class="info-row">
            <span class="label">المندوب:</span>
            <span class="value"><?php echo htmlspecialchars($repName); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="invoice-divider"></div>

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
                    echo '<tr><td colspan="5" style="text-align: center; padding: 10px;">لا توجد منتجات</td></tr>';
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
            <span class="value <?php echo $dueAmount > 0 ? 'text-danger' : 'text-success'; ?>">
                <?php echo formatCurrency($dueAmount); ?>
            </span>
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
    <div class="invoice-footer-80mm">
        <div class="thanks">نشكركم على ثقتكم بنا</div>
        <div class="terms">يرجى التأكد من مطابقة المنتجات عند الاستلام</div>
    </div>
</div>

<style>
.invoice-80mm {
    font-family: 'Tajawal', 'Arial', 'Helvetica', sans-serif;
    max-width: 80mm;
    margin: 0 auto;
    padding: 5mm;
    background: #ffffff;
    color: #000;
    font-size: 10px;
    line-height: 1.4;
}

.invoice-header-80mm {
    text-align: center;
    margin-bottom: 8px;
    padding-bottom: 8px;
    border-bottom: 2px solid #000;
}

.company-name-80mm {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 4px;
    text-transform: uppercase;
}

.company-address-80mm,
.company-phone-80mm {
    font-size: 9px;
    margin-bottom: 2px;
}

.invoice-divider {
    border-top: 1px dashed #000;
    margin: 6px 0;
}

.invoice-info-80mm,
.customer-info-80mm {
    margin-bottom: 8px;
}

.section-title {
    font-weight: 700;
    font-size: 11px;
    margin-bottom: 4px;
    text-align: center;
    background: #f0f0f0;
    padding: 3px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 3px;
    font-size: 9px;
}

.info-row .label {
    font-weight: 600;
    margin-left: 8px;
}

.info-row .value {
    text-align: left;
    flex: 1;
}

.items-section-80mm {
    margin-bottom: 8px;
}

.items-table-80mm {
    width: 100%;
    border-collapse: collapse;
    font-size: 8px;
    margin-top: 4px;
}

.items-table-80mm thead {
    background: #f0f0f0;
    border-bottom: 1px solid #000;
}

.items-table-80mm th {
    padding: 4px 2px;
    text-align: center;
    font-weight: 700;
    font-size: 8px;
    border-left: 1px solid #ddd;
}

.items-table-80mm th:first-child {
    border-left: none;
}

.items-table-80mm td {
    padding: 3px 2px;
    text-align: center;
    border-bottom: 1px dotted #ddd;
    border-left: 1px solid #ddd;
    font-size: 8px;
}

.items-table-80mm td:first-child {
    border-left: none;
    text-align: right;
}

.items-table-80mm .col-product {
    width: 35%;
    text-align: right;
}

.items-table-80mm .col-batch {
    width: 15%;
}

.items-table-80mm .col-qty {
    width: 12%;
}

.items-table-80mm .col-price {
    width: 18%;
    text-align: left;
}

.items-table-80mm .col-total {
    width: 20%;
    text-align: left;
    font-weight: 600;
}

.summary-section-80mm {
    margin-bottom: 8px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 3px;
    font-size: 9px;
    padding: 2px 0;
}

.summary-row.total {
    border-top: 1px solid #000;
    border-bottom: 1px solid #000;
    padding: 4px 0;
    margin-top: 4px;
    font-weight: 700;
    font-size: 10px;
}

.summary-row.due {
    font-weight: 700;
    font-size: 10px;
    margin-top: 4px;
}

.summary-row .label {
    font-weight: 600;
}

.summary-row .value {
    text-align: left;
    flex: 1;
}

.text-success { color: #28a745; }
.text-danger { color: #dc3545; }
.text-info { color: #17a2b8; }

.notes-section-80mm {
    margin-bottom: 8px;
}

.notes-text {
    font-size: 8px;
    padding: 4px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    margin-top: 4px;
}

.invoice-footer-80mm {
    text-align: center;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed #000;
}

.thanks {
    font-weight: 700;
    font-size: 10px;
    margin-bottom: 4px;
}

.terms {
    font-size: 8px;
    color: #666;
}

@media print {
    @page {
        size: 80mm auto;
        margin: 5mm;
    }

    body {
        margin: 0;
        padding: 0;
        background: #ffffff;
    }

    .invoice-80mm {
        margin: 0;
        padding: 0;
        box-shadow: none;
    }

    .no-print {
        display: none !important;
    }
}
</style>

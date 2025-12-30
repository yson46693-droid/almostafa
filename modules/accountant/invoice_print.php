<?php
/**
 * صفحة طباعة الفاتورة المصممة للطباعة الاحترافية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// التأكد من تضمين config.php إذا لم يكن متضمناً بالفعل
if (!function_exists('formatDate') || !function_exists('formatCurrency')) {
    $configPath = __DIR__ . '/../../includes/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    }
}

$isReturnDocument = isset($returnDetails) && is_array($returnDetails);
$returnMetadata = null;

if ($isReturnDocument) {
    $returnSummary = $returnDetails['summary'] ?? ($returnDetails['return'] ?? null);
    if (!$returnSummary) {
        die('المرتجع غير موجود');
    }

    $returnItems = $returnDetails['items'] ?? [];
    
    // التأكد من أن returnItems هو مصفوفة
    if (!is_array($returnItems)) {
        $returnItems = [];
    }
    
    $normalizedItems = array_map(function ($item) {
        if (!is_array($item)) {
            return null;
        }
        
        $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0;
        $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0;
        $itemNotes = trim((string)($item['notes'] ?? '')); // استخدام itemNotes بدلاً من notes لتجنب التعارض مع ملاحظات الفاتورة
        $batchNumber = $item['batch_number'] ?? null;
        $condition = $item['condition'] ?? null;
        
        return [
            'product_name' => $item['product_name'] ?? $item['description'] ?? 'منتج',
            'description'  => $itemNotes, // الوصف يحتوي فقط على الملاحظات
            'quantity'     => $quantity,
            'unit_price'   => $unitPrice,
            'total_price'  => $item['total_price'] ?? ($quantity * $unitPrice),
            'batch_number' => $batchNumber,
            'condition'    => $condition,
            'notes'        => $itemNotes,
        ];
    }, $returnItems);
    
    // إزالة العناصر null
    $normalizedItems = array_filter($normalizedItems, function($item) {
        return $item !== null;
    });

    $invoiceData = [
        'invoice_number'    => $returnSummary['return_number'] ?? ('RET-' . str_pad($returnSummary['id'] ?? 0, 4, '0', STR_PAD_LEFT)),
        'date'              => $returnSummary['return_date'] ?? ($returnSummary['created_at'] ?? date('Y-m-d')),
        'due_date'          => $returnSummary['return_date'] ?? ($returnSummary['created_at'] ?? date('Y-m-d')),
        'status'            => $returnSummary['status'] ?? 'pending',
        'customer_name'     => $returnSummary['customer_name'] ?? 'عميل',
        'customer_phone'    => $returnSummary['customer_phone'] ?? '',
        'customer_address'  => $returnSummary['customer_address'] ?? '',
        'sales_rep_name'    => $returnSummary['sales_rep_name'] ?? null,
        'subtotal'          => $returnSummary['refund_amount'] ?? 0,
        'discount_amount'   => 0,
        'total_amount'      => $returnSummary['refund_amount'] ?? 0,
        'paid_amount'       => $returnSummary['refund_amount'] ?? 0,
        'notes'             => trim(
            implode(
                "\n",
                array_filter([
                    !empty($returnSummary['reason']) ? 'سبب الإرجاع: ' . $returnSummary['reason'] : null,
                    !empty($returnSummary['reason_description']) ? $returnSummary['reason_description'] : null,
                    $returnSummary['notes'] ?? ''
                ])
            )
        ),
        'items'             => $normalizedItems,
        'company_address'   => $returnSummary['company_address'] ?? null,
        'company_phone'     => $returnSummary['company_phone'] ?? null,
        'company_email'     => $returnSummary['company_email'] ?? null,
        'company_tax_number'=> $returnSummary['company_tax_number'] ?? null,
    ];

    $returnMetadata = [
        'invoice_reference' => $returnSummary['invoice_number'] ?? null,
        'refund_method'     => $returnSummary['refund_method'] ?? null,
        'return_type'       => $returnSummary['return_type'] ?? null,
        'refund_amount'     => $returnSummary['refund_amount'] ?? 0,
    ];
} else {
    $invoiceData = $selectedInvoice ?? $invoice ?? null;
}

if (!$invoiceData) {
    die($isReturnDocument ? 'المرتجع غير موجود' : 'الفاتورة غير موجودة');
}

$companyName      = COMPANY_NAME;
$companySubtitle  = 'نظام إدارة المبيعات';
$companyAddress   = $invoiceData['company_address'] ?? 'نطاق التوزيع :  الاسكندريه - شحن لجميع انحاء الجمهوريه';
$companyPhone     = $invoiceData['company_phone']   ?? '01003533905';
$companyEmail     = $invoiceData['company_email']   ?? 'صفحة فيسبوك  : عسل نحل المصطفي';
$companyTaxNumber = $invoiceData['company_tax_number'] ?? '';

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
// استخدام remaining_amount من قاعدة البيانات إذا كان موجوداً، وإلا حسابها من total - paid
// ملاحظة: عند البيع بالآجل، paidAmount = 0، لذا remaining_amount يجب أن يكون = total
$dueAmount       = isset($invoiceData['remaining_amount']) && $invoiceData['remaining_amount'] !== null 
    ? (float)$invoiceData['remaining_amount'] 
    : max(0, $total - $paidAmount);
// التأكد من أن dueAmount لا يكون 0 إذا كان total > 0 و paidAmount = 0 (بيع بالآجل)
if ($total > 0 && $paidAmount <= 0.01 && $dueAmount <= 0.01) {
    $dueAmount = $total;
}
$creditUsed      = (float)($invoiceData['credit_used'] ?? 0);
$paidFromCredit  = isset($invoiceData['paid_from_credit']) ? (int)$invoiceData['paid_from_credit'] : 0;
// التحقق من كون الفاتورة مدفوعة من رصيد العميل
$isPaidFromCredit = ($paidFromCredit == 1) || ($creditUsed > 0.01);

// تسجيل في invoice_print.php
$invoiceIdForLog = $invoiceData['id'] ?? $invoiceData['invoice_number'] ?? 'UNKNOWN';
error_log(sprintf('[INVOICE PRINT] Step 1 - Enter invoice_print.php: invoiceId=%s, hasNotesKey=%s, notesValue=%s', 
    $invoiceIdForLog,
    isset($invoiceData['notes']) ? 'yes' : 'no',
    !empty($invoiceData['notes']) ? substr($invoiceData['notes'], 0, 50) : 'empty'
));

// جلب الملاحظات من invoiceData مع التأكد من أنها موجودة
$notes = '';
if (isset($invoiceData['notes']) && !empty($invoiceData['notes'])) {
    $notes = trim((string)$invoiceData['notes']);
    error_log(sprintf('[INVOICE PRINT] Step 2 - Found notes in invoiceData[notes]: invoiceId=%s, notes=%s', 
        $invoiceIdForLog,
        substr($notes, 0, 50)
    ));
} elseif (isset($invoiceData['invoice_notes']) && !empty($invoiceData['invoice_notes'])) {
    $notes = trim((string)$invoiceData['invoice_notes']);
    error_log(sprintf('[INVOICE PRINT] Step 2 - Found notes in invoiceData[invoice_notes]: invoiceId=%s, notes=%s', 
        $invoiceIdForLog,
        substr($notes, 0, 50)
    ));
} elseif (isset($invoiceData['cart_notes']) && !empty($invoiceData['cart_notes'])) {
    $notes = trim((string)$invoiceData['cart_notes']);
    error_log(sprintf('[INVOICE PRINT] Step 2 - Found notes in invoiceData[cart_notes]: invoiceId=%s, notes=%s', 
        $invoiceIdForLog,
        substr($notes, 0, 50)
    ));
} else {
    error_log(sprintf('[INVOICE PRINT] Step 2 - No notes found in invoiceData: invoiceId=%s', $invoiceIdForLog));
}

error_log(sprintf('[INVOICE PRINT] Step 3 - Final notes value: invoiceId=%s, notes=%s, notesLength=%d, notesIsEmpty=%s, notesTrimmed=%s', 
    $invoiceIdForLog,
    !empty($notes) ? substr($notes, 0, 50) : 'EMPTY',
    strlen($notes),
    empty($notes) ? 'YES' : 'NO',
    trim($notes) !== '' ? 'NOT_EMPTY' : 'EMPTY'
));

// التأكد من أن $notes ليست فارغة بعد trim
if (!empty($notes) && trim($notes) !== '') {
    $notes = trim($notes);
} else {
    $notes = '';
    error_log(sprintf('[INVOICE PRINT] Step 3.5 - WARNING: notes is empty after processing: invoiceId=%s', $invoiceIdForLog));
}

$currencyLabel   = CURRENCY . ' ' . CURRENCY_SYMBOL;

// باركود فيسبوك - يمكن تعديل الرابط حسب صفحة الشركة على فيسبوك
$facebookPageUrl = 'https://www.facebook.com/share/1AHxSmFhEp/'; // يرجى تعديل هذا الرابط
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($facebookPageUrl);

$statusLabelsMap = [
    'draft'     => 'مسودة',
    'approved'  => 'معتمدة',
    'paid'      => 'مدفوعة',
    'partial'   => 'مدفوع جزئياً',
    'cancelled' => 'ملغاة',
    'pending'   => 'قيد المراجعة',
    'processed' => 'تمت المعالجة',
    'completed' => 'مكتمل',
    'rejected'  => 'مرفوض'
];

$statusClassesMap = [
    'draft'     => 'status-draft',
    'approved'  => 'status-approved',
    'paid'      => 'status-paid',
    'partial'   => 'status-partial',
    'cancelled' => 'status-cancelled',
    'pending'   => 'status-draft',
    'processed' => 'status-approved',
    'completed' => 'status-paid',
    'rejected'  => 'status-cancelled'
];

$statusLabel = $statusLabelsMap[$status] ?? 'مسودة';
$statusClass = $statusClassesMap[$status] ?? 'status-draft';

$documentTitleText = $isReturnDocument ? 'فاتورة مرتجع' : 'فاتورة مبيعات';
$documentNumberLabel = $isReturnDocument ? 'رقم المرتجع' : 'رقم الفاتورة';
$summaryTitleText = $isReturnDocument ? 'ملخص المرتجع' : 'ملخص الفاتورة';

$returnRefundLabels = [
    'cash'             => 'إرجاع نقداً',
    'credit'           => 'إضافة لرصيد العميل',
    'company_request'  => 'طلب المبلغ من الشركة'
];
$returnTypeLabels = [
    'full'    => 'مرتجع كامل',
    'partial' => 'مرتجع جزئي'
];
$returnRefundLabel = $isReturnDocument ? ($returnRefundLabels[$returnMetadata['refund_method'] ?? ''] ?? 'غير محدد') : '';
$returnTypeLabel = $isReturnDocument ? ($returnTypeLabels[$returnMetadata['return_type'] ?? ''] ?? 'غير محدد') : '';
?>

<div class="invoice-wrapper" id="invoicePrint">
    <div class="invoice-card">
        <header class="invoice-header">
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
            <div class="invoice-meta">
                <div class="invoice-title"><?php echo htmlspecialchars($documentTitleText); ?></div>
                <div class="invoice-number"><?php echo htmlspecialchars($documentNumberLabel); ?><span><?php echo htmlspecialchars($invoiceData['invoice_number']); ?></span></div>
                <div class="invoice-meta-grid">
                    <div class="meta-item">
                        <span>تاريخ الإصدار</span>
                        <strong><?php echo $issueDate; ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>تاريخ الاستحقاق</span>
                        <strong><?php echo $dueDate; ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>الحالة</span>
                        <strong class="<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></strong>
                    </div>
                </div>
            </div>
        </header>

        <section class="info-grid">
            <div class="info-card">
                <div class="info-title">بيانات الشركة</div>
                <div class="info-item"><?php echo htmlspecialchars($companyAddress); ?></div>
                <div class="info-item"><?php echo htmlspecialchars($companyPhone); ?></div>
                <div class="info-item"><?php echo htmlspecialchars($companyEmail); ?></div>
                <div class="info-item"><?php echo htmlspecialchars($companyTaxNumber); ?></div>
            </div>
            <div class="info-card">
                <div class="info-title">بيانات العميل</div>
                <div class="info-item name"><?php echo htmlspecialchars($customerName); ?></div>
                <?php if (!empty($customerPhone)): ?>
                    <div class="info-item">هاتف: <?php echo htmlspecialchars($customerPhone); ?></div>
                <?php endif; ?>
                <?php if (!empty($customerAddress)): ?>
                    <div class="info-item">العنوان: <?php echo htmlspecialchars($customerAddress); ?></div>
                <?php endif; ?>
                <?php if ($repName): ?>
                    <div class="info-item rep">المسؤول عن عملية البيع: <?php echo htmlspecialchars($repName); ?></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="items-table">
            <table>
                <thead>
                    <tr>
                        <th style="width: 30%;">المنتج</th>
                        <?php if ($isReturnDocument): ?>
                            <th style="width: 15%; text-align: center;">الحالة</th>
                            <?php 
                            // عرض عمود رقم التشغيلة إذا كان هناك أي عنصر يحتوي على رقم تشغيلة
                            $hasBatchNumbers = false;
                            if (!empty($invoiceData['items']) && is_array($invoiceData['items'])) {
                                foreach ($invoiceData['items'] as $item) {
                                    if (!empty($item['batch_number'])) {
                                        $hasBatchNumbers = true;
                                        break;
                                    }
                                }
                            }
                            if ($hasBatchNumbers): ?>
                                <th style="width: 15%; text-align: center;">رقم التشغيلة</th>
                            <?php endif; ?>
                        <?php else: ?>
                            <th style="width: 20%; text-align: center;">رقم التشغيلة</th>
                        <?php endif; ?>
                        <th style="width: 12%; text-align: center;">الكمية</th>
                        <th style="width: 15%; text-align: end;">سعر الوحدة</th>
                        <th style="width: 15%; text-align: end;">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // حساب عدد الأعمدة
                    $hasBatchNumbers = false;
                    if (!empty($invoiceData['items']) && is_array($invoiceData['items'])) {
                        foreach ($invoiceData['items'] as $item) {
                            if (!empty($item['batch_number'])) {
                                $hasBatchNumbers = true;
                                break;
                            }
                        }
                    }
                    
                    if ($isReturnDocument) {
                        $colspan = 5; // المنتج، الحالة، الكمية، سعر الوحدة، الإجمالي
                        if ($hasBatchNumbers) {
                            $colspan = 6; // إضافة عمود رقم التشغيلة
                        }
                    } else {
                        $colspan = 5; // المنتج، رقم التشغيلة، الكمية، سعر الوحدة، الإجمالي
                    }
                    
                    if (empty($invoiceData['items']) || !is_array($invoiceData['items'])) {
                        echo '<tr><td colspan="' . $colspan . '" style="text-align: center; padding: 20px; color: #64748b;">لا توجد منتجات في هذا المرتجع</td></tr>';
                    } else {
                        foreach ($invoiceData['items'] as $item): 
                            $quantity   = isset($item['quantity']) ? number_format($item['quantity'], 2) : '0.00';
                            $unitPrice  = isset($item['unit_price']) ? formatCurrency($item['unit_price']) : formatCurrency(0);
                            $totalPrice = isset($item['total_price']) ? formatCurrency($item['total_price']) : formatCurrency(0);
                            $description = trim((string)($item['description'] ?? ''));
                            $batchNumber = $item['batch_number'] ?? null;
                            $condition = $item['condition'] ?? null;
                            $itemNotes = trim((string)($item['notes'] ?? '')); // استخدام itemNotes بدلاً من notes لتجنب التعارض مع ملاحظات الفاتورة
                    ?>
                    <tr>
                        <td>
                            <div class="product-name" style="font-weight: 600; margin-bottom: 4px;">
                                <?php echo htmlspecialchars($item['product_name'] ?? 'منتج'); ?>
                            </div>
                            <?php if ($itemNotes && !$isReturnDocument): ?>
                                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                    <?php echo nl2br(htmlspecialchars($itemNotes)); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php if ($isReturnDocument): ?>
                            <td style="text-align: center; vertical-align: middle;">
                                <?php if (!empty($condition)): ?>
                                    <?php
                                    $conditionLabels = [
                                        'new' => ['label' => 'جديد', 'color' => '#10b981', 'bg' => '#d1fae5'],
                                        'used' => ['label' => 'مستعمل', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
                                        'damaged' => ['label' => 'تالف', 'color' => '#ef4444', 'bg' => '#fee2e2'],
                                        'defective' => ['label' => 'معيب', 'color' => '#dc2626', 'bg' => '#fecaca']
                                    ];
                                    $conditionInfo = $conditionLabels[$condition] ?? ['label' => $condition, 'color' => '#64748b', 'bg' => '#f1f5f9'];
                                    ?>
                                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; color: <?php echo $conditionInfo['color']; ?>; background: <?php echo $conditionInfo['bg']; ?>;">
                                        <?php echo htmlspecialchars($conditionInfo['label']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($hasBatchNumbers): ?>
                                <td style="text-align: center; vertical-align: middle;">
                                    <?php if (!empty($batchNumber)): ?>
                                        <span style="font-size: 12px; color: #0f4c81; font-weight: 600; background: #e0f2fe; padding: 4px 8px; border-radius: 6px; display: inline-block;">
                                            <?php echo htmlspecialchars($batchNumber); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        <?php else: ?>
                            <td style="text-align: center; vertical-align: middle;">
                                <?php if (!empty($batchNumber)): ?>
                                    <span style="font-size: 12px; color: #0f4c81; font-weight: 600; background: #e0f2fe; padding: 4px 8px; border-radius: 6px; display: inline-block;">
                                        <?php echo htmlspecialchars($batchNumber); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td style="text-align: center; vertical-align: middle; font-weight: 600;">
                            <?php echo $quantity; ?>
                        </td>
                        <td style="text-align: end; vertical-align: middle;">
                            <?php echo $unitPrice; ?>
                        </td>
                        <td style="text-align: end; vertical-align: middle; font-weight: 600; color: #0f4c81;">
                            <?php echo $totalPrice; ?>
                        </td>
                    </tr>
                    <?php 
                        endforeach;
                    }
                    ?>
                </tbody>
            </table>
        </section>

        <section class="summary-grid">
            <?php if (!$isReturnDocument): ?>
                <div class="summary-card">
                    <div class="summary-title"><?php echo htmlspecialchars($summaryTitleText); ?></div>
                    <div class="summary-row">
                        <span>المجموع الفرعي</span>
                        <strong><?php echo formatCurrency($subtotal); ?></strong>
                    </div>
                    <?php if ($discount > 0): ?>
                        <div class="summary-row">
                            <span>الخصم</span>
                            <strong class="text-danger">-<?php echo formatCurrency($discount); ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="summary-row total">
                        <span>الإجمالي النهائي</span>
                        <strong><?php echo formatCurrency($total); ?></strong>
                    </div>
                    <?php 
                    // إخفاء حقل "المدفوع" فقط إذا كان البيع بالآجل الكامل (credit) بدون أي دفع نقدي
                    // أو إذا كانت الفاتورة مدفوعة بالكامل من رصيد العميل فقط (بدون دفع نقدي)
                    $paymentType = isset($invoiceMeta) && is_array($invoiceMeta) ? ($invoiceMeta['payment_type'] ?? null) : null;
                    $isCreditSale = ($paymentType === 'credit');
                    // حساب المبلغ النقدي الفعلي (paidAmount - creditUsed)
                    $cashPaidAmount = max(0, $paidAmount - $creditUsed);
                    // إخفاء "المدفوع" فقط إذا كان البيع بالآجل الكامل (بدون دفع نقدي على الإطلاق)
                    // أو إذا كانت الفاتورة مدفوعة بالكامل من رصيد العميل فقط (بدون دفع نقدي)
                    // ملاحظة: في حالة التحصيل الجزئي (partial)، يجب عرض المبلغ المدفوع
                    $shouldHideCashPaid = ($isCreditSale && $cashPaidAmount <= 0.01) || ($isPaidFromCredit && $cashPaidAmount <= 0.01);
                    // عرض "المدفوع" في حالة:
                    // 1. التحصيل الجزئي (partial) - يجب عرض المبلغ المدفوع
                    // 2. الدفع الكامل (full) - يجب عرض المبلغ المدفوع
                    // 3. أي حالة فيها cashPaidAmount > 0
                    if (!$shouldHideCashPaid && $cashPaidAmount > 0.01): ?>
                        <div class="summary-row">
                            <span>المدفوع</span>
                            <strong class="text-success"><?php echo formatCurrency($cashPaidAmount); ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ($creditUsed > 0): ?>
                        <div class="summary-row">
                            <span>المدفوع من رصيد العميل</span>
                            <strong class="text-info"><?php echo formatCurrency($creditUsed); ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="summary-row due">
                        <span>المتبقي</span>
                        <strong class="<?php echo $dueAmount > 0 ? 'text-danger' : 'text-success'; ?>">
                            <?php echo formatCurrency($dueAmount); ?>
                        </strong>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($isReturnDocument): ?>
                <div class="summary-card">
                    <div class="summary-title">تفاصيل الإرجاع</div>
                    <?php if (!empty($returnMetadata['invoice_reference'])): ?>
                        <div class="summary-row">
                            <span>فاتورة مرتبطة</span>
                            <strong><?php echo htmlspecialchars($returnMetadata['invoice_reference']); ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="summary-row">
                        <span>طريقة الإرجاع</span>
                        <strong><?php echo htmlspecialchars($returnRefundLabel); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>نوع المرتجع</span>
                        <strong><?php echo htmlspecialchars($returnTypeLabel); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>القيمة المرتجعة</span>
                        <strong><?php echo formatCurrency($returnMetadata['refund_amount'] ?? 0); ?></strong>
                    </div>
                </div>
            <?php endif; ?>
            <div class="summary-card qr-card">
                <div class="summary-title">تابعنا على فيسبوك</div>
                <div class="qr-wrapper">
                    <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="Facebook QR Code">
                </div>
                <div class="qr-note">امسح الرمز لمتابعة صفحتنا على فيسبوك</div>
            </div>
        </section>

        <footer class="invoice-footer">
            <div class="thanks">نشكركم على ثقتكم بنا</div>
            <div class="terms">
                <div>يرجى التأكد من مطابقة المنتجات عند الاستلام.</div>
                <div>لأي استفسارات يرجى التواصل على: <?php echo htmlspecialchars($companyPhone); ?></div>
            </div>
            <?php 
            // تسجيل قبل عرض الملاحظات في footer
            error_log(sprintf('[INVOICE PRINT] Step 5 - Before footer notes: invoiceId=%s, notes=%s, notesEmpty=%s, notesType=%s, willDisplay=%s', 
                $invoiceIdForLog,
                !empty($notes) ? substr($notes, 0, 50) : 'EMPTY',
                empty($notes) ? 'YES' : 'NO',
                gettype($notes),
                (isset($notes) && $notes !== null && $notes !== '' && trim($notes) !== '') ? 'YES' : 'NO'
            ));

            // عرض الملاحظات دائماً إذا كانت موجودة
            if (isset($notes) && $notes !== null && $notes !== '' && trim($notes) !== ''): ?>
                <div class="invoice-notes">
                    <div class="notes-label">ملاحظات الفاتورة:</div>
                    <div class="notes-text"><?php echo nl2br(htmlspecialchars(trim($notes))); ?></div>
                </div>
            <?php 
            else:
                error_log(sprintf('[INVOICE PRINT] Step 5 - SKIP footer notes: invoiceId=%s, reason=%s', 
                    $invoiceIdForLog,
                    !isset($notes) ? 'notes not set' : (empty($notes) ? 'notes is empty' : 'notes trimmed is empty')
                ));
            endif; ?>
        </footer>
    </div>
</div>

<style>
.invoice-wrapper {
    font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: #1f2937;
}

.invoice-card {
    background: #ffffff;
    border-radius: 24px;
    border: 1px solid #e2e8f0;
    padding: 32px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
    position: relative;
    overflow: hidden;
}

.invoice-card::before {
    content: '';
    position: absolute;
    top: -40%;
    left: -25%;
    width: 60%;
    height: 120%;
    background: radial-gradient(circle at center, rgba(15, 76, 129, 0.12), transparent 70%);
    z-index: 0;
}

.invoice-card > * {
    position: relative;
    z-index: 1;
}

.invoice-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 32px;
}

.brand-block {
    display: flex;
    align-items: center;
    gap: 18px;
}

.logo-placeholder {
    width: 74px;
    height: 74px;
    border-radius: 20px;
    background: linear-gradient(135deg, #0f4c81, #0a2d4a);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 30px;
    font-weight: 700;
    box-shadow: 0 12px 24px rgba(15, 76, 129, 0.25);
    overflow: hidden;
    position: relative;
}

.company-logo-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 8px;
}

.logo-letter {
    transform: translateY(2px);
}

.company-name {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: #0f172a;
}

.company-subtitle {
    font-size: 14px;
    color: #475569;
    margin-top: 6px;
}

.invoice-meta {
    text-align: left;
}

.invoice-title {
    font-size: 20px;
    font-weight: 600;
    color: #0f4c81;
    margin-bottom: 6px;
}

.invoice-number {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 10px;
}

.invoice-number span {
    display: block;
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
}

.invoice-meta-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(160px, 1fr));
    gap: 12px;
}

.meta-item {
    background: #f8fafc;
    border: 1px solid rgba(15, 76, 129, 0.08);
    border-radius: 12px;
    padding: 12px 16px;
}

.meta-item span {
    display: block;
    font-size: 12px;
    color: #64748b;
    margin-bottom: 6px;
}

.meta-item strong {
    font-size: 15px;
    color: #0f172a;
}

.status-draft { color: #eab308; }
.status-approved { color: #10b981; }
.status-paid { color: #059669; }
.status-partial { color: #f97316; }
.status-cancelled { color: #ef4444; }

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.info-card {
    background: #f9fafb;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px 22px;
}

.info-title {
    font-size: 15px;
    font-weight: 600;
    color: #0f4c81;
    margin-bottom: 12px;
}

.info-item {
    font-size: 14px;
    color: #475569;
    margin-bottom: 6px;
    line-height: 1.6;
}

.info-item.name {
    font-weight: 600;
    color: #0f172a;
}

.info-item.rep {
    margin-top: 10px;
    color: #1d4ed8;
}

.items-table table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid rgba(15, 76, 129, 0.12);
    table-layout: fixed;
}

.items-table thead {
    background: linear-gradient(135deg, rgba(15, 76, 129, 0.1), rgba(15, 76, 129, 0.05));
}

.items-table th {
    padding: 14px 12px;
    font-size: 13px;
    color: #0f4c81;
    text-align: right;
    border-bottom: 1px solid rgba(15, 76, 129, 0.12);
    border-left: 1px solid rgba(15, 76, 129, 0.08);
    font-weight: 600;
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
    box-sizing: border-box;
}

.items-table th:first-child {
    text-align: right;
    border-left: none;
}

.items-table td {
    padding: 16px 12px;
    font-size: 14px;
    color: #1f2937;
    border-bottom: 1px solid rgba(148, 163, 184, 0.25);
    border-left: 1px solid rgba(148, 163, 184, 0.15);
    text-align: right;
    vertical-align: middle;
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
    box-sizing: border-box;
}

.items-table td:first-child {
    border-left: none;
}

.items-table tbody tr:last-child td {
    border-bottom: none;
}

.items-table tbody tr:hover {
    background-color: rgba(15, 76, 129, 0.02);
}

.items-table .product-name {
    font-weight: 600;
    margin-bottom: 6px;
    color: #0f172a;
}

.items-table .product-unit {
    font-size: 12px;
    color: #64748b;
}

.items-table .muted {
    color: #9ca3af;
    font-size: 13px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
    margin: 28px 0;
}

.summary-card {
    background: #f8fafc;
    border-radius: 18px;
    border: 1px solid rgba(15, 76, 129, 0.1);
    padding: 20px 22px;
    min-height: 220px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.summary-card.qr-card {
    align-items: center;
    text-align: center;
}

.summary-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f4c81;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    color: #1f2937;
    gap: 12px;
}

.summary-row strong {
    font-size: 15px;
}

.summary-row.total {
    border-top: 1px dashed rgba(15, 76, 129, 0.2);
    padding-top: 10px;
    margin-top: 6px;
}

.summary-row.due {
    font-weight: 600;
}

.text-success { color: #16a34a !important; }
.text-danger { color: #dc2626 !important; }

.qr-wrapper {
    background: #fff;
    padding: 12px;
    border-radius: 16px;
    border: 1px solid rgba(15, 76, 129, 0.15);
    box-shadow: inset 0 2px 12px rgba(15, 23, 42, 0.05);
}

.qr-wrapper img {
    width: 150px;
    height: 150px;
    display: block;
}

.qr-note {
    font-size: 12px;
    color: #64748b;
}

.notes-card .notes-content {
    font-size: 13px;
    color: #475569;
    line-height: 1.8;
    background: rgba(15, 76, 129, 0.05);
    padding: 12px;
    border-radius: 12px;
    border: 1px solid rgba(15, 76, 129, 0.08);
}

.invoice-footer {
    text-align: center;
    padding-top: 18px;
    border-top: 1px dashed rgba(148, 163, 184, 0.5);
}

.invoice-footer .thanks {
    font-size: 16px;
    font-weight: 600;
    color: #0f4c81;
    margin-bottom: 6px;
}

.invoice-footer .terms {
    font-size: 12px;
    color: #64748b;
    line-height: 1.6;
}

.invoice-footer .invoice-notes {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px dashed rgba(148, 163, 184, 0.3);
    text-align: right;
}

.invoice-footer .invoice-notes .notes-label {
    font-size: 13px;
    font-weight: 600;
    color: #0f4c81;
    margin-bottom: 8px;
}

.invoice-footer .invoice-notes .notes-text {
    font-size: 12px;
    color: #475569;
    line-height: 1.8;
    background: rgba(15, 76, 129, 0.05);
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid rgba(15, 76, 129, 0.1);
    text-align: right;
}

@media print {
    body {
        background: #ffffff;
        margin: 0;
    }

    .invoice-card {
        box-shadow: none;
        border: none;
        padding: 24px;
        border-radius: 0;
    }

    .invoice-card::before {
        display: none;
    }

    .btn, .no-print, .card-header, .sidebar, .navbar {
        display: none !important;
    }

    .invoice-wrapper {
        margin: 0;
    }
}

@media (max-width: 768px) {
    body {
        padding: 10px !important;
        margin: 0 !important;
        overflow-x: hidden !important;
    }

    .invoice-wrapper {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .invoice-card {
        padding: 16px 12px !important;
        border-radius: 12px !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    .invoice-header {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 16px !important;
        margin-bottom: 20px !important;
    }

    .brand-block {
        flex-direction: row !important;
        align-items: center !important;
        gap: 12px !important;
        width: 100% !important;
    }

    .logo-placeholder {
        width: 60px !important;
        height: 60px !important;
        flex-shrink: 0 !important;
    }

    .company-name {
        font-size: 20px !important;
        line-height: 1.3 !important;
    }

    .company-subtitle {
        font-size: 12px !important;
    }

    .invoice-meta {
        width: 100% !important;
        text-align: right !important;
    }

    .invoice-title {
        font-size: 16px !important;
        margin-bottom: 8px !important;
    }

    .invoice-number {
        font-size: 12px !important;
        margin-bottom: 12px !important;
    }

    .invoice-number span {
        font-size: 18px !important;
    }

    .invoice-meta-grid {
        grid-template-columns: 1fr !important;
        gap: 8px !important;
    }

    .meta-item {
        padding: 10px 12px !important;
    }

    .meta-item span {
        font-size: 11px !important;
    }

    .meta-item strong {
        font-size: 13px !important;
    }

    .info-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
        margin-bottom: 20px !important;
    }

    .info-card {
        padding: 14px 16px !important;
    }

    .info-title {
        font-size: 14px !important;
        margin-bottom: 10px !important;
    }

    .info-item {
        font-size: 13px !important;
        margin-bottom: 6px !important;
        word-wrap: break-word !important;
        overflow-wrap: break-word !important;
        line-height: 1.5 !important;
        white-space: normal !important;
        overflow: visible !important;
    }

    .info-item.name {
        font-size: 14px !important;
        font-weight: 600 !important;
    }

    .items-table {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        margin-bottom: 20px !important;
    }

    .items-table table {
        min-width: 600px !important;
        font-size: 12px !important;
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: separate !important;
        border-spacing: 0 !important;
    }

    .items-table th {
        padding: 10px 8px !important;
        font-size: 11px !important;
        border-left: 1px solid rgba(15, 76, 129, 0.08) !important;
        word-wrap: break-word !important;
        overflow-wrap: break-word !important;
        white-space: normal !important;
        box-sizing: border-box !important;
    }

    .items-table th:first-child {
        border-left: none !important;
    }

    .items-table td {
        padding: 12px 8px !important;
        font-size: 12px !important;
        border-left: 1px solid rgba(148, 163, 184, 0.15) !important;
        word-wrap: break-word !important;
        overflow-wrap: break-word !important;
        white-space: normal !important;
        box-sizing: border-box !important;
    }

    .items-table td:first-child {
        border-left: none !important;
    }

    .items-table .product-name {
        font-size: 13px !important;
    }

    .summary-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
        margin: 20px 0 !important;
    }

    .summary-card {
        padding: 16px !important;
        min-height: auto !important;
    }

    .summary-title {
        font-size: 14px !important;
    }

    .summary-row {
        font-size: 13px !important;
        gap: 8px !important;
    }

    .summary-row strong {
        font-size: 14px !important;
    }

    .qr-wrapper {
        padding: 10px !important;
    }

    .qr-wrapper img {
        width: 120px !important;
        height: 120px !important;
    }

    .qr-note {
        font-size: 11px !important;
    }

    .invoice-footer {
        padding-top: 16px !important;
    }

    .invoice-footer .thanks {
        font-size: 14px !important;
    }

    .invoice-footer .terms {
        font-size: 11px !important;
    }
}

@media print {
    @page {
        size: A4;
        margin: 1cm;
    }

    body {
        padding: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
    }

    .invoice-wrapper {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .invoice-card {
        box-shadow: none !important;
        border: none !important;
        padding: 20px !important;
        border-radius: 0 !important;
        page-break-inside: avoid !important;
    }

    .invoice-card::before {
        display: none !important;
    }

    .invoice-header {
        page-break-inside: avoid !important;
    }

    .items-table {
        page-break-inside: auto !important;
    }

    .items-table tbody tr {
        page-break-inside: avoid !important;
        page-break-after: auto !important;
    }

    .summary-grid {
        page-break-inside: avoid !important;
    }

    .btn, .no-print, .card-header, .sidebar, .navbar {
        display: none !important;
    }
}
</style>

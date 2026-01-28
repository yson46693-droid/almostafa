<?php
/**
 * صفحة إدارة الفواتير للمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['accountant', 'sales', 'manager', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// Pagination (استخدام p لرقم الصفحة لأن page=invoices لمخطط Dashboard)
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// البحث والفلترة المتقدمة
$filters = [
    'invoice_number' => trim($_GET['invoice_number'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
    'date_exact' => trim($_GET['date_exact'] ?? ''),
    'status' => trim($_GET['status'] ?? ''),
    'customer_id' => isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? intval($_GET['customer_id']) : null,
];
// يوم محدد: استخدام نفس التاريخ للفترة
if (!empty($filters['date_exact'])) {
    $filters['date_from'] = $filters['date_exact'];
    $filters['date_to'] = $filters['date_exact'];
}
$filters = array_filter($filters, function($value) {
    return $value !== '' && $value !== null;
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_invoice') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $salesRepId = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : null;
        $date = $_POST['date'] ?? date('Y-m-d');
        $taxRate = 0; // تم إلغاء الضريبة
        $discountAmount = floatval($_POST['discount_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        // معالجة العناصر
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0 && $item['unit_price'] > 0) {
                    $items[] = [
                        'product_id' => intval($item['product_id']),
                        'description' => trim($item['description'] ?? ''),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
            }
        }
        
        if ($customerId <= 0 || empty($items)) {
            $error = 'يجب إدخال العميل وعناصر الفاتورة';
        } else {
            $result = createInvoice($customerId, $salesRepId, $date, $items, $taxRate, $discountAmount, $notes);
            if ($result['success']) {
                $success = 'تم إنشاء الفاتورة بنجاح: ' . $result['invoice_number'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'update_status') {
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($invoiceId > 0 && !empty($status)) {
            $result = updateInvoiceStatus($invoiceId, $status);
            if ($result['success']) {
                $success = 'تم تحديث حالة الفاتورة بنجاح';
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'record_payment') {
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($invoiceId > 0 && $amount > 0) {
            $result = recordInvoicePayment($invoiceId, $amount, $notes);
            if ($result['success']) {
                $success = 'تم تسجيل الدفعة بنجاح';
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'delete_invoice') {
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        
        if ($invoiceId > 0) {
            $result = deleteInvoice($invoiceId);
            if ($result['success']) {
                $success = 'تم حذف الفاتورة بنجاح';
            } else {
                $error = $result['message'];
            }
        }
    }
}

// الحصول على البيانات
$totalInvoices = getInvoicesCount($filters);
$totalPages = ceil($totalInvoices / $perPage);
$invoices = getInvoices($filters, $perPage, $offset);

$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");

// التحقق من وجود عمود unit قبل الاستعلام
$unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
$hasUnitColumn = !empty($unitColumnCheck);

if ($hasUnitColumn) {
    $products = $db->query("SELECT id, name, unit_price, unit FROM products WHERE status = 'active' ORDER BY name");
} else {
    // إضافة العمود إذا لم يكن موجوداً
    try {
        $db->execute("ALTER TABLE products ADD COLUMN unit VARCHAR(20) DEFAULT 'piece' AFTER quantity");
        $hasUnitColumn = true;
        // تحديث البيانات الموجودة
        $db->execute("UPDATE products SET unit = 'piece' WHERE unit IS NULL OR unit = ''");
    } catch (Exception $e) {
        error_log("Error adding unit column: " . $e->getMessage());
    }
    // استعلام بدون unit
    $products = $db->query("SELECT id, name, unit_price FROM products WHERE status = 'active' ORDER BY name");
    // إضافة unit افتراضي للمنتجات
    foreach ($products as &$product) {
        $product['unit'] = 'piece';
    }
}

$salesReps = $db->query("SELECT id, username, full_name FROM users WHERE role = 'sales' AND status = 'active' ORDER BY username");

// فاتورة محددة للعرض
$selectedInvoice = null;
if (isset($_GET['id'])) {
    $selectedInvoice = getInvoice(intval($_GET['id']));
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-receipt me-2"></i>إدارة الفواتير</h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($selectedInvoice && !isset($_GET['print'])): ?>
    <!-- عرض فاتورة محددة -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">فاتورة رقم: <?php echo htmlspecialchars($selectedInvoice['invoice_number']); ?></h5>
            <div>
                <a href="<?php echo getRelativeUrl('print_invoice.php?id=' . $selectedInvoice['id'] . '&print=1'); ?>" 
                   class="btn btn-light btn-sm" target="_blank">
                    <i class="bi bi-printer me-2"></i>طباعة
                </a>
                <a href="?page=invoices" class="btn btn-light btn-sm">
                    <i class="bi bi-x"></i>
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php 
            $selectedInvoice = $selectedInvoice; // متغير للاستخدام في invoice_print.php
            include __DIR__ . '/invoice_print.php'; 
            ?>
        </div>
    </div>
<?php endif; ?>

<!-- البحث والفلترة المتقدمة -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="fw-bold"><i class="bi bi-funnel me-1"></i>بحث وفلترة الفواتير</span>
        <a href="?page=invoices" class="btn btn-outline-secondary btn-sm">مسح الفلاتر</a>
    </div>
    <div class="card-body">
        <form method="GET" id="searchForm">
            <input type="hidden" name="page" value="invoices">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">البحث برقم الفاتورة</label>
                    <input type="text" class="form-control" name="invoice_number" id="invoiceSearchInput"
                           value="<?php echo htmlspecialchars($filters['invoice_number'] ?? ''); ?>"
                           placeholder="INV-..." autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الحالة</label>
                    <select class="form-select" name="status" id="filterStatus">
                        <option value="">جميع الحالات</option>
                        <option value="draft" <?php echo ($filters['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>مسودة</option>
                        <option value="sent" <?php echo ($filters['status'] ?? '') === 'sent' ? 'selected' : ''; ?>>مرسلة</option>
                        <option value="partial" <?php echo ($filters['status'] ?? '') === 'partial' ? 'selected' : ''; ?>>مدفوع جزئياً</option>
                        <option value="paid" <?php echo ($filters['status'] ?? '') === 'paid' ? 'selected' : ''; ?>>مدفوعة</option>
                        <option value="overdue" <?php echo ($filters['status'] ?? '') === 'overdue' ? 'selected' : ''; ?>>متأخرة</option>
                        <option value="cancelled" <?php echo ($filters['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>ملغاة</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">العميل</label>
                    <select class="form-select" name="customer_id" id="filterCustomer">
                        <option value="">جميع العملاء</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo (isset($filters['customer_id']) && (int)$filters['customer_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label d-block">فلترة حسب التاريخ</label>
                    <div class="btn-group btn-group-sm mb-2" role="group" id="dateFilterTypeGroup">
                        <input type="radio" class="btn-check" name="date_filter_type" id="dateTypeAll" value="all" <?php echo empty($filters['date_exact'] ?? '') && empty($filters['date_from'] ?? '') ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="dateTypeAll">كل التواريخ</label>
                        <input type="radio" class="btn-check" name="date_filter_type" id="dateTypeSingle" value="single" <?php echo !empty($filters['date_exact'] ?? '') ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="dateTypeSingle">يوم محدد</label>
                        <input type="radio" class="btn-check" name="date_filter_type" id="dateTypeRange" value="range" <?php echo !empty($filters['date_from'] ?? '') && empty($filters['date_exact'] ?? '') ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="dateTypeRange">فترة محددة</label>
                    </div>
                    <div class="row g-2">
                        <div class="col-auto" id="wrapDateExact" style="<?php echo empty($filters['date_exact'] ?? '') ? 'display:none' : ''; ?>">
                            <input type="date" class="form-control form-control-sm" name="date_exact" id="filterDateExact"
                                   value="<?php echo htmlspecialchars($filters['date_exact'] ?? ''); ?>">
                        </div>
                        <div class="col-auto" id="wrapDateRange" style="<?php echo (empty($filters['date_from'] ?? '') || !empty($filters['date_exact'] ?? '')) ? 'display:none' : ''; ?>">
                            <input type="date" class="form-control form-control-sm d-inline-block" name="date_from" id="filterDateFrom"
                                   value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>" placeholder="من">
                            <span class="mx-1 align-middle">–</span>
                            <input type="date" class="form-control form-control-sm d-inline-block" name="date_to" id="filterDateTo"
                                   value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>" placeholder="إلى">
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary" id="searchButton">
                        <i class="bi bi-search"></i> بحث
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="btnClearFilters">
                        <i class="bi bi-x-circle"></i> مسح الفلاتر
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- قائمة الفواتير -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة الفواتير (<span id="totalInvoicesCount"><?php echo $totalInvoices; ?></span>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>العميل</th>
                        <th>التاريخ</th>
                        <th>المبلغ الإجمالي</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody id="invoicesTableBody">
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد فواتير</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $invoice): ?>
                            <?php
                            // استخدام remaining_amount من قاعدة البيانات إذا كان موجوداً، وإلا حسابها
                            $remaining = isset($invoice['remaining_amount']) && $invoice['remaining_amount'] !== null
                                ? (float)$invoice['remaining_amount']
                                : max(0, (float)$invoice['total_amount'] - (float)$invoice['paid_amount']);
                            ?>
                            <tr>
                                <td>
                                    <a href="?page=invoices&id=<?php echo $invoice['id']; ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($invoice['customer_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($invoice['date']); ?></td>
                                <td><?php echo formatCurrency($invoice['total_amount']); ?></td>
                                <td><?php echo formatCurrency($invoice['paid_amount']); ?></td>
                                <td>
                                    <span class="<?php echo $remaining > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo formatCurrency($remaining); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $invoice['status'] === 'paid' ? 'success' : 
                                            ($invoice['status'] === 'partial' ? 'warning' :
                                            ($invoice['status'] === 'sent' ? 'info' : 
                                            ($invoice['status'] === 'cancelled' ? 'danger' : 
                                            ($invoice['status'] === 'overdue' ? 'warning' : 'secondary')))); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'draft' => 'مسودة',
                                            'sent' => 'مرسلة',
                                            'partial' => 'مدفوع جزئياً',
                                            'paid' => 'مدفوعة',
                                            'cancelled' => 'ملغاة',
                                            'overdue' => 'متأخرة'
                                        ];
                                        echo $statuses[$invoice['status']] ?? $invoice['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <div class="btn-group" role="group">
                                            <button type="button" 
                                                    class="btn btn-secondary btn-sm dropdown-toggle" 
                                                    data-bs-toggle="dropdown" 
                                                    aria-expanded="false"
                                                    title="طباعة">
                                                <i class="bi bi-printer"></i> طباعة
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <a class="dropdown-item" 
                                                       href="<?php echo getRelativeUrl('print_invoice.php?id=' . $invoice['id'] . '&print=1&format=a4'); ?>" 
                                                       target="_blank">
                                                        <i class="bi bi-file-earmark-pdf me-2"></i>طباعة A4
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" 
                                                       href="<?php echo getRelativeUrl('print_invoice.php?id=' . $invoice['id'] . '&print=1&format=80mm'); ?>" 
                                                       target="_blank">
                                                        <i class="bi bi-receipt me-2"></i>طباعة 80mm
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                        <button type="button" 
                                                class="btn btn-success btn-sm" 
                                                title="مشاركة الفاتورة خارج المتصفح"
                                                onclick="shareInvoiceExternal(<?php echo $invoice['id']; ?>)">
                                            <i class="bi bi-share"></i> مشاركة
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div id="invoicesPagination">
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=invoices&p=<?php echo $page - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=invoices&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=invoices&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=invoices&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=invoices&p=<?php echo $page + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal إنشاء فاتورة -->
<div class="modal fade" id="addInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء فاتورة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="invoiceForm">
                <input type="hidden" name="action" value="create_invoice">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">العميل <span class="text-danger">*</span></label>
                            <select class="form-select" name="customer_id" id="invoiceCustomer" required>
                                <option value="">اختر العميل</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">مندوب المبيعات</label>
                            <select class="form-select" name="sales_rep_id">
                                <option value="">اختر مندوب</option>
                                <?php foreach ($salesReps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>">
                                        <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">خصم (ج.م)</label>
                            <input type="number" step="0.01" class="form-control" name="discount_amount" value="0" min="0">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر الفاتورة</label>
                        <div id="invoiceItems">
                            <div class="invoice-item row mb-2">
                                <div class="col-md-4">
                                    <select class="form-select product-select" name="items[0][product_id]" required>
                                        <option value="">اختر المنتج</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>" 
                                                    data-price="<?php echo $product['unit_price']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?> 
                                                (<?php echo formatCurrency($product['unit_price']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control" name="items[0][description]" 
                                           placeholder="الوصف (اختياري)">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required min="0.01">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control unit-price" 
                                           name="items[0][unit_price]" placeholder="السعر" required min="0.01">
                                </div>
                                <div class="col-md-2">
                                    <div class="d-flex">
                                        <input type="text" class="form-control item-total" readonly 
                                               placeholder="الإجمالي">
                                        <button type="button" class="btn btn-danger ms-2 remove-item">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between mb-2">
                                        <strong>المجموع الفرعي:</strong>
                                        <span id="subtotal">0.00 ج.م</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <strong>الخصم:</strong>
                                        <span id="discountDisplay">0.00 ج.م</span>
                                    </div>
                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <h5>الإجمالي:</h5>
                                        <h5 id="totalAmount">0.00 ج.م</h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إنشاء فاتورة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تغيير الحالة -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تغيير حالة الفاتورة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="invoice_id" id="statusInvoiceId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status" id="statusSelect" required>
                            <option value="draft">مسودة</option>
                            <option value="sent">مرسلة</option>
                            <option value="paid">مدفوعة</option>
                            <option value="cancelled">ملغاة</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تسجيل دفعة -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تسجيل دفعة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="invoice_id" id="paymentInvoiceId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المبلغ المتبقي</label>
                        <input type="text" class="form-control" id="remainingAmount" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المبلغ المدفوع <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="amount" 
                               id="paymentAmount" required min="0.01">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">تسجيل الدفعة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let itemIndex = 1;

// إضافة عنصر جديد
document.getElementById('addItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('invoiceItems');
    const newItem = document.createElement('div');
    newItem.className = 'invoice-item row mb-2';
    newItem.innerHTML = `
        <div class="col-md-4">
            <select class="form-select product-select" name="items[${itemIndex}][product_id]" required>
                <option value="">اختر المنتج</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo $product['id']; ?>" 
                            data-price="<?php echo $product['unit_price']; ?>">
                        <?php echo htmlspecialchars($product['name']); ?> 
                        (<?php echo formatCurrency($product['unit_price']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control" name="items[${itemIndex}][description]" 
                   placeholder="الوصف (اختياري)">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" class="form-control quantity" 
                   name="items[${itemIndex}][quantity]" placeholder="الكمية" required min="0.01">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" class="form-control unit-price" 
                   name="items[${itemIndex}][unit_price]" placeholder="السعر" required min="0.01">
        </div>
        <div class="col-md-2">
            <div class="d-flex">
                <input type="text" class="form-control item-total" readonly placeholder="الإجمالي">
                <button type="button" class="btn btn-danger ms-2 remove-item">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    itemIndex++;
    attachItemEvents(newItem);
});

// حذف عنصر
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        e.target.closest('.invoice-item').remove();
        calculateTotal();
    }
});

// ربط أحداث العناصر
function attachItemEvents(item) {
    const productSelect = item.querySelector('.product-select');
    const quantityInput = item.querySelector('.quantity');
    const unitPriceInput = item.querySelector('.unit-price');
    const itemTotal = item.querySelector('.item-total');
    
    // تحديث السعر عند اختيار المنتج
    productSelect?.addEventListener('change', function() {
        const price = this.options[this.selectedIndex].dataset.price;
        if (price) {
            unitPriceInput.value = price;
            calculateItemTotal(item);
            calculateTotal();
        }
    });
    
    // حساب إجمالي العنصر
    [quantityInput, unitPriceInput].forEach(input => {
        input?.addEventListener('input', function() {
            calculateItemTotal(item);
            calculateTotal();
        });
    });
}

// حساب إجمالي العنصر
function calculateItemTotal(item) {
    const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
    const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
    const total = quantity * unitPrice;
    item.querySelector('.item-total').value = total.toFixed(2);
}

// حساب الإجمالي الكامل
function calculateTotal() {
    const form = document.getElementById('invoiceForm');
    if (!form) return;
    
    let subtotal = 0;
    document.querySelectorAll('.item-total').forEach(input => {
        subtotal += parseFloat(input.value) || 0;
    });
    
    const discountAmount = parseFloat(form.querySelector('[name="discount_amount"]')?.value) || 0;
    
    const total = subtotal - discountAmount;
    
    const subtotalEl = document.getElementById('subtotal');
    const discountDisplayEl = document.getElementById('discountDisplay');
    const totalAmountEl = document.getElementById('totalAmount');
    
    if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2) + ' ج.م';
    if (discountDisplayEl) discountDisplayEl.textContent = discountAmount.toFixed(2) + ' ج.م';
    if (totalAmountEl) totalAmountEl.textContent = total.toFixed(2) + ' ج.م';
}

// ربط الأحداث للعناصر الموجودة
document.querySelectorAll('.invoice-item').forEach(item => {
    attachItemEvents(item);
});

// ربط أحداث الخصم
document.getElementById('invoiceForm')?.querySelectorAll('[name="discount_amount"]').forEach(input => {
    input.addEventListener('input', calculateTotal);
});

// عرض Modal تغيير الحالة
function showStatusModal(invoiceId, currentStatus) {
    document.getElementById('statusInvoiceId').value = invoiceId;
    document.getElementById('statusSelect').value = currentStatus;
    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
}

// عرض Modal تسجيل دفعة
function showPaymentModal(invoiceId, remaining) {
    document.getElementById('paymentInvoiceId').value = invoiceId;
    document.getElementById('remainingAmount').value = remaining.toFixed(2) + ' ج.م';
    document.getElementById('paymentAmount').value = remaining.toFixed(2);
    document.getElementById('paymentAmount').max = remaining;
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

// تأكيد حذف فاتورة
function deleteInvoiceConfirm(invoiceId) {
    if (confirm('هل أنت متأكد من حذف هذه الفاتورة؟\n\nهذه العملية لا يمكن التراجع عنها.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_invoice">
            <input type="hidden" name="invoice_id" value="${invoiceId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();

async function shareInvoiceExternal(invoiceId) {
    if (!invoiceId || invoiceId <= 0) {
        alert('رقم الفاتورة غير صحيح');
        return;
    }

    // إظهار مؤشر التحميل
    const button = event.target.closest('button');
    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>جاري التحميل...';

    try {
        // الحصول على رابط الفاتورة
        const response = await fetch('<?php echo getRelativeUrl("api/get_invoice_url.php"); ?>?id=' + invoiceId, {
            method: 'GET',
            credentials: 'include'
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error || 'تعذر الحصول على رابط الفاتورة');
        }

        const invoiceUrl = data.url;
        const fileUrl = data.file_url || data.url;
        const fileType = data.file_type || 'pdf';
        const invoiceTitle = data.title || 'فاتورة رقم: ' + (data.invoice_number || invoiceId);
        const fileName = 'فاتورة-' + (data.invoice_number || invoiceId) + '.' + fileType;
        
        // تسجيل معلومات للتشخيص
        console.log('Invoice share data:', {
            fileType: fileType,
            fileUrl: fileUrl,
            pdfFailed: data.pdf_failed || false,
            pdfFailedReason: data.pdf_failed_reason || null
        });
        
        if (data.pdf_failed) {
            console.warn('PDF generation failed:', data.pdf_failed_reason);
            // عرض تحذير للمستخدم
            if (data.pdf_failed_reason === 'API key missing') {
                console.error('PDF API key is missing. Please configure APDF_IO_API_KEY in config.php');
            } else if (data.pdf_failed_reason === 'cURL not available') {
                console.error('cURL extension is not available. PDF generation requires cURL.');
            }
        }
        
        // فتح صفحة الطباعة أولاً
        const printWindow = window.open(invoiceUrl, '_blank');
        
        // بعد فتح صفحة الطباعة، انتظر قليلاً ثم استخدم Web Share API
        setTimeout(async () => {
            try {
                // محاولة استخدام Web Share API مع الملف الفعلي
                if (navigator.share) {
                    // محاولة جلب الملف ومشاركته
                    try {
                        const response = await fetch(fileUrl);
                        if (response.ok) {
                            const blob = await response.blob();
                            const mimeType = fileType === 'pdf' ? 'application/pdf' : 'text/html';
                            const file = new File([blob], fileName, { type: mimeType });
                            
                            await navigator.share({
                                title: invoiceTitle,
                                text: invoiceTitle,
                                files: [file]
                            });
                            alert('تم مشاركة الفاتورة بنجاح');
                        } else {
                            // إذا فشل جلب الملف، استخدم الرابط
                            await navigator.share({
                                title: invoiceTitle,
                                text: invoiceTitle,
                                url: fileUrl
                            });
                            alert('تم مشاركة رابط الفاتورة بنجاح');
                        }
                    } catch (fileError) {
                        // إذا فشل مشاركة الملف، استخدم الرابط
                        await navigator.share({
                            title: invoiceTitle,
                            text: invoiceTitle,
                            url: fileUrl
                        });
                        alert('تم مشاركة رابط الفاتورة بنجاح');
                    }
                } else {
                    // إذا لم يكن Web Share API متاحاً، نسخ الرابط
                    await navigator.clipboard.writeText(fileUrl);
                    alert('تم نسخ رابط الفاتورة إلى الحافظة\nيمكنك الآن مشاركته من أي تطبيق');
                }
            } catch (shareError) {
                // إذا ألغى المستخدم المشاركة أو حدث خطأ
                if (shareError.name !== 'AbortError') {
                    // نسخ الرابط كبديل
                    try {
                        await navigator.clipboard.writeText(fileUrl);
                        alert('تم نسخ رابط الفاتورة إلى الحافظة\nيمكنك الآن مشاركته من أي تطبيق');
                    } catch (clipError) {
                        // عرض الرابط في نافذة منبثقة
                        prompt('انسخ هذا الرابط للمشاركة:', fileUrl);
                    }
                }
            }
        }, 1000);

    } catch (error) {
        console.error('Error sharing invoice:', error);
        alert(error.message || 'حدث خطأ أثناء مشاركة الفاتورة');
    } finally {
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
}

// البحث الديناميكي والفلترة المتقدمة في الفواتير
(function() {
    const searchInput = document.getElementById('invoiceSearchInput');
    const searchForm = document.getElementById('searchForm');
    const tableBody = document.getElementById('invoicesTableBody');
    const paginationContainer = document.getElementById('invoicesPagination');
    const totalCountSpan = document.getElementById('totalInvoicesCount');
    const wrapDateExact = document.getElementById('wrapDateExact');
    const wrapDateRange = document.getElementById('wrapDateRange');
    const dateTypeAll = document.getElementById('dateTypeAll');
    const dateTypeSingle = document.getElementById('dateTypeSingle');
    const dateTypeRange = document.getElementById('dateTypeRange');
    const filterDateExact = document.getElementById('filterDateExact');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    const filterStatus = document.getElementById('filterStatus');
    const filterCustomer = document.getElementById('filterCustomer');
    const btnClearFilters = document.getElementById('btnClearFilters');
    
    let searchTimeout = null;
    let currentAbortController = null;
    let currentPage = <?php echo $page; ?>;
    
    if (!searchInput || !tableBody) {
        return;
    }
    
    function toggleDateFilterUI() {
        const single = dateTypeSingle && dateTypeSingle.checked;
        const range = dateTypeRange && dateTypeRange.checked;
        if (wrapDateExact) wrapDateExact.style.display = single ? '' : 'none';
        if (wrapDateRange) wrapDateRange.style.display = range ? '' : 'none';
        if (filterDateExact) filterDateExact.disabled = !single;
        if (filterDateFrom) filterDateFrom.disabled = !range;
        if (filterDateTo) filterDateTo.disabled = !range;
    }
    
    [dateTypeAll, dateTypeSingle, dateTypeRange].filter(Boolean).forEach(function(r) {
        r.addEventListener('change', toggleDateFilterUI);
    });
    toggleDateFilterUI();
    
    if (btnClearFilters) {
        btnClearFilters.addEventListener('click', function() {
            var base = (typeof window !== 'undefined' && window.location && window.location.pathname) ? window.location.pathname : '';
            window.location.href = base + '?page=invoices';
        });
    }
    
    function getFilterParams() {
        const o = {};
        o.invoice_number = (searchInput && searchInput.value.trim()) || '';
        o.status = (filterStatus && filterStatus.value) || '';
        o.customer_id = (filterCustomer && filterCustomer.value) || '';
        const single = dateTypeSingle && dateTypeSingle.checked;
        const range = dateTypeRange && dateTypeRange.checked;
        if (single && filterDateExact && filterDateExact.value) {
            o.date_exact = filterDateExact.value;
        } else if (range) {
            if (filterDateFrom && filterDateFrom.value) o.date_from = filterDateFrom.value;
            if (filterDateTo && filterDateTo.value) o.date_to = filterDateTo.value;
        }
        return o;
    }
    
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            loadInvoices(1);
        });
    }
    
    searchInput.addEventListener('input', function() {
        if (searchTimeout) clearTimeout(searchTimeout);
        if (currentAbortController) currentAbortController.abort();
        searchTimeout = setTimeout(function() { loadInvoices(1); }, 300);
    });
    
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            if (searchTimeout) clearTimeout(searchTimeout);
            loadInvoices(1);
        }
    });
    
    function loadInvoices(page) {
        currentPage = page;
        const fp = getFilterParams();
        
        if (currentAbortController) currentAbortController.abort();
        currentAbortController = new AbortController();
        
        tableBody.innerHTML = '<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">جاري التحميل...</span></div></td></tr>';
        
        const params = new URLSearchParams();
        params.append('p', page);
        ['invoice_number', 'status', 'customer_id', 'date_exact', 'date_from', 'date_to'].forEach(function(k) {
            if (fp[k]) params.append(k, fp[k]);
        });
        
        fetch('<?php echo getRelativeUrl("api/search_invoices.php"); ?>?' + params.toString(), {
            method: 'GET',
            credentials: 'include',
            signal: currentAbortController.signal
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                tableBody.innerHTML = data.tableRows;
                if (paginationContainer) paginationContainer.innerHTML = data.pagination;
                if (totalCountSpan) totalCountSpan.textContent = data.totalInvoices;
                const url = new URL(window.location);
                url.searchParams.set('page', 'invoices');
                url.searchParams.set('p', page);
                Object.keys(fp).forEach(function(k) { if (fp[k]) url.searchParams.set(k, fp[k]); });
                window.history.pushState({}, '', url);
            } else {
                tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">' + (data.message || 'حدث خطأ في البحث') + '</td></tr>';
            }
        })
        .catch(function(error) {
            if (error.name === 'AbortError') return;
            console.error('Error loading invoices:', error);
            tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">حدث خطأ في تحميل البيانات</td></tr>';
        })
        .finally(function() {
            currentAbortController = null;
        });
    }
    
    window.loadInvoices = loadInvoices;
})();
</script>


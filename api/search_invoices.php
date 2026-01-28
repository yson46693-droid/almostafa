<?php
/**
 * API: البحث الديناميكي في الفواتير
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/invoices.php';
require_once __DIR__ . '/../includes/path_helper.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من الصلاحيات
$currentUser = getCurrentUser();
if (!in_array($currentUser['role'], ['accountant', 'sales', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Pagination
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
if (!empty($filters['date_exact'])) {
    $filters['date_from'] = $filters['date_exact'];
    $filters['date_to'] = $filters['date_exact'];
}
$filters = array_filter($filters, function($value) {
    return $value !== '' && $value !== null;
});

try {
    // الحصول على البيانات
    $totalInvoices = getInvoicesCount($filters);
    $totalPages = ceil($totalInvoices / $perPage);
    $invoices = getInvoices($filters, $perPage, $offset);
    
    // بناء HTML للجدول
    ob_start();
    ?>
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
    <?php
    $tableRows = ob_get_clean();
    
    // بناء HTML للـ Pagination
    ob_start();
    ?>
    <?php if ($totalPages > 1): ?>
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center flex-wrap">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="javascript:void(0);" onclick="loadInvoices(<?php echo $page - 1; ?>)">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="javascript:void(0);" onclick="loadInvoices(1)">1</a></li>
                <?php if ($startPage > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="javascript:void(0);" onclick="loadInvoices(<?php echo $i; ?>)">
                        <?php echo $i; ?>
                    </a>
                </li>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="javascript:void(0);" onclick="loadInvoices(<?php echo $totalPages; ?>)"><?php echo $totalPages; ?></a></li>
            <?php endif; ?>
            
            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                <a class="page-link" href="javascript:void(0);" onclick="loadInvoices(<?php echo $page + 1; ?>)">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
    <?php
    $paginationHtml = ob_get_clean();
    
    $response = [
        'success' => true,
        'tableRows' => $tableRows,
        'pagination' => $paginationHtml,
        'totalInvoices' => $totalInvoices,
        'currentPage' => $page,
        'totalPages' => $totalPages
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Search Invoices Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في البحث'], JSON_UNESCAPED_UNICODE);
}

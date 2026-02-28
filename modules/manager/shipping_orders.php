<?php
/**
 * إدارة طلبات شركات الشحن للمدير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// منع الكاش عند التبديل بين تبويبات الشريط الجانبي لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/inventory_movements.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/customer_history.php';

requireRole(['manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$db = db();

$sessionErrorKey = 'manager_shipping_orders_error';
$sessionSuccessKey = 'manager_shipping_orders_success';
$error = '';
$success = '';

if (!empty($_SESSION[$sessionErrorKey])) {
    $error = $_SESSION[$sessionErrorKey];
    unset($_SESSION[$sessionErrorKey]);
}

if (!empty($_SESSION[$sessionSuccessKey])) {
    $success = $_SESSION[$sessionSuccessKey];
    unset($_SESSION[$sessionSuccessKey]);
}

try {
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `shipping_companies` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(150) NOT NULL,
            `contact_person` varchar(100) DEFAULT NULL,
            `phone` varchar(30) DEFAULT NULL,
            `email` varchar(120) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
            `status` enum('active','inactive') NOT NULL DEFAULT 'active',
            `notes` text DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `updated_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`),
            KEY `status` (`status`),
            KEY `created_by` (`created_by`),
            KEY `updated_by` (`updated_by`),
            CONSTRAINT `shipping_companies_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `shipping_companies_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $tableError) {
    error_log('shipping_orders: failed ensuring shipping_companies table -> ' . $tableError->getMessage());
}

    try {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `shipping_company_orders` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `order_number` varchar(50) NOT NULL,
                `tg_number` varchar(50) DEFAULT NULL COMMENT 'رقم التليجراف',
                `shipping_company_id` int(11) NOT NULL,
                `customer_id` int(11) NOT NULL,
                `invoice_id` int(11) DEFAULT NULL,
                `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                `status` enum('assigned','in_transit','delivered','cancelled') NOT NULL DEFAULT 'assigned',
                `handed_over_at` timestamp NULL DEFAULT NULL,
                `delivered_at` timestamp NULL DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `created_by` int(11) DEFAULT NULL,
                `updated_by` int(11) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `order_number` (`order_number`),
                KEY `tg_number` (`tg_number`),
                KEY `shipping_company_id` (`shipping_company_id`),
                KEY `customer_id` (`customer_id`),
                KEY `invoice_id` (`invoice_id`),
                KEY `status` (`status`),
                CONSTRAINT `shipping_company_orders_company_fk` FOREIGN KEY (`shipping_company_id`) REFERENCES `shipping_companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `shipping_company_orders_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
                CONSTRAINT `shipping_company_orders_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `shipping_company_orders_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        
        // Ensure tg_number column exists if table already exists
        $hasTgColumn = !empty($db->queryOne("SHOW COLUMNS FROM shipping_company_orders LIKE 'tg_number'"));
        if (!$hasTgColumn) {
            $db->execute("ALTER TABLE shipping_company_orders ADD COLUMN tg_number varchar(50) DEFAULT NULL COMMENT 'رقم التليجراف' AFTER order_number");
            $db->execute("ALTER TABLE shipping_company_orders ADD INDEX tg_number (tg_number)");
        }
    } catch (Throwable $tableError) {
        error_log('shipping_orders: failed ensuring shipping_company_orders table -> ' . $tableError->getMessage());
    }

try {
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `shipping_company_order_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `order_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `batch_id` int(11) DEFAULT NULL,
            `quantity` decimal(10,2) NOT NULL,
            `unit_price` decimal(15,2) NOT NULL,
            `total_price` decimal(15,2) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `order_id` (`order_id`),
            KEY `product_id` (`product_id`),
            KEY `batch_id` (`batch_id`),
            CONSTRAINT `shipping_company_order_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `shipping_company_orders` (`id`) ON DELETE CASCADE,
            CONSTRAINT `shipping_company_order_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    
    // إضافة عمود batch_id إذا لم يكن موجوداً
    try {
        $batchIdColumn = $db->queryOne("SHOW COLUMNS FROM shipping_company_order_items LIKE 'batch_id'");
        if (empty($batchIdColumn)) {
            $db->execute("ALTER TABLE shipping_company_order_items ADD COLUMN batch_id int(11) DEFAULT NULL AFTER product_id");
            $db->execute("ALTER TABLE shipping_company_order_items ADD KEY batch_id (batch_id)");
        }
    } catch (Throwable $alterError) {
        // العمود موجود بالفعل أو حدث خطأ
        error_log('shipping_orders: batch_id column check -> ' . $alterError->getMessage());
    }
} catch (Throwable $tableError) {
    error_log('shipping_orders: failed ensuring shipping_company_order_items table -> ' . $tableError->getMessage());
}

// جدول تحصيلات شركات الشحن (مماثل لـ local_collections)
try {
    $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'shipping_company_collections'");
    if (empty($collectionsTableExists)) {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `shipping_company_collections` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `collection_number` varchar(50) DEFAULT NULL COMMENT 'رقم التحصيل',
                `shipping_company_id` int(11) NOT NULL COMMENT 'معرف شركة الشحن',
                `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ المحصل',
                `date` date NOT NULL COMMENT 'تاريخ التحصيل',
                `payment_method` enum('cash','bank','cheque','other') DEFAULT 'cash' COMMENT 'طريقة الدفع',
                `reference_number` varchar(50) DEFAULT NULL COMMENT 'رقم مرجعي',
                `notes` text DEFAULT NULL COMMENT 'ملاحظات',
                `collected_by` int(11) NOT NULL COMMENT 'من قام بالتحصيل',
                `status` enum('pending','approved','rejected') DEFAULT 'pending' COMMENT 'حالة التحصيل',
                `approved_by` int(11) DEFAULT NULL COMMENT 'من وافق على التحصيل',
                `approved_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `collection_number` (`collection_number`),
                KEY `shipping_company_id` (`shipping_company_id`),
                KEY `collected_by` (`collected_by`),
                KEY `date` (`date`),
                KEY `status` (`status`),
                CONSTRAINT `shipping_company_collections_company_fk` FOREIGN KEY (`shipping_company_id`) REFERENCES `shipping_companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `shipping_company_collections_collected_fk` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول التحصيلات من شركات الشحن'"
        );
    }
} catch (Throwable $e) {
    error_log('shipping_orders: shipping_company_collections table -> ' . $e->getMessage());
}

// جدول خصومات شركات الشحن (إجراء خصم من الرصيد بدون تحصيل نقدي)
try {
    $deductionsTableExists = $db->queryOne("SHOW TABLES LIKE 'shipping_company_deductions'");
    if (empty($deductionsTableExists)) {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `shipping_company_deductions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `shipping_company_id` int(11) NOT NULL,
                `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ المخصوم',
                `notes` text DEFAULT NULL COMMENT 'ملاحظات',
                `created_by` int(11) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `shipping_company_id` (`shipping_company_id`),
                KEY `created_at` (`created_at`),
                CONSTRAINT `sc_deductions_company_fk` FOREIGN KEY (`shipping_company_id`) REFERENCES `shipping_companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='خصومات من رصيد شركات الشحن'"
        );
    }
} catch (Throwable $e) {
    error_log('shipping_orders: shipping_company_deductions table -> ' . $e->getMessage());
}

// جدول الفواتير الورقية لشركات الشحن (سجل فواتير ورقية مرفقة بكل شركة)
$paperInvTable = $db->queryOne("SHOW TABLES LIKE 'shipping_company_paper_invoices'");
if (empty($paperInvTable)) {
    try {
        $db->rawQuery(
            "CREATE TABLE IF NOT EXISTS `shipping_company_paper_invoices` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `shipping_company_id` int(11) NOT NULL,
                `invoice_number` varchar(100) DEFAULT NULL COMMENT 'رقم الفاتورة',
                `total_amount` decimal(15,2) NOT NULL COMMENT 'إجمالي الفاتورة',
                `image_path` varchar(500) DEFAULT NULL COMMENT 'مسار صورة الفاتورة الورقية',
                `created_by` int(11) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `shipping_company_id` (`shipping_company_id`),
                KEY `created_at` (`created_at`),
                CONSTRAINT `sc_paper_inv_company_fk` FOREIGN KEY (`shipping_company_id`) REFERENCES `shipping_companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `sc_paper_inv_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير ورقية مرفقة بشركات الشحن'"
        );
    } catch (Throwable $e) {
        error_log('shipping_orders: shipping_company_paper_invoices table -> ' . $e->getMessage());
    }
}

function generateShippingOrderNumber(Database $db): string
{
    $maxAttempts = 10;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $randomNumber = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        
        // التحقق من التفرد
        $exists = $db->queryOne(
            "SELECT id FROM shipping_company_orders WHERE order_number = ?",
            [$randomNumber]
        );
        
        if (!$exists) {
            return $randomNumber;
        }
    }
    
    // في حال عدم العثور على رقم فريد (نادر جداً)، نستخدم الوقت
    return substr(time(), -5);
}

/**
 * جلب بيانات كشف حساب شركة الشحن (سجل الحركات المالية: مدين/دائن والرصيد بعد كل معاملة)
 */
function getShippingCompanyStatementData($companyId) {
    $db = db();
    $movements = [];

    // طلبات الشحن المسلّمة للشركة (مدين) - نستخدم handed_over_at أو created_at
    $orders = $db->query(
        "SELECT id, order_number, total_amount, handed_over_at, created_at
         FROM shipping_company_orders
         WHERE shipping_company_id = ? AND status IN ('assigned', 'in_transit', 'delivered')
         ORDER BY COALESCE(handed_over_at, created_at) ASC",
        [$companyId]
    ) ?: [];
    foreach ($orders as $o) {
        $date = !empty($o['handed_over_at']) ? $o['handed_over_at'] : $o['created_at'];
        $sortDate = date('Y-m-d', strtotime($date));
        $movements[] = [
            'sort_date' => $sortDate,
            'sort_id' => (int)$o['id'],
            'type_order' => 1,
            'type' => 'order',
            'date' => $date,
            'label' => 'طلب شحن #' . ($o['order_number'] ?? $o['id']),
            'debit' => (float)($o['total_amount'] ?? 0),
            'credit' => 0.0,
        ];
    }

    // التحصيلات (دائن)
    $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'shipping_company_collections'");
    if (!empty($collectionsTableExists)) {
        $collections = $db->query(
            "SELECT id, amount, date, collection_number FROM shipping_company_collections WHERE shipping_company_id = ? ORDER BY date ASC, id ASC",
            [$companyId]
        ) ?: [];
        foreach ($collections as $c) {
            $label = !empty($c['collection_number']) ? 'تحصيل ' . $c['collection_number'] : ('تحصيل #' . $c['id']);
            $movements[] = [
                'sort_date' => $c['date'],
                'sort_id' => (int)$c['id'] + 500000,
                'type_order' => 2,
                'type' => 'collection',
                'date' => $c['date'],
                'label' => $label,
                'debit' => 0.0,
                'credit' => (float)($c['amount'] ?? 0),
            ];
        }
    }

    // الخصومات (دائن)
    $deductionsTableExists = $db->queryOne("SHOW TABLES LIKE 'shipping_company_deductions'");
    if (!empty($deductionsTableExists)) {
        $deductions = $db->query(
            "SELECT id, amount, notes, created_at FROM shipping_company_deductions WHERE shipping_company_id = ? ORDER BY created_at ASC",
            [$companyId]
        ) ?: [];
        foreach ($deductions as $d) {
            $movements[] = [
                'sort_date' => date('Y-m-d', strtotime($d['created_at'])),
                'sort_id' => (int)$d['id'] + 600000,
                'type_order' => 3,
                'type' => 'deduction',
                'date' => $d['created_at'],
                'label' => 'خصم' . (!empty(trim($d['notes'] ?? '')) ? ' - ' . trim($d['notes']) : ''),
                'debit' => 0.0,
                'credit' => (float)($d['amount'] ?? 0),
            ];
        }
    }

    usort($movements, function ($a, $b) {
        $c = strcmp($a['sort_date'], $b['sort_date']);
        if ($c !== 0) return $c;
        $to = ($a['type_order'] ?? 9) - ($b['type_order'] ?? 9);
        return $to !== 0 ? $to : ($a['sort_id'] - $b['sort_id']);
    });

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

    return ['movements' => $movements, 'totals' => $totals];
}

$mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
if (!$mainWarehouse) {
    $db->execute(
        "INSERT INTO warehouses (name, warehouse_type, status, location, description) VALUES (?, 'main', 'active', ?, ?)",
        ['المخزن الرئيسي', 'الموقع الرئيسي للشركة', 'تم إنشاء هذا المخزن تلقائياً لطلبات الشحن']
    );
    $mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($action === 'get_shipping_company_statement' && $isAjax) {
        $companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
        if ($companyId <= 0) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'معرف الشركة غير صالح.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $company = $db->queryOne("SELECT id, name, balance FROM shipping_companies WHERE id = ?", [$companyId]);
        if (!$company) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'الشركة غير موجودة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $data = getShippingCompanyStatementData($companyId);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'company_name' => $company['name'] ?? '',
            'current_balance' => (float)($company['balance'] ?? 0),
            'movements' => $data['movements'],
            'totals' => $data['totals'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'search_orders') {
        $query = trim($_POST['query'] ?? '');

        if ($query === '') {
            echo json_encode(['html' => '']);
            exit;
        }

        try {
            // Clean output buffer to ensure valid JSON
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');

            $searchTerm = "%{$query}%";
            $orders = $db->query(
                "SELECT 
                    sco.*, 
                    sc.name AS shipping_company_name,
                    sc.balance AS company_balance,
                    COALESCE(lc.name, c.name) AS customer_name,
                    COALESCE(lc.phone, c.phone) AS customer_phone,
                    COALESCE(lc.balance, c.balance, 0) AS customer_balance,
                    (lc.id IS NOT NULL) AS is_local_customer,
                    i.invoice_number
                FROM shipping_company_orders sco
                LEFT JOIN shipping_companies sc ON sco.shipping_company_id = sc.id
                LEFT JOIN local_customers lc ON sco.customer_id = lc.id
                LEFT JOIN customers c ON sco.customer_id = c.id AND lc.id IS NULL
                LEFT JOIN invoices i ON sco.invoice_id = i.id
                WHERE 
                    sco.order_number LIKE ? OR 
                    sco.tg_number LIKE ? OR
                    sc.name LIKE ? OR 
                    lc.name LIKE ? OR 
                    c.name LIKE ? OR 
                    lc.phone LIKE ? OR
                    c.phone LIKE ? OR
                    sco.notes LIKE ? OR
                    EXISTS (
                        SELECT 1 
                        FROM shipping_company_order_items scoi 
                        JOIN products p ON scoi.product_id = p.id 
                        WHERE scoi.order_id = sco.id AND (p.name LIKE ?)
                    )
                ORDER BY sco.created_at DESC
                LIMIT 50",
                [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]
            );

            $html = '';
            $statusLabels = [
                'assigned' => ['label' => 'تم التسليم لشركة الشحن', 'class' => 'bg-primary'],
                'in_transit' => ['label' => 'جاري الشحن', 'class' => 'bg-warning text-dark'],
                'delivered' => ['label' => 'تم التسليم للعميل', 'class' => 'bg-success'],
                'cancelled' => ['label' => 'ملغي', 'class' => 'bg-secondary'],
            ];

            foreach ($orders as $order) {
                $statusInfo = $statusLabels[$order['status']] ?? ['label' => $order['status'], 'class' => 'bg-secondary'];
                $invoiceLink = '';
                if (!empty($order['invoice_id'])) {
                    $invoiceUrl = getRelativeUrl('print_invoice.php?id=' . (int)$order['invoice_id']);
                    $invoiceLink = '<a href="' . htmlspecialchars($invoiceUrl) . '" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>عرض الفاتورة</a>';
                }

                $html .= '<tr>';
                
                // Column 1: Order Number
                $html .= '<td>';
                $html .= '<div class="fw-semibold">#' . htmlspecialchars($order['order_number']) . '</div>';
                $html .= '<div class="text-muted small">' . formatDateTime($order['created_at']) . '</div>';
                if (!empty($order['tg_number'])) {
                    $html .= '<div class="text-info small"><i class="bi bi-hash"></i> TG: ' . htmlspecialchars($order['tg_number']) . '</div>';
                }
                $html .= '</td>';

                // Column 2: Shipping Company
                $html .= '<td>';
                $html .= '<div class="fw-semibold">' . htmlspecialchars($order['shipping_company_name'] ?? 'غير معروف') . '</div>';
                $html .= '</td>';

                // Column 3: Customer
                $html .= '<td>';
                $html .= '<div class="fw-semibold">' . htmlspecialchars($order['customer_name'] ?? 'غير محدد') . '</div>';
                if (!empty($order['customer_phone'])) {
                    $html .= '<div class="text-muted small"><i class="bi bi-telephone"></i> ' . htmlspecialchars($order['customer_phone']) . '</div>';
                }
                $html .= '</td>';

                // Column 4: Amount
                $html .= '<td class="fw-semibold">' . formatCurrency((float)$order['total_amount']) . '</td>';

                // Column 5: Status
                $html .= '<td>';
                $html .= '<span class="badge ' . $statusInfo['class'] . '">' . htmlspecialchars($statusInfo['label']) . '</span>';
                if (!empty($order['handed_over_at'])) {
                    $html .= '<div class="text-muted small mt-1">سُلِّم: ' . formatDateTime($order['handed_over_at']) . '</div>';
                }
                $html .= '</td>';

                // Column 6: Invoice
                $html .= '<td>';
                if (!empty($invoiceLink)) {
                    $html .= $invoiceLink;
                } else {
                    $html .= '<span class="text-muted">لا توجد فاتورة</span>';
                }
                $html .= '</td>';

                // Column 7: Actions
                $html .= '<td>';
                $html .= '<div class="d-flex flex-wrap gap-2">';
                
                // سجل الفواتير والفاتورة الورقية (يظهر لجميع الطلبات؛ المحتوى حسب عميل محلي أو لا)
                if (!empty($order['customer_id'])) {
                    $html .= '<button type="button" class="btn btn-outline-info btn-sm btn-shipping-invoice-log" onclick="showShippingInvoiceLogModal(this)" ';
                    $html .= 'data-customer-id="' . (int)$order['customer_id'] . '" data-customer-name="' . htmlspecialchars($order['customer_name'] ?? 'غير محدد') . '" ';
                    $html .= 'data-is-local="' . ((int)(isset($order['is_local_customer']) ? $order['is_local_customer'] : 0)) . '" title="سجل الفواتير والفاتورة الورقية">';
                    $html .= '<i class="bi bi-receipt me-1"></i>سجل الفواتير';
                    $html .= '</button>';
                }

                // Cancel Button (Only if not delivered/cancelled)
                if ($order['status'] !== 'cancelled' && $order['status'] !== 'delivered') {
                    $html .= '<form method="POST" class="d-inline cancel-order-form" onsubmit="return handleCancelOrder(event, this);" ';
                    $html .= 'data-order-number="' . htmlspecialchars($order['order_number'] ?? '') . '" ';
                    $html .= 'data-total-amount="' . (float)($order['total_amount'] ?? 0) . '">';
                    $html .= '<input type="hidden" name="action" value="cancel_shipping_order">';
                    $html .= '<input type="hidden" name="order_id" value="' . (int)$order['id'] . '">';
                    $html .= '<button type="submit" class="btn btn-outline-danger btn-sm cancel-order-btn">';
                    $html .= '<i class="bi bi-x-circle me-1"></i>إلغاء';
                    $html .= '</button>';
                    $html .= '</form>';
                }

                // Delivery Button (Only if in_transit or assigned)
                if ($order['status'] === 'in_transit' || $order['status'] === 'assigned') {
                    $html .= '<button type="button" class="btn btn-success btn-sm delivery-btn" onclick="showDeliveryModal(this)" ';
                    $html .= 'data-order-id="' . (int)$order['id'] . '" ';
                    $html .= 'data-order-number="' . htmlspecialchars($order['order_number'] ?? '') . '" ';
                    $html .= 'data-customer-id="' . (int)($order['customer_id'] ?? 0) . '" ';
                    $html .= 'data-customer-name="' . htmlspecialchars($order['customer_name'] ?? 'غير محدد') . '" ';
                    $html .= 'data-customer-balance="' . (float)($order['customer_balance'] ?? 0) . '" ';
                    $html .= 'data-total-amount="' . (float)$order['total_amount'] . '" ';
                    $html .= 'data-shipping-company-name="' . htmlspecialchars($order['shipping_company_name'] ?? 'غير معروف') . '" ';
                    $html .= 'data-company-balance="' . (float)($order['company_balance'] ?? 0) . '">';
                    $html .= '<i class="bi bi-check-circle me-1"></i>تسليم';
                    $html .= '</button>';
                }
                
                $html .= '</div>';
                $html .= '</td>';

                $html .= '</tr>';
            }
            
            if ($html === '') {
                 $html = '<tr><td colspan="7" class="text-center p-4 text-muted">لا توجد نتائج مطابقة لـ "' . htmlspecialchars($query) . '"</td></tr>';
            }

            echo json_encode(['html' => $html]);
        } catch (Throwable $e) {
            error_log('search_orders error: ' . $e->getMessage());
            echo json_encode(['html' => '<tr><td colspan="7" class="text-center text-danger">حدث خطأ أثناء البحث</td></tr>']);
        }
        exit;
    }

    if ($action === 'add_shipping_company') {
        $name = trim($_POST['company_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['company_notes'] ?? '');

        if ($name === '') {
            $_SESSION[$sessionErrorKey] = 'يجب إدخال اسم شركة الشحن.';
        } else {
            try {
                $existingCompany = $db->queryOne("SELECT id FROM shipping_companies WHERE name = ?", [$name]);
                if ($existingCompany) {
                    throw new InvalidArgumentException('اسم شركة الشحن مستخدم بالفعل.');
                }

                $db->execute(
                    "INSERT INTO shipping_companies (name, contact_person, phone, email, address, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $name,
                        $contactPerson !== '' ? $contactPerson : null,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                        $address !== '' ? $address : null,
                        $notes !== '' ? $notes : null,
                        $currentUser['id'] ?? null,
                    ]
                );

                $_SESSION[$sessionSuccessKey] = 'تم إضافة شركة الشحن بنجاح.';
            } catch (InvalidArgumentException $validationError) {
                $_SESSION[$sessionErrorKey] = $validationError->getMessage();
            } catch (Throwable $addError) {
                error_log('shipping_orders: add company error -> ' . $addError->getMessage());
                $_SESSION[$sessionErrorKey] = 'تعذر إضافة شركة الشحن. يرجى المحاولة لاحقاً.';
            }
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'add_local_customer') {
        $name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['customer_phone'] ?? '');
        $address = trim($_POST['customer_address'] ?? '');
        $balance = isset($_POST['customer_balance']) ? cleanFinancialValue($_POST['customer_balance'], true) : 0;

        if ($name === '') {
            $_SESSION[$sessionErrorKey] = 'يجب إدخال اسم العميل.';
        } else {
            try {
                // التحقق من وجود جدول local_customers
                $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
                if (empty($localCustomersTableExists)) {
                    throw new RuntimeException('جدول العملاء المحليين غير موجود. يرجى التأكد من إعداد النظام.');
                }

                // التحقق من عدم تكرار اسم العميل
                $existingCustomer = $db->queryOne("SELECT id FROM local_customers WHERE name = ?", [$name]);
                if ($existingCustomer) {
                    throw new InvalidArgumentException('اسم العميل مستخدم بالفعل. يرجى اختيار اسم آخر.');
                }

                // إضافة العميل الجديد
                $result = $db->execute(
                    "INSERT INTO local_customers (name, phone, address, balance, status, created_by) VALUES (?, ?, ?, ?, 'active', ?)",
                    [
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $balance,
                        $currentUser['id'] ?? null,
                    ]
                );

                $newCustomerId = (int)($result['insert_id'] ?? 0);
                if ($newCustomerId <= 0) {
                    throw new RuntimeException('فشل إضافة العميل: لم يتم الحصول على معرف العميل.');
                }

                // تسجيل العملية
                require_once __DIR__ . '/../../includes/audit_log.php';
                logAudit($currentUser['id'], 'add_local_customer_from_shipping', 'local_customer', $newCustomerId, null, [
                    'name' => $name,
                    'from_shipping_page' => true,
                ]);

                // إرجاع معرف العميل الجديد في الجلسة لاستخدامه في JavaScript
                $_SESSION['new_customer_id'] = $newCustomerId;
                $_SESSION['new_customer_name'] = $name;
                $_SESSION['new_customer_phone'] = $phone;
                $_SESSION[$sessionSuccessKey] = 'تم إضافة العميل بنجاح.';
            } catch (InvalidArgumentException $validationError) {
                $_SESSION[$sessionErrorKey] = $validationError->getMessage();
            } catch (Throwable $addError) {
                error_log('shipping_orders: add local customer error -> ' . $addError->getMessage());
                $errorMessage = $addError->getMessage();
                if (stripos($errorMessage, 'duplicate') !== false || stripos($errorMessage, '1062') !== false) {
                    $_SESSION[$sessionErrorKey] = 'يوجد عميل مسجل مسبقاً بنفس الاسم.';
                } else {
                    $_SESSION[$sessionErrorKey] = 'تعذر إضافة العميل: ' . htmlspecialchars($errorMessage);
                }
            }
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'edit_shipping_company_balance') {
        $companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
        $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : null;

        if ($companyId <= 0) {
            $_SESSION[$sessionErrorKey] = 'معرف شركة الشحن غير صالح.';
        } elseif ($balance === null) {
            $_SESSION[$sessionErrorKey] = 'يجب إدخال قيمة الرصيد.';
        } else {
            try {
                $company = $db->queryOne("SELECT id, name FROM shipping_companies WHERE id = ?", [$companyId]);
                if (!$company) {
                    throw new InvalidArgumentException('شركة الشحن غير موجودة.');
                }
                $db->execute(
                    "UPDATE shipping_companies SET balance = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$balance, $currentUser['id'] ?? null, $companyId]
                );
                logAudit(
                    $currentUser['id'] ?? null,
                    'edit_shipping_company_balance',
                    'shipping_company',
                    $companyId,
                    null,
                    ['balance' => $balance, 'company_name' => $company['name'] ?? '']
                );
                $_SESSION[$sessionSuccessKey] = 'تم تحديث ديون شركة الشحن بنجاح.';
            } catch (InvalidArgumentException $e) {
                $_SESSION[$sessionErrorKey] = $e->getMessage();
            } catch (Throwable $e) {
                error_log('shipping_orders: edit company balance -> ' . $e->getMessage());
                $_SESSION[$sessionErrorKey] = 'تعذر تحديث الرصيد. يرجى المحاولة لاحقاً.';
            }
        }
        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'collect_from_shipping_company') {
        $companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
        $amount = isset($_POST['amount']) ? cleanFinancialValue($_POST['amount']) : 0;
        $collectionType = isset($_POST['collection_type']) ? trim($_POST['collection_type']) : 'direct';

        if ($companyId <= 0) {
            $_SESSION[$sessionErrorKey] = 'معرف شركة الشحن غير صالح.';
        } elseif ($amount <= 0) {
            $_SESSION[$sessionErrorKey] = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } elseif (!in_array($collectionType, ['direct', 'management'])) {
            $_SESSION[$sessionErrorKey] = 'نوع التحصيل غير صالح.';
        } else {
            $transactionStarted = false;
            try {
                $db->beginTransaction();
                $transactionStarted = true;

                $company = $db->queryOne(
                    "SELECT id, name, balance FROM shipping_companies WHERE id = ? FOR UPDATE",
                    [$companyId]
                );
                if (!$company) {
                    throw new InvalidArgumentException('لم يتم العثور على شركة الشحن.');
                }
                $currentBalance = (float)($company['balance'] ?? 0);
                if ($currentBalance <= 0) {
                    throw new InvalidArgumentException('لا توجد ديون نشطة على هذه الشركة.');
                }
                if ($amount > $currentBalance) {
                    throw new InvalidArgumentException('المبلغ المدخل أكبر من ديون الشركة الحالية.');
                }
                $newBalance = round(max($currentBalance - $amount, 0), 2);

                $db->execute(
                    "UPDATE shipping_companies SET balance = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$newBalance, $currentUser['id'] ?? null, $companyId]
                );

                logAudit(
                    $currentUser['id'] ?? null,
                    'collect_shipping_company_debt',
                    'shipping_company',
                    $companyId,
                    null,
                    ['collected_amount' => $amount, 'previous_balance' => $currentBalance, 'new_balance' => $newBalance]
                );

                $collectionNumber = null;
                $collectionId = null;
                $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'shipping_company_collections'");
                if (!empty($collectionsTableExists)) {
                    $hasStatusColumn = !empty($db->queryOne("SHOW COLUMNS FROM shipping_company_collections LIKE 'status'"));
                    $hasCollectionNumberColumn = !empty($db->queryOne("SHOW COLUMNS FROM shipping_company_collections LIKE 'collection_number'"));
                    $hasNotesColumn = !empty($db->queryOne("SHOW COLUMNS FROM shipping_company_collections LIKE 'notes'"));

                    if ($hasCollectionNumberColumn) {
                        $year = date('Y');
                        $month = date('m');
                        $lastCollection = $db->queryOne(
                            "SELECT collection_number FROM shipping_company_collections WHERE collection_number LIKE ? ORDER BY collection_number DESC LIMIT 1 FOR UPDATE",
                            ["SHIP-COL-{$year}{$month}-%"]
                        );
                        $serial = 1;
                        if (!empty($lastCollection['collection_number'])) {
                            $parts = explode('-', $lastCollection['collection_number']);
                            $serial = (int)($parts[3] ?? 0) + 1;
                        }
                        $collectionNumber = sprintf("SHIP-COL-%s%s-%04d", $year, $month, $serial);
                    }

                    $collectionDate = date('Y-m-d');
                    $collectionColumns = ['shipping_company_id', 'amount', 'date', 'payment_method', 'collected_by'];
                    $collectionValues = [$companyId, $amount, $collectionDate, 'cash', $currentUser['id'] ?? null];
                    $collectionPlaceholders = array_fill(0, count($collectionColumns), '?');

                    if ($hasCollectionNumberColumn && $collectionNumber !== null) {
                        array_unshift($collectionColumns, 'collection_number');
                        array_unshift($collectionValues, $collectionNumber);
                        array_unshift($collectionPlaceholders, '?');
                    }
                    if ($hasNotesColumn) {
                        $collectionColumns[] = 'notes';
                        $collectionValues[] = 'تحصيل من صفحة طلبات الشحن';
                        $collectionPlaceholders[] = '?';
                    }
                    if ($hasStatusColumn) {
                        $collectionColumns[] = 'status';
                        $collectionValues[] = 'approved';
                        $collectionPlaceholders[] = '?';
                    }

                    $db->execute(
                        "INSERT INTO shipping_company_collections (" . implode(', ', $collectionColumns) . ") VALUES (" . implode(', ', $collectionPlaceholders) . ")",
                        $collectionValues
                    );
                    $collectionId = $db->getLastInsertId();
                    logAudit(
                        $currentUser['id'] ?? null,
                        'add_shipping_company_collection',
                        'shipping_company_collection',
                        $collectionId,
                        null,
                        ['collection_number' => $collectionNumber, 'shipping_company_id' => $companyId, 'amount' => $amount]
                    );
                }

                $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                if (!empty($accountantTableExists)) {
                    $companyName = $company['name'] ?? 'شركة شحن';
                    $description = ($collectionType === 'management')
                        ? 'تحصيل للإدارة من شركة شحن: ' . $companyName
                        : 'تحصيل من شركة شحن: ' . $companyName;
                    $referenceNumber = $collectionNumber ?? ('SHIP-CUST-' . $companyId . '-' . date('YmdHis'));

                    $transactionColumns = [
                        'transaction_type', 'amount', 'description', 'reference_number', 'payment_method',
                        'status', 'created_by', 'approved_by', 'approved_at'
                    ];
                    $transactionValues = [
                        'income', $amount, $description, $referenceNumber, 'cash',
                        'approved', $currentUser['id'] ?? null, $currentUser['id'] ?? null, date('Y-m-d H:i:s')
                    ];

                    $hasShippingCompanyIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM accountant_transactions LIKE 'shipping_company_id'"));
                    if ($hasShippingCompanyIdColumn) {
                        $transactionColumns[] = 'shipping_company_id';
                        $transactionValues[] = $companyId;
                    }
                    $hasShippingCollectionIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM accountant_transactions LIKE 'shipping_company_collection_id'"));
                    if ($hasShippingCollectionIdColumn && $collectionId !== null) {
                        $transactionColumns[] = 'shipping_company_collection_id';
                        $transactionValues[] = $collectionId;
                    }
                    $placeholders = array_fill(0, count($transactionColumns), '?');
                    $db->execute(
                        "INSERT INTO accountant_transactions (" . implode(', ', $transactionColumns) . ") VALUES (" . implode(', ', $placeholders) . ")",
                        $transactionValues
                    );
                    logAudit(
                        $currentUser['id'] ?? null,
                        'add_income_from_shipping_company_collection',
                        'accountant_transaction',
                        $db->getLastInsertId(),
                        null,
                        ['shipping_company_id' => $companyId, 'amount' => $amount, 'reference_number' => $referenceNumber]
                    );
                }

                $db->commit();
                $transactionStarted = false;
                $msg = 'تم تحصيل المبلغ بنجاح.';
                if (!empty($collectionNumber)) {
                    $msg .= ' رقم التحصيل: ' . $collectionNumber . '.';
                }
                $_SESSION[$sessionSuccessKey] = $msg;
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => true,
                        'message' => $msg,
                        'amount_collected' => (float)$amount,
                        'new_balance' => (float)$newBalance,
                        'collection_number' => $collectionNumber,
                        'company_id' => $companyId
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } catch (InvalidArgumentException $e) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                $_SESSION[$sessionErrorKey] = $e->getMessage();
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                error_log('shipping_orders: collect from shipping company -> ' . $e->getMessage());
                $_SESSION[$sessionErrorKey] = 'حدث خطأ أثناء تحصيل المبلغ. يرجى المحاولة مرة أخرى.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'حدث خطأ أثناء تحصيل المبلغ. يرجى المحاولة مرة أخرى.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $_SESSION[$sessionErrorKey] ?? 'خطأ في البيانات المدخلة.'], JSON_UNESCAPED_UNICODE);
            if (!empty($_SESSION[$sessionErrorKey])) {
                unset($_SESSION[$sessionErrorKey]);
            }
            exit;
        }
        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'deduct_from_shipping_company') {
        $companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
        $amount = isset($_POST['amount']) ? cleanFinancialValue($_POST['amount']) : 0;
        $notes = trim($_POST['notes'] ?? '');

        if ($companyId <= 0) {
            $_SESSION[$sessionErrorKey] = 'معرف شركة الشحن غير صالح.';
        } elseif ($amount <= 0) {
            $_SESSION[$sessionErrorKey] = 'يجب إدخال مبلغ خصم أكبر من صفر.';
        } else {
            try {
                $company = $db->queryOne(
                    "SELECT id, name, balance FROM shipping_companies WHERE id = ? FOR UPDATE",
                    [$companyId]
                );
                if (!$company) {
                    throw new InvalidArgumentException('لم يتم العثور على شركة الشحن.');
                }
                $currentBalance = (float)($company['balance'] ?? 0);
                if ($amount > $currentBalance) {
                    throw new InvalidArgumentException('المبلغ المدخل أكبر من ديون الشركة الحالية.');
                }
                $newBalance = round(max($currentBalance - $amount, 0), 2);

                $db->execute(
                    "UPDATE shipping_companies SET balance = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$newBalance, $currentUser['id'] ?? null, $companyId]
                );

                $deductionsTableExists = $db->queryOne("SHOW TABLES LIKE 'shipping_company_deductions'");
                if (!empty($deductionsTableExists)) {
                    $db->execute(
                        "INSERT INTO shipping_company_deductions (shipping_company_id, amount, notes, created_by) VALUES (?, ?, ?, ?)",
                        [$companyId, $amount, $notes ?: null, $currentUser['id'] ?? null]
                    );
                }

                logAudit(
                    $currentUser['id'] ?? null,
                    'deduct_from_shipping_company',
                    'shipping_company',
                    $companyId,
                    null,
                    ['amount' => $amount, 'previous_balance' => $currentBalance, 'new_balance' => $newBalance]
                );

                $_SESSION[$sessionSuccessKey] = 'تم خصم المبلغ بنجاح. الرصيد الجديد: ' . number_format($newBalance, 2) . ' ج.م.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => true,
                        'message' => 'تم خصم المبلغ بنجاح.',
                        'new_balance' => (float)$newBalance,
                        'company_id' => $companyId
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } catch (InvalidArgumentException $e) {
                $_SESSION[$sessionErrorKey] = $e->getMessage();
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } catch (Throwable $e) {
                error_log('shipping_orders: deduct_from_shipping_company -> ' . $e->getMessage());
                $_SESSION[$sessionErrorKey] = 'حدث خطأ أثناء تنفيذ الخصم. يرجى المحاولة مرة أخرى.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'حدث خطأ أثناء تنفيذ الخصم.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $_SESSION[$sessionErrorKey] ?? 'خطأ في البيانات المدخلة.'], JSON_UNESCAPED_UNICODE);
            if (!empty($_SESSION[$sessionErrorKey])) unset($_SESSION[$sessionErrorKey]);
            exit;
        }
        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'create_shipping_order') {
        $shippingCompanyId = isset($_POST['shipping_company_id']) ? (int)$_POST['shipping_company_id'] : 0;
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $notes = trim($_POST['order_notes'] ?? '');
        $tgNumber = trim($_POST['tg_number'] ?? '');
        $customTotalAmount = isset($_POST['custom_total_amount']) ? (float)$_POST['custom_total_amount'] : null;
        $itemsInput = $_POST['items'] ?? [];

        if ($shippingCompanyId <= 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار شركة الشحن.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        if ($customerId <= 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار العميل.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        // إذا تم تحديد مبلغ يدوي، يمكن تجاوز التحقق من المنتجات
        $hasCustomAmount = ($customTotalAmount !== null && $customTotalAmount >= 0);
        
        if ((!is_array($itemsInput) || empty($itemsInput)) && !$hasCustomAmount) {
            $_SESSION[$sessionErrorKey] = 'يرجى إضافة منتجات إلى الطلب أو تحديد قيمة إجمالية للطلب.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        $normalizedItems = [];
        $totalAmountFromItems = 0.0;
        $productIds = [];

        if (is_array($itemsInput) && !empty($itemsInput)) {
            error_log("shipping_orders: Processing create_shipping_order - itemsInput count: " . count($itemsInput));
            foreach ($itemsInput as $index => $itemRow) {
                // ... existing processing logic ...
                if (!is_array($itemRow)) continue;

                $productId = isset($itemRow['product_id']) ? (int)$itemRow['product_id'] : 0;
                $rawQuantity = $itemRow['quantity'] ?? 0.0;
                $quantity = (float)$rawQuantity;
                
                if ($quantity <= 0 || $quantity > 100000) continue;
                
                $unitPrice = isset($itemRow['unit_price']) ? (float)$itemRow['unit_price'] : 0.0;
                $batchId = !empty($itemRow['batch_id']) && (int)$itemRow['batch_id'] > 0 ? (int)$itemRow['batch_id'] : null;
                $productType = isset($itemRow['product_type']) ? trim($itemRow['product_type']) : '';

                if ($productId <= 0 || $unitPrice < 0) continue;

                $originalProductId = $productId;
                if ($productId > 1000000 && $productType === 'factory') {
                    $originalProductId = $productId - 1000000;
                }

                $productIds[] = $originalProductId;
                $lineTotal = isset($itemRow['line_total']) && (float)$itemRow['line_total'] >= 0
                    ? round((float)$itemRow['line_total'], 2)
                    : round($quantity * $unitPrice, 2);
                $totalAmountFromItems += $lineTotal;

                $normalizedItems[] = [
                    'product_id' => $originalProductId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                    'batch_id' => $batchId,
                    'product_type' => $productType,
                ];
            }
        }

        // السماح بإنشاء طلب بدون منتجات إذا كان هناك مبلغ يدوي
        if (empty($normalizedItems) && !$hasCustomAmount) {
            $_SESSION[$sessionErrorKey] = 'يرجى التأكد من إدخال بيانات صحيحة للممنتجات.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        // تجميع العناصر المكررة
        $groupedItems = [];
        foreach ($normalizedItems as $index => $item) {
             $batchIdForKey = $item['batch_id'] ?? null;
            $key = $item['product_id'] . '_' . ($batchIdForKey ?? 'null') . '_' . $item['product_type'];
            
            if (!isset($groupedItems[$key])) {
                $groupedItems[$key] = $item;
            } else {
                $groupedItems[$key]['quantity'] += $item['quantity'];
                if ($item['unit_price'] > $groupedItems[$key]['unit_price']) {
                    $groupedItems[$key]['unit_price'] = $item['unit_price'];
                }
                $groupedItems[$key]['total_price'] = round($groupedItems[$key]['quantity'] * $groupedItems[$key]['unit_price'], 2);
            }
        }
        
        // إعادة حساب المجموع من العناصر (للتحقق)
        $totalAmountFromItems = 0.0;
        foreach ($groupedItems as $item) {
            $totalAmountFromItems += $item['total_price'];
        }
        $normalizedItems = array_values($groupedItems);
        
        // تحديد المبلغ النهائي للطلب
        // إذا تم إدخال مبلغ يدوي نستخدمه، وإلا نستخدم مجموع المنتجات
        $finalTotalAmount = $hasCustomAmount ? $customTotalAmount : round($totalAmountFromItems, 2);

        $transactionStarted = false;

        try {
            $db->beginTransaction();
            $transactionStarted = true;

            $shippingCompany = $db->queryOne(
                "SELECT id, status, balance FROM shipping_companies WHERE id = ? FOR UPDATE",
                [$shippingCompanyId]
            );

            if (!$shippingCompany || ($shippingCompany['status'] ?? '') !== 'active') {
                throw new InvalidArgumentException('شركة الشحن المحددة غير متاحة أو غير نشطة.');
            }

            // البحث عن العميل في جدول local_customers (العملاء المحليين)
            $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
            if (empty($localCustomersTableExists)) {
                throw new InvalidArgumentException('جدول العملاء المحليين غير متوفر في النظام.');
            }

            $customer = $db->queryOne(
                "SELECT id, balance, status FROM local_customers WHERE id = ? FOR UPDATE",
                [$customerId]
            );

            if (!$customer) {
                error_log('Shipping order: Customer not found - customer_id: ' . $customerId);
                throw new InvalidArgumentException('تعذر العثور على العميل المحدد. يرجى التحقق من اختيار العميل.');
            }

            if (($customer['status'] ?? '') !== 'active') {
                error_log('Shipping order: Customer is not active - customer_id: ' . $customerId . ', status: ' . ($customer['status'] ?? 'unknown'));
                throw new InvalidArgumentException('العميل المحدد غير نشط. يرجى اختيار عميل نشط.');
            }

            // التحقق من الكميات المتاحة
            foreach ($normalizedItems as $normalizedItem) {
                $productId = $normalizedItem['product_id'];
                $requestedQuantity = $normalizedItem['quantity'];
                $productType = $normalizedItem['product_type'] ?? '';
                $batchId = $normalizedItem['batch_id'] ?? null;

                if ($productType === 'factory' && $batchId) {
                    // للمنتجات من المصنع، التحقق من الكمية المتاحة في finished_products والمخزن الرئيسي
                    $fp = $db->queryOne("
                        SELECT 
                            fp.id,
                            fp.quantity_produced,
                            fp.batch_number,
                            COALESCE(fp.product_id, bn.product_id) AS product_id,
                            COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name
                        FROM finished_products fp
                        LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                        LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                        WHERE fp.id = ?
                    ", [$batchId]);

                    if (!$fp) {
                        throw new InvalidArgumentException('تعذر العثور على منتج من عناصر الطلب.');
                    }

                    $quantityProduced = (float)($fp['quantity_produced'] ?? 0);
                    $actualProductId = (int)($fp['product_id'] ?? 0);
                    
                    // للمنتجات التي لها رقم تشغيلة: استخدام quantity_produced مباشرة
                    // وعدم استخدام products.quantity لأنها قد تكون لجميع أرقام التشغيلة مجتمعة
                    $quantity = $quantityProduced;
                    
                    // حساب الكميات المحجوزة والمباعة (يُطبق فقط على quantity_produced للمنتجات التي لها رقم تشغيلة)
                    $soldQty = 0;
                    $pendingQty = 0;
                    $pendingShippingQty = 0;
                    
                    if (!empty($fp['batch_number'])) {
                        try {
                            // حساب الكمية المباعة (مثل company_products.php)
                            $sold = $db->queryOne("
                                SELECT COALESCE(SUM(ii.quantity), 0) AS sold_quantity
                                FROM invoice_items ii
                                INNER JOIN invoices i ON ii.invoice_id = i.id
                                INNER JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                                INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                                WHERE bn.batch_number = ?
                            ", [$fp['batch_number']]);
                            $soldQty = (float)($sold['sold_quantity'] ?? 0);
                            
                            // حساب الكمية المحجوزة في طلبات العملاء المعلقة
                            // ملاحظة: customer_order_items لا يحتوي على batch_number مباشرة
                            // لذلك نستخدم finished_products للربط مع batch_number بناءً على product_id و batch_number
                            $pending = $db->queryOne("
                                SELECT COALESCE(SUM(oi.quantity), 0) AS pending_quantity
                                FROM customer_order_items oi
                                INNER JOIN customer_orders co ON oi.order_id = co.id
                                INNER JOIN finished_products fp2 ON fp2.product_id = oi.product_id AND fp2.batch_number = ?
                                WHERE co.status = 'pending'
                            ", [$fp['batch_number']]);
                            $pendingQty = (float)($pending['pending_quantity'] ?? 0);
                            
                            // حساب الكمية المحجوزة في طلبات الشحن المعلقة (in_transit)
                            $pendingShipping = $db->queryOne("
                                SELECT COALESCE(SUM(soi.quantity), 0) AS pending_quantity
                                FROM shipping_company_order_items soi
                                INNER JOIN shipping_company_orders sco ON soi.order_id = sco.id
                                WHERE sco.status = 'in_transit'
                                  AND soi.batch_id = ?
                            ", [$batchId]);
                            $pendingShippingQty = (float)($pendingShipping['pending_quantity'] ?? 0);
                        } catch (Throwable $calcError) {
                            error_log('shipping_orders: error calculating available quantity: ' . $calcError->getMessage());
                        }
                    }
                    
                    // حساب الكمية المتاحة
                    // ملاحظة: quantity_produced يتم تحديثه تلقائياً عند المبيعات وطلبات الشحن
                    // لذلك نحتاج فقط خصم طلبات العملاء المعلقة (pendingQty)
                    $availableQuantity = max(0, $quantity - $pendingQty);
                    
                    if ($availableQuantity < $requestedQuantity) {
                        throw new InvalidArgumentException('الكمية المتاحة للمنتج ' . ($fp['product_name'] ?? '') . ' غير كافية.');
                    }
                } else {
                    // للمنتجات الخارجية، التحقق من جدول products
                    $productRow = $db->queryOne(
                        "SELECT id, name, quantity FROM products WHERE id = ? FOR UPDATE",
                        [$productId]
                    );

                    if (!$productRow) {
                        throw new InvalidArgumentException('تعذر العثور على منتج من عناصر الطلب.');
                    }

                    $availableQuantity = (float)($productRow['quantity'] ?? 0);
                    if ($availableQuantity < $requestedQuantity) {
                        throw new InvalidArgumentException('الكمية المتاحة للمنتج ' . ($productRow['name'] ?? '') . ' غير كافية.');
                    }
                }
            }

            $invoiceId = null;
            if (!empty($normalizedItems)) {
            // ===== إعداد عناصر الفاتورة مع اسم المنتج الصحيح =====
            $invoiceItems = [];
            foreach ($normalizedItems as $normalizedItem) {
                $productId = $normalizedItem['product_id'];
                $productType = $normalizedItem['product_type'] ?? '';
                $batchId = $normalizedItem['batch_id'] ?? null;
                $correctProductId = $productId;
                
                $productName = '';
                $batchNumberForDisplay = '';
                
                if ($productType === 'factory' && $batchId) {
                    // ===== منتج مصنع - جلب جميع البيانات من finished_products =====
                    $fpData = $db->queryOne("
                        SELECT 
                            fp.id as finished_product_id,
                            fp.product_name as fp_product_name,
                            fp.batch_number as fp_batch_number,
                            fp.product_id as fp_product_id,
                            bn.id as batch_number_id,
                            bn.batch_number as bn_batch_number,
                            bn.product_id as bn_product_id,
                            pr1.name as pr1_name,
                            pr2.name as pr2_name
                        FROM finished_products fp
                        LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                        LEFT JOIN products pr1 ON fp.product_id = pr1.id
                        LEFT JOIN products pr2 ON bn.product_id = pr2.id
                        WHERE fp.id = ?
                        LIMIT 1
                    ", [$batchId]);
                    
                    if ($fpData) {
                        // 1. جلب batch_number
                        if (!empty($fpData['fp_batch_number'])) {
                            $batchNumberForDisplay = trim($fpData['fp_batch_number']);
                        } elseif (!empty($fpData['bn_batch_number'])) {
                            $batchNumberForDisplay = trim($fpData['bn_batch_number']);
                        }
                        
                        // 2. تحديد product_id الصحيح
                        if (!empty($fpData['fp_product_id']) && $fpData['fp_product_id'] > 0) {
                            $correctProductId = (int)$fpData['fp_product_id'];
                        } elseif (!empty($fpData['bn_product_id']) && $fpData['bn_product_id'] > 0) {
                            $correctProductId = (int)$fpData['bn_product_id'];
                        }
                        
                        // 3. تحديد اسم المنتج - الأولوية لأسماء products
                        $candidateNames = [];
                        
                        // اسم من products المرتبط بـ fp.product_id
                        if (!empty($fpData['pr1_name']) && trim($fpData['pr1_name']) !== '' 
                            && strpos($fpData['pr1_name'], 'منتج رقم') !== 0) {
                            $candidateNames[] = trim($fpData['pr1_name']);
                        }
                        
                        // اسم من products المرتبط بـ bn.product_id
                        if (!empty($fpData['pr2_name']) && trim($fpData['pr2_name']) !== '' 
                            && strpos($fpData['pr2_name'], 'منتج رقم') !== 0) {
                            $candidateNames[] = trim($fpData['pr2_name']);
                        }
                        
                        // اسم من finished_products
                        if (!empty($fpData['fp_product_name']) && trim($fpData['fp_product_name']) !== '' 
                            && strpos($fpData['fp_product_name'], 'منتج رقم') !== 0) {
                            $candidateNames[] = trim($fpData['fp_product_name']);
                        }
                        
                        // اختيار أول اسم صالح
                        if (!empty($candidateNames)) {
                            $productName = $candidateNames[0];
                        } else {
                            $productName = 'منتج رقم ' . $correctProductId;
                        }
                        
                        // إضافة رقم التشغيلة إلى اسم المنتج للعرض
                        if (!empty($batchNumberForDisplay)) {
                            $productName .= ' (' . $batchNumberForDisplay . ')';
                        }
                        
                        error_log("shipping_orders: Invoice item - product_name: $productName, batch_number: $batchNumberForDisplay, correct_product_id: $correctProductId");
                    } else {
                        // لم يُعثر على finished_product
                        $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                        $productName = $product['name'] ?? 'غير محدد';
                    }
                } else {
                    // ===== منتج خارجي =====
                    $productRow = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                    $productName = $productRow['name'] ?? 'غير محدد';
                }
                
                $invoiceItems[] = [
                    'product_id' => $correctProductId, // استخدام product_id الصحيح
                    'description' => $productName,
                    'quantity' => $normalizedItem['quantity'],
                    'unit_price' => $normalizedItem['unit_price'],
                ];
            }

            // التحقق من وجود العميل في جدول customers قبل إنشاء الفاتورة
            // لأن جدول invoices يحتوي على foreign key constraint يشير إلى customers
            $customerInCustomersTable = $db->queryOne(
                "SELECT id FROM customers WHERE id = ?",
                [$customerId]
            );
            
            if (!$customerInCustomersTable) {
                // جلب بيانات العميل من local_customers
                $localCustomerData = $db->queryOne(
                    "SELECT name, phone, address, balance, created_by FROM local_customers WHERE id = ?",
                    [$customerId]
                );
                
                if ($localCustomerData) {
                    // التحقق من وجود عمود rep_id في جدول customers
                    $hasRepIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'rep_id'"));
                    
                    // إنشاء سجل في جدول customers
                    if ($hasRepIdColumn) {
                        $db->execute(
                            "INSERT INTO customers (id, name, phone, address, balance, status, rep_id, created_by, created_at) 
                             VALUES (?, ?, ?, ?, ?, 'active', NULL, ?, NOW())",
                            [
                                $customerId,
                                $localCustomerData['name'] ?? '',
                                $localCustomerData['phone'] ?? null,
                                $localCustomerData['address'] ?? null,
                                $localCustomerData['balance'] ?? 0,
                                $localCustomerData['created_by'] ?? $currentUser['id'] ?? null,
                            ]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO customers (id, name, phone, address, balance, status, created_by, created_at) 
                             VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())",
                            [
                                $customerId,
                                $localCustomerData['name'] ?? '',
                                $localCustomerData['phone'] ?? null,
                                $localCustomerData['address'] ?? null,
                                $localCustomerData['balance'] ?? 0,
                                $localCustomerData['created_by'] ?? $currentUser['id'] ?? null,
                            ]
                        );
                    }
                } else {
                    throw new InvalidArgumentException('تعذر العثور على بيانات العميل.');
                }
            }

            $invoiceResult = createInvoice(
                $customerId,
                null,
                date('Y-m-d'),
                $invoiceItems,
                0,
                0,
                $notes,
                $currentUser['id'] ?? null
            );

            if (empty($invoiceResult['success'])) {
                throw new RuntimeException($invoiceResult['message'] ?? 'تعذر إنشاء الفاتورة الخاصة بالطلب.');
            }

            $invoiceId = (int)$invoiceResult['invoice_id'];
            $invoiceNumber = $invoiceResult['invoice_number'] ?? '';

            // التحقق من عدم تحديث فواتير نقطة البيع
            $hasCreatedFromPosColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'created_from_pos'"));
            if ($hasCreatedFromPosColumn) {
                $invoiceCheck = $db->queryOne("SELECT created_from_pos FROM invoices WHERE id = ?", [$invoiceId]);
                if (empty($invoiceCheck) || empty($invoiceCheck['created_from_pos'])) {
                    // ليست فاتورة من نقطة البيع، يمكن تحديثها
                    $db->execute(
                        "UPDATE invoices SET paid_amount = 0, remaining_amount = ?, status = 'sent', updated_at = NOW() WHERE id = ?",
                        [$totalAmount, $invoiceId]
                    );
                }
            } else {
                // العمود غير موجود، يمكن التحديث (للتوافق مع الإصدارات القديمة)
                $db->execute(
                    "UPDATE invoices SET paid_amount = 0, remaining_amount = ?, status = 'sent', updated_at = NOW() WHERE id = ?",
                    [$totalAmount, $invoiceId]
                );
            }

            // ربط أرقام التشغيلة بعناصر الفاتورة - مطابقة محسّنة لضمان الدقة
            $invoiceItemsFromDb = $db->query(
                "SELECT id, product_id, quantity, unit_price FROM invoice_items WHERE invoice_id = ? ORDER BY id",
                [$invoiceId]
            );
            
            // إنشاء خريطة محسّنة للمطابقة بين invoice_items و normalizedItems
            // المطابقة بناءً على product_id و quantity و unit_price لضمان الدقة
            $invoiceItemsMap = [];
            foreach ($invoiceItemsFromDb as $invItem) {
                $productId = (int)$invItem['product_id'];
                $quantity = (float)$invItem['quantity'];
                $unitPrice = (float)$invItem['unit_price'];
                $key = "{$productId}_{$quantity}_{$unitPrice}";
                
                if (!isset($invoiceItemsMap[$key])) {
                    $invoiceItemsMap[$key] = [];
                }
                $invoiceItemsMap[$key][] = (int)$invItem['id'];
            }
            
            // ربط أرقام التشغيلة بعناصر الفاتورة
            foreach ($normalizedItems as $normalizedItem) {
                $productId = (int)$normalizedItem['product_id'];
                $batchId = $normalizedItem['batch_id'] ?? null;
                $productType = $normalizedItem['product_type'] ?? '';
                $quantity = (float)$normalizedItem['quantity'];
                $unitPrice = (float)$normalizedItem['unit_price'];
                
                // البحث عن invoice_item_id المطابق بناءً على product_id و quantity و unit_price
                $matchKey = "{$productId}_{$quantity}_{$unitPrice}";
                $invoiceItemId = null;
                
                if (isset($invoiceItemsMap[$matchKey]) && !empty($invoiceItemsMap[$matchKey])) {
                    // استخدام أول invoice_item_id متطابق
                    $invoiceItemId = array_shift($invoiceItemsMap[$matchKey]);
                    if (empty($invoiceItemsMap[$matchKey])) {
                        unset($invoiceItemsMap[$matchKey]);
                    }
                } else {
                    // إذا لم نجد مطابقة دقيقة، نبحث عن أي invoice_item بنفس product_id
                    // (للتعامل مع حالات التقريب في الأسعار)
                    foreach ($invoiceItemsMap as $key => $items) {
                        $parts = explode('_', $key);
                        if (count($parts) >= 3 && (int)$parts[0] === $productId) {
                            // مطابقة product_id فقط
                            if (!empty($items)) {
                                $invoiceItemId = array_shift($items);
                                if (empty($items)) {
                                    unset($invoiceItemsMap[$key]);
                                } else {
                                    $invoiceItemsMap[$key] = $items;
                                }
                                break;
                            }
                        }
                    }
                }
                
                if ($invoiceItemId) {
                    // البحث عن batch_number_id من جدول batch_numbers
                    $batchNumberId = null;
                    if ($productType === 'factory' && $batchId) {
                        // جلب batch_number من finished_products
                        $fp = $db->queryOne("
                            SELECT fp.batch_number, fp.batch_id, fp.product_id, fp.product_name
                            FROM finished_products fp
                            WHERE fp.id = ?
                            LIMIT 1
                        ", [$batchId]);
                        
                        if ($fp) {
                            // محاولة جلب batch_number من finished_products.batch_number مباشرة
                            $batchNumber = null;
                            if (!empty($fp['batch_number'])) {
                                $batchNumber = trim($fp['batch_number']);
                            } elseif (!empty($fp['batch_id'])) {
                                // إذا لم يكن batch_number موجوداً، نحاول جلب batch_number من batch_numbers باستخدام batch_id
                                $batchFromTable = $db->queryOne(
                                    "SELECT batch_number FROM batch_numbers WHERE id = ?",
                                    [(int)$fp['batch_id']]
                                );
                                if ($batchFromTable && !empty($batchFromTable['batch_number'])) {
                                    $batchNumber = trim($batchFromTable['batch_number']);
                                }
                            }
                            
                            if ($batchNumber) {
                                // البحث عن batch_number_id من جدول batch_numbers
                                $batchCheck = $db->queryOne(
                                    "SELECT id FROM batch_numbers WHERE batch_number = ?",
                                    [$batchNumber]
                                );
                                if ($batchCheck) {
                                    $batchNumberId = (int)$batchCheck['id'];
                                } else {
                                    error_log("shipping_orders: WARNING - batch_number '$batchNumber' not found in batch_numbers table for batch_id=$batchId");
                                }
                            } else {
                                error_log("shipping_orders: WARNING - batch_number is empty for finished_products.id = $batchId");
                            }
                        } else {
                            error_log("shipping_orders: ERROR - finished_products not found with id = $batchId");
                        }
                    }
                    
                    // ربط رقم التشغيلة بعنصر الفاتورة إذا وُجد
                    if ($batchNumberId) {
                        try {
                            // التحقق من وجود سجل مسبقاً لتجنب التكرار
                            $existingBatchLink = $db->queryOne(
                                "SELECT id, quantity FROM sales_batch_numbers WHERE invoice_item_id = ? AND batch_number_id = ?",
                                [$invoiceItemId, $batchNumberId]
                            );
                            
                            if ($existingBatchLink) {
                                // إذا كان السجل موجوداً، نحدّث الكمية فقط إذا كانت مختلفة
                                if (abs((float)($existingBatchLink['quantity'] ?? 0) - $quantity) > 0.001) {
                                    $db->execute(
                                        "UPDATE sales_batch_numbers SET quantity = ? WHERE id = ?",
                                        [$quantity, $existingBatchLink['id']]
                                    );
                                    error_log("shipping_orders: Updated existing batch link - invoice_item_id=$invoiceItemId, batch_number_id=$batchNumberId, quantity=$quantity");
                                }
                            } else {
                                // إدراج سجل جديد
                                $db->execute(
                                    "INSERT INTO sales_batch_numbers (invoice_item_id, batch_number_id, quantity) 
                                     VALUES (?, ?, ?)",
                                    [$invoiceItemId, $batchNumberId, $quantity]
                                );
                                error_log("shipping_orders: Successfully linked batch - invoice_item_id=$invoiceItemId, batch_number_id=$batchNumberId, batch_number=$batchNumber, quantity=$quantity, product_id=$productId, batch_id=$batchId");
                            }
                        } catch (Throwable $batchError) {
                            error_log('shipping_orders: Error linking batch number to invoice item: ' . $batchError->getMessage());
                        }
                    } else {
                        error_log("shipping_orders: WARNING - batchNumberId is null for product_id: $productId, batchId: $batchId, productType: $productType");
                    }
                } else {
                    error_log("shipping_orders: WARNING - Could not find matching invoice_item for normalized item - product_id=$productId, quantity=$quantity, unit_price=$unitPrice, batch_id=$batchId");
                }
            }
            } // End if (!empty($normalizedItems))

            $orderNumber = generateShippingOrderNumber($db);

            $db->execute(
                "INSERT INTO shipping_company_orders (order_number, tg_number, shipping_company_id, customer_id, invoice_id, total_amount, status, handed_over_at, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, 'in_transit', NOW(), ?, ?)",
                [
                    $orderNumber,
                    $tgNumber !== '' ? $tgNumber : null,
                    $shippingCompanyId,
                    $customerId,
                    $invoiceId, // Can be NULL
                    $finalTotalAmount, // Use Custom Total or Calc Total
                    $notes !== '' ? $notes : null,
                    $currentUser['id'] ?? null,
                ]
            );

            $orderId = (int)$db->getLastInsertId();

            if (!empty($normalizedItems)) {
                // حفظ عناصر الطلب
                foreach ($normalizedItems as $normalizedItem) {
                    $db->execute(
                        "INSERT INTO shipping_company_order_items (order_id, product_id, batch_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            $orderId,
                            $normalizedItem['product_id'],
                            $normalizedItem['batch_id'] ?? null,
                            $normalizedItem['quantity'],
                            $normalizedItem['unit_price'],
                            $normalizedItem['total_price'],
                        ]
                    );
                }

                // خصم الكميات من المخزون عند إنشاء الطلب
                // ملاحظة: نستخدم recordInventoryMovement فقط لتجنب الخصم المزدوج
                $movementNote = 'تسليم طلب شحن #' . $orderNumber . ' لشركة الشحن';
                foreach ($normalizedItems as $normalizedItem) {
                    $productId = $normalizedItem['product_id'];
                    $batchId = $normalizedItem['batch_id'] ?? null;
                    $productType = $normalizedItem['product_type'] ?? '';
                    $quantity = (float)$normalizedItem['quantity'];

                    // للمنتجات التي لها رقم تشغيلة: قراءة products.quantity قبل recordInventoryMovement
                    $productsQuantityBeforeMovement = null;
                    $actualProductIdForTracking = null;
                    if ($productType === 'factory' && $batchId) {
                        try {
                            // جلب product_id الصحيح من finished_products
                            $fpDataForTracking = $db->queryOne("
                                SELECT 
                                    fp.product_id,
                                    bn.product_id AS batch_product_id,
                                    COALESCE(fp.product_id, bn.product_id) AS actual_product_id
                                FROM finished_products fp
                                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                                WHERE fp.id = ?
                            ", [$batchId]);
                            
                            if ($fpDataForTracking) {
                                $actualProductIdForTracking = (int)($fpDataForTracking['actual_product_id'] ?? $productId);
                                $productBeforeMovement = $db->queryOne(
                                    "SELECT quantity FROM products WHERE id = ?",
                                    [$actualProductIdForTracking]
                                );
                                if ($productBeforeMovement) {
                                    $productsQuantityBeforeMovement = (float)($productBeforeMovement['quantity'] ?? 0);
                                    error_log(sprintf(
                                        "shipping_orders: BEFORE recordInventoryMovement - Order: %s, Product ID: %d, Batch ID: %d, products.quantity: %.2f",
                                        $orderNumber,
                                        $actualProductIdForTracking,
                                        $batchId,
                                        $productsQuantityBeforeMovement
                                    ));
                                }
                            }
                        } catch (Throwable $trackingError) {
                            error_log(sprintf(
                                "shipping_orders: ERROR reading products.quantity before recordInventoryMovement - Order: %s, Error: %s",
                                $orderNumber,
                                $trackingError->getMessage()
                            ));
                        }
                    }

                    // تسجيل حركة المخزون (تقوم الدالة بالخصم تلقائياً)
                    recordInventoryMovement(
                        $productId,
                        $mainWarehouse['id'] ?? null,
                        'out',
                        $quantity,
                        'shipping_order',
                        $orderId,
                        $movementNote,
                        $currentUser['id'] ?? null,
                        ($productType === 'factory' && $batchId) ? $batchId : null
                    );

                    // للمنتجات التي لها رقم تشغيلة: قراءة products.quantity بعد recordInventoryMovement
                    if ($productType === 'factory' && $batchId && $actualProductIdForTracking) {
                        try {
                            $productAfterMovement = $db->queryOne(
                                "SELECT quantity FROM products WHERE id = ?",
                                [$actualProductIdForTracking]
                            );
                            if ($productAfterMovement) {
                                $productsQuantityAfterMovement = (float)($productAfterMovement['quantity'] ?? 0);
                                $difference = $productsQuantityBeforeMovement !== null 
                                    ? ($productsQuantityAfterMovement - $productsQuantityBeforeMovement) 
                                    : null;
                                error_log(sprintf(
                                    "shipping_orders: AFTER recordInventoryMovement - Order: %s, Product ID: %d, Batch ID: %d, products.quantity: %.2f, Difference: %s",
                                    $orderNumber,
                                    $actualProductIdForTracking,
                                    $batchId,
                                    $productsQuantityAfterMovement,
                                    $difference !== null ? sprintf("%.2f", $difference) : 'N/A'
                                ));
                            }
                        } catch (Throwable $trackingError) {
                            error_log(sprintf(
                                "shipping_orders: ERROR reading products.quantity after recordInventoryMovement - Order: %s, Error: %s",
                                $orderNumber,
                                $trackingError->getMessage()
                            ));
                        }
                    }

                    // للمنتجات التي لها رقم تشغيلة: إضافة الكمية إلى products.quantity
                    if ($productType === 'factory' && $batchId) {
                        try {
                            // جلب product_id الصحيح من finished_products (لأن batch_id هو finished_products.id وليس products.id)
                            $fpData = $db->queryOne("
                                SELECT 
                                    fp.product_id,
                                    bn.product_id AS batch_product_id,
                                    COALESCE(fp.product_id, bn.product_id) AS actual_product_id
                                FROM finished_products fp
                                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                                WHERE fp.id = ?
                            ", [$batchId]);
                            
                            if (!$fpData) {
                                error_log(sprintf(
                                    "shipping_orders: WARNING - finished_products not found for batch_id: %d, Order: %s",
                                    $batchId,
                                    $orderNumber
                                ));
                                continue;
                            }
                            
                            // استخدام product_id الصحيح من finished_products أو batch_numbers
                            $actualProductId = (int)($fpData['actual_product_id'] ?? $productId);
                            
                            // جلب الكمية الحالية من products قبل الإضافة
                            $currentProduct = $db->queryOne(
                                "SELECT quantity FROM products WHERE id = ?",
                                [$actualProductId]
                            );
                            
                            if (!$currentProduct) {
                                error_log(sprintf(
                                    "shipping_orders: WARNING - products not found for product_id: %d (from batch_id: %d), Order: %s",
                                    $actualProductId,
                                    $batchId,
                                    $orderNumber
                                ));
                                continue;
                            }
                            
                            $currentQuantity = (float)($currentProduct['quantity'] ?? 0);
                            
                            error_log(sprintf(
                                "shipping_orders: BEFORE adding quantity - Order: %s, Product ID: %d, Batch ID: %d, products.quantity: %.2f, Quantity to add: %.2f",
                                $orderNumber,
                                $actualProductId,
                                $batchId,
                                $currentQuantity,
                                $quantity
                            ));
                            
                            // إضافة الكمية المدخلة إلى products.quantity
                            $newQuantity = $currentQuantity + $quantity;
                            $db->execute(
                                "UPDATE products SET quantity = ? WHERE id = ?",
                                [$newQuantity, $actualProductId]
                            );
                            
                            // قراءة الكمية بعد الإضافة للتحقق
                            $productAfterAdd = $db->queryOne(
                                "SELECT quantity FROM products WHERE id = ?",
                                [$actualProductId]
                            );
                            $verifiedQuantity = $productAfterAdd ? (float)($productAfterAdd['quantity'] ?? 0) : $newQuantity;
                            
                            // تسجيل العملية في سجل الأخطاء
                            error_log(sprintf(
                                "shipping_orders: AFTER adding quantity - Order: %s, Finished Product ID (batch_id): %d, Actual Product ID: %d, Quantity Added: %.2f, Previous Quantity: %.2f, Calculated New Quantity: %.2f, Verified Quantity: %.2f",
                                $orderNumber,
                                $batchId,
                                $actualProductId,
                                $quantity,
                                $currentQuantity,
                                $newQuantity,
                                $verifiedQuantity
                            ));
                        } catch (Throwable $addQuantityError) {
                            // في حالة حدوث خطأ، نسجله فقط ولا نوقف العملية
                            error_log(sprintf(
                                "shipping_orders: ERROR adding quantity to products.quantity for product with batch_id - Order: %s, Batch ID: %d, Product ID: %d, Quantity: %.2f, Error: %s",
                                $orderNumber,
                                $batchId,
                                $productId,
                                $quantity,
                                $addQuantityError->getMessage()
                            ));
                        }
                    }
                }
            }

            $db->execute(
                "UPDATE shipping_companies SET balance = balance + ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$finalTotalAmount, $currentUser['id'] ?? null, $shippingCompanyId]
            );

            logAudit(
                $currentUser['id'] ?? null,
                'create_shipping_order',
                'shipping_order',
                $orderId,
                null,
                [
                    'order_number' => $orderNumber,
                    'total_amount' => $totalAmount,
                    'shipping_company_id' => $shippingCompanyId,
                    'customer_id' => $customerId,
                ]
            );

            $db->commit();
            $transactionStarted = false;

            $_SESSION[$sessionSuccessKey] = 'تم تسجيل طلب الشحن وتسليم المنتجات لشركة الشحن بنجاح.';
        } catch (InvalidArgumentException $validationError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            $_SESSION[$sessionErrorKey] = $validationError->getMessage();
        } catch (Throwable $createError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log('shipping_orders: create order error -> ' . $createError->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر إنشاء طلب الشحن. يرجى المحاولة لاحقاً.';
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'cancel_shipping_order') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $deductedAmount = isset($_POST['deducted_amount']) ? cleanFinancialValue($_POST['deducted_amount'], true) : null;

        if ($orderId <= 0) {
            $_SESSION[$sessionErrorKey] = 'طلب غير صالح للإلغاء.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        if ($deductedAmount === null || $deductedAmount < 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى إدخال مبلغ الشحن المخصوم (صفر أو أكثر).';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        $transactionStarted = false;

        try {
            $db->beginTransaction();
            $transactionStarted = true;

            $order = $db->queryOne(
                "SELECT id, shipping_company_id, customer_id, total_amount, status, invoice_id, order_number FROM shipping_company_orders WHERE id = ? FOR UPDATE",
                [$orderId]
            );

            if (!$order) {
                throw new InvalidArgumentException('طلب الشحن المحدد غير موجود.');
            }

            if ($order['status'] === 'cancelled') {
                throw new InvalidArgumentException('تم إلغاء هذا الطلب بالفعل.');
            }

            if ($order['status'] === 'delivered') {
                throw new InvalidArgumentException('لا يمكن إلغاء طلب تم تسليمه بالفعل.');
            }

            $deductedAmount = (float)$deductedAmount;

            // خصم المبلغ المدخل من ديون شركة الشحن
            $db->execute(
                "UPDATE shipping_companies SET balance = balance - ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$deductedAmount, $currentUser['id'] ?? null, $order['shipping_company_id']]
            );

            // إرجاع المنتجات إلى المخزن الرئيسي
            // ملاحظة: نستخدم recordInventoryMovement فقط لتجنب الإرجاع المزدوج
            $orderItems = $db->query(
                "SELECT product_id, batch_id, quantity FROM shipping_company_order_items WHERE order_id = ?",
                [$orderId]
            );

            $movementNote = 'إرجاع منتجات من طلب شحن ملغي #' . ($order['order_number'] ?? $orderId);
            
            foreach ($orderItems as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $batchId = isset($item['batch_id']) && $item['batch_id'] > 0 ? (int)$item['batch_id'] : null;
                $quantity = (float)($item['quantity'] ?? 0);
                
                // للمنتجات التي لها رقم تشغيلة: جلب actual_product_id الصحيح
                $actualProductId = $productId;
                if ($batchId) {
                    try {
                        $fpData = $db->queryOne("
                            SELECT 
                                fp.id,
                                fp.quantity_produced,
                                fp.product_id,
                                bn.product_id AS batch_product_id,
                                COALESCE(fp.product_id, bn.product_id) AS actual_product_id
                            FROM finished_products fp
                            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                            WHERE fp.id = ?
                        ", [$batchId]);
                        
                        if ($fpData) {
                            $actualProductId = (int)($fpData['actual_product_id'] ?? $productId);
                        }
                    } catch (Throwable $fpError) {
                        error_log(sprintf(
                            "shipping_orders: ERROR getting actual_product_id for batch_id (cancel) - Order: %s, Batch ID: %d, Error: %s",
                            $order['order_number'] ?? $orderId,
                            $batchId,
                            $fpError->getMessage()
                        ));
                    }
                }
                
                // تسجيل حركة المخزون (تقوم الدالة بالإرجاع تلقائياً)
                // ملاحظة: للمنتجات التي لها batchId، recordInventoryMovement يحدث products.quantity فقط
                // ولا يحدث finished_products.quantity_produced عند type = 'in'
                recordInventoryMovement(
                    $actualProductId,
                    $mainWarehouse['id'] ?? null,
                    'in',
                    $quantity,
                    'shipping_order_cancelled',
                    $orderId,
                    $movementNote,
                    $currentUser['id'] ?? null,
                    $batchId
                );

                // للمنتجات التي لها رقم تشغيلة: إرجاع الكمية إلى finished_products.quantity_produced
                // (products.quantity تم تحديثه بالفعل بواسطة recordInventoryMovement)
                if ($batchId) {
                    try {
                        // جلب بيانات finished_products (إذا لم نكن قد جلبناها بالفعل)
                        if (!isset($fpData)) {
                            $fpData = $db->queryOne("
                                SELECT 
                                    fp.id,
                                    fp.quantity_produced,
                                    fp.product_id,
                                    bn.product_id AS batch_product_id,
                                    COALESCE(fp.product_id, bn.product_id) AS actual_product_id
                                FROM finished_products fp
                                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                                WHERE fp.id = ?
                            ", [$batchId]);
                        }
                        
                        if (!$fpData) {
                            error_log(sprintf(
                                "shipping_orders: WARNING - finished_products not found for batch_id: %d, Order: %s",
                                $batchId,
                                $order['order_number'] ?? $orderId
                            ));
                            continue;
                        }
                        
                        $currentQuantityProduced = (float)($fpData['quantity_produced'] ?? 0);
                        
                        // إرجاع الكمية إلى finished_products.quantity_produced
                        $newQuantityProduced = $currentQuantityProduced + $quantity;
                        $db->execute(
                            "UPDATE finished_products SET quantity_produced = ? WHERE id = ?",
                            [$newQuantityProduced, $batchId]
                        );
                        
                        error_log(sprintf(
                            "shipping_orders: Updated finished_products.quantity_produced (cancel) - Order: %s, Batch ID: %d, Previous: %.2f, Added: %.2f, New: %.2f",
                            $order['order_number'] ?? $orderId,
                            $batchId,
                            $currentQuantityProduced,
                            $quantity,
                            $newQuantityProduced
                        ));
                    } catch (Throwable $addQuantityError) {
                        // في حالة حدوث خطأ، نسجله فقط ولا نوقف العملية
                        error_log(sprintf(
                            "shipping_orders: ERROR updating finished_products.quantity_produced for batch_id (cancel) - Order: %s, Batch ID: %d, Quantity: %.2f, Error: %s",
                            $order['order_number'] ?? $orderId,
                            $batchId,
                            $quantity,
                            $addQuantityError->getMessage()
                        ));
                    }
                }
            }

            // تحديث حالة الطلب إلى ملغي
            $db->execute(
                "UPDATE shipping_company_orders SET status = 'cancelled', updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$currentUser['id'] ?? null, $orderId]
            );

            // إلغاء الفاتورة إذا كانت موجودة
            if (!empty($order['invoice_id'])) {
                // التحقق من عدم تحديث فواتير نقطة البيع
                $hasCreatedFromPosColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'created_from_pos'"));
                if ($hasCreatedFromPosColumn) {
                    $invoiceCheck = $db->queryOne("SELECT created_from_pos FROM invoices WHERE id = ?", [$order['invoice_id']]);
                    if (empty($invoiceCheck) || empty($invoiceCheck['created_from_pos'])) {
                        // ليست فاتورة من نقطة البيع، يمكن تحديثها
                        $db->execute(
                            "UPDATE invoices SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
                            [$order['invoice_id']]
                        );
                    }
                } else {
                    // العمود غير موجود، يمكن التحديث (للتوافق مع الإصدارات القديمة)
                    $db->execute(
                        "UPDATE invoices SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
                        [$order['invoice_id']]
                    );
                }
            }

            logAudit(
                $currentUser['id'] ?? null,
                'cancel_shipping_order',
                'shipping_order',
                $orderId,
                null,
                [
                    'deducted_amount' => $deductedAmount,
                    'shipping_company_id' => $order['shipping_company_id'],
                ]
            );

            $db->commit();
            $transactionStarted = false;

            $_SESSION[$sessionSuccessKey] = 'تم إلغاء الطلب وخصم مبلغ ' . number_format($deductedAmount, 2) . ' من ديون شركة الشحن وإرجاع المنتجات إلى المخزن الرئيسي بنجاح.';
        } catch (InvalidArgumentException $validationError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            $_SESSION[$sessionErrorKey] = $validationError->getMessage();
        } catch (Throwable $cancelError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log('shipping_orders: cancel order error -> ' . $cancelError->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر إلغاء الطلب. يرجى المحاولة لاحقاً.';
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'complete_shipping_order') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

        if ($orderId <= 0) {
            $_SESSION[$sessionErrorKey] = 'طلب غير صالح لإتمام التسليم.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'طلب غير صالح لإتمام التسليم.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        $transactionStarted = false;

        try {
            $db->beginTransaction();
            $transactionStarted = true;

            $order = $db->queryOne(
                "SELECT id, order_number, shipping_company_id, customer_id, total_amount, status, invoice_id FROM shipping_company_orders WHERE id = ? FOR UPDATE",
                [$orderId]
            );

            if (!$order) {
                throw new InvalidArgumentException('طلب الشحن المحدد غير موجود.');
            }

            if ($order['status'] === 'delivered') {
                throw new InvalidArgumentException('تم تسليم هذا الطلب بالفعل.');
            }

            if ($order['status'] === 'cancelled') {
                throw new InvalidArgumentException('لا يمكن إتمام طلب ملغى.');
            }

            $shippingCompany = $db->queryOne(
                "SELECT id, balance FROM shipping_companies WHERE id = ? FOR UPDATE",
                [$order['shipping_company_id']]
            );

            if (!$shippingCompany) {
                throw new InvalidArgumentException('شركة الشحن المرتبطة بالطلب غير موجودة.');
            }

            // البحث عن العميل في جدول local_customers أولاً (العملاء المحليين)
            $customer = null;
            $customerTable = null;
            $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
            
            if (!empty($localCustomersTableExists)) {
                $customer = $db->queryOne(
                    "SELECT id, name, phone, address, balance, status FROM local_customers WHERE id = ? FOR UPDATE",
                    [$order['customer_id']]
                );
                if ($customer) {
                    $customerTable = 'local_customers';
                }
            }

            // إذا لم نجد العميل في local_customers، نبحث في customers (للطلبات القديمة)
            if (!$customer) {
                $customersTableExists = $db->queryOne("SHOW TABLES LIKE 'customers'");
                if (!empty($customersTableExists)) {
                    $customer = $db->queryOne(
                        "SELECT id, name, phone, address, balance, status FROM customers WHERE id = ? FOR UPDATE",
                        [$order['customer_id']]
                    );
                    if ($customer) {
                        $customerTable = 'customers';
                    }
                }
            }

            if (!$customer || !$customerTable) {
                error_log('Complete shipping order: Customer not found - customer_id: ' . ($order['customer_id'] ?? 'null') . ', order_id: ' . $orderId);
                throw new InvalidArgumentException('تعذر العثور على العميل المرتبط بالطلب. قد يكون العميل قد تم حذفه.');
            }

            $totalAmount = (float)($order['total_amount'] ?? 0.0);
            
            // الحصول على المبلغ المحصل من العميل (إن وجد)
            $collectedAmount = isset($_POST['collected_amount']) ? (float)$_POST['collected_amount'] : 0.0;
            if ($collectedAmount < 0) {
                $collectedAmount = 0.0;
            }
            if ($collectedAmount > $totalAmount) {
                throw new InvalidArgumentException('المبلغ المحصل لا يمكن أن يكون أكبر من المبلغ الإجمالي للطلب.');
            }

            // الخطوة 1: خصم المبلغ الإجمالي من دين الشركة الحالي
            // عند التسليم، يتم خصم المبلغ الإجمالي من دين الشركة لأن الطلب تم تسليمه للعميل
            $db->execute(
                "UPDATE shipping_companies SET balance = balance - ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$totalAmount, $currentUser['id'] ?? null, $order['shipping_company_id']]
            );

            // الخطوة 2: إضافة المبلغ الإجمالي إلى ديون العميل
            // الخطوة 3: خصم المبلغ الذي تم تحصيله من العميل كتحصيل
            // المتبقي في ديون العميل = المبلغ الإجمالي - المبلغ الذي تم تحصيله
            // الصيغة: الرصيد الجديد = الرصيد الحالي + المبلغ الإجمالي - المبلغ المحصل
            $currentBalance = (float)($customer['balance'] ?? 0.0);
            $newBalance = round($currentBalance + $totalAmount - $collectedAmount, 2);
            
            $db->execute(
                "UPDATE {$customerTable} SET balance = ?, updated_at = NOW() WHERE id = ?",
                [$newBalance, $order['customer_id']]
            );
            
            // الخطوة 4: تسجيل عملية التحصيل في accountant_transactions إذا تم تحصيل مبلغ من العميل
            // هذا يسجل المبلغ المحصل كتحصيل في خزنة الشركة
            if ($collectedAmount > 0) {
                $accountantTableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                if (!empty($accountantTableCheck)) {
                    $customerName = $customer['name'] ?? 'غير محدد';
                    $description = 'تحصيل من عميل: ' . $customerName . ' (طلب شحن #' . ($order['order_number'] ?? $orderId) . ')';
                    $referenceNumber = 'COL-CUST-' . $order['customer_id'] . '-' . date('YmdHis');
                    
                    $db->execute(
                        "INSERT INTO accountant_transactions 
                            (transaction_type, amount, sales_rep_id, description, reference_number, 
                             status, approved_by, created_by, approved_at)
                         VALUES (?, ?, NULL, ?, ?, 'approved', ?, ?, NOW())",
                        [
                            'income',  // نوع المعاملة: إيراد (تحصيل من عميل وليس من مندوب)
                            $collectedAmount,
                            $description,
                            $referenceNumber,
                            $currentUser['id'],
                            $currentUser['id']
                        ]
                    );
                }
            }

            $db->execute(
                "UPDATE shipping_company_orders SET status = 'delivered', delivered_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$currentUser['id'] ?? null, $orderId]
            );

            // ربط أرقام التشغيلة بعناصر الفاتورة في sales_batch_numbers (لضمان ظهورها في سجل مشتريات العميل)
            // هذا الحل الجذري يضمن الربط الصحيح حتى لو فشل عند إنشاء الطلب
            if (!empty($order['invoice_id'])) {
                try {
                    $invoiceId = (int)$order['invoice_id'];
                    
                    // جلب عناصر الفاتورة مع جميع البيانات المطلوبة للمطابقة الدقيقة
                    $invoiceItemsFromDb = $db->query(
                        "SELECT id, product_id, quantity, unit_price FROM invoice_items WHERE invoice_id = ? ORDER BY id",
                        [$invoiceId]
                    );
                    
                    // جلب عناصر طلب الشحن مع batch_id و unit_price للمطابقة الدقيقة
                    $orderItemsWithBatch = $db->query(
                        "SELECT product_id, batch_id, quantity, unit_price FROM shipping_company_order_items WHERE order_id = ? ORDER BY id",
                        [$orderId]
                    );
                    
                    // إنشاء خريطة محسّنة للمطابقة بين invoice_items و orderItems
                    // المطابقة بناءً على product_id و quantity و unit_price لضمان الدقة
                    $invoiceItemsMap = [];
                    foreach ($invoiceItemsFromDb as $invItem) {
                        $productId = (int)$invItem['product_id'];
                        $quantity = (float)$invItem['quantity'];
                        $unitPrice = (float)$invItem['unit_price'];
                        $key = "{$productId}_{$quantity}_{$unitPrice}";
                        
                        if (!isset($invoiceItemsMap[$key])) {
                            $invoiceItemsMap[$key] = [];
                        }
                        $invoiceItemsMap[$key][] = (int)$invItem['id'];
                    }
                    
                    // ربط أرقام التشغيلة بعناصر الفاتورة
                    foreach ($orderItemsWithBatch as $orderItem) {
                        $productId = (int)$orderItem['product_id'];
                        $batchId = !empty($orderItem['batch_id']) ? (int)$orderItem['batch_id'] : null;
                        $quantity = (float)$orderItem['quantity'];
                        $unitPrice = (float)$orderItem['unit_price'];
                        
                        if ($batchId) {
                            // البحث عن invoice_item_id المطابق بناءً على product_id و quantity و unit_price
                            $matchKey = "{$productId}_{$quantity}_{$unitPrice}";
                            $invoiceItemId = null;
                            
                            if (isset($invoiceItemsMap[$matchKey]) && !empty($invoiceItemsMap[$matchKey])) {
                                // استخدام أول invoice_item_id متطابق
                                $invoiceItemId = array_shift($invoiceItemsMap[$matchKey]);
                            } else {
                                // إذا لم نجد مطابقة دقيقة، نبحث عن أي invoice_item بنفس product_id
                                // (للتعامل مع حالات التقريب في الأسعار)
                                foreach ($invoiceItemsMap as $key => $items) {
                                    $parts = explode('_', $key);
                                    if (count($parts) >= 3 && (int)$parts[0] === $productId) {
                                        // مطابقة product_id فقط
                                        if (!empty($items)) {
                                            $invoiceItemId = array_shift($items);
                                            if (empty($items)) {
                                                unset($invoiceItemsMap[$key]);
                                            } else {
                                                $invoiceItemsMap[$key] = $items;
                                            }
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            if ($invoiceItemId) {
                                // جلب batch_number من finished_products
                                $fp = $db->queryOne("
                                    SELECT fp.batch_number, fp.batch_id, fp.product_id, fp.product_name
                                    FROM finished_products fp
                                    WHERE fp.id = ?
                                    LIMIT 1
                                ", [$batchId]);
                                
                                if ($fp) {
                                    $batchNumber = null;
                                    if (!empty($fp['batch_number'])) {
                                        $batchNumber = trim($fp['batch_number']);
                                    } elseif (!empty($fp['batch_id'])) {
                                        // إذا لم يكن batch_number موجوداً، نحاول جلب batch_number من batch_numbers
                                        $batchFromTable = $db->queryOne(
                                            "SELECT batch_number FROM batch_numbers WHERE id = ?",
                                            [(int)$fp['batch_id']]
                                        );
                                        if ($batchFromTable && !empty($batchFromTable['batch_number'])) {
                                            $batchNumber = trim($batchFromTable['batch_number']);
                                        }
                                    }
                                    
                                    if ($batchNumber) {
                                        // البحث عن batch_number_id من جدول batch_numbers
                                        $batchCheck = $db->queryOne(
                                            "SELECT id FROM batch_numbers WHERE batch_number = ?",
                                            [$batchNumber]
                                        );
                                        if ($batchCheck) {
                                            $batchNumberId = (int)$batchCheck['id'];
                                            
                                            // التحقق من وجود سجل مسبقاً لتجنب التكرار
                                            $existingBatchLink = $db->queryOne(
                                                "SELECT id, quantity FROM sales_batch_numbers WHERE invoice_item_id = ? AND batch_number_id = ?",
                                                [$invoiceItemId, $batchNumberId]
                                            );
                                            
                                            if ($existingBatchLink) {
                                                // إذا كان السجل موجوداً، نحدّث الكمية فقط إذا كانت مختلفة
                                                if (abs((float)($existingBatchLink['quantity'] ?? 0) - $quantity) > 0.001) {
                                                    $db->execute(
                                                        "UPDATE sales_batch_numbers SET quantity = ? WHERE id = ?",
                                                        [$quantity, $existingBatchLink['id']]
                                                    );
                                                    error_log("shipping_orders: Updated batch link on delivery - invoice_item_id=$invoiceItemId, batch_number_id=$batchNumberId, batch_number=$batchNumber, quantity=$quantity");
                                                }
                                            } else {
                                                // إدراج سجل جديد
                                                $db->execute(
                                                    "INSERT INTO sales_batch_numbers (invoice_item_id, batch_number_id, quantity) 
                                                     VALUES (?, ?, ?)",
                                                    [$invoiceItemId, $batchNumberId, $quantity]
                                                );
                                                error_log("shipping_orders: Linked batch on delivery - invoice_item_id=$invoiceItemId, batch_number_id=$batchNumberId, batch_number=$batchNumber, quantity=$quantity, product_id=$productId, batch_id=$batchId");
                                                
                                                // التحقق من أن الربط تم بشكل صحيح
                                                $verifyLink = $db->queryOne(
                                                    "SELECT sbn.id, sbn.invoice_item_id, sbn.batch_number_id, bn.batch_number, 
                                                            fp.product_name, pr.name as product_name_from_products
                                                     FROM sales_batch_numbers sbn
                                                     INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                                                     LEFT JOIN finished_products fp ON fp.batch_number = bn.batch_number
                                                     LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                                                     WHERE sbn.invoice_item_id = ? AND sbn.batch_number_id = ?
                                                     LIMIT 1",
                                                    [$invoiceItemId, $batchNumberId]
                                                );
                                                if ($verifyLink) {
                                                    error_log("shipping_orders: VERIFIED link - invoice_item_id=$invoiceItemId, batch_number=" . ($verifyLink['batch_number'] ?? 'N/A') . ", product_name=" . ($verifyLink['product_name'] ?? $verifyLink['product_name_from_products'] ?? 'N/A'));
                                                } else {
                                                    error_log("shipping_orders: WARNING - Could not verify link for invoice_item_id=$invoiceItemId, batch_number_id=$batchNumberId");
                                                }
                                            }
                                        } else {
                                            error_log("shipping_orders: WARNING - batch_number '$batchNumber' not found in batch_numbers table when completing delivery for batch_id=$batchId");
                                        }
                                    } else {
                                        error_log("shipping_orders: WARNING - batch_number is empty for finished_products.id = $batchId when completing delivery");
                                    }
                                } else {
                                    error_log("shipping_orders: ERROR - finished_products not found with id = $batchId when completing delivery");
                                }
                            } else {
                                error_log("shipping_orders: WARNING - Could not find matching invoice_item for order item - product_id=$productId, quantity=$quantity, unit_price=$unitPrice, batch_id=$batchId");
                            }
                        }
                    }
                } catch (Throwable $batchLinkError) {
                    error_log('shipping_orders: Error linking batch numbers to invoice items on delivery completion: ' . $batchLinkError->getMessage());
                    error_log('shipping_orders: batch link error trace: ' . $batchLinkError->getTraceAsString());
                    // لا نوقف العملية إذا فشل ربط أرقام التشغيلة
                }
            } else {
                error_log("shipping_orders: WARNING - invoice_id is empty for order_id=$orderId when completing delivery");
            }

            // تحديد customer_id للاستخدام في جدول sales والمزامنة (يجب أن يكون متاحاً خارج try-catch)
            $salesCustomerId = null;
            
            // إضافة المنتجات إلى جدول sales (سجل مشتريات العميل)
            try {
                // جلب منتجات الطلب
                $orderItems = $db->query(
                    "SELECT product_id, batch_id, quantity, unit_price, total_price FROM shipping_company_order_items WHERE order_id = ?",
                    [$orderId]
                );

                if (!empty($orderItems)) {
                    // تحديد customer_id للاستخدام في جدول sales
                    $salesCustomerId = $order['customer_id'];
                    
                    // إذا كان العميل في local_customers، يجب البحث عن أو إنشاء عميل مؤقت في customers
                    // لأن جدول sales له foreign key على customers
                    if ($customerTable === 'local_customers') {
                        $customerName = $customer['name'] ?? '';
                        $customerPhone = $customer['phone'] ?? null;
                        $customerAddress = $customer['address'] ?? null;
                        
                        // البحث عن عميل في customers بنفس الاسم
                        $existingCustomerInCustomers = $db->queryOne(
                            "SELECT id FROM customers WHERE name = ? AND created_by_admin = 1 LIMIT 1",
                            [$customerName]
                        );
                        
                        if ($existingCustomerInCustomers) {
                            $salesCustomerId = (int)$existingCustomerInCustomers['id'];
                        } else {
                            // إنشاء عميل مؤقت في customers للربط
                            $db->execute(
                                "INSERT INTO customers (name, phone, address, balance, status, created_by, rep_id, created_from_pos, created_by_admin) 
                                 VALUES (?, ?, ?, 0, 'active', ?, NULL, 1, 1)",
                                [
                                    $customerName,
                                    $customerPhone,
                                    $customerAddress,
                                    $currentUser['id'] ?? null,
                                ]
                            );
                            $salesCustomerId = (int) $db->getLastInsertId();
                        }
                    }
                    
                    // تاريخ البيع (تاريخ التسليم)
                    $saleDate = date('Y-m-d');
                    
                    // التحقق من وجود عمود batch_id في جدول sales
                    $hasBatchIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM sales LIKE 'batch_id'"));
                    
                    // إضافة كل منتج إلى جدول sales
                    foreach ($orderItems as $item) {
                        $productId = (int)($item['product_id'] ?? 0);
                        $batchId = !empty($item['batch_id']) ? (int)$item['batch_id'] : null;
                        $quantity = (float)($item['quantity'] ?? 0);
                        $unitPrice = (float)($item['unit_price'] ?? 0);
                        $totalPrice = (float)($item['total_price'] ?? 0);
                        
                        if ($productId > 0 && $quantity > 0) {
                            if ($hasBatchIdColumn && $batchId) {
                                // إضافة batch_id إذا كان العمود موجوداً وكان batch_id متوفراً
                                $db->execute(
                                    "INSERT INTO sales (customer_id, product_id, batch_id, quantity, price, total, date, salesperson_id, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed')",
                                    [$salesCustomerId, $productId, $batchId, $quantity, $unitPrice, $totalPrice, $saleDate, $currentUser['id'] ?? null]
                                );
                                
                                error_log(sprintf(
                                    'shipping_orders: Added sale record with batch_id - customer_id=%d, product_id=%d, batch_id=%d, quantity=%.2f, total=%.2f, order_id=%d',
                                    $salesCustomerId,
                                    $productId,
                                    $batchId,
                                    $quantity,
                                    $totalPrice,
                                    $orderId
                                ));
                            } else {
                                // إضافة بدون batch_id (للتوافق مع الإصدارات القديمة)
                                $db->execute(
                                    "INSERT INTO sales (customer_id, product_id, quantity, price, total, date, salesperson_id, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')",
                                    [$salesCustomerId, $productId, $quantity, $unitPrice, $totalPrice, $saleDate, $currentUser['id'] ?? null]
                                );
                                
                                error_log(sprintf(
                                    'shipping_orders: Added sale record - customer_id=%d, product_id=%d, quantity=%.2f, total=%.2f, order_id=%d%s',
                                    $salesCustomerId,
                                    $productId,
                                    $quantity,
                                    $totalPrice,
                                    $orderId,
                                    $batchId ? " (batch_id=$batchId not added - column not exists)" : ''
                                ));
                            }
                        }
                    }
                    
                    error_log(sprintf(
                        'shipping_orders: Added %d product(s) to sales table for order_id=%d, customer_id=%d',
                        count($orderItems),
                        $orderId,
                        $salesCustomerId
                    ));
                }
            } catch (Throwable $salesError) {
                error_log('shipping_orders: failed adding products to sales table -> ' . $salesError->getMessage());
                error_log('shipping_orders: sales error trace -> ' . $salesError->getTraceAsString());
                // لا نوقف العملية إذا فشل إضافة المنتجات إلى جدول sales
            }

            // إنشاء فاتورة محلية للعميل المحلي (حتى تظهر المشتريات في سجل العميل المحلي)
            if ($customerTable === 'local_customers') {
                try {
                    // جلب منتجات الطلب
                    $orderItems = $db->query(
                        "SELECT product_id, batch_id, quantity, unit_price, total_price FROM shipping_company_order_items WHERE order_id = ?",
                        [$orderId]
                    );
                    
                    if (empty($orderItems)) {
                        throw new RuntimeException('لا توجد منتجات في الطلب');
                    }
                    // التأكد من وجود جدول local_invoices وإنشاؤه إذا لم يكن موجوداً
                    $localInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
                    if (empty($localInvoicesTableExists)) {
                        // إنشاء جدول local_invoices
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `local_invoices` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `invoice_number` varchar(50) NOT NULL,
                              `customer_id` int(11) NOT NULL,
                              `date` date NOT NULL,
                              `due_date` date DEFAULT NULL,
                              `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
                              `tax_rate` decimal(5,2) DEFAULT 0.00,
                              `tax_amount` decimal(15,2) DEFAULT 0.00,
                              `discount_amount` decimal(15,2) DEFAULT 0.00,
                              `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                              `paid_amount` decimal(15,2) DEFAULT 0.00,
                              `remaining_amount` decimal(15,2) DEFAULT 0.00,
                              `status` enum('draft','sent','paid','partial','overdue','cancelled') DEFAULT 'draft',
                              `notes` text DEFAULT NULL,
                              `created_by` int(11) NOT NULL,
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              UNIQUE KEY `invoice_number` (`invoice_number`),
                              KEY `customer_id` (`customer_id`),
                              KEY `date` (`date`),
                              KEY `status` (`status`),
                              KEY `created_by` (`created_by`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    } else {
                        // التأكد من وجود جميع الأعمدة المطلوبة وإضافتها إذا لم تكن موجودة
                        $requiredColumns = [
                            'due_date' => "ALTER TABLE local_invoices ADD COLUMN `due_date` date DEFAULT NULL AFTER `date`",
                            'subtotal' => "ALTER TABLE local_invoices ADD COLUMN `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `due_date`",
                            'tax_rate' => "ALTER TABLE local_invoices ADD COLUMN `tax_rate` decimal(5,2) DEFAULT 0.00 AFTER `subtotal`",
                            'tax_amount' => "ALTER TABLE local_invoices ADD COLUMN `tax_amount` decimal(15,2) DEFAULT 0.00 AFTER `tax_rate`",
                            'discount_amount' => "ALTER TABLE local_invoices ADD COLUMN `discount_amount` decimal(15,2) DEFAULT 0.00 AFTER `tax_amount`",
                            'total_amount' => "ALTER TABLE local_invoices ADD COLUMN `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`",
                            'paid_amount' => "ALTER TABLE local_invoices ADD COLUMN `paid_amount` decimal(15,2) DEFAULT 0.00 AFTER `total_amount`",
                            'remaining_amount' => "ALTER TABLE local_invoices ADD COLUMN `remaining_amount` decimal(15,2) DEFAULT 0.00 AFTER `paid_amount`",
                            'status' => "ALTER TABLE local_invoices ADD COLUMN `status` enum('draft','sent','paid','partial','overdue','cancelled') DEFAULT 'draft' AFTER `remaining_amount`",
                            'notes' => "ALTER TABLE local_invoices ADD COLUMN `notes` text DEFAULT NULL AFTER `status`",
                            'created_by' => "ALTER TABLE local_invoices ADD COLUMN `created_by` int(11) NOT NULL AFTER `notes`",
                            'created_at' => "ALTER TABLE local_invoices ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`",
                            'updated_at' => "ALTER TABLE local_invoices ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"
                        ];
                        
                        foreach ($requiredColumns as $columnName => $alterSql) {
                            $columnExists = !empty($db->queryOne("SHOW COLUMNS FROM local_invoices LIKE '$columnName'"));
                            if (!$columnExists) {
                                try {
                                    $db->execute($alterSql);
                                    error_log("Added column $columnName to local_invoices table");
                                } catch (Throwable $alterError) {
                                    error_log("Error adding column $columnName to local_invoices: " . $alterError->getMessage());
                                }
                            }
                        }
                    }
                    
                    // التأكد من وجود جدول local_invoice_items وإنشاؤه إذا لم يكن موجوداً
                    $localInvoiceItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoice_items'");
                    if (empty($localInvoiceItemsTableExists)) {
                        // إنشاء جدول local_invoice_items مع الأعمدة المطلوبة
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `local_invoice_items` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `invoice_id` int(11) NOT NULL,
                              `product_id` int(11) NOT NULL,
                              `description` varchar(255) DEFAULT NULL,
                              `quantity` decimal(10,2) NOT NULL,
                              `unit_price` decimal(15,2) NOT NULL,
                              `total_price` decimal(15,2) NOT NULL,
                              `batch_number` varchar(100) DEFAULT NULL,
                              `batch_id` int(11) DEFAULT NULL,
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              KEY `invoice_id` (`invoice_id`),
                              KEY `product_id` (`product_id`),
                              KEY `batch_id` (`batch_id`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                        error_log("shipping_orders: Created local_invoice_items table with batch_number and batch_id columns");
                    } else {
                        // التأكد من وجود الأعمدة المطلوبة وإضافتها إذا لم تكن موجودة
                        $requiredColumns = [
                            'batch_number' => "ALTER TABLE local_invoice_items ADD COLUMN `batch_number` varchar(100) DEFAULT NULL AFTER `total_price`",
                            'batch_id' => "ALTER TABLE local_invoice_items ADD COLUMN `batch_id` int(11) DEFAULT NULL AFTER `batch_number`"
                        ];
                        
                        foreach ($requiredColumns as $columnName => $alterSql) {
                            $columnExists = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE '$columnName'"));
                            if (!$columnExists) {
                                try {
                                    $db->execute($alterSql);
                                    // إضافة index لـ batch_id إذا لم يكن موجوداً
                                    if ($columnName === 'batch_id') {
                                        try {
                                            $indexExists = !empty($db->queryOne("SHOW INDEX FROM local_invoice_items WHERE Key_name = 'batch_id'"));
                                            if (!$indexExists) {
                                                $db->execute("ALTER TABLE local_invoice_items ADD KEY `batch_id` (`batch_id`)");
                                            }
                                        } catch (Throwable $indexError) {
                                            error_log("shipping_orders: Error adding index for batch_id: " . $indexError->getMessage());
                                        }
                                    }
                                    error_log("shipping_orders: Added column $columnName to local_invoice_items table");
                                } catch (Throwable $alterError) {
                                    error_log("shipping_orders: Error adding column $columnName to local_invoice_items: " . $alterError->getMessage());
                                }
                            }
                        }
                    }
                    
                    // إنشاء الفاتورة المحلية
                    $orderNumber = $order['order_number'] ?? 'ORD-' . $orderId;
                    $localInvoiceNumber = 'LOC-' . $orderNumber;
                    
                    // فحص إذا كانت الفاتورة المحلية موجودة مسبقاً
                    $existingLocalInvoice = $db->queryOne(
                        "SELECT id FROM local_invoices WHERE invoice_number = ? LIMIT 1",
                        [$localInvoiceNumber]
                    );
                    
                    if (empty($existingLocalInvoice)) {
                        // إنشاء الفاتورة المحلية
                        $saleDate = date('Y-m-d');
                        $localCustomerId = $order['customer_id'];
                        $subtotal = $totalAmount; // المبلغ الإجمالي هو نفسه subtotal في حالة طلبات الشحن
                        $dueDate = null; // لا يوجد تاريخ استحقاق لطلبات الشحن
                        $notes = 'طلب شحن - ' . $orderNumber;
                        
                        // بعد التأكد من وجود جميع الأعمدة، نستخدم نفس استعلام INSERT
                        $hasDueDateColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_invoices LIKE 'due_date'"));
                        
                        if ($hasDueDateColumn) {
                            $db->execute(
                                "INSERT INTO local_invoices 
                                (invoice_number, customer_id, date, due_date, subtotal, tax_rate, tax_amount, 
                                 discount_amount, total_amount, paid_amount, remaining_amount, status, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?, 0, ?, 'sent', ?, ?)",
                                [
                                    $localInvoiceNumber,
                                    $localCustomerId,
                                    $saleDate,
                                    $dueDate,
                                    $subtotal,
                                    $totalAmount,
                                    $totalAmount,
                                    $notes,
                                    $currentUser['id'] ?? null
                                ]
                            );
                        } else {
                            $db->execute(
                                "INSERT INTO local_invoices 
                                (invoice_number, customer_id, date, subtotal, tax_rate, tax_amount, 
                                 discount_amount, total_amount, paid_amount, remaining_amount, status, notes, created_by)
                                VALUES (?, ?, ?, ?, 0, 0, 0, ?, 0, ?, 'sent', ?, ?)",
                                [
                                    $localInvoiceNumber,
                                    $localCustomerId,
                                    $saleDate,
                                    $subtotal,
                                    $totalAmount,
                                    $totalAmount,
                                    $notes,
                                    $currentUser['id'] ?? null
                                ]
                            );
                        }
                        
                        $localInvoiceId = (int)$db->getLastInsertId();
                        
                        error_log("Local invoice created successfully: ID=$localInvoiceId, Number=$localInvoiceNumber, Customer=$localCustomerId");
                        
                        // إضافة عناصر الفاتورة المحلية
                        if (!empty($orderItems)) {
                            // التحقق من وجود أعمدة batch_number و batch_id في local_invoice_items
                            $hasBatchNumber = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_number'"));
                            $hasBatchId = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_id'"));
                            
                            foreach ($orderItems as $item) {
                                $productId = (int)($item['product_id'] ?? 0);
                                $quantity = (float)($item['quantity'] ?? 0);
                                $unitPrice = (float)($item['unit_price'] ?? 0);
                                $totalPrice = (float)($item['total_price'] ?? 0);
                                
                                if ($productId > 0 && $quantity > 0) {
                                    // ===== جلب بيانات المنتج ورقم التشغيلة بشكل جذري =====
                                    $batchId = isset($item['batch_id']) && $item['batch_id'] ? (int)$item['batch_id'] : null;
                                    $productName = '';
                                    $batchNumber = null;
                                    $correctProductId = $productId; // product_id الصحيح للمنتج
                                    
                                    error_log("shipping_orders: Processing local_invoice_item - original product_id: $productId, batch_id: " . ($batchId ?? 'NULL'));
                                    
                                    if ($batchId) {
                                        // ===== منتج مصنع - جلب جميع البيانات من finished_products =====
                                        $fpData = $db->queryOne("
                                            SELECT 
                                                fp.id as finished_product_id,
                                                fp.product_name as fp_product_name,
                                                fp.batch_number as fp_batch_number,
                                                fp.product_id as fp_product_id,
                                                bn.id as batch_number_id,
                                                bn.batch_number as bn_batch_number,
                                                bn.product_id as bn_product_id,
                                                pr1.name as pr1_name,
                                                pr2.name as pr2_name
                                            FROM finished_products fp
                                            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                                            LEFT JOIN products pr1 ON fp.product_id = pr1.id
                                            LEFT JOIN products pr2 ON bn.product_id = pr2.id
                                            WHERE fp.id = ?
                                            LIMIT 1
                                        ", [$batchId]);
                                        
                                        if ($fpData) {
                                            // 1. جلب batch_number
                                            if (!empty($fpData['fp_batch_number'])) {
                                                $batchNumber = trim($fpData['fp_batch_number']);
                                            } elseif (!empty($fpData['bn_batch_number'])) {
                                                $batchNumber = trim($fpData['bn_batch_number']);
                                            }
                                            
                                            // 2. تحديد product_id الصحيح
                                            if (!empty($fpData['fp_product_id']) && $fpData['fp_product_id'] > 0) {
                                                $correctProductId = (int)$fpData['fp_product_id'];
                                            } elseif (!empty($fpData['bn_product_id']) && $fpData['bn_product_id'] > 0) {
                                                $correctProductId = (int)$fpData['bn_product_id'];
                                            }
                                            
                                            // 3. تحديد اسم المنتج بالترتيب الصحيح
                                            // الأولوية: اسم من products المرتبط بـ fp.product_id > اسم من products المرتبط بـ bn.product_id > fp.product_name
                                            $candidateNames = [];
                                            
                                            // اسم المنتج من products (fp.product_id)
                                            if (!empty($fpData['pr1_name']) && trim($fpData['pr1_name']) !== '' 
                                                && strpos($fpData['pr1_name'], 'منتج رقم') !== 0) {
                                                $candidateNames[] = trim($fpData['pr1_name']);
                                            }
                                            
                                            // اسم المنتج من products (bn.product_id)
                                            if (!empty($fpData['pr2_name']) && trim($fpData['pr2_name']) !== '' 
                                                && strpos($fpData['pr2_name'], 'منتج رقم') !== 0) {
                                                $candidateNames[] = trim($fpData['pr2_name']);
                                            }
                                            
                                            // اسم المنتج من finished_products
                                            if (!empty($fpData['fp_product_name']) && trim($fpData['fp_product_name']) !== '' 
                                                && strpos($fpData['fp_product_name'], 'منتج رقم') !== 0) {
                                                $candidateNames[] = trim($fpData['fp_product_name']);
                                            }
                                            
                                            // اختيار أول اسم صالح
                                            if (!empty($candidateNames)) {
                                                $productName = $candidateNames[0];
                                            } else {
                                                // لا يوجد اسم صالح، نستخدم product_id
                                                $productName = 'منتج رقم ' . $correctProductId;
                                            }
                                            
                                            error_log("shipping_orders: Found fpData - batch_number: " . ($batchNumber ?? 'NULL') . 
                                                      ", product_name: $productName, correct_product_id: $correctProductId" .
                                                      ", fp_product_id: " . ($fpData['fp_product_id'] ?? 'NULL') .
                                                      ", bn_product_id: " . ($fpData['bn_product_id'] ?? 'NULL'));
                                        } else {
                                            // لم يُعثر على finished_product - جلب من products
                                            error_log("shipping_orders: WARNING - finished_products not found for batch_id: $batchId");
                                            $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                                            $productName = $product['name'] ?? 'منتج رقم ' . $productId;
                                        }
                                    } else {
                                        // ===== منتج خارجي - جلب اسم المنتج من products =====
                                        $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                                        $productName = $product['name'] ?? 'منتج رقم ' . $productId;
                                    }
                                    
                                    $itemTotal = $quantity * $unitPrice;
                                    
                                    // ===== بناء استعلام INSERT ديناميكياً =====
                                    $columns = ['invoice_id', 'product_id', 'description', 'quantity', 'unit_price', 'total_price'];
                                    $values = [
                                        $localInvoiceId,
                                        $correctProductId, // استخدام product_id الصحيح
                                        $productName,      // اسم المنتج الصحيح
                                        $quantity,
                                        $unitPrice,
                                        $itemTotal
                                    ];
                                    
                                    if ($hasBatchNumber) {
                                        $columns[] = 'batch_number';
                                        $values[] = $batchNumber;
                                        error_log("shipping_orders: Adding to local_invoice_items - product_name: $productName, batch_number: " . ($batchNumber ?? 'NULL') . ", product_id: $correctProductId, batch_id: " . ($batchId ?? 'NULL'));
                                    }
                                    
                                    if ($hasBatchId) {
                                        $columns[] = 'batch_id';
                                        $values[] = $batchId;
                                    }
                                    
                                    $placeholders = str_repeat('?,', count($values) - 1) . '?';
                                    $sql = "INSERT INTO local_invoice_items (" . implode(', ', $columns) . ") VALUES ($placeholders)";
                                    
                                    $insertResult = $db->execute($sql, $values);
                                    
                                    // ربط local_invoice_item مع sales_batch_numbers عبر invoice_items (إذا كان هناك invoice_id)
                                    // هذا يضمن ظهور رقم التشغيلة في سجل مشتريات العميل المحلي
                                    if (!empty($order['invoice_id']) && $batchId && !empty($batchNumber)) {
                                        try {
                                            $localInvoiceItemId = (int)($insertResult['insert_id'] ?? $db->getLastInsertId());
                                            
                                            // البحث عن invoice_item_id المطابق من الفاتورة الأصلية
                                            // المطابقة بناءً على product_id و quantity و unit_price
                                            $matchingInvoiceItem = $db->queryOne(
                                                "SELECT id FROM invoice_items 
                                                 WHERE invoice_id = ? 
                                                   AND product_id = ? 
                                                   AND ABS(quantity - ?) < 0.001 
                                                   AND ABS(unit_price - ?) < 0.001
                                                 ORDER BY id ASC
                                                 LIMIT 1",
                                                [$order['invoice_id'], $productId, $quantity, $unitPrice]
                                            );
                                            
                                            if ($matchingInvoiceItem && !empty($matchingInvoiceItem['id'])) {
                                                $invoiceItemId = (int)$matchingInvoiceItem['id'];
                                                
                                                // جلب batch_number_id من batch_numbers
                                                $batchNumberIdCheck = $db->queryOne(
                                                    "SELECT id FROM batch_numbers WHERE batch_number = ?",
                                                    [$batchNumber]
                                                );
                                                
                                                if ($batchNumberIdCheck) {
                                                    $batchNumberId = (int)$batchNumberIdCheck['id'];
                                                    
                                                    // التحقق من وجود سجل في sales_batch_numbers لهذا invoice_item_id
                                                    $existingSalesBatchLink = $db->queryOne(
                                                        "SELECT id FROM sales_batch_numbers WHERE invoice_item_id = ? AND batch_number_id = ?",
                                                        [$invoiceItemId, $batchNumberId]
                                                    );
                                                    
                                                    if (!$existingSalesBatchLink) {
                                                        // إنشاء سجل في sales_batch_numbers إذا لم يكن موجوداً
                                                        $db->execute(
                                                            "INSERT INTO sales_batch_numbers (invoice_item_id, batch_number_id, quantity) 
                                                             VALUES (?, ?, ?)
                                                             ON DUPLICATE KEY UPDATE quantity = quantity",
                                                            [$invoiceItemId, $batchNumberId, $quantity]
                                                        );
                                                        error_log("shipping_orders: Created sales_batch_numbers link for local_invoice_item_id=$localInvoiceItemId via invoice_item_id=$invoiceItemId, batch_number=$batchNumber");
                                                    } else {
                                                        error_log("shipping_orders: sales_batch_numbers link already exists for invoice_item_id=$invoiceItemId, batch_number=$batchNumber");
                                                    }
                                                } else {
                                                    error_log("shipping_orders: WARNING - batch_number_id not found for batch_number=$batchNumber when linking local_invoice_item");
                                                }
                                            } else {
                                                error_log("shipping_orders: WARNING - Could not find matching invoice_item for local_invoice_item - product_id=$productId, quantity=$quantity, unit_price=$unitPrice");
                                            }
                                        } catch (Throwable $linkError) {
                                            error_log('shipping_orders: Error linking local_invoice_item to sales_batch_numbers: ' . $linkError->getMessage());
                                            // لا نوقف العملية
                                        }
                                    }
                                }
                            }
                            error_log("Local invoice items added successfully: " . count($orderItems) . " items for invoice ID=$localInvoiceId");
                        }
                    } else {
                        error_log("Local invoice already exists: Number=$localInvoiceNumber");
                        // حتى لو كانت الفاتورة موجودة، نحاول ربط العناصر مع sales_batch_numbers
                        if (!empty($order['invoice_id'])) {
                            try {
                                $existingLocalInvoiceId = (int)$existingLocalInvoice['id'];
                                
                                // جلب عناصر الفاتورة المحلية
                                $existingLocalInvoiceItems = $db->query(
                                    "SELECT id, product_id, batch_id, quantity, unit_price, batch_number 
                                     FROM local_invoice_items 
                                     WHERE invoice_id = ?",
                                    [$existingLocalInvoiceId]
                                );
                                
                                foreach ($existingLocalInvoiceItems as $localItem) {
                                    $localProductId = (int)$localItem['product_id'];
                                    $localQuantity = (float)$localItem['quantity'];
                                    $localUnitPrice = (float)$localItem['unit_price'];
                                    $localBatchId = !empty($localItem['batch_id']) ? (int)$localItem['batch_id'] : null;
                                    $localBatchNumber = !empty($localItem['batch_number']) ? trim($localItem['batch_number']) : null;
                                    
                                    if ($localBatchId && $localBatchNumber) {
                                        // البحث عن invoice_item_id المطابق
                                        $matchingInvoiceItem = $db->queryOne(
                                            "SELECT id FROM invoice_items 
                                             WHERE invoice_id = ? 
                                               AND product_id = ? 
                                               AND ABS(quantity - ?) < 0.001 
                                               AND ABS(unit_price - ?) < 0.001
                                             ORDER BY id ASC
                                             LIMIT 1",
                                            [$order['invoice_id'], $localProductId, $localQuantity, $localUnitPrice]
                                        );
                                        
                                        if ($matchingInvoiceItem) {
                                            $invoiceItemId = (int)$matchingInvoiceItem['id'];
                                            $batchNumberIdCheck = $db->queryOne(
                                                "SELECT id FROM batch_numbers WHERE batch_number = ?",
                                                [$localBatchNumber]
                                            );
                                            
                                            if ($batchNumberIdCheck) {
                                                $batchNumberId = (int)$batchNumberIdCheck['id'];
                                                $existingSalesBatchLink = $db->queryOne(
                                                    "SELECT id FROM sales_batch_numbers WHERE invoice_item_id = ? AND batch_number_id = ?",
                                                    [$invoiceItemId, $batchNumberId]
                                                );
                                                
                                                if (!$existingSalesBatchLink) {
                                                    $db->execute(
                                                        "INSERT INTO sales_batch_numbers (invoice_item_id, batch_number_id, quantity) 
                                                         VALUES (?, ?, ?)
                                                         ON DUPLICATE KEY UPDATE quantity = quantity",
                                                        [$invoiceItemId, $batchNumberId, $localQuantity]
                                                    );
                                                    error_log("shipping_orders: Linked existing local_invoice_item_id=" . $localItem['id'] . " via invoice_item_id=$invoiceItemId");
                                                }
                                            }
                                        }
                                    }
                                }
                            } catch (Throwable $existingLinkError) {
                                error_log('shipping_orders: Error linking existing local invoice items: ' . $existingLinkError->getMessage());
                            }
                        }
                    }
                } catch (Throwable $localInvoiceError) {
                    // لا نوقف العملية إذا فشل إنشاء الفاتورة المحلية، فقط نسجل الخطأ
                    error_log('Error creating local invoice: ' . $localInvoiceError->getMessage());
                    error_log('Stack trace: ' . $localInvoiceError->getTraceAsString());
                }
            }

            // تحديث الفاتورة لتعكس المبلغ المتبقي وإضافة المنتجات إلى سجل مشتريات العميل
            if (!empty($order['invoice_id'])) {
                // التحقق من عدم تحديث فواتير نقطة البيع
                $hasCreatedFromPosColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'created_from_pos'"));
                if ($hasCreatedFromPosColumn) {
                    $invoiceCheck = $db->queryOne("SELECT created_from_pos FROM invoices WHERE id = ?", [$order['invoice_id']]);
                    if (empty($invoiceCheck) || empty($invoiceCheck['created_from_pos'])) {
                        // ليست فاتورة من نقطة البيع، يمكن تحديثها
                        $db->execute(
                            "UPDATE invoices SET status = 'sent', remaining_amount = ?, paid_amount = 0, updated_at = NOW() WHERE id = ?",
                            [$totalAmount, $order['invoice_id']]
                        );
                    }
                } else {
                    // العمود غير موجود، يمكن التحديث (للتوافق مع الإصدارات القديمة)
                    $db->execute(
                        "UPDATE invoices SET status = 'sent', remaining_amount = ?, paid_amount = 0, updated_at = NOW() WHERE id = ?",
                        [$totalAmount, $order['invoice_id']]
                    );
                }
                
                // جلب بيانات الفاتورة للتأكد من تحديث سجل المشتريات
                $invoiceData = $db->queryOne(
                    "SELECT id, invoice_number, date, total_amount, paid_amount, status, customer_id 
                     FROM invoices WHERE id = ?",
                    [$order['invoice_id']]
                );
                
                // إضافة المنتجات إلى سجل مشتريات العميل
                if ($invoiceData) {
                    try {
                        // التحقق من تطابق customer_id بين الفاتورة والطلب
                        $invoiceCheck = $db->queryOne(
                            "SELECT customer_id FROM invoices WHERE id = ?",
                            [$order['invoice_id']]
                        );
                        
                        if (!$invoiceCheck) {
                            throw new RuntimeException('الفاتورة غير موجودة');
                        }
                        
                        $invoiceCustomerId = (int)($invoiceCheck['customer_id'] ?? 0);
                        $orderCustomerId = (int)($order['customer_id'] ?? 0);
                        
                        if ($invoiceCustomerId !== $orderCustomerId) {
                            error_log(sprintf(
                                'ERROR: customer_id mismatch in shipping_orders! Invoice customer_id: %d, Order customer_id: %d, Invoice ID: %d',
                                $invoiceCustomerId,
                                $orderCustomerId,
                                $order['invoice_id']
                            ));
                            throw new RuntimeException('تضارب في بيانات العميل: customer_id في الفاتورة لا يطابق customer_id في الطلب');
                        }
                        
                        // التأكد من أن جدول customer_purchase_history موجود
                        customerHistoryEnsureSetup();
                        
                        // إضافة أو تحديث سجل الفاتورة في customer_purchase_history
                        $db->execute(
                            "INSERT INTO customer_purchase_history
                                (customer_id, invoice_id, invoice_number, invoice_date, invoice_total, paid_amount, invoice_status,
                                 return_total, return_count, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())
                             ON DUPLICATE KEY UPDATE
                                invoice_number = VALUES(invoice_number),
                                invoice_date = VALUES(invoice_date),
                                invoice_total = VALUES(invoice_total),
                                paid_amount = VALUES(paid_amount),
                                invoice_status = VALUES(invoice_status),
                                updated_at = NOW()",
                            [
                                $orderCustomerId,
                                $order['invoice_id'],
                                $invoiceData['invoice_number'] ?? '',
                                $invoiceData['date'] ?? date('Y-m-d'),
                                (float)($invoiceData['total_amount'] ?? 0),
                                (float)($invoiceData['paid_amount'] ?? 0),
                                $invoiceData['status'] ?? 'sent',
                            ]
                        );
                        
                        error_log(sprintf(
                            'shipping_orders: Saved purchase history for customer_id=%d, invoice_id=%d, invoice_number=%s',
                            $order['customer_id'],
                            $order['invoice_id'],
                            $invoiceData['invoice_number'] ?? 'N/A'
                        ));
                    } catch (Throwable $historyError) {
                        error_log('shipping_orders: failed saving customer purchase history -> ' . $historyError->getMessage());
                        error_log('shipping_orders: history error trace -> ' . $historyError->getTraceAsString());
                    }
                }
            }
            
            // مزامنة كاملة لسجل المشتريات للتأكد من تحديث جميع البيانات (يتم استدعاؤها دائماً)
            // يجب استخدام customer_id من جدول customers وليس local_customers
            try {
                // تحديد customer_id الصحيح للاستخدام في المزامنة
                $syncCustomerId = $order['customer_id'];
                $foundCustomerIdFromInvoice = false;
                
                // إذا كان هناك invoice_id، نستخدم customer_id من الفاتورة (من جدول customers)
                if (!empty($order['invoice_id'])) {
                    $invoiceDataForSync = $db->queryOne(
                        "SELECT customer_id FROM invoices WHERE id = ?",
                        [$order['invoice_id']]
                    );
                    if ($invoiceDataForSync && !empty($invoiceDataForSync['customer_id'])) {
                        $syncCustomerId = (int)$invoiceDataForSync['customer_id'];
                        $foundCustomerIdFromInvoice = true;
                    }
                }
                
                // إذا كان العميل من local_customers ولم نجد customer_id من الفاتورة، نحاول استخدام salesCustomerId
                // أو البحث عن/إنشاء عميل مؤقت في customers
                if ($customerTable === 'local_customers' && !$foundCustomerIdFromInvoice) {
                    // البحث عن أو إنشاء عميل مؤقت في customers (مثل ما تم في كود sales)
                    $customerName = $customer['name'] ?? '';
                    $customerPhone = $customer['phone'] ?? null;
                    $customerAddress = $customer['address'] ?? null;
                    
                    $existingCustomerInCustomers = $db->queryOne(
                        "SELECT id FROM customers WHERE name = ? AND created_by_admin = 1 LIMIT 1",
                        [$customerName]
                    );
                    
                    if ($existingCustomerInCustomers) {
                        $syncCustomerId = (int)$existingCustomerInCustomers['id'];
                    } elseif ($salesCustomerId && !empty($salesCustomerId)) {
                        // استخدام salesCustomerId إذا كان موجوداً
                        $customerInCustomers = $db->queryOne(
                            "SELECT id FROM customers WHERE id = ?",
                            [$salesCustomerId]
                        );
                        if ($customerInCustomers) {
                            $syncCustomerId = $salesCustomerId;
                        }
                    }
                } elseif ($customerTable === 'local_customers' && isset($salesCustomerId) && !empty($salesCustomerId)) {
                    // التحقق من أن salesCustomerId موجود في جدول customers
                    $customerInCustomers = $db->queryOne(
                        "SELECT id FROM customers WHERE id = ?",
                        [$salesCustomerId]
                    );
                    if ($customerInCustomers) {
                        $syncCustomerId = $salesCustomerId;
                    }
                }
                
                // استدعاء دالة المزامنة فقط إذا كان customer_id من جدول customers
                $customerInCustomersCheck = $db->queryOne(
                    "SELECT id FROM customers WHERE id = ?",
                    [$syncCustomerId]
                );
                
                if ($customerInCustomersCheck) {
                    customerHistorySyncForCustomer($syncCustomerId);
                    error_log(sprintf(
                        'shipping_orders: Synced purchase history for customer_id=%d (from %s table) after completing order_id=%d',
                        $syncCustomerId,
                        $customerTable === 'local_customers' ? 'customers (mapped from local)' : 'customers',
                        $orderId
                    ));
                } else {
                    error_log(sprintf(
                        'shipping_orders: Skipped syncing purchase history - customer_id=%d is not in customers table (from %s table)',
                        $syncCustomerId,
                        $customerTable
                    ));
                }
            } catch (Throwable $syncError) {
                error_log('shipping_orders: failed syncing customer purchase history -> ' . $syncError->getMessage());
                error_log('shipping_orders: sync error trace -> ' . $syncError->getTraceAsString());
                // لا نوقف العملية إذا فشل تحديث السجل
            }

            logAudit(
                $currentUser['id'] ?? null,
                'complete_shipping_order',
                'shipping_order',
                $orderId,
                null,
                [
                    'total_amount' => $totalAmount,
                    'customer_id' => $order['customer_id'],
                    'customer_table' => $customerTable,
                    'old_balance' => $currentBalance,
                    'new_balance' => $newBalance,
                    'shipping_company_id' => $order['shipping_company_id'],
                ]
            );

            $db->commit();
            $transactionStarted = false;

            $deliverySuccessMsg = 'تم تأكيد تسليم الطلب للعميل ونقل الدين بنجاح. تم إضافة المنتجات إلى سجل مشتريات العميل.';
            $_SESSION[$sessionSuccessKey] = $deliverySuccessMsg;
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => $deliverySuccessMsg,
                    'collected_amount' => (float)$collectedAmount,
                    'total_amount' => (float)$totalAmount,
                    'remaining_balance' => (float)$newBalance,
                    'order_id' => (int)$orderId
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (InvalidArgumentException $validationError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            $_SESSION[$sessionErrorKey] = $validationError->getMessage();
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $validationError->getMessage()], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (Throwable $completeError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log('shipping_orders: complete order error -> ' . $completeError->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر إتمام إجراءات الطلب. يرجى المحاولة لاحقاً.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'تعذر إتمام إجراءات الطلب. يرجى المحاولة لاحقاً.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }
}

$shippingCompanies = [];
try {
    $shippingCompanies = $db->query(
        "SELECT id, name, phone, status, balance FROM shipping_companies ORDER BY status = 'active' DESC, name ASC"
    );
} catch (Throwable $companiesError) {
    error_log('shipping_orders: failed fetching companies -> ' . $companiesError->getMessage());
    $shippingCompanies = [];
}

$activeCustomers = [];
try {
    // جلب العملاء المحليين فقط من جدول local_customers
    $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (!empty($localCustomersTableExists)) {
        $activeCustomers = $db->query(
            "SELECT id, name, phone FROM local_customers WHERE status = 'active' ORDER BY name ASC"
        );
    } else {
        $activeCustomers = [];
    }
} catch (Throwable $customersError) {
    error_log('shipping_orders: failed fetching customers -> ' . $customersError->getMessage());
    $activeCustomers = [];
}

$availableProducts = [];
try {
    // التحقق من وجود جدول finished_products
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    
    $productsList = [];
    
    // جلب منتجات المصنع من finished_products
    if (!empty($finishedProductsTableExists)) {
        try {
            $factoryProducts = $db->query("
                SELECT 
                    fp.id,
                    COALESCE(fp.product_id, bn.product_id) AS product_id,
                    COALESCE(
                        NULLIF(TRIM(fp.product_name), ''),
                        pr.name,
                        CONCAT('منتج رقم ', COALESCE(fp.product_id, bn.product_id, fp.id))
                    ) AS name,
                    fp.quantity_produced AS quantity,
                    COALESCE(
                        NULLIF(fp.unit_price, 0),
                        (SELECT pt.unit_price 
                         FROM product_templates pt 
                         WHERE pt.status = 'active' 
                           AND pt.unit_price IS NOT NULL 
                           AND pt.unit_price > 0
                           AND pt.unit_price <= 10000
                           AND (
                               (COALESCE(fp.product_id, bn.product_id) IS NOT NULL 
                                AND COALESCE(fp.product_id, bn.product_id) > 0
                                AND pt.product_id IS NOT NULL 
                                AND pt.product_id > 0 
                                AND pt.product_id = COALESCE(fp.product_id, bn.product_id))
                               OR (
                                   pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                   )
                               )
                           )
                         ORDER BY pt.unit_price DESC
                         LIMIT 1),
                        0
                    ) AS unit_price,
                    'قطعة' AS unit,
                    fp.batch_number,
                    fp.id AS batch_id,
                    'factory' AS product_type
                FROM finished_products fp
                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                ORDER BY fp.production_date DESC, fp.id DESC
            ");
            
            foreach ($factoryProducts as $fp) {
                $batchNumber = $fp['batch_number'] ?? '';
                $productName = $fp['name'] ?? 'غير محدد';
                $productId = (int)($fp['product_id'] ?? 0);
                $batchId = (int)($fp['batch_id'] ?? 0);
                $quantityProduced = (float)($fp['quantity'] ?? 0); // هذا هو quantity_produced (الكمية المتبقية)
                $unitPrice = (float)($fp['unit_price'] ?? 0);
                
                // للمنتجات التي لها رقم تشغيلة: استخدام quantity_produced مباشرة
                // وعدم استخدام products.quantity لأنها قد تكون لجميع أرقام التشغيلة مجتمعة
                $quantity = $quantityProduced;
                
                // حساب الكميات المحجوزة والمباعة (يُطبق فقط على quantity_produced للمنتجات التي لها رقم تشغيلة)
                $soldQty = 0;
                $pendingQty = 0;
                $pendingShippingQty = 0;
                
                if (!empty($batchNumber)) {
                    try {
                        // حساب الكمية المباعة (مثل company_products.php)
                        $sold = $db->queryOne("
                            SELECT COALESCE(SUM(ii.quantity), 0) AS sold_quantity
                            FROM invoice_items ii
                            INNER JOIN invoices i ON ii.invoice_id = i.id
                            INNER JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                            INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                            WHERE bn.batch_number = ?
                        ", [$batchNumber]);
                        $soldQty = (float)($sold['sold_quantity'] ?? 0);
                        
                        // حساب الكمية المحجوزة في طلبات العملاء المعلقة
                        // ملاحظة: customer_order_items لا يحتوي على batch_number مباشرة
                        // لذلك نستخدم finished_products للربط مع batch_number بناءً على product_id و batch_number
                        $pending = $db->queryOne("
                            SELECT COALESCE(SUM(oi.quantity), 0) AS pending_quantity
                            FROM customer_order_items oi
                            INNER JOIN customer_orders co ON oi.order_id = co.id
                            INNER JOIN finished_products fp2 ON fp2.product_id = oi.product_id AND fp2.batch_number = ?
                            WHERE co.status = 'pending'
                        ", [$batchNumber]);
                        $pendingQty = (float)($pending['pending_quantity'] ?? 0);
                        
                        // حساب الكمية المحجوزة في طلبات الشحن المعلقة
                        $pendingShipping = $db->queryOne("
                            SELECT COALESCE(SUM(soi.quantity), 0) AS pending_quantity
                            FROM shipping_company_order_items soi
                            INNER JOIN shipping_company_orders sco ON soi.order_id = sco.id
                            WHERE sco.status = 'in_transit'
                              AND soi.batch_id = ?
                        ", [$batchId]);
                        $pendingShippingQty = (float)($pendingShipping['pending_quantity'] ?? 0);
                    } catch (Throwable $calcError) {
                        error_log('shipping_orders: error calculating available quantity for batch ' . $batchNumber . ': ' . $calcError->getMessage());
                    }
                }
                
                // حساب الكمية المتاحة
                // ملاحظة: quantity_produced يتم تحديثه تلقائياً عند المبيعات وطلبات الشحن
                // لذلك نحتاج فقط خصم طلبات العملاء المعلقة (pendingQty)
                $availableQuantity = max(0, $quantity - $pendingQty);
                
                // عرض جميع المنتجات حتى لو كانت الكمية المتاحة صفر (مثل نقطة بيع المدير)
                $productsList[] = [
                    'id' => (int)$fp['id'] + 1000000, // استخدام رقم فريد لمنتجات المصنع
                    'name' => $productName . ($batchNumber ? ' (' . $batchNumber . ')' : ''),
                    'quantity' => $availableQuantity,
                    'total_quantity' => $quantity, // الكمية الإجمالية قبل طرح المبيعات
                    'unit' => $fp['unit'] ?? 'قطعة',
                    'unit_price' => $unitPrice,
                    'batch_number' => $batchNumber,
                    'batch_id' => $fp['batch_id'] ?? null,
                    'product_type' => 'factory',
                    'original_id' => (int)$fp['id']
                ];
            }
        } catch (Throwable $factoryError) {
            error_log('shipping_orders: failed fetching factory products -> ' . $factoryError->getMessage());
        }
    }
    
    // جلب المنتجات الخارجية من products
    try {
        $externalProducts = $db->query("
            SELECT 
                id,
                name,
                quantity,
                COALESCE(unit, 'قطعة') as unit,
                unit_price
            FROM products
            WHERE product_type = 'external'
              AND status = 'active'
              AND quantity > 0
            ORDER BY name ASC
        ");
        
        foreach ($externalProducts as $ep) {
            $productsList[] = [
                'id' => (int)$ep['id'],
                'name' => $ep['name'] ?? 'غير محدد',
                'quantity' => (float)($ep['quantity'] ?? 0),
                'unit' => $ep['unit'] ?? 'قطعة',
                'unit_price' => (float)($ep['unit_price'] ?? 0),
                'batch_number' => null,
                'batch_id' => null,
                'product_type' => 'external',
                'original_id' => (int)$ep['id']
            ];
        }
    } catch (Throwable $externalError) {
        error_log('shipping_orders: failed fetching external products -> ' . $externalError->getMessage());
    }
    
    // ترتيب المنتجات حسب الاسم
    usort($productsList, function($a, $b) {
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    });
    
    $availableProducts = $productsList;
    
} catch (Throwable $productsError) {
    error_log('shipping_orders: failed fetching products -> ' . $productsError->getMessage());
    $availableProducts = [];
}

$orders = [];
try {
    // جلب الطلبات مع البحث عن العملاء في كلا الجدولين (local_customers و customers)
    $orders = $db->query(
        "SELECT 
            sco.*, 
            sc.name AS shipping_company_name,
            sc.balance AS company_balance,
            COALESCE(lc.name, c.name) AS customer_name,
            COALESCE(lc.phone, c.phone) AS customer_phone,
            COALESCE(lc.balance, c.balance, 0) AS customer_balance,
            (lc.id IS NOT NULL) AS is_local_customer,
            i.invoice_number
        FROM shipping_company_orders sco
        LEFT JOIN shipping_companies sc ON sco.shipping_company_id = sc.id
        LEFT JOIN local_customers lc ON sco.customer_id = lc.id
        LEFT JOIN customers c ON sco.customer_id = c.id AND lc.id IS NULL
        LEFT JOIN invoices i ON sco.invoice_id = i.id
        ORDER BY sco.created_at DESC
        LIMIT 50"
    );
} catch (Throwable $ordersError) {
    error_log('shipping_orders: failed fetching orders -> ' . $ordersError->getMessage());
    $orders = [];
}

$ordersStats = [
    'total_orders' => 0,
    'active_orders' => 0,
    'delivered_orders' => 0,
    'outstanding_amount' => 0.0,
];

try {
    $statsRow = $db->queryOne(
        "SELECT 
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) AS active_orders,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
            SUM(CASE WHEN status = 'in_transit' THEN total_amount ELSE 0 END) AS outstanding_amount
        FROM shipping_company_orders"
    );

    if ($statsRow) {
        $ordersStats['total_orders'] = (int)($statsRow['total_orders'] ?? 0);
        $ordersStats['active_orders'] = (int)($statsRow['active_orders'] ?? 0);
        $ordersStats['delivered_orders'] = (int)($statsRow['delivered_orders'] ?? 0);
        $ordersStats['outstanding_amount'] = (float)($statsRow['outstanding_amount'] ?? 0);
    }
} catch (Throwable $statsError) {
    error_log('shipping_orders: failed fetching stats -> ' . $statsError->getMessage());
}

$statusLabels = [
    'assigned' => ['label' => 'تم التسليم لشركة الشحن', 'class' => 'bg-primary'],
    'in_transit' => ['label' => 'جاري الشحن', 'class' => 'bg-warning text-dark'],
    'delivered' => ['label' => 'تم التسليم للعميل', 'class' => 'bg-success'],
    'cancelled' => ['label' => 'ملغي', 'class' => 'bg-secondary'],
];

$hasProducts = !empty($availableProducts);
$hasShippingCompanies = !empty($shippingCompanies);
?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">تسجيل طلب شحن جديد</h5>
            <small class="text-muted">قم بتسليم المنتجات لشركة الشحن وتتبع الدين عليها لحين استلام العميل.</small>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$hasShippingCompanies): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-info-circle-fill fs-5"></i>
                <div>لم يتم إضافة شركات شحن بعد. يرجى إضافة شركة شحن قبل تسجيل الطلبات.</div>
            </div>
        <?php elseif (!$hasProducts): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-box-seam fs-5"></i>
                <div>لا توجد منتجات متاحة في المخزن الرئيسي حالياً.</div>
            </div>
        <?php elseif (empty($activeCustomers)): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-people fs-5"></i>
                <div>لا يوجد عملاء نشطون في النظام. قم بإضافة عميل أولاً.</div>
            </div>
        <?php else: ?>
            <form method="POST" id="shippingOrderForm" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="create_shipping_order">
                <div class="row g-3 mb-3">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">شركة الشحن <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select class="form-select" name="shipping_company_id" required>
                                <option value="">اختر شركة الشحن</option>
                                <?php foreach ($shippingCompanies as $company): ?>
                                    <option value="<?php echo (int)$company['id']; ?>">
                                        <?php echo htmlspecialchars($company['name']); ?>
                                        <?php if (!empty($company['phone'])): ?>
                                            - <?php echo htmlspecialchars($company['phone']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-secondary" type="button" onclick="showAddShippingCompanyModal()" title="إضافة شركة شحن">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">العميل <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select class="form-select" name="customer_id" id="customerSelect" required>
                                <option value="">اختر العميل</option>
                                <?php foreach ($activeCustomers as $customer): ?>
                                    <option value="<?php echo (int)$customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                        <?php if (!empty($customer['phone'])): ?>
                                            - <?php echo htmlspecialchars($customer['phone']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-secondary" type="button" onclick="showAddLocalCustomerModal()" title="إضافة عميل جديد">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-3">
                         <label class="form-label">رقم التليجراف (TG)</label>
                         <input type="text" class="form-control" name="tg_number" placeholder="أدخل رقم TG">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">المخزن المصدر</label>
                        <div class="form-control bg-light">
                            <i class="bi bi-building me-1"></i>
                            <?php echo htmlspecialchars($mainWarehouse['name'] ?? 'المخزن الرئيسي'); ?>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle" id="shippingItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 220px;">المنتج</th>
                                <th style="width: 110px;">المتاح</th>
                                <th style="width: 100px;">الوحدة</th>
                                <th style="width: 120px;">الكمية <span class="text-danger">*</span></th>
                                <th style="width: 140px;">سعر الوحدة <span class="text-danger">*</span></th>
                                <th style="width: 160px;">الإجمالي (قابل للتحكم)</th>
                                <th style="width: 80px;">حذف</th>
                            </tr>
                        </thead>
                        <tbody id="shippingItemsBody"></tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addShippingItemBtn">
                        <i class="bi bi-plus-circle me-1"></i>إضافة منتج
                    </button>
                    <div class="shipping-order-summary card bg-light border-0 px-3 py-2">
                        <div class="d-flex align-items-center gap-3">
                            <div>
                                <div class="small text-muted">إجمالي عدد المنتجات</div>
                                <div class="fw-semibold" id="shippingItemsCount">0</div>
                            </div>
                            <div class="border-start ps-3">
                                <label for="customTotalAmount" class="small text-muted d-block mb-1">إجمالي الطلب (تحكم يدوي)</label>
                                <div class="input-group input-group-sm" style="width: 150px;">
                                    <input type="number" class="form-control fw-bold text-success" id="customTotalAmount" name="custom_total_amount" step="0.01" min="0" placeholder="0.00">
                                    <span class="input-group-text">ج.م</span>
                                </div>
                            </div>
                            <!-- Hidden visual total as backup or reference -->
                            <div class="d-none">
                                <div class="small text-muted">إجمالي محسوب</div>
                                <div class="fw-bold text-success" id="shippingOrderTotal"><?php echo formatCurrency(0); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const customTotalInput = document.getElementById('customTotalAmount');
                    const shippingItemsBody = document.getElementById('shippingItemsBody');
                    
                    // تحديث الإجمالي اليدوي تلقائياً عند إضافة منتجات إذا لم يكن المستخدم قد عدله يدوياً
                    // (يمكن تنفيذ منطق أكثر تعقيداً هنا إذا لزم الأمر، لكن للتبسيط سنتركه للمستخدم)
                    
                    // منع إرسال النموذج إذا لم يكن هناك منتجات ولا إجمالي يدوي
                    document.getElementById('shippingOrderForm').addEventListener('submit', function(e) {
                         const itemsCount = shippingItemsBody.children.length;
                         const customTotal = parseFloat(customTotalInput.value || 0);
                         
                         if (itemsCount === 0 && customTotal <= 0) {
                             e.preventDefault();
                             alert('يرجى إضافة منتجات أو تحديد قيمة إجمالية للطلب.');
                         }
                    });
                });
                </script>

                <div class="mb-3">
                    <label class="form-label">ملاحظات إضافية</label>
                    <textarea class="form-control" name="order_notes" rows="2" placeholder="أي تفاصيل إضافية لشركة الشحن أو فريق المبيعات (اختياري)"></textarea>
                </div>

                <div class="alert alert-info d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle-fill fs-5"></i>
                    <div>
                        سيتم تسجيل هذا الطلب على شركة الشحن كدين لحين تأكيد التسليم للعميل، ثم يتحول الدين إلى العميل ليتم تحصيله لاحقاً.
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-success btn-lg" id="submitShippingOrderBtn">
                        <i class="bi bi-send-check me-1"></i>تسجيل الطلب وتسليم المنتجات
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0">شركات الشحن</h5>
        <button type="button" class="btn btn-primary btn-sm" onclick="showAddShippingCompanyModal()" title="إضافة شركة شحن">
            <i class="bi bi-plus-lg me-1"></i>إضافة شركة شحن
        </button>
    </div>
    <div class="card-body p-0">
        <?php if (empty($shippingCompanies)): ?>
            <div class="p-4 text-center text-muted">لم يتم إضافة شركات شحن بعد.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>الاسم</th>
                            <th>الهاتف</th>
                            <th>الحالة</th>
                            <th>ديون الشركة</th>
                            <th style="width: 300px;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shippingCompanies as $company):
                            $companyBalance = (float)($company['balance'] ?? 0);
                            $balanceFormatted = formatCurrency($companyBalance);
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($company['name']); ?></td>
                                <td><?php echo $company['phone'] ? htmlspecialchars($company['phone']) : '<span class="text-muted">غير متوفر</span>'; ?></td>
                                <td>
                                    <?php if (($company['status'] ?? '') === 'active'): ?>
                                        <span class="badge bg-success">نشطة</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">غير نشطة</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold text-<?php echo $companyBalance > 0 ? 'danger' : 'muted'; ?>">
                                    <?php echo $balanceFormatted; ?>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="إجراءات">
                                            <i class="bi bi-gear me-1"></i>إجراءات
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="showShippingCompanyStatement(<?php echo (int)$company['id']; ?>, <?php echo json_encode($company['name'], JSON_UNESCAPED_UNICODE); ?>);"><i class="bi bi-journal-text me-2"></i>كشف حساب</button></li>
                                            <li><button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="showCompanyPaperInvoicesByIdName(<?php echo (int)$company['id']; ?>, <?php echo json_encode($company['name'], JSON_UNESCAPED_UNICODE); ?>);"><i class="bi bi-receipt-cutoff me-2"></i>فواتير ورقية</button></li>
                                            <li><button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="showEditBalanceByIdName(<?php echo (int)$company['id']; ?>, <?php echo json_encode($company['name'], JSON_UNESCAPED_UNICODE); ?>, <?php echo $companyBalance; ?>);"><i class="bi bi-pencil me-2"></i>تعديل الديون</button></li>
                                            <li><button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent <?php echo $companyBalance <= 0 ? 'disabled' : ''; ?>" onclick="if (!this.classList.contains('disabled')) showCollectByIdName(<?php echo (int)$company['id']; ?>, <?php echo json_encode($company['name'], JSON_UNESCAPED_UNICODE); ?>, <?php echo $companyBalance; ?>, <?php echo json_encode($balanceFormatted, JSON_UNESCAPED_UNICODE); ?>);"><i class="bi bi-cash-coin me-2"></i>تحصيل</button></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent <?php echo $companyBalance <= 0 ? 'disabled' : ''; ?>" onclick="if (!this.classList.contains('disabled')) showDeductFromShippingCompany(<?php echo (int)$company['id']; ?>, <?php echo json_encode($company['name'], JSON_UNESCAPED_UNICODE); ?>, <?php echo $companyBalance; ?>);"><i class="bi bi-dash-circle me-2"></i>خصم</button></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- بطاقة كشف حساب شركة الشحن (سجل الحركات المالية) -->
<div class="card shadow-sm mb-4 border-primary" id="shippingCompanyStatementCard" style="display: none;">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>كشف حساب - <span id="statementCardCompanyName">-</span></h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeShippingCompanyStatementCard()" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
        <input type="hidden" id="statementCardCompanyId" value="">
        <div id="statementCardLoading" class="text-center py-4 text-muted">
            <div class="spinner-border" role="status"></div>
            <p class="mt-2 mb-0">جاري تحميل كشف الحساب...</p>
        </div>
        <div id="statementCardContent" style="display: none;">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>التاريخ</th>
                            <th>البيان</th>
                            <th class="text-end">مدين</th>
                            <th class="text-end">دائن</th>
                            <th class="text-end">الرصيد</th>
                        </tr>
                    </thead>
                    <tbody id="statementCardTableBody"></tbody>
                </table>
            </div>
            <div class="row mt-3 text-muted small">
                <div class="col">إجمالي المدين: <strong id="statementTotalDebit">0</strong> ج.م</div>
                <div class="col">إجمالي الدائن: <strong id="statementTotalCredit">0</strong> ج.م</div>
                <div class="col">الرصيد النهائي: <strong id="statementNetBalance" class="text-primary">0</strong> ج.م</div>
            </div>
        </div>
        <div id="statementCardEmpty" class="text-center py-4 text-muted" style="display: none;">
            <i class="bi bi-inbox fs-1"></i>
            <p class="mb-0">لا توجد حركات مالية مسجلة لهذه الشركة.</p>
        </div>
    </div>
</div>

<!-- بطاقة سجل الفواتير الورقية لشركة الشحن (بدل المودال) -->
<div class="card shadow-sm mb-4 border-info" id="companyPaperInvoicesCard" style="display: none;">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>سجل الفواتير الورقية - <span id="companyPaperInvoicesCompanyName">-</span></h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeCompanyPaperInvoicesCard()" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
        <input type="hidden" id="companyPaperInvoicesCompanyId" value="">
        <div class="mb-3">
            <button type="button" class="btn btn-primary btn-sm" onclick="toggleCompanyPaperInvoiceAddCard()">
                <i class="bi bi-plus-lg me-1"></i>إضافة فاتورة ورقية
            </button>
        </div>
        <!-- بطاقة إضافة فاتورة ورقية لشركة (قابلة للطي) -->
        <div class="card mb-3 border-primary collapse" id="companyPaperInvoiceAddCard">
            <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-receipt-cutoff me-2"></i>إضافة فاتورة ورقية</span>
                <button type="button" class="btn btn-sm btn-light" onclick="toggleCompanyPaperInvoiceAddCard()"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="card-body">
                <p class="text-muted small">رفع صورة الفاتورة الورقية وإدخال رقم الفاتورة والإجمالي. تُحفظ كسجل للشركة.</p>
                <input type="hidden" id="companyPaperInvoiceAddCompanyId" value="">
                <div class="mb-3">
                    <label class="form-label">صورة الفاتورة <span class="text-danger">*</span></label>
                    <input type="file" id="companyPaperInvoiceAddImageInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp">
                    <div id="companyPaperInvoiceAddImagePreview" class="mt-2 text-center" style="display: none;">
                        <img id="companyPaperInvoiceAddPreviewImg" src="" alt="معاينة" class="img-fluid rounded border" style="max-height: 180px;">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">رقم الفاتورة <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="companyPaperInvoiceAddNumber" placeholder="أدخل رقم الفاتورة">
                </div>
                <div class="mb-3">
                    <label class="form-label">إجمالي الفاتورة (ج.م) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" class="form-control" id="companyPaperInvoiceAddTotal" placeholder="موجب = زيادة الديون، سالب = تقليل الديون">
                    <div class="form-text">موجب لزيادة ديون الشركة، سالب لتقليل الديون.</div>
                </div>
                <div id="companyPaperInvoiceAddMessage" class="alert d-none mb-0"></div>
                <button type="button" class="btn btn-primary" id="companyPaperInvoiceAddSubmitBtn" onclick="submitCompanyPaperInvoice()">
                    <i class="bi bi-check-lg me-1"></i>حفظ
                </button>
            </div>
        </div>
        <div id="companyPaperInvoicesLoading" class="text-center py-4 text-muted d-none">
            <div class="spinner-border" role="status"></div>
            <p class="mt-2 mb-0">جاري تحميل السجل...</p>
        </div>
        <div class="table-responsive" id="companyPaperInvoicesTableWrap" style="display: none;">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>الإجمالي</th>
                        <th>التاريخ</th>
                        <th>الإجراء</th>
                    </tr>
                </thead>
                <tbody id="companyPaperInvoicesTableBody"></tbody>
            </table>
        </div>
        <nav id="companyPaperInvoicesPaginationWrap" class="mt-2 d-flex flex-wrap justify-content-between align-items-center gap-2" style="display: none !important;" aria-label="تقسيم سجل الفواتير الورقية">
            <div class="text-muted small" id="companyPaperInvoicesPaginationInfo"></div>
            <ul class="pagination pagination-sm mb-0 flex-wrap" id="companyPaperInvoicesPagination"></ul>
        </nav>
        <div id="companyPaperInvoicesEmpty" class="text-center py-4 text-muted" style="display: none;">
            <i class="bi bi-inbox fs-1"></i>
            <p class="mb-0">لا توجد فواتير ورقية مسجلة لهذه الشركة بعد.</p>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0">طلبات الشحن</h5>
        <div class="input-group input-group-sm w-auto" style="min-width: 280px;">
             <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
             <input type="text" class="form-control border-start-0 ps-0" id="orderSearchInput" placeholder="بحث شامل (رقم الطلب، TG، العميل...)">
        </div>
    </div>
    <div class="card-body p-0">
        <!-- Search Results Container -->
        <div id="searchResultsContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover table-nowrap mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>رقم الطلب</th>
                            <th>شركة الشحن</th>
                            <th>العميل</th>
                            <th>المبلغ</th>
                            <th>الحالة</th>
                            <th>الفاتورة</th>
                            <th style="width: 150px;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="searchResultsBody"></tbody>
                </table>
            </div>
        </div>

        <div id="ordersTabsContainer">
        <?php
        // تقسيم الطلبات حسب الحالة
        $activeOrders = array_filter($orders, function($order) {
            return in_array($order['status'], ['in_transit'], true);
        });
        $deliveredOrders = array_filter($orders, function($order) {
            return $order['status'] === 'delivered';
        });
        $cancelledOrders = array_filter($orders, function($order) {
            return $order['status'] === 'cancelled';
        });
        ?>
        
        <ul class="nav nav-tabs card-header-tabs border-0 mx-0 mt-0" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="active-orders-tab" data-bs-toggle="tab" data-bs-target="#active-orders" type="button" role="tab">
                    جاري الشحن <span class="badge bg-warning text-dark ms-1"><?php echo count($activeOrders); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="delivered-orders-tab" data-bs-toggle="tab" data-bs-target="#delivered-orders" type="button" role="tab">
                    تم التسليم <span class="badge bg-success ms-1"><?php echo count($deliveredOrders); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="cancelled-orders-tab" data-bs-toggle="tab" data-bs-target="#cancelled-orders" type="button" role="tab">
                    ملغاة <span class="badge bg-secondary ms-1"><?php echo count($cancelledOrders); ?></span>
                </button>
            </li>
        </ul>
        
        <div class="tab-content">
            <!-- طلبات جارية -->
            <div class="tab-pane fade show active" id="active-orders" role="tabpanel">
                <?php if (empty($activeOrders)): ?>
                    <div class="p-4 text-center text-muted">لا توجد طلبات جارية حالياً.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>شركة الشحن</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>تاريخ التسليم للشركة</th>
                                    <th>الفاتورة</th>
                                    <th style="width: 250px;">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeOrders as $order): ?>
                                    <?php
                                        $statusInfo = $statusLabels[$order['status']] ?? ['label' => $order['status'], 'class' => 'bg-secondary'];
                                        $invoiceLink = '';
                                        if (!empty($order['invoice_id'])) {
                                            $invoiceUrl = getRelativeUrl('print_invoice.php?id=' . (int)$order['invoice_id']);
                                            $invoiceLink = '<a href="' . htmlspecialchars($invoiceUrl) . '" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>عرض الفاتورة</a>';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                                            <div class="text-muted small">سجل في <?php echo formatDateTime($order['created_at']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['shipping_company_name'] ?? 'غير معروف'); ?></div>
                                            <div class="text-muted small">دين حالي: <?php echo formatCurrency((float)($order['company_balance'] ?? 0)); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></div>
                                            <?php if (!empty($order['customer_phone'])): ?>
                                                <div class="text-muted small"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatCurrency((float)$order['total_amount']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $statusInfo['class']; ?>">
                                                <?php echo htmlspecialchars($statusInfo['label']); ?>
                                            </span>
                                            <?php if (!empty($order['handed_over_at'])): ?>
                                                <div class="text-muted small mt-1">سُلِّم للشركة: <?php echo formatDateTime($order['handed_over_at']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($order['handed_over_at'])): ?>
                                                <span class="text-info fw-semibold"><?php echo formatDateTime($order['handed_over_at']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($invoiceLink)): ?>
                                                <?php echo $invoiceLink; ?>
                                            <?php else: ?>
                                                <span class="text-muted">لا توجد فاتورة</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php if (!empty($order['customer_id'])): ?>
                                                    <button type="button" class="btn btn-outline-info btn-sm btn-shipping-invoice-log" onclick="showShippingInvoiceLogModal(this)"
                                                            data-customer-id="<?php echo (int)$order['customer_id']; ?>"
                                                            data-customer-name="<?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?>"
                                                            data-is-local="<?php echo (int)(isset($order['is_local_customer']) ? $order['is_local_customer'] : 0); ?>"
                                                            title="سجل الفواتير والفاتورة الورقية">
                                                        <i class="bi bi-receipt me-1"></i>سجل الفواتير
                                                    </button>
                                                <?php endif; ?>
                                                <form method="POST" class="d-inline cancel-order-form" onsubmit="return handleCancelOrder(event, this);"
                                                      data-order-number="<?php echo htmlspecialchars($order['order_number'] ?? ''); ?>"
                                                      data-total-amount="<?php echo (float)($order['total_amount'] ?? 0); ?>">
                                                    <input type="hidden" name="action" value="cancel_shipping_order">
                                                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm cancel-order-btn">
                                                        <i class="bi bi-x-circle me-1"></i>طلب ملغي
                                                    </button>
                                                </form>
                                                <button type="button" 
                                                        class="btn btn-success btn-sm delivery-btn" 
                                                        onclick="showDeliveryModal(this)"
                                                        data-order-id="<?php echo (int)$order['id']; ?>"
                                                        data-order-number="<?php echo htmlspecialchars($order['order_number'] ?? ''); ?>"
                                                        data-customer-id="<?php echo (int)($order['customer_id'] ?? 0); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?>"
                                                        data-customer-balance="<?php echo (float)($order['customer_balance'] ?? 0); ?>"
                                                        data-total-amount="<?php echo (float)$order['total_amount']; ?>"
                                                        data-shipping-company-name="<?php echo htmlspecialchars($order['shipping_company_name'] ?? 'غير معروف'); ?>"
                                                        data-company-balance="<?php echo (float)($order['company_balance'] ?? 0); ?>">
                                                    <i class="bi bi-check-circle me-1"></i>تم التسليم
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- طلبات مكتملة -->
            <div class="tab-pane fade" id="delivered-orders" role="tabpanel">
                <?php if (empty($deliveredOrders)): ?>
                    <div class="p-4 text-center text-muted">لا توجد طلبات مكتملة.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>شركة الشحن</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>تاريخ التسليم للعميل</th>
                                    <th>الفاتورة</th>
                                    <th style="width: 250px;">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deliveredOrders as $order): ?>
                                    <?php
                                        $deliveredAt = $order['delivered_at'] ?? null;
                                        $invoiceLink = '';
                                        if (!empty($order['invoice_id'])) {
                                            $invoiceUrl = getRelativeUrl('print_invoice.php?id=' . (int)$order['invoice_id']);
                                            $invoiceLink = '<a href="' . htmlspecialchars($invoiceUrl) . '" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>عرض الفاتورة</a>';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                                            <div class="text-muted small">سجل في <?php echo formatDateTime($order['created_at']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['shipping_company_name'] ?? 'غير معروف'); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></div>
                                            <?php if (!empty($order['customer_phone'])): ?>
                                                <div class="text-muted small"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatCurrency((float)$order['total_amount']); ?></td>
                                        <td>
                                            <?php if ($deliveredAt): ?>
                                                <span class="text-success fw-semibold"><?php echo formatDateTime($deliveredAt); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($invoiceLink)): ?>
                                                <?php echo $invoiceLink; ?>
                                            <?php else: ?>
                                                <span class="text-muted">لا توجد فاتورة</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($order['customer_id'])): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm btn-shipping-invoice-log" onclick="showShippingInvoiceLogModal(this)"
                                                        data-customer-id="<?php echo (int)$order['customer_id']; ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?>"
                                                        data-is-local="<?php echo (int)(isset($order['is_local_customer']) ? $order['is_local_customer'] : 0); ?>"
                                                        title="سجل الفواتير والفاتورة الورقية">
                                                    <i class="bi bi-receipt me-1"></i>سجل الفواتير
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- طلبات ملغاة -->
            <div class="tab-pane fade" id="cancelled-orders" role="tabpanel">
                <?php if (empty($cancelledOrders)): ?>
                    <div class="p-4 text-center text-muted">لا توجد طلبات ملغاة.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>شركة الشحن</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>تاريخ الإلغاء</th>
                                    <th>الفاتورة</th>
                                    <th style="width: 250px;">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cancelledOrders as $order): ?>
                                    <?php
                                        $invoiceLink = '';
                                        if (!empty($order['invoice_id'])) {
                                            $invoiceUrl = getRelativeUrl('print_invoice.php?id=' . (int)$order['invoice_id']);
                                            $invoiceLink = '<a href="' . htmlspecialchars($invoiceUrl) . '" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>عرض الفاتورة</a>';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                                            <div class="text-muted small">سجل في <?php echo formatDateTime($order['created_at']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['shipping_company_name'] ?? 'غير معروف'); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></div>
                                            <?php if (!empty($order['customer_phone'])): ?>
                                                <div class="text-muted small"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatCurrency((float)$order['total_amount']); ?></td>
                                        <td>
                                            <?php if (!empty($order['updated_at'])): ?>
                                                <span class="text-muted"><?php echo formatDateTime($order['updated_at']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($invoiceLink)): ?>
                                                <?php echo $invoiceLink; ?>
                                            <?php else: ?>
                                                <span class="text-muted">لا توجد فاتورة</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($order['customer_id'])): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm btn-shipping-invoice-log" onclick="showShippingInvoiceLogModal(this)"
                                                        data-customer-id="<?php echo (int)$order['customer_id']; ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?>"
                                                        data-is-local="<?php echo (int)(isset($order['is_local_customer']) ? $order['is_local_customer'] : 0); ?>"
                                                        title="سجل الفواتير والفاتورة الورقية">
                                                    <i class="bi bi-receipt me-1"></i>سجل الفواتير
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>
</div>

<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="addShippingCompanyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-truck me-2"></i>إضافة شركة شحن</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_shipping_company">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الشركة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="company_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الشخص المسؤول</label>
                        <input type="text" class="form-control" name="contact_person" placeholder="اسم الشخص المسؤول (اختياري)">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="text" class="form-control" name="phone" placeholder="مثال: 01000000000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">البريد الإلكتروني</label>
                            <input type="email" class="form-control" name="email" placeholder="example@domain.com">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label">العنوان</label>
                        <textarea class="form-control" name="address" rows="2" placeholder="عنوان شركة الشحن (اختياري)"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="company_notes" rows="2" placeholder="أي معلومات إضافية (اختياري)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="addLocalCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>إضافة عميل جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="addLocalCustomerForm">
                <input type="hidden" name="action" value="add_local_customer">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="customer_name" id="newCustomerName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" class="form-control" name="customer_phone" id="newCustomerPhone" placeholder="مثال: 01000000000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea class="form-control" name="customer_address" id="newCustomerAddress" rows="2" placeholder="عنوان العميل (اختياري)"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">الرصيد الابتدائي</label>
                        <input type="number" class="form-control" name="customer_balance" id="newCustomerBalance" value="0" step="0.01" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل ديون شركة الشحن - للكمبيوتر -->
<div class="modal fade d-none d-md-block" id="editShippingCompanyBalanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل ديون شركة الشحن</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_shipping_company_balance">
                <input type="hidden" name="company_id" id="editBalanceCompanyId">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">شركة الشحن</div>
                        <div class="fs-5" id="editBalanceCompanyName">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editBalanceAmount">ديون الشركة (الرصيد) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="editBalanceAmount" name="balance" step="0.01" required>
                        <div class="form-text">القيمة الموجبة = دين على الشركة، الصفر = لا ديون.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تحصيل من شركة الشحن - للكمبيوتر (مماثل لتحصيل العميل المحلي) -->
<div class="modal fade d-none d-md-block" id="collectFromShippingCompanyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل من شركة الشحن</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" data-no-loading="true">
                <input type="hidden" name="action" value="collect_from_shipping_company">
                <input type="hidden" name="company_id" id="collectCompanyId">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">شركة الشحن</div>
                        <div class="fs-5 collection-shipping-company-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning collection-shipping-current-debt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="collectShippingAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="collectShippingAmount" name="amount" step="0.01" min="0.01" required>
                        <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع التحصيل <span class="text-danger">*</span></label>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="form-check p-2 border rounded h-100" style="cursor: pointer;" onmouseover="this.style.backgroundColor='#f8f9fa'" onmouseout="this.style.backgroundColor=''">
                                    <input class="form-check-input" type="radio" name="collection_type" id="collectShippingTypeDirect" value="direct" checked>
                                    <label class="form-check-label w-100" for="collectShippingTypeDirect" style="cursor: pointer;">
                                        <div class="fw-semibold mb-1"><i class="bi bi-cash-stack me-1 text-success"></i>مباشر</div>
                                        <small class="text-muted d-block">تحصيل مباشر في خزنة الشركة</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check p-2 border rounded h-100" style="cursor: pointer;" onmouseover="this.style.backgroundColor='#f8f9fa'" onmouseout="this.style.backgroundColor=''">
                                    <input class="form-check-input" type="radio" name="collection_type" id="collectShippingTypeManagement" value="management">
                                    <label class="form-check-label w-100" for="collectShippingTypeManagement" style="cursor: pointer;">
                                        <div class="fw-semibold mb-1"><i class="bi bi-building me-1 text-primary"></i>للإدارة</div>
                                        <small class="text-muted d-block">تحصيل للإدارة وتوريدات</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">تحصيل المبلغ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal خصم من رصيد شركة الشحن - للكمبيوتر -->
<div class="modal fade d-none d-md-block" id="deductFromShippingCompanyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-dash-circle me-2"></i>خصم من رصيد شركة الشحن</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" data-no-loading="true" id="deductShippingCompanyFormModal">
                <input type="hidden" name="action" value="deduct_from_shipping_company">
                <input type="hidden" name="company_id" id="deductModalCompanyId">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">شركة الشحن</div>
                        <div class="fs-5" id="deductModalCompanyName">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning" id="deductModalCurrentDebt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="deductModalAmount">مبلغ الخصم <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="deductModalAmount" name="amount" step="0.01" min="0.01" required>
                        <div class="form-text">سيُخصم من ديون الشركة (بدون تحصيل نقدي).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="deductModalNotes">ملاحظات (اختياري)</label>
                        <textarea class="form-control" id="deductModalNotes" name="notes" rows="2" placeholder="سبب الخصم أو تفاصيل إضافية"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">تنفيذ الخصم</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal إلغاء الطلب - إدخال مبلغ الشحن المخصوم -->
<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="cancelOrderModalLabel">
                    <i class="bi bi-x-circle me-2"></i>إلغاء الطلب - مبلغ الشحن المخصوم
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-2"></i>
                    سيتم خصم المبلغ المدخل من ديون شركة الشحن وإرجاع المنتجات إلى المخزن الرئيسي.
                </div>
                <div class="mb-2">
                    <span class="text-muted">رقم الطلب:</span> <strong id="cancelModalOrderNumber">-</strong>
                </div>
                <div class="mb-2">
                    <span class="text-muted">مبلغ الطلب:</span> <strong id="cancelModalOrderTotal">-</strong>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="cancelModalDeductedAmount">مبلغ الشحن المخصوم <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="cancelModalDeductedAmount" step="0.01" min="0" placeholder="0.00" required>
                    <div class="form-text">المبلغ الذي سيُخصم من ديون شركة الشحن</div>
                    <div class="invalid-feedback" id="cancelModalAmountError"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-warning" id="cancelOrderModalConfirmBtn" onclick="confirmCancelOrderWithDeductedAmount();">
                    <i class="bi bi-x-circle me-1"></i>تأكيد الإلغاء وخصم المبلغ
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Card للموبايل - تعديل ديون شركة الشحن -->
<div class="card shadow-sm mb-4 d-md-none" id="editShippingCompanyBalanceCard" style="display: none;">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0"><i class="bi bi-pencil me-2"></i>تعديل ديون شركة الشحن</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="edit_shipping_company_balance">
            <input type="hidden" name="company_id" id="editBalanceCardCompanyId">
            <div class="mb-3">
                <div class="fw-semibold text-muted">شركة الشحن</div>
                <div class="fs-5" id="editBalanceCardCompanyName">-</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="editBalanceCardAmount">ديون الشركة (الرصيد)</label>
                <input type="number" class="form-control" id="editBalanceCardAmount" name="balance" step="0.01" required>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ التعديل</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditBalanceCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - تحصيل من شركة الشحن -->
<div class="card shadow-sm mb-4 d-md-none" id="collectFromShippingCompanyCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>تحصيل من شركة الشحن</h5>
    </div>
    <div class="card-body">
        <form method="POST" data-no-loading="true">
            <input type="hidden" name="action" value="collect_from_shipping_company">
            <input type="hidden" name="company_id" id="collectCardCompanyId">
            <div class="mb-3">
                <div class="fw-semibold text-muted">شركة الشحن</div>
                <div class="fs-5" id="collectCardCompanyName">-</div>
            </div>
            <div class="mb-3">
                <div class="fw-semibold text-muted">الديون الحالية</div>
                <div class="fs-5 text-warning" id="collectCardCurrentDebt">-</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="collectCardAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                <input type="number" class="form-control" id="collectCardAmount" name="amount" step="0.01" min="0.01" required>
            </div>
            <div class="mb-3">
                <label class="form-label">نوع التحصيل</label>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="form-check p-2 border rounded">
                            <input class="form-check-input" type="radio" name="collection_type" id="collectCardTypeDirect" value="direct" checked>
                            <label class="form-check-label" for="collectCardTypeDirect">مباشر</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check p-2 border rounded">
                            <input class="form-check-input" type="radio" name="collection_type" id="collectCardTypeManagement" value="management">
                            <label class="form-check-label" for="collectCardTypeManagement">للإدارة</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">تحصيل المبلغ</button>
                <button type="button" class="btn btn-secondary" onclick="closeCollectFromShippingCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - خصم من رصيد شركة الشحن -->
<div class="card shadow-sm mb-4 d-md-none" id="deductFromShippingCompanyCard" style="display: none;">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0"><i class="bi bi-dash-circle me-2"></i>خصم من رصيد شركة الشحن</h5>
    </div>
    <div class="card-body">
        <form method="POST" data-no-loading="true" id="deductShippingCompanyFormCard">
            <input type="hidden" name="action" value="deduct_from_shipping_company">
            <input type="hidden" name="company_id" id="deductCardCompanyId">
            <div class="mb-3">
                <div class="fw-semibold text-muted">شركة الشحن</div>
                <div class="fs-5" id="deductCardCompanyName">-</div>
            </div>
            <div class="mb-3">
                <div class="fw-semibold text-muted">الديون الحالية</div>
                <div class="fs-5 text-warning" id="deductCardCurrentDebt">-</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="deductCardAmount">مبلغ الخصم <span class="text-danger">*</span></label>
                <input type="number" class="form-control" id="deductCardAmount" name="amount" step="0.01" min="0.01" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="deductCardNotes">ملاحظات (اختياري)</label>
                <textarea class="form-control" id="deductCardNotes" name="notes" rows="2"></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-warning">تنفيذ الخصم</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeductFromShippingCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - إضافة شركة شحن -->
<div class="card shadow-sm mb-4 d-md-none" id="addShippingCompanyCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-truck me-2"></i>إضافة شركة شحن</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_shipping_company">
            <div class="mb-3">
                <label class="form-label">اسم الشركة <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="company_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label">الشخص المسؤول</label>
                <input type="text" class="form-control" name="contact_person" placeholder="اسم الشخص المسؤول (اختياري)">
            </div>
            <div class="mb-3">
                <label class="form-label">رقم الهاتف</label>
                <input type="text" class="form-control" name="phone" placeholder="مثال: 01000000000">
            </div>
            <div class="mb-3">
                <label class="form-label">البريد الإلكتروني</label>
                <input type="email" class="form-control" name="email" placeholder="example@domain.com">
            </div>
            <div class="mb-3">
                <label class="form-label">العنوان</label>
                <textarea class="form-control" name="address" rows="2" placeholder="عنوان شركة الشحن (اختياري)"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">ملاحظات</label>
                <textarea class="form-control" name="company_notes" rows="2" placeholder="أي معلومات إضافية (اختياري)"></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>حفظ
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeAddShippingCompanyCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - إضافة عميل -->
<div class="card shadow-sm mb-4 d-md-none" id="addLocalCustomerCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>إضافة عميل جديد</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="addLocalCustomerCardForm">
            <input type="hidden" name="action" value="add_local_customer">
            <div class="mb-3">
                <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="customer_name" id="newCustomerCardName" required>
            </div>
            <div class="mb-3">
                <label class="form-label">رقم الهاتف</label>
                <input type="text" class="form-control" name="customer_phone" id="newCustomerCardPhone" placeholder="مثال: 01000000000">
            </div>
            <div class="mb-3">
                <label class="form-label">العنوان</label>
                <textarea class="form-control" name="customer_address" id="newCustomerCardAddress" rows="2" placeholder="عنوان العميل (اختياري)"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">الرصيد الابتدائي</label>
                <input type="number" class="form-control" name="customer_balance" id="newCustomerCardBalance" value="0" step="0.01" min="0">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>حفظ
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeAddLocalCustomerCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
// ===== دوال أساسية =====

function isMobile() {
    return window.innerWidth <= 768;
}

function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80;
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, elementTop - offset),
                behavior: 'smooth'
            });
        });
    }, 200);
}

function closeAllForms() {
    const cards = ['addShippingCompanyCard', 'addLocalCustomerCard', 'companyPaperInvoicesCard', 'shippingCompanyStatementCard', 'collectFromShippingCompanyCard', 'deductFromShippingCompanyCard'];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    const modals = ['addShippingCompanyModal', 'addLocalCustomerModal', 'deliveryModal', 'editShippingCompanyBalanceModal', 'collectFromShippingCompanyModal', 'deductFromShippingCompanyModal'];
    
    // إضافة deliveryCard
    const deliveryCard = document.getElementById('deliveryCard');
    if (deliveryCard && deliveryCard.style.display !== 'none') {
        deliveryCard.style.display = 'none';
        const form = deliveryCard.querySelector('form');
        if (form) form.reset();
    }
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// ===== دوال فتح النماذج =====

function showAddShippingCompanyModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addShippingCompanyCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addShippingCompanyModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

function showAddLocalCustomerModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addLocalCustomerCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addLocalCustomerModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

function showEditShippingCompanyBalanceModal(button) {
    if (!button) return;
    closeAllForms();
    var companyId = button.getAttribute('data-company-id');
    var companyName = button.getAttribute('data-company-name');
    var balance = button.getAttribute('data-company-balance') || '0';
    if (isMobile()) {
        var card = document.getElementById('editShippingCompanyBalanceCard');
        if (card) {
            var idInput = document.getElementById('editBalanceCardCompanyId');
            var nameEl = document.getElementById('editBalanceCardCompanyName');
            var amountInput = document.getElementById('editBalanceCardAmount');
            if (idInput) idInput.value = companyId || '';
            if (nameEl) nameEl.textContent = companyName || '-';
            if (amountInput) amountInput.value = balance;
            card.style.display = 'block';
            setTimeout(function() { scrollToElement(card); }, 50);
        }
    } else {
        var modal = document.getElementById('editShippingCompanyBalanceModal');
        if (modal) {
            var idInput = document.getElementById('editBalanceCompanyId');
            var nameEl = document.getElementById('editBalanceCompanyName');
            var amountInput = document.getElementById('editBalanceAmount');
            if (idInput) idInput.value = companyId || '';
            if (nameEl) nameEl.textContent = companyName || '-';
            if (amountInput) amountInput.value = balance;
            var modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

function closeEditBalanceCard() {
    var card = document.getElementById('editShippingCompanyBalanceCard');
    if (card) card.style.display = 'none';
}

function showCollectFromShippingCompanyModal(button) {
    if (!button) return;
    closeAllForms();
    var companyId = button.getAttribute('data-company-id');
    var companyName = button.getAttribute('data-company-name');
    var balanceRaw = button.getAttribute('data-company-balance') || '0';
    var balanceFormatted = button.getAttribute('data-company-balance-formatted') || balanceRaw;
    var numericBalance = parseFloat(balanceRaw) || 0;
    if (isMobile()) {
        var card = document.getElementById('collectFromShippingCompanyCard');
        if (card) {
            var idInput = document.getElementById('collectCardCompanyId');
            var nameEl = document.getElementById('collectCardCompanyName');
            var debtEl = document.getElementById('collectCardCurrentDebt');
            var amountInput = document.getElementById('collectCardAmount');
            if (idInput) idInput.value = companyId || '';
            if (nameEl) nameEl.textContent = companyName || '-';
            if (debtEl) debtEl.textContent = balanceFormatted;
            if (amountInput) { amountInput.value = ''; amountInput.max = numericBalance; }
            card.style.display = 'block';
            setTimeout(function() { scrollToElement(card); }, 50);
        }
    } else {
        var modal = document.getElementById('collectFromShippingCompanyModal');
        if (modal) {
            var idInput = document.getElementById('collectCompanyId');
            var nameEl = modal.querySelector('.collection-shipping-company-name');
            var debtEl = modal.querySelector('.collection-shipping-current-debt');
            var amountInput = document.getElementById('collectShippingAmount');
            if (idInput) idInput.value = companyId || '';
            if (nameEl) nameEl.textContent = companyName || '-';
            if (debtEl) debtEl.textContent = balanceFormatted;
            if (amountInput) { amountInput.value = ''; amountInput.max = numericBalance; }
            var modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

function closeCollectFromShippingCard() {
    var card = document.getElementById('collectFromShippingCompanyCard');
    if (card) card.style.display = 'none';
}

function closeShippingCompanyStatementCard() {
    var card = document.getElementById('shippingCompanyStatementCard');
    if (card) card.style.display = 'none';
}

function showShippingCompanyStatement(companyId, companyName) {
    closeAllForms();
    var card = document.getElementById('shippingCompanyStatementCard');
    if (!card) return;
    document.getElementById('statementCardCompanyId').value = companyId;
    document.getElementById('statementCardCompanyName').textContent = companyName || '-';
    document.getElementById('statementCardLoading').style.display = 'block';
    document.getElementById('statementCardContent').style.display = 'none';
    document.getElementById('statementCardEmpty').style.display = 'none';
    card.style.display = 'block';
    setTimeout(function() { scrollToElement(card); }, 50);
    var formData = new FormData();
    formData.append('action', 'get_shipping_company_statement');
    formData.append('company_id', companyId);
    fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('statementCardLoading').style.display = 'none';
            if (data.success && data.movements && data.movements.length) {
                var tbody = document.getElementById('statementCardTableBody');
                tbody.innerHTML = '';
                var fmt = function(n) { return (parseFloat(n) || 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
                data.movements.forEach(function(m) {
                    var dateStr = (m.date && m.date.toString().substring) ? m.date.toString().substring(0, 10) : (m.date || '-');
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + dateStr + '</td><td>' + (m.label || '-') + '</td><td class="text-end">' + (m.debit > 0 ? fmt(m.debit) + ' ج.م' : '-') + '</td><td class="text-end">' + (m.credit > 0 ? fmt(m.credit) + ' ج.م' : '-') + '</td><td class="text-end fw-semibold">' + fmt(m.balance_after) + ' ج.م</td>';
                    tbody.appendChild(tr);
                });
                document.getElementById('statementTotalDebit').textContent = fmt(data.totals.total_debit);
                document.getElementById('statementTotalCredit').textContent = fmt(data.totals.total_credit);
                document.getElementById('statementNetBalance').textContent = fmt(data.totals.net_balance);
                document.getElementById('statementCardContent').style.display = 'block';
            } else {
                document.getElementById('statementCardEmpty').style.display = 'block';
            }
        })
        .catch(function() {
            document.getElementById('statementCardLoading').style.display = 'none';
            document.getElementById('statementCardEmpty').innerHTML = '<i class="bi bi-exclamation-triangle fs-1"></i><p class="mb-0">حدث خطأ في تحميل كشف الحساب.</p>';
            document.getElementById('statementCardEmpty').style.display = 'block';
        });
}

function showCompanyPaperInvoicesByIdName(companyId, companyName) {
    var btn = document.createElement('button');
    btn.setAttribute('data-company-id', companyId);
    btn.setAttribute('data-company-name', companyName);
    showCompanyPaperInvoicesCard(btn);
}

function showEditBalanceByIdName(companyId, companyName, balance) {
    var btn = document.createElement('button');
    btn.setAttribute('data-company-id', companyId);
    btn.setAttribute('data-company-name', companyName);
    btn.setAttribute('data-company-balance', balance);
    showEditShippingCompanyBalanceModal(btn);
}

function showCollectByIdName(companyId, companyName, balance, balanceFormatted) {
    var btn = document.createElement('button');
    btn.setAttribute('data-company-id', companyId);
    btn.setAttribute('data-company-name', companyName);
    btn.setAttribute('data-company-balance', balance);
    btn.setAttribute('data-company-balance-formatted', balanceFormatted || balance);
    showCollectFromShippingCompanyModal(btn);
}

function showDeductFromShippingCompany(companyId, companyName, balance) {
    closeAllForms();
    var balanceFormatted = (parseFloat(balance) || 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    if (isMobile()) {
        var card = document.getElementById('deductFromShippingCompanyCard');
        if (card) {
            document.getElementById('deductCardCompanyId').value = companyId;
            document.getElementById('deductCardCompanyName').textContent = companyName || '-';
            document.getElementById('deductCardCurrentDebt').textContent = balanceFormatted;
            var amountInput = document.getElementById('deductCardAmount');
            if (amountInput) { amountInput.value = ''; amountInput.max = balance; }
            document.getElementById('deductCardNotes').value = '';
            card.style.display = 'block';
            setTimeout(function() { scrollToElement(card); }, 50);
        }
    } else {
        var modal = document.getElementById('deductFromShippingCompanyModal');
        if (modal) {
            document.getElementById('deductModalCompanyId').value = companyId;
            document.getElementById('deductModalCompanyName').textContent = companyName || '-';
            document.getElementById('deductModalCurrentDebt').textContent = balanceFormatted;
            var amountInput = document.getElementById('deductModalAmount');
            if (amountInput) { amountInput.value = ''; amountInput.max = balance; }
            document.getElementById('deductModalNotes').value = '';
            var modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

function closeDeductFromShippingCard() {
    var card = document.getElementById('deductFromShippingCompanyCard');
    if (card) card.style.display = 'none';
}

function showDeliveryModal(button) {
    if (!button) return;
    
    closeAllForms();
    
    // نسخ البيانات من data attributes
    const orderId = button.getAttribute('data-order-id');
    const orderNumber = button.getAttribute('data-order-number');
    const customerId = button.getAttribute('data-customer-id');
    const customerName = button.getAttribute('data-customer-name');
    const customerBalance = button.getAttribute('data-customer-balance');
    const totalAmount = button.getAttribute('data-total-amount');
    const shippingCompanyName = button.getAttribute('data-shipping-company-name');
    const companyBalance = button.getAttribute('data-company-balance');
    
    const isMobileDevice = isMobile();
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('deliveryCard');
        const form = document.getElementById('deliveryFormCard');
        if (!card || !form) {
            return;
        }
        
        const orderIdInput = document.getElementById('card_order_id');
        const orderNumberEl = document.getElementById('card_order_number');
        const shippingCompanyEl = document.getElementById('card_shipping_company');
        const companyBalanceEl = document.getElementById('card_company_balance');
        const customerNameEl = document.getElementById('card_customer_name');
        const customerBalanceEl = document.getElementById('card_customer_balance');
        const totalAmountEl = document.getElementById('card_total_amount');
        const collectedAmountInput = document.getElementById('collected_amount_card');
        const balanceWarning = document.getElementById('balance_warning_card');
        const balanceWarningText = document.getElementById('balance_warning_text_card');
        
        if (orderIdInput) orderIdInput.value = orderId || '';
        if (orderNumberEl) orderNumberEl.textContent = '#' + (orderNumber || '');
        if (shippingCompanyEl) shippingCompanyEl.textContent = shippingCompanyName || '';
        if (companyBalanceEl) companyBalanceEl.textContent = (parseFloat(companyBalance) || 0).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
        if (customerNameEl) customerNameEl.textContent = customerName || '';
        if (customerBalanceEl) customerBalanceEl.textContent = (parseFloat(customerBalance) || 0).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
        if (totalAmountEl) totalAmountEl.textContent = (parseFloat(totalAmount) || 0).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
        if (collectedAmountInput) collectedAmountInput.value = '';
        if (balanceWarning) balanceWarning.style.display = 'none';
        
        // إضافة event listener للتحقق من الرصيد
        if (collectedAmountInput && balanceWarning && balanceWarningText) {
            const updateBalanceWarning = function() {
                const collected = parseFloat(collectedAmountInput.value) || 0;
                const customerBal = parseFloat(customerBalance) || 0;
                const total = parseFloat(totalAmount) || 0;
                const newBalance = customerBal + total - collected;
                
                if (newBalance < 0) {
                    balanceWarningText.textContent = 'تحذير: الرصيد الجديد للعميل سيكون سالباً (' + Math.abs(newBalance).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م)';
                    balanceWarning.style.display = 'block';
                } else {
                    balanceWarning.style.display = 'none';
                }
            };
            
            // إزالة الـ listeners القديمة وإضافة جديدة
            const newInput = collectedAmountInput.cloneNode(true);
            collectedAmountInput.parentNode.replaceChild(newInput, collectedAmountInput);
            newInput.addEventListener('input', updateBalanceWarning);
        }
        
        card.style.display = 'block';
        setTimeout(function() {
            scrollToElement(card);
        }, 50);
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('deliveryModal');
        if (modal) {
            // تعيين القيم في Modal (إذا كانت العناصر موجودة)
            const orderIdInput = document.getElementById('modal_order_id');
            const orderNumberEl = document.getElementById('modal_order_number');
            const shippingCompanyEl = document.getElementById('modal_shipping_company');
            const companyBalanceEl = document.getElementById('modal_company_balance');
            const customerNameEl = document.getElementById('modal_customer_name');
            const customerBalanceEl = document.getElementById('modal_customer_balance');
            const totalAmountEl = document.getElementById('modal_total_amount');
            
            if (orderIdInput) orderIdInput.value = orderId || '';
            if (orderNumberEl) orderNumberEl.textContent = '#' + (orderNumber || '');
            if (shippingCompanyEl) shippingCompanyEl.textContent = shippingCompanyName || '';
            if (companyBalanceEl) companyBalanceEl.textContent = (parseFloat(companyBalance) || 0).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
            if (customerNameEl) customerNameEl.textContent = customerName || '';
            if (customerBalanceEl) customerBalanceEl.textContent = (parseFloat(customerBalance) || 0).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
            if (totalAmountEl) totalAmountEl.textContent = (parseFloat(totalAmount) || 0).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

function closeDeliveryCard() {
    const card = document.getElementById('deliveryCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

// ===== دوال إغلاق Cards =====

function closeAddShippingCompanyCard() {
    const card = document.getElementById('addShippingCompanyCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeDeliveryCard() {
    const card = document.getElementById('deliveryCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeAddLocalCustomerCard() {
    const card = document.getElementById('addLocalCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

(function() {
    const products = <?php echo json_encode(array_map(function ($product) {
        return [
            'id' => (int)($product['id'] ?? 0),
            'name' => $product['name'] ?? '',
            'quantity' => (float)($product['quantity'] ?? 0),
            'unit_price' => (float)($product['unit_price'] ?? 0),
            'unit' => $product['unit'] ?? '',
            'batch_id' => isset($product['batch_id']) ? (int)$product['batch_id'] : null,
            'batch_number' => $product['batch_number'] ?? null,
            'product_type' => $product['product_type'] ?? 'external'
        ];
    }, $availableProducts), JSON_UNESCAPED_UNICODE); ?>;

    const itemsBody = document.getElementById('shippingItemsBody');
    const addItemBtn = document.getElementById('addShippingItemBtn');
    const itemsCountEl = document.getElementById('shippingItemsCount');
    const orderTotalEl = document.getElementById('shippingOrderTotal');
    const submitBtn = document.getElementById('submitShippingOrderBtn');

    if (!itemsBody || !addItemBtn) {
        return;
    }

    if (!Array.isArray(products) || !products.length) {
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        return;
    }

    let rowIndex = 0;

    const formatCurrency = (value) => {
        return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP', minimumFractionDigits: 2 }).format(value || 0);
    };

    const escapeHtml = (value) => {
        if (typeof value !== 'string') {
            return '';
        }
        return value.replace(/[&<>"']/g, function (char) {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;';
                default: return char;
            }
        });
    };

    const buildProductOptions = () => {
        return products.map(product => {
            const available = Number(product.quantity || 0).toFixed(2);
            const unitPrice = Number(product.unit_price || 0).toFixed(2);
            const unit = escapeHtml(product.unit || 'وحدة');
            const name = escapeHtml(product.name || '');
            const batchId = product.batch_id || '';
            const productType = product.product_type || 'external';
            return `
                <option value="${product.id}" 
                        data-available="${available}" 
                        data-unit-price="${unitPrice}"
                        data-unit="${unit}"
                        data-batch-id="${batchId}"
                        data-product-type="${productType}">
                    ${name} (المتاح: ${available} ${unit})
                </option>
            `;
        }).join('');
    };

    const recalculateTotals = (skipLineTotalUpdate) => {
        const rows = itemsBody.querySelectorAll('tr');
        let totalItems = 0;
        let totalAmount = 0;

        rows.forEach(row => {
            const quantityInput = row.querySelector('input[name$="[quantity]"]');
            const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');
            const lineTotalInput = row.querySelector('.line-total-input');

            const quantity = parseFloat(quantityInput?.value || '0');
            const unitPrice = parseFloat(unitPriceInput?.value || '0');
            const lineTotal = quantity * unitPrice;

            if (quantity > 0) {
                totalItems += quantity;
            }
            if (lineTotalInput) {
                if (!skipLineTotalUpdate) {
                    lineTotalInput.value = lineTotal > 0 ? lineTotal.toFixed(2) : '';
                }
                totalAmount += parseFloat(lineTotalInput.value || '0') || 0;
            } else {
                totalAmount += lineTotal;
            }
        });

        if (itemsCountEl) {
            itemsCountEl.textContent = totalItems.toLocaleString('ar-EG', { maximumFractionDigits: 2 });
        }
        if (orderTotalEl) {
            orderTotalEl.textContent = formatCurrency(totalAmount);
        }
        const customTotalInput = document.getElementById('customTotalAmount');
        if (customTotalInput && !customTotalInput.dataset.manual) {
            customTotalInput.value = totalAmount > 0 ? totalAmount.toFixed(2) : '';
        }
    };

    const attachRowEvents = (row) => {
        const productSelect = row.querySelector('select[name$="[product_id]"]');
        const quantityInput = row.querySelector('input[name$="[quantity]"]');
        const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');
        const availableBadges = row.querySelectorAll('.available-qty');
        const removeBtn = row.querySelector('.remove-item');

        const updateAvailability = () => {
            const selectedOption = productSelect?.selectedOptions?.[0];
            const available = parseFloat(selectedOption?.dataset?.available || '0');
            const unitPrice = parseFloat(selectedOption?.dataset?.unitPrice || '0');
            const unit = selectedOption?.dataset?.unit || '-';
            const batchId = selectedOption?.dataset?.batchId || '';
            const productType = selectedOption?.dataset?.productType || 'external';

            // تحديث الحقول المخفية
            const batchIdInput = row.querySelector('.batch-id-input');
            const productTypeInput = row.querySelector('.product-type-input');
            if (batchIdInput) {
                batchIdInput.value = batchId;
            }
            if (productTypeInput) {
                productTypeInput.value = productType;
            }

            // تحديث عرض الوحدة حسب المنتج المختار
            const unitLabel = row.querySelector('.unit-label');
            if (unitLabel) {
                unitLabel.textContent = unit;
            }

            if (quantityInput) {
                quantityInput.max = available > 0 ? String(available) : '';
                if (available > 0 && parseFloat(quantityInput.value || '0') > available) {
                    quantityInput.value = available;
                }
            }

            if (unitPriceInput && (!unitPriceInput.value || parseFloat(unitPriceInput.value) <= 0)) {
                unitPriceInput.value = unitPrice.toFixed(2);
            }

            if (availableBadges.length) {
                const message = selectedOption && available > 0
                    ? `المتاح: ${available.toLocaleString('ar-EG')} ${unit}`
                    : 'لا توجد كمية متاحة';
                availableBadges.forEach((badge) => {
                    badge.textContent = message;
                    badge.classList.toggle('text-danger', !(selectedOption && available > 0));
                });
            }

            recalculateTotals();
        };

        const lineTotalInput = row.querySelector('.line-total-input');
        if (lineTotalInput) {
            lineTotalInput.addEventListener('input', function() {
                const qty = parseFloat(quantityInput?.value || '0');
                const totalVal = parseFloat(this.value || '0');
                if (qty > 0 && totalVal >= 0 && unitPriceInput) {
                    unitPriceInput.value = (totalVal / qty).toFixed(2);
                }
                recalculateTotals(true);
            });
        }

        productSelect?.addEventListener('change', updateAvailability);
        quantityInput?.addEventListener('input', recalculateTotals);
        unitPriceInput?.addEventListener('input', recalculateTotals);

        removeBtn?.addEventListener('click', () => {
            if (itemsBody.children.length > 1) {
                row.remove();
                recalculateTotals();
            }
        });

        updateAvailability();
    };

    const addNewRow = () => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <select class="form-select" name="items[${rowIndex}][product_id]" required>
                    <option value="">اختر المنتج</option>
                    ${buildProductOptions()}
                </select>
                <input type="hidden" name="items[${rowIndex}][batch_id]" class="batch-id-input">
                <input type="hidden" name="items[${rowIndex}][product_type]" class="product-type-input">
            </td>
            <td class="text-muted fw-semibold">
                <span class="available-qty d-inline-block">-</span>
            </td>
            <td class="text-muted small row-unit">
                <span class="unit-label">-</span>
            </td>
            <td>
                <input type="number" class="form-control" name="items[${rowIndex}][quantity]" step="any" min="0" value="1" required>
            </td>
            <td>
                <input type="number" class="form-control" name="items[${rowIndex}][unit_price]" step="0.01" min="0" required>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" class="form-control fw-semibold line-total-input" name="items[${rowIndex}][line_total]" step="0.01" min="0" placeholder="0.00" title="الإجمالي = الكمية × سعر الوحدة (قابل للتعديل)">
                    <span class="input-group-text">ج.م</span>
                </div>
            </td>
            <td>
                <button type="button" class="btn btn-outline-danger btn-sm remove-item" title="حذف المنتج">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        itemsBody.appendChild(row);
        attachRowEvents(row);
        rowIndex += 1;
        recalculateTotals();
    };

    addItemBtn.addEventListener('click', () => {
        addNewRow();
    });

    addNewRow();

    // إضافة validation للنموذج
    const shippingOrderForm = document.getElementById('shippingOrderForm');
    if (shippingOrderForm) {
        shippingOrderForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // التحقق من شركة الشحن
            const shippingCompanySelect = shippingOrderForm.querySelector('select[name="shipping_company_id"]');
            if (!shippingCompanySelect || !shippingCompanySelect.value || shippingCompanySelect.value === '') {
                alert('يرجى اختيار شركة الشحن');
                shippingCompanySelect?.focus();
                return false;
            }

            // التحقق من العميل
            const customerSelect = shippingOrderForm.querySelector('select[name="customer_id"]');
            if (!customerSelect || !customerSelect.value || customerSelect.value === '') {
                alert('يرجى اختيار العميل');
                customerSelect?.focus();
                return false;
            }

            // التحقق من المنتجات
            const rows = itemsBody.querySelectorAll('tr');
            let hasValidItems = false;
            const validationErrors = [];

            rows.forEach((row, index) => {
                const productSelect = row.querySelector('select[name$="[product_id]"]');
                const quantityInput = row.querySelector('input[name$="[quantity]"]');
                const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');

                const productId = productSelect?.value || '';
                const quantity = parseFloat(quantityInput?.value || '0');
                const unitPrice = parseFloat(unitPriceInput?.value || '0');

                if (productId && productId !== '') {
                    if (quantity <= 0) {
                        validationErrors.push(`المنتج في الصف ${index + 1}: يرجى إدخال كمية صحيحة`);
                        quantityInput?.classList.add('is-invalid');
                    } else {
                        quantityInput?.classList.remove('is-invalid');
                    }

                    if (unitPrice <= 0) {
                        validationErrors.push(`المنتج في الصف ${index + 1}: يرجى إدخال سعر وحدة صحيح`);
                        unitPriceInput?.classList.add('is-invalid');
                    } else {
                        unitPriceInput?.classList.remove('is-invalid');
                    }

                    // التحقق من الكمية المتاحة
                    const selectedOption = productSelect?.selectedOptions?.[0];
                    const available = parseFloat(selectedOption?.dataset?.available || '0');
                    if (quantity > available) {
                        validationErrors.push(`المنتج في الصف ${index + 1}: الكمية المطلوبة (${quantity}) أكبر من المتاح (${available})`);
                        quantityInput?.classList.add('is-invalid');
                    }

                    if (productId && quantity > 0 && unitPrice > 0 && quantity <= available) {
                        hasValidItems = true;
                    }
                }
            });

            if (!hasValidItems) {
                alert('يرجى إضافة منتج واحد على الأقل مع كمية وسعر صحيحين');
                return false;
            }

            if (validationErrors.length > 0) {
                alert('يرجى تصحيح الأخطاء التالية:\n' + validationErrors.join('\n'));
                return false;
            }

            // إذا تم التحقق من كل شيء، أرسل النموذج
            this.submit();
        });
    }
})();

// نموذج الإلغاء المؤجل (لإظهار بطاقة مبلغ الشحن المخصوم)
let _pendingCancelForm = null;

// دالة معالجة إلغاء الطلب - إظهار بطاقة إدخال مبلغ الشحن المخصوم
function handleCancelOrder(event, form) {
    if (!event) {
        return false;
    }
    event.preventDefault();
    event.stopPropagation();

    const orderNumber = form.getAttribute('data-order-number') || '-';
    const totalAmount = parseFloat(form.getAttribute('data-total-amount') || '0') || 0;
    const totalFormatted = new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP', minimumFractionDigits: 2 }).format(totalAmount);

    document.getElementById('cancelModalOrderNumber').textContent = '#' + orderNumber;
    document.getElementById('cancelModalOrderTotal').textContent = totalFormatted;
    document.getElementById('cancelModalDeductedAmount').value = totalAmount > 0 ? totalAmount.toFixed(2) : '';
    document.getElementById('cancelModalAmountError').textContent = '';
    document.getElementById('cancelModalDeductedAmount').classList.remove('is-invalid');

    _pendingCancelForm = form;
    const cancelModal = new bootstrap.Modal(document.getElementById('cancelOrderModal'));
    cancelModal.show();
    return false;
}

// تأكيد إلغاء الطلب بعد إدخال مبلغ الشحن المخصوم
function confirmCancelOrderWithDeductedAmount() {
    if (!_pendingCancelForm) return;

    const amountInput = document.getElementById('cancelModalDeductedAmount');
    const amount = parseFloat(amountInput.value);
    const errorEl = document.getElementById('cancelModalAmountError');

    if (isNaN(amount) || amount < 0) {
        amountInput.classList.add('is-invalid');
        errorEl.textContent = 'يرجى إدخال مبلغ صحيح (صفر أو أكثر).';
        return;
    }

    amountInput.classList.remove('is-invalid');
    errorEl.textContent = '';

    // إضافة حقل المبلغ المخصوم للنموذج
    let hiddenInput = _pendingCancelForm.querySelector('input[name="deducted_amount"]');
    if (!hiddenInput) {
        hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'deducted_amount';
        _pendingCancelForm.appendChild(hiddenInput);
    }
    hiddenInput.value = amount.toFixed(2);

    const submitBtn = _pendingCancelForm.querySelector('.cancel-order-btn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الإلغاء...';
    }

    bootstrap.Modal.getInstance(document.getElementById('cancelOrderModal')).hide();
    _pendingCancelForm.submit();
    _pendingCancelForm = null;
}
</script>

<!-- Modal لتسليم الطلب -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="deliveryModal" tabindex="-1" aria-labelledby="deliveryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="deliveryModalLabel">
                    <i class="bi bi-check-circle me-2"></i>تأكيد تسليم الطلب
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="deliveryForm">
                <input type="hidden" name="action" value="complete_shipping_order">
                <input type="hidden" name="order_id" id="modal_order_id">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>ملاحظة:</strong> سيتم نقل الدين من شركة الشحن إلى العميل تلقائياً.
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">معلومات الطلب</h6>
                            <p class="mb-1"><strong>رقم الطلب:</strong> <span id="modal_order_number"></span></p>
                            <p class="mb-1"><strong>شركة الشحن:</strong> <span id="modal_shipping_company"></span></p>
                            <p class="mb-1"><strong>دين الشركة الحالي:</strong> <span id="modal_company_balance" class="text-danger"></span></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">معلومات العميل</h6>
                            <p class="mb-1"><strong>اسم العميل:</strong> <span id="modal_customer_name"></span></p>
                            <p class="mb-1"><strong>الرصيد الحالي:</strong> <span id="modal_customer_balance"></span></p>
                            <p class="mb-1"><strong>المبلغ الإجمالي:</strong> <span id="modal_total_amount" class="text-primary fw-bold"></span></p>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label for="collected_amount" class="form-label">
                            <i class="bi bi-cash-coin me-1"></i>المبلغ الذي تم تحصيله من العميل
                            <span class="text-danger">*</span>
                        </label>
                        <input type="number" 
                               class="form-control" 
                               id="collected_amount" 
                               name="collected_amount" 
                               step="0.01" 
                               min="0" 
                               required
                               placeholder="أدخل المبلغ المحصل من العميل">
                        <div class="form-text">
                            سيتم خصم هذا المبلغ من ديون العميل بعد نقل الدين من الشركة.
                        </div>
                    </div>
                    
                    <div class="alert alert-warning" id="balance_warning" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <span id="balance_warning_text"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i>تأكيد التسليم
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- بطاقة سجل الفواتير والفاتورة الورقية (طلبات الشحن - عميل محلي) -->
<div class="card shadow-sm mb-4 border-info" id="shippingInvoiceLogCard" style="display: none;">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="bi bi-receipt me-2"></i>سجل الفواتير والفاتورة الورقية - <span id="shippingInvoiceLogCustomerName">-</span>
        </h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeShippingInvoiceLogCard()" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
        <input type="hidden" id="shippingInvoiceLogCustomerId" value="">
        <input type="hidden" id="shippingInvoiceLogIsLocal" value="1">
        <div class="mb-3" id="shippingAddPaperInvoiceWrap">
            <button type="button" class="btn btn-primary btn-sm" id="shippingAddPaperInvoiceBtn" onclick="toggleShippingPaperInvoiceCard()">
                <i class="bi bi-plus-lg me-1"></i>إضافة فاتورة ورقية
            </button>
            <button type="button" class="btn btn-warning btn-sm ms-2" id="shippingAddPaperInvoiceReturnBtn" onclick="toggleShippingPaperInvoiceReturnCard()">
                <i class="bi bi-arrow-return-left me-1"></i>مرتجع من فاتورة ورقية
            </button>
        </div>
        <!-- بطاقة إضافة فاتورة ورقية (عميل محلي) -->
        <div class="card mb-3 border-primary collapse" id="shippingPaperInvoiceCard">
            <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-receipt-cutoff me-2"></i>إضافة فاتورة ورقية</span>
                <button type="button" class="btn btn-sm btn-light" onclick="toggleShippingPaperInvoiceCard()"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="card-body">
                <p class="text-muted small">رفع صورة الفاتورة الورقية وإدخال الإجمالي. سيُضاف المبلغ كرصيد دائن للعميل ويُسجّل في سجل الفواتير.</p>
                <input type="hidden" id="shippingPaperInvoiceCustomerId" value="">
                <div class="mb-3">
                    <label class="form-label">صورة الفاتورة <span class="text-danger">*</span></label>
                    <input type="file" id="shippingPaperInvoiceImageInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp">
                    <div id="shippingPaperInvoiceImagePreview" class="mt-2 text-center" style="display: none;">
                        <img id="shippingPaperInvoicePreviewImg" src="" alt="معاينة" class="img-fluid rounded border" style="max-height: 180px;">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">رقم الفاتورة <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="shippingPaperInvoiceNumber" placeholder="أدخل رقم الفاتورة">
                </div>
                <div class="mb-3">
                    <label class="form-label">إجمالي الفاتورة (ج.م) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="shippingPaperInvoiceTotal" placeholder="0.00">
                </div>
                <div id="shippingPaperInvoiceMessage" class="alert d-none mb-0"></div>
                <button type="button" class="btn btn-primary" id="shippingPaperInvoiceSubmitBtn" onclick="submitShippingPaperInvoice()">
                    <i class="bi bi-check-lg me-1"></i>حفظ وإضافة للرصيد الدائن
                </button>
            </div>
        </div>
        <!-- بطاقة مرتجع من فاتورة ورقية (عميل محلي) -->
        <div class="card mb-3 border-warning collapse" id="shippingPaperInvoiceReturnCard">
            <div class="card-header bg-warning text-dark py-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-arrow-return-left me-2"></i>مرتجع من فاتورة ورقية</span>
                <button type="button" class="btn btn-sm btn-dark" onclick="toggleShippingPaperInvoiceReturnCard()"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="card-body">
                <p class="text-muted small">رفع صورة الفاتورة/المرتجع وإدخال رقم الفاتورة ومبلغ المرتجع. يُخصم المبلغ من الرصيد المدين؛ إن زاد عن الدين يتحول الفرق إلى رصيد دائن.</p>
                <input type="hidden" id="shippingPaperInvoiceReturnCustomerId" value="">
                <div class="mb-3">
                    <label class="form-label">صورة الفاتورة / المرتجع <span class="text-danger">*</span></label>
                    <input type="file" id="shippingPaperInvoiceReturnImageInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp">
                    <div id="shippingPaperInvoiceReturnImagePreview" class="mt-2 text-center" style="display: none;">
                        <img id="shippingPaperInvoiceReturnPreviewImg" src="" alt="معاينة" class="img-fluid rounded border" style="max-height: 180px;">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">رقم الفاتورة <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="shippingPaperInvoiceReturnNumber" placeholder="رقم الفاتورة المرتجعة">
                </div>
                <div class="mb-3">
                    <label class="form-label">مبلغ المرتجع (ج.م) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="shippingPaperInvoiceReturnAmount" placeholder="0.00">
                </div>
                <div id="shippingPaperInvoiceReturnMessage" class="alert d-none mb-0"></div>
                <button type="button" class="btn btn-warning" id="shippingPaperInvoiceReturnSubmitBtn" onclick="submitShippingPaperInvoiceReturn()">
                    <i class="bi bi-check-lg me-1"></i>حفظ وخصم من الرصيد
                </button>
            </div>
        </div>
        <div id="shippingInvoiceLogLoading" class="text-center py-4 text-muted d-none">
            <div class="spinner-border" role="status"></div>
            <p class="mt-2 mb-0">جاري تحميل سجل الفواتير...</p>
        </div>
        <div class="table-responsive" id="shippingInvoiceLogTableWrap" style="display: none;">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>الإجمالي</th>
                        <th>التاريخ</th>
                        <th>الإجراء</th>
                    </tr>
                </thead>
                <tbody id="shippingInvoiceLogTableBody"></tbody>
            </table>
        </div>
        <nav id="shippingInvoiceLogPaginationWrap" class="mt-2 d-flex flex-wrap justify-content-between align-items-center gap-2" style="display: none !important;" aria-label="تقسيم سجل الفواتير">
            <div class="text-muted small" id="shippingInvoiceLogPaginationInfo"></div>
            <ul class="pagination pagination-sm mb-0 flex-wrap" id="shippingInvoiceLogPagination"></ul>
        </nav>
        <div id="shippingInvoiceLogEmpty" class="text-center py-4 text-muted" style="display: none;">
            <i class="bi bi-inbox fs-1"></i>
            <p class="mb-0" id="shippingInvoiceLogEmptyText">لا توجد فواتير مسجلة لهذا العميل بعد.</p>
        </div>
    </div>
</div>

<!-- بطاقة عرض صورة الفاتورة الورقية (طلبات الشحن) -->
<div class="card shadow-sm mb-4 border-secondary" id="shippingPaperInvoiceViewCard" style="display: none;">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">عرض الفاتورة الورقية</h5>
        <button type="button" class="btn btn-sm btn-light" onclick="closeShippingPaperInvoiceViewCard()" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body d-flex align-items-center justify-content-center overflow-auto p-2" style="min-height: 300px; max-height: 85vh;">
        <img id="shippingPaperInvoiceViewImg" src="" alt="صورة الفاتورة" style="max-width: 100%; max-height: 82vh; width: auto; height: auto; object-fit: contain;">
    </div>
</div>

<!-- بطاقة عرض صورة مرتجع الفاتورة الورقية (طلبات الشحن) -->
<div class="card shadow-sm mb-4 border-warning" id="shippingPaperInvoiceReturnViewCard" style="display: none;">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <h5 class="mb-0">عرض صورة مرتجع الفاتورة الورقية</h5>
        <button type="button" class="btn btn-sm btn-dark" onclick="closeShippingPaperInvoiceReturnViewCard()" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body text-center p-0">
        <img id="shippingPaperInvoiceReturnViewImg" src="" alt="صورة المرتجع" class="img-fluid" style="max-height: 80vh;">
    </div>
</div>

<script>
var shippingPurchaseHistoryUrl = <?php echo json_encode(getRelativeUrl('api/customer_purchase_history.php')); ?>;
var shippingPaperInvoiceUrl = <?php echo json_encode(getRelativeUrl('api/local_paper_invoice.php')); ?>;
var shippingPaperInvoiceReturnUrl = <?php echo json_encode(getRelativeUrl('api/local_paper_invoice_return.php')); ?>;
var companyPaperInvoiceUrl = <?php echo json_encode(getRelativeUrl('api/shipping_company_paper_invoice.php')); ?>;

function showCompanyPaperInvoicesCard(button) {
    var companyId = button.getAttribute('data-company-id');
    var companyName = button.getAttribute('data-company-name') || 'شركة الشحن';
    if (!companyId) return;
    document.getElementById('companyPaperInvoicesCompanyId').value = companyId;
    document.getElementById('companyPaperInvoicesCompanyName').textContent = companyName;
    document.getElementById('companyPaperInvoicesTableWrap').style.display = 'none';
    document.getElementById('companyPaperInvoicesEmpty').style.display = 'none';
    document.getElementById('companyPaperInvoicesLoading').classList.remove('d-none');
    var cardEl = document.getElementById('companyPaperInvoicesCard');
    if (cardEl) {
        cardEl.style.display = '';
        cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    var addCard = document.getElementById('companyPaperInvoiceAddCard');
    if (addCard && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getInstance(addCard);
        if (col) col.hide();
    }
    loadCompanyPaperInvoices(companyId);
}

function closeCompanyPaperInvoicesCard() {
    var cardEl = document.getElementById('companyPaperInvoicesCard');
    if (cardEl) cardEl.style.display = 'none';
}

function toggleCompanyPaperInvoiceAddCard() {
    var addCard = document.getElementById('companyPaperInvoiceAddCard');
    if (!addCard) return;
    var companyIdEl = document.getElementById('companyPaperInvoicesCompanyId');
    if (companyIdEl) document.getElementById('companyPaperInvoiceAddCompanyId').value = companyIdEl.value;
    if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getOrCreateInstance(addCard, { toggle: true });
        col.toggle();
    } else {
        addCard.classList.toggle('show');
        addCard.style.display = addCard.classList.contains('show') ? 'block' : 'none';
    }
}

function loadCompanyPaperInvoices(companyId) {
    var url = companyPaperInvoiceUrl + (companyPaperInvoiceUrl.indexOf('?') >= 0 ? '&' : '?') + 'action=list&shipping_company_id=' + encodeURIComponent(companyId);
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('companyPaperInvoicesLoading').classList.add('d-none');
            if (data.success) {
                var list = data.paper_invoices || [];
                displayCompanyPaperInvoices(list);
            } else {
                document.getElementById('companyPaperInvoicesEmpty').style.display = 'block';
                document.getElementById('companyPaperInvoicesEmpty').querySelector('p').textContent = data.message || 'حدث خطأ في تحميل السجل.';
            }
        })
        .catch(function() {
            document.getElementById('companyPaperInvoicesLoading').classList.add('d-none');
            document.getElementById('companyPaperInvoicesEmpty').style.display = 'block';
            document.getElementById('companyPaperInvoicesEmpty').querySelector('p').textContent = 'حدث خطأ في الاتصال بالخادم.';
        });
}

function displayCompanyPaperInvoices(list) {
    var tbody = document.getElementById('companyPaperInvoicesTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!list || list.length === 0) {
        document.getElementById('companyPaperInvoicesEmpty').style.display = 'block';
        document.getElementById('companyPaperInvoicesPaginationWrap').style.display = 'none';
        return;
    }
    window._companyPaperInvoicesFullList = list;
    window._companyPaperInvoicesPage = 1;
    window._companyPaperInvoicesPerPage = 15;
    goToCompanyPaperInvoicesPage(1);
}

function goToCompanyPaperInvoicesPage(page) {
    var list = window._companyPaperInvoicesFullList;
    if (!list || !list.length) return;
    var perPage = window._companyPaperInvoicesPerPage || 15;
    var totalPages = Math.ceil(list.length / perPage);
    page = Math.max(1, Math.min(page, totalPages));
    window._companyPaperInvoicesPage = page;
    var start = (page - 1) * perPage;
    var slice = list.slice(start, start + perPage);
    var tbody = document.getElementById('companyPaperInvoicesTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';
    slice.forEach(function(pi) {
        var safeNum = (pi.invoice_number || 'ورقية-' + pi.id).replace(/</g, '&lt;').replace(/>/g, '&gt;');
        var dateStr = (pi.created_at || '-').toString().substring(0, 10);
        var viewBtn = pi.image_path
            ? '<button type="button" class="btn btn-sm btn-outline-primary" onclick="showCompanyPaperInvoiceImage(' + parseInt(pi.id, 10) + ')" title="عرض صورة الفاتورة"><i class="bi bi-image me-1"></i>عرض الفاتورة</button>'
            : '<span class="text-muted small">لا توجد صورة</span>';
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + safeNum + '</td><td>' + parseFloat(pi.total_amount || 0).toFixed(2) + ' ج.م</td><td>' + dateStr + '</td><td>' + viewBtn + '</td>';
        tbody.appendChild(tr);
    });
    document.getElementById('companyPaperInvoicesTableWrap').style.display = 'block';
    var wrap = document.getElementById('companyPaperInvoicesPaginationWrap');
    if (totalPages <= 1) {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = 'flex';
    var from = start + 1;
    var to = Math.min(start + perPage, list.length);
    document.getElementById('companyPaperInvoicesPaginationInfo').textContent = 'عرض ' + from + '-' + to + ' من ' + list.length;
    var ul = document.getElementById('companyPaperInvoicesPagination');
    ul.innerHTML = '';
    var prevLi = document.createElement('li');
    prevLi.className = 'page-item' + (page <= 1 ? ' disabled' : '');
    prevLi.innerHTML = '<a class="page-link" href="#" onclick="goToCompanyPaperInvoicesPage(' + (page - 1) + '); return false;" aria-label="السابق"><i class="bi bi-chevron-right"></i></a>';
    ul.appendChild(prevLi);
    var maxVisible = 5;
    var fromPage = Math.max(1, page - Math.floor(maxVisible / 2));
    var toPage = Math.min(totalPages, fromPage + maxVisible - 1);
    if (toPage - fromPage < maxVisible - 1) fromPage = Math.max(1, toPage - maxVisible + 1);
    for (var p = fromPage; p <= toPage; p++) {
        var li = document.createElement('li');
        li.className = 'page-item' + (p === page ? ' active' : '');
        li.innerHTML = '<a class="page-link" href="#" onclick="goToCompanyPaperInvoicesPage(' + p + '); return false;">' + p + '</a>';
        ul.appendChild(li);
    }
    var nextLi = document.createElement('li');
    nextLi.className = 'page-item' + (page >= totalPages ? ' disabled' : '');
    nextLi.innerHTML = '<a class="page-link" href="#" onclick="goToCompanyPaperInvoicesPage(' + (page + 1) + '); return false;" aria-label="التالي"><i class="bi bi-chevron-left"></i></a>';
    ul.appendChild(nextLi);
}

function openCompanyPaperInvoiceForm() {
    var companyId = document.getElementById('companyPaperInvoicesCompanyId').value;
    if (!companyId) return;
    document.getElementById('companyPaperInvoiceAddCompanyId').value = companyId;
    document.getElementById('companyPaperInvoiceAddNumber').value = '';
    document.getElementById('companyPaperInvoiceAddTotal').value = '';
    document.getElementById('companyPaperInvoiceAddImageInput').value = '';
    document.getElementById('companyPaperInvoiceAddImagePreview').style.display = 'none';
    document.getElementById('companyPaperInvoiceAddMessage').classList.add('d-none');
    var addCard = document.getElementById('companyPaperInvoiceAddCard');
    if (addCard && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getOrCreateInstance(addCard);
        col.show();
    }
}

function submitCompanyPaperInvoice() {
    var companyId = document.getElementById('companyPaperInvoiceAddCompanyId').value;
    var invoiceNumber = (document.getElementById('companyPaperInvoiceAddNumber').value || '').trim();
    var total = (document.getElementById('companyPaperInvoiceAddTotal').value || '').replace(',', '.').trim();
    var fileInput = document.getElementById('companyPaperInvoiceAddImageInput');
    var file = fileInput && fileInput.files && fileInput.files[0];
    var msgEl = document.getElementById('companyPaperInvoiceAddMessage');
    var submitBtn = document.getElementById('companyPaperInvoiceAddSubmitBtn');
    if (!companyId) { alert('لم يتم تحديد الشركة'); return; }
    if (!invoiceNumber) { alert('يرجى إدخال رقم الفاتورة'); return; }
    var totalNum = parseFloat(total);
    if (total === '' || isNaN(totalNum) || totalNum === 0) { alert('يرجى إدخال إجمالي صحيح (موجب أو سالب، غير صفر)'); return; }
    if (!file) { alert('يرجى اختيار صورة الفاتورة الورقية'); return; }
    if (msgEl) { msgEl.classList.add('d-none'); msgEl.innerHTML = ''; }
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...'; }
    var formData = new FormData();
    formData.append('action', 'save');
    formData.append('shipping_company_id', companyId);
    formData.append('invoice_number', invoiceNumber);
    formData.append('total_amount', total);
    formData.append('image', file);
    fetch(companyPaperInvoiceUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (msgEl) {
                msgEl.classList.remove('d-none');
                msgEl.className = data.success ? 'alert alert-success mb-0' : 'alert alert-danger mb-0';
                msgEl.textContent = data.message || (data.success ? 'تم الحفظ.' : 'حدث خطأ.');
            }
            if (data.success) {
                loadCompanyPaperInvoices(companyId);
                var addCard = document.getElementById('companyPaperInvoiceAddCard');
                if (addCard && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                    var col = bootstrap.Collapse.getInstance(addCard);
                    if (col) setTimeout(function() { col.hide(); }, 1200);
                }
            }
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ'; }
        })
        .catch(function() {
            if (msgEl) { msgEl.classList.remove('d-none'); msgEl.className = 'alert alert-danger mb-0'; msgEl.textContent = 'حدث خطأ في الاتصال.'; }
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ'; }
        });
}

function showCompanyPaperInvoiceImage(paperInvoiceId) {
    if (!paperInvoiceId) return;
    var imgUrl = companyPaperInvoiceUrl + (companyPaperInvoiceUrl.indexOf('?') >= 0 ? '&' : '?') + 'action=view_image&id=' + encodeURIComponent(paperInvoiceId);
    document.getElementById('shippingPaperInvoiceViewImg').src = imgUrl;
    var cardEl = document.getElementById('shippingPaperInvoiceViewCard');
    if (cardEl) {
        cardEl.style.display = '';
        cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function closeShippingPaperInvoiceViewCard() {
    var cardEl = document.getElementById('shippingPaperInvoiceViewCard');
    if (cardEl) cardEl.style.display = 'none';
}

function showShippingInvoiceLogModal(button) {
    var customerId = button.getAttribute('data-customer-id');
    var customerName = button.getAttribute('data-customer-name') || 'غير محدد';
    var isLocal = parseInt(button.getAttribute('data-is-local') || '1', 10) !== 0;
    if (!customerId) return;
    document.getElementById('shippingInvoiceLogCustomerId').value = customerId;
    document.getElementById('shippingInvoiceLogCustomerName').textContent = customerName;
    document.getElementById('shippingInvoiceLogIsLocal').value = isLocal ? '1' : '0';
    var addWrap = document.getElementById('shippingAddPaperInvoiceWrap');
    if (addWrap) addWrap.style.display = isLocal ? '' : 'none';
    document.getElementById('shippingInvoiceLogTableWrap').style.display = 'none';
    document.getElementById('shippingInvoiceLogEmpty').style.display = 'none';
    document.getElementById('shippingInvoiceLogLoading').classList.remove('d-none');
    var cardEl = document.getElementById('shippingInvoiceLogCard');
    if (cardEl) {
        cardEl.style.display = '';
        cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    var paperCard = document.getElementById('shippingPaperInvoiceCard');
    var returnCard = document.getElementById('shippingPaperInvoiceReturnCard');
    if (paperCard && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getInstance(paperCard);
        if (col) col.hide();
    }
    if (returnCard && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getInstance(returnCard);
        if (col) col.hide();
    }
    loadShippingInvoiceLog(customerId, isLocal);
}

function closeShippingInvoiceLogCard() {
    var cardEl = document.getElementById('shippingInvoiceLogCard');
    if (cardEl) cardEl.style.display = 'none';
}

function toggleShippingPaperInvoiceCard() {
    var cardEl = document.getElementById('shippingPaperInvoiceCard');
    if (!cardEl) return;
    var customerIdEl = document.getElementById('shippingInvoiceLogCustomerId');
    if (customerIdEl) document.getElementById('shippingPaperInvoiceCustomerId').value = customerIdEl.value;
    if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getOrCreateInstance(cardEl, { toggle: true });
        col.toggle();
    } else {
        cardEl.classList.toggle('show');
        cardEl.style.display = cardEl.classList.contains('show') ? 'block' : 'none';
    }
}

function toggleShippingPaperInvoiceReturnCard() {
    var cardEl = document.getElementById('shippingPaperInvoiceReturnCard');
    if (!cardEl) return;
    var customerIdEl = document.getElementById('shippingInvoiceLogCustomerId');
    if (customerIdEl) document.getElementById('shippingPaperInvoiceReturnCustomerId').value = customerIdEl.value;
    if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getOrCreateInstance(cardEl, { toggle: true });
        col.toggle();
    } else {
        cardEl.classList.toggle('show');
        cardEl.style.display = cardEl.classList.contains('show') ? 'block' : 'none';
    }
}

function loadShippingInvoiceLog(customerId, isLocal) {
    isLocal = isLocal !== false;
    var url = shippingPurchaseHistoryUrl + (shippingPurchaseHistoryUrl.indexOf('?') >= 0 ? '&' : '?') + 'action=get_history&customer_id=' + encodeURIComponent(customerId) + '&type=local';
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('shippingInvoiceLogLoading').classList.add('d-none');
            var emptyText = document.getElementById('shippingInvoiceLogEmptyText');
            if (data.success) {
                var history = data.purchase_history || [];
                var paperInvoices = data.paper_invoices || [];
                var paperInvoiceReturns = data.paper_invoice_returns || [];
                displayShippingInvoiceLog(history, paperInvoices, paperInvoiceReturns);
            } else {
                document.getElementById('shippingInvoiceLogEmpty').style.display = 'block';
                if (emptyText) emptyText.textContent = !isLocal ? 'العميل غير مسجل كعميل محلي. سجل الفواتير والفاتورة الورقية متاح للعملاء المحليين فقط.' : (data.message || 'حدث خطأ في تحميل السجل.');
            }
        })
        .catch(function() {
            document.getElementById('shippingInvoiceLogLoading').classList.add('d-none');
            document.getElementById('shippingInvoiceLogEmpty').style.display = 'block';
            var emptyText = document.getElementById('shippingInvoiceLogEmptyText');
            if (emptyText) emptyText.textContent = 'حدث خطأ في الاتصال بالخادم.';
        });
}

function groupShippingHistoryByInvoice(history) {
    if (!history || !history.length) return [];
    var byInvoice = {};
    history.forEach(function(item) {
        var key = item.invoice_id;
        if (!byInvoice[key]) {
            byInvoice[key] = {
                invoice_id: item.invoice_id,
                invoice_number: item.invoice_number || '-',
                invoice_date: item.invoice_date || '-',
                total_amount: 0,
                items: []
            };
        }
        byInvoice[key].total_amount += parseFloat(item.total_price || 0);
        byInvoice[key].items.push(item);
    });
    return Object.values(byInvoice).sort(function(a, b) {
        var d1 = (a.invoice_date || '').replace(/-/g, '');
        var d2 = (b.invoice_date || '').replace(/-/g, '');
        return d2.localeCompare(d1) || (b.invoice_id - a.invoice_id);
    });
}

function displayShippingInvoiceLog(history, paperInvoices, paperInvoiceReturns) {
    paperInvoices = paperInvoices || [];
    paperInvoiceReturns = paperInvoiceReturns || [];
    var tbody = document.getElementById('shippingInvoiceLogTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';
    var invoices = groupShippingHistoryByInvoice(history);
    var rows = [];
    invoices.forEach(function(inv) {
        var safeNum = (inv.invoice_number || '-').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        rows.push('<td>' + safeNum + '</td><td>' + parseFloat(inv.total_amount || 0).toFixed(2) + ' ج.م</td><td>' + (inv.invoice_date || '-') + '</td><td><span class="text-muted small">فاتورة نظام</span></td>');
    });
    paperInvoices.forEach(function(pi) {
        var safeNum = (pi.invoice_number || 'ورقية-' + pi.id).replace(/</g, '&lt;').replace(/>/g, '&gt;');
        var dateStr = (pi.invoice_date || pi.created_at || '-').toString().substring(0, 10);
        var viewBtn = pi.image_path
            ? '<button type="button" class="btn btn-sm btn-outline-primary" onclick="showShippingPaperInvoiceImage(' + parseInt(pi.id, 10) + ')" title="عرض صورة الفاتورة"><i class="bi bi-image me-1"></i>عرض الفاتورة</button>'
            : '<span class="text-muted small">لا توجد صورة</span>';
        rows.push('<td>' + safeNum + '</td><td>' + parseFloat(pi.total_amount || 0).toFixed(2) + ' ج.م</td><td>' + dateStr + '</td><td>' + viewBtn + '</td>');
    });
    paperInvoiceReturns.forEach(function(pr) {
        var safeNum = ('مرتجع ورقية - ' + (pr.invoice_number || pr.id)).replace(/</g, '&lt;').replace(/>/g, '&gt;');
        var dateStr = (pr.return_date || pr.created_at || '-').toString().substring(0, 10);
        var viewBtn = pr.image_path
            ? '<button type="button" class="btn btn-sm btn-outline-warning" onclick="showShippingPaperInvoiceReturnImage(' + parseInt(pr.id, 10) + ')" title="عرض صورة المرتجع"><i class="bi bi-image me-1"></i>عرض المرتجع</button>'
            : '<span class="text-muted small">لا توجد صورة</span>';
        rows.push('<td>' + safeNum + '</td><td class="text-warning">-' + parseFloat(pr.return_amount || 0).toFixed(2) + ' ج.م</td><td>' + dateStr + '</td><td>' + viewBtn + '</td>');
    });
    var hasAny = rows.length > 0;
    var isLocal = document.getElementById('shippingInvoiceLogIsLocal');
    isLocal = isLocal ? (parseInt(isLocal.value, 10) !== 0) : true;
    if (!hasAny) {
        document.getElementById('shippingInvoiceLogEmpty').style.display = 'block';
        document.getElementById('shippingInvoiceLogPaginationWrap').style.display = 'none';
        var emptyText = document.getElementById('shippingInvoiceLogEmptyText');
        if (emptyText && !isLocal) emptyText.textContent = 'العميل غير مسجل كعميل محلي. سجل الفواتير والفاتورة الورقية متاح للعملاء المحليين فقط.';
        return;
    }
    window._shippingInvoiceLogRows = rows;
    window._shippingInvoiceLogPage = 1;
    window._shippingInvoiceLogPerPage = 15;
    document.getElementById('shippingInvoiceLogTableWrap').style.display = 'block';
    goToShippingInvoiceLogPage(1);
}

function goToShippingInvoiceLogPage(page) {
    var rows = window._shippingInvoiceLogRows;
    if (!rows || !rows.length) return;
    var perPage = window._shippingInvoiceLogPerPage || 15;
    var totalPages = Math.ceil(rows.length / perPage);
    page = Math.max(1, Math.min(page, totalPages));
    window._shippingInvoiceLogPage = page;
    var start = (page - 1) * perPage;
    var slice = rows.slice(start, start + perPage);
    var tbody = document.getElementById('shippingInvoiceLogTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';
    slice.forEach(function(rowHtml) {
        var tr = document.createElement('tr');
        tr.innerHTML = rowHtml;
        tbody.appendChild(tr);
    });
    var wrap = document.getElementById('shippingInvoiceLogPaginationWrap');
    if (totalPages <= 1) {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = 'flex';
    var from = start + 1;
    var to = Math.min(start + perPage, rows.length);
    document.getElementById('shippingInvoiceLogPaginationInfo').textContent = 'عرض ' + from + '-' + to + ' من ' + rows.length;
    var ul = document.getElementById('shippingInvoiceLogPagination');
    ul.innerHTML = '';
    var prevLi = document.createElement('li');
    prevLi.className = 'page-item' + (page <= 1 ? ' disabled' : '');
    prevLi.innerHTML = '<a class="page-link" href="#" onclick="goToShippingInvoiceLogPage(' + (page - 1) + '); return false;" aria-label="السابق"><i class="bi bi-chevron-right"></i></a>';
    ul.appendChild(prevLi);
    var maxVisible = 5;
    var fromPage = Math.max(1, page - Math.floor(maxVisible / 2));
    var toPage = Math.min(totalPages, fromPage + maxVisible - 1);
    if (toPage - fromPage < maxVisible - 1) fromPage = Math.max(1, toPage - maxVisible + 1);
    for (var p = fromPage; p <= toPage; p++) {
        var li = document.createElement('li');
        li.className = 'page-item' + (p === page ? ' active' : '');
        li.innerHTML = '<a class="page-link" href="#" onclick="goToShippingInvoiceLogPage(' + p + '); return false;">' + p + '</a>';
        ul.appendChild(li);
    }
    var nextLi = document.createElement('li');
    nextLi.className = 'page-item' + (page >= totalPages ? ' disabled' : '');
    nextLi.innerHTML = '<a class="page-link" href="#" onclick="goToShippingInvoiceLogPage(' + (page + 1) + '); return false;" aria-label="التالي"><i class="bi bi-chevron-left"></i></a>';
    ul.appendChild(nextLi);
}

function openShippingPaperInvoiceForm() {
    var customerId = document.getElementById('shippingInvoiceLogCustomerId').value;
    if (!customerId) return;
    document.getElementById('shippingPaperInvoiceCustomerId').value = customerId;
    document.getElementById('shippingPaperInvoiceNumber').value = '';
    document.getElementById('shippingPaperInvoiceTotal').value = '';
    document.getElementById('shippingPaperInvoiceImageInput').value = '';
    document.getElementById('shippingPaperInvoiceImagePreview').style.display = 'none';
    document.getElementById('shippingPaperInvoiceMessage').classList.add('d-none');
    var cardEl = document.getElementById('shippingPaperInvoiceCard');
    if (cardEl && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getOrCreateInstance(cardEl);
        col.show();
    }
}

function submitShippingPaperInvoice() {
    var customerId = document.getElementById('shippingPaperInvoiceCustomerId').value;
    var invoiceNumber = (document.getElementById('shippingPaperInvoiceNumber').value || '').trim();
    var total = (document.getElementById('shippingPaperInvoiceTotal').value || '').replace(',', '.').trim();
    var fileInput = document.getElementById('shippingPaperInvoiceImageInput');
    var file = fileInput && fileInput.files && fileInput.files[0];
    var msgEl = document.getElementById('shippingPaperInvoiceMessage');
    var submitBtn = document.getElementById('shippingPaperInvoiceSubmitBtn');
    if (!customerId) { alert('لم يتم تحديد العميل'); return; }
    if (!invoiceNumber) { alert('يرجى إدخال رقم الفاتورة'); return; }
    if (!total || isNaN(parseFloat(total)) || parseFloat(total) <= 0) { alert('يرجى إدخال إجمالي صحيح'); return; }
    if (!file) { alert('يرجى اختيار صورة الفاتورة الورقية'); return; }
    if (msgEl) { msgEl.classList.add('d-none'); msgEl.innerHTML = ''; }
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...'; }
    var formData = new FormData();
    formData.append('action', 'save');
    formData.append('customer_id', customerId);
    formData.append('invoice_number', invoiceNumber);
    formData.append('total_amount', total);
    formData.append('image', file);
    fetch(shippingPaperInvoiceUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (msgEl) {
                msgEl.classList.remove('d-none');
                msgEl.className = data.success ? 'alert alert-success mb-0' : 'alert alert-danger mb-0';
                msgEl.textContent = data.message || (data.success ? 'تم الحفظ.' : 'حدث خطأ.');
            }
            if (data.success) {
                document.getElementById('shippingPaperInvoiceNumber').value = '';
                document.getElementById('shippingPaperInvoiceTotal').value = '';
                fileInput.value = '';
                document.getElementById('shippingPaperInvoiceImagePreview').style.display = 'none';
                var logCustomerId = document.getElementById('shippingInvoiceLogCustomerId').value;
                loadShippingInvoiceLog(logCustomerId);
                var cardEl = document.getElementById('shippingPaperInvoiceCard');
                if (cardEl && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                    var col = bootstrap.Collapse.getInstance(cardEl);
                    if (col) setTimeout(function() { col.hide(); }, 1200);
                }
            }
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ وإضافة للرصيد الدائن'; }
        })
        .catch(function() {
            if (msgEl) { msgEl.classList.remove('d-none'); msgEl.className = 'alert alert-danger mb-0'; msgEl.textContent = 'حدث خطأ في الاتصال.'; }
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ وإضافة للرصيد الدائن'; }
        });
}

function showShippingPaperInvoiceImage(paperInvoiceId) {
    if (!paperInvoiceId) return;
    var imgUrl = shippingPaperInvoiceUrl + (shippingPaperInvoiceUrl.indexOf('?') >= 0 ? '&' : '?') + 'action=view_image&id=' + encodeURIComponent(paperInvoiceId);
    document.getElementById('shippingPaperInvoiceViewImg').src = imgUrl;
    var cardEl = document.getElementById('shippingPaperInvoiceViewCard');
    if (cardEl) {
        cardEl.style.display = '';
        cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function openShippingPaperInvoiceReturnForm() {
    var customerId = document.getElementById('shippingInvoiceLogCustomerId').value;
    if (!customerId) return;
    document.getElementById('shippingPaperInvoiceReturnCustomerId').value = customerId;
    document.getElementById('shippingPaperInvoiceReturnNumber').value = '';
    document.getElementById('shippingPaperInvoiceReturnAmount').value = '';
    document.getElementById('shippingPaperInvoiceReturnImageInput').value = '';
    document.getElementById('shippingPaperInvoiceReturnImagePreview').style.display = 'none';
    document.getElementById('shippingPaperInvoiceReturnMessage').classList.add('d-none');
    var cardEl = document.getElementById('shippingPaperInvoiceReturnCard');
    if (cardEl && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        var col = bootstrap.Collapse.getOrCreateInstance(cardEl);
        col.show();
    }
}

function submitShippingPaperInvoiceReturn() {
    var customerId = document.getElementById('shippingPaperInvoiceReturnCustomerId').value;
    var invoiceNumber = (document.getElementById('shippingPaperInvoiceReturnNumber').value || '').trim();
    var returnAmount = (document.getElementById('shippingPaperInvoiceReturnAmount').value || '').replace(',', '.').trim();
    var fileInput = document.getElementById('shippingPaperInvoiceReturnImageInput');
    var file = fileInput && fileInput.files && fileInput.files[0];
    var msgEl = document.getElementById('shippingPaperInvoiceReturnMessage');
    var submitBtn = document.getElementById('shippingPaperInvoiceReturnSubmitBtn');
    if (!customerId) { alert('لم يتم تحديد العميل'); return; }
    if (!invoiceNumber) { alert('يرجى إدخال رقم الفاتورة'); return; }
    if (!returnAmount || isNaN(parseFloat(returnAmount)) || parseFloat(returnAmount) <= 0) { alert('يرجى إدخال مبلغ مرتجع صحيح'); return; }
    if (!file) { alert('يرجى اختيار صورة الفاتورة/المرتجع'); return; }
    if (msgEl) { msgEl.classList.add('d-none'); msgEl.innerHTML = ''; }
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...'; }
    var formData = new FormData();
    formData.append('action', 'save');
    formData.append('customer_id', customerId);
    formData.append('invoice_number', invoiceNumber);
    formData.append('return_amount', returnAmount);
    formData.append('image', file);
    fetch(shippingPaperInvoiceReturnUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (msgEl) {
                msgEl.classList.remove('d-none');
                msgEl.className = data.success ? 'alert alert-success mb-0' : 'alert alert-danger mb-0';
                msgEl.textContent = data.message || (data.success ? 'تم الحفظ.' : 'حدث خطأ.');
            }
            if (data.success) {
                document.getElementById('shippingPaperInvoiceReturnNumber').value = '';
                document.getElementById('shippingPaperInvoiceReturnAmount').value = '';
                fileInput.value = '';
                document.getElementById('shippingPaperInvoiceReturnImagePreview').style.display = 'none';
                var logCustomerId = document.getElementById('shippingInvoiceLogCustomerId').value;
                var isLocalEl = document.getElementById('shippingInvoiceLogIsLocal');
                var isLocal = !isLocalEl || parseInt(isLocalEl.value, 10) !== 0;
                loadShippingInvoiceLog(logCustomerId, isLocal);
                var returnCardEl = document.getElementById('shippingPaperInvoiceReturnCard');
                if (returnCardEl && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                    var col = bootstrap.Collapse.getInstance(returnCardEl);
                    if (col) setTimeout(function() { col.hide(); }, 1200);
                }
            }
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ وخصم من الرصيد'; }
        })
        .catch(function() {
            if (msgEl) { msgEl.classList.remove('d-none'); msgEl.className = 'alert alert-danger mb-0'; msgEl.textContent = 'حدث خطأ في الاتصال.'; }
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ وخصم من الرصيد'; }
        });
}

function showShippingPaperInvoiceReturnImage(returnId) {
    if (!returnId) return;
    var imgUrl = shippingPaperInvoiceReturnUrl + (shippingPaperInvoiceReturnUrl.indexOf('?') >= 0 ? '&' : '?') + 'action=view_image&id=' + encodeURIComponent(returnId);
    document.getElementById('shippingPaperInvoiceReturnViewImg').src = imgUrl;
    var cardEl = document.getElementById('shippingPaperInvoiceReturnViewCard');
    if (cardEl) {
        cardEl.style.display = '';
        cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function closeShippingPaperInvoiceReturnViewCard() {
    var cardEl = document.getElementById('shippingPaperInvoiceReturnViewCard');
    if (cardEl) cardEl.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    var companyPaperInvoiceAddImageInput = document.getElementById('companyPaperInvoiceAddImageInput');
    if (companyPaperInvoiceAddImageInput) {
        companyPaperInvoiceAddImageInput.addEventListener('change', function() {
            var file = this.files && this.files[0];
            var preview = document.getElementById('companyPaperInvoiceAddImagePreview');
            var previewImg = document.getElementById('companyPaperInvoiceAddPreviewImg');
            if (!file || !preview || !previewImg) return;
            var reader = new FileReader();
            reader.onload = function(e) { previewImg.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(file);
        });
    }
    var shippingPaperInvoiceImageInput = document.getElementById('shippingPaperInvoiceImageInput');
    if (shippingPaperInvoiceImageInput) {
        shippingPaperInvoiceImageInput.addEventListener('change', function() {
            var file = this.files && this.files[0];
            var preview = document.getElementById('shippingPaperInvoiceImagePreview');
            var previewImg = document.getElementById('shippingPaperInvoicePreviewImg');
            if (!file || !preview || !previewImg) return;
            var reader = new FileReader();
            reader.onload = function(e) { previewImg.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(file);
        });
    }
    var shippingPaperInvoiceReturnImageInput = document.getElementById('shippingPaperInvoiceReturnImageInput');
    if (shippingPaperInvoiceReturnImageInput) {
        shippingPaperInvoiceReturnImageInput.addEventListener('change', function() {
            var file = this.files && this.files[0];
            var preview = document.getElementById('shippingPaperInvoiceReturnImagePreview');
            var previewImg = document.getElementById('shippingPaperInvoiceReturnPreviewImg');
            if (!file || !preview || !previewImg) return;
            var reader = new FileReader();
            reader.onload = function(e) { previewImg.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(file);
        });
    }
});
</script>

<script>
(function() {
    'use strict';

    function showShippingToast(message, type) {
        type = type || 'success';
        var container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }
        var id = 'toast-' + Date.now();
        var bg = type === 'success' ? 'bg-success' : 'bg-danger';
        var safeMsg = (message || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
        var html = '<div id="' + id + '" class="toast align-items-center text-white ' + bg + ' border-0" role="alert" data-bs-autohide="true" data-bs-delay="6000"><div class="d-flex"><div class="toast-body">' + safeMsg + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>';
        container.insertAdjacentHTML('beforeend', html);
        var el = document.getElementById(id);
        if (el && typeof bootstrap !== 'undefined' && bootstrap.Toast) {
            var t = new bootstrap.Toast(el);
            t.show();
            el.addEventListener('hidden.bs.toast', function() { el.remove(); });
        }
    }

    const deliveryModal = document.getElementById('deliveryModal');
    const deliveryForm = document.getElementById('deliveryForm');
    const collectedAmountInput = document.getElementById('collected_amount');
    const balanceWarning = document.getElementById('balance_warning');
    const balanceWarningText = document.getElementById('balance_warning_text');
    
    if (deliveryModal) {
        deliveryModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const orderId = button.getAttribute('data-order-id');
            const orderNumber = button.getAttribute('data-order-number');
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name');
            const customerBalance = parseFloat(button.getAttribute('data-customer-balance') || 0);
            const totalAmount = parseFloat(button.getAttribute('data-total-amount') || 0);
            const shippingCompanyName = button.getAttribute('data-shipping-company-name');
            const companyBalance = parseFloat(button.getAttribute('data-company-balance') || 0);
            
            // تعبئة البيانات في الـ modal
            document.getElementById('modal_order_id').value = orderId;
            document.getElementById('modal_order_number').textContent = '#' + orderNumber;
            document.getElementById('modal_shipping_company').textContent = shippingCompanyName;
            document.getElementById('modal_company_balance').textContent = companyBalance.toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
            document.getElementById('modal_customer_name').textContent = customerName;
            document.getElementById('modal_customer_balance').textContent = customerBalance.toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
            document.getElementById('modal_total_amount').textContent = totalAmount.toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
            
            // تعيين القيمة الافتراضية للمبلغ المحصل (يمكن أن يكون 0 أو المبلغ الكامل)
            collectedAmountInput.value = '';
            collectedAmountInput.max = totalAmount;
            balanceWarning.style.display = 'none';
        });
        
        // التحقق من المبلغ المحصل عند الإدخال
        if (collectedAmountInput) {
            collectedAmountInput.addEventListener('input', function() {
                const collectedAmount = parseFloat(this.value) || 0;
                const totalAmount = parseFloat(document.getElementById('modal_total_amount').textContent.replace(/[^\d.-]/g, '')) || 0;
                const customerBalance = parseFloat(document.getElementById('modal_customer_balance').textContent.replace(/[^\d.-]/g, '')) || 0;
                const newCustomerDebt = customerBalance + totalAmount - collectedAmount;
                
                if (collectedAmount > totalAmount) {
                    balanceWarningText.textContent = 'المبلغ المحصل أكبر من المبلغ الإجمالي للطلب!';
                    balanceWarning.className = 'alert alert-danger';
                    balanceWarning.style.display = 'block';
                } else if (collectedAmount > 0) {
                    balanceWarningText.textContent = 'بعد التسليم، سيكون رصيد العميل: ' + 
                        newCustomerDebt.toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
                    balanceWarning.className = 'alert alert-info';
                    balanceWarning.style.display = 'block';
                } else {
                    balanceWarning.style.display = 'none';
                }
            });
        }
        
        // تنظيف البيانات عند إغلاق الـ modal
        deliveryModal.addEventListener('hidden.bs.modal', function() {
            collectedAmountInput.value = '';
            balanceWarning.style.display = 'none';
        });
    }

    if (deliveryForm) {
        deliveryForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(deliveryForm);
            var submitBtn = deliveryForm.querySelector('button[type="submit"]');
            var orderId = formData.get('order_id');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...'; }
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تأكيد التسليم'; }
                    if (data.success) {
                        var msg = data.message || 'تم تأكيد التسليم بنجاح.';
                        if (data.collected_amount != null && parseFloat(data.collected_amount) > 0) {
                            msg += ' المبلغ المحصل: ' + parseFloat(data.collected_amount).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م.';
                        }
                        if (data.remaining_balance != null) {
                            msg += ' المبلغ المتبقي على العميل: ' + parseFloat(data.remaining_balance).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م.';
                        }
                        showShippingToast(msg, 'success');
                        if (deliveryModal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            var m = bootstrap.Modal.getInstance(deliveryModal);
                            if (m) m.hide();
                        }
                        var btn = document.querySelector('button.delivery-btn[data-order-id="' + (data.order_id || orderId) + '"]');
                        if (btn && btn.closest('tr')) btn.closest('tr').remove();
                    } else {
                        showShippingToast(data.error || 'حدث خطأ.', 'danger');
                    }
                })
                .catch(function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تأكيد التسليم'; }
                    showShippingToast('حدث خطأ في الاتصال.', 'danger');
                });
            return false;
        });
    }

    var deliveryFormCard = document.getElementById('deliveryFormCard');
    if (deliveryFormCard) {
        deliveryFormCard.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(deliveryFormCard);
            var submitBtn = deliveryFormCard.querySelector('button[type="submit"]');
            var orderId = formData.get('order_id');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...'; }
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تأكيد التسليم'; }
                    if (data.success) {
                        var msg = data.message || 'تم تأكيد التسليم بنجاح.';
                        if (data.collected_amount != null && parseFloat(data.collected_amount) > 0) {
                            msg += ' المبلغ المحصل: ' + parseFloat(data.collected_amount).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م.';
                        }
                        if (data.remaining_balance != null) {
                            msg += ' المبلغ المتبقي على العميل: ' + parseFloat(data.remaining_balance).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م.';
                        }
                        showShippingToast(msg, 'success');
                        var card = document.getElementById('deliveryCard');
                        if (card) { card.style.display = 'none'; if (deliveryFormCard.reset) deliveryFormCard.reset(); }
                        var btn = document.querySelector('button.delivery-btn[data-order-id="' + (data.order_id || orderId) + '"]');
                        if (btn && btn.closest('tr')) btn.closest('tr').remove();
                    } else {
                        showShippingToast(data.error || 'حدث خطأ.', 'danger');
                    }
                })
                .catch(function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تأكيد التسليم'; }
                    showShippingToast('حدث خطأ في الاتصال.', 'danger');
                });
            return false;
        });
    }

    var collectModal = document.getElementById('collectFromShippingCompanyModal');
    var collectModalForm = collectModal ? collectModal.querySelector('form') : null;
    if (collectModalForm) {
        collectModalForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(collectModalForm);
            var submitBtn = collectModalForm.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التحصيل...'; }
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'تحصيل المبلغ'; }
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    if (data.success) {
                        var msg = data.message || 'تم التحصيل بنجاح.';
                        if (data.amount_collected != null) {
                            msg += ' المبلغ المحصل: ' + parseFloat(data.amount_collected).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م.';
                        }
                        if (data.new_balance != null) {
                            msg += ' المبلغ المتبقي (ديون الشركة): ' + parseFloat(data.new_balance).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م.';
                        }
                        showShippingToast(msg, 'success');
                        var debtEl = collectModal.querySelector('.collection-shipping-current-debt');
                        if (debtEl && data.new_balance != null) debtEl.textContent = parseFloat(data.new_balance).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            var m = bootstrap.Modal.getInstance(collectModal);
                            if (m) m.hide();
                        }
                    } else {
                        showShippingToast(data.error || 'حدث خطأ.', 'danger');
                    }
                })
                .catch(function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'تحصيل المبلغ'; }
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    showShippingToast('حدث خطأ في الاتصال.', 'danger');
                });
            return false;
        });
    }

    var collectCard = document.getElementById('collectFromShippingCompanyCard');
    var collectCardForm = collectCard ? collectCard.querySelector('form') : null;
    if (collectCardForm) {
        collectCardForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(collectCardForm);
            var submitBtn = collectCardForm.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التحصيل...'; }
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'تحصيل المبلغ'; }
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    if (data.success) {
                        var msg = data.message || 'تم التحصيل بنجاح.';
                        if (data.amount_collected != null) {
                            msg += ' المبلغ المحصل: ' + parseFloat(data.amount_collected).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م.';
                        }
                        if (data.new_balance != null) {
                            msg += ' المبلغ المتبقي (ديون الشركة): ' + parseFloat(data.new_balance).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م.';
                        }
                        showShippingToast(msg, 'success');
                        var debtEl = document.getElementById('collectCardCurrentDebt');
                        if (debtEl && data.new_balance != null) debtEl.textContent = parseFloat(data.new_balance).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
                        if (collectCard) { collectCard.style.display = 'none'; if (collectCardForm.reset) collectCardForm.reset(); }
                    } else {
                        showShippingToast(data.error || 'حدث خطأ.', 'danger');
                    }
                })
                .catch(function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'تحصيل المبلغ'; }
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    showShippingToast('حدث خطأ في الاتصال.', 'danger');
                });
            return false;
        });
    }

    var deductModalForm = document.getElementById('deductShippingCompanyFormModal');
    if (deductModalForm) {
        deductModalForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(deductModalForm);
            var submitBtn = deductModalForm.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الخصم...'; }
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'تنفيذ الخصم'; }
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    if (data.success) {
                        showShippingToast(data.message || 'تم خصم المبلغ بنجاح.', 'success');
                        var deductModal = document.getElementById('deductFromShippingCompanyModal');
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal && deductModal) {
                            var m = bootstrap.Modal.getInstance(deductModal);
                            if (m) m.hide();
                        }
                        if (data.new_balance != null) {
                            var debtEl = document.getElementById('deductModalCurrentDebt');
                            if (debtEl) debtEl.textContent = parseFloat(data.new_balance).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
                        }
                        if (window.location && window.location.reload) window.location.reload();
                    } else {
                        showShippingToast(data.error || 'حدث خطأ.', 'danger');
                    }
                })
                .catch(function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'تنفيذ الخصم'; }
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    showShippingToast('حدث خطأ في الاتصال.', 'danger');
                });
            return false;
        });
    }

    var deductCardForm = document.getElementById('deductShippingCompanyFormCard');
    if (deductCardForm) {
        deductCardForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(deductCardForm);
            var submitBtn = deductCardForm.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الخصم...'; }
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'تنفيذ الخصم'; }
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    if (data.success) {
                        showShippingToast(data.message || 'تم خصم المبلغ بنجاح.', 'success');
                        closeDeductFromShippingCard();
                        if (window.location && window.location.reload) window.location.reload();
                    } else {
                        showShippingToast(data.error || 'حدث خطأ.', 'danger');
                    }
                })
                .catch(function() {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'تنفيذ الخصم'; }
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    showShippingToast('حدث خطأ في الاتصال.', 'danger');
                });
            return false;
        });
    }
    
    // معالجة إضافة عميل جديد
    const addLocalCustomerModal = document.getElementById('addLocalCustomerModal');
    const addLocalCustomerForm = document.getElementById('addLocalCustomerForm');
    const customerSelect = document.getElementById('customerSelect');
    
    if (addLocalCustomerModal && addLocalCustomerForm && customerSelect) {
        // عند إغلاق الـ modal بعد إضافة عميل جديد، تحديث القائمة واختيار العميل الجديد
        addLocalCustomerModal.addEventListener('hidden.bs.modal', function() {
            <?php if (!empty($_SESSION['new_customer_id'])): ?>
                const newCustomerId = <?php echo (int)$_SESSION['new_customer_id']; ?>;
                const newCustomerName = <?php echo json_encode($_SESSION['new_customer_name'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
                const newCustomerPhone = <?php echo json_encode($_SESSION['new_customer_phone'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
                
                // إضافة العميل الجديد إلى القائمة
                const option = document.createElement('option');
                option.value = newCustomerId;
                option.selected = true;
                let optionText = newCustomerName;
                if (newCustomerPhone) {
                    optionText += ' - ' + newCustomerPhone;
                }
                option.textContent = optionText;
                customerSelect.appendChild(option);
                
                // مسح البيانات من الجلسة
                <?php 
                unset($_SESSION['new_customer_id']);
                unset($_SESSION['new_customer_name']);
                unset($_SESSION['new_customer_phone']);
                ?>
            <?php endif; ?>
            
            // تنظيف النموذج
            addLocalCustomerForm.reset();
        });
    }
    
    // Real-time Search functionality
    const searchInput = document.getElementById('orderSearchInput');
    const searchResultsContainer = document.getElementById('searchResultsContainer');
    const ordersTabsContainer = document.getElementById('ordersTabsContainer');
    const searchResultsBody = document.getElementById('searchResultsBody');
    let searchTimeout = null;

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            if (query.length === 0) {
                searchResultsContainer.style.display = 'none';
                ordersTabsContainer.style.display = 'block';
                return;
            }

            searchTimeout = setTimeout(function() {
                // Show loading state if needed
                
                const formData = new FormData();
                formData.append('action', 'search_orders');
                formData.append('query', query);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.html) {
                        searchResultsBody.innerHTML = data.html;
                        ordersTabsContainer.style.display = 'none';
                        searchResultsContainer.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error searching orders:', error);
                });
            }, 300); // Debounce delay
        });
    }

})();
</script>

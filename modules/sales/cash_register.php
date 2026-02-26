<?php
/**
 * صفحة خزنة المندوب - عرض التفاصيل المالية
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
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager', 'developer']);

$currentUser = getCurrentUser();
$isSalesUser = isset($currentUser['role']) && $currentUser['role'] === 'sales';
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

// إذا كان المستخدم مندوب، عرض فقط بياناته
$salesRepId = $isSalesUser ? $currentUser['id'] : (isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : null);

// معالجة إضافة رصيد مباشر للخزنة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_cash_balance') {
    // التحقق من طلب AJAX
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    // إذا كان طلب AJAX، ابدأ output buffering فوراً لالتقاط أي output غير مرغوب فيه
    if ($isAjax) {
        // تنظيف أي buffers موجودة وبدء buffer جديد
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        ob_start();
    }
    
    // تهيئة المتغيرات
    $newTotalCashAdditions = 0.0;
    $lastAddition = null;
    
    // التأكد من أن المستخدم مندوب مبيعات فقط
    if (!$isSalesUser) {
        $error = 'غير مصرح لك بإضافة رصيد للخزنة';
    } else {
        $amount = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        if ($amount <= 0) {
            $error = 'يجب إدخال مبلغ صحيح أكبر من الصفر';
        } else {
            try {
                // التحقق من وجود الجدول مرة واحدة فقط باستخدام cache في session
                // لتجنب فحص الجدول في كل طلب POST
                if (!isset($_SESSION['cash_register_table_checked'])) {
                    $tableExists = $db->queryOne("SHOW TABLES LIKE 'cash_register_additions'");
                    if (empty($tableExists)) {
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `cash_register_additions` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `sales_rep_id` int(11) NOT NULL,
                              `amount` decimal(15,2) NOT NULL,
                              `description` text DEFAULT NULL,
                              `created_by` int(11) NOT NULL,
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              KEY `sales_rep_id` (`sales_rep_id`),
                              KEY `created_at` (`created_at`),
                              CONSTRAINT `cash_register_additions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                              CONSTRAINT `cash_register_additions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                    $_SESSION['cash_register_table_checked'] = true;
                }
                
                // إضافة الرصيد مباشرة بدون فحص الجدول
                $db->execute(
                    "INSERT INTO cash_register_additions (sales_rep_id, amount, description, created_by) VALUES (?, ?, ?, ?)",
                    [$currentUser['id'], $amount, $description ?: null, $currentUser['id']]
                );
                
                $insertId = $db->getLastInsertId();
                
                // تسجيل في سجل التدقيق (غير متزامن لتسريع الاستجابة)
                try {
                    logAudit($currentUser['id'], 'add_cash_balance', 'cash_register_addition', $insertId, null, [
                        'amount' => $amount,
                        'description' => $description
                    ]);
                } catch (Throwable $auditError) {
                    error_log('Error logging audit for cash addition: ' . $auditError->getMessage());
                }
                
                // حساب الرصيد الجديد مباشرة بدون إعادة تحميل الصفحة
                $cashAdditionsTableExists = $db->queryOne("SHOW TABLES LIKE 'cash_register_additions'");
                if (!empty($cashAdditionsTableExists)) {
                    $additionsResult = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total_additions
                         FROM cash_register_additions
                         WHERE sales_rep_id = ?",
                        [$currentUser['id']]
                    );
                    $newTotalCashAdditions = (float)($additionsResult['total_additions'] ?? 0);
                }
                
                // جلب آخر إضافة للعرض في الجدول
                $lastAddition = $db->queryOne(
                    "SELECT 
                        cra.id,
                        cra.amount,
                        cra.description,
                        cra.created_at,
                        cra.created_by,
                        u.username as created_by_username,
                        u.full_name as created_by_name
                     FROM cash_register_additions cra
                     LEFT JOIN users u ON cra.created_by = u.id
                     WHERE cra.id = ?",
                    [$insertId]
                );
                
                $success = 'تم إضافة الرصيد إلى الخزنة بنجاح';
            } catch (Throwable $e) {
                error_log('Error adding cash balance: ' . $e->getMessage());
                $error = 'حدث خطأ في إضافة الرصيد. يرجى المحاولة لاحقاً.';
            }
        }
    }
    
    // إذا كان طلب AJAX، إرجاع JSON مع البيانات المحدثة
    if ($isAjax) {
        // بدء output buffering لالتقاط أي output غير مرغوب فيه
        if (ob_get_level() == 0) {
            ob_start();
        }
        
        // تنظيف جميع output buffers الموجودة بشكل آمن
        $safetyCounter = 0;
        while (ob_get_level() > 0 && $safetyCounter < 20) {
            $level = ob_get_level();
            if (@ob_end_clean() === false) {
                break;
            }
            // التحقق من أن المستوى انخفض فعلاً
            if (ob_get_level() >= $level) {
                break;
            }
            $safetyCounter++;
        }
        
        // بدء buffer جديد نظيف
        ob_start();
        
        // التأكد من عدم إرسال headers مسبقاً
        if (!headers_sent()) {
            // إلغاء أي headers تم إرسالها مسبقاً
            if (function_exists('header_remove')) {
                @header_remove();
            }
            
            http_response_code(!empty($success) ? 200 : 400);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            // منع التخزين المؤقت للاستجابة
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        
        // إعداد الاستجابة JSON
        if (!empty($success)) {
            $response = [
                'success' => true, 
                'message' => $success,
                'newTotalCashAdditions' => $newTotalCashAdditions,
                'lastAddition' => $lastAddition
            ];
        } else {
            $response = [
                'success' => false, 
                'message' => $error ?: 'حدث خطأ غير معروف'
            ];
        }
        
        // تنظيف أي output غير مرغوب فيه
        ob_clean();
        
        // إرسال JSON فقط
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // إرسال output وإيقاف buffering
        ob_end_flush();
        
        // إنهاء التنفيذ فوراً
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        exit; // إيقاف تنفيذ الكود تماماً للطلبات AJAX
    }
    
    // إعادة التوجيه لتجنب إعادة إرسال النموذج (للطلبات العادية فقط - غير AJAX)
    // هذا الكود لن ينفذ أبداً للطلبات AJAX لأننا خرجنا بالفعل
    if (!empty($success)) {
        $_SESSION['success'] = $success;
    }
    if (!empty($error)) {
        $_SESSION['error'] = $error;
    }
    
    // التحقق من أن headers لم يتم إرسالها بعد
    if (!headers_sent()) {
        $redirectUrl = $_SERVER['PHP_SELF'] . '?page=cash_register';
        if ($salesRepId && !$isSalesUser) {
            $redirectUrl .= '&sales_rep_id=' . $salesRepId;
        }
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        // إذا تم إرسال headers بالفعل، فقط نترك الصفحة تكمل (سيتم عرض الرسائل عبر applyPRGPattern)
        // لا نحتاج لإعادة توجيه لأن POST قد تم معالجته مسبقاً في dashboard/sales.php
    }
}

if (!$salesRepId) {
    $error = 'يجب تحديد مندوب المبيعات';
    $salesRepId = $currentUser['id'];
}

// التحقق من وجود الجداول
$invoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'invoices'");
$collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
$salesTableExists = $db->queryOne("SHOW TABLES LIKE 'sales'");

// التحقق من وجود عمود paid_from_credit وإضافته إذا لم يكن موجوداً
$hasPaidFromCreditColumn = false;
if (!empty($invoicesTableExists)) {
    $hasPaidFromCreditColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'paid_from_credit'"));
    
    if (!$hasPaidFromCreditColumn) {
        try {
            $db->execute("ALTER TABLE invoices ADD COLUMN paid_from_credit TINYINT(1) DEFAULT 0 AFTER status");
            $hasPaidFromCreditColumn = true;
            error_log('Added paid_from_credit column to invoices table');
        } catch (Throwable $e) {
            error_log('Error adding paid_from_credit column: ' . $e->getMessage());
        }
    }
    
    // التحقق بأثر رجعي من الفواتير المدفوعة بالكامل وتحديثها إذا كانت مدفوعة من رصيد دائن
    // تحسين الأداء: تنفيذ هذا التحقق مرة واحدة فقط لكل مندوب باستخدام flag في session
    // لتجنب إعادة تشغيل هذا المنطق الثقيل في كل تحميل للصفحة
    if ($hasPaidFromCreditColumn) {
        $retroCheckKey = 'retro_credit_check_' . $salesRepId;
        // التحقق من أن هذا التحقق لم يتم تنفيذه من قبل في هذه الجلسة
        if (!isset($_SESSION[$retroCheckKey])) {
            try {
                // جلب الفواتير المدفوعة بالكامل التي لم يتم تحديدها كمدفوعة من رصيد دائن
                // تحديد حد أقصى للفواتير للتحقق منها لتسريع العملية
                $invoicesToCheck = $db->query(
                    "SELECT i.id, i.customer_id, i.date, i.total_amount, i.paid_amount, i.status, i.paid_from_credit
                     FROM invoices i
                     WHERE i.sales_rep_id = ?
                     AND i.status = 'paid'
                     AND i.paid_amount >= i.total_amount
                     AND i.status != 'cancelled'
                     AND (i.paid_from_credit IS NULL OR i.paid_from_credit = 0)
                     ORDER BY i.date ASC, i.id ASC
                     LIMIT 100",
                    [$salesRepId]
                );
                
                $updatedCount = 0;
                foreach ($invoicesToCheck as $invoice) {
                    $customerId = (int)$invoice['customer_id'];
                    $invoiceDate = $invoice['date'];
                    $invoiceId = (int)$invoice['id'];
                    $invoiceTotal = (float)$invoice['total_amount'];
                    
                    // حساب الرصيد التراكمي للعميل قبل هذه الفاتورة
                    // نحسب الرصيد من جميع الفواتير السابقة لهذا العميل (بترتيب زمني)
                    $previousInvoices = $db->query(
                        "SELECT id, date, total_amount, paid_amount, status, paid_from_credit
                         FROM invoices
                         WHERE customer_id = ?
                         AND (date < ? OR (date = ? AND id < ?))
                         AND status != 'cancelled'
                         ORDER BY date ASC, id ASC",
                        [$customerId, $invoiceDate, $invoiceDate, $invoiceId]
                    );
                    
                    // حساب الرصيد التراكمي قبل هذه الفاتورة
                    // نبدأ من رصيد العميل الحالي ونرجع للخلف
                    $currentCustomer = $db->queryOne(
                        "SELECT balance FROM customers WHERE id = ?",
                        [$customerId]
                    );
                    $currentBalance = (float)($currentCustomer['balance'] ?? 0);
                    
                    // حساب الرصيد قبل هذه الفاتورة عن طريق طرح تأثير الفواتير اللاحقة
                    $balanceBeforeInvoice = $currentBalance;
                    
                    // جلب جميع الفواتير بعد هذه الفاتورة (بترتيب زمني عكسي)
                    $laterInvoices = $db->query(
                        "SELECT id, date, total_amount, paid_amount, status, paid_from_credit
                         FROM invoices
                         WHERE customer_id = ?
                         AND (date > ? OR (date = ? AND id > ?))
                         AND status != 'cancelled'
                         ORDER BY date ASC, id ASC",
                        [$customerId, $invoiceDate, $invoiceDate, $invoiceId]
                    );
                    
                    // طرح تأثير الفواتير اللاحقة من الرصيد الحالي
                    foreach ($laterInvoices as $laterInv) {
                        $laterTotal = (float)($laterInv['total_amount'] ?? 0);
                        $laterPaid = (float)($laterInv['paid_amount'] ?? 0);
                        $laterCreditUsed = (int)($laterInv['paid_from_credit'] ?? 0);
                        
                        if ($laterCreditUsed) {
                            // إذا كانت الفاتورة اللاحقة مدفوعة من رصيد دائن، لا نطرحها
                            continue;
                        }
                        
                        // طرح الفرق بين المبلغ الإجمالي والمبلغ المدفوع من الرصيد
                        $balanceBeforeInvoice -= ($laterTotal - $laterPaid);
                    }
                    
                    // طرح تأثير هذه الفاتورة نفسها
                    $balanceBeforeInvoice -= ($invoiceTotal - (float)($invoice['paid_amount'] ?? 0));
                    
                    // إذا كان الرصيد قبل الفاتورة سالب (رصيد دائن) وكانت الفاتورة مدفوعة بالكامل
                    // فهذا يعني أنها دفعت من الرصيد الدائن
                    if ($balanceBeforeInvoice < -0.01 && $invoiceTotal > 0.01) {
                        // التحقق من أن الفاتورة استهلكت الرصيد الدائن
                        // الرصيد المتوقع بعد الفاتورة = الرصيد قبل + قيمة الفاتورة
                        $expectedBalanceAfter = $balanceBeforeInvoice + $invoiceTotal;
                        
                        // إذا كان الرصيد المتوقع قريب من الرصيد الفعلي (مع هامش خطأ صغير)
                        // فهذا يعني أن الفاتورة دفعت من رصيد دائن
                        if (abs($expectedBalanceAfter - $currentBalance) < 0.02) {
                            // التحقق من عدم تحديث فواتير نقطة البيع
                            $hasCreatedFromPosColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'created_from_pos'"));
                            $canUpdate = true;
                            if ($hasCreatedFromPosColumn) {
                                $invoiceCheck = $db->queryOne("SELECT created_from_pos FROM invoices WHERE id = ?", [$invoiceId]);
                                if (!empty($invoiceCheck) && !empty($invoiceCheck['created_from_pos'])) {
                                    // هذه فاتورة من نقطة البيع، لا يمكن تحديثها
                                    $canUpdate = false;
                                }
                            }
                            
                            if ($canUpdate) {
                                // تحديث الفاتورة لتحديدها كمدفوعة من رصيد دائن
                                $db->execute(
                                    "UPDATE invoices SET paid_from_credit = 1 WHERE id = ?",
                                    [$invoiceId]
                                );
                                $updatedCount++;
                            }
                        }
                    }
                }
                
                if ($updatedCount > 0) {
                    error_log("Retroactively updated $updatedCount invoices as paid_from_credit for sales rep $salesRepId");
                }
                
                // تعيين flag لتجنب إعادة تشغيل هذا التحقق في نفس الجلسة
                $_SESSION[$retroCheckKey] = true;
            } catch (Throwable $retroCheckError) {
                error_log('Error in retroactive credit check: ' . $retroCheckError->getMessage());
            }
        }
    }
}

// حساب إجمالي المبيعات من الفواتير
// استخدام total_amount دائماً لعرض إجمالي المبيعات الكامل بغض النظر عن الرصيد الدائن
$totalSalesFromInvoices = 0.0;
if (!empty($invoicesTableExists)) {
    // استخدام total_amount دائماً لعرض إجمالي المبيعات الكامل
    $salesResult = $db->queryOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total_sales
         FROM invoices
         WHERE sales_rep_id = ? AND status != 'cancelled'",
        [$salesRepId]
    );
    $totalSalesFromInvoices = (float)($salesResult['total_sales'] ?? 0);
}

// حساب إجمالي المبيعات من جدول sales
$totalSalesFromSalesTable = 0.0;
if (!empty($salesTableExists)) {
    $salesTableResult = $db->queryOne(
        "SELECT COALESCE(SUM(total), 0) as total_sales
         FROM sales
         WHERE salesperson_id = ? AND status IN ('approved', 'completed')",
        [$salesRepId]
    );
    $totalSalesFromSalesTable = (float)($salesTableResult['total_sales'] ?? 0);
}

// إجمالي المبيعات (نستخدم الفواتير إذا كانت موجودة، وإلا نستخدم جدول sales)
$totalSales = $totalSalesFromInvoices > 0 ? $totalSalesFromInvoices : $totalSalesFromSalesTable;

// حساب إجمالي المرتجعات للمندوب
$totalReturns = 0.0;
$returnsTableExists = $db->queryOne("SHOW TABLES LIKE 'returns'");
if (!empty($returnsTableExists)) {
    try {
        // حساب المرتجعات المعتمدة والمعالجة فقط
        $returnsResult = $db->queryOne(
            "SELECT COALESCE(SUM(refund_amount), 0) as total_returns
             FROM returns
             WHERE sales_rep_id = ? 
               AND status IN ('approved', 'processed')
               AND refund_amount > 0",
            [$salesRepId]
        );
        $totalReturns = (float)($returnsResult['total_returns'] ?? 0);
    } catch (Throwable $returnsError) {
        error_log('Returns calculation error: ' . $returnsError->getMessage());
        $totalReturns = 0.0;
    }
}

// حساب صافي المبيعات (إجمالي المبيعات - المرتجعات)
$netSales = $totalSales - $totalReturns;

// حساب إجمالي التحصيلات
$totalCollections = 0.0;
if (!empty($collectionsTableExists)) {
    // التحقق من وجود عمود status
    $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasStatusColumn = !empty($statusColumnCheck);
    
    if ($hasStatusColumn) {
        // حساب جميع التحصيلات (pending و approved) لأن المندوب قام بتحصيلها بالفعل
        $collectionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections
             FROM collections
             WHERE collected_by = ? AND status IN ('pending', 'approved')",
            [$salesRepId]
        );
    } else {
        $collectionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections
             FROM collections
             WHERE collected_by = ?",
            [$salesRepId]
        );
    }
    $totalCollections = (float)($collectionsResult['total_collections'] ?? 0);
}

// حساب المبيعات المدفوعة بالكامل (من الفواتير)
// استخدام amount_added_to_sales إذا كان موجوداً (للفواتير المدفوعة من الرصيد الدائن)
// أو total_amount للفواتير العادية
// ملاحظة مهمة: نستبعد الفواتير التي تم تسجيلها بالفعل في جدول collections
// لأنها موجودة في إجمالي التحصيلات ($totalCollections) ولا يجب حسابها مرتين
$fullyPaidSales = 0.0;
if (!empty($invoicesTableExists) && !empty($collectionsTableExists)) {
    // التحقق من وجود عمود amount_added_to_sales
    $hasAmountAddedToSalesColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'amount_added_to_sales'"));
    
    // التحقق من وجود عمود invoice_id في collections
    $hasInvoiceIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'invoice_id'"));
    
    if ($hasAmountAddedToSalesColumn) {
        // التحقق من وجود عمود credit_used
        $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
        
        // استخدام amount_added_to_sales إذا كان محدداً، وإلا استخدام total_amount
        // هذا يضمن أن المبالغ المدفوعة من الرصيد الدائن لا تُضاف إلى خزنة المندوب
        // استبعاد الفواتير التي تم تسجيلها في collections (من خلال invoice_id أو notes)
        // عند استخدام الرصيد الدائن (paid_from_credit = 1): لا يُضاف المبلغ المستخدم من الرصيد الدائن إلى خزنة المندوب
        if ($hasInvoiceIdColumn) {
            // إذا كان هناك عمود invoice_id، نستخدمه للربط
            if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
                // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales - credit_used (لا يشمل المبلغ المستخدم من الرصيد الدائن)
                // لأن creditUsed يُضاف إلى amount_added_to_sales لإجمالي المبيعات (صافي) لكن لا يُضاف إلى رصيد الخزنة الإجمالي
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN paid_from_credit = 1 AND credit_used > 0
                        THEN GREATEST(0, COALESCE(amount_added_to_sales, 0) - credit_used)
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date" . 
                     ($hasInvoiceIdColumn ? " AND (c.invoice_id IS NULL OR c.invoice_id != i.id)" : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            } elseif ($hasPaidFromCreditColumn) {
                // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN paid_from_credit = 1
                        THEN COALESCE(amount_added_to_sales, 0)
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date" . 
                     ($hasInvoiceIdColumn ? " AND (c.invoice_id IS NULL OR c.invoice_id != i.id)" : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            } else {
                // إذا لم يكن عمود paid_from_credit موجوداً، نستخدم amount_added_to_sales أو total_amount
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date" . 
                     ($hasInvoiceIdColumn ? " AND (c.invoice_id IS NULL OR c.invoice_id != i.id)" : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            }
        } else {
            // إذا لم يكن هناك عمود invoice_id، نستخدم notes للبحث عن رقم الفاتورة
            // نمط البحث: "فاتورة [invoice_number]" أو "فاتورة [invoice_number]%" أو "%فاتورة [invoice_number]"
            if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
                // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales - credit_used (لا يشمل المبلغ المستخدم من الرصيد الدائن)
                // لأن creditUsed يُضاف إلى amount_added_to_sales لإجمالي المبيعات (صافي) لكن لا يُضاف إلى رصيد الخزنة الإجمالي
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN paid_from_credit = 1 AND credit_used > 0
                        THEN GREATEST(0, COALESCE(amount_added_to_sales, 0) - credit_used)
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date" . 
                     ($hasInvoiceIdColumn ? " AND (c.invoice_id IS NULL OR c.invoice_id != i.id)" : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            } elseif ($hasPaidFromCreditColumn) {
                // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales - COALESCE(credit_used, 0)
                // لأن creditUsed يُضاف إلى amount_added_to_sales لإجمالي المبيعات (صافي) لكن لا يُضاف إلى رصيد الخزنة الإجمالي
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN paid_from_credit = 1
                        THEN GREATEST(0, COALESCE(amount_added_to_sales, 0) - COALESCE(credit_used, 0))
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date" . 
                     ($hasInvoiceIdColumn ? " AND (c.invoice_id IS NULL OR c.invoice_id != i.id)" : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            } else {
                // إذا لم يكن عمود paid_from_credit موجوداً، نستخدم amount_added_to_sales أو total_amount
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date" . 
                     ($hasInvoiceIdColumn ? " AND (c.invoice_id IS NULL OR c.invoice_id != i.id)" : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            }
        }
    } else {
        // إذا لم يكن العمود موجوداً، نستخدم total_amount (للتوافق مع الإصدارات القديمة)
        if ($hasInvoiceIdColumn) {
            $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
             FROM invoices i
             WHERE i.sales_rep_id = ? 
             AND i.status = 'paid' 
             AND i.paid_amount >= i.total_amount
             AND i.status != 'cancelled'
             AND NOT EXISTS (
                 SELECT 1 FROM collections c 
                 WHERE c.invoice_id = i.id 
                 AND c.collected_by = ?
             )";
        } else {
            $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
             FROM invoices i
             WHERE i.sales_rep_id = ? 
             AND i.status = 'paid' 
             AND i.paid_amount >= i.total_amount
             AND i.status != 'cancelled'
             AND NOT EXISTS (
                 SELECT 1 FROM collections c 
                 WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                 AND c.collected_by = ?
             )
             AND NOT EXISTS (
                 SELECT 1 FROM collections c
                 WHERE c.customer_id = i.customer_id
                 AND c.collected_by = ?
                 AND c.date >= i.date" . 
                 ($hasInvoiceIdColumn ? " AND (c.invoice_id IS NULL OR c.invoice_id != i.id)" : "") . "
                 AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
             )";
        }
    }
    
    $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId]);
    $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
} elseif (!empty($invoicesTableExists)) {
    // إذا لم يكن جدول collections موجوداً، نستخدم الطريقة القديمة
    $hasAmountAddedToSalesColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'amount_added_to_sales'"));
    $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
    
    if ($hasAmountAddedToSalesColumn) {
        if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
            // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales - credit_used (لا يشمل المبلغ المستخدم من الرصيد الدائن)
            // لأن creditUsed يُضاف إلى amount_added_to_sales لإجمالي المبيعات (صافي) لكن لا يُضاف إلى رصيد الخزنة الإجمالي
            $fullyPaidSql = "SELECT COALESCE(SUM(
                CASE 
                    WHEN paid_from_credit = 1 AND credit_used > 0
                    THEN GREATEST(0, COALESCE(amount_added_to_sales, 0) - credit_used)
                    WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                    THEN amount_added_to_sales 
                    ELSE total_amount 
                END
            ), 0) as fully_paid
             FROM invoices
             WHERE sales_rep_id = ? 
             AND status = 'paid' 
             AND paid_amount >= total_amount
             AND status != 'cancelled'";
        } elseif ($hasPaidFromCreditColumn) {
            // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales - COALESCE(credit_used, 0)
            // لأن creditUsed يُضاف إلى amount_added_to_sales لإجمالي المبيعات (صافي) لكن لا يُضاف إلى رصيد الخزنة الإجمالي
            $fullyPaidSql = "SELECT COALESCE(SUM(
                CASE 
                    WHEN paid_from_credit = 1
                    THEN GREATEST(0, COALESCE(amount_added_to_sales, 0) - COALESCE(credit_used, 0))
                    WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                    THEN amount_added_to_sales 
                    ELSE total_amount 
                END
            ), 0) as fully_paid
             FROM invoices
             WHERE sales_rep_id = ? 
             AND status = 'paid' 
             AND paid_amount >= total_amount
             AND status != 'cancelled'";
        } else {
            // إذا لم يكن عمود paid_from_credit موجوداً، نستخدم amount_added_to_sales أو total_amount
            $fullyPaidSql = "SELECT COALESCE(SUM(
                CASE 
                    WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                    THEN amount_added_to_sales 
                    ELSE total_amount 
                END
            ), 0) as fully_paid
             FROM invoices
             WHERE sales_rep_id = ? 
             AND status = 'paid' 
             AND paid_amount >= total_amount
             AND status != 'cancelled'";
        }
    } else {
        $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
         FROM invoices
         WHERE sales_rep_id = ? 
         AND status = 'paid' 
         AND paid_amount >= total_amount
         AND status != 'cancelled'";
    }
    
    $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId]);
    $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
}

// حساب المبالغ المحصلة من المندوب (من accountant_transactions) لخصمها من الرصيد
$collectedFromRep = 0.0;
$accountantTransactionsExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
if (!empty($accountantTransactionsExists)) {
    $collectedResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total_collected
         FROM accountant_transactions
         WHERE sales_rep_id = ? 
         AND transaction_type = 'collection_from_sales_rep'
         AND status = 'approved'",
        [$salesRepId]
    );
    $collectedFromRep = (float)($collectedResult['total_collected'] ?? 0);
}

// حساب إجمالي المرتجعات التالفة للمندوب
$totalDamagedReturns = 0.0;
$damagedReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'damaged_returns'");
if (!empty($damagedReturnsTableExists)) {
    try {
        // حساب قيمة المرتجعات التالفة المعتمدة
        // نحسب القيمة من return_items المرتبطة بالمرتجعات التالفة
        $damagedReturnsResult = $db->queryOne(
            "SELECT COALESCE(SUM(ri.total_price), 0) as total_damaged_returns
             FROM damaged_returns dr
             INNER JOIN return_items ri ON dr.return_item_id = ri.id
             WHERE dr.sales_rep_id = ? 
               AND dr.approval_status = 'approved'",
            [$salesRepId]
        );
        $totalDamagedReturns = (float)($damagedReturnsResult['total_damaged_returns'] ?? 0);
    } catch (Throwable $damagedReturnsError) {
        error_log('Damaged returns calculation error: ' . $damagedReturnsError->getMessage());
        $totalDamagedReturns = 0.0;
    }
}

// حساب الإضافات المباشرة للرصيد
$totalCashAdditions = 0.0;
$cashAdditionsTableExists = $db->queryOne("SHOW TABLES LIKE 'cash_register_additions'");
$cashAdditions = [];
if (!empty($cashAdditionsTableExists)) {
    try {
        $additionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_additions
             FROM cash_register_additions
             WHERE sales_rep_id = ?",
            [$salesRepId]
        );
        $totalCashAdditions = (float)($additionsResult['total_additions'] ?? 0);
        
        // جلب قائمة الإضافات المباشرة مع التفاصيل
        $cashAdditions = $db->query(
            "SELECT 
                cra.id,
                cra.amount,
                cra.description,
                cra.created_at,
                cra.created_by,
                u.username as created_by_username,
                u.full_name as created_by_name
             FROM cash_register_additions cra
             LEFT JOIN users u ON cra.created_by = u.id
             WHERE cra.sales_rep_id = ?
             ORDER BY cra.created_at DESC",
            [$salesRepId]
        );
    } catch (Throwable $additionsError) {
        error_log('Cash additions calculation error: ' . $additionsError->getMessage());
        $totalCashAdditions = 0.0;
        $cashAdditions = [];
    }
}

// رصيد الخزنة = التحصيلات + المبيعات المدفوعة بالكامل + الإضافات المباشرة - المبالغ المحصلة من المندوب
// لا يتم خصم المرتجعات من رصيد الخزنة الإجمالي
$cashRegisterBalance = $totalCollections + $fullyPaidSales + $totalCashAdditions - $collectedFromRep;

// حساب المبيعات المعلقة (الديون) بالاعتماد على أرصدة العملاء لضمان التطابق مع صفحة العملاء
$pendingSales = 0.0;
$customerDebtResult = null;
try {
    $customerDebtSql = "SELECT COALESCE(SUM(balance), 0) AS total_debt FROM customers WHERE balance > 0";
    $customerDebtParams = [];
    
    // إذا كان هناك مندوب محدد، نعرض العملاء الخاصين به فقط (نفس منطق صفحة العملاء)
    if ($salesRepId) {
        $customerDebtSql .= " AND created_by = ?";
        $customerDebtParams[] = $salesRepId;
    }
    
    $customerDebtResult = $db->queryOne($customerDebtSql, $customerDebtParams);
    $pendingSales = (float)($customerDebtResult['total_debt'] ?? 0);
} catch (Throwable $customerDebtError) {
    // في حالة حدوث خطأ، نستخدم الطريقة القديمة كحل احتياطي
    error_log('Sales cash register debt calculation fallback: ' . $customerDebtError->getMessage());
    $pendingSales = $totalSales - $fullyPaidSales - $totalCollections;
    if ($pendingSales < 0) {
        $pendingSales = 0;
    }
}

// إحصائيات إضافية
$todaySales = 0.0;
$monthSales = 0.0;
$todayCollections = 0.0;
$monthCollections = 0.0;

if (!empty($invoicesTableExists)) {
    // حساب مبيعات اليوم (جميع الفواتير بغض النظر عن الرصيد الدائن)
    $todaySalesSql = "SELECT COALESCE(SUM(total_amount), 0) as total
         FROM invoices
         WHERE sales_rep_id = ? AND DATE(date) = CURDATE() AND status != 'cancelled'";
    
    $todaySalesResult = $db->queryOne($todaySalesSql, [$salesRepId]);
    $todaySales = (float)($todaySalesResult['total'] ?? 0);
    
    // حساب مبيعات الشهر (جميع الفواتير بغض النظر عن الرصيد الدائن)
    $monthSalesSql = "SELECT COALESCE(SUM(total_amount), 0) as total
         FROM invoices
         WHERE sales_rep_id = ? 
         AND MONTH(date) = MONTH(NOW()) 
         AND YEAR(date) = YEAR(NOW())
         AND status != 'cancelled'";
    
    $monthSalesResult = $db->queryOne($monthSalesSql, [$salesRepId]);
    $monthSales = (float)($monthSalesResult['total'] ?? 0);
}

// خصم قيمة المرتجعات من مبيعات اليوم والشهر
if (!empty($returnsTableExists)) {
    try {
        // حساب المرتجعات اليومية
        $todayReturnsResult = $db->queryOne(
            "SELECT COALESCE(SUM(refund_amount), 0) as total_returns
             FROM returns
             WHERE sales_rep_id = ? 
               AND DATE(return_date) = CURDATE()
               AND status IN ('approved', 'processed')
               AND refund_amount > 0",
            [$salesRepId]
        );
        $todayReturns = (float)($todayReturnsResult['total_returns'] ?? 0);
        
        // خصم المرتجعات اليومية من مبيعات اليوم
        $todaySales = max(0, $todaySales - $todayReturns);
        
        // حساب المرتجعات الشهرية
        $monthReturnsResult = $db->queryOne(
            "SELECT COALESCE(SUM(refund_amount), 0) as total_returns
             FROM returns
             WHERE sales_rep_id = ? 
               AND MONTH(return_date) = MONTH(NOW()) 
               AND YEAR(return_date) = YEAR(NOW())
               AND status IN ('approved', 'processed')
               AND refund_amount > 0",
            [$salesRepId]
        );
        $monthReturns = (float)($monthReturnsResult['total_returns'] ?? 0);
        
        // خصم المرتجعات الشهرية من مبيعات الشهر
        $monthSales = max(0, $monthSales - $monthReturns);
    } catch (Throwable $returnsError) {
        error_log('Returns calculation error for daily/monthly sales: ' . $returnsError->getMessage());
    }
}

// حساب تأثير الاستبدالات على مبيعات اليوم والشهر
$exchangesTableExists = $db->queryOne("SHOW TABLES LIKE 'exchanges'");
if (!empty($exchangesTableExists)) {
    try {
        // حساب الاستبدالات اليومية (الفرق الإجمالي)
        $todayExchangesResult = $db->queryOne(
            "SELECT COALESCE(SUM(difference_amount), 0) as total_exchanges
             FROM exchanges
             WHERE sales_rep_id = ? 
               AND DATE(exchange_date) = CURDATE()
               AND status IN ('approved', 'completed')",
            [$salesRepId]
        );
        $todayExchanges = (float)($todayExchangesResult['total_exchanges'] ?? 0);
        
        // إضافة/خصم الاستبدالات اليومية من مبيعات اليوم
        // الفرق موجب = خصم، الفرق سالب = إضافة
        $todaySales = $todaySales - $todayExchanges; // سالب الفرق = إضافة إذا كان الفرق سالب
        
        // حساب الاستبدالات الشهرية
        $monthExchangesResult = $db->queryOne(
            "SELECT COALESCE(SUM(difference_amount), 0) as total_exchanges
             FROM exchanges
             WHERE sales_rep_id = ? 
               AND MONTH(exchange_date) = MONTH(NOW()) 
               AND YEAR(exchange_date) = YEAR(NOW())
               AND status IN ('approved', 'completed')",
            [$salesRepId]
        );
        $monthExchanges = (float)($monthExchangesResult['total_exchanges'] ?? 0);
        
        // إضافة/خصم الاستبدالات الشهرية من مبيعات الشهر
        $monthSales = $monthSales - $monthExchanges; // سالب الفرق = إضافة إذا كان الفرق سالب
    } catch (Throwable $exchangesError) {
        error_log('Exchanges calculation error for daily/monthly sales: ' . $exchangesError->getMessage());
    }
}

if (!empty($collectionsTableExists)) {
    $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasStatusColumn = !empty($statusColumnCheck);
    
    // حساب جميع التحصيلات (pending و approved) لأن المندوب قام بتحصيلها بالفعل
    $statusFilter = $hasStatusColumn ? "AND status IN ('pending', 'approved')" : "";
    
    $todayCollectionsResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total
         FROM collections
         WHERE collected_by = ? AND DATE(date) = CURDATE() $statusFilter",
        [$salesRepId]
    );
    $todayCollections = (float)($todayCollectionsResult['total'] ?? 0);
    
    $monthCollectionsResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total
         FROM collections
         WHERE collected_by = ? 
         AND MONTH(date) = MONTH(NOW()) 
         AND YEAR(date) = YEAR(NOW())
         $statusFilter",
        [$salesRepId]
    );
    $monthCollections = (float)($monthCollectionsResult['total'] ?? 0);
}

// حساب الديون القديمة (العملاء المدينين بدون سجل مشتريات)
$oldDebtsCustomers = [];
$oldDebtsTotal = 0.0;

try {
    // التحقق من وجود جدول customer_purchase_history
    $purchaseHistoryTableExists = $db->queryOne("SHOW TABLES LIKE 'customer_purchase_history'");
    
    if (!empty($purchaseHistoryTableExists)) {
        // جلب العملاء المدينين الذين ليس لديهم سجل مشتريات
        $oldDebtsQuery = "
            SELECT 
                c.id,
                c.name,
                c.phone,
                c.address,
                c.balance,
                c.created_at
            FROM customers c
            WHERE c.created_by = ?
            AND c.balance IS NOT NULL 
            AND c.balance > 0
            AND NOT EXISTS (
                SELECT 1 
                FROM customer_purchase_history cph 
                WHERE cph.customer_id = c.id
            )
            ORDER BY c.balance DESC, c.name ASC
        ";
        
        $oldDebtsCustomers = $db->query($oldDebtsQuery, [$salesRepId]);
        
        // حساب إجمالي الديون القديمة
        if (!empty($oldDebtsCustomers)) {
            foreach ($oldDebtsCustomers as $customer) {
                $oldDebtsTotal += (float)($customer['balance'] ?? 0);
            }
        }
    } else {
        // إذا لم يكن الجدول موجوداً، نستخدم استعلام مختلف
        // جلب العملاء المدينين الذين ليس لديهم فواتير
        $oldDebtsQuery = "
            SELECT 
                c.id,
                c.name,
                c.phone,
                c.address,
                c.balance,
                c.created_at
            FROM customers c
            WHERE c.created_by = ?
            AND c.balance IS NOT NULL 
            AND c.balance > 0
            AND NOT EXISTS (
                SELECT 1 
                FROM invoices inv 
                WHERE inv.customer_id = c.id
            )
            ORDER BY c.balance DESC, c.name ASC
        ";
        
        $oldDebtsCustomers = $db->query($oldDebtsQuery, [$salesRepId]);
        
        // حساب إجمالي الديون القديمة
        if (!empty($oldDebtsCustomers)) {
            foreach ($oldDebtsCustomers as $customer) {
                $oldDebtsTotal += (float)($customer['balance'] ?? 0);
            }
        }
    }
} catch (Throwable $oldDebtsError) {
    error_log('Old debts calculation error: ' . $oldDebtsError->getMessage());
    $oldDebtsCustomers = [];
    $oldDebtsTotal = 0.0;
}

// جلب معلومات المندوب
$salesRepInfo = $db->queryOne(
    "SELECT id, full_name, username, phone
     FROM users
     WHERE id = ? AND role = 'sales'",
    [$salesRepId]
);

?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-stack me-2"></i>خزنة المندوب</h2>
    <div class="d-flex align-items-center gap-3">
        <?php if ($salesRepInfo): ?>
            <div class="text-muted">
                <i class="bi bi-person-circle me-2"></i>
                <strong><?php echo htmlspecialchars($salesRepInfo['full_name'] ?? $salesRepInfo['username']); ?></strong>
            </div>
        <?php endif; ?>
        <a href="<?php echo getRelativeUrl('print_cash_register_statement.php'); ?>?sales_rep_id=<?php echo $salesRepId; ?>" 
           target="_blank" 
           class="btn btn-primary">
            <i class="bi bi-printer me-2"></i>طباعة كشف حساب شامل
        </a>
    </div>
</div>

<!-- بطاقات الإحصائيات الرئيسية -->
<div class="row g-3 mb-4">
    
</div>

<!-- بطاقات إحصائيات إضافية -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">مبيعات اليوم</div>
                <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($todaySales); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">مبيعات الشهر</div>
                <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($monthSales); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">تحصيلات اليوم</div>
                <div class="fs-5 fw-bold mb-0 text-success"><?php echo formatCurrency($todayCollections); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">تحصيلات الشهر</div>
                <div class="fs-5 fw-bold mb-0 text-success"><?php echo formatCurrency($monthCollections); ?></div>
            </div>
        </div>
    </div>
</div>



<!-- ملخص الحسابات -->
<style>
/* Glassmorphism Styles for Cash Register */
.glass-card {
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    position: relative;
}

.glass-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
}

.glass-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.glass-card-header i {
    font-size: 24px;
}

.glass-card-body {
    padding: 24px 20px;
}

.glass-card-value {
    font-size: 32px;
    font-weight: 700;
    line-height: 1.2;
    margin: 0;
}

.glass-card-title {
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    margin: 0;
    margin-bottom: 8px;
}

.glass-card-blue {
    color: #0057ff;
}

.glass-card-green {
    color: #0fa55a;
}

.glass-card-orange {
    color: #c98200;
}

.glass-card-red {
    color: #d00000;
}

.glass-card-red-bg {
    background: rgba(208, 0, 0, 0.1);
    border-color: rgba(208, 0, 0, 0.2);
}

.glass-card-red-bg .glass-card-header {
    background: rgba(208, 0, 0, 0.05);
}

.glass-debts-table {
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-radius: 16px;
    overflow: hidden;
    margin-top: 20px;
}

.glass-debts-table table {
    margin: 0;
}

.glass-debts-table thead th {
    background: rgba(208, 0, 0, 0.08);
    border: none;
    padding: 16px;
    font-weight: 600;
    color: #374151;
}

.glass-debts-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.glass-debts-table tbody tr:last-child td {
    border-bottom: none;
}

.glass-debts-table tbody tr:hover {
    background: rgba(208, 0, 0, 0.03);
}

.glass-debts-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    margin-bottom: 16px;
}

.glass-debts-summary i {
    font-size: 20px;
    color: #d00000;
}

@media (max-width: 768px) {
    .glass-card-value {
        font-size: 24px;
    }
    
    .glass-card-header {
        padding: 14px 16px;
    }
    
    .glass-card-body {
        padding: 20px 16px;
    }
}
</style>

<div class="mb-4">
    <div class="row g-4">
        <!-- إجمالي المبيعات (صافي بعد خصم المرتجعات) -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-bar-chart-fill glass-card-blue"></i>
                    <h6 class="mb-0 fw-semibold">إجمالي المبيعات (صافي)</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-blue mb-0"><?php echo formatCurrency($netSales); ?></p>
                    <?php if ($totalReturns > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        إجمالي المبيعات: <?php echo formatCurrency($totalSales); ?> - المرتجعات: <?php echo formatCurrency($totalReturns); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- التحصيلات من العملاء -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-wallet-fill glass-card-green"></i>
                    <h6 class="mb-0 fw-semibold">إجمالي التحصيلات من العملاء</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-green mb-0">+ <?php echo formatCurrency($totalCollections); ?></p>
                </div>
            </div>
        </div>
        
        
        
        <!-- المبيعات المعلقة -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-exclamation-triangle-fill glass-card-orange"></i>
                    <h6 class="mb-0 fw-semibold">الديون المعلقة (يشمل الديون القديمه)</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-orange mb-0">- <?php echo formatCurrency($pendingSales); ?></p>
                    <?php if ($oldDebtsTotal > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        ديون بدون سجل مشتريات: <?php echo formatCurrency($oldDebtsTotal); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- إجمالي المرتجعات -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-arrow-return-left glass-card-red"></i>
                    <h6 class="mb-0 fw-semibold">إجمالي المرتجعات</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-red mb-0">- <?php echo formatCurrency($totalReturns); ?></p>
                    <?php if ($totalDamagedReturns > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        منها تالفة: <?php echo formatCurrency($totalDamagedReturns); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- المرتجعات التالفة -->
        <?php if ($totalDamagedReturns > 0): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-exclamation-triangle glass-card-red"></i>
                    <h6 class="mb-0 fw-semibold">المرتجعات التالفة</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-red mb-0">- <?php echo formatCurrency($totalDamagedReturns); ?></p>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        قيمة المنتجات التالفة المعتمدة
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- رصيد الخزنة الإجمالي -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-bank glass-card-blue"></i>
                    <h6 class="mb-0 fw-semibold">رصيد الخزنة الإجمالي</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-blue mb-0"><?php echo formatCurrency($cashRegisterBalance); ?></p>
                    <?php if ($totalCashAdditions > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        إضافات مباشرة: <?php echo formatCurrency($totalCashAdditions); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- نموذج إضافة رصيد للخزنة (ثابت) -->
<?php if ($isSalesUser): ?>
<div class="glass-card mb-4">
    <div class="glass-card-header">
        <i class="bi bi-plus-circle-fill glass-card-green"></i>
        <h6 class="mb-0 fw-semibold">إضافة رصيد للخزنة</h6>
    </div>
    <div class="glass-card-body">
        <form method="POST" action="javascript:void(0);" id="addCashBalanceForm" onsubmit="return false;">
            <input type="hidden" name="action" value="add_cash_balance">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="cashBalanceAmount" class="form-label">
                        <i class="bi bi-cash-coin me-2"></i>المبلغ <span class="text-danger">*</span>
                    </label>
                    <input type="number" 
                           class="form-control" 
                           id="cashBalanceAmount" 
                           name="amount" 
                           step="0.01" 
                           min="0.01" 
                           required 
                           placeholder="أدخل المبلغ">
                    <div class="form-text">يجب أن يكون المبلغ أكبر من الصفر</div>
                </div>
                <div class="col-12 col-md-6">
                    <label for="cashBalanceDescription" class="form-label">
                        <i class="bi bi-card-text me-2"></i>الوصف
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="cashBalanceDescription" 
                           name="description" 
                           placeholder="اكتب وصفاً للمبلغ (اختياري)">
                    <div class="form-text">مثال: إضافة رصيد نقدي، إيداع من حساب شخصي، إلخ</div>
                </div>
                <div class="col-12 col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-circle me-2"></i>إضافة
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- جدول الديون القديمة -->
<div class="glass-card glass-card-red-bg mb-4">
    <div class="glass-card-header">
        <i class="bi bi-clipboard-x-fill glass-card-red"></i>
        <h5 class="mb-0 fw-bold">الديون القديمة</h5>
    </div>
    <div class="glass-card-body">
        <div class="glass-debts-summary">
            <i class="bi bi-people-fill"></i>
            <div>
                <p class="mb-1 text-muted small">العملاء المدينين الذين ليس لديهم سجل مشتريات في النظام.</p>
                <div class="d-flex align-items-center gap-4 flex-wrap">
                    <div>
                        <span class="text-muted small">عدد العملاء: </span>
                        <strong><?php echo count($oldDebtsCustomers); ?></strong>
                    </div>
                    <div>
                        <span class="text-muted small">إجمالي الديون: </span>
                        <strong class="glass-card-red fs-5"><?php echo formatCurrency($oldDebtsTotal); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($oldDebtsCustomers)): ?>
            <div class="glass-debts-table">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>
                                    <i class="bi bi-person-fill me-2"></i>اسم العميل
                                </th>
                                <th>
                                    <i class="bi bi-telephone-fill me-2"></i>الهاتف
                                </th>
                                <th>
                                    <i class="bi bi-geo-alt-fill me-2"></i>العنوان
                                </th>
                                <th class="text-end">
                                    <i class="bi bi-cash-stack me-2"></i>الديون
                                </th>
                                <th>
                                    <i class="bi bi-calendar-event-fill me-2"></i>تاريخ الإضافة
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($oldDebtsCustomers as $customer): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($customer['name'] ?? '-'); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($customer['phone'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($customer['address'] ?? '-'); ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <strong class="glass-card-red">
                                            <?php echo formatCurrency((float)($customer['balance'] ?? 0)); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            if (!empty($customer['created_at'])) {
                                                echo date('Y-m-d', strtotime($customer['created_at']));
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: rgba(208, 0, 0, 0.1);">
                                <th colspan="3" class="text-end">
                                    <strong>الإجمالي:</strong>
                                </th>
                                <th class="text-end">
                                    <strong class="glass-card-red"><?php echo formatCurrency($oldDebtsTotal); ?></strong>
                                </th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0" style="border-radius: 12px;">
                <i class="bi bi-check-circle me-2"></i>
                لا توجد ديون قديمة للعملاء المدينين بدون سجل مشتريات.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- جدول الإضافات المباشرة للخزنة -->
<div class="glass-card mb-4">
    <div class="glass-card-header">
        <i class="bi bi-plus-circle-fill glass-card-green"></i>
        <h5 class="mb-0 fw-bold">سجل الإضافات المباشرة للخزنة</h5>
    </div>
    <div class="glass-card-body">
        <?php if (!empty($cashAdditions)): ?>
            <div class="glass-debts-table">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>
                                    <i class="bi bi-hash me-2"></i>#
                                </th>
                                <th>
                                    <i class="bi bi-calendar-event me-2"></i>التاريخ والوقت
                                </th>
                                <th class="text-end">
                                    <i class="bi bi-cash-coin me-2"></i>المبلغ
                                </th>
                                <th>
                                    <i class="bi bi-card-text me-2"></i>الوصف
                                </th>
                                <th>
                                    <i class="bi bi-person me-2"></i>تمت الإضافة بواسطة
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($cashAdditions as $addition): 
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $counter++; ?></strong>
                                    </td>
                                    <td>
                                        <small>
                                            <?php echo formatDateTime($addition['created_at']); ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-success">
                                            + <?php echo formatCurrency((float)($addition['amount'] ?? 0)); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($addition['description'])): ?>
                                            <span class="text-muted">
                                                <?php echo htmlspecialchars($addition['description']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-person-circle me-2 text-muted"></i>
                                            <span>
                                                <?php 
                                                echo htmlspecialchars(
                                                    $addition['created_by_name'] ?? 
                                                    $addition['created_by_username'] ?? 
                                                    'غير معروف'
                                                ); 
                                                ?>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: rgba(15, 165, 90, 0.1);">
                                <th colspan="2" class="text-end">
                                    <strong>إجمالي الإضافات:</strong>
                                </th>
                                <th class="text-end text-success">
                                    <strong>+ <?php echo formatCurrency($totalCashAdditions); ?></strong>
                                </th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0" style="border-radius: 12px;">
                <i class="bi bi-info-circle me-2"></i>
                لا توجد إضافات مباشرة مسجلة للخزنة حتى الآن.
            </div>
        <?php endif; ?>
    </div>
</div>


<!-- معالجة نموذج إضافة الرصيد عبر AJAX -->
<script>
// تم إزالة كود إعادة التحميل التلقائي لأننا الآن نحدث البيانات مباشرة عبر AJAX
// لا حاجة لإعادة تحميل الصفحة بعد إضافة الرصيد

// معالجة نموذج إضافة الرصيد عبر AJAX
(function() {
    // الانتظار حتى يتم تحميل DOM بالكامل
    function initCashBalanceForm() {
        const addCashBalanceForm = document.getElementById('addCashBalanceForm');
        const cashBalanceAmount = document.getElementById('cashBalanceAmount');
        const cashBalanceDescription = document.getElementById('cashBalanceDescription');
        
        // التحقق من وجود العناصر
        if (!addCashBalanceForm) {
            // إعادة المحاولة بعد 100ms إذا لم تكن العناصر جاهزة
            setTimeout(initCashBalanceForm, 100);
            return;
        }
        
        // معالجة إرسال النموذج
        addCashBalanceForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation(); // منع أي معالجات أخرى
            
            const amount = parseFloat(cashBalanceAmount.value) || 0;
            const submitBtn = addCashBalanceForm.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
            
            // التحقق من المبلغ
            if (isNaN(amount) || amount <= 0) {
                let errorDiv = cashBalanceAmount.parentElement.querySelector('.invalid-feedback');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'invalid-feedback d-block';
                    errorDiv.style.color = '#dc3545';
                    errorDiv.style.fontSize = '0.875em';
                    errorDiv.style.marginTop = '0.25rem';
                    cashBalanceAmount.parentElement.appendChild(errorDiv);
                }
                errorDiv.textContent = 'يجب أن يكون المبلغ أكبر من الصفر';
                cashBalanceAmount.classList.add('is-invalid');
                cashBalanceAmount.focus();
                return false;
            } else {
                cashBalanceAmount.classList.remove('is-invalid');
                const errorDiv = cashBalanceAmount.parentElement.querySelector('.invalid-feedback');
                if (errorDiv) {
                    errorDiv.remove();
                }
            }
            
            // تعطيل الزر وإظهار loading
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الإضافة...';
            }
            
            // إعداد FormData
            const formData = new FormData(addCashBalanceForm);
            
            // إرسال طلب AJAX
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(async response => {
                // قراءة النص أولاً للتحقق من المحتوى
                const text = await response.text();
                
                // محاولة تنظيف النص من أي whitespace أو محتوى غير مرغوب فيه
                const cleanedText = text.trim();
                
                // البحث عن JSON في النص (في حالة وجود محتوى إضافي)
                let jsonStart = cleanedText.indexOf('{');
                let jsonEnd = cleanedText.lastIndexOf('}');
                
                let jsonText = cleanedText;
                if (jsonStart !== -1 && jsonEnd !== -1 && jsonEnd > jsonStart) {
                    // استخراج JSON فقط
                    jsonText = cleanedText.substring(jsonStart, jsonEnd + 1);
                }
                
                // محاولة تحليل JSON
                let data;
                try {
                    data = JSON.parse(jsonText);
                } catch (parseError) {
                    // إذا فشل التحليل، تحقق من نوع المحتوى
                    const contentType = response.headers.get('content-type') || '';
                    if (contentType.includes('application/json')) {
                        console.error('JSON parse error:', parseError);
                        console.error('Response text:', text.substring(0, 500));
                        throw new Error('فشل في تحليل استجابة الخادم');
                    } else {
                        // إذا لم يكن JSON، قد يكون هناك خطأ في الخادم
                        console.error('Non-JSON response:', text.substring(0, 500));
                        throw new Error('الخادم لم يعد استجابة JSON صحيحة');
                    }
                }
                
                if (!response.ok) {
                    throw new Error(data.message || 'حدث خطأ في الخادم');
                }
                
                return data;
            })
            .then(data => {
                // إعادة تفعيل الزر
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
                
                // التحقق من أن البيانات صحيحة
                if (!data || typeof data !== 'object') {
                    console.error('Invalid response data:', data);
                    showAlert('danger', 'استجابة غير صحيحة من الخادم');
                    return;
                }
                
                if (data.success) {
                    // إظهار رسالة النجاح
                    showAlert('success', data.message || 'تم إضافة الرصيد بنجاح');
                    
                    // إعادة تعيين النموذج
                    addCashBalanceForm.reset();
                    
                    // إعادة تحميل الصفحة بعد النجاح (بدون إعادة إرسال البيانات)
                    // استخدام setTimeout لإعطاء الوقت لعرض رسالة النجاح
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert('danger', data.message || 'حدث خطأ أثناء إضافة الرصيد');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
                
                // عرض رسالة خطأ واضحة
                const errorMessage = error.message || 'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.';
                showAlert('danger', errorMessage);
            });
            
            return false; // منع إرسال النموذج بشكل افتراضي
        });
        
        // إزالة رسالة الخطأ عند البدء بالكتابة
        if (cashBalanceAmount) {
            cashBalanceAmount.addEventListener('input', function() {
                if (this.classList.contains('is-invalid')) {
                    this.classList.remove('is-invalid');
                    const errorDiv = this.parentElement.querySelector('.invalid-feedback');
                    if (errorDiv) {
                        errorDiv.remove();
                    }
                }
            }, { passive: true });
        }
    }
    
    // تهيئة عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCashBalanceForm);
    } else {
        // DOM محمل بالفعل
        initCashBalanceForm();
    }
    
    
    // دالة لإظهار رسائل التنبيه
    function showAlert(type, message) {
        // البحث عن container الرسائل
        const alertContainer = document.querySelector('.container-fluid, .container, main') || document.body;
        
        // إنشاء عنصر التنبيه
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        // إدراج التنبيه في بداية الصفحة
        const firstChild = alertContainer.firstElementChild;
        if (firstChild) {
            alertContainer.insertBefore(alertDiv, firstChild);
        } else {
            alertContainer.appendChild(alertDiv);
        }
        
        // إزالة التنبيه تلقائياً بعد 5 ثوانٍ
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
    
    // دالة لتحديث رصيد الخزنة الإجمالي
    function updateCashRegisterBalance(newTotal) {
        // البحث عن عنصر رصيد الخزنة الإجمالي
        const balanceCard = document.querySelector('.glass-card:has(.glass-card-blue)');
        if (balanceCard) {
            const balanceValue = balanceCard.querySelector('.glass-card-value');
            if (balanceValue) {
                // تحديث القيمة (نحتاج إلى حساب الرصيد الكامل من البيانات الأخرى)
                // لكن على الأقل نحدث الإضافات المباشرة
                const additionsText = balanceCard.querySelector('.glass-card-title');
                if (additionsText) {
                    additionsText.textContent = `إضافات مباشرة: ${formatCurrency(newTotal)}`;
                }
            }
        }
        
        // تحديث رصيد الخزنة في بطاقة الإحصائيات (إذا كان موجوداً)
        const cashRegisterCards = document.querySelectorAll('.glass-card');
        cashRegisterCards.forEach(card => {
            const header = card.querySelector('.glass-card-header');
            if (header && header.textContent.includes('رصيد الخزنة الإجمالي')) {
                const body = card.querySelector('.glass-card-body');
                if (body) {
                    const valueElement = body.querySelector('.glass-card-value');
                    const additionsElement = body.querySelector('.glass-card-title');
                    if (additionsElement) {
                        additionsElement.textContent = `إضافات مباشرة: ${formatCurrency(newTotal)}`;
                    }
                }
            }
        });
    }
    
    // دالة لإضافة سجل جديد إلى جدول الإضافات
    function addCashAdditionToTable(addition) {
        const additionsTable = document.querySelector('.glass-debts-table table tbody');
        if (!additionsTable) return;
        
        // حساب رقم السجل الجديد
        const existingRows = additionsTable.querySelectorAll('tr');
        const newCounter = existingRows.length + 1;
        
        // تنسيق التاريخ والوقت
        const dateTime = new Date(addition.created_at);
        const formattedDate = dateTime.toLocaleDateString('ar-EG', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        // إنشاء صف جديد
        const newRow = document.createElement('tr');
        newRow.innerHTML = `
            <td><strong>${newCounter}</strong></td>
            <td><small>${formattedDate}</small></td>
            <td class="text-end">
                <strong class="text-success">+ ${formatCurrency(parseFloat(addition.amount))}</strong>
            </td>
            <td>
                ${addition.description ? 
                    `<span class="text-muted">${escapeHtml(addition.description)}</span>` : 
                    '<span class="text-muted fst-italic">-</span>'}
            </td>
            <td>
                <div class="d-flex align-items-center">
                    <i class="bi bi-person-circle me-2 text-muted"></i>
                    <span>${escapeHtml(addition.created_by_name || addition.created_by_username || 'غير معروف')}</span>
                </div>
            </td>
        `;
        
        // إدراج الصف في بداية الجدول
        if (additionsTable.firstChild) {
            additionsTable.insertBefore(newRow, additionsTable.firstChild);
        } else {
            additionsTable.appendChild(newRow);
        }
        
        // تحديث الأرقام التسلسلية للصفوف الموجودة
        const allRows = additionsTable.querySelectorAll('tr');
        allRows.forEach((row, index) => {
            const counterCell = row.querySelector('td:first-child strong');
            if (counterCell) {
                counterCell.textContent = index + 1;
            }
        });
        
        // تحديث إجمالي الإضافات في التذييل
        const footer = additionsTable.closest('table').querySelector('tfoot tr');
        if (footer) {
            const totalCell = footer.querySelector('th.text-success');
            if (totalCell) {
                const currentTotal = parseFloat(totalCell.textContent.replace(/[^\d.]/g, '')) || 0;
                const newTotal = currentTotal + parseFloat(addition.amount);
                totalCell.innerHTML = `<strong>+ ${formatCurrency(newTotal)}</strong>`;
            }
        }
        
        // إزالة رسالة "لا توجد إضافات" إذا كانت موجودة
        const noDataAlert = additionsTable.closest('.glass-card-body').querySelector('.alert-info');
        if (noDataAlert && noDataAlert.textContent.includes('لا توجد إضافات')) {
            noDataAlert.remove();
        }
    }
    
    // دالة مساعدة لتنسيق العملة (متوافقة مع PHP formatCurrency)
    function formatCurrency(amount) {
        // استخدام نفس التنسيق المستخدم في PHP
        const formatted = new Intl.NumberFormat('ar-EG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.abs(amount));
        
        // إضافة رمز العملة - تنظيف القيمة لتجنب مشاكل JavaScript
        const symbol = <?php echo json_encode(getCurrencySymbol(), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        return formatted + ' ' + symbol;
    }
    
    // دالة مساعدة لتهريب HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>


<?php
/**
 * نظام الفواتير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';

/**
 * إنشاء فاتورة جديدة
 * 
 * @param int $customerId معرف العميل
 * @param int|null $salesRepId معرف مندوب المبيعات
 * @param string $date تاريخ الفاتورة
 * @param array $items عناصر الفاتورة
 * @param float $taxRate نسبة الضريبة
 * @param float $discountAmount مبلغ الخصم
 * @param string|null $notes ملاحظات
 * @param int|null $createdBy معرف المستخدم الذي أنشأ الفاتورة
 * @param string|null $dueDate تاريخ الاستحقاق
 * @param bool $createdFromPos هل تم إنشاء الفاتورة من نقطة البيع
 * @param string|null $posType نوع نقطة البيع: 'sales' للمندوب، 'manager' أو 'accountant' للمدير/المحاسب
 */
function createInvoice($customerId, $salesRepId, $date, $items, $taxRate = 0, $discountAmount = 0, $notes = null, $createdBy = null, $dueDate = null, $createdFromPos = false, $posType = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        if (!$createdBy) {
            return ['success' => false, 'message' => 'يجب تسجيل الدخول'];
        }
        
        // التحقق من صحة customer_id قبل إنشاء الفاتورة
        if ($customerId <= 0) {
            return ['success' => false, 'message' => 'معرف العميل غير صالح'];
        }
        
        // التحقق من وجود العميل وحالته
        $customer = $db->queryOne(
            "SELECT id, status, rep_id, created_by FROM customers WHERE id = ?",
            [$customerId]
        );
        
        if (!$customer) {
            return ['success' => false, 'message' => 'العميل غير موجود'];
        }
        
        if ($customer['status'] !== 'active') {
            return ['success' => false, 'message' => 'العميل غير نشط'];
        }
        
        // التحقق من ملكية المندوب للعميل (إذا كان المستخدم مندوب مبيعات)
        if ($salesRepId && $createdBy) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            if ($currentUser && ($currentUser['role'] ?? '') === 'sales') {
                $salesRepIdInt = (int)$salesRepId;
                $customerRepId = !empty($customer['rep_id']) ? (int)$customer['rep_id'] : null;
                $customerCreatedBy = !empty($customer['created_by']) ? (int)$customer['created_by'] : null;
                
                // التحقق من أن العميل ينتمي للمندوب
                if ($customerRepId !== $salesRepIdInt && $customerCreatedBy !== $salesRepIdInt) {
                    return ['success' => false, 'message' => 'هذا العميل غير مرتبط بك'];
                }
            }
        }
        
        // التحقق من وجود عمود created_from_pos وإضافته إذا لم يكن موجوداً
        $hasCreatedFromPosColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'created_from_pos'"));
        if (!$hasCreatedFromPosColumn) {
            try {
                $db->execute("ALTER TABLE invoices ADD COLUMN created_from_pos TINYINT(1) DEFAULT 0 COMMENT 'تم إنشاء الفاتورة من نقطة البيع' AFTER amount_added_to_sales");
            } catch (Throwable $e) {
                error_log('Error adding created_from_pos column: ' . $e->getMessage());
            }
        }
        
        // تحديد نوع نقطة البيع تلقائياً إذا لم يتم تحديده
        if ($posType === null && $createdFromPos) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            if ($currentUser) {
                $userRole = $currentUser['role'] ?? '';
                if ($userRole === 'sales') {
                    $posType = 'sales';
                } elseif ($userRole === 'manager' || $userRole === 'accountant') {
                    $posType = $userRole;
                }
            }
        }
        
        // توليد رقم فاتورة
        $invoiceNumber = generateInvoiceNumber($posType);
        
        // التحقق النهائي من عدم تكرار رقم الفاتورة قبل الإدراج
        // هذا يضمن عدم التكرار حتى في حالة الطلبات المتزامنة
        $existingInvoice = $db->queryOne(
            "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
            [$invoiceNumber]
        );
        
        if ($existingInvoice) {
            // إذا كان الرقم موجوداً، توليد رقم جديد
            error_log("Warning: Invoice number {$invoiceNumber} already exists, generating new number");
            $invoiceNumber = generateInvoiceNumber($posType);
            
            // التحقق مرة أخرى
            $existingInvoice = $db->queryOne(
                "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
                [$invoiceNumber]
            );
            
            if ($existingInvoice) {
                // إذا كان موجوداً مرة أخرى، استخدم timestamp
                $timestamp = substr(time(), -6);
                $microtime = substr(str_replace('.', '', microtime(true)), -4);
                $random = bin2hex(random_bytes(3));
                $year = date('Y');
                $month = date('m');
                $invoiceNumber = sprintf("INV-%s%s-%04d-%s%s-%s", $year, $month, rand(1000, 9999), $timestamp, $microtime, $random);
                error_log("Using fallback invoice number: {$invoiceNumber}");
            }
        }
        
        // حساب المبالغ
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += ($item['quantity'] * $item['unit_price']);
        }
        
        $taxAmount = 0; // تم إلغاء الضريبة
        $totalAmount = $subtotal - $discountAmount;
        
        // التحقق من صحة تاريخ الاستحقاق (إذا لم يتم تحديده، استخدم null لطباعة "أجل غير مسمى")
        if (empty($dueDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $dueDate = null;
        }
        
        // إنشاء الفاتورة
        $sql = "INSERT INTO invoices 
                (invoice_number, customer_id, sales_rep_id, date, due_date, subtotal, tax_rate, tax_amount, 
                 discount_amount, total_amount, notes, created_by, status" . ($hasCreatedFromPosColumn ? ", created_from_pos" : "") . ") 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft'" . ($hasCreatedFromPosColumn ? ", ?" : "") . ")";
        
        $params = [
            $invoiceNumber,
            $customerId,
            $salesRepId,
            $date,
            $dueDate,
            $subtotal,
            $taxRate,
            $taxAmount,
            $discountAmount,
            $totalAmount,
            $notes,
            $createdBy
        ];
        
        if ($hasCreatedFromPosColumn) {
            $params[] = $createdFromPos ? 1 : 0;
        }
        
        try {
            $result = $db->execute($sql, $params);
        } catch (Exception $insertError) {
            // إذا فشل الإدراج بسبب تكرار رقم الفاتورة (UNIQUE constraint)
            if (strpos($insertError->getMessage(), 'Duplicate entry') !== false || 
                strpos($insertError->getMessage(), 'invoice_number') !== false) {
                error_log("Duplicate invoice number detected: {$invoiceNumber}, generating new number");
                
                // توليد رقم جديد
                $invoiceNumber = generateInvoiceNumber($posType);
                
                // محاولة الإدراج مرة أخرى
                $retryParams = [
                    $invoiceNumber,
                    $customerId,
                    $salesRepId,
                    $date,
                    $dueDate,
                    $subtotal,
                    $taxRate,
                    $taxAmount,
                    $discountAmount,
                    $totalAmount,
                    $notes,
                    $createdBy
                ];
                
                if ($hasCreatedFromPosColumn) {
                    $retryParams[] = $createdFromPos ? 1 : 0;
                }
                
                $result = $db->execute($sql, $retryParams);
            } else {
                // إذا كان الخطأ مختلفاً، ارميه مرة أخرى
                throw $insertError;
            }
        }
        
        $invoiceId = $result['insert_id'];
        
        // إضافة عناصر الفاتورة
        foreach ($items as $item) {
            $itemTotal = $item['quantity'] * $item['unit_price'];
            
            $db->execute(
                "INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, total_price) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $invoiceId,
                    $item['product_id'],
                    $item['description'] ?? null,
                    $item['quantity'],
                    $item['unit_price'],
                    $itemTotal
                ]
            );
        }
        
        // تسجيل سجل التدقيق
        logAudit($createdBy, 'create_invoice', 'invoice', $invoiceId, null, [
            'invoice_number' => $invoiceNumber,
            'total_amount' => $totalAmount
        ]);
        
        return ['success' => true, 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'total_amount' => $totalAmount];
        
    } catch (Exception $e) {
        error_log("Invoice Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء الفاتورة'];
    }
}

/**
 * توليد رقم فاتورة فريد (يضمن عدم تكرار الأرقام حتى لو كان البيع لنفس العميل)
 * يتم التحقق من عدم التكرار بغض النظر عن العميل أو المنتجات
 * يستخدم transaction مع SELECT FOR UPDATE لضمان عدم التكرار في حالة الطلبات المتزامنة
 * 
 * @param string|null $posType نوع نقطة البيع: 'manager' أو 'accountant' للعداد التصاعدي، 'sales' للرقم العشوائي، null للنظام القديم
 * @return string رقم الفاتورة
 */
function generateInvoiceNumber($posType = null) {
    $db = db();
    $conn = $db->getConnection();
    
    // إذا كانت الفاتورة من نقطة البيع الخاصة بالمندوب، استخدم رقم عشوائي مكون من 5 أرقام
    if ($posType === 'sales') {
        return generateRandomInvoiceNumber();
    }
    
    // إذا كانت الفاتورة من نقطة البيع الخاصة بالمدير/المحاسب، استخدم عداد تصاعدي يبدأ من 1
    if ($posType === 'manager' || $posType === 'accountant') {
        return generateSequentialInvoiceNumber();
    }
    
    // النظام القديم (للتوافق مع الاستخدامات الأخرى)
    $year = date('Y');
    $month = date('m');
    $prefix = "INV-{$year}{$month}";
    
    // استخدام transaction مع SELECT FOR UPDATE لضمان عدم التكرار في حالة الطلبات المتزامنة
    $transactionStarted = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $transactionStarted = true;
    }
    
    try {
        // الحصول على آخر رقم فاتورة لهذا الشهر مع قفل الصف
        // نستخدم FOR UPDATE لضمان عدم قراءة نفس الرقم من طلبات متزامنة
        $result = $db->queryOne(
            "SELECT invoice_number, id FROM invoices 
             WHERE invoice_number LIKE ? 
             ORDER BY id DESC LIMIT 1
             FOR UPDATE",
            ["{$prefix}-%"]
        );
        
        $serial = 1;
        
        if ($result && !empty($result['invoice_number'])) {
            $invoiceNumber = $result['invoice_number'];
            
            // استخراج الرقم التسلسلي من رقم الفاتورة
            // الصيغة: INV-YYYYMM-NNNN أو INV-YYYYMM-NNNN-TIMESTAMP
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)(?:-.*)?$/', $invoiceNumber, $matches)) {
                $lastSerial = intval($matches[1] ?? 0);
                if ($lastSerial > 0) {
                    $serial = $lastSerial + 1;
                }
            } else {
                // إذا فشل استخراج الرقم، ابحث عن أكبر رقم تسلسلي
                $allInvoices = $db->query(
                    "SELECT invoice_number FROM invoices 
                     WHERE invoice_number LIKE ? 
                     ORDER BY id DESC LIMIT 200
                     FOR UPDATE",
                    ["{$prefix}-%"]
                );
                
                $maxSerial = 0;
                foreach ($allInvoices as $inv) {
                    $invNumber = $inv['invoice_number'] ?? '';
                    if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)(?:-.*)?$/', $invNumber, $invMatches)) {
                        $invSerial = intval($invMatches[1] ?? 0);
                        if ($invSerial > $maxSerial) {
                            $maxSerial = $invSerial;
                        }
                    }
                }
                $serial = $maxSerial + 1;
            }
        }
        
        // التأكد من عدم وجود فاتورة بنفس الرقم (حماية إضافية)
        // هذا يضمن عدم التكرار حتى في حالة إعادة بيع منتج مرتجع لنفس العميل
        $maxAttempts = 200;
        $attempt = 0;
        $invoiceNumber = sprintf("{$prefix}-%04d", $serial);
        
        while ($attempt < $maxAttempts) {
            $existing = $db->queryOne(
                "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1 FOR UPDATE",
                [$invoiceNumber]
            );
            
            if (!$existing) {
                // الرقم فريد، يمكن استخدامه
                if ($transactionStarted) {
                    $db->commit();
                }
                error_log("Generated unique invoice number: {$invoiceNumber}");
                return $invoiceNumber;
            }
            
            // الرقم موجود، جرب الرقم التالي
            $serial++;
            $invoiceNumber = sprintf("{$prefix}-%04d", $serial);
            $attempt++;
            
            // إذا تجاوزنا 100 محاولة، أضف timestamp لضمان التفرد
            if ($attempt > 100) {
                $timestamp = substr(time(), -6); // آخر 6 أرقام من timestamp
                $microtime = substr(str_replace('.', '', microtime(true)), -4); // آخر 4 أرقام من microtime
                $invoiceNumber = sprintf("{$prefix}-%04d-%s%s", $serial, $timestamp, $microtime);
            }
        }
        
        // في حالة فشل جميع المحاولات، استخدم timestamp + microtime لضمان التفرد
        $timestamp = substr(time(), -6);
        $microtime = substr(str_replace('.', '', microtime(true)), -4);
        $random = bin2hex(random_bytes(2)); // 4 أحرف عشوائية
        $finalNumber = sprintf("{$prefix}-%04d-%s%s-%s", $serial, $timestamp, $microtime, $random);
        
        // التحقق النهائي من عدم التكرار
        $existing = $db->queryOne(
            "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
            [$finalNumber]
        );
        
        if ($transactionStarted) {
            $db->commit();
        }
        
        if (!$existing) {
            error_log("Generated invoice number with timestamp fallback: {$finalNumber}");
            return $finalNumber;
        }
        
        // إذا كان موجوداً (مستحيل تقريباً)، أضف رقم عشوائي إضافي
        $finalNumber = sprintf("{$prefix}-%04d-%s%s-%s-%s", $serial, $timestamp, $microtime, $random, bin2hex(random_bytes(2)));
        error_log("Generated invoice number with extended fallback: {$finalNumber}");
        return $finalNumber;
        
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $db->rollback();
            } catch (Throwable $rollbackError) {
                // تجاهل خطأ rollback
            }
        }
        
        error_log("Error generating invoice number: " . $e->getMessage());
        
        // استخدام طريقة احتياطية بدون transaction
        $timestamp = substr(time(), -6);
        $microtime = substr(str_replace('.', '', microtime(true)), -4);
        $random = bin2hex(random_bytes(3));
        $fallbackNumber = sprintf("{$prefix}-%04d-%s%s-%s", rand(1000, 9999), $timestamp, $microtime, $random);
        
        // التحقق من عدم التكرار
        $existing = $db->queryOne(
            "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
            [$fallbackNumber]
        );
        
        if (!$existing) {
            return $fallbackNumber;
        }
        
        // إذا كان موجوداً، أضف رقم عشوائي إضافي
        $fallbackNumber = sprintf("{$prefix}-%04d-%s%s-%s-%s", rand(1000, 9999), $timestamp, $microtime, $random, bin2hex(random_bytes(2)));
        return $fallbackNumber;
    }
}

/**
 * توليد رقم فاتورة عشوائي يبدأ بـ 0 ثم أرقام عشوائية (لنقطة البيع الخاصة بالمندوب)
 * الصيغة: 0 + 4 أرقام عشوائية = 5 أرقام إجمالاً (مثل: 01234)
 */
function generateRandomInvoiceNumber() {
    $db = db();
    
    // استخدام transaction مع SELECT FOR UPDATE لضمان عدم التكرار
    $transactionStarted = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $transactionStarted = true;
    }
    
    try {
        $maxAttempts = 100;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            // توليد رقم يبدأ بـ 0 ثم 4 أرقام عشوائية (من 0 إلى 9999)
            // الصيغة: 0 + 4 أرقام عشوائية = 5 أرقام إجمالاً
            $randomDigits = rand(0, 9999);
            $invoiceNumber = '0' . str_pad((string)$randomDigits, 4, '0', STR_PAD_LEFT);
            
            // التحقق من عدم وجود فاتورة بنفس الرقم
            $existing = $db->queryOne(
                "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1 FOR UPDATE",
                [$invoiceNumber]
            );
            
            if (!$existing) {
                // الرقم فريد، يمكن استخدامه
                if ($transactionStarted) {
                    $db->commit();
                }
                error_log("Generated random invoice number: {$invoiceNumber}");
                return $invoiceNumber;
            }
            
            $attempt++;
        }
        
        // إذا فشلت جميع المحاولات، استخدم timestamp + رقم عشوائي
        $timestamp = substr(time(), -4);
        $random = rand(10, 99);
        $invoiceNumber = '0' . $timestamp . $random;
        
        // التحقق النهائي
        $existing = $db->queryOne(
            "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
            [$invoiceNumber]
        );
        
        if ($transactionStarted) {
            $db->commit();
        }
        
        if (!$existing) {
            error_log("Generated random invoice number with timestamp fallback: {$invoiceNumber}");
            return $invoiceNumber;
        }
        
        // إذا كان موجوداً، أضف رقم عشوائي إضافي
        $invoiceNumber = '0' . $timestamp . rand(100, 999);
        error_log("Generated random invoice number with extended fallback: {$invoiceNumber}");
        return $invoiceNumber;
        
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $db->rollback();
            } catch (Throwable $rollbackError) {
                // تجاهل خطأ rollback
            }
        }
        
        error_log("Error generating random invoice number: " . $e->getMessage());
        
        // استخدام طريقة احتياطية
        $randomDigits = rand(0, 9999);
        $invoiceNumber = '0' . str_pad((string)$randomDigits, 4, '0', STR_PAD_LEFT);
        $existing = $db->queryOne(
            "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
            [$invoiceNumber]
        );
        
        if (!$existing) {
            return $invoiceNumber;
        }
        
        // إذا كان موجوداً، استخدم timestamp
        $timestamp = substr(time(), -5);
        return '0' . $timestamp;
    }
}

/**
 * توليد رقم فاتورة عداد تصاعدي يبدأ من 1 (لنقطة البيع الخاصة بالمدير/المحاسب)
 * الأرقام من 1 إلى 9999 للعداد التصاعدي
 * الأرقام التي تبدأ بـ 0 (مثل 01234) هي من نقطة البيع الخاصة بالمندوب
 */
function generateSequentialInvoiceNumber() {
    $db = db();
    
    // استخدام transaction مع SELECT FOR UPDATE لضمان عدم التكرار
    $transactionStarted = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $transactionStarted = true;
    }
    
    try {
        // البحث عن آخر رقم فاتورة من نوع العداد التصاعدي (أرقام فقط بدون prefix)
        // نبحث عن أرقام من 1 إلى 9999 (عداد تصاعدي)
        // نستثني الأرقام التي تبدأ بـ 0 لأنها من نقطة البيع الخاصة بالمندوب
        $result = $db->queryOne(
            "SELECT invoice_number, id FROM invoices 
             WHERE invoice_number REGEXP '^[0-9]+$'
             AND invoice_number NOT LIKE '0%'
             AND CAST(invoice_number AS UNSIGNED) >= 1
             AND CAST(invoice_number AS UNSIGNED) < 10000
             ORDER BY CAST(invoice_number AS UNSIGNED) DESC, id DESC LIMIT 1
             FOR UPDATE"
        );
        
        $serial = 1;
        
        if ($result && !empty($result['invoice_number'])) {
            $lastInvoiceNumber = $result['invoice_number'];
            // التحقق من أن الرقم هو رقم صحيح
            if (preg_match('/^\d+$/', $lastInvoiceNumber)) {
                $lastSerial = intval($lastInvoiceNumber);
                if ($lastSerial > 0) {
                    $serial = $lastSerial + 1;
                }
            }
        }
        
        // التأكد من عدم وجود فاتورة بنفس الرقم
        $maxAttempts = 1000;
        $attempt = 0;
        $invoiceNumber = (string)$serial;
        
        while ($attempt < $maxAttempts) {
            $existing = $db->queryOne(
                "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1 FOR UPDATE",
                [$invoiceNumber]
            );
            
            if (!$existing) {
                // الرقم فريد، يمكن استخدامه
                if ($transactionStarted) {
                    $db->commit();
                }
                error_log("Generated sequential invoice number: {$invoiceNumber}");
                return $invoiceNumber;
            }
            
            // الرقم موجود، جرب الرقم التالي
            $serial++;
            $invoiceNumber = (string)$serial;
            $attempt++;
        }
        
        // في حالة فشل جميع المحاولات (مستحيل تقريباً)، استخدم timestamp
        $timestamp = time();
        $invoiceNumber = (string)$timestamp;
        
        // التحقق النهائي
        $existing = $db->queryOne(
            "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
            [$invoiceNumber]
        );
        
        if ($transactionStarted) {
            $db->commit();
        }
        
        if (!$existing) {
            error_log("Generated sequential invoice number with timestamp fallback: {$invoiceNumber}");
            return $invoiceNumber;
        }
        
        // إذا كان موجوداً (مستحيل تقريباً)، أضف رقم عشوائي
        $invoiceNumber = (string)($timestamp . rand(100, 999));
        error_log("Generated sequential invoice number with extended fallback: {$invoiceNumber}");
        return $invoiceNumber;
        
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $db->rollback();
            } catch (Throwable $rollbackError) {
                // تجاهل خطأ rollback
            }
        }
        
        error_log("Error generating sequential invoice number: " . $e->getMessage());
        
        // استخدام طريقة احتياطية
        $serial = 1;
        $existing = $db->queryOne(
            "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
            [(string)$serial]
        );
        
        if (!$existing) {
            return (string)$serial;
        }
        
        // إذا كان موجوداً، جرب الرقم التالي
        $serial = 2;
        $existing = $db->queryOne(
            "SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1",
            [(string)$serial]
        );
        
        if (!$existing) {
            return (string)$serial;
        }
        
        // إذا كان موجوداً أيضاً، استخدم timestamp
        $timestamp = time();
        return (string)$timestamp;
    }
}

/**
 * الحصول على فاتورة
 */
function getInvoice($invoiceId) {
    $db = db();
    
    $invoice = $db->queryOne(
        "SELECT i.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
                u.full_name as sales_rep_name, u2.username as created_by_name
         FROM invoices i
         LEFT JOIN customers c ON i.customer_id = c.id
         LEFT JOIN users u ON i.sales_rep_id = u.id
         LEFT JOIN users u2 ON i.created_by = u2.id
         WHERE i.id = ?",
        [$invoiceId]
    );
    
    if ($invoice) {
        // جلب عناصر الفاتورة مع رقم التشغيلة من مصادر متعددة
        $invoice['items'] = $db->query(
            "SELECT ii.*, 
                    p.name as product_name, 
                    p.unit,
                    -- جلب رقم التشغيلة من sales_batch_numbers أولاً
                    COALESCE(
                        -- 1. من sales_batch_numbers -> batch_numbers
                        (SELECT GROUP_CONCAT(DISTINCT 
                            CASE 
                                WHEN bn2.batch_number IS NOT NULL AND TRIM(bn2.batch_number) != '' 
                                THEN bn2.batch_number 
                                ELSE NULL 
                            END
                            ORDER BY bn2.batch_number 
                            SEPARATOR ', '
                        )
                        FROM sales_batch_numbers sbn2
                        INNER JOIN batch_numbers bn2 ON sbn2.batch_number_id = bn2.id
                        WHERE sbn2.invoice_item_id = ii.id
                          AND bn2.batch_number IS NOT NULL 
                          AND TRIM(bn2.batch_number) != ''),
                        -- 2. من shipping_company_order_items -> finished_products (للفواتير من طلبات الشحن)
                        (SELECT GROUP_CONCAT(DISTINCT 
                            CASE 
                                WHEN fp.batch_number IS NOT NULL AND TRIM(fp.batch_number) != '' 
                                THEN fp.batch_number 
                                ELSE NULL 
                            END
                            ORDER BY fp.batch_number 
                            SEPARATOR ', '
                        )
                        FROM shipping_company_orders sco
                        INNER JOIN shipping_company_order_items scoi ON sco.id = scoi.order_id
                        INNER JOIN finished_products fp ON scoi.batch_id = fp.id
                        WHERE sco.invoice_id = ii.invoice_id
                          AND scoi.product_id = ii.product_id
                          AND ABS(scoi.quantity - ii.quantity) < 0.001
                          AND ABS(scoi.unit_price - ii.unit_price) < 0.001
                          AND fp.batch_number IS NOT NULL 
                          AND TRIM(fp.batch_number) != ''),
                        -- 3. من description إذا كان يحتوي على رقم تشغيلة (مثل: اسم المنتج (رقم التشغيلة))
                        CASE 
                            WHEN ii.description REGEXP '\\\\([A-Za-z0-9-]+\\\\)$'
                            THEN SUBSTRING(ii.description, 
                                 LOCATE('(', ii.description) + 1, 
                                 LOCATE(')', ii.description) - LOCATE('(', ii.description) - 1)
                            ELSE NULL
                        END,
                        ''
                    ) as batch_numbers
             FROM invoice_items ii
             LEFT JOIN products p ON ii.product_id = p.id
             WHERE ii.invoice_id = ?
             GROUP BY ii.id
             ORDER BY ii.id",
            [$invoiceId]
        );
        
        // إضافة batch_number لكل عنصر (أول رقم تشغيلة أو جميع الأرقام)
        foreach ($invoice['items'] as &$item) {
            // التأكد من أن batch_numbers ليس NULL أو سلسلة فارغة
            $batchNumbers = isset($item['batch_numbers']) ? trim((string)$item['batch_numbers']) : '';
            $item['batch_number'] = !empty($batchNumbers) ? $batchNumbers : null;
            
            // إذا كان اسم المنتج يحتوي على رقم التشغيلة في الأقواس، نستخرجه
            if (empty($item['batch_number']) && !empty($item['description'])) {
                if (preg_match('/\(([A-Za-z0-9-]+)\)$/', $item['description'], $matches)) {
                    $item['batch_number'] = $matches[1];
                    // تنظيف اسم المنتج من رقم التشغيلة للعرض
                    $item['product_name_clean'] = trim(preg_replace('/\s*\([A-Za-z0-9-]+\)$/', '', $item['description']));
                }
            }
        }
        unset($item);
        
        // حساب remaining_amount إذا لم يكن موجوداً في قاعدة البيانات
        if (!isset($invoice['remaining_amount']) || $invoice['remaining_amount'] === null) {
            $totalAmount = (float)($invoice['total_amount'] ?? 0);
            $paidAmount = (float)($invoice['paid_amount'] ?? 0);
            $invoice['remaining_amount'] = max(0, round($totalAmount - $paidAmount, 2));
        } else {
            // التأكد من أن remaining_amount هو رقم صحيح
            $invoice['remaining_amount'] = (float)$invoice['remaining_amount'];
        }
    }
    
    return $invoice;
}

/**
 * الحصول على فاتورة بواسطة رقم الفاتورة مع العناصر
 */
function getInvoiceByNumberDetailed($invoiceNumber) {
    if (!$invoiceNumber) {
        return null;
    }

    $db = db();

    $invoice = $db->queryOne(
        "SELECT i.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
                c.balance as customer_balance,
                u.full_name as sales_rep_name, u.id as sales_rep_user_id,
                u.username as sales_rep_username, u.phone as sales_rep_phone
         FROM invoices i
         LEFT JOIN customers c ON i.customer_id = c.id
         LEFT JOIN users u ON i.sales_rep_id = u.id
         WHERE i.invoice_number = ?",
        [$invoiceNumber]
    );

    if ($invoice) {
        $invoice['items'] = $db->query(
            "SELECT ii.*, p.name as product_name, p.unit,
                    GROUP_CONCAT(DISTINCT 
                        CASE 
                            WHEN bn.batch_number IS NOT NULL AND TRIM(bn.batch_number) != '' 
                            THEN bn.batch_number 
                            ELSE NULL 
                        END
                        ORDER BY bn.batch_number 
                        SEPARATOR ', '
                    ) as batch_numbers
             FROM invoice_items ii
             LEFT JOIN products p ON ii.product_id = p.id
             LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
             LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id AND bn.batch_number IS NOT NULL
             WHERE ii.invoice_id = ?
             GROUP BY ii.id
             ORDER BY ii.id",
            [$invoice['id']]
        );
        
        // إضافة batch_number لكل عنصر (أول رقم تشغيلة أو جميع الأرقام)
        foreach ($invoice['items'] as &$item) {
            // التأكد من أن batch_numbers ليس NULL أو سلسلة فارغة
            $batchNumbers = isset($item['batch_numbers']) ? trim((string)$item['batch_numbers']) : '';
            $item['batch_number'] = !empty($batchNumbers) ? $batchNumbers : null;
        }
        unset($item);
    }

    return $invoice;
}

/**
 * تحديث حالة الفاتورة
 */
function updateInvoiceStatus($invoiceId, $status, $updatedBy = null) {
    try {
        $db = db();
        
        if ($updatedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $updatedBy = $currentUser['id'] ?? null;
        }
        
        $oldInvoice = getInvoice($invoiceId);
        
        // التحقق من وجود عمود created_from_pos وعدم تحديث فواتير نقطة البيع
        $hasCreatedFromPosColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'created_from_pos'"));
        if ($hasCreatedFromPosColumn) {
            $invoiceCheck = $db->queryOne("SELECT created_from_pos FROM invoices WHERE id = ?", [$invoiceId]);
            if (!empty($invoiceCheck) && !empty($invoiceCheck['created_from_pos'])) {
                // هذه فاتورة من نقطة البيع، لا يمكن تحديثها
                return ['success' => false, 'message' => 'لا يمكن تحديث فاتورة تم إنشاؤها من نقطة البيع'];
            }
        }
        
        $sql = "UPDATE invoices SET status = ?, updated_at = NOW() WHERE id = ?";
        $db->execute($sql, [$status, $invoiceId]);
        
        // إذا تم الدفع، تحديث paid_amount
        if ($status === 'paid') {
            $db->execute(
                "UPDATE invoices SET paid_amount = total_amount, updated_at = NOW() WHERE id = ?",
                [$invoiceId]
            );
            
            // إرسال إشعار
            createNotification(
                $oldInvoice['created_by'],
                'تم دفع الفاتورة',
                "تم دفع الفاتورة رقم {$oldInvoice['invoice_number']}",
                'success',
                "dashboard/accountant.php?page=invoices&id={$invoiceId}"
            );
        }
        
        // تسجيل سجل التدقيق
        logAudit($updatedBy, 'update_invoice_status', 'invoice', $invoiceId, 
                 ['old_status' => $oldInvoice['status']], 
                 ['new_status' => $status]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Update Invoice Status Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تحديث حالة الفاتورة'];
    }
}

/**
 * تسجيل دفعة على فاتورة
 */
function recordInvoicePayment($invoiceId, $amount, $notes = null, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        $invoice = getInvoice($invoiceId);
        
        if (!$invoice) {
            return ['success' => false, 'message' => 'الفاتورة غير موجودة'];
        }
        
        // التحقق من وجود عمود created_from_pos وعدم تحديث فواتير نقطة البيع
        $hasCreatedFromPosColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'created_from_pos'"));
        if ($hasCreatedFromPosColumn) {
            $invoiceCheck = $db->queryOne("SELECT created_from_pos FROM invoices WHERE id = ?", [$invoiceId]);
            if (!empty($invoiceCheck) && !empty($invoiceCheck['created_from_pos'])) {
                // هذه فاتورة من نقطة البيع، لا يمكن تحديثها
                return ['success' => false, 'message' => 'لا يمكن تحديث فاتورة تم إنشاؤها من نقطة البيع'];
            }
        }
        
        $newPaidAmount = $invoice['paid_amount'] + $amount;
        
        // تحديث المبلغ المدفوع
        $db->execute(
            "UPDATE invoices SET paid_amount = ?, updated_at = NOW() WHERE id = ?",
            [$newPaidAmount, $invoiceId]
        );
        
        // إذا تم دفع المبلغ بالكامل، تحديث الحالة
        if ($newPaidAmount >= $invoice['total_amount']) {
            updateInvoiceStatus($invoiceId, 'paid', $createdBy);
        } else {
            updateInvoiceStatus($invoiceId, 'sent', $createdBy);
        }
        
        // تسجيل سجل التدقيق
        logAudit($createdBy, 'invoice_payment', 'invoice', $invoiceId, 
                 ['old_paid' => $invoice['paid_amount']], 
                 ['new_paid' => $newPaidAmount, 'payment' => $amount]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Invoice Payment Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تسجيل الدفعة'];
    }
}

/**
 * توزيع التحصيل على فواتير العميل وتحديثها
 * يتم توزيع المبلغ على الفواتير من الأقدم للأحدث
 */
function distributeCollectionToInvoices($customerId, $amount, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        // التحقق من حالة 'partial' في enum مرة واحدة فقط
        $statusCheck = $db->queryOne("SHOW COLUMNS FROM invoices WHERE Field = 'status'");
        $statusEnum = $statusCheck['Type'] ?? '';
        $hasPartialStatus = strpos($statusEnum, 'partial') !== false;
        
        // التحقق من وجود عمود created_from_pos
        $hasCreatedFromPosColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'created_from_pos'"));
        
        // الحصول على فواتير العميل التي لم يتم دفعها بالكامل، مرتبة من الأقدم للأحدث
        // استبعاد الفواتير التي تم إنشاؤها من نقطة البيع (created_from_pos = 1)
        $sql = "SELECT id, invoice_number, total_amount, paid_amount, status 
                FROM invoices 
                WHERE customer_id = ? 
                AND status NOT IN ('paid', 'cancelled')
                AND (total_amount - paid_amount) > 0";
        
        if ($hasCreatedFromPosColumn) {
            $sql .= " AND (created_from_pos IS NULL OR created_from_pos = 0)";
        }
        
        $sql .= " ORDER BY date ASC, created_at ASC";
        
        $invoices = $db->query($sql, [$customerId]);
        
        if (empty($invoices)) {
            return ['success' => true, 'message' => 'لا توجد فواتير معلقة للعميل'];
        }
        
        $remainingAmount = $amount;
        $updatedInvoices = [];
        
        foreach ($invoices as $invoice) {
            if ($remainingAmount <= 0) {
                break;
            }
            
            $invoiceRemaining = $invoice['total_amount'] - $invoice['paid_amount'];
            $paymentAmount = min($remainingAmount, $invoiceRemaining);
            
            // تحديث الفاتورة
            $newPaidAmount = $invoice['paid_amount'] + $paymentAmount;
            $newRemaining = $invoice['total_amount'] - $newPaidAmount;
            
            // تحديد الحالة الجديدة
            $newStatus = $invoice['status'];
            if ($newRemaining <= 0.0001) {
                // تم دفع الفاتورة بالكامل
                $newStatus = 'paid';
            } elseif ($newPaidAmount > 0 && $newRemaining > 0.0001) {
                // تم دفع جزء من الفاتورة
                if ($invoice['status'] === 'draft') {
                    $newStatus = 'sent';
                } elseif ($hasPartialStatus) {
                    $newStatus = 'partial';
                } else {
                    // إذا لم تكن 'partial' مدعومة، استخدم 'sent'
                    $newStatus = 'sent';
                }
            }
            
            // التحقق من وجود عمود remaining_amount
            $remainingColumnCheck = $db->queryOne("SHOW COLUMNS FROM invoices LIKE 'remaining_amount'");
            $hasRemainingColumn = !empty($remainingColumnCheck);
            
            // تحديث الفاتورة
            if ($hasRemainingColumn) {
                $db->execute(
                    "UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() WHERE id = ?",
                    [$newPaidAmount, $newRemaining, $newStatus, $invoice['id']]
                );
            } else {
                $db->execute(
                    "UPDATE invoices SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ?",
                    [$newPaidAmount, $newStatus, $invoice['id']]
                );
            }
            
            // تسجيل سجل التدقيق
            logAudit($createdBy, 'invoice_payment_from_collection', 'invoice', $invoice['id'], 
                     ['old_paid' => $invoice['paid_amount'], 'old_status' => $invoice['status']], 
                     ['new_paid' => $newPaidAmount, 'new_status' => $newStatus, 'payment' => $paymentAmount]);
            
            $updatedInvoices[] = [
                'invoice_id' => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'payment_amount' => $paymentAmount,
                'new_status' => $newStatus
            ];
            
            $remainingAmount -= $paymentAmount;
        }
        
        return [
            'success' => true,
            'updated_invoices' => $updatedInvoices,
            'remaining_amount' => $remainingAmount
        ];
        
    } catch (Exception $e) {
        error_log("Distribute Collection Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في توزيع التحصيل على الفواتير'];
    }
}

/**
 * الحصول على قائمة الفواتير
 */
function getInvoices($filters = [], $limit = 50, $offset = 0) {
    $db = db();
    
    $sql = "SELECT i.*, c.name as customer_name, u.full_name as sales_rep_name
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u ON i.sales_rep_id = u.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['customer_id'])) {
        $sql .= " AND i.customer_id = ?";
        $params[] = $filters['customer_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND i.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(i.date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(i.date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    if (!empty($filters['invoice_number'])) {
        $sql .= " AND i.invoice_number LIKE ?";
        $params[] = "%{$filters['invoice_number']}%";
    }
    
    $sql .= " ORDER BY i.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $invoices = $db->query($sql, $params);
    
    // حساب remaining_amount لكل فاتورة إذا لم يكن موجوداً
    foreach ($invoices as &$invoice) {
        if (!isset($invoice['remaining_amount']) || $invoice['remaining_amount'] === null) {
            $totalAmount = (float)($invoice['total_amount'] ?? 0);
            $paidAmount = (float)($invoice['paid_amount'] ?? 0);
            $invoice['remaining_amount'] = max(0, round($totalAmount - $paidAmount, 2));
        } else {
            $invoice['remaining_amount'] = (float)$invoice['remaining_amount'];
        }
    }
    unset($invoice);
    
    return $invoices;
}

/**
 * الحصول على عدد الفواتير
 */
function getInvoicesCount($filters = []) {
    $db = db();
    
    $sql = "SELECT COUNT(*) as count FROM invoices WHERE 1=1";
    $params = [];
    
    if (!empty($filters['customer_id'])) {
        $sql .= " AND customer_id = ?";
        $params[] = $filters['customer_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    if (!empty($filters['invoice_number'])) {
        $sql .= " AND invoice_number LIKE ?";
        $params[] = "%{$filters['invoice_number']}%";
    }
    
    $result = $db->queryOne($sql, $params);
    return $result['count'] ?? 0;
}

/**
 * الحصول على قائمة الفواتير الورقية (محلي + شركات شحن) مع فلترة وترتيب موحد
 * @param array $filters ['date_from'=>'', 'date_to'=>'', 'invoice_number'=>'']
 * @param int $limit
 * @param int $offset
 * @return array عناصر بصيغة: id, invoice_number, total_amount, date, customer_name, type (local|shipping), image_path, view_image_url
 */
function getPaperInvoices($filters = [], $limit = 50, $offset = 0) {
    $db = db();
    $merged = [];
    $dateFrom = $filters['date_from'] ?? null;
    $dateTo = $filters['date_to'] ?? null;
    $invoiceNumber = isset($filters['invoice_number']) ? trim($filters['invoice_number']) : '';

    // فواتير ورقية عملاء محليين
    $localTable = $db->queryOne("SHOW TABLES LIKE 'local_customer_paper_invoices'");
    if (!empty($localTable)) {
        $hasInvoiceNumber = !empty($db->queryOne("SHOW COLUMNS FROM local_customer_paper_invoices LIKE 'invoice_number'"));
        $sql = "SELECT p.id, p.invoice_number, p.total_amount, p.image_path, p.created_at,
                l.name as customer_name
                FROM local_customer_paper_invoices p
                LEFT JOIN local_customers l ON p.customer_id = l.id
                WHERE 1=1";
        $params = [];
        if ($dateFrom) {
            $sql .= " AND DATE(p.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND DATE(p.created_at) <= ?";
            $params[] = $dateTo;
        }
        if ($invoiceNumber !== '' && $hasInvoiceNumber) {
            $sql .= " AND (p.invoice_number LIKE ? OR CAST(p.id AS CHAR) LIKE ?)";
            $params[] = "%{$invoiceNumber}%";
            $params[] = "%{$invoiceNumber}%";
        }
        $sql .= " ORDER BY p.created_at DESC";
        $localRows = $db->query($sql, $params) ?: [];
        foreach ($localRows as $row) {
            $merged[] = [
                'id' => (int)$row['id'],
                'invoice_number' => $row['invoice_number'] ?: ('ورقية-' . $row['id']),
                'total_amount' => (float)$row['total_amount'],
                'date' => $row['created_at'],
                'customer_name' => $row['customer_name'] ?? '-',
                'type' => 'local',
                'image_path' => $row['image_path'],
            ];
        }
    }

    // فواتير ورقية شركات الشحن
    $shippingTable = $db->queryOne("SHOW TABLES LIKE 'shipping_company_paper_invoices'");
    if (!empty($shippingTable)) {
        $sql = "SELECT p.id, p.invoice_number, p.total_amount, p.image_path, p.created_at,
                s.name as customer_name
                FROM shipping_company_paper_invoices p
                LEFT JOIN shipping_companies s ON p.shipping_company_id = s.id
                WHERE 1=1";
        $params = [];
        if ($dateFrom) {
            $sql .= " AND DATE(p.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND DATE(p.created_at) <= ?";
            $params[] = $dateTo;
        }
        if ($invoiceNumber !== '') {
            $sql .= " AND (p.invoice_number LIKE ? OR CAST(p.id AS CHAR) LIKE ?)";
            $params[] = "%{$invoiceNumber}%";
            $params[] = "%{$invoiceNumber}%";
        }
        $sql .= " ORDER BY p.created_at DESC";
        $shipRows = $db->query($sql, $params) ?: [];
        foreach ($shipRows as $row) {
            $merged[] = [
                'id' => (int)$row['id'],
                'invoice_number' => $row['invoice_number'] ?: ('ورقية-' . $row['id']),
                'total_amount' => (float)$row['total_amount'],
                'date' => $row['created_at'],
                'customer_name' => $row['customer_name'] ?? '-',
                'type' => 'shipping',
                'image_path' => $row['image_path'],
            ];
        }
    }

    // ترتيب موحد حسب التاريخ (الأحدث أولاً) ثم تطبيق الترقيم
    usort($merged, function ($a, $b) {
        $t1 = strtotime($a['date'] ?? 0);
        $t2 = strtotime($b['date'] ?? 0);
        return $t2 - $t1;
    });

    return array_slice($merged, $offset, $limit);
}

/**
 * عدد الفواتير الورقية الإجمالي (محلي + شركات شحن) مع نفس الفلاتر
 */
function getPaperInvoicesCount($filters = []) {
    $db = db();
    $total = 0;
    $dateFrom = $filters['date_from'] ?? null;
    $dateTo = $filters['date_to'] ?? null;
    $invoiceNumber = isset($filters['invoice_number']) ? trim($filters['invoice_number']) : '';

    $localTable = $db->queryOne("SHOW TABLES LIKE 'local_customer_paper_invoices'");
    if (!empty($localTable)) {
        $hasInvNum = !empty($db->queryOne("SHOW COLUMNS FROM local_customer_paper_invoices LIKE 'invoice_number'"));
        $sql = "SELECT COUNT(*) as c FROM local_customer_paper_invoices p WHERE 1=1";
        $params = [];
        if ($dateFrom) { $sql .= " AND DATE(p.created_at) >= ?"; $params[] = $dateFrom; }
        if ($dateTo) { $sql .= " AND DATE(p.created_at) <= ?"; $params[] = $dateTo; }
        if ($invoiceNumber !== '' && $hasInvNum) {
            $sql .= " AND (p.invoice_number LIKE ? OR CAST(p.id AS CHAR) LIKE ?)";
            $params[] = "%{$invoiceNumber}%";
            $params[] = "%{$invoiceNumber}%";
        }
        $r = $db->queryOne($sql, $params);
        $total += (int)($r['c'] ?? 0);
    }

    $shippingTable = $db->queryOne("SHOW TABLES LIKE 'shipping_company_paper_invoices'");
    if (!empty($shippingTable)) {
        $sql = "SELECT COUNT(*) as c FROM shipping_company_paper_invoices p WHERE 1=1";
        $params = [];
        if ($dateFrom) { $sql .= " AND DATE(p.created_at) >= ?"; $params[] = $dateFrom; }
        if ($dateTo) { $sql .= " AND DATE(p.created_at) <= ?"; $params[] = $dateTo; }
        if ($invoiceNumber !== '') {
            $sql .= " AND (p.invoice_number LIKE ? OR CAST(p.id AS CHAR) LIKE ?)";
            $params[] = "%{$invoiceNumber}%";
            $params[] = "%{$invoiceNumber}%";
        }
        $r = $db->queryOne($sql, $params);
        $total += (int)($r['c'] ?? 0);
    }

    return $total;
}

/**
 * حذف فاتورة
 */
function deleteInvoice($invoiceId, $deletedBy = null) {
    try {
        $db = db();
        
        if ($deletedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $deletedBy = $currentUser['id'] ?? null;
        }
        
        $invoice = getInvoice($invoiceId);
        
        if ($invoice['status'] === 'paid') {
            return ['success' => false, 'message' => 'لا يمكن حذف فاتورة مدفوعة'];
        }
        
        // حذف عناصر الفاتورة
        $db->execute("DELETE FROM invoice_items WHERE invoice_id = ?", [$invoiceId]);
        
        // حذف الفاتورة
        $db->execute("DELETE FROM invoices WHERE id = ?", [$invoiceId]);
        
        // تسجيل سجل التدقيق
        logAudit($deletedBy, 'delete_invoice', 'invoice', $invoiceId, json_encode($invoice), null);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Delete Invoice Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في حذف الفاتورة'];
    }
}

/**
 * إنشاء فاتورة PDF لتسوية مرتب موظف
 */
function generateSalarySettlementInvoice($settlementId, $salary, $settlementAmount, $previousAccumulated, $remainingAfter, $settlementDate, $notes = null) {
    try {
        require_once __DIR__ . '/path_helper.php';
        
        $db = db();
        
        // الحصول على بيانات التسوية
        $settlement = $db->queryOne(
            "SELECT ss.*, u.full_name as employee_name, u.username, creator.full_name as created_by_name
             FROM salary_settlements ss
             LEFT JOIN users u ON ss.user_id = u.id
             LEFT JOIN users creator ON ss.created_by = creator.id
             WHERE ss.id = ?",
            [$settlementId]
        );
        
        if (!$settlement) {
            return null;
        }
        
        $companyName = COMPANY_NAME ?? 'شركة';
        $invoiceNumber = 'SETT-' . date('Ymd') . '-' . str_pad($settlementId, 4, '0', STR_PAD_LEFT);
        
        // إنشاء محتوى HTML للفاتورة
        $html = generateSalarySettlementInvoiceHTML($settlement, $salary, $settlementAmount, $previousAccumulated, $remainingAfter, $settlementDate, $notes, $companyName, $invoiceNumber);
        
        // حفظ الفاتورة كملف HTML
        $invoicesDir = __DIR__ . '/../invoices/salary_settlements/';
        if (!is_dir($invoicesDir)) {
            mkdir($invoicesDir, 0755, true);
        }
        
        $filename = 'settlement_' . $settlementId . '_' . date('YmdHis') . '.html';
        $filepath = $invoicesDir . $filename;
        
        file_put_contents($filepath, $html);
        
        // إرجاع المسار النسبي
        return 'invoices/salary_settlements/' . $filename;
        
    } catch (Exception $e) {
        error_log('Error generating salary settlement invoice: ' . $e->getMessage());
        return null;
    }
}

/**
 * توليد HTML فاتورة تسوية المرتب
 */
function generateSalarySettlementInvoiceHTML($settlement, $salary, $settlementAmount, $previousAccumulated, $remainingAfter, $settlementDate, $notes, $companyName, $invoiceNumber) {
    $settlementTypeLabel = $settlement['settlement_type'] === 'full' ? 'تسوية كاملة' : 'تسوية جزئية';
    $formattedDate = formatDate($settlementDate);
    
    $html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة تسوية مرتب - ' . htmlspecialchars($invoiceNumber) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 30%, #60a5fa 60%, #93c5fd 100%);
            padding: 20px;
            color: #1e293b;
        }
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .invoice-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 30%, #60a5fa 60%, #93c5fd 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .invoice-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .invoice-header .invoice-number {
            font-size: 18px;
            opacity: 0.9;
        }
        .invoice-body {
            padding: 30px;
        }
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-box {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border-right: 4px solid #3b82f6;
        }
        .info-box label {
            display: block;
            font-size: 12px;
            color: #64748b;
            margin-bottom: 5px;
        }
        .info-box .value {
            font-size: 18px;
            font-weight: bold;
            color: #1e293b;
        }
        .amounts-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .amounts-table th {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 15px;
            text-align: right;
            font-weight: 600;
        }
        .amounts-table td {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        .amounts-table tr:last-child td {
            border-bottom: none;
        }
        .amount-primary { color: #3b82f6; font-weight: bold; }
        .amount-success { color: #10b981; font-weight: bold; }
        .amount-warning { color: #f59e0b; font-weight: bold; }
        .notes-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border-right: 4px solid #10b981;
        }
        .notes-section h3 {
            color: #1e293b;
            margin-bottom: 10px;
        }
        .footer {
            padding: 20px 30px;
            background: #f8fafc;
            text-align: center;
            color: #64748b;
            font-size: 12px;
        }
        @media print {
            body { background: white; padding: 0; }
            .invoice-container { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="invoice-header">
            <h1>فاتورة تسوية مرتب موظف</h1>
            <div class="invoice-number">رقم الفاتورة: ' . htmlspecialchars($invoiceNumber) . '</div>
        </div>
        <div class="invoice-body">
            <div class="info-section">
                <div class="info-box">
                    <label>الموظف</label>
                    <div class="value">' . htmlspecialchars($settlement['employee_name'] ?? $settlement['username'] ?? 'غير محدد') . '</div>
                </div>
                <div class="info-box">
                    <label>تاريخ التسوية</label>
                    <div class="value">' . htmlspecialchars($formattedDate) . '</div>
                </div>
                <div class="info-box">
                    <label>نوع التسوية</label>
                    <div class="value">' . htmlspecialchars($settlementTypeLabel) . '</div>
                </div>
                <div class="info-box">
                    <label>من قام بالتسوية</label>
                    <div class="value">' . htmlspecialchars($settlement['created_by_name'] ?? 'غير محدد') . '</div>
                </div>
            </div>
            
            <table class="amounts-table">
                <thead>
                    <tr>
                        <th>الوصف</th>
                        <th>المبلغ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>المبلغ التراكمي السابق</strong></td>
                        <td class="amount-primary">' . number_format($previousAccumulated, 2) . ' ج.م</td>
                    </tr>
                    <tr>
                        <td><strong>المبلغ المسدد</strong></td>
                        <td class="amount-success">' . number_format($settlementAmount, 2) . ' ج.م</td>
                    </tr>
                    <tr>
                        <td><strong>المتبقي بعد التسوية</strong></td>
                        <td class="amount-warning">' . number_format($remainingAfter, 2) . ' ج.م</td>
                    </tr>
                </tbody>
            </table>';
    
    if ($notes) {
        $html .= '
            <div class="notes-section">
                <h3>ملاحظات</h3>
                <p>' . nl2br(htmlspecialchars($notes)) . '</p>
            </div>';
    }
    
    $html .= '
        </div>
        <div class="footer">
            <p>' . htmlspecialchars($companyName) . ' - ' . date('Y') . '</p>
            <p>تم إنشاء هذه الفاتورة تلقائياً من النظام</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}


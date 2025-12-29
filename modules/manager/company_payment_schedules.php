<?php
/**
 * صفحة إدارة الجداول الزمنية للتحصيل - العملاء المحليين فقط
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payment_schedules.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole(['manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// إظهار رسالة نجاح بعد إعادة التوجيه من إنشاء موعد جديد
if (isset($_GET['created']) && $_GET['created'] == '1') {
    $success = 'تم إضافة موعد التحصيل بنجاح.';
}

// التحقق من وجود جدول local_customers
$localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
if (empty($localCustomersTableExists)) {
    die('جدول العملاء المحليين غير موجود. يرجى التأكد من إعداد قاعدة البيانات.');
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'due_date_from' => $_GET['due_date_from'] ?? '',
    'due_date_to' => $_GET['due_date_to'] ?? '',
    'overdue_only' => isset($_GET['overdue_only']) ? true : false
];

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة طلبات AJAX لجلب عدد أيام التنبيه
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_reminder_days') {
    // تنظيف أي output buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $scheduleId = intval($_GET['schedule_id'] ?? 0);
    
    if ($scheduleId > 0) {
        $reminder = $db->queryOne(
            "SELECT days_before_due FROM payment_reminders 
             WHERE payment_schedule_id = ? AND reminder_type = 'before_due' 
             ORDER BY created_at DESC LIMIT 1",
            [$scheduleId]
        );
        
        header('Content-Type: application/json; charset=utf-8');
        if ($reminder && isset($reminder['days_before_due'])) {
            echo json_encode([
                'success' => true,
                'days_before_due' => (int)$reminder['days_before_due']
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'days_before_due' => 3
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

// دالة مساعدة للتحقق من أن العميل هو عميل محلي
function isLocalCustomer($customerId) {
    $db = db();
    $localCustomer = $db->queryOne(
        "SELECT id FROM local_customers WHERE id = ? AND status = 'active'",
        [$customerId]
    );
    return !empty($localCustomer);
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'record_payment') {
        $scheduleId = intval($_POST['schedule_id'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $amount = !empty($_POST['amount']) ? floatval($_POST['amount']) : null;
        $notes = trim($_POST['notes'] ?? '');
        
        if ($scheduleId > 0) {
            try {
                // استعلام مبسط وسريع - التحقق من أن الجدول مرتبط بعميل محلي
                $schedule = $db->queryOne(
                    "SELECT ps.*, lc.status as customer_status 
                     FROM payment_schedules ps
                     INNER JOIN local_customers lc ON ps.customer_id = lc.id
                     WHERE ps.id = ? AND lc.status = 'active'",
                    [$scheduleId]
                );
                
                if (!$schedule) {
                    $error = 'الجدول الزمني غير موجود أو غير مرتبط بعميل محلي نشط.';
                } elseif ($schedule['status'] === 'paid') {
                    $error = 'تم دفع هذه الدفعة بالفعل.';
                } else {
                    // فقط تحديث payment_schedules - لا تأثير على أي حسابات
                    $paymentAmount = $amount ?? (float)$schedule['amount'];
                    $oldStatus = $schedule['status'];
                    
                    $db->execute(
                        "UPDATE payment_schedules 
                         SET payment_date = ?, status = 'paid', updated_at = NOW() 
                         WHERE id = ?",
                        [$paymentDate, $scheduleId]
                    );
                    
                    // تسجيل audit log بشكل غير متزامن (لا ينتظر)
                    try {
                        logAudit(
                            $currentUser['id'],
                            'record_payment_schedule_local',
                            'payment_schedule',
                            $scheduleId,
                            ['old_status' => $oldStatus],
                            [
                                'new_status' => 'paid',
                                'amount' => $paymentAmount,
                                'local_customer' => true,
                                'note' => 'موعد تذكير فقط - لا تأثير على الحسابات'
                            ]
                        );
                    } catch (Throwable $auditError) {
                        // تجاهل أخطاء audit log حتى لا تؤثر على الاستجابة
                        error_log('Audit log error (non-blocking): ' . $auditError->getMessage());
                    }
                    
                    $success = 'تم تسجيل الدفعة في موعد التذكير بنجاح. (ملاحظة: هذا مجرد تذكير ولا يؤثر على حسابات العميل)';
                }
            } catch (Throwable $e) {
                error_log('Record payment error: ' . $e->getMessage());
                $error = 'حدث خطأ أثناء تسجيل الدفعة: ' . $e->getMessage();
            }
        } else {
            $error = 'معرف الجدول الزمني غير صحيح.';
        }
    } elseif ($action === 'create_reminder') {
        $scheduleId = intval($_POST['schedule_id'] ?? 0);
        $daysBeforeDueRaw = $_POST['days_before_due'] ?? null;
        
        if ($daysBeforeDueRaw !== null && $daysBeforeDueRaw !== '') {
            $daysBeforeDue = intval($daysBeforeDueRaw);
        } else {
            $daysBeforeDue = 3;
        }
        
        if ($daysBeforeDue < 1 || $daysBeforeDue > 30) {
            $daysBeforeDue = 3;
        }
        
        if ($scheduleId > 0) {
            if (createAutoReminder($scheduleId, $daysBeforeDue)) {
                $success = 'تم إنشاء التذكير بنجاح';
            } else {
                $error = 'حدث خطأ في إنشاء التذكير';
            }
        } else {
            $error = 'معرف الجدول الزمني غير صحيح';
        }
    } elseif ($action === 'create_schedule') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $amount = !empty($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $dueDate = $_POST['due_date'] ?? '';
        
        $dueDateValid = DateTime::createFromFormat('Y-m-d', $dueDate) !== false;
        
        if ($customerId <= 0) {
            $error = 'يجب اختيار عميل صالح.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } elseif (!$dueDateValid) {
            $error = 'يرجى إدخال تاريخ استحقاق صحيح.';
        } else {
            try {
                // التحقق من أن العميل هو عميل محلي
                $customer = $db->queryOne(
                    "SELECT id, name, balance 
                     FROM local_customers
                     WHERE id = ? AND status = 'active' 
                       AND (balance IS NOT NULL AND balance > 0.01)",
                    [$customerId]
                );
                
                if (!$customer) {
                    $error = 'لا يمكنك إنشاء موعد تحصيل لهذا العميل. يجب أن يكون العميل عميلاً محلياً نشطاً برصيد مدين.';
                } else {
                    $dueDateObj = DateTime::createFromFormat('Y-m-d', $dueDate);
                    if ($dueDateObj < new DateTime('today')) {
                        $error = 'لا يمكن تحديد موعد تحصيل في تاريخ سابق.';
                    } else {
                        // للعملاء المحليين، sales_rep_id يجب أن يكون NULL لأنهم ليسوا عملاء مندوب مبيعات
                        // هذه الصفحة مخصصة للعملاء المحليين فقط (يستخدمها المدير والمحاسب)
                        $result = $db->execute(
                            "INSERT INTO payment_schedules 
                                (sale_id, customer_id, sales_rep_id, amount, due_date, installment_number, total_installments, status, created_by, created_at) 
                             VALUES (NULL, ?, NULL, ?, ?, 1, 1, 'pending', ?, NOW())",
                            [
                                $customerId,
                                $amount,
                                $dueDate,
                                $currentUser['id']
                            ]
                        );
                        
                        // التحقق من نجاح الإدراج أولاً
                        $affectedRows = $result['affected_rows'] ?? 0;
                        $insertIdFromResult = $result['insert_id'] ?? null;
                        $lastInsertId = $db->getLastInsertId();
                        
                        // تسجيل معلومات تفصيلية
                        error_log('Payment schedule insert result - affected_rows: ' . $affectedRows . ', insert_id: ' . ($insertIdFromResult ?? 'null') . ', getLastInsertId: ' . $lastInsertId);
                        
                        if ($affectedRows <= 0) {
                            $dbError = $db->getLastError();
                            $errorDetails = [
                                'affected_rows' => $affectedRows,
                                'insert_id' => $insertIdFromResult,
                                'getLastInsertId' => $lastInsertId,
                                'db_error' => $dbError,
                                'customer_id' => $customerId,
                                'amount' => $amount,
                                'due_date' => $dueDate
                            ];
                            error_log('Create payment schedule failed - No rows affected. Details: ' . json_encode($errorDetails, JSON_UNESCAPED_UNICODE));
                            
                            $errorMsg = 'فشل إدراج موعد التحصيل. لم يتم إدراج أي صف في قاعدة البيانات.';
                            if ($dbError) {
                                $errorMsg .= '<br><strong>تفاصيل الخطأ من قاعدة البيانات:</strong><br>' . htmlspecialchars($dbError);
                            }
                            $errorMsg .= '<br><strong>المعلومات التقنية:</strong><br>';
                            $errorMsg .= 'affected_rows: ' . $affectedRows . '<br>';
                            $errorMsg .= 'insert_id: ' . ($insertIdFromResult ?? 'null') . '<br>';
                            $errorMsg .= 'getLastInsertId: ' . $lastInsertId;
                            
                            throw new Exception($errorMsg);
                        }
                        
                        // الحصول على insert_id
                        $scheduleId = null;
                        if ($insertIdFromResult && $insertIdFromResult > 0) {
                            $scheduleId = (int)$insertIdFromResult;
                        } elseif ($lastInsertId && $lastInsertId > 0) {
                            $scheduleId = (int)$lastInsertId;
                        }
                        
                        // التحقق النهائي من scheduleId
                        if (!$scheduleId || $scheduleId <= 0) {
                            $dbError = $db->getLastError();
                            $errorDetails = [
                                'affected_rows' => $affectedRows,
                                'insert_id_from_result' => $insertIdFromResult,
                                'getLastInsertId' => $lastInsertId,
                                'db_error' => $dbError,
                                'customer_id' => $customerId,
                                'amount' => $amount,
                                'due_date' => $dueDate
                            ];
                            error_log('Create payment schedule failed - Invalid insert_id. Details: ' . json_encode($errorDetails, JSON_UNESCAPED_UNICODE));
                            
                            $errorMsg = 'فشل الحصول على معرف الجدول الزمني بعد الإدراج.';
                            $errorMsg .= '<br><strong>المعلومات التقنية:</strong><br>';
                            $errorMsg .= 'affected_rows: ' . $affectedRows . '<br>';
                            $errorMsg .= 'insert_id من النتيجة: ' . ($insertIdFromResult ?? 'null') . '<br>';
                            $errorMsg .= 'getLastInsertId: ' . ($lastInsertId ?? 'null');
                            if ($dbError) {
                                $errorMsg .= '<br><strong>خطأ قاعدة البيانات:</strong><br>' . htmlspecialchars($dbError);
                            }
                            
                            throw new Exception($errorMsg);
                        }
                        
                        logAudit(
                            $currentUser['id'],
                            'create_payment_schedule_manual',
                            'payment_schedule',
                            $scheduleId,
                            null,
                            [
                                'customer_id' => $customerId,
                                'amount' => $amount,
                                'due_date' => $dueDate,
                                'local_customer' => true
                            ]
                        );
                        
                        // إنشاء تذكير تلقائي إذا تم تحديد عدد الأيام
                        $daysBeforeDue = isset($_POST['days_before_due']) && $_POST['days_before_due'] !== '' 
                            ? intval($_POST['days_before_due']) 
                            : null;
                        
                        if ($daysBeforeDue !== null && $daysBeforeDue >= 1 && $daysBeforeDue <= 30) {
                            // إنشاء التذكير تلقائياً
                            try {
                                createAutoReminder($scheduleId, $daysBeforeDue, $currentUser['id']);
                            } catch (Throwable $reminderError) {
                                // تسجيل الخطأ ولكن لا نمنع إنشاء الموعد
                                error_log('Error creating auto reminder for schedule ' . $scheduleId . ': ' . $reminderError->getMessage());
                            }
                        }
                        
                        // بعد النجاح، إعادة التوجيه لإظهار الجدول المضاف فوراً (يتجاوز أي فلاتر حالية)
                        header('Location: ?page=company_payment_schedules&id=' . $scheduleId . '&created=1');
                        exit;
                    }
                }
            } catch (Throwable $createScheduleError) {
                // تسجيل تفصيلي للخطأ
                $errorDetails = [
                    'message' => $createScheduleError->getMessage(),
                    'file' => $createScheduleError->getFile(),
                    'line' => $createScheduleError->getLine(),
                    'customer_id' => $customerId ?? 'null',
                    'amount' => $amount ?? 'null',
                    'due_date' => $dueDate ?? 'null',
                    'trace' => $createScheduleError->getTraceAsString()
                ];
                error_log('Create payment schedule error: ' . json_encode($errorDetails, JSON_UNESCAPED_UNICODE));
                
                // الحصول على خطأ قاعدة البيانات إن وجد
                $dbError = '';
                try {
                    $dbError = $db->getLastError();
                } catch (Exception $e) {
                    // تجاهل خطأ الحصول على خطأ قاعدة البيانات
                }
                
                // بناء رسالة خطأ تفصيلية
                $errorMessage = '<strong>خطأ في إنشاء موعد التحصيل:</strong><br><br>';
                
                // رسالة الخطأ الأساسية
                $errorMessage .= '<div style="background: #fee; padding: 10px; border-left: 4px solid #f00; margin: 10px 0;">';
                $errorMessage .= '<strong>الرسالة:</strong> ' . htmlspecialchars($createScheduleError->getMessage()) . '<br>';
                $errorMessage .= '</div>';
                
                // معلومات الخطأ التقنية
                $errorMessage .= '<div style="background: #f9f9f9; padding: 10px; border-left: 4px solid #999; margin: 10px 0; font-family: monospace; font-size: 12px;">';
                $errorMessage .= '<strong>المعلومات التقنية:</strong><br>';
                $errorMessage .= 'الملف: ' . htmlspecialchars(basename($createScheduleError->getFile())) . '<br>';
                $errorMessage .= 'السطر: ' . $createScheduleError->getLine() . '<br>';
                
                if ($dbError) {
                    $errorMessage .= '<br><strong>خطأ قاعدة البيانات:</strong><br>';
                    $errorMessage .= htmlspecialchars($dbError) . '<br>';
                }
                
                $errorMessage .= '<br><strong>البيانات المدخلة:</strong><br>';
                $errorMessage .= 'معرف العميل: ' . ($customerId ?? 'غير محدد') . '<br>';
                $errorMessage .= 'المبلغ: ' . ($amount ?? 'غير محدد') . '<br>';
                $errorMessage .= 'تاريخ الاستحقاق: ' . ($dueDate ?? 'غير محدد') . '<br>';
                $errorMessage .= '</div>';
                
                // رسائل خطأ محددة حسب نوع الخطأ
                $errorType = '';
                $errorMsgLower = strtolower($createScheduleError->getMessage());
                if (strpos($errorMsgLower, 'sales_rep_id') !== false && strpos($errorMsgLower, 'cannot be null') !== false) {
                    $errorType = '<div style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0;">';
                    $errorType .= '<strong>⚠️ خطأ في بنية قاعدة البيانات:</strong><br>';
                    $errorType .= 'عمود <code>sales_rep_id</code> في جدول <code>payment_schedules</code> لا يقبل NULL.<br>';
                    $errorType .= 'هذه الصفحة مخصصة للعملاء المحليين فقط ولا تحتاج لمندوب مبيعات.<br><br>';
                    $errorType .= '<strong>الحل:</strong> يجب تعديل بنية قاعدة البيانات للسماح بـ NULL في عمود <code>sales_rep_id</code> للعملاء المحليين.<br>';
                    $errorType .= 'قم بتنفيذ هذا الاستعلام في قاعدة البيانات:<br>';
                    $errorType .= '<code style="background: #f0f0f0; padding: 5px; display: block; margin-top: 5px;">ALTER TABLE payment_schedules MODIFY COLUMN sales_rep_id INT(11) NULL;</code>';
                    $errorType .= '</div>';
                } elseif (strpos($errorMsgLower, 'duplicate') !== false || strpos($errorMsgLower, 'مكرر') !== false) {
                    $errorType = '<div style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0;">';
                    $errorType .= '<strong>⚠️ خطأ التكرار:</strong> يوجد موعد تحصيل مكرر لهذا العميل في نفس التاريخ.';
                    $errorType .= '</div>';
                } elseif (strpos($errorMsgLower, 'foreign key') !== false || strpos($errorMsgLower, 'foreign_key') !== false) {
                    $errorType = '<div style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0;">';
                    $errorType .= '<strong>⚠️ خطأ المفتاح الخارجي:</strong> العميل المحدد غير موجود أو غير نشط في قاعدة البيانات.';
                    $errorType .= '</div>';
                } elseif (strpos($errorMsgLower, 'constraint') !== false) {
                    $errorType = '<div style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0;">';
                    $errorType .= '<strong>⚠️ خطأ القيد:</strong> البيانات المدخلة لا تتوافق مع قيود قاعدة البيانات. يرجى التحقق من المبلغ وتاريخ الاستحقاق.';
                    $errorType .= '</div>';
                } elseif (strpos($errorMsgLower, 'affected_rows') !== false || strpos($errorMsgLower, 'insert_id') !== false) {
                    $errorType = '<div style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0;">';
                    $errorType .= '<strong>⚠️ خطأ في الإدراج:</strong> فشل إدراج البيانات في قاعدة البيانات. يرجى التحقق من اتصال قاعدة البيانات.';
                    $errorType .= '</div>';
                }
                
                if ($errorType) {
                    $errorMessage = $errorType . '<br>' . $errorMessage;
                }
                
                $error = $errorMessage;
            }
        }
    } elseif ($action === 'update_schedule') {
        $scheduleId = intval($_POST['schedule_id'] ?? 0);
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $dueDate = $_POST['due_date'] ?? '';

        $dueDateObj = DateTime::createFromFormat('Y-m-d', $dueDate);

        if ($scheduleId <= 0) {
            $error = 'معرف الجدول غير صالح.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } elseif (!$dueDateObj) {
            $error = 'يرجى إدخال تاريخ استحقاق صحيح.';
        } else {
            try {
                // استعلام مبسط وسريع - التحقق من أن الجدول مرتبط بعميل محلي
                $schedule = $db->queryOne(
                    "SELECT ps.* FROM payment_schedules ps
                     INNER JOIN local_customers lc ON ps.customer_id = lc.id
                     WHERE ps.id = ? AND lc.status = 'active'",
                    [$scheduleId]
                );

                if (!$schedule) {
                    $error = 'لا يمكنك تعديل هذا الجدول. يجب أن يكون مرتبطاً بعميل محلي نشط.';
                } elseif ($schedule['status'] === 'paid' || $schedule['status'] === 'cancelled') {
                    $error = 'لا يمكن تعديل جدول مدفوع أو ملغى.';
                } else {
                    $oldData = [
                        'amount' => $schedule['amount'],
                        'due_date' => $schedule['due_date'],
                        'status' => $schedule['status']
                    ];

                    $today = new DateTimeImmutable('today');
                    $newStatus = $schedule['status'];
                    if ($newStatus !== 'paid' && $newStatus !== 'cancelled') {
                        $newStatus = ($dueDateObj < $today) ? 'overdue' : 'pending';
                    }

                    $db->execute(
                        "UPDATE payment_schedules 
                         SET amount = ?, due_date = ?, status = ?, reminder_sent = 0, reminder_sent_at = NULL, updated_at = NOW()
                         WHERE id = ?",
                        [
                            $amount,
                            $dueDateObj->format('Y-m-d'),
                            $newStatus,
                            $scheduleId
                        ]
                    );

                    // تسجيل audit log بشكل غير متزامن
                    try {
                        logAudit(
                            $currentUser['id'],
                            'update_payment_schedule',
                            'payment_schedule',
                            $scheduleId,
                            $oldData,
                            [
                                'amount' => $amount,
                                'due_date' => $dueDateObj->format('Y-m-d'),
                                'status' => $newStatus,
                                'local_customer' => true
                            ]
                        );
                    } catch (Throwable $auditError) {
                        error_log('Audit log error (non-blocking): ' . $auditError->getMessage());
                    }

                    $success = 'تم تحديث موعد التحصيل بنجاح.';
                }
            } catch (Throwable $updateScheduleError) {
                error_log('Update payment schedule error: ' . $updateScheduleError->getMessage());
                $error = 'تعذر تحديث موعد التحصيل، يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'mark_as_paid') {
        $scheduleId = intval($_POST['schedule_id'] ?? 0);
        
        if ($scheduleId <= 0) {
            $error = 'معرف الجدول غير صالح.';
        } else {
            try {
                // استعلام مبسط وسريع - التحقق من أن الجدول مرتبط بعميل محلي
                $schedule = $db->queryOne(
                    "SELECT ps.* FROM payment_schedules ps
                     INNER JOIN local_customers lc ON ps.customer_id = lc.id
                     WHERE ps.id = ? AND lc.status = 'active'",
                    [$scheduleId]
                );

                if (!$schedule) {
                    $error = 'لا يمكنك تمييز هذا الجدول. يجب أن يكون مرتبطاً بعميل محلي نشط.';
                } elseif ($schedule['status'] === 'paid') {
                    $error = 'هذا الجدول مدفوع بالفعل.';
                } elseif ($schedule['status'] === 'cancelled') {
                    $error = 'لا يمكن تمييز جدول ملغى.';
                } else {
                    $oldData = [
                        'status' => $schedule['status'],
                        'payment_date' => $schedule['payment_date']
                    ];

                    $db->execute(
                        "UPDATE payment_schedules 
                         SET status = 'paid', payment_date = CURDATE(), updated_at = NOW()
                         WHERE id = ?",
                        [$scheduleId]
                    );

                    // تسجيل audit log بشكل غير متزامن
                    try {
                        logAudit(
                            $currentUser['id'],
                            'mark_payment_schedule_paid',
                            'payment_schedule',
                            $scheduleId,
                            $oldData,
                            [
                                'status' => 'paid',
                                'payment_date' => date('Y-m-d'),
                                'local_customer' => true,
                                'note' => 'موعد تذكير فقط - لا تأثير على الحسابات'
                            ]
                        );
                    } catch (Throwable $auditError) {
                        error_log('Audit log error (non-blocking): ' . $auditError->getMessage());
                    }

                    $success = 'تم تمييز الجدول كمدفوع بنجاح. (ملاحظة: هذا مجرد تذكير ولا يؤثر على حسابات العميل)';
                }
            } catch (Throwable $markPaidError) {
                error_log('Mark payment schedule as paid error: ' . $markPaidError->getMessage());
                $error = 'تعذر تمييز الجدول كمدفوع، يرجى المحاولة مرة أخرى.';
            }
        }
    }
}

// تحديث الحالات المتأخرة
updateOverdueSchedules();

// إرسال التذكيرات المعلقة - للعملاء المحليين (sales_rep_id = NULL)
// يجب استدعاء sendPaymentReminders بدون salesRepId لإرسال تذكيرات العملاء المحليين
error_log('=== company_payment_schedules.php: Calling sendPaymentReminders for LOCAL customers ===');
error_log('Current User ID: ' . ($currentUser['id'] ?? 'null') . ', Role: ' . ($currentUser['role'] ?? 'null'));
error_log('Current Date: ' . date('Y-m-d H:i:s'));
error_log('NOTE: Calling with NULL salesRepId to send reminders for local customers (sales_rep_id = NULL)');
$sentReminders = sendPaymentReminders(null); // null لإرسال تذكيرات العملاء المحليين
error_log('sendPaymentReminders returned: ' . $sentReminders);

// إرسال إشعارات للمواعيد المتأخرة للمحاسبين والمديرين (للعملاء المحليين)
error_log('=== company_payment_schedules.php: Calling notifyOverduePaymentSchedulesForManagers ===');
$overdueNotificationsSent = notifyOverduePaymentSchedulesForManagers();
error_log('notifyOverduePaymentSchedulesForManagers returned: ' . $overdueNotificationsSent);

// الحصول على البيانات - فقط جداول العملاء المحليين
$saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
$hasSaleNumberColumn = !empty($saleNumberColumnCheck);

// استعلام محسّن - العملاء المحليين لديهم sales_rep_id = NULL
$countSql = "SELECT COUNT(*) as total 
             FROM payment_schedules ps
             INNER JOIN local_customers lc ON ps.customer_id = lc.id
             WHERE lc.status = 'active' AND ps.sales_rep_id IS NULL";

$countParams = [];

// تطبيق الفلاتر
if (!empty($filters['customer_id'])) {
    $countSql .= " AND ps.customer_id = ?";
    $countParams[] = $filters['customer_id'];
}

if (!empty($filters['status'])) {
    $countSql .= " AND ps.status = ?";
    $countParams[] = $filters['status'];
}

if (!empty($filters['due_date_from'])) {
    $countSql .= " AND ps.due_date >= ?";
    $countParams[] = $filters['due_date_from'];
}

if (!empty($filters['due_date_to'])) {
    $countSql .= " AND ps.due_date <= ?";
    $countParams[] = $filters['due_date_to'];
}

if (!empty($filters['overdue_only'])) {
    $countSql .= " AND ps.status = 'pending' AND ps.due_date < CURDATE()";
}

$totalSchedules = $db->queryOne($countSql, $countParams);
$totalSchedules = $totalSchedules['total'] ?? 0;
$totalPages = ceil($totalSchedules / $perPage);

// جلب الجداول - استعلام محسّن
if ($hasSaleNumberColumn) {
    $sql = "SELECT ps.*, s.sale_number, lc.name as customer_name, 
                   u.full_name as sales_rep_name, u.username as sales_rep_username
            FROM payment_schedules ps
            INNER JOIN local_customers lc ON ps.customer_id = lc.id
            LEFT JOIN sales s ON ps.sale_id = s.id
            LEFT JOIN users u ON ps.sales_rep_id = u.id
            WHERE lc.status = 'active' AND ps.sales_rep_id IS NULL";
} else {
    $sql = "SELECT ps.*, s.id as sale_number, lc.name as customer_name, 
                   u.full_name as sales_rep_name, u.username as sales_rep_username
            FROM payment_schedules ps
            INNER JOIN local_customers lc ON ps.customer_id = lc.id
            LEFT JOIN sales s ON ps.sale_id = s.id
            LEFT JOIN users u ON ps.sales_rep_id = u.id
            WHERE lc.status = 'active' AND ps.sales_rep_id IS NULL";
}

$params = [];

// تطبيق الفلاتر
if (!empty($filters['customer_id'])) {
    $sql .= " AND ps.customer_id = ?";
    $params[] = $filters['customer_id'];
}

if (!empty($filters['status'])) {
    $sql .= " AND ps.status = ?";
    $params[] = $filters['status'];
}

if (!empty($filters['due_date_from'])) {
    $sql .= " AND ps.due_date >= ?";
    $params[] = $filters['due_date_from'];
}

if (!empty($filters['due_date_to'])) {
    $sql .= " AND ps.due_date <= ?";
    $params[] = $filters['due_date_to'];
}

if (!empty($filters['overdue_only'])) {
    $sql .= " AND ps.status = 'pending' AND ps.due_date < CURDATE()";
}

// إظهار الأحدث أولاً لضمان ظهور الموعد المضاف مؤخراً في الصفحة الأولى
$sql .= " ORDER BY ps.created_at DESC, ps.due_date ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$schedules = $db->query($sql, $params);

// جلب العملاء المحليين
try {
    $customers = $db->query(
        "SELECT id, name FROM local_customers 
         WHERE status = 'active' 
         ORDER BY name"
    );
    
    $debtorCustomers = $db->query(
        "SELECT id, name, balance FROM local_customers 
         WHERE status = 'active' 
         AND balance IS NOT NULL AND balance > 0.01
         ORDER BY name"
    );
} catch (Throwable $e) {
    error_log('Error fetching local customers: ' . $e->getMessage());
    $customers = [];
    $debtorCustomers = [];
}

$hasDebtorCustomers = !empty($debtorCustomers);

// إحصائيات
$stats = [
    'total_pending' => 0,
    'total_overdue' => 0,
    'total_paid' => 0,
    'total_amount_pending' => 0,
    'total_amount_overdue' => 0
];

$statsSql = "SELECT 
        COUNT(CASE WHEN ps.status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN ps.status = 'overdue' THEN 1 END) as overdue_count,
        COUNT(CASE WHEN ps.status = 'paid' THEN 1 END) as paid_count,
        COALESCE(SUM(CASE WHEN ps.status = 'pending' THEN ps.amount END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN ps.status = 'overdue' THEN ps.amount END), 0) as overdue_amount
     FROM payment_schedules ps
     INNER JOIN local_customers lc ON ps.customer_id = lc.id
     WHERE lc.status = 'active' AND ps.sales_rep_id IS NULL";

$statsParams = [];
$statsQuery = $db->queryOne($statsSql, $statsParams);

if ($statsQuery) {
    $stats = [
        'total_pending' => $statsQuery['pending_count'] ?? 0,
        'total_overdue' => $statsQuery['overdue_count'] ?? 0,
        'total_paid' => $statsQuery['paid_count'] ?? 0,
        'total_amount_pending' => $statsQuery['pending_amount'] ?? 0,
        'total_amount_overdue' => $statsQuery['overdue_amount'] ?? 0
    ];
}

// جدول محدد للعرض
$selectedSchedule = null;
if (isset($_GET['id'])) {
    $scheduleId = intval($_GET['id']);
    
    // استعلام محسّن
    if ($hasSaleNumberColumn) {
        $selectedSchedule = $db->queryOne(
            "SELECT ps.*, s.sale_number, lc.name as customer_name, lc.phone as customer_phone,
                    u.full_name as sales_rep_name
             FROM payment_schedules ps
             INNER JOIN local_customers lc ON ps.customer_id = lc.id
             LEFT JOIN sales s ON ps.sale_id = s.id
             LEFT JOIN users u ON ps.sales_rep_id = u.id
             WHERE ps.id = ? AND lc.status = 'active' AND ps.sales_rep_id IS NULL",
            [$scheduleId]
        );
    } else {
        $selectedSchedule = $db->queryOne(
            "SELECT ps.*, s.id as sale_number, lc.name as customer_name, lc.phone as customer_phone,
                    u.full_name as sales_rep_name
             FROM payment_schedules ps
             INNER JOIN local_customers lc ON ps.customer_id = lc.id
             LEFT JOIN sales s ON ps.sale_id = s.id
             LEFT JOIN users u ON ps.sales_rep_id = u.id
             WHERE ps.id = ? AND lc.status = 'active' AND ps.sales_rep_id IS NULL",
            [$scheduleId]
        );
    }
}
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h2 class="mb-0"><i class="bi bi-calendar-check me-2"></i>جداول التحصيل - العملاء المحليين</h2>
    <div class="d-flex align-items-center gap-2">
        <?php if ($sentReminders > 0): ?>
        <div class="alert alert-info mb-0 py-2 px-3">
            <i class="bi bi-info-circle me-2"></i>
            تم إرسال <?php echo $sentReminders; ?> تذكير
        </div>
        <?php endif; ?>
        <a href="?page=company_payment_schedules&action=show_add_form" class="btn btn-primary">
            <i class="bi bi-plus-circle me-2"></i>إضافة موعد تحصيل
        </a>
    </div>
</div>

<div class="alert alert-info alert-dismissible fade show mb-4">
    <i class="bi bi-info-circle-fill me-2"></i>
    <strong>ملاحظة مهمة:</strong> هذه الصفحة مخصصة لمواعيد التذكير بالمبالغ المستحقة فقط. 
    تمييز موعد كمدفوع أو تسجيل دفعة هنا <strong>لا يؤثر على حسابات العميل أو خزنة الشركة</strong> - 
    إنها مجرد أداة تذكير وتتبع للمواعيد المستحقة.
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true" style="white-space: pre-wrap;">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <div style="margin-top: 10px;">
            <?php echo $error; ?>
        </div>
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

<?php if (!$hasDebtorCustomers): ?>
    <div class="alert alert-warning">
        <i class="bi bi-info-circle-fill me-2"></i>
        لا توجد عملاء محليون مدينون حالياً. قم بإضافة عميل محلي أو تحديث رصيد العملاء المحليين ليظهر هنا.
    </div>
<?php endif; ?>

<?php if ($selectedSchedule): ?>
    <!-- عرض جدول محدد -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">جدول التحصيل #<?php echo $selectedSchedule['id']; ?></h5>
            <a href="?page=company_payment_schedules" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">العميل:</th>
                            <td><?php echo htmlspecialchars($selectedSchedule['customer_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>مندوب المبيعات:</th>
                            <td><?php echo htmlspecialchars($selectedSchedule['sales_rep_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>رقم البيع:</th>
                            <td><?php echo htmlspecialchars($selectedSchedule['sale_number'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>المبلغ:</th>
                            <td><strong><?php echo formatCurrency($selectedSchedule['amount']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>تاريخ الاستحقاق:</th>
                            <td><?php echo formatDate($selectedSchedule['due_date']); ?></td>
                        </tr>
                        <tr>
                            <th>تاريخ الدفع:</th>
                            <td><?php echo $selectedSchedule['payment_date'] ? formatDate($selectedSchedule['payment_date']) : '-'; ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedSchedule['status'] === 'paid' ? 'success' : 
                                        ($selectedSchedule['status'] === 'overdue' ? 'danger' : 'warning'); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'pending' => 'معلق',
                                        'paid' => 'مدفوع',
                                        'overdue' => 'متأخر',
                                        'cancelled' => 'ملغى'
                                    ];
                                    echo $statuses[$selectedSchedule['status']] ?? $selectedSchedule['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>القسط:</th>
                            <td><?php echo $selectedSchedule['installment_number']; ?> / <?php echo $selectedSchedule['total_installments']; ?></td>
                        </tr>
                        <tr>
                            <th>تم إرسال تذكير:</th>
                            <td><?php echo $selectedSchedule['reminder_sent'] ? 'نعم' : 'لا'; ?></td>
                        </tr>
                        <?php if ($selectedSchedule['reminder_sent_at']): ?>
                        <tr>
                            <th>تاريخ آخر تذكير:</th>
                            <td><?php echo formatDateTime($selectedSchedule['reminder_sent_at']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <?php if ($selectedSchedule['status'] !== 'paid' && $selectedSchedule['status'] !== 'cancelled'): ?>
                        <div class="mt-3">
                            <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من تمييز هذا الجدول كمدفوع؟');">
                                <input type="hidden" name="action" value="mark_as_paid">
                                <input type="hidden" name="schedule_id" value="<?php echo $selectedSchedule['id']; ?>">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-circle me-2"></i>تم التحصيل
                                </button>
                            </form>
                            <button class="btn btn-info" onclick="showReminderModal(<?php echo $selectedSchedule['id']; ?>)">
                                <i class="bi bi-bell me-2"></i>إنشاء تذكير
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- بطاقات الإحصائيات -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="card-title">معلق</div>
            <div class="card-value"><?php echo $stats['total_pending']; ?></div>
            <div class="card-subtitle"><?php echo formatCurrency($stats['total_amount_pending']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="card-title">متأخر</div>
            <div class="card-value text-danger"><?php echo $stats['total_overdue']; ?></div>
            <div class="card-subtitle"><?php echo formatCurrency($stats['total_amount_overdue']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-check-circle"></i></div>
            <div class="card-title">مدفوع</div>
            <div class="card-value text-success"><?php echo $stats['total_paid']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-calendar-event"></i></div>
            <div class="card-title">إجمالي الجداول</div>
            <div class="card-value"><?php echo $totalSchedules; ?></div>
        </div>
    </div>
</div>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="company_payment_schedules">
            <div class="col-md-3">
                <label class="form-label">العميل</label>
                <select class="form-select" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" 
                                <?php echo ($filters['customer_id'] ?? '') == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="paid" <?php echo ($filters['status'] ?? '') === 'paid' ? 'selected' : ''; ?>>مدفوع</option>
                    <option value="overdue" <?php echo ($filters['status'] ?? '') === 'overdue' ? 'selected' : ''; ?>>متأخر</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="due_date_from" 
                       value="<?php echo htmlspecialchars($filters['due_date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="due_date_to" 
                       value="<?php echo htmlspecialchars($filters['due_date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="overdue_only" id="overdueOnly" 
                           <?php echo ($filters['overdue_only'] ?? false) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="overdueOnly">
                        المتأخرة فقط
                    </label>
                </div>
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة الجداول الزمنية -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة الجداول الزمنية (<?php echo $totalSchedules; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>العميل</th>
                        <th>المبلغ</th>
                        <th>تاريخ الاستحقاق</th>
                        <th>القسط</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">لا توجد جداول زمنية</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr class="<?php echo $schedule['status'] === 'overdue' ? 'table-danger' : ''; ?>">
                                <td><?php echo htmlspecialchars($schedule['customer_name'] ?? '-'); ?></td>
                                <td><strong><?php echo formatCurrency($schedule['amount']); ?></strong></td>
                                <td>
                                    <?php echo formatDate($schedule['due_date']); ?>
                                    <?php if ($schedule['status'] === 'overdue'): ?>
                                        <span class="badge bg-danger ms-2">متأخر</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $schedule['installment_number']; ?> / <?php echo $schedule['total_installments']; ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $schedule['status'] === 'paid' ? 'success' : 
                                            ($schedule['status'] === 'overdue' ? 'danger' : 'warning'); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'paid' => 'مدفوع',
                                            'overdue' => 'متأخر',
                                            'cancelled' => 'ملغى'
                                        ];
                                        echo $statuses[$schedule['status']] ?? $schedule['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($schedule['status'] !== 'paid' && $schedule['status'] !== 'cancelled'): ?>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button class="btn btn-sm btn-warning rounded-pill px-3" 
                                                data-schedule-id="<?php echo $schedule['id']; ?>"
                                                onclick="showReminderModal(<?php echo $schedule['id']; ?>)"
                                                title="تحديد عدد أيام التنبيه"
                                                style="border: 2px solid #ffc107; background-color: #ffc107; color: #000; font-weight: 500;">
                                            <i class="bi bi-bell-fill me-1"></i>تنبيه
                                        </button>
                                        <button class="btn btn-sm btn-primary rounded-pill px-3"
                                                data-schedule-id="<?php echo $schedule['id']; ?>"
                                                data-customer="<?php echo htmlspecialchars($schedule['customer_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-amount="<?php echo $schedule['amount']; ?>"
                                                data-due-date="<?php echo htmlspecialchars($schedule['due_date']); ?>"
                                                onclick="showEditScheduleModal(this)"
                                                title="تعديل"
                                                style="border: 2px solid #0d6efd; background-color: #0d6efd; color: #fff; font-weight: 500;">
                                            <i class="bi bi-pencil-fill me-1"></i>تعديل
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من تمييز هذا الجدول كمدفوع؟');">
                                            <input type="hidden" name="action" value="mark_as_paid">
                                            <input type="hidden" name="schedule_id" value="<?php echo $schedule['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success rounded-pill px-3"
                                                    title="تم التحصيل"
                                                    style="border: 2px solid #198754; background-color: #198754; color: #fff; font-weight: 500;">
                                                <i class="bi bi-check-circle-fill me-1"></i>تم التحصيل
                                            </button>
                                        </form>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=company_payment_schedules&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=company_payment_schedules&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=company_payment_schedules&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=company_payment_schedules&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=company_payment_schedules&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- نموذج إضافة موعد تحصيل مبسط -->
<?php 
$showAddForm = isset($_GET['action']) && $_GET['action'] === 'show_add_form';
if ($showAddForm): 
?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إضافة موعد تحصيل</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
            <input type="hidden" name="action" value="create_schedule">
            <?php if ($hasDebtorCustomers): ?>
            <div class="mb-3">
                <label class="form-label">العميل <span class="text-danger">*</span></label>
                <select class="form-select" name="customer_id" required>
                    <option value="">اختر العميل</option>
                    <?php foreach ($debtorCustomers as $customer): ?>
                        <option value="<?php echo (int) $customer['id']; ?>">
                            <?php echo htmlspecialchars($customer['name']); ?> - رصيد مدين: <?php echo formatCurrency($customer['balance']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">يتم عرض العملاء المحليين المدينين فقط.</small>
            </div>
            <div class="mb-3">
                <label class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="mb-3">
                <label class="form-label">موعد التحصيل <span class="text-danger">*</span></label>
                <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">عدد الأيام قبل الموعد للتذكير</label>
                <input type="number" name="days_before_due" class="form-control" value="3" min="1" max="30" step="1">
                <small class="text-muted">سيتم إرسال التذكير قبل موعد الاستحقاق بهذا العدد من الأيام (اختياري - الافتراضي: 3 أيام)</small>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-2"></i>حفظ
                </button>
                <a href="?page=company_payment_schedules" class="btn btn-secondary">
                    <i class="bi bi-x-circle me-2"></i>إلغاء
                </a>
            </div>
            <?php else: ?>
            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                لا يوجد عملاء محليون مدينون (رصيد مدين أكبر من صفر) لإضافة موعد تحصيل. 
                <br><small class="text-muted">تأكد من وجود عملاء محليين نشطين برصيد مدين (balance > 0).</small>
            </div>
            <div class="mt-3">
                <a href="?page=company_payment_schedules" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>رجوع
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal تعديل موعد تحصيل -->
<div class="modal fade" id="editScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-fill me-2"></i>تعديل موعد التحصيل</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_schedule">
                <input type="hidden" name="schedule_id" id="editScheduleId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">العميل</label>
                        <input type="text" class="form-control" id="editScheduleCustomer" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" step="1" min="0" id="editScheduleAmount" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">موعد التحصيل <span class="text-danger">*</span></label>
                        <input type="date" name="due_date" class="form-control" id="editScheduleDueDate" required>
                    </div>
                    <div class="alert alert-info mb-0">
                        تعديل التاريخ سيُحدّث حالة الجدول تلقائياً ليتناسب مع الموعد الجديد.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4" style="border: 2px solid #0d6efd; font-weight: 500;">
                        <i class="bi bi-check-circle me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تحديد عدد أيام التنبيه -->
<div class="modal fade" id="reminderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-bell-fill me-2"></i>تحديد عدد أيام التنبيه</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="reminderForm" onsubmit="return validateReminderForm(this)">
                <input type="hidden" name="action" value="create_reminder">
                <input type="hidden" name="schedule_id" id="reminderScheduleId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">عدد الأيام قبل موعد الاستحقاق <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="days_before_due" id="daysBeforeDueInput" value="3" min="1" max="30" required>
                        <small class="text-muted">سيتم إرسال التذكير قبل موعد الاستحقاق بهذا العدد من الأيام</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4" style="border: 2px solid #ffc107; font-weight: 500;">
                        <i class="bi bi-check-circle me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// متغير لتتبع ما إذا كان المستخدم قد غير القيمة
let userChangedDays = false;
let lastScheduleId = null;

// دالة للتحقق من صحة النموذج قبل الإرسال
function validateReminderForm(form) {
    const daysInput = form.querySelector('input[name="days_before_due"]');
    if (daysInput) {
        const value = parseInt(daysInput.value);
        if (isNaN(value) || value < 1 || value > 30) {
            alert('يرجى إدخال عدد أيام صحيح بين 1 و 30');
            return false;
        }
    }
    return true;
}

function showReminderModal(scheduleId) {
    const scheduleIdInput = document.getElementById('reminderScheduleId');
    const daysInput = document.getElementById('daysBeforeDueInput');
    const modalElement = document.getElementById('reminderModal');
    
    if (!scheduleIdInput || !daysInput || !modalElement) {
        console.error('Required elements not found');
        return;
    }
    
    // إذا كان scheduleId مختلف عن الأخير، نعيد تعيين المتغيرات
    if (lastScheduleId !== scheduleId) {
        userChangedDays = false;
        lastScheduleId = scheduleId;
    }
    
    scheduleIdInput.value = scheduleId;
    
    // فتح المودال مباشرة - event listener سيجلب القيمة تلقائياً
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
}

function showEditScheduleModal(button) {
    if (!button) {
        return;
    }

    const scheduleId = button.getAttribute('data-schedule-id') || '';
    const customer = button.getAttribute('data-customer') || '';
    const amount = button.getAttribute('data-amount') || '';
    const dueDate = button.getAttribute('data-due-date') || '';

    const modalEl = document.getElementById('editScheduleModal');
    if (!modalEl) {
        return;
    }

    const idInput = modalEl.querySelector('#editScheduleId');
    const customerInput = modalEl.querySelector('#editScheduleCustomer');
    const amountInput = modalEl.querySelector('#editScheduleAmount');
    const dueDateInput = modalEl.querySelector('#editScheduleDueDate');

    if (idInput) idInput.value = scheduleId;
    if (customerInput) customerInput.value = customer;
    if (amountInput) amountInput.value = amount;
    if (dueDateInput) dueDateInput.value = dueDate;

    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {

    const editScheduleModal = document.getElementById('editScheduleModal');
    if (editScheduleModal) {
        editScheduleModal.addEventListener('hidden.bs.modal', function () {
            const form = editScheduleModal.querySelector('form');
            if (form) {
                form.reset();
            }
        });
    }
    
    // إعادة تعيين مودال التنبيه عند إغلاقه
    const reminderModal = document.getElementById('reminderModal');
    if (reminderModal) {
        // تتبع تغييرات المستخدم في حقل عدد الأيام
        const daysInput = document.getElementById('daysBeforeDueInput');
        if (daysInput) {
            daysInput.addEventListener('input', function() {
                userChangedDays = true;
            });
        }
        
        // عند فتح المودال، جلب القيمة المحفوظة
        reminderModal.addEventListener('show.bs.modal', function () {
            const scheduleIdInput = document.getElementById('reminderScheduleId');
            const daysInputEl = document.getElementById('daysBeforeDueInput');
            
            if (scheduleIdInput && scheduleIdInput.value && daysInputEl) {
                const scheduleId = scheduleIdInput.value;
                
                // إعادة تعيين userChangedDays عند فتح المودال
                userChangedDays = false;
                
                // جلب القيمة المحفوظة عند فتح المودال
                fetch('?page=company_payment_schedules&action=get_reminder_days&schedule_id=' + scheduleId)
                    .then(response => {
                        // التحقق من نوع المحتوى
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            return response.text().then(text => {
                                console.error('Expected JSON but got HTML:', text.substring(0, 200));
                                throw new Error('Expected JSON but got: ' + contentType);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success && data.days_before_due) {
                            // تحديث القيمة بالقيمة المحفوظة
                            daysInputEl.value = data.days_before_due;
                            console.log('Loaded saved days_before_due:', data.days_before_due);
                        } else {
                            // إذا لم تكن هناك قيمة محفوظة، نستخدم القيمة الافتراضية
                            daysInputEl.value = '3';
                            console.log('No saved value, using default: 3');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching reminder days on modal show:', error);
                        // في حالة الخطأ، نستخدم القيمة الافتراضية
                        daysInputEl.value = '3';
                    });
            }
        });
        
        reminderModal.addEventListener('hidden.bs.modal', function () {
            // إعادة تعيين المتغيرات عند إغلاق المودال
            userChangedDays = false;
            lastScheduleId = null;
            // لا نعيد تعيين القيمة إلى 3 - سنجلبها من السيرفر عند فتح المودال مرة أخرى
        });
    }
});
</script>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود معاملات في URL تشير إلى رسالة جديدة
    const currentUrl = new URL(window.location.href);
    const hasMessageParams = currentUrl.searchParams.has('success') || 
                             currentUrl.searchParams.has('error') ||
                             currentUrl.searchParams.has('created');
    
    // دالة للتحقق من وجود modal مفتوح
    function isModalOpen() {
        const openModals = document.querySelectorAll('.modal.show, .modal.showing');
        return openModals.length > 0;
    }
    
    // فقط إذا كان هناك معاملات رسالة في URL و alert موجود
    if ((successAlert || errorAlert) && hasMessageParams) {
        const alertElement = successAlert || errorAlert;
        
        if (alertElement && alertElement.dataset.autoRefresh === 'true') {
            // إزالة معاملات الرسائل من URL مباشرة بدون timeout
            if (!isModalOpen()) {
                currentUrl.searchParams.delete('success');
                currentUrl.searchParams.delete('error');
                currentUrl.searchParams.delete('created');
                currentUrl.searchParams.delete('_nocache');
                // تحديث URL بدون إعادة تحميل الصفحة
                window.history.replaceState({}, '', currentUrl.toString());
            }
        }
    }
})();
</script>

<style>
/* ===== تنسيقات النماذج الأخرى ===== */
/* قائمة النماذج: editScheduleModal, reminderModal */

@media (min-width: 769px) {
    #editScheduleModal .modal-dialog.modal-dialog-centered,
    #reminderModal .modal-dialog.modal-dialog-centered {
        margin: 0.5rem auto;
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 1rem);
    }

    #editScheduleModal .modal-content,
    #reminderModal .modal-content {
        display: flex !important;
        flex-direction: column !important;
        height: auto !important;
        max-height: 100% !important;
        overflow: hidden !important;
    }

    /* إصلاح المساحة البيضاء - منع modal-body من التمدد */
    #editScheduleModal .modal-body,
    #reminderModal .modal-body {
        flex: 0 1 auto !important;
        flex-grow: 0 !important;
        flex-shrink: 1 !important;
        flex-basis: auto !important;
        min-height: 0 !important;
        height: auto !important;
        max-height: none !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        padding-bottom: 1rem !important;
        margin-bottom: 0 !important;
    }
}

/* قواعد عامة للـ header والـ footer */
#editScheduleModal .modal-header,
#reminderModal .modal-header {
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
}

#editScheduleModal .modal-footer,
#reminderModal .modal-footer {
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    border-top: 1px solid #dee2e6 !important;
}

/* padding للـ header والـ footer على الشاشات الكبيرة فقط */
@media (min-width: 769px) {
    #editScheduleModal .modal-footer,
    #reminderModal .modal-footer {
        padding-top: 1rem !important;
        padding-bottom: 1rem !important;
    }
}

/* إزالة أي pseudo-elements قد تسبب مساحة فارغة */
#editScheduleModal .modal-content::after,
#editScheduleModal .modal-content::before,
#reminderModal .modal-content::after,
#reminderModal .modal-content::before {
    display: none !important;
    content: none !important;
}

/* إصلاح خاص لـ modal-dialog-scrollable (للشاشات الكبيرة فقط) */
@media (min-width: 769px) {
    #editScheduleModal .modal-dialog.modal-dialog-scrollable .modal-content,
    #reminderModal .modal-dialog.modal-dialog-scrollable .modal-content {
        max-height: 100% !important;
        overflow: hidden !important;
    }

    #editScheduleModal .modal-dialog.modal-dialog-scrollable .modal-body,
    #reminderModal .modal-dialog.modal-dialog-scrollable .modal-body {
        flex: 0 1 auto !important;
        overflow-y: auto !important;
        max-height: calc(100vh - 250px) !important;
    }
}

/* تنسيقات للشاشات الصغيرة */
@media (max-width: 768px) {
    #editScheduleModal .modal-dialog,
    #reminderModal .modal-dialog {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
        max-height: calc(100vh - 1rem) !important;
        height: auto !important;
    }
    
    #editScheduleModal .modal-content,
    #reminderModal .modal-content {
        max-height: calc(100vh - 1rem) !important;
        height: auto !important;
    }
    
    #editScheduleModal .modal-body,
    #reminderModal .modal-body {
        flex: 0 1 auto !important;
        flex-grow: 0 !important;
        padding-bottom: 1rem !important;
        max-height: none !important;
        height: auto !important;
        overflow-y: visible !important;
    }
    
    #editScheduleModal .modal-footer,
    #reminderModal .modal-footer {
        flex-shrink: 0 !important;
        flex-grow: 0 !important;
        margin-top: 0 !important;
        padding-top: 1rem !important;
        padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px)) !important;
    }
    
    #editScheduleModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body,
    #reminderModal .modal-dialog:not(.modal-dialog-scrollable) .modal-body {
        overflow-y: visible !important;
        max-height: none !important;
    }
}

/* ===== منع scroll و touch خارج النموذج عند فتحه ===== */
/* منع scroll في body عند فتح النموذج */
body.modal-open {
    overflow: hidden !important;
}

/* ضمان أن backdrop يمنع التفاعل */
.modal-backdrop {
    z-index: 1040 !important;
    background-color: #000 !important;
    pointer-events: auto !important;
    touch-action: none !important;
}

.modal-backdrop.show {
    opacity: 0.5 !important;
}

/* منع touch events على المحتوى خلف النموذج */
body.modal-open > *:not(.modal):not(.modal-backdrop) {
    pointer-events: none !important;
    touch-action: none !important;
}

/* السماح بالتفاعل داخل النموذج فقط */
#editScheduleModal.modal.show,
#reminderModal.modal.show {
    pointer-events: auto !important;
    touch-action: auto !important;
}

#editScheduleModal .modal-dialog,
#reminderModal .modal-dialog {
    pointer-events: auto !important;
    touch-action: auto !important;
}

#editScheduleModal .modal-content,
#reminderModal .modal-content {
    pointer-events: auto !important;
    touch-action: auto !important;
}

/* السماح بالتمرير داخل modal-body فقط */
#editScheduleModal .modal-body,
#reminderModal .modal-body {
    -webkit-overflow-scrolling: touch !important;
    touch-action: pan-y !important;
    pointer-events: auto !important;
}

/* منع scroll على المحتوى خلف النموذج على الموبايل */
@media (max-width: 768px) {
    body.modal-open {
        overflow: hidden !important;
        /* إزالة touch-action: none - يسبب freeze على الموبايل */
        position: fixed !important;
        width: 100% !important;
    }
    
    body.modal-open > *:not(.modal):not(.modal-backdrop) {
        pointer-events: none !important;
        /* إزالة touch-action: none - يسبب freeze */
        -webkit-overflow-scrolling: none !important;
    }
    
    /* السماح بالتمرير والتفاعل داخل النموذج فقط */
    #editScheduleModal .modal-body,
    #reminderModal .modal-body {
        -webkit-overflow-scrolling: touch !important;
        touch-action: pan-y pinch-zoom !important; /* السماح بالتمرير والـ pinch-zoom */
        overflow-y: auto !important;
        max-height: calc(100vh - 180px) !important;
    }
    
    /* السماح بالتفاعل الكامل داخل modal-content */
    #editScheduleModal .modal-content,
    #reminderModal .modal-content {
        touch-action: auto !important; /* السماح بجميع touch actions */
    }
    
    /* السماح بالتفاعل الكامل مع الحقول والأزرار */
    #editScheduleModal input,
    #editScheduleModal select,
    #editScheduleModal textarea,
    #editScheduleModal button,
    #reminderModal input,
    #reminderModal select,
    #reminderModal textarea,
    #reminderModal button {
        touch-action: manipulation !important; /* السماح بالتفاعل الطبيعي */
        -webkit-tap-highlight-color: rgba(0, 123, 255, 0.2) !important;
    }
}
</style>

<script>
// ===== منع scroll و touch خارج النموذج =====
document.addEventListener('DOMContentLoaded', function() {
    const modals = ['editScheduleModal', 'reminderModal'];
    
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        // عند فتح النموذج - لا حاجة لإضافة classes أو styles
        modal.addEventListener('show.bs.modal', function() {
            // لا شيء - Bootstrap يتعامل مع هذا تلقائياً
        });
        
        // عند إغلاق النموذج - لا حاجة لتنظيف
        modal.addEventListener('hidden.bs.modal', function() {
            // لا شيء - Bootstrap يتعامل مع هذا تلقائياً
        });
        
        // منع propagation للأحداث من النموذج إلى body - فقط للأحداث خارج modal-content
        modal.addEventListener('touchstart', function(e) {
            // السماح بالتفاعل داخل modal-content (الحقول والأزرار)
            const modalContent = modal.querySelector('.modal-content');
            if (modalContent && modalContent.contains(e.target)) {
                return; // لا تمنع propagation داخل modal-content
            }
            e.stopPropagation();
        }, { passive: true });
        
        modal.addEventListener('touchmove', function(e) {
            // السماح بالتمرير والتفاعل داخل modal-content
            const modalContent = modal.querySelector('.modal-content');
            if (modalContent && modalContent.contains(e.target)) {
                return; // لا تمنع propagation داخل modal-content
            }
            e.stopPropagation();
        }, { passive: true });
        
        modal.addEventListener('touchend', function(e) {
            // السماح بالتفاعل داخل modal-content
            const modalContent = modal.querySelector('.modal-content');
            if (modalContent && modalContent.contains(e.target)) {
                return; // لا تمنع propagation داخل modal-content
            }
            e.stopPropagation();
        }, { passive: true });
        
        // منع scroll على backdrop
        modal.addEventListener('wheel', function(e) {
            const modalContent = modal.querySelector('.modal-content');
            if (modalContent && !modalContent.contains(e.target)) {
                e.preventDefault();
            }
        }, { passive: false });
    });
    
    // منع scroll على backdrop مباشرة - فقط على backdrop نفسه
    document.addEventListener('touchmove', function(e) {
        // التحقق من أن الهدف هو backdrop وليس أي عنصر داخل modal
        const target = e.target;
        if (target && target.classList && target.classList.contains('modal-backdrop')) {
            // التحقق من عدم وجود modal مفتوح يحتوي على العنصر
            const openModal = document.querySelector('.modal.show');
            if (!openModal || !openModal.contains(target)) {
                e.preventDefault();
            }
        }
    }, { passive: false });
    
    document.addEventListener('wheel', function(e) {
        const target = e.target;
        if (target && target.classList && target.classList.contains('modal-backdrop')) {
            const openModal = document.querySelector('.modal.show');
            if (!openModal || !openModal.contains(target)) {
                e.preventDefault();
            }
        }
    }, { passive: false });
});
</script>


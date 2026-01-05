<?php
/**
 * صفحة إدارة المستخدمين والأدوار للمدير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['manager', 'developer']);

$currentUser = getCurrentUser();
$passwordMinLength = getPasswordMinLength();
$db = db();

$usersModuleContextValue = isset($usersModuleContext) && $usersModuleContext === 'security' ? 'security' : 'users';
$usersBaseParams = $usersModuleContextValue === 'security' ? ['page' => 'security', 'tab' => 'users'] : ['page' => 'users'];
$buildUsersUrl = function(array $extra = []) use ($usersBaseParams) {
    return $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($usersBaseParams, $extra));
};

// استلام رسائل النجاح أو الخطأ من session (بعد redirect)
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);
unset($_SESSION['error_message'], $_SESSION['success_message']);

// Pagination
// للحصول على رقم الصفحة من parameter منفصل (p) لتجنب التعارض مع page parameter
$pageNum = isset($_GET['p']) && is_numeric($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$page = $pageNum; // للتوافق مع الكود القديم
$perPage = 10;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$getFilterParams = function() use (&$pageNum, &$search, &$roleFilter, &$statusFilter) {
    $params = ['p' => $pageNum];
    if ($search !== '') {
        $params['search'] = $search;
    }
    if ($roleFilter !== '') {
        $params['role'] = $roleFilter;
    }
    if ($statusFilter !== '') {
        $params['status'] = $statusFilter;
    }
    return $params;
};

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $hourlyRate = cleanFinancialValue($_POST['hourly_rate'] ?? 0);
        
        if (empty($username) || empty($password) || empty($role)) {
            $error = 'يجب إدخال جميع الحقول المطلوبة';
        } elseif (strlen($password) < $passwordMinLength) {
            $error = 'كلمة المرور يجب أن تكون على الأقل ' . $passwordMinLength . ' أحرف';
        } else {
            // التحقق من وجود المستخدم
            $existing = getUserByUsername($username);
            if ($existing) {
                $error = 'اسم المستخدم موجود بالفعل';
            } else {
                $passwordHash = hashPassword($password);
                
                // التحقق من وجود عمود email في جدول users
                $emailColumnCheck = $db->queryOne("SHOW COLUMNS FROM users WHERE Field = 'email'");
                $hasEmailColumn = !empty($emailColumnCheck);
                
                // بناء استعلام INSERT ديناميكي بناءً على وجود عمود email
                if ($hasEmailColumn) {
                    // إنشاء email فريد بناءً على username لتجنب خطأ duplicate entry في عمود email الفريد
                    // إذا كان العمود NOT NULL، يجب توفير قيمة فريدة
                    $uniqueEmail = $username . '@' . uniqid() . '.local';
                    
                    $result = $db->execute(
                        "INSERT INTO users (username, email, password_hash, role, full_name, phone, hourly_rate, status) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'active')",
                        [$username, $uniqueEmail, $passwordHash, $role, $fullName, $phone, $hourlyRate]
                    );
                } else {
                    $result = $db->execute(
                        "INSERT INTO users (username, password_hash, role, full_name, phone, hourly_rate, status) 
                         VALUES (?, ?, ?, ?, ?, ?, 'active')",
                        [$username, $passwordHash, $role, $fullName, $phone, $hourlyRate]
                    );
                }
                
                logAudit($currentUser['id'], 'create_user', 'user', $result['insert_id'], null, [
                    'username' => $username,
                    'role' => $role
                ]);
                
                // استدعاء auto_salary_init بعد إنشاء المستخدم الجديد
                // يتم استدعاؤه مرة واحدة فقط بفضل الفحص الأولي في الملف
                if (file_exists(__DIR__ . '/../../includes/auto_salary_init.php')) {
                    try {
                        require_once __DIR__ . '/../../includes/auto_salary_init.php';
                        // استدعاء الدالة مباشرة بعد تحميل الملف
                        if (function_exists('runAutoSalaryInit')) {
                            runAutoSalaryInit();
                        }
                    } catch (Exception $e) {
                        error_log("auto_salary_init: Error after user creation: " . $e->getMessage());
                    }
                }
                
                // تطبيق PRG pattern لمنع التكرار
                preventDuplicateSubmission('تم إضافة المستخدم بنجاح', ['page' => 'users'], null, $currentUser['role']);
            }
        }
        if (!empty($error)) {
            // عند وجود خطأ، نستخدم PRG لكن بدون redirect فوري (يتم عرض الخطأ في الصفحة)
            // فقط نتحقق من عدم تكرار الطلب
            $_SESSION['error_message'] = $error;
        }
    } elseif ($action === 'update_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        // منع تغيير الدور - استخدام الدور الحالي من قاعدة البيانات
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $hourlyRate = cleanFinancialValue($_POST['hourly_rate'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        
        if ($userId <= 0) {
            $error = 'معرف المستخدم غير صحيح';
        } else {
            $user = getUserById($userId);
            if (!$user) {
                $error = 'المستخدم غير موجود';
            } else {
                if (empty($error)) {
                    // الاحتفاظ بالدور الحالي - منع تغيير الدور
                    $role = $user['role'];
                    
                    $db->execute(
                        "UPDATE users SET role = ?, full_name = ?, phone = ?, hourly_rate = ?, status = ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$role, $fullName, $phone, $hourlyRate, $status, $userId]
                    );
                    
                    // تنظيف Cache للمستخدم بعد التحديث
                    if (function_exists('clearUserCache')) {
                        clearUserCache($userId);
                    }
                    
                    logAudit($currentUser['id'], 'update_user', 'user', $userId, 
                             json_encode($user), ['role' => $role]);
                    
                    $_SESSION['success_message'] = 'تم تحديث المستخدم بنجاح';
                    $redirectUrl = $buildUsersUrl($getFilterParams());
                    
                    if (!headers_sent()) {
                        header('Location: ' . $redirectUrl);
                        exit;
                    } else {
                        echo '<script>window.location.href = ' . json_encode($redirectUrl) . ';</script>';
                        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '"></noscript>';
                        exit;
                    }
                }
            }
        }
        if (!empty($error)) {
            $_SESSION['error_message'] = $error;
            $redirectUrl = $buildUsersUrl($getFilterParams());
            
            if (!headers_sent()) {
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                echo '<script>window.location.href = ' . json_encode($redirectUrl) . ';</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '"></noscript>';
                exit;
            }
        }
    } elseif ($action === 'delete_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        
        if ($userId <= 0 || $userId == $currentUser['id']) {
            $error = 'لا يمكن حذف حسابك الخاص';
        } else {
            $user = getUserById($userId);
            if ($user) {
                $db->execute("DELETE FROM users WHERE id = ?", [$userId]);
                
                logAudit($currentUser['id'], 'delete_user', 'user', $userId, json_encode($user), null);
                
                $_SESSION['success_message'] = 'تم حذف المستخدم بنجاح';
                $redirectUrl = $buildUsersUrl($getFilterParams());
                
                if (!headers_sent()) {
                    header('Location: ' . $redirectUrl);
                    exit;
                } else {
                    echo '<script>window.location.href = ' . json_encode($redirectUrl) . ';</script>';
                    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '"></noscript>';
                    exit;
                }
            }
        }
        if (!empty($error)) {
            $_SESSION['error_message'] = $error;
            $redirectUrl = $buildUsersUrl($getFilterParams());
            
            if (!headers_sent()) {
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                echo '<script>window.location.href = ' . json_encode($redirectUrl) . ';</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '"></noscript>';
                exit;
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = intval($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        
        if ($userId <= 0 || empty($newPassword)) {
            $error = 'يجب إدخال كلمة مرور جديدة';
        } elseif (strlen($newPassword) < $passwordMinLength) {
            $error = 'كلمة المرور يجب أن تكون على الأقل ' . $passwordMinLength . ' أحرف';
        } else {
            $passwordHash = hashPassword($newPassword);
            $db->execute(
                "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?",
                [$passwordHash, $userId]
            );
            
            // تنظيف Cache للمستخدم بعد تغيير كلمة المرور
            if (function_exists('clearUserCache')) {
                clearUserCache($userId);
            }
            
            logAudit($currentUser['id'], 'reset_password', 'user', $userId, null, null);
            
            $success = 'تم إعادة تعيين كلمة المرور بنجاح';
        }
    }
}

// بناء استعلام البحث
$sql = "SELECT u.*, COUNT(DISTINCT w.id) as webauthn_count 
        FROM users u 
        LEFT JOIN webauthn_credentials w ON u.id = w.user_id";
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(u.username LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($roleFilter)) {
    $where[] = "u.role = ?";
    $params[] = $roleFilter;
}

if (!empty($statusFilter)) {
    $where[] = "u.status = ?";
    $params[] = $statusFilter;
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " GROUP BY u.id";

// الحصول على العدد الإجمالي
$countSql = "SELECT COUNT(DISTINCT u.id) as total FROM users u";
if (!empty($where)) {
    $countSql .= " WHERE " . implode(" AND ", $where);
}
$totalResult = $db->queryOne($countSql, $params);
$totalUsers = $totalResult['total'] ?? 0;
$totalPages = ceil($totalUsers / $perPage);
$page = $pageNum; // تحديث $page للتوافق

// الحصول على المستخدمين مع Pagination
$sql .= " ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$users = $db->query($sql, $params);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-people me-2"></i>إدارة المستخدمين والأدوار</h2>
    <button class="btn btn-primary" onclick="showAddUserModal()">
        <i class="bi bi-person-plus me-2"></i>إضافة مستخدم
    </button>
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

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <?php
        require_once __DIR__ . '/../../includes/path_helper.php';
        $currentUrl = getRelativeUrl('dashboard/manager.php');
        ?>
        <form method="GET" action="<?php echo htmlspecialchars($currentUrl); ?>" class="row g-3">
            <input type="hidden" name="page" value="<?php echo $usersModuleContextValue === 'security' ? 'security' : 'users'; ?>">
            <?php if ($usersModuleContextValue === 'security'): ?>
            <input type="hidden" name="tab" value="users">
            <?php endif; ?>
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" 
                       placeholder="بحث (اسم، بريد، اسم كامل)..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="role">
                    <option value="">جميع الأدوار</option>
                    <option value="manager" <?php echo $roleFilter === 'manager' ? 'selected' : ''; ?>>مدير</option>
                    <option value="accountant" <?php echo $roleFilter === 'accountant' ? 'selected' : ''; ?>>محاسب</option>
                    <option value="sales" <?php echo $roleFilter === 'sales' ? 'selected' : ''; ?>>مندوب مبيعات</option>
                    <option value="production" <?php echo $roleFilter === 'production' ? 'selected' : ''; ?>>عامل إنتاج</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>نشط</option>
                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>غير نشط</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-2"></i>بحث
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة المستخدمين -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة المستخدمين (<?php echo $totalUsers; ?>)</h5>
    </div>
    <div class="card-body">
        <!-- عرض البطاقات على الموبايل -->
        <div class="d-md-none">
            <?php if (empty($users)): ?>
                <div class="text-center text-muted py-4">لا توجد نتائج</div>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <?php 
                    // تنظيف hourly_rate بشكل شامل
                    $hourlyRate = $user['hourly_rate'];
                    
                    $hourlyRate = cleanFinancialValue($hourlyRate);
                    ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($user['username']); ?></h6>
                                    <small class="text-muted"><?php echo htmlspecialchars($user['full_name'] ?? '-'); ?></small>
                                </div>
                                <span class="badge bg-<?php 
                                    echo $user['role'] === 'manager' ? 'danger' : 
                                        ($user['role'] === 'accountant' ? 'primary' : 
                                        ($user['role'] === 'sales' ? 'success' : 'info')); 
                                ?>">
                                    <?php echo $lang['role_' . $user['role']] ?? $user['role']; ?>
                                </span>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <small class="text-muted d-block">سعر الساعة</small>
                                    <small><?php echo formatCurrency($hourlyRate); ?></small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">WebAuthn</small>
                                    <?php if ($user['webauthn_count'] > 0): ?>
                                        <span class="badge bg-success"><?php echo $user['webauthn_count']; ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">غير مفعّل</span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">الحالة</small>
                                    <span class="badge bg-<?php echo $user['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo $lang[$user['status']] ?? $user['status']; ?>
                                    </span>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted d-block">تاريخ التسجيل</small>
                                    <small><?php echo formatDate($user['created_at']); ?></small>
                                </div>
                            </div>
                            <div class="btn-group btn-group-sm w-100 mt-2" role="group">
                                <button class="btn btn-info btn-sm" 
                                        onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)"
                                        title="تعديل">
                                    <i class="bi bi-pencil"></i> تعديل
                                </button>
                                <button class="btn btn-warning btn-sm" 
                                        onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')"
                                        title="إعادة تعيين كلمة المرور">
                                    <i class="bi bi-key"></i> كلمة المرور
                                </button>
                                <?php if ($user['id'] != $currentUser['id']): ?>
                                <button class="btn btn-danger btn-sm" 
                                        onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')"
                                        title="حذف">
                                    <i class="bi bi-trash"></i> حذف
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- عرض الجدول على الشاشات الكبيرة -->
        <div class="table-responsive dashboard-table-wrapper d-none d-md-block">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>اسم المستخدم</th>
                        <th>الاسم الكامل</th>
                        <th>الدور</th>
                        <th>سعر الساعة</th>
                        <th>WebAuthn</th>
                        <th>الحالة</th>
                        <th>تاريخ التسجيل</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد نتائج</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $user['role'] === 'manager' ? 'danger' : 
                                            ($user['role'] === 'accountant' ? 'primary' : 
                                            ($user['role'] === 'sales' ? 'success' : 'info')); 
                                    ?>">
                                        <?php echo $lang['role_' . $user['role']] ?? $user['role']; ?>
                                    </span>
                                </td>
                                <td><?php 
                                    $hourlyRate = cleanFinancialValue($user['hourly_rate']);
                                    echo formatCurrency($hourlyRate); 
                                ?></td>
                                <td>
                                    <?php if ($user['webauthn_count'] > 0): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-circle me-1"></i><?php echo $user['webauthn_count']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">غير مفعّل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $user['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo $lang[$user['status']] ?? $user['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo formatDate($user['created_at']); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-info" 
                                                onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)"
                                                title="تعديل">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-warning" 
                                                onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')"
                                                title="إعادة تعيين كلمة المرور">
                                            <i class="bi bi-key"></i>
                                        </button>
                                        <?php if ($user['id'] != $currentUser['id']): ?>
                                        <button class="btn btn-danger" 
                                                onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')"
                                                title="حذف">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <?php
        $paginationFilters = [];
        if ($search !== '') {
            $paginationFilters['search'] = $search;
        }
        if ($roleFilter !== '') {
            $paginationFilters['role'] = $roleFilter;
        }
        if ($statusFilter !== '') {
            $paginationFilters['status'] = $statusFilter;
        }
        $prevParams = $paginationFilters;
        $prevParams['p'] = max(1, $page - 1);
        $prevUrl = $buildUsersUrl($prevParams);
        $nextParams = $paginationFilters;
        $nextParams['p'] = min($totalPages, $page + 1);
        $nextUrl = $buildUsersUrl($nextParams);
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($prevUrl); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php if ($startPage > 1): ?>
                    <?php
                    $firstParams = $paginationFilters;
                    $firstParams['p'] = 1;
                    $firstUrl = $buildUsersUrl($firstParams);
                    ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($firstUrl); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php
                    $pageParams = $paginationFilters;
                    $pageParams['p'] = $i;
                    $pageUrl = $buildUsersUrl($pageParams);
                    ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($pageUrl); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <?php
                    $lastParams = $paginationFilters;
                    $lastParams['p'] = $totalPages;
                    $lastUrl = $buildUsersUrl($lastParams);
                    ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($lastUrl); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($nextUrl); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal للكمبيوتر فقط - إضافة مستخدم -->
<div class="modal fade d-none d-md-block" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة مستخدم جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">اسم المستخدم <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الدور <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" required>
                                <option value="">اختر الدور</option>
                                <option value="manager">مدير</option>
                                <option value="accountant">محاسب</option>
                                <option value="sales">مندوب مبيعات</option>
                                <option value="production">عامل إنتاج</option>
                            </select>
                            <small class="text-muted">لا يمكن تغيير الدور بعد إنشاء المستخدم</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">كلمة المرور <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="password" 
                                   minlength="<?php echo $passwordMinLength; ?>" required>
                            <small class="text-muted">على الأقل <?php echo $passwordMinLength; ?> أحرف</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الاسم الكامل</label>
                            <input type="text" class="form-control" name="full_name">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="tel" class="form-control" name="phone">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الساعة (ج.م)</label>
                        <input type="number" step="0.01" class="form-control" name="hourly_rate" value="0" min="0" max="10000">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal للكمبيوتر فقط - تعديل مستخدم -->
<div class="modal fade d-none d-md-block" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل مستخدم</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">اسم المستخدم</label>
                            <input type="text" class="form-control" id="editUsername" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الدور</label>
                            <input type="text" class="form-control" id="editRoleDisplay" readonly style="background-color: #e9ecef;">
                            <input type="hidden" name="role" id="editRole">
                            <small class="text-muted">لا يمكن تغيير الدور بعد إنشاء المستخدم</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الحالة</label>
                            <select class="form-select" name="status" id="editStatus">
                                <option value="active">نشط</option>
                                <option value="inactive">غير نشط</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الاسم الكامل</label>
                            <input type="text" class="form-control" name="full_name" id="editFullName">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="tel" class="form-control" name="phone" id="editPhone">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الساعة (ج.م)</label>
                        <input type="number" step="0.01" class="form-control" name="hourly_rate" id="editHourlyRate" min="0" max="10000">
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

<!-- Modal للكمبيوتر فقط - إعادة تعيين كلمة المرور -->
<div class="modal fade d-none d-md-block" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إعادة تعيين كلمة المرور</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المستخدم</label>
                        <input type="text" class="form-control" id="resetUsername" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">كلمة المرور الجديدة <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="new_password" 
                               minlength="<?php echo $passwordMinLength; ?>" required>
                        <small class="text-muted">على الأقل <?php echo $passwordMinLength; ?> أحرف</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">إعادة تعيين</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card للموبايل - إضافة مستخدم -->
<div class="card shadow-sm mb-4 d-md-none" id="addUserCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">إضافة مستخدم جديد</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <div class="row">
                <div class="col-12 mb-3">
                    <label class="form-label">اسم المستخدم <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="username" required>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">الدور <span class="text-danger">*</span></label>
                    <select class="form-select" name="role" required>
                        <option value="">اختر الدور</option>
                        <option value="manager">مدير</option>
                        <option value="accountant">محاسب</option>
                        <option value="sales">مندوب مبيعات</option>
                        <option value="production">عامل إنتاج</option>
                    </select>
                    <small class="text-muted">لا يمكن تغيير الدور بعد إنشاء المستخدم</small>
                </div>
            </div>
            <div class="row">
                <div class="col-12 mb-3">
                    <label class="form-label">كلمة المرور <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="password" 
                           minlength="<?php echo $passwordMinLength; ?>" required>
                    <small class="text-muted">على الأقل <?php echo $passwordMinLength; ?> أحرف</small>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">الاسم الكامل</label>
                    <input type="text" class="form-control" name="full_name">
                </div>
            </div>
            <div class="row">
                <div class="col-12 mb-3">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="tel" class="form-control" name="phone">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">سعر الساعة (ج.م)</label>
                <input type="number" step="0.01" class="form-control" name="hourly_rate" value="0" min="0" max="10000">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">إضافة</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddUserCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - تعديل مستخدم -->
<div class="card shadow-sm mb-4 d-md-none" id="editUserCard" style="display: none;">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">تعديل مستخدم</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="editUserCardId">
            <div class="row">
                <div class="col-12 mb-3">
                    <label class="form-label">اسم المستخدم</label>
                    <input type="text" class="form-control" id="editUserCardUsername" readonly>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">الدور</label>
                    <input type="text" class="form-control" id="editUserCardRoleDisplay" readonly style="background-color: #e9ecef;">
                    <input type="hidden" name="role" id="editUserCardRole">
                    <small class="text-muted">لا يمكن تغيير الدور بعد إنشاء المستخدم</small>
                </div>
            </div>
            <div class="row">
                <div class="col-12 mb-3">
                    <label class="form-label">الحالة</label>
                    <select class="form-select" name="status" id="editUserCardStatus">
                        <option value="active">نشط</option>
                        <option value="inactive">غير نشط</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-12 mb-3">
                    <label class="form-label">الاسم الكامل</label>
                    <input type="text" class="form-control" name="full_name" id="editUserCardFullName">
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="tel" class="form-control" name="phone" id="editUserCardPhone">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">سعر الساعة (ج.م)</label>
                <input type="number" step="0.01" class="form-control" name="hourly_rate" id="editUserCardHourlyRate" min="0" max="10000">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditUserCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - إعادة تعيين كلمة المرور -->
<div class="card shadow-sm mb-4 d-md-none" id="resetPasswordCard" style="display: none;">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0">إعادة تعيين كلمة المرور</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetPasswordCardUserId">
            <div class="mb-3">
                <label class="form-label">المستخدم</label>
                <input type="text" class="form-control" id="resetPasswordCardUsername" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">كلمة المرور الجديدة <span class="text-danger">*</span></label>
                <input type="password" class="form-control" name="new_password" 
                       minlength="<?php echo $passwordMinLength; ?>" required>
                <small class="text-muted">على الأقل <?php echo $passwordMinLength; ?> أحرف</small>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-warning">إعادة تعيين</button>
                <button type="button" class="btn btn-secondary" onclick="closeResetPasswordCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
// ===== دوال أساسية =====

// التحقق من الموبايل
function isMobile() {
    return window.innerWidth <= 768;
}

// Scroll تلقائي للعنصر
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

// إغلاق جميع النماذج
function closeAllForms() {
    const cards = ['addUserCard', 'editUserCard', 'resetPasswordCard'];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    const modals = ['addUserModal', 'editUserModal', 'resetPasswordModal'];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// ===== دوال فتح النماذج =====

// فتح نموذج إضافة مستخدم
function showAddUserModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addUserCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addUserModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// ===== دوال إغلاق Cards =====

function closeAddUserCard() {
    const card = document.getElementById('addUserCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeEditUserCard() {
    const card = document.getElementById('editUserCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeResetPasswordCard() {
    const card = document.getElementById('resetPasswordCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

// ===== دوال موجودة - تعديلها لدعم الموبايل =====

function editUser(user) {
    closeAllForms();
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('editUserCard');
        if (card) {
            document.getElementById('editUserCardId').value = user.id;
            document.getElementById('editUserCardUsername').value = user.username;
            document.getElementById('editUserCardRole').value = user.role;
            
            const roleNames = {
                'manager': 'مدير',
                'accountant': 'محاسب',
                'sales': 'مندوب مبيعات',
                'production': 'عامل إنتاج'
            };
            document.getElementById('editUserCardRoleDisplay').value = roleNames[user.role] || user.role;
            document.getElementById('editUserCardStatus').value = user.status;
            document.getElementById('editUserCardFullName').value = user.full_name || '';
            document.getElementById('editUserCardPhone').value = user.phone || '';
            let hourlyRate = parseFloat(user.hourly_rate) || 0;
            if (isNaN(hourlyRate) || hourlyRate > 100000) {
                hourlyRate = 0;
            }
            document.getElementById('editUserCardHourlyRate').value = hourlyRate;
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        document.getElementById('editUserId').value = user.id;
        document.getElementById('editUsername').value = user.username;
        
        // تعيين الدور في الحقل المخفي
        document.getElementById('editRole').value = user.role;
        
        // عرض الدور في حقل العرض فقط
        const roleNames = {
            'manager': 'مدير',
            'accountant': 'محاسب',
            'sales': 'مندوب مبيعات',
            'production': 'عامل إنتاج'
        };
        document.getElementById('editRoleDisplay').value = roleNames[user.role] || user.role;
        
        document.getElementById('editStatus').value = user.status;
        document.getElementById('editFullName').value = user.full_name || '';
        document.getElementById('editPhone').value = user.phone || '';
        let hourlyRate = parseFloat(user.hourly_rate) || 0;
        if (isNaN(hourlyRate) || hourlyRate > 100000) {
            hourlyRate = 0;
        }
        document.getElementById('editHourlyRate').value = hourlyRate;
        
        const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        modal.show();
    }
}

function resetPassword(userId, username) {
    closeAllForms();
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('resetPasswordCard');
        if (card) {
            document.getElementById('resetPasswordCardUserId').value = userId;
            document.getElementById('resetPasswordCardUsername').value = username;
            
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        document.getElementById('resetUserId').value = userId;
        document.getElementById('resetUsername').value = username;
        
        const modal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
        modal.show();
    }
}
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUsername').value = user.username;
    
    // تعيين الدور في الحقل المخفي
    document.getElementById('editRole').value = user.role;
    
    // عرض الدور في حقل العرض فقط
    const roleNames = {
        'manager': 'مدير',
        'accountant': 'محاسب',
        'sales': 'مندوب مبيعات',
        'production': 'عامل إنتاج'
    };
    document.getElementById('editRoleDisplay').value = roleNames[user.role] || user.role;
    
    document.getElementById('editStatus').value = user.status;
    document.getElementById('editFullName').value = user.full_name || '';
    document.getElementById('editPhone').value = user.phone || '';
    let hourlyRate = parseFloat(user.hourly_rate) || 0;
    if (isNaN(hourlyRate) || hourlyRate > 100000) {
        hourlyRate = 0;
    }
    document.getElementById('editHourlyRate').value = hourlyRate;
    
    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

function resetPassword(userId, username) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUsername').value = username;
    
    const modal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    modal.show();
}

function deleteUser(userId, username) {
    closeAllForms();
    
    if (isMobile()) {
        // على الموبايل: استخدام Card (يمكن إضافة Card للحذف إذا لزم الأمر)
        // حالياً نستخدم confirm على الموبايل أيضاً
        if (confirm('هل أنت متأكد من حذف المستخدم "' + username + '"؟\n\nهذه العملية لا يمكن التراجع عنها.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="${userId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    } else {
        // على الكمبيوتر: استخدام confirm
        if (confirm('هل أنت متأكد من حذف المستخدم "' + username + '"؟\n\nهذه العملية لا يمكن التراجع عنها.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="${userId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
}
</script>



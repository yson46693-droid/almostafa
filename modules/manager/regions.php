<?php
/**
 * صفحة إدارة المناطق
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

if (!defined('REGIONS_MODULE_BOOTSTRAPPED')) {
    define('REGIONS_MODULE_BOOTSTRAPPED', true);

    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/audit_log.php';
    require_once __DIR__ . '/../../includes/path_helper.php';
}

$currentUser = getCurrentUser();
$userRole = strtolower($currentUser['role'] ?? '');
$db = db();

// التأكد من وجود جدول regions
try {
    $regionsTable = $db->queryOne("SHOW TABLES LIKE 'regions'");
    if (empty($regionsTable)) {
        $createRegionsTableSql = "CREATE TABLE IF NOT EXISTS `regions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المناطق'";
        
        $connection = $db->getConnection();
        $connection->query($createRegionsTableSql);
    }
} catch (Throwable $e) {
    error_log('Error creating regions table: ' . $e->getMessage());
}

$error = '';
$success = '';

// معالجة POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_region') {
        // فقط المدير يمكنه إضافة مناطق
        if ($userRole !== 'manager') {
            $error = 'غير مصرح لك بإضافة مناطق';
        } else {
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                $error = 'يجب إدخال اسم المنطقة';
            } else {
                try {
                    // التحقق من عدم التكرار
                    $existing = $db->queryOne("SELECT id FROM regions WHERE name = ?", [$name]);
                    if ($existing) {
                        $error = 'المنطقة موجودة بالفعل';
                    } else {
                        $db->execute("INSERT INTO regions (name) VALUES (?)", [$name]);
                        logAudit($currentUser['id'], 'add_region', 'region', $db->getLastInsertId(), null, ['name' => $name]);
                        $_SESSION['success_message'] = 'تم إضافة المنطقة بنجاح';
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    }
                } catch (Throwable $e) {
                    error_log('Add region error: ' . $e->getMessage());
                    $error = 'حدث خطأ أثناء إضافة المنطقة';
                }
            }
        }
    } elseif ($action === 'edit_region') {
        // المدير والمحاسب والمندوب يمكنهم تعديل المناطق
        if (!in_array($userRole, ['manager', 'developer', 'accountant', 'sales'], true)) {
            $error = 'غير مصرح لك بتعديل المناطق';
        } else {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim($_POST['name'] ?? '');
            if ($id <= 0) {
                $error = 'معرف المنطقة غير صحيح';
            } elseif (empty($name)) {
                $error = 'يجب إدخال اسم المنطقة';
            } else {
                try {
                    // التحقق من عدم التكرار
                    $existing = $db->queryOne("SELECT id FROM regions WHERE name = ? AND id != ?", [$name, $id]);
                    if ($existing) {
                        $error = 'المنطقة موجودة بالفعل';
                    } else {
                        $db->execute("UPDATE regions SET name = ? WHERE id = ?", [$name, $id]);
                        logAudit($currentUser['id'], 'edit_region', 'region', $id, null, ['name' => $name]);
                        $_SESSION['success_message'] = 'تم تعديل المنطقة بنجاح';
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    }
                } catch (Throwable $e) {
                    error_log('Edit region error: ' . $e->getMessage());
                    $error = 'حدث خطأ أثناء تعديل المنطقة';
                }
            }
        }
    } elseif ($action === 'delete_region') {
        // فقط المدير يمكنه حذف المناطق
        if ($userRole !== 'manager') {
            $error = 'غير مصرح لك بحذف المناطق';
        } else {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id <= 0) {
                $error = 'معرف المنطقة غير صحيح';
            } else {
                try {
                    // التحقق من وجود عملاء مرتبطين بهذه المنطقة
                    $customersCount = $db->queryOne("SELECT COUNT(*) as count FROM customers WHERE region_id = ?", [$id]);
                    $localCustomersCount = $db->queryOne("SELECT COUNT(*) as count FROM local_customers WHERE region_id = ?", [$id]);
                    $totalCount = (int)($customersCount['count'] ?? 0) + (int)($localCustomersCount['count'] ?? 0);
                    
                    if ($totalCount > 0) {
                        $error = 'لا يمكن حذف المنطقة لأنها مرتبطة بـ ' . $totalCount . ' عميل';
                    } else {
                        $regionName = $db->queryOne("SELECT name FROM regions WHERE id = ?", [$id]);
                        $db->execute("DELETE FROM regions WHERE id = ?", [$id]);
                        logAudit($currentUser['id'], 'delete_region', 'region', $id, null, ['name' => $regionName['name'] ?? '']);
                        $_SESSION['success_message'] = 'تم حذف المنطقة بنجاح';
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    }
                } catch (Throwable $e) {
                    error_log('Delete region error: ' . $e->getMessage());
                    $error = 'حدث خطأ أثناء حذف المنطقة';
                }
            }
        }
    }
}

// استلام الرسائل من session
$error = $_SESSION['error_message'] ?? $error;
$success = $_SESSION['success_message'] ?? $success;
unset($_SESSION['error_message'], $_SESSION['success_message']);

// جلب جميع المناطق
$regions = $db->query("SELECT r.*, 
    (SELECT COUNT(*) FROM customers WHERE region_id = r.id) + 
    (SELECT COUNT(*) FROM local_customers WHERE region_id = r.id) as customers_count
    FROM regions r 
    ORDER BY r.name ASC");

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = $translations;
?>

<div class="page-header mb-4 d-flex justify-content-between align-items-center">
    <h2><i class="bi bi-geo-alt me-2"></i>إدارة المناطق</h2>
    <?php if ($userRole === 'manager'): ?>
        <button type="button" class="btn btn-primary" onclick="showAddRegionModal()">
            <i class="bi bi-plus-circle me-2"></i>إضافة منطقة جديدة
        </button>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>اسم المنطقة</th>
                        <th>عدد العملاء</th>
                        <th>تاريخ الإنشاء</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($regions)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">لا توجد مناطق مسجلة</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($regions as $index => $region): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($region['name']); ?></strong></td>
                                <td>
                                    <span class="badge bg-info"><?php echo (int)($region['customers_count'] ?? 0); ?></span>
                                </td>
                                <td><?php echo formatDateTime($region['created_at']); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary edit-region-btn" 
                                                data-id="<?php echo $region['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($region['name']); ?>">
                                            <i class="bi bi-pencil"></i> تعديل
                                        </button>
                                        <?php if ($userRole === 'manager'): ?>
                                            <button type="button" class="btn btn-outline-danger delete-region-btn" 
                                                    data-id="<?php echo $region['id']; ?>" 
                                                    data-name="<?php echo htmlspecialchars($region['name']); ?>"
                                                    data-customers-count="<?php echo (int)($region['customers_count'] ?? 0); ?>">
                                                <i class="bi bi-trash"></i> حذف
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
    </div>
</div>

<!-- Modal للكمبيوتر فقط - إضافة منطقة -->
<?php if ($userRole === 'manager'): ?>
<div class="modal fade d-none d-md-block" id="addRegionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة منطقة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_region">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنطقة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
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
<?php endif; ?>

<!-- Modal للكمبيوتر فقط - تعديل منطقة -->
<div class="modal fade d-none d-md-block" id="editRegionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل المنطقة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_region">
                <input type="hidden" name="id" id="editRegionId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنطقة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="editRegionName" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($userRole === 'manager'): ?>
<!-- Card للموبايل - إضافة منطقة -->
<div class="card shadow-sm mb-4 d-md-none" id="addRegionCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">إضافة منطقة جديدة</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_region">
            <div class="mb-3">
                <label class="form-label">اسم المنطقة <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" required>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">إضافة</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddRegionCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Card للموبايل - تعديل منطقة -->
<div class="card shadow-sm mb-4 d-md-none" id="editRegionCard" style="display: none;">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">تعديل المنطقة</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="edit_region">
            <input type="hidden" name="id" id="editRegionCardId">
            <div class="mb-3">
                <label class="form-label">اسم المنطقة <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" id="editRegionCardName" required>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditRegionCard()">إلغاء</button>
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
    const cards = ['addRegionCard', 'editRegionCard'];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    const modals = ['addRegionModal', 'editRegionModal'];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// ===== دوال فتح النماذج =====

function showAddRegionModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addRegionCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addRegionModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// ===== دوال إغلاق Cards =====

function closeAddRegionCard() {
    const card = document.getElementById('addRegionCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeEditRegionCard() {
    const card = document.getElementById('editRegionCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

// ===== Event Listeners =====

document.addEventListener('DOMContentLoaded', function() {
    // تعديل المنطقة
    document.querySelectorAll('.edit-region-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            closeAllForms();
            
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            
            if (isMobile()) {
                // على الموبايل: استخدام Card
                const card = document.getElementById('editRegionCard');
                if (card) {
                    document.getElementById('editRegionCardId').value = id;
                    document.getElementById('editRegionCardName').value = name;
                    
                    card.style.display = 'block';
                    setTimeout(function() {
                        scrollToElement(card);
                    }, 50);
                }
            } else {
                // على الكمبيوتر: استخدام Modal
                document.getElementById('editRegionId').value = id;
                document.getElementById('editRegionName').value = name;
                const modal = new bootstrap.Modal(document.getElementById('editRegionModal'));
                modal.show();
            }
        });
    });
    
    // حذف المنطقة
    document.querySelectorAll('.delete-region-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const customersCount = this.getAttribute('data-customers-count');
            
            if (parseInt(customersCount) > 0) {
                alert('لا يمكن حذف المنطقة لأنها مرتبطة بـ ' + customersCount + ' عميل');
                return;
            }
            
            if (confirm('هل أنت متأكد من حذف المنطقة "' + name + '"؟')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_region">' +
                                '<input type="hidden" name="id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
});
</script>


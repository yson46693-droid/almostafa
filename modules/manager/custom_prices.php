<?php
/**
 * صفحة الأسعار المخصصة - للمدير والمحاسب
 * خانة العميل: اختيار من العملاء المحليين / عملاء المندوب / عميل جديد يدوي (يُحفظ في المحليين)
 * قائمة المنتجات (أسماء القوالب) + وحدة + سعر مخصص. حفظ وعرض الأسعار في بطاقة.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

$db = db();
$currentUser = getCurrentUser();
$basePath = getBasePath();
$apiBase = rtrim(getRelativeUrl('api'), '/');

// قائمة العملاء المحليين
$localCustomers = [];
try {
    $t = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (!empty($t)) {
        $localCustomers = $db->query("SELECT id, name FROM local_customers WHERE status = 'active' ORDER BY name ASC");
    }
} catch (Throwable $e) {
    error_log('custom_prices local_customers: ' . $e->getMessage());
}

// قائمة عملاء المندوبين (مبسطة للاختيار)
$repCustomers = [];
try {
    $repCustomers = $db->query("
        SELECT c.id, c.name,
               COALESCE(rep1.full_name, rep2.full_name) as rep_name
        FROM customers c
        LEFT JOIN users rep1 ON c.rep_id = rep1.id AND rep1.role = 'sales'
        LEFT JOIN users rep2 ON c.created_by = rep2.id AND rep2.role = 'sales'
        WHERE c.status = 'active'
        ORDER BY c.name ASC
        LIMIT 500
    ");
} catch (Throwable $e) {
    error_log('custom_prices rep customers: ' . $e->getMessage());
}

$units = ['كرتونه', 'عبوة', 'كيلو', 'جرام', 'شرينك', 'جركن', 'قطعة'];
?>
<style>
.custom-prices-page .card { border-radius: 12px; }
.custom-prices-page .form-select, .custom-prices-page .form-control { border-radius: 8px; }
.custom-prices-page .btn { border-radius: 8px; }
.custom-prices-page .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.custom-prices-page .price-row td { vertical-align: middle; }
.custom-prices-page .saved-list .list-group-item { border-radius: 8px; margin-bottom: 6px; }
@media (max-width: 768px) {
    .custom-prices-page .customer-type-wrap { flex-direction: column; align-items: stretch; }
    .custom-prices-page .product-row select { font-size: 0.9rem; }
}
</style>

<div class="custom-prices-page container-fluid py-3">
    <div class="page-header mb-4">
        <h2 class="h4 mb-0"><i class="bi bi-tag me-2"></i>الأسعار المخصصة</h2>
        <p class="text-muted small mb-0">تخصيص أسعار المنتجات حسب العميل وعرض البطاقة</p>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>خانة العميل</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">نوع العميل</label>
                        <div class="customer-type-wrap d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="customer_type_radio" id="ct_local" value="local" checked>
                                <label class="form-check-label" for="ct_local">عميل محلي</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="customer_type_radio" id="ct_rep" value="rep">
                                <label class="form-check-label" for="ct_rep">عميل مندوب</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="customer_type_radio" id="ct_manual" value="manual">
                                <label class="form-check-label" for="ct_manual">عميل جديد يدوي</label>
                            </div>
                        </div>
                    </div>

                    <div id="customer_select_local" class="customer-select-block mb-3">
                        <label class="form-label">اختر العميل المحلي</label>
                        <select id="local_customer_id" class="form-select">
                            <option value="">-- اختر عميلًا --</option>
                            <?php foreach ($localCustomers as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="customer_select_rep" class="customer-select-block mb-3 d-none">
                        <label class="form-label">اختر عميل المندوب</label>
                        <select id="rep_customer_id" class="form-select">
                            <option value="">-- اختر عميلًا --</option>
                            <?php foreach ($repCustomers as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?><?php if (!empty($c['rep_name'])) echo ' (' . htmlspecialchars($c['rep_name']) . ')'; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="customer_manual_block" class="customer-select-block mb-3 d-none">
                        <label class="form-label">اسم العميل الجديد</label>
                        <input type="text" id="manual_customer_name" class="form-control" placeholder="اسم العميل">
                        <div class="mt-2">
                            <label class="form-label small">رقم الهاتف (اختياري)</label>
                            <input type="text" id="manual_phone" class="form-control" placeholder="رقم الهاتف">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">حقل إدخال إضافي (اختياري)</label>
                        <input type="text" id="manual_extra_field" class="form-control" placeholder="ملاحظات أو بيان إضافي">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-box-seam me-2"></i>المنتجات والأسعار</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="add_product_row"><i class="bi bi-plus-lg me-1"></i>إضافة سطر</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>المنتج</th>
                                    <th style="width:130px">الوحدة</th>
                                    <th style="width:120px">السعر</th>
                                    <th style="width:50px"></th>
                                </tr>
                            </thead>
                            <tbody id="product_rows">
                                <tr class="price-row">
                                    <td>
                                        <select class="form-select form-select-sm product-name-select">
                                            <option value="">-- اختر منتجًا --</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm unit-select">
                                            <?php foreach ($units as $u): ?>
                                                <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" class="form-control form-control-sm price-input" placeholder="0" min="0" step="0.01" value=""></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="حذف"><i class="bi bi-trash"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary" id="save_custom_prices_btn"><i class="bi bi-check-lg me-1"></i>حفظ الأسعار</button>
                    </div>
                    <div id="save_message" class="mt-2 d-none"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-collection me-2"></i>البطاقات المحفوظة</h5>
                </div>
                <div class="card-body">
                    <div id="saved_list_loading" class="text-muted small">جاري التحميل...</div>
                    <div id="saved_list_empty" class="text-muted small d-none">لا توجد أسعار مخصصة محفوظة بعد.</div>
                    <div id="saved_list" class="saved-list list-group d-none"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- بطاقة عرض الأسعار (مودال) -->
<div class="modal fade" id="viewPricesModal" tabindex="-1" aria-labelledby="viewPricesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewPricesModalLabel"><i class="bi bi-tag me-2"></i>عرض الأسعار المخصصة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <p id="view_prices_customer_name" class="fw-bold mb-3"></p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="table-light">
                            <tr>
                                <th>المنتج</th>
                                <th>الوحدة</th>
                                <th>السعر</th>
                            </tr>
                        </thead>
                        <tbody id="view_prices_tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var basePath = <?php echo json_encode($basePath); ?>;
    var apiBase = <?php echo json_encode($apiBase); ?>;
    if (!apiBase) apiBase = basePath ? basePath + '/api' : 'api';

    var units = <?php echo json_encode($units); ?>;
    var productTemplates = [];

    function apiUrl(path) {
        var p = path.indexOf('/') === 0 ? path.slice(1) : path;
        return apiBase.indexOf('/') === 0 ? apiBase + '/' + p : apiBase + '/' + p;
    }

    function setCustomerBlocks() {
        var v = document.querySelector('input[name="customer_type_radio"]:checked');
        var val = v ? v.value : 'local';
        document.getElementById('customer_select_local').classList.toggle('d-none', val !== 'local');
        document.getElementById('customer_select_rep').classList.toggle('d-none', val !== 'rep');
        document.getElementById('customer_manual_block').classList.toggle('d-none', val !== 'manual');
    }
    document.querySelectorAll('input[name="customer_type_radio"]').forEach(function(r) {
        r.addEventListener('change', setCustomerBlocks);
    });
    setCustomerBlocks();

    function loadTemplates() {
        fetch(apiUrl('get_product_templates.php'))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                productTemplates = (data && data.templates) ? data.templates : [];
                var opts = '<option value="">-- اختر منتجًا --</option>';
                productTemplates.forEach(function(name) {
                    opts += '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>';
                });
                document.querySelectorAll('.product-name-select').forEach(function(sel) {
                    var cur = sel.value;
                    sel.innerHTML = opts;
                    if (cur) sel.value = cur;
                });
            })
            .catch(function() { productTemplates = []; });
    }
    function escapeHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    loadTemplates();

    function addProductRow() {
        var tbody = document.getElementById('product_rows');
        var tr = document.createElement('tr');
        tr.className = 'price-row';
        var optHtml = '<option value="">-- اختر منتجًا --</option>';
        productTemplates.forEach(function(name) {
            optHtml += '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>';
        });
        var unitOpts = units.map(function(u) { return '<option value="' + escapeHtml(u) + '">' + escapeHtml(u) + '</option>'; }).join('');
        tr.innerHTML =
            '<td><select class="form-select form-select-sm product-name-select">' + optHtml + '</select></td>' +
            '<td><select class="form-select form-select-sm unit-select">' + unitOpts + '</select></td>' +
            '<td><input type="number" class="form-control form-control-sm price-input" placeholder="0" min="0" step="0.01" value=""></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="حذف"><i class="bi bi-trash"></i></button></td>';
        tbody.appendChild(tr);
        tr.querySelector('.remove-row').addEventListener('click', function() {
            if (tbody.querySelectorAll('.price-row').length > 1) tr.remove();
        });
    }
    document.getElementById('add_product_row').addEventListener('click', addProductRow);
    document.getElementById('product_rows').addEventListener('click', function(e) {
        if (e.target.closest('.remove-row') && document.getElementById('product_rows').querySelectorAll('.price-row').length > 1) {
            e.target.closest('tr').remove();
        }
    });

    function getFormPayload() {
        var v = document.querySelector('input[name="customer_type_radio"]:checked');
        var type = v ? v.value : 'local';
        var customerId = null;
        var manualName = '';
        var manualPhone = '';
        if (type === 'local') {
            customerId = document.getElementById('local_customer_id').value ? parseInt(document.getElementById('local_customer_id').value, 10) : null;
        } else if (type === 'rep') {
            customerId = document.getElementById('rep_customer_id').value ? parseInt(document.getElementById('rep_customer_id').value, 10) : null;
        } else {
            manualName = (document.getElementById('manual_customer_name') && document.getElementById('manual_customer_name').value) ? document.getElementById('manual_customer_name').value.trim() : '';
            manualPhone = (document.getElementById('manual_phone') && document.getElementById('manual_phone').value) ? document.getElementById('manual_phone').value.trim() : '';
        }
        var items = [];
        document.querySelectorAll('#product_rows .price-row').forEach(function(row) {
            var sel = row.querySelector('.product-name-select');
            var unitSel = row.querySelector('.unit-select');
            var priceInp = row.querySelector('.price-input');
            var productName = sel ? sel.value.trim() : '';
            var unit = unitSel ? unitSel.value : 'قطعة';
            var price = priceInp && priceInp.value !== '' ? parseFloat(priceInp.value) : 0;
            if (productName) items.push({ product_name: productName, unit: unit, price: price });
        });
        return {
            customer_type: type === 'manual' ? 'manual' : type,
            customer_id: customerId,
            manual_customer_name: manualName,
            manual_phone: manualPhone,
            items: items
        };
    }

    document.getElementById('save_custom_prices_btn').addEventListener('click', function() {
        var payload = getFormPayload();
        if (payload.customer_type === 'manual' && !payload.manual_customer_name) {
            alert('يرجى إدخال اسم العميل الجديد');
            return;
        }
        if ((payload.customer_type === 'local' || payload.customer_type === 'rep') && !payload.customer_id) {
            alert('يرجى اختيار العميل');
            return;
        }
        if (payload.items.length === 0) {
            alert('أضف منتجًا واحدًا على الأقل مع السعر');
            return;
        }
        var msgEl = document.getElementById('save_message');
        msgEl.classList.add('d-none');
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...';
        var formData = new FormData();
        formData.append('customer_type', payload.customer_type);
        if (payload.customer_id) formData.append('customer_id', payload.customer_id);
        if (payload.manual_customer_name) formData.append('manual_customer_name', payload.manual_customer_name);
        if (payload.manual_phone) formData.append('manual_phone', payload.manual_phone);
        payload.items.forEach(function(item, i) {
            formData.append('items[' + i + '][product_name]', item.product_name);
            formData.append('items[' + i + '][unit]', item.unit);
            formData.append('items[' + i + '][price]', item.price);
        });
        fetch(apiUrl('save_custom_prices.php'), { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                msgEl.classList.remove('d-none');
                msgEl.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger') + ' mb-0';
                msgEl.textContent = data.message || (data.error || (data.success ? 'تم الحفظ.' : 'حدث خطأ.'));
                if (data.success) {
                    loadSavedList();
                    document.getElementById('manual_customer_name').value = '';
                    document.getElementById('manual_phone').value = '';
                }
            })
            .catch(function() {
                msgEl.classList.remove('d-none');
                msgEl.className = 'alert alert-danger mb-0';
                msgEl.textContent = 'حدث خطأ في الاتصال.';
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>حفظ الأسعار';
            });
    });

    function loadSavedList() {
        var wrap = document.getElementById('saved_list');
        var loading = document.getElementById('saved_list_loading');
        var empty = document.getElementById('saved_list_empty');
        wrap.classList.add('d-none');
        empty.classList.add('d-none');
        loading.classList.remove('d-none');
        fetch(apiUrl('get_custom_prices_list.php'), { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                loading.classList.add('d-none');
                if (!data.success || !data.list || data.list.length === 0) {
                    empty.classList.remove('d-none');
                    return;
                }
                var html = '';
                data.list.forEach(function(item) {
                    html += '<div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">';
                    html += '<span><strong>' + escapeHtml(item.customer_name) + '</strong> <span class="text-muted small">(' + item.items_count + ' منتج)</span></span>';
                    html += '<button type="button" class="btn btn-sm btn-outline-primary view-prices-btn" data-type="' + escapeHtml(item.customer_type) + '" data-id="' + item.customer_id + '" data-name="' + escapeHtml(item.customer_name) + '"><i class="bi bi-eye me-1"></i>عرض الأسعار</button>';
                    html += '</div>';
                });
                wrap.innerHTML = html;
                wrap.classList.remove('d-none');
                wrap.querySelectorAll('.view-prices-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        showPricesCard(this.getAttribute('data-type'), parseInt(this.getAttribute('data-id'), 10), this.getAttribute('data-name'));
                    });
                });
            })
            .catch(function() {
                loading.classList.add('d-none');
                empty.classList.remove('d-none');
            });
    }

    function showPricesCard(customerType, customerId, customerName) {
        var modal = document.getElementById('viewPricesModal');
        var tbody = document.getElementById('view_prices_tbody');
        var nameEl = document.getElementById('view_prices_customer_name');
        nameEl.textContent = 'العميل: ' + (customerName || '');
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">جاري التحميل...</td></tr>';
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            (new bootstrap.Modal(modal)).show();
        } else {
            modal.classList.add('show');
            modal.style.display = 'block';
        }
        var url = apiUrl('get_custom_prices_by_customer.php') + '?customer_type=' + encodeURIComponent(customerType) + '&customer_id=' + encodeURIComponent(customerId);
        fetch(url, { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success || !data.items || data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">لا توجد أسعار مسجلة.</td></tr>';
                    return;
                }
                var html = '';
                data.items.forEach(function(row) {
                    html += '<tr><td>' + escapeHtml(row.product_name) + '</td><td>' + escapeHtml(row.unit) + '</td><td>' + (row.price != null ? Number(row.price).toLocaleString('ar-EG') : '') + '</td></tr>';
                });
                tbody.innerHTML = html;
            })
            .catch(function() {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">فشل التحميل.</td></tr>';
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadSavedList);
    } else {
        loadSavedList();
    }
})();
</script>

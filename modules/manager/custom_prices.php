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

// قائمة عملاء المندوبين فقط (مرتبطون بمندوب مبيعات - من جدول customers وليس المحليين)
$repCustomers = [];
try {
    $repCustomers = $db->query("
        SELECT c.id, c.name,
               COALESCE(rep1.full_name, rep2.full_name) as rep_name
        FROM customers c
        LEFT JOIN users rep1 ON c.rep_id = rep1.id AND rep1.role = 'sales'
        LEFT JOIN users rep2 ON c.created_by = rep2.id AND rep2.role = 'sales'
        WHERE c.status = 'active'
          AND ((c.rep_id IS NOT NULL AND c.rep_id IN (SELECT id FROM users WHERE role = 'sales'))
               OR (c.created_by IS NOT NULL AND c.created_by IN (SELECT id FROM users WHERE role = 'sales')))
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
.custom-prices-page .search-wrap { position: relative; }
.custom-prices-page .search-dropdown { position: absolute; left: 0; right: 0; top: 100%; z-index: 100; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.custom-prices-page .search-dropdown-item { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
.custom-prices-page .search-dropdown-item:hover { background: #f8f9fa; }
.custom-prices-page .search-dropdown-item:last-child { border-bottom: none; }
.view-prices-table { font-size: 0.8rem; }
.view-prices-table th,
.view-prices-table td { padding: 0.25rem 0.4rem; vertical-align: middle; white-space: nowrap; }
.view-prices-table td:first-child { white-space: normal; }
.view-prices-table thead th { font-weight: 600; }
@media (max-width: 768px) {
    .custom-prices-page .customer-type-wrap { flex-direction: column; align-items: stretch; }
    .custom-prices-page .product-row input.form-control { font-size: 0.9rem; }
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
                        <label class="form-label">ابحث عن العميل المحلي</label>
                        <div class="search-wrap">
                            <input type="text" id="local_customer_search" class="form-control" placeholder="اكتب للبحث في قائمة العملاء المحليين..." autocomplete="off">
                            <input type="hidden" id="local_customer_id" value="">
                            <div id="local_customer_dropdown" class="search-dropdown d-none"></div>
                        </div>
                    </div>
                    <div id="customer_select_rep" class="customer-select-block mb-3 d-none">
                        <label class="form-label">ابحث عن عميل المندوب</label>
                        <div class="search-wrap">
                            <input type="text" id="rep_customer_search" class="form-control" placeholder="اكتب للبحث في قائمة عملاء المندوبين..." autocomplete="off">
                            <input type="hidden" id="rep_customer_id" value="">
                            <div id="rep_customer_dropdown" class="search-dropdown d-none"></div>
                        </div>
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

        <div class="col-lg-6 mb-4" id="custom_prices_products_section">
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
                                        <div class="search-wrap position-relative">
                                            <input type="text" class="form-control form-control-sm product-name-input" placeholder="اكتب للبحث عن المنتج..." autocomplete="off">
                                            <div class="search-dropdown product-dropdown d-none"></div>
                                        </div>
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

    <!-- بطاقة عرض الأسعار المخصصة (داخل الصفحة وليست مودال) -->
    <div class="row mt-3 d-none" id="view_prices_card_wrap">
        <div class="col-12">
            <div class="card view-prices-card shadow-sm border rounded-3 overflow-hidden" id="view_prices_card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-tag-fill me-2 text-primary"></i>عرض الأسعار المخصصة</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="view_prices_close_btn" aria-label="إغلاق"><i class="bi bi-x-lg me-1"></i>إغلاق</button>
                </div>
                <div class="card-body py-2">
                    <p id="view_prices_customer_name" class="fw-bold mb-2 text-primary small"></p>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-0">بحث عن منتج</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white py-1"><i class="bi bi-search small"></i></span>
                            <input type="text" id="view_prices_search" class="form-control form-control-sm py-1" placeholder="اكتب اسم المنتج للتصفية..." autocomplete="off">
                        </div>
                    </div>
                    <div class="table-responsive border rounded">
                        <table class="table table-bordered table-hover table-sm view-prices-table mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>المنتج</th>
                                    <th class="text-nowrap">الوحدة</th>
                                    <th class="text-nowrap">السعر</th>
                                </tr>
                            </thead>
                            <tbody id="view_prices_tbody"></tbody>
                        </table>
                    </div>
                    <p id="view_prices_no_results" class="text-muted small mt-1 mb-0 d-none">لا توجد نتائج تطابق البحث.</p>
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
    var localCustomers = <?php echo json_encode(array_map(function($c) { return ['id' => (int)$c['id'], 'name' => $c['name']]; }, $localCustomers)); ?>;
    var repCustomers = <?php echo json_encode(array_map(function($c) { return ['id' => (int)$c['id'], 'name' => $c['name'], 'rep_name' => isset($c['rep_name']) ? $c['rep_name'] : '']; }, $repCustomers)); ?>;
    var productTemplates = [];

    function apiUrl(path) {
        var p = path.indexOf('/') === 0 ? path.slice(1) : path;
        return apiBase.indexOf('/') === 0 ? apiBase + '/' + p : apiBase + '/' + p;
    }
    function escapeHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function matchSearch(text, q) {
        if (!q || !text) return true;
        var t = (text + '').toLowerCase();
        var k = (q + '').trim().toLowerCase();
        return t.indexOf(k) !== -1;
    }

    function setCustomerBlocks() {
        var v = document.querySelector('input[name="customer_type_radio"]:checked');
        var val = v ? v.value : 'local';
        document.getElementById('customer_select_local').classList.toggle('d-none', val !== 'local');
        document.getElementById('customer_select_rep').classList.toggle('d-none', val !== 'rep');
        document.getElementById('customer_manual_block').classList.toggle('d-none', val !== 'manual');
        if (val !== 'local') {
            document.getElementById('local_customer_search').value = '';
            document.getElementById('local_customer_id').value = '';
            document.getElementById('local_customer_dropdown').classList.add('d-none');
        }
        if (val !== 'rep') {
            document.getElementById('rep_customer_search').value = '';
            document.getElementById('rep_customer_id').value = '';
            document.getElementById('rep_customer_dropdown').classList.add('d-none');
        }
    }
    document.querySelectorAll('input[name="customer_type_radio"]').forEach(function(r) {
        r.addEventListener('change', setCustomerBlocks);
    });
    setCustomerBlocks();

    function showCustomerDropdown(inputId, hiddenId, dropdownId, list, getLabel) {
        var input = document.getElementById(inputId);
        var hidden = document.getElementById(hiddenId);
        var drop = document.getElementById(dropdownId);
        if (!input || !hidden || !drop) return;
        var q = (input.value || '').trim();
        var filtered = list.filter(function(c) { return matchSearch(getLabel(c), q); });
        drop.innerHTML = '';
        if (filtered.length === 0) {
            drop.classList.add('d-none');
            return;
        }
        filtered.forEach(function(c) {
            var div = document.createElement('div');
            div.className = 'search-dropdown-item';
            div.textContent = getLabel(c);
            div.dataset.id = c.id;
            div.dataset.name = c.name;
            div.addEventListener('click', function() {
                hidden.value = this.dataset.id;
                input.value = this.dataset.name;
                drop.classList.add('d-none');
            });
            drop.appendChild(div);
        });
        drop.classList.remove('d-none');
    }
    function initCustomerSearch(inputId, hiddenId, dropdownId, list, getLabel) {
        var input = document.getElementById(inputId);
        var drop = document.getElementById(dropdownId);
        if (!input || !drop) return;
        input.addEventListener('input', function() {
            document.getElementById(hiddenId).value = '';
            showCustomerDropdown(inputId, hiddenId, dropdownId, list, getLabel);
        });
        input.addEventListener('focus', function() {
            if ((input.value || '').trim()) showCustomerDropdown(inputId, hiddenId, dropdownId, list, getLabel);
        });
    }
    initCustomerSearch('local_customer_search', 'local_customer_id', 'local_customer_dropdown', localCustomers, function(c) { return c.name; });
    initCustomerSearch('rep_customer_search', 'rep_customer_id', 'rep_customer_dropdown', repCustomers, function(c) { return c.rep_name ? c.name + ' (' + c.rep_name + ')' : c.name; });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-wrap')) {
            document.querySelectorAll('.search-dropdown').forEach(function(d) { d.classList.add('d-none'); });
        }
    });

    function loadTemplates() {
        fetch(apiUrl('get_product_templates.php'))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                productTemplates = (data && data.templates) ? data.templates : [];
            })
            .catch(function() { productTemplates = []; });
    }
    loadTemplates();

    function bindProductSearch(inputEl, dropdownEl) {
        if (!inputEl || !dropdownEl) return;
        inputEl.addEventListener('input', function() {
            var q = (inputEl.value || '').trim();
            var filtered = productTemplates.filter(function(n) { return matchSearch(n, q); });
            dropdownEl.innerHTML = '';
            if (filtered.length === 0) {
                dropdownEl.classList.add('d-none');
                return;
            }
            filtered.forEach(function(name) {
                var div = document.createElement('div');
                div.className = 'search-dropdown-item';
                div.textContent = name;
                div.addEventListener('click', function() {
                    inputEl.value = this.textContent;
                    dropdownEl.classList.add('d-none');
                });
                dropdownEl.appendChild(div);
            });
            dropdownEl.classList.remove('d-none');
        });
        inputEl.addEventListener('focus', function() {
            if ((inputEl.value || '').trim()) inputEl.dispatchEvent(new Event('input'));
        });
    }

    function addProductRow() {
        var tbody = document.getElementById('product_rows');
        var tr = document.createElement('tr');
        tr.className = 'price-row';
        var unitOpts = units.map(function(u) { return '<option value="' + escapeHtml(u) + '">' + escapeHtml(u) + '</option>'; }).join('');
        tr.innerHTML =
            '<td><div class="search-wrap position-relative">' +
            '<input type="text" class="form-control form-control-sm product-name-input" placeholder="اكتب للبحث عن المنتج..." autocomplete="off">' +
            '<div class="search-dropdown product-dropdown d-none"></div></div></td>' +
            '<td><select class="form-select form-select-sm unit-select">' + unitOpts + '</select></td>' +
            '<td><input type="number" class="form-control form-control-sm price-input" placeholder="0" min="0" step="0.01" value=""></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="حذف"><i class="bi bi-trash"></i></button></td>';
        tbody.appendChild(tr);
        bindProductSearch(tr.querySelector('.product-name-input'), tr.querySelector('.product-dropdown'));
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
    bindProductSearch(
        document.querySelector('#product_rows .price-row .product-name-input'),
        document.querySelector('#product_rows .price-row .product-dropdown')
    );

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
            var inp = row.querySelector('.product-name-input');
            var unitSel = row.querySelector('.unit-select');
            var priceInp = row.querySelector('.price-input');
            var productName = inp ? inp.value.trim() : '';
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
                    document.getElementById('local_customer_search').value = '';
                    document.getElementById('local_customer_id').value = '';
                    document.getElementById('rep_customer_search').value = '';
                    document.getElementById('rep_customer_id').value = '';
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
                    html += '<div class="d-flex flex-wrap gap-1">';
                    html += '<button type="button" class="btn btn-sm btn-outline-secondary edit-prices-btn" data-type="' + escapeHtml(item.customer_type) + '" data-id="' + item.customer_id + '" data-name="' + escapeHtml(item.customer_name) + '"><i class="bi bi-pencil me-1"></i>تعديل</button>';
                    html += '<button type="button" class="btn btn-sm btn-outline-primary view-prices-btn" data-type="' + escapeHtml(item.customer_type) + '" data-id="' + item.customer_id + '" data-name="' + escapeHtml(item.customer_name) + '"><i class="bi bi-eye me-1"></i>عرض الأسعار</button>';
                    html += '</div></div>';
                });
                wrap.innerHTML = html;
                wrap.classList.remove('d-none');
                wrap.querySelectorAll('.view-prices-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        showPricesCard(this.getAttribute('data-type'), parseInt(this.getAttribute('data-id'), 10), this.getAttribute('data-name'));
                    });
                });
                wrap.querySelectorAll('.edit-prices-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        loadForEdit(this.getAttribute('data-type'), parseInt(this.getAttribute('data-id'), 10), this.getAttribute('data-name'));
                    });
                });
            })
            .catch(function() {
                loading.classList.add('d-none');
                empty.classList.remove('d-none');
            });
    }

    function filterViewPricesTable() {
        var q = (document.getElementById('view_prices_search').value || '').trim().toLowerCase();
        var tbody = document.getElementById('view_prices_tbody');
        var noResults = document.getElementById('view_prices_no_results');
        if (!tbody) return;
        var rows = tbody.querySelectorAll('tr[data-product-name]');
        var visible = 0;
        rows.forEach(function(tr) {
            var name = (tr.getAttribute('data-product-name') || '').toLowerCase();
            var show = !q || name.indexOf(q) !== -1;
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (noResults) {
            noResults.classList.toggle('d-none', rows.length === 0 || visible > 0);
        }
    }

    function showPricesCard(customerType, customerId, customerName) {
        var wrap = document.getElementById('view_prices_card_wrap');
        var tbody = document.getElementById('view_prices_tbody');
        var nameEl = document.getElementById('view_prices_customer_name');
        var searchInput = document.getElementById('view_prices_search');
        var noResults = document.getElementById('view_prices_no_results');
        if (wrap) wrap.classList.remove('d-none');
        if (searchInput) searchInput.value = '';
        if (noResults) noResults.classList.add('d-none');
        if (nameEl) nameEl.textContent = 'العميل: ' + (customerName || '');
        if (tbody) tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-1">جاري التحميل...</td></tr>';
        if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var url = apiUrl('get_custom_prices_by_customer.php') + '?customer_type=' + encodeURIComponent(customerType) + '&customer_id=' + encodeURIComponent(customerId);
        fetch(url, { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!tbody) return;
                if (!data.success || !data.items || data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-1">لا توجد أسعار مسجلة.</td></tr>';
                    return;
                }
                var html = '';
                data.items.forEach(function(row) {
                    var productName = (row.product_name || '').trim();
                    var nameAttr = escapeHtml(productName).replace(/"/g, '&quot;');
                    html += '<tr data-product-name="' + nameAttr + '"><td>' + escapeHtml(productName) + '</td><td>' + escapeHtml(row.unit) + '</td><td>' + (row.price != null ? Number(row.price).toLocaleString('ar-EG') : '') + '</td></tr>';
                });
                tbody.innerHTML = html;
                filterViewPricesTable();
            })
            .catch(function() {
                if (tbody) tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-1">فشل التحميل.</td></tr>';
            });
    }

    function hideViewPricesCard() {
        var wrap = document.getElementById('view_prices_card_wrap');
        if (wrap) wrap.classList.add('d-none');
    }

    document.getElementById('view_prices_search') && document.getElementById('view_prices_search').addEventListener('input', filterViewPricesTable);
    document.getElementById('view_prices_close_btn') && document.getElementById('view_prices_close_btn').addEventListener('click', hideViewPricesCard);

    function loadForEdit(customerType, customerId, customerName) {
        var radio = document.querySelector('input[name="customer_type_radio"][value="' + customerType + '"]');
        if (radio) radio.checked = true;
        setCustomerBlocks();
        document.getElementById('local_customer_id').value = '';
        document.getElementById('local_customer_search').value = '';
        document.getElementById('rep_customer_id').value = '';
        document.getElementById('rep_customer_search').value = '';
        if (customerType === 'local') {
            document.getElementById('local_customer_id').value = customerId;
            document.getElementById('local_customer_search').value = customerName || '';
        } else if (customerType === 'rep') {
            document.getElementById('rep_customer_id').value = customerId;
            document.getElementById('rep_customer_search').value = customerName || '';
        }
        var url = apiUrl('get_custom_prices_by_customer.php') + '?customer_type=' + encodeURIComponent(customerType) + '&customer_id=' + encodeURIComponent(customerId);
        fetch(url, { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                var tbody = document.getElementById('product_rows');
                tbody.innerHTML = '';
                var items = (data.success && data.items) ? data.items : [];
                if (items.length === 0) {
                    addProductRow();
                    return;
                }
                items.forEach(function(item) {
                    addProductRow();
                    var rows = tbody.querySelectorAll('.price-row');
                    var row = rows[rows.length - 1];
                    if (row) {
                        var nameInp = row.querySelector('.product-name-input');
                        var unitSel = row.querySelector('.unit-select');
                        var priceInp = row.querySelector('.price-input');
                        if (nameInp) nameInp.value = item.product_name || '';
                        if (unitSel) {
                            if ([].slice.call(unitSel.options).some(function(o) { return o.value === (item.unit || 'قطعة'); })) {
                                unitSel.value = item.unit || 'قطعة';
                            } else if (item.unit) {
                                var opt = document.createElement('option');
                                opt.value = item.unit;
                                opt.textContent = item.unit;
                                unitSel.appendChild(opt);
                                unitSel.value = item.unit;
                            }
                        }
                        if (priceInp) priceInp.value = item.price != null ? item.price : '';
                    }
                });
                document.getElementById('save_message').classList.add('d-none');
                var anchor = document.getElementById('custom_prices_products_section');
                if (anchor) anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(function() {
                alert('فشل تحميل البيانات للتعديل.');
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadSavedList);
    } else {
        loadSavedList();
    }
})();
</script>

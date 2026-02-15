/**
 * البحث اللحظي في صفحة العملاء المحليين
 * يعمل مع التحميل العادي و AJAX (لوحة المدير والمحاسب)
 */
(function() {
    'use strict';

    function getApiBase() {
        var p = (window.location.pathname || '').split('/').filter(Boolean);
        if (p.length > 0 && p[p.length - 1].indexOf('.php') !== -1) p.pop();
        if (p.length > 0 && p[p.length - 1] === 'dashboard') p.pop();
        return (p.length > 0 ? '/' + p.join('/') : '');
    }

    function initLocalCustomersSearch() {
        var customerSearchInput = document.getElementById('customerSearch');
        var tableBody = document.getElementById('customersTableBody');
        if (!customerSearchInput || !tableBody) return;
        if (customerSearchInput.getAttribute('data-local-search-inited') === '1') return;
        customerSearchInput.setAttribute('data-local-search-inited', '1');

        var config = window.LOCAL_CUSTOMERS_CONFIG || {};
        var currentRole = config.currentRole || 'manager';
        var localCustomersApiBase = config.apiBase !== undefined && config.apiBase !== '' ? config.apiBase : getApiBase();
        var currentPage = parseInt(config.pageNum, 10) || 1;

        var searchForm = document.getElementById('localCustomersSearchForm');
        var localSearchClearBtn = document.getElementById('localSearchClearBtn');
        var localSearchResetBtn = document.getElementById('localSearchResetBtn');
        var filterStatus = document.getElementById('debtStatusFilter');
        var filterRegion = document.getElementById('regionFilter');
        var balanceFromInput = document.getElementById('balanceFromLocal');
        var balanceToInput = document.getElementById('balanceToLocal');
        var sortBalanceFilter = document.getElementById('sortBalanceFilterLocal');
        var autocompleteDropdown = document.getElementById('autocompleteDropdown');

        var searchTimeout = null;
        var currentAbortController = null;
        var autocompleteTimeout = null;
        var autocompleteAbortController = null;
        var selectedAutocompleteIndex = -1;
        var autocompleteResults = [];

        function escapeHtml(text) {
            if (text == null) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function toggleLocalSearchClearBtn() {
            if (!localSearchClearBtn) return;
            localSearchClearBtn.style.display = (customerSearchInput && customerSearchInput.value.trim()) ? 'inline-block' : 'none';
        }

        function showError(message) {
            var tbody = document.getElementById('customersTableBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">' + escapeHtml(message) + '</td></tr>';
            }
        }

        function getFilterParams() {
            return {
                search: (customerSearchInput && customerSearchInput.value.trim()) || '',
                debt_status: (filterStatus && filterStatus.value) || 'all',
                region_id: (filterRegion && filterRegion.value) || '',
                balance_from: (balanceFromInput && balanceFromInput.value.trim()) || '',
                balance_to: (balanceToInput && balanceToInput.value.trim()) || '',
                sort_balance: (sortBalanceFilter && sortBalanceFilter.value) || ''
            };
        }

        function fetchCustomers(page) {
            currentPage = page || 1;
            var fp = getFilterParams();

            if (currentAbortController) currentAbortController.abort();
            currentAbortController = new AbortController();

            var loadingEl = document.getElementById('customersTableLoading');
            var tableWrapper = document.querySelector('.table-responsive');
            if (loadingEl) loadingEl.style.display = 'block';
            if (tableWrapper) tableWrapper.style.opacity = '0.5';

            var apiUrl = (localCustomersApiBase || '') + '/api/get_local_customers_search.php';
            var params = new URLSearchParams();
            params.append('p', currentPage);
            if (fp.search) params.append('search', fp.search);
            if (fp.debt_status && fp.debt_status !== 'all') params.append('debt_status', fp.debt_status);
            if (fp.region_id) params.append('region_id', fp.region_id);
            if (fp.balance_from) params.append('balance_from', fp.balance_from);
            if (fp.balance_to) params.append('balance_to', fp.balance_to);
            if (fp.sort_balance) params.append('sort_balance', fp.sort_balance);

            fetch(apiUrl + '?' + params.toString(), {
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
                    updateCustomersTable(data.customers, data.pagination);
                    currentPage = data.pagination.current_page;
                } else {
                    showError(data.message || 'حدث خطأ أثناء جلب البيانات');
                }
            })
            .catch(function(error) {
                if (error.name === 'AbortError') return;
                showError('حدث خطأ أثناء الاتصال بالسيرفر');
            })
            .finally(function() {
                currentAbortController = null;
                if (loadingEl) loadingEl.style.display = 'none';
                if (tableWrapper) tableWrapper.style.opacity = '1';
            });
        }

        function updateCustomersTable(customers, pagination) {
            var tbody = document.getElementById('customersTableBody');
            var countEl = document.getElementById('customersCount');
            var paginationEl = document.getElementById('customersPagination');
            if (!tbody) return;

            if (countEl) countEl.textContent = pagination.total_customers;
            tbody.innerHTML = '';

            if (customers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">لا توجد عملاء محليين</td></tr>';
                if (paginationEl) paginationEl.style.display = 'none';
                return;
            }

            customers.forEach(function(customer) {
                var row = document.createElement('tr');
                var phonesHtml = '';
                if (customer.phones && customer.phones.length > 0) {
                    customer.phones.forEach(function(phone) {
                        if (phone && phone.trim()) {
                            phonesHtml += '<a href="tel:' + escapeHtml(phone) + '" class="btn btn-sm btn-outline-primary me-1 mb-1" title="اتصل بـ ' + escapeHtml(phone) + '"><i class="bi bi-telephone-fill"></i> </a>';
                        }
                    });
                } else if (customer.phone) {
                    phonesHtml = '<a href="tel:' + escapeHtml(customer.phone) + '" class="btn btn-sm btn-outline-primary me-1 mb-1" title="اتصل بـ ' + escapeHtml(customer.phone) + '"><i class="bi bi-telephone-fill"></i> </a>';
                }
                if (!phonesHtml) phonesHtml = '-';

                var balanceHtml = '<strong>' + customer.balance_formatted + '</strong>';
                if (customer.balance !== 0) {
                    balanceHtml += ' <span class="badge ' + customer.balance_badge_class + ' ms-1">' + customer.balance_badge_text + '</span>';
                }

                var locationHtml = '<div class="d-flex flex-wrap align-items-center gap-2">';
                locationHtml += '<button type="button" class="btn btn-sm btn-outline-primary location-capture-btn" data-customer-id="' + customer.id + '" data-customer-name="' + escapeHtml(customer.name) + '"><i class="bi bi-geo-alt me-1"></i>تحديد</button>';
                if (customer.has_location) {
                    locationHtml += '<button type="button" class="btn btn-sm btn-outline-info location-view-btn" data-customer-id="' + customer.id + '" data-customer-name="' + escapeHtml(customer.name) + '" data-latitude="' + customer.latitude.toFixed(8) + '" data-longitude="' + customer.longitude.toFixed(8) + '"><i class="bi bi-map me-1"></i>عرض</button>';
                } else {
                    locationHtml += '<span class="badge bg-secondary-subtle text-secondary">غير محدد</span>';
                }
                locationHtml += '</div>';

                var actionsHtml = '<div class="dropdown table-actions-dropdown" data-bs-boundary="viewport">';
                actionsHtml += '<button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear me-1"></i>إجراءات</button>';
                actionsHtml += '<ul class="dropdown-menu dropdown-menu-end">';
                if (['manager', 'developer', 'accountant', 'sales'].indexOf(currentRole) !== -1) {
                    actionsHtml += '<li><button type="button" class="dropdown-item" onclick="showEditLocalCustomerModal(this)" data-customer-id="' + customer.id + '" data-customer-name="' + escapeHtml(customer.name) + '" data-customer-phone="' + escapeHtml(customer.phone || '') + '" data-customer-address="' + escapeHtml(customer.address || '') + '" data-customer-region-id="' + customer.region_id + '" data-customer-balance="' + customer.raw_balance + '"><i class="bi bi-pencil me-2"></i>تعديل</button></li>';
                }
                actionsHtml += '<li><button type="button" class="dropdown-item ' + (customer.balance <= 0 ? 'disabled' : '') + '" onclick="showCollectPaymentModal(this)" data-customer-id="' + customer.id + '" data-customer-name="' + escapeHtml(customer.name) + '" data-customer-balance="' + customer.raw_balance + '" data-customer-balance-formatted="' + escapeHtml(customer.balance_formatted) + '" ' + (customer.balance > 0 ? '' : 'disabled') + '><i class="bi bi-cash-coin me-2"></i>تحصيل</button></li>';
                actionsHtml += '<li><button type="button" class="dropdown-item local-customer-purchase-history-btn" onclick="showLocalCustomerPurchaseHistoryModal(this)" data-customer-id="' + customer.id + '" data-customer-name="' + escapeHtml(customer.name) + '" data-customer-phone="' + escapeHtml(customer.phone || '') + '" data-customer-address="' + escapeHtml(customer.address || '') + '"><i class="bi bi-receipt me-2"></i>سجل المشتريات</button></li>';
                actionsHtml += '<li><hr class="dropdown-divider"></li>';
                actionsHtml += '<li><button type="button" class="dropdown-item" onclick="showPaperInvoiceModal(this)" data-customer-id="' + customer.id + '" data-customer-name="' + escapeHtml(customer.name) + '"><i class="bi bi-receipt-cutoff me-2"></i>فاتورة ورقية</button></li>';
                actionsHtml += '<li><button type="button" class="dropdown-item local-customer-return-btn" onclick="showLocalCustomerReturnModal(this)" data-customer-id="' + customer.id + '" data-customer-name="' + escapeHtml(customer.name) + '" data-customer-phone="' + escapeHtml(customer.phone || '') + '" data-customer-address="' + escapeHtml(customer.address || '') + '"><i class="bi bi-arrow-return-left me-2"></i>مرتجع</button></li>';
                if (currentRole === 'manager') {
                    actionsHtml += '<li><hr class="dropdown-divider"></li>';
                    actionsHtml += '<li><button type="button" class="dropdown-item text-danger" onclick="showDeleteLocalCustomerModal(this)" data-customer-id="' + customer.id + '" data-customer-name="' + escapeHtml(customer.name) + '"><i class="bi bi-trash3 me-2"></i>حذف</button></li>';
                }
                actionsHtml += '</ul></div>';

                var nameCellHtml = '';
                if (customer.balance_updated_at_formatted) {
                    nameCellHtml += '<span class="badge bg-info-subtle text-info mb-1 d-inline-block" style="font-size: 0.7rem;" title="آخر تعديل للرصيد"><i class="bi bi-clock-history me-1"></i>' + escapeHtml(customer.balance_updated_at_formatted) + '</span><br>';
                }
                nameCellHtml += '<strong>' + escapeHtml(customer.name) + '</strong>';

                row.innerHTML =
                    '<td><strong>' + customer.id + '</strong></td>' +
                    '<td>' + nameCellHtml + '</td>' +
                    '<td>' + phonesHtml + '</td>' +
                    '<td>' + balanceHtml + '</td>' +
                    '<td>' + escapeHtml(customer.address || '-') + '</td>' +
                    '<td>' + escapeHtml(customer.region_name || '-') + '</td>' +
                    '<td>' + locationHtml + '</td>' +
                    '<td>' + actionsHtml + '</td>';
                tbody.appendChild(row);
            });

            updatePagination(pagination);
        }

        function updatePagination(pagination) {
            var paginationEl = document.getElementById('customersPagination');
            if (!paginationEl) return;
            if (pagination.total_pages <= 1) {
                paginationEl.style.display = 'none';
                return;
            }
            paginationEl.style.display = 'block';
            var startPage = Math.max(1, pagination.current_page - 2);
            var endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
            var html = '<ul class="pagination justify-content-center">';
            html += '<li class="page-item ' + (pagination.current_page <= 1 ? 'disabled' : '') + '">';
            html += '<a class="page-link" href="#" data-page="' + (pagination.current_page - 1) + '"><i class="bi bi-chevron-right"></i></a></li>';
            if (startPage > 1) {
                html += '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                if (startPage > 2) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            for (var i = startPage; i <= endPage; i++) {
                html += '<li class="page-item ' + (i === pagination.current_page ? 'active' : '') + '">';
                html += '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
            }
            if (endPage < pagination.total_pages) {
                if (endPage < pagination.total_pages - 1) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                html += '<li class="page-item"><a class="page-link" href="#" data-page="' + pagination.total_pages + '">' + pagination.total_pages + '</a></li>';
            }
            html += '<li class="page-item ' + (pagination.current_page >= pagination.total_pages ? 'disabled' : '') + '">';
            html += '<a class="page-link" href="#" data-page="' + (pagination.current_page + 1) + '"><i class="bi bi-chevron-left"></i></a></li>';
            html += '</ul>';
            paginationEl.innerHTML = html;
            paginationEl.querySelectorAll('a.page-link[data-page]').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var page = parseInt(this.getAttribute('data-page'), 10);
                    if (page > 0) fetchCustomers(page);
                });
            });
        }

        window.fetchCustomers = fetchCustomers;

        function showAutocompleteLoading() {
            if (!autocompleteDropdown) return;
            autocompleteDropdown.innerHTML = '<div class="autocomplete-loading"><i class="bi bi-hourglass-split"></i> جاري البحث...</div>';
            autocompleteDropdown.classList.add('show');
            autocompleteDropdown.style.display = 'block';
            if (customerSearchInput) customerSearchInput.setAttribute('aria-expanded', 'true');
        }

        function showAutocompleteNoResults() {
            if (!autocompleteDropdown) return;
            autocompleteDropdown.innerHTML = '<div class="autocomplete-no-results">لا توجد نتائج</div>';
            autocompleteDropdown.classList.add('show');
            autocompleteDropdown.style.display = 'block';
            if (customerSearchInput) customerSearchInput.setAttribute('aria-expanded', 'true');
        }

        function hideAutocomplete() {
            if (!autocompleteDropdown) return;
            autocompleteDropdown.classList.remove('show');
            autocompleteDropdown.innerHTML = '';
            if (customerSearchInput) customerSearchInput.setAttribute('aria-expanded', 'false');
            selectedAutocompleteIndex = -1;
            autocompleteResults = [];
        }

        function removeAutocompleteSelection() {
            if (autocompleteDropdown) {
                autocompleteDropdown.querySelectorAll('.autocomplete-item').forEach(function(item) {
                    item.classList.remove('selected');
                });
            }
        }

        function displayAutocompleteResults(results) {
            if (!autocompleteDropdown) return;
            if (results.length === 0) {
                showAutocompleteNoResults();
                return;
            }
            var html = '';
            results.forEach(function(result, index) {
                var balanceClass = 'zero';
                var balanceText = 'رصيد: ' + result.balance_formatted;
                if (result.balance > 0) { balanceClass = 'positive'; balanceText = 'مدين: ' + result.balance_formatted; }
                else if (result.balance < 0) { balanceClass = 'negative'; balanceText = 'دائن: ' + result.balance_formatted; }
                html += '<div class="autocomplete-item" data-index="' + index + '" data-customer-id="' + result.id + '" role="option" tabindex="0">';
                html += '<div class="autocomplete-item-name">' + escapeHtml(result.name) + '</div>';
                html += '<div class="autocomplete-item-sub">';
                if (result.sub_text) html += '<span>' + escapeHtml(result.sub_text) + '</span>';
                html += '<span class="autocomplete-item-balance ' + balanceClass + '">' + balanceText + '</span></div></div>';
            });
            autocompleteDropdown.innerHTML = html;
            autocompleteDropdown.classList.add('show');
            autocompleteDropdown.style.display = 'block';
            if (customerSearchInput) customerSearchInput.setAttribute('aria-expanded', 'true');
            autocompleteDropdown.querySelectorAll('.autocomplete-item').forEach(function(item, index) {
                item.addEventListener('click', function() { selectAutocompleteResult(results[index]); });
                item.addEventListener('mouseenter', function() {
                    removeAutocompleteSelection();
                    selectedAutocompleteIndex = index;
                    item.classList.add('selected');
                });
            });
            selectedAutocompleteIndex = -1;
        }

        function performAutocompleteSearch(query) {
            if (!autocompleteDropdown || !query || query.length < 1) {
                hideAutocomplete();
                return;
            }
            if (autocompleteAbortController) autocompleteAbortController.abort();
            autocompleteAbortController = new AbortController();
            showAutocompleteLoading();
            var apiUrl = (localCustomersApiBase || '') + '/fs.php';
            fetch(apiUrl + '?q=' + encodeURIComponent(query), {
                method: 'GET',
                credentials: 'include',
                signal: autocompleteAbortController.signal
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.results) {
                    autocompleteResults = data.results;
                    displayAutocompleteResults(data.results);
                } else {
                    showAutocompleteNoResults();
                }
            })
            .catch(function(error) {
                if (error.name !== 'AbortError') hideAutocomplete();
            });
        }

        function selectAutocompleteResult(result) {
            if (!result) return;
            customerSearchInput.value = result.name;
            hideAutocomplete();
            toggleLocalSearchClearBtn();
            if (searchTimeout) clearTimeout(searchTimeout);
            if (currentAbortController) currentAbortController.abort();
            fetchCustomers(1);
        }

        if (searchForm) {
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                fetchCustomers(1);
            });
        }

        customerSearchInput.addEventListener('input', function() {
            var v = this.value;
            if (v === undefined || v === null) { this.value = ''; v = ''; }
            toggleLocalSearchClearBtn();
            if (autocompleteTimeout) clearTimeout(autocompleteTimeout);
            if (autocompleteAbortController) autocompleteAbortController.abort();
            var query = (v && v.trim()) || '';
            if (query.length >= 1) {
                autocompleteTimeout = setTimeout(function() { performAutocompleteSearch(query); }, 200);
            } else {
                hideAutocomplete();
            }
            if (searchTimeout) clearTimeout(searchTimeout);
            if (currentAbortController) currentAbortController.abort();
            searchTimeout = setTimeout(function() { fetchCustomers(1); }, 500);
        });

        customerSearchInput.addEventListener('keydown', function(e) {
            if (autocompleteDropdown && autocompleteDropdown.classList.contains('show')) {
                var items = autocompleteDropdown.querySelectorAll('.autocomplete-item');
                if (e.key === 'ArrowDown' || e.keyCode === 40) {
                    e.preventDefault();
                    removeAutocompleteSelection();
                    selectedAutocompleteIndex = Math.min(selectedAutocompleteIndex + 1, items.length - 1);
                    if (items[selectedAutocompleteIndex]) {
                        items[selectedAutocompleteIndex].classList.add('selected');
                        items[selectedAutocompleteIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                    return;
                }
                if (e.key === 'ArrowUp' || e.keyCode === 38) {
                    e.preventDefault();
                    removeAutocompleteSelection();
                    selectedAutocompleteIndex = Math.max(selectedAutocompleteIndex - 1, -1);
                    if (selectedAutocompleteIndex >= 0 && items[selectedAutocompleteIndex]) {
                        items[selectedAutocompleteIndex].classList.add('selected');
                        items[selectedAutocompleteIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                    return;
                }
                if (e.key === 'Enter' || e.keyCode === 13) {
                    e.preventDefault();
                    if (selectedAutocompleteIndex >= 0 && autocompleteResults[selectedAutocompleteIndex]) {
                        selectAutocompleteResult(autocompleteResults[selectedAutocompleteIndex]);
                    } else {
                        hideAutocomplete();
                        if (searchTimeout) clearTimeout(searchTimeout);
                        if (currentAbortController) currentAbortController.abort();
                        fetchCustomers(1);
                    }
                    return;
                }
                if (e.key === 'Escape' || e.keyCode === 27) {
                    e.preventDefault();
                    hideAutocomplete();
                    return;
                }
            }
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                hideAutocomplete();
                if (searchTimeout) clearTimeout(searchTimeout);
                if (currentAbortController) currentAbortController.abort();
                fetchCustomers(1);
            }
        });

        document.addEventListener('click', function(e) {
            if (autocompleteDropdown && customerSearchInput && !customerSearchInput.contains(e.target) && !autocompleteDropdown.contains(e.target)) {
                hideAutocomplete();
            }
        });

        customerSearchInput.addEventListener('blur', function() {
            setTimeout(function() {
                if (document.activeElement !== customerSearchInput && autocompleteDropdown && !autocompleteDropdown.contains(document.activeElement)) {
                    hideAutocomplete();
                }
            }, 200);
        });

        if (filterStatus) {
            filterStatus.addEventListener('change', function() {
                if (searchTimeout) clearTimeout(searchTimeout);
                if (currentAbortController) currentAbortController.abort();
                fetchCustomers(1);
            });
        }
        if (filterRegion) {
            filterRegion.addEventListener('change', function() {
                if (searchTimeout) clearTimeout(searchTimeout);
                if (currentAbortController) currentAbortController.abort();
                fetchCustomers(1);
            });
        }
        if (balanceFromInput) {
            balanceFromInput.addEventListener('change', function() {
                if (searchTimeout) clearTimeout(searchTimeout);
                if (currentAbortController) currentAbortController.abort();
                fetchCustomers(1);
            });
        }
        if (balanceToInput) {
            balanceToInput.addEventListener('change', function() {
                if (searchTimeout) clearTimeout(searchTimeout);
                if (currentAbortController) currentAbortController.abort();
                fetchCustomers(1);
            });
        }
        if (sortBalanceFilter) {
            sortBalanceFilter.addEventListener('change', function() {
                if (searchTimeout) clearTimeout(searchTimeout);
                if (currentAbortController) currentAbortController.abort();
                fetchCustomers(1);
            });
        }
        if (localSearchClearBtn) {
            localSearchClearBtn.addEventListener('click', function() {
                if (customerSearchInput) { customerSearchInput.value = ''; customerSearchInput.focus(); }
                hideAutocomplete();
                toggleLocalSearchClearBtn();
                if (searchTimeout) clearTimeout(searchTimeout);
                if (autocompleteTimeout) clearTimeout(autocompleteTimeout);
                if (currentAbortController) currentAbortController.abort();
                if (autocompleteAbortController) autocompleteAbortController.abort();
                fetchCustomers(1);
            });
        }
        if (localSearchResetBtn) {
            localSearchResetBtn.addEventListener('click', function() {
                if (customerSearchInput) customerSearchInput.value = '';
                if (filterStatus) filterStatus.value = 'all';
                if (filterRegion) filterRegion.value = '';
                if (balanceFromInput) balanceFromInput.value = '';
                if (balanceToInput) balanceToInput.value = '';
                if (sortBalanceFilter) sortBalanceFilter.value = '';
                toggleLocalSearchClearBtn();
                if (searchTimeout) clearTimeout(searchTimeout);
                if (currentAbortController) currentAbortController.abort();
                fetchCustomers(1);
                var u = new URL(window.location);
                u.searchParams.set('page', 'local_customers');
                u.searchParams.delete('search');
                u.searchParams.delete('p');
                u.searchParams.delete('debt_status');
                u.searchParams.delete('region_id');
                u.searchParams.delete('balance_from');
                u.searchParams.delete('balance_to');
                u.searchParams.delete('sort_balance');
                window.history.replaceState({}, '', u.toString());
            });
        }

        toggleLocalSearchClearBtn();
    }

    window.initLocalCustomersSearch = initLocalCustomersSearch;

    (function observeLocalCustomersPage() {
        var main = document.querySelector('main');
        if (!main) return;
        function tryInit() {
            var el = document.getElementById('customerSearch');
            if (el && el.getAttribute('data-local-search-inited') !== '1' && window.LOCAL_CUSTOMERS_CONFIG) {
                initLocalCustomersSearch();
            }
        }
        var observer = new MutationObserver(function() { tryInit(); });
        observer.observe(main, { childList: true, subtree: true });
        setTimeout(tryInit, 100);
    })();
})();

<?php
/**
 * صفحة تتبع الموقع المباشر للسائقين وخطوط السير
 * للمدير والمحاسب - تصميم مميز مستوحى من الخرائط الورقية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'accountant', 'developer']);

$driverLocationApiPath = (function_exists('getRelativeUrl') ? getRelativeUrl('api/driver_location.php') : '/api/driver_location.php');
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
<style>
#driver-tracking-map {
    height: 480px;
    min-height: 280px;
    border-radius: 12px;
}
@media (max-width: 768px) {
    #driver-tracking-map {
        height: min(400px, 55vh);
        min-height: 240px;
    }
}
#driver-tracking-map.leaflet-container {
    border: 2px solid #8b4513;
    box-shadow: inset 0 0 30px rgba(139,69,19,0.08), 0 4px 20px rgba(0,0,0,0.12);
    background: #f5f0e6;
}
#driver-tracking-map .leaflet-tile-pane { filter: sepia(0.15) contrast(1.05) brightness(0.98); }
#driver-tracking-map.driver-tracking-map-satellite .leaflet-tile-pane { filter: none; }
.driver-tracking-panel {
    background: linear-gradient(145deg, #faf7f0 0%, #f0ebe0 100%);
    border: 1px solid #c4a574;
    border-radius: 10px;
    box-shadow: 0 2px 12px rgba(139,69,19,0.15);
}
.driver-tracking-panel .card-header {
    background: linear-gradient(90deg, #8b4513 0%, #a0522d 100%);
    color: #fff;
    font-weight: 600;
    border-radius: 10px 10px 0 0;
}
.driver-status-active { color: #228b22; font-weight: 600; }
.driver-status-inactive { color: #8b0000; }
.live-marker-large {
    width: 36px !important;
    height: 36px !important;
    margin-left: -18px !important;
    margin-top: -36px !important;
    filter: drop-shadow(0 2px 6px rgba(0,0,0,0.4));
}
.route-marker {
    width: 24px !important;
    height: 24px !important;
    margin-left: -12px !important;
    margin-top: -12px !important;
    background: #8b4513 !important;
    border: 2px solid #fff !important;
    border-radius: 50% !important;
    font-size: 11px !important;
    line-height: 20px !important;
    text-align: center !important;
    color: #fff !important;
    font-weight: bold !important;
}
.driver-route-colors { --route-1: #c41e3a; --route-2: #1e3a8a; --route-3: #047857; --route-4: #7c2d12; }
.driver-tracking-map-loading {
    position: absolute; left: 0; top: 0; right: 0; bottom: 0;
    display: flex; align-items: center; justify-content: center;
    background: #f5f0e6; border-radius: 10px; z-index: 500;
    font-size: 0.95rem; color: #5a4a3a;
}
.driver-tracking-map-loading.hide { display: none !important; }
</style>

<div class="page-header mb-4">
    <h2 class="mb-1"><i class="bi bi-geo-alt-fill me-2"></i>تتبع السائقين المباشر</h2>
    <p class="text-muted mb-0">عرض المواقع المباشرة وخطوط السير اليومية والتاريخية للسائقين</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card driver-tracking-panel shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center py-2 gap-2">
                <span><i class="bi bi-map me-2"></i>الخريطة</span>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-light active" id="btn-map-type-street" data-type="street" title="خريطة طرق">طرق</button>
                        <button type="button" class="btn btn-outline-light" id="btn-map-type-satellite" data-type="satellite" title="قمر صناعي">قمر صناعي</button>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-light active" id="btn-live-view" data-view="live">مباشر</button>
                        <button type="button" class="btn btn-outline-light" id="btn-route-view" data-view="route">خط السير</button>
                    </div>
                </div>
            </div>
            <div class="card-body p-2">
                <div id="route-controls" class="mb-2 p-2 bg-white rounded d-none">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small mb-0">السائق</label>
                            <select class="form-select form-select-sm" id="route-driver-select"></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-0">التاريخ</label>
                            <input type="date" class="form-control form-control-sm" id="route-date-input">
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btn-load-route">
                                <i class="bi bi-arrow-right-circle me-1"></i>تحميل الخط
                            </button>
                        </div>
                    </div>
                </div>
                <div id="driver-tracking-map" class="position-relative">
                    <div id="driver-tracking-map-loading" class="driver-tracking-map-loading">
                        <span class="spinner-border spinner-border-sm text-primary me-2" role="status"></span>
                        جاري تحميل الخريطة...
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card driver-tracking-panel shadow-sm">
            <div class="card-header py-2"><i class="bi bi-table me-2"></i>حالة التتبع</div>
            <div class="card-body p-2 overflow-auto" style="max-height: 520px;">
                <table class="table table-sm table-hover mb-0" id="drivers-status-table">
                    <thead>
                        <tr>
                            <th>السائق</th>
                            <th>الحالة</th>
                            <th>آخر تحديث</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
<script>
(function () {
    'use strict';
    var apiBase = '<?php echo addslashes($driverLocationApiPath); ?>';
    var map = null;
    var liveMarkers = {};
    var routeLayer = null;
    var routeMarkersLayer = null;
    var driverColors = ['#c41e3a', '#1e3a8a', '#047857', '#7c2d12', '#581845', '#0d9488'];
    var colorIndex = 0;

    function getDriverColor(id) {
        var idx = driverColors.indexOf(localStorage.getItem('driver_color_' + id));
        if (idx >= 0) return driverColors[idx];
        var c = driverColors[colorIndex % driverColors.length];
        colorIndex++;
        localStorage.setItem('driver_color_' + id, c);
        return c;
    }

    function runWhenLeafletReady(callback) {
        if (typeof L !== 'undefined') {
            callback();
            return;
        }
        var attempts = 0;
        var maxAttempts = 100;
        function check() {
            if (typeof L !== 'undefined') {
                callback();
                return;
            }
            attempts++;
            if (attempts < maxAttempts) {
                setTimeout(check, 50);
            }
        }
        setTimeout(check, 50);
    }

    var streetLayer = null;
    var satelliteLayer = null;

    function initDriverTrackingMap() {
        var mapEl = document.getElementById('driver-tracking-map');
        if (!mapEl) return;

        // خريطة الطرق (CartoDB)
        streetLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 19
        });
        // قمر صناعي (ESRI World Imagery)
        satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '&copy; <a href="https://www.esri.com/">Esri</a>',
            maxZoom: 19
        });

        map = L.map('driver-tracking-map', { zoomControl: false }).addLayer(streetLayer);
        L.control.zoom({ position: 'topright' }).addTo(map);
        map.setView([30.0444, 31.2357], 10);

        setTimeout(function () {
            if (map) try { map.invalidateSize(); } catch (e) {}
        }, 300);

        var loadingEl = document.getElementById('driver-tracking-map-loading');
        if (loadingEl) loadingEl.classList.add('hide');

    function createLiveIcon(color) {
        return L.divIcon({
            className: 'live-marker-large',
            html: '<div style="width:100%;height:100%;background:' + color + ';clip-path:polygon(50% 0%,100% 100%,0% 100%);border-radius:0 0 50% 50%;"></div>',
            iconSize: [36, 36],
            iconAnchor: [18, 36]
        });
    }

    function refreshLiveLocations() {
        fetch(apiBase + '?action=live', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.locations) return;
                var bounds = [];
                data.locations.forEach(function (loc) {
                    var id = loc.user_id;
                    var lat = parseFloat(loc.latitude);
                    var lng = parseFloat(loc.longitude);
                    if (isNaN(lat) || isNaN(lng)) return;
                    bounds.push([lat, lng]);
                    var color = getDriverColor(id);
                    if (liveMarkers[id]) {
                        liveMarkers[id].setLatLng([lat, lng]);
                    } else {
                        var m = L.marker([lat, lng], { icon: createLiveIcon(color) })
                            .bindTooltip((loc.full_name || loc.username || 'سائق') + ' - مباشر', { permanent: false, direction: 'top' });
                        liveMarkers[id] = m.addTo(map);
                    }
                });
                var ids = data.locations.map(function (l) { return l.user_id; });
                Object.keys(liveMarkers).forEach(function (id) {
                    if (ids.indexOf(parseInt(id, 10)) < 0) {
                        map.removeLayer(liveMarkers[id]);
                        delete liveMarkers[id];
                    }
                });
                if (bounds.length > 0) {
                    try { map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 }); } catch (e) {}
                }
            })
            .catch(function () {});
    }

    function refreshStatusTable() {
        fetch(apiBase + '?action=status', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    try {
                        var data = JSON.parse(text || '{}');
                        if (!r.ok) data.success = false;
                        return data;
                    } catch (e) {
                        return { success: false, message: r.ok ? 'استجابة غير صالحة' : 'خطأ ' + r.status };
                    }
                });
            })
            .then(function (data) {
                var tbody = document.querySelector('#drivers-status-table tbody');
                if (!tbody) return;
                tbody.innerHTML = '';
                if (!data || !data.success) {
                    var msg = (data && data.message) ? data.message : 'فشل تحميل البيانات';
                    tbody.innerHTML = '<tr><td colspan="3" class="text-muted text-center py-3">' + escapeHtml(msg) + '</td></tr>';
                    return;
                }
                var drivers = data.drivers || [];
                if (drivers.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-muted text-center py-3">لا يوجد سائقين مسجلين في النظام</td></tr>';
                    return;
                }
                drivers.forEach(function (d) {
                    var active = d.location_active == 1;
                    var name = (d.full_name || d.username || 'سائق').trim() || '-';
                    var updated = d.updated_at ? new Date(d.updated_at.replace(' ', 'T')).toLocaleString('ar-EG') : '-';
                    var row = '<tr><td>' + escapeHtml(name) + '</td><td>';
                    if (active) {
                        row += '<span class="driver-status-active"><i class="bi bi-broadcast me-1"></i>يعمل</span>';
                    } else {
                        row += '<span class="driver-status-inactive"><i class="bi bi-broadcast-pin me-1"></i>لا يعمل</span>';
                    }
                    row += '</td><td class="small text-muted">' + escapeHtml(String(updated)) + '</td></tr>';
                    tbody.insertAdjacentHTML('beforeend', row);
                });
            })
            .catch(function () {
                var tbody = document.querySelector('#drivers-status-table tbody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="3" class="text-danger text-center py-3">فشل الاتصال بالخادم - تحقق من مسار API</td></tr>';
            });
    }

    function escapeHtml(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function loadRoute() {
        var driverId = document.getElementById('route-driver-select').value;
        var date = document.getElementById('route-date-input').value;
        if (!driverId || !date) return;
        fetch(apiBase + '?action=route&driver_id=' + encodeURIComponent(driverId) + '&date=' + encodeURIComponent(date), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.route) return;
                if (routeLayer) map.removeLayer(routeLayer);
                if (routeMarkersLayer) map.removeLayer(routeMarkersLayer);
                var pts = data.route.map(function (p) { return [parseFloat(p.latitude), parseFloat(p.longitude)]; }).filter(function (p) { return !isNaN(p[0]) && !isNaN(p[1]); });
                if (pts.length === 0) return;
                var color = getDriverColor(data.route[0] ? parseInt(driverId, 10) : 0);
                routeLayer = L.polyline(pts, { color: color, weight: 5, opacity: 0.9 }).addTo(map);
                routeMarkersLayer = L.layerGroup();
                pts.forEach(function (p, i) {
                    var num = i + 1;
                    var icon = L.divIcon({
                        className: 'route-marker',
                        html: '<span>' + num + '</span>',
                        iconSize: [24, 24],
                        iconAnchor: [12, 12]
                    });
                    L.marker(p, { icon: icon }).addTo(routeMarkersLayer);
                });
                routeMarkersLayer.addTo(map);
                map.fitBounds(pts, { padding: [30, 30], maxZoom: 14 });
            })
            .catch(function () {});
    }

    function populateDriverSelect() {
        fetch(apiBase + '?action=status', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.drivers) return;
                var sel = document.getElementById('route-driver-select');
                sel.innerHTML = '<option value="">اختر السائق</option>';
                data.drivers.forEach(function (d) {
                    sel.innerHTML += '<option value="' + d.id + '">' + escapeHtml((d.full_name || d.username || 'سائق').trim()) + '</option>';
                });
            })
            .catch(function () {});
    }

    var routeDateInput = document.getElementById('route-date-input');
    if (routeDateInput) routeDateInput.value = new Date().toISOString().slice(0, 10);

    function setMapType(type) {
        if (!map || !streetLayer || !satelliteLayer) return;
        var container = map.getContainer();
        if (type === 'satellite') {
            map.removeLayer(streetLayer);
            map.addLayer(satelliteLayer);
            if (container) container.classList.add('driver-tracking-map-satellite');
        } else {
            map.removeLayer(satelliteLayer);
            map.addLayer(streetLayer);
            if (container) container.classList.remove('driver-tracking-map-satellite');
        }
        var btnStreet = document.getElementById('btn-map-type-street');
        var btnSat = document.getElementById('btn-map-type-satellite');
        if (btnStreet) btnStreet.classList.toggle('active', type === 'street');
        if (btnSat) btnSat.classList.toggle('active', type === 'satellite');
    }

    var btnMapStreet = document.getElementById('btn-map-type-street');
    if (btnMapStreet) btnMapStreet.addEventListener('click', function () { setMapType('street'); });
    var btnMapSatellite = document.getElementById('btn-map-type-satellite');
    if (btnMapSatellite) btnMapSatellite.addEventListener('click', function () { setMapType('satellite'); });

    var btnLiveView = document.getElementById('btn-live-view');
    if (btnLiveView) btnLiveView.addEventListener('click', function () {
        document.getElementById('btn-live-view').classList.add('active');
        document.getElementById('btn-route-view').classList.remove('active');
        document.getElementById('route-controls').classList.add('d-none');
        Object.keys(liveMarkers).forEach(function (id) { map.addLayer(liveMarkers[id]); });
        if (routeLayer) map.removeLayer(routeLayer);
        if (routeMarkersLayer) map.removeLayer(routeMarkersLayer);
        refreshLiveLocations();
    });

    var btnRouteView = document.getElementById('btn-route-view');
    if (btnRouteView) btnRouteView.addEventListener('click', function () {
        document.getElementById('btn-route-view').classList.add('active');
        document.getElementById('btn-live-view').classList.remove('active');
        document.getElementById('route-controls').classList.remove('d-none');
        Object.keys(liveMarkers).forEach(function (id) { map.removeLayer(liveMarkers[id]); });
        populateDriverSelect();
    });

    var btnLoadRoute = document.getElementById('btn-load-route');
    if (btnLoadRoute) btnLoadRoute.addEventListener('click', loadRoute);

    refreshLiveLocations();
    refreshStatusTable();
    setInterval(refreshLiveLocations, 15000);
    setInterval(refreshStatusTable, 15000);
    }

    runWhenLeafletReady(initDriverTrackingMap);
})();
</script>

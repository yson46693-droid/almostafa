/**
 * تتبع الموقع المباشر للسائق - يعمل في الواجهة وفي الخلفية
 * يستخدم watchPosition للاستمرار حتى عند تصغير التبويب
 */
(function () {
    'use strict';

    var API_URL = window.DRIVER_LOCATION_API_URL || '';
    var INTERVAL_VISIBLE = 15000;   // 15 ثانية عند ظهور الصفحة
    var INTERVAL_HIDDEN = 25000;    // 25 ثانية عند وجود الصفحة في الخلفية
    var watchId = null;
    var lastLat = null, lastLng = null;
    var sendTimer = null;
    var isPageVisible = true;

    if (!API_URL || !navigator.geolocation) return;

    function isDocumentVisible() {
        return !(document.hidden || document.visibilityState === 'hidden');
    }

    function getInterval() {
        return isPageVisible ? INTERVAL_VISIBLE : INTERVAL_HIDDEN;
    }

    function sendLocation(lat, lng) {
        if (lat === lastLat && lng === lastLng && lastLat !== null) return;
        lastLat = lat;
        lastLng = lng;

        fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ latitude: lat, longitude: lng }),
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    if (window.console && window.console.debug) {
                        window.console.debug('[DriverLocation] Updated:', new Date().toLocaleTimeString());
                    }
                }
            })
            .catch(function () {});
    }

    function scheduleSend(position) {
        if (sendTimer) clearTimeout(sendTimer);
        var lat = position.coords.latitude;
        var lng = position.coords.longitude;
        sendLocation(lat, lng);
        sendTimer = setTimeout(function () {
            sendLocation(lat, lng);
            scheduleSend(position);
        }, getInterval());
    }

    function onPosition(position) {
        scheduleSend(position);
    }

    function onError(err) {
        if (sendTimer) {
            clearTimeout(sendTimer);
            sendTimer = null;
        }
        if (window.console && window.console.warn) {
            window.console.warn('[DriverLocation] Geolocation error:', err.code, err.message);
        }
    }

    function startTracking() {
        if (watchId !== null) return;
        watchId = navigator.geolocation.watchPosition(
            onPosition,
            onError,
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 5000
            }
        );
    }

    function stopTracking() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
        if (sendTimer) {
            clearTimeout(sendTimer);
            sendTimer = null;
        }
    }

    function onVisibilityChange() {
        isPageVisible = isDocumentVisible();
    }

    document.addEventListener('visibilitychange', onVisibilityChange);
    isPageVisible = isDocumentVisible();

    startTracking();

    window.DriverLocationTracker = {
        start: startTracking,
        stop: stopTracking
    };
})();

'use strict';

const PRECACHE_VERSION = 'v1';
const PRECACHE_NAME = `customers-pwa-precache-${PRECACHE_VERSION}`;
const RUNTIME_CACHE_NAME = 'customers-pwa-runtime';

// تحديد base path من موقع service worker
const getPwaBase = () => {
  const swPath = self.location.pathname;
  // استخراج المسار حتى مجلد cus
  const swDir = swPath.substring(0, swPath.lastIndexOf('/'));
  return swDir || '/cus';
};

const PWA_BASE = getPwaBase();

const PRECACHE_ASSETS = [
  PWA_BASE + '/index.php',
  PWA_BASE + '/manifest.php',
  PWA_BASE + '/cus.png'
];

const CDN_ASSETS = [
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  '/assets/bootstrap-icons/bootstrap-icons.css',
  'https://code.jquery.com/jquery-3.7.0.min.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
  'https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(PRECACHE_NAME).then((cache) =>
      Promise.allSettled(PRECACHE_ASSETS.map((asset) => {
        try {
          return cache.add(asset).catch(err => {
            console.warn('Failed to cache asset:', asset, err);
            return null;
          });
        } catch (err) {
          console.warn('Error caching asset:', asset, err);
          return null;
        }
      }))
    )
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== PRECACHE_NAME && key !== RUNTIME_CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  // معالجة طلبات API
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(request).catch(() =>
        new Response(
          JSON.stringify({ success: false, message: 'لا يوجد اتصال بالشبكة.' }),
          {
            status: 503,
            headers: { 'Content-Type': 'application/json; charset=utf-8' }
          }
        )
      )
    );
    return;
  }

  // معالجة الموارد المسبقة التخزين
  const isPrecacheAsset = PRECACHE_ASSETS.some(asset => {
    const assetPath = asset.startsWith('/') ? asset : '/' + asset;
    return url.pathname === asset || url.pathname === assetPath || url.href.includes(asset);
  });
  
  if (isPrecacheAsset) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) {
          return cached;
        }
        return fetch(request).catch(() => {
          // إذا فشل التحميل، حاول البحث في الكاش بمسارات مختلفة
          return caches.match(request.url) || caches.match(url.pathname);
        });
      })
    );
    return;
  }

  // معالجة موارد CDN
  if (CDN_ASSETS.includes(url.href)) {
    event.respondWith(
      caches.open(PRECACHE_NAME).then((cache) =>
        cache.match(request).then((cached) => {
          const networkFetch = fetch(request)
            .then((response) => {
              if (response.status === 200) {
                cache.put(request, response.clone());
              }
              return response;
            })
            .catch(() => cached);
          return cached || networkFetch;
        })
      )
    );
    return;
  }

  // معالجة الملفات الثابتة (JS, CSS, Fonts)
  if (request.destination === 'script' || request.destination === 'style' || request.destination === 'font') {
    event.respondWith(
      caches.open(RUNTIME_CACHE_NAME).then((cache) =>
        cache.match(request).then((cached) => {
          const networkFetch = fetch(request).then((response) => {
            if (response.status === 200) {
              cache.put(request, response.clone());
            }
            return response;
          }).catch(() => cached);
          return cached || networkFetch;
        })
      )
    );
    return;
  }

  // معالجة الصور
  if (request.destination === 'image') {
    event.respondWith(
      caches.open(RUNTIME_CACHE_NAME).then((cache) =>
        cache.match(request).then((cached) => {
          if (cached) {
            return cached;
          }
          return fetch(request)
            .then((response) => {
              if (response.status === 200) {
                cache.put(request, response.clone());
              }
              return response;
            })
            .catch(() => {
              // محاولة العثور على أي صورة في الكاش كبديل
              return caches.match(PWA_BASE + '/cus.png') || 
                     caches.match('/cus/cus.png') ||
                     caches.match('cus.png');
            });
        })
      )
    );
    return;
  }

  // معالجة طلبات HTML - استخدام cache-first لتسريع التحميل
  const acceptsHTML = request.headers.get('accept')?.includes('text/html');

  if (acceptsHTML) {
    event.respondWith(
      caches.open(RUNTIME_CACHE_NAME).then((cache) => {
        // محاولة استخدام cache أولاً لتسريع التحميل
        return cache.match(request).then((cached) => {
          if (cached) {
            // تحديث cache في الخلفية بدون انتظار
            fetch(request)
              .then((networkResponse) => {
                if (networkResponse.status === 200) {
                  cache.put(request, networkResponse.clone());
                }
              })
              .catch(() => {
                // تجاهل الأخطاء في التحديث
              });
            return cached;
          }
          
          // إذا لم يكن في cache، جلب من network
          return fetch(request)
            .then((networkResponse) => {
              if (networkResponse.status === 200) {
                const responseClone = networkResponse.clone();
                cache.put(request, responseClone);
              }
              return networkResponse;
            })
            .catch(() => {
              // محاولة العثور على أي نسخة في cache
              return caches.match(request)
                .then((cached) => cached || caches.match(PWA_BASE + '/index.php'))
                .then((cached) => cached || caches.match('/cus/index.php'))
                .then((cached) => cached || caches.match('index.php'));
            });
        });
      })
    );
    return;
  }

  // معالجة الطلبات الأخرى
  event.respondWith(
    fetch(request)
      .then((networkResponse) => {
        if (networkResponse.status === 200) {
          const responseClone = networkResponse.clone();
          caches.open(RUNTIME_CACHE_NAME).then((cache) => cache.put(request, responseClone));
        }
        return networkResponse;
      })
      .catch(() => caches.match(request))
  );
});

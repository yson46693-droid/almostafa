'use strict';

// ============================================
// Configuration
// ============================================
const CACHE_VERSION = 'v2.2.0'; // تحديث الإصدار لإضافة الكاش المحسّن
const PRECACHE_NAME = `albarakah-precache-${CACHE_VERSION}`;
const STATIC_CACHE_NAME = `albarakah-static-${CACHE_VERSION}`;
const CDN_CACHE_NAME = `albarakah-cdn-${CACHE_VERSION}`;

// Maximum cache size: 50MB (approximate)
const MAX_CACHE_SIZE = 50 * 1024 * 1024;

// Assets to precache during install - جميع الأيقونات والملفات الأساسية 
const PRECACHE_ASSETS = [
  '/offline.html',
  // جميع الأيقونات الأساسية للكاش المسبق
  '/assets/icons/icon-72x72.png',
  '/assets/icons/icon-96x96.png',
  '/assets/icons/icon-128x128.png',
  '/assets/icons/icon-144x144.png',
  '/assets/icons/icon-152x152.png',
  '/assets/icons/icon-192x192.png',
  '/assets/icons/icon-384x384.png',
  '/assets/icons/icon-512x512.png',
  '/assets/icons/apple-touch-icon.svg',
  // ملفات CSS الأساسية
  '/assets/css/style.css',
  '/assets/css/rtl.css',
  '/assets/css/homeline-dashboard.css',
  '/assets/css/topbar.css',
  '/assets/css/responsive.css',
  '/assets/css/sidebar.css',
  // ملفات JS الأساسية
  '/assets/js/main.js',
  '/assets/js/local-storage-cache.js',
  '/assets/js/lazy-loading.js'
];

// CDN assets to cache
const CDN_ASSETS = [
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  '/assets/bootstrap-icons/bootstrap-icons.css',
  'https://code.jquery.com/jquery-3.7.0.min.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
  'https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap'
];

// Critical static assets to cache (CSS, JS, Fonts) - ملفات حرجة للكاش
const CRITICAL_ASSETS = [
  // CSS files - جميع ملفات CSS الأساسية
  '/assets/css/style.css',
  '/assets/css/rtl.css',
  '/assets/css/homeline-dashboard.css',
  '/assets/css/topbar.css',
  '/assets/css/responsive.css',
  '/assets/css/sidebar.css',
  '/assets/css/cards.css',
  '/assets/css/tables.css',
  '/assets/css/modal-mobile-fix.css',
  '/assets/css/dark-mode.css',
  // JS files - جميع ملفات JS الأساسية
  '/assets/js/main.js',
  '/assets/js/local-storage-cache.js',
  '/assets/js/lazy-loading.js',
  '/assets/js/ajax-navigation.js',
  '/assets/js/auto-refresh-navigation.js',
  '/assets/js/notifications.js',
  '/assets/js/sidebar.js',
  '/assets/js/modal-mobile-fix.js',
  // Fonts - الخطوط الأساسية
  'https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap',
  '/assets/bootstrap-icons/fonts/bootstrap-icons.woff2'
];

// Network timeout - محسّن لـ PWA
// تقليل timeout للصفحات PHP لتسريع الاستجابة في PWA
const NETWORK_TIMEOUT = 8000; // 8 ثواني للصفحات PHP (كان 15 ثانية)
const STATIC_NETWORK_TIMEOUT = 5000; // 5 ثواني للموارد الثابتة (كان 10 ثواني)

// ============================================
// Helper Functions
// ============================================

/**
 * Check if error is a real network error
 */
function isNetworkError(error) {
  return (
    error.name === 'TypeError' ||
    error.name === 'NetworkError' ||
    error.message === 'Failed to fetch' ||
    error.message === 'Request timeout' ||
    error.message?.includes('network') ||
    error.message?.includes('fetch') ||
    error.message?.includes('timeout')
  );
}

/**
 * Get offline page response
 */
async function getOfflinePage() {
    // Only try cache if CacheStorage is available
    if ('caches' in self) {
      try {
        const cache = await caches.open(PRECACHE_NAME);
        const offlinePage = await cache.match('/offline.html');
        if (offlinePage) {
          return offlinePage;
        }
      } catch (error) {
        // Silently fail - will use fallback
        // Don't log any errors to console
      }
    }
  
  // Fallback offline response
  return new Response(
    '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لا يوجد اتصال</title><style>body{font-family:Arial;text-align:center;padding:50px;background:#f5f5f5}h1{color:#333}</style></head><body><h1>لا يوجد اتصال بالشبكة</h1><p>يرجى التحقق من اتصالك بالإنترنت</p></body></html>',
    {
      status: 503,
      headers: { 'Content-Type': 'text/html; charset=utf-8' }
    }
  );
}

/**
 * Get JSON error response for API requests
 */
function getApiErrorResponse() {
  return new Response(
    JSON.stringify({ success: false, message: 'لا يوجد اتصال بالشبكة.' }),
    {
      status: 503,
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    }
  );
}

/**
 * Network request with timeout
 */
function fetchWithTimeout(request, timeout = NETWORK_TIMEOUT) {
  // استخدام timeout أطول لصفحات PHP
  const url = new URL(request.url);
  const isPhpPage = url.pathname.endsWith('.php');
  const actualTimeout = isPhpPage ? NETWORK_TIMEOUT : STATIC_NETWORK_TIMEOUT;
  
  return Promise.race([
    fetch(request, {
      cache: 'no-store',
      credentials: 'same-origin'
    }),
    new Promise((_, reject) =>
      setTimeout(() => reject(new Error('Request timeout')), actualTimeout)
    )
  ]);
}

/**
 * Cache First strategy - try cache, then network
 */
async function cacheFirst(request, cacheName) {
  // Check if CacheStorage is available
  if (!('caches' in self)) {
    // CacheStorage not available, fetch from network only
    return await fetch(request);
  }

  try {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    
    if (cached) {
      return cached;
    }
    
    // Not in cache, fetch from network
    const response = await fetch(request);
    
    // Only cache successful responses
    if (response.status === 200 && response.ok) {
      const responseClone = response.clone();
        cache.put(request, responseClone).catch(() => {
          // Silently fail caching - don't block response
          // Don't log to console
        });
    }
    
    return response;
  } catch (error) {
    // If cache operations fail, fallback to network only
    // Silently handle CacheStorage errors - don't log to console
    // Fallback to network
    try {
      return await fetch(request);
    } catch (networkError) {
      // Silently handle network errors too
      throw networkError;
    }
  }
}

/**
 * Network First strategy - try network, then cache, then offline
 * محسّن لـ PWA: محاولة cache أولاً لأول فتح PWA لتسريع الاستجابة بشكل كبير
 */
async function networkFirst(request, cacheName, isNavigation = false) {
  // لأول فتح PWA، محاولة استخدام cache أولاً لتسريع الاستجابة بشكل كبير
  // هذا يقلل وقت التحميل من دقيقة كاملة إلى ثواني قليلة
  if (isNavigation && 'caches' in self) {
    try {
      // محاولة فتح جميع caches المحتملة
      const cacheNames = [cacheName, 'albarakah-precache-v2.0.0'];
      
      for (const name of cacheNames) {
        try {
          const cache = await caches.open(name);
          const cached = await cache.match(request);
          
          if (cached) {
            // استخدام cache فوراً - هذا يسرع التحميل بشكل كبير
            // تحديث cache في الخلفية بدون انتظار
            fetchWithTimeout(request).then(async (networkResponse) => {
              if (networkResponse.status === 200 && networkResponse.ok) {
                try {
                  const cache = await caches.open(name);
                  const responseClone = networkResponse.clone();
                  await cache.put(request, responseClone);
                } catch (cacheError) {
                  // Silently fail cache update
                }
              }
            }).catch(() => {
              // Silently fail background update
            });
            
            return cached;
          }
        } catch (e) {
          // تجاهل أخطاء cache معين، جرب cache آخر
          continue;
        }
      }
    } catch (cacheError) {
      // Silently fail cache lookup - continue to network
    }
  }
  
  // Network first strategy مع timeout أقصر لتسريع الاستجابة
  try {
    const response = await fetchWithTimeout(request);
    
    // Cache successful responses (only if CacheStorage is available)
    if (response.status === 200 && response.ok && ('caches' in self)) {
      try {
        const cache = await caches.open(cacheName);
        const responseClone = response.clone();
        cache.put(request, responseClone).catch(() => {
          // Silently fail caching - don't block response
          // Don't log to console
        });
      } catch (cacheError) {
        // Cache error shouldn't block response
        // Silently handle all cache errors - don't log to console
      }
    }
    
    return response;
  } catch (error) {
    // Network failed, try cache
    if (isNetworkError(error) && !error.message.includes('timeout')) {
      // فقط للأخطاء الحقيقية في الشبكة (ليس timeout)
      
      // Only try cache if CacheStorage is available
      if ('caches' in self) {
        try {
          // محاولة فتح جميع caches المحتملة
          const cacheNames = [cacheName, 'albarakah-precache-v2.0.0'];
          
          for (const name of cacheNames) {
            try {
              const cache = await caches.open(name);
              const cached = await cache.match(request);
              
              if (cached) {
                return cached;
              }
            } catch (e) {
              // تجاهل أخطاء cache معين، جرب cache آخر
              continue;
            }
          }
        } catch (cacheError) {
          // Silently fail cache lookup
          // Don't log any errors to console
        }
      }
      
      // No cache, return offline page for navigation requests (فقط للأخطاء الحقيقية)
      if (isNavigation) {
        return await getOfflinePage();
      }
    }
    
    // للأخطاء الأخرى (مثل timeout)، نترك البrowser يتعامل معها
    // Re-throw non-network errors or timeout errors
    throw error;
  }
}

/**
 * Network Only strategy - no caching, offline fallback for navigation
 */
async function networkOnly(request, isNavigation = false) {
  try {
    const response = await fetchWithTimeout(request);
    // التحقق من أن الاستجابة صالحة
    if (!response.ok && response.status >= 500) {
      // أخطاء الخادم (500+) - لا نعتبرها network errors
      // نعيد الاستجابة للبrowser للتعامل معها
      return response;
    }
    return response;
  } catch (error) {
    // فقط للأخطاء الحقيقية في الشبكة، نعيد offline page
    // timeout أو أخطاء أخرى نتركها للبrowser
    if (isNetworkError(error) && !error.message.includes('timeout')) {
      if (isNavigation) {
        return await getOfflinePage();
      }
    }
    
    //  للأخطاء الأخرى (مثل timeout)، نترك البrowser يتعامل معها
    // نعيد error للبrowser بدلاً من offline page
    throw error;
  }
}

// ============================================
// Install Event - Precaching
// ============================================
self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      // Check if CacheStorage is available
      if (!('caches' in self)) {
        // Silently skip precaching if CacheStorage is not available
        return;
      }

      try {
        // التحقق من دعم CacheStorage أولاً
        if (!('caches' in self) || typeof caches.open !== 'function') {
          // Silently skip precaching if CacheStorage is not available
          return;
        }
        
        // محاولة فتح الكاش مع معالجة أفضل للأخطاء
        let precache, staticCache;
        try {
          precache = await caches.open(PRECACHE_NAME);
          staticCache = await caches.open(STATIC_CACHE_NAME);
        } catch (openError) {
          // إذا فشل فتح الكاش، تخطي التخزين المؤقت بصمت
          // لا نطبع أي شيء في الكونسول
          return;
        }
        
        // Cache precache assets
        const precachePromises = PRECACHE_ASSETS.map(async (asset) => {
          try {
            await precache.add(asset);
          } catch (error) {
            // تجاهل أخطاء التخزين المؤقت بصمت - لا نطبع أي شيء
          }
        });

        // Cache critical assets
        const criticalPromises = CRITICAL_ASSETS.map(async (asset) => {
          try {
            await staticCache.add(asset);
          } catch (error) {
            // تجاهل أخطاء التخزين المؤقت بصمت - لا نطبع أي شيء
          }
        });

        await Promise.allSettled([...precachePromises, ...criticalPromises]);
      } catch (error) {
        // If CacheStorage fails completely, continue without caching
        // Silently handle errors - don't log to console
        // Don't fail installation - service worker can work without cache
      }
    })()
  );
  
  // Use skipWaiting for faster activation
  self.skipWaiting();
});

// ============================================
// Activate Event - Cleanup Old Caches
// ============================================
self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Check if CacheStorage is available
      if (!('caches' in self)) {
        // Silently skip cache cleanup if CacheStorage is not available
        return;
      }

      try {
        const cacheKeys = await caches.keys();
        const currentCaches = [PRECACHE_NAME, STATIC_CACHE_NAME, CDN_CACHE_NAME];
        
        // Delete old caches
        const deletePromises = cacheKeys
          .filter((key) => !currentCaches.includes(key))
          .map(async (key) => {
            try {
              await caches.delete(key);
            } catch (error) {
              // Don't fail if individual cache deletion fails
              // Silently handle errors - don't log to console
            }
          });

        await Promise.allSettled(deletePromises);
        
        // Claim clients (optional - can be removed if causing issues)
        // await self.clients.claim();
      } catch (error) {
        // If CacheStorage fails completely, continue without cleanup
        // Silently handle errors - don't log to console
        // Don't fail activation - service worker can work without cache
      }
    })()
  );
});

// ============================================
// Fetch Event - Request Handling
// ============================================
/**
 * تنظيف URL وإصلاح أي تكرار في اسم الملف
 */
function normalizeUrl(urlString) {
  try {
    const url = new URL(urlString);
    
    // إصلاح تكرار اسم الملف في pathname (مثل manager.phpmanager.php)
    let pathname = url.pathname;
    
    // البحث عن نمط ملف.php مكرر
    const phpFilePattern = /([a-z_]+\.php)\1+/gi;
    if (phpFilePattern.test(pathname)) {
      // إزالة التكرار
      pathname = pathname.replace(phpFilePattern, '$1');
    }
    
    // إصلاح أي تكرار آخر لـ .php
    pathname = pathname.replace(/([a-z_]+\.php)+/gi, (match) => {
      // إذا كان هناك تكرار، احتفظ بالاسم الأول فقط
      const parts = match.split('.php');
      return parts[0] + '.php';
    });
    
    // تحديث pathname
    url.pathname = pathname;
    
    return url.href;
  } catch (e) {
    // إذا فشل parsing، حاول إصلاح URL يدوياً
    let fixedUrl = urlString;
    // إصلاح تكرار manager.phpmanager.php
    fixedUrl = fixedUrl.replace(/([a-z_]+\.php)\1+/gi, '$1');
    // إصلاح أي تكرار آخر
    fixedUrl = fixedUrl.replace(/([a-z_]+\.php)+/gi, (match) => {
      const parts = match.split('.php');
      return parts[0] + '.php';
    });
    return fixedUrl;
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  
  // تنظيف URL قبل الاستخدام
  const cleanedUrl = normalizeUrl(request.url);
  const url = new URL(cleanedUrl);
  
  // إذا تغير URL، أنشئ request جديد
  if (cleanedUrl !== request.url) {
    event.respondWith(
      fetch(cleanedUrl, {
        method: request.method,
        headers: request.headers,
        body: request.body,
        mode: request.mode,
        credentials: request.credentials,
        cache: request.cache,
        redirect: request.redirect,
        referrer: request.referrer,
        integrity: request.integrity
      })
    );
    return;
  }

  // Only handle GET requests
  if (request.method !== 'GET') {
    return;
  }

  // ============================================
  // Handle CDN Assets (BEFORE cross-origin check)
  // ============================================
  if (CDN_ASSETS.some((cdn) => url.href.includes(cdn))) {
    event.respondWith(cacheFirst(request, CDN_CACHE_NAME));
    return;
  }

  // Skip cross-origin requests (except CDN which we handle above)
  if (url.origin !== location.origin) {
    return;
  }

  // ============================================
  // استثناء index.php من service worker - السماح للبrowser بالتعامل معه مباشرة
  // ============================================
  const isIndexPage = url.pathname === '/index.php' || 
                      url.pathname.endsWith('/index.php') ||
                      (url.pathname === '/' && request.mode === 'navigate');
  
  if (isIndexPage) {
    // لا نعترض index.php - نتركه للبrowser مباشرة
    return;
  }

  // ============================================
  // Handle API Requests - Network Only
  // ============================================
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      networkOnly(request, false)
        .catch((error) => {
          if (isNetworkError(error)) {
            return getApiErrorResponse();
          }
          throw error;
        })
    );
    return;
  }

  // ============================================
  // Skip Service Worker for Camera/Media Requests
  // ============================================
  // Don't intercept camera/media related requests
  // This ensures getUserMedia works correctly
  if (
    url.pathname.includes('attendance') ||
    url.pathname.includes('camera') ||
    request.destination === 'media' ||
    url.searchParams.has('_camera') ||
    url.searchParams.has('_media')
  ) {
    // Let browser handle these requests directly
    return;
  }

  // ============================================
  // Handle PHP Pages - Network First with Cache Fallback (محسّن لـ PWA)
  // ============================================
  if (url.pathname.endsWith('.php')) {
    // استثناء AJAX navigation requests - دع المتصفح يتعامل معها مباشرة
    // هذا يحل مشكلة الشاشة البيضاء عند التنقل بين الصفحات في PWA
    const isAjaxRequest = request.headers.get('X-Requested-With') === 'XMLHttpRequest';
    const acceptsHtml = request.headers.get('Accept')?.includes('text/html');
    
    // إذا كان AJAX request للتنقل (XMLHttpRequest + text/html)، اتركه للمتصفح
    if (isAjaxRequest && acceptsHtml) {
      return; // لا تعترض الطلب - دع المتصفح يتعامل معه مباشرة
    }
    
    const isNavigation = request.mode === 'navigate';
    
    // استخدام Network First مع Cache Fallback لتحسين الأداء في PWA
    // هذا يسمح بتحميل سريع من cache عند أول فتح PWA
    event.respondWith(networkFirst(request, STATIC_CACHE_NAME, isNavigation));
    return;
  }

  // ============================================
  // Handle Static Assets - Cache First
  // ============================================
  const isStaticAsset = 
    request.destination === 'script' ||
    request.destination === 'style' ||
    request.destination === 'font' ||
    request.destination === 'image' ||
    url.pathname.match(/\.(css|js|woff|woff2|ttf|eot|png|jpg|jpeg|gif|svg|ico|webp)$/i);

  // Skip caching for attendance-related JavaScript files to ensure latest version
  const isAttendanceJS = url.pathname.includes('attendance.js') || 
                          url.pathname.includes('attendance_notifications.js');

  if (isStaticAsset) {
    if (isAttendanceJS) {
      // Network only for attendance JS files - no caching to ensure latest version
      event.respondWith(networkOnly(request, false));
    } else {
      event.respondWith(cacheFirst(request, STATIC_CACHE_NAME));
    }
    return;
  }

  // ============================================
  // Handle HTML Pages - Network First
  // ============================================
  const isHTML = request.headers.get('accept')?.includes('text/html');
  
  if (isHTML) {
    const isNavigation = request.mode === 'navigate';
    event.respondWith(networkFirst(request, STATIC_CACHE_NAME, isNavigation));
    return;
  }

  // ============================================
  // Handle Precached Assets
  // ============================================
  const isPrecached = PRECACHE_ASSETS.some((asset) => {
    const assetUrl = new URL(asset, location.origin);
    return assetUrl.pathname === url.pathname;
  });

  if (isPrecached) {
    event.respondWith(cacheFirst(request, PRECACHE_NAME));
    return;
  }

  // ============================================
  // Default: Network First for unknown requests
  // ============================================
  event.respondWith(networkFirst(request, STATIC_CACHE_NAME, false));
});

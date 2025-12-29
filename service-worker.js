'use strict';

// ============================================
// Configuration
// ============================================
const CACHE_VERSION = 'v2.0.0';
const PRECACHE_NAME = `albarakah-precache-${CACHE_VERSION}`;
const STATIC_CACHE_NAME = `albarakah-static-${CACHE_VERSION}`;
const CDN_CACHE_NAME = `albarakah-cdn-${CACHE_VERSION}`;

// Maximum cache size: 50MB (approximate)
const MAX_CACHE_SIZE = 50 * 1024 * 1024;

// Assets to precache during install
const PRECACHE_ASSETS = [
  '/offline.html',
  '/assets/icons/icon-192x192.png',
  '/assets/icons/icon-512x512.png'
];

// CDN assets to cache
const CDN_ASSETS = [
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
  'https://code.jquery.com/jquery-3.7.0.min.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'
];

// Critical static assets to cache (CSS, JS, Fonts)
const CRITICAL_ASSETS = [
  // CSS files
  '/assets/css/homeline-dashboard.css',
  '/assets/css/topbar.css',
  '/assets/css/responsive.css',
  '/assets/css/sidebar.css',
  '/assets/css/cards.css',
  '/assets/css/tables.css',
  // JS files
  '/assets/js/main.js',
  '/assets/js/ajax-navigation.js',
  '/assets/js/notifications.js',
  // Fonts
  'https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/fonts/bootstrap-icons.woff2'
];

// Network timeout (10 seconds)
const NETWORK_TIMEOUT = 10000;

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
      if (!error.message?.includes('CacheStorage') && !error.message?.includes('open')) {
        console.warn('[SW] Failed to get offline page from cache:', error.message);
      }
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
  return Promise.race([
    fetch(request, {
      cache: 'no-store',
      credentials: 'same-origin'
    }),
    new Promise((_, reject) =>
      setTimeout(() => reject(new Error('Request timeout')), timeout)
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
      cache.put(request, responseClone).catch((error) => {
        // Silently fail caching - don't block response
        console.warn(`[SW] Failed to cache ${request.url}:`, error.message);
      });
    }
    
    return response;
  } catch (error) {
    // If cache operations fail, fallback to network only
    // Don't log as error if it's a known CacheStorage issue
    if (error.message?.includes('CacheStorage') || error.message?.includes('open')) {
      console.warn(`[SW] CacheStorage unavailable for ${request.url}, using network only`);
    } else {
      console.error(`[SW] Cache First error for ${request.url}:`, error);
    }
    
    // Fallback to network
    try {
      return await fetch(request);
    } catch (networkError) {
      console.error(`[SW] Network fallback failed for ${request.url}:`, networkError);
      throw networkError;
    }
  }
}

/**
 * Network First strategy - try network, then cache, then offline
 */
async function networkFirst(request, cacheName, isNavigation = false) {
  try {
    const response = await fetchWithTimeout(request);
    
    // Cache successful responses (only if CacheStorage is available)
    if (response.status === 200 && response.ok && ('caches' in self)) {
      try {
        const cache = await caches.open(cacheName);
        const responseClone = response.clone();
        cache.put(request, responseClone).catch((error) => {
          // Silently fail caching - don't block response
          console.warn(`[SW] Failed to cache ${request.url}:`, error.message);
        });
      } catch (cacheError) {
        // Cache error shouldn't block response
        if (cacheError.message?.includes('CacheStorage') || cacheError.message?.includes('open')) {
          console.warn(`[SW] CacheStorage unavailable, skipping cache for ${request.url}`);
        } else {
          console.warn(`[SW] Cache error for ${request.url}:`, cacheError.message);
        }
      }
    }
    
    return response;
  } catch (error) {
    // Network failed, try cache
    if (isNetworkError(error)) {
      console.error(`[SW] Network error for ${request.url}:`, error.message);
      
      // Only try cache if CacheStorage is available
      if ('caches' in self) {
        try {
          const cache = await caches.open(cacheName);
          const cached = await cache.match(request);
          
          if (cached) {
            return cached;
          }
        } catch (cacheError) {
          // Silently fail cache lookup
          if (!cacheError.message?.includes('CacheStorage') && !cacheError.message?.includes('open')) {
            console.warn(`[SW] Cache lookup failed for ${request.url}:`, cacheError.message);
          }
        }
      }
      
      // No cache, return offline page for navigation requests
      if (isNavigation) {
        return await getOfflinePage();
      }
    }
    
    // Re-throw non-network errors
    throw error;
  }
}

/**
 * Network Only strategy - no caching, offline fallback for navigation
 */
async function networkOnly(request, isNavigation = false) {
  try {
    const response = await fetchWithTimeout(request);
    return response;
  } catch (error) {
    if (isNetworkError(error)) {
      console.error(`Network error for ${request.url}:`, error);
      
      // Return offline page for navigation requests
      if (isNavigation) {
        return await getOfflinePage();
      }
    }
    
    // Re-throw non-network errors
    throw error;
  }
}

// ============================================
// Install Event - Precaching
// ============================================
self.addEventListener('install', (event) => {
  console.log(`[SW] Installing Service Worker v${CACHE_VERSION}`);
  
  event.waitUntil(
    (async () => {
      // Check if CacheStorage is available
      if (!('caches' in self)) {
        console.warn('[SW] CacheStorage API not available - skipping precaching');
        return;
      }

      try {
        // التحقق من دعم CacheStorage أولاً
        if (!('caches' in self) || typeof caches.open !== 'function') {
          console.warn('[SW] CacheStorage API not available - skipping precaching');
          return;
        }
        
        // محاولة فتح الكاش مع معالجة أفضل للأخطاء
        let precache, staticCache;
        try {
          precache = await caches.open(PRECACHE_NAME);
          staticCache = await caches.open(STATIC_CACHE_NAME);
        } catch (openError) {
          // إذا فشل فتح الكاش، تخطي التخزين المؤقت بصمت
          // لا نطبع warning لأن هذا قد يكون طبيعياً في بعض الحالات (خصوصية المتصفح، إعدادات الأمان)
          return;
        }
        
        // Cache precache assets
        const precachePromises = PRECACHE_ASSETS.map(async (asset) => {
          try {
            await precache.add(asset);
            console.log(`[SW] Precached: ${asset}`);
          } catch (error) {
            // تجاهل أخطاء التخزين المؤقت بصمت
            if (error.name !== 'TypeError' && error.name !== 'NetworkError') {
              console.warn(`[SW] Failed to precache ${asset}:`, error.message);
            }
          }
        });

        // Cache critical assets
        const criticalPromises = CRITICAL_ASSETS.map(async (asset) => {
          try {
            await staticCache.add(asset);
            console.log(`[SW] Cached critical asset: ${asset}`);
          } catch (error) {
            // تجاهل أخطاء التخزين المؤقت بصمت
            if (error.name !== 'TypeError' && error.name !== 'NetworkError') {
              console.warn(`[SW] Failed to cache critical asset ${asset}:`, error.message);
            }
          }
        });

        await Promise.allSettled([...precachePromises, ...criticalPromises]);
        console.log('[SW] Precaching completed');
      } catch (error) {
        // If CacheStorage fails completely, continue without caching
        console.warn('[SW] Cache operation failed, continuing without cache:', error.message);
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
  console.log(`[SW] Activating Service Worker v${CACHE_VERSION}`);
  
  event.waitUntil(
    (async () => {
      // Check if CacheStorage is available
      if (!('caches' in self)) {
        console.warn('[SW] CacheStorage API not available - skipping cache cleanup');
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
              console.log(`[SW] Deleted old cache: ${key}`);
            } catch (error) {
              // Don't fail if individual cache deletion fails
              if (error.message?.includes('CacheStorage') || error.message?.includes('open')) {
                console.warn(`[SW] CacheStorage unavailable, skipping deletion of ${key}`);
              } else {
                console.warn(`[SW] Failed to delete cache ${key}:`, error.message);
              }
            }
          });

        await Promise.allSettled(deletePromises);
        console.log('[SW] Cache cleanup completed');
        
        // Claim clients (optional - can be removed if causing issues)
        // await self.clients.claim();
      } catch (error) {
        // If CacheStorage fails completely, continue without cleanup
        if (error.message?.includes('CacheStorage') || error.message?.includes('open')) {
          console.warn('[SW] CacheStorage unavailable - continuing without cleanup');
        } else {
          console.error('[SW] Activate error:', error);
        }
        // Don't fail activation - service worker can work without cache
      }
    })()
  );
});

// ============================================
// Fetch Event - Request Handling
// ============================================
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

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
  // Handle PHP Pages - Network Only (No Caching)
  // ============================================
  if (url.pathname.endsWith('.php')) {
    const isNavigation = request.mode === 'navigate';
    event.respondWith(networkOnly(request, isNavigation));
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

  if (isStaticAsset) {
    event.respondWith(cacheFirst(request, STATIC_CACHE_NAME));
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

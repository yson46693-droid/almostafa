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
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css'
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
  try {
    const cache = await caches.open(PRECACHE_NAME);
    const offlinePage = await cache.match('/offline.html');
    if (offlinePage) {
      return offlinePage;
    }
  } catch (error) {
    console.error('Failed to get offline page:', error);
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
        console.error(`Failed to cache ${request.url}:`, error);
      });
    }
    
    return response;
  } catch (error) {
    console.error(`Cache First error for ${request.url}:`, error);
    // Fallback to network
    try {
      return await fetch(request);
    } catch (networkError) {
      console.error(`Network fallback failed for ${request.url}:`, networkError);
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
    
    // Cache successful responses
    if (response.status === 200 && response.ok) {
      try {
        const cache = await caches.open(cacheName);
        const responseClone = response.clone();
        cache.put(request, responseClone).catch((error) => {
          console.error(`Failed to cache ${request.url}:`, error);
        });
      } catch (cacheError) {
        // Cache error shouldn't block response
        console.error(`Cache error for ${request.url}:`, cacheError);
      }
    }
    
    return response;
  } catch (error) {
    // Network failed, try cache
    if (isNetworkError(error)) {
      console.error(`Network error for ${request.url}:`, error);
      
      try {
        const cache = await caches.open(cacheName);
        const cached = await cache.match(request);
        
        if (cached) {
          return cached;
        }
      } catch (cacheError) {
        console.error(`Cache lookup failed for ${request.url}:`, cacheError);
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
      try {
        if (!('caches' in self)) {
          console.warn('[SW] CacheStorage API not available');
          return;
        }

        const cache = await caches.open(PRECACHE_NAME);
        
        // Cache precache assets
        const cachePromises = PRECACHE_ASSETS.map(async (asset) => {
          try {
            await cache.add(asset);
            console.log(`[SW] Precached: ${asset}`);
          } catch (error) {
            console.error(`[SW] Failed to precache ${asset}:`, error);
          }
        });

        await Promise.allSettled(cachePromises);
        console.log('[SW] Precaching completed');
      } catch (error) {
        console.error('[SW] Install error:', error);
        // Don't fail installation
      }
    })()
  );
  
  // Don't use skipWaiting to avoid unexpected page reloads
  // Let the user control when to update
});

// ============================================
// Activate Event - Cleanup Old Caches
// ============================================
self.addEventListener('activate', (event) => {
  console.log(`[SW] Activating Service Worker v${CACHE_VERSION}`);
  
  event.waitUntil(
    (async () => {
      try {
        if (!('caches' in self)) {
          console.warn('[SW] CacheStorage API not available');
          return;
        }

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
              console.error(`[SW] Failed to delete cache ${key}:`, error);
            }
          });

        await Promise.allSettled(deletePromises);
        console.log('[SW] Cache cleanup completed');
        
        // Claim clients (optional - can be removed if causing issues)
        // await self.clients.claim();
      } catch (error) {
        console.error('[SW] Activate error:', error);
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

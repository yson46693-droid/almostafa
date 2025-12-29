'use strict';

const PRECACHE_VERSION = 'v1';
const PRECACHE_NAME = `albarakah-precache-${PRECACHE_VERSION}`;
const RUNTIME_CACHE_NAME = 'albarakah-runtime';

const PRECACHE_ASSETS = [
  '/offline.html',
  '/assets/icons/icon-192x192.png',
  '/assets/icons/icon-512x512.png'
];

const CDN_ASSETS = [
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css'
];

// Install event - cache essential assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    Promise.resolve().then(async () => {
      try {
        // Check if CacheStorage is available
        if (!('caches' in self)) {
          console.warn('CacheStorage API not available');
          return;
        }

        const cache = await caches.open(PRECACHE_NAME).catch((error) => {
          console.error('Failed to open cache:', error);
          // Return null to skip caching, but don't fail install
          return null;
        });

        if (!cache) {
          console.warn('Cache not available, skipping precaching');
          return;
        }

        // Cache assets with individual error handling
        const cachePromises = PRECACHE_ASSETS.map(async (asset) => {
          try {
            await cache.add(asset);
          } catch (error) {
            console.error(`Failed to cache ${asset}:`, error);
            // Continue with other assets even if one fails
          }
        });

        await Promise.allSettled(cachePromises);
      } catch (error) {
        // Handle any unexpected errors gracefully
        console.error('Unexpected error during install:', error);
        // Don't fail the install
      }
    })
  );
  self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    Promise.resolve().then(async () => {
      try {
        // Check if CacheStorage is available
        if (!('caches' in self)) {
          console.warn('CacheStorage API not available');
          return;
        }

        const keys = await caches.keys().catch((error) => {
          console.error('Failed to get cache keys:', error);
          return [];
        });

        const deletePromises = keys
          .filter((key) => key !== PRECACHE_NAME && key !== RUNTIME_CACHE_NAME)
          .map(async (key) => {
            try {
              await caches.delete(key);
            } catch (error) {
              console.error(`Failed to delete cache ${key}:`, error);
            }
          });

        await Promise.allSettled(deletePromises);
      } catch (error) {
        // Handle any unexpected errors gracefully
        console.error('Unexpected error during activate:', error);
        // Don't fail activation
      }
    })
  );
  self.clients.claim();
});

// Fetch event - handle network requests
self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Only handle GET requests
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  // Skip cross-origin requests
  if (url.origin !== location.origin) {
    return;
  }

  // Handle API requests - network only
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Allow all server responses to pass through (even errors like 500, 404)
          return response;
        })
        .catch((error) => {
          // Only show network error for actual network failures
          // Check if it's a real network error (TypeError, NetworkError, or failed fetch)
          const isNetworkError = 
            error.name === 'TypeError' ||
            error.name === 'NetworkError' ||
            error.message === 'Failed to fetch' ||
            error.message?.includes('network') ||
            error.message?.includes('fetch');
          
          // Only return network error message for actual network failures
          if (isNetworkError) {
            return new Response(
              JSON.stringify({ success: false, message: 'لا يوجد اتصال بالشبكة.' }),
              {
                status: 503,
                headers: { 'Content-Type': 'application/json; charset=utf-8' }
              }
            );
          }
          
          // For other errors, re-throw to let them propagate normally
          throw error;
        })
    );
    return;
  }

  // Handle precached assets (static files only, not PHP pages)
  const isPrecached = PRECACHE_ASSETS.some((asset) => {
    const assetUrl = new URL(asset, location.origin);
    return assetUrl.pathname === url.pathname;
  });

  if (isPrecached) {
    event.respondWith(
      caches.match(request)
        .then((cached) => cached || fetch(request))
        .catch((error) => {
          console.error('CacheStorage error for precached asset:', error);
          // Fallback to network fetch
          return fetch(request);
        })
    );
    return;
  }

  // Skip PHP pages from precaching - they are dynamic and should always be fetched fresh
  if (url.pathname.endsWith('.php') && !url.pathname.includes('/api/')) {
    // For PHP pages, always try network first with timeout
    event.respondWith(
      Promise.race([
        fetch(request, {
          cache: 'no-store',
          credentials: 'same-origin'
        }),
        new Promise((_, reject) => 
          setTimeout(() => reject(new Error('Request timeout')), 10000)
        )
      ])
      .then((networkResponse) => {
        // Only cache successful responses
        if (networkResponse.status === 200 && networkResponse.ok) {
          const responseClone = networkResponse.clone();
          caches.open(RUNTIME_CACHE_NAME).then((cache) => {
            cache.put(request, responseClone);
          }).catch((error) => {
            console.error('Failed to cache PHP response:', error);
          });
        }
        return networkResponse;
      })
      .catch((error) => {
        console.error('Network fetch failed for PHP page:', error);
        // Try cache as fallback
        return caches.match(request)
          .then((cached) => {
            if (cached) {
              return cached;
            }
            // If no cache and network failed, return offline page for navigation
            if (request.mode === 'navigate') {
              return caches.match('/offline.html')
                .then((offlinePage) => {
                  if (offlinePage) {
                    return offlinePage;
                  }
                  // Last resort: return error response
                  return new Response('لا يوجد اتصال بالشبكة', {
                    status: 503,
                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                  });
                })
                .catch(() => {
                  // If cache lookup fails, return error response
                  return new Response('لا يوجد اتصال بالشبكة', {
                    status: 503,
                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                  });
                });
            }
            throw error;
          })
          .catch(() => {
            // If cache operations fail, return error response for navigation requests
            if (request.mode === 'navigate') {
              return new Response('لا يوجد اتصال بالشبكة', {
                status: 503,
                headers: { 'Content-Type': 'text/html; charset=utf-8' }
              });
            }
            throw error;
          });
      })
    );
    return;
  }

  // Handle CDN assets - cache first, then network
  if (CDN_ASSETS.some((cdn) => url.href.includes(cdn))) {
    event.respondWith(
      caches.open(PRECACHE_NAME)
        .then((cache) =>
          cache.match(request).then((cached) => {
            const networkFetch = fetch(request)
              .then((response) => {
                if (response.status === 200) {
                  cache.put(request, response.clone()).catch((error) => {
                    console.error('Failed to cache CDN asset:', error);
                  });
                }
                return response;
              })
              .catch(() => cached);
            return cached || networkFetch;
          })
        )
        .catch((error) => {
          console.error('CacheStorage error for CDN assets:', error);
          // Fallback to network fetch
          return fetch(request);
        })
    );
    return;
  }

  // Handle static assets (scripts, styles, fonts) - cache first
  if (request.destination === 'script' || request.destination === 'style' || request.destination === 'font') {
    event.respondWith(
      caches.open(RUNTIME_CACHE_NAME)
        .then((cache) =>
          cache.match(request).then((cached) => {
            const networkFetch = fetch(request).then((response) => {
              if (response.status === 200) {
                cache.put(request, response.clone()).catch((error) => {
                  console.error('Failed to cache static asset:', error);
                });
              }
              return response;
            }).catch(() => cached);
            return cached || networkFetch;
          })
        )
        .catch((error) => {
          console.error('CacheStorage error for static assets:', error);
          // Fallback to network fetch
          return fetch(request);
        })
    );
    return;
  }

  // Handle images - cache first
  if (request.destination === 'image') {
    event.respondWith(
      caches.open(RUNTIME_CACHE_NAME)
        .then((cache) =>
          cache.match(request).then((cached) => {
            if (cached) {
              return cached;
            }
            return fetch(request)
              .then((response) => {
                if (response.status === 200) {
                  cache.put(request, response.clone()).catch((error) => {
                    console.error('Failed to cache image:', error);
                  });
                }
                return response;
              })
              .catch(() => null);
          })
        )
        .catch((error) => {
          console.error('CacheStorage error for images:', error);
          // Fallback to network fetch
          return fetch(request).catch(() => null);
        })
    );
    return;
  }

  // Handle HTML pages (non-PHP) - network first, fallback to cache or offline page
  const acceptsHTML = request.headers.get('accept')?.includes('text/html');

  if (acceptsHTML && !url.pathname.endsWith('.php')) {
    event.respondWith(
      Promise.race([
        fetch(request, {
          cache: 'no-store',
          credentials: 'same-origin'
        }),
        new Promise((_, reject) => 
          setTimeout(() => reject(new Error('Request timeout')), 10000)
        )
      ])
      .then((networkResponse) => {
        if (networkResponse.status === 200 && networkResponse.ok) {
          const responseClone = networkResponse.clone();
          caches.open(RUNTIME_CACHE_NAME)
            .then((cache) => cache.put(request, responseClone))
            .catch((error) => {
              console.error('Failed to cache HTML response:', error);
            });
        }
        return networkResponse;
      })
      .catch((error) => {
        console.error('Network fetch failed for HTML page:', error);
        return caches.match(request)
          .then((cached) => {
            if (cached) {
              return cached;
            }
            // Fallback to offline page for navigation requests
            if (request.mode === 'navigate') {
              return caches.match('/offline.html')
                .then((offlinePage) => {
                  if (offlinePage) {
                    return offlinePage;
                  }
                  return new Response('لا يوجد اتصال بالشبكة', {
                    status: 503,
                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                  });
                })
                .catch(() => {
                  return new Response('لا يوجد اتصال بالشبكة', {
                    status: 503,
                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                  });
                });
            }
            return new Response('لا يوجد اتصال بالشبكة', {
              status: 503,
              headers: { 'Content-Type': 'text/plain; charset=utf-8' }
            });
          })
          .catch(() => {
            // If cache operations fail, return error response
            if (request.mode === 'navigate') {
              return new Response('لا يوجد اتصال بالشبكة', {
                status: 503,
                headers: { 'Content-Type': 'text/html; charset=utf-8' }
              });
            }
            return new Response('لا يوجد اتصال بالشبكة', {
              status: 503,
              headers: { 'Content-Type': 'text/plain; charset=utf-8' }
            });
          });
      })
    );
    return;
  }

  // Default: network first with timeout, fallback to cache
  event.respondWith(
    Promise.race([
      fetch(request, {
        cache: 'no-store',
        credentials: 'same-origin'
      }),
      new Promise((_, reject) => 
        setTimeout(() => reject(new Error('Request timeout')), 10000)
      )
    ])
    .then((networkResponse) => {
      if (networkResponse.status === 200 && networkResponse.ok) {
        const responseClone = networkResponse.clone();
        caches.open(RUNTIME_CACHE_NAME)
          .then((cache) => cache.put(request, responseClone))
          .catch((error) => {
            console.error('Failed to cache default response:', error);
          });
      }
      return networkResponse;
    })
    .catch((error) => {
      console.error('Network fetch failed:', error);
      return caches.match(request)
        .then((cached) => {
          if (cached) {
            return cached;
          }
          throw error;
        })
        .catch(() => {
          // If cache operations fail, re-throw original error
          throw error;
        });
    })
  );
});


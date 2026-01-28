/**
 * Local Storage & IndexedDB Cache Manager
 * نظام إدارة التخزين المحلي لتقليل طلبات الشبكة
 */

(function() {
    'use strict';

    const CACHE_VERSION = 'v1.0.0';
    const STORAGE_PREFIX = 'albarakah_cache_';
    const DB_NAME = 'albarakah_cache_db';
    const DB_VERSION = 1;
    const MAX_CACHE_SIZE = 50 * 1024 * 1024; // 50MB
    const CACHE_EXPIRY = 24 * 60 * 60 * 1000; // 24 ساعة

    let db = null;

    /**
     * تهيئة IndexedDB
     */
    function initIndexedDB() {
        return new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                console.warn('[Cache] IndexedDB not supported, using localStorage only');
                resolve(null);
                return;
            }

            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onerror = () => {
                console.warn('[Cache] IndexedDB error:', request.error);
                resolve(null);
            };

            request.onsuccess = () => {
                db = request.result;
                console.log('[Cache] IndexedDB initialized');
                resolve(db);
            };

            request.onupgradeneeded = (event) => {
                const database = event.target.result;
                
                // إنشاء object store للبيانات
                if (!database.objectStoreNames.contains('cache')) {
                    const store = database.createObjectStore('cache', { keyPath: 'key' });
                    store.createIndex('timestamp', 'timestamp', { unique: false });
                }
            };
        });
    }

    /**
     * حفظ بيانات في localStorage
     */
    function saveToLocalStorage(key, data, expiry = CACHE_EXPIRY) {
        try {
            const cacheData = {
                data: data,
                timestamp: Date.now(),
                expiry: expiry
            };
            localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(cacheData));
            return true;
        } catch (error) {
            // إذا تجاوز localStorage الحد المسموح، حاول حذف بيانات قديمة
            if (error.name === 'QuotaExceededError') {
                cleanupLocalStorage();
                try {
                    const cacheData = {
                        data: data,
                        timestamp: Date.now(),
                        expiry: expiry
                    };
                    localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(cacheData));
                    return true;
                } catch (retryError) {
                    console.warn('[Cache] Failed to save to localStorage:', retryError);
                    return false;
                }
            }
            console.warn('[Cache] Error saving to localStorage:', error);
            return false;
        }
    }

    /**
     * جلب بيانات من localStorage
     */
    function getFromLocalStorage(key) {
        try {
            const cached = localStorage.getItem(STORAGE_PREFIX + key);
            if (!cached) return null;

            const cacheData = JSON.parse(cached);
            const now = Date.now();
            
            // التحقق من انتهاء الصلاحية
            if (now - cacheData.timestamp > cacheData.expiry) {
                localStorage.removeItem(STORAGE_PREFIX + key);
                return null;
            }

            return cacheData.data;
        } catch (error) {
            console.warn('[Cache] Error reading from localStorage:', error);
            return null;
        }
    }

    /**
     * حفظ بيانات في IndexedDB
     */
    function saveToIndexedDB(key, data, expiry = CACHE_EXPIRY) {
        if (!db) return Promise.resolve(false);

        return new Promise((resolve) => {
            const transaction = db.transaction(['cache'], 'readwrite');
            const store = transaction.objectStore('cache');
            
            const cacheData = {
                key: key,
                data: data,
                timestamp: Date.now(),
                expiry: expiry
            };

            const request = store.put(cacheData);

            request.onsuccess = () => {
                resolve(true);
            };

            request.onerror = () => {
                console.warn('[Cache] Error saving to IndexedDB:', request.error);
                resolve(false);
            };
        });
    }

    /**
     * جلب بيانات من IndexedDB
     */
    function getFromIndexedDB(key) {
        if (!db) return Promise.resolve(null);

        return new Promise((resolve) => {
            const transaction = db.transaction(['cache'], 'readonly');
            const store = transaction.objectStore('cache');
            const request = store.get(key);

            request.onsuccess = () => {
                const result = request.result;
                if (!result) {
                    resolve(null);
                    return;
                }

                const now = Date.now();
                
                // التحقق من انتهاء الصلاحية
                if (now - result.timestamp > result.expiry) {
                    // حذف البيانات المنتهية
                    const deleteTransaction = db.transaction(['cache'], 'readwrite');
                    const deleteStore = deleteTransaction.objectStore('cache');
                    deleteStore.delete(key);
                    resolve(null);
                    return;
                }

                resolve(result.data);
            };

            request.onerror = () => {
                console.warn('[Cache] Error reading from IndexedDB:', request.error);
                resolve(null);
            };
        });
    }

    /**
     * تنظيف localStorage من البيانات المنتهية
     */
    function cleanupLocalStorage() {
        try {
            const keys = Object.keys(localStorage);
            const now = Date.now();
            let cleaned = 0;

            keys.forEach(key => {
                if (key.startsWith(STORAGE_PREFIX)) {
                    try {
                        const cached = localStorage.getItem(key);
                        if (cached) {
                            const cacheData = JSON.parse(cached);
                            if (now - cacheData.timestamp > cacheData.expiry) {
                                localStorage.removeItem(key);
                                cleaned++;
                            }
                        }
                    } catch (error) {
                        // حذف البيانات التالفة
                        localStorage.removeItem(key);
                        cleaned++;
                    }
                }
            });

            if (cleaned > 0) {
                console.log(`[Cache] Cleaned up ${cleaned} expired entries from localStorage`);
            }
        } catch (error) {
            console.warn('[Cache] Error cleaning localStorage:', error);
        }
    }

    /**
     * تنظيف IndexedDB من البيانات المنتهية
     */
    function cleanupIndexedDB() {
        if (!db) return Promise.resolve();

        return new Promise((resolve) => {
            const transaction = db.transaction(['cache'], 'readwrite');
            const store = transaction.objectStore('cache');
            const index = store.index('timestamp');
            const now = Date.now();
            let cleaned = 0;

            const request = index.openCursor();
            request.onsuccess = (event) => {
                const cursor = event.target.result;
                if (cursor) {
                    const data = cursor.value;
                    if (now - data.timestamp > data.expiry) {
                        cursor.delete();
                        cleaned++;
                    }
                    cursor.continue();
                } else {
                    if (cleaned > 0) {
                        console.log(`[Cache] Cleaned up ${cleaned} expired entries from IndexedDB`);
                    }
                    resolve();
                }
            };

            request.onerror = () => {
                console.warn('[Cache] Error cleaning IndexedDB:', request.error);
                resolve();
            };
        });
    }

    /**
     * مسح جميع بيانات الكاش من localStorage
     */
    function clearAllLocalStorage() {
        try {
            const keys = Object.keys(localStorage);
            let cleared = 0;

            keys.forEach(key => {
                if (key.startsWith(STORAGE_PREFIX)) {
                    localStorage.removeItem(key);
                    cleared++;
                }
            });

            if (cleared > 0) {
                console.log(`[Cache] Cleared ${cleared} entries from localStorage`);
            }
            return cleared;
        } catch (error) {
            console.warn('[Cache] Error clearing localStorage:', error);
            return 0;
        }
    }

    /**
     * مسح جميع بيانات الكاش من IndexedDB
     */
    function clearAllIndexedDB() {
        if (!db) return Promise.resolve(0);

        return new Promise((resolve) => {
            const transaction = db.transaction(['cache'], 'readwrite');
            const store = transaction.objectStore('cache');
            const request = store.clear();

            request.onsuccess = () => {
                console.log('[Cache] Cleared all entries from IndexedDB');
                resolve(1);
            };

            request.onerror = () => {
                console.warn('[Cache] Error clearing IndexedDB:', request.error);
                resolve(0);
            };
        });
    }

    /**
     * مسح جميع بيانات الكاش (localStorage و IndexedDB)
     */
    async function clearAllCache() {
        const localStorageCleared = clearAllLocalStorage();
        const indexedDBCleared = await clearAllIndexedDB();
        
        // مسح Service Worker cache أيضاً إذا كان متاحاً
        if ('caches' in window) {
            try {
                const cacheNames = await caches.keys();
                await Promise.all(
                    cacheNames.map(cacheName => caches.delete(cacheName))
                );
                console.log('[Cache] Cleared Service Worker caches');
            } catch (error) {
                console.warn('[Cache] Error clearing Service Worker caches:', error);
            }
        }
        
        console.log('[Cache] All cache cleared');
        return localStorageCleared + indexedDBCleared;
    }

    /**
     * مسح كاش API محدد بناءً على URL
     */
    function clearApiCache(url) {
        const key = 'api_' + btoa(url).replace(/[+/=]/g, '');
        
        // مسح من localStorage
        try {
            localStorage.removeItem(STORAGE_PREFIX + key);
        } catch (error) {
            console.warn('[Cache] Error clearing API cache from localStorage:', error);
        }
        
        // مسح من IndexedDB
        if (db) {
            return new Promise((resolve) => {
                const transaction = db.transaction(['cache'], 'readwrite');
                const store = transaction.objectStore('cache');
                const request = store.delete(key);

                request.onsuccess = () => {
                    console.log(`[Cache] Cleared API cache for: ${url}`);
                    resolve(true);
                };

                request.onerror = () => {
                    console.warn('[Cache] Error clearing API cache from IndexedDB:', request.error);
                    resolve(false);
                };
            });
        }
        
        return Promise.resolve(true);
    }

    /**
     * حفظ API response في الكاش
     */
    function cacheApiResponse(url, data, expiry = CACHE_EXPIRY) {
        const key = 'api_' + btoa(url).replace(/[+/=]/g, '');
        
        // حفظ في localStorage (للبيانات الصغيرة)
        if (JSON.stringify(data).length < 100000) { // أقل من 100KB
            saveToLocalStorage(key, data, expiry);
        }
        
        // حفظ في IndexedDB (لجميع البيانات)
        if (db) {
            saveToIndexedDB(key, data, expiry);
        }
    }

    /**
     * جلب API response من الكاش
     */
    async function getCachedApiResponse(url) {
        const key = 'api_' + btoa(url).replace(/[+/=]/g, '');
        
        // محاولة جلب من localStorage أولاً
        const localData = getFromLocalStorage(key);
        if (localData) {
            return localData;
        }
        
        // محاولة جلب من IndexedDB
        if (db) {
            const dbData = await getFromIndexedDB(key);
            if (dbData) {
                return dbData;
            }
        }
        
        return null;
    }

    /**
     * Fetch wrapper مع caching
     */
    async function cachedFetch(url, options = {}, cacheExpiry = CACHE_EXPIRY) {
        const cacheKey = options.method === 'GET' ? url : null;
        
        // محاولة جلب من الكاش للطلبات GET فقط
        if (cacheKey && !options.cache || options.cache !== 'no-cache') {
            const cached = await getCachedApiResponse(cacheKey);
            if (cached) {
                return new Response(JSON.stringify(cached), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                });
            }
        }
        
        // جلب من الشبكة
        try {
            const response = await fetch(url, options);
            
            if (response.ok && cacheKey && options.method === 'GET') {
                const data = await response.json();
                cacheApiResponse(cacheKey, data, cacheExpiry);
                return new Response(JSON.stringify(data), {
                    status: response.status,
                    headers: response.headers
                });
            }
            
            return response;
        } catch (error) {
            // في حالة الخطأ، محاولة جلب من الكاش
            if (cacheKey) {
                const cached = await getCachedApiResponse(cacheKey);
                if (cached) {
                    return new Response(JSON.stringify(cached), {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' }
                    });
                }
            }
            throw error;
        }
    }

    // تهيئة النظام
    initIndexedDB().then(() => {
        // تنظيف البيانات المنتهية عند التحميل
        cleanupLocalStorage();
        if (db) {
            cleanupIndexedDB();
        }
        
        // تنظيف دوري كل ساعة
        setInterval(() => {
            cleanupLocalStorage();
            if (db) {
                cleanupIndexedDB();
            }
        }, 60 * 60 * 1000);
    });

    // تصدير الوظائف للاستخدام العام
    window.LocalStorageCache = {
        save: saveToLocalStorage,
        get: getFromLocalStorage,
        saveToDB: saveToIndexedDB,
        getFromDB: getFromIndexedDB,
        cacheApi: cacheApiResponse,
        getCachedApi: getCachedApiResponse,
        cachedFetch: cachedFetch,
        cleanup: cleanupLocalStorage,
        clearAll: clearAllCache,
        clearApiCache: clearApiCache,
        clearLocalStorage: clearAllLocalStorage,
        clearIndexedDB: clearAllIndexedDB,
        init: initIndexedDB
    };

    console.log('[Cache] Local Storage Cache Manager initialized');
})();


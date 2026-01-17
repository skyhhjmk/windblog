/* windblog_webman Service Worker
 * Simplified Strategy with Auto-Bypass Feature
 * - Pages (HTML, navigate): Network First with cache fallback
 * - Static assets (same-origin /assets, common static extensions): Cache First
 * - CDN resources (cross-origin from allowlist): Cache First
 * - Versioned caches with cleanup on activate
 * - Auto-bypass feature: Disables caching for problematic resources
 */
const SW_VERSION = 'v2.5.3';
const CACHE_PAGES = `pages-${SW_VERSION}`;
const CACHE_STATIC = `static-${SW_VERSION}`;
const CACHE_CDN = `cdn-${SW_VERSION}`;
const CACHE_API = `api-${SW_VERSION}`;

const CDN_HOSTS = ['cdn.jsdelivr.net', 'unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.tailwindcss.com', 'cdnjs.cloudflare.com'];

// Auto-bypass configuration
let bypassMode = false;
const bypassedResources = new Set();
const MAX_FAILURES = 3;
const failureCounts = new Map();

// Log function for debugging
function log(message, data = {}) {
    console.log(`[SW v${SW_VERSION}] ${message}`, data);
}

// Utility functions
function isSameOrigin(url) {
    return url.origin === self.location.origin;
}

function isStaticPath(url) {
    if (url.pathname.startsWith('/assets/')) return true;
    const ext = url.pathname.split('.').pop().toLowerCase();
    const staticExts = ['css', 'js', 'mjs', 'json', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico', 'avif', 'woff', 'woff2', 'ttf', 'otf', 'eot'];
    return staticExts.includes(ext);
}

function isApiPath(url) {
    const apiPaths = ['/user/profile/api'];
    return apiPaths.some(p => url.pathname.startsWith(p));
}

function isCdn(url) {
    return CDN_HOSTS.some(h => url.hostname === h || url.hostname.endsWith(`.${h}`));
}

// Track resource failures and enable bypass if needed
function trackFailure(url) {
    const key = url.toString();
    const count = (failureCounts.get(key) || 0) + 1;
    failureCounts.set(key, count);

    log(`Resource failed to load`, {url: key, failureCount: count});

    if (count >= MAX_FAILURES) {
        bypassedResources.add(key);
        log(`Auto-bypass enabled for resource`, {url: key});

        // If too many resources are failing, enable global bypass mode
        if (bypassedResources.size >= 5) {
            bypassMode = true;
            log(`Global bypass mode enabled`);
        }
    }
}

// Reset failure count for successful resources
function resetFailureCount(url) {
    const key = url.toString();
    failureCounts.delete(key);
    bypassedResources.delete(key);
}

// Simple Cache First strategy with auto-bypass
async function cacheFirst(req, cacheName) {
    const url = new URL(req.url);

    // Check if this resource should be bypassed
    if (bypassMode || bypassedResources.has(req.url)) {
        log(`Bypassing cache for resource`, {url: req.url});
        try {
            const res = await fetch(req);
            if (res.ok) {
                resetFailureCount(url);
            }
            return res;
        } catch (err) {
            trackFailure(url);
            throw err;
        }
    }

    try {
        const cache = await caches.open(cacheName);
        const cached = await cache.match(req, {ignoreVary: true});

        if (cached) {
            // Return cached response immediately
            return cached;
        }

        // No cache, fetch from network
        const res = await fetch(req, {mode: req.mode, credentials: req.credentials});
        if (res && res.ok) {
            // Cache successful response
            await cache.put(req, res.clone());
            resetFailureCount(url);
        }

        return res;
    } catch (err) {
        trackFailure(url);
        // Fallback to network if cache operation fails
        return fetch(req);
    }
}

// Simple Network First strategy for HTML pages with auto-bypass
async function networkFirst(req, cacheName) {
    const url = new URL(req.url);

    // Check if this resource should be bypassed
    if (bypassMode || bypassedResources.has(req.url)) {
        log(`Bypassing cache for HTML resource`, {url: req.url});
        try {
            const res = await fetch(req);
            if (res.ok) {
                resetFailureCount(url);
            }
            return res;
        } catch (err) {
            trackFailure(url);
            throw err;
        }
    }

    try {
        // Try network first
        const res = await fetch(req);
        if (res && res.ok) {
            // Cache successful response
            const cache = await caches.open(cacheName);
            await cache.put(req, res.clone());
            resetFailureCount(url);
            return res;
        }

        // Network failed, try cache
        const cache = await caches.open(cacheName);
        const cached = await cache.match(req, {ignoreVary: true});
        if (cached) {
            log(`Using cached response for HTML`, {url: req.url});
            return cached;
        }

        // No cache available
        const errorResponse = new Response('Network error occurred', {
            status: 503,
            headers: {'Content-Type': 'text/plain; charset=utf-8'}
        });
        trackFailure(url);
        return errorResponse;
    } catch (err) {
        trackFailure(url);
        // Network error, try cache as last resort
        try {
            const cache = await caches.open(cacheName);
            const cached = await cache.match(req, {ignoreVary: true});
            if (cached) {
                log(`Using cached response after network error`, {url: req.url});
                return cached;
            }

            // No cache available
            return new Response('Network error occurred', {
                status: 503,
                headers: {'Content-Type': 'text/plain; charset=utf-8'}
            });
        } catch (cacheErr) {
            // Cache error, return network error
            return new Response('Network error occurred', {
                status: 503,
                headers: {'Content-Type': 'text/plain; charset=utf-8'}
            });
        }
    }
}

// Simple API cache strategy with auto-bypass
async function apiCacheStrategy(req, cacheName) {
    const url = new URL(req.url);

    // Check if this resource should be bypassed
    if (bypassMode || bypassedResources.has(req.url)) {
        log(`Bypassing cache for API resource`, {url: req.url});
        try {
            const res = await fetch(req);
            if (res.ok) {
                resetFailureCount(url);
            }
            return res;
        } catch (err) {
            trackFailure(url);
            throw err;
        }
    }

    try {
        // Try network first
        const res = await fetch(req);
        if (res && res.ok) {
            // Cache successful response
            const cache = await caches.open(cacheName);
            await cache.put(req, res.clone());
            resetFailureCount(url);
            return res;
        }

        // Network failed, try cache
        const cache = await caches.open(cacheName);
        const cached = await cache.match(req, {ignoreVary: true});
        if (cached) {
            log(`Using cached API response`, {url: req.url});
            return cached;
        }

        // No cache available, return empty JSON response
        const errorResponse = new Response(JSON.stringify({code: -1, msg: 'Network unavailable', data: null}), {
            status: 200,
            headers: {'Content-Type': 'application/json; charset=utf-8'}
        });
        trackFailure(url);
        return errorResponse;
    } catch (err) {
        trackFailure(url);
        // Network error, try cache
        try {
            const cache = await caches.open(cacheName);
            const cached = await cache.match(req, {ignoreVary: true});
            if (cached) {
                log(`Using cached API response after network error`, {url: req.url});
                return cached;
            }

            // No cache available
            return new Response(JSON.stringify({code: -1, msg: 'Network unavailable', data: null}), {
                status: 200,
                headers: {'Content-Type': 'application/json; charset=utf-8'}
            });
        } catch (cacheErr) {
            // Cache error
            return new Response(JSON.stringify({code: -1, msg: 'Network unavailable', data: null}), {
                status: 200,
                headers: {'Content-Type': 'application/json; charset=utf-8'}
            });
        }
    }
}

// Install event - simplified
self.addEventListener('install', event => {
    log('Service Worker installing');
    event.waitUntil((async () => {
        // Skip waiting to activate new service worker immediately
        self.skipWaiting();
        log('Service Worker skipWaiting completed');
    })());
});

// Activate event - cleanup old caches
self.addEventListener('activate', event => {
    log('Service Worker activating');
    event.waitUntil((async () => {
        // Cleanup old caches
        const keys = await caches.keys();
        const currentCaches = new Set([CACHE_PAGES, CACHE_STATIC, CACHE_CDN, CACHE_API]);
        await Promise.all(keys.map(key => {
            if (!currentCaches.has(key)) {
                log('Deleting old cache', {cacheName: key});
                return caches.delete(key);
            }
        }));

        // Enable navigation preload if supported
        try {
            if (self.registration.navigationPreload) {
                await self.registration.navigationPreload.enable();
                log('Navigation preload enabled');
            }
        } catch (err) {
            log('Navigation preload not supported', {error: err.message});
        }

        // Claim clients immediately
        await self.clients.claim();
        log('Service Worker activated and clients claimed');
    })());
});

// Message handler for skipWaiting and bypass control
self.addEventListener('message', event => {
    const {type, data} = event.data || {};

    if (type === 'SKIP_WAITING') {
        log('Skip waiting requested from client');
        self.skipWaiting();
    } else if (type === 'CLEAR_BYPASS') {
        // Clear bypass state
        bypassMode = false;
        bypassedResources.clear();
        failureCounts.clear();
        log('Bypass state cleared');
    } else if (type === 'GET_BYPASS_STATE') {
        // Send bypass state to client
        if (event.source) {
            event.source.postMessage({
                type: 'BYPASS_STATE',
                data: {
                    bypassMode,
                    bypassedResources: Array.from(bypassedResources),
                    failureCounts: Object.fromEntries(failureCounts)
                }
            });
        }
    }
});

// Fetch event - simplified handling with auto-bypass
self.addEventListener('fetch', event => {
    const req = event.request;

    // Only handle GET requests
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // Exclude admin paths completely - use browser default behavior
    if (url.pathname.startsWith('/app/admin') || url.pathname.startsWith('/admin')) {
        log('Excluding admin path from Service Worker', {url: req.url});
        return;
    }

    const accept = req.headers.get('accept') || '';
    const isHtml = accept.includes('text/html');

    // HTML requests: Network First with cache fallback
    if (req.mode === 'navigate' || (isHtml && isSameOrigin(url))) {
        log('Handling HTML request', {url: req.url, strategy: 'networkFirst'});
        event.respondWith(networkFirst(req, CACHE_PAGES));
        return;
    }

    // Same-origin static assets: Cache First
    if (isSameOrigin(url) && isStaticPath(url)) {
        log('Handling static asset', {url: req.url, strategy: 'cacheFirst'});
        event.respondWith(cacheFirst(req, CACHE_STATIC));
        return;
    }

    // CDN assets: Cache First, except for AdSense scripts
    if (isCdn(url)) {
        // Don't cache AdSense scripts to avoid loading outdated versions
        if (url.hostname === 'pagead2.googlesyndication.com' || url.pathname.includes('adsbygoogle')) {
            log('Bypassing cache for AdSense script', {url: req.url});
            return; // Let browser handle it directly
        }
        log('Handling CDN asset', {url: req.url, strategy: 'cacheFirst'});
        event.respondWith(cacheFirst(req, CACHE_CDN));
        return;
    }

    // API requests: Network First with cache fallback
    if (isSameOrigin(url) && isApiPath(url)) {
        log('Handling API request', {url: req.url, strategy: 'networkFirst'});
        event.respondWith(apiCacheStrategy(req, CACHE_API));
        return;
    }

    // Default: Pass through to network
    log('Passing through to network', {url: req.url});
});

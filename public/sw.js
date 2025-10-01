/* windblog_webman Service Worker
 * Strategy:
 * - Pages (HTML, navigate): Network First
 * - Static assets (same-origin /assets, common static extensions): Cache First
 * - CDN resources (cross-origin from allowlist): Cache First (opaque allowed)
 * - Versioned caches with cleanup on activate
 */
const SW_VERSION = 'v1.0.3';
const CACHE_PAGES = `pages-${SW_VERSION}`;
const CACHE_STATIC = `static-${SW_VERSION}`;
const CACHE_CDN = `cdn-${SW_VERSION}`;
const SLOW_NETWORK_THRESHOLD_MS = 2000;

const PRECACHE_URLS = ['/'];

const CDN_HOSTS = [
  'cdn.jsdelivr.net',
  'unpkg.com',
  'fonts.googleapis.com',
  'fonts.gstatic.com',
  'cdn.tailwindcss.com',
  'cdnjs.cloudflare.com'
];

// Utility: test if a request is same-origin static
function isSameOrigin(url) {
  return url.origin === self.location.origin;
}

function isStaticPath(url) {
  // prioritize /assets; fallback to extensions
  if (url.pathname.startsWith('/assets/')) return true;
  const ext = url.pathname.split('.').pop().toLowerCase();
  const staticExts = [
    'css','js','mjs','json','map',
    'png','jpg','jpeg','webp','gif','svg','ico','avif',
    'woff','woff2','ttf','otf','eot',
    'mp3','mp4','webm','ogg'
  ];
  return staticExts.includes(ext);
}

function isCdn(url) {
  return CDN_HOSTS.some(h => url.hostname === h || url.hostname.endsWith(`.${h}`));
}

// Cache First for static/cdn
async function cacheFirst(req, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(req, { ignoreVary: true });
  if (cached) {
    // Try to revalidate in background (best-effort)
    try {
      const fresh = await fetch(req, { mode: req.mode, credentials: req.credentials });
      if (fresh && (fresh.ok || fresh.type === 'opaque')) {
        cache.put(req, fresh.clone());
      }
    } catch (_) {}
    return cached;
  }
  try {
    const res = await fetch(req, { mode: req.mode, credentials: req.credentials });
    if (res && (res.ok || res.type === 'opaque')) {
      cache.put(req, res.clone());
    }
    return res;
  } catch (err) {
    return cached || new Response('', { status: 408, statusText: 'Offline' });
  }
}

async function notifyClient(event, payload) {
  try {
    if (event && event.clientId) {
      const client = await self.clients.get(event.clientId);
      if (client) client.postMessage(payload);
      return;
    }
    const all = await self.clients.matchAll({ type: 'window' });
    all.forEach(c => c.postMessage(payload));
  } catch (_) {}
}

function extractAssetUrls(html) {
  try {
    const urls = new Set();
    const re = /(href|src)=["']([^"']+)["']/gi;
    let m;
    while ((m = re.exec(html)) !== null) {
      const u = m[2];
      if (!u) continue;
      if (u.startsWith('data:') || u.startsWith('mailto:') || u.startsWith('javascript:')) continue;
      // 仅同源 /assets/ 下的 .css/.js
      const isCssJs = u.endsWith('.css') || u.endsWith('.js');
      if (!isCssJs) continue;
      if (u.startsWith('/assets/')) {
        urls.add(new URL(u, self.location.origin).toString());
        continue;
      }
      // 绝对地址但同源且路径在 /assets/
      try {
        const abs = new URL(u, self.location.origin);
        if (abs.origin === self.location.origin && abs.pathname.startsWith('/assets/')) {
          urls.add(abs.toString());
        }
      } catch (_) {}
    }
    return Array.from(urls);
  } catch (_) {
    return [];
  }
}


// Network First for pages
async function networkFirst(req, event, useTimeout = true) {
  let wasTimeout = false;
  let networkFailed = false;
  const cache = await caches.open(CACHE_PAGES);

  const networkPromise = (async () => {
    try {
      const res = await fetch(req);
      if (res && res.ok) {
        cache.put(req, res.clone());
        // 如果是 HTML，后台解析并预缓存 /assets 下的 .css/.js
        try {
          const ct = res.headers.get('content-type') || '';
          if (ct.includes('text/html') && event) {
            const copy = res.clone();
            event.waitUntil((async () => {
              try {
                const html = await copy.text();
                const assetUrls = extractAssetUrls(html);
                if (assetUrls.length) {
                  const staticCache = await caches.open(CACHE_STATIC);
                  await Promise.all(assetUrls.map(u => staticCache.add(new Request(u, { credentials: 'same-origin' }))));
                }
              } catch (_) {}
            })());
          }
        } catch (_) {}
      }
      return res;
    } catch (e) {
      // 防止 Promise.race 因网络错误直接 reject
      return null;
    }
  })();


  let raced = null;
  if (useTimeout) {
    const timeoutPromise = new Promise(resolve => {
      setTimeout(() => resolve(null), SLOW_NETWORK_THRESHOLD_MS);
    });
    raced = await Promise.race([networkPromise, timeoutPromise]);

    // 网络先返回则直接使用（已在上方写入缓存）
    if (raced) {
      return raced;
    }
    wasTimeout = true;
  } else {
    // 在线导航不做超时竞速，直接等待网络（失败再走缓存兜底）
    try {
      const direct = await networkPromise;
      if (direct) return direct;
      networkFailed = true;
    } catch (_) {
      networkFailed = true;
    }
  }

  // 慢网络：尝试使用旧缓存副本
  const cached = await cache.match(req, { ignoreVary: true });
  if (cached) {
    // 后台刷新缓存
    if (event) {
      event.waitUntil((async () => {
        try {
          const res = await networkPromise;
          if (res && res.ok) {
            await cache.put(req, res.clone());
          }
        } catch (_) {}
      })());
      const noticePayload = wasTimeout
        ? { type: 'SHOW_STALE_NOTICE', reason: 'slow_network', message: '网络欠佳，已为您展示缓存副本。' }
        : { type: 'SHOW_STALE_NOTICE', reason: networkFailed ? 'offline_page_cache' : 'slow_network', message: networkFailed ? '当前离线，已为您展示该页面的缓存副本。' : '网络欠佳，已为您展示缓存副本。' };
      event.waitUntil(notifyClient(event, noticePayload));
    }
    return cached;
  }

  // 无缓存：继续等待网络
  try {
    const res = await networkPromise;
    if (res) return res;
  } catch (_) {}

  // 兜底：依次尝试已缓存的“/”与“/index.html”
  try {
    const rootUrl = new URL('/', self.location.origin);
    const idxUrl = new URL('/index.html', self.location.origin);
    const fallback = await cache.match(rootUrl, { ignoreVary: true }) || await cache.match(idxUrl, { ignoreVary: true });
    if (fallback) {
      if (event) {
        event.waitUntil(notifyClient(event, {
          type: 'SHOW_STALE_NOTICE',
          reason: 'offline_fallback',
          message: '当前离线，已为您展示缓存的首页副本。'
        }));
      }
      return fallback;
    }
  } catch (_) {}

  return new Response(
    '<h1>离线</h1><p>当前无网络连接，且无可用缓存。</p>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
  );
}

self.addEventListener('install', (event) => {
  // Skip waiting to allow quicker activation on first install
  self.skipWaiting();
  // 预缓存首页，确保离线可用
  event.waitUntil((async () => {
    try {
      const cache = await caches.open(CACHE_PAGES);
      try {
        await cache.add(new Request('/', { cache: 'reload', redirect: 'follow' }));
      } catch (_) {}
      try {
        await cache.add(new Request('/index.html', { cache: 'reload', redirect: 'follow' }));
      } catch (_) {}
    } catch (_) {}
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    const allow = new Set([CACHE_PAGES, CACHE_STATIC, CACHE_CDN]);
    await Promise.all(keys.map(k => {
      if (!allow.has(k)) return caches.delete(k);
    }));
    try {
      if (self.registration.navigationPreload && self.registration.navigationPreload.enable) {
        await self.registration.navigationPreload.enable();
      }
    } catch (_) {}
    await self.clients.claim();
  })());
});

// Optional message channel to trigger skipWaiting from page
self.addEventListener('message', (event) => {
  const { type } = event.data || {};
  if (type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Only handle GET
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Bypass PJAX/XHR HTML requests to avoid SW interference
  try {
    const xpjax = (req.headers.get('x-pjax') || '').toLowerCase();
    const xr = (req.headers.get('x-requested-with') || '').toLowerCase();
    const acceptHdr = req.headers.get('accept') || '';
    const looksHtml = acceptHdr.includes('text/html');
    const isPjaxLike = (xpjax && xpjax !== 'false') || url.searchParams.has('_pjax') || (xr === 'xmlhttprequest' && looksHtml);
    if (isPjaxLike) {
      event.respondWith(fetch(req));
      return;
    }
  } catch (_) {}

  // HTML navigations: Network First
  // Some browsers set request.mode='navigate' for navigations
  const accept = req.headers.get('accept') || '';
  const isHtml = accept.includes('text/html');

  if (req.mode === 'navigate' || (isHtml && isSameOrigin(url))) {
    // 页面请求：优先使用 navigation preload（更快），否则回退到 networkFirst（含慢网回退）
    event.respondWith((async () => {
      try {
        if (event.preloadResponse) {
          const pre = await event.preloadResponse;
          if (pre) {
            // 写入页面缓存，便于后续离线可用
            try {
              const cache = await caches.open(CACHE_PAGES);
              cache.put(req, pre.clone());
            } catch (_) {}
            await notifyClient(event, { type: 'SW_DEBUG', stage: 'navigate_preload_used', url: req.url });
            return pre;
          }
        }
      } catch (_) {}
      event.waitUntil(notifyClient(event, { type: 'SW_DEBUG', stage: 'navigate_intercept', url: req.url }));

      return networkFirst(req, event, false);
    })());
    return;
  }

  // Same-origin static: Cache First
  if (isSameOrigin(url) && isStaticPath(url)) {
    event.respondWith(cacheFirst(req, CACHE_STATIC));
    return;
  }

  // CDN/static cross-origin allowlist: Cache First
  // 保持原始请求（含 CORS 与 SRI 校验），避免破坏 integrity
  if (isCdn(url)) {
    event.respondWith(cacheFirst(req, CACHE_CDN));
    return;
  }

  // Default: passthrough
  // You could optionally add Stale-While-Revalidate for other GETs
});
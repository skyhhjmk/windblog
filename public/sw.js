/* windblog_webman Service Worker
 * Strategy:
 * - Pages (HTML, navigate): Network First
 * - Static assets (same-origin /assets, common static extensions): Cache First
 * - CDN resources (cross-origin from allowlist): Cache First (opaque allowed)
 * - Versioned caches with cleanup on activate
 */
const SW_VERSION = 'v1.8.17';
const CACHE_PAGES = `pages-${SW_VERSION}`;
const CACHE_STATIC = `static-${SW_VERSION}`;
const CACHE_CDN = `cdn-${SW_VERSION}`;
const CACHE_API = `api-${SW_VERSION}`;
const SLOW_NETWORK_THRESHOLD_MS = 2000;
const API_CACHE_MAX_AGE = 5 * 60 * 1000; // API 缓存最长 5 分钟

const PRECACHE_URLS = ['/'];

const CDN_HOSTS = ['cdn.jsdelivr.net', 'unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.tailwindcss.com', 'cdnjs.cloudflare.com'];

// Utility: test if a request is same-origin static
function isSameOrigin(url) {
    return url.origin === self.location.origin;
}

function isStaticPath(url) {
    // prioritize /assets; fallback to extensions
    if (url.pathname.startsWith('/assets/')) return true;
    const ext = url.pathname.split('.').pop().toLowerCase();
    const staticExts = ['css', 'js', 'mjs', 'json', 'map', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico', 'avif', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp3', 'mp4', 'webm', 'ogg'];
    return staticExts.includes(ext);
}

function isApiPath(url) {
    // API 路径：/captcha/config, /comment/list/*, /user/profile/api 等
    const apiPaths = [
        '/captcha/config',
        '/captcha/image',
        '/comment/list/',
        '/comment/status/',
        '/user/profile/api'
    ];
    return apiPaths.some(p => url.pathname.startsWith(p));
}

function isCdn(url) {
    return CDN_HOSTS.some(h => url.hostname === h || url.hostname.endsWith(`.${h}`));
}

// Cache First for static/cdn
async function cacheFirst(req, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(req, {ignoreVary: true});
    if (cached) {
        // Try to revalidate in background (best-effort)
        try {
            const fresh = await fetch(req, {mode: req.mode, credentials: req.credentials});
            if (fresh && (fresh.ok || fresh.type === 'opaque')) {
                cache.put(req, fresh.clone());
            }
        } catch (_) {
        }
        return cached;
    }
    try {
        const res = await fetch(req, {mode: req.mode, credentials: req.credentials});
        if (res && (res.ok || res.type === 'opaque')) {
            cache.put(req, res.clone());
        }
        return res;
    } catch (err) {
        return cached || new Response('', {status: 408, statusText: 'Offline'});
    }
}

// 网络优先但带超时的 API 缓存策略（Stale While Revalidate with timeout）
async function apiCacheStrategy(req, event) {
    const cache = await caches.open(CACHE_API);
    const url = new URL(req.url);

    // 创建网络请求 Promise（带超时）
    const networkPromise = (async () => {
        try {
            const res = await fetch(req);
            if (res && res.ok) {
                // 为响应添加时间戳
                const cloned = res.clone();
                const headers = new Headers(cloned.headers);
                headers.set('sw-cached-at', Date.now().toString());
                const newRes = new Response(cloned.body, {
                    status: cloned.status,
                    statusText: cloned.statusText,
                    headers: headers
                });
                cache.put(req, newRes.clone());
            }
            return res;
        } catch (err) {
            return null;
        }
    })();

    // 1.5 秒超时竞速（API 要求更快响应）
    const timeoutPromise = new Promise(resolve => {
        setTimeout(() => resolve(null), 1500);
    });

    const raced = await Promise.race([networkPromise, timeoutPromise]);

    // 网络快速返回则直接使用
    if (raced) {
        return raced;
    }

    // 网络慢或失败，尝试使用缓存
    const cached = await cache.match(req, {ignoreVary: true});
    if (cached) {
        // 检查缓存是否过期
        const cachedAt = cached.headers.get('sw-cached-at');
        const isExpired = cachedAt ? (Date.now() - parseInt(cachedAt)) > API_CACHE_MAX_AGE : true;

        if (!isExpired) {
            // 后台继续更新
            event.waitUntil((async () => {
                try {
                    const res = await networkPromise;
                    if (res && res.ok) {
                        const headers = new Headers(res.headers);
                        headers.set('sw-cached-at', Date.now().toString());
                        const newRes = new Response(res.body, {
                            status: res.status,
                            statusText: res.statusText,
                            headers: headers
                        });
                        await cache.put(req, newRes);
                    }
                } catch (_) {
                }
            })());

            // 发送提示
            if (event) {
                event.waitUntil(notifyClient(event, {
                    type: 'SHOW_STALE_NOTICE',
                    reason: 'slow_api',
                    message: '网络欠佳，已使用缓存数据。'
                }));
            }
            return cached;
        }
    }

    // 无有效缓存，继续等待网络
    try {
        const res = await networkPromise;
        if (res) return res;
    } catch (_) {
    }

    // 网络完全失败，返回缓存（即使过期）或空响应
    if (cached) {
        if (event) {
            event.waitUntil(notifyClient(event, {
                type: 'SHOW_STALE_NOTICE',
                reason: 'offline_api',
                message: '当前离线，已使用旧缓存数据。'
            }));
        }
        return cached;
    }

    // 完全无法获取数据，返回空的 JSON 响应
    return new Response(JSON.stringify({code: -1, msg: '网络不可用', data: null}), {
        status: 200,
        headers: {'Content-Type': 'application/json; charset=utf-8'}
    });
}

async function notifyClient(event, payload) {
    try {
        if (event && event.clientId) {
            const client = await self.clients.get(event.clientId);
            if (client) client.postMessage(payload);
            return;
        }
        const all = await self.clients.matchAll({type: 'window'});
        all.forEach(c => c.postMessage(payload));
    } catch (_) {
    }
}

function extractAssetUrls(html) {
    try {
        // 如果检测到是骨架屏页面（通常 <2KB），则不提取资源
        if (html.length < 2000 && html.indexOf('skeleton_page') !== -1) {
            return [];
        }

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
            } catch (_) {
            }
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
    const networkStartTime = Date.now();

    const networkPromise = (async () => {
        try {
            const res = await fetch(req);
            if (res && res.ok) {
                // 检查是否为骨架页：小于 2KB 且含 skeleton_page 标记
                let isSkeleton = false;
                try {
                    const ct = res.headers.get('content-type') || '';
                    if (ct.includes('text/html')) {
                        const copy = res.clone();
                        const html = await copy.text();
                        isSkeleton = html.length < 2000 && html.indexOf('skeleton_page') !== -1;
                    }
                } catch (_) {
                }

                // 只缓存真实页面，不缓存骨架页
                if (!isSkeleton) {
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
                                        await Promise.all(assetUrls.map(u => staticCache.add(new Request(u, {credentials: 'same-origin'}))));
                                    }
                                } catch (_) {
                                }
                            })());
                        }
                    } catch (_) {
                    }
                }
            }
            return {success: true, response: res};
        } catch (e) {
            // 网络请求失败（真正离线）
            return {success: false, response: null};
        }
    })();


    let raced = null;
    if (useTimeout) {
        const timeoutPromise = new Promise(resolve => {
            setTimeout(() => resolve(null), SLOW_NETWORK_THRESHOLD_MS);
        });
        raced = await Promise.race([networkPromise, timeoutPromise]);

        // 网络先返回则直接使用
        if (raced) {
            // 检查是真正离线还是成功返回
            if (!raced.success) {
                // 网络快速失败（真正离线）
                networkFailed = true;
            } else if (raced.response && raced.response.ok) {
                // 网络成功返回
                return raced.response;
            }
        } else {
            // 超时，说明网络慢
            wasTimeout = true;
        }
    } else {
        // 在线导航不做超时竞速，直接等待网络
        const result = await networkPromise;
        if (result.success && result.response && result.response.ok) {
            return result.response;
        }
        networkFailed = true;
    }

    // 慢网络或离线：尝试使用旧缓存副本
    const cached = await cache.match(req, {ignoreVary: true});
    if (cached) {
        // 后台刷新缓存
        if (event) {
            event.waitUntil((async () => {
                try {
                    const result = await networkPromise;
                    if (result.success && result.response && result.response.ok) {
                        await cache.put(req, result.response.clone());
                    }
                } catch (_) {
                }
            })());
            // 发送相应的通知
            const noticePayload = networkFailed ? {
                type: 'SHOW_STALE_NOTICE',
                reason: 'offline_page_cache',
                message: '当前离线，已为您展示该页面的缓存副本。'
            } : {
                type: 'SHOW_STALE_NOTICE',
                reason: 'slow_network',
                message: '网络欠佳，已为您展示缓存副本。'
            };
            event.waitUntil(notifyClient(event, noticePayload));
        }
        return cached;
    }

    // 无缓存：继续等待网络
    try {
        const result = await networkPromise;
        if (result.success && result.response) return result.response;
    } catch (_) {
    }

    // 兜底：依次尝试已缓存的“/”与“/index.html”
    try {
        const rootUrl = new URL('/', self.location.origin);
        const idxUrl = new URL('/index.html', self.location.origin);
        const fallback = await cache.match(rootUrl, {ignoreVary: true}) || await cache.match(idxUrl, {ignoreVary: true});
        if (fallback) {
            if (event) {
                event.waitUntil(notifyClient(event, {
                    type: 'SHOW_STALE_NOTICE', reason: 'offline_fallback', message: '当前离线，已为您展示缓存的首页副本。'
                }));
            }
            return fallback;
        }
    } catch (_) {
    }

    return createOfflinePage();
}

function createOfflinePage() {
    const html = `<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>离线模式</title>
<style>
html,body{height:100%;margin:0;background:#f9fafb;font-family:system-ui,-apple-system,sans-serif}
.container{display:flex;align-items:center;justify-content:center;min-height:100%;padding:20px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;max-width:420px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,0.05)}
.icon{font-size:56px;margin-bottom:16px;filter:grayscale(1);opacity:0.7}
.title{font-size:22px;font-weight:600;color:#1f2937;margin-bottom:12px}
.msg{font-size:14px;line-height:1.7;color:#6b7280;margin-bottom:24px}
.btn{display:inline-block;background:#3b82f6;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:500;transition:background 0.2s}
.btn:hover{background:#2563eb}
@media(prefers-color-scheme:dark){html,body{background:#0b0f19}.card{background:#1f2937;border-color:#374151}.title{color:#f3f4f6}.msg{color:#d1d5db}}
</style>
</head>
<body>
<div class="container">
<div class="card">
<div class="icon">🚫</div>
<div class="title">页面不可用</div>
<div class="msg">当前无网络连接，且该页面没有缓存。<br>请检查网络连接后再试。</div>
<a href="/" class="btn">返回首页</a>
</div>
</div>
</body>
</html>`;
    return new Response(html, {
        status: 503,
        headers: {'Content-Type': 'text/html; charset=utf-8'}
    });
}

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        try {
            self.skipWaiting();
        } catch (_) {
        }
        // 预缓存首页及关键静态资源，确保离线可用
        const pagesCache = await caches.open(CACHE_PAGES);
        const staticCache = await caches.open(CACHE_STATIC);
        try {
            await pagesCache.addAll(['/', '/index.html']);
        } catch (_) {
        }
        // 从首页提取 /assets 下的 .css/.js 并预缓存
        try {
            const resp = await fetch('/');
            if (resp && resp.ok) {
                const html = await resp.clone().text();
                const assets = extractAssetUrls(html);
                if (assets && assets.length) {
                    await Promise.all(assets.map(u => staticCache.add(new Request(u, {credentials: 'same-origin'}))));
                }
            }
        } catch (_) {
        }
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        const allow = new Set([CACHE_PAGES, CACHE_STATIC, CACHE_CDN, CACHE_API]);
        await Promise.all(keys.map(k => {
            if (!allow.has(k)) return caches.delete(k);
        }));
        try {
            if (self.registration.navigationPreload && self.registration.navigationPreload.enable) {
                await self.registration.navigationPreload.enable();
            }
        } catch (_) {
        }
        await self.clients.claim();
    })());
});

// Optional message channel to trigger skipWaiting from page
self.addEventListener('message', (event) => {
    const {type} = event.data || {};
    if (type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    try {
        const xpjax = (req.headers.get('x-pjax') || '').toLowerCase();
        const xr = (req.headers.get('x-requested-with') || '').toLowerCase();
        const acceptHdr = req.headers.get('accept') || '';
        const looksHtml = acceptHdr.includes('text/html');
        const isPjaxLike = (xpjax && xpjax !== 'false') || url.searchParams.has('_pjax') || (xr === 'xmlhttprequest' && looksHtml);
        // 检查是否为骨架屏绕过请求
        const isInstantBypass = req.headers.get('x-instant-bypass') === '1' || url.searchParams.has('_instant_bypass') || url.searchParams.has('no_instant');
        if (isPjaxLike || isInstantBypass) {
            // PJAX/骨架屏请求：使用网络优先策略，支持慢网缓存回退和网络不佳提示
            event.respondWith((async () => {
                const cache = await caches.open(CACHE_PAGES);
                let wasTimeout = false;
                let networkFailed = false;

                // 创建网络请求 Promise（带超时检测）
                const networkPromise = (async () => {
                    try {
                        const res = await fetch(req);
                        // 如果是真实页面（非骨架页），缓存它
                        if (res && res.ok) {
                            try {
                                const ct = res.headers.get('content-type') || '';
                                if (ct.includes('text/html')) {
                                    const copy = res.clone();
                                    const html = await copy.text();
                                    const isSkeleton = html.length < 2000 && html.indexOf('skeleton_page') !== -1;

                                    // 对于非骨架页，缓存到原始 URL（清除特殊参数）
                                    if (!isSkeleton) {
                                        try {
                                            const cleanUrl = new URL(url);
                                            cleanUrl.searchParams.delete('_instant_bypass');
                                            cleanUrl.searchParams.delete('_pjax');
                                            cleanUrl.searchParams.delete('t');
                                            cleanUrl.searchParams.delete('no_instant');
                                            const cleanReq = new Request(cleanUrl.toString(), {credentials: req.credentials});
                                            cache.put(cleanReq, res.clone());
                                        } catch (_) {
                                        }
                                    }
                                }
                            } catch (_) {
                            }
                        }
                        return {success: true, response: res};
                    } catch (err) {
                        return {success: false, response: null};
                    }
                })();

                // 慢网检测：2秒超时竞速
                const timeoutPromise = new Promise(resolve => {
                    setTimeout(() => resolve(null), SLOW_NETWORK_THRESHOLD_MS);
                });

                const raced = await Promise.race([networkPromise, timeoutPromise]);

                // 如果网络在2秒内返回
                if (raced) {
                    if (!raced.success) {
                        // 真正离线
                        networkFailed = true;
                    } else if (raced.response && raced.response.ok) {
                        // 直接使用网络结果
                        return raced.response;
                    }
                } else {
                    // 超时则标记慢网
                    wasTimeout = true;
                }

                // 网络超时或失败，尝试使用缓存

                // 尝试匹配原始 URL（清除所有特殊参数）
                let cached = null;
                try {
                    const cleanUrl = new URL(url);
                    cleanUrl.searchParams.delete('_instant_bypass');
                    cleanUrl.searchParams.delete('_pjax');
                    cleanUrl.searchParams.delete('t');
                    cleanUrl.searchParams.delete('no_instant');
                    const cleanReq = new Request(cleanUrl.toString(), {credentials: req.credentials});
                    cached = await cache.match(cleanReq, {ignoreVary: true, ignoreSearch: true});
                } catch (_) {
                }

                // 如果没找到，再尝试匹配带参数的请求
                if (!cached) {
                    cached = await cache.match(req, {ignoreVary: true, ignoreSearch: false});
                }

                if (cached) {
                    // 后台继续等待网络，更新缓存
                    event.waitUntil((async () => {
                        try {
                            const result = await networkPromise;
                            if (result.success && result.response && result.response.ok) {
                                const res = result.response;
                                const ct = res.headers.get('content-type') || '';
                                if (ct.includes('text/html')) {
                                    const copy = res.clone();
                                    const html = await copy.text();
                                    const isSkeleton = html.length < 2000 && html.indexOf('skeleton_page') !== -1;
                                    if (!isSkeleton) {
                                        try {
                                            const cleanUrl = new URL(url);
                                            cleanUrl.searchParams.delete('_instant_bypass');
                                            cleanUrl.searchParams.delete('_pjax');
                                            cleanUrl.searchParams.delete('t');
                                            cleanUrl.searchParams.delete('no_instant');
                                            const cleanReq = new Request(cleanUrl.toString(), {credentials: req.credentials});
                                            await cache.put(cleanReq, res.clone());
                                        } catch (_) {
                                        }
                                    }
                                }
                            }
                        } catch (_) {
                        }
                    })());

                    // 发送提示：根据网络状态选择离线或慢网
                    const noticePayload = networkFailed ? {
                        type: 'SHOW_STALE_NOTICE',
                        reason: 'offline_page_cache',
                        message: '当前离线，已为您展示该页面的缓存副本。'
                    } : {
                        type: 'SHOW_STALE_NOTICE',
                        reason: 'slow_network',
                        message: '网络欠佳，已为您展示缓存副本。'
                    };
                    event.waitUntil(notifyClient(event, noticePayload));
                    return cached;
                }

                // 无缓存：继续等待网络
                try {
                    const result = await networkPromise;
                    if (result.success && result.response) return result.response;
                    networkFailed = true;
                } catch (_) {
                    networkFailed = true;
                }

                // 网络完全失败且无缓存：PJAX 请求返回 200 状态 + 离线提示片段，普通请求返回完整页面
                if (isPjaxLike) {
                    event.waitUntil(notifyClient(event, {
                        type: 'SHOW_STALE_NOTICE',
                        reason: 'offline_no_cache',
                        message: '当前离线，且该页面没有缓存。'
                    }));
                    return new Response('<div style="padding:40px 20px;text-align:center;"><div style="font-size:48px;margin-bottom:16px;opacity:0.6">🚫</div><div style="font-size:18px;font-weight:600;color:#1f2937;margin-bottom:8px">页面不可用</div><div style="font-size:14px;color:#6b7280;line-height:1.6">当前离线，且该页面没有缓存。<br>请检查网络连接后再试。</div></div>', {
                        status: 200,
                        headers: {'Content-Type': 'text/html; charset=utf-8'}
                    });
                }
                return createOfflinePage();
            })());
            return;
        }
    } catch (_) {
    }

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
                        } catch (_) {
                        }
                        await notifyClient(event, {type: 'SW_DEBUG', stage: 'navigate_preload_used', url: req.url});
                        return pre;
                    }
                }
            } catch (_) {
            }
            event.waitUntil(notifyClient(event, {type: 'SW_DEBUG', stage: 'navigate_intercept', url: req.url}));

            return networkFirst(req, event, true);
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

    // API 请求：网络优先 + 超时缓存回退
    if (isSameOrigin(url) && isApiPath(url)) {
        event.respondWith(apiCacheStrategy(req, event));
        return;
    }

    // Default: passthrough
    // You could optionally add Stale-While-Revalidate for other GETs
});

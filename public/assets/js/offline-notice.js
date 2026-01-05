(function () {
  const TOAST_TTL = 4500; // ms
    const INDICATOR_ID = 'sw-offline-indicator';

  function ensureHost() {
    let host = document.getElementById('sw-toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'sw-toast-host';
      host.className = 'fixed z-[9999] top-4 right-4 flex flex-col gap-3';
      document.body.appendChild(host);
    }
    return host;
  }

  function showToast(opts) {
    const { title = '提示', message = '', tone = 'info' } = opts || {};
    const host = ensureHost();
    const base =
      'w-[22rem] max-w-[92vw] shadow-xl rounded-lg border px-4 py-3 ring-1 transition-all duration-300 ' +
      'backdrop-blur bg-white/90 dark:bg-slate-800/90 ring-black/5 dark:ring-white/10';
    const toneMap = {
      info: 'border-blue-200 bg-blue-50/70 text-slate-900 dark:text-slate-100',
      warn: 'border-amber-200 bg-amber-50/70 text-slate-900 dark:text-slate-100',
      error:'border-rose-200 bg-rose-50/70 text-slate-900 dark:text-slate-100'
    };
    const el = document.createElement('div');
    el.className = (toneMap[tone] || toneMap.info) + ' ' + base + ' opacity-0 translate-y-2';
    el.innerHTML = [
      '<div class="flex items-start gap-3">',
        '<div class="shrink-0 mt-0.5">',
          '<span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-black/5 dark:bg-white/10">',
            '<svg class="h-4 w-4 text-black/60 dark:text-white/70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">',
              '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.5a.75.75 0 10-1.5 0 .75.75 0 001.5 0zM9 8.75A.75.75 0 019.75 8h.5a.75.75 0 01.75.75V13a.75.75 0 01-.75.75H9.75A.75.75 0 019 13V8.75z" clip-rule="evenodd" />',
            '</svg>',
          '</span>',
        '</div>',
        '<div class="min-w-0 flex-1">',
          '<div class="font-semibold leading-6 truncate">', title, '</div>',
          '<div class="text-sm leading-5 text-slate-600 dark:text-slate-300 mt-0.5">', message, '</div>',
        '</div>',
        '<button type="button" aria-label="关闭" class="ml-2 shrink-0 text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">',
          '<svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">',
            '<path fill-rule="evenodd" d="M4.22 4.22a.75.75 0 011.06 0L10 8.94l4.72-4.72a.75.75 0 111.06 1.06L11.06 10l4.72 4.72a.75.75 0 11-1.06 1.06L10 11.06l-4.72 4.72a.75.75 0 11-1.06-1.06L8.94 10 4.22 5.28a.75.75 0 010-1.06z" clip-rule="evenodd"/>',
          '</svg>',
        '</button>',
      '</div>'
    ].join('');
    const closeBtn = el.querySelector('button');
    closeBtn.addEventListener('click', () => dismiss());
    host.appendChild(el);
    requestAnimationFrame(() => {
      el.classList.remove('opacity-0', 'translate-y-2');
      el.classList.add('opacity-100', 'translate-y-0');
    });

    const timer = setTimeout(dismiss, TOAST_TTL);
    function dismiss() {
      clearTimeout(timer);
      el.classList.remove('opacity-100', 'translate-y-0');
      el.classList.add('opacity-0', 'translate-y-2');
      setTimeout(() => el.remove(), 250);
    }
  }

    function ensureIndicator() {
        let el = document.getElementById(INDICATOR_ID);
        if (!el) {
            el = document.createElement('div');
            el.id = INDICATOR_ID;
            el.className = 'fixed left-4 bottom-4 px-3 py-1.5 rounded-full text-sm font-medium bg-amber-500 text-white shadow-lg transition-opacity duration-200 pointer-events-none';
            el.style.opacity = '0';
            el.style.zIndex = '9998';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            el.textContent = '离线模式';
            document.body.appendChild(el);
        }
        return el;
    }

    function setIndicatorVisible(visible) {
        const el = ensureIndicator();
        el.style.opacity = visible ? '1' : '0';
    }

    // 共享状态变量
    let lastOnlineState = navigator.onLine;
    let isInitializing = true;
    let hasInitialized = false;
    let swDetectedOffline = false;
    let lastSwNoticeTime = 0;
    let isEdgeMode = false;
    let edgeDegradedMode = false;
    let edgeDegradedDuration = 0;

    // 页面加载后 500ms 内忽略网络状态变化事件，避免虚假提示
    setTimeout(() => {
        isInitializing = false;
        hasInitialized = true;
        console.debug('[NetworkStatus] Initialization complete, current state:', navigator.onLine ? 'online' : 'offline');
    }, 500);

    function updateOnlineStatus(evt) {
        const online = navigator.onLine;
        const stateChanged = lastOnlineState !== online;

        console.debug('[NetworkStatus] updateOnlineStatus called:', {
            hasEvent: !!evt,
            isInitializing,
            hasInitialized,
            online,
            lastOnlineState,
            stateChanged,
            swDetectedOffline,
            isEdgeMode,
            edgeDegradedMode
        });

        setIndicatorVisible(!online);

        if (hasInitialized && evt && stateChanged) {
            console.debug('[NetworkStatus] Browser detected network state change');
            if (online) {
                swDetectedOffline = false;
                showToast({title: '已恢复网络', message: '已切回在线模式。', tone: 'info'});
            } else {
                swDetectedOffline = true;
                showToast({title: '离线模式', message: '当前离线，页面将使用缓存内容（如有）。', tone: 'warn'});
            }
        }

        lastOnlineState = online;
    }

    function checkEdgeMode() {
        try {
            const edgeModeHeader = document.querySelector('meta[name="x-edge-mode"]')?.content;
            const datacenterStatusHeader = document.querySelector('meta[name="x-datacenter-status"]')?.content;
            const serviceDegradedHeader = document.querySelector('meta[name="x-service-degraded"]')?.content;
            const degradedDurationHeader = document.querySelector('meta[name="x-degraded-duration"]')?.content;

            isEdgeMode = edgeModeHeader === 'true';
            edgeDegradedMode = serviceDegradedHeader === 'true';
            edgeDegradedDuration = parseInt(degradedDurationHeader) || 0;

            if (isEdgeMode) {
                console.debug('[EdgeMode] Edge mode detected');
                if (edgeDegradedMode) {
                    showEdgeDegradedNotice();
                } else {
                    hideEdgeDegradedNotice();
                }
            }
        } catch (e) {
            console.debug('[EdgeMode] Failed to check edge mode:', e);
        }
    }

    function showEdgeDegradedNotice() {
        const duration = Math.floor(edgeDegradedDuration / 60);
        const durationText = duration > 0 ? `已持续 ${duration} 分钟` : '刚刚开始';
        showToast({
            title: '服务降级',
            message: `当前使用边缘节点缓存提供服务，${durationText}。部分功能可能受限。`,
            tone: 'warn'
        });
    }

    function hideEdgeDegradedNotice() {
        showToast({
            title: '服务已恢复',
            message: '已连接到数据中心，服务恢复正常。',
            tone: 'info'
        });
    }

  function handleSWMessage(event) {
    const data = event.data || {};
    if (data.type === 'SHOW_STALE_NOTICE') {
      const reason = data.reason || 'info';
      let title, message, tone;
        const now = Date.now();

        // 检测是否是离线相关的通知
        const isOfflineNotice = ['offline_fallback', 'offline_page_cache', 'offline_no_cache', 'offline_api'].includes(reason);

      switch (reason) {
        case 'offline_fallback':
        case 'offline_page_cache':
          case 'offline_no_cache':
          case 'offline_api':
              // 如果 SW 检测到离线，且距离上次通知超过 5 秒，显示离线通知
              if (!swDetectedOffline || (now - lastSwNoticeTime > 5000)) {
                  title = '离线模式';
                  tone = 'warn';
                  message = '当前离线，页面将使用缓存内容（如有）。';
                  swDetectedOffline = true;
                  lastSwNoticeTime = now;
                  showToast({title, message, tone});
                  console.debug('[NetworkStatus] SW detected offline');
              }
              return; // 不再显示额外的缓存副本通知
          case 'slow_network':
          case 'slow_api':
              title = '网络欠佳';
              tone = 'info';
              message = data.message || '网络较慢，已为您展示缓存副本。';
              // 如果之前是离线状态，现在能访问网络（虽然慢），说明恢复了
              if (swDetectedOffline && (now - lastSwNoticeTime > 3000)) {
                  swDetectedOffline = false;
                  lastSwNoticeTime = now;
                  showToast({title: '已恢复网络', message: '已切回在线模式。', tone: 'info'});
                  console.debug('[NetworkStatus] SW detected back online');
                  return;
              }
              break;
        default:
          title = '网络欠佳';
          tone = 'info';
          message = data.message || '网络较慢，已为您展示缓存副本。';
      }
      showToast({ title, message, tone });
    } else if (data.type === 'NEW_CONTENT_AVAILABLE') {
        // 新内容可用通知
        showToast({
            title: '内容已更新',
            message: data.message || '页面内容已更新，下次刷新时将显示最新内容',
            tone: 'info'
        });
    } else if (data.type === 'SW_DEBUG') {
      // 调试输出，可按需移除
      console.debug('[SW_DEBUG]', data);
    }
  }

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', handleSWMessage);
      navigator.serviceWorker.addEventListener('controllerchange', function () {
          showToast({title: '页面已更新', message: '已切换到最新版本。', tone: 'info'});
      });
  }

    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            updateOnlineStatus();
            checkEdgeMode();
        });
    } else {
        updateOnlineStatus();
        checkEdgeMode();
  }
})();

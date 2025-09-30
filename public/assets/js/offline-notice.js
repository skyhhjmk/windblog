(function () {
  const TOAST_TTL = 4500; // ms

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

  function handleSWMessage(event) {
    const data = event.data || {};
    if (data.type === 'SHOW_STALE_NOTICE') {
      const reason = data.reason || 'info';
      let title, message, tone;
      switch (reason) {
        case 'offline_fallback':
          title = '离线模式';
          tone = 'warn';
          message = data.message || '当前离线，已为您展示缓存的首页副本。';
          break;
        case 'offline_page_cache':
          title = '离线模式';
          tone = 'warn';
          message = data.message || '当前离线，已为您展示该页面的缓存副本。';
          break;
        default:
          title = '网络欠佳';
          tone = 'info';
          message = data.message || '网络较慢，已为您展示缓存副本。';
      }
      showToast({ title, message, tone });
    } else if (data.type === 'SW_DEBUG') {
      // 调试输出，可按需移除
      console.debug('[SW_DEBUG]', data);
    }
  }

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', handleSWMessage);
  }
})();
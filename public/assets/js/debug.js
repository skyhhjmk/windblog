document.addEventListener('DOMContentLoaded', function() {
    // 创建调试图标
    const debugIcon = document.createElement('div');
    debugIcon.id = 'debug-icon';
    debugIcon.className = 'fixed bottom-5 right-5 w-12 h-12 bg-blue-500 text-white rounded-full flex justify-center items-center cursor-pointer z-50 shadow-lg hover:bg-blue-600 transition-all duration-300 hover:scale-110';
    debugIcon.innerHTML = '<i class="fas fa-wrench"></i>';
    document.body.appendChild(debugIcon);
    // 耗时徽章
    const badge = document.createElement('span');
    badge.id = 'debug-badge';
    badge.className = 'absolute -top-2 -right-2 bg-red-500 text-white text-xs px-2 py-0.5 rounded-full shadow';
    badge.textContent = '';
    debugIcon.style.position = 'fixed';
    debugIcon.appendChild(badge);
    // 页面加载完成后立即显示耗时（若有注入数据）
    (function () {
        const dbg = window.__DEBUG_TOOLKIT_DATA || null;
        if (!dbg || !dbg.timing || typeof dbg.timing.total_ms === 'undefined') return;
        const ms = dbg.timing.total_ms;
        const badgeEl = document.getElementById('debug-badge');
        if (!badgeEl) return;
        badgeEl.textContent = `${ms}ms`;
        badgeEl.className = 'absolute -top-2 -right-2 text-white text-xs px-2 py-0.5 rounded-full shadow ' + (ms > 500 ? 'bg-red-500' : 'bg-green-500');
    })();
    
    // 创建调试面板和遮罩
    const overlay = document.createElement('div');
    overlay.id = 'debug-overlay';
    overlay.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 hidden';
    document.body.appendChild(overlay);
    
    const debugPanel = document.createElement('div');
    debugPanel.id = 'debug-panel';
    debugPanel.className = 'hidden fixed inset-0 z-50 flex items-center justify-center p-4';
    debugPanel.innerHTML = `
        <div id="debug-panel-container" class="w-full max-w-6xl h-5/6 bg-white rounded-xl shadow-2xl flex flex-col overflow-hidden">
            <div id="debug-panel-header" class="bg-blue-500 text-white p-4 flex justify-between items-center">
                <div id="debug-panel-title" class="text-xl font-bold">调试工具箱</div>
                <button id="debug-panel-close" class="text-white text-2xl hover:text-gray-200 focus:outline-none">&times;</button>
            </div>
            <div id="debug-panel-tabs" class="flex bg-gray-100 border-b border-gray-200">
                <div class="debug-tab active px-6 py-3 cursor-pointer font-medium text-blue-500 border-b-2 border-blue-500" data-tab="info">服务器信息</div>
                <div class="debug-tab px-6 py-3 cursor-pointer font-medium text-gray-600 hover:text-gray-900" data-tab="request">请求信息</div>
                <div class="debug-tab px-6 py-3 cursor-pointer font-medium text-gray-600 hover:text-gray-900" data-tab="response">响应信息</div>
                <div class="debug-tab px-6 py-3 cursor-pointer font-medium text-gray-600 hover:text-gray-900" data-tab="http">HTTP请求工具</div>
            </div>
            <div id="debug-panel-content" class="flex-1 overflow-y-auto p-6">
                <div class="debug-tab-content active" id="info-tab">
                    <div class="debug-section mb-6">
                        <div class="debug-section-title font-bold mb-3 text-blue-800">服务器信息</div>
                        <div id="server-info" class="debug-info bg-gray-50 border border-gray-200 rounded p-4 font-mono whitespace-pre-wrap overflow-x-auto max-h-80 overflow-y-auto"></div>
                    </div>
                    <div class="debug-section mb-6">
                        <div class="debug-section-title font-bold mb-3 text-blue-800">耗时详情</div>
                        <div id="timing-info" class="debug-info bg-gray-50 border border-gray-200 rounded p-4 font-mono whitespace-pre-wrap overflow-x-auto max-h-40 overflow-y-auto">暂无耗时数据</div>
                    </div>
                    <div class="debug-section mb-6">
                        <div class="debug-section-title font-bold mb-3 text-blue-800">日志</div>
                        <div id="logs-info" class="debug-info bg-gray-50 border border-gray-200 rounded p-4 font-mono whitespace-pre-wrap overflow-x-auto max-h-80 overflow-y-auto">暂无日志</div>
                    </div>
                </div>
                
                <div class="debug-tab-content hidden" id="request-tab">
                    <div class="debug-section mb-6">
                        <div class="debug-section-title font-bold mb-3 text-blue-800">请求信息</div>
                        <div id="request-info" class="debug-info bg-gray-50 border border-gray-200 rounded p-4 font-mono whitespace-pre-wrap overflow-x-auto max-h-80 overflow-y-auto"></div>
                    </div>
                </div>
                
                <div class="debug-tab-content hidden" id="response-tab">
                    <div class="debug-section mb-6">
                        <div class="debug-section-title font-bold mb-3 text-blue-800">响应信息</div>
                        <div id="response-info" class="debug-info bg-gray-50 border border-gray-200 rounded p-4 font-mono whitespace-pre-wrap overflow-x-auto max-h-80 overflow-y-auto"></div>
                    </div>
                </div>
                
                <div class="debug-tab-content hidden" id="http-tab">
                    <div class="debug-section mb-6">
                        <div class="debug-section-title font-bold mb-3 text-blue-800">HTTP请求工具</div>
                        <div id="http-request-form" class="bg-gray-50 border border-gray-200 rounded p-4 mb-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">请求方法</label>
                                    <select id="http-method" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="GET">GET</option>
                                        <option value="POST">POST</option>
                                        <option value="PUT">PUT</option>
                                        <option value="DELETE">DELETE</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
                                    <input type="text" id="http-url" placeholder="请输入URL，例如: /api/posts" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">请求体 (仅POST/PUT)</label>
                                <textarea id="http-body" placeholder="请求体" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                            <button id="http-send" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">发送请求</button>
                        </div>
                        <div class="debug-section-title font-bold mb-3 text-blue-800">响应结果</div>
                        <div id="http-request-result" class="bg-gray-50 border border-gray-200 rounded p-4 font-mono whitespace-pre-wrap overflow-x-auto max-h-80 overflow-y-auto">发送请求后将在此显示结果</div>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(debugPanel);
    
    // 绑定事件
    debugIcon.addEventListener('click', function() {
        overlay.classList.remove('hidden');
        debugPanel.classList.remove('hidden');
        loadDebugInfo();
    });
    
    overlay.addEventListener('click', function() {
        overlay.classList.add('hidden');
        debugPanel.classList.add('hidden');
    });
    
    document.getElementById('debug-panel-close').addEventListener('click', function() {
        overlay.classList.add('hidden');
        debugPanel.classList.add('hidden');
    });
    
    // 标签页切换
    document.querySelectorAll('.debug-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            // 移除所有活动状态
            document.querySelectorAll('.debug-tab').forEach(t => {
                t.classList.remove('text-blue-500', 'border-b-2', 'border-blue-500');
                t.classList.add('text-gray-600');
            });
            document.querySelectorAll('.debug-tab-content').forEach(c => c.classList.add('hidden'));
            
            // 添加活动状态到当前标签
            this.classList.remove('text-gray-600');
            this.classList.add('text-blue-500', 'border-b-2', 'border-blue-500');
            const tabId = this.getAttribute('data-tab');
            const tabContent = document.getElementById(tabId + '-tab');
            if (tabContent) {
                tabContent.classList.remove('hidden');
                tabContent.classList.add('block');
            }
        });
    });
    
    // HTTP请求工具
    document.getElementById('http-send').addEventListener('click', function() {
        const method = document.getElementById('http-method').value;
        const url = document.getElementById('http-url').value;
        const body = document.getElementById('http-body').value;
        
        if (!url) {
            alert('请输入URL');
            return;
        }
        
        const resultElement = document.getElementById('http-request-result');
        resultElement.textContent = '请求中...';
        
        // 创建请求
        const xhr = new XMLHttpRequest();
        xhr.open(method, url);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/json');
        // 自动附带调试头
        xhr.setRequestHeader('X-Debug-Toolkit', '1');
        
        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                resultElement.textContent = `状态码: ${xhr.status}\n响应:\n${xhr.responseText}`;
            } else {
                resultElement.textContent = `错误: ${xhr.status} - ${xhr.statusText}`;
            }
        };
        
        xhr.onerror = function() {
            resultElement.textContent = '请求失败';
        };
        
        // 发送请求
        if ((method === 'POST' || method === 'PUT') && body) {
            xhr.send(body);
        } else {
            xhr.send();
        }
    });
    
    // 加载调试信息（优先使用后端注入的 window.__DEBUG_TOOLKIT_DATA）
    function loadDebugInfo() {
        const dbg = window.__DEBUG_TOOLKIT_DATA || null;

        const setJSON = (elId, obj, fallbackText) => {
            const el = document.getElementById(elId);
            if (!el) return;
            if (obj) {
                el.textContent = JSON.stringify(obj, null, 2);
            } else if (fallbackText) {
                el.textContent = fallbackText;
            }
        };

        if (dbg) {
            // 请求/响应/服务器信息（含扩展与耗时）
            setJSON('request-info', dbg.request, '无请求信息');
            setJSON('response-info', dbg.response, '无响应信息');

            const serverPayload = {
                timing: dbg.timing || {},
                trigger: dbg.trigger || {}
            };
            setJSON('server-info', serverPayload, '无服务器信息');

            // 填充耗时详情（总耗时 + handler占位）
            (function () {
                const el = document.getElementById('timing-info');
                if (!el) return;
                const total = (dbg.timing && typeof dbg.timing.total_ms !== 'undefined') ? dbg.timing.total_ms : null;
                const handler = (dbg.timing && typeof dbg.timing.handler_ms !== 'undefined') ? dbg.timing.handler_ms : null;
                const lines = [];
                lines.push('total_ms: ' + (total !== null ? total + ' ms' : '未知'));
                lines.push('handler_ms: ' + (handler !== null ? handler + ' ms' : '占位'));
                el.textContent = lines.join('\\n');
            })();

            // 更新耗时徽章（仅展示耗时，不包含扩展状态）
            try {
                const ms = (dbg.timing && typeof dbg.timing.total_ms !== 'undefined') ? dbg.timing.total_ms : null;
                const badgeEl = document.getElementById('debug-badge');
                if (badgeEl) {
                    badgeEl.textContent = (ms !== null) ? `${ms}ms` : '';
                    badgeEl.className = 'absolute -top-2 -right-2 text-white text-xs px-2 py-0.5 rounded-full shadow ' + ((ms !== null && ms > 500) ? 'bg-red-500' : 'bg-green-500');
                }
            } catch (e) {}

            // 日志拉取（如配置了端点），1秒超时，附带触发头
            if (dbg.logs && dbg.logs.endpoint) {
                const controller = new AbortController();
                const tid = setTimeout(() => controller.abort(), 1000);
                fetch(dbg.logs.endpoint, {
                    headers: {
                        'X-Debug-Toolkit': '1',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    signal: controller.signal
                })
                .then(r => r.ok ? r.json() : Promise.reject(new Error(r.status + ' ' + r.statusText)))
                .then(data => {
                    clearTimeout(tid);
                    setJSON('logs-info', data, '暂无日志数据');
                })
                .catch(err => {
                    clearTimeout(tid);
                    const el = document.getElementById('logs-info');
                    if (el) el.textContent = '获取日志失败: ' + err.message;
                });
            } else {
                setJSON('logs-info', null, '未配置日志端点');
            }
        } else {
            // 回退：兼容旧接口
            Promise.allSettled([
                fetch('/debug/server-info').then(r => r.json()),
                fetch('/debug/request-info').then(r => r.json()),
                fetch('/debug/response-info').then(r => r.json())
            ])
            .then(([s, q, p]) => {
                setJSON('server-info', s.status === 'fulfilled' ? s.value : null, s.status === 'rejected' ? ('获取服务器信息失败: ' + s.reason) : '无服务器信息');
                setJSON('request-info', q.status === 'fulfilled' ? q.value : null, q.status === 'rejected' ? ('获取请求信息失败: ' + q.reason) : '无请求信息');
                setJSON('response-info', p.status === 'fulfilled' ? p.value : null, p.status === 'rejected' ? ('获取响应信息失败: ' + p.reason) : '无响应信息');
            });
        }
    }
    
    // 使元素可拖动的函数
    function makeDraggable(element, handle) {
        let pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
        
        if (handle) {
            handle.onmousedown = dragMouseDown;
        } else {
            element.onmousedown = dragMouseDown;
        }
        
        function dragMouseDown(e) {
            e = e || window.event;
            e.preventDefault();
            // 获取鼠标位置
            pos3 = e.clientX;
            pos4 = e.clientY;
            document.onmouseup = closeDragElement;
            document.onmousemove = elementDrag;
        }
        
        function elementDrag(e) {
            e = e || window.event;
            e.preventDefault();
            // 计算新位置
            pos1 = pos3 - e.clientX;
            pos2 = pos4 - e.clientY;
            pos3 = e.clientX;
            pos4 = e.clientY;
            // 设置元素的新位置
            element.style.top = (element.offsetTop - pos2) + "px";
            element.style.left = (element.offsetLeft - pos1) + "px";
        }
        
        function closeDragElement() {
            // 停止移动
            document.onmouseup = null;
            document.onmousemove = null;
        }
    }
});
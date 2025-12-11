// Rainyun控制台JavaScript代码
(function () {
    // 本地存储键常量
    const LS_KEY_BASE = 'rainyun_api_base';
    const LS_KEY_KEY = 'rainyun_api_key';

    // DOM选择器函数
    const $ = selector => document.querySelector(selector);
    const $$ = selector => document.querySelectorAll(selector);

    // 配置获取/设置函数
    function getCfg() {
        const base = localStorage.getItem(LS_KEY_BASE) || 'https://api.rainyun.com';
        const key = localStorage.getItem(LS_KEY_KEY) || '';
        return {baseUrl: base, apiKey: key};
    }

    function setCfg(baseUrl, apiKey) {
        localStorage.setItem(LS_KEY_BASE, baseUrl);
        localStorage.setItem(LS_KEY_KEY, apiKey);
    }

    // 密钥掩码处理
    function maskKey(key) {
        if (!key) return '';
        if (key.length <= 8) return '*'.repeat(key.length);
        return key.substring(0, 4) + '*'.repeat(key.length - 8) + key.substring(key.length - 4);
    }

    // 标签页切换逻辑
    function switchTab(tabName) {
        // 隐藏所有内容区域
        $$('.tab-content').forEach(el => el.classList.add('hidden'));
        $$('.nav-tab').forEach(el => {
            el.classList.remove('border-blue-600', 'text-blue-600');
            el.classList.add('border-transparent', 'text-gray-500');
        });

        // 显示选中的内容
        $(`#tab-${tabName}`).classList.remove('hidden');
        const activeTab = $(`[data-tab="${tabName}"]`);
        if (activeTab) {
            activeTab.classList.remove('border-transparent', 'text-gray-500');
            activeTab.classList.add('border-blue-600', 'text-blue-600');
        }
    }

    // API服务对象
    const APIS = {
        base: () => getCfg().baseUrl,

        // 获取应用列表
        listApps: async function (page = 1, size = 20) {
            return new Promise((resolve, reject) => {
                try {
                    const xhr = new XMLHttpRequest();
                    const url = new URL(`${this.base()}/product/rca/appstore/`);
                    url.searchParams.set('page', page);
                    url.searchParams.set('size', size);

                    xhr.open('GET', url.toString(), true);
                    const {apiKey} = getCfg();
                    if (apiKey) xhr.setRequestHeader('x-api-key', apiKey);

                    xhr.onload = function () {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                resolve(response);
                            } catch (e) {
                                reject(new Error('解析响应失败'));
                            }
                        } else {
                            reject(new Error(`HTTP ${xhr.status}`));
                        }
                    };

                    xhr.onerror = () => reject(new Error('网络请求失败'));
                    xhr.send();
                } catch (e) {
                    reject(e);
                }
            });
        },

        // 获取应用详情
        getAppDetail: async function (id) {
            return new Promise((resolve, reject) => {
                try {
                    const xhr = new XMLHttpRequest();
                    xhr.open('GET', `${this.base()}/product/rca/appstore/${id}`, true);
                    const {apiKey} = getCfg();
                    if (apiKey) xhr.setRequestHeader('x-api-key', apiKey);

                    xhr.onload = function () {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                resolve(response);
                            } catch (e) {
                                reject(new Error('解析响应失败'));
                            }
                        } else {
                            reject(new Error(`HTTP ${xhr.status}`));
                        }
                    };

                    xhr.onerror = () => reject(new Error('网络请求失败'));
                    xhr.send();
                } catch (e) {
                    reject(e);
                }
            });
        },

        // 创建应用
        createApp: async function (data) {
            return new Promise((resolve, reject) => {
                try {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', `${this.base()}/product/rca/appstore/`, true);
                    const {apiKey} = getCfg();
                    if (apiKey) xhr.setRequestHeader('x-api-key', apiKey);
                    xhr.setRequestHeader('Content-Type', 'application/json');

                    xhr.onload = function () {
                        if (xhr.status === 200 || xhr.status === 201) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.code === 200 && response.data) {
                                    resolve(response.data);
                                } else {
                                    reject(new Error(response.message || '创建失败'));
                                }
                            } catch (e) {
                                reject(new Error('解析响应失败'));
                            }
                        } else {
                            reject(new Error(`HTTP ${xhr.status}`));
                        }
                    };

                    xhr.onerror = () => reject(new Error('网络请求失败'));
                    xhr.send(JSON.stringify(data));
                } catch (e) {
                    reject(e);
                }
            });
        }
    };

// 配置收集器
    window.RainyunConfigCollector = {
        collectConfig: function () {
            const version = document.getElementById('ry-version')?.value?.trim() || 'v1.0.0';

            // 收集选项配置
            const options = [];
            const optionElements = document.querySelectorAll('[id^="ry-option-"]');

            optionElements.forEach(optionEl => {
                const labelEl = optionEl.querySelector('.ry-option-label');
                const envKeyEl = optionEl.querySelector('.ry-option-env-key');
                const typeEl = optionEl.querySelector('.ry-option-type');
                const defaultEl = optionEl.querySelector('.ry-option-default');
                const ruleEl = optionEl.querySelector('.ry-option-rule');
                const requiredEl = optionEl.querySelector('.ry-option-required');
                const disabledEl = optionEl.querySelector('.ry-option-disabled');
                const randomEl = optionEl.querySelector('.ry-option-random');

                if (labelEl && envKeyEl && typeEl && defaultEl && ruleEl && requiredEl && disabledEl && randomEl) {
                    options.push({
                        label: labelEl.value.trim(),
                        env_key: envKeyEl.value.trim(),
                        type: typeEl.value,
                        default: defaultEl.value.trim(),
                        rule: ruleEl.value,
                        required: requiredEl.checked,
                        disabled: disabledEl.checked,
                        random: randomEl.checked,
                        value: defaultEl.value.trim(),
                        values: null
                    });
                }
            });

            // 收集容器配置
            const containers = [];
            const containerElements = document.querySelectorAll('[id^="ry-container-"]');

            containerElements.forEach((containerEl, index) => {
                const commandEl = containerEl.querySelector('.ry-container-command');
                const argsEl = containerEl.querySelector('.ry-container-args');
                const command = commandEl ? commandEl.value.trim() : '';
                const args = argsEl ? argsEl.value.trim() : '';

                // 收集脚本设定
                const scripts = {
                    install: containerEl.querySelector('.ry-container-script-install')?.value || '',
                    install_image: containerEl.querySelector('.ry-container-script-install-image')?.value || '',
                    post_start: containerEl.querySelector('.ry-container-script-post-start')?.value || '',
                    pre_stop: containerEl.querySelector('.ry-container-script-pre-stop')?.value || ''
                };

                // 收集环境变量
                const env = [];
                const envBlocks = containerEl.querySelectorAll('[id^="ry-container-tab-env-"] .flex.gap-2');
                envBlocks.forEach(envBlock => {
                    const keyInput = envBlock.querySelector('input:nth-child(1)');
                    const valueInput = envBlock.querySelector('input:nth-child(2)');
                    if (keyInput && valueInput) {
                        const key = keyInput.value.trim();
                        const value = valueInput.value.trim();
                        if (key) {
                            env.push({key, value});
                        }
                    }
                });

                // 收集配置文件
                const configMaps = [];
                const configBlocks = containerEl.querySelectorAll('[id^="ry-container-tab-configs-"] .bg-white.p-3.rounded-lg.border.border-gray-200');
                configBlocks.forEach(configBlock => {
                    const fileNameEl = configBlock.querySelector('input:nth-child(1)');
                    const containerPathEl = configBlock.querySelector('input:nth-child(2)');
                    const contentEl = configBlock.querySelector('textarea');

                    if (fileNameEl && containerPathEl && contentEl) {
                        const fileName = fileNameEl.value.trim();
                        const containerPath = containerPathEl.value.trim();
                        const content = contentEl.value.trim();
                        if (fileName && containerPath) {
                            configMaps.push({file_name: fileName, container_path: containerPath, content});
                        }
                    }
                });

                // 收集持久化卷
                const volumeMounts = [];
                const volumeBlocks = containerEl.querySelectorAll('[id^="ry-container-tab-volumes-"] .bg-white.p-3.rounded-lg.border.border-gray-200');
                volumeBlocks.forEach(volumeBlock => {
                    const nameEl = volumeBlock.querySelector('input:nth-child(1)');
                    const mountPathEl = volumeBlock.querySelector('input:nth-child(2)');

                    if (nameEl && mountPathEl) {
                        const name = nameEl.value.trim();
                        const mountPath = mountPathEl.value.trim();
                        if (name && mountPath) {
                            volumeMounts.push({name, mount_path: mountPath});
                        }
                    }
                });

                // 收集服务配置
                const services = [];
                const serviceBlocks = containerEl.querySelectorAll('[id^="ry-container-tab-services-"] .bg-white.p-3.rounded-lg.border.border-gray-200');
                serviceBlocks.forEach(serviceBlock => {
                    const nameEl = serviceBlock.querySelector('input:nth-child(1)');
                    const labelEl = serviceBlock.querySelector('input:nth-child(2)');
                    const typeEl = serviceBlock.querySelector('select:nth-child(1)');
                    const internalPortEl = serviceBlock.querySelector('input:nth-child(3)');
                    const externalPortEl = serviceBlock.querySelector('input:nth-child(4)');
                    const protocolEl = serviceBlock.querySelector('select:nth-child(2)');

                    if (nameEl && labelEl && typeEl && internalPortEl && externalPortEl && protocolEl) {
                        const name = nameEl.value.trim();
                        const label = labelEl.value.trim();
                        const type = typeEl.value;
                        const internalPort = internalPortEl.value.trim();
                        const externalPort = externalPortEl.value.trim();
                        const protocol = protocolEl.value;
                        if (name && label && internalPort && protocol) {
                            services.push({
                                name,
                                label,
                                type,
                                internal_port: internalPort,
                                external_port: externalPort,
                                protocol
                            });
                        }
                    }
                });

                // 获取容器核心配置
                const nameEl = containerEl.querySelector('.ry-container-name');
                const imageEl = containerEl.querySelector('.ry-container-image');
                const cpuEl = containerEl.querySelector('.ry-container-cpu');
                const memoryEl = containerEl.querySelector('.ry-container-memory');

                const containerName = nameEl ? nameEl.value.trim() || `container-${index}` : `container-${index}`;
                const image = imageEl ? imageEl.value.trim() || 'nginx:latest' : 'nginx:latest';
                const cpu = cpuEl ? parseInt(cpuEl.value) || 500 : 500;
                const memory = memoryEl ? parseInt(memoryEl.value) || 256 : 256;

                const container = {
                    id: Math.floor(Math.random() * 10000),
                    release_id: window.ryGeneratedConfig?.release_id || 0,
                    name: containerName,
                    image: image,
                    env: env,
                    config_maps: configMaps,
                    resource_request: {
                        min_cpu: cpu,
                        min_memory: memory
                    },
                    volume_mounts: volumeMounts,
                    command: command ? command.split(' ') : [],
                    args: args ? args.split(' ') : [],
                    scripts: scripts,
                    services: services,
                    startup_health_check: null
                };

                containers.push(container);
            });

            return {
                version: version || 'v1.0.0',
                container_templates: containers,
                options: options,
                release_id: window.ryGeneratedConfig?.release_id || 0
            };
        }
    };

// UI 控制器
    window.RainyunUI = {
        showTab: function (tabName) {
            // 隐藏所有视图
            document.querySelectorAll('.ry-view').forEach(view => {
                view.classList.add('hidden');
            });

            // 显示指定视图
            const targetView = document.getElementById(`ry-view-${tabName}`);
            if (targetView) {
                targetView.classList.remove('hidden');
            }

            // 更新导航状态
            document.querySelectorAll('.ry-nav-btn').forEach(btn => {
                btn.classList.remove('bg-blue-100', 'text-blue-700');
                btn.classList.add('hover:bg-gray-100', 'text-gray-700');
            });

            const activeBtn = document.querySelector(`[data-tab="${tabName}"]`);
            if (activeBtn) {
                activeBtn.classList.remove('hover:bg-gray-100', 'text-gray-700');
                activeBtn.classList.add('bg-blue-100', 'text-blue-700');
            }

            // 更新面包屑
            document.querySelectorAll('.ry-bc').forEach(bc => {
                bc.classList.add('hidden');
            });

            const targetBc = document.querySelector(`[data-ry-bc="${tabName}"]`);
            if (targetBc) {
                targetBc.classList.remove('hidden');
            }
        },

        updateKeyStatus: function (status, message) {
            const statusEl = document.getElementById('ry-key-status');
            if (statusEl) {
                statusEl.textContent = message;
                statusEl.className = `text-sm ${status === 'success' ? 'text-green-600' : 'text-red-600'}`;
            }
        },

        showMessage: function (elementId, message, type = 'info') {
            const el = document.getElementById(elementId);
            if (el) {
                el.textContent = message;
                el.className = `text-sm ${type === 'error' ? 'text-red-600' : type === 'success' ? 'text-green-600' : 'text-gray-600'}`;
            }
        }
    };

// 初始化函数
    document.addEventListener('DOMContentLoaded', function () {
        // 初始化设置
        const apiBase = localStorage.getItem('rainyun_apiBase');
        const apiKey = localStorage.getItem('rainyun_apiKey');

        if (apiBase) {
            document.getElementById('ry-api-base').value = apiBase;
        }
        if (apiKey) {
            document.getElementById('ry-api-key').value = apiKey;
        }

        // 绑定事件监听器
        document.getElementById('ry-open-settings')?.addEventListener('click', function () {
            RainyunUI.showTab('settings');
        });

        document.getElementById('ry-save-settings')?.addEventListener('click', async function () {
            const base = document.getElementById('ry-api-base').value.trim();
            const key = document.getElementById('ry-api-key').value.trim();

            if (!base || !key) {
                RainyunUI.showMessage('ry-settings-msg', '请填写完整的API配置', 'error');
                return;
            }

            localStorage.setItem('rainyun_apiBase', base);
            localStorage.setItem('rainyun_apiKey', key);

            try {
                await RainyunAPI.testConnection();
                RainyunUI.showMessage('ry-settings-msg', '配置保存成功！', 'success');
                RainyunUI.updateKeyStatus('success', 'API 连接正常');
            } catch (error) {
                RainyunUI.showMessage('ry-settings-msg', `配置保存失败: ${error.message}`, 'error');
                RainyunUI.updateKeyStatus('error', 'API 连接失败');
            }
        });

        document.getElementById('ry-test-settings')?.addEventListener('click', async function () {
            try {
                await RainyunAPI.testConnection();
                RainyunUI.showMessage('ry-settings-msg', 'API 连接测试成功！', 'success');
                RainyunUI.updateKeyStatus('success', 'API 连接正常');
            } catch (error) {
                RainyunUI.showMessage('ry-settings-msg', `连接测试失败: ${error.message}`, 'error');
                RainyunUI.updateKeyStatus('error', 'API 连接失败');
            }
        });

        // 导航按钮事件绑定
        document.querySelectorAll('.ry-nav-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const tabName = this.getAttribute('data-tab');
                RainyunUI.showTab(tabName);
            });
        });

        // 默认显示概览
        RainyunUI.showTab('overview');

        // 测试API连接状态
        if (apiBase && apiKey) {
            RainyunAPI.testConnection()
                .then(() => RainyunUI.updateKeyStatus('success', 'API 已配置'))
                .catch(() => RainyunUI.updateKeyStatus('error', 'API 连接失败'));
        } else {
            RainyunUI.updateKeyStatus('error', 'API 未配置');
        }
    });

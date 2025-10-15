/**
 * FloLink - 浮动链接悬浮窗 JavaScript
 * 处理鼠标悬停显示悬浮窗的交互逻辑
 */

(function() {
    'use strict';

    class FloLink {
        constructor(options = {}) {
            this.options = Object.assign({
                selector: 'a[data-flolink="true"]',
                tooltipClass: 'flolink-tooltip',
                showDelay: 200,
                hideDelay: 300,
                offset: { x: 0, y: 10 },
                enableCache: true,
                cacheExpiry: 600000, // 10分钟
            }, options);

            this.tooltip = null;
            this.currentLink = null;
            this.showTimer = null;
            this.hideTimer = null;
            this.cache = new Map();

            this.init();
        }

        init() {
            this.createTooltip();
            this.bindEvents();
        }

        createTooltip() {
            // 创建悬浮窗DOM
            this.tooltip = document.createElement('div');
            this.tooltip.className = this.options.tooltipClass;
            this.tooltip.innerHTML = '<div class="flolink-tooltip-loading"><div class="flolink-tooltip-spinner"></div></div>';
            document.body.appendChild(this.tooltip);

            // 鼠标进入悬浮窗时保持显示
            this.tooltip.addEventListener('mouseenter', () => {
                this.clearHideTimer();
            });

            // 鼠标离开悬浮窗时隐藏
            this.tooltip.addEventListener('mouseleave', () => {
                this.hide();
            });
        }

        bindEvents() {
            // 使用事件委托处理所有FloLink链接
            document.addEventListener('mouseover', (e) => {
                const link = e.target.closest(this.options.selector);
                if (link) {
                    // 屏蔽 raw_md 容器内的链接
                    if (link.closest('#raw_md')) return;
                    this.handleMouseEnter(link, e);
                }
            });

            document.addEventListener('mouseout', (e) => {
                const link = e.target.closest(this.options.selector);
                if (link && !this.tooltip.contains(e.relatedTarget)) {
                    if (link.closest('#raw_md')) return;
                    this.handleMouseLeave(link);
                }
            });

            // 点击链接时隐藏悬浮窗
            document.addEventListener('click', (e) => {
                const link = e.target.closest(this.options.selector);
                if (link) {
                    if (link.closest('#raw_md')) return;
                    this.hide(true);
                }
            });

            // 滚动时隐藏悬浮窗
            let scrollTimer = null;
            window.addEventListener('scroll', () => {
                if (this.tooltip.classList.contains('show')) {
                    this.updatePosition();
                }
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(() => {
                    if (this.currentLink) {
                        this.updatePosition();
                    }
                }, 100);
            });

            // 窗口大小改变时隐藏悬浮窗
            window.addEventListener('resize', () => {
                this.hide();
            });
        }

        handleMouseEnter(link, event) {
            this.clearHideTimer();
            this.currentLink = link;

            // 获取延迟时间
            const delay = parseInt(link.dataset.flolinkDelay) || this.options.showDelay;

            // 设置显示延迟
            this.showTimer = setTimeout(() => {
                this.show(link, event);
            }, delay);
        }

        handleMouseLeave(link) {
            this.clearShowTimer();

            // 设置隐藏延迟
            this.hideTimer = setTimeout(() => {
                this.hide();
            }, this.options.hideDelay);
        }

        async show(link, event) {
            if (!link) return;

            // 获取FloLink数据
            const data = await this.getFloLinkData(link);

            if (!data) {
                return;
            }

            // 更新悬浮窗内容
            this.updateContent(data);

            // 显示悬浮窗
            this.tooltip.classList.add('show');

            // 更新位置
            this.updatePosition(link);
        }

        hide(immediate = false) {
            if (immediate) {
                this.clearShowTimer();
                this.clearHideTimer();
            }

            this.tooltip.classList.remove('show');
            this.currentLink = null;
        }

        async getFloLinkData(link) {
            // 从data属性获取数据
            const id = link.dataset.flolinkId;
            const title = link.dataset.flolinkTitle;
            const description = link.dataset.flolinkDesc;
            const image = link.dataset.flolinkImage;
            const url = link.href;

            // 如果有缓存且未过期，直接返回
            if (this.options.enableCache && this.cache.has(id)) {
                const cached = this.cache.get(id);
                if (Date.now() - cached.timestamp < this.options.cacheExpiry) {
                    return cached.data;
                }
            }

            // 构建数据对象
            const data = {
                id,
                title: title || link.textContent,
                description: description || '',
                image: image || '',
                url
            };

            // 缓存数据
            if (this.options.enableCache) {
                this.cache.set(id, {
                    data,
                    timestamp: Date.now()
                });
            }

            return data;
        }

        updateContent(data) {
            const hasImage = data.image && data.image.trim() !== '';
            const hasDescription = data.description && data.description.trim() !== '';

            // 移除no-image和simple类
            this.tooltip.classList.remove('no-image', 'simple');

            if (!hasImage) {
                this.tooltip.classList.add('no-image');
            }

            if (!hasImage && !hasDescription) {
                this.tooltip.classList.add('simple');
            }

            // 构建HTML
            let html = '';

            // 图片
            if (hasImage) {
                html += `<img src="${this.escapeHtml(data.image)}" alt="${this.escapeHtml(data.title)}" class="flolink-tooltip-image" onerror="this.style.display='none'">`;
            }

            // 内容区
            html += '<div class="flolink-tooltip-content">';

            // 标题
            html += `<h3 class="flolink-tooltip-title">${this.escapeHtml(data.title)}</h3>`;

            // 描述
            if (hasDescription) {
                html += `<p class="flolink-tooltip-description">${this.escapeHtml(data.description)}</p>`;
            }

            // 底部信息
            if (hasDescription) {
                const domain = this.extractDomain(data.url);
                html += `
                    <div class="flolink-tooltip-footer">
                        <span class="flolink-tooltip-url" title="${this.escapeHtml(data.url)}">${this.escapeHtml(domain)}</span>
                        <span class="flolink-tooltip-icon">→</span>
                    </div>
                `;
            }

            html += '</div>';

            this.tooltip.innerHTML = html;
        }

        updatePosition(link = this.currentLink) {
            if (!link) return;

            const rect = link.getBoundingClientRect();
            const tooltipRect = this.tooltip.getBoundingClientRect();

            // 计算位置
            let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2) + this.options.offset.x;
            let top = rect.bottom + this.options.offset.y;

            // 防止超出屏幕左侧
            if (left < 10) {
                left = 10;
            }

            // 防止超出屏幕右侧
            if (left + tooltipRect.width > window.innerWidth - 10) {
                left = window.innerWidth - tooltipRect.width - 10;
            }

            // 防止超出屏幕底部
            if (top + tooltipRect.height > window.innerHeight - 10) {
                top = rect.top - tooltipRect.height - this.options.offset.y;
            }

            // 应用位置
            this.tooltip.style.left = left + 'px';
            this.tooltip.style.top = top + 'px';

            // 更新箭头位置
            const arrowLeft = rect.left + (rect.width / 2) - left;
            this.tooltip.style.setProperty('--arrow-left', arrowLeft + 'px');
        }

        clearShowTimer() {
            if (this.showTimer) {
                clearTimeout(this.showTimer);
                this.showTimer = null;
            }
        }

        clearHideTimer() {
            if (this.hideTimer) {
                clearTimeout(this.hideTimer);
                this.hideTimer = null;
            }
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        extractDomain(url) {
            try {
                const urlObj = new URL(url);
                return urlObj.hostname;
            } catch (e) {
                return url;
            }
        }

        destroy() {
            // 清除定时器
            this.clearShowTimer();
            this.clearHideTimer();

            // 移除DOM
            if (this.tooltip && this.tooltip.parentNode) {
                this.tooltip.parentNode.removeChild(this.tooltip);
            }

            // 清空缓存
            this.cache.clear();
        }
    }

    // 自动初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.floLink = new FloLink();
        });
    } else {
        window.floLink = new FloLink();
    }

    // 导出到全局
    window.FloLink = FloLink;

})();

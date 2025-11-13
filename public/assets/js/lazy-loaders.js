/**
 * WindBlog 懒加载工具集
 * 按需加载大体积JS库，减少初始加载时间
 */

(function (window) {
    'use strict';

    // 已加载脚本缓存
    const loadedScripts = new Set();
    const loadedStyles = new Set();

    /**
     * 动态加载 JavaScript 文件
     * @param {string} src - 脚本 URL
     * @returns {Promise<void>}
     */
    function loadScript(src) {
        return new Promise((resolve, reject) => {
            // 检查是否已加载
            if (loadedScripts.has(src) || document.querySelector(`script[src="${src}"]`)) {
                loadedScripts.add(src);
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;

            script.onload = () => {
                loadedScripts.add(src);
                resolve();
            };

            script.onerror = () => {
                reject(new Error(`Failed to load script: ${src}`));
            };

            document.head.appendChild(script);
        });
    }

    /**
     * 动态加载 CSS 文件
     * @param {string} href - CSS URL
     * @returns {Promise<void>}
     */
    function loadCSS(href) {
        return new Promise((resolve) => {
            // 检查是否已加载
            if (loadedStyles.has(href) || document.querySelector(`link[href="${href}"]`)) {
                loadedStyles.add(href);
                resolve();
                return;
            }

            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;

            link.onload = () => {
                loadedStyles.add(href);
                resolve();
            };

            // CSS 加载失败不阻塞
            link.onerror = () => {
                console.warn(`Failed to load CSS: ${href}`);
                resolve();
            };

            document.head.appendChild(link);
        });
    }

    /**
     * 懒加载 SweetAlert2
     * @returns {Promise<void>}
     */
    window.loadSwal = function () {
        if (window.Swal) {
            return Promise.resolve();
        }

        return loadScript('/assets/js/sweetalert2.all.min.js')
            .catch(err => {
                console.error('Failed to load SweetAlert2:', err);
                throw err;
            });
    };

    /**
     * 懒加载 Highlight.js（代码高亮）
     * @returns {Promise<void>}
     */
    window.loadHighlightJS = function () {
        if (window.hljs) {
            return Promise.resolve();
        }

        // 根据当前主题加载对应样式
        const theme = document.documentElement.getAttribute('data-theme') || 'light';
        const cssHref = theme === 'dark'
            ? '/assets/css/github-dark.min.css'
            : 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css';

        return Promise.all([
            loadCSS(cssHref),
            loadScript('/assets/js/highlight.min.js')
        ])
            .then(() => {
                // 自动高亮所有代码块
                if (window.hljs && typeof hljs.highlightAll === 'function') {
                    hljs.highlightAll();
                }
            })
            .catch(err => {
                console.error('Failed to load Highlight.js:', err);
            });
    };

    /**
     * 懒加载 Markdown 编辑器
     * @param {string} editorType - 编辑器类型: 'vditor' 或 'easymde'
     * @returns {Promise<void>}
     */
    window.loadMarkdownEditor = function (editorType = 'vditor') {
        if (editorType === 'vditor') {
            if (window.Vditor) {
                return Promise.resolve();
            }

            return Promise.all([
                loadScript('/assets/vditor/index.min.js'),
                loadCSS('/assets/vditor/index.css')
            ])
                .catch(err => {
                    console.error('Failed to load Vditor:', err);
                    throw err;
                });
        }

        if (editorType === 'easymde') {
            if (window.EasyMDE) {
                return Promise.resolve();
            }

            return Promise.all([
                loadScript('/assets/js/easymde.min.js'),
                loadCSS('/assets/css/easymde.min.css')
            ])
                .catch(err => {
                    console.error('Failed to load EasyMDE:', err);
                    throw err;
                });
        }

        return Promise.reject(new Error(`Unknown editor type: ${editorType}`));
    };


    /**
     * 自动按需加载 Highlight.js
     * 检测页面中是否有代码块，有则自动加载
     */
    function autoLoadHighlight() {
        const hasCodeBlocks = document.querySelectorAll('pre code').length > 0;
        if (hasCodeBlocks && !window.hljs) {
            window.loadHighlightJS();
        }
    }

    /**
     * 页面加载完成后的自动检测
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoLoadHighlight);
    } else {
        autoLoadHighlight();
    }

    // PJAX 页面切换后重新检测
    document.addEventListener('page:ready', autoLoadHighlight);
    document.addEventListener('pjax:complete', autoLoadHighlight);

    // 导出工具函数（供高级用户使用）
    window.lazyLoad = {
        script: loadScript,
        css: loadCSS
    };

    console.log('[LazyLoader] Initialized - Use window.loadSwal(), loadHighlightJS(), loadMarkdownEditor()');

})(window);

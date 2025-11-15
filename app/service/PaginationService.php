<?php

namespace app\service;

class PaginationService
{
    /**
     * 生成 Wind-BLOG 风格的分页 HTML
     *
     * @param int    $currentPage     当前页码
     * @param int    $totalItems      总条目数
     * @param int    $itemsPerPage    每页条目数
     * @param string $routeName       路由名称 - 用于拼接分页链接
     * @param array  $routeParams     路由参数
     * @param int $maxDisplayPages 最大显示页码数（用于控制 n 的数量，> 此值时只显示临近当前页的一段）
     *
     * @return string 分页HTML
     */
    public static function generatePagination(
        int $currentPage,
        int $totalItems,
        int $itemsPerPage,
        string $routeName,
        array $routeParams = [],
        int $maxDisplayPages = 10
    ): string {
        if ($totalItems <= $itemsPerPage) {
            // 条目总数小于每页数量，则不需要分页
            return '';
        }

        $totalPages = (int) ceil($totalItems / $itemsPerPage);
        if ($totalPages <= 1) {
            return '';
        }

        // 规范化当前页
        $currentPage = max(1, min($currentPage, $totalPages));

        // 构造前后、首尾页的路由参数
        $firstPageParams = $routeParams;
        $firstPageParams['page'] = 1;

        $lastPageParams = $routeParams;
        $lastPageParams['page'] = $totalPages;

        $prevPageParams = $routeParams;
        $prevPageParams['page'] = max(1, $currentPage - 1);

        $nextPageParams = $routeParams;
        $nextPageParams['page'] = min($totalPages, $currentPage + 1);

        // 为 JS 跳转构造 URL 模板，使用占位符 __PAGE__
        $placeholder = '__PAGE__';
        $templateParams = $routeParams;
        $templateParams['page'] = $placeholder;
        $pageUrlTemplate = route($routeName, $templateParams);

        // 计算要展示的页码集合（用 n 来承载），超过 maxDisplayPages 时，仅展示临近当前页的一段
        $maxDisplayPages = max(1, $maxDisplayPages);
        if ($totalPages <= $maxDisplayPages) {
            $startPage = 1;
            $endPage = $totalPages;
        } else {
            $windowSize = $maxDisplayPages;
            $half = (int) floor($windowSize / 2);
            $startPage = $currentPage - $half;
            $endPage = $startPage + $windowSize - 1;

            if ($startPage < 1) {
                $startPage = 1;
                $endPage = $windowSize;
            }

            if ($endPage > $totalPages) {
                $endPage = $totalPages;
                $startPage = $endPage - $windowSize + 1;
            }
        }

        $paginationHtml = '';
        $paginationHtml .= '<div class="wind-blog-pagination flex flex-col items-center my-6"'
            . ' data-current-page="' . $currentPage . '"'
            . ' data-total-pages="' . $totalPages . '"'
            . ' data-page-url-template="' . htmlspecialchars($pageUrlTemplate, ENT_QUOTES, 'UTF-8') . '">';

        // 顶部主行：首/上 + Wind-BLOG 文字区 + 下/末
        $paginationHtml .= '<div class="flex items-center space-x-4 mb-1">';

        // 第一页按钮
        if ($currentPage <= 1) {
            $paginationHtml .= '<span class="wind-blog-nav-btn" aria-disabled="true" aria-label="第一页">&laquo;</span>';
        } else {
            $paginationHtml .= '<a class="wind-blog-nav-btn" href="' . route($routeName, $firstPageParams) . '" aria-label="第一页">&laquo;</a>';
        }

        // 上一页按钮
        if ($currentPage <= 1) {
            $paginationHtml .= '<span class="wind-blog-nav-btn" aria-disabled="true" aria-label="上一页">&lsaquo;</span>';
        } else {
            $paginationHtml .= '<a class="wind-blog-nav-btn" href="' . route($routeName, $prevPageParams) . '" rel="prev" aria-label="上一页">&lsaquo;</a>';
        }

        // Wind-BLOG 文字 + n 列表 + 可输入的 O + G / Go >
        $paginationHtml .= '<div class="wind-blog-word flex items-baseline space-x-1 text-2xl font-bold select-none">';

        // "Wi"
        $paginationHtml .= '<span class="wind-blog-letter">W</span>';
        $paginationHtml .= '<span class="wind-blog-letter">i</span>';

        // 用多个 n 承载不同页码
        $paginationHtml .= '<div class="flex items-end">';
        for ($page = $startPage; $page <= $endPage; $page++) {
            $isActive = ($page === $currentPage);
            $pageParams = $routeParams;
            $pageParams['page'] = $page;

            $itemClasses = 'wind-blog-n ' . ($isActive ? 'wind-blog-n-active' : 'wind-blog-n-inactive');

            if ($isActive) {
                $paginationHtml .= '<span class="' . $itemClasses . '">';
                $paginationHtml .= '<span class="wind-blog-n-main">n</span>';
                $paginationHtml .= '<span class="wind-blog-n-number">' . $page . '</span>';
                $paginationHtml .= '</span>';
            } else {
                $paginationHtml .= '<a class="' . $itemClasses . '" href="' . route($routeName, $pageParams) . '">';
                $paginationHtml .= '<span class="wind-blog-n-main">n</span>';
                $paginationHtml .= '<span class="wind-blog-n-number">' . $page . '</span>';
                $paginationHtml .= '</a>';
            }
        }
        $paginationHtml .= '</div>'; // 结束 n 列表

        // "d-"
        $paginationHtml .= '<span class="wind-blog-letter">d</span>';
        $paginationHtml .= '<span class="wind-blog-letter">-</span>';

        // "B"、"L"
        $paginationHtml .= '<span class="wind-blog-letter">B</span>';
        $paginationHtml .= '<span class="wind-blog-letter">L</span>';

        // 可输入的 "O"：直接使用原生输入框，保证上下与文字基线对齐
        $paginationHtml .= '<input type="number" class="wind-blog-o-input" min="1" max="' . $totalPages . '"'
            . ' inputmode="numeric" pattern="[0-9]*" placeholder="' . $currentPage . '" aria-label="跳转到指定页">';

        // 右侧 "Go >" 按钮：默认只有 G 可见，展开时补上 "o >"，宽度始终以完整 "Go >" 计算
        $paginationHtml .= '<button type="button" class="wind-blog-go-button" aria-label="跳转">';
        $paginationHtml .= '<span class="wind-blog-go-text-collapsed">G</span>';
        $paginationHtml .= '<span class="wind-blog-go-text-expanded">o &gt;</span>';
        $paginationHtml .= '</button>';

        $paginationHtml .= '</div>'; // 结束 Wind-BLOG 文字区

        // 下一页按钮
        if ($currentPage >= $totalPages) {
            $paginationHtml .= '<span class="wind-blog-nav-btn" aria-disabled="true" aria-label="下一页">&rsaquo;</span>';
        } else {
            $paginationHtml .= '<a class="wind-blog-nav-btn" href="' . route($routeName, $nextPageParams) . '" rel="next" aria-label="下一页">&rsaquo;</a>';
        }

        // 最后一页按钮
        if ($currentPage >= $totalPages) {
            $paginationHtml .= '<span class="wind-blog-nav-btn" aria-disabled="true" aria-label="最后一页">&raquo;</span>';
        } else {
            $paginationHtml .= '<a class="wind-blog-nav-btn" href="' . route($routeName, $lastPageParams) . '" aria-label="最后一页">&raquo;</a>';
        }

        $paginationHtml .= '</div>'; // 结束顶部主行

        // 底部辅助信息：总页数/当前页
        $paginationHtml .= '<div class="mt-1 text-xs text-gray-400">';
        $paginationHtml .= '第 ' . $currentPage . ' / ' . $totalPages . ' 页';
        $paginationHtml .= '</div>';

        $paginationHtml .= '</div>'; // 结束外层容器

        // 附加 CSS / JS 资源（仅在第一次调用时输出一次）
        $paginationHtml .= self::getWindBlogPaginationAssets();

        return $paginationHtml;
    }

    /**
     * 返回 Wind-BLOG 分页控件所需的样式与脚本（每个请求只注入一次）
     */
    protected static function getWindBlogPaginationAssets(): string
    {
        // 说明：在 Webman 等长驻进程环境中，静态变量会跨请求保留，
        // 如果这里使用 static 去“只注入一次”，后续请求将拿不到样式，
        // 导致你看到的 Win1n2n3... 这种未样式化效果。
        // 因此这里每次调用都返回样式和脚本，由浏览器负责去重即可。

        $style = <<<'CSS'
<style>
/* Wind-BLOG pagination styling */
.wind-blog-pagination {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.wind-blog-pagination .wind-blog-word {
    letter-spacing: 0.04em;
    align-items: baseline; /* 所有字母与 n 的底部对齐 */
    font-size: 1.6rem;      /* 统一字号，方便 O 与其他字母对齐 */
    line-height: 1;
}
.wind-blog-pagination .wind-blog-letter {
    display: inline-flex;
    align-items: baseline; /* 与基线对齐，不是 flex-end */
}
/* n 的容器垂直堆叠，但底部与字母对齐 */
.wind-blog-pagination .wind-blog-n {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end; /* 将 n 和小数字推向底部 */
    margin: 0 0.1rem;
    text-decoration: none;
    vertical-align: baseline; /* 使 n 容器在 inline-flex 中与字母底部对齐 */
}
.wind-blog-pagination .wind-blog-n-main {
    font-size: 1em;  /* 跟随 wind-blog-word 的字号 */
    line-height: 1;
}
.wind-blog-pagination .wind-blog-n-number {
    font-size: 0.7rem;
    line-height: 1;
    margin-top: 0.1rem;
}
.wind-blog-pagination .wind-blog-n-active .wind-blog-n-main,
.wind-blog-pagination .wind-blog-n-active .wind-blog-n-number {
    color: var(--accent);
    font-weight: 600;
}
.wind-blog-pagination .wind-blog-n-inactive .wind-blog-n-main {
    color: var(--text);
}
.wind-blog-pagination .wind-blog-n-inactive .wind-blog-n-number {
    color: var(--text-muted);
}
/* O 输入框：圆形、稍大一点，保持与文字基线对齐 */
.wind-blog-pagination .wind-blog-o-input {
    display: inline-block;
    width: 1.6rem;
    height: 1.6rem;
    border-radius: 9999px;
    border: 1px solid rgba(148, 163, 184, 0.9);
    font-size: 0.8rem;           /* 数字略小于主字号 */
    font-weight: 600;
    text-align: center;
    color: var(--text);
    background-color: transparent;
    padding: 0;
    margin: 0 0.12rem;
    line-height: 1.6rem;         /* 让数字在圆内垂直居中 */
    vertical-align: middle;      /* 与字母中心对齐，避免上/下偏移 */
    position: relative;
    top: -1px;                   /* 轻微上移 1px，视觉上完全居中 */
    -moz-appearance: textfield;
    appearance: textfield;
}
.wind-blog-pagination .wind-blog-o-input::-webkit-outer-spin-button,
.wind-blog-pagination .wind-blog-o-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.wind-blog-pagination .wind-blog-o-input::placeholder {
    color: var(--text-muted);
}
.wind-blog-pagination .wind-blog-o-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.35);
    background-color: rgba(37, 99, 235, 0.06);
    outline: none;
}
/* Go 按钮：宽度由完整 "Go >" 决定，避免展开时改变布局，只通过透明度切换尾部 "o >" */
.wind-blog-pagination .wind-blog-go-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    border: 1px solid transparent;
    padding: 0.15rem 0.65rem;
    margin-left: 0.12rem; /* 更贴近 O，视觉上几乎连在一起 */
    font-size: 1em;
    font-weight: 700;
    background-color: transparent;
    color: var(--text);
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    vertical-align: baseline;
}
.wind-blog-pagination .wind-blog-go-text-collapsed {
    display: inline;
}
/* 默认让 "o >" 占位但不可见，保证按钮宽度按完整 "Go >" 计算 */
.wind-blog-pagination .wind-blog-go-text-expanded {
    display: inline-block;
    opacity: 0;
    transform: scaleX(0);              /* 从 G 右侧向外展开的视觉效果 */
    transform-origin: left center;
    transition: opacity 0.2s ease, transform 0.2s ease;
}
/* O 获得焦点或输入有值时：按钮高亮，显示完整 "Go >" */
.wind-blog-pagination .wind-blog-o-input:focus ~ .wind-blog-go-button,
.wind-blog-pagination.wind-blog-has-value .wind-blog-go-button {
    background-color: var(--accent);
    color: #ffffff;
    border-color: var(--accent);
}
.wind-blog-pagination .wind-blog-o-input:focus ~ .wind-blog-go-button .wind-blog-go-text-expanded,
.wind-blog-pagination.wind-blog-has-value .wind-blog-go-button .wind-blog-go-text-expanded {
    opacity: 1;
    transform: scaleX(1);              /* 向右展开显示 o > */
}
/* 导航按钮样式 */
.wind-blog-pagination .wind-blog-nav-btn {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    color: var(--text-muted);
    background-color: var(--card);
    text-decoration: none;
    font-size: 0.9rem;
    transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
}
.wind-blog-pagination .wind-blog-nav-btn:hover {
    background-color: #f3f4f6;
    transform: translateY(-1px);
}
.wind-blog-pagination .wind-blog-nav-btn[aria-disabled="true"] {
    cursor: default;
    opacity: 0.4;
    pointer-events: none;
}
@media (max-width: 640px) {
    .wind-blog-pagination .wind-blog-word {
        font-size: 1.1rem;
    }
    .wind-blog-pagination .wind-blog-n-main {
        font-size: 1.4rem;
    }
}
</style>
CSS;

        $script = <<<'JS'
<script>
(function () {
    function initWindBlogPagination(root) {
        root = root || document;
        var containers = root.querySelectorAll('.wind-blog-pagination');
        if (!containers.length) return;

        containers.forEach(function (container) {
            var input = container.querySelector('.wind-blog-o-input');
            var goBtn = container.querySelector('.wind-blog-go-button');
            if (!input || !goBtn) return;

            var template = container.getAttribute('data-page-url-template') || '';
            var total = parseInt(container.getAttribute('data-total-pages') || '1', 10) || 1;
            var current = parseInt(container.getAttribute('data-current-page') || '1', 10) || 1;

            // 更新 has-value 状态类，用于控制 G / Go > 展开行为
            function updateHasValue() {
                if ((input.value || '').trim() !== '') {
                    container.classList.add('wind-blog-has-value');
                } else {
                    container.classList.remove('wind-blog-has-value');
                }
            }

            function normalizePage(value) {
                var page = parseInt(value, 10);
                if (isNaN(page) || page < 1) page = 1;
                if (page > total) page = total;
                return page;
            }

            function buildUrl(page) {
                page = normalizePage(page);
                if (template && template.indexOf('__PAGE__') !== -1) {
                    return template.replace('__PAGE__', page);
                }
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('page', page);
                    return url.toString();
                } catch (e) {
                    return window.location.pathname + '?page=' + page;
                }
            }

            function go(page) {
                var target = buildUrl(page);
                if (target) window.location.href = target;
            }

            // 默认用当前页作为浅色占位符
            if (!input.placeholder) {
                input.placeholder = String(current);
            }

            input.addEventListener('input', updateHasValue);
            input.addEventListener('blur', updateHasValue);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var val = input.value || input.placeholder || current;
                    go(val);
                }
            });

            goBtn.addEventListener('click', function () {
                var val = input.value || input.placeholder || current;
                go(val);
            });

            updateHasValue();
        });
    }

    if (!window.initWindBlogPagination) {
        window.initWindBlogPagination = initWindBlogPagination;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                initWindBlogPagination(document);
            });
        } else {
            initWindBlogPagination(document);
        }

        // 兼容 PJAX 或前端路由在页面切换后重新绑定事件
        document.addEventListener('page:ready', function () {
            initWindBlogPagination(document);
        });
    } else {
        window.initWindBlogPagination(document);
    }
})();
</script>
JS;

        return $style . $script;
    }
}

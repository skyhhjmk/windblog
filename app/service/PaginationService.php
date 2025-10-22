<?php

namespace app\service;

class PaginationService
{
    /**
     * 生成分页HTML
     *
     * @param int    $currentPage     当前页码
     * @param int    $totalItems      总条目数
     * @param int    $itemsPerPage    每页条目数
     * @param string $routeName       路由名称 - 用于拼接分页链接
     * @param array  $routeParams     路由参数
     * @param int    $maxDisplayPages 最大显示页码数
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

        $totalPages = ceil($totalItems / $itemsPerPage);

        // 开始构建新的分页HTML结构
        $paginationHtml = '<div class="flex flex-col items-center my-6">';
        $paginationHtml .= '<div class="flex space-x-1 mb-4">';

        // 上一页按钮
        $prevDisabled = ($currentPage <= 1) ? 'pointer-events-none opacity-50' : '';
        $prevRouteParams = $routeParams;
        $prevRouteParams['page'] = $currentPage - 1;
        $paginationHtml .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 ' . $prevDisabled . '" href="' . (($currentPage > 1) ? route($routeName, $prevRouteParams) : 'javascript:void(0)') . '">';
        $paginationHtml .= '<span class="sr-only">上一页</span>';
        $paginationHtml .= '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>';
        $paginationHtml .= '</a>';

        // 智能页码显示逻辑
        if ($totalPages <= $maxDisplayPages) {
            // 如果总页数小于等于最大显示页数，显示所有页码
            for ($i = 1; $i <= $totalPages; $i++) {
                $active = ($i == $currentPage) ? 'bg-blue-500 text-white border-blue-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50';
                $pageRouteParams = $routeParams;
                $pageRouteParams['page'] = $i;
                $paginationHtml .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border ' . $active . '" href="' . route($routeName, $pageRouteParams) . '">' . $i . '</a>';
            }
        } else {
            // 总页数超过最大显示页数，需要智能显示
            $sidePages = 2; // 当前页两侧各显示的页码数

            // 始终显示第一页
            $active = ($currentPage == 1) ? 'bg-blue-500 text-white border-blue-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50';
            $firstPageRouteParams = $routeParams;
            $firstPageRouteParams['page'] = 1;
            $paginationHtml .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border ' . $active . '" href="' . route($routeName, $firstPageRouteParams) . '">1</a>';

            // 处理第一页后的省略号
            if ($currentPage > $sidePages + 2) {
                $paginationHtml .= '<span class="flex items-center justify-center w-10 h-10">...</span>';
            }

            // 计算中间显示的页码范围
            $start = max(2, $currentPage - $sidePages);
            $end = min($totalPages - 1, $currentPage + $sidePages);

            // 调整范围，确保不会与第一页或最后一页重叠
            if ($currentPage <= $sidePages + 1) {
                $end = min($totalPages - 1, $sidePages * 2 + 2);
            }

            if ($currentPage >= $totalPages - $sidePages) {
                $start = max(2, $totalPages - $sidePages * 2 - 1);
            }

            // 显示中间页码
            for ($i = $start; $i <= $end; $i++) {
                $active = ($i == $currentPage) ? 'bg-blue-500 text-white border-blue-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50';
                $pageRouteParams = $routeParams;
                $pageRouteParams['page'] = $i;
                $paginationHtml .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border ' . $active . '" href="' . route($routeName, $pageRouteParams) . '">' . $i . '</a>';
            }

            // 处理最后一页前的省略号
            if ($currentPage < $totalPages - $sidePages - 1) {
                $paginationHtml .= '<span class="flex items-center justify-center w-10 h-10">...</span>';
            }

            // 始终显示最后一页
            $active = ($currentPage == $totalPages) ? 'bg-blue-500 text-white border-blue-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50';
            $lastPageRouteParams = $routeParams;
            $lastPageRouteParams['page'] = $totalPages;
            $paginationHtml .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border ' . $active . '" href="' . route($routeName, $lastPageRouteParams) . '">' . $totalPages . '</a>';
        }

        // 下一页按钮
        $nextDisabled = ($currentPage >= $totalPages) ? 'pointer-events-none opacity-50' : '';
        $nextRouteParams = $routeParams;
        $nextRouteParams['page'] = $currentPage + 1;
        $paginationHtml .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 ' . $nextDisabled . '" href="' . (($currentPage < $totalPages) ? route($routeName, $nextRouteParams) : 'javascript:void(0)') . '">';
        $paginationHtml .= '<span class="sr-only">下一页</span>';
        $paginationHtml .= '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>';
        $paginationHtml .= '</a>';

        $paginationHtml .= '</div>';

        // 手动跳转部分
        $paginationHtml .= '<div class="flex items-center space-x-2">';
        $paginationHtml .= '<input type="number" id="page-input" min="1" max="' . $totalPages . '" placeholder="页码" class="w-20 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">';
        $paginationHtml .= '<button onclick="jumpToPage(' . $totalPages . ')" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">跳转</button>';
        $paginationHtml .= '</div>';

        $paginationHtml .= '</div>';

        // 动作：分页构建完成（需权限 pagination:action.built）
        PluginService::do_action('pagination.built', [
            'currentPage' => $currentPage,
            'totalItems' => $totalItems,
            'itemsPerPage' => $itemsPerPage,
            'routeName' => $routeName,
            'totalPages' => $totalPages,
        ]);

        // 过滤器：分页HTML（需权限 pagination:filter.html）
        $paginationHtml = PluginService::apply_filters('pagination.html_filter', [
            'routeName' => $routeName,
            'html' => $paginationHtml,
        ])['html'] ?? $paginationHtml;

        return $paginationHtml;
    }
}

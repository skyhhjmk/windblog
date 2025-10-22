<?php

namespace app\service;

use support\Request;
use support\Response;

/**
 * PJAX辅助类
 * 提供统一的PJAX检测和处理方法
 */
class PJAXHelper
{
    /**
     * 检测是否为PJAX请求
     *
     * @param Request $request 请求对象
     *
     * @return bool 是否为PJAX请求
     */
    public static function isPJAX(Request $request): bool
    {
        // 优先使用Request对象自带的方法（如果存在）
        if (method_exists($request, 'isPjax')) {
            return $request->isPjax();
        }

        // 备用实现：检查常见的PJAX请求特征
        return ($request->header('X-PJAX') !== null)
            || (bool) $request->get('_pjax')
            || strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';
    }

    /**
     * 检测是否为AJAX请求
     *
     * @param Request $request 请求对象
     *
     * @return bool 是否为AJAX请求
     */
    public static function isAjax(Request $request): bool
    {
        // 优先使用Request对象自带的方法（如果存在）
        if (method_exists($request, 'isAjax')) {
            return $request->isAjax();
        }

        // 备用实现：检查常见的AJAX请求特征
        return strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';
    }

    /**
     * 为PJAX请求生成缓存键
     *
     * @param string $route  路由名称
     * @param array  $params 参数数组
     * @param int    $page   页码
     * @param string $locale 语言区域
     *
     * @return string 缓存键
     */
    public static function generateCacheKey(string $route, array $params = [], int $page = 1, string $locale = 'zh-CN'): string
    {
        $paramsHash = substr(sha1(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);

        return sprintf('list:%s:%s:%d:%s', $route, $paramsHash, $page, $locale);
    }

    /**
     * 获取适用于PJAX或非PJAX请求的视图名称
     *
     * @param string $baseView 基础视图名称
     * @param bool $isPjax 是否为PJAX请求
     *
     * @return string 完整的视图名称
     */
    public static function getViewName(string $baseView, bool $isPjax): string
    {
        return $isPjax ? $baseView . '.content' : $baseView;
    }

    /**
     * 创建带缓存的PJAX响应
     *
     * @param Request     $request    请求对象
     * @param string      $viewName   视图名称
     * @param array       $viewData   视图数据
     * @param string|null $cacheKey   缓存键
     * @param int         $ttl        缓存时间（秒）
     * @param string      $cacheGroup 缓存分组
     *
     * @return Response 响应对象
     */
    public static function createResponse(Request $request, string $viewName, array $viewData, ?string $cacheKey = null, int $ttl = 120, string $cacheGroup = 'page'): Response
    {
        $isPjax = self::isPJAX($request);
        $enhancedCache = null;

        // 如果提供了缓存键，尝试从缓存获取
        if ($cacheKey) {
            $enhancedCache = new EnhancedCacheService();
            $cached = $enhancedCache->get($cacheKey, $cacheGroup, null, $ttl);

            if ($cached !== false) {
                return new Response(200, ['X-PJAX-Cache' => 'HIT', 'X-PJAX-URL' => $request->url()], $cached);
            }
        }

        // 创建响应
        $resp = view($viewName, $viewData);

        // 添加PJAX相关的响应头
        $headers = [
            'X-PJAX-Cache' => 'MISS',
            'X-PJAX-URL' => $request->url(),
            'X-PJAX-CONTAINER' => '#pjax-container', // 统一与前端容器选择器一致
            'Vary' => 'X-PJAX,X-Requested-With',
        ];

        // 应用响应头
        foreach ($headers as $key => $value) {
            $resp = $resp->withHeader($key, $value);
        }

        // 如果提供了缓存键，且响应内容不为空，则缓存响应
        if ($cacheKey && $enhancedCache) {
            $rawBody = $resp->rawBody();
            // 避免缓存空响应或极小的响应（可能表示错误）
            // 只有当响应内容长度大于10个字符时才缓存
            if (strlen($rawBody) > 10) {
                $enhancedCache->set($cacheKey, $rawBody, $ttl, $cacheGroup);
            }
        }

        return $resp;
    }

    /**
     * 创建PJAX错误响应
     *
     * @param int   $status  状态码
     * @param string $message 错误消息
     * @param array $headers 额外的响应头
     *
     * @return Response 响应对象
     */
    public static function createErrorResponse(int $status = 400, string $message = 'Bad Request', array $headers = []): Response
    {
        $defaultHeaders = [
            'X-PJAX-Error' => 'true',
            'Vary' => 'X-PJAX,X-Requested-With',
        ];

        $mergedHeaders = array_merge($defaultHeaders, $headers);

        return new Response($status, $mergedHeaders, $message);
    }
}

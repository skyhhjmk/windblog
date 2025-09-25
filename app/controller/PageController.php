<?php

namespace app\controller;

use support\Request;
use support\Response;
use app\service\PageService;
use Webman\RateLimiter\Annotation\RateLimiter;

class PageController
{
    protected array $noNeedLogin = ['index'];

    #[RateLimiter(limit: 3, ttl: 3)]
    public function index(Request $request, mixed $keyword = null): Response
    {
        try {
            // 移除URL参数中的 .html 后缀
            if (is_string($keyword) && str_ends_with($keyword, '.html')) {
                $keyword = substr($keyword, 0, -5);
            }
            
            // 根据URL模式处理不同类型的参数
            $urlMode = blog_config('url_mode', 'slug');
            
            // 如果URL模式是id或mix，尝试将keyword转换为整数
            if ($urlMode === 'id' || $urlMode === 'mix') {
                // 尝试从URL中提取数字ID
                if (is_numeric($keyword)) {
                    $pageContent = PageService::getAndRenderPageById((int)$keyword);
                    if ($pageContent) {
                        return view('page/index', ['html' => $pageContent]);
                    }
                }
            }
            
            // 默认使用slug模式
            $pageContent = PageService::getAndRenderPage($keyword);
            if ($pageContent) {
                return view('page/index', ['html' => $pageContent]);
            }
            
            return view('error/404');
        } catch (\Throwable $e) {
            \support\Log::error('Page rendering error: ' . $e->getMessage());
            return view('error/500');
        }
    }
}
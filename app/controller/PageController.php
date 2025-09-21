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
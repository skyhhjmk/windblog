<?php

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class Lang implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        locale(session('lang', 'zh_CN'));

        return $handler($request);
    }
}

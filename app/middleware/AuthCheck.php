<?php
namespace app\middleware;

use ReflectionClass;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class AuthCheck implements MiddlewareInterface
{
    public function process(Request $request, callable $handler) : Response
    {
        if (session('admin')) {
            // 已经登录
            return $handler($request);
        }

        // 通过反射获取控制器哪些方法不需要登录、哪些方法需要管理员权限
        $controller = new ReflectionClass($request->controller);
        $noNeedLogin = $controller->getDefaultProperties()['noNeedLogin'] ?? [];
        $adminOnly = $controller->getDefaultProperties()['adminOnly'] ?? [];

        // 访问的方法需要登录
        if (!in_array($request->action, $noNeedLogin)) {
            // 拦截请求，返回一个重定向响应，请求停止向洋葱芯穿越
            return redirect('/app/admin/login');
        }

        // 访问的方法需要管理员权限
        if (!in_array($request->action, $adminOnly)) {
            // 如果已登录但非管理员
            if (session('login')) {
                // 拦截请求，返回一个403响应，请求停止向洋葱芯穿越
                return view('admin/code/403');
            }
        }

        // 不需要登录，请求继续向洋葱芯穿越
        return $handler($request);
    }
}
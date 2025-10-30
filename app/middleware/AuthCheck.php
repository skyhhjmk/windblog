<?php

namespace app\middleware;

use ReflectionClass;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AuthCheck implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $session = $request->session();

        // 检查是否已登录(兼容管理员和普通用户)
        $isAdmin = $session->get('admin');
        $isUser = $session->get('user_id');
        $isLoggedIn = $isAdmin || $isUser;

        // 通过反射获取控制器哪些方法不需要登录、哪些方法需要管理员权限、哪些方法只需普通用户权限
        $controller = new ReflectionClass($request->controller);
        $noNeedLogin = $controller->getDefaultProperties()['noNeedLogin'] ?? [];
        $adminOnly = $controller->getDefaultProperties()['adminOnly'] ?? [];
        $userOnly = $controller->getDefaultProperties()['userOnly'] ?? [];

        // 如果方法不需要登录,直接放行
        if (in_array($request->action, $noNeedLogin)) {
            return $handler($request);
        }

        // 如果方法需要管理员权限
        if (in_array($request->action, $adminOnly)) {
            if (!$isAdmin) {
                // 未登录重定向到管理员登录页
                if (!$isLoggedIn) {
                    return redirect('/app/admin/login');
                }

                // 已登录但不是管理员,返回403
                return view('admin/code/403');
            }

            return $handler($request);
        }

        // 如果方法需要普通用户权限
        if (in_array($request->action, $userOnly)) {
            if (!$isUser) {
                // 未登录重定向到用户登录页
                if (!$isLoggedIn) {
                    return redirect('/user/login');
                }

                // 已登录但不是普通用户(是管理员),可以选择允许或拒绝
                // 这里选择允许管理员访问普通用户功能
                return $handler($request);
            }

            return $handler($request);
        }

        // 默认行为:需要登录(兼容管理员和普通用户)
        if (!$isLoggedIn) {
            // 根据请求路径判断重定向到哪个登录页
            $path = $request->path();
            if (str_starts_with($path, '/app/admin') || str_starts_with($path, '/admin')) {
                return redirect('/app/admin/login');
            } else {
                return redirect('/user/login');
            }
        }

        // 已登录,请求继续向洋葱芯穿越
        return $handler($request);
    }
}

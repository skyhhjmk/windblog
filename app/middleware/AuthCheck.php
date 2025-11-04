<?php

namespace app\middleware;

use app\model\User;
use ReflectionClass;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 前端用户认证中间件
 *
 * 支持以下控制器属性：
 * - $noNeedLogin: 不需要登录的方法列表
 * - $userOnly: 需要普通用户权限的方法列表
 * - $adminOnly: 需要管理员权限的方法列表（保留，用于后台）
 */
class AuthCheck implements MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param Request  $request
     * @param callable $handler
     *
     * @return Response
     * @throws \ReflectionException
     */
    public function process(Request $request, callable $handler): Response
    {
        $action = $request->action;
        $controller = new ReflectionClass($request->controller);
        $noNeedLogin = $controller->getDefaultProperties()['noNeedLogin'] ?? [];

        // 不需要登录的方法直接放行
        if (in_array($action, $noNeedLogin)) {
            return $handler($request);
        }

        // 获取会话和登录状态
        $session = $request->session();
        $isAdmin = $session->get('admin');
        $userId = $session->get('user_id');
        $isLoggedIn = $isAdmin || $userId;

        $adminOnly = $controller->getDefaultProperties()['adminOnly'] ?? [];
        $userOnly = $controller->getDefaultProperties()['userOnly'] ?? [];

        // 管理员权限验证
        if (in_array($action, $adminOnly)) {
            if (!$isAdmin) {
                if (!$isLoggedIn) {
                    return $this->redirectToLogin($request, 'admin');
                }

                return $this->unauthorizedResponse($request);
            }

            return $handler($request);
        }

        // 用户权限验证
        if (in_array($action, $userOnly)) {
            if ($isAdmin) {
                return $handler($request);
            }

            if (!$userId) {
                return $this->redirectToLogin($request, 'user');
            }

            $response = $this->validateUserStatus($request, $session, $userId);
            if ($response !== null) {
                return $response;
            }

            return $handler($request);
        }

        // 默认需要登录
        if (!$isLoggedIn) {
            return $this->redirectToLogin($request);
        }

        // 验证普通用户状态
        if ($userId && !$isAdmin) {
            $response = $this->validateUserStatus($request, $session, $userId);
            if ($response !== null) {
                return $response;
            }
        }

        return $handler($request);
    }

    /**
     * 验证用户状态
     *
     * @param Request $request
     * @param mixed   $session
     * @param int     $userId
     *
     * @return Response|null
     */
    private function validateUserStatus(Request $request, mixed $session, int $userId): ?Response
    {
        $user = User::find($userId);

        if (!$user) {
            $session->delete('user_id');
            $session->delete('username');

            return $this->redirectToLogin($request, 'user');
        }

        if ($user->status === 2) {
            return $this->forbiddenResponse($request, '账户已被禁用，请联系管理员');
        }

        if ($user->status === 0) {
            return $this->forbiddenResponse($request, '账户未激活，请先激活您的账户');
        }

        if ($user->status !== 1) {
            $session->delete('user_id');
            $session->delete('username');

            return $this->redirectToLogin($request, 'user');
        }

        return null;
    }

    /**
     * 重定向到登录页
     *
     * @param Request     $request
     * @param string|null $type
     *
     * @return Response
     */
    private function redirectToLogin(Request $request, ?string $type = null): Response
    {
        if ($request->expectsJson()) {
            return json_error([
                'code' => 401,
                'msg' => '未登录或登录已过期，请重新登录',
            ], 401);
        }

        if ($type === 'admin') {
            return redirect('/app/admin/login');
        }

        if ($type === 'user') {
            return redirect('/user/login');
        }

        $path = $request->path();
        if (str_starts_with($path, '/app/admin') || str_starts_with($path, '/admin')) {
            return redirect('/app/admin/login');
        }

        return redirect('/user/login');
    }

    /**
     * 返回未授权响应
     *
     * @param Request $request
     *
     * @return Response
     */
    private function unauthorizedResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return json_error([
                'code' => 403,
                'msg' => '拒绝访问',
            ], 403);
        }

        return view_error('error/403', [], 403);
    }

    /**
     * 返回禁止访问响应
     *
     * @param Request $request
     * @param string  $message
     *
     * @return Response
     */
    private function forbiddenResponse(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return json([
                'code' => 403,
                'msg' => $message,
            ], 403);
        }

        return view('error/403', ['message' => $message], null, 403);
    }
}

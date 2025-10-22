<?php

namespace app\middleware;

use app\service\SecurityService;
use Exception;
use ReflectionClass;
use support\Db;
use support\Log;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 增强的身份验证和权限检查中间件
 *
 * 提供细粒度的权限控制、请求频率限制和安全防护
 */
class EnhancedAuthCheck implements MiddlewareInterface
{
    /**
     * 需要登录验证的路由前缀
     */
    private array $protectedPrefixes = [
        '/app/admin',
        '/api/admin',
        '/admin',
        '/api/v1/admin',
    ];

    /**
     * 免登录验证的路由
     */
    private array $publicRoutes = [
        '/app/admin/login',
        '/app/admin/logout',
        '/api/admin/login',
        '/api/v1/admin/login',
        '/api/v1/auth/login',
    ];

    /**
     * 请求频率限制配置（每分钟最大请求数）
     */
    private array $rateLimits = [
        'admin_login' => 5,        // 管理员登录每分钟最多5次
        'admin_action' => 60,      // 管理员操作每分钟最多60次
        'api_request' => 120,      // API请求每分钟最多120次
        'file_upload' => 10,       // 文件上传每分钟最多10次
    ];

    /**
     * 处理请求
     *
     * @param Request  $request
     * @param callable $handler
     *
     * @return Response
     * @throws Exception
     */
    public function process(Request $request, callable $handler): Response
    {
        // 检查是否需要身份验证
        if (!$this->requiresAuth($request)) {
            return $handler($request);
        }

        // 检查请求频率限制
        $rateLimitCheck = $this->checkRateLimit($request);
        if (!$rateLimitCheck['allowed']) {
            SecurityService::logSecurityEvent(
                'rate_limit_exceeded',
                $rateLimitCheck['message'],
                [
                    'ip' => $request->getRealIp(),
                    'route' => $request->route,
                    'method' => $request->method(),
                ]
            );

            return SecurityService::jsonResponse(
                null,
                429,
                $rateLimitCheck['message']
            );
        }

        // 检查会话有效性
        if (!$this->isValidSession($request)) {
            return $this->handleInvalidSession($request);
        }

        // 检查权限
        $permissionCheck = $this->checkPermission($request);
        if (!$permissionCheck['allowed']) {
            SecurityService::logSecurityEvent(
                'permission_denied',
                $permissionCheck['message'],
                [
                    'ip' => $request->getRealIp(),
                    'user_id' => session('admin.id'),
                    'route' => $request->route,
                    'required_permission' => $permissionCheck['required_permission'] ?? null,
                ]
            );

            return SecurityService::jsonResponse(
                null,
                403,
                $permissionCheck['message']
            );
        }

        // 记录访问日志
        $this->logAccess($request);

        return $handler($request);
    }

    /**
     * 判断请求是否需要身份验证
     *
     * @param Request $request
     *
     * @return bool
     */
    private function requiresAuth(Request $request): bool
    {
        $path = $request->path();

        // 检查是否为公开路由
        if (array_any($this->publicRoutes, fn ($publicRoute) => str_starts_with($path, $publicRoute))) {
            return false;
        }

        // 检查是否为受保护的路由前缀
        return array_any($this->protectedPrefixes, fn ($prefix) => str_starts_with($path, $prefix));

        // 默认不需要身份验证
    }

    /**
     * 检查请求频率限制
     *
     * @param Request $request
     *
     * @return array [(bool)是否允许, (string)错误信息]
     */
    private function checkRateLimit(Request $request): array
    {
        $ip = $this->getClientIp($request);
        $path = $request->path();
        $method = $request->method();

        // 根据路由和方法确定限制类型
        $limitType = $this->getLimitType($path, $method);

        if (!$limitType) {
            return ['allowed' => true, 'message' => ''];
        }

        $limit = $this->rateLimits[$limitType] ?? 60;
        $cacheKey = "rate_limit:{$limitType}:{$ip}";
        $currentTime = time();

        $cache = cache();

        // 获取最近请求记录
        $requestHistory = $cache->get($cacheKey, []);

        // 清理过期记录（1分钟外）
        $requestHistory = array_filter($requestHistory, function ($timestamp) use ($currentTime) {
            return ($currentTime - $timestamp) < 60;
        });

        // 检查是否超过限制
        if (count($requestHistory) >= $limit) {
            return ['allowed' => false, 'message' => '请求频率过高，请稍后再试'];
        }

        // 添加当前请求记录
        $requestHistory[] = $currentTime;
        $cache->set($cacheKey, $requestHistory, 60);

        return ['allowed' => true, 'message' => ''];
    }

    /**
     * 获取限制类型
     *
     * @param string $path
     * @param string $method
     *
     * @return string|null
     */
    private function getLimitType(string $path, string $method): ?string
    {
        if (str_contains($path, '/login')) {
            return 'admin_login';
        }

        if (str_contains($path, '/admin') || str_contains($path, '/api/admin')) {
            return 'admin_action';
        }

        if (str_contains($path, '/api/')) {
            return 'api_request';
        }

        if ($method === 'POST' && str_contains($path, '/upload')) {
            return 'file_upload';
        }

        return null;
    }

    /**
     * 检查会话有效性
     *
     * @param Request $request
     *
     * @return bool
     * @throws Exception
     */
    private function isValidSession(Request $request): bool
    {
        $admin = session('admin');

        if (!$admin || !isset($admin['id'])) {
            return false;
        }

        // 检查会话是否过期（24小时）
        $sessionTime = session('login_time');
        if (!$sessionTime || (time() - $sessionTime) > 86400) {
            session(['admin' => null, 'login_time' => null]);

            return false;
        }

        // 验证用户在数据库中是否存在且未被禁用
        try {
            $user = Db::table('wa_admins')->where('id', $admin['id'])->first();
            if (!$user || $user->status != 0) {
                session(['admin' => null, 'login_time' => null]);

                return false;
            }
        } catch (Exception $e) {
            Log::error('Session validation error: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * 处理无效会话
     *
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     */
    private function handleInvalidSession(Request $request): Response
    {
        // 清除会话
        session(['admin' => null, 'login_time' => null]);

        // 如果是AJAX请求，返回JSON
        if ($request->expectsJson()) {
            return SecurityService::jsonResponse(
                null,
                401,
                '登录已过期，请重新登录'
            );
        }

        // 重定向到登录页
        return redirect('/app/admin/login');
    }

    /**
     * 检查权限
     *
     * @param Request $request
     *
     * @return array [是否允许, 错误信息, 所需权限]
     */
    private function checkPermission(Request $request): array
    {
        $controller = $request->controller;
        $action = $request->action;

        if (!$controller) {
            return ['allowed' => false, 'message' => '控制器不存在'];
        }

        try {
            $reflection = new ReflectionClass($controller);
            $properties = $reflection->getDefaultProperties();

            // 检查是否需要特定权限
            $requiredPermission = $properties['requiredPermission'] ?? null;
            if ($requiredPermission) {
                return $this->checkSpecificPermission($request, $requiredPermission);
            }

            // 检查是否为管理员专用方法
            $adminOnly = $properties['adminOnly'] ?? [];
            if (in_array($action, $adminOnly)) {
                return $this->checkAdminPermission($request);
            }

        } catch (Exception $e) {
            Log::error('Permission check error: ' . $e->getMessage());

            return ['allowed' => false, 'message' => '权限检查失败'];
        }

        return ['allowed' => true, 'message' => ''];
    }

    /**
     * 检查特定权限
     *
     * @param Request $request
     * @param string  $permission
     *
     * @return array
     */
    private function checkSpecificPermission(Request $request, string $permission): array
    {
        $admin = session('admin');

        // 这里可以根据实际情况实现更复杂的权限检查逻辑
        // 例如：检查用户角色、权限表等

        // 简化版：检查管理员角色
        if (isset($admin['role']) && $admin['role'] === 'super_admin') {
            return ['allowed' => true, 'message' => ''];
        }

        return ['allowed' => false, 'message' => '权限不足', 'required_permission' => $permission];
    }

    /**
     * 检查管理员权限
     *
     * @param Request $request
     *
     * @return array
     */
    private function checkAdminPermission(Request $request): array
    {
        $admin = session('admin');

        if (!$admin || !isset($admin['role'])) {
            return ['allowed' => false, 'message' => '需要管理员权限'];
        }

        // 检查是否为管理员角色
        $adminRoles = ['admin', 'super_admin'];
        if (!in_array($admin['role'], $adminRoles)) {
            return ['allowed' => false, 'message' => '需要管理员权限'];
        }

        return ['allowed' => true, 'message' => ''];
    }

    /**
     * 记录访问日志
     *
     * @param Request $request
     */
    private function logAccess(Request $request): void
    {
        try {
            $admin = session('admin');
            $userId = $admin['id'] ?? null;

            // 只记录重要的管理操作
            $importantActions = ['delete', 'update', 'create', 'upload', 'download'];
            $action = $request->action;

            $shouldLog = false;
            foreach ($importantActions as $importantAction) {
                if (str_contains($action, $importantAction)) {
                    $shouldLog = true;
                    break;
                }
            }

            if ($shouldLog) {
                Log::info('Admin Access Log', [
                    'user_id' => $userId,
                    'username' => $admin['username'] ?? 'unknown',
                    'ip' => $request->getRealIp(),
                    'method' => $request->method(),
                    'route' => $request->route,
                    'user_agent' => $request->header('User-Agent', ''),
                    'timestamp' => time(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Access logging error: ' . $e->getMessage());
        }
    }

    /**
     * 获取客户端真实IP地址
     *
     * @param Request $request
     *
     * @return string
     */
    private function getClientIp(Request $request): string
    {
        $ip = $request->getRealIp();

        // 本地开发环境处理
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return 'localhost';
        }

        return $ip;
    }
}

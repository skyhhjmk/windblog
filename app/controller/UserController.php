<?php

namespace app\controller;

use app\model\User;
use app\model\UserOAuthBinding;
use app\service\MQService;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class UserController
{
    /**
     * 用户注册
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function register(Request $request): Response
    {
        // 获取注册数据
        $username = trim($request->post('username', ''));
        $email = trim($request->post('email', ''));
        $password = $request->post('password', '');
        $passwordConfirm = $request->post('password_confirm', '');
        $nickname = trim($request->post('nickname', ''));

        // 1. 基本验证
        if (empty($username)) {
            return json(['code' => 400, 'msg' => '用户名不能为空']);
        }

        if (strlen($username) < 3 || strlen($username) > 32) {
            return json(['code' => 400, 'msg' => '用户名长度需要在3-32个字符之间']);
        }

        // 用户名只能包含字母、数字、下划线
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return json(['code' => 400, 'msg' => '用户名只能包含字母、数字和下划线']);
        }

        if (empty($email)) {
            return json(['code' => 400, 'msg' => '邮箱不能为空']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'msg' => '邮箱格式不正确']);
        }

        if (empty($password)) {
            return json(['code' => 400, 'msg' => '密码不能为空']);
        }

        if (strlen($password) < 6) {
            return json(['code' => 400, 'msg' => '密码长度至少为6个字符']);
        }

        if ($password !== $passwordConfirm) {
            return json(['code' => 400, 'msg' => '两次输入的密码不一致']);
        }

        // 昵称为空则使用用户名
        if (empty($nickname)) {
            $nickname = $username;
        }

        // 2. 检查用户名是否已存在
        $existingUser = User::where('username', $username)->first();
        if ($existingUser) {
            return json(['code' => 400, 'msg' => '用户名已被使用']);
        }

        // 3. 检查邮箱是否已存在
        $existingEmail = User::where('email', $email)->first();
        if ($existingEmail) {
            return json(['code' => 400, 'msg' => '邮箱已被注册']);
        }

        // 4. 创建用户
        try {
            $user = new User();
            $user->username = $username;
            $user->nickname = $nickname;
            $user->email = $email;
            $user->password = password_hash($password, PASSWORD_BCRYPT);
            $user->status = 0; // 未激活状态
            $user->join_time = date('Y-m-d H:i:s');
            $user->join_ip = $request->getRealIp();

            if ($user->save()) {
                // 5. 生成激活令牌
                $activationToken = $user->generateActivationToken(24); // 24小时有效期

                // 6. 发送激活邮件
                $this->sendActivationEmail($user, $activationToken);

                return json([
                    'code' => 0,
                    'msg' => '注册成功！激活邮件已发送到您的邮箱，请在24小时内完成激活',
                    'data' => [
                        'user_id' => $user->id,
                        'username' => $user->username,
                    ],
                ]);
            }

            return json(['code' => 500, 'msg' => '注册失败，请稍后重试']);
        } catch (Exception $e) {
            Log::error('User registration failed: ' . $e->getMessage());

            return json(['code' => 500, 'msg' => '注册失败，请稍后重试']);
        }
    }

    /**
     * 用户登录
     *
     * @param Request $request
     *
     * @return Response
     */
    public function login(Request $request): Response
    {
        $username = trim($request->post('username', ''));
        $password = $request->post('password', '');
        $remember = (bool) $request->post('remember', false);

        // 1. 基本验证
        if (empty($username)) {
            return json(['code' => 400, 'msg' => '用户名不能为空']);
        }

        if (empty($password)) {
            return json(['code' => 400, 'msg' => '密码不能为空']);
        }

        // 2. 查找用户（支持用户名或邮箱登录）
        $user = User::where('username', $username)
            ->orWhere('email', $username)
            ->first();

        if (!$user) {
            return json(['code' => 400, 'msg' => '用户名或密码错误']);
        }

        // 3. 验证密码
        if (!password_verify($password, $user->password)) {
            return json(['code' => 400, 'msg' => '用户名或密码错误']);
        }

        // 4. 检查用户状态
        if ($user->status === 2) {
            return json(['code' => 403, 'msg' => '账户已被禁用']);
        }

        if ($user->status === 0) {
            return json(['code' => 403, 'msg' => '账户未激活，请先激活您的账户']);
        }

        // 5. 更新登录信息
        $user->last_time = date('Y-m-d H:i:s');
        $user->last_ip = $request->getRealIp();
        $user->save();

        // 6. 设置会话
        $session = $request->session();
        $session->set('user_id', $user->id);
        $session->set('username', $user->username);

        // 7. 记住我功能（可选）
        if ($remember) {
            // 这里可以实现记住我功能，例如设置长期cookie
        }

        return json([
            'code' => 0,
            'msg' => '登录成功',
            'data' => [
                'user_id' => $user->id,
                'username' => $user->username,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    /**
     * 用户登出
     *
     * @param Request $request
     *
     * @return Response
     */
    public function logout(Request $request): Response
    {
        $session = $request->session();
        $session->delete('user_id');
        $session->delete('username');

        return json([
            'code' => 0,
            'msg' => '退出成功',
        ]);
    }

    /**
     * 激活账户
     *
     * @param Request $request
     *
     * @return Response
     */
    public function activate(Request $request): Response
    {
        $token = trim($request->get('token', ''));

        if (empty($token)) {
            return view('user/activation-result', [
                'success' => false,
                'message' => '激活令牌无效',
            ]);
        }

        // 查找用户
        $user = User::where('activation_token', $token)->first();

        if (!$user) {
            return view('user/activation-result', [
                'success' => false,
                'message' => '激活令牌无效或已过期',
            ]);
        }

        // 检查令牌是否过期
        if (!$user->isActivationTokenValid()) {
            return view('user/activation-result', [
                'success' => false,
                'message' => '激活令牌已过期，请重新注册或申请重发激活邮件',
            ]);
        }

        // 激活账户
        if ($user->activate()) {
            return view('user/activation-result', [
                'success' => true,
                'message' => '账户激活成功！您现在可以登录并使用所有功能了',
                'username' => $user->username,
            ]);
        }

        return view('user/activation-result', [
            'success' => false,
            'message' => '激活失败，请稍后重试',
        ]);
    }

    /**
     * 重发激活邮件
     *
     * @param Request $request
     *
     * @return Response
     */
    public function resendActivation(Request $request): Response
    {
        $email = trim($request->post('email', ''));

        if (empty($email)) {
            return json(['code' => 400, 'msg' => '邮箱不能为空']);
        }

        // 查找用户
        $user = User::where('email', $email)->first();

        if (!$user) {
            return json(['code' => 404, 'msg' => '该邮箱未注册']);
        }

        // 检查是否已激活
        if ($user->isEmailVerified()) {
            return json(['code' => 400, 'msg' => '该账户已激活']);
        }

        // 重新生成激活令牌
        $activationToken = $user->generateActivationToken(24);

        // 发送激活邮件
        $this->sendActivationEmail($user, $activationToken);

        return json([
            'code' => 0,
            'msg' => '激活邮件已重新发送，请查收',
        ]);
    }

    /**
     * 用户资料页面（重定向到用户中心）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function profile(Request $request): Response
    {
        return redirect('/user/center');
    }

    /**
     * 获取用户信息 API
     *
     * @param Request $request
     *
     * @return Response
     */
    public function profileApi(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if (!$userId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $user = User::find($userId);

        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        return json([
            'code' => 0,
            'data' => [
                'user_id' => $user->id,
                'username' => $user->username,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'avatar_url' => $user->getAvatarUrl(200, 'identicon'),
                'level' => $user->level,
                'score' => $user->score,
                'email_verified' => $user->isEmailVerified(),
                'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * 发送激活邮件
     *
     * @param User   $user
     * @param string $token
     *
     * @return void
     */
    private function sendActivationEmail(User $user, string $token): void
    {
        try {
            // 生成激活链接
            $activationUrl = request()->host() . '/user/activate?token=' . $token;

            // 邮件内容
            $subject = '激活您的账户';
            $html = <<<HTML
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>激活账户</title>
                </head>
                <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                        <h2 style="color: #4a90e2;">欢迎注册 WindBlog！</h2>
                        <p>您好，{$user->username}！</p>
                        <p>感谢您注册我们的网站。请点击下方按钮激活您的账户：</p>
                        <div style="text-align: center; margin: 30px 0;">
                            <a href="{$activationUrl}"
                               style="background-color: #4a90e2; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
                                激活账户
                            </a>
                        </div>
                        <p>或者复制以下链接到浏览器中打开：</p>
                        <p style="word-break: break-all; color: #666;">{$activationUrl}</p>
                        <p style="color: #999; font-size: 14px;">此链接将在24小时后失效。</p>
                        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                        <p style="color: #999; font-size: 12px;">如果您没有注册此账户，请忽略此邮件。</p>
                    </div>
                </body>
                </html>
                HTML;

            // 发送到消息队列
            $mailData = [
                'to' => $user->email,
                'subject' => $subject,
                'html' => $html,
            ];

            MQService::publishMail($mailData);

            Log::info("Activation email queued for user: {$user->username} ({$user->email})");
        } catch (Throwable $e) {
            Log::error('Failed to queue activation email: ' . $e->getMessage());
        }
    }

    // ==================== OAuth 2.0 接口 ====================

    /**
     * 发起OAuth登录
     *
     * @param Request $request
     * @param string  $provider OAuth提供商
     *
     * @return Response
     */
    public function oauthRedirect(Request $request, string $provider): Response
    {
        try {
            // 从数据库获取OAuth配置
            $config = $this->getOAuthConfig($provider);
            if (!$config || empty($config['enabled'])) {
                return json(['code' => 400, 'msg' => '该OAuth平台未启用或配置不完整']);
            }

            // 生成state防止CSRF攻击
            $state = bin2hex(random_bytes(16));
            $session = $request->session();
            $session->set('oauth_state', $state);

            // 构建授权URL
            $authUrl = $this->getAuthorizationUrl($provider, $config, $state);

            return redirect($authUrl);
        } catch (Exception $e) {
            Log::error('OAuth redirect failed: ' . $e->getMessage());

            return json(['code' => 500, 'msg' => 'OAuth跳转失败']);
        }
    }

    /**
     * OAuth 登录回调
     *
     * @param Request $request
     * @param string  $provider OAuth提供商
     *
     * @return Response
     */
    public function oauthCallback(Request $request, string $provider): Response
    {
        try {
            // 验证state防止CSRF攻击
            $session = $request->session();
            $state = $session->get('oauth_state');
            if (!$state || $state !== $request->get('state')) {
                return view('user/oauth-error', ['message' => '非法请求，请重试']);
            }

            $code = $request->get('code');
            if (!$code) {
                return view('user/oauth-error', ['message' => '授权失败，未获取授权码']);
            }

            // 获取OAuth用户信息
            $userData = $this->getOAuthUserData($provider, $code);
            if (!$userData) {
                return view('user/oauth-error', ['message' => '获取用户信息失败']);
            }

            // 查找或创建用户
            $user = $this->findOrCreateUserFromOAuth($provider, $userData);
            if (!$user) {
                return view('user/oauth-error', ['message' => '用户创建失败']);
            }

            // 登录用户
            $session->set('user_id', $user->id);
            $session->set('username', $user->username);
            $session->delete('oauth_state');

            // 更新登录信息
            $user->last_time = date('Y-m-d H:i:s');
            $user->last_ip = $request->getRealIp();
            $user->save();

            return redirect('/user/center');
        } catch (Exception $e) {
            Log::error('OAuth callback failed: ' . $e->getMessage());

            return view('user/oauth-error', ['message' => 'OAuth登录失败']);
        }
    }

    /**
     * 绑定OAuth账户
     *
     * @param Request $request
     * @param string  $provider
     *
     * @return Response
     */
    public function bindOAuth(Request $request, string $provider): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if (!$userId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        try {
            $code = $request->post('code');
            if (!$code) {
                return json(['code' => 400, 'msg' => '缺少授权码']);
            }

            // 获取OAuth用户信息
            $userData = $this->getOAuthUserData($provider, $code);
            if (!$userData) {
                return json(['code' => 500, 'msg' => '获取用户信息失败']);
            }

            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }

            // 检查是否已绑定
            if ($user->hasOAuthBinding($provider)) {
                return json(['code' => 400, 'msg' => '已绑定该平台']);
            }

            // 检查该OAuth账号是否已被其他用户绑定
            $existingBinding = UserOAuthBinding::findByProvider($provider, $userData['id']);
            if ($existingBinding) {
                return json(['code' => 400, 'msg' => '该账号已被其他用户绑定']);
            }

            // 创建绑定
            $binding = new UserOAuthBinding();
            $binding->user_id = $user->id;
            $binding->provider = $provider;
            $binding->provider_user_id = $userData['id'];
            $binding->provider_username = $userData['username'] ?? null;
            $binding->provider_email = $userData['email'] ?? null;
            $binding->provider_avatar = $userData['avatar'] ?? null;
            $binding->access_token = $userData['access_token'] ?? null;
            $binding->refresh_token = $userData['refresh_token'] ?? null;
            $binding->expires_at = $userData['expires_at'] ?? null;
            $binding->extra_data = $userData['extra'] ?? null;
            $binding->save();

            return json([
                'code' => 0,
                'msg' => '绑定成功',
                'data' => [
                    'provider' => $provider,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('OAuth bind failed: ' . $e->getMessage());

            return json(['code' => 500, 'msg' => '绑定失败']);
        }
    }

    /**
     * 解绑OAuth账户
     *
     * @param Request $request
     * @param string  $provider
     *
     * @return Response
     */
    public function unbindOAuth(Request $request, string $provider): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if (!$userId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        try {
            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }

            $binding = $user->getOAuthBinding($provider);
            if (!$binding) {
                return json(['code' => 400, 'msg' => '未绑定该平台']);
            }

            // 删除绑定
            $binding->delete();

            return json([
                'code' => 0,
                'msg' => '解绑成功',
            ]);
        } catch (Exception $e) {
            Log::error('OAuth unbind failed: ' . $e->getMessage());

            return json(['code' => 500, 'msg' => '解绑失败']);
        }
    }

    /**
     * 用户中心
     *
     * @param Request $request
     *
     * @return Response
     */
    public function center(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if (!$userId) {
            return redirect('/user/login');
        }

        $user = User::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        // 获取用户的OAuth绑定
        $bindings = $user->oauthBindings;
        $supportedProviders = UserOAuthBinding::getSupportedProviders();

        return view('user/center', [
            'user' => $user,
            'bindings' => $bindings,
            'supportedProviders' => $supportedProviders,
        ]);
    }

    // ==================== 私有方法 ====================

    /**
     * 获取OAuth配置
     *
     * @param string $provider
     *
     * @return array|null
     */
    private function getOAuthConfig(string $provider): ?array
    {
        try {
            // 从数据库读取配置
            $config = blog_config('oauth_' . $provider, null, false, true);

            if (!$config || !is_array($config)) {
                return null;
            }

            // 检查是否启用
            if (isset($config['enabled']) && !$config['enabled']) {
                return null;
            }

            // 验证必要字段
            $requiredFields = ['client_id', 'client_secret'];

            foreach ($requiredFields as $field) {
                if (empty($config[$field])) {
                    return null;
                }
            }

            // 确保有回调URL
            if (empty($config['redirect_uri'])) {
                $config['redirect_uri'] = request()->host() . '/oauth/' . $provider . '/callback';
            }

            return $config;
        } catch (Throwable $e) {
            Log::error('Get OAuth config failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 构建授权URL
     *
     * @param string $provider
     * @param array  $config
     * @param string $state
     *
     * @return string
     */
    private function getAuthorizationUrl(string $provider, array $config, string $state): string
    {
        $oauthService = new \app\service\OAuthService();

        // 使用 OAuthService 获取授权 URL
        $scopes = match ($provider) {
            'wind' => ['basic', 'profile'],
            'github' => ['user:email'],
            'google' => ['openid', 'email', 'profile'],
            default => [],
        };

        $authUrl = $oauthService->getAuthorizationUrl($provider, $config, $state, $scopes);

        if (!$authUrl) {
            throw new Exception('不支持的OAuth平台');
        }

        return $authUrl;
    }

    /**
     * 获取OAuth用户数据
     *
     * @param string $provider
     * @param string $code
     *
     * @return array|null
     */
    private function getOAuthUserData(string $provider, string $code): ?array
    {
        try {
            // 获取 OAuth 配置
            $config = $this->getOAuthConfig($provider);
            if (!$config) {
                return null;
            }

            // 使用 OAuthService 获取用户数据
            $oauthService = new \app\service\OAuthService();

            return $oauthService->getUserData($provider, $code, $config);
        } catch (\Throwable $e) {
            Log::error('Get OAuth user data failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 从 OAuth 数据查找或创建用户
     *
     * @param string $provider
     * @param array  $userData
     *
     * @return User|null
     */
    private function findOrCreateUserFromOAuth(string $provider, array $userData): ?User
    {
        // 查找OAuth绑定
        $binding = UserOAuthBinding::findByProvider($provider, $userData['id']);
        if ($binding) {
            return $binding->user;
        }

        // 如果有邮箱，尝试查找已存在的用户
        if (!empty($userData['email'])) {
            $user = User::where('email', $userData['email'])->first();
            if ($user) {
                // 自动绑定
                $this->createOAuthBinding($user, $provider, $userData);

                return $user;
            }
        }

        // 创建新用户
        try {
            $user = new User();
            $user->username = $provider . '_' . substr($userData['id'], 0, 16) . '_' . substr(uniqid(), -4);
            $user->nickname = $userData['username'] ?? '用户' . substr(uniqid(), -6);
            $user->email = $userData['email'] ?? null;
            $user->password = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $user->avatar = $userData['avatar'] ?? null;
            $user->status = 1; // 直接激活
            $user->email_verified_at = !empty($userData['email']) ? date('Y-m-d H:i:s') : null;
            $user->join_time = date('Y-m-d H:i:s');
            $user->join_ip = request()->getRealIp();
            $user->save();

            // 创建 OAuth 绑定
            $this->createOAuthBinding($user, $provider, $userData);

            return $user;
        } catch (Exception $e) {
            Log::error('Failed to create user from OAuth: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 创建 OAuth 绑定
     *
     * @param User   $user
     * @param string $provider
     * @param array  $userData
     *
     * @return void
     */
    private function createOAuthBinding(User $user, string $provider, array $userData): void
    {
        $binding = new UserOAuthBinding();
        $binding->user_id = $user->id;
        $binding->provider = $provider;
        $binding->provider_user_id = $userData['id'];
        $binding->provider_username = $userData['username'] ?? null;
        $binding->provider_email = $userData['email'] ?? null;
        $binding->provider_avatar = $userData['avatar'] ?? null;
        $binding->access_token = $userData['access_token'] ?? null;
        $binding->refresh_token = $userData['refresh_token'] ?? null;
        $binding->expires_at = $userData['expires_at'] ?? null;
        $binding->extra_data = $userData['extra'] ?? null;
        $binding->save();
    }
}

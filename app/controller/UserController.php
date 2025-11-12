<?php

namespace app\controller;

use app\annotation\CSRFVerify;
use app\helper\BreadcrumbHelper;
use app\model\User;
use app\model\UserOAuthBinding;
use app\service\CaptchaService;
use app\service\CSRFService;
use app\service\MailService;
use app\service\OAuthService;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class UserController
{
    /**
     * 不需要登录的方法
     * register: 用户注册
     * login: 用户登录
     * activate: 激活账户
     * resendActivation: 重发激活邮件
     * oauthRedirect: OAuth登录跳转
     * oauthCallback: OAuth回调
     * profileApi: 获取用户信息API
     */
    protected array $noNeedLogin = [
        'register',
        'login',
        'activate',
        'resendActivation',
        'oauthRedirect',
        'oauthCallback',
        'profileApi',
    ];

    /**
     * 用户注册
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    #[CSRFVerify(tokenName: '_token', methods: ['POST'])]
    public function register(Request $request): Response
    {
        // 同步封装的验证码验证（内部使用 http-client，短暂等待，不会长时间阻塞）
        [$ok, $msg] = CaptchaService::verify($request);
        if (!$ok) {
            return json(['code' => 400, 'msg' => $msg]);
        }
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
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->status = 0; // 未激活状态
            $user->join_time = utc_now();
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
    #[CSRFVerify(tokenName: '_token', methods: ['POST'])]
    public function login(Request $request): Response
    {
        [$ok, $msg] = CaptchaService::verify($request);
        if (!$ok) {
            return json(['code' => 400, 'msg' => $msg]);
        }

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
        $user->last_time = utc_now();
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
     * @throws Exception
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
                'mobile' => $user->mobile,
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
     * 更新用户资料 API
     *
     * @param Request $request
     *
     * @return Response
     */
    #[CSRFVerify(tokenName: '_token', methods: ['POST'])]
    public function updateProfile(Request $request): Response
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

        // 获取要更新的字段
        $nickname = trim($request->post('nickname', ''));
        $email = trim($request->post('email', ''));
        $mobile = trim($request->post('mobile', ''));

        // 验证昵称
        if (!empty($nickname)) {
            if (mb_strlen($nickname, 'UTF-8') < 2) {
                return json(['code' => 400, 'msg' => '昵称至少需要2个字符']);
            }
            if (mb_strlen($nickname, 'UTF-8') > 32) {
                return json(['code' => 400, 'msg' => '昵称不能超过32个字符']);
            }
            $user->nickname = $nickname;
        }

        // 验证邮箱
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return json(['code' => 400, 'msg' => '邮箱格式不正确']);
            }
            // 检查邮箱是否已被其他用户使用
            $existingUser = User::where('email', $email)
                ->where('id', '!=', $userId)
                ->first();
            if ($existingUser) {
                return json(['code' => 400, 'msg' => '该邮箱已被其他用户使用']);
            }

            // 如果邮箱改变，需要重新验证
            if ($email !== $user->email) {
                $user->email = $email;
                $user->email_verified_at = null;
                // 可以在这里发送新的验证邮件
            }
        }

        // 验证手机号
        if (!empty($mobile)) {
            if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
                return json(['code' => 400, 'msg' => '手机号格式不正确']);
            }
            $user->mobile = $mobile;
        } elseif ($request->post('mobile') === '') {
            // 允许清空手机号
            $user->mobile = null;
        }

        try {
            if ($user->save()) {
                // 更新session中的昵称
                $session->set('nickname', $user->nickname);

                return json([
                    'code' => 0,
                    'msg' => '资料更新成功',
                    'data' => [
                        'nickname' => $user->nickname,
                        'email' => $user->email,
                        'mobile' => $user->mobile,
                        'email_verified' => $user->isEmailVerified(),
                        'avatar_url' => $user->getAvatarUrl(200, 'identicon'),
                    ],
                ]);
            }

            return json(['code' => 500, 'msg' => '更新失败，请稍后重试']);
        } catch (Exception $e) {
            Log::error('Update profile failed: ' . $e->getMessage());

            return json(['code' => 500, 'msg' => '更新失败，请稍后重试']);
        }
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

            // 入队
            $mailData = [
                'to' => $user->email,
                'subject' => $subject,
                'html' => $html,
                'priority' => 'high',
            ];

            MailService::enqueue($mailData);

            Log::info("Activation email queued for user: {$user->username} ({$user->email})");
        } catch (Throwable $e) {
            Log::error('Failed to queue activation email: ' . $e->getMessage());
        }
    }

    /**
     * 忘记密码页面
     */
    public function forgotPasswordPage(Request $request): Response
    {
        $csrf = (new CSRFService())->generateToken($request, '_token');

        return view('user/forgot-password', ['csrf_token' => $csrf]);
    }

    /**
     * 提交忘记密码
     */
    #[CSRFVerify(tokenName: '_token', methods: ['POST'])]
    public function forgotPassword(Request $request): Response
    {
        $email = trim((string) $request->post('email', ''));
        if ($email === '') {
            return json(['code' => 400, 'msg' => '邮箱不能为空']);
        }
        // 验证验证码
        [$ok, $msg] = CaptchaService::verify($request);
        if (!$ok) {
            return json(['code' => 400, 'msg' => $msg]);
        }
        $user = User::where('email', $email)->first();
        if ($user) {
            try {
                $token = bin2hex(random_bytes(32));
                $user->password_reset_token = $token;
                $user->password_reset_expire = utc_now()->addHour();
                $user->save();
                $this->sendPasswordResetEmail($user, $token);
            } catch (Throwable $e) {
                Log::error('generate reset token failed: ' . $e->getMessage());
            }
        }

        // 统一返回，避免枚举邮箱
        return json(['code' => 0, 'msg' => '如果该邮箱已注册，我们将发送重置链接至您的邮箱']);
    }

    /**
     * 重置密码页面
     */
    public function resetPasswordPage(Request $request): Response
    {
        $token = (string) $request->get('token', '');
        if ($token === '') {
            return view('user/reset-password-error', ['message' => '重置链接无效']);
        }
        $user = User::where('password_reset_token', $token)->first();
        if (!$user || !$user->password_reset_expire || utc_now()->gt($user->password_reset_expire)) {
            return view('user/reset-password-error', ['message' => '重置链接无效或已过期']);
        }
        $csrf = (new CSRFService())->generateToken($request, '_token');

        return view('user/reset-password', ['token' => $token, 'csrf_token' => $csrf]);
    }

    /**
     * 提交重置密码
     */
    #[CSRFVerify(tokenName: '_token', methods: ['POST'])]
    public function resetPassword(Request $request): Response
    {
        $token = (string) $request->post('token', '');
        $pwd = (string) $request->post('password', '');
        $pwd2 = (string) $request->post('password_confirm', '');
        if ($token === '' || $pwd === '' || $pwd2 === '') {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        if ($pwd !== $pwd2) {
            return json(['code' => 400, 'msg' => '两次输入的密码不一致']);
        }
        if (strlen($pwd) < 6) {
            return json(['code' => 400, 'msg' => '密码长度至少为6个字符']);
        }
        $user = User::where('password_reset_token', $token)->first();
        if (!$user || !$user->password_reset_expire || utc_now()->gt($user->password_reset_expire)) {
            return json(['code' => 400, 'msg' => '重置链接无效或已过期']);
        }
        $user->password = password_hash($pwd, PASSWORD_DEFAULT);
        $user->password_reset_token = null;
        $user->password_reset_expire = null;
        $user->save();

        return json(['code' => 0, 'msg' => '密码重置成功，请使用新密码登录']);
    }

    private function sendPasswordResetEmail(User $user, string $token): void
    {
        try {
            $resetUrl = request()->host() . '/user/reset-password?token=' . $token;
            $subject = '重置您的密码';
            $html = <<<HTML
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>重置密码</title>
                </head>
                <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                        <h2 style="color: #4a90e2;">重置您的密码</h2>
                        <p>您好，{$user->username}！</p>
                        <p>我们收到了您的密码重置请求。请点击下方按钮重置您的密码：</p>
                        <div style="text-align: center; margin: 30px 0;">
                            <a href="{$resetUrl}"
                               style="background-color: #4a90e2; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
                                重置密码
                            </a>
                        </div>
                        <p>或者复制以下链接到浏览器中打开：</p>
                        <p style="word-break: break-all; color: #666;">{$resetUrl}</p>
                        <p style="color: #999; font-size: 14px;">此链接将在1小时后失效。</p>
                        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                        <p style="color: #999; font-size: 12px;">如果您没有请求重置密码，请忽略此邮件。</p>
                    </div>
                </body>
                </html>
                HTML;
            MailService::enqueue([
                'to' => $user->email,
                'subject' => $subject,
                'html' => $html,
                'priority' => 'high',
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to enqueue reset email: ' . $e->getMessage());
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
                return view('user/oauth-error', array_merge($this->getSiteData(), ['message' => '非法请求，请重试']));
            }

            $code = $request->get('code');
            if (!$code) {
                return view('user/oauth-error', array_merge($this->getSiteData(), ['message' => '授权失败，未获取授权码']));
            }

            // 获取OAuth用户信息
            $userData = $this->getOAuthUserData($provider, $code);
            if (!$userData) {
                return view('user/oauth-error', array_merge($this->getSiteData(), ['message' => '获取用户信息失败']));
            }

            // 查找或创建用户
            $user = $this->findOrCreateUserFromOAuth($provider, $userData);
            if (!$user) {
                return view('user/oauth-error', array_merge($this->getSiteData(), ['message' => '用户创建失败']));
            }

            // 登录用户
            $session->set('user_id', $user->id);
            $session->set('username', $user->username);
            $session->delete('oauth_state');

            // 更新登录信息
            $user->last_time = utc_now();
            $user->last_ip = $request->getRealIp();
            $user->save();

            return redirect('/user/center');
        } catch (Exception $e) {
            Log::error('OAuth callback failed: ' . $e->getMessage());

            return view('user/oauth-error', array_merge($this->getSiteData(), ['message' => 'OAuth登录失败']));
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

        // 生成面包屑导航
        $breadcrumbs = BreadcrumbHelper::forUserCenter('用户中心');

        // 生成CSRF token
        $csrf = (new CSRFService())->generateToken($request, '_token');

        return view('user/center', array_merge($this->getSiteData(), [
            'user' => $user,
            'bindings' => $bindings,
            'supportedProviders' => $supportedProviders,
            'breadcrumbs' => $breadcrumbs,
            'csrf_token' => $csrf,
        ]));
    }

    // ==================== 私有方法 ====================

    /**
     * 获取站点信息用于视图
     *
     * @return array
     */
    private function getSiteData(): array
    {
        $siteTitle = blog_config('title', 'WindBlog', true);

        return [
            'page_title' => $siteTitle,
        ];
    }

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
                $scheme = request()->header('x-forwarded-proto') ?: (request()->connection->transport === 'ssl' ? 'https' : 'http');
                $host = request()->host();
                $config['redirect_uri'] = $scheme . '://' . $host . '/oauth/' . $provider . '/callback';
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
        $oauthService = new OAuthService();

        // 从配置中读取scopes，如果没有则使用默认值
        $scopes = $config['scopes'] ?? [];

        // 如果配置中没有scopes，使用内置平台的默认值
        if (empty($scopes)) {
            $scopes = match ($provider) {
                'wind' => ['basic', 'profile'],
                'github' => ['user:email'],
                'google' => ['openid', 'email', 'profile'],
                default => [],
            };
        }

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
            $oauthService = new OAuthService();

            return $oauthService->getUserData($provider, $code, $config);
        } catch (Throwable $e) {
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
            $user->password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $user->avatar = $userData['avatar'] ?? null;
            $user->status = 1; // 直接激活
            $user->email_verified_at = !empty($userData['email']) ? utc_now() : null;
            $user->join_time = utc_now();
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

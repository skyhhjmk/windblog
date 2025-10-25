<?php

namespace plugin\admin\app\controller;

use app\model\UserOAuthBinding;
use plugin\admin\app\model\User;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 用户管理
 */
class UserController extends Crud
{
    /**
     * @var User
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->model = new User();
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('user/index');
    }

    /**
     * 查询 - 重写以预加载OAuth绑定数据
     *
     * @param Request $request
     *
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function select(Request $request): Response
    {
        [$where, $format, $limit, $field, $order] = $this->selectInput($request);
        $query = $this->doSelect($where, $field, $order);

        // 预加载OAuth绑定数量
        $query = $query->withCount('oauthBindings as oauth_bindings_count');

        return $this->doFormat($query, $format, $limit);
    }

    /**
     * 插入
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return parent::insert($request);
        }

        return raw_view('user/insert');
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function update(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return parent::update($request);
        }

        return raw_view('user/update');
    }

    /**
     * 激活用户账户
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function activate(Request $request): Response
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少用户ID']);
        }

        $user = $this->model->find($id);
        if (!$user) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        // 激活账户
        $user->email_verified_at = date('Y-m-d H:i:s');
        $user->activation_token = null;
        $user->activation_token_expires_at = null;
        $user->status = 1; // 设置为正常状态

        if ($user->save()) {
            return json(['code' => 0, 'msg' => '激活成功']);
        }

        return json(['code' => 1, 'msg' => '激活失败']);
    }

    /**
     * 重置激活令牌
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function resetActivationToken(Request $request): Response
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少用户ID']);
        }

        $user = $this->model->find($id);
        if (!$user) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        // 生成新的激活令牌
        $token = bin2hex(random_bytes(32));
        $user->activation_token = $token;
        $user->activation_token_expires_at = date('Y-m-d H:i:s', time() + 24 * 3600);

        if ($user->save()) {
            return json([
                'code' => 0,
                'msg' => '重置成功',
                'data' => [
                    'token' => $token,
                    'activation_url' => request()->host() . '/user/activate?token=' . $token,
                ],
            ]);
        }

        return json(['code' => 1, 'msg' => '重置失败']);
    }

    /**
     * 获取用户的OAuth绑定列表
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function oauthBindings(Request $request): Response
    {
        $userId = $request->get('user_id');
        if (!$userId) {
            return json(['code' => 1, 'msg' => '缺少用户ID']);
        }

        $bindings = UserOAuthBinding::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get([
                'id',
                'provider',
                'provider_user_id',
                'provider_username',
                'provider_email',
                'provider_avatar',
                'created_at',
                'updated_at',
            ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $bindings]);
    }

    /**
     * 解绑OAuth
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function unbindOAuth(Request $request): Response
    {
        $bindingId = $request->post('binding_id');
        if (!$bindingId) {
            return json(['code' => 1, 'msg' => '缺少绑定ID']);
        }

        $binding = UserOAuthBinding::find($bindingId);
        if (!$binding) {
            return json(['code' => 1, 'msg' => 'OAuth绑定不存在']);
        }

        if ($binding->delete()) {
            return json(['code' => 0, 'msg' => '解绑成功']);
        }

        return json(['code' => 1, 'msg' => '解绑失败']);
    }

    /**
     * 添加OAuth绑定
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function addOAuthBinding(Request $request): Response
    {
        $userId = $request->post('user_id');
        $provider = $request->post('provider');
        $providerUserId = $request->post('provider_user_id');
        $providerUsername = $request->post('provider_username');
        $providerEmail = $request->post('provider_email');

        if (!$userId || !$provider || !$providerUserId) {
            return json(['code' => 1, 'msg' => '缺少必要参数']);
        }

        // 检查用户是否存在
        $user = $this->model->find($userId);
        if (!$user) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        // 检查是否已经绑定
        $existing = UserOAuthBinding::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existing) {
            if ($existing->user_id == $userId) {
                return json(['code' => 1, 'msg' => '该OAuth账号已绑定到此用户']);
            }

            return json(['code' => 1, 'msg' => '该OAuth账号已绑定到其他用户']);
        }

        // 创建绑定
        $binding = new UserOAuthBinding();
        $binding->user_id = $userId;
        $binding->provider = $provider;
        $binding->provider_user_id = $providerUserId;
        $binding->provider_username = $providerUsername;
        $binding->provider_email = $providerEmail;

        if ($binding->save()) {
            return json(['code' => 0, 'msg' => '绑定成功', 'data' => $binding]);
        }

        return json(['code' => 1, 'msg' => '绑定失败']);
    }
}

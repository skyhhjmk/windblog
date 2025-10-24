<?php

namespace plugin\admin\app\controller;

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
}

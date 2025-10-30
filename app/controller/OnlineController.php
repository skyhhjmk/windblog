<?php

namespace app\controller;

use app\model\User;
use app\service\LocationService;
use app\service\OnlineUserService;
use support\Request;
use support\Response;

/**
 * 在线用户统计控制器
 */
class OnlineController
{
    /**
     * 不需要登录的方法
     *
     * @var array
     */
    protected $noNeedLogin = [];

    /**
     * 在线用户服务
     *
     * @var OnlineUserService
     */
    private OnlineUserService $onlineService;

    /**
     * 地理位置服务
     *
     * @var LocationService
     */
    private LocationService $locationService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->onlineService = new OnlineUserService();
        $this->locationService = new LocationService();
    }

    /**
     * 获取在线用户数量
     *
     * @param Request $request
     *
     * @return Response
     */
    public function count(Request $request): Response
    {
        $count = $this->onlineService->getOnlineCount();

        return json([
            'code' => 0,
            'data' => [
                'count' => $count,
            ],
        ]);
    }

    /**
     * 获取在线用户列表
     *
     * @param Request $request
     *
     * @return Response
     */
    public function list(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);

        // 限制每页数量
        $pageSize = min(max($pageSize, 1), 100);

        $result = $this->onlineService->getOnlineUsers($page, $pageSize);

        return json([
            'code' => 0,
            'data' => $result,
        ]);
    }

    /**
     * 获取在线统计信息
     *
     * @param Request $request
     *
     * @return Response
     */
    public function stats(Request $request): Response
    {
        $stats = $this->onlineService->getOnlineStats();

        return json([
            'code' => 0,
            'data' => $stats,
        ]);
    }

    /**
     * 用户心跳接口
     *
     * @param Request $request
     *
     * @return Response
     */
    public function heartbeat(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if (!$userId) {
            return json([
                'code' => 401,
                'msg' => '未登录',
            ]);
        }

        // 发送心跳
        $result = $this->onlineService->userHeartbeat($userId);

        if ($result) {
            return json([
                'code' => 0,
                'msg' => '心跳成功',
            ]);
        }

        return json([
            'code' => 500,
            'msg' => '心跳失败',
        ]);
    }

    /**
     * 用户上线（连接Push服务时调用）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function online(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if (!$userId) {
            return json([
                'code' => 401,
                'msg' => '未登录',
            ]);
        }

        // 获取用户信息
        $user = User::find($userId);
        if (!$user) {
            return json([
                'code' => 404,
                'msg' => '用户不存在',
            ]);
        }

        // 获取 IP 和地理位置
        $ip = $request->getRealIp();
        $location = $this->locationService->getLocationByIp($ip);

        // 获取前端传来的客户端信息
        $clientInfo = $request->post('client_info', []);

        // 用户上线
        $userInfo = [
            'user_id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'avatar' => $user->getAvatarUrl(50),
            'ip' => $ip,
            'location' => $location['display'] ?? '未知地区',
            'location_full' => $location,
            'user_agent' => $request->header('user-agent', ''),
            'client_info' => $clientInfo,
        ];

        $result = $this->onlineService->userOnline($userId, $userInfo);

        if ($result) {
            // 广播用户上线消息
            $this->onlineService->broadcastUserOnline($userInfo, false);

            // 广播在线人数更新
            $this->onlineService->broadcastOnlineStats();

            return json([
                'code' => 0,
                'msg' => '上线成功',
                'data' => [
                    'user_id' => $userId,
                    'location' => $location['display'] ?? '未知地区',
                    'welcome_message' => sprintf('欢迎来自%s的用户', $location['display'] ?? '未知地区'),
                ],
            ]);
        }

        return json([
            'code' => 500,
            'msg' => '上线失败',
        ]);
    }

    /**
     * 用户下线（断开Push连接时调用）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function offline(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if (!$userId) {
            return json([
                'code' => 401,
                'msg' => '未登录',
            ]);
        }

        // 用户下线
        $result = $this->onlineService->userOffline($userId);

        if ($result) {
            // 广播在线人数更新
            $this->onlineService->broadcastOnlineCount();

            return json([
                'code' => 0,
                'msg' => '下线成功',
            ]);
        }

        return json([
            'code' => 500,
            'msg' => '下线失败',
        ]);
    }

    /**
     * 检查用户是否在线
     *
     * @param Request $request
     * @param int     $userId
     *
     * @return Response
     */
    public function check(Request $request, int $userId): Response
    {
        $isOnline = $this->onlineService->isUserOnline($userId);

        return json([
            'code' => 0,
            'data' => [
                'user_id' => $userId,
                'is_online' => $isOnline,
            ],
        ]);
    }
}

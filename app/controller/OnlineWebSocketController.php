<?php

namespace app\controller;

use app\model\User;
use app\service\LocationService;
use app\service\OnlineUserService;
use support\Log;
use support\Request;
use support\Response;

/**
 * 在线用户 WebSocket 连接控制器
 */
class OnlineWebSocketController
{
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
     * 连接建立时（客户端上线）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function connect(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');
        $ip = $request->getRealIp();

        // 获取前端传来的客户端信息
        $clientInfo = $request->post('client_info', []);

        Log::info('Online connect called', [
            'user_id' => $userId,
            'session_id' => $session->getId(),
            'ip' => $ip,
            'client_info' => $clientInfo,
        ]);

        // 获取地理位置信息
        $location = $this->locationService->getLocationByIp($ip);

        if ($userId) {
            // 已登录用户
            $user = User::find($userId);
            if ($user) {
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
                Log::info('User online result', ['result' => $result, 'user_id' => $userId]);

                // 广播用户上线消息
                $this->onlineService->broadcastUserOnline($userInfo, false);
            }
        } else {
            // 访客 - 使用 session_id 作为唯一标识
            $guestId = $session->getId();
            if ($guestId) {
                $guestInfo = [
                    'guest_id' => $guestId,
                    'ip' => $ip,
                    'location' => $location['display'] ?? '未知地区',
                    'location_full' => $location,
                    'user_agent' => $request->header('user-agent', ''),
                    'client_info' => $clientInfo,
                ];

                $result = $this->onlineService->guestOnline($guestId, $guestInfo);
                Log::info('Guest online result', ['result' => $result, 'guest_id' => $guestId]);

                // 广播访客上线消息
                $this->onlineService->broadcastUserOnline($guestInfo, true);
            } else {
                Log::warning('No session ID available');
            }
        }

        // 广播在线人数更新
        $broadcastResult = $this->onlineService->broadcastOnlineStats();
        Log::info('Broadcast result', ['result' => $broadcastResult]);

        // 获取当前统计并返回
        $stats = $this->onlineService->getOnlineStats();

        // 构建欢迎消息
        $welcomeMessage = $this->buildWelcomeMessage($location, $userId !== null);

        return json([
            'code' => 0,
            'msg' => '连接成功',
            'data' => [
                'stats' => $stats,
                'location' => $location,
                'welcome_message' => $welcomeMessage,
            ],
        ]);
    }

    /**
     * 构建欢迎消息
     *
     * @param array|null $location 地理位置信息
     * @param bool       $isUser   是否为已登录用户
     *
     * @return string
     */
    private function buildWelcomeMessage(?array $location, bool $isUser): string
    {
        if (!$location) {
            return $isUser ? '欢迎回来！' : '欢迎访问！';
        }

        $locationDisplay = $location['display'] ?? '未知地区';

        if ($isUser) {
            return sprintf('欢迎来自%s的用户', $locationDisplay);
        }

        return sprintf('欢迎来自%s的访客', $locationDisplay);
    }

    /**
     * 连接断开时（客户端下线）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function disconnect(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if ($userId) {
            // 已登录用户下线
            $this->onlineService->userOffline($userId);
        } else {
            // 访客下线
            $guestId = $session->getId();
            if ($guestId) {
                $this->onlineService->guestOffline($guestId);
            }
        }

        // 广播在线人数更新
        $this->onlineService->broadcastOnlineStats();

        return json([
            'code' => 0,
            'msg' => '断开成功',
        ]);
    }

    /**
     * 心跳接口
     *
     * @param Request $request
     *
     * @return Response
     */
    public function heartbeat(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');

        if ($userId) {
            // 已登录用户心跳
            $this->onlineService->userHeartbeat($userId);
        } else {
            // 访客心跳
            $guestId = $session->getId();
            if ($guestId) {
                $this->onlineService->guestHeartbeat($guestId);
            }
        }

        return json([
            'code' => 0,
            'msg' => '心跳成功',
        ]);
    }

    /**
     * 获取当前在线统计
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
}

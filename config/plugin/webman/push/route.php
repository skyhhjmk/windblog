<?php

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Request;
use Webman\Push\Api;
use Webman\Route;

/**
 * 推送js客户端文件
 */
Route::get('/plugin/webman/push/push.js', function (Request $request) {
    return response()->file(base_path() . '/vendor/webman/push/src/push.js');
});

/**
 * 私有频道鉴权,这里应该使用session辨别当前用户身份,然后确定该用户是否有权限监听channel_name
 */
Route::post(config('plugin.webman.push.app.auth'), function (Request $request) {
    $pusher = new Api(
        str_replace('0.0.0.0', '127.0.0.1', config('plugin.webman.push.app.api')),
        config('plugin.webman.push.app.app_key'),
        config('plugin.webman.push.app.app_secret')
    );

    $channel_name = $request->post('channel_name');
    $socket_id = $request->post('socket_id');
    $session = $request->session();

    // 验证参数
    if (empty($channel_name) || empty($socket_id)) {
        return response('Invalid parameters', 400);
    }

    // 检查用户是否登录(兼容管理员和普通用户)
    $userId = $session->get('user_id') ?? $session->get('admin');
    if (!$userId) {
        return response('Unauthorized', 401);
    }

    // 权限验证逻辑
    $has_authority = false;

    // 1. 公共频道(public-*)允许所有已登录用户
    if (str_starts_with($channel_name, 'public-')) {
        $has_authority = true;
    } // 2. 私有用户频道(private-user-*)只允许用户自己访问
    elseif (preg_match('/^private-user-(\d+)$/', $channel_name, $matches)) {
        $channelUserId = (int) $matches[1];
        $has_authority = ($userId == $channelUserId);
    } // 3. 管理员频道(private-admin-*)只允许管理员访问
    elseif (str_starts_with($channel_name, 'private-admin-')) {
        $has_authority = $session->get('admin') ? true : false;
    } // 4. 在线统计频道(presence-online)允许所有已登录用户
    elseif ($channel_name === 'presence-online') {
        $has_authority = true;
    }

    if ($has_authority) {
        // 对于presence频道,添加用户信息
        if (str_starts_with($channel_name, 'presence-')) {
            // user_info 不应该包含 user_id，因为 user_id 是单独的参数
            $userInfo = [
                'username' => $session->get('username') ?? 'admin',
            ];
            // 确保 userId 是标量值
            $userIdStr = is_array($userId) ? json_encode($userId) : strval($userId);
            // presenceAuth 返回 JSON 字符串
            $authJson = $pusher->presenceAuth($channel_name, $socket_id, $userIdStr, $userInfo);
            // 确保返回的是字符串
            if (!is_string($authJson)) {
                $authJson = json_encode($authJson);
            }

            return response($authJson)->withHeader('Content-Type', 'application/json');
        }

        // socketAuth 返回 JSON 字符串
        $authJson = $pusher->socketAuth($channel_name, $socket_id);

        return response($authJson)->withHeader('Content-Type', 'application/json');
    }

    return response('Forbidden', 403);
});

/**
 * 当频道上线以及下线时触发的回调
 * 频道上线:是指某个频道从没有连接在线到有连接在线的事件
 * 频道下线:是指某个频道的所有连接都断开触发的事件
 */
Route::post(parse_url(config('plugin.webman.push.app.channel_hook'), PHP_URL_PATH), function (Request $request) {
    // 没有x-pusher-signature头视为伪造请求
    if (!$webhook_signature = $request->header('x-pusher-signature')) {
        return response('401 Not authenticated', 401);
    }

    $body = $request->rawBody();

    // 计算签名,$app_secret 是双方使用的密钥,是保密的,外部无从得知
    $expected_signature = hash_hmac('sha256', $body, config('plugin.webman.push.app.app_secret'), false);

    // 安全校验,如果签名不一致可能是伪造的请求,返回401状态码
    if ($webhook_signature !== $expected_signature) {
        return response('401 Not authenticated', 401);
    }

    try {
        // 解析webhook数据
        $payload = json_decode($body, true);

        if (!$payload || !isset($payload['events'])) {
            return response('Invalid payload', 400);
        }

        $channels_online = [];
        $channels_offline = [];
        $members_added = [];
        $members_removed = [];

        foreach ($payload['events'] as $event) {
            $eventName = $event['name'] ?? '';
            $channel = $event['channel'] ?? '';

            switch ($eventName) {
                case 'channel_added':
                    $channels_online[] = $channel;
                    break;
                case 'channel_removed':
                    $channels_offline[] = $channel;
                    break;
                case 'member_added':
                    $members_added[] = [
                        'channel' => $channel,
                        'user_id' => $event['user_id'] ?? null,
                    ];
                    break;
                case 'member_removed':
                    $members_removed[] = [
                        'channel' => $channel,
                        'user_id' => $event['user_id'] ?? null,
                    ];
                    break;
            }
        }

        // 处理在线用户统计
        if (!empty($members_added) || !empty($members_removed)) {
            // 使用OnlineUserService处理在线用户变化
            $onlineService = new \app\service\OnlineUserService();

            foreach ($members_added as $member) {
                if ($member['user_id']) {
                    $onlineService->userOnline($member['user_id']);
                }
            }

            foreach ($members_removed as $member) {
                if ($member['user_id']) {
                    $onlineService->userOffline($member['user_id']);
                }
            }
        }

        // 记录日志
        if (!empty($channels_online)) {
            \support\Log::info('Push channels online: ' . implode(',', $channels_online));
        }
        if (!empty($channels_offline)) {
            \support\Log::info('Push channels offline: ' . implode(',', $channels_offline));
        }

        return response('OK');
    } catch (\Throwable $e) {
        \support\Log::error('Push webhook error: ' . $e->getMessage());

        return response('Internal Server Error', 500);
    }
});

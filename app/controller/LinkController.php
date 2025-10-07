<?php
/*
 * 这里面有很多屎山
 */

namespace app\controller;

use app\annotation\CSRFVerify;
use app\service\CSRFHelper;
use app\service\MQService;
use PhpAmqpLib\Message\AMQPMessage;
use support\Request;
use app\model\Link;
use app\service\PaginationService;
use support\Response;
use Throwable;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * 链接广场控制器
 */
class LinkController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index', 'goto', 'info', 'request'];

    public function index(Request $request, int $page = 1)
    {
        $count = Link::where('status', 'true')->count('*');

        $links_per_page = blog_config('links_per_page', 15, true);

        // 使用分页服务生成分页HTML
        $pagination_html = PaginationService::generatePagination(
            $page,
            $count,
            $links_per_page,
            'link.page',
            [],
            10
        );

        if (empty($request->get())) {
            $cached = cache('blog_links_page_' . $page);
            if ($cached) {
                return $cached;
            } else {
                $links = Link::where('status', 'true')->orderByDesc('id')->forPage($page, $links_per_page)->get();
                cache('blog_links_page_' . $page . '_per_' . $links_per_page, $links, true);
            }
        } else {
            $links = Link::where('status', 'true')->orderByDesc('id')->forPage($page, $links_per_page)->get();
        }

        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';
        // 侧边栏（PJAX 与非 PJAX 均获取）
        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'link');
        return view($isPjax ? 'link/index.content' : 'link/index', [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 链接广场',
            'links' => $links,
            'pagination' => $pagination_html,
            'sidebar' => $sidebar
        ]);
    }

    /**
     * 链接跳转方法
     *
     * @param Request $request
     * @param int     $id 链接ID
     *
     * @return Response
     * @throws Throwable
     */
    public function goto(Request $request, int $id): Response
    {
        // 查找链接
        $link = Link::find($id);

        // 检查链接是否存在
        if (!$link) {
            return view('error/404', [
                'message' => '链接不存在'
            ]);
        }

        // 检查链接是否启用
        if (!$link->status) {
            return view('error/404', [
                'message' => '链接已被禁用'
            ]);
        }

        // 异步发送回调请求（如果设置了callback_url）
        if (!empty($link->callback_url)) {
            $this->sendCallbackAsync($link);
        }

        // 根据跳转类型处理
        return match ($link->redirect_type) {
            'direct' => redirect($link->url, $link->target === '_blank' ? 302 : 301),
            'iframe' => redirect($link->url),
            default => view('link/goto', [
                'link' => $link,
                'page_title' => blog_config('title', 'WindBlog', true) . ' - 外链跳转确认'
            ]),
        };
    }

    /**
     * 链接详情页面
     *
     * @param Request $request
     * @param int     $id 链接ID
     *
     * @return Response
     */
    public function info(Request $request, int $id): Response
    {
        // 查找链接
        $link = Link::find($id);

        // 检查链接是否存在
        if (!$link) {
            return view('error/404', [
                'message' => '链接不存在'
            ]);
        }

        // 检查链接是否启用
        if (!$link->status) {
            return view('error/404', [
                'message' => '链接已被禁用'
            ]);
        }

        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';
        // 侧边栏（PJAX 与非 PJAX 均获取）
        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'link');
        return view($isPjax ? 'link/info.content' : 'link/info', [
            'link' => $link,
            'page_title' => $link->name . ' - 链接详情',
            'sidebar' => $sidebar
        ]);
    }

    /**
     * 申请友链页面
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    #[RateLimiter(limit: 3, ttl: 3600, message: '短时间内提交次数过多')]
    #[RateLimiter(limit: 3, ttl: 3600, key: RateLimiter::SID, message: '短时间内提交次数过多')]
    #[CSRFVerify(
        tokenName: '_link_request_token',
        if_failed_config: [
            'response_type' => 'json',
            'response_code' => 403,
            'response_body' => [
                'code' => 1,
                'msg' => 'CSRF 过期，请刷新页面重试'
            ]
        ],
        methods: ['POST'],
        expire: 3600,
        oneTime: true, // 一次性
    )]
    public function request(Request $request): Response
    {
        // 如果是POST请求，处理表单提交
        if ($request->method() === 'POST') {
            // 验证蜜罐
            $honeypot = $request->post('fullname', '');
            if (!empty($honeypot)) {
                return json(['code' => 1, 'msg' => '人机验证不通过']);
            }

            // 获取表单数据
            $name = $request->post('name', '');
            $url = $request->post('url', '');
            $icon = $request->post('icon', '');
            $description = $request->post('description', '');
            $contact = $request->post('contact', '');
            $supports_wind_connect = $request->post('supports_wind_connect') === 'on';
            $allows_crawling = $request->post('allows_crawling') === 'on';
            $hide_url = $request->post('hide_url') === 'on';
            $callback_url = $request->post('callback_url', '');
            $email = $request->post('email', '');
            $full_description = $request->post('full_description', '');


            $ipAddress = $request->getRealIp();
            $show_url = !(bool)$hide_url; // 取反值，即将是否隐藏 url 转为是否显示 url

            // 增强验证
            if (empty($name) || empty($url) || empty($description)) {
                return json(['code' => 1, 'msg' => '请填写必填字段']);
            }

            // 更严格的URL验证
            if (!filter_var($url, FILTER_VALIDATE_URL) ||
                !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_+.~#?&\/=]*)?$/', $url)) {
                return json(['code' => 1, 'msg' => '请输入有效的网址']);
            }

            // 验证图标URL（如果提供了）
            if (!empty($icon)) {
                if (!filter_var($icon, FILTER_VALIDATE_URL) ||
                    !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_+.~#?&\/=]*)?$/', $icon)) {
                    return json(['code' => 1, 'msg' => '请输入有效的网站图标地址']);
                }
            }

            // 验证回调URL（如果提供了）
            if (!empty($callback_url)) {
                if (!filter_var($callback_url, FILTER_VALIDATE_URL) ||
                    !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_+.~#?&\/=]*)?$/', $callback_url)) {
                    return json(['code' => 1, 'msg' => '请输入有效的回调地址']);
                }
            }

            // 验证邮箱地址（如果提供了）
            if (!empty($email)) {
                // 使用 PHP 内置过滤器进行基础验证
                // 并通过正则表达式进行更严格的格式检查
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
                    !preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email)) {
                    return json(['code' => 1, 'msg' => '请输入有效的邮箱地址']);
                }
            }

            // 检查是否已存在相同的链接
            $existingLink = Link::where('url', 'like', "%{$url}%")->first();
            if ($existingLink) {
                return json(['code' => 1, 'msg' => '该链接已存在或正在审核中']);
            }

            try {
                // 创建待审核的链接
                $link = new Link();
                $link->name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $link->url = $url;
                $link->icon = $icon;
                $link->description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
                $link->status = false; // 默认为未审核状态
                $link->sort_order = 999; // 默认排序
                $link->target = '_blank';
                $link->redirect_type = 'goto';
                $link->show_url = $show_url;
                $link->content = $full_description;
                $link->email = $email;
                $link->callback_url = $callback_url;

                // 构建内容信息 - 使用更结构化的格式
                $note = [
                    '## 申请信息',
                    '',
                    '**联系方式**: ' . htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'),
                    '',
                    '**邮箱**: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
                    '',
                    '**申请时间**: ' . date('Y-m-d H:i:s'),
                    '',
                    '**申请IP**: ' . $ipAddress,
                    '',
                    '### 附加选项',
                    '',
                    '- 支持风屿互联协议: ' . ($supports_wind_connect ? '是' : '否'),
                    '- 允许资源爬虫访问: ' . ($allows_crawling ? '是' : '否'),
                    '- 回调地址: ' . (!empty($callback_url) ? $callback_url : '未设置'),
                    '',
                    '### 审核记录',
                    '',
                    '> 待审核'
                ];

                $link->note = implode("\n", $note);
                $link->save();

                // 预留通知函数
                $this->notifyLinkRequest($link);

                return json(['code' => 0, 'msg' => '申请成功，等待管理员审核']);
            } catch (\Exception $e) {
                \support\Log::error('友链申请失败: ' . $e->getMessage());
                return json(['code' => 1, 'msg' => '系统错误，请稍后再试']);
            }
        }

        // 显示申请页面
        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';
        // 侧边栏（PJAX 与非 PJAX 均获取）
        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'link');
        return view($isPjax ? 'link/request.content' : 'link/request', [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 申请友链',
            'site_info_json_config' => $this->getSiteInfoConfig(),
            'csrf' => CSRFHelper::oneTimeToken($request, '_link_request_token'),
            'sidebar' => $sidebar
        ]);
    }

    /**
     * 获取站点信息配置（用于友链申请页面）
     *
     * @return string JSON格式的站点信息
     * @throws Throwable
     */
    private function getSiteInfoConfig(): string
    {
        $config = [
            'name' => blog_config('title', 'WindBlog', true),
            'url' => blog_config('site_url', '', true),
            'description' => blog_config('description', '', true),
            'icon' => blog_config('favicon', '', true),
            'protocol' => 'CAT3E',
            'version' => '1.0'
        ];

        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 通知新的友链申请（预留函数）
     *
     * @param Link $link 友链对象
     *
     * @return void
     */
    private function notifyLinkRequest(Link $link): void
    {
        // 预留函数，由用户自行实现通知逻辑
    }

    /**
     * 异步发送回调请求
     *
     * @param Link $link 友链对象
     *
     * @return void
     * @throws Throwable
     */
    private function sendCallbackAsync(Link $link): void
    {
        try {
            \support\Log::debug('Sending callback to MQ because: ' . $link->url . ' .Requesting: ' . $link->callback_url);

            // 准备回调数据
            $callbackData = [
                'link_id' => $link->id,
                'link_name' => $link->name,
                'link_url' => $link->url,
                'callback_url' => $link->callback_url,
                'access_time' => date('Y-m-d H:i:s'),
                'access_ip' => request()->getRealIp(),
                'user_agent' => request()->header('User-Agent', '')
            ];

            // 发布到 http_callback 队列
            $exchange   = (string)blog_config('rabbitmq_http_callback_exchange', 'http_callback_exchange', true);
            $routingKey = (string)blog_config('rabbitmq_http_callback_routing_key', 'http_callback', true);
            $channel = MQService::getChannel();
            $message = new AMQPMessage(json_encode($callbackData, JSON_UNESCAPED_UNICODE), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]);
            $channel->basic_publish($message, $exchange, $routingKey);

        } catch (\Exception $e) {
            \support\Log::error('Callback error: ' . $e->getMessage());
        }
    }

    /**
     * CAT3*: A站向B站发起友链申请（本地建立pending + 远端自动建waiting）
     * POST: peer_api, name, url, icon, description, email(optional)
     */
    public function connectApply(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return json(['code' => 1, 'msg' => '仅支持POST']);
        }

        $peerApi = $request->post('peer_api', '');
        $name = $request->post('name', '');
        $url = $request->post('url', '');
        $icon = $request->post('icon', '');
        $description = $request->post('description', '');
        $email = $request->post('email', '');

        if (!$peerApi || !$name || !$url) {
            return json(['code' => 1, 'msg' => '参数不完整']);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return json(['code' => 1, 'msg' => '无效URL']);
        }

        // 本地创建对方B站记录，状态为 pending（status=false）
        $existing = Link::where('url', $url)->first();
        if ($existing) {
            return json(['code' => 1, 'msg' => '该链接已存在或审核中']);
        }

        $link = new Link();
        $link->name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $link->url = $url;
        $link->icon = $icon;
        $link->description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $link->status = false;
        $link->sort_order = 999;
        $link->target = '_blank';
        $link->redirect_type = 'goto';
        $link->show_url = true;
        $link->email = $email;
        $link->setCustomFields([
            'peer_status' => 'pending',
            'peer_api' => $peerApi,
            'peer_protocol' => 'CAT3',
            'source' => 'connect_apply'
        ]);
        $link->save();

        // 组织发送给对方的数据（我们的站点信息）
        $payload = [
            'type' => 'wind_connect_apply',
            'site' => [
                'name' => blog_config('title', 'WindBlog', true),
                'url' => blog_config('site_url', '', true),
                'description' => blog_config('description', '', true),
                'icon' => blog_config('favicon', '', true),
                'protocol' => 'CAT3',
                'version' => '1.0'
            ],
            'link' => [
                'name' => blog_config('title', 'WindBlog', true),
                'url' => blog_config('site_url', '', true),
                'icon' => blog_config('favicon', '', true),
                'description' => blog_config('description', '', true),
                'email' => blog_config('admin_email', '', true)
            ],
            'timestamp' => time()
        ];

        $res = $this->httpPostJson($peerApi, $payload);
        if (!$res['success']) {
            return json(['code' => 0, 'msg' => '本地记录创建成功，但未能联系对方：' . $res['error']]);
        }

        return json(['code' => 0, 'msg' => '申请已发送，对方将自动创建等待记录']);
    }

    /**
     * CAT3*: B站接收来自A站的申请并自动创建 waiting 记录
     * 接收JSON：type=wind_connect_apply, site{}, link{}
     */
    public function connectReceive(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return json(['code' => 1, 'msg' => '仅支持POST']);
        }
        // 鉴权：要求 X-WIND-CONNECT-TOKEN 匹配配置
        $incoming = $request->header('X-WIND-CONNECT-TOKEN', '');
        $expected = blog_config('wind_connect_token', '', true);
        if (empty($expected) || $incoming !== $expected) {
            return json(['code' => 1, 'msg' => '鉴权失败']);
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data || ($data['type'] ?? '') !== 'wind_connect_apply') {
            return json(['code' => 1, 'msg' => '无效载荷']);
        }

        $fromSite = $data['site'] ?? [];
        $fromLink = $data['link'] ?? [];
        $peerUrl = $fromLink['url'] ?? '';
        if (!filter_var($peerUrl, FILTER_VALIDATE_URL)) {
            return json(['code' => 1, 'msg' => '无效对方站点URL']);
        }

        // 若已存在则跳过创建
        $exist = Link::where('url', $peerUrl)->first();
        if (!$exist) {
            $new = new Link();
            $new->name = htmlspecialchars($fromLink['name'] ?? ($fromSite['name'] ?? '友链'), ENT_QUOTES, 'UTF-8');
            $new->url = $peerUrl;
            $new->icon = $fromLink['icon'] ?? ($fromSite['icon'] ?? '');
            $new->description = htmlspecialchars($fromLink['description'] ?? '', ENT_QUOTES, 'UTF-8');
            $new->status = false; // waiting
            $new->sort_order = 999;
            $new->target = '_blank';
            $new->redirect_type = 'goto';
            $new->show_url = true;
            $new->email = $fromLink['email'] ?? '';
            $new->setCustomFields([
                'peer_status' => 'waiting',
                'peer_protocol' => $fromSite['protocol'] ?? 'CAT3',
                'source' => 'connect_receive'
            ]);
            $new->save();
        }

        return json(['code' => 0, 'msg' => '已接收，等待审核']);
    }

    /**
     * 简易JSON POST（禁用SSL验证）
     */
    private function httpPostJson(string $url, array $payload): array
    {
        try {
            $token = blog_config('wind_connect_token', '', true);
            $headers = "Content-Type: application/json

";
            if (!empty($token)) {
                $headers .= "X-WIND-CONNECT-TOKEN: {$token}

";
            }
            $opts = [
                'http' => [
                    'method' => 'POST',
                    'timeout' => 30,
                    'header' => $headers,
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ];
            $context = stream_context_create($opts);
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                return ['success' => false, 'error' => '请求失败'];
            }
            return ['success' => true, 'body' => (string)$result];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
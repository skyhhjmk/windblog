<?php

/*
 * 这里面有很多屎山
 *
 * custom_fields 字段内容
 * $custom_fields = [
 *     'link_position' => $link_position, 对方友链放置位置
 *     'page_link' => $page_link, 对方友链放置页链接地址
 *     'enable_monitor' => true, 是否启用监控
 *     'enable_auto_moderation' => true, 启用友链自动审核
 *     'enable_auto_report' => true, 启用友链下线自动告警双方
 * ];
 */

namespace app\controller;

use app\annotation\CSRFVerify;
use app\annotation\EnableInstantFirstPaint;
use app\helper\BreadcrumbHelper;
use app\model\Link;
use app\service\CSRFHelper;
use app\service\LinkConnectQueueService;
use app\service\LinkConnectService;
use app\service\MQService;
use app\service\PaginationService;
use app\service\PJAXHelper;
use app\service\SidebarService;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 链接广场控制器
 */
class LinkController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index', 'goto', 'info', 'request'];

    #[EnableInstantFirstPaint]
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

        $isPjax = PJAXHelper::isPJAX($request);
        // 侧边栏（PJAX 与非 PJAX 均获取）
        $sidebar = SidebarService::getSidebarContent($request, 'link');
        // 统一选择视图并生成响应（包含 X-PJAX 相关头）
        $viewName = PJAXHelper::getViewName('link/index', $isPjax);

        // 生成面包屑导航
        $breadcrumbs = BreadcrumbHelper::forLinks();

        return PJAXHelper::createResponse($request, $viewName, [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 链接广场',
            'links' => $links,
            'pagination' => $pagination_html,
            'sidebar' => $sidebar,
            'breadcrumbs' => $breadcrumbs,
        ], null, 120, 'page');
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
                'message' => '链接不存在',
            ]);
        }

        // 检查链接是否启用
        if (!$link->status) {
            return view('error/404', [
                'message' => '链接已被禁用',
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
                'page_title' => blog_config('title', 'WindBlog', true) . ' - 外链跳转确认',
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
                'message' => '链接不存在',
            ]);
        }

        // 检查链接是否启用
        if (!$link->status) {
            return view('error/404', [
                'message' => '链接已被禁用',
            ]);
        }

        $isPjax = PJAXHelper::isPJAX($request);
        // 侧边栏（PJAX 与非 PJAX 均获取）
        $sidebar = SidebarService::getSidebarContent($request, 'link');
        // 统一选择视图并生成响应
        $viewName = PJAXHelper::getViewName('link/info', $isPjax);

        // 生成面包屑导航
        $breadcrumbs = BreadcrumbHelper::forLinks();

        return PJAXHelper::createResponse($request, $viewName, [
            'link' => $link,
            'page_title' => htmlspecialchars($link->name, ENT_QUOTES, 'UTF-8') . ' - 链接详情',
            'sidebar' => $sidebar,
            'breadcrumbs' => $breadcrumbs,
        ], null, 120, 'page');
    }

    /**
     * 申请友链页面
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    #[CSRFVerify(
        tokenName: '_link_request_token',
        if_failed_config: [
            'response_type' => 'json',
            'response_code' => 403,
            'response_body' => [
                'code' => 1,
                'msg' => '请求验证失败，请刷新页面重试',
            ],
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
                Log::warning('友链申请蜜罐验证失败，可能为机器人提交', [
                    'ip' => $request->getRealIp(),
                    'user_agent' => $request->header('User-Agent'),
                ]);

                return json(['code' => 1, 'msg' => '人机验证不通过']);
            }

            // 获取表单数据
            $name = trim($request->post('name', ''));
            $url = trim($request->post('url', ''));
            $icon = trim($request->post('icon', ''));
            $description = trim($request->post('description', ''));
            $contact = trim($request->post('contact', ''));
            $supports_wind_connect = $request->post('supports_wind_connect') === 'on';
            $allows_crawling = $request->post('allows_crawling') === 'on';
            $hide_url = $request->post('hide_url') === 'on';
            $callback_url = trim($request->post('callback_url', ''));
            $email = trim($request->post('email', ''));
            $full_description = trim($request->post('full_description', ''));
            $link_position = trim($request->post('link_position', ''));
            $page_link = trim($request->post('page_link', ''));
            $redirect_type = trim($request->post('redirect_type', ''));

            $ipAddress = $request->getRealIp();
            $show_url = !(bool) $hide_url; // 取反值，即将是否隐藏 url 转为是否显示 url

            // 增强验证
            if (empty($name) || empty($url) || empty($description)) {
                return json(['code' => 1, 'msg' => '请填写必填字段']);
            }

            // 验证友链放置位置
            if (empty($link_position)) {
                return json(['code' => 1, 'msg' => '请选择友链放置位置']);
            }

            // 验证友链放置位置的值是否合法
            if (!in_array($link_position, ['homepage', 'link_page', 'other_page'], true)) {
                return json(['code' => 1, 'msg' => '无效的友链放置位置']);
            }

            // 如果选择了友链页或其他页面，则页面链接为必填
            if (($link_position === 'link_page' || $link_position === 'other_page') && empty($page_link)) {
                return json(['code' => 1, 'msg' => '请填写页面链接']);
            }

            // 验证跳转方式
            if (empty($redirect_type)) {
                return json(['code' => 1, 'msg' => '请选择跳转方式']);
            }

            // 验证跳转方式的值是否合法
            if (!in_array($redirect_type, ['direct', 'goto', 'info'], true)) {
                return json(['code' => 1, 'msg' => '无效的跳转方式']);
            }

            // 限制字段长度，防止超长输入
            if (strlen($name) > 100) {
                return json(['code' => 1, 'msg' => '网站名称过长']);
            }

            if (strlen($url) > 500) {
                return json(['code' => 1, 'msg' => '网站链接过长']);
            }

            if (strlen($description) > 500) {
                return json(['code' => 1, 'msg' => '网站描述过长']);
            }

            if (strlen($contact) > 200) {
                return json(['code' => 1, 'msg' => '联系方式过长']);
            }

            if (strlen($email) > 100) {
                return json(['code' => 1, 'msg' => '邮箱地址过长']);
            }

            if (strlen($full_description) > 2000) {
                return json(['code' => 1, 'msg' => '详细描述过长']);
            }

            // 更严格的URL验证
            if (!filter_var($url, FILTER_VALIDATE_URL) ||
                !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_+.~#?&\/=]*)?$/', $url)) {
                return json(['code' => 1, 'msg' => '请输入有效的网址']);
            }

            // 验证图标URL（如果提供了）
            if (!empty($icon)) {
                if (strlen($icon) > 500) {
                    return json(['code' => 1, 'msg' => '网站图标地址过长']);
                }

                if (!filter_var($icon, FILTER_VALIDATE_URL) ||
                    !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_+.~#?&\/=]*)?$/', $icon)) {
                    return json(['code' => 1, 'msg' => '请输入有效的网站图标地址']);
                }
            }

            // 验证回调URL（如果提供了）
            if (!empty($callback_url)) {
                if (strlen($callback_url) > 500) {
                    return json(['code' => 1, 'msg' => '回调地址过长']);
                }

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

            // 验证页面链接（如果提供了）
            if (!empty($page_link)) {
                if (strlen($page_link) > 500) {
                    return json(['code' => 1, 'msg' => '页面链接地址过长']);
                }

                if (!filter_var($page_link, FILTER_VALIDATE_URL) ||
                    !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_+.~#?&\/=]*)?$/', $page_link)) {
                    return json(['code' => 1, 'msg' => '请输入有效的页面链接地址']);
                }
            }

            // 检查是否已存在相同的链接
            $existingLink = Link::where('url', 'like', "%{$url}%")->first();
            if ($existingLink) {
                Log::info('友链申请重复提交', [
                    'url' => $url,
                    'ip' => $ipAddress,
                ]);

                return json(['code' => 1, 'msg' => '该链接已存在或正在审核中']);
            }
            $custom_fields = [
                'link_position' => $link_position,
                'page_link' => $page_link,
                'enable_monitor' => true,
                'enable_auto_moderation' => true,
                'enable_auto_report' => true,
            ];

            try {
                // 创建待审核的链接 - 存储原始数据，在显示时转义
                $link = new Link();
                $link->name = $name;
                $link->url = $url;
                $link->icon = $icon;
                $link->description = $description;
                $link->status = false; // 默认为未审核状态
                $link->sort_order = 999; // 默认排序
                $link->target = '_blank';
                $link->redirect_type = $redirect_type;
                $link->show_url = $show_url;
                $link->content = $full_description;
                $link->email = $email;
                $link->callback_url = $callback_url;
                $link->custom_fields = json_encode($custom_fields);

                // 构建内容信息 - 使用更结构化的格式
                $linkPositionText = match ($link_position) {
                    'homepage' => '首页',
                    'link_page' => '友链页',
                    'other_page' => '其他页面',
                    default => '未知',
                };

                $redirectTypeText = match ($redirect_type) {
                    'direct' => '直接跳转',
                    'goto' => 'goto页面',
                    'info' => 'info页面',
                    default => '未知',
                };

                $note = [
                    '## 申请信息',
                    '',
                    '**联系方式**: ' . $contact,
                    '',
                    '**邮箱**: ' . $email,
                    '',
                    '**申请时间**: ' . utc_now_string('Y-m-d H:i:s'),
                    '',
                    '**申请IP**: ' . $ipAddress,
                    '',
                    '**友链放置位置**: ' . $linkPositionText,
                    '',
                ];

                // 如果有页面链接，添加到备注中
                if (!empty($page_link)) {
                    $note[] = '**页面链接**: ' . $page_link;
                    $note[] = '';
                }

                $note[] = '**跳转方式**: ' . $redirectTypeText;
                $note[] = '';

                $note = array_merge($note, [
                    '### 附加选项',
                    '',
                    '- 支持风屿互联协议: ' . ($supports_wind_connect ? '是' : '否'),
                    '- 允许资源爬虫访问: ' . ($allows_crawling ? '是' : '否'),
                    '- 回调地址: ' . (!empty($callback_url) ? $callback_url : '未设置'),
                    '',
                    '### 审核记录',
                    '',
                    '> 待审核',
                ]);

                $link->note = implode("\n", $note);
                $link->save();

                // 预留通知函数
                $this->notifyLinkRequest($link);

                Log::info('友链申请提交成功', [
                    'link_id' => $link->id,
                    'ip' => $ipAddress,
                ]);

                return json(['code' => 0, 'msg' => '申请成功，等待管理员审核']);
            } catch (Exception $e) {
                Log::error('友链申请失败: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);

                return json(['code' => 1, 'msg' => '系统错误，请稍后再试']);
            }
        }

        // 显示申请页面
        $isPjax = PJAXHelper::isPJAX($request);
        // 侧边栏（PJAX 与非 PJAX 均获取）
        $sidebar = SidebarService::getSidebarContent($request, 'link');
        // 统一选择视图并生成响应
        $viewName = PJAXHelper::getViewName('link/request', $isPjax);

        // 生成面包屑导航
        $breadcrumbs = BreadcrumbHelper::forLinks();

        return PJAXHelper::createResponse($request, $viewName, [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 申请友链',
            'site_info_json_config' => $this->getSiteInfoConfig(),
            'csrf' => CSRFHelper::oneTimeToken($request, '_link_request_token'),
            'sidebar' => $sidebar,
            'breadcrumbs' => $breadcrumbs,
        ], null, 120, 'page');
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
            'version' => '1.0',
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
            Log::debug('Sending callback to MQ because: ' . $link->url . ' .Requesting: ' . $link->callback_url);

            // 准备回调数据
            $callbackData = [
                'link_id' => $link->id,
                'link_name' => htmlspecialchars($link->name, ENT_QUOTES, 'UTF-8'),
                'link_url' => $link->url,
                'callback_url' => $link->callback_url,
                'access_time' => utc_now_string('Y-m-d H:i:s'),
                'access_ip' => request()->getRealIp(),
                'user_agent' => htmlspecialchars(request()->header('User-Agent', ''), ENT_QUOTES, 'UTF-8'),
            ];

            // 发布到 http_callback 队列
            $exchange = (string) blog_config('rabbitmq_http_callback_exchange', 'http_callback_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_http_callback_routing_key', 'http_callback', true);
            $channel = MQService::getChannel();
            $message = new AMQPMessage(json_encode($callbackData, JSON_UNESCAPED_UNICODE), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]);
            $channel->basic_publish($message, $exchange, $routingKey);

        } catch (Exception $e) {
            Log::error('Callback error: ' . $e->getMessage());
        }
    }

    /**
     * CAT3*: 发起友链申请（异步处理+轮询机制）
     *
     * 流程：
     * 1. 优先验证token（如果是快速互联URL）
     * 2. 入队异步处理
     * 3. 返回task_id供前端轮询
     */
    public function connectApply(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return json(['code' => 1, 'msg' => '仅支持POST']);
        }

        // 解析请求数据
        $jsonBody = [];
        if (stripos((string) $request->header('Content-Type', ''), 'application/json') !== false) {
            $jsonBody = json_decode((string) $request->rawBody(), true) ?: [];
        }

        $peerApi = trim((string) ($jsonBody['peer_api'] ?? $request->post('peer_api', '')));

        // 提取基本字段
        $name = trim((string) ($jsonBody['name'] ?? $request->post('name', '')));
        $url = trim((string) ($jsonBody['url'] ?? $request->post('url', '')));
        $icon = trim((string) ($jsonBody['icon'] ?? $request->post('icon', '')));
        $description = trim((string) ($jsonBody['description'] ?? $request->post('description', '')));
        $email = trim((string) ($jsonBody['email'] ?? $request->post('email', '')));

        // 兼容site结构
        $site = is_array($jsonBody['site'] ?? null) ? $jsonBody['site'] : [];
        $name = $name ?: trim((string) ($site['name'] ?? ''));
        $url = $url ?: trim((string) ($site['url'] ?? ''));
        $icon = $icon ?: trim((string) ($site['icon'] ?? ''));
        $description = $description ?: trim((string) ($site['description'] ?? ''));
        $email = $email ?: trim((string) ($site['email'] ?? ''));

        // 参数长度验证
        if (strlen($name) > 100) {
            return json(['code' => 1, 'msg' => '站点名称过长']);
        }
        if (strlen($url) > 500) {
            return json(['code' => 1, 'msg' => '站点链接过长']);
        }
        if (strlen($icon) > 500) {
            return json(['code' => 1, 'msg' => '图标地址过长']);
        }
        if (strlen($description) > 500) {
            return json(['code' => 1, 'msg' => '站点描述过长']);
        }
        if (strlen($email) > 100) {
            return json(['code' => 1, 'msg' => '邮箱地址过长']);
        }

        $extractedToken = null;

        // === 步骤1: 提取并验证token（仅查状态，不做远程调用） ===
        if ($peerApi) {
            try {
                $parsedUrl = parse_url($peerApi);
                $queryParams = [];
                if (isset($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $queryParams);
                }

                // 如果是快速互联URL（带token）
                if (!empty($queryParams['token'])) {
                    $extractedToken = $queryParams['token'];

                    Log::info('检测到快速互联URL，token: ' . substr($extractedToken, 0, 8) . '...');

                    // 【关键】仅验证token状态（不做远程调用）
                    $tokens = LinkConnectService::listTokens();
                    $tokenValid = false;
                    $tokenError = null;

                    foreach ($tokens as $t) {
                        if ($t['token'] === $extractedToken) {
                            if ($t['status'] === 'revoked') {
                                $tokenError = 'token已被作废';
                                break;
                            } elseif ($t['status'] === 'used') {
                                $tokenError = 'token已被使用';
                                break;
                            } elseif ($t['status'] === 'unused') {
                                $tokenValid = true;
                                break;
                            }
                        }
                    }

                    // 如果token无效，立即返回错误
                    if ($tokenError) {
                        Log::warning("Token验证失败: {$tokenError}");

                        return json(['code' => 1, 'msg' => $tokenError]);
                    }

                    if (!$tokenValid) {
                        Log::warning('无效的token');

                        return json(['code' => 1, 'msg' => '无效的token']);
                    }

                    Log::info('Token状态验证通过，将交给Worker处理远程调用');
                }
            } catch (Throwable $e) {
                Log::warning('Token验证失败: ' . $e->getMessage());

                return json(['code' => 1, 'msg' => 'Token验证失败']);
            }
        }

        // 回填默认值
        if (empty($name)) {
            $name = (string) blog_config('title', 'WindBlog', true);
        }
        if (empty($url)) {
            $url = (string) blog_config('site_url', '', true);
        }
        if (empty($icon)) {
            $icon = (string) blog_config('favicon', '', true);
        }
        if (empty($description)) {
            $description = (string) blog_config('description', '', true);
        }
        if (empty($email)) {
            $email = (string) blog_config('admin_email', '', true);
        }

        // 参数完整性验证
        if (empty($peerApi)) {
            return json(['code' => 1, 'msg' => '请填写对方API地址']);
        }
        if (empty($name)) {
            return json(['code' => 1, 'msg' => '请填写站点名称']);
        }
        if (empty($url)) {
            return json(['code' => 1, 'msg' => '请填写站点URL']);
        }

        // URL格式验证
        if (!filter_var($peerApi, FILTER_VALIDATE_URL)) {
            return json(['code' => 1, 'msg' => '对方API地址格式不正确']);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return json(['code' => 1, 'msg' => '站点URL格式不正确']);
        }

        // === 步骤2: 入队异步处理（包含token信息） ===
        // 注意：不在这里检查URL是否已存在，交给Worker在异步处理时检查
        $queueResult = LinkConnectQueueService::enqueue([
            'peer_api' => $peerApi,
            'name' => $name,
            'url' => $url,
            'icon' => $icon,
            'description' => $description,
            'email' => $email,
            'token' => $extractedToken, // 传递token给Worker处理
        ]);

        if ($queueResult['code'] !== 0) {
            Log::error('入队失败: ' . $queueResult['msg']);

            return json(['code' => 1, 'msg' => '提交任务失败: ' . $queueResult['msg']]);
        }

        // === 步骤3: 立即返回task_id供前端轮询 ===
        return json([
            'code' => 0,
            'msg' => '任务已提交，正在异步处理',
            'task_id' => $queueResult['task_id'],
        ]);
    }

    /**
     * 检查任务状态（供前端轮询使用）
     */
    public function checkTaskStatus(Request $request): Response
    {
        $taskId = trim((string) $request->get('task_id', ''));

        if (empty($taskId)) {
            return json(['code' => 1, 'msg' => 'task_id不能为空']);
        }

        $status = LinkConnectQueueService::getTaskStatus($taskId);

        if ($status === null) {
            return json(['code' => 1, 'msg' => '任务不存在或已过期']);
        }

        return json([
            'code' => 0,
            'status' => $status['status'] ?? 'unknown',
            'message' => $status['message'] ?? '',
            'data' => $status['data'] ?? [],
        ]);
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
        $raw = $request->rawBody();
        $data = json_decode($raw, true) ?: [];
        $result = LinkConnectService::receiveFromPeer(
            ['X-WIND-CONNECT-TOKEN' => $request->header('X-WIND-CONNECT-TOKEN', '')],
            $data
        );

        return json($result);
    }

    /**
     * 快速互联API接口
     * 用于处理其他站点通过token快速连接并获取本站信息
     *
     * @param Request $request
     *
     * @return Response
     */
    public function quickConnect(Request $request): Response
    {
        try {
            // 获取token参数
            $token = trim((string) $request->get('token', ''));

            // 验证token是否有效
            if (empty($token)) {
                return json(['code' => 1, 'msg' => 'token不能为空']);
            }

            // 获取互联协议配置
            $config = LinkConnectService::getConfig();

            // 检查互联协议是否启用
            if (!$config['enabled']) {
                return json(['code' => 1, 'msg' => '互联协议未启用']);
            }

            // 验证token是否存在且未被使用（优先验证，防止作废token触发后续逻辑）
            $tokens = LinkConnectService::listTokens();
            $validToken = false;
            foreach ($tokens as $t) {
                if ($t['token'] === $token) {
                    // 检查token状态
                    if ($t['status'] === 'unused') {
                        $validToken = true;
                    } elseif ($t['status'] === 'revoked') {
                        return json(['code' => 1, 'msg' => 'token已被作废']);
                    } elseif ($t['status'] === 'used') {
                        return json(['code' => 1, 'msg' => 'token已被使用']);
                    }
                    break;
                }
            }

            if (!$validToken) {
                return json(['code' => 1, 'msg' => '无效的token']);
            }

            // 构建并返回本站信息
            $siteInfo = [
                'name' => blog_config('title', 'WindBlog', true),
                'url' => blog_config('site_url', '', true),
                'description' => blog_config('description', '', true),
                'icon' => blog_config('favicon', '', true),
                'protocol' => 'CAT3E',
                'version' => '1.0',
            ];

            // 构建友链信息
            $linkInfo = [
                'name' => $siteInfo['name'],
                'url' => $siteInfo['url'],
                'icon' => $siteInfo['icon'],
                'description' => $siteInfo['description'],
                'email' => blog_config('admin_email', '', true),
            ];

            // 成功返回后，token由connectApply调用方标记为已使用
            // 注意：此处不标记，由connectApply在成功获取信息后标记
            // 这样可以防止仅查询信息就消耗token的情况

            return json([
                'code' => 0,
                'msg' => 'success',
                'site' => $siteInfo,
                'link' => $linkInfo,
            ]);
        } catch (Throwable $e) {
            Log::error('快速互联API错误: ' . $e->getMessage());

            return json(['code' => 1, 'msg' => '系统错误']);
        }
    }

    /**
     * 友链互联API接口
     * 处理来自其他站点的友链互联请求
     *
     * @param Request $request HTTP请求对象
     *
     * @return Response JSON响应
     */
    public function windConnect(Request $request): Response
    {
        try {
            // 获取请求头
            $headers = $request->header();

            // 获取请求体（JSON格式）
            $body = $request->rawBody();
            $payload = json_decode($body, true);

            // 检查请求体是否为有效JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                return json(['code' => 1, 'msg' => '无效的JSON格式']);
            }

            // 调用LinkConnectService处理友链互联请求
            $result = LinkConnectService::receiveFromPeer($headers, $payload);

            // 返回处理结果
            return json($result);
        } catch (Throwable $e) {
            // 记录异常并返回错误信息
            Log::error('友链互联处理异常: ' . $e->getMessage());

            return json(['code' => 1, 'msg' => '处理请求时发生错误']);
        }
    }
}

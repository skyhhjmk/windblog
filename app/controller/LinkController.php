<?php

/*
 * 这里面有很多屎山
 */

namespace app\controller;

use app\annotation\CSRFVerify;
use app\annotation\EnableInstantFirstPaint;
use app\helper\BreadcrumbHelper;
use app\model\Link;
use app\service\CSRFHelper;
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

            $ipAddress = $request->getRealIp();
            $show_url = !(bool) $hide_url; // 取反值，即将是否隐藏 url 转为是否显示 url

            // 增强验证
            if (empty($name) || empty($url) || empty($description)) {
                return json(['code' => 1, 'msg' => '请填写必填字段']);
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

            // 检查是否已存在相同的链接
            $existingLink = Link::where('url', 'like', "%{$url}%")->first();
            if ($existingLink) {
                Log::info('友链申请重复提交', [
                    'url' => $url,
                    'ip' => $ipAddress,
                ]);

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
                $link->content = htmlspecialchars($full_description, ENT_QUOTES, 'UTF-8');
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
                    '**申请时间**: ' . utc_now_string('Y-m-d H:i:s'),
                    '',
                    '**申请IP**: ' . $ipAddress,
                    '',
                    '### 附加选项',
                    '',
                    '- 支持风屿互联协议: ' . ($supports_wind_connect ? '是' : '否'),
                    '- 允许资源爬虫访问: ' . ($allows_crawling ? '是' : '否'),
                    '- 回调地址: ' . (!empty($callback_url) ? htmlspecialchars($callback_url, ENT_QUOTES, 'UTF-8') : '未设置'),
                    '',
                    '### 审核记录',
                    '',
                    '> 待审核',
                ];

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
     * CAT3*: A站向B站发起友链申请（本地建立pending + 远端自动建waiting）
     * POST: peer_api, name, url, icon, description, email(optional)
     */
    public function connectApply(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return json(['code' => 1, 'msg' => '仅支持POST']);
        }

        // 兼容 application/json 与 x-www-form-urlencoded
        $jsonBody = [];
        if (stripos((string) $request->header('Content-Type', ''), 'application/json') !== false) {
            $jsonBody = json_decode((string) $request->rawBody(), true) ?: [];
        }

        $peerApi = trim((string) ($jsonBody['peer_api'] ?? $request->post('peer_api', '')));

        // 扁平字段
        $name = trim((string) ($jsonBody['name'] ?? $request->post('name', '')));
        $url = trim((string) ($jsonBody['url'] ?? $request->post('url', '')));
        $icon = trim((string) ($jsonBody['icon'] ?? $request->post('icon', '')));
        $description = trim((string) ($jsonBody['description'] ?? $request->post('description', '')));
        $email = trim((string) ($jsonBody['email'] ?? $request->post('email', '')));

        // 兼容前端结构：site{ name,url,icon,description,email }
        $site = is_array($jsonBody['site'] ?? null) ? $jsonBody['site'] : [];
        $name = $name ?: trim((string) ($site['name'] ?? ''));
        $url = $url ?: trim((string) ($site['url'] ?? ''));
        $icon = $icon ?: trim((string) ($site['icon'] ?? ''));
        $description = $description ?: trim((string) ($site['description'] ?? ''));
        $email = $email ?: trim((string) ($site['email'] ?? ''));

        // 限制字段长度
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

        // 快速互联URL处理：如果peer_api是一个带有token的URL，尝试自动获取对方站点信息
        if ($peerApi) {
            Log::info('尝试处理互联URL: ' . $peerApi);
            try {
                // 检查是否为快速互联URL（带有token参数）
                $parsedUrl = parse_url($peerApi);
                $queryParams = [];
                if (isset($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $queryParams);
                }
                Log::info('解析到的参数: ' . json_encode($queryParams));

                // 如果是快速互联URL，尝试调用quickConnect接口获取对方信息
                if (!empty($queryParams['token'])) {
                    Log::info('检测到快速互联URL，token: ' . substr($queryParams['token'], 0, 8) . '...');

                    // 构建请求URL - 确保是调用quickConnect接口
                    $scheme = $parsedUrl['scheme'] ?? 'http';
                    $host = $parsedUrl['host'] ?? '';
                    $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
                    $path = $parsedUrl['path'] ?? '';

                    // 确保调用的是quickConnect接口
                    $quickConnectUrl = $scheme . '://' . $host . $port . '/link/quick-connect?token=' . urlencode($queryParams['token']);
                    Log::info('构建的quickConnectURL: ' . $quickConnectUrl);

                    $headersArr = ['Accept: application/json'];
                    $localToken = (string) blog_config('wind_connect_token', '', true);
                    if (!empty($localToken)) {
                        $headers = implode("\r\n", $headersArr) . "\r\nX-WIND-CONNECT-TOKEN: " . $localToken . "\r\n";
                    } else {
                        // 确保headers变量始终有定义
                        $headers = implode("\r\n", $headersArr) . "\r\n";
                    }
                    $opts = [
                        'http' => [
                            'method' => 'GET',
                            'timeout' => 10,
                            'header' => $headers,
                        ],
                        'ssl' => [
                            'verify_peer' => true,
                            'verify_peer_name' => true,
                        ],
                    ];
                    $ctx = stream_context_create($opts);

                    Log::info('发送请求到quickConnect接口');
                    $resp = @file_get_contents($quickConnectUrl, false, $ctx);

                    if ($resp === false) {
                        Log::warning('quickConnect请求失败: ' . ((error_get_last() ?? [])['message'] ?? '未知错误'));
                    } else {
                        Log::info('quickConnect请求成功，响应长度: ' . strlen($resp));

                        $data = json_decode($resp, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            Log::warning('JSON解析失败: ' . json_last_error_msg());
                        } else {
                            Log::info('quickConnect响应状态: ' . ($data['code'] ?? '未知'));

                            if (is_array($data) && $data['code'] === 0) {
                                // 从快速互联响应中提取信息
                                $remoteSite = $data['site'] ?? [];
                                $remoteLink = $data['link'] ?? [];

                                Log::info('从快速互联响应提取信息: ' . json_encode(['site' => array_keys($remoteSite), 'link' => array_keys($remoteLink)]));

                                $name = $name ?: (string) ($remoteLink['name'] ?? ($remoteSite['name'] ?? $name));
                                $url = $url ?: (string) ($remoteLink['url'] ?? ($remoteSite['url'] ?? $url));
                                $icon = $icon ?: (string) ($remoteLink['icon'] ?? ($remoteSite['icon'] ?? $icon));
                                $description = $description ?: (string) ($remoteLink['description'] ?? ($remoteSite['description'] ?? $description));
                                $email = $email ?: (string) ($remoteLink['email'] ?? $email);

                                Log::info('提取后的数据: ' . json_encode(['name' => $name, 'url' => $url]));

                                // 标记token为已使用
                                LinkConnectService::markTokenUsed($queryParams['token'], $url);

                                // 自动调整peer_api为接收接口
                                $peerApi = rtrim($url, '/') . '/link/connect/receive';
                                Log::info('调整后的peer_api: ' . $peerApi);
                            } else {
                                Log::warning('quickConnect响应不符合预期: ' . json_encode($data));
                            }
                        }
                    }
                } // 如果快速互联失败或不是快速互联URL，尝试使用原有的自动补全逻辑
                elseif (empty($name) || empty($url)) {
                    Log::info('尝试使用原有自动补全逻辑');
                    $headersArr = ['Accept: application/json'];
                    $localToken = (string) blog_config('wind_connect_token', '', true);
                    $headers = implode("\r\n", $headersArr) . "\r\n";
                    if (!empty($localToken)) {
                        $headers .= 'X-WIND-CONNECT-TOKEN: ' . $localToken . "\r\n";
                    }
                    $opts = [
                        'http' => [
                            'method' => 'GET',
                            'timeout' => 10,
                            'header' => $headers,
                        ],
                        'ssl' => [
                            'verify_peer' => true,
                            'verify_peer_name' => true,
                        ],
                    ];
                    $ctx = stream_context_create($opts);
                    $resp = @file_get_contents($peerApi, false, $ctx);
                    if ($resp !== false) {
                        $data = json_decode($resp, true);
                        if (is_array($data)) {
                            $remoteLink = $data['link'] ?? [];
                            $remoteSite = $data['site'] ?? ($data['site_info'] ?? []);
                            $name = $name ?: (string) ($remoteLink['name'] ?? ($remoteSite['name'] ?? $name));
                            $url = $url ?: (string) ($remoteLink['url'] ?? ($remoteSite['url'] ?? $url));
                            $icon = $icon ?: (string) ($remoteLink['icon'] ?? ($remoteSite['logo'] ?? $icon));
                            $description = $description ?: (string) ($remoteLink['description'] ?? ($remoteSite['description'] ?? $description));
                            $email = $email ?: (string) ($remoteLink['email'] ?? $email);
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning('connectApply auto-complete failed: ' . $e->getMessage());
            }
        }

        Log::info('快速互联处理后的数据: ' . json_encode(['peerApi' => $peerApi, 'name' => $name, 'url' => $url]));

        // 本地站点默认值回填（避免"参数不完整"）
        if (empty($name)) {
            $name = (string) blog_config('title', 'WindBlog', true);
        }
        if (empty($url)) {
            $url = (string) blog_config('site_url', '', true);
            // 如果配置中没有站点URL，尝试使用默认URL
            if (empty($url)) {
                $url = 'https://example.com';
            }
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

        // 增加参数完整性验证，提供更友好的错误提示
        if (empty($peerApi)) {
            return json(['code' => 1, 'msg' => '请填写对方API地址']);
        }
        if (empty($name)) {
            return json(['code' => 1, 'msg' => '请填写站点名称']);
        }
        if (empty($url)) {
            return json(['code' => 1, 'msg' => '请填写站点URL']);
        }

        // 验证URL格式
        if (!filter_var($peerApi, FILTER_VALIDATE_URL)) {
            return json(['code' => 1, 'msg' => '对方API地址格式不正确']);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return json(['code' => 1, 'msg' => '站点URL格式不正确']);
        }

        $result = LinkConnectService::applyToPeer([
            'peer_api' => $peerApi,
            'name' => $name,
            'url' => $url,
            'icon' => $icon,
            'description' => $description,
            'email' => $email,
        ]);

        return json($result);
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

            // 验证token是否存在且未被使用
            $tokens = LinkConnectService::listTokens();
            $validToken = false;
            foreach ($tokens as $t) {
                if ($t['token'] === $token && $t['status'] === 'unused') {
                    $validToken = true;
                    break;
                }
            }

            if (!$validToken) {
                return json(['code' => 1, 'msg' => '无效或已使用的token']);
            }

            // 构建并返回本站信息
            $siteInfo = [
                'name' => htmlspecialchars(blog_config('title', 'WindBlog', true), ENT_QUOTES, 'UTF-8'),
                'url' => htmlspecialchars(blog_config('site_url', '', true), ENT_QUOTES, 'UTF-8'),
                'description' => htmlspecialchars(blog_config('description', '', true), ENT_QUOTES, 'UTF-8'),
                'icon' => htmlspecialchars(blog_config('favicon', '', true), ENT_QUOTES, 'UTF-8'),
                'protocol' => 'CAT3E',
                'version' => '1.0',
            ];

            // 构建友链信息
            $linkInfo = [
                'name' => $siteInfo['name'],
                'url' => $siteInfo['url'],
                'icon' => $siteInfo['icon'],
                'description' => $siteInfo['description'],
                'email' => htmlspecialchars(blog_config('admin_email', '', true), ENT_QUOTES, 'UTF-8'),
            ];

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

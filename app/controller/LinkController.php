<?php
/*
 * 这里面有很多屎山
 */

namespace app\controller;

use app\annotation\CSRFVerify;
use app\service\CSRFHelper;
use app\service\MQService;
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

        return view('link/index', [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 链接广场',
            'links' => $links,
            'pagination' => $pagination_html,
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

        return view('link/info', [
            'link' => $link,
            'page_title' => $link->name . ' - 链接详情'
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
        return view('link/request', [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 申请友链',
            'site_info_json_config' => $this->getSiteInfoConfig(),
            'csrf' => CSRFHelper::oneTimeToken($request, '_link_request_token')
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

            // 使用MQ服务发送到http_callback队列
            MQService::sendToHttpCallback($callbackData);

        } catch (\Exception $e) {
            \support\Log::error('Callback error: ' . $e->getMessage());
        }
    }
}
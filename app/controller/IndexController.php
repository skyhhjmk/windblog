<?php

namespace app\controller;

use League\CommonMark\Exception\CommonMarkException;
use support\Request;
use app\service\BlogService;
use support\Response;
use Throwable;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * 博客首页控制器
 */
class IndexController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index'];

    /**
     * 允许的过滤字段
     */
    protected array $allowedFilters = [
        'category',
        'tag',
        'author',
        'search'
    ];

    /**
     * 博客首页
     *
     * @param Request $request 请求对象
     * @param int     $page    页码
     *
     * @return Response
     * @throws CommonMarkException
     * @throws Throwable
     */
    #[RateLimiter(limit: 3, ttl: 3)]
    public function index(Request $request, int $page = 1): Response
    {
        if ($request->get('test')){
            sleep(20);
        }
        // 构建筛选条件，并进行输入过滤
        $filters = $this->filterInput($request->get() ?: []);

        // 调用博客服务获取文章数据
        $result = BlogService::getBlogPosts($page, $filters);

        // 获取博客标题
        $blog_title = BlogService::getBlogTitle();

        // PJAX 优化：检测是否为 PJAX 请求（兼容 header/_pjax 参数/XHR）
        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';

        // 获取侧边栏内容（仅非 PJAX 时获取）
        $sidebar = $isPjax ? null : \app\service\SidebarService::getSidebarContent($request, 'home');

        // 动态选择模板（统一返回完整页面，PJAX 前端抽取片段）
        $viewName = 'index/index';

        return view($viewName, [
            'page_title' => $blog_title,
            'posts' => $result['posts'],
            'pagination' => $result['pagination'],
            'sidebar' => $sidebar
        ]);
    }

    /**
     * 输入过滤函数
     * 确保只接受允许的参数并进行适当的清洗
     *
     * @param array $input 原始输入数据
     *
     * @return array 过滤后的安全数据
     */
    protected function filterInput(array $input): array
    {
        $filtered = [];

        foreach ($input as $key => $value) {
            // 只处理允许的过滤字段
            if (in_array($key, $this->allowedFilters) && !empty($value)) {
                // 进行基本的安全清洗
                $filtered[$key] = $this->sanitizeValue($value, $key);
            }
        }

        return $filtered;
    }

    /**
     * 对输入值进行清洗
     *
     * @param mixed  $value 输入值
     * @param string $key   字段名
     *
     * @return mixed 清洗后的值
     */
    protected function sanitizeValue(mixed $value, string $key): mixed
    {
        if (is_string($value)) {
            // 移除潜在的危险标签
            $value = strip_tags($value);
            // 移除多余的空格
            $value = trim($value);
            // 对特殊字符进行转义
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        } elseif (is_array($value)) {
            // 递归清洗数组值
            foreach ($value as $k => $v) {
                $value[$k] = $this->sanitizeValue($v, $k);
            }
        }

        return $value;
    }

    /**
     * 调试用获取自己的全部session内容
     *
     * @param Request $request 请求对象
     *
     * @return Response
     */
    public function getSession(Request $request): Response
    {
        // 这样就可以！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！
        try {
            // 检查session对象是否存在
            $session = $request->session();
            if (!$session) {
                return response('Session object not available', 500);
            }

            // 获取session ID
            $sessionId = $request->sessionId();

            // 获取所有session数据
            $all = $session->all();

            // 组织返回信息
            $result = [
                'session_id' => $sessionId,
                'session_data' => $all,
                'session_class' => get_class($session)
            ];

            return response(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200)
                ->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return response('Error: ' . $e->getMessage(), 500);
        }

        // 但是这样就不行！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！
        /* 这tm可是官方示例代码的：【获取全部session】
        $session = $request->session();
        $all = $session->all(); // 但是这里为什么获取的是null？？？？？？？？？？
        */
    }
}
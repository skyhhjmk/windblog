<?php

namespace app\controller;

use app\annotation\EnableInstantFirstPaint;
use app\service\BlogService;
use app\service\PJAXHelper;
use app\service\SidebarService;
use League\CommonMark\Exception\CommonMarkException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 博客首页控制器
 */
class IndexController
{
    /**
     * 不需要登录的方法
     * index: 博客首页展示，公开访问
     */
    protected array $noNeedLogin = ['index'];

    /**
     * 允许的过滤字段
     */
    protected array $allowedFilters = [
        'category',
        'tag',
        'author',
        'search',
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
    #[EnableInstantFirstPaint]
    public function index(Request $request, int $page = 1): Response
    {
        // 构建筛选条件，并进行输入过滤
        $filters = $this->filterInput($request->get() ?: []);

        // 调用博客服务获取文章数据
        $result = BlogService::getBlogPosts($page, $filters);

        // 获取博客标题
        $blog_title = BlogService::getBlogTitle();

        // 使用PJAXHelper检测是否为PJAX请求
        $isPjax = PJAXHelper::isPJAX($request);

        // 获取侧边栏内容（PJAX 与非 PJAX 均获取，便于片段携带并在完成后注入右栏）
        $sidebar = SidebarService::getSidebarContent($request, 'home');

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = PJAXHelper::getViewName('index/index', $isPjax);

        // 仅对 PJAX 片段启用HTML缓存（TTL=120秒）
        $cacheKey = null;
        if ($isPjax) {
            $route = 'home:index.content';
            $params = [
                'page' => $page,
                'filters' => $filters,
            ];
            $cacheKey = PJAXHelper::generateCacheKey($route, $params, 1);
        }

        // 准备 SEO 数据
        $siteUrl = $request->host();
        $siteTitle = blog_config('title', 'WindBlog', true);
        $siteDescription = blog_config('description', '一个异常精致的博客系统', true);

        $seoData = [
            'title' => $siteTitle,
            'description' => $siteDescription,
            'og_type' => 'website',
            'url' => 'https://' . $siteUrl,
            'canonical' => 'https://' . $siteUrl,
            'site_name' => $siteTitle,
            'locale' => 'zh_CN',
            'image' => 'https://' . $siteUrl . blog_config('site_logo', '', true),
            'image_alt' => $siteTitle,
            'twitter_card' => 'summary',
        ];

        // 准备 Schema.org WebSite 结构化数据
        $schemaData = [
            'type' => 'WebSite',
            'name' => $siteTitle,
            'description' => $siteDescription,
            'url' => 'https://' . $siteUrl,
            'searchUrl' => 'https://' . $siteUrl . '/search?q={search_term_string}',
        ];

        // 创建带缓存的PJAX响应
        $resp = PJAXHelper::createResponse(
            $request,
            $viewName,
            [
                'page_title' => $blog_title,
                'posts' => $result['posts'],
                'pagination' => $result['pagination'],
                'sidebar' => $sidebar,
                'seo' => $seoData,
                'schema' => $schemaData,
            ],
            $cacheKey,
            120,
            'page'
        );

        return $resp;
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
}

<?php

namespace app\controller;

use app\annotation\EnableInstantFirstPaint;
use app\helper\BreadcrumbHelper;
use app\model\Post;
use app\service\AdService;
use app\service\CSRFService;
use app\service\FloLinkService;
use app\service\markdown\MarkdownService;
use app\service\PJAXHelper;
use app\service\SidebarService;
use app\util\HtmlSanitizer;
use Exception;
use support\Log;
use support\Request;
use support\Response;

class PostController
{
    /**
     * 不需要登录的方法
     * index: 文章详情页，公开访问（包括公开文章和密码文章）
     */
    protected array $noNeedLogin = ['index'];

    #[EnableInstantFirstPaint]
    public function index(Request $request, mixed $keyword = null): Response
    {
        // 移除URL参数中的 .html 后缀
        if (is_string($keyword) && str_ends_with($keyword, '.html')) {
            $keyword = substr($keyword, 0, -5);
        }

        // 统一使用 slug 模式，提高性能并降低错误率
        $post = Post::where('slug', $keyword)
            ->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', utc_now());
            })
            ->first();

        if (!$post) {
            return view('error/404');
        }

        // 检测是否为AMP请求
        $isAmp = $this->isAmpRequest($request);

        // 使用PJAXHelper检测是否为PJAX请求
        $isPjax = PJAXHelper::isPJAX($request);

        // 获取侧边栏内容（PJAX 与非 PJAX 均获取）
        $sidebar = SidebarService::getSidebarContent($request, 'post');

        // 加载作者信息与分类、标签
        $post->load(['authors', 'primaryAuthor', 'categories', 'tags']);
        $primaryAuthor = $post->primaryAuthor->first();
        $authorName = $primaryAuthor ? $primaryAuthor->nickname : ($post->authors->first() ? $post->authors->first()->nickname : '未知作者');

        if ($post->visibility === 'public') {

            // 记录浏览量（post_ext:view_count）
            try {
                $viewExt = $post->getExt('view_count');
                $data = $viewExt ? (is_array($viewExt->value) ? $viewExt->value : []) : [];
                $cnt = (int) ($data['count'] ?? 0);
                $data['count'] = $cnt + 1;
                $data['updated_at'] = time();
                $post->setExt('view_count', $data);
            } catch (\Throwable $e) {
                Log::warning('记录浏览量失败: ' . $e->getMessage());
            }

            // 生成面包屑导航
            $breadcrumbs = BreadcrumbHelper::forPost($post);

            // 使用FloLink处理文章内容(仅非AMP请求)
            if (!$isAmp && blog_config('flolink_enabled', true)) {
                try {
                    $post->content = FloLinkService::processContent($post->content);
                } catch (Exception $e) {
                    Log::error('FloLink处理失败: ' . $e->getMessage());
                    // 处理失败时使用原始内容
                }
            }

            // AMP请求使用专用渲染
            if ($isAmp) {
                return $this->renderAmpPost($request, $post, $authorName, $breadcrumbs);
            }

            // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
            $viewName = PJAXHelper::getViewName('index/post', $isPjax);

            // 先进行服务端 Markdown 渲染，保证与前端 Vditor 风格一致
            $postHtml = $post->content ?? '';
            $contentType = $post->content_type ?? 'markdown';
            if ($postHtml !== '') {
                if ($contentType === 'markdown' || $contentType === 'md' || $contentType === null) {
                    $md = new MarkdownService([
                        'options' => [
                            // 贴近 Vditor：不输出原始 HTML，禁用不安全链接
                            'html_input' => 'strip',
                            'allow_unsafe_links' => false,
                        ],
                        'css_class' => 'vditor-reset',
                    ]);
                    $postHtml = $md->render($postHtml, [
                        'wrap' => false,
                        'inject_css' => '',
                    ]);
                }
            }

            // 注入内嵌广告（非 AMP）
            try {
                if (!$isAmp) {
                    $postHtml = AdService::injectInlineAds((string) $postHtml, ['slug' => $post->slug]);
                }
            } catch (\Throwable $e) {
                Log::warning('Inline ads injection failed: ' . $e->getMessage());
            }

            // 非 PJAX 请求启用页面级缓存（TTL=120）
            $cacheKey = null;
            if (!$isPjax) {
                $locale = $request->header('Accept-Language') ?? 'zh-CN';
                $route = 'post.index';
                $params = ['slug' => $keyword];
                $cacheKey = PJAXHelper::generateCacheKey($route, $params, 1, $locale);
            }

            // 准备 SEO 数据，优先使用自定义 SEO 字段
            $siteUrl = $request->host();
            $postUrl = 'https://' . $siteUrl . '/post/' . $post->slug . '.html';

            // SEO 标题：自定义 > 文章标题
            $seoTitle = !empty($post->seo_title) ? $post->seo_title : $post->title;

            // SEO 描述：自定义 > AI 摘要 > 内容截取
            $description = !empty($post->seo_description)
                ? $post->seo_description
                : ($post->ai_summary ?? (mb_substr(strip_tags($post->content), 0, 150) . '...'));

            // SEO 关键词：自定义 > 标签
            if (!empty($post->seo_keywords)) {
                $keywords = array_map('trim', explode(',', $post->seo_keywords));
            } else {
                $keywords = $post->tags->pluck('name')->toArray();
            }

            $categoryNames = $post->categories->pluck('name')->toArray();

            $seoData = [
                'title' => $seoTitle,
                'description' => $description,
                'keywords' => implode(', ', $keywords),
                'author' => $authorName,
                'og_type' => 'article',
                'url' => $postUrl,
                'canonical' => $postUrl,
                'publish_date' => $post->created_at->toIso8601String(),
                'modified_date' => $post->updated_at->toIso8601String(),
                'site_name' => blog_config('title', 'WindBlog', true),
                'locale' => 'zh_CN',
                'section' => !empty($categoryNames) ? $categoryNames[0] : null,
                'tags' => $keywords,
                'image' => $post->featured_image ?? ('https://' . $siteUrl . blog_config('site_logo', '', true)),
                'image_alt' => $post->title,
                'twitter_card' => 'summary_large_image',
            ];

            // 准备 Schema.org 结构化数据
            $schemaData = [
                'type' => 'BlogPosting',
                'headline' => $post->title,
                'description' => $description,
                'image' => [$post->featured_image ?? ('https://' . $siteUrl . blog_config('site_logo', '', true))],
                'datePublished' => $post->created_at->toIso8601String(),
                'dateModified' => $post->updated_at->toIso8601String(),
                'author' => $authorName,
                'publisher' => [
                    'name' => blog_config('title', 'WindBlog', true),
                    'logo' => 'https://' . $siteUrl . blog_config('site_logo', '', true),
                ],
                'url' => $postUrl,
                'keywords' => $keywords,
                'articleSection' => !empty($categoryNames) ? $categoryNames[0] : null,
                'wordCount' => mb_strlen(strip_tags($post->content)),
            ];

            // 生成AMP URL
            $ampUrl = $postUrl . '?amp=1';

            // 创建帧缓存的PJAX响应
            $resp = PJAXHelper::createResponse(
                $request,
                $viewName,
                [
                    'page_title' => $post['title'] . ' - ' . blog_config('title', 'WindBlog', true),
                    'post' => $post,
                    'post_html' => HtmlSanitizer::sanitize($postHtml),
                    'author' => $authorName,
                    'sidebar' => $sidebar,
                    'breadcrumbs' => $breadcrumbs,
                    'seo' => $seoData,
                    'schema' => $schemaData,
                    'amp_url' => $ampUrl,
                    'csrf_token' => (new CSRFService())->generateToken($request, '_token'),
                ],
                $cacheKey,
                120,
                'page'
            );
        } elseif ($post->visibility === 'password') {
            $accessble = false;
            $current_password = $request->get('password');
            if ($current_password) {
                if (password_verify($current_password, $post->password)) {
                    $accessble = true;
                } else {
                    $note = '密码错误';
                }
            }
            if ($accessble) {
                // 生成面包屑导航
                $breadcrumbs = BreadcrumbHelper::forPost($post);

                // 使用FloLink处理文章内容
                if (blog_config('flolink_enabled', true)) {
                    try {
                        $post->content = FloLinkService::processContent($post->content);
                    } catch (Exception $e) {
                        Log::error('FloLink处理失败: ' . $e->getMessage());
                        // 处理失败时使用原始内容
                    }
                }

                // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
                $viewName = PJAXHelper::getViewName('index/post', $isPjax);

                // 先进行服务端 Markdown 渲染，保证与前端 Vditor 风格一致
                $postHtml = $post->content ?? '';
                $contentType = $post->content_type ?? 'markdown';
                if ($postHtml !== '') {
                    if ($contentType === 'markdown' || $contentType === 'md' || $contentType === null) {
                        $md = new MarkdownService([
                            'options' => [
                                'html_input' => 'strip',
                                'allow_unsafe_links' => false,
                            ],
                            'css_class' => 'vditor-reset',
                        ]);
                        $postHtml = $md->render($postHtml, [
                            'wrap' => false,
                            'inject_css' => '',
                        ]);
                    }
                }

                // 注入内嵌广告（非 AMP）
                try {
                    if (!$isAmp) {
                        $postHtml = AdService::injectInlineAds((string) $postHtml, ['slug' => $post->slug]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Inline ads injection failed: ' . $e->getMessage());
                }

                // 非 PJAX 请求启用页面级缓存（TTL=120）
                $cacheKey = null;
                if (!$isPjax) {
                    $locale = $request->header('Accept-Language') ?? 'zh-CN';
                    $route = 'post.index';
                    $params = ['slug' => $keyword];
                    $cacheKey = PJAXHelper::generateCacheKey($route, $params, 1, $locale);
                }

                // 准备 SEO 数据，优先使用自定义 SEO 字段（密码文章也需要SEO）
                $siteUrl = $request->host();
                $postUrl = 'https://' . $siteUrl . '/post/' . $post->slug . '.html';

                // SEO 标题：自定义 > 文章标题
                $seoTitle = !empty($post->seo_title) ? $post->seo_title : $post->title;

                // SEO 描述：自定义 > AI 摘要 > 内容截取
                $description = !empty($post->seo_description)
                    ? $post->seo_description
                    : ($post->ai_summary ?? (mb_substr(strip_tags($post->content), 0, 150) . '...'));

                // SEO 关键词：自定义 > 标签
                if (!empty($post->seo_keywords)) {
                    $keywords = array_map('trim', explode(',', $post->seo_keywords));
                } else {
                    $keywords = $post->tags->pluck('name')->toArray();
                }

                $categoryNames = $post->categories->pluck('name')->toArray();

                $seoData = [
                    'title' => $seoTitle,
                    'description' => $description,
                    'keywords' => implode(', ', $keywords),
                    'author' => $authorName,
                    'og_type' => 'article',
                    'url' => $postUrl,
                    'canonical' => $postUrl,
                    'publish_date' => $post->created_at->toIso8601String(),
                    'modified_date' => $post->updated_at->toIso8601String(),
                    'site_name' => blog_config('title', 'WindBlog', true),
                    'locale' => 'zh_CN',
                    'section' => !empty($categoryNames) ? $categoryNames[0] : null,
                    'tags' => $keywords,
                    'image' => $post->featured_image ?? ('https://' . $siteUrl . blog_config('site_logo', '', true)),
                    'image_alt' => $post->title,
                    'twitter_card' => 'summary_large_image',
                ];

                // 准备 Schema.org 结构化数据
                $schemaData = [
                    'type' => 'BlogPosting',
                    'headline' => $post->title,
                    'description' => $description,
                    'image' => [$post->featured_image ?? ('https://' . $siteUrl . blog_config('site_logo', '', true))],
                    'datePublished' => $post->created_at->toIso8601String(),
                    'dateModified' => $post->updated_at->toIso8601String(),
                    'author' => $authorName,
                    'publisher' => [
                        'name' => blog_config('title', 'WindBlog', true),
                        'logo' => 'https://' . $siteUrl . blog_config('site_logo', '', true),
                    ],
                    'url' => $postUrl,
                    'keywords' => $keywords,
                    'articleSection' => !empty($categoryNames) ? $categoryNames[0] : null,
                    'wordCount' => mb_strlen(strip_tags($post->content)),
                ];

                // 创建带缓存的PJAX响应
                $resp = PJAXHelper::createResponse(
                    $request,
                    $viewName,
                    [
                        'page_title' => $post['title'] . ' - ' . blog_config('title', 'WindBlog', true),
                        'post' => $post,
                        'post_html' => HtmlSanitizer::sanitize($postHtml),
                        'author' => $authorName,
                        'sidebar' => $sidebar,
                        'breadcrumbs' => $breadcrumbs,
                        'seo' => $seoData,
                        'schema' => $schemaData,
                        'csrf_token' => (new CSRFService())->generateToken($request, '_token'),
                    ],
                    $cacheKey,
                    120,
                    'page'
                );
            } else {
                $viewName = PJAXHelper::getViewName('lock/post', $isPjax);
                $resp = view($viewName, [
                    'note' => $note ?? null,
                    'post' => $post,
                    'author' => $authorName,
                    'sidebar' => $sidebar,
                ]);
            }
        }

        if (empty($resp)) {
            $resp = view('error/404');
        }

        return $resp;
    }

    /**
     * 检测是否为AMP请求
     *
     * @param Request $request
     * @return bool
     */
    protected function isAmpRequest(Request $request): bool
    {
        // 通过查询参数检测
        if ($request->get('amp') === '1' || $request->get('amp') === 'true') {
            return true;
        }

        // 通过路径检测 (例如 /amp/post/123.html)
        $path = $request->path();
        if (str_starts_with($path, '/amp/')) {
            return true;
        }

        return false;
    }

    /**
     * 渲染AMP文章页面
     *
     * @param Request $request
     * @param Post $post
     * @param string $authorName
     * @param array $breadcrumbs
     * @return Response
     */
    protected function renderAmpPost(Request $request, Post $post, string $authorName, array $breadcrumbs): Response
    {
        $siteUrl = $request->host();
        $postUrl = 'https://' . $siteUrl . '/post/' . $post->slug . '.html';

        // 在 AMP 下将 Markdown 渲染为 HTML，且禁止内联 CSS 注入与包裹容器
        $ampContent = $post->content ?? '';
        $contentType = $post->content_type ?? 'markdown';
        if ($ampContent !== '') {
            if ($contentType === 'markdown' || $contentType === 'md' || $contentType === null) {
                $md = new MarkdownService([
                    'options' => [
                        // AMP 下移除原始 HTML 以避免不被允许的标签
                        'html_input' => 'strip',
                        'allow_unsafe_links' => false,
                    ],
                ]);
                $ampContent = $md->render($ampContent, [
                    'wrap' => false,
                    'inject_css' => '',
                ]);
            }
            // 可选：后续如需将 <img> 转换为 <amp-img>，可以在此处做正则替换
            // 为了保持内容纯净与兼容性，此处先直接输出渲染后的基本 HTML
        }

        // SEO 标题：自定义 > 文章标题
        $seoTitle = !empty($post->seo_title) ? $post->seo_title : $post->title;

        // SEO 描述：自定义 > AI 摘要 > 内容截取
        $description = !empty($post->seo_description)
            ? $post->seo_description
            : ($post->ai_summary ?? (mb_substr(strip_tags($post->content), 0, 150) . '...'));

        // SEO 关键词：自定义 > 标签
        if (!empty($post->seo_keywords)) {
            $keywords = array_map('trim', explode(',', $post->seo_keywords));
        } else {
            $keywords = $post->tags->pluck('name')->toArray();
        }

        $categoryNames = $post->categories->pluck('name')->toArray();

        // 准备 SEO 数据
        $seoData = [
            'title' => $seoTitle,
            'description' => $description,
            'keywords' => implode(', ', $keywords),
            'author' => $authorName,
        ];

        // 准备 Schema.org 结构化数据
        $schemaData = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post->title,
            'description' => $description,
            'image' => [$post->featured_image ?? ('https://' . $siteUrl . blog_config('site_logo', '', true))],
            'datePublished' => $post->created_at->toIso8601String(),
            'dateModified' => $post->updated_at->toIso8601String(),
            'author' => [
                '@type' => 'Person',
                'name' => $authorName,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => blog_config('title', 'WindBlog', true),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => 'https://' . $siteUrl . blog_config('site_logo', '', true),
                ],
            ],
            'url' => $postUrl,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $postUrl,
            ],
        ];

        // 准备 AMP 广告（Google）
        $ampAds = [];
        try {
            $ads = \app\service\AdService::getActiveAdsByPosition('inline', 1, ['slug' => $post->slug]);
            if (!$ads) {
                $ads = \app\service\AdService::getActiveAdsByPosition('sidebar', 1);
            }
            // 全局 Ad Client 兜底
            $globalCfg = blog_config('google_adsense', [], false, true);
            $globalClient = is_array($globalCfg) ? (string) ($globalCfg['client'] ?? '') : '';
            foreach ($ads as $ad) {
                if (($ad['type'] ?? '') !== 'google') {
                    continue;
                }
                $client = trim((string) ($ad['google_ad_client'] ?? '')) ?: $globalClient;
                $slot = trim((string) ($ad['google_ad_slot'] ?? ''));
                if (!$client || !$slot) {
                    continue;
                }
                $p = is_array($ad['placements'] ?? null) ? $ad['placements'] : [];
                $g = is_array($p['google'] ?? null) ? $p['google'] : [];
                $layout = (string) ($g['amp_layout'] ?? 'fixed');
                $width = (int) ($g['amp_width'] ?? 270);
                $height = (int) ($g['amp_height'] ?? 300);
                if ($width <= 0) {
                    $width = 270;
                }
                if ($height <= 0) {
                    $height = 300;
                }
                $ampAds[] = [
                    'client' => $client,
                    'slot' => $slot,
                    'layout' => $layout,
                    'width' => $width,
                    'height' => $height,
                ];
            }
        } catch (\Throwable $e) {
            // 忽略广告获取异常
        }

        return view('index/post.amp', [
            'page_title' => $post->title . ' - ' . blog_config('title', 'WindBlog', true),
            'post' => $post,
            'author' => $authorName,
            'breadcrumbs' => $breadcrumbs,
            'seo' => $seoData,
            'schema' => $schemaData,
            'canonical_url' => $postUrl,
            'request' => $request,
            'amp_content' => $ampContent,
            'amp_ads' => $ampAds,
        ]);
    }
}

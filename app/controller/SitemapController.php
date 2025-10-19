<?php

namespace app\controller;

use app\model\Category;
use app\model\Post;
use app\model\Tag;
use app\service\URLHelper;
use support\Request;
use support\Response;

class SitemapController
{
    protected function baseUrl(Request $request): string
    {
        $site = (string) blog_config('site_url', '', true);
        if ($site !== '') {
            return rtrim($site, '/');
        }
        $proto = $request->header('X-Forwarded-Proto') ?: ($request->header('x-forwarded-proto') ?: null);
        $scheme = $proto ?: 'http';
        $host = (string) ($request->header('host') ?? 'localhost');

        return $scheme . '://' . $host;
    }

    protected function abs(string $base, string $path): string
    {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    public function sitemap(Request $request): Response
    {
        // 缓存键
        $cacheKey = 'sitemap_xml_v1';
        $cached = cache($cacheKey);
        if ($cached !== false) {
            return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $cached);
        }

        $base = $this->baseUrl($request);

        // 首页
        $urls = [];
        $urls[] = [
            'loc' => $this->abs($base, '/'),
            'lastmod' => date(DATE_W3C),
            'changefreq' => 'daily',
            'priority' => '1.0',
        ];

        // 分类
        $categories = Category::query()->orderBy('id', 'asc')->get(['slug', 'updated_at']);
        foreach ($categories as $c) {
            $urls[] = [
                'loc' => $this->abs($base, URLHelper::generateCategoryUrl((string) $c->slug)),
                'lastmod' => ($c->updated_at ? $c->updated_at->toAtomString() : date(DATE_W3C)),
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ];
        }

        // 标签
        $tags = Tag::query()->orderBy('id', 'asc')->get(['slug', 'updated_at']);
        foreach ($tags as $t) {
            $urls[] = [
                'loc' => $this->abs($base, URLHelper::generateTagUrl((string) $t->slug)),
                'lastmod' => ($t->updated_at ? $t->updated_at->toAtomString() : date(DATE_W3C)),
                'changefreq' => 'weekly',
                'priority' => '0.5',
            ];
        }

        // 文章（仅已发布）
        $posts = Post::query()
            ->where('status', 'published')
            ->orderByDesc('id')
            ->get(['slug', 'updated_at', 'published_at']);
        foreach ($posts as $p) {
            $last = $p->updated_at ?: $p->published_at;
            $urls[] = [
                'loc' => $this->abs($base, URLHelper::generatePostUrl((string) $p->slug)),
                'lastmod' => ($last ? $last->toAtomString() : date(DATE_W3C)),
                'changefreq' => 'monthly',
                'priority' => '0.9',
            ];
        }

        // 归档（按月）
        $archives = $this->calcArchives();
        foreach ($archives as $ym => $lastDate) {
            $urls[] = [
                'loc' => $this->abs($base, '/archive/' . $ym),
                'lastmod' => date(DATE_W3C, $lastDate),
                'changefreq' => 'monthly',
                'priority' => '0.4',
            ];
        }

        $xml = $this->renderUrlset($urls);

        // 缓存 6 小时
        cache($cacheKey, $xml, true, 21600);

        return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $xml);
    }

    public function index(Request $request): Response
    {
        // 缓存键
        $cacheKey = 'sitemap_index_xml_v1';
        $cached = cache($cacheKey);
        if ($cached !== false) {
            return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $cached);
        }

        $base = $this->baseUrl($request);
        $maps = [];

        // posts
        $lastPost = Post::query()->where('status', 'published')->orderByDesc('updated_at')->orderByDesc('published_at')->first(['updated_at', 'published_at']);
        $maps[] = [
            'loc' => $this->abs($base, '/sitemap/posts.xml'),
            'lastmod' => $this->bestDate($lastPost?->updated_at, $lastPost?->published_at),
        ];

        // categories
        $lastCategory = Category::query()->orderByDesc('updated_at')->first(['updated_at']);
        $maps[] = [
            'loc' => $this->abs($base, '/sitemap/categories.xml'),
            'lastmod' => $this->bestDate($lastCategory?->updated_at, null),
        ];

        // tags
        $lastTag = Tag::query()->orderByDesc('updated_at')->first(['updated_at']);
        $maps[] = [
            'loc' => $this->abs($base, '/sitemap/tags.xml'),
            'lastmod' => $this->bestDate($lastTag?->updated_at, null),
        ];

        // archives
        $archives = $this->calcArchives();
        $lastArchiveTime = !empty($archives) ? max($archives) : time();
        $maps[] = [
            'loc' => $this->abs($base, '/sitemap/archives.xml'),
            'lastmod' => date(DATE_W3C, $lastArchiveTime),
        ];

        $xml = $this->renderSitemapIndex($maps);

        // 缓存 6 小时
        cache($cacheKey, $xml, true, 21600);

        return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $xml);
    }

    public function posts(Request $request): Response
    {
        // 缓存键
        $cacheKey = 'sitemap_posts_xml_v1';
        $cached = cache($cacheKey);
        if ($cached !== false) {
            return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $cached);
        }

        $base = $this->baseUrl($request);
        $urls = [];
        $posts = Post::query()
            ->where('status', 'published')
            ->orderByDesc('id')
            ->get(['slug', 'updated_at', 'published_at']);
        foreach ($posts as $p) {
            $last = $p->updated_at ?: $p->published_at;
            $urls[] = [
                'loc' => $this->abs($base, URLHelper::generatePostUrl((string) $p->slug)),
                'lastmod' => ($last ? $last->toAtomString() : date(DATE_W3C)),
                'changefreq' => 'monthly',
                'priority' => '0.9',
            ];
        }

        $xml = $this->renderUrlset($urls);

        // 缓存 6 小时
        cache($cacheKey, $xml, true, 21600);

        return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $xml);
    }

    public function categories(Request $request): Response
    {
        // 缓存键
        $cacheKey = 'sitemap_categories_xml_v1';
        $cached = cache($cacheKey);
        if ($cached !== false) {
            return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $cached);
        }

        $base = $this->baseUrl($request);
        $urls = [];
        $categories = Category::query()->orderBy('id', 'asc')->get(['slug', 'updated_at']);
        foreach ($categories as $c) {
            $urls[] = [
                'loc' => $this->abs($base, URLHelper::generateCategoryUrl((string) $c->slug)),
                'lastmod' => ($c->updated_at ? $c->updated_at->toAtomString() : date(DATE_W3C)),
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ];
        }
        $xml = $this->renderUrlset($urls);

        // 缓存 6 小时
        cache($cacheKey, $xml, true, 21600);

        return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $xml);
    }

    public function tags(Request $request): Response
    {
        // 缓存键
        $cacheKey = 'sitemap_tags_xml_v1';
        $cached = cache($cacheKey);
        if ($cached !== false) {
            return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $cached);
        }

        $base = $this->baseUrl($request);
        $urls = [];
        $tags = Tag::query()->orderBy('id', 'asc')->get(['slug', 'updated_at']);
        foreach ($tags as $t) {
            $urls[] = [
                'loc' => $this->abs($base, URLHelper::generateTagUrl((string) $t->slug)),
                'lastmod' => ($t->updated_at ? $t->updated_at->toAtomString() : date(DATE_W3C)),
                'changefreq' => 'weekly',
                'priority' => '0.5',
            ];
        }
        $xml = $this->renderUrlset($urls);

        // 缓存 6 小时
        cache($cacheKey, $xml, true, 21600);

        return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $xml);
    }

    public function archives(Request $request): Response
    {
        // 缓存键
        $cacheKey = 'sitemap_archives_xml_v1';
        $cached = cache($cacheKey);
        if ($cached !== false) {
            return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $cached);
        }

        $base = $this->baseUrl($request);
        $urls = [];
        $archives = $this->calcArchives();
        foreach ($archives as $ym => $lastDate) {
            $urls[] = [
                'loc' => $this->abs($base, '/archive/' . $ym),
                'lastmod' => date(DATE_W3C, $lastDate),
                'changefreq' => 'monthly',
                'priority' => '0.4',
            ];
        }
        $xml = $this->renderUrlset($urls);

        // 缓存 6 小时
        cache($cacheKey, $xml, true, 21600);

        return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'], $xml);
    }

    protected function renderUrlset(array $urls): string
    {
        $buf = [];
        $buf[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $buf[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $u) {
            $buf[] = '  <url>';
            $buf[] = '    <loc>' . htmlspecialchars($u['loc'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
            if (!empty($u['lastmod'])) {
                $buf[] = '    <lastmod>' . htmlspecialchars((string) $u['lastmod'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</lastmod>';
            }
            if (!empty($u['changefreq'])) {
                $buf[] = '    <changefreq>' . htmlspecialchars((string) $u['changefreq'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</changefreq>';
            }
            if (!empty($u['priority'])) {
                $buf[] = '    <priority>' . htmlspecialchars((string) $u['priority'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</priority>';
            }
            $buf[] = '  </url>';
        }
        $buf[] = '</urlset>';

        return implode("\n", $buf);
    }

    protected function renderSitemapIndex(array $maps): string
    {
        $buf = [];
        $buf[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $buf[] = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($maps as $m) {
            $buf[] = '  <sitemap>';
            $buf[] = '    <loc>' . htmlspecialchars($m['loc'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
            if (!empty($m['lastmod'])) {
                $buf[] = '    <lastmod>' . htmlspecialchars((string) $m['lastmod'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</lastmod>';
            }
            $buf[] = '  </sitemap>';
        }
        $buf[] = '</sitemapindex>';

        return implode("\n", $buf);
    }

    protected function bestDate($a, $b): string
    {
        $ts = null;
        if ($a) {
            $ts = strtotime((string) $a);
        }
        if ($b) {
            $ts = max($ts ?? 0, strtotime((string) $b));
        }
        if (!$ts) {
            $ts = time();
        }

        return date(DATE_W3C, $ts);
    }

    /**
     * 计算归档：返回 [ 'YYYY-MM' => 月末时间戳 ]
     */
    protected function calcArchives(): array
    {
        $posts = Post::query()->where('status', 'published')->get(['published_at', 'created_at']);
        $months = [];
        foreach ($posts as $p) {
            $dt = $p->published_at ?: $p->created_at;
            if (!$dt) {
                continue;
            }
            $ym = $dt->format('Y-m');
            // 当月最后一天 23:59:59
            $lastDayTs = strtotime(date('Y-m-t 23:59:59', strtotime($dt->format('Y-m-d H:i:s'))));
            if (!isset($months[$ym]) || $months[$ym] < $lastDayTs) {
                $months[$ym] = $lastDayTs;
            }
        }
        krsort($months); // 新的在前

        return $months;
    }

    /**
     * 图形化站点地图页面
     */
    public function graphical(Request $request): Response
    {
        // 缓存键
        $cacheKey = 'sitemap_graphical_html_v1';
        $cached = cache($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return view('sitemap/graphical', $cached);
        }

        $base = $this->baseUrl($request);

        // 首页
        $home = [
            'title' => '首页',
            'url' => '/',
            'icon' => 'fa-home',
        ];

        // 分类
        $categories = Category::query()->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get(['name', 'slug', 'description']);
        $categoryList = [];
        foreach ($categories as $c) {
            $categoryList[] = [
                'name' => $c->name,
                'url' => URLHelper::generateCategoryUrl((string) $c->slug, false),
                'description' => $c->description,
            ];
        }

        // 标签
        $tags = Tag::query()->withCount('posts')->orderByDesc('posts_count')->get(['name', 'slug']);
        $tagList = [];
        foreach ($tags as $t) {
            $tagList[] = [
                'name' => $t->name,
                'url' => URLHelper::generateTagUrl((string) $t->slug, false),
                'count' => $t->posts_count ?? 0,
            ];
        }

        // 文章（全部）
        $posts = Post::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get(['title', 'slug', 'published_at', 'excerpt']);
        $postList = [];
        foreach ($posts as $p) {
            $postList[] = [
                'title' => $p->title,
                'url' => URLHelper::generatePostUrl((string) $p->slug, false),
                'date' => $p->published_at ? $p->published_at->format('Y-m-d') : '',
                'excerpt' => $p->excerpt ? mb_substr(strip_tags((string) $p->excerpt), 0, 100) : '',
            ];
        }

        // 归档
        $archives = $this->calcArchives();
        $archiveList = [];
        foreach ($archives as $ym => $ts) {
            $archiveList[] = [
                'month' => $ym,
                'url' => '/archive/' . $ym,
            ];
        }

        $viewData = [
            'page_title' => '站点地图',
            'home' => $home,
            'categories' => $categoryList,
            'tags' => $tagList,
            'posts' => $postList,
            'archives' => $archiveList,
            'site_url' => $base,
        ];

        // 缓存 6 小时
        cache($cacheKey, $viewData, true, 21600);

        return view('sitemap/graphical', $viewData);
    }
}

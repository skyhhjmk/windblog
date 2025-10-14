<?php

namespace app\service;

use app\model\FloLink;

/**
 * FloLink 服务类
 * 处理文章内容中的关键词自动链接替换
 */
class FloLinkService
{
    /**
     * 缓存键前缀
     */
    private const CACHE_PREFIX = 'flolink_';

    /**
     * 缓存时间（秒）
     */
    private const CACHE_TTL = 3600;

    /**
     * 处理文章内容，替换关键词为浮动链接
     *
     * @param string $content HTML内容
     * @return string 处理后的内容
     */
    public static function processContent(string $content): string
    {
        // 检查是否启用FloLink功能
        if (!blog_config('flolink_enabled', true)) {
            return $content;
        }

        // 获取所有启用的浮动链接配置
        $floLinks = self::getActiveFloLinks();
        // 即使没有任何 FloLink 规则，也继续解析 DOM，以便执行通用链接改写（如 rainyun 联盟链接）

        // 解析HTML，避免替换标签内的内容
        $dom = new \DOMDocument('1.0', 'UTF-8');

        // 抑制HTML解析错误
        libxml_use_internal_errors(true);

        // 添加meta标签确保UTF-8编码
        $contentWithMeta = '<?xml encoding="UTF-8"><div>' . $content . '</div>';
        @$dom->loadHTML($contentWithMeta, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        // 处理每个浮动链接配置
        foreach ($floLinks as $floLink) {
            self::replaceKeywordInDOM($dom, $floLink);
        }

        // 将纯文本中的 URL 自动包裹为链接（可配置）
        try {
            $enableAutoLink = blog_config('flolink_auto_link_url', true);
        } catch (\Throwable $e) {
            $enableAutoLink = true;
        }
        if ($enableAutoLink) {
            self::autoLinkUrlsInDOM($dom);
        }
        // 对现有超链接执行特殊改写（可配置）
        try {
            $enableAffiliateRewrite = blog_config('flolink_affiliate_rewrite', true);
        } catch (\Throwable $e) {
            $enableAffiliateRewrite = true;
        }
        if ($enableAffiliateRewrite) {
            self::rewriteSpecialLinksInDOM($dom);
        }

        // 获取处理后的HTML
        $processedContent = $dom->saveHTML();

        // 移除添加的包装div标签
        $processedContent = preg_replace('/^<div>(.*)<\/div>$/s', '$1', $processedContent);

        return $processedContent;
    }

    /**
     * 在DOM中替换关键词
     *
     * @param \DOMDocument $dom
     * @param FloLink $floLink
     */
    private static function replaceKeywordInDOM(\DOMDocument $dom, FloLink $floLink): void
    {
        $xpath = new \DOMXPath($dom);

        // 只处理文本节点，排除script、style、pre、code等标签
        $textNodes = $xpath->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code) and not(ancestor::a)]');

        $replacedCount = 0;
        $nodesToReplace = [];

        foreach ($textNodes as $textNode) {
            $text = $textNode->nodeValue;

            // 根据是否区分大小写进行匹配
            $pattern = $floLink->case_sensitive
                ? '/(?<![<>])(' . preg_quote($floLink->keyword, '/') . ')(?![<>])/'
                : '/(?<![<>])(' . preg_quote($floLink->keyword, '/') . ')(?![<>])/iu';

            // 检查是否匹配
            if (preg_match($pattern, $text)) {
                // 根据匹配模式决定替换次数
                $limit = ($floLink->match_mode === 'first') ? 1 : -1;

                // 检查是否已经达到替换限制
                if ($floLink->match_mode === 'first' && $replacedCount >= 1) {
                    continue;
                }

                // 创建替换后的HTML片段
                $replacement = self::createLinkHTML($floLink);

                // 执行替换
                $newText = preg_replace($pattern, $replacement, $text, $limit, $count);

                if ($count > 0) {
                    $nodesToReplace[] = [
                        'node' => $textNode,
                        'html' => $newText,
                    ];
                    $replacedCount += $count;
                }
            }
        }

        // 执行替换操作
        foreach ($nodesToReplace as $item) {
            $fragment = $dom->createDocumentFragment();
            @$fragment->appendXML($item['html']);

            if ($fragment) {
                $item['node']->parentNode->replaceChild($fragment, $item['node']);
            }
        }
    }

    /**
     * 智能替换已有链接
     * 如果文章中已经有带链接的关键词，根据配置决定是否替换
     *
     * @param string $content
     * @param FloLink $floLink
     * @return string
     */
    private static function replaceExistingLinks(string $content, FloLink $floLink): string
    {
        if (!$floLink->replace_existing) {
            return $content;
        }

        // 查找包含关键词的现有链接
        $pattern = '/<a[^>]+>(' . preg_quote($floLink->keyword, '/') . ')<\/a>/i';

        if ($floLink->match_mode === 'first') {
            $content = preg_replace($pattern, self::createLinkHTML($floLink), $content, 1);
        } else {
            $content = preg_replace($pattern, self::createLinkHTML($floLink), $content);
        }

        return $content;
    }

    /**
     * 创建链接HTML
     *
     * @param FloLink $floLink
     * @return string
     */
    private static function createLinkHTML(FloLink $floLink): string
    {
        // 在生成链接前做联盟链接改写（可配置）
        $preparedUrl = (string) $floLink->url;
        try {
            $enableAffiliateRewrite = blog_config('flolink_affiliate_rewrite', true);
        } catch (\Throwable $e) {
            $enableAffiliateRewrite = true;
        }
        if ($enableAffiliateRewrite) {
            $preparedUrl = self::rewriteAffiliateLink($preparedUrl);
        }

        $attributes = [
            'href' => htmlspecialchars($preparedUrl, ENT_QUOTES, 'UTF-8'),
            'target' => htmlspecialchars($floLink->target, ENT_QUOTES, 'UTF-8'),
            'rel' => htmlspecialchars($floLink->rel, ENT_QUOTES, 'UTF-8'),
            'class' => htmlspecialchars($floLink->css_class, ENT_QUOTES, 'UTF-8'),
        ];

        // 如果启用悬浮窗，添加data属性
        if ($floLink->enable_hover) {
            $attributes['data-flolink'] = 'true';
            $attributes['data-flolink-id'] = $floLink->id;

            if ($floLink->title) {
                $attributes['data-flolink-title'] = htmlspecialchars($floLink->title, ENT_QUOTES, 'UTF-8');
            }

            if ($floLink->description) {
                $attributes['data-flolink-desc'] = htmlspecialchars($floLink->description, ENT_QUOTES, 'UTF-8');
            }

            if ($floLink->image) {
                $attributes['data-flolink-image'] = htmlspecialchars($floLink->image, ENT_QUOTES, 'UTF-8');
            }

            $attributes['data-flolink-delay'] = $floLink->hover_delay;
        }

        // 构建属性字符串
        $attrString = '';
        foreach ($attributes as $key => $value) {
            $attrString .= sprintf(' %s="%s"', $key, $value);
        }

        return sprintf('<a%s>$1</a>', $attrString);
    }

    /**
     * 针对特定站点的联盟/推广链接改写（rainyun专用）
     * 规则：当 host 为 rainyun.com（含子域）且路径最后一个非空段以“_”结尾，则将该段改为“github_”。
     */
    private static function rewriteAffiliateLink(string $url): string
    {
        if ($url === '') {
            return $url;
        }
        try {
            $parts = parse_url($url);
            if (!$parts || empty($parts['host'])) {
                return $url;
            }
            $host = strtolower($parts['host']);
            $isRainyun = ($host === 'rainyun.com') || ($host === 'www.rainyun.com') || (str_ends_with($host, '.rainyun.com'));
            if (!$isRainyun) {
                return $url;
            }
            $path = $parts['path'] ?? '/';
            if ($path === '') {
                return $url;
            }
            $hasTrailingSlash = str_ends_with($path, '/');
            $segments = explode('/', $path);
            // 找到最后一个非空段
            $idx = count($segments) - 1;
            if ($idx < 0) {
                return $url;
            }
            if ($segments[$idx] === '') {
                $idx--;
            }
            if ($idx < 0) {
                return $url;
            }
            $last = $segments[$idx];
            // 仅在末段以 '_' 结尾时改写
            if (!str_ends_with($last, '_')) {
                return $url;
            }
            // 统一改写为配置的后缀（默认 github_）
            $suffix = 'github_';
            try {
                $conf = blog_config('flolink_affiliate_suffix', 'github_');
                if (is_string($conf) && $conf !== '') {
                    $suffix = $conf;
                }
            } catch (\Throwable $e) {
            }
            $segments[$idx] = $suffix;
            $newPath = implode('/', $segments);
            if ($newPath === '' || $newPath[0] !== '/') {
                $newPath = '/' . $newPath;
            }
            if ($hasTrailingSlash && !str_ends_with($newPath, '/')) {
                $newPath .= '/';
            }

            $scheme = $parts['scheme'] ?? 'https';
            $port = isset($parts['port']) ? (':' . $parts['port']) : '';
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';
            $auth = '';
            if ($user !== '') {
                $auth = $user;
                if ($pass !== '') {
                    $auth .= ':' . $pass;
                }
                $auth .= '@';
            }
            $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
            $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? ('#' . $parts['fragment']) : '';

            return $scheme . '://' . $auth . $host . $port . $newPath . $query . $fragment;
        } catch (\Throwable $e) {
            return $url;
        }
    }

    /**
     * 遍历 DOM 中的链接并应用联盟改写
     */
    private static function rewriteSpecialLinksInDOM(\DOMDocument $dom): void
    {
        try {
            $xpath = new \DOMXPath($dom);
            $anchors = $xpath->query('//a[@href]');
            if (!$anchors) {
                return;
            }
            foreach ($anchors as $a) {
                $href = $a->getAttribute('href') ?? '';
                if ($href === '') {
                    continue;
                }
                $newHref = self::rewriteAffiliateLink($href);
                if ($newHref !== $href && $newHref !== '') {
                    $a->setAttribute('href', $newHref);
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 将纯文本中的 URL 自动包裹为链接（不处理 a/script/style/pre/code 中的内容）
     */
    private static function autoLinkUrlsInDOM(\DOMDocument $dom): void
    {
        try {
            $xpath = new \DOMXPath($dom);
            $textNodes = $xpath->query('//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]');
            if (!$textNodes) {
                return;
            }
            $pattern = '/https?:\/\/[^\s<>"]+/iu';
            foreach ($textNodes as $node) {
                $text = $node->nodeValue ?? '';
                if ($text === '' || !preg_match($pattern, $text)) {
                    continue;
                }
                $html = '';
                $offset = 0;
                if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $m) {
                        $url = $m[0];
                        $pos = (int) $m[1];
                        $before = substr($text, $offset, $pos - $offset);
                        $html .= htmlspecialchars($before, ENT_QUOTES, 'UTF-8');
                        $rewritten = self::rewriteAffiliateLink($url);
                        $safeHref = htmlspecialchars($rewritten, ENT_QUOTES, 'UTF-8');
                        $safeText = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                        $html .= '<a href="' . $safeHref . '" target="_blank" rel="noopener nofollow">' . $safeText . '</a>';
                        $offset = $pos + strlen($url);
                    }
                    $after = substr($text, $offset);
                    $html .= htmlspecialchars($after, ENT_QUOTES, 'UTF-8');

                    $frag = $dom->createDocumentFragment();
                    @$frag->appendXML($html);
                    if ($frag) {
                        $node->parentNode->replaceChild($frag, $node);
                    }
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 获取所有启用的浮动链接配置（带缓存）
     *
     * @return array
     */
    private static function getActiveFloLinks(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'active_list';

        // 尝试从缓存获取（使用统一的PSR-16适配器，自动处理序列化/反序列化）
        try {
            $cache = CacheService::getPsr16Adapter();
            $cached = $cache->get($cacheKey, null);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Exception $e) {
            \support\Log::warning('FloLink cache get failed: ' . $e->getMessage());
        }

        // 从数据库获取
        try {
            // 返回模型对象数组，便于后续按对象属性访问
            $floLinks = FloLink::active()
                ->ordered()
                ->get()
                ->all();

            // 存入缓存（对象通过序列化存储，读取时自动反序列化）
            try {
                $cache = CacheService::getPsr16Adapter();
                $cache->set($cacheKey, $floLinks, self::CACHE_TTL);
            } catch (\Exception $e) {
                \support\Log::warning('FloLink cache set failed: ' . $e->getMessage());
            }

            return $floLinks;
        } catch (\Exception $e) {
            \support\Log::error('Get active FloLinks failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * 清除FloLink缓存
     *
     * @return void
     */
    public static function clearCache(): void
    {
        try {
            $cacheKey = self::CACHE_PREFIX . 'active_list';
            // 使用 PSR-16 适配器，内部会正确调用底层 del，并处理前缀
            $cache = CacheService::getPsr16Adapter();
            $cache->delete($cacheKey);
        } catch (\Exception $e) {
            \support\Log::warning('FloLink cache clear failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取单个FloLink的悬浮窗数据（用于AJAX请求）
     *
     * @param int $id
     * @return array|null
     */
    public static function getFloLinkData(int $id): ?array
    {
        try {
            $floLink = FloLink::find($id);

            if (!$floLink || !$floLink->status) {
                return null;
            }

            return [
                'id' => $floLink->id,
                'keyword' => $floLink->keyword,
                'url' => $floLink->url,
                'title' => $floLink->title,
                'description' => $floLink->description,
                'image' => $floLink->image,
                'enable_hover' => $floLink->enable_hover,
            ];
        } catch (\Exception $e) {
            \support\Log::error('Get FloLink data failed: ' . $e->getMessage());

            return null;
        }
    }
}

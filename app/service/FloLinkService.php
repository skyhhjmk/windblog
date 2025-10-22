<?php

namespace app\service;

use app\model\FloLink;
use DOMDocument;
use DOMXPath;
use Exception;
use support\Log;
use Throwable;

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
     *
     * @return string 处理后的内容
     */
    public static function processContent(string $content): string
    {
        // 检查是否启用FloLink功能（缺失时初始化为默认值，避免反复 miss 日志）
        if (!blog_config('flolink_enabled', true, true)) {
            return $content;
        }

        // Markdown 内容：走 Markdown 安全替换流程，避免破坏换行与语法
        if (!self::isHtmlLike($content)) {
            $floLinks = self::getActiveFloLinks();

            return self::processMarkdown($content, $floLinks);
        }

        // 获取所有启用的浮动链接配置
        $floLinks = self::getActiveFloLinks();
        // 即使没有任何 FloLink 规则，也继续解析 DOM，以便执行通用链接改写（如 rainyun 联盟链接）

        // 解析HTML，避免替换标签内的内容
        $dom = new DOMDocument('1.0', 'UTF-8');

        // 抑制HTML解析错误
        libxml_use_internal_errors(true);

        // 添加meta标签确保UTF-8编码
        $contentWithMeta = '<?xml encoding="UTF-8"><div>' . $content . '</div>';
        @$dom->loadHTML($contentWithMeta, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        // 在处理前保护代码/图形等敏感节点，避免任何序列化与替换对其造成影响
        $placeholders = self::protectSensitiveNodes($dom);

        // 处理每个浮动链接配置
        foreach ($floLinks as $floLink) {
            self::replaceKeywordInDOM($dom, $floLink);
        }

        // 对现有超链接执行特殊改写（可配置）
        try {
            $enableAffiliateRewrite = blog_config('flolink_affiliate_rewrite', true, true);
        } catch (Throwable $e) {
            $enableAffiliateRewrite = true;
        }
        if ($enableAffiliateRewrite) {
            self::rewriteSpecialLinksInDOM($dom);
        }

        // 获取处理后的HTML（精确提取包裹 div 的内部内容，避免遗留 XML 声明等）
        $processedContent = self::extractInnerHtmlFromRootDiv($dom);

        // 恢复占位符到原始受保护的片段
        $processedContent = self::restorePlaceholders($processedContent, $placeholders);

        return $processedContent;
    }

    /**
     * 在DOM中替换关键词
     *
     * @param DOMDocument $dom
     * @param FloLink $floLink
     */
    private static function replaceKeywordInDOM(DOMDocument $dom, FloLink $floLink): void
    {
        $xpath = new DOMXPath($dom);

        // 只处理文本节点，排除 script、style、pre、code、a 以及任何含有 mermaid 相关类名的容器内的文本
        // 说明：部分渲染器会把 mermaid 流程图输出为 <div class="language-mermaid"> 或 <div class="mermaid"> 等
        // 为避免在图文本中插入链接导致语法解析失败，这里排除所有 class 中包含 "mermaid" 子串的祖先元素
        $textNodes = $xpath->query(
            '//text()[
                not(ancestor::script)
                and not(ancestor::style)
                and not(ancestor::pre)
                and not(ancestor::code)
                and not(ancestor::a)
                and not(ancestor::*[contains(@class, "mermaid")])
                and not(ancestor::*[contains(@class, "language-")])
            ]'
        );

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
     * 保护敏感节点，防止在 DOM 序列化与替换过程中破坏其文本格式（如 mermaid、代码块等）
     *
     * @param DOMDocument $dom
     *
     * @return array<string,string> [placeholder => originalOuterHTML]
     */
    private static function protectSensitiveNodes(DOMDocument $dom): array
    {
        $placeholders = [];
        try {
            $xpath = new DOMXPath($dom);
            // 保护包含 mermaid / language-* 的容器以及 pre/code 节点
            $nodes = $xpath->query('//*[contains(@class, "mermaid") or contains(@class, "language-") or self::pre or self::code]');
            if (!$nodes) {
                return $placeholders;
            }
            // 倒序替换，避免节点移动导致的索引问题
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if (!$node || !$node->parentNode) {
                    continue;
                }
                // 生成占位符
                $uniq = str_replace(['.', ' '], '', uniqid('FLOLINK_', true));
                $ph = '[[[' . $uniq . ']]]';
                // 存储原始 outerHTML
                $original = $dom->saveHTML($node);
                if (!is_string($original) || $original === '') {
                    continue;
                }
                $placeholders[$ph] = $original;
                // 用纯文本占位符替换节点
                $textNode = $dom->createTextNode($ph);
                $node->parentNode->replaceChild($textNode, $node);
            }
        } catch (Throwable $e) {
            // 忽略，返回已收集的占位符
        }

        return $placeholders;
    }

    /**
     * 将占位符还原为原始 HTML 片段
     *
     * @param string $html
     * @param array<string,string> $placeholders
     *
     * @return string
     */
    private static function restorePlaceholders(string $html, array $placeholders): string
    {
        if (!$placeholders) {
            return $html;
        }
        foreach ($placeholders as $ph => $frag) {
            $html = str_replace($ph, $frag, $html);
        }

        return $html;
    }

    /**
     * 提取通过包装 <div> 处理后的内部 HTML，避免输出 XML 声明/外层 div
     */
    private static function extractInnerHtmlFromRootDiv(DOMDocument $dom): string
    {
        try {
            $root = $dom->documentElement; // 可能就是 div
            if (!$root) {
                return $dom->saveHTML();
            }
            // 如果根就是 div，则拼接其子节点；否则尝试定位首个 div
            if (strtolower($root->nodeName) !== 'div') {
                $divs = $dom->getElementsByTagName('div');
                if ($divs && $divs->length) {
                    $root = $divs->item(0);
                }
            }
            if (!$root) {
                return $dom->saveHTML();
            }
            $html = '';
            foreach ($root->childNodes as $child) {
                $html .= $dom->saveHTML($child);
            }

            return $html;
        } catch (Throwable $e) {
            return $dom->saveHTML();
        }
    }

    /**
     * 处理 Markdown 文本：仅在普通文本段落中进行关键词替换，保护代码块/内联代码/链接/图片/mermaid 等
     *
     * @param string $content
     * @param array $floLinks
     *
     * @return string
     */
    private static function processMarkdown(string $content, array $floLinks): string
    {
        if ($content === '') {
            return $content;
        }

        // 先对 Markdown 中已有的链接做联盟改写（rainyun 等）
        try {
            $enableAffiliateRewrite = blog_config('flolink_affiliate_rewrite', true, true);
        } catch (Throwable $e) {
            $enableAffiliateRewrite = true;
        }
        if ($enableAffiliateRewrite) {
            // 简单重写 [text](url) 形式的链接地址
            $content = preg_replace_callback('/\\[[^\\]]*\\]\\(([^)\s]+)\\)/u', function ($m) {
                $url = $m[1] ?? '';
                $new = self::rewriteAffiliateLink($url);
                if ($new === $url || $new === '') {
                    return $m[0];
                }

                // 替换原有 url，保持展示文本不变
                return str_replace('(' . $url . ')', '(' . self::escapeMarkdownUrl($new) . ')', $m[0]);
            }, $content) ?? $content;
        }

        if (!$floLinks) {
            return $content;
        }

        $placeholders = [];
        $seq = 0;
        $protect = function (string $pattern) use (&$content, &$placeholders, &$seq) {
            $content = preg_replace_callback($pattern, function ($m) use (&$placeholders, &$seq) {
                $key = '[[[FLOLINK_MD_' . (++$seq) . ']]]';
                $placeholders[$key] = $m[0];

                return $key;
            }, $content);
        };

        // 保护 fenced code blocks（```lang ... ```）与 ~~~ 块
        $protect('/```[^\n]*\n[\s\S]*?```/u');
        $protect('/~~~[^\n]*\n[\s\S]*?~~~/u');
        // 保护内联代码 `...`
        $protect('/`[^`]*`/u');
        // 保护链接和图片
        $protect('/!\[[^\]]*\]\([^\)\n]*\)/u');
        $protect('/\[[^\]]*\]\([^\)\n]*\)/u');

        // 执行关键词替换
        foreach ($floLinks as $floLink) {
            $keyword = (string) $floLink->keyword;
            $url = (string) $floLink->url;
            if ($keyword === '' || $url === '') {
                continue;
            }

            // 联盟改写（可配置）
            try {
                $enableAffiliateRewrite = blog_config('flolink_affiliate_rewrite', true);
            } catch (Throwable $e) {
                $enableAffiliateRewrite = true;
            }
            if ($enableAffiliateRewrite) {
                $url = self::rewriteAffiliateLink($url);
            }

            $escapedKeyword = preg_quote($keyword, '/');
            $flags = $floLink->case_sensitive ? 'u' : 'iu';
            // 使用捕获组，便于套入 HTML a 标签（包含 data- 属性等）
            $pattern = '/(' . $escapedKeyword . ')/' . $flags;

            $limit = ($floLink->match_mode === 'first') ? 1 : -1;

            // 直接输出 HTML 超链接（Markdown 允许内联 HTML），以便携带 data-flolink 属性用于悬浮效果
            $replacement = self::createLinkHTML($floLink); // 形如 <a ...>$1</a>

            $content = preg_replace($pattern, $replacement, $content, $limit);
        }

        // 还原占位片段
        if ($placeholders) {
            $content = strtr($content, $placeholders);
        }

        return $content;
    }

    private static function escapeMarkdownUrl(string $url): string
    {
        // 避免 markdown 链接中出现未转义的括号与空格
        $url = str_replace(['(', ')', ' '], ['%28', '%29', '%20'], $url);

        return $url;
    }

    /**
     * 智能替换已有链接
     * 如果文章中已经有带链接的关键词，根据配置决定是否替换
     *
     * @param string $content
     * @param FloLink $floLink
     *
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
     *
     * @return string
     */
    private static function createLinkHTML(FloLink $floLink): string
    {
        // 在生成链接前做联盟链接改写（可配置）
        $preparedUrl = (string) $floLink->url;
        try {
            $enableAffiliateRewrite = blog_config('flolink_affiliate_rewrite', true);
        } catch (Throwable $e) {
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
                $conf = blog_config('flolink_affiliate_suffix', 'github_', true);
                if (is_string($conf) && $conf !== '') {
                    $suffix = $conf;
                }
            } catch (Throwable $e) {
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
        } catch (Throwable $e) {
            return $url;
        }
    }

    /**
     * 遍历 DOM 中的链接并应用联盟改写
     */
    private static function rewriteSpecialLinksInDOM(DOMDocument $dom): void
    {
        try {
            $xpath = new DOMXPath($dom);
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
        } catch (Throwable $e) {
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
        } catch (Exception $e) {
            Log::warning('FloLink cache get failed: ' . $e->getMessage());
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
            } catch (Exception $e) {
                Log::warning('FloLink cache set failed: ' . $e->getMessage());
            }

            return $floLinks;
        } catch (Exception $e) {
            Log::error('Get active FloLinks failed: ' . $e->getMessage());

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
        } catch (Exception $e) {
            Log::warning('FloLink cache clear failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取单个FloLink的悬浮窗数据（用于AJAX请求）
     *
     * @param int $id
     *
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
        } catch (Exception $e) {
            Log::error('Get FloLink data failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 简单判断内容是否像 HTML 片段
     */
    private static function isHtmlLike(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        // 存在任意形如 <tag ...> 的标记则认为是 HTML
        return (bool) preg_match('/<\s*[a-zA-Z][a-zA-Z0-9-]*[^>]*>/', $content);
    }
}

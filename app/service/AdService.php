<?php

namespace app\service;

use app\model\Ad;
use support\Log;

class AdService
{
    /**
     * 将内容中的段落注入内嵌广告
     *
     * placements 建议配置：
     * - positions: ["inline"]
     * - interval: 每隔多少段插入，默认4
     * - start_after: 前多少段后开始，默认2
     * - max_inserts: 最多插入几次，默认2
     *
     * @param string $html    已渲染的文章HTML
     * @param array  $context 例如 ['slug' => 'post-slug']
     *
     * @return string
     */
    public static function injectInlineAds(string $html, array $context = []): string
    {
        if ($html === '') {
            return $html;
        }

        $ads = self::getActiveAdsByPosition('inline', null, $context);
        if (!$ads) {
            return $html;
        }

        // 使用第一个或轮换广告
        $snippets = [];
        foreach ($ads as $ad) {
            $code = self::renderAdHtml($ad, 'inline');
            if ($code) {
                $snippets[] = '<div class="inline-ad my-6">' . $code . '</div>';
            }
        }
        if (!$snippets) {
            return $html;
        }

        // 读取投放节奏
        $first = $ads[0];
        $placements = is_array($first['placements'] ?? null) ? $first['placements'] : [];
        $interval = max(1, (int) ($placements['interval'] ?? 4));
        $startAfter = max(0, (int) ($placements['start_after'] ?? 2));
        $maxInserts = max(1, (int) ($placements['max_inserts'] ?? 2));

        // 以 </p> 作为段落分隔点，保留分隔符
        $parts = preg_split('/(<\/p\s*>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts || count($parts) < 3) {
            // 段落太少，直接尾部插入一条
            return $html . $snippets[0];
        }

        $result = '';
        $pCount = 0; // 记录完整段落数
        $inserted = 0;
        $snippetIndex = 0;

        for ($i = 0; $i < count($parts); $i++) {
            $result .= $parts[$i];
            // 每次遇到 </p> 增加计数（因为我们保留了分隔符）
            if (preg_match('/^<\/p\s*>$/i', $parts[$i])) {
                $pCount++;
                if ($pCount > $startAfter && (($pCount - $startAfter) % $interval === 0)) {
                    if ($inserted < $maxInserts) {
                        $result .= $snippets[$snippetIndex % count($snippets)];
                        $snippetIndex++;
                        $inserted++;
                    }
                }
            }
        }

        // 如果未插入任何广告，兜底在末尾插入一条
        if ($inserted === 0) {
            $result .= $snippets[0];
        }

        return $result;
    }

    /**
     * 获取指定位置启用的广告
     *
     * @param string   $position 位置：sidebar|inline|top|bottom 等
     * @param int|null $limit    数量限制
     * @param array    $context  页面上下文，如 ['slug' => 'post-slug']
     *
     * @return array<array<string,mixed>>
     */
    public static function getActiveAdsByPosition(string $position, ?int $limit = null, array $context = []): array
    {
        try {
            $query = Ad::query()->where('enabled', true)
                ->orderByDesc('weight')
                ->orderByDesc('id');

            $ads = $query->get()->map(function (Ad $ad) {
                return $ad->toArray();
            })->all();

            $filtered = [];
            foreach ($ads as $ad) {
                $placements = is_array($ad['placements'] ?? null) ? $ad['placements'] : [];
                $positions = (array) ($placements['positions'] ?? []);
                if (empty($positions)) {
                    // 默认仅作为侧边栏小工具使用，避免误投放
                    $positions = ['sidebar'];
                }
                if (!in_array($position, $positions, true)) {
                    continue;
                }

                // include/exclude slugs
                $include = (array) ($placements['include_slugs'] ?? []);
                $exclude = (array) ($placements['exclude_slugs'] ?? []);
                $slug = (string) ($context['slug'] ?? '');
                if ($include && $slug && !in_array($slug, $include, true)) {
                    continue;
                }
                if ($exclude && $slug && in_array($slug, $exclude, true)) {
                    continue;
                }

                $filtered[] = $ad;
                if ($limit && count($filtered) >= $limit) {
                    break;
                }
            }

            return $filtered;
        } catch (\Throwable $e) {
            Log::error('[AdService] getActiveAdsByPosition failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * 渲染广告HTML片段
     *
     * @param array  $ad
     * @param string $placement
     *
     * @return string
     */
    public static function renderAdHtml(array $ad, string $placement = 'sidebar'): string
    {
        $type = $ad['type'] ?? 'image';
        switch ($type) {
            case 'google':
                $client = trim((string) ($ad['google_ad_client'] ?? ''));
                $slot = trim((string) ($ad['google_ad_slot'] ?? ''));
                if (!$client || !$slot) {
                    return '';
                }
                // 读取可视化配置（placements.google）
                $placements = is_array($ad['placements'] ?? null) ? $ad['placements'] : [];
                $g = is_array($placements['google'] ?? null) ? $placements['google'] : [];
                $format = (string) ($g['format'] ?? 'auto'); // auto|in-article|in-feed
                $full = (bool) ($g['full_width_responsive'] ?? true);
                $style = trim((string) ($g['style'] ?? 'display:block'));
                $layoutKey = trim((string) ($g['layout_key'] ?? ''));

                // 懒加载并避免重复加载 Adsense 脚本
                $s = '';
                $s .= '<div class="ad ad-google my-4" style="min-height: 100px; overflow: hidden;">';
                $s .= '<script>(function(){if(!window.__adsbygoogleLoaded){var s=document.createElement("script");s.async=true;s.src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . htmlspecialchars($client, ENT_QUOTES) . '";s.crossOrigin="anonymous";document.head.appendChild(s);window.__adsbygoogleLoaded=true;}})();</script>';
                $ins = '<ins class="adsbygoogle" style="' . htmlspecialchars($style, ENT_QUOTES) . '" data-ad-client="' . htmlspecialchars($client, ENT_QUOTES) . '" data-ad-slot="' . htmlspecialchars($slot, ENT_QUOTES) . '"';
                if ($format === 'in-article') {
                    $ins .= ' data-ad-format="fluid" data-ad-layout="in-article"';
                } elseif ($format === 'in-feed') {
                    $ins .= ' data-ad-format="fluid"';
                    if ($layoutKey !== '') {
                        $ins .= ' data-ad-layout-key="' . htmlspecialchars($layoutKey, ENT_QUOTES) . '"';
                    }
                } else {
                    $ins .= ' data-ad-format="auto"';
                }
                if ($full) {
                    $ins .= ' data-full-width-responsive="true"';
                }
                $ins .= '></ins>';
                $s .= $ins;
                // 延迟执行 push() 直到容器有宽度，并检测广告加载状态
                $s .= '<script>';
                $s .= '(function(){';
                $s .= 'var ins=document.currentScript.previousElementSibling;';
                $s .= 'var container=ins.parentElement;';
                $s .= 'var attempts=0;';
                $s .= 'var maxAttempts=50;';  // 最多尝试 5 秒
                $s .= 'var pushed=false;';
                $s .= 'function tryPush(){';
                $s .= 'attempts++;';
                // 检查容器是否可见且有宽度
                $s .= 'if(ins&&ins.offsetWidth>0&&ins.offsetParent!==null){';
                $s .= 'try{';
                $s .= '(adsbygoogle=window.adsbygoogle||[]).push({});';
                $s .= 'pushed=true;';
                // 监听广告加载状态
                $s .= 'setTimeout(function(){';
                $s .= 'if(ins.innerHTML.trim()===""||ins.offsetHeight<50){';
                $s .= 'container.style.display="none";';
                $s .= 'console.warn("AdSense: No ad content, hiding container");';
                $s .= '}';
                $s .= '},2000);';
                $s .= '}catch(e){';
                $s .= 'console.error("AdSense push error:",e);';
                $s .= 'container.style.display="none";';
                $s .= '}';
                $s .= 'return;';
                $s .= '}';
                // 如果超过最大尝试次数，隐藏容器
                $s .= 'if(attempts>=maxAttempts){';
                $s .= 'console.warn("AdSense: Container not ready after "+attempts+" attempts");';
                $s .= 'if(!pushed){container.style.display="none";}';
                $s .= 'return;';
                $s .= '}';
                $s .= 'setTimeout(tryPush,100);';
                $s .= '}';
                // 根据页面加载状态决定何时开始尝试
                $s .= 'if(document.readyState==="complete"){';
                $s .= 'setTimeout(tryPush,100);';
                $s .= '}else{';
                $s .= 'window.addEventListener("load",function(){setTimeout(tryPush,100);});';
                $s .= '}';
                // PJAX 支持
                $s .= 'document.addEventListener("pjax:complete",function(){setTimeout(tryPush,100);});';
                $s .= '})();';
                $s .= '</script>';
                $s .= '</div>';

                return $s;
            case 'html':
                return '<div class="ad ad-html my-4">' . (string) ($ad['html'] ?? '') . '</div>';
            case 'image':
            default:
                $img = '<img src="' . htmlspecialchars((string) ($ad['image_url'] ?? ''), ENT_QUOTES) . '" alt="' . htmlspecialchars((string) ($ad['title'] ?? 'ad'), ENT_QUOTES) . '" class="w-full h-auto rounded" loading="lazy" />';
                $href = trim((string) ($ad['link_url'] ?? ''));
                if ($href) {
                    $target = htmlspecialchars((string) ($ad['link_target'] ?? '_blank'), ENT_QUOTES);

                    return '<div class="ad ad-image my-4"><a href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="' . $target . '" rel="noopener noreferrer">' . $img . '</a></div>';
                }

                return '<div class="ad ad-image my-4">' . $img . '</div>';
        }
    }
}

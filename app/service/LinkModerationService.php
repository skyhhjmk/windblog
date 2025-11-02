<?php

declare(strict_types=1);

namespace app\service;

use app\model\Link;
use support\Log;
use Throwable;

/**
 * 友链AI审核服务
 * 用于检测友链质量、反链情况、网站内容等
 */
class LinkModerationService
{
    /**
     * 批量审核友链（用于后台批量处理）
     *
     * @param array $links 友链列表
     *
     * @return array 审核结果列表
     */
    public static function moderateLinks(array $links): array
    {
        $results = [];

        foreach ($links as $link) {
            $linkId = $link['id'] ?? null;
            $url = $link['url'] ?? '';
            $name = $link['name'] ?? '';
            $description = $link['description'] ?? '';
            $email = $link['email'] ?? '';

            $result = self::moderateLink(
                $url,
                $name,
                $description,
                $email
            );

            $results[$linkId] = $result;
        }

        return $results;
    }

    /**
     * 审核友链
     *
     * @param string $url         友链URL
     * @param string $name        友链名称
     * @param string $description 友链描述
     * @param string $email       友链邮箱
     *
     * @return array{
     *     passed: bool,
     *     result: string,
     *     reason: string,
     *     confidence: float,
     *     score: float,
     *     categories: array
     * }
     */
    public static function moderateLink(
        string $url,
        string $name = '',
        string $description = '',
        string $email = ''
    ): array {
        // 检查是否启用AI审核
        $aiModerationEnabled = blog_config('link_ai_moderation_enabled', false, true);

        if (!$aiModerationEnabled) {
            Log::debug('AI link moderation is disabled');

            return [
                'passed' => true,
                'result' => 'approved',
                'reason' => 'AI审核未启用',
                'confidence' => 1.0,
                'score' => 0.0,
                'categories' => [],
            ];
        }

        try {
            // 获取AI提供者
            $provider = AISummaryService::getCurrentProvider();

            if (!$provider) {
                Log::warning('No AI provider available for link moderation');

                return self::getFallbackResult(true, 'AI服务不可用，使用默认审核');
            }

            // 检查提供者是否支持审核任务
            if (!in_array('moderate_link', $provider->getSupportedTasks(), true)) {
                Log::warning("AI provider does not support 'moderate_link' task");

                return self::getFallbackResult(true, 'AI提供者不支持友链审核');
            }

            // 抓取友链网站内容
            $fetch = self::fetchWebContent($url);
            if (!$fetch['success']) {
                return self::getFallbackResult(false, '无法访问目标网站: ' . $fetch['error']);
            }

            $html = $fetch['html'];
            $myDomain = blog_config('site_url', '', true);

            // 检查反链
            $backlink = self::checkBacklink($html, $myDomain, $url);

            // 构建审核参数
            $params = [
                'url' => $url,
                'name' => $name,
                'description' => $description,
                'email' => $email,
                'backlink_found' => $backlink['found'] ?? false,
                'backlink_count' => $backlink['link_count'] ?? 0,
                'html_snippet' => mb_substr(strip_tags($html), 0, 500),
            ];

            // 获取审核选项
            $options = [
                'temperature' => (float) blog_config('link_ai_moderation_temperature', 0.1, true),
                'model' => blog_config('link_ai_moderation_model', '', true) ?: $provider->getDefaultModel(),
            ];

            // 获取提示词模板
            $template = (string) blog_config('link_ai_moderation_prompt', '', true);
            if (trim($template) === '') {
                $template = self::getDefaultPrompt();
            }

            $promptText = strtr($template, [
                '{url}' => $url,
                '{name}' => $name,
                '{description}' => $description,
                '{email}' => $email,
                '{backlink_found}' => $backlink['found'] ? '是' : '否',
                '{backlink_count}' => (string) ($backlink['link_count'] ?? 0),
                '{html_snippet}' => mb_substr(strip_tags($html), 0, 500),
                '{my_domain}' => $myDomain,
            ]);

            $params['messages'] = [
                ['role' => 'user', 'content' => $promptText],
            ];

            // 调用AI进行审核
            $result = $provider->call('moderate_link', $params, $options);

            if (!$result['ok']) {
                Log::error('AI link moderation failed: ' . ($result['error'] ?? 'Unknown error'));

                return self::getFallbackResult(true, 'AI审核失败: ' . ($result['error'] ?? 'Unknown error'));
            }

            // 解析AI返回结果
            $moderationResult = $result['result'] ?? [];

            return [
                'passed' => $moderationResult['passed'] ?? true,
                'result' => $moderationResult['result'] ?? 'approved',
                'reason' => $moderationResult['reason'] ?? '',
                'confidence' => $moderationResult['confidence'] ?? 1.0,
                'score' => $moderationResult['score'] ?? 0.0,
                'categories' => $moderationResult['categories'] ?? [],
            ];

        } catch (Throwable $e) {
            Log::error('Link moderation exception: ' . $e->getMessage());

            // 获取失败策略
            $failureStrategy = blog_config('link_ai_moderation_failure_strategy', 'approve', true);
            $passed = ($failureStrategy === 'approve');

            return self::getFallbackResult($passed, 'AI审核异常: ' . $e->getMessage());
        }
    }

    /**
     * 获取回退结果
     */
    private static function getFallbackResult(bool $passed, string $reason): array
    {
        return [
            'passed' => $passed,
            'result' => $passed ? 'approved' : 'pending',
            'reason' => $reason,
            'confidence' => 0.0,
            'score' => 0.0,
            'categories' => [],
        ];
    }

    /**
     * 抓取网站内容
     */
    private static function fetchWebContent(string $url): array
    {
        try {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return ['success' => false, 'error' => '无效URL'];
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => 'WindBlog LinkAudit/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $html = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                return ['success' => false, 'error' => $err];
            }
            if ($code >= 400) {
                return ['success' => false, 'error' => 'HTTP错误: ' . $code];
            }

            return ['success' => true, 'html' => $html ?: ''];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 检查反链
     */
    private static function checkBacklink(string $html, string $myDomain, string $targetUrl): array
    {
        $result = ['found' => false, 'link_count' => 0];
        $clean = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $myDomain);
        $clean = rtrim($clean, '/');

        if ($clean === '') {
            return $result;
        }

        if (stripos($html, $clean) !== false) {
            $result['domain_mentioned'] = true;
        }

        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                if (stripos($href, $clean) !== false) {
                    $result['found'] = true;
                    $result['link_count']++;
                }
            }
        }

        return $result;
    }

    /**
     * 获取默认提示词模板
     */
    private static function getDefaultPrompt(): string
    {
        return <<<EOT
请审核以下友情链接，并仅以JSON返回结果：
{
  "passed": true/false,
  "result": "approved/rejected/spam",
  "reason": "审核理由",
  "confidence": 0.0-1.0,
  "score": 0-100,
  "categories": ["问题类别，如 spam, low_quality, no_backlink 等"]
}

审核要点：
1. 反链检查：对方网站是否包含指向本站({my_domain})的链接
2. 网站质量：网站内容质量、是否正常访问、是否涉及违规内容
3. 相关性：网站内容与本站是否有一定相关性
4. 信誉度：根据网站描述、邮箱等判断可信度

评分规则（score）：
- 找到反链：基础分40分
- 反链数量：每个额外+5分（最多20分）
- 内容质量高：+20分
- 相关性强：+20分
- 总分最高100分

置信度（confidence）说明：
- 表示对本次审核结论的把握程度
- 0表示完全不确定，1表示完全确定

友链信息：
URL：{url}
名称：{name}
描述：{description}
邮箱：{email}
反链状态：{backlink_found}（数量：{backlink_count}）
网站内容片段：{html_snippet}

仅输出上述JSON，不要包含多余文本或注释。
EOT;
    }

    /**
     * 获取审核统计信息
     *
     * @param int $days 统计天数
     *
     * @return array{
     *     total: int,
     *     approved: int,
     *     rejected: int,
     *     spam: int,
     *     rate: float
     * }
     */
    public static function getModerationStats(int $days = 7): array
    {
        try {
            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            // 统计有AI审核记录的友链
            $total = Link::withTrashed()
                ->where('updated_at', '>=', $startDate)
                ->whereRaw("JSON_EXTRACT(custom_fields, '$.ai_audit_status') IS NOT NULL")
                ->count();

            $approved = Link::withTrashed()
                ->where('updated_at', '>=', $startDate)
                ->whereRaw("JSON_EXTRACT(custom_fields, '$.ai_audit_status') = 'approved'")
                ->count();

            $rejected = Link::withTrashed()
                ->where('updated_at', '>=', $startDate)
                ->whereRaw("JSON_EXTRACT(custom_fields, '$.ai_audit_status') = 'rejected'")
                ->count();

            $spam = Link::withTrashed()
                ->where('updated_at', '>=', $startDate)
                ->whereRaw("JSON_EXTRACT(custom_fields, '$.ai_audit_status') = 'spam'")
                ->count();

            $rate = $total > 0 ? round(($approved / $total) * 100, 2) : 0;

            return [
                'total' => $total,
                'approved' => $approved,
                'rejected' => $rejected,
                'spam' => $spam,
                'rate' => $rate,
            ];
        } catch (Throwable $e) {
            Log::error('Failed to get link moderation stats: ' . $e->getMessage());

            return [
                'total' => 0,
                'approved' => 0,
                'rejected' => 0,
                'spam' => 0,
                'rate' => 0.0,
            ];
        }
    }
}

<?php

declare(strict_types=1);

namespace app\process;

use app\model\Link;
use app\service\AISummaryService;
use app\service\MQService;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use support\Log;
use Throwable;
use Workerman\Timer;

/**
 * 友链AI审核工作进程
 * - 消费友链审核队列，执行AI审核任务
 * - 注意：手动触发的审核不会修改友链的展示状态
 */
class LinkAuditWorker
{
    /** @var AMQPChannel|null */
    protected $mqChannel = null;

    protected string $exchange = 'link_audit_exchange';

    protected string $routingKey = 'link_audit_moderate';

    protected string $queueName = 'link_audit_queue';

    protected string $dlxExchange = 'link_audit_dlx_exchange';

    protected string $dlq = 'link_audit_dlx_queue';

    public function onWorkerStart(): void
    {
        if (!is_installed()) {
            Log::warning('LinkAuditWorker: system not installed, skip');

            return;
        }

        $this->initMq();
        $this->startConsumer();

        if (class_exists(Timer::class)) {
            Timer::add(60, function () {
                try {
                    MQService::checkAndHeal();
                } catch (Throwable $e) {
                    Log::warning('MQ health check (Link audit): ' . $e->getMessage());
                }
            });
        }
    }

    protected function initMq(): void
    {
        try {
            $this->mqChannel = MQService::getChannel();

            $this->exchange = (string) blog_config('rabbitmq_link_audit_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey = (string) blog_config('rabbitmq_link_audit_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName = (string) blog_config('rabbitmq_link_audit_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange = (string) blog_config('rabbitmq_link_audit_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->dlq = (string) blog_config('rabbitmq_link_audit_dlx_queue', $this->dlq, true) ?: $this->dlq;

            MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->dlq);
            MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->dlq);
        } catch (Throwable $e) {
            Log::error('LinkAuditWorker MQ init failed: ' . $e->getMessage());
        }
    }

    protected function startConsumer(): void
    {
        if (!$this->mqChannel) {
            return;
        }
        $this->mqChannel->basic_qos(0, 1, null);
        $this->mqChannel->basic_consume($this->queueName, '', false, false, false, false, function (AMQPMessage $message) {
            $this->handleMessage($message);
        });

        if (class_exists(Timer::class)) {
            Timer::add(1, function () {
                try {
                    if ($this->mqChannel === null) {
                        Log::warning('Link audit: channel is null, reconnecting...');
                        $this->reconnectMq();

                        return;
                    }
                    $this->mqChannel->wait(null, false, 1.0);
                } catch (AMQPTimeoutException $e) {
                    // noop
                } catch (Throwable $e) {
                    $errorMsg = $e->getMessage();
                    Log::warning('Link audit wait: ' . $errorMsg);

                    // 检测通道连接断开，触发自愈
                    if (strpos($errorMsg, 'Channel connection is closed') !== false ||
                        strpos($errorMsg, 'Broken pipe') !== false ||
                        strpos($errorMsg, 'connection is closed') !== false ||
                        strpos($errorMsg, 'on null') !== false) {
                        Log::warning('Link audit 检测到连接断开，尝试重建连接');
                        $this->reconnectMq();
                    }
                }
            });
        }
    }

    protected function handleMessage(AMQPMessage $message): void
    {
        try {
            $data = json_decode($message->getBody(), true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid payload');
            }

            $this->handleAuditTask($data, $message);
        } catch (Throwable $e) {
            Log::error('Link audit handle failed: ' . $e->getMessage());
            // 失败后直接 ACK，不重试，避免性能消耗
            $message->ack();
        }
    }

    protected function handleAuditTask(array $data, AMQPMessage $message): void
    {
        $linkId = (int) ($data['link_id'] ?? 0);
        if ($linkId <= 0) {
            throw new RuntimeException('Missing link_id');
        }

        $link = Link::withTrashed()->find($linkId);
        if (!$link) {
            // 友链已不存在，直接 ACK
            $message->ack();

            return;
        }

        // 判断是否为手动触发（手动触发不修改展示状态）
        $isManual = (bool) ($data['manual'] ?? false);

        // 构建提示词
        $template = (string) blog_config('link_ai_moderation_prompt', '', true);
        if (trim($template) === '') {
            $template = $this->getDefaultPrompt();
        }

        // 抓取友链网站内容
        $fetch = $this->fetchWebContent($link->url);
        if (!$fetch['success']) {
            // 无法访问，记录失败原因
            $link->setCustomField('ai_audit_status', 'error');
            $link->setCustomField('ai_audit_reason', '无法访问: ' . $fetch['error']);
            $link->setCustomField('last_audit_time', utc_now_string('Y-m-d H:i:s'));
            $link->save();
            $message->ack();

            return;
        }

        $html = $fetch['html'];
        $myDomain = blog_config('site_url', '', true);

        // 检查反链：首页检测
        $backlink = $this->checkBacklink($html, $myDomain);

        // 如果有友链页面且不是首页，需要额外检测友链页面
        $linkPosition = $link->getCustomField('link_position', '');
        $pageLink = $link->getCustomField('page_link', '');

        if (!empty($pageLink) && $linkPosition !== 'homepage') {
            try {
                $pageFetch = $this->fetchWebContent($pageLink);
                if ($pageFetch['success']) {
                    $pageBacklink = $this->checkBacklink($pageFetch['html'], $myDomain);

                    // 合并反链结果：只要其中一个页面找到反链就认为找到了
                    if ($pageBacklink['found'] ?? false) {
                        $backlink['found'] = true;
                        $backlink['link_count'] = ($backlink['link_count'] ?? 0) + ($pageBacklink['link_count'] ?? 0);
                    }

                    Log::debug('Link audit checked page_link', [
                        'link_id' => $linkId,
                        'page_link' => $pageLink,
                        'homepage_found' => $backlink['found'] ?? false,
                        'pagepage_found' => $pageBacklink['found'] ?? false,
                        'total_count' => $backlink['link_count'] ?? 0,
                    ]);
                } else {
                    Log::warning('Link audit: failed to fetch page_link', [
                        'link_id' => $linkId,
                        'page_link' => $pageLink,
                        'error' => $pageFetch['error'] ?? 'unknown',
                    ]);
                }
            } catch (Throwable $e) {
                Log::error('Link audit: exception when checking page_link', [
                    'link_id' => $linkId,
                    'page_link' => $pageLink,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 清理并格式化 HTML，提取关键内容
        $cleanedHtml = $this->cleanHtml($html);

        $backlinkSummary = sprintf('反链：%s（数量：%d）', ($backlink['found'] ?? false) ? '是' : '否', (int) ($backlink['link_count'] ?? 0));
        $promptText = strtr($template, [
            '{url}' => (string) $link->url,
            '{name}' => (string) $link->name,
            '{description}' => (string) $link->description,
            '{email}' => (string) $link->email,
            '{backlink_found}' => $backlink['found'] ? '是' : '否',
            '{backlink_count}' => (string) ($backlink['link_count'] ?? 0),
            '{html_snippet}' => mb_substr($cleanedHtml, 0, 2000),
            '{html_content}' => $cleanedHtml,
            '{my_domain}' => $myDomain,
            // 兼容旧占位符
            '{backlink_info}' => $backlinkSummary,
            '{page_summary}' => mb_substr($cleanedHtml, 0, 2000),
            '{created_at}' => $link->created_at ? $link->created_at->format('Y-m-d H:i:s') : '',
        ]);

        $params = [
            'url' => (string) $link->url,
            'name' => (string) $link->name,
            'description' => (string) $link->description,
            'email' => (string) $link->email,
            'backlink_found' => $backlink['found'] ?? false,
            'backlink_count' => $backlink['link_count'] ?? 0,
            'html_snippet' => mb_substr($this->cleanHtml($html), 0, 2000),
            'messages' => [
                ['role' => 'user', 'content' => $promptText],
            ],
        ];

        $options = [
            'temperature' => (float) blog_config('link_ai_moderation_temperature', 0.1, true),
        ];

        // 选择AI提供者
        $provider = AISummaryService::getCurrentProvider();
        if (!$provider) {
            throw new RuntimeException('No AI provider available');
        }

        // 记录发送给 AI 的上下文信息
        Log::debug('Link audit AI request context', [
            'link_id' => $linkId,
            'link_url' => $link->url,
            'link_name' => $link->name,
            'backlink_found' => $backlink['found'] ?? false,
            'backlink_count' => $backlink['link_count'] ?? 0,
            'prompt_length' => mb_strlen($promptText),
            'html_snippet_length' => mb_strlen($params['html_snippet'] ?? ''),
            'temperature' => $options['temperature'] ?? null,
            'provider' => get_class($provider),
        ]);

        // 使用通用 chat 任务类型而非特定的 moderate_link
        $result = $provider->call('chat', $params, $options);
        if (!($result['ok'] ?? false)) {
            throw new RuntimeException('AI link audit failed: ' . ($result['error'] ?? 'unknown'));
        }

        // 注意：result['result'] 是 AI 返回的文本内容，需要解析为 JSON
        $resultText = $result['result'] ?? '';
        if (!is_string($resultText)) {
            Log::error('Link audit AI result is not string', [
                'result_type' => gettype($resultText),
                'result' => $resultText,
            ]);
            throw new RuntimeException('AI link audit result is not string');
        }

        // 从 AI 返回的文本中提取 JSON
        $resultData = $this->tryParseJsonFromText($resultText);

        Log::debug('Link audit parsing step', [
            'result_text_type' => gettype($resultText),
            'result_text_length' => strlen($resultText),
            'result_text_preview' => mb_substr($resultText, 0, 200),
            'parsed_data_type' => gettype($resultData),
            'is_array' => is_array($resultData),
            'is_empty' => empty($resultData),
        ]);

        if (!is_array($resultData) || empty($resultData)) {
            Log::error('Link audit AI result parsing failed', [
                'result_text' => $resultText,
                'parsed_data' => $resultData,
            ]);
            throw new RuntimeException('AI link audit result parsing failed');
        }

        // 记录 AI 返回的原始数据
        Log::debug('Link audit AI raw response', [
            'link_id' => $linkId,
            'result_data' => $resultData,
            'result_data_keys' => array_keys($resultData),
        ]);

        // 校验规则
        Log::debug('Before validateAuditResult', [
            'result_data_type' => gettype($resultData),
            'result_data' => $resultData,
        ]);

        try {
            $validated = $this->validateAuditResult($resultData);
        } catch (\Throwable $e) {
            Log::error('validateAuditResult threw exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'result_data' => $resultData,
            ]);
            throw $e;
        }

        if ($validated === null) {
            Log::error('Link audit result validation failed', [
                'link_id' => $linkId,
                'result_data' => $resultData,
                'missing_or_invalid_fields' => $this->getDiagnosticInfo($resultData),
            ]);
            throw new RuntimeException('AI link audit result not match schema');
        }

        Log::debug('After validateAuditResult', [
            'validated_type' => gettype($validated),
            'validated_is_array' => is_array($validated),
            'validated' => $validated,
        ]);

        // 确保 $validated 是数组
        if (!is_array($validated)) {
            Log::error('$validated is not an array!', [
                'validated_type' => gettype($validated),
                'validated' => $validated,
            ]);
            throw new RuntimeException('Validated result is not an array');
        }

        // 更新友链审核信息到 custom_fields
        $link->setCustomField('ai_audit_status', $validated['result']);
        $link->setCustomField('ai_audit_reason', $validated['reason']);
        $link->setCustomField('ai_audit_confidence', $validated['confidence']);
        $link->setCustomField('ai_audit_score', $validated['score']);
        $link->setCustomField('last_audit_time', utc_now_string('Y-m-d H:i:s'));

        if (!empty($validated['categories'])) {
            $link->setCustomField('ai_audit_categories', $validated['categories']);
        }

        // 只有非手动触发的审核才自动更新友链状态
        if (!$isManual) {
            if (!$validated['passed']) {
                // AI 审核不通过，设置为隐藏状态
                $link->status = false;
                $link->setCustomField('ai_auto_hide', true);
            } else {
                // AI 审核通过
                $autoApprove = (bool) blog_config('link_ai_auto_approve_on_pass', false, true);
                $minConf = (float) blog_config('link_ai_auto_approve_min_confidence', 0.85, true);
                $minScore = (int) blog_config('link_ai_auto_approve_min_score', 60, true);

                if ($autoApprove && ($validated['confidence'] >= $minConf) && ($validated['score'] >= $minScore)) {
                    // 自动通过
                    $link->status = true;
                    $link->setCustomField('ai_auto_approved', true);

                    // 根据评分设置排序
                    if ((int) $link->sort_order === 999) {
                        $link->sort_order = max(1, 1000 - (int) $validated['score']);
                    }
                }
            }
        }

        $link->save();

        $message->ack();

        Log::info('Link audit completed: ' . $linkId . ', result: ' . $validated['result'] . ', score: ' . $validated['score']);
    }

    private function getDefaultPrompt(): string
    {
        return <<<EOT
请审核以下友情链接，并仅以JSON返回结果：
{
  "passed": true/false,
  "result": "approved/rejected/spam",
  "reason": "审核理由",
  "confidence": 0.0-1.0,
  "score": 0-100,
  "categories": ["问题类别，如 spam, low_quality, no_backlink, illegal_content, adult_content 等"]
}

审核要点：
1. 反链检查：对方网站是否包含指向本站({my_domain})的链接
2. 网站质量：网站内容质量、是否正常访问
3. **违规内容检测**：仔细分析网站内容，检查是否包含色情、赌博、暴力、欺诈等违法违规内容
4. 内容合规性：是否涉及政治敏感、低俗、广告欺诈等不适内容
5. 相关性：网站内容与本站是否有一定相关性
6. 信誉度：根据网站描述、邮箱等判断可信度

违规内容判定标准：
- 色情内容：包含成人、色情、性暗示等内容 → 直接拒绝（categories: adult_content）
- 赌博博彩：涉及赌博、彩票、博彩等内容 → 直接拒绝（categories: gambling）
- 违法内容：诈骗、钓鱼、恶意软件、非法交易等 → 直接拒绝（categories: illegal_content）
- 低质量内容：垃圾广告、采集站、无实质内容 → 拒绝（categories: spam, low_quality）

评分规则（score）：
- **存在违规内容：直接0分**
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

网站内容（已清理格式，请仔细分析以下内容判断是否违规）：
{html_snippet}

仅输出上述JSON格式，不要包含markdown代码块标记、多余文本或注释。
EOT;
    }

    /**
     * 抓取网站内容
     */
    private function fetchWebContent(string $url): array
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
    private function checkBacklink(string $html, string $myDomain): array
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
     * 从包含JSON的文本中提取对象
     */
    private function tryParseJsonFromText(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $json = substr($text, $start, $end - $start + 1);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * 校验AI返回是否符合规则
     *
     * @param array $data
     *
     * @return array|null {passed:bool,result:string,reason:string,confidence:float,score:float,categories:array}
     */
    private function validateAuditResult(array $data): ?array
    {
        // 首先确保 $data 确实是数组
        if (!is_array($data)) {
            Log::error('validateAuditResult: $data is not an array', [
                'data_type' => gettype($data),
                'data' => $data,
            ]);

            return null;
        }

        // 如果缺少 result 字段，尝试从 passed 推断
        if (!isset($data['result']) || trim((string) $data['result']) === '') {
            $passed = $data['passed'] ?? null;
            if ($passed === true) {
                $data['result'] = 'approved';
            } elseif ($passed === false) {
                $data['result'] = 'rejected';
            } else {
                return null;
            }
        }

        $result = strtolower((string) ($data['result'] ?? ''));
        $allowed = ['approved', 'rejected', 'spam', 'pending'];
        if (!in_array($result, $allowed, true)) {
            return null;
        }
        $passed = (bool) ($data['passed'] ?? ($result === 'approved'));
        $reason = (string) ($data['reason'] ?? '');
        if ($reason === '') {
            $reason = 'OK';
        }
        $confidence = (float) ($data['confidence'] ?? 1.0);
        if ($confidence < 0.0) {
            $confidence = 0.0;
        } elseif ($confidence > 1.0) {
            $confidence = 1.0;
        }
        $score = (float) ($data['score'] ?? 0.0);
        if ($score < 0.0) {
            $score = 0.0;
        } elseif ($score > 100.0) {
            $score = 100.0;
        }
        $categories = $data['categories'] ?? [];
        if (!is_array($categories)) {
            $categories = [];
        } else {
            $categories = array_values(array_filter(array_map('strval', $categories), function ($s) {
                return $s !== '';
            }));
        }

        $returnValue = [
            'passed' => $passed,
            'result' => $result,
            'reason' => $reason,
            'confidence' => $confidence,
            'score' => $score,
            'categories' => $categories,
        ];

        Log::debug('validateAuditResult returning', [
            'return_value' => $returnValue,
            'return_type' => gettype($returnValue),
        ]);

        return $returnValue;
    }

    /**
     * 获取诊断信息，用于调试
     */
    private function getDiagnosticInfo(array $data): array
    {
        $diagnostic = [];
        $requiredFields = ['result', 'passed'];
        $optionalFields = ['reason', 'confidence', 'score', 'categories'];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $diagnostic[$field] = 'missing';
            } elseif ($field === 'result') {
                $value = strtolower((string) $data[$field]);
                $allowed = ['approved', 'rejected', 'spam', 'pending'];
                if (!in_array($value, $allowed, true)) {
                    $diagnostic[$field] = "invalid value: {$value}, expected: " . implode('/', $allowed);
                }
            }
        }

        foreach ($optionalFields as $field) {
            if (!isset($data[$field])) {
                $diagnostic[$field] = 'missing (optional)';
            }
        }

        return $diagnostic;
    }

    /**
     * 清理 HTML，提取页面关键内容
     * 删除 script、style、注释等无用标签，保留文本内容
     */
    private function cleanHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // 删除 script 标签及内容
        $html = preg_replace('/<script[^>]*?>.*?<\/script>/is', '', $html);

        // 删除 style 标签及内容
        $html = preg_replace('/<style[^>]*?>.*?<\/style>/is', '', $html);

        // 删除 HTML 注释
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // 删除 iframe、embed、object 等嵌入标签
        $html = preg_replace('/<(iframe|embed|object|noscript)[^>]*?>.*?<\/\1>/is', '', $html);

        // 提取 title 标签内容（保留用于分析）
        $title = '';
        if (preg_match('/<title[^>]*?>(.*?)<\/title>/is', $html, $matches)) {
            $title = 'Title: ' . trim(strip_tags($matches[1])) . "\n\n";
        }

        // 提取 meta description（保留用于分析）
        $description = '';
        if (preg_match('/<meta[^>]*?name=["\']description["\'][^>]*?content=["\']([^"\']*)["\'][^>]*?>/i', $html, $matches)) {
            $description = 'Description: ' . trim($matches[1]) . "\n\n";
        }

        // 提取 meta keywords（保留用于分析）
        $keywords = '';
        if (preg_match('/<meta[^>]*?name=["\']keywords["\'][^>]*?content=["\']([^"\']*)["\'][^>]*?>/i', $html, $matches)) {
            $keywords = 'Keywords: ' . trim($matches[1]) . "\n\n";
        }

        // 删除所有 HTML 标签
        $text = strip_tags($html);

        // 解码 HTML 实体
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 删除多余空白字符
        $text = preg_replace('/\s+/', ' ', $text);

        // 组合结果：meta信息 + 正文内容
        $cleaned = $title . $description . $keywords . trim($text);

        // 限制总长度，避免发送过大的内容给 AI
        return mb_substr($cleaned, 0, 3000);
    }

    /**
     * 重建 MQ 连接（自愈机制）
     */
    protected function reconnectMq(): void
    {
        try {
            $this->mqChannel = null;

            // 等待短暂时间后重建
            usleep(500000); // 0.5秒

            // 重新初始化 MQ 并启动消费者
            $this->initMq();
            $this->startConsumer();

            Log::info('LinkAuditWorker MQ连接重建成功');
        } catch (Throwable $e) {
            Log::error('LinkAuditWorker MQ连接重建失败: ' . $e->getMessage());
            $this->mqChannel = null;
        }
    }
}

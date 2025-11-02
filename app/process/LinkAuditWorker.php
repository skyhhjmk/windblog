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
                    $this->mqChannel?->wait(null, false, 1.0);
                } catch (AMQPTimeoutException $e) {
                    // noop
                } catch (Throwable $e) {
                    Log::warning('Link audit wait: ' . $e->getMessage());
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

            $taskType = (string) ($data['task_type'] ?? 'moderate_link');
            if ($taskType !== 'moderate_link') {
                // 忽略非审核任务
                $message->ack();

                return;
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

        // 检查反链
        $backlink = $this->checkBacklink($html, $myDomain);

        $promptText = strtr($template, [
            '{url}' => (string) $link->url,
            '{name}' => (string) $link->name,
            '{description}' => (string) $link->description,
            '{email}' => (string) $link->email,
            '{backlink_found}' => $backlink['found'] ? '是' : '否',
            '{backlink_count}' => (string) ($backlink['link_count'] ?? 0),
            '{html_snippet}' => mb_substr(strip_tags($html), 0, 500),
            '{my_domain}' => $myDomain,
        ]);

        $params = [
            'url' => (string) $link->url,
            'name' => (string) $link->name,
            'description' => (string) $link->description,
            'email' => (string) $link->email,
            'backlink_found' => $backlink['found'] ?? false,
            'backlink_count' => $backlink['link_count'] ?? 0,
            'html_snippet' => mb_substr(strip_tags($html), 0, 500),
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

        $resultData = $result['result'] ?? [];
        if (is_string($resultData)) {
            // 兼容文本：提取 JSON
            $resultData = $this->tryParseJsonFromText($resultData);
        }
        if (!is_array($resultData)) {
            Log::error('Link audit AI result parsing failed', [
                'result_type' => gettype($resultData),
                'raw_result' => is_scalar($resultData) ? $resultData : json_encode($resultData),
            ]);
            throw new RuntimeException('AI link audit result is not array');
        }

        // 记录 AI 返回的原始数据
        Log::debug('Link audit AI raw response', [
            'link_id' => $linkId,
            'result_data' => $resultData,
        ]);

        // 校验规则
        $validated = $this->validateAuditResult($resultData);
        if ($validated === null) {
            Log::error('Link audit result validation failed', [
                'link_id' => $linkId,
                'result_data' => $resultData,
                'missing_or_invalid_fields' => $this->getDiagnosticInfo($resultData),
            ]);
            throw new RuntimeException('AI link audit result not match schema');
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

        return [
            'passed' => $passed,
            'result' => $result,
            'reason' => $reason,
            'confidence' => $confidence,
            'score' => $score,
            'categories' => $categories,
        ];
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
}

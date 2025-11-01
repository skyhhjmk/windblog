<?php

declare(strict_types=1);

namespace app\process;

use app\model\Comment;
use app\service\AISummaryService;
use app\service\MQService;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use support\Log;
use Throwable;
use Workerman\Timer;

/**
 * AI 评论审核工作进程（非阻塞）
 * - 参考 AiSummaryWorker 设计
 */
class AiModerationWorker
{
    /** @var AMQPChannel|null */
    protected $mqChannel = null;

    protected string $exchange = 'ai_moderation_exchange';

    protected string $routingKey = 'ai_moderation_moderate';

    protected string $queueName = 'ai_moderation_queue';

    protected string $dlxExchange = 'ai_moderation_dlx_exchange';

    protected string $dlq = 'ai_moderation_dlx_queue';

    public function onWorkerStart(): void
    {
        if (!is_installed()) {
            Log::warning('AiModerationWorker: system not installed, skip');

            return;
        }

        $this->initMq();
        $this->startConsumer();

        if (class_exists(Timer::class)) {
            Timer::add(60, function () {
                try {
                    MQService::checkAndHeal();
                } catch (Throwable $e) {
                    Log::warning('MQ health check (AI moderation): ' . $e->getMessage());
                }
            });
        }
    }

    protected function initMq(): void
    {
        try {
            $this->mqChannel = MQService::getChannel();

            $this->exchange = (string) blog_config('rabbitmq_ai_moderation_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey = (string) blog_config('rabbitmq_ai_moderation_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName = (string) blog_config('rabbitmq_ai_moderation_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange = (string) blog_config('rabbitmq_ai_moderation_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->dlq = (string) blog_config('rabbitmq_ai_moderation_dlx_queue', $this->dlq, true) ?: $this->dlq;

            MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->dlq);
            MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->dlq);
        } catch (Throwable $e) {
            Log::error('AiModerationWorker MQ init failed: ' . $e->getMessage());
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
                    Log::warning('AI moderation wait: ' . $e->getMessage());
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

            $taskType = (string) ($data['task_type'] ?? 'moderate_comment');
            if ($taskType !== 'moderate_comment') {
                // 兼容：忽略非审核任务
                $message->ack();

                return;
            }

            $this->handleModerationTask($data, $message);
        } catch (Throwable $e) {
            Log::error('AI moderation handle failed: ' . $e->getMessage());
            $this->handleFailedMessage($message);
        }
    }

    protected function handleModerationTask(array $data, AMQPMessage $message): void
    {
        $commentId = (int) ($data['comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new RuntimeException('Missing comment_id');
        }

        $comment = Comment::find($commentId);
        if (!$comment) {
            // 评论已不存在，直接 ACK
            $message->ack();

            return;
        }

        // 构建提示词（默认不为空）
        $template = (string) blog_config('comment_ai_moderation_prompt', '', true);
        if (trim($template) === '') {
            $template = $this->getDefaultPrompt();
        }

        $promptText = strtr($template, [
            '{content}' => (string) $comment->content,
            '{author_name}' => (string) $comment->guest_name,
            '{author_email}' => (string) $comment->guest_email,
            '{ip_address}' => (string) $comment->ip_address,
            '{user_agent}' => (string) $comment->user_agent,
        ]);

        $params = [
            'content' => (string) $comment->content,
            'author_name' => (string) $comment->guest_name,
            'author_email' => (string) $comment->guest_email,
            'ip_address' => (string) $comment->ip_address,
            'user_agent' => (string) $comment->user_agent,
            'messages' => [
                ['role' => 'user', 'content' => $promptText],
            ],
        ];
        $options = [
            'temperature' => (float) blog_config('comment_ai_moderation_temperature', 0.1, true),
        ];

        // 选择AI提供者
        $provider = AISummaryService::getCurrentProvider();
        if (!$provider) {
            throw new RuntimeException('No AI provider available');
        }

        $result = $provider->call('moderate_comment', $params, $options);
        if (!($result['ok'] ?? false)) {
            throw new RuntimeException('AI moderation failed: ' . ($result['error'] ?? 'unknown'));
        }

        $data = $result['result'] ?? [];
        if (is_string($data)) {
            // 兼容文本：提取 JSON
            $data = $this->tryParseJsonFromText($data);
        }
        if (!is_array($data)) {
            throw new RuntimeException('AI moderation result is not array');
        }

        // 校验规则
        $validated = $this->validateModerationResult($data);
        if ($validated === null) {
            throw new RuntimeException('AI moderation result not match schema');
        }

        // 写入评论记录
        $comment->ai_moderation_result = $validated['result'];
        $comment->ai_moderation_reason = $validated['reason'];
        $comment->ai_moderation_confidence = $validated['confidence'];
        $comment->ai_moderation_categories = !empty($validated['categories']) ? json_encode($validated['categories'], JSON_UNESCAPED_UNICODE) : null;

        // 根据结果更新评论状态（与表约束一致：pending/approved/spam/trash）
        if (!$validated['passed']) {
            // AI 返回 rejected/spam -> 存储为 spam
            $comment->status = 'spam';
        } else {
            $requireModeration = blog_config('comment_moderation', true, true);
            $autoApprove = (bool) blog_config('comment_ai_auto_approve_on_pass', false, true);
            $minConf = (float) blog_config('comment_ai_auto_approve_min_confidence', 0.85, true);
            if ($autoApprove && ($validated['confidence'] >= $minConf)) {
                $comment->status = 'approved';
            } else {
                $comment->status = $requireModeration ? 'pending' : 'approved';
            }
        }

        $comment->save();

        $message->ack();
    }

    private function getDefaultPrompt(): string
    {
        return <<<EOT
            请审核以下评论内容，并仅以JSON返回结果：
            {
              "passed": true/false,
              "result": "approved/rejected/spam",
              "reason": "审核理由",
              "confidence": 0.0-1.0,
              "categories": ["问题类别，如 spam, offensive 等"]
            }

            请严格按照如下说明给出 confidence：
            - confidence 表示“你对本次审核结论(result/passed)的把握程度”。
            - 取值范围 0 到 1，保留两位小数。
            - 0 表示 100% 不确定（高度怀疑你的结论是错误的），1 表示 100% 确定（你的结论完全可信）。

            在判断是否违规时，请特别留意并尽量进行归一化识别以下绕过方式：
            - 同音/谐音/形似替换（含数字、符号、标点、emoji、部首拆分、火星文、形声/象形变体）
            - 多语/方言/中英混写（含拼音、粤语/闽南/吴语等方言写法、音译/谐译，及夹杂阿拉伯文、日文、韩文等）
            - Leet/大小写变换/插入空格与标点/拉长重复/零宽字符
            - 隐喻、暗号、缩写、避敏同义替换

            额外要求（语音音素检测）：
            1) 先将“评论内容”转换为多通道的发音序列：
               - 普通话：拼音（保留与不保留声调两版）
               - 粤语：粤拼/Jyutping
               - 英语：音标或CMU-like音素
               - 日语：罗马音/假名；韩语：RR/IPA
               - 其他语种可近似用IPA或合理的转写
            2) 基于音素序列做近似匹配，检查是否与常见违规词（辱骂/歧视/色情/暴恐/违法等）的读音相近（允许少量替换/插入/删除、跨语种同音）。
            3) 若识别为谐音/近音规避，请在 reason 中明确：
               - 标注“phonetic-hint”并给出被映射的目标词、使用的音素/转写及匹配依据（如编辑距离/相似度）。

            如命中，请在 reason 中简要说明识别依据与类别（如："offensive(谐音, phonetic-hint): ..."）。

            仅输出上述JSON，不要包含多余文本或注释。

            评论内容：{content}
            昵称：{author_name}
            邮箱：{author_email}
            IP：{ip_address}
            User-Agent：{user_agent}
            EOT;
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
     * 校验AI返回是否符合规则，返回正规化后的数组，不符合则返回null
     *
     * @param array $data
     *
     * @return array|null {passed:bool,result:string,reason:string,confidence:float,categories:array}
     */
    private function validateModerationResult(array $data): ?array
    {
        $result = strtolower((string) ($data['result'] ?? ''));
        $allowed = ['approved', 'rejected', 'spam', 'pending'];
        if (!in_array($result, $allowed, true)) {
            return null;
        }
        $passed = (bool) ($data['passed'] ?? ($result === 'approved'));
        $reason = (string) ($data['reason'] ?? '');
        if ($reason === '') {
            // 允许为空但给个默认
            $reason = 'OK';
        }
        $confidence = (float) ($data['confidence'] ?? 1.0);
        if ($confidence < 0.0) {
            $confidence = 0.0;
        } elseif ($confidence > 1.0) {
            $confidence = 1.0;
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
            'categories' => $categories,
        ];
    }

    protected function handleFailedMessage(AMQPMessage $message): void
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        $retry = 0;
        if ($headers instanceof AMQPTable) {
            $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
            $retry = (int) ($native['x-retry-count'] ?? 0);
        }
        if ($retry < 2) {
            $newHeaders = $headers ? clone $headers : new AMQPTable();
            $newHeaders->set('x-retry-count', $retry + 1);
            $newMsg = new AMQPMessage($message->getBody(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => $newHeaders,
            ]);
            $this->mqChannel?->basic_publish($newMsg, $this->exchange, $this->routingKey);
            $message->ack();
        } else {
            $message->reject(false);
        }
    }
}

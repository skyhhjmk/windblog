<?php

declare(strict_types=1);

namespace app\service\ai\providers;

use app\service\ai\BaseAiProvider;
use GuzzleHttp\Client;
use support\Log;

/**
 * OpenAI提供者：支持OpenAI官方API和兼容接口（如Azure OpenAI、自建等）
 */
class OpenAiProvider extends BaseAiProvider
{
    protected ?Client $client = null;

    public function getId(): string
    {
        return 'openai';
    }

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function getType(): string
    {
        return 'openai';
    }

    public function getDescription(): string
    {
        return 'OpenAI 官方 API - 业界领先的大语言模型（GPT系列）';
    }

    public function getIcon(): string
    {
        return '🤖';
    }

    public function getPresetModels(): array
    {
        return [
            [
                'id' => 'gpt-4o',
                'name' => 'GPT-4o',
                'description' => '最新旗舰模型，多模态能力强',
                'context_window' => 128000,
            ],
            [
                'id' => 'gpt-4-turbo',
                'name' => 'GPT-4 Turbo',
                'description' => '更快更便宜的GPT-4',
                'context_window' => 128000,
            ],
            [
                'id' => 'gpt-4',
                'name' => 'GPT-4',
                'description' => '强大的推理能力',
                'context_window' => 8192,
            ],
            [
                'id' => 'gpt-3.5-turbo',
                'name' => 'GPT-3.5 Turbo',
                'description' => '快速且经济实惠',
                'context_window' => 16385,
            ],
            [
                'id' => 'o1',
                'name' => 'O1',
                'description' => '深度思考模型，适合复杂推理',
                'context_window' => 200000,
            ],
            [
                'id' => 'o1-mini',
                'name' => 'O1 Mini',
                'description' => '轻量级深度思考模型',
                'context_window' => 128000,
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'gpt-4o';
    }

    public function getSupportedFeatures(): array
    {
        return [
            'streaming' => true,
            'multimodal' => ['text', 'image', 'audio'],
            'function_calling' => true,
            'deep_thinking' => true,
        ];
    }

    public function call(string $task, array $params = [], array $options = []): array
    {
        try {
            switch ($task) {
                case 'summarize':
                    return $this->doSummarize($params, $options);
                case 'translate':
                    return $this->doTranslate($params, $options);
                case 'chat':
                    return $this->doChat($params, $options);
                case 'generate':
                    return $this->doGenerate($params, $options);
                case 'moderate_comment':
                    return $this->doModerateComment($params, $options);
                default:
                    return ['ok' => false, 'error' => 'Unsupported task: ' . $task];
            }
        } catch (\Throwable $e) {
            Log::error('OpenAI Provider error: ' . $e->getMessage(), [
                'task' => $task,
                'exception' => get_class($e),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function getSupportedTasks(): array
    {
        return ['summarize', 'translate', 'chat', 'generate', 'moderate_comment'];
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text', 'required' => true, 'default' => 'https://api.openai.com/v1', 'placeholder' => 'https://api.openai.com/v1'],
            ['key' => 'chat_endpoint', 'label' => '聊天接口路径', 'type' => 'text', 'required' => false, 'default' => '/chat/completions', 'placeholder' => '/chat/completions'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'placeholder' => 'sk-...'],
            ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'default' => 'gpt-3.5-turbo', 'options' => 'auto'],
            ['key' => 'custom_model_id', 'label' => '自定义模型ID', 'type' => 'text', 'required' => false, 'placeholder' => '留空则使用上面选择的模型'],
            ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7, 'min' => 0, 'max' => 2, 'step' => 0.1],
            ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false, 'default' => 1000],
            ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false, 'default' => 30],
            ['key' => 'verify_ssl', 'label' => '验证SSL证书', 'type' => 'checkbox', 'required' => false, 'default' => true],
            ['key' => 'ca_bundle', 'label' => 'CA证书路径', 'type' => 'text', 'required' => false, 'placeholder' => '可选，用于自定义SSL证书'],
            ['key' => 'multimodal_support', 'label' => '多模态支持', 'type' => 'multiselect', 'required' => false, 'options' => [
                ['value' => 'text', 'label' => '文本'],
                ['value' => 'image', 'label' => '图片'],
                ['value' => 'audio', 'label' => '音频'],
                ['value' => 'video', 'label' => '视频'],
                ['value' => 'file', 'label' => '文件'],
            ]],
            ['key' => 'deep_thinking', 'label' => '深度思考支持', 'type' => 'checkbox', 'required' => false, 'default' => false],
            ['key' => 'weight', 'label' => '权重', 'type' => 'number', 'required' => false, 'default' => 1, 'min' => 0],
            ['key' => 'enabled', 'label' => '启用', 'type' => 'checkbox', 'required' => false, 'default' => true],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['base_url'])) {
            $errors[] = 'Base URL is required';
        }

        if (empty($config['api_key'])) {
            $errors[] = 'API Key is required';
        }

        if (empty($config['model'])) {
            $errors[] = 'Model is required';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 获取可用模型列表
     */
    public function fetchModels(): array
    {
        try {
            $client = $this->getClient();
            $response = $client->get('/models', [
                'headers' => $this->getHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['data']) || !is_array($data['data'])) {
                return ['ok' => false, 'error' => 'Invalid response format'];
            }

            $models = array_map(function ($model) {
                return [
                    'id' => $model['id'] ?? '',
                    'created' => $model['created'] ?? 0,
                    'owned_by' => $model['owned_by'] ?? '',
                ];
            }, $data['data']);

            // 过滤出聊天模型
            $chatModels = array_filter($models, function ($model) {
                return str_contains($model['id'], 'gpt') ||
                    str_contains($model['id'], 'turbo') ||
                    str_contains($model['id'], 'chat');
            });

            return [
                'ok' => true,
                'models' => array_values($chatModels),
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to fetch OpenAI models: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function doSummarize(array $params, array $options): array
    {
        $content = (string) ($params['content'] ?? '');
        $prompt = $options['prompt'] ?? '请为以下内容生成一个简洁的摘要：';

        $messages = [
            ['role' => 'system', 'content' => '你是一个专业的内容摘要助手。'],
            ['role' => 'user', 'content' => $prompt . "\n\n" . $content],
        ];

        return $this->callChatCompletion($messages, $options);
    }

    protected function doTranslate(array $params, array $options): array
    {
        $text = (string) ($params['text'] ?? '');
        $targetLang = (string) ($params['target_lang'] ?? 'English');
        $sourceLang = (string) ($params['source_lang'] ?? 'auto');

        $prompt = "Translate the following text to {$targetLang}";
        if ($sourceLang !== 'auto') {
            $prompt .= " from {$sourceLang}";
        }
        $prompt .= ":\n\n" . $text;

        $messages = [
            ['role' => 'system', 'content' => 'You are a professional translator.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        return $this->callChatCompletion($messages, $options);
    }

    protected function doChat(array $params, array $options): array
    {
        $messages = $params['messages'] ?? [];

        if (empty($messages) && isset($params['message'])) {
            $content = $params['message'];

            // 支持多模态：如果有图片，构建包含图片的消息
            if (!empty($params['images']) && is_array($params['images'])) {
                $contentParts = [
                    ['type' => 'text', 'text' => $content],
                ];

                foreach ($params['images'] as $imageUrl) {
                    $contentParts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $imageUrl],
                    ];
                }

                $messages = [['role' => 'user', 'content' => $contentParts]];
            } else {
                $messages = [['role' => 'user', 'content' => $content]];
            }
        }

        return $this->callChatCompletion($messages, $options);
    }

    protected function doGenerate(array $params, array $options): array
    {
        $prompt = (string) ($params['prompt'] ?? '');

        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        return $this->callChatCompletion($messages, $options);
    }

    protected function doModerateComment(array $params, array $options): array
    {
        $content = (string) ($params['content'] ?? '');
        $authorName = (string) ($params['author_name'] ?? '');
        $authorEmail = (string) ($params['author_email'] ?? '');
        $ipAddress = (string) ($params['ip_address'] ?? '');

        $systemPrompt = '你是一个专业的评论审核助手。你的任务是检测评论是否包含垃圾信息、广告、恶意内容、敏感词汇、人身攻击等不当内容。';

        $userPrompt = <<<EOT
            请审核以下评论内容，判断是否应该通过审核。

            评论内容：
            {$content}

            评论者信息：
            - 昵称：{$authorName}
            - 邮箱：{$authorEmail}
            - IP地址：{$ipAddress}

            请按照以下JSON格式返回审核结果（只返回JSON，不要其他内容）：
            {
              "passed": true/false,
              "result": "approved/rejected/spam",
              "reason": "审核理由",
              "confidence": 0.0-1.0,
              "categories": ["检测到的问题类别，如：spam, offensive, sensitive等"]
            }

            审核标准：
            1. 垃圾评论：包含大量链接、重复内容、无意义字符
            2. 广告：推销产品、服务的内容
            3. 恶意内容：人身攻击、辱骂、威胁
            4. 敏感词汇：政治敏感、色情、暴力等内容
            5. 正常评论：友好、建设性的讨论
            EOT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $response = $this->callChatCompletion($messages, $options);

        if (!$response['ok']) {
            return $response;
        }

        // 解析JSON结果
        try {
            $resultText = trim($response['result']);

            // 尝试提取JSON（防止AI返回了额外的文本）
            if (preg_match('/\{[\s\S]*\}/', $resultText, $matches)) {
                $resultText = $matches[0];
            }

            $moderationResult = json_decode($resultText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse moderation result as JSON', [
                    'result' => $resultText,
                    'error' => json_last_error_msg(),
                ]);

                // 回退到默认结果
                $moderationResult = [
                    'passed' => true,
                    'result' => 'approved',
                    'reason' => 'AI返回结果解析失败，默认通过',
                    'confidence' => 0.0,
                    'categories' => [],
                ];
            }

            return [
                'ok' => true,
                'result' => $moderationResult,
                'usage' => $response['usage'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Error processing moderation result: ' . $e->getMessage());

            return [
                'ok' => false,
                'error' => 'Failed to process moderation result: ' . $e->getMessage(),
            ];
        }
    }

    protected function callChatCompletion(array $messages, array $options): array
    {
        $body = [
            'model' => $this->getModel($options),
            'messages' => $messages,
            'temperature' => $this->getTemperature($options),
        ];

        // 深度思考支持（如o1等模型）
        // 只对支持的模型添加 reasoning_effort 参数
        $modelId = $body['model'];
        if ($this->getConfig('deep_thinking', false) && (str_contains($modelId, 'o1') || str_contains($modelId, 'o3'))) {
            $body['reasoning_effort'] = 'high';
        }

        $maxTokens = $this->getMaxTokens($options);
        if ($maxTokens !== null) {
            $body['max_tokens'] = $maxTokens;
        }

        try {
            $baseUrl = $this->getConfig('base_url', 'https://api.openai.com/v1');
            $apiKey = $this->getConfig('api_key', '');
            $endpoint = $this->getConfig('chat_endpoint', '/chat/completions');
            $url = rtrim($baseUrl, '/') . $endpoint;

            Log::info('OpenAI API Request', [
                'base_url' => $baseUrl,
                'endpoint' => '/chat/completions',
                'full_url' => $url,
                'model' => $body['model'],
                'has_api_key' => !empty($apiKey),
                'api_key_prefix' => !empty($apiKey) ? substr($apiKey, 0, 10) . '...' : 'none',
                'body' => $body,
            ]);

            // 使用原生 curl 代替 Guzzle，避免SSL和重定向问题
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => $this->getTimeout(),
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $responseBody = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // curl错误检查
            if ($curlError) {
                Log::error('cURL error', ['error' => $curlError]);

                return ['ok' => false, 'error' => 'cURL error: ' . $curlError];
            }

            Log::debug('OpenAI API Response', [
                'status_code' => $statusCode,
                'content_type' => $contentType,
                'body_preview' => mb_substr($responseBody, 0, 500),
                'body_length' => strlen($responseBody),
            ]);

            // 检查 HTTP 状态码
            if ($statusCode >= 400) {
                Log::error('HTTP error response', [
                    'status_code' => $statusCode,
                    'body' => $responseBody,
                ]);

                // 尝试解析错误消息
                $errorData = json_decode($responseBody, true);
                if ($errorData && isset($errorData['error'])) {
                    $errorMsg = is_array($errorData['error'])
                        ? ($errorData['error']['message'] ?? json_encode($errorData['error']))
                        : $errorData['error'];

                    return ['ok' => false, 'error' => "HTTP {$statusCode}: {$errorMsg}"];
                }

                return ['ok' => false, 'error' => "HTTP {$statusCode}: {$responseBody}"];
            }

            // 检查是否返回了 HTML 而不是 JSON
            if (str_contains($contentType, 'text/html') || str_starts_with(trim($responseBody), '<!doctype') || str_starts_with(trim($responseBody), '<html')) {
                Log::error('API returned HTML instead of JSON', [
                    'content_type' => $contentType,
                    'body_preview' => mb_substr($responseBody, 0, 200),
                ]);

                return [
                    'ok' => false,
                    'error' => 'API endpoint returned HTML instead of JSON. Please check your base_url configuration. Expected: https://your-api.com/v1 (without /chat/completions)',
                ];
            }

            $data = json_decode($responseBody, true);

            if ($data === null) {
                Log::error('Failed to parse JSON response', [
                    'raw_body_preview' => mb_substr($responseBody, 0, 500),
                    'json_error' => json_last_error_msg(),
                ]);

                return ['ok' => false, 'error' => 'Failed to parse API response as JSON: ' . json_last_error_msg()];
            }

            // 检查错误响应
            if (isset($data['error'])) {
                $errorMsg = is_array($data['error'])
                    ? ($data['error']['message'] ?? json_encode($data['error']))
                    : $data['error'];
                Log::error('API returned error', ['error' => $data['error']]);

                return ['ok' => false, 'error' => $errorMsg];
            }

            if (!isset($data['choices'][0]['message']['content'])) {
                Log::error('Invalid response structure', ['response_data' => $data]);

                return ['ok' => false, 'error' => 'Invalid response format: missing choices[0].message.content'];
            }

            return [
                'ok' => true,
                'result' => $data['choices'][0]['message']['content'],
                'usage' => [
                    'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                    'total_tokens' => $data['usage']['total_tokens'] ?? 0,
                ],
                'model' => $data['model'] ?? null,
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI API call failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $baseUrl = rtrim($this->getConfig('base_url', 'https://api.openai.com/v1'), '/');

            $clientOptions = [
                'base_uri' => $baseUrl,
                'timeout' => $this->getTimeout(),
                'connect_timeout' => 10,
                'http_errors' => false, // 不自动抛出 HTTP 错误，手动处理
                'allow_redirects' => true, // 允许自动跟随重定向
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'WindBlog-Webman/1.0',
                ],
            ];

            // SSL 证书配置
            if ($this->getConfig('verify_ssl', true) === false) {
                $clientOptions['verify'] = false;
            } else {
                $caPath = $this->getConfig('ca_bundle');
                if ($caPath && file_exists($caPath)) {
                    $clientOptions['verify'] = $caPath;
                } else {
                    // Windows 环境下，如果没有配置证书，暂时禁用验证
                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        $clientOptions['verify'] = false;
                    }
                }
            }

            $this->client = new Client($clientOptions);
        }

        return $this->client;
    }

    protected function getHeaders(): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->getConfig('api_key', ''),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // 记录请求头（隐藏 API Key）
        Log::debug('Request Headers', [
            'has_authorization' => !empty($headers['Authorization']),
            'content_type' => $headers['Content-Type'],
        ]);

        return $headers;
    }
}

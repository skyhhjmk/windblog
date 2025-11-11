<?php

declare(strict_types=1);

namespace app\service\ai\providers;

use GuzzleHttp\Client;
use support\Log;

/**
 * 智谱AI (GLM) 提供者
 * 实现智谱AI官方API规范
 */
class ZhipuProvider extends OpenAiProvider
{
    public function getId(): string
    {
        return 'zhipu';
    }

    public function getName(): string
    {
        return '智谱AI (GLM)';
    }

    public function getType(): string
    {
        return 'zhipu';
    }

    public function getDescription(): string
    {
        return '智谱AI ChatGLM API - 国产大语言模型';
    }

    public function getIcon(): string
    {
        return '🎓';
    }

    public function getPresetModels(): array
    {
        return [
            [
                'id' => 'glm-4.6',
                'name' => 'GLM-4.6',
                'description' => '最新旗舰模型，专为智能体应用打造',
                'context_window' => 128000,
            ],
            [
                'id' => 'glm-4.5',
                'name' => 'GLM-4.5',
                'description' => '复杂推理、超长上下文',
                'context_window' => 128000,
            ],
            [
                'id' => 'glm-4.5-air',
                'name' => 'GLM-4.5 Air',
                'description' => '推理速度快',
                'context_window' => 128000,
            ],
            [
                'id' => 'glm-4.5-flash',
                'name' => 'GLM-4.5 Flash',
                'description' => '极快推理速度',
                'context_window' => 128000,
            ],
            [
                'id' => 'glm-4-plus',
                'name' => 'GLM-4 Plus',
                'description' => 'GLM-4系列增强版',
                'context_window' => 128000,
            ],
            [
                'id' => 'glm-4-air-250414',
                'name' => 'GLM-4 Air',
                'description' => '推理速度快，适合高频调用',
                'context_window' => 128000,
            ],
            [
                'id' => 'glm-4-flashx',
                'name' => 'GLM-4 FlashX',
                'description' => '免费模型，适合开发测试',
                'context_window' => 128000,
            ],
            [
                'id' => 'glm-4v-flash',
                'name' => 'GLM-4V Flash',
                'description' => '多模态视觉模型，支持图片理解',
                'context_window' => 8192,
                'multimodal' => true,
            ],
            [
                'id' => 'glm-4v-plus',
                'name' => 'GLM-4V Plus',
                'description' => '增强版多模态模型',
                'context_window' => 8192,
                'multimodal' => true,
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'glm-4.6';
    }

    public function getSupportedFeatures(): array
    {
        return [
            'streaming' => false,
            'multimodal' => ['text', 'image'],
            'function_calling' => false,
            'deep_thinking' => true,  // 支持thinking模式
        ];
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true, 'default' => 'https://open.bigmodel.cn', 'placeholder' => 'https://open.bigmodel.cn'],
            ['key' => 'chat_endpoint', 'label' => '聊天接口路径', 'type' => 'text', 'required' => false, 'default' => '/api/paas/v4/chat/completions', 'placeholder' => '/api/paas/v4/chat/completions (代理用/chat/completions)'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'placeholder' => 'xxx.xxx'],
            ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'default' => 'glm-4.6', 'options' => 'auto'],
            ['key' => 'custom_model_id', 'label' => '自定义模型ID', 'type' => 'text', 'required' => false, 'placeholder' => '留空则使用上面选择的模型'],
            ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 1.0, 'min' => 0, 'max' => 1, 'step' => 0.1],
            ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false, 'default' => 1024],
            ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false, 'default' => 30],
            ['key' => 'do_sample', 'label' => '启用采样', 'type' => 'checkbox', 'required' => false, 'default' => true],
            ['key' => 'top_p', 'label' => 'Top P', 'type' => 'number', 'required' => false, 'default' => 0.95, 'min' => 0, 'max' => 1, 'step' => 0.01],
            ['key' => 'enable_thinking', 'label' => '启用思考模式', 'type' => 'checkbox', 'required' => false, 'default' => false, 'description' => '启用后模型会展示推理过程'],
            ['key' => 'verify_ssl', 'label' => '验证SSL证书', 'type' => 'checkbox', 'required' => false, 'default' => true],
            ['key' => 'ca_bundle', 'label' => 'CA证书路径', 'type' => 'text', 'required' => false, 'placeholder' => '可选，用于自定义SSL证书'],
        ];
    }

    /**
     * 重写获取客户端方法
     */
    protected function getClient(): Client
    {
        if ($this->client === null) {
            $baseUrl = rtrim($this->getConfig('base_url', 'https://open.bigmodel.cn'), '/');

            $clientOptions = [
                'base_uri' => $baseUrl,
                'timeout' => $this->getTimeout(),
                'connect_timeout' => 10,
                'http_errors' => true,
                'headers' => [
                    'User-Agent' => 'WindBlog-Webman/1.0',
                ],
            ];

            // SSL 证书配置
            // 优先使用系统证书，如果失败则禁用验证（仅开发环境）
            if ($this->getConfig('verify_ssl', true) === false) {
                $clientOptions['verify'] = false;
            } else {
                // 尝试使用系统 CA 证书包
                $caPath = $this->getConfig('ca_bundle');
                if ($caPath && file_exists($caPath)) {
                    $clientOptions['verify'] = $caPath;
                } else {
                    $clientOptions['verify'] = false;

                }
            }

            $this->client = new Client($clientOptions);
        }

        return $this->client;
    }

    /**
     * 重写调用聊天补全接口，使用智谱AI的实际路径
     */
    protected function callChatCompletion(array $messages, array $options): array
    {
        $model = $this->getModel($options);

        // 检查是否包含图片
        $hasImages = $this->messagesContainImages($messages);

        // 如果有图片但没有使用多模态模型，返回错误
        if ($hasImages && !str_contains($model, '4v')) {
            Log::warning('ZhipuProvider: Images provided but model does not support multimodal', [
                'model' => $model,
                'supported_models' => ['glm-4v-flash', 'glm-4v-plus'],
            ]);

            return [
                'ok' => false,
                'error' => '多模态功能需要使用 glm-4v-flash 或 glm-4v-plus 模型，当前模型：' . $model,
            ];
        }

        // 处理多模态消息（图片URL）
        $messages = $this->processMultimodalMessages($messages);

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $this->getTemperature($options),
        ];

        // 智谱AI特有参数
        $doSample = $this->getConfig('do_sample');
        if ($doSample !== null) {
            $body['do_sample'] = (bool) $doSample;
        }

        // Top P 参数
        $topP = $this->getConfig('top_p');
        if ($topP !== null) {
            $body['top_p'] = (float) $topP;
        }

        // 最大Token数
        $maxTokens = $this->getMaxTokens($options);
        if ($maxTokens !== null) {
            $body['max_tokens'] = $maxTokens;
        }

        // 思考模式
        if ($this->getConfig('enable_thinking') || ($options['thinking'] ?? false)) {
            $body['thinking'] = ['type' => 'enabled'];
        }

        // 其他智谱AI参数
        $body['stream'] = false;
        $body['tool_stream'] = false;
        $body['response_format'] = ['type' => 'text'];

        try {
            $baseUrl = $this->getConfig('base_url', 'https://open.bigmodel.cn');
            $apiKey = $this->getConfig('api_key', '');
            $endpoint = $this->getConfig('chat_endpoint', '/api/paas/v4/chat/completions');
            $url = rtrim($baseUrl, '/') . $endpoint;

            Log::info('Zhipu AI API Request', [
                'base_url' => $baseUrl,
                'endpoint' => $endpoint,
                'full_url' => $url,
                'model' => $body['model'],
                'has_api_key' => !empty($apiKey),
            ]);

            // 使用原生 curl
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

            Log::debug('Zhipu AI API Response', [
                'status_code' => $statusCode,
                'content_type' => $contentType,
                'body_preview' => mb_substr($responseBody, 0, 500),
            ]);

            // 检查 HTTP 状态码
            if ($statusCode >= 400) {
                Log::error('HTTP error response', [
                    'status_code' => $statusCode,
                    'body' => $responseBody,
                ]);

                $errorData = json_decode($responseBody, true);
                if ($errorData && isset($errorData['error'])) {
                    $errorMsg = is_array($errorData['error'])
                        ? ($errorData['error']['message'] ?? json_encode($errorData['error']))
                        : $errorData['error'];

                    return ['ok' => false, 'error' => "HTTP {$statusCode}: {$errorMsg}"];
                }

                return ['ok' => false, 'error' => "HTTP {$statusCode}: {$responseBody}"];
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
                return ['ok' => false, 'error' => 'Invalid response format'];
            }

            $rawContent = (string) ($data['choices'][0]['message']['content'] ?? '');
            $check = $this->validateThinkBlocks($rawContent);
            if (!$check['valid']) {
                return ['ok' => false, 'error' => $check['error'] ?? 'AI 响应格式错误：<think></think> 标签不完整或不匹配'];
            }
            $parsed = $this->extractThinkFromText($rawContent);

            $result = [
                'ok' => true,
                'result' => $parsed['content'],
                'usage' => [
                    'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                    'total_tokens' => $data['usage']['total_tokens'] ?? 0,
                ],
                'model' => $data['model'] ?? null,
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
                'id' => $data['id'] ?? null,
                'request_id' => $data['request_id'] ?? null,
            ];

            if (!empty($parsed['thinking'])) {
                $result['reasoning'] = $parsed['thinking'];
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Zhipu AI API call failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 智谱AI不支持通过API获取模型列表，直接返回预设模型
     */
    public function fetchModels(): array
    {
        $presetModels = $this->getPresetModels();
        $models = array_map(function ($model) {
            return [
                'id' => $model['id'],
                'name' => $model['name'] ?? $model['id'],
                'description' => $model['description'] ?? '',
            ];
        }, $presetModels);

        return [
            'ok' => true,
            'models' => $models,
        ];
    }

    /**
     * 检查消息中是否包含图片
     *
     * @param array $messages 消息数组
     *
     * @return bool
     */
    protected function messagesContainImages(array $messages): bool
    {
        foreach ($messages as $message) {
            if (!isset($message['content'])) {
                continue;
            }

            $content = $message['content'];

            // 检查数组格式的多模态消息
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (isset($part['type']) && $part['type'] === 'image_url') {
                        return true;
                    }
                }
            }

            // 检查字符串中的图片标记
            if (is_string($content) && preg_match('/\[image:.+?\]/i', $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 处理多模态消息（支持图片URL）
     * 将简单的文本消息转换为智谱AI的多模态格式
     *
     * @param array $messages 原始消息数组
     *
     * @return array 处理后的消息数组
     */
    protected function processMultimodalMessages(array $messages): array
    {
        foreach ($messages as &$message) {
            // 如果消息已经是多模态格式（content是数组），验证图片URL
            if (isset($message['content']) && is_array($message['content'])) {
                foreach ($message['content'] as &$part) {
                    if (isset($part['type']) && $part['type'] === 'image_url') {
                        $url = $part['image_url']['url'] ?? '';
                        // 检查图片URL是否是公网可访问的
                        if (!empty($url) && !$this->isPublicUrl($url)) {
                            Log::warning('ZhipuProvider: Image URL may not be publicly accessible', ['url' => $url]);
                        }
                    }
                }
                unset($part);
                continue;
            }

            // 检测消息中是否包含图片URL（简单检测）
            if (isset($message['content']) && is_string($message['content'])) {
                // 检测是否包含图片URL标记
                if (preg_match('/\[image:(.+?)\]/i', $message['content'], $matches)) {
                    $imageUrl = $matches[1];
                    $textContent = trim(preg_replace('/\[image:.+?\]/i', '', $message['content']));

                    // 检查URL是否公网可访问
                    if (!$this->isPublicUrl($imageUrl)) {
                        Log::warning('ZhipuProvider: Image URL may not be publicly accessible', ['url' => $imageUrl]);
                    }

                    // 转换为多模态格式
                    $content = [];
                    if (!empty($imageUrl)) {
                        $content[] = [
                            'type' => 'image_url',
                            'image_url' => ['url' => $imageUrl],
                        ];
                    }
                    if (!empty($textContent)) {
                        $content[] = [
                            'type' => 'text',
                            'text' => $textContent,
                        ];
                    }

                    if (!empty($content)) {
                        $message['content'] = $content;
                    }
                }
            }
        }
        unset($message);

        return $messages;
    }

    /**
     * 检查URL是否是公网可访问的
     *
     * @param string $url
     *
     * @return bool
     */
    protected function isPublicUrl(string $url): bool
    {
        // 检查是否以 http:// 或 https:// 开头
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }

        // 检查是否包含本地地址
        $localPatterns = [
            '/localhost/i',
            '/127\.0\.0\.1/',
            '/192\.168\./',
            '/10\./',
            '/172\.(1[6-9]|2[0-9]|3[0-1])\./',
        ];

        foreach ($localPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 构建图片消息（辅助方法）
     *
     * @param string $imageUrl 图片URL
     * @param string $text     文本内容
     *
     * @return array 消息数组
     */
    public static function buildImageMessage(string $imageUrl, string $text = ''): array
    {
        $content = [
            [
                'type' => 'image_url',
                'image_url' => ['url' => $imageUrl],
            ],
        ];

        if (!empty($text)) {
            $content[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        return [
            'role' => 'user',
            'content' => $content,
        ];
    }
}

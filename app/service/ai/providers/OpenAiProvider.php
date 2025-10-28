<?php

declare(strict_types=1);

namespace app\service\ai\providers;

use app\service\ai\AiProviderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use support\Log;

/**
 * OpenAI提供者：支持OpenAI官方API和兼容接口（如Azure OpenAI、自建等）
 */
class OpenAiProvider implements AiProviderInterface
{
    protected array $config = [];

    protected ?Client $client = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

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
        return ['summarize', 'translate', 'chat', 'generate'];
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text', 'required' => true, 'default' => 'https://api.openai.com/v1', 'placeholder' => 'https://api.openai.com/v1'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'placeholder' => 'sk-...'],
            ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'default' => 'gpt-3.5-turbo', 'options' => 'auto'],
            ['key' => 'custom_model_id', 'label' => '自定义模型ID', 'type' => 'text', 'required' => false, 'placeholder' => '留空则使用上面选择的模型'],
            ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7, 'min' => 0, 'max' => 2, 'step' => 0.1],
            ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false, 'default' => 1000],
            ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false, 'default' => 30],
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
            $messages = [['role' => 'user', 'content' => $params['message']]];
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

    protected function callChatCompletion(array $messages, array $options): array
    {
        $client = $this->getClient();

        // 优先使用自定义模型ID
        $model = $this->config['custom_model_id'] ?? $this->config['model'] ?? 'gpt-3.5-turbo';
        if (empty($model)) {
            $model = $this->config['model'] ?? 'gpt-3.5-turbo';
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? $this->config['temperature'] ?? 0.7),
        ];

        // 深度思考支持（如o1等模型）
        if (isset($this->config['deep_thinking']) && $this->config['deep_thinking']) {
            $body['reasoning_effort'] = 'high';
        }

        if (isset($options['max_tokens']) || isset($this->config['max_tokens'])) {
            $body['max_tokens'] = (int) ($options['max_tokens'] ?? $this->config['max_tokens']);
        }

        try {
            $response = $client->post('/chat/completions', [
                'headers' => $this->getHeaders(),
                'json' => $body,
                'timeout' => (int) ($this->config['timeout'] ?? 30),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['choices'][0]['message']['content'])) {
                return ['ok' => false, 'error' => 'Invalid response format'];
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
        } catch (GuzzleException $e) {
            Log::error('OpenAI API call failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $baseUrl = rtrim($this->config['base_url'] ?? 'https://api.openai.com/v1', '/');
            $this->client = new Client([
                'base_uri' => $baseUrl,
            ]);
        }

        return $this->client;
    }

    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
            'Content-Type' => 'application/json',
        ];
    }
}

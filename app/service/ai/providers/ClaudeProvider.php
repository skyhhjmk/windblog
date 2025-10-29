<?php

declare(strict_types=1);

namespace app\service\ai\providers;

use app\service\ai\BaseAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use support\Log;

/**
 * Claude (Anthropic) 提供者
 */
class ClaudeProvider extends BaseAiProvider
{
    protected ?Client $client = null;

    public function getId(): string
    {
        return 'claude';
    }

    public function getName(): string
    {
        return 'Claude (Anthropic)';
    }

    public function getType(): string
    {
        return 'claude';
    }

    public function getDescription(): string
    {
        return 'Anthropic Claude API - 强大的AI助手，擅长深度思考和长文本处理';
    }

    public function getIcon(): string
    {
        return '🧠';
    }

    public function getPresetModels(): array
    {
        return [
            [
                'id' => 'claude-3-5-sonnet-20241022',
                'name' => 'Claude 3.5 Sonnet',
                'description' => '最新最强大的Claude模型，平衡性能与速度',
                'context_window' => 200000,
            ],
            [
                'id' => 'claude-3-opus-20240229',
                'name' => 'Claude 3 Opus',
                'description' => '最强大的Claude模型，适合复杂任务',
                'context_window' => 200000,
            ],
            [
                'id' => 'claude-3-sonnet-20240229',
                'name' => 'Claude 3 Sonnet',
                'description' => '平衡性能与成本',
                'context_window' => 200000,
            ],
            [
                'id' => 'claude-3-haiku-20240307',
                'name' => 'Claude 3 Haiku',
                'description' => '最快速的Claude模型',
                'context_window' => 200000,
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'claude-3-5-sonnet-20241022';
    }

    public function getSupportedTasks(): array
    {
        return ['summarize', 'translate', 'chat', 'generate', 'analyze'];
    }

    public function getSupportedFeatures(): array
    {
        return [
            'streaming' => true,
            'multimodal' => ['text', 'image'],
            'function_calling' => true,
            'deep_thinking' => true,
            'long_context' => true,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true, 'default' => 'https://api.anthropic.com', 'placeholder' => 'https://api.anthropic.com'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'placeholder' => 'sk-ant-...'],
            ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'default' => 'claude-3-5-sonnet-20241022', 'options' => 'auto'],
            ['key' => 'custom_model_id', 'label' => '自定义模型ID', 'type' => 'text', 'required' => false, 'placeholder' => '留空则使用上面选择的模型'],
            ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7, 'min' => 0, 'max' => 1, 'step' => 0.1],
            ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false, 'default' => 1024],
            ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false, 'default' => 30],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['base_url'])) {
            $errors[] = 'API 基址不能为空';
        }

        if (empty($config['api_key'])) {
            $errors[] = 'API Key 不能为空';
        }

        if (empty($config['model'])) {
            $errors[] = '模型不能为空';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
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
                case 'analyze':
                    return $this->doAnalyze($params, $options);
                default:
                    return ['ok' => false, 'error' => 'Unsupported task: ' . $task];
            }
        } catch (\Throwable $e) {
            Log::error('Claude Provider error: ' . $e->getMessage(), [
                'task' => $task,
                'exception' => get_class($e),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function doSummarize(array $params, array $options): array
    {
        $content = (string) ($params['content'] ?? '');
        $prompt = $options['prompt'] ?? '请为以下内容生成一个简洁的摘要：';

        $messages = [
            ['role' => 'user', 'content' => $prompt . "\n\n" . $content],
        ];

        return $this->callMessages($messages, '你是一个专业的内容摘要助手。', $options);
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
            ['role' => 'user', 'content' => $prompt],
        ];

        return $this->callMessages($messages, 'You are a professional translator.', $options);
    }

    protected function doChat(array $params, array $options): array
    {
        $messages = $params['messages'] ?? [];

        if (empty($messages) && isset($params['message'])) {
            $messages = [['role' => 'user', 'content' => $params['message']]];
        }

        $system = $params['system'] ?? '';

        return $this->callMessages($messages, $system, $options);
    }

    protected function doGenerate(array $params, array $options): array
    {
        $prompt = (string) ($params['prompt'] ?? '');

        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        return $this->callMessages($messages, '', $options);
    }

    protected function doAnalyze(array $params, array $options): array
    {
        $content = (string) ($params['content'] ?? '');
        $analysisType = (string) ($params['analysis_type'] ?? 'general');

        $messages = [
            ['role' => 'user', 'content' => "请分析以下内容({$analysisType})：\n\n{$content}"],
        ];

        return $this->callMessages($messages, '你是一个专业的内容分析师。', $options);
    }

    protected function callMessages(array $messages, string $system = '', array $options = []): array
    {
        $client = $this->getClient();

        $body = [
            'model' => $this->getModel($options),
            'messages' => $messages,
            'max_tokens' => $this->getMaxTokens($options) ?? 1024,
            'temperature' => $this->getTemperature($options),
        ];

        if (!empty($system)) {
            $body['system'] = $system;
        }

        try {
            $response = $client->post('/v1/messages', [
                'headers' => $this->getHeaders(),
                'json' => $body,
                'timeout' => $this->getTimeout(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['content'][0]['text'])) {
                return ['ok' => false, 'error' => 'Invalid response format'];
            }

            return [
                'ok' => true,
                'result' => $data['content'][0]['text'],
                'usage' => [
                    'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                    'output_tokens' => $data['usage']['output_tokens'] ?? 0,
                ],
                'model' => $data['model'] ?? null,
                'stop_reason' => $data['stop_reason'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::error('Claude API call failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $baseUrl = rtrim($this->getConfig('base_url', 'https://api.anthropic.com'), '/');
            $this->client = new Client([
                'base_uri' => $baseUrl,
            ]);
        }

        return $this->client;
    }

    protected function getHeaders(): array
    {
        return [
            'x-api-key' => $this->getConfig('api_key', ''),
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ];
    }
}

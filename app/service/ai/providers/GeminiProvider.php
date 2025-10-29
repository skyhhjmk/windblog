<?php

declare(strict_types=1);

namespace app\service\ai\providers;

use app\service\ai\BaseAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use support\Log;

/**
 * Google Gemini 提供者
 */
class GeminiProvider extends BaseAiProvider
{
    protected ?Client $client = null;

    public function getId(): string
    {
        return 'gemini';
    }

    public function getName(): string
    {
        return 'Google Gemini';
    }

    public function getType(): string
    {
        return 'gemini';
    }

    public function getDescription(): string
    {
        return 'Google Gemini API - 强大的多模态AI模型';
    }

    public function getIcon(): string
    {
        return '✨';
    }

    public function getPresetModels(): array
    {
        return [
            [
                'id' => 'gemini-2.0-flash-exp',
                'name' => 'Gemini 2.0 Flash (Experimental)',
                'description' => '最新实验版本，速度快且功能强大',
                'context_window' => 1000000,
            ],
            [
                'id' => 'gemini-1.5-pro',
                'name' => 'Gemini 1.5 Pro',
                'description' => '最强大的Gemini模型，支持超长上下文',
                'context_window' => 2000000,
            ],
            [
                'id' => 'gemini-1.5-flash',
                'name' => 'Gemini 1.5 Flash',
                'description' => '速度快，适合大多数任务',
                'context_window' => 1000000,
            ],
            [
                'id' => 'gemini-pro',
                'name' => 'Gemini Pro',
                'description' => '通用文本生成模型',
                'context_window' => 32000,
            ],
            [
                'id' => 'gemini-pro-vision',
                'name' => 'Gemini Pro Vision',
                'description' => '支持图像和文本输入',
                'context_window' => 16000,
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'gemini-1.5-flash';
    }

    public function getSupportedTasks(): array
    {
        return ['summarize', 'translate', 'chat', 'generate', 'analyze'];
    }

    public function getSupportedFeatures(): array
    {
        return [
            'streaming' => true,
            'multimodal' => ['text', 'image', 'audio', 'video'],
            'function_calling' => true,
            'deep_thinking' => false,
            'long_context' => true,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true, 'default' => 'https://generativelanguage.googleapis.com', 'placeholder' => 'https://generativelanguage.googleapis.com'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'placeholder' => 'AIza...'],
            ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'default' => 'gemini-1.5-flash', 'options' => 'auto'],
            ['key' => 'custom_model_id', 'label' => '自定义模型ID', 'type' => 'text', 'required' => false, 'placeholder' => '留空则使用上面选择的模型'],
            ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7, 'min' => 0, 'max' => 2, 'step' => 0.1],
            ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false, 'default' => 1000],
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
            Log::error('Gemini Provider error: ' . $e->getMessage(), [
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

        return $this->generateContent($prompt . "\n\n" . $content, $options);
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

        return $this->generateContent($prompt, $options);
    }

    protected function doChat(array $params, array $options): array
    {
        $message = (string) ($params['message'] ?? '');
        if (empty($message) && isset($params['messages'])) {
            // 简单处理多轮对话，只取最后一条
            $messages = $params['messages'];
            $lastMessage = end($messages);
            $message = $lastMessage['content'] ?? '';
        }

        return $this->generateContent($message, $options);
    }

    protected function doGenerate(array $params, array $options): array
    {
        $prompt = (string) ($params['prompt'] ?? '');

        return $this->generateContent($prompt, $options);
    }

    protected function doAnalyze(array $params, array $options): array
    {
        $content = (string) ($params['content'] ?? '');
        $analysisType = (string) ($params['analysis_type'] ?? 'general');

        $prompt = "请分析以下内容({$analysisType})：\n\n{$content}";

        return $this->generateContent($prompt, $options);
    }

    protected function generateContent(string $prompt, array $options = []): array
    {
        $client = $this->getClient();
        $model = $this->getModel($options);

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $this->getTemperature($options),
            ],
        ];

        $maxTokens = $this->getMaxTokens($options);
        if ($maxTokens !== null) {
            $body['generationConfig']['maxOutputTokens'] = $maxTokens;
        }

        try {
            $apiKey = $this->getConfig('api_key', '');
            $response = $client->post("/v1/models/{$model}:generateContent", [
                'query' => ['key' => $apiKey],
                'json' => $body,
                'timeout' => $this->getTimeout(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                return ['ok' => false, 'error' => 'Invalid response format'];
            }

            return [
                'ok' => true,
                'result' => $data['candidates'][0]['content']['parts'][0]['text'],
                'usage' => [
                    'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                    'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                    'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0,
                ],
                'finish_reason' => $data['candidates'][0]['finishReason'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::error('Gemini API call failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $baseUrl = rtrim($this->getConfig('base_url', 'https://generativelanguage.googleapis.com'), '/');
            $this->client = new Client([
                'base_uri' => $baseUrl,
            ]);
        }

        return $this->client;
    }
}

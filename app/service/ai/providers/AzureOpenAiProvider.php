<?php

declare(strict_types=1);

namespace app\service\ai\providers;

/**
 * Azure OpenAI 提供者
 * 使用Azure托管的OpenAI服务
 */
class AzureOpenAiProvider extends OpenAiProvider
{
    public function getId(): string
    {
        return 'azure_openai';
    }

    public function getName(): string
    {
        return 'Azure OpenAI';
    }

    public function getType(): string
    {
        return 'azure_openai';
    }

    public function getDescription(): string
    {
        return 'Azure托管的OpenAI服务 - 企业级可靠性和安全性';
    }

    public function getIcon(): string
    {
        return '☁️';
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'Azure Endpoint', 'type' => 'text', 'required' => true, 'placeholder' => 'https://your-resource.openai.azure.com'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['key' => 'api_version', 'label' => 'API Version', 'type' => 'text', 'required' => true, 'default' => '2024-02-15-preview', 'placeholder' => '2024-02-15-preview'],
            ['key' => 'deployment_name', 'label' => 'Deployment Name', 'type' => 'text', 'required' => true, 'placeholder' => 'your-deployment-name'],
            ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7, 'min' => 0, 'max' => 2, 'step' => 0.1],
            ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false, 'default' => 1000],
            ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false, 'default' => 30],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['base_url'])) {
            $errors[] = 'Azure Endpoint 不能为空';
        }

        if (empty($config['api_key'])) {
            $errors[] = 'API Key 不能为空';
        }

        if (empty($config['api_version'])) {
            $errors[] = 'API Version 不能为空';
        }

        if (empty($config['deployment_name'])) {
            $errors[] = 'Deployment Name 不能为空';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    protected function callChatCompletion(array $messages, array $options): array
    {
        $client = $this->getClient();
        $deploymentName = $this->getConfig('deployment_name', '');

        $body = [
            'messages' => $messages,
            'temperature' => $this->getTemperature($options),
        ];

        $maxTokens = $this->getMaxTokens($options);
        if ($maxTokens !== null) {
            $body['max_tokens'] = $maxTokens;
        }

        try {
            $apiVersion = $this->getConfig('api_version', '2024-02-15-preview');
            $response = $client->post("/openai/deployments/{$deploymentName}/chat/completions", [
                'query' => ['api-version' => $apiVersion],
                'headers' => $this->getHeaders(),
                'json' => $body,
                'timeout' => $this->getTimeout(),
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
        } catch (\Throwable $e) {
            \support\Log::error('Azure OpenAI API call failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function getHeaders(): array
    {
        return [
            'api-key' => $this->getConfig('api_key', ''),
            'Content-Type' => 'application/json',
        ];
    }
}

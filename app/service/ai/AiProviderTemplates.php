<?php

declare(strict_types=1);

namespace app\service\ai;

/**
 * AI提供方模板管理
 * 类似于邮件系统的预设配置，提供常见AI服务商的快速配置模板
 */
class AiProviderTemplates
{
    /**
     * 获取所有可用模板
     */
    public static function getTemplates(): array
    {
        return [
            'openai' => [
                'name' => 'OpenAI',
                'type' => 'openai',
                'description' => 'OpenAI 官方 API（GPT 系列）',
                'icon' => '🤖',
                'config_template' => [
                    'base_url' => 'https://api.openai.com/v1',
                    'api_key' => '',
                    'model' => 'gpt-3.5-turbo',
                    'temperature' => 0.7,
                    'max_tokens' => 1000,
                    'timeout' => 30,
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                    ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'options' => 'auto'],
                    ['key' => 'custom_model_id', 'label' => '自定义模型ID', 'type' => 'text', 'required' => false],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 2, 'step' => 0.1],
                    ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false],
                    ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false],
                ],
            ],
            'azure_openai' => [
                'name' => 'Azure OpenAI',
                'type' => 'azure_openai',
                'description' => 'Azure 托管的 OpenAI 服务',
                'icon' => '☁️',
                'config_template' => [
                    'base_url' => 'https://your-resource.openai.azure.com/openai/deployments/your-deployment',
                    'api_key' => '',
                    'api_version' => '2024-02-15-preview',
                    'deployment_name' => '',
                    'temperature' => 0.7,
                    'max_tokens' => 1000,
                    'timeout' => 30,
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'Endpoint URL', 'type' => 'text', 'required' => true, 'placeholder' => 'https://your-resource.openai.azure.com'],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                    ['key' => 'api_version', 'label' => 'API Version', 'type' => 'text', 'required' => true],
                    ['key' => 'deployment_name', 'label' => 'Deployment Name', 'type' => 'text', 'required' => true],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 2, 'step' => 0.1],
                    ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false],
                    ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false],
                ],
            ],
            'claude' => [
                'name' => 'Claude (Anthropic)',
                'type' => 'claude',
                'description' => 'Anthropic Claude API',
                'icon' => '🧠',
                'config_template' => [
                    'base_url' => 'https://api.anthropic.com/v1',
                    'api_key' => '',
                    'model' => 'claude-3-5-sonnet-20241022',
                    'temperature' => 0.7,
                    'max_tokens' => 1000,
                    'timeout' => 30,
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                    ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'options' => [
                        'claude-3-5-sonnet-20241022',
                        'claude-3-opus-20240229',
                        'claude-3-sonnet-20240229',
                        'claude-3-haiku-20240307',
                    ]],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 2, 'step' => 0.1],
                    ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false],
                    ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false],
                ],
            ],
            'gemini' => [
                'name' => 'Google Gemini',
                'type' => 'gemini',
                'description' => 'Google Gemini API',
                'icon' => '✨',
                'config_template' => [
                    'base_url' => 'https://generativelanguage.googleapis.com/v1',
                    'api_key' => '',
                    'model' => 'gemini-pro',
                    'temperature' => 0.7,
                    'max_tokens' => 1000,
                    'timeout' => 30,
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                    ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'options' => [
                        'gemini-pro',
                        'gemini-pro-vision',
                        'gemini-ultra',
                    ]],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 2, 'step' => 0.1],
                    ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false],
                    ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false],
                ],
            ],
            'deepseek' => [
                'name' => 'DeepSeek',
                'type' => 'openai', // 兼容 OpenAI 接口
                'description' => 'DeepSeek API（兼容OpenAI格式）',
                'icon' => '🔍',
                'config_template' => [
                    'base_url' => 'https://api.deepseek.com/v1',
                    'api_key' => '',
                    'model' => 'deepseek-chat',
                    'temperature' => 0.7,
                    'max_tokens' => 1000,
                    'timeout' => 30,
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                    ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'options' => [
                        'deepseek-chat',
                        'deepseek-coder',
                    ]],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 2, 'step' => 0.1],
                    ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false],
                    ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false],
                ],
            ],
            'zhipu' => [
                'name' => '智谱AI (GLM)',
                'type' => 'openai', // 兼容 OpenAI 接口
                'description' => '智谱AI ChatGLM API（兼容OpenAI格式）',
                'icon' => '🎓',
                'config_template' => [
                    'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
                    'api_key' => '',
                    'model' => 'glm-4',
                    'temperature' => 0.7,
                    'max_tokens' => 1000,
                    'timeout' => 30,
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                    ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'options' => [
                        'glm-4',
                        'glm-4-plus',
                        'glm-3-turbo',
                    ]],
                    ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 2, 'step' => 0.1],
                    ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false],
                    ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false],
                ],
            ],
            'local_echo' => [
                'name' => '本地占位提供者',
                'type' => 'local',
                'description' => '本地占位提供者（调试用，不调用外部API）',
                'icon' => '🔧',
                'config_template' => [
                    'max_chars' => 300,
                ],
                'fields' => [
                    ['key' => 'max_chars', 'label' => '最大字符数', 'type' => 'number', 'required' => false, 'default' => 300],
                ],
            ],
            'custom' => [
                'name' => '自定义提供方',
                'type' => 'custom',
                'description' => '自定义配置的AI提供方',
                'icon' => '⚙️',
                'config_template' => [
                    'base_url' => '',
                    'api_key' => '',
                    'model' => '',
                    'timeout' => 30,
                ],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                    ['key' => 'model', 'label' => '模型名称', 'type' => 'text', 'required' => true],
                    ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false],
                ],
            ],
        ];
    }

    /**
     * 获取指定模板
     */
    public static function getTemplate(string $templateId): ?array
    {
        $templates = self::getTemplates();

        return $templates[$templateId] ?? null;
    }

    /**
     * 根据模板生成配置
     */
    public static function generateConfig(string $templateId, array $userConfig = []): array
    {
        $template = self::getTemplate($templateId);
        if (!$template) {
            return $userConfig;
        }

        // 合并模板默认配置和用户配置
        return array_merge($template['config_template'] ?? [], $userConfig);
    }

    /**
     * 获取模板列表（简化版，用于前端选择）
     */
    public static function getTemplateList(): array
    {
        $templates = self::getTemplates();
        $list = [];

        foreach ($templates as $id => $template) {
            $list[] = [
                'id' => $id,
                'name' => $template['name'],
                'description' => $template['description'] ?? '',
                'icon' => $template['icon'] ?? '🤖',
                'type' => $template['type'],
            ];
        }

        return $list;
    }
}

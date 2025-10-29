<?php

declare(strict_types=1);

namespace app\service\ai\providers;

/**
 * DeepSeek 提供者
 * 兼容OpenAI API格式
 */
class DeepSeekProvider extends OpenAiProvider
{
    public function getId(): string
    {
        return 'deepseek';
    }

    public function getName(): string
    {
        return 'DeepSeek';
    }

    public function getType(): string
    {
        return 'deepseek';
    }

    public function getDescription(): string
    {
        return 'DeepSeek API - 高性价比的AI模型（兼容OpenAI格式）';
    }

    public function getIcon(): string
    {
        return '🔍';
    }

    public function getPresetModels(): array
    {
        return [
            [
                'id' => 'deepseek-chat',
                'name' => 'DeepSeek Chat',
                'description' => '通用对话模型',
                'context_window' => 32000,
            ],
            [
                'id' => 'deepseek-coder',
                'name' => 'DeepSeek Coder',
                'description' => '专注代码生成的模型',
                'context_window' => 16000,
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'deepseek-chat';
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => true, 'default' => 'https://api.deepseek.com/v1', 'placeholder' => 'https://api.deepseek.com/v1'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'placeholder' => 'sk-...'],
            ['key' => 'model', 'label' => '模型', 'type' => 'select', 'required' => true, 'default' => 'deepseek-chat', 'options' => 'auto'],
            ['key' => 'custom_model_id', 'label' => '自定义模型ID', 'type' => 'text', 'required' => false, 'placeholder' => '留空则使用上面选择的模型'],
            ['key' => 'temperature', 'label' => '温度', 'type' => 'number', 'required' => false, 'default' => 0.7, 'min' => 0, 'max' => 2, 'step' => 0.1],
            ['key' => 'max_tokens', 'label' => '最大Token数', 'type' => 'number', 'required' => false, 'default' => 1000],
            ['key' => 'timeout', 'label' => '超时（秒）', 'type' => 'number', 'required' => false, 'default' => 30],
        ];
    }
}

<?php

declare(strict_types=1);

namespace app\service\ai;

/**
 * AI提供者抽象基类
 * 提供通用实现，减少各Provider的重复代码
 */
abstract class BaseAiProvider implements AiProviderInterface
{
    protected array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * 获取提供者描述（子类可覆盖）
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * 获取提供者图标（子类可覆盖）
     */
    public function getIcon(): string
    {
        return '🤖';
    }

    /**
     * 获取预置模型列表（子类应覆盖）
     */
    public function getPresetModels(): array
    {
        return [];
    }

    /**
     * 获取默认模型ID（子类应覆盖）
     */
    public function getDefaultModel(): string
    {
        return '';
    }

    /**
     * 获取支持的功能特性（子类可覆盖）
     */
    public function getSupportedFeatures(): array
    {
        return [
            'streaming' => false,
            'multimodal' => ['text'],
            'function_calling' => false,
            'deep_thinking' => false,
        ];
    }

    /**
     * 从API获取可用模型列表（子类可覆盖）
     */
    public function fetchModels(): array
    {
        return [
            'ok' => false,
            'error' => 'fetchModels not implemented for this provider',
        ];
    }

    /**
     * 获取配置值
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * 获取HTTP超时时间
     */
    protected function getTimeout(): int
    {
        return (int) $this->getConfig('timeout', 30);
    }

    /**
     * 获取温度参数
     */
    protected function getTemperature(array $options = []): float
    {
        return (float) ($options['temperature'] ?? $this->getConfig('temperature', 0.7));
    }

    /**
     * 获取最大token数
     */
    protected function getMaxTokens(array $options = []): ?int
    {
        $maxTokens = $options['max_tokens'] ?? $this->getConfig('max_tokens');

        return $maxTokens !== null ? (int) $maxTokens : null;
    }

    /**
     * 获取模型ID（优先使用自定义模型ID）
     */
    protected function getModel(array $options = []): string
    {
        // 优先使用options中的model
        if (!empty($options['model'])) {
            return (string) $options['model'];
        }

        // 其次使用自定义模型ID
        $customModel = $this->getConfig('custom_model_id');
        if (!empty($customModel)) {
            return (string) $customModel;
        }

        // 最后使用配置的model
        $model = $this->getConfig('model');
        if (!empty($model)) {
            return (string) $model;
        }

        // 返回默认模型
        return $this->getDefaultModel();
    }

    /**
     * 默认流式调用实现（子类可以覆盖）
     * 默认实现不支持流式，返回 false
     */
    public function callStream(string $task, array $params = [], array $options = []): \Generator|false
    {
        // 默认不支持流式输出，返回 false
        // 子类如果支持流式输出，需要覆盖此方法
        return false;
    }
}

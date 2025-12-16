<?php

declare(strict_types=1);

namespace app\service\ai;

use Generator;

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
     * 获取提供者唯一ID
     */
    public function getId(): string
    {
        return (string) ($this->getConfig('id') ?? $this->getType());
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
     * 从文本中抽离 <think>...</think> 内容，并返回清理后的正文
     *
     * @return array{content:string,thinking:string}
     */
    protected function extractThinkFromText(string $text): array
    {
        $thinking = '';

        // 提取并移除所有 <think>...</think> 片段（大小写不敏感，跨行）
        if (preg_match_all('/<think>([\s\S]*?)<\/think>/i', $text, $matches)) {
            $parts = array_map(static function ($s) {
                return trim($s);
            }, $matches[1]);
            $thinking = trim(implode("\n\n", $parts));
            $text = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $text) ?? $text;
        }

        // 兼容性处理：去除可能出现的 <reasoning> 包裹标签（极少数提供方会输出）
        $text = preg_replace('/<\/?reason(?:ing)?>/i', '', $text) ?? $text;

        return [
            'content' => trim($text),
            'thinking' => $thinking,
        ];
    }

    /**
     * 校验 <think></think> 标签是否成对、顺序正确
     * - 若文本中不存在任何 <think> 或 </think>，视为有效
     * - 若存在，则需满足：打开数==关闭数且顺序不出现先关后开
     *
     * @return array{valid:bool,error?:string}
     */
    protected function validateThinkBlocks(string $text): array
    {
        $openCount = preg_match_all('/<think>/i', $text, $m1);
        $closeCount = preg_match_all('/<\/think>/i', $text, $m2);

        // 无任何标签，视为有效
        if (($openCount === 0) && ($closeCount === 0)) {
            return ['valid' => true];
        }

        // 数量不相等或没有成对出现
        if ($openCount !== $closeCount || $openCount === 0) {
            return [
                'valid' => false,
                'error' => "AI 响应格式错误：<think></think> 标签不完整或数量不匹配（open={$openCount}, close={$closeCount}）",
            ];
        }

        // 顺序校验：不允许先出现关闭标签，且最终平衡为0
        $balance = 0;
        if (preg_match_all('/<\/?think>/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $token) {
                $tag = strtolower($token[0]);
                if ($tag === '<think>') {
                    $balance++;
                } elseif ($tag === '</think>') {
                    $balance--;
                    if ($balance < 0) {
                        return [
                            'valid' => false,
                            'error' => 'AI 响应格式错误：<think></think> 标签闭合顺序不正确',
                        ];
                    }
                }
            }
        }

        if ($balance !== 0) {
            return [
                'valid' => false,
                'error' => 'AI 响应格式错误：<think></think> 标签未正确闭合',
            ];
        }

        return ['valid' => true];
    }

    /**
     * 默认流式调用实现（子类可以覆盖）
     * 默认实现不支持流式，返回 false
     */
    public function callStream(string $task, array $params = [], array $options = []): Generator|false
    {
        // 默认不支持流式输出，返回 false
        // 子类如果支持流式输出，需要覆盖此方法
        yield from [];
    }
}

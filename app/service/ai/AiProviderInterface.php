<?php

declare(strict_types=1);

namespace app\service\ai;

/**
 * AI 提供者通用接口
 * 设计为可复用的通用AI能力接口，支持多种AI任务（摘要、翻译、问答、生成等）
 */
interface AiProviderInterface
{
    /**
     * 返回提供者ID（配置中使用）
     */
    public function getId(): string;

    /**
     * 返回人类可读名称
     */
    public function getName(): string;

    /**
     * 返回提供者类型（openai/claude/azure/gemini/custom等）
     */
    public function getType(): string;

    /**
     * 通用AI调用方法
     *
     * @param string $task    任务类型（summarize/translate/chat/generate/classify等）
     * @param array  $params  任务参数，根据不同task传入不同结构
     * @param array  $options 调用选项（模型、温度、最大token等）
     *
     * @return array{ok:bool, result?:mixed, error?:string, usage?:array}
     */
    public function call(string $task, array $params = [], array $options = []): array;

    /**
     * 获取该提供者支持的任务类型列表
     *
     * @return string[] 如 ['summarize', 'translate', 'chat', 'generate']
     */
    public function getSupportedTasks(): array;

    /**
     * 获取配置字段定义（用于后台动态生成配置表单）
     *
     * @return array 配置字段结构，例如：
     * [
     *   ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
     *   ['key' => 'base_url', 'label' => 'API 基址', 'type' => 'text', 'required' => false],
     *   ['key' => 'model', 'label' => '模型', 'type' => 'select', 'options' => ['gpt-4', 'gpt-3.5'], 'required' => true],
     * ]
     */
    public function getConfigFields(): array;

    /**
     * 验证配置是否有效
     *
     * @param array $config 配置数组
     *
     * @return array{valid:bool, errors?:array}
     */
    public function validateConfig(array $config): array;
}

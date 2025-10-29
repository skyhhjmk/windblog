<?php

declare(strict_types=1);

namespace app\service\ai;

use Generator;

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
     * 获取提供者描述
     */
    public function getDescription(): string;

    /**
     * 获取提供者图标
     */
    public function getIcon(): string;

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
     * 流式AI调用方法
     *
     * @param string $task    任务类型（summarize/translate/chat/generate/classify等）
     * @param array  $params  任务参数，根据不同task传入不同结构
     * @param array  $options 调用选项（模型、温度、最大token等）
     *
     * @return Generator|false 返回生成器用于流式输出，失败返回false
     */
    public function callStream(string $task, array $params = [], array $options = []): Generator|false;

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
     * 获取预置模型列表
     *
     * @return array 模型列表，例如：
     * [
     *   ['id' => 'gpt-4', 'name' => 'GPT-4', 'description' => '最先进的模型'],
     *   ['id' => 'gpt-3.5-turbo', 'name' => 'GPT-3.5 Turbo', 'description' => '快速且高效'],
     * ]
     */
    public function getPresetModels(): array;

    /**
     * 获取默认模型ID
     */
    public function getDefaultModel(): string;

    /**
     * 获取支持的功能特性
     *
     * @return array 功能特性列表，例如：
     * [
     *   'streaming' => true,          // 是否支持流式输出
     *   'multimodal' => ['text', 'image'],  // 支持的模态
     *   'function_calling' => true,   // 是否支持函数调用
     *   'deep_thinking' => false,     // 是否支持深度思考
     * ]
     */
    public function getSupportedFeatures(): array;

    /**
     * 验证配置是否有效
     *
     * @param array $config 配置数组
     *
     * @return array{valid:bool, errors?:array}
     */
    public function validateConfig(array $config): array;

    /**
     * 从API获取可用模型列表（如果支持）
     *
     * @return array{ok:bool, models?:array, error?:string}
     */
    public function fetchModels(): array;
}

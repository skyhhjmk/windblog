<?php

declare(strict_types=1);

namespace app\service\ai\providers;

use app\service\ai\BaseAiProvider;

/**
 * 本地占位提供者：不调用外部平台，直接基于内容生成简单结果
 * 便于初期联调，可替换为真实平台（OpenAI/Claude/Azure/自建等）
 */
class LocalEchoProvider extends BaseAiProvider
{
    public function getId(): string
    {
        return 'local.echo';
    }

    public function getName(): string
    {
        return '本地占位提供者（调试用）';
    }

    public function getType(): string
    {
        return 'local';
    }

    public function getDescription(): string
    {
        return '本地占位提供者（调试用，不调用外部API）';
    }

    public function getIcon(): string
    {
        return '🔧';
    }

    public function getPresetModels(): array
    {
        return [
            [
                'id' => 'local-echo',
                'name' => 'Local Echo',
                'description' => '本地模拟模型',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'local-echo';
    }

    public function call(string $task, array $params = [], array $options = []): array
    {
        switch ($task) {
            case 'summarize':
                return $this->doSummarize($params, $options);
            case 'translate':
                return $this->doTranslate($params, $options);
            case 'chat':
                return $this->doChat($params, $options);
            default:
                return ['ok' => false, 'error' => 'Unsupported task: ' . $task];
        }
    }

    public function getSupportedTasks(): array
    {
        return ['summarize', 'translate', 'chat'];
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'max_chars', 'label' => '最大字符数', 'type' => 'number', 'required' => false, 'default' => 300],
        ];
    }

    public function validateConfig(array $config): array
    {
        return ['valid' => true];
    }

    protected function doSummarize(array $params, array $options): array
    {
        $content = (string) ($params['content'] ?? '');
        $max = (int) ($this->getConfig('max_chars', 300));
        $clean = trim(strip_tags($content));
        $summary = mb_substr($clean, 0, max(50, $max));
        if (mb_strlen($clean) > $max) {
            $summary .= '...';
        }

        return [
            'ok' => true,
            'result' => $summary,
            'usage' => ['prompt_chars' => mb_strlen($clean), 'result_chars' => mb_strlen($summary)],
        ];
    }

    protected function doTranslate(array $params, array $options): array
    {
        $text = (string) ($params['text'] ?? '');

        return ['ok' => true, 'result' => '[LocalEcho] Translated: ' . $text];
    }

    protected function doChat(array $params, array $options): array
    {
        $message = (string) ($params['message'] ?? '');

        return ['ok' => true, 'result' => '[LocalEcho] Echo: ' . $message];
    }
}

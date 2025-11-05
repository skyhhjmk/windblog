<?php

declare(strict_types=1);

namespace app\service;

use support\Log;
use Throwable;

/**
 * Slug翻译服务
 * 支持百度翻译和AI生成两种方式
 */
class SlugTranslateService
{
    /**
     * 百度翻译服务实例
     */
    private ?BaiduTranslateService $baiduService = null;

    /**
     * AI摘要服务
     */
    private ?AISummaryService $aiService = null;

    /**
     * 测试翻译配置
     *
     * @param string      $mode        翻译模式
     * @param string|null $aiSelection AI选择
     *
     * @return array ['success' => bool, 'message' => string, 'result' => string]
     */
    public function testConfig(string $mode, ?string $aiSelection = null): array
    {
        $testText = '测试文章标题';

        try {
            $result = $this->translate($testText, [
                'mode' => $mode,
                'ai_selection' => $aiSelection,
            ]);

            if ($result !== null) {
                return [
                    'success' => true,
                    'message' => '翻译测试成功',
                    'result' => $result,
                ];
            }

            return [
                'success' => false,
                'message' => '翻译测试失败，请检查配置',
                'result' => '',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => '翻译测试异常: ' . $e->getMessage(),
                'result' => '',
            ];
        }
    }

    /**
     * 翻译文本为slug（自动选择翻译方式）
     *
     * @param string $text    需要翻译的文本
     * @param array  $options 选项配置
     *                        - mode: 'baidu'(仅百度), 'ai'(仅AI), 'auto'(百度优先，失败则AI)
     *                        - ai_selection: AI提供者选择 如 'provider:ai_xxx' 或 'group:1'
     *
     * @return string|null 翻译后的英文文本
     */
    public function translate(string $text, array $options = []): ?string
    {
        // 获取翻译模式
        $mode = $options['mode'] ?? $this->getDefaultMode();

        // 如果文本已经是英文，直接返回
        if ($this->isEnglish($text)) {
            return $this->formatAsSlug($text);
        }

        switch ($mode) {
            case 'baidu':
                // 仅使用百度翻译
                return $this->translateWithBaidu($text);

            case 'ai':
                // 仅使用AI生成
                $aiSelection = $options['ai_selection'] ?? null;

                return $this->translateWithAI($text, $aiSelection);

            case 'auto':
            default:
                // 百度翻译优先，失败则使用AI
                $result = $this->translateWithBaidu($text);
                if ($result === null) {
                    Log::info('百度翻译失败，尝试使用AI生成slug');
                    $aiSelection = $options['ai_selection'] ?? null;
                    $result = $this->translateWithAI($text, $aiSelection);
                }

                return $result;
        }
    }

    /**
     * 获取默认翻译模式
     *
     * @return string
     */
    private function getDefaultMode(): string
    {
        try {
            return blog_config('slug_translate_mode', 'auto', true);
        } catch (Throwable $e) {
            return 'auto';
        }
    }

    /**
     * 检查文本是否为英文
     *
     * @param string $text
     *
     * @return bool
     */
    private function isEnglish(string $text): bool
    {
        // 如果文本主要由ASCII字符组成（至少80%），则认为是英文
        $asciiCount = 0;
        $totalCount = mb_strlen($text);

        for ($i = 0; $i < $totalCount; $i++) {
            $char = mb_substr($text, $i, 1);
            if (ord($char) < 128) {
                $asciiCount++;
            }
        }

        return $totalCount > 0 && ($asciiCount / $totalCount) >= 0.8;
    }

    /**
     * 格式化文本为slug格式
     *
     * @param string $text
     *
     * @return string
     */
    private function formatAsSlug(string $text): string
    {
        // 转换为小写
        $slug = strtolower(trim($text));

        // 将空格和特殊字符替换为连字符
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug);

        // 移除多余的连字符
        $slug = preg_replace('/-+/', '-', $slug);

        // 移除开头和结尾的连字符
        $slug = trim($slug, '-');

        // 如果结果为空，返回随机字符串
        if (empty($slug)) {
            return 'slug-' . time();
        }

        return $slug;
    }

    /**
     * 使用百度翻译
     *
     * @param string $text
     *
     * @return string|null
     */
    private function translateWithBaidu(string $text): ?string
    {
        try {
            if ($this->baiduService === null) {
                $this->baiduService = new BaiduTranslateService();
            }

            $translated = $this->baiduService->translateToEnglish($text);

            if ($translated !== null) {
                return $this->formatAsSlug($translated);
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Baidu translate error in SlugTranslateService: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 使用AI生成slug
     *
     * @param string      $text        原文本
     * @param string|null $aiSelection AI提供者选择
     *
     * @return string|null
     */
    private function translateWithAI(string $text, ?string $aiSelection = null): ?string
    {
        try {
            // 如果指定了AI选择，临时保存并恢复
            $originalSelection = null;
            if ($aiSelection !== null) {
                $originalSelection = blog_config('ai_current_selection', '', true);
                // 临时设置AI选择（不保存到数据库）
                // 注意：这里我们不能直接修改全局配置，需要直接使用指定的provider
            }

            // 构建优质提示词
            $prompt = $this->buildSlugPrompt($text);

            // 获取AI提供者
            if ($aiSelection !== null) {
                $provider = $this->getProviderBySelection($aiSelection);
            } else {
                $provider = AISummaryService::getCurrentProvider();
            }

            if ($provider === null) {
                Log::warning('No AI provider available for slug generation');

                return null;
            }

            // 调用AI生成
            $result = $provider->call('chat', [
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ], [
                'temperature' => 0.3,
                'max_tokens' => 100,
            ]);

            if (!$result['ok'] || empty($result['result'])) {
                Log::error('AI provider call failed: ' . ($result['error'] ?? 'Unknown error'));

                return null;
            }

            $response = $result['result'];

            // 解析JSON响应
            $result = $this->parseAIResponse($response);

            if ($result !== null) {
                return $this->formatAsSlug($result);
            }

            return null;
        } catch (Throwable $e) {
            Log::error('AI translate error in SlugTranslateService: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 构建AI提示词（返回JSON格式）
     *
     * @param string $text
     *
     * @return string
     */
    private function buildSlugPrompt(string $text): string
    {
        return <<<PROMPT
You are a professional content translator and SEO expert. Your task is to translate the following text into an English slug suitable for URLs.

Requirements:
1. Translate the text into English
2. Convert to lowercase
3. Replace spaces with hyphens (-)
4. Remove special characters (keep only letters, numbers, and hyphens)
5. Make it SEO-friendly and readable
6. Keep it concise (preferably under 60 characters)

Input text: "{$text}"

Please respond ONLY with a valid JSON object in this exact format:
{"slug": "your-translated-slug-here"}

Do not include any other text, explanation, or formatting. Just the JSON object.
PROMPT;
    }

    /**
     * 根据选择获取AI提供者
     *
     * @param string $selection 格式: 'provider:xxx' 或 'group:xxx'
     *
     * @return mixed
     */
    private function getProviderBySelection(string $selection)
    {
        if (str_starts_with($selection, 'provider:')) {
            $providerId = substr($selection, 9);

            return AISummaryService::createProviderFromDb($providerId);
        }

        if (str_starts_with($selection, 'group:')) {
            // 临时设置选择
            $originalSelection = blog_config('ai_current_selection', '', true);
            blog_config('ai_current_selection', $selection, true, false, false); // 不保存到数据库
            $provider = AISummaryService::getCurrentProvider();
            // 恢复原选择
            blog_config('ai_current_selection', $originalSelection, true, false, false);

            return $provider;
        }

        return null;
    }

    /**
     * 解析AI响应的JSON
     *
     * @param string $response
     *
     * @return string|null
     */
    private function parseAIResponse(string $response): ?string
    {
        try {
            // 清理响应（移除可能的markdown代码块标记）
            $response = trim($response);
            $response = preg_replace('/^```json\s*/i', '', $response);
            $response = preg_replace('/\s*```$/', '', $response);
            $response = trim($response);

            // 解析JSON
            $data = json_decode($response, true);

            if (isset($data['slug']) && is_string($data['slug'])) {
                return trim($data['slug']);
            }

            // 如果JSON解析失败，尝试直接使用响应内容
            Log::warning('AI response is not valid JSON, using raw response: ' . $response);

            return trim($response);
        } catch (Throwable $e) {
            Log::error('Failed to parse AI response: ' . $e->getMessage());

            return null;
        }
    }
}

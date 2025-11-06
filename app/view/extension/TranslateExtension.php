<?php

namespace app\view\extension;

use Exception;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Class TranslateExtension
 *
 * Twig模板翻译扩展，提供trans()函数用于模板中的文本翻译
 *
 * 注意：
 * - trans() 函数会根据客户端的语言设置进行翻译，适用于前端显示和API响应
 * - 在日志输出中不应使用 trans()，应使用 trans_log() 或直接使用中文
 * - trans_log() 始终使用系统默认语言(zh_CN)，确保日志内容的一致性
 */
class TranslateExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('trans', [$this, 'getTranslate']),
        ];
    }

    /**
     * 获取翻译文本
     *
     * 该函数根据session中的语言设置或传入的$lang参数进行翻译
     * 适用于前端显示和API响应，会响应客户端的语言设置
     *
     * @param string      $key    翻译键
     * @param string|null $lang   语言代码（如zh_CN、en_US），为null时使用session中的语言
     * @param array       $params 参数替换
     *
     * @return string 翻译后的文本
     * @throws Exception
     */
    public function getTranslate(string $key, ?string $lang = null, ?array $params = []): string
    {
        if ($lang === null) {
            $lang = session('lang', 'zh_CN');
        }

        return trans($key, $params, null, $lang);
    }
}

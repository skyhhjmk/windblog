<?php

namespace app\middleware;

use Exception;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 语言中间件
 *
 * 支持多种语言检测方式（按优先级）：
 * 1. Cookie (locale) - 用户主动选择的语言
 * 2. Session (lang) - 会话中的语言设置
 * 3. Query参数 (lang) - URL参数指定语言
 * 4. Accept-Language请求头 - 浏览器语言偏好
 * 5. 系统默认语言 - 配置中的默认值
 */
class Lang implements MiddlewareInterface
{
    /**
     * 支持的语言列表（从 blog_config 动态读取）
     */
    private ?array $supportedLocales = null;

    public function process(Request $request, callable $handler): Response
    {
        $locale = $this->detectLocale($request);

        // 设置应用语言
        locale($locale);

        // 如果cookie中的语言与检测到的不同，同步到session
        if (session('lang') !== $locale) {
            session(['lang' => $locale]);
        }

        return $handler($request);
    }

    /**
     * 检测用户语言偏好
     *
     * @param Request $request
     *
     * @return string
     * @throws Exception
     */
    private function detectLocale(Request $request): string
    {
        // 1. 优先从Cookie读取（用户明确选择的语言）
        $cookieLocale = $request->cookie('locale');
        if ($cookieLocale && $this->isSupportedLocale($cookieLocale)) {
            return $cookieLocale;
        }

        // 2. 从Session读取（向后兼容）
        $sessionLang = session('lang');
        if ($sessionLang && $this->isSupportedLocale($sessionLang)) {
            return $sessionLang;
        }

        // 3. 从URL参数读取（临时切换语言，不保存）
        $queryLang = $request->get('lang');
        if ($queryLang && $this->isSupportedLocale($queryLang)) {
            return $queryLang;
        }

        // 4. 从Accept-Language请求头解析（浏览器偏好）
        $acceptLanguage = $request->header('accept-language');
        if ($acceptLanguage) {
            $browserLocale = $this->parseAcceptLanguage($acceptLanguage);
            if ($browserLocale) {
                return $browserLocale;
            }
        }

        // 5. 使用系统默认语言
        return $this->getDefaultLocale();
    }

    /**
     * 解析Accept-Language请求头
     * 支持 ISO 639-1 语言代码和 ISO 3166-1 地区代码
     *
     * @param string $acceptLanguage
     *
     * @return string|null
     */
    private function parseAcceptLanguage(string $acceptLanguage): ?string
    {
        // 解析格式: zh-CN,zh;q=0.9,en;q=0.8
        $languages = [];

        foreach (explode(',', $acceptLanguage) as $lang) {
            $parts = explode(';', trim($lang));
            // 保持原始格式，不转换连字符
            $locale = trim($parts[0]);
            $quality = 1.0;

            if (isset($parts[1]) && strpos($parts[1], 'q=') === 0) {
                $quality = (float) substr($parts[1], 2);
            }

            $languages[$locale] = $quality;
        }

        // 按质量值排序
        arsort($languages);

        // 找到第一个支持的语言
        foreach (array_keys($languages) as $locale) {
            // 精确匹配
            if ($this->isSupportedLocale($locale)) {
                return $locale;
            }

            // 模糊匹配（zh 匹配 zh-CN）
            $fuzzyMatch = $this->findFuzzyMatch($locale);
            if ($fuzzyMatch) {
                return $fuzzyMatch;
            }
        }

        return null;
    }

    /**
     * 获取支持的语言列表
     * 从 blog_config 动态读取
     *
     * @return array
     */
    private function getSupportedLocales(): array
    {
        if ($this->supportedLocales !== null) {
            return $this->supportedLocales;
        }

        try {
            // 从 I18nService 获取语言列表
            $locales = \app\service\I18nService::getAvailableLocales();

            // 提取语言代码
            $this->supportedLocales = array_map(function ($locale) {
                return $locale['code'] ?? '';
            }, $locales);

            // 过滤空值
            $this->supportedLocales = array_filter($this->supportedLocales);

            return $this->supportedLocales;
        } catch (\Throwable $e) {
            // 如果失败，返回默认语言列表
            $this->supportedLocales = ['zh-CN', 'en-US', 'ja-JP'];

            return $this->supportedLocales;
        }
    }

    /**
     * 模糊匹配语言代码
     * 例如：zh -> zh-CN, en -> en-US
     * 支持下划线和连字符两种格式
     *
     * @param string $locale
     *
     * @return string|null
     */
    private function findFuzzyMatch(string $locale): ?string
    {
        // 处理下划线和连字符两种格式
        $shortCode = strtolower(preg_split('/[_-]/', $locale)[0]);

        foreach ($this->getSupportedLocales() as $supported) {
            $supportedShort = strtolower(preg_split('/[_-]/', $supported)[0]);
            if ($shortCode === $supportedShort) {
                return $supported;
            }
        }

        return null;
    }

    /**
     * 检查语言是否支持
     *
     * @param string $locale
     *
     * @return bool
     */
    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, $this->getSupportedLocales(), true);
    }

    /**
     * 获取默认语言
     *
     * @return string
     */
    private function getDefaultLocale(): string
    {
        try {
            return (string) blog_config('default_locale', 'zh_CN', true);
        } catch (\Throwable $e) {
            return 'zh_CN';
        }
    }
}

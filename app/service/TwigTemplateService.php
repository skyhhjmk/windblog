<?php

declare(strict_types=1);

namespace app\service;

use function app_path;
use function array_merge;
use function base_path;
use function config;
use function is_array;
use function md5;
use function request;

use support\Log;
use Throwable;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Webman\View;

/**
 * Class TwigTemplateService
 * 实现了 Webman\View 接口，用于渲染 Twig 模板。
 */
class TwigTemplateService implements View
{
    /**
     * Assign.
     *
     * @param string|array $name
     * @param mixed        $value
     */
    public static function assign(string|array $name, mixed $value = null): void
    {
        $request = request();
        $request->_view_vars = array_merge((array) $request->_view_vars, is_array($name) ? $name : [$name => $value]);
    }

    /**
     * 获取可用的主题列表
     *
     * @param string $viewPath 视图路径
     * @return array 可用主题列表
     */
    private static function getAvailableThemes(string $viewPath): array
    {
        $availableThemes = ['default']; // default 主题总是可用
        $viewPathBase = rtrim($viewPath, '/') . '/';

        if (is_dir($viewPathBase)) {
            $directories = scandir($viewPathBase);
            if ($directories !== false) {
                foreach ($directories as $directory) {
                    // 跳过 . 和 .. 以及非目录项
                    if ($directory !== '.' && $directory !== '..' && is_dir($viewPathBase . $directory)) {
                        $availableThemes[] = $directory;
                    }
                }
            }
        }

        return array_unique($availableThemes);
    }

    /**
     * 验证并获取安全的主题名
     *
     * @param string $requestedTheme  请求的主题名
     * @param array  $availableThemes 可用主题列表
     * @param string $defaultTheme    默认主题名
     *
     * @return string 验证后的安全主题名
     */
    private static function validateTheme(string $requestedTheme, array $availableThemes, string $defaultTheme = 'default'): string
    {
        // 安全检查：确保主题名只包含字母、数字、连字符和下划线
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $requestedTheme)) {
            Log::warning("Invalid theme name format: $requestedTheme, falling back to default");

            return $defaultTheme;
        }

        // 检查主题是否存在
        if (in_array($requestedTheme, $availableThemes, true)) {
            return $requestedTheme;
        }

        Log::warning("Theme not found: $requestedTheme, available themes: " . implode(', ', $availableThemes) . ', falling back to default');

        return $defaultTheme;
    }

    /**
     * 生成模板缓存键
     *
     * @param string      $template 模板名称
     * @param array       $vars     变量数组
     * @param string      $theme    主题名称
     * @param string|null $app      应用名
     * @param string|null $plugin   插件名
     *
     * @return string 缓存键
     */
    private static function generateTemplateCacheKey(string $template, array $vars, string $theme, ?string $app = null, ?string $plugin = null): string
    {
        // 生成唯一标识符：包含模板名、主题、变量哈希
        $cacheKey = 'twig_template:' . ($plugin ? "plugin.$plugin:" : '') . ($app ? "$app:" : '') . $template . ':' . $theme;

        // 对变量进行哈希，确保变量变化时缓存失效
        $varsHash = md5(serialize($vars));
        $cacheKey .= ':' . $varsHash;

        return $cacheKey;
    }

    /**
     * 压缩HTML内容，清理无用空格和换行
     *
     * @param string $html HTML内容
     *
     * @return string 压缩后的HTML内容
     */
    private static function compressHtml(string $html): string
    {
        // 移除HTML注释（除了条件注释）
        $html = preg_replace('/<!--(?!\[if).*?-->/s', '', $html);

        // 移除多余的空白字符，但保留pre、textarea、script标签内的内容
        $html = preg_replace_callback('/<(pre|textarea|script)[^>]*>.*?<\/\1>/is', function ($matches) {
            return $matches[0]; // 保持原样
        }, $html);

        // 移除标签间的空白字符
        $html = preg_replace('/>\s+</', '><', $html);

        // 移除行首尾的空白字符
        $html = preg_replace('/^\s+|\s+$/m', '', $html);

        // 压缩CSS中的空白字符
        $html = preg_replace_callback('/<style[^>]*>.*?<\/style>/is', function ($matches) {
            return preg_replace('/\s+/', ' ', $matches[0]);
        }, $html);

        // 压缩JavaScript中的空白字符（简单处理）
        $html = preg_replace_callback('/<script[^>]*>.*?<\/script>/is', function ($matches) {
            return preg_replace('/\s+/', ' ', $matches[0]);
        }, $html);

        return trim($html);
    }

    /**
     * 获取HTML内容摘要用于日志记录
     *
     * @param string $html      HTML内容
     * @param int    $maxLength 最大长度
     *
     * @return string HTML摘要
     */
    private static function getHtmlSummary(string $html, int $maxLength = 200): string
    {
        $html = trim($html);
        if (strlen($html) <= $maxLength) {
            return $html;
        }

        return substr($html, 0, $maxLength) . '...[截断]';
    }

    /**
     * Render.
     *
     * @param string      $template Template namespace, such as: app/index, plugin/foo/index.
     *                              If the template is not in the plugin, it will be in the app.
     * @param array       $vars     Variables to pass to the template.
     * @param string|null $app      Application name, such as: app, admin, api, etc.
     * @param string|null $plugin   Plugin name, such as: foo, bar, etc.
     *
     * @return string
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public static function render(string $template, array $vars, ?string $app = null, ?string $plugin = null): string
    {
        static $views = [];
        $request = request();
        $plugin ??= ($request->plugin ?? '');
        $app ??= ($request->app ?? '');
        $configPrefix = $plugin ? "plugin.$plugin." : '';
        $viewSuffix = config("{$configPrefix}view.options.view_suffix", 'html');
        $baseViewPath = $plugin ? base_path() . "/plugin/$plugin/app" : app_path();
        if ($template[0] === '/') {
            $template = ltrim($template, '/');
            if (str_contains($template, '/view/')) {
                [$viewPath, $template] = explode('/view/', $template, 2);
                $viewPath = base_path("$viewPath/view");
            } else {
                $viewPath = base_path();
            }
        } else {
            $viewPath = $app === '' ? "$baseViewPath/view/" : "$baseViewPath/$app/view/";
        }

        // 获取可用主题列表
        $availableThemes = self::getAvailableThemes($viewPath);
        Log::debug('Available themes: ' . implode(', ', $availableThemes));

        // 解析主题，失败或空值均回退 default
        $theme = 'default';
        // 用户侧选择的主题
        $user_theme = $request->cookie('theme');
        try {
            if (!empty($user_theme)) {
                // 验证用户输入的主题
                $theme = self::validateTheme($user_theme, $availableThemes, 'default');
            } else {
                $t = blog_config('theme', 'default', true);
                if (is_string($t) && $t !== '') {
                    // 验证配置中的主题
                    $theme = self::validateTheme($t, $availableThemes, 'default');
                }
            }

            Log::debug("using theme: $theme");
        } catch (Throwable $e) {
            Log::error('theme resolution failed: ' . $e->getMessage() . ', falling back to default');
            $theme = 'default';
        }

        // 构建主题与回退路径
        $viewPathBase = rtrim($viewPath, '/') . '/';
        $viewPathTheme = $viewPathBase . $theme . '/';
        $loaderPaths = [$viewPathTheme, $viewPathBase];
        $viewsKey = implode('|', $loaderPaths);
        if (!isset($views[$viewsKey])) {
            $views[$viewsKey] = new Environment(new FilesystemLoader($loaderPaths), config("{$configPrefix}view.options", []));

            // 添加 config 函数到 Twig
            $views[$viewsKey]->addFunction(new TwigFunction('config', function ($key, $default = null) {
                return config($key, $default);
            }));

            // 添加 blog_config 函数到 Twig
            $views[$viewsKey]->addFunction(new TwigFunction('blog_config', function ($key, $default = null) {
                try {
                    // 只读，安全原因不允许写入
                    return blog_config($key, $default, false, true, false);
                } catch (Throwable $e) {
                    return $default;
                }
            }));

            $extension = config("{$configPrefix}view.extension");
            if ($extension) {
                $extension($views[$viewsKey]);
            }
        }
        if (isset($request->_view_vars)) {
            $vars = array_merge((array) $request->_view_vars, $vars);
        }

        // 检查是否启用模板缓存
        $cacheEnabled = (bool) config("{$configPrefix}view.cache_enabled", true);

        $html = '';
        $fromCache = false;

        if ($cacheEnabled) {
            // 生成缓存键
            $cacheKey = self::generateTemplateCacheKey($template, $vars, $theme, $app, $plugin);

            // 尝试从缓存获取
            $cachedHtml = CacheService::cache($cacheKey);
            if ($cachedHtml !== false) {
                $html = $cachedHtml;
                $fromCache = true;
            }
        }

        if (!$fromCache) {
            // 记录渲染开始时间
            $renderStartTime = microtime(true);

            // 执行模板渲染
            $html = $views[$viewsKey]->render("$template.$viewSuffix", $vars);

            // 计算渲染耗时
            $renderTime = round((microtime(true) - $renderStartTime) * 1000, 2);

            // 保存到缓存（如果启用缓存）
            if ($cacheEnabled) {
                // 检查是否启用HTML压缩
                $compressHtml = (bool) config("{$configPrefix}view.compress_html", true);

                if ($compressHtml) {
                    $compressedHtml = self::compressHtml($html);
                    $originalSize = strlen($html);
                    $compressedSize = strlen($compressedHtml);
                    $compressionRatio = $originalSize > 0 ? round((1 - $compressedSize / $originalSize) * 100, 1) : 0;

                    // 记录压缩统计（仅在调试模式或压缩率超过10%时）
                    if ($debug || $compressionRatio > 10) {
                        Log::debug("[TwigTemplateService] HTML压缩: {$originalSize} -> {$compressedSize} bytes (减少{$compressionRatio}%), 模板: {$template}");
                    }

                    $html = $compressedHtml;
                }

                $cacheKey = self::generateTemplateCacheKey($template, $vars, $theme, $app, $plugin);
                $cacheTtl = (int) config("{$configPrefix}view.cache_ttl", 3600); // 默认1小时
                CacheService::cache($cacheKey, $html, true, $cacheTtl);
            }
        }

        // 获取调试模式状态
        $debug = (bool) config('app.debug', false);

        // 记录渲染时间日志（仅在调试模式下或渲染时间超过100ms时记录）
        if ($debug || !$fromCache) {
            $renderInfo = $fromCache ? '来自缓存' : '实时渲染';
            $renderInfo .= ", 模板: {$template}.{$viewSuffix}, 主题: {$theme}";

            if (!$fromCache) {
                $renderInfo .= ", 耗时: {$renderTime}ms";

                // 如果渲染时间超过500ms，记录警告日志
                if ($renderTime > 500) {
                    Log::warning("[TwigTemplateService] 模板渲染耗时过长: {$renderTime}ms, 模板: {$template}.{$viewSuffix}, 主题: {$theme}");
                }
            }

            Log::debug("[TwigTemplateService] {$renderInfo}");
        }

        return $html;
    }

    /**
     * 清除模板渲染缓存
     *
     * @param string|null $pattern 缓存模式，null表示清除所有模板缓存
     *
     * @return bool 是否清除成功
     */
    public static function clearTemplateCache(?string $pattern = null): bool
    {
        try {
            $cachePattern = $pattern ?? 'twig_template:*';
            Log::info("[TwigTemplateService] Clearing template cache: {$cachePattern}");

            return CacheService::clearCache($cachePattern);
        } catch (Exception $e) {
            Log::error('[TwigTemplateService] Failed to clear template cache: ' . $e->getMessage());

            return false;
        }
    }
}

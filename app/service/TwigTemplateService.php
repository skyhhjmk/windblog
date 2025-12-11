<?php

declare(strict_types=1);

namespace app\service;

use function app_path;
use function array_merge;
use function base_path;
use function config;
use function is_array;
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

        // 使用多路径缓存键进行渲染
        $html = $views[$viewsKey]->render("$template.$viewSuffix", $vars);

        return $html;
    }
}

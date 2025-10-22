<?php

declare(strict_types=1);

namespace app\service;

use Throwable;
use function app_path;
use function array_merge;
use function base_path;
use function config;
use function is_array;
use function request;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
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
        $plugin = $plugin === null ? ($request->plugin ?? '') : $plugin;
        $app = $app === null ? ($request->app ?? '') : $app;
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
        // 解析主题，失败或空值均回退 default
        $theme = 'default';
        try {
            $t = blog_config('theme', 'default', true);
            if (is_string($t) && $t !== '') {
                $theme = $t;
            }
        } catch (Throwable $e) {
            // ignore and fallback to default
        }
        // 构建主题与回退路径
        $viewPathBase = rtrim($viewPath, '/') . '/';
        $viewPathTheme = $viewPathBase . $theme . '/';
        $loaderPaths = [$viewPathTheme, $viewPathBase];
        $viewsKey = implode('|', $loaderPaths);
        if (!isset($views[$viewsKey])) {
            $views[$viewsKey] = new Environment(new FilesystemLoader($loaderPaths), config("{$configPrefix}view.options", []));
            $extension = config("{$configPrefix}view.extension");
            if ($extension) {
                $extension($views[$viewsKey]);
            }
        }
        if (isset($request->_view_vars)) {
            $vars = array_merge((array) $request->_view_vars, $vars);
        }

        // 过滤器：模板变量（需权限 template:filter.vars）
        $vars = PluginService::apply_filters('template.vars_filter', [
            'template' => $template,
            'vars' => $vars,
            'app' => $app,
            'plugin' => $plugin,
        ])['vars'] ?? $vars;

        // 动作：渲染开始（需权限 template:action.render_start）
        PluginService::do_action('template.render_start', [
            'template' => $template,
            'app' => $app,
            'plugin' => $plugin,
            'paths' => $loaderPaths,
        ]);

        // 使用多路径缓存键进行渲染
        $html = $views[$viewsKey]->render("$template.$viewSuffix", $vars);

        // 动作：渲染结束（需权限 template:action.render_end）
        PluginService::do_action('template.render_end', [
            'template' => $template,
            'app' => $app,
            'plugin' => $plugin,
            'html_len' => strlen($html),
        ]);

        // 过滤器：HTML输出（需权限 template:filter.html）
        $html = PluginService::apply_filters('template.html_filter', [
            'template' => $template,
            'html' => $html,
            'app' => $app,
            'plugin' => $plugin,
        ])['html'] ?? $html;

        return $html;
    }
}

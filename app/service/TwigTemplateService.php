<?php
declare(strict_types=1);

namespace app\service;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use Webman\View;
use function app_path;
use function array_merge;
use function base_path;
use function config;
use function is_array;
use function request;

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
        $request->_view_vars = array_merge((array)$request->_view_vars, is_array($name) ? $name : [$name => $value]);
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
        try {
            $viewPath = $viewPath . blog_config('theme', 'default', true) . '/';
        } catch (\Throwable $e) {

        }
        if (!isset($views[$viewPath])) {
            $views[$viewPath] = new Environment(new FilesystemLoader($viewPath), config("{$configPrefix}view.options", []));
            $extension = config("{$configPrefix}view.extension");
            if ($extension) {
                $extension($views[$viewPath]);
            }
        }
        if (isset($request->_view_vars)) {
            $vars = array_merge((array)$request->_view_vars, $vars);
        }
        return $views[$viewPath]->render("$template.$viewSuffix", $vars);
    }
}
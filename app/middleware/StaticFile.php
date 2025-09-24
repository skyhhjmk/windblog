<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace app\middleware;

use support\Log;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 静态文件中间件
 */
class StaticFile implements MiddlewareInterface
{
    /**
     * 不同文件类型的缓存策略(秒)
     * @var array
     */
    protected array $cacheStrategies = [
        // 图片类型 - 30天
        'jpg'  => 2592000,
        'jpeg' => 2592000,
        'png'  => 2592000,
        'gif'  => 2592000,
        'webp' => 2592000,
        'ico'  => 2592000,
        'svg'  => 2592000,

        // 字体文件 - 30天
        'woff' => 2592000,
        'woff2' => 2592000,
        'ttf' => 2592000,
        'eot' => 2592000,

        // CSS和JS - 7天
        'css'  => 604800,
        'js'   => 604800,

        // 其他静态资源 - 1天
        'default' => 86400,
    ];

    public function process(Request $request, callable $handler): Response
    {
        // 禁止访问以.开头的文件
        if (str_contains($request->path(), '/.')) {
            return new Response(403, [], '<h1>403 forbidden</h1>');
        }

        /** @var Response $response */
        $response = $handler($request);

        // 添加跨域HTTP头
        try {
            if (blog_config('allow_static_cors', false, true)) {
                $response->withHeaders([
                    'Access-Control-Allow-Origin'      => blog_config('allow_static_cors_origin', '*', true),
                    'Access-Control-Allow-Credentials' => blog_config('allow_static_cors_credentials', true, true),
                ]);
            }
        } catch (\Throwable $e) {
            $response->withHeaders(['X-Error' => 'YES']);
            Log::error('[StaticFile Middleware] Error: ' . $e->getMessage());
        }


        // 处理缓存控制
        $this->handleCacheControl($request, $response);

        return $response;
    }

    /**
     * 处理缓存控制头
     * @param Request $request
     * @param Response $response
     */
    protected function handleCacheControl(Request $request, Response $response): void
    {
        // 只对成功的GET请求设置缓存
        if ($request->method() !== 'GET' || $response->getStatusCode() !== 200) {
            return;
        }

        $path = $request->path();
        $fileExt = $this->getFileExtension($path);
        $maxAge = $this->getMaxAgeByExtension($fileExt);

        // 获取文件信息用于生成ETag和Last-Modified
        $filePath = public_path() . '/' . ltrim($path, '/');
        $fileInfo = $this->getFileInfo($filePath);

        // 设置缓存控制头
        $cacheControl = [];
        $cacheControl[] = 'public';
        $cacheControl[] = "max-age={$maxAge}";

        // 如果是带哈希的静态资源文件，添加immutable
        if ($this->isImmutableFile($path, $fileExt)) {
            $cacheControl[] = 'immutable';
        }

        $response->header('Cache-Control', implode(', ', $cacheControl));

        // 设置ETag
        if (!empty($fileInfo['etag'])) {
            $response->header('ETag', $fileInfo['etag']);

            // 检查条件请求
            if ($request->header('If-None-Match') === $fileInfo['etag']) {
                $response->withStatus(304);
                $response->withBody('');
                return;
            }
        }

        // 设置Last-Modified
        if (!empty($fileInfo['last_modified'])) {
            $response->header('Last-Modified', $fileInfo['last_modified']);

            // 检查条件请求
            if ($request->header('If-Modified-Since') === $fileInfo['last_modified']) {
                $response->withStatus(304);
                $response->withBody('');
                return;
            }
        }
    }

    /**
     * 获取文件扩展名
     * @param string $path
     * @return string
     */
    protected function getFileExtension(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return strtolower($ext);
    }

    /**
     * 根据文件扩展名获取缓存时间
     * @param string $ext
     * @return int
     */
    protected function getMaxAgeByExtension(string $ext): int
    {
        return $this->cacheStrategies[$ext] ?? $this->cacheStrategies['default'];
    }

    /**
     * 判断是否为不可变文件(通常是带哈希的静态资源)
     * @param string $path
     * @param string $ext
     * @return bool
     */
    protected function isImmutableFile(string $path, string $ext): bool
    {
        // 只对特定类型的静态资源启用immutable
        $immutableTypes = ['js', 'css', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
        if (!in_array($ext, $immutableTypes)) {
            return false;
        }

        // 检查文件名中是否包含哈希值(8位以上字母数字组合)
        $filename = pathinfo($path, PATHINFO_FILENAME);
        return preg_match('/[a-f0-9]{8,}/i', $filename) === 1;
    }

    /**
     * 获取文件信息用于缓存验证
     * @param string $filePath
     * @return array
     */
    protected function getFileInfo(string $filePath): array
    {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return [];
        }

        $mtime = filemtime($filePath);
        $size = filesize($filePath);

        return [
            // ETag基于文件修改时间和大小生成
            'etag' => sprintf('W/"%s-%s"', $size, $mtime),
            // Last-Modified使用GMT格式
            'last_modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT'
        ];
    }
}

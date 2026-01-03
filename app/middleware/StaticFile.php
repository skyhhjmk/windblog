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

use app\service\CacheControl;
use support\Log;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 静态文件中间件
 */
class StaticFile implements MiddlewareInterface
{
    // 缓存策略已移至 CacheControl 服务统一管理

    public function process(Request $request, callable $handler): Response
    {
        // 禁止访问以.开头的文件
        if (str_contains($request->path(), '/.')) {
            return new Response(403, [], '<h1>403 forbidden</h1>');
        }

        /** @var Response $response */
        $response = $handler($request);

        // 添加跨域HTTP头
        /*try {
            if (blog_config('allow_static_cors', false, true)) {
                $response->withHeaders([
                    'Access-Control-Allow-Origin'      => blog_config('allow_static_cors_origin', '*', true),
                    'Access-Control-Allow-Credentials' => blog_config('allow_static_cors_credentials', true, true),
                ]);
            }
        } catch (\Throwable $e) {
            $response->withHeaders(['X-Error' => 'YES']);
            Log::error('[StaticFile Middleware] Error: ' . $e->getMessage());
        }*/

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

        // 获取文件信息用于生成ETag和Last-Modified
        $filePath = public_path() . '/' . ltrim($path, '/');
        $fileInfo = $this->getFileInfo($filePath);

        // 设置缓存控制头 - 使用统一的缓存策略服务
        $isImmutable = $this->isImmutableFile($path, $fileExt);
        $cacheControlValue = CacheControl::getStaticAssetCacheControl($fileExt, $isImmutable);
        $response->header('Cache-Control', $cacheControlValue);

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
            'last_modified' => CacheControl::generateLastModified($mtime),
        ];
    }
}

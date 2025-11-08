<?php

namespace app\middleware;

use app\service\AssetMinifyRegistry;
use MatthiasMullie\Minify\CSS as CSSMinifier;
use MatthiasMullie\Minify\JS as JSMinifier;

use function public_path;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 在调试模式且启用 ASSET_AUTO_MINIFY 时：
 * 1) 如访问 /assets/min/{hash}.(js|css) 且文件缺失，基于注册表按需重建并直接返回（防止 404）。
 * 2) 对页面响应头追加 X-Asset-Minify-Reduction 汇总信息。
 */
class AssetMinifyMetrics implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        /** @var Response $response */
        $response = $handler($request);

        // 若是对 /assets/min/* 的 404，尝试按需重建并立即返回
        if ($response->getStatusCode() === 404) {
            $path = $request->path();
            if (preg_match('#^/assets/min/([a-f0-9]{32})\\.(js|css)$#i', $path, $m)) {
                $hash = strtolower($m[1]);
                $ext = strtolower($m[2]);

                $src = null;
                $info = AssetMinifyRegistry::get($hash, $ext);
                if (is_array($info) && !empty($info['src'])) {
                    $src = $info['src'];
                }
                if (!$src) {
                    $src = $this->findSourceByHash($hash, $ext);
                }
                if ($src && is_file($src)) {
                    $public = rtrim((string) public_path(), DIRECTORY_SEPARATOR);
                    $minDir = $public . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'min';
                    if (!is_dir($minDir)) {
                        @mkdir($minDir, 0o775, true);
                    }
                    $outPath = $minDir . DIRECTORY_SEPARATOR . $hash . '.' . $ext;
                    if (!is_file($outPath)) {
                        try {
                            if ($ext === 'js') {
                                if (!class_exists(JSMinifier::class)) {
                                    return $response;
                                }
                                (new JSMinifier($src))->minify($outPath);
                            } else {
                                if (!class_exists(CSSMinifier::class)) {
                                    return $response;
                                }
                                (new CSSMinifier($src))->minify($outPath);
                            }
                        } catch (\Throwable $e) {
                            return $response;
                        }
                    }

                    // 返回文件内容（带强缓存）
                    $body = @file_get_contents($outPath) ?: '';
                    $headers = [
                        'Content-Type' => $ext === 'js' ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8',
                        'Cache-Control' => 'public, max-age=604800, immutable',
                    ];
                    $mtime = @filemtime($outPath) ?: time();
                    $etag = 'W/"' . md5($outPath . '|' . (string) $mtime) . '"';
                    $headers['ETag'] = $etag;
                    $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

                    return new Response(200, $headers, $body);
                }
            }
        }

        // 追加压缩比例响应头（仅在 ASSET_AUTO_MINIFY && APP_DEBUG 同时开启且存在统计时）
        $autoEnv = getenv('ASSET_AUTO_MINIFY');
        $autoOn = $autoEnv !== false ? filter_var($autoEnv, FILTER_VALIDATE_BOOL) : false;
        $debugOn = filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOL);
        if ($autoOn && $debugOn) {
            $stats = $request->_asset_minify ?? null;
            if (is_array($stats)) {
                $orig = (int) ($stats['total_orig'] ?? 0);
                $min = (int) ($stats['total_min'] ?? 0);
                if ($orig > 0) {
                    $reduction = ($orig - $min) / $orig * 100;
                    if ($reduction < 0) {
                        $reduction = 0;
                    }
                    $value = number_format($reduction, 2, '.', '') . '%';
                    $response->withHeader('X-Asset-Minify-Reduction', $value);
                }
            }
        }

        return $response;
    }

    private function findSourceByHash(string $hash, string $ext): ?string
    {
        $public = rtrim((string) public_path(), DIRECTORY_SEPARATOR);
        $hash = strtolower($hash);
        $ext = strtolower($ext);
        $roots = [
            $public . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $ext,
            $public . DIRECTORY_SEPARATOR . 'assets',
        ];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $dirs = [$root];
            $sub = @scandir($root) ?: [];
            foreach ($sub as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'min') {
                    continue;
                }
                $p = $root . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($p)) {
                    $dirs[] = $p;
                }
            }
            foreach ($dirs as $dir) {
                $files = @scandir($dir) ?: [];
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') {
                        continue;
                    }
                    $fp = $dir . DIRECTORY_SEPARATOR . $f;
                    if (!is_file($fp)) {
                        continue;
                    }
                    if (strtolower(pathinfo($fp, PATHINFO_EXTENSION)) !== $ext) {
                        continue;
                    }
                    $base = strtolower(basename($fp));
                    if (str_contains($base, '.min.')) {
                        continue;
                    }
                    $h = @md5_file($fp);
                    if ($h && strtolower($h) === $hash) {
                        return $fp;
                    }
                }
            }
        }

        return null;
    }
}

<?php

namespace app\view\extension;

use app\service\AssetMinifyRegistry;
use MatthiasMullie\Minify\CSS as CSSMinifier;
use MatthiasMullie\Minify\JS as JSMinifier;

use function public_path;
use function request;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// 自定义Twig扩展：path 与 asset
class PathExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('path', [$this, 'getPath']),
            new TwigFunction('asset', [$this, 'asset']),
        ];
    }

    /**
     * Twig: asset() - 资源路径处理
     * - 非 .min 的本地 JS/CSS 在开启时自动压缩，输出基于文件哈希的版本路径
     * - 其他情况回退为追加版本查询参数
     */
    public function asset(string $path, ?string $version = null): string
    {
        // 绝对/协议相对/data: 资源直接追加版本后返回
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path) || strpos($path, 'data:') === 0) {
            return $this->appendVersion($path, $version);
        }

        // 拆分查询参数
        $qpos = strpos($path, '?');
        $pathOnly = $qpos !== false ? substr($path, 0, $qpos) : $path;

        $ext = strtolower(pathinfo((string) parse_url($pathOnly, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, ['js', 'css'], true)) {
            return $this->appendVersion($path, $version);
        }

        $base = basename($pathOnly);
        if (str_contains($base, '.min.')) {
            return $this->appendVersion($path, $version);
        }

        // 是否启用自动压缩：ASSET_AUTO_MINIFY 优先，否则默认在非调试环境启用
        $envVal = getenv('ASSET_AUTO_MINIFY');
        $debug = filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOL);
        $shouldMinify = $envVal !== false ? filter_var($envVal, FILTER_VALIDATE_BOOL) : !$debug;
        if (!$shouldMinify) {
            return $this->appendVersion($path, $version);
        }

        // 仅处理本地以 / 开头的资源
        $public = rtrim((string) public_path(), DIRECTORY_SEPARATOR);
        $rel = '/' . ltrim($pathOnly, '/');
        $srcPath = $public . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($srcPath)) {
            return $this->appendVersion($path, $version);
        }

        // 简单记忆缓存（请求内复用）
        static $memo = [];
        if (isset($memo[$srcPath])) {
            return $memo[$srcPath];
        }

        // 基于文件内容哈希的输出文件名
        $hash = @md5_file($srcPath);
        if (!$hash) {
            $hash = md5($srcPath . '|' . (string) @filemtime($srcPath));
        }

        $minBaseUrl = '/assets/min';
        $minBaseDir = $public . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'min';
        if (!is_dir($minBaseDir)) {
            @mkdir($minBaseDir, 0o775, true);
        }
        $outRel = $minBaseUrl . '/' . $hash . '.' . $ext;
        $outPath = $minBaseDir . DIRECTORY_SEPARATOR . $hash . '.' . $ext;

        if (!is_file($outPath)) {
            try {
                if ($ext === 'js') {
                    if (!class_exists(JSMinifier::class)) {
                        return $this->appendVersion($path, $version);
                    }
                    $minifier = new JSMinifier($srcPath);
                    $minifier->minify($outPath);
                } else {
                    if (!class_exists(CSSMinifier::class)) {
                        return $this->appendVersion($path, $version);
                    }
                    $minifier = new CSSMinifier($srcPath);
                    $minifier->minify($outPath);
                }
            } catch (\Throwable $e) {
                return $this->appendVersion($path, $version);
            }
        }

        // 在 debug + 自动压缩 开启时，统计缩小比例（去重）
        if ($shouldMinify && $debug) {
            $origSize = (int) @filesize($srcPath);
            $minSize = (int) (@is_file($outPath) ? @filesize($outPath) : 0);
            if ($minSize <= 0) {
                $minSize = $origSize;
            }
            // 每个源文件只计一次
            static $recorded = [];
            if (!isset($recorded[$srcPath])) {
                $recorded[$srcPath] = true;
                try {
                    $req = request();
                    $stats = is_array($req->_asset_minify ?? null) ? $req->_asset_minify : ['total_orig' => 0, 'total_min' => 0, 'items' => []];
                    $stats['items'][$srcPath] = ['orig' => $origSize, 'min' => $minSize];
                    $stats['total_orig'] += $origSize;
                    $stats['total_min'] += $minSize;
                    $req->_asset_minify = $stats;
                } catch (\Throwable $e) {
                    // ignore stats failure
                }
            }
        }

        // 记录映射，便于缺失时按 hash 反向生成
        try {
            $mtime = (int) @filemtime($srcPath);
            AssetMinifyRegistry::put($hash, $ext, $srcPath, $mtime);
        } catch (\Throwable $e) {
            // ignore mapping failure
        }

        // 哈希文件名本身即可用于长缓存，不再追加版本参数
        $memo[$srcPath] = $outRel;

        return $outRel;
    }

    private function appendVersion(string $path, ?string $version = null): string
    {
        $ver = $version ?? getenv('ASSET_VERSION') ?? getenv('APP_VERSION') ?? date('Ymd');
        $sep = str_contains($path, '?') ? '&' : '?';

        return $path . $sep . 'v=' . $ver;
    }

    /**
     * 生成路由路径
     *
     * @param string $name   路由名称
     * @param array  $params 路由参数
     *
     * @return string
     */
    public function getPath(string $name, array $params = []): string
    {
        // 简单实现路由路径生成功能
        // 实际项目中可能需要更复杂的路由匹配逻辑
        $routes = route($name, $params);

        if (!isset($routes)) {
            return '#'; // 如果找不到路由，返回#
        }

        $path = $routes;

        // 替换路径参数
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', $value, $path);
        }

        return $path;
    }
}

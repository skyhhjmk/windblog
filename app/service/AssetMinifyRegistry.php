<?php

namespace app\service;

use function runtime_path;

class AssetMinifyRegistry
{
    public static function put(string $hash, string $ext, string $srcPath, int $mtime): void
    {
        $file = self::mapFile();
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $key = strtolower($hash) . '.' . strtolower($ext);
        $map = [];
        if (is_file($file)) {
            $json = @file_get_contents($file);
            if ($json !== false) {
                $map = json_decode($json, true) ?: [];
            }
        }
        $map[$key] = [
            'src' => $srcPath,
            'mtime' => $mtime,
            'ext' => strtolower($ext),
        ];
        @file_put_contents($file, json_encode($map, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    protected static function mapFile(): string
    {
        return rtrim((string) runtime_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'asset_min_map.json';
    }

    public static function get(string $hash, string $ext): ?array
    {
        $file = self::mapFile();
        if (!is_file($file)) {
            return null;
        }
        static $cache = null;
        if ($cache === null) {
            $json = @file_get_contents($file);
            $cache = json_decode($json, true) ?: [];
        }
        $key = strtolower($hash) . '.' . strtolower($ext);

        return $cache[$key] ?? null;
    }
}

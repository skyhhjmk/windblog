<?php

namespace app\service\plugin;

/**
 * 解析插件主文件头部注释的元数据（WordPress 风格）
 * 支持字段：
 * - Plugin Name:
 * - Plugin Slug:（可选；若缺失则根据文件名推导）
 * - Version:
 * - Description:
 * - Author:
 * - Requires PHP:
 * - Requires at least:
 */
class PluginMetadata
{
    public string $name = '';
    public string $slug = '';
    public string $version = '';
    public string $description = '';
    public string $author = '';
    public string $requires_php = '';
    public string $requires_at_least = '';
    public string $file = '';
    /** @var array<string, string> 版本/环境依赖约束，如 { "php": ">=8.2" } */
    public array $requires = [];
    /** @var array<string> 能力声明，如 [ "template", "route", "queue" ] */
    public array $capabilities = [];
    /** @var array<string> 权限声明，如 [ "send_mail", "read_posts" ] */
    public array $permissions = [];

    public static function parseFromFile(string $file): ?self
    {
        if (!is_file($file)) {
            return null;
        }
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return null;
        }
        $head = @fread($fp, 8192);
        @fclose($fp);
        if (!is_string($head) || $head === '') {
            return null;
        }

        // 仅解析文件顶部第一个块注释
        if (!preg_match('#/\*\*(.*?)\*/#s', $head, $m)) {
            return null;
        }
        $block = $m[1];

        $meta = new self();
        $meta->file = $file;

        $fields = [
            'Plugin Name'        => 'name',
            'Plugin Slug'        => 'slug',
            'Version'            => 'version',
            'Description'        => 'description',
            'Author'             => 'author',
            'Requires PHP'       => 'requires_php',
            'Requires at least'  => 'requires_at_least',
        ];

        foreach ($fields as $label => $prop) {
            if (preg_match('/^\s*\*\s*' . preg_quote($label, '/') . '\s*:\s*(.+)$/mi', $block, $mm)) {
                $val = trim($mm[1]);
                $meta->{$prop} = $val;
            }
        }

        // 可选：读取同目录下的 plugin.json 以覆盖/补充元数据与权限声明
        $jsonFile = dirname($file) . DIRECTORY_SEPARATOR . 'plugin.json';
        if (is_file($jsonFile)) {
            try {
                $json = json_decode((string)@file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($json)) {
                    $meta->name = $json['name']    ?? $meta->name;
                    $meta->slug = $json['slug']    ?? $meta->slug;
                    $meta->version = $json['version'] ?? $meta->version;
                    $meta->description = $json['description'] ?? $meta->description;
                    $meta->author = $json['author'] ?? $meta->author;
                    $meta->requires_php = $json['requires_php'] ?? ($json['requires']['php'] ?? $meta->requires_php);
                    $meta->requires_at_least = $json['requires_at_least'] ?? $meta->requires_at_least;
                    $meta->requires = is_array($json['requires'] ?? null) ? $json['requires'] : $meta->requires;
                    $meta->capabilities = is_array($json['capabilities'] ?? null) ? $json['capabilities'] : $meta->capabilities;
                    $meta->permissions = is_array($json['permissions'] ?? null) ? $json['permissions'] : $meta->permissions;
                }
            } catch (\Throwable $e) {
                // 忽略 plugin.json 解析错误，保持兼容
            }
        }

        if ($meta->slug === '') {
            // 根据文件名推导 slug（去扩展名、下划线/空格/大写标准化）
            $base = pathinfo($file, PATHINFO_FILENAME);
            $slug = strtolower(preg_replace('/[^a-z0-9\-]+/i', '-', $base));
            $slug = trim($slug, '-');
            $meta->slug = $slug;
        }

        return $meta;
    }
}
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
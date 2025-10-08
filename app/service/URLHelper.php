<?php

namespace app\service;

/**
 * URL辅助工具类
 * 提供统一的URL生成方法，支持不同类型URL的生成和格式化
 */
class URLHelper
{
    /**
     * 生成文章页面URL
     *
     * @param string $slug 文章的slug
     * @param bool $withHtmlSuffix 是否添加.html后缀
     * @return string 格式化后的文章URL
     */
    public static function generatePostUrl(string $slug, bool $withHtmlSuffix = true): string
    {
        $url = '/post/' . urlencode($slug);
        if ($withHtmlSuffix) {
            $url .= '.html';
        }
        return $url;
    }

    /**
     * 生成页面URL
     *
     * @param string $slug 页面的slug
     * @param bool $withHtmlSuffix 是否添加.html后缀
     * @return string 格式化后的页面URL
     */
    public static function generatePageUrl(string $slug, bool $withHtmlSuffix = true): string
    {
        $url = '/page/' . urlencode($slug);
        if ($withHtmlSuffix) {
            $url .= '.html';
        }
        return $url;
    }

    /**
     * 生成分类页面URL
     *
     * @param string $slug 分类的slug
     * @param bool $withHtmlSuffix 是否添加.html后缀
     * @return string 格式化后的分类URL
     */
    public static function generateCategoryUrl(string $slug, bool $withHtmlSuffix = true): string
    {
        // 如果已包含百分号编码（%xx），视为已编码，避免二次编码
        $encodedSlug = preg_match('/%[0-9A-Fa-f]{2}/', $slug) ? $slug : rawurlencode($slug);
        $url = '/category/' . $encodedSlug;
        if ($withHtmlSuffix) {
            $url .= '.html';
        }
        return $url;
    }

    /**
     * 生成标签页面URL
     *
     * @param string $slug 标签的slug
     * @param bool $withHtmlSuffix 是否添加.html后缀
     * @return string 格式化后的标签URL
     */
    public static function generateTagUrl(string $slug, bool $withHtmlSuffix = true): string
    {
        // 如果已包含百分号编码（%xx），视为已编码，避免二次编码
        $encodedSlug = preg_match('/%[0-9A-Fa-f]{2}/', $slug) ? $slug : rawurlencode($slug);
        $url = '/tag/' . $encodedSlug;
        if ($withHtmlSuffix) {
            $url .= '.html';
        }
        return $url;
    }

    /**
     * 生成搜索结果页面URL
     *
     * @param string $keyword 搜索关键词
     * @param bool $withHtmlSuffix 是否添加.html后缀
     * @return string 格式化后的搜索URL
     */
    public static function generateSearchUrl(string $keyword, bool $withHtmlSuffix = false): string
    {
        $url = '/search?q=' . urlencode($keyword);
        if ($withHtmlSuffix) {
            $url .= '.html';
        }
        return $url;
    }

    /**
     * 从URL中移除.html后缀
     *
     * @param string $url 原始URL或slug
     * @return string 移除后缀后的URL或slug
     */
    public static function removeHtmlSuffix(string $url): string
    {
        if (str_ends_with($url, '.html')) {
            return substr($url, 0, -5);
        }
        return $url;
    }

    /**
     * 检查URL是否包含.html后缀
     *
     * @param string $url 要检查的URL
     * @return bool 是否包含.html后缀
     */
    public static function hasHtmlSuffix(string $url): bool
    {
        return str_ends_with($url, '.html');
    }

    /**
     * 确保URL包含.html后缀
     *
     * @param string $url 原始URL
     * @return string 确保包含.html后缀的URL
     */
    public static function ensureHtmlSuffix(string $url): string
    {
        if (!str_ends_with($url, '.html')) {
            $url .= '.html';
        }
        return $url;
    }
}
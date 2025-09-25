<?php

namespace app\service;

use app\model\Page;
use support\Log;
use Throwable;

/**
 * 页面服务类
 * 用于提供页面功能，从数据库中获取页面内容并渲染前端页面
 */
class PageService
{
    /**
     * 根据URL关键字获取页面内容
     *
     * @param string $keyword URL关键字（slug）
     * @return array|null 页面数据或null（如果页面不存在）
     * @throws Throwable
     */
    public static function getPageByKeyword(string $keyword): ?array
    {
        $page = Page::where('slug', $keyword)
            ->where('status', 'published')
            ->first();

        if (!$page) {
            return null;
        }

        // 处理页面内容
        $pageData = self::processPageContent($page);

        return $pageData;
    }

    /**
     * 根据ID获取页面内容
     *
     * @param int $id 页面ID
     * @return array|null 页面数据或null（如果页面不存在）
     * @throws Throwable
     */
    public static function getPageById(int $id): ?array
    {
        $page = Page::where('id', $id)
            ->where('status', 'published')
            ->first();

        if (!$page) {
            return null;
        }

        // 处理页面内容
        $pageData = self::processPageContent($page);

        return $pageData;
    }

    /**
     * 处理页面内容
     *
     * @param Page $page 页面模型实例
     * @return array 处理后的页面数据
     */
    protected static function processPageContent(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'content' => $page->content,
            'status' => $page->status,
            'template' => $page->template,
            'sort_order' => $page->sort_order,
            'created_at' => $page->created_at,
            'updated_at' => $page->updated_at,
            'meta_title' => $page->title . ' - ' . blog_config('title', 'WindBlog', true),
            'meta_description' => self::generateMetaDescription($page->content)
        ];
    }

    /**
     * 生成Meta描述
     *
     * @param string $content 内容
     * @return string 生成的描述
     */
    protected static function generateMetaDescription(string $content): string
    {
        $plainText = strip_tags($content);
        return mb_substr($plainText, 0, 160, 'UTF-8') . '...';
    }

    /**
     * 渲染页面
     *
     * @param array $pageData 页面数据
     * @return string 渲染后的HTML
     */
    public static function renderPage(array $pageData): string
    {
        $templateData = [
            'page_title' => $pageData['meta_title'],
            'page' => $pageData,
            'meta_description' => $pageData['meta_description']
        ];

        // 使用页面指定的模板，如果没有则使用默认模板
        $template = $pageData['template'] ?: 'index/page';

        return view($template, $templateData);
    }

    /**
     * 根据URL关键字获取并渲染页面
     *
     * @param string $keyword URL关键字
     * @return string|null 渲染后的页面内容或null（如果页面不存在）
     * @throws Throwable
     */
    public static function getAndRenderPage(string $keyword): ?string
    {
        $pageData = self::getPageByKeyword($keyword);
        
        if (!$pageData) {
            return null;
        }

        return self::renderPage($pageData);
    }

    /**
     * 根据ID获取并渲染页面
     *
     * @param int $id 页面ID
     * @return string|null 渲染后的页面内容或null（如果页面不存在）
     * @throws Throwable
     */
    public static function getAndRenderPageById(int $id): ?string
    {
        $pageData = self::getPageById($id);
        
        if (!$pageData) {
            return null;
        }

        return self::renderPage($pageData);
    }
}
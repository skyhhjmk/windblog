<?php

namespace app\service;

use Illuminate\Support\Facades\DB;
use support\Request;
use Throwable;

class I18nService
{
    public static function setLocaleCookie(string $locale): string
    {
        // 返回设置 cookie 的字符串（供 HTML 脚本设置），这里不直接写响应对象，兼容任意控制器
        return "locale=" . rawurlencode($locale) . "; Max-Age=" . (365 * 24 * 3600) . "; Path=/";
    }

    public static function getAvailableLocales(): array
    {
        try {
            $rows = DB::table('i18n_languages')->where('enabled', 1)->orderBy('sort_order')->get();
            return array_map(static function ($r) {
                return [
                    'code' => $r->code,
                    'name' => $r->name,
                    'native_name' => $r->native_name,
                    'is_default' => (bool)$r->is_default,
                ];
            }, $rows->all());
        } catch (Throwable $e) {
            return [
                ['code' => 'zh_CN', 'name' => 'Simplified Chinese', 'native_name' => '简体中文', 'is_default' => true],
                ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_default' => false],
            ];
        }
    }

    public static function translatePosts($posts, ?string $locale = null): void
    {
        $locale = $locale ?: self::getCurrentLocale();
        foreach ($posts as $post) {
            self::applyPostTranslation($post, $locale, onlyMeta: true);
        }
    }

    public static function applyPostTranslation($post, ?string $locale = null, bool $onlyMeta = false): void
    {
        $locale = $locale ?: self::getCurrentLocale();
        $id = (int)($post->id ?? 0);
        if ($id <= 0) return;
        $t = self::getTranslation('post', $id, 'title', $locale);
        if ($t) $post->title = $t;
        $e = self::getTranslation('post', $id, 'excerpt', $locale);
        if ($e) $post->excerpt = $e;
        if (!$onlyMeta) {
            $c = self::getTranslation('post', $id, 'content', $locale);
            if ($c) $post->content = $c;
        }
    }

    public static function getTranslation(string $entityType, int $entityId, string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?: self::getCurrentLocale();
        try {
            $row = DB::table('i18n_contents')
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('field_name', $field)
                ->where('locale', $locale)
                ->value('content');
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function getCurrentLocale(?Request $request = null): string
    {
        $request = $request ?: request();
        $cookie = (string)($request->cookie('locale') ?? '');
        if ($cookie !== '') return $cookie;
        // 默认
        return (string)blog_config('default_locale', 'zh_CN', true);
    }
}

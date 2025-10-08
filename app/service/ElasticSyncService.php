<?php

namespace app\service;

use app\model\Post;
use support\Log;
use support\Redis;

class ElasticSyncService
{
    /**
     * 创建索引，支持选择分析器（standard 或 ik_max_word）
     */
    public static function createIndex(string $analyzer = 'standard'): bool
    {
        $cfg = ElasticService::getConfigProxy();
        if (!$cfg['enabled']) {
            // 仍允许创建索引以便后续启用
        }
        $settings = [
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                    'excerpt' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                    'content' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                    'created_at' => ['type' => 'date', 'format' => 'strict_date_optional_time||epoch_millis'],
                    'author' => ['type' => 'keyword'],
                    'categories_names' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                    'tags_names' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                    'categories_slugs' => ['type' => 'keyword'],
                    'tags_slugs' => ['type' => 'keyword']
                ]
            ]
        ];
        $url = sprintf('%s/%s', rtrim($cfg['host'], '/'), $cfg['index']);
        try {
            $client = ElasticService::client();
            $client->indices()->create([
                'index' => $cfg['index'],
                'body' => $settings
            ]);
            $resp = ['ok' => true, 'status' => 200];
        } catch (\Throwable $e) {
            $resp = ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
        $msg = sprintf('[ES] createIndex analyzer=%s status=%s', $analyzer, $resp['status'] ?? 'n/a');
        Redis::lpush('es:sync:logs', date('Y-m-d H:i:s') . ' ' . $msg);
        if (!$resp['ok']) {
            Log::warning('[ElasticSyncService] createIndex failed status=' . $resp['status'] . ' error=' . ($resp['error'] ?? ''));
            return false;
        }
        return true;
    }

    /**
     * 索引单篇文章
     */
    public static function indexPost(Post $post): bool
    {
        $cfg = ElasticService::getConfigProxy();
        $created = null;
        if ($post->created_at instanceof \DateTimeInterface) {
            $created = $post->created_at->format('c'); // ISO-8601
        } elseif (!empty($post->created_at)) {
            // 尝试强转为字符串，ES将按strict_date_optional_time解析
            $created = (string)$post->created_at;
        }

        $payload = [
            'id' => (int)$post->id,
            'title' => (string)$post->title,
            'excerpt' => (string)$post->excerpt,
            'content' => (string)$post->content,
            'author' => self::pickAuthor($post),
        ];
        if ($created) {
            $payload['created_at'] = $created;
        }

        // 补充分类/标签信息到索引文档（名称与 slug）
        try {
            if (method_exists($post, 'relationLoaded')) {
                $needLoadCat = !$post->relationLoaded('categories');
                $needLoadTag = !$post->relationLoaded('tags');
            } else {
                $needLoadCat = $needLoadTag = true;
            }
            if (($needLoadCat || $needLoadTag) && method_exists($post, 'loadMissing')) {
                $load = [];
                if ($needLoadCat) $load[] = 'categories:id,name,slug';
                if ($needLoadTag) $load[] = 'tags:id,name,slug';
                if ($load) $post->loadMissing($load);
            }

            $catNames = [];
            $catSlugs = [];
            if (isset($post->categories) && $post->categories) {
                foreach ($post->categories as $c) {
                    $name = (string)($c->name ?? '');
                    $slug = (string)($c->slug ?? '');
                    if ($name !== '') $catNames[] = $name;
                    if ($slug !== '') $catSlugs[] = $slug;
                }
            }

            $tagNames = [];
            $tagSlugs = [];
            if (isset($post->tags) && $post->tags) {
                foreach ($post->tags as $t) {
                    $name = (string)($t->name ?? '');
                    $slug = (string)($t->slug ?? '');
                    if ($name !== '') $tagNames[] = $name;
                    if ($slug !== '') $tagSlugs[] = $slug;
                }
            }

            if ($catNames) $payload['categories_names'] = array_values($catNames);
            if ($catSlugs) $payload['categories_slugs'] = array_values($catSlugs);
            if ($tagNames) $payload['tags_names'] = array_values($tagNames);
            if ($tagSlugs) $payload['tags_slugs'] = array_values($tagSlugs);
        } catch (\Throwable $e) {
            // 忽略关系异常，不影响基础索引
        }

        try {
            $client = ElasticService::client();
            $client->index([
                'index' => $cfg['index'],
                'id' => (int)$post->id,
                'body' => $payload
            ]);
            $resp = ['ok' => true, 'status' => 200];
        } catch (\Throwable $e) {
            $resp = ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
        $msg = sprintf('[ES] indexPost id=%d status=%s', (int)$post->id, $resp['status'] ?? 'n/a');
        Redis::lpush('es:sync:logs', date('Y-m-d H:i:s') . ' ' . $msg . (isset($resp['body']) ? ' body=' . json_encode($resp['body']) : ''));
        if (!$resp['ok']) {
            Log::warning('[ElasticSyncService] indexPost failed id=' . $post->id . ' status=' . $resp['status'] . ' error=' . ($resp['error'] ?? '') . (isset($resp['body']) ? ' body=' . json_encode($resp['body']) : ''));
            return false;
        }
        return true;
    }

    /**
     * 删除文章索引
     */
    public static function deletePost(int $id): bool
    {
        $cfg = ElasticService::getConfigProxy();
        try {
            $client = ElasticService::client();
            $client->delete([
                'index' => $cfg['index'],
                'id' => $id
            ]);
            $resp = ['ok' => true, 'status' => 200];
        } catch (\Throwable $e) {
            $resp = ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
        $msg = sprintf('[ES] deletePost id=%d status=%s', $id, $resp['status'] ?? 'n/a');
        Redis::lpush('es:sync:logs', date('Y-m-d H:i:s') . ' ' . $msg);
        if (!$resp['ok']) {
            Log::warning('[ElasticSyncService] deletePost failed id=' . $id . ' status=' . $resp['status'] . ' error=' . ($resp['error'] ?? ''));
            return false;
        }
        return true;
    }

    protected static function pickAuthor(Post $post): string
    {
        // primaryAuthor 或 authors 数组
        try {
            if (isset($post->primaryAuthor) && $post->primaryAuthor) {
                return (string)($post->primaryAuthor->nickname ?? $post->primaryAuthor->name ?? '未知作者');
            }
            if (isset($post->authors) && $post->authors && count($post->authors) > 0) {
                $a = $post->authors[0];
                return (string)($a->nickname ?? $a->name ?? '未知作者');
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '未知作者';
    }
}
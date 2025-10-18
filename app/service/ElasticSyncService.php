<?php

namespace app\service;

use app\model\Post;
use support\Log;

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
                    // 通用区分类型字段
                    'item_type' => ['type' => 'keyword'], // post | tag | category

                    // 文章(post)字段
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                    'excerpt' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                    'content' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                    'created_at' => ['type' => 'date', 'format' => 'strict_date_optional_time||epoch_millis'],
                    'author' => ['type' => 'keyword'],
                    'categories_names' => [
                        'type' => 'text',
                        'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard',
                        'fields' => [
                            'keyword' => ['type' => 'keyword'],
                        ],
                    ],
                    'tags_names' => [
                        'type' => 'text',
                        'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard',
                        'fields' => [
                            'keyword' => ['type' => 'keyword'],
                        ],
                    ],
                    'categories_slugs' => ['type' => 'keyword'],
                    'tags_slugs' => ['type' => 'keyword'],

                    // 标签(tag)独立文档字段
                    'tag_id' => ['type' => 'integer'],
                    'tag_name' => [
                        'type' => 'text',
                        'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard',
                        'fields' => [
                            'keyword' => ['type' => 'keyword'],
                        ],
                    ],
                    'tag_slug' => ['type' => 'keyword'],
                    'tag_description' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],

                    // 分类(category)独立文档字段
                    'category_id' => ['type' => 'integer'],
                    'category_name' => [
                        'type' => 'text',
                        'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard',
                        'fields' => [
                            'keyword' => ['type' => 'keyword'],
                        ],
                    ],
                    'category_slug' => ['type' => 'keyword'],
                    'category_description' => ['type' => 'text', 'analyzer' => $analyzer === 'ik_max_word' ? 'ik_max_word' : 'standard'],
                ],
            ],
        ];
        $url = sprintf('%s/%s', rtrim($cfg['host'], '/'), $cfg['index']);
        try {
            $client = ElasticService::client();
            $client->indices()->create([
                'index' => $cfg['index'],
                'body' => $settings,
            ]);
            $resp = ['ok' => true, 'status' => 200];
        } catch (\Throwable $e) {
            $resp = ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
        $msg = sprintf('[ES] createIndex analyzer=%s status=%s', $analyzer, $resp['status'] ?? 'n/a');
        redis()->lPush('es:sync:logs', date('Y-m-d H:i:s') . ' ' . $msg);
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
            $created = (string) $post->created_at;
        }

        $payload = [
            'item_type' => 'post',
            'id' => (int) $post->id,
            'title' => (string) $post->title,
            'excerpt' => (string) $post->excerpt,
            'content' => (string) $post->content,
            'author' => self::pickAuthor($post),
        ];

        // 过滤器：允许插件调整索引payload（需权限 search:filter.index_post_payload）
        $payload = \app\service\PluginService::apply_filters('es.index_post_payload_filter', $payload);
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
                if ($needLoadCat) {
                    $load[] = 'categories:id,name,slug';
                }
                if ($needLoadTag) {
                    $load[] = 'tags:id,name,slug';
                }
                if ($load) {
                    $post->loadMissing($load);
                }
            }

            $catNames = [];
            $catSlugs = [];
            if (isset($post->categories) && $post->categories) {
                foreach ($post->categories as $c) {
                    $name = (string) ($c->name ?? '');
                    $slug = (string) ($c->slug ?? '');
                    if ($name !== '') {
                        $catNames[] = $name;
                    }
                    if ($slug !== '') {
                        $catSlugs[] = $slug;
                    }
                }
            }

            $tagNames = [];
            $tagSlugs = [];
            if (isset($post->tags) && $post->tags) {
                foreach ($post->tags as $t) {
                    $name = (string) ($t->name ?? '');
                    $slug = (string) ($t->slug ?? '');
                    if ($name !== '') {
                        $tagNames[] = $name;
                    }
                    if ($slug !== '') {
                        $tagSlugs[] = $slug;
                    }
                }
            }

            if ($catNames) {
                $payload['categories_names'] = array_values($catNames);
            }
            if ($catSlugs) {
                $payload['categories_slugs'] = array_values($catSlugs);
            }
            if ($tagNames) {
                $payload['tags_names'] = array_values($tagNames);
            }
            if ($tagSlugs) {
                $payload['tags_slugs'] = array_values($tagSlugs);
            }
        } catch (\Throwable $e) {
            // 忽略关系异常，不影响基础索引
        }

        try {
            $client = ElasticService::client();
            $params = [
                'index' => $cfg['index'],
                'id' => (int) $post->id,
                'body' => $payload,
            ];
            $response = $client->index($params);
            $resp = ['ok' => true, 'status' => 200, 'body' => $response->asArray()];
        } catch (\Throwable $e) {
            $resp = ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
        $msg = sprintf('[ES] indexPost id=%d status=%s', (int) $post->id, $resp['status'] ?? 'n/a');
        redis()->lPush('es:sync:logs', date('Y-m-d H:i:s') . ' ' . $msg . (isset($resp['body']) ? ' body=' . json_encode($resp['body']) : ''));
        if (!$resp['ok']) {
            Log::warning('[ElasticSyncService] indexPost failed id=' . $post->id . ' status=' . $resp['status'] . ' error=' . ($resp['error'] ?? '') . (isset($resp['body']) ? ' body=' . json_encode($resp['body']) : ''));

            return false;
        }

        return true;
    }

    /**
     * 索引单个标签为独立文档
     */
    public static function indexTag(\app\model\Tag $tag): bool
    {
        $cfg = ElasticService::getConfigProxy();
        $body = [
            'item_type' => 'tag',
            'tag_id' => (int) $tag->id,
            'tag_name' => (string) $tag->name,
            'tag_slug' => (string) ($tag->slug ?? ''),
            'tag_description' => (string) ($tag->description ?? ''),
        ];

        // 过滤器：索引标签payload（需权限 search:filter.index_tag_payload）
        $body = \app\service\PluginService::apply_filters('es.index_tag_payload_filter', $body);
        // 动作：开始索引标签（需权限 search:action.index_tag_start）
        \app\service\PluginService::do_action('elastic.index_tag_start', ['id' => (int) $tag->id]);

        try {
            $client = ElasticService::client();
            $params = [
                'index' => $cfg['index'],
                'id' => 'tag_' . (int) $tag->id,
                'body' => $body,
            ];
            $response = $client->index($params);
            redis()->lPush('es:sync:logs', date('Y-m-d H:i:s') . ' [ES] indexTag id=' . (int) $tag->id . ' name=' . (string) $tag->name . ' status=200');

            // 动作：完成索引标签（需权限 search:action.index_tag_done）
            \app\service\PluginService::do_action('elastic.index_tag_done', ['id' => (int) $tag->id]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[ElasticSyncService] indexTag failed id=' . $tag->id . ' error=' . $e->getMessage());

            return false;
        }
    }

    /**
     * 索引单个分类为独立文档
     */
    public static function indexCategory(\app\model\Category $category): bool
    {
        $cfg = ElasticService::getConfigProxy();
        $body = [
            'item_type' => 'category',
            'category_id' => (int) $category->id,
            'category_name' => (string) $category->name,
            'category_slug' => (string) ($category->slug ?? ''),
            'category_description' => (string) ($category->description ?? ''),
        ];

        // 过滤器：索引分类payload（需权限 search:filter.index_category_payload）
        $body = \app\service\PluginService::apply_filters('es.index_category_payload_filter', $body);
        // 动作：开始索引分类（需权限 search:action.index_category_start）
        \app\service\PluginService::do_action('elastic.index_category_start', ['id' => (int) $category->id]);

        try {
            $client = ElasticService::client();
            $params = [
                'index' => $cfg['index'],
                'id' => 'category_' . (int) $category->id,
                'body' => $body,
            ];
            $response = $client->index($params);
            redis()->lPush('es:sync:logs', date('Y-m-d H:i:s') . ' [ES] indexCategory id=' . (int) $category->id . ' name=' . (string) $category->name . ' status=200');

            // 动作：完成索引分类（需权限 search:action.index_category_done）
            \app\service\PluginService::do_action('elastic.index_category_done', ['id' => (int) $category->id]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[ElasticSyncService] indexCategory failed id=' . $category->id . ' error=' . $e->getMessage());

            return false;
        }
    }

    /**
     * 删除文章索引
     */
    public static function deletePost(int $id): bool
    {
        $cfg = ElasticService::getConfigProxy();
        try {
            \app\service\PluginService::do_action('elastic.delete_post_start', ['id' => $id]);

            $client = ElasticService::client();
            $params = [
                'index' => $cfg['index'],
                'id' => $id,
            ];
            $response = $client->delete($params);
            \app\service\PluginService::do_action('elastic.delete_post_done', ['id' => $id]);
            $resp = ['ok' => true, 'status' => 200, 'body' => $response->asArray()];
        } catch (\Throwable $e) {
            $resp = ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
        $msg = sprintf('[ES] deletePost id=%d status=%s', $id, $resp['status'] ?? 'n/a');
        redis()->lPush('es:sync:logs', date('Y-m-d H:i:s') . ' ' . $msg);
        if (!$resp['ok']) {
            Log::warning('[ElasticSyncService] deletePost failed id=' . $id . ' status=' . $resp['status'] . ' error=' . ($resp['error'] ?? ''));

            return false;
        }

        return true;
    }

    /**
     * 删除标签文档
     */
    public static function deleteTag(int $id): bool
    {
        $cfg = ElasticService::getConfigProxy();
        try {
            \app\service\PluginService::do_action('elastic.delete_tag_start', ['id' => $id]);

            $client = ElasticService::client();
            $params = [
                'index' => $cfg['index'],
                'id' => 'tag_' . $id,
            ];
            $response = $client->delete($params);
            \app\service\PluginService::do_action('elastic.delete_tag_done', ['id' => $id]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[ElasticSyncService] deleteTag failed id=' . $id . ' error=' . $e->getMessage());

            return false;
        }
    }

    /**
     * 删除分类文档
     */
    public static function deleteCategory(int $id): bool
    {
        $cfg = ElasticService::getConfigProxy();
        try {
            \app\service\PluginService::do_action('elastic.delete_category_start', ['id' => $id]);

            $client = ElasticService::client();
            $params = [
                'index' => $cfg['index'],
                'id' => 'category_' . $id,
            ];
            $response = $client->delete($params);
            \app\service\PluginService::do_action('elastic.delete_category_done', ['id' => $id]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[ElasticSyncService] deleteCategory failed id=' . $id . ' error=' . $e->getMessage());

            return false;
        }
    }

    protected static function pickAuthor(Post $post): string
    {
        // primaryAuthor 或 authors 数组
        try {
            if (isset($post->primaryAuthor) && $post->primaryAuthor) {
                return (string) ($post->primaryAuthor->nickname ?? $post->primaryAuthor->name ?? '未知作者');
            }
            if (isset($post->authors) && $post->authors && count($post->authors) > 0) {
                $a = $post->authors[0];

                return (string) ($a->nickname ?? $a->name ?? '未知作者');
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '未知作者';
    }
}

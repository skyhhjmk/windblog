<?php

namespace app\service;

use app\model\Post;
use support\Log;

class ElasticRebuildService
{
    /**
     * 全量重建索引（分页遍历）
     */
    public static function rebuildAll(int $pageSize = 200): bool
    {
        $page = 1;
        try {
            while (true) {
                $query = Post::published()->orderBy('id')->forPage($page, $pageSize)->with(['authors', 'primaryAuthor']);
                $batch = $query->get();
                if ($batch->isEmpty()) {
                    break;
                }
                foreach ($batch as $post) {
                    ElasticSyncService::indexPost($post);
                }
                $page++;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('[ElasticRebuildService] rebuildAll error: ' . $e->getMessage());
            return false;
        }
    }
}
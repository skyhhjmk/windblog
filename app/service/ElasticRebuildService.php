<?php

namespace app\service;

use app\model\Category;
use app\model\Post;
use app\model\Tag;
use support\Log;
use Throwable;

class ElasticRebuildService
{
    /**
     * 全量重建索引（分页遍历）
     */
    public static function rebuildAll(int $pageSize = 200): bool
    {
        $page = 1;
        try {
            // 动作：全量重建开始（需权限 search:action.rebuild_start）
            PluginService::do_action('elastic.rebuild_start', ['pageSize' => $pageSize]);
            // 先重建全部标签
            $tpage = 1;
            while (true) {
                $tBatch = Tag::orderBy('id')->forPage($tpage, $pageSize)->get(['id', 'name', 'slug', 'description']);
                if ($tBatch->isEmpty()) {
                    break;
                }
                foreach ($tBatch as $tag) {
                    ElasticSyncService::indexTag($tag);
                }
                $tpage++;
            }

            // 再重建全部分类
            $cpage = 1;
            while (true) {
                $cBatch = Category::orderBy('id')->forPage($cpage, $pageSize)->get(['id', 'name', 'slug', 'description']);
                if ($cBatch->isEmpty()) {
                    break;
                }
                foreach ($cBatch as $cat) {
                    ElasticSyncService::indexCategory($cat);
                }
                $cpage++;
            }

            // 最后重建文章
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
            // 动作：全量重建结束（需权限 search:action.rebuild_end）
            PluginService::do_action('elastic.rebuild_end', ['pageSize' => $pageSize]);

            return true;
        } catch (Throwable $e) {
            Log::error('[ElasticRebuildService] rebuildAll error: ' . $e->getMessage());

            return false;
        }
    }
}

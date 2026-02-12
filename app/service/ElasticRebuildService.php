<?php

namespace app\service;

use app\model\Category;
use app\model\Post;
use app\model\Tag;
use support\Log;
use Throwable;
use Workerman\Timer;

class ElasticRebuildService
{
    /**
     * 全量重建索引（分页遍历）
     */
    public static function rebuildAll(int $pageSize = 200): bool
    {
        $page = 1;
        try {
            // 先重建全部标签
            $tpage = 1;
            $timer_id = Timer::add(0.1, function () use (&$tpage, $pageSize, &$timer_id) {
                $tBatch = Tag::orderBy('id')->forPage($tpage, $pageSize)->get(['id', 'name', 'slug', 'description']);
                if ($tBatch->isEmpty()) {
                    Timer::del($timer_id);
                }
                foreach ($tBatch as $tag) {
                    ElasticSyncService::indexTag($tag);
                }
                $tpage++;
            });

            // 再重建全部分类
            $cpage = 1;
            $timer_id = Timer::add(0.1, function () use (&$cpage, $pageSize, &$timer_id) {
                $cBatch = Category::orderBy('id')->forPage($cpage, $pageSize)->get(['id', 'name', 'slug', 'description']);
                if ($cBatch->isEmpty()) {
                    Timer::del($timer_id);
                }
                foreach ($cBatch as $cat) {
                    ElasticSyncService::indexCategory($cat);
                }
                $cpage++;
            });

            // 最后重建文章
            $timer_id = Timer::add(0.1, function () use (&$page, $pageSize, &$timer_id) {
                $query = Post::published()->orderBy('id')->forPage($page, $pageSize)->with(['authors', 'primaryAuthor']);
                $batch = $query->get();
                if ($batch->isEmpty()) {
                    Timer::del($timer_id);
                }
                foreach ($batch as $post) {
                    ElasticSyncService::indexPost($post);
                }
                $page++;
            });

            return true;
        } catch (Throwable $e) {
            Log::error('[ElasticRebuildService] rebuildAll error: ' . $e->getMessage());

            return false;
        }
    }
}

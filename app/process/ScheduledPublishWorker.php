<?php

namespace app\process;

use app\model\Post;
use support\Log;
use Workerman\Timer;

class ScheduledPublishWorker
{
    public function onWorkerStart($worker): void
    {
        // 每分钟检查一次：将达到发布时间的草稿置为已发布
        Timer::add(60, function () {
            $this->publishDuePosts();
        });
        // 启动即检查一次
        $this->publishDuePosts();
    }

    protected function publishDuePosts(): void
    {
        try {
            $now = utc_now();
            $posts = Post::where('status', 'draft')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', $now)
                ->take(200)
                ->get();

            foreach ($posts as $post) {
                $old = $post->status;
                $post->status = 'published';
                $post->save();
                Log::info("Scheduled publish: post={$post->id} from {$old} -> published at {$now->toIso8601String()}");
            }
        } catch (\Throwable $e) {
            Log::error('ScheduledPublishWorker error: ' . $e->getMessage());
        }
    }
}

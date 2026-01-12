<?php

namespace app\service;

use support\Cache;
use support\Log;

class EdgeSyncService
{
    private string $datacenterUrl;

    private int $syncInterval;

    private const QUEUE_KEY = 'edge:sync:queue';

    private const HISTORY_KEY = 'edge:sync:history';

    private const LAST_SYNC_KEY = 'edge:sync:last';

    private const MAX_HISTORY = 100;

    public function __construct()
    {
        $this->datacenterUrl = config('app.datacenter_url', '');
        $this->syncInterval = config('app.edge_sync_interval', 300);
    }

    public function syncFromDatacenter(): array
    {
        if (empty($this->datacenterUrl)) {
            Log::warning('[EdgeSync] Datacenter URL not configured');

            return ['success' => false, 'message' => 'Datacenter URL not configured'];
        }

        EdgeNodeService::markSyncStart();

        try {
            $syncResult = $this->fetchDataFromDatacenter();

            if ($syncResult['success']) {
                $this->processSyncData($syncResult['data']);
                EdgeNodeService::markSyncComplete($syncResult['count'] ?? 0);

                return [
                    'success' => true,
                    'message' => 'Sync completed successfully',
                    'synced_count' => $syncResult['count'] ?? 0,
                ];
            } else {
                EdgeNodeService::markSyncError($syncResult['message']);

                return $syncResult;
            }
        } catch (\Throwable $e) {
            $errorMsg = 'Sync failed: ' . $e->getMessage();
            Log::error('[EdgeSync] ' . $errorMsg);
            EdgeNodeService::markSyncError($errorMsg);

            return ['success' => false, 'message' => $errorMsg];
        }
    }

    private function fetchDataFromDatacenter(): array
    {
        $syncUrl = rtrim($this->datacenterUrl, '/') . '/api/edge/sync';
        $lastSync = Cache::get(self::LAST_SYNC_KEY) ?? 0;

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode(['last_sync' => $lastSync]),
            ],
        ]);

        $response = @file_get_contents($syncUrl, false, $context);

        if ($response === false) {
            return ['success' => false, 'message' => 'Failed to fetch data from datacenter'];
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Invalid JSON response from datacenter'];
        }

        return ['success' => true, 'data' => $data, 'count' => count($data['items'] ?? [])];
    }

    private function processSyncData(array $data): void
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            return;
        }

        $syncedCount = 0;

        foreach ($data['items'] as $item) {
            try {
                $this->syncItem($item);
                $syncedCount++;
            } catch (\Throwable $e) {
                Log::error('[EdgeSync] Failed to sync item: ' . $e->getMessage());
            }
        }

        Cache::set(self::LAST_SYNC_KEY, time(), 86400);

        $this->addToHistory([
            'timestamp' => time(),
            'count' => $syncedCount,
            'type' => 'pull',
        ]);
    }

    public function getSyncDataSince(int $timestamp): array
    {
        $items = [];
        $date = date('Y-m-d H:i:s', $timestamp);

        // 1. Sync Posts
        try {
            $posts = \app\model\Post::where('updated_at', '>', $date)
                ->with(['authors', 'categories', 'tags'])
                ->get();
            foreach ($posts as $post) {
                $items[] = [
                    'type' => 'post',
                    'id' => $post->id,
                    'data' => $post->toArray(),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('[EdgeSync] Failed to fetch posts for sync: ' . $e->getMessage());
        }

        // 2. Sync Categories
        try {
            $categories = \app\model\Category::where('updated_at', '>', $date)->get();
            foreach ($categories as $category) {
                $items[] = [
                    'type' => 'category',
                    'id' => $category->id,
                    'data' => $category->toArray(),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('[EdgeSync] Failed to fetch categories for sync: ' . $e->getMessage());
        }

        // 3. Sync Tags
        try {
            $tags = \app\model\Tag::where('updated_at', '>', $date)->get();
            foreach ($tags as $tag) {
                $items[] = [
                    'type' => 'tag',
                    'id' => $tag->id,
                    'data' => $tag->toArray(),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('[EdgeSync] Failed to fetch tags for sync: ' . $e->getMessage());
        }

        // 4. Sync Settings (All settings if any updated)
        try {
            $hasUpdatedSettings = \app\model\Setting::where('updated_at', '>', $date)->exists();
            if ($hasUpdatedSettings || $timestamp === 0) {
                $settings = \app\model\Setting::all();
                foreach ($settings as $setting) {
                    $items[] = [
                        'type' => 'config',
                        'id' => $setting->key,
                        'data' => $setting->value,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('[EdgeSync] Failed to fetch settings for sync: ' . $e->getMessage());
        }

        // 5. Sync Post Lists (for homepage and archives)
        if ($timestamp === 0 || count($items) > 0) {
            try {
                // Latest posts
                $latestPostIds = \app\model\Post::where('status', 'published')
                    ->orderByDesc('featured')
                    ->orderByDesc('id')
                    ->limit(500)
                    ->pluck('id')
                    ->toArray();

                $items[] = [
                    'type' => 'post_list',
                    'id' => 'latest',
                    'data' => $latestPostIds,
                ];

                // Most used categories
                $categories = \app\model\Category::withCount([
                    'posts' => function ($q) {
                        $q->where('status', 'published');
                    },
                ])->orderByDesc('posts_count')->limit(50)->get();

                $items[] = [
                    'type' => 'category_list',
                    'id' => 'popular',
                    'data' => $categories->toArray(),
                ];

                // Most used tags
                $tags = \app\model\Tag::withCount([
                    'posts' => function ($q) {
                        $q->where('status', 'published');
                    },
                ])->orderByDesc('posts_count')->limit(100)->get();

                $items[] = [
                    'type' => 'tag_list',
                    'id' => 'popular',
                    'data' => $tags->toArray(),
                ];
            } catch (\Throwable $e) {
                Log::error('[EdgeSync] Failed to fetch lists for sync: ' . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'items' => $items,
            'timestamp' => time(),
            'count' => count($items),
        ];
    }

    private function syncItem(array $item): void
    {
        $type = $item['type'] ?? '';
        $id = $item['id'] ?? 0;
        $data = $item['data'] ?? [];

        switch ($type) {
            case 'post':
                $this->syncPost((int) $id, $data);
                break;
            case 'category':
                $this->syncCategory((int) $id, $data);
                break;
            case 'tag':
                $this->syncTag((int) $id, $data);
                break;
            case 'config':
                $this->syncConfig((string) $id, $data);
                break;
            case 'post_list':
                $this->syncPostList((string) $id, $data);
                break;
            case 'category_list':
                $this->syncCategoryList((string) $id, $data);
                break;
            case 'tag_list':
                $this->syncTagList((string) $id, $data);
                break;
            default:
                Log::warning('[EdgeSync] Unknown item type: ' . $type);
        }
    }

    private function syncPost(int $id, array $data): void
    {
        EdgeNodeService::setCache('post:' . $id, $data, 86400);
        // Also cache by slug for quick lookup
        if (isset($data['slug'])) {
            EdgeNodeService::setCache('post_slug:' . $data['slug'], $id, 86400);
        }
        Log::debug('[EdgeSync] Synced post ' . $id . ' to cache');
    }

    private function syncCategory(int $id, array $data): void
    {
        EdgeNodeService::setCache('category:' . $id, $data, 86400);
        if (isset($data['slug'])) {
            EdgeNodeService::setCache('category_slug:' . $data['slug'], $id, 86400);
        }
        Log::debug('[EdgeSync] Synced category ' . $id . ' to cache');
    }

    private function syncTag(int $id, array $data): void
    {
        EdgeNodeService::setCache('tag:' . $id, $data, 86400);
        if (isset($data['slug'])) {
            EdgeNodeService::setCache('tag_slug:' . $data['slug'], $id, 86400);
        }
        Log::debug('[EdgeSync] Synced tag ' . $id . ' to cache');
    }

    private function syncConfig(string $key, mixed $data): void
    {
        // Settings are stored as raw values, not arrays necessarily
        EdgeNodeService::setCache('config:' . $key, $data, 86400);
        Log::debug('[EdgeSync] Synced config ' . $key . ' to cache');
    }

    private function syncPostList(string $listId, array $data): void
    {
        EdgeNodeService::setCache('post_list:' . $listId, $data, 86400);
        Log::debug('[EdgeSync] Synced post list ' . $listId . ' to cache');
    }

    private function syncCategoryList(string $listId, array $data): void
    {
        EdgeNodeService::setCache('category_list:' . $listId, $data, 86400);
        Log::debug('[EdgeSync] Synced category list ' . $listId . ' to cache');
    }

    private function syncTagList(string $listId, array $data): void
    {
        EdgeNodeService::setCache('tag_list:' . $listId, $data, 86400);
        Log::debug('[EdgeSync] Synced tag list ' . $listId . ' to cache');
    }

    public function pushToDatacenter(array $items): array
    {
        if (empty($this->datacenterUrl)) {
            return ['success' => false, 'message' => 'Datacenter URL not configured'];
        }

        if (!EdgeNodeService::isDatacenterAvailable()) {
            return ['success' => false, 'message' => 'Datacenter is not available'];
        }

        try {
            $pushUrl = rtrim($this->datacenterUrl, '/') . '/api/edge/push';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode(['items' => $items]),
                ],
            ]);

            $response = @file_get_contents($pushUrl, false, $context);

            if ($response === false) {
                return ['success' => false, 'message' => 'Failed to push data to datacenter'];
            }

            $result = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['success' => false, 'message' => 'Invalid JSON response from datacenter'];
            }

            $this->addToHistory([
                'timestamp' => time(),
                'count' => count($items),
                'type' => 'push',
            ]);

            return [
                'success' => true,
                'message' => 'Push completed successfully',
                'pushed_count' => count($items),
            ];
        } catch (\Throwable $e) {
            $errorMsg = 'Push failed: ' . $e->getMessage();
            Log::error('[EdgeSync] ' . $errorMsg);

            return ['success' => false, 'message' => $errorMsg];
        }
    }

    public function addToQueue(string $type, int $id, array $data): void
    {
        $queue = Cache::get(self::QUEUE_KEY, []);
        $queue[] = [
            'type' => $type,
            'id' => $id,
            'data' => $data,
            'timestamp' => time(),
        ];
        Cache::set(self::QUEUE_KEY, $queue, 86400);
        Log::debug('[EdgeSync] Added to queue: ' . $type . ':' . $id);
    }

    public function processQueue(): array
    {
        $queue = Cache::get(self::QUEUE_KEY, []);

        if (empty($queue)) {
            return ['success' => true, 'message' => 'Queue is empty', 'processed_count' => 0];
        }

        Cache::set(self::QUEUE_KEY, [], 86400);

        return $this->pushToDatacenter($queue);
    }

    public function getSyncHistory(int $limit = 10): array
    {
        $history = Cache::get(self::HISTORY_KEY, []);

        return array_slice($history, 0, $limit);
    }

    private function addToHistory(array $entry): void
    {
        $history = Cache::get(self::HISTORY_KEY, []);
        array_unshift($history, $entry);

        if (count($history) > self::MAX_HISTORY) {
            array_pop($history);
        }

        Cache::set(self::HISTORY_KEY, $history, 86400);
    }

    public function getQueueSize(): int
    {
        $queue = Cache::get(self::QUEUE_KEY, []);

        return count($queue);
    }

    public function clearQueue(): void
    {
        Cache::set(self::QUEUE_KEY, [], 86400);
        Log::info('[EdgeSync] Queue cleared');
    }
}

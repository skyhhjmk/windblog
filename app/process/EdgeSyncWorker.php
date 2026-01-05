<?php

namespace app\process;

use app\service\EdgeNodeService;
use app\service\EdgeSyncService;
use support\Log;
use Workerman\Timer;

class EdgeSyncWorker
{
    private EdgeSyncService $syncService;

    private int $syncInterval;

    private ?int $timerId = null;

    public function onWorkerStart()
    {
        if (!EdgeNodeService::isEdgeMode()) {
            Log::info('[EdgeSyncWorker] Not in edge mode, exiting');

            return;
        }

        $this->syncService = new EdgeSyncService();
        $this->syncInterval = config('app.edge_sync_interval', 300);

        Log::info('[EdgeSyncWorker] Started with sync interval: ' . $this->syncInterval . 's');

        $this->startSyncTimer();
        $this->initialSync();
    }

    private function startSyncTimer(): void
    {
        if ($this->timerId !== null) {
            Timer::del($this->timerId);
        }

        $this->timerId = Timer::add($this->syncInterval, function () {
            $this->performSync();
        });

        Log::info('[EdgeSyncWorker] Sync timer started, interval: ' . $this->syncInterval . 's');
    }

    private function performSync(): void
    {
        if (!EdgeNodeService::isDatacenterAvailable()) {
            Log::warning('[EdgeSyncWorker] Datacenter not available, skipping sync');

            return;
        }

        try {
            $result = $this->syncService->syncFromDatacenter();

            if ($result['success']) {
                $syncedCount = $result['synced_count'] ?? 0;
                Log::info('[EdgeSyncWorker] Sync completed, synced: ' . $syncedCount . ' items');
            } else {
                Log::error('[EdgeSyncWorker] Sync failed: ' . ($result['message'] ?? 'Unknown error'));
            }

            $this->processQueue();
        } catch (\Throwable $e) {
            Log::error('[EdgeSyncWorker] Sync error: ' . $e->getMessage());
        }
    }

    private function processQueue(): void
    {
        if (!EdgeNodeService::isDatacenterAvailable()) {
            return;
        }

        $queueSize = $this->syncService->getQueueSize();

        if ($queueSize > 0) {
            Log::info('[EdgeSyncWorker] Processing queue, size: ' . $queueSize);

            $result = $this->syncService->processQueue();

            if ($result['success']) {
                $pushedCount = $result['pushed_count'] ?? 0;
                Log::info('[EdgeSyncWorker] Queue processed, pushed: ' . $pushedCount . ' items');
            } else {
                Log::error('[EdgeSyncWorker] Queue processing failed: ' . ($result['message'] ?? 'Unknown error'));
            }
        }
    }

    private function initialSync(): void
    {
        Log::info('[EdgeSyncWorker] Performing initial sync...');

        $this->performSync();
    }

    public function onWorkerStop()
    {
        if ($this->timerId !== null) {
            Timer::del($this->timerId);
            $this->timerId = null;
        }

        Log::info('[EdgeSyncWorker] Stopped');
    }
}

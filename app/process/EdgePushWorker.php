<?php

namespace app\process;

use app\service\EdgeNodeService;
use support\Log;
use Workerman\Timer;

class EdgePushWorker
{
    private int $pushInterval;

    private ?int $timerId = null;

    public function onWorkerStart()
    {
        if (EdgeNodeService::isEdgeMode()) {
            Log::info('[EdgePushWorker] Not in datacenter mode, exiting');

            return;
        }

        $this->pushInterval = config('app.edge_push_interval', 300);

        Log::info('[EdgePushWorker] Started with push interval: ' . $this->pushInterval . 's');

        $this->startPushTimer();
        $this->initialPush();
    }

    private function startPushTimer(): void
    {
        if ($this->timerId !== null) {
            Timer::del($this->timerId);
        }

        $this->timerId = Timer::add($this->pushInterval, function () {
            $this->performPush();
        });

        Log::info('[EdgePushWorker] Push timer started, interval: ' . $this->pushInterval . 's');
    }

    private function performPush(): void
    {
        try {
            $nodes = $this->getEdgeNodes();

            if (empty($nodes)) {
                Log::info('[EdgePushWorker] No edge nodes found, skipping push');

                return;
            }

            Log::info('[EdgePushWorker] Found ' . count($nodes) . ' edge nodes, starting push...');

            foreach ($nodes as $node) {
                $this->pushToNode($node);
            }

            Log::info('[EdgePushWorker] Push to all nodes completed');
        } catch (\Throwable $e) {
            Log::error('[EdgePushWorker] Push error: ' . $e->getMessage());
        }
    }

    private function getEdgeNodes(): array
    {
        $nodes = blog_config('edge_nodes', []);

        return array_filter($nodes, function ($node) {
            return !empty($node['url']) && !empty($node['api_key']) && $node['status'] === 'active';
        });
    }

    private function pushToNode(array $node): void
    {
        $nodeId = $node['id'];
        $nodeUrl = $node['url'];
        $apiKey = $node['api_key'];

        try {
            Log::info('[EdgePushWorker] Pushing to node ' . $nodeId . ' (' . $nodeUrl . ')');

            $syncUrl = rtrim($nodeUrl, '/') . '/edge/sync';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json' . "\r\n" . 'X-Edge-Certificate: ' . $apiKey,
                    'content' => json_encode(['last_sync' => $node['last_sync'] ?? 0]),
                ],
            ]);

            $response = @file_get_contents($syncUrl, false, $context);

            if ($response === false) {
                $error = error_get_last();
                $errorMessage = $error ? $error['message'] : 'Unknown error';
                Log::error('[EdgePushWorker] Failed to push to node ' . $nodeId . ': ' . $errorMessage);

                return;
            }

            $result = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('[EdgePushWorker] Invalid response from node ' . $nodeId . ': ' . json_last_error_msg());

                return;
            }

            if (isset($result['success']) && $result['success']) {
                Log::info('[EdgePushWorker] Successfully pushed to node ' . $nodeId);

                $this->updateNodeSyncTime($nodeId);
            } else {
                Log::error('[EdgePushWorker] Push to node ' . $nodeId . ' failed: ' . ($result['message'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            Log::error('[EdgePushWorker] Error pushing to node ' . $nodeId . ': ' . $e->getMessage());
        }
    }

    private function updateNodeSyncTime(string $nodeId): void
    {
        $nodes = blog_config('edge_nodes', []);

        if (isset($nodes[$nodeId])) {
            $nodes[$nodeId]['last_sync'] = time();
            blog_config('edge_nodes', $nodes, false, true, true);

            Log::info('[EdgePushWorker] Updated last_sync time for node ' . $nodeId);
        }
    }

    private function initialPush(): void
    {
        Log::info('[EdgePushWorker] Performing initial push...');

        $this->performPush();
    }

    public function onWorkerStop()
    {
        if ($this->timerId !== null) {
            Timer::del($this->timerId);
            $this->timerId = null;
        }

        Log::info('[EdgePushWorker] Stopped');
    }
}

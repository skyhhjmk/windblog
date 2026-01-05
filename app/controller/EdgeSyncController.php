<?php

namespace app\controller;

use app\service\EdgeSyncService;
use support\Request;
use support\Response;

class EdgeSyncController
{
    private string $datacenterApiKey;

    private string $edgeApiKey;

    private int $maxItemsPerSync = 100;

    private int $maxPushItems = 50;

    public function __construct()
    {
        $this->datacenterApiKey = env('EDGE_DATACENTER_API_KEY', '');
        $this->edgeApiKey = env('EDGE_API_KEY', '');
    }

    public function sync(Request $request): Response
    {
        if (!$this->verifyApiKey($request)) {
            return json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $lastSync = (int) $request->input('last_sync', 0);
        $syncService = new EdgeSyncService();
        $result = $syncService->syncFromDatacenter();

        return json($result);
    }

    private function verifyApiKey(Request $request): bool
    {
        $apiKey = $request->header('X-Edge-Certificate') ?: $request->input('api_key');

        if (empty($apiKey)) {
            return false;
        }

        if ($request->input('source') === 'edge') {
            return hash_equals($this->edgeApiKey, $apiKey);
        } else {
            return hash_equals($this->datacenterApiKey, $apiKey);
        }
    }

    public function push(Request $request): Response
    {
        if (!$this->verifyApiKey($request)) {
            return json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $items = $request->input('items', []);

        if (!is_array($items) || count($items) > $this->maxPushItems) {
            return json(['success' => false, 'message' => 'Too many items'], 400);
        }

        $syncService = new EdgeSyncService();
        $result = $syncService->pushToDatacenter($items);

        return json($result);
    }

    public function health(Request $request): Response
    {
        return response('ok');
    }

    public function getSyncStatus(Request $request): Response
    {
        if (!$this->verifyApiKey($request)) {
            return json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $syncService = new EdgeSyncService();
        $history = $syncService->getSyncHistory(10);
        $queueSize = $syncService->getQueueSize();

        return json([
            'success' => true,
            'history' => $history,
            'queue_size' => $queueSize,
        ]);
    }
}

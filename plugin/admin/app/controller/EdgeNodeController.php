<?php

namespace plugin\admin\app\controller;

use plugin\admin\api\Auth;
use support\Request;
use support\Response;

class EdgeNodeController
{
    public function index(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, __FUNCTION__)) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        return raw_view('edge-node/index');
    }

    public function list(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, 'index')) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        $nodes = blog_config('edge_nodes', []);

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'list' => array_values($nodes),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, __FUNCTION__)) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        $name = $request->input('name');
        $url = $request->input('url');
        $datacenterUrl = $request->input('datacenter_url');
        $apiKey = $request->input('api_key');
        $bandwidth = (float) $request->input('bandwidth', 0);
        $cpu = (int) $request->input('cpu', 0);
        $memory = (float) $request->input('memory', 0);
        $costPerGb = (float) $request->input('cost_per_gb', 0);
        $costPerTraffic = (float) $request->input('cost_per_traffic', 0);
        $availableStorage = (float) $request->input('available_storage', 0);
        $stabilityIndex = (int) $request->input('stability_index', 0);
        $monthlyCost = (float) $request->input('monthly_cost', 0);
        $status = $request->input('status', 'active');

        if (empty($name) || empty($url) || empty($datacenterUrl) || empty($apiKey)) {
            return json(['code' => 400, 'msg' => 'Missing required fields', 'data' => []], 400);
        }

        $nodes = blog_config('edge_nodes', []);
        $nodeId = uniqid('edge_');

        $nodes[$nodeId] = [
            'id' => $nodeId,
            'name' => $name,
            'url' => $url,
            'datacenter_url' => $datacenterUrl,
            'api_key' => $apiKey,
            'bandwidth' => $bandwidth,
            'cpu' => $cpu,
            'memory' => $memory,
            'cost_per_gb' => $costPerGb,
            'cost_per_traffic' => $costPerTraffic,
            'available_storage' => $availableStorage,
            'stability_index' => $stabilityIndex,
            'monthly_cost' => $monthlyCost,
            'status' => $status,
            'last_sync' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        blog_config('edge_nodes', $nodes, false, true, true);

        return json([
            'code' => 0,
            'msg' => 'Node created successfully',
            'data' => [
                'node' => $nodes[$nodeId],
            ],
        ]);
    }

    public function generateDeployment(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, 'index')) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        $nodeId = $request->input('id');
        $nodes = blog_config('edge_nodes', []);

        if (!isset($nodes[$nodeId])) {
            return json(['code' => 404, 'msg' => 'Node not found', 'data' => []], 404);
        }

        $node = $nodes[$nodeId];
        $deployService = new \app\service\DeploymentService();
        $deployResult = $deployService->generateDeployment($nodeId, $node);

        if ($deployResult['success']) {
            $nodes[$nodeId]['deployment'] = [
                'generated_at' => time(),
                'docker_compose_path' => $deployResult['docker_compose_path'],
                'env_path' => $deployResult['env_path'],
                'script_path' => $deployResult['script_path'],
            ];
            blog_config('edge_nodes', $nodes, false, true, true);

            return json([
                'code' => 0,
                'msg' => 'Deployment generated successfully',
                'data' => [
                    'deployment' => $deployResult,
                    'node' => $nodes[$nodeId],
                ],
            ]);
        } else {
            return json(['code' => 500, 'msg' => 'Failed to generate deployment: ' . $deployResult['message'], 'data' => []], 500);
        }
    }

    public function downloadDeployment(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, 'index')) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        $nodeId = $request->input('id');
        $nodes = blog_config('edge_nodes', []);

        if (!isset($nodes[$nodeId])) {
            return json(['code' => 404, 'msg' => 'Node not found', 'data' => []], 404);
        }

        $node = $nodes[$nodeId];
        if (!isset($node['deployment'])) {
            return json(['code' => 400, 'msg' => 'Deployment not generated yet', 'data' => []], 400);
        }

        $deployService = new \app\service\DeploymentService();
        $zipPath = $deployService->generateManualPackage($nodeId, $node);

        if (!file_exists($zipPath)) {
            return json(['code' => 404, 'msg' => 'Deployment package not found', 'data' => []], 404);
        }

        // 设置响应头
        $filename = 'windblog-edge-' . $nodeId . '.zip';
        $response = response()->download($zipPath, $filename);

        // 在请求结束后删除文件
        register_shutdown_function(function () use ($zipPath) {
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        });

        return $response;
    }

    public function update(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, __FUNCTION__)) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        $nodeId = $request->input('id');
        $nodes = blog_config('edge_nodes', []);

        if (!isset($nodes[$nodeId])) {
            return json(['code' => 404, 'msg' => 'Node not found', 'data' => []], 404);
        }

        $nodes[$nodeId]['name'] = $request->input('name', $nodes[$nodeId]['name']);
        $nodes[$nodeId]['url'] = $request->input('url', $nodes[$nodeId]['url']);
        $nodes[$nodeId]['datacenter_url'] = $request->input('datacenter_url', $nodes[$nodeId]['datacenter_url'] ?? '');
        $nodes[$nodeId]['api_key'] = $request->input('api_key', $nodes[$nodeId]['api_key']);
        $nodes[$nodeId]['bandwidth'] = (float) $request->input('bandwidth', $nodes[$nodeId]['bandwidth'] ?? 0);
        $nodes[$nodeId]['cpu'] = (int) $request->input('cpu', $nodes[$nodeId]['cpu'] ?? 0);
        $nodes[$nodeId]['memory'] = (float) $request->input('memory', $nodes[$nodeId]['memory'] ?? 0);
        $nodes[$nodeId]['cost_per_gb'] = (float) $request->input('cost_per_gb', $nodes[$nodeId]['cost_per_gb'] ?? 0);
        $nodes[$nodeId]['cost_per_traffic'] = (float) $request->input('cost_per_traffic', $nodes[$nodeId]['cost_per_traffic'] ?? 0);
        $nodes[$nodeId]['available_storage'] = (float) $request->input('available_storage', $nodes[$nodeId]['available_storage'] ?? 0);
        $nodes[$nodeId]['stability_index'] = (int) $request->input('stability_index', $nodes[$nodeId]['stability_index'] ?? 0);
        $nodes[$nodeId]['monthly_cost'] = (float) $request->input('monthly_cost', $nodes[$nodeId]['monthly_cost'] ?? 0);
        $nodes[$nodeId]['status'] = $request->input('status', $nodes[$nodeId]['status']);
        $nodes[$nodeId]['updated_at'] = time();

        blog_config('edge_nodes', $nodes, false, true, true);

        return json([
            'code' => 0,
            'msg' => 'Node updated successfully',
            'data' => [
                'node' => $nodes[$nodeId],
            ],
        ]);
    }

    public function destroy(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, __FUNCTION__)) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        $nodeId = $request->input('id');
        $nodes = blog_config('edge_nodes', []);

        if (!isset($nodes[$nodeId])) {
            return json(['code' => 404, 'msg' => 'Node not found', 'data' => []], 404);
        }

        unset($nodes[$nodeId]);
        blog_config('edge_nodes', $nodes, false, true, true);

        return json([
            'code' => 0,
            'msg' => 'Node deleted successfully',
            'data' => [],
        ]);
    }

    public function sync(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, __FUNCTION__)) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        $nodeId = $request->input('id');
        $nodes = blog_config('edge_nodes', []);

        if (!isset($nodes[$nodeId])) {
            return json(['code' => 404, 'msg' => 'Node not found', 'data' => []], 404);
        }

        $node = $nodes[$nodeId];
        $syncUrl = rtrim($node['url'], '/') . '/api/edge/sync';
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'method' => 'POST',
                'header' => 'Content-Type: application/json' . "\r\n" . 'X-Edge-Certificate: ' . $node['api_key'],
                'content' => json_encode(['last_sync' => $node['last_sync'] ?? 0]),
            ],
        ]);

        $response = @file_get_contents($syncUrl, false, $context);

        if ($response === false) {
            return json(['code' => 500, 'msg' => 'Failed to sync with node', 'data' => []], 500);
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return json(['code' => 500, 'msg' => 'Invalid response from node', 'data' => []], 500);
        }

        $nodes[$nodeId]['last_sync'] = time();
        blog_config('edge_nodes', $nodes, false, true, true);

        return json([
            'code' => 0,
            'msg' => 'Sync completed successfully',
            'data' => [
                'result' => $data,
            ],
        ]);
    }

    public function getStatus(Request $request): Response
    {
        if (!Auth::canAccess(__CLASS__, __FUNCTION__)) {
            return json(['code' => 403, 'msg' => 'No permission', 'data' => []], 403);
        }

        $nodeId = $request->input('id');
        $nodes = blog_config('edge_nodes', []);

        if (!isset($nodes[$nodeId])) {
            return json(['code' => 404, 'msg' => 'Node not found', 'data' => []], 404);
        }

        $node = $nodes[$nodeId];
        $statusUrl = rtrim($node['url'], '/') . '/api/edge/status';
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'method' => 'GET',
                'header' => 'X-Edge-Certificate: ' . $node['api_key'],
            ],
        ]);

        $response = @file_get_contents($statusUrl, false, $context);

        if ($response === false) {
            return json([
                'code' => 0,
                'msg' => 'ok',
                'data' => [
                    'node' => $node,
                    'status' => 'offline',
                ],
            ]);
        }

        $data = json_decode($response, true);

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'node' => $node,
                'status' => 'online',
                'sync_info' => $data,
            ],
        ]);
    }
}

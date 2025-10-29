<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use app\model\PostExt;
use app\service\AISummaryService;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

class AiSummaryController
{
    /**
     * 管理页面：分标签页（Overview、API配置、Prompt配置）
     * GET /app/admin/ai-summary
     */
    public function index(Request $request): Response
    {
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'ai_summary' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'AI summary index template not found');
    }

    // 统计：各状态数量
    public function stats(Request $request): Response
    {
        $rows = Db::table('post_ext')->where('key', 'ai_summary_meta')->get();
        $counters = ['none' => 0, 'done' => 0, 'failed' => 0, 'refreshing' => 0, 'persisted' => 0];
        foreach ($rows as $r) {
            $val = $r->value;
            if (is_string($val)) {
                $meta = json_decode($val, true) ?: [];
            } else {
                $meta = (array) $val;
            }
            $st = (string) ($meta['status'] ?? 'none');
            if (!isset($counters[$st])) {
                $counters[$st] = 0;
            }
            $counters[$st]++;
        }

        return json(['code' => 0, 'data' => $counters]);
    }

    /**
     * 获取可用提供者列表及当前选择
     * GET /app/admin/ai-summary/providers
     */
    public function providersGet(Request $request): Response
    {
        $availableProviders = [];
        foreach (AISummaryService::getAvailableProviders() as $id => $class) {
            $provider = AISummaryService::createProvider($id);
            if ($provider) {
                $availableProviders[] = [
                    'id' => $provider->getId(),
                    'name' => $provider->getName(),
                    'type' => $provider->getType(),
                    'supported_tasks' => $provider->getSupportedTasks(),
                ];
            }
        }

        $currentProviderId = (string) blog_config('ai_provider', 'local.echo', true);

        return json([
            'code' => 0,
            'data' => [
                'available' => $availableProviders,
                'current' => $currentProviderId,
            ],
        ]);
    }

    /**
     * 获取指定提供者的配置
     * GET /app/admin/ai-summary/provider-config?provider=openai
     */
    public function providerConfigGet(Request $request): Response
    {
        $providerId = (string) $request->get('provider', '');
        if ($providerId === '') {
            return json(['code' => 1, 'msg' => 'provider is required']);
        }

        $provider = AISummaryService::createProvider($providerId);
        if (!$provider) {
            return json(['code' => 1, 'msg' => 'Unknown provider']);
        }

        $config = AISummaryService::getProviderConfig($providerId);
        $configFields = $provider->getConfigFields();

        return json([
            'code' => 0,
            'data' => [
                'config' => $config,
                'fields' => $configFields,
            ],
        ]);
    }

    /**
     * 保存提供者配置
     * POST /app/admin/ai-summary/provider-config-save
     * body: { provider: 'openai', config: {...} }
     */
    public function providerConfigSave(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }
            if (!is_array($payload)) {
                return json(['code' => 1, 'msg' => 'invalid payload']);
            }

            $providerId = (string) ($payload['provider'] ?? '');
            $config = (array) ($payload['config'] ?? []);

            if ($providerId === '') {
                return json(['code' => 1, 'msg' => 'provider is required']);
            }

            // 验证配置
            $provider = AISummaryService::createProvider($providerId, $config);
            if (!$provider) {
                return json(['code' => 1, 'msg' => 'Unknown provider']);
            }

            $validation = $provider->validateConfig($config);
            if (!($validation['valid'] ?? false)) {
                $errors = $validation['errors'] ?? ['Invalid configuration'];

                return json(['code' => 1, 'msg' => implode(', ', $errors)]);
            }

            // 保存配置
            if (!AISummaryService::saveProviderConfig($providerId, $config)) {
                return json(['code' => 1, 'msg' => 'Failed to save configuration']);
            }

            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 设置当前选择（提供方或轮询组）
     * POST /app/admin/ai/selection/set
     * body: { selection: 'provider:openai' | 'group:1' }
     */
    public function setSelection(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $selection = (string) ($payload['selection'] ?? '');
            if (empty($selection)) {
                return json(['code' => 1, 'msg' => 'selection is required']);
            }

            // 验证选择格式
            if (str_starts_with($selection, 'provider:')) {
                $providerId = substr($selection, 9);
                $provider = AISummaryService::createProvider($providerId);
                if (!$provider) {
                    return json(['code' => 1, 'msg' => '未知的提供方']);
                }
            } elseif (str_starts_with($selection, 'group:')) {
                $groupId = (int) substr($selection, 6);
                $group = \app\model\AiPollingGroup::find($groupId);
                if (!$group) {
                    return json(['code' => 1, 'msg' => '轮询组不存在']);
                }
            } else {
                return json(['code' => 1, 'msg' => '无效的选择格式']);
            }

            blog_config('ai_current_selection', $selection, false, true, true);

            return json(['code' => 0, 'msg' => '设置成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取当前选择
     * GET /app/admin/ai/selection/get
     */
    public function getSelection(Request $request): Response
    {
        try {
            $currentSelection = (string) blog_config('ai_current_selection', '', true);

            return json(['code' => 0, 'data' => ['selection' => $currentSelection]]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取OpenAI模型列表（自动获取）
     * POST /app/admin/ai-summary/fetch-models
     * body: { provider: 'openai', config: {...} }
     */
    public function fetchModels(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $providerId = (string) ($payload['provider'] ?? '');
            $config = (array) ($payload['config'] ?? []);

            if ($providerId === '') {
                return json(['code' => 1, 'msg' => 'provider is required']);
            }

            $provider = AISummaryService::createProvider($providerId, $config);
            if (!$provider) {
                return json(['code' => 1, 'msg' => 'Unknown provider']);
            }

            // Check if provider supports fetchModels
            if (!method_exists($provider, 'fetchModels')) {
                return json(['code' => 1, 'msg' => 'Provider does not support model fetching']);
            }

            $result = $provider->fetchModels();

            if (!($result['ok'] ?? false)) {
                return json(['code' => 1, 'msg' => $result['error'] ?? 'Failed to fetch models']);
            }

            return json(['code' => 0, 'data' => $result['models'] ?? []]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    // 为文章设置启用/状态/持久化
    public function setMeta(Request $request): Response
    {
        $postId = (int) $request->post('post_id', 0);
        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => 'post_id required']);
        }
        $enabled = $request->post('enabled');
        $status = $request->post('status');
        $meta = [];
        if ($enabled !== null) {
            $meta['enabled'] = (bool) $enabled;
        }
        if (is_string($status) && $status !== '') {
            $meta['status'] = $status;
        }
        $row = PostExt::where('post_id', $postId)->where('key', 'ai_summary_meta')->first();
        if (!$row) {
            $row = new PostExt(['post_id' => $postId, 'key' => 'ai_summary_meta', 'value' => []]);
        }
        $row->value = array_merge((array) $row->value, $meta);
        $row->save();

        return json(['code' => 0, 'msg' => 'ok']);
    }

    // 入队生成
    public function enqueue(Request $request): Response
    {
        $postId = (int) $request->post('post_id', 0);
        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => 'post_id required']);
        }
        $provider = (string) $request->post('provider', '');
        $force = (bool) $request->post('force', false);
        $ok = AISummaryService::enqueue(['post_id' => $postId, 'provider' => ($provider ?: null), 'options' => ['force' => $force]]);

        return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? '已入队' : '入队失败']);
    }
}

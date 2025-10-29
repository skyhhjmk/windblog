<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use app\model\AiPollingGroup;
use app\model\AiPollingGroupProvider;
use app\service\AISummaryService;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

class AiPollingGroupController
{
    /**
     * 管理页面
     * GET /app/admin/ai/polling-groups
     */
    public function index(Request $request): Response
    {
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'ai_polling_groups' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'AI polling groups index template not found');
    }

    /**
     * 获取轮询组列表
     * GET /app/admin/ai/polling-groups/list
     */
    public function list(Request $request): Response
    {
        try {
            $groups = AiPollingGroup::with('providers')->orderBy('id', 'desc')->get();

            $data = $groups->map(function ($group) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'strategy' => $group->strategy,
                    'enabled' => $group->enabled,
                    'providers' => $group->providers->map(function ($provider) {
                        return [
                            'id' => $provider->id,
                            'provider_id' => $provider->provider_id,
                            'weight' => $provider->weight,
                            'enabled' => $provider->enabled,
                        ];
                    })->toArray(),
                    'created_at' => $group->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $group->updated_at?->format('Y-m-d H:i:s'),
                ];
            });

            return json(['code' => 0, 'data' => $data]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 创建轮询组
     * POST /app/admin/ai/polling-groups/create
     * body: { name, description, strategy, providers: [{provider_id, weight, enabled}] }
     */
    public function create(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }
            if (!is_array($payload)) {
                return json(['code' => 1, 'msg' => 'invalid payload']);
            }

            $name = (string) ($payload['name'] ?? '');
            $description = ($payload['description'] ?? null);
            $strategy = (string) ($payload['strategy'] ?? 'polling');
            $providers = (array) ($payload['providers'] ?? []);

            if (empty($name)) {
                return json(['code' => 1, 'msg' => '轮询组名称不能为空']);
            }

            if (!in_array($strategy, ['polling', 'failover'], true)) {
                return json(['code' => 1, 'msg' => '无效的调度策略']);
            }

            if (empty($providers)) {
                return json(['code' => 1, 'msg' => '至少需要一个提供方']);
            }

            // 检查轮询组名称是否已存在
            $exists = AiPollingGroup::where('name', $name)->exists();
            if ($exists) {
                return json(['code' => 1, 'msg' => '轮询组名称已存在']);
            }

            Db::beginTransaction();

            try {
                // 创建轮询组
                $group = AiPollingGroup::create([
                    'name' => $name,
                    'description' => $description,
                    'strategy' => $strategy,
                    'enabled' => true,
                ]);

                // 添加提供方
                foreach ($providers as $provider) {
                    $providerId = (string) ($provider['provider_id'] ?? '');
                    $weight = (int) ($provider['weight'] ?? 1);
                    $enabled = (bool) ($provider['enabled'] ?? true);

                    if (empty($providerId)) {
                        continue;
                    }

                    AiPollingGroupProvider::create([
                        'group_id' => $group->id,
                        'provider_id' => $providerId,
                        'weight' => max(0, $weight),
                        'enabled' => $enabled,
                    ]);
                }

                Db::commit();

                return json(['code' => 0, 'msg' => '创建成功', 'data' => ['id' => $group->id]]);
            } catch (Throwable $e) {
                Db::rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 更新轮询组
     * POST /app/admin/ai/polling-groups/update
     * body: { id, name, description, strategy, providers: [{id?, provider_id, weight, enabled}] }
     */
    public function update(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }
            if (!is_array($payload)) {
                return json(['code' => 1, 'msg' => 'invalid payload']);
            }

            $id = (int) ($payload['id'] ?? 0);
            $name = (string) ($payload['name'] ?? '');
            $description = ($payload['description'] ?? null);
            $strategy = (string) ($payload['strategy'] ?? 'polling');
            $providers = (array) ($payload['providers'] ?? []);

            if ($id <= 0) {
                return json(['code' => 1, 'msg' => 'id is required']);
            }

            if (empty($name)) {
                return json(['code' => 1, 'msg' => '轮询组名称不能为空']);
            }

            if (!in_array($strategy, ['polling', 'failover'], true)) {
                return json(['code' => 1, 'msg' => '无效的调度策略']);
            }

            if (empty($providers)) {
                return json(['code' => 1, 'msg' => '至少需要一个提供方']);
            }

            $group = AiPollingGroup::find($id);
            if (!$group) {
                return json(['code' => 1, 'msg' => '轮询组不存在']);
            }

            // 检查轮询组名称是否与其他组重复
            $exists = AiPollingGroup::where('name', $name)->where('id', '!=', $id)->exists();
            if ($exists) {
                return json(['code' => 1, 'msg' => '轮询组名称已存在']);
            }

            Db::beginTransaction();

            try {
                // 更新轮询组
                $group->name = $name;
                $group->description = $description;
                $group->strategy = $strategy;
                $group->save();

                // 删除旧的提供方关系
                AiPollingGroupProvider::where('group_id', $group->id)->delete();

                // 添加新的提供方
                foreach ($providers as $provider) {
                    $providerId = (string) ($provider['provider_id'] ?? '');
                    $weight = (int) ($provider['weight'] ?? 1);
                    $enabled = (bool) ($provider['enabled'] ?? true);

                    if (empty($providerId)) {
                        continue;
                    }

                    AiPollingGroupProvider::create([
                        'group_id' => $group->id,
                        'provider_id' => $providerId,
                        'weight' => max(0, $weight),
                        'enabled' => $enabled,
                    ]);
                }

                Db::commit();

                return json(['code' => 0, 'msg' => '更新成功']);
            } catch (Throwable $e) {
                Db::rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 删除轮询组
     * POST /app/admin/ai/polling-groups/delete
     * body: { id }
     */
    public function delete(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                return json(['code' => 1, 'msg' => 'id is required']);
            }

            $group = AiPollingGroup::find($id);
            if (!$group) {
                return json(['code' => 1, 'msg' => '轮询组不存在']);
            }

            // 检查是否正在使用
            $currentSelection = (string) blog_config('ai_current_selection', '', true);
            if ($currentSelection === "group:{$id}") {
                return json(['code' => 1, 'msg' => '该轮询组正在使用中，无法删除']);
            }

            $group->delete();

            return json(['code' => 0, 'msg' => '删除成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 切换轮询组启用状态
     * POST /app/admin/ai/polling-groups/toggle-enabled
     * body: { id }
     */
    public function toggleEnabled(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                return json(['code' => 1, 'msg' => 'id is required']);
            }

            $group = AiPollingGroup::find($id);
            if (!$group) {
                return json(['code' => 1, 'msg' => '轮询组不存在']);
            }

            $group->enabled = !$group->enabled;
            $group->save();

            return json(['code' => 0, 'msg' => '操作成功', 'data' => ['enabled' => $group->enabled]]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取可用的提供方列表（用于添加到轮询组）
     * GET /app/admin/ai/polling-groups/available-providers
     */
    public function availableProviders(Request $request): Response
    {
        try {
            // 从数据库获取所有启用的提供方
            $providers = AISummaryService::getAllProviders(true);

            $availableProviders = array_map(function ($provider) {
                return [
                    'id' => $provider['id'],
                    'name' => $provider['name'],
                    'type' => $provider['type'],
                    'template' => $provider['template'] ?? null,
                ];
            }, $providers);

            return json(['code' => 0, 'data' => $availableProviders]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
}

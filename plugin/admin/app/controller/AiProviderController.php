<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use app\model\AiProvider;
use app\service\ai\AiProviderTemplates;
use app\service\AISummaryService;
use support\Request;
use support\Response;
use Throwable;

/**
 * AI提供方管理控制器
 */
class AiProviderController
{
    /**
     * 管理页面
     * GET /app/admin/ai/providers
     */
    public function index(Request $request): Response
    {
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'ai_providers' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'AI providers index template not found');
    }

    /**
     * 获取提供方列表
     * GET /app/admin/ai/providers/list
     */
    public function list(Request $request): Response
    {
        try {
            $providers = AiProvider::orderBy('created_at', 'desc')->get();

            $data = $providers->map(function ($provider) {
                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'template' => $provider->template,
                    'type' => $provider->type,
                    'weight' => $provider->weight,
                    'enabled' => $provider->enabled,
                    'created_at' => $provider->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $provider->updated_at?->format('Y-m-d H:i:s'),
                ];
            });

            return json(['code' => 0, 'data' => $data]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取提供方详情（包含配置）
     * GET /app/admin/ai/providers/detail?id=xxx
     */
    public function detail(Request $request): Response
    {
        try {
            $id = (string) $request->get('id', '');
            if (empty($id)) {
                return json(['code' => 1, 'msg' => 'id is required']);
            }

            $provider = AiProvider::find($id);
            if (!$provider) {
                return json(['code' => 1, 'msg' => '提供方不存在']);
            }

            $data = [
                'id' => $provider->id,
                'name' => $provider->name,
                'template' => $provider->template,
                'type' => $provider->type,
                'config' => $provider->getConfigArray(),
                'weight' => $provider->weight,
                'enabled' => $provider->enabled,
                'created_at' => $provider->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $provider->updated_at?->format('Y-m-d H:i:s'),
            ];

            return json(['code' => 0, 'data' => $data]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 创建提供方
     * POST /app/admin/ai/providers/create
     * body: { name, template?, type, config, weight?, enabled? }
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
            $template = ($payload['template'] ?? null);
            $type = (string) ($payload['type'] ?? 'openai');
            $config = (array) ($payload['config'] ?? []);
            $weight = (int) ($payload['weight'] ?? 1);
            $enabled = (bool) ($payload['enabled'] ?? true);

            if (empty($name)) {
                return json(['code' => 1, 'msg' => '提供方名称不能为空']);
            }

            // 生成ID
            $id = AiProvider::generateId();

            // 如果选择了模板，合并模板配置
            if (!empty($template)) {
                $templateConfig = AiProviderTemplates::generateConfig((string) $template, $config);
                $config = array_merge($templateConfig, $config);
            }

            // 创建提供方
            $provider = AiProvider::create([
                'id' => $id,
                'name' => $name,
                'template' => $template,
                'type' => $type,
                'weight' => max(0, $weight),
                'enabled' => $enabled,
            ]);

            $provider->setConfigArray($config);
            $provider->save();

            return json(['code' => 0, 'msg' => '创建成功', 'data' => ['id' => $provider->id]]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 更新提供方
     * POST /app/admin/ai/providers/update
     * body: { id, name?, type?, config?, weight?, enabled? }
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

            $id = (string) ($payload['id'] ?? '');
            if (empty($id)) {
                return json(['code' => 1, 'msg' => 'id is required']);
            }

            $provider = AiProvider::find($id);
            if (!$provider) {
                return json(['code' => 1, 'msg' => '提供方不存在']);
            }

            // 更新字段
            if (isset($payload['name'])) {
                $provider->name = (string) $payload['name'];
            }
            if (isset($payload['template'])) {
                $provider->template = $payload['template'];
            }
            if (isset($payload['type'])) {
                $provider->type = (string) $payload['type'];
            }
            if (isset($payload['config'])) {
                $provider->setConfigArray((array) $payload['config']);
            }
            if (isset($payload['weight'])) {
                $provider->weight = max(0, (int) $payload['weight']);
            }
            if (isset($payload['enabled'])) {
                $provider->enabled = (bool) $payload['enabled'];
            }

            $provider->save();

            return json(['code' => 0, 'msg' => '更新成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 删除提供方
     * POST /app/admin/ai/providers/delete
     * body: { id }
     */
    public function delete(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $id = (string) ($payload['id'] ?? '');
            if (empty($id)) {
                return json(['code' => 1, 'msg' => 'id is required']);
            }

            $provider = AiProvider::find($id);
            if (!$provider) {
                return json(['code' => 1, 'msg' => '提供方不存在']);
            }

            // 检查是否正在使用
            $currentSelection = (string) blog_config('ai_current_selection', '', true);
            if ($currentSelection === "provider:{$id}") {
                return json(['code' => 1, 'msg' => '该提供方正在使用中，无法删除']);
            }

            // 检查是否在轮询组中
            $inPollingGroup = \app\model\AiPollingGroupProvider::where('provider_id', $id)->exists();
            if ($inPollingGroup) {
                return json(['code' => 1, 'msg' => '该提供方在轮询组中使用，请先从轮询组移除']);
            }

            $provider->delete();

            return json(['code' => 0, 'msg' => '删除成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 切换提供方启用状态
     * POST /app/admin/ai/providers/toggle-enabled
     * body: { id }
     */
    public function toggleEnabled(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $id = (string) ($payload['id'] ?? '');
            if (empty($id)) {
                return json(['code' => 1, 'msg' => 'id is required']);
            }

            $provider = AiProvider::find($id);
            if (!$provider) {
                return json(['code' => 1, 'msg' => '提供方不存在']);
            }

            $provider->enabled = !$provider->enabled;
            $provider->save();

            return json(['code' => 0, 'msg' => '操作成功', 'data' => ['enabled' => $provider->enabled]]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取模板列表
     * GET /app/admin/ai/providers/templates
     */
    public function templates(Request $request): Response
    {
        try {
            $templates = AiProviderTemplates::getTemplateList();

            return json(['code' => 0, 'data' => $templates]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取模板详情（包含配置字段）
     * GET /app/admin/ai/providers/template-detail?template=openai
     */
    public function templateDetail(Request $request): Response
    {
        try {
            $templateId = (string) $request->get('template', '');
            if (empty($templateId)) {
                return json(['code' => 1, 'msg' => 'template is required']);
            }

            $template = AiProviderTemplates::getTemplate($templateId);
            if (!$template) {
                return json(['code' => 1, 'msg' => '模板不存在']);
            }

            return json(['code' => 0, 'data' => $template]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 测试提供方连接
     * POST /app/admin/ai/providers/test
     * body: { id, stream? } 或 { type, config, stream? }
     */
    public function test(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }
            if (!is_array($payload)) {
                return json(['code' => 1, 'msg' => 'invalid payload']);
            }

            $providerId = (string) ($payload['id'] ?? '');
            $stream = (bool) ($payload['stream'] ?? false);

            // 从 ID加载或直接使用配置
            if (!empty($providerId)) {
                $providerInstance = AISummaryService::createProviderFromDb($providerId);
            } else {
                $type = (string) ($payload['type'] ?? '');
                $config = (array) ($payload['config'] ?? []);
                if (empty($type)) {
                    return json(['code' => 1, 'msg' => 'type is required']);
                }
                $providerInstance = AISummaryService::createProviderInstance($type, $config);
            }

            if (!$providerInstance) {
                return json(['code' => 1, 'msg' => '无法创建提供方实例']);
            }

            // 流式测试
            if ($stream) {
                return $this->handleStreamTest($providerInstance);
            }

            // 执行简单测试
            $result = $providerInstance->call('chat', ['message' => 'Hello, this is a test message.'], []);

            if ($result['ok']) {
                return json(['code' => 0, 'msg' => '测试成功', 'data' => $result]);
            } else {
                return json(['code' => 1, 'msg' => '测试失败: ' . ($result['error'] ?? 'Unknown error')]);
            }
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => '测试异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 处理流式测试（收集后一次性返回）
     */
    private function handleStreamTest($providerInstance): Response
    {
        $generator = $providerInstance->callStream('chat', ['message' => 'Hello, this is a streaming test message.'], []);

        if ($generator === false) {
            return json(['code' => 1, 'msg' => 'Stream not supported or failed to initialize']);
        }

        // 收集所有数据
        $resultText = '';
        $usage = null;
        $finishReason = null;

        try {
            foreach ($generator as $chunk) {
                if ($chunk['type'] === 'content' && !empty($chunk['content'])) {
                    $resultText .= $chunk['content'];
                } elseif ($chunk['type'] === 'done') {
                    $usage = $chunk['usage'] ?? null;
                    $finishReason = $chunk['finish_reason'] ?? null;
                } elseif ($chunk['type'] === 'error') {
                    return json(['code' => 1, 'msg' => 'Test failed: ' . ($chunk['error'] ?? 'Unknown error')]);
                }
            }

            return json([
                'code' => 0,
                'msg' => '测试成功',
                'data' => [
                    'result' => $resultText,
                    'usage' => $usage,
                    'finish_reason' => $finishReason,
                ],
            ]);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => '测试异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取模型列表
     * POST /app/admin/ai/providers/fetch-models
     * body: { type, base_url, api_key }
     */
    public function fetchModels(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }
            if (!is_array($payload)) {
                return json(['code' => 1, 'msg' => 'invalid payload']);
            }

            $type = (string) ($payload['type'] ?? '');
            $baseUrl = (string) ($payload['base_url'] ?? '');
            $apiKey = (string) ($payload['api_key'] ?? '');

            if (empty($type) || empty($baseUrl) || empty($apiKey)) {
                return json(['code' => 1, 'msg' => 'type, base_url and api_key are required']);
            }

            // 创建临时提供方实例
            $config = [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
            ];

            $providerInstance = AISummaryService::createProviderInstance($type, $config);
            if (!$providerInstance) {
                return json(['code' => 1, 'msg' => '无法创建提供方实例']);
            }

            // 尝试获取模型列表
            $result = $providerInstance->fetchModels();
            if (!empty($result['ok']) && $result['ok'] && !empty($result['models'])) {
                return json(['code' => 0, 'data' => ['models' => $result['models']]]);
            }

            // API 拉取失败则使用预置列表
            if (method_exists($providerInstance, 'getPresetModels')) {
                $preset = $providerInstance->getPresetModels();
                if (!empty($preset)) {
                    // 统一格式化为 [{id: string}]
                    $models = array_map(function ($m) {
                        if (is_array($m)) {
                            return ['id' => $m['id'] ?? ''];
                        }

                        return ['id' => (string) $m];
                    }, $preset);

                    return json(['code' => 0, 'data' => ['models' => $models]]);
                }
            }

            // 最后回退到默认内置列表
            $defaultModels = [
                'openai' => [
                    ['id' => 'gpt-4o'],
                    ['id' => 'gpt-4-turbo'],
                    ['id' => 'gpt-3.5-turbo'],
                ],
                'azure_openai' => [],
                'claude' => [
                    ['id' => 'claude-3-5-sonnet-20241022'],
                    ['id' => 'claude-3-opus-20240229'],
                    ['id' => 'claude-3-sonnet-20240229'],
                ],
                'gemini' => [
                    ['id' => 'gemini-1.5-flash'],
                    ['id' => 'gemini-1.5-pro'],
                ],
                'deepseek' => [
                    ['id' => 'deepseek-chat'],
                    ['id' => 'deepseek-coder'],
                ],
                'zhipu' => [
                    ['id' => 'glm-4'],
                    ['id' => 'glm-4-plus'],
                ],
            ];

            $models = $defaultModels[$type] ?? [];

            return json(['code' => 0, 'data' => ['models' => $models]]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => '获取模型列表异常: ' . $e->getMessage()]);
        }
    }
}

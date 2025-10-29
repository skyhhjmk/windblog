<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use app\model\Media;
use app\service\AISummaryService;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * AI测试控制器
 * 用于测试AI效果、选择提供者、修改提示词、附加媒体图片
 */
class AiTestController
{
    /**
     * AI测试页面
     * GET /app/admin/ai-test
     */
    public function index(Request $request): Response
    {
        Log::debug('AiTestController::index - Accessing AI test page');
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'ai_test' . DIRECTORY_SEPARATOR . 'index.html';
        Log::debug('AiTestController::index - Template path: ' . $path);
        if (is_file($path)) {
            Log::debug('AiTestController::index - Template file found, returning content');

            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        Log::warning('AiTestController::index - Template file not found');

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'AI test page not found');
    }

    /**
     * 获取可用的AI提供者列表
     * GET /app/admin/ai-test/providers
     */
    public function getProviders(Request $request): Response
    {
        Log::debug('AiTestController::getProviders - Fetching AI providers list');
        try {
            $availableProviders = [];
            $providers = AISummaryService::getAllProviders(true); // 获取所有启用的提供者
            Log::debug('AiTestController::getProviders - Found providers: ' . count($providers));

            foreach ($providers as $providerData) {
                // 支持数组和对象两种格式
                $id = is_array($providerData) ? $providerData['id'] : $providerData->id;
                $name = is_array($providerData) ? $providerData['name'] : $providerData->name;
                $type = is_array($providerData) ? $providerData['type'] : $providerData->type;
                $config = is_array($providerData) ? ($providerData['config'] ?? '{}') : $providerData->config;

                $providerInstance = AISummaryService::createProviderInstance(
                    $type,
                    json_decode($config, true) ?: []
                );

                if ($providerInstance) {
                    $availableProviders[] = [
                        'id' => $id,
                        'name' => $name,
                        'type' => $type,
                        'supported_tasks' => $providerInstance->getSupportedTasks(),
                    ];
                }
            }

            $currentSelection = (string) blog_config('ai_current_selection', '', true);
            $currentProviderId = '';
            if (str_starts_with($currentSelection, 'provider:')) {
                $currentProviderId = substr($currentSelection, 9);
            }
            Log::debug('AiTestController::getProviders - Current provider ID: ' . $currentProviderId);
            Log::debug('AiTestController::getProviders - Available providers count: ' . count($availableProviders));

            return json([
                'code' => 0,
                'data' => [
                    'available' => $availableProviders,
                    'current' => $currentProviderId,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('AiTestController::getProviders - Error: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取媒体库图片列表
     * GET /app/admin/ai-test/media
     */
    public function getMedia(Request $request): Response
    {
        Log::debug('AiTestController::getMedia - Fetching media library images');
        try {
            $page = (int) $request->get('page', 1);
            $limit = (int) $request->get('limit', 20);
            $search = (string) $request->get('search', '');
            Log::debug('AiTestController::getMedia - Parameters', ['page' => $page, 'limit' => $limit, 'search' => $search]);

            $query = Media::query()->where('mime_type', 'like', 'image/%');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('filename', 'like', "%{$search}%")
                        ->orWhere('original_name', 'like', "%{$search}%");
                });
            }

            $total = $query->count();
            Log::debug('AiTestController::getMedia - Total images found: ' . $total);
            $list = $query->orderBy('id', 'desc')
                ->forPage($page, $limit)
                ->get()
                ->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'filename' => $media->filename,
                        'original_name' => $media->original_name,
                        'file_path' => $media->file_path,
                        'url' => '/uploads/' . $media->file_path,
                        'thumb_url' => isset($media->thumb_path) ? '/uploads/' . $media->thumb_path : '/uploads/' . $media->file_path,
                        'mime_type' => $media->mime_type,
                        'file_size' => $media->file_size,
                    ];
                })
                ->toArray();

            return json([
                'code' => 0,
                'data' => [
                    'list' => $list,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('AiTestController::getMedia - Error: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 测试AI调用（异步方式）
     * POST /app/admin/ai-test/test
     * body: { provider: 'openai', task: 'chat', prompt: '...', images: [id1, id2], options: {...} }
     *
     * 返回 task_id，前端需要轮询 /app/admin/ai-test/task-status 获取结果
     */
    public function test(Request $request): Response
    {
        Log::debug('AiTestController::test - Starting AI test (async mode)');
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }
            if (!is_array($payload)) {
                Log::warning('AiTestController::test - Invalid payload received');

                return json(['code' => 1, 'msg' => 'invalid payload']);
            }

            $providerId = (string) ($payload['provider'] ?? '');
            $task = (string) ($payload['task'] ?? 'chat');
            $prompt = (string) ($payload['prompt'] ?? '');
            $images = (array) ($payload['images'] ?? []);
            $options = (array) ($payload['options'] ?? []);

            Log::debug('AiTestController::test - Parameters', [
                'provider_id' => $providerId,
                'task' => $task,
                'prompt_length' => strlen($prompt),
                'images_count' => count($images),
            ]);

            if ($providerId === '') {
                Log::warning('AiTestController::test - Provider ID is required but not provided');

                return json(['code' => 1, 'msg' => 'provider is required']);
            }

            if ($prompt === '') {
                Log::warning('AiTestController::test - Prompt is required but not provided');

                return json(['code' => 1, 'msg' => 'prompt is required']);
            }

            // 验证提供者是否存在
            $provider = AISummaryService::createProviderFromDb($providerId);
            if (!$provider) {
                Log::error('AiTestController::test - Failed to create provider instance: ' . $providerId);

                return json(['code' => 1, 'msg' => 'Unknown provider or provider is disabled']);
            }

            // 构建参数
            $params = [];
            if ($task === 'chat' || $task === 'generate') {
                $params['message'] = $prompt;
            } elseif ($task === 'summarize') {
                $params['content'] = $prompt;
            } elseif ($task === 'translate') {
                $params['text'] = $prompt;
                $params['target_lang'] = $options['target_lang'] ?? 'English';
            }

            // 如果有图片,添加到参数中(为未来的多模态支持预留)
            if (!empty($images)) {
                Log::debug('AiTestController::test - Processing images: ' . count($images));
                $imageUrls = [];
                foreach ($images as $imageId) {
                    $media = Media::find((int) $imageId);
                    if ($media && str_starts_with($media->mime_type, 'image/')) {
                        $imageUrls[] = request()->host() . '/uploads/' . $media->file_path;
                    }
                }
                // 只有在确实有有效图片URL时才添加images参数
                if (!empty($imageUrls)) {
                    $params['images'] = $imageUrls;
                    Log::debug('AiTestController::test - Processed image URLs: ' . count($imageUrls));
                }
            }

            // 生成任务ID
            $taskId = 'ai_test_' . uniqid() . '_' . time();

            // 异步入队处理
            Log::debug('AiTestController::test - Enqueueing AI task: ' . $taskId);
            $enqueued = AISummaryService::enqueueTask($taskId, $task, $params, $options, $providerId);

            if (!$enqueued) {
                Log::error('AiTestController::test - Failed to enqueue task');

                return json(['code' => 1, 'msg' => '任务入队失败，请稍后重试']);
            }

            Log::debug('AiTestController::test - Task enqueued successfully');

            return json([
                'code' => 0,
                'data' => [
                    'task_id' => $taskId,
                    'message' => '任务已提交，正在处理中...',
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('AiTestController::test - Exception occurred: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 查询任务状态
     * GET /app/admin/ai-test/task-status?task_id=xxx
     */
    public function getTaskStatus(Request $request): Response
    {
        Log::debug('AiTestController::getTaskStatus - Fetching task status');
        try {
            $taskId = (string) $request->get('task_id', '');

            if ($taskId === '') {
                return json(['code' => 1, 'msg' => 'task_id is required']);
            }

            Log::debug('AiTestController::getTaskStatus - Task ID: ' . $taskId);
            $status = AISummaryService::getTaskStatus($taskId);

            if ($status === null) {
                Log::warning('AiTestController::getTaskStatus - Task not found: ' . $taskId);

                return json(['code' => 1, 'msg' => '任务不存在或已过期']);
            }

            Log::debug('AiTestController::getTaskStatus - Task status: ' . ($status['status'] ?? 'unknown'));

            return json([
                'code' => 0,
                'data' => $status,
            ]);
        } catch (Throwable $e) {
            Log::error('AiTestController::getTaskStatus - Error: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 保存常用提示词模板
     * POST /app/admin/ai-test/save-template
     * body: { name: '...', prompt: '...', task: 'chat' }
     */
    public function saveTemplate(Request $request): Response
    {
        Log::debug('AiTestController::saveTemplate - Saving prompt template');
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $name = (string) ($payload['name'] ?? '');
            $prompt = (string) ($payload['prompt'] ?? '');
            $task = (string) ($payload['task'] ?? 'chat');

            Log::debug('AiTestController::saveTemplate - Template data', ['name' => $name, 'task' => $task, 'prompt_length' => strlen($prompt)]);

            if ($name === '' || $prompt === '') {
                return json(['code' => 1, 'msg' => 'name and prompt are required']);
            }

            // 读取现有模板
            $templatesConfig = blog_config('ai_test_templates', '[]', true);
            if (is_array($templatesConfig)) {
                $templates = $templatesConfig;
            } else {
                $templates = json_decode((string) $templatesConfig, true) ?: [];
            }

            // 添加新模板
            $templates[] = [
                'id' => uniqid(),
                'name' => $name,
                'prompt' => $prompt,
                'task' => $task,
                'created_at' => time(),
            ];

            // 保存
            blog_config('ai_test_templates', json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, true, true);
            Log::debug('AiTestController::saveTemplate - Template saved successfully, total templates: ' . count($templates));

            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (Throwable $e) {
            Log::error('AiTestController::saveTemplate - Error: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取提示词模板列表
     * GET /app/admin/ai-test/templates
     */
    public function getTemplates(Request $request): Response
    {
        Log::debug('AiTestController::getTemplates - Fetching prompt templates');
        try {
            $templatesConfig = blog_config('ai_test_templates', '[]', true);
            // blog_config 可能返回数组或字符串，统一处理
            if (is_array($templatesConfig)) {
                $templates = $templatesConfig;
            } else {
                $templates = json_decode((string) $templatesConfig, true) ?: [];
            }
            Log::debug('AiTestController::getTemplates - Found templates: ' . count($templates));

            return json(['code' => 0, 'data' => $templates]);
        } catch (Throwable $e) {
            Log::error('AiTestController::getTemplates - Error: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 删除提示词模板
     * POST /app/admin/ai-test/delete-template
     * body: { id: '...' }
     */
    public function deleteTemplate(Request $request): Response
    {
        Log::debug('AiTestController::deleteTemplate - Deleting prompt template');
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $id = (string) ($payload['id'] ?? '');
            Log::debug('AiTestController::deleteTemplate - Template ID: ' . $id);
            if ($id === '') {
                Log::warning('AiTestController::deleteTemplate - Template ID is required but not provided');

                return json(['code' => 1, 'msg' => 'id is required']);
            }

            // 读取现有模板
            $templatesConfig = blog_config('ai_test_templates', '[]', true);
            if (is_array($templatesConfig)) {
                $templates = $templatesConfig;
            } else {
                $templates = json_decode((string) $templatesConfig, true) ?: [];
            }

            // 删除指定模板
            $templates = array_filter($templates, function ($tpl) use ($id) {
                return ($tpl['id'] ?? '') !== $id;
            });

            // 保存
            blog_config('ai_test_templates', json_encode(array_values($templates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, true, true);
            Log::debug('AiTestController::deleteTemplate - Template deleted successfully, remaining templates: ' . count($templates));

            return json(['code' => 0, 'msg' => '删除成功']);
        } catch (Throwable $e) {
            Log::error('AiTestController::deleteTemplate - Error: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
}

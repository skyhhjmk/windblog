<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use app\model\Media;
use app\service\AISummaryService;
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
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'ai_test' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'AI test page not found');
    }

    /**
     * 获取可用的AI提供者列表
     * GET /app/admin/ai-test/providers
     */
    public function getProviders(Request $request): Response
    {
        try {
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
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取媒体库图片列表
     * GET /app/admin/ai-test/media
     */
    public function getMedia(Request $request): Response
    {
        try {
            $page = (int) $request->get('page', 1);
            $limit = (int) $request->get('limit', 20);
            $search = (string) $request->get('search', '');

            $query = Media::query()->where('mime_type', 'like', 'image/%');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('filename', 'like', "%{$search}%")
                        ->orWhere('original_name', 'like', "%{$search}%");
                });
            }

            $total = $query->count();
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
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 测试AI调用
     * POST /app/admin/ai-test/test
     * body: { provider: 'openai', task: 'chat', prompt: '...', images: [id1, id2], options: {...} }
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

            $providerId = (string) ($payload['provider'] ?? '');
            $task = (string) ($payload['task'] ?? 'chat');
            $prompt = (string) ($payload['prompt'] ?? '');
            $images = (array) ($payload['images'] ?? []);
            $options = (array) ($payload['options'] ?? []);

            if ($providerId === '') {
                return json(['code' => 1, 'msg' => 'provider is required']);
            }

            if ($prompt === '') {
                return json(['code' => 1, 'msg' => 'prompt is required']);
            }

            // 创建提供者实例
            $provider = AISummaryService::createProvider($providerId);
            if (!$provider) {
                return json(['code' => 1, 'msg' => 'Unknown provider']);
            }

            // 获取提供者配置
            $config = AISummaryService::getProviderConfig($providerId);
            $provider = AISummaryService::createProvider($providerId, $config);

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

            // 如果有图片，添加到参数中（为未来的多模态支持预留）
            if (!empty($images)) {
                $imageUrls = [];
                foreach ($images as $imageId) {
                    $media = Media::find((int) $imageId);
                    if ($media && str_starts_with($media->mime_type, 'image/')) {
                        $imageUrls[] = request()->host() . '/uploads/' . $media->file_path;
                    }
                }
                $params['images'] = $imageUrls;
            }

            // 调用AI提供者
            $result = $provider->call($task, $params, $options);

            if (!$result['ok']) {
                return json(['code' => 1, 'msg' => $result['error'] ?? 'AI call failed']);
            }

            return json([
                'code' => 0,
                'data' => [
                    'result' => $result['result'] ?? '',
                    'usage' => $result['usage'] ?? null,
                    'model' => $result['model'] ?? null,
                    'finish_reason' => $result['finish_reason'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
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
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $name = (string) ($payload['name'] ?? '');
            $prompt = (string) ($payload['prompt'] ?? '');
            $task = (string) ($payload['task'] ?? 'chat');

            if ($name === '' || $prompt === '') {
                return json(['code' => 1, 'msg' => 'name and prompt are required']);
            }

            // 读取现有模板
            $templatesJson = (string) blog_config('ai_test_templates', '[]', true);
            $templates = json_decode($templatesJson, true) ?: [];

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

            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取提示词模板列表
     * GET /app/admin/ai-test/templates
     */
    public function getTemplates(Request $request): Response
    {
        try {
            $templatesJson = (string) blog_config('ai_test_templates', '[]', true);
            $templates = json_decode($templatesJson, true) ?: [];

            return json(['code' => 0, 'data' => $templates]);
        } catch (Throwable $e) {
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
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $id = (string) ($payload['id'] ?? '');
            if ($id === '') {
                return json(['code' => 1, 'msg' => 'id is required']);
            }

            // 读取现有模板
            $templatesJson = (string) blog_config('ai_test_templates', '[]', true);
            $templates = json_decode($templatesJson, true) ?: [];

            // 删除指定模板
            $templates = array_filter($templates, function ($tpl) use ($id) {
                return ($tpl['id'] ?? '') !== $id;
            });

            // 保存
            blog_config('ai_test_templates', json_encode(array_values($templates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, true, true);

            return json(['code' => 0, 'msg' => '删除成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
}

<?php

namespace plugin\admin\app\controller;

use app\model\ImportJob;
use app\model\Media;
use app\model\Post;
use app\service\MediaLibraryService;
use app\service\MQService;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;
use support\exception\BusinessException;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class WpImportController extends Base
{
    /**
     * 显示导入页面
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request)
    {
        return view('tools/wp-import/index');
    }

    /**
     * 显示失败媒体列表页面
     *
     * @param Request $request
     *
     * @return Response
     */
    public function failedMedia(Request $request)
    {
        return view('tools/wp-import/failed-media');
    }

    /**
     * 获取导入任务列表
     *
     * @param Request $request
     *
     * @return Response
     */
    public function list(Request $request): Response
    {
        // 后台不使用缓存
        // 获取请求参数
        $name = $request->get('name', '');
        //        $username = $request->get('username', '');
        $status = $request->get('status', '');
        $page = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 15);
        $order = $request->get('order', 'id');
        $sort = $request->get('sort', 'desc');

        // 构建查询
        $query = ImportJob::query();

        // 搜索条件
        if ($name) {
            $query->where('title', 'like', "%{$name}%");
        }

        //        if ($status) {
        //            $query->where('slug', 'like', "%{$status}%");
        //        }

        // 状态筛选
        if ($status) {
            $query->where('status', $status);
        }

        // 获取总数
        $total = $query->count();

        // 排序和分页
        $list = $query->orderBy($order, $sort)
            ->forPage($page, $limit)
            ->get()
            ->toArray();

        // 返回JSON数据
        return $this->success('成功', $list, $total);
    }

    /**
     * 创建表
     *
     * @param Request $request
     *
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function create(Request $request): Response
    {
        return raw_view('tools/wp-import/create', []);
    }

    /**
     * 上传并创建导入任务
     *
     * 貌似并没有被使用，准备废弃
     *
     * @param Request $request
     *
     * @return Response
     */

    /**
     * 统一提交导入任务（包含文件和表单数据）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function submit(Request $request)
    {
        try {
            // 检查是否有上传媒体
            $file = $request->file('import_file');
            if (!$file) {
                return json(['code' => 400, 'msg' => '请先上传媒体']);
            }

            // 验证文件类型，仅支持XML
            if (strtolower($file->getUploadExtension()) !== 'xml') {
                return json(['code' => 400, 'msg' => '只支持XML格式的文件']);
            }

            // 创建上传目录
            $uploadDir = runtime_path('imports');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0o755, true);
            }

            // 移动文件到指定目录
            $filename = time() . '_' . uniqid() . '.' . $file->getUploadExtension();
            $filePath = $uploadDir . '/' . $filename;
            if (!$file->move($filePath)) {
                return json(['code' => 500, 'msg' => '文件上传失败']);
            }

            // 获取默认作者ID，如果选择system则为null
            $defaultAuthor = $request->post('default_author', 'system');
            $defaultAuthorId = null;
            if ($defaultAuthor !== 'system') {
                $defaultAuthorId = (int) $defaultAuthor;
            }

            // 获取force参数
            $force = $request->post('force') === '1' ? 1 : 0;

            // 如果不是强制导入，则检查是否已存在相同文件名的任务
            if (!$force) {
                $existingJob = ImportJob::where('name', $file->getUploadName())
                    ->whereNotIn('status', ['failed', 'completed'])
                    ->first();

                if ($existingJob) {
                    return json([
                        'code' => 409,
                        'msg' => '检测到相同文件名的导入任务，请手动处理或选择强制上传',
                        'data' => [
                            'job_id' => $existingJob->id,
                            'status' => $existingJob->status,
                        ],
                    ]);
                }
            }

            // 构造导入任务选项
            $options = [
                'convert_to' => $request->post('convert_to', 'markdown'),
                'create_users' => (bool) $request->post('create_users', 0),
                'import_attachments' => (bool) $request->post('import_attachments', 0),
                'download_attachments' => (bool) $request->post('download_attachments', 0),
                'import_comments' => (bool) $request->post('import_comments', 0),
                'duplicate_mode' => $request->post('duplicate_mode', 'skip'),
                'author_action' => $request->post('author_action', 'map_to_system'),
            ];

            // 创建导入任务记录到数据库
            $job = new ImportJob();
            $job->name = $file->getUploadName();
            $job->type = 'wordpress_xml';
            $job->file_path = $filePath;
            $job->author_id = $defaultAuthorId;
            $job->options = json_encode($options, JSON_UNESCAPED_UNICODE);
            $job->status = 'pending';
            $job->progress = 0;
            $job->message = '任务已创建，等待处理';
            $job->save();

            // 同时发送消息队列通知，确保实时性
            $payload = [
                'type' => 'wordpress_xml',
                'name' => $file->getUploadName(),
                'file_path' => $filePath,
                'author_id' => $defaultAuthorId,
                'options' => $options,
                'force' => (bool) $force,
                'job_id' => $job->id, // 添加任务ID用于关联
            ];

            $exchange = (string) blog_config('rabbitmq_import_exchange', 'import_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_import_routing_key', 'import_job', true);

            $channel = MQService::getChannel();
            $message = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]);
            $channel->basic_publish($message, $exchange, $routingKey);

            // 成功响应（兼容layui）
            return json([
                'code' => 0,
                'msg' => '导入任务已添加到队列',
            ]);
        } catch (Exception $e) {
            // 记录错误日志
            Log::error('导入提交错误: ' . $e->getMessage(), ['exception' => $e]);

            // 错误响应格式兼容layui
            return json([
                'code' => 500,
                'msg' => '服务器错误 ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取导入任务状态
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function status(Request $request, int $id)
    {
        try {
            $job = ImportJob::find($id);
            if (!$job) {
                return json(['code' => 404, 'msg' => '任务不存在']);
            }

            return json([
                'code' => 200,
                'data' => [
                    'status' => $job->status,
                    'progress' => $job->progress,
                    'message' => $job->message,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('获取导入状态错误: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 500, 'msg' => '服务器内部错误']);
        }
    }

    /**
     * 重置导入任务
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function reset(Request $request, int $id)
    {
        try {
            $job = ImportJob::find($id);
            if (!$job) {
                return json(['code' => 404, 'msg' => '任务不存在']);
            }

            // 只能重置失败或已完成的任务
            if (!in_array($job->status, ['failed', 'completed'])) {
                return json(['code' => 400, 'msg' => '只能重置失败或已完成的任务']);
            }

            $job->update([
                'status' => 'pending',
                'progress' => 0,
                'message' => '任务已被重置，等待重新处理',
            ]);

            return json(['code' => 200, 'msg' => '任务重置成功']);
        } catch (Exception $e) {
            Log::error('重置导入任务错误: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 500, 'msg' => '服务器内部错误']);
        }
    }

    /**
     * 删除导入任务
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function delete(Request $request, int $id)
    {
        try {
            $job = ImportJob::find($id);
            if (!$job) {
                return json(['code' => 404, 'msg' => '任务不存在']);
            }

            // 删除导入文件
            if (file_exists($job->file_path)) {
                unlink($job->file_path);
            }

            // 删除任务记录
            $job->delete();

            return json(['code' => 200, 'msg' => '任务删除成功']);
        } catch (Exception $e) {
            Log::error('删除导入任务错误: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 500, 'msg' => '服务器内部错误']);
        }
    }

    /**
     * 获取失败媒体列表
     *
     * @param Request $request
     *
     * @return Response
     */
    public function failedMediaList(Request $request): Response
    {
        try {
            // 获取请求参数
            $page = (int) $request->get('page', 1);
            $limit = (int) $request->get('limit', 15);
            $jobId = $request->get('id', 0);

            if ($jobId <= 0) {
                return $this->success('成功', [], 0);
            }

            // 获取导入任务
            $job = ImportJob::find($jobId);
            if (!$job) {
                return json(['code' => 404, 'msg' => '任务不存在']);
            }

            // 解析任务选项
            $options = json_decode($job->options, true) ?? [];
            $failedMedia = $options['failed_media'] ?? [];

            // 获取总数
            $total = count($failedMedia);

            // 分页处理
            $offset = ($page - 1) * $limit;
            $list = array_slice($failedMedia, $offset, $limit);

            // 格式化数据，确保结构与前端期望一致
            $formattedList = [];
            foreach ($list as $index => $media) {
                $formattedList[] = [
                    'id' => $jobId . '_' . $index,
                    'filename' => 'failed-' . $media['created_at'],
                    'original_name' => $media['title'],
                    'file_path' => '',
                    'thumb_path' => null,
                    'file_size' => 0,
                    'mime_type' => 'application/octet-stream',
                    'alt_text' => null,
                    'caption' => null,
                    'description' => null,
                    'author_id' => $job->author_id,
                    'author_type' => 'admin',
                    'created_at' => date('Y-m-d\TH:i:s.000000Z', $media['created_at']),
                    'updated_at' => date('Y-m-d\TH:i:s.000000Z', $media['created_at']),
                    'deleted_at' => null,
                    'custom_fields' => [
                        'reference_info' => [
                            'failed_external_urls' => [
                                [
                                    'url' => $media['url'],
                                    'error' => $media['error'],
                                    'retry_count' => $media['retry_count'] ?? 0,
                                    'job_id' => $jobId,
                                ],
                            ],
                        ],
                    ],
                ];
            }

            // 返回JSON数据
            return $this->success('成功', $formattedList, $total);
        } catch (Exception $e) {
            Log::error('获取失败媒体列表错误: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 500, 'msg' => '服务器内部错误']);
        }
    }

    /**
     * 重试下载失败媒体
     *
     * @param Request $request
     * @param string  $id 格式为 jobId_index 的失败媒体ID
     *
     * @return Response
     */
    public function retryFailedMedia(Request $request, string $id): Response
    {
        try {
            // 解析ID，格式为 jobId_index
            $parts = explode('_', $id);
            if (count($parts) !== 2) {
                return json(['code' => 400, 'msg' => '无效的媒体ID格式']);
            }

            $jobId = (int) $parts[0];
            $mediaIndex = (int) $parts[1];

            // 获取导入任务
            $job = ImportJob::find($jobId);
            if (!$job) {
                return json(['code' => 404, 'msg' => '任务不存在']);
            }

            // 解析任务选项
            $options = json_decode($job->options, true) ?? [];
            $failedMedia = $options['failed_media'] ?? [];

            if (!isset($failedMedia[$mediaIndex])) {
                return json(['code' => 404, 'msg' => '失败媒体记录不存在']);
            }

            // 获取失败的媒体信息
            $failedMediaItem = $failedMedia[$mediaIndex];
            $externalUrl = $failedMediaItem['url'];
            $title = $failedMediaItem['title'];

            // 创建MediaLibraryService实例
            $mediaLibraryService = new MediaLibraryService();

            // 重试下载
            $result = $mediaLibraryService->downloadRemoteFile(
                $externalUrl,
                $title,
                $job->author_id,
                'admin'
            );

            if ($result['code'] === 0) {
                // 下载成功，从失败列表中移除该媒体
                array_splice($failedMedia, $mediaIndex, 1);
                $options['failed_media'] = $failedMedia;

                // 更新任务选项
                $job->options = json_encode($options, JSON_UNESCAPED_UNICODE);
                $job->save();

                // 更新文章中的引用
                $this->updatePostReferences($externalUrl, $result['data']);

                return json(['code' => 200, 'msg' => '媒体下载重试成功']);
            } else {
                // 下载失败，增加重试次数
                $failedMedia[$mediaIndex]['retry_count'] = ($failedMedia[$mediaIndex]['retry_count'] ?? 0) + 1;
                $failedMedia[$mediaIndex]['last_retry_at'] = time();
                $options['failed_media'] = $failedMedia;

                // 更新任务选项
                $job->options = json_encode($options, JSON_UNESCAPED_UNICODE);
                $job->save();

                return json(['code' => 500, 'msg' => '媒体下载重试失败: ' . $result['msg']]);
            }
        } catch (Exception $e) {
            Log::error('重试下载失败媒体错误: ' . $e->getMessage(), ['exception' => $e]);

            return json(['code' => 500, 'msg' => '服务器内部错误: ' . $e->getMessage()]);
        }
    }

    /**
     * 更新文章中的媒体引用
     *
     * @param string $externalUrl 外部URL
     * @param Media  $media       媒体对象
     *
     * @return void
     */
    protected function updatePostReferences(string $externalUrl, Media $media): void
    {
        try {
            // 获取新的媒体URL
            $newUrl = '/uploads/' . $media->file_path;

            // 查找所有包含该外部URL的文章
            $posts = Post::where(function ($query) use ($externalUrl) {
                $query->where('content', 'LIKE', '%' . $externalUrl . '%')
                    ->orWhere('excerpt', 'LIKE', '%' . $externalUrl . '%');
            })->get();

            foreach ($posts as $post) {
                // 更新文章内容中的引用
                $post->content = str_replace($externalUrl, $newUrl, $post->content);
                $post->excerpt = str_replace($externalUrl, $newUrl, $post->excerpt);
                $post->save();

                // 记录到媒体的引用信息中
                $media->addExternalUrlReference($externalUrl, $post->id);
            }

            // 保存媒体的引用更新
            $media->save();
        } catch (Exception $e) {
            Log::error('更新文章引用错误: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}

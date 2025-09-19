<?php

namespace plugin\admin\app\controller;

use app\model\Author;
use app\model\ImportJob;
use app\model\Post;
use Illuminate\Database\Schema\Blueprint;
use plugin\admin\app\common\Util;
use plugin\admin\app\model\Option;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

class WpImportController extends Base
{
    /**
     * 显示导入页面
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        return view('tools/wp-import/index');
    }

    /**
     * 获取导入任务列表
     *
     * @param Request $request
     * @return Response
     */
    public function list(Request $request): Response
    {
        // 后台不使用缓存
        // 获取请求参数
        $name = $request->get('name', '');
        $username = $request->get('username', '');
        $status = $request->get('status', '');
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 15);
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
        return $this->success(trans('Success'), $list, $total);
    }

    /**
     * 创建表
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function create(Request $request): Response
    {
        if ($request->method() === 'GET') {
            return raw_view('tools/wp-import/create', []);
        }
        try {
            // 检查是否有上传媒体
            $file = $request->file('import_file');
            if (!$file) {
                return json(['code' => 400, 'msg' => trans('Please select a file to import')]);
            }

            // 验证文件类型，仅支持XML
            if (strtolower($file->getUploadExtension()) !== 'xml') {
                return json(['code' => 400, 'msg' => trans('Only XML files are supported')]);
            }

            // 创建上传目录
            $uploadDir = runtime_path('imports');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // 移动文件到指定目录
            $filename = time() . '_' . uniqid() . '.' . $file->getUploadExtension();
            $filePath = $uploadDir . '/' . $filename;
            if (!$file->move($filePath)) {
                return json(['code' => 500, 'msg' => trans('File upload failed')]);
            }

            // 获取默认作者ID，如果选择system则为null
            $defaultAuthor = $request->post('default_author');
            $defaultAuthorId = null;
            if ($defaultAuthor !== 'system') {
                $defaultAuthorId = (int)$defaultAuthor;
            }

            // 检查是否已存在相同文件名的任务
            $existingJob = ImportJob::where('name', $file->getUploadName())
//                ->whereNotIn('status', ['failed', 'completed'])
                ->first();

            if ($existingJob) {
                return json([
                    'code' => 409,
                    'msg' => '检测到相同文件名的导入任务，请手动处理',
                    'data' => [
                        'job_id' => $existingJob->id,
                        'status' => $existingJob->status
                    ]
                ]);
            }

            // 创建导入任务
            $importJob = new ImportJob();
            $importJob->name = $file->getUploadName();
            $importJob->type = 'wordpress_xml';
            $importJob->file_path = $filePath;
            $importJob->author_id = $defaultAuthorId;
            $importJob->options = json_encode([
                'create_users' => (bool)$request->post('create_users', false),
                'import_attachments' => (bool)$request->post('import_attachments', false),
                'download_attachments' => (bool)$request->post('download_attachments', false),
                'duplicate_mode' => $request->post('duplicate_mode', 'skip')
            ]);
            $importJob->save();

            return json(['code' => 0, 'msg' => trans('Import job created')]);
        } catch (\Exception $e) {
            // 记录错误日志
            \support\Log::error('导入上传错误: ' . $e->getMessage(), ['exception' => $e]);
            return json(['code' => 500, 'msg' => trans('Server error :error', ['error' => $e->getMessage()])]);
        }
//        return $this->json(0, 'ok');
    }

    /**
     * 上传并创建导入任务
     *
     * @param Request $request
     * @return Response
     */
    public function upload(Request $request)
    {
        // 获取是否强制上传的参数
        $force = (bool)$request->post('force', false);

        try {
            // 检查是否有上传媒体
            $file = $request->file('import_file');
            if (!$file) {
                return json(['code' => 400, 'msg' => '请选择要导入的文件']);
            }

            // 验证文件类型，仅支持XML
            if (strtolower($file->getUploadExtension()) !== 'xml') {
                return json(['code' => 400, 'msg' => '只支持XML格式的文件']);
            }

            // 创建上传目录
            $uploadDir = runtime_path('imports');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // 移动文件到指定目录
            $filename = time() . '_' . uniqid() . '.' . $file->getUploadExtension();
            $filePath = $uploadDir . '/' . $filename;
            if (!$file->move($filePath)) {
                return json(['code' => 500, 'msg' => '文件上传失败']);
            }

            // 获取默认作者ID，如果选择system则为null
            $defaultAuthor = $request->post('default_author');
            $defaultAuthorId = null;
            if ($defaultAuthor !== 'system') {
                $defaultAuthorId = (int)$defaultAuthor;
            }

            // 如果不是强制上传，则检查是否已存在相同文件名的任务
            if (!$force) {
                $existingJob = ImportJob::where('name', $file->getUploadName())
                    ->first();

                if ($existingJob) {
                    return json([
                        'code' => 409,
                        'msg' => '检测到相同文件名的导入任务，请手动处理或选择强制上传',
                        'data' => [
                            'job_id' => $existingJob->id,
                            'status' => $existingJob->status
                        ]
                    ]);
                }
            }

            // 创建导入任务
            $importJob = new ImportJob();
            $importJob->name = $file->getUploadName();
            $importJob->type = 'wordpress_xml';
            $importJob->file_path = $filePath;
            $importJob->author_id = $defaultAuthorId;
            $importJob->options = json_encode([
                'create_users' => (bool)$request->post('create_users', false),
                'import_attachments' => (bool)$request->post('import_attachments', false),
                'download_attachments' => (bool)$request->post('download_attachments', false),
                'duplicate_mode' => $request->post('duplicate_mode', 'skip')
            ]);
            $importJob->save();

            // 成功响应格式兼容layui
            return json([
                'code' => 0,
                'msg' => '导入任务创建成功',
                'data' => [
                    'name' => $file->getUploadName()
                ]
            ]);
        } catch (\Exception $e) {
            // 记录错误日志
            \support\Log::error('导入上传错误: ' . $e->getMessage(), ['exception' => $e]);
            // 错误响应格式兼容layui
            return json([
                'code' => 500,
                'msg' => '服务器内部错误: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 统一提交导入任务（包含文件和表单数据）
     *
     * @param Request $request
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
                mkdir($uploadDir, 0755, true);
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
                $defaultAuthorId = (int)$defaultAuthor;
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
                            'status' => $existingJob->status
                        ]
                    ]);
                }
            }

            // 创建导入任务
            $importJob = new ImportJob();
            $importJob->name = $file->getUploadName();
            $importJob->type = 'wordpress_xml';
            $importJob->file_path = $filePath;
            $importJob->author_id = $defaultAuthorId;

            $options = [
                'convert_to' => $request->post('convert_to', 'markdown'),
                'create_users' => (bool)$request->post('create_users', 0),
                'import_attachments' => (bool)$request->post('import_attachments', 0),
                'download_attachments' => (bool)$request->post('download_attachments', 0),
                'duplicate_mode' => $request->post('duplicate_mode', 'skip')
            ];

            $importJob->options = json_encode($options);
            $importJob->save();

            // 成功响应格式兼容layui
            return json([
                'code' => 0,
                'msg' => trans('Import job created')
            ]);
        } catch (\Exception $e) {
            // 记录错误日志
            \support\Log::error('导入提交错误: ' . $e->getMessage(), ['exception' => $e]);
            // 错误响应格式兼容layui
            return json([
                'code' => 500,
                'msg' => trans('Server error :error', ['error' => $e->getMessage()])
            ]);
        }
    }

    /**
     * 获取导入任务状态
     *
     * @param Request $request
     * @param int $id
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
                    'message' => $job->message
                ]
            ]);
        } catch (\Exception $e) {
            \support\Log::error('获取导入状态错误: ' . $e->getMessage(), ['exception' => $e]);
            return json(['code' => 500, 'msg' => '服务器内部错误']);
        }
    }

    /**
     * 重置导入任务
     *
     * @param Request $request
     * @param int $id
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
                'message' => '任务已被重置，等待重新处理'
            ]);

            return json(['code' => 200, 'msg' => '任务重置成功']);
        } catch (\Exception $e) {
            \support\Log::error('重置导入任务错误: ' . $e->getMessage(), ['exception' => $e]);
            return json(['code' => 500, 'msg' => '服务器内部错误']);
        }
    }

    /**
     * 删除导入任务
     *
     * @param Request $request
     * @param int $id
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
        } catch (\Exception $e) {
            \support\Log::error('删除导入任务错误: ' . $e->getMessage(), ['exception' => $e]);
            return json(['code' => 500, 'msg' => '服务器内部错误']);
        }
    }
}
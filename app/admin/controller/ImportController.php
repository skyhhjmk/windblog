<?php

namespace app\admin\controller;

use app\model\ImportJob;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

class ImportController
{
    /**
     * 显示导入页面
     *
     * @param Request $request
     * @return \support\Response
     */
    public function index(Request $request)
    {
        // 获取所有导入任务
        $jobs = ImportJob::orderByDesc('created_at')->get();

        // 获取用户列表用于选项
        $users = \support\Db::table('users')->get();

        return view('admin/import/index', [
            'jobs' => $jobs,
            'users' => $users
        ]);
    }
        /**
     * 强制创建导入任务（忽略重复检查）
     *
     * @param Request $request
     * @return \support\Response
     */
    public function forceUpload(Request $request)
    {
        try {
            // 检查是否有上传文件
            if (!$request->file('import_file')) {
                return json(['code' => 400, 'msg' => '请选择要导入的文件']);
            }

            // 获取上传文件
            $file = $request->file('import_file');
            
            // 验证文件类型
            if ($file->getUploadExtension() !== 'xml') {
                return json(['code' => 400, 'msg' => '只支持XML格式的文件']);
            }

            // 获取默认作者ID，如果选择system则为null
            $defaultAuthor = $request->post('default_author');
            $defaultAuthorId = null;
            if ($defaultAuthor !== 'system') {
                $defaultAuthorId = (int) $defaultAuthor;
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

            // 创建导入任务
            $importJob = new ImportJob();
            $importJob->name = $file->getUploadName();
            $importJob->type = 'wordpress_xml';
            $importJob->file_path = $filePath;
            $importJob->author_id = $defaultAuthorId;
            $importJob->options = json_encode([
                'create_users' => (bool) $request->post('create_users', false),
                'import_attachments' => (bool) $request->post('import_attachments', false),
                'download_attachments' => (bool) $request->post('download_attachments', false),
                'duplicate_mode' => $request->post('duplicate_mode', 'skip') // 添加重复处理模式
            ]);
            $importJob->save();

            return json(['code' => 200, 'msg' => '导入任务创建成功']);
        } catch (\Exception $e) {
            // 记录错误日志
            \support\Log::error('强制导入上传错误: ' . $e->getMessage(), ['exception' => $e]);
            return json(['code' => 500, 'msg' => '服务器内部错误: ' . $e->getMessage()]);
        }
    }


    /**
     * 上传并创建导入任务
     *
     * @param Request $request
     * @return \support\Response
     */
    public function upload(Request $request)
    {
        try {
            // 检查是否有上传文件
            $file = $request->file('import_file');
            if (!$file) {
                return json(['code' => 400, 'msg' => '请选择要导入的文件']);
            }
            
            // 验证文件类型
            if ($file->getUploadExtension() !== 'xml') {
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
                $defaultAuthorId = (int) $defaultAuthor;
            }

            // 检查是否已存在相同文件名的任务
            $existingJob = ImportJob::where('name', $file->getUploadName())
                ->whereNotIn('status', ['failed', 'completed'])
                ->first();
                
            if ($existingJob) {
                return json([
                    'code' => 409, 
                    'msg' => '检测到相同文件名的导入任务正在进行中，请等待完成或手动处理',
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
                'create_users' => (bool) $request->post('create_users', false),
                'import_attachments' => (bool) $request->post('import_attachments', false),
                'download_attachments' => (bool) $request->post('download_attachments', false),
                'duplicate_mode' => $request->post('duplicate_mode', 'skip') // 添加重复处理模式
            ]);
            $importJob->save();

            return json(['code' => 200, 'msg' => '导入任务创建成功']);
        } catch (\Exception $e) {
            // 记录错误日志
            \support\Log::error('导入上传错误: ' . $e->getMessage(), ['exception' => $e]);
            return json(['code' => 500, 'msg' => '服务器内部错误: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取导入任务状态
     *
     * @param Request $request
     * @param int $id
     * @return \support\Response
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
     * @return \support\Response
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
     * @return \support\Response
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
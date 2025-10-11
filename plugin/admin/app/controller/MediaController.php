<?php

namespace plugin\admin\app\controller;

use plugin\admin\app\controller\Base;
use app\service\MediaLibraryService;
use app\model\Media;
use support\Request;
use support\Response;

class MediaController extends Base
{
    /**
     * @var MediaLibraryService
     */
    private MediaLibraryService $mediaService;
    
    public function __construct()
    {
        $this->mediaService = new MediaLibraryService();
    }
    
    /**
     * 媒体库列表页面
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        return view('media/index');
    }
    
    /**
     * 显示媒体选择器视图
     * @return Response
     */
    public function selector(): Response
    {
        return view('media/media_selector');
    }
    
    /**
     * 获取媒体库列表数据
     *
     * @param Request $request
     * @return Response
     */
    public function list(Request $request): Response
    {
        try {
            // 获取请求参数
            $params = [
                'search' => $request->get('search', ''),
                'page' => (int)$request->get('page', 1),
                'limit' => min((int)$request->get('limit', 20), 100), // 限制最大数量
                'order' => $request->get('order', 'id'),
                'sort' => $request->get('sort', 'desc'),
                'mime_type' => $request->get('mime_type', '')
            ];
            
            $result = $this->mediaService->getList($params);
            
            // 格式化返回数据，确保包含所有必要字段
            $formattedData = array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'filename' => $item['filename'],
                    'original_name' => $item['original_name'],
                    'file_path' => $item['file_path'],
                    'thumb_path' => $item['thumb_path'] ?? null,
                    'file_size' => $item['file_size'],
                    'mime_type' => $item['mime_type'],
                    'alt_text' => $item['alt_text'] ?? '',
                    'caption' => $item['caption'] ?? '',
                    'description' => $item['description'] ?? '',
                    'created_at' => $item['created_at'],
                    'updated_at' => $item['updated_at']
                ];
            }, $result['list']);
            
            return json([
                'code' => 0, 
                'msg' => 'success', 
                'data' => $formattedData, 
                'total' => $result['total'],
                'page' => $params['page'],
                'limit' => $params['limit']
            ]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
    
    /**
     * 上传媒体文件
     *
     * @param Request $request
     * @return Response
     */
    public function upload(Request $request): Response
    {
        try {
            // 检查是否有上传文件
            $file = $request->file('file');
            if (!$file) {
                return json(['code' => 1, 'msg' => '请选择文件']);
            }

            // 验证文件
            if (!$file->isValid()) {
                return json(['code' => 1, 'msg' => '文件上传失败']);
            }

            // 检查文件大小（默认最大50MB）
            $maxSize = config('app.max_upload_size', 50 * 1024 * 1024);
            if ($file->getSize() > $maxSize) {
                return json(['code' => 1, 'msg' => '文件大小超过限制']);
            }
            
            $data = [
                'alt_text' => $request->post('alt_text', ''),
                'caption' => $request->post('caption', ''),
                'description' => $request->post('description', '')
            ];
            
            $result = $this->mediaService->upload($file, $data);
            
            if ($result['code'] === 0) {
                // 格式化返回数据
                $formattedResult = [
                    'id' => $result['data']['id'],
                    'filename' => $result['data']['filename'],
                    'original_name' => $result['data']['original_name'],
                    'file_path' => $result['data']['file_path'],
                    'thumb_path' => $result['data']['thumb_path'] ?? null,
                    'file_size' => $result['data']['file_size'],
                    'mime_type' => $result['data']['mime_type'],
                    'alt_text' => $result['data']['alt_text'] ?? '',
                    'caption' => $result['data']['caption'] ?? '',
                    'description' => $result['data']['description'] ?? '',
                    'created_at' => $result['data']['created_at'],
                    'updated_at' => $result['data']['updated_at']
                ];
                
                return json(['code' => 0, 'msg' => '上传成功', 'data' => $formattedResult]);
            } else {
                return json(['code' => 1, 'msg' => $result['msg']]);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '上传失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 编辑媒体信息
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function update(Request $request, int $id): Response
    {
        try {
            if (!$id) {
                return json(['code' => 1, 'msg' => '参数错误']);
            }

            // 支持JSON和表单数据
            $input = $request->getContent();
            if ($input && $this->isJson($input)) {
                $data = json_decode($input, true);
                $updateData = [
                    'alt_text' => $data['alt_text'] ?? '',
                    'caption' => $data['caption'] ?? '',
                    'description' => $data['description'] ?? ''
                ];
            } else {
                $updateData = [
                    'alt_text' => $request->post('alt_text', ''),
                    'caption' => $request->post('caption', ''),
                    'description' => $request->post('description', '')
                ];
            }
            
            $result = $this->mediaService->update($id, $updateData);
            
            if ($result['code'] === 0) {
                return json(['code' => 0, 'msg' => '更新成功', 'data' => $result['data'] ?? null]);
            } else {
                return json(['code' => 1, 'msg' => $result['msg']]);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 检查字符串是否为JSON格式
     * @param string $string
     * @return bool
     */
    private function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    /**
     * 删除媒体文件
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function remove(Request $request, int $id): Response
    {
        try {
            if (!$id) {
                return json(['code' => 1, 'msg' => '参数错误']);
            }

            $result = $this->mediaService->delete($id);
            
            if ($result['code'] === 0) {
                return json(['code' => 0, 'msg' => '删除成功']);
            } else {
                return json(['code' => 1, 'msg' => $result['msg']]);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
    
    /**
     * 批量删除媒体文件
     *
     * @param Request $request
     * @param string $ids
     * @return Response
     */
    public function batchRemove(Request $request, string $ids): Response
    {
        try {
            if (empty($ids)) {
                return json(['code' => 1, 'msg' => '参数错误']);
            }
            
            // 支持逗号分隔的ID字符串
            $idArray = array_filter(array_map('intval', explode(',', $ids)));
            
            if (empty($idArray)) {
                return json(['code' => 1, 'msg' => '没有有效的ID']);
            }

            $successCount = 0;
            $errors = [];

            foreach ($idArray as $id) {
                try {
                    $result = $this->mediaService->delete($id);
                    if ($result['code'] === 0) {
                        $successCount++;
                    } else {
                        $errors[] = "ID {$id}: " . $result['msg'];
                    }
                } catch (\Exception $e) {
                    $errors[] = "ID {$id}: " . $e->getMessage();
                }
            }

            if ($successCount > 0) {
                $message = "成功删除 {$successCount} 个文件";
                if (!empty($errors)) {
                    $message .= "，" . count($errors) . " 个失败";
                }
                return json(['code' => 0, 'msg' => $message, 'errors' => $errors]);
            } else {
                return json(['code' => 1, 'msg' => '批量删除失败', 'errors' => $errors]);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
    
    /**
     * 重新生成缩略图
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function regenerateThumbnail(Request $request, int $id): Response
    {
        try {
            if (!$id) {
                return json(['code' => 1, 'msg' => '参数错误']);
            }

            $result = $this->mediaService->regenerateThumbnail($id);
            
            if ($result['code'] === 0) {
                return json(['code' => 0, 'msg' => '缩略图重新生成成功', 'data' => $result['data'] ?? null]);
            } else {
                return json(['code' => 1, 'msg' => $result['msg']]);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 预览文本文件内容
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function previewText(Request $request, int $id): Response
    {
        try {
            if (!$id) {
                return json(['code' => 1, 'msg' => '参数错误']);
            }

            // 获取媒体文件信息
            $media = Media::find($id);
            if (!$media) {
                return json(['code' => 1, 'msg' => '文件不存在']);
            }
            
            // 检查是否为文本文件
            $config = config('media', []);
            $editableTypes = $config['text_preview']['editable_types'] ?? [];
            
            if (!in_array($media->mime_type, $editableTypes)) {
                return json(['code' => 1, 'msg' => '不支持预览此文件类型']);
            }
            
            // 检查文件大小限制
            $maxPreviewSize = $config['text_preview']['max_preview_size'] ?? (2 * 1024 * 1024);
            $filePath = public_path('uploads/' . $media->file_path);
            
            if (!file_exists($filePath)) {
                return json(['code' => 1, 'msg' => '文件不存在']);
            }
            
            $fileSize = filesize($filePath);
            if ($fileSize > $maxPreviewSize) {
                return json(['code' => 1, 'msg' => '文件过大，无法预览']);
            }
            
            // 读取文件内容
            $content = file_get_contents($filePath);
            
            // 检测文件编码并转换为UTF-8
            $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ASCII'], true);
            if ($encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
            
            return json([
                'code' => 0, 
                'msg' => 'success', 
                'data' => [
                    'content' => $content,
                    'encoding' => $encoding,
                    'size' => $fileSize,
                    'mime_type' => $media->mime_type
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '预览失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 保存文本文件内容
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function saveText(Request $request, int $id): Response
    {
        try {
            if (!$id) {
                return json(['code' => 1, 'msg' => '参数错误']);
            }

            // 获取媒体文件信息
            $media = Media::find($id);
            if (!$media) {
                return json(['code' => 1, 'msg' => '文件不存在']);
            }
            
            // 检查是否为可编辑的文本文件
            $config = config('media', []);
            $editableTypes = $config['text_preview']['editable_types'] ?? [];
            
            if (!in_array($media->mime_type, $editableTypes)) {
                return json(['code' => 1, 'msg' => '不支持编辑此文件类型']);
            }
            
            // 获取编辑内容
            $content = $request->post('content', '');
            if (empty($content)) {
                return json(['code' => 1, 'msg' => '内容不能为空']);
            }
            
            // 保存文件内容
            $filePath = public_path('uploads/' . $media->file_path);
            if (!file_exists($filePath)) {
                return json(['code' => 1, 'msg' => '文件不存在']);
            }
            
            // 备份原文件
            $backupPath = $filePath . '.backup.' . date('YmdHis');
            copy($filePath, $backupPath);
            
            // 写入新内容
            file_put_contents($filePath, $content);
            
            // 更新文件大小
            $media->file_size = filesize($filePath);
            $media->updated_at = date('Y-m-d H:i:s');
            $media->save();
            
            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '保存失败: ' . $e->getMessage()]);
        }
    }
}
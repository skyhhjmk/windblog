<?php

namespace plugin\admin\app\controller;

use plugin\admin\app\controller\Base;
use app\service\MediaLibraryService;
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
        // 获取请求参数
        $params = [
            'search' => $request->get('search', ''),
            'page' => (int)$request->get('page', 1),
            'limit' => (int)$request->get('limit', 15),
            'order' => $request->get('order', 'id'),
            'sort' => $request->get('sort', 'desc'),
            'mime_type' => $request->get('mime_type', '')
        ];
        
        $result = $this->mediaService->getList($params);
        
        // 返回JSON数据
        return $this->success('Success', $result['list'], $result['total']);
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
                return json(['code' => 400, 'msg' => '没有上传文件']);
            }
            
            $data = [
                'alt_text' => $request->post('alt_text', ''),
                'caption' => $request->post('caption', ''),
                'description' => $request->post('description', '')
            ];
            
            $result = $this->mediaService->upload($file, $data);
            return json($result);
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
        $data = [
            'alt_text' => $request->post('alt_text', ''),
            'caption' => $request->post('caption', ''),
            'description' => $request->post('description', '')
        ];
        
        $result = $this->mediaService->update($id, $data);
        
        if ($result['code'] === 0) {
            return $this->success($result['msg']);
        } else {
            return $this->fail($result['msg']);
        }
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
        $result = $this->mediaService->delete($id);
        
        if ($result['code'] === 0) {
            return $this->success($result['msg']);
        } else {
            return $this->fail($result['msg']);
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
        if (empty($ids)) {
            return $this->fail('参数错误');
        }
        
        $idArray = explode(',', $ids);
        $result = $this->mediaService->batchDelete($idArray);
        
        return $this->success($result['msg']);
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
            $result = $this->mediaService->regenerateThumbnail($id);
            
            if ($result['code'] === 0) {
                return $this->success($result['msg']);
            } else {
                return $this->fail($result['msg']);
            }
        } catch (\Exception $e) {
            return $this->fail('操作失败: ' . $e->getMessage());
        }
    }
}
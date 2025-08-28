<?php

namespace app\admin\controller;

use support\Request;
use support\Response;
use app\model\Posts;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class PostsController
{
    /**
     * 文章列表页面
     *
     * @param Request $request
     * @return Response
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function index(Request $request)
    {
        // 获取当前页码
        $page = (int)$request->get('page', 1);
        $page = $page > 0 ? $page : 1;
        
        // 获取搜索和筛选参数
        $search = $request->get('search', '');
        $status = $request->get('status', '');
        $orderBy = $request->get('order_by', 'created_at');
        $orderDir = $request->get('order_dir', 'desc');
        
        // 构建查询
        $query = Posts::query();
        
        // 搜索条件
        if ($search) {
            $query->where('title', 'like', "%{$search}%");
        }
        
        // 状态筛选
        if ($status) {
            $query->where('status', $status);
        }
        
        // 排序
        $query->orderBy($orderBy, $orderDir);
        
        // 分页，每页15条记录
        $posts = $query->paginate(15, '*', 'page', $page);
        
        // 传递数据到视图
        return view('admin/posts/index', [
            'posts' => $posts,
            'search' => $search,
            'status' => $status,
            'orderBy' => $orderBy,
            'orderDir' => $orderDir
        ]);
    }
    
    /**
     * 删除文章
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function delete(Request $request, $id)
    {
        $post = Posts::find($id);
        if ($post) {
            $post->delete();
            return json(['code' => 0, 'msg' => '文章删除成功']);
        }
        return json(['code' => 1, 'msg' => '文章不存在']);
    }
    
    /**
     * 批量操作
     *
     * @param Request $request
     * @return Response
     */
    public function batch(Request $request)
    {
        $ids = $request->post('ids', []);
        $action = $request->post('action', '');
        
        if (empty($ids) || empty($action)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        
        switch ($action) {
            case 'delete':
                Posts::destroy($ids);
                return json(['code' => 0, 'msg' => '文章删除成功']);
            case 'publish':
                Posts::whereIn('id', $ids)->update(['status' => 'published']);
                return json(['code' => 0, 'msg' => '文章已发布']);
            case 'draft':
                Posts::whereIn('id', $ids)->update(['status' => 'draft']);
                return json(['code' => 0, 'msg' => '文章已设为草稿']);
            default:
                return json(['code' => 1, 'msg' => '未知操作']);
        }
    }
}
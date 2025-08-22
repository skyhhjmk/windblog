<?php

namespace plugin\admin\app\controller;

use plugin\admin\app\controller\Base;
use app\model\Post;
use support\Request;
use support\Response;

class PostsController extends Base
{
    /**
     * 文章列表页面
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        return view('posts/index');
    }
    
    /**
     * 获取文章列表数据
     *
     * @param Request $request
     * @return Response
     */
    public function list(Request $request)
    {
        // 后台不使用缓存
        // 获取请求参数
        $realName = $request->get('realName', '');
        $username = $request->get('username', '');
        $status = $request->get('status', '');
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 15);
        $order = $request->get('order', 'id');
        $sort = $request->get('sort', 'desc');
        
        // 构建查询
        $query = Post::query();
        
        // 搜索条件
        if ($realName) {
            $query->where('title', 'like', "%{$realName}%");
        }
        
        if ($username) {
            $query->where('slug', 'like', "%{$username}%");
        }
        
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
        return $this->success('成功',$list, $total);
    }
    
    /**
     * 删除文章
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function remove(Request $request, $id)
    {
        $post = Post::find($id);
        if (!$post) {
            return $this->fail('文章不存在');
        }
        
        $post->delete();
        return $this->success('删除成功');
    }
    
    /**
     * 批量删除文章
     *
     * @param Request $request
     * @param string $ids
     * @return Response
     */
    public function batchRemove(Request $request, $ids)
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }
        
        $idArray = explode(',', $ids);
        $count = Post::destroy($idArray);
        return $this->success("成功删除 {$count} 篇文章");
    }
    
    /**
     * 创建文章页面
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        return view('posts/create');
    }
    
    /**
     * 编辑文章页面
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function edit(Request $request, $id)
    {
        $post = Post::find($id);
        if (!$post) {
            return $this->fail('文章不存在');
        }
        
        return view('posts/edit', ['post' => $post]);
    }
    
    /**
     * 查看文章页面
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function view(Request $request, $id)
    {
        $post = Post::find($id);
        if (!$post) {
            return $this->fail('文章不存在');
        }
        
        return view('posts/view', ['post' => $post]);
    }
    
    /**
     * 保存文章
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        $data = $request->post();
        
        // 验证数据
        if (empty($data['title'])) {
            return $this->fail('标题不能为空');
        }
        
        if (empty($data['content'])) {
            return $this->fail('内容不能为空');
        }
        
        $post = new Post();
        $post->title = $data['title'];
        $post->content = $data['content'];
        $post->status = $data['status'] ?? 'draft';
        $post->summary = $data['summary'] ?? '';
        $post->save();
        
        return $this->success('成功',['id' => $post->id]);
    }
    
    /**
     * 更新文章
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $post = Post::find($id);
        if (!$post) {
            return $this->fail('文章不存在');
        }
        
        $data = $request->post();
        
        // 验证数据
        if (empty($data['title'])) {
            return $this->fail('标题不能为空');
        }
        
        if (empty($data['content'])) {
            return $this->fail('内容不能为空');
        }
        
        $post->title = $data['title'];
        $post->content = $data['content'];
        $post->status = $data['status'] ?? 'draft';
        $post->summary = $data['summary'] ?? '';
        $post->save();
        
        return $this->success();
    }
}
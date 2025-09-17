<?php

namespace plugin\admin\app\controller;

use plugin\admin\app\controller\Base;
use app\model\Post;
use support\Request;
use support\Response;
use Throwable;

class PostsController extends Base
{
    /**
     * 文章列表页面
     *
     * @param Request $request
     *
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
     *
     * @return Response
     */
    public function list(Request $request)
    {
        // 后台不使用缓存
        // 获取请求参数
        $realName = $request->get('realName', '');
        $username = $request->get('username', '');
        $status = $request->get('status', '');
        $isTrashed = $request->get('isTrashed', 'false');
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 15);
        $order = $request->get('order', 'id');
        $sort = $request->get('sort', 'desc');

        // 构建查询
        if ($isTrashed === 'true') {
            // 查询垃圾箱中的文章
            $query = Post::onlyTrashed();
        } else {
            // 查询正常文章
            $query = Post::query();
        }

        // 状态筛选（在所有模式下都应用）
        if ($status) {
            $query->where('status', $status);
        }

        // 搜索条件
        if ($realName) {
            $query->where('title', 'like', "%{$realName}%");
        }

        if ($username) {
            $query->where('slug', 'like', "%{$username}%");
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
     * 软删除文章
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     * @throws Throwable
     */
    public function remove(Request $request, $id)
    {
        $post = Post::find($id);
        if (!$post) {
            return $this->fail('文章不存在');
        }

        // 记录软删除前的状态
        $useSoftDelete = blog_config('soft_delete', true);
        \support\Log::debug('Soft delete config: ' . var_export($useSoftDelete, true));
        \support\Log::debug('Post before soft delete: ' . var_export($post->toArray(), true));

        // 执行软删除并检查结果
        $result = $post->softDelete();
        \support\Log::debug('Soft delete result: ' . var_export($result, true));

        if ($result === false) {
            return $this->fail('删除失败');
        }

        // 检查删除后的状态
        $updatedPost = Post::withTrashed()->find($id);
        if ($updatedPost) {
            \support\Log::debug('Post after soft delete: ' . var_export($updatedPost->toArray(), true));
        }

        return $this->success('文章已移至垃圾箱');
    }

    /**
     * 恢复软删除的文章
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function restore(Request $request, $id)
    {
        $post = Post::withTrashed()->find($id);
        if (!$post) {
            return $this->fail('文章不存在');
        }

        if ($post->restore()) {
            return $this->success('文章已恢复');
        } else {
            return $this->fail('恢复失败');
        }
    }

    /**
     * 永久删除文章
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     * @throws Throwable
     */
    public function forceDelete(Request $request, $id)
    {
        $post = Post::withTrashed()->find($id);
        if (!$post) {
            return $this->fail('文章不存在');
        }

        // 使用 forceDelete 参数来强制删除
        $result = $post->softDelete(true);
        if ($result) {
            return $this->success('文章已永久删除');
        } else {
            return $this->fail('删除失败');
        }
    }

    /**
     * 批量恢复文章
     *
     * @param Request $request
     * @param string  $ids
     *
     * @return Response
     */
    public function batchRestore(Request $request, $ids)
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;

        foreach ($idArray as $id) {
            $post = Post::withTrashed()->find($id);
            if ($post && $post->restore()) {
                $count++;
            }
        }

        return $this->success("成功恢复 {$count} 篇文章");
    }

    /**
     * 批量永久删除文章
     *
     * @param Request $request
     * @param string  $ids
     *
     * @return Response
     * @throws Throwable
     */
    public function batchForceDelete(Request $request, $ids)
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;

        foreach ($idArray as $id) {
            $post = Post::withTrashed()->find($id);
            if ($post) {
                // 修复：检查softDelete的返回值是否为true，而不是简单判断是否为真值
                $result = $post->softDelete(true);
                if ($result === true) {
                    $count++;
                }
            }
        }

        return $this->success("成功永久删除 {$count} 篇文章");
    }

    /**
     * 批量删除文章
     *
     * @param Request $request
     * @param string  $ids
     *
     * @return Response
     * @throws Throwable
     */
    public function batchRemove(Request $request, $ids)
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;

        // 使用软删除而不是 destroy 方法
        foreach ($idArray as $id) {
            $post = Post::find($id);
            if ($post) {
                // 检查软删除是否成功
                if ($post->softDelete() !== false) {
                    $count++;
                }
            }
        }
        return $this->success("成功删除 {$count} 篇文章");
    }

    /**
     * 查看文章页面
     *
     * @param Request $request
     * @param int     $id
     *
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
}
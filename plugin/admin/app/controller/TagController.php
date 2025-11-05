<?php

namespace plugin\admin\app\controller;

use app\model\Tag;
use app\service\SlugTranslateService;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class TagController extends Base
{
    /**
     * 标签列表页面
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request)
    {
        return view('tag/index');
    }

    /**
     * 获取标签列表数据
     *
     * @param Request $request
     *
     * @return Response
     */
    public function list(Request $request)
    {
        $name = $request->get('name', '');
        $slug = $request->get('slug', '');
        $isTrashed = $request->get('isTrashed', 'false');
        $page = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 15);
        $order = $request->get('order', 'id');
        $sort = $request->get('sort', 'desc');

        // 构建查询
        if ($isTrashed === 'true') {
            $query = Tag::onlyTrashed();
        } else {
            $query = Tag::query();
        }

        // 搜索条件
        if ($name) {
            $query->where('name', 'like', "%{$name}%");
        }

        if ($slug) {
            $query->where('slug', 'like', "%{$slug}%");
        }

        // 获取总数
        $total = $query->count();

        // 排序和分页
        $list = $query->orderBy($order, $sort)
            ->forPage($page, $limit)
            ->get()
            ->toArray();

        return $this->success(trans('Success'), $list, $total);
    }

    /**
     * 获取单个标签信息
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function get(Request $request, $id)
    {
        $tag = Tag::withTrashed()->find($id);
        if (!$tag) {
            return $this->fail('标签不存在');
        }

        return $this->success('获取成功', $tag->toArray());
    }

    /**
     * 创建标签
     *
     * @param Request $request
     *
     * @return Response
     */
    public function create(Request $request)
    {
        $data = $request->post();

        // 验证必填字段
        if (empty($data['name'])) {
            return $this->fail('标签名称不能为空');
        }

        // 如果slug为空，使用翻译服务自动生成
        if (empty($data['slug'])) {
            $slugService = new SlugTranslateService();
            $data['slug'] = $slugService->translate($data['name']);

            // 如果翻译失败，返回错误
            if (empty($data['slug'])) {
                return $this->fail('标签别名生成失败，请手动输入');
            }
        }

        // 检查别名是否已存在
        if (Tag::where('slug', $data['slug'])->exists()) {
            return $this->fail('标签别名已存在');
        }

        try {
            $tag = new Tag();
            $tag->name = $data['name'];
            $tag->slug = $data['slug'];
            $tag->description = $data['description'] ?? '';
            $tag->save();

            return $this->success('创建成功');
        } catch (Exception $e) {
            Log::error('创建标签失败: ' . $e->getMessage());

            return $this->fail('创建失败');
        }
    }

    /**
     * 更新标签
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $tag = Tag::find($id);
        if (!$tag) {
            return $this->fail('标签不存在');
        }

        $data = $request->post();

        // 验证必填字段
        if (empty($data['name'])) {
            return $this->fail('标签名称不能为空');
        }

        // 如果slug为空，使用翻译服务自动生成
        if (empty($data['slug'])) {
            $slugService = new SlugTranslateService();
            $data['slug'] = $slugService->translate($data['name']);

            // 如果翻译失败，返回错误
            if (empty($data['slug'])) {
                return $this->fail('标签别名生成失败，请手动输入');
            }
        }

        // 检查别名是否已被其他标签使用
        if (Tag::where('slug', $data['slug'])->where('id', '!=', $id)->exists()) {
            return $this->fail('标签别名已存在');
        }

        try {
            $tag->name = $data['name'];
            $tag->slug = $data['slug'];
            $tag->description = $data['description'] ?? '';
            $tag->save();

            return $this->success('更新成功');
        } catch (Exception $e) {
            Log::error('更新标签失败: ' . $e->getMessage());

            return $this->fail('更新失败');
        }
    }

    /**
     * 软删除标签
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     * @throws Throwable
     */
    public function remove(Request $request, $id)
    {
        $tag = Tag::find($id);
        if (!$tag) {
            return $this->fail('标签不存在');
        }

        $result = $tag->softDelete();
        if ($result === false) {
            return $this->fail('删除失败');
        }

        return $this->success('标签已移至垃圾箱');
    }

    /**
     * 永久删除标签
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     * @throws Throwable
     */
    public function forceDelete(Request $request, $id)
    {
        $tag = Tag::withTrashed()->find($id);
        if (!$tag) {
            return $this->fail('标签不存在');
        }

        $result = $tag->softDelete(true);
        if ($result) {
            return $this->success('标签已永久删除');
        } else {
            return $this->fail('删除失败');
        }
    }

    /**
     * 批量删除标签
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

        foreach ($idArray as $id) {
            $tag = Tag::find($id);
            if ($tag && $tag->softDelete()) {
                $count++;
            }
        }

        return $this->success("成功删除 {$count} 个标签");
    }

    /**
     * 批量恢复标签
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
            $tag = Tag::withTrashed()->find($id);
            if ($tag && $tag->restore()) {
                $count++;
            }
        }

        return $this->success("成功恢复 {$count} 个标签");
    }

    /**
     * 恢复软删除的标签
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function restore(Request $request, $id)
    {
        $tag = Tag::withTrashed()->find($id);
        if (!$tag) {
            return $this->fail('标签不存在');
        }

        if ($tag->restore()) {
            return $this->success('标签已恢复');
        } else {
            return $this->fail('恢复失败');
        }
    }

    /**
     * 批量永久删除标签
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
            $tag = Tag::withTrashed()->find($id);
            if ($tag && $tag->softDelete(true)) {
                $count++;
            }
        }

        return $this->success("成功永久删除 {$count} 个标签");
    }
}

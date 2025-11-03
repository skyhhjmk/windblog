<?php

namespace plugin\admin\app\controller;

use app\model\Category;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class CategoryController extends Base
{
    /**
     * 分类列表页面
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request)
    {
        return view('category/index');
    }

    /**
     * 获取分类列表数据
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
        $order = $request->get('order', 'sort_order');
        $sort = $request->get('sort', 'asc');

        // 构建查询
        if ($isTrashed === 'true') {
            $query = Category::onlyTrashed();
        } else {
            $query = Category::query();
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
     * 获取单个分类信息
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function get(Request $request, $id)
    {
        $category = Category::withTrashed()->find($id);
        if (!$category) {
            return $this->fail('分类不存在');
        }

        return $this->success('获取成功', $category->toArray());
    }

    /**
     * 创建分类
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
            return $this->fail('分类名称不能为空');
        }

        if (empty($data['slug'])) {
            return $this->fail('分类别名不能为空');
        }

        // 检查别名是否已存在
        if (Category::where('slug', $data['slug'])->exists()) {
            return $this->fail('分类别名已存在');
        }

        try {
            $category = new Category();
            $category->name = $data['name'];
            $category->slug = $data['slug'];
            $category->description = $data['description'] ?? '';
            $category->parent_id = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
            $category->sort_order = !empty($data['sort_order']) ? (int) $data['sort_order'] : 0;
            $category->save();

            return $this->success('创建成功');
        } catch (\Exception $e) {
            Log::error('创建分类失败: ' . $e->getMessage());

            return $this->fail('创建失败');
        }
    }

    /**
     * 更新分类
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $category = Category::find($id);
        if (!$category) {
            return $this->fail('分类不存在');
        }

        $data = $request->post();

        // 验证必填字段
        if (empty($data['name'])) {
            return $this->fail('分类名称不能为空');
        }

        if (empty($data['slug'])) {
            return $this->fail('分类别名不能为空');
        }

        // 检查别名是否已被其他分类使用
        if (Category::where('slug', $data['slug'])->where('id', '!=', $id)->exists()) {
            return $this->fail('分类别名已存在');
        }

        try {
            $category->name = $data['name'];
            $category->slug = $data['slug'];
            $category->description = $data['description'] ?? '';
            $category->parent_id = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
            $category->sort_order = !empty($data['sort_order']) ? (int) $data['sort_order'] : 0;
            $category->save();

            return $this->success('更新成功');
        } catch (\Exception $e) {
            Log::error('更新分类失败: ' . $e->getMessage());

            return $this->fail('更新失败');
        }
    }

    /**
     * 软删除分类
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     * @throws Throwable
     */
    public function remove(Request $request, $id)
    {
        $category = Category::find($id);
        if (!$category) {
            return $this->fail('分类不存在');
        }

        // 检查是否有子分类
        if ($category->children()->count() > 0) {
            return $this->fail('该分类下有子分类，无法删除');
        }

        $result = $category->softDelete();
        if ($result === false) {
            return $this->fail('删除失败');
        }

        return $this->success('分类已移至垃圾箱');
    }

    /**
     * 永久删除分类
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     * @throws Throwable
     */
    public function forceDelete(Request $request, $id)
    {
        $category = Category::withTrashed()->find($id);
        if (!$category) {
            return $this->fail('分类不存在');
        }

        $result = $category->softDelete(true);
        if ($result) {
            return $this->success('分类已永久删除');
        } else {
            return $this->fail('删除失败');
        }
    }

    /**
     * 批量删除分类
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
            $category = Category::find($id);
            if ($category && $category->children()->count() == 0) {
                if ($category->softDelete()) {
                    $count++;
                }
            }
        }

        return $this->success("成功删除 {$count} 个分类");
    }

    /**
     * 批量恢复分类
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
            $category = Category::withTrashed()->find($id);
            if ($category && $category->restore()) {
                $count++;
            }
        }

        return $this->success("成功恢复 {$count} 个分类");
    }

    /**
     * 恢复软删除的分类
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function restore(Request $request, $id)
    {
        $category = Category::withTrashed()->find($id);
        if (!$category) {
            return $this->fail('分类不存在');
        }

        if ($category->restore()) {
            return $this->success('分类已恢复');
        } else {
            return $this->fail('恢复失败');
        }
    }

    /**
     * 批量永久删除分类
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
            $category = Category::withTrashed()->find($id);
            if ($category && $category->softDelete(true)) {
                $count++;
            }
        }

        return $this->success("成功永久删除 {$count} 个分类");
    }

    /**
     * 获取所有父级分类（用于下拉选择）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function parents(Request $request)
    {
        $categories = Category::whereNull('parent_id')
            ->orderBy('sort_order', 'asc')
            ->get(['id', 'name'])
            ->toArray();

        return $this->success('获取成功', $categories);
    }
}

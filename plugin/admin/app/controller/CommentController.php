<?php

namespace plugin\admin\app\controller;

use app\model\Comment as CommentModel;
use support\Request;
use support\Response;
use Throwable;

/**
 * 评论管理控制器
 */
class CommentController extends Base
{
    /**
     * 评论列表页面
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        return view('comment/index');
    }

    /**
     * 获取评论列表数据
     *
     * @param Request $request
     * @return Response
     */
    public function list(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 10);
        $status = $request->get('status', '');
        $keyword = $request->get('keyword', '');

        // 使用 deleted_at 判断是否删除；status 仅用于业务状态（pending/approved/spam/trash）
        $isDeleted = $request->get('isDeleted', 'false');
        $query = ($isDeleted === 'true') ? CommentModel::onlyTrashed() : CommentModel::query();
        if ($status !== '') {
            $query->where('status', $status);
        }

        // 关键词搜索
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('content', 'like', "%{$keyword}%")
                  ->orWhere('guest_name', 'like', "%{$keyword}%")
                  ->orWhere('guest_email', 'like', "%{$keyword}%");
            });
        }

        // 获取总数
        $count = $query->count();

        // 获取数据
        $comments = $query->with(['post', 'author'])
            ->orderBy('created_at', 'desc')
            ->forPage($page, $limit)
            ->get();

        return json([
            'code' => 0,
            'msg' => 'success',
            'count' => $count,
            'data' => $comments,
        ]);
    }

    /**
     * 审核评论
     *
     * @param Request $request
     * @return Response
     */
    public function moderate(Request $request): Response
    {
        $ids = $request->post('ids', []);
        $status = $request->post('status', 'approved');

        // 兼容 JSON 请求体
        if (!$ids) {
            $raw = $request->getContent();
            if ($raw) {
                $data = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $ids = $data['ids'] ?? $ids;
                    $status = $data['status'] ?? $status;
                }
            }
        }

        if (empty($ids) || !in_array($status, ['pending', 'approved', 'spam', 'trash'])) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            // 严格按状态字段更新，符合检查器
            CommentModel::whereIn('id', (array) $ids)->update(['status' => $status]);

            return json(['code' => 0, 'msg' => '操作成功']);
        } catch (Throwable $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }

    /**
     * 删除评论
     *
     * @param Request $request
     * @return Response
     */
    public function delete(Request $request): Response
    {
        $ids = $request->post('ids', []);
        $force = (bool) $request->post('force', false);
        // 兼容 JSON 请求体
        if (!$ids) {
            $raw = $request->getContent();
            if ($raw) {
                $data = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $ids = $data['ids'] ?? $ids;
                    $force = (bool) ($data['force'] ?? $force);
                }
            }
        }

        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '请选择要删除的评论']);
        }

        try {
            $count = 0;
            foreach ((array) $ids as $id) {
                $comment = CommentModel::withTrashed()->find($id);
                if ($comment && $comment->softDelete($force)) {
                    $count++;
                }
            }

            return json(['code' => 0, 'msg' => $force ? '已彻底删除' : '已删除至回收站', 'data' => ['count' => $count]]);
        } catch (Throwable $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    /**
     * 恢复评论（从回收站）
     *
     * @param Request $request
     * @return Response
     */
    public function restore(Request $request): Response
    {
        $ids = $request->post('ids', []);
        // 兼容 JSON 请求体
        if (!$ids) {
            $raw = $request->getContent();
            if ($raw) {
                $data = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $ids = $data['ids'] ?? $ids;
                }
            }
        }

        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '请选择要恢复的评论']);
        }

        try {
            $count = 0;
            $models = CommentModel::onlyTrashed()->whereIn('id', (array) $ids)->get();
            foreach ($models as $m) {
                if ($m->restore()) {
                    $count++;
                }
            }

            return json(['code' => 0, 'msg' => '恢复成功', 'data' => ['count' => $count]]);
        } catch (Throwable $e) {
            return json(['code' => 500, 'msg' => '恢复失败：' . $e->getMessage()]);
        }
    }
}
